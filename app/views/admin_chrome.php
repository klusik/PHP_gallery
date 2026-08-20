<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_chrome.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders reusable admin navigation and tab chrome.
 *
 * Responsibilities:
 *   - Keep admin navigation presentation out of generic helpers
 *   - Render tab lists and tab panels from caller-provided view models
 *   - Preserve existing admin HTML structure for CSS and JS modules
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
 *   2026-05-24
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Controllers\thumbnail_maintenance_notice_is_dismissed;
use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\url_for;
use function Gallery\Services\application_update_nav_label;
use function Gallery\Services\application_update_pending;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\t;

/**
 * Handle view admin menu structure.
 *
 * Used by server-rendered view helpers.
 *
 * @return array Structured result data for the caller.
 */
function view_admin_menu_structure(): array
{
    $updatePending = function_exists('Gallery\\Services\\application_update_pending') ? application_update_pending() : false;
    $updateLabel = function_exists('Gallery\\Services\\application_update_nav_label') ? application_update_nav_label($updatePending) : t('admin.menu.updates', 'Updates');
    return [
        [
            'label' => t('admin.menu.dashboard', 'Dashboard'),
            'items' => [
                ['label' => t('admin.menu.overview', 'Overview'), 'page' => 'admin', 'url' => url_for('admin')],
                ['label' => t('admin.menu.settings', 'Settings'), 'page' => 'admin_settings', 'url' => url_for('admin_settings')],
            ],
        ],
        [
            'label' => t('admin.menu.galleries', 'Galleries'),
            'items' => [
                ['label' => t('admin.menu.all_galleries', 'All galleries'), 'page' => 'admin', 'url' => url_for('admin') . '#admin-tab-galleries'],
                ['label' => t('admin.menu.create_gallery', 'Create gallery'), 'page' => 'admin_new_gallery', 'url' => url_for('admin_new_gallery')],
                ['label' => t('admin.menu.smart_galleries', 'Smart Galleries'), 'page' => 'admin_smart_galleries', 'url' => url_for('admin_smart_galleries')],
                ['label' => t('admin.menu.upload_photos', 'Upload photos'), 'page' => 'admin_upload', 'url' => url_for('admin_upload')],
                ['label' => t('admin.menu.upload_settings', 'Upload settings'), 'page' => 'admin_upload_settings', 'url' => url_for('admin_upload_settings')],
                ['label' => t('admin.menu.mobile_uploads', 'Mobile uploads'), 'page' => 'admin_mobile_uploads', 'url' => url_for('admin_mobile_uploads'), 'feature' => 'mobile_webdav'],
                ['label' => t('admin.menu.media_renamer', 'Media renamer'), 'page' => 'admin_media_renamer', 'url' => url_for('admin_media_renamer'), 'feature' => 'media_renamer'],
                ['label' => t('admin.menu.api_manager', 'API manager'), 'page' => 'admin_api_manager', 'url' => url_for('admin_api_manager'), 'feature' => 'upload_api'],
                ['label' => t('admin.menu.edit_tags', 'Edit tags'), 'page' => 'admin_tags', 'url' => url_for('admin_tags')],
            ],
        ],
        [
            'label' => t('admin.menu.appearance', 'Appearance'),
            'items' => [
                ['label' => t('admin.menu.theme', 'Theme'), 'page' => 'admin_theme', 'url' => url_for('admin_theme')],
                ['label' => t('admin.menu.features', 'Features'), 'page' => 'admin_features', 'url' => url_for('admin_features')],
            ],
        ],
        [
            'label' => t('admin.menu.maintenance', 'Maintenance'),
            'items' => [
                ['label' => t('admin.menu.logs', 'Logs'), 'page' => 'admin_logs', 'url' => url_for('admin_logs')],
                ['label' => t('admin.menu.telemetry', 'Telemetry'), 'page' => 'admin_telemetry', 'url' => url_for('admin_telemetry'), 'feature' => 'telemetry'],
                ['label' => t('admin.menu.gallery_report', 'Complete report'), 'page' => 'admin_gallery_report', 'url' => url_for('admin_gallery_report')],
                ['label' => t('admin.menu.integrity', 'Integrity'), 'page' => 'admin_integrity', 'url' => url_for('admin_integrity')],
                ['label' => t('admin.menu.navdata', 'Navigation data'), 'page' => 'admin_navdata', 'url' => url_for('admin_navdata'), 'feature' => 'navigation_data'],
                ['label' => $updateLabel, 'page' => 'admin_update', 'url' => url_for('admin_update'), 'highlight' => $updatePending],
            ],
        ],
        [
            'label' => t('admin.menu.account', 'Account'),
            'items' => [
                ['label' => t('admin.menu.profile', 'Profile'), 'page' => 'admin_account', 'url' => url_for('admin_account')],
                ['label' => t('viewer.admin.invites.menu', 'Viewer accounts'), 'page' => 'admin_viewer_invitations', 'url' => url_for('admin_viewer_invitations'), 'feature' => 'viewer_accounts'],
                ['label' => t('admin.menu.logout', 'Logout'), 'page' => 'admin_logout', 'url' => url_for('admin_logout')],
            ],
        ],
    ];
}

