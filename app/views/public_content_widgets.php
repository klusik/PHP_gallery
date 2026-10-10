<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/public_content_widgets.php
 * Module Type: View
 * Purpose: Render server-prepared, single-instance public content widget regions.
 * Responsibilities:
 *   - Escape widget headings, attributes and stable IDs in all placement modes.
 *   - Reuse pre-sanitized Markdown HTML prepared by the service.
 *   - Preserve non-obstructive in-flow content if JavaScript is disabled.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;

/**
 * Return one complete region of widget markup without querying persistence.
 *
 * A floating region is an ordinary flow fallback in the initial response.
 * Visitor JavaScript may progressively fix the same article to the viewport;
 * no duplicate responsive/no-JS markup is generated.
 *
 * @param array<string,list<array<string,int|string>>> $plan Controller-prepared zones.
 * @param string $slot One of the server-defined region keys.
 * @return string Escaped, semantically grouped widget HTML or an empty string.
 */
function view_public_widget_region_html(array $plan, string $slot): string
{
    $rows = $plan[$slot] ?? [];
    if (!is_array($rows) || $rows === []) {
        return '';
    }
    $tag = in_array($slot, ['left_rail', 'right_rail'], true) ? 'aside' : 'div';
    $html = '<' . $tag . ' class="public-widget-region public-widget-region--' . e($slot)
        . '" data-public-widget-zone="' . e($slot) . '">';
    foreach ($rows as $row) {
        $id = (string) ($row['widget_id'] ?? '');
        $appearance = ($row['appearance'] ?? '') === 'minimal' ? 'minimal' : 'card';
        $language = in_array($row['source_language'] ?? '', ['en', 'cs', 'de', 'sv'], true)
            ? (string) $row['source_language'] : 'en';
        $floating = $slot === 'floating';
        $html .= '<article class="public-content-widget public-content-widget--' . $appearance
            . '" data-public-widget-id="' . e($id) . '" lang="' . e($language) . '"'
            . ' style="--public-widget-max-width:' . (int) ($row['width_px'] ?? 320) . 'px"';
        if ($floating) {
            $html .= ' data-public-widget-floating="1" data-public-widget-anchor="'
                . e((string) ($row['floating_anchor'] ?? 'bottom-right')) . '"'
                . ' data-public-widget-x="' . (int) ($row['x_permille'] ?? 900) . '"'
                . ' data-public-widget-y="' . (int) ($row['y_permille'] ?? 900) . '"';
        }
        $html .= '>';
        $title = trim((string) ($row['title'] ?? ''));
        if ($title !== '') {
            $html .= '<h2 class="public-content-widget-title">' . e($title) . '</h2>';
        }
        $html .= '<div class="public-content-widget-body">'
            . (string) ($row['body_html'] ?? '') . '</div></article>';
    }
    return $html . '</' . $tag . '>';
}

/**
 * Emit one controller-prepared public region, skipping empty zones entirely.
 *
 * @param array<string,list<array<string,int|string>>> $plan Prepared safe widget groups.
 * @param string $slot Known position to render.
 * @return void Write one markup instance for every entry in the given zone.
 */
function view_render_public_widget_region(array $plan, string $slot): void
{
    echo view_public_widget_region_html($plan, $slot);
}
