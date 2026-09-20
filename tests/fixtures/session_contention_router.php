<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/session_contention_router.php
 * Module Type: Test Fixture
 * Purpose: Route loopback session-contention fixture requests.
 * Responsibilities:
 *   - Use only the owned disposable file store and isolated runtime adapters.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Loopback-only session contention router with an owned disposable file store.
 * This router never serves files or bootstraps the active installation.
 */
declare(strict_types=1);

use function Gallery\Tests\SessionContention\check;
use function Gallery\Tests\SessionContention\owned_directory;
use const Gallery\Tests\SessionContention\HOLD_TIMEOUT_SECONDS;
use const Gallery\Tests\SessionContention\MILLISECONDS_PER_SECOND;
use const Gallery\Tests\SessionContention\POLL_MICROSECONDS;
use const Gallery\Tests\SessionContention\DELAYED_READER_MILLISECONDS;

require_once dirname(__DIR__) . '/support/session_contention.php';

try {
    $owner = (string) getenv('GALLERY_SESSION_OWNER');
    check(PHP_SAPI === 'cli-server' && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
        && $owner !== '' && hash_equals($owner, (string) ($_SERVER['HTTP_X_FIXTURE_OWNER'] ?? '')), 'Fixture request refused.');
    $directory = owned_directory((string) getenv('GALLERY_SESSION_FIXTURE'), $owner);
    check(realpath((string) ini_get('session.save_path')) === realpath($directory . '/sessions')
        && !is_link($directory . '/sessions') && session_module_name() === 'files', 'Fixture session storage mismatch.');
    $route = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    header('Cache-Control: private, no-store');
    if ($route === '/health') {
        echo $owner;
        return;
    }
    $GLOBALS['session_contention_reader_barrier'] = '';
    if ($route === '/suggest' && isset($_GET['scenario'], $_GET['reader'])) {
        $scenario = (string) $_GET['scenario'];
        $reader = (string) $_GET['reader'];
        check(preg_match('/\A(?:held|released)-[0-2]\z/', $scenario) === 1
            && in_array($reader, ['same', 'independent'], true), 'Invalid fixture reader barrier.');
        if ($scenario === 'held-2' && $reader === 'same') {
            // Simulate scheduling/startup latency longer than the observation window.
            usleep(DELAYED_READER_MILLISECONDS * MILLISECONDS_PER_SECOND);
        }
        $GLOBALS['session_contention_reader_barrier'] = $directory . '/enter-' . $scenario . '-' . $reader;
    }
    require_once dirname(__DIR__) . '/support/session_contention_runtime.php';
    Gallery\Core\cms_start_session(['admin_session_name' => 'session_fixture']);
    $marks = $GLOBALS['session_contention_marks'];
    $milliseconds = ($marks['session_start_end'] - $marks['session_start_begin']) * MILLISECONDS_PER_SECOND;
    header('X-Fixture-Session-Ms: ' . number_format($milliseconds, 3, '.', ''));
    header('X-Fixture-Handler: files');
    header('Content-Type: application/json');
    if ($route === '/login' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        // Fixture-only session issuance requires the private owner header. Identity
        // reads below still execute the application's real current_user() function.
        session_regenerate_id(true);
        $_SESSION['user_id'] = 1;
        $_SESSION['fixture_preference'] = 'retained';
        $_SESSION['fixture_csrf'] = Gallery\Core\csrf_token();
        echo '{"ok":true}';
        return;
    }
    if ($route === '/suggest') {
        $_GET = ['q' => 'Fl', 'parent_id' => '0'];
        Gallery\Controllers\cms_admin_gallery_title_completion();
        return;
    }
    $user = Gallery\Core\current_user();
    check(is_array($user) && $user['role'] === 'admin', 'Fixture administrator required.');
    if ($route === '/state') {
        echo json_encode(['ok' => ($_SESSION['fixture_preference'] ?? '') === 'retained'
            && hash_equals((string) ($_SESSION['fixture_csrf'] ?? ''), Gallery\Core\csrf_token())], JSON_THROW_ON_ERROR);
        return;
    }
    $scenario = (string) ($_GET['scenario'] ?? '');
    check($route === '/hold' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && preg_match('/\A(?:held|released)-[0-2]\z/', $scenario) === 1, 'Fixture route refused.');
    if (str_starts_with($scenario, 'released-')) {
        session_write_close();
    }
    check(file_put_contents($directory . '/ready-' . $scenario, 'ready') !== false, 'Fixture readiness write failed.');
    $deadline = microtime(true) + HOLD_TIMEOUT_SECONDS;
    do {
        clearstatcache(true, $directory . '/release-' . $scenario);
        if (is_file($directory . '/release-' . $scenario)) {
            echo '{"ok":true}';
            return;
        }
        usleep(POLL_MICROSECONDS);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Fixture holder deadline exceeded.');
} catch (Throwable) {
    http_response_code(500);
    echo '{"ok":false}';
}
