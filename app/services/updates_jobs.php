<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_jobs.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides the durable, resumable application-update state machine used by Admin and background workers.
 *
 * Responsibilities:
 *   - Persist update job checkpoints outside active application files
 *   - Bound normal worker invocations by elapsed wall-clock time
 *   - Stream downloads to disk without loading release archives into PHP memory
 *   - Extract, validate, stage, and back up release files before activation
 *   - Serialize workers with durable job metadata and operating-system file locks
 *   - Keep activation as a small retry-safe critical section
 *   - Resume migrations at migration-file boundaries
 *   - Redact update failures before they reach Admin or public responses
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Implementation lives in app/services/updates_jobs/; this file is the module entry point.
 *   - The require_once list preserves the historical app/services.php include contract.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *   - Correctness must not depend on set_time_limit() or ignore_user_abort().
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Purpose: retain the optional publisher-manifest sweep without running it in jobs.
 * Type: bool. Units: enabled/disabled. Scope: newly validated installation jobs.
 * Consumers: package acceptance and exact-byte staging/activation.
 * Rationale: false remains the default until explicitly re-enabled; required
 * files, archive paths, database recovery evidence and removal policy stay intact.
 */
const APPLICATION_UPDATE_PACKAGE_INTEGRITY_ENABLED = false;

// Keep active/last state lookups available without including worker state-machine parts.
require_once __DIR__ . '/updates_job_lookup.php';
require_once dirname(__DIR__) . '/early_runtime.php';
require_once __DIR__ . '/../models/schema_inspection.php';

/**
 * Bound retries of an atomic updater checkpoint commit on Windows.
 *
 * @var int
 * Units: rename attempts, including the initial commit. Scope: one updater JSON write.
 * Consumers: application_update_write_json_atomic().
 * Rationale: tolerate brief Windows sharing locks while retaining the previous checkpoint.
 */
const APPLICATION_UPDATE_STATE_RENAME_ATTEMPTS = 10;

/**
 * Bound the pause between Windows checkpoint-commit attempts.
 *
 * @var int
 * Units: microseconds. Scope: one updater JSON write, at most nine pauses.
 * Consumers: application_update_write_json_atomic().
 * Rationale: allow transient readers to release their handles within a 450 ms retry budget.
 */
const APPLICATION_UPDATE_STATE_RENAME_DELAY_US = 50_000;

/**
 * Bound restarts when an archive server ignores a continuation Range request.
 * Type: int. Units: incomplete full-response restarts. Scope: one pinned job.
 * Consumers: application_update_download_incomplete().
 * Rationale: three restarts allow transient throughput recovery without an
 * endless background chain repeatedly downloading the same archive prefix.
 */
const APPLICATION_UPDATE_DOWNLOAD_RESTART_LIMIT = 3;

/**
 * Bound the independently persisted updater GC checkpoint before JSON decoding.
 * Type: int. Units: bytes. Scope: one updater GC evidence/checkpoint file.
 * Consumers: scheduled cache cleanup, read-only Maintenance Center evidence and
 * bounded manual diagnostics of the blocking updater pointer/job.
 * Rationale: 64 directory levels plus counters fit within 64 KiB; oversized
 * existing state must require review rather than unbounded allocation.
 */
const APPLICATION_UPDATE_GC_CHECKPOINT_MAX_BYTES = 65536;

/**
 * Bound each private worker's updater execution.
 * Type: float. Units: seconds. Scope: one authenticated continuation request.
 * Consumers: application_update_background_chain_run().
 * Rationale: three seconds retains the ordinary background-worker budget and
 *   leaves room for checkpoint persistence and the next asynchronous handoff.
 */
const APPLICATION_UPDATE_CHAIN_SLICE_SECONDS = 3.0;

/**
 * Purpose: pace private continuations while activation drains old readers.
 * Type: int. Units: microseconds. Scope: one gate-closed background handoff.
 * Consumers: application_update_background_chain_run().
 * Rationale: 1000000 microseconds limits waiting successors to about one per
 * second without depending on visitors that the activation gate now refuses.
 * The delay runs after both worker and chain locks have been released.
 */
const APPLICATION_UPDATE_CHAIN_DRAIN_DELAY_US = 1_000_000;

/**
 * Recover a handed-off ticket when no worker arrival was recorded.
 * Type: int. Units: seconds. Scope: one installation-wide queued handoff.
 * Consumers: background chain start/run ticket admission.
 * Rationale: 120 seconds tolerates hosting queues without permanently stalling
 *   an update after a killed request or a lost connection.
 */
const APPLICATION_UPDATE_CHAIN_LEASE_SECONDS = 120;

/**
 * Throttle unavailable background transport independently of release discovery.
 * Type: int. Units: seconds. Scope: visitor recovery after a failed handoff.
 * Consumers: application_update_background_chain_start().
 * Rationale: thirty seconds avoids a connection attempt on every visitor while
 *   preserving the existing request-driven worker fallback.
 */
const APPLICATION_UPDATE_CHAIN_RETRY_SECONDS = 30;

