<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/gallery_feature_plans.php
 * Module Type: Model
 * Purpose: Read bounded gallery feature snapshots and own atomic row-locking transactions.
 * Responsibilities: Keep feature-plan SQL and transaction boundaries out of HTTP/domain code.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Models;
use function Gallery\Core\db;

/** Read only non-secret hierarchy and requested feature state columns.
 * @param list<string> $features Verified semantic feature keys.
 * @param bool $lock Whether the caller owns an applying transaction.
 * @return list<array<string,mixed>> Stable non-secret catalog snapshot.
 */
function gallery_feature_plan_model_snapshot(array $features, bool $lock = false): array
{
    $columns = ['id', 'parent_id', 'title', 'folder_path', 'sort_order', 'edit_revision'];
    $featureColumns = ['maps' => 'gps_map_enabled', 'filenames' => 'show_filenames', 'voting' => 'voting_enabled', 'game' => 'picture_game_enabled'];
    foreach ($features as $feature) {
        if (!isset($featureColumns[$feature])) {
            throw new \InvalidArgumentException('Unsupported gallery feature.');
        }
        $columns[] = $featureColumns[$feature];
    }
    return db()->query('SELECT ' . implode(', ', array_unique($columns)) . ' FROM galleries ORDER BY id' . ($lock ? ' FOR UPDATE' : ''))->fetchAll();
}

/** Execute an exact-scope feature update while retaining row and insertion-gap locks until commit.
 * The supported MySQL connection uses REPEATABLE READ for this transaction only,
 * so a deployment default of READ COMMITTED cannot admit new descendants after
 * the complete catalog snapshot. This does not change the session/global default.
 * @template T
 * @param callable():T $operation Snapshot comparison and existing model writes.
 * @return T Committed callback result.
 */
function gallery_feature_plan_model_transaction(callable $operation): mixed
{
    $connection = db();
    if ($connection->inTransaction()) {
        throw new \RuntimeException('Gallery feature transaction already active.');
    }
    $connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection->beginTransaction();
    try {
        $result = $operation();
        $connection->commit();
        return $result;
    } catch (\Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $exception;
    }
}
