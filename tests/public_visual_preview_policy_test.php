<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_visual_preview_policy_test.php
 * Module Type: Regression Test
 * Purpose: Prove the authenticated visual preview admits only read-only public render and asset requests.
 * Responsibilities:
 *   - Check administrator, method, route, benchmark, original-asset, and no-store decisions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/public_visual_preview.php';
require_once dirname(__DIR__) . '/app/services/custom_css.php';
require_once dirname(__DIR__) . '/app/controllers/shared_layout.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
};
$decide = static fn (string $page, string $method = 'GET', bool $isAdmin = true, array $query = ['preview' => 'visual']): array =>
    \Gallery\Services\public_visual_preview_request_decision($page, $method, $isAdmin, $query);

$normal = $decide('admin_theme', 'POST', false, []);
$assert(!$normal['active'] && $normal['allowed'] && $normal['cache_control'] === '', 'Ordinary requests must retain the existing dispatch path.');

foreach (['home', 'gallery', 'theme_css', 'theme_background_asset', 'theme_branding_asset', 'favicon_asset', 'gallery_cover_asset', 'gallery_branding_asset', 'media', 'public_media', 'thumb', 'public_thumb'] as $page) {
    $decision = $decide($page);
    $assert($decision['active'] && $decision['allowed'], 'The approved public preview route was refused: ' . $page);
    $assert($decision['cache_control'] === 'private, no-store, max-age=0', 'Preview responses must never be shared-cacheable: ' . $page);
}

$assert(!$decide('home', 'GET', false)['allowed'], 'A non-admin principal must not open a preview document.');
$post = $decide('gallery', 'POST');
$assert(!$post['allowed'] && $post['status'] === 405, 'Preview routes must reject state-changing methods.');
foreach (['vote', 'gallery_access', 'admin_theme', 'download_gallery', 'gallery_lightbox_data', 'thumbnail_warmup', 'setup'] as $page) {
    $assert(!$decide($page)['allowed'], 'A non-render or mutation route must not be preview-allowlisted: ' . $page);
}
$assert(!$decide('theme_background_asset', 'GET', true, ['preview' => 'visual', 'variant' => 'original'])['allowed'], 'The administrator-only original background must remain unavailable to the workspace.');
$assert(!$decide('media', 'GET', true, ['preview' => 'visual', 'ofp' => '1'])['allowed'], 'OFP attachment downloads must not enter the visual preview.');
$assert(!$decide('home', 'GET', true, ['preview' => 'other'])['allowed'], 'Unknown preview modes must fail closed.');
$assert(!$decide('home', 'GET', true, ['preview' => 'visual', 'benchmark_token' => 'fixture'])['allowed'], 'Benchmark writes must not run from preview requests.');
$assert(\Gallery\Services\public_visual_preview_is_active(['preview' => 'visual']), 'The exact visual preview marker should be recognized.');
$assert(!\Gallery\Services\public_visual_preview_is_active(['preview' => 'other']), 'Unknown preview markers should not activate preview behavior.');

$inherit = static fn (array $query, string $referer = 'https://gallery.example/sub/index.php?page=home&preview=visual', string $method = 'GET', string $origin = 'https://gallery.example', string $mount = '/sub'): array =>
    \Gallery\Services\public_visual_preview_inherit_referrer_context($query, $method, $referer, $origin, $mount);
