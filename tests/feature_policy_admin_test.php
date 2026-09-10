<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/feature_policy_admin_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects capability-dashboard save and dependency presentation semantics.
 *
 * Responsibilities:
 *   - Verify unchanged configured state does not create persistence churn
 *   - Verify one explicit checkbox transition produces one bounded audit transition
 *   - Verify stale registry revisions reject absence-as-disabled form submissions
 *   - Verify dependency-disabled capabilities retain their configured preferences
 *   - Verify the Admin surface renders configured/effective state and registry revision metadata
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Services {
    /** Return a deterministic translated fallback for the isolated Admin policy test. */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        $text = is_string($fallback) ? $fallback : $key;
        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** Return one in-memory application setting for the isolated Admin policy test. */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $GLOBALS['feature_policy_admin_settings'] ?? [])
            ? (string) $GLOBALS['feature_policy_admin_settings'][$key]
            : $default;
    }

    /** Persist one in-memory application setting for the isolated Admin policy test. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['feature_policy_admin_settings'][$key] = $value;
    }
}

namespace {
    use function Gallery\Services\feature_capability_configured_enabled;
    use function Gallery\Services\feature_capability_definitions;
    use function Gallery\Services\feature_capability_effective_blockers;
    use function Gallery\Services\feature_capability_effective_enabled;
    use function Gallery\Services\feature_capability_registry_revision;
    use function Gallery\Services\feature_flag_summary_counts;
    use function Gallery\Services\save_feature_flags_from_post;
    use function Gallery\Services\set_feature_flag_enabled;

    /** Throw when one Admin capability-dashboard expectation fails. */
    function feature_policy_admin_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    /** Return all currently configured enabled editable capability keys. */
    function feature_policy_admin_enabled_payload(): array
    {
        $enabled = [];
        foreach (feature_capability_definitions() as $key => $definition) {
            if (!empty($definition['editable_on_features']) && feature_capability_configured_enabled((string) $key)) {
                $enabled[] = (string) $key;
            }
        }
        return $enabled;
    }

    $root = dirname(__DIR__);
    require_once $root . '/app/services/feature_flags.php';

    $GLOBALS['feature_policy_admin_settings'] = [];
    $revision = feature_capability_registry_revision();
    feature_policy_admin_assert(strlen($revision) === 20, 'Capability dashboard revision must remain compact and deterministic.');
    feature_policy_admin_assert($revision === feature_capability_registry_revision(), 'Capability dashboard revision must be deterministic within one registry shape.');

    $unchanged = save_feature_flags_from_post([
        'feature_registry_revision' => $revision,
        'enabled_features' => feature_policy_admin_enabled_payload(),
    ]);
    feature_policy_admin_assert(($unchanged['changed_count'] ?? -1) === 0, 'Submitting unchanged configured state must not rewrite capability settings.');
    feature_policy_admin_assert(($unchanged['changes'] ?? null) === [], 'Unchanged capability submission must produce an empty transition audit list.');

    $withoutDownloads = array_values(array_filter(
        feature_policy_admin_enabled_payload(),
        static fn (string $key): bool => $key !== 'downloads'
    ));
    $changed = save_feature_flags_from_post([
        'feature_registry_revision' => $revision,
        'enabled_features' => $withoutDownloads,
    ]);
    feature_policy_admin_assert(($changed['changed_count'] ?? 0) === 1, 'One explicit checkbox transition must create exactly one capability change.');
    feature_policy_admin_assert(
        ($changed['changes'][0] ?? null) === [
            'key' => 'downloads',
            'old_configured' => true,
            'new_configured' => false,
            'effective' => false,
            'source_surface' => 'admin_features',
        ],
        'Capability transition audit data must remain bounded to key, configured states, effective state, and source surface.'
    );

    $beforeStaleAttempt = $GLOBALS['feature_policy_admin_settings'];
    $staleRejected = false;
    try {
        save_feature_flags_from_post([
            'feature_registry_revision' => str_repeat('0', 20),
            'enabled_features' => [],
        ]);
    } catch (RuntimeException) {
        $staleRejected = true;
    }
    feature_policy_admin_assert($staleRejected, 'A stale capability-dashboard form must be rejected before absence-as-disabled processing.');
    feature_policy_admin_assert($GLOBALS['feature_policy_admin_settings'] === $beforeStaleAttempt, 'Rejecting a stale form must not mutate capability state.');

    $missingRevisionRejected = false;
    try {
        save_feature_flags_from_post([
            'enabled_features' => [],
        ]);
    } catch (RuntimeException) {
        $missingRevisionRejected = true;
    }
    feature_policy_admin_assert($missingRevisionRejected, 'A missing capability-dashboard revision must be rejected before absence-as-disabled processing.');
    feature_policy_admin_assert($GLOBALS['feature_policy_admin_settings'] === $beforeStaleAttempt, 'Rejecting a revision-less form must not mutate capability state.');

    // Dependency OFF makes Picture Game ineffective but must not rewrite its configured ON preference.
    set_feature_flag_enabled('image_voting', false);
    set_feature_flag_enabled('picture_game', true);
    $dependencyState = save_feature_flags_from_post([
        'feature_registry_revision' => $revision,
        'enabled_features' => feature_policy_admin_enabled_payload(),
    ]);
    feature_policy_admin_assert(($dependencyState['changed_count'] ?? -1) === 0, 'Dependency-only effective-state changes must not create configured-state writes.');
    feature_policy_admin_assert(feature_capability_configured_enabled('picture_game'), 'Picture Game configured preference must remain ON while Image Voting is OFF.');
    feature_policy_admin_assert(!feature_capability_effective_enabled('picture_game'), 'Picture Game effective state must remain OFF while Image Voting is OFF.');
    feature_policy_admin_assert(feature_capability_effective_blockers('picture_game') === ['image_voting'], 'Capability dashboard must identify Image Voting as the direct Picture Game blocker.');
    $effectiveSummary = feature_flag_summary_counts();
    feature_policy_admin_assert(
        $effectiveSummary['enabled'] === count(feature_policy_admin_enabled_payload()) - 1,
        'Capability summary must count dependency-blocked configured preferences as effectively disabled.'
    );

    $controllerSource = (string) file_get_contents($root . '/app/controllers/admin_features.php');
    feature_policy_admin_assert(
        str_contains($controllerSource, 'name="feature_registry_revision"')
            && str_contains($controllerSource, 'feature_capability_admin_health_snapshot')
            && str_contains($controllerSource, "health['configured']")
            && str_contains($controllerSource, "health['effective']")
            && str_contains($controllerSource, "definition['behavior_tags']")
            && str_contains($controllerSource, "health['settings_route']")
            && str_contains($controllerSource, "health['source_type']"),
        'Admin > Features must render registry revision, bounded configured/effective health, operational badges, storage source, and specialized-settings discovery.'
    );

    echo "Feature policy Admin tests passed.\n";
}
