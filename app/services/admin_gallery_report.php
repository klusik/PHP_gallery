<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_report.php
 * Module Type: Service
 *
 * Purpose:
 *   Builds the complete Admin gallery overview report.
 *
 * Responsibilities:
 *   - Collect gallery, image, EXIF, GPS, storage, database, telemetry, and runtime diagnostics
 *   - Process image-heavy checks in browser-driven batches to avoid shared-hosting timeouts
 *   - Render a single self-contained HTML report without saving the generated output on the server
 *   - Keep GPS place clustering approximate and exclude probable simulator/game captures where possible
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
 *   - Implementation lives in app/services/admin_gallery_report/; this file is the module entry point.
 *   - The require_once list preserves the historical app/services.php include contract.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

const ADMIN_GALLERY_REPORT_JOB_KEY = 'admin_gallery_report_job_v1';
const ADMIN_GALLERY_REPORT_DEFAULT_BATCH_SIZE = 20;
const ADMIN_GALLERY_REPORT_MAX_BATCH_SIZE = 100;
const ADMIN_GALLERY_REPORT_GPS_AREA_KM = 20.0;
const ADMIN_GALLERY_REPORT_PLACE_MATCH_DEFAULT_RADIUS_KM = 35.0;

// This module is split into focused part files under app/services/admin_gallery_report/.
// Shared constants stay above so every part resolves them in this namespace.
// Job lifecycle and persisted batch state for the browser-driven report.
require_once __DIR__ . '/admin_gallery_report/job.php';
// Incremental image statistics accumulated across report batches.
require_once __DIR__ . '/admin_gallery_report/image_summary.php';
// Approximate GPS clustering and known-place labelling.
require_once __DIR__ . '/admin_gallery_report/gps.php';
// Host, runtime, memory, and storage diagnostics.
require_once __DIR__ . '/admin_gallery_report/system_summary.php';
// Database usage section built from enumerated base tables.
require_once __DIR__ . '/admin_gallery_report/database_section.php';
// Gallery, tag, vote, feature, log, and telemetry aggregation.
require_once __DIR__ . '/admin_gallery_report/content_summary.php';
// Read-only query, schema probing, and grouping helpers.
require_once __DIR__ . '/admin_gallery_report/query_helpers.php';
// Pure value formatting and labelling helpers.
require_once __DIR__ . '/admin_gallery_report/format.php';
// Self-contained HTML rendering for the finished report.
require_once __DIR__ . '/admin_gallery_report/render.php';
