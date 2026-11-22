<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_gallery_edit_test.php
 * Module Type: Linked Gallery Edit Regression Test
 * Purpose: Exercise native metadata edits, real folder moves and shared writer ownership.
 * Responsibilities:
 *   - Verify explicit scope, fresh revisions and unrelated-field preservation.
 *   - Retain rollback and post-commit sidecar uncertainty as distinct outcomes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return the fixture's advisory-SQL adapter connection.
     * @return \PDO Disposable SQLite connection, never installation storage.
     */
    function db(): \PDO { return $GLOBALS['edit_writer_database']; }

    /** Configure only the disposable physical-gallery fixture.
     * @return array{galleries_root:string} Owned temporary storage root.
     */
    function cms_config(): array { return ['galleries_root' => $GLOBALS['edit_root']]; }
}

namespace Gallery\Models {
    /**
     * Admit the fixture's exact live grant and retain rollback state.
     * @param int $userId Expected fixture Admin identity.
     * @param int $grantId Expected fixture grant identity.
     * @param string $hash Presented fixture bearer digest.
     * @param string $issuer Expected fixture installation.
     * @param string $client Expected registered WinApp client.
     * @param string $scope Exact separately consented bundle.
     * @return bool True while the fixture grant is current.
     */
    function app_link_model_create_transaction_begin(
        int $userId, int $grantId, string $hash, string $issuer,
        string $client, string $scope,
    ): bool {
        $GLOBALS['edit_backup'] = $GLOBALS['edit_row'];
        $GLOBALS['edit_child_backup'] = $GLOBALS['edit_child'];
        return $GLOBALS['edit_grant'] && $userId === 3 && $grantId === 4
            && $hash === hash('sha256', $GLOBALS['edit_token'])
            && $issuer === 'https://gallery.example.test'
            && $client === 'php-gallery-winapp'
            && $scope === $GLOBALS['edit_scope'];
    }

    /**
     * Commit or roll back the in-memory persistence boundary.
     * @param bool $commit Whether the service confirmed its pre-commit checks.
     * @return void Restores the snapshot only for rollback.
     */
    function app_link_model_create_transaction_end(bool $commit): void
    {
        if ($GLOBALS['edit_commit_lost']) throw new \RuntimeException('Transaction outcome unknown.');
        if ($commit && $GLOBALS['edit_lose_commit']) {
            $GLOBALS['edit_commit_lost'] = true;
            throw new \RuntimeException('Commit reply lost.');
        }
        if (!$commit) {
            $GLOBALS['edit_row'] = $GLOBALS['edit_backup'];
            $GLOBALS['edit_child'] = $GLOBALS['edit_child_backup'];
        }
    }

    /**
     * Model the current shared writer lease.
     * @param string $writer Actual connection-owned advisory lock name.
     * @return bool True for the admitted fixture writer.
     */
    function admin_operation_model_lock_owned(string $writer): bool
    {
        return $GLOBALS['edit_writer_depth'] > 0
            && $writer === $GLOBALS['edit_writer_name'];
    }

    /**
     * Return the locked complete fixture row.
     * @param int $galleryId Exact fixture gallery identity.
     * @return array{id:int,title:string,description:string,edit_revision:string,visibility:string,access_listing:string,nsfw_enabled:int,folder_path:string,sort_order:int,updated_at:string}|null Current row or missing target.
     */
    function gallery_model_linked_visibility_lock(int $galleryId): ?array
    {
        return $galleryId === 7 ? $GLOBALS['edit_row'] : null;
    }

