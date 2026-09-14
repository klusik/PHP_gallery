<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/public_gallery_controls.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders gallery sorting, manager, access, share, breadcrumb, and preview controls.
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
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use RuntimeException;
use Throwable;
use function Gallery\Core\admin_anonymous_preview_active;
use function Gallery\Core\anonymous_preview_url;
use function Gallery\Core\append_cms_footer_script;
use function Gallery\Core\append_cms_head_extras;
use function Gallery\Core\csrf_field;
use function Gallery\Core\csrf_token;
use function Gallery\Core\flash_message;
use function Gallery\Core\css_value;
use function Gallery\Core\current_user;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\image_alt_text;
use function Gallery\Core\image_public_url;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\slugify;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\child_galleries;
use function Gallery\Services\child_galleries_tree_preload;
use function Gallery\Services\contained_tags_for_gallery;
use function Gallery\Services\current_user_is_known_under_18;
use function Gallery\Services\current_votes_for_images;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_access_find_by_token;
use function Gallery\Services\find_gallery_by_folder_path;
use function Gallery\Services\find_gallery_by_slug;
use function Gallery\Services\find_image;
use function Gallery\Services\gallery_access_lifetime_seconds;
use function Gallery\Services\gallery_benchmark_record_public_render;
use function Gallery\Services\gallery_access_requirement;
use function Gallery\Services\gallery_access_schema_ready;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\gallery_access_share_token_schema_status;
use function Gallery\Services\gallery_access_assert_public_policy_available;
use function Gallery\Services\gallery_allows_direct_public_request;
use function Gallery\Services\gallery_allows_gps_maps;
use function Gallery\Services\gallery_background_asset_url;
use function Gallery\Services\gallery_branch_image_count;
use function Gallery\Services\gallery_branch_image_counts;
use function Gallery\Services\gallery_branding_asset_url;
use function Gallery\Services\gallery_branding_schema_ready;
use function Gallery\Services\gallery_breadcrumb_ancestors;
use function Gallery\Services\gallery_cover_asset_url;
use function Gallery\Services\gallery_cover_collage_images;
use function Gallery\Services\gallery_cover_image;
use function Gallery\Services\gallery_date_range_display_value;
use function Gallery\Services\gallery_effective_count_badge_enabled;
use function Gallery\Services\gallery_effective_description_layout;
use function Gallery\Services\gallery_description_layout_normalize;
use function Gallery\Services\gallery_effective_grid_settings;
use function Gallery\Services\gallery_effective_lightbox_browsing_mode;
use function Gallery\Services\gallery_count_dated_rows;
use function Gallery\Services\gallery_effective_visibility;
use function Gallery\Services\gallery_has_map_payload;
use function Gallery\Services\gallery_lightbox_browsing_mode_normalize;
use function Gallery\Services\gallery_sort_row_has_start_date;
use function Gallery\Services\gallery_sort_rows_by_date_preserving_undated_positions;
use function Gallery\Services\gallery_lightbox_excludes_restricted_nsfw;
use function Gallery\Services\gallery_lightbox_fetch_images;
use function Gallery\Services\gallery_lightbox_image_position;
use function Gallery\Services\gallery_lightbox_total_count;
use function Gallery\Services\gallery_nsfw_requirement;
use function Gallery\Services\gallery_voting_allowed;
use function Gallery\Services\grant_gallery_public_access;
use function Gallery\Services\grant_nsfw_guard_access;
use function Gallery\Services\image_has_gps;
use function Gallery\Services\image_map_point;
use function Gallery\Services\image_nsfw_restricted;
use function Gallery\Services\likely_gallery_destination_id;
use function Gallery\Services\main_page_gallery_grid_settings;
use function Gallery\Services\pagination_current_page;
use function Gallery\Services\pagination_gallery_clean_url;
use function Gallery\Services\pagination_grid_columns_class;
use function Gallery\Services\pagination_home_gallery_clean_url;
use function Gallery\Services\pagination_model;
use function Gallery\Services\pagination_photo_thumbnail_sizes_attribute;
use function Gallery\Services\pagination_slice_items;
use function Gallery\Services\picture_game_available;
use function Gallery\Services\public_gallery_media_manifest;
use function Gallery\Services\public_gallery_metadata;
use function Gallery\Services\public_home_search_enabled;
use function Gallery\Services\public_image_display_title;
use function Gallery\Services\public_responsive_thumbnail_loading_attributes;
use function Gallery\Services\public_thumbnail_render_picture_html;
use function Gallery\Services\public_thumbnail_rendering_mode;
use function Gallery\Services\public_path_schema_ready;
use function Gallery\Services\public_render_profile_count;
use function Gallery\Services\public_render_profile_db;
use function Gallery\Services\public_render_profile_set_gallery;
use function Gallery\Services\public_render_profile_span;
use function Gallery\Services\public_render_profile_snapshot;
use function Gallery\Services\public_render_profile_start;
use function Gallery\Services\public_render_profile_with_thumbnail_purpose;
use function Gallery\Services\public_search_normalize_query;
use function Gallery\Services\public_search_query_length;
use function Gallery\Services\public_search_results;
use function Gallery\Services\gallery_date_view_model;
use function Gallery\Views\view_render_gallery_date;
use function Gallery\Views\view_render_pagination_controls;
use function Gallery\Services\resolve_public_gallery_path;
use function Gallery\Services\site_name;
use function Gallery\Services\t;
use function Gallery\Services\tags_for_entities;
use function Gallery\Services\tags_for_entity;
use function Gallery\Services\sort_public_hero_tag_groups;
use function Gallery\Services\theme_hero_tag_display_all_enabled;
use function Gallery\Services\theme_hero_tag_scrollbar_enabled;
use function Gallery\Services\theme_hero_tag_scrollbar_rows;
use function Gallery\Services\theme_hero_tag_sort_mode;
use function Gallery\Services\theme_hero_tag_visible_limit;
use function Gallery\Services\telemetry_append_public_script;
use function Gallery\Services\thumbnail_bundle;
use function Gallery\Services\thumbnail_bundle_url;
use function Gallery\Services\thumbnail_bundles_preload;
use function Gallery\Services\thumbnail_picture_html;
use function Gallery\Services\visitor_can_access_gallery;
use function Gallery\Services\visitor_can_access_nsfw_content;
use function Gallery\Views\view_render_gallery_json_ld;
use function Gallery\Views\view_render_public_seo_tags;
use function Gallery\Services\admin_log_event;

