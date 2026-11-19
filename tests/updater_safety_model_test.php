<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/updater_safety_model_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Verifies that application updates validate complete snapshots and never classify
 *   legitimate app modules as misplaced project copies.
 *
 * Responsibilities:
 *   - Protect app/views.php and other current top-level app entries from cleanup
 *   - Require critical runtime files before an update can touch the installation
 *   - Preserve narrowly targeted cleanup for an actual project copy nested in app
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Check writer invalidation immediately before a native fixture mutation.
     * @return void Throws if an enabled fixture still observes stale markers.
     */
    function updater_rewrite_fixture_before_mutation(): void
    {
        if (empty($GLOBALS['updater_rewrite_fixture_spy'])) {
            return;
        }
        if (($GLOBALS['cms_url_rewrite_writer_active'] ?? false) !== true
            || isset($GLOBALS['cms_url_rewrite_request_observation'])) {
            throw new \RuntimeException('Rewrite observations survived until a native writer call.');
        }
        $GLOBALS['updater_rewrite_fixture_mutations']++;
    }

    /**
     * Exercise native copy after checking the updater's writer boundary.
     * @param string $from Source path owned by the temporary fixture.
     * @param string $to Destination path owned by the temporary fixture.
     * @return bool Native copy success or failure.
     */
    function copy(string $from, string $to): bool
    {
        updater_rewrite_fixture_before_mutation();
        return \copy($from, $to);
    }

    /**
     * Exercise native rename or inject a post-copy activation failure.
     * @param string $from Prepared temporary fixture path.
     * @param string $to Active temporary fixture destination.
     * @return bool Native result, or false for the explicitly injected failure.
     */
    function rename(string $from, string $to): bool
    {
        updater_rewrite_fixture_before_mutation();
        if (!empty($GLOBALS['updater_rewrite_fixture_spy'])
            && !empty($GLOBALS['updater_rewrite_fixture_fail_rename'])) {
            return false;
        }
        return \rename($from, $to);
    }

    /**
     * Exercise native removal after checking invalidation at the writer boundary.
     * @param string $path File path owned by the temporary fixture.
     * @return bool Native unlink success or failure.
     */
    function unlink(string $path): bool
    {
        updater_rewrite_fixture_before_mutation();
        return \unlink($path);
    }
}

