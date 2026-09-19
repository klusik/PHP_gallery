<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/feature_flags/routes.php
 * Module Type: Service
 *
 * Purpose:
 *   Owns capability route ownership, effective route gating, and disabled-route response decisions.
 *
 * Responsibilities:
 *   - Derive explicit route ownership from canonical capability metadata
 *   - Resolve prefix-owned route families without duplicating route maps
 *   - Support single, all-of, and any-of route requirements
 *   - Gate routes through dependency-aware effective capability state
 *   - Build consistent non-advertising public or informative Admin disabled-route decisions
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
 * Return centralized multi-capability route requirements.
 *
 * Simple single-capability ownership remains declared on capability definitions.
 * Only routes whose authorization cannot be represented by one owner belong here.
 *
 * @return array<string,array{type:string,capabilities:list<string>}> Route requirements.
 */
function feature_capability_multi_route_requirements(): array
{
    return [
        'gallery_map_data' => [
            'type' => 'any_of',
            'capabilities' => ['gallery_maps', 'flight_maps'],
        ],
        'smart_gallery_lightbox_data' => [
            'type' => 'all_of',
            'capabilities' => ['smart_galleries', 'lightbox_modes'],
        ],
        'smart_gallery_map_data' => [
            'type' => 'all_of',
            'capabilities' => ['smart_galleries', 'gallery_maps'],
        ],
        'download_smart_gallery_start' => [
            'type' => 'all_of',
            'capabilities' => ['smart_galleries', 'downloads'],
        ],
        'download_smart_gallery' => [
            'type' => 'all_of',
            'capabilities' => ['smart_galleries', 'downloads'],
        ],
        'download_smart_gallery_manifest' => [
            'type' => 'all_of',
            'capabilities' => ['smart_galleries', 'downloads'],
        ],
        'download_smart_gallery_file' => [
            'type' => 'all_of',
            'capabilities' => ['smart_galleries', 'downloads'],
        ],
    ];
}

/**
 * Normalize one route requirement to the canonical policy shape.
 *
 * @param mixed $requirement Raw route requirement.
 * @return ?array{type:string,capabilities:list<string>} Canonical requirement or null when malformed.
 */
function feature_capability_normalize_route_requirement(mixed $requirement): ?array
{
    if (is_string($requirement)) {
        $key = feature_flag_normalize_key($requirement);
        return $key !== '' ? ['type' => 'single', 'capabilities' => [$key]] : null;
    }
    if (!is_array($requirement)) {
        return null;
    }

    $type = (string) ($requirement['type'] ?? '');
    if (!in_array($type, ['single', 'all_of', 'any_of'], true)) {
        return null;
    }

    $capabilities = [];
    foreach ((array) ($requirement['capabilities'] ?? []) as $key) {
        $normalized = feature_flag_normalize_key((string) $key);
        if ($normalized !== '') {
            $capabilities[$normalized] = true;
        }
    }
    $capabilities = array_keys($capabilities);
    if ($capabilities === [] || ($type === 'single' && count($capabilities) !== 1)) {
        return null;
    }

    return ['type' => $type, 'capabilities' => $capabilities];
}

/**
 * Return the canonical requirement for one route, or null for a core route.
 *
 * Exact multi-capability requirements win over simple ownership. Route prefixes
 * are resolved after exact route declarations.
 *
 * @param string $page Page number or page data.
 * @return ?array{type:string,capabilities:list<string>} Canonical route requirement.
 */
function feature_capability_route_requirement(string $page): ?array
{
    $page = trim($page);
    if ($page === '') {
        return null;
    }

    $multi = feature_capability_multi_route_requirements()[$page] ?? null;
    if ($multi !== null) {
        return feature_capability_normalize_route_requirement($multi);
    }

    foreach (feature_capability_definitions() as $featureKey => $definition) {
        foreach ((array) ($definition['routes'] ?? []) as $route) {
            if ($page === trim((string) $route)) {
                return ['type' => 'single', 'capabilities' => [(string) $featureKey]];
            }
        }
    }

    foreach (feature_capability_definitions() as $featureKey => $definition) {
        foreach ((array) ($definition['route_prefixes'] ?? []) as $prefix) {
            $prefixKey = (string) $prefix;
            if ($prefixKey !== '' && str_starts_with($page, $prefixKey)) {
                return ['type' => 'single', 'capabilities' => [(string) $featureKey]];
            }
        }
    }

    return null;
}

/**
 * Return whether one canonical route requirement is effectively satisfied.
 *
 * @param array{type:string,capabilities:list<string>} $requirement Canonical requirement.
 * @return bool True when route dispatch is allowed by capability policy.
 */
