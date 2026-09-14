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

use function Gallery\Core\current_user;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\verify_vote_rate_limit;
use function Gallery\Services\current_vote_for_image;
use function Gallery\Services\delete_current_vote_for_image;
use function Gallery\Services\find_gallery;
use function Gallery\Services\find_image;
use function Gallery\Services\gallery_voting_allowed;
use function Gallery\Services\save_current_vote_for_image;
use function Gallery\Services\schema_inspection_is_missing;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\presentation_schema_log_degraded;
use function Gallery\Services\presentation_voting_schema_status;
use function Gallery\Services\t;
use function Gallery\Views\view_render_vote_form;
use function Gallery\Views\view_vote_form_html;
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
    return view_vote_form_html(vote_form_view_model($imageId, $score, $currentVote, $votingAllowed));
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
    view_render_vote_form(vote_form_view_model($imageId, $score, $currentVote, $votingAllowed));
}

/**
 * Prepare vote controls for the presentation layer.
 *
 * @return array<string, mixed> Prepared vote presentation model.
 */
function vote_form_view_model(int $imageId, int $score, int $currentVote, bool $votingAllowed = true): array
{
    return [
        'enabled' => $votingAllowed,
        'image_id' => $imageId,
        'score' => $score,
        'current_vote' => $currentVote,
        'action_url' => url_for('vote'),
        'likes_label' => t('public.vote.likes', 'Likes'),
        'up_label' => t('public.vote.up', 'Vote up'),
    ];
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
    // $existingVote stores the current vote for the logged-in user or visitor.
    $existingVote = current_vote_for_image($imageId);

    // Revoking an existing like must be allowed immediately. The rate limiter is
    // kept for new likes only, otherwise a fast second click would be rejected
    // before it can remove the previous vote.
    if ($vote === 1 && $existingVote !== 1) {
        verify_vote_rate_limit($imageId);
    }

    if ($vote === 0) {
        delete_current_vote_for_image($imageId);
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
        delete_current_vote_for_image($imageId);
        $result = ['image_id' => $imageId, 'score' => vote_score($imageId), 'vote' => 0];
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            header('Content-Type: application/json');
            echo json_encode($result);
            return;
        }
        redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
    }
    save_current_vote_for_image($imageId, $vote);
    // Variable $result stores this steps working value.
    $result = ['image_id' => $imageId, 'score' => vote_score($imageId), 'vote' => 1];
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode($result);
        return;
    }
    redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
}
