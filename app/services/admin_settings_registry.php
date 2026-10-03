<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_settings_registry.php
 * Module Type: Service
 *
 * Purpose:
 *   Defines the centralized Admin Settings taxonomy and setting ownership registry.
 *
 * Responsibilities:
 *   - Keep stable section identifiers independent from translated labels
 *   - Describe canonical setting ownership without moving persistence into the view
 *   - Resolve safe current/default summaries for the central Settings hub
 *   - Whitelist centrally editable preferences and coupled upload tuning
 *   - Redact sensitive settings before they reach presentation code
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
 *   - Specialized controllers remain the canonical owner for complex or sensitive workflows.
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_runtime_limit;
use function Gallery\Core\url_for;

require_once __DIR__ . '/site_url.php';

/**
 * Return the administrator preference for showing historical upload navigation.
 *
 * @return bool Whether the three legacy upload links should be visible.
 */
function admin_legacy_upload_navigation_enabled(): bool
{
    return app_setting('admin_legacy_upload_navigation_enabled', '0') === '1';
}

/**
 * Describe centrally editable browser upload controls using canonical runtime limits.
 *
 * @return array<string,array<string,mixed>> Definitions with human-readable MB values.
 */
function admin_settings_browser_upload_definitions(): array
{
    $settings = browser_upload_settings();
    $definitions = [];
    foreach (['default_worker_count', 'max_worker_count', 'hard_worker_cap'] as $name) {
        $definitions['browser_upload_' . $name] = ['current' => (string) $settings[$name], 'default' => (string) browser_upload_default_settings()[$name], 'validation' => ['min' => cms_runtime_limit('browser_upload.min_worker_count'), 'max' => cms_runtime_limit('browser_upload.hard_worker_cap'), 'step' => 1]];
    }
    $definitions['browser_upload_enabled'] = ['current' => $settings['enabled'] ? '1' : '0', 'default' => '1', 'validation' => []];
    $definitions['browser_upload_batch_size_policy'] = ['current' => $settings['batch_size_policy'], 'default' => BROWSER_UPLOAD_BATCH_POLICY_LIMIT_RATIO, 'validation' => ['allowed' => [BROWSER_UPLOAD_BATCH_POLICY_LIMIT_RATIO]]];
    $definitions['browser_upload_zip_size_threshold_ratio'] = ['current' => (string) $settings['zip_size_threshold_ratio'], 'default' => (string) cms_runtime_limit('browser_upload.default_zip_ratio'), 'validation' => ['min' => cms_runtime_limit('browser_upload.min_zip_ratio'), 'max' => cms_runtime_limit('browser_upload.max_zip_ratio'), 'step' => 0.01]];
    $definitions['browser_upload_max_items_per_batch'] = ['current' => (string) $settings['max_items_per_batch'], 'default' => (string) cms_runtime_limit('browser_upload.default_max_items_per_batch'), 'validation' => ['min' => cms_runtime_limit('browser_upload.min_items_per_batch'), 'max' => cms_runtime_limit('browser_upload.max_items_per_batch'), 'step' => 1]];
    foreach (['browser_upload_max_zip_batch_bytes' => ['max_zip_batch_bytes', 'browser_upload.default_max_zip_batch_bytes', 'browser_upload.min_max_zip_batch_bytes', 'browser_upload.hard_max_zip_batch_bytes'], 'browser_thumbnail_rebuild_source_chunk_bytes' => ['thumbnail_rebuild_source_chunk_bytes', 'browser_thumbnail_rebuild.default_chunk_bytes', 'browser_thumbnail_rebuild.min_chunk_bytes', 'browser_thumbnail_rebuild.hard_chunk_bytes']] as $id => $limits) {
        $definitions[$id] = ['current' => (string) ($settings[$limits[0]] / 1048576), 'default' => (string) (cms_runtime_limit($limits[1]) / 1048576), 'validation' => ['min' => cms_runtime_limit($limits[2]) / 1048576, 'max' => cms_runtime_limit($limits[3]) / 1048576, 'step' => 0.01]];
    }
    return $definitions;
}

/**
 * Strictly validate one browser upload field before coupled normalization.
 *
 * @param string $id Stable central setting identifier.
 * @param string|int|float|bool $value Submitted scalar value, with sizes expressed in MB.
 * @return string Canonical validated scalar value.
 */
function admin_settings_browser_upload_normalize_value(string $id, mixed $value): string
{
    $definition = admin_settings_browser_upload_definitions()[$id] ?? null;
    if ($definition === null || !is_scalar($value)) {
        throw new InvalidArgumentException('Invalid browser upload setting.');
    }
    $value = trim((string) $value);
    if ($id === 'browser_upload_enabled') {
        return admin_upload_auto_rename_setting_normalize($value === '' ? '0' : $value);
    }
    $validation = $definition['validation'];
    if (isset($validation['allowed'])) {
        if (!in_array($value, $validation['allowed'], true)) {
            throw new InvalidArgumentException('Choose a supported upload batch policy.');
        }
        return $value;
    }
    $integer = $validation['step'] === 1;
    if (!preg_match($integer ? '/^\d+$/' : '/^\d+(?:\.\d+)?$/', $value)
        || !is_finite((float) $value) || (float) $value < $validation['min'] || (float) $value > $validation['max']) {
        throw new InvalidArgumentException(t('admin.settings.uploads.invalid_number', 'Enter a number within the displayed limits.'));
    }
    return $integer ? (string) (int) $value : (string) (float) $value;
}

/**
 * Prepare a complete browser upload patch and validate coupled worker bounds.
 *
 * @param array<string,string> $values Validated central values; absent fields stay unchanged.
 * @return array<string,string> Canonical bulk-save input preserving omitted settings.
 */
function admin_settings_browser_upload_values(array $values): array
{
    $definitions = admin_settings_browser_upload_definitions();
    if (array_diff_key($values, $definitions) !== []) {
        throw new InvalidArgumentException('Unknown browser upload setting.');
    }
    $input = [];
    foreach ($definitions as $id => $definition) {
        $input[$id] = array_key_exists($id, $values)
            ? admin_settings_browser_upload_normalize_value($id, $values[$id]) : $definition['current'];
    }
    if ((int) $input['browser_upload_default_worker_count'] > (int) $input['browser_upload_max_worker_count']
        || (int) $input['browser_upload_max_worker_count'] > (int) $input['browser_upload_hard_worker_cap']) {
        throw new InvalidArgumentException(t('admin.settings.uploads.invalid_workers', 'Default workers must not exceed maximum workers, and maximum workers must not exceed the hard cap.'));
    }
    $input['browser_upload_max_zip_batch_megabytes'] = $input['browser_upload_max_zip_batch_bytes'];
    $input['browser_thumbnail_rebuild_source_chunk_megabytes'] = $input['browser_thumbnail_rebuild_source_chunk_bytes'];
    return $input;
}

/**
 * Return the stable top-level Settings section taxonomy.
 *
 * @return array<string,array<string,string>> Section definitions keyed by stable identifier.
 */
function admin_settings_sections(): array
{
    return [
        'general' => [
            'label_key' => 'admin.settings.section.general',
            'label' => 'General',
            'description_key' => 'admin.settings.section.general_hint',
            'description' => 'Site identity, public language, URL behavior, and public search.',
        ],
        'site' => [
            'label_key' => 'admin.settings.section.site',
            'label' => 'Website address',
            'description_key' => 'admin.settings.section.site_hint',
            'description' => 'Public installation URL stored directly in config.php.',
        ],
        'appearance' => [
            'label_key' => 'admin.settings.section.appearance',
            'label' => 'Public appearance',
            'description_key' => 'admin.settings.section.appearance_hint',
            'description' => 'Theme defaults, page width, gallery-card presentation, and hero display.',
        ],
        'content' => [
            'label_key' => 'admin.settings.section.content',
            'label' => 'Tags and content',
            'description_key' => 'admin.settings.section.content_hint',
            'description' => 'Public tag-page layout, hero tag behavior, and tag administration.',
        ],
        'media' => [
            'label_key' => 'admin.settings.section.media',
            'label' => 'Media and browsing',
            'description_key' => 'admin.settings.section.media_hint',
            'description' => 'Thumbnail rendering, lightbox browsing, GPS display, and media presentation defaults.',
        ],
        'uploads' => [
            'label_key' => 'admin.settings.section.uploads',
            'label' => 'Uploads and automation',
            'description_key' => 'admin.settings.section.uploads_hint',
            'description' => 'Upload format policy, browser-side preparation, and upload automation entry points.',
        ],
        'privacy' => [
            'label_key' => 'admin.settings.section.privacy',
            'label' => 'Privacy and diagnostics',
            'description_key' => 'admin.settings.section.privacy_hint',
            'description' => 'Telemetry, SEO request protection, diagnostics, and non-destructive maintenance preferences.',
        ],
        'advanced' => [
            'label_key' => 'admin.settings.section.advanced',
            'label' => 'Advanced',
            'description_key' => 'admin.settings.section.advanced_hint',
            'description' => 'Sensitive, destructive, or specialized configuration remains on dedicated Admin pages.',
        ],
    ];

}

