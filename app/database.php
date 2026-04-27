<?php

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

    $database = cms_config()['database'];
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $database['host'], $database['name'], $database['charset'] ?? 'utf8mb4');
    if (!empty($database['port'])) {
        $dsn .= ';port=' . (int) $database['port'];
    }
    $pdo = new PDO($dsn, $database['user'], $database['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

