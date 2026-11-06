<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Persist actor-bound claims and completed responses under connection-scoped exclusion.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/admin_operation_keys.php
 * Module Type: Model
 * Purpose: Own durable operation-key SQL and connection-scoped worker exclusion.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

use RuntimeException;
use function Gallery\Core\db;
use const Gallery\Core\ADMIN_OPERATION_PENDING_PAGE_SIZE;
use const Gallery\Core\ADMIN_OPERATION_PRIMARY_COLUMNS;

require_once dirname(__DIR__) . '/policy_constants.php';

/**
 * Observe the exact uniqueness definition, independently of named-index existence.
 *
 * Reads at most the expected column count plus one lookahead. A confirmed absent,
 * reordered, prefixed, nonunique or extra-column definition lacks the guarantee.
 * Native inspection failures and incomplete metadata remain unknown; no database
 * exception or raw definition crosses the model boundary.
 *
 * @return 'available'|'missing'|'unknown' Observed full actor/key PRIMARY guarantee.
 */
function admin_operation_model_primary_state(): string
{
    try {
        $statement = db()->prepare(
            'SELECT COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX LIMIT '
                . (count(ADMIN_OPERATION_PRIMARY_COLUMNS) + 1),
        );
        if (!$statement->execute(['admin_operation_keys', 'PRIMARY'])) {
            return 'unknown';
        }
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) !== count(ADMIN_OPERATION_PRIMARY_COLUMNS)) {
            return 'missing';
        }
        foreach (ADMIN_OPERATION_PRIMARY_COLUMNS as $offset => $column) {
            $row = $rows[$offset];
            foreach ([
                'COLUMN_NAME',
                'SEQ_IN_INDEX',
                'NON_UNIQUE',
                'SUB_PART',
            ] as $field) {
                if (!array_key_exists($field, $row)) {
                    return 'unknown';
                }
            }
            if (
                $row['COLUMN_NAME'] !== $column
                || !in_array(
                    $row['SEQ_IN_INDEX'],
                    [$offset + 1, (string) ($offset + 1)],
                    true,
                )
                || !in_array($row['NON_UNIQUE'], [0, '0'], true)
                || $row['SUB_PART'] !== null
            ) {
                return 'missing';
            }
        }
        return 'available';
    } catch (\Throwable) {
        return 'unknown';
    }
}

/**
 * Acquire a non-waiting lock; a crashed connection releases it automatically.
 *
 * @param int $actorId Authenticated administrator identifier.
 * @param string $keyHash SHA-256 operation-key digest.
 * @return string|null Owned database-scoped lock name, or null when another worker owns it.
 */
function admin_operation_model_lock(int $actorId, string $keyHash): ?string
{
    if (db()->inTransaction()) {
        throw new RuntimeException(
            'Operation claims require an independent durable commit.',
        );
    }
    $databaseName = (string) db()->query('SELECT DATABASE()')->fetchColumn();
    $name = hash(
        'sha256',
        $databaseName . ':admin_operation:' . $actorId . ':' . $keyHash,
    );
    $statement = db()->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([$name]);
    $result = $statement->fetchColumn();
    if ($result === null || $result === false) {
        throw new RuntimeException(
            'Operation ownership could not be verified.',
        );
    }
    return (int) $result === 1 ? $name : null;
}

/**
 * Release only the exact connection lock acquired for this operation.
 *
 * @param string $lockName Database-scoped lock name returned by the model.
 * @return void
 */
function admin_operation_model_unlock(string $lockName): void
{
    $statement = db()->prepare('SELECT RELEASE_LOCK(?)');
    $statement->execute([$lockName]);
}

/**
 * Verify that this connection still owns a previously acquired operation lease.
 *
 * @param string $lockName Opaque name returned by the operation lock owner.
 * @return bool True only when the current connection holds the named lock.
 */
function admin_operation_model_lock_owned(string $lockName): bool
{
    $statement = db()->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
    $statement->execute([$lockName]);
    return (int) $statement->fetchColumn() === 1;
}

