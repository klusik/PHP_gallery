<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/release_qualification_lib.php
 * Module Type: Release Qualification Library
 *
 * Purpose:
 *   Owns the release evidence schema and the ordered support-module contract.
 *
 * Responsibilities:
 *   - Bind manual review and audit evidence to versioned content snapshots
 *
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace PhpGallery\ReleaseQualification;

const SCHEMA_VERSION = 1;
const SCOPE_VERSION = 1;
const MANUAL_CHECKS = [
    'pdf-title' => ['label' => 'PDF title, target version and edition date', 'phase' => 'pre-publication'],
    'pdf-contents' => ['label' => 'PDF table of contents', 'phase' => 'pre-publication'],
    'pdf-index' => ['label' => 'PDF index', 'phase' => 'pre-publication'],
    'pdf-changed-pages' => ['label' => 'PDF changed feature sections', 'phase' => 'pre-publication'],
    'pdf-links-layout' => ['label' => 'PDF bookmarks, internal links and page layout', 'phase' => 'pre-publication'],
    'browser-smoke' => ['label' => 'Applicable browser smoke workflows', 'phase' => 'pre-publication'],
    'post-publication-smoke' => ['label' => 'Published updater, migrations, login, gallery and integrity smoke', 'phase' => 'post-publication'],
];

require_once __DIR__ . '/release_lib.php';
require_once __DIR__ . '/audit_lib.php';
require_once __DIR__ . '/release_qualification/fingerprint.php';
require_once __DIR__ . '/release_qualification/record.php';
require_once __DIR__ . '/release_qualification/audit.php';
require_once __DIR__ . '/release_qualification/preview.php';
require_once __DIR__ . '/release_qualification/cli.php';
