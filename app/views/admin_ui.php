<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_ui.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders shared Admin user-interface primitives used by full admin pages and
 *   embedded side-panel workflows.
 *
 * Responsibilities:
 *   - Keep repeated Admin hero, summary, and section-intro markup in one place
 *   - Expose a compact design-spec model for the Admin cinematic interface
 *   - Keep copy short and human-readable while preserving accessible labels
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
 *   2026-06-08
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Services\t;

/**
 * Return a decorative, unfilled Trash symbol for Admin labels and headings.
 *
 * @return string Trusted SVG using the surrounding text color and no background.
 */
function view_admin_trash_icon(): string
{
    return '<svg class="admin-trash-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/></svg>';
}

/**
 * Return a decorative outline symbol for a main Admin navigation item.
 *
 * @param string $icon Presentation identifier from the navigation structure.
 * @return string Trusted SVG hidden from assistive technology, or empty for unknown icons.
 */
function view_admin_menu_icon(string $icon): string
{
    if ($icon === 'trash') {
        return view_admin_trash_icon();
    }
    // Keep the same view box, stroke, and text color as the existing Trash symbol.
    $symbols = [
        'overview' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'settings' => '<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="8" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="18" r="2"/>',
        'galleries' => '<rect x="3" y="5" width="18" height="15" rx="2"/><path d="m3 16 5-5 5 5 3-3 5 5"/><circle cx="16" cy="9" r="1"/>',
        'create' => '<path d="M3 7V5h7l2 2h9v13H3V7M12 10v7M8.5 13.5h7"/>',
        'smart' => '<path d="m12 3 2.5 6.5L21 12l-6.5 2.5L12 21l-2.5-6.5L3 12l6.5-2.5L12 3"/>',
        'upload' => '<path d="M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6"/>',
        'mobile' => '<rect x="6" y="2" width="12" height="20" rx="2"/><path d="M10 18h4m-2-4V6m-3 3 3-3 3 3"/>',
        'rename' => '<path d="m14 5 5 5M4 20l4-1L21 6l-5-5L3 14l-1 6h2M12 21h9"/>',
        'api' => '<path d="m8 7-5 5 5 5m8-10 5 5-5 5m-3-13-2 18"/>',
        'tags' => '<path d="M3 3h8l10 10-8 8L3 11V3"/><circle cx="7.5" cy="7.5" r="1"/>',
        'theme' => '<path d="M12 3a9 9 0 1 0 0 18h1a2 2 0 0 0 1-4 2 2 0 0 1 1-4h3a3 3 0 0 0 3-3c0-4-4-7-9-7Z"/><circle cx="7" cy="10" r="1"/><circle cx="10" cy="6" r="1"/><circle cx="15" cy="6" r="1"/>',
        'features' => '<rect x="3" y="7" width="18" height="10" rx="5"/><circle cx="16" cy="12" r="2"/>',
        'logs' => '<path d="M6 3h9l4 4v14H6V3m9 0v5h4M9 12h7m-7 4h7"/>',
        'telemetry' => '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
        'maintenance' => '<path d="M14 3a6 6 0 0 0-7 7L2 15l7 7 5-5a6 6 0 0 0 7-7l-4 4-5-5 4-4-2-2Z"/>',
        'report' => '<path d="M5 3h14v18H5V3m4 14v-4m3 4V7m3 10v-7"/>',
        'integrity' => '<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3m-4 9 3 3 5-6"/>',
        'navigation' => '<circle cx="12" cy="12" r="9"/><path d="m16 8-3 5-5 3 3-5 5-3"/>',
        'update' => '<path d="M20 9a8 8 0 0 0-14-4L3 8m0-5v5h5m-4 7a8 8 0 0 0 14 4l3-3m0 5v-5h-5"/>',
        'profile' => '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'viewers' => '<circle cx="9" cy="7" r="3"/><path d="M2 21v-3a7 7 0 0 1 14 0v3M16 4a3 3 0 0 1 0 6m3 4a6 6 0 0 1 3 5v2"/>',
        'logout' => '<path d="M9 3H3v18h6m0-9h12m-5-5 5 5-5 5"/>',
    ];
    if (!isset($symbols[$icon])) {
        return '';
    }
    return '<svg class="admin-menu-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $symbols[$icon] . '</svg>';
}

