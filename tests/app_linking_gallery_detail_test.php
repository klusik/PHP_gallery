<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_gallery_detail_test.php
 * Module Type: Database Regression Test
 * Purpose: Verify native gallery-read and search isolation with owned fixture records.
 * Responsibilities:
 *   - Keep legacy scopes separate from explicitly approved gallery reads.
 *   - Recheck live grant, issuer and safe stored detail projections.
 *   - Rank searchable gallery fields and return display ancestry only.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return only the verified disposable workflow fixture connection.
     *
     * @return \PDO Connection to the explicitly owned disposable database.
     */
    function db(): \PDO
    {
        return $GLOBALS['app_link_detail_test_db'];
    }
}

namespace Gallery\Services {
    /**
     * Model an owned writer lease for pre-mutation authority tests.
     *
     * @return string Fixture lease identifier.
     */
    function gallery_edit_writer_begin(): string
    {
        return 'fixture';
    }

    /**
     * Release the fixture writer lease.
     *
     * @param string $lock Fixture lease identifier.
     * @return void The fixture has no external lock.
     */
    function gallery_edit_writer_end(string $lock): void {}

    /**
     * Record delegation after all preconditions pass, without deleting files.
     *
     * @param int $galleryId Owning gallery identifier.
     * @param list<int> $ids Validated photo identifiers.
     * @return array{deleted:int} Number of fixture photos accepted.
     */
    function delete_gallery_images_owned(int $galleryId, array $ids): array
    {
        $GLOBALS['app_link_delete_calls'][] = [$galleryId, $ids];
        return ['deleted' => count($ids)];
    }
}

namespace {
    use function Gallery\Models\app_link_model_exchange;
    use function Gallery\Models\app_link_model_issue;
    use function Gallery\Models\app_link_model_revoke;
    use function Gallery\Services\app_linking_access_token_generate;
    use function Gallery\Services\app_linking_access_token_hash;
    use function Gallery\Services\app_linking_admin_media_change_photo_visibility;
    use function Gallery\Services\app_linking_admin_media_gallery;
    use function Gallery\Services\app_linking_authenticate;
    use function Gallery\Services\app_linking_gallery_detail;
    use function Gallery\Services\app_linking_gallery_page;
    use function Gallery\Services\app_linking_gallery_search_page;
    use function Gallery\Services\app_linking_media_gallery;
    use function Gallery\Services\app_linking_media_page;
    use function Gallery\Services\gallery_admin_media_preload_gallery_allowed;
    use function Gallery\Services\app_linking_scope_allowed;
    use function Gallery\Services\app_linking_upload_target;
    use function GalleryWorkflow\check;
    use function GalleryWorkflow\fixtureDatabase;
    use function GalleryWorkflow\validateFixture;

    require_once __DIR__ . '/support/gallery_workflow_safety.php';
    require_once __DIR__ . '/../app/models/schema_inspection.php';
    require_once __DIR__ . '/../app/services/schema_inspection.php';
    require_once __DIR__ . '/../app/services/mutation_schema_policy.php';
    require_once __DIR__ . '/../app/models/app_linking.php';
    require_once __DIR__ . '/../app/models/galleries.php';
    require_once __DIR__ . '/../app/models/images.php';
    require_once __DIR__ . '/../app/helpers_runtime.php';
    require_once __DIR__ . '/../app/services/content_localization.php';
    require_once __DIR__ . '/../app/services/public_search.php';
    require_once __DIR__ . '/../app/services/tag_metadata.php';
    require_once __DIR__ . '/../app/services/app_linking_schema.php';
    require_once __DIR__ . '/../app/services/app_linking_policy.php';
    require_once __DIR__ . '/../app/services/security_tokens.php';
    require_once __DIR__ . '/../app/services/app_linking.php';
    require_once __DIR__ . '/../app/services/gallery_access.php';
    require_once __DIR__ . '/../app/services/app_linking_media.php';