/**
 * Limit unattended retries of one failed job without resetting its identity.
 * Type: int. Units: retries. Scope: one durable background update job.
 * Consumers: retry admission and failed-job guidance.
 * Rationale: three retries tolerate transient hosting faults without looping
 * forever over the same package, including failures with changing references.
 */
const APPLICATION_UPDATE_AUTO_RETRY_LIMIT = 3;

/**
 * Space unattended attempts after caught failures.
 * Type: int. Units: seconds. Scope: one failed update or automatic rollback.
 * Consumers: automatic retry admission (60, 120 and 240 seconds).
 * Rationale: a sixty-second initial delay preserves hosting recovery time;
 * the finite retry cap also bounds the exponential calculation.
 */
const APPLICATION_UPDATE_AUTO_RETRY_DELAY_SECONDS = 60;

/**
 * Wait before abandoning a failed attempt or restoring its previous files.
 * Type: int. Units: seconds. Scope: the first caught failure of one update job.
 * Consumers: automatic rollback admission for every original trigger.
 * Rationale: 600 seconds leaves time for 60/120/240-second transient retries;
 * recovery still needs a later worker opportunity and verified database safety.
 */
const APPLICATION_UPDATE_RECOVERY_TIMEOUT_SECONDS = 600;

/**
 * Bound the automatic installer exclusion index without removing job evidence.
 * Type: int. Units: full GitHub commit identities. Scope: one installation.
 * Consumers: failed-source quarantine and immutable download admission.
 * Rationale: 32 recent bad commits bound preference storage; all original failed
 * jobs and the highest failed stable version remain independently preserved.
 */
const APPLICATION_UPDATE_FAILED_COMMIT_LIMIT = 32;

/**
 * Bound migration evidence admitted for verified file recovery.
 * Type: int. Units: migration records. Scope: one updater database snapshot.
 * Consumers: schema inspection model and recovery compatibility checks.
 * Rationale: 1024 exceeds the maintained migration chain while refusing
 * oversized or unexpected ledgers instead of truncating safety evidence.
 */
const APPLICATION_UPDATE_RECOVERY_MIGRATION_LIMIT = 1024;

/**
 * Bound complete schema metadata read before restoring prior files.
 * Type: int. Units: metadata rows. Scope: each column/index inventory.
 * Consumers: recovery database evidence model.
 * Rationale: 16384 rows exceeds the shipped schema; overflow refuses recovery
 * rather than hashing incomplete evidence on large shared databases.
 */
const APPLICATION_UPDATE_RECOVERY_SCHEMA_ROWS = 16384;

/**
 * Keep immutable archive metadata within an ordinary background slice.
 * Type: int. Units: seconds. Scope: one download-source GitHub API call.
 * Consumers: commit resolution and published-archive fallback selection.
 * Rationale: two seconds bounds each metadata hop before streaming begins;
 * fallback selection performs only one such request per worker invocation.
 */
const APPLICATION_UPDATE_COMMIT_LOOKUP_SECONDS = 2;

/**
 * Bound recovery package hashing before yielding a durable cursor.
 * Type: int. Units: files. Scope: one recovery package validation slice.
 * Consumers: full recovery manifest verification.
 * Rationale: forty files plus the elapsed-time gate fit shared-host slices.
 */
const APPLICATION_UPDATE_RECOVERY_VERIFY_FILES = 40;

/**
 * Bound the recovery manifest before decoding it.
 * Type: int. Units: bytes. Scope: one validated recovery package manifest.
 * Consumers: recovery package integrity admission.
 * Rationale: eight MiB exceeds the maintained manifest without admitting a
 * full archive entry's allocation as unbounded JSON safety evidence.
 */
const APPLICATION_UPDATE_RECOVERY_MANIFEST_BYTES = 8 * 1024 * 1024;

/**
 * Bound authored runtime protocol declarations inspected before activation.
 * Type: int. Units: bytes per file. Scope: early runtime and bootstrap readers.
 * Consumers: automatic recovery observation-window admission.
 * Rationale: 65536 bytes covers these small entry files; larger declarations
 * cannot establish rollback compatibility through an unbounded allocation.
 */
const APPLICATION_UPDATE_RECOVERY_PROTOCOL_BYTES = 65536;

// This module is split into focused part files under app/services/updates_jobs/.
// Per-request time budget and runtime limit resolution.
require_once __DIR__ . '/updates_jobs/budget.php';
// Safe error reduction and retry classification.
require_once __DIR__ . '/updates_jobs/errors.php';
// Job state persistence, stage transitions, and locking.
require_once __DIR__ . '/updates_jobs/state.php';
// Job start, advance, cancel, and retry entry points.
require_once __DIR__ . '/updates_jobs/lifecycle.php';
// Release archive download, validation, and extraction.
require_once __DIR__ . '/updates_jobs/download.php';
// Replacement plan construction and backup staging.
require_once __DIR__ . '/updates_jobs/plan.php';
// Gated activation, migration, and finalization.
require_once __DIR__ . '/updates_jobs/activation.php';
// Bounded pruning of finished job artifacts.
require_once __DIR__ . '/updates_jobs/cleanup.php';
