<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/link_favicons.php
 * Module Type: Service
 *
 * Purpose:
 *   Caches safe external-site favicons used by links in gallery descriptions.
 *
 * Responsibilities:
 *   - Share gallery-description URL normalization and known-brand matching
 *   - Discover unknown-site favicons only during administrator gallery saves
 *   - Pin outbound requests to validated public IPv4 addresses to reduce SSRF risk
 *   - Store validated raster/ICO favicon files below the persistent galleries root
 *   - Keep public rendering read-only and free of outbound HTTP requests
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
 *   - Remote SVG favicons are intentionally not cached.
 *
 * Last Updated:
 *   2026-08-30
 */

declare(strict_types=1);

namespace Gallery\Services;

use PDO;
use Throwable;
use function Gallery\Core\base_url;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;
use function Gallery\Core\url_for;

const LINK_FAVICON_CACHE_TABLE = 'link_favicon_cache';
const LINK_FAVICON_INTERNAL_DIRECTORY = '_php-gallery-internal/link-favicons';
const LINK_FAVICON_MAX_HTML_BYTES = 131072;
const LINK_FAVICON_MAX_IMAGE_BYTES = 524288;
const LINK_FAVICON_MAX_REDIRECTS = 3;
const LINK_FAVICON_MAX_NEW_HOSTS_PER_SAVE = 5;
const LINK_FAVICON_CONNECT_TIMEOUT_SECONDS = 2;
const LINK_FAVICON_REQUEST_TIMEOUT_SECONDS = 4;
const LINK_FAVICON_SAVE_NETWORK_BUDGET_SECONDS = 8.0;

/**
 * Normalize a gallery-description link target to an allowed public HTTP(S) URL.
 *
 * This normalization is shared by the public renderer and the favicon cache so
 * both features interpret URL/LINK markup identically.
 *
 * @param string $url Raw URL text from description markup.
 * @return ?string Normalized HTTP(S) URL, or null when the target is unsafe.
 */
function link_favicon_normalize_url(string $url): ?string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($url === '') {
        return null;
    }
    if (preg_match('/^www\./iu', $url) === 1) {
        $url = 'https://' . $url;
    }
    if (preg_match('/^https?:\/\/[^\s<>]+$/iu', $url) !== 1) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || trim((string) ($parts['host'] ?? '')) === '') {
        return null;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }
    return $url;
}

/**
 * Return a normalized lowercase hostname from one HTTP(S) URL.
 *
 * @param string $url Normalized or raw HTTP(S) URL.
 * @return ?string Hostname without a trailing dot.
 */
function link_favicon_url_hostname(string $url): ?string
{
    $normalized = link_favicon_normalize_url($url);
    if ($normalized === null) {
        return null;
    }
    $parts = parse_url($normalized);
    if (!is_array($parts)) {
        return null;
    }
    $host = strtolower(rtrim(trim((string) ($parts['host'] ?? '')), '.'));
    return $host !== '' ? $host : null;
}

/**
 * Return whether a hostname is exactly a known domain or one of its subdomains.
 *
 * @param string $host Normalized lowercase hostname.
 * @param string $domain Canonical lowercase domain to test.
 * @return bool True when the hostname belongs to the domain.
 */
function link_favicon_host_matches(string $host, string $domain): bool
{
    return $host === $domain || str_ends_with($host, '.' . $domain);
}

/**
 * Resolve a known public website hostname to a bundled local brand icon symbol.
 *
 * @param string $url Normalized HTTP(S) URL.
 * @return ?string Local SVG symbol id, or null for an unrecognized website.
 */
function link_favicon_known_icon_id(string $url): ?string
{
    $host = link_favicon_url_hostname($url);
    if ($host === null) {
        return null;
    }

    static $icons = [
        'youtube' => ['youtube.com', 'youtu.be', 'youtube-nocookie.com'],
        'facebook' => ['facebook.com', 'fb.com', 'fb.me', 'fb.watch'],
        'twitter-x' => ['x.com', 'twitter.com', 't.co'],
        'instagram' => ['instagram.com', 'instagr.am'],
        'wikipedia' => ['wikipedia.org', 'wikimedia.org', 'wikidata.org'],
        'linkedin' => ['linkedin.com', 'lnkd.in'],
        'github' => ['github.com', 'githubusercontent.com'],
        'reddit' => ['reddit.com', 'redd.it'],
        'tiktok' => ['tiktok.com'],
        'discord' => ['discord.com', 'discord.gg'],
        'twitch' => ['twitch.tv'],
        'vimeo' => ['vimeo.com'],
    ];

    foreach ($icons as $iconId => $domains) {
        foreach ($domains as $domain) {
            if (link_favicon_host_matches($host, $domain)) {
                return $iconId;
            }
        }
    }
    return null;
}

/**
 * Return whether the favicon cache migration is available.
 */
function link_favicon_cache_schema_ready(): bool
{
    return db_table_exists(LINK_FAVICON_CACHE_TABLE);
}

/**
 * Extract unique HTTP(S) targets from supported gallery-description link markup.
 *
 * @param string $description Raw gallery description.
 * @return array<int,string> Unique normalized URLs in source order.
 */
