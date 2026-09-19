<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/smart_gallery_public_contract_test.php
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
/** Source-level integration contracts for public Smart Gallery access, lightbox, Admin panel, and downloads. */

declare(strict_types=1);

/** Fail this standalone test with a concise label. */
function smart_gallery_public_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/services/smart_galleries.php');
$model = (string) file_get_contents($root . '/app/models/smart_galleries.php');
$controller = (string) file_get_contents($root . '/app/controllers/smart_galleries.php');
$smartGalleryView = (string) file_get_contents($root . '/app/views/smart_galleries.php');
$smartGalleryRenderSource = $controller . "\n" . $smartGalleryView;
$publicCards = (string) file_get_contents($root . '/app/controllers/public_gallery_cards.php') . "\n" . (string) file_get_contents($root . '/app/views/public_gallery_cards.php');
$dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
$featureRoutes = (string) file_get_contents($root . '/app/services/feature_flags/routes.php');
$earlyRuntime = (string) file_get_contents($root . '/app/early_runtime.php');
$httpHelpers = (string) file_get_contents($root . '/app/controllers/http_helpers.php');
$helpersRequest = (string) file_get_contents($root . '/app/helpers_request.php');
$seoGuard = (string) file_get_contents($root . '/app/services/seo_request_guard.php');
$downloads = (string) file_get_contents($root . '/app/services/downloads.php');
$downloadController = (string) file_get_contents($root . '/app/controllers/downloads.php');
$sidePanel = (string) file_get_contents($root . '/public/assets/gallery-modules/admin-side-panel.js');
$smartGalleryJs = (string) file_get_contents($root . '/public/assets/gallery-modules/admin-smart-galleries.js');
$lightbox = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
$lightboxView = (string) file_get_contents($root . '/app/controllers/public_gallery_lightbox.php');
$lightboxMarkup = (string) file_get_contents($root . '/app/views/public_gallery_lightbox.php');
$publicSharedCss = (string) file_get_contents($root . '/public/assets/styles/public-shared.css');
$migration = (string) file_get_contents($root . '/database/migrations/202608170001_smart_gallery_presentation.php');

smart_gallery_public_contract_assert(str_contains($service, 'gallery_is_public_listed($sourceGallery)') && str_contains($service, 'visitor_can_access_gallery($sourceGallery)'), 'Public Smart Gallery source membership intersects listing and visitor access policy.');
smart_gallery_public_contract_assert(str_contains($model, "AND i.visibility = 'public'") && str_contains($service, 'visitor_can_access_nsfw_content()'), 'Public Smart Gallery image membership keeps visibility persistence in the model while NSFW access policy remains service-owned.');
smart_gallery_public_contract_assert(str_contains($model, 'function smart_gallery_model_result_query') && str_contains($model, 'smart_gallery_model_result_query($rules, $accessibleGalleryIds, $publicOnly, $allowNsfw'), 'Counts, row queries, card summaries, and membership checks share one model-owned canonical result-query builder.');
smart_gallery_public_contract_assert(str_contains($model, "', i.id ' . \$directionSql"), 'Smart Gallery ordering always includes a stable image-id tie breaker in the model allowlist.');
smart_gallery_public_contract_assert(str_contains($service, 'const SMART_GALLERY_LIGHTBOX_MAX_WINDOW = 80;') && str_contains($service, 'const SMART_GALLERY_QUERY_MAX_PAGE_SIZE = 200;'), 'Smart Gallery metadata windows and database pages are hard bounded.');

