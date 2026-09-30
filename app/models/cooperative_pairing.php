<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/cooperative_pairing.php
 * Module Type: Model
 * Purpose: Own atomic peer and invitation persistence.
 * Responsibilities: Lock pairing rows, protect concurrent transitions and provide bounded administrative reads.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/** Execute a local atomic transition; network calls must never run inside this callback.
 *
 * @param callable $operation Trusted local callback; never obtained from request data.
 * @return array<string,mixed>|int|null Result described by the operation above.
 */
function cooperative_pairing_model_transaction(callable $operation): mixed
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Pairing cannot join another transaction.');
    }
    $pdo->beginTransaction();
    try {
        $result = $operation();
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** Read a pairing by public invitation identity, optionally locking its transition.
 *
 * @param string $invitationId Stable public invitation identifier.
 * @param bool $lock Whether to acquire a transaction-scoped row lock.
 * @return array<string,mixed>|null Result described by the operation above.
 */
function cooperative_pairing_model_find(string $invitationId, bool $lock = false): ?array
{
    $sql = 'SELECT * FROM cooperative_pairings WHERE invitation_id = ?';
    if ($lock && db()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $statement = db()->prepare($sql);
    $statement->execute([$invitationId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    foreach (['id', 'revision', 'expires_at', 'attempts', 'next_attempt_at'] as $key) {
        $row[$key] = (int) $row[$key];
    }
    return $row;
}

/** Lock the peer independently of invitation direction before changing credentials.
 *
 * @param string $peerId Stable remote installation identifier.
 * @return array<string,mixed>|null Result described by the operation above.
 */
function cooperative_pairing_model_lock_peer(string $peerId): ?array
{
    $sql = 'SELECT instance_id FROM cooperative_peers WHERE instance_id = ?';
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $statement = db()->prepare($sql);
    $statement->execute([$peerId]);
    return $statement->fetchColumn() === false ? null : cooperative_model_peer_find($peerId);
}

/** Insert a fresh peer and its invitation together; an existing relationship is never overwritten.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @param array<string,mixed> $peer Direct peer credential record.
 * @param string $now UTC persistence timestamp.
 * @param bool $insertPeer Whether the peer is new rather than a revoked generation already replaced.
 * @return int Result described by the operation above.
 */
function cooperative_pairing_model_insert(array $pair, array $peer, string $now, bool $insertPeer = true): int
{
    if ($insertPeer) {
        cooperative_model_peer_insert($peer, $now);
    }
    db()->prepare('INSERT INTO cooperative_pairings (invitation_id, peer_id, role, state, revision, expires_at, secret_hash, payload_cipher, attempts, next_attempt_at, last_error, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?, 0, 0, ?, ?)')
        ->execute([$pair['invitation_id'], $pair['peer_id'], $pair['role'], $pair['state'], $pair['expires_at'], $pair['secret_hash'], $pair['payload_cipher'], '', $now]);
    return (int) db()->lastInsertId();
}

/** Persist a locked pairing with an additional optimistic guard.
 *
 * @param array<string,mixed> $pair Internal pairing record; encrypted recovery payload stays server-side.
 * @param string $now UTC persistence timestamp.
 * @return bool Result described by the operation above.
 */
function cooperative_pairing_model_save(array $pair, string $now): bool
{
    $statement = db()->prepare('UPDATE cooperative_pairings SET state = ?, revision = revision + 1, payload_cipher = ?, attempts = ?, next_attempt_at = ?, last_error = ?, updated_at = ? WHERE id = ? AND revision = ?');
    $statement->execute([$pair['state'], $pair['payload_cipher'], $pair['attempts'], $pair['next_attempt_at'], $pair['last_error'], $now, $pair['id'], $pair['revision']]);
    return $statement->rowCount() === 1;
}

/** Read bounded nonsecret administrative state, using a local numeric pagination cursor.
 *
 * @param int $afterId Exclusive local numeric pagination cursor.
 * @param int $limit Maximum number of records or bytes to accept.
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_model_list(int $afterId, int $limit): array
{
    $limit = max(1, min(100, $limit));
    $statement = db()->prepare('SELECT c.id, c.invitation_id, c.peer_id, c.role, c.state, c.revision, c.expires_at, c.attempts, c.next_attempt_at, c.last_error, p.base_url, p.state AS peer_state FROM cooperative_pairings c JOIN cooperative_peers p ON p.instance_id = c.peer_id WHERE c.id > ? ORDER BY c.id LIMIT ' . $limit);
    $statement->execute([max(0, $afterId)]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** Revoke through the minimal verified invitation-to-peer mapping even when recovery storage is unavailable.
 *
 * @param string $invitationId Stable public invitation identifier.
 * @param int $expectedRevision Expected local optimistic revision from the administrator snapshot.
 * @return array<string,mixed>|null Result described by the operation above.
 */
function cooperative_pairing_model_local_revoke(string $invitationId, int $expectedRevision): ?array
{
    return cooperative_pairing_model_transaction(/** Apply the local transition or bounded response callback.
     * @return array<string,mixed>|null Transition result or accepted byte count.
     */ static function () use ($invitationId, $expectedRevision): ?array {
        $sql = 'SELECT id, peer_id, revision FROM cooperative_pairings WHERE invitation_id = ?';
        if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $statement = db()->prepare($sql);
        $statement->execute([$invitationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int) $row['revision'] !== $expectedRevision) {
            return null;
        }
        cooperative_model_peer_revoke($row['peer_id']);
        db()->prepare('UPDATE cooperative_pairings SET revision = revision + 1 WHERE id = ?')->execute([$row['id']]);
        return ['id' => (int) $row['id'], 'peer_id' => $row['peer_id'], 'revision' => $expectedRevision + 1];
    });
}

/** Lock an existing relationship lifecycle before locking its peer, preserving lock order.
 * @param string $peerId Stable remote installation identifier.
 * @return array<string,mixed>|null Existing pairing record, if any.
 */
function cooperative_pairing_model_find_peer(string $peerId): ?array
{
    $sql = 'SELECT invitation_id FROM cooperative_pairings WHERE peer_id = ?';
    if (db()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $sql .= ' FOR UPDATE';
    }
    $statement = db()->prepare($sql);
    $statement->execute([$peerId]);
    $id = $statement->fetchColumn();
    return $id === false ? null : cooperative_pairing_model_find((string) $id);
}

/** Replace a revoked lifecycle without reusing its invitation or resetting revisions.
 * @param array<string,mixed> $pair Fresh lifecycle with newly generated authority.
 * @param array<string,mixed> $previous Locked previous lifecycle.
 * @param string $now UTC persistence timestamp.
 * @return array<string,mixed> Fresh record with stable numeric identity and increased revision.
 */
function cooperative_pairing_model_replace(array $pair, array $previous, string $now): array
{
    $statement = db()->prepare('UPDATE cooperative_pairings SET invitation_id = ?, role = ?, state = ?, revision = revision + 1, expires_at = ?, secret_hash = ?, payload_cipher = ?, attempts = 0, next_attempt_at = 0, last_error = ?, updated_at = ? WHERE id = ? AND revision = ?');
    $statement->execute([$pair['invitation_id'], $pair['role'], $pair['state'], $pair['expires_at'], $pair['secret_hash'], $pair['payload_cipher'], '', $now, $previous['id'], $previous['revision']]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Concurrent pairing replacement.');
    }
    $pair['id'] = $previous['id'];
    $pair['revision'] = $previous['revision'] + 1;
    return $pair;
}
