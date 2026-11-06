<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/app_linking.php
 * Module Type: Model
 * Purpose: Persist revocable scoped grants and single-use linking codes.
 * Responsibilities:
 *   - Store only one-way credential hashes and keep codes separate from grants.
 *   - Serialize issue, exchange and revocation with consistent row locks.
 *   - Recheck the administrator's current role at issuance and use.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/**
 * Bound retained linking grants for one administrator.
 * Type: int. Units: grant rows. Scope: one administrator account.
 * Consumers: app_link_model_exchange.
 * Rationale: 64 bounds per-owner storage without deleting active authority.
 * This is not a global cap on all administrator accounts.
 */
const APP_LINKING_GRANT_OWNER_CAP = 64;

/**
 * Preserve terminated linking grant metadata before reclaiming a full slot.
 * Type: int. Units: seconds. Scope: one terminated grant.
 * Consumers: app_link_model_reclaim_one.
 * Rationale: 86400 seconds retains a day of expired or revoked grant evidence.
 */
const APP_LINKING_GRANT_RETENTION_SECONDS = 86400;

/**
 * Bound pending and consumed authorization codes for one administrator.
 * Type: int. Units: rows. Scope: one administrator account.
 * Consumers: app_link_model_issue. Rationale: 64 bounds short-lived code storage.
 */
const APP_LINKING_CODE_OWNER_CAP = 64;

/**
 * Bound expired code cleanup when an owner's code storage is full.
 * Type: int. Units: rows. Scope: one issuance transaction.
 * Consumers: app_link_model_issue. Rationale: 32 bounds a single deletion.
 */
const APP_LINKING_CODE_CLEANUP_BATCH = 32;

/**
 * Reject a value that cannot be a lowercase SHA-256 credential hash.
 *
 * @param string $hash Lowercase hexadecimal one-way SHA-256 digest.
 * @return void Accepts a well-formed digest or throws for malformed input.
 * @throws InvalidArgumentException When the digest has an invalid shape.
 */
function app_link_model_assert_hash(string $hash): void
{
    if (preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
        throw new InvalidArgumentException('Invalid credential hash.');
    }
}

/**
 * Create a bounded, standalone one-time code after explicit Admin consent.
 *
 * @param int $userId Consenting administrator's identifier.
 * @param string $clientId Validated registered public-client identifier.
 * @param string $issuer Trusted exact installation issuer.
 * @param string $scope Consented identity scope.
 * @param callable():string $makeCodeHash Generates and returns a SHA-256 digest.
 * @param string $redirectUri Exact registered callback URI.
 * @param string $challenge Canonical S256 challenge.
 * @param int $codeTtl Positive lifetime in seconds.
 * @return int|null Created code ID, or null when admission is refused.
 * @throws Throwable When storage, randomness or transaction ownership fails.
 */