/**
 * Atomically insert a pending claim without changing any previous claim.
 *
 * @param int $actorId Authenticated administrator identifier.
 * @param string $keyHash SHA-256 operation-key digest.
 * @param string $operation Closed operation name selected by the controller.
 * @param string $payloadHash Canonical semantic input digest.
 * @param string $ownerHash Private worker ownership digest.
 * @param string $now UTC SQL timestamp.
 * @return array<string,mixed> Persisted claim, including any prior outcome.
 */
function admin_operation_model_claim(
    int $actorId,
    string $keyHash,
    string $operation,
    string $payloadHash,
    string $ownerHash,
    string $now,
): array {
    if (db()->inTransaction()) {
        throw new RuntimeException(
            'Operation claim cannot join a target transaction.',
        );
    }
    $statement = db()->prepare(
        "INSERT INTO admin_operation_keys (actor_id, key_hash, operation_name, payload_hash, owner_hash, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?) ON DUPLICATE KEY UPDATE key_hash = key_hash",
    );
    $statement->execute([
        $actorId,
        $keyHash,
        $operation,
        $payloadHash,
        $ownerHash,
        $now,
        $now,
    ]);
    return (
        admin_operation_model_find(
            $actorId,
            $keyHash,
        ) ?? throw new RuntimeException('Operation claim could not be read.')
    );
}

/**
 * Read one exact actor-bound ledger row without searching by title or filename.
 *
 * @param int $actorId Original authenticated administrator identifier.
 * @param string $keyHash SHA-256 operation-key digest.
 * @return array<string,mixed>|null Durable claim or confirmed missing row.
 */
function admin_operation_model_find(int $actorId, string $keyHash): ?array
{
    $statement = db()->prepare(
        'SELECT actor_id, key_hash, operation_name, payload_hash, owner_hash, state, response_json, created_at, updated_at FROM admin_operation_keys WHERE actor_id = ? AND key_hash = ?',
    );
    $statement->execute([$actorId, $keyHash]);
    return $statement->fetch() ?: null;
}

/**
 * Recognize a complete canonical linked-create tombstone.
 * @param array<string,mixed> $row Stored row read under the operation lock.
 * @param int $actorId Expected owner.
 * @param string $keyHash Expected operation key digest.
 * @param string $payloadHash Expected normalized payload digest.
 * @return bool True only for an exact tombstone with valid identities and dates.
 */
function admin_operation_model_linked_tombstone_valid(
    array $row,
    int $actorId,
    string $keyHash,
    string $payloadHash,
): bool {
    foreach (['key_hash', 'payload_hash', 'owner_hash'] as $field) {
        if (!is_string($row[$field] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $row[$field]) !== 1) {
            return false;
        }
    }
    foreach (['created_at', 'updated_at'] as $field) {
        if (!is_string($row[$field] ?? null)
            || preg_match('/\A[1-9][0-9]{3}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01]) (?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\z/D', $row[$field]) !== 1) {
            return false;
        }
    }
    return (string) ($row['actor_id'] ?? '') === (string) $actorId
        && $row['key_hash'] === $keyHash && $row['payload_hash'] === $payloadHash
        && ($row['operation_name'] ?? null) === 'gallery.create'
        && ($row['state'] ?? null) === 'abandoned'
        && array_key_exists('response_json', $row) && $row['response_json'] === null;
}

/**
 * Describe valid closed linked-create rows for ledger health queries.
 * @return string Model-local SQL predicate; invalid tombstones remain unknown.
 */
function admin_operation_model_linked_tombstone_sql(): string
{
    $hashes = [];
    foreach (['key_hash', 'payload_hash', 'owner_hash'] as $field) {
        $hashes[] = '(CHAR_LENGTH(' . $field . ') = 64 AND BINARY '
            . $field . ' = BINARY LOWER(' . $field . ') AND UNHEX('
            . $field . ') IS NOT NULL)';
    }
    $datePattern = "'^[1-9][0-9]{3}-(0[1-9]|1[0-2])-(0[1-9]|[12][0-9]|3[01]) ([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$'";
    return "(BINARY state = BINARY 'abandoned' "
        . "AND BINARY operation_name = BINARY 'gallery.create' "
        . 'AND actor_id > 0 AND response_json IS NULL AND '
        . "DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') REGEXP " . $datePattern
        . " AND DATE_FORMAT(updated_at, '%Y-%m-%d %H:%i:%s') REGEXP " . $datePattern
        . ' AND ' . implode(' AND ', $hashes) . ')';
}

