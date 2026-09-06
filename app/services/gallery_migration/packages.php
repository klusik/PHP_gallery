<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_migration/packages.php
 * Module Type: Service
 *
 * Purpose:
 *   Groups assets into bounded transfer packages.
 *
 * Responsibilities:
 *   - Plan packages against configured target and hard byte limits
 *   - Build the package archive on the source instance
 *   - Install a received package on the target instance
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
 * Return the preferred migration ZIP package size for the receiving server.
 *
 * The existing browser-upload settings are reused so gallery migration follows
 * the same soft package-size policy as normal prepared upload ZIPs.
 *
 * @return int Preferred package bytes.
 */
function gallery_migration_package_target_bytes(): int
{
    if (function_exists('Gallery\\Services\\browser_upload_server_upload_limit_bytes')
        && function_exists('Gallery\\Services\\browser_upload_settings')
        && function_exists('Gallery\\Services\\browser_upload_effective_batch_target_bytes')) {
        $settings = browser_upload_settings();
        $uploadLimit = browser_upload_server_upload_limit_bytes();
        return browser_upload_effective_batch_target_bytes(
            $uploadLimit,
            (float) ($settings['zip_size_threshold_ratio'] ?? 0.8),
            (int) ($settings['max_zip_batch_bytes'] ?? 25165824)
        );
    }

    return 24 * 1024 * 1024;
}

/**
 * Return the hard ZIP upload ceiling for source-push requests.
 *
 * @return int Hard package bytes.
 */
function gallery_migration_package_hard_limit_bytes(): int
{
    if (function_exists('Gallery\\Services\\browser_upload_server_upload_limit_bytes')) {
        $limit = browser_upload_server_upload_limit_bytes();
        $reserve = max(262144, (int) floor($limit * 0.02));
        return max(1, $limit - $reserve);
    }

    return 64 * 1024 * 1024;
}

/**
 * Return asset groups that should remain atomic inside migration ZIP packages.
 *
 * Each original image stays with all already-generated thumbnails. Gallery-level
 * assets remain independent so one unusually large branding asset does not force
 * otherwise unrelated gallery assets into an oversized package.
 *
 * @param array $manifest Manifest value.
 * @return array<int,array<int,array<string,mixed>>> Asset groups.
 */
