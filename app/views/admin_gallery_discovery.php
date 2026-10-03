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
use function Gallery\Services\t;

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

/**
 * Render compact gallery creation with its existing direct-page POST fallback.
 * @param array<string,mixed> $viewModel Prepared navigation, escaped notices and shared form markup.
 * @return void Emits one creation form with persistent primary actions.
 */
function view_render_admin_new_gallery_page(array $viewModel): void
{
    render_header((string) ($viewModel['title'] ?? ''));
    echo '<div class="gallery-create-page"><header class="gallery-create-header"><div><h1>' . e((string) ($viewModel['title'] ?? '')) . '</h1><p>' . e(t('admin.galleries.create_hint', 'Start with a name. Add photos from the gallery after creating it.')) . '</p></div><nav class="nav"><a class="button secondary" href="' . e((string) ($viewModel['galleries_url'] ?? '')) . '">' . e((string) ($viewModel['galleries_label'] ?? '')) . '</a><a class="button secondary gallery-create-back" href="' . e((string) ($viewModel['dashboard_url'] ?? '')) . '">' . e((string) ($viewModel['dashboard_label'] ?? '')) . '</a></nav></header>';
    if ((string) ($viewModel['error_notice'] ?? '') !== '') {
        echo '<div class="notice error" role="alert">' . e((string) $viewModel['error_notice']) . '</div>';
    }
    echo '<form method="post" action="' . e((string) ($viewModel['action_url'] ?? '')) . '" class="form-grid gallery-create-form">' . (string) ($viewModel['csrf_html'] ?? '');
    echo (string) ($viewModel['fields_html'] ?? '');
    echo '<footer class="gallery-create-actions"><a href="' . e((string) ($viewModel['galleries_url'] ?? '')) . '">' . e(t('admin.gallery_list.cancel', 'Cancel')) . '</a><button type="submit" class="button primary">' . e((string) ($viewModel['submit_label'] ?? '')) . '</button></footer></form></div>';
    render_footer();
}
