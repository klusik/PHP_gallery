<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/feature_flags.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides global feature visibility and availability switches.
 *
 * Responsibilities:
 *   - Define every optional feature exposed in the Admin feature settings page
 *   - Persist enabled/disabled state through app_settings
 *   - Provide route and UI guards so unfinished or unwanted features can be hidden cleanly
 *   - Keep all features enabled by default for backward-compatible upgrades
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
 *   2026-06-04
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\url_for;

const FEATURE_FLAG_SETTING_PREFIX = 'feature_flag.';
const FEATURE_FLAG_SETTING_SUFFIX = '.enabled';

/**
 * Return the canonical optional-feature registry.
 *
 * Every feature is enabled when no explicit setting exists. This keeps existing
 * installations unchanged after deployment and lets administrators opt out from
 * specific tools later.
 *
 * @return array<string array<string, string>>.
 */
function feature_flag_definitions(): array
{
    return [
        'public_search' => [
            'group' => 'public_display',
            'label' => t('admin.features.public_search.label', 'Public live search'),
            'description' => t('admin.features.public_search.description', 'Thin live search bar on the public home page and inside galleries.'),
        ],
        'lightbox_modes' => [
            'group' => 'public_display',
            'label' => t('admin.features.lightbox_modes.label', 'Lightbox browsing modes'),
            'description' => t('admin.features.lightbox_modes.description', 'Public lightbox viewer, slideshow, fullscreen controls, picture strip, and 3D carousel mode controls.'),
        ],
        'picture_manager' => [
            'group' => 'public_display',
            'label' => t('admin.features.picture_manager.label', 'Public picture manager controls'),
            'description' => t('admin.features.picture_manager.description', 'Logged-in admin move/copy controls rendered over public gallery photo cards.'),
        ],
        'image_voting' => [
            'group' => 'public_display',
            'label' => t('admin.features.image_voting.label', 'Image voting'),
            'description' => t('admin.features.image_voting.description', 'Vote buttons, vote submissions, and vote indicators on gallery photos.'),
        ],
        'picture_game' => [
            'group' => 'public_display',
            'label' => t('admin.features.picture_game.label', 'Picture game'),
            'description' => t('admin.features.picture_game.description', 'Public picture-comparison game and its per-gallery enable controls.'),
        ],
        'downloads' => [
            'group' => 'public_display',
            'label' => t('admin.features.downloads.label', 'Gallery ZIP downloads'),
            'description' => t('admin.features.downloads.description', 'Download links and ZIP archive routes for one gallery or all galleries.'),
        ],
        'gallery_maps' => [
            'group' => 'maps_flightsim',
            'label' => t('admin.features.gallery_maps.label', 'EXIF GPS gallery maps'),
            'description' => t('admin.features.gallery_maps.description', 'Map buttons, photo GPS pins, and gallery maps based on image EXIF coordinates.'),
        ],
        'flight_maps' => [
            'group' => 'maps_flightsim',
            'label' => t('admin.features.flight_maps.label', 'Flight route maps'),
            'description' => t('admin.features.flight_maps.description', 'Stored simflying route maps, route text editor fields, and map payloads from resolved flight paths.'),
        ],
        'navigation_data' => [
            'group' => 'maps_flightsim',
            'label' => t('admin.features.navigation_data.label', 'Navigation data maintenance'),
            'description' => t('admin.features.navigation_data.description', 'Admin tools for local airport, navaid, and waypoint lookup data used by route maps.'),
        ],
        'simbrief' => [
            'group' => 'maps_flightsim',
            'label' => t('admin.features.simbrief.label', 'SimBrief integration'),
            'description' => t('admin.features.simbrief.description', 'Gallery description and route-map generation from the latest SimBrief OFP.'),
        ],
        'openai_text_assist' => [
            'group' => 'ai_automation',
            'label' => t('admin.features.openai_text_assist.label', 'OpenAI text assistance'),
            'description' => t('admin.features.openai_text_assist.description', 'Profile API settings and AI-assisted gallery/photo description generation or cleanup.'),
        ],
        'ai_image_metadata' => [
            'group' => 'ai_automation',
            'label' => t('admin.features.ai_image_metadata.label', 'Local AI image metadata'),
            'description' => t('admin.features.ai_image_metadata.description', 'Locally generated visual metadata used for internal search and admin diagnostics.'),
        ],
        'upload_api' => [
            'group' => 'ai_automation',
            'label' => t('admin.features.upload_api.label', 'API uploader'),
            'description' => t('admin.features.upload_api.description', 'Token-based upload API and the desktop companion upload workflow.'),
        ],
        'mobile_webdav' => [
            'group' => 'ai_automation',
            'label' => t('admin.features.mobile_webdav.label', 'Mobile WebDAV uploads'),
            'description' => t('admin.features.mobile_webdav.description', 'PhotoSync/WebDAV-compatible mobile upload endpoint and setup screen.'),
        ],
        'gallery_migration' => [
            'group' => 'ai_automation',
            'label' => t('admin.features.gallery_migration.label', 'Gallery migration transfer'),
            'description' => t('admin.features.gallery_migration.description', 'Manifest, asset receive, and status endpoints for gallery-to-gallery transfer workflows.'),
        ],
        'media_renamer' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.media_renamer.label', 'Media renamer'),
            'description' => t('admin.features.media_renamer.description', 'Admin tool for planned media filename cleanup and generated derivative movement.'),
        ],
        'telemetry' => [
            'group' => 'admin_tools',
            'label' => t('admin.features.telemetry.label', 'Anonymous telemetry'),
            'description' => t('admin.features.telemetry.description', 'Anonymous usage collection script, ingestion endpoint, and admin telemetry reports.'),
        ],
    ];
}