function app_link_model_issue(
    int $userId,
    string $clientId,
    string $issuer,
    string $scope,
    callable $makeCodeHash,
    string $redirectUri,
    string $challenge,
    int $codeTtl,
): ?int {
    if ($codeTtl <= 0) {
        throw new InvalidArgumentException('Code lifetime must be positive.');
    }
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Code issuance requires its own transaction.');
    }
    $pdo->beginTransaction();
    try {
        $user = $pdo->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
        $user->execute([$userId]);
        if ($user->fetchColumn() !== 'admin') {
            $pdo->rollBack();
            return null;
        }
        $scan = $pdo->prepare(
            'SELECT id FROM app_link_codes FORCE INDEX (app_link_codes_owner) '
                . 'WHERE user_id = ? LIMIT ?',
        );
        $scan->bindValue(1, $userId, PDO::PARAM_INT);
        $scan->bindValue(2, APP_LINKING_CODE_OWNER_CAP + 1, PDO::PARAM_INT);
        $scan->execute();
        $count = count($scan->fetchAll(PDO::FETCH_COLUMN));
        if ($count > APP_LINKING_CODE_OWNER_CAP) {
            $pdo->rollBack();
            return null;
        }
        if ($count === APP_LINKING_CODE_OWNER_CAP) {
            $cleanup = $pdo->prepare(
                'SELECT id FROM app_link_codes FORCE INDEX (app_link_codes_owner) '
                    . 'WHERE user_id = ? '
                    . 'AND expires_at <= UTC_TIMESTAMP(6) '
                    . 'ORDER BY expires_at, id LIMIT ?',
            );
            $cleanup->bindValue(1, $userId, PDO::PARAM_INT);
            $cleanup->bindValue(2, APP_LINKING_CODE_CLEANUP_BATCH, PDO::PARAM_INT);
            $cleanup->execute();
            $expired = $cleanup->fetchAll(PDO::FETCH_COLUMN);
            if ($expired === []) {
                $pdo->rollBack();
                return null;
            }
            $delete = $pdo->prepare(
                'DELETE FROM app_link_codes WHERE id = ? AND user_id = ? '
                    . 'AND expires_at <= UTC_TIMESTAMP(6)',
            );
            foreach ($expired as $id) {
                $delete->execute([(int) $id, $userId]);
                if ($delete->rowCount() !== 1) {
                    throw new RuntimeException('Expired code changed during cleanup.');
                }
            }
        }
        $codeHash = $makeCodeHash();
        app_link_model_assert_hash($codeHash);
        $insert = $pdo->prepare(
            'INSERT INTO app_link_codes (user_id, code_hash, client_id, issuer, '
                . 'scope, redirect_uri, pkce_s256, created_at, expires_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(6), '
                . 'DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))',
        );
        $insert->execute([
            $userId, $codeHash, $clientId, $issuer, $scope, $redirectUri,
            $challenge, $codeTtl,
        ]);
        $id = (int) $pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Reclaim at most one old terminated grant while its owner row is locked.
 *
 * @param PDO $pdo Connection holding the owner's transaction lock.
 * @param int $userId Locked owner identifier.
 * @param list<int> $grantIds Bounded candidate grant IDs.
 * @param 'absent'|'ready'|'unknown' $refreshState Verified refresh-family
 *     inspection; unknown storage must never authorize reclamation.
 * @return bool True after exactly one safe reclaim; false when none qualifies.
 * @throws Throwable When transaction or storage invariants fail.
 */
function app_link_model_reclaim_one(
    PDO $pdo,
    int $userId,
    array $grantIds,
    string $refreshState = 'unknown',
): bool {
    if (!$pdo->inTransaction() || count($grantIds) > APP_LINKING_GRANT_OWNER_CAP) {
        throw new RuntimeException('Grant reclaim requires a bounded transaction.');
    }
    if (!in_array($refreshState, ['absent', 'ready'], true)) {
        // Unknown or partially migrated storage cannot prove safe deletion.
        return false;
    }
    $select = $pdo->prepare(
        'SELECT revoked_at, access_token_hash, access_issued_at, access_expires_at, '
            . '(revoked_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND)) AS revoked_old, '
            . '(access_expires_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND)) AS expired_old '
            . 'FROM app_link_grants WHERE id = ? AND user_id = ? FOR UPDATE',
    );
    foreach ($grantIds as $id) {
        $select->execute([
            APP_LINKING_GRANT_RETENTION_SECONDS, APP_LINKING_GRANT_RETENTION_SECONDS,
            $id, $userId,
        ]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            continue;
        }
        $revokedOld = (int) $row['revoked_old'] === 1;
        $expiredOld = $row['revoked_at'] === null
            && is_string($row['access_token_hash'])
            && preg_match('/^[a-f0-9]{64}$/D', $row['access_token_hash']) === 1
            && $row['access_issued_at'] !== null && (int) $row['expired_old'] === 1;
        if (!$revokedOld && !$expiredOld) {
            continue;
        }
        if ($refreshState === 'ready' && !$revokedOld) {
            // User and grant are already locked; serialize with refresh
            // rotation before deleting an unrevoked grant whose access expired.
            // A grant revoked beyond retention is already unusable; its family
            // can be removed by the existing cascade under the grant lock.
            $family = $pdo->prepare(
                'SELECT grant_id FROM app_link_refresh_families '
                    . 'WHERE grant_id = ? AND revoked_at IS NULL '
                    . 'AND expires_at > UTC_TIMESTAMP(6) '
                    . 'AND idle_expires_at > UTC_TIMESTAMP(6) FOR UPDATE',
            );
            $family->execute([$id]);
            if ($family->fetchColumn() !== false) {
                continue;
            }
        }
        $delete = $pdo->prepare('DELETE FROM app_link_grants WHERE id = ? AND user_id = ?');
        $delete->execute([$id, $userId]);
        if ($delete->rowCount() !== 1) {
            throw new RuntimeException('Grant changed during reclaim.');
        }
        return true;
    }
    return false;
}

/**
 * Read the scope of a live code before optional refresh-schema inspection.
 *
 * This read is not authorization: exchange must lock and recheck the code,
 * issuer, client, scope, account and PKCE proof before issuing any credential.
 *
 * @param string $codeHash Lowercase SHA-256 digest of the authorization code.
 * @param string $clientId Registered public-client identifier from the request.
 * @param string $issuer Independently trusted exact HTTPS issuer and mount.
 * @return string|null Stored scope for the matching live code, or null.
 * @throws Throwable When database inspection fails.
 */
function app_link_model_pending_code_scope(
    string $codeHash,
    string $clientId,
    string $issuer,
): ?string {
    app_link_model_assert_hash($codeHash);
    $query = db()->prepare(
        'SELECT client_id, issuer, scope FROM app_link_codes '
            . 'WHERE code_hash = ? AND BINARY code_hash = BINARY ? '
            . 'AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP(6)',
    );
    $query->execute([$codeHash, $codeHash]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) && is_string($row['scope'])
        && hash_equals((string) $row['client_id'], $clientId)
        && hash_equals((string) $row['issuer'], $issuer)
            ? $row['scope'] : null;
}

/**
 * Atomically consume an authorization code and insert its grant and token hash.
 *
 * @param string $codeHash Lowercase SHA-256 digest of the authorization code.
 * @param string $clientId Validated registered public-client identifier.
 * @param string $issuer Trusted exact installation issuer.
 * @param string $redirectUri Exact loopback callback URI.
 * @param string $challenge S256 challenge computed from the submitted verifier.
 * @param list<string> $allowedScopes Exact registered consented scope bundles.
 * @param callable():string $makeTokenHash Generates and returns a token digest.
 * @param int $tokenTtl Positive token lifetime in seconds.
 * @param list<string> $refreshScopes Explicitly approved offline scope bundles.
 * @param callable():string|null $makeRefreshHash Fresh refresh hash factory,
 *     or null when refresh issuance is unavailable.
 * @param 'absent'|'ready'|'unknown' $refreshReclaimState Verified family storage.
 * @param int $refreshFamilyTtl Maximum family lifetime in seconds, or zero
 *     when no offline issuance is permitted.
 * @param int $refreshIdleTtl Maximum idle lifetime in seconds, or zero when
 *     no offline issuance is permitted.
 * @param string|null $expectedScope Previously observed exact code scope, or
 *     null for established callers that do not perform the optional pre-read.
 * @return array{grant_id:int,user_id:int,scope:string,refresh_expires_in?:int}|null
 *     Issued grant identity and optional refresh lifetime, or refusal.
 * @throws Throwable When storage, randomness or transaction ownership fails.
 */
function app_link_model_exchange(
    string $codeHash,
    string $clientId,
    string $issuer,
    string $redirectUri,
    string $challenge,
    array $allowedScopes,
    callable $makeTokenHash,
    int $tokenTtl,
    array $refreshScopes = [],
    ?callable $makeRefreshHash = null,
    string $refreshReclaimState = 'unknown',
    int $refreshFamilyTtl = 0,
    int $refreshIdleTtl = 0,
    ?string $expectedScope = null,
): ?array {
    app_link_model_assert_hash($codeHash);
    if ($tokenTtl <= 0) {
        throw new InvalidArgumentException('Token lifetime must be positive.');
    }
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Code exchange requires its own transaction.');
    }
    $owner = $pdo->prepare(
        'SELECT user_id FROM app_link_codes WHERE code_hash = ? '
            . 'AND BINARY code_hash = BINARY ?',
    );
    $owner->execute([$codeHash, $codeHash]);
    $ownerId = $owner->fetchColumn();
    if ($ownerId === false) {
        return null;
    }
    $userId = (int) $ownerId;
    $pdo->beginTransaction();
    try {
        $user = $pdo->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
        $user->execute([$userId]);
        if ($user->fetchColumn() !== 'admin') {
            $pdo->rollBack();
            return null;
        }
        $code = $pdo->prepare(
            'SELECT id, user_id, client_id, issuer, scope, redirect_uri, pkce_s256 '
                . 'FROM app_link_codes WHERE code_hash = ? '
                . 'AND BINARY code_hash = BINARY ? AND consumed_at IS NULL '
                . 'AND expires_at > UTC_TIMESTAMP(6) FOR UPDATE',
        );
        $code->execute([$codeHash, $codeHash]);
        $row = $code->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) $row['user_id'] !== $userId
            || !hash_equals((string) $row['client_id'], $clientId)
            || !hash_equals((string) $row['issuer'], $issuer)
            || !in_array((string) $row['scope'], $allowedScopes, true)
            || ($expectedScope !== null
                && !hash_equals((string) $row['scope'], $expectedScope))
            || !hash_equals((string) $row['redirect_uri'], $redirectUri)
            || !hash_equals((string) $row['pkce_s256'], $challenge)) {
            $pdo->rollBack();
            return null;
        }
        $capacity = $pdo->prepare(
            'SELECT id FROM app_link_grants FORCE INDEX (app_link_grants_owner) '
                . 'WHERE user_id = ? LIMIT ?',
        );
        $capacity->bindValue(1, $userId, PDO::PARAM_INT);
        $capacity->bindValue(2, APP_LINKING_GRANT_OWNER_CAP + 1, PDO::PARAM_INT);
        $capacity->execute();
        $ids = array_map(
            static fn(mixed $id): int => (int) $id,
            $capacity->fetchAll(PDO::FETCH_COLUMN),
        );
        if (count($ids) > APP_LINKING_GRANT_OWNER_CAP
            || (count($ids) === APP_LINKING_GRANT_OWNER_CAP
                && !app_link_model_reclaim_one(
                    $pdo, $userId, $ids, $refreshReclaimState,
                ))) {
            $pdo->rollBack();
            return null;
        }
        $consume = $pdo->prepare(
            'UPDATE app_link_codes SET consumed_at = UTC_TIMESTAMP(6) '
                . 'WHERE id = ? AND user_id = ? AND consumed_at IS NULL '
                . 'AND expires_at > UTC_TIMESTAMP(6)',
        );
        $consume->execute([(int) $row['id'], $userId]);
        if ($consume->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }
        $tokenHash = $makeTokenHash();
        app_link_model_assert_hash($tokenHash);
        $grant = $pdo->prepare(
            'INSERT INTO app_link_grants (user_id, client_id, issuer, scope, '
                . 'created_at, access_token_hash, access_issued_at, access_expires_at) '
                . 'VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), '
                . 'DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))',
        );
        $scope = (string) $row['scope'];
        $grant->execute([$userId, $clientId, $issuer, $scope, $tokenHash, $tokenTtl]);
        $grantId = (int) $pdo->lastInsertId();
        $refreshExpiresIn = null;
        $offlineRequested = str_contains(' ' . $scope . ' ', ' offline_access ');
        if ($offlineRequested) {
            if (!in_array($scope, $refreshScopes, true)
                || $makeRefreshHash === null || $refreshReclaimState !== 'ready') {
                $pdo->rollBack();
                return null;
            }
            $refreshHash = $makeRefreshHash();
            app_link_model_assert_hash($refreshHash);
            $refreshExpiresIn = app_link_refresh_model_issue_initial_owned(
                $pdo, $grantId, $userId, $clientId, $issuer, $scope, $refreshHash,
                $refreshFamilyTtl, $refreshIdleTtl,
            );
            if ($refreshExpiresIn === null) {
                $pdo->rollBack();
                return null;
            }
        }
        $pdo->commit();
        $result = ['grant_id' => $grantId, 'user_id' => $userId, 'scope' => $scope];
        if ($refreshExpiresIn !== null) {
            $result['refresh_expires_in'] = $refreshExpiresIn;
        }
        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Resolve an unexpired token against its live, unrevoked Admin grant.
 *
 * The caller must establish token-authentication schema readiness first and
 * authorize the returned scope for the requested resource.
 *
 * @param string $tokenHash Lowercase SHA-256 digest of the presented token.
 * @param string $issuer Trusted exact installation issuer including its mount.
 * @return array{grant_id:int,user_id:int,username:string,scope:string}|null
 *     Current identity and consented scope, or null when no active token exists.
 * @throws InvalidArgumentException When the digest is malformed.
 * @throws Throwable When database inspection or lookup fails.
 */
