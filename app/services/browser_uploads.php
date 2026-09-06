<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/browser_uploads.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides the client-side upload package settings and server-side
 *   package acceptance workflow.
 *
 * Responsibilities:
 *   - Normalize administrator-controlled browser upload settings
 *   - Derive safe browser batch sizes from PHP upload limits
 *   - Parse store-only ZIP packages without depending on ZipArchive
 *   - Place browser-prepared originals and thumbnails inside a gallery folder
 *   - Refresh image and thumbnail metadata after a successful batch
 *   - Own the shared module constant and load the part files below in dependency order
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
 *   - Implementation lives in app/services/browser_uploads/; this file is the module entry point.
 *   - The require_once list preserves the historical app/services.php include contract.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

const BROWSER_UPLOAD_BATCH_POLICY_LIMIT_RATIO = 'upload_limit_ratio';

// This module is split into focused part files under app/services/browser_uploads/.
// Shared constants stay above so every part resolves them in this namespace.
// Structured validation exception, declared before any part file can throw it.
require_once __DIR__ . '/browser_uploads/exception.php';
// Setting normalization, persistence, and browser configuration.
require_once __DIR__ . '/browser_uploads/settings.php';
// Stored-entry ZIP container parsing.
require_once __DIR__ . '/browser_uploads/zip_parsing.php';
// Resumable per-batch cache, state, and source ordering.
require_once __DIR__ . '/browser_uploads/batch_state.php';
// Payload signature validation and safe filename preparation.
require_once __DIR__ . '/browser_uploads/payload_validation.php';
// Per-batch manifest construction and validation.
require_once __DIR__ . '/browser_uploads/manifest.php';
// Batch ingestion orchestration entry points.
require_once __DIR__ . '/browser_uploads/pipeline.php';
