<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/release_lib.php
 * Module Type: Release Tooling Library
 *
 * Purpose:
 *   Centralizes deterministic release-version preparation and consistency checks.
 *
 * Responsibilities:
 *   - Validate release version identifiers
 *   - Update only explicitly registered current-version markers
 *   - Maintain release metadata without rewriting historical entries
 *   - Create an unmistakable patch-notes scaffold for a new version
 *   - Evaluate release consistency without changing repository files
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
 *   - Do not replace arbitrary version-looking strings. Historical version references are intentional.
 *
 * Last Updated:
 *   2026-09-05
 */

declare(strict_types=1);

namespace PhpGallery\Release;

use DateTimeImmutable;
use RuntimeException;

/**
 * Return the repository root.
 */
function project_root(): string
{
    return dirname(__DIR__);
}

/**
 * Return true when a version uses the supported X.Y or X.Y.Z numeric form.
 */
function valid_version(string $version): bool
{
    return preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)(?:\.(0|[1-9]\d*))?$/', $version) === 1;
}

/**
 * Read a required text file.
 */
function read_text(string $path): string
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read file: ' . $path);
    }
    return $contents;
}

/**
 * Atomically write a text file when its content changed.
 */
function write_text(string $path, string $contents): bool
{
    $existing = is_file($path) ? file_get_contents($path) : false;
    if ($existing === $contents) {
        return false;
    }

    $directory = dirname($path);
    $temporary = tempnam($directory, '.release-');
    if ($temporary === false) {
        throw new RuntimeException('Unable to create temporary file in: ' . $directory);
    }
    if (file_put_contents($temporary, $contents) === false) {
        @unlink($temporary);
        throw new RuntimeException('Unable to write temporary release file: ' . $temporary);
    }
    if (is_file($path)) {
        @chmod($temporary, fileperms($path) & 0777);
    }
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to replace file: ' . $path);
    }
    return true;
}

/**
 * Detect the runtime CMS version from app/bootstrap.php.
 */
function detect_cms_version(string $root): string
{
    $contents = read_text($root . '/app/bootstrap.php');
    if (preg_match("/const\\s+CMS_VERSION\\s*=\\s*['\"]([^'\"]+)['\"]\\s*;/", $contents, $match) !== 1) {
        throw new RuntimeException('Unable to detect CMS_VERSION in app/bootstrap.php.');
    }
    return (string) $match[1];
}

/**
 * Replace exactly one registered marker in a file.
 */
function replace_registered_marker(string $path, string $pattern, string $replacement, string $label): bool
{
    $contents = read_text($path);
    $count = 0;
    $updated = preg_replace($pattern, $replacement, $contents, 1, $count);
    if ($updated === null || $count !== 1) {
        throw new RuntimeException('Expected exactly one ' . $label . ' marker in ' . $path . '; found ' . $count . '.');
    }
    return write_text($path, $updated);
}

/**
 * Update the deterministic current-version markers owned by release tooling.
 *
 * @return list<string> Repository-relative paths that changed.
 */
function prepare_version_markers(string $root, string $version, string $manualDate): array
{
    $definitions = [
        ['app/bootstrap.php', "/(const\\s+CMS_VERSION\\s*=\\s*['\"])[^'\"]+(['\"]\\s*;)/", '${1}' . $version . '${2}', 'runtime version'],
        ['README.md', '/(\*\*Current Version:\*\*\s*)[^\r\n]+/', '${1}' . $version, 'README current version'],
        ['TESTING.md', '/(This guide applies to PHP Gallery Version\s+)[0-9]+(?:\.[0-9]+){1,2}(\.)/', '${1}' . $version . '${2}', 'testing-guide version'],
        ['DATABASE.md', '/(as of application version\s+)[0-9]+(?:\.[0-9]+){1,2}(\.)/', '${1}' . $version . '${2}', 'database-document version'],
        ['ARCHITECTURE.md', "/(const\\s+CMS_VERSION\\s*=\\s*['\"])[^'\"]+(['\"]\\s*;)/", '${1}' . $version . '${2}', 'architecture version example'],
        ['docs/PHP_Gallery_Manual.tex', '/(\\\\newcommand\{\\\\version\}\{)[^}]+(\})/', '${1}' . $version . '${2}', 'manual version'],
        ['docs/PHP_Gallery_Manual.tex', '/(\\\\newcommand\{\\\\manualdate\}\{)[^}]+(\})/', '${1}' . $manualDate . '${2}', 'manual edition date'],
    ];

    $changed = [];
    foreach ($definitions as [$relative, $pattern, $replacement, $label]) {
        if (replace_registered_marker($root . '/' . $relative, $pattern, $replacement, $label)) {
            $changed[] = $relative;
        }
    }
    return array_values(array_unique($changed));
}