function link_favicon_extract_description_urls(string $description): array
{
    $matchesByOffset = [];

    if (preg_match_all('/\[(link|url)=([^\]\r\n]{1,2048})\][^\r\n]*?\[\/\1\]/iu', $description, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[2] as $index => $candidate) {
            $offset = (int) ($matches[0][$index][1] ?? 0);
            $matchesByOffset[] = ['offset' => $offset, 'url' => (string) $candidate[0]];
        }
    }
    if (preg_match_all('/\[(link|url)\]([^\[\]\r\n]{1,2048})\[\/\1\]/iu', $description, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[2] as $index => $candidate) {
            $offset = (int) ($matches[0][$index][1] ?? 0);
            $matchesByOffset[] = ['offset' => $offset, 'url' => trim((string) $candidate[0])];
        }
    }
    if (preg_match_all('/\[[^\]\r\n]{1,160}\]\((https?:\/\/[^\s<>")]+)\)/iu', $description, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[1] as $index => $candidate) {
            $offset = (int) ($matches[0][$index][1] ?? 0);
            $matchesByOffset[] = ['offset' => $offset, 'url' => (string) $candidate[0]];
        }
    }

    usort($matchesByOffset, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);
    $urls = [];
    foreach ($matchesByOffset as $match) {
        $normalized = link_favicon_normalize_url((string) ($match['url'] ?? ''));
        if ($normalized === null) {
            continue;
        }
        $key = strtolower($normalized);
        if (!isset($urls[$key])) {
            $urls[$key] = $normalized;
        }
    }
    return array_values($urls);
}

/**
 * Load all source and translated descriptions currently stored for one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @return array<int,string> Stored descriptions.
 */
function link_favicon_gallery_descriptions(int $galleryId): array
{
    if ($galleryId < 1) {
        return [];
    }

    try {
        $stmt = db()->prepare('SELECT description FROM galleries WHERE id = ?');
        $stmt->execute([$galleryId]);
        $descriptions = [(string) ($stmt->fetchColumn() ?: '')];

        if (db_table_exists('gallery_translations')) {
            $translated = db()->prepare('SELECT description FROM gallery_translations WHERE gallery_id = ? AND description IS NOT NULL');
            $translated->execute([$galleryId]);
            foreach ($translated->fetchAll(PDO::FETCH_COLUMN) as $description) {
                $descriptions[] = (string) $description;
            }
        }
        return $descriptions;
    } catch (Throwable) {
        return [];
    }
}

/**
 * Refresh favicon cache entries referenced by one just-saved gallery.
 *
 * Failures are intentionally best-effort. A favicon is cosmetic and must never
 * make the gallery save fail.
 *
 * @param int $galleryId Gallery identifier.
 * @return array<string,int> Bounded refresh counters.
 */
function link_favicon_refresh_gallery(int $galleryId): array
{
    $result = ['candidates' => 0, 'attempted' => 0, 'stored' => 0, 'skipped' => 0];
    $networkDeadline = microtime(true) + LINK_FAVICON_SAVE_NETWORK_BUDGET_SECONDS;
    if ($galleryId < 1 || !link_favicon_cache_schema_ready()) {
        return $result;
    }

    $uniqueHosts = [];
    foreach (link_favicon_gallery_descriptions($galleryId) as $description) {
        foreach (link_favicon_extract_description_urls($description) as $url) {
            if (link_favicon_known_icon_id($url) !== null) {
                continue;
            }
            $host = link_favicon_url_hostname($url);
            if ($host === null || isset($uniqueHosts[$host])) {
                continue;
            }
            $uniqueHosts[$host] = $url;
        }
    }

    $result['candidates'] = count($uniqueHosts);
    foreach ($uniqueHosts as $host => $url) {
        if (microtime(true) >= $networkDeadline) {
            $result['skipped']++;
            continue;
        }
        if ($result['attempted'] >= LINK_FAVICON_MAX_NEW_HOSTS_PER_SAVE) {
            $result['skipped']++;
            continue;
        }
        if (!link_favicon_host_needs_refresh($host)) {
            $result['skipped']++;
            continue;
        }
        $result['attempted']++;
        try {
            $fetch = link_favicon_fetch_site_icon($url, $networkDeadline);
            link_favicon_store_fetch_result($host, $fetch);
            if (($fetch['status'] ?? '') === 'ok') {
                $result['stored']++;
            }
        } catch (Throwable) {
            link_favicon_store_fetch_result($host, [
                'status' => 'failed',
                'source_url' => null,
                'mime_type' => null,
                'extension' => null,
                'body' => null,
            ]);
        }
    }

    return $result;
}

/**
 * Return whether one hostname is absent, expired, or missing its cached file.
 */
function link_favicon_host_needs_refresh(string $host): bool
{
    try {
        $stmt = db()->prepare('SELECT status, icon_file, retry_after FROM ' . LINK_FAVICON_CACHE_TABLE . ' WHERE hostname = ?');
        $stmt->execute([$host]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return true;
        }

        if ((string) ($row['status'] ?? '') === 'ok') {
            $file = link_favicon_valid_file_name((string) ($row['icon_file'] ?? ''));
            if ($file === null || !is_file(link_favicon_cache_directory() . DIRECTORY_SEPARATOR . $file)) {
                return true;
            }
        }

        $retryAt = strtotime((string) ($row['retry_after'] ?? ''));
        return $retryAt === false || $retryAt <= time();
    } catch (Throwable) {
        return false;
    }
}