/**
 * Return exhaustive discovery entries for global controls owned by specialized pages.
 *
 * These entries deliberately remain summary-only. They make every global control
 * searchable without duplicating validation, persistence, secrets, or destructive
 * actions in the central Settings page.
 *
 * @return array<string,array<string,mixed>> Specialized discovery entries.
 */
function admin_settings_specialized_catalog(): array
{
    $definitions = [
        // Theme: appearance, identity, width, map pin, tags, and preview.
        ['theme_accent', 'appearance', 'Accent color', 'Buttons, selected pagination, and important public links.', 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-colors'], 'admin-theme-tab-appearance'],
        ['theme_accent_dark', 'appearance', 'Dark accent color', 'Hover states, outlines, and secondary public actions.', 'admin_theme', [], 'admin-theme-appearance-subtab-colors'],
        ['theme_paper', 'appearance', 'Page background color', 'Base public page color behind all content.', 'admin_theme', [], 'admin-theme-appearance-subtab-colors'],
        ['theme_panel', 'appearance', 'Panel background color', 'Background color for public cards and panels.', 'admin_theme', [], 'admin-theme-appearance-subtab-colors'],
        ['theme_gallery_panel', 'appearance', 'Open gallery panel color', 'Background used by gallery-specific cards and image panels.', 'admin_theme', [], 'admin-theme-appearance-subtab-colors'],
        ['theme_header_text', 'appearance', 'Header title color', 'Color of the main site title in the public header.', 'admin_theme', [], 'admin-theme-appearance-subtab-colors'],
        ['theme_hero_text', 'appearance', 'Gallery title color', 'Color of open-gallery title and hero text.', 'admin_theme', [], 'admin-theme-appearance-subtab-colors'],
        ['theme_radius', 'appearance', 'Rounded corners', 'Global public corner-radius style.', 'admin_theme', [], 'admin-theme-appearance-subtab-colors'],
        ['theme_font', 'appearance', 'Font style', 'Classic serif or clean sans-serif public typography.', 'admin_theme', [], 'admin-theme-appearance-subtab-colors'],
        ['theme_page_width_custom', 'appearance', 'Custom page width', 'Custom public container width in pixels.', 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-width-map'], 'admin-theme-tab-appearance'],
        ['theme_gps_pin_enabled', 'appearance', 'Photo-card GPS pin', 'Show a GPS pin on photo cards with location metadata.', 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-width-map'], 'admin-theme-tab-appearance'],
        ['theme_gps_pin_background_enabled', 'appearance', 'GPS pin background', 'Show the background underlay behind photo-card GPS pins.', 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-width-map'], 'admin-theme-tab-appearance'],
        ['theme_gps_pin_size', 'appearance', 'GPS pin size', 'Diameter of the GPS marker on public photo cards.', 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-width-map'], 'admin-theme-tab-appearance'],
        ['theme_gps_pin_background_size', 'appearance', 'GPS pin background size', 'Diameter of the GPS marker background badge.', 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-width-map'], 'admin-theme-tab-appearance'],
        ['theme_hero_tag_display_all', 'content', 'Display all gallery hero tags', 'Show every gallery tag immediately instead of using expansion.', 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-gallery-tags'], 'admin-theme-tab-appearance'],
        ['theme_hero_tag_scrollbar_enabled', 'content', 'Hero tag scrollbar', 'Use bounded scrolling when a gallery has many hero tags.', 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-gallery-tags'], 'admin-theme-tab-appearance'],
        ['theme_hero_tag_scrollbar_rows', 'content', 'Hero tag scrollbar rows', 'Maximum visible tag rows before hero-tag scrolling begins.', 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-gallery-tags'], 'admin-theme-tab-appearance'],
        ['theme_live_preview', 'appearance', 'Theme live preview', 'Preview public Theme colors, corners, typography, width, and identity.', 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-preview'], 'admin-theme-tab-appearance'],

        // Theme: layout, cards, grids, shortcuts, lightbox, and media.
        ['theme_favorite_galleries', 'content', 'Favorite gallery shortcuts', 'Configure public-header shortcuts to the main page or selected galleries.', 'admin_theme', [], 'admin-theme-tab-layout'],
        ['theme_gallery_count_badge_enabled', 'appearance', 'Gallery picture-count badge', 'Show the stacked-picture image count on gallery cards and in opened gallery heroes.', 'admin_theme', [], 'admin-gallery-count-badge'],
        ['reset_all_gallery_grid_overrides', 'advanced', 'Reset gallery grid overrides', 'Clear every per-gallery custom grid and stale gallery.json grid key.', 'admin_theme', [], 'admin-home-grid'],
        ['theme_header_branding', 'appearance', 'Header branding image', 'Upload, position, size, crop, or remove the public header branding asset.', 'admin_theme', [], 'admin-theme-tab-media'],
        ['theme_background_image', 'appearance', 'Public background image', 'Upload, position, size, or remove the public page background.', 'admin_theme', [], 'admin-theme-tab-media'],
        ['theme_favicon', 'appearance', 'Site favicon', 'Upload, crop, generate, or remove browser and shortcut icons.', 'admin_theme', [], 'admin-theme-tab-media'],
        ['theme_media_cleanup', 'advanced', 'Theme media cleanup', 'Remove superseded generated Theme media files safely.', 'admin_theme', [], 'admin-theme-tab-media'],
        ['theme_language_catalog', 'general', 'Installed language', 'Select the active public language catalog.', 'admin_theme', [], 'admin-theme-tab-language'],
        ['theme_language_editor', 'advanced', 'Language string editor', 'Search and edit individual translation strings in an installed language pack.', 'admin_theme', [], 'admin-theme-tab-language'],
        ['theme_language_import', 'advanced', 'Import language pack', 'Import a translated JSON language catalog.', 'admin_theme', [], 'admin-theme-tab-language'],
        ['theme_language_export', 'advanced', 'Export language pack', 'Download an installed language catalog for editing or backup.', 'admin_theme', [], 'admin-theme-tab-language'],
        ['theme_custom_css_editor', 'advanced', 'Custom CSS editor', 'Edit raw custom public stylesheet rules.', 'admin_theme', [], 'admin-theme-tab-custom-css'],
        ['theme_custom_css_preset', 'advanced', 'Custom CSS preset', 'Select and apply a built-in custom CSS starting point.', 'admin_theme', [], 'admin-theme-tab-custom-css'],
        ['theme_custom_css_import', 'advanced', 'Import custom CSS', 'Upload a custom stylesheet into the Theme editor.', 'admin_theme', [], 'admin-theme-tab-custom-css'],

        // Gallery-scoped upload credentials remain specialized.
        ['upload_api_key_management', 'advanced', 'Upload automation API keys', 'Create, revoke, and inspect gallery-scoped upload API credentials.', 'admin_api_manager', [], ''],

        // Telemetry and privacy.
        ['telemetry_performance_enabled', 'privacy', 'Browser performance telemetry', 'Collect anonymous browser performance measurements.', 'admin_telemetry', [], ''],
        ['telemetry_cache_enabled', 'privacy', 'Cache telemetry', 'Collect thumbnail and media cache-hit/miss metrics.', 'admin_telemetry', [], ''],
        ['telemetry_database_enabled', 'privacy', 'Database telemetry', 'Collect bounded query timing and database operation metrics.', 'admin_telemetry', [], ''],
        ['telemetry_respect_dnt', 'privacy', 'Respect Do Not Track', 'Suppress public telemetry when the browser requests Do Not Track.', 'admin_telemetry', [], ''],
        ['telemetry_admin_excluded', 'privacy', 'Exclude administrators from telemetry', 'Do not count signed-in Admin browsing in public usage metrics.', 'admin_telemetry', [], ''],
        ['telemetry_max_photo_view_seconds', 'privacy', 'Maximum counted photo-view time', 'Cap engagement time contributed by one open photo.', 'admin_telemetry', [], ''],
        ['telemetry_raw_retention_days', 'privacy', 'Raw telemetry retention', 'Days to retain detailed anonymous telemetry events.', 'admin_telemetry', [], ''],
        ['telemetry_hourly_retention_days', 'privacy', 'Hourly telemetry retention', 'Days to retain compact hourly aggregate metrics.', 'admin_telemetry', [], ''],
        ['telemetry_daily_retention_days', 'privacy', 'Daily telemetry retention', 'Days to retain long-term daily aggregate metrics.', 'admin_telemetry', [], ''],
        ['telemetry_export', 'privacy', 'Telemetry HTML export', 'Generate a private aggregated telemetry report.', 'admin_telemetry', [], ''],
        ['telemetry_maintenance', 'privacy', 'Telemetry aggregation and cleanup', 'Run telemetry rollup, retention, and maintenance jobs.', 'admin_telemetry', [], ''],

        // Scheduled maintenance, thumbnails, navigation, diagnostics, and operations.
        ['thumbnail_compatibility_mode', 'media', 'Thumbnail compatibility mode', 'Choose modern WebP-only support or legacy JPEG fallback generation.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['thumbnail_exact_scan', 'media', 'Exact thumbnail scan', 'Scan every expected thumbnail variant and report missing files.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['thumbnail_rebuild', 'media', 'Thumbnail rebuild', 'Generate missing or refreshed thumbnail variants.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['thumbnail_cache_cleanup', 'media', 'Thumbnail cache cleanup', 'Remove generated thumbnail cache through protected maintenance controls.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['archive_downloads', 'media', 'Gallery archive downloads', 'Configure and maintain generated gallery download archives.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['media_renamer', 'media', 'Physical media renamer', 'Preview and apply safe filename normalization for gallery media.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['site_maintenance_request_trigger_enabled', 'privacy', 'Request-triggered maintenance', 'Allow authenticated Admin dashboard requests to schedule due maintenance without using public visitor traffic.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['site_maintenance_utc_time', 'privacy', 'Maintenance start time', 'Daily UTC start time for scheduled maintenance.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['site_maintenance_window_minutes', 'privacy', 'Maintenance window', 'Overall time window in which scheduled maintenance may run.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['site_maintenance_batch_size', 'privacy', 'Maintenance batch size', 'Maximum images inspected by one internal maintenance batch.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['site_maintenance_time_budget_seconds', 'privacy', 'Maintenance call time budget', 'Maximum execution time allocated to one maintenance request.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['site_maintenance_run_now', 'advanced', 'Run maintenance now', 'Run one bounded safe maintenance check immediately.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['site_maintenance_reset_state', 'advanced', 'Reset maintenance state', 'Clear interrupted scheduled-maintenance progress safely.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['site_maintenance_rotate_token', 'advanced', 'Rotate maintenance cron token', 'Invalidate the existing secret web-cron URL and issue a new token.', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance'],
        ['navigation_data_accounts', 'advanced', 'Navigation-data accounts', 'Configure source accounts used to download flight navigation data.', 'admin_navdata', [], ''],
        ['navigation_data_update', 'advanced', 'Update navigation data', 'Download, import, and inspect flight-map navigation datasets.', 'admin_navdata', [], ''],
        ['admin_logs', 'advanced', 'Admin operational logs', 'Filter, review, resolve, archive, and inspect application events.', 'admin_logs', [], ''],
        ['application_updates', 'advanced', 'Application updates', 'Check, download, verify, and apply PHP Gallery updates.', 'admin_update', [], ''],
        ['integrity_check', 'advanced', 'Core integrity check', 'Compare deployed core files with the release integrity manifest.', 'admin_integrity', [], ''],
        ['runtime_diagnostics', 'advanced', 'Runtime diagnostics', 'Inspect PHP, image libraries, upload limits, and conversion capability.', 'admin', [], 'admin-tab-maintenance'],
        ['feature_flags', 'advanced', 'Feature visibility', 'Enable or hide optional and unfinished application features.', 'admin_features', [], ''],
        ['gallery_report', 'advanced', 'Complete gallery report', 'Generate the detailed HTML storage, database, telemetry, EXIF, GPS, and runtime report.', 'admin_gallery_report', [], ''],
        ['database_audit', 'advanced', 'Database schema audit', 'Inspect every table, column, key, migration reference, and storage estimate.', 'admin_storage_statistics', ['tab' => 'maintenance'], ''],
        ['database_safe_cleanup', 'advanced', 'Safe database cleanup', 'Preview or remove high-confidence orphaned and expired rows in bounded batches.', 'admin_storage_statistics', ['tab' => 'maintenance'], ''],
        ['database_schema_repair', 'advanced', 'Legacy database schema repair', 'Preview and apply the dedicated idempotent schema repair migration.', 'admin_storage_statistics', ['tab' => 'maintenance'], ''],
        ['database_analyze', 'advanced', 'Analyze database tables', 'Refresh optimizer statistics for explicitly selected tables.', 'admin_storage_statistics', ['tab' => 'maintenance'], ''],
        ['database_optimize', 'advanced', 'Optimize database tables', 'Rebuild explicitly selected tables to reclaim estimated free space.', 'admin_storage_statistics', ['tab' => 'maintenance'], ''],

        // Account, authentication, mail, linked services, and user preferences.
        ['account_username', 'advanced', 'Administrator username', 'Change the signed-in administrator account name.', 'admin_account', [], ''],
        ['account_password', 'advanced', 'Administrator password', 'Change the signed-in administrator password.', 'admin_account', [], ''],
        ['password_reset_enabled', 'advanced', 'Password reset', 'Enable or disable administrator password recovery by email.', 'admin_account', [], ''],
        ['password_reset_transport', 'advanced', 'Password reset mail transport', 'Choose PHP mail or authenticated SMTP delivery.', 'admin_account', [], ''],
        ['password_reset_from_email', 'advanced', 'Password reset sender email', 'Sender email address used for recovery messages.', 'admin_account', [], ''],
        ['password_reset_from_name', 'advanced', 'Password reset sender name', 'Readable sender name used for recovery messages.', 'admin_account', [], ''],
        ['password_reset_token_lifetime_minutes', 'advanced', 'Password reset token lifetime', 'Minutes before a password-reset link expires.', 'admin_account', [], ''],
        ['password_reset_smtp_host', 'advanced', 'SMTP host', 'Mail server hostname for password recovery.', 'admin_account', [], ''],
        ['password_reset_smtp_port', 'advanced', 'SMTP port', 'Mail server port for password recovery.', 'admin_account', [], ''],
        ['password_reset_smtp_encryption', 'advanced', 'SMTP encryption', 'TLS or connection security mode for password-reset mail.', 'admin_account', [], ''],
        ['password_reset_smtp_username', 'advanced', 'SMTP username', 'Authentication username for password-reset mail.', 'admin_account', [], ''],
        ['google_account_linking', 'advanced', 'Google account linking', 'Connect or disconnect a Google identity for administrator sign-in.', 'admin_account', [], ''],
        ['openai_text_assist_enabled', 'advanced', 'OpenAI text assistance', 'Enable or disable per-user AI-assisted Admin text generation.', 'admin_account', [], ''],
        ['openai_api_key', 'advanced', 'OpenAI API key', 'Configure the per-user secret used by Admin text assistance.', 'admin_account', [], ''],
        ['openai_model', 'advanced', 'OpenAI model', 'Select the model used by Admin text assistance.', 'admin_account', [], ''],
    ];

    $entries = [];
    foreach ($definitions as [$id, $group, $label, $description, $route, $params, $fragment]) {
        $entries[$id] = admin_settings_entry($id, $group, $label, $description, '', 'specialized', '', t('admin.settings.status.specialized_only', 'Specialized page only'), $route, $params, $fragment, false, str_contains($id, 'password') || str_contains($id, 'token') || str_contains($id, 'api_key') ? 'secret' : 'normal');
        $entries[$id]['discovery_only'] = true;
    }
    if (feature_capability_effective_enabled('cooperative_galleries')) {
        $entries['cooperative_friendships'] = admin_settings_entry(
            'cooperative_friendships', 'advanced',
            t('admin.cooperative.ui.title', 'Friendly galleries'),
            t('admin.cooperative.ui.intro', 'Connect two installations by invitation. Friendship alone does not share any albums or photographs.'),
            '', 'specialized', '', t('admin.settings.status.specialized_only', 'Specialized page only'),
            'admin_cooperative_galleries', [], '', false
        );
        $entries['cooperative_friendships']['specialized_panel_workflow'] = 'cooperative_pairing';
        $entries['cooperative_friendships']['specialized_panel_url'] = admin_settings_specialized_url('admin_cooperative_galleries', ['panel' => '1']);
        $entries['cooperative_collaborations'] = admin_settings_entry(
            'cooperative_collaborations', 'advanced',
            t('admin.cooperative.review.title', 'Album collaborations'),
            t('admin.cooperative.review.intro', 'Review the exact participants and sharing permissions before approving. Each installation decides independently. Shared photographs and email invitations require explicit participant approval.'),
            '', 'specialized', '', t('admin.settings.status.specialized_only', 'Specialized page only'),
            'admin_cooperative_collaborations', [], '', false
        );
        $entries['cooperative_collaborations']['specialized_panel_workflow'] = 'cooperative_proposals';
        $entries['cooperative_collaborations']['specialized_panel_url'] = admin_settings_specialized_url('admin_cooperative_collaborations', ['panel' => '1']);
    }
    if (function_exists('Gallery\\Services\\feature_flag_definitions')) {
        foreach (feature_flag_definitions() as $featureKey => $definition) {
            $id = 'feature_' . feature_flag_normalize_key((string) $featureKey);
            $entries[$id] = admin_settings_entry(
                $id,
                'advanced',
                (string) ($definition['label'] ?? $featureKey),
                (string) ($definition['description'] ?? 'Optional application feature visibility.'),
                feature_flag_setting_key((string) $featureKey),
                'summary',
                '1',
                feature_capability_configured_enabled((string) $featureKey) ? '1' : '0',
                'admin_features'
            );
            $entries[$id]['discovery_only'] = true;
        }
    }
    return $entries;
}

/**
 * Normalize one Settings section identifier.
 *
 * @param mixed $section Submitted section value.
 * @return string Stable known section identifier.
 */
function admin_settings_section_normalize(mixed $section): string
{
    $candidate = strtolower(trim((string) $section));
    return array_key_exists($candidate, admin_settings_sections()) ? $candidate : 'general';
}

/**
 * Return the stable DOM id for one central Settings section.
 *
 * @param string $section Section identifier.
 * @return string Stable DOM id.
 */
function admin_settings_section_id(string $section): string
{
    return 'settings-' . admin_settings_section_normalize($section);
}

/**
 * Build a central Settings URL with stable section query and fragment values.
 *
 * @param ?string $section Optional section identifier.
 * @param ?string $return Optional return-context token.
 * @param bool $includeFragment Whether a section deep-link fragment should be appended.
 * @return string Admin URL.
 */
function admin_settings_url(?string $section = null, ?string $return = null, bool $includeFragment = false): string
{
    $params = [];
    $fragment = '';
    if ($section !== null && trim($section) !== '') {
        $section = admin_settings_section_normalize($section);
        $params['section'] = $section;
        $fragment = $includeFragment ? '#' . admin_settings_section_id($section) : '';
    }
    if ($return !== null && trim($return) !== '') {
        $params['return'] = preg_replace('/[^a-z0-9_.:-]/i', '', $return) ?: '';
    }
    return url_for('admin_settings', $params) . $fragment;
}

/**
 * Build one specialized Admin route with optional query parameters and hash.
 *
 * @param string $route Route name.
 * @param array<string,string> $params Query parameters.
 * @param string $fragment Optional fragment without leading hash.
 * @return string Admin URL.
 */
function admin_settings_specialized_url(string $route, array $params = [], string $fragment = ''): string
{
    $url = url_for($route, $params);
    return $fragment !== '' ? $url . '#' . ltrim($fragment, '#') : $url;
}

/**
 * Return whether one app_settings key has an explicit persisted value.
 *
 * @param string $key Canonical setting key.
 * @return bool True when an explicit value exists.
 */
function admin_settings_has_explicit_value(string $key): bool
{
    return app_setting($key, null) !== null;
}

/**
 * Return a safe yes/no value for Admin summaries.
 *
 * @param bool $value Boolean value.
 * @return string Translated readable value.
 */
function admin_settings_bool_label(bool $value): string
{
    return $value ? t('admin.common.enabled', 'Enabled') : t('admin.common.disabled', 'Disabled');
}

/**
 * Return a readable summary of one sensitive setting without exposing its value.
 *
 * @param string $key Canonical setting key.
 * @return string Redacted configuration status.
 */
function admin_settings_sensitive_status(string $key): string
{
    $configured = trim((string) app_setting($key, '')) !== '';
    return $configured
        ? t('admin.settings.status.configured', 'Configured')
        : t('admin.settings.status.not_configured', 'Not configured');
}

/**
 * Return the centralized setting ownership registry.
 *
 * Registry entries intentionally describe both centrally editable and summary-only
 * settings. Complex Theme, Account, credential, maintenance, and per-gallery flows
 * stay specialized so the hub cannot become a competing source of truth.
 *
 * @param bool $includeSessionSettings Include the Settings-only Admin/browser language control.
 * @return array<string,array<string,mixed>> Setting entries keyed by stable identifier.
 */
function admin_settings_registry(bool $includeSessionSettings = false): array
{
    $pagination = pagination_global_settings();
    $homeGrid = function_exists('Gallery\\Services\\main_page_gallery_grid_settings') ? main_page_gallery_grid_settings() : $pagination;
    $tagGrid = tag_page_gallery_grid_settings();
    $browserDefinitions = admin_settings_browser_upload_definitions();
    $theme = theme_settings();

    $registry = [
        'base_url' => admin_settings_entry('base_url', 'site', 'Website URL', 'Full public address, for example https://example.com. Include a path only when the gallery is actually installed there. Saves directly to config.php; no database setting is changed.', 'base_url', 'text', '', (string) (cms_config()['base_url'] ?? ''), '', [], '', true, 'normal', ['max_length' => 2048]),
        'site_name' => admin_settings_entry('site_name', 'general', 'Site name', 'Public site title used in the header and browser title.', 'site_name', 'text', 'Gallery CMS', site_name(), 'admin_theme', [], 'admin-theme-tab-appearance', true, 'normal', ['max_length' => 120]),
        'public_language' => admin_settings_entry('public_language', 'general', 'Public language', 'Default language for anonymous visitors.', 'public_language', 'select', 'en', translation_public_language(), 'admin_theme', [], 'admin-theme-tab-language', true, 'normal', ['allowed' => function_exists('Gallery\\Services\\translation_supported_languages') ? translation_supported_languages() : ['en', 'cs', 'de', 'sv']]),
        'public_language_selector_enabled' => admin_settings_entry('public_language_selector_enabled', 'general', 'Viewer language selector', 'Allow each public viewer to choose a language stored only in that viewer\'s browser; this never changes the site default or another viewer.', 'public_language_selector_enabled', 'checkbox', '1', translation_public_language_selector_enabled() ? '1' : '0', 'admin_theme', [], 'admin-theme-tab-language', true),
        'public_language_selector_languages' => admin_settings_entry('public_language_selector_languages', 'general', 'Viewer languages', 'Languages offered for each public viewer\'s browser-only preference; Admin and site-wide language settings are unchanged.', 'public_language_selector_languages', 'language-multicheckbox', CMS_SELECTABLE_LANGUAGES, translation_public_language_selector_languages(), 'admin_theme', [], 'admin-theme-tab-language', true, 'normal', ['allowed' => translation_supported_languages()]),
        'public_language_selector_design' => admin_settings_entry('public_language_selector_design', 'general', 'Viewer selector design', 'Choose five live-preview presets; configure flags, names, codes, colors, padding, margins, borders, sizing, layout, and reset controls.', CMS_PUBLIC_LANGUAGE_SELECTOR_DESIGN_KEY, 'language-selector-design', translation_public_language_selector_design_defaults(), translation_public_language_selector_design(), 'admin_theme', [], 'admin-theme-tab-language', true),
        'url_rewrite_enabled' => admin_settings_entry('url_rewrite_enabled', 'general', 'Clean public URLs', 'Controls whether generated public links prefer URL rewriting.', 'url_rewrite_enabled', 'checkbox', '1', url_rewrite_enabled() ? '1' : '0', 'admin', [], 'admin-tab-maintenance', true),
        'public_home_search_enabled' => admin_settings_entry('public_home_search_enabled', 'general', 'Public search', 'Shows the public search interface when the feature is enabled.', 'public_home_search_enabled', 'checkbox', '1', public_home_search_enabled() ? '1' : '0', 'admin', [], 'admin-tab-maintenance', (!function_exists('Gallery\\Services\\feature_capability_effective_enabled') || feature_capability_effective_enabled('public_search'))),

        'theme_page_width' => admin_settings_entry('theme_page_width', 'appearance', 'Page width', 'Global public page width mode.', 'theme_page_width', 'summary', 'default', (string) ($theme['page_width'] ?? 'default'), 'admin_theme', [], 'admin-theme-tab-appearance'),
        'theme_gallery_description_layout' => admin_settings_entry('theme_gallery_description_layout', 'appearance', 'Gallery card layout', 'Global gallery-card description layout used unless a more specific override applies.', 'theme_gallery_description_layout', 'summary', 'vertical', theme_gallery_description_layout(), 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-gallery-tags'], 'admin-theme-tab-appearance'),
        'pagination_enabled' => admin_settings_entry('pagination_enabled', 'appearance', 'Pagination', 'Global public list pagination switch.', 'pagination_enabled', 'summary', '0', !empty($pagination['enabled']) ? '1' : '0', 'admin_theme', [], 'admin-theme-tab-layout'),
        'pagination_columns' => admin_settings_entry('pagination_columns', 'appearance', 'Global grid columns', 'Default number of columns used by paginated public lists.', 'pagination_columns', 'number', defined('Gallery\\Services\\CMS_PAGINATION_DEFAULT_COLUMNS') ? (string) CMS_PAGINATION_DEFAULT_COLUMNS : '4', (string) ($pagination['columns'] ?? 4), 'admin_theme', [], 'admin-theme-tab-layout', false, 'normal', ['min' => 1, 'max' => defined('Gallery\\Services\\CMS_PAGINATION_MAX_COLUMNS') ? CMS_PAGINATION_MAX_COLUMNS : 12]),
        'pagination_rows' => admin_settings_entry('pagination_rows', 'appearance', 'Global grid rows', 'Default number of rows per paginated public list.', 'pagination_rows', 'number', defined('Gallery\\Services\\CMS_PAGINATION_DEFAULT_ROWS') ? (string) CMS_PAGINATION_DEFAULT_ROWS : '5', (string) ($pagination['rows'] ?? 5), 'admin_theme', [], 'admin-theme-tab-layout', false, 'normal', ['min' => 1, 'max' => defined('Gallery\\Services\\CMS_PAGINATION_MAX_ROWS') ? CMS_PAGINATION_MAX_ROWS : 50]),
        'home_gallery_grid_columns' => admin_settings_entry('home_gallery_grid_columns', 'appearance', 'Home gallery columns', 'Dedicated home-page gallery grid, inheriting global dimensions when unset.', 'home_gallery_grid_columns', 'summary', (string) ($pagination['columns'] ?? 4), (string) ($homeGrid['columns'] ?? 4), 'admin_theme', [], 'admin-theme-tab-layout'),
        'home_gallery_grid_rows' => admin_settings_entry('home_gallery_grid_rows', 'appearance', 'Home gallery rows', 'Dedicated home-page row count, inheriting global dimensions when unset.', 'home_gallery_grid_rows', 'summary', (string) ($pagination['rows'] ?? 5), (string) ($homeGrid['rows'] ?? 5), 'admin_theme', [], 'admin-theme-tab-layout'),

        'tag_page_gallery_grid_columns' => admin_settings_entry('tag_page_gallery_grid_columns', 'content', 'Tag-page columns', 'Dedicated public tag-page grid columns. Missing values inherit the global grid.', 'tag_page_gallery_grid_columns', 'summary', (string) ($pagination['columns'] ?? 4), (string) ($tagGrid['columns'] ?? 4), 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-gallery-tags'], 'admin-theme-tab-appearance'),
        'tag_page_gallery_grid_rows' => admin_settings_entry('tag_page_gallery_grid_rows', 'content', 'Tag-page rows', 'Dedicated public tag-page row count. Missing values inherit the global grid.', 'tag_page_gallery_grid_rows', 'summary', (string) ($pagination['rows'] ?? 5), (string) ($tagGrid['rows'] ?? 5), 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-gallery-tags'], 'admin-theme-tab-appearance'),
        'tag_page_gallery_description_layout' => admin_settings_entry('tag_page_gallery_description_layout', 'content', 'Tag-page card layout', 'Card layout used only for public tag landing-page results.', 'tag_page_gallery_description_layout', 'summary', theme_gallery_description_layout(), tag_page_gallery_description_layout(), 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-gallery-tags'], 'admin-theme-tab-appearance'),
        'theme_hero_tag_visible_limit' => admin_settings_entry('theme_hero_tag_visible_limit', 'content', 'Hero tag visible limit', 'Number of hero tags shown before expansion controls.', 'theme_hero_tag_visible_limit', 'summary', '20', (string) theme_hero_tag_visible_limit(), 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-gallery-tags'], 'admin-theme-tab-appearance'),
        'theme_hero_tag_sort_mode' => admin_settings_entry('theme_hero_tag_sort_mode', 'content', 'Hero tag ordering', 'Sort order used for tags shown in gallery hero areas.', 'theme_hero_tag_sort_mode', 'summary', 'usage', theme_hero_tag_sort_mode(), 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-gallery-tags'], 'admin-theme-tab-appearance'),
        'theme_gallery_info_motion_ms' => admin_settings_entry('theme_gallery_info_motion_ms', 'appearance', 'Gallery tag panel animation', 'Duration for opening and closing the public gallery information panel, in milliseconds.', 'theme_gallery_info_motion_ms', 'summary', '320', (string) theme_gallery_info_motion_ms(), 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-animations'], 'admin-theme-tab-appearance'),
        'theme_admin_side_panel_motion_ms' => admin_settings_entry('theme_admin_side_panel_motion_ms', 'appearance', 'Admin side panel animation', 'Duration for opening and closing the Admin side panel, in milliseconds.', 'theme_admin_side_panel_motion_ms', 'summary', '260', (string) theme_admin_side_panel_motion_ms(), 'admin_theme', ['appearance_subtab' => 'admin-theme-appearance-subtab-animations'], 'admin-theme-tab-appearance'),
        'tag_metadata' => admin_settings_entry('tag_metadata', 'content', 'Tag metadata', 'Rename tags, manage slugs, and edit public tag landing-page text on the dedicated Tags page.', '', 'specialized', '', t('admin.settings.status.specialized_only', 'Specialized page only'), 'admin_tags'),

        'public_thumbnail_rendering_mode' => admin_settings_entry('public_thumbnail_rendering_mode', 'media', 'Public thumbnail renderer', 'Permanent rendering pipeline for selected-gallery photo cards.', PUBLIC_THUMBNAIL_RENDERING_SETTING_KEY, 'select', PUBLIC_THUMBNAIL_RENDERING_DEFAULT, public_thumbnail_rendering_mode(), 'admin_theme', [], 'admin-theme-tab-layout', true, 'normal', ['allowed' => public_thumbnail_rendering_modes()]),
        'theme_lightbox_browsing_mode' => admin_settings_entry('theme_lightbox_browsing_mode', 'media', 'Lightbox default mode', 'Global fallback for galleries without a lightbox browsing override.', 'theme_lightbox_browsing_mode', 'summary', 'single', theme_lightbox_browsing_mode(), 'admin_theme', [], 'admin-theme-tab-layout'),
        'exif_gps_maps_default_enabled' => admin_settings_entry('exif_gps_maps_default_enabled', 'media', 'EXIF / GPS public display', 'Global fallback used by galleries without an explicit GPS display override.', exif_gps_default_enabled_setting_key(), 'checkbox', '1', exif_gps_default_enabled() ? '1' : '0', 'admin', [], 'admin-tab-maintenance', exif_gps_override_schema_ready(), 'normal', [], !exif_gps_override_schema_ready()),
        'thumbnail_background_warmup_enabled' => admin_settings_entry('thumbnail_background_warmup_enabled', 'media', 'Public thumbnail self-healing', 'Allows guarded public requests to request background repair of missing thumbnails. Existing installations retain the historical enabled fallback.', 'thumbnail_background_warmup_enabled', 'summary', '1', function_exists('Gallery\Services\thumbnail_warmup_enabled') && thumbnail_warmup_enabled() ? '1' : '0', 'admin', ['maintenance_tab' => 'media'], 'admin-tab-maintenance', false, 'operational'),

        'admin_legacy_upload_navigation_enabled' => admin_settings_entry('admin_legacy_upload_navigation_enabled', 'uploads', 'Show legacy uploads', 'Show Upload photos, Upload settings, and Mobile uploads in the Admin menu. Hiding these links preserves existing routes and upload services.', 'admin_legacy_upload_navigation_enabled', 'checkbox', '0', admin_legacy_upload_navigation_enabled() ? '1' : '0', 'admin_upload_settings', [], '', true, 'normal', []),
        'admin_upload_client_format_mode' => admin_settings_entry('admin_upload_client_format_mode', 'uploads', 'Upload source format policy', 'Choose which image formats the upload picker accepts.', 'admin_upload_client_format_mode', 'select', 'server_supported', admin_upload_client_format_mode(), 'admin_upload_settings', [], '', true, 'normal', ['allowed' => ['server_supported', 'phone_jpeg']]),
        'admin_upload_auto_rename_enabled' => admin_settings_entry('admin_upload_auto_rename_enabled', 'uploads', 'Automatic upload rename', 'Automatically rename newly imported uploads.', 'admin_upload_auto_rename_enabled', 'checkbox', '1', admin_upload_auto_rename_enabled() ? '1' : '0', 'admin_upload_settings', [], '', true, 'normal', []),
        'browser_upload_enabled' => admin_settings_entry('browser_upload_enabled', 'uploads', 'Browser-assisted uploads', 'Allow browser-side preparation and bounded ZIP batches.', 'browser_upload_enabled', 'checkbox', $browserDefinitions['browser_upload_enabled']['default'], $browserDefinitions['browser_upload_enabled']['current'], 'admin_upload_settings', [], '', true, 'normal', $browserDefinitions['browser_upload_enabled']['validation']),
        'browser_upload_default_worker_count' => admin_settings_entry('browser_upload_default_worker_count', 'uploads', 'Default browser upload workers', 'Default preparation worker count.', 'browser_upload_default_worker_count', 'number', $browserDefinitions['browser_upload_default_worker_count']['default'], $browserDefinitions['browser_upload_default_worker_count']['current'], 'admin_upload_settings', [], '', true, 'normal', $browserDefinitions['browser_upload_default_worker_count']['validation']),
        'browser_upload_max_worker_count' => admin_settings_entry('browser_upload_max_worker_count', 'uploads', 'Maximum browser upload workers', 'Upper bound for preparation workers.', 'browser_upload_max_worker_count', 'number', $browserDefinitions['browser_upload_max_worker_count']['default'], $browserDefinitions['browser_upload_max_worker_count']['current'], 'admin_upload_settings', [], '', true, 'normal', $browserDefinitions['browser_upload_max_worker_count']['validation']),
        'browser_upload_hard_worker_cap' => admin_settings_entry('browser_upload_hard_worker_cap', 'uploads', 'Browser upload worker hard cap', 'Absolute worker safety cap.', 'browser_upload_hard_worker_cap', 'number', $browserDefinitions['browser_upload_hard_worker_cap']['default'], $browserDefinitions['browser_upload_hard_worker_cap']['current'], 'admin_upload_settings', [], '', true, 'normal', $browserDefinitions['browser_upload_hard_worker_cap']['validation']),
        'browser_upload_batch_size_policy' => admin_settings_entry('browser_upload_batch_size_policy', 'uploads', 'Browser upload batch policy', 'Bound prepared ZIP batches against server upload limits.', 'browser_upload_batch_size_policy', 'select', $browserDefinitions['browser_upload_batch_size_policy']['default'], $browserDefinitions['browser_upload_batch_size_policy']['current'], 'admin_upload_settings', [], '', true, 'normal', $browserDefinitions['browser_upload_batch_size_policy']['validation']),
        'browser_upload_zip_size_threshold_ratio' => admin_settings_entry('browser_upload_zip_size_threshold_ratio', 'uploads', 'Browser ZIP threshold ratio', 'Fraction of the PHP upload limit used by a ZIP batch.', 'browser_upload_zip_size_threshold_ratio', 'number', $browserDefinitions['browser_upload_zip_size_threshold_ratio']['default'], $browserDefinitions['browser_upload_zip_size_threshold_ratio']['current'], 'admin_upload_settings', [], '', true, 'normal', $browserDefinitions['browser_upload_zip_size_threshold_ratio']['validation']),
        'browser_upload_max_items_per_batch' => admin_settings_entry('browser_upload_max_items_per_batch', 'uploads', 'Images per browser batch', 'Maximum prepared images in a batch.', 'browser_upload_max_items_per_batch', 'number', $browserDefinitions['browser_upload_max_items_per_batch']['default'], $browserDefinitions['browser_upload_max_items_per_batch']['current'], 'admin_upload_settings', [], '', true, 'normal', $browserDefinitions['browser_upload_max_items_per_batch']['validation']),
        'browser_upload_max_zip_batch_bytes' => admin_settings_entry('browser_upload_max_zip_batch_bytes', 'uploads', 'Preferred browser ZIP batch target (MB)', 'Soft ZIP size target; an atomic image package may exceed it up to the PHP upload limit.', 'browser_upload_max_zip_batch_bytes', 'number', $browserDefinitions['browser_upload_max_zip_batch_bytes']['default'], $browserDefinitions['browser_upload_max_zip_batch_bytes']['current'], 'admin_upload_settings', [], '', true, 'normal', $browserDefinitions['browser_upload_max_zip_batch_bytes']['validation']),
        'browser_thumbnail_rebuild_source_chunk_bytes' => admin_settings_entry('browser_thumbnail_rebuild_source_chunk_bytes', 'uploads', 'Thumbnail rebuild source chunk (MB)', 'Size of original-file ZIP chunks for browser thumbnail rebuilding.', 'browser_thumbnail_rebuild_source_chunk_bytes', 'number', $browserDefinitions['browser_thumbnail_rebuild_source_chunk_bytes']['default'], $browserDefinitions['browser_thumbnail_rebuild_source_chunk_bytes']['current'], 'admin_upload_settings', [], '', true, 'normal', $browserDefinitions['browser_thumbnail_rebuild_source_chunk_bytes']['validation']),

        'telemetry_enabled' => admin_settings_entry('telemetry_enabled', 'privacy', 'Telemetry subsystem', 'Master switch for local anonymous telemetry.', 'telemetry_enabled', 'summary', '0', telemetry_setting_enabled('telemetry_enabled', '0') ? '1' : '0', 'admin_telemetry', [], '', false, 'privacy', [], !telemetry_settings_schema_ready()),
        'telemetry_public_usage_enabled' => admin_settings_entry('telemetry_public_usage_enabled', 'privacy', 'Public usage telemetry', 'Anonymous public usage collection preference.', 'telemetry_public_usage_enabled', 'summary', '0', telemetry_setting_enabled('telemetry_public_usage_enabled', '0') ? '1' : '0', 'admin_telemetry', [], '', false, 'privacy', [], !telemetry_settings_schema_ready()),
        'seo_request_guard_enabled' => admin_settings_entry('seo_request_guard_enabled', 'privacy', 'SEO request guard', 'Rejects known junk or exploit-oriented public query patterns.', 'seo_request_guard_enabled', 'summary', '1', seo_request_guard_enabled() ? '1' : '0', 'admin', [], 'admin-tab-maintenance', false, 'security'),
        'seo_request_guard_logging_enabled' => admin_settings_entry('seo_request_guard_logging_enabled', 'privacy', 'SEO guard logging', 'Controls operational logging for rejected SEO-guard requests.', 'seo_request_guard_logging_enabled', 'summary', '1', seo_request_guard_logging_enabled() ? '1' : '0', 'admin', [], 'admin-tab-maintenance', false, 'security'),
        'dev_mode_enabled' => admin_settings_entry('dev_mode_enabled', 'privacy', 'Development diagnostics', 'Admin-only browser diagnostics and extra instrumentation.', 'dev_mode_enabled', 'checkbox', '0', dev_mode_enabled() ? '1' : '0', 'admin', [], 'admin-tab-maintenance', true, 'diagnostic'),
        'remote_favicon_discovery_enabled' => admin_settings_entry('remote_favicon_discovery_enabled', 'privacy', 'Remote favicon discovery', 'Allow bounded outbound favicon discovery for unknown links after Admin gallery saves. Built-in and cached local favicons remain available.', 'remote_favicon_discovery_enabled', 'checkbox', '1', app_setting('remote_favicon_discovery_enabled', '1') !== '0' ? '1' : '0', 'admin_features', [], '', true, 'network'),
        'gallery_trash_enabled' => admin_settings_entry('gallery_trash_enabled', 'privacy', 'Gallery Trash', 'Master switch for recoverable gallery deletion. Disabling it preserves existing Trash entries and subordinate purge settings.', 'gallery_trash_enabled', 'summary', '1', function_exists('Gallery\Services\gallery_trash_enabled') && gallery_trash_enabled() ? '1' : '0', 'admin_trash', [], '', false, 'operational'),
        'gallery_trash_auto_purge_enabled' => admin_settings_entry('gallery_trash_auto_purge_enabled', 'privacy', 'Gallery Trash automatic purge', 'Independent preference for automatic expiry cleanup. It remains stored while the Trash master is disabled.', 'gallery_trash_auto_purge_enabled', 'summary', '0', function_exists('Gallery\Services\gallery_trash_auto_purge_enabled') && gallery_trash_auto_purge_enabled() ? '1' : '0', 'admin_trash', [], '', false, 'destructive'),
        'gallery_trash_retention_days' => admin_settings_entry('gallery_trash_retention_days', 'privacy', 'Gallery Trash retention', 'Number of days retained when automatic purge is enabled.', 'gallery_trash_retention_days', 'summary', '30', function_exists('Gallery\Services\gallery_trash_retention_days') ? (string) gallery_trash_retention_days() : '30', 'admin_trash', [], '', false, 'destructive'),
        'gallery_trash_purge_batch' => admin_settings_entry('gallery_trash_purge_batch', 'privacy', 'Gallery Trash purge batch', 'Maximum number of expired Trash entries removed by one automatic purge batch.', 'gallery_trash_purge_batch', 'summary', '5', function_exists('Gallery\Services\gallery_trash_purge_batch_size') ? (string) gallery_trash_purge_batch_size() : '5', 'admin_trash', [], '', false, 'destructive'),
        'application_autoupdate_enabled' => admin_settings_entry('application_autoupdate_enabled', 'privacy', 'Automatic stable updates', 'Master preference for request-time automatic stable update checks and installation.', 'application_autoupdate_enabled', 'summary', '1', function_exists('Gallery\Services\application_autoupdate_enabled') && application_autoupdate_enabled() ? '1' : '0', 'admin_update', [], '', false, 'outbound-network'),
        'site_maintenance_enabled' => admin_settings_entry('site_maintenance_enabled', 'privacy', 'Scheduled site maintenance', 'Non-destructive schedule status for bounded background repair work. Execution and token controls remain specialized.', 'site_maintenance_enabled', 'summary', '1', function_exists('Gallery\\Services\\site_maintenance_enabled') && site_maintenance_enabled() ? '1' : '0', 'admin', [], 'admin-tab-maintenance', false, 'operational'),

        'password_reset_smtp_password' => admin_settings_entry('password_reset_smtp_password', 'advanced', 'SMTP password', 'Credential is managed only from Account settings.', 'password_reset_smtp_password', 'secret-status', '', admin_settings_sensitive_status('password_reset_smtp_password'), 'admin_account', [], '', false, 'secret'),
        'site_maintenance_token' => admin_settings_entry('site_maintenance_token', 'advanced', 'Maintenance cron token', 'Secret token is never displayed in the central Settings hub.', 'site_maintenance_token', 'secret-status', '', admin_settings_sensitive_status('site_maintenance_token'), 'admin', [], 'admin-tab-maintenance', false, 'secret'),
        'database_maintenance' => admin_settings_entry('database_maintenance', 'advanced', 'Database repair and migrations', 'Schema repair, cleanup, and migration actions remain specialized and potentially destructive.', '', 'specialized', '', t('admin.settings.status.specialized_only', 'Specialized page only'), 'admin_storage_statistics', ['tab' => 'maintenance'], '', false, 'destructive'),
        'custom_css' => admin_settings_entry('custom_css', 'advanced', 'Custom CSS', 'Raw stylesheet editing and uploads remain in the Theme editor.', 'custom_css_preset', 'specialized', '', (string) app_setting('custom_css_preset', '') !== '' ? t('admin.settings.status.configured', 'Configured') : t('admin.settings.status.not_configured', 'Not configured'), 'admin_theme', [], 'admin-theme-tab-custom-css', false, 'advanced'),
        'theme_branding_assets' => admin_settings_entry('theme_branding_assets', 'advanced', 'Branding assets', 'Uploaded header branding assets stay in the Theme media editor.', '', 'specialized', '', t('admin.settings.status.specialized_only', 'Specialized page only'), 'admin_theme', [], 'admin-theme-tab-media', false, 'advanced'),
        'language_pack_editor' => admin_settings_entry('language_pack_editor', 'advanced', 'Language pack editor', 'Raw language-pack editing, import, and export stay in the Theme language editor.', '', 'specialized', '', t('admin.settings.status.specialized_only', 'Specialized page only'), 'admin_theme', [], 'admin-theme-tab-language', false, 'advanced'),
        'upload_api_keys' => admin_settings_entry('upload_api_keys', 'advanced', 'Upload API keys', 'Gallery-scoped automation keys remain in the dedicated API manager.', '', 'specialized', '', t('admin.settings.status.specialized_only', 'Specialized page only'), 'admin_api_manager', [], '', false, 'secret'),
        'destructive_maintenance' => admin_settings_entry('destructive_maintenance', 'advanced', 'Destructive maintenance', 'Reset, cleanup, rebuild, and other destructive operations remain on their existing maintenance screens.', '', 'specialized', '', t('admin.settings.status.specialized_only', 'Specialized page only'), 'admin', [], 'admin-tab-maintenance', false, 'destructive'),
        'account_credentials' => admin_settings_entry('account_credentials', 'advanced', 'Account and credentials', 'Passwords, Google OAuth, OpenAI API keys, and credential-bearing settings remain on Account.', '', 'specialized', '', t('admin.settings.status.specialized_only', 'Specialized page only'), 'admin_account', [], '', false, 'secret'),
    ];

    $registry = array_replace($registry, admin_settings_specialized_catalog());
    if ($includeSessionSettings) {
        // This is the existing private Admin/browser preference, never a second site-wide setting.
        $registry['admin_language'] = admin_settings_entry('admin_language', 'general', 'Application language', 'Language of the administrator interface in this browser. Visitor language is configured separately.', '', 'select', translation_default_language(), translation_active_language(), 'admin_theme', [], 'admin-theme-tab-language', true, 'normal', ['allowed' => translation_supported_languages()]);
        $registry['admin_language']['explicit'] = true;
        $registry['admin_language']['source'] = 'session';
    }
    return $registry;
}

/**
 * Return the canonical owner for one registry entry.
 *
 * @param string $id Stable setting identifier.
 * @return string Service or controller ownership label.
 */
function admin_settings_owner_for_id(string $id): string
{
    if ($id === 'admin_legacy_upload_navigation_enabled') {
        return 'admin_settings_registry';
    }
    if ($id === 'base_url') {
        return 'config.php / site_url';
    }
    if (in_array($id, ['site_name', 'url_rewrite_enabled', 'dev_mode_enabled', 'remote_favicon_discovery_enabled'], true)) {
        return 'app_settings';
    }
    if (in_array($id, ['admin_language', 'public_language'], true) || str_starts_with($id, 'public_language_selector_')) {
        return 'translations';
    }
    if ($id === 'public_home_search_enabled') {
        return 'public_search';
    }
    if (str_starts_with($id, 'theme_') || str_starts_with($id, 'tag_page_') || str_starts_with($id, 'pagination_') || str_starts_with($id, 'home_gallery_grid_')) {
        return 'theme / pagination';
    }
    if ($id === 'public_thumbnail_rendering_mode') {
        return 'public_thumbnail_rendering';
    }
    if ($id === 'exif_gps_maps_default_enabled') {
        return 'exif';
    }
    if (str_starts_with($id, 'browser_upload_') || str_starts_with($id, 'browser_thumbnail_') || str_starts_with($id, 'admin_upload_')) {
        return 'browser_uploads / admin_uploads';
    }
    if (str_starts_with($id, 'telemetry_')) {
        return 'telemetry_settings';
    }
    if (str_starts_with($id, 'seo_request_guard_')) {
        return 'seo_request_guard';
    }
    if (in_array($id, ['password_reset_smtp_password', 'account_credentials'], true)) {
        return 'admin_auth';
    }
    if ($id === 'site_maintenance_token') {
        return 'site_maintenance';
    }
    return 'specialized controller';
}

/**
 * Build one normalized registry entry.
 * @param string $id Stable setting identifier.
 * @param string $group Settings section identifier.
 * @param string $label Human-readable fallback label.
 * @param string $description Human-readable fallback explanation.
 * @param string $key Canonical storage key.
 * @param string $inputType Presentation control type.
 * @param string|bool|int|array<string,mixed>|list<string>|null $default Scalar or structured default owned by the setting service.
 * @param string|bool|int|array<string,mixed>|list<string>|null $current Scalar or structured resolved setting value.
 * @param string $specializedRoute Dedicated controller route or empty.
 * @param array<string,mixed> $specializedParams Dedicated route parameters.
 * @param string $specializedFragment Dedicated page anchor.
 * @param bool $centralEditable Whether the hub may persist this setting.
 * @param string $sensitivity Presentation sensitivity classification.
 * @param array<string,mixed> $validation Input bounds and allowed values.
 * @param bool $migrationRequired Whether a schema upgrade is needed.
 *
 * @return array<string,mixed> Registry entry.
 */
function admin_settings_entry(
    string $id,
    string $group,
    string $label,
    string $description,
    string $key,
    string $inputType,
    mixed $default,
    mixed $current,
    string $specializedRoute,
    array $specializedParams = [],
    string $specializedFragment = '',
    bool $centralEditable = false,
    string $sensitivity = 'normal',
    array $validation = [],
    bool $migrationRequired = false
): array {
    $explicit = $id === 'base_url' ? true : ($key !== '' ? admin_settings_has_explicit_value($key) : false);
    $inheritable = str_starts_with($key, 'tag_page_') || str_starts_with($key, 'home_gallery_grid_');
    return [
        'id' => $id,
        'group' => admin_settings_section_normalize($group),
        'label' => $label,
        'label_key' => 'admin.settings.item.' . $id,
        'description' => $description,
        'description_key' => 'admin.settings.item.' . $id . '.hint',
        'key' => $key,
        'input_type' => $inputType,
        'default_resolver' => static fn (): mixed => $default,
        'current_resolver' => static fn (): mixed => $current,
        'default' => $default,
        'current' => $current,
        'explicit' => $explicit,
        'source' => $explicit ? 'configured' : ($inheritable ? 'inherited' : 'default'),
        'fallback_behavior' => $inheritable ? 'inherits_global' : 'default_resolver',
        'owner' => admin_settings_owner_for_id($id),
        'central_editable' => $centralEditable,
        'sensitivity' => $sensitivity,
        'validation' => $validation,
        'specialized_route' => $specializedRoute,
        'specialized_params' => $specializedParams,
        'specialized_fragment' => $specializedFragment,
        'specialized_url' => $specializedRoute !== '' ? admin_settings_specialized_url($specializedRoute, $specializedParams, $specializedFragment) : '',
        'migration_required' => $migrationRequired,
        'normalization_callback' => $centralEditable ? 'Gallery\\Services\\admin_settings_normalize_editable_value' : null,
        'save_callback' => $centralEditable ? 'Gallery\\Services\\admin_settings_save_editable_value' : null,
        'content_revision' => $id === 'public_thumbnail_rendering_mode' || in_array($key, ['theme_lightbox_browsing_mode', 'theme_gallery_description_layout', 'theme_hero_tag_visible_limit', 'theme_hero_tag_sort_mode'], true),
    ];
}

/**
 * Return all registry entries belonging to one section.
 *
 * @param string $section Section identifier.
 * @return array<string,array<string,mixed>> Entries keyed by stable id.
 */
function admin_settings_registry_for_section(string $section): array
{
    $section = admin_settings_section_normalize($section);
    return array_filter(admin_settings_registry(), static fn (array $entry): bool => ($entry['group'] ?? '') === $section);
}

/**
 * Normalize one centrally editable submitted value.
 *
 * @param array<string,mixed> $entry Registry entry.
 * @param string|bool|int|array<string,mixed>|list<string>|null $value Submitted value.
 * @return string|bool|int|array<string,mixed>|list<string>|null Normalized scalar or structured setting payload owned by its canonical service.
 */
function admin_settings_normalize_editable_value(array $entry, mixed $value): mixed
{
    if (empty($entry['central_editable'])) {
        throw new InvalidArgumentException('Setting is not centrally editable.');
    }

    $id = (string) ($entry['id'] ?? '');
    if (isset(admin_settings_browser_upload_definitions()[$id])) {
        return admin_settings_browser_upload_normalize_value($id, $value);
    }
    if ($id === 'admin_upload_client_format_mode') {
        return admin_upload_client_format_mode_validate($value);
    }
    if (in_array($id, ['admin_upload_auto_rename_enabled', 'admin_legacy_upload_navigation_enabled'], true)) {
        return admin_upload_auto_rename_setting_normalize($value === '' ? '0' : $value);
    }
    if ($id === 'base_url') {
        return site_url_normalize($value);
    }
    if ($id === 'site_name') {
        $normalized = trim((string) $value);
        return $normalized !== '' ? substr($normalized, 0, 120) : 'Gallery CMS';
    }
    if (in_array($id, ['admin_language', 'public_language'], true)) {
        $normalized = translation_normalize_language_code((string) $value);
        if ($normalized === '' || !translation_language_allowed($normalized)) {
            throw new InvalidArgumentException(t('admin.settings.error.invalid_language', 'Choose a supported public language.'));
        }
        return $normalized;
    }
    if ($id === 'public_language_selector_enabled') {
        return !empty($value) ? '1' : '0';
    }
    if ($id === 'public_language_selector_languages') {
        $normalized = translation_public_language_selector_normalize_languages($value);
        if ($normalized === []) {
            throw new InvalidArgumentException(t('admin.theme.language.viewer_languages_required', 'Enable at least one viewer language.'));
        }
        return $normalized;
    }
    if ($id === 'public_language_selector_design') {
        if (is_array($value) && !empty($value['basic_only'])) {
            $current = translation_public_language_selector_design();
            if (array_key_exists('preset', $value)) {
                $current['preset'] = $value['preset'];
            }
            // A submitted basic editor omits an unchecked checkbox; preserve all detailed design fields.
            $current['show_flags'] = !empty($value['show_flags']);
            return translation_public_language_selector_design_normalize($current);
        }
        return translation_public_language_selector_design_normalize($value);
    }
    if ($id === 'public_thumbnail_rendering_mode') {
        return public_thumbnail_rendering_mode_normalize($value);
    }
    if (in_array($id, ['url_rewrite_enabled', 'public_home_search_enabled', 'exif_gps_maps_default_enabled', 'dev_mode_enabled', 'remote_favicon_discovery_enabled'], true)) {
        return !empty($value) ? '1' : '0';
    }

    throw new InvalidArgumentException('Unknown central setting.');
}

/**
 * Persist one centrally editable value through the canonical service boundary.
 *
 * @param string $id Stable registry identifier.
 * @param string|bool|int|array<string,mixed>|list<string>|null $value Normalized scalar or structured setting payload owned by its canonical service.
 * @return void
 */
function admin_settings_save_editable_value(string $id, mixed $value): void
{
    if ($id === 'public_home_search_enabled' && function_exists('Gallery\\Services\\feature_capability_effective_enabled') && !feature_capability_effective_enabled('public_search')) {
        throw new InvalidArgumentException('Public search is not available.');
    }
    if ($id === 'exif_gps_maps_default_enabled' && !exif_gps_override_schema_ready()) {
        throw new InvalidArgumentException('EXIF/GPS defaults require the current schema.');
    }

    match ($id) {
        'admin_legacy_upload_navigation_enabled' => set_app_setting('admin_legacy_upload_navigation_enabled', (string) $value === '1' ? '1' : '0'),
        'admin_upload_client_format_mode' => save_admin_upload_client_format_mode($value),
        'admin_upload_auto_rename_enabled' => save_admin_upload_auto_rename_setting($value),
        'base_url' => site_url_save((string) $value),
        'site_name' => set_site_name((string) $value),
        'public_language' => translation_set_public_language((string) $value),
        'admin_language' => translation_set_active_language((string) $value),
        'public_language_selector_enabled' => translation_save_public_language_selector_settings((string) $value === '1', translation_public_language_selector_languages()),
        'public_language_selector_languages' => translation_save_public_language_selector_settings(translation_public_language_selector_enabled(), is_array($value) ? $value : []),
        'public_language_selector_design' => translation_save_public_language_selector_design($value),
        'url_rewrite_enabled' => set_url_rewrite_enabled((string) $value === '1'),
        'public_home_search_enabled' => set_public_home_search_enabled((string) $value === '1'),
        'public_thumbnail_rendering_mode' => public_thumbnail_rendering_mode_save_with_revision((string) $value),
        'exif_gps_maps_default_enabled' => set_exif_gps_default_enabled((string) $value === '1'),
        'dev_mode_enabled' => set_dev_mode_enabled((string) $value === '1'),
        'remote_favicon_discovery_enabled' => set_app_setting('remote_favicon_discovery_enabled', (string) $value === '1' ? '1' : '0'),
        default => throw new InvalidArgumentException('Unknown or non-editable central setting.'),
    };
}
