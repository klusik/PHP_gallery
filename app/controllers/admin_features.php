<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_features.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders and saves global feature visibility switches.
 *
 * Responsibilities:
 *   - Require admin authentication for feature settings
 *   - Render registry-driven feature checkboxes grouped by context
 *   - Persist opt-out feature state through app_settings
 *   - Honor each registered feature's declared default state
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
 *   2026-08-19
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\feature_flag_enabled;
use function Gallery\Services\feature_flag_summary_counts;
use function Gallery\Services\grouped_feature_flag_definitions;
use function Gallery\Services\save_feature_flags_from_post;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;

/**
 * Render the Admin feature settings page.
 */
function cms_admin_features(): void
{
    require_admin();

    if (request_method() === 'POST') {
        verify_csrf();
        $summary = save_feature_flags_from_post($_POST);
        admin_log_event('info', 'features.updated', 'Admin updated global feature switches.', $summary);
        flash_message('admin_notice', t('admin.features.notice_saved', 'Feature settings saved. Enabled: {enabled}. Disabled: {disabled}.', [
            'enabled' => (string) $summary['enabled'],
            'disabled' => (string) $summary['disabled'],
        ]));
        redirect_to(url_for('admin_features', ['saved' => 1]));
    }

    $summary = feature_flag_summary_counts();
    render_header(t('admin.features.page_title', 'Feature settings'));
    echo '<section class="hero admin-features-hero"><div><p class="admin-kicker">' . e(t('admin.features.kicker', 'Configuration')) . '</p><h1>' . e(t('admin.features.title', 'Feature settings')) . '</h1><p class="muted">' . e(t('admin.features.description', 'Disable unfinished or unwanted features without removing their code or data. Disabled features are hidden from normal navigation and guarded at their routes.')) . '</p></div>';
    echo '<div class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.features.back_to_dashboard', 'Back to dashboard')) . '</a></div></section>';

    $notice = (string) flash_message('admin_notice');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }

    echo '<section class="panel admin-feature-summary-panel"><h2>' . e(t('admin.features.summary_title', 'Current state')) . '</h2>';
    echo '<div class="admin-metric-grid">';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.features.summary_enabled', 'Enabled')) . '</span><strong>' . (int) $summary['enabled'] . '</strong><small>' . e(t('admin.features.summary_enabled_help', 'Visible and usable.')) . '</small></article>';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.features.summary_disabled', 'Disabled')) . '</span><strong>' . (int) $summary['disabled'] . '</strong><small>' . e(t('admin.features.summary_disabled_help', 'Hidden and route-guarded.')) . '</small></article>';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.features.summary_total', 'Total')) . '</span><strong>' . (int) $summary['total'] . '</strong><small>' . e(t('admin.features.summary_total_help', 'Registered optional features.')) . '</small></article>';
    echo '</div></section>';

    echo '<form method="post" class="admin-feature-form">' . csrf_field();
    foreach (grouped_feature_flag_definitions() as $groupKey => $group) {
        $groupDefinition = (array) ($group['group'] ?? []);
        $features = (array) ($group['features'] ?? []);
        echo '<section class="panel admin-feature-group" id="admin-feature-group-' . e((string) $groupKey) . '">';
        echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.features.group_kicker', 'Feature group')) . '</p><h2>' . e((string) ($groupDefinition['label'] ?? $groupKey)) . '</h2></div><p class="muted">' . e((string) ($groupDefinition['description'] ?? '')) . '</p></div>';
        echo '<div class="admin-feature-grid">';
        foreach ($features as $featureKey => $definition) {
            $enabled = feature_flag_enabled((string) $featureKey);
            echo '<label class="admin-feature-card' . ($enabled ? ' is-enabled' : ' is-disabled') . '">';
            echo '<input type="checkbox" name="enabled_features[]" value="' . e((string) $featureKey) . '"' . ($enabled ? ' checked' : '') . '>';
            echo '<span class="admin-feature-card-body"><strong>' . e((string) ($definition['label'] ?? $featureKey)) . '</strong><small>' . e((string) ($definition['description'] ?? '')) . '</small><code>' . e((string) $featureKey) . '</code></span>';
            echo '<span class="admin-feature-state">' . e($enabled ? t('admin.features.state_enabled', 'Enabled') : t('admin.features.state_disabled', 'Disabled')) . '</span>';
            echo '</label>';
        }
        echo '</div></section>';
    }
    echo '<div class="admin-sticky-actions"><button type="submit">' . e(t('admin.features.save', 'Save feature settings')) . '</button><a class="button secondary" href="' . e(url_for('admin_features')) . '">' . e(t('admin.features.reset_form', 'Reset form')) . '</a></div>';
    echo '</form>';

    render_footer();
}