/**
 * Return user-facing feature groups in display order.
 *
 * @return array<string array<string, string>>.
 */
function feature_flag_groups(): array
{
    return [
        'public_display' => [
            'label' => t('admin.features.group.public_display', 'Gallery display and visitor features'),
            'description' => t('admin.features.group.public_display_help', 'Public browsing, lightbox, voting, downloads, and logged-in admin controls on gallery pages.'),
        ],
        'maps_flightsim' => [
            'label' => t('admin.features.group.maps_flightsim', 'Maps, GPS, and flight simulation'),
            'description' => t('admin.features.group.maps_flightsim_help', 'EXIF GPS maps, stored flight routes, navigation data, and SimBrief workflows.'),
        ],
        'ai_automation' => [
            'label' => t('admin.features.group.ai_automation', 'AI, uploads, and automation'),
            'description' => t('admin.features.group.ai_automation_help', 'OpenAI text tools, local AI metadata, upload APIs, mobile WebDAV, and migration transfers.'),
        ],
        'admin_tools' => [
            'label' => t('admin.features.group.admin_tools', 'Admin and maintenance tools'),
            'description' => t('admin.features.group.admin_tools_help', 'Special-purpose admin tools that can be hidden from simpler installations.'),
        ],
    ];
}

/**
 * Normalize an incoming feature key to the registry key format.
 *
 * @param string $key Lookup key.
 * @return string Text result for the caller.
 */
function feature_flag_normalize_key(string $key): string
{
    return strtolower(trim(preg_replace('/[^a-zA-Z0-9_]+/', '_', $key) ?? '', '_'));
}

/**
 * Return the app_settings key used to store one feature toggle.
 *
 * @param string $key Lookup key.
 * @return string Text result for the caller.
 */
function feature_flag_setting_key(string $key): string
{
    return FEATURE_FLAG_SETTING_PREFIX . feature_flag_normalize_key($key) . FEATURE_FLAG_SETTING_SUFFIX;
}

