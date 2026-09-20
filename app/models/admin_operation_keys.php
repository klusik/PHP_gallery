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
        $statement = db()->prepare('SELECT COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE, SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX LIMIT ' . (count(ADMIN_OPERATION_PRIMARY_COLUMNS) + 1));
        if (!$statement->execute(['admin_operation_keys', 'PRIMARY'])) {
            return 'unknown';
        }
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        if (count($rows) !== count(ADMIN_OPERATION_PRIMARY_COLUMNS)) {
            return 'missing';
        }
        foreach (ADMIN_OPERATION_PRIMARY_COLUMNS as $offset => $column) {
            $row = $rows[$offset];
            foreach (['COLUMN_NAME', 'SEQ_IN_INDEX', 'NON_UNIQUE', 'SUB_PART'] as $field) {
                if (!array_key_exists($field, $row)) {
                    return 'unknown';
                }
            }
            if ($row['COLUMN_NAME'] !== $column
                || !in_array($row['SEQ_IN_INDEX'], [$offset + 1, (string) ($offset + 1)], true)
                || !in_array($row['NON_UNIQUE'], [0, '0'], true)
                || $row['SUB_PART'] !== null) {
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
        throw new RuntimeException('Operation claims require an independent durable commit.');
    }
    $databaseName = (string) db()->query('SELECT DATABASE()')->fetchColumn();
    $name = hash('sha256', $databaseName . ':admin_operation:' . $actorId . ':' . $keyHash);
    $statement = db()->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([$name]);
    $result = $statement->fetchColumn();
    if ($result === null || $result === false) {
        throw new RuntimeException('Operation ownership could not be verified.');
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
function admin_operation_model_claim(int $actorId, string $keyHash, string $operation, string $payloadHash, string $ownerHash, string $now): array
{
    if (db()->inTransaction()) {
        throw new RuntimeException('Operation claim cannot join a target transaction.');
    }
    $statement = db()->prepare("INSERT INTO admin_operation_keys (actor_id, key_hash, operation_name, payload_hash, owner_hash, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?) ON DUPLICATE KEY UPDATE key_hash = key_hash");
    $statement->execute([$actorId, $keyHash, $operation, $payloadHash, $ownerHash, $now, $now]);
    return admin_operation_model_find($actorId, $keyHash) ?? throw new RuntimeException('Operation claim could not be read.');
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
    $statement = db()->prepare('SELECT actor_id, key_hash, operation_name, payload_hash, owner_hash, state, response_json, created_at, updated_at FROM admin_operation_keys WHERE actor_id = ? AND key_hash = ?');
    $statement->execute([$actorId, $keyHash]);
    return $statement->fetch() ?: null;
}

/**
 * Durably complete an owned pending/reconciled claim before HTTP output.
 *
 * @param array<string,mixed> $claim Original claim identity and worker ownership.
 * @param string $responseJson Bounded credential-free canonical response.
 * @param string $now UTC SQL timestamp.
 * @return void
 */
function admin_operation_model_complete(array $claim, string $responseJson, string $now): void
{
    if (db()->inTransaction()) {
        throw new RuntimeException('Operation response must commit before output.');
    }
    $statement = db()->prepare("UPDATE admin_operation_keys SET state = 'completed', response_json = ?, updated_at = ? WHERE actor_id = ? AND key_hash = ? AND operation_name = ? AND payload_hash = ? AND owner_hash = ? AND state IN ('pending', 'needs_reconciliation')");
    $statement->execute([$responseJson, $now, $claim['actor_id'], $claim['key_hash'], $claim['operation_name'], $claim['payload_hash'], $claim['owner_hash']]);
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
    $statement = db()->prepare("UPDATE admin_operation_keys SET state = 'needs_reconciliation', updated_at = ? WHERE actor_id = ? AND key_hash = ? AND owner_hash = ? AND state = 'pending'");
    $statement->execute([$now, $claim['actor_id'], $claim['key_hash'], $claim['owner_hash']]);
}

/**
 * Count retained states for explicit operator inspection, never a public page load.
 *
 * @return array<string,mixed> Aggregate state counts without private response contents.
 */
function admin_operation_model_pending_counts(): array
{
    return db()->query("SELECT COALESCE(SUM(state = 'pending'), 0) AS pending, COALESCE(SUM(state = 'needs_reconciliation'), 0) AS needs_reconciliation, COALESCE(SUM(state NOT IN ('pending', 'needs_reconciliation', 'completed')), 0) AS unknown FROM admin_operation_keys")->fetch() ?: [];
}

/**
 * Page unresolved claim metadata in exact actor/hash order without selecting responses.
 *
 * @param int $afterActor Cursor actor, or zero for the first page.
 * @param string $afterKeyHash Cursor key hash, or empty for the first page.
 * @return list<array<string,mixed>> Bounded metadata rows with one pagination lookahead.
 */
function admin_operation_model_pending_page(int $afterActor, string $afterKeyHash): array
{
    $statement = db()->prepare("SELECT actor_id, key_hash, operation_name, payload_hash, state, created_at, updated_at FROM admin_operation_keys WHERE state <> 'completed' AND (actor_id > ? OR (actor_id = ? AND key_hash > ?)) ORDER BY actor_id, key_hash LIMIT " . (ADMIN_OPERATION_PENDING_PAGE_SIZE + 1));
    $statement->execute([$afterActor, $afterActor, $afterKeyHash]);
    return $statement->fetchAll();
}
