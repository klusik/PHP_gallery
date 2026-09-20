<?php
/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Read revision state and acquire/release the established edit operation lock.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/gallery_edit_concurrency.php
 * Module Type: Model
 * Purpose: Reserve gallery revisions durably and exclude competing database writers during an editor operation.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

use RuntimeException;
use Throwable;
use function Gallery\Core\db;
use const Gallery\Core\GALLERY_EDIT_LOCK_SUFFIX;
use const Gallery\Core\GALLERY_EDIT_LOCK_WAIT_SECONDS;

require_once dirname(__DIR__) . '/policy_constants.php';

/**
 * Acquire a connection-owned operation lock and durably reserve an exact submitted revision.
 *
 * The named lock serializes supported application writers. Only this short
 * reservation uses a transaction; all filesystem work starts after its commit.
 * The named lock survives later nested use-case transactions.
 *
 * @param int $galleryId Positive gallery identity.
 * @param string $expectedRevision Decimal revision from the exact rendered row.
 * @return array{state:string,lock:?string,gallery:?array<string,mixed>} Internal result; gallery contains private persistence fields.
 * @throws Throwable On database uncertainty, with owned locks/transactions cleaned up.
 */
function gallery_edit_model_reserve(int $galleryId, string $expectedRevision): array
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Gallery edit reservation requires an independent commit.');
    }
    $name = gallery_edit_model_lock();
    if ($name === null) {
        return ['state' => 'busy', 'lock' => null, 'gallery' => null];
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT * FROM galleries WHERE id = ? FOR UPDATE');
        $stmt->execute([$galleryId]);
        $gallery = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if ($gallery === null || (string) $gallery['edit_revision'] !== $expectedRevision) {
            $pdo->rollBack();
            gallery_edit_model_release($name);
            return ['state' => 'conflict', 'lock' => null, 'gallery' => $gallery];
        }
        // Explicitly reserve the next revision before any later folder, asset,
        // or sidecar work. No database trigger or elevated privilege is needed.
        $stmt = $pdo->prepare('UPDATE galleries SET edit_revision = edit_revision + 1 WHERE id = ? AND edit_revision = ?');
        $stmt->execute([$galleryId, $expectedRevision]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Gallery edit revision reservation failed.');
        }
        $pdo->commit();
        return ['state' => 'acquired', 'lock' => $name, 'gallery' => $gallery];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        gallery_edit_model_release($name);
        throw $exception;
    }
}

/**
 * Acquire the common lock before a gallery writer performs filesystem work.
 *
 * Reentrant acquisition on the same connection is paired with one release per
 * acquisition. Callers must acquire before starting their own transactions.
 *
 * @return string|null Owned lock name, or null when another connection owns it.
 * @throws RuntimeException When ownership cannot be observed.
 */
function gallery_edit_model_lock(): ?string
{
    $pdo = db();
    $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $name = hash('sha256', $databaseName . GALLERY_EDIT_LOCK_SUFFIX);
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
    $stmt->execute([$name, GALLERY_EDIT_LOCK_WAIT_SECONDS]);
    $acquired = $stmt->fetchColumn();
    if ($acquired === null || $acquired === false) {
        throw new RuntimeException('Gallery edit ownership is unavailable.');
    }
    return (int) $acquired === 1 ? $name : null;
}

/**
 * Release the exact connection-owned lock after every editor side effect has finished.
 *
 * @param string $lockName Owned opaque advisory-lock name returned by reserve.
 * @return void
 */
function gallery_edit_model_release(string $lockName): void
{
    $stmt = db()->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute([$lockName]);
}
