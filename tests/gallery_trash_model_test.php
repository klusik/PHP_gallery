<?php

/**
 * Regression contracts for the recoverable gallery trash bin.
 *
 * The tests cover the pure retention/token/path model, the real filesystem move
 * used to take a gallery out of galleries_root(), and the source contracts that
 * keep an unverifiable trash schema from silently falling back to permanent
 * deletion. No live database or browser is required.
 */

declare(strict_types=1);

namespace {
    /** Fail this standalone test with one concise contract message. */
    function gallery_trash_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /** Remove a temporary fixture tree created by this test. */
    function gallery_trash_test_rmtree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            gallery_trash_test_rmtree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }
}

namespace Gallery\Core {
    /** Test double for normalize_relative_path(). */
    function normalize_relative_path(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new \RuntimeException('Invalid relative path.');
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }

    /** Test double for now_sql(). */
    function now_sql(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** Test double for path_inside(). */
    function path_inside(string $root, string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = rtrim(str_replace('\\', '/', $path), '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }

    /** Test double for db(). The trash model tests never reach a query. */
    function db(): \PDO
    {
        throw new \RuntimeException('The trash model test must not reach the database.');
    }
}

namespace Gallery\Services {
    /** Test double for app_setting(). */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['gallery_trash_test_settings'][$key] ?? $default;
    }

    /** Test double for set_app_setting(). */
    function set_app_setting(string $key, ?string $value): void
    {
        $GLOBALS['gallery_trash_test_settings'][$key] = $value;
    }

    /** Test double for delete_directory_tree() used by the cross-device move fallback. */
    function delete_directory_tree(string $directory, string $allowedRoot): void
    {
        if ($directory === '' || $directory === $allowedRoot || !\str_starts_with($directory, $allowedRoot)) {
            throw new \RuntimeException('Refusing to delete an unsafe path.');
        }
        \gallery_trash_test_rmtree($directory);
    }

    /** Test double for schema_inspection_is_available(). */
    function schema_inspection_is_available(array $status): bool
    {
        return (string) ($status['state'] ?? '') === 'available';
    }

    /** Test double for schema_inspection_column(). */
    function schema_inspection_column(string $table, string $column): array
    {
        $known = $GLOBALS['gallery_trash_test_columns'][$table] ?? [];
        return ['state' => in_array($column, $known, true) ? 'available' : 'missing'];
    }

    /** Test double for galleries_root(). */
    function galleries_root(): string
    {
        return $GLOBALS['gallery_trash_test_galleries_root'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gallery_trash_root');
    }

    require_once __DIR__ . '/../app/services/gallery_trash.php';
}

namespace {
    $root = dirname(__DIR__);
    $GLOBALS['gallery_trash_test_settings'] = [];
    $GLOBALS['gallery_trash_test_columns'] = [];

    // ---------------------------------------------------------------------
    // 1. Retention model
    // ---------------------------------------------------------------------
    gallery_trash_assert(
        \Gallery\Services\gallery_trash_retention_days() === \Gallery\Services\GALLERY_TRASH_DEFAULT_RETENTION_DAYS,
        'An unconfigured trash bin keeps galleries for the documented 30-day default.'
    );
    gallery_trash_assert(
        \Gallery\Services\gallery_trash_normalize_retention_days(0) === 1
        && \Gallery\Services\gallery_trash_normalize_retention_days(-99) === 1
        && \Gallery\Services\gallery_trash_normalize_retention_days(100000) === 365
        && \Gallery\Services\gallery_trash_normalize_retention_days(30) === 30,
        'Retention length is clamped to the supported one-to-365-day range.'
    );

    $GLOBALS['gallery_trash_test_settings']['gallery_trash_retention_days'] = '7';
    gallery_trash_assert(
        \Gallery\Services\gallery_trash_retention_days() === 7,
        'A configured retention value is read back through app settings.'
    );

    gallery_trash_assert(
        \Gallery\Services\gallery_trash_enabled(),
        'The trash bin is enabled by default so an ordinary delete stays recoverable.'
    );
    $GLOBALS['gallery_trash_test_settings']['gallery_trash_enabled'] = '0';
    gallery_trash_assert(
        !\Gallery\Services\gallery_trash_enabled(),
        'An explicit opt-out disables the trash bin.'
    );
    $GLOBALS['gallery_trash_test_settings']['gallery_trash_enabled'] = '1';

    gallery_trash_assert(
        !\Gallery\Services\gallery_trash_auto_purge_enabled()
        && !\Gallery\Services\gallery_trash_auto_purge_active(),
        'Automatic retention purge is disabled by default.'
    );

    gallery_trash_assert(
        \Gallery\Services\gallery_trash_purge_batch_size() === 25,
        'Scheduled purging uses a bounded default batch size.'
    );

    \Gallery\Services\set_gallery_trash_settings(true, false, 500, 100000);
    gallery_trash_assert(
        \Gallery\Services\gallery_trash_retention_days() === 365
        && \Gallery\Services\gallery_trash_purge_batch_size() === 100
        && \Gallery\Services\gallery_trash_enabled()
        && !\Gallery\Services\gallery_trash_auto_purge_enabled(),
        'Saving settings clamps retention/batch values and preserves the independent auto-purge opt-in.'
    );

    // ---------------------------------------------------------------------
    // 2. Token and path safety
    // ---------------------------------------------------------------------
    $tokenRejected = false;
    foreach (['', '../etc', 'ZZZZ', str_repeat('a', 31), str_repeat('a', 33), 'a/b'] as $badToken) {
        try {
            \Gallery\Services\gallery_trash_assert_token($badToken);
            $tokenRejected = false;
            break;
        } catch (\RuntimeException) {
            $tokenRejected = true;
        }
    }
    gallery_trash_assert($tokenRejected, 'Every non-hexadecimal trash token is rejected before a path is built from it.');

    $goodToken = str_repeat('ab', 16);
    \Gallery\Services\gallery_trash_assert_token($goodToken);
    gallery_trash_assert(
        \Gallery\Services\gallery_trash_relative_path($goodToken) === 'data/gallery-trash/' . $goodToken,
        'The stored trash path stays project-relative and inside persistent runtime data.'
    );
    gallery_trash_assert(
        !str_contains(\Gallery\Services\gallery_trash_relative_path($goodToken), 'galleries/'),
        'The trash store never lives inside the gallery root that discovery and scanning walk.'
    );

    // A restore names its destination before the ancestor chain is recreated, so the
    // gallery path helper must not require the folder or its parent to exist yet.
    gallery_trash_assert(
        str_ends_with(
            \Gallery\Services\gallery_trash_gallery_path('trips/does-not-exist-yet'),
            DIRECTORY_SEPARATOR . 'trips' . DIRECTORY_SEPARATOR . 'does-not-exist-yet'
        ),
        'A future gallery destination can be named before its folder or parent exists.'
    );
    $traversalRejected = 0;
    foreach (['', '.', '..', 'a/../../etc', 'trips/../..'] as $badPath) {
        try {
            \Gallery\Services\gallery_trash_gallery_path($badPath);
        } catch (\RuntimeException) {
            $traversalRejected++;
        }
    }
    gallery_trash_assert($traversalRejected === 5, 'Empty and traversal gallery paths are rejected before any filesystem work.');

    gallery_trash_assert(
        \Gallery\Services\gallery_trash_normalize_origin('dashboard_bulk') === 'dashboard_bulk'
        && \Gallery\Services\gallery_trash_normalize_origin('public_inline') === 'public_inline'
        && \Gallery\Services\gallery_trash_normalize_origin('<script>') === 'other',
        'The stored deletion origin is restricted to a bounded vocabulary.'
    );

    // ---------------------------------------------------------------------
    // 3. Retention countdown
    // ---------------------------------------------------------------------
    gallery_trash_assert(
        \Gallery\Services\gallery_trash_days_remaining(['purge_after' => date('Y-m-d H:i:s', time() + (86400 * 10) + 60)]) === 11,
        'A future retention deadline rounds partial remaining days up for the countdown.'
    );
    gallery_trash_assert(
        \Gallery\Services\gallery_trash_days_remaining(['purge_after' => date('Y-m-d H:i:s', time() - 86400)]) === 0
        && \Gallery\Services\gallery_trash_days_remaining(['purge_after' => '']) === 0,
        'An elapsed or unreadable deadline never reports a negative countdown.'
    );

    // ---------------------------------------------------------------------
    // 4. Restorable column filtering
    // ---------------------------------------------------------------------
    $GLOBALS['gallery_trash_test_columns'] = [
        'galleries' => ['visibility', 'sort_order', 'access_password_hash'],
        'images' => ['title', 'sort_order'],
    ];
    $galleryColumns = \Gallery\Services\gallery_trash_existing_columns('galleries', \Gallery\Services\gallery_trash_restorable_gallery_columns());
    gallery_trash_assert(
        in_array('visibility', $galleryColumns, true)
        && in_array('access_password_hash', $galleryColumns, true)
        && !in_array('nsfw_enabled', $galleryColumns, true)
        && !in_array('gps_map_enabled', $galleryColumns, true),
        'Restore re-applies only gallery columns whose presence was positively confirmed.'
    );
    $imageColumns = \Gallery\Services\gallery_trash_existing_columns('images', \Gallery\Services\gallery_trash_restorable_image_columns());
    gallery_trash_assert(
        $imageColumns === ['title', 'sort_order'],
        'Restore re-applies only image columns whose presence was positively confirmed.'
    );
    gallery_trash_assert(
        \Gallery\Services\gallery_trash_existing_columns('galleries', ['id']) === [],
        'An unconfirmed optional column is omitted instead of guessed.'
    );

    // ---------------------------------------------------------------------
    // 5. Real filesystem move behavior
    // ---------------------------------------------------------------------
    $fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gallery_trash_test_' . bin2hex(random_bytes(6));
    $source = $fixtureRoot . DIRECTORY_SEPARATOR . 'galleries' . DIRECTORY_SEPARATOR . 'trip';
    mkdir($source . DIRECTORY_SEPARATOR . 'sub', 0775, true);
    file_put_contents($source . DIRECTORY_SEPARATOR . 'gallery.json', '{"title":"Trip"}');
    file_put_contents($source . DIRECTORY_SEPARATOR . 'a.jpg', str_repeat('x', 1024));
    file_put_contents($source . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.jpg', str_repeat('y', 2048));

    // Trash storage must be disjoint from the configured live gallery root in both directions.
    $liveRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'live-root';
    $separateTrashRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'separate-trash';
    $nestedTrashRoot = $liveRoot . DIRECTORY_SEPARATOR . 'trash';
    mkdir($liveRoot, 0775, true);
    mkdir($separateTrashRoot, 0775, true);
    mkdir($nestedTrashRoot, 0775, true);
    $GLOBALS['gallery_trash_test_galleries_root'] = $liveRoot;
    \Gallery\Services\gallery_trash_assert_storage_root_disjoint($separateTrashRoot);
    $overlapRejected = false;
    try {
        \Gallery\Services\gallery_trash_assert_storage_root_disjoint($nestedTrashRoot);
    } catch (\RuntimeException) {
        $overlapRejected = true;
    }
    gallery_trash_assert($overlapRejected, 'Trash storage nested under galleries_root is rejected.');
    $parentOverlapRejected = false;
    try {
        \Gallery\Services\gallery_trash_assert_storage_root_disjoint($fixtureRoot);
    } catch (\RuntimeException) {
        $parentOverlapRejected = true;
    }
    gallery_trash_assert($parentOverlapRejected, 'A trash root that contains galleries_root is also rejected.');
    unset($GLOBALS['gallery_trash_test_galleries_root']);

    gallery_trash_assert(
        \Gallery\Services\gallery_trash_directory_size($source) === 1024 + 2048 + 16,
        'The recorded payload size sums every file in the moved subtree.'
    );

    $destination = $fixtureRoot . DIRECTORY_SEPARATOR . 'trash' . DIRECTORY_SEPARATOR . 'payload' . DIRECTORY_SEPARATOR . 'trip';
    \Gallery\Services\gallery_trash_move_directory($source, $destination, $fixtureRoot);
    gallery_trash_assert(
        !is_dir($source)
        && is_file($destination . DIRECTORY_SEPARATOR . 'gallery.json')
        && is_file($destination . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.jpg'),
        'Trashing moves the whole subtree, including sidecars and nested subgalleries, out of the gallery root.'
    );

    // The reverse move is the restore path and must reproduce the original tree.
    \Gallery\Services\gallery_trash_move_directory($destination, $source, $fixtureRoot);
    gallery_trash_assert(
        is_file($source . DIRECTORY_SEPARATOR . 'a.jpg')
        && is_file($source . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.jpg')
        && !is_dir($destination),
        'Restoring moves the complete payload back to its original location.'
    );

    $moveRefused = false;
    try {
        \Gallery\Services\gallery_trash_move_directory($fixtureRoot . DIRECTORY_SEPARATOR . 'missing', $destination, $fixtureRoot);
    } catch (\RuntimeException) {
        $moveRefused = true;
    }
    gallery_trash_assert($moveRefused, 'Moving a missing source folder fails instead of creating an empty trash entry.');

    $overwriteRefused = false;
    try {
        \Gallery\Services\gallery_trash_move_directory($source, $source, $fixtureRoot);
    } catch (\RuntimeException) {
        $overwriteRefused = true;
    }
    gallery_trash_assert($overwriteRefused, 'A move never overwrites an existing destination folder.');

    // The copy fallback used across filesystems must reproduce the tree exactly.
    $copyTarget = $fixtureRoot . DIRECTORY_SEPARATOR . 'copied';
    \Gallery\Services\gallery_trash_copy_directory($source, $copyTarget);
    gallery_trash_assert(
        is_file($copyTarget . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.jpg')
        && (int) filesize($copyTarget . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.jpg') === 2048,
        'The cross-filesystem copy fallback reproduces nested files with their exact content length.'
    );

    gallery_trash_assert(
        \Gallery\Services\gallery_trash_directory_signature($source) === \Gallery\Services\gallery_trash_directory_signature($copyTarget),
        'Cross-filesystem verification includes deterministic path, size, and SHA-256 content identity.'
    );
    file_put_contents($copyTarget . DIRECTORY_SEPARATOR . 'a.jpg', str_repeat('z', 1024));
    gallery_trash_assert(
        \Gallery\Services\gallery_trash_directory_signature($source) !== \Gallery\Services\gallery_trash_directory_signature($copyTarget),
        'Same-size content corruption changes the trash copy verification signature.'
    );

    gallery_trash_test_rmtree($fixtureRoot);

    // ---------------------------------------------------------------------
    // 6. Source contracts
    // ---------------------------------------------------------------------
    $service = (string) file_get_contents($root . '/app/services/gallery_trash.php');
    $bulk = (string) file_get_contents($root . '/app/controllers/admin_galleries_bulk.php');
    $inline = (string) file_get_contents($root . '/app/controllers/admin_public_inline.php');
    $maintenance = (string) file_get_contents($root . '/app/services/site_maintenance.php');
    $policy = (string) file_get_contents($root . '/app/services/mutation_schema_policy.php');
    $dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
    $controller = (string) file_get_contents($root . '/app/controllers/admin_trash.php');

    $trashFunction = (string) substr(
        $service,
        (int) strpos($service, 'function move_gallery_subtrees_to_trash'),
        (int) (strpos($service, 'function gallery_trash_collapse_selected_roots') - strpos($service, 'function move_gallery_subtrees_to_trash'))
    );
    gallery_trash_assert(
        strpos($trashFunction, 'gallery_trash_assert_available') < strpos($trashFunction, 'gallery_trash_move_directory'),
        'Trash storage schema is asserted before the first irreversible filesystem move.'
    );
    gallery_trash_assert(
        strpos($trashFunction, 'write_gallery_sidecar') < strpos($trashFunction, 'gallery_trash_move_directory'),
        'Every gallery sidecar is refreshed before the folder leaves the gallery root, so the payload carries current metadata.'
    );
    // The deletion helper also appears in the earlier stale-row branch, so the
    // ordering contracts compare against its final call on the normal trash path.
    $finalRowDeletion = (int) strrpos($trashFunction, 'gallery_delete_database_subtree_rows');
    gallery_trash_assert(
        strpos($trashFunction, 'gallery_trash_capture_snapshot') < $finalRowDeletion,
        'The restore snapshot is captured while the gallery rows still exist.'
    );
    gallery_trash_assert(
        strpos($trashFunction, 'gallery_trash_move_directory') < $finalRowDeletion,
        'The folder move happens before row deletion so a failed database step can put the gallery back.'
    );
    gallery_trash_assert(
        str_contains($trashFunction, 'gallery_trash_move_directory($payloadPath, $absolutePath, gallery_trash_root())'),
        'A failed trash operation restores the moved folder through the guarded move helper instead of hiding a live gallery.'
    );
    gallery_trash_assert(
        str_contains($trashFunction, 'gallery_trash_refresh_after_structure_change()'),
        'Trashing refreshes hierarchy and public paths after the gallery tree changed.'
    );
    gallery_trash_assert(
        str_contains($service, 'sync_gallery_parent_ids();')
        && str_contains($service, 'refresh_gallery_public_paths();'),
        'The trash service reuses the shared hierarchy and public-path repair helpers.'
    );

    gallery_trash_assert(
        str_contains($service, "path_inside(galleries_root(), \$absolutePath)")
        && str_contains($service, 'Refusing to trash a gallery path outside the gallery root.'),
        'Trashing refuses any path that is not inside the configured gallery root.'
    );
    gallery_trash_assert(
        str_contains($service, 'delete_directory_tree($directory, gallery_trash_entry_storage_root($trashToken))'),
        'Purging destroys files only through the allowed-root guard of the current or legacy managed trash store.'
    );

    gallery_trash_assert(
        str_contains($policy, 'function gallery_trash_schema_status')
        && str_contains($policy, "'mutation.gallery_trash'")
        && str_contains($policy, "'gallery_trash_entries'"),
        'The trash bin registers a dedicated three-state mutation schema capability.'
    );

    foreach (['bulk delete action' => $bulk, 'public inline delete action' => $inline] as $label => $source) {
        gallery_trash_assert(
            str_contains($source, '$trashEnabled = gallery_trash_enabled();')
            && str_contains($source, 'if ($trashEnabled && !gallery_trash_available())'),
            "The {$label} freezes the delete policy and stops on an enabled but unverifiable trash bin instead of deleting permanently."
        );
        gallery_trash_assert(
            str_contains($source, 'move_gallery_subtrees_to_trash'),
            "The {$label} routes an administrator delete through the trash bin."
        );
        gallery_trash_assert(
            strpos($source, 'trash_requires_migration') < strpos($source, 'delete_gallery_subtrees('),
            "The {$label} reports missing trash storage before it can reach the permanent deletion path."
        );
    }

    gallery_trash_assert(
        str_contains($maintenance, 'purge_expired_gallery_trash()'),
        'Scheduled maintenance purges expired trash entries during its cleanup phase.'
    );
    gallery_trash_assert(
        str_contains($service, "status = 'trashed' AND purge_after <= ?"),
        'Automatic purging selects only trashed entries whose retention window has elapsed.'
    );
    gallery_trash_assert(
        str_contains($service, 'gallery_trash_normalize_purge_batch_size($limit ?? gallery_trash_purge_batch_size())'),
        'Automatic purging stays bounded per maintenance slice.'
    );
    gallery_trash_assert(
        !str_contains($service, 'gmdate(') && !str_contains($service, " . ' UTC'"),
        'Trash retention and reconciliation timestamps use the same local application timezone as now_sql().'
    );
    gallery_trash_assert(
        str_contains($trashFunction, "'requested_root_count' => count(\$rootIds)"),
        'Bulk trash results preserve the normalized requested-root count for partial-success reporting.'
    );
    gallery_trash_assert(
        str_contains($service, "'gallery.trash_auto_purged'"),
        'Each successful retention-based permanent purge leaves an Admin audit event.'
    );

    foreach (['admin_trash', 'admin_trash_restore', 'admin_trash_purge', 'admin_trash_empty'] as $route) {
        gallery_trash_assert(
            str_contains($dispatch, "'" . $route . "' => '\\\\Gallery\\\\Controllers\\\\cms_" . $route . "'"),
            'Route ' . $route . ' is registered in the central dispatch table.'
        );
    }

    foreach (['cms_admin_trash_restore', 'cms_admin_trash_purge', 'cms_admin_trash_empty'] as $handler) {
        $start = (int) strpos($controller, 'function ' . $handler);
        $body = (string) substr($controller, $start, 900);
        gallery_trash_assert(
            strpos($body, 'require_admin()') !== false
            && strpos($body, "request_method() !== 'POST'") !== false
            && strpos($body, 'verify_csrf()') !== false
            && strpos($body, 'require_admin()') < strpos($body, 'verify_csrf()'),
            $handler . ' authenticates the administrator, stays POST-only, and validates CSRF before mutating.'
        );
    }
    gallery_trash_assert(
        str_contains($controller, 'admin_mutation_success_envelope')
        && str_contains($controller, 'admin_mutation_error_envelope')
        && str_contains($controller, 'admin_mutation_panel_metadata'),
        'Trash mutations answer enhanced requests with the canonical Admin mutation envelope.'
    );

    // ---------------------------------------------------------------------
    // 7. Migration and translation contracts
    // ---------------------------------------------------------------------
    $migration = (string) file_get_contents($root . '/database/migrations/202609070001_gallery_trash_bin.php');
    $stateMachineMigration = (string) file_get_contents($root . '/database/migrations/202609070002_gallery_trash_state_machine.php');
    gallery_trash_assert(
        str_contains($migration, 'CREATE TABLE IF NOT EXISTS gallery_trash_entries')
        && str_contains($migration, 'trash_token CHAR(32) NOT NULL')
        && str_contains($migration, 'purge_after DATETIME NOT NULL')
        && str_contains($migration, 'gallery_trash_entries_token_unique')
        && str_contains($migration, 'gallery_trash_entries_status_purge_index')
        && str_contains($stateMachineMigration, "status VARCHAR(24) NOT NULL DEFAULT 'preparing'")
        && str_contains($stateMachineMigration, 'snapshot_json LONGTEXT NULL')
        && str_contains($stateMachineMigration, 'snapshot_version SMALLINT UNSIGNED NOT NULL DEFAULT 1')
        && str_contains($stateMachineMigration, 'operation_started_at DATETIME NULL')
        && str_contains($stateMachineMigration, 'last_error_code VARCHAR(64) NULL'),
        'The create and upgrade migrations together provide the crash-safe recoverable-deletion schema.'
    );
    gallery_trash_assert(
        !preg_match('/REFERENCES\s+galleries\s*\(/i', $migration),
        'Trash entries have no foreign key to galleries because they must outlive the rows they describe.'
    );

    $catalogs = [];
    foreach (['en', 'cs', 'de', 'sv'] as $language) {
        $catalogs[$language] = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true);
        gallery_trash_assert(is_array($catalogs[$language]), 'Catalog ' . $language . ' is valid JSON.');
    }
    foreach ([
        'admin.trash.title',
        'admin.trash.restore',
        'admin.trash.restore_confirm',
        'admin.trash.purge',
        'admin.trash.empty',
        'admin.trash.empty_confirm',
        'admin.trash.retention_label',
        'admin.trash.enabled_label',
        'admin.trash.auto_purge_enabled_label',
        'admin.galleries.trashed_result',
        'admin.galleries.trash_requires_migration',
        'admin.dashboard.mutation_schema_feature_gallery_trash',
    ] as $key) {
        foreach ($catalogs as $language => $catalog) {
            gallery_trash_assert(
                isset($catalog[$key]) && trim((string) $catalog[$key]) !== '',
                'Translation key ' . $key . ' exists in the ' . $language . ' catalog.'
            );
        }
    }

    echo "gallery_trash_model_test: PASS\n";
}
