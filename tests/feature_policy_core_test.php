<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/feature_policy_core_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the canonical feature capability registry and policy semantics.
 *
 * Responsibilities:
 *   - Keep all current feature keys and configured defaults compatible
 *   - Distinguish configured administrator state from dependency-aware effective state
 *   - Keep strict canonical lookups fail-closed while legacy unknown feature checks remain fail-open
 *   - Validate registry groups, sources, dependencies, defaults, and route ownership without database schema probes
 *   - Keep Picture Game dependent on Image Voting without rewriting its stored preference
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
    /** Minimal translation stub for canonical registry definitions. */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        $text = is_string($fallback) ? $fallback : $key;
        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** Return one in-memory application setting for the focused policy test. */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $GLOBALS['feature_policy_core_settings'] ?? [])
            ? (string) $GLOBALS['feature_policy_core_settings'][$key]
            : $default;
    }

    /** Persist one in-memory application setting for the focused policy test. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['feature_policy_core_settings'][$key] = $value;
    }

    /** Return the in-memory Development Diagnostics state through the production adapter contract. */
    function dev_mode_enabled(): bool
    {
        return app_setting('dev_mode_enabled', '0') !== '0';
    }

    /** Persist the in-memory Development Diagnostics state through the production adapter contract. */
    function set_dev_mode_enabled(bool $enabled): void
    {
        set_app_setting('dev_mode_enabled', $enabled ? '1' : '0');
    }
}

namespace {
    use function Gallery\Services\feature_capability_configured_enabled;
    use function Gallery\Services\feature_capability_definitions;
    use function Gallery\Services\feature_capability_effective_enabled;
    use function Gallery\Services\feature_capability_route_requirement;
    use function Gallery\Services\feature_capability_validate_registry;
    use function Gallery\Services\feature_flag_enabled;
    use function Gallery\Services\feature_flag_for_route;
    use function Gallery\Services\feature_flag_route_enabled;
    use function Gallery\Services\feature_flag_route_map;
    use function Gallery\Services\set_feature_capability_enabled;
    use function Gallery\Services\set_feature_flag_enabled;

    /** Throw when one canonical feature-policy expectation fails. */
    function feature_policy_core_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    /** Return true when a validator result starts with the expected error code. */
    function feature_policy_core_has_error(array $errors, string $prefix): bool
    {
        foreach ($errors as $error) {
            if (str_starts_with((string) $error, $prefix)) {
                return true;
            }
        }
        return false;
    }

    $root = dirname(__DIR__);
    require_once $root . '/tests/support/module_source.php';
    require_once $root . '/app/services/feature_flags.php';

    $GLOBALS['feature_policy_core_settings'] = [];
    $definitions = feature_capability_definitions();
    $expectedKeys = [
        'public_search',
        'lightbox_modes',
        'picture_manager',
        'inline_administration',
        'image_voting',
        'picture_game',
        'smart_galleries',
        'downloads',
        'thumbnail_warmup',
        'multilingual_content',
        'public_tag_browsing',
        'viewer_accounts',
        'gallery_maps',
        'flight_maps',
        'navigation_data',
        'simbrief',
        'openai_text_assist',
        'ai_image_metadata',
        'upload_api',
        'mobile_webdav',
        'gallery_migration',
        'gallery_trash',
        'duplicate_photo_detector',
        'metadata_organizer',
        'exif_gallery_date_suggestions',
        'complete_gallery_report',
        'development_diagnostics',
        'remote_favicon_discovery',
        'built_in_update_installer',
        'advanced_database_maintenance',
        'media_renamer',
        'telemetry',
        'admin_test_runs',
    ];