/**
 * Upsert one release-metadata entry while preserving all historical formatting/content.
 */
function upsert_release_metadata(string $root, string $version, ?DateTimeImmutable $releasedAt = null): bool
{
    $path = $root . '/release-metadata.json';
    $contents = read_text($path);
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('release-metadata.json is not a valid JSON object.');
    }

    $existing = $decoded[$version] ?? null;
    if ($releasedAt === null && is_array($existing)) {
        $releasedAtText = (string) ($existing['released_at'] ?? '');
        $releasedLabel = (string) ($existing['released_label'] ?? '');
        $existingTag = (string) ($existing['tag'] ?? '');
        if ($releasedAtText !== '' && $releasedLabel !== '' && $existingTag === 'v_' . $version) {
            return false;
        }
        if ($releasedAtText === '' || $releasedLabel === '') {
            $releasedAt = new DateTimeImmutable('now');
        }
    } else {
        $releasedAtText = '';
        $releasedLabel = '';
    }

    if ($releasedAt !== null) {
        $releasedAtText = $releasedAt->format('Y-m-d H:i:s');
        $releasedLabel = $releasedAt->format('j F Y, H:i');
    }

    $entryLines = [
        '    ' . json_encode($version, JSON_UNESCAPED_SLASHES) . ': {',
        '        "released_at": ' . json_encode($releasedAtText, JSON_UNESCAPED_SLASHES) . ',',
        '        "released_label": ' . json_encode($releasedLabel, JSON_UNESCAPED_SLASHES) . ',',
        '        "tag": ' . json_encode('v_' . $version, JSON_UNESCAPED_SLASHES),
        '    }',
    ];
    $block = implode("\n", $entryLines);

    $quotedVersion = preg_quote($version, '/');
    $pattern = '/^\s*"' . $quotedVersion . '"\s*:\s*\{.*?^\s*\}(?=\s*,|\s*$)/ms';
    if (preg_match($pattern, $contents) === 1) {
        $updated = preg_replace($pattern, $block, $contents, 1);
        if ($updated === null) {
            throw new RuntimeException('Unable to update release metadata entry for ' . $version . '.');
        }
        return write_text($path, $updated);
    }

    $openBrace = strpos($contents, '{');
    if ($openBrace === false) {
        throw new RuntimeException('release-metadata.json has no root object.');
    }
    $tail = substr($contents, $openBrace + 1);
    $hasEntries = trim($tail, " \t\r\n}") !== '';
    $insertion = "\n" . $block . ($hasEntries ? ',' : '') . "\n";
    $updated = substr($contents, 0, $openBrace + 1) . $insertion . ltrim($tail, "\r\n");
    return write_text($path, $updated);
}

/**
 * Ensure a new version has an explicit patch-notes work item.
 */
function ensure_patch_notes_scaffold(string $root, string $version): bool
{
    $path = $root . '/PATCH_NOTES.md';
    $contents = read_text($path);
    if (preg_match('/^## Version\s+' . preg_quote($version, '/') . '\s*$/m', $contents) === 1) {
        return false;
    }

    $scaffold = "## Version {$version}\n\n"
        . "<!-- RELEASE_NOTES_TODO: replace this scaffold with complete release notes before publishing. -->\n\n"
        . "Release notes for Version {$version} are not complete yet.\n\n"
        . "### Highlights\n\n- TODO: describe the release highlights.\n\n"
        . "### Technical Details\n\n- TODO: describe implementation, migration, frontend, backend, and compatibility changes.\n\n"
        . "### Tests\n\n- TODO: describe added or changed verification coverage.\n\n"
        . "### User Impact\n\n- TODO: describe administrator and visitor impact.\n\n";

    if (preg_match('/\A(# Patch notes\s*\R+)/i', $contents, $match) !== 1) {
        throw new RuntimeException('PATCH_NOTES.md does not start with the expected title.');
    }
    $updated = substr($contents, 0, strlen($match[1])) . $scaffold . substr($contents, strlen($match[1]));
    return write_text($path, $updated);
}

/**
 * Extract one version marker using a capture group.
 */
function extract_marker(string $path, string $pattern): ?string
{
    $contents = read_text($path);
    return preg_match($pattern, $contents, $match) === 1 ? (string) $match[1] : null;
}

/**
 * Return the target version's patch-note section, excluding older releases.
 */
function patch_notes_section(string $root, string $version): ?string
{
    $contents = read_text($root . '/PATCH_NOTES.md');
    $pattern = '/^## Version\s+' . preg_quote($version, '/') . '\s*$\R(.*?)(?=^## Version\s+|\z)/ms';
    return preg_match($pattern, $contents, $match) === 1 ? (string) $match[1] : null;
}

/**
 * Build one normalized consistency result.
 */
function check_result(string $label, bool $passed, string $detail): array
{
    return ['label' => $label, 'status' => $passed ? 'PASS' : 'FAIL', 'detail' => $detail];
}


