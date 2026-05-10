<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/public_gallery.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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
 *   2026-05-04
 */

declare(strict_types=1);

/**
 * Public gallery controller model.
 * 
 * This module renders the public home page, gallery pages, gallery access gate, share redirects, gallery cards, lightbox markup, and public inline admin edit forms.
 */

function cms_home(): void
{
    public_render_profile_start('home');
    // $listingCondition stores an intermediate value used by the surrounding gallery workflow.
    $listingCondition = public_gallery_listing_condition('g');
    // Variable $stmt stores this steps working value.
    $galleries = public_render_profile_db('home_gallery_query', static function () use ($listingCondition): array {
        // $stmt stores the prepared home gallery query.
        $stmt = db()->prepare("SELECT g.*, COUNT(i.id) AS image_count
            FROM galleries g
            LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
            WHERE $listingCondition AND g.parent_id IS NULL
            GROUP BY g.id
            ORDER BY g.sort_order, g.title");
        $stmt->execute();
        return $stmt->fetchAll();
    });
    // Variable $paginationSettings stores this steps working value.
    $paginationSettings = main_page_gallery_grid_settings();
    // Variable $galleryPagination stores this steps working value.
    $galleryPagination = pagination_model(count($galleries), pagination_current_page('gallery_page'), (int) $paginationSettings['columns'], (int) $paginationSettings['rows'], 'gallery_page', null, static fn (int $pageNumber): string => pagination_home_gallery_clean_url($pageNumber));
    if (!empty($paginationSettings['enabled'])) {
        // $galleries stores the public home gallery list after optional pagination slicing.
        $galleries = pagination_slice_items($galleries, $galleryPagination);
    }
    render_header(site_name());
    if ($galleries) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $galleryPagination : [], 'Gallery pages');
        echo '<section class="grid gallery-list-content' . e(pagination_grid_columns_class($paginationSettings)) . '" data-back-to-top-list>';
        public_render_profile_count('rendered_subgalleries', count($galleries));
        public_render_profile_span('render_home_gallery_cards', static function () use ($galleries): void {
            foreach ($galleries as $gallery) {
                render_gallery_card($gallery, true, false, true);
            }
        });
        echo '</section>';
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $galleryPagination : [], 'Gallery pages');
        render_back_to_top_button();
        echo '</div>';
    }
    render_public_render_profile_panel();
    telemetry_append_public_script([
        'route_name' => 'home',
        'page_kind' => 'home',
    ]);
    render_footer();
}

