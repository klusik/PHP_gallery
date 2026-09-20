<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Require explicit operator intent before resuming a journal's recovery service.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/reconcile_image_moves.php
 * Module Type: CLI Controller
 * Purpose: List retained move journals or explicitly reconcile one selected operation.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $arguments = array_slice($argv, 1);
    if ($arguments === [] || $arguments === ['--list']) {
        echo json_encode(\Gallery\Services\gallery_image_move_pending(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit(0);
    }
    if (count($arguments) !== 2 || !preg_match('/^--recover=([a-f0-9]{32})$/D', $arguments[0], $match)
        || $arguments[1] !== '--apply') {
        fwrite(STDERR, "Usage: php scripts/reconcile_image_moves.php --list\n"
            . "       php scripts/reconcile_image_moves.php --recover=<operation-id> --apply\n");
        exit(2);
    }
    echo json_encode(\Gallery\Services\gallery_image_move_recover($match[1]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Image move inspection/recovery refused. Verify pending migrations and storage; journals and uncertain files are retained.\n");
    exit(1);
}
