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
 *   2026-09-09
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
use function Gallery\Services\feature_capability_admin_context_hint;
use function Gallery\Services\feature_capability_admin_health_snapshot;
use function Gallery\Services\feature_capability_registry_revision;
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
        try {
            $summary = save_feature_flags_from_post($_POST);
            admin_log_event('info', 'features.updated', 'Admin updated global feature switches.', $summary);
            flash_message('admin_notice', t('admin.features.notice_saved', 'Feature settings saved. Enabled: {enabled}. Disabled: {disabled}.', [
                'enabled' => (string) $summary['enabled'],
                'disabled' => (string) $summary['disabled'],
            ]));
            redirect_to(url_for('admin_features', ['saved' => 1]));
        } catch (\RuntimeException $exception) {
            $saveError = $exception->getMessage();
        }
    }

    $summary = feature_flag_summary_counts();
    $healthSnapshot = feature_capability_admin_health_snapshot();
    render_header(t('admin.features.page_title', 'Feature settings'));
    echo '<section class="hero admin-features-hero"><div><p class="admin-kicker">' . e(t('admin.features.kicker', 'Configuration')) . '</p><h1>' . e(t('admin.features.title', 'Feature settings')) . '</h1><p class="muted">' . e(t('admin.features.description', 'Disable unfinished or unwanted features without removing their code or data. Disabled features are hidden from normal navigation and guarded at their routes.')) . '</p></div>';
    echo '<div class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.features.back_to_dashboard', 'Back to dashboard')) . '</a></div></section>';

    $notice = (string) flash_message('admin_notice');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }

    if (isset($saveError) && $saveError !== '') {
        echo '<div class="notice error">' . e($saveError) . '</div>';
    }

    echo '<section class="panel admin-feature-summary-panel"><h2>' . e(t('admin.features.summary_title', 'Current state')) . '</h2>';
    echo '<div class="admin-metric-grid">';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.features.summary_enabled', 'Enabled')) . '</span><strong>' . (int) $summary['enabled'] . '</strong><small>' . e(t('admin.features.summary_enabled_help', 'Visible and usable.')) . '</small></article>';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.features.summary_disabled', 'Disabled')) . '</span><strong>' . (int) $summary['disabled'] . '</strong><small>' . e(t('admin.features.summary_disabled_help', 'Hidden and route-guarded.')) . '</small></article>';
    echo '<article class="admin-metric-card"><span>' . e(t('admin.features.summary_total', 'Total')) . '</span><strong>' . (int) $summary['total'] . '</strong><small>' . e(t('admin.features.summary_total_help', 'Registered optional features.')) . '</small></article>';
    echo '</div></section>';

    echo '<form method="post" class="admin-feature-form">' . csrf_field() . '<input type="hidden" name="feature_registry_revision" value="' . e(feature_capability_registry_revision()) . '">';
    foreach (grouped_feature_flag_definitions() as $groupKey => $group) {
        $groupDefinition = (array) ($group['group'] ?? []);
        $features = (array) ($group['features'] ?? []);
        echo '<section class="panel admin-feature-group" id="admin-feature-group-' . e((string) $groupKey) . '">';
        echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.features.group_kicker', 'Feature group')) . '</p><h2>' . e((string) ($groupDefinition['label'] ?? $groupKey)) . '</h2></div><p class="muted">' . e((string) ($groupDefinition['description'] ?? '')) . '</p></div>';
        echo '<div class="admin-feature-grid">';
        foreach ($features as $featureKey => $definition) {
            $health = (array) ($healthSnapshot[(string) $featureKey] ?? []);
            $configuredEnabled = !empty($health['configured']);
            $effectiveEnabled = !empty($health['effective']);
            $editable = !empty($definition['editable_on_features']);
            $blockers = array_values(array_filter(array_map('strval', (array) ($health['blockers'] ?? []))));
            $contextHint = feature_capability_admin_context_hint((string) $featureKey);
            $cardClass = $configuredEnabled ? ' is-enabled' : ' is-disabled';
            if ($configuredEnabled && !$effectiveEnabled) {
                $cardClass .= ' is-ineffective';
            }
            if (!$editable) {
                $cardClass .= ' is-read-only';
            }

            echo '<article class="admin-feature-card' . $cardClass . '">';
            echo '<input type="checkbox" name="enabled_features[]" value="' . e((string) $featureKey) . '" aria-label="' . e((string) ($definition['label'] ?? $featureKey)) . '"' . ($configuredEnabled ? ' checked' : '') . ($editable ? '' : ' disabled') . '>';
            echo '<span class="admin-feature-card-body"><strong>' . e((string) ($definition['label'] ?? $featureKey)) . '</strong><small>' . e((string) ($definition['description'] ?? '')) . '</small>';

            $tags = array_values(array_unique(array_filter(array_map('strval', (array) ($definition['behavior_tags'] ?? [])))));
            if ($tags !== []) {
                echo '<span class="admin-feature-badges">';
                foreach ($tags as $tag) {
                    $tagLabel = match ($tag) {
                        'public' => t('admin.features.badge.public', 'Public'),
                        'admin-only' => t('admin.features.badge.admin_only', 'Admin only'),
                        'writes-files' => t('admin.features.badge.writes_files', 'Writes files'),
                        'background-work' => t('admin.features.badge.background_work', 'Background work'),
                        'outbound-network' => t('admin.features.badge.outbound_network', 'Outbound network'),
                        'privacy' => t('admin.features.badge.privacy', 'Privacy'),
                        'diagnostic' => t('admin.features.badge.diagnostic', 'Diagnostic'),
                        'destructive-maintenance' => t('admin.features.badge.destructive_maintenance', 'Destructive maintenance'),
                        default => str_replace('-', ' ', ucfirst($tag)),
                    };
                    echo '<span class="admin-feature-badge">' . e($tagLabel) . '</span>';
                }
                echo '</span>';
            }

            echo '<span class="admin-feature-policy-state">';
            echo '<span>' . e(t('admin.features.configured_state', 'Configured: {state}', ['state' => $configuredEnabled ? t('admin.features.state_enabled', 'Enabled') : t('admin.features.state_disabled', 'Disabled')])) . '</span>';
            echo '<span>' . e(t('admin.features.effective_state', 'Effective: {state}', ['state' => $effectiveEnabled ? t('admin.features.state_enabled', 'Enabled') : t('admin.features.state_disabled', 'Disabled')])) . '</span>';
            echo '</span>';

            $sourceType = (string) ($health['source_type'] ?? 'unknown');
            echo '<small class="admin-feature-storage-source">' . e(t('admin.features.storage_source', 'Storage: {source}', ['source' => $sourceType])) . '</small>';
            $schemaState = $health['schema_state'] ?? null;
            if (is_string($schemaState) && $schemaState !== '') {
                echo '<small class="admin-feature-schema-state">' . e(t('admin.features.schema_state', 'Schema: {state}', ['state' => $schemaState])) . '</small>';
            }

            if ($blockers !== []) {
                $blockerLabels = [];
                foreach ($blockers as $blockerKey) {
                    $blockerDefinition = \Gallery\Services\feature_capability_definition($blockerKey);
                    $blockerLabels[] = is_array($blockerDefinition) ? (string) ($blockerDefinition['label'] ?? $blockerKey) : $blockerKey;
                }
                echo '<small class="admin-feature-blocker">' . e(t('admin.features.blocked_by', 'Currently unavailable because this dependency is disabled: {feature}', ['feature' => implode(', ', $blockerLabels)])) . '</small>';
            }
            if (is_string($contextHint) && $contextHint !== '') {
                echo '<small class="admin-feature-context">' . e($contextHint) . '</small>';
            }

            $settingsRoute = trim((string) ($health['settings_route'] ?? ''));
            if ($settingsRoute !== '') {
                $settingsParams = is_array($health['settings_params'] ?? null) ? $health['settings_params'] : [];
                $settingsUrl = url_for($settingsRoute, $settingsParams);
                $settingsFragment = trim((string) ($health['settings_fragment'] ?? ''));
                if ($settingsFragment !== '') {
                    $settingsUrl .= '#' . rawurlencode($settingsFragment);
                }
                echo '<a class="admin-feature-settings-link" href="' . e($settingsUrl) . '">' . e(t('admin.features.specialized_settings', 'Open specialized settings')) . '</a>';
            }

            echo '<code>' . e((string) $featureKey) . '</code></span>';
            echo '<span class="admin-feature-state">' . e($effectiveEnabled ? t('admin.features.state_enabled', 'Enabled') : t('admin.features.state_disabled', 'Disabled')) . '</span>';
            echo '</article>';
        }
        echo '</div></section>';
    }
    echo '<div class="admin-sticky-actions"><button type="submit">' . e(t('admin.features.save', 'Save feature settings')) . '</button><a class="button secondary" href="' . e(url_for('admin_features')) . '">' . e(t('admin.features.reset_form', 'Reset form')) . '</a></div>';
    echo '</form>';

    render_footer();
}