/**
 * Handles cms gallery logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_gallery(): void
{
    public_render_profile_start('gallery');
    // Variable $anonymousPreview stores whether a logged-in admin asked to render the page with anonymous visitor rules.
    $anonymousPreview = admin_anonymous_preview_active();
    // Variable $viewer stores this steps working value.
    $viewer = $anonymousPreview ? null : (current_user_is_known_under_18() ? null : current_user());
    // Variable $gallery stores this steps working value.
    $gallery = null;
    // Variable $requestedImage stores this steps working value.
    $requestedImage = null;
    if (isset($_GET['public_path'])) {
        // $resolved stores an intermediate value used by the surrounding gallery workflow.
        $resolved = resolve_public_gallery_path((string) $_GET['public_path'], !$viewer);
        // $gallery stores an intermediate value used by the surrounding gallery workflow.
        $gallery = $resolved['gallery'];
        // $requestedImage stores an intermediate value used by the surrounding gallery workflow.
        $requestedImage = $resolved['image'];
    }
    if (!$gallery && isset($_GET['gallery_path'])) {
        try {
            // $gallery stores an intermediate value used by the surrounding gallery workflow.
            $gallery = find_gallery_by_folder_path((string) $_GET['gallery_path']);
        } catch (RuntimeException) {
            // $gallery stores an intermediate value used by the surrounding gallery workflow.
            $gallery = null;
        }
    }
    if (!$gallery && isset($_GET['slug'])) {
        try {
            // $gallery stores an intermediate value used by the surrounding gallery workflow.
            $gallery = find_gallery_by_folder_path((string) $_GET['slug']);
        } catch (RuntimeException) {
            // $gallery stores an intermediate value used by the surrounding gallery workflow.
            $gallery = null;
        }
    }
    if (!$gallery) {
        // $gallery stores an intermediate value used by the surrounding gallery workflow.
        $gallery = find_gallery_by_slug((string) ($_GET['slug'] ?? ''));
    }
    if (!$gallery || (!$viewer && !gallery_allows_direct_public_request($gallery) && !visitor_can_access_gallery($gallery))) {
        cms_not_found();
        return;
    }
    if (!$viewer && !visitor_can_access_gallery($gallery)) {
        render_gallery_access_gate($gallery);
        return;
    }
    if (!$viewer && $requestedImage && image_nsfw_restricted($requestedImage, $gallery) && !visitor_can_access_nsfw_content()) {
        render_gallery_access_gate($gallery, '', $requestedImage);
        return;
    }
    public_render_profile_set_gallery((int) $gallery['id']);
    // Variable $publicOnly stores this steps working value.
    $publicOnly = !$viewer;

    // Variable $stmt stores this steps working value.
    $sql = "SELECT i.*, COALESCE(SUM(v.vote), 0) AS score
        FROM images i
        LEFT JOIN image_votes v ON v.image_id = i.id
        WHERE i.gallery_id = ? AND i.relative_path NOT LIKE '%/%'";
    if ($publicOnly) {
        $sql .= " AND i.visibility = 'public'";
    }
    $sql .= "
        GROUP BY i.id
        ORDER BY i.sort_order, i.filename";
    $images = public_render_profile_db('gallery_image_query', static function () use ($sql, $gallery): array {
        // $stmt stores the prepared gallery image query.
        $stmt = db()->prepare($sql);
        $stmt->execute([(int) $gallery['id']]);
        return $stmt->fetchAll();
    });
    // Variable $allImages stores the complete sorted image list before optional pagination slicing.
    $allImages = $images;
    // Variable $imageIds stores this steps working value.
    $imageIds = array_map(static fn (array $image): int => (int) $image['id'], $images);
    // Variable $imageTagsById stores this steps working value.
    $imageTagsById = public_render_profile_span('image_tag_lookup', static fn (): array => tags_for_entities('image', $imageIds));
    // Variable $votesById stores this steps working value.
    $votesById = public_render_profile_span('image_vote_lookup', static fn (): array => current_votes_for_images($imageIds));
    // Variable $children stores this steps working value.
    public_render_profile_count('gallery_scan_calls');
    $children = public_render_profile_span('child_gallery_lookup', static fn (): array => child_galleries((int) $gallery['id'], $publicOnly));
    // Variable $allChildren stores the complete sorted child-gallery list before optional pagination slicing.
    $allChildren = $children;
    // Variable $mapsAllowed stores this steps working value.
    $mapsAllowed = gallery_allows_gps_maps($gallery);
    // Variable $galleryMapAvailable stores whether the map button should be shown without building the full marker payload.
    $galleryMapAvailable = $mapsAllowed ? gallery_has_map_points($gallery, $publicOnly, true) : false;
    // Variable $votingAllowed stores this steps working value.
    $votingAllowed = gallery_voting_allowed($gallery);
    // Variable $pictureGameImages stores this steps working value.
    $pictureGameImages = public_render_profile_span('picture_game_lookup', static fn (): array => picture_game_images($gallery));
    // Variable $paginationSettings stores this steps working value.
    $paginationSettings = public_render_profile_span('gallery_grid_settings', static fn (): array => gallery_effective_grid_settings($gallery));
    // Variable $galleryPaginationPath stores the gallery-level URL path used for clean pagination links.
    $galleryPaginationPath = trim((string) ($gallery['url_path'] ?? ''), '/');
    if ($galleryPaginationPath === '') {
        // $galleryPaginationPath stores a legacy folder-path fallback for installs without regenerated public paths.
        $galleryPaginationPath = trim((string) ($gallery['folder_path'] ?? ''), '/');
    }
    if ($galleryPaginationPath === '') {
        // $galleryPaginationPath stores the final slug fallback for unusual root-level gallery records.
        $galleryPaginationPath = (string) ($gallery['slug'] ?? '');
    }
    // Variable $galleryPaginationQuery stores this steps working value.
    $galleryPaginationQuery = ['page' => 'gallery', 'public_path' => $galleryPaginationPath];
    // Variable $childPagination stores this steps working value.
    $childPagination = pagination_model(count($children), pagination_current_page('gallery_page'), (int) $paginationSettings['columns'], (int) $paginationSettings['rows'], 'gallery_page', $galleryPaginationQuery, static fn (int $pageNumber): string => pagination_gallery_clean_url($gallery, $pageNumber, 'subgalleries'));
    // Variable $photoCurrentPage stores this steps working value.
    $photoCurrentPage = pagination_current_page('photo_page');
    if (!empty($paginationSettings['enabled']) && $requestedImage) {
        foreach ($images as $imageIndex => $imageCandidate) {
            if ((int) $imageCandidate['id'] === (int) $requestedImage['id']) {
                // $photoCurrentPage stores the page that contains the explicitly requested image.
                $photoCurrentPage = (int) floor($imageIndex / (int) $paginationSettings['items_per_page']) + 1;
                break;
            }
        }
    }
    // Variable $photoPagination stores this steps working value.
    $photoPagination = pagination_model(count($images), $photoCurrentPage, (int) $paginationSettings['columns'], (int) $paginationSettings['rows'], 'photo_page', $galleryPaginationQuery, static fn (int $pageNumber): string => pagination_gallery_clean_url($gallery, $pageNumber, 'photos'));
    if (!empty($paginationSettings['enabled'])) {
        // $children stores the subgallery list after sorting has already been applied by child_galleries().
        $children = pagination_slice_items($children, $childPagination);
        // $images stores the photo list after database sorting and metadata preparation have preserved order.
        $images = pagination_slice_items($images, $photoPagination);
    }
    // Variable $backgroundAssetUrl stores this steps working value.
    $backgroundAssetUrl = public_render_profile_span('background_asset_lookup', static fn (): string => gallery_background_asset_url($gallery, $publicOnly));
    // Variable $seo stores this steps working value.
    $seo = public_render_profile_span('seo_metadata_lookup', static fn (): array => public_gallery_metadata($gallery));
    ob_start();
    render_public_seo_tags($gallery, $allImages);
    render_gallery_json_ld($gallery, $images);
    append_cms_head_extras((string) ob_get_clean());
    if ($backgroundAssetUrl !== '') {
        append_cms_head_extras('<style>.theme-background-image{background-image:url("' . css_value($backgroundAssetUrl) . '");}</style>');
    }

    render_header((string) $seo['title'], $gallery, $publicOnly);
    echo '<section class="hero">';
    echo '<div class="hero-topbar">';
    render_public_gallery_branding_header($gallery, $seo, $publicOnly);
    echo '<div class="hero-meta">';
    echo '<div class="hero-actions" aria-label="Gallery actions">';
    render_public_gallery_admin_delete_form($gallery, 'hero');
    render_public_gallery_admin_edit_link($gallery, 'hero');
    render_public_gallery_admin_add_child_link($gallery, 'hero');
    echo '<a class="button hero-icon-button hero-download-button" href="' . e(url_for('download_gallery', ['id' => $gallery['id']])) . '" aria-label="Download gallery" title="Download gallery"><span aria-hidden="true">&#10515;</span><span class="visually-hidden">Download gallery</span></a>';
    if ($galleryMapAvailable) {
        echo '<button type="button" class="button secondary map-button" data-gallery-map-url="' . e(url_for('gallery_map_data', ['id' => $gallery['id']])) . '" data-gallery-map-title="' . e((string) $gallery['title']) . '">Show gallery map</button>';
    }
    if (picture_game_available($gallery, $pictureGameImages)) {
        echo '<a class="button secondary hero-icon-button hero-picture-game-button" href="' . e(url_for('picture_game', ['id' => $gallery['id']])) . '" aria-label="Play picture game" title="Play picture game"><span aria-hidden="true">&#127918;</span><span class="visually-hidden">Play picture game</span></a>';
    }
    echo '</div>';
    echo '<div class="hero-tags" aria-label="Gallery tags">';
    render_tag_list(tags_for_entity('gallery', (int) $gallery['id']));
    if ($children) {
        render_tag_list(public_render_profile_span('contained_tag_lookup', static fn (): array => contained_tags_for_gallery($gallery, $publicOnly)), 'Containing tags');
    }
    echo '</div>';
    echo '</div>';
    echo '</div>';
    render_breadcrumbs($gallery);
    echo '</section>';
    render_public_gallery_branding_separator($gallery, $publicOnly);
    render_public_gallery_preview_toolbar($gallery);
    // Variable $publicPageReorderEnabled stores whether the logged-in admin can reorder visible public-page cards.
    $publicPageReorderEnabled = current_user() && !admin_anonymous_preview_active();
    if ($children || $images) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        echo '<div class="gallery-list-content" data-back-to-top-list>';
    }
    if ($children) {
        echo '<section class="panel public-subgallery-panel" data-public-subgallery-section aria-label="Subgalleries">';
        render_public_page_reorder_toolbar('gallery', $gallery, !empty($paginationSettings['enabled']) ? $childPagination : [], count($children), count($allChildren));
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $childPagination : [], 'Subgallery pages');
        echo '<div class="grid' . e(pagination_grid_columns_class($paginationSettings)) . '" data-public-reorder-list="gallery" data-public-subgallery-grid>';
        public_render_profile_count('rendered_subgalleries', count($children));
        public_render_profile_span('render_subgallery_cards', static function () use ($children, $publicPageReorderEnabled): void {
            foreach ($children as $child) {
                render_gallery_card($child, true, $publicPageReorderEnabled && count($children) > 1, true);
            }
        });
        echo '</div>';
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $childPagination : [], 'Subgallery pages');
        echo '</section>';
    }
    // Variable $publicPhotoReorderEnabled stores whether visible photo cards should render drag handles.
    $publicPhotoReorderEnabled = $publicPageReorderEnabled && count($images) > 1;
    if ($images) {
        render_public_page_reorder_toolbar('photo', $gallery, !empty($paginationSettings['enabled']) ? $photoPagination : [], count($images), count($allImages));
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $photoPagination : [], 'Photo pages');
        echo '<section class="grid gallery-image-grid' . e(pagination_grid_columns_class($paginationSettings)) . '" data-public-reorder-list="photo" data-gallery-image-list>';
    }
    public_render_profile_count('rendered_images', count($images));
    public_render_profile_span('render_image_cards', static function () use ($images, $gallery, $publicOnly, $mapsAllowed, $imageTagsById, $votesById, $votingAllowed, $paginationSettings, $photoPagination, $publicPhotoReorderEnabled): void {
    foreach ($images as $index => $image) {
        // Variable $imageNeedsNsfwGate stores whether this card must avoid exposing thumbnail/media URLs.
        $imageNeedsNsfwGate = $publicOnly && image_nsfw_restricted($image, $gallery) && !visitor_can_access_nsfw_content();
        if ($imageNeedsNsfwGate) {
            echo '<article class="image-card nsfw-card"><div class="image-stage nsfw-stage"><a class="nsfw-placeholder" href="' . e(image_public_url($image, $gallery)) . '"><strong>18+ photo</strong><span>Confirm your age to view this restricted photo.</span></a></div>';
            render_public_image_admin_edit_link($image);
            render_public_image_admin_delete_form($image);
            echo '</article>';
            continue;
        }
        // Variable $mediaUrl stores this steps working value.
        $mediaUrl = public_path_schema_ready() ? image_public_media_url($image, $gallery) : url_for('media', ['id' => $image['id']]);
        // Variable $imagePageUrl stores this steps working value.
        $imagePageUrl = image_public_url($image, $gallery);
        // Variable $thumbnailBundle stores all generated variants for this visible card during this request.
        $thumbnailBundle = public_render_profile_with_thumbnail_purpose('image card bundle discovery', static fn (): array => thumbnail_bundle($image));
        // Variable $previewUrl stores this steps working value.
        $previewUrl = public_render_profile_with_thumbnail_purpose('image card lightbox preview 1600', static fn (): string => thumbnail_bundle_url($thumbnailBundle, 1600));
        // Variable $imageTags stores this steps working value.
        $imageTags = $imageTagsById[(int) $image['id']] ?? [];
        // Variable $imageHasPublicGps stores this steps working value.
        $imageHasPublicGps = $mapsAllowed && image_has_gps($image);
        // Variable $imageMapPoint stores this steps working value.
        $imageMapPoint = $imageHasPublicGps ? public_render_profile_with_thumbnail_purpose('image card map preview 300', static fn (): array => image_map_point($image, $gallery, true, $thumbnailBundle)) : null;
        // Variable $displayIndex stores this steps working value.
        $displayIndex = $index + 1 + (!empty($paginationSettings['enabled']) ? (int) $photoPagination['offset'] : 0);
        // Variable $altText stores this steps working value.
        $altText = image_alt_text($image, $gallery, $displayIndex);
        // Variable $vote stores this steps working value.
        $vote = $votesById[(int) $image['id']] ?? 0;
        // Variable $displayTitle stores this steps working value.
        $displayTitle = public_image_display_title($image, $gallery);
        $imageCardClass = $publicPhotoReorderEnabled ? 'image-card has-public-reorder-handle' : 'image-card';
        echo '<article class="' . e($imageCardClass) . '" data-public-photo-order-item data-public-order-id="' . (int) $image['id'] . '" ' . lightbox_image_data_attributes($image, $gallery, $mediaUrl, $previewUrl, $imagePageUrl, $displayTitle, (int) $image['score'], $vote, $imageMapPoint, 'data-lightbox-image') . '>';
        if ($publicPhotoReorderEnabled) {
            echo '<button type="button" class="public-reorder-handle public-photo-reorder-handle" data-public-reorder-handle aria-label="Drag photo to reorder visible photos" title="Drag to reorder this visible photo"><span aria-hidden="true">↕</span><span>Move photo</span></button>';
        }
        echo '<div class="image-stage">';
        // $thumbnailSizesAttribute stores a responsive image hint derived from the configured grid.
        $thumbnailSizesAttribute = pagination_photo_thumbnail_sizes_attribute($paginationSettings);
        // $thumbnailLoadingAttributes keeps the first visible photos responsive while the rest stay lazy.
        $thumbnailLoadingAttributes = $index < 2 ? 'loading="eager" fetchpriority="high" data-responsive-thumbnail' : 'loading="lazy" fetchpriority="low" data-responsive-thumbnail';
        echo '<a class="image-preview-link" href="' . e($imagePageUrl) . '">' . public_render_profile_with_thumbnail_purpose('image card progressive picture', static fn (): string => thumbnail_progressive_picture_html($image, 300, [300, 600, 800, 960], '300px', $thumbnailSizesAttribute, $altText, $thumbnailLoadingAttributes, $thumbnailBundle)) . '</a>';
        if ($imageMapPoint) {
            echo '<button type="button" class="photo-map-pin" data-photo-map aria-label="Show photo location" title="Show photo location">&#128205;</button>';
        }
        render_vote_form((int) $image['id'], (int) $image['score'], $vote, $votingAllowed);
        // Variable $hasPublicImageMeta stores whether the anonymous-facing metadata overlay has visible content.
        // Empty metadata is not rendered, because hidden file names should not leave a blank bar under the photo.
        // The metadata is rendered inside .image-stage so long descriptions do not increase card height or break the grid rhythm.
        $hasPublicImageMeta = $displayTitle !== '' || trim((string) $image['description']) !== '' || $imageTags;
        if ($hasPublicImageMeta) {
            echo '<div class="image-meta image-meta-overlay">';
            if ($displayTitle !== '') {
                echo '<h2>' . e($displayTitle) . '</h2>';
            }
            if (trim((string) $image['description']) !== '') {
                echo '<p>' . e($image['description']) . '</p>';
            }
            render_tag_list($imageTags);
            echo '</div>';
        }
        echo '</div>';
        render_public_image_admin_edit_link($image);
        render_public_image_admin_delete_form($image);
        echo '</article>';
    }
    });
    if ($images) {
        echo '</section>';
        if (!empty($paginationSettings['enabled']) && count($allImages) > count($images)) {
            render_lightbox_source_nodes($allImages, $gallery, $mapsAllowed, $votesById);
        }
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $photoPagination : [], 'Photo pages');
    }
    if ($children || $images) {
        echo '</div>';
        render_back_to_top_button();
        echo '</div>';
    }
    render_lightbox($votingAllowed);
    if ($requestedImage) {
        append_cms_footer_script('document.addEventListener("DOMContentLoaded",function(){var selector="[data-lightbox-image][data-image-id=\"' . (int) $requestedImage['id'] . '\"], [data-lightbox-source][data-image-id=\"' . (int) $requestedImage['id'] . '\"]";var card=document.querySelector(selector);if(card){card.click();}});');
    }
    render_public_render_profile_panel();
    telemetry_append_public_script([
        'route_name' => 'gallery',
        'page_kind' => 'gallery',
        'gallery_id' => (int) $gallery['id'],
        'image_id' => $requestedImage ? (int) $requestedImage['id'] : null,
    ]);
    render_footer();
}



/**
 * Render the anonymous preview control for logged-in admins viewing a public gallery page.
 */

