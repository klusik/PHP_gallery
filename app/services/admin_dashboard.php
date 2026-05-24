<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_dashboard.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides database-backed read helpers for the Admin dashboard.
 *
 * Responsibilities:
 *   - Keep Admin dashboard SQL outside the controller
 *   - Return normalized model data for dashboard rendering
 *   - Preserve partially migrated installation safety checks
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
 *   2026-05-18
 */

declare(strict_types=1);

/**
 * Return the total byte size of imported original gallery files.
 *
 * The dashboard intentionally reads the image metadata table here instead of
 * scanning the filesystem. The value therefore represents source files already
 * imported into the gallery index and excludes generated thumbnails, DNG display
 * masters, caches, and any other derivative files stored beside the gallery.
 */
function admin_dashboard_original_storage_bytes(): int
{
    try {
        // $row stores the aggregate as a scalar-compatible result from the images table.
        $row = db()->query('SELECT COALESCE(SUM(file_size), 0) AS original_bytes FROM images')->fetch();
        return max(0, (int) ($row['original_bytes'] ?? 0));
    } catch (Throwable) {
        return 0;
    }
}

/**
 * Return admin dashboard gallery rows with only columns used by the table.
 *
 * Optional columns are selected only when their migrations are present. This
 * keeps partially upgraded installations safe while avoiding SELECT * in the
 * dashboard hot path.
 */
function admin_dashboard_gallery_rows(bool $accessReady, bool $gpsMapReady, bool $backgroundSourceReady, bool $filenameDisplayReady, bool $votingReady, bool $pictureGameReady, bool $publicPathReady, bool $coverAssetReady): array
{
    // $selects stores the explicit gallery columns required by dashboard rendering.
    $selects = [
        'g.id',
        'g.parent_id',
        'g.folder_path',
        'g.slug',
        'g.title',
        'g.sort_order',
        'g.visibility',
        'parent.title AS parent_title',
        'COALESCE(image_counts.image_count, 0) AS image_count',
    ];

    $selects[] = $publicPathReady ? 'g.url_path' : "'' AS url_path";
    $selects[] = $accessReady ? 'g.access_mode' : "'normal' AS access_mode";
    $selects[] = $accessReady ? 'g.access_listing' : "'listed' AS access_listing";
    $selects[] = $gpsMapReady ? 'g.gps_map_enabled' : '0 AS gps_map_enabled';
    $selects[] = $backgroundSourceReady ? 'g.background_source' : 'NULL AS background_source';
    $selects[] = $filenameDisplayReady ? 'g.show_filenames' : '0 AS show_filenames';
    $selects[] = $votingReady ? 'g.voting_enabled' : '0 AS voting_enabled';
    $selects[] = $pictureGameReady ? 'g.picture_game_enabled' : '0 AS picture_game_enabled';
    $selects[] = $coverAssetReady ? 'g.cover_image_path' : 'NULL AS cover_image_path';

    // $sql stores a one-pass gallery query with image counts pre-aggregated by gallery.
    $sql = 'SELECT ' . implode(', ', $selects) . "
        FROM galleries g
        LEFT JOIN galleries parent ON parent.id = g.parent_id
        LEFT JOIN (
            SELECT gallery_id, COUNT(id) AS image_count
            FROM images
            WHERE relative_path NOT LIKE '%/%'
            GROUP BY gallery_id
        ) image_counts ON image_counts.gallery_id = g.id
        ORDER BY COALESCE(g.parent_id, 0), g.sort_order, g.title";

    return db()->query($sql)->fetchAll();
}

/**
 * Build a cheap fingerprint for gallery hierarchy state used by parent-id repair.
 */
function admin_dashboard_parent_sync_fingerprint(): string
{
    try {
        // $row stores aggregate gallery data that changes when indexed gallery rows change.
        $row = admin_render_profile_db('parent_sync_fingerprint_query', static fn (): array => db()->query("SELECT COUNT(*) AS gallery_count, COALESCE(MAX(id), 0) AS newest_id, COALESCE(MAX(updated_at), '') AS newest_updated_at, COALESCE(SUM(CHAR_LENGTH(folder_path)), 0) AS path_length_sum FROM galleries")->fetch() ?: []);
    } catch (Throwable) {
        return '';
    }

    return hash('sha256', implode('|', [
        (string) ($row['gallery_count'] ?? '0'),
        (string) ($row['newest_id'] ?? '0'),
        (string) ($row['newest_updated_at'] ?? ''),
        (string) ($row['path_length_sum'] ?? '0'),
    ]));
}