/**
 * Persist a completed favicon fetch attempt and atomically replace old content.
 *
 * @param string $host Normalized hostname.
 * @param array<string,mixed> $fetch Fetch result.
 */
function link_favicon_store_fetch_result(string $host, array $fetch): void
{
    if (!link_favicon_cache_schema_ready()) {
        return;
    }

    $status = in_array((string) ($fetch['status'] ?? ''), ['ok', 'missing', 'failed', 'blocked'], true)
        ? (string) $fetch['status']
        : 'failed';
    $retryBasis = $status;
    $oldRow = null;
    $oldFile = null;
    try {
        $oldStmt = db()->prepare('SELECT status, icon_file, mime_type, source_url, content_sha256, fetched_at FROM ' . LINK_FAVICON_CACHE_TABLE . ' WHERE hostname = ?');
        $oldStmt->execute([$host]);
        $candidate = $oldStmt->fetch(PDO::FETCH_ASSOC);
        $oldRow = is_array($candidate) ? $candidate : null;
        $oldFile = $oldRow !== null ? link_favicon_valid_file_name((string) ($oldRow['icon_file'] ?? '')) : null;
    } catch (Throwable) {
        $oldRow = null;
        $oldFile = null;
    }

    $iconFile = null;
    $mimeType = null;
    $contentHash = null;
    $fetchedAt = null;
    if ($status === 'ok') {
        $body = is_string($fetch['body'] ?? null) ? (string) $fetch['body'] : '';
        $extension = (string) ($fetch['extension'] ?? '');
        $mimeType = (string) ($fetch['mime_type'] ?? '');
        if ($body === '' || $extension === '' || $mimeType === '') {
            $status = 'failed';
            $retryBasis = 'failed';
        } else {
            $contentHash = hash('sha256', $body);
            $iconFile = substr(hash('sha256', $host), 0, 24) . '-' . substr($contentHash, 0, 16) . '.' . $extension;
            $directory = link_favicon_cache_directory();
            if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
                $status = 'failed';
                $retryBasis = 'failed';
                $iconFile = null;
                $mimeType = null;
                $contentHash = null;
            } else {
                $tmp = tempnam($directory, '.favicon-');
                if ($tmp === false || @file_put_contents($tmp, $body, LOCK_EX) !== strlen($body) || !@rename($tmp, $directory . DIRECTORY_SEPARATOR . $iconFile)) {
                    if (is_string($tmp) && is_file($tmp)) {
                        @unlink($tmp);
                    }
                    $status = 'failed';
                    $retryBasis = 'failed';
                    $iconFile = null;
                    $mimeType = null;
                    $contentHash = null;
                } else {
                    $fetchedAt = now_sql();
                    if ($oldFile !== null && $oldFile !== $iconFile) {
                        @unlink($directory . DIRECTORY_SEPARATOR . $oldFile);
                    }
                }
            }
        }
    }

    $sourceUrl = is_string($fetch['source_url'] ?? null) ? link_favicon_limit_text((string) $fetch['source_url'], 2048) : null;
    $oldFileExists = $oldFile !== null && is_file(link_favicon_cache_directory() . DIRECTORY_SEPARATOR . $oldFile);
    if (($status === 'failed' || $status === 'blocked')
        && $oldRow !== null
        && (string) ($oldRow['status'] ?? '') === 'ok'
        && $oldFileExists) {
        // Keep the last known-good favicon visible during temporary network or
        // DNS failures. retry_after still uses the failed/blocked attempt class.
        $status = 'ok';
        $iconFile = $oldFile;
        $mimeType = (string) ($oldRow['mime_type'] ?? '');
        $sourceUrl = (string) ($oldRow['source_url'] ?? '');
        $contentHash = (string) ($oldRow['content_sha256'] ?? '');
        $fetchedAt = (string) ($oldRow['fetched_at'] ?? '');
        $sourceUrl = $sourceUrl !== '' ? $sourceUrl : null;
        $contentHash = $contentHash !== '' ? $contentHash : null;
        $fetchedAt = $fetchedAt !== '' ? $fetchedAt : null;
    }

    $now = now_sql();
    $retryAfter = match ($retryBasis) {
        'ok' => date('Y-m-d H:i:s', time() + 90 * 86400),
        'missing' => date('Y-m-d H:i:s', time() + 7 * 86400),
        'blocked' => date('Y-m-d H:i:s', time() + 30 * 86400),
        default => date('Y-m-d H:i:s', time() + 86400),
    };

    try {
        $stmt = db()->prepare(
            'INSERT INTO ' . LINK_FAVICON_CACHE_TABLE . ' (hostname, status, icon_file, mime_type, source_url, content_sha256, fetched_at, last_attempt_at, retry_after, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status), icon_file = VALUES(icon_file), mime_type = VALUES(mime_type), source_url = VALUES(source_url), content_sha256 = VALUES(content_sha256), fetched_at = VALUES(fetched_at), last_attempt_at = VALUES(last_attempt_at), retry_after = VALUES(retry_after), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([$host, $status, $iconFile, $mimeType, $sourceUrl, $contentHash, $fetchedAt, $now, $retryAfter, $now]);
    } catch (Throwable) {
        if ($iconFile !== null && $iconFile !== $oldFile) {
            @unlink(link_favicon_cache_directory() . DIRECTORY_SEPARATOR . $iconFile);
        }
    }
}

