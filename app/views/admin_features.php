<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_features.php
 * Module Type: View
 *
 * Purpose:
 *   Renders the Admin feature settings page from controller-prepared presentation data.
 *
 * Responsibilities:
 *   - Render feature summary metrics and grouped feature cards
 *   - Render configured/effective state without querying feature policy
 *   - Keep request handling and feature registry lookups outside the view
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
 *   - This view consumes presentation-ready groups and labels only.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;

/**
 * Render the Admin feature settings page body.
 *
 * @param array<string, mixed> $viewModel Prepared feature-settings presentation model.
 */
function view_render_admin_features_page(array $viewModel): void
{
    echo '<section class="hero admin-features-hero"><div><p class="admin-kicker">' . e((string) ($viewModel['kicker'] ?? '')) . '</p><h1>' . e((string) ($viewModel['title'] ?? '')) . '</h1><p class="muted">' . e((string) ($viewModel['description'] ?? '')) . '</p></div>';
    echo '<div class="admin-hero-actions"><a class="button secondary" href="' . e((string) ($viewModel['dashboard_url'] ?? '')) . '">' . e((string) ($viewModel['back_label'] ?? '')) . '</a></div></section>';

    if ((string) ($viewModel['notice'] ?? '') !== '') {
        echo '<div class="notice">' . e((string) $viewModel['notice']) . '</div>';
    }
    if ((string) ($viewModel['save_error'] ?? '') !== '') {
        echo '<div class="notice error">' . e((string) $viewModel['save_error']) . '</div>';
    }

    $summary = (array) ($viewModel['summary'] ?? []);
    echo '<section class="panel admin-feature-summary-panel"><h2>' . e((string) ($viewModel['summary_title'] ?? '')) . '</h2>';
    echo '<div class="admin-metric-grid">';
    view_render_admin_feature_metric((array) ($summary['enabled'] ?? []));
    view_render_admin_feature_metric((array) ($summary['disabled'] ?? []));
    view_render_admin_feature_metric((array) ($summary['total'] ?? []));
    echo '</div></section>';

    echo '<form method="post" class="admin-feature-form">' . csrf_field() . '<input type="hidden" name="feature_registry_revision" value="' . e((string) ($viewModel['registry_revision'] ?? '')) . '">';
    foreach ((array) ($viewModel['groups'] ?? []) as $group) {
        view_render_admin_feature_group((array) $group, (string) ($viewModel['group_kicker'] ?? ''));
    }
    echo '<div class="admin-sticky-actions"><button type="submit">' . e((string) ($viewModel['save_label'] ?? '')) . '</button><a class="button secondary" href="' . e((string) ($viewModel['reset_url'] ?? '')) . '">' . e((string) ($viewModel['reset_label'] ?? '')) . '</a></div>';
    echo '</form>';
}

/**
 * Render one feature summary metric.
 *
 * @param array<string, mixed> $metric Prepared summary metric.
 */
function view_render_admin_feature_metric(array $metric): void
{
    echo '<article class="admin-metric-card"><span>' . e((string) ($metric['label'] ?? '')) . '</span><strong>' . (int) ($metric['value'] ?? 0) . '</strong><small>' . e((string) ($metric['help'] ?? '')) . '</small></article>';
}

/**
 * Render one feature group.
 *
 * @param array<string, mixed> $group Prepared group presentation model.
 * @param string $groupKicker Group kicker label.
 */
function view_render_admin_feature_group(array $group, string $groupKicker): void
{
    echo '<section class="panel admin-feature-group" id="admin-feature-group-' . e((string) ($group['key'] ?? '')) . '">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e($groupKicker) . '</p><h2>' . e((string) ($group['label'] ?? '')) . '</h2></div><p class="muted">' . e((string) ($group['description'] ?? '')) . '</p></div>';
    echo '<div class="admin-feature-grid">';
    foreach ((array) ($group['features'] ?? []) as $feature) {
        view_render_admin_feature_card((array) $feature);
    }
    echo '</div></section>';
}

/**
 * Render one feature card.
 *
 * @param array<string, mixed> $feature Prepared feature presentation model.
 */
function view_render_admin_feature_card(array $feature): void
{
    echo '<article class="admin-feature-card' . e((string) ($feature['card_class'] ?? '')) . '">';
    echo '<input type="checkbox" name="enabled_features[]" value="' . e((string) ($feature['key'] ?? '')) . '" aria-label="' . e((string) ($feature['label'] ?? '')) . '"' . (!empty($feature['configured']) ? ' checked' : '') . (!empty($feature['editable']) ? '' : ' disabled') . '>';
    echo '<span class="admin-feature-card-body"><strong>' . e((string) ($feature['label'] ?? '')) . '</strong><small>' . e((string) ($feature['description'] ?? '')) . '</small>';

    $badges = (array) ($feature['badges'] ?? []);
    if ($badges !== []) {
        echo '<span class="admin-feature-badges">';
        foreach ($badges as $badge) {
            echo '<span class="admin-feature-badge">' . e((string) $badge) . '</span>';
        }
        echo '</span>';
    }

    echo '<span class="admin-feature-policy-state">';
    echo '<span>' . e((string) ($feature['configured_state_label'] ?? '')) . '</span>';
    echo '<span>' . e((string) ($feature['effective_state_label'] ?? '')) . '</span>';
    echo '</span>';
    echo '<small class="admin-feature-storage-source">' . e((string) ($feature['storage_source_label'] ?? '')) . '</small>';

    if ((string) ($feature['schema_state_label'] ?? '') !== '') {
        echo '<small class="admin-feature-schema-state">' . e((string) $feature['schema_state_label']) . '</small>';
    }
    if ((string) ($feature['blocker_label'] ?? '') !== '') {
        echo '<small class="admin-feature-blocker">' . e((string) $feature['blocker_label']) . '</small>';
    }
    if ((string) ($feature['context_hint'] ?? '') !== '') {
        echo '<small class="admin-feature-context">' . e((string) $feature['context_hint']) . '</small>';
    }
    if ((string) ($feature['settings_url'] ?? '') !== '') {
        echo '<a class="admin-feature-settings-link" href="' . e((string) $feature['settings_url']) . '">' . e((string) ($feature['settings_label'] ?? '')) . '</a>';
    }

    echo '<code>' . e((string) ($feature['key'] ?? '')) . '</code></span>';
    echo '<span class="admin-feature-state">' . e((string) ($feature['effective_state'] ?? '')) . '</span>';
    echo '</article>';
}
