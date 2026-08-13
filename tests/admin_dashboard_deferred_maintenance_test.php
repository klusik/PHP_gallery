<?php

/**
 * Project: PHP Gallery
 * File: tests/admin_dashboard_deferred_maintenance_test.php
 * Purpose: Verify that dashboard maintenance work is deferred until activation.
 */

declare(strict_types=1);

/**
 * Assert that dashboard source contains a required deferred-maintenance marker.
 */
function assert_admin_dashboard_deferred_contains(string $source, string $needle, string $label): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($label . ' is missing: ' . $needle);
    }
}

$serviceSource = (string) file_get_contents(__DIR__ . '/../app/services/admin_dashboard.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_dashboard.php');
$viewSource = (string) file_get_contents(__DIR__ . '/../app/views/admin_dashboard.php');
$sectionsSource = (string) file_get_contents(__DIR__ . '/../app/views/admin_dashboard_sections.php');
$tabsSource = (string) file_get_contents(__DIR__ . '/../public/assets/gallery-modules/admin-tabs.js');
$bootstrapSource = (string) file_get_contents(__DIR__ . '/../app/bootstrap.php')
    . (string) file_get_contents(__DIR__ . '/../app/bootstrap/dispatch.php');

assert_admin_dashboard_deferred_contains($serviceSource, 'function admin_dashboard_view_model(bool $includeMaintenance = false): array', 'dashboard model has an explicit maintenance loading flag');
assert_admin_dashboard_deferred_contains($serviceSource, '$databaseUsage = $includeMaintenance &&', 'database usage is maintenance-only');
assert_admin_dashboard_deferred_contains($controllerSource, 'function cms_admin_dashboard_maintenance(): void', 'deferred maintenance controller exists');
assert_admin_dashboard_deferred_contains($controllerSource, 'use function Gallery\\Views\\view_render_admin_dashboard_maintenance_panel;', 'deferred maintenance controller imports its panel renderer');
assert_admin_dashboard_deferred_contains($controllerSource, "admin_dashboard_view_model(\$requestedMaintenanceTab === 'media')", 'explicit thumbnail maintenance links render maintenance during the main request');
assert_admin_dashboard_deferred_contains($viewSource, 'data-admin-dashboard-maintenance-placeholder', 'dashboard renders a deferred maintenance placeholder');
assert_admin_dashboard_deferred_contains($viewSource, "['maintenance_tab' => 'media']", 'dashboard forwards the thumbnail maintenance deep link to its deferred endpoint');
assert_admin_dashboard_deferred_contains($tabsSource, 'loadDeferredAdminPanel', 'tab activation loads deferred panels');
assert_admin_dashboard_deferred_contains($bootstrapSource, "'admin_dashboard_maintenance'", 'deferred maintenance route is registered');
assert_admin_dashboard_deferred_contains($bootstrapSource, "'admin_dashboard_maintenance_client_log'", 'maintenance browser failure logging route is registered');
assert_admin_dashboard_deferred_contains($controllerSource, "admin.dashboard_maintenance_load_failed", 'server maintenance render failures are logged');
assert_admin_dashboard_deferred_contains($controllerSource, "admin.dashboard_maintenance_browser_failed", 'browser maintenance failures are logged');
assert_admin_dashboard_deferred_contains($viewSource, 'data-maintenance-log-endpoint', 'dashboard exposes its maintenance diagnostics endpoint');
assert_admin_dashboard_deferred_contains($tabsSource, "body.set('response_snippet'", 'browser reports the failed maintenance response snippet');
assert_admin_dashboard_deferred_contains($sectionsSource, "['maintenance_tab' => 'media']", 'thumbnail gap metric links to media maintenance');
assert_admin_dashboard_deferred_contains($sectionsSource, "strtolower(trim((string) (\$_GET['maintenance_tab'] ?? '')))", 'thumbnail maintenance deep-link parameter is parsed with balanced function calls');
assert_admin_dashboard_deferred_contains($sectionsSource, "\$requestedMaintenanceTab === 'media' ? 'admin-maintenance-media'", 'thumbnail maintenance deep link activates the media subtab');

echo "Deferred Admin dashboard maintenance tests passed.\n";
