<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_run_analysis.php
 * Module Type: Diagnostics Analysis Service
 *
 * Purpose:
 *   Provides bounded post-measurement analysis for Admin Test Run v1.1.3.
 *
 * Responsibilities:
 *   - Build a single-pass semantic cache inventory with explicit entry/time limits
 *   - Inventory cron, updater, maintenance, warmup, log archive, and database-maintenance mechanisms without running them
 *   - Aggregate PDO traces into per-request and whole-run SQL hotspots and conservative possible-N+1 candidates
 *   - Correlate browser timing with PHP lifecycle request IDs without claiming unavailable clock precision
 *   - Classify browser cache observations, infrastructure headers, DB write side effects, locks, and OPcache capabilities
 *   - Produce conservative info/warning/critical analysis flags with evidence and thresholds
 *   - Load the analysis part files below in dependency order
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
 *   - Implementation lives in app/services/admin_test_run_analysis/; this file is the module entry point.
 *   - The require_once list preserves the historical app/services.php include contract.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - All filesystem walks are bounded by both entry count and elapsed time.
 *   - Cron/maintenance diagnostics observe state only and never invoke maintenance/update work.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

// This module is split into focused part files under app/services/admin_test_run_analysis/.
// SQL hotspot detection and write classification.
require_once __DIR__ . '/admin_test_run_analysis/sql_analysis.php';
// Redaction of secrets and volatile identifiers.
require_once __DIR__ . '/admin_test_run_analysis/sanitization.php';
// Browser payload analysis and PHP correlation.
require_once __DIR__ . '/admin_test_run_analysis/browser.php';
// Cache inventory, cache-control, and opcache analysis.
require_once __DIR__ . '/admin_test_run_analysis/cache_analysis.php';
// Maintenance schedule, lock, and update job assessment.
require_once __DIR__ . '/admin_test_run_analysis/maintenance_analysis.php';
// Post-response work and session lock contention summaries.
require_once __DIR__ . '/admin_test_run_analysis/request_analysis.php';
// Final aggregation of analysis passes into ranked flags.
require_once __DIR__ . '/admin_test_run_analysis/flags.php';