function gallery_migration_package_asset_groups(array $manifest): array
{
    $groups = [];
    foreach (gallery_migration_manifest_galleries($manifest) as $galleryEntry) {
        $sourceGalleryId = (int) ($galleryEntry['source_id'] ?? 0);
        foreach ((array) ($galleryEntry['images'] ?? []) as $image) {
            if (!is_array($image)) {
                continue;
            }
            $group = [];
            foreach ((array) ($image['assets'] ?? []) as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $asset['source_gallery_id'] = (int) ($asset['source_gallery_id'] ?? $sourceGalleryId);
                $asset['label'] = (string) ($image['relative_path'] ?? $asset['filename'] ?? '');
                $group[] = $asset;
            }
            if ($group) {
                $groups[] = $group;
            }
        }

        foreach ((array) ($galleryEntry['gallery_assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $asset['source_gallery_id'] = (int) ($asset['source_gallery_id'] ?? $sourceGalleryId);
            $asset['label'] = (string) ($asset['relative_path'] ?? $asset['filename'] ?? '');
            $groups[] = [$asset];
        }
    }

    return $groups;
}

/**
 * Return a deterministic ZIP-entry name for one migration asset.
 *
 * @param array $asset Asset value.
 * @return string Archive entry name.
 */
function gallery_migration_package_entry_name(array $asset): string
{
    return 'assets/' . gallery_migration_asset_key($asset);
}

/**
 * Build the receiving server's deterministic migration package plan.
 *
 * @param array $manifest Manifest value.
 * @param ?int $targetBytes Preferred package bytes.
 * @param ?int $hardBytes Hard upload bytes.
 * @return array<int,array<string,mixed>> Package descriptors.
 */
function gallery_migration_package_plan(array $manifest, ?int $targetBytes = null, ?int $hardBytes = null): array
{
    $hardBytes = max(1, $hardBytes ?? gallery_migration_package_hard_limit_bytes());
    $targetBytes = min($hardBytes, max(1, $targetBytes ?? gallery_migration_package_target_bytes()));
    $packages = [];
    $current = [];
    $currentBytes = 0;

    $flush = static function () use (&$packages, &$current, &$currentBytes, $manifest): void {
        if (!$current) {
            return;
        }
        $keys = array_map(static fn (array $asset): string => gallery_migration_asset_key($asset), $current);
        $packageId = substr(hash('sha256', (string) ($manifest['migration_id'] ?? '') . '|' . implode('|', $keys)), 0, 24);
        $packages[] = [
            'package_id' => $packageId,
            'asset_keys' => $keys,
            'assets' => $current,
            'asset_count' => count($current),
            'source_bytes' => $currentBytes,
        ];
        $current = [];
        $currentBytes = 0;
    };

    foreach (gallery_migration_package_asset_groups($manifest) as $group) {
        $groupBytes = array_sum(array_map(static fn (array $asset): int => max(0, (int) ($asset['file_size'] ?? 0)), $group));
        if ($groupBytes > $hardBytes) {
            $label = (string) (($group[0]['label'] ?? $group[0]['filename'] ?? 'asset'));
            throw new RuntimeException(gallery_migration_t(
                'gallery_migration.error.package_too_large',
                'Migration package for {asset} is larger than the receiving server upload limit.',
                ['asset' => $label]
            ));
        }

        $groupCount = count($group);
        if ($groupCount > GALLERY_MIGRATION_PACKAGE_MAX_ASSETS) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
        }
        if ($current && (
            $currentBytes + $groupBytes > $targetBytes
            || count($current) + $groupCount > GALLERY_MIGRATION_PACKAGE_MAX_ASSETS
        )) {
            $flush();
        }
        foreach ($group as $asset) {
            $current[] = $asset;
        }
        $currentBytes += $groupBytes;

        // An atomic image package may exceed the soft target, exactly like the
        // browser upload workflow. It is flushed alone while remaining below
        // the receiving server hard limit.
        if ($currentBytes > $targetBytes) {
            $flush();
        }
    }
    $flush();

    return $packages;
}

/**
 * Return one package descriptor from a target job.
 *
 * @param array $job Job value.
 * @param string $packageId Package identifier.
 * @return ?array<string,mixed> Package descriptor or null.
 */
function gallery_migration_job_package(array $job, string $packageId): ?array
{
    $packageId = strtolower(trim($packageId));
    foreach ((array) ($job['packages'] ?? []) as $package) {
        if (is_array($package) && hash_equals((string) ($package['package_id'] ?? ''), $packageId)) {
            return $package;
        }
    }
    return null;
}

/**
 * Build a store-only ZIP package from authorized source assets.
 *
 * @param int $rootGalleryId API-key authorized root gallery.
 * @param array $assets Asset descriptors requested by the receiver.
 * @param bool $includeSubgalleries Include descendants value.
 * @return string Temporary ZIP path.
 */
function gallery_migration_build_package_file(int $rootGalleryId, array $assets, bool $includeSubgalleries): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.zip_unavailable', 'PHP ZipArchive is required for gallery migration ZIP packages.'));
    }
    if (!$assets || count($assets) > GALLERY_MIGRATION_PACKAGE_MAX_ASSETS) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
    }

    $tmp = tempnam(sys_get_temp_dir(), 'php_gallery_migration_zip_');
    if ($tmp === false) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
    }
    $zipPath = $tmp . '.zip';
    @unlink($tmp);

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($zipPath);
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_create_failed', 'Could not create migration ZIP package.'));
    }

    try {
        $seen = [];
        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
            }
            $key = gallery_migration_asset_key($asset);
            if ($key === '' || isset($seen[$key])) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
            }
            $seen[$key] = true;
            $descriptor = gallery_migration_source_asset_descriptor($rootGalleryId, $asset, $includeSubgalleries);
            $expectedSize = max(0, (int) ($asset['file_size'] ?? 0));
            $actualSize = filesize($descriptor['path']);
            if ($expectedSize > 0 && ($actualSize === false || $actualSize !== $expectedSize)) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_checksum_failed', 'Migration asset checksum does not match the manifest.'));
            }
            $expectedChecksum = strtolower((string) ($asset['checksum_sha256'] ?? ''));
            if ($expectedChecksum !== '' && strtolower((string) (hash_file('sha256', $descriptor['path']) ?: '')) !== $expectedChecksum) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.asset_checksum_failed', 'Migration asset checksum does not match the manifest.'));
            }
            $entryName = gallery_migration_package_entry_name($asset);
            if (!$zip->addFile($descriptor['path'], $entryName)) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_create_failed', 'Could not create migration ZIP package.'));
            }
            if (!method_exists($zip, 'setCompressionName') || !$zip->setCompressionName($entryName, ZipArchive::CM_STORE)) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_create_failed', 'Could not create migration ZIP package.'));
            }
        }
    } catch (Throwable $exception) {
        $zip->close();
        @unlink($zipPath);
        throw $exception;
    }

    if (!$zip->close()) {
        @unlink($zipPath);
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_create_failed', 'Could not create migration ZIP package.'));
    }

    return $zipPath;
}

