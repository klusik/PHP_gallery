<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_search_default_policy_test.php
 * Module Type: Regression Test
 * Purpose: Verify the default-on public search preference without overwriting stored choices.
 * Responsibilities:
 *   - Cover unset preferences, explicit enable and disable, and capability gating
 *   - Confirm that reading defaults never writes installation preferences
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services {
    $GLOBALS['public_search_default_store'] = [];
    $GLOBALS['public_search_default_writes'] = [];
    $GLOBALS['public_search_default_capability'] = true;

    /**
     * Read the fixture's explicit preference or the supplied installation fallback.
     * @param string $key Canonical preference identifier.
     * @param string $fallback Missing-value fallback.
     * @return string Explicit value or fallback.
     */
    function app_setting(string $key, string $fallback): string
    {
        return $GLOBALS['public_search_default_store'][$key] ?? $fallback;
    }

    /**
     * Record an explicit administrator preference in the isolated fixture.
     * @param string $key Canonical preference identifier.
     * @param string $value Persisted scalar preference.
     * @return void
     */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['public_search_default_store'][$key] = $value;
        $GLOBALS['public_search_default_writes'][] = $key;
    }

    /**
     * Return the fixture capability's effective availability.
     * @param string $key Canonical capability identifier.
     * @return bool Whether the feature may run.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return $GLOBALS['public_search_default_capability'];
    }

    require_once dirname(__DIR__) . '/app/services/public_search.php';

    /**
     * Verify a public search default or preference boundary.
     * @param bool $condition Expected outcome.
     * @param string $message Failure description.
     * @return void
     */
    function public_search_default_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    public_search_default_assert(public_home_search_enabled(), 'An unset fresh or upgraded installation must default to visible search.');
    public_search_default_assert($GLOBALS['public_search_default_writes'] === [], 'Resolving a default must not seed or overwrite preferences.');
    set_public_home_search_enabled(false);
    $writes = $GLOBALS['public_search_default_writes'];
    public_search_default_assert(!public_home_search_enabled(), 'An explicit saved OFF must override the default.');
    public_search_default_assert($GLOBALS['public_search_default_writes'] === $writes, 'Reading saved OFF must not rewrite it.');
    set_public_home_search_enabled(true);
    public_search_default_assert(public_home_search_enabled(), 'An explicit saved ON must remain enabled.');
    $GLOBALS['public_search_default_capability'] = false;
    public_search_default_assert(!public_home_search_enabled(), 'An unavailable capability must still gate explicit ON.');
    public_search_default_assert($GLOBALS['public_search_default_store'][PUBLIC_HOME_SEARCH_SETTING] === '1', 'Capability OFF must preserve the saved display preference.');
    echo "Public search default policy tests passed.\n";
}