/**
 * Limit stored metadata text without splitting a UTF-8 code point.
 */
function link_favicon_limit_text(string $value, int $limit): string
{
    if ($limit <= 0) {
        return '';
    }
    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, $limit, 'UTF-8');
    }
    return substr($value, 0, $limit);
}

/**
 * Return the public URL of one cached unknown-site favicon.
 *
 * Public rendering performs only a local DB lookup and filesystem existence
 * check. It never initiates an outbound network request.
 *
 * @param string $url Link target.
 * @return ?string Cached local favicon URL, or null when unavailable.
 */
function link_favicon_cached_public_url(string $url): ?string
{
    if (link_favicon_known_icon_id($url) !== null || !link_favicon_cache_schema_ready()) {
        return null;
    }
    $host = link_favicon_url_hostname($url);
    if ($host === null) {
        return null;
    }

    static $cache = [];
    if (array_key_exists($host, $cache)) {
        return $cache[$host];
    }

    try {
        $stmt = db()->prepare('SELECT icon_file FROM ' . LINK_FAVICON_CACHE_TABLE . " WHERE hostname = ? AND status = 'ok' LIMIT 1");
        $stmt->execute([$host]);
        $file = link_favicon_valid_file_name((string) ($stmt->fetchColumn() ?: ''));
        if ($file === null || !is_file(link_favicon_cache_directory() . DIRECTORY_SEPARATOR . $file)) {
            return $cache[$host] = null;
        }
        return $cache[$host] = link_favicon_public_file_url($file);
    } catch (Throwable) {
        return $cache[$host] = null;
    }
}

/**
 * Return the persistent filesystem directory for cached link favicons.
 */
function link_favicon_cache_directory(): string
{
    return galleries_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, LINK_FAVICON_INTERNAL_DIRECTORY);
}

/**
 * Validate one generated favicon cache filename.
 */
function link_favicon_valid_file_name(string $file): ?string
{
    $file = trim($file);
    return preg_match('/^[a-f0-9]{24}-[a-f0-9]{16}\.(?:png|jpg|gif|webp|ico)$/', $file) === 1 ? $file : null;
}

/**
 * Build a public favicon URL that works with repository-root and public/ web roots.
 *
 * Repository-root deployments can let Apache/Nginx serve the persistent gallery
 * file directly. A public/ document root falls back to the cacheable PHP asset
 * route because galleries_root is intentionally outside that document root.
 */
function link_favicon_public_file_url(string $file): string
{
    $file = link_favicon_valid_file_name($file) ?? '';
    if ($file === '') {
        return '';
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $scriptFile = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $projectGalleriesRoot = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'galleries';
    $configuredGalleriesRoot = rtrim(galleries_root(), DIRECTORY_SEPARATOR);
    $standardGalleriesRoot = rtrim($projectGalleriesRoot, DIRECTORY_SEPARATOR);
    if ($configuredGalleriesRoot !== $standardGalleriesRoot
        || (str_ends_with($scriptFile, '/public/index.php') && !str_ends_with($script, '/public/index.php'))) {
        return url_for('link_favicon_asset', ['f' => $file]);
    }

    return base_url('galleries/' . LINK_FAVICON_INTERNAL_DIRECTORY . '/' . rawurlencode($file));
}

/**
 * Resolve a public asset route request to one validated cached favicon file.
 *
 * @param string $file Untrusted route filename.
 * @return ?array{path:string,mime_type:string} Valid stored file details.
 */
function link_favicon_public_asset(string $file): ?array
{
    $file = link_favicon_valid_file_name($file);
    if ($file === null) {
        return null;
    }
    $path = link_favicon_cache_directory() . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }
    $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        default => null,
    };
    return $mime !== null ? ['path' => $path, 'mime_type' => $mime] : null;
}

/**
 * Fetch and validate the best favicon for one unknown website.
 *
 * @param string $pageUrl Gallery-description target.
 * @return array<string,mixed> Fetch result suitable for persistence.
 */
