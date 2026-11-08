<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_gallery_tree_test.php
 * Module Type: Regression Test
 * Purpose: Verify optional complete catalogue envelopes and authority fences.
 * Responsibilities:
 *   - Exercise the real service with controlled external storage boundaries.
 *   - Preserve scope, issuer, schema, size and final-authorization refusal.
 *   - Bind unchanged replies to the current account and complete catalogue.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Supply the selected authentication schema observation without a database.
     *
     * @return array{state:string} Current fixture schema observation.
     */
    function app_linking_authentication_schema_status(): array
    {
        return ['state' => $GLOBALS['tree_fixture_schema']];
    }

    /**
     * Supply the selected catalogue schema observation without a database.
     *
     * @return array{state:string} Current fixture schema observation.
     */
    function app_linking_gallery_listing_schema_status(): array
    {
        return ['state' => $GLOBALS['tree_fixture_schema']];
    }
}

namespace Gallery\Models {
    /**
     * Model the persisted grant lookup at the service's database boundary.
     *
     * @param string $tokenHash Digest of the presented fixture bearer.
     * @param string $issuer Exact requested installation issuer.
     * @return array{grant_id:int,user_id:int,username:string,scope:string}|null
     *     Controlled live identity, or null after rejection or revocation.
     */
    function app_link_model_lookup_token(
        string $tokenHash,
        string $issuer,
    ): ?array {
        if ($tokenHash !== $GLOBALS['tree_fixture_token_hash']
            || $issuer !== $GLOBALS['tree_fixture_issuer']) {
            return null;
        }
        return $GLOBALS['tree_fixture_identity'];
    }

    /**
     * Supply one statement's authorized rows at the external database seam.
     *
     * @param int $grantId Exact grant used for the statement.
     * @param int $userId Exact approving account used for the statement.
     * @param string $tokenHash Digest of the presented bearer.
     * @param string $issuer Exact verified installation.
     * @param string $scope Exact current consented scope.
     * @param int $afterId Exclusive catalogue cursor.
     * @param int $limit Positive row bound including the lookahead.
     * @param array{terms:list<string>,translated:bool,media_typed:bool}|null $search
     *     Optional picker projection, which the tree must never request.
     * @return list<array{id:int,parent_id:?int,title:string}>|null Controlled
     *     authorized rows, or null when the statement no longer has authority.
     */
    function gallery_model_linked_client_page(
        int $grantId,
        int $userId,
        string $tokenHash,
        string $issuer,
        string $scope,
        int $afterId,
        int $limit,
        ?array $search = null,
    ): ?array {
        $identity = $GLOBALS['tree_fixture_identity'];
        if ($identity === null || $grantId !== $identity['grant_id']
            || $userId !== $identity['user_id']
            || $tokenHash !== $GLOBALS['tree_fixture_token_hash']
            || $issuer !== $GLOBALS['tree_fixture_issuer']
            || $scope !== $identity['scope']) {
            return null;
        }
        if ($afterId !== 0 || $search !== null
            || $limit !== \Gallery\Services\APP_LINKING_GALLERY_TREE_MAX_ROWS + 1) {
            throw new \RuntimeException('Tree must use one bounded catalogue read.');
        }
        ++$GLOBALS['tree_fixture_reads'];
        $rows = array_slice($GLOBALS['tree_fixture_rows'], 0, $limit);
        if ($GLOBALS['tree_fixture_revoke_after_read']) {
            $GLOBALS['tree_fixture_identity'] = null;
        } elseif ($GLOBALS['tree_fixture_change_scope_after_read']) {
            $GLOBALS['tree_fixture_identity']['scope'] = 'link:identity';
        }
        return $rows;
    }
}

namespace {
    use function Gallery\Services\app_linking_access_token_generate;
    use function Gallery\Services\app_linking_access_token_hash;
    use function Gallery\Services\app_linking_gallery_tree;

    require_once __DIR__ . '/../app/services/schema_inspection.php';
    require_once __DIR__ . '/../app/services/security_tokens.php';
    require_once __DIR__ . '/../app/services/app_linking_policy.php';
    require_once __DIR__ . '/../app/services/app_linking.php';

    /**
     * Keep the byte-limit fixture within the real gallery title capacity.
     * Type: int. Units: Unicode characters. Scope: oversized-tree fixture.
     * Consumers: str_repeat below.
     * Rationale: 255 matches stored gallery titles; multi-byte text crosses the
     * response byte bound without exceeding the separate row-count bound.
     */
    const TREE_FIXTURE_TITLE_CHARACTERS = 255;

    /**
     * Stop on a failed complete-tree security or compatibility expectation.
     *
     * @param bool $condition Whether the expected behavior holds.
     * @param string $message Safe fixture failure explanation.
     * @return void Continues only when the expectation holds.
     */
    function tree_fixture_check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Require bounds to refuse rather than returning a partial complete tree.
     *
     * @param string $token Current fixture bearer.
     * @param string $issuer Exact fixture installation.
     * @return void Continues only after the service reports an overflow.
     */
    function tree_fixture_expect_overflow(string $token, string $issuer): void
    {
        try {
            app_linking_gallery_tree($token, $issuer);
        } catch (\OverflowException) {
            return;
        }
        throw new \RuntimeException('An oversized tree must require paging.');
    }

