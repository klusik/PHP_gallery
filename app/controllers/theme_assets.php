<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/theme_assets.php
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
 * Theme asset controllers.
 *
 * Streams generated CSS and stored theme/favicons assets through stable routes.
 * Filesystem paths are resolved from the project root because this module lives
 * one directory deeper than the legacy app/controllers.php file.
 */

/**
 * Stream the stored global theme background image.
 */
function cms_theme_background_asset(): void
{
    // $relative stores an intermediate value used by the surrounding gallery workflow.
    $relative = theme_background_path();
    if ($relative === null) {
        cms_not_found();
        return;
    }
    // $absolute stores an intermediate value used by the surrounding gallery workflow.
    $absolute = dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    if (!is_file($absolute)) {
        cms_not_found();
        return;
    }
    // $mime stores an intermediate value used by the surrounding gallery workflow.
    $mime = mime_content_type($absolute) ?: 'application/octet-stream';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($absolute));
    header('Cache-Control: public, max-age=86400');
    readfile($absolute);
}

/**
 * Stream the stored favicon image variant.
 */
function cms_favicon_asset(): void
{
    // $size stores an intermediate value used by the surrounding gallery workflow.
    $size = favicon_safe_size((int) ($_GET['s'] ?? 32));
    // $relative stores an intermediate value used by the surrounding gallery workflow.
    $relative = favicon_path($size);
    if ($relative === null) {
        cms_not_found();
        return;
    }
    // $absolute stores an intermediate value used by the surrounding gallery workflow.
    $absolute = dirname(__DIR__, 2) . '/' . ltrim($relative, '/');
    if (!is_file($absolute)) {
        cms_not_found();
        return;
    }
    header('Content-Type: image/png');
    header('Content-Length: ' . (string) filesize($absolute));
    header('Cache-Control: public, max-age=604800');
    readfile($absolute);
}

/**
 * Render dynamic theme CSS without using HTML style attributes.
 */

