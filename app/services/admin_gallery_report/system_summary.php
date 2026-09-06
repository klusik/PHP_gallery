<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_report/system_summary.php
 * Module Type: Service
 *
 * Purpose:
 *   Collects host, runtime, storage, and PHP environment diagnostics.
 *
 * Responsibilities:
 *   - Summarize site identity, PHP runtime, and server memory
 *   - Report configured data paths with free and total capacity
 *   - Expose the database server version for the report header
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
 *   - Path note: this file lives one directory deeper than the module entry file,
 *     so project-root paths must use dirname(__DIR__, 3), not dirname(__DIR__, 2).
 *   - Loaded by app/services/admin_gallery_report.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_gallery_report.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;

/**
 * Build a storage snapshot from report accumulators.
 *
 * @param array $job Job data.
 * @return array<string, mixed> Storage snapshot.
 */
function admin_gallery_report_storage_snapshot(array $job): array
{
    if (!function_exists('Gallery\\Services\\admin_storage_statistics_compact_source_summary') || !function_exists('Gallery\\Services\\admin_storage_statistics_snapshot_from_summaries')) {
        return ['available' => false, 'error' => 'Storage statistics service is not available.'];
    }
    $source = admin_storage_statistics_compact_source_summary(is_array($job['storage_source'] ?? null) ? $job['storage_source'] : []);
    $generated = is_array($job['storage_generated'] ?? null) ? $job['storage_generated'] : [];
    $snapshot = admin_storage_statistics_snapshot_from_summaries('', $source, $generated);
    $snapshot['available'] = true;
    $snapshot['thumbnail_metadata_used'] = !empty($job['thumbnail_metadata_used']);
    return $snapshot;
}

/**
 * Return site identity and installed version information.
 *
 * @return array<string, mixed> Site summary.
 */
function admin_gallery_report_site_summary(): array
{
    $config = cms_config();
    return [
        'site_name' => function_exists('Gallery\\Services\\site_name') ? site_name() : 'PHP Gallery',
        'version' => function_exists('Gallery\\Core\\cms_current_version') ? cms_current_version() : '',
        'base_url' => (string) ($config['base_url'] ?? ''),
        'language' => function_exists('Gallery\\Services\\translation_active_language') ? translation_active_language() : 'en',
        'generated_at_utc' => gmdate('c'),
        'server_time' => date('c'),
    ];
}

/**
 * Return PHP, server, and extension diagnostics.
 *
 * @return array<string, mixed> Runtime summary.
 */
function admin_gallery_report_runtime_summary(): array
{
    $extensions = ['exif', 'gd', 'imagick', 'pdo_mysql', 'zip', 'json', 'mbstring', 'fileinfo', 'openssl', 'curl'];
    $extensionRows = [];
    foreach ($extensions as $extension) {
        $extensionRows[] = ['extension' => $extension, 'loaded' => extension_loaded($extension) ? 'yes' : 'no'];
    }

    return [
        'php_version' => PHP_VERSION,
        'php_sapi' => PHP_SAPI,
        'os' => PHP_OS_FAMILY . ' / ' . PHP_OS,
        'uname' => php_uname(),
        'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
        'document_root' => (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
        'memory_limit' => (string) ini_get('memory_limit'),
        'max_execution_time' => (string) ini_get('max_execution_time'),
        'max_input_vars' => (string) ini_get('max_input_vars'),
        'post_max_size' => (string) ini_get('post_max_size'),
        'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
        'max_file_uploads' => (string) ini_get('max_file_uploads'),
        'timezone' => date_default_timezone_get(),
        'current_memory_usage_bytes' => memory_get_usage(true),
        'peak_memory_usage_bytes' => memory_get_peak_usage(true),
        'server_memory' => admin_gallery_report_server_memory_summary(),
        'load_average' => function_exists('sys_getloadavg') ? (sys_getloadavg() ?: []) : [],
        'extensions' => $extensionRows,
        'gd_info' => function_exists('gd_info') ? gd_info() : [],
        'imagick_version' => class_exists('Imagick') ? (string) (\Imagick::getVersion()['versionString'] ?? '') : '',
        'mysql_version' => admin_gallery_report_mysql_version(),
    ];
}

/**
 * Return physical server memory information when the host exposes it.
 *
 * @return array<string, mixed> Memory summary.
 */
function admin_gallery_report_server_memory_summary(): array
{
    $summary = [
        'available' => false,
        'source' => '',
        'total_bytes' => 0,
        'available_bytes' => 0,
        'free_bytes' => 0,
        'swap_total_bytes' => 0,
        'swap_free_bytes' => 0,
    ];
    if (!is_readable('/proc/meminfo')) {
        return $summary;
    }

    $raw = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($raw)) {
        return $summary;
    }
    $values = [];
    foreach ($raw as $line) {
        if (!preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', (string) $line, $matches)) {
            continue;
        }
        $values[$matches[1]] = (int) $matches[2] * 1024;
    }

    $summary['available'] = isset($values['MemTotal']);
    $summary['source'] = '/proc/meminfo';
    $summary['total_bytes'] = (int) ($values['MemTotal'] ?? 0);
    $summary['available_bytes'] = (int) ($values['MemAvailable'] ?? 0);
    $summary['free_bytes'] = (int) ($values['MemFree'] ?? 0);
    $summary['swap_total_bytes'] = (int) ($values['SwapTotal'] ?? 0);
    $summary['swap_free_bytes'] = (int) ($values['SwapFree'] ?? 0);
    return $summary;
}

