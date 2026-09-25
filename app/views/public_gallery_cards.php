<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/public_gallery_cards.php
 * Module Type: View
 *
 * Purpose:
 *   Renders public physical/Smart Gallery cards and compact administrator card
 *   controls from controller-prepared presentation state.
 *
 * Responsibilities:
 *   - Render physical gallery cards without performing domain lookups
 *   - Render Smart Gallery cards from prepared summary/thumbnail state
 *   - Render public-page Admin add/edit/delete/visibility controls
 *   - Consume prepared URLs, CSRF fragments, and trusted presentation fragments
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
 *   - Translation and escaping are presentation helpers.
 *   - The controller owns feature policy, gallery/image policy, persistence,
 *     thumbnail selection, URL generation, and mutation configuration.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Services\t;

/**
 * Render one physical public gallery card.
 *
 * @param array<string,mixed> $viewModel Controller-prepared gallery-card state.
 */
function view_render_public_gallery_card(array $viewModel): void
{
    $galleryId = (int) ($viewModel['gallery_id'] ?? 0);
    $title = (string) ($viewModel['title'] ?? '');
    $url = (string) ($viewModel['url'] ?? '');
    $visibility = (string) ($viewModel['visibility'] ?? 'unpublished');
    $descriptionLayout = (string) ($viewModel['description_layout'] ?? 'vertical');
    $isProtected = !empty($viewModel['is_protected']);
    $showReorderHandle = !empty($viewModel['show_reorder_handle']);
    $showUnpublishedMarker = !empty($viewModel['show_unpublished_marker']);
    $showCountBadge = !empty($viewModel['show_count_badge']);
    $pictureManagerEnabled = !empty($viewModel['picture_manager_enabled']);
    $branchImageCount = max(0, (int) ($viewModel['branch_image_count'] ?? 0));
    $coverAsset = (string) ($viewModel['cover_asset'] ?? '');
    $coverPictureHtml = (string) ($viewModel['cover_picture_html'] ?? '');
    $collagePictureHtml = array_values(array_filter((array) ($viewModel['collage_picture_html'] ?? []), static fn ($html): bool => is_string($html) && $html !== ''));
    $coverLoadingAttributes = (string) ($viewModel['cover_loading_attributes'] ?? '');
    $descriptionPreview = view_gallery_description_markdown_excerpt((string) ($viewModel['description'] ?? ''));
    $descriptionHtml = view_gallery_description_markdown_html($descriptionPreview, (array) ($viewModel['description_links'] ?? []));

    $galleryCardClass = 'gallery-card is-gallery-description-' . $descriptionLayout
        . ($isProtected ? ' is-protected-gallery' : '')
        . ($showReorderHandle ? ' has-public-reorder-handle' : '')
        . ($pictureManagerEnabled ? ' has-picture-manager-select' : '')
        . ($showUnpublishedMarker ? ' is-admin-unpublished-gallery' : '');

    echo '<article class="' . e($galleryCardClass) . '" data-gallery-id="' . $galleryId . '"' . ($pictureManagerEnabled ? ' data-picture-manager-gallery data-picture-manager-gallery-id="' . $galleryId . '" aria-selected="false"' : '') . ' data-gallery-visibility="' . e($visibility) . '" data-gallery-updated-at="' . e((string) ($viewModel['updated_at'] ?? '')) . '" data-public-gallery-order-item data-public-order-id="' . $galleryId . '">';
    if ($showUnpublishedMarker) {
        echo '<span class="admin-gallery-visibility-marker" title="' . e(t('gallery.card.unpublished_admin_hint', 'Only logged-in admins can see this gallery in listings.')) . '">' . e(t('gallery.visibility.unpublished', 'unpublished')) . '</span>';
    }
    if ($showReorderHandle) {
        echo '<button type="button" class="public-reorder-handle public-gallery-reorder-handle" data-public-reorder-handle aria-label="' . e(t('gallery.reorder.drag_subgallery_aria', 'Drag subgallery to reorder visible subgalleries')) . '" title="' . e(t('gallery.reorder.drag_subgallery_title', 'Drag to reorder this visible subgallery')) . '"><span aria-hidden="true">↕</span><span>' . e(t('gallery.reorder.move_gallery', 'Move gallery')) . '</span></button>';
    }

    if ($pictureManagerEnabled) {
        echo '<button type="button" class="picture-manager-select-button picture-manager-gallery-select-button" data-picture-manager-select aria-pressed="false" aria-label="' . e(t('picture_manager.select_gallery', 'Select gallery')) . '" title="' . e(t('picture_manager.select_gallery', 'Select gallery')) . '"><span aria-hidden="true">✓</span><span class="visually-hidden">' . e(t('picture_manager.select_gallery', 'Select gallery')) . '</span></button>';
    }

    echo '<a class="gallery-card-media" href="' . e($url) . '" aria-label="' . e(t('gallery.card.open_gallery', 'Open gallery {title}', ['title' => $title])) . '">';
    if ($showCountBadge) {
        echo '<span class="subgallery-stack-badge" aria-label="' . e(t('gallery.card.subgallery_image_count', 'Subgallery containing {count} images', ['count' => $branchImageCount])) . '"><span class="subgallery-stack-icon" aria-hidden="true"><span></span><span></span><span></span></span><span class="subgallery-stack-count">' . $branchImageCount . '</span></span>';
    }
    if ($isProtected) {
        echo '<span class="gallery-collage gallery-locked-preview" aria-hidden="true">' . e(t('gallery.card.protected', 'Protected')) . '</span>';
    } elseif ($coverAsset !== '') {
        echo '<img decoding="async" ' . $coverLoadingAttributes . ' src="' . e($coverAsset) . '" alt="">';
    } elseif ($coverPictureHtml !== '') {
        echo $coverPictureHtml;
    } elseif ($collagePictureHtml !== []) {
        echo '<span class="gallery-collage collage-count-' . count($collagePictureHtml) . '">';
        foreach ($collagePictureHtml as $pictureHtml) {
            echo $pictureHtml;
        }
        echo '</span>';
    }
    echo '</a>';

    echo '<div class="gallery-card-body"><h2><a class="gallery-card-title-link" href="' . e($url) . '">' . e($title) . '</a></h2>';
    $horizontalMetaHtml = (string) ($viewModel['horizontal_meta_html'] ?? '');
    if ($descriptionLayout === 'horizontal' && !$isProtected && $horizontalMetaHtml !== '') {
        echo '<div class="gallery-card-meta-row">' . $horizontalMetaHtml . '</div>';
    } elseif (!$isProtected) {
        echo (string) ($viewModel['date_html'] ?? '');
    }
    if ($descriptionHtml !== '') {
        echo '<div class="gallery-card-description gallery-card-description-rich">' . $descriptionHtml . '</div>';
    }
    if ($isProtected) {
        echo '<p class="muted gallery-card-count">' . e(t('gallery.card.protected_gallery', 'Protected gallery')) . '</p>';
    } else {
        if ($showCountBadge) {
            echo '<p class="muted gallery-card-count gallery-card-count-visual-hidden">' . e(t('gallery.image_count', '{count} images', ['count' => $branchImageCount])) . '</p>';
        }
        if ($descriptionLayout !== 'horizontal') {
            echo (string) ($viewModel['tag_list_html'] ?? '');
        }
    }
    echo '</div>';
    echo (string) ($viewModel['admin_controls_html'] ?? '');
    echo '</article>';
}

