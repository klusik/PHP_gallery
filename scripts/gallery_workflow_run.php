<?php
/**
 * Project: PHP Gallery
 * Author: Rudolf Klusal
 * Run a disposable real-database application through development checks or the central audit.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
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
    check(in_array($argv[1] ?? '', ['--development', '--audit', '--release'], true), 'Choose development checks or a central audit profile.');
    $fixture = new Fixture(dirname(__DIR__), getenv());
    $stage = 'provisioning';
    $fixture->start();
    echo "PASS gallery workflow disposable migrated database and isolated HTTP server\n";
    if ($argv[1] === '--development') {
        $stage = 'HTTP development checks';
        $fixture->run([PHP_BINARY, dirname(__DIR__) . '/tests/gallery_workflow_integration_test.php'], 180, $stage, true);
        $stage = 'browser development checks';
        $fixture->run([PHP_BINARY, dirname(__DIR__) . '/tests/gallery_workflow_browser_test.php'], 170, $stage, true);
    } else {
        // The existing PHP regression suite discovers the PHP workflow tests and the real DB races.
        // No parallel test orchestrator and no direct invocation of the existing concurrency suite.
        $profile = $argv[1] === '--release' ? 'release' : 'full';
        $stage = 'central ' . $profile . ' audit';
        $fixture->run([PHP_BINARY, __DIR__ . '/audit.php', '--profile=' . $profile], 1200, $stage);
        $report = json_decode((string) file_get_contents(dirname(__DIR__) . '/cache/test-audit/latest.json'), true, 512, JSON_THROW_ON_ERROR);
        $regression = array_values(array_filter($report['tasks'] ?? [], static fn (array $task): bool => $task['id'] === 'php-regression'))[0] ?? [];
        $logPath = (string) ($regression['log'] ?? '');
        check(str_starts_with($logPath, 'cache/test-audit/') && !str_contains($logPath, '..'), 'Central regression evidence missing.');
        $evidence = (string) file_get_contents(dirname(__DIR__) . '/' . $logPath);
        // These exact PASS records must come from the central runner, including the existing race suite.
        foreach (['gallery_workflow_integration_test.php', 'gallery_workflow_browser_test.php', 'viewer_phase07_mysql_concurrency_test.php'] as $test) {
            check(preg_match('/^\[PASS\] ' . preg_quote($test, '/') . ' /m', $evidence) === 1, 'Mandatory integration coverage was skipped or unregistered.');
        }
        echo 'PASS gallery workflow central ' . $profile . " audit completed\n";
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
