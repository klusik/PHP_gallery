<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Reject unsafe aliases and keep evidence I/O within the selected scope.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/recovery/io.php
 * Module Type: Recovery Tooling Support
 *
 * Purpose:
 *   Bound recovery evidence I/O and reject unsafe filesystem aliases.
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
 *   - Never bootstrap a restored installation or read installation credentials.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Recovery;

use RuntimeException;

require_once dirname(__DIR__, 2) . '/app/helpers_files.php';
require_once dirname(__DIR__, 2) . '/app/integrity.php';

/** Errors contain fixed codes only: paths, JSON values and exception messages stay private. */
function demand(bool $condition, string $code): void
{
    if (!$condition) {
        throw new RuntimeException($code);
    }
}

/** Encode a private evidence document deterministically with a final newline. */
function json_text(array $value): string
{
    return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
}

/** Bind evidence to the complete ordered JSON document; any edit invalidates it. */
function fingerprint(array $value): string
{
    return hash('sha256', json_text($value));
}

/** Compare canonical paths using the host filesystem case convention. */
function same_path(string $left, string $right): bool
{
    $left = rtrim(str_replace('\\', '/', $left), '/');
    $right = rtrim(str_replace('\\', '/', $right), '/');
    return PHP_OS_FAMILY === 'Windows' ? strcasecmp($left, $right) === 0 : $left === $right;
}

/** Reject aliases at every existing segment, including Windows junctions and linked ancestors. */
function plain_path(string $path): string
{
    demand(!str_contains($path, "\0") && !str_contains($path, '://'), 'unsafe_path');
    $withoutDrive = preg_replace('/^[A-Za-z]:/', '', $path);
    demand(!str_contains((string) $withoutDrive, ':'), 'unsafe_path');
    $resolved = realpath($path);
    demand($resolved !== false, 'path_unavailable');
    $cursor = $path;
    while (true) {
        demand(!is_link($cursor), 'linked_path');
        $parent = dirname($cursor);
        if ($parent === $cursor || $cursor === '.') {
            break;
        }
        $parentReal = realpath($parent);
        demand($parentReal !== false && same_path(rtrim($parentReal, '/\\') . '/' . basename($cursor), (string) realpath($cursor)), 'aliased_path');
        $cursor = $parent;
    }
    return $resolved;
}

/** Disjoint roots prevent selecting the executing installation, a child, or its parent. */
function external_directory(string $path): string
{
    $resolved = plain_path($path);
    demand(is_dir($resolved), 'directory_required');
    $project = dirname(__DIR__, 2);
    demand(!contains_path($project, $resolved) && !contains_path($resolved, $project), 'installation_overlap');
    return $resolved;
}

/** Apply the existing containment owner with consistent Windows path casing. */
function contains_path(string $root, string $path): bool
{
    if (PHP_OS_FAMILY === 'Windows') {
        $root = strtolower($root);
        $path = strtolower($path);
    }
    return \Gallery\Core\path_inside($root, $path);
}

/** Reject traversal, Windows streams, ambiguous names and credential filenames. */
function relative_path(string $path): string
{
    demand($path !== '' && strlen($path) <= 1024 && !preg_match('/[\x00-\x1f\x7f:\\\\]/', $path), 'unsafe_relative_path');
    demand(!str_starts_with($path, '/') && !str_ends_with($path, '/') && !str_contains($path, '//'), 'unsafe_relative_path');
    demand(\Gallery\Core\normalize_relative_path($path) === $path, 'unsafe_relative_path');
    foreach (explode('/', $path) as $part) {
        demand(!preg_match('/[. ]$/', $part) && !in_array(strtolower($part), ['config.php', '.env', '.git'], true), 'forbidden_file');
        demand(preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $part) !== 1, 'reserved_filename');
    }
    return $path;
}

/** Require a readable regular file with no symbolic or hard-link alias. */
function regular_file(string $path): string
{
    $resolved = plain_path($path);
    $stat = stat($resolved);
    demand(is_file($resolved) && is_readable($resolved) && is_array($stat), 'file_unavailable');
    demand(($stat['nlink'] ?? 1) <= 1, 'hardlinked_file');
    return $resolved;
}

/** Resolve an approved relative file strictly beneath its isolated root. */
function contained_file(string $root, string $relative): string
{
    $path = regular_file($root . '/' . relative_path($relative));
    demand(contains_path($root, $path), 'path_escape');
    return $path;
}

/** Read bounded JSON input without accepting PHP or stream-wrapper paths. */
function read_json(string $path): array
{
    demand(strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json', 'json_file_required');
    $file = regular_file($path);
    demand(filesize($file) <= 33554432, 'json_too_large');
    $value = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    demand(is_array($value), 'json_object_required');
    return $value;
}

/** Exclusive creation never replaces evidence; no stdout dump of an input document. */
function write_json(string $path, array $value): void
{
    demand(strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json', 'json_file_required');
    $directory = external_directory(dirname($path));
    demand(basename($path) === relative_path(basename($path)), 'unsafe_output');
    $destination = $directory . '/' . basename($path);
    demand(!file_exists($destination) && !is_link($destination), 'output_exists');
    $text = json_text($value);
    $oldMask = umask(0077);
    try {
        $handle = fopen($destination, 'xb');
        demand($handle !== false, 'output_unavailable');
        try {
            demand(fwrite($handle, $text) === strlen($text) && fflush($handle), 'output_incomplete');
        } finally {
            fclose($handle);
        }
    } finally {
        umask($oldMask);
    }
}

/** Parse a real UTC calendar timestamp in the documented exact format. */
function timestamp(mixed $value): int
{
    demand(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value) === 1, 'invalid_timestamp');
    $parsed = strtotime($value);
    demand($parsed !== false && gmdate('Y-m-d\TH:i:s\Z', $parsed) === $value, 'invalid_timestamp');
    return $parsed;
}

/** Reject missing and unexpected fields rather than retaining arbitrary metadata. */
function keys(array $value, array $expected): void
{
    $actual = array_keys($value);
    sort($actual);
    sort($expected);
    demand($actual === $expected, 'invalid_fields');
}

/** Recognize a lowercase SHA-256 receipt without interpreting its source. */
function digest(mixed $value): bool
{
    return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
}

/** Recognize bounded integer counts and second-based recovery targets. */
function nonnegative(mixed $value): bool
{
    return is_int($value) && $value >= 0 && $value <= 2147483647;
}