/**
 * Render one placed Smart Gallery card.
 *
 * @param array<string,mixed> $viewModel Controller-prepared Smart Gallery state.
 */
function view_render_public_smart_gallery_card(array $viewModel): void
{
    $smartGalleryId = (int) ($viewModel['gallery_id'] ?? 0);
    $url = (string) ($viewModel['url'] ?? '');
    $title = (string) ($viewModel['title'] ?? '');
    $count = max(0, (int) ($viewModel['count'] ?? 0));
    $placementAttributes = '';
    if (!empty($viewModel['has_placement'])) {
        $placementAttributes = ' data-smart-gallery-placement="' . e((string) ($viewModel['placement'] ?? '')) . '"';
        $placementAttributes .= ' data-smart-gallery-placement-order="' . max(0, (int) ($viewModel['placement_order'] ?? 0)) . '"';
    }

    echo '<article class="gallery-card smart-gallery-card is-gallery-description-' . e((string) ($viewModel['card_layout'] ?? 'vertical')) . '" data-smart-gallery-id="' . $smartGalleryId . '"' . $placementAttributes . '>';
    echo '<a class="gallery-card-media" href="' . e($url) . '" aria-label="' . e(t('smart_gallery.open_named', 'Open Smart Gallery {title}', ['title' => $title])) . '">';
    echo '<span class="subgallery-stack-badge" aria-label="' . e(t('gallery.card.subgallery_image_count', 'Subgallery containing {count} images', ['count' => $count])) . '"><span class="subgallery-stack-icon" aria-hidden="true"><span></span><span></span><span></span></span><span class="subgallery-stack-count">' . $count . '</span></span>';
    $coverPictureHtml = (string) ($viewModel['cover_picture_html'] ?? '');
    if ($coverPictureHtml !== '') {
        echo $coverPictureHtml;
    } else {
        echo '<span class="gallery-collage gallery-empty-preview" aria-hidden="true">' . e(t('smart_gallery.empty_card', 'Smart Gallery')) . '</span>';
    }
    echo '</a><div class="gallery-card-body"><p class="admin-kicker">' . e(t('smart_gallery.public_kicker', 'Smart Gallery')) . '</p><h2><a class="gallery-card-title-link" href="' . e($url) . '">' . e($title) . '</a></h2>';
    $description = trim((string) ($viewModel['description'] ?? ''));
    if ($description !== '') {
        echo '<p class="gallery-card-description">' . e($description) . '</p>';
    }
    echo '<p class="muted gallery-card-count">' . e(t('gallery.image_count', '{count} images', ['count' => $count])) . '</p></div></article>';
}

