<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/smart_galleries.php
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
/** Smart Gallery Admin CRUD, preview, public listing, and rating endpoints. */

declare(strict_types=1);

namespace Gallery\Controllers;

use InvalidArgumentException;
use Throwable;
use Gallery\Services\MutationSchemaUnavailableException;

use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_panel_metadata;
use function Gallery\Core\admin_mutation_postcondition;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\admin_wants_json;
use function Gallery\Core\csrf_field;
use function Gallery\Core\csrf_token;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\image_alt_text;
use function Gallery\Core\image_public_media_url;
use function Gallery\Core\image_public_url;
use function Gallery\Services\find_gallery;
use function Gallery\Services\gallery_picker_source_rows;
use function Gallery\Services\download_capability_issue;
use function Gallery\Services\pagination_current_page;
use function Gallery\Services\pagination_grid_columns_class;
use function Gallery\Services\pagination_model;
use function Gallery\Views\view_render_pagination_controls;
use function Gallery\Services\smart_galleries_all;
use function Gallery\Services\smart_galleries_for_placement;
use function Gallery\Services\smart_gallery_count_images;
use function Gallery\Services\smart_gallery_delete;
use function Gallery\Services\smart_gallery_duplicate;
use function Gallery\Services\smart_gallery_empty_rules;
use function Gallery\Services\smart_gallery_find;
use function Gallery\Services\smart_gallery_find_public;
use function Gallery\Services\smart_gallery_find_public_by_id;
use function Gallery\Services\smart_gallery_effective_presentation;
use function Gallery\Services\smart_gallery_normalize_presentation;
use function Gallery\Services\smart_gallery_lightbox_fetch_images;
use function Gallery\Services\smart_gallery_source_galleries;
use function Gallery\Services\smart_gallery_thumbnail_sizes;
use function Gallery\Services\smart_gallery_query_images;
use function Gallery\Services\smart_gallery_placement_galleries;
use function Gallery\Services\smart_gallery_remove_from_gallery;
use function Gallery\Services\smart_gallery_update_placement;
use function Gallery\Services\smart_gallery_rule_catalog;
use function Gallery\Services\smart_gallery_rules_from_json;
use function Gallery\Services\smart_gallery_rules_from_search;
use function Gallery\Services\smart_gallery_save;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_bundle;
use function Gallery\Services\thumbnail_bundles_preload;
use function Gallery\Services\public_thumbnail_render_picture_html;
use function Gallery\Services\public_thumbnail_rendering_mode;
use function Gallery\Services\public_thumbnail_rendering_modes;
use function Gallery\Services\admin_tag_rows;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\current_votes_for_images;
use function Gallery\Services\current_viewer;
use function Gallery\Services\gallery_voting_allowed;
use function Gallery\Services\public_image_display_title;
use function Gallery\Services\thumbnail_bundle_url;
use function Gallery\Services\tags_for_entities;
use function Gallery\Services\theme_lightbox_browsing_mode;
use function Gallery\Services\gallery_lightbox_browsing_mode_options;
use function Gallery\Services\gallery_description_layout_options;
use function Gallery\Services\gallery_description_layout_label;
use function Gallery\Services\gallery_allows_gps_maps;
use function Gallery\Services\translation_active_language;
use function Gallery\Services\content_localize_entities;
use function Gallery\Services\content_localize_entity;
use function Gallery\Services\thumbnail_sizes;
use function Gallery\Services\pagination_photo_thumbnail_sizes_attribute;
use function Gallery\Services\viewer_favourites_for_image_ids;
use function Gallery\Services\viewer_favourites_storage_available;
use function Gallery\Services\viewer_collections_for_owner;
use function Gallery\Services\viewer_collections_storage_available;
use function Gallery\Services\viewer_source_image_can_reference;
use function Gallery\Services\admin_test_run_active;
use function Gallery\Services\admin_test_run_mark;
use function Gallery\Services\admin_test_run_record_component;
use const Gallery\Services\SMART_GALLERY_QUERY_MAX_PAGE_SIZE;
use const Gallery\Services\SMART_GALLERY_LIGHTBOX_MAX_WINDOW;
use const Gallery\Services\DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY;
use const Gallery\Services\DOWNLOAD_CAPABILITY_SCOPE_LEGACY;

