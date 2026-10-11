<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_contract_test.php
 * Module Type: Regression Test
 * Purpose: Verify dormant public widget persistence shape and bounded semantic validation.
 * Responsibilities:
 *   - Prove known page scopes/locations, URL handling and safe unpublished defaults.
 *   - Keep invalid coordinates, publishing, and stale revision values from being accepted.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/public_content_widgets.php';
require_once dirname(__DIR__) . '/app/controllers/admin_public_widgets.php';

use Gallery\Services\PublicWidgetInvalidField;
use function Gallery\Controllers\admin_public_widget_published_peers;
use function Gallery\Services\public_widget_id;
use function Gallery\Services\public_widget_normalize;
use function Gallery\Services\public_widget_revision;
use function Gallery\Services\public_widget_safe_url;
use function Gallery\Services\public_widget_public_rows;
use function Gallery\Services\public_widget_visible_on_page;

$equal = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': ' . var_export($actual, true));
    }
};
$invalid = static function (callable $action, string $field): void {
    try {
        $action();
    } catch (PublicWidgetInvalidField $error) {
        if ($error->field !== $field) {
            throw new RuntimeException('Expected invalid ' . $field . ', got ' . $error->field);
        }
        return;
    }
    throw new RuntimeException('Missing expected field refusal: ' . $field);
};

$base = ['title' => 'Partner galleries', 'content_md' => "Recommended resources:\n\n- [Gallery A](https://example.org/a)\n- [Gallery B](/index.php?page=gallery&id=42)"];
$draft = public_widget_normalize($base);
$equal('draft', $draft['status'], 'New widget remains unpublished');
$equal('home', $draft['page_scope'], 'No unexpected gallery exposure');
$equal('flow', $draft['placement_mode'], 'Safe in-page placement');
$equal(320, $draft['width_px'], 'Bounded width');
$equal('home_after_grid', $draft['flow_slot'], 'Safe default slot');
$equal('en', $draft['source_language'], 'Maintained default language');
$equal(false, public_widget_visible_on_page($draft, 'home'), 'Draft excluded');
$equal([], public_widget_public_rows('admin'), 'Forbidden page type skips DB and output');
$equal([], public_widget_public_rows('login'), 'Authentication page never loads widgets');

$published = public_widget_normalize($base + ['status' => 'published', 'page_scope' => 'all']);
$equal(true, public_widget_visible_on_page($published, 'home'), 'Home scope');
$equal(true, public_widget_visible_on_page($published, 'gallery'), 'Gallery scope');
$equal(false, public_widget_visible_on_page($published, 'admin'), 'Admin never public');
$equal(false, public_widget_visible_on_page($published, 'media'), 'Media never public');
$equal(false, public_widget_visible_on_page(public_widget_normalize($base + ['status' => 'disabled']), 'home'), 'Disabled excluded');
$equal(false, public_widget_visible_on_page(public_widget_normalize([...$base, 'status' => 'published', 'page_scope' => 'gallery', 'flow_slot' => 'content_top']), 'home'), 'Gallery-only excluded from home');
$equal('https://example.org/a?q=1#part', public_widget_safe_url('https://example.org/a?q=1#part'), 'HTTPS URI');
$equal('/index.php?page=gallery&id=12', public_widget_safe_url('/index.php?page=gallery&id=12'), 'Internal gallery path');
$equal('index.php?page=gallery&id=12', public_widget_safe_url('index.php?page=gallery&id=12'), 'Subfolder query routing');
$equal('#details', public_widget_safe_url('#details'), 'In-page fragment');
$equal('0123456789abcdef0123456789abcdef', public_widget_id('0123456789abcdef0123456789abcdef'), 'Stable ID');
$equal(17, public_widget_revision('17'), 'Revision parser');

