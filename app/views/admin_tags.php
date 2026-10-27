<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_tags.php
 * Module Type: View
 *
 * Purpose:
 *   Renders the admin tag management surface from controller-prepared state.
 *
 * Responsibilities:
 *   - Render the tag list, selection state, usage references, and editor form
 *   - Render side-panel and full-page variants without owning request state
 *   - Keep tag-management markup out of the controller
 *
 * Author:
 *   Rudolf Klusal
 *
 * License: MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Tag persistence, normalization, feature policy, URL generation, and CSRF issuance stay in the controller.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/**
 * Render the Admin tag-management page from its prepared view model.
 *
 * @param array{panel?:bool,selected_id?:int,sort_mode?:string,media_type?:string,
 *     filter_options?:list<array{value:string,label:string,disabled:bool}>,
 *     notice?:string,error?:string,selected_tag?:array{id:int,media_type?:string}|null,
 *     tags?:list<array{id:int,name:string,slug:string,media_type?:string,
 *     usage_count?:int|string,edit_url:string}>,settings_url?:string,filters_url?:string,
 *     form?:array{tag?:array{id:int,name?:string,slug?:string},description?:string,
 *     media_type?:string,filter_value?:string,sort_mode?:string,action_url?:string,csrf_html?:string,
 *     public_url?:string,theme_url?:string},usage?:array{
 *     galleries?:list<array{public_url:string,title:string}>,
 *     images?:list<array{edit_url:string,side_panel_url:string,
 *     relative_path:string,gallery_title:string}>,
 *     songs?:list<array{edit_url:string,title:string}>}} $viewModel
 *     Prepared catalog, selected editor, safe usage links and request notices.
 * @return void Emit the tag list, filters, and available management actions.
 */
