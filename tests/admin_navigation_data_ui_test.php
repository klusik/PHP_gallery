<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_navigation_data_ui_test.php
 * Module Type: Regression Test
 * Purpose: Verify the shared navdata widget on both owned Admin surfaces offline.
 * Responsibilities: Exercise real view/controller composition, guarded imports and canonical completion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Escape visible fixture values. @param string $text Raw text. @return string HTML text. */
    function e(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    /** Return a fixture route. @param string $page Route name. @param array<string,string> $query Query values. @return string Confined URL. */
    function url_for(string $page, array $query = []): string { return '/index.php?' . http_build_query(['page' => $page] + $query); }
    /** Emit the fixture page boundary. @param string $title Page title. @return void Opens the page. */
    function render_header(string $title): void { echo '<main>'; }
    /** Emit the closing page boundary. @return void Closes the page. */
    function render_footer(): void { echo '</main>'; }
    /** Return a disposable CSRF field. @return string Fixture markup. */
    function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="fixture-csrf">'; }
    /** Record authentication. @return void Increments the boundary counter. */
    function require_admin(): void { $GLOBALS['nav_ui_auth']++; }
    /** Record CSRF validation. @return void Increments the boundary counter. */
    function verify_csrf(): void { $GLOBALS['nav_ui_csrf']++; }
    /** Return fixture transport. @return string Request method. */
    function request_method(): string { return $GLOBALS['nav_ui_method']; }
    /** Store or read a confined notice. @param string $key Notice key. @param ?string $value Notice value. @return string Stored notice. */
    function flash_message(string $key, ?string $value = null): string { return ''; }
    /** Record the non-JavaScript destination. @param string $url Owned route. @return void Stores the fallback destination. */
    function redirect_to(string $url): void { $GLOBALS['nav_ui_redirect'] = $url; }
    /** Return a confined public gallery URL. @param array<string,mixed> $gallery Fixture gallery. @return string Local render route. */
    function gallery_public_url(array $gallery): string { return '/index.php?page=gallery&id=' . (int) $gallery['id']; }
}

namespace Gallery\Services {
    /** Translate without bootstrapping application data.
     * @param string $key Translation key. @param string|array<string,mixed>|null $fallback Fallback or parameters.
     * @param array<string,mixed> $parameters Message variables. @return string Safe presentation text.
     */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string {
        static $labels = null;
        $labels ??= require __DIR__ . '/../app/lang/en.php';
        if (is_array($fallback)) { $parameters = $fallback; $fallback = null; }
        $text = (string) ($labels[$key] ?? $fallback ?? $key);
        foreach ($parameters as $name => $value) { $text = str_replace('{' . $name . '}', (string) $value, $text); }
        return $text;
    }
    /** Return one prepared import/source snapshot. @return array<string,mixed> Offline status. */
    function flight_map_navdata_status(): array { $GLOBALS['nav_ui_status_reads']++; return $GLOBALS['nav_ui_status']; }
    /** Simulate a successful import without storage or HTTP.
     * @param bool $onlyIfDue Whether this is a periodic check.
     * @param int $galleryId Saved gallery identity for completion.
     * @return array<string,mixed> Fixture outcome.
     */
    function flight_map_navdata_refresh(bool $onlyIfDue = false, int $galleryId = 0): array {
        $GLOBALS['nav_ui_refresh_args'] = [$onlyIfDue, $galleryId];
        $GLOBALS['nav_ui_refresh_session'] = session_status();
        if (!empty($GLOBALS['nav_ui_fail'])) throw new \RuntimeException('Private provider error');
        return $GLOBALS['nav_ui_outcome'] ?? ['state' => 'updated', 'result' => ['airports' => 100, 'navaids' => 20, 'skipped' => 2, 'deleted' => 3]];
    }
    /** Find only disposable gallery identities. @param int $id Gallery identity. @param bool $refresh Fresh read request. @return ?array Fixture row or null. */
    function find_gallery(int $id, bool $refresh = false): ?array { return in_array($id, [41, 42], true) ? ['id' => $id, 'slug' => 'fixture-' . $id, 'parent_id' => $id === 41 ? 42 : 0] : null; }
    /** Return prepared notices. @param array<string,mixed> $query Request query. @param string $flash Existing notice. @return list<string> Empty fixture notices. */
    function admin_dashboard_notice_messages(array $query, string $flash): array { return []; }
    /** Accept confined audit events. @param string $level Severity. @param string $event Event name. @param string $message Description. @param array<string,mixed> $details Metadata. @return void No external logging. */
    function admin_log_event(string $level, string $event, string $message, array $details = []): void {}
}

