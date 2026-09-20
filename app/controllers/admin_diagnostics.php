<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_diagnostics.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders an admin-only runtime diagnostics page for image conversion and host capability checks.
 *
 * Responsibilities:
 *   - Require admin authentication
 *   - Summarize PHP, extensions, and image-format support in a compact form
 *   - Help operators verify HEIC, DNG, and WebP availability on shared hosting
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
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Imagick;
use Throwable;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\dng_conversion_attempt_order;
use function Gallery\Services\dng_conversion_color_policy;
use function Gallery\Services\dng_conversion_color_policy_options;
use function Gallery\Services\dng_conversion_runtime_capabilities;
use function Gallery\Services\dng_conversion_source_policy;
use function Gallery\Services\dng_conversion_source_policy_options;
use function Gallery\Services\dng_derivative_generation_status;
use function Gallery\Services\dng_normalize_conversion_color_policy;
use function Gallery\Services\dng_normalize_conversion_source_policy;
use function Gallery\Services\set_app_setting;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\admin_mutation_schema_health_statuses;
use function Gallery\Services\admin_image_move_pending_health_status;
use function Gallery\Services\admin_presentation_schema_health_statuses;
use function Gallery\Services\admin_nsfw_schema_health_status;
use function Gallery\Services\admin_security_schema_health_statuses;
use function Gallery\Services\runtime_support_health_status;
use function Gallery\Views\view_render_admin_diagnostics_page;

/**
 * Authenticate and prepare the runtime, shared schema-health, and conversion report.
 *
 * GET observes bounded maintenance state without recovering pending mutations.
 * POST verifies CSRF before persisting the selected DNG conversion policies.
 * Raw schema exceptions and journal paths never enter the prepared health cards.
 *
 * @return void Render the prepared diagnostics page, or redirect after a policy update.
 */
