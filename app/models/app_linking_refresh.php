<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/app_linking_refresh.php
 * Module Type: Model
 * Purpose: Persist bounded, replay-detecting refresh token families.
 * Responsibilities:
 *   - Retain only token hashes and serialize rotation against live grants.
 *   - Revoke a grant family when a verified consumed token is replayed.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/**
 * Bound retained token hashes in one refresh family without losing replay proof.
 * Type: int. Units: issued token hashes. Scope: one grant family.
 * Consumers: app_link_refresh_model_rotate.
 * Rationale: 4096 covers 30 days at fifteen-minute intervals while bounding
 * storage; reaching it refuses rotation and requires a new authorization.
 */
const APP_LINK_REFRESH_GENERATION_LIMIT = 4096;

/**
 * Insert the first refresh hash inside an already locked code-exchange transaction.
 *
 * The caller must have locked the current user and inserted the corresponding
 * grant in this same transaction after explicit scope approval. The model
 * repeats the user/grant checks before inserting either family record. It never
 * commits or rolls back the caller's transaction and receives no plaintext.
 *
 * @param PDO $pdo Connection owning the ongoing code-exchange transaction.
 * @param int $grantId Newly inserted grant belonging to the same transaction.
 * @param int $userId Current consenting administrator identifier.
 * @param string $clientId Approved registered public-client identifier.
 * @param string $issuer Exact trusted HTTPS installation issuer and mount.
 * @param string $scope Exact approved scope bundle saved on the new grant.
 * @param string $refreshHash Lowercase SHA-256 hash of a fresh refresh token.
 * @param int $familyTtl Absolute family lifetime in positive seconds.
 * @param int $idleTtl Maximum inactivity in positive seconds.
 * @return int|null Remaining idle-limited seconds after insertion, or null if
 *     the bound live grant changed or the family is already expired.
 * @throws RuntimeException When transaction, policy bounds or storage fail.
 * @throws Throwable When database work fails; caller must roll back.
 */
function app_link_refresh_model_issue_initial_owned(
    PDO $pdo,
    int $grantId,
    int $userId,
    string $clientId,
    string $issuer,
    string $scope,
    string $refreshHash,
    int $familyTtl,
    int $idleTtl,
): ?int {
    if (!$pdo->inTransaction() || $grantId <= 0 || $userId <= 0
        || $familyTtl <= 0 || $idleTtl <= 0 || $idleTtl > $familyTtl
        || !str_contains(' ' . $scope . ' ', ' offline_access ')) {
        throw new RuntimeException('Refresh issuance requires a bounded transaction.');
    }
    app_link_model_assert_hash($refreshHash);
    // All mutation owners lock user before grant before family before token.
    $user = $pdo->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
    $user->execute([$userId]);
    if ($user->fetchColumn() !== 'admin') {
        return null;
    }
    $grant = $pdo->prepare(
        'SELECT client_id, issuer, scope, revoked_at, access_token_hash, '
            . '(access_expires_at > UTC_TIMESTAMP(6)) AS access_live '
            . 'FROM app_link_grants WHERE id = ? AND user_id = ? FOR UPDATE',
    );
    $grant->execute([$grantId, $userId]);
    $row = $grant->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || $row['revoked_at'] !== null
        || !is_string($row['access_token_hash']) || (int) $row['access_live'] !== 1
        || !hash_equals((string) $row['client_id'], $clientId)
        || !hash_equals((string) $row['issuer'], $issuer)
        || !hash_equals((string) $row['scope'], $scope)) {
        return null;
    }
    $family = $pdo->prepare(
        'INSERT INTO app_link_refresh_families '
            . '(grant_id, user_id, client_id, issuer, scope, created_at, '
            . 'expires_at, idle_expires_at, issued_count) '
            . 'VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), '
            . 'DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), '
            . 'DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), 1)',
    );
    $family->execute([
        $grantId, $userId, $clientId, $issuer, $scope, $familyTtl, $idleTtl,
    ]);
    $token = $pdo->prepare(
        'INSERT INTO app_link_refresh_tokens '
            . '(grant_id, token_hash, active_slot, issued_at) '
            . 'VALUES (?, ?, 1, UTC_TIMESTAMP(6))',
    );
    $token->execute([$grantId, $refreshHash]);
    $remaining = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), '
            . 'LEAST(expires_at, idle_expires_at)) '
            . 'FROM app_link_refresh_families WHERE grant_id = ?',
    );
    $remaining->execute([$grantId]);
    $seconds = $remaining->fetchColumn();
    return $seconds !== false && (int) $seconds > 0 ? (int) $seconds : null;
}