/**
 * Return the requested admin-only subgallery date sort mode.
 *
 * @return string Sort mode: asc, desc, or an empty string for default order.
 */
function public_subgallery_date_sort_mode(): string
{
    // $mode stores the raw query value accepted from the public gallery URL.
    $mode = strtolower(trim((string) ($_GET[PUBLIC_SUBGALLERY_DATE_SORT_PARAM] ?? '')));
    return in_array($mode, ['asc', 'desc'], true) ? $mode : '';
}

/**
 * Return whether a gallery row has a filled start date for date sorting.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the gallery has a usable start date.
 */
function public_subgallery_has_start_date(array $gallery): bool
{
    return gallery_sort_row_has_start_date($gallery);
}

/**
 * Count direct child galleries that have a filled start date.
 *
 * @param array $children Direct child gallery rows.
 * @return int Number of child galleries that can participate in date sorting.
 */
function public_count_dated_subgalleries(array $children): int
{
    return gallery_count_dated_rows($children);
}

/**
 * Sort dated subgalleries by start date while leaving undated positions alone.
 *
 * Undated cards are ignored by the sort exactly as requested by the Admin UI:
 * their current visible positions remain stable, while dated rows occupy only
 * the positions that already belonged to dated rows.
 *
 * @param array $children Direct child gallery rows in default order.
 * @param string $mode Sort mode: asc or desc.
 * @return array Direct child gallery rows with dated rows sorted.
 */
function public_sort_subgalleries_by_date(array $children, string $mode): array
{
    return gallery_sort_rows_by_date_preserving_undated_positions($children, $mode);
}

/**
 * Build a public gallery URL for changing the preview subgallery date sort.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param string $mode Sort mode: asc, desc, or an empty string for default order.
 * @return string Public gallery URL with the requested sort state.
 */
function public_subgallery_date_sort_url(array $gallery, string $mode): string
{
    // $query stores non-routing query parameters that should survive the view switch.
    $query = $_GET;
    foreach (['page', 'public_path', 'gallery_path', 'slug', 'gallery_page', 'list_page', PUBLIC_SUBGALLERY_DATE_SORT_PARAM] as $name) {
        unset($query[$name]);
    }

    if (in_array($mode, ['asc', 'desc'], true)) {
        $query[PUBLIC_SUBGALLERY_DATE_SORT_PARAM] = $mode;
    }

    // $baseUrl stores the canonical public gallery URL without existing route state.
    $baseUrl = rtrim(gallery_public_url($gallery), '/') . '/';
    if ($query === []) {
        return $baseUrl;
    }
    return $baseUrl . '?' . http_build_query($query);
}