smart_gallery_public_contract_assert(str_contains($controller, 'function cms_smart_gallery_lightbox_data()') && str_contains($controller, 'smart_gallery_lightbox_fetch_images($gallery, true'), 'Public Smart Gallery lightbox uses the authorized bounded lazy endpoint.');
smart_gallery_public_contract_assert(str_contains($smartGalleryView, 'data-lightbox-total="') && str_contains($smartGalleryView, 'data-lightbox-endpoint="'), 'Public Smart Gallery page exposes total ordering metadata without embedding every image row.');
smart_gallery_public_contract_assert(str_contains($controller, '$offset + $index') && str_contains($controller, '$offset + $rowIndex'), 'Rendered cards and lazy metadata share global ordered indexes across pagination boundaries.');
smart_gallery_public_contract_assert(str_contains($service, "'source_gallery_visible' => true") && str_contains($service, "'source_gallery_visible', 'map_enabled', 'lightbox_enabled'"), 'Smart Gallery source context is an inherited presentation preference and supports explicit overrides.');
smart_gallery_public_contract_assert(str_contains($service, "'map_enabled' => true") && str_contains($service, "'source_gallery_visible', 'map_enabled', 'lightbox_enabled'") && str_contains($service, "'maps' => \$capabilityEnabled('gallery_maps')"), 'Smart Gallery aggregate map is an inherited preference suppressed by the site-wide GPS map capability.');
smart_gallery_public_contract_assert(str_contains($controller, "'source_gallery_visible' => isset(\$_POST['presentation_source_gallery_visible'])") && str_contains($smartGalleryView, 'presentation_source_gallery_visible'), 'Admin Smart Gallery presentation persists a source-gallery visibility preference.');
smart_gallery_public_contract_assert(str_contains($controller, "'map_enabled' => isset(\$_POST['presentation_map_enabled'])") && str_contains($smartGalleryView, 'presentation_map_enabled'), 'Admin Smart Gallery presentation persists the aggregate-map preference.');
smart_gallery_public_contract_assert(str_contains($controller, "'source_gallery' => \$sourceGalleryContext") && str_contains($smartGalleryView, 'smart-gallery-source-badge') && str_contains($smartGalleryView, 'data-smart-gallery-source-link'), 'Smart Gallery cards expose localized physical-source context through a dedicated provenance badge.');
smart_gallery_public_contract_assert(str_contains($service, 'function smart_gallery_source_contexts') && str_contains($service, "'breadcrumb_compact'") && str_contains($smartGalleryView, 'breadcrumb_compact'), 'Smart Gallery source provenance includes structural breadcrumbs with a compact card representation.');
smart_gallery_public_contract_assert(str_contains($controller, '$sourceGalleryContext') && str_contains($lightboxView, "'source_gallery_title'") && str_contains($lightboxView, "'source_gallery_breadcrumb'") && str_contains($lightboxView, "'source_gallery_url'"), 'Initial Smart Gallery lightbox attributes carry breadcrumb-aware physical-source context.');
smart_gallery_public_contract_assert(str_contains($controller, '$imageHasPublicGps = gallery_allows_gps_maps($source) && image_has_gps($image);') && str_contains($controller, 'image_map_point($image, $source, true, $bundle)') && preg_match("~\\\$imageMapPoint,\\s*'data-lightbox-image'~", $controller) === 1, 'Initial Smart Gallery lightbox items expose the same source-gallery-authorized GPS map point contract as lazy items.');
smart_gallery_public_contract_assert(str_contains($controller, "!empty(\$presentation['source_gallery_visible'])") && str_contains($controller, 'gallery_lightbox_json_item('), 'Lazy Smart Gallery lightbox metadata opts into source context according to the effective presentation.');
smart_gallery_public_contract_assert(str_contains($lightbox, 'card.dataset.sourceGalleryTitle') && str_contains($lightbox, 'card.dataset.sourceGalleryBreadcrumb') && str_contains($lightbox, 'data-lightbox-source-gallery') && str_contains($lightboxMarkup, 'data-source-gallery-breadcrumb'), 'Shared lightbox hydrates and renders breadcrumb-aware source-gallery context only when supplied by the active source item.');
smart_gallery_public_contract_assert(str_contains($lightbox, '[data-smart-gallery-source-link]'), 'Clicking a Smart Gallery source badge navigates to the physical gallery instead of being intercepted as a lightbox-open gesture.');
smart_gallery_public_contract_assert(str_contains($model, 'function smart_gallery_model_image_position') && str_contains($model, 'smart_gallery_model_order_column_sql') && str_contains($model, 'MySQL 5.7 and MariaDB 10.2'), 'Smart Gallery map target navigation resolves canonical indexes server-side without requiring SQL window functions.');
smart_gallery_public_contract_assert(str_contains($service, 'function smart_gallery_image_position') && str_contains($controller, '$targetImageId = max(0, (int) ($_GET[\'target_image_id\'] ?? 0));') && str_contains($controller, '\'target_index\' => $targetIndex'), 'Smart Gallery lazy metadata endpoint can resolve one map-selected image to its exact global ordered index.');
smart_gallery_public_contract_assert(str_contains($service, "'smart_gallery_id'") && str_contains($service, "'source_gallery_title'") && str_contains($service, "'source_gallery_breadcrumb'") && str_contains($service, "'source_gallery_url'") && str_contains($service, "'thumb'"), 'Aggregate Smart Gallery markers carry breadcrumb-aware Smart Gallery provenance and a lazy popup thumbnail URL.');
smart_gallery_public_contract_assert(str_contains($lightbox, 'data-map-smart-gallery-context') && str_contains($lightbox, 'smartGalleryContext') && str_contains($lightbox, 'source_gallery_url') && str_contains($lightbox, 'map-popup-source-gallery'), 'Shared map popup retains Smart Gallery lightbox navigation while exposing a separate source-gallery link.');
smart_gallery_public_contract_assert(str_contains($publicSharedCss, '.smart-gallery-source-badge') && str_contains($publicSharedCss, '.lightbox-source-gallery'), 'Source-gallery context has dedicated public card and lightbox styling.');
smart_gallery_public_contract_assert(str_contains($model, 'function smart_gallery_model_count_map_images') && str_contains($model, 'function smart_gallery_model_query_map_images') && substr_count($model, 'smart_gallery_model_result_query($rules, $accessibleGalleryIds, $publicOnly, $allowNsfw') >= 4, 'Aggregate Smart Gallery map count/projection reuse the canonical model-owned result-query compiler.');
$mapProjectionStart = strpos($model, 'function smart_gallery_model_query_map_images');
$mapProjectionEnd = $mapProjectionStart === false ? false : strpos($model, '/**', $mapProjectionStart + 1);
$mapProjectionSource = $mapProjectionStart === false ? '' : substr($model, $mapProjectionStart, $mapProjectionEnd === false ? null : $mapProjectionEnd - $mapProjectionStart);
smart_gallery_public_contract_assert(str_contains($mapProjectionSource, "SELECT i.id, i.gallery_id, i.filename, i.url_slug, i.title, i.description, i.content_language, i.gps_lat, i.gps_lng") && !str_contains($mapProjectionSource, 'SELECT i.*'), 'Aggregate Smart Gallery map query uses an explicit GPS projection instead of SELECT *.');
smart_gallery_public_contract_assert(str_contains($service, 'const SMART_GALLERY_MAP_MAX_POINTS = 10000;') && str_contains($service, 'function smart_gallery_map_payload') && str_contains($service, 'gallery_allows_gps_maps($source, $lookup)') && str_contains($service, 'image_map_point($image, $source, false)'), 'Aggregate map service applies a hard point cap, inherited source GPS policy, and canonical map DTO creation before Stage 5 provenance enrichment.');
smart_gallery_public_contract_assert(str_contains($service, 'function smart_gallery_all_source_gallery_rows') && str_contains($service, 'smart_gallery_all_source_gallery_rows()') && str_contains($service, 'smart_gallery_source_galleries_by_ids'), 'Physical gallery rows are request-cached and reused instead of introducing aggregate-map source-gallery N+1 lookups.');
smart_gallery_public_contract_assert(str_contains($controller, 'function cms_smart_gallery_map_data()') && str_contains($controller, "empty(\$presentation['map_enabled'])") && str_contains($controller, 'smart_gallery_map_payload($gallery, true, $sourceGalleryId)'), 'Public Smart Gallery map endpoint enforces effective presentation before building the authorized aggregate payload.');
smart_gallery_public_contract_assert(str_contains($service, 'function smart_gallery_map_query_context') && str_contains($service, 'function smart_gallery_map_diagnostics') && str_contains($service, 'function smart_gallery_has_map_payload') && str_contains($service, '\'semantic\' => $mapGalleryIds !== [] ? smart_gallery_query_semantics'), 'Smart Gallery hero map diagnostics and payload share one authorized inherited-GPS query context.');
smart_gallery_public_contract_assert(str_contains($controller, '$mapDiagnostics = smart_gallery_map_diagnostics($gallery, true, $sourceGalleryId);') && str_contains($controller, '$mapAvailable = !empty($mapDiagnostics[\'available\']);') && str_contains($controller, '\'map_url\' => $mapUrl'), 'Public Smart Gallery page exposes a lazy aggregate-map action only when count-only authorized GPS diagnostics report points.');
smart_gallery_public_contract_assert(str_contains($smartGalleryView, 'smart-gallery-map-button') && str_contains($smartGalleryView, 'data-gallery-map-url=') && str_contains($smartGalleryView, 'smart_gallery.show_map'), 'Smart Gallery hero renders the aggregate map through the existing shared gallery-map interaction contract.');
smart_gallery_public_contract_assert(str_contains($smartGalleryView, 'data-lightbox-gallery-map-url=') && str_contains($smartGalleryView, 'data-lightbox-maps-enabled=') && str_contains($controller, 'render_lightbox(') && str_contains($controller, '$mapAvailable,') && str_contains($controller, '$mapUrl,'), 'Smart Gallery lightbox receives the same aggregate map route as the hero action.');
smart_gallery_public_contract_assert(str_contains($model, 'function smart_gallery_model_source_summary_rows') && str_contains($model, 'GROUP BY i.gallery_id ORDER BY image_count DESC, i.gallery_id ASC') && str_contains($model, 'smart_gallery_model_result_query($rules, $accessibleGalleryIds, $publicOnly, $allowNsfw'), 'Smart Gallery source summary is a model-owned grouped projection over the canonical result predicate.');
smart_gallery_public_contract_assert(str_contains($service, 'function smart_gallery_source_summary') && str_contains($service, 'if ($sourceGalleryId > 0)') && str_contains($service, '? [$sourceGalleryId] : []'), 'Temporary source filtering narrows the authorized gallery-id scope instead of compiling a parallel image predicate.');
smart_gallery_public_contract_assert(str_contains($controller, "\$_GET['source_gallery_id']") && str_contains($controller, 'smart_gallery_count_images($gallery, true, $sourceGalleryId)') && str_contains($controller, 'smart_gallery_query_images($gallery, true, $limit, $offset, $sourceGalleryId)') && str_contains($controller, 'smart_gallery_lightbox_fetch_images($gallery, true, $offset, $limit, $sourceGalleryId)') && str_contains($controller, 'smart_gallery_map_payload($gallery, true, $sourceGalleryId)'), 'Source filter request state is propagated consistently through Smart Gallery count, grid, lazy lightbox, and map semantics.');
smart_gallery_public_contract_assert(str_contains($smartGalleryView, 'smart-gallery-source-summary') && str_contains($smartGalleryView, 'smart-gallery-source-filter-chip') && str_contains($smartGalleryView, 'smart-gallery-source-filter-origin') && str_contains($publicSharedCss, '.smart-gallery-source-filter-list'), 'Public Smart Gallery exposes grouped source provenance, source filters, and direct source-gallery navigation with dedicated styling.');
smart_gallery_public_contract_assert(str_contains($helpersRequest, '$cleanParams = $params;') && str_contains($helpersRequest, "unset(\$cleanParams['slug'], \$cleanParams['photo_page'])") && str_contains($helpersRequest, 'http_build_query($cleanParams)'), 'Clean Smart Gallery URLs preserve temporary source-filter query state while keeping slug and photo pagination in the clean path.');
smart_gallery_public_contract_assert(str_contains($dispatch, "'smart_gallery_map_data'") && str_contains($dispatch, 'cms_smart_gallery_map_data'), 'Smart Gallery aggregate map endpoint is routed explicitly.');
smart_gallery_public_contract_assert(str_contains($featureRoutes, "'smart_gallery_map_data'") && str_contains($featureRoutes, "['smart_galleries', 'gallery_maps']"), 'Smart Gallery aggregate map route requires both Smart Galleries and EXIF GPS maps capabilities.');
smart_gallery_public_contract_assert(str_contains($seoGuard, "'smart_gallery_map_data' => ['id', 'source_gallery_id', 'view_as']") && str_contains($earlyRuntime, "'smart_gallery_map_data'") && str_contains($httpHelpers, "'smart_gallery_map_data'"), 'Smart Gallery aggregate map endpoint is classified consistently as bounded public JSON across request guard and failure handling.');
smart_gallery_public_contract_assert(str_contains($dispatch, "'smart_gallery_lightbox_data'") && str_contains($dispatch, 'cms_smart_gallery_lightbox_data'), 'Smart Gallery lazy lightbox endpoint is routed explicitly.');
smart_gallery_public_contract_assert(substr_count($dispatch, "'smart_gallery_lightbox_data'") >= 2, 'Smart Gallery lazy lightbox endpoint is also covered by public route policy.');
smart_gallery_public_contract_assert(str_contains($seoGuard, "'smart_gallery' => ['slug', 'photo_page', 'source_gallery_id', 'view_as']"), 'Anonymous Smart Gallery pages allow canonical route, pagination, and temporary source-filter parameters through the SEO request guard.');
smart_gallery_public_contract_assert(str_contains($seoGuard, "'smart_gallery_lightbox_data' => ['id', 'limit', 'offset', 'target_image_id', 'source_gallery_id', 'view_as']"), 'Anonymous Smart Gallery lazy-lightbox requests allow bounded window and temporary source-filter parameters through the SEO request guard.');
smart_gallery_public_contract_assert(str_contains($seoGuard, "'download_smart_gallery' => ['id', 'capability']"), 'Anonymous Smart Gallery downloads allow the trusted Smart Gallery id route parameter through the SEO request guard.');
smart_gallery_public_contract_assert(str_contains($seoGuard, "if (\$page === 'smart_gallery')") && str_contains($seoGuard, "url_for('smart_gallery', ['slug' => \$slug])"), 'Smart Gallery direct pages emit their clean canonical URL.');
smart_gallery_public_contract_assert(str_contains($smartGalleryView, 'class="hero-meta"><div class="hero-actions"') && str_contains($smartGalleryView, 'hero-download-button'), 'Smart Gallery download action uses the established compact hero action container instead of stretching across the hero.');

