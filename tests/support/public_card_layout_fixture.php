<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/public_card_layout_fixture.php
 * Module Type: Browser Test Fixture
 * Purpose: Render physical and Smart Gallery cards with actual public styles without storage access.
 * Responsibilities: Supply disposable picture variants and explicit canonical card orientations.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
require_once __DIR__.'/theme_appearance_fixture.php';
require_once __DIR__.'/theme_css_fixture.php';
require_once dirname(__DIR__,2).'/app/views/gallery_descriptions.php';
require_once dirname(__DIR__,2).'/app/views/public_gallery_cards.php';

/** Render representative authorized picture markup without fetching installation media.
 * @param string $renderer Supported progressive or responsive renderer.
 * @return string Prepared inert or eager public picture structure.
 */
function public_layout_picture(string $renderer): string {
    $source='data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="320" height="180"><rect width="320" height="180" fill="#9ab"/></svg>');
    return '<picture data-fixture-renderer="'.$renderer.'"><source '.($renderer==='progressive'?'data-srcset':'srcset').'="'.\Gallery\Core\e($source).' 320w"><img src="'.\Gallery\Core\e($source).'" width="320" height="180" alt="Disposable gallery cover"></picture>';
}

/** Render actual public view output with the canonical anonymous stylesheet sequence.
 * @return string Isolated complete document with the supported one-column grid; no public application route is executed.
 */
function public_layout_document(): string {
    ob_start();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Public card orientation fixture</title>';
    foreach (\Gallery\Views\view_public_stylesheet_files() as $stylesheet) echo '<link rel="stylesheet" href="/public/'.\Gallery\Core\e($stylesheet).'">';
    // Preserve the orientation fixture's original full-width cards using the real supported grid setting.
    echo '<style id="fixture-theme-css">' . theme_fixture_css([]) . '</style></head><body class="public-page"><header class="site-header"><a href="#home" class="brand">Fixture logo</a><nav class="nav"><a href="#gallery">Gallery</a><a href="#admin">Admin</a></nav><div class="public-language-switcher"><a href="#en">EN</a></div></header><main class="site-main"><section class="hero"><h1>Public gallery</h1><p>Hero backdrop stays present.</p></section><section class="grid pagination-grid-columns-1">';
    $id=500;
    foreach (['vertical','horizontal'] as $layout) {
        foreach (['progressive','responsive'] as $renderer) {
            \Gallery\Views\view_render_public_gallery_card(['gallery_id'=>++$id,'title'=>'Physical '.$layout.' '.$renderer,'url'=>'#physical-'.$id,'visibility'=>'public','description_layout'=>$layout,'description'=>'A disposable **gallery description**.','cover_picture_html'=>public_layout_picture($renderer),'horizontal_meta_html'=>'<span>Safe metadata</span>','tag_list_html'=>'<span class="tag-list">Fixture tag</span>']);
            \Gallery\Views\view_render_public_smart_gallery_card(['gallery_id'=>++$id,'title'=>'Smart '.$layout.' '.$renderer,'url'=>'#smart-'.$id,'card_layout'=>$layout,'description'=>'A disposable Smart Gallery description.','cover_picture_html'=>public_layout_picture($renderer),'count'=>3]);
        }
    }
    echo '</section></main><template id="fixture-transparent-css">' . \Gallery\Core\e(theme_fixture_css(['theme_header_transparent'=>'1'])) . '</template><template id="fixture-advanced-css">' . \Gallery\Core\e(theme_fixture_css(['theme_gallery_grid_gap'=>'28','theme_gallery_card_padding'=>'9','theme_card_shadow'=>'none','theme_public_type_scale'=>'120','theme_header_transparent'=>'1'])) . '</template></body></html>';
    return (string)ob_get_clean();
}
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? ''))===__FILE__) echo public_layout_document();