/**
 * Decode asset descriptors posted to the authenticated source package endpoint.
 *
 * @param string $json JSON asset list.
 * @return array<int,array<string,mixed>> Asset descriptors.
 */
function gallery_migration_package_assets_from_json(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded) || !$decoded || count($decoded) > GALLERY_MIGRATION_PACKAGE_MAX_ASSETS) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
    }
    $assets = [];
    foreach ($decoded as $asset) {
        if (!is_array($asset)) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
        }
        $assets[] = $asset;
    }
    return $assets;
}

/**
 * Install every asset from one received migration ZIP package.
 *
 * Package extraction is explicit and entry-name based. No archive path is ever
 * extracted directly into a gallery folder.
 *
 * @param string $jobId Migration job id.
 * @param int $targetGalleryId Receiving parent gallery id.
 * @param string $packageId Package identifier.
 * @param string $zipPath Uploaded or downloaded ZIP path.
 * @return array<string,mixed> Package installation result.
 */
function gallery_migration_install_package_file(string $jobId, int $targetGalleryId, string $packageId, string $zipPath): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.zip_unavailable', 'PHP ZipArchive is required for gallery migration ZIP packages.'));
    }
    if (!is_file($zipPath)) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_missing', 'Migration ZIP package is not available.'));
    }

    $job = gallery_migration_load_job($jobId);
    if ((int) ($job['target_gallery_id'] ?? 0) !== $targetGalleryId) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.job_target_mismatch', 'Migration job does not belong to this target gallery.'));
    }
    $package = gallery_migration_job_package($job, $packageId);
    if ($package === null) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_not_in_job', 'Migration ZIP package is not part of this job.'));
    }
    $assets = array_values(array_filter((array) ($package['assets'] ?? []), 'is_array'));

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_invalid', 'Migration ZIP package request is invalid.'));
    }

    try {
        $expectedEntries = [];
        foreach ($assets as $asset) {
            $entryName = gallery_migration_package_entry_name($asset);
            if (isset($expectedEntries[$entryName])) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
            }
            $expectedEntries[$entryName] = $asset;
        }
        if ($zip->numFiles !== count($expectedEntries)) {
            throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
        }

        $seenEntries = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (!isset($expectedEntries[$name]) || isset($seenEntries[$name])) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
            }
            $seenEntries[$name] = true;
            $stat = $zip->statIndex($index);
            if (!is_array($stat)) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
            }
            $expectedSize = max(0, (int) ($expectedEntries[$name]['file_size'] ?? 0));
            $archiveSize = max(0, (int) ($stat['size'] ?? 0));
            if ($expectedSize > 0 && $archiveSize !== $expectedSize) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
            }
            if (isset($stat['comp_method']) && (int) $stat['comp_method'] !== ZipArchive::CM_STORE) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
            }
        }

        $installedKeys = [];
        $currentJob = gallery_migration_load_job($jobId);
        $alreadyReceived = (array) ($currentJob['assets_received'] ?? []);
        foreach ($assets as $asset) {
            $assetKey = gallery_migration_asset_key($asset);
            if (isset($alreadyReceived[$assetKey])) {
                $installedKeys[] = $assetKey;
                continue;
            }
            $entryName = gallery_migration_package_entry_name($asset);
            $stream = $zip->getStream($entryName);
            if (!is_resource($stream)) {
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.package_contents', 'Migration ZIP package contents do not match the prepared job.'));
            }
            $tmp = tempnam(sys_get_temp_dir(), 'php_gallery_migration_asset_');
            if ($tmp === false) {
                fclose($stream);
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
            }
            $out = fopen($tmp, 'wb');
            if ($out === false) {
                fclose($stream);
                @unlink($tmp);
                throw new RuntimeException(gallery_migration_t('gallery_migration.error.temp_failed', 'Could not create a temporary migration file.'));
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);
            try {
                $result = gallery_migration_install_asset_file($jobId, $targetGalleryId, $asset, $tmp);
                $installedKeys[] = (string) ($result['asset_key'] ?? gallery_migration_asset_key($asset));
            } finally {
                @unlink($tmp);
            }
        }
    } finally {
        $zip->close();
    }

    $updatedJob = gallery_migration_load_job($jobId);
    return [
        'ok' => true,
        'job_id' => $jobId,
        'package_id' => $packageId,
        'asset_keys' => $installedKeys,
        'received' => count((array) ($updatedJob['assets_received'] ?? [])),
        'total_assets' => count(gallery_migration_manifest_asset_refs((array) ($updatedJob['manifest'] ?? []))),
    ];
}
