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

require_once __DIR__ . '/cli_guard.php';
gallery_require_cli_sapi();

/** CLI recovery assurance. Never bootstraps an installation or connects to a database. */
require_once __DIR__ . '/recovery/cli.php';

exit(\Gallery\Recovery\main($argv));