    /**
     * Model the existing field writer and explicit revision increment.
     * @param int $galleryId Exact physical fixture identity.
     * @param array{title?:string,description?:string,nsfw_enabled?:int,access_listing?:string,folder_path?:string,folder_path_hash?:string,parent_id?:?int} $fields Selected metadata or moved-root path fields.
     * @param string $now Caller-owned SQL timestamp.
     * @return void Applies the field and optionally injects unrelated trigger drift.
     */
    function gallery_model_update_fields(int $galleryId, array $fields, string $now): void
    {
        if ($galleryId !== 7) {
            throw new \RuntimeException('Wrong fixture gallery.');
        }
        $GLOBALS['edit_writes']++;
        $GLOBALS['edit_row'] = array_replace($GLOBALS['edit_row'], $fields);
        $GLOBALS['edit_row']['edit_revision'] = (string) (
            (int) $GLOBALS['edit_row']['edit_revision'] + 1
        );
        $GLOBALS['edit_row']['updated_at'] = $now;
        if ($GLOBALS['edit_tamper']) {
            $GLOBALS['edit_row']['visibility'] = 'private';
        }
    }

    /**
     * Read complete state after the sidecar publication boundary.
     * @param int $galleryId Exact fixture gallery identity.
     * @return array{id:int,title:string,description:string,edit_revision:string,visibility:string,access_listing:string,nsfw_enabled:int,folder_path:string,sort_order:int,updated_at:string}|null Persisted row or missing target.
     */
    function gallery_model_find_by_id(int $galleryId): ?array
    {
        return gallery_model_linked_visibility_lock($galleryId);
    }

    /** Apply the real mover's complete path map at the persistence boundary.
     * @param list<array{id:int,folder_path:string,folder_path_hash:string,parent_id:?int,title?:string}> $updates Computed subtree map.
     * @param string $now Shared SQL timestamp.
     * @return void Updates root and descendant fixture rows.
     */
    function gallery_mutation_model_update_gallery_paths(array $updates, string $now): void
    {
        foreach ($updates as $update) {
            $id = $update['id'];
            unset($update['id']);
            if ($id === 7) gallery_model_update_fields($id, $update, $now);
            else $GLOBALS['edit_child'] = array_replace($GLOBALS['edit_child'], $update);
        }
        if ($GLOBALS['edit_db_failure']) throw new \RuntimeException('Injected path persistence failure.');
    }

    /** Read the fixture's actual complete subtree.
     * @param string $path Root relative path.
     * @param string $pattern Escaped descendant selector.
     * @return list<array{id:int,folder_path:string,parent_id:?int,...}> Root and child rows.
     */
    function gallery_mutation_model_subtree_rows(string $path, string $pattern): array
    {
        return [$GLOBALS['edit_row'], $GLOBALS['edit_child']];
    }

    /** Read stable fixture subtree identities after its physical rename.
     * @param string $path Current root path.
     * @param string $pattern Escaped descendant selector.
     * @param bool $deepestFirst Whether children must precede their root.
     * @return list<int> The two fixture gallery IDs.
     */
    function gallery_mutation_model_subtree_ids(string $path, string $pattern, bool $deepestFirst = false): array
    {
        return $deepestFirst ? [8, 7] : [7, 8];
    }

    /** Supply the consistent parent graph to real hierarchy completion.
     * @return list<array{id:int,folder_path:string,parent_id:?int,...}> Current rows.
     */
    function gallery_mutation_model_hierarchy_rows(): array
    {
        return [$GLOBALS['edit_row'], $GLOBALS['edit_child']];
    }

    /** Verify that a rename preserves parent relationships.
     * @param list<array{id:int,folder_path:string,parent_id:?int,...}> $rows Current hierarchy.
     * @param array<int,int> $parents Desired parents, zero meaning storage root.
     * @param string $now Shared timestamp.
     * @return bool False because the correct fixture graph needs no repair.
     */
    function gallery_mutation_model_sync_parent_map(array $rows, array $parents, string $now): bool
    {
        if ($parents !== [7 => 0, 8 => 7]) throw new \RuntimeException('Wrong subtree graph.');
        return false;
    }
}

namespace Gallery\Services {
    /** Preserve safe English messages at the external localization boundary.
     * @param string $key Translation identity requested by the actual writer.
     * @param string $fallback Bounded prepared English message.
     * @return string Unmodified fixture fallback text.
     */
    function t(string $key, string $fallback = ''): string { return $fallback; }