    feature_policy_core_assert(array_keys($definitions) === $expectedKeys, 'Canonical feature policy changed the expected set or order of the 33 Admin feature switches.');
    feature_policy_core_assert(feature_capability_validate_registry() === [], 'The canonical capability registry must validate without errors.');
    feature_policy_core_assert(($definitions['picture_game']['dependencies'] ?? []) === ['image_voting'], 'Picture Game must canonically depend on Image Voting.');
    feature_policy_core_assert(($definitions['viewer_accounts']['default_enabled'] ?? null) === false, 'Viewer accounts must remain disabled by default.');
    feature_policy_core_assert(($definitions['admin_test_runs']['default_enabled'] ?? null) === false, 'Admin test runs must remain disabled by default.');
    feature_policy_core_assert(($definitions['thumbnail_warmup']['source'] ?? null) === ['type' => 'domain_adapter', 'adapter' => 'thumbnail_warmup'], 'Public thumbnail self-healing must use the existing thumbnail warmup domain adapter.');
    feature_policy_core_assert(($definitions['thumbnail_warmup']['fresh_default_enabled'] ?? null) === false, 'Public thumbnail self-healing must seed OFF only on fresh installations.');
    feature_policy_core_assert(($definitions['gallery_trash']['source'] ?? null) === ['type' => 'domain_adapter', 'adapter' => 'gallery_trash'], 'Gallery Trash must use the existing compound domain adapter.');
    feature_policy_core_assert(($definitions['multilingual_content']['data_disable_policy'] ?? null) === 'preserve', 'Multilingual authored content must preserve stored translations while disabled.');
    feature_policy_core_assert(($definitions['development_diagnostics']['source'] ?? null) === ['type' => 'domain_adapter', 'adapter' => 'development_diagnostics'], 'Development Diagnostics must reuse the existing dev_mode_enabled domain setting.');
    feature_policy_core_assert(($definitions['development_diagnostics']['default_enabled'] ?? null) === false, 'Development Diagnostics must remain disabled by default.');
    feature_policy_core_assert(($definitions['remote_favicon_discovery']['source'] ?? null) === ['type' => 'app_setting', 'key' => 'remote_favicon_discovery_enabled'], 'Remote Favicon Discovery must own one canonical app_setting.');
    feature_policy_core_assert(($definitions['remote_favicon_discovery']['fresh_default_enabled'] ?? null) === false, 'Remote Favicon Discovery must seed OFF only on fresh installations while upgrades retain ON fallback.');

    // Established switches remain configured ON by default, including Picture Game and its dependency.
    feature_policy_core_assert(feature_capability_configured_enabled('picture_game'), 'Picture Game configured state must remain enabled by default.');
    feature_policy_core_assert(feature_capability_effective_enabled('picture_game'), 'Picture Game must be effectively enabled while Image Voting is enabled.');

    // Disabling the dependency must not rewrite the stored Picture Game preference.
    set_feature_flag_enabled('image_voting', false);
    feature_policy_core_assert(feature_flag_enabled('picture_game'), 'Legacy feature_flag_enabled() must continue exposing Picture Game configured state.');
    feature_policy_core_assert(feature_capability_configured_enabled('picture_game'), 'Dependency disablement must not rewrite Picture Game configured state.');
    feature_policy_core_assert(!feature_capability_effective_enabled('picture_game'), 'Picture Game must become effectively disabled when Image Voting is disabled.');
    feature_policy_core_assert(!feature_flag_route_enabled('picture_game'), 'Picture Game route policy must use effective capability state.');
    feature_policy_core_assert(
        ($GLOBALS['feature_policy_core_settings']['feature_flag.picture_game.enabled'] ?? null) === null,
        'Dependency disablement must not persist an implicit Picture Game preference change.'
    );

    // Re-enabling the dependency restores effective state without touching Picture Game storage.
    set_feature_flag_enabled('image_voting', true);
    feature_policy_core_assert(feature_capability_effective_enabled('picture_game'), 'Re-enabling Image Voting must restore Picture Game effective state automatically.');
    set_feature_capability_enabled('picture_game', false);
    feature_policy_core_assert(!feature_capability_configured_enabled('picture_game'), 'Canonical setter must persist Picture Game configured OFF state.');
    feature_policy_core_assert(!feature_capability_effective_enabled('picture_game'), 'Configured OFF must always produce effective OFF.');

    // Strict canonical policy fails closed, while the documented legacy extension boundary remains fail-open.
    feature_policy_core_assert(!feature_capability_configured_enabled('unknown_core_capability'), 'Unknown canonical configured-state lookup must fail closed.');
    feature_policy_core_assert(!feature_capability_effective_enabled('unknown_core_capability'), 'Unknown canonical effective-state lookup must fail closed.');
    feature_policy_core_assert(feature_flag_enabled('unknown_extension_capability'), 'Legacy unknown feature lookup must remain fail-open for extension compatibility.');

