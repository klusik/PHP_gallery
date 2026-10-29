<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/public_gallery_controls.php
 * Module Type: View
 *
 * Purpose:
 *   Renders public gallery controls, navigation, branding, and access-gate
 *   presentation from controller-prepared view models.
 *
 * Responsibilities:
 *   - Render admin-only public gallery toolbars without owning feature policy
 *   - Render breadcrumbs and gallery branding presentation
 *   - Render gallery access-gate states without inspecting request/session data
 *   - Consume controller-prepared URLs, CSRF fragments, and domain state
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
 *   - Translation, escaping, shared layout renderers, and sibling view helpers
 *     are presentation dependencies.
 *   - The controller owns request parsing, feature policy, access policy,
 *     persistence, URL preparation, and domain-service orchestration.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/**
 * Render the admin-only subgallery date-sort preview toolbar.
 *
 * @param array{active_mode?:string,urls?:array{default?:string,asc?:string,
 *     desc?:string},save_url?:string,csrf_html?:string,gallery_id?:int} $viewModel
 *     Prepared preview links and authenticated save form state.
 * @return void Emits the admin-only date-sort controls.
 */
function view_render_public_subgallery_date_sort_toolbar(array $viewModel): void
{
    $activeMode = (string) ($viewModel['active_mode'] ?? '');
    $urls = (array) ($viewModel['urls'] ?? []);
    $defaultClass =
        'button secondary public-subgallery-sort-button'
        . ($activeMode === '' ? ' is-active' : '');
    $ascClass =
        'button secondary public-subgallery-sort-button'
        . ($activeMode === 'asc' ? ' is-active' : '');
    $descClass =
        'button secondary public-subgallery-sort-button'
        . ($activeMode === 'desc' ? ' is-active' : '');
    $defaultCurrent = $activeMode === '' ? ' aria-current="true"' : '';
    $ascCurrent = $activeMode === 'asc' ? ' aria-current="true"' : '';
    $descCurrent = $activeMode === 'desc' ? ' aria-current="true"' : '';

    echo
        '<div class="public-subgallery-sort-toolbar public-gallery-control-bar" aria-label="'
            . e(t('public.subgallery_sort.label', 'Subgallery sort'))
            . '">';
    echo
        '<div class="public-gallery-control-heading"><span class="public-gallery-control-icon" aria-hidden="true">↕</span><strong>'
            . e(t('public.subgallery_sort.title', 'Sort subgalleries by date'))
            . '</strong><details class="public-gallery-control-help" data-gallery-media-widget><summary aria-label="'
            . e(t('public.subgallery_sort.title', 'Sort subgalleries by date'))
            . '"><span aria-hidden="true">?</span></summary><div class="public-gallery-control-help-panel" data-gallery-media-panel>'
            . e(t(
                'public.subgallery_sort.help',
                'Only subgalleries with a From date participate. Undated cards keep their current positions. Preview the date order, then save it to update the real order for everyone.',
            ))
            . '</div></details></div>';
    echo '<div class="public-subgallery-sort-actions">';
    echo
        '<a class="'
            . e($defaultClass)
            . '" href="'
            . e((string) ($urls['default'] ?? ''))
            . '"'
            . $defaultCurrent
            . '><span class="public-gallery-control-icon" aria-hidden="true">↶</span>'
            . e(t('public.subgallery_sort.default', 'Default order'))
            . '</a>';
    echo
        '<a class="'
            . e($ascClass)
            . '" href="'
            . e((string) ($urls['asc'] ?? ''))
            . '"'
            . $ascCurrent
            . '><span class="public-gallery-control-icon" aria-hidden="true">↑</span>'
            . e(t('public.subgallery_sort.asc', 'Oldest first'))
            . '</a>';
    echo
        '<a class="'
            . e($descClass)
            . '" href="'
            . e((string) ($urls['desc'] ?? ''))
            . '"'
            . $descCurrent
            . '><span class="public-gallery-control-icon" aria-hidden="true">↓</span>'
            . e(t('public.subgallery_sort.desc', 'Newest first'))
            . '</a>';
    if (in_array($activeMode, ['asc', 'desc'], true)) {
        echo
            '<form class="public-subgallery-sort-save-form" method="post" action="'
                . e((string) ($viewModel['save_url'] ?? ''))
                . '">'
                . (string) ($viewModel['csrf_html'] ?? '');
        echo
            '<input type="hidden" name="gallery_id" value="'
                . (int) ($viewModel['gallery_id'] ?? 0)
                . '">';
        echo
            '<input type="hidden" name="sort_mode" value="'
                . e($activeMode)
                . '">';
        echo
            '<button class="button public-subgallery-sort-save-button" type="submit"><span class="public-gallery-control-icon" aria-hidden="true">✓</span>'
                . e(t('public.subgallery_sort.save_active', 'Save this order'))
                . '</button>';
        echo '</form>';
    }
    echo '</div>';
    echo '</div>';
}