/**
 * Render the admin-only subgallery date sort preview and save toolbar.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param string $activeMode Active sort mode: asc, desc, or an empty string.
 * @param int $datedCount Number of child galleries with a filled start date.
 * @param int $totalCount Total number of visible direct child galleries.
 */
function render_public_subgallery_date_sort_toolbar(array $gallery, string $activeMode, int $datedCount, int $totalCount): void
{
    if (!current_user() || admin_anonymous_preview_active() || !feature_capability_effective_enabled('inline_administration') || $datedCount < 2 || $totalCount < 2) {
        return;
    }

    \Gallery\Views\view_render_public_subgallery_date_sort_toolbar([
        'gallery_id' => (int) $gallery['id'],
        'active_mode' => $activeMode,
        'urls' => [
            'default' => public_subgallery_date_sort_url($gallery, ''),
            'asc' => public_subgallery_date_sort_url($gallery, 'asc'),
            'desc' => public_subgallery_date_sort_url($gallery, 'desc'),
        ],
        'save_url' => url_for('admin_sort_public_subgalleries_by_date'),
        'csrf_html' => csrf_field(),
    ]);
}

/**
 * Render the small public-page reorder toolbar used by logged-in admins.
 *
 * The toolbar is deliberately scoped to the visible pagination page. PHP sends
 * the current slice offset and item count to the save endpoint, and the server
 * verifies that the submitted ids still match that exact slice before writing
 * any sort_order values.
 *
 * @param string $kind Kind value.
 * @param array $gallery Gallery row or gallery data.
 * @param array $pagination Pagination value.
 * @param int $visibleCount Visible count value.
 * @param int $totalCount Total count value.
 */
function render_public_page_reorder_toolbar(string $kind, array $gallery, array $pagination, int $visibleCount, int $totalCount): void
{
    if (!current_user() || admin_anonymous_preview_active() || !feature_capability_effective_enabled('inline_administration') || $visibleCount < 2) {
        return;
    }

    \Gallery\Views\view_render_public_page_reorder_toolbar([
        'kind' => $kind,
        'endpoint' => $kind === 'gallery' ? url_for('admin_reorder_public_galleries') : url_for('admin_reorder_images'),
        'gallery_id' => (int) $gallery['id'],
        'offset' => (int) ($pagination['offset'] ?? 0),
        'visible_count' => $visibleCount,
        'total_count' => $totalCount,
        'csrf_token' => csrf_token(),
    ]);
}

/**
 * Render the logged-in public gallery Picture manager toolbar.
 *
 * The toolbar keeps discovery visible instead of relying only on hidden
 * modifier-key gestures. It intentionally stays on the public gallery page and
 * posts to small JSON endpoints that delegate mutation work to services.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $hasVisibleDropTargets Has visible drop targets flag.
 */
function render_picture_manager_toolbar(array $gallery, bool $hasVisibleDropTargets): void
{
    if (!current_user() || admin_anonymous_preview_active()) {
        return;
    }

    // $galleryId stores the source gallery ID for all manager actions.
    $galleryId = (int) $gallery['id'];
    // $suggestedDestinationId stores the most likely child gallery destination for typeahead prefill.
    $suggestedDestinationId = function_exists('Gallery\\Services\\likely_gallery_destination_id') ? likely_gallery_destination_id($galleryId) : 0;
    // $destinationPickerHtml reuses the established gallery picker rather than duplicating its data/presentation contract.
    $destinationPickerHtml = render_gallery_search_picker('', 0, $galleryId, [
        'id' => 'picture-manager-destination-' . $galleryId,
        'placeholder' => t('picture_manager.search_destination', 'Search target gallery'),
        'prefill_gallery_id' => $suggestedDestinationId,
        'hidden_attributes' => ['data-picture-manager-destination' => ''],
    ]);
    // $newParentPickerHtml commits the current gallery as the default parent while still allowing any physical gallery to be selected.
    $newParentPickerHtml = render_gallery_search_picker('', $galleryId, 0, [
        'id' => 'picture-manager-new-parent-' . $galleryId,
        'placeholder' => t('picture_manager.search_parent', 'Search parent gallery'),
        'disable_prefill' => true,
        'hidden_attributes' => ['data-picture-manager-new-parent' => ''],
    ]);

    \Gallery\Views\view_render_picture_manager_toolbar([
        'gallery_id' => $galleryId,
        'has_visible_drop_targets' => $hasVisibleDropTargets,
        'csrf_token' => csrf_token(),
        'move_url' => url_for('picture_manager_move'),
        'copy_url' => url_for('picture_manager_copy'),
        'create_url' => url_for('picture_manager_create_gallery'),
        'delete_url' => url_for('picture_manager_delete'),
        'download_url' => url_for('picture_manager_download_selection'),
        'destination_picker_html' => $destinationPickerHtml,
        'new_parent_picker_html' => $newParentPickerHtml,
    ]);
}