function app_link_model_lookup_token(string $tokenHash, string $issuer): ?array
{
    app_link_model_assert_hash($tokenHash);
    $statement = db()->prepare(
        'SELECT g.id AS grant_id, u.id AS user_id, u.username, g.scope '
            . 'FROM app_link_grants g INNER JOIN users u ON u.id = g.user_id '
            . 'WHERE g.access_token_hash = ? '
            . 'AND BINARY g.access_token_hash = BINARY ? '
            . 'AND BINARY g.issuer = BINARY ? AND g.revoked_at IS NULL '
            . 'AND g.access_expires_at > UTC_TIMESTAMP(6) '
            . 'AND BINARY u.role = BINARY ?',
    );
    $statement->execute([$tokenHash, $tokenHash, $issuer, 'admin']);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    return [
        'grant_id' => (int) $row['grant_id'],
        'user_id' => (int) $row['user_id'],
        'username' => (string) $row['username'],
        'scope' => (string) $row['scope'],
    ];
}

/**
 * Start a gallery creation transaction only while the bearer authority is live.
 *
 * The caller must already own the gallery writer lease and a durable operation
 * claim. Lock ordering matches revocation and refresh: user, then grant. A true
 * result leaves this connection's transaction open until the caller completes
 * or rolls back the entire operation. A false result leaves no transaction open.
 *
 * @param int $userId Previously authenticated grant owner's identity.
 * @param int $grantId Previously authenticated grant identity.
 * @param string $tokenHash SHA-256 digest of the presented bearer.
 * @param string $issuer Exact configured installation issuer.
 * @param string $clientId Exact registered public-client identifier.
 * @param string $scope Exact previously authenticated capability bundle.
 * @return bool True with both authority rows locked, false for stale authority.
 * @throws Throwable On transaction or storage failure after rollback.
 */
