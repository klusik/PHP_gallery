<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/feature_flags/policy.php
 * Module Type: Service
 *
 * Purpose:
 *   Resolves configured and effective capability state and validates registry policy.
 *
 * Responsibilities:
 *   - Provide strict fail-closed canonical lookups and bounded unknown-key diagnostics
 *   - Resolve dependency-aware effective state without rewriting configured preferences
 *   - Persist canonical native feature-flag state through the existing app_settings owner
 *   - Validate groups, storage metadata, dependencies, defaults, and route ownership declarations
 *   - Preserve legacy feature_flag_* compatibility behavior at the extension boundary
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
 * Report one unknown canonical capability lookup without allowing log flooding.
 *
 * Legacy feature_flag_enabled() deliberately does not call this helper because
 * unknown extension keys remain fail-open until that compatibility boundary is
 * intentionally deprecated.
 *
 * @param string $key Normalized capability key.
 */
function feature_capability_report_unknown(string $key): void
{
    static $reported = [];
    static $reportedCount = 0;

    $safeKey = substr(feature_flag_normalize_key($key), 0, 80);
    if ($safeKey === '') {
        $safeKey = '(empty)';
    }
    if (isset($reported[$safeKey]) || $reportedCount >= 8) {
        return;
    }

    $reported[$safeKey] = true;
    $reportedCount++;
    error_log('[PHP Gallery] Unknown canonical capability key: ' . $safeKey);
}

/**
 * Return true when the requested canonical capability exists.
 *
 * @param string $key Lookup key.
 * @return bool True when the capability is registered.
 */
function feature_capability_exists(string $key): bool
{
    return array_key_exists(feature_flag_normalize_key($key), feature_capability_definitions());
}

/**
 * Return one canonical capability definition.
 *
 * Unknown keys fail closed and emit one bounded request-local diagnostic.
 *
 * @param string $key Lookup key.
 * @return ?array<string,mixed> Canonical definition or null for an unknown key.
 */
function feature_capability_definition(string $key): ?array
{
    $key = feature_flag_normalize_key($key);
    $definition = feature_capability_definitions()[$key] ?? null;
    if (!is_array($definition)) {
        feature_capability_report_unknown($key);
        return null;
    }
    return $definition;
}

/**
 * Return the configured default for one canonical capability.
 *
 * Unknown canonical keys fail closed. The legacy feature_flag_default_enabled()
 * wrapper keeps its historical fail-open behavior separately.
 *
 * @param string $key Lookup key.
 * @return bool Default configured state.
 */
function feature_capability_default_enabled(string $key): bool
{
    $definition = feature_capability_definition($key);
    if ($definition === null) {
        return false;
    }
    return !array_key_exists('default_enabled', $definition) || $definition['default_enabled'] === true;
}

/**
 * Return the persisted administrator preference for one capability.
 *
 * Storage dispatch remains centralized so native feature flags, app settings,
 * and domain-owned settings expose the same configured-state contract.
 *
 * @param string $key Lookup key.
 * @return bool Configured administrator state.
 */
function feature_capability_configured_enabled(string $key): bool
{
    $key = feature_flag_normalize_key($key);
    $definition = feature_capability_definition($key);
    if ($definition === null) {
        return false;
    }

    return feature_capability_source_read($key, $definition);
}

/**
 * Resolve effective capability state through the dependency graph.
 *
 * Configured state is never rewritten when a dependency is disabled. Re-enabling
 * the dependency therefore restores the dependent capability automatically.
 * Runtime recursion is cycle-safe even though registry validation also rejects
 * dependency cycles.
 *
 * @param string $key Lookup key.
 * @param array<string,bool> $resolving Internal recursion guard.
 * @return bool Effective state after dependencies.
 */
function feature_capability_effective_enabled(string $key, array $resolving = []): bool
{
    $key = feature_flag_normalize_key($key);
    $definition = feature_capability_definition($key);
    if ($definition === null || isset($resolving[$key])) {
        return false;
    }
    if (!feature_capability_configured_enabled($key)) {
        return false;
    }

    $resolving[$key] = true;
    foreach ((array) ($definition['dependencies'] ?? []) as $dependency) {
        $dependencyKey = feature_flag_normalize_key((string) $dependency);
        if ($dependencyKey === '' || !feature_capability_effective_enabled($dependencyKey, $resolving)) {
            return false;
        }
    }

    return true;
}

