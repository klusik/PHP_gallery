<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_migration/http.php
 * Module Type: Service
 *
 * Purpose:
 *   Authenticated HTTP transport between the two instances.
 *
 * Responsibilities:
 *   - Normalize instance base URLs and build endpoint URLs
 *   - Perform authenticated JSON, form, and file requests with timeouts
 *   - Stream large responses to a temporary file instead of memory
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
 *   - Loaded by app/services/gallery_migration.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/gallery_migration.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use CURLFile;
use RuntimeException;
use Throwable;
use ZipArchive;
use const Gallery\Core\CMS_VERSION;
use function Gallery\Controllers\admin_edit_gallery_tab_url;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\path_inside;
use function Gallery\Core\unique_slug;

/**
 * Normalize an admin-entered instance URL into a base app URL.
 *
 * @param string $url URL used by this workflow.
 * @return string Text result for the caller.
 */
function gallery_migration_normalize_instance_base(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.url_required', 'Enter the source or target PHP Gallery URL.'));
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = preg_match('~^(localhost|127\.0\.0\.1|\[::1\])(?::\d+)?(?:/|$)~i', $url) === 1
            ? 'http://' . $url
            : 'https://' . $url;
    }
    $parts = parse_url($url);
    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.url_invalid', 'Enter a valid HTTP or HTTPS PHP Gallery URL.'));
    }
    $scheme = strtolower((string) $parts['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.url_scheme', 'Only HTTP and HTTPS migration URLs are supported.'));
    }
    if (!empty($parts['user']) || !empty($parts['pass'])) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.url_credentials', 'Do not include credentials in the migration URL.'));
    }

    $host = (string) $parts['host'];
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = (string) ($parts['path'] ?? '');
    $path = preg_replace('~/index\.php$~i', '', $path) ?? $path;
    $path = rtrim($path, '/');
    return $scheme . '://' . $host . $port . $path;
}

/**
 * Build a front-controller endpoint URL for another PHP Gallery instance.
 *
 * @param string $instanceUrl Instance url URL.
 * @param string $page Page number or page data.
 * @param array $params Params value.
 * @return string Text result for the caller.
 */
function gallery_migration_endpoint_url(string $instanceUrl, string $page, array $params = []): string
{
    $base = gallery_migration_normalize_instance_base($instanceUrl);
    $query = array_merge(['page' => $page], $params);
    return $base . '/index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Fetch JSON from a remote migration endpoint.
 *
 * @param string $url URL used by this workflow.
 * @param string $apiKey Api key value.
 * @param ?int $timeoutSeconds Timeout seconds value.
 * @return array Structured result data for the caller.
 */
function gallery_migration_http_get_json(string $url, string $apiKey, ?int $timeoutSeconds = null): array
{
    $timeout = gallery_migration_timeout_seconds($timeoutSeconds);
    $response = http_fetch_response_with_headers($url, $timeout, [
        'Accept: application/json',
        'X-Gallery-API-Key: ' . $apiKey,
    ]);
    $decoded = json_decode((string) ($response['body'] ?? ''), true);
    if (!is_array($decoded)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.remote_json_invalid', 'Remote migration endpoint did not return valid JSON.'));
    }
    if (isset($decoded['ok']) && !$decoded['ok']) {
        throw new RuntimeException((string) ($decoded['error'] ?? 'Remote migration request failed.'));
    }

    return $decoded;
}

/**
 * POST form fields to a remote migration endpoint and decode JSON.
 *
 * @param string $url URL used by this workflow.
 * @param array $fields Fields value.
 * @param string $apiKey Api key value.
 * @param ?int $timeoutSeconds Timeout seconds value.
 * @return array Structured result data for the caller.
 */
function gallery_migration_http_post_form_json(string $url, array $fields, string $apiKey, ?int $timeoutSeconds = null): array
{
    $timeout = gallery_migration_timeout_seconds($timeoutSeconds);
    $headers = [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
        'X-Gallery-API-Key: ' . $apiKey,
    ];
    $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.http_init_failed', 'Could not initialize HTTP client.'));
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'PHP-Gallery-Migration/' . gallery_migration_current_version(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($responseBody === false || $status >= 400) {
            throw new RuntimeException($error !== '' ? $error : 'Remote migration request failed with status ' . $status . '.');
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeout,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body,
                'ignore_errors' => true,
            ],
        ]);
        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.http_failed', 'Remote migration request failed.'));
        }
    }

    $decoded = json_decode((string) $responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.remote_json_invalid', 'Remote migration endpoint did not return valid JSON.'));
    }
    if (isset($decoded['ok']) && !$decoded['ok']) {
        throw new RuntimeException((string) ($decoded['error'] ?? 'Remote migration request failed.'));
    }

    return $decoded;
}

/**
 * POST one local file to a remote target migration endpoint.
 *
 * @param string $url URL used by this workflow.
 * @param array $fields Fields value.
 * @param string $filePath File path filesystem path.
 * @param string $fileName File name value.
 * @param string $mimeType Mime type value.
 * @param string $apiKey Api key value.
 * @param ?int $timeoutSeconds Timeout seconds value.
 * @param string $fileField Multipart file field name.
 * @return array Structured result data for the caller.
 */
