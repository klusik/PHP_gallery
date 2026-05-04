<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/database.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides core bootstrap, configuration, helper, security, database, or routing functionality.
 *
 * Responsibilities:
 *   - Support shared project infrastructure
 *   - Keep behavior compatible with existing controllers and services
 *   - Avoid unnecessary coupling to presentation code
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

/**
 * Return the shared PDO connection for the current request.
 *
 * The connection is cached in a static variable because controllers and services
 * call db() frequently while rendering one page. The optional port field is used
 * by the browser installer and local stacks such as Laragon/XAMPP.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // Variable $database stores this steps working value.
    $database = cms_config()['database'];
    // Variable $dsn stores this steps working value.
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $database['host'], $database['name'], $database['charset'] ?? 'utf8mb4');
    if (!empty($database['port'])) {
        $dsn .= ';port=' . (int) $database['port'];
    }
    // Variable $pdo stores this steps working value.
    $pdo = new PDO($dsn, $database['user'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

