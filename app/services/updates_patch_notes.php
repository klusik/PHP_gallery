<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/updates_patch_notes.php
 * Module Type: Service
 *
 * Purpose:
 *   Handles patch-note retrieval, caching, raw Markdown parsing and release metadata.
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
 * Return cached and bundled patch notes without spending GitHub quota.
 *
 * @param ?string $preferredBranch Preferred branch value.
 * @param int $ttlSeconds Compatibility argument; passive reads may use older release history.
 * @return array{ok:bool,branch:string,cached_at:int,source:string,versions:array<string,array<string,mixed>>,error:string} Passive release history.
 */
function application_patch_notes_viewer_data(?string $preferredBranch = null, int $ttlSeconds = 1800): array
{
    // $branch stores the trusted branch selected by the update checker or fallback candidates.
    $branch = in_array($preferredBranch, application_update_branch_candidates(), true) ? (string) $preferredBranch : (string) application_update_branch_candidates()[0];
    $cachedData = application_patch_notes_read_cache($branch, PHP_INT_MAX);
    $localPath = application_update_project_root() . '/PATCH_NOTES.md';
    $localMarkdown = is_file($localPath) ? (string) file_get_contents($localPath) : '';
    $localVersions = application_patch_notes_parse_versions($localMarkdown);
    $versions = (array) ($cachedData['versions'] ?? []) + $localVersions;
    // The installed package is authoritative for its own notes, even when a
    // pre-update remote cache still contains an older copy of that section.
    $currentVersion = cms_current_version();
    if (isset($localVersions[$currentVersion])) {
        $versions[$currentVersion] = $localVersions[$currentVersion];
    }
    // Older caches also stored rendered HTML. Present their raw Markdown with
    // the current view renderer instead of retaining obsolete or unsafe markup.
    foreach ($versions as $version => $entry) {
        $entry = (array) $entry;
        unset($entry['html']);
        $versions[$version] = $entry;
    }
    uksort($versions, /** Sort newest release first. @param string $left First version. @param string $right Second version. @return int Version order. */ static fn (string $left, string $right): int => version_compare($right, $left));
    return [
        'ok' => $versions !== [],
        'branch' => $branch,
        'cached_at' => (int) ($cachedData['cached_at'] ?? 0),
        'source' => $cachedData !== null ? 'github-api' : 'local',
        'versions' => $versions,
        'error' => '',
    ];
}

/**
 * Fetch missing pending-release notes within the explicit discovery budget.
 *
 * Page renders and version selection never call this network operation. A
 * bootstrap fallback that already downloaded PATCH_NOTES.md seeds this cache.
 *
 * @param string $branch Trusted stable branch.
 * @param string $version Discovered pending version.
 * @param float $deadline Discovery request's absolute wall-clock deadline.
 * @return void Stores optional remote history; failures do not invalidate discovery.
 */
function application_patch_notes_refresh_pending(string $branch, string $version, float $deadline): void
{
    $cached = application_patch_notes_read_cache($branch, 1800);
    if (version_compare($version, cms_current_version(), '<=') || isset($cached['versions'][$version])) {
        return;
    }
    $timeout = application_update_remote_timeout_seconds($deadline, 5);
    if ($timeout < 1) {
        return;
    }
    try {
        $markdown = application_update_fetch_github_content($branch, 'PATCH_NOTES.md', $timeout);
        application_patch_notes_write_cache($branch, [
            'ok' => true, 'branch' => $branch, 'cached_at' => time(),
            'source' => 'github-api', 'versions' => application_patch_notes_parse_versions($markdown), 'error' => '',
        ]);
    } catch (Throwable) {
        // Bundled notes and the previous cache remain available offline.
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
 * @return array<string,array{version:string,title:string,released_at:?string,released_label:string,markdown:string}> Raw release sections keyed by normalized version.
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
