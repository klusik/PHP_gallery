<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_migration.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides gallery-to-gallery migration support over the existing API-key
 *   automation model.
 *
 * Responsibilities:
 *   - Build versioned gallery migration manifests
 *   - Validate exact-version compatibility for migration jobs
 *   - Persist small resumable migration job state files
 *   - Install originals, thumbnails, and gallery assets without regeneration
 *   - Keep outbound HTTP work bounded to one manifest or one ZIP package per request
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
 *   - Implementation lives in app/services/gallery_migration/; this file is the module entry point.
 *   - The require_once list preserves the historical app/services.php include contract.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

const GALLERY_MIGRATION_PROTOCOL_VERSION = 2;
const GALLERY_MIGRATION_TIMEOUT_SECONDS = 45;
const GALLERY_MIGRATION_RECONNECT_SECONDS = 30;
const GALLERY_MIGRATION_PACKAGE_MAX_ASSETS = 2048;

// This module is split into focused part files under app/services/gallery_migration/.
// Shared constants stay above so every part resolves them in this namespace.
// Protocol compatibility, timeouts, and instance identity.
require_once __DIR__ . '/gallery_migration/versions.php';
// Resumable job identity, persistence, and status reporting.
require_once __DIR__ . '/gallery_migration/jobs.php';
// Gallery and image metadata serialization.
require_once __DIR__ . '/gallery_migration/metadata.php';
// Asset enumeration, addressing, and source authorization.
require_once __DIR__ . '/gallery_migration/assets.php';
// Manifest construction, lookup, and validation.
require_once __DIR__ . '/gallery_migration/manifest.php';
// Bounded multi-asset transfer package planning.
require_once __DIR__ . '/gallery_migration/packages.php';
// Target gallery tree preparation and metadata application.
require_once __DIR__ . '/gallery_migration/target_setup.php';
// Asset installation and image row registration.
require_once __DIR__ . '/gallery_migration/install.php';
// Existing-file recovery for resumed migrations.
require_once __DIR__ . '/gallery_migration/recovery.php';
// Authenticated instance-to-instance HTTP transport.
require_once __DIR__ . '/gallery_migration/http.php';
