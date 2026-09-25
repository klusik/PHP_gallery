<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_galleries_edit_page/tab_identity.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders the Identity tab of the gallery editor.
 *
 * Responsibilities:
 *   - Edit the public title, date range, description, slug, and disk folder name
 *   - Edit the gallery tree position, sort order, and tags
 *   - Present Smart Gallery attachment placement and ordering for this parent
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
 *   - Loaded by app/controllers/admin_galleries_edit_page.php; do not require this file directly.
 *   - Fields render inside the shared editor form opened by the module entry point.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\gallery_folder_name_from_path;
use function Gallery\Services\smart_galleries_all;
use function Gallery\Services\smart_gallery_attachment_rows_for_gallery;
use function Gallery\Services\smart_gallery_attachment_schema_ready;
use function Gallery\Services\smart_gallery_schema_ready;
use function Gallery\Services\t;
use function Gallery\Services\tag_names_for_entity;
use function Gallery\Views\view_render_admin_gallery_date_range_fields;
use function Gallery\Views\view_render_admin_gallery_identity_tab;
use function Gallery\Views\view_render_admin_gallery_smart_attachments;
use function Gallery\Views\view_render_admin_openai_text_assist_tool;
use function Gallery\Views\view_render_content_localization_fields;

/**
 * Render the Identity tab panel.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @param string $activeEditTab Currently selected editor tab.
 * @return void Emits Identity markup with the bounded parent picker and prepared feature controls.
 * @author Rudolf Klusal
 */
function admin_edit_gallery_render_identity_tab(array $gallery, string $activeEditTab): void
{
    $formModel = admin_gallery_form_view_model('gallery', $gallery);

    ob_start();
    view_render_admin_gallery_date_range_fields($gallery, false, $formModel);
    $dateFieldsHtml = (string) ob_get_clean();

    ob_start();
    render_gallery_description_formatting_hint();
    $descriptionHintHtml = (string) ob_get_clean();

    ob_start();
    view_render_content_localization_fields('gallery', $gallery, $formModel);
    $localizationHtml = (string) ob_get_clean();

    ob_start();
    render_admin_simbrief_description_tool((int) $gallery['id'], $formModel);
    $simbriefHtml = (string) ob_get_clean();

    $openaiHtml = '';
    if ((!function_exists('Gallery\\Services\\feature_capability_effective_enabled') || feature_capability_effective_enabled('openai_text_assist'))
        && function_exists('Gallery\\Views\\view_render_admin_openai_text_assist_tool')) {
        ob_start();
        view_render_admin_openai_text_assist_tool((int) $gallery['id'], 0, 'gallery', $formModel);
        $openaiHtml = (string) ob_get_clean();
    }

    ob_start();
    render_tag_datalist();
    $tagDatalistHtml = (string) ob_get_clean();

    view_render_admin_gallery_identity_tab([
        'active' => $activeEditTab === 'admin-edit-identity',
        'gallery' => $gallery,
        'folder_name' => gallery_folder_name_from_path((string) $gallery['folder_path']),
        'parent_picker_html' => render_gallery_parent_picker((int) ($gallery['parent_id'] ?? 0), (int) $gallery['id']),
        'tags' => tag_names_for_entity('gallery', (int) $gallery['id']),
        'tag_suggestions_attribute' => admin_weighted_tag_suggestions_attribute((int) $gallery['id']),
        'date_fields_html' => $dateFieldsHtml,
        'description_hint_html' => $descriptionHintHtml,
        'localization_html' => $localizationHtml,
        'simbrief_html' => $simbriefHtml,
        'openai_html' => $openaiHtml,
        'tag_datalist_html' => $tagDatalistHtml,
        'smart_attachments' => admin_edit_gallery_smart_gallery_attachments_view_model($gallery),
        'labels' => [
            'title' => t('admin.gallery_editor.title', 'Title'),
            'description' => t('admin.gallery_editor.description', 'Description'),
            'slug' => t('admin.gallery_editor.slug', 'Slug'),
            'slug_help' => t('admin.gallery_editor.slug_help', 'Used in the public gallery URL.'),
            'folder_name' => t('admin.gallery_editor.folder_name', 'Folder name'),
            'folder_rename_help' => t('admin.gallery_editor.folder_rename_help', 'Changing this renames the folder on disk.'),
            'parent_gallery' => t('admin.gallery_editor.parent_gallery', 'Parent gallery'),
            'no_parent' => t('admin.gallery_editor.no_parent', 'No parent'),
            'sort_order' => t('admin.gallery_editor.sort_order', 'Sort order'),
            'tags' => t('admin.gallery_editor.tags', 'Tags'),
            'tags_help' => t('admin.gallery_editor.tags_help', 'Separate tags with commas. Suggested tags are ranked by nearby galleries, images, and folder context.'),
        ],
    ]);
}

/**
 * Prepare Smart Gallery attachment presentation data for the Identity tab.
 *
 * Placement and ordering are stored per parent gallery, so this card only
 * describes attachments as they apply to the gallery being edited.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 * @return ?array<string, mixed> View model, or null when the feature is unavailable.
 */