    /** Read current fixture rows at the catalogue boundary.
     * @param int $id Selected gallery ID.
     * @param bool $fresh Whether stale request caches must be bypassed.
     * @return array{id:int,folder_path:string,parent_id:?int,...}|null Current row.
     */
    function find_gallery(int $id, bool $fresh = false): ?array
    {
        return $id === 7 ? $GLOBALS['edit_row'] : ($id === 8 ? $GLOBALS['edit_child'] : null);
    }

    /** Resolve fixture-owned catalogue paths for collision/ancestor checks.
     * @param string $path Exact relative gallery directory.
     * @return array{id:int,folder_path:string,parent_id:?int,...}|null Matching row.
     */
    function find_gallery_by_folder_path(string $path): ?array
    {
        foreach ([$GLOBALS['edit_row'], $GLOBALS['edit_child']] as $row) {
            if ($row['folder_path'] === $path) return $row;
        }
        return null;
    }

    /** Supply the externally inspected move schema state.
     * @return array{state:string} Fixture schema observation.
     */
    function gallery_move_schema_status(): array
    {
        return ['state' => $GLOBALS['edit_schema'] ? 'available' : 'unknown'];
    }

    /** Reject physical changes when schema inspection is uncertain.
     * @param array{state:string} $status Inspected schema state.
     * @param string $operation Semantic mutation identity.
     * @param string $missing Safe missing-schema description.
     * @param string $unknown Safe uncertainty description.
     * @return void Admits only confirmed schema availability.
     */
    function mutation_schema_assert_available(array $status, string $operation, string $missing, string $unknown): void
    {
        if ($status['state'] !== 'available') throw new \RuntimeException($unknown);
    }

    /** Keep optional public-path storage absent in this isolated fixture.
     * @return bool False; public-path regeneration has its existing DB coverage.
     */
    function public_path_schema_ready(): bool { return false; }
    /** Represent a confirmed pre-write stale revision in the isolated service. */
    final class LinkedGalleryVisibilityConflict extends \RuntimeException {}

    /**
     * Provide schema inspection at its external boundary.
     * @param string $operation Service-owned operation identity.
     * @param array<string,list<string>> $tables Required columns per table.
     * @param array<string,array<string,list<string>>> $indexes Required indexed columns.
     * @param list<string> $transactional Required transactional table names.
     * @return array{state:string} Fixture's explicit available or unknown observation.
     */
    function app_linking_schema_status(
        string $operation, array $tables, array $indexes, array $transactional,
    ): array {
        return ['state' => $GLOBALS['edit_schema'] ? 'available' : 'unknown'];
    }

    /**
     * Consume the explicit fixture schema state.
     * @param array{state:string} $status Structured inspection observation.
     * @return bool True only for confirmed availability.
     */
    function schema_inspection_is_available(array $status): bool
    {
        return $status['state'] === 'available';
    }

    /**
     * Model current scoped gallery authorization at its persistence boundary.
     * @param array{grant_id:int,user_id:int,username:string,scope:string} $identity Captured fixture Admin identity.
     * @param string $token Exact fixture bearer.
     * @param string $issuer Exact fixture installation.
     * @param int $galleryId Selected fixture gallery.
     * @return array{id:int,title:string,description:string,edit_revision:string,visibility:string,access_listing:string,nsfw_enabled:int,folder_path:string,sort_order:int,updated_at:string}|null Current authorized row or refusal.
     */
    function app_linking_admin_media_gallery(
        array $identity, string $token, string $issuer, int $galleryId,
    ): ?array {
        return $GLOBALS['edit_grant'] && $identity['user_id'] === 3
            && $token === $GLOBALS['edit_token'] && $issuer === 'https://gallery.example.test'
            ? \Gallery\Models\gallery_model_find_by_id($galleryId) : null;
    }

