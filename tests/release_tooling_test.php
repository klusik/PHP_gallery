<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/release_tooling_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects deterministic release preparation and release consistency contracts.
 *
 * Responsibilities:
 *   - Validate supported release-version syntax
 *   - Verify only registered current-version markers are updated
 *   - Verify metadata upsert and patch-note scaffolding are idempotent
 *   - Verify consistency checks reject unfinished release notes and accept completed artifacts
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
 *   - Keep comments and docstrings intact when modifying this file.
 *   - The fixture is isolated under the system temporary directory and never edits the repository.
 *
 * Last Updated:
 *   2026-09-05
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/release_lib.php';

use function PhpGallery\Release\collect_consistency_checks;
use function PhpGallery\Release\ensure_patch_notes_scaffold;
use function PhpGallery\Release\prepare_version_markers;
use function PhpGallery\Release\upsert_release_metadata;
use function PhpGallery\Release\valid_version;

/**
 * Throw when a release-tooling contract is not satisfied.
 */
function release_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Write one fixture file and create its parent directory.
 */
function release_test_write(string $root, string $relative, string $contents): void
{
    $path = $root . '/' . $relative;
    if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0777, true) && !is_dir(dirname($path))) {
        throw new RuntimeException('Unable to create fixture directory.');
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Unable to write fixture file: ' . $relative);
    }
}

release_test_assert(valid_version('0.96.2'), 'Patch release versions must be accepted.');
release_test_assert(valid_version('10.500'), 'Two-component versions must remain supported.');
release_test_assert(!valid_version('0.096.2'), 'Leading zeroes must remain rejected.');
release_test_assert(!valid_version('v_0.96.2'), 'Tag prefixes are not release-version input.');

$fixture = sys_get_temp_dir() . '/php-gallery-release-tooling-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($fixture, 0777, true);

try {
    release_test_write($fixture, 'app/bootstrap.php', "<?php\nconst CMS_VERSION = '0.96.1';\n");
    release_test_write($fixture, 'README.md', "# PHP Gallery\n\n**Current Version:** 0.96.1\n\nHistorical Version 0.95 stays here.\n");
    release_test_write($fixture, 'TESTING.md', "# Testing Guide\n\nThis guide applies to PHP Gallery Version 0.96.1. Historical Version 0.95 remains documented.\n");
    release_test_write($fixture, 'DATABASE.md', "# Database\n\nThis document describes the database schema as of application version 0.96.1. Version 0.94.5 added the newest migration.\n");
    release_test_write($fixture, 'ARCHITECTURE.md', "# Architecture\n\n```php\nconst CMS_VERSION = '0.96.1';\n```\nHistorical Version 0.95 stays here.\n");
    release_test_write($fixture, 'docs/PHP_Gallery_Manual.tex', "\\newcommand{\\version}{0.96.1}\n\\newcommand{\\manualdate}{5 September 2026}\n");
    release_test_write($fixture, 'docs/PHP_Gallery_Manual.pdf', "%PDF-1.4 fixture\n");
    release_test_write($fixture, 'PATCH_NOTES.md', "# Patch notes\n\n## Version 0.96.1\n\nPrevious release.\n");
    release_test_write($fixture, 'release-metadata.json', "{\n    \"0.96.1\": {\"released_at\": \"2026-09-05 20:36:34\", \"released_label\": \"5 September 2026, 20:36\", \"tag\": \"v_0.96.1\"}\n}\n");
    release_test_write($fixture, 'app/core-manifest.json', "{\"version\":\"0.96.2\",\"files\":{}}\n");

    $changed = prepare_version_markers($fixture, '0.96.2', '6 September 2026');
    release_test_assert(in_array('app/bootstrap.php', $changed, true), 'Runtime version marker must be prepared.');
    release_test_assert(str_contains(file_get_contents($fixture . '/README.md') ?: '', 'Historical Version 0.95 stays here.'), 'Historical version prose must not be rewritten by release preparation.');

    release_test_assert(upsert_release_metadata($fixture, '0.96.2', new DateTimeImmutable('2026-09-06 09:15:00')), 'New release metadata must be inserted.');
    release_test_assert(!upsert_release_metadata($fixture, '0.96.2'), 'Re-running metadata preparation without a new timestamp must be idempotent.');
    $metadata = json_decode(file_get_contents($fixture . '/release-metadata.json') ?: '', true);
    release_test_assert(($metadata['0.96.2']['tag'] ?? '') === 'v_0.96.2', 'Release metadata tag must use v_<version>.');

    release_test_assert(ensure_patch_notes_scaffold($fixture, '0.96.2'), 'Missing patch notes must receive a scaffold.');
    release_test_assert(!ensure_patch_notes_scaffold($fixture, '0.96.2'), 'Patch-note scaffolding must be idempotent.');

    touch($fixture . '/docs/PHP_Gallery_Manual.tex', time() - 10);
    touch($fixture . '/docs/PHP_Gallery_Manual.pdf', time());
    $checks = collect_consistency_checks($fixture, '0.96.2');
    $failedLabels = array_values(array_map(
        static fn(array $check): string => $check['label'],
        array_filter($checks, static fn(array $check): bool => $check['status'] === 'FAIL')
    ));
    release_test_assert($failedLabels === ['Patch notes'], 'Unexpected failed fixture invariants: ' . implode(', ', $failedLabels));

    $notes = file_get_contents($fixture . '/PATCH_NOTES.md') ?: '';
    $notes = preg_replace(
        '/<!-- RELEASE_NOTES_TODO:.*?-->\R\RRelease notes for Version 0\.96\.2 are not complete yet\..*?(?=^## Version 0\.96\.1)/ms',
        "Version 0.96.2 centralizes release preparation and consistency checks.\n\n### Highlights\n\n- Added deterministic release tooling.\n\n### Technical Details\n\n- Added release marker validation.\n\n### Tests\n\n- Added release tooling regression coverage.\n\n### User Impact\n\n- Maintainers receive safer release automation.\n\n",
        $notes
    );
    file_put_contents($fixture . '/PATCH_NOTES.md', $notes);

    $checks = collect_consistency_checks($fixture, '0.96.2');
    $failed = array_filter($checks, static fn(array $check): bool => $check['status'] === 'FAIL');
    release_test_assert($failed === [], 'Completed release fixture must satisfy every consistency invariant.');
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($fixture);
}

echo "Release tooling contracts passed.\n";