/**
 * Render the public-page child-gallery creation entry point.
 *
 * @param array<string,mixed> $viewModel Controller-prepared add-child state.
 * @return void Emit the prepared creation link.
 */
function view_render_public_gallery_admin_add_child_link(array $viewModel): void
{
    $placement = (string) ($viewModel['placement'] ?? 'card');
    $title = (string) ($viewModel['title'] ?? '');
    $label = $placement === 'hero' ? t('gallery.add_here', 'Add gallery here') : t('gallery.add_inside', 'Add gallery inside {title}', ['title' => $title]);
    $class = $placement === 'hero' ? 'public-admin-add-gallery-button public-admin-add-gallery-button-hero hero-icon-button' : 'public-admin-add-gallery-button public-admin-add-gallery-button-card';
    echo '<a class="' . e($class) . '" href="' . e((string) ($viewModel['url'] ?? '')) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="create" data-admin-side-panel-kicker="' . e(t('gallery.workflow', 'Gallery workflow')) . '" data-admin-side-panel-title="' . e(t('gallery.add_here', 'Add gallery here')) . '" data-gallery-side-panel-url="' . e((string) ($viewModel['panel_url'] ?? '')) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">+</span><span class="visually-hidden">' . e($label) . '</span></a>';
}

/**
 * Render the public-page gallery edit entry point.
 *
 * @param array<string,mixed> $viewModel Controller-prepared gallery-edit state.
 */
function view_render_public_gallery_admin_edit_link(array $viewModel): void
{
    $placement = (string) ($viewModel['placement'] ?? 'card');
    $title = (string) ($viewModel['title'] ?? '');
    $label = $placement === 'hero' ? t('gallery.edit_current', 'Edit current gallery') : t('gallery.edit_named', 'Edit gallery {title}', ['title' => $title]);
    $class = $placement === 'hero' ? 'public-admin-edit-button public-admin-edit-button-hero' : 'public-admin-edit-button public-admin-edit-button-card';
    echo '<a class="' . e($class) . '" href="' . e((string) ($viewModel['url'] ?? '')) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="gallery-edit" data-admin-side-panel-kicker="' . e(t('gallery.editor', 'Gallery editor')) . '" data-admin-side-panel-title="' . e(t('gallery.edit', 'Edit gallery')) . '" data-gallery-side-panel-url="' . e((string) ($viewModel['panel_url'] ?? '')) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#9998;</span><span class="visually-hidden">' . e($label) . '</span></a>';
}

/**
 * Render the public-page gallery delete/trash form.
 *
 * @param array<string,mixed> $viewModel Controller-prepared gallery-delete state.
 */
