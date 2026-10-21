<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_support_access_test.php
 * Module Type: Regression Test
 * Purpose: Verify #275 support-grant policy and security contracts without a DB.
 * Responsibilities:
 *   - Exercise real CSPRNG issue/redeem/validate/revoke service code
 *   - Check one-use, 24-hour expiry, role isolation and strict route allowlist
 *   - Protect migration, CSRF, fragment redaction, audit and locale contracts
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Models {
    /**
     * Simulate successful grant creation in a disposable in-memory fixture.
     *
     * @param int $ownerId Permanent owner identifier.
     * @param string $tokenHash One-way SHA-256 invitation verifier.
     * @param int $ttlSeconds Grant lifetime in seconds.
     * @return array{id:int,owner_user_id:int,created_at:string,expires_at:string} Grant without the bearer token.
     */
    function admin_support_model_create(
        int $ownerId,
        string $tokenHash,
        int $ttlSeconds,
    ): array {
        if ($ownerId !== 7) {
            throw new \RuntimeException('Fixture refuses non-Admin issuance.');
        }
        $id = ++$GLOBALS['support_fixture_last_id'];
        $now = (int) $GLOBALS['support_fixture_now'];
        $row = [
            'id' => $id,
            'owner_user_id' => $ownerId,
            'token_hash' => $tokenHash,
            'created_timestamp' => $now,
            'expires_timestamp' => $now + $ttlSeconds,
            'session_hash' => null,
            'redeemed' => false,
            'revoked' => false,
        ];
        $GLOBALS['support_fixture_grants'][$id] = $row;
        return [
            'id' => $id,
            'owner_user_id' => $ownerId,
            'created_at' => gmdate('Y-m-d H:i:s', $now),
            'expires_at' => gmdate('Y-m-d H:i:s', $now + $ttlSeconds),
        ];
    }

    /**
     * Simulate the model's one-row atomic one-use token claim.
     *
     * @param string $tokenHash SHA-256 invitation verifier.
     * @param string $sessionHash SHA-256 independent session verifier.
     * @return array{id:int,owner_user_id:int,expires_at:string}|null Claim receipt or refusal.
     */
    function admin_support_model_claim(
        string $tokenHash,
        string $sessionHash,
    ): ?array {
        foreach ($GLOBALS['support_fixture_grants'] as &$row) {
            if (
                $row['token_hash'] !== $tokenHash
                || $row['redeemed']
                || $row['revoked']
                || $row['expires_timestamp']
                    <= (int) $GLOBALS['support_fixture_now']
            ) {
                continue;
            }
            $row['session_hash'] = $sessionHash;
            $row['redeemed'] = true;
            return [
                'id' => $row['id'],
                'owner_user_id' => $row['owner_user_id'],
                'expires_at' => gmdate(
                    'Y-m-d H:i:s',
                    $row['expires_timestamp'],
                ),
            ];
        }
        return null;
    }

    /**
     * Simulate the database-backed validity predicate using fixture UTC time.
     *
     * @param int $id Granted access identifier.
     * @param int $ownerId Expected permanent Admin identifier.
     * @param string $sessionHash Independent session verifier hash.
     * @return array{id:int,owner_user_id:int,expires_at:string}|null Active grant or null.
     */
    function admin_support_model_active_session(
        int $id,
        int $ownerId,
        string $sessionHash,
    ): ?array {
        $row = $GLOBALS['support_fixture_grants'][$id] ?? null;
        if (
            !is_array($row)
            || $row['owner_user_id'] !== $ownerId
            || !$row['redeemed']
            || $row['revoked']
            || $row['session_hash'] !== $sessionHash
            || $row['expires_timestamp']
                <= (int) $GLOBALS['support_fixture_now']
        ) {
            return null;
        }
        return [
            'id' => $id,
            'owner_user_id' => $ownerId,
            'expires_at' => gmdate('Y-m-d H:i:s', $row['expires_timestamp']),
        ];
    }

    /**
     * Return bounded metadata for the fixture's permanent owner.
     *
     * @param int $ownerId Requested owner.
     * @param int $limit Maximum records.
     * @return list<array<string,mixed>> Owner's recent records without secrets.
     */
    function admin_support_model_list(int $ownerId, int $limit): array
    {
        $result = [];
        foreach ($GLOBALS['support_fixture_grants'] as $row) {
            if ($row['owner_user_id'] !== $ownerId) {
                continue;
            }
            $result[] = [
                'id' => $row['id'],
                'owner_user_id' => $ownerId,
            ];
        }
        return array_slice(array_reverse($result), 0, $limit);
    }

    /**
     * Simulate a permanent owner's revocation of one support grant.
     *
     * @param int $ownerId Only the issuing owner may revoke.
     * @param int $grantId Grant identifier.
     * @return bool Whether the grant transitioned to revoked.
     */
    function admin_support_model_revoke(int $ownerId, int $grantId): bool
    {
        $row = &$GLOBALS['support_fixture_grants'][$grantId];
        if (
            !is_array($row)
            || $row['owner_user_id'] !== $ownerId
            || $row['revoked']
        ) {
            return false;
        }
        $row['revoked'] = true;
        return true;
    }

    /**
     * Record one simulated permitted support-session use.
     *
     * @param int $grantId Active grant identifier.
     * @return void Increments the fixture's touch count.
     */
    function admin_support_model_touch(int $grantId): void
    {
        $GLOBALS['support_fixture_touches'][] = $grantId;
    }

    /**
     * Record one simulated expiry observation without live database work.
     *
     * @param int $grantId Expired grant identifier.
     * @return void Saves the grant identifier for the expiry assertion.
     */
    function admin_support_model_mark_expired(int $grantId): void
    {
        $GLOBALS['support_fixture_expiries'][] = $grantId;
    }

    /**
     * Simulate bounded retention cleanup, recording policy parameters.
     *
     * @param int $retentionDays Audit history lifetime in days.
     * @param int $batchSize Maximum purged grants.
     * @return int No fixture grants are pruned before the deterministic checks.
     */
    function admin_support_model_prune(
        int $retentionDays,
        int $batchSize,
    ): int {
        $GLOBALS['support_fixture_retention'] = [
            'days' => $retentionDays,
            'batch' => $batchSize,
        ];
        return 0;
    }
}

