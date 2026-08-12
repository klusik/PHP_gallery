<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_patch_notes.php
 * Module Type: Service
 *
 * Purpose:
 *   Handles patch-note retrieval, caching, parsing, release metadata, and safe HTML rendering.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Services;

use DateTimeImmutable;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;
use const Gallery\Core\CMS_GITHUB_REPOSITORY;
use const Gallery\Core\CMS_UPDATE_BRANCHES;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\e;
use function Gallery\Core\run_migrations;

/**
 * Application update service model.
 *
 * This module owns GitHub version checks, cached update status, release ZIP download,
 * beta install/restore helpers, protected-path rules, filesystem copy logic, and
 * OPcache invalidation for application updates.
 *
 * The functions remain deliberately procedural because the rest of PHP Gallery uses
 * function-based services. Keeping the original public function names avoids route,
 * controller, installer, and admin template changes while allowing the legacy
 * app/services.php file to shrink safely.
 */

/**
 * Return parsed remote patch notes for the update page viewer.
 *
 * @param ?string $preferredBranch Preferred branch value.
 * @param int $ttlSeconds Ttl seconds value.
 * @return array Structured result data for the caller.
 */
function application_patch_notes_viewer_data(?string $preferredBranch = null, int $ttlSeconds = 1800): array
{
    // $branch stores the trusted branch selected by the update checker or fallback candidates.
    $branch = in_array($preferredBranch, application_update_branch_candidates(), true) ? (string) $preferredBranch : (string) application_update_branch_candidates()[0];
    if ($ttlSeconds > 0) {
        // $cachedData stores the file-backed payload when it is still fresh enough for admin viewing.
        $cachedData = application_patch_notes_read_cache($branch, $ttlSeconds);
        if ($cachedData !== null) {
            $currentVersion = cms_current_version();
            $currentEntry = (array) ($cachedData['versions'][$currentVersion] ?? []);
            if ((isset($currentEntry['released_label']) && (string) $currentEntry['released_label'] !== '') || empty($cachedData['versions'])) {
                return $cachedData;
            }
            application_patch_notes_clear_cache($branch);
        }
    }

    try {
        // $markdown stores the remote PATCH_NOTES.md text fetched from GitHub Contents API.
        $markdown = application_update_fetch_github_content($branch, 'PATCH_NOTES.md', 5);
        // $versions stores the parsed version sections keyed by normalized version number.
        $versions = application_patch_notes_parse_versions($markdown);
        // $data stores the viewer payload cached for subsequent page views.
        $data = [
            'ok' => true,
            'branch' => $branch,
            'cached_at' => time(),
            'source' => 'github-api',
            'versions' => $versions,
            'error' => '',
        ];
        application_patch_notes_write_cache($branch, $data);
        return $data;
    } catch (Throwable $exception) {
        // $localPath stores the bundled patch notes file used when GitHub is unavailable.
        $localPath = application_update_project_root() . '/PATCH_NOTES.md';
        // $localMarkdown stores the bundled patch notes text when it can be read safely.
        $localMarkdown = is_file($localPath) ? (string) file_get_contents($localPath) : '';
        return [
            'ok' => $localMarkdown !== '',
            'branch' => $branch,
            'cached_at' => time(),
            'source' => 'local',
            'versions' => $localMarkdown !== '' ? application_patch_notes_parse_versions($localMarkdown) : [],
            'error' => 'Remote patch notes unavailable. Reference: ' . application_update_safe_error($exception)['reference'],
        ];
    }
}

/**
 * Return the writable file-cache directory for remote patch notes payloads.
 *
 * @return string Text result for the caller.
 */
function application_patch_notes_cache_dir(): string
{
    // $path stores the generated metadata cache directory outside the public asset path.
    $path = application_update_project_root() . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'patch-notes';
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    return rtrim($path, DIRECTORY_SEPARATOR);
}

/**
 * Return the cache file path for a trusted update branch.
 *
 * @param string $branch Branch value.
 * @return string Text result for the caller.
 */
function application_patch_notes_cache_path(string $branch): string
{
    // $safeBranch stores a filesystem-safe representation of the trusted branch name.
    $safeBranch = preg_replace('/[^a-z0-9_.-]+/i', '_', $branch) ?: 'main';
    return application_patch_notes_cache_dir() . DIRECTORY_SEPARATOR . $safeBranch . '.json';
}

