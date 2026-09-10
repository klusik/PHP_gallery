<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/feature_flags/admin.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides Admin-facing feature summary, save, and grouped presentation helpers.
 *
 * Responsibilities:
 *   - Count effectively enabled and disabled capabilities for the Admin feature page
 *   - Persist the complete feature checkbox payload through the canonical setters
 *   - Group canonical definitions using the existing localized feature groups
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
 *   - Loaded by app/services/feature_flags.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/feature_flags.php.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Services;

/**
 * Return a deterministic revision token for the editable capability surface.
 *
 * The token protects an Admin form opened before a deployment from silently
 * applying absence-as-disabled semantics to capabilities added by that deployment.
 *
 * @return string Stable registry revision token.
 */
function feature_capability_registry_revision(): string
{
    $shape = [];
    foreach (feature_capability_definitions() as $key => $definition) {
        $shape[$key] = [
            'editable' => !empty($definition['editable_on_features']),
            'source' => $definition['source'] ?? null,
            'dependencies' => array_values((array) ($definition['dependencies'] ?? [])),
        ];
    }
    return substr(hash('sha256', json_encode($shape, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''), 0, 20);
}

/**
 * Return direct dependency blockers that currently make one configured capability ineffective.
 *
 * @param string $key Canonical capability key.
 * @return list<string> Blocking capability keys.
 */
function feature_capability_effective_blockers(string $key): array
{
    $definition = feature_capability_definition($key);
    if ($definition === null || !feature_capability_configured_enabled($key)) {
        return [];
    }

    $blocked = [];
    foreach ((array) ($definition['dependencies'] ?? []) as $dependency) {
        $dependencyKey = feature_flag_normalize_key((string) $dependency);
        if ($dependencyKey !== '' && !feature_capability_effective_enabled($dependencyKey)) {
            $blocked[] = $dependencyKey;
        }
    }
    return array_values(array_unique($blocked));
}

/**
 * Return compact read-only subordinate state for the Admin capability dashboard.
 *
 * This is contextual information only. Persistence remains owned by the existing
 * specialized setting or domain service.
 *
 * @param string $key Canonical capability key.
 * @return ?string Context hint or null when no useful subordinate state exists.
 */
function feature_capability_admin_context_hint(string $key): ?string
{
    return match (feature_flag_normalize_key($key)) {
        'public_search' => function_exists(__NAMESPACE__ . '\\public_home_search_enabled')
            ? (public_home_search_enabled() ? t('admin.features.context.site_enabled', 'Site setting: Enabled') : t('admin.features.context.site_disabled', 'Site setting: Disabled'))
            : null,
        'telemetry' => function_exists(__NAMESPACE__ . '\\telemetry_setting_enabled')
            ? (telemetry_setting_enabled('telemetry_enabled', '0') ? t('admin.features.context.collection_enabled', 'Collection: Enabled') : t('admin.features.context.collection_disabled', 'Collection: Disabled'))
            : null,
        'viewer_accounts' => function_exists(__NAMESPACE__ . '\\viewer_registration_mode')
            ? t('admin.features.context.viewer_mode', 'Viewer mode: {mode}', ['mode' => (string) viewer_registration_mode()])
            : null,
        default => null,
    };
}

/**
 * Return a bounded Admin health snapshot for the canonical capability registry.
 *
 * Schema state is accepted only from a caller-provided map. This helper never
 * initiates schema inspection itself, so opening Admin > Features cannot turn
 * into a fan-out of optional information_schema probes. Callers that already
 * performed a lazy schema-health check may pass canonical capability states.
 *
 * The snapshot deliberately contains no filesystem paths, credentials, tokens,
 * endpoint URLs, or raw setting values.
 *
 * @param ?array<string,array<string,mixed>|string> $schemaStatesByCapability Optional already-resolved schema states keyed by canonical capability.
 * @return array<string,array<string,mixed>> Bounded health rows keyed by capability.
 */
function feature_capability_admin_health_snapshot(?array $schemaStatesByCapability = null): array
{
    $allowedSchemaStates = ['available', 'missing', 'unknown', 'disabled'];
    $snapshot = [];

    foreach (feature_capability_definitions() as $key => $definition) {
        $configured = feature_capability_configured_enabled($key);
        $effective = feature_capability_effective_enabled($key);
        $source = is_array($definition['source'] ?? null) ? $definition['source'] : [];
        $sourceType = (string) ($source['type'] ?? 'unknown');
        if (!in_array($sourceType, ['feature_flag', 'app_setting', 'domain_adapter', 'derived'], true)) {
            $sourceType = 'unknown';
        }

        $schemaState = null;
        if ($effective && is_array($schemaStatesByCapability) && array_key_exists($key, $schemaStatesByCapability)) {
            $rawSchema = $schemaStatesByCapability[$key];
            $candidate = is_array($rawSchema) ? (string) ($rawSchema['state'] ?? '') : (string) $rawSchema;
            if (in_array($candidate, $allowedSchemaStates, true)) {
                $schemaState = $candidate;
            }
        }

        $settingsRoute = trim((string) ($definition['settings_route'] ?? ''));
        if ($settingsRoute !== '' && preg_match('/^[a-z0-9_]+$/D', $settingsRoute) !== 1) {
            $settingsRoute = '';
        }
        $settingsParams = [];
        foreach (array_slice((array) ($definition['settings_params'] ?? []), 0, 8, true) as $paramKey => $paramValue) {
            $safeKey = trim((string) $paramKey);
            if ($safeKey === '' || preg_match('/^[a-z0-9_]+$/D', $safeKey) !== 1 || !is_scalar($paramValue)) {
                continue;
            }
            $settingsParams[$safeKey] = (string) $paramValue;
        }
        $settingsFragment = trim((string) ($definition['settings_fragment'] ?? ''));
        if ($settingsFragment !== '' && preg_match('/^[a-zA-Z0-9_-]+$/D', $settingsFragment) !== 1) {
            $settingsFragment = '';
        }

        $snapshot[$key] = [
            'configured' => $configured,
            'effective' => $effective,
            'blockers' => feature_capability_effective_blockers($key),
            'source_type' => $sourceType,
            'settings_route' => $settingsRoute,
            'settings_params' => $settingsParams,
            'settings_fragment' => $settingsFragment,
            'schema_state' => $schemaState,
        ];
    }

    return $snapshot;
}

/**
 * Return effective enabled and disabled counts for the feature registry.
 *
 * @return array{enabled:int,disabled:int,total:int} Structured result data for the caller.
 */
function feature_flag_summary_counts(): array
{
    $enabled = 0;
    $disabled = 0;
    foreach (array_keys(feature_flag_definitions()) as $key) {
        if (feature_capability_effective_enabled($key)) {
            $enabled++;
        } else {
            $disabled++;
        }
    }
    return [
        'enabled' => $enabled,
        'disabled' => $disabled,
        'total' => $enabled + $disabled,
    ];
}

/**
 * Save all feature switches from the Admin form payload.
 *
 * @param array $post Submitted Admin form values.
 * @return array{enabled:int,disabled:int,total:int,changed_count:int,changes:list<array<string,mixed>>,registry_revision:string} Save summary.
 */
function save_feature_flags_from_post(array $post): array
{
    $submittedRevision = trim((string) ($post['feature_registry_revision'] ?? ''));
    if ($submittedRevision === '' || !hash_equals(feature_capability_registry_revision(), $submittedRevision)) {
        throw new \RuntimeException(t('admin.features.error.stale_form', 'The feature registry changed while this form was open. Reload the page and review the current capabilities before saving.'));
    }

    $enabledKeys = [];
    foreach ((array) ($post['enabled_features'] ?? []) as $key) {
        $normalizedKey = feature_flag_normalize_key((string) $key);
        if (feature_flag_exists($normalizedKey)) {
            $enabledKeys[$normalizedKey] = true;
        }
    }

    $changes = [];
    foreach (feature_capability_definitions() as $key => $definition) {
        if (empty($definition['editable_on_features'])) {
            continue;
        }
        $oldConfigured = feature_capability_configured_enabled($key);
        $newConfigured = isset($enabledKeys[$key]);
        if ($oldConfigured === $newConfigured) {
            continue;
        }
        if (!set_feature_capability_enabled($key, $newConfigured)) {
            throw new \RuntimeException(t('admin.features.error.save_failed', 'Feature settings could not be saved. Reload the page and try again.'));
        }
        $changes[] = [
            'key' => $key,
            'old_configured' => $oldConfigured,
            'new_configured' => $newConfigured,
            'effective' => feature_capability_effective_enabled($key),
            'source_surface' => 'admin_features',
        ];
    }

    return feature_flag_summary_counts() + [
        'changed_count' => count($changes),
        'changes' => array_slice($changes, 0, 50),
        'registry_revision' => feature_capability_registry_revision(),
    ];
}
/**
 * Return feature definitions grouped for the Admin feature settings page.
 *
 * @return array<string,array{group:array<string,string>,features:array<string,array<string,mixed>>}> Grouped feature definitions.
 */
function grouped_feature_flag_definitions(): array
{
    $groups = [];
    foreach (feature_flag_groups() as $groupKey => $groupDefinition) {
        $groups[$groupKey] = [
            'group' => $groupDefinition,
            'features' => [],
        ];
    }

    foreach (feature_flag_definitions() as $key => $definition) {
        $groupKey = (string) ($definition['group'] ?? 'admin_tools');
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'group' => [
                    'label' => $groupKey,
                    'description' => '',
                ],
                'features' => [],
            ];
        }
        $groups[$groupKey]['features'][$key] = $definition;
    }

    return array_filter($groups, static fn (array $group): bool => $group['features'] !== []);
}