/**
 * Close the exact owned pending claim after its caller proved no filesystem attempt.
 *
 * @param array{actor_id:int|numeric-string,key_hash:string,payload_hash:string,owner_hash:string} $claim Original owned claim identity.
 * @param string $now UTC SQL timestamp.
 * @return void Returns only after a canonical tombstone is read back in the active transaction.
 * @throws RuntimeException When transaction or exact claim ownership is unavailable.
 */
function admin_operation_model_abandon_before_filesystem(array $claim, string $now): void
{
    $pdo = db();
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Linked operation transaction was lost.');
    }
    $update = $pdo->prepare('UPDATE admin_operation_keys SET state = ?, updated_at = ? '
        . 'WHERE actor_id = ? AND BINARY key_hash = BINARY ? '
        . "AND BINARY operation_name = BINARY 'gallery.create' "
        . 'AND BINARY payload_hash = BINARY ? AND BINARY owner_hash = BINARY ? '
        . "AND BINARY state = BINARY 'pending' AND response_json IS NULL");
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Linked operation transaction was lost.');
    }
    $update->execute([
        'abandoned', $now, $claim['actor_id'], $claim['key_hash'],
        $claim['payload_hash'], $claim['owner_hash'],
    ]);
    if ($update->rowCount() !== 1 || !$pdo->inTransaction()) {
        throw new RuntimeException('Linked operation ownership is unavailable.');
    }
    $row = admin_operation_model_find((int) $claim['actor_id'], $claim['key_hash']);
    if (!is_array($row) || !$pdo->inTransaction()
        || !admin_operation_model_linked_tombstone_valid(
            $row, (int) $claim['actor_id'], $claim['key_hash'], $claim['payload_hash'],
        ) || ($row['owner_hash'] ?? null) !== $claim['owner_hash']) {
        throw new RuntimeException('Linked operation tombstone is unavailable.');
    }
}

/**
 * Close one exact linked-create key without asserting that it had no effects.
 *
 * The caller owns the operation lock, gallery writer lease, live-grant locks
 * and transaction. Existing completed results are never replaced.
 *
 * @param int $actorId Original live administrator identity.
 * @param string $keyHash Exact actor-bound linked operation digest.
 * @param string $payloadHash Exact canonical creation fingerprint.
 * @param string $ownerHash Fresh non-secret worker identity digest.
 * @param string $now UTC SQL timestamp.
 * @return 'abandoned'|'completed' Verified tombstone or an existing receipt.
 * @throws RuntimeException When ownership, identity, state or readback is uncertain.
 */