function feature_capability_route_requirement_allowed(array $requirement): bool
{
    $normalized = feature_capability_normalize_route_requirement($requirement);
    if ($normalized === null) {
        return false;
    }

    $states = array_map(
        static fn (string $key): bool => feature_capability_effective_enabled($key),
        $normalized['capabilities']
    );

    return match ($normalized['type']) {
        'single', 'all_of' => !in_array(false, $states, true),
        'any_of' => in_array(true, $states, true),
        default => false,
    };
}

/**
 * Return the route-to-feature map used by legacy callers.
 *
 * Multi-capability routes are intentionally omitted because reducing an any-of or
 * all-of requirement to one owner would create a second, misleading policy model.
 *
 * @return array<string,string> Structured result data for the caller.
 */
function feature_flag_route_map(): array
{
    $map = [];
    foreach (feature_capability_definitions() as $featureKey => $definition) {
        foreach ((array) ($definition['routes'] ?? []) as $route) {
            $routeKey = trim((string) $route);
            if ($routeKey !== '' && !isset(feature_capability_multi_route_requirements()[$routeKey])) {
                $map[$routeKey] = (string) $featureKey;
            }
        }
    }
    return $map;
}

/**
 * Return the feature key that owns one route, or null for core or multi-capability routes.
 *
 * @param string $page Page number or page data.
 * @return ?string Text result for the caller.
 */
function feature_flag_for_route(string $page): ?string
{
    $requirement = feature_capability_route_requirement($page);
    if ($requirement === null || $requirement['type'] !== 'single') {
        return null;
    }
    return $requirement['capabilities'][0] ?? null;
}

/**
 * Return true when the current route can be dispatched.
 *
 * @param string $page Page number or page data.
 * @return bool True when the condition matches.
 */
function feature_flag_route_enabled(string $page): bool
{
    $requirement = feature_capability_route_requirement($page);
    return $requirement === null || feature_capability_route_requirement_allowed($requirement);
}

/**
 * Return a bounded human-readable label for one disabled route requirement.
 *
 * @param array{type:string,capabilities:list<string>} $requirement Canonical requirement.
 * @return string User-facing capability label.
 */
function feature_capability_route_requirement_label(array $requirement): string
{
    $labels = [];
    foreach ($requirement['capabilities'] as $key) {
        $definition = feature_capability_definitions()[$key] ?? null;
        $labels[] = is_array($definition) ? (string) ($definition['label'] ?? $key) : $key;
    }
    $labels = array_values(array_filter($labels, static fn (string $label): bool => $label !== ''));
    if ($labels === []) {
        return 'Optional feature';
    }
    if (count($labels) === 1) {
        return $labels[0];
    }
    $separator = $requirement['type'] === 'any_of' ? ' or ' : ' and ';
    return implode($separator, $labels);
}

/**
 * Build the disabled-route response decision for the HTTP dispatch boundary.
 *
 * Anonymous and non-Admin callers receive a non-advertising not-found decision.
 * Authenticated administrators receive an actionable disabled-capability decision.
 * No headers, status codes, request globals, or presentation output are owned here.
 *
 * @param string $page Route identifier.
 * @param bool $wantsJson Whether the HTTP boundary selected JSON representation.
 * @param bool $isAdmin Whether the current authenticated principal is an administrator.
 * @return array{status:int,representation:string,headers:array<string,string>,payload:array<string,mixed>,title:string,message:string,admin:bool}
 */
function feature_flag_disabled_route_decision(string $page, bool $wantsJson, bool $isAdmin): array
{
    $requirement = feature_capability_route_requirement($page);
    $requirement ??= ['type' => 'single', 'capabilities' => []];
    $headers = [
        'X-Robots-Tag' => 'noindex, nofollow',
        'Cache-Control' => 'private, no-store, max-age=0',
    ];

    if (!$isAdmin) {
        return [
            'status' => 404,
            'representation' => $wantsJson ? 'json' : 'html',
            'headers' => $headers,
            'payload' => ['ok' => false, 'error' => 'not_found'],
            'title' => t('public.not_found_title', 'Not found'),
            'message' => t('public.not_found_message', 'The requested page was not found.'),
            'admin' => false,
        ];
    }

    $label = feature_capability_route_requirement_label($requirement);
    $message = t('admin.features.disabled_route_message', 'This feature is disabled in Admin > Features: {feature}', ['feature' => $label]);
    return [
        'status' => 403,
        'representation' => $wantsJson ? 'json' : 'html',
        'headers' => $headers,
        'payload' => [
            'ok' => false,
            'error' => 'feature_disabled',
            'features' => $requirement['capabilities'],
            'requirement' => $requirement['type'],
            'message' => $message,
        ],
        'title' => t('admin.features.disabled_title', 'Feature disabled'),
        'message' => $message,
        'admin' => true,
    ];
}