/**
 * Render the small public-page reorder toolbar used by logged-in admins.
 *
 * The toolbar is deliberately scoped to the visible pagination page. PHP sends
 * the current slice offset and item count to the save endpoint, and the server
 * verifies that the submitted ids still match that exact slice before writing
 * any sort_order values.
 */
function render_public_page_reorder_toolbar(string $kind, array $gallery, array $pagination, int $visibleCount, int $totalCount): void
{
    if (!current_user() || admin_anonymous_preview_active() || $visibleCount < 2) {
        return;
    }

    // $offset stores the first zero-based position represented by this visible page.
    $offset = (int) ($pagination['offset'] ?? 0);
    // $label stores the item type shown in the compact admin-only toolbar.
    $label = $kind === 'gallery' ? 'subgalleries' : 'photos';
    // $endpoint stores the existing backend route or a small public-page wrapper around it.
    $endpoint = $kind === 'gallery' ? url_for('admin_reorder_public_galleries') : url_for('admin_reorder_images');

    echo '<div class="public-reorder-toolbar" data-public-reorder-toolbar data-reorder-kind="' . e($kind) . '" data-reorder-url="' . e($endpoint) . '" data-gallery-id="' . (int) $gallery['id'] . '" data-visible-offset="' . $offset . '" data-visible-count="' . $visibleCount . '" data-total-count="' . $totalCount . '" data-csrf-token="' . e(csrf_token()) . '">';
    echo '<div><strong>Move visible ' . e($label) . '</strong><p>Drag only the cards shown on this page. Other pagination pages are not touched.</p></div>';
    echo '<span class="public-reorder-status" data-public-reorder-status aria-live="polite">Ready.</span>';
    echo '</div>';
}

