<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tools/perf/issue_362_a1_ci.php
 * Module Type: Disposable Benchmark Provisioner
 * Purpose: Reuse the existing CI MariaDB isolation contract for issue #362 A1.
 * Responsibilities:
 *   - Reject every non-GitHub or non-disposable invocation.
 *   - Create a fresh least-privileged schema-scoped test principal.
 *   - Erase root credentials from the benchmark process environment.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

/**
 * Bound CI-only MariaDB readiness probing before refusing unavailable fixtures.
 * Type: int. Units: attempts. Scope: A1 disposable GitHub-hosted provisioner.
 * Consumers: PDO bootstrap loop. Rationale: 60 attempts with 0.5-second
 * pauses allow 30 seconds of bounded container initialization.
 */
const A1_CI_DB_CONNECT_MAX_ATTEMPTS = 60;

/**
 * Pace CI database retries without spinning on an unavailable owned container.
 * Type: int. Units: microseconds. Scope: A1 disposable MariaDB startup.
 * Consumers: PDOException retry sleep. Rationale: 500000 microseconds equals
 * 0.5 seconds, preserving the existing 30-second readiness window.
 */
const A1_CI_DB_CONNECT_RETRY_MICROSECONDS = 500000;

require_once dirname(__DIR__, 2) . '/tests/support/gallery_workflow_safety.php';

try {
    if (!in_array($argv, [
        [$argv[0]],
        [$argv[0], '--thumbnail-batch'],
        [$argv[0], '--tree-catalogue'],
    ], true)) {
        throw new RuntimeException('Unexpected disposable provisioner arguments.');
    }
    if (PHP_SAPI !== 'cli'
        || getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('GALLERY_WORKFLOW_ENABLE') !== 'disposable-only') {
        throw new RuntimeException('Disposable GitHub Actions runner required.');
    }
    // Use the project's canonical hostname/port/user and isolation guard.
    $password = bin2hex(random_bytes(24));
    putenv('GALLERY_WORKFLOW_DB_PASSWORD=' . $password);
    $settings = \GalleryWorkflow\databaseOptions(getenv());
    $rootPassword = (string) getenv('GALLERY_WORKFLOW_CI_ROOT_PASSWORD');
    if ($rootPassword === '') {
        throw new RuntimeException('Missing CI-only MariaDB bootstrap secret.');
    }
    $database = null;
    for ($attempt = 0; $attempt < A1_CI_DB_CONNECT_MAX_ATTEMPTS; ++$attempt) {
        try {
            $database = new PDO(
                'mysql:host=127.0.0.1;port=' . $settings['port'],
                'root', $rootPassword,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
            break;
        } catch (PDOException) {
            usleep(A1_CI_DB_CONNECT_RETRY_MICROSECONDS);
        }
    }
    if (!$database instanceof PDO
        || $database->query('SELECT @@hostname')->fetchColumn()
            !== 'gallery-workflow-ci') {
        throw new RuntimeException('Wrong or unavailable CI MariaDB instance.');
    }
    $database->exec(
        "CREATE USER 'gallery_workflow_runner'@'%' IDENTIFIED BY "
        . $database->quote($password),
    );
    $schemaPattern = chr(96) . 'gallery\\_workflow\\_%' . chr(96);
    $database->exec(
        'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, '
        . 'CREATE TEMPORARY TABLES, ALTER, DROP, INDEX, REFERENCES ON '
        . $schemaPattern . ".* TO 'gallery_workflow_runner'@'%'",
    );
    $database = null;
    $rootPassword = '';
    putenv('GALLERY_WORKFLOW_CI_ROOT_PASSWORD');
    $owner = match ($argv[1] ?? null) {
        '--thumbnail-batch' => '/issue_362_b2_http.php',
        '--tree-catalogue' => '/issue_362_c1_tree.php',
        default => '/issue_362_a1_http.php',
    };
    require __DIR__ . $owner;
} catch (Throwable $error) {
    fwrite(STDERR, 'BLOCKED A1 disposable DB provisioning (' .
        basename($error->getFile()) . ':' . $error->getLine() . ")\n");
    exit(1);
}
