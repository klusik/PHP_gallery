<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/picture_game.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database persistence and read queries for the Picture Game feature.
 *
 * Responsibilities:
 *   - Repair gallery voting/game state
 *   - Read public gallery branches and eligible game images
 *   - Persist displayed pairs and completed votes
 *   - Return bounded leaderboard rows
 *   - Keep SQL and PDO calls out of service orchestration
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
 *   - No caller-provided SQL fragments are accepted.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use Throwable;
use function Gallery\Core\db;

/**
 * Repair Picture Game galleries so voting is enabled and revisions advance.
 *
 * @param string $now Shared SQL timestamp assigned to each repaired gallery.
 * @return int Number of gallery rows changed by the repair.
 */
function picture_game_model_sync_voting_state(string $now): int
{
    $stmt = db()->prepare(
        'UPDATE galleries SET voting_enabled = 1, updated_at = ?, edit_revision = edit_revision + 1 WHERE picture_game_enabled = 1 AND voting_enabled = 0'
    );
    $stmt->execute([$now]);
    return $stmt->rowCount();
}

/**
 * Return publicly listable galleries inside one folder-path branch.
 *
 * @return array<int,array<string,mixed>>
 */
function picture_game_model_gallery_branch_rows(string $folderPath, bool $requireListedAccess): array
{
    $sql = "SELECT g.* FROM galleries g WHERE g.visibility = 'public'";
    if ($requireListedAccess) {
        $sql .= " AND g.access_listing = 'listed'";
    }
    $sql .= ' AND (g.folder_path = ? OR g.folder_path LIKE ?) ORDER BY g.folder_path';
    $stmt = db()->prepare($sql);
    $stmt->execute([$folderPath, $folderPath . '/%']);
    return $stmt->fetchAll() ?: [];
}

/**
 * Return public direct images for Picture Game candidates.
 *
 * @param array<int,int> $galleryIds Eligible gallery identifiers.
 * @param bool $includeFilenameSetting Whether g.show_filenames is available.
 * @param bool $excludeNsfw Whether explicit NSFW images must be excluded in SQL.
 * @param ?int $limit Optional bounded result limit.
 * @return array<int,array<string,mixed>>
 */
function picture_game_model_images(
    array $galleryIds,
    bool $includeFilenameSetting,
    bool $excludeNsfw = false,
    ?int $limit = null
): array {
    if ($galleryIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    $filenameSelect = $includeFilenameSetting
        ? 'g.show_filenames AS gallery_show_filenames'
        : '0 AS gallery_show_filenames';
    $sql = 'SELECT i.*, g.title AS gallery_title, g.folder_path AS gallery_folder_path, ' . $filenameSelect
        . ' FROM images i JOIN galleries g ON g.id = i.gallery_id'
        . ' WHERE i.gallery_id IN (' . $placeholders . ") AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'";
    if ($excludeNsfw) {
        $sql .= ' AND COALESCE(i.nsfw_enabled, 0) = 0';
    }
    $sql .= ' ORDER BY g.folder_path, i.sort_order, i.filename';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, $limit);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($galleryIds);
    return $stmt->fetchAll() ?: [];
}

/** @return array<int,array{image_a_id:int,image_b_id:int}> */
function picture_game_model_seen_pairs(int $galleryId, string $voterHash): array
{
    $stmt = db()->prepare('SELECT image_a_id, image_b_id FROM picture_game_votes WHERE gallery_id = ? AND voter_hash = ?');
    $stmt->execute([$galleryId, $voterHash]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Persist one displayed pair without overwriting an already recorded winner. */
function picture_game_model_record_displayed_pair(
    int $galleryId,
    int $imageAId,
    int $imageBId,
    string $voterHash,
    string $now
): void {
    $stmt = db()->prepare(
        'INSERT IGNORE INTO picture_game_votes (gallery_id, image_a_id, image_b_id, winner_image_id, voter_hash, created_at) VALUES (?, ?, ?, NULL, ?, ?)'
    );
    $stmt->execute([$galleryId, $imageAId, $imageBId, $voterHash, $now]);
}

/**
 * Persist a completed Picture Game vote and matching image upvote atomically.
 *
 * @return bool True when a previously unrecorded winner was persisted.
 */
function picture_game_model_record_vote(
    int $galleryId,
    int $imageAId,
    int $imageBId,
    int $winnerImageId,
    string $voterHash,
    ?int $userId,
    string $visitorHash,
    string $now
): bool {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare(
            'SELECT winner_image_id FROM picture_game_votes WHERE gallery_id = ? AND voter_hash = ? AND image_a_id = ? AND image_b_id = ? FOR UPDATE'
        );
        $existing->execute([$galleryId, $voterHash, $imageAId, $imageBId]);
        $winner = $existing->fetchColumn();
        if ($winner !== false && $winner !== null) {
            $pdo->commit();
            return false;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO picture_game_votes (gallery_id, image_a_id, image_b_id, winner_image_id, voter_hash, created_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE winner_image_id = VALUES(winner_image_id)'
        );
        $stmt->execute([$galleryId, $imageAId, $imageBId, $winnerImageId, $voterHash, $now]);

        if ($userId !== null && $userId > 0) {
            $vote = $pdo->prepare(
                'INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) '
                . 'VALUES (?, ?, NULL, 1, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)'
            );
            $vote->execute([$winnerImageId, $userId, $now, $now]);
        } else {
            $vote = $pdo->prepare(
                'INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) '
                . 'VALUES (?, NULL, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)'
            );
            $vote->execute([$winnerImageId, $visitorHash, $now, $now]);
        }
        $pdo->commit();
        return true;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Return bounded top Picture Game winners for already-authorized image ids.
 *
 * @param array<int,int> $imageIds Positive image identifiers.
 * @return array<int,array<string,mixed>>
 */
function picture_game_model_top_images(array $imageIds, bool $includeFilenameSetting, int $limit): array
{
    if ($imageIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
    $filenameSelect = $includeFilenameSetting
        ? 'g.show_filenames AS gallery_show_filenames'
        : '0 AS gallery_show_filenames';
    $sql = 'SELECT i.*, g.title AS gallery_title, ' . $filenameSelect . ', '
        . '(SELECT COUNT(*) FROM picture_game_votes pgv WHERE pgv.winner_image_id = i.id) AS game_wins, '
        . '(SELECT COALESCE(SUM(iv.vote), 0) FROM image_votes iv WHERE iv.image_id = i.id) AS score '
        . 'FROM images i JOIN galleries g ON g.id = i.gallery_id '
        . 'WHERE i.id IN (' . $placeholders . ') '
        . 'AND (SELECT COUNT(*) FROM picture_game_votes pgv WHERE pgv.winner_image_id = i.id) > 0 '
        . 'ORDER BY game_wins DESC, score DESC, i.sort_order, i.filename LIMIT ' . max(1, $limit);
    $stmt = db()->prepare($sql);
    $stmt->execute($imageIds);
    return $stmt->fetchAll() ?: [];
}