/**
 * Handle view admin menu item is active.
 *
 * Used by server-rendered view helpers.
 *
 * @param array $item Item value.
 * @param string $currentPage Current page value.
 * @return bool True when the condition matches.
 */
function view_admin_menu_item_is_active(array $item, string $currentPage): bool
{
    $itemPage = (string) ($item['page'] ?? '');
    if ($itemPage === '') {
        return false;
    }
    if ($currentPage === $itemPage) {
        if ($itemPage === 'admin') {
            return !str_contains((string) ($item['url'] ?? ''), '#');
        }
        return true;
    }
    if ($itemPage === 'admin' && in_array($currentPage, ['admin_edit_gallery', 'admin_edit_image'], true)) {
        return str_contains((string) ($item['url'] ?? ''), '#admin-tab-galleries');
    }
    return false;
}

/**
 * Handle view render admin tabs.
 *
 * Used by server-rendered view helpers.
 *
 * @param array $tabs Tabs value.
 * @param string $activeId Active id identifier.
 */
function view_render_admin_tabs(array $tabs, string $activeId = ''): void
{
    $resolvedActiveId = $activeId;
    if ($resolvedActiveId === '') {
        foreach ($tabs as $tab) {
            if (!empty($tab['active']) && !empty($tab['id'])) {
                $resolvedActiveId = (string) $tab['id'];
                break;
            }
        }
    }
    if ($resolvedActiveId === '' && isset($tabs[0]['id'])) {
        $resolvedActiveId = (string) $tabs[0]['id'];
    }

    echo '<nav class="admin-tabs" data-admin-tabs aria-label="' . e(t('admin.tabs.aria_sections', 'Admin sections')) . '">';
    echo '<div class="admin-tab-list" role="tablist">';
    foreach ($tabs as $tab) {
        $tabId = trim((string) ($tab['id'] ?? ''));
        if ($tabId === '') {
            continue;
        }
        $tabLabel = (string) ($tab['label'] ?? $tabId);
        $tabHref = (string) ($tab['href'] ?? ('#' . $tabId));
        $isActive = $tabId === $resolvedActiveId;
        $controlId = $tabId . '-control';
        echo '<a class="admin-tab' . ($isActive ? ' is-active' : '') . '" id="' . e($controlId) . '" href="' . e($tabHref) . '" role="tab" aria-controls="' . e($tabId) . '" aria-selected="' . ($isActive ? 'true' : 'false') . '" tabindex="' . ($isActive ? '0' : '-1') . '" data-admin-tab-target="' . e($tabId) . '">';
        echo '<span>' . e($tabLabel) . '</span>';
        if (array_key_exists('badge', $tab) && $tab['badge'] !== null && $tab['badge'] !== '') {
            echo '<span class="admin-tab-badge">' . e((string) $tab['badge']) . '</span>';
        }
        echo '</a>';
    }
    echo '</div></nav>';
}

/**
 * Handle view render admin tab panel.
 *
 * Used by server-rendered view helpers.
 *
 * @param string $id Identifier value.
 * @param string $contentHtml Content html HTML markup.
 * @param bool $active Active value.
 */
function view_render_admin_tab_panel(string $id, string $contentHtml, bool $active = false): void
{
    $controlId = $id . '-control';
    echo '<section class="panel admin-tab-panel' . ($active ? ' is-active' : '') . '" id="' . e($id) . '" role="tabpanel" aria-labelledby="' . e($controlId) . '" data-admin-tab-panel>';
    echo $contentHtml;
    echo '</section>';
}