function gallery_migration_http_post_file_json(string $url, array $fields, string $filePath, string $fileName, string $mimeType, string $apiKey, ?int $timeoutSeconds = null, string $fileField = 'asset'): array
{
    $timeout = gallery_migration_timeout_seconds($timeoutSeconds);
    if (!function_exists('curl_init') || !class_exists(CURLFile::class)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.curl_required', 'PHP cURL is required for source-push asset transfer.'));
    }
    if (!is_file($filePath)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_missing', 'Requested migration asset is not available.'));
    }

    $fields[$fileField] = new CURLFile($filePath, $mimeType, $fileName);
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.http_init_failed', 'Could not initialize HTTP client.'));
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-Gallery-API-Key: ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'PHP-Gallery-Migration/' . gallery_migration_current_version(),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $responseBody = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($responseBody === false || $status >= 400) {
        throw new RuntimeException($error !== '' ? $error : 'Remote migration asset upload failed with status ' . $status . '.');
    }

    $decoded = json_decode((string) $responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.remote_json_invalid', 'Remote migration endpoint did not return valid JSON.'));
    }
    if (isset($decoded['ok']) && !$decoded['ok']) {
        throw new RuntimeException((string) ($decoded['error'] ?? 'Remote migration asset upload failed.'));
    }

    return $decoded;
}

/**
 * POST form fields to a remote endpoint and stream the binary response to a temporary file.
 *
 * @param string $url URL used by this workflow.
 * @param array $fields Form fields.
 * @param string $apiKey API key value.
 * @param ?int $timeoutSeconds Timeout seconds value.
 * @return string Temporary file path.
 */
function gallery_migration_http_post_form_to_file(string $url, array $fields, string $apiKey, ?int $timeoutSeconds = null): string
{
    $timeout = gallery_migration_timeout_seconds($timeoutSeconds);
    if (!function_exists('curl_init')) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.curl_required', 'PHP cURL is required for gallery migration ZIP transfer.'));
    }
    $tmp = tempnam(sys_get_temp_dir(), 'php_gallery_migration_package_');
    if ($tmp === false) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
    }
    $out = fopen($tmp, 'wb');
    if ($out === false) {
        @unlink($tmp);
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
    }
    $handle = curl_init($url);
    if ($handle === false) {
        fclose($out);
        @unlink($tmp);
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.http_init_failed', 'Could not initialize HTTP client.'));
    }
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_FILE => $out,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'PHP-Gallery-Migration/' . gallery_migration_current_version(),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'Accept: application/zip, application/octet-stream',
            'X-Gallery-API-Key: ' . $apiKey,
        ],
    ]);
    $ok = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $contentType = strtolower((string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE));
    $error = curl_error($handle);
    curl_close($handle);
    fclose($out);
    if ($ok === false || $status >= 400 || ($contentType !== '' && str_contains($contentType, 'application/json'))) {
        $remoteMessage = '';
        $decoded = json_decode((string) @file_get_contents($tmp), true);
        if (is_array($decoded)) {
            $remoteMessage = (string) ($decoded['error'] ?? $decoded['message'] ?? '');
        }
        @unlink($tmp);
        throw new RuntimeException($remoteMessage !== '' ? $remoteMessage : ($error !== '' ? $error : 'Remote migration ZIP download failed with status ' . $status . '.'));
    }
    return $tmp;
}

/**
 * Fetch one remote asset to a temporary file.
 *
 * @param string $url URL used by this workflow.
 * @param string $apiKey Api key value.
 * @param ?int $timeoutSeconds Timeout seconds value.
 * @return string Text result for the caller.
 */
function gallery_migration_http_get_to_file(string $url, string $apiKey, ?int $timeoutSeconds = null): string
{
    $timeout = gallery_migration_timeout_seconds($timeoutSeconds);
    $tmp = tempnam(sys_get_temp_dir(), 'php_gallery_migration_');
    if ($tmp === false) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
    }

    if (function_exists('curl_init')) {
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            @unlink($tmp);
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
        }
        $handle = curl_init($url);
        if ($handle === false) {
            fclose($out);
            @unlink($tmp);
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.http_init_failed', 'Could not initialize HTTP client.'));
        }
        curl_setopt_array($handle, [
            CURLOPT_FILE => $out,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'PHP-Gallery-Migration/' . gallery_migration_current_version(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/octet-stream',
                'X-Gallery-API-Key: ' . $apiKey,
            ],
        ]);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        fclose($out);
        if ($ok === false || $status >= 400) {
            @unlink($tmp);
            throw new RuntimeException($error !== '' ? $error : 'Remote migration asset download failed with status ' . $status . '.');
        }
        return $tmp;
    }

    try {
        $body = http_fetch_with_headers($url, $timeout, [
            'Accept: application/octet-stream',
            'X-Gallery-API-Key: ' . $apiKey,
        ]);
        file_put_contents($tmp, $body);
        return $tmp;
    } catch (Throwable $exception) {
        @unlink($tmp);
        throw $exception;
    }
}
