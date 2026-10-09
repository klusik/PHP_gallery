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

use GalleryWorkflow\Fixture;
use function GalleryWorkflow\check;

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
        $fixture->run([PHP_BINARY, dirname(__DIR__) . '/tests/gallery_workflow_browser_test.php'], 170, $stage, true);
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
            default => 'full',
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
        check(str_starts_with($logPath, 'cache/test-audit/') && !str_contains($logPath, '..'), 'Central regression evidence missing.');
        $evidence = (string) file_get_contents(dirname(__DIR__) . '/' . $logPath);
        $requiredTests = ['database_engine_contract_test.php'];
        if ($profile !== 'quick') {
            // Only explicit Chromium disablement omits browser evidence. Every real
            // database/HTTP and race PASS remains mandatory in both qualification profiles.
            $requiredTests = array_merge($requiredTests, ['installer_first_install_test.php', 'gallery_workflow_integration_test.php',
                'public_visual_preview_workflow_test.php', 'theme_visual_background_workflow_test.php',
                'gallery_image_move_crash_test.php', 'viewer_phase07_mysql_concurrency_test.php']);
            if (getenv('GALLERY_WORKFLOW_BROWSER') !== 'disabled') {
                $requiredTests[] = 'gallery_workflow_browser_test.php';
            }
        }
        foreach ($requiredTests as $test) {
            check(preg_match('/^\[PASS\] ' . preg_quote($test, '/') . ' /m', $evidence) === 1, 'Mandatory integration coverage was skipped or unregistered.');
        }
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