foreach (['javascript:alert(1)', 'data:text/html,hi', '//example.net', 'ftp://example.net', 'https://evil.org%0dheader', 'https://x.org/"onclick="x', '../admin', 'https://name:pass@example.org'] as $url) {
    $invalid(static fn (): string => public_widget_safe_url($url), 'content_md');
}
$invalid(static fn (): array => public_widget_normalize(['title' => 'X', 'content_md' => '[x](javascript:alert)']), 'content_md');
$invalid(static fn (): array => public_widget_normalize([...$base, 'status' => 'published', 'content_md' => '']), 'content_md');
$invalid(static fn (): array => public_widget_normalize([...$base, 'page_scope' => 'gallery', 'flow_slot' => 'home_after_grid']), 'flow_slot');
$inactiveFlow = public_widget_normalize([...$base, 'page_scope' => 'gallery', 'placement_mode' => 'floating', 'flow_slot' => 'home_after_grid']);
$equal('home_after_grid', $inactiveFlow['flow_slot'], 'Inactive flow location survives a gallery-only floating edit');
$invalid(static fn (): array => public_widget_normalize([...$base, 'placement_mode' => 'fixed']), 'placement_mode');
$invalid(static fn (): array => public_widget_normalize([...$base, 'floating_anchor' => 'middle-center']), 'floating_anchor');
$invalid(static fn (): array => public_widget_normalize([...$base, 'x_permille' => '-1']), 'x_permille');
$invalid(static fn (): array => public_widget_normalize([...$base, 'y_permille' => '1001']), 'y_permille');
$invalid(static fn (): array => public_widget_normalize([...$base, 'width_px' => 9000]), 'width_px');
$invalid(static fn (): array => public_widget_normalize([...$base, 'appearance' => 'raw_html']), 'appearance');
$invalid(static fn (): array => public_widget_normalize([...$base, 'status' => 'enabled']), 'status');
$invalid(static fn (): int => public_widget_revision('0'), 'revision');
$invalid(static fn (): int => public_widget_revision('1e3'), 'revision');
$invalid(static fn (): string => public_widget_id('not-a-widget'), 'widget_id');

$publishedFloat = public_widget_normalize([...$base, 'status' => 'published', 'placement_mode' => 'floating', 'page_scope' => 'all']);
$publishedFloat['widget_id'] = str_repeat('a', 32);
$draftFloat = [...$publishedFloat, 'widget_id' => str_repeat('b', 32), 'status' => 'draft'];
$invalidFloat = [...$publishedFloat, 'widget_id' => str_repeat('c', 32), 'x_permille' => 1200];
$regularFlow = [...$publishedFloat, 'widget_id' => str_repeat('d', 32), 'placement_mode' => 'flow'];
$publishedPeers = admin_public_widget_published_peers([$draftFloat, $invalidFloat, $regularFlow, $publishedFloat]);
$equal([[
    'id' => str_repeat('a', 32),
    'scope' => 'all',
    'anchor' => 'bottom-right',
    'x' => 900,
    'y' => 900,
    'width' => 320,
]], $publishedPeers, 'Only normalized published floating geometry is sent to Admin preview, without content');

$allPositions = ['content_top', 'content_bottom', 'left_rail', 'right_rail', 'home_before_grid', 'home_after_grid', 'footer'];
foreach ($allPositions as $slot) {
    $result = public_widget_normalize([...$base, 'flow_slot' => $slot]);
    $equal($slot, $result['flow_slot'], 'Allowed normalized flow zone');
}
$anchors = ['top-left', 'top-center', 'top-right', 'middle-left', 'middle-right', 'bottom-left', 'bottom-center', 'bottom-right', 'custom'];
foreach ($anchors as $anchor) {
    $result = public_widget_normalize([...$base, 'placement_mode' => 'floating', 'floating_anchor' => $anchor, 'x_permille' => 75, 'y_permille' => 675]);
    $equal($anchor, $result['floating_anchor'], 'Floating anchor');
    $equal(675, $result['y_permille'], 'Normalized viewport coordinates survive');
}

$migration = require dirname(__DIR__) . '/database/migrations/202610100001_public_content_widgets.php';
$equal(1, count($migration), 'One idempotent append-only table');
foreach (['CREATE TABLE IF NOT EXISTS public_content_widgets', 'PRIMARY KEY (widget_id)', 'status', 'page_scope', 'placement_mode', 'flow_slot', 'floating_anchor', 'x_permille', 'y_permille', 'revision', 'source_language'] as $fragment) {
    if (!str_contains($migration[0], $fragment)) {
        throw new RuntimeException('Missing durable storage field: ' . $fragment);
    }
}
echo "public_content_widget_contract_test: PASS\n";
