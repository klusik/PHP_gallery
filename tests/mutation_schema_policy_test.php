<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/mutation_schema_policy_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the Phase 10 fail-closed schema policy for destructive and ingestion workflows.
 *
 * Responsibilities:
 *   - Cover available, confirmed missing, and unknown mutation capability states
 *   - Prove optional compatibility skips only confirmed absence
 *   - Prove token revocation uses narrower verified schema than issuance/authentication
 *   - Verify request-local metadata query caching remains bounded
 *   - Verify Phase 10 mutation callers no longer use legacy boolean schema helpers
 *   - Verify destructive/ingestion System Health and Runtime Diagnostics registration
 *   - Verify upload and updater preflights occur before irreversible target mutations
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - The schema executor seam avoids any live database connection.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/services/schema_inspection.php';
require_once __DIR__ . '/../app/services/mutation_schema_policy.php';

use Gallery\Services\MutationSchemaUnavailableException;
use function Gallery\Services\gallery_deletion_schema_status;
use function Gallery\Services\mobile_webdav_revocation_schema_status;
use function Gallery\Services\mobile_webdav_schema_status;
use function Gallery\Services\mutation_schema_optional_column_available;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\schema_inspection_is_missing;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\schema_inspection_reset_request_cache;
use function Gallery\Services\schema_inspection_set_query_executor_for_tests;
use function Gallery\Services\upload_automation_revocation_schema_status;
use function Gallery\Services\upload_automation_schema_status;
use function Gallery\Services\upload_ingestion_schema_status;

/**
 * Throw when one strict Phase 10 expectation fails.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual Actual value.
 * @param string $label Assertion label.
 */
function mutation_policy_assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ' expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

/**
 * Throw when one Phase 10 condition is false.
 *
 * @param bool $condition Condition to verify.
 * @param string $label Assertion label.
 */