    /**
     * Model the canonical revision increment for small fixture revisions.
     * @param string $revision Reviewed fixture revision.
     * @return string Next fixture revision.
     */
    function app_linking_visibility_next_revision(string $revision): string
    {
        return (string) ((int) $revision + 1);
    }

    /**
     * Model a clear filesystem recovery boundary without writing local files.
     * @param string $path Exact fixture-owned folder spelling.
     * @return void Refuses an unexpected path.
     */
    function gallery_visibility_marker_assert_importable(string $path): void
    {
        if (!is_dir(gallery_abs_path($path))) {
            throw new \RuntimeException('Unexpected fixture folder.');
        }
    }

    /**
     * Reset the fixture schema-inspection boundary.
     * @return void Resets no state in the isolated schema adapter.
     */
    function schema_inspection_reset_request_cache(): void {}

    /**
     * Admit the isolated native read schema through the real scope policy.
     * @param bool $presentation Whether playback storage is requested.
     * @return bool True for the fixture's explicitly available media storage.
     */
    function app_linking_media_ready(bool $presentation): bool { return true; }

    /**
     * Admit the fixture Admin media storage through the real scope policy.
     * @return bool True for the explicitly available fixture storage.
     */
    function app_linking_admin_media_ready(): bool { return true; }

    /**
     * Return the fixture's explicit wide-scope schema observation.
     * @return array{state:string} Available schema observation for the fixture.
     */
    function app_linking_wide_scope_schema_status(): array
    {
        return ['state' => 'available'];
    }

    /**
     * Admit the isolated fixture's specialized visibility schema.
     * @return void The fixture models already verified visibility storage.
     */
    function app_linking_visibility_require_schema(): void {}

    /**
     * Admit the fixture public-access schema.
     * @return void The isolated fixture has verified public-access storage.
     */
    function gallery_access_assert_public_policy_available(): void {}

    /**
     * Admit the fixture NSFW schema.
     * @return void The isolated fixture has verified NSFW storage.
     */
    function nsfw_guard_assert_public_policy_available(): void {}

    /**
     * Record content invalidation after commit.
     * @param list<int> $ids Exact gallery identities whose content changed.
     * @return void Retains the service's invalidation scope for assertions.
     */
    function gallery_visit_content_update(array $ids): void
    {
        $GLOBALS['edit_invalidated'] = $ids;
    }

    /**
     * Model canonical sidecar publication and its post-commit failure.
     * @param array{id:int,folder_path:string,parent_id:?int,folder_path_hash?:string,title?:string,description?:string,edit_revision?:string,visibility?:string,access_listing?:string,nsfw_enabled?:int,sort_order?:int,updated_at?:string} $row Confirmed root or minimal descendant fixture row.
     * @return bool Whether the fixture permits sidecar completion.
     */
    function write_gallery_sidecar(array $row): bool
    {
        if ($row['id'] === 7) $GLOBALS['edit_sidecar'] = $row;
        return $GLOBALS['edit_sidecar_ok'];
    }
}

namespace {
    require_once __DIR__ . '/../app/helpers_files.php';
    require_once __DIR__ . '/../app/helpers_runtime.php';
    require_once __DIR__ . '/../app/services/gallery_paths.php';
    require_once __DIR__ . '/../app/services/gallery_mutations.php';
    require_once __DIR__ . '/../app/services/security_tokens.php';
    require_once __DIR__ . '/../app/services/app_linking_policy.php';
    require_once __DIR__ . '/../app/services/app_linking_gallery_edit.php';