/**
 * Return the Admin interface design tokens used by CSS and by the visible spec card.
 *
 * @return array<string mixed>.
 */
function view_admin_ui_design_spec(): array
{
    return [
        'palette' => [
            ['name' => t('admin.ui.palette_canvas', 'Canvas'), 'hex' => '#F3F6FA', 'role' => t('admin.ui.palette_canvas_role', '60 percent neutral workspace')],
            ['name' => t('admin.ui.palette_surface', 'Surface'), 'hex' => '#FFFFFF', 'role' => t('admin.ui.palette_surface_role', 'Primary cards and forms')],
            ['name' => t('admin.ui.palette_mist', 'Mist'), 'hex' => '#E7EEF6', 'role' => t('admin.ui.palette_mist_role', '30 percent supporting panels')],
            ['name' => t('admin.ui.palette_ink', 'Ink'), 'hex' => '#182230', 'role' => t('admin.ui.palette_ink_role', 'Readable text')],
            ['name' => t('admin.ui.palette_brand', 'Brand'), 'hex' => '#2563A8', 'role' => t('admin.ui.palette_brand_role', '10 percent primary action color')],
            ['name' => t('admin.ui.palette_success', 'Ready'), 'hex' => '#237A3B', 'role' => t('admin.ui.palette_success_role', 'Safe and complete state')],
            ['name' => t('admin.ui.palette_warning', 'Needs care'), 'hex' => '#9A6700', 'role' => t('admin.ui.palette_warning_role', 'Attention without alarm')],
            ['name' => t('admin.ui.palette_danger', 'Remove'), 'hex' => '#A33A2F', 'role' => t('admin.ui.palette_danger_role', 'Destructive action')],
        ],
        'typography' => [
            ['name' => 'H1', 'size' => '24px', 'weight' => '700'],
            ['name' => 'H2', 'size' => '20px', 'weight' => '700'],
            ['name' => 'H3', 'size' => '16px', 'weight' => '700'],
            ['name' => t('admin.ui.type_body', 'Body'), 'size' => '14px', 'weight' => '400'],
        ],
        'spacing' => t('admin.ui.spacing_rule', 'Spacing uses 4px and 8px steps. Common gaps are 4px, 8px, 12px, and 16px.'),
        'motion' => t('admin.ui.motion_rule', 'Tabs fade upward, side panels slide in from the right, and cards lift only on deliberate interaction.'),
    ];
}

/**
 * Convert an attribute map to safe HTML attributes.
 *
 * @param array $attributes Attributes value.
 * @return string Text result for the caller.
 */
function view_admin_ui_attributes(array $attributes): string
{
    $html = '';
    foreach ($attributes as $name => $value) {
        $attributeName = trim((string) $name);
        if ($attributeName === '' || $value === null || $value === false) {
            continue;
        }
        if ($value === true) {
            $html .= ' ' . e($attributeName);
            continue;
        }
        $html .= ' ' . e($attributeName) . '="' . e((string) $value) . '"';
    }
    return $html;
}

/**
 * Render one anchor-style Admin action.
 *
 * @param array $action Action value.
 * @return string Text result for the caller.
 */
function view_admin_ui_action_link_html(array $action): string
{
    $label = trim((string) ($action['label'] ?? ''));
    $url = trim((string) ($action['url'] ?? ''));
    if ($label === '' || $url === '') {
        return '';
    }

    $className = trim((string) ($action['class'] ?? 'button secondary'));
    $attributes = is_array($action['attributes'] ?? null) ? $action['attributes'] : [];
    $attributes['class'] = trim($className !== '' ? $className : 'button secondary');
    $attributes['href'] = $url;
    if (!empty($action['target'])) {
        $attributes['target'] = (string) $action['target'];
        $attributes['rel'] = (string) ($action['rel'] ?? 'noopener noreferrer');
    }
    return '<a' . view_admin_ui_attributes($attributes) . '>' . e($label) . '</a>';
}