/**
 * Render one reusable admin subtab control row.
 *
 * Subtabs are intentionally separate from the top-level Admin tabs. They do not
 * own the browser URL hash and can therefore be nested inside normal tab panels
 * without fighting the parent tab state. Callers should keep ids unique inside
 * the page and render matching panels with view_render_admin_subtab_panel().
 *
 * @param array $tabs Tabs value.
 * @param string $activeId Preferred active subtab id. The first subtab is used when empty.
 * @param string $ariaLabel Accessible label for this subtab group.
 */
function view_render_admin_subtabs(array $tabs, string $activeId = '', string $ariaLabel = ''): void
{
    $resolvedActiveId = $activeId;
    if ($resolvedActiveId === '') {
        foreach ($tabs as $tab) {
            if (!empty($tab['active']) && !empty($tab['id'])) {
                $resolvedActiveId = (string) $tab['id'];
                break;
            }
        }
    }
    if ($resolvedActiveId === '' && isset($tabs[0]['id'])) {
        $resolvedActiveId = (string) $tabs[0]['id'];
    }

    $resolvedAriaLabel = $ariaLabel !== '' ? $ariaLabel : t('admin.subtabs.aria_sections', 'Admin subsection tabs');
    echo '<nav class="admin-subtabs" data-admin-subtabs aria-label="' . e($resolvedAriaLabel) . '">';
    echo '<div class="admin-subtab-list" role="tablist">';
    foreach ($tabs as $tab) {
        $tabId = trim((string) ($tab['id'] ?? ''));
        if ($tabId === '') {
            continue;
        }
        $tabLabel = (string) ($tab['label'] ?? $tabId);
        $isActive = $tabId === $resolvedActiveId;
        $controlId = $tabId . '-control';
        echo '<button type="button" class="admin-subtab' . ($isActive ? ' is-active' : '') . '" id="' . e($controlId) . '" role="tab" aria-controls="' . e($tabId) . '" aria-selected="' . ($isActive ? 'true' : 'false') . '" tabindex="' . ($isActive ? '0' : '-1') . '" data-admin-subtab-target="' . e($tabId) . '">';
        echo '<span>' . e($tabLabel) . '</span>';
        if (array_key_exists('badge', $tab) && $tab['badge'] !== null && $tab['badge'] !== '') {
            echo '<span class="admin-subtab-badge">' . e((string) $tab['badge']) . '</span>';
        }
        echo '</button>';
    }
    echo '</div></nav>';
}

/**
 * Render one reusable admin subtab panel.
 *
 * Panels are left visible in the server-rendered response. The browser module
 * hides inactive panels after binding, preserving full form usability when
 * JavaScript is unavailable or a future admin screen opts out of scripting.
 *
 * @param string $id Panel id referenced by the matching subtab.
 * @param string $contentHtml Trusted admin HTML rendered by the caller.
 * @param bool $active Whether the panel should start selected.
 */
function view_render_admin_subtab_panel(string $id, string $contentHtml, bool $active = false): void
{
    $controlId = $id . '-control';
    echo '<section class="admin-subtab-panel' . ($active ? ' is-active' : '') . '" id="' . e($id) . '" role="tabpanel" aria-labelledby="' . e($controlId) . '" data-admin-subtab-panel>';
    echo $contentHtml;
    echo '</section>';
}

/**
 * Handle view render admin feature flag.
 *
 * Used by server-rendered view helpers.
 *
 * @param bool $enabled Enabled flag.
 * @param string $symbolHtml Symbol html HTML markup.
 * @param string $label Label value.
 * @return string Text result for the caller.
 */
function view_render_admin_feature_flag(bool $enabled, string $symbolHtml, string $label): string
{
    if (!$enabled) {
        return '';
    }
    return '<span class="admin-flag is-enabled" title="' . e($label) . '" aria-label="' . e($label) . '">' . $symbolHtml . '</span>';
}

/**
 * Handle view render admin thumbnail maintenance notice.
 *
 * Used by server-rendered view helpers.
 *
 * @param array $summary Summary value.
 */