function cms_theme_css(): void
{
    // $updatePendingCss stores an intermediate value used by the surrounding gallery workflow.
    $updatePendingCss = '.nav a.is-update-pending,.button.is-update-pending,button.is-update-pending{border-color:#7f1d1d!important;background:repeating-linear-gradient(135deg,#b91c1c 0 .55rem,#f59e0b .55rem 1.1rem)!important;color:#fff!important;box-shadow:0 0 0 2px #fff,0 0 0 4px #7f1d1d!important;font-weight:800;}';
    // $themeBackground stores an intermediate value used by the surrounding gallery workflow.
    $themeBackground = theme_background_asset_url();
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: public, max-age=31536000, immutable');
    // $theme stores an intermediate value used by the surrounding gallery workflow.
    $theme = theme_settings();
    // $fontFamily stores an intermediate value used by the surrounding gallery workflow.
    $fontFamily = $theme['font'] === 'sans' ? 'Arial, Helvetica, sans-serif' : 'Georgia, Times New Roman, serif';
    // $backgroundOpacity stores an intermediate value used by the surrounding gallery workflow.
    $backgroundOpacity = max(0, min(100, (int) ($theme['background_opacity'] ?? 65)));
    echo ':root{';
    echo '--accent:' . css_value((string) $theme['accent']) . ';';
    echo '--accent-dark:' . css_value((string) $theme['accent_dark']) . ';';
    echo '--paper:' . css_value((string) $theme['paper']) . ';';
    echo '--panel:' . css_value((string) $theme['panel']) . ';';
    echo '--gallery-panel:' . css_value((string) $theme['gallery_panel']) . ';';
    echo '--header-text:' . css_value((string) $theme['header_text']) . ';';
    echo '--hero-text:' . css_value((string) $theme['hero_text']) . ';';
    echo '--radius:' . (int) $theme['radius'] . 'px;';
    echo '--font-family:' . css_value($fontFamily) . ';';
    echo '}';
    echo 'body,.admin-page{color:var(--ink);background:var(--paper);font-family:var(--font-family);}';
    echo '.public-page{color:var(--ink);background:var(--paper);font-family:var(--font-family);position:relative;}';
    echo '.theme-background-shell{position:fixed;inset:0;pointer-events:none;z-index:0;}';
    echo '.theme-background-base,.theme-background-image{position:absolute;inset:0;}';
    echo '.theme-background-base{background:var(--paper);}';
    echo '.theme-background-image{background-image:' . ($themeBackground !== '' ? 'url("' . css_value($themeBackground) . '")' : 'none') . ';background-size:cover;background-position:center center;background-repeat:no-repeat;opacity:' . number_format($backgroundOpacity / 100, 2, '.', '') . ';}';
    echo '.public-page > *:not(.theme-background-shell):not(.map-overlay):not(.lightbox){position:relative;z-index:1;}';
    echo 'a{color:var(--accent-dark);}';
    echo '.site-header{background:rgba(255,255,255,0.10);backdrop-filter:blur(12px) saturate(1.08);-webkit-backdrop-filter:blur(12px) saturate(1.08);border-color:rgba(255,255,255,0.22);padding:clamp(1rem,3vw,2rem);margin-bottom:1rem;border-radius:var(--radius);}';
    echo '.admin-page .site-header{background:var(--paper);border-color:var(--line);}';
    echo '.brand{color:var(--header-text, var(--ink));font-family:var(--font-family);}';
    echo '.admin-page .brand{color:var(--ink);font-family:var(--font-family);}';
    echo '.nav a,.button,button,input[type="submit"]{border-color:var(--accent-dark);background:var(--accent);color:#fffdf8;border-radius:var(--radius);}';
    echo '.nav a:hover,.button:hover,button:hover,input[type="submit"]:hover{border-color:var(--accent-dark);background:var(--accent-dark);}';
    echo '.lightbox .lightbox-stage-link,.lightbox .lightbox-stage-link:hover,.lightbox .lightbox-stage-link:focus,.lightbox .lightbox-stage-link:focus-visible,.lightbox .lightbox-stage-link:active{border:0!important;background:transparent!important;color:inherit!important;box-shadow:none!important;text-decoration:none!important;outline:0!important;}';
    echo '.lightbox .lightbox-stage-link::-moz-focus-inner{border:0!important;}';
    echo '.button.secondary,button.secondary{border-color:var(--accent-dark);background:transparent;color:var(--accent-dark);}';
    echo '.hero,.panel,.gallery-card,.image-card,.admin-page .hero,.admin-page .panel{background:var(--panel);border-color:var(--line);border-radius:var(--radius);}';
    echo '.public-page .hero{background:rgba(255,255,255,0.18);backdrop-filter:blur(10px) saturate(1.06);-webkit-backdrop-filter:blur(10px) saturate(1.06);position:relative;overflow:hidden;border-color:rgba(255,255,255,0.28);}';
    echo '.public-page .hero > *{position:relative;z-index:1;}';
    echo '.public-page .hero::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(255,255,255,.10) 0%,rgba(255,255,255,.16) 52%,rgba(255,255,255,.22) 100%);pointer-events:none;}';
    echo '.public-page .hero h1,.public-page .hero p,.public-page .hero .tag-list-label{color:var(--hero-text, var(--ink));}';
    echo '.gallery-card-link{background:var(--panel);color:inherit;}';
    echo '.gallery-card-body h2,.image-meta h2{color:var(--ink);}';
    echo '.inline-editor{border-color:var(--line);background:var(--field);border-radius:var(--radius);}';
    echo 'input,textarea,select{background:var(--field);border-color:var(--line);border-radius:var(--radius);color:var(--ink);}';
    echo 'input:focus,textarea:focus,select:focus{border-color:var(--accent);outline-color:color-mix(in srgb,var(--accent) 22%,transparent);}';
    echo '.tag{border-color:var(--accent);background:var(--field);color:var(--accent-dark);}';
    echo '.tag:hover,.tag:focus{border-color:var(--accent-dark);background:var(--panel);color:var(--accent-dark);}';
    echo 'table{border-color:var(--line);border-radius:var(--radius);}';
    echo 'th{background:var(--field);color:var(--ink);}';
    echo $updatePendingCss;
}