function admin_edit_gallery_smart_gallery_attachments_view_model(array $gallery): ?array
{
    if (function_exists('Gallery\\Services\\feature_capability_effective_enabled')
        && !feature_capability_effective_enabled('smart_galleries')) {
        return null;
    }
    if (!smart_gallery_schema_ready()) {
        return null;
    }

    $smartGalleryDefinitions = smart_galleries_all();
    $attachmentMetadataReady = smart_gallery_attachment_schema_ready();
    $attachedRows = $attachmentMetadataReady ? smart_gallery_attachment_rows_for_gallery((int) $gallery['id']) : [];
    $attachedById = [];
    foreach ($attachedRows as $attachedRow) {
        $attachedById[(int) $attachedRow['id']] = $attachedRow;
    }

    $migrationNoticeHtml = '';
    if (!$attachmentMetadataReady) {
        ob_start();
        render_admin_migration_notice(t('smart_gallery.attachment_migration_required', 'Run the pending database migration before changing Smart Gallery placement or ordering. Existing attachments continue to render below gallery content until then.'));
        $migrationNoticeHtml = (string) ob_get_clean();
    }

    $groups = [];
    foreach (['top' => 'Above gallery content', 'bottom' => 'Below gallery content'] as $groupKey => $fallbackLabel) {
        $groupRows = [];
        foreach ($attachedRows as $attachedRow) {
            if (($attachedRow['placement'] ?? 'bottom') !== $groupKey) {
                continue;
            }
            $state = ($attachedRow['visibility'] ?? '') === 'public' && !empty($attachedRow['enabled'])
                ? t('smart_gallery.public', 'Published')
                : t('smart_gallery.not_public', 'Not publicly visible');
            $diagnostic = empty($attachedRow['relationship_valid'])
                ? ' · ' . t('smart_gallery.relationship_invalid_short', 'relationship needs repair')
                : '';
            $groupRows[] = [
                'title' => (string) $attachedRow['title'],
                'status' => t('smart_gallery.order_value', 'order {order}', ['order' => (int) ($attachedRow['placement_order'] ?? 0)]) . ' · ' . $state . $diagnostic,
            ];
        }
        $groups[] = [
            'key' => $groupKey,
            'label' => t('smart_gallery.placement_' . $groupKey, $fallbackLabel),
            'rows' => $groupRows,
        ];
    }

    $definitions = [];
    foreach ($smartGalleryDefinitions as $smartDefinition) {
        $smartId = (int) $smartDefinition['id'];
        $current = $attachedById[$smartId] ?? null;
        $assignedHere = is_array($current);
        $definitions[] = [
            'id' => $smartId,
            'title' => (string) $smartDefinition['title'],
            'visibility_state' => ($smartDefinition['visibility'] ?? '') === 'public' && !empty($smartDefinition['enabled'])
                ? t('smart_gallery.public', 'Published')
                : t('smart_gallery.not_public', 'Not publicly visible'),
            'assigned' => $assignedHere,
            'placement' => $assignedHere ? (string) ($current['placement'] ?? 'bottom') : 'bottom',
            'placement_order' => $assignedHere ? (int) ($current['placement_order'] ?? 0) : 0,
            'relationship_valid' => !$assignedHere || !empty($current['relationship_valid']),
        ];
    }

    return [
        'metadata_ready' => $attachmentMetadataReady,
        'migration_notice_html' => $migrationNoticeHtml,
        'groups' => $groups,
        'definitions' => $definitions,
        'labels' => [
            'title' => t('smart_gallery.children_title', 'Smart Gallery attachments'),
            'help' => t('smart_gallery.children_help', 'Attach virtual galleries above or below this physical gallery content. Placement and order apply only to this parent gallery.'),
            'empty' => t('smart_gallery.children_empty', 'No Smart Galleries exist yet.'),
            'current_attachments' => t('smart_gallery.current_attachments', 'Current Smart Gallery attachments'),
            'group_empty' => t('smart_gallery.attachment_group_empty', 'No Smart Galleries in this placement area.'),
            'settings' => t('smart_gallery.attachment_settings', 'Attachment settings'),
            'placement' => t('smart_gallery.attachment_placement', 'Placement for this parent'),
            'placement_top' => t('smart_gallery.placement_top', 'Above gallery content'),
            'placement_bottom' => t('smart_gallery.placement_bottom', 'Below gallery content'),
            'order' => t('smart_gallery.attachment_order', 'Order'),
            'relationship_invalid' => t('smart_gallery.relationship_invalid', 'This attachment participates in a recursive or malformed Smart Gallery relationship. Detach it or change the referenced gallery rule.'),
            'order_help' => t('smart_gallery.attachment_order_help', 'Order is evaluated separately within the Above and Below groups. Equal values are resolved deterministically by Smart Gallery ID.'),
        ],
    ];
}

/**
 * Render the Smart Gallery attachment card inside the Identity tab.
 *
 * @param array<string, mixed> $gallery Gallery row being edited.
 */
function admin_edit_gallery_render_smart_gallery_attachments(array $gallery): void
{
    $viewModel = admin_edit_gallery_smart_gallery_attachments_view_model($gallery);
    if ($viewModel !== null) {
        view_render_admin_gallery_smart_attachments($viewModel);
    }
}