function render_public_gallery_preview_toolbar(array $gallery): void
{
    if (!current_user()) {
        return;
    }
    // $isPreview stores whether the current request is already using anonymous visitor rules.
    $isPreview = admin_anonymous_preview_active();
    // $baseUrl stores the clean gallery URL used to avoid carrying image or pagination state unexpectedly.
    $baseUrl = gallery_public_url($gallery);
    // $targetUrl stores the destination for entering or leaving preview mode.
    $targetUrl = anonymous_preview_url($baseUrl, !$isPreview);

    echo '<div class="anonymous-preview-toolbar" role="status">';
    if ($isPreview) {
        echo '<span><strong>Anonymous preview active.</strong> Admin controls are hidden and visitor visibility rules are being applied.</span>';
        echo '<a class="button" href="' . e($targetUrl) . '">Exit preview</a>';
    } else {
        echo '<span>Review this gallery without inline admin controls, admin navigation, hidden photos, or admin-only visibility.</span>';
        echo '<a class="button secondary" href="' . e($targetUrl) . '">View as anonymous</a>';
    }
    echo '</div>';
}

/**
 * Render the public gallery title area with optional banner and logo assets.
 *
 * The text title remains in the h1 for accessibility and SEO even when a banner
 * image visually replaces it. The logo is decorative here because it appears
 * beside an existing text or banner title and would otherwise duplicate content.
 */
