<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/cache_lifecycle_registry.php
 * Module Type: Model
 * Purpose: Own persistent cache-region scheduling and checkpoint SQL.
 * Responsibilities: Keep stable row identities, bound passive reads and update existing checkpoints without runtime DDL.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Models;

use PDO;
use function Gallery\Core\db;

/**
 * Describe a persisted region independently of driver-specific integer conversion.
 * @phpstan-type CacheRegistryRow array{region_key:string,relative_path:string,owner:string,kind:string,last_serviced_at:int|string,last_completed_at:int|string,next_due_at:int|string,retry_at:int|string,failures:int|string,drained:int|string,seen_generation:string,missing_since:int|string,checkpoint_json:string,finding_json:string,completed_json:string}
 * @phpstan-type CacheRegistryEvidence array{region_key:string,relative_path:string,owner:string,kind:string,last_serviced_at:int|string,last_completed_at:int|string,next_due_at:int|string,retry_at:int|string,failures:int|string,drained:int|string,missing_since:int|string,finding_json:string,completed_json:string}
 */

/**
 * Resolve the application connection or an explicitly isolated disposable database.
 * @return PDO Application connection; the fixture override is accepted only with an owned test root.
 */
function cache_registry_model_database(): PDO
{
    if (defined('CACHE_LIFECYCLE_TEST_ROOT') && ($GLOBALS['cache_lifecycle_test_database'] ?? null) instanceof PDO) {
        return $GLOBALS['cache_lifecycle_test_database'];
    }
    return db();
}

/**
 * Read one authoritative region row without interpreting its filesystem state.
 * @param string $key Stable SHA-256 region identity.
 * @return CacheRegistryRow|null Stored scheduling and serialized checkpoint fields, or null for a new identity.
 */
