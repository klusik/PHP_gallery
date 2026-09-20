<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect progressive-search diagnostics ownership.
 * Responsibilities:
 *   - Check Admin diagnostic fields and their MVC boundaries.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_search_diagnostics_stage0a_test.php
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
/**
 * Protect Stage 0A Admin progressive-search diagnostics and MVC boundaries.
 */

declare(strict_types=1);

/**
 * Assert one Stage 0A search-diagnostics contract.
 *
 * @param bool $condition Assertion result.
 * @param string $label Failure label.
 */
function public_search_diagnostics_stage0a_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
$modelSource = (string) file_get_contents($root . '/app/models/public_search_diagnostics.php');
$progressiveModelSource = (string) file_get_contents($root . '/app/models/public_search_progressive.php');
$progressiveDeferredModelSource = (string) file_get_contents($root . '/app/models/public_search_progressive/deferred.php');
$serviceSource = (string) file_get_contents($root . '/app/services/public_search_diagnostics.php');
$controllerSource = (string) file_get_contents($root . '/app/controllers/admin_search_diagnostics.php');
$viewSource = (string) file_get_contents($root . '/app/views/admin_search_diagnostics.php');
$dispatchSource = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
$dashboardSource = (string) file_get_contents($root . '/app/views/admin_dashboard_sections.php');
$browserSource = (string) file_get_contents($root . '/public/assets/gallery-modules/admin-search-diagnostics.js');
$galleryEntrySource = (string) file_get_contents($root . '/public/assets/gallery.js');

public_search_diagnostics_stage0a_assert(
    str_contains($dispatchSource, "'admin_search_diagnostics' => '\\Gallery\\Controllers\\cms_admin_search_diagnostics'")
        && str_contains($controllerSource, 'function cms_admin_search_diagnostics(): void')
        && str_contains($controllerSource, 'require_admin();')
        && str_contains($controllerSource, 'verify_csrf();'),
    'Search diagnostics must be an authenticated Admin route with CSRF-protected execution.'
);
public_search_diagnostics_stage0a_assert(
    str_contains($modelSource, 'function public_search_diagnostics_profile_start(): void')
        && str_contains($modelSource, 'function public_search_diagnostics_fetch_all(')
        && str_contains($modelSource, "EXPLAIN FORMAT=JSON ")
        && str_contains($modelSource, 'SHOW INDEX FROM'),
    'The model layer must own query timing, EXPLAIN, and index metadata inspection.'
);
public_search_diagnostics_stage0a_assert(
    substr_count($progressiveModelSource, 'public_search_diagnostics_fetch_all(') >= 8
        && substr_count($progressiveDeferredModelSource, 'public_search_diagnostics_fetch_all(') >= 8,
    'Every progressive search model query must pass through the request-local diagnostics wrapper.'
);
public_search_diagnostics_stage0a_assert(
    !str_contains($serviceSource, 'db()->')
        && !str_contains($serviceSource, '->prepare(')
        && !str_contains($controllerSource, '->prepare(')
        && !str_contains($viewSource, '->prepare('),
    'Search diagnostics service/controller/view layers must remain free of SQL/PDO access.'
);
public_search_diagnostics_stage0a_assert(
    str_contains($serviceSource, 'PUBLIC_SEARCH_PHASE_PRIMARY')
        && str_contains($serviceSource, 'PUBLIC_SEARCH_PHASE_MEDIA')
        && str_contains($serviceSource, 'PUBLIC_SEARCH_PHASE_DESCRIPTIVE')
        && str_contains($serviceSource, 'PUBLIC_SEARCH_PHASE_DEEP')
        && str_contains($serviceSource, 'phase_summary')
        && str_contains($serviceSource, 'query_summary'),
    'The diagnostics report must execute and summarize all four progressive search phases.'
);
public_search_diagnostics_stage0a_assert(
    str_contains($viewSource, 'data-admin-search-diagnostics-report')
        && str_contains($viewSource, "url_for('admin_search_diagnostics', ['download' => 1])")
        && str_contains($browserSource, 'navigator.clipboard')
        && str_contains($galleryEntrySource, 'setupAdminSearchDiagnostics'),
    'Admin UI must expose copy and JSON-download workflows through a dedicated browser module.'
);
public_search_diagnostics_stage0a_assert(
    str_contains($dashboardSource, "url_for('admin_search_diagnostics')")
        && str_contains($dashboardSource, "admin.search_diagnostics.dashboard_title"),
    'Maintenance dashboard must provide a direct Search diagnostics entry point.'
);
public_search_diagnostics_stage0a_assert(
    substr_count($controllerSource, "'query_length'") >= 2
        && str_contains($controllerSource, "'search_diagnostics.completed'")
        && str_contains($controllerSource, "'search_diagnostics.failed'"),
    'Admin diagnostic logging must use bounded completion/failure events with query-length metadata.'
);

fwrite(STDOUT, "Public-search diagnostics Stage 0A checks passed.\n");