function link_favicon_fetch_site_icon(string $pageUrl, ?float $deadline = null): array
{
    $pageUrl = link_favicon_normalize_url($pageUrl);
    if ($pageUrl === null) {
        return ['status' => 'blocked'];
    }
    $host = link_favicon_url_hostname($pageUrl);
    if ($host === null || link_favicon_known_icon_id($pageUrl) !== null) {
        return ['status' => 'blocked'];
    }

    $page = link_favicon_http_fetch($pageUrl, LINK_FAVICON_MAX_HTML_BYTES, 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.2', $deadline);
    if (!empty($page['blocked'])) {
        return ['status' => 'blocked'];
    }

    $baseUrl = (string) ($page['final_url'] ?? $pageUrl);
    $iconUrls = [];
    if (!empty($page['ok'])) {
        $contentType = strtolower((string) (($page['headers']['content-type'] ?? '')));
        if ($contentType === '' || str_contains($contentType, 'text/html') || str_contains($contentType, 'application/xhtml+xml')) {
            $iconUrls = link_favicon_html_icon_urls((string) ($page['body'] ?? ''), $baseUrl);
        }
    }

    $fallback = link_favicon_origin_url($baseUrl);
    if ($fallback !== null) {
        $iconUrls[] = rtrim($fallback, '/') . '/favicon.ico';
    }
    $originalOrigin = link_favicon_origin_url($pageUrl);
    if ($originalOrigin !== null) {
        $iconUrls[] = rtrim($originalOrigin, '/') . '/favicon.ico';
    }

    $seen = [];
    $networkFailure = empty($page['ok']) && empty($page['blocked']);
    foreach ($iconUrls as $iconUrl) {
        if ($deadline !== null && microtime(true) >= $deadline) {
            $networkFailure = true;
            break;
        }
        $iconUrl = link_favicon_normalize_url((string) $iconUrl);
        if ($iconUrl === null || isset($seen[strtolower($iconUrl)])) {
            continue;
        }
        $seen[strtolower($iconUrl)] = true;
        if (count($seen) > 6) {
            break;
        }

        $response = link_favicon_http_fetch($iconUrl, LINK_FAVICON_MAX_IMAGE_BYTES, 'image/avif,image/webp,image/png,image/jpeg,image/gif,image/x-icon,image/vnd.microsoft.icon,*/*;q=0.1', $deadline);
        if (!empty($response['blocked'])) {
            continue;
        }
        if (empty($response['ok'])) {
            $status = (int) ($response['status'] ?? 0);
            if (!empty($response['error']) || $status === 0 || $status >= 500) {
                $networkFailure = true;
            }
            continue;
        }
        $validated = link_favicon_validate_image((string) ($response['body'] ?? ''));
        if ($validated === null) {
            continue;
        }
        return [
            'status' => 'ok',
            'source_url' => (string) ($response['final_url'] ?? $iconUrl),
            'mime_type' => $validated['mime_type'],
            'extension' => $validated['extension'],
            'body' => (string) $response['body'],
        ];
    }

    return [
        'status' => $networkFailure ? 'failed' : 'missing',
        'source_url' => null,
        'mime_type' => null,
        'extension' => null,
        'body' => null,
    ];
}

/**
 * Fetch one URL with bounded redirects while validating every destination.
 *
 * @param string $url URL to fetch.
 * @param int $maxBytes Maximum accepted response body.
 * @param string $accept Accept header.
 * @return array<string,mixed> HTTP result.
 */
function link_favicon_http_fetch(string $url, int $maxBytes, string $accept, ?float $deadline = null): array
{
    $current = link_favicon_normalize_url($url);
    if ($current === null) {
        return ['ok' => false, 'blocked' => true, 'error' => 'invalid_url'];
    }

    for ($redirect = 0; $redirect <= LINK_FAVICON_MAX_REDIRECTS; $redirect++) {
        if ($deadline !== null && microtime(true) >= $deadline) {
            return ['ok' => false, 'blocked' => false, 'error' => 'budget_exhausted', 'final_url' => $current];
        }
        $response = link_favicon_http_request_once($current, $maxBytes, $accept);
        if (!empty($response['blocked'])) {
            return $response + ['final_url' => $current];
        }
        $status = (int) ($response['status'] ?? 0);
        if ($status >= 300 && $status < 400) {
            $location = trim((string) (($response['headers']['location'] ?? '')));
            if ($location === '' || $redirect >= LINK_FAVICON_MAX_REDIRECTS) {
                return ['ok' => false, 'blocked' => false, 'status' => $status, 'final_url' => $current, 'headers' => $response['headers'] ?? []];
            }
            $next = link_favicon_resolve_relative_url($current, $location);
            if ($next === null) {
                return ['ok' => false, 'blocked' => true, 'status' => $status, 'final_url' => $current];
            }
            $current = $next;
            continue;
        }
        $response['final_url'] = $current;
        $response['ok'] = $status >= 200 && $status < 300 && empty($response['error']);
        return $response;
    }

    return ['ok' => false, 'blocked' => false, 'final_url' => $current];
}

/**
 * Execute one HTTP request pinned to a previously validated public IPv4 address.
 *
 * @param string $url URL to fetch.
 * @param int $maxBytes Maximum accepted response body.
 * @param string $accept Accept header.
 * @return array<string,mixed> One-response result.
 */
function link_favicon_http_request_once(string $url, int $maxBytes, string $accept): array
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return ['ok' => false, 'blocked' => true, 'error' => 'invalid_url'];
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
    if (($scheme !== 'http' && $scheme !== 'https') || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
        return ['ok' => false, 'blocked' => true, 'error' => 'blocked_url'];
    }
    $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
    if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
        return ['ok' => false, 'blocked' => true, 'error' => 'blocked_port'];
    }

    $resolved = link_favicon_resolve_public_ipv4($host);
    if ($resolved === null) {
        return ['ok' => false, 'blocked' => true, 'error' => 'blocked_address'];
    }

    if (function_exists('curl_init')) {
        return link_favicon_http_request_curl($url, $host, $port, $resolved, $maxBytes, $accept);
    }
    return link_favicon_http_request_socket($url, $host, $port, $resolved, $maxBytes, $accept);
}

