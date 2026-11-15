<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_gallery_edit_test.php
 * Module Type: Linked Gallery Edit Regression Test
 * Purpose: Exercise single-field metadata policy with isolated persistence adapters.
 * Responsibilities:
 *   - Verify explicit scope, fresh revisions and unrelated-field preservation.
 *   - Retain rollback and post-commit sidecar uncertainty as distinct outcomes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the fixture SQL timestamp.
     * @return string Fixed fixture SQL timestamp.
     */
    function now_sql(): string { return '2026-11-15 12:00:00'; }
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
        if (!$commit) {
            $GLOBALS['edit_row'] = $GLOBALS['edit_backup'];
        }
    }

    /**
     * Model the current shared writer lease.
     * @param string $writer Expected fixture writer identity.
     * @return bool True for the admitted fixture writer.
     */
    function admin_operation_model_lock_owned(string $writer): bool
    {
        return $writer === 'fixture-writer';
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
     * @param array{title?:string,description?:string,nsfw_enabled?:int,access_listing?:string} $fields Selected semantic field map.
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
}

namespace Gallery\Services {
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
     * Model the canonical revision reader for small fixture revisions.
     * @param array{edit_revision?:int|string} $row Stored fixture revision.
     * @return string Canonical positive decimal revision, or empty for invalid input.
     */
    function gallery_edit_revision(array $row): string
    {
        $revision = (string) ($row['edit_revision'] ?? '');
        return preg_match('/\A[1-9][0-9]*\z/D', $revision) === 1 ? $revision : '';
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
        if ($path !== 'stable-folder') {
            throw new \RuntimeException('Unexpected fixture folder.');
        }
    }

    /**
     * Acquire the isolated fixture writer.
     * @return string Owned fixture writer identifier.
     */
    function gallery_edit_writer_begin(): string { return 'fixture-writer'; }

    /**
     * Release the isolated fixture writer.
     * @param string $writer Captured fixture writer identity.
     * @return void No external lock is held by this test.
     */
    function gallery_edit_writer_end(string $writer): void {}

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
     * @param array{id:int,title:string,description:string,edit_revision:string,visibility:string,access_listing:string,nsfw_enabled:int,folder_path:string,sort_order:int,updated_at:string} $row Complete confirmed row.
     * @return bool Whether the fixture permits sidecar completion.
     */
    function write_gallery_sidecar(array $row): bool
    {
        $GLOBALS['edit_sidecar'] = $row;
        return $GLOBALS['edit_sidecar_ok'];
    }
}

namespace {
    require_once __DIR__ . '/../app/services/security_tokens.php';
    require_once __DIR__ . '/../app/services/app_linking_policy.php';
    require_once __DIR__ . '/../app/services/app_linking_gallery_edit.php';

    $check = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    };
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
    $GLOBALS['edit_row'] = [
        'id' => 7, 'title' => 'Server title',
        // A description longer than the existing 512-character info projection.
        'description' => str_repeat('Complete description. ', 40),
        'edit_revision' => '9', 'visibility' => 'public',
        'access_listing' => 'listed', 'nsfw_enabled' => 0,
        'folder_path' => 'stable-folder', 'sort_order' => 6, 'updated_at' => 'before',
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
            && $GLOBALS['edit_row']['folder_path'] === 'stable-folder'
            && $GLOBALS['edit_invalidated'] === [7]
            && $GLOBALS['edit_sidecar'] === $GLOBALS['edit_row'],
            'Single-field edits must confirm canonical state without moving folders.');
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
    }
    $GLOBALS['edit_tamper'] = false;
    $GLOBALS['edit_sidecar_ok'] = false;
    try {
        \Gallery\Services\app_linking_gallery_edit_save(
            $identity, $GLOBALS['edit_token'], 'https://gallery.example.test', 7,
            $before['edit_revision'], 'title', 'Committed but unconfirmed',
        );
        throw new \LogicException('Failed sidecar was acknowledged.');
    } catch (\RuntimeException $error) {
        $check(!$error instanceof \LogicException
            && $GLOBALS['edit_row']['title'] === 'Committed but unconfirmed',
            'Sidecar uncertainty must not claim success or invent rollback.');
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
    echo "linked gallery metadata edit tests passed\n";
}
