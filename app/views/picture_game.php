<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/picture_game.php
 * Module Type: View
 *
 * Purpose:
 *   Renders the public picture-comparison game from controller-prepared data.
 *
 * Responsibilities:
 *   - Render picture-game page structure and reusable fragments
 *   - Keep request, schema, persistence, and gallery policy outside the view
 *   - Preserve the existing public HTML contract
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
 *   - Do not call application services from this view.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;

/**
 * Render the temporary-unavailable picture-game body.
 *
 * @param array<string, mixed> $viewModel Prepared error presentation model.
 */
function view_render_picture_game_unavailable(array $viewModel): void
{
    echo '<section class="panel"><h1>' . e((string) ($viewModel['title'] ?? '')) . '</h1><p>' . e((string) ($viewModel['message'] ?? '')) . '</p></section>';
}

/**
 * Render the public picture-game body.
 *
 * @param array<string, mixed> $viewModel Prepared game presentation model.
 */
function view_render_picture_game_page(array $viewModel): void
{
    echo (string) ($viewModel['breadcrumbs_html'] ?? '');
    echo '<section class="hero"><h1>' . e((string) ($viewModel['hero_title'] ?? '')) . '</h1><p>' . e((string) ($viewModel['description'] ?? '')) . '</p></section>';

    $pair = $viewModel['pair'] ?? null;
    if (!is_array($pair)) {
        echo '<section class="panel"><h2>' . e((string) ($viewModel['complete_title'] ?? '')) . '</h2><p>' . e((string) ($viewModel['complete_description'] ?? '')) . '</p><p><a class="button" href="' . e((string) ($viewModel['back_url'] ?? '')) . '">' . e((string) ($viewModel['back_label'] ?? '')) . '</a></p></section>';
        view_render_picture_game_stats((array) ($viewModel['top_images'] ?? []), (string) ($viewModel['top_pictures_label'] ?? ''), (string) ($viewModel['score_line_template'] ?? ''));
        return;
    }

    echo '<section class="picture-game" data-picture-game>';
    echo '<form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '">' . csrf_field();
    echo '<input type="hidden" name="gallery_id" value="' . (int) ($viewModel['gallery_id'] ?? 0) . '">';
    echo '<input type="hidden" name="left_image_id" value="' . (int) ($pair['left']['id'] ?? 0) . '">';
    echo '<input type="hidden" name="right_image_id" value="' . (int) ($pair['right']['id'] ?? 0) . '">';
    echo '<div class="picture-game-pair">';
    view_render_picture_game_choice((array) ($pair['left'] ?? []));
    view_render_picture_game_choice((array) ($pair['right'] ?? []));
    echo '</div>';
    echo '</form>';
    echo '<p class="muted">' . e((string) ($viewModel['remaining_label'] ?? '')) . '</p>';
    echo '</section>';
    view_render_picture_game_stats((array) ($viewModel['top_images'] ?? []), (string) ($viewModel['top_pictures_label'] ?? ''), (string) ($viewModel['score_line_template'] ?? ''));
}

/**
 * Render one selectable picture-game choice.
 *
 * @param array<string, mixed> $viewModel Prepared image-choice model.
 */
function view_render_picture_game_choice(array $viewModel): void
{
    echo '<button class="picture-game-choice" type="submit" name="winner_image_id" value="' . (int) ($viewModel['id'] ?? 0) . '" data-picture-game-choice="' . e((string) ($viewModel['side'] ?? '')) . '" aria-label="' . e((string) ($viewModel['label'] ?? '')) . '">';
    echo '<img decoding="async" loading="lazy" src="' . e((string) ($viewModel['src'] ?? '')) . '" srcset="' . e((string) ($viewModel['srcset'] ?? '')) . '" sizes="(min-width: 60rem) 30vw, 80vw" alt="' . e((string) ($viewModel['alt'] ?? '')) . '">';
    echo '<span>';
    if ((string) ($viewModel['display_title'] ?? '') !== '') {
        echo '<strong>' . e((string) $viewModel['display_title']) . '</strong>';
    }
    echo '<small>' . e((string) ($viewModel['gallery_title'] ?? '')) . '</small></span>';
    echo '</button>';
}

/**
 * Render top global picture-game winners for one gallery.
 *
 * @param array<int, array<string, mixed>> $items Prepared top-image models.
 * @param string $title Section title.
 * @param string $unusedTemplate Reserved compatibility argument for presentation contracts.
 */
function view_render_picture_game_stats(array $items, string $title, string $unusedTemplate = ''): void
{
    if ($items === []) {
        return;
    }
    echo '<section class="panel"><h2>' . e($title) . '</h2><div class="grid">';
    foreach ($items as $item) {
        echo '<article class="image-card"><img decoding="async" loading="lazy" src="' . e((string) ($item['src'] ?? '')) . '" srcset="' . e((string) ($item['srcset'] ?? '')) . '" sizes="(min-width: 60rem) 30vw, 80vw" alt="' . e((string) ($item['alt'] ?? '')) . '">';
        echo '<div class="image-meta">';
        if ((string) ($item['display_title'] ?? '') !== '') {
            echo '<h2>' . e((string) $item['display_title']) . '</h2>';
        }
        echo '<p class="muted">' . e((string) ($item['score_label'] ?? '')) . '</p></div></article>';
    }
    echo '</div></section>';
}
