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

use function Gallery\Core\url_for;
use function Gallery\Services\feature_capability_effective_enabled;
use function Gallery\Services\gallery_flight_map_row;
use function Gallery\Services\gallery_flight_map_unresolved_from_row;
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
 * @param array<string, mixed> $capabilities Resolved editor capabilities.
 * @return void Emits Identity markup with the bounded parent picker and prepared feature controls.
 * @author Rudolf Klusal
 */
function admin_edit_gallery_render_identity_tab(
    array $gallery,
    string $activeEditTab,
    array $capabilities,
): void {
    $formModel = admin_gallery_form_view_model('gallery', $gallery);

    ob_start();
    view_render_admin_gallery_date_range_fields($gallery, false, $formModel);
    $dateFieldsHtml = (string) ob_get_clean();

    ob_start();
    render_gallery_description_formatting_hint();
    $descriptionHintHtml = (string) ob_get_clean();

    ob_start();
    view_render_content_localization_fields(
        'gallery',
        $gallery,
        $formModel,
        'source',
    );
    $sourceLanguageHtml = (string) ob_get_clean();

    ob_start();
    view_render_content_localization_fields(
        'gallery',
        $gallery,
        $formModel,
        'translations',
    );
    $localizationHtml = (string) ob_get_clean();

    $flightMap = ['state' => 'hidden'];
    if (!empty($capabilities['flight_map_ready'])) {
        // $flightMapRow stores the existing route-map data shown in the editor.
        $flightMapRow = gallery_flight_map_row((int) $gallery['id']);
        // $flightRouteText stores the raw route text that will be resolved during save.
        $flightRouteText = (string) ($flightMapRow['route_text'] ?? '');
        // $flightPointCount stores how many route points are ready for display.
        $flightPointCount = (int) ($flightMapRow['point_count'] ?? 0);
        // $flightUnresolved stores unresolved diagnostics from the last save.
        $flightUnresolved = $flightMapRow
            ? gallery_flight_map_unresolved_from_row($flightMapRow)
            : [];
        $flightMap = [
            'state' => 'ready',
            'title' => t(
                'admin.gallery_editor.flight_route_map',
                'Flight route map',
            ),
            'label' => t(
                'admin.gallery_editor.flight_route_label',
                'Route text',
            ),
            'route_text' => $flightRouteText,
            'help' => t(
                'admin.gallery_editor.flight_route_help',
                'For simflying galleries, this gallery stores one resolved route map. SimBrief OFP data and route points are stored when you save the gallery. Manual routes still support local lookup and NAME@latitude,longitude entries.',
            ),
            'status' => t(
                'admin.gallery_editor.flight_route_status',
                'Resolved points: {points}. Unresolved skipped: {unresolved}.',
                [
                    'points' => (string) $flightPointCount,
                    'unresolved' => (string) count($flightUnresolved),
                ],
            ),
        ];
    } elseif (!empty($capabilities['flight_map_feature_enabled'])) {
        $flightMap = [
            'state' => 'migration',
            'migration_message' => t(
                'admin.gallery_editor.flight_route_migration_hidden',
                'Flight route map controls will be available after the database migration is applied.',
            ),
        ];
    }

    ob_start();
    render_admin_simbrief_description_tool(
        (int) $gallery['id'],
        $formModel,
        $flightMap,
    );
    $simbriefHtml = (string) ob_get_clean();

    $openaiHtml = '';
    if (
        (
            !function_exists(
                'Gallery\\Services\\feature_capability_effective_enabled',
            )
            || feature_capability_effective_enabled('openai_text_assist')
        )
        && function_exists(
            'Gallery\\Views\\view_render_admin_openai_text_assist_tool',
        )
    ) {
        ob_start();
        view_render_admin_openai_text_assist_tool(
            (int) $gallery['id'],
            0,
            'gallery',
            $formModel,
        );
        $openaiHtml = (string) ob_get_clean();
    }

    ob_start();
    render_tag_datalist();
    $tagDatalistHtml = (string) ob_get_clean();

    view_render_admin_gallery_identity_tab([
        'active' => $activeEditTab === 'admin-edit-identity',
        'gallery' => $gallery,
        'folder_name' => gallery_folder_name_from_path(
            (string) $gallery['folder_path'],
        ),
        'parent_picker_html' => render_gallery_parent_picker(
            (int) ($gallery['parent_id'] ?? 0),
            (int) $gallery['id'],
        ),
        'tags' => tag_names_for_entity('gallery', (int) $gallery['id']),
        'tag_suggestions_attribute' => admin_weighted_tag_suggestions_attribute(
            (int) $gallery['id'],
        ),
        'date_fields_html' => $dateFieldsHtml,
        'description_hint_html' => $descriptionHintHtml,
        'localization_html' => $localizationHtml,
        'source_language_html' => $sourceLanguageHtml,
        'simbrief_html' => $simbriefHtml,
        'flight_map' => $flightMap,
        'openai_html' => $openaiHtml,
        'tag_datalist_html' => $tagDatalistHtml,
        'smart_attachments' =>
            admin_edit_gallery_smart_gallery_attachments_view_model($gallery),
        'labels' => [
            'title' => t('admin.gallery_editor.title', 'Title'),
            'description' => t(
                'admin.gallery_editor.description',
                'Description',
            ),
            'slug' => t('admin.gallery_editor.slug', 'Slug'),
            'slug_help' => t(
                'admin.gallery_editor.slug_help',
                'Used in the public gallery URL.',
            ),
            'folder_name' => t(
                'admin.gallery_editor.folder_name',
                'Folder name',
            ),
            'folder_rename_help' => t(
                'admin.gallery_editor.folder_rename_help',
                'Changing this renames the folder on disk.',
            ),
            'parent_gallery' => t(
                'admin.gallery_editor.parent_gallery',
                'Parent gallery',
            ),
            'no_parent' => t('admin.gallery_editor.no_parent', 'No parent'),
            'sort_order' => t('admin.gallery_editor.sort_order', 'Sort order'),
            'tags' => t('admin.gallery_editor.tags', 'Tags'),
            'tags_help' => t(
                'admin.gallery_editor.tags_help',
                'Separate tags with commas. Suggested tags are ranked by nearby galleries, images, and folder context.',
            ),
        ],
    ]);
}

