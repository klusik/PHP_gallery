<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/release_qualification.php
 * Module Type: Release Qualification CLI
 *
 * Purpose:
 *   Records artifact-bound release evidence independently of automated auditing.
 *
 * Responsibilities:
 *   - Expose init, check, record, record-audit, and render commands
 *   - Keep qualification separate from Git, packaging, and publication
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

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/release_qualification_lib.php';

exit(\PhpGallery\ReleaseQualification\run_cli(dirname(__DIR__), array_slice($argv, 1)));
