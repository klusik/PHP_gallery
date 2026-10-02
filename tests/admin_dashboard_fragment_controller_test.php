<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_dashboard_fragment_controller_test.php
 * Module Type: Regression Test
 * Purpose: Verify deferred dashboard HTTP authorization and surface selection.
 * Responsibilities: Exercise production controllers with isolated presentation seams.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /** Guard fixture reads. @return void Throws before any metadata work if unauthorized. */
    function require_admin(): void { if ($GLOBALS['overview_denied']) throw new \RuntimeException('auth'); }
    /** Seed a fixture token. @return string Token marker. */
    function csrf_token(): string { $GLOBALS['overview_token_seeded'] = true; return 'fixture'; }
    /** Return fixture request method. @return string GET or POST. */
    function request_method(): string { return $GLOBALS['overview_method']; }
    /** Return a fixture administrator. @return array{id:int} Identity. */
    function current_user(): array { return ['id' => 1]; }
    /** Return a fixture flash notice. @param string $key Name. @return string Empty notice. */
    function flash_message(string $key): string { return ''; }
    /** Omit the unrelated footer. @return void No output. */
    function render_footer(): void {}
}
namespace Gallery\Services {
    /** Capture requested work at the service boundary. @param bool $maintenance Include Maintenance. @param string $surface Requested surface. @return array<string,mixed> Fixture model. */
    function admin_dashboard_view_model(bool $maintenance = false, string $surface = 'complete'): array {
        $GLOBALS['overview_reads'][] = [$maintenance, $surface];
        if ($GLOBALS['overview_fail']) throw new \RuntimeException('private SQL and filesystem paths');
        return ['total_galleries' => 7];
    }
    /** Omit real maintenance job reads. @param ?int $actorId Owner. @return array<string,mixed> Empty fixture state. */
    function maintenance_center_dashboard_status(?int $actorId): array { return []; }
    /** Omit installation notices. @param array<string,mixed> $query Parameters. @param string $notice Flash notice. @return list<string> No notices. */
    function admin_dashboard_notice_messages(array $query, string $notice): array { return []; }
    /** Omit profiling writes. @param string $route Route. @return void No output. */
    function admin_render_profile_start(string $route): void {}
    /** Omit real diagnostics. @return null Disabled panel. */
    function admin_render_profile_panel_model(): null { return null; }
    /** Execute the owned renderer. @param string $name Span. @param callable $callback Rendering callback. @return void Calls once. */
    function admin_render_profile_span(string $name, callable $callback): void { $callback(); }
    /** Return fixture text. @param string $key Translation key. @param string $fallback Default. @return string Safe message. */
    function t(string $key, string $fallback): string { return $fallback; }
    /** Capture bounded logging. @param string $level Severity. @param string $event Identity. @param string $message Safe message. @param array<string,mixed> $context Bounded event fields. @param array<string,mixed> $options Logging flags. @return void Stores the safe event. */
    function admin_log_event(string $level, string $event, string $message, array $context, array $options): void { $GLOBALS['overview_log'] = [$level, $event, $message, $context, $options]; }
}
namespace Gallery\Views {
    /** Capture controller-prepared navigation. @param array<string,mixed> $model Dashboard model. @return void Stores model. */
    function view_render_admin_dashboard(array $model): void { $GLOBALS['overview_model'] = $model; }
    /** Render a summary fixture. @param array<string,mixed> $model Totals. @return void Outputs owned markup. */
    function view_render_admin_dashboard_summary(array $model): void { echo '<section>summary</section>'; }
    /** Render a gallery fixture. @param array<string,mixed> $model Rows. @return void Outputs owned markup. */
    function view_render_admin_dashboard_galleries_panel(array $model): void { echo '<form>galleries</form>'; }
    /** Omit profiling markup. @param ?array $model Disabled diagnostics. @param bool $expanded Initial state. @return void No output. */
    function view_render_admin_render_profile_panel(?array $model, bool $expanded = true): void {}
}
namespace {
    require_once __DIR__ . '/../app/controllers/admin_dashboard.php';
    /** Assert a controller boundary. @param bool $condition Expected behavior. @param string $message Failure context. @return void Throws on failure. */
    function overview_http_check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    /** Reset disposable request state. @return void Clears read/log history. */
    function overview_http_reset(): void {
        $GLOBALS['overview_denied'] = $GLOBALS['overview_fail'] = $GLOBALS['overview_token_seeded'] = false;
        $GLOBALS['overview_reads'] = $GLOBALS['overview_log'] = [];
        $GLOBALS['overview_method'] = 'GET'; $_GET = ['surface' => 'overview']; http_response_code(200);
    }
    /** Run a production controller without sending output. @param callable $controller Controller. @return string Captured response. */
    function overview_http_request(callable $controller): string { ob_start(); try { $controller(); return (string) ob_get_contents(); } finally { ob_end_clean(); } }
    foreach (['overview', 'galleries'] as $surface) {
        overview_http_reset(); $_GET['surface'] = $surface;
        $result = json_decode(overview_http_request('Gallery\\Controllers\\cms_admin_dashboard_fragment'), true, 512, JSON_THROW_ON_ERROR);
        overview_http_check($result['ok'] && $result['total_galleries'] === 7 && $GLOBALS['overview_reads'] === [[false, $surface]] && $GLOBALS['overview_token_seeded'], 'Each fragment reads only its authorized surface');
    }
    overview_http_reset(); $_GET['surface'] = 'maintenance';
    overview_http_request('Gallery\\Controllers\\cms_admin_dashboard_fragment');
    overview_http_check(http_response_code() === 400 && $GLOBALS['overview_reads'] === [], 'Unknown surfaces must never trigger work');
    overview_http_reset(); $GLOBALS['overview_method'] = 'POST';
    overview_http_request('Gallery\\Controllers\\cms_admin_dashboard_fragment');
    overview_http_check(http_response_code() === 405 && $GLOBALS['overview_reads'] === [], 'Fragment routes remain read-only');
    overview_http_reset(); $GLOBALS['overview_denied'] = true;
    try { overview_http_request('Gallery\\Controllers\\cms_admin_dashboard_fragment'); throw new \LogicException('Authorization bypass'); }
    catch (\RuntimeException $exception) { overview_http_check($exception->getMessage() === 'auth' && $GLOBALS['overview_reads'] === [] && !$GLOBALS['overview_token_seeded'], 'Authentication must precede all read and session work'); }
    overview_http_reset(); $GLOBALS['overview_fail'] = true;
    $failed = overview_http_request('Gallery\\Controllers\\cms_admin_dashboard_fragment');
    overview_http_check(http_response_code() === 503 && !json_decode($failed, true)['ok'] && !str_contains($failed . json_encode($GLOBALS['overview_log']), 'private SQL'), 'Failed totals must expose a retryable safe error rather than false zero or raw storage details');
    foreach (['' => [false, 'shell', 'overview'], 'overview' => [false, 'overview', 'overview'], 'galleries' => [false, 'galleries', 'galleries'], 'maintenance' => [true, 'shell', 'maintenance']] as $tab => $expected) {
        overview_http_reset(); $_GET = ['dashboard_tab' => $tab];
        overview_http_request('Gallery\\Controllers\\cms_admin');
        overview_http_check($GLOBALS['overview_reads'] === [array_slice($expected, 0, 2)] && $GLOBALS['overview_model']['active_tab'] === $expected[2], 'Shell and direct fallback URLs select only their required data');
    }
    overview_http_reset(); $_GET = ['maintenance_tab' => 'media'];
    overview_http_request('Gallery\\Controllers\\cms_admin');
    overview_http_check($GLOBALS['overview_reads'] === [[true, 'shell']] && $GLOBALS['overview_model']['maintenance_loaded'], 'Maintenance deep links must stay directly usable without preparing gallery covers');
    echo "Dashboard deferred HTTP tests passed.\n";
}