function cms_admin_diagnostics(): void
{
    require_admin();

    if (request_method() === 'POST') {
        verify_csrf();
        $sourcePolicy = dng_normalize_conversion_source_policy((string) ($_POST['dng_conversion_source_policy'] ?? 'auto_fallback'));
        $colorPolicy = dng_normalize_conversion_color_policy((string) ($_POST['dng_conversion_color_policy'] ?? 'force_srgb'));
        set_app_setting('dng_conversion_source_policy', $sourcePolicy);
        set_app_setting('dng_conversion_color_policy', $colorPolicy);
        admin_log_event('info', 'diagnostics.dng_policy_updated', 'Admin updated DNG conversion policy.', [
            'source_policy' => $sourcePolicy,
            'color_policy' => $colorPolicy,
        ]);
        flash_message('admin_notice', t('admin.diagnostics.dng_policy_saved', 'DNG conversion policy saved.'));
        redirect_to(url_for('admin_diagnostics', ['saved' => 1]));
    }

    $runtimeSupport = runtime_support_health_status();
    $imagickLoaded = class_exists('Imagick');
    $imagickFormats = [];
    if ($imagickLoaded) {
        foreach (['HEIC', 'HEIF', 'DNG', 'WEBP', 'JPEG', 'JPG', 'PNG', 'TIFF'] as $format) {
            try {
                $formats = Imagick::queryFormats($format);
                $imagickFormats[$format] = is_array($formats) ? array_values(array_unique(array_map('strtoupper', $formats))) : [];
            } catch (Throwable) {
                $imagickFormats[$format] = [];
            }
        }
    }

    $gdInfo = function_exists('gd_info') ? gd_info() : [];
    $diagnostics = [
        ['label' => 'PHP version', 'value' => phpversion() ?: PHP_VERSION],
        ['label' => 'SAPI', 'value' => PHP_SAPI],
        ['label' => 'Loaded extensions', 'value' => implode(', ', get_loaded_extensions())],
        ['label' => 'Imagick loaded', 'value' => $imagickLoaded ? 'yes' : 'no'],
        ['label' => 'GD loaded', 'value' => function_exists('imagecreatetruecolor') ? 'yes' : 'no'],
        ['label' => 'WebP function', 'value' => function_exists('imagewebp') ? 'yes' : 'no'],
        ['label' => 'EXIF function', 'value' => function_exists('exif_read_data') ? 'yes' : 'no'],
        ['label' => 'Shell exec', 'value' => function_exists('shell_exec') ? 'yes' : 'no'],
        ['label' => 'upload_max_filesize', 'value' => (string) ini_get('upload_max_filesize')],
        ['label' => 'post_max_size', 'value' => (string) ini_get('post_max_size')],
        ['label' => 'memory_limit', 'value' => (string) ini_get('memory_limit')],
    ];
    $dngPolicy = [
        'source' => dng_conversion_source_policy(),
        'color' => dng_conversion_color_policy(),
        'status' => dng_derivative_generation_status(),
        'capabilities' => dng_conversion_runtime_capabilities(),
        'attempts' => dng_conversion_attempt_order(dng_conversion_source_policy(), dng_conversion_runtime_capabilities()),
    ];
    // $securitySchemaHealth stores the same bounded models rendered by Admin System Health.
    $securitySchemaHealth = admin_security_schema_health_statuses();
    // $mutationSchemaHealth stores Phase 10 destructive/ingestion readiness using the same bounded models.
    $mutationSchemaHealth = admin_mutation_schema_health_statuses();
    $imageMovePending = admin_image_move_pending_health_status($mutationSchemaHealth['mutation_gallery_move'] ?? [], true);
    // $presentationSchemaHealth stores Phase 11 optional presentation/reporting readiness.
    $presentationSchemaHealth = admin_presentation_schema_health_statuses();
    // Preserve the established NSFW variable for compatibility with diagnostics tests and extensions.
    $nsfwSchemaHealth = $securitySchemaHealth['nsfw_guard'] ?? admin_nsfw_schema_health_status();
    // $schemaFeatureLabels maps bounded capability identifiers to operator-readable report labels.
    $schemaFeatureLabels = [
        'gallery_access' => 'Gallery password and access policy',
        'gallery_visibility' => 'Gallery visibility compatibility',
        'gallery_share_token' => 'Gallery share-token storage',
        'nsfw_guard' => 'NSFW Guard',
        'auth_persistent_login' => 'Persistent administrator login',
        'auth_password_reset' => 'Password reset storage',
        'auth_external_identity' => 'External identity links',
        'presentation_gps_exif' => 'GPS and EXIF maps',
        'presentation_gps_override' => 'Per-gallery GPS map overrides',
        'presentation_flight_map' => 'Flight route maps',
        'presentation_flight_navdata' => 'Flight-map navigation data',
        'presentation_image_voting' => 'Image voting',
        'presentation_picture_game' => 'Picture game',
        'presentation_lightbox_override' => 'Lightbox mode overrides',
        'presentation_openai_text' => 'OpenAI text assistance',
        'presentation_openai_image_input' => 'OpenAI image input',
        'presentation_ai_image_analysis' => 'AI image metadata',
        'presentation_simbrief_route_map' => 'SimBrief route-map persistence',
        'presentation_navigation_cache' => 'Navigation data cache',
        'presentation_navigation_account' => 'Navigation account storage',
        'presentation_telemetry_reporting' => 'Telemetry reporting',
        'presentation_admin_gallery_report' => 'Complete Admin gallery report',
        'mutation_gallery_delete' => t('admin.dashboard.mutation_schema_feature_gallery_delete', 'Gallery and image deletion'),
        'mutation_gallery_move' => t('admin.dashboard.mutation_schema_feature_gallery_move', 'Gallery and image move/copy'),
        'mutation_duplicate_photo_ledger' => t('admin.dashboard.mutation_schema_feature_duplicate_ledger', 'Duplicate Photo Detector ledger'),
        'mutation_upload_ingestion' => t('admin.dashboard.mutation_schema_feature_upload_ingestion', 'Gallery upload ingestion'),
        'mutation_upload_automation' => t('admin.dashboard.mutation_schema_feature_upload_automation', 'Upload automation tokens'),
        'mutation_gallery_migration' => t('admin.dashboard.mutation_schema_feature_gallery_migration', 'Gallery migration'),
        'mutation_mobile_webdav' => t('admin.dashboard.mutation_schema_feature_mobile_webdav', 'Mobile WebDAV uploads'),
        'mutation_thumbnail_metadata' => t('admin.dashboard.mutation_schema_feature_thumbnail_metadata', 'Thumbnail metadata maintenance'),
        'mutation_database_maintenance' => t('admin.dashboard.mutation_schema_feature_database_maintenance', 'Database cleanup and repair'),
        'mutation_application_update' => t('admin.dashboard.mutation_schema_feature_application_update', 'Application update activation'),
    ];
    // $schemaSuggestedCheckLabels turns bounded model identifiers into copy-report guidance.
    $schemaSuggestedCheckLabels = [
        'database_connection' => 'verify database connectivity',
        'selected_database' => 'verify the configured database name',
        'schema_inspection_permissions' => 'verify permission to inspect database metadata',
        'pending_migrations' => 'review and apply pending database migrations',
    ];
    $reportLines = [
        'PHP Gallery runtime diagnostics',
        'PHP version: ' . ($diagnostics[0]['value'] ?? ''),
        'SAPI: ' . ($diagnostics[1]['value'] ?? ''),
        'Loaded extensions: ' . ($diagnostics[2]['value'] ?? ''),
        'Imagick loaded: ' . ($diagnostics[3]['value'] ?? ''),
        'GD loaded: ' . ($diagnostics[4]['value'] ?? ''),
        'WebP function: ' . ($diagnostics[5]['value'] ?? ''),
        'EXIF function: ' . ($diagnostics[6]['value'] ?? ''),
        'Shell exec: ' . ($diagnostics[7]['value'] ?? ''),
        'upload_max_filesize: ' . ($diagnostics[8]['value'] ?? ''),
        'post_max_size: ' . ($diagnostics[9]['value'] ?? ''),
        'memory_limit: ' . ($diagnostics[10]['value'] ?? ''),
        '',
        'Security and authentication database status',
    ];
    foreach ($securitySchemaHealth as $feature => $schemaHealth) {
        if (!is_array($schemaHealth)) {
            continue;
        }
        $suggestedChecks = array_values(array_map(
            static fn (string $check): string => $schemaSuggestedCheckLabels[$check] ?? $check,
            array_map('strval', (array) ($schemaHealth['suggested_checks'] ?? []))
        ));
        $reportLines[] = ($schemaFeatureLabels[$feature] ?? $feature) . ': ' . (string) ($schemaHealth['state'] ?? 'unknown');
        $reportLines[] = '  Affected objects: ' . implode(', ', array_map('strval', (array) ($schemaHealth['affected_objects'] ?? [])));
        $reportLines[] = '  Suggested checks: ' . implode(', ', $suggestedChecks);
        if ((string) ($schemaHealth['request_id'] ?? '') !== '') {
            $reportLines[] = '  Request reference: ' . (string) $schemaHealth['request_id'];
        }
    }
    $reportLines[] = '';
    $reportLines[] = 'Destructive and ingestion database status';
    foreach ($mutationSchemaHealth as $feature => $schemaHealth) {
        if (!is_array($schemaHealth)) {
            continue;
        }
        $suggestedChecks = array_values(array_map(
            static fn (string $check): string => $schemaSuggestedCheckLabels[$check] ?? $check,
            array_map('strval', (array) ($schemaHealth['suggested_checks'] ?? []))
        ));
        $reportLines[] = ($schemaHealth['title'] ?? $schemaFeatureLabels[$feature] ?? $feature) . ': ' . (string) ($schemaHealth['state'] ?? 'unknown');
        if (isset($schemaHealth['message'])) {
            $reportLines[] = '  ' . $schemaHealth['message'];
        }
        $reportLines[] = '  Affected objects: ' . implode(', ', array_map('strval', (array) ($schemaHealth['affected_objects'] ?? [])));
        $reportLines[] = '  Suggested checks: ' . implode(', ', $suggestedChecks);
        if ((string) ($schemaHealth['request_id'] ?? '') !== '') {
            $reportLines[] = '  Request reference: ' . (string) $schemaHealth['request_id'];
        }
    }
    $reportLines[] = '';
    $reportLines[] = 'Optional presentation and reporting database status';
    foreach ($presentationSchemaHealth as $feature => $schemaHealth) {
        if (!is_array($schemaHealth)) {
            continue;
        }
        $suggestedChecks = array_values(array_map(
            static fn (string $check): string => $schemaSuggestedCheckLabels[$check] ?? $check,
            array_map('strval', (array) ($schemaHealth['suggested_checks'] ?? []))
        ));
        $reportLines[] = ($schemaFeatureLabels[$feature] ?? $feature) . ': ' . (string) ($schemaHealth['state'] ?? 'unknown');
        $reportLines[] = '  Affected objects: ' . implode(', ', array_map('strval', (array) ($schemaHealth['affected_objects'] ?? [])));
        $reportLines[] = '  Suggested checks: ' . implode(', ', $suggestedChecks);
        if ((string) ($schemaHealth['request_id'] ?? '') !== '') {
            $reportLines[] = '  Request reference: ' . (string) $schemaHealth['request_id'];
        }
    }
    $reportLines = array_merge($reportLines, [
        '',
        'DNG conversion policy',
        'Source policy: ' . $dngPolicy['source'],
        'Color policy: ' . $dngPolicy['color'],
        'Runtime paths: ' . implode(', ', $dngPolicy['attempts']),
        'Status: ' . (string) ($dngPolicy['status']['reason'] ?? ''),
        '',
        'Imagick format support',
    ]);
    if (!$imagickLoaded) {
        $reportLines[] = 'Imagick: not loaded';
    } else {
        foreach ($imagickFormats as $format => $formats) {
            $reportLines[] = $format . ': ' . (!empty($formats) ? 'yes (' . implode(', ', $formats) . ')' : 'no');
        }
    }
    $reportLines[] = '';
    $reportLines[] = 'GD capabilities';
    if (empty($gdInfo)) {
        $reportLines[] = 'GD: not available';
    } else {
        foreach ($gdInfo as $label => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map(static fn ($item): string => (string) $item, $value));
            }
            $reportLines[] = (string) $label . ': ' . (string) $value;
        }
    }
    $reportLines = array_merge($reportLines, [''], $runtimeSupport['report_lines']);
    $reportLines = array_merge($reportLines, [''], $imageMovePending['report_lines']);
    $reportText = implode("\n", $reportLines);

    $viewModel = [
        'diagnostics' => $diagnostics,
        'runtime_support_status' => $runtimeSupport,
        'image_move_pending_status' => $imageMovePending,
        'dng_policy' => $dngPolicy,
        'security_schema_health' => $securitySchemaHealth,
        'mutation_schema_health' => $mutationSchemaHealth,
        'presentation_schema_health' => $presentationSchemaHealth,
        'schema_feature_labels' => $schemaFeatureLabels,
        'report_text' => $reportText,
        'notice' => (string) flash_message('admin_notice'),
        'imagick_loaded' => $imagickLoaded,
        'imagick_formats' => $imagickFormats,
        'gd_info' => $gdInfo,
        'source_policy_options' => dng_conversion_source_policy_options(),
        'color_policy_options' => dng_conversion_color_policy_options(),
    ];

    render_header(t('admin.diagnostics.page_title', 'Runtime diagnostics'));
    view_render_admin_diagnostics_page($viewModel);
    render_footer();
}