function admin_operation_model_abandon_linked_create(
    int $actorId,
    string $keyHash,
    string $payloadHash,
    string $ownerHash,
    string $now,
): string {
    $pdo = db();
    if (!$pdo->inTransaction() || $actorId <= 0
        || preg_match('/\A[a-f0-9]{64}\z/D', $keyHash) !== 1
        || preg_match('/\A[a-f0-9]{64}\z/D', $payloadHash) !== 1
        || preg_match('/\A[a-f0-9]{64}\z/D', $ownerHash) !== 1) {
        throw new RuntimeException('Linked operation ownership is unavailable.');
    }
    $select = $pdo->prepare(
        'SELECT actor_id, key_hash, operation_name, payload_hash, owner_hash, '
        . 'state, response_json, created_at, updated_at FROM admin_operation_keys '
        . 'WHERE actor_id = ? AND BINARY key_hash = BINARY ? FOR UPDATE',
    );
    $select->execute([$actorId, $keyHash]);
    $row = $select->fetch(\PDO::FETCH_ASSOC);
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Linked operation transaction was lost.');
    }
    $expectedOwner = $ownerHash;
    if ($row === false) {
        $insert = $pdo->prepare(
            'INSERT INTO admin_operation_keys '
            . '(actor_id, key_hash, operation_name, payload_hash, owner_hash, '
            . 'state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $insert->execute([
            $actorId, $keyHash, 'gallery.create', $payloadHash, $ownerHash,
            'abandoned', $now, $now,
        ]);
        if ($insert->rowCount() !== 1) {
            throw new RuntimeException('Linked operation closure is unavailable.');
        }
    } else {
        if (!is_array($row) || (int) ($row['actor_id'] ?? 0) !== $actorId
            || ($row['key_hash'] ?? null) !== $keyHash
            || ($row['operation_name'] ?? null) !== 'gallery.create'
            || ($row['payload_hash'] ?? null) !== $payloadHash) {
            throw new RuntimeException('Linked operation identity does not match.');
        }
        if ($row['state'] === 'completed') {
            return 'completed';
        }
        if ($row['state'] === 'abandoned') {
            if (admin_operation_model_linked_tombstone_valid(
                $row, $actorId, $keyHash, $payloadHash,
            )) {
                return 'abandoned';
            }
            throw new RuntimeException('Linked operation tombstone is invalid.');
        }
        // A pending row may still belong to a worker whose DB connection died
        // before its filesystem work stopped. A free DB lease is not proof.
        if ($row['state'] !== 'needs_reconciliation'
            || $row['response_json'] !== null || !is_string($row['owner_hash'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $row['owner_hash']) !== 1) {
            throw new RuntimeException('Linked operation state is unavailable.');
        }
        $expectedOwner = $row['owner_hash'];
        $update = $pdo->prepare(
            'UPDATE admin_operation_keys SET state = ?, updated_at = ? '
            . 'WHERE actor_id = ? AND BINARY key_hash = BINARY ? '
            . 'AND BINARY operation_name = BINARY ? AND BINARY payload_hash = BINARY ? '
            . 'AND BINARY owner_hash = BINARY ? AND BINARY state = BINARY ? '
            . 'AND response_json IS NULL',
        );
        $update->execute([
            'abandoned', $now, $actorId, $keyHash, 'gallery.create',
            $payloadHash, $row['owner_hash'], $row['state'],
        ]);
        if ($update->rowCount() !== 1) {
            throw new RuntimeException('Linked operation closure is unavailable.');
        }
    }
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('Linked operation transaction was lost.');
    }
    $select->execute([$actorId, $keyHash]);
    $confirmed = $select->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($confirmed)
        || !admin_operation_model_linked_tombstone_valid(
            $confirmed, $actorId, $keyHash, $payloadHash,
        )
        || ($confirmed['owner_hash'] ?? null) !== $expectedOwner
        || !$pdo->inTransaction()) {
        throw new RuntimeException('Linked operation closure could not be verified.');
    }
    return 'abandoned';
}

/**
 * Durably complete an owned pending/reconciled claim before HTTP output.
 *
 * @param array<string,mixed> $claim Original claim identity and worker ownership.
 * @param string $responseJson Bounded credential-free canonical response.
 * @param string $now UTC SQL timestamp.
 * @return void
 */
function admin_operation_model_complete(
    array $claim,
    string $responseJson,
    string $now,
): void {
    if (db()->inTransaction()) {
        throw new RuntimeException(
            'Operation response must commit before output.',
        );
    }
    $statement = db()->prepare(
        "UPDATE admin_operation_keys SET state = 'completed', response_json = ?, updated_at = ? WHERE actor_id = ? AND key_hash = ? AND operation_name = ? AND payload_hash = ? AND owner_hash = ? AND state IN ('pending', 'needs_reconciliation')",
    );
    $statement->execute([
        $responseJson,
        $now,
        $claim['actor_id'],
        $claim['key_hash'],
        $claim['operation_name'],
        $claim['payload_hash'],
        $claim['owner_hash'],
    ]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Operation completion ownership was lost.');
    }
}