namespace {
require_once __DIR__ . '/../app/services/updates.php';

/**
 * Give the new rewrite-writer fixture an isolated temporary directory suffix.
 * Type: integer.
 * Units: random bytes before hexadecimal encoding.
 * Scope: the rewrite-writer contract scenario in this fixture process.
 * Consumers: rewriteFixture temporary-directory creation.
 * Rationale: six bytes preserve the established fixture suffix size while
 *     avoiding accidental collisions between concurrently running processes.
 */
const UPDATER_REWRITE_FIXTURE_NONCE_BYTES = 6;

// Merely loading the marked writer owners must not disable request reuse.
if (!\Gallery\Services\UPDATES_FILESYSTEM_INVALIDATES_REWRITE_OBSERVATIONS
    || !\Gallery\Services\UPDATE_SERVER_POLICY_INVALIDATES_REWRITE_OBSERVATIONS
    || !\Gallery\Services\UPDATE_ACTIVATION_INVALIDATES_REWRITE_OBSERVATIONS
    || !empty($GLOBALS['cms_url_rewrite_writer_active'])) {
    throw new RuntimeException('Marked updater owners must remain passive until invoked.');
}
$rewriteFixture = sys_get_temp_dir() . '/php-gallery-updater-rewrite-'
    . bin2hex(random_bytes(UPDATER_REWRITE_FIXTURE_NONCE_BYTES));
mkdir($rewriteFixture);
$rewriteSource = $rewriteFixture . '/release.htaccess';
$rewriteDestination = $rewriteFixture . '/.htaccess';
file_put_contents($rewriteSource, "# incoming rewrite marker\n");
file_put_contents($rewriteDestination, "# original rewrite marker\n");
$GLOBALS['updater_rewrite_fixture_spy'] = true;
$GLOBALS['updater_rewrite_fixture_mutations'] = 0;
try {
    $GLOBALS['cms_url_rewrite_request_observation'] = ['stale' => true];
    \Gallery\Services\application_update_server_policy_replace_file($rewriteSource, $rewriteDestination);
    if (file_get_contents($rewriteDestination) !== "# incoming rewrite marker\n"
        || $GLOBALS['updater_rewrite_fixture_mutations'] === 0) {
        throw new RuntimeException('Actual policy replacement did not exercise the writer contract.');
    }
    unset($GLOBALS['cms_url_rewrite_writer_active']);
    $GLOBALS['cms_url_rewrite_request_observation'] = ['stale' => true];
    // Distinct staged bytes make an accidental active overwrite observable.
    file_put_contents($rewriteSource, "# distinct failed replacement marker\n");
    $GLOBALS['updater_rewrite_fixture_fail_rename'] = true;
    $failedReplacement = false;
    try {
        \Gallery\Services\application_update_server_policy_replace_file($rewriteSource, $rewriteDestination);
    } catch (RuntimeException) {
        $failedReplacement = true;
    }
    $GLOBALS['updater_rewrite_fixture_fail_rename'] = false;
    if (!$failedReplacement
        || ($GLOBALS['cms_url_rewrite_writer_active'] ?? false) !== true
        || isset($GLOBALS['cms_url_rewrite_request_observation'])
        || file_get_contents($rewriteDestination) !== "# incoming rewrite marker\n") {
        throw new RuntimeException('Partial replacement failure lost invalidation or changed the prior active marker.');
    }
    unset($GLOBALS['cms_url_rewrite_writer_active']);
    $GLOBALS['cms_url_rewrite_request_observation'] = ['stale' => true];
    \Gallery\Services\application_update_remove_path($rewriteDestination);
    if (is_file($rewriteDestination)
        || ($GLOBALS['cms_url_rewrite_writer_active'] ?? false) !== true
        || isset($GLOBALS['cms_url_rewrite_request_observation'])) {
        throw new RuntimeException('Actual marker removal did not preserve the writer latch.');
    }
} finally {
    unset($GLOBALS['updater_rewrite_fixture_spy'], $GLOBALS['updater_rewrite_fixture_fail_rename']);
    foreach (glob($rewriteFixture . '/*') ?: [] as $temporaryRewriteFile) {
        unlink($temporaryRewriteFile);
    }
    if (is_file($rewriteDestination)) {
        unlink($rewriteDestination);
    }
    rmdir($rewriteFixture);
    // Only this isolated test simulates a new request between contract scenarios.
    unset($GLOBALS['cms_url_rewrite_writer_active'], $GLOBALS['cms_url_rewrite_request_observation']);
}

// Independent mutation entry points must invalidate before validation, leases,
// staging or deletion can throw; this also covers older loaded settings owners.
foreach ([
    'updates_filesystem.php' => [
        'application_update_copy_files', 'application_update_remove_malformed_root_files',
        'application_update_remove_path', 'application_update_stage_path_for_prune',
        'application_update_delete_tree_slice',
    ],
    'update_server_policy_reconciliation.php' => ['application_update_server_policy_replace_file'],
    'updates_jobs/activation.php' => ['application_update_job_activate'],
] as $writerFile => $boundaries) {
    $writerSource = (string) file_get_contents(__DIR__ . '/../app/services/' . $writerFile);
    foreach ($boundaries as $boundary) {
        $declaration = strpos($writerSource, 'function ' . $boundary . '(');
        $bodyStart = $declaration === false ? false : strpos($writerSource, '{', $declaration);
        $latchStart = $bodyStart === false ? false : strpos($writerSource, "\$GLOBALS['cms_url_rewrite_writer_active'] = true;", $bodyStart);
        $clearStart = $bodyStart === false ? false : strpos($writerSource, "unset(\$GLOBALS['cms_url_rewrite_request_observation']);", $bodyStart);
        if ($bodyStart === false || $latchStart === false || $clearStart === false
            || trim((string) preg_replace('/\/\/[^\n]*\n/', '', substr($writerSource, $bodyStart + 1, $latchStart - $bodyStart - 1))) !== ''
            || $clearStart < $latchStart) {
            throw new RuntimeException('Updater writer does not invalidate at entry: ' . $boundary);
        }
    }
}

$updatesController = (string) file_get_contents(__DIR__
    . '/../app/controllers/updates.php');
$updatesView = (string) file_get_contents(__DIR__
    . '/../app/views/admin_updates.php');
if (
    !str_contains(
        $updatesController,
        "\$action === 'cleanup_malformed_root_files'",
    )
    || !str_contains($updatesView, 'value="cleanup_malformed_root_files"')
) {
    throw new RuntimeException(
        'Advanced updater tools no longer expose malformed root-file cleanup.',
    );
}

use function Gallery\Services\application_update_assert_source_root;
use function Gallery\Services\application_update_misplaced_project_paths;
use function Gallery\Services\application_update_remove_malformed_root_files;

$root = dirname(__DIR__);
$unexpectedFixturePaths = array_values(array_diff(
    \Gallery\Core\release_file_policy_archive_owned_candidates($root),
    \Gallery\Core\release_file_policy_paths($root, 'updater'),
));
if ($unexpectedFixturePaths !== []) {
    throw new RuntimeException('Source-checkout updater fixture contains unowned files: '
        . implode(', ', $unexpectedFixturePaths));
}
application_update_assert_source_root($root);

$misplaced = application_update_misplaced_project_paths($root);
foreach ([
    'app/views.php',
    'app/views',
    'app/lang',
    'app/migration_definitions.php',
    'app/migration_repairs.php',
] as $validPath) {
    if (in_array($validPath, $misplaced, true)) {
        throw new RuntimeException('Updater classified a valid application path as misplaced: '
            . $validPath);
    }
}

foreach (['app/app', 'app/public', 'app/index.php'] as $knownWrongPath) {
    if (!in_array($knownWrongPath, $misplaced, true)) {
        throw new RuntimeException(
            'Updater no longer recognizes a known nested project artifact: '
                . $knownWrongPath,
        );
    }
}

$tempRoot =
    sys_get_temp_dir()
    . '/php-gallery-incomplete-update-'
    . bin2hex(random_bytes(6));
mkdir($tempRoot, 0775, true);
$runtimePaths = [
    'app/bootstrap_full.php',
    'app/runtime/autoload.php',
    'app/runtime/bridge.php',
    'app/runtime/modules.php',
    'app/runtime/Kernel.php',
    'app/runtime/ModuleLoader.php',
    'app/runtime/Request.php',
    'app/runtime/Router.php',
    'app/runtime/RouteDefinition.php',
    'app/runtime/RouteRegistry.php',
    'app/services/updates_request.php',
    'app/services/updates_job_lookup.php',
];
try {
    foreach (array_merge($runtimePaths, [
        'index.php',
        'public/index.php',
        'app/bootstrap.php',
        'app/bootstrap/configuration.php',
        'app/bootstrap/dispatch.php',
        'app/bootstrap/maintenance.php',
        'app/bootstrap/request.php',
        'app/bootstrap/routing.php',
        'app/bootstrap/session.php',
        'app/controllers.php',
        'app/controllers/admin_galleries_edit.php',
        'app/controllers/admin_galleries_edit_actions.php',
        'app/controllers/admin_galleries_edit_metadata.php',
        'app/controllers/admin_galleries_edit_page.php',
        'app/controllers/admin_galleries_edit_views.php',
        'app/controllers/admin_theme.php',
        'app/controllers/admin_theme_actions.php',
        'app/controllers/admin_theme_appearance.php',
        'app/controllers/admin_theme_custom_css.php',
        'app/controllers/admin_theme_language.php',
        'app/controllers/admin_theme_layout.php',
        'app/controllers/admin_theme_media.php',
        'app/controllers/admin_theme_page.php',
        'app/controllers/public_gallery.php',
        'app/controllers/public_gallery_cards.php',
        'app/controllers/public_gallery_controls.php',
        'app/controllers/public_gallery_home.php',
        'app/controllers/public_gallery_lightbox.php',
        'app/controllers/public_gallery_page.php',
        'app/database.php',
        'app/helpers.php',
        'app/helpers_admin_rendering.php',
        'app/helpers_files.php',
        'app/helpers_page_rendering.php',
        'app/helpers_public_urls.php',
        'app/helpers_request.php',
        'app/helpers_runtime.php',
        'app/integrity.php',
        'app/core-manifest.json',
        'app/migrations.php',
        'app/security.php',
        'app/services.php',
        'app/services/schema_inspection.php',
        'app/services/mutation_schema_policy.php',
        'app/services/updates.php',
        'app/services/updates_filesystem.php',
        'app/services/updates_install.php',
        'app/services/updates_jobs.php',
        'app/services/updates_patch_notes.php',
        'app/services/updates_remote.php',
        'app/services/updates_status.php',
        'app/views/layout.php',
        'app/lang/en.php',
        'public/assets/styles.css',
    ]) as $path) {
        $absolute = $tempRoot . '/' . $path;
        $directory = dirname($absolute);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents($absolute, "<?php\n");
    }
    file_put_contents($tempRoot . '/app/core-manifest.json', json_encode([
        'version' => '0.0-test',
        'algorithm' => 'sha256',
        'hash_mode' => 'normalized-text-sha256',
        'files' => ['index.php' => 'sha256:fixture'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        . "\n");

    $failedAsExpected = false;
    try {
        application_update_assert_source_root($tempRoot);
    } catch (RuntimeException $exception) {
        $failedAsExpected = str_contains(
            $exception->getMessage(),
            'app/views.php',
        );
    }
    if (!$failedAsExpected) {
        throw new RuntimeException(
            'Incomplete update snapshot was not rejected for missing app/views.php.',
        );
    }
    file_put_contents($tempRoot . '/app/views.php', "<?php\n");
    application_update_assert_source_root($tempRoot);
    foreach ($runtimePaths as $path) {
        unlink($tempRoot . '/' . $path);
        $refused = false;
        try {
            application_update_assert_source_root($tempRoot);
        } catch (RuntimeException $exception) {
            $refused = str_contains($exception->getMessage(), $path);
        }
        file_put_contents($tempRoot . '/' . $path, "<?php\n");
        if (!$refused) {
            throw new RuntimeException('Updater accepted a snapshot missing required runtime file: '
                . $path);
        }
    }
} finally {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $tempRoot,
            FilesystemIterator::SKIP_DOTS,
        ),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir()
            ? rmdir($item->getPathname())
            : unlink($item->getPathname());
    }
    rmdir($tempRoot);
}

if (class_exists(ZipArchive::class) && DIRECTORY_SEPARATOR !== '\\') {
    $cleanupRoot =
        sys_get_temp_dir()
        . '/php-gallery-malformed-root-cleanup-'
        . bin2hex(random_bytes(6));
    mkdir($cleanupRoot . '/app/services', 0775, true);
    mkdir($cleanupRoot . '/public/assets', 0775, true);
    mkdir($cleanupRoot . '/cache', 0775, true);
    foreach ([
        'index.php',
        'app/bootstrap.php',
        'app/services/updates.php',
        'public/assets/styles.css',
    ] as $requiredPath) {
        file_put_contents($cleanupRoot . '/' . $requiredPath, "<?php\n");
    }
    $malformedName = 'app\\controllers\\admin_auth.php';
    file_put_contents($cleanupRoot . '/' . $malformedName, "<?php\n");
    file_put_contents($cleanupRoot . '/bootstrap.php', "<?php\n");
    file_put_contents(
        $cleanupRoot . '/google-site-verification.html',
        "verification\n",
    );
    file_put_contents($cleanupRoot . '/custom-tracker.php', "<?php\n");
    mkdir($cleanupRoot . '/nested', 0775, true);
    file_put_contents($cleanupRoot . '/nested/keep\\file.php', "<?php\n");
    mkdir($cleanupRoot . '/keep\\directory', 0775, true);

    $backupPath = $cleanupRoot . '/cache/malformed-cleanup.zip';
    $backup = new ZipArchive();
    if (
        $backup->open($backupPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)
        !== true
    ) {
        throw new RuntimeException(
            'Could not create malformed-root cleanup test archive.',
        );
    }
    try {
        $removed = application_update_remove_malformed_root_files(
            $cleanupRoot,
            $backup,
        );
    } finally {
        $backup->close();
    }

    sort($removed);
    $expectedRemoved = [$malformedName, 'bootstrap.php'];
    sort($expectedRemoved);
    if (
        $removed !== $expectedRemoved
        || file_exists($cleanupRoot . '/' . $malformedName)
        || file_exists($cleanupRoot . '/bootstrap.php')
    ) {
        throw new RuntimeException(
            'Updater did not remove the malformed root file safely.',
        );
    }
    if (
        !is_file($cleanupRoot . '/nested/keep\\file.php')
        || !is_dir($cleanupRoot . '/keep\\directory')
        || !is_file($cleanupRoot . '/google-site-verification.html')
        || !is_file($cleanupRoot . '/custom-tracker.php')
    ) {
        throw new RuntimeException(
            'Malformed-root cleanup escaped its direct regular-file scope.',
        );
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $cleanupRoot,
            FilesystemIterator::SKIP_DOTS,
        ),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir()
            ? rmdir($item->getPathname())
            : unlink($item->getPathname());
    }
    rmdir($cleanupRoot);
}

echo "Updater safety model tests passed.\n";

}
