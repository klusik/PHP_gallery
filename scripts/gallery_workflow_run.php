<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/gallery_workflow_run.php
 * Module Type: CLI Tool
 * Purpose: Run workflows against a disposable real-database application.
 * Responsibilities:
 *   - Own the isolated application lifecycle and pass checks to the central audit.
 * Author: Rudolf Klusal
 * Run a disposable real-database application through development checks or the central audit.
 */
declare(strict_types=1);

require_once __DIR__ . '/cli_guard.php';
gallery_require_cli_sapi();
// Preserve the historical convenience flag without provisioning a database for
// an edit-cycle profile that intentionally excludes real workflow qualification.
if (($argv[1] ?? '') === '--quick') {
    $argv = [__DIR__ . '/audit.php', '--profile=quick'];
    require __DIR__ . '/audit.php';
    exit;
}
if (!is_file(__DIR__ . '/../tests/support/gallery_workflow_fixture.php')) {
    fwrite(STDERR, "BLOCKED gallery workflow source checkout with test support required\n");
    exit(1);
}
require_once __DIR__ . '/../tests/support/gallery_workflow_fixture.php';
require_once __DIR__ . '/audit_lib.php';

use GalleryWorkflow\Fixture;
use function GalleryWorkflow\check;
use function PhpGallery\Audit\missing_required_php_test_passes;

