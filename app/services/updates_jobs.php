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

// Keep active/last state lookups available without including worker state-machine parts.
require_once __DIR__ . '/updates_job_lookup.php';

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
