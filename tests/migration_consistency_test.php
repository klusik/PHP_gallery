<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/migration_consistency_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the current database migration chain and definition contract.
 *
 * Responsibilities:
 *   - Validate every migration filename and returned definition
 *   - Keep migration versions unique and lexically ordered
 *   - Confirm obsolete implementation names are absent from migration filenames
 *   - Confirm the browser-setting cleanup follows the canonical setting seeds
 *   - Confirm current public-path and database-maintenance repairs are recorded callbacks
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
 *   2026-07-25
 */

declare(strict_types=1);

use function Gallery\Core\discover_migration_files;
use function Gallery\Core\load_migration_definition;
use function Gallery\Core\load_migration_definitions;
use function Gallery\Core\migration_array_is_list;
use function Gallery\Core\pending_migration_files;

require_once __DIR__ . '/../app/migration_definitions.php';

/**
 * Throw when a migration expectation fails.
 *
 * @param bool $condition Condition value.
 * @param string $label Assertion label.
 */
function assert_migration_consistency(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$migrationFiles = discover_migration_files(__DIR__ . '/../database/migrations');
assert_migration_consistency($migrationFiles !== [], 'Migration directory must not be empty.');
assert_migration_consistency(migration_array_is_list([]), 'Empty arrays must be recognized as migration lists on PHP 8.0.');
assert_migration_consistency(migration_array_is_list(['SELECT 1']), 'Sequential SQL arrays must be recognized as migration lists.');
assert_migration_consistency(!migration_array_is_list(['statements' => []]), 'Associative migration definitions must not be recognized as SQL lists.');
$preflightDefinitions = load_migration_definitions($migrationFiles);
assert_migration_consistency(count($preflightDefinitions) === count($migrationFiles), 'Migration preflight must validate the complete discovered set.');

$versions = [];
$definitions = [];
foreach ($migrationFiles as $file) {
    $filename = basename($file);
    assert_migration_consistency(
        preg_match('/^\d{12}_[a-z0-9_]+\.php$/', $filename) === 1,
        'Invalid migration filename: ' . $filename
    );
    assert_migration_consistency(!str_contains($filename, 'experimental'), 'Obsolete implementation name remains in migration filename: ' . $filename);

    $version = basename($file, '.php');
    assert_migration_consistency(!isset($versions[$version]), 'Duplicate migration version: ' . $version);
    $versions[$version] = true;
    $definitions[$version] = load_migration_definition($file);
}

$orderedVersions = array_keys($versions);
$sortedVersions = $orderedVersions;
sort($sortedVersions, SORT_STRING);
assert_migration_consistency($orderedVersions === $sortedVersions, 'Migration files are not in deterministic lexical order.');

$browserSeed = '202606100001_browser_client_upload_settings';
$browserSafety = '202606100002_browser_upload_batch_safety';
$browserRebuild = '202606100003_browser_thumbnail_rebuild_settings';
$legacyCleanup = '202607120001_browser_upload_legacy_settings_cleanup';
$publicPathRepair = '202607120002_harden_gallery_public_paths';
$hierarchicalPathRepair = '202607120003_restore_hierarchical_gallery_public_paths';
$runnerCompatibilityRepair = '202607120004_verify_gallery_public_paths_after_runner_upgrade';
$databaseMaintenanceRepair = '202607250001_database_maintenance_schema_repair';
$viewerSecurityFoundation = '202608180001_viewer_security_foundations';
$viewerRegistrationFoundation = '202608180002_viewer_registration_foundations';
$viewerAuthenticationFoundation = '202608180003_viewer_authentication_foundations';
$viewerLifecycleFoundation = '202608180004_viewer_account_lifecycle_foundations';
$viewerInvitationAdminManagement = '202608180005_viewer_invitation_admin_management';
$viewerAdminAccountManagement = '202608180006_viewer_admin_account_management';
$publicThumbnailProgressiveDefault = '202608200002_public_thumbnail_progressive_default';

foreach ([$browserSeed, $browserSafety, $browserRebuild, $legacyCleanup, $publicPathRepair, $hierarchicalPathRepair, $runnerCompatibilityRepair, $databaseMaintenanceRepair, $viewerSecurityFoundation, $viewerRegistrationFoundation, $viewerAuthenticationFoundation, $viewerLifecycleFoundation, $viewerInvitationAdminManagement, $viewerAdminAccountManagement, $publicThumbnailProgressiveDefault] as $requiredVersion) {
    assert_migration_consistency(isset($definitions[$requiredVersion]), 'Required migration is missing: ' . $requiredVersion);
}
assert_migration_consistency(strcmp($legacyCleanup, $browserRebuild) > 0, 'Legacy cleanup must run after canonical browser setting migrations.');
assert_migration_consistency(count($definitions[$legacyCleanup]['statements']) === 1, 'Legacy cleanup must remain the immutable released migration definition.');
assert_migration_consistency($definitions[$publicPathRepair]['after'] !== null, 'Public-path repair must be recorded as a post-migration callback.');
assert_migration_consistency($definitions[$publicPathRepair]['statements'] === [], 'Public-path repair should not contain unrelated SQL schema changes.');
assert_migration_consistency($definitions[$hierarchicalPathRepair]['after'] !== null, 'Hierarchical public-path repair must be recorded as a post-migration callback.');
assert_migration_consistency($definitions[$hierarchicalPathRepair]['statements'] === [], 'Hierarchical public-path repair should not contain unrelated SQL schema changes.');
assert_migration_consistency(strcmp($hierarchicalPathRepair, $publicPathRepair) > 0, 'Hierarchical public-path repair must run after the first hardening repair.');
assert_migration_consistency($definitions[$runnerCompatibilityRepair]['after'] !== null, 'Runner compatibility repair must be recorded as a post-migration callback.');
assert_migration_consistency($definitions[$runnerCompatibilityRepair]['statements'] === [], 'Runner compatibility repair should not contain unrelated SQL schema changes.');
assert_migration_consistency(strcmp($runnerCompatibilityRepair, $hierarchicalPathRepair) > 0, 'Runner compatibility repair must run after the hierarchical path repair.');
assert_migration_consistency($definitions[$databaseMaintenanceRepair]['after'] !== null, 'Database maintenance repair must be recorded as a post-migration callback.');
assert_migration_consistency($definitions[$databaseMaintenanceRepair]['statements'] === [], 'Database maintenance repair must conditionally inspect objects instead of exposing destructive SQL statements.');
assert_migration_consistency(strcmp($databaseMaintenanceRepair, $runnerCompatibilityRepair) > 0, 'Database maintenance repair must run after the released migration-runner compatibility repair.');
assert_migration_consistency(strcmp($viewerRegistrationFoundation, $viewerSecurityFoundation) > 0, 'Viewer registration foundations must run after the base viewer-security migration.');
assert_migration_consistency(strcmp($viewerAuthenticationFoundation, $viewerRegistrationFoundation) > 0, 'Viewer authentication foundations must run after registration foundations.');
assert_migration_consistency(strcmp($viewerLifecycleFoundation, $viewerAuthenticationFoundation) > 0, 'Viewer Phase 0.7 lifecycle foundations must run after Phase 0.6 authentication foundations.');
assert_migration_consistency(count($definitions[$viewerLifecycleFoundation]['statements']) === 1, 'Viewer Phase 0.7 lifecycle migration must remain one additive table definition.');
assert_migration_consistency(strcmp($viewerInvitationAdminManagement, $viewerLifecycleFoundation) > 0, 'Viewer invitation Admin management migration must run after the Phase 0.7 lifecycle foundations.');
assert_migration_consistency(count($definitions[$viewerInvitationAdminManagement]['statements']) === 1, 'Viewer invitation Admin management migration must remain one additive column definition.');
assert_migration_consistency(strcmp($viewerAdminAccountManagement, $viewerInvitationAdminManagement) > 0, 'Viewer Admin account management migration must run after invitation Admin management.');
assert_migration_consistency(count($definitions[$viewerAdminAccountManagement]['statements']) === 1, 'Viewer Admin account management migration must remain one additive first-login flag definition.');
assert_migration_consistency(str_contains((string) $definitions[$viewerAdminAccountManagement]['statements'][0], 'must_change_password'), 'Viewer Admin account management migration must add the forced password-change flag.');
assert_migration_consistency(strcmp($publicThumbnailProgressiveDefault, $viewerAdminAccountManagement) > 0, 'Progressive thumbnail default migration must run after the current viewer-security migrations.');
assert_migration_consistency(count($definitions[$publicThumbnailProgressiveDefault]['statements']) === 2, 'Progressive thumbnail default migration must remain a two-setting transition.');
assert_migration_consistency(str_contains((string) $definitions[$publicThumbnailProgressiveDefault]['statements'][0], "public_thumbnail_rendering_mode', 'progressive'"), 'Progressive thumbnail default migration must seed and update the renderer setting to progressive.');
assert_migration_consistency(str_contains((string) $definitions[$publicThumbnailProgressiveDefault]['statements'][0], "setting_value = 'progressive'"), 'Progressive thumbnail default migration must move existing persisted renderer settings to progressive.');
assert_migration_consistency(str_contains((string) $definitions[$publicThumbnailProgressiveDefault]['statements'][1], 'theme_public_content_revision'), 'Progressive thumbnail default migration must invalidate cached public presentation through the content revision.');

$simulatedFiles = [
    '/project/database/migrations/202606100001_browser_client_upload_settings.php',
    '/project/database/migrations/202607120002_harden_gallery_public_paths.php',
    '/project/database/migrations/202607120003_restore_hierarchical_gallery_public_paths.php',
    '/project/database/migrations/202607120004_verify_gallery_public_paths_after_runner_upgrade.php',
];
$pendingWithRemovedHistory = pending_migration_files($simulatedFiles, [
    '202606100001_browser_client_upload_settings',
    '202606100001_experimental_client_upload_settings',
]);
assert_migration_consistency(
    $pendingWithRemovedHistory === [
        '/project/database/migrations/202607120002_harden_gallery_public_paths.php',
        '/project/database/migrations/202607120003_restore_hierarchical_gallery_public_paths.php',
        '/project/database/migrations/202607120004_verify_gallery_public_paths_after_runner_upgrade.php',
    ],
    'Removed historical migration audit rows must not affect current pending-file detection.'
);
assert_migration_consistency(
    pending_migration_files($simulatedFiles, [
        '202606100001_browser_client_upload_settings',
        '202607120002_harden_gallery_public_paths',
        '202607120003_restore_hierarchical_gallery_public_paths',
        '202607120004_verify_gallery_public_paths_after_runner_upgrade',
        '202606100001_experimental_client_upload_settings',
    ]) === [],
    'Extra applied historical versions must not make a fully migrated database appear pending.'
);

echo "Migration consistency tests passed.\n";
