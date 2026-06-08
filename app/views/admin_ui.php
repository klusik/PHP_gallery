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

/**
 * Return the Admin interface design tokens used by CSS and by the visible spec card.
 *
 * @return array<string, mixed>
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
 * @param array<string, scalar|null|bool> $attributes Attribute values keyed by name.
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
 * @param array<string, mixed> $action Action definition.
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
 * @param array<string, mixed> $model Hero view model.
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
 * @param array<string, mixed> $model Intro view model.
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
 * @param array<int, array<string, mixed>> $cards Summary cards.
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
 * @param array<string, mixed> $card Summary card model.
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
