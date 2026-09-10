<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/feature_policy_stage13_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the cross-feature health, route, dependency, and query-budget contracts.
 *
 * Responsibilities:
 *   - Verify the bounded Admin capability health snapshot without schema fan-out
 *   - Exercise OFF and ON route semantics for every capability with explicit owned routes
 *   - Exercise dependency blocking without rewriting the dependent configured preference
 *   - Keep Admin navigation feature metadata registry-valid and capability-filtered
 *   - Protect early exits for expensive optional work when the owning capability is disabled
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Services {
    /** Return a deterministic translated fallback for this isolated policy test. */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        $text = is_string($fallback) ? $fallback : $key;
        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** Return one isolated app setting without touching a database. */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $GLOBALS['feature_policy_stage13_settings'] ?? [])
            ? (string) $GLOBALS['feature_policy_stage13_settings'][$key]
            : $default;
    }

    /** Persist one isolated app setting without touching subsystem data. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['feature_policy_stage13_settings'][$key] = $value;
    }

    /** Return the isolated thumbnail-warmup domain setting. */
    function thumbnail_warmup_enabled(): bool
    {
        return app_setting('thumbnail_background_warmup_enabled', '1') !== '0';
    }

    /** Persist the isolated thumbnail-warmup domain setting. */
    function set_thumbnail_warmup_enabled(bool $enabled): void
    {
        set_app_setting('thumbnail_background_warmup_enabled', $enabled ? '1' : '0');
    }

    /** Return the isolated Development Diagnostics domain setting. */
    function dev_mode_enabled(): bool
    {
        return app_setting('dev_mode_enabled', '0') !== '0';
    }

    /** Persist the isolated Development Diagnostics domain setting. */
    function set_dev_mode_enabled(bool $enabled): void
    {
        set_app_setting('dev_mode_enabled', $enabled ? '1' : '0');
    }
}

namespace {
    use function Gallery\Services\feature_capability_admin_health_snapshot;
    use function Gallery\Services\feature_capability_configured_enabled;
    use function Gallery\Services\feature_capability_definitions;
    use function Gallery\Services\feature_capability_effective_enabled;
    use function Gallery\Services\feature_flag_route_enabled;
    use function Gallery\Services\set_feature_capability_enabled;

    /** Throw when one cross-feature capability contract fails. */
    function feature_policy_stage13_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    $root = dirname(__DIR__);
    require_once $root . '/app/services/feature_flags.php';
    $GLOBALS['feature_policy_stage13_settings'] = [];

