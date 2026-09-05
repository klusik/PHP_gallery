<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/prepare_release.php
 * Module Type: Release Preparation CLI
 *
 * Purpose:
 *   Applies deterministic version metadata changes for a new PHP Gallery release.
 *
 * Responsibilities:
 *   - Validate and set the requested release version
 *   - Update registered current-version documentation markers
 *   - Upsert release metadata and create patch-note scaffolding when needed
 *   - Leave editorial release notes, manual build, manifest generation, audit, commit, and tag explicit
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
 *   - This script never creates commits or Git tags.
 *
 * Last Updated:
 *   2026-09-05
 */

declare(strict_types=1);

use function PhpGallery\Release\detect_cms_version;
use function PhpGallery\Release\ensure_patch_notes_scaffold;
use function PhpGallery\Release\prepare_version_markers;
use function PhpGallery\Release\project_root;
use function PhpGallery\Release\upsert_release_metadata;
use function PhpGallery\Release\valid_version;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/release_lib.php';

$args = array_values(array_slice($argv, 1));
if ($args === [] || in_array('--help', $args, true) || in_array('-h', $args, true)) {
    fwrite(STDOUT, <<<'TEXT'
PHP Gallery release preparation

Usage:
  php scripts/prepare_release.php <version>
  php scripts/prepare_release.php <version> --released-at="2026-09-05 21:30:00"

The command updates only registered mechanical release markers. It does not write
final release-note prose, build the PDF manual, generate the manifest, run tests,
commit, or tag. Follow RELEASE.md after preparation.
TEXT
    );
    exit($args === [] ? 2 : 0);
}

$version = array_shift($args);
if (!is_string($version) || !valid_version($version)) {
    fwrite(STDERR, "Invalid release version. Use X.Y or X.Y.Z without leading zeroes.\n");
    exit(2);
}

$releasedAt = null;
foreach ($args as $argument) {
    if (str_starts_with($argument, '--released-at=')) {
        $value = substr($argument, strlen('--released-at='));
        $releasedAt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value) ?: null;
        if ($releasedAt === null || $releasedAt->format('Y-m-d H:i:s') !== $value) {
            fwrite(STDERR, "Invalid --released-at value. Expected YYYY-MM-DD HH:MM:SS.\n");
            exit(2);
        }
        continue;
    }
    fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
    exit(2);
}

$root = project_root();
$current = detect_cms_version($root);
if ($current !== $version && version_compare($version, $current, '<=')) {
    fwrite(STDERR, 'Refusing non-incrementing release version ' . $version . '; current runtime version is ' . $current . ".\n");
    exit(2);
}

$editionDate = ($releasedAt ?? new \DateTimeImmutable('now'))->format('j F Y');

try {
    $changed = prepare_version_markers($root, $version, $editionDate);
    if (upsert_release_metadata($root, $version, $releasedAt)) {
        $changed[] = 'release-metadata.json';
    }
    if (ensure_patch_notes_scaffold($root, $version)) {
        $changed[] = 'PATCH_NOTES.md';
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'Release preparation failed: ' . $exception->getMessage() . "\n");
    exit(1);
}

$changed = array_values(array_unique($changed));
sort($changed, SORT_STRING);

fwrite(STDOUT, 'PHP Gallery release preparation | ' . $current . ' -> ' . $version . "\n");
fwrite(STDOUT, str_repeat('=', 72) . "\n");
if ($changed === []) {
    fwrite(STDOUT, "No mechanical release markers required changes.\n");
} else {
    foreach ($changed as $path) {
        fwrite(STDOUT, '[UPDATED] ' . $path . "\n");
    }
}
fwrite(STDOUT, str_repeat('-', 72) . "\n");
fwrite(STDOUT, "Preparation is not release qualification. Continue with RELEASE.md.\n");
fwrite(STDOUT, "Complete PATCH_NOTES.md and release-sensitive documentation, rebuild the manual,\n");
fwrite(STDOUT, "then regenerate the manifest and run exactly one release audit:\n");
fwrite(STDOUT, "  php scripts/generate_manifest.php\n");
fwrite(STDOUT, "  php scripts/audit.php --profile=release\n");
