<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/capabilities.php
 * Module Type: Controller
 *
 * Purpose:
 *   Resolves the schema and feature-flag readiness used by the gallery editor.
 *
 * Responsibilities:
 *   - Combine schema readiness with the matching global feature flag
 *   - Keep structured access and share-token status separate from booleans
 *   - Return one bounded array so every render phase reads the same decisions
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
 *   - Loaded by app/controllers/admin_galleries_edit_page.php; do not require this file directly.
 *   - Readiness is resolved once per request so tabs cannot disagree with each other.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Services\exif_gps_override_schema_ready;
use function Gallery\Services\exif_gps_schema_ready;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\flight_map_schema_ready;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\gallery_access_share_token_schema_status;
use function Gallery\Services\gallery_access_schema_status;
use function Gallery\Services\gallery_lightbox_browsing_mode_schema_ready;
use function Gallery\Services\gallery_voting_schema_ready;
use function Gallery\Services\picture_game_schema_ready;

/**
 * Return whether a global feature flag allows a gallery editor control.
 *
 * Feature flags are optional at this layer. When the flag service is not
 * loaded, the control stays visible so the editor keeps its historical
 * behavior.
 *
 * @param string $flag Feature flag name.
 * @return bool True when the control may be surfaced.
 */
function admin_edit_gallery_feature_enabled(string $flag): bool
{
    return !function_exists('Gallery\\Services\\feature_flag_enabled') || feature_flag_enabled($flag);
}

/**
 * Resolve every schema and feature-flag decision used by the gallery editor.
 *
 * Readiness is resolved once and shared by the POST handlers and all render
 * phases, so a control cannot be hidden in one tab and writable in another.
 *
 * @return array<string, mixed> Bounded capability decisions.
 */
function admin_edit_gallery_capabilities(): array
{
    // Structured access capability status distinguishes migration absence from inspection failure.
    $accessSchemaStatus = gallery_access_schema_status();
    // Share-token persistence is independently migrated and can therefore differ from core access readiness.
    $shareTokenSchemaStatus = gallery_access_share_token_schema_status();
    // $gpsMapReady stores whether EXIF/GPS display may be offered at all.
    $gpsMapReady = exif_gps_schema_ready() && admin_edit_gallery_feature_enabled('gallery_maps');

    return [
        'picture_game_ready' => picture_game_schema_ready()
            && admin_edit_gallery_feature_enabled('picture_game')
            && admin_edit_gallery_feature_enabled('image_voting'),
        'gps_map_ready' => $gpsMapReady,
        // $gpsMapOverrideReady stores whether GPS display supports inherited per-gallery overrides.
        'gps_map_override_ready' => $gpsMapReady && exif_gps_override_schema_ready(),
        'flight_map_ready' => flight_map_schema_ready() && admin_edit_gallery_feature_enabled('flight_maps'),
        'voting_ready' => gallery_voting_schema_ready() && admin_edit_gallery_feature_enabled('image_voting'),
        'lightbox_mode_ready' => gallery_lightbox_browsing_mode_schema_ready()
            && admin_edit_gallery_feature_enabled('lightbox_modes'),
        // Feature-only decisions control whether a control is surfaced before schema is considered.
        'picture_game_feature_enabled' => admin_edit_gallery_feature_enabled('picture_game'),
        'image_voting_feature_enabled' => admin_edit_gallery_feature_enabled('image_voting'),
        'flight_map_feature_enabled' => admin_edit_gallery_feature_enabled('flight_maps'),
        'lightbox_mode_feature_enabled' => admin_edit_gallery_feature_enabled('lightbox_modes'),
        'media_renamer_feature_enabled' => admin_edit_gallery_feature_enabled('media_renamer'),
        'upload_api_feature_enabled' => admin_edit_gallery_feature_enabled('upload_api'),
        'gallery_migration_feature_enabled' => admin_edit_gallery_feature_enabled('gallery_migration'),
        'access_schema_status' => $accessSchemaStatus,
        'access_ready' => schema_inspection_is_available($accessSchemaStatus),
        'share_token_schema_status' => $shareTokenSchemaStatus,
        'share_token_ready' => schema_inspection_is_available($shareTokenSchemaStatus),
    ];
}
