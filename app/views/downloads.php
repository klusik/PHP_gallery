<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/downloads.php
 * Module Type: View
 *
 * Purpose:
 *   Renders browser-facing download compatibility pages.
 *
 * Responsibilities:
 *   - Render the legacy download POST confirmation page
 *   - Consume only controller-prepared URLs and capability values
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
 *   - Capability issuance and authorization remain controller/service responsibilities.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;

/**
 * Render the compatibility confirmation page for a legacy download URL.
 *
 * @param array<string, mixed> $viewModel Controller-prepared confirmation state.
 */
function view_render_download_legacy_confirmation(array $viewModel): void
{
    $label = (string) ($viewModel['label'] ?? '');
    $actionUrl = (string) ($viewModel['action_url'] ?? '');
    $resourceId = (int) ($viewModel['resource_id'] ?? 0);
    $capability = (string) ($viewModel['capability'] ?? '');

    render_header($label);
    echo '<section class="hero"><div><h1>' . e($label) . '</h1></div><div class="hero-actions">';
    echo '<form method="post" action="' . e($actionUrl) . '">';
    echo '<input type="hidden" name="id" value="' . $resourceId . '">';
    echo '<input type="hidden" name="capability" value="' . e($capability) . '">';
    echo '<button type="submit" class="button">' . e($label) . '</button>';
    echo '</form>';
    echo '</div></section>';
    render_footer();
}
