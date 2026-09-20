<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/session_contention_runtime.php
 * Module Type: Test Fixture
 * Purpose: Provide isolated adapters for real session and identity code.
 * Responsibilities:
 *   - Exercise session startup, current-user and title-completion paths without installation data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Disposable adapters for real session startup, current_user and title completion.
 * No installation bootstrap, configuration, database or writable data is loaded.
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the synthetic in-memory database used by real authentication/model reads.
     *
     * @return \PDO Per-request SQLite fixture with generated public sample rows only.
     */
    function db(): \PDO
    {
        return $GLOBALS['session_contention_database'];
    }

    /**
     * Declare the fixture's exclusively loopback plain-HTTP transport.
     *
     * @return bool Always false; this is not production HTTPS detection.
     */
    function request_is_https(): bool
    {
        return false;
    }
}

namespace Gallery\Services {
    /**
     * Observe the real bootstrap's existing session timing marks without telemetry writes.
     *
     * @param string $name Existing session_start_begin/session_start_end marker.
     * @param array<string,mixed> $context Bounded bootstrap context; never retained.
     * @return void Records the two timestamps and an optional owned, pre-session reader barrier.
     */
    function admin_test_run_mark(string $name, array $context = []): void
    {
        if ($name === 'session_start_begin' && ($GLOBALS['session_contention_reader_barrier'] ?? '') !== '') {
            \Gallery\Tests\SessionContention\check(
                file_put_contents($GLOBALS['session_contention_reader_barrier'], 'ready') !== false,
                'Fixture reader startup barrier failed.'
            );
        }
        if (in_array($name, ['session_start_begin', 'session_start_end'], true)) {
            $GLOBALS['session_contention_marks'][$name] = microtime(true);
        }
    }
}

namespace Gallery\Controllers {
    /**
     * Clear fixture response caching before the real JSON controller applies its policy.
     *
     * @return void Removes only the response cache header in this isolated router.
     */
    function clear_response_cache_headers(): void
    {
        header_remove('Cache-Control');
    }
}

namespace {
    require_once dirname(__DIR__, 2) . '/app/security.php';
    require_once dirname(__DIR__, 2) . '/app/bootstrap/session.php';
    require_once dirname(__DIR__, 2) . '/app/models/galleries.php';
    require_once dirname(__DIR__, 2) . '/app/services/gallery_picker.php';
    require_once dirname(__DIR__, 2) . '/app/controllers/admin_gallery_title_completion.php';

    // Per-request disposable schema is sufficient for the real current_user() and
    // title model SQL. This adapter deliberately does not claim MySQL/login coverage.
    $database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $database->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT)');
    $database->exec("INSERT INTO users VALUES (1, 'session-fixture-admin', 'admin')");
    $database->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY, parent_id INTEGER, title TEXT, created_at TEXT)');
    $database->exec("INSERT INTO galleries VALUES (1, 0, 'Flight sample', '2026-09-20 12:00:00')");
    $GLOBALS['session_contention_database'] = $database;
    $GLOBALS['session_contention_marks'] = [];
}
