<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_asset_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Locks the current compatibility relationship between the anonymous telemetry browser assets.
 *
 * Responsibilities:
 *   - Verify usage.js and telemetry.js remain byte-identical while both are shipped
 *   - Verify the PHP telemetry bootstrap loads only the canonical runtime asset usage.js
 *   - Prevent the two browser implementations from silently diverging
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - telemetry.js is retained as a compatibility copy; usage.js is the runtime entry point.
 */

declare(strict_types=1);

/** Throw when one telemetry asset compatibility contract fails. */
function telemetry_asset_contract_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
$usagePath = $root . '/public/assets/usage.js';
$compatibilityPath = $root . '/public/assets/telemetry.js';
$servicePath = $root . '/app/services/telemetry.php';

telemetry_asset_contract_assert(is_file($usagePath), 'Canonical telemetry runtime asset public/assets/usage.js must exist.');
telemetry_asset_contract_assert(is_file($compatibilityPath), 'Compatibility telemetry asset public/assets/telemetry.js must exist while the alias contract is active.');
telemetry_asset_contract_assert(
    hash_file('sha256', $usagePath) === hash_file('sha256', $compatibilityPath),
    'usage.js and telemetry.js must remain byte-identical while telemetry.js is shipped as a compatibility copy.'
);

$serviceSource = (string) file_get_contents($servicePath);
telemetry_asset_contract_assert(
    str_contains($serviceSource, "asset_url('assets/usage.js')"),
    'Telemetry bootstrap must load usage.js as the canonical runtime asset.'
);
telemetry_asset_contract_assert(
    !str_contains($serviceSource, "asset_url('assets/telemetry.js')"),
    'Telemetry bootstrap must not load the compatibility copy telemetry.js in parallel.'
);

$usageSource = (string) file_get_contents($usagePath);
telemetry_asset_contract_assert(
    str_contains($usageSource, "public.session.started")
        && str_contains($usageSource, "public.gallery.viewed")
        && str_contains($usageSource, "public.page.viewed"),
    'Canonical telemetry asset must retain the session-start and page-view event contract expected by server telemetry.'
);

echo "telemetry_asset_contract_test: ok\n";