    $routeMap = feature_flag_route_map();
    feature_policy_core_assert(($routeMap['picture_game'] ?? null) === 'picture_game', 'Picture Game route ownership must be derived from the canonical registry.');
    feature_policy_core_assert(feature_flag_for_route('viewer_collection_share') === 'viewer_accounts', 'viewer_* routes must be owned through canonical viewer route-prefix metadata.');
    feature_policy_core_assert(feature_flag_for_route('smart_gallery') === 'smart_galleries', 'Public Smart Gallery pages must be owned by the Smart Galleries capability.');
    feature_policy_core_assert(feature_flag_for_route('admin_smart_galleries') === 'smart_galleries', 'Admin Smart Gallery management must be owned by the Smart Galleries capability.');
    feature_policy_core_assert(feature_flag_for_route('smart_gallery_lightbox_data') === null, 'Smart Gallery lightbox data must remain a multi-capability route.');
    feature_policy_core_assert(feature_flag_for_route('picture_manager_download_selection') === 'picture_manager', 'Picture Manager selected ZIP must be owned by the picture-manager capability.');
    feature_policy_core_assert(feature_flag_for_route('admin_public_update_gallery') === 'inline_administration', 'Public inline gallery mutations must be owned by Inline Administration.');
    feature_policy_core_assert(feature_flag_for_route('admin_public_update_image') === 'inline_administration', 'Public inline image mutations must be owned by Inline Administration.');
    feature_policy_core_assert(feature_flag_for_route('thumbnail_warmup') === 'thumbnail_warmup', 'Public thumbnail warmup route must be owned by the public thumbnail self-healing capability.');
    feature_policy_core_assert(feature_flag_for_route('tag') === 'public_tag_browsing', 'Public tag landing route must be owned by Public Tag Browsing.');
    feature_policy_core_assert(feature_flag_for_route('admin_duplicate_photos') === 'duplicate_photo_detector', 'Duplicate Photo Detector route must be owned by its Admin-tool capability.');
    feature_policy_core_assert(feature_flag_for_route('admin_metadata_organizer_preview_batch') === 'metadata_organizer', 'Metadata Organizer preview route must be owned by its Admin-tool capability.');
    feature_policy_core_assert(feature_flag_for_route('admin_metadata_organizer_apply_date_plan_batch') === 'metadata_organizer', 'Metadata Organizer apply route must be owned by its Admin-tool capability.');
    feature_policy_core_assert(feature_flag_for_route('admin_gallery_dates') === 'exif_gallery_date_suggestions', 'EXIF gallery-date review route must be owned by the EXIF suggestion capability.');
    feature_policy_core_assert(feature_flag_for_route('admin_gallery_date_suggestion') === 'exif_gallery_date_suggestions', 'EXIF gallery-date apply route must be owned by the EXIF suggestion capability.');
    feature_policy_core_assert(feature_flag_for_route('admin_gallery_report') === 'complete_gallery_report', 'Complete Gallery Report page must be owned by its Admin-tool capability.');
    feature_policy_core_assert(feature_flag_for_route('admin_gallery_report_generate') === 'complete_gallery_report', 'Complete Gallery Report generation route must be owned by its Admin-tool capability.');
    foreach (['admin_gallery_benchmark_start', 'admin_gallery_benchmark_browser', 'admin_gallery_benchmark_status', 'admin_gallery_benchmark_probe', 'admin_gallery_benchmark_download'] as $benchmarkRoute) {
        feature_policy_core_assert(feature_flag_for_route($benchmarkRoute) === 'development_diagnostics', 'Gallery benchmark route must be owned by Development Diagnostics: ' . $benchmarkRoute);
    }
    feature_policy_core_assert(feature_flag_for_route('admin_update') === null, 'Mixed read-only/mutation updater page must remain a core route and gate only mutation actions.');
    feature_policy_core_assert(feature_flag_for_route('admin_database_maintenance_inspect') === null, 'Read-only database inspection must remain available independently of advanced mutation policy.');
    feature_policy_core_assert(feature_flag_for_route('download_all') === null, 'Legacy download_all must remain a core route outside the optional downloads capability.');