/** Render and process Smart Gallery administration. */
function cms_admin_smart_galleries(): void
{
    require_admin();
    $id = max(0, (int) ($_GET['id'] ?? 0));
    $selected = $id > 0 ? smart_gallery_find($id) : null;
    $error = '';
    $previewCount = null;
    $previewImages = [];
    if (request_method() === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'save');
        // Preview is a non-persistent editor operation and intentionally keeps its server-rendered HTML response.
        $wantsJson = admin_wants_json() && $action !== 'preview';
        try {
            if ($action === 'delete') {
                $deletedId = max(0, (int) ($_POST['id'] ?? 0));
                // $beforeDefinition and $beforePlacements preserve every public context invalidated by deletion.
                $beforeDefinition = $deletedId > 0 ? smart_gallery_find($deletedId) : null;
                $beforePlacements = $deletedId > 0 ? smart_gallery_placement_galleries($deletedId) : [];
                smart_gallery_delete($deletedId);
                admin_log_event('info', 'smart_gallery.deleted', 'Admin deleted a Smart Gallery definition.', ['action' => 'delete'], ['category' => 'gallery', 'subject_type' => 'smart_gallery', 'subject_id' => $deletedId]);
                $message = t('smart_gallery.deleted', 'Smart Gallery deleted.');
                if ($wantsJson) {
                    smart_gallery_admin_json_response(
                        $message,
                        admin_mutation_descriptor('smart_gallery.delete', 'smart_gallery', 'delete', [$deletedId]),
                        0,
                        smart_gallery_admin_affected_contexts($deletedId, $beforeDefinition, null, $beforePlacements, []),
                        url_for('admin_smart_galleries')
                    );
                    return;
                }
                flash_message('smart_gallery_notice', $message);
                redirect_to(url_for('admin_smart_galleries'));
            }
            if ($action === 'duplicate') {
                $sourceId = max(0, (int) ($_POST['id'] ?? 0));
                $copy = smart_gallery_duplicate($sourceId);
                admin_log_event('info', 'smart_gallery.duplicated', 'Admin duplicated a Smart Gallery definition.', ['action' => 'duplicate', 'source_id' => $sourceId], ['category' => 'gallery', 'subject_type' => 'smart_gallery', 'subject_id' => (int) $copy['id']]);
                $message = t('smart_gallery.duplicated', 'A private disabled copy was created.');
                $redirectUrl = url_for('admin_smart_galleries', ['id' => $copy['id']]);
                if ($wantsJson) {
                    smart_gallery_admin_json_response(
                        $message,
                        admin_mutation_descriptor('smart_gallery.duplicate', 'smart_gallery', 'duplicate', [(int) $copy['id']]),
                        (int) $copy['id'],
                        [],
                        $redirectUrl,
                        ['source_smart_gallery_id' => $sourceId]
                    );
                    return;
                }
                flash_message('smart_gallery_notice', $message);
                redirect_to($redirectUrl);
            }
            if ($action === 'update_placement') {
                $smartGalleryId = max(0, (int) ($_POST['id'] ?? 0));
                $galleryId = max(0, (int) ($_POST['gallery_id'] ?? 0));
                $placement = (string) ($_POST['attachment_placement'] ?? 'bottom');
                $placementOrder = (int) ($_POST['attachment_order'] ?? 0);
                smart_gallery_update_placement($smartGalleryId, $galleryId, $placement, $placementOrder);
                admin_log_event('info', 'smart_gallery.placement_updated', 'Admin updated one physical Smart Gallery placement.', ['action' => 'update_placement', 'gallery_id' => $galleryId, 'placement' => $placement === 'top' ? 'top' : 'bottom', 'placement_order' => $placementOrder], ['category' => 'gallery', 'subject_type' => 'smart_gallery', 'subject_id' => $smartGalleryId]);
                $message = t('smart_gallery.placement_updated', 'Smart Gallery placement updated.');
                $redirectUrl = url_for('admin_smart_galleries', ['id' => $smartGalleryId]);
                if ($wantsJson) {
                    $physicalGallery = find_gallery($galleryId, true) ?: find_gallery($galleryId);
                    $contexts = $physicalGallery ? [admin_mutation_public_gallery_context(
                        $galleryId,
                        gallery_public_url($physicalGallery),
                        smart_gallery_admin_context_postcondition($smartGalleryId, $galleryId)
                    )] : [];
                    smart_gallery_admin_json_response(
                        $message,
                        admin_mutation_descriptor('smart_gallery.placement_update', 'smart_gallery', 'update_placement', [$smartGalleryId]),
                        $smartGalleryId,
                        $contexts,
                        $redirectUrl,
                        ['gallery_id' => $galleryId]
                    );
                    return;
                }
                flash_message('smart_gallery_notice', $message);
                redirect_to($redirectUrl);
            }
            if ($action === 'remove_placement') {
                $smartGalleryId = max(0, (int) ($_POST['id'] ?? 0));
                $galleryId = max(0, (int) ($_POST['gallery_id'] ?? 0));
                // Load the physical context before deleting the relationship row.
                $physicalGallery = find_gallery($galleryId, true) ?: find_gallery($galleryId);
                $removed = smart_gallery_remove_from_gallery($smartGalleryId, $galleryId);
                admin_log_event('info', 'smart_gallery.placement_removed', 'Admin removed one physical Smart Gallery placement.', ['action' => 'remove_placement', 'gallery_id' => $galleryId, 'removed' => $removed], ['category' => 'gallery', 'subject_type' => 'smart_gallery', 'subject_id' => $smartGalleryId]);
                $message = t('smart_gallery.hidden_from_gallery', 'Smart Gallery hidden from that physical gallery.');
                $redirectUrl = url_for('admin_smart_galleries', ['id' => $smartGalleryId]);
                if ($wantsJson) {
                    $contexts = $physicalGallery ? [admin_mutation_public_gallery_context(
                        $galleryId,
                        gallery_public_url($physicalGallery),
                        smart_gallery_admin_context_postcondition($smartGalleryId, $galleryId)
                    )] : [];
                    smart_gallery_admin_json_response(
                        $message,
                        admin_mutation_descriptor('smart_gallery.placement_remove', 'smart_gallery', 'remove_placement', [$smartGalleryId]),
                        $smartGalleryId,
                        $contexts,
                        $redirectUrl,
                        ['gallery_id' => $galleryId, 'removed' => $removed]
                    );
                    return;
                }
                flash_message('smart_gallery_notice', $message);
                redirect_to($redirectUrl);
            }
            $input = smart_gallery_admin_input();
            if ($action === 'preview') {
                $rules = smart_gallery_rules_from_json($input['rules_json']);
                $preview = $selected ?: ['rules_json' => ''];
                $preview = array_merge($preview, $input, ['rules_json' => json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                $previewCount = smart_gallery_count_images($preview, false);
                $previewImages = $previewCount > 0 ? smart_gallery_query_images($preview, false, min(12, $previewCount), 0) : [];
                $selected = array_merge($preview, ['id' => $id]);
                admin_log_event('info', 'smart_gallery.previewed', 'Admin previewed Smart Gallery rules.', array_merge(smart_gallery_admin_log_context($input, 'preview'), ['matched_images' => $previewCount]), ['category' => 'gallery', 'subject_type' => 'smart_gallery', 'subject_id' => $id > 0 ? $id : null]);
            } else {
                $saveAction = $id > 0 ? 'update' : 'create';
                // Preserve the previous placement state so mode changes invalidate both old and new public contexts.
                $beforeDefinition = $id > 0 ? smart_gallery_find($id) : null;
                $beforePlacements = $id > 0 ? smart_gallery_placement_galleries($id) : [];
                $selected = smart_gallery_save($input, $id);
                $afterPlacements = smart_gallery_placement_galleries((int) $selected['id']);
                admin_log_event('info', 'smart_gallery.saved', 'Admin saved a Smart Gallery definition.', smart_gallery_admin_log_context($input, $saveAction), ['category' => 'gallery', 'subject_type' => 'smart_gallery', 'subject_id' => (int) $selected['id']]);
                $message = t('smart_gallery.saved', 'Smart Gallery saved.');
                $redirectUrl = url_for('admin_smart_galleries', ['id' => $selected['id']]);
                if ($wantsJson) {
                    smart_gallery_admin_json_response(
                        $message,
                        admin_mutation_descriptor('smart_gallery.' . $saveAction, 'smart_gallery', $saveAction, [(int) $selected['id']]),
                        (int) $selected['id'],
                        smart_gallery_admin_affected_contexts((int) $selected['id'], $beforeDefinition, $selected, $beforePlacements, $afterPlacements),
                        $redirectUrl,
                        [
                            'smart_gallery_id' => (int) $selected['id'],
                            'smart_gallery_title' => (string) ($selected['title'] ?? ''),
                            'smart_gallery_slug' => (string) ($selected['slug'] ?? ''),
                        ]
                    );
                    return;
                }
                flash_message('smart_gallery_notice', $message);
                redirect_to($redirectUrl);
            }
        } catch (InvalidArgumentException $exception) {
            admin_log_event('warning', 'smart_gallery.validation_failed', 'Smart Gallery Admin action failed validation.', array_merge(smart_gallery_admin_log_context(smart_gallery_admin_input(), $action), ['reason' => substr($exception->getMessage(), 0, 240)]), ['category' => 'gallery', 'severity' => 'warning', 'subject_type' => 'smart_gallery', 'subject_id' => $id > 0 ? $id : null]);
            if ($wantsJson) {
                smart_gallery_admin_json_error($exception->getMessage(), 'smart_gallery_validation_failed', $action, max($id, (int) ($_POST['id'] ?? 0)), 422);
                return;
            }
            $error = $exception->getMessage();
            if (in_array($action, ['save', 'preview'], true)) $selected = array_merge($selected ?: [], smart_gallery_admin_input(), ['id' => $id]);
        } catch (MutationSchemaUnavailableException $exception) {
            admin_log_event('warning', 'smart_gallery.schema_unavailable', 'Smart Gallery Admin action was refused because required schema was unavailable.', array_merge(smart_gallery_admin_log_context(smart_gallery_admin_input(), $action), ['feature' => $exception->feature, 'schema_state' => $exception->state, 'operation' => $exception->operation]), ['category' => 'database', 'severity' => 'warning', 'subject_type' => 'smart_gallery', 'subject_id' => $id > 0 ? $id : null]);
            if ($wantsJson) {
                smart_gallery_admin_json_error($exception->getMessage(), 'smart_gallery_schema_unavailable', $action, max($id, (int) ($_POST['id'] ?? 0)), 503);
                return;
            }
            $error = $exception->getMessage();
            if (in_array($action, ['save', 'preview'], true)) $selected = array_merge($selected ?: [], smart_gallery_admin_input(), ['id' => $id]);
        } catch (Throwable $exception) {
            admin_log_event('error', 'smart_gallery.action_failed', 'Smart Gallery Admin action failed unexpectedly.', array_merge(smart_gallery_admin_log_context(smart_gallery_admin_input(), $action), ['exception_class' => get_class($exception), 'exception_code' => (string) $exception->getCode()]), ['category' => 'gallery', 'severity' => 'error', 'subject_type' => 'smart_gallery', 'subject_id' => $id > 0 ? $id : null]);
            $error = t('smart_gallery.unexpected_error', 'The Smart Gallery could not be saved. Check Admin Logs for the request diagnostic.');
            if ($wantsJson) {
                smart_gallery_admin_json_error($error, 'smart_gallery_action_failed', $action, max($id, (int) ($_POST['id'] ?? 0)), 500);
                return;
            }
            if (in_array($action, ['save', 'preview'], true)) $selected = array_merge($selected ?: [], smart_gallery_admin_input(), ['id' => $id]);
        }
    }
    if (!$selected && isset($_GET['from_search'])) {
        $selected = ['title' => '', 'slug' => '', 'description' => '', 'rules_json' => json_encode(smart_gallery_rules_from_search((string) $_GET['from_search'])), 'enabled' => 1, 'visibility' => 'private', 'placement_mode' => 'unlisted', 'sort_mode' => 'capture_date', 'sort_direction' => 'desc'];
    }
    $notice = (string) (flash_message('smart_gallery_notice') ?? '');
    $rows = [];
    foreach (smart_galleries_all() as $row) {
        $statusLabel = $row['visibility'] === 'public'
            ? t('smart_gallery.public', 'Published')
            : t('smart_gallery.private', 'Private');
        if (empty($row['enabled'])) {
            $statusLabel .= ' · ' . t('smart_gallery.disabled', 'Disabled');
        }
        $rows[] = [
            'url' => url_for('admin_smart_galleries', ['id' => (int) $row['id']]),
            'title' => (string) $row['title'],
            'status_label' => $statusLabel,
        ];
    }

    \Gallery\Views\view_render_admin_smart_galleries([
        'page_title' => t('smart_gallery.admin_title', 'Smart Galleries'),
        'create_url' => url_for('admin_smart_galleries', ['new' => 1]),
        'notice' => $notice,
        'error' => $error,
        'rows' => $rows,
        'editor' => ($selected || isset($_GET['new']))
            ? smart_gallery_admin_editor_view_model($selected ?: [], $previewCount, $previewImages)
            : null,
    ]);
}

/**
 * Build the observable public placement postcondition for one Smart Gallery context.
 *
 * Root cards may be paginated, so the full root Smart Gallery count is always
 * included. Physical gallery attachments are not paginated and additionally expose
 * placement/order metadata so moving a card between top and bottom cannot verify
 * against structurally valid but stale HTML.
 *
 * @param int $smartGalleryId Stable Smart Gallery id.
 * @param int $galleryId Physical parent gallery id, or zero for the root index.
 * @return array<string, mixed> Canonical smart_gallery_presence postcondition.
 */
function smart_gallery_admin_context_postcondition(int $smartGalleryId, int $galleryId): array
{
    $rows = smart_galleries_for_placement($galleryId > 0 ? $galleryId : null, true);
    $matched = null;
    foreach ($rows as $row) {
        if ((int) ($row['id'] ?? 0) === $smartGalleryId) {
            $matched = $row;
            break;
        }
    }

    $data = [
        'smart_gallery_id' => $smartGalleryId,
        'present' => is_array($matched),
        'count' => count($rows),
    ];
    if ($galleryId > 0 && is_array($matched)) {
        $data['placement'] = (string) ($matched['placement'] ?? 'bottom');
        $data['placement_order'] = max(0, (int) ($matched['placement_order'] ?? 0));
    }
    return admin_mutation_postcondition('smart_gallery_presence', $data);
}

/**
 * Return every physical/root public context whose Smart Gallery output may have changed.
 *
 * @param int $smartGalleryId Stable Smart Gallery id whose placement/render state changed.
 * @param ?array $beforeDefinition Definition before persistence, when available.
 * @param ?array $afterDefinition Definition after persistence, when available.
 * @param array<int, array<string, mixed>> $beforePlacements Physical placements before persistence.
 * @param array<int, array<string, mixed>> $afterPlacements Physical placements after persistence.
 * @return array<int, array<string, mixed>> Canonical affected public contexts.
 */
function smart_gallery_admin_affected_contexts(int $smartGalleryId, ?array $beforeDefinition, ?array $afterDefinition, array $beforePlacements, array $afterPlacements): array
{
    $contexts = [];
    $seen = [];

    $beforeMode = (string) ($beforeDefinition['placement_mode'] ?? '');
    $afterMode = (string) ($afterDefinition['placement_mode'] ?? '');
    if ($beforeMode === 'root' || $afterMode === 'root') {
        $contexts[] = admin_mutation_public_gallery_context(
            0,
            url_for('home'),
            smart_gallery_admin_context_postcondition($smartGalleryId, 0)
        );
        $seen['gallery_index'] = true;
    }

    foreach ([[$beforeMode, $beforePlacements], [$afterMode, $afterPlacements]] as [$mode, $placements]) {
        if ($mode !== 'gallery') {
            continue;
        }
        foreach ($placements as $placement) {
            $galleryId = (int) ($placement['id'] ?? 0);
            if ($galleryId <= 0 || isset($seen['gallery:' . $galleryId])) {
                continue;
            }
            $gallery = find_gallery($galleryId, true) ?: find_gallery($galleryId);
            if (!$gallery) {
                continue;
            }
            $contexts[] = admin_mutation_public_gallery_context(
                $galleryId,
                gallery_public_url($gallery),
                smart_gallery_admin_context_postcondition($smartGalleryId, $galleryId)
            );
            $seen['gallery:' . $galleryId] = true;
        }
    }

    return $contexts;
}

/**
 * Send one canonical Smart Gallery success response for an enhanced side-panel mutation.
 *
 * @param string $message Localized success message.
 * @param array<string, mixed> $mutation Canonical mutation descriptor.
 * @param int $selectedId Smart Gallery that should remain selected in the drawer, or zero for the list.
 * @param array<int, array<string, mixed>> $contexts Affected public render contexts.
 * @param string $fallbackUrl Direct-page fallback URL.
 * @param array<string, mixed> $extra Temporary workflow-specific compatibility fields.
 */
function smart_gallery_admin_json_response(string $message, array $mutation, int $selectedId, array $contexts, string $fallbackUrl, array $extra = []): void
{
    $panelParams = ['panel' => 1];
    if ($selectedId > 0) {
        $panelParams['id'] = $selectedId;
    }
    $payload = admin_mutation_success_envelope(
        $message,
        $mutation,
        admin_mutation_panel_metadata('smart-gallery', url_for('admin_smart_galleries', $panelParams), true),
        $contexts,
        ['redirect_url' => $fallbackUrl]
    );
    header('Content-Type: application/json');
    echo json_encode(array_merge($payload, $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Send one canonical Smart Gallery expected-error response.
 *
 * @param string $message Safe error message.
 * @param string $errorCode Stable error category.
 * @param string $action Requested Smart Gallery action.
 * @param int $smartGalleryId Stable Smart Gallery id when known.
 * @param int $status HTTP status code.
 */
function smart_gallery_admin_json_error(string $message, string $errorCode, string $action, int $smartGalleryId, int $status): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(admin_mutation_error_envelope(
        $message,
        $errorCode,
        admin_mutation_descriptor(
            'smart_gallery.' . ($action !== '' ? $action : 'mutation'),
            'smart_gallery',
            $action !== '' ? $action : 'mutation',
            $smartGalleryId > 0 ? [$smartGalleryId] : []
        )
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Normalize the Smart Gallery editor POST payload. */
function smart_gallery_admin_input(): array
{
    $presentation = [];
    if (isset($_POST['presentation_override_enabled'])) {
        $presentation = [
            'grid_columns' => $_POST['presentation_grid_columns'] ?? null,
            'grid_rows' => $_POST['presentation_grid_rows'] ?? null,
            'pagination_enabled' => isset($_POST['presentation_pagination_enabled']),
            'thumbnail_min_size' => $_POST['presentation_thumbnail_min_size'] ?? null,
            'thumbnail_max_size' => $_POST['presentation_thumbnail_max_size'] ?? null,
            'thumbnail_rendering_mode' => (string) ($_POST['presentation_thumbnail_rendering_mode'] ?? ''),
            'card_layout' => (string) ($_POST['presentation_card_layout'] ?? ''),
            'metadata_visible' => isset($_POST['presentation_metadata_visible']),
            'lightbox_enabled' => isset($_POST['presentation_lightbox_enabled']),
            'lightbox_browsing_mode' => (string) ($_POST['presentation_lightbox_browsing_mode'] ?? ''),
            'slideshow_enabled' => isset($_POST['presentation_slideshow_enabled']),
            'download_enabled' => isset($_POST['presentation_download_enabled']),
            'voting_enabled' => isset($_POST['presentation_voting_enabled']),
        ];
    }

    return [
        'title' => (string) ($_POST['title'] ?? ''),
        'slug' => (string) ($_POST['slug'] ?? ''),
        'description' => (string) ($_POST['description'] ?? ''),
        'rules_json' => (string) ($_POST['rules_json'] ?? ''),
        'enabled' => isset($_POST['enabled']),
        'visibility' => (string) ($_POST['visibility'] ?? 'private'),
        'placement_mode' => (string) ($_POST['placement_mode'] ?? 'unlisted'),
        'sort_mode' => (string) ($_POST['sort_mode'] ?? 'capture_date'),
        'sort_direction' => (string) ($_POST['sort_direction'] ?? 'desc'),
        'presentation' => $presentation,
        'presentation_json' => $presentation,
    ];
}

/**
 * Build a bounded, value-free summary of one submitted Smart Gallery intent.
 *
 * Rule values and raw JSON are deliberately excluded because they may contain
 * private descriptions, filenames, AI search text, or other visitor data.
 */
function smart_gallery_admin_log_context(array $input, string $action): array
{
    $title = trim((string) ($input['title'] ?? ''));
    $summary = [
        'action' => preg_match('/^[a-z_]{1,32}$/D', $action) === 1 ? $action : 'unknown',
        'title' => function_exists('mb_substr') ? mb_substr($title, 0, 160, 'UTF-8') : substr($title, 0, 160),
        'slug_mode' => trim((string) ($input['slug'] ?? '')) === '' ? 'automatic' : 'custom',
        'enabled' => !empty($input['enabled']),
        'visibility' => in_array($input['visibility'] ?? '', ['private', 'public'], true) ? (string) $input['visibility'] : 'invalid',
        'placement_mode' => in_array($input['placement_mode'] ?? '', ['unlisted', 'root', 'gallery'], true) ? (string) $input['placement_mode'] : 'invalid',
        'rule_json_valid' => false,
        'condition_count' => 0,
        'group_counts' => ['AND' => 0, 'OR' => 0, 'NOT' => 0],
        'maximum_depth' => 0,
        'fields' => [],
        'operators' => [],
    ];
    $decoded = json_decode((string) ($input['rules_json'] ?? ''), true);
    if (!is_array($decoded) || !is_array($decoded['root'] ?? null)) {
        return $summary;
    }
    $summary['rule_json_valid'] = true;
    $stack = [[$decoded['root'], 0]];
    $visited = 0;
    while ($stack !== [] && $visited < 100) {
        [$node, $depth] = array_pop($stack);
        if (!is_array($node)) continue;
        $visited++;
        $summary['maximum_depth'] = max((int) $summary['maximum_depth'], (int) $depth);
        if (($node['type'] ?? '') === 'group') {
            $operator = strtoupper((string) ($node['operator'] ?? ''));
            if (array_key_exists($operator, $summary['group_counts'])) $summary['group_counts'][$operator]++;
            foreach (array_reverse((array) ($node['children'] ?? [])) as $child) $stack[] = [$child, $depth + 1];
            continue;
        }
        if (($node['type'] ?? '') !== 'condition') continue;
        $summary['condition_count']++;
        $field = (string) ($node['field'] ?? '');
        $operator = (string) ($node['operator'] ?? '');
        if (preg_match('/^[a-z0-9_]{1,64}$/D', $field) === 1) $summary['fields'][] = $field;
        if (preg_match('/^[a-z0-9_]{1,64}$/D', $operator) === 1) $summary['operators'][] = $operator;
    }
    $summary['fields'] = array_slice(array_values(array_unique($summary['fields'])), 0, 32);
    $summary['operators'] = array_slice(array_values(array_unique($summary['operators'])), 0, 32);
    return $summary;
}

/**
 * Build the Smart Gallery editor view model.
 *
 * @param array<string,mixed> $gallery Smart Gallery definition or unsaved editor state.
 * @param ?int $previewCount Preview match count when preview was requested.
 * @param array<int,array<string,mixed>> $previewImages Matching preview images.
 * @return array<string,mixed> Controller-prepared editor state.
 */
function smart_gallery_admin_editor_view_model(array $gallery, ?int $previewCount, array $previewImages = []): array
{
    $rulesJson = (string) ($gallery['rules_json'] ?? json_encode(smart_gallery_empty_rules()));
    $catalog = smart_gallery_rule_catalog();
    foreach ($catalog as $field => &$definition) {
        $definition['label'] = t('smart_gallery.field.' . $field, ucfirst(str_replace('_', ' ', $field)));
        $definition['operator_labels'] = [];
        foreach ($definition['operators'] as $operator) {
            $definition['operator_labels'][$operator] = t('smart_gallery.operator.' . $operator, ucfirst(str_replace('_', ' ', $operator)));
        }
    }
    unset($definition);

    $presentationOverrides = smart_gallery_normalize_presentation(
        array_key_exists('presentation', $gallery) ? $gallery['presentation'] : ($gallery['presentation_json'] ?? [])
    );
    $presentation = smart_gallery_effective_presentation($gallery);
    $galleryId = (int) ($gallery['id'] ?? 0);
    $formAction = url_for(
        'admin_smart_galleries',
        $galleryId > 0 ? ['id' => $galleryId] : (isset($_GET['new']) ? ['new' => 1] : [])
    );

    $placementModes = [];
    foreach (['unlisted', 'root', 'gallery'] as $placementMode) {
        $placementModes[] = [
            'value' => $placementMode,
            'selected' => (string) ($gallery['placement_mode'] ?? 'unlisted') === $placementMode,
            'label' => t('smart_gallery.placement_' . $placementMode, match ($placementMode) {
                'root' => 'Root gallery on the homepage',
                'gallery' => 'Subgallery of a physical gallery',
                default => 'Unlisted (URL only)',
            }),
        ];
    }

    $sortModes = [];
    foreach (['capture_date', 'filename', 'created_at', 'title', 'rating', 'default'] as $mode) {
        $sortModes[] = [
            'value' => $mode,
            'selected' => (string) ($gallery['sort_mode'] ?? 'capture_date') === $mode,
            'label' => t('smart_gallery.sort_' . $mode, ucfirst(str_replace('_', ' ', $mode))),
        ];
    }

    $placements = [];
    if ($galleryId > 0) {
        foreach (smart_gallery_placement_galleries($galleryId) as $placement) {
            $placements[] = [
                'id' => (int) ($placement['id'] ?? 0),
                'title' => (string) ($placement['title'] ?? ''),
                'folder_path' => (string) ($placement['folder_path'] ?? ''),
                'edit_url' => url_for('admin_edit_gallery', ['id' => (int) ($placement['id'] ?? 0)]),
                'placement' => ($placement['placement'] ?? 'bottom') === 'top' ? 'top' : 'bottom',
                'placement_order' => (int) ($placement['placement_order'] ?? 0),
                'parent_restricted' => ($placement['visibility'] ?? '') !== 'public' || ($placement['access_mode'] ?? 'normal') !== 'normal',
                'relationship_valid' => !empty($placement['relationship_valid']),
            ];
        }
    }

    return [
        'gallery' => $gallery,
        'gallery_id' => $galleryId,
        'existing' => $galleryId > 0,
        'form_action' => $formAction,
        'csrf_html' => csrf_field(),
        'catalog_json' => (string) json_encode($catalog),
        'tags_json' => (string) json_encode(admin_tag_rows('name', 'asc')),
        'galleries_json' => (string) json_encode(gallery_picker_source_rows(true)),
        'placement_modes' => $placementModes,
        'sort_modes' => $sortModes,
        'enabled' => !array_key_exists('enabled', $gallery) || !empty($gallery['enabled']),
        'presentation_controls' => smart_gallery_presentation_controls_view_model($presentation, $presentationOverrides !== []),
        'rules_json' => $rulesJson,
        'preview_count' => $previewCount,
        'preview_cards' => $previewCount !== null && $previewImages !== []
            ? smart_gallery_image_cards_view_model($previewImages, smart_gallery_source_galleries($previewImages), $presentation, [], [], 0, false)
            : null,
        'placements' => $placements,
        'public_url' => $galleryId > 0 && ($gallery['visibility'] ?? '') === 'public' && !empty($gallery['enabled'])
            ? url_for('smart_gallery', ['slug' => (string) ($gallery['slug'] ?? '')])
            : '',
    ];
}

/**
 * Render the non-programmer rule-builder editor and standard POST fallback.
 *
 * Retained as a compatibility renderer for existing callers.
 */
function smart_gallery_render_editor(array $gallery, ?int $previewCount, array $previewImages = []): void
{
    \Gallery\Views\view_render_smart_gallery_editor(
        smart_gallery_admin_editor_view_model($gallery, $previewCount, $previewImages)
    );
}

/**
 * Build canonical Smart Gallery presentation-control state.
 *
 * @param array<string,mixed> $presentation Effective Smart Gallery presentation.
 * @return array<string,mixed> Controller-prepared control state.
 */
function smart_gallery_presentation_controls_view_model(array $presentation, bool $hasOverride): array
{
    $thumbnailModes = [];
    foreach (public_thumbnail_rendering_modes() as $mode) {
        $thumbnailModes[] = [
            'value' => $mode,
            'selected' => (string) ($presentation['thumbnail_rendering_mode'] ?? '') === $mode,
            'label' => $mode === 'progressive'
                ? t('admin.settings.thumbnail.progressive', 'Progressive')
                : t('admin.settings.thumbnail.responsive', 'Responsive'),
        ];
    }

    $cardLayouts = [];
    foreach (gallery_description_layout_options() as $layout) {
        $cardLayouts[] = [
            'value' => $layout,
            'selected' => (string) ($presentation['card_layout'] ?? '') === $layout,
            'label' => gallery_description_layout_label($layout),
        ];
    }

    $lightboxModes = [];
    foreach (gallery_lightbox_browsing_mode_options() as $mode) {
        $lightboxModes[] = [
            'value' => $mode,
            'selected' => (string) ($presentation['lightbox_browsing_mode'] ?? '') === $mode,
            'label' => t('gallery.lightbox_mode.' . $mode, ucfirst(str_replace('_', ' ', $mode))),
        ];
    }

    return [
        'presentation' => $presentation,
        'has_override' => $hasOverride,
        'thumbnail_modes' => $thumbnailModes,
        'card_layouts' => $cardLayouts,
        'thumbnail_sizes' => thumbnail_sizes(),
        'lightbox_modes' => $lightboxModes,
    ];
}

/**
 * Render canonical Smart Gallery presentation controls with explicit Theme inheritance.
 *
 * Retained as a compatibility renderer for existing callers.
 */
function smart_gallery_render_presentation_controls(array $presentation, bool $hasOverride): void
{
    \Gallery\Views\view_render_smart_gallery_presentation_controls(
        smart_gallery_presentation_controls_view_model($presentation, $hasOverride)
    );
}

/**
 * Capture one legacy presentation renderer into a trusted HTML fragment.
 *
 * @param callable():void $renderer Presentation callback.
 */
function smart_gallery_capture_html(callable $renderer): string
{
    ob_start();
    $renderer();
    return (string) ob_get_clean();
}

/**
 * Build real Smart Gallery image cards with one shared presentation contract.
 *
 * @param array<int,array<string,mixed>> $images Image rows.
 * @param array<int,array<string,mixed>> $sourceGalleries Source galleries keyed by id.
 * @param array<string,mixed> $presentation Effective presentation.
 * @param array<int,int> $votes Current vote state keyed by image id.
 * @param array<int,array<int,array<string,mixed>>> $tags Tags keyed by image id.
 * @return array<string,mixed> Controller-prepared card view model.
 */
function smart_gallery_image_cards_view_model(array $images, array $sourceGalleries, array $presentation, array $votes, array $tags, int $offset, bool $interactive): array
{
    $viewerPrincipal = $interactive ? current_viewer() : null;
    $viewerFavouriteControlsEnabled = $viewerPrincipal !== null && viewer_favourites_storage_available();
    // Smart Gallery source discovery historically honors an administrator principal. Favourites do not.
    $viewerFavouriteRequiresSourceRecheck = $viewerFavouriteControlsEnabled && current_user() !== null;
    $viewerFavouriteStates = $viewerFavouriteControlsEnabled
        ? viewer_favourites_for_image_ids((int) $viewerPrincipal['id'], array_map(static fn (array $image): int => (int) $image['id'], $images))
        : [];
    $viewerCollectionControlsEnabled = $viewerPrincipal !== null && viewer_collections_storage_available();
    // Smart Gallery source discovery may honor Admin. Viewer collection controls never inherit that bypass.
    $viewerCollectionRequiresSourceRecheck = $viewerCollectionControlsEnabled && current_user() !== null;
    $viewerCollections = $viewerCollectionControlsEnabled
        ? viewer_collections_for_owner((int) $viewerPrincipal['id'])
        : [];

    $columns = (int) ($presentation['grid_columns'] ?? 3);
    $sizesAttribute = pagination_photo_thumbnail_sizes_attribute(['enabled' => true, 'columns' => $columns]);
    $cards = [];
    foreach ($images as $index => $image) {
        $source = $sourceGalleries[(int) ($image['gallery_id'] ?? 0)] ?? null;
        if (!$source) {
            continue;
        }

        $imageId = (int) $image['id'];
        $url = image_public_url($image, $source);
        $bundle = thumbnail_bundle($image);
        $title = public_image_display_title($image, $source);
        $candidateSizes = smart_gallery_thumbnail_sizes($presentation, $image, $source, thumbnail_sizes());
        $fallbackSize = (int) ($candidateSizes[0] ?? 300);
        $voting = $interactive && !empty($presentation['voting_enabled']) && gallery_voting_allowed($source);
        $lightbox = $interactive && !empty($presentation['lightbox_enabled']);
        $attributesHtml = '';
        if ($lightbox) {
            $mediaUrl = image_public_media_url($image, $source);
            $previewUrl = thumbnail_bundle_url($bundle, 1600);
            $attributesHtml = ' ' . lightbox_image_data_attributes(
                $image,
                $source,
                $mediaUrl,
                $previewUrl,
                $url,
                $title,
                (int) ($image['score'] ?? 0),
                (int) ($votes[$imageId] ?? 0),
                null,
                'data-lightbox-image',
                $voting,
                $offset + $index,
                $bundle
            );
        }

        $sourceReferenceAllowed = true;
        if (($viewerFavouriteRequiresSourceRecheck || $viewerCollectionRequiresSourceRecheck)
            && ($viewerFavouriteControlsEnabled || $viewerCollectionControlsEnabled)) {
            $sourceReferenceAllowed = viewer_source_image_can_reference($imageId);
        }
        $viewerFavouriteAvailableForImage = $viewerFavouriteControlsEnabled
            && (!$viewerFavouriteRequiresSourceRecheck || $sourceReferenceAllowed);
        $viewerCollectionAvailableForImage = $viewerCollectionControlsEnabled
            && (!$viewerCollectionRequiresSourceRecheck || $sourceReferenceAllowed);
        $description = trim((string) ($image['description'] ?? ''));
        $imageTags = $tags[$imageId] ?? [];
        $metadataVisible = !empty($presentation['metadata_visible'])
            && ($title !== '' || $description !== '' || $imageTags !== []);

        $voteHtml = '';
        if ($interactive && !empty($presentation['voting_enabled'])) {
            $voteHtml = smart_gallery_capture_html(static function () use ($imageId, $image, $votes, $voting): void {
                render_vote_form($imageId, (int) ($image['score'] ?? 0), (int) ($votes[$imageId] ?? 0), $voting);
            });
        }

        $tagsHtml = '';
        if ($metadataVisible && $imageTags !== []) {
            $tagsHtml = smart_gallery_capture_html(static function () use ($imageTags): void {
                render_tag_list($imageTags);
            });
        }

        $cards[] = [
            'url' => $url,
            'attributes_html' => $attributesHtml,
            'favourite_attribute_html' => $viewerFavouriteAvailableForImage
                ? ' data-viewer-favourite="' . (!empty($viewerFavouriteStates[$imageId]) ? '1' : '0') . '"'
                : '',
            'thumbnail_html' => public_thumbnail_render_picture_html(
                $image,
                $fallbackSize,
                $candidateSizes,
                $sizesAttribute,
                image_alt_text($image, $source, $offset + $index + 1),
                $index,
                $bundle,
                (string) $presentation['thumbnail_rendering_mode']
            ),
            'favourite_html' => $viewerFavouriteAvailableForImage
                ? render_viewer_favourite_form_html($imageId, !empty($viewerFavouriteStates[$imageId]), 'viewer-favourite-card-overlay')
                : '',
            'collection_html' => $viewerCollectionAvailableForImage
                ? render_viewer_collection_add_control_html($imageId, $viewerCollections, 'viewer-collection-card-overlay')
                : '',
            'vote_html' => $voteHtml,
            'metadata_visible' => $metadataVisible,
            'title' => $title,
            'description' => $description,
            'tags_html' => $tagsHtml,
        ];
    }

    return [
        'grid_class' => pagination_grid_columns_class(['columns' => $columns, 'grid_columns_enabled' => true]),
        'cards' => $cards,
    ];
}

/**
 * Render real Smart Gallery image cards with one shared presentation contract.
 *
 * Retained as a compatibility renderer for existing callers.
 */
function smart_gallery_render_image_cards(array $images, array $sourceGalleries, array $presentation, array $votes, array $tags, int $offset, bool $interactive): void
{
    \Gallery\Views\view_render_smart_gallery_image_cards(
        smart_gallery_image_cards_view_model($images, $sourceGalleries, $presentation, $votes, $tags, $offset, $interactive)
    );
}

/** Render a published Smart Gallery using physical-gallery media URLs and shared thumbnail cards. */
function cms_smart_gallery(): void
{
    $testRunActive = admin_test_run_active();
    if ($testRunActive) admin_test_run_mark('smart_gallery_find_begin');
    $gallery = smart_gallery_find_public((string) ($_GET['slug'] ?? ''));
    if ($testRunActive) admin_test_run_mark('smart_gallery_find_end', ['found' => $gallery !== null]);
    if (!$gallery) {
        cms_not_found();
        return;
    }

    try {
        if ($testRunActive) admin_test_run_mark('smart_gallery_count_begin', ['smart_gallery_id' => (int) $gallery['id']]);
        $total = smart_gallery_count_images($gallery, true);
        if ($testRunActive) admin_test_run_mark('smart_gallery_count_end', ['total' => $total]);
        if ($testRunActive) admin_test_run_mark('smart_gallery_presentation_begin');
        $presentation = smart_gallery_effective_presentation($gallery);
        if ($testRunActive) admin_test_run_mark('smart_gallery_presentation_end');
    } catch (InvalidArgumentException) {
        cms_not_found();
        return;
    }

    $columns = (int) $presentation['grid_columns'];
    $rows = (int) $presentation['grid_rows'];
    $paginationRequiredForSafety = $total > SMART_GALLERY_QUERY_MAX_PAGE_SIZE;
    $usePagination = !empty($presentation['pagination_enabled']) || $paginationRequiredForSafety;
    if ($usePagination) {
        $pagination = pagination_model($total, pagination_current_page('photo_page'), $columns, $rows, 'photo_page', ['page' => 'smart_gallery', 'slug' => $gallery['slug']]);
        $limit = (int) $pagination['limit'];
        $offset = (int) $pagination['offset'];
    } else {
        $limit = max(1, min(SMART_GALLERY_QUERY_MAX_PAGE_SIZE, $total));
        $offset = 0;
        $pagination = null;
    }

    if ($testRunActive) admin_test_run_mark('smart_gallery_image_query_begin', ['limit' => $limit, 'offset' => $offset]);
    $images = $total > 0 ? smart_gallery_query_images($gallery, true, $limit, $offset) : [];
    if ($testRunActive) admin_test_run_mark('smart_gallery_image_query_end', ['images' => count($images)]);
    if ($testRunActive) admin_test_run_mark('smart_gallery_source_gallery_lookup_begin');
    $sourceGalleries = smart_gallery_source_galleries($images);
    if ($testRunActive) admin_test_run_mark('smart_gallery_source_gallery_lookup_end', ['source_galleries' => count($sourceGalleries)]);
    $contentLanguage = translation_active_language();
    if ($testRunActive) admin_test_run_mark('smart_gallery_localization_begin', ['language' => $contentLanguage]);
    $images = content_localize_entities('image', $images, $contentLanguage);
    foreach ($sourceGalleries as $sourceId => $sourceGallery) {
        $sourceGalleries[$sourceId] = content_localize_entity('gallery', $sourceGallery, $contentLanguage);
    }
    if ($testRunActive) admin_test_run_mark('smart_gallery_localization_end');
    $imageIds = array_map(static fn (array $image): int => (int) $image['id'], $images);
    if ($testRunActive) admin_test_run_mark('smart_gallery_votes_begin', ['enabled' => !empty($presentation['voting_enabled'])]);
    $votes = !empty($presentation['voting_enabled']) ? current_votes_for_images($imageIds) : [];
    if ($testRunActive) admin_test_run_mark('smart_gallery_votes_end', ['rows' => count($votes)]);
    if ($testRunActive) admin_test_run_mark('smart_gallery_tags_begin', ['enabled' => !empty($presentation['metadata_visible'])]);
    $tags = !empty($presentation['metadata_visible']) ? tags_for_entities('image', $imageIds) : [];
    if ($testRunActive) admin_test_run_mark('smart_gallery_tags_end', ['entities' => count($tags)]);

    if ($testRunActive) admin_test_run_mark('smart_gallery_render_begin');

    $paginationHtml = $pagination !== null
        ? smart_gallery_capture_html(static function () use ($pagination): void {
            view_render_pagination_controls($pagination, t('pagination.photo_pages', 'Photo pages'));
        })
        : '';
    $lightboxEnabled = !empty($presentation['lightbox_enabled']) && $total > 0;
    $lightboxHtml = $lightboxEnabled
        ? smart_gallery_capture_html(static function () use ($presentation, $gallery): void {
            render_lightbox(
                !empty($presentation['voting_enabled']),
                false,
                '',
                (string) $gallery['title'],
                (string) $presentation['lightbox_browsing_mode'],
                !empty($presentation['slideshow_enabled'])
            );
        })
        : '';
    $download = null;
    if (!empty($presentation['download_enabled']) && $total > 0) {
        $download = [
            'id' => (int) $gallery['id'],
            'label' => t('smart_gallery.download', 'Download Smart Gallery'),
            'capability' => download_capability_issue(
                DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY,
                (int) $gallery['id'],
                DOWNLOAD_CAPABILITY_SCOPE_LEGACY
            ),
            'action_url' => url_for('download_smart_gallery'),
            'start_url' => url_for('download_smart_gallery_start', ['id' => (int) $gallery['id']]),
        ];
    }

    \Gallery\Views\view_render_public_smart_gallery([
        'title' => (string) $gallery['title'],
        'description' => (string) $gallery['description'],
        'total' => $total,
        'download' => $download,
        'pagination_html' => $paginationHtml,
        'lightbox_enabled' => $lightboxEnabled,
        'lightbox_endpoint' => url_for('smart_gallery_lightbox_data', ['id' => (int) $gallery['id']]),
        'lightbox_browsing_mode' => (string) $presentation['lightbox_browsing_mode'],
        'cards' => smart_gallery_image_cards_view_model($images, $sourceGalleries, $presentation, $votes, $tags, $offset, true),
        'lightbox_html' => $lightboxHtml,
    ]);

    if ($testRunActive) {
        admin_test_run_mark('smart_gallery_render_before_footer');
        admin_test_run_record_component('smart_gallery', [
            'smart_gallery_id' => (int) $gallery['id'],
            'total_matching_images' => $total,
            'page_images' => count($images),
            'limit' => $limit,
            'offset' => $offset,
            'pagination_enabled' => $usePagination,
            'pagination_forced_for_safety' => $paginationRequiredForSafety,
            'source_gallery_count' => count($sourceGalleries),
            'lightbox_enabled' => $lightboxEnabled,
            'presentation' => [
                'grid_columns' => (int) $presentation['grid_columns'],
                'grid_rows' => (int) $presentation['grid_rows'],
                'thumbnail_rendering_mode' => (string) ($presentation['thumbnail_rendering_mode'] ?? ''),
                'metadata_visible' => !empty($presentation['metadata_visible']),
                'voting_enabled' => !empty($presentation['voting_enabled']),
                'slideshow_enabled' => !empty($presentation['slideshow_enabled']),
            ],
            'query_page_size_cap' => SMART_GALLERY_QUERY_MAX_PAGE_SIZE,
            'lightbox_window_cap' => SMART_GALLERY_LIGHTBOX_MAX_WINDOW,
        ]);
    }
    render_footer();
    if ($testRunActive) admin_test_run_mark('smart_gallery_render_end');
}

/** Return one authorized bounded metadata window for complete Smart Gallery lightbox navigation. */
function cms_smart_gallery_lightbox_data(): void
{
    $gallery = smart_gallery_find_public_by_id(max(0, (int) ($_GET['id'] ?? 0)));
    if (!$gallery) {
        gallery_lightbox_json_response(['ok' => false, 'error' => 'not_found'], 404);
        return;
    }

    try {
        $presentation = smart_gallery_effective_presentation($gallery);
        if (empty($presentation['lightbox_enabled'])) {
            gallery_lightbox_json_response(['ok' => false, 'error' => 'not_found'], 404);
            return;
        }
        $total = smart_gallery_count_images($gallery, true);
        $limit = max(1, min(SMART_GALLERY_LIGHTBOX_MAX_WINDOW, (int) ($_GET['limit'] ?? 60)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        if ($total > 0 && $offset >= $total) $offset = max(0, $total - $limit);
        $images = $total > 0 ? smart_gallery_lightbox_fetch_images($gallery, true, $offset, $limit) : [];
    } catch (InvalidArgumentException) {
        gallery_lightbox_json_response(['ok' => false, 'error' => 'not_found'], 404);
        return;
    }

    $contentLanguage = translation_active_language();
    $sourceGalleries = smart_gallery_source_galleries($images);
    $imageIds = array_map(static fn (array $image): int => (int) $image['id'], $images);
    $votes = !empty($presentation['voting_enabled']) ? current_votes_for_images($imageIds) : [];
    $viewerPrincipal = current_viewer();
    $viewerFavouriteStates = $viewerPrincipal !== null && viewer_favourites_storage_available()
        ? viewer_favourites_for_image_ids((int) $viewerPrincipal['id'], $imageIds)
        : null;
    $viewerFavouriteRequiresSourceRecheck = is_array($viewerFavouriteStates) && current_user() !== null;
    if (!empty($presentation['voting_enabled'])) {
        csrf_token();
    }

    cms_release_gallery_lightbox_session_lock();

    $images = content_localize_entities('image', $images, $contentLanguage);
    foreach ($sourceGalleries as $sourceId => $sourceGallery) {
        $sourceGalleries[$sourceId] = content_localize_entity('gallery', $sourceGallery, $contentLanguage);
    }
    thumbnail_bundles_preload($images);
    $items = [];
    foreach ($images as $rowIndex => $image) {
        $source = $sourceGalleries[(int) ($image['gallery_id'] ?? 0)] ?? null;
        if (!$source) continue;
        $votingAllowed = !empty($presentation['voting_enabled']) && gallery_voting_allowed($source);
        $item = gallery_lightbox_json_item($image, $source, $offset + $rowIndex, gallery_allows_gps_maps($source), $votingAllowed, $votes);
        if (is_array($viewerFavouriteStates)
            && (!$viewerFavouriteRequiresSourceRecheck || viewer_source_image_can_reference((int) $image['id']))) {
            $item['viewer_favourite'] = !empty($viewerFavouriteStates[(int) $image['id']]);
        }
        $items[] = $item;
    }

    gallery_lightbox_json_response([
        'ok' => true,
        'smart_gallery_id' => (int) $gallery['id'],
        'total' => $total,
        'offset' => $offset,
        'limit' => $limit,
        'count' => count($items),
        'items' => $items,
    ]);
}