/**
 * Render the compact admin-only reorder toolbar for one visible pagination page.
 *
 * @param array{kind?:string,endpoint?:string,gallery_id?:int,offset?:int,
 *     visible_count?:int,total_count?:int,csrf_token?:string} $viewModel
 *     Prepared visible-page identities, mutation destination and CSRF token.
 * @return void Emits the reorder toolbar for visible items.
 */
function view_render_public_page_reorder_toolbar(array $viewModel): void
{
    $kind = (string) ($viewModel['kind'] ?? 'photo');
    $label = $kind === 'gallery'
        ? t('public.reorder.subgalleries', 'subgalleries')
        : t('public.reorder.photos', 'photos');

    echo
        '<div class="public-reorder-toolbar public-gallery-control-bar" data-public-reorder-toolbar data-reorder-kind="'
            . e($kind)
            . '" data-reorder-url="'
            . e((string) ($viewModel['endpoint'] ?? ''))
            . '" data-gallery-id="'
            . (int) ($viewModel['gallery_id'] ?? 0)
            . '" data-visible-offset="'
            . (int) ($viewModel['offset'] ?? 0)
            . '" data-visible-count="'
            . (int) ($viewModel['visible_count'] ?? 0)
            . '" data-total-count="'
            . (int) ($viewModel['total_count'] ?? 0)
            . '" data-csrf-token="'
            . e((string) ($viewModel['csrf_token'] ?? ''))
            . '">';
    echo
        '<div class="public-gallery-control-heading"><span class="public-gallery-control-icon" aria-hidden="true">⠿</span><strong>'
            . e(t('public.reorder.move_visible_items', 'Move visible {items}', [
                'items' => $label,
            ]))
            . '</strong><details class="public-gallery-control-help" data-gallery-media-widget><summary aria-label="'
            . e(t('public.reorder.move_visible_items', 'Move visible {items}', [
                'items' => $label,
            ]))
            . '"><span aria-hidden="true">?</span></summary><div class="public-gallery-control-help-panel" data-gallery-media-panel>'
            . e(t(
                'public.reorder.visible_page_help',
                'Drag only the cards shown on this page. Other pagination pages are not touched.',
            ))
            . '</div></details></div>';
    echo
        '<span class="public-reorder-status" data-public-reorder-status aria-live="polite">'
            . e(t('public.reorder.ready', 'Ready.'))
            . '</span>';
    echo '</div>';
}

/**
 * Render the logged-in public gallery Picture manager toolbar.
 *
 * @param array{gallery_id?:int,has_visible_drop_targets?:bool,
 *     csrf_token?:string,move_url?:string,copy_url?:string,create_url?:string,
 *     delete_url?:string,download_url?:string,destination_picker_html?:string,
 *     new_parent_picker_html?:string} $viewModel Prepared endpoints, source
 *     identity, CSRF token, drop-target availability and escaped picker markup.
 * @return void Emits the Picture manager controls.
 */
