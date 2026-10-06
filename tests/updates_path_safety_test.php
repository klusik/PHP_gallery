<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/updates_path_safety_test.php
 * Module Type: Regression Test
 * Purpose: Prove updater targets reject redirected ancestors while allowing safe new paths.
 * Responsibilities: Exercise ordinary, missing, escaping, and in-root alias paths in an isolated fixture.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/release_file_policy.php';
require_once dirname(__DIR__) . '/app/services/updates_path_safety.php';

use function Gallery\Services\application_update_assert_safe_target;

/**
 * Require the updater path guard to reject a target.
 *
 * @param string $root Installation root used by the guard.
 * @param string $relativePath Relative path expected to fail validation.
 * @return void Throws when the guard accepts the target.
 */
function updates_path_safety_expect_rejected(string $root, string $relativePath): void
{
    try {
        application_update_assert_safe_target($root, $relativePath);
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException('Updater path guard accepted unsafe target: ' . $relativePath);
}

/**
 * Create a directory alias using a symbolic link or a Windows junction fallback.
 *
 * @param string $target Existing directory to reference.
 * @param string $link Alias path to create.
 * @return bool True when either supported directory alias was created.
 */
function updates_path_safety_create_directory_alias(string $target, string $link): bool
{
    if (@symlink($target, $link)) {
        return true;
    }
    if (DIRECTORY_SEPARATOR !== '\\' || !is_dir($target)) {
        return false;
    }

    // cmd.exe internal commands need a command line rather than VC-style argv quoting.
    // Keep fixture paths quoted and refuse percent expansion or embedded quotes/newlines.
    if (preg_match('/["%\r\n]/', $link . $target) !== 0) {
        return false;
    }
    $process = proc_open(
        'cmd.exe /d /v:off /c mklink /J "' . $link . '" "' . $target . '"',
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        return false;
    }

    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process) === 0 && is_dir($link);
}

/**
 * Remove one isolated test directory without following symbolic links.
 *
 * @param string $path Temporary fixture directory to remove.
 * @return void Removes the directory and its entries when safely contained in system temp.
 */
function updates_path_safety_remove_fixture(string $path): void
{
    $resolved = realpath($path);
    $temporaryRoot = realpath(sys_get_temp_dir());
    if ($resolved === false || $temporaryRoot === false) {
        return;
    }
    $temporaryBoundary = rtrim($temporaryRoot, '/\\') . DIRECTORY_SEPARATOR;
    if (!str_starts_with($resolved, $temporaryBoundary) || basename($resolved) === '') {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isLink() || !$entry->isDir()) {
            @unlink($entry->getPathname());
        } else {
            @rmdir($entry->getPathname());
        }
    }
    @rmdir($resolved);
}

$fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'php-gallery-update-path-safety-' . bin2hex(random_bytes(8));
$installRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'install';
$outsideRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'outside';
$insideAliasTarget = $installRoot . DIRECTORY_SEPARATOR . 'inside-target';
$externalLink = $installRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'external-link';
$internalAlias = $installRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'internal-alias';
if (!mkdir($installRoot . DIRECTORY_SEPARATOR . 'app', 0777, true)
    || !mkdir($insideAliasTarget, 0777, true)
    || !mkdir($outsideRoot, 0777, true)) {
    throw new RuntimeException('Could not create updater path safety fixture.');
}
file_put_contents($installRoot . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'existing.php', '<?php return true;');
file_put_contents($outsideRoot . DIRECTORY_SEPARATOR . 'external.php', '<?php return false;');
file_put_contents($insideAliasTarget . DIRECTORY_SEPARATOR . 'aliased.php', '<?php return false;');

$externalLinkCreated = false;
$internalAliasCreated = false;
try {
    application_update_assert_safe_target($installRoot, 'app/existing.php');
    application_update_assert_safe_target($installRoot, 'app/new/nested/file.php', true);
    updates_path_safety_expect_rejected($installRoot, 'app/new/nested/file.php');
    updates_path_safety_expect_rejected($installRoot, '../outside/external.php');

    $externalLinkCreated = updates_path_safety_create_directory_alias($outsideRoot, $externalLink);
    $internalAliasCreated = updates_path_safety_create_directory_alias($insideAliasTarget, $internalAlias);
    if ($externalLinkCreated) {
        updates_path_safety_expect_rejected($installRoot, 'app/external-link/external.php');
        updates_path_safety_expect_rejected($installRoot, 'app/external-link/new/file.php');
    }
    if ($internalAliasCreated) {
        updates_path_safety_expect_rejected($installRoot, 'app/internal-alias/aliased.php');
    }
    if (!$externalLinkCreated && !$internalAliasCreated) {
        echo "SKIP: This runtime cannot create directory symbolic links; ordinary and missing target cases passed.\n";
    }

    echo "Updater path safety tests passed.\n";
} finally {
    if ($externalLinkCreated) {
        @unlink($externalLink);
        @rmdir($externalLink);
    }
    if ($internalAliasCreated) {
        @unlink($internalAlias);
        @rmdir($internalAlias);
    }
    updates_path_safety_remove_fixture($fixtureRoot);
}
