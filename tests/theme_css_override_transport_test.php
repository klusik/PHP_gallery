<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_css_override_transport_test.php
 * Module Type: Regression Test
 * Purpose: Exercise the actual Theme editor request flow before any disposable override mutation.
 * Responsibilities: Verify Admin/CSRF wiring, explicit form isolation, stale responses and clear confirmation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /**
     * Assert the production route invokes its Admin boundary before editor work.
     * @return void Refuses unauthorized fixture requests with HTTP 403.
     */
    function require_admin(): void { if (!$GLOBALS['transport_admin']) { http_response_code(403); throw new \RuntimeException('Fixture Admin refusal.'); } }
    /**
     * Route every fixture invocation through the actual POST handler.
     * @return string POST request method.
     */
    function request_method(): string { return 'POST'; }
    /**
     * Assert the production request invokes CSRF validation before editor persistence.
     * @return void Refuses missing/invalid fixture authority with HTTP 403.
     */
    function verify_csrf(): void { if (($_POST['csrf_token'] ?? '') !== 'fixture-authority') { http_response_code(403); throw new \RuntimeException('Fixture CSRF refusal.'); } }
    /**
     * Keep asset URLs independent from installation configuration.
     * @param string $path Fixed first-party asset path.
     * @return string Disposable public URL.
     */
    function asset_url(string $path): string { return '/fixture/' . $path; }
    /**
     * Prepare direct-page fallback destinations without a live web server.
     * @param string $route Normalized route identifier.
     * @param array<string,int|string> $params Prepared query values.
     * @return string Disposable redirect URL.
     */
    function url_for(string $route, array $params = []): string { return '/index.php?' . http_build_query(['page' => $route] + $params); }
    /**
     * Capture the ordinary reset fallback instead of leaving the CLI fixture.
     * @param string $url Controller-prepared fallback URL.
     * @return void Stores only disposable redirect metadata.
     */
    function redirect_to(string $url): void { $GLOBALS['transport_redirect'] = $url; }
}
namespace Gallery\Services {
    /**
     * Supply deterministic translated fallback messages without installation language state.
     * @param string $key Stable translation identity.
     * @param string $fallback English message used by the real controller.
     * @return string Bounded fixture message.
     */
    function t(string $key, string $fallback = ''): string { return $fallback; }
    /**
     * Observe the unrelated ordinary Theme reset without database access.
     * @return void Records that the controller selected the separate Theme owner.
     */
    function clear_theme_overrides(): void { $GLOBALS['transport_theme_reset'] = true; }
}
namespace {
    /**
     * Require an observable transport/persistence invariant.
     * @param bool $condition Required predicate.
     * @param string $message Failure context.
     * @return void Throws on regression.
     */
    function transport_require(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
    /**
     * Invoke the actual protected route with only disposable transport and copied service storage.
     * @param array{csrf_token?:string,css_override_action?:string|list<string>,css_override_text?:string|list<string>,css_override_revision?:string|list<string>,css_override_clear_confirm?:string,reset_custom_css?:string,reset_theme_overrides?:string} $post Explicit fixture request, including malformed transport variants.
     * @param bool $authorized Whether the Admin boundary grants this fixture request.
     * @return array{status:int,denied:bool,body:array{ok:bool,message:string,state:array{text:string,revision:string,url:string}|null}|null} Captured response or pre-mutation boundary refusal.
     */
    function transport_request(array $post, bool $authorized = true): array {
        $_POST = $post; $_GET = []; $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $GLOBALS['transport_admin'] = $authorized; http_response_code(200); $denied = false;
        ob_start();
        try { \Gallery\Controllers\cms_admin_theme(); } catch (RuntimeException) { $denied = true; }
        $output = (string) ob_get_clean();
        return ['status' => http_response_code(), 'denied' => $denied, 'body' => $output === '' ? null : json_decode($output, true, 512, JSON_THROW_ON_ERROR)];
    }
    $root = sys_get_temp_dir() . '/gallery-css-transport-' . bin2hex(random_bytes(10));
    $directories = ['', '/app', '/app/services', '/public', '/public/assets'];
    foreach ($directories as $directory) mkdir($root . $directory);
    copy(dirname(__DIR__) . '/app/services/custom_css.php', $root . '/app/services/custom_css.php');
    require $root . '/app/services/custom_css.php';
    require dirname(__DIR__) . '/app/controllers/admin_theme.php';
    file_put_contents($root . '/public/assets/custom.css', '.installed { color:blue; }');
    try {
        $initial = \Gallery\Services\custom_css_overrides_state();
        $valid = ['csrf_token' => 'fixture-authority', 'css_override_action' => 'save', 'css_override_text' => '.saved { color:red; }', 'css_override_revision' => $initial['revision'], 'reset_custom_css' => '1'];
        foreach ([[$valid, false], [array_replace($valid, ['csrf_token'=>'invalid']), true], [array_diff_key($valid, ['csrf_token'=>true]), true]] as [$post, $authorized]) {
            $result = transport_request($post, $authorized);
            transport_require($result['denied'] && $result['status'] === 403 && \Gallery\Services\custom_css_overrides_state() === $initial, 'Admin/CSRF refusal must precede every file write');
        }
        $saved = transport_request($valid);
        transport_require($saved['status'] === 200 && $saved['body']['ok'] && $saved['body']['state']['text'] === $valid['css_override_text'], 'explicit protected save must return the actual installed snapshot');
        transport_require(file_get_contents($root . '/public/assets/custom.css') === '.installed { color:blue; }', 'editor POST must preempt unrelated reset fields');
        $snapshot = $saved['body']['state'];
        $stale = transport_request($valid);
        transport_require($stale['status'] === 409 && !$stale['body']['ok'] && \Gallery\Services\custom_css_overrides_state() === $snapshot, 'stale request must expose HTTP 409 without overwriting current CSS');
        foreach (['css_override_text', 'css_override_revision', 'css_override_action'] as $field) {
            $result = transport_request(array_replace($valid, [$field=>['invalid']]));
            transport_require($result['status'] === 422 && !$result['body']['ok'] && \Gallery\Services\custom_css_overrides_state() === $snapshot, 'malformed explicit transport must be refused: ' . $field);
        }
        $reload = transport_request(['csrf_token'=>'fixture-authority','css_override_action'=>'reload']);
        transport_require($reload['status'] === 200 && $reload['body']['state'] === $snapshot, 'protected reload must return saved text without requiring a draft');
        $GLOBALS['transport_theme_reset'] = false;
        transport_request(['csrf_token'=>'fixture-authority','reset_theme_overrides'=>'1','css_override_text'=>'']);
        transport_require($GLOBALS['transport_theme_reset'] && \Gallery\Services\custom_css_overrides_state() === $snapshot, 'an unrelated Theme reset with empty editor field must not clear overrides');
        $clear = ['csrf_token'=>'fixture-authority','css_override_action'=>'clear','css_override_text'=>$snapshot['text'],'css_override_revision'=>$snapshot['revision']];
        $unconfirmed = transport_request($clear);
        transport_require($unconfirmed['status'] === 422 && \Gallery\Services\custom_css_overrides_state() === $snapshot, 'clear requires separate explicit confirmation');
        $confirmed = transport_request($clear + ['css_override_clear_confirm'=>'1']);
        transport_require($confirmed['status'] === 200 && $confirmed['body']['state'] === $initial, 'confirmed clear affects only the override layer');
        echo "PASS CSS override protected transport\n";
    } finally {
        foreach (new DirectoryIterator($root . '/public/assets') as $entry) if ($entry->isFile()) unlink($entry->getPathname());
        unlink($root . '/app/services/custom_css.php');
        foreach (array_reverse($directories) as $directory) rmdir($root . $directory);
    }
}