/**
 * Persist a validated receipt inside the caller's gallery creation transaction.
 *
 * The durable claim must already have been committed and its advisory lock
 * retained. The caller owns both commit and rollback; this write alone never
 * authorizes output or releases the operation lease.
 *
 * @param array{actor_id:int|numeric-string,key_hash:string,operation_name:string,payload_hash:string,owner_hash:string} $claim Owned pending ledger identity.
 * @param string $responseJson Validated bounded canonical completion JSON.
 * @param string $now UTC SQL timestamp.
 * @return void Writes the receipt in the existing transaction or throws on lost ownership.
 * @throws RuntimeException When no transaction exists or the pending claim changed.
 */
function admin_operation_model_complete_owned(
    array $claim,
    string $responseJson,
    string $now,
): void {
    if (!db()->inTransaction()) {
        throw new RuntimeException('Operation completion requires an owned transaction.');
    }
    $statement = db()->prepare(
        "UPDATE admin_operation_keys SET state = 'completed', response_json = ?, updated_at = ? WHERE actor_id = ? AND key_hash = ? AND operation_name = ? AND payload_hash = ? AND owner_hash = ? AND state = 'pending'",
    );
    $statement->execute([
        $responseJson,
        $now,
        $claim['actor_id'],
        $claim['key_hash'],
        $claim['operation_name'],
        $claim['payload_hash'],
        $claim['owner_hash'],
    ]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Operation completion ownership was lost.');
    }
}

/**
 * Keep ambiguous failure durable without replacing a completed response.
 *
 * @param array<string,mixed> $claim Owned claim identity.
 * @param string $now UTC SQL timestamp.
 * @return void
 */
function admin_operation_model_fail(array $claim, string $now): void
{
    $statement = db()->prepare(
        "UPDATE admin_operation_keys SET state = 'needs_reconciliation', updated_at = ? WHERE actor_id = ? AND key_hash = ? AND owner_hash = ? AND state = 'pending'",
    );
    $statement->execute([
        $now,
        $claim['actor_id'],
        $claim['key_hash'],
        $claim['owner_hash'],
    ]);
}

/**
 * Count retained states for explicit operator inspection, never a public page load.
 *
 * @return array<string,mixed> Aggregate state counts without private response contents.
 */
function admin_operation_model_pending_counts(): array
{
    return (
        db()
            ->query(
                "SELECT COALESCE(SUM(state = 'pending'), 0) AS pending, COALESCE(SUM(state = 'needs_reconciliation'), 0) AS needs_reconciliation, COALESCE(SUM(state NOT IN ('pending', 'needs_reconciliation', 'completed') AND NOT COALESCE("
                . admin_operation_model_linked_tombstone_sql()
                . ", 0)), 0) AS unknown FROM admin_operation_keys",
            )
            ->fetch() ?: []
    );
}

/**
 * Page unresolved claim metadata in exact actor/hash order without selecting responses.
 *
 * @param int $afterActor Cursor actor, or zero for the first page.
 * @param string $afterKeyHash Cursor key hash, or empty for the first page.
 * @return list<array<string,mixed>> Bounded metadata rows with one pagination lookahead.
 */
function admin_operation_model_pending_page(
    int $afterActor,
    string $afterKeyHash,
): array {
    $statement = db()->prepare(
        "SELECT actor_id, key_hash, operation_name, payload_hash, state, created_at, updated_at FROM admin_operation_keys WHERE state <> 'completed' AND NOT COALESCE("
            . admin_operation_model_linked_tombstone_sql()
            . ", 0) AND (actor_id > ? OR (actor_id = ? AND key_hash > ?)) ORDER BY actor_id, key_hash LIMIT "
            . (ADMIN_OPERATION_PENDING_PAGE_SIZE + 1),
    );
    $statement->execute([$afterActor, $afterActor, $afterKeyHash]);
    return $statement->fetchAll();
}
