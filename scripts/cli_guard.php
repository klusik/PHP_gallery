<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/cli_guard.php
 * Module Type: CLI Execution Boundary
 * Purpose: Reject direct HTTP access to CLI-only scripts without loading application code.
 * Responsibilities: Provide a reusable no-bootstrap SAPI guard for command-line entrypoints.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

/**
 * Stop execution unless PHP is running through the command-line SAPI.
 *
 * @return void No value is returned; non-CLI requests receive an empty 404 response and exit.
 */
function gallery_require_cli_sapi(): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
}

/**
 * Apply the CLI SAPI boundary only when a script is the requested entrypoint.
 *
 * @param string $entryFile Absolute path of the source file that owns optional CLI dispatch.
 * @return void Included libraries remain usable; direct non-CLI requests receive an empty 404 response.
 */
function gallery_guard_cli_entrypoint(string $entryFile): void
{
    $scriptFile = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $entryPath = realpath($entryFile);
    if ($scriptFile !== false && $entryPath !== false && $scriptFile === $entryPath) {
        gallery_require_cli_sapi();
    }
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    gallery_require_cli_sapi();
}