$fixture = null;
$exit = 0;
$stage = 'prerequisites';
try {
    $mode = (string) ($argv[1] ?? '');
    check(in_array($mode, ['--development', '--audit-quick', '--audit', '--release', '--route-probes'], true), 'Choose development checks, route probes, or a central audit profile.');
    $routeProbeLabel = '';
    if ($mode === '--route-probes') {
        $routeProbeLabel = trim((string) ($argv[2] ?? getenv('PHP_GALLERY_ROUTE_PROBE_EVIDENCE') ?: ''));
        check(in_array($routeProbeLabel, ['phase3', 'phase4', 'phase5', 'after'], true), 'Route probe evidence label must be phase3, phase4, phase5, or after.');
    }
    $fixture = new Fixture(dirname(__DIR__), getenv());
    $stage = 'provisioning';
    $fixture->start();
    echo "PASS gallery workflow disposable migrated database and isolated HTTP server\n";
    if ($mode === '--development') {
        $stage = 'HTTP development checks';
        $fixture->run([PHP_BINARY, dirname(__DIR__) . '/tests/gallery_workflow_integration_test.php'], 180, $stage, true);
        $stage = 'browser development checks';
        // Purpose: Bound the development-mode live Gallery browser regression.
        // Type: integer.
        // Units: seconds.
        // Scope: The child-process deadline around gallery_workflow_browser_test.php.
        // Consumers: Disposable workflow fixture used by the development check mode.
        // Rationale: The 240-second browser result window allows up to 30 seconds for startup and 30 seconds for shutdown and fixture cleanup.
        $fixture->run([PHP_BINARY, dirname(__DIR__) . '/tests/gallery_workflow_browser_test.php'], 300, $stage, true);
    } elseif ($mode === '--route-probes') {
        $stage = 'route lifecycle probes';
        $fixture->run([PHP_BINARY, dirname(__DIR__) . '/scripts/audit_route_performance.php', $routeProbeLabel], 600, $stage, true);
        echo 'PASS gallery workflow route lifecycle probes captured (' . $routeProbeLabel . ")\n";
    } else {
        // The existing PHP regression suite discovers the PHP workflow tests and the real DB races.
        // No parallel test orchestrator and no direct invocation of the existing concurrency suite.
        $profile = match ($mode) {
            '--audit-quick' => 'quick',
            '--release' => 'release',
            default => getenv('PHP_GALLERY_AUDIT_OWNER') === 'hosted-full' ? 'hosted-full' : 'full',
        };
        $workflowBrowserRequired = getenv('GALLERY_WORKFLOW_BROWSER_REQUIRED') === '1';
        check(!$workflowBrowserRequired || (string) getenv('GALLERY_WORKFLOW_BROWSER') !== ''
            && (string) getenv('GALLERY_WORKFLOW_BROWSER') !== 'disabled',
            'The required real-app Chromium workflow has no verified browser executable.');
        $stage = 'central ' . $profile . ' audit';
        $fixture->run([PHP_BINARY, __DIR__ . '/audit.php', '--profile=' . $profile], 1200, $stage);
        $report = json_decode((string) file_get_contents(dirname(__DIR__) . '/cache/test-audit/latest.json'), true, 512, JSON_THROW_ON_ERROR);
        $runtime = array_values(array_filter($report['tasks'] ?? [], static fn (array $task): bool => $task['id'] === 'runtime-performance'))[0] ?? [];
        check(($runtime['status'] ?? '') === 'PASS'
            && ($runtime['counts']['passed'] ?? 0) === 11
            && ($runtime['counts']['skipped'] ?? 1) === 0,
            'The disposable central audit must qualify both include phases and all nine actual route lifecycles.');
        $regressionId = $profile === 'quick' ? 'php-fast' : 'php-regression';
        $regression = array_values(array_filter($report['tasks'] ?? [], static fn (array $task): bool => $task['id'] === $regressionId))[0] ?? [];

        $logPath = (string) ($regression['log'] ?? '');
        check(str_starts_with($logPath, 'cache/test-audit/') && !str_contains($logPath, '..')
            && is_file(dirname(__DIR__) . '/' . $logPath), 'Central regression evidence missing.');
        $requiredTests = ['database_engine_contract_test.php'];
        if ($profile !== 'quick') {
            // Retain the established real-database fixtures and the telemetry
            // populated-upgrade regression. A report-level PASS
            // can otherwise conceal a required per-file SKIP.
            $requiredTests = array_merge($requiredTests, [
                'telemetry_storage_compaction_database_test.php',
                'installer_first_install_test.php',
                'gallery_workflow_integration_test.php',
                'public_visual_preview_workflow_test.php',
                'theme_visual_background_workflow_test.php',
                'gallery_image_move_crash_test.php',
                'viewer_phase07_mysql_concurrency_test.php',
                'gallery_edit_concurrency_mysql_test.php',
                'admin_operation_keys_http_test.php',
                'public_content_widget_db_workflow_test.php',
                'public_content_widget_admin_http_workflow_test.php',
                'breadcrumb_workflow_integration_test.php',
                'content_language_workflow_integration_test.php',
                'session_route_contention_test.php',
            ]);
            if (getenv('GALLERY_WORKFLOW_BROWSER') !== 'disabled') {
                $requiredTests[] = 'gallery_workflow_browser_test.php';
                $requiredTests[] = 'public_widget_persistence_browser_test.php';
            }
        }
        if ($profile === 'hosted-full') {
            // The central selector owns the complete disjoint partition. Each
            // private database runner must prove every required child assigned
            // to it; the native aggregate requires all four successful shards.
            $registry = require __DIR__ . '/audit_registry.php';
            $names = array_map(static fn(string $path): string => basename($path),
                glob(dirname(__DIR__) . '/tests/*_test.php') ?: []);
            $names = array_values(array_diff($names, $registry['packaging_tests']));
            $owned = \PhpGallery\Audit\audit_partition_php_tests($names,
                (int) getenv('PHP_GALLERY_AUDIT_SHARD_INDEX'),
                (int) getenv('PHP_GALLERY_AUDIT_SHARD_TOTAL'));
            $requiredTests = array_values(array_intersect($requiredTests, $owned));
        }
        $missingRequired = missing_required_php_test_passes($regression, $requiredTests);
        check($missingRequired === [],
            'Mandatory integration regression did not produce an unambiguous PASS: '
                . implode(', ', $missingRequired));
        if ($profile === 'quick') {
            echo "PASS gallery workflow central quick audit completed with all nine route lifecycles and database engine contracts\n";
        } else {
            echo 'PASS gallery workflow central ' . $profile . " audit completed\n";
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL gallery workflow ' . $stage . ' at ' . basename($exception->getFile()) . ' line ' . $exception->getLine() . "\n");
    $exit = 1;
} finally {
    if ($fixture) {
        try {
            $fixture->close();
            echo "PASS gallery workflow disposable database and files cleaned\n";
        } catch (Throwable) {
            fwrite(STDERR, "FAIL gallery workflow cleanup requires operator attention\n");
            $exit = 1;
        }
    }
}
exit($exit);