namespace {
    use function Gallery\Services\admin_support_grants;
    use function Gallery\Services\admin_support_issue;
    use function Gallery\Services\admin_support_redeem;
    use function Gallery\Services\admin_support_revoke;
    use function Gallery\Services\admin_support_route_allowed;
    use function Gallery\Services\admin_support_validate_session;
    use const Gallery\Services\ADMIN_SUPPORT_LIFETIME_SECONDS;

    require_once __DIR__ . '/../app/services/auth_persistence.php';

    /**
     * Assert a required support-access contract.
     *
     * @param bool $condition Expected successful check.
     * @param string $label Description of the security invariant.
     * @return void Throws a labeled failure when a contract is broken.
     */
    function admin_support_test_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
    }

    $root = dirname(__DIR__);
    $GLOBALS['support_fixture_last_id'] = 0;
    $GLOBALS['support_fixture_now'] = 1792500000;
    $GLOBALS['support_fixture_grants'] = [];
    $GLOBALS['support_fixture_touches'] = [];
    $GLOBALS['support_fixture_expiries'] = [];

    admin_support_test_assert(
        ADMIN_SUPPORT_LIFETIME_SECONDS === 86400,
        'Support grant expiry must be 86,400 seconds from issuance.',
    );
    $issued = admin_support_issue(7);
    $token = $issued['token'];
    $id = $issued['grant']['id'];
    $stored = $GLOBALS['support_fixture_grants'][$id];
    admin_support_test_assert(
        strlen($token) === 64
            && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1,
        'Invitation must contain exactly 256 bits of random hex authority.',
    );
    admin_support_test_assert(
        $stored['token_hash'] === hash('sha256', $token)
            && !str_contains(json_encode($stored), $token),
        'The persisted grant must not retain the bearer invitation.',
    );
    admin_support_test_assert(
        $stored['expires_timestamp'] - $stored['created_timestamp'] === 86400,
        'Lifetime is fixed to issuance, not first use.',
    );
    admin_support_test_assert(
        admin_support_redeem('not-a-token') === null,
        'Malformed tokens must be refused before database lookup.',
    );

    $claimed = admin_support_redeem($token);
    admin_support_test_assert(
        $claimed !== null && $claimed['grant']['id'] === $id,
        'A valid unused invitation must be redeemable.',
    );
    admin_support_test_assert(
        admin_support_redeem($token) === null,
        'Replaying a consumed invitation must fail.',
    );
    $nonce = $claimed['nonce'];
    admin_support_test_assert(
        strlen($nonce) === 64
            && $nonce !== $token
            && $stored['token_hash'] !== hash('sha256', $nonce),
        'A different 256-bit verifier must back each delegated session.',
    );
    admin_support_test_assert(
        admin_support_validate_session($id, 7, $nonce) !== null,
        'Active grant must authenticate its independent verifier.',
    );
    admin_support_test_assert(
        admin_support_validate_session($id, 8, $nonce) === null
            && admin_support_validate_session($id, 7, $token) === null,
        'Owner mismatch and invitation-as-session reuse must fail.',
    );
    admin_support_test_assert(
        admin_support_revoke(8, $id) === false,
        'A different owner must not revoke the grant.',
    );
    admin_support_test_assert(
        admin_support_revoke(7, $id),
        'The issuing permanent Admin must be able to revoke.',
    );
    admin_support_test_assert(
        admin_support_validate_session($id, 7, $nonce) === null,
        'Revocation must immediately invalidate its delegated session.',
    );

    $expiring = admin_support_issue(7);
    $GLOBALS['support_fixture_now'] += 86400;
    admin_support_test_assert(
        admin_support_redeem($expiring['token']) === null,
        'At exactly 24 hours an unclaimed link is invalid.',
    );
    $GLOBALS['support_fixture_now'] -= 86400;
    $shortLived = admin_support_issue(7);
    $accepted = admin_support_redeem($shortLived['token']);
    admin_support_test_assert(
        $accepted !== null,
        'Second unexpired invitation must redeem.',
    );
    $GLOBALS['support_fixture_now'] += 86400;
    admin_support_test_assert(
        admin_support_validate_session(
            $accepted['grant']['id'],
            7,
            $accepted['nonce'],
        ) === null,
        'At exactly 24 hours an already redeemed session must fail.',
    );

    admin_support_grants(7);
    admin_support_test_assert(
        $GLOBALS['support_fixture_retention'] === [
            'days' => 90,
            'batch' => 50,
        ],
        'Owner cleanup must bound retention and row deletion volume.',
    );

    foreach ([
        ['admin', 'GET', true],
        ['admin_edit_gallery', 'GET', true],
        ['admin_edit_gallery', 'POST', false],
        ['admin_logs', 'GET', true],
        ['admin_test_run_start', 'POST', true],
        ['admin_logout', 'GET', true],
        ['admin_support', 'GET', false],
        ['cooperative_peer_api', 'GET', false],
        ['picture_manager_move', 'POST', false],
        ['site_maintenance_cron', 'GET', false],
        ['viewer_account_password', 'POST', false],
        ['gallery', 'GET', true],
        ['gallery', 'POST', false],
        ['admin_support', 'POST', false],
        ['admin_support_redeem', 'POST', false],
        ['admin_account', 'GET', false],
        ['admin_settings', 'GET', true],
        ['admin_settings', 'POST', false],
        ['admin_api_manager', 'GET', false],
        ['admin_upload_automation_token', 'POST', false],
        ['admin_update', 'GET', false],
        ['admin_run_migrations', 'POST', false],
        ['admin_google_start', 'GET', false],
        ['admin_forgot_password', 'GET', false],
        ['admin_viewer_invitations', 'POST', false],
        ['admin_undocumented_future_route', 'GET', false],
        ['setup', 'GET', false],
        ['gallery_migration_receive_asset', 'POST', false],
        ['upload_automation_upload', 'POST', false],
    ] as [$page, $method, $expected]) {
        admin_support_test_assert(
            admin_support_route_allowed($page, $method) === $expected,
            'Delegated route policy mismatch: ' . $page . ' ' . $method,
        );
    }

    admin_support_test_assert(
        admin_support_route_allowed(
            'admin_edit_gallery',
            'POST',
            ['action' => 'cover'],
        ),
        'Targeted gallery cover edits remain allowed.',
    );
    admin_support_test_assert(
        !admin_support_route_allowed(
            'admin_edit_gallery',
            'POST',
            ['action' => 'cover', 'access_action' => 'generate_link'],
        ),
        'Gallery share-link issuance must never use temporary Admin authority.',
    );
    admin_support_test_assert(
        !admin_support_route_allowed(
            'admin_edit_gallery',
            'POST',
            ['access_action' => 'generate_link'],
        ),
        'A full editor form cannot create durable share credentials.',
    );

    $controller = (string) file_get_contents(
        $root . '/app/controllers/admin_support.php',
    );
    $view = (string) file_get_contents(
        $root . '/app/views/admin_support.php',
    );
    $model = (string) file_get_contents(
        $root . '/app/models/auth_persistence.php',
    );
    $migration = (string) file_get_contents(
        $root . '/database/migrations/202610210001_admin_support_access.php',
    );
    $security = (string) file_get_contents($root . '/app/security.php');
    $dispatch = (string) file_get_contents(
        $root . '/app/bootstrap/dispatch.php',
    );
    $logs = (string) file_get_contents($root . '/app/services/logs.php');

    admin_support_test_assert(
        substr_count($controller, 'verify_csrf()') === 2
            && str_contains($controller, 'session_regenerate_id(true)')
            && str_contains($controller, 'admin_auth_clear_remember_cookie()')
            && str_contains($controller, '$_SESSION = []'),
        'Issuance/redemption require CSRF and delegated session rotation.',
    );
    admin_support_test_assert(
        str_contains($controller, "url_for('admin_support_redeem')")
            && str_contains($controller, "'#support='")
            && !str_contains($controller, "'?token='"),
        'Invitations must be emitted in a client fragment, never query data.',
    );
    admin_support_test_assert(
        str_contains($view, 'history.replaceState(')
            && str_contains($view, 'name="support_access_token"')
            && str_contains($controller, "['support_access_token']")
            && !str_contains($view, '$_GET'),
        'Acceptance must remove fragments and send redacted POST credentials.',
    );
    admin_support_test_assert(
        str_contains($security, "array_key_exists('admin_support',")
            && str_contains($security, 'admin_support_validate_session(')
            && str_contains($dispatch, 'admin_support_assert_session()')
            && str_contains($dispatch, 'admin_support_route_allowed('),
        'Authentication and route dispatch must enforce live grants.',
    );
    admin_support_test_assert(
        str_contains($logs, "'support_actor'")
            && str_contains($logs, "'grant_id'")
            && str_contains($logs, "'delegate'"),
        'Admin events must distinguish delegated actions from the owner.',
    );
    foreach ([
        'token_hash',
        'session_hash',
        'expires_at',
        'redeemed_at',
        'revoked_at',
        'UTC_TIMESTAMP(6)',
        'ON DELETE CASCADE',
    ] as $columnOrConstraint) {
        admin_support_test_assert(
            str_contains($model . $migration, $columnOrConstraint),
            'Grant schema/model missing: ' . $columnOrConstraint,
        );
    }
    admin_support_test_assert(
        str_contains($model, 'g.redeemed_at IS NULL')
            && str_contains($model, 'g.revoked_at IS NULL')
            && str_contains($model, 'g.expires_at > UTC_TIMESTAMP(6)')
            && str_contains($model, 'u.role = ?')
            && str_contains($model, '$pdo->beginTransaction()'),
        'Atomic claim must restrict used/revoked/expired grants and owner role.',
    );
    foreach (['admin_support', 'admin_support_redeem'] as $routeName) {
        admin_support_test_assert(
            str_contains($dispatch, "'" . $routeName . "' =>"),
            'The dispatcher must register ' . $routeName . '.',
        );
    }

    $requiredKeys = [
        'admin.support.title',
        'admin.support.issue',
        'admin.support.revoke',
        'admin.support.access_denied',
        'admin.support.banner',
        'admin.support.invalid_invitation',
    ];
    foreach (['en', 'cs', 'de', 'sv'] as $lang) {
        $langJson = json_decode(
            (string) file_get_contents($root . '/app/lang/' . $lang . '.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        foreach ($requiredKeys as $key) {
            admin_support_test_assert(
                isset($langJson[$key]) && $langJson[$key] !== '',
                'Missing support UI translation: ' . $lang . '/' . $key,
            );
        }
    }
    fwrite(STDOUT, "Temporary Admin support access contracts passed.\n");
}