function view_render_picture_manager_toolbar(array $viewModel): void
{
    $galleryId = (int) ($viewModel['gallery_id'] ?? 0);
    $dropHelp = !empty($viewModel['has_visible_drop_targets'])
        ? t(
            'picture_manager.drop_help_visible',
            'Drag selected photos onto a visible subgallery, or use the destination list below.',
        )
        : t(
            'picture_manager.drop_help_hidden',
            'No subgallery target is visible on this page. Use the destination list below.',
        );

    echo
        '<section class="picture-manager-toolbar is-picture-manager-collapsed" data-picture-manager data-source-gallery-id="'
            . $galleryId
            . '" data-csrf-token="'
            . e((string) ($viewModel['csrf_token'] ?? ''))
            . '" data-move-url="'
            . e((string) ($viewModel['move_url'] ?? ''))
            . '" data-copy-url="'
            . e((string) ($viewModel['copy_url'] ?? ''))
            . '" data-create-url="'
            . e((string) ($viewModel['create_url'] ?? ''))
            . '" data-delete-url="'
            . e((string) ($viewModel['delete_url'] ?? ''))
            . '" data-download-url="'
            . e((string) ($viewModel['download_url'] ?? ''))
            . '">';
    echo '<div class="picture-manager-summary">';
    echo
        '<button type="button" class="picture-manager-toggle" data-picture-manager-toggle aria-controls="picture-manager-panel-'
            . $galleryId
            . '" aria-expanded="false">';
    echo
        '<span class="picture-manager-toggle-icon" aria-hidden="true">▸</span>';
    echo
        '<span><strong>'
            . e(t('picture_manager.title', 'Picture manager'))
            . '</strong></span>';
    echo '</button>';
    echo
        '<span class="picture-manager-count" data-picture-manager-count aria-live="polite">'
            . e(t('picture_manager.none_selected', 'No items selected.'))
            . '</span>';
    echo
        '<button type="button" class="picture-manager-help-toggle" popovertarget="picture-manager-help-'
            . $galleryId
            . '" aria-label="'
            . e(t('picture_manager.hud_help', 'Selection and actions help'))
            . '" title="'
            . e(t('picture_manager.hud_help', 'Selection and actions help'))
            . '">?</button>';
    echo '</div>';

    // Native auto popovers provide click, keyboard, Escape and light dismissal.
    echo '<div class="picture-manager-help" popover id="picture-manager-help-'
        . $galleryId . '">';
    echo '<strong>'
        . e(t('picture_manager.hud_help', 'Selection and actions help'))
        . '</strong>';
    foreach ([
        t('picture_manager.help', 'Select photos or physical subgalleries with the checkmarks. Shift-click selects a range. Ctrl-click or Cmd-click toggles one item.'),
        $dropHelp,
        t('picture_manager.create_from_selection', 'Create physical gallery from selection'),
        t('picture_manager.move_copy_warning', 'Move removes photos from this gallery. Copy keeps the originals here and creates real file copies in the selected gallery.'),
        t('picture_manager.copy_warning', 'Creates a real file-based gallery under the selected parent. Photos and selected physical subgallery trees are copied; originals stay here.'),
        t('gallery_picker.help', 'Type to search, then press Enter or click a result to select it.'),
    ] as $hint) {
        echo '<p>' . e($hint) . '</p>';
    }
    echo '</div>';
    echo '<div class="picture-manager-panel" id="picture-manager-panel-'
        . $galleryId . '" data-picture-manager-panel>';
    echo '<div class="picture-manager-heading">';
    echo
        '<div class="picture-manager-actions" aria-label="'
            . e(t('picture_manager.selection_actions', 'Selection actions'))
            . '">';
    echo
        '<button type="button" class="button secondary picture-manager-icon-button" data-picture-manager-select-all title="'
            . e(t('picture_manager.select_all', 'Select all'))
            . '" aria-label="'
            . e(t('picture_manager.select_all', 'Select all'))
            . '"><span class="picture-manager-button-icon" aria-hidden="true">☑</span><span class="picture-manager-button-label">'
            . e(t('picture_manager.select_all_short', 'All'))
            . '</span></button>';
    echo
        '<button type="button" class="button secondary picture-manager-icon-button" data-picture-manager-clear title="'
            . e(t('picture_manager.clear_selection', 'Clear selection'))
            . '" aria-label="'
            . e(t('picture_manager.clear_selection', 'Clear selection'))
            . '" disabled><span class="picture-manager-button-icon" aria-hidden="true">×</span><span class="picture-manager-button-label">'
            . e(t('picture_manager.clear_selection_short', 'Clear'))
            . '</span></button>';
    echo
        '<button type="button" class="button secondary picture-manager-icon-button picture-manager-share-button" data-picture-manager-share title="'
            . e(t('picture_manager.share_selected', 'Share selected'))
            . '" aria-label="'
            . e(t('picture_manager.share_selected', 'Share selected'))
            . '" disabled><span class="picture-manager-button-icon" aria-hidden="true">↗</span><span class="picture-manager-button-label">'
            . e(t('picture_manager.share_short', 'Share'))
            . '</span></button>';
    echo
        '<button type="button" class="button secondary picture-manager-icon-button picture-manager-delete-button" data-picture-manager-delete title="'
            . e(t('picture_manager.delete_selected', 'Delete selected'))
            . '" aria-label="'
            . e(t('picture_manager.delete_selected', 'Delete selected'))
            . '" disabled><span class="picture-manager-button-icon" aria-hidden="true">⌫</span><span class="picture-manager-button-label">'
            . e(t('picture_manager.delete_short', 'Delete'))
            . '</span></button>';
    echo '</div>';
    echo '</div>';

    echo '<div class="picture-manager-action-grid">';
    echo '<div class="picture-manager-action-card">';
    echo
        '<label for="picture-manager-destination-'
            . $galleryId
            . '">'
            . e(t(
                'picture_manager.hud_destination',
                'Destination gallery',
            ))
            . '</label>';
    echo '<div class="picture-manager-inline-fields">';
    echo (string) ($viewModel['destination_picker_html'] ?? '');
    echo
        '<button type="button" class="button picture-manager-icon-button is-primary-action" data-picture-manager-move title="'
            . e(t('picture_manager.move_selected', 'Move selected'))
            . '" aria-label="'
            . e(t('picture_manager.move_selected', 'Move selected'))
            . '" disabled><span class="picture-manager-button-icon" aria-hidden="true">↪</span><span class="picture-manager-button-label">'
            . e(t('picture_manager.move_short', 'Move'))
            . '</span></button>';
    echo
        '<button type="button" class="button secondary picture-manager-icon-button" data-picture-manager-copy title="'
            . e(t('picture_manager.copy_selected', 'Copy selected'))
            . '" aria-label="'
            . e(t('picture_manager.copy_selected', 'Copy selected'))
            . '" disabled><span class="picture-manager-button-icon" aria-hidden="true">⧉</span><span class="picture-manager-button-label">'
            . e(t('picture_manager.copy_short', 'Copy'))
            . '</span></button>';
    echo '</div>';
    echo '</div>';

    echo '<details class="picture-manager-create-disclosure">';
    echo '<summary><span aria-hidden="true">＋</span> '
        . e(t('picture_manager.hud_create', 'New gallery from selection'))
        . '</summary>';
    echo '<div class="picture-manager-action-card">';
    echo
        '<label for="picture-manager-new-parent-'
            . $galleryId
            . '">'
            . e(t(
                'picture_manager.search_parent',
                'Search parent gallery',
            ))
            . '</label>';
    echo
        '<div class="picture-manager-inline-fields picture-manager-create-fields">';
    echo (string) ($viewModel['new_parent_picker_html'] ?? '');
    echo
        '<input id="picture-manager-new-title-'
            . $galleryId
            . '" type="text" data-picture-manager-new-title aria-label="'
            . e(t('picture_manager.new_gallery_title', 'New gallery title'))
            . '" placeholder="'
            . e(t('picture_manager.new_gallery_title', 'New gallery title'))
            . '">';
    echo
        '<input type="text" data-picture-manager-new-folder aria-label="'
            . e(t('picture_manager.optional_folder_name', 'Optional folder name'))
            . '" placeholder="'
            . e(t(
                'picture_manager.optional_folder_name',
                'Optional folder name',
            ))
            . '">';
    echo
        '<button type="button" class="button picture-manager-icon-button is-primary-action" data-picture-manager-create title="'
            . e(t('picture_manager.create_gallery', 'Create gallery'))
            . '" aria-label="'
            . e(t('picture_manager.create_gallery', 'Create gallery'))
            . '" disabled><span class="picture-manager-button-icon" aria-hidden="true">＋</span><span class="picture-manager-button-label">'
            . e(t('picture_manager.create_short', 'Create'))
            . '</span></button>';
    echo '</div>';
    echo '</div>';
    echo '</details>';
    echo '</div>';

    echo
        '<p class="picture-manager-status" data-picture-manager-status data-state="idle" aria-live="polite">'
            . e(t('picture_manager.ready', 'Ready.'))
            . '</p>';
    echo '</div>';
    echo '</section>';
}