/**
 * Render a reusable Admin hero used by dashboard pages and edit side panels.
 *
 * @param array $model Model value.
 */
function view_render_admin_hero(array $model): void
{
    $title = trim((string) ($model['title'] ?? ''));
    if ($title === '') {
        return;
    }

    $className = trim('hero admin-dashboard-hero admin-cinematic-hero ' . (string) ($model['class'] ?? ''));
    $kicker = trim((string) ($model['kicker'] ?? ''));
    $description = trim((string) ($model['description'] ?? ''));
    $actions = is_array($model['actions'] ?? null) ? $model['actions'] : [];
    $actionsHtml = (string) ($model['actions_html'] ?? '');
    $meta = is_array($model['meta'] ?? null) ? $model['meta'] : [];
    $ariaLabel = trim((string) ($model['actions_aria_label'] ?? t('admin.ui.hero_actions', 'Page actions')));

    echo '<section class="' . e($className) . '">';
    echo '<div class="admin-cinematic-hero-copy">';
    if ($kicker !== '') {
        echo '<p class="admin-kicker">' . e($kicker) . '</p>';
    }
    echo '<h1>' . e($title) . '</h1>';
    if ($description !== '') {
        echo '<p class="muted admin-cinematic-hero-lede">' . e($description) . '</p>';
    }
    if ($meta !== []) {
        echo '<div class="admin-cinematic-meta" aria-label="' . e(t('admin.ui.page_summary', 'Page summary')) . '">';
        foreach ($meta as $item) {
            $label = trim((string) ($item['label'] ?? ''));
            $value = trim((string) ($item['value'] ?? ''));
            if ($label === '' || $value === '') {
                continue;
            }
            echo '<span><strong>' . e($value) . '</strong> ' . e($label) . '</span>';
        }
        echo '</div>';
    }
    echo '</div>';

    if ($actions !== [] || trim($actionsHtml) !== '') {
        echo '<nav class="admin-hero-actions admin-cinematic-actions" aria-label="' . e($ariaLabel) . '">';
        foreach ($actions as $action) {
            if (is_array($action)) {
                echo view_admin_ui_action_link_html($action);
            }
        }
        echo $actionsHtml;
        echo '</nav>';
    }
    echo '</section>';
}

/**
 * Render a reusable Admin section intro.
 *
 * @param array $model Model value.
 */
