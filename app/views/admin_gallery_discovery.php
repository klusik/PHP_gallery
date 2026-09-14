<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_gallery_discovery.php
 * Module Type: View
 *
 * Purpose:
 *   Renders Admin gallery discovery and create-gallery page presentation.
 *
 * Responsibilities:
 *   - Render the discovery page shell and browser-job data attributes
 *   - Render the full-page create-gallery form from controller-prepared state
 *
 * Author:
 *   Rudolf Klusal
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Discovery jobs, imports, mutations, filesystem work, URL generation, CSRF issuance,
 *     gallery lookup, option generation, and create-gallery validation remain outside this view.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;

/** @param array<string,mixed> $viewModel Controller-prepared discovery-page state. */
function view_render_admin_gallery_discovery_page(array $viewModel): void
{
    render_header((string) ($viewModel['title'] ?? ''));
    echo '<section class="hero"><h1>' . e((string) ($viewModel['title'] ?? '')) . '</h1><nav class="nav"><a class="button secondary" href="' . e((string) ($viewModel['back_url'] ?? '')) . '">' . e((string) ($viewModel['back_label'] ?? '')) . '</a></nav></section>';
    view_render_admin_gallery_discovery_shell((array) ($viewModel['shell'] ?? []));
    render_footer();
}

/** @param array<string,mixed> $viewModel Controller-prepared discovery shell state. */
function view_render_admin_gallery_discovery_shell(array $viewModel): void
{
    echo '<section class="panel admin-discovery-panel" data-admin-discovery-panel data-discovery-endpoint="' . e((string) ($viewModel['endpoint'] ?? '')) . '" data-import-url="' . e((string) ($viewModel['import_url'] ?? '')) . '" data-csrf-token="' . e((string) ($viewModel['csrf_token'] ?? '')) . '" data-job-token="' . e((string) ($viewModel['job_token'] ?? '')) . '">';
    echo '<p class="muted">' . e((string) ($viewModel['intro'] ?? '')) . '</p>';
    echo '<div class="thumbnail-progress" data-admin-discovery-progress hidden><progress class="thumbnail-progress-bar" max="100" value="0" data-admin-discovery-progress-bar></progress><p class="muted" data-admin-discovery-status>' . e((string) ($viewModel['starting_message'] ?? '')) . '</p><p class="muted" data-admin-discovery-counts></p></div>';
    echo '<template data-admin-gallery-move-options><option value="">' . e((string) ($viewModel['move_placeholder'] ?? '')) . '</option>' . (string) ($viewModel['gallery_options_html'] ?? '') . '</template>';
    echo '<div data-admin-discovery-results></div>';
    echo '</section>';
}

/** @param array<string,mixed> $viewModel Controller-prepared create-gallery page state. */
function view_render_admin_new_gallery_page(array $viewModel): void
{
    render_header((string) ($viewModel['title'] ?? ''));
    echo '<section class="hero"><h1>' . e((string) ($viewModel['title'] ?? '')) . '</h1><nav class="nav"><a class="button secondary" href="' . e((string) ($viewModel['dashboard_url'] ?? '')) . '">' . e((string) ($viewModel['dashboard_label'] ?? '')) . '</a><a class="button secondary" href="' . e((string) ($viewModel['upload_url'] ?? '')) . '">' . e((string) ($viewModel['upload_label'] ?? '')) . '</a></nav></section>';
    if ((string) ($viewModel['parent_notice'] ?? '') !== '') {
        echo '<div class="notice">' . e((string) $viewModel['parent_notice']) . '</div>';
    }
    if ((string) ($viewModel['error_notice'] ?? '') !== '') {
        echo '<div class="notice">' . e((string) $viewModel['error_notice']) . '</div>';
    }
    echo '<section class="panel"><form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" class="form-grid">' . (string) ($viewModel['csrf_html'] ?? '');
    echo (string) ($viewModel['fields_html'] ?? '');
    echo '<button type="submit">' . e((string) ($viewModel['submit_label'] ?? '')) . '</button></form></section>';
    render_footer();
}
