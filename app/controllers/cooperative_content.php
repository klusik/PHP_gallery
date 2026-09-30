<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/cooperative_content.php
 * Module Type: Controller
 * Purpose: Adapt public cooperative catalogs and source media to HTTP.
 * Responsibilities: Keep public cooperation bounded, source-authorized and independent of admin sessions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Controllers;


use Gallery\Core as Core;
use Gallery\Services as Service;

/** Bound default scalar identifiers before service parsing.
 * Type: int. Units: bytes. Scope: cooperative content HTTP adapters.
 * Consumers: query reader default. Rationale: public opaque IDs contain exactly 32 hex characters.
 */
const COOPERATIVE_QUERY_ID_BYTES = 32;

/** Handle a bounded direct peer catalog POST with header-only credentials.
 * @return void Emit private JSON and bounded, non-diagnostic failures.
 */
function cms_cooperative_content_api(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    if (Core\request_method() !== 'POST') {
        header('Allow: POST');
        cooperative_pairing_json(['ok' => false, 'error_code' => 'method_not_allowed'], 405);
        return;
    }
    $bearer = cooperative_pairing_bearer();
    if ($bearer === '') { cooperative_pairing_json(['ok' => false, 'error_code' => 'content_unauthorized'], 403); return; }
    try { cooperative_pairing_json(Service\cooperative_content_export(cooperative_pairing_request_body(), $bearer)); }
    catch (\Throwable) { cooperative_pairing_json(['ok' => false, 'error_code' => 'content_unavailable'], 403); }
}

/** Read one bounded scalar query value without implicit array casts.
 * Purpose: Bound identifier inputs. Type: int. Units: bytes. Scope: content HTTP queries.
 * Consumers: content query adapter. Rationale: IDs use 32 bytes; tickets explicitly opt into 1600.
 * @param string $key Controller-selected query field.
 * @param int $limit Byte bound for the field.
 * @return string Submitted scalar, or a domain refusal.
 */
function cooperative_content_query(string $key, int $limit = COOPERATIVE_QUERY_ID_BYTES): string
{
    $value = $_GET[$key] ?? '';
    if (!is_string($value) || strlen($value) > $limit) { throw new Service\CooperativeException('invalid_input'); }
    return $value;
}

/** Build public labels in the controller so views remain presentation-only.
 * @return array<string,string> Localized cooperative gallery labels.
 */
function cooperative_content_labels(): array
{
    $labels = [];
    foreach (['title' => 'Shared trip', 'source' => 'Photographs from', 'local' => 'This gallery',
        'load' => 'Load photographs', 'more' => 'More photographs', 'retry' => 'Retry',
        'pending' => 'Verifying participants. Please try again shortly.',
        'unavailable' => 'This source is temporarily unavailable or no longer shared.',
        'empty' => 'No shared photographs on this page.', 'open' => 'Open photo preview',
        'back' => 'All participants'] as $key => $fallback) {
        $labels[$key] = Service\t('cooperative.public.' . $key, $fallback);
    }
    return $labels;
}

/** Convert validated source tickets into fixed-origin media links and local pagination.
 * @param array<string,mixed> $result Validated service response.
 * @param string $groupId Local collaboration identity.
 * @param string $source Current source identity.
 * @param array<string,string> $labels Prepared localized text.
 * @return array<string,mixed> Presentation-only source page.
 */
function cooperative_content_catalog_model(array $result, string $groupId, string $source, array $labels): array
{
    if ($result['pending']) { return ['pending' => true, 'labels' => $labels]; }
    $catalog = $result['catalog'];
    $photos = [];
    foreach ($catalog['photos'] as $photo) {
        $params = ['ticket' => $photo['ticket'], 'scope' => 'thumbnail'];
        $url = $result['base'] !== '' ? $result['base'] . '/index.php?' . http_build_query(['page' => 'cooperative_media'] + $params)
            : Core\url_for('cooperative_media', $params);
        $params['scope'] = 'preview';
        $preview = $result['base'] !== '' ? $result['base'] . '/index.php?' . http_build_query(['page' => 'cooperative_media'] + $params)
            : Core\url_for('cooperative_media', $params);
        $photos[] = ['src' => $url, 'href' => $photo['preview'] ? $preview : $url, 'alt' => $photo['alt']];
    }
    return ['pending' => false, 'labels' => $labels, 'title' => $catalog['title'], 'photos' => $photos,
        'next' => $catalog['next_cursor'] !== null ? Core\url_for('cooperative_gallery',
            ['group_id' => $groupId, 'source' => $source, 'cursor' => $catalog['next_cursor']]) : ''];
}

/** Render the shared gallery or one server-rendered source fragment.
 * @return void Emit safe semantic HTML with a no-JavaScript per-source fallback.
 */
function cms_cooperative_gallery(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    $labels = cooperative_content_labels();
    try {
        if (Core\request_method() !== 'GET') { throw new Service\CooperativeException('invalid_method'); }
        $id = cooperative_content_query('group_id');
        $source = cooperative_content_query('source');
        $cursor = cooperative_content_query('cursor', 1600);
        $page = Service\cooperative_content_page($id);
        if ($source !== '' && ($_GET['fragment'] ?? '') === '1') {
            $result = Service\cooperative_content_read($id, $source, $cursor);
            ob_start();
            \Gallery\Views\view_cooperative_source(cooperative_content_catalog_model($result, $id, $source, $labels));
            $html = (string) ob_get_clean();
            cooperative_pairing_json(['ok' => true, 'pending' => $result['pending'], 'html' => $html]);
            return;
        }
        $sources = [];
        foreach ($page['sources'] as $item) {
            $item['label'] = $item['local'] ? $labels['local'] : $item['origin'];
            $item['url'] = Core\url_for('cooperative_gallery', ['group_id' => $id, 'source' => $item['instance_id']]);
            $item['catalog'] = null;
            if ($source === $item['instance_id']) {
                try {
                    $item['catalog'] = cooperative_content_catalog_model(Service\cooperative_content_read($id, $source, $cursor), $id, $source, $labels);
                } catch (\Throwable) { $item['catalog'] = ['unavailable' => true, 'labels' => $labels]; }
            }
            $sources[] = $item;
        }
        Core\render_header($page['title']);
        \Gallery\Views\view_cooperative_gallery(['title' => $page['title'], 'labels' => $labels, 'sources' => $sources,
            'script_url' => Core\asset_url('assets/cooperative-gallery.js?v=20260927-photos-v1'),
            'style_url' => Core\asset_url('assets/cooperative-gallery.css?v=20260927-photos-v1')]);
        Core\render_footer();
    } catch (\Throwable) {
        http_response_code(503);
        if (($_GET['fragment'] ?? '') === '1') {
            cooperative_pairing_json(['ok' => false, 'message' => $labels['unavailable']], 503);
        } else {
            Core\render_header($labels['title']);
            \Gallery\Views\view_cooperative_source(['unavailable' => true, 'labels' => $labels]);
            Core\render_footer();
        }
    }
}

/** Emit only a currently authorized metadata-free derivative with no shared caching.
 * @return void Emit an image or a generic refusal; credentials, paths and errors never reach output.
 */
function cms_cooperative_media(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    try {
        if (Core\request_method() !== 'GET') { throw new Service\CooperativeException('invalid_method'); }
        $file = Service\cooperative_content_media(cooperative_content_query('ticket', 1600), cooperative_content_query('scope', 16));
        header('Content-Type: ' . $file['mime']);
        echo $file['bytes'];
    } catch (\Throwable) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Media unavailable.';
    }
}