$inherited = $inherit(['page' => 'thumb', 'id' => '8']);
$assert($inherited['inherited'] && $inherited['query']['preview'] === 'visual', 'Same-origin mounted resource requests should inherit the preview marker.');
$anonymousInherited = $inherit(['page' => 'thumb', 'view_as' => 'administrator'], 'https://gallery.example/sub/index.php?page=home&preview=visual&view_as=anonymous');
$assert($anonymousInherited['anonymous'] && $anonymousInherited['query']['view_as'] === 'anonymous', 'Anonymous referrer audience must override resource query audience.');
$assert(!$inherit(['page' => 'thumb'], 'https://other.example/sub/index.php?page=home&preview=visual')['inherited'], 'A different origin must not inherit preview context.');
$assert(!$inherit(['page' => 'thumb'], 'https://gallery.example/submarine/index.php?page=home&preview=visual')['inherited'], 'A neighboring mount path must not inherit preview context.');
$assert(!$inherit(['page' => 'thumb'], 'https://gallery.example/sub/index.php?page=home&preview=visual', 'POST')['inherited'], 'State-changing requests must not inherit preview context.');
$invalidMarker = $inherit(['page' => 'thumb', 'preview' => 'other']);
$assert(!$invalidMarker['inherited'] && $invalidMarker['query']['preview'] === 'other', 'An explicit invalid resource marker must remain untouched for dispatcher refusal.');
$assert(!$inherit(['page' => 'thumb'], 'https://gallery.example:444/sub/index.php?page=home&preview=visual')['inherited'], 'A different port must not inherit preview context.');
$assert(!$inherit(['page' => 'thumb'], 'https://gallery.example/sub/index.php?page=home&preview=visual&preview=visual')['inherited'], 'Duplicated referrer preview markers must fail closed.');
$assert(!$inherit(['page' => 'thumb'], 'https://user@gallery.example/sub/index.php?page=home&preview=visual')['inherited'], 'Referrer user information must fail closed.');
$assert(!$inherit(['page' => 'thumb'], 'https://user:secret@gallery.example/sub/index.php?page=home&preview=visual')['inherited'], 'Referrer passwords must fail closed.');
$assert(!$inherit(['page' => 'thumb'], 'https://gallery.example/sub/index.php?page=home&preview=visual#fragment')['inherited'], 'Referrer fragments must fail closed.');
$invalidPathOrigin = $inherit(['page' => 'thumb'], 'https://gallery.example/sub/index.php?page=home&preview=visual', 'GET', 'https://gallery.example/path');
$invalidQueryOrigin = $inherit(['page' => 'thumb'], 'https://gallery.example/sub/index.php?page=home&preview=visual', 'GET', 'https://gallery.example?query=1');
$assert(!$invalidPathOrigin['inherited'], 'An origin with a path must fail closed.');
$assert(!$invalidQueryOrigin['inherited'], 'An origin with a query must fail closed.');

$hasImports = '\\Gallery\\Services\\custom_css_visual_preview_css_has_imports';
$assert($hasImports('@import url("/assets/site.css");'), 'A top-level CSS import must block visual preview.');
$assert($hasImports('@\\69 mport url("/assets/site.css");'), 'An escaped top-level import identifier must block visual preview.');
$assert(!$hasImports('/* @import url(x); */ .card { content: "@import"; }'), 'Comments and quoted values must not be treated as import rules.');
$assert(!$hasImports(':root { --example: @import; }'), 'Custom-property values must not be treated as import rules.');
$assert(!$hasImports('body { background: url("data:text/plain,@import"); }'), 'Function contents must not be treated as import rules.');
$assert(!$hasImports('\\@import url("not-an-at-rule.css");'), 'An escaped at-sign identifier must not be treated as an import rule.');

$effectiveStylesheets = array_unique(array_merge(
    \Gallery\Controllers\shared_layout_public_stylesheet_files(),
    \Gallery\Controllers\shared_layout_admin_stylesheet_files(),
    ['assets/styles/mobile-gallery.css']
));
foreach ($effectiveStylesheets as $stylesheetPath) {
    $fullPath = dirname(__DIR__) . '/public/' . $stylesheetPath;
    $stylesheetText = file_get_contents($fullPath);
    $assert(is_string($stylesheetText) && !$hasImports($stylesheetText), 'A stylesheet in the shared public/Admin layout must remain import-free: ' . $stylesheetPath);
}

fwrite(STDOUT, "Public visual preview policy contract passed.\n");