/**
 * Render the administrator anonymous-preview control.
 *
 * @param array{is_preview?:bool,target_url?:string} $viewModel Prepared
 *     visitor-preview state and canonical entry or exit destination.
 * @return void Emits the anonymous-preview status and action link.
 */
function view_render_public_gallery_preview_toolbar(array $viewModel): void
{
    $isPreview = !empty($viewModel['is_preview']);
    echo '<div class="anonymous-preview-toolbar public-gallery-control-bar">';
    if ($isPreview) {
        echo
            '<div class="public-gallery-control-heading"><span class="public-gallery-control-icon" aria-hidden="true">◉</span><strong role="status">'
                . e(t(
                    'public.preview.active_title',
                    'Anonymous preview active.',
                ))
                . '</strong><details class="public-gallery-control-help" data-gallery-media-widget><summary aria-label="'
                . e(t('public.preview.active_title', 'Anonymous preview active.'))
                . '"><span aria-hidden="true">?</span></summary><div class="public-gallery-control-help-panel" data-gallery-media-panel>'
                . e(t(
                    'public.preview.active_message',
                    'Admin controls are hidden and visitor visibility rules are being applied.',
                ))
                . '</div></details></div>';
        echo
            '<a class="button" href="'
                . e((string) ($viewModel['target_url'] ?? ''))
                . '"><span class="public-gallery-control-icon" aria-hidden="true">↶</span>'
                . e(t('public.preview.exit', 'Exit preview'))
                . '</a>';
    } else {
        echo '<div class="public-gallery-control-heading">';
        echo
            '<a class="button secondary" href="'
                . e((string) ($viewModel['target_url'] ?? ''))
                . '"><span class="public-gallery-control-icon" aria-hidden="true">◉</span>'
                . e(t('public.preview.view_as_anonymous', 'View as anonymous'))
                . '</a>';
        echo
            '<details class="public-gallery-control-help" data-gallery-media-widget><summary aria-label="'
                . e(t('public.preview.view_as_anonymous', 'View as anonymous'))
                . '"><span aria-hidden="true">?</span></summary><div class="public-gallery-control-help-panel" data-gallery-media-panel>'
                . e(t(
                    'public.preview.help',
                    'Review this gallery without inline admin controls, admin navigation, hidden photos, or admin-only visibility.',
                ))
                . '</div></details></div>';
    }
    echo '</div>';
}

