<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: .github/scripts/prepare_release_candidate.php
 * Module Type: GitHub Release Preparation Helper
 * Purpose: Apply deterministic release metadata to a trusted release branch before CI qualification.
 * Responsibilities:
 *   - Reuse the canonical release library for ordinary version markers and metadata
 *   - Align all maintained translated manual source version/date markers
 *   - Preserve existing completed release notes and create only the canonical scaffold when absent
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - This helper intentionally does not write release-note prose, build PDFs, commit, tag, merge, or publish.
 *   - It is hosted under .github because it supports repository automation rather than the shipped application.
 *   - Keep comments and docstrings intact when modifying this file.
 */
declare(strict_types=1);

use function PhpGallery\Release\detect_cms_version;
use function PhpGallery\Release\ensure_patch_notes_scaffold;
use function PhpGallery\Release\prepare_version_markers;
use function PhpGallery\Release\resolve_release_moment;
use function PhpGallery\Release\upsert_release_metadata;
use function PhpGallery\Release\valid_version;

require_once dirname(__DIR__, 2) . '/scripts/release_lib.php';

$version = (string) ($argv[1] ?? '');
if (!valid_version($version)) {
    fwrite(STDERR, "Usage: php .github/scripts/prepare_release_candidate.php X.Y[.Z]\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$current = detect_cms_version($root);
if ($current !== $version && version_compare($version, $current, '<=')) {
    fwrite(STDERR, 'Refusing non-incrementing release version ' . $version . '; current runtime version is ' . $current . ".\n");
    exit(2);
}

$timezone = new DateTimeZone('Europe/Prague');
$metadataPath = $root . '/release-metadata.json';
$metadata = json_decode((string) file_get_contents($metadataPath), true);
if (!is_array($metadata)) {
    throw new RuntimeException('release-metadata.json is not a valid JSON object.');
}

$existing = isset($metadata[$version]) && is_array($metadata[$version]) ? $metadata[$version] : null;
$initialEpoch = getenv('RELEASE_INITIAL_EPOCH');
$releaseMoment = resolve_release_moment($existing, $version, $initialEpoch === false ? null : $initialEpoch, $timezone);

$changed = prepare_version_markers($root, $version, $releaseMoment->format('j F Y'));
if (upsert_release_metadata($root, $version, $existing === null ? $releaseMoment : null)) {
    $changed[] = 'release-metadata.json';
}
if (ensure_patch_notes_scaffold($root, $version)) {
    $changed[] = 'PATCH_NOTES.md';
}

$months = [
    'CZ' => [1 => 'ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'],
    'DE' => [1 => 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'],
    'SV' => [1 => 'januari', 'februari', 'mars', 'april', 'maj', 'juni', 'juli', 'augusti', 'september', 'oktober', 'november', 'december'],
];

foreach (['CZ', 'DE', 'SV'] as $language) {
    $path = 'docs/PHP_Gallery_Manual_' . $language . '.tex';
    $absolute = $root . '/' . $path;
    $contents = (string) file_get_contents($absolute);
    $date = match ($language) {
        'CZ' => $releaseMoment->format('j') . '.~' . $months[$language][(int) $releaseMoment->format('n')] . '~' . $releaseMoment->format('Y'),
        'DE' => $releaseMoment->format('j') . '.~' . $months[$language][(int) $releaseMoment->format('n')] . '~' . $releaseMoment->format('Y'),
        'SV' => $releaseMoment->format('j') . '~' . $months[$language][(int) $releaseMoment->format('n')] . '~' . $releaseMoment->format('Y'),
    };

    $versionCount = 0;
    $dateCount = 0;
    $updated = preg_replace_callback(
        '/(\\\\newcommand\{\\\\version\}\{)[^}]+(\})/',
        static fn(array $match): string => $match[1] . $version . $match[2],
        $contents,
        1,
        $versionCount
    );
    if (!is_string($updated) || $versionCount !== 1) {
        throw new RuntimeException('Unable to update translated manual version marker: ' . $path);
    }
    $updated = preg_replace_callback(
        '/(\\\\newcommand\{\\\\manualdate\}\{)[^}]+(\})/',
        static fn(array $match): string => $match[1] . $date . $match[2],
        $updated,
        1,
        $dateCount
    );
    if (!is_string($updated) || $dateCount !== 1) {
        throw new RuntimeException('Unable to update translated manual date marker: ' . $path);
    }
    if ($updated !== $contents) {
        if (file_put_contents($absolute, $updated) === false) {
            throw new RuntimeException('Unable to write translated manual marker: ' . $path);
        }
        $changed[] = $path;
    }
}

$changed = array_values(array_unique($changed));
sort($changed, SORT_STRING);
fwrite(STDOUT, 'GitHub release preparation | ' . $current . ' -> ' . $version . "\n");
if ($changed === []) {
    fwrite(STDOUT, "No deterministic release markers required changes.\n");
} else {
    foreach ($changed as $path) {
        fwrite(STDOUT, 'UPDATED ' . $path . "\n");
    }
}
