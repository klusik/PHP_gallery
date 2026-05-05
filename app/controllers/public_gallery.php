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
    // $listingCondition stores an intermediate value used by the surrounding gallery workflow.
    $listingCondition = public_gallery_listing_condition('g');
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT g.*, COUNT(i.id) AS image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'
        WHERE $listingCondition AND g.parent_id IS NULL
        GROUP BY g.id
        ORDER BY g.sort_order, g.title");
    $stmt->execute();
    // Variable $galleries stores this steps working value.
    $galleries = $stmt->fetchAll();
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
        foreach ($galleries as $gallery) {
            render_gallery_card($gallery, true);
        }
        echo '</section>';
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $galleryPagination : [], 'Gallery pages');
        render_back_to_top_button();
        echo '</div>';
    }
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
    // Variable $viewer stores this steps working value.
    $viewer = current_user();
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
    if (!$gallery || ($gallery['visibility'] !== 'public' && !$viewer)) {
        cms_not_found();
        return;
    }
    if (!$viewer && !visitor_can_access_gallery($gallery)) {
        render_gallery_access_gate($gallery);
        return;
    }
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
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare($sql);
    $stmt->execute([(int) $gallery['id']]);
    // Variable $images stores this steps working value.
    $images = $stmt->fetchAll();
    // Variable $allImages stores the complete sorted image list before optional pagination slicing.
    $allImages = $images;
    // Variable $imageIds stores this steps working value.
    $imageIds = array_map(static fn (array $image): int => (int) $image['id'], $images);
    // Variable $imageTagsById stores this steps working value.
    $imageTagsById = tags_for_entities('image', $imageIds);
    // Variable $votesById stores this steps working value.
    $votesById = current_votes_for_images($imageIds);
    // Variable $children stores this steps working value.
    $children = child_galleries((int) $gallery['id'], $publicOnly);
    // Variable $mapsAllowed stores this steps working value.
    $mapsAllowed = gallery_allows_gps_maps($gallery);
    // Variable $galleryMapPoints stores this steps working value.
    $galleryMapPoints = $mapsAllowed ? gallery_map_points($gallery, $publicOnly, true) : [];
    // Variable $votingAllowed stores this steps working value.
    $votingAllowed = gallery_voting_allowed($gallery);
    // Variable $pictureGameImages stores this steps working value.
    $pictureGameImages = picture_game_images($gallery);
    // Variable $paginationSettings stores this steps working value.
    $paginationSettings = gallery_effective_grid_settings($gallery);
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
    $backgroundAssetUrl = gallery_background_asset_url($gallery, $publicOnly);
    // Variable $seo stores this steps working value.
    $seo = public_gallery_metadata($gallery);
    ob_start();
    render_public_seo_tags($gallery, $allImages);
    render_gallery_json_ld($gallery, $allImages);
    append_cms_head_extras((string) ob_get_clean());
    if ($backgroundAssetUrl !== '') {
        append_cms_head_extras('<style>.theme-background-image{background-image:url("' . css_value($backgroundAssetUrl) . '");}</style>');
    }

    render_header((string) $seo['title']);
    echo '<section class="hero"><h1>' . e((string) $seo['title']) . '</h1><p>' . e((string) $seo['description']) . '</p>';
    render_tag_list(tags_for_entity('gallery', (int) $gallery['id']));
    if ($children) {
        render_tag_list(contained_tags_for_gallery($gallery, $publicOnly), 'Containing tags');
    }
    echo '<div class="hero-actions">';
    echo '<a class="button" href="' . e(url_for('download_gallery', ['id' => $gallery['id']])) . '">Download gallery</a>';
    if ($galleryMapPoints) {
        echo '<button type="button" class="button secondary map-button" data-gallery-map-url="' . e(url_for('gallery_map_data', ['id' => $gallery['id']])) . '" data-gallery-map-title="' . e((string) $gallery['title']) . '">Show gallery map</button>';
    }
    if (picture_game_available($gallery, $pictureGameImages)) {
        echo '<a class="button secondary" href="' . e(url_for('picture_game', ['id' => $gallery['id']])) . '">Play picture game</a>';
    }
    echo '</div>';
    render_breadcrumbs($gallery);
    echo '</section>';
    render_public_gallery_admin_form($gallery);
    if ($children || $images) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        echo '<div class="gallery-list-content" data-back-to-top-list>';
    }
    if ($children) {
        echo '<section class="panel"><h2>Subgalleries</h2>';
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $childPagination : [], 'Subgallery pages');
        echo '<div class="grid' . e(pagination_grid_columns_class($paginationSettings)) . '">';
        foreach ($children as $child) {
            render_gallery_card($child, true);
        }
        echo '</div>';
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $childPagination : [], 'Subgallery pages');
        echo '</section>';
    }
    if ($images) {
        render_pagination_controls(!empty($paginationSettings['enabled']) ? $photoPagination : [], 'Photo pages');
        echo '<section class="grid gallery-image-grid' . e(pagination_grid_columns_class($paginationSettings)) . '" data-gallery-image-list>';
    }
    foreach ($images as $index => $image) {
        // Variable $mediaUrl stores this steps working value.
        $mediaUrl = public_path_schema_ready() ? image_public_media_url($image, $gallery) : url_for('media', ['id' => $image['id']]);
        // Variable $imagePageUrl stores this steps working value.
        $imagePageUrl = image_public_url($image, $gallery);
        // Variable $previewUrl stores this steps working value.
        $previewUrl = thumbnail_url($image, 1600);
        // Variable $imageTags stores this steps working value.
        $imageTags = $imageTagsById[(int) $image['id']] ?? [];
        // Variable $imageHasPublicGps stores this steps working value.
        $imageHasPublicGps = $mapsAllowed && image_has_gps($image);
        // Variable $imageMapPoint stores this steps working value.
        $imageMapPoint = $imageHasPublicGps ? image_map_point($image, $gallery) : null;
        // Variable $displayIndex stores this steps working value.
        $displayIndex = $index + 1 + (!empty($paginationSettings['enabled']) ? (int) $photoPagination['offset'] : 0);
        // Variable $altText stores this steps working value.
        $altText = image_alt_text($image, $gallery, $displayIndex);
        // Variable $vote stores this steps working value.
        $vote = $votesById[(int) $image['id']] ?? 0;
        // Variable $displayTitle stores this steps working value.
        $displayTitle = public_image_display_title($image, $gallery);
        echo '<article class="image-card" ' . lightbox_image_data_attributes($image, $gallery, $mediaUrl, $previewUrl, $imagePageUrl, $displayTitle, (int) $image['score'], $vote, $imageMapPoint, 'data-lightbox-image') . '>';
        // $thumbnailSizesAttribute stores a responsive image hint derived from the configured grid.
        $thumbnailSizesAttribute = pagination_photo_thumbnail_sizes_attribute($paginationSettings);
        echo '<a class="image-preview-link" href="' . e($imagePageUrl) . '">' . thumbnail_picture_html($image, 300, [300, 600, 800, 960], $thumbnailSizesAttribute, $altText, 'loading="lazy" data-responsive-thumbnail') . '</a>';
        if ($imageMapPoint) {
            echo '<button type="button" class="photo-map-pin" data-photo-map aria-label="Show photo location" title="Show photo location">&#128205;</button>';
        }
        // Variable $hasPublicImageMeta stores whether the anonymous-facing metadata area has visible content.
        // Empty metadata is not rendered, because hidden file names should not leave a blank bar under the photo.
        $hasPublicImageMeta = $displayTitle !== '' || trim((string) $image['description']) !== '' || $imageTags || $votingAllowed;
        if ($hasPublicImageMeta) {
            echo '<div class="image-meta">';
            if ($displayTitle !== '') {
                echo '<h2>' . e($displayTitle) . '</h2>';
            }
            if (trim((string) $image['description']) !== '') {
                echo '<p>' . e($image['description']) . '</p>';
            }
            render_tag_list($imageTags);
            render_vote_form((int) $image['id'], (int) $image['score'], $vote, $votingAllowed);
            echo '</div>';
        }
        render_public_image_admin_form($image);
        echo '</article>';
    }
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
    render_footer();
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
        foreach (gallery_ancestors($gallery, true) as $ancestor) {
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
function render_gallery_access_gate(array $gallery, string $error = ''): void
{
    // $requirement stores an intermediate value used by the surrounding gallery workflow.
    $requirement = gallery_access_requirement($gallery) ?: $gallery;
    render_header((string) $gallery['title']);
    render_breadcrumbs($gallery);
    echo '<section class="panel"><h1>' . e($gallery['title']) . '</h1>';
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }
    if (empty($requirement['access_password_hash'])) {
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
    if (!$gallery || (string) $gallery['visibility'] !== 'public') {
        cms_not_found();
        return;
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
        $stmt = db()->prepare("SELECT * FROM galleries WHERE id = ? AND access_token_hash = ? AND visibility = 'public' LIMIT 1");
        $stmt->execute([$galleryId, hash('sha256', $token)]);
    } else {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->prepare("SELECT * FROM galleries WHERE access_token_hash = ? AND visibility = 'public' ORDER BY updated_at DESC, id DESC LIMIT 1");
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
function render_gallery_card(array $gallery, bool $publicOnly): void
{
    // $isProtectedPublicCard stores an intermediate value used by the surrounding gallery workflow.
    $isProtectedPublicCard = $publicOnly && gallery_access_requirement($gallery) !== null;
    // Variable $cover stores this steps working value.
    $coverAsset = $isProtectedPublicCard ? '' : gallery_cover_asset_url($gallery, $publicOnly);
    // $cover stores an intermediate value used by the surrounding gallery workflow.
    $cover = $isProtectedPublicCard || $coverAsset !== '' ? null : gallery_cover_image((int) $gallery['id'], $publicOnly);
    echo '<article class="gallery-card' . ($isProtectedPublicCard ? ' is-protected-gallery' : '') . '"><a class="gallery-card-link" href="' . e(gallery_public_url($gallery)) . '">';
    if ($isProtectedPublicCard) {
        echo '<span class="gallery-collage gallery-locked-preview" aria-hidden="true">Protected</span>';
    } elseif ($coverAsset !== '') {
        echo '<img decoding="async" loading="lazy" src="' . e($coverAsset) . '" alt="">';
    } elseif ($cover) {
        echo thumbnail_picture_html($cover, 800, [300, 800, 960], '(max-width: 299px) 300px, 800px', '', 'loading="lazy"');
    } else {
        // Variable $collage stores this steps working value.
        $collage = gallery_cover_collage_images((int) $gallery['id'], $publicOnly);
        if ($collage) {
            echo '<span class="gallery-collage collage-count-' . count($collage) . '">';
            foreach ($collage as $image) {
                echo thumbnail_picture_html($image, 800, [300, 800, 960], '(max-width: 299px) 300px, 800px', '', 'loading="lazy"');
            }
            echo '</span>';
        }
    }
    echo '<span class="gallery-card-body"><h2>' . e($gallery['title']) . '</h2>';
    echo '<p>' . e($gallery['description']) . '</p>';
    if ($isProtectedPublicCard) {
        echo '<p class="muted">Protected gallery</p></span>';
    } else {
        // Variable $branchImageCount stores this steps working value.
        $branchImageCount = gallery_branch_image_count((int) $gallery['id'], $publicOnly);
        echo '<p class="muted">' . $branchImageCount . ' images</p></span>';
    }
    echo '</a>';
    render_public_gallery_admin_form($gallery);
    if (!$isProtectedPublicCard) {
        render_tag_list(contained_tags_for_gallery($gallery, $publicOnly), 'Containing tags');
    }
    echo '</article>';
}

/**
 * Handles render public gallery admin form logic for the gallery application.
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_public_gallery_admin_form(array $gallery): void
{
    if (!current_user()) {
        return;
    }
    echo '<details class="inline-editor" data-admin-inline-editor><summary>Edit gallery</summary>';
    echo '<form method="post" action="' . e(url_for('admin_public_update_gallery')) . '" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<label>Gallery name<input name="title" value="' . e((string) $gallery['title']) . '" required></label>';
    echo '<label>Description<textarea name="description">' . e((string) $gallery['description']) . '</textarea></label>';
    if (gallery_filename_display_schema_ready()) {
        echo '<label><input type="checkbox" name="show_filenames" value="1"' . ((int) ($gallery['show_filenames'] ?? 0) === 1 ? ' checked' : '') . '> Show file names</label>';
    }
    echo '<div class="bulk-row"><button type="submit" name="action" value="save">Save</button>';
    echo '<button type="submit" class="secondary" name="action" value="publish">Publish</button>';
    echo '<button type="submit" class="secondary" name="action" value="hide">Hide from public</button>';
    echo '<button type="submit" class="secondary" name="action" value="delete">Remove from CMS</button>';
    echo '<a class="button secondary" href="' . e(url_for('admin_edit_gallery', ['id' => $gallery['id']])) . '">Admin edit</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_new_gallery', ['parent_id' => $gallery['id']])) . '">Create gallery here</a>';
    echo '<a class="button secondary" href="' . e(url_for('admin_upload', ['gallery_id' => $gallery['id']])) . '">Upload photos here</a></div>';
    echo '</form></details>';
}

/**
 * Handles render public image admin form logic for the gallery application.
 * @param mixed $image Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function render_public_image_admin_form(array $image): void
{
    if (!current_user()) {
        return;
    }
    echo '<details class="inline-editor image-inline-editor" data-admin-inline-editor><summary>Edit photo</summary>';
    echo '<form method="post" action="' . e(url_for('admin_public_update_image')) . '" class="form-grid">' . csrf_field();
    echo '<input type="hidden" name="image_id" value="' . (int) $image['id'] . '">';
    echo '<label>Photo title<input name="title" value="' . e((string) ($image['title'] ?? '')) . '"></label>';
    echo '<label>Description<textarea name="description">' . e((string) $image['description']) . '</textarea></label>';
    echo '<div class="bulk-row"><button type="submit" name="action" value="save">Save</button>';
    echo '<button type="submit" class="secondary" name="action" value="publish">Publish</button>';
    echo '<button type="submit" class="secondary" name="action" value="hide">Hide from public</button>';
    echo '<button type="submit" class="secondary" name="action" value="delete">Remove from CMS</button>';
    echo '<a class="button secondary" href="' . e(url_for('admin_edit_image', ['id' => $image['id']])) . '">Admin edit</a></div>';
    echo '</form></details>';
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
        // Variable $mediaUrl stores this steps working value.
        $mediaUrl = public_path_schema_ready() ? image_public_media_url($image, $gallery) : url_for('media', ['id' => $image['id']]);
        // Variable $imagePageUrl stores this steps working value.
        $imagePageUrl = image_public_url($image, $gallery);
        // Variable $previewUrl stores this steps working value.
        $previewUrl = thumbnail_url($image, 1600);
        // Variable $displayTitle stores this steps working value.
        $displayTitle = public_image_display_title($image, $gallery);
        // Variable $vote stores this steps working value.
        $vote = $votesById[(int) $image['id']] ?? 0;
        // Variable $imageMapPoint stores this steps working value.
        $imageMapPoint = $mapsAllowed && image_has_gps($image) ? image_map_point($image, $gallery) : null;
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
    echo '<figure><button type="button" class="lightbox-stage-link" data-lightbox-stage aria-label="Toggle fullscreen image"><img decoding="async" data-lightbox-img alt=""></button><figcaption class="lightbox-meta"><div class="lightbox-toolbar"><span class="lightbox-counter" data-lightbox-counter></span><button type="button" class="lightbox-fullscreen-link" data-lightbox-action="fullscreen" aria-label="Toggle fullscreen" title="Toggle fullscreen">F fullscreen</button><button type="button" class="lightbox-map-button" data-lightbox-map hidden>&#128205; Map</button></div><div class="lightbox-score-badge">Score <strong data-lightbox-score data-score-for="">0</strong></div><h2 data-lightbox-title></h2><p class="lightbox-description" data-lightbox-description></p>' . ($votingAllowed ? '<div class="lightbox-vote-panel"><form class="vote-row lightbox-vote" method="post" action="' . e(url_for('vote')) . '" data-vote-form data-lightbox-vote-form><input type="hidden" name="image_id" value="">' . csrf_field() . '<span class="lightbox-vote-label">Vote</span><button type="submit" name="vote" value="1" aria-label="Vote up" title="Vote up">&#9650;</button><button type="submit" name="vote" value="-1" aria-label="Vote down" title="Vote down">&#9660;</button><span class="lightbox-vote-indicator" data-lightbox-vote-indicator>No vote</span></form></div>' : '') . '</figcaption><div class="lightbox-map-split" data-lightbox-map-split hidden><button type="button" class="lightbox-map-split-close" data-lightbox-map-split-close aria-label="Close map split">Close map</button><div class="lightbox-map-split-title" data-lightbox-map-split-title></div><div class="lightbox-map-split-canvas" data-lightbox-map-split-canvas></div></div></figure>';
    echo '<button type="button" class="lightbox-nav lightbox-next lightbox-hud" data-lightbox-action="next" aria-label="Next image">&gt;</button>';
    echo '<button type="button" class="lightbox-fullscreen-button lightbox-hud" data-lightbox-action="fullscreen" aria-label="Toggle fullscreen" title="Toggle fullscreen">F</button>';
    echo '<button type="button" class="lightbox-mobile-fullscreen-button" data-lightbox-action="fullscreen" aria-label="Toggle fullscreen" title="Toggle fullscreen">&#9974;</button>';
    echo '</div>';
}