/**
 * Rotate one refresh token and its access token in one serialized transaction.
 *
 * The transport must apply bounded rate limits and protect bearer inputs. A
 * replayed, consumed hash revokes its still-active grant and family; the same
 * generic denial must be returned to clients for invalid and replayed tokens.
 * Hashes remain until family deletion so any previous generation is detectable.
 * The trusted factory returns only hashes; raw credentials remain in the service.
 *
 * @param string $refreshHash Lowercase SHA-256 hash of the submitted token.
 * @param string $clientId Registered public-client identifier from the request.
 * @param string $issuer Independently trusted exact HTTPS issuer and mount.
 * @param list<string> $allowedScopes Exact currently registered scope bundles.
 * @param callable(string):array{access_hash:string,refresh_hash:string} $makeHashes
 *     Trusted factory receiving the locked scope and returning fresh hashes.
 * @param int $accessTtl Maximum access credential lifetime in seconds.
 * @param int $idleTtl Maximum refresh inactivity in seconds.
 * @return array{status:'rotated',scope:string,expires_in:int,refresh_expires_in:int}|array{status:'replayed'}|null
 *     Rotated metadata, committed replay revocation, or an invalid request.
 * @throws RuntimeException When bounds, transaction ownership or storage fail.
 * @throws Throwable When database work fails after rollback.
 */