smart_gallery_public_contract_assert(str_contains($migration, 'ADD COLUMN presentation_json MEDIUMTEXT NULL'), 'Presentation overrides are persisted by an additive migration.');
smart_gallery_public_contract_assert(str_contains($service, 'function smart_gallery_mutation_schema_status(): array') && str_contains($service, "'sort_direction', 'presentation_json'") && str_contains($service, 'smart_gallery_mutation_schema_status(),') && !str_contains($service, 'smart_gallery_presentation_schema_ready()'), 'Smart Gallery write schema requires presentation_json before a current-version save can proceed while read compatibility remains separate.');
smart_gallery_public_contract_assert(str_contains($model, 'function smart_gallery_model_save(array $definition, int $id): int') && substr_count($model, 'presentation_json') >= 3 && !str_contains($model, '$presentationReady'), 'Smart Gallery model writes presentation_json unconditionally after the service mutation guard.');
smart_gallery_public_contract_assert(str_contains($controller, 'thumbnail_bound_pair_from_post(\'presentation_thumbnail\', $_POST)') && str_contains($controller, 'smart_gallery_presentation_preferences($gallery)'), 'Smart Gallery Admin input reuses canonical thumbnail-bound normalization and loads editable preference state separately from runtime presentation.');
smart_gallery_public_contract_assert(str_contains($smartGalleryView, 'type="range"') && str_contains($smartGalleryView, 'data-smart-gallery-grid-columns') && str_contains($smartGalleryView, 'render_admin_thumbnail_bound_slider('), 'Smart Gallery presentation uses range sliders and the shared dual thumbnail-bound control.');
smart_gallery_public_contract_assert(str_contains($smartGalleryView, 'data-smart-gallery-presentation-toggle') && str_contains($smartGalleryView, 'data-smart-gallery-presentation-fields'), 'Admin editor exposes presentation inheritance and override controls.');
smart_gallery_public_contract_assert(str_contains($smartGalleryJs, 'setupSmartGalleryPresentation(form)') && str_contains($smartGalleryJs, 'data-smart-gallery-pagination-toggle') && str_contains($smartGalleryJs, 'data-smart-gallery-items-per-page'), 'Smart Gallery browser enhancement synchronizes presentation visibility, pagination dependency, and live items-per-page feedback.');
smart_gallery_public_contract_assert(str_contains($smartGalleryJs, "classList.toggle('is-inactive', !paginationEnabled)") && !str_contains($smartGalleryJs, 'rowsControl.disabled ='), 'Pagination-off styling never disables the rows form field, so the stored row preference survives unrelated saves.');
smart_gallery_public_contract_assert(str_contains($smartGalleryView, 'presentation_card_layout') && str_contains($service, "'card_layout' => theme_gallery_description_layout()"), 'Smart Gallery presentation reuses the canonical Theme gallery-card layout default and Admin control.');
smart_gallery_public_contract_assert(str_contains($publicCards, 'is-gallery-description-') && str_contains($publicCards, '$presentation[\'thumbnail_rendering_mode\']'), 'Placed Smart Gallery cards honor effective card layout and thumbnail renderer settings.');
smart_gallery_public_contract_assert(str_contains($controller, 'smart_gallery_image_cards_view_model($previewImages') && str_contains($smartGalleryView, "view_render_smart_gallery_image_cards((array) \$viewModel['preview_cards'])"), 'Admin preview renders real result cards through the same presentation renderer.');
smart_gallery_public_contract_assert(str_contains($controller, 'pagination_grid_columns_class([\'columns\' => $columns, \'grid_columns_enabled\' => true])'), 'Smart Gallery image grids explicitly enable the configured column-count CSS class for both public rendering and Admin preview.');
smart_gallery_public_contract_assert(str_contains($sidePanel, "workflow.name === 'smart-gallery'") && str_contains($sidePanel, 'submitAdminSmartGalleryPanelForm'), 'Smart Gallery Admin editing is enhanced inside the existing side panel.');
smart_gallery_public_contract_assert(str_contains($sidePanel, "String(actionUrl.searchParams.get('page') || '') === 'admin_smart_galleries'"), 'Smart Gallery side-panel POSTs remain on the current browser origin for local host aliases.');

