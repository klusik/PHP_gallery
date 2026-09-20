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
 *   - Prepare report sections for controller-selected HTML presentation without saving an export on the server
 *   - Keep GPS place clustering approximate and exclude probable simulator/game captures where possible
 *   - Load the centralized immutable policy and the part files below in dependency order
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

require_once dirname(__DIR__) . '/policy_constants.php';

// This module is split into focused part files under app/services/admin_gallery_report/.
// Each part imports its own required constants from the loaded Core policy owner.
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
