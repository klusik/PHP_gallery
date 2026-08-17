<?php

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
$controller = (string) file_get_contents($root . '/app/controllers/smart_galleries.php');
$publicCards = (string) file_get_contents($root . '/app/controllers/public_gallery_cards.php');
$dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
$seoGuard = (string) file_get_contents($root . '/app/services/seo_request_guard.php');
$downloads = (string) file_get_contents($root . '/app/services/downloads.php');
$downloadController = (string) file_get_contents($root . '/app/controllers/downloads.php');
$sidePanel = (string) file_get_contents($root . '/public/assets/gallery-modules/admin-side-panel.js');
$lightbox = (string) file_get_contents($root . '/public/assets/gallery-modules/lightbox.js');
$lightboxView = (string) file_get_contents($root . '/app/controllers/public_gallery_lightbox.php');
$migration = (string) file_get_contents($root . '/database/migrations/202608170001_smart_gallery_presentation.php');

smart_gallery_public_contract_assert(str_contains($service, 'gallery_is_public_listed($sourceGallery)') && str_contains($service, 'visitor_can_access_gallery($sourceGallery)'), 'Public Smart Gallery source membership intersects listing and visitor access policy.');
smart_gallery_public_contract_assert(str_contains($service, "AND i.visibility = 'public'") && str_contains($service, 'visitor_can_access_nsfw_content()'), 'Public Smart Gallery image membership enforces public visibility and NSFW policy.');
smart_gallery_public_contract_assert(str_contains($service, 'function smart_gallery_result_query') && str_contains($service, 'smart_gallery_result_query($gallery, $publicOnly)'), 'Counts and row queries share one canonical result-query builder.');
smart_gallery_public_contract_assert(str_contains($service, ', i.id \' . $direction'), 'Smart Gallery ordering always includes a stable image-id tie breaker.');
smart_gallery_public_contract_assert(str_contains($service, 'const SMART_GALLERY_LIGHTBOX_MAX_WINDOW = 80;') && str_contains($service, 'const SMART_GALLERY_QUERY_MAX_PAGE_SIZE = 200;'), 'Smart Gallery metadata windows and database pages are hard bounded.');

smart_gallery_public_contract_assert(str_contains($controller, 'function cms_smart_gallery_lightbox_data()') && str_contains($controller, 'smart_gallery_lightbox_fetch_images($gallery, true'), 'Public Smart Gallery lightbox uses the authorized bounded lazy endpoint.');
smart_gallery_public_contract_assert(str_contains($controller, 'data-lightbox-total="') && str_contains($controller, 'data-lightbox-endpoint="'), 'Public Smart Gallery page exposes total ordering metadata without embedding every image row.');
smart_gallery_public_contract_assert(str_contains($controller, '$offset + $index') && str_contains($controller, '$offset + $rowIndex'), 'Rendered cards and lazy metadata share global ordered indexes across pagination boundaries.');
smart_gallery_public_contract_assert(str_contains($dispatch, "'smart_gallery_lightbox_data'") && str_contains($dispatch, 'cms_smart_gallery_lightbox_data'), 'Smart Gallery lazy lightbox endpoint is routed explicitly.');
smart_gallery_public_contract_assert(substr_count($dispatch, "'smart_gallery_lightbox_data'") >= 2, 'Smart Gallery lazy lightbox endpoint is also covered by public route policy.');
smart_gallery_public_contract_assert(str_contains($seoGuard, "'smart_gallery' => ['slug', 'photo_page', 'view_as']"), 'Anonymous Smart Gallery pages allow their canonical route and pagination parameters through the SEO request guard.');
smart_gallery_public_contract_assert(str_contains($seoGuard, "'smart_gallery_lightbox_data' => ['id', 'limit', 'offset', 'view_as']"), 'Anonymous Smart Gallery lazy-lightbox requests allow their bounded window parameters through the SEO request guard.');
smart_gallery_public_contract_assert(str_contains($seoGuard, "'download_smart_gallery' => ['id']"), 'Anonymous Smart Gallery downloads allow the trusted Smart Gallery id route parameter through the SEO request guard.');
smart_gallery_public_contract_assert(str_contains($seoGuard, "if (\$page === 'smart_gallery')") && str_contains($seoGuard, "url_for('smart_gallery', ['slug' => \$slug])"), 'Smart Gallery direct pages emit their clean canonical URL.');
smart_gallery_public_contract_assert(str_contains($controller, 'class="hero-meta"><div class="hero-actions"') && str_contains($controller, 'hero-download-button'), 'Smart Gallery download action uses the established compact hero action container instead of stretching across the hero.');

