<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Keep restored installation bootstrap and credentials outside the CLI workflow.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/recovery.php
 * Module Type: CLI Utility
 *
 * Purpose:
 *   Expose inventory, isolated validation and synthetic recovery rehearsal commands.
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
 *   - Never bootstrap a restored installation or read installation credentials.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

/** CLI recovery assurance. Never bootstraps an installation or connects to a database. */
require_once __DIR__ . '/recovery/cli.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

exit(\Gallery\Recovery\main($argv));