    $issuer = 'https://gallery.example.test/Galerie';
    $token = app_linking_access_token_generate();
    $identity = [
        'grant_id' => 4, 'user_id' => 7, 'username' => 'fixture',
        'scope' => 'link:identity galleries:list',
    ];
    $rows = [
        ['id' => 1, 'parent_id' => null, 'title' => 'Private fixture'],
        ['id' => 2, 'parent_id' => 1, 'title' => 'Nested fixture'],
    ];
    $GLOBALS['tree_fixture_issuer'] = $issuer;
    $GLOBALS['tree_fixture_token_hash'] = app_linking_access_token_hash($token);
    $GLOBALS['tree_fixture_identity'] = $identity;
    $GLOBALS['tree_fixture_schema'] = 'available';
    $GLOBALS['tree_fixture_rows'] = $rows;
    $GLOBALS['tree_fixture_reads'] = 0;
    $GLOBALS['tree_fixture_revoke_after_read'] = false;
    $GLOBALS['tree_fixture_change_scope_after_read'] = false;

    $full = app_linking_gallery_tree($token, $issuer);
    tree_fixture_check(is_array($full)
        && array_keys($full) === ['version', 'revision', 'unchanged', 'galleries']
        && $full['version'] === 1 && $full['unchanged'] === false
        && $full['galleries'] === $rows
        && preg_match('/\A[a-f0-9]{64}\z/D', $full['revision']) === 1,
        'A complete tree must contain only bounded ordered identity fields.');
    $revision = $full['revision'];
    tree_fixture_check(app_linking_gallery_tree($token, $issuer, $revision) === [
        'version' => 1, 'revision' => $revision, 'unchanged' => true,
    ], 'An unchanged reply must omit the tree only after current authorization.');
    tree_fixture_check($GLOBALS['tree_fixture_reads'] === 2,
        'Revision comparison must not skip the authorized catalogue read.');

    foreach ([
        [
            ['id' => 1, 'parent_id' => null, 'title' => 'Renamed fixture'],
            $rows[1],
        ],
        [
            $rows[0],
            ['id' => 2, 'parent_id' => null, 'title' => 'Nested fixture'],
        ],
        [$rows[0]],
        [...$rows, ['id' => 3, 'parent_id' => 1, 'title' => 'Added fixture']],
    ] as $changed) {
        $GLOBALS['tree_fixture_rows'] = $changed;
        $result = app_linking_gallery_tree($token, $issuer, $revision);
        tree_fixture_check(is_array($result) && $result['unchanged'] === false
            && $result['galleries'] === $changed
            && $result['revision'] !== $revision,
            'Rename, move, deletion and creation must invalidate the revision.');
    }
    $GLOBALS['tree_fixture_rows'] = $rows;
    $GLOBALS['tree_fixture_identity']['user_id'] = 8;
    $otherAccount = app_linking_gallery_tree($token, $issuer, $revision);
    tree_fixture_check(is_array($otherAccount)
        && $otherAccount['unchanged'] === false,
        'The same row content for another account must not match a revision.');
    $GLOBALS['tree_fixture_identity'] = $identity;
    tree_fixture_check(app_linking_gallery_tree(
        $token, 'https://other.example.test', $revision,
    ) === null, 'A foreign issuer must not receive a saved tree.');
    $GLOBALS['tree_fixture_identity']['scope'] = 'link:identity';
    tree_fixture_check(app_linking_gallery_tree($token, $issuer, $revision) === null,
        'Identity-only scope must not gain catalogue access.');
    $GLOBALS['tree_fixture_identity'] = $identity;

    $GLOBALS['tree_fixture_revoke_after_read'] = true;
    tree_fixture_check(app_linking_gallery_tree($token, $issuer, $revision) === null,
        'Revocation after the statement must deny even an unchanged result.');
    $GLOBALS['tree_fixture_revoke_after_read'] = false;
    $GLOBALS['tree_fixture_identity'] = $identity;
    $GLOBALS['tree_fixture_change_scope_after_read'] = true;
    tree_fixture_check(app_linking_gallery_tree($token, $issuer, $revision) === null,
        'A changed scope after the statement must deny the earlier snapshot.');
    $GLOBALS['tree_fixture_change_scope_after_read'] = false;
    $GLOBALS['tree_fixture_identity'] = $identity;
    tree_fixture_check(app_linking_gallery_tree($token, $issuer, 'invalid') === null,
        'An invalid known revision must not become a conditional read.');

    $GLOBALS['tree_fixture_rows'] = array_fill(
        0, \Gallery\Services\APP_LINKING_GALLERY_TREE_MAX_ROWS + 1, $rows[0],
    );
    tree_fixture_expect_overflow($token, $issuer);
    $largeTitle = str_repeat('🛩', TREE_FIXTURE_TITLE_CHARACTERS);
    $byteRows = intdiv(
        \Gallery\Services\APP_LINKING_GALLERY_TREE_MAX_BYTES,
        strlen($largeTitle),
    ) + 1;
    tree_fixture_check($byteRows <= \Gallery\Services\APP_LINKING_GALLERY_TREE_MAX_ROWS,
        'The byte-bound fixture must remain below the independent row bound.');
    $GLOBALS['tree_fixture_rows'] = [];
    for ($id = 1; $id <= $byteRows; ++$id) {
        $GLOBALS['tree_fixture_rows'][] = [
            'id' => $id, 'parent_id' => null, 'title' => $largeTitle,
        ];
    }
    tree_fixture_expect_overflow($token, $issuer);

    $GLOBALS['tree_fixture_rows'] = [];
    $empty = app_linking_gallery_tree($token, $issuer);
    tree_fixture_check(is_array($empty) && $empty['galleries'] === []
        && $empty['unchanged'] === false,
        'An authorized empty catalogue must remain a complete result.');
    foreach (['missing', 'unknown'] as $schema) {
        $GLOBALS['tree_fixture_schema'] = $schema;
        $refused = false;
        try {
            app_linking_gallery_tree($token, $issuer, $revision);
        } catch (\RuntimeException) {
            $refused = true;
        }
        tree_fixture_check($refused, 'Unverified schema must refuse tree access.');
    }
    echo "OK optional native gallery tree authority and bounds\n";
}