smart_gallery_public_contract_assert(str_contains($migration, 'ADD COLUMN presentation_json MEDIUMTEXT NULL'), 'Presentation overrides are persisted by an additive migration.');
smart_gallery_public_contract_assert(str_contains($controller, 'data-smart-gallery-presentation-toggle') && str_contains($controller, 'data-smart-gallery-presentation-fields'), 'Admin editor exposes presentation inheritance and override controls.');
smart_gallery_public_contract_assert(str_contains($controller, 'presentation_card_layout') && str_contains($service, "'card_layout' => theme_gallery_description_layout()"), 'Smart Gallery presentation reuses the canonical Theme gallery-card layout default and Admin control.');
smart_gallery_public_contract_assert(str_contains($publicCards, 'is-gallery-description-') && str_contains($publicCards, '$presentation[\'thumbnail_rendering_mode\']'), 'Placed Smart Gallery cards honor effective card layout and thumbnail renderer settings.');
smart_gallery_public_contract_assert(str_contains($controller, 'smart_gallery_render_image_cards($previewImages'), 'Admin preview renders real result cards through the same presentation renderer.');
smart_gallery_public_contract_assert(str_contains($controller, 'pagination_grid_columns_class([\'columns\' => $columns, \'grid_columns_enabled\' => true])'), 'Smart Gallery image grids explicitly enable the configured column-count CSS class for both public rendering and Admin preview.');
smart_gallery_public_contract_assert(str_contains($sidePanel, "workflow.name === 'smart-gallery'") && str_contains($sidePanel, 'submitAdminSmartGalleryPanelForm'), 'Smart Gallery Admin editing is enhanced inside the existing side panel.');
smart_gallery_public_contract_assert(str_contains($sidePanel, "String(actionUrl.searchParams.get('page') || '') === 'admin_smart_galleries'"), 'Smart Gallery side-panel POSTs remain on the current browser origin for local host aliases.');

smart_gallery_public_contract_assert(str_contains($downloadController, 'function cms_download_smart_gallery()') && str_contains($downloads, 'function build_smart_gallery_zip'), 'Smart Gallery download has an authorized controller and server-side archive service.');
smart_gallery_public_contract_assert(str_contains($downloads, 'if ($total > SMART_GALLERY_ZIP_MAX_IMAGES)') && str_contains($downloads, 'smart_gallery_zip_max_source_bytes()'), 'Smart Gallery ZIP creation has explicit image-count and aggregate source-byte guards.');
smart_gallery_public_contract_assert(!str_contains($controller, 'folder_path AS download_url'), 'Public Smart Gallery controller never serializes filesystem paths as download URLs.');

smart_gallery_public_contract_assert(str_contains($lightboxView, 'bool $slideshowAllowed = true'), 'Normal gallery lightbox keeps slideshow enabled by default.');
smart_gallery_public_contract_assert(str_contains($lightbox, 'lightboxPendingWindows.has(key)') && str_contains($lightbox, 'lightboxPendingWindows.delete(key)'), 'Shared lazy lightbox client deduplicates adjacent metadata requests and releases failed/completed windows.');
smart_gallery_public_contract_assert(str_contains($lightbox, "return false;") && str_contains($lightbox, "signal: controller.signal"), 'Shared lazy lightbox client fails closed for metadata fetch errors and aborts stale work on teardown.');
smart_gallery_public_contract_assert(str_contains($lightbox, "overlay.dataset.lightboxSlideshowEnabled !== '0'"), 'Lightbox client honors per-Smart-Gallery slideshow disablement without changing default behavior.');

fwrite(STDOUT, "Smart Gallery public contract tests passed.\n");