    $check = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    };
    if (!extension_loaded('pdo_sqlite')) {
        throw new \RuntimeException('Required disposable SQLite driver is unavailable.');
    }
    $GLOBALS['edit_writer_depth'] = 0;
    $GLOBALS['edit_writer_name'] = null;
    $lockDatabase = new \PDO('sqlite::memory:', null, null,
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $lockDatabase->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL, updated_at TEXT NOT NULL)');
    $GLOBALS['edit_writer_database'] = $lockDatabase;
    // Execute the actual writer service/model, replacing only MySQL-specific
    // SQL primitives at the driver boundary. Arity 0/2/1 mirrors that protocol;
    // return codes 1/0 represent owned/busy rather than bypassing ownership.
    $lockDatabase->sqliteCreateFunction('DATABASE', static fn(): string => 'linked-edit-fixture', 0);
    $lockDatabase->sqliteCreateFunction('GET_LOCK', static function (string $name, int $seconds): int {
        if ($seconds !== \Gallery\Core\GALLERY_EDIT_LOCK_WAIT_SECONDS
            || ($GLOBALS['edit_writer_name'] !== null && $GLOBALS['edit_writer_name'] !== $name)) {
            return 0;
        }
        $GLOBALS['edit_writer_name'] = $name;
        $GLOBALS['edit_writer_depth']++;
        return 1;
    }, 2);
    $lockDatabase->sqliteCreateFunction('RELEASE_LOCK', static function (string $name): int {
        if ($GLOBALS['edit_writer_name'] !== $name || $GLOBALS['edit_writer_depth'] <= 0) return 0;
        $GLOBALS['edit_writer_depth']--;
        if ($GLOBALS['edit_writer_depth'] === 0) $GLOBALS['edit_writer_name'] = null;
        return 1;
    }, 1);
    $GLOBALS['edit_token'] = \Gallery\Services\app_linking_access_token_generate();
    $GLOBALS['edit_scope'] = \Gallery\Services\APP_LINKING_VISIBILITY_SCOPE
        . \Gallery\Services\APP_LINKING_ADMIN_MEDIA_SUFFIX
        . \Gallery\Services\APP_LINKING_GALLERY_EDIT_SUFFIX;
    $identity = ['grant_id' => 4, 'user_id' => 3, 'username' => 'fixture',
        'scope' => $GLOBALS['edit_scope']];
    $GLOBALS['edit_grant'] = true;
    $GLOBALS['edit_schema'] = true;
    $GLOBALS['edit_tamper'] = false;
    $GLOBALS['edit_sidecar_ok'] = true;
    $GLOBALS['edit_writes'] = 0;
    $GLOBALS['edit_db_failure'] = false;
    $GLOBALS['edit_commit_lost'] = false;
    $GLOBALS['edit_lose_commit'] = false;
    $GLOBALS['edit_root'] = sys_get_temp_dir() . '/linked-gallery-edit-' . bin2hex(random_bytes(8));
    mkdir($GLOBALS['edit_root'] . '/stable-folder/child', 0700, true);
    file_put_contents($GLOBALS['edit_root'] . '/stable-folder/child/photo.jpg', 'owned image bytes');
    try {
    $GLOBALS['edit_child'] = ['id' => 8, 'folder_path' => 'stable-folder/child', 'parent_id' => 7];
    $GLOBALS['edit_row'] = [
        'id' => 7, 'title' => 'Server title',
        // A description longer than the existing 512-character info projection.
        'description' => str_repeat('Complete description. ', 40),
        'edit_revision' => '9', 'visibility' => 'public',
        'access_listing' => 'listed', 'nsfw_enabled' => 0,
        'folder_path' => 'stable-folder', 'folder_path_hash' => hash('sha256', 'stable-folder'),
        'parent_id' => null, 'sort_order' => 6, 'updated_at' => 'before',
    ];
    $read = \Gallery\Services\app_linking_gallery_edit_read(
        $identity, $GLOBALS['edit_token'], 'https://gallery.example.test', 7,
    );
    $check($read['description'] === $GLOBALS['edit_row']['description']
        && $read['revision'] === '9',
        'Editor reads must retain complete server text and revision.');
    $check(!\Gallery\Services\app_linking_scope_allows_gallery_edit(
        \Gallery\Services\APP_LINKING_VISIBILITY_SCOPE
            . \Gallery\Services\APP_LINKING_ADMIN_MEDIA_SUFFIX,
    ), 'Existing Admin visibility consent cannot gain metadata authority.');
    foreach (['title' => 'Renamed', 'description' => '', 'nsfw' => true, 'listed' => false] as $field => $value) {
        $before = $GLOBALS['edit_row'];
        $saved = \Gallery\Services\app_linking_gallery_edit_save(
            $identity, $GLOBALS['edit_token'], 'https://gallery.example.test', 7,
            $before['edit_revision'], $field, $value,
        );
        $check($saved[$field] === $value
            && $saved['revision'] === (string) ((int) $before['edit_revision'] + 1)
            && $GLOBALS['edit_row']['folder_path'] === 'renamed'
            && in_array(7, $GLOBALS['edit_invalidated'], true)
            && $GLOBALS['edit_sidecar'] === $GLOBALS['edit_row'],
            'Edits must confirm canonical state and retain the renamed physical directory.');
        $check(is_file($GLOBALS['edit_root'] . '/renamed/child/photo.jpg')
            && !is_dir($GLOBALS['edit_root'] . '/stable-folder')
            && $GLOBALS['edit_child']['folder_path'] === 'renamed/child',
            'Native title edit did not move the real descendant/media directory.');
    }
    $before = $GLOBALS['edit_row'];
    try {
        \Gallery\Services\app_linking_gallery_edit_save(
            $identity, $GLOBALS['edit_token'], 'https://gallery.example.test', 7,
            '9', 'title', 'Stale title',
        );
        throw new \LogicException('Stale save was accepted.');
    } catch (\Gallery\Services\LinkedGalleryVisibilityConflict) {
        $check($before === $GLOBALS['edit_row'], 'A stale save changed persisted state.');
    }
    foreach (['private', 'unpublished'] as $visibility) {
        $GLOBALS['edit_row']['visibility'] = $visibility;
        $hidden = $GLOBALS['edit_row'];
        try {
            \Gallery\Services\app_linking_gallery_edit_save(
                $identity, $GLOBALS['edit_token'], 'https://gallery.example.test', 7,
                $hidden['edit_revision'], 'listed', true,
            );
            throw new \LogicException('Nonpublic listing override was changed.');
        } catch (\Gallery\Services\LinkedGalleryVisibilityConflict) {
            $check($hidden === $GLOBALS['edit_row'],
                'Nonpublic listing must stay effectively off without losing its override.');
        }
    }
    $GLOBALS['edit_row'] = $before;
    mkdir($GLOBALS['edit_root'] . '/collision');
    try {
        \Gallery\Services\app_linking_gallery_edit_save(
            $identity, $GLOBALS['edit_token'], 'https://gallery.example.test', 7,
            $before['edit_revision'], 'title', 'Collision',
        );
        throw new \LogicException('Occupied physical destination was accepted.');
    } catch (\RuntimeException $error) {
        $check(!$error instanceof \LogicException && $before === $GLOBALS['edit_row']
            && is_file($GLOBALS['edit_root'] . '/renamed/child/photo.jpg'),
            'Destination collision must preserve source bytes and metadata.');
    }
    rmdir($GLOBALS['edit_root'] . '/collision');
    $GLOBALS['edit_schema'] = false;
    try {
        \Gallery\Services\app_linking_gallery_edit_save(
            $identity, $GLOBALS['edit_token'], 'https://gallery.example.test', 7,
            $before['edit_revision'], 'title', 'Unknown schema',
        );
        throw new \LogicException('Unknown schema admitted a title move.');
    } catch (\RuntimeException $error) {
        $check(!$error instanceof \LogicException && $before === $GLOBALS['edit_row']
            && !is_dir($GLOBALS['edit_root'] . '/unknown-schema'), 'Unknown schema must fail before mutation.');
    }
    $GLOBALS['edit_schema'] = true;
    $GLOBALS['edit_tamper'] = true;
    try {
        \Gallery\Services\app_linking_gallery_edit_save(
            $identity, $GLOBALS['edit_token'], 'https://gallery.example.test', 7,
            $before['edit_revision'], 'title', 'Trigger drift',
        );
        throw new \LogicException('Unrelated trigger changes were accepted.');
    } catch (\RuntimeException $error) {
        $check(!$error instanceof \LogicException && $before === $GLOBALS['edit_row'],
            'Unrelated field changes must roll back before commit.');
        $check(is_file($GLOBALS['edit_root'] . '/renamed/child/photo.jpg')
            && !is_dir($GLOBALS['edit_root'] . '/trigger-drift'),
            'Confirmed DB rollback must restore physical subtree bytes.');
    }
    $GLOBALS['edit_tamper'] = false;
    $GLOBALS['edit_db_failure'] = true;
    try {
        \Gallery\Services\app_linking_gallery_edit_save(
            $identity, $GLOBALS['edit_token'], 'https://gallery.example.test', 7,
            $before['edit_revision'], 'title', 'Database failure',
        );
        throw new \LogicException('Failed path persistence was accepted.');
    } catch (\RuntimeException $error) {
        $check(!$error instanceof \LogicException && $before === $GLOBALS['edit_row']
            && is_file($GLOBALS['edit_root'] . '/renamed/child/photo.jpg')
            && !is_dir($GLOBALS['edit_root'] . '/database-failure'),
            'Database failure must restore the directory only after confirmed rollback.');
    }
    $GLOBALS['edit_db_failure'] = false;
    $GLOBALS['edit_sidecar_ok'] = false;
    try {
        \Gallery\Services\app_linking_gallery_edit_save(
            $identity, $GLOBALS['edit_token'], 'https://gallery.example.test', 7,
            $before['edit_revision'], 'title', 'Committed but unconfirmed',
        );
        throw new \LogicException('Failed sidecar was acknowledged.');
    } catch (\RuntimeException $error) {
        $check(!$error instanceof \LogicException
            && $GLOBALS['edit_row']['title'] === 'Committed but unconfirmed'
            && is_file($GLOBALS['edit_root'] . '/committed-but-unconfirmed/child/photo.jpg'),
            'Sidecar uncertainty must not claim success or invent rollback.');
    }
    $GLOBALS['edit_sidecar_ok'] = true;
    $GLOBALS['edit_lose_commit'] = true;
    try {
        \Gallery\Services\app_linking_gallery_edit_save(
            $identity, $GLOBALS['edit_token'], 'https://gallery.example.test', 7,
            $GLOBALS['edit_row']['edit_revision'], 'title', 'Lost commit',
        );
        throw new \LogicException('Ambiguous commit was acknowledged.');
    } catch (\RuntimeException $error) {
        $check(!$error instanceof \LogicException && $GLOBALS['edit_commit_lost']
            && $GLOBALS['edit_row']['title'] === 'Lost commit'
            && is_file($GLOBALS['edit_root'] . '/lost-commit/child/photo.jpg'),
            'Ambiguous commit must preserve recovery evidence without inventing rollback.');
    }
    $GLOBALS['edit_grant'] = false;
    try {
        \Gallery\Services\app_linking_gallery_edit_read(
            $identity, $GLOBALS['edit_token'], 'https://gallery.example.test', 7,
        );
        throw new \LogicException('Revoked grant was accepted.');
    } catch (\RuntimeException $error) {
        $check(!$error instanceof \LogicException, 'Revocation must fail closed.');
    }
    } finally {
        \Gallery\Services\delete_directory_tree($GLOBALS['edit_root'], dirname($GLOBALS['edit_root']));
        $check($GLOBALS['edit_writer_depth'] === 0 && $GLOBALS['edit_writer_name'] === null,
            'The actual writer model leaked an advisory acquisition after a terminal outcome.');
    }
    echo "linked gallery metadata edit tests passed\n";
}
