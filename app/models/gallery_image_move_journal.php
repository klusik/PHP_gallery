<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Store journal stages and the ownership-transaction commit marker.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/gallery_image_move_journal.php
 * Module Type: Model
 * Purpose: Persist move intent and serialize competing image-move workers.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

use RuntimeException;
use Throwable;
use function Gallery\Core\db;
use const Gallery\Core\IMAGE_MOVE_DIAGNOSTIC_LIMIT;
use const Gallery\Core\IMAGE_MOVE_PREPARED;
use const Gallery\Core\IMAGE_MOVE_MOVING;
use const Gallery\Core\IMAGE_MOVE_DB_COMMITTED;
use const Gallery\Core\IMAGE_MOVE_FINALIZED;
use const Gallery\Core\IMAGE_MOVE_ROLLED_BACK;
use const Gallery\Core\IMAGE_MOVE_NEEDS_RECONCILIATION;

require_once dirname(__DIR__) . '/policy_constants.php';

/**
 * Acquire deterministic non-waiting connection locks for both galleries.
 *
 * MySQL releases these if the process/connection dies. They serialize journal
 * recovery and image moves across sessions, not unrelated filesystem operators.
 *
 * @param array<int,int> $galleryIds Positive source/destination identifiers.
 * @return array<int,string> Acquired lock names; release in a finally block.
 */
function gallery_image_move_model_lock(array $galleryIds): array
{
    $ids = array_values(array_unique(array_map('intval', $galleryIds)));
    sort($ids, SORT_NUMERIC);
    $locks = [];
    $databaseName = (string) db()->query('SELECT DATABASE()')->fetchColumn();
    try {
        foreach ($ids as $id) {
            if ($id <= 0) {
                throw new RuntimeException('Invalid gallery move scope.');
            }
            $name = hash('sha256', $databaseName . ':gallery_image_move:' . $id);
            $stmt = db()->prepare('SELECT GET_LOCK(?, 0)');
            $stmt->execute([$name]);
            if ((int) $stmt->fetchColumn() !== 1) {
                throw new RuntimeException('Another image move or recovery is using this gallery.');
            }
            $locks[] = $name;
        }
        return $locks;
    } catch (Throwable $exception) {
        gallery_image_move_model_unlock($locks);
        throw $exception;
    }
}

/**
 * Refuse a new move while either gallery has unresolved durable work.
 *
 * @param int $sourceId Requested source gallery.
 * @param int $destinationId Requested destination gallery.
 * @return void
 */
function gallery_image_move_model_assert_idle(int $sourceId, int $destinationId): void
{
    $stmt = db()->prepare('SELECT operation_id FROM gallery_image_move_journal WHERE state NOT IN (?, ?) AND (source_gallery_id IN (?, ?) OR destination_gallery_id IN (?, ?)) LIMIT 1');
    $stmt->execute([IMAGE_MOVE_FINALIZED, IMAGE_MOVE_ROLLED_BACK, $sourceId, $destinationId, $sourceId, $destinationId]);
    if ($stmt->fetchColumn() !== false) {
        throw new RuntimeException('An earlier image move needs reconciliation before another move can use this gallery.');
    }
}

/**
 * Release only this worker's acquired connection locks.
 *
 * @param array<int,string> $locks Names returned by gallery_image_move_model_lock().
 * @return void
 */
function gallery_image_move_model_unlock(array $locks): void
{
    foreach (array_reverse($locks) as $name) {
        $stmt = db()->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$name]);
    }
}

/**
 * Persist the full intent before the first original or derivative is touched.
 *
 * @param string $operationId Random operation identifier.
 * @param int $sourceId Source gallery identifier.
 * @param int $destinationId Destination gallery identifier.
 * @param string $manifestJson Versioned, validated relative paths and file identities.
 * @param string $now SQL timestamp.
 * @return void
 */
function gallery_image_move_model_prepare(string $operationId, int $sourceId, int $destinationId, string $manifestJson, string $now): void
{
    $stmt = db()->prepare('INSERT INTO gallery_image_move_journal (operation_id, source_gallery_id, destination_gallery_id, state, manifest_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$operationId, $sourceId, $destinationId, IMAGE_MOVE_PREPARED, $manifestJson, $now, $now]);
}

/**
 * Read one durable operation for recovery without loading the pending queue.
 *
 * @param string $operationId Exact operation identifier.
 * @return array<string,mixed>|null Persisted journal row, or null when absent.
 */
function gallery_image_move_model_find(string $operationId): ?array
{
    $stmt = db()->prepare('SELECT * FROM gallery_image_move_journal WHERE operation_id = ?');
    $stmt->execute([$operationId]);
    return $stmt->fetch() ?: null;
}

/**
 * Return bounded safe operator diagnostics without manifest paths or image hashes.
 *
 * @return array<int,array<string,mixed>> Pending identifiers, scope, state and timestamps.
 */
function gallery_image_move_model_pending(): array
{
    $stmt = db()->prepare('SELECT operation_id, source_gallery_id, destination_gallery_id, state, database_committed, last_error_code, updated_at FROM gallery_image_move_journal WHERE state NOT IN (?, ?) ORDER BY updated_at, operation_id LIMIT ' . IMAGE_MOVE_DIAGNOSTIC_LIMIT);
    $stmt->execute([IMAGE_MOVE_FINALIZED, IMAGE_MOVE_ROLLED_BACK]);
    return $stmt->fetchAll() ?: [];
}

/**
 * Update a non-commit lifecycle state; preserve the immutable ownership commit marker.
 *
 * @param string $operationId Exact operation identifier.
 * @param string $state Allowlisted non-commit lifecycle state.
 * @param string $now SQL timestamp.
 * @param ?string $errorCode Safe bounded category, never an exception message or path.
 * @return void
 */
function gallery_image_move_model_state(string $operationId, string $state, string $now, ?string $errorCode = null): void
{
    if (!in_array($state, [IMAGE_MOVE_MOVING, IMAGE_MOVE_FINALIZED, IMAGE_MOVE_ROLLED_BACK, IMAGE_MOVE_NEEDS_RECONCILIATION], true)
        || ($errorCode !== null && !preg_match('/^[a-z_]+$/D', $errorCode))) {
        throw new RuntimeException('Invalid image move journal transition.');
    }
    $stmt = db()->prepare('UPDATE gallery_image_move_journal SET state = ?, last_error_code = ?, updated_at = ? WHERE operation_id = ?');
    $stmt->execute([$state, $errorCode, $now, $operationId]);
}

/**
 * Mark committed inside the same transaction that updates image ownership.
 *
 * @param string $operationId Prepared operation identifier.
 * @param int $sourceId Expected source gallery identifier.
 * @param int $destinationId Expected destination gallery identifier.
 * @param string $now SQL timestamp.
 * @return void
 */
function gallery_image_move_model_commit_marker(string $operationId, int $sourceId, int $destinationId, string $now): void
{
    if (!db()->inTransaction()) {
        throw new RuntimeException('Image move commit marker requires its ownership transaction.');
    }
    $stmt = db()->prepare('UPDATE gallery_image_move_journal SET state = ?, database_committed = 1, updated_at = ? WHERE operation_id = ? AND source_gallery_id = ? AND destination_gallery_id = ? AND state = ? AND database_committed = 0');
    $stmt->execute([IMAGE_MOVE_DB_COMMITTED, $now, $operationId, $sourceId, $destinationId, IMAGE_MOVE_MOVING]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('Image move journal no longer owns this transaction.');
    }
}
