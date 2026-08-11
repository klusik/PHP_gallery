<?php

/**
 * Project: PHP Gallery
 * File: tests/admin_dashboard_deferred_maintenance_test.php
 * Purpose: Verify that dashboard maintenance work is deferred until activation.
 */

declare(strict_types=1);

function assert_admin_dashboard_deferred_contains(string $source, string $needle, string $label): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($label . ' is missing: ' . $needle);
    }
}

$serviceSource = (string) file_get_contents(__DIR__ . '/../app/services/admin_dashboard.php');
$controllerSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_dashboard.php');
$viewSource = (string) file_get_contents(__DIR__ . '/../app/views/admin_dashboard.php');
$tabsSource = (string) file_get_contents(__DIR__ . '/../public/assets/gallery-modules/admin-tabs.js');
$bootstrapSource = (string) file_get_contents(__DIR__ . '/../app/bootstrap.php');

assert_admin_dashboard_deferred_contains($serviceSource, 'function admin_dashboard_view_model(bool $includeMaintenance = false): array', 'dashboard model has an explicit maintenance loading flag');
assert_admin_dashboard_deferred_contains($serviceSource, '$databaseUsage = $includeMaintenance &&', 'database usage is maintenance-only');
assert_admin_dashboard_deferred_contains($controllerSource, 'function cms_admin_dashboard_maintenance(): void', 'deferred maintenance controller exists');
assert_admin_dashboard_deferred_contains($viewSource, 'data-admin-dashboard-maintenance-placeholder', 'dashboard renders a deferred maintenance placeholder');
assert_admin_dashboard_deferred_contains($tabsSource, 'loadDeferredAdminPanel', 'tab activation loads deferred panels');
assert_admin_dashboard_deferred_contains($bootstrapSource, "'admin_dashboard_maintenance'", 'deferred maintenance route is registered');

echo "Deferred Admin dashboard maintenance tests passed.\n";
