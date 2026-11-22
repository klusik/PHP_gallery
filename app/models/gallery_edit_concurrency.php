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
use PDO;
use function Gallery\Core\db;
use const Gallery\Core\GALLERY_EDIT_LOCK_SUFFIX;
use const Gallery\Core\GALLERY_EDIT_LOCK_WAIT_SECONDS;

require_once dirname(__DIR__) . '/policy_constants.php';

/**
 * Name the singleton revision of application-owned gallery catalogue writes.
 * Type: string. Units: app-settings key. Scope: one installation database.
 * Consumers: gallery writer leases and Admin media-renamer confirmations.
 * Rationale: the keyed row provides a constant-size revision read even for
 * galleries with thousands of images.
 */
const GALLERY_EDIT_CATALOGUE_REVISION_KEY = 'gallery_writer_catalogue_revision';

/**
 * Size the opaque revision used to invalidate transient rename proposals.
 * Type: int. Units: random bytes. Scope: one gallery writer generation.
 * Consumers: catalogue revision initialization and invalidation.
 * Rationale: 16 bytes prevent reuse across independently initialized databases
 * or ordinary concurrent writer operations without relying on timestamp precision.
 */
const GALLERY_EDIT_CATALOGUE_REVISION_BYTES = 16;

/**
 * Read the current catalogue generation while holding the gallery writer lock.
 *
 * @param bool $initialize Create the marker on first confirmed analysis only.
 * @return string|null Current opaque revision, or null if never initialized.
 * @throws RuntimeException When the stored revision cannot be trusted.
 */
function gallery_edit_model_catalogue_revision(bool $initialize = false): ?string
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([GALLERY_EDIT_CATALOGUE_REVISION_KEY]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        if (!$initialize) {
            return null;
        }
        $revision = bin2hex(random_bytes(GALLERY_EDIT_CATALOGUE_REVISION_BYTES));
        $pdo->prepare(
            'INSERT INTO app_settings (setting_key, setting_value, updated_at) '
            . 'VALUES (?, ?, CURRENT_TIMESTAMP)',
        )->execute([GALLERY_EDIT_CATALOGUE_REVISION_KEY, $revision]);
        return $revision;
    }
    if (!is_string($value) || preg_match('/\A[a-f0-9]{32}\z/', $value) !== 1) {
        throw new RuntimeException('Gallery catalogue revision is unavailable.');
    }
    return $value;
}

/**
 * Invalidate existing catalogue proposals before entering a gallery writer.
 *
 * @param PDO|null $pdo Explicit owning connection in an editor transaction,
 *     or null for the currently configured writer connection.
 * @return void Advances an initialized marker on that connection; a missing row
 *     means this installation has never allocated a confirmation plan.
 */
function gallery_edit_model_invalidate_catalogue_revision(?PDO $pdo = null): void
{
    $pdo ??= db();
    $pdo->prepare(
        'UPDATE app_settings SET setting_value = ?, updated_at = CURRENT_TIMESTAMP '
        . 'WHERE setting_key = ?',
    )->execute([
        bin2hex(random_bytes(GALLERY_EDIT_CATALOGUE_REVISION_BYTES)),
        GALLERY_EDIT_CATALOGUE_REVISION_KEY,
    ]);
}

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
function gallery_edit_model_reserve(
    int $galleryId,
    string $expectedRevision,
): array {
    $pdo = db();
    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Gallery edit reservation requires an independent commit.',
        );
    }
    $name = gallery_edit_model_lock();
    if ($name === null) {
        return ['state' => 'busy', 'lock' => null, 'gallery' => null];
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'SELECT * FROM galleries WHERE id = ? FOR UPDATE',
        );
        $stmt->execute([$galleryId]);
        $gallery = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        if (
            $gallery === null
            || (string) $gallery['edit_revision'] !== $expectedRevision
        ) {
            $pdo->rollBack();
            gallery_edit_model_release($name);
            return [
                'state' => 'conflict',
                'lock' => null,
                'gallery' => $gallery,
            ];
        }
        // Explicitly reserve the next revision before any later folder, asset,
        // or sidecar work. No database trigger or elevated privilege is needed.
        $stmt = $pdo->prepare(
            'UPDATE galleries SET edit_revision = edit_revision + 1 WHERE id = ? AND edit_revision = ?',
        );
        $stmt->execute([$galleryId, $expectedRevision]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Gallery edit revision reservation failed.',
            );
        }
        gallery_edit_model_invalidate_catalogue_revision($pdo);
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
 * @param PDO|null $pdo Explicit migration/installer connection; null uses the configured application connection.
 * @return string|null Owned lock name, or null when another connection owns it.
 * @throws RuntimeException When ownership cannot be observed.
 */
function gallery_edit_model_lock(?PDO $pdo = null): ?string
{
    $pdo ??= db();
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
 * @param PDO|null $pdo Connection that acquired the lock; null uses the configured application connection.
 * @return void Releases one acquisition on the owning connection.
 */
function gallery_edit_model_release(string $lockName, ?PDO $pdo = null): void
{
    $pdo ??= db();
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
    $stmt->execute([$lockName]);
}
