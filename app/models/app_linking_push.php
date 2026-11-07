<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/app_linking_push.php
 * Module Type: Model
 * Purpose: Persist and atomically claim bounded pushed linking requests.
 * Responsibilities:
 *   - Read the installation-owned linking key without creating or rotating it.
 *   - Serialize capacity admission and bounded expired-request cleanup.
 *   - Bind a one-time claim to a live Admin and one browser session.
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
 * Read the migration-owned singleton key without modifying storage.
 *
 * @return string|false Raw binary key, or false when the singleton is absent.
 */
function app_link_push_model_installation_key(): string|false
{
    // Row 1 is the fixed installation identity shared with the migration.
    return db()->query('SELECT secret FROM app_link_installation_key WHERE id = 1')
        ->fetchColumn();
}

/**
 * Admit one encrypted request while serializing the bounded capacity scan.
 *
 * @param array{claim_hash:string,issuer:string,client_id:string,scope:string,redirect_uri:string,pkce_s256:string,state_nonce:string,state_cipher:string,state_tag:string} $request Validated fields and authenticated ciphertext.
 * @param int $ttl Request lifetime in seconds.
 * @param int $cap Maximum number of persisted requests.
 * @param int $batch Maximum expired rows removed for this admission.
 * @return bool True when inserted, false if the storage cap is full.
 * @throws Throwable When storage fails or the connection has a transaction.
 */
