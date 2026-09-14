<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/votes.php
 * Module Type: View
 *
 * Purpose:
 *   Renders public image vote controls from controller-prepared presentation data.
 *
 * Responsibilities:
 *   - Render the vote form without reading request state or application services
 *   - Preserve the existing vote-control HTML contract
 *   - Keep vote persistence and policy decisions outside the view layer
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
 *   - This view accepts presentation-ready labels and URLs only.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;

/**
 * Build the public vote controls from presentation-ready data.
 *
 * @param array<string, mixed> $viewModel Prepared vote presentation model.
 * @return string Rendered vote form HTML.
 */
function view_vote_form_html(array $viewModel): string
{
    if (empty($viewModel['enabled'])) {
        return '';
    }

    $imageId = (int) ($viewModel['image_id'] ?? 0);
    $score = (int) ($viewModel['score'] ?? 0);
    $currentVote = (int) ($viewModel['current_vote'] ?? 0);

    return '<form class="vote-row image-vote-overlay" method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" data-vote-form>'
        . '<input type="hidden" name="image_id" value="' . $imageId . '">'
        . csrf_field()
        . '<span class="vote-score-badge" aria-label="' . e((string) ($viewModel['likes_label'] ?? '')) . '"><span aria-hidden="true">&#9650;</span><strong data-score-for="' . $imageId . '">' . $score . '</strong></span>'
        . '<span class="vote-action-group">'
        . '<button type="submit" name="vote" value="1" class="' . ($currentVote === 1 ? 'is-active' : '') . '" aria-pressed="' . ($currentVote === 1 ? 'true' : 'false') . '" aria-label="' . e((string) ($viewModel['up_label'] ?? '')) . '">&#9650;</button>'
        . '</span>'
        . '</form>';
}

/**
 * Render the public vote controls from presentation-ready data.
 *
 * @param array<string, mixed> $viewModel Prepared vote presentation model.
 */
function view_render_vote_form(array $viewModel): void
{
    echo view_vote_form_html($viewModel);
}
