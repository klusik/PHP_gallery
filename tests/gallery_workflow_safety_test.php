<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_workflow_safety_test.php
 * Module Type: Regression Test
 * Purpose: Verify disposable-workflow ownership and opt-in guards.
 * Responsibilities:
 *   - Reject unsafe fixture configurations before any database connection.
 *   - Execute disposable-fixture guards without opening a database connection.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/php_source.php';

require_once __DIR__ . '/support/gallery_workflow_safety.php';

use function GalleryWorkflow\check;
use function GalleryWorkflow\databaseOptions;
use function GalleryWorkflow\removeFixture;
use function GalleryWorkflow\validateDatabaseName;
use function GalleryWorkflow\validateFixture;

/** Require a guard to reject the supplied operation before a resource can be used. */
function gallery_workflow_expect_refusal(callable $operation): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Expected fixture safety refusal.');
}

$environment = [
    'GALLERY_WORKFLOW_ENABLE' => 'disposable-only',
    'GALLERY_WORKFLOW_DB_HOST' => '127.0.0.1',
    'GALLERY_WORKFLOW_DB_PORT' => '13316',
    'GALLERY_WORKFLOW_DB_USER' => 'gallery_workflow_runner',
    'GALLERY_WORKFLOW_DB_PASSWORD' => str_repeat('fixture-only-', 3),
];
check(
    databaseOptions($environment)['port'] === 13316,
    'Valid isolated options rejected.',
);
foreach ([
    ['GALLERY_WORKFLOW_ENABLE' => '1'],
    ['GALLERY_WORKFLOW_DB_HOST' => 'localhost'],
    ['GALLERY_WORKFLOW_DB_HOST' => '192.0.2.1'],
    ['GALLERY_WORKFLOW_DB_HOST' => '127.0.0.1;dbname=gallery'],
    ['GALLERY_WORKFLOW_DB_PORT' => '3306'],
    ['GALLERY_WORKFLOW_DB_PORT' => '80'],
    ['GALLERY_WORKFLOW_DB_PORT' => '13316;unix_socket=/tmp/mysql.sock'],
    ['GALLERY_WORKFLOW_DB_PORT' => '70000'],
    ['GALLERY_WORKFLOW_DB_USER' => 'root'],
    ['GALLERY_WORKFLOW_DB_PASSWORD' => ''],
    ['GALLERY_WORKFLOW_DB_NAME' => 'gallery_cms'],
    ['GALLERY_WORKFLOW_DSN' => 'mysql:dbname=gallery_cms'],
] as $unsafe) {
    gallery_workflow_expect_refusal(static fn(): mixed => databaseOptions(array_replace(
        $environment,
        $unsafe,
    )));
}
foreach ([
    'gallery_cms',
    'gallery_workflow_existing',
    'gallery_workflow_' . str_repeat('a', 24) . ';DROP DATABASE mysql',
] as $name) {
    gallery_workflow_expect_refusal(static fn(): mixed => validateDatabaseName($name));
}
$token = bin2hex(random_bytes(12));
validateDatabaseName('gallery_workflow_' . $token);
$directory = sys_get_temp_dir() . '/gallery-workflow-' . $token;
check(mkdir($directory, 0700), 'Could not create guard fixture.');
file_put_contents($directory . '/.workflow-owner', $token);
file_put_contents($directory . '/sentinel', 'synthetic');
try {
    check(
        validateFixture($directory, $token) === realpath($directory),
        'Owned fixture rejected.',
    );
    gallery_workflow_expect_refusal(static fn(): mixed => removeFixture($directory, str_repeat(
        '0',
        24,
    )));
    gallery_workflow_expect_refusal(static fn(): mixed => removeFixture(
        dirname(__DIR__),
        $token,
    ));
    gallery_workflow_expect_refusal(static fn(): mixed => removeFixture(
        sys_get_temp_dir(),
        $token,
    ));
    check(
        is_file($directory . '/sentinel'),
        'Refused cleanup changed contents.',
    );
    mkdir($directory . '/child');
    file_put_contents($directory . '/child/nested', 'synthetic');
} finally {
    removeFixture($directory, $token);
}
check(!is_dir($directory), 'Owned fixture cleanup incomplete.');
$runnerSource = (string) file_get_contents(
    dirname(__DIR__) . '/scripts/gallery_workflow_run.php',
);
$mysqlSource = (string) file_get_contents(
    dirname(__DIR__) . '/scripts/gallery_workflow_mysql.php',
);
// Only the private-MySQL launcher owns the opt-in loader benchmark. Retain
// exact mode lists for both entrypoints rather than accepting arbitrary flags.
foreach ([
    [
        $runnerSource,
        "['--development', '--audit-quick', '--audit', '--release',"
            . " '--route-probes']",
    ],
    [
        $mysqlSource,
        "['--development', '--audit-quick', '--audit', '--release',"
            . " '--route-probes', '--loader-benchmark']",
    ],
] as [$source, $expectedModes]) {
    check(
        php_source_contains(
            $source,
            $expectedModes,
        )
            && php_source_contains($source, "if ((\$argv[1] ?? '') === '--quick')")
            && php_source_contains(
                $source,
                "[__DIR__ . '/audit.php', '--profile=quick']",
            ),
        'Qualification modes and direct quick delegation must remain explicit.',
    );
}
check(
    php_source_contains(
        $mysqlSource,
        "\$childCommand = \$mode === '--loader-benchmark'"
            . " ? [PHP_BINARY, __DIR__ . '/audit_loader_benchmark.php']"
            . " : [PHP_BINARY, __DIR__ . '/gallery_workflow_run.php', \$mode]",
    ),
    'Only the benchmark mode may delegate to the dedicated measurement tool.',
);
check(
    php_source_contains($runnerSource, "'--release' => 'release'")
        && !php_source_contains($runnerSource, "'--quick' => 'quick'"),
    'Release mode must select release without a preceding full audit.',
);
check(
    php_source_contains($runnerSource, "'--profile=' . \$profile"),
    'Selected profile must flow to the authoritative audit.',
);
check(
    php_source_contains($mysqlSource, "\$mode === '--development' ? 540 : 1320"),
    'Development and non-development workflow modes require their full timeout budgets.',
);

echo
    "PASS gallery workflow opt-in connection identity cleanup and audit-profile guards\n";
