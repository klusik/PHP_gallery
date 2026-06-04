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
    // $variant stores an optional admin preview mode for the saved original upload.
    $variant = (string) ($_GET['variant'] ?? '');
    // $relative stores the selected background derivative path.
    $relative = $variant === 'original' ? theme_background_original_path() : theme_background_served_path();
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
 * Stream one stored global theme branding fallback image.
 */
function cms_theme_branding_asset(): void
{
    try {
        // $kind stores an intermediate value used by the surrounding gallery workflow.
        $kind = theme_branding_asset_kind((string) ($_GET['kind'] ?? ''));
    } catch (InvalidArgumentException) {
        cms_not_found();
        return;
    }
    // $absolute stores an intermediate value used by the surrounding gallery workflow.
    $absolute = theme_branding_asset_abs_path($kind);
    if ($absolute === null) {
        cms_not_found();
        return;
    }
    // $mime stores an intermediate value used by the surrounding gallery workflow.
    $mime = mime_content_type($absolute) ?: 'application/octet-stream';
    if (!str_starts_with($mime, 'image/')) {
        cms_not_found();
        return;
    }
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
    $fontFamily = $theme['font'] === 'sans' ? 'Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif' : 'Georgia, Times New Roman, serif';
    // $backgroundOpacity stores an intermediate value used by the surrounding gallery workflow.
    $backgroundOpacity = max(0, min(100, (int) ($theme['background_opacity'] ?? 65)));
    // $gpsPinEnabled stores whether the EXIF GPS pin is visible on public cards.
    $gpsPinEnabled = ((string) ($theme['gps_pin_enabled'] ?? '1')) === '1' ? 1 : 0;
    // $gpsPinBackgroundEnabled stores whether the badge underlay is visible.
    $gpsPinBackgroundEnabled = ((string) ($theme['gps_pin_background_enabled'] ?? '1')) === '1' ? 1 : 0;
    // $gpsPinSize stores the visual size of the pin glyph.
    $gpsPinSize = theme_gps_pin_size_value($theme['gps_pin_size'] ?? null);
    // $gpsPinBackgroundSize stores the visual size of the badge underlay.
    $gpsPinBackgroundSize = theme_gps_pin_background_size_value($theme['gps_pin_background_size'] ?? null);
    // $customPageWidth stores the validated pixel width used by the Custom page-width layout preset.
    $customPageWidth = theme_page_width_custom_value($theme['page_width_custom'] ?? null);
    // $brandingSeparatorWidth stores the optional fixed separator width. Zero keeps the responsive container width.
    $brandingSeparatorWidth = theme_branding_separator_width_value($theme['branding_separator_width'] ?? null);
    // $brandingSeparatorHeight stores the public separator image height limit.
    $brandingSeparatorHeight = theme_branding_separator_height_value($theme['branding_separator_height'] ?? null);
    // $brandingSeparatorStretch stores whether the separator can ignore its native aspect ratio.
    $brandingSeparatorStretch = theme_branding_separator_stretch_enabled($theme['branding_separator_stretch'] ?? null);
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
    echo '--type-body-size:1rem;';
    echo '--type-body-line-height:1.5;';
    echo '--type-heading-line-height:1.12;';
    echo '--type-tight-line-height:1.05;';
    echo '--type-tracking-tight:-0.025em;';
    echo '--page-width-default:1120px;';
    echo '--page-width-wide:1440px;';
    echo '--page-width-custom:' . $customPageWidth . 'px;';
    echo '--gps-pin-enabled:' . $gpsPinEnabled . ';';
    echo '--gps-pin-background-enabled:' . $gpsPinBackgroundEnabled . ';';
    echo '--gps-pin-size:' . $gpsPinSize . ';';
    echo '--gps-pin-background-size:' . $gpsPinBackgroundSize . ';';
    echo '}';
    echo 'body,.admin-page{color:var(--ink);background:var(--paper);font-family:var(--font-family);font-size:var(--type-body-size);line-height:var(--type-body-line-height);text-rendering:optimizeLegibility;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale;}';
    echo '.public-page{color:var(--ink);background:var(--paper);font-family:var(--font-family);font-size:var(--type-body-size);line-height:var(--type-body-line-height);position:relative;}';
    // These layout rules are emitted by the generated theme stylesheet because it is loaded after
    // built-in skins and uploaded custom CSS. Keeping the final page-width decision here makes the
    // admin select effective even when a preset stylesheet defines its own .site-main width.
    echo '.public-page .site-header,.public-page .site-main,.public-page .site-footer{width:min(var(--page-width-default,1120px),calc(100% - 2rem));max-width:none;margin-left:auto;margin-right:auto;}';
    echo '.public-page.page-width-wide .site-header,.public-page.page-width-wide .site-main,.public-page.page-width-wide .site-footer{width:min(var(--page-width-wide,1440px),calc(100% - 2rem));max-width:none;}';
    echo '.public-page.page-width-custom .site-header,.public-page.page-width-custom .site-main,.public-page.page-width-custom .site-footer{width:min(var(--page-width-custom,1440px),calc(100% - 2rem));max-width:none;}';
    echo '.public-page.page-width-full .site-header,.public-page.page-width-full .site-main,.public-page.page-width-full .site-footer{width:calc(100% - clamp(1rem,3vw,3rem));max-width:none;}';
    if ($brandingSeparatorWidth > 0) {
        echo '.public-page .site-branding-separator{width:min(' . $brandingSeparatorWidth . 'px,calc(100% - 2rem));max-width:none;}';
    }
    if ($brandingSeparatorStretch) {
        echo '.public-page .site-branding-separator img{height:' . $brandingSeparatorHeight . 'px;max-height:none;object-fit:fill;}';
    } else {
        echo '.public-page .site-branding-separator img{height:auto;max-height:' . $brandingSeparatorHeight . 'px;object-fit:contain;}';
    }
    echo '.theme-background-shell{position:fixed;inset:0;pointer-events:none;z-index:0;}';
    echo '.theme-background-base,.theme-background-image{position:absolute;inset:0;}';
    echo '.theme-background-base{background:var(--paper);}';
    echo '.theme-background-image{background-image:' . ($themeBackground !== '' ? 'url("' . css_value($themeBackground) . '")' : 'none') . ';background-size:cover;background-position:center center;background-repeat:no-repeat;opacity:' . number_format($backgroundOpacity / 100, 2, '.', '') . ';}';
    echo '.public-page > *:not(.theme-background-shell):not(.map-overlay):not(.lightbox){position:relative;z-index:1;}';
    echo 'a{color:var(--accent-dark);}';
    echo '.site-header{background:rgba(255,255,255,0.10);backdrop-filter:blur(12px) saturate(1.08);-webkit-backdrop-filter:blur(12px) saturate(1.08);border-color:rgba(255,255,255,0.22);padding:clamp(1rem,3vw,2rem);margin-bottom:1rem;border-radius:var(--radius);}';
    echo '.admin-page .site-header{background:var(--paper);border-color:var(--line);}';
    echo '.brand{color:var(--header-text, var(--ink));font-family:var(--font-family);line-height:var(--type-tight-line-height);letter-spacing:var(--type-tracking-tight);}';
    echo '.admin-page .brand{color:var(--ink);font-family:var(--font-family);}';
    echo '.nav a,.button,button,input[type="submit"]{border-color:var(--accent-dark);background:var(--accent);color:#fffdf8;border-radius:var(--radius);}';
    echo '.pagination-link{border-radius:var(--radius)!important;}';
    echo '.nav a:hover,.button:hover,button:hover,input[type="submit"]:hover{border-color:var(--accent-dark);background:var(--accent-dark);}';
    echo '.lightbox .lightbox-stage-link,.lightbox .lightbox-stage-link:hover,.lightbox .lightbox-stage-link:focus,.lightbox .lightbox-stage-link:focus-visible,.lightbox .lightbox-stage-link:active{border:0!important;background:transparent!important;color:inherit!important;box-shadow:none!important;text-decoration:none!important;outline:0!important;}';
    echo '.lightbox .lightbox-stage-link::-moz-focus-inner{border:0!important;}';
    // These lightbox vote rules are emitted last so uploaded skins cannot make the cloned gallery voting widget unreadable.
    echo '.lightbox .lightbox-toolbar .lightbox-vote-panel,.lightbox .lightbox-toolbar .lightbox-vote-panel .lightbox-vote{background:transparent!important;color:#fffdf8!important;border:0!important;box-shadow:none!important;}';
    echo '.lightbox .lightbox-toolbar .lightbox-vote-panel .vote-score-badge{display:inline-flex!important;align-items:center!important;gap:.16rem!important;min-width:auto!important;height:1.65rem!important;padding:0 .42rem!important;border:1px solid rgba(255,253,248,.34)!important;background:rgba(2,6,23,.52)!important;color:#fffdf8!important;border-radius:999px!important;box-shadow:none!important;font-weight:800!important;line-height:1!important;}';
    echo '.lightbox .lightbox-toolbar .lightbox-vote-panel .vote-score-badge span,.lightbox .lightbox-toolbar .lightbox-vote-panel .vote-score-badge strong{color:#fffdf8!important;line-height:1!important;}';
    echo '.lightbox .lightbox-toolbar .lightbox-vote-panel .vote-action-group{display:inline-flex!important;align-items:center!important;max-width:none!important;opacity:1!important;overflow:visible!important;pointer-events:auto!important;transform:none!important;}';
    echo '.lightbox .lightbox-toolbar .lightbox-vote-panel button{display:inline-flex!important;align-items:center!important;justify-content:center!important;width:1.65rem!important;min-width:1.65rem!important;height:1.65rem!important;min-height:1.65rem!important;padding:0!important;border:1px solid rgba(255,253,248,.42)!important;background:rgba(2,6,23,.58)!important;color:#fffdf8!important;border-radius:999px!important;box-shadow:none!important;font-size:0!important;line-height:1!important;}';
    echo '.lightbox .lightbox-toolbar .lightbox-vote-panel button::before{content:"\25B2";display:block!important;color:#fffdf8!important;font-size:.72rem!important;line-height:1!important;transform:translateY(-1px)!important;}';
    echo '.lightbox .lightbox-toolbar .lightbox-vote-panel button.is-active{border-color:rgba(255,253,248,.9)!important;background:var(--accent)!important;color:#fffdf8!important;}';
    echo '.button.secondary,button.secondary{border-color:var(--accent-dark);background:transparent;color:var(--accent-dark);}';
    echo '.hero,.panel,.gallery-card,.image-card,.admin-page .hero,.admin-page .panel{background:var(--panel);border-color:var(--line);border-radius:var(--radius);}';
    echo '.public-page .hero{background:rgba(255,255,255,0.18);backdrop-filter:blur(10px) saturate(1.06);-webkit-backdrop-filter:blur(10px) saturate(1.06);position:relative;overflow:hidden;border-color:rgba(255,255,255,0.28);padding:clamp(.75rem,1.7vw,1.2rem) clamp(.9rem,2.2vw,1.45rem);margin-bottom:.75rem;}';
    echo '.public-page .hero > *{position:relative;z-index:1;}';
    echo '.public-page .hero::before{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(255,255,255,.10) 0%,rgba(255,255,255,.16) 52%,rgba(255,255,255,.22) 100%);pointer-events:none;}';
    echo '.public-page .hero h1,.public-page .hero p,.public-page .hero .tag-list-label{color:var(--hero-text, var(--ink));}';
    echo '.public-page .hero h1{line-height:var(--type-tight-line-height);letter-spacing:var(--type-tracking-tight);}';
    echo '.gallery-card-link{background:var(--panel);color:inherit;}';
    echo '.gallery-card-body h2,.image-meta h2{color:var(--ink);}';
    echo '.inline-editor{border-color:var(--line);background:var(--field);border-radius:var(--radius);}';
    echo 'input,textarea,select{background:var(--field);border-color:var(--line);border-radius:var(--radius);color:var(--ink);}';
    echo 'input:focus,textarea:focus,select:focus{border-color:var(--accent);outline-color:color-mix(in srgb,var(--accent) 22%,transparent);}';
    echo '.tag{border-color:var(--accent);background:var(--field);color:var(--accent-dark);}';
    echo '.tag:hover,.tag:focus{border-color:var(--accent-dark);background:var(--panel);color:var(--accent-dark);}';
    if ($gpsPinEnabled === 0) {
        echo '.photo-map-pin{display:none!important;}';
    } else {
        echo '.photo-map-pin{--gps-pin-size:' . $gpsPinSize . ';--gps-pin-background-size:' . $gpsPinBackgroundSize . ';}';
        echo '.photo-map-pin{--gps-pin-background-color:rgba(15,23,42,' . ($gpsPinBackgroundEnabled ? '0.55' : '0') . ');--gps-pin-border-color:rgba(255,255,255,' . ($gpsPinBackgroundEnabled ? '0.25' : '0') . ');--gps-pin-shadow:' . ($gpsPinBackgroundEnabled ? '0 1px 3px rgba(0, 0, 0, 0.16)' : 'none') . ';--gps-pin-backdrop:' . ($gpsPinBackgroundEnabled ? 'blur(4px)' : 'none') . ';}';
        if ($gpsPinBackgroundEnabled === 0) {
            echo '.photo-map-pin{background:transparent;border-color:transparent;box-shadow:none;backdrop-filter:none;-webkit-backdrop-filter:none;}';
        }
    }
    echo 'table{border-color:var(--line);border-radius:var(--radius);}';
    echo 'th{background:var(--field);color:var(--ink);}';
    echo $updatePendingCss;
}