/**
 * Render the public gallery title and optional branding assets.
 *
 * @param array{title?:string,description?:string,
 *     description_links?:array<string,array{icon_id:?string,cached_url:?string}>,
 *     banner_url?:string,logo_url?:string,date_html?:string,pdf_html?:string,
 *     presentation_url?:string,presentation_music?:bool,
 *     presentation_csrf?:string} $viewModel Prepared
 *     branding, authorized attachments and optional presentation controls;
 *     omitted fields retain their empty presentation defaults.
 * @param bool $includeContent Whether to append description and documents; defaults to true for direct view consumers.
 * @return void Emits public gallery branding and conditional flight-plan controls.
 */
function view_render_public_gallery_branding_header(
    array $viewModel,
    bool $includeContent = true,
): void {
    $title = (string) ($viewModel['title'] ?? 'Gallery');
    $bannerUrl = (string) ($viewModel['banner_url'] ?? '');
    $logoUrl = (string) ($viewModel['logo_url'] ?? '');
    $titleBarClasses =
        'gallery-title-bar'
        . ($bannerUrl !== '' ? ' has-gallery-banner' : '')
        . ($logoUrl !== '' ? ' has-gallery-logo' : '');

    echo '<div class="' . e($titleBarClasses) . '">';
    if ($logoUrl !== '') {
        echo
            '<img class="gallery-branding-logo" src="'
                . e($logoUrl)
                . '" alt="" aria-hidden="true" decoding="async">';
    }
    if ($bannerUrl !== '') {
        echo
            '<h1 class="gallery-title gallery-title-with-banner"><span class="visually-hidden">'
                . e($title)
                . '</span><img class="gallery-branding-banner" src="'
                . e($bannerUrl)
                . '" alt="" aria-hidden="true" decoding="async"></h1>';
    } else {
        echo '<h1 class="gallery-title">' . e($title) . '</h1>';
    }
    echo '</div>';
    echo (string) ($viewModel['date_html'] ?? '');
    if ($includeContent) {
        view_render_public_gallery_description_documents($viewModel);
    }
}

