<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_runs.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides opt-in, administrator-triggered full request test runs for public and Smart Galleries.
 *
 * Responsibilities:
 *   - Create short-lived test-run contexts without exposing diagnostics to anonymous visitors
 *   - Record request/bootstrap/session/maintenance/dispatch/database/process lifecycle details
 *   - Track all PHP requests caused by one browser test through sidecar files and measure concurrency
 *   - Inventory safe application caches, updater/maintenance states, locks, PHP runtime resources, and worker caps
 *   - Persist a detailed JSON report and optional ZIP download artifact under the application cache directory
 *   - Keep the diagnostic runner bounded so the test itself does not become a load generator
 *   - Own the shared module constants and load the part files below in dependency order
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
 *   - Implementation lives in app/services/admin_test_runs/; this file is the module entry point.
 *   - The require_once list preserves the historical app/services.php include contract.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *   - Test runs never sleep to simulate throttling and never intentionally create parallel PHP probes.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

const ADMIN_TEST_RUN_COOKIE = 'gallery_admin_test_run';
const ADMIN_TEST_RUN_TTL_SECONDS = 600;
const ADMIN_TEST_RUN_SCHEMA_VERSION = 2;
const ADMIN_TEST_RUN_DIAGNOSTICS_VERSION = '20260821-admin-test-run-v1.1.3';
const ADMIN_TEST_RUN_MAX_DB_EVENTS_PER_REQUEST = 2500;
const ADMIN_TEST_RUN_CACHE_SCAN_ENTRY_CAP = 25000;
const ADMIN_TEST_RUN_CACHE_SCAN_TIME_BUDGET_MS = 250.0;
const ADMIN_TEST_RUN_MAX_REPORTS = 20;
const ADMIN_TEST_RUN_MAX_REPORT_STORAGE_BYTES = 209715200;
const ADMIN_TEST_RUN_CLEANUP_ENTRY_CAP = 2000;
const ADMIN_TEST_RUN_CLEANUP_TIME_BUDGET_MS = 80.0;

// This module is split into focused part files under app/services/admin_test_runs/.
// Shared constants stay above so every part resolves them in this namespace.
// Run token validation and filesystem path resolution.
require_once __DIR__ . '/admin_test_runs/paths.php';
// Artifact persistence, bounded pruning, and ZIP packaging.
require_once __DIR__ . '/admin_test_runs/storage.php';
// Active-run cookie, ownership checks, and target normalization.
require_once __DIR__ . '/admin_test_runs/context.php';
// Bounded runtime, cache, lock, and subsystem snapshots.
require_once __DIR__ . '/admin_test_runs/snapshot.php';
// Run creation, per-request boundaries, and finalization.
require_once __DIR__ . '/admin_test_runs/lifecycle.php';
// Hot-path instrumentation recorded during an active run.
require_once __DIR__ . '/admin_test_runs/recording.php';
// Concurrency and database summaries over recorded requests.
require_once __DIR__ . '/admin_test_runs/summary.php';
// Admin side-panel rendering for test runs.
require_once __DIR__ . '/admin_test_runs/panel.php';