/**
 * Prepare Smart Gallery attachment presentation data for the Identity tab.
 *
 * Placement and ordering are stored per parent gallery, so this card only
 * describes attachments as they apply to the gallery being edited.
 *
 * @param array{id:int|string} $gallery Physical gallery identity being edited.
 * @return array{metadata_ready:bool,migration_notice_html:string,
 *     definitions:list<array{id:int,title:string,edit_url:string,
 *     visibility_state:string,assigned:bool,placement:string,
 *     placement_order:int,relationship_valid:bool}>,create_url:string,
 *     labels:array<string,string>}|null Attachment rows, prepared destinations
 *     and localized labels, or null when the feature is unavailable.
 */
function admin_edit_gallery_smart_gallery_attachments_view_model(array $gallery): ?array
{
    if (
        function_exists(
            'Gallery\\Services\\feature_capability_effective_enabled',
        )
        && !feature_capability_effective_enabled('smart_galleries')
    ) {
        return null;
    }
    if (!smart_gallery_schema_ready()) {
        return null;
    }

    $smartGalleryDefinitions = smart_galleries_all();
    $attachmentMetadataReady = smart_gallery_attachment_schema_ready();
    $attachedRows = $attachmentMetadataReady
        ? smart_gallery_attachment_rows_for_gallery((int) $gallery['id'])
        : [];
    $attachedById = [];
    foreach ($attachedRows as $attachedRow) {
        $attachedById[(int) $attachedRow['id']] = $attachedRow;
    }

    $migrationNoticeHtml = '';
    if (!$attachmentMetadataReady) {
        ob_start();
        render_admin_migration_notice(t(
            'smart_gallery.attachment_migration_required',
            'Run the pending database migration before changing Smart Gallery placement or ordering. Existing attachments continue to render below gallery content until then.',
        ));
        $migrationNoticeHtml = (string) ob_get_clean();
    }

    $definitions = [];
    foreach ($smartGalleryDefinitions as $smartDefinition) {
        $smartId = (int) $smartDefinition['id'];
        $current = $attachedById[$smartId] ?? null;
        $assignedHere = is_array($current);
        $definitions[] = [
            'id' => $smartId,
            'title' => (string) $smartDefinition['title'],
            'edit_url' => url_for('admin_smart_galleries', ['id' => $smartId]),
            'visibility_state' => ($smartDefinition['visibility'] ?? '')
                === 'public'
            && !empty($smartDefinition['enabled'])
                ? t('smart_gallery.public', 'Published')
                : t('smart_gallery.not_public', 'Not publicly visible'),
            'assigned' => $assignedHere,
            'placement' => $assignedHere
                ? (string) ($current['placement'] ?? 'bottom')
                : 'bottom',
            'placement_order' => $assignedHere
                ? (int) ($current['placement_order'] ?? 0)
                : 0,
            'relationship_valid' =>
                !$assignedHere || !empty($current['relationship_valid']),
        ];
    }

    return [
        'metadata_ready' => $attachmentMetadataReady,
        'migration_notice_html' => $migrationNoticeHtml,
        'definitions' => $definitions,
        'create_url' => url_for('admin_smart_galleries', ['new' => 1]),
        'labels' => [
            'add' => t('smart_gallery.admin_title', 'Smart Galleries'),
            'search' => t('gallery_picker.search', 'Search'),
            'search_hint' => t(
                'gallery_picker.help',
                'Type to search, then press Enter or click a result to select it.',
            ),
            'no_matches' => t(
                'gallery_picker.no_results',
                'No matching galleries found.',
            ),
            'detach' => t('smart_gallery.hide_from_here', 'Detach'),
            'create' => t('smart_gallery.create', 'Create Smart Gallery'),
            'none_attached' => t('admin.common.none', 'None'),
            'save_hint' => t(
                'admin.gallery_editor.savebar_help',
                'Saves gallery settings, including presentation defaults.',
            ),
            'title' => t(
                'smart_gallery.children_title',
                'Smart Gallery attachments',
            ),
            'help' => t(
                'smart_gallery.children_help',
                'Attach virtual galleries above or below this physical gallery content. Placement and order apply only to this parent gallery.',
            ),
            'placement' => t(
                'smart_gallery.attachment_placement',
                'Placement for this parent',
            ),
            'placement_top' => t(
                'smart_gallery.placement_top',
                'Above gallery content',
            ),
            'placement_bottom' => t(
                'smart_gallery.placement_bottom',
                'Below gallery content',
            ),
            'order' => t('smart_gallery.attachment_order', 'Order'),
            'relationship_invalid' => t(
                'smart_gallery.relationship_invalid',
                'This attachment participates in a recursive or malformed Smart Gallery relationship. Detach it or change the referenced gallery rule.',
            ),
            'order_help' => t(
                'smart_gallery.attachment_order_help',
                'Order is evaluated separately within the Above and Below groups. Equal values are resolved deterministically by Smart Gallery ID.',
            ),
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
    $viewModel =
        admin_edit_gallery_smart_gallery_attachments_view_model($gallery);
    if ($viewModel !== null) {
        view_render_admin_gallery_smart_attachments($viewModel);
    }
}
