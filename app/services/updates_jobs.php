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