function view_render_admin_tags(array $viewModel): void
{
    $selectedId = (int) ($viewModel['selected_id'] ?? 0);
    $sortMode = (string) ($viewModel['sort_mode'] ?? 'usage');
    $mediaType = (string) ($viewModel['media_type'] ?? 'image');
    $notice = (string) ($viewModel['notice'] ?? '');
    $error = (string) ($viewModel['error'] ?? '');
    $selectedTag = is_array($viewModel['selected_tag'] ?? null)
        ? $viewModel['selected_tag']
        : null;

    if (!empty($viewModel['panel'])) {
        echo
            '<div class="admin-side-panel-stack admin-tags-panel-stack" data-admin-tag-edit-panel>';
        if ($notice !== '') {
            echo '<p class="success">' . e($notice) . '</p>';
        }
        if ($error !== '') {
            echo '<p class="error">' . e($error) . '</p>';
        }
        if (!$selectedTag) {
            echo
                '<div class="admin-side-panel-copy"><p class="admin-kicker">'
                    . e(t('admin.tags.kicker', 'Metadata'))
                    . '</p><h2>'
                    . e(t('admin.tags.no_selection', 'No tag selected'))
                    . '</h2><p class="muted">'
                    . e(t(
                        'admin.tags.no_selection_help',
                        'Select a tag ' . 'from the list to edit it.',
                    ))
                    . '</p></div>';
        } else {
            view_render_admin_tag_form((array) ($viewModel['form'] ?? []), 'panel');
        }
        echo '</div>';
        return;
    }

    render_header(t('admin.tags.title', 'Edit tags'));
    echo '<div class="admin-tags-page" id="admin-tags-workspace"><header class="admin-tags-toolbar">'
        . '<div class="admin-tags-title"><h1>'
        . e(t('admin.tags.title', 'Edit tags')) . '</h1>';
    view_render_admin_tag_help(
        'admin-tags-overview-help',
        t('admin.tags.title', 'Edit tags'),
        t(
            $mediaType === 'music' ? 'admin.tags.description_music' : 'admin.tags.description',
            'Rename reusable tags, adjust their clean URL slug, and add public text for tag landing pages. Tags are always stored as safe lowercase values.',
        ),
    );
    echo '</div><a class="admin-tags-settings" href="'
        . e((string) ($viewModel['settings_url'] ?? '')) . '">'
        . e(t('admin.menu.settings', 'Settings'))
        . '<span aria-hidden="true"> ↗</span></a></header>';

    if ($notice !== '') {
        echo '<p class="success">' . e($notice) . '</p>';
    }
    if ($error !== '') {
        echo '<p class="error">' . e($error) . '</p>';
    }

    echo '<div class="admin-tags-layout">';
    echo '<section class="admin-content-group admin-tags-list-panel">';
    echo '<h2>' . e(t('admin.tags.existing_tags', 'Existing tags'));
    view_render_admin_tag_help(
        'admin-tags-filter-help',
        t('admin.tags.filter_label', 'Filter by use'),
        t('admin.tags.filter_help',
            'Images and Galleries filter by direct assignments. Shared tags can appear in both; editing a tag changes it everywhere. Counts follow the selected filter. All tags includes unassigned tags. Documents is reserved for future PDF tagging.',
        ),
    );
    if ($selectedTag) {
        echo '<a class="admin-tags-editor-jump" href="#admin-tags-editor"'
            . ' aria-label="' . e(t('admin.tags.edit_heading', 'Edit tag'))
            . '">↗</a>';
    }
    echo '</h2>';

    echo '<form method="get" action="'
        . e((string) ($viewModel['filters_url'] ?? ''))
        . '" class="admin-tags-sort-form">';
    echo '<input type="hidden" name="page" value="admin_tags">';
    if ($selectedId > 0) {
        echo '<input type="hidden" name="id" value="' . $selectedId . '">';
    }
    if (!empty($viewModel['panel'])) {
        echo '<input type="hidden" name="panel" value="1">';
    }
    echo '<label><span>'
        . e(t('admin.tags.filter_label', 'Filter by use'))
        . '</span><select name="media_type">';
    foreach ($viewModel['filter_options'] ?? [] as $option) {
        echo '<option value="' . e((string) $option['value']) . '"'
            . ($mediaType === $option['value'] ? ' selected' : '')
            . (!empty($option['disabled']) ? ' disabled' : '') . '>'
            . e((string) $option['label']) . '</option>';
    }
    echo '</select></label>';
    echo
        '<label><span>'
            . e(t('admin.tags.sort_label', 'Sort'))
            . '</span><select name="sort">';
    echo
        '<option value="usage"'
            . ($sortMode === 'usage' ? ' selected' : '')
            . '>'
            . e(t('admin.tags.sort_usage', 'Most used'))
            . '</option>';
    echo
        '<option value="name"'
            . ($sortMode === 'name' ? ' selected' : '')
            . '>'
            . e(t('admin.tags.sort_name', 'Alphabetical'))
            . '</option>';
    echo '</select></label>';
    echo '<button type="submit" class="button secondary">'
        . e(t('admin.tags.sort_apply', 'Apply')) . '</button>';
    echo '</form>';

    $tags = (array) ($viewModel['tags'] ?? []);
    if (!$tags) {
        echo
            '<p class="muted">'
                . e(t(
                    match ($mediaType) {
                        'music' => 'admin.tags.empty_music',
                        'image' => 'admin.tags.empty_images',
                        'gallery' => 'admin.tags.empty_galleries',
                        'documents' => 'admin.tags.documents_pending',
                        default => 'admin.tags.empty',
                    },
                    'No tags exist yet. Add tags from a gallery or image editor first.',
                ))
                . '</p>';
    } else {
        echo '<ul class="admin-tags-list">';
        foreach ($tags as $tag) {
            $active = (int) ($tag['id'] ?? 0) === $selectedId;
            $usage = (int) ($tag['usage_count'] ?? 0);
            echo
                '<li><a class="admin-tag-row'
                    . ($active ? ' is-active' : '')
                    . '"' . ($active ? ' aria-current="true"' : '')
                    . ' href="'
                    . e((string) ($tag['edit_url'] ?? ''))
                    . '">';
            echo '<span class="admin-tag-copy"><strong>'
                . e((string) ($tag['name'] ?? '')) . '</strong>';
            if ((string) ($tag['slug'] ?? '') !== (string) ($tag['name'] ?? '')) {
                echo '<small>/' . e((string) ($tag['slug'] ?? '')) . '</small>';
            }
            if ($mediaType === 'all') {
                echo '<small class="admin-tag-media-type">'
                    . e(t(
                        (string) ($tag['media_type'] ?? 'image') === 'image'
                            ? 'admin.tags.type_visual'
                            : 'admin.tags.type_' . (string) $tag['media_type'],
                        (string) ($tag['media_type'] ?? 'image'),
                    )) . '</small>';
            }
            echo '</span>';
            if ((string) ($tag['media_type'] ?? 'image') === 'music') {
                echo '<em>' . e(t('admin.tags.music_usage_count', '{count} songs', [
                    'count' => $usage,
                ])) . '</em></a></li>';
                continue;
            }
            echo '<em>' . e(t('admin.tags.usage_count', '{count} uses', [
                'count' => $usage,
            ])) . '</em></a></li>';
        }
        echo '</ul>';
    }
    echo '</section>';

    echo '<div class="admin-tags-edit-panel" id="admin-tags-editor">';
    if (!$selectedTag) {
        echo
            '<section class="admin-content-group"><h2>'
                . e(t('admin.tags.no_selection', 'No tag selected'))
                . '</h2>';
        echo
            '<p class="muted">'
                . e(t(
                    'admin.tags.no_selection_help',
                    'Select a tag ' . 'from the list to edit it.',
                ))
                . '</p></section>';
    } else {
        view_render_admin_tag_form((array) ($viewModel['form'] ?? []));
        echo
            '<section class="admin-content-group admin-tags-usage-panel"><h3>'
                . e(t('admin.tags.used_where', 'Used in'))
                . '</h3>';
        $usage = (array) ($viewModel['usage'] ?? []);
        $galleries = (array) ($usage['galleries'] ?? []);
        $images = (array) ($usage['images'] ?? []);
        $songs = (array) ($usage['songs'] ?? []);
        if (!$galleries && !$images && !$songs) {
            echo
                '<p class="muted">'
                    . e(t(
                        (string) ($selectedTag['media_type'] ?? 'image') === 'music'
                            ? 'admin.tags.used_where_empty_music'
                            : 'admin.tags.used_where_empty',
                        'This tag is not attached to any galleries or images yet.',
                    ))
                    . '</p>';
        } else {
            if ($galleries) {
                echo
                    '<div class="admin-tags-usage-group"><h4>'
                        . e(t('admin.tags.used_in_galleries', 'Galleries'))
                        . '</h4><ul class="admin-tags-usage-list">';
                foreach ($galleries as $gallery) {
                    echo
                        '<li><a href="'
                            . e((string) ($gallery['public_url'] ?? ''))
                            . '" target="_blank" rel="noopener">'
                            . e((string) ($gallery['title'] ?? ''))
                            . '</a></li>';
                }
                echo '</ul></div>';
            }
            if ($images) {
                echo
                    '<div class="admin-tags-usage-group"><h4>'
                        . e(t('admin.tags.used_in_images', 'Images'))
                        . '</h4><ul class="admin-tags-usage-list">';
                foreach ($images as $image) {
                    echo
                        '<li><a href="'
                            . e((string) ($image['edit_url'] ?? ''))
                            . '" data-gallery-side-panel-link data-admin-side-panel-workflow="image-edit" data-admin-side-panel-kicker="'
                            . e(t('gallery.photo_editor', 'Photo editor'))
                            . '" data-admin-side-panel-title="'
                            . e(t(
                                'admin.gallery_editor.edit_photo',
                                'Edit photo',
                            ))
                            . '" data-gallery-side-panel-url="'
                            . e((string) ($image['side_panel_url'] ?? ''))
                            . '">'
                            . e((string) ($image['relative_path'] ?? ''))
                            . '</a><small>'
                            . e((string) ($image['gallery_title'] ?? ''))
                            . '</small></li>';
                }
                echo '</ul></div>';
            }
            if ($songs) {
                echo '<div class="admin-tags-usage-group"><h4>'
                    . e(t('admin.tags.used_in_music', 'Songs'))
                    . '</h4><ul class="admin-tags-usage-list">';
                foreach ($songs as $song) {
                    echo '<li><a href="' . e((string) ($song['edit_url'] ?? ''))
                        . '">' . e((string) ($song['title'] ?? ''))
                        . '</a></li>';
                }
                echo '</ul></div>';
            }
        }
        echo '</section>';
    }
    echo '</div></div></div>';
    render_footer();
}

