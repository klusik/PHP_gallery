<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cache_transport_maintenance_test.php
 * Module Type: Regression Test
 * Purpose: Prevent passive updater reads and explicit cache actions from advancing background work.
 * Responsibilities: Exercise transport selection independently of installation state and preserve ordinary maintenance triggers.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /**
     * Supply the current fixture method without accessing a web request.
     * @return string GET or POST selected by the isolated test.
     */
    function request_method(): string { return $GLOBALS['cache_transport_method']; }
    /**
     * Keep fixture routes outside the unrelated media-asset exemption.
     * @param string $page Route under test.
     * @return bool False for this explicit Admin transport fixture.
     */
    function cms_route_is_read_only_media_asset(string $page): bool { return false; }
}
namespace Gallery\Services {
    /**
     * Supply an interval without reading installation settings.
     * @return int Fixture metadata interval in seconds.
     */
    function application_autoupdate_interval_seconds(): int { return 60; }
    /**
     * Observe whether startup dispatches background updater work.
     * @param int $ttlSeconds Requested metadata interval in seconds.
     * @param string $requestMethod Fixture HTTP method.
     * @param (callable(string):void)|null $loadDependencies Optional loader accepting a module name; deliberately unused by this isolated probe.
     * @return void Records a dispatch without running any worker.
     */
    function application_autoupdate_maybe_run(int $ttlSeconds, string $requestMethod, ?callable $loadDependencies = null): void
    {
        $GLOBALS['cache_transport_background_calls']++;
    }
}
namespace {
require_once __DIR__ . '/../app/bootstrap/maintenance.php';

/**
 * Require the scoped transport predicate to preserve background-work ownership.
 * @param bool $condition Expected transport classification.
 * @param string $message Explanation of the violated route/method boundary.
 * @return void Throws when the route classification regresses.
 */
function assert_cache_transport(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
use function Gallery\Core\cms_request_owns_cache_transport;
assert_cache_transport(cms_request_owns_cache_transport('admin_update', 'GET', true, ''), 'Passive status GET may execute background updater work.');
assert_cache_transport(cms_request_owns_cache_transport('site_maintenance_cron', 'POST', false, ''), 'Private workers may run an implicit updater before token and single-use ticket validation.');
    foreach (['cache_cleanup', 'cache_diagnostic', 'cache_job_stop', 'cache_inventory_start', 'cache_inventory_step', 'cache_cleanup_start', 'cache_cleanup_step'] as $action) {
    assert_cache_transport(cms_request_owns_cache_transport('admin_site_maintenance_settings', 'POST', false, $action), 'Explicit cache POST may run unrelated background work before its owner.');
}
foreach ([['admin_update','GET',false,''], ['admin_update','POST',true,''], ['admin','GET',true,''],
    ['admin_site_maintenance_settings','GET',false,'cache_job_stop'], ['admin_site_maintenance_settings','POST',false,'save'],
    ['public_gallery','GET',true,'cache_cleanup']] as [$route,$method,$statusRead,$action]) {
    assert_cache_transport(!cms_request_owns_cache_transport($route,$method,$statusRead,$action), 'Unrelated route/method/preference action bypassed ordinary maintenance.');
}
// Exercise the actual startup hook, not only its route classification.
$GLOBALS['cache_transport_background_calls'] = 0;
$GLOBALS['cache_transport_method'] = 'GET';
$_GET = ['update_job_status' => '1'];
$_POST = [];
\Gallery\Core\cms_run_request_maintenance('admin_update');
\Gallery\Core\cms_run_request_maintenance('site_maintenance_cron');
$GLOBALS['cache_transport_method'] = 'POST';
$_GET = [];
foreach (['cache_cleanup', 'cache_diagnostic', 'cache_job_stop'] as $action) {
    $_POST = ['site_maintenance_action' => $action];
    \Gallery\Core\cms_run_request_maintenance('admin_site_maintenance_settings');
}
assert_cache_transport($GLOBALS['cache_transport_background_calls'] === 0, 'Passive/explicit transports actually dispatched background work.');
$_POST = ['site_maintenance_action' => 'save'];
\Gallery\Core\cms_run_request_maintenance('admin_site_maintenance_settings');
assert_cache_transport($GLOBALS['cache_transport_background_calls'] === 1, 'Ordinary settings request lost its established background trigger.');
echo "PASS explicit cache transports and actual startup background dispatch ownership\n";
}