function view_render_admin_thumbnail_maintenance_notice(array $summary): void
{
    if (($summary['images_with_missing'] ?? 0) <= 0) {
        return;
    }

    if (thumbnail_maintenance_notice_is_dismissed($summary)) {
        return;
    }

    echo '<div class="notice admin-thumbnail-maintenance-notice">';
    echo '<div class="admin-thumbnail-maintenance-copy">';
    if (($summary['images_with_missing'] ?? 0) > 0) {
        echo '<strong>' . e(t('admin.thumbnails.maintenance_required', 'Thumbnail maintenance required.')) . '</strong> ';
        echo e(t('admin.thumbnails.missing_images_value', '{count} image(s) are missing optimized thumbnails or have stale thumbnail files.', ['count' => (string) $summary['images_with_missing']])) . ' ';
        echo e(t('admin.thumbnails.missing_variants_value', '{count} thumbnail variant(s) need to be created.', ['count' => (string) $summary['missing_variants']])) . ' ';
        if (!empty($summary['limited'])) {
            echo e(t('admin.thumbnails.limited_scan_value', 'Only the first {count} image(s) were checked, so more may be pending.', ['count' => (string) $summary['images_scanned']])) . ' ';
        }
        echo t('admin.thumbnails.public_visitors_do_not_generate', 'Public visitors will not generate these thumbnails while browsing. Use <strong>Create all thumbnails</strong> in the admin toolbar.');
    }
    if (($summary['webp_skipped'] ?? 0) > 0) {
        echo (($summary['images_with_missing'] ?? 0) > 0 ? '<br>' : '');
        echo e(t('admin.thumbnails.webp_skipped_exif', 'Some WebP variants are intentionally skipped because the source images contain EXIF metadata and this server cannot preserve EXIF during WebP conversion.'));
    }
    echo '</div>';
    echo '<form method="post" action="' . e(url_for('admin_dismiss_thumbnail_notice')) . '" class="admin-thumbnail-maintenance-dismiss" data-thumbnail-maintenance-form>';
    echo csrf_field();
    echo '<input type="hidden" name="thumbnail_inventory_fingerprint" value="' . e((string) ($summary['inventory_fingerprint'] ?? '')) . '">';
    echo '<button type="submit" class="secondary" formaction="' . e(url_for('admin_create_thumbnails')) . '" name="scope" value="missing" data-create-missing-thumbnails>' . e(t('admin.thumbnails.create_missing', 'Create missing thumbnails')) . '</button>';
    echo '<button type="submit" class="secondary">' . e(t('admin.thumbnails.dismiss_7_days', 'Dismiss for 7 days')) . '</button>';
    echo '</form>';
    echo '</div>';
}

/**
 * Handle view render admin sidebar.
 *
 * Used by server-rendered view helpers.
 *
 * @param string $currentPage Current page value.
 */
function view_render_admin_sidebar(string $currentPage): void
{
    echo '<aside class="admin-sidebar" aria-label="' . e(t('admin.menu.aria_navigation', 'Admin navigation')) . '">';
    echo '<div class="admin-sidebar-title">' . e(t('admin.menu.title', 'Admin')) . '</div>';
    foreach (view_admin_menu_structure() as $group) {
        echo '<section class="admin-menu-group">';
        echo '<h2>' . e((string) $group['label']) . '</h2>';
        echo '<nav class="admin-menu-links">';
        foreach ((array) $group['items'] as $item) {
            $featureKey = (string) ($item['feature'] ?? '');
            if ($featureKey !== '' && function_exists('Gallery\\Services\\feature_flag_enabled') && !feature_flag_enabled($featureKey)) {
                continue;
            }
            $activeClass = view_admin_menu_item_is_active($item, $currentPage) ? ' is-active' : '';
            $highlightClass = !empty($item['highlight']) ? ' is-update-pending' : '';
            echo '<a class="admin-menu-link' . e($activeClass . $highlightClass) . '" href="' . e((string) $item['url']) . '">' . e((string) $item['label']) . '</a>';
        }
        echo '</nav></section>';
    }
    echo '</aside>';
}

/**
 * Handle view render missing admin email notice.
 *
 * Used by server-rendered view helpers.
 *
 * @param ?array $user User value.
 * @param string $currentPage Current page value.
 */
function view_render_missing_admin_email_notice(?array $user, string $currentPage): void
{
    if (!$user || $currentPage === 'admin_login' || $currentPage === 'admin_logout' || $currentPage === 'setup') {
        return;
    }
    if (trim((string) ($user['email'] ?? '')) !== '') {
        return;
    }

    echo '<div class="notice admin-account-notice">';
    echo '<strong>' . e(t('admin.account.notice_recovery_email_missing_title', 'Recovery email missing.')) . '</strong> ';
    echo e(t('admin.account.notice_recovery_email_missing_body', 'Add an email address to your account so username-or-email login works and the app is ready for future account recovery.'));
    echo ' <a href="' . e(url_for('admin_account')) . '">' . e(t('admin.account.open_account_settings', 'Open account settings')) . '</a>';
    echo '</div>';
}