/**
 * Read a fresh file-backed patch notes payload when available.
 *
 * @param string $branch Branch value.
 * @param int $ttlSeconds Ttl seconds value.
 * @return ?array Structured result data for the caller.
 */
function application_patch_notes_read_cache(string $branch, int $ttlSeconds): ?array
{
    // $path stores the cache file selected for the current GitHub branch.
    $path = application_patch_notes_cache_path($branch);
    if (!is_file($path)) {
        return null;
    }

    // $modifiedAt stores the cache write timestamp reported by the filesystem.
    $modifiedAt = filemtime($path);
    if ($modifiedAt === false || time() - $modifiedAt > $ttlSeconds) {
        return null;
    }

    // $json stores the cached JSON payload.
    $json = (string) file_get_contents($path);
    // $data stores the decoded payload when it matches the expected shape.
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['versions']) || !is_array($data['versions'])) {
        return null;
    }

    return $data;
}

/**
 * Store a patch notes payload in the filesystem cache.
 *
 * @param string $branch Branch value.
 * @param array $data Input data.
 */
function application_patch_notes_write_cache(string $branch, array $data): void
{
    // $path stores the cache file selected for the current GitHub branch.
    $path = application_patch_notes_cache_path($branch);
    // $json stores the payload without database column length constraints.
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return;
    }

    @file_put_contents($path, $json, LOCK_EX);
}

/**
 * Remove cached patch notes so the next view refreshes from the source.
 *
 * @param ?string $branch Branch value.
 */
