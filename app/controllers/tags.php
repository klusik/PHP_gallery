<?php

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
    render_header('Tag: ' . (string) $tag['name']);
    echo '<nav class="breadcrumbs" aria-label="Breadcrumbs"><a href="' . e(url_for('home')) . '">Galleries</a><span aria-hidden="true">/</span><span>Tag: ' . e($tag['name']) . '</span></nav>';
    echo '<section class="hero"><h1>Tag: ' . e($tag['name']) . '</h1><p class="muted">' . count($galleries) . ' galleries</p></section>';
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
 * Render the public vote controls and current vote state.
 */
function render_vote_form(int $imageId, int $score, int $currentVote, bool $votingAllowed = true): void
{
    if (!$votingAllowed) {
        return;
    }
    echo '<form class="vote-row" method="post" action="' . e(url_for('vote')) . '" data-vote-form>';
    echo '<input type="hidden" name="image_id" value="' . $imageId . '">';
    echo csrf_field();
    echo '<span>Score: <strong data-score-for="' . $imageId . '">' . $score . '</strong></span>';
    echo '<button type="submit" name="vote" value="1" class="' . ($currentVote === 1 ? 'is-active' : '') . '" aria-pressed="' . ($currentVote === 1 ? 'true' : 'false') . '" aria-label="Vote up">&#9650;</button>';
    echo '<button type="submit" name="vote" value="-1" class="' . ($currentVote === -1 ? 'is-active' : '') . '" aria-pressed="' . ($currentVote === -1 ? 'true' : 'false') . '" aria-label="Vote down">&#9660;</button>';
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
    verify_vote_rate_limit($imageId);
    // Variable $vote stores this steps working value.
    $vote = (int) ($_POST['vote'] ?? 0);
    // $image stores an intermediate value used by the surrounding gallery workflow.
    $image = find_image($imageId);
    // $gallery stores an intermediate value used by the surrounding gallery workflow.
    $gallery = $image ? find_gallery((int) $image['gallery_id']) : null;
    if (!in_array($vote, [-1, 1], true) || !$image || !$gallery || !gallery_voting_allowed($gallery) || (($image['visibility'] !== 'public' || !visitor_can_access_gallery($gallery)) && !current_user())) {
        http_response_code(422);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid vote.']);
        return;
    }
    // Variable $user stores this steps working value.
    $user = current_user();
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
    $result = ['image_id' => $imageId, 'score' => vote_score($imageId), 'vote' => $vote];
    if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode($result);
        return;
    }
    redirect_to((string) ($_SERVER['HTTP_REFERER'] ?? url_for('home')));
}