function render_public_gallery_branding_header(array $gallery, array $seo, bool $publicOnly): void
{
    // $title stores the accessible gallery title used by the current page.
    $title = (string) ($seo['title'] ?? $gallery['title'] ?? 'Gallery');
    // $description stores the public gallery description.
    $description = (string) ($seo['description'] ?? $gallery['description'] ?? '');
    // $bannerUrl stores only the per-gallery title-replacement image. Theme fallback banners belong to the shared site header.
    $bannerUrl = gallery_branding_schema_ready() ? gallery_branding_asset_url($gallery, 'banner', $publicOnly) : '';
    // $logoUrl stores the optional supplementary logo image.
    $logoUrl = gallery_branding_schema_ready() ? gallery_branding_asset_url($gallery, 'logo', $publicOnly) : '';
    // $titleBarClasses stores layout flags for tight and wide gallery headers.
    $titleBarClasses = 'gallery-title-bar' . ($bannerUrl !== '' ? ' has-gallery-banner' : '') . ($logoUrl !== '' ? ' has-gallery-logo' : '');

    echo '<div class="' . e($titleBarClasses) . '">';
    if ($logoUrl !== '') {
        echo '<img class="gallery-branding-logo" src="' . e($logoUrl) . '" alt="" aria-hidden="true" decoding="async">';
    }
    if ($bannerUrl !== '') {
        echo '<h1 class="gallery-title gallery-title-with-banner"><span class="visually-hidden">' . e($title) . '</span><img class="gallery-branding-banner" src="' . e($bannerUrl) . '" alt="" aria-hidden="true" decoding="async"></h1>';
    } else {
        echo '<h1 class="gallery-title">' . e($title) . '</h1>';
    }
    echo '</div>';
    if (trim($description) !== '') {
        echo '<p>' . e($description) . '</p>';
    }
}

/**
 * Render the optional horizontal branding separator below the gallery title area.
 */
function render_public_gallery_branding_separator(array $gallery, bool $publicOnly): void
{
    if (!gallery_branding_schema_ready()) {
        return;
    }
    // $separatorUrl stores only the per-gallery divider image. Theme fallback separators belong to the shared site header.
    $separatorUrl = gallery_branding_schema_ready() ? gallery_branding_asset_url($gallery, 'separator', $publicOnly) : '';
    if ($separatorUrl === '') {
        return;
    }
    echo '<div class="gallery-branding-separator" aria-hidden="true"><img src="' . e($separatorUrl) . '" alt="" decoding="async"></div>';
}


