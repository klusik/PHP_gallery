<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/session_contention_application_router.php
 * Module Type: Test Fixture
 * Purpose: Exercise actual cms_run/login/suggestion lifecycle inside an owned application clone.
 * Responsibilities:
 *   - Observe existing trace marks and session writes without replacing production handlers.
 *   - Keep the synthetic holder and private controls outside application routes.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Diagnostics {
    /**
     * Observe the existing request trace hook without replacing Core or service functions.
     * @param string $name Existing lifecycle mark.
     * @param array<string,mixed> $context Bounded trace context, not retained.
     * @return void Signals entry immediately before session startup and snapshots dispatch state.
     */
    function admin_test_run_early_mark(string $name, array $context = []): void
    {
        if ($name === 'config_load_end' && ($GLOBALS['session_route_enter_path'] ?? '') !== '') {
            \Gallery\Tests\SessionContention\check(
                file_put_contents($GLOBALS['session_route_enter_path'], 'ready') !== false,
                'Application reader entry barrier failed.'
            );
        }
        if ($name === 'dispatch_start') {
            $GLOBALS['session_route_before_dispatch'] = $_SESSION;
        }
    }
}

namespace {
    require_once __DIR__ . '/session_contention.php';
    require_once __DIR__ . '/gallery_workflow_safety.php';

    use function Gallery\Tests\SessionContention\check;
    use const Gallery\Tests\SessionContention\HOLD_TIMEOUT_SECONDS;
    use const Gallery\Tests\SessionContention\MILLISECONDS_PER_SECOND;
    use const Gallery\Tests\SessionContention\POLL_MICROSECONDS;

    /**
     * Emit only numeric session duration and handler; existing application marks own timing.
     * @return void No diagnostic context, cookies, paths or identifiers are exposed.
     */
    function session_route_timing_headers(): void
    {
        $times = [];
        foreach ($GLOBALS['admin_test_run_request']['marks'] ?? [] as $mark) {
            if (in_array($mark['name'] ?? '', ['session_start_begin', 'session_start_end'], true)) {
                $times[$mark['name']] = $mark['at_unix'];
            }
        }
        check(isset($times['session_start_begin'], $times['session_start_end']), 'Actual application session marks missing.');
        header('X-Fixture-Session-Ms: ' . number_format(($times['session_start_end'] - $times['session_start_begin']) * MILLISECONDS_PER_SECOND, 3, '.', ''));
        header('X-Fixture-Handler: files');
    }

    try {
        $owner = (string) getenv('GALLERY_WORKFLOW_TOKEN');
        check(PHP_SAPI === 'cli-server' && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
            && $owner !== '' && hash_equals($owner, (string) ($_SERVER['HTTP_X_FIXTURE_OWNER'] ?? '')), 'Application fixture authority refused.');
        $directory = \GalleryWorkflow\validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $owner);
        check(realpath((string) ini_get('session.save_path')) === realpath($directory . '/sessions')
            && session_module_name() === 'files', 'Application fixture session storage mismatch.');
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        if ($path === '/health') {
            echo $owner;
            return;
        }
        check(in_array($path, ['/index.php', '/hold', '/control'], true), 'Application fixture route refused.');
        if ($path === '/index.php') {
            check(in_array($_GET['page'] ?? '', ['admin_login', 'admin_logout', 'admin_gallery_title_completion', 'admin_gallery_picker_search'], true),
                'Application fixture page refused.');
        } else {
            $_GET['page'] = 'admin_gallery_title_completion';
        }
        $scenario = (string) ($_GET['scenario'] ?? '');
        $reader = (string) ($_GET['reader'] ?? '');
        $GLOBALS['session_route_enter_path'] = '';
        if ($reader !== '') {
            check(preg_match('/^(?:held|released)-[0-2]$/D', $scenario) === 1 && in_array($reader, ['same', 'independent'], true), 'Application fixture sample refused.');
            $GLOBALS['session_route_enter_path'] = $directory . '/enter-' . $scenario . '-' . $reader;
        }
        require $directory . '/app/bootstrap.php';
        $traceToken = (string) file_get_contents($directory . '/session-trace-token');
        check(preg_match('/^[a-f0-9]{32}$/D', $traceToken) === 1, 'Application trace ownership unavailable.');
        $_COOKIE[\Gallery\Services\ADMIN_TEST_RUN_COOKIE] = $traceToken;

        if ($path === '/index.php') {
            // cms_run performs the unchanged startup, translation/viewer restoration,
            // maintenance, real dispatcher/controller, and response completion lifecycle.
            ob_start();
            \Gallery\Core\cms_run();
            $body = (string) ob_get_clean();
            session_route_timing_headers();
            $before = $GLOBALS['session_route_before_dispatch'] ?? [];
            $late = [];
            $otherChanges = 0;
            foreach (array_unique(array_merge(array_keys($before), array_keys($_SESSION))) as $key) {
                if (($before[$key] ?? null) !== ($_SESSION[$key] ?? null)) {
                    if (in_array($key, ['user_id', 'csrf_token', 'cms_language', 'cms_admin_language', 'cms_translation_context', 'cms_translation_missing'], true)) {
                        $late[] = $key;
                    } else {
                        $otherChanges++;
                    }
                }
            }
            header('X-Fixture-Late-Session-Keys: ' . implode(',', $late));
            header('X-Fixture-Other-Session-Changes: ' . $otherChanges);
            header('X-Fixture-Session-Active: ' . (session_status() === PHP_SESSION_ACTIVE ? '1' : '0'));
            echo $body;
            return;
        }

        // Only private fixture controls use this short sequence; product requests use cms_run above.
        \Gallery\Core\cms_request_trace_begin();
        \Gallery\Core\cms_start_session(\Gallery\Core\cms_config());
        \Gallery\Core\cms_initialize_request();
        \Gallery\Core\require_admin();
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            \Gallery\Core\verify_csrf();
        }
        session_route_timing_headers();
        header('Content-Type: application/json');
        header('Cache-Control: private, no-store');
        if ($path === '/control') {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'language') {
                check(\Gallery\Services\translation_set_active_language('cs'), 'Actual language preference could not be set.');
                \Gallery\Services\translation_clear_missing_diagnostics();
            }
            echo json_encode([
                'ok' => true, 'admin' => \Gallery\Core\current_user()['role'] === 'admin',
                'csrf_token' => \Gallery\Core\csrf_token(), 'language' => $_SESSION['cms_admin_language'] ?? '',
                'session_name' => session_name(), 'remember_name' => \Gallery\Services\auth_remember_cookie_name(),
                'missing_count' => count(\Gallery\Services\translation_missing_diagnostics()),
            ], JSON_THROW_ON_ERROR);
            return;
        }
        check(($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && preg_match('/^(?:held|released)-[0-2]$/D', $scenario) === 1, 'Application holder refused.');
        if (str_starts_with($scenario, 'released-')) {
            session_write_close();
        }
        check(file_put_contents($directory . '/ready-' . $scenario, 'ready') !== false, 'Application holder readiness failed.');
        $deadline = microtime(true) + HOLD_TIMEOUT_SECONDS;
        do {
            clearstatcache(true, $directory . '/release-' . $scenario);
            if (is_file($directory . '/release-' . $scenario)) {
                echo '{"ok":true}';
                return;
            }
            usleep(POLL_MICROSECONDS);
        } while (microtime(true) < $deadline);
        check(false, 'Application holder deadline exceeded.');
    } catch (\Throwable) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo '{"ok":false,"error":"fixture_application_failed"}';
    }
}