/**
 * Render the Admin form for creating or editing one tag.
 *
 * @param array{tag?:array{id:int,name?:string,slug?:string},description?:string,
 *     media_type?:string,filter_value?:string,sort_mode?:string,action_url?:string,csrf_html?:string,
 *     public_url?:string,theme_url?:string} $viewModel Prepared field values,
 *     issued CSRF markup and allowed action destinations.
 * @param string $context Page (default) or panel context for distinct help IDs.
 * @return void Emit the selected-tag fields and save controls.
 */
function view_render_admin_tag_form(
    array $viewModel,
    string $context = 'page',
): void {
    $tag = (array) ($viewModel['tag'] ?? []);
    $isMusic = (string) ($viewModel['media_type'] ?? 'image') === 'music';
    $idPrefix = 'admin-tag-' . $context . '-' . (int) ($tag['id'] ?? 0);
    echo '<section class="admin-content-group admin-tags-editor-group"><h2>'
        . e(t('admin.tags.edit_heading', 'Edit tag')) . '</h2>';
    echo
        '<form method="post" action="'
            . e((string) ($viewModel['action_url'] ?? ''))
            . '" class="admin-tags-form">'
            . (string) ($viewModel['csrf_html'] ?? '');
    echo
        '<input type="hidden" name="tag_id" value="'
            . (int) ($tag['id'] ?? 0)
            . '"><input type="hidden" name="action" value="save"><input type="hidden" name="sort" value="'
            . e((string) ($viewModel['sort_mode'] ?? 'usage'))
            . '"><input type="hidden" name="media_type" value="'
            . e((string) ($viewModel['media_type'] ?? 'image'))
            . '"><input type="hidden" name="catalog_filter" value="'
            . e((string) ($viewModel['filter_value'] ?? $viewModel['media_type'] ?? 'image'))
            . '">';
    view_render_admin_tag_field_heading(
        $idPrefix . '-name',
        t('admin.tags.name', 'Tag name'),
        t('admin.tags.name_help',
            'Use lowercase letters, numbers, and hyphens only. Other input is normalized automatically when saved.',
        ),
    );
    echo '<input id="' . e($idPrefix . '-name') . '" name="name" value="'
        . e((string) ($tag['name'] ?? ''))
        . '" aria-describedby="' . e($idPrefix . '-name-help')
        . '" required maxlength="100" autocomplete="off">';
    view_render_admin_tag_field_heading(
        $idPrefix . '-slug',
        t($isMusic ? 'admin.tags.slug_music' : 'admin.tags.slug', 'URL slug'),
        t($isMusic ? 'admin.tags.slug_help_music' : 'admin.tags.slug_help',
            'This controls the public tag URL. Keep it short and stable when possible.',
        ),
    );
    echo '<input id="' . e($idPrefix . '-slug') . '" name="slug" value="'
        . e((string) ($tag['slug'] ?? ''))
        . '" aria-describedby="' . e($idPrefix . '-slug-help')
        . '" required maxlength="120" autocomplete="off">';
    view_render_admin_tag_field_heading(
        $idPrefix . '-description',
        t($isMusic ? 'admin.tags.description_label_music' : 'admin.tags.public_description',
            'Public description',
        ),
        t($isMusic ? 'admin.tags.description_help_music' : 'admin.tags.description_help',
            'Optional text shown on the public tag landing page.',
        ),
    );
    echo '<textarea id="' . e($idPrefix . '-description')
        . '" name="description" aria-describedby="'
        . e($idPrefix . '-description-help') . '" rows="4">'
        . e((string) ($viewModel['description'] ?? '')) . '</textarea>';
    echo
        '<div class="bulk-row"><button type="submit">'
            . e(t('admin.tags.save', 'Save tag'))
            . '</button>';
    if (!empty($viewModel['public_url'])) {
        echo
            '<a class="button secondary" href="'
                . e((string) $viewModel['public_url'])
                . '">'
                . e(t('admin.tags.view_public', 'View public tag'))
                . '</a>';
    }
    if ((string) ($viewModel['theme_url'] ?? '') !== '') {
        echo '<a class="button secondary" href="'
            . e((string) ($viewModel['theme_url'] ?? ''))
            . '">'
            . e(t('admin.tags.configure_display', 'Configure tag display'))
            . '</a>';
    }
    echo '</div></form></section>';
}