    // The health snapshot must be bounded and must not initiate schema inspection.
    $health = feature_capability_admin_health_snapshot([
        'public_search' => [
            'state' => 'available',
            'token' => 'STAGE13_SECRET_MUST_NOT_LEAK',
            'path' => 'C:\\private\\gallery\\deployment',
        ],
        'viewer_accounts' => ['state' => 'missing'],
    ]);
    feature_policy_stage13_assert(count($health) === count(feature_capability_definitions()), 'Capability health snapshot must account for every canonical capability exactly once.');
    feature_policy_stage13_assert(($health['public_search']['schema_state'] ?? null) === 'available', 'Already-resolved schema state may be attached to an effectively enabled capability.');
    feature_policy_stage13_assert(($health['viewer_accounts']['schema_state'] ?? null) === null, 'Disabled capabilities must not expose or trigger optional schema state.');
    feature_policy_stage13_assert(($health['remote_favicon_discovery']['source_type'] ?? '') === 'app_setting', 'Capability health must expose only the canonical storage source type.');
    feature_policy_stage13_assert(($health['development_diagnostics']['source_type'] ?? '') === 'domain_adapter', 'Domain-owned capability storage must remain identifiable without exposing raw values.');
    feature_policy_stage13_assert(($health['remote_favicon_discovery']['settings_route'] ?? '') === 'admin_settings', 'Capability health must retain bounded specialized-settings discovery metadata.');
    $serializedHealth = json_encode($health, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    feature_policy_stage13_assert(!str_contains($serializedHealth, 'STAGE13_SECRET_MUST_NOT_LEAK') && !str_contains($serializedHealth, 'private\\gallery'), 'Capability health must discard caller secrets and private path diagnostics.');

    // Every capability with explicit route ownership receives the same master-off contract.
    foreach (feature_capability_definitions() as $featureKey => $definition) {
        $ownedRoutes = array_values(array_filter(array_map('strval', (array) ($definition['routes'] ?? []))));
        if ($ownedRoutes === []) {
            continue;
        }
        $originalConfigured = feature_capability_configured_enabled($featureKey);
        feature_policy_stage13_assert(set_feature_capability_enabled($featureKey, false), 'Capability must accept OFF through its canonical storage owner: ' . $featureKey);
        foreach ($ownedRoutes as $ownedRoute) {
            feature_policy_stage13_assert(!feature_flag_route_enabled($ownedRoute), 'Master OFF must reject owned route ' . $ownedRoute . ' for ' . $featureKey . '.');
        }
        feature_policy_stage13_assert(set_feature_capability_enabled($featureKey, true), 'Capability must accept ON through its canonical storage owner: ' . $featureKey);
        foreach ($ownedRoutes as $ownedRoute) {
            feature_policy_stage13_assert(feature_flag_route_enabled($ownedRoute), 'Master ON must restore owned route ' . $ownedRoute . ' for ' . $featureKey . '.');
        }
        feature_policy_stage13_assert(set_feature_capability_enabled($featureKey, $originalConfigured), 'Capability test must restore configured state: ' . $featureKey);
    }

    // Dependency OFF affects only effective state and route availability.
    feature_policy_stage13_assert(set_feature_capability_enabled('image_voting', false), 'Image Voting dependency must accept OFF.');
    feature_policy_stage13_assert(set_feature_capability_enabled('picture_game', true), 'Picture Game configured preference must accept ON.');
    feature_policy_stage13_assert(feature_capability_configured_enabled('picture_game'), 'Dependent configured state must remain ON while its dependency is OFF.');
    feature_policy_stage13_assert(!feature_capability_effective_enabled('picture_game'), 'Dependent effective state must be OFF while its dependency is OFF.');
    feature_policy_stage13_assert(!feature_flag_route_enabled('picture_game'), 'Dependent owned route must be unavailable while a dependency is OFF.');
    feature_policy_stage13_assert(set_feature_capability_enabled('image_voting', true), 'Image Voting dependency must restore ON.');
    feature_policy_stage13_assert(feature_capability_effective_enabled('picture_game') && feature_flag_route_enabled('picture_game'), 'Restoring a dependency must recover effective state without rewriting the dependent preference.');

    // Persistent optional subsystems must use non-destructive disable semantics.
    foreach (['smart_galleries', 'gallery_trash', 'multilingual_content', 'duplicate_photo_detector', 'public_tag_browsing', 'viewer_accounts'] as $persistentFeature) {
        $definition = feature_capability_definitions()[$persistentFeature] ?? [];
        feature_policy_stage13_assert(($definition['data_disable_policy'] ?? '') === 'preserve', 'Master OFF must preserve persistent subsystem data for ' . $persistentFeature . '.');
    }
    $policyWriteSource = (string) file_get_contents($root . '/app/services/feature_flags/policy.php')
        . "
" . (string) file_get_contents($root . '/app/services/feature_flags/adapters.php');
    foreach (['smart_gallery_delete', 'duplicate_photo_ledger_clear', 'DELETE FROM tags', 'viewer_account_delete'] as $destructiveBoundary) {
        feature_policy_stage13_assert(!str_contains($policyWriteSource, $destructiveBoundary), 'Canonical capability setters must not couple master OFF to subsystem deletion: ' . $destructiveBoundary);
    }

    // Admin navigation metadata remains registry-backed and the renderer filters declared masters.
    $adminChrome = (string) file_get_contents($root . '/app/views/admin_chrome.php');
    $adminHelper = (string) file_get_contents($root . '/app/helpers_admin_rendering.php');
    foreach ([$adminChrome, $adminHelper] as $navigationSource) {
        preg_match_all("/'feature'\\s*=>\\s*'([a-z0-9_]+)'/", $navigationSource, $matches);
        foreach (array_unique($matches[1] ?? []) as $navigationFeature) {
            feature_policy_stage13_assert(array_key_exists((string) $navigationFeature, feature_capability_definitions()), 'Admin navigation references unknown capability ' . $navigationFeature . '.');
        }
    }
    feature_policy_stage13_assert(
        str_contains($adminChrome, 'feature_capability_effective_enabled($featureKey)'),
        'Admin sidebar rendering must hide items whose declared capability is not effectively available.'
    );

    // Expensive optional work must retain an early capability boundary.
    $localizationSource = (string) file_get_contents($root . '/app/services/content_localization.php');
    $smartSource = (string) file_get_contents($root . '/app/services/smart_galleries.php');
    $benchmarkSource = (string) file_get_contents($root . '/app/services/gallery_benchmark.php');
    $faviconSource = (string) file_get_contents($root . '/app/services/link_favicons.php');
    $warmupSource = (string) file_get_contents($root . '/app/services/thumbnail_warmup.php');
    $maintenanceSource = (string) file_get_contents($root . '/app/services/site_maintenance.php');
    $telemetrySettingsSource = (string) file_get_contents($root . '/app/services/telemetry_settings.php');
    $telemetryPublicUsageStart = strpos($telemetrySettingsSource, 'function telemetry_public_usage_enabled(): bool');
    $telemetryMasterGate = $telemetryPublicUsageStart === false ? false : strpos($telemetrySettingsSource, "feature_capability_effective_enabled('telemetry')", $telemetryPublicUsageStart);
    $telemetrySubordinateSetting = $telemetryPublicUsageStart === false ? false : strpos($telemetrySettingsSource, "telemetry_setting_enabled('telemetry_enabled')", $telemetryPublicUsageStart);
    feature_policy_stage13_assert(
        $telemetryPublicUsageStart !== false
            && $telemetryMasterGate !== false
            && $telemetrySubordinateSetting !== false
            && $telemetryMasterGate < $telemetrySubordinateSetting,
        'Telemetry master OFF must win before subordinate collection settings on public and server-side telemetry paths.'
    );

    $databaseObserverSource = (string) file_get_contents($root . '/app/services/database_observer.php');
    $databaseTelemetryStart = strpos($databaseObserverSource, 'function telemetry_record_db_query(');
    $databaseTelemetryGate = $databaseTelemetryStart === false ? false : strpos($databaseObserverSource, "feature_capability_effective_enabled('telemetry')", $databaseTelemetryStart);
    $databaseTelemetrySchema = $databaseTelemetryStart === false ? false : strpos($databaseObserverSource, 'telemetry_settings_schema_ready()', $databaseTelemetryStart);
    feature_policy_stage13_assert(
        $databaseTelemetryStart !== false
            && $databaseTelemetryGate !== false
            && $databaseTelemetrySchema !== false
            && $databaseTelemetryGate < $databaseTelemetrySchema,
        'Telemetry master OFF must stop database-observer metrics before telemetry schema work.'
    );

    $telemetryRollupSource = (string) file_get_contents($root . '/app/services/telemetry_rollup.php');
    $telemetryMaintenanceStart = strpos($telemetryRollupSource, 'function telemetry_run_maintenance(): array');
    $telemetryMaintenanceGate = $telemetryMaintenanceStart === false ? false : strpos($telemetryRollupSource, "feature_capability_effective_enabled('telemetry')", $telemetryMaintenanceStart);
    $telemetryMaintenanceRollup = $telemetryMaintenanceStart === false ? false : strpos($telemetryRollupSource, 'telemetry_rollup_daily()', $telemetryMaintenanceStart);
    feature_policy_stage13_assert(
        $telemetryMaintenanceStart !== false
            && $telemetryMaintenanceGate !== false
            && $telemetryMaintenanceRollup !== false
            && $telemetryMaintenanceGate < $telemetryMaintenanceRollup,
        'Telemetry maintenance must fail fast on effective capability state before rollup or retention queries.'
    );

    $galleryReportContentSource = (string) file_get_contents($root . '/app/services/admin_gallery_report/content_summary.php');
    $galleryReportRenderSource = (string) file_get_contents($root . '/app/services/admin_gallery_report/render.php');
    $galleryReportViewSource = (string) file_get_contents($root . '/app/views/admin_gallery_report.php');
    feature_policy_stage13_assert(str_contains($localizationSource, 'content_localization_enabled() ? content_translation_rows'), 'Multilingual OFF must skip translation-row loaders.');
    feature_policy_stage13_assert(substr_count($smartSource, "feature_capability_effective_enabled('smart_galleries')") >= 3, 'Smart Gallery public discovery must short-circuit while its master is OFF.');
    feature_policy_stage13_assert(substr_count($benchmarkSource, "feature_capability_effective_enabled('development_diagnostics')") >= 5, 'Development Diagnostics OFF must skip profiler and benchmark work.');
    feature_policy_stage13_assert(substr_count($faviconSource, "feature_capability_effective_enabled('remote_favicon_discovery')") >= 2, 'Remote favicon OFF must skip outbound discovery work.');
    feature_policy_stage13_assert(strpos($warmupSource, 'if (!thumbnail_warmup_enabled())') < strpos($warmupSource, '@fopen(thumbnail_warmup_lock_path()'), 'Thumbnail warmup OFF must exit before worker-lock/file work.');
    feature_policy_stage13_assert(str_contains($maintenanceSource, "feature_capability_effective_enabled('telemetry')"), 'Scheduled maintenance must skip telemetry maintenance while telemetry is OFF.');
    $galleryReportTelemetryStart = strpos($galleryReportContentSource, 'function admin_gallery_report_telemetry_section(');
    $galleryReportTelemetryGate = $galleryReportTelemetryStart === false ? false : strpos($galleryReportContentSource, "feature_capability_effective_enabled('telemetry')", $galleryReportTelemetryStart);
    $galleryReportTelemetrySchema = $galleryReportTelemetryStart === false ? false : strpos($galleryReportContentSource, 'telemetry_schema_ready()', $galleryReportTelemetryStart);
    feature_policy_stage13_assert(
        $galleryReportTelemetryStart !== false
            && $galleryReportTelemetryGate !== false
            && $galleryReportTelemetrySchema !== false
            && $galleryReportTelemetryGate < $galleryReportTelemetrySchema,
        'Complete Gallery Report must skip Telemetry schema/report queries while Telemetry is effectively disabled.'
    );
    feature_policy_stage13_assert(
        !str_contains($galleryReportContentSource, 'telemetry_all_settings()'),
        'Complete Gallery Report must not execute an unused Telemetry settings query.'
    );
    feature_policy_stage13_assert(
        str_contains($galleryReportRenderSource, "if (!empty(\$telemetry['disabled']))")
            && str_contains($galleryReportViewSource, "feature_capability_effective_enabled('telemetry')")
            && str_contains($galleryReportViewSource, 'if ($telemetryEnabled)'),
        'Complete Gallery Report must omit Telemetry-only output controls and sections while Telemetry is effectively disabled.'
    );

    $publicSearchSource = (string) file_get_contents($root . '/app/services/public_search.php');
    $publicSearchAiReadyStart = strpos($publicSearchSource, 'function public_search_ai_metadata_ready(');
    $publicSearchAiGate = $publicSearchAiReadyStart === false ? false : strpos($publicSearchSource, "feature_capability_effective_enabled('ai_image_metadata')", $publicSearchAiReadyStart);
    $publicSearchAiSchema = $publicSearchAiReadyStart === false ? false : strpos($publicSearchSource, 'ai_image_analysis_schema_ready()', $publicSearchAiReadyStart);
    feature_policy_stage13_assert(
        $publicSearchAiReadyStart !== false
            && $publicSearchAiGate !== false
            && $publicSearchAiSchema !== false
            && $publicSearchAiGate < $publicSearchAiSchema
            && substr_count($publicSearchSource, '$aiSearchReady = public_search_ai_metadata_ready();') >= 2,
        'Local AI metadata OFF must skip AI schema/search joins before gallery and image search enrichment.'
    );

    $uploadAutomationSource = (string) file_get_contents($root . '/app/controllers/upload_automation.php');
    $aiWorkerHandlerStart = strpos($uploadAutomationSource, 'function upload_automation_handle_ai_action(');
    $aiWorkerGate = $aiWorkerHandlerStart === false ? false : strpos($uploadAutomationSource, "feature_capability_effective_enabled('ai_image_metadata')", $aiWorkerHandlerStart);
    $aiWorkerSchema = $aiWorkerHandlerStart === false ? false : strpos($uploadAutomationSource, 'presentation_ai_image_analysis_schema_status()', $aiWorkerHandlerStart);
    feature_policy_stage13_assert(
        $aiWorkerHandlerStart !== false
            && $aiWorkerGate !== false
            && $aiWorkerSchema !== false
            && $aiWorkerGate < $aiWorkerSchema,
        'Shared Upload API worker actions must reject Local AI metadata OFF before AI schema or queue work.'
    );

    $openAiAssistSource = (string) file_get_contents($root . '/app/services/openai_text_assist.php');
    feature_policy_stage13_assert(
        str_contains($openAiAssistSource, "feature_capability_effective_enabled('ai_image_metadata')")
            && str_contains($openAiAssistSource, '$localAiMetadataEnabled && function_exists'),
        'OpenAI text assistance must not consume preserved local AI metadata while that capability is effectively disabled.'
    );

    // Runtime/UI gates use effective state, and disabled shared-route actions must short-circuit before optional schema work.
    $pictureGameSource = (string) file_get_contents($root . '/app/services/picture_game.php');
    $pictureGameAvailableStart = strpos($pictureGameSource, 'function picture_game_available(');
    $pictureGameEffectiveGate = $pictureGameAvailableStart === false ? false : strpos($pictureGameSource, "feature_capability_effective_enabled('picture_game')", $pictureGameAvailableStart);
    $pictureGameAvailabilityQuery = $pictureGameAvailableStart === false ? false : strpos($pictureGameSource, 'picture_game_available_image_count($gallery, 2)', $pictureGameAvailableStart);
    feature_policy_stage13_assert(
        $pictureGameAvailableStart !== false
            && $pictureGameEffectiveGate !== false
            && $pictureGameAvailabilityQuery !== false
            && $pictureGameEffectiveGate < $pictureGameAvailabilityQuery,
        'Picture Game availability must fail fast on effective capability state before image/schema work.'
    );

    $adminLogsSource = (string) file_get_contents($root . '/app/controllers/admin_logs.php');
    feature_policy_stage13_assert(
        str_contains($adminLogsSource, "if (feature_capability_effective_enabled('telemetry'))")
            && str_contains($adminLogsSource, "url_for('admin_telemetry')"),
        'Admin Logs must not advertise the Telemetry route while Telemetry is effectively disabled.'
    );

    $dashboardSource = (string) file_get_contents($root . '/app/services/admin_dashboard.php');
    foreach ([
        ["feature_capability_effective_enabled('picture_game')", "picture_game_schema_ready()", 'Picture Game'],
        ["feature_capability_effective_enabled('gallery_maps')", "exif_gps_schema_ready()", 'Gallery Maps'],
        ["feature_capability_effective_enabled('image_voting')", "gallery_voting_schema_ready()", 'Image Voting'],
        ["feature_capability_effective_enabled('navigation_data')", "flight_map_navdata_schema_ready()", 'Navigation Data'],
    ] as [$featureNeedle, $schemaNeedle, $label]) {
        $featureOffset = strpos($dashboardSource, $featureNeedle);
        $schemaOffset = strpos($dashboardSource, $schemaNeedle);
        feature_policy_stage13_assert(
            $featureOffset !== false && $schemaOffset !== false && $featureOffset < $schemaOffset,
            'Admin dashboard must evaluate ' . $label . ' capability before its optional schema probe.'
        );
    }
    feature_policy_stage13_assert(
        str_contains($dashboardSource, 'feature_capability_effective_enabled($flag)'),
        'Optional presentation System Health must use effective capability state before invoking lazy schema resolvers.'
    );

    foreach ([
        "'mutation_duplicate_photo_ledger' => ['resolver' => static fn (): array => duplicate_photo_ledger_schema_status(), 'flag' => 'duplicate_photo_detector']",
        "'mutation_upload_automation' => ['resolver' => static fn (): array => upload_automation_schema_status(), 'flag' => 'upload_api']",
        "'mutation_gallery_migration' => ['resolver' => static fn (): array => gallery_migration_schema_status(), 'flag' => 'gallery_migration']",
        "'mutation_mobile_webdav' => ['resolver' => static fn (): array => mobile_webdav_schema_status(), 'flag' => 'mobile_webdav']",
        "'mutation_database_maintenance' => ['resolver' => static fn (): array => database_maintenance_mutation_schema_status(), 'flag' => 'advanced_database_maintenance']",
        "'mutation_application_update' => ['resolver' => static fn (): array => application_update_activation_schema_status(), 'flag' => 'built_in_update_installer']",
    ] as $mutationHealthDefinition) {
        feature_policy_stage13_assert(
            str_contains($dashboardSource, $mutationHealthDefinition),
            'Optional mutation System Health must bind schema inspection to its owning effective capability.'
        );
    }
    feature_policy_stage13_assert(
        str_contains($dashboardSource, 'if (!$enabled) {')
            && str_contains($dashboardSource, '$statuses[$feature] = admin_schema_health_model([], $feature, false);'),
        'Disabled optional mutation capabilities must report disabled health without executing schema resolvers.'
    );

    $galleryEditorCapabilitySource = (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_page/capabilities.php');
    feature_policy_stage13_assert(
        str_contains($galleryEditorCapabilitySource, 'feature_capability_effective_enabled($flag)')
            && str_contains($galleryEditorCapabilitySource, '$pictureGameFeatureEnabled && picture_game_schema_ready()')
            && str_contains($galleryEditorCapabilitySource, '$gpsMapFeatureEnabled && exif_gps_schema_ready()')
            && str_contains($galleryEditorCapabilitySource, '$imageVotingFeatureEnabled && gallery_voting_schema_ready()'),
        'Gallery editor readiness must apply effective capability state before optional schema checks.'
    );

    $galleryEditorSaveSource = (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_actions.php');
    feature_policy_stage13_assert(
        str_contains($galleryEditorSaveSource, "\$pictureGameFeatureEnabled && array_key_exists('picture_game_enabled', \$input)")
            && str_contains($galleryEditorSaveSource, "\$gpsMapFeatureEnabled && array_key_exists('gps_map_enabled', \$input)")
            && str_contains($galleryEditorSaveSource, "\$votingFeatureEnabled && array_key_exists('voting_enabled', \$input)"),
        'Gallery editor save preflights must ignore stale fields for effectively disabled optional features.'
    );

    $bulkGallerySource = (string) file_get_contents($root . '/app/controllers/admin_galleries_bulk.php');
    foreach ([
        ["feature_capability_effective_enabled('gallery_maps')", 'exif_gps_schema_ready()', 'Gallery Maps'],
        ["feature_capability_effective_enabled('image_voting')", 'gallery_voting_schema_ready()', 'Image Voting'],
        ["feature_capability_effective_enabled('picture_game')", 'presentation_picture_game_schema_status()', 'Picture Game'],
    ] as [$featureNeedle, $schemaNeedle, $label]) {
        $featureOffset = strpos($bulkGallerySource, $featureNeedle);
        $schemaOffset = strpos($bulkGallerySource, $schemaNeedle);
        feature_policy_stage13_assert(
            $featureOffset !== false && $schemaOffset !== false && $featureOffset < $schemaOffset,
            'Shared Admin bulk route must reject disabled ' . $label . ' actions before optional schema/data work.'
        );
    }
    $dashboardViewSource = (string) file_get_contents($root . '/app/views/admin_dashboard.php');
    feature_policy_stage13_assert(
        str_contains($dashboardViewSource, 'if ($gpsMapReady)')
            && str_contains($dashboardViewSource, 'if ($votingReady)')
            && str_contains($dashboardViewSource, 'if ($pictureGameReady)'),
        'Admin bulk UI must hide optional Gallery Maps, Image Voting, and Picture Game actions when their effective capability/readiness is unavailable.'
    );

    echo "Feature policy cross-feature contracts passed.\n";
}