/**
 * Handles render breadcrumbs logic for the gallery application.
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_breadcrumbs(?array $gallery = null): void
{
    echo '<nav class="breadcrumbs" aria-label="Breadcrumbs">';
    echo '<a href="' . e(url_for('home')) . '">Galleries</a>';
    if ($gallery) {
        foreach (gallery_breadcrumb_ancestors($gallery) as $ancestor) {
            echo '<span aria-hidden="true">/</span><a href="' . e(gallery_public_url($ancestor)) . '">' . e($ancestor['title']) . '</a>';
        }
        echo '<span aria-hidden="true">/</span><span>' . e($gallery['title']) . '</span>';
    }
    echo '</nav>';
}

/**
 * Handles render gallery access gate logic for the gallery application.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $error Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_gallery_access_gate(array $gallery, string $error = '', ?array $image = null): void
{
    // $requirement stores an intermediate value used by the surrounding gallery workflow.
    $requirement = gallery_access_requirement($gallery) ?: $gallery;
    // $nsfwRequirement stores the inherited NSFW source, or the current gallery for per-image NSFW.
    $nsfwRequirement = gallery_nsfw_requirement($gallery) ?: ($image !== null && image_nsfw_restricted($image, $gallery) ? $gallery : null);
    render_header((string) $gallery['title']);
    render_breadcrumbs($gallery);
    echo '<section class="panel"><h1>' . e($gallery['title']) . '</h1>';
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    if ($nsfwRequirement !== null && !visitor_can_access_nsfw_content()) {
        echo '<p>This gallery or photo is marked as restricted 18+ content. Anonymous visitors must confirm they are at least 18 before access is granted for this browser session. If you are an administrator planning to publish NSFW content, please verify that your hosting provider or web hosting terms allow it before enabling access.</p>';
        echo '<form method="post" action="' . e(url_for('gallery_access')) . '" class="form-grid">' . csrf_field();
        echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
        if ($image !== null) {
            echo '<input type="hidden" name="image_id" value="' . (int) $image['id'] . '">';
        }
        echo '<input type="hidden" name="access_action" value="confirm_nsfw_age">';
        echo '<label><input type="checkbox" name="adult_confirmed" value="1" required> I confirm that I am at least 18 years old.</label>';
        echo '<button type="submit">Continue</button></form>';
    } elseif (empty($requirement['access_password_hash'])) {
        echo '<p>This gallery is available only through its share link.</p>';
    } else {
        echo '<p>This gallery is password protected. Access closes after ' . (int) (gallery_access_lifetime_seconds() / 60) . ' minutes of session time.</p>';
        echo '<form method="post" action="' . e(url_for('gallery_access')) . '" class="form-grid">' . csrf_field();
        echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
        echo '<input type="hidden" name="requirement_id" value="' . (int) $requirement['id'] . '">';
        echo '<label>Password<input name="gallery_password" type="password" required autocomplete="current-password"></label>';
        echo '<button type="submit">Open gallery</button></form>';
    }
    echo '</section>';
    render_footer();
}

/**
 * Handles cms gallery access logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_gallery_access(): void
{
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = find_gallery((int) ($_POST['gallery_id'] ?? 0));
    if (!$gallery || (!gallery_allows_direct_public_request($gallery) && !current_user())) {
        cms_not_found();
        return;
    }
    // $image stores the optional image that sent the visitor to the NSFW confirmation gate.
    $image = find_image((int) ($_POST['image_id'] ?? 0));
    if ($image && (int) $image['gallery_id'] !== (int) $gallery['id']) {
        $image = null;
    }
    // $accessAction stores whether this request confirms age or submits a gallery password.
    $accessAction = (string) ($_POST['access_action'] ?? 'password');
    if ($accessAction === 'confirm_nsfw_age') {
        if (current_user_is_known_under_18()) {
            render_gallery_access_gate($gallery, 'This account is not eligible to access 18+ content.', $image);
            return;
        }
        if (empty($_POST['adult_confirmed'])) {
            render_gallery_access_gate($gallery, 'You must confirm that you are at least 18 years old.', $image);
            return;
        }
        grant_nsfw_guard_access();
        redirect_to($image ? image_public_url($image, $gallery) : gallery_public_url($gallery));
    }
    // $requirement stores an intermediate value used by the surrounding gallery workflow.
    $requirement = gallery_access_requirement($gallery);
    if (!$requirement || empty($requirement['access_password_hash'])) {
        render_gallery_access_gate($gallery, 'This gallery does not have a password login configured.');
        return;
    }
    // $password stores an intermediate value used by the surrounding gallery workflow.
    $password = (string) ($_POST['gallery_password'] ?? '');
    if (!password_verify($password, (string) $requirement['access_password_hash'])) {
        render_gallery_access_gate($gallery, 'The password is incorrect.');
        return;
    }
    grant_gallery_public_access((int) $requirement['id']);
    redirect_to(gallery_public_url($gallery));
}

/**
 * Handles cms share logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_share(): void
{
    // $token stores an intermediate value used by the surrounding gallery workflow.
    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '' || !gallery_access_schema_ready()) {
        cms_not_found();
        return;
    }
    // $galleryId stores an intermediate value used by the surrounding gallery workflow.
    $galleryId = (int) ($_GET['id'] ?? 0);
    if ($galleryId > 0) {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->prepare("SELECT * FROM galleries WHERE id = ? AND access_token_hash = ? LIMIT 1");
        $stmt->execute([$galleryId, hash('sha256', $token)]);
    } else {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->prepare("SELECT * FROM galleries WHERE access_token_hash = ? ORDER BY updated_at DESC, id DESC LIMIT 1");
        $stmt->execute([hash('sha256', $token)]);
    }
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = $stmt->fetch();
    if (!$gallery || (!empty($gallery['access_token_expires_at']) && strtotime((string) $gallery['access_token_expires_at']) < time())) {
        cms_not_found();
        return;
    }
    grant_gallery_public_access((int) $gallery['id']);
    redirect_to(gallery_public_url($gallery));
}

/**
 * Handles gallery share url logic for the gallery application.
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $token Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_share_url(int $galleryId, string $token): string
{
    return url_for('share', ['id' => $galleryId, 'token' => $token]);
}

/**
 * Handles render gallery card logic for the gallery application.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $publicOnly Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_gallery_card(array $gallery, bool $publicOnly, bool $showPublicReorderHandle = false, bool $showSubgalleryBadge = false): void
{
    // $isProtectedPublicCard stores an intermediate value used by the surrounding gallery workflow.
    $isProtectedPublicCard = $publicOnly && gallery_access_requirement($gallery) !== null;
    // Variable $cover stores this steps working value.
    $coverAsset = $isProtectedPublicCard ? '' : public_render_profile_span('gallery_cover_asset_lookup', static fn (): string => gallery_cover_asset_url($gallery, $publicOnly));
    // $cover stores an intermediate value used by the surrounding gallery workflow.
    $cover = $isProtectedPublicCard || $coverAsset !== '' ? null : public_render_profile_span('gallery_cover_image_lookup', static fn (): ?array => gallery_cover_image((int) $gallery['id'], $publicOnly));
    // Variable $branchImageCount stores this steps working value.
    $branchImageCount = $isProtectedPublicCard ? 0 : public_render_profile_span('gallery_branch_image_count', static fn (): int => gallery_branch_image_count((int) $gallery['id'], $publicOnly));
    $galleryCardClass = 'gallery-card' . ($isProtectedPublicCard ? ' is-protected-gallery' : '') . ($showPublicReorderHandle ? ' has-public-reorder-handle' : '');
    echo '<article class="' . e($galleryCardClass) . '" data-gallery-id="' . (int) $gallery['id'] . '" data-public-gallery-order-item data-public-order-id="' . (int) $gallery['id'] . '">';
    if ($showPublicReorderHandle) {
        echo '<button type="button" class="public-reorder-handle public-gallery-reorder-handle" data-public-reorder-handle aria-label="Drag subgallery to reorder visible subgalleries" title="Drag to reorder this visible subgallery"><span aria-hidden="true">↕</span><span>Move gallery</span></button>';
    }
    echo '<a class="gallery-card-media" href="' . e(gallery_public_url($gallery)) . '" aria-label="Open gallery ' . e((string) $gallery['title']) . '">';
    if ($showSubgalleryBadge && !$isProtectedPublicCard) {
        echo '<span class="subgallery-stack-badge" aria-label="Subgallery containing ' . (int) $branchImageCount . ' images"><span class="subgallery-stack-icon" aria-hidden="true"><span></span><span></span><span></span></span><span class="subgallery-stack-count">' . (int) $branchImageCount . '</span></span>';
    }
    if ($isProtectedPublicCard) {
        echo '<span class="gallery-collage gallery-locked-preview" aria-hidden="true">Protected</span>';
    } elseif ($coverAsset !== '') {
        echo '<img decoding="async" loading="lazy" src="' . e($coverAsset) . '" alt="">';
    } elseif ($cover) {
        echo public_render_profile_with_thumbnail_purpose('subgallery cover progressive picture', static fn (): string => thumbnail_progressive_picture_html($cover, 300, [300, 800, 960], '300px', '(max-width: 299px) 300px, 800px', '', 'loading="lazy" fetchpriority="low" data-responsive-thumbnail'));
    } else {
        // Variable $collage stores this steps working value.
        $collage = public_render_profile_span('gallery_cover_collage_lookup', static fn (): array => gallery_cover_collage_images((int) $gallery['id'], $publicOnly));
        if ($collage) {
            echo '<span class="gallery-collage collage-count-' . count($collage) . '">';
            foreach ($collage as $image) {
                echo public_render_profile_with_thumbnail_purpose('subgallery collage progressive picture', static fn (): string => thumbnail_progressive_picture_html($image, 300, [300, 800, 960], '300px', '(max-width: 299px) 300px, 800px', '', 'loading="lazy" fetchpriority="low" data-responsive-thumbnail'));
            }
            echo '</span>';
        }
    }
    echo '</a>';
    echo '<div class="gallery-card-body"><h2><a class="gallery-card-title-link" href="' . e(gallery_public_url($gallery)) . '">' . e($gallery['title']) . '</a></h2>';
    echo '<p class="gallery-card-description">' . e($gallery['description']) . '</p>';
    if ($isProtectedPublicCard) {
        echo '<p class="muted gallery-card-count">Protected gallery</p>';
    } else {
        echo '<p class="muted gallery-card-count gallery-card-count-visual-hidden">' . $branchImageCount . ' images</p>';
        render_tag_list(public_render_profile_span('contained_tag_lookup', static fn (): array => contained_tags_for_gallery($gallery, $publicOnly)), 'Containing tags');
    }
    echo '</div>';
    render_public_gallery_admin_edit_link($gallery, 'card');
    render_public_gallery_admin_delete_form($gallery, 'card');
    echo '</article>';
}


/**
 * Render the compact public-page child-gallery creation entry point for logged-in admins.
 *
 * This opens the upload controller in create-and-upload mode, so the admin can
 * create a child gallery and optionally upload photos in one panel workflow.
 *
 * @param mixed $gallery Input used by this operation.
 * @param mixed $placement Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_public_gallery_admin_add_child_link(array $gallery, string $placement = 'card'): void
{
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }
    $label = $placement === 'hero' ? 'Add gallery here' : 'Add gallery inside ' . (string) $gallery['title'];
    $class = $placement === 'hero' ? 'public-admin-add-gallery-button public-admin-add-gallery-button-hero hero-icon-button' : 'public-admin-add-gallery-button public-admin-add-gallery-button-card';
    $url = url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id']]);
    $panelUrl = url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id'], 'panel' => 1]);
    echo '<a class="' . e($class) . '" href="' . e($url) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="upload" data-admin-side-panel-kicker="Gallery workflow" data-admin-side-panel-title="Add gallery here" data-gallery-side-panel-url="' . e($panelUrl) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">+</span><span class="visually-hidden">' . e($label) . '</span></a>';
}

/**
 * Render the compact public-page gallery edit entry point for logged-in admins.
 *
 * The link keeps the full admin edit route as its href while enhancing the click
 * into the existing side-panel workflow when JavaScript is available.
 *
 * @param mixed $gallery Input used by this operation.
 * @param mixed $placement Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_public_gallery_admin_edit_link(array $gallery, string $placement = 'card'): void
{
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }
    $label = $placement === 'hero' ? 'Edit current gallery' : 'Edit gallery ' . (string) $gallery['title'];
    $class = $placement === 'hero' ? 'public-admin-edit-button public-admin-edit-button-hero' : 'public-admin-edit-button public-admin-edit-button-card';
    echo '<a class="' . e($class) . '" href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="gallery-edit" data-admin-side-panel-kicker="Gallery editor" data-admin-side-panel-title="Edit gallery" data-gallery-side-panel-url="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id'], 'panel' => 1])) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#9998;</span><span class="visually-hidden">' . e($label) . '</span></a>';
}


/**
 * Render the compact public-page gallery delete entry point for logged-in admins.
 *
 * This uses the existing public admin update route and keeps the action as an
 * explicit POST so browsers without JavaScript still have a safe CSRF-protected
 * fallback. JavaScript adds the confirmation prompt before the form is submitted.
 *
 * @param mixed $gallery Input used by this operation.
 * @param mixed $placement Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_public_gallery_admin_delete_form(array $gallery, string $placement = 'card'): void
{
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }
    $name = trim((string) ($gallery['title'] ?? 'gallery'));
    $label = $placement === 'hero' ? 'Remove current gallery from CMS' : 'Remove gallery ' . $name . ' from CMS';
    $class = $placement === 'hero' ? 'public-admin-delete-form public-admin-delete-form-hero' : 'public-admin-delete-form public-admin-delete-form-card';
    echo '<form class="' . e($class) . '" method="post" action="' . e(url_for('admin_public_update_gallery')) . '" data-public-admin-card-action data-public-admin-delete-form data-public-admin-delete-name="' . e($name) . '" data-public-admin-delete-kind="gallery">';
    echo csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<button type="submit" class="public-admin-card-action-button public-admin-delete-button" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#128465;</span><span class="visually-hidden">' . e($label) . '</span></button>';
    echo '</form>';
}

/**
 * Render the compact public-page photo edit entry point for logged-in admins.
 *
 * The link falls back to the full admin edit image page and uses the current
 * side-panel loader when the gallery JavaScript is active.
 *
 * @param mixed $image Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_public_image_admin_edit_link(array $image): void
{
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }
    $title = trim((string) ($image['title'] ?? ''));
    $name = $title !== '' ? $title : (string) ($image['relative_path'] ?? 'photo');
    $label = 'Edit photo ' . $name;
    echo '<a class="public-admin-edit-button public-admin-edit-button-card public-admin-edit-button-photo" href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="image-edit" data-admin-side-panel-kicker="Photo editor" data-admin-side-panel-title="Edit photo" data-gallery-side-panel-url="' . e(url_for('admin_edit_image', ['id' => $image['id'], 'panel' => 1])) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#9998;</span><span class="visually-hidden">' . e($label) . '</span></a>';
}


/**
 * Render the compact public-page photo delete entry point for logged-in admins.
 *
 * The form reuses the existing public admin image update route. The route removes
 * the image row from the CMS and redirects back to the current public context
 * when JavaScript is unavailable.
 *
 * @param mixed $image Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_public_image_admin_delete_form(array $image): void
{
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }
    $title = trim((string) ($image['title'] ?? ''));
    $name = $title !== '' ? $title : (string) ($image['relative_path'] ?? 'photo');
    $label = 'Remove photo ' . $name . ' from CMS';
    echo '<form class="public-admin-delete-form public-admin-delete-form-card public-admin-delete-form-photo" method="post" action="' . e(url_for('admin_public_update_image')) . '" data-public-admin-card-action data-public-admin-delete-form data-public-admin-delete-name="' . e($name) . '" data-public-admin-delete-kind="photo">';
    echo csrf_field();
    echo '<input type="hidden" name="image_id" value="' . (int) $image['id'] . '">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<button type="submit" class="public-admin-card-action-button public-admin-delete-button" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#128465;</span><span class="visually-hidden">' . e($label) . '</span></button>';
    echo '</form>';
}

/**
 * Build the shared data attributes consumed by the public lightbox.
 *
 * Keeping visible cards and hidden pagination sources on the same attribute
 * contract prevents the lightbox from having a separate pagination-specific path.
 */
