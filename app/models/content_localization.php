<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/content_localization.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence for authored gallery and image translations.
 *
 * Responsibilities:
 *   - Load translation rows for semantic gallery/image entity types
 *   - Persist source-language metadata and translated title/description rows atomically
 *   - Keep dynamic table and owner-column selection inside a fixed model allowlist
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Callers pass semantic entity types, never SQL identifiers or fragments.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use InvalidArgumentException;
use Throwable;
use function Gallery\Core\db;

/** Return fixed storage metadata for one semantic localized entity type. */
function content_localization_model_storage(string $entityType): array
{
    return match ($entityType) {
        'gallery' => ['table' => 'gallery_translations', 'owner_column' => 'gallery_id', 'base_table' => 'galleries'],
        'image' => ['table' => 'image_translations', 'owner_column' => 'image_id', 'base_table' => 'images'],
        default => throw new InvalidArgumentException('Unsupported localized content entity type.'),
    };
}

/** Load translation rows for one semantic entity type and optional language. */
function content_localization_model_rows(string $entityType, array $entityIds, ?string $language): array
{
    $storage = content_localization_model_storage($entityType);
    $ids = array_values(array_unique(array_filter(array_map('intval', $entityIds), static fn (int $id): bool => $id > 0)));
    if ($ids === []) return [];
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $sql = 'SELECT ' . $storage['owner_column'] . ' AS owner_id, language_code, title, description, created_at, updated_at FROM ' . $storage['table'] . ' WHERE ' . $storage['owner_column'] . ' IN (' . $marks . ')';
    $params = $ids;
    if ($language !== null) {
        $sql .= ' AND language_code = ?';
        $params[] = $language;
    }
    $sql .= ' ORDER BY ' . $storage['owner_column'] . ', language_code';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Atomically persist source language plus normalized translation rows.
 *
 * @param string $entityType gallery or image.
 * @param int $entityId Owning entity identifier.
 * @param ?string $sourceLanguage Canonical source language.
 * @param array<string,array{title:?string,description:?string}|null> $translations Normalized rows keyed by language; null deletes a row.
 * @param string $now Shared SQL timestamp.
 */
function content_localization_model_save(string $entityType, int $entityId, ?string $sourceLanguage, array $translations, string $now): void
{
    $storage = content_localization_model_storage($entityType);
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $sourceStmt = $pdo->prepare('UPDATE ' . $storage['base_table'] . ' SET content_language = ?, updated_at = ? WHERE id = ?');
        $sourceStmt->execute([$sourceLanguage, $now, $entityId]);
        $deleteStmt = $pdo->prepare('DELETE FROM ' . $storage['table'] . ' WHERE ' . $storage['owner_column'] . ' = ? AND language_code = ?');
        $upsertStmt = $pdo->prepare('INSERT INTO ' . $storage['table'] . ' (' . $storage['owner_column'] . ', language_code, title, description, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE title = VALUES(title), description = VALUES(description), updated_at = VALUES(updated_at)');
        foreach ($translations as $language => $row) {
            if ($row === null) {
                $deleteStmt->execute([$entityId, $language]);
                continue;
            }
            $upsertStmt->execute([$entityId, $language, $row['title'], $row['description'], $now, $now]);
        }
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}
