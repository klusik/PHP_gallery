<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/votes.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence for image vote scores and viewer vote state.
 *
 * Responsibilities:
 *   - Read aggregate vote scores
 *   - Read one or many votes for an identified user or anonymous visitor
 *   - Delete and upsert vote rows without exposing SQL to services/controllers
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
 *   - Actor identity is supplied semantically by the service layer.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDOException;
use function Gallery\Core\db;

/**
 * Sum all persisted votes for an image.
 *
 * @param int $imageId Image identifier.
 * @return int Aggregate vote score.
 */
function vote_model_score(int $imageId): int
{
    $stmt = db()->prepare('SELECT COALESCE(SUM(vote), 0) FROM image_votes WHERE image_id = ?');
    $stmt->execute([$imageId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return one user's vote for an image.
 *
 * @param int $imageId Image identifier.
 * @param int $userId Admin/viewer user identifier.
 * @return int Persisted vote value or zero.
 */
function vote_model_user_vote(int $imageId, int $userId): int
{
    $stmt = db()->prepare('SELECT vote FROM image_votes WHERE image_id = ? AND user_id = ?');
    $stmt->execute([$imageId, $userId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Return one anonymous visitor's vote for an image.
 *
 * @param int $imageId Image identifier.
 * @param string $visitorHash Stable anonymous visitor hash.
 * @return int Persisted vote value or zero.
 */
function vote_model_visitor_vote(int $imageId, string $visitorHash): int
{
    $stmt = db()->prepare('SELECT vote FROM image_votes WHERE image_id = ? AND visitor_hash = ?');
    $stmt->execute([$imageId, $visitorHash]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Return many user votes keyed by image identifier.
 *
 * @param array<int, int> $imageIds Image identifiers.
 * @param int $userId User identifier.
 * @return array<int, int> Votes keyed by image identifier.
 */
function vote_model_user_votes(array $imageIds, int $userId): array
{
    return vote_model_actor_votes($imageIds, 'user_id', $userId);
}

/**
 * Return many anonymous visitor votes keyed by image identifier.
 *
 * @param array<int, int> $imageIds Image identifiers.
 * @param string $visitorHash Stable anonymous visitor hash.
 * @return array<int, int> Votes keyed by image identifier.
 */
function vote_model_visitor_votes(array $imageIds, string $visitorHash): array
{
    return vote_model_actor_votes($imageIds, 'visitor_hash', $visitorHash);
}

/**
 * Return many votes for one already-validated actor selector.
 *
 * @param array<int, int> $imageIds Image identifiers.
 * @param string $actorColumn Internal actor column, restricted to known constants.
 * @param int|string $actorValue Actor identifier value.
 * @return array<int, int> Votes keyed by image identifier.
 */
function vote_model_actor_votes(array $imageIds, string $actorColumn, int|string $actorValue): array
{
    if ($imageIds === []) {
        return [];
    }
    if (!in_array($actorColumn, ['user_id', 'visitor_hash'], true)) {
        throw new \InvalidArgumentException('Unsupported vote actor column.');
    }

    $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
    try {
        $stmt = db()->prepare('SELECT image_id, vote FROM image_votes WHERE ' . $actorColumn . ' = ? AND image_id IN (' . $placeholders . ')');
        $stmt->execute(array_merge([$actorValue], $imageIds));
        $votes = [];
        foreach ($stmt->fetchAll() as $row) {
            $votes[(int) $row['image_id']] = (int) $row['vote'];
        }
        return $votes;
    } catch (PDOException) {
        return [];
    }
}

/**
 * Delete one user's vote for an image.
 *
 * @param int $imageId Image identifier.
 * @param int $userId User identifier.
 */
function vote_model_delete_user_vote(int $imageId, int $userId): void
{
    $stmt = db()->prepare('DELETE FROM image_votes WHERE image_id = ? AND user_id = ?');
    $stmt->execute([$imageId, $userId]);
}

/**
 * Delete one anonymous visitor vote for an image.
 *
 * @param int $imageId Image identifier.
 * @param string $visitorHash Stable anonymous visitor hash.
 */
function vote_model_delete_visitor_vote(int $imageId, string $visitorHash): void
{
    $stmt = db()->prepare('DELETE FROM image_votes WHERE image_id = ? AND visitor_hash = ?');
    $stmt->execute([$imageId, $visitorHash]);
}

/**
 * Insert or update one user's vote.
 *
 * @param int $imageId Image identifier.
 * @param int $userId User identifier.
 * @param int $vote Vote value.
 * @param string $now Shared SQL timestamp.
 */
function vote_model_upsert_user_vote(int $imageId, int $userId, int $vote, string $now): void
{
    $stmt = db()->prepare('INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) VALUES (?, ?, NULL, ?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)');
    $stmt->execute([$imageId, $userId, $vote, $now, $now]);
}

/**
 * Insert or update one anonymous visitor vote.
 *
 * @param int $imageId Image identifier.
 * @param string $visitorHash Stable anonymous visitor hash.
 * @param int $vote Vote value.
 * @param string $now Shared SQL timestamp.
 */
function vote_model_upsert_visitor_vote(int $imageId, string $visitorHash, int $vote, string $now): void
{
    $stmt = db()->prepare('INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)');
    $stmt->execute([$imageId, $visitorHash, $vote, $now, $now]);
}
