<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/tags.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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
 *   2026-05-04
 */

declare(strict_types=1);

/**
 * Tag and voting controllers.
 *
 * This module contains public tag landing pages, public vote handling, and the
 * shared rendering helpers for tag chips and image vote forms. It is separated
 * from the main controller file so gallery page rendering can stay focused on
 * layout while the interaction metadata routes remain easy to review.
 */

/**
 * Public tag-filter page listing galleries associated with a tag.
 */
function cms_tag(): void
{
    // Variable $tag stores this steps working value.
    $tag = find_tag_by_slug((string) ($_GET['slug'] ?? ''));
    if (!$tag) {
        cms_not_found();
        return;
    }
    // Variable $galleries stores this steps working value.
    $galleries = public_galleries_for_tag((int) $tag['id']);
    render_header(t('public.tag.title_value', 'Tag: {tag}', ['tag' => (string) $tag['name']]));
    echo '<nav class="breadcrumbs" aria-label="' . e(t('public.common.breadcrumbs', 'Breadcrumbs')) . '"><a href="' . e(url_for('home')) . '">' . e(t('public.gallery.galleries', 'Galleries')) . '</a><span aria-hidden="true">/</span><span>' . e(t('public.tag.title_value', 'Tag: {tag}', ['tag' => (string) $tag['name']])) . '</span></nav>';
    echo '<section class="hero"><h1>' . e(t('public.tag.title_value', 'Tag: {tag}', ['tag' => (string) $tag['name']])) . '</h1><p class="muted">' . e(t('public.tag.gallery_count', '{count} galleries', ['count' => count($galleries)])) . '</p></section>';
    if ($galleries) {
        echo '<div class="gallery-list-frame" data-back-to-top-scope>';
        echo '<section class="grid gallery-list-content" data-back-to-top-list>';
        foreach ($galleries as $gallery) {
            render_gallery_card($gallery, true);
        }
        echo '</section>';
        render_back_to_top_button();
        echo '</div>';
    }
    render_footer();
}


/**
 * Render clickable tag pills.
 */
function render_tag_list(array $tags, ?string $label = null): void
{
    if (!$tags) {
        return;
    }
    echo '<p class="tag-list">';
    if ($label !== null) {
        echo '<span class="tag-list-label">' . e($label) . '</span>';
    }
    foreach ($tags as $tag) {
        echo '<a class="tag" href="' . e(url_for('tag', ['slug' => $tag['slug']])) . '">' . e($tag['name']) . '</a>';
    }
    echo '</p>';
}

/**
 * Render a one-line tag preview for horizontal gallery cards.
 *
 * Full gallery pages still render every tag through render_tag_list(). This
 * helper keeps card metadata visually stable beside the optional manual date
 * by showing the first tags inline and replacing the remaining tags with a
 * compact ellipsis indicator.
 */
function render_compact_tag_list(array $tags, int $visibleLimit = 3): void
{
    if (!$tags) {
        return;
    }

    $visibleLimit = max(1, $visibleLimit);
    $visibleTags = array_slice($tags, 0, $visibleLimit);
    $hiddenCount = max(0, count($tags) - count($visibleTags));

    echo '<p class="tag-list tag-list-compact">';
    foreach ($visibleTags as $tag) {
        echo '<a class="tag" href="' . e(url_for('tag', ['slug' => $tag['slug']])) . '">' . e($tag['name']) . '</a>';
    }
    if ($hiddenCount > 0) {
        echo '<span class="tag tag-more" title="' . e(t('gallery.more_tags', '{count} more tags', ['count' => $hiddenCount])) . '" aria-label="' . e(t('gallery.more_tags', '{count} more tags', ['count' => $hiddenCount])) . '">...</span>';
    }
    echo '</p>';
}

/**
 * Render the public vote controls and current vote state.
 */
function render_vote_form(int $imageId, int $score, int $currentVote, bool $votingAllowed = true): void
{
    if (!$votingAllowed) {
        return;
    }
    echo '<form class="vote-row image-vote-overlay" method="post" action="' . e(url_for('vote')) . '" data-vote-form>';
    echo '<input type="hidden" name="image_id" value="' . $imageId . '">';
    echo csrf_field();
    echo '<span class="vote-score-badge" aria-label="' . e(t('public.vote.likes', 'Likes')) . '"><span aria-hidden="true">&#9650;</span><strong data-score-for="' . $imageId . '">' . $score . '</strong></span>';
    echo '<span class="vote-action-group">';
    echo '<button type="submit" name="vote" value="1" class="' . ($currentVote === 1 ? 'is-active' : '') . '" aria-pressed="' . ($currentVote === 1 ? 'true' : 'false') . '" aria-label="' . e(t('public.vote.up', 'Vote up')) . '">&#9650;</button>';
    echo '</span>';
    echo '</form>';
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