function mutation_policy_assert_true(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$allAvailableExecutor = static fn (): bool => true;
schema_inspection_set_query_executor_for_tests($allAvailableExecutor);
$deletionAvailable = gallery_deletion_schema_status();
mutation_policy_assert_true(schema_inspection_is_available($deletionAvailable), 'gallery deletion current schema available');

schema_inspection_set_query_executor_for_tests(
    static fn (string $type, string $table, string $object): bool => !($type === 'column' && $table === 'images' && $object === 'gallery_id')
);
$deletionMissing = gallery_deletion_schema_status();
mutation_policy_assert_true(schema_inspection_is_missing($deletionMissing), 'gallery deletion confirmed missing state');

schema_inspection_set_query_executor_for_tests(
    static function (string $type, string $table, string $object): bool {
        if ($type === 'column' && $table === 'images' && $object === 'gallery_id') {
            $exception = new PDOException('private-host password=DoNotExpose SELECT * FROM images');
            $exception->errorInfo = ['HY000', 2002, 'metadata unavailable'];
            throw $exception;
        }
        return true;
    }
);
$deletionUnknown = gallery_deletion_schema_status();
mutation_policy_assert_true(schema_inspection_is_unknown($deletionUnknown), 'gallery deletion inspection failure is unknown');
mutation_policy_assert_true(!str_contains((string) json_encode($deletionUnknown), 'DoNotExpose'), 'unknown capability redacts database exception details');

schema_inspection_set_query_executor_for_tests(
    static fn (string $type, string $table, string $object): bool => !($type === 'column' && $table === 'images' && $object === 'optional_legacy_column')
);
$optionalMissing = mutation_schema_optional_column_available(
    'mutation.test_optional',
    'images',
    'optional_legacy_column',
    'test.optional_missing'
);
mutation_policy_assert_same(false, $optionalMissing, 'confirmed missing optional column uses compatibility path');

schema_inspection_set_query_executor_for_tests(
    static function (string $type, string $table, string $object): bool {
        if ($type === 'column' && $table === 'images' && $object === 'optional_legacy_column') {
            throw new PDOException('password=NeverLogThis');
        }
        return true;
    }
);
$unknownOptionalThrew = false;
try {
    mutation_schema_optional_column_available('mutation.test_optional', 'images', 'optional_legacy_column', 'test.optional_unknown');
} catch (MutationSchemaUnavailableException $exception) {
    $unknownOptionalThrew = $exception->state === 'unknown'
        && $exception->operation === 'test.optional_unknown'
        && !str_contains($exception->getMessage(), 'NeverLogThis');
}
mutation_policy_assert_true($unknownOptionalThrew, 'unknown optional column refuses mutation with bounded exception');

schema_inspection_set_query_executor_for_tests(
    static fn (string $type, string $table, string $object): bool => !($type === 'column' && $table === 'gallery_upload_tokens' && $object === 'token_hash')
);
mutation_policy_assert_true(schema_inspection_is_missing(upload_automation_schema_status()), 'upload automation issuance requires token hash');
mutation_policy_assert_true(schema_inspection_is_available(upload_automation_revocation_schema_status()), 'upload automation revocation remains available with narrow verified columns');

schema_inspection_set_query_executor_for_tests(
    static fn (string $type, string $table, string $object): bool => !($type === 'column' && $table === 'mobile_webdav_upload_tokens' && $object === 'password_hash')
);
mutation_policy_assert_true(schema_inspection_is_missing(mobile_webdav_schema_status()), 'WebDAV issuance/authentication requires password hash');
mutation_policy_assert_true(schema_inspection_is_available(mobile_webdav_revocation_schema_status()), 'WebDAV revocation remains available with verified id column');

$queryCount = 0;
schema_inspection_set_query_executor_for_tests(
    static function () use (&$queryCount): bool {
        $queryCount++;
        return true;
    }
);
schema_inspection_reset_request_cache();
mutation_policy_assert_true(schema_inspection_is_available(upload_ingestion_schema_status()), 'upload ingestion current schema available');
$firstQueryCount = $queryCount;
mutation_policy_assert_same(12, $firstQueryCount, 'upload ingestion metadata query budget');
mutation_policy_assert_true(schema_inspection_is_available(upload_ingestion_schema_status()), 'upload ingestion cached schema available');
mutation_policy_assert_same($firstQueryCount, $queryCount, 'upload ingestion request-local cache prevents repeat metadata queries');

$phase10MutationSources = [
    'app/services/gallery_mutations.php',
    'app/services/picture_manager.php',
    'app/services/duplicate_photo_ledger.php',
    'app/services/uploads.php',
    'app/services/browser_uploads.php',
    'app/services/upload_automation.php',
    'app/services/gallery_migration.php',
    'app/services/mobile_webdav.php',
    'app/services/thumbnail_metadata.php',
    'app/services/thumbnail_generation.php',
    'app/services/thumbnail_maintenance.php',
    'app/services/database_maintenance.php',
    'app/services/updates_install.php',
];
foreach ($phase10MutationSources as $relativePath) {
    $source = (string) file_get_contents(__DIR__ . '/../' . $relativePath);
    mutation_policy_assert_true(!str_contains($source, 'db_table_exists('), $relativePath . ' has no legacy table-exists mutation policy');
    mutation_policy_assert_true(!str_contains($source, 'db_column_exists('), $relativePath . ' has no legacy column-exists mutation policy');
}

$servicesSource = (string) file_get_contents(__DIR__ . '/../app/services.php');
$policyRegistration = strpos($servicesSource, "require_once __DIR__ . '/services/mutation_schema_policy.php';");
$galleryMutationRegistration = strpos($servicesSource, "require_once __DIR__ . '/services/gallery_mutations.php';");
mutation_policy_assert_true($policyRegistration !== false, 'mutation schema policy service registered');
mutation_policy_assert_true($galleryMutationRegistration !== false && $policyRegistration < $galleryMutationRegistration, 'mutation schema policy loads before destructive consumers');

$dashboardSource = (string) file_get_contents(__DIR__ . '/../app/services/admin_dashboard.php');
foreach ([
    'mutation_gallery_delete',
    'mutation_gallery_move',
    'mutation_duplicate_photo_ledger',
    'mutation_upload_ingestion',
    'mutation_upload_automation',
    'mutation_gallery_migration',
    'mutation_mobile_webdav',
    'mutation_thumbnail_metadata',
    'mutation_database_maintenance',
    'mutation_application_update',
] as $feature) {
    mutation_policy_assert_true(str_contains($dashboardSource, "'" . $feature . "' =>"), 'System Health registration ' . $feature);
}

$dashboardView = (string) file_get_contents(__DIR__ . '/../app/views/admin_dashboard_sections.php');
mutation_policy_assert_true(str_contains($dashboardView, 'view_render_admin_dashboard_mutation_schema_card'), 'generic mutation health renderer');
mutation_policy_assert_true(str_contains($dashboardView, 'mutation_schema_statuses'), 'System Health consumes mutation capability set');

$diagnosticsSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_diagnostics.php');
mutation_policy_assert_true(str_contains($diagnosticsSource, 'admin_mutation_schema_health_statuses()'), 'Runtime Diagnostics mutation status source');
mutation_policy_assert_true(str_contains($diagnosticsSource, 'Destructive and ingestion database status'), 'Runtime Diagnostics mutation report heading');
mutation_policy_assert_true(!str_contains($diagnosticsSource, 'getMessage()'), 'Runtime Diagnostics exposes no raw exception messages');

$uploadSource = (string) file_get_contents(__DIR__ . '/../app/services/uploads.php');
mutation_policy_assert_true(
    strpos($uploadSource, "thumbnail_metadata_preflight_write_schema('upload.thumbnail_metadata_preflight')") < strpos($uploadSource, 'move_uploaded_file('),
    'classic upload metadata preflight occurs before first gallery file move'
);
$browserUploadSource = (string) file_get_contents(__DIR__ . '/../app/services/browser_uploads.php');
$browserFunctionStart = strpos($browserUploadSource, 'function browser_upload_store_prepared_zip_batch');
$browserPreflight = strpos($browserUploadSource, "thumbnail_metadata_preflight_write_schema('browser_upload.thumbnail_metadata_preflight')", $browserFunctionStart);
$browserGalleryWrite = strpos($browserUploadSource, 'file_put_contents($targetPath', $browserFunctionStart);
mutation_policy_assert_true($browserFunctionStart !== false && $browserPreflight !== false && $browserGalleryWrite !== false && $browserPreflight < $browserGalleryWrite, 'prepared browser upload preflight occurs before gallery file write');

$thumbnailGenerationSource = (string) file_get_contents(__DIR__ . '/../app/services/thumbnail_generation.php');
$generationFunctionStart = strpos($thumbnailGenerationSource, 'function create_image_thumbnails_result');
$generationPreflight = strpos($thumbnailGenerationSource, "thumbnail_metadata_preflight_write_schema('thumbnail_generation.create_image_thumbnails')", $generationFunctionStart);
$generationDirectoryMutation = strpos($thumbnailGenerationSource, 'gallery_thumbs_dir($gallery, true)', $generationFunctionStart);
mutation_policy_assert_true($generationPreflight !== false && $generationDirectoryMutation !== false && $generationPreflight < $generationDirectoryMutation, 'thumbnail schema preflight occurs before derivative directory mutation');

$updateSource = (string) file_get_contents(__DIR__ . '/../app/services/updates_install.php');
foreach ([
    'application_update.install_beta',
    'application_update.restore_stable',
    'application_update.clean_reinstall',
    'application_update.install_stable',
] as $operation) {
    $preflight = strpos($updateSource, "application_update_assert_activation_schema_known('" . $operation . "')");
    $copy = $preflight === false ? false : strpos($updateSource, 'application_update_copy_files(', $preflight);
    mutation_policy_assert_true($preflight !== false && $copy !== false && $preflight < $copy, 'updater activation preflight before active copy for ' . $operation);
}

$updateFilesystemSource = (string) file_get_contents(__DIR__ . '/../app/services/updates_filesystem.php');
mutation_policy_assert_true(str_contains($updateFilesystemSource, "'app/services/schema_inspection.php'"), 'updater snapshot requires schema inspection service');
mutation_policy_assert_true(str_contains($updateFilesystemSource, "'app/services/mutation_schema_policy.php'"), 'updater snapshot requires mutation schema policy service');

schema_inspection_set_query_executor_for_tests(null);

echo "Mutation schema policy checks passed.\n";