/**
 * Persist one canonical capability through its registered storage owner.
 *
 * Source dispatch is explicit so app-setting and domain adapters cannot bypass
 * existing domain setters.
 *
 * @param string $key Lookup key.
 * @param bool $enabled Configured administrator state.
 * @return bool True when the write was accepted.
 */
function set_feature_capability_enabled(string $key, bool $enabled): bool
{
    $key = feature_flag_normalize_key($key);
    $definition = feature_capability_definition($key);
    if ($definition === null) {
        return false;
    }

    if (empty($definition['editable_on_features']) && ($definition['source']['type'] ?? '') === 'derived') {
        return false;
    }

    return feature_capability_source_write($key, $definition, $enabled);
}

/**
 * Seed definition-declared fresh-install capability defaults exactly once.
 *
 * This function is called only from the first-install setup lifecycle after
 * migrations and after confirming that no administrator account exists. Normal
 * upgrades never call it. Existing explicit values are preserved defensively.
 *
 * @param array<string,array<string,mixed>>|null $definitions Definitions to seed.
 * @return list<string> Canonical capability keys seeded during this call.
 */
function feature_capability_seed_fresh_install_defaults(?array $definitions = null): array
{
    $markerKey = 'feature_capability.fresh_defaults_seeded';
    if (app_setting($markerKey, null) !== null) {
        return [];
    }

    $definitions ??= feature_capability_definitions();
    $seeded = [];
    $complete = true;
    foreach ($definitions as $key => $definition) {
        $key = feature_flag_normalize_key((string) $key);
        if ($key === '' || !is_array($definition) || !array_key_exists('fresh_default_enabled', $definition)) {
            continue;
        }
        if (feature_capability_source_has_explicit_value($key, $definition)) {
            continue;
        }
        if (feature_capability_source_write($key, $definition, (bool) $definition['fresh_default_enabled'])) {
            $seeded[] = $key;
        } else {
            $complete = false;
        }
    }

    if ($complete) {
        set_app_setting($markerKey, '1');
    }
    return $seeded;
}

/**
 * Validate one capability registry without constructing runtime schema state.
 *
 * The validator is intentionally pure with respect to persistence and database
 * schema. It is suitable for regression tests and future startup diagnostics.
 *
 * @param array<string,array<string,mixed>>|null $definitions Definitions to validate.
 * @param array<string,array<string,string>>|null $groups Group metadata to validate against.
 * @return list<string> Validation errors. An empty list means the registry is valid.
 */