/**
 * Render description text beside the compact document and playlist launchers.
 * @param array{description?:string,
 *     description_links?:array<string,array{icon_id:?string,cached_url:?string}>,
 *     pdf_html?:string,presentation_url?:string,
 *     presentation_music?:bool,presentation_csrf?:string} $viewModel Prepared description, link metadata,
 *     eligible presentation URL and
 *     protected attachment markup; absent fields render no content.
 * @return void Emit the hero row, standalone text or left-aligned applets.
 */
function view_render_public_gallery_description_documents(array $viewModel): void
{
    $description = (string) ($viewModel['description'] ?? '');
    // Keep rich-description dependencies with physical-gallery presentation.
    // Smart collections reuse only the shared prepared-HTML layout below.
    view_render_gallery_media_hero([
        'description_html' => trim($description) !== ''
            ? view_gallery_description_markdown_html(
                $description,
                (array) ($viewModel['description_links'] ?? []),
            ) : '',
        'media_html' => (string) ($viewModel['pdf_html'] ?? ''),
        'presentation_url' => (string) ($viewModel['presentation_url'] ?? ''),
        'presentation_music' => !empty($viewModel['presentation_music']),
        'presentation_csrf' => (string) ($viewModel['presentation_csrf'] ?? ''),
    ]);
}

/**
 * Render the optional per-gallery branding separator.
 *
 * @param array<string,mixed> $viewModel Controller-prepared separator state.
 */
function view_render_public_gallery_branding_separator(array $viewModel): void
{
    $separatorUrl = (string) ($viewModel['separator_url'] ?? '');
    if ($separatorUrl === '') {
        return;
    }
    echo
        '<div class="gallery-branding-separator" aria-hidden="true"><img src="'
            . e($separatorUrl)
            . '" alt="" decoding="async"></div>';
}

