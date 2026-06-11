<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/picture_game.php
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
 * Public picture-game controller layer.
 *
 * The functions in this file render the game route and its small reusable
 * fragments. They keep the existing global function names so the router does
 * not need to change while the large legacy controller file is reduced.
 */

/**
 * Public picture comparison game for opted-in gallery branches.
 */
function cms_picture_game(): void
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_GET['id'] ?? $_POST['gallery_id'] ?? 0));
    if (!$gallery || !visitor_can_access_gallery($gallery)) {
        cms_not_found();
        return;
    }
    if (request_method() === 'POST') {
        verify_csrf();
        try {
            record_picture_game_vote(
                $gallery,
                (int) ($_POST['left_image_id'] ?? 0),
                (int) ($_POST['right_image_id'] ?? 0),
                (int) ($_POST['winner_image_id'] ?? 0)
            );
        } catch (RuntimeException) {
            admin_log_event('warning', 'picture_game.vote_rejected', t('picture_game.log_vote_rejected'), [
                'gallery_id' => (int) $gallery['id'],
            ]);
        }
        redirect_to(url_for('picture_game', ['id' => $gallery['id']]));
    }
    // Variable $pair stores this steps working value.
    $pair = next_picture_game_pair($gallery);
    // Variable $topImages stores this steps working value.
    $topImages = picture_game_top_images($gallery);
    render_header(t('picture_game.page_title', ['title' => (string) $gallery['title']]));
    render_breadcrumbs($gallery);
    echo '<section class="hero"><h1>' . e(t('picture_game.title')) . '</h1><p>' . e(t('picture_game.description')) . '</p></section>';
    if (!$pair) {
        echo '<section class="panel"><h2>' . e(t('picture_game.complete_title')) . '</h2><p>' . e(t('picture_game.complete_description')) . '</p><p><a class="button" href="' . e(gallery_public_url($gallery)) . '">' . e(t('picture_game.back_to_gallery')) . '</a></p></section>';
        render_picture_game_stats($topImages);
        render_footer();
        return;
    }
    echo '<section class="picture-game" data-picture-game>';
    echo '<form method="post" action="' . e(url_for('picture_game')) . '">' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) $gallery['id'] . '">';
    echo '<input type="hidden" name="left_image_id" value="' . (int) $pair['left']['id'] . '">';
    echo '<input type="hidden" name="right_image_id" value="' . (int) $pair['right']['id'] . '">';
    echo '<div class="picture-game-pair">';
    render_picture_game_choice($pair['left'], 'left');
    render_picture_game_choice($pair['right'], 'right');
    echo '</div>';
    echo '</form>';
    echo '<p class="muted">' . e(t('picture_game.remaining_comparisons', ['remaining' => (string) max(0, (int) $pair['remaining_pairs'] - 1), 'total' => (string) (int) $pair['total_pairs']])) . '</p>';
    echo '</section>';
    render_picture_game_stats($topImages);
    render_footer();
}

/**
 * Render one selectable picture-game choice.
 *
 * @param array $image Image row or image data.
 * @param string $side Side value.
 */
function render_picture_game_choice(array $image, string $side): void
{
    // Variable $label stores this steps working value.
    $label = $side === 'left' ? t('picture_game.choose_left') : t('picture_game.choose_right');
    // Variable $imageGallery stores this steps working value.
    $imageGallery = ['show_filenames' => (int) ($image['gallery_show_filenames'] ?? 0)];
    // Variable $displayTitle stores this steps working value.
    $displayTitle = public_image_display_title($image, $imageGallery);
    // Variable $altText stores this steps working value.
    $altText = $displayTitle !== '' ? $displayTitle : t('picture_game.picture_alt');
    echo '<button class="picture-game-choice" type="submit" name="winner_image_id" value="' . (int) $image['id'] . '" data-picture-game-choice="' . e($side) . '" aria-label="' . e($label) . '">';
    echo '<img decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" srcset="' . e(thumbnail_srcset($image, [300, 600, 800])) . '" sizes="(min-width: 60rem) 30vw, 80vw" alt="' . e($altText) . '">';
    echo '<span>';
    if ($displayTitle !== '') {
        echo '<strong>' . e($displayTitle) . '</strong>';
    }
    echo '<small>' . e((string) ($image['gallery_title'] ?? '')) . '</small></span>';
    echo '</button>';
}

/**
 * Render top global picture-game winners for one gallery.
 *
 * @param array $topImages Top images value.
 */
function render_picture_game_stats(array $topImages): void
{
    if (!$topImages) {
        return;
    }
    echo '<section class="panel"><h2>' . e(t('picture_game.top_pictures')) . '</h2><div class="grid">';
    foreach ($topImages as $image) {
        // Variable $imageGallery stores this steps working value.
        $imageGallery = ['show_filenames' => (int) ($image['gallery_show_filenames'] ?? 0)];
        // Variable $displayTitle stores this steps working value.
        $displayTitle = public_image_display_title($image, $imageGallery);
        // Variable $altText stores this steps working value.
        $altText = $displayTitle !== '' ? $displayTitle : t('picture_game.picture_alt');
        echo '<article class="image-card"><img decoding="async" loading="lazy" src="' . e(thumbnail_url($image, 300)) . '" srcset="' . e(thumbnail_srcset($image, [300, 600, 800])) . '" sizes="(min-width: 60rem) 30vw, 80vw" alt="' . e($altText) . '">';
        echo '<div class="image-meta">';
        if ($displayTitle !== '') {
            echo '<h2>' . e($displayTitle) . '</h2>';
        }
        echo '<p class="muted">' . e(t('picture_game.score_line', ['wins' => (string) (int) $image['game_wins'], 'score' => (string) (int) $image['score']])) . '</p></div></article>';
    }
    echo '</div></section>';
}