function lightbox_image_data_attributes(array $image, array $gallery, string $mediaUrl, string $previewUrl, string $imagePageUrl, string $displayTitle, int $score, int $vote, ?array $imageMapPoint, string $sourceAttribute): string
{
    // $mapPointAttribute stores the optional GPS payload used by map-enabled photos.
    $mapPointAttribute = $imageMapPoint ? ' data-map-point="' . e(json_encode($imageMapPoint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"' : '';
    return $sourceAttribute
        . ' data-image-id="' . (int) $image['id'] . '"'
        . ' data-gallery-id="' . (int) $gallery['id'] . '"'
        . ' data-full-src="' . e($mediaUrl) . '"'
        . ' data-preview-src="' . e($previewUrl) . '"'
        . ' data-page-url="' . e($imagePageUrl) . '"'
        . ' data-gallery-url="' . e(gallery_public_url($gallery)) . '"'
        . ' data-title="' . e($displayTitle) . '"'
        . ' data-description="' . e($image['description']) . '"'
        . ' data-score="' . $score . '"'
        . ' data-user-vote="' . $vote . '"'
        . ' data-image-width="' . (int) ($image['width'] ?? 0) . '"'
        . ' data-image-height="' . (int) ($image['height'] ?? 0) . '"'
        . $mapPointAttribute;
}

/**
 * Render hidden ordered lightbox data for paginated galleries.
 *
 * Pagination limits visible photo cards, but fullscreen navigation should still
 * move through the complete sorted gallery. These hidden nodes are metadata only
 * and do not affect the public grid layout.
 */
function render_lightbox_source_nodes(array $allImages, array $gallery, bool $mapsAllowed, array $votesById): void
{
    echo '<div class="lightbox-source-list" hidden aria-hidden="true">';
    foreach ($allImages as $image) {
        if (!current_user() && image_nsfw_restricted($image, $gallery) && !visitor_can_access_nsfw_content()) {
            continue;
        }
        // Variable $mediaUrl stores this steps working value.
        $mediaUrl = public_path_schema_ready() ? image_public_media_url($image, $gallery) : url_for('media', ['id' => $image['id']]);
        // Variable $imagePageUrl stores this steps working value.
        $imagePageUrl = image_public_url($image, $gallery);
        // Variable $previewUrl stores this steps working value.
        // Hidden source nodes are metadata for fullscreen order only. Keep their
        // preview empty so paginated galleries do not resolve a large thumbnail
        // for every non-rendered image during normal page render.
        $previewUrl = '';
        // Variable $displayTitle stores this steps working value.
        $displayTitle = public_image_display_title($image, $gallery);
        // Variable $vote stores this steps working value.
        $vote = $votesById[(int) $image['id']] ?? 0;
        // Variable $imageMapPoint stores this steps working value.
        $imageMapPoint = $mapsAllowed && image_has_gps($image) ? public_render_profile_with_thumbnail_purpose('hidden source map metadata no thumb', static fn (): array => image_map_point($image, $gallery, false)) : null;
        // $sourceAttribute stores a separate marker from visible cards so JavaScript can preserve the full image order.
        $sourceAttribute = 'data-lightbox-source';
        echo '<div ' . lightbox_image_data_attributes($image, $gallery, $mediaUrl, $previewUrl, $imagePageUrl, $displayTitle, (int) $image['score'], $vote, $imageMapPoint, $sourceAttribute) . '></div>';
    }
    echo '</div>';
}

/**
 * Handles render lightbox logic for the gallery application.
 * @param mixed $votingAllowed Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_lightbox(bool $votingAllowed = true): void
{
    echo '<div class="lightbox" data-lightbox hidden>';
    echo '<button class="lightbox-close lightbox-hud" type="button" data-lightbox-action="close">Close</button>';
    echo '<button type="button" class="lightbox-nav lightbox-previous lightbox-hud" data-lightbox-action="previous" aria-label="Previous image">&lt;</button>';
    echo '<figure><button type="button" class="lightbox-stage-link" data-lightbox-stage aria-label="Toggle fullscreen image"><img decoding="async" data-lightbox-img alt=""></button><figcaption class="lightbox-meta"><div class="lightbox-toolbar"><span class="lightbox-counter" data-lightbox-counter></span><button type="button" class="lightbox-fullscreen-link" data-lightbox-action="fullscreen" aria-label="Toggle fullscreen" title="Toggle fullscreen">F fullscreen</button><button type="button" class="lightbox-map-button" data-lightbox-map hidden>&#128205; Map</button></div><div class="lightbox-score-badge">Score <strong data-lightbox-score data-score-for="">0</strong></div><h2 data-lightbox-title></h2><p class="lightbox-description" data-lightbox-description></p>' . ($votingAllowed ? '<div class="lightbox-vote-panel"><form class="vote-row lightbox-vote" method="post" action="' . e(url_for('vote')) . '" data-vote-form data-lightbox-vote-form><input type="hidden" name="image_id" value="">' . csrf_field() . '<span class="lightbox-vote-label">Like</span><button type="submit" name="vote" value="1" aria-label="Like" title="Like">&#9650;</button><span class="lightbox-vote-indicator" data-lightbox-vote-indicator>No like</span></form></div>' : '') . '</figcaption><div class="lightbox-map-split" data-lightbox-map-split hidden><button type="button" class="lightbox-map-split-close" data-lightbox-map-split-close aria-label="Close map split">Close map</button><div class="lightbox-map-split-title" data-lightbox-map-split-title></div><div class="lightbox-map-split-canvas" data-lightbox-map-split-canvas></div></div></figure>';
    echo '<button type="button" class="lightbox-nav lightbox-next lightbox-hud" data-lightbox-action="next" aria-label="Next image">&gt;</button>';
    echo '<button type="button" class="lightbox-fullscreen-button lightbox-hud" data-lightbox-action="fullscreen" aria-label="Toggle fullscreen" title="Toggle fullscreen">F</button>';
    echo '<button type="button" class="lightbox-mobile-fullscreen-button" data-lightbox-action="fullscreen" aria-label="Toggle fullscreen" title="Toggle fullscreen">&#9974;</button>';
    echo '</div>';
}

