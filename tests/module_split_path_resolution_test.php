<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/module_split_path_resolution_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects project-root path resolution and stored-location identity across
 *   modules that are split into part files.
 *
 * Responsibilities:
 *   - Prove every part file resolves project-root paths to the repository root
 *   - Prove runtime storage stays at its historical location outside app/
 *   - Prove the migration instance identifier does not depend on module depth
 *   - Prove instrumentation frames are excluded from reported SQL callsites
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - A part file lives one directory deeper than its module entry file, so a
 *     copied dirname(__DIR__, 2) silently resolves to app/ instead of the root.
 *   - This test needs no database, web server, or writable runtime directory.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

/**
 * Record a failed path-resolution expectation.
 *
 * @param bool $condition Condition that must hold.
 * @param string $message Failure description.
 */
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

/**
 * Return the module part directories that exist in this checkout.
 *
 * A part directory is a directory that sits beside a same-named .php module
 * entry file, which is the shape the split convention produces.
 *
 * @param string $root Repository root.
 * @return array<int,string> Absolute part directory paths.
 */
function module_split_part_directories(string $root): array
{
    $directories = [];
    foreach (['app/services', 'app/controllers'] as $relativeParent) {
        foreach (glob($root . '/' . $relativeParent . '/*', GLOB_ONLYDIR) ?: [] as $candidate) {
            if (is_file($candidate . '.php')) {
                $directories[] = $candidate;
            }
        }
    }
    sort($directories, SORT_STRING);
    return $directories;
}

/**
 * Return PHP source with comments removed.
 *
 * Path notes in docblocks legitimately mention the wrong depth in order to warn
 * about it, so the scan below must look only at executable code.
 *
 * @param string $source PHP source text.
 * @return string Source with comment tokens stripped.
 */
function module_split_code_without_comments(string $source): string
{
    $code = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }
    return $code;
}

$partDirectories = module_split_part_directories($root);
$assert($partDirectories !== [], 'No split module part directories were found; the discovery rule is stale.');

// Every project-root expression inside a part file must account for the extra
// directory level. A part file is at app/<layer>/<module>/<part>.php, so
// dirname(__DIR__, 3) is the repository root and dirname(__DIR__, 2) is app/.
$inspected = 0;
foreach ($partDirectories as $partDirectory) {
    foreach (glob($partDirectory . '/*.php') ?: [] as $partFile) {
        $inspected++;
        $source = module_split_code_without_comments((string) file_get_contents($partFile));
        $relative = substr($partFile, strlen($root) + 1);

        $assert(
            !str_contains($source, 'dirname(__DIR__, 2)'),
            $relative . ' uses dirname(__DIR__, 2), which resolves to app/ from a part file. Use dirname(__DIR__, 3).'
        );
        $assert(
            !preg_match('/dirname\(__DIR__\s*\)/', $source),
            $relative . ' uses bare dirname(__DIR__), which resolves to the layer directory from a part file.'
        );

        // Prove the depth actually used lands on the repository root.
        if (preg_match_all('/dirname\(__DIR__,\s*(\d+)\)/', $source, $matches)) {
            foreach ($matches[1] as $depth) {
                $resolved = dirname(dirname($partFile), (int) $depth);
                $assert(
                    $resolved === $root,
                    $relative . ' resolves dirname(__DIR__, ' . $depth . ') to ' . $resolved . ' instead of the repository root.'
                );
            }
        }
    }
}
$assert($inspected > 0, 'No part files were inspected; the discovery rule is stale.');

// Runtime storage must stay outside app/ so read-only application directories
// keep working and existing jobs and reports remain discoverable.
require_once $root . '/app/services/admin_test_runs.php';
require_once $root . '/app/services/gallery_migration.php';

$testRunRoot = \Gallery\Services\admin_test_run_root();
$assert(
    $testRunRoot === $root . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'admin-test-runs',
    'Admin test-run storage moved away from cache/admin-test-runs: ' . $testRunRoot
);
$assert(
    !str_starts_with($testRunRoot, $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR),
    'Admin test-run storage must never live inside the application directory.'
);

$migrationJobDir = \Gallery\Services\gallery_migration_job_dir();
$assert(
    $migrationJobDir === $root . '/cache/gallery-migrations',
    'Gallery migration job storage moved away from cache/gallery-migrations: ' . $migrationJobDir
);
$assert(
    !str_starts_with($migrationJobDir, $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR),
    'Gallery migration job storage must never live inside the application directory.'
);

// The instance identifier feeds migration job identity. It must be derived from
// the repository root, not from the depth of the module that computes it.
$instanceId = \Gallery\Services\gallery_migration_instance_id();
$assert($instanceId !== '', 'Gallery migration instance identifier must not be empty.');
$assert(
    (bool) preg_match('/^[a-f0-9]{16}$/', $instanceId),
    'Gallery migration instance identifier format changed: ' . $instanceId
);
// The identifier mixes the configured base URL with the repository root, and
// tolerates configuration being unavailable, exactly as the service does.
$expectedBase = '';
try {
    $expectedBase = (string) (\Gallery\Core\cms_config()['base_url'] ?? '');
} catch (Throwable) {
    $expectedBase = '';
}
$assert(
    $instanceId === substr(hash('sha256', $expectedBase . '|' . $root), 0, 16),
    'Gallery migration instance identifier is no longer derived from the repository root, which changes migration job identity.'
);

// SQL callsite reporting must skip the whole instrumentation module, not only
// the entry file it used to live in.
$assert(
    \Gallery\Services\admin_test_run_callsite_is_instrumentation('/x/app/database.php'),
    'Database layer frames must be excluded from reported SQL callsites.'
);
$assert(
    \Gallery\Services\admin_test_run_callsite_is_instrumentation('/x/app/services/admin_test_runs.php'),
    'The test-run module entry file must be excluded from reported SQL callsites.'
);
$assert(
    \Gallery\Services\admin_test_run_callsite_is_instrumentation('/x/app/services/admin_test_runs/recording.php'),
    'Test-run part files must be excluded from reported SQL callsites.'
);
$assert(
    !\Gallery\Services\admin_test_run_callsite_is_instrumentation('/x/app/controllers/admin_dashboard.php'),
    'Ordinary application frames must remain eligible as reported SQL callsites.'
);

if ($failures !== []) {
    fwrite(STDERR, "Module split path resolution failures:\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}

echo 'Module split path resolution checks passed (' . $inspected . " part files inspected).\n";