/**
 * Extract the first manual pages through pdftotext when that optional tool is available.
 *
 * Returning null means content extraction is unavailable and callers may use a weaker fallback.
 */
function extract_pdf_text(string $pdfPath): ?string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    try {
        $process = @proc_open(['pdftotext', '-f', '1', '-l', '3', $pdfPath, '-'], $descriptors, $pipes, dirname($pdfPath));
    } catch (\Throwable) {
        return null;
    }
    if (!is_resource($process)) {
        return null;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0 || $stdout === false || trim($stdout) === '') {
        return null;
    }
    return $stdout;
}

/**
 * Evaluate deterministic release invariants without changing files.
 *
 * @return list<array{label:string,status:string,detail:string}>
 */
function collect_consistency_checks(string $root, string $version): array
{
    $markers = [
        ['Runtime CMS_VERSION', 'app/bootstrap.php', "/const\\s+CMS_VERSION\\s*=\\s*['\"]([^'\"]+)['\"]\\s*;/"],
        ['README current version', 'README.md', '/\*\*Current Version:\*\*\s*([^\r\n]+)/'],
        ['Testing guide version', 'TESTING.md', '/This guide applies to PHP Gallery Version\s+([0-9]+(?:\.[0-9]+){1,2})\./'],
        ['Database document version', 'DATABASE.md', '/as of application version\s+([0-9]+(?:\.[0-9]+){1,2})\./'],
        ['Architecture version example', 'ARCHITECTURE.md', "/const\\s+CMS_VERSION\\s*=\\s*['\"]([^'\"]+)['\"]\\s*;/"],
        ['Manual source version', 'docs/PHP_Gallery_Manual.tex', '/\\\\newcommand\{\\\\version\}\{([^}]+)\}/'],
    ];

    $checks = [];
    foreach ($markers as [$label, $relative, $pattern]) {
        $actual = extract_marker($root . '/' . $relative, $pattern);
        $checks[] = check_result($label, $actual === $version, $actual === null ? 'marker not found' : 'found ' . $actual . ', expected ' . $version);
    }

    $metadataPath = $root . '/release-metadata.json';
    $metadata = json_decode(read_text($metadataPath), true);
    $entry = is_array($metadata) && isset($metadata[$version]) && is_array($metadata[$version]) ? $metadata[$version] : null;
    $metadataOkay = $entry !== null
        && (string) ($entry['tag'] ?? '') === 'v_' . $version
        && trim((string) ($entry['released_at'] ?? '')) !== ''
        && trim((string) ($entry['released_label'] ?? '')) !== '';
    $checks[] = check_result('Release metadata', $metadataOkay, $metadataOkay ? 'entry and v_' . $version . ' tag are present' : 'missing/incomplete release-metadata.json entry');

    $section = patch_notes_section($root, $version);
    $notesOkay = $section !== null && !str_contains($section, 'RELEASE_NOTES_TODO') && !preg_match('/\bTODO\b/i', $section);
    $checks[] = check_result('Patch notes', $notesOkay, $section === null ? 'Version heading is missing' : ($notesOkay ? 'completed Version ' . $version . ' section found' : 'release-note scaffold/TODO is still present'));

    $manualTex = $root . '/docs/PHP_Gallery_Manual.tex';
    $manualPdf = $root . '/docs/PHP_Gallery_Manual.pdf';
    $pdfText = is_file($manualPdf) ? extract_pdf_text($manualPdf) : null;
    if ($pdfText !== null) {
        $pdfOkay = preg_match('/(?:Version|version|Application version:)\s*' . preg_quote($version, '/') . '\b/', $pdfText) === 1;
        $pdfDetail = $pdfOkay ? 'PDF text contains release version ' . $version : 'PDF text does not contain release version ' . $version . '; rebuild the manual';
    } else {
        $pdfOkay = is_file($manualPdf) && filemtime($manualPdf) !== false && filemtime($manualTex) !== false && filemtime($manualPdf) >= filemtime($manualTex);
        $pdfDetail = $pdfOkay ? 'pdftotext unavailable; PDF timestamp fallback is current' : 'rebuild docs/PHP_Gallery_Manual.pdf after the final LaTeX edit';
    }
    $checks[] = check_result('Manual PDF freshness', $pdfOkay, $pdfDetail);

    $manifestPath = $root . '/app/core-manifest.json';
    $manifest = is_file($manifestPath) ? json_decode(read_text($manifestPath), true) : null;
    $manifestVersion = is_array($manifest) ? (string) ($manifest['version'] ?? '') : '';
    $checks[] = check_result('Core manifest version', $manifestVersion === $version, $manifestVersion === '' ? 'manifest version missing' : 'found ' . $manifestVersion . ', expected ' . $version);

    return $checks;
}