function cache_registry_model_find(string $key): ?array
{
    $query = cache_registry_model_database()->prepare('SELECT * FROM cache_lifecycle_regions WHERE region_key = ?');
    $query->execute([$key]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Register a discovered region or refresh its discovery generation without replacing work.
 * @param string $key Stable SHA-256 region identity.
 * @param string $path Safe relative region path or the internal discovery/root-files sentinel.
 * @param string $owner Registered producer category.
 * @param string $generation Completed discovery's unique generation.
 * @param int $now Current Unix seconds.
 * @param string $kind Internal discovery/files/history owner, or region for a filesystem path.
 * @return void Inserts once and subsequently updates discovery evidence while preserving checkpoints and findings.
 */
function cache_registry_model_register(string $key, string $path, string $owner, string $generation, int $now, string $kind = 'region'): void
{
    $database = cache_registry_model_database();
    // The installation-wide kernel worker lock serializes this insert/update pair.
    $old = cache_registry_model_find($key);
    if ($old === null) {
        $query = $database->prepare('INSERT INTO cache_lifecycle_regions (region_key, relative_path, owner, kind, next_due_at, seen_generation, checkpoint_json, finding_json, completed_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $query->execute([$key, $path, $owner, $kind, $now, $generation, '{}', '{}', '{}']);
    } else {
        $query = $database->prepare('UPDATE cache_lifecycle_regions SET next_due_at = CASE WHEN missing_since > 0 THEN ? ELSE next_due_at END, seen_generation = ?, missing_since = 0 WHERE region_key = ?');
        $query->execute([$now, $generation, $key]);
    }
}

/**
 * Select the oldest due region, with recently serviced partial regions yielded to other due work.
 * @param int $now Current Unix seconds.
 * @param bool $force Whether the maintainer bypasses the due timestamp; retry backoff and missing-region protection still apply.
 * @return CacheRegistryRow|null Oldest due and retry-admitted row, or null when no work is due.
 */
function cache_registry_model_due(int $now, bool $force = false): ?array
{
    $query = cache_registry_model_database()->prepare("SELECT * FROM cache_lifecycle_regions WHERE (next_due_at <= ? OR ? = 1) AND retry_at <= ? AND missing_since = 0 AND kind <> 'history' ORDER BY next_due_at, last_completed_at, last_serviced_at, region_key LIMIT 1");
    // Preserve numeric literal comparisons on SQLite as well as MySQL/MariaDB;
    // execute(array) may bind the force placeholder as a string.
    $query->bindValue(1, $now, PDO::PARAM_INT);
    $query->bindValue(2, $force ? 1 : 0, PDO::PARAM_INT);
    $query->bindValue(3, $now, PDO::PARAM_INT);
    $query->execute();
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/**
 * Commit a region checkpoint and its service-prepared scheduling/finding metadata together.
 * @param string $key Existing region identity held by the worker.
 * @param array{checkpoint_json:string,finding_json:string,completed_json:string,last_serviced_at:int,last_completed_at:int,next_due_at:int,retry_at:int,failures:int,drained:int} $record Serialized authoritative state and indexed timestamps.
 * @param string|null $history Updated installation-wide removal receipt JSON, or null when no removal delta exists.
 * @return void Atomically commits the checkpoint and optional retained removal receipts; failures roll back both.
 */
function cache_registry_model_save(string $key, array $record, ?string $history = null): void
{
    $database = cache_registry_model_database();
    $database->beginTransaction();
    try {
        $query = $database->prepare('UPDATE cache_lifecycle_regions SET checkpoint_json = ?, finding_json = ?, completed_json = ?, last_serviced_at = ?, last_completed_at = ?, next_due_at = ?, retry_at = ?, failures = ?, drained = ? WHERE region_key = ?');
        $query->execute([$record['checkpoint_json'], $record['finding_json'], $record['completed_json'], $record['last_serviced_at'],
            $record['last_completed_at'], $record['next_due_at'], $record['retry_at'], $record['failures'], $record['drained'], $key]);
        if ($history !== null) {
            $receipt = $database->prepare("UPDATE cache_lifecycle_regions SET finding_json = ? WHERE kind = 'history'");
            $receipt->execute([$history]);
        }
        $database->commit();
    } catch (\Throwable $exception) {
        if ($database->inTransaction()) $database->rollBack();
        throw $exception;
    }
}

/**
 * Return bounded passive evidence without fetching traversal stacks or catalogs.
 * @param int $limit Maximum admitted region rows plus one overflow indicator.
 * @return list<CacheRegistryEvidence> Region paths, indexed timing and compact findings; no filesystem I/O is performed.
 */
function cache_registry_model_summaries(int $limit): array
{
    $query = cache_registry_model_database()->prepare('SELECT region_key, relative_path, owner, kind, last_serviced_at, last_completed_at, next_due_at, retry_at, failures, drained, missing_since, finding_json, completed_json FROM cache_lifecycle_regions ORDER BY region_key LIMIT ?');
    $query->bindValue(1, $limit + 1, PDO::PARAM_INT);
    $query->execute();
    return $query->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mark absence only after a complete readable root discovery; pending work remains runnable.
 * @param string $generation Completed root catalog generation.
 * @param int $now Current Unix seconds.
 * @return void Records tombstone timing for unseen drained regions; no payload or checkpoint is deleted.
 */
function cache_registry_model_mark_missing(string $generation, int $now): void
{
    $query = cache_registry_model_database()->prepare("UPDATE cache_lifecycle_regions SET missing_since = ? WHERE seen_generation <> ? AND missing_since = 0 AND kind = 'region' AND drained = 1");
    $query->execute([$now, $generation]);
}

/**
 * Retire one old drained row after its private journals have been safely retired.
 * @param string $key Exact region admitted and retired by the filesystem owner.
 * @param int $before Retained tombstone boundary in Unix seconds.
 * @return void Removes only the still-drained absent identity; no payload or other region is affected.
 */
function cache_registry_model_prune(string $key, int $before): void
{
    $query = cache_registry_model_database()->prepare('DELETE FROM cache_lifecycle_regions WHERE region_key = ? AND drained = 1 AND missing_since > 0 AND missing_since <= ?');
    $query->execute([$key, $before]);
}

/**
 * Retire the transition-only whole-cache display snapshot while preserving actual removal receipts.
 * @param string $history Serialized existing receipt record with only the legacy display fields removed.
 * @return void Updates the fixed history identity; normal observation/deletion state remains untouched.
 */
function cache_registry_model_retire_legacy(string $history): void
{
    $query = cache_registry_model_database()->prepare("UPDATE cache_lifecycle_regions SET finding_json = ? WHERE kind = 'history'");
    $query->execute([$history]);
}
