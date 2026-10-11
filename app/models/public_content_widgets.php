<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/public_content_widgets.php
 * Module Type: Model
 * Purpose: Persist administrator-authored public widget rows with revision-safe writes.
 * Responsibilities:
 *   - Keep widget SQL, row selection and transaction ownership in the model layer.
 *   - Reject stale edits/deletes and transactional reorder conflicts.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

use RuntimeException;
use function Gallery\Core\db;

/**
 * Read a bounded deterministic list of widget rows, optionally published only.
 *
 * @param bool $publishedOnly Whether to exclude every nonpublished row in SQL.
 * @return list<array<string,mixed>> Stored rows ordered by administrator position and stable ID.
 */
function public_widget_model_list(bool $publishedOnly = false): array
{
    $sql = 'SELECT widget_id, title, content_md, status, page_scope, placement_mode, flow_slot, floating_anchor, x_permille, y_permille, width_px, mobile_fallback, appearance, sort_order, source_language, revision, created_at, updated_at FROM public_content_widgets';
    if ($publishedOnly) {
        $sql .= " WHERE status = 'published'";
    }
    $sql .= ' ORDER BY sort_order ASC, widget_id ASC LIMIT 49';
    $statement = db()->query($sql);
    if ($statement === false) {
        throw new RuntimeException('Widget storage could not be read.');
    }
    return $statement->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

/**
 * Read exactly one widget for authorized administration and concurrency checks.
 *
 * @param string $widgetId Validated lowercase hexadecimal stable identifier.
 * @return array<string,mixed>|null Stored row or null when absent.
 */
function public_widget_model_find(string $widgetId): ?array
{
    $statement = db()->prepare('SELECT widget_id, title, content_md, status, page_scope, placement_mode, flow_slot, floating_anchor, x_permille, y_permille, width_px, mobile_fallback, appearance, sort_order, source_language, revision, created_at, updated_at FROM public_content_widgets WHERE widget_id = ? LIMIT 1');
    $statement->execute([$widgetId]);
    $row = $statement->fetch(\PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/**
 * Insert a normalized independent widget, rejecting an exceeded per-site limit.
 *
 * The locking read serializes admitting inserts under InnoDB's transaction locking.
 *
 * @param array<string,int|string> $row Complete validated widget state, including a new ID and revision 1.
 * @param string $now UTC SQL timestamp for insertion and modification.
 * @param int $limit Positive maximum number of stored widgets.
 * @return void Commits exactly one new row or throws without a partial write.
 */
function public_widget_model_insert(array $row, string $now, int $limit): void
{
    $pdo = db();
    if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
        throw new RuntimeException('Widget creation requires its own transaction.');
    }
    try {
        $statement = $pdo->query('SELECT widget_id FROM public_content_widgets ORDER BY widget_id FOR UPDATE');
        if ($statement === false) {
            throw new RuntimeException('Widget capacity could not be checked.');
        }
        if (count($statement->fetchAll(\PDO::FETCH_COLUMN)) >= $limit) {
            throw new RuntimeException('Widget limit reached.');
        }
        $columns = ['widget_id', 'title', 'content_md', 'status', 'page_scope', 'placement_mode', 'flow_slot', 'floating_anchor', 'x_permille', 'y_permille', 'width_px', 'mobile_fallback', 'appearance', 'sort_order', 'source_language', 'revision'];
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $sql = 'INSERT INTO public_content_widgets (' . implode(', ', $columns) . ', created_at, updated_at) VALUES (' . $placeholders . ', ?, ?)';
        $values = array_map(static fn (string $field): int|string => $row[$field], $columns);
        $statement = $pdo->prepare($sql);
        $statement->execute([...$values, $now, $now]);
        if (!$pdo->commit()) {
            throw new RuntimeException('Widget creation did not commit.');
        }
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Atomically replace widget content/placement only when its revision is current.
 *
 * @param array<string,int|string> $row Complete validated replacement including widget_id and expected revision.
 * @param string $now UTC SQL modification timestamp.
 * @return bool True for an applied update; false for a stale or missing widget.
 */
function public_widget_model_replace(array $row, string $now): bool
{
    $columns = ['title', 'content_md', 'status', 'page_scope', 'placement_mode', 'flow_slot', 'floating_anchor', 'x_permille', 'y_permille', 'width_px', 'mobile_fallback', 'appearance', 'sort_order', 'source_language'];
    $assignments = implode(', ', array_map(static fn (string $field): string => $field . ' = ?', $columns));
    $statement = db()->prepare('UPDATE public_content_widgets SET ' . $assignments . ', revision = revision + 1, updated_at = ? WHERE widget_id = ? AND revision = ?');
    $values = array_map(static fn (string $field): int|string => $row[$field], $columns);
    $statement->execute([...$values, $now, $row['widget_id'], $row['revision']]);
    return $statement->rowCount() === 1;
}

/**
 * Remove one widget only when its supplied optimistic revision is current.
 *
 * @param string $widgetId Validated stable widget ID.
 * @param int $revision Expected positive revision supplied by the editor.
 * @return bool True only if exactly one matching record was deleted.
 */
function public_widget_model_delete(string $widgetId, int $revision): bool
{
    $statement = db()->prepare('DELETE FROM public_content_widgets WHERE widget_id = ? AND revision = ?');
    $statement->execute([$widgetId, $revision]);
    return $statement->rowCount() === 1;
}

/**
 * Apply a complete position change in one transaction with individual revision guards.
 *
 * @param list<array{widget_id:string,revision:int,sort_order:int}> $changes Validated identity, current revision and desired order for every changed row.
 * @param string $now UTC SQL modification timestamp.
 * @return bool True after commit, or false when any row is missing/stale and all writes are rolled back.
 */
function public_widget_model_reorder(array $changes, string $now): bool
{
    $pdo = db();
    if ($pdo->inTransaction() || !$pdo->beginTransaction()) {
        throw new RuntimeException('Widget ordering requires its own transaction.');
    }
    try {
        $statement = $pdo->prepare('UPDATE public_content_widgets SET sort_order = ?, revision = revision + 1, updated_at = ? WHERE widget_id = ? AND revision = ?');
        foreach ($changes as $change) {
            $statement->execute([$change['sort_order'], $now, $change['widget_id'], $change['revision']]);
            if ($statement->rowCount() !== 1) {
                $pdo->rollBack();
                return false;
            }
        }
        if (!$pdo->commit()) {
            throw new RuntimeException('Widget ordering did not commit.');
        }
        return true;
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
