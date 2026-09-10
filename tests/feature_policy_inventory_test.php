<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/feature_policy_inventory_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the complete capability ownership inventory and repaired policy boundaries.
 *
 * Responsibilities:
 *   - Verify the complete current Admin feature registry and its compatibility defaults
 *   - Detect literal core feature lookups that reference an unregistered capability key
 *   - Verify every declared route owner references a registered capability
 *   - Verify repaired optional route and UI ownership boundaries
 *   - Verify Admin navigation feature metadata references only registered capabilities
 *   - Keep source-level ownership checks deterministic and behavior-neutral
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - This is intentionally a source-level ownership contract. It must not mutate application state.
 *   - Unknown extension keys remain outside this contract because feature_flag_enabled() is a legacy fail-open boundary.
 */

declare(strict_types=1);

namespace Gallery\Services {
    /** Minimal translation stub for registry construction. */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        $text = is_string($fallback) ? $fallback : $key;
        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** In-memory setting reader used only by the focused inventory test. */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['feature_policy_inventory_settings'][$key] ?? $default;
    }

    /** In-memory setting writer used only by the focused inventory test. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['feature_policy_inventory_settings'][$key] = $value;
    }
}

namespace Gallery\Core {
    /** Throw when one inventory contract fails. */
    function feature_policy_inventory_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
    }

    /** Return all PHP files below one directory in deterministic order. */
    function feature_policy_inventory_php_files(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $files[] = $file->getPathname();
        }
        sort($files, SORT_STRING);
        return $files;
    }

    $root = dirname(__DIR__);
    require_once $root . '/app/services/feature_flags.php';

    $definitions = \Gallery\Services\feature_flag_definitions();
    $registeredKeys = array_keys($definitions);
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

    feature_policy_inventory_assert(
        $registeredKeys === $expectedKeys,
        'Feature-policy inventory must explicitly account for the complete current 33-feature registry.'
    );

    foreach ($expectedKeys as $featureKey) {
        $expectedDefault = !in_array($featureKey, ['viewer_accounts', 'admin_test_runs', 'development_diagnostics'], true);
        feature_policy_inventory_assert(
            \Gallery\Services\feature_flag_default_enabled($featureKey) === $expectedDefault,
            'Unexpected compatibility default for registered feature: ' . $featureKey
        );
    }

    $groups = \Gallery\Services\feature_flag_groups();
    foreach ($definitions as $featureKey => $definition) {
        $groupKey = (string) ($definition['group'] ?? '');
        feature_policy_inventory_assert(
            $groupKey !== '' && array_key_exists($groupKey, $groups),
            'Feature references an unknown Admin group: ' . $featureKey . ' -> ' . $groupKey
        );
    }

    $lookupPatterns = [
        '/\\bfeature_flag_enabled\\(\\s*[\'\"]([a-z0-9_]+)[\'\"]\\s*\\)/',
        '/\\bfeature_flag_exists\\(\\s*[\'\"]([a-z0-9_]+)[\'\"]\\s*\\)/',
        '/\\bfeature_flag_default_enabled\\(\\s*[\'\"]([a-z0-9_]+)[\'\"]\\s*\\)/',
        '/\\bset_feature_flag_enabled\\(\\s*[\'\"]([a-z0-9_]+)[\'\"]\\s*,/',
    ];
    $literalCoreFeatureKeys = [];
    foreach (feature_policy_inventory_php_files($root . '/app') as $path) {
        if (str_replace('\\', '/', $path) === str_replace('\\', '/', $root . '/app/services/feature_flags.php')) {
            continue;
        }
        $source = (string) file_get_contents($path);
        foreach ($lookupPatterns as $pattern) {
            preg_match_all($pattern, $source, $matches);
            foreach ($matches[1] ?? [] as $featureKey) {
                $literalCoreFeatureKeys[(string) $featureKey] = true;
            }
        }
    }
    ksort($literalCoreFeatureKeys, SORT_STRING);
    foreach (array_keys($literalCoreFeatureKeys) as $featureKey) {
        feature_policy_inventory_assert(
            array_key_exists($featureKey, $definitions),
            'Core source references an unregistered feature key: ' . $featureKey
        );
    }

    $routeMap = \Gallery\Services\feature_flag_route_map();
    foreach ($routeMap as $route => $featureKey) {
        feature_policy_inventory_assert(
            array_key_exists($featureKey, $definitions),
            'Route feature ownership references an unregistered key: ' . $route . ' -> ' . $featureKey
        );
    }

    $dispatchSource = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
    preg_match('/\\$routes\\s*=\\s*\\[(.*?)\\n\\s*\\];/s', $dispatchSource, $routeTableMatch);
    feature_policy_inventory_assert(isset($routeTableMatch[1]), 'Central dispatcher route table could not be inventoried.');
    preg_match_all(
        "/^\\s*'([a-z0-9_]+)'\\s*=>\\s*'\\\\\\\\Gallery\\\\\\\\Controllers\\\\\\\\[^']+'/m",
        (string) $routeTableMatch[1],
        $routeMatches
    );
    $allRoutes = array_values(array_unique($routeMatches[1] ?? []));
    feature_policy_inventory_assert(count($allRoutes) >= 180, 'Dispatcher inventory unexpectedly lost a substantial part of the route table.');

    foreach ($routeMap as $route => $_featureKey) {
        feature_policy_inventory_assert(
            in_array($route, $allRoutes, true),
            'Feature route ownership points to a route that is not present in the dispatcher: ' . $route
        );
    }

    $viewerRoutes = array_values(array_filter($allRoutes, static fn (string $route): bool => str_starts_with($route, 'viewer_')));
    feature_policy_inventory_assert(count($viewerRoutes) >= 20, 'Viewer route family inventory unexpectedly shrank.');
    foreach ($viewerRoutes as $route) {
        feature_policy_inventory_assert(
            \Gallery\Services\feature_flag_for_route($route) === 'viewer_accounts',
            'Viewer route escaped the existing viewer_accounts feature owner: ' . $route
        );
    }

    $multipleCapabilityRoutes = [
        'gallery_map_data' => ['gallery_maps', 'flight_maps'],
        'smart_gallery_lightbox_data' => ['smart_galleries', 'lightbox_modes'],
        'download_smart_gallery_start' => ['smart_galleries', 'downloads'],
        'download_smart_gallery' => ['smart_galleries', 'downloads'],
        'download_smart_gallery_manifest' => ['smart_galleries', 'downloads'],
        'download_smart_gallery_file' => ['smart_galleries', 'downloads'],
    ];
    foreach ($multipleCapabilityRoutes as $route => $requirements) {
        feature_policy_inventory_assert(in_array($route, $allRoutes, true), 'Known multi-capability route is missing: ' . $route);
        foreach ($requirements as $featureKey) {
            feature_policy_inventory_assert(array_key_exists($featureKey, $definitions), 'Unknown multi-capability route requirement: ' . $route . ' -> ' . $featureKey);
        }
    }

    $mapRequirement = \Gallery\Services\feature_capability_route_requirement('gallery_map_data');
    feature_policy_inventory_assert(
        is_array($mapRequirement)
            && ($mapRequirement['type'] ?? null) === 'any_of'
            && ($mapRequirement['capabilities'] ?? null) === ['gallery_maps', 'flight_maps'],
        'Gallery map data must remain available when either map capability is effectively enabled.'
    );

    $repairedOptionalRoutes = [
        'picture_manager_download_selection' => 'picture_manager',
        'smart_gallery' => 'smart_galleries',
        'admin_smart_galleries' => 'smart_galleries',
    ];
    foreach ($repairedOptionalRoutes as $route => $owner) {
        feature_policy_inventory_assert(in_array($route, $allRoutes, true), 'Repaired optional route is missing from the dispatcher: ' . $route);
        feature_policy_inventory_assert(
            \Gallery\Services\feature_flag_for_route($route) === $owner,
            'Repaired optional route escaped its canonical owner: ' . $route . ' -> ' . $owner
        );
    }
    feature_policy_inventory_assert(
        \Gallery\Services\feature_flag_for_route('download_all') === null,
        'Legacy download_all route must remain outside the optional downloads capability policy.'
    );

    $adminNavigationSources = [
        $root . '/app/helpers_admin_rendering.php',
        $root . '/app/views/admin_chrome.php',
    ];
    foreach ($adminNavigationSources as $path) {
        $source = (string) file_get_contents($path);
        preg_match_all("/'feature'\\s*=>\\s*'([a-z0-9_]+)'/", $source, $matches);
        foreach (array_unique($matches[1] ?? []) as $featureKey) {
            feature_policy_inventory_assert(
                array_key_exists((string) $featureKey, $definitions),
                'Admin navigation references an unregistered feature key: ' . basename($path) . ' -> ' . $featureKey
            );
        }
    }

    $publicGallerySource = (string) file_get_contents($root . '/app/controllers/public_gallery_page.php');
    feature_policy_inventory_assert(
        str_contains($publicGallerySource, "feature_capability_effective_enabled('downloads')")
            && str_contains($publicGallerySource, "url_for('download_gallery_start'"),
        'Physical-gallery download controls must remain hidden when the downloads capability is effectively disabled.'
    );

    $smartGallerySource = (string) file_get_contents($root . '/app/services/smart_galleries.php');
    feature_policy_inventory_assert(
        str_contains($smartGallerySource, "feature_capability_effective_enabled('lightbox_modes')")
            && str_contains($smartGallerySource, "feature_capability_effective_enabled('downloads')")
            && str_contains($smartGallerySource, "feature_capability_effective_enabled('image_voting')")
            && str_contains($smartGallerySource, '$effective[\'download_enabled\'] = !empty($effective[\'download_enabled\']) && $downloadMasterEnabled;'),
        'Smart Gallery local presentation overrides must remain subordinate to the global capability masters.'
    );

    $inlineAdministrationDefinition = $definitions['inline_administration'] ?? [];
    feature_policy_inventory_assert(
        ($inlineAdministrationDefinition['data_disable_policy'] ?? '') === 'preserve'
            && ($inlineAdministrationDefinition['routes'] ?? []) === ['admin_public_update_gallery', 'admin_public_update_image', 'admin_reorder_public_galleries', 'admin_sort_public_subgalleries_by_date'],
        'Inline Administration must own only public-page mutation routes and preserve all gallery data when disabled.'
    );
    $inlineAdministrationSources = implode("\n", [
        (string) file_get_contents($root . '/app/controllers/public_gallery_cards.php'),
        (string) file_get_contents($root . '/app/controllers/public_gallery_controls.php'),
        (string) file_get_contents($root . '/app/controllers/public_gallery_page.php'),
        (string) file_get_contents($root . '/app/controllers/admin_images_reorder.php'),
    ]);
    feature_policy_inventory_assert(
        substr_count($inlineAdministrationSources, "feature_capability_effective_enabled('inline_administration')") >= 5,
        'Public-page edit/add/delete/reorder affordances must use the canonical Inline Administration capability.'
    );
    feature_policy_inventory_assert(
        str_contains($inlineAdministrationSources, "feature_capability_effective_enabled('picture_manager')"),
        'Picture Manager remains an independent public-page capability when Inline Administration is disabled.'
    );

    $thumbnailWarmupDefinition = $definitions['thumbnail_warmup'] ?? [];
    feature_policy_inventory_assert(
        ($thumbnailWarmupDefinition['source'] ?? []) === ['type' => 'domain_adapter', 'adapter' => 'thumbnail_warmup']
            && ($thumbnailWarmupDefinition['fresh_default_enabled'] ?? null) === false
            && ($thumbnailWarmupDefinition['routes'] ?? []) === ['thumbnail_warmup'],
        'Public thumbnail self-healing must bridge the existing warmup setting, seed fresh installs OFF, and own the public warmup route.'
    );
    $thumbnailWarmupSource = (string) file_get_contents($root . '/app/services/thumbnail_warmup.php');
    feature_policy_inventory_assert(
        str_contains($thumbnailWarmupSource, 'if (!thumbnail_warmup_enabled())')
            && strpos($thumbnailWarmupSource, 'if (!thumbnail_warmup_enabled())') < strpos($thumbnailWarmupSource, "@fopen(thumbnail_warmup_lock_path(), 'c')")
            && str_contains($thumbnailWarmupSource, 'function thumbnail_warmup_candidate_attributes')
            && str_contains($thumbnailWarmupSource, "return '';"),
        'Public thumbnail warmup OFF must be checked before worker lock creation and suppress emitted warmup metadata.'
    );

    $galleryTrashDefinition = $definitions['gallery_trash'] ?? [];
    feature_policy_inventory_assert(
        ($galleryTrashDefinition['source'] ?? []) === ['type' => 'domain_adapter', 'adapter' => 'gallery_trash']
            && ($galleryTrashDefinition['data_disable_policy'] ?? '') === 'preserve'
            && ($galleryTrashDefinition['routes'] ?? []) === [],
        'Gallery Trash must bridge the existing compound setting without route-gating recovery management or deleting stored trash data.'
    );
    $galleryTrashSource = (string) file_get_contents($root . '/app/services/gallery_trash.php');
    feature_policy_inventory_assert(
        str_contains($galleryTrashSource, 'return gallery_trash_enabled() && gallery_trash_auto_purge_enabled();'),
        'Gallery Trash automatic purge must remain inactive while the master is disabled while preserving the subordinate preference.'
    );

    $publicTagDefinition = $definitions['public_tag_browsing'] ?? [];
    feature_policy_inventory_assert(
        ($publicTagDefinition['data_disable_policy'] ?? '') === 'preserve'
            && ($publicTagDefinition['routes'] ?? []) === ['tag'],
        'Public Tag Browsing must own only the public tag route and preserve internal tag records/assignments.'
    );
    $publicTagSource = (string) file_get_contents($root . '/app/controllers/public_tags.php');
    $adminTagSource = (string) file_get_contents($root . '/app/controllers/admin_tags.php');
    feature_policy_inventory_assert(
        substr_count($publicTagSource, "feature_capability_effective_enabled('public_tag_browsing')") >= 2
            && str_contains($publicTagSource, '<span class="tag">')
            && substr_count($adminTagSource, "feature_capability_effective_enabled('public_tag_browsing')") >= 2,
        'Public Tag Browsing OFF must render tag metadata without dead links while keeping Admin tag management available.'
    );

    $multilingualDefinition = $definitions['multilingual_content'] ?? [];
    feature_policy_inventory_assert(
        ($multilingualDefinition['data_disable_policy'] ?? '') === 'preserve'
            && ($multilingualDefinition['routes'] ?? []) === [],
        'Multilingual authored content must preserve translation rows and must not gate interface-language routes.'
    );
    $contentLocalizationSource = (string) file_get_contents($root . '/app/services/content_localization.php');
    $adminLocalizationView = (string) file_get_contents($root . '/app/views/admin_gallery_forms.php');
    $galleryLocalizationSave = (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_actions.php');
    $imageLocalizationSave = (string) file_get_contents($root . '/app/controllers/admin_public_inline.php');
    $publicSearchSource = (string) file_get_contents($root . '/app/services/public_search.php');
    feature_policy_inventory_assert(
        str_contains($contentLocalizationSource, "feature_capability_effective_enabled('multilingual_content')")
            && str_contains($contentLocalizationSource, 'content_localization_enabled() ? content_translation_rows')
            && str_contains($adminLocalizationView, 'if (!content_localization_enabled())')
            && substr_count($galleryLocalizationSave . $imageLocalizationSave, 'content_localization_enabled()') >= 2
            && str_contains($publicSearchSource, '$localizedSearchReady = content_localization_enabled()'),
        'Multilingual authored-content OFF must skip public translation resolution/search queries and ignore hidden Admin localization fields.'
    );

    $smartGalleryDefinition = $definitions['smart_galleries'] ?? [];
    feature_policy_inventory_assert(
        ($smartGalleryDefinition['data_disable_policy'] ?? '') === 'preserve'
            && ($smartGalleryDefinition['routes'] ?? []) === ['admin_smart_galleries', 'smart_gallery'],
        'Smart Galleries master must preserve data and own its dedicated Admin/public routes.'
    );
    $smartGalleryAdminSources = implode("\n", [
        (string) file_get_contents($root . '/app/views/admin_chrome.php'),
        (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_page/tab_identity.php'),
        (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_page/post_actions.php'),
        (string) file_get_contents($root . '/app/controllers/admin_public_inline.php'),
    ]);
    feature_policy_inventory_assert(
        str_contains($smartGalleryAdminSources, "'feature' => 'smart_galleries'")
            && substr_count($smartGalleryAdminSources, "feature_capability_effective_enabled('smart_galleries')") >= 4,
        'Smart Gallery Admin entry points, physical-gallery attachments, and editorial-rating controls must honor the master capability.'
    );
    feature_policy_inventory_assert(
        str_contains($smartGallerySource, "feature_capability_effective_enabled('smart_galleries')"),
        'Smart Gallery public service discovery must honor the master capability without deleting stored definitions.'
    );

    $adminToolDefinitions = [
        'duplicate_photo_detector' => ['admin_duplicate_photos'],
        'metadata_organizer' => ['admin_metadata_organizer_preview_batch', 'admin_metadata_organizer_apply_date_plan_batch'],
        'exif_gallery_date_suggestions' => ['admin_gallery_dates', 'admin_gallery_date_suggestion'],
        'complete_gallery_report' => ['admin_gallery_report', 'admin_gallery_report_generate'],
    ];
    foreach ($adminToolDefinitions as $featureKey => $ownedRoutes) {
        $definition = $definitions[$featureKey] ?? [];
        feature_policy_inventory_assert(
            ($definition['group'] ?? '') === 'admin_tools'
                && ($definition['data_disable_policy'] ?? '') === 'preserve'
                && ($definition['routes'] ?? []) === $ownedRoutes,
            'Optional Admin tool must use the canonical admin_tools policy with preserved data and explicit route ownership: ' . $featureKey
        );
    }

    $duplicateToolSource = (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_page/tab_images.php');
    feature_policy_inventory_assert(
        str_contains($duplicateToolSource, "feature_capability_effective_enabled('duplicate_photo_detector')")
            && str_contains($duplicateToolSource, "url_for('admin_duplicate_photos'"),
        'Duplicate Photo Detector entry point must disappear when its canonical capability is disabled.'
    );

    $organizerSources = implode("\n", [
        (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_page/overview.php'),
        (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_page/controller.php'),
        (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_page/post_actions.php'),
        (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_page/tab_tools.php'),
    ]);
    feature_policy_inventory_assert(
        substr_count($organizerSources, "feature_capability_effective_enabled('metadata_organizer')") >= 4,
        'Metadata Organizer tab, shared preview boundary, and shared apply boundary must honor the canonical capability.'
    );

    $galleryDateSources = implode("\n", [
        (string) file_get_contents($root . '/app/views/admin_gallery_forms.php'),
        (string) file_get_contents($root . '/app/views/admin_dashboard.php'),
        (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_page/post_actions.php'),
    ]);
    feature_policy_inventory_assert(
        substr_count($galleryDateSources, "feature_capability_effective_enabled('exif_gallery_date_suggestions')") >= 3
            && str_contains($galleryDateSources, 'function view_render_admin_gallery_date_range_fields')
            && str_contains($galleryDateSources, 'name="gallery_date"')
            && str_contains($galleryDateSources, 'name="gallery_date_end"'),
        'EXIF Gallery Date Suggestions OFF must hide automated suggestions while preserving ordinary manual From/To date editing.'
    );

    $reportNavigationSources = implode("\n", [
        (string) file_get_contents($root . '/app/views/admin_chrome.php'),
        (string) file_get_contents($root . '/app/helpers_admin_rendering.php'),
        (string) file_get_contents($root . '/app/views/admin_dashboard_sections.php'),
    ]);
    feature_policy_inventory_assert(
        substr_count($reportNavigationSources, "'feature' => 'complete_gallery_report'") >= 2
            && str_contains($reportNavigationSources, "view_admin_dashboard_feature_enabled('complete_gallery_report')"),
        'Complete Gallery Report navigation and dashboard entry points must honor only the report capability.'
    );

    $developmentDefinition = $definitions['development_diagnostics'] ?? [];
    feature_policy_inventory_assert(
        ($developmentDefinition['group'] ?? '') === 'admin_tools'
            && ($developmentDefinition['source'] ?? []) === ['type' => 'domain_adapter', 'adapter' => 'development_diagnostics']
            && ($developmentDefinition['default_enabled'] ?? null) === false
            && ($developmentDefinition['routes'] ?? []) === ['admin_gallery_benchmark_start', 'admin_gallery_benchmark_browser', 'admin_gallery_benchmark_status', 'admin_gallery_benchmark_probe', 'admin_gallery_benchmark_download'],
        'Development Diagnostics must bridge dev_mode_enabled and own only Gallery benchmark routes.'
    );
    $benchmarkSource = (string) file_get_contents($root . '/app/services/gallery_benchmark.php');
    feature_policy_inventory_assert(
        substr_count($benchmarkSource, "feature_capability_effective_enabled('development_diagnostics')") >= 5,
        'Development Diagnostics OFF must stop stale-cookie/media/render benchmark instrumentation at service boundaries.'
    );

    $faviconDefinition = $definitions['remote_favicon_discovery'] ?? [];
    feature_policy_inventory_assert(
        ($faviconDefinition['source'] ?? []) === ['type' => 'app_setting', 'key' => 'remote_favicon_discovery_enabled']
            && ($faviconDefinition['fresh_default_enabled'] ?? null) === false
            && in_array('outbound-network', (array) ($faviconDefinition['behavior_tags'] ?? []), true),
        'Remote Favicon Discovery must use one canonical app_setting, fresh-install OFF policy, and outbound-network disclosure.'
    );
    $faviconSource = (string) file_get_contents($root . '/app/services/link_favicons.php');
    $faviconRefreshPosition = strpos($faviconSource, 'function link_favicon_refresh_gallery');
    $faviconFetchPosition = strpos($faviconSource, 'function link_favicon_fetch_site_icon');
    $faviconCachedPosition = strpos($faviconSource, 'function link_favicon_cached_public_url');
    feature_policy_inventory_assert(
        substr_count($faviconSource, "feature_capability_effective_enabled('remote_favicon_discovery')") >= 2
            && $faviconRefreshPosition !== false
            && $faviconFetchPosition !== false
            && $faviconCachedPosition !== false
            && !str_contains(substr($faviconSource, (int) $faviconCachedPosition, (int) $faviconFetchPosition - (int) $faviconCachedPosition), "feature_capability_effective_enabled('remote_favicon_discovery')"),
        'Remote Favicon Discovery OFF must block outbound refresh/fetch without disabling already cached local favicon rendering.'
    );

    $updateInstallerDefinition = $definitions['built_in_update_installer'] ?? [];
    feature_policy_inventory_assert(
        ($updateInstallerDefinition['group'] ?? '') === 'admin_tools'
            && ($updateInstallerDefinition['routes'] ?? []) === []
            && ($updateInstallerDefinition['data_disable_policy'] ?? '') === 'preserve',
        'Built-in Update Installer must action-gate mixed updater workflows instead of hiding the read-only update page.'
    );
    $updateControllerSource = (string) file_get_contents($root . '/app/controllers/updates.php');
    $updateInstallSource = (string) file_get_contents($root . '/app/services/updates_install.php');
    $updateLifecycleSource = (string) file_get_contents($root . '/app/services/updates_jobs/lifecycle.php');
    feature_policy_inventory_assert(
        str_contains($updateControllerSource, '$installerMutationActions')
            && substr_count($updateLifecycleSource, "feature_capability_effective_enabled('built_in_update_installer')") >= 4
            && str_contains($updateInstallSource, "feature_capability_effective_enabled('built_in_update_installer')")
            && str_contains($updateControllerSource, 'name="update_action" value="force_check"')
            && str_contains($updateControllerSource, 'name="update_action" value="autoupdate_dry_run"'),
        'Built-in Update Installer OFF must block install/resume/rollback mutation boundaries while preserving read-only checks and dry runs.'
    );

    $databaseMaintenanceDefinition = $definitions['advanced_database_maintenance'] ?? [];
    feature_policy_inventory_assert(
        ($databaseMaintenanceDefinition['group'] ?? '') === 'admin_tools'
            && ($databaseMaintenanceDefinition['routes'] ?? []) === []
            && ($databaseMaintenanceDefinition['data_disable_policy'] ?? '') === 'preserve',
        'Advanced Database Maintenance must action-gate mixed maintenance routes so read-only inspection remains available.'
    );
    $databaseControllerSource = (string) file_get_contents($root . '/app/controllers/admin_database_maintenance.php');
    $databaseViewSource = (string) file_get_contents($root . '/app/views/admin_database_maintenance.php');
    feature_policy_inventory_assert(
        substr_count($databaseControllerSource, 'admin_database_maintenance_require_mutation_enabled()') >= 5
            && str_contains($databaseControllerSource, '$dryRun = !empty($_POST[\'dry_run\'])')
            && str_contains($databaseViewSource, "feature_capability_effective_enabled('advanced_database_maintenance')")
            && str_contains($databaseViewSource, 'name="dry_run" value="1"'),
        'Advanced Database Maintenance OFF must block live cleanup/repair/ANALYZE/OPTIMIZE while keeping inspection and dry-run planning visible.'
    );

    $pictureGameDefinition = $definitions['picture_game'] ?? [];
    feature_policy_inventory_assert(
        ($pictureGameDefinition['dependencies'] ?? []) === ['image_voting'],
        'Picture Game must declare Image Voting as its canonical capability dependency.'
    );

    $pictureGameSources = [
        (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_actions.php'),
        (string) file_get_contents($root . '/app/services/admin_dashboard.php'),
    ];
    feature_policy_inventory_assert(
        !str_contains(implode("\n", $pictureGameSources), "feature_flag_enabled('picture_game') && feature_flag_enabled('image_voting')")
            && str_contains(implode("\n", $pictureGameSources), "feature_capability_effective_enabled('picture_game')"),
        'Picture Game core readiness checks must use the canonical effective capability state.'
    );

    $appSettingsSource = (string) file_get_contents($root . '/app/services/app_settings.php');
    foreach (['dev_mode_enabled', 'set_dev_mode_enabled'] as $functionName) {
        feature_policy_inventory_assert(
            str_contains($appSettingsSource, 'function ' . $functionName . '('),
            'Existing diagnostics domain accessor must remain available to the canonical policy adapter: ' . $functionName
        );
    }

    $knownMasterSettings = [
        'gallery_trash_enabled',
        'thumbnail_background_warmup_enabled',
        'dev_mode_enabled',
        'application_autoupdate_enabled',
        'remote_favicon_discovery_enabled',
    ];
    $appTreeSource = '';
    foreach (feature_policy_inventory_php_files($root . '/app') as $path) {
        $appTreeSource .= "\n" . (string) file_get_contents($path);
    }
    foreach ($knownMasterSettings as $settingKey) {
        feature_policy_inventory_assert(
            str_contains($appTreeSource, $settingKey),
            'Known canonical global master setting disappeared and must be re-inventoried: ' . $settingKey
        );
    }

    echo "Feature policy ownership inventory tests passed.\n";
}