function app_link_model_create_transaction_begin(
    int $userId,
    int $grantId,
    string $tokenHash,
    string $issuer,
    string $clientId,
    string $scope,
): bool {
    app_link_model_assert_hash($tokenHash);
    $pdo = db();
    if ($pdo->inTransaction() || $userId <= 0 || $grantId <= 0) {
        throw new RuntimeException('Gallery creation needs an independent transaction.');
    }
    if (!$pdo->beginTransaction() || !$pdo->inTransaction()) {
        throw new RuntimeException('Gallery creation transaction is unavailable.');
    }
    try {
        $user = $pdo->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
        $user->execute([$userId]);
        if ($user->fetchColumn() !== 'admin') {
            if (!$pdo->rollBack() || $pdo->inTransaction()) {
                throw new RuntimeException('Gallery creation rollback is unavailable.');
            }
            return false;
        }
        $grant = $pdo->prepare(
            'SELECT id FROM app_link_grants WHERE id = ? AND user_id = ? '
                . 'AND BINARY client_id = BINARY ? AND BINARY issuer = BINARY ? '
                . 'AND BINARY scope = BINARY ? AND BINARY access_token_hash = BINARY ? '
                . 'AND revoked_at IS NULL AND access_expires_at > UTC_TIMESTAMP(6) FOR UPDATE',
        );
        $grant->execute([$grantId, $userId, $clientId, $issuer, $scope, $tokenHash]);
        if ($grant->fetchColumn() === false) {
            if (!$pdo->rollBack() || $pdo->inTransaction()) {
                throw new RuntimeException('Gallery creation rollback is unavailable.');
            }
            return false;
        }
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Finish or abandon a transaction started for a scoped gallery creation.
 *
 * @param bool $commit True to commit the gallery and receipt; false to roll back.
 * @return void Ends the owned transaction; filesystem effects are not undone.
 * @throws RuntimeException When the expected transaction is no longer active.
 * @throws Throwable When the database cannot complete the transition.
 */
function app_link_model_create_transaction_end(bool $commit): void
{
    $pdo = db();
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Gallery creation transaction was lost.');
    }
    $finished = $commit ? $pdo->commit() : $pdo->rollBack();
    if ($finished !== true || $pdo->inTransaction()) {
        throw new RuntimeException('Gallery creation transaction completion is unavailable.');
    }
}

/**
 * Revoke one owner's grant, optionally requiring its current token digest.
 *
 * The caller establishes the narrower revocation schema readiness first.
 * Owner revocation checks the live Admin role; bearer revocation requires the
 * original issuer and token digest even when the owner's role has changed.
 *
 * @param int $userId Stored grant owner identifier.
 * @param int $grantId Grant identifier selected by the authorized caller.
 * @param string|null $issuer Exact installation issuer for bearer revocation;
 *     null permits only the independently authenticated owner operation.
 * @param string|null $tokenHash Optional lowercase token digest for bearer
 *     revocation; null permits the authenticated owner's selected grant.
 * @return bool True only when an active matching grant was newly revoked;
 *     false when the owner, grant or supplied token does not match.
 * @throws InvalidArgumentException When a supplied digest is malformed.
 * @throws RuntimeException When a caller already owns a database transaction.
 * @throws Throwable When database work fails; the transaction is rolled back.
 */
function app_link_model_revoke(
    int $userId,
    int $grantId,
    ?string $issuer,
    ?string $tokenHash = null,
): bool {
    if ($tokenHash !== null) {
        app_link_model_assert_hash($tokenHash);
        if ($issuer === null) {
            throw new InvalidArgumentException('Bearer revocation requires an issuer.');
        }
    }
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Link revocation requires its own transaction.');
    }
    $pdo->beginTransaction();
    try {
        $user = $pdo->prepare($tokenHash === null
            ? 'SELECT role FROM users WHERE id = ? FOR UPDATE'
            : 'SELECT id FROM users WHERE id = ? FOR UPDATE');
        $user->execute([$userId]);
        $userValue = $user->fetchColumn();
        if ($userValue === false || ($tokenHash === null && $userValue !== 'admin')) {
            $pdo->rollBack();
            return false;
        }
        // Owner revocation remains available without token storage; bearer
        // revocation still verifies the token under this same grant lock.
        $columns = $tokenHash === null
            ? 'user_id, revoked_at'
            : 'user_id, issuer, access_token_hash, revoked_at';
        $grant = $pdo->prepare(
            'SELECT ' . $columns
                . ' FROM app_link_grants WHERE id = ? FOR UPDATE',
        );
        $grant->execute([$grantId]);
        $row = $grant->fetch(PDO::FETCH_ASSOC);
        if (
            !is_array($row)
            || (int) $row['user_id'] !== $userId
            || $row['revoked_at'] !== null
            || ($tokenHash !== null
                && (!hash_equals((string) $row['issuer'], (string) $issuer)
                    || !is_string($row['access_token_hash'])
                    || !hash_equals($row['access_token_hash'], $tokenHash)))
        ) {
            $pdo->rollBack();
            return false;
        }
        $update = $pdo->prepare(
            'UPDATE app_link_grants SET revoked_at = UTC_TIMESTAMP(6) '
                . 'WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
        );
        $update->execute([$grantId, $userId]);
        $revoked = $update->rowCount() === 1;
        $pdo->commit();
        return $revoked;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Revoke the grant addressed by one presented access-token digest.
 *
 * The lookup identifies the owner only; revocation rechecks the exact digest
 * under the same user-then-grant row locks used by the owner operation.
 *
 * @param string $tokenHash Lowercase SHA-256 digest of the presented token.
 * @param string $issuer Trusted exact installation issuer including its mount.
 * @return bool True only when an active matching grant was newly revoked;
 *     false when the digest is unknown or the grant was already revoked.
 * @throws InvalidArgumentException When the digest is malformed.
 * @throws RuntimeException When a caller already owns a database transaction.
 * @throws Throwable When database work fails; any active transaction rolls back.
 */
function app_link_model_revoke_token(string $tokenHash, string $issuer): bool
{
    app_link_model_assert_hash($tokenHash);
    $statement = db()->prepare(
        'SELECT id, user_id FROM app_link_grants '
            . 'WHERE access_token_hash = ? AND BINARY access_token_hash = BINARY ? '
            . 'AND BINARY issuer = BINARY ?',
    );
    $statement->execute([$tokenHash, $tokenHash, $issuer]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return false;
    }
    return app_link_model_revoke(
        (int) $row['user_id'],
        (int) $row['id'],
        $issuer,
        $tokenHash,
    );
}

/**
 * Atomically consume a bounded linking rate-limit bucket independently.
 *
 * A private transaction locks the shared bucket before verifying its actual
 * bounded subject count. A full bucket receives only a bounded stale-row cleanup;
 * a damaged over-cap bucket refuses admission without an unbounded recount.
 * The existing atomic rate-limit model is called only when its own unbounded
 * cap-cleanup branch cannot run. The caller must verify the full rate-limit
 * schema and exact indexes before this operation and supply only fixed policy.
 *
 * @param string $bucket Fixed application-owned linking bucket identifier.
 * @param string $subjectHash Lowercase HMAC digest of the normalized subject.
 * @param array{window_seconds:int,max_attempts:int,lock_seconds:int} $policy
 *     Fixed time window, admitted attempts and lock duration in seconds.
 * @param int $subjectCap Positive maximum subject rows in this bucket.
 * @param int $cleanupBatch Positive maximum stale subjects removed per call.
 * @param string $staleBefore SQL timestamp before which unlocked rows are stale.
 * @param string $now Current SQL timestamp in the existing limiter timezone.
 * @param int $nowTimestamp Current Unix timestamp for the existing limiter.
 * @return array{allowed:bool,retry_after_seconds:int,attempts:int,reason:string}
 *     Atomic admission decision, including storage-cap refusal when full.
 * @throws InvalidArgumentException When a digest or positive limit is invalid.
 * @throws RuntimeException When a caller already owns a database transaction.
 * @throws Throwable When database work fails; the transaction is rolled back.
 */
function app_link_model_rate_limit_consume(
    string $bucket,
    string $subjectHash,
    array $policy,
    int $subjectCap,
    int $cleanupBatch,
    string $staleBefore,
    string $now,
    int $nowTimestamp,
): array {
    app_link_model_assert_hash($subjectHash);
    if ($subjectCap <= 0 || $cleanupBatch <= 0) {
        throw new InvalidArgumentException('Rate-limit bounds must be positive.');
    }
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Link rate limiting requires its own transaction.');
    }
    $pdo->beginTransaction();
    try {
        $bucketInsert = $pdo->prepare(
            'INSERT INTO viewer_rate_limit_buckets (bucket, entry_count, updated_at) '
                . 'VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE bucket = bucket',
        );
        $bucketInsert->execute([$bucket, $now]);
        $bucketLock = $pdo->prepare(
            'SELECT entry_count FROM viewer_rate_limit_buckets '
                . 'WHERE bucket = ? FOR UPDATE',
        );
        $bucketLock->execute([$bucket]);
        if ($bucketLock->fetchColumn() === false) {
            throw new RuntimeException('Rate-limit bucket is unavailable.');
        }
        // The extra row detects drift above the cap without scanning beyond it.
        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM (SELECT subject_hash FROM viewer_rate_limits '
                . 'FORCE INDEX (PRIMARY) WHERE bucket = ? LIMIT ?) AS bounded',
        );
        $count->bindValue(1, $bucket, PDO::PARAM_STR);
        $count->bindValue(2, $subjectCap + 1, PDO::PARAM_INT);
        $count->execute();
        $countValue = $count->fetchColumn();
        if ($countValue === false) {
            throw new RuntimeException('Rate-limit count is unavailable.');
        }
        $subjectCount = (int) $countValue;
        if ($subjectCount > $subjectCap) {
            $pdo->rollBack();
            return [
                'allowed' => false,
                'retry_after_seconds' => 0,
                'attempts' => 0,
                'reason' => 'storage_cap',
            ];
        }
        $subject = $pdo->prepare(
            'SELECT subject_hash FROM viewer_rate_limits '
                . 'WHERE bucket = ? AND subject_hash = ?',
        );
        $subject->execute([$bucket, $subjectHash]);
        $knownSubject = $subject->fetchColumn() !== false;
        if (!$knownSubject && $subjectCount >= $subjectCap) {
            // Force the verified bucket-first primary index, and delete only
            // each selected subject rather than scanning other buckets.
            $cleanup = $pdo->prepare(
                'SELECT subject_hash FROM viewer_rate_limits FORCE INDEX (PRIMARY) '
                    . 'WHERE bucket = ? AND last_attempt_at < ? '
                    . 'AND (locked_until IS NULL OR locked_until < ?) '
                    . 'ORDER BY subject_hash LIMIT ? FOR UPDATE',
            );
            $cleanup->bindValue(1, $bucket, PDO::PARAM_STR);
            $cleanup->bindValue(2, $staleBefore, PDO::PARAM_STR);
            $cleanup->bindValue(3, $now, PDO::PARAM_STR);
            $cleanup->bindValue(4, $cleanupBatch, PDO::PARAM_INT);
            $cleanup->execute();
            $delete = $pdo->prepare(
                'DELETE FROM viewer_rate_limits WHERE bucket = ? '
                    . 'AND subject_hash = ? AND last_attempt_at < ? '
                    . 'AND (locked_until IS NULL OR locked_until < ?)',
            );
            foreach ($cleanup->fetchAll(PDO::FETCH_COLUMN) as $staleHash) {
                $delete->execute([$bucket, (string) $staleHash, $staleBefore, $now]);
                $subjectCount -= $delete->rowCount();
            }
        }
        // Repair drift only while this same bucket row remains locked.
        $repair = $pdo->prepare(
            'UPDATE viewer_rate_limit_buckets SET entry_count = ?, updated_at = ? '
                . 'WHERE bucket = ?',
        );
        $repair->execute([$subjectCount, $now, $bucket]);
        if (!$knownSubject && $subjectCount >= $subjectCap) {
            $pdo->commit();
            return [
                'allowed' => false,
                'retry_after_seconds' => 0,
                'attempts' => 0,
                'reason' => 'storage_cap',
            ];
        }
        $decision = viewer_rate_limit_model_consume(
            $bucket,
            $subjectHash,
            $policy,
            $subjectCap,
            $now,
            $nowTimestamp,
        );
        $pdo->commit();
        return $decision;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * List one owner's linking grants without exposing credential hashes.
 *
 * The caller must verify owner-listing schema readiness and authenticate the
 * same ordinary administrator. The verified owner-first index bounds the scan;
 * the same account can manage its own grants across issuers sharing this DB.
 *
 * @param int $userId Authenticated owner's stable user identifier.
 * @return array{links:list<array{id:int,issuer:string,client_id:string,scope:string,created_at:string,revoked_at:?string,access_issued_at:?string,access_expires_at:?string}>,truncated:bool}
 *     Bounded rows and an explicit indication that more rows exist.
 * @throws Throwable When database lookup fails.
 */
function app_link_model_list_owner(int $userId): array
{
    $statement = db()->prepare(
        'SELECT g.id, g.issuer, g.client_id, g.scope, g.created_at, g.revoked_at, '
            . 'g.access_issued_at, g.access_expires_at '
            . 'FROM app_link_grants AS g FORCE INDEX (app_link_grants_owner) '
            . 'INNER JOIN users u ON u.id = g.user_id '
            . 'WHERE g.user_id = ? AND BINARY u.role = BINARY ? '
            . 'ORDER BY g.revoked_at, g.id LIMIT ?',
    );
    $statement->bindValue(1, $userId, PDO::PARAM_INT);
    $statement->bindValue(2, 'admin', PDO::PARAM_STR);
    // Fetch one extra row to detect pre-existing over-cap schema drift.
    $statement->bindValue(3, APP_LINKING_GRANT_OWNER_CAP + 1, PDO::PARAM_INT);
    $statement->execute();
    $result = [];
    $truncated = false;
    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (count($result) === APP_LINKING_GRANT_OWNER_CAP) {
            $truncated = true;
            break;
        }
        $result[] = [
            'id' => (int) $row['id'],
            'issuer' => (string) $row['issuer'],
            'client_id' => (string) $row['client_id'],
            'scope' => (string) $row['scope'],
            'created_at' => (string) $row['created_at'],
            'revoked_at' => $row['revoked_at'] === null
                ? null : (string) $row['revoked_at'],
            'access_issued_at' => $row['access_issued_at'] === null
                ? null : (string) $row['access_issued_at'],
            'access_expires_at' => $row['access_expires_at'] === null
                ? null : (string) $row['access_expires_at'],
        ];
    }
    return ['links' => $result, 'truncated' => $truncated];
}