/**
 * Return true when the requested feature exists in the registry.
 *
 * @param string $key Lookup key.
 * @return bool True when the condition matches.
 */
function feature_flag_exists(string $key): bool
{
    return array_key_exists(feature_flag_normalize_key($key), feature_flag_definitions());
}

/**
 * Return true when a feature is globally enabled.
 *
 * Unknown feature keys deliberately return true so optional checks cannot break
 * older extension code or partially deployed files.
 *
 * @param string $key Lookup key.
 * @return bool True when the condition matches.
 */
function feature_flag_enabled(string $key): bool
{
    $key = feature_flag_normalize_key($key);
    if (!feature_flag_exists($key)) {
        return true;
    }
    return app_setting(feature_flag_setting_key($key), '1') !== '0';
}

/**
 * Persist one feature switch.
 *
 * @param string $key Lookup key.
 * @param bool $enabled Enabled flag.
 */
function set_feature_flag_enabled(string $key, bool $enabled): void
{
    $key = feature_flag_normalize_key($key);
    if (!feature_flag_exists($key)) {
        return;
    }
    set_app_setting(feature_flag_setting_key($key), $enabled ? '1' : '0');
}

/**
 * Return enabled and disabled counts for the feature registry.
 *
 * @return array{enabled:int,disabled:int,total:int} Structured result data for the caller.
 */
function feature_flag_summary_counts(): array
{
    $enabled = 0;
    $disabled = 0;
    foreach (array_keys(feature_flag_definitions()) as $key) {
        if (feature_flag_enabled($key)) {
            $enabled++;
        } else {
            $disabled++;
        }
    }
    return [
        'enabled' => $enabled,
        'disabled' => $disabled,
        'total' => $enabled + $disabled,
    ];
}

/**
 * Save all feature switches from the Admin form payload.
 *
 * @param array $post Post value.
 * @return array{enabled:int,disabled:int,total:int} Structured result data for the caller.
 */
function save_feature_flags_from_post(array $post): array
{
    $enabledKeys = [];
    foreach ((array) ($post['enabled_features'] ?? []) as $key) {
        $normalizedKey = feature_flag_normalize_key((string) $key);
        if (feature_flag_exists($normalizedKey)) {
            $enabledKeys[$normalizedKey] = true;
        }
    }

    foreach (array_keys(feature_flag_definitions()) as $key) {
        set_feature_flag_enabled($key, isset($enabledKeys[$key]));
    }

    return feature_flag_summary_counts();
}

/**
 * Return feature definitions grouped for the Admin feature settings page.
 *
 * @return array<string array{group:array<string,string>,features:array<string,array<string,string>>}>.
 */
function grouped_feature_flag_definitions(): array
{
    $groups = [];
    foreach (feature_flag_groups() as $groupKey => $groupDefinition) {
        $groups[$groupKey] = [
            'group' => $groupDefinition,
            'features' => [],
        ];
    }

    foreach (feature_flag_definitions() as $key => $definition) {
        $groupKey = (string) ($definition['group'] ?? 'admin_tools');
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'group' => [
                    'label' => $groupKey,
                    'description' => '',
                ],
                'features' => [],
            ];
        }
        $groups[$groupKey]['features'][$key] = $definition;
    }

    return array_filter($groups, static fn (array $group): bool => $group['features'] !== []);
}

/**
 * Return the route-to-feature map used by the central dispatcher.
 *
 * @return array<string,string> Structured result data for the caller.
 */