function feature_capability_validate_registry(?array $definitions = null, ?array $groups = null): array
{
    $definitions ??= feature_capability_definitions();
    $groups ??= feature_flag_groups();
    $errors = [];
    $routeOwners = [];
    $prefixOwners = [];

    foreach ($definitions as $key => $definition) {
        $normalizedKey = feature_flag_normalize_key((string) $key);
        if ($normalizedKey === '' || $normalizedKey !== (string) $key) {
            $errors[] = 'invalid_key:' . (string) $key;
            continue;
        }
        if (!is_array($definition)) {
            $errors[] = 'invalid_definition:' . $normalizedKey;
            continue;
        }

        $group = (string) ($definition['group'] ?? '');
        if ($group === '' || !array_key_exists($group, $groups)) {
            $errors[] = 'unknown_group:' . $normalizedKey . ':' . $group;
        }

        if (array_key_exists('default_enabled', $definition) && !is_bool($definition['default_enabled'])) {
            $errors[] = 'invalid_default:' . $normalizedKey;
        }

        $source = $definition['source'] ?? null;
        $sourceType = is_array($source) ? (string) ($source['type'] ?? '') : '';
        if (!is_array($source) || $sourceType === '') {
            $errors[] = 'invalid_source:' . $normalizedKey;
        } elseif (!in_array($sourceType, ['feature_flag', 'app_setting', 'domain_adapter', 'derived'], true)) {
            $errors[] = 'unsupported_source:' . $normalizedKey . ':' . $sourceType;
        } elseif ($sourceType === 'app_setting' && trim((string) ($source['key'] ?? '')) === '') {
            $errors[] = 'invalid_app_setting_source:' . $normalizedKey;
        } elseif ($sourceType === 'domain_adapter') {
            $adapterId = feature_flag_normalize_key((string) ($source['adapter'] ?? ''));
            if ($adapterId === '' || !array_key_exists($adapterId, feature_capability_domain_adapters())) {
                $errors[] = 'unknown_domain_adapter:' . $normalizedKey . ':' . $adapterId;
            }
        } elseif ($sourceType === 'derived' && !empty($definition['editable_on_features'])) {
            $errors[] = 'editable_derived_source:' . $normalizedKey;
        }

        if (array_key_exists('fresh_default_enabled', $definition)) {
            if (!is_bool($definition['fresh_default_enabled'])) {
                $errors[] = 'invalid_fresh_default:' . $normalizedKey;
            } elseif (!in_array($sourceType, ['feature_flag', 'app_setting', 'domain_adapter'], true)) {
                $errors[] = 'unwritable_fresh_default_source:' . $normalizedKey . ':' . $sourceType;
            }
        }

        $dependencies = $definition['dependencies'] ?? [];
        if (!is_array($dependencies)) {
            $errors[] = 'invalid_dependencies:' . $normalizedKey;
            $dependencies = [];
        }
        foreach ($dependencies as $dependency) {
            $dependencyKey = feature_flag_normalize_key((string) $dependency);
            if ($dependencyKey === $normalizedKey) {
                $errors[] = 'self_dependency:' . $normalizedKey;
            } elseif ($dependencyKey === '' || !array_key_exists($dependencyKey, $definitions)) {
                $errors[] = 'unknown_dependency:' . $normalizedKey . ':' . $dependencyKey;
            }
        }

        $routes = $definition['routes'] ?? [];
        if (!is_array($routes)) {
            $errors[] = 'invalid_routes:' . $normalizedKey;
            $routes = [];
        }
        foreach ($routes as $route) {
            $routeKey = trim((string) $route);
            if ($routeKey === '' || preg_match('/^[a-z0-9_]+$/D', $routeKey) !== 1) {
                $errors[] = 'invalid_route:' . $normalizedKey;
                continue;
            }
            if (isset($routeOwners[$routeKey]) && $routeOwners[$routeKey] !== $normalizedKey) {
                $errors[] = 'conflicting_route_owner:' . $routeKey . ':' . $routeOwners[$routeKey] . ':' . $normalizedKey;
                continue;
            }
            $routeOwners[$routeKey] = $normalizedKey;
        }

        $prefixes = $definition['route_prefixes'] ?? [];
        if (!is_array($prefixes)) {
            $errors[] = 'invalid_route_prefixes:' . $normalizedKey;
            $prefixes = [];
        }
        foreach ($prefixes as $prefix) {
            $prefixKey = trim((string) $prefix);
            if ($prefixKey === '' || preg_match('/^[a-z0-9_]+$/D', $prefixKey) !== 1) {
                $errors[] = 'invalid_route_prefix:' . $normalizedKey;
                continue;
            }
            if (isset($prefixOwners[$prefixKey]) && $prefixOwners[$prefixKey] !== $normalizedKey) {
                $errors[] = 'conflicting_route_prefix_owner:' . $prefixKey . ':' . $prefixOwners[$prefixKey] . ':' . $normalizedKey;
                continue;
            }
            $prefixOwners[$prefixKey] = $normalizedKey;
        }
    }

    foreach ($routeOwners as $routeKey => $routeOwner) {
        foreach ($prefixOwners as $prefixKey => $prefixOwner) {
            if ($routeOwner !== $prefixOwner && str_starts_with($routeKey, $prefixKey)) {
                $errors[] = 'conflicting_route_prefix_owner:' . $routeKey . ':' . $routeOwner . ':' . $prefixOwner;
            }
        }
    }
    $prefixKeys = array_keys($prefixOwners);
    foreach ($prefixKeys as $index => $prefixKey) {
        foreach (array_slice($prefixKeys, $index + 1) as $otherPrefixKey) {
            if ($prefixOwners[$prefixKey] !== $prefixOwners[$otherPrefixKey]
                && (str_starts_with($prefixKey, $otherPrefixKey) || str_starts_with($otherPrefixKey, $prefixKey))) {
                $errors[] = 'overlapping_route_prefixes:' . $prefixKey . ':' . $otherPrefixKey;
            }
        }
    }

    $states = [];
    $visit = function (string $key, array $path = []) use (&$visit, &$states, &$errors, $definitions): void {
        $state = $states[$key] ?? 0;
        if ($state === 2) {
            return;
        }
        if ($state === 1) {
            $errors[] = 'dependency_cycle:' . implode('>', array_merge($path, [$key]));
            return;
        }

        $states[$key] = 1;
        $definition = $definitions[$key] ?? null;
        if (is_array($definition) && is_array($definition['dependencies'] ?? null)) {
            foreach ($definition['dependencies'] as $dependency) {
                $dependencyKey = feature_flag_normalize_key((string) $dependency);
                if ($dependencyKey !== '' && array_key_exists($dependencyKey, $definitions)) {
                    $visit($dependencyKey, array_merge($path, [$key]));
                }
            }
        }
        $states[$key] = 2;
    };

    foreach (array_keys($definitions) as $key) {
        $normalizedKey = feature_flag_normalize_key((string) $key);
        if ($normalizedKey !== '' && array_key_exists($normalizedKey, $definitions)) {
            $visit($normalizedKey);
        }
    }

    $multiRequirements = function_exists(__NAMESPACE__ . '\\feature_capability_multi_route_requirements')
        ? feature_capability_multi_route_requirements()
        : [];
    foreach ($multiRequirements as $route => $requirement) {
        $routeKey = trim((string) $route);
        $normalized = function_exists(__NAMESPACE__ . '\\feature_capability_normalize_route_requirement')
            ? feature_capability_normalize_route_requirement($requirement)
            : null;
        if ($routeKey === '' || preg_match('/^[a-z0-9_]+$/D', $routeKey) !== 1 || $normalized === null) {
            $errors[] = 'invalid_multi_route_requirement:' . $routeKey;
            continue;
        }
        foreach ($normalized['capabilities'] as $capabilityKey) {
            if (!array_key_exists($capabilityKey, $definitions)) {
                $errors[] = 'unknown_route_requirement:' . $routeKey . ':' . $capabilityKey;
            }
        }
        if (isset($routeOwners[$routeKey]) && !in_array($routeOwners[$routeKey], $normalized['capabilities'], true)) {
            $errors[] = 'conflicting_multi_route_owner:' . $routeKey . ':' . $routeOwners[$routeKey];
        }
        foreach ($prefixOwners as $prefixKey => $prefixOwner) {
            if (str_starts_with($routeKey, $prefixKey) && !in_array($prefixOwner, $normalized['capabilities'], true)) {
                $errors[] = 'conflicting_multi_route_prefix_owner:' . $routeKey . ':' . $prefixOwner;
            }
        }
    }

    return array_values(array_unique($errors));
}