/**
 * Render a field label and its adjacent contextual explanation.
 *
 * @param string $inputId Unique native input ID selected by the form renderer.
 * @param string $label Localized field name.
 * @param string $help Localized plain explanation, escaped by the help renderer.
 * @return void Emit an associated label and question-mark trigger.
 */
function view_render_admin_tag_field_heading(
    string $inputId,
    string $label,
    string $help,
): void {
    echo '<div class="admin-tags-field-heading"><label for="' . e($inputId)
        . '">' . e($label) . '</label>';
    view_render_admin_tag_help($inputId . '-help', $label, $help);
    echo '</div>';
}

/**
 * Render the tag workspace's native, light-dismiss contextual help.
 *
 * @param string $id Unique help ID within the page/panel document.
 * @param string $label Localized control or workspace name.
 * @param string $text Plain localized explanation; never trusted HTML.
 * @return void Emit an accessible native popover and its explicit invoker.
 */
function view_render_admin_tag_help(string $id, string $label, string $text): void
{
    echo '<button type="button" class="admin-tags-help" popovertarget="'
        . e($id) . '" aria-controls="' . e($id) . '" aria-label="'
        . e(t('admin.settings.workspace.help_item', 'Help: {label}', [
            'label' => $label,
        ])) . '">?</button><div popover class="admin-tags-help-panel" id="'
        . e($id) . '"><strong>' . e($label) . '</strong><p>'
        . e($text) . '</p></div>';
}