/**
 * Delegate public gallery navigation to the shared breadcrumb presentation.
 *
 * @param array<string,mixed> $viewModel Controller-prepared breadcrumb state.
 * @return void
 */
function view_render_public_gallery_breadcrumbs(array $viewModel): void
{
    view_render_breadcrumbs($viewModel);
}

/**
 * Render the public gallery access gate.
 *
 * @param array<string,mixed> $viewModel Controller-prepared access state.
 * @return void Emits the access form and completes the page layout.
 */
function view_render_gallery_access_gate(array $viewModel): void
{
    render_header((string) ($viewModel['title'] ?? ''));
    view_render_public_gallery_breadcrumbs(
        (array) ($viewModel['breadcrumbs'] ?? []),
    );
    echo
        '<section class="panel"><h1>'
            . e((string) ($viewModel['title'] ?? ''))
            . '</h1>';

    $error = (string) ($viewModel['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice">' . e($error) . '</div>';
    }

    $state = (string) ($viewModel['state'] ?? 'share_only');
    if ($state === 'nsfw') {
        echo
            '<p>'
                . e(t(
                    'gallery.access.nsfw_gate_intro',
                    'This gallery or photo is marked as restricted 18+ content. Anonymous visitors must confirm they are at least 18 before access is granted for this browser session. If you are an administrator planning to publish NSFW content, please verify that your hosting provider or web hosting terms allow it before enabling access.',
                ))
                . '</p>';
        echo
            '<form method="post" action="'
                . e((string) ($viewModel['access_url'] ?? ''))
                . '" class="form-grid">'
                . (string) ($viewModel['csrf_html'] ?? '');
        echo
            '<input type="hidden" name="gallery_id" value="'
                . (int) ($viewModel['gallery_id'] ?? 0)
                . '">';
        if ((int) ($viewModel['image_id'] ?? 0) > 0) {
            echo
                '<input type="hidden" name="image_id" value="'
                    . (int) $viewModel['image_id']
                    . '">';
        }
        echo
            '<input type="hidden" name="access_action" value="confirm_nsfw_age">';
        echo
            '<label><input type="checkbox" name="adult_confirmed" value="1" required> '
                . e(t(
                    'gallery.access.nsfw_confirm_label',
                    'I confirm that I am at least 18 years old.',
                ))
                . '</label>';
        echo
            '<button type="submit">'
                . e(t('common.continue', 'Continue'))
                . '</button></form>';
    } elseif ($state === 'password') {
        echo
            '<p>'
                . e(t(
                    'gallery.access.password_protected_duration',
                    'This gallery is password protected. Access closes after {minutes} minutes of session time.',
                    [
                        'minutes' => (string) (int) (
                            $viewModel['lifetime_minutes'] ?? 0
                        ),
                    ],
                ))
                . '</p>';
        echo
            '<form method="post" action="'
                . e((string) ($viewModel['access_url'] ?? ''))
                . '" class="form-grid">'
                . (string) ($viewModel['csrf_html'] ?? '');
        echo
            '<input type="hidden" name="gallery_id" value="'
                . (int) ($viewModel['gallery_id'] ?? 0)
                . '">';
        echo
            '<input type="hidden" name="requirement_id" value="'
                . (int) ($viewModel['requirement_id'] ?? 0)
                . '">';
        echo
            '<label>'
                . e(t('common.password', 'Password'))
                . '<input name="gallery_password" type="password" required autocomplete="current-password"></label>';
        echo
            '<button type="submit">'
                . e(t('gallery.access.open_gallery', 'Open gallery'))
                . '</button></form>';
    } else {
        echo
            '<p>'
                . e(t(
                    'gallery.access.share_link_only',
                    'This gallery is available only through its share link.',
                ))
                . '</p>';
    }
    echo '</section>';
    render_footer();
}