    $smartLightboxRequirement = feature_capability_route_requirement('smart_gallery_lightbox_data');
    feature_policy_core_assert(
        is_array($smartLightboxRequirement)
            && ($smartLightboxRequirement['type'] ?? null) === 'all_of'
            && ($smartLightboxRequirement['capabilities'] ?? null) === ['smart_galleries', 'lightbox_modes'],
        'Smart Gallery lightbox data must require both Smart Galleries and Lightbox Modes.'
    );
    $smartDownloadRequirement = feature_capability_route_requirement('download_smart_gallery_manifest');
    feature_policy_core_assert(
        is_array($smartDownloadRequirement)
            && ($smartDownloadRequirement['type'] ?? null) === 'all_of'
            && ($smartDownloadRequirement['capabilities'] ?? null) === ['smart_galleries', 'downloads'],
        'Smart Gallery downloads must require both Smart Galleries and Gallery ZIP Downloads.'
    );
    set_feature_flag_enabled('inline_administration', false);
    feature_policy_core_assert(!feature_flag_route_enabled('admin_public_update_gallery'), 'Inline Administration OFF must block public-page gallery mutation routes.');
    feature_policy_core_assert(feature_flag_route_enabled('picture_manager_move'), 'Picture Manager remains independent when Inline Administration is disabled.');
    set_feature_flag_enabled('inline_administration', true);

    set_feature_flag_enabled('public_tag_browsing', false);
    feature_policy_core_assert(!feature_flag_route_enabled('tag'), 'Public Tag Browsing OFF must block direct public tag routes.');
    set_feature_flag_enabled('public_tag_browsing', true);
    feature_policy_core_assert(feature_flag_route_enabled('tag'), 'Public Tag Browsing ON must restore public tag routes without rebuilding tag data.');

    set_feature_flag_enabled('smart_galleries', false);
    feature_policy_core_assert(!feature_flag_route_enabled('smart_gallery'), 'Smart Gallery public route must be blocked by its master capability.');
    feature_policy_core_assert(!feature_flag_route_enabled('admin_smart_galleries'), 'Smart Gallery Admin route must be blocked by its master capability.');
    feature_policy_core_assert(!feature_flag_route_enabled('smart_gallery_lightbox_data'), 'Smart Gallery lightbox route must be blocked when the Smart Galleries master is off.');
    feature_policy_core_assert(!feature_flag_route_enabled('download_smart_gallery_manifest'), 'Smart Gallery download routes must be blocked when the Smart Galleries master is off.');
    set_feature_flag_enabled('smart_galleries', true);
    feature_policy_core_assert(feature_flag_route_enabled('smart_gallery'), 'Smart Gallery public route must recover when its master capability is re-enabled.');

    foreach ([
        'duplicate_photo_detector' => ['admin_duplicate_photos'],
        'metadata_organizer' => ['admin_metadata_organizer_preview_batch', 'admin_metadata_organizer_apply_date_plan_batch'],
        'exif_gallery_date_suggestions' => ['admin_gallery_dates', 'admin_gallery_date_suggestion'],
        'complete_gallery_report' => ['admin_gallery_report', 'admin_gallery_report_generate'],
        'development_diagnostics' => ['admin_gallery_benchmark_start', 'admin_gallery_benchmark_browser', 'admin_gallery_benchmark_status', 'admin_gallery_benchmark_probe', 'admin_gallery_benchmark_download'],
    ] as $featureKey => $ownedRoutes) {
        set_feature_flag_enabled($featureKey, false);
        foreach ($ownedRoutes as $ownedRoute) {
            feature_policy_core_assert(!feature_flag_route_enabled($ownedRoute), $featureKey . ' OFF must block owned route ' . $ownedRoute . '.');
        }
        set_feature_flag_enabled($featureKey, true);
        foreach ($ownedRoutes as $ownedRoute) {
            feature_policy_core_assert(feature_flag_route_enabled($ownedRoute), $featureKey . ' ON must restore owned route ' . $ownedRoute . '.');
        }
    }

    $mapRequirement = feature_capability_route_requirement('gallery_map_data');
    feature_policy_core_assert(
        is_array($mapRequirement)
            && ($mapRequirement['type'] ?? null) === 'any_of'
            && ($mapRequirement['capabilities'] ?? null) === ['gallery_maps', 'flight_maps'],
        'Gallery map data must use the canonical any-of map requirement.'
    );
    set_feature_flag_enabled('gallery_maps', false);
    set_feature_flag_enabled('flight_maps', true);
    feature_policy_core_assert(feature_flag_route_enabled('gallery_map_data'), 'Any-of route policy must allow gallery_map_data when Flight Maps remains enabled.');
    set_feature_flag_enabled('flight_maps', false);
    feature_policy_core_assert(!feature_flag_route_enabled('gallery_map_data'), 'Any-of route policy must deny gallery_map_data when both map capabilities are disabled.');
    set_feature_flag_enabled('gallery_maps', true);
    feature_policy_core_assert(feature_flag_route_enabled('gallery_map_data'), 'Any-of route policy must recover when Gallery Maps is re-enabled.');