function view_render_public_gallery_admin_delete_form(array $viewModel): void
{
    $placement = (string) ($viewModel['placement'] ?? 'card');
    $name = (string) ($viewModel['name'] ?? 'gallery');
    $trashEnabled = !empty($viewModel['trash_enabled']);
    $label = $trashEnabled
        ? ($placement === 'hero' ? t('gallery.trash_current', 'Move current gallery to trash') : t('gallery.trash_named', 'Move gallery {name} to trash', ['name' => $name]))
        : ($placement === 'hero' ? t('gallery.remove_current', 'Remove current gallery from CMS') : t('gallery.remove_named', 'Remove gallery {name} from CMS', ['name' => $name]));
    $class = $placement === 'hero' ? 'public-admin-delete-form public-admin-delete-form-hero' : 'public-admin-delete-form public-admin-delete-form-card';

    echo '<form class="' . e($class) . '" method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" data-public-admin-card-action data-public-admin-delete-form data-public-admin-delete-name="' . e($name) . '" data-public-admin-delete-kind="gallery" data-public-admin-delete-mode="' . ($trashEnabled ? 'trash' : 'permanent') . '" data-public-admin-delete-auto-purge-enabled="' . (!empty($viewModel['auto_purge_enabled']) ? '1' : '0') . '" data-public-admin-delete-retention-days="' . (int) ($viewModel['retention_days'] ?? 0) . '">';
    echo (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="gallery_id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<button type="submit" class="public-admin-card-action-button public-admin-delete-button" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#128465;</span><span class="visually-hidden">' . e($label) . '</span></button>';
    echo '</form>';
}

/**
 * Render the public-page photo edit entry point.
 *
 * @param array<string,mixed> $viewModel Controller-prepared photo-edit state.
 */
function view_render_public_image_admin_edit_link(array $viewModel): void
{
    $name = (string) ($viewModel['name'] ?? 'photo');
    $label = t('gallery.edit_photo_named', 'Edit photo {name}', ['name' => $name]);
    echo '<a class="public-admin-edit-button public-admin-edit-button-card public-admin-edit-button-photo" href="' . e((string) ($viewModel['url'] ?? '')) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="image-edit" data-admin-side-panel-kicker="' . e(t('gallery.photo_editor', 'Photo editor')) . '" data-admin-side-panel-title="' . e(t('gallery.edit_photo', 'Edit photo')) . '" data-gallery-side-panel-url="' . e((string) ($viewModel['panel_url'] ?? '')) . '" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#9998;</span><span class="visually-hidden">' . e($label) . '</span></a>';
}

/**
 * Render the public-page photo delete form.
 *
 * @param array<string,mixed> $viewModel Controller-prepared photo-delete state.
 */
function view_render_public_image_admin_delete_form(array $viewModel): void
{
    $name = (string) ($viewModel['name'] ?? 'photo');
    $label = t('gallery.remove_photo_named', 'Remove photo {name} from CMS', ['name' => $name]);
    echo '<form class="public-admin-delete-form public-admin-delete-form-card public-admin-delete-form-photo" method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" data-public-admin-card-action data-public-admin-delete-form data-public-admin-delete-name="' . e($name) . '" data-public-admin-delete-kind="photo">';
    echo (string) ($viewModel['csrf_html'] ?? '');
    echo '<input type="hidden" name="image_id" value="' . (int) ($viewModel['image_id'] ?? 0) . '">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<button type="submit" class="public-admin-card-action-button public-admin-delete-button" aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">&#128465;</span><span class="visually-hidden">' . e($label) . '</span></button>';
    echo '</form>';
}

/**
 * Render the shared three-state visibility menu for a gallery or image card.
 *
 * @param array<string,mixed> $viewModel Controller-prepared visibility state.
 */
function view_render_public_admin_visibility_menu(array $viewModel): void
{
    $entityId = (int) ($viewModel['entity_id'] ?? 0);
    if ($entityId <= 0) {
        return;
    }
    $kind = (string) ($viewModel['kind'] ?? 'image');
    $name = (string) ($viewModel['name'] ?? '');
    $visibility = (string) ($viewModel['visibility'] ?? 'unpublished');
    $visibility = in_array($visibility, ['public', 'unpublished', 'private'], true) ? $visibility : 'unpublished';
    $idField = $kind === 'gallery' ? 'gallery_id' : 'image_id';
    $label = t('gallery.visibility.change_named', 'Change visibility for {name}', ['name' => $name]);
    $options = [
        'public' => ['public-admin-visibility-icon-public', t('gallery.visibility.public', 'Published')],
        'unpublished' => ['public-admin-visibility-icon-unpublished', t('gallery.visibility.unpublished', 'Unpublished')],
        'private' => ['public-admin-visibility-icon-private', t('gallery.visibility.private', 'Private')],
    ];

    echo '<details class="public-admin-visibility-menu public-admin-visibility-menu-card" data-public-admin-card-action data-public-admin-visibility-menu>';
    echo '<summary class="public-admin-card-action-button public-admin-visibility-trigger" aria-label="' . e($label) . '" title="' . e($label) . '"><span class="public-admin-visibility-icon ' . e($options[$visibility][0]) . '" aria-hidden="true"><span class="public-admin-visibility-eye">&#128065;</span></span><span class="visually-hidden">' . e($label) . '</span></summary>';
    echo '<div class="public-admin-visibility-options" role="group" aria-label="' . e($label) . '">';
    foreach ($options as $value => $option) {
        echo '<form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" data-public-admin-visibility-form data-public-admin-visibility-kind="' . e($kind) . '">' . (string) ($viewModel['csrf_html'] ?? '');
        echo '<input type="hidden" name="' . e($idField) . '" value="' . $entityId . '"><input type="hidden" name="action" value="' . e($value) . '">';
        echo '<button type="submit" class="public-admin-visibility-option' . ($visibility === $value ? ' is-current' : '') . '" aria-pressed="' . ($visibility === $value ? 'true' : 'false') . '" title="' . e((string) $option[1]) . '"><span class="public-admin-visibility-icon ' . e((string) $option[0]) . '" aria-hidden="true"><span class="public-admin-visibility-eye">&#128065;</span></span><span class="visually-hidden">' . e((string) $option[1]) . '</span></button></form>';
    }
    echo '</div></details>';
}
