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
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Controllers;

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
use function Gallery\Services\feature_capability_definition;
use function Gallery\Services\feature_capability_registry_revision;
use function Gallery\Services\feature_flag_summary_counts;
use function Gallery\Services\grouped_feature_flag_definitions;
use function Gallery\Services\save_feature_flags_from_post;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;
use function Gallery\Views\view_render_admin_features_page;

/**
 * Render the Admin feature settings page.
 */
function cms_admin_features(): void
{
    require_admin();
    $saveError = '';

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
    $viewModel = admin_features_view_model(
        grouped_feature_flag_definitions(),
        feature_capability_admin_health_snapshot(),
        $summary,
        (string) flash_message('admin_notice'),
        $saveError
    );

    render_header(t('admin.features.page_title', 'Feature settings'));
    view_render_admin_features_page($viewModel);
    render_footer();
}

/**
 * Prepare the Admin feature settings presentation model.
 *
 * @param array<string, mixed> $groupedDefinitions Grouped registry definitions.
 * @param array<string, mixed> $healthSnapshot Effective feature health snapshot.
 * @param array<string, mixed> $summary Feature summary counters.
 * @param string $notice Flash notice text.
 * @param string $saveError Save failure text.
 * @return array<string, mixed> Prepared page presentation model.
 */
function admin_features_view_model(array $groupedDefinitions, array $healthSnapshot, array $summary, string $notice, string $saveError): array
{
    $groups = [];
    foreach ($groupedDefinitions as $groupKey => $group) {
        $groupDefinition = (array) ($group['group'] ?? []);
        $features = [];
        foreach ((array) ($group['features'] ?? []) as $featureKey => $definition) {
            $features[] = admin_feature_card_view_model((string) $featureKey, (array) $definition, (array) ($healthSnapshot[(string) $featureKey] ?? []));
        }
        $groups[] = [
            'key' => (string) $groupKey,
            'label' => (string) ($groupDefinition['label'] ?? $groupKey),
            'description' => (string) ($groupDefinition['description'] ?? ''),
            'features' => $features,
        ];
    }

    return [
        'kicker' => t('admin.features.kicker', 'Configuration'),
        'title' => t('admin.features.title', 'Feature settings'),
        'description' => t('admin.features.description', 'Disable unfinished or unwanted features without removing their code or data. Disabled features are hidden from normal navigation and guarded at their routes.'),
        'dashboard_url' => url_for('admin'),
        'back_label' => t('admin.features.back_to_dashboard', 'Back to dashboard'),
        'notice' => $notice,
        'save_error' => $saveError,
        'summary_title' => t('admin.features.summary_title', 'Current state'),
        'summary' => [
            'enabled' => [
                'label' => t('admin.features.summary_enabled', 'Enabled'),
                'value' => (int) ($summary['enabled'] ?? 0),
                'help' => t('admin.features.summary_enabled_help', 'Visible and usable.'),
            ],
            'disabled' => [
                'label' => t('admin.features.summary_disabled', 'Disabled'),
                'value' => (int) ($summary['disabled'] ?? 0),
                'help' => t('admin.features.summary_disabled_help', 'Hidden and route-guarded.'),
            ],
            'total' => [
                'label' => t('admin.features.summary_total', 'Total'),
                'value' => (int) ($summary['total'] ?? 0),
                'help' => t('admin.features.summary_total_help', 'Registered optional features.'),
            ],
        ],
        'registry_revision' => feature_capability_registry_revision(),
        'group_kicker' => t('admin.features.group_kicker', 'Feature group'),
        'groups' => $groups,
        'save_label' => t('admin.features.save', 'Save feature settings'),
        'reset_url' => url_for('admin_features'),
        'reset_label' => t('admin.features.reset_form', 'Reset form'),
    ];
}

/**
 * Prepare one feature card for the presentation layer.
 *
 * @param string $featureKey Feature registry key.
 * @param array<string, mixed> $definition Feature definition.
 * @param array<string, mixed> $health Effective feature health data.
 * @return array<string, mixed> Prepared feature-card presentation model.
 */
function admin_feature_card_view_model(string $featureKey, array $definition, array $health): array
{
    $configuredEnabled = !empty($health['configured']);
    $effectiveEnabled = !empty($health['effective']);
    $editable = !empty($definition['editable_on_features']);
    $blockers = array_values(array_filter(array_map('strval', (array) ($health['blockers'] ?? []))));
    $contextHint = feature_capability_admin_context_hint($featureKey);
    $cardClass = $configuredEnabled ? ' is-enabled' : ' is-disabled';
    if ($configuredEnabled && !$effectiveEnabled) {
        $cardClass .= ' is-ineffective';
    }
    if (!$editable) {
        $cardClass .= ' is-read-only';
    }

    $badges = [];
    foreach (array_values(array_unique(array_filter(array_map('strval', (array) ($definition['behavior_tags'] ?? []))))) as $tag) {
        $badges[] = match ($tag) {
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
    }

    $blockerLabel = '';
    if ($blockers !== []) {
        $blockerLabels = [];
        foreach ($blockers as $blockerKey) {
            $blockerDefinition = feature_capability_definition($blockerKey);
            $blockerLabels[] = is_array($blockerDefinition) ? (string) ($blockerDefinition['label'] ?? $blockerKey) : $blockerKey;
        }
        $blockerLabel = t('admin.features.blocked_by', 'Currently unavailable because this dependency is disabled: {feature}', ['feature' => implode(', ', $blockerLabels)]);
    }

    $settingsUrl = '';
    $settingsRoute = trim((string) ($health['settings_route'] ?? ''));
    if ($settingsRoute !== '') {
        $settingsParams = is_array($health['settings_params'] ?? null) ? $health['settings_params'] : [];
        $settingsUrl = url_for($settingsRoute, $settingsParams);
        $settingsFragment = trim((string) ($health['settings_fragment'] ?? ''));
        if ($settingsFragment !== '') {
            $settingsUrl .= '#' . rawurlencode($settingsFragment);
        }
    }

    $enabledLabel = t('admin.features.state_enabled', 'Enabled');
    $disabledLabel = t('admin.features.state_disabled', 'Disabled');
    $sourceType = (string) ($health['source_type'] ?? 'unknown');
    $schemaState = $health['schema_state'] ?? null;

    return [
        'key' => $featureKey,
        'label' => (string) ($definition['label'] ?? $featureKey),
        'description' => (string) ($definition['description'] ?? ''),
        'configured' => $configuredEnabled,
        'effective' => $effectiveEnabled,
        'editable' => $editable,
        'card_class' => $cardClass,
        'badges' => $badges,
        'configured_state_label' => t('admin.features.configured_state', 'Configured: {state}', ['state' => $configuredEnabled ? $enabledLabel : $disabledLabel]),
        'effective_state_label' => t('admin.features.effective_state', 'Effective: {state}', ['state' => $effectiveEnabled ? $enabledLabel : $disabledLabel]),
        'storage_source_label' => t('admin.features.storage_source', 'Storage: {source}', ['source' => $sourceType]),
        'schema_state_label' => is_string($schemaState) && $schemaState !== '' ? t('admin.features.schema_state', 'Schema: {state}', ['state' => $schemaState]) : '',
        'blocker_label' => $blockerLabel,
        'context_hint' => is_string($contextHint) ? $contextHint : '',
        'settings_url' => $settingsUrl,
        'settings_label' => t('admin.features.specialized_settings', 'Open specialized settings'),
        'effective_state' => $effectiveEnabled ? $enabledLabel : $disabledLabel,
    ];
}