    // Registry validation is intentionally pure and must reject malformed policy metadata before runtime use.
    $bad = $definitions;
    $bad['public_search']['group'] = 'missing_group';
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'unknown_group:public_search:'), 'Validator must reject unknown groups.');

    $bad = $definitions;
    $bad['public_search']['source'] = ['type' => ''];
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'invalid_source:public_search'), 'Validator must reject malformed storage definitions.');

    $bad = $definitions;
    $bad['public_search']['source'] = ['type' => 'domain_adapter', 'adapter' => 'missing_adapter'];
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'unknown_domain_adapter:public_search:'), 'Validator must reject unknown domain adapters.');

    $bad = $definitions;
    $bad['public_search']['fresh_default_enabled'] = '0';
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'invalid_fresh_default:public_search'), 'Validator must reject non-boolean fresh-install defaults.');

    $bad = $definitions;
    $bad['public_search']['source'] = ['type' => 'derived'];
    $bad['public_search']['editable_on_features'] = false;
    $bad['public_search']['fresh_default_enabled'] = false;
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'unwritable_fresh_default_source:public_search:derived'), 'Fresh-install defaults must be limited to writable persisted capability sources.');

    $bad = $definitions;
    unset($bad['gallery_maps']);
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'unknown_route_requirement:gallery_map_data:gallery_maps'), 'Validator must reject compound route requirements that reference an unregistered capability.');

    $bad = $definitions;
    $bad['public_search']['default_enabled'] = '1';
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'invalid_default:public_search'), 'Validator must reject non-boolean defaults.');

    $bad = $definitions;
    $bad['picture_game']['dependencies'] = ['picture_game'];
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'self_dependency:picture_game'), 'Validator must reject self dependencies.');

    $bad = $definitions;
    $bad['picture_game']['dependencies'] = ['missing_capability'];
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'unknown_dependency:picture_game:'), 'Validator must reject unknown dependencies.');

    $bad = $definitions;
    $bad['public_search']['dependencies'] = ['downloads'];
    $bad['downloads']['dependencies'] = ['public_search'];
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'dependency_cycle:'), 'Validator must reject dependency cycles.');

    $bad = $definitions;
    $bad['downloads']['routes'][] = 'public_search';
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'conflicting_route_owner:public_search:'), 'Validator must reject conflicting explicit route ownership.');

    $bad = $definitions;
    $bad['downloads']['route_prefixes'] = ['viewer_'];
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'conflicting_route_prefix_owner:viewer_:'), 'Validator must reject conflicting route-prefix ownership.');

    $bad = $definitions;
    $bad['downloads']['routes'][] = 'invalid-route';
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'invalid_route:downloads'), 'Validator must reject malformed route identifiers.');

    $bad = $definitions;
    $bad['downloads']['route_prefixes'] = ['viewer_private_'];
    feature_policy_core_assert(feature_policy_core_has_error(feature_capability_validate_registry($bad), 'overlapping_route_prefixes:'), 'Validator must reject route prefixes that overlap another capability owner.');

    $featureServiceSource = module_source($root . '/app/services/feature_flags.php');
    feature_policy_core_assert(!str_contains($featureServiceSource, 'CREATE TABLE'), 'Capability registry assembly must not introduce database schema creation or probing.');

    $adminFeaturesSource = (string) file_get_contents($root . '/app/controllers/admin_features.php');
    feature_policy_core_assert(
        str_contains($adminFeaturesSource, 'feature_capability_admin_health_snapshot()')
            && str_contains($adminFeaturesSource, "health['configured']")
            && str_contains($adminFeaturesSource, '$configuredEnabled'),
        'Admin > Features checkboxes must render configured state from the bounded health snapshot rather than dependency-aware effective state.'
    );

    echo "Feature policy core tests passed.\n";
}
