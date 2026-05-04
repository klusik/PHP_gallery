<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/migrate.php
 * Module Type: CLI Utility
 *
 * Purpose:
 *   Provides a command-line maintenance utility for installation, migration, deployment, or administration.
 *
 * Responsibilities:
 *   - Run from command line or deployment workflow
 *   - Reuse project bootstrap code when needed
 *   - Report failures clearly to the operator
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
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

// Variable $ran stores this steps working value.
$ran = run_migrations();
echo $ran ? "Applied migrations:\n" . implode("\n", $ran) . "\n" : "No pending migrations.\n";

