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
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use RuntimeException;
use Gallery\Services\PresentationSchemaUnavailableException;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\find_gallery;
use function Gallery\Services\next_picture_game_pair;
use function Gallery\Services\schema_inspection_is_missing;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\presentation_schema_log_degraded;
use function Gallery\Services\presentation_picture_game_schema_status;
use function Gallery\Services\picture_game_top_images;
use function Gallery\Services\public_image_display_title;
use function Gallery\Services\record_picture_game_vote;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_srcset;
use function Gallery\Services\thumbnail_url;
use function Gallery\Services\visitor_can_access_gallery;
use function Gallery\Services\admin_log_event;
use function Gallery\Views\view_render_picture_game_choice;
use function Gallery\Views\view_render_picture_game_page;
use function Gallery\Views\view_render_picture_game_stats;
use function Gallery\Views\view_render_picture_game_unavailable;

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
    $schemaStatus = presentation_picture_game_schema_status();
    if (!schema_inspection_is_available($schemaStatus)) {
        if (schema_inspection_is_missing($schemaStatus)) {
            cms_not_found();
            return;
        }
        presentation_schema_log_degraded($schemaStatus, 'picture_game_route');
        http_response_code(503);
        $title = t('public.service_unavailable_title', 'Temporarily unavailable');
        render_header($title);
        view_render_picture_game_unavailable([
            'title' => $title,
            'message' => t('public.presentation_schema_unavailable', 'This optional gallery feature is temporarily unavailable because its database schema could not be verified. The main gallery remains available.'),
        ]);
        render_footer();
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
        } catch (PresentationSchemaUnavailableException $exception) {
            http_response_code(503);
            $title = t('public.service_unavailable_title', 'Temporarily unavailable');
            render_header($title);
            view_render_picture_game_unavailable(['title' => $title, 'message' => $exception->getMessage()]);
            render_footer();
            return;
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

    ob_start();
    render_breadcrumbs($gallery);
    $breadcrumbsHtml = (string) ob_get_clean();

    $viewModel = [
        'breadcrumbs_html' => $breadcrumbsHtml,
        'hero_title' => t('picture_game.title'),
        'description' => t('picture_game.description'),
        'complete_title' => t('picture_game.complete_title'),
        'complete_description' => t('picture_game.complete_description'),
        'back_url' => gallery_public_url($gallery),
        'back_label' => t('picture_game.back_to_gallery'),
        'action_url' => url_for('picture_game'),
        'gallery_id' => (int) $gallery['id'],
        'pair' => $pair ? [
            'left' => picture_game_choice_view_model((array) $pair['left'], 'left'),
            'right' => picture_game_choice_view_model((array) $pair['right'], 'right'),
        ] : null,
        'remaining_label' => $pair ? t('picture_game.remaining_comparisons', [
            'remaining' => (string) max(0, (int) $pair['remaining_pairs'] - 1),
            'total' => (string) (int) $pair['total_pairs'],
        ]) : '',
        'top_images' => array_map('Gallery\\Controllers\\picture_game_stats_item_view_model', $topImages),
        'top_pictures_label' => t('picture_game.top_pictures'),
    ];

    render_header(t('picture_game.page_title', ['title' => (string) $gallery['title']]));
    view_render_picture_game_page($viewModel);
    render_footer();
}

/**
 * Prepare one selectable picture-game choice.
 *
 * @param array $image Image row or image data.
 * @param string $side Side value.
 * @return array<string, mixed> Prepared image-choice presentation model.
 */
function picture_game_choice_view_model(array $image, string $side): array
{
    // Variable $label stores this steps working value.
    $label = $side === 'left' ? t('picture_game.choose_left') : t('picture_game.choose_right');
    // Variable $imageGallery stores this steps working value.
    $imageGallery = ['show_filenames' => (int) ($image['gallery_show_filenames'] ?? 0)];
    // Variable $displayTitle stores this steps working value.
    $displayTitle = public_image_display_title($image, $imageGallery);
    // Variable $altText stores this steps working value.
    $altText = $displayTitle !== '' ? $displayTitle : t('picture_game.picture_alt');

    return [
        'id' => (int) ($image['id'] ?? 0),
        'side' => $side,
        'label' => $label,
        'src' => thumbnail_url($image, 300),
        'srcset' => thumbnail_srcset($image, [300, 600, 800]),
        'alt' => $altText,
        'display_title' => $displayTitle,
        'gallery_title' => (string) ($image['gallery_title'] ?? ''),
    ];
}

/**
 * Render one selectable picture-game choice.
 *
 * @param array $image Image row or image data.
 * @param string $side Side value.
 */
function render_picture_game_choice(array $image, string $side): void
{
    view_render_picture_game_choice(picture_game_choice_view_model($image, $side));
}

/**
 * Prepare one top-image card for the picture-game stats view.
 *
 * @param array $image Image row or image data.
 * @return array<string, mixed> Prepared stats-card presentation model.
 */
function picture_game_stats_item_view_model(array $image): array
{
    // Variable $imageGallery stores this steps working value.
    $imageGallery = ['show_filenames' => (int) ($image['gallery_show_filenames'] ?? 0)];
    // Variable $displayTitle stores this steps working value.
    $displayTitle = public_image_display_title($image, $imageGallery);
    // Variable $altText stores this steps working value.
    $altText = $displayTitle !== '' ? $displayTitle : t('picture_game.picture_alt');

    return [
        'src' => thumbnail_url($image, 300),
        'srcset' => thumbnail_srcset($image, [300, 600, 800]),
        'alt' => $altText,
        'display_title' => $displayTitle,
        'score_label' => t('picture_game.score_line', [
            'wins' => (string) (int) ($image['game_wins'] ?? 0),
            'score' => (string) (int) ($image['score'] ?? 0),
        ]),
    ];
}

/**
 * Render top global picture-game winners for one gallery.
 *
 * @param array $topImages Top images value.
 */
function render_picture_game_stats(array $topImages): void
{
    $items = array_map('Gallery\\Controllers\\picture_game_stats_item_view_model', $topImages);
    view_render_picture_game_stats($items, t('picture_game.top_pictures'));
}
