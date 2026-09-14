<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/picture_manager_mixed_selection_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects mixed photo/physical-gallery Picture manager selection, deletion,
 *   and file-based create-from-selection behavior.
 *
 * Responsibilities:
 *   - Verify the delete route remains registered and feature-owned
 *   - Verify public physical gallery cards expose selection markup only through the physical-card renderer
 *   - Verify create-from-selection posts both photo and gallery IDs plus an explicit parent gallery
 *   - Verify physical subtree copies rebuild database state from copied folders instead of Smart Gallery rules
 *   - Verify move/copy remains photo-only when physical galleries are selected
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

/**
 * Assert one mixed-selection Picture manager contract.
 *
 * @param bool $condition Assertion result.
 * @param string $label Failure label.
 */
function picture_manager_mixed_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
$dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
$registry = (string) file_get_contents($root . '/app/services/feature_flags/registry.php');
$controller = (string) file_get_contents($root . '/app/controllers/picture_manager.php');
$service = (string) file_get_contents($root . '/app/services/picture_manager.php');
$controlsController = (string) file_get_contents($root . '/app/controllers/public_gallery_controls.php');
$controlsView = (string) file_get_contents($root . '/app/views/public_gallery_controls.php');
$cardsView = (string) file_get_contents($root . '/app/views/public_gallery_cards.php');
$pageController = (string) file_get_contents($root . '/app/controllers/public_gallery_page.php');
$pageView = (string) file_get_contents($root . '/app/views/public_gallery_pages.php');
$browser = (string) file_get_contents($root . '/public/assets/gallery-modules/picture-manager.js');
$entrypoint = (string) file_get_contents($root . '/public/assets/gallery.js');
$sidePanel = (string) file_get_contents($root . '/public/assets/gallery-modules/admin-side-panel.js');

picture_manager_mixed_assert(
    str_contains($dispatch, "'picture_manager_delete' => '\\\\Gallery\\\\Controllers\\\\cms_picture_manager_delete'")
        || str_contains($dispatch, "'picture_manager_delete' => '\\\\Gallery\\Controllers\\cms_picture_manager_delete'"),
    'Picture manager delete route must dispatch to cms_picture_manager_delete.'
);
picture_manager_mixed_assert(
    str_contains($registry, "'picture_manager_delete'") && str_contains($registry, "'picture_manager_create_gallery'"),
    'Picture manager feature ownership must include delete and create routes.'
);

picture_manager_mixed_assert(str_contains($controlsController, "'delete_url' => url_for('picture_manager_delete')"), 'Toolbar controller must prepare the delete endpoint URL.');
picture_manager_mixed_assert(str_contains($controlsController, "'new_parent_picker_html' => \$newParentPickerHtml"), 'Toolbar controller must prepare the selectable parent-gallery picker.');
picture_manager_mixed_assert(str_contains($controlsView, 'data-picture-manager-delete'), 'Toolbar view must render a mixed-selection delete button.');
picture_manager_mixed_assert(str_contains($controlsController, "'data-picture-manager-new-parent' => ''"), 'Toolbar parent picker contract must remain available to browser code.');
picture_manager_mixed_assert(str_contains($controlsView, 'Create physical gallery from selection'), 'Toolbar must describe create-from-selection as a physical gallery operation.');

picture_manager_mixed_assert(str_contains($cardsView, 'data-picture-manager-gallery'), 'Physical gallery cards must expose Picture manager selection metadata.');
picture_manager_mixed_assert(str_contains($cardsView, 'data-picture-manager-gallery-id'), 'Physical gallery cards must expose their gallery ID to browser selection code.');
picture_manager_mixed_assert(str_contains($cardsView, 'picture-manager-gallery-select-button'), 'Physical gallery cards must render a visible selection control.');
picture_manager_mixed_assert(!str_contains($cardsView, 'data-picture-manager-smart-gallery'), 'Smart Gallery cards must not join the physical mixed-selection contract.');

picture_manager_mixed_assert(str_contains($pageController, "\$pictureManagerEnabled && (\$images || \$children)"), 'Picture manager must render for galleries containing only physical subgalleries.');
picture_manager_mixed_assert(str_contains($pageController, "'picture_manager_toolbar_html' => \$pictureManagerToolbarHtml"), 'Detail controller must place the toolbar in the page-level view model.');
picture_manager_mixed_assert(str_contains($pageView, "\$viewModel['picture_manager_toolbar_html']"), 'Detail page view must render the page-level Picture manager toolbar.');