/**
 * Resolve one hostname and require every returned IPv4 address to be public.
 *
 * The selected address is then pinned into the actual HTTP connection, avoiding
 * a second untrusted DNS lookup between validation and connect.
 */
function link_favicon_resolve_public_ipv4(string $host): ?string
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return null;
    }

    $dnsHost = $host;
    if (function_exists('idn_to_ascii')) {
        $flags = defined('IDNA_DEFAULT') ? IDNA_DEFAULT : 0;
        $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
        $ascii = @idn_to_ascii($host, $flags, $variant);
        if (is_string($ascii) && $ascii !== '') {
            $dnsHost = $ascii;
        }
    }
    if (preg_match('/^[A-Za-z0-9.-]{1,253}$/', $dnsHost) !== 1) {
        return null;
    }

    $addresses = [];
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($dnsHost, DNS_A);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $addresses[] = (string) $record['ip'];
                }
            }
        }
    }
    if ($addresses === []) {
        $fallback = @gethostbynamel($dnsHost);
        if (is_array($fallback)) {
            $addresses = array_values(array_filter(array_map('strval', $fallback)));
        }
    }
    $addresses = array_values(array_unique($addresses));
    if ($addresses === []) {
        return null;
    }

    foreach ($addresses as $address) {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }
    }
    return $addresses[0];
}

/**
 * Execute one pinned HTTP request with cURL when the extension is available.
 *
 * @return array<string,mixed> One-response result.
 */
function link_favicon_http_request_curl(string $url, string $host, int $port, string $ip, int $maxBytes, string $accept): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        return ['ok' => false, 'blocked' => false, 'error' => 'curl_init'];
    }

    $headers = [];
    $body = '';
    $tooLarge = false;
    $options = [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => LINK_FAVICON_CONNECT_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => LINK_FAVICON_REQUEST_TIMEOUT_SECONDS,
        CURLOPT_USERAGENT => 'PHP-Gallery-Link-Favicon',
        CURLOPT_HTTPHEADER => [
            'Accept: ' . $accept,
            'Accept-Encoding: identity',
            'Connection: close',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_RESOLVE => [$host . ':' . $port . ':' . $ip],
        CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $line) use (&$headers): int {
            $length = strlen($line);
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, 'HTTP/')) {
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $headers = [];
                }
                return $length;
            }
            $parts = explode(':', $trimmed, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return $length;
        },
        CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    curl_setopt_array($handle, $options);
    $executed = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($tooLarge) {
        return ['ok' => false, 'blocked' => false, 'status' => $status, 'headers' => $headers, 'error' => 'body_too_large'];
    }
    if ($executed === false && $status === 0) {
        return ['ok' => false, 'blocked' => false, 'status' => 0, 'headers' => $headers, 'error' => $error !== '' ? $error : 'curl_failed'];
    }
    return ['ok' => false, 'blocked' => false, 'status' => $status, 'headers' => $headers, 'body' => $body];
}

/**
 * Execute one pinned HTTP/1.1 request without cURL.
 *
 * @return array<string,mixed> One-response result.
 */
