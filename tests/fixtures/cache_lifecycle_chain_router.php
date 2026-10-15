<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/cache_lifecycle_chain_router.php
 * Module Type: Isolated HTTP Fixture
 * Purpose: Exercise real authenticated self-requests after one public visit on owned loopback storage.
 * Responsibilities: Provide deterministic time and database-free preferences while retaining the production transport, HTTP controller and worker owner.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /**
     * Return the fixture's independently fixed installation origin.
     * @return array{base_url:string} Owned loopback configuration, never a caller's Host header.
     */
    function cms_config(): array { return ['base_url'=>'http://127.0.0.1:18812']; }
    /**
     * Supply the same isolated database double as the existing log regression.
     * @return \CacheDiagnosticDatabase Owned query/insert receiver, never a live connection.
     */
    function db(): \CacheDiagnosticDatabase { return $GLOBALS['cache_diagnostic_db']; }
    /**
     * Represent an unauthenticated independent maintenance request.
     * @return null No administrator session is attached to the worker.
     */
    function current_user(): ?array { return null; }
    /**
     * Timestamp the real logger from the owned virtual clock.
     * @return string SQL-compatible UTC timestamp for the normalized log row.
     */
    function now_sql(): string { return gmdate('Y-m-d H:i:s', $GLOBALS['cache_lifecycle_test_now']); }
}
namespace Gallery\Services {
    /**
     * Yield the virtual timer so the parent can observe and stop the real HTTP chain.
     * Type: int. Units: microseconds. Scope: one marker-owned virtual wait.
     * Consumers: fixture-only sleep seam. Rationale: ten milliseconds prevents a busy loop while preserving the real production wait argument and HTTP continuation.
     */
    const CACHE_CHAIN_HTTP_WAIT_PACE_US = 10000;

    /**
     * Read only owned fixture preferences and a fixed maintenance credential.
     * @param string $key Requested canonical preference.
     * @param string|null $default Missing-value fallback.
     * @return string|null Fixture token, explicit OFF, empty recovery reference or the supplied fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        if ($key === 'site_maintenance_token') return str_repeat('a', 64);
        if ($key === 'application_update_beta_backup_path') return '';
        return $key === 'feature_flag.automatic_cache_cleanup.enabled' && is_file(CACHE_LIFECYCLE_TEST_ROOT . '/off') ? '0' : $default;
    }
    /**
     * Resolve the real owner's capability against the fixture's opt-out marker.
     * @param string $key Requested capability identity.
     * @return bool True for automatic cleanup while the marker is absent.
     */
    function feature_capability_effective_enabled(string $key): bool { return $key === 'automatic_cache_cleanup' && !is_file(CACHE_LIFECYCLE_TEST_ROOT . '/off'); }
    /**
     * Advance virtual time for production timer hops while briefly yielding the test process.
     * @param int $seconds Production owner's bounded wait seconds.
     * @return int Zero remaining seconds; only this marker-owned fixture bypasses real thirty-second waits.
     */
    function sleep(int $seconds): int
    {
        $GLOBALS['cache_lifecycle_test_now'] += $seconds;
        file_put_contents(CACHE_LIFECYCLE_TEST_ROOT . '/clock', (string) $GLOBALS['cache_lifecycle_test_now'], LOCK_EX);
        usleep(CACHE_CHAIN_HTTP_WAIT_PACE_US);
        return 0;
    }
}
namespace Gallery\Models {
    /**
     * Resolve the absence of a central mutation owner in the owned fixture.
     * @param bool $requireKnown Whether strict claim evidence was requested.
     * @return null No conflicting central mutation exists.
     */
    function maintenance_center_model_mutation_owner(bool $requireKnown = false): ?array { return null; }
}
namespace {
    $root = getenv('CACHE_CHAIN_HTTP_ROOT');
    if (!is_string($root) || !is_file($root . '/.chain-http-owner')) { http_response_code(404); return; }
    // Route every production cache operation into this marker-owned test root.
    // Type: string. Units: absolute path. Scope: one disposable HTTP request.
    // Consumers: lifecycle path guards. Rationale: the fixture never sees installation cache, settings, tokens or payloads.
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    $GLOBALS['cache_lifecycle_test_now'] = (int) file_get_contents($root . '/clock');
    require_once dirname(__DIR__) . '/support/cache_diagnostic_database.php';
    $GLOBALS['cache_diagnostic_db'] = new \CacheDiagnosticDatabase();
    $GLOBALS['cache_diagnostic_rows'] = [];
    $GLOBALS['cache_diagnostic_refuse'] = false;
    $GLOBALS['cache_diagnostic_sink'] = static function (array $row) use ($root): bool {
        return file_put_contents($root . '/events.jsonl', json_encode(['key'=>$row['event_key'],
            'context'=>json_decode($row['context_json'], true, 16, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR)
            . "\n", FILE_APPEND | LOCK_EX) !== false;
    };
    require_once dirname(__DIR__, 2) . '/app/request_data.php';
    require_once dirname(__DIR__, 2) . '/app/services/cache_cleanup_diagnostics.php';
    require_once dirname(__DIR__, 2) . '/app/services/cache_lifecycle.php';
    require_once dirname(__DIR__, 2) . '/app/services/site_maintenance.php';
    if (($_GET['page'] ?? '') === 'site_maintenance_cron') {
        file_put_contents($root . '/arrivals.jsonl', "worker\n", FILE_APPEND | LOCK_EX);
        require_once dirname(__DIR__, 2) . '/app/controllers/site_maintenance.php';
        \Gallery\Controllers\cms_site_maintenance_cron();
        return;
    }
    file_put_contents($root . '/arrivals.jsonl', "public\n", FILE_APPEND | LOCK_EX);
    \Gallery\Services\site_maintenance_register_cache_trigger('home');
    header('Content-Type: text/html; charset=utf-8');
    echo '<main>One public visit</main>';
}