function app_link_refresh_model_rotate(
    string $refreshHash,
    string $clientId,
    string $issuer,
    array $allowedScopes,
    callable $makeHashes,
    int $accessTtl,
    int $idleTtl,
): ?array {
    app_link_model_assert_hash($refreshHash);
    if ($accessTtl <= 0 || $idleTtl <= 0 || $allowedScopes === []) {
        throw new RuntimeException('Invalid refresh rotation bounds.');
    }
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Refresh rotation requires its own transaction.');
    }
    // The unlocked lookup finds the lock identities; every value is rechecked.
    $owner = $pdo->prepare(
        'SELECT t.grant_id, g.user_id FROM app_link_refresh_tokens t '
            . 'INNER JOIN app_link_grants g ON g.id = t.grant_id '
            . 'WHERE t.token_hash = ? AND BINARY t.token_hash = BINARY ?',
    );
    $owner->execute([$refreshHash, $refreshHash]);
    $identity = $owner->fetch(PDO::FETCH_ASSOC);
    if (!is_array($identity)) {
        return null;
    }
    $grantId = (int) $identity['grant_id'];
    $userId = (int) $identity['user_id'];
    if ($grantId <= 0 || $userId <= 0) {
        return null;
    }
    $pdo->beginTransaction();
    try {
        $user = $pdo->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
        $user->execute([$userId]);
        if ($user->fetchColumn() !== 'admin') {
            $pdo->rollBack();
            return null;
        }
        $grant = $pdo->prepare(
            'SELECT client_id, issuer, scope, revoked_at FROM app_link_grants '
                . 'WHERE id = ? AND user_id = ? FOR UPDATE',
        );
        $grant->execute([$grantId, $userId]);
        $grantRow = $grant->fetch(PDO::FETCH_ASSOC);
        if (!is_array($grantRow) || $grantRow['revoked_at'] !== null
            || !hash_equals((string) $grantRow['client_id'], $clientId)
            || !hash_equals((string) $grantRow['issuer'], $issuer)
            || !str_contains(' ' . (string) $grantRow['scope'] . ' ', ' offline_access ')
            || !in_array((string) $grantRow['scope'], $allowedScopes, true)) {
            $pdo->rollBack();
            return null;
        }
        $family = $pdo->prepare(
            'SELECT user_id, client_id, issuer, scope, issued_count, revoked_at, '
                . '(expires_at > UTC_TIMESTAMP(6)) AS absolute_live, '
                . '(idle_expires_at > UTC_TIMESTAMP(6)) AS idle_live '
                . 'FROM app_link_refresh_families WHERE grant_id = ? FOR UPDATE',
        );
        $family->execute([$grantId]);
        $familyRow = $family->fetch(PDO::FETCH_ASSOC);
        if (!is_array($familyRow) || (int) $familyRow['user_id'] !== $userId
            || $familyRow['revoked_at'] !== null
            || (int) $familyRow['absolute_live'] !== 1
            || (int) $familyRow['idle_live'] !== 1
            || !hash_equals((string) $familyRow['client_id'], $clientId)
            || !hash_equals((string) $familyRow['issuer'], $issuer)
            || !hash_equals((string) $familyRow['scope'], (string) $grantRow['scope'])) {
            $pdo->rollBack();
            return null;
        }
        $token = $pdo->prepare(
            'SELECT id, active_slot, consumed_at FROM app_link_refresh_tokens '
                . 'WHERE grant_id = ? AND token_hash = ? '
                . 'AND BINARY token_hash = BINARY ? FOR UPDATE',
        );
        $token->execute([$grantId, $refreshHash, $refreshHash]);
        $tokenRow = $token->fetch(PDO::FETCH_ASSOC);
        if (!is_array($tokenRow)) {
            $pdo->rollBack();
            return null;
        }
        if ($tokenRow['consumed_at'] !== null && $tokenRow['active_slot'] === null) {
            $revokeFamily = $pdo->prepare(
                'UPDATE app_link_refresh_families SET revoked_at = UTC_TIMESTAMP(6) '
                    . 'WHERE grant_id = ? AND revoked_at IS NULL',
            );
            $revokeFamily->execute([$grantId]);
            $revokeGrant = $pdo->prepare(
                'UPDATE app_link_grants SET revoked_at = UTC_TIMESTAMP(6) '
                    . 'WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            );
            $revokeGrant->execute([$grantId, $userId]);
            if ($revokeFamily->rowCount() !== 1 || $revokeGrant->rowCount() !== 1) {
                throw new RuntimeException('Refresh replay transition failed.');
            }
            $pdo->commit();
            return ['status' => 'replayed'];
        }
        $issuedCount = (int) $familyRow['issued_count'];
        if ($tokenRow['consumed_at'] !== null || (int) $tokenRow['active_slot'] !== 1
            || $issuedCount < 1 || $issuedCount >= APP_LINK_REFRESH_GENERATION_LIMIT) {
            $pdo->rollBack();
            return null;
        }
        $hashes = $makeHashes((string) $grantRow['scope']);
        app_link_model_assert_hash($hashes['access_hash']);
        app_link_model_assert_hash($hashes['refresh_hash']);
        $consume = $pdo->prepare(
            'UPDATE app_link_refresh_tokens SET active_slot = NULL, '
                . 'consumed_at = UTC_TIMESTAMP(6) WHERE id = ? '
                . 'AND active_slot = 1 AND consumed_at IS NULL',
        );
        $consume->execute([(int) $tokenRow['id']]);
        if ($consume->rowCount() !== 1) {
            throw new RuntimeException('Refresh token consumption failed.');
        }
        $insert = $pdo->prepare(
            'INSERT INTO app_link_refresh_tokens '
                . '(grant_id, token_hash, active_slot, issued_at) '
                . 'VALUES (?, ?, 1, UTC_TIMESTAMP(6))',
        );
        $insert->execute([$grantId, $hashes['refresh_hash']]);
        $advance = $pdo->prepare(
            'UPDATE app_link_refresh_families SET issued_count = issued_count + 1, '
                . 'idle_expires_at = LEAST(expires_at, '
                . 'DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND)) '
                . 'WHERE grant_id = ? AND revoked_at IS NULL '
                . 'AND expires_at > UTC_TIMESTAMP(6) '
                . 'AND idle_expires_at > UTC_TIMESTAMP(6) AND issued_count = ?',
        );
        $advance->execute([$idleTtl, $grantId, $issuedCount]);
        if ($advance->rowCount() !== 1) {
            throw new RuntimeException('Refresh family advancement failed.');
        }
        $updateAccess = $pdo->prepare(
            'UPDATE app_link_grants g INNER JOIN app_link_refresh_families f '
                . 'ON f.grant_id = g.id SET g.access_token_hash = ?, '
                . 'g.access_issued_at = UTC_TIMESTAMP(6), g.access_expires_at = '
                . 'LEAST(f.expires_at, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND)) '
                . 'WHERE g.id = ? AND g.user_id = ? AND g.revoked_at IS NULL '
                . 'AND f.revoked_at IS NULL AND f.expires_at > UTC_TIMESTAMP(6)',
        );
        $updateAccess->execute([$hashes['access_hash'], $accessTtl, $grantId, $userId]);
        if ($updateAccess->rowCount() !== 1) {
            throw new RuntimeException('Refresh access issuance failed.');
        }
        $lifetimes = $pdo->prepare(
            'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), g.access_expires_at) '
                . 'AS access_seconds, TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(6), '
                . 'LEAST(f.expires_at, f.idle_expires_at)) AS refresh_seconds '
                . 'FROM app_link_grants g INNER JOIN app_link_refresh_families f '
                . 'ON f.grant_id = g.id WHERE g.id = ?',
        );
        $lifetimes->execute([$grantId]);
        $remaining = $lifetimes->fetch(PDO::FETCH_ASSOC);
        if (!is_array($remaining) || (int) $remaining['access_seconds'] <= 0
            || (int) $remaining['refresh_seconds'] <= 0) {
            $pdo->rollBack();
            return null;
        }
        $pdo->commit();
        return [
            'status' => 'rotated',
            'scope' => (string) $grantRow['scope'],
            'expires_in' => (int) $remaining['access_seconds'],
            'refresh_expires_in' => (int) $remaining['refresh_seconds'],
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Revoke a matching refresh family and grant without requiring a live token.
 *
 * The holder may disconnect even after expiry or account demotion. The
 * transport must return the same response for invalid and recognized inputs;
 * a consumed historical token may also revoke its own original family.
 *
 * @param string $refreshHash Lowercase SHA-256 hash of the submitted token.
 * @param string $clientId Registered public-client identifier from the request.
 * @param string $issuer Independently trusted exact HTTPS issuer and mount.
 * @return bool True when a matching owned family exists; false otherwise.
 * @throws RuntimeException When transaction ownership or storage fails.
 * @throws Throwable When database work fails after rollback.
 */
function app_link_refresh_model_revoke_token(
    string $refreshHash,
    string $clientId,
    string $issuer,
): bool {
    app_link_model_assert_hash($refreshHash);
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Refresh revocation requires its own transaction.');
    }
    $owner = $pdo->prepare(
        'SELECT t.grant_id, g.user_id FROM app_link_refresh_tokens t '
            . 'INNER JOIN app_link_grants g ON g.id = t.grant_id '
            . 'WHERE t.token_hash = ? AND BINARY t.token_hash = BINARY ?',
    );
    $owner->execute([$refreshHash, $refreshHash]);
    $identity = $owner->fetch(PDO::FETCH_ASSOC);
    if (!is_array($identity)) {
        return false;
    }
    $grantId = (int) $identity['grant_id'];
    $userId = (int) $identity['user_id'];
    if ($grantId <= 0 || $userId <= 0) {
        return false;
    }
    $pdo->beginTransaction();
    try {
        // A demoted but existing account must still be able to remove authority.
        $user = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $user->execute([$userId]);
        if ($user->fetchColumn() === false) {
            $pdo->rollBack();
            return false;
        }
        $grant = $pdo->prepare(
            'SELECT client_id, issuer FROM app_link_grants '
                . 'WHERE id = ? AND user_id = ? FOR UPDATE',
        );
        $grant->execute([$grantId, $userId]);
        $grantRow = $grant->fetch(PDO::FETCH_ASSOC);
        if (!is_array($grantRow)
            || !hash_equals((string) $grantRow['client_id'], $clientId)
            || !hash_equals((string) $grantRow['issuer'], $issuer)) {
            $pdo->rollBack();
            return false;
        }
        $family = $pdo->prepare(
            'SELECT user_id, client_id, issuer FROM app_link_refresh_families '
                . 'WHERE grant_id = ? FOR UPDATE',
        );
        $family->execute([$grantId]);
        $familyRow = $family->fetch(PDO::FETCH_ASSOC);
        if (!is_array($familyRow) || (int) $familyRow['user_id'] !== $userId
            || !hash_equals((string) $familyRow['client_id'], $clientId)
            || !hash_equals((string) $familyRow['issuer'], $issuer)) {
            $pdo->rollBack();
            return false;
        }
        $token = $pdo->prepare(
            'SELECT grant_id FROM app_link_refresh_tokens '
                . 'WHERE grant_id = ? AND token_hash = ? '
                . 'AND BINARY token_hash = BINARY ? FOR UPDATE',
        );
        $token->execute([$grantId, $refreshHash, $refreshHash]);
        if ($token->fetchColumn() === false) {
            $pdo->rollBack();
            return false;
        }
        $revokeFamily = $pdo->prepare(
            'UPDATE app_link_refresh_families SET revoked_at = '
                . 'COALESCE(revoked_at, UTC_TIMESTAMP(6)) WHERE grant_id = ?',
        );
        $revokeFamily->execute([$grantId]);
        $revokeGrant = $pdo->prepare(
            'UPDATE app_link_grants SET revoked_at = '
                . 'COALESCE(revoked_at, UTC_TIMESTAMP(6)) '
                . 'WHERE id = ? AND user_id = ?',
        );
        $revokeGrant->execute([$grantId, $userId]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