namespace {
    /** Require an observable UI/transport contract. @param bool $condition Expected behavior. @param string $message Failure description. @return void Throws on failure. */
    function nav_ui_assert(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    require_once __DIR__ . '/../app/helpers_mutation.php';
    require_once __DIR__ . '/../app/views/admin_dashboard.php';
    require_once __DIR__ . '/../app/views/navigation_data.php';
    require_once __DIR__ . '/../app/views/admin_gallery_forms.php';
    require_once __DIR__ . '/../app/controllers/admin_dashboard.php';
    require_once __DIR__ . '/../app/controllers/navigation_data.php';
    $GLOBALS['nav_ui_auth'] = $GLOBALS['nav_ui_csrf'] = $GLOBALS['nav_ui_status_reads'] = 0;
    $GLOBALS['nav_ui_method'] = 'GET';
    $GLOBALS['nav_ui_status'] = ['ready' => true, 'total' => 120, 'last_update' => '2026-10-02 12:00:00', 'last_airports' => 100, 'last_navaids' => 20, 'last_skipped' => 2, 'last_deleted' => 3, 'refresh_due' => false, 'hybrid' => ['bundled_count' => 48]];
    $_GET = [];
    ob_start();
    \Gallery\Controllers\cms_admin_navdata();
    $page = (string) ob_get_clean();
    nav_ui_assert($GLOBALS['nav_ui_auth'] === 1 && $GLOBALS['nav_ui_status_reads'] === 1, 'Manager prepares one snapshot behind authentication.');
    nav_ui_assert(substr_count($page, ' data-navdata-card ') === 1 && str_contains($page, 'name="navdata_return_page" value="admin_navdata"'), 'Manager reuses one shared import widget with its owned return path.');
    nav_ui_assert(!str_contains($page, 'Open manager') && str_contains($page, 'page=admin&amp;dashboard_tab=maintenance#admin-tab-maintenance'), 'Manager avoids linking to itself and preserves Maintenance navigation.');
    nav_ui_assert(str_contains($page, 'admin-navdata-tools') && str_contains($page, '<details class="panel admin-navdata-rules-panel">') && str_contains($page, 'aria-live="polite" hidden'), 'Secondary tools stay compact and detailed explanations/results are initially collapsed.');
    ob_start();
    \Gallery\Views\view_render_admin_navdata_maintenance_card(true, $GLOBALS['nav_ui_status']);
    $maintenance = (string) ob_get_clean();
    nav_ui_assert(str_contains($maintenance, 'Open manager') && str_contains($maintenance, 'name="navdata_return_page" value="admin"'), 'Maintenance retains its manager link and fallback destination.');
    ob_start();
    \Gallery\Views\view_render_admin_navdata_maintenance_card(false, [], 'admin_navdata');
    $unavailable = (string) ob_get_clean();
    nav_ui_assert(str_contains($unavailable, 'disabled') && !str_contains($unavailable, 'data-navdata-update-form'), 'Unavailable schema cannot render an actionable importer.');
    $GLOBALS['nav_ui_method'] = 'POST';
    foreach (['admin_navdata' => 'admin_navdata', 'admin' => 'admin', 'https://untrusted.invalid' => 'admin'] as $requested => $expected) {
        $_POST = ['ajax' => '1', 'navdata_return_page' => $requested, 'csrf_token' => 'fixture-csrf'];
        ob_start();
        \Gallery\Controllers\cms_admin_update_navdata();
        $payload = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        nav_ui_assert($payload['ok'] && $payload['mutation']['type'] === 'navigation_data.refresh' && $payload['mutation']['entity_ids'] === [] && $payload['panel']['keep_open'], 'Both surfaces preserve the canonical persistent mutation envelope.');
        nav_ui_assert($payload['fallback']['redirect_url'] === \Gallery\Core\url_for($expected) && str_contains($payload['html'], 'name="navdata_return_page" value="' . $expected . '"'), 'AJAX completion preserves only an allowlisted surface across replacements.');
        unset($_POST['ajax']);
        \Gallery\Controllers\cms_admin_update_navdata();
        nav_ui_assert($GLOBALS['nav_ui_redirect'] === \Gallery\Core\url_for($expected), 'No-JavaScript POST returns to its owned surface.');
    }
    nav_ui_assert($GLOBALS['nav_ui_auth'] === 7 && $GLOBALS['nav_ui_csrf'] === 6, 'Every persistent request keeps authentication and CSRF checks.');
    $GLOBALS['nav_ui_method'] = 'GET';
    ob_start(); \Gallery\Controllers\cms_admin_route_navdata_refresh(); $refusal = json_decode((string) ob_get_clean(), true);
    nav_ui_assert(http_response_code() === 405 && !$refusal['ok'], 'Editor freshness is never a mutating GET.');
    $GLOBALS['nav_ui_method'] = 'POST';
    $_POST = ['gallery_id' => 999, 'csrf_token' => 'fixture-csrf'];
    ob_start(); \Gallery\Controllers\cms_admin_route_navdata_refresh(); $refusal = json_decode((string) ob_get_clean(), true);
    nav_ui_assert(http_response_code() === 404 && !$refusal['ok'], 'Missing galleries refuse background work.');
    foreach (['busy', 'current', 'updated'] as $state) {
        $_POST = ['gallery_id' => 41, 'csrf_token' => 'fixture-csrf'];
        $GLOBALS['nav_ui_outcome'] = ['state' => $state, 'result' => [], 'route_updated' => false];
        ob_start(); \Gallery\Controllers\cms_admin_route_navdata_refresh(); $payload = json_decode((string) ob_get_clean(), true);
        nav_ui_assert($payload['ok'] && $payload['state'] === $state && $payload['panel'] === null && $payload['contexts'] === [], 'Background freshness preserves the canonical envelope without replacing the editor.');
        nav_ui_assert($GLOBALS['nav_ui_refresh_args'] === [true, 41] && $GLOBALS['nav_ui_refresh_session'] !== PHP_SESSION_ACTIVE, 'Background requests always respect due policy and release the session before downloads.');
    }
    $GLOBALS['nav_ui_outcome'] = ['state' => 'current', 'result' => [], 'route_updated' => true];
    ob_start(); \Gallery\Controllers\cms_admin_route_navdata_refresh(); $payload = json_decode((string) ob_get_clean(), true);
    nav_ui_assert($payload['mutation']['entity_ids'] === [41] && $payload['mutation']['type'] === 'gallery.flight_route_resolve' && array_column($payload['contexts'], 'gallery_id') === [41, 42], 'Completed saved routes identify the affected gallery and parent for canonical public refresh.');
    $GLOBALS['nav_ui_fail'] = true;
    ob_start(); \Gallery\Controllers\cms_admin_route_navdata_refresh(); $refusal = json_decode((string) ob_get_clean(), true);
    nav_ui_assert(http_response_code() === 503 && !$refusal['ok'] && !str_contains(json_encode($refusal), 'Private provider error'), 'Import failures expose a safe error independently of Save.');
    nav_ui_assert($GLOBALS['nav_ui_auth'] === 14 && $GLOBALS['nav_ui_csrf'] === 12, 'Background mutation retains authentication and CSRF on every POST.');
    ob_start(); \Gallery\Views\view_render_admin_gallery_route_disclosure(['state' => 'ready', 'route_text' => 'LKPR EDDF'], ['route_navdata_url' => '/fixture-background']); $routeControl = (string) ob_get_clean();
    nav_ui_assert(str_contains($routeControl, 'data-route-navdata-url="/fixture-background"') && str_contains($routeControl, 'name="flight_route_text"'), 'Manual route controls carry prepared refresh authority even without SimBrief.');
    echo "Admin navigation-data UI: PASS\n";
}
