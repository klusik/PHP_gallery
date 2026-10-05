<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_tags_render_fixture.php
 * Module Type: Browser Test Fixture
 * Purpose: Render real public gallery-card and tag-view HTML without database access.
 * Responsibilities: Supply first-paint disclosure and scrollbar settings for responsive Chromium checks.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/public_card_layout_fixture.php';
require_once dirname(__DIR__, 2) . '/app/views/public_tags.php';

/** Build a deterministic tag set that wraps at a narrow card width. @param int $count Number of tags to render. @return array<int,array{name:string,href:string}> Ordered tag-link view models. */
function gallery_tags_fixture_items(int $count): array
{
    $names = [
        'Urban wildlife',
        'Long coast observation point tag',
        'Blue hour photographs',
        'Northern marsh habitat survey',
        'Migrating birds near the eastern shoreline',
        'Field notes from the old railway bridge',
        'Aerial view of the wetlands after rainfall',
        'Long exposure lights beside the river crossing',
        'Distant mountain range beyond the forest',
        'Seasonal color changes across the protected valley',
        'Historic stone structures on the coastal trail',
        'Wide landscape with changing afternoon clouds',
    ];
    $items = [];
    for ($index = 0; $index < $count; $index++) {
        $items[] = ['name' => $names[$index % count($names)], 'href' => '#fixture-tag-' . $index];
    }
    return $items;
}

/**
 * Render a card using the production card and gallery-tag view functions.
 * @param string $case Stable browser fixture case.
 * @param int $visibleLimit Saved tag count visible at first paint.
 * @param int $rows Saved scrollbar row cap.
 * @param bool $displayAll Whether the Theme setting displays every tag.
 * @param int $tagCount Number of tag links generated for this fixture card.
 * @param string $renderer Thumbnail renderer variant used by the card-layout fixture.
 * @return void Emits one test-only physical gallery card.
 */
function gallery_tags_fixture_card(string $case, int $visibleLimit, int $rows, bool $displayAll = false, int $tagCount = 12, string $renderer = 'responsive'): void
{
    $tagModel = [
        'items' => gallery_tags_fixture_items($tagCount),
        'visible_limit' => $visibleLimit,
        'display_all' => $displayAll,
        'scrollbar_enabled' => true,
        'scrollbar_rows' => $rows,
    ];
    static $galleryId = 1200;
    $galleryId++;
    ob_start();
    \Gallery\Views\view_render_public_gallery_card([
        'gallery_id' => $galleryId,
        'title' => 'Fixture ' . $case,
        'url' => '#fixture-gallery-' . $case,
        'visibility' => 'public',
        'description_layout' => 'vertical',
        'description' => 'A public description for ' . $case . '.',
        'cover_picture_html' => public_layout_picture($renderer),
        'tag_list_view_model' => $tagModel,
    ]);
    $cardHtml = (string) ob_get_clean();
    $cardHtml = str_replace('data-gallery-id="' . $galleryId . '"', 'data-gallery-id="' . $galleryId . '" data-fixture-case="' . \Gallery\Core\e($case) . '"' . (in_array($case, ['rows-one', 'rows-one-responsive', 'rows-five'], true) ? ' style="max-width:250px"' : ''), $cardHtml);
    echo $cardHtml;
}

/** Render the requested production stylesheet set and three setting variants. @return void Emits fixture document content for a browser test only. */
function gallery_tags_fixture_document(): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Gallery tag fixture</title>';
    foreach (\Gallery\Views\view_public_stylesheet_files() as $stylesheet) {
        echo '<link rel="stylesheet" href="/public/' . \Gallery\Core\e($stylesheet) . '">';
    }
    echo '</head><body class="public-page"><main class="gallery-grid">';
    gallery_tags_fixture_card('rows-one', 4, 1, false, 12, 'progressive');
    gallery_tags_fixture_card('rows-one-responsive', 4, 1, false, 12, 'responsive');
    gallery_tags_fixture_card('rows-five', 8, 5);
    gallery_tags_fixture_card('exact-limit', 4, 5, false, 4);
    gallery_tags_fixture_card('display-all', 4, 1, true, 8);
    $heroItems = gallery_tags_fixture_items(12);
    echo '<section class="hero" data-fixture-case="hero-rows-one" style="max-width:250px;min-width:0">';
    \Gallery\Views\view_render_public_hero_tags([
        'groups' => [
            ['items' => array_slice($heroItems, 0, 2)],
            ['label' => 'Containing tags', 'items' => array_slice($heroItems, 2)],
        ],
        'tag_count' => 12,
        'visible_limit' => 4,
        'display_all' => false,
        'scrollbar_enabled' => true,
        'scrollbar_rows' => 1,
    ]);
    echo '</section>';
    foreach ([['case' => 'hero-group-boundary', 'limit' => 2, 'all' => false, 'count' => 12], ['case' => 'hero-exact-limit', 'limit' => 4, 'all' => false, 'count' => 4], ['case' => 'hero-display-all', 'limit' => 2, 'all' => true, 'count' => 8]] as $heroCase) {
        $items = gallery_tags_fixture_items($heroCase['count']);
        echo '<section class="hero" data-fixture-case="' . $heroCase['case'] . '" style="max-width:250px;min-width:0">';
        \Gallery\Views\view_render_public_hero_tags([
            'groups' => [
                ['items' => array_slice($items, 0, 2)],
                ['label' => 'Containing tags', 'items' => array_slice($items, 2)],
            ],
            'tag_count' => $heroCase['count'],
            'visible_limit' => $heroCase['limit'],
            'display_all' => $heroCase['all'],
            'scrollbar_enabled' => true,
            'scrollbar_rows' => 1,
        ]);
        echo '</section>';
    }
    echo '</main></body></html>';
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    gallery_tags_fixture_document();
}