picture_manager_mixed_assert(str_contains($controller, 'function cms_picture_manager_delete(): void'), 'Mixed-selection delete endpoint must exist.');
picture_manager_mixed_assert(str_contains($controller, 'picture_manager_owned_galleries_for_selection'), 'Delete/create endpoints must validate selected galleries against the source gallery.');
picture_manager_mixed_assert(str_contains($controller, 'move_gallery_subtrees_to_trash'), 'Physical gallery deletion must honor the established gallery trash path.');
picture_manager_mixed_assert(str_contains($controller, 'delete_gallery_images'), 'Photo deletion must reuse the filesystem-backed image deletion service.');
picture_manager_mixed_assert(str_contains($controller, "\$_POST['new_gallery_parent_id']"), 'Create-from-selection must accept an explicit parent gallery.');
picture_manager_mixed_assert(str_contains($controller, 'picture_manager_copy_gallery_subtrees'), 'Create-from-selection must invoke physical subtree copying for selected galleries.');

picture_manager_mixed_assert(str_contains($service, 'function picture_manager_normalize_gallery_ids('), 'Picture manager service must normalize physical gallery IDs.');
picture_manager_mixed_assert(str_contains($service, 'function picture_manager_owned_galleries_for_selection('), 'Picture manager service must restrict selected galleries to direct physical children.');
picture_manager_mixed_assert(str_contains($service, 'function picture_manager_assert_destination_outside_gallery_selection('), 'Physical subtree copy must guard against recursive self-copy destinations.');
picture_manager_mixed_assert(str_contains($service, 'function picture_manager_copy_gallery_subtrees('), 'Picture manager service must own physical subtree cloning.');
picture_manager_mixed_assert(str_contains($service, 'gallery_trash_copy_directory('), 'Physical subtree clone must reuse the established safe recursive directory copier.');
picture_manager_mixed_assert(str_contains($service, "if (!@mkdir(\$targetRootAbs, 0775, false))"), 'Physical subtree clone must reserve each target root before recursive copying.');
picture_manager_mixed_assert(strpos($service, "\$rawCopiedRoots[] = \$targetRootAbs;") < strpos($service, "gallery_trash_copy_directory((string) \$plan['source_root_abs'], \$targetRootAbs);"), 'Rollback tracking must begin before the recursive copier can partially write a target tree.');
picture_manager_mixed_assert(str_contains($service, 'create_gallery_row_for_folder(') && str_contains($service, 'scan_gallery_images('), 'Copied physical folders must be re-indexed from filesystem state.');
picture_manager_mixed_assert(!str_contains($service, 'create_smart_gallery') && !str_contains($controller, 'create_smart_gallery'), 'Create-from-selection must not create Smart Gallery rules.');

picture_manager_mixed_assert(str_contains($browser, "'[data-picture-manager-image], [data-picture-manager-gallery]'"), 'Browser selection must include photos and physical gallery cards.');
picture_manager_mixed_assert(str_contains($browser, "formData.append('gallery_ids[]', galleryId)"), 'Browser requests must submit selected physical gallery IDs.');
picture_manager_mixed_assert(str_contains($browser, "formData.append('new_gallery_parent_id', parentGalleryId)"), 'Browser create requests must submit the chosen parent gallery ID.');
picture_manager_mixed_assert(str_contains($browser, 'async function deleteSelectedItems()'), 'Browser module must implement mixed-selection deletion.');
picture_manager_mixed_assert(str_contains($browser, 'hasSelectedGalleries') && str_contains($browser, 'move_copy_photos_only'), 'Move/copy controls must refuse mixed physical-gallery selections.');
picture_manager_mixed_assert(str_contains($browser, 'card.draggable = !isGallery'), 'Physical gallery cards must not become native drag sources.');

$cacheKey = '20260914-picture-manager-mixed-v1';
picture_manager_mixed_assert(str_contains($entrypoint, $cacheKey), 'Main browser entrypoint must bust the Picture manager module cache.');
picture_manager_mixed_assert(str_contains($sidePanel, $cacheKey), 'Admin side-panel import must use the same Picture manager cache revision.');

fwrite(STDOUT, "Picture manager mixed-selection checks passed.\n");
