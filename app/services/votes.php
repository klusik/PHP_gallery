<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/votes.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Provides vote score and current-vote lookup services.
 *
 * Responsibilities:
 *   - Keep behavior compatible with the previous combined implementation
 *   - Expose focused functions for one feature responsibility
 *   - Avoid coupling unrelated workflows into one large source file
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
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-12
 */

declare(strict_types=1);

/**
 * Tag and voting service functions.
 *
 * This module owns the public interaction metadata around images and galleries:
 * vote totals, current visitor vote lookups, tag parsing, tag slug generation,
 * tag persistence, entity tag synchronization, and tag-based gallery listing.
 * The legacy function names are intentionally preserved because controllers,
 * admin forms, and public views already call them directly.
 */

/**
 * Sum all votes for an image.
 */
function vote_score(int $imageId): int
{
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT COALESCE(SUM(vote), 0) FROM image_votes WHERE image_id = ?');
    $stmt->execute([$imageId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return the current logged-in user or visitor's vote for one image.
 */
function current_vote_for_image(int $imageId): int
{
    // Variable $user stores this steps working value.
    $user = current_user();
    if ($user) {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('SELECT vote FROM image_votes WHERE image_id = ? AND user_id = ?');
        $stmt->execute([$imageId, (int) $user['id']]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT vote FROM image_votes WHERE image_id = ? AND visitor_hash = ?');
    $stmt->execute([$imageId, visitor_hash()]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Return votes for many images for the current viewer in one query, keyed by image ID.
 */
function current_votes_for_images(array $imageIds): array
{
    // $imageIds stores an intermediate value used by the surrounding gallery workflow.
    $imageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if (!$imageIds) {
        return [];
    }
    // $user stores an intermediate value used by the surrounding gallery workflow.
    $user = current_user();
    // $placeholders stores an intermediate value used by the surrounding gallery workflow.
    $placeholders = implode(',', array_fill(0, count($imageIds), '?'));
    try {
        if ($user) {
            // $stmt stores an intermediate value used by the surrounding gallery workflow.
            $stmt = db()->prepare('SELECT image_id, vote FROM image_votes WHERE user_id = ? AND image_id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([(int) $user['id']], $imageIds));
        } else {
            // $stmt stores an intermediate value used by the surrounding gallery workflow.
            $stmt = db()->prepare('SELECT image_id, vote FROM image_votes WHERE visitor_hash = ? AND image_id IN (' . $placeholders . ')');
            $stmt->execute(array_merge([visitor_hash()], $imageIds));
        }
        // $votes stores an intermediate value used by the surrounding gallery workflow.
        $votes = [];
        foreach ($stmt->fetchAll() as $row) {
            $votes[(int) $row['image_id']] = (int) $row['vote'];
        }
        return $votes;
    } catch (PDOException) {
        return [];
    }
}
