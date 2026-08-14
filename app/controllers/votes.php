<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/votes.php
 * Module Type: Refactored Module
 *
 * Purpose:
 *   Renders vote controls and records public votes.
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

namespace Gallery\Controllers;

use function Gallery\Core\csrf_field;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\e;
use function Gallery\Core\now_sql;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\verify_vote_rate_limit;
use function Gallery\Core\visitor_hash;
use function Gallery\Services\current_vote_for_image;
use function Gallery\Services\find_gallery;
use function Gallery\Services\find_image;
use function Gallery\Services\gallery_voting_allowed;
use function Gallery\Services\schema_inspection_is_missing;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\presentation_schema_log_degraded;
use function Gallery\Services\presentation_voting_schema_status;
use function Gallery\Services\t;
use function Gallery\Services\visitor_can_access_gallery;
use function Gallery\Services\vote_score;

/**
 * Build the public vote controls and current vote state.
 *
 * @param int $imageId Image identifier.
 * @param int $score Score value.
 * @param int $currentVote Current vote value.
 * @param bool $votingAllowed Voting allowed value.
 * @return string Text result for the caller.
 */
function render_vote_form_html(int $imageId, int $score, int $currentVote, bool $votingAllowed = true): string
{
    if (!$votingAllowed) {
        return '';
    }

    return '<form class="vote-row image-vote-overlay" method="post" action="' . e(url_for('vote')) . '" data-vote-form>'
        . '<input type="hidden" name="image_id" value="' . $imageId . '">'
        . csrf_field()
        . '<span class="vote-score-badge" aria-label="' . e(t('public.vote.likes', 'Likes')) . '"><span aria-hidden="true">&#9650;</span><strong data-score-for="' . $imageId . '">' . $score . '</strong></span>'
        . '<span class="vote-action-group">'
        . '<button type="submit" name="vote" value="1" class="' . ($currentVote === 1 ? 'is-active' : '') . '" aria-pressed="' . ($currentVote === 1 ? 'true' : 'false') . '" aria-label="' . e(t('public.vote.up', 'Vote up')) . '">&#9650;</button>'
        . '</span>'
        . '</form>';
}

/**
 * Render the public vote controls and current vote state.
 *
 * @param int $imageId Image identifier.
 * @param int $score Score value.
 * @param int $currentVote Current vote value.
 * @param bool $votingAllowed Voting allowed value.
 */
function render_vote_form(int $imageId, int $score, int $currentVote, bool $votingAllowed = true): void
{
    echo render_vote_form_html($imageId, $score, $currentVote, $votingAllowed);
}

/**
 * Record or update the current visitor's vote for an image.
 */
function cms_vote(): void
{
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    $schemaStatus = presentation_voting_schema_status();
    if (!schema_inspection_is_available($schemaStatus)) {
        if (!schema_inspection_is_missing($schemaStatus)) {
            presentation_schema_log_degraded($schemaStatus, 'image_vote_write');
        }
        http_response_code(schema_inspection_is_missing($schemaStatus) ? 409 : 503);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => schema_inspection_is_missing($schemaStatus)
                ? t('public.presentation_schema_missing', 'This optional feature requires a pending database migration.')
                : t('public.presentation_schema_unavailable', 'This optional gallery feature is temporarily unavailable because its database schema could not be verified. The main gallery remains available.'),
        ]);
        return;
    }
    // Variable $imageId stores this steps working value.
    $imageId = (int) ($_POST['image_id'] ?? 0);
    // Variable $vote stores this steps working value.
    $vote = (int) ($_POST['vote'] ?? 0);
    // $image stores an intermediate value used by the surrounding gallery workflow.
    $image = find_image($imageId);
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = $image ? find_gallery((int) $image['gallery_id']) : null;
    if (!in_array($vote, [0, 1], true) || !$image || !$gallery || !gallery_voting_allowed($gallery) || (($image['visibility'] !== 'public' || !visitor_can_access_gallery($gallery)) && !current_user())) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => t('public.vote.invalid', 'Invalid vote.')]);
        return;
    }
    // Variable $user stores this steps working value.
    $user = current_user();
    // $existingVote stores the current vote for the logged-in user or visitor.
    $existingVote = current_vote_for_image($imageId);

    // Revoking an existing like must be allowed immediately. The rate limiter is
    // kept for new likes only, otherwise a fast second click would be rejected
    // before it can remove the previous vote.
    if ($vote === 1 && $existingVote !== 1) {
        verify_vote_rate_limit($imageId);
    }

    if ($vote === 0) {
        if ($user) {
            $stmt = db()->prepare('DELETE FROM image_votes WHERE image_id = ? AND user_id = ?');
            $stmt->execute([$imageId, (int) $user['id']]);
        } else {
            $stmt = db()->prepare('DELETE FROM image_votes WHERE image_id = ? AND visitor_hash = ?');
            $stmt->execute([$imageId, visitor_hash()]);
        }
        $result = ['image_id' => $imageId, 'score' => vote_score($imageId), 'vote' => 0];
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode($result);
            return;
        }
        redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
    }
    // Submitting the active like again is treated as a revoke. This keeps normal
    // card votes, image-detail votes, lightbox votes, and keyboard-triggered
    // lightbox votes consistent even if older cached JavaScript still posts 1.
    if ($existingVote === 1) {
        if ($user) {
            $stmt = db()->prepare('DELETE FROM image_votes WHERE image_id = ? AND user_id = ?');
            $stmt->execute([$imageId, (int) $user['id']]);
        } else {
            $stmt = db()->prepare('DELETE FROM image_votes WHERE image_id = ? AND visitor_hash = ?');
            $stmt->execute([$imageId, visitor_hash()]);
        }
        $result = ['image_id' => $imageId, 'score' => vote_score($imageId), 'vote' => 0];
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode($result);
            return;
        }
        redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
    }
    if ($user) {
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) VALUES (?, ?, NULL, ?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)');
        $stmt->execute([$imageId, (int) $user['id'], $vote, now_sql(), now_sql()]);
    } else {
        // Variable $hash stores this steps working value.
        $hash = visitor_hash();
        // Variable $stmt stores this steps working value.
        $stmt = db()->prepare('INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) VALUES (?, NULL, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)');
        $stmt->execute([$imageId, $hash, $vote, now_sql(), now_sql()]);
    }
    // Variable $result stores this steps working value.
    $result = ['image_id' => $imageId, 'score' => vote_score($imageId), 'vote' => 1];
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode($result);
        return;
    }
    redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
}