function application_patch_notes_clear_cache(?string $branch = null): void
{
    if ($branch !== null && $branch !== '') {
        $paths = [application_patch_notes_cache_path($branch)];
    } else {
        $paths = glob(application_patch_notes_cache_dir() . DIRECTORY_SEPARATOR . '*.json') ?: [];
    }

    foreach ($paths as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/**
 * Parse PATCH_NOTES.md into normalized version sections.
 *
 * @param string $markdown Markdown value.
 * @return array Structured result data for the caller.
 */
function application_patch_notes_parse_versions(string $markdown): array
{
    // $lines stores the source text split into individual Markdown lines.
    $lines = preg_split('/\R/u', $markdown) ?: [];
    // $versions stores parsed release-note sections keyed by version number.
    $versions = [];
    // $currentVersion stores the version currently being collected.
    $currentVersion = null;
    // $currentTitle stores the raw heading text for the current version.
    $currentTitle = '';
    // $buffer stores Markdown lines belonging to the current version section.
    $buffer = [];

    foreach ($lines as $line) {
        if (preg_match('/^##\s+(?:Version\s+)?v?([0-9]+(?:\.[0-9]+){1,2})\b(.*)$/i', (string) $line, $match)) {
            if ($currentVersion !== null) {
                $releaseMetadata = application_patch_notes_release_metadata_for_version($currentVersion);
                $versions[$currentVersion] = [
                    'version' => $currentVersion,
                    'title' => trim($currentTitle) !== '' ? trim($currentTitle) : 'Version ' . $currentVersion,
                    'released_at' => $releaseMetadata['released_at'],
                    'released_label' => $releaseMetadata['released_label'],
                    'markdown' => trim(implode("\n", $buffer)),
                    'html' => application_patch_notes_markdown_to_html(trim(implode("\n", $buffer))),
                ];
            }
            $currentVersion = application_update_normalize_version((string) $match[1]);
            $currentTitle = trim((string) preg_replace('/^##\s+/', '', (string) $line));
            $buffer = [];
            continue;
        }

        if ($currentVersion !== null) {
            $buffer[] = (string) $line;
        }
    }

    if ($currentVersion !== null) {
        $releaseMetadata = application_patch_notes_release_metadata_for_version($currentVersion);
        $versions[$currentVersion] = [
            'version' => $currentVersion,
            'title' => trim($currentTitle) !== '' ? trim($currentTitle) : 'Version ' . $currentVersion,
            'released_at' => $releaseMetadata['released_at'],
            'released_label' => $releaseMetadata['released_label'],
            'markdown' => trim(implode("\n", $buffer)),
            'html' => application_patch_notes_markdown_to_html(trim(implode("\n", $buffer))),
        ];
    }

    uksort($versions, static fn (string $a, string $b): int => version_compare($b, $a));
    return $versions;
}

/**
 * Return release metadata for a patch-note version from the checked-in release map.
 *
 * @param string $version Version value.
 * @return array Structured result data for the caller.
 */
function application_patch_notes_release_metadata_for_version(string $version): array
{
    static $cache = [];
    if (isset($cache[$version])) {
        return $cache[$version];
    }

    $metadata = [
        'released_at' => null,
        'released_label' => '',
    ];

    $metadataFile = application_update_project_root() . '/release-metadata.json';
    if (is_file($metadataFile)) {
        $json = (string) file_get_contents($metadataFile);
        $json = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
        $allMetadata = json_decode($json, true);
        if (is_array($allMetadata) && isset($allMetadata[$version]) && is_array($allMetadata[$version])) {
            $entry = $allMetadata[$version];
            $releasedAt = trim((string) ($entry['released_at'] ?? ''));
            $releasedLabel = trim((string) ($entry['released_label'] ?? ''));
            if ($releasedAt !== '') {
                $metadata['released_at'] = $releasedAt;
            }
            if ($releasedLabel !== '') {
                $metadata['released_label'] = $releasedLabel;
            } elseif ($releasedAt !== '') {
                try {
                    $metadata['released_label'] = (new DateTimeImmutable($releasedAt))->format('j. F Y, H:i');
                } catch (Throwable) {
                    $metadata['released_label'] = '';
                }
            }
        }
    }

    $cache[$version] = $metadata;
    return $metadata;
}

/**
 * Convert the limited PATCH_NOTES.md syntax into safe admin HTML.
 *
 * @param string $markdown Markdown value.
 * @return string Text result for the caller.
 */
function application_patch_notes_markdown_to_html(string $markdown): string
{
    if ($markdown === '') {
        return '<p class="muted">' . e(t('admin.updates.patch_notes_empty', 'No patch notes were found for this version.')) . '</p>';
    }

    // $html stores the generated safe HTML fragments.
    $html = [];
    // $inList tracks whether a Markdown list is currently open.
    $inList = false;
    // $inCode tracks whether a fenced code section is currently open.
    $inCode = false;
    // $codeLines stores raw lines inside a fenced code section.
    $codeLines = [];

    foreach (preg_split('/\R/u', $markdown) ?: [] as $line) {
        // $rawLine stores the unmodified Markdown line for code fences.
        $rawLine = (string) $line;
        // $trimmed stores a whitespace-trimmed copy for syntax checks.
        $trimmed = trim($rawLine);

        if (str_starts_with($trimmed, '```')) {
            if ($inCode) {
                $html[] = '<pre><code>' . e(implode("\n", $codeLines)) . '</code></pre>';
                $codeLines = [];
                $inCode = false;
            } else {
                if ($inList) {
                    $html[] = '</ul>';
                    $inList = false;
                }
                $inCode = true;
            }
            continue;
        }

        if ($inCode) {
            $codeLines[] = $rawLine;
            continue;
        }

        if ($trimmed === '') {
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
            continue;
        }

        if (preg_match('/^(#{3,6})\s+(.+)$/', $trimmed, $headingMatch)) {
            if ($inList) {
                $html[] = '</ul>';
                $inList = false;
            }
            // $level stores a bounded heading level suitable inside the update panel.
            $level = min(5, max(3, strlen((string) $headingMatch[1])));
            $html[] = '<h' . $level . '>' . application_patch_notes_inline_markdown((string) $headingMatch[2]) . '</h' . $level . '>';
            continue;
        }

        if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $listMatch)) {
            if (!$inList) {
                $html[] = '<ul>';
                $inList = true;
            }
            $html[] = '<li>' . application_patch_notes_inline_markdown((string) $listMatch[1]) . '</li>';
            continue;
        }

        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }
        $html[] = '<p>' . application_patch_notes_inline_markdown($trimmed) . '</p>';
    }

    if ($inCode) {
        $html[] = '<pre><code>' . e(implode("\n", $codeLines)) . '</code></pre>';
    }
    if ($inList) {
        $html[] = '</ul>';
    }

    return implode("\n", $html);
}

/**
 * Convert safe inline Markdown emphasis and code spans for patch notes.
 *
 * @param string $text Text value.
 * @return string Text result for the caller.
 */
function application_patch_notes_inline_markdown(string $text): string
{
    // $escaped stores HTML-safe text before tiny Markdown replacements are applied.
    $escaped = e($text);
    $escaped = preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped) ?? $escaped;
    $escaped = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped) ?? $escaped;
    return $escaped;
}
