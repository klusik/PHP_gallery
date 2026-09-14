<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/http.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders small shared HTTP/public presentation fragments prepared by controllers.
 *
 * Responsibilities:
 *   - Render the minimal HTML 503 document for protected public schema failures
 *   - Render the public back-to-top control
 *   - Render the normal public 404 panel
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
 *   - This view receives translated presentation strings and performs no request or domain lookup.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\css_value;
use function Gallery\Core\e;

/**
 * Render the deliberately minimal public 503 document.
 *
 * @param string $title Translated page title.
 * @param string $message Translated visitor-facing failure message.
 * @param string $requestReference Optional translated request reference line.
 */
function view_render_public_service_unavailable(string $title, string $message, string $requestReference = ''): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . '</title></head><body><main><h1>' . e($title) . '</h1><p>' . e($message) . '</p>';
    if ($requestReference !== '') {
        echo '<p>' . e($requestReference) . '</p>';
    }
    echo '</main></body></html>';
}

/**
 * Render the public back-to-top control.
 *
 * @param string $label Accessible translated label.
 * @param string $shortLabel Visible translated short label.
 */
function view_render_back_to_top_button(string $label, string $shortLabel): void
{
    echo '<button type="button" class="back-to-top-button" data-back-to-top-button hidden aria-label="' . e($label) . '" title="' . e($label) . '"><span aria-hidden="true">↑</span><span>' . e($shortLabel) . '</span></button>';
}

/**
 * Render the normal public not-found panel inside the shared layout.
 *
 * @param string $title Translated heading.
 * @param string $message Translated explanatory message.
 */
function view_render_not_found(string $title, string $message): void
{
    echo '<section class="panel"><h1>' . e($title) . '</h1><p>' . e($message) . '</p></section>';
}



/**
 * Render an administrator-facing disabled-feature panel.
 *
 * @param string $title Translated heading.
 * @param string $message Translated explanatory message.
 * @param string $settingsUrl URL to the Admin feature settings page.
 * @param string $settingsLabel Translated settings-link label.
 */
function view_render_feature_disabled_admin(string $title, string $message, string $settingsUrl, string $settingsLabel): void
{
    echo '<section class="hero"><h1>' . e($title) . '</h1><p class="muted">' . e($message) . '</p></section>';
    echo '<section class="panel"><p><a class="button" href="' . e($settingsUrl) . '">' . e($settingsLabel) . '</a></p></section>';
}

/**
 * Return the noindex/nofollow head fragment used by public token-gated pages.
 *
 * @return string Safe head markup.
 */
function view_public_noindex_meta(): string
{
    return '<meta name="robots" content="noindex,nofollow">';
}

/**
 * Return the scoped gallery-background style fragment for the shared document head.
 *
 * @param string $assetUrl Authorized public background asset URL.
 * @return string Safe head markup.
 */
function view_gallery_background_style(string $assetUrl): string
{
    return '<style>.theme-background-image{background-image:url("' . css_value($assetUrl) . '");}</style>';
}