function link_favicon_http_request_socket(string $url, string $host, int $port, string $ip, int $maxBytes, string $accept): array
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return ['ok' => false, 'blocked' => true, 'error' => 'invalid_url'];
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $contextOptions = [];
    $transport = 'tcp://';
    if ($scheme === 'https') {
        $transport = 'tls://';
        $contextOptions['ssl'] = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $host,
            'SNI_enabled' => true,
            'SNI_server_name' => $host,
        ];
    }
    $context = stream_context_create($contextOptions);
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client(
        $transport . $ip . ':' . $port,
        $errno,
        $errstr,
        LINK_FAVICON_CONNECT_TIMEOUT_SECONDS,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!is_resource($socket)) {
        return ['ok' => false, 'blocked' => false, 'error' => $errstr !== '' ? $errstr : 'connect_failed'];
    }

    stream_set_timeout($socket, LINK_FAVICON_REQUEST_TIMEOUT_SECONDS);
    $path = (string) ($parts['path'] ?? '/');
    if ($path === '') {
        $path = '/';
    }
    if (isset($parts['query']) && (string) $parts['query'] !== '') {
        $path .= '?' . (string) $parts['query'];
    }
    $request = "GET " . $path . " HTTP/1.1\r\n"
        . "Host: " . $host . "\r\n"
        . "User-Agent: PHP-Gallery-Link-Favicon\r\n"
        . "Accept: " . $accept . "\r\n"
        . "Accept-Encoding: identity\r\n"
        . "Connection: close\r\n\r\n";
    if (@fwrite($socket, $request) === false) {
        fclose($socket);
        return ['ok' => false, 'blocked' => false, 'error' => 'write_failed'];
    }

    $statusLine = fgets($socket, 4096);
    if (!is_string($statusLine) || preg_match('/^HTTP\/\S+\s+(\d{3})/', trim($statusLine), $statusMatch) !== 1) {
        fclose($socket);
        return ['ok' => false, 'blocked' => false, 'error' => 'invalid_response'];
    }
    $status = (int) $statusMatch[1];
    $headers = [];
    $headerBytes = strlen($statusLine);
    while (($line = fgets($socket, 8192)) !== false) {
        $headerBytes += strlen($line);
        if ($headerBytes > 65536) {
            fclose($socket);
            return ['ok' => false, 'blocked' => false, 'status' => $status, 'error' => 'headers_too_large'];
        }
        if ($line === "\r\n" || $line === "\n") {
            break;
        }
        $headerParts = explode(':', trim($line), 2);
        if (count($headerParts) === 2) {
            $headers[strtolower(trim($headerParts[0]))] = trim($headerParts[1]);
        }
    }

    if (isset($headers['content-length']) && (int) $headers['content-length'] > $maxBytes) {
        fclose($socket);
        return ['ok' => false, 'blocked' => false, 'status' => $status, 'headers' => $headers, 'error' => 'body_too_large'];
    }

    $body = '';
    $transferEncoding = strtolower((string) ($headers['transfer-encoding'] ?? ''));
    if (str_contains($transferEncoding, 'chunked')) {
        while (!feof($socket)) {
            $sizeLine = fgets($socket, 4096);
            if (!is_string($sizeLine)) {
                break;
            }
            $hex = trim(explode(';', trim($sizeLine), 2)[0]);
            if ($hex === '' || preg_match('/^[0-9a-fA-F]+$/', $hex) !== 1) {
                fclose($socket);
                return ['ok' => false, 'blocked' => false, 'status' => $status, 'headers' => $headers, 'error' => 'invalid_chunk'];
            }
            $size = hexdec($hex);
            if ($size === 0) {
                while (($trailer = fgets($socket, 4096)) !== false && $trailer !== "\r\n" && $trailer !== "\n") {
                }
                break;
            }
            if (strlen($body) + $size > $maxBytes) {
                fclose($socket);
                return ['ok' => false, 'blocked' => false, 'status' => $status, 'headers' => $headers, 'error' => 'body_too_large'];
            }
            $remaining = $size;
            while ($remaining > 0 && !feof($socket)) {
                $chunk = fread($socket, min(8192, $remaining));
                if (!is_string($chunk) || $chunk === '') {
                    break 2;
                }
                $body .= $chunk;
                $remaining -= strlen($chunk);
            }
            fread($socket, 2);
        }
    } else {
        while (!feof($socket)) {
            $chunk = fread($socket, 8192);
            if (!is_string($chunk) || $chunk === '') {
                break;
            }
            if (strlen($body) + strlen($chunk) > $maxBytes) {
                fclose($socket);
                return ['ok' => false, 'blocked' => false, 'status' => $status, 'headers' => $headers, 'error' => 'body_too_large'];
            }
            $body .= $chunk;
        }
    }
    $meta = stream_get_meta_data($socket);
    fclose($socket);
    if (!empty($meta['timed_out'])) {
        return ['ok' => false, 'blocked' => false, 'status' => $status, 'headers' => $headers, 'error' => 'timeout'];
    }
    return ['ok' => false, 'blocked' => false, 'status' => $status, 'headers' => $headers, 'body' => $body];
}

/**
 * Parse favicon candidates from HTML LINK elements in priority order.
 *
 * @param string $html Bounded HTML document prefix.
 * @param string $baseUrl Final page URL used for relative references.
 * @return array<int,string> Absolute candidate URLs.
 */