/**
 * Return MySQL or MariaDB server version.
 *
 * @return string Version string.
 */
function admin_gallery_report_mysql_version(): string
{
    try {
        return (string) (db()->query('SELECT VERSION()')->fetchColumn() ?: '');
    } catch (Throwable) {
        return '';
    }
}

/**
 * Return configured storage paths and disk metrics.
 *
 * @return array<string, mixed> Path summary.
 */
function admin_gallery_report_data_path_summary(): array
{
    $config = cms_config();
    $paths = [
        ['label' => 'Gallery root', 'path' => (string) ($config['galleries_root'] ?? '')],
        ['label' => 'ZIP cache', 'path' => (string) ($config['zip_cache_path'] ?? '')],
        ['label' => 'Application cache', 'path' => dirname(__DIR__, 3) . '/cache'],
        ['label' => 'Navigation data', 'path' => (string) ($config['navigation_data']['bundled_navdata_path'] ?? '')],
    ];
    foreach ($paths as &$path) {
        $path['exists'] = $path['path'] !== '' && file_exists((string) $path['path']) ? 'yes' : 'no';
        $path['readable'] = $path['path'] !== '' && is_readable((string) $path['path']) ? 'yes' : 'no';
        $path['writable'] = $path['path'] !== '' && is_writable((string) $path['path']) ? 'yes' : 'no';
        $path['free_bytes'] = admin_gallery_report_disk_free_bytes((string) $path['path']);
        $path['total_bytes'] = admin_gallery_report_disk_total_bytes((string) $path['path']);
    }
    unset($path);
    return ['paths' => $paths];
}

/**
 * Return free disk bytes for a path when available.
 *
 * @param string $path Filesystem path.
 * @return int Free bytes.
 */
function admin_gallery_report_disk_free_bytes(string $path): int
{
    if ($path === '' || !\function_exists('disk_free_space')) {
        return 0;
    }
    $probe = is_dir($path) ? $path : dirname($path);
    $bytes = @\disk_free_space($probe);
    return is_float($bytes) ? (int) $bytes : 0;
}

/**
 * Return total disk bytes for a path when available.
 *
 * @param string $path Filesystem path.
 * @return int Total bytes.
 */
function admin_gallery_report_disk_total_bytes(string $path): int
{
    if ($path === '' || !\function_exists('disk_total_space')) {
        return 0;
    }
    $probe = is_dir($path) ? $path : dirname($path);
    $bytes = @\disk_total_space($probe);
    return is_float($bytes) ? (int) $bytes : 0;
}
