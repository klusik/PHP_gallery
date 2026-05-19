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
    // $allHomeGalleries stores the full front-page gallery list before optional slicing.
    $allHomeGalleries = $galleries;
    // $homeGalleryCount stores the full front-page gallery count before optional slicing.
    $homeGalleryCount = count($allHomeGalleries);
    // Variable $galleryPagination stores this steps working value.
    $galleryPagination = pagination_model($homeGalleryCount, pagination_current_page('gallery_page'), (int) $paginationSettings['columns'], (int) $paginationSettings['rows'], 'gallery_page', null, static fn (int $pageNumber): string => pagination_home_gallery_clean_url($pageNumber));
    if (!empty($paginationSettings['enabled'])) {
        // $galleries stores the public home gallery list after optional pagination slicing.
        // Always slice from the immutable full result set so the rendered cards and
        // the pagination controls are based on the same source. This avoids a
        // controls-only front page after Theme pagination settings change.
        $galleries = pagination_slice_items($allHomeGalleries, $galleryPagination);
        if ($homeGalleryCount > 0 && $galleries === []) {
            // A stale or malformed front-page pagination request must not render a controls-only page.
            $galleryPagination = pagination_model($homeGalleryCount, 1, (int) $paginationSettings['columns'], (int) $paginationSettings['rows'], 'gallery_page', null, static fn (int $pageNumber): string => pagination_home_gallery_clean_url($pageNumber));
            $galleries = pagination_slice_items($allHomeGalleries, $galleryPagination);
        }
    } else {
        // Keep the non-paginated branch explicit so later code never depends on
        // a list variable that was already touched by another page mode.
        $galleries = $allHomeGalleries;
    }
    render_header(site_name());
    if ($homeGalleryCount > 0) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        echo '<div class="gallery-list-content" data-back-to-top-list>';
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $galleryPagination : [], t('gallery.pagination.gallery_pages', 'Gallery pages'));
        echo '<section class="grid public-home-gallery-grid' . e(pagination_grid_columns_class($paginationSettings)) . '">';
        public_render_profile_count('rendered_subgalleries', count($galleries));
        public_render_profile_span('render_home_gallery_cards', static function () use ($galleries): void {
            foreach ($galleries as $index => $gallery) {
                render_gallery_card($gallery, true, false, true, $index);
            }
        });
        echo '</section>';
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $galleryPagination : [], t('gallery.pagination.gallery_pages', 'Gallery pages'));
        echo '</div>';
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
    // $lightboxExcludesRestrictedNsfw stores whether the async lightbox list must omit restricted rows.
    $lightboxExcludesRestrictedNsfw = gallery_lightbox_excludes_restricted_nsfw($gallery, $publicOnly);
    // $imageTotalCount stores the complete top-level photo count used by public pagination.
    $imageTotalCount = public_render_profile_db('gallery_image_count_query', static fn (): int => gallery_lightbox_total_count($gallery, $publicOnly, false));
    // $lightboxTotalCount stores the async lightbox count after visitor-specific restrictions are applied.
    $lightboxTotalCount = public_render_profile_db('gallery_lightbox_count_query', static fn (): int => gallery_lightbox_total_count($gallery, $publicOnly, true));
    // Variable $images stores the visible page photo rows. It is filled after pagination knows the requested offset.
    $images = [];
    // Variable $allImages stores only the current visible photo set. Full-gallery lightbox data is loaded asynchronously.
    $allImages = [];
    // Variable $imageTagsById stores this steps working value.
    $imageTagsById = [];
    // Variable $votesById stores this steps working value.
    $votesById = [];
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
        // $requestedImagePosition stores the zero-based sorted position of the direct-linked photo.
        $requestedImagePosition = gallery_lightbox_image_position($requestedImage, $gallery, $publicOnly, false);
        if ($requestedImagePosition >= 0) {
            // $photoCurrentPage stores the page that contains the explicitly requested image.
            $photoCurrentPage = (int) floor($requestedImagePosition / (int) $paginationSettings['items_per_page']) + 1;
        }
    }
    // Variable $photoPagination stores this steps working value.
    $photoPagination = pagination_model($imageTotalCount, $photoCurrentPage, (int) $paginationSettings['columns'], (int) $paginationSettings['rows'], 'photo_page', $galleryPaginationQuery, static fn (int $pageNumber): string => pagination_gallery_clean_url($gallery, $pageNumber, 'photos'));
    if (!empty($paginationSettings['enabled'])) {
        // $children stores the subgallery list after sorting has already been applied by child_galleries().
        $children = pagination_slice_items($children, $childPagination);
        // $images stores only the visible photo page so large galleries do not render full-gallery metadata.
        $images = public_render_profile_db('gallery_image_page_query', static fn (): array => gallery_lightbox_fetch_images($gallery, $publicOnly, (int) $photoPagination['offset'], (int) $photoPagination['limit'], false));
    } else {
        // $images stores all rows only when the gallery grid explicitly has pagination disabled.
        $images = public_render_profile_db('gallery_image_full_query', static fn (): array => gallery_lightbox_fetch_images($gallery, $publicOnly, 0, null, false));
    }
    // Variable $allImages stores only the visible image set for crawler metadata and social preview fallback.
    $allImages = $images;
    // Variable $imageIds stores this steps working value.
    $imageIds = array_map(static fn (array $image): int => (int) $image['id'], $images);
    // Variable $imageTagsById stores this steps working value.
    $imageTagsById = public_render_profile_span('image_tag_lookup', static fn (): array => tags_for_entities('image', $imageIds));
    // Variable $votesById stores this steps working value.
    $votesById = public_render_profile_span('image_vote_lookup', static fn (): array => current_votes_for_images($imageIds));
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
    echo '<div class="hero-actions" aria-label="' . e(t('gallery.actions', 'Gallery actions')) . '">';
    render_public_gallery_admin_delete_form($gallery, 'hero');
    render_public_gallery_admin_edit_link($gallery, 'hero');
    render_public_gallery_admin_add_child_link($gallery, 'hero');
    echo '<a class="button hero-icon-button hero-download-button" href="' . e(url_for('download_gallery', ['id' => $gallery['id']])) . '" aria-label="' . e(t('gallery.download', 'Download gallery')) . '" title="' . e(t('gallery.download', 'Download gallery')) . '"><span aria-hidden="true">&#10515;</span><span class="visually-hidden">' . e(t('gallery.download', 'Download gallery')) . '</span></a>';
    if ($galleryMapAvailable) {
        echo '<button type="button" class="button secondary map-button" data-gallery-map-url="' . e(url_for('gallery_map_data', ['id' => $gallery['id']])) . '" data-gallery-map-title="' . e((string) $gallery['title']) . '">' . e(t('gallery.show_map', 'Show gallery map')) . '</button>';
    }
    if (picture_game_available($gallery, $pictureGameImages)) {
        echo '<a class="button secondary hero-icon-button hero-picture-game-button" href="' . e(url_for('picture_game', ['id' => $gallery['id']])) . '" aria-label="' . e(t('gallery.play_picture_game', 'Play picture game')) . '" title="' . e(t('gallery.play_picture_game', 'Play picture game')) . '"><span aria-hidden="true">&#127918;</span><span class="visually-hidden">' . e(t('gallery.play_picture_game', 'Play picture game')) . '</span></a>';
    }
    echo '</div>';
    echo '<div class="hero-tags" aria-label="' . e(t('gallery.tags', 'Gallery tags')) . '">';
    render_tag_list(tags_for_entity('gallery', (int) $gallery['id']));
    if ($children) {
        render_tag_list(public_render_profile_span('contained_tag_lookup', static fn (): array => contained_tags_for_gallery($gallery, $publicOnly)), t('gallery.containing_tags', 'Containing tags'));
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
    // $pictureManagerEnabled stores whether the logged-in viewer can select and manage visible photos.
    $pictureManagerEnabled = current_user() && !admin_anonymous_preview_active();
    if ($children || $images) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        echo '<div class="gallery-list-content" data-back-to-top-list>';
    }
    if ($children) {
        echo '<section class="panel public-subgallery-panel" data-public-subgallery-section aria-label="' . e(t('public.subgalleries', 'Subgalleries')) . '">';
        render_public_page_reorder_toolbar('gallery', $gallery, !empty($paginationSettings['enabled']) ? $childPagination : [], count($children), count($allChildren));
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $childPagination : [], t('pagination.subgallery_pages', 'Subgallery pages'));
        echo '<div class="grid' . e(pagination_grid_columns_class($paginationSettings)) . '" data-public-reorder-list="gallery" data-public-subgallery-grid>';
        public_render_profile_count('rendered_subgalleries', count($children));
        public_render_profile_span('render_subgallery_cards', static function () use ($children, $publicPageReorderEnabled): void {
            foreach ($children as $index => $child) {
                render_gallery_card($child, true, $publicPageReorderEnabled && count($children) > 1, true, $index);
            }
        });
        echo '</div>';
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $childPagination : [], t('pagination.subgallery_pages', 'Subgallery pages'));
        echo '</section>';
    }
    // Variable $publicPhotoReorderEnabled stores whether visible photo cards should render drag handles.
    $publicPhotoReorderEnabled = $publicPageReorderEnabled && count($images) > 1;
    if ($images) {
        render_picture_manager_toolbar($gallery, count($children) > 0);
        render_public_page_reorder_toolbar('photo', $gallery, !empty($paginationSettings['enabled']) ? $photoPagination : [], count($images), $imageTotalCount);
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $photoPagination : [], t('pagination.photo_pages', 'Photo pages'));
        $lightboxEndpointParams = ['id' => (int) $gallery['id']];
        if ($anonymousPreview) {
            $lightboxEndpointParams['view_as'] = 'anonymous';
        }
        echo '<section class="grid gallery-image-grid' . e(pagination_grid_columns_class($paginationSettings)) . '" data-public-reorder-list="photo" data-gallery-image-list data-lightbox-config data-lightbox-endpoint="' . e(url_for('gallery_lightbox_data', $lightboxEndpointParams)) . '" data-lightbox-total="' . (int) $lightboxTotalCount . '" data-lightbox-window-size="60" data-lightbox-maps-enabled="' . ($mapsAllowed ? '1' : '0') . '">';
    }
    public_render_profile_count('rendered_images', count($images));
    public_render_profile_span('render_image_cards', static function () use ($images, $gallery, $publicOnly, $mapsAllowed, $imageTagsById, $votesById, $votingAllowed, $paginationSettings, $photoPagination, $publicPhotoReorderEnabled, $pictureManagerEnabled, $lightboxExcludesRestrictedNsfw): void {
    foreach ($images as $index => $image) {
        // Variable $imageNeedsNsfwGate stores whether this card must avoid exposing thumbnail/media URLs.
        $imageNeedsNsfwGate = $publicOnly && image_nsfw_restricted($image, $gallery) && !visitor_can_access_nsfw_content();
        if ($imageNeedsNsfwGate) {
            echo '<article class="image-card nsfw-card"><div class="image-stage nsfw-stage"><a class="nsfw-placeholder" href="' . e(image_public_url($image, $gallery)) . '"><strong>' . e(t('public.nsfw_photo_title', '18+ photo')) . '</strong><span>' . e(t('public.nsfw_photo_message', 'Confirm your age to view this restricted photo.')) . '</span></a></div>';
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
        // $lightboxIndex stores the zero-based index in the async lightbox order.
        $lightboxIndex = $lightboxExcludesRestrictedNsfw ? gallery_lightbox_image_position($image, $gallery, $publicOnly, true) : $displayIndex - 1;
        // Variable $altText stores this steps working value.
        $altText = image_alt_text($image, $gallery, $displayIndex);
        // Variable $vote stores this steps working value.
        $vote = $votesById[(int) $image['id']] ?? 0;
        // Variable $displayTitle stores this steps working value.
        $displayTitle = public_image_display_title($image, $gallery);
        $imageCardClass = 'image-card' . ($publicPhotoReorderEnabled ? ' has-public-reorder-handle' : '') . ($pictureManagerEnabled ? ' has-picture-manager-select' : '');
        $pictureManagerAttributes = $pictureManagerEnabled ? ' data-picture-manager-image data-picture-manager-image-id="' . (int) $image['id'] . '" data-picture-manager-index="' . (int) $displayIndex . '"' : '';
        echo '<article class="' . e($imageCardClass) . '" data-public-photo-order-item data-public-order-id="' . (int) $image['id'] . '"' . $pictureManagerAttributes . ' ' . lightbox_image_data_attributes($image, $gallery, $mediaUrl, $previewUrl, $imagePageUrl, $displayTitle, (int) $image['score'], $vote, $imageMapPoint, 'data-lightbox-image', $votingAllowed, $lightboxIndex >= 0 ? $lightboxIndex : null) . '>';
        if ($publicPhotoReorderEnabled) {
            echo '<button type="button" class="public-reorder-handle public-photo-reorder-handle" data-public-reorder-handle aria-label="' . e(t('public.reorder.drag_photo_label', 'Drag photo to reorder visible photos')) . '" title="' . e(t('public.reorder.drag_photo_title', 'Drag to reorder this visible photo')) . '"><span aria-hidden="true">↕</span><span>' . e(t('public.reorder.move_photo', 'Move photo')) . '</span></button>';
        }
        if ($pictureManagerEnabled) {
            echo '<button type="button" class="picture-manager-select-button" data-picture-manager-select aria-pressed="false" aria-label="' . e(t('picture_manager.select_photo', 'Select photo')) . '" title="' . e(t('picture_manager.select_photo', 'Select photo')) . '"><span aria-hidden="true">✓</span><span class="visually-hidden">' . e(t('picture_manager.select_photo', 'Select photo')) . '</span></button>';
        }
        echo '<div class="image-stage">';
        // $thumbnailSizesAttribute stores a responsive image hint derived from the configured grid.
        $thumbnailSizesAttribute = pagination_photo_thumbnail_sizes_attribute($paginationSettings);
        // $thumbnailLoadingAttributes keeps above-the-fold photos eager without forcing later rows to compete for bandwidth.
        $thumbnailLoadingAttributes = public_thumbnail_loading_attributes($index);
        echo '<a class="image-preview-link" href="' . e($imagePageUrl) . '">' . public_render_profile_with_thumbnail_purpose('image card stable picture', static fn (): string => thumbnail_picture_html($image, 300, [300, 600, 800, 960], $thumbnailSizesAttribute, $altText, $thumbnailLoadingAttributes, $thumbnailBundle)) . '</a>';
        if ($imageMapPoint) {
            echo '<button type="button" class="photo-map-pin" data-photo-map aria-label="' . e(t('public.show_photo_location', 'Show photo location')) . '" title="' . e(t('public.show_photo_location', 'Show photo location')) . '">&#128205;</button>';
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
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $photoPagination : [], t('pagination.photo_pages', 'Photo pages'));
    }
    if ($children || $images) {
        echo '</div>';
        render_back_to_top_button();
        echo '</div>';
    }
    render_lightbox($votingAllowed, $mapsAllowed);
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
    $label = $kind === 'gallery' ? t('public.reorder.subgalleries', 'subgalleries') : t('public.reorder.photos', 'photos');
    // $endpoint stores the existing backend route or a small public-page wrapper around it.
    $endpoint = $kind === 'gallery' ? url_for('admin_reorder_public_galleries') : url_for('admin_reorder_images');

    echo '<div class="public-reorder-toolbar" data-public-reorder-toolbar data-reorder-kind="' . e($kind) . '" data-reorder-url="' . e($endpoint) . '" data-gallery-id="' . (int) $gallery['id'] . '" data-visible-offset="' . $offset . '" data-visible-count="' . $visibleCount . '" data-total-count="' . $totalCount . '" data-csrf-token="' . e(csrf_token()) . '">';
    echo '<div><strong>' . e(t('public.reorder.move_visible_items', 'Move visible {items}', ['items' => $label])) . '</strong><p>' . e(t('public.reorder.visible_page_help', 'Drag only the cards shown on this page. Other pagination pages are not touched.')) . '</p></div>';
    echo '<span class="public-reorder-status" data-public-reorder-status aria-live="polite">' . e(t('public.reorder.ready', 'Ready.')) . '</span>';
    echo '</div>';
}

/**
 * Render the logged-in public gallery Picture manager toolbar.
 *
 * The toolbar keeps discovery visible instead of relying only on hidden
 * modifier-key gestures. It intentionally stays on the public gallery page and
 * posts to small JSON endpoints that delegate mutation work to services.
 */
function render_picture_manager_toolbar(array $gallery, bool $hasVisibleDropTargets): void
{
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }

    // $galleryId stores the source gallery ID for all manager actions.
    $galleryId = (int) $gallery['id'];
    // $suggestedDestinationId stores the most likely child gallery destination for typeahead prefill.
    $suggestedDestinationId = function_exists('likely_gallery_destination_id') ? likely_gallery_destination_id($galleryId) : 0;
    // $dropHelp stores the drag-and-drop hint appropriate for the current visible page.
    $dropHelp = $hasVisibleDropTargets
        ? t('picture_manager.drop_help_visible', 'Drag selected photos onto a visible subgallery, or use the destination list below.')
        : t('picture_manager.drop_help_hidden', 'No subgallery target is visible on this page. Use the destination list below.');

    echo '<section class="picture-manager-toolbar is-picture-manager-collapsed" data-picture-manager data-source-gallery-id="' . $galleryId . '" data-csrf-token="' . e(csrf_token()) . '" data-move-url="' . e(url_for('picture_manager_move')) . '" data-copy-url="' . e(url_for('picture_manager_copy')) . '" data-create-url="' . e(url_for('picture_manager_create_gallery')) . '">';
    echo '<div class="picture-manager-summary">';
    echo '<button type="button" class="picture-manager-toggle" data-picture-manager-toggle aria-expanded="false">';
    echo '<span class="picture-manager-toggle-icon" aria-hidden="true">▸</span>';
    echo '<span><strong>' . e(t('picture_manager.title', 'Picture manager')) . '</strong><small>' . e(t('picture_manager.collapsed_help', 'Select, move, copy, or create galleries from visible photos.')) . '</small></span>';
    echo '</button>';
    echo '<span class="picture-manager-count" data-picture-manager-count aria-live="polite">' . e(t('picture_manager.none_selected', 'No photos selected.')) . '</span>';
    echo '</div>';

    echo '<div class="picture-manager-panel" data-picture-manager-panel>';
    echo '<div class="picture-manager-heading">';
    echo '<div class="picture-manager-hints"><p>' . e(t('picture_manager.help', 'Select photos with the checkmarks. Shift-click selects a range. Ctrl-click or Cmd-click toggles one photo.')) . '</p><p>' . e($dropHelp) . '</p></div>';
    echo '<div class="picture-manager-actions" aria-label="' . e(t('picture_manager.selection_actions', 'Selection actions')) . '">';
    echo '<button type="button" class="button secondary picture-manager-icon-button" data-picture-manager-select-all title="' . e(t('picture_manager.select_all', 'Select all')) . '" aria-label="' . e(t('picture_manager.select_all', 'Select all')) . '"><span class="picture-manager-button-icon" aria-hidden="true">☑</span><span class="picture-manager-button-label">' . e(t('picture_manager.select_all_short', 'All')) . '</span></button>';
    echo '<button type="button" class="button secondary picture-manager-icon-button" data-picture-manager-clear title="' . e(t('picture_manager.clear_selection', 'Clear selection')) . '" aria-label="' . e(t('picture_manager.clear_selection', 'Clear selection')) . '" disabled><span class="picture-manager-button-icon" aria-hidden="true">×</span><span class="picture-manager-button-label">' . e(t('picture_manager.clear_selection_short', 'Clear')) . '</span></button>';
    echo '</div>';
    echo '</div>';

    echo '<div class="picture-manager-action-grid">';
    echo '<div class="picture-manager-action-card">';
    echo '<label for="picture-manager-destination-' . $galleryId . '">' . e(t('picture_manager.move_or_copy_to', 'Move or copy selected to gallery')) . '</label>';
    echo '<div class="picture-manager-inline-fields">';
    echo render_gallery_search_picker('', 0, $galleryId, [
        'id' => 'picture-manager-destination-' . $galleryId,
        'placeholder' => t('picture_manager.search_destination', 'Search target gallery'),
        'prefill_gallery_id' => $suggestedDestinationId,
        'hidden_attributes' => ['data-picture-manager-destination' => ''],
    ]);
    echo '<button type="button" class="button picture-manager-icon-button is-primary-action" data-picture-manager-move title="' . e(t('picture_manager.move_selected', 'Move selected')) . '" aria-label="' . e(t('picture_manager.move_selected', 'Move selected')) . '" disabled><span class="picture-manager-button-icon" aria-hidden="true">↪</span><span class="picture-manager-button-label">' . e(t('picture_manager.move_short', 'Move')) . '</span></button>';
    echo '<button type="button" class="button secondary picture-manager-icon-button" data-picture-manager-copy title="' . e(t('picture_manager.copy_selected', 'Copy selected')) . '" aria-label="' . e(t('picture_manager.copy_selected', 'Copy selected')) . '" disabled><span class="picture-manager-button-icon" aria-hidden="true">⧉</span><span class="picture-manager-button-label">' . e(t('picture_manager.copy_short', 'Copy')) . '</span></button>';
    echo '</div>';
    echo '<p>' . e(t('picture_manager.move_copy_warning', 'Move removes photos from this gallery. Copy keeps the originals here and creates real file copies in the selected gallery.')) . '</p>';
    echo '</div>';

    echo '<div class="picture-manager-action-card">';
    echo '<label for="picture-manager-new-title-' . $galleryId . '">' . e(t('picture_manager.create_from_selection', 'Create gallery from selected photos')) . '</label>';
    echo '<div class="picture-manager-inline-fields">';
    echo '<input id="picture-manager-new-title-' . $galleryId . '" type="text" data-picture-manager-new-title placeholder="' . e(t('picture_manager.new_gallery_title', 'New gallery title')) . '">';
    echo '<input type="text" data-picture-manager-new-folder placeholder="' . e(t('picture_manager.optional_folder_name', 'Optional folder name')) . '">';
    echo '<button type="button" class="button picture-manager-icon-button is-primary-action" data-picture-manager-create title="' . e(t('picture_manager.create_gallery', 'Create gallery')) . '" aria-label="' . e(t('picture_manager.create_gallery', 'Create gallery')) . '" disabled><span class="picture-manager-button-icon" aria-hidden="true">＋</span><span class="picture-manager-button-label">' . e(t('picture_manager.create_short', 'Create')) . '</span></button>';
    echo '</div>';
    echo '<p>' . e(t('picture_manager.copy_warning', 'This copies selected photos into the new child gallery. Originals stay here.')) . '</p>';
    echo '</div>';
    echo '</div>';

    echo '<p class="picture-manager-status" data-picture-manager-status aria-live="polite">' . e(t('picture_manager.ready', 'Ready.')) . '</p>';
    echo '</div>';
    echo '</section>';
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
        echo '<span><strong>' . e(t('public.preview.active_title', 'Anonymous preview active.')) . '</strong> ' . e(t('public.preview.active_message', 'Admin controls are hidden and visitor visibility rules are being applied.')) . '</span>';
        echo '<a class="button" href="' . e($targetUrl) . '">' . e(t('public.preview.exit', 'Exit preview')) . '</a>';
    } else {
        echo '<span>' . e(t('public.preview.help', 'Review this gallery without inline admin controls, admin navigation, hidden photos, or admin-only visibility.')) . '</span>';
        echo '<a class="button secondary" href="' . e($targetUrl) . '">' . e(t('public.preview.view_as_anonymous', 'View as anonymous')) . '</a>';
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
    // $description stores the public gallery description without SEO fallback text.
    $description = (string) ($gallery['description'] ?? '');
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
    render_gallery_date($gallery, 'hero-gallery-date');
    if (trim($description) !== '') {
        echo '<div class="hero-description gallery-description-rich">' . gallery_description_markdown_html($description) . '</div>';
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
    echo '<nav class="breadcrumbs" aria-label="' . e(t('public.breadcrumbs', 'Breadcrumbs')) . '">';
    echo '<a href="' . e(url_for('home')) . '">' . e(t('public.galleries', 'Galleries')) . '</a>';
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
            render_gallery_access_gate($gallery, t('gallery.access.nsfw_account_ineligible', 'This account is not eligible to access 18+ content.'), $image);
            return;
        }
        if (empty($_POST['adult_confirmed'])) {
            render_gallery_access_gate($gallery, t('gallery.access.nsfw_confirm_required', 'You must confirm that you are at least 18 years old.'), $image);
            return;
        }
        grant_nsfw_guard_access();
        redirect_to($image ? image_public_url($image, $gallery) : gallery_public_url($gallery));
    }
    // $requirement stores an intermediate value used by the surrounding gallery workflow.
    $requirement = gallery_access_requirement($gallery);
    if (!$requirement || empty($requirement['access_password_hash'])) {
        render_gallery_access_gate($gallery, t('gallery.access.password_not_configured', 'This gallery does not have a password login configured.'));
        return;
    }
    // $password stores an intermediate value used by the surrounding gallery workflow.
    $password = (string) ($_POST['gallery_password'] ?? '');
    if (!password_verify($password, (string) $requirement['access_password_hash'])) {
        render_gallery_access_gate($gallery, t('gallery.access.password_incorrect', 'The password is incorrect.'));
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
 * Return stable loading attributes for public gallery and photo thumbnails.
 *
 * The first visible cards should load during the initial page render, because
 * lazy-loading a whole first row can leave empty thumbnail slots that then pop
 * in one by one. Later rows remain lazy so large galleries do not start too
 * many image requests at once.
 */
function public_thumbnail_loading_attributes(int $index): string
{
    if ($index < 2) {
        return 'loading="eager" fetchpriority="high"';
    }
    if ($index < 8) {
        return 'loading="eager" fetchpriority="auto"';
    }
    return 'loading="lazy" fetchpriority="low"';
}

/**
 * Handles render gallery card logic for the gallery application.
 * @param mixed $gallery Input used by this operation.
 * @param mixed $publicOnly Input used by this operation.
 * @param mixed $cardIndex Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_gallery_card(array $gallery, bool $publicOnly, bool $showPublicReorderHandle = false, bool $showSubgalleryBadge = false, int $cardIndex = 0): void
{
    // $isProtectedPublicCard stores an intermediate value used by the surrounding gallery workflow.
    $isProtectedPublicCard = $publicOnly && gallery_access_requirement($gallery) !== null;
    // $descriptionLayout stores the visual layout selected by Theme or the gallery override.
    $descriptionLayout = gallery_effective_description_layout($gallery);
    // Variable $cover stores this steps working value.
    $coverAsset = $isProtectedPublicCard ? '' : public_render_profile_span('gallery_cover_asset_lookup', static fn (): string => gallery_cover_asset_url($gallery, $publicOnly));
    // $cover stores an intermediate value used by the surrounding gallery workflow.
    $cover = $isProtectedPublicCard || $coverAsset !== '' ? null : public_render_profile_span('gallery_cover_image_lookup', static fn (): ?array => gallery_cover_image((int) $gallery['id'], $publicOnly));
    // $showCountBadge stores whether this card should show the contained-picture badge.
    $showCountBadge = !$isProtectedPublicCard && $showSubgalleryBadge && gallery_effective_count_badge_enabled($gallery);
    // Variable $branchImageCount stores this steps working value.
    $branchImageCount = $showCountBadge ? public_render_profile_span('gallery_branch_image_count', static fn (): int => gallery_branch_image_count((int) $gallery['id'], $publicOnly)) : 0;
    // $galleryCardTags stores the tags shown in the card body. Own gallery tags win, then contained tags keep the old fallback useful.
    $galleryCardTags = $isProtectedPublicCard ? [] : public_render_profile_span('gallery_card_tag_lookup', static function () use ($gallery, $publicOnly): array {
        // $ownTags stores tags attached directly to the gallery record.
        $ownTags = tags_for_entity('gallery', (int) $gallery['id']);
        if ($ownTags) {
            return $ownTags;
        }
        return contained_tags_for_gallery($gallery, $publicOnly);
    });
    // $descriptionPreview stores the card-length Markdown source. Full text remains visible in the opened gallery hero.
    $descriptionPreview = gallery_description_markdown_excerpt((string) ($gallery['description'] ?? ''));
    // $descriptionHtml stores the safe rendered card description.
    $descriptionHtml = gallery_description_markdown_html($descriptionPreview);
    // $effectiveVisibility stores the normalized card visibility used for admin-only visual state markers.
    $effectiveVisibility = gallery_effective_visibility($gallery);
    // $showAdminUnpublishedMarker keeps unpublished galleries visible to admins while making their non-public state obvious.
    $showAdminUnpublishedMarker = current_user() && !admin_anonymous_preview_active() && $effectiveVisibility === 'unpublished';
    $galleryCardClass = 'gallery-card is-gallery-description-' . $descriptionLayout . ($isProtectedPublicCard ? ' is-protected-gallery' : '') . ($showPublicReorderHandle ? ' has-public-reorder-handle' : '') . ($showAdminUnpublishedMarker ? ' is-admin-unpublished-gallery' : '');
    echo '<article class="' . e($galleryCardClass) . '" data-gallery-id="' . (int) $gallery['id'] . '" data-gallery-visibility="' . e($effectiveVisibility) . '" data-public-gallery-order-item data-public-order-id="' . (int) $gallery['id'] . '">';
    if ($showAdminUnpublishedMarker) {
        echo '<span class="admin-gallery-visibility-marker" title="' . e(t('gallery.card.unpublished_admin_hint', 'Only logged-in admins can see this gallery in listings.')) . '">' . e(t('gallery.visibility.unpublished', 'unpublished')) . '</span>';
    }
    if ($showPublicReorderHandle) {
        echo '<button type="button" class="public-reorder-handle public-gallery-reorder-handle" data-public-reorder-handle aria-label="' . e(t('gallery.reorder.drag_subgallery_aria', 'Drag subgallery to reorder visible subgalleries')) . '" title="' . e(t('gallery.reorder.drag_subgallery_title', 'Drag to reorder this visible subgallery')) . '"><span aria-hidden="true">↕</span><span>' . e(t('gallery.reorder.move_gallery', 'Move gallery')) . '</span></button>';
    }
    echo '<a class="gallery-card-media" href="' . e(gallery_public_url($gallery)) . '" aria-label="' . e(t('gallery.card.open_gallery', 'Open gallery {title}', ['title' => (string) $gallery['title']])) . '">';
    if ($showCountBadge) {
        echo '<span class="subgallery-stack-badge" aria-label="' . e(t('gallery.card.subgallery_image_count', 'Subgallery containing {count} images', ['count' => (int) $branchImageCount])) . '"><span class="subgallery-stack-icon" aria-hidden="true"><span></span><span></span><span></span></span><span class="subgallery-stack-count">' . (int) $branchImageCount . '</span></span>';
    }
    // $coverLoadingAttributes keeps above-the-fold gallery cards eager without forcing later rows to compete for bandwidth.
    $coverLoadingAttributes = public_thumbnail_loading_attributes($cardIndex);
    if ($isProtectedPublicCard) {
        echo '<span class="gallery-collage gallery-locked-preview" aria-hidden="true">' . e(t('gallery.card.protected', 'Protected')) . '</span>';
    } elseif ($coverAsset !== '') {
        echo '<img decoding="async" ' . $coverLoadingAttributes . ' src="' . e($coverAsset) . '" alt="">';
    } elseif ($cover) {
        $coverThumbnailBundle = public_render_profile_span('subgallery_cover_thumbnail_bundle', static fn (): array => thumbnail_bundle($cover));
        echo public_render_profile_with_thumbnail_purpose('subgallery cover stable picture', static fn (): string => thumbnail_picture_html($cover, 300, [300, 600, 800, 960], '(max-width: 299px) 300px, 800px', '', $coverLoadingAttributes, $coverThumbnailBundle));
    } else {
        // Variable $collage stores this steps working value.
        $collage = public_render_profile_span('gallery_cover_collage_lookup', static fn (): array => gallery_cover_collage_images((int) $gallery['id'], $publicOnly));
        if ($collage) {
            echo '<span class="gallery-collage collage-count-' . count($collage) . '">';
            foreach ($collage as $image) {
                // Collage images must not use progressive replacement. A metagallery card contains several child covers, and independent delayed srcset upgrades make the card repaint in visible waves. Render a stable srcset immediately and let the browser choose the best candidate once.
                $collageThumbnailBundle = public_render_profile_span('subgallery_collage_thumbnail_bundle', static fn (): array => thumbnail_bundle($image));
                echo public_render_profile_with_thumbnail_purpose('subgallery collage stable picture', static fn (): string => thumbnail_picture_html($image, 300, [300, 600, 800], '(max-width: 520px) 300px, 420px', '', $coverLoadingAttributes, $collageThumbnailBundle));
            }
            echo '</span>';
        }
    }
    echo '</a>';
    echo '<div class="gallery-card-body"><h2><a class="gallery-card-title-link" href="' . e(gallery_public_url($gallery)) . '">' . e($gallery['title']) . '</a></h2>';
    if ($descriptionLayout === 'horizontal' && !$isProtectedPublicCard && ($galleryCardTags || gallery_date_display_value($gallery['gallery_date'] ?? null) !== '')) {
        echo '<div class="gallery-card-meta-row">';
        render_gallery_date($gallery, 'gallery-card-date');
        render_compact_tag_list($galleryCardTags);
        echo '</div>';
    } elseif (!$isProtectedPublicCard) {
        render_gallery_date($gallery, 'gallery-card-date');
    }
    if ($descriptionHtml !== '') {
        echo '<div class="gallery-card-description gallery-card-description-rich">' . $descriptionHtml . '</div>';
    }
    if ($isProtectedPublicCard) {
        echo '<p class="muted gallery-card-count">' . e(t('gallery.card.protected_gallery', 'Protected gallery')) . '</p>';
    } else {
        if ($showCountBadge) {
            echo '<p class="muted gallery-card-count gallery-card-count-visual-hidden">' . e(t('gallery.image_count', '{count} images', ['count' => (int) $branchImageCount])) . '</p>';
        }
        if ($descriptionLayout !== 'horizontal') {
            render_tag_list($galleryCardTags, t('gallery.containing_tags', 'Containing tags'));
        }
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
    $label = $placement === 'hero' ? t('gallery.add_here', 'Add gallery here') : t('gallery.add_inside', 'Add gallery inside {title}', ['title' => (string) $gallery['title']]);
    $class = $placement === 'hero' ? 'public-admin-add-gallery-button public-admin-add-gallery-button-hero hero-icon-button' : 'public-admin-add-gallery-button public-admin-add-gallery-button-card';
    $url = url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id']]);
    $panelUrl = url_for('admin_upload', ['upload_mode' => 'new', 'parent_id' => $gallery['id'], 'panel' => 1]);
    echo '<a class="' . e($class) . '" href="' . e($url) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="upload" data-admin-side-panel-kicker="' . e(t('gallery.workflow', 'Gallery workflow')) . '" data-admin-side-panel-title="' . e(t('gallery.add_here', 'Add gallery here')) . '" data-gallery-side-panel-url="' . e($panelUrl) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">+</span><span class="visually-hidden">' . e($label) . '</span></a>';
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
    $label = $placement === 'hero' ? t('gallery.edit_current', 'Edit current gallery') : t('gallery.edit_named', 'Edit gallery {title}', ['title' => (string) $gallery['title']]);
    $class = $placement === 'hero' ? 'public-admin-edit-button public-admin-edit-button-hero' : 'public-admin-edit-button public-admin-edit-button-card';
    echo '<a class="' . e($class) . '" href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="gallery-edit" data-admin-side-panel-kicker="' . e(t('gallery.editor', 'Gallery editor')) . '" data-admin-side-panel-title="' . e(t('gallery.edit', 'Edit gallery')) . '" data-gallery-side-panel-url="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id'], 'panel' => 1])) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#9998;</span><span class="visually-hidden">' . e($label) . '</span></a>';
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
    $label = $placement === 'hero' ? t('gallery.remove_current', 'Remove current gallery from CMS') : t('gallery.remove_named', 'Remove gallery {name} from CMS', ['name' => $name]);
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
    $label = t('gallery.edit_photo_named', 'Edit photo {name}', ['name' => $name]);
    echo '<a class="public-admin-edit-button public-admin-edit-button-card public-admin-edit-button-photo" href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="image-edit" data-admin-side-panel-kicker="' . e(t('gallery.photo_editor', 'Photo editor')) . '" data-admin-side-panel-title="' . e(t('gallery.edit_photo', 'Edit photo')) . '" data-gallery-side-panel-url="' . e(url_for('admin_edit_image', ['id' => $image['id'], 'panel' => 1])) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#9998;</span><span class="visually-hidden">' . e($label) . '</span></a>';
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
    $label = t('gallery.remove_photo_named', 'Remove photo {name} from CMS', ['name' => $name]);
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
function lightbox_image_data_attributes(array $image, array $gallery, string $mediaUrl, string $previewUrl, string $imagePageUrl, string $displayTitle, int $score, int $vote, ?array $imageMapPoint, string $sourceAttribute, bool $votingAllowed = true, ?int $lightboxIndex = null): string
{
    // $mapPointAttribute stores the optional GPS payload used by map-enabled photos.
    $mapPointAttribute = $imageMapPoint ? ' data-map-point="' . e(json_encode($imageMapPoint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"' : '';
    // $votingAttribute marks whether a cloned gallery-card vote form should exist for this image.
    $votingAttribute = $votingAllowed ? ' data-voting-allowed="1"' : '';
    // $indexAttribute stores the zero-based async lightbox order position when known.
    $indexAttribute = $lightboxIndex !== null ? ' data-lightbox-index="' . max(0, $lightboxIndex) . '"' : '';
    return $sourceAttribute
        . ' data-image-id="' . (int) $image['id'] . '"'
        . $indexAttribute
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
        . $votingAttribute
        . $mapPointAttribute;
}

/**
 * Render an inert vote template for hidden lightbox source nodes.
 *
 * Visible photo cards already contain the active gallery-card voting form. Hidden
 * pagination source nodes need the same server-rendered widget without creating
 * another live form on the page. The browser does not submit or bind controls
 * inside <template>, so the lightbox can safely clone it when that image opens.
 */
function render_lightbox_vote_template(int $imageId, int $score, int $vote, bool $votingAllowed): void
{
    // $voteFormHtml stores the exact same server-rendered widget used by visible gallery cards.
    $voteFormHtml = render_vote_form_html($imageId, $score, $vote, $votingAllowed);
    if ($voteFormHtml === '') {
        return;
    }

    echo '<template data-lightbox-vote-template>' . $voteFormHtml . '</template>';
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
        // $votingAllowed stores whether this hidden source needs a reusable server-rendered vote widget.
        $votingAllowed = gallery_voting_allowed($gallery);
        echo '<div ' . lightbox_image_data_attributes($image, $gallery, $mediaUrl, $previewUrl, $imagePageUrl, $displayTitle, (int) $image['score'], $vote, $imageMapPoint, $sourceAttribute, $votingAllowed) . '>';
        render_lightbox_vote_template((int) $image['id'], (int) $image['score'], $vote, $votingAllowed);
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Handles render lightbox logic for the gallery application.
 * @param mixed $votingAllowed Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_lightbox(bool $votingAllowed = true, bool $mapsAllowed = false): void
{
    // $votePanelHtml is a host for the exact gallery-card vote widget.
    // JavaScript clones the current image's server-rendered form into it.
    $votePanelHtml = $votingAllowed ? '<div class="lightbox-vote-panel" data-lightbox-vote-panel hidden></div>' : '';

    echo '<div class="lightbox" data-lightbox data-lightbox-maps-enabled="' . ($mapsAllowed ? '1' : '0') . '" data-lightbox-slideshow-visible-ms="2000" data-lightbox-slideshow-transition-ms="1000" hidden>';
    echo '<button class="lightbox-close lightbox-hud" type="button" data-lightbox-action="close">' . e(t('lightbox.close', 'Close')) . '</button>';
    echo '<button type="button" class="lightbox-nav lightbox-previous lightbox-hud" data-lightbox-action="previous" aria-label="' . e(t('lightbox.previous_image', 'Previous image')) . '">&lt;</button>';
    echo '<figure><button type="button" class="lightbox-stage-link" data-lightbox-stage aria-label="' . e(t('lightbox.toggle_fullscreen_image', 'Toggle fullscreen image')) . '"><span class="lightbox-initial-loader" data-lightbox-initial-loader data-lightbox-loading-count-template="' . e(t('lightbox.initial_loader_count', 'Preparing photo {current} of {total}')) . '" hidden><span class="lightbox-initial-loader-label" data-lightbox-initial-loader-label>' . e(t('lightbox.initial_loader', 'Preparing lightbox')) . '</span><span class="lightbox-initial-loader-track" aria-hidden="true"><span class="lightbox-initial-loader-fill" data-lightbox-initial-loader-fill></span></span><span class="lightbox-initial-loader-count" data-lightbox-initial-loader-count></span></span><img decoding="async" data-lightbox-img alt=""></button><figcaption class="lightbox-meta"><div class="lightbox-toolbar"><span class="lightbox-counter" data-lightbox-counter></span><button type="button" class="lightbox-fullscreen-link" data-lightbox-action="fullscreen" aria-label="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '" title="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '">F ' . e(t('lightbox.fullscreen', 'fullscreen')) . '</button><button type="button" class="lightbox-slideshow-link" data-lightbox-action="slideshow" aria-label="' . e(t('lightbox.toggle_slideshow', 'Toggle slideshow')) . '" title="' . e(t('lightbox.toggle_slideshow', 'Toggle slideshow')) . '" aria-pressed="false">S ' . e(t('lightbox.slideshow', 'slideshow')) . '</button><button type="button" class="lightbox-map-button" data-lightbox-map hidden>&#128205; ' . e(t('lightbox.map', 'Map')) . '</button>' . $votePanelHtml . '</div><h2 data-lightbox-title></h2><p class="lightbox-description" data-lightbox-description></p></figcaption><div class="lightbox-map-split" data-lightbox-map-split hidden><button type="button" class="lightbox-map-split-close" data-lightbox-map-split-close aria-label="' . e(t('lightbox.close_map_split', 'Close map split')) . '">' . e(t('lightbox.close_map', 'Close map')) . '</button><div class="lightbox-map-split-title" data-lightbox-map-split-title></div><div class="lightbox-map-split-canvas" data-lightbox-map-split-canvas></div></div></figure>';
    echo '<button type="button" class="lightbox-nav lightbox-next lightbox-hud" data-lightbox-action="next" aria-label="' . e(t('lightbox.next_image', 'Next image')) . '">&gt;</button>';
    echo '<button type="button" class="lightbox-fullscreen-button lightbox-hud" data-lightbox-action="fullscreen" aria-label="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '" title="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '">F</button>';
    echo '<button type="button" class="lightbox-slideshow-button lightbox-hud" data-lightbox-action="slideshow" aria-label="' . e(t('lightbox.toggle_slideshow', 'Toggle slideshow')) . '" title="' . e(t('lightbox.toggle_slideshow', 'Toggle slideshow')) . '" aria-pressed="false">S</button>';
    echo '<button type="button" class="lightbox-mobile-fullscreen-button" data-lightbox-action="fullscreen" aria-label="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '" title="' . e(t('lightbox.toggle_fullscreen', 'Toggle fullscreen')) . '">&#9974;</button>';
    echo '</div>';
}