/**
 * Build a safe browser filename for one selected photo share candidate.
 *
 * The native Web Share API receives File objects, so clean names make the
 * receiving mobile application show meaningful media labels without exposing
 * internal gallery paths.
 *
 * @param array $image Image row or image data.
 * @param string $displayTitle Display title value.
 * @return string Text result for the caller.
 */
function picture_manager_share_filename(array $image, string $displayTitle): string
{
    // $sourceName stores the most readable source label available for this image.
    $sourceName = trim($displayTitle) !== '' ? $displayTitle : (string) ($image['filename'] ?? 'photo');
    // $extension stores the original extension when the source was already browser friendly.
    $extension = strtolower(pathinfo((string) ($image['filename'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $extension = 'jpg';
    }
    // $baseName stores a filesystem-safe stem that still keeps enough context for sharing.
    $baseName = slugify(pathinfo($sourceName, PATHINFO_FILENAME));
    if ($baseName === '') {
        $baseName = 'photo-' . (int) ($image['id'] ?? 0);
    }
    return $baseName . '.' . $extension;
}

/**
 * Render public gallery preview toolbar.
 *
 * Used by HTTP controller routing for this workflow.
 *
 * @param array $gallery Gallery row or gallery data.
 */
function render_public_gallery_preview_toolbar(array $gallery): void
{
    if (!current_user()) {
        return;
    }
    // $isPreview stores whether the current request is already using anonymous visitor rules.
    $isPreview = admin_anonymous_preview_active();
    // $baseUrl stores the clean gallery URL used to avoid carrying image or pagination state unexpectedly.
    $baseUrl = gallery_public_url($gallery);

    \Gallery\Views\view_render_public_gallery_preview_toolbar([
        'is_preview' => $isPreview,
        'target_url' => anonymous_preview_url($baseUrl, !$isPreview),
    ]);
}

/**
 * Render the public gallery title area with optional banner and logo assets.
 *
 * The text title remains in the h1 for accessibility and SEO even when a banner
 * image visually replaces it. The logo is decorative here because it appears
 * beside an existing text or banner title and would otherwise duplicate content.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param array $seo Seo value.
 * @param bool $publicOnly Public only value.
 */
function render_public_gallery_branding_header(array $gallery, array $seo, bool $publicOnly): void
{
    // $brandingSchemaReady avoids repeated schema-policy inspection for the optional assets.
    $brandingSchemaReady = gallery_branding_schema_ready();
    // $dateHtml preserves the established gallery-date renderer while moving page markup into the view layer.
    ob_start();
    view_render_gallery_date(gallery_date_view_model($gallery), 'hero-gallery-date');
    $dateHtml = (string) ob_get_clean();

    \Gallery\Views\view_render_public_gallery_branding_header([
        'title' => (string) ($seo['title'] ?? $gallery['title'] ?? 'Gallery'),
        'description' => (string) ($gallery['description'] ?? ''),
        'description_links' => public_gallery_description_link_models((string) ($gallery['description'] ?? '')),
        'banner_url' => $brandingSchemaReady ? gallery_branding_asset_url($gallery, 'banner', $publicOnly) : '',
        'logo_url' => $brandingSchemaReady ? gallery_branding_asset_url($gallery, 'logo', $publicOnly) : '',
        'date_html' => $dateHtml,
    ]);
}

/**
 * Render the optional horizontal branding separator below the gallery title area.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param bool $publicOnly Public only value.
 */
function render_public_gallery_branding_separator(array $gallery, bool $publicOnly): void
{
    if (!gallery_branding_schema_ready()) {
        return;
    }
    // $separatorUrl stores only the per-gallery divider image. Theme fallback separators belong to the shared site header.
    $separatorUrl = gallery_branding_asset_url($gallery, 'separator', $publicOnly);
    if ($separatorUrl === '') {
        return;
    }

    \Gallery\Views\view_render_public_gallery_branding_separator([
        'separator_url' => $separatorUrl,
    ]);
}

/**
 * Build the presentation-only breadcrumb model for a public gallery context.
 *
 * @param ?array $gallery Current gallery or null for the root gallery index.
 * @return array<string,mixed> Breadcrumb presentation state.
 */
function public_gallery_breadcrumbs_view_model(?array $gallery = null): array
{
    $ancestors = [];
    if ($gallery) {
        foreach (gallery_breadcrumb_ancestors($gallery) as $ancestor) {
            $ancestors[] = [
                'title' => (string) ($ancestor['title'] ?? ''),
                'url' => gallery_public_url($ancestor),
            ];
        }
    }

    return [
        'home_url' => url_for('home'),
        'ancestors' => $ancestors,
        'current_title' => $gallery ? (string) ($gallery['title'] ?? '') : '',
    ];
}

/**
 * Handles render breadcrumbs logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 */
function render_breadcrumbs(?array $gallery = null): void
{
    \Gallery\Views\view_render_public_gallery_breadcrumbs(public_gallery_breadcrumbs_view_model($gallery));
}

/**
 * Handles render gallery access gate logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @param mixed $error Input used by this operation.
 * @param ?array $image Image row or image data.
 */
function render_gallery_access_gate(array $gallery, string $error = '', ?array $image = null): void
{
    // $requirement stores an intermediate value used by the surrounding gallery workflow.
    $requirement = gallery_access_requirement($gallery) ?: $gallery;
    // $nsfwRequirement stores the inherited NSFW source, or the current gallery for per-image NSFW.
    $nsfwRequirement = gallery_nsfw_requirement($gallery) ?: ($image !== null && image_nsfw_restricted($image, $gallery) ? $gallery : null);
    // $state selects one presentation branch after all access-policy checks have been resolved in the controller.
    $state = 'share_only';
    if ($nsfwRequirement !== null && !visitor_can_access_nsfw_content()) {
        $state = 'nsfw';
    } elseif (!empty($requirement['access_password_hash'])) {
        $state = 'password';
    }

    \Gallery\Views\view_render_gallery_access_gate([
        'title' => (string) $gallery['title'],
        'breadcrumbs' => public_gallery_breadcrumbs_view_model($gallery),
        'error' => $error,
        'state' => $state,
        'access_url' => url_for('gallery_access'),
        'csrf_html' => csrf_field(),
        'gallery_id' => (int) $gallery['id'],
        'image_id' => $image !== null ? (int) $image['id'] : 0,
        'requirement_id' => (int) ($requirement['id'] ?? 0),
        'lifetime_minutes' => (int) (gallery_access_lifetime_seconds() / 60),
    ]);
}

/**
 * Handles cms gallery access logic for the gallery application.
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
 */
function cms_share(): void
{
    // $token stores an intermediate value used by the surrounding gallery workflow.
    $token = trim((string) ($_GET['token'] ?? ''));
    if ($token === '') {
        cms_not_found();
        return;
    }
    gallery_access_assert_public_policy_available();
    $shareSchemaStatus = gallery_access_share_token_schema_status();
    if (schema_inspection_is_unknown($shareSchemaStatus)) {
        throw new \Gallery\Services\GalleryShareTokenSchemaUnavailableException();
    }
    if (!gallery_access_schema_ready() || !schema_inspection_is_available($shareSchemaStatus)) {
        cms_not_found();
        return;
    }
    // $galleryId stores an intermediate value used by the surrounding gallery workflow.
    $galleryId = (int) ($_GET['id'] ?? 0);
    // $gallery stores the gallery resolved through the access-token persistence boundary.
    $gallery = gallery_access_find_by_token($token, $galleryId);
    if (!$gallery || (!empty($gallery['access_token_expires_at']) && strtotime((string) $gallery['access_token_expires_at']) < time())) {
        cms_not_found();
        return;
    }
    grant_gallery_public_access((int) $gallery['id']);
    redirect_to(gallery_public_url($gallery));
}

/**
 * Handles gallery share url logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $token Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_share_url(int $galleryId, string $token): string
{
    return url_for('share', ['id' => $galleryId, 'token' => $token]);
}