function view_render_admin_tab_intro(array $model): void
{
    $title = trim((string) ($model['title'] ?? ''));
    if ($title === '') {
        return;
    }

    $kicker = trim((string) ($model['kicker'] ?? ''));
    $description = trim((string) ($model['description'] ?? ''));
    $actionsHtml = (string) ($model['actions_html'] ?? '');
    $actions = is_array($model['actions'] ?? null) ? $model['actions'] : [];
    $className = trim('admin-tab-intro admin-cinematic-intro ' . (string) ($model['class'] ?? ''));

    echo '<div class="' . e($className) . '"><div>';
    if ($kicker !== '') {
        echo '<p class="admin-kicker">' . e($kicker) . '</p>';
    }
    echo '<h2>' . e($title) . '</h2></div>';
    if ($description !== '' || $actions !== [] || trim($actionsHtml) !== '') {
        echo '<div class="admin-cinematic-intro-side">';
        if ($description !== '') {
            echo '<p class="muted">' . e($description) . '</p>';
        }
        if ($actions !== [] || trim($actionsHtml) !== '') {
            echo '<div class="admin-hero-actions">';
            foreach ($actions as $action) {
                if (is_array($action)) {
                    echo view_admin_ui_action_link_html($action);
                }
            }
            echo $actionsHtml;
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

/**
 * Render a reusable summary card grid.
 *
 * @param array $cards Cards value.
 * @param string $className Class name value.
 * @param string $ariaLabel Aria label value.
 */
function view_render_admin_metric_grid(array $cards, string $className = 'admin-metric-grid', string $ariaLabel = ''): void
{
    $resolvedAriaLabel = $ariaLabel !== '' ? $ariaLabel : t('admin.dashboard.admin_summary', 'Admin summary');
    echo '<section class="' . e($className) . ' admin-cinematic-card-grid" aria-label="' . e($resolvedAriaLabel) . '">';
    foreach ($cards as $card) {
        if (is_array($card)) {
            view_render_admin_metric_card($card);
        }
    }
    echo '</section>';
}

/**
 * Render one reusable summary card.
 *
 * @param array $card Card value.
 */
function view_render_admin_metric_card(array $card): void
{
    $label = trim((string) ($card['label'] ?? ''));
    $value = trim((string) ($card['value'] ?? ''));
    if ($label === '' || $value === '') {
        return;
    }

    $help = trim((string) ($card['help'] ?? ''));
    $helpHtml = (string) ($card['help_html'] ?? '');
    $state = trim((string) ($card['state'] ?? 'neutral'));
    $className = trim('admin-metric-card admin-cinematic-card ' . (string) ($card['class'] ?? ''));

    echo '<article class="' . e($className) . '">';
    echo '<span class="admin-metric-label"><span class="admin-status-dot is-' . e($state) . '" aria-hidden="true"></span>' . e($label) . '</span>';
    echo '<strong>' . e($value) . '</strong>';
    if ($helpHtml !== '') {
        echo '<small>' . $helpHtml . '</small>';
    } elseif ($help !== '') {
        echo '<small>' . e($help) . '</small>';
    }
    echo '</article>';
}

/**
 * Render a concise Admin design-spec panel for maintainers.
 */
function view_render_admin_design_spec_panel(): void
{
    $spec = view_admin_ui_design_spec();
    echo '<section class="admin-design-spec-panel" aria-label="' . e(t('admin.ui.design_spec', 'Admin design spec')) . '">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.ui.design_kicker', 'Design language')) . '</p><h2>' . e(t('admin.ui.design_title', 'Cinematic admin flow')) . '</h2></div><p class="muted">' . e(t('admin.ui.design_description', 'The admin zone and side panels now share the same visual primitives, motion rhythm, and copy rules.')) . '</p></div>';
    echo '<div class="admin-design-spec-grid">';
    echo '<article class="admin-design-spec-card"><h3>' . e(t('admin.ui.palette_title', 'Palette')) . '</h3><div class="admin-palette-list">';
    foreach ((array) ($spec['palette'] ?? []) as $color) {
        if (!is_array($color)) {
            continue;
        }
        $hex = trim((string) ($color['hex'] ?? ''));
        $name = trim((string) ($color['name'] ?? ''));
        $role = trim((string) ($color['role'] ?? ''));
        if ($hex === '' || $name === '') {
            continue;
        }
        echo '<span class="admin-palette-chip"><i style="--admin-palette-chip-color: ' . e($hex) . '" aria-hidden="true"></i><strong>' . e($name) . '</strong><code>' . e($hex) . '</code><small>' . e($role) . '</small></span>';
    }
    echo '</div></article>';

    echo '<article class="admin-design-spec-card"><h3>' . e(t('admin.ui.typography_title', 'Typography')) . '</h3><div class="admin-type-list">';
    foreach ((array) ($spec['typography'] ?? []) as $type) {
        if (!is_array($type)) {
            continue;
        }
        echo '<span><strong>' . e((string) ($type['name'] ?? '')) . '</strong><code>' . e((string) ($type['size'] ?? '')) . '</code><small>' . e(t('admin.ui.type_weight', 'Weight {weight}', ['weight' => (string) ($type['weight'] ?? '')])) . '</small></span>';
    }
    echo '</div></article>';

    echo '<article class="admin-design-spec-card"><h3>' . e(t('admin.ui.spacing_title', 'Spacing')) . '</h3><p>' . e((string) ($spec['spacing'] ?? '')) . '</p><h3>' . e(t('admin.ui.motion_title', 'Motion')) . '</h3><p>' . e((string) ($spec['motion'] ?? '')) . '</p></article>';
    echo '</div></section>';
}