function link_favicon_html_icon_urls(string $html, string $baseUrl): array
{
    $groups = [[], [], []];
    if (preg_match_all('/<link\b[^>]*>/iu', $html, $matches)) {
        foreach ($matches[0] as $tag) {
            $rel = strtolower(link_favicon_html_attribute((string) $tag, 'rel') ?? '');
            $href = link_favicon_html_attribute((string) $tag, 'href');
            if ($rel === '' || $href === null || $href === '') {
                continue;
            }
            $relTokens = preg_split('/\s+/u', trim($rel)) ?: [];
            $isIcon = in_array('icon', $relTokens, true);
            $isApple = in_array('apple-touch-icon', $relTokens, true) || in_array('apple-touch-icon-precomposed', $relTokens, true);
            if (!$isIcon && !$isApple) {
                continue;
            }
            $absolute = link_favicon_resolve_relative_url($baseUrl, html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($absolute === null) {
                continue;
            }
            $type = strtolower(link_favicon_html_attribute((string) $tag, 'type') ?? '');
            if ($type === 'image/svg+xml' || preg_match('/\.svg(?:$|[?#])/i', $absolute) === 1) {
                continue;
            }
            if ($isIcon && !in_array('shortcut', $relTokens, true)) {
                $groups[0][] = $absolute;
            } elseif ($isIcon) {
                $groups[1][] = $absolute;
            } else {
                $groups[2][] = $absolute;
            }
        }
    }

    $result = [];
    foreach ($groups as $group) {
        foreach ($group as $url) {
            $key = strtolower($url);
            if (!isset($result[$key])) {
                $result[$key] = $url;
            }
        }
    }
    return array_values($result);
}

/**
 * Read one quoted or unquoted HTML attribute from a LINK tag.
 */
function link_favicon_html_attribute(string $tag, string $name): ?string
{
    $quotedName = preg_quote($name, '/');
    if (preg_match('/\b' . $quotedName . '\s*=\s*(["\'])(.*?)\1/isu', $tag, $match) === 1) {
        return trim((string) $match[2]);
    }
    if (preg_match('/\b' . $quotedName . '\s*=\s*([^\s>"\']+)/isu', $tag, $match) === 1) {
        return trim((string) $match[1]);
    }
    return null;
}

/**
 * Resolve an HTTP(S) relative URL without allowing a scheme downgrade bypass.
 */
function link_favicon_resolve_relative_url(string $baseUrl, string $reference): ?string
{
    $reference = trim($reference);
    if ($reference === '') {
        return null;
    }
    if (preg_match('/^https?:\/\//i', $reference) === 1) {
        return link_favicon_normalize_url($reference);
    }

    $base = parse_url($baseUrl);
    if (!is_array($base)) {
        return null;
    }
    $scheme = strtolower((string) ($base['scheme'] ?? ''));
    $host = (string) ($base['host'] ?? '');
    if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
        return null;
    }
    if (str_starts_with($reference, '//')) {
        return link_favicon_normalize_url($scheme . ':' . $reference);
    }
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference) === 1) {
        return null;
    }

    $origin = $scheme . '://' . $host;
    if (!empty($base['port'])) {
        $origin .= ':' . (int) $base['port'];
    }
    $fragmentPos = strpos($reference, '#');
    if ($fragmentPos !== false) {
        $reference = substr($reference, 0, $fragmentPos);
    }
    if ($reference === '') {
        return link_favicon_normalize_url($baseUrl);
    }

    if (str_starts_with($reference, '?')) {
        $path = (string) ($base['path'] ?? '/');
        return link_favicon_normalize_url($origin . ($path !== '' ? $path : '/') . $reference);
    }

    $query = '';
    $queryPos = strpos($reference, '?');
    if ($queryPos !== false) {
        $query = substr($reference, $queryPos);
        $reference = substr($reference, 0, $queryPos);
    }
    if (str_starts_with($reference, '/')) {
        $path = $reference;
    } else {
        $basePath = (string) ($base['path'] ?? '/');
        $directory = preg_replace('~/[^/]*$~', '/', $basePath) ?: '/';
        $path = $directory . $reference;
    }

    $segments = [];
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }
    $normalizedPath = '/' . implode('/', $segments);
    return link_favicon_normalize_url($origin . $normalizedPath . $query);
}

/**
 * Return only the scheme/host/port origin of one HTTP(S) URL.
 */
function link_favicon_origin_url(string $url): ?string
{
    $normalized = link_favicon_normalize_url($url);
    if ($normalized === null) {
        return null;
    }
    $parts = parse_url($normalized);
    if (!is_array($parts)) {
        return null;
    }
    $origin = strtolower((string) ($parts['scheme'] ?? '')) . '://' . (string) ($parts['host'] ?? '');
    if (!empty($parts['port'])) {
        $origin .= ':' . (int) $parts['port'];
    }
    return $origin;
}

/**
 * Validate downloaded favicon bytes by signature rather than remote MIME claims.
 *
 * @return ?array{mime_type:string,extension:string} Accepted image metadata.
 */
function link_favicon_validate_image(string $body): ?array
{
    $length = strlen($body);
    if ($length < 4 || $length > LINK_FAVICON_MAX_IMAGE_BYTES) {
        return null;
    }

    $detected = null;
    if (str_starts_with($body, "\x89PNG\r\n\x1a\n")) {
        $detected = ['mime_type' => 'image/png', 'extension' => 'png'];
    } elseif (substr($body, 0, 3) === "\xFF\xD8\xFF") {
        $detected = ['mime_type' => 'image/jpeg', 'extension' => 'jpg'];
    } elseif (str_starts_with($body, 'GIF87a') || str_starts_with($body, 'GIF89a')) {
        $detected = ['mime_type' => 'image/gif', 'extension' => 'gif'];
    } elseif ($length >= 12 && substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WEBP') {
        $detected = ['mime_type' => 'image/webp', 'extension' => 'webp'];
    } elseif (substr($body, 0, 4) === "\x00\x00\x01\x00") {
        $detected = ['mime_type' => 'image/x-icon', 'extension' => 'ico'];
    }
    if ($detected === null) {
        return null;
    }

    if ($detected['extension'] === 'ico') {
        if ($length < 22) {
            return null;
        }
        $count = unpack('vcount', substr($body, 4, 2));
        $entryCount = (int) ($count['count'] ?? 0);
        if ($entryCount < 1 || $entryCount > 64 || $length < 6 + 16 * $entryCount) {
            return null;
        }
        for ($index = 0; $index < $entryCount; $index++) {
            $offset = 6 + 16 * $index;
            $width = ord($body[$offset]);
            $height = ord($body[$offset + 1]);
            $width = $width === 0 ? 256 : $width;
            $height = $height === 0 ? 256 : $height;
            if ($width < 1 || $height < 1 || $width > 512 || $height > 512) {
                return null;
            }
        }
        return $detected;
    }

    $imageInfo = @getimagesizefromstring($body);
    if (!is_array($imageInfo)) {
        return null;
    }
    $width = (int) ($imageInfo[0] ?? 0);
    $height = (int) ($imageInfo[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 2048 || $height > 2048) {
        return null;
    }
    return $detected;
}
