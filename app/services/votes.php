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
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\current_user;
use function Gallery\Core\now_sql;
use function Gallery\Core\visitor_hash;
use function Gallery\Models\vote_model_delete_user_vote;
use function Gallery\Models\vote_model_delete_visitor_vote;
use function Gallery\Models\vote_model_score;
use function Gallery\Models\vote_model_upsert_user_vote;
use function Gallery\Models\vote_model_upsert_visitor_vote;
use function Gallery\Models\vote_model_user_vote;
use function Gallery\Models\vote_model_user_votes;
use function Gallery\Models\vote_model_visitor_vote;
use function Gallery\Models\vote_model_visitor_votes;

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
 *
 * @param int $imageId Image identifier.
 * @return int Integer result for the caller.
 */
function vote_score(int $imageId): int
{
    return vote_model_score($imageId);
}

/**
 * Return the current logged-in user or visitor's vote for one image.
 *
 * @param int $imageId Image identifier.
 * @return int Integer result for the caller.
 */
function current_vote_for_image(int $imageId): int
{
    $user = current_user();
    if ($user) {
        return vote_model_user_vote($imageId, (int) $user['id']);
    }

    return vote_model_visitor_vote($imageId, visitor_hash());
}

/**
 * Return votes for many images for the current viewer in one query, keyed by image ID.
 *
 * @param array $imageIds Image ids value.
 * @return array Structured result data for the caller.
 */
function current_votes_for_images(array $imageIds): array
{
    // $imageIds stores an intermediate value used by the surrounding gallery workflow.
    $imageIds = array_values(array_unique(array_filter(array_map('intval', $imageIds), static fn (int $id): bool => $id > 0)));
    if (!$imageIds) {
        return [];
    }

    $user = current_user();
    if ($user) {
        return vote_model_user_votes($imageIds, (int) $user['id']);
    }

    return vote_model_visitor_votes($imageIds, visitor_hash());
}

/**
 * Delete the current logged-in user or anonymous visitor's vote for an image.
 *
 * @param int $imageId Image identifier.
 */
function delete_current_vote_for_image(int $imageId): void
{
    $user = current_user();
    if ($user) {
        vote_model_delete_user_vote($imageId, (int) $user['id']);
        return;
    }

    vote_model_delete_visitor_vote($imageId, visitor_hash());
}

/**
 * Insert or update the current logged-in user or anonymous visitor's vote.
 *
 * @param int $imageId Image identifier.
 * @param int $vote Vote value.
 */
function save_current_vote_for_image(int $imageId, int $vote): void
{
    $now = now_sql();
    $user = current_user();
    if ($user) {
        vote_model_upsert_user_vote($imageId, (int) $user['id'], $vote, $now);
        return;
    }

    vote_model_upsert_visitor_vote($imageId, visitor_hash(), $vote, $now);
}