function feature_flag_route_map(): array
{
    return [
        'public_search' => 'public_search',
        'gallery_lightbox_data' => 'lightbox_modes',
        'picture_manager_move' => 'picture_manager',
        'picture_manager_copy' => 'picture_manager',
        'picture_manager_create_gallery' => 'picture_manager',
        'vote' => 'image_voting',
        'picture_game' => 'picture_game',
        'download_gallery' => 'downloads',
        'download_all' => 'downloads',
        'gallery_map_data' => 'gallery_maps',
        'admin_navdata' => 'navigation_data',
        'admin_update_navdata' => 'navigation_data',
        'navdata_lookup' => 'navigation_data',
        'admin_simbrief_description' => 'simbrief',
        'admin_openai_text_assist' => 'openai_text_assist',
        'admin_api_manager' => 'upload_api',
        'admin_upload_automation_token' => 'upload_api',
        'upload_automation_upload' => 'upload_api',
        'admin_mobile_uploads' => 'mobile_webdav',
        'mobile_webdav' => 'mobile_webdav',
        'gallery_migration_manifest' => 'gallery_migration',
        'gallery_migration_asset' => 'gallery_migration',
        'gallery_migration_receive_manifest' => 'gallery_migration',
        'gallery_migration_receive_asset' => 'gallery_migration',
        'gallery_migration_receive_complete' => 'gallery_migration',
        'gallery_migration_receive_status' => 'gallery_migration',
        'admin_gallery_migration' => 'gallery_migration',
        'admin_media_renamer' => 'media_renamer',
        'admin_telemetry' => 'telemetry',
        'admin_telemetry_settings' => 'telemetry',
        'admin_telemetry_maintenance' => 'telemetry',
        'admin_telemetry_export' => 'telemetry',
        'telemetry_ingest' => 'telemetry',
        'usage_collect' => 'telemetry',
    ];
}

/**
 * Return the feature key that owns one route, or null for core routes.
 *
 * @param string $page Page number or page data.
 * @return ?string Text result for the caller.
 */
function feature_flag_for_route(string $page): ?string
{
    $map = feature_flag_route_map();
    return $map[$page] ?? null;
}

/**
 * Return true when the current route can be dispatched.
 *
 * @param string $page Page number or page data.
 * @return bool True when the condition matches.
 */
function feature_flag_route_enabled(string $page): bool
{
    if ($page === 'gallery_map_data') {
        return feature_flag_enabled('gallery_maps') || feature_flag_enabled('flight_maps');
    }
    $featureKey = feature_flag_for_route($page);
    return $featureKey === null || feature_flag_enabled($featureKey);
}

/**
 * Return true when the current request expects a JSON response.
 *
 * @return bool True when the condition matches.
 */
function feature_flag_request_wants_json(): bool
{
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    return str_contains($accept, 'application/json')
        || str_contains($contentType, 'application/json')
        || (string) ($_GET['ajax'] ?? $_POST['ajax'] ?? '') !== '';
}

/**
 * Render a consistent disabled-feature response and stop route dispatch.
 *
 * @param string $page Page number or page data.
 */
function feature_flag_render_disabled_route(string $page): void
{
    $featureKey = feature_flag_for_route($page) ?? '';
    $definition = feature_flag_definitions()[$featureKey] ?? null;
    $label = is_array($definition) ? (string) ($definition['label'] ?? $featureKey) : $featureKey;
    if ($page === 'gallery_map_data') {
        $label = t('admin.features.combined_maps_label', 'EXIF GPS gallery maps / Flight route maps');
    }
    $message = t('admin.features.disabled_route_message', 'This feature is disabled in Admin > Features: {feature}', ['feature' => $label]);

    http_response_code(403);
    if (feature_flag_request_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'feature_disabled',
            'feature' => $featureKey,
            'message' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    render_header(t('admin.features.disabled_title', 'Feature disabled'));
    echo '<section class="hero"><h1>' . e(t('admin.features.disabled_title', 'Feature disabled')) . '</h1><p class="muted">' . e($message) . '</p></section>';
    if (current_user() && (string) (current_user()['role'] ?? '') === 'admin') {
        echo '<section class="panel"><p><a class="button" href="' . e(url_for('admin_features')) . '">' . e(t('admin.features.open_settings', 'Open feature settings')) . '</a></p></section>';
    }
    render_footer();
}