/**
 * Return true when the requested feature exists in the registry.
 *
 * @param string $key Lookup key.
 * @return bool True when the condition matches.
 */
function feature_flag_exists(string $key): bool
{
    return feature_capability_exists($key);
}

/**
 * Return the default state for one registered feature.
 *
 * Established feature switches remain enabled by default when no explicit default is
 * declared. New or intentionally staged features can opt into a disabled default.
 *
 * @param string $key Lookup key.
 * @return bool Default enabled state.
 */
function feature_flag_default_enabled(string $key): bool
{
    $key = feature_flag_normalize_key($key);
    if (!feature_capability_exists($key)) {
        return true;
    }
    return feature_capability_default_enabled($key);
}

/**
 * Return true when a feature is globally enabled.
 *
 * Unknown feature keys deliberately return true so optional checks cannot break
 * older extension code or partially deployed files.
 *
 * @param string $key Lookup key.
 * @return bool True when the condition matches.
 */
function feature_flag_enabled(string $key): bool
{
    $key = feature_flag_normalize_key($key);
    if (!feature_capability_exists($key)) {
        return true;
    }
    return feature_capability_configured_enabled($key);
}

/**
 * Persist one feature switch.
 *
 * @param string $key Lookup key.
 * @param bool $enabled Enabled flag.
 */
function set_feature_flag_enabled(string $key, bool $enabled): void
{
    $key = feature_flag_normalize_key($key);
    if (!feature_capability_exists($key)) {
        return;
    }
    set_feature_capability_enabled($key, $enabled);
}