function app_link_push_model_create(array $request, int $ttl, int $cap, int $batch): bool
{
    $pdo = db();
    if ($pdo->inTransaction() || $ttl <= 0 || $cap <= 0 || $batch <= 0) {
        throw new RuntimeException('Invalid push request transaction or bounds.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->exec('INSERT IGNORE INTO app_link_request_capacity (id) VALUES (1)');
        $pdo->query('SELECT id FROM app_link_request_capacity WHERE id = 1 FOR UPDATE')->fetchColumn();
        $scan = $pdo->prepare('SELECT id FROM app_link_requests FORCE INDEX (PRIMARY) LIMIT ?');
        $scan->bindValue(1, $cap + 1, PDO::PARAM_INT);
        $scan->execute();
        $count = count($scan->fetchAll(PDO::FETCH_COLUMN));
        if ($count > $cap) {
            $pdo->rollBack();
            return false;
        }
        if ($count === $cap) {
            $cleanup = $pdo->prepare(
                'DELETE FROM app_link_requests WHERE expires_at <= UTC_TIMESTAMP(6) '
                    . 'ORDER BY expires_at, id LIMIT ?',
            );
            $cleanup->bindValue(1, $batch, PDO::PARAM_INT);
            $cleanup->execute();
            if ($cleanup->rowCount() === 0) {
                $pdo->rollBack();
                return false;
            }
        }
        $insert = $pdo->prepare(
            'INSERT INTO app_link_requests (claim_hash, issuer, client_id, scope, '
                . 'redirect_uri, pkce_s256, state_nonce, state_cipher, state_tag, '
                . 'created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, '
                . 'UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))',
        );
        $insert->execute([
            $request['claim_hash'], $request['issuer'], $request['client_id'],
            $request['scope'], $request['redirect_uri'], $request['pkce_s256'],
            $request['state_nonce'], $request['state_cipher'], $request['state_tag'],
            $ttl,
        ]);
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Claim an unexpired request once, binding it to the current session digest.
 *
 * @param string $claimHash SHA-256 digest of the canonical claim code.
 * @param string $issuer Exact trusted issuer.
 * @param int $userId Authenticated browser Admin identifier.
 * @param string $sessionHash HMAC of this session, issuer and Admin identifier.
 * @return int|null Claimed request ID, or null for any refusal.
 * @throws Throwable When storage fails or the connection has a transaction.
 */
function app_link_push_model_claim(
    string $claimHash,
    string $issuer,
    int $userId,
    string $sessionHash,
): ?int {
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Push claim requires its own transaction.');
    }
    $pdo->beginTransaction();
    try {
        $user = $pdo->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
        $user->execute([$userId]);
        if ($user->fetchColumn() !== 'admin') {
            $pdo->rollBack();
            return null;
        }
        $claim = $pdo->prepare(
            'SELECT id, issuer FROM app_link_requests WHERE claim_hash = ? '
                . 'AND BINARY claim_hash = BINARY ? AND claimed_at IS NULL '
                . 'AND expires_at > UTC_TIMESTAMP(6) FOR UPDATE',
        );
        $claim->execute([$claimHash, $claimHash]);
        $row = $claim->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals((string) $row['issuer'], $issuer)) {
            $pdo->rollBack();
            return null;
        }
        $id = (int) $row['id'];
        $update = $pdo->prepare(
            'UPDATE app_link_requests SET claimed_at = UTC_TIMESTAMP(6), '
                . 'claimed_user_id = ?, session_hash = ? WHERE id = ? '
                . 'AND claimed_at IS NULL AND expires_at > UTC_TIMESTAMP(6)',
        );
        $update->execute([$userId, $sessionHash, $id]);
        if ($update->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }
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
 * Read only an unexpired request bound to a still-live Admin and session.
 *
 * @param int $id Session-stored claimed request ID.
 * @param string $issuer Exact configured issuer.
 * @param int $userId Authenticated browser Admin identifier.
 * @param string $sessionHash HMAC of this session, issuer and Admin identifier.
 * @return array{claim_hash:string,issuer:string,client_id:string,scope:string,redirect_uri:string,pkce_s256:string,state_nonce:string,state_cipher:string,state_tag:string}|null
 *     Stored encrypted request bindings, or null.
 * @throws Throwable When database lookup fails.
 */
function app_link_push_model_read(int $id, string $issuer, int $userId, string $sessionHash): ?array
{
    $statement = db()->prepare(
        'SELECT r.claim_hash, r.issuer, r.client_id, r.scope, r.redirect_uri, '
            . 'r.pkce_s256, r.state_nonce, r.state_cipher, r.state_tag '
            . 'FROM app_link_requests r INNER JOIN users u ON u.id = r.claimed_user_id '
            . 'WHERE r.id = ? AND r.claimed_user_id = ? AND BINARY r.issuer = BINARY ? '
            . 'AND BINARY r.session_hash = BINARY ? AND r.claimed_at IS NOT NULL '
            . 'AND r.consent_consumed_at IS NULL '
            . 'AND r.expires_at > UTC_TIMESTAMP(6) AND BINARY u.role = BINARY ?',
    );
    $statement->execute([$id, $userId, $issuer, $sessionHash, 'admin']);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Consume one session-bound request before separately issuing a grant.
 *
 * @param int $id Session-stored claimed request ID.
 * @param string $issuer Exact configured issuer.
 * @param int $userId Authenticated browser Admin identifier.
 * @param string $sessionHash HMAC of this session, issuer and Admin identifier.
 * @return array{claim_hash:string,issuer:string,client_id:string,scope:string,redirect_uri:string,pkce_s256:string,state_nonce:string,state_cipher:string,state_tag:string}|null
 *     Encrypted bound fields, or null on refusal.
 * @throws Throwable When storage fails or the connection has a transaction.
 */
function app_link_push_model_consume(
    int $id,
    string $issuer,
    int $userId,
    string $sessionHash,
): ?array {
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Push consent requires its own transaction.');
    }
    $pdo->beginTransaction();
    try {
        $user = $pdo->prepare('SELECT role FROM users WHERE id = ? FOR UPDATE');
        $user->execute([$userId]);
        if ($user->fetchColumn() !== 'admin') {
            $pdo->rollBack();
            return null;
        }
        $select = $pdo->prepare(
            'SELECT claim_hash, issuer, client_id, scope, redirect_uri, pkce_s256, '
                . 'state_nonce, state_cipher, state_tag FROM app_link_requests '
                . 'WHERE id = ? AND claimed_user_id = ? '
                . 'AND BINARY issuer = BINARY ? AND BINARY session_hash = BINARY ? '
                . 'AND claimed_at IS NOT NULL AND consent_consumed_at IS NULL '
                . 'AND expires_at > UTC_TIMESTAMP(6) FOR UPDATE',
        );
        $select->execute([$id, $userId, $issuer, $sessionHash]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $pdo->rollBack();
            return null;
        }
        $consume = $pdo->prepare(
            'UPDATE app_link_requests SET consent_consumed_at = UTC_TIMESTAMP(6) '
                . 'WHERE id = ? AND consent_consumed_at IS NULL '
                . 'AND expires_at > UTC_TIMESTAMP(6)',
        );
        $consume->execute([$id]);
        if ($consume->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }
        $pdo->commit();
        return $row;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
