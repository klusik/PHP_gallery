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
 *   - Render compact grouped switches with factory-state comparison
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

use function Gallery\Core\admin_wants_json;
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
use function Gallery\Services\feature_capability_default_enabled;
use function Gallery\Services\feature_capability_registry_revision;
use function Gallery\Services\feature_flag_summary_counts;
use function Gallery\Services\grouped_feature_flag_definitions;
use function Gallery\Services\save_feature_flags_from_post;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;
use function Gallery\Views\view_render_admin_features_page;
use function Gallery\Views\view_render_admin_feature_groups;

/**
 * Render the Admin feature settings page.
 *
 * @return void Processes authenticated CSRF writes, returns authoritative JSON
 *     health for autosave/read requests, or renders the direct-page form.
 */
function cms_admin_features(): void
{
    require_admin();
    $saveError = '';

    if (request_method() === 'POST') {
        verify_csrf();
        try {
            $summary = save_feature_flags_from_post($_POST);
            admin_log_event(
                'info',
                'features.updated',
                'Admin updated global feature switches.',
                $summary,
            );
            $savedMessage = t(
                'admin.features.notice_saved',
                'Feature settings saved. Enabled: {enabled}. Disabled: {disabled}.',
                [
                    'enabled' => (string) $summary['enabled'],
                    'disabled' => (string) $summary['disabled'],
                ],
            );
            if (!admin_wants_json()) {
                flash_message('admin_notice', $savedMessage);
                redirect_to(url_for('admin_features', ['saved' => 1]));
            }
        } catch (\RuntimeException $exception) {
            // Persistence owners can throw driver details; expose only safe UI text.
            $saveError = t(
                'admin.features.error.save_failed',
                'Feature settings could not be saved. Reload the page and try again.',
            );
        }
    }

    $summary = feature_flag_summary_counts();
    $viewModel = admin_features_view_model(
        grouped_feature_flag_definitions(),
        feature_capability_admin_health_snapshot(),
        $summary,
        (string) flash_message('admin_notice'),
        $saveError,
    );

    if (admin_wants_json()) {
        ob_start();
        view_render_admin_feature_groups($viewModel['groups']);
        $groupsHtml = (string) ob_get_clean();
        http_response_code($saveError === '' ? 200 : 409);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode([
            'ok' => $saveError === '',
            'message' => $saveError,
            'registry_revision' => $viewModel['registry_revision'],
            'groups_html' => $groupsHtml,
            'summary' => $viewModel['summary'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    render_header(t('admin.features.page_title', 'Feature settings'));
    view_render_admin_features_page($viewModel);
    render_footer();
}

/**
 * Prepare the Admin feature settings presentation model.
 *
 * @param array<string,array{group:array{label:string,description:string},features:array<string,FeatureDefinition>}> $groupedDefinitions Localized groups and canonical definitions documented by admin_feature_card_view_model().
 * @param array<string,FeatureHealth> $healthSnapshot Bounded health rows documented by admin_feature_card_view_model().
 * @param array{enabled:int,disabled:int,total:int} $summary Effective-state counters.
 * @param string $notice Flash notice text.
 * @param string $saveError Save failure text.
 * @return array{title:string,description:string,endpoint:string,notice:string,save_error:string,summary:string,registry_revision:string,groups:list<FeatureGroup>,save_label:string,factory_label:string,factory_help:string,saving_label:string,saved_label:string,failed_label:string,reload_label:string} Prepared page; FeatureGroup is documented by the owning view renderer.
 */
function admin_features_view_model(
    array $groupedDefinitions,
    array $healthSnapshot,
    array $summary,
    string $notice,
    string $saveError,
): array {
    $groups = [];
    $changed = 0;
    foreach ($groupedDefinitions as $groupKey => $group) {
        $features = [];
        $editableCount = 0;
        $configuredCount = 0;
        foreach ($group['features'] as $featureKey => $definition) {
            $feature = admin_feature_card_view_model(
                (string) $featureKey,
                $definition,
                $healthSnapshot[$featureKey] ?? [],
            );
            $features[] = $feature;
            $changed += $feature['changed'] ? 1 : 0;
            if ($feature['editable']) {
                $editableCount++;
                $configuredCount += $feature['configured'] ? 1 : 0;
            }
        }
        $groups[] = [
            'key' => (string) $groupKey,
            'label' => $group['group']['label'],
            'description' => $group['group']['description'],
            'features' => $features,
            'editable_count' => $editableCount,
            'configured_count' => $configuredCount,
            'toggle_label' => t(
                'admin.features.toggle_group',
                'Toggle group: {group}',
                ['group' => $group['group']['label']],
            ),
            'columns' => [
                'feature' => t('admin.features.column.feature', 'Feature'),
                'factory' => t('admin.features.column.factory', 'Factory'),
            ],
        ];
    }

    return [
        'title' => t('admin.features.title', 'Feature settings'),
        'description' => t(
            'admin.features.autosave_hint',
            'Changes save immediately. Turning features off preserves their data.',
        ),
        'endpoint' => url_for('admin_features'),
        'notice' => $notice,
        'save_error' => $saveError,
        'summary' => t(
            'admin.features.compact_summary',
            '{enabled}/{total} active · {changed} changed',
            [
                'enabled' => (string) $summary['enabled'],
                'total' => (string) $summary['total'],
                'changed' => (string) $changed,
            ],
        ),
        'registry_revision' => feature_capability_registry_revision(),
        'groups' => $groups,
        'save_label' => t('admin.features.save', 'Save feature settings'),
        'factory_label' => t(
            'admin.features.factory_reset',
            'Reset to factory defaults',
        ),
        'factory_help' => t(
            'admin.features.factory_reset_help',
            'Restore all feature switches to new-installation defaults. Feature data and specialized settings are preserved.',
        ),
        'saving_label' => t('admin.features.saving', 'Saving…'),
        'saved_label' => t('admin.features.saved', 'Saved'),
        'failed_label' => t(
            'admin.features.save_unknown',
            'Save could not be verified. Reload state before making another change.',
        ),
        'reload_label' => t('admin.features.reload_state', 'Reload state'),
    ];
}

/**
 * Prepare a compact feature row with factory comparison and bounded health.
 *
 * @param string $featureKey Feature registry key.
 * @phpstan-type FeatureDefinition array{group:string,label:string,description:string,default_enabled?:bool,fresh_default_enabled?:bool,source:array{type:string,key?:string,adapter?:string},dependencies:list<string>,routes:list<string>,route_prefixes:list<string>,editable_on_features:bool,data_disable_policy:string,behavior_tags:list<string>,settings_route:string,settings_params:array<string,string|int|bool>,settings_fragment:string}
 * @phpstan-type FeatureHealth array{configured:bool,effective:bool,blockers:list<string>,source_type:string,settings_route:string,settings_params:array<string,string>,settings_fragment:string,schema_state:?string}
 * @param FeatureDefinition $definition Canonical capability metadata.
 * @param FeatureHealth $health Configured/effective health and prepared settings destination.
 * @return FeatureRow Prepared row; its complete fields are documented by view_render_admin_feature_card().
 */
function admin_feature_card_view_model(
    string $featureKey,
    array $definition,
    array $health,
): array {
    $configuredEnabled = !empty($health['configured']);
    $factoryEnabled = (bool) ($definition['fresh_default_enabled']
        ?? feature_capability_default_enabled($featureKey));
    $effectiveEnabled = !empty($health['effective']);
    $editable = !empty($definition['editable_on_features']);
    $blockers = array_values(array_filter(array_map(
        'strval',
        (array) ($health['blockers'] ?? []),
    )));
    $contextHint = feature_capability_admin_context_hint($featureKey);
    $cardClass = $configuredEnabled ? ' is-enabled' : ' is-disabled';
    if ($configuredEnabled && !$effectiveEnabled) {
        $cardClass .= ' is-ineffective';
    }
    if (!$editable) {
        $cardClass .= ' is-read-only';
    }

    $badges = [];
    foreach (array_values(array_unique(array_filter(array_map(
        'strval',
        (array) ($definition['behavior_tags'] ?? []),
    )))) as $tag) {
        $badges[] = match ($tag) {
            'public' => t('admin.features.badge.public', 'Public'),
            'admin-only' => t('admin.features.badge.admin_only', 'Admin only'),
            'writes-files' => t(
                'admin.features.badge.writes_files',
                'Writes files',
            ),
            'background-work' => t(
                'admin.features.badge.background_work',
                'Background work',
            ),
            'outbound-network' => t(
                'admin.features.badge.outbound_network',
                'Outbound network',
            ),
            'privacy' => t('admin.features.badge.privacy', 'Privacy'),
            'diagnostic' => t('admin.features.badge.diagnostic', 'Diagnostic'),
            'destructive-maintenance' => t(
                'admin.features.badge.destructive_maintenance',
                'Destructive maintenance',
            ),
            default => str_replace('-', ' ', ucfirst($tag)),
        };
    }

    $blockerLabel = '';
    if ($blockers !== []) {
        $blockerLabels = [];
        foreach ($blockers as $blockerKey) {
            $blockerDefinition = feature_capability_definition($blockerKey);
            $blockerLabels[] = is_array($blockerDefinition)
                ? (string) ($blockerDefinition['label'] ?? $blockerKey)
                : $blockerKey;
        }
        $blockerLabel = t(
            'admin.features.blocked_by',
            'Currently unavailable because this dependency is disabled: {feature}',
            ['feature' => implode(', ', $blockerLabels)],
        );
    }

    $settingsUrl = '';
    $settingsRoute = trim((string) ($health['settings_route'] ?? ''));
    if ($settingsRoute !== '') {
        $settingsParams = is_array($health['settings_params'] ?? null)
            ? $health['settings_params']
            : [];
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
        'factory' => $factoryEnabled,
        'factory_label' => $factoryEnabled ? $enabledLabel : $disabledLabel,
        'changed' => $configuredEnabled !== $factoryEnabled,
        'comparison_label' => $configuredEnabled !== $factoryEnabled
            ? t('admin.features.changed', 'Changed')
            : t('admin.features.factory_match', 'Default'),
        'help_label' => t('admin.features.help', 'Details: {feature}', [
            'feature' => (string) ($definition['label'] ?? $featureKey),
        ]),
        'blocked_state' => t('admin.features.blocked', 'Blocked'),
        'configured' => $configuredEnabled,
        'effective' => $effectiveEnabled,
        'editable' => $editable,
        'card_class' => $cardClass,
        'badges' => $badges,
        'configured_state_label' => t(
            'admin.features.configured_state',
            'Configured: {state}',
            ['state' => $configuredEnabled ? $enabledLabel : $disabledLabel],
        ),
        'effective_state_label' => t(
            'admin.features.effective_state',
            'Effective: {state}',
            ['state' => $effectiveEnabled ? $enabledLabel : $disabledLabel],
        ),
        'storage_source_label' => t(
            'admin.features.storage_source',
            'Storage: {source}',
            ['source' => $sourceType],
        ),
        'schema_state_label' => is_string($schemaState) && $schemaState !== ''
            ? t('admin.features.schema_state', 'Schema: {state}', [
                'state' => $schemaState,
            ])
            : '',
        'blocker_label' => $blockerLabel,
        'context_hint' => is_string($contextHint) ? $contextHint : '',
        'settings_url' => $settingsUrl,
        'settings_panel' =>
            $featureKey === 'cooperative_galleries' && $effectiveEnabled,
        'settings_label' => t(
            'admin.features.specialized_settings',
            'Open specialized settings',
        ),
        'effective_state' => $effectiveEnabled ? $enabledLabel : $disabledLabel,
    ];
}