    if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
        $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
        echo ($required ? 'BLOCKED' : 'SKIP')
            . " gallery detail requires an owned disposable database\n";
        exit($required ? 1 : 0);
    }

    $pdo = null;
    $userId = 0;
    $galleryId = 0;
    $nsfwParentId = 0;
    $searchGalleries = [];
    $tagId = 0;
    try {
        $owner = (string) getenv('GALLERY_WORKFLOW_TOKEN');
        $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $owner);
        $pdo = fixtureDatabase($directory, $owner);
        $GLOBALS['app_link_detail_test_db'] = $pdo;
        $username = 'link_read_' . bin2hex(random_bytes(12));
        $folder = 'link_read_' . bin2hex(random_bytes(12));
        $insert = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, created_at, updated_at) '
                . "VALUES (?, 'fixture-only', 'admin', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        );
        $insert->execute([$username]);
        $userId = (int) $pdo->lastInsertId();
        $insert = $pdo->prepare(
            'INSERT INTO galleries (folder_path, folder_path_hash, slug, title, '
                . 'visibility, access_mode, access_listing, access_password_hash, '
                . 'created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, '
                . 'UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        );
        $insert->execute([
            $folder, hash('sha256', $folder), $folder, 'Stored private gallery',
            'private', 'password', 'unlisted', 'fixture-secret-hash',
        ]);
        $galleryId = (int) $pdo->lastInsertId();
        check($userId > 0 && $galleryId > 0, 'Owned gallery and Admin are required.');

        $client = 'php-gallery-winapp';
        $issuer = 'https://gallery.example.test/Galerie';
        $redirect = 'http://127.0.0.1:1234/oauth2redirect/php-gallery';
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
        $readScope = \Gallery\Services\APP_LINKING_READ_SCOPE;
        check(app_linking_scope_allowed($client, $readScope),
            'Verified wide storage must admit the exact read scope.');
        $issue = static function (string $scope) use (
            $userId, $client, $issuer, $redirect, $challenge,
        ): array {
            $code = hash('sha256', random_bytes(32));
            $token = app_linking_access_token_generate();
            $tokenHash = app_linking_access_token_hash($token);
            check(is_string($tokenHash), 'Generated access bearer is invalid.');
            $id = app_link_model_issue(
                $userId, $client, $issuer, $scope, static fn(): string => $code,
                $redirect, $challenge, \Gallery\Services\APP_LINKING_CODE_TTL_SECONDS,
            );
            check(is_int($id), 'Fixture code issuance failed.');
            $grant = app_link_model_exchange(
                $code, $client, $issuer, $redirect, $challenge, [$scope],
                static fn(): string => $tokenHash,
                \Gallery\Services\APP_LINKING_ACCESS_TTL_SECONDS,
            );
            check(is_array($grant), 'Fixture code exchange failed.');
            return [$token, (int) $grant['grant_id']];
        };
        [$readToken, $readGrant] = $issue($readScope);
        [$oldToken] = $issue(\Gallery\Services\APP_LINKING_UPLOAD_SCOPE);
        [$combinedToken] = $issue(\Gallery\Services\APP_LINKING_READ_UPLOAD_SCOPE);
        $detail = app_linking_gallery_detail($readToken, $issuer, $galleryId);
        check($detail === [
            'id' => $galleryId,
            'title' => 'Stored private gallery',
            'revision' => '1',
            'visibility' => 'private',
            'access_mode' => 'password',
            'access_listing' => 'unlisted',
        ], 'The read projection must contain only exact stored non-secret values.');
        check(app_linking_gallery_detail($oldToken, $issuer, $galleryId) === null
            && app_linking_gallery_detail($readToken, 'https://other.example', $galleryId) === null
            && app_linking_gallery_detail($readToken, $issuer, PHP_INT_MAX) === null
            && app_linking_upload_target($readToken, $issuer, $galleryId) === null,
            'Old upload-only authority and wrong targets must not gain gallery reads.');
        check(app_linking_gallery_detail($combinedToken, $issuer, $galleryId) === $detail
            && app_linking_upload_target($combinedToken, $issuer, $galleryId) !== null,
            'Combined scope must preserve both expressly granted permissions.');
        $page = app_linking_gallery_page($readToken, $issuer, $galleryId - 1);
        check(is_array($page) && ($page['galleries'][0]['id'] ?? null) === $galleryId,
            'A read scope must retain its explicitly included listing permission.');
        check(app_link_model_revoke($userId, $readGrant, null)
            && app_linking_gallery_detail($readToken, $issuer, $galleryId) === null,
            'Revocation must immediately deny stored gallery details.');

        $mediaScope = \Gallery\Services\APP_LINKING_GALLERIES_SCOPE
            . \Gallery\Services\APP_LINKING_ADMIN_MEDIA_SUFFIX;
        [$mediaToken, $mediaGrant] = $issue($mediaScope);
        $mediaIdentity = app_linking_authenticate($mediaToken, $issuer);
        $oldIdentity = app_linking_authenticate($oldToken, $issuer);
        check(is_array($mediaIdentity) && is_array($oldIdentity),
            'The fixture requires current Admin bearers.');
        $mediaGallery = app_linking_admin_media_gallery(
            $mediaIdentity, $mediaToken, $issuer, $galleryId,
        );
        check(is_array($mediaGallery)
            && app_linking_admin_media_gallery($oldIdentity, $oldToken, $issuer, $galleryId) === null
            && app_linking_media_gallery($oldIdentity['scope'], $galleryId, false) === null,
            'Only explicit Admin media consent admits photos in the restricted gallery.');

        $insert = $pdo->prepare(
            'INSERT INTO images (gallery_id, relative_path, relative_path_hash, '
                . 'filename, visibility, nsfw_enabled, created_at, updated_at) '
                . "VALUES (?, ?, ?, ?, 'private', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        );
        $filename = 'fixture-private.jpg';
        $insert->execute([$galleryId, $filename, hash('sha256', $filename), $filename]);
        $imageId = (int) $pdo->lastInsertId();
        $GLOBALS['app_link_delete_calls'] = [];
        check(\Gallery\Services\app_linking_admin_media_delete_photos(
            $mediaIdentity, $mediaToken, $issuer, $galleryId, [$imageId],
        ) === [$imageId] && $GLOBALS['app_link_delete_calls'] === [[$galleryId, [$imageId]]],
            'A saved Admin Photos grant must delete through the shared owner.');
        $GLOBALS['app_link_delete_calls'] = [];
        $deleteScope = $mediaScope . \Gallery\Services\APP_LINKING_ADMIN_DELETE_SUFFIX;
        [$deleteToken] = $issue($deleteScope);
        $deleteIdentity = app_linking_authenticate($deleteToken, $issuer);
        check(is_array($deleteIdentity), 'Legacy delete fixture requires an authorized bearer.');
        try {
            \Gallery\Services\app_linking_admin_media_delete_photos(
                $oldIdentity, $oldToken, $issuer, $galleryId, [$imageId],
            );
            throw new \LogicException('Public-only authority admitted photo deletion.');
        } catch (\DomainException) {
            check($GLOBALS['app_link_delete_calls'] === [],
                'Public-only grant must refuse before mutation.');
        }
        foreach ([[$imageId, PHP_INT_MAX]] as $staleIds) {
            try {
                \Gallery\Services\app_linking_admin_media_delete_photos(
                    $deleteIdentity, $deleteToken, $issuer, $galleryId, $staleIds,
                );
                throw new \LogicException('Stale deletion batch was admitted.');
            } catch (\DomainException) {
                check($GLOBALS['app_link_delete_calls'] === [], 'A stale batch must make no mutation call.');
            }
        }
        $otherFolder = $folder . '_other';
        $otherGallery = $pdo->prepare(
            'INSERT INTO galleries (folder_path, folder_path_hash, slug, title, '
                . 'visibility, created_at, updated_at) VALUES (?, ?, ?, ?, '
                . "'private', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        );
        $otherGallery->execute([$otherFolder, hash('sha256', $otherFolder), $otherFolder, 'Other']);
        $otherGalleryId = (int) $pdo->lastInsertId();
        try {
            \Gallery\Services\app_linking_admin_media_delete_photos(
                $deleteIdentity, $deleteToken, $issuer, $otherGalleryId, [$imageId],
            );
            throw new \LogicException('Cross-gallery photo was admitted.');
        } catch (\DomainException) {
            check($GLOBALS['app_link_delete_calls'] === [], 'Cross-gallery deletion must not mutate.');
        }
        $pdo->prepare('DELETE FROM galleries WHERE id = ?')->execute([$otherGalleryId]);
        $GLOBALS['app_link_delete_calls'] = [];
        check(\Gallery\Services\app_linking_admin_media_delete_photos(
            $deleteIdentity, $deleteToken, $issuer, $galleryId, [$imageId],
        ) === [$imageId] && $GLOBALS['app_link_delete_calls'] === [[$galleryId, [$imageId]]],
            'A valid batch must delegate exactly once to the shared owner.');
        $expected = [
            'id' => $imageId, 'title' => $filename, 'visibility' => 'private',
            'nsfw' => true, 'size' => null, 'cache_eligible' => false,
            'preload_eligible' => false,
        ];
        check(app_linking_media_page($mediaGallery, 0, false, true)['photos'] === [$expected],
            'The Admin catalogue must include the restricted photo without cache admission.');
        check(gallery_admin_media_preload_gallery_allowed($galleryId),
            'A private non-NSFW gallery remains eligible independently of its photo flag.');
        $pdo->prepare('UPDATE images SET nsfw_enabled = 0 WHERE id = ?')->execute([$imageId]);
        $preloadPage = app_linking_media_page($mediaGallery, 0, false, true);
        check(($preloadPage['photos'][0]['preload_eligible'] ?? null) === true
            && ($preloadPage['photos'][0]['cache_eligible'] ?? null) === false,
            'A non-NSFW photo in a private gallery may preload without public admission.');
        $parentFolder = $folder . '_nsfw_parent';
        $parentInsert = $pdo->prepare(
            'INSERT INTO galleries (folder_path, folder_path_hash, slug, title, '
                . 'visibility, access_mode, access_listing, nsfw_enabled, '
                . 'created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, '
                . 'UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        );
        $parentInsert->execute([
            $parentFolder, hash('sha256', $parentFolder), $parentFolder,
            'Restricted parent', 'private', 'normal', 'listed',
        ]);
        $nsfwParentId = (int) $pdo->lastInsertId();
        $pdo->prepare('UPDATE galleries SET parent_id = ? WHERE id = ?')
            ->execute([$nsfwParentId, $galleryId]);
        $inheritedRestrictedPage = app_linking_media_page(
            $mediaGallery, 0, false, true,
        );
        check(!gallery_admin_media_preload_gallery_allowed($galleryId)
            && ($inheritedRestrictedPage['photos'][0]['preload_eligible'] ?? null) === false,
            'An NSFW ancestor must exclude the complete private gallery from preload.');
        $pdo->prepare('UPDATE galleries SET parent_id = NULL WHERE id = ?')
            ->execute([$galleryId]);
        $pdo->prepare('DELETE FROM galleries WHERE id = ?')
            ->execute([$nsfwParentId]);
        $nsfwParentId = 0;
        $pdo->prepare('UPDATE images SET nsfw_enabled = 1 WHERE id = ?')->execute([$imageId]);
        try {
            app_linking_admin_media_change_photo_visibility(
                $mediaIdentity, $mediaToken, $issuer, $galleryId, $imageId, 'private', 'public',
            );
            throw new \LogicException('Admin media without visibility scope edited visibility.');
        } catch (\RuntimeException $error) {
            check($error->getMessage() === 'Photo visibility authority is unavailable.',
                'The visibility scope must be checked before writing.');
        }
        check(app_linking_media_page($mediaGallery, 0, false, true)['photos'] === [$expected]
            && app_link_model_revoke($userId, $mediaGrant, null)
            && app_linking_authenticate($mediaToken, $issuer) === null
            && app_linking_admin_media_gallery($mediaIdentity, $mediaToken, $issuer, $galleryId) === null,
            'A refused write preserves visibility and a revoked bearer cannot reuse its identity.');
        $GLOBALS['app_link_delete_calls'] = [];
        try {
            \Gallery\Services\app_linking_admin_media_delete_photos(
                $mediaIdentity, $mediaToken, $issuer, $galleryId, [$imageId],
            );
            throw new \LogicException('Revoked Admin authority admitted photo deletion.');
        } catch (\DomainException) {
            check($GLOBALS['app_link_delete_calls'] === [],
                'A revoked bearer must fail the locked deletion recheck.');
        }

        // Keep three owned matches with the same expression in distinct fields.
        $phrase = 'Cyberpunk ' . $folder;
        $insert = $pdo->prepare(
            'INSERT INTO galleries (parent_id, folder_path, folder_path_hash, '
                . 'slug, title, description, visibility, created_at, updated_at) '
                . "VALUES (?, ?, ?, ?, ?, ?, 'private', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        );
        foreach ([
            'tag' => ['Tagged gallery', ''],
            'description' => ['Described gallery', $phrase],
            'name' => [$phrase, ''],
        ] as $match => [$title, $description]) {
            $path = $folder . '/' . $match;
            $slug = $folder . '-' . $match;
            $insert->execute([
                $galleryId, $path, hash('sha256', $path), $slug, $title, $description,
            ]);
            $searchGalleries[$slug] = (int) $pdo->lastInsertId();
        }
        $insert = $pdo->prepare(
            'INSERT INTO tags (name, slug, created_at, updated_at) '
                . 'VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        );
        $insert->execute([$phrase, $folder]);
        $tagId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO gallery_tags (gallery_id, tag_id) VALUES (?, ?)')
            ->execute([$searchGalleries[$folder . '-tag'], $tagId]);
        [$searchToken, $searchGrant] = $issue(\Gallery\Services\APP_LINKING_SEARCH_SCOPE);
        $search = app_linking_authenticate($searchToken, $issuer);
        $legacy = app_linking_authenticate($oldToken, $issuer);
        check(is_array($search) && is_array($legacy), 'Owned grants must authenticate.');
        check(app_linking_gallery_search_page($legacy, $oldToken, $issuer, $phrase, 0) === null
            && app_linking_upload_target($searchToken, $issuer, $galleryId) === null
            && app_linking_gallery_detail($searchToken, $issuer, $galleryId) === null,
            'Temporary search must stay separate from legacy, details and upload authority.');
        $choices = app_linking_gallery_search_page($search, $searchToken, $issuer, $phrase, 0);
        check(array_column($choices['galleries'] ?? [], 'id')
            === array_reverse(array_values($searchGalleries)),
            'Names must rank before descriptions and tags across the catalog.');
        $wildcard = app_linking_gallery_search_page(
            $search, $searchToken, $issuer, 'Cyber%punk AND ' . $folder, 0,
        );
        check(($wildcard['galleries'] ?? null) === [], 'Search terms are literal text.');
        $expected = [
            'id' => $searchGalleries[$folder . '-name'],
            'parent_id' => $galleryId,
            'title' => $phrase,
            'depth' => 1,
            'ancestors' => ['Stored private gallery'],
        ];
        foreach (['cyberpnuk', 'cyberpuk'] as $typo) {
            $query = $typo . ' AND "' . $folder . '"';
            $choices = app_linking_gallery_search_page($search, $searchToken, $issuer, $query, 0);
            check(($choices['galleries'] ?? null) === [$expected],
                'One title edit must find the exact child and expose only display ancestry.');
            $literal = app_linking_gallery_search_page(
                $search, $searchToken, $issuer, '"' . $typo . '" AND ' . $folder, 0,
            );
            check(($literal['galleries'] ?? null) === [], 'Quoted words must remain literal.');
        }
        check(app_link_model_revoke($userId, $searchGrant, null)
            && app_linking_gallery_search_page($search, $searchToken, $issuer, $phrase, 0) === null,
            'A revoked search grant must fail even with previously verified identity.');

        \Gallery\Services\schema_inspection_set_query_executor_for_tests(
            static fn(string $type, string $table, string $object): bool => false,
        );
        check(app_linking_scope_allowed($client, \Gallery\Services\APP_LINKING_UPLOAD_SCOPE)
            && !app_linking_scope_allowed($client, $readScope)
            && !app_linking_scope_allowed(
                $client, \Gallery\Services\APP_LINKING_READ_UPLOAD_OFFLINE_SCOPE,
            ), 'Unknown or missing wide storage must not affect the existing upload scope.');
        \Gallery\Services\schema_inspection_set_query_executor_for_tests(
            static function (string $type, string $table, string $object): bool {
                throw new \RuntimeException('Fixture metadata inspection failure.');
            },
        );
        check(app_linking_scope_allowed($client, \Gallery\Services\APP_LINKING_UPLOAD_SCOPE)
            && !app_linking_scope_allowed($client, $readScope),
            'Unknown wide storage must fail closed without disabling old scope.');
        \Gallery\Services\schema_inspection_set_query_executor_for_tests(null);
        echo "gallery detail scope tests passed\n";
    } finally {
        \Gallery\Services\schema_inspection_set_query_executor_for_tests(null);
        if ($pdo instanceof \PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($pdo instanceof \PDO && $tagId > 0) {
            $delete = $pdo->prepare('DELETE FROM tags WHERE id = ? AND slug = ?');
            $delete->execute([$tagId, $folder]);
        }
        if ($pdo instanceof \PDO && $searchGalleries !== []) {
            $delete = $pdo->prepare('DELETE FROM galleries WHERE id = ? AND slug = ?');
            foreach ($searchGalleries as $slug => $id) {
                $delete->execute([$id, $slug]);
            }
        }
        if ($pdo instanceof \PDO && $galleryId > 0) {
            $pdo->prepare('UPDATE galleries SET parent_id = NULL WHERE id = ?')
                ->execute([$galleryId]);
        }
        if ($pdo instanceof \PDO && $nsfwParentId > 0) {
            $delete = $pdo->prepare('DELETE FROM galleries WHERE id = ?');
            $delete->execute([$nsfwParentId]);
        }
        if ($pdo instanceof \PDO && $galleryId > 0) {
            $delete = $pdo->prepare('DELETE FROM galleries WHERE id = ? AND slug = ?');
            $delete->execute([$galleryId, $folder]);
        }
        if ($pdo instanceof \PDO && $userId > 0) {
            $delete = $pdo->prepare('DELETE FROM users WHERE id = ? AND username = ?');
            $delete->execute([$userId, $username]);
        }
    }
}