smart_gallery_public_contract_assert(str_contains($downloadController, 'function cms_download_smart_gallery()') && str_contains($downloads, 'function build_smart_gallery_zip'), 'Smart Gallery download has an authorized controller and server-side archive service.');
smart_gallery_public_contract_assert(str_contains($downloads, 'if ($total > max(1, (int) cms_runtime_limit(\'download.smart_gallery_zip_max_images\')))') && str_contains($downloads, 'smart_gallery_zip_max_source_bytes()'), 'Smart Gallery ZIP creation has explicit image-count and aggregate source-byte guards.');
smart_gallery_public_contract_assert(!str_contains($controller, 'folder_path AS download_url'), 'Public Smart Gallery controller never serializes filesystem paths as download URLs.');

smart_gallery_public_contract_assert(str_contains($lightboxView, 'bool $slideshowAllowed = true'), 'Normal gallery lightbox keeps slideshow enabled by default.');
smart_gallery_public_contract_assert(str_contains($lightbox, 'lightboxPendingWindows.has(key)') && str_contains($lightbox, 'lightboxPendingWindows.delete(key)'), 'Shared lazy lightbox client deduplicates adjacent metadata requests and releases failed/completed windows.');
smart_gallery_public_contract_assert(str_contains($lightbox, "return false;") && str_contains($lightbox, "signal: metadataSignal") && str_contains($lightbox, 'cancelLightboxMetadataRequests();'), 'Shared lazy lightbox client fails closed for metadata fetch errors and aborts stale work when the viewer closes or tears down.');
smart_gallery_public_contract_assert(str_contains($lightbox, "overlay.dataset.lightboxSlideshowEnabled !== '0'"), 'Lightbox client honors per-Smart-Gallery slideshow disablement without changing default behavior.');

fwrite(STDOUT, "Smart Gallery public contract tests passed.\n");
