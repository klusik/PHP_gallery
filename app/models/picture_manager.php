<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/picture_manager.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database persistence for Picture Manager copy operations.
 *
 * Responsibilities:
 *   - Inspect the current images table columns used by compatibility copy rows
 *   - Insert copied image rows atomically
 *   - Copy image-tag assignments when the optional schema is available
 *   - Repair the destination gallery cover inside the same transaction
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Filesystem copying remains service-layer orchestration.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/** Return the current images table columns in database order. */
function picture_manager_model_image_table_columns(): array
{
    $rows = db()->query('SHOW COLUMNS FROM images')->fetchAll() ?: [];
    $columns = [];
    foreach ($rows as $row) {
        $field = (string) ($row['Field'] ?? '');
        if ($field !== '') {
            $columns[] = $field;
        }
    }
    return $columns;
}

/**
 * Persist copied image rows, optional tag assignments, and the destination cover atomically.
 *
 * @param int $destinationGalleryId Destination gallery identifier.
 * @param array<int,array<string,mixed>> $rowsBySourceImageId INSERT-ready image rows keyed by source image id.
 * @param bool $copyTags Whether image_tags persistence is verified and available.
 * @param array<int,int> $destinationBranchIds Gallery ids accepted by the existing cover.
 * @param string $now Shared SQL timestamp.
 * @return array{created_image_ids:array<int,int>,destination_cover_image_id:int|null}
 */
function picture_manager_model_copy_rows(
    int $destinationGalleryId,
    array $rowsBySourceImageId,
    bool $copyTags,
    array $destinationBranchIds,
    string $now
): array {
    if ($destinationGalleryId <= 0 || $rowsBySourceImageId === []) {
        return ['created_image_ids' => [], 'destination_cover_image_id' => null];
    }

    $allowedColumns = array_fill_keys(picture_manager_model_image_table_columns(), true);
    unset($allowedColumns['id']);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $createdImageIds = [];
        foreach ($rowsBySourceImageId as $sourceImageId => $row) {
            if (!is_array($row) || $row === []) {
                throw new RuntimeException('Picture Manager copy row is empty.');
            }
            $columns = array_keys($row);
            foreach ($columns as $column) {
                if (!is_string($column) || !isset($allowedColumns[$column]) || preg_match('/^[A-Za-z0-9_]+$/D', $column) !== 1) {
                    throw new RuntimeException('Picture Manager copy row contains an unsupported image column.');
                }
            }
            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $columnSql = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
            $stmt = $pdo->prepare('INSERT INTO images (' . $columnSql . ') VALUES (' . $placeholders . ')');
            $stmt->execute(array_values($row));
            $createdImageIds[(int) $sourceImageId] = (int) $pdo->lastInsertId();
        }

        if ($copyTags && $createdImageIds !== []) {
            $tagStmt = $pdo->prepare(
                'INSERT IGNORE INTO image_tags (image_id, tag_id) SELECT ?, tag_id FROM image_tags WHERE image_id = ?'
            );
            foreach ($createdImageIds as $sourceImageId => $destinationImageId) {
                $tagStmt->execute([$destinationImageId, $sourceImageId]);
            }
        }

        $coverStmt = $pdo->prepare('SELECT cover_image_id FROM galleries WHERE id = ?');
        $coverStmt->execute([$destinationGalleryId]);
        $coverRaw = $coverStmt->fetchColumn();
        $destinationCoverImageId = $coverRaw ? (int) $coverRaw : null;
        if (
            $destinationCoverImageId === null
            || !gallery_mutation_model_image_belongs_to_galleries($destinationCoverImageId, $destinationBranchIds)
        ) {
            $destinationCoverImageId = gallery_mutation_model_first_cover_candidate($destinationGalleryId, []);
        }

        $updateCover = $pdo->prepare('UPDATE galleries SET cover_image_id = ?, updated_at = ?, edit_revision = edit_revision + 1 WHERE id = ?');
        $updateCover->execute([$destinationCoverImageId, $now, $destinationGalleryId]);
        $pdo->commit();

        return [
            'created_image_ids' => $createdImageIds,
            'destination_cover_image_id' => $destinationCoverImageId,
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
