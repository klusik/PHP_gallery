<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_css_override_transport_test.php
 * Module Type: Regression Test
 * Purpose: Exercise protected CSS editor transport and isolate optional Theme image-read failures.
 * Responsibilities: Verify Admin/CSRF wiring, CSS-only fallback saves, missing-image rejection, explicit form isolation and stale responses.
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
    /** Prepare the safe public homepage preview destination for the controller rendering fixture.
     * @param string $url Relative destination selected by the controller.
     * @return string Deterministic fixture preview URL.
     */
    function public_visual_preview_url(string $url): string { return '/fixture-preview' . $url; }
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
    /** Supply a harmless application setting to the copied Custom CSS service.
     * @param string $key Stable setting identifier.
     * @param string $default Missing-value fallback.
     * @return string Fixture setting value or the provided default.
     */
    function app_setting(string $key, string $default = ''): string { return $GLOBALS['transport_settings'][$key] ?? $default; }
    /** Prepare the CSS editor's independent width baseline without reading installation settings.
     * @return array{page_width:string,page_width_custom:int} Bounded disposable Theme settings.
     */
    function theme_settings(): array { return ['page_width'=>'default','page_width_custom'=>1440]; }
    /**
     * Observe the unrelated ordinary Theme reset without database access.
     * @return void Records that the controller selected the separate Theme owner.
     */
    function clear_theme_overrides(): void { $GLOBALS['transport_theme_reset'] = true; }
    /** Return a harmless background snapshot or inject an unreadable owned asset during rendering.
     * @return array{available:bool,revision:string,source:string,url:string} Disposable controller response model.
     * @throws \RuntimeException When the fixture simulates an unreadable configured image.
     */
    function theme_background_editor_state(): array {
        if (!empty($GLOBALS['transport_background_unavailable'])) throw new \RuntimeException('Injected unreadable Theme image.');
        return ['available'=>false,'revision'=>str_repeat('a',64),'source'=>'','url'=>''];
    }
}
namespace Gallery\Views {
    /** Capture only the prepared CSS-tab model for the controller exception fixture.
     * @param array<string,mixed> $model Presentation model prepared by the actual controller.
     * @return void Stores the exact disposable model for assertions.
     */
    function view_render_admin_theme_custom_css_tab(array $model): void { $GLOBALS['transport_css_rendered_model'] = $model; }
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
     * @param array{csrf_token?:string,css_override_action?:string|list<string>,css_override_text?:string|list<string>,css_override_revision?:string|list<string>,theme_background_revision?:string,theme_background_operation?:'keep'|'replace'|'remove',theme_background_target?:string,css_override_clear_confirm?:string,reset_custom_css?:string,reset_theme_overrides?:string} $post Explicit fixture request, including malformed transport variants and discriminated Theme-image operations.
     * @param bool $authorized Whether the Admin boundary grants this fixture request.
     * @param array<string,array{name?:string,tmp_name?:string,error?:int,size?:int}> $files PHP upload descriptors submitted to the same existing route.
     * @return array{status:int,denied:bool,body:array{ok:bool,message:string,state:array{text:string,revision:string,url:string}|null,background:array{available:bool,revision:string,source:string,url:string,operation?:string,target?:string}|null}|null} Captured response or pre-mutation boundary refusal.
     */
    function transport_request(array $post, bool $authorized = true, array $files = []): array {
        $_POST = $post; $_FILES = $files; $_GET = []; $_SERVER['HTTP_ACCEPT'] = 'application/json';
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
    mkdir($root . '/app/services/custom_css');
    copy(dirname(__DIR__) . '/app/services/custom_css/visual_background_save.php', $root . '/app/services/custom_css/visual_background_save.php');
    require $root . '/app/services/custom_css.php';
    require dirname(__DIR__) . '/app/controllers/admin_theme.php';
    file_put_contents($root . '/public/assets/custom.css', '.installed { color:blue; }');
    try {
        $GLOBALS['transport_settings'] = ['custom_css_preset'=>'uploaded'];
        $GLOBALS['transport_background_unavailable'] = true;
        $_GET = ['css_editor'=>'1']; $_SESSION = [];
        \Gallery\Controllers\render_admin_theme_custom_css_tab();
        $renderedModel = $GLOBALS['transport_css_rendered_model'] ?? null;
        transport_require(is_array($renderedModel)
            && ($renderedModel['overrides']['ready'] ?? false) === true
            && ($renderedModel['overrides']['background']['ready'] ?? true) === false
            && ($renderedModel['overrides']['background']['available'] ?? true) === false
            && ($renderedModel['overrides']['background']['revision'] ?? null) === ''
            && ($renderedModel['overrides']['background']['url'] ?? null) === '',
            'An unreadable Theme image must prepare a closed background snapshot while leaving saved CSS editor readiness intact.');
        $initial = \Gallery\Services\custom_css_overrides_state();
        $emptyInitial = $initial;
        $backgroundFailureSave = transport_request([
            'csrf_token'=>'fixture-authority',
            'css_override_action'=>'save',
            'css_override_text'=>'.css-only-after-image-read-failure { color: green; }',
            'css_override_revision'=>$initial['revision'],
        ]);
        transport_require($backgroundFailureSave['status'] === 200 && $backgroundFailureSave['body']['ok']
            && $backgroundFailureSave['body']['state']['text'] === '.css-only-after-image-read-failure { color: green; }'
            && $backgroundFailureSave['body']['background'] === null,
            'CSS-only Save must succeed even when the follow-up Theme image read remains unavailable.');
        $GLOBALS['transport_background_unavailable'] = false;
        $initial = $backgroundFailureSave['body']['state'];
        $valid = ['csrf_token' => 'fixture-authority', 'css_override_action' => 'save', 'css_override_text' => '.saved { color:red; }', 'css_override_revision' => $initial['revision'], 'reset_custom_css' => '1'];
        foreach ([[$valid, false], [array_replace($valid, ['csrf_token'=>'invalid']), true], [array_diff_key($valid, ['csrf_token'=>true]), true]] as [$post, $authorized]) {
            $result = transport_request($post, $authorized);
            transport_require($result['denied'] && $result['status'] === 403 && \Gallery\Services\custom_css_overrides_state() === $initial, 'Admin/CSRF refusal must precede every file write');
        }
        $saved = transport_request($valid);
        transport_require($saved['status'] === 200 && $saved['body']['ok'] && $saved['body']['state']['text'] === $valid['css_override_text'] && $saved['body']['background']['available'] === false, 'explicit CSS-only save must preserve its installed snapshot and return the reviewed background model');
        transport_require(file_get_contents($root . '/public/assets/custom.css') === '.installed { color:blue; }', 'editor POST must preempt unrelated reset fields');
        $snapshot = $saved['body']['state'];
        $stale = transport_request($valid);
        transport_require($stale['status'] === 409 && !$stale['body']['ok'] && \Gallery\Services\custom_css_overrides_state() === $snapshot, 'stale request must expose HTTP 409 without overwriting current CSS');
        $noFileReplace = transport_request([
            'csrf_token'=>'fixture-authority',
            'css_override_action'=>'save',
            'css_override_text'=>$snapshot['text'] . "\n/* no file may publish */",
            'css_override_revision'=>$snapshot['revision'],
            'theme_background_revision'=>str_repeat('b',64),
            'theme_background_operation'=>'replace',
            'theme_background_target'=>'theme',
        ], true, ['theme_background_file'=>['name'=>'','tmp_name'=>'','error'=>UPLOAD_ERR_NO_FILE,'size'=>0]]);
        transport_require($noFileReplace['status'] === 422 && is_array($noFileReplace['body'])
            && !$noFileReplace['body']['ok'] && is_string($noFileReplace['body']['message'])
            && trim($noFileReplace['body']['message']) !== ''
            && $noFileReplace['body']['state'] === null && $noFileReplace['body']['background'] === null
            && \Gallery\Services\custom_css_overrides_state() === $snapshot,
            'Explicit Replace with PHP UPLOAD_ERR_NO_FILE must return actionable failure and preserve saved CSS.');
        foreach (['css_override_text', 'css_override_revision', 'css_override_action'] as $field) {
            $result = transport_request(array_replace($valid, [$field=>['invalid']]));
            transport_require($result['status'] === 422 && !$result['body']['ok'] && \Gallery\Services\custom_css_overrides_state() === $snapshot, 'malformed explicit transport must be refused: ' . $field);
        }
        $missingBackgroundRevision = transport_request($valid, true, ['theme_background_file'=>['name'=>'draft.png','tmp_name'=>'/fixture/upload','error'=>UPLOAD_ERR_OK,'size'=>12]]);
        transport_require($missingBackgroundRevision['status'] === 422 && !$missingBackgroundRevision['body']['ok'] && \Gallery\Services\custom_css_overrides_state() === $snapshot, 'background upload without its optimistic revision must be refused before changing CSS');
        $reload = transport_request(['csrf_token'=>'fixture-authority','css_override_action'=>'reload']);
        transport_require($reload['status'] === 200 && $reload['body']['state'] === $snapshot, 'protected reload must return saved text without requiring a draft');
        $GLOBALS['transport_theme_reset'] = false;
        transport_request(['csrf_token'=>'fixture-authority','reset_theme_overrides'=>'1','css_override_text'=>'']);
        transport_require($GLOBALS['transport_theme_reset'] && \Gallery\Services\custom_css_overrides_state() === $snapshot, 'an unrelated Theme reset with empty editor field must not clear overrides');
        $clear = ['csrf_token'=>'fixture-authority','css_override_action'=>'clear','css_override_text'=>$snapshot['text'],'css_override_revision'=>$snapshot['revision']];
        $unconfirmed = transport_request($clear);
        transport_require($unconfirmed['status'] === 422 && \Gallery\Services\custom_css_overrides_state() === $snapshot, 'clear requires separate explicit confirmation');
        $confirmed = transport_request($clear + ['css_override_clear_confirm'=>'1']);
        transport_require($confirmed['status'] === 200 && $confirmed['body']['state'] === $emptyInitial, 'confirmed clear affects only the override layer');
        echo "PASS CSS override protected transport\n";
    } finally {
        foreach (new DirectoryIterator($root . '/public/assets') as $entry) if ($entry->isFile()) unlink($entry->getPathname());
        unlink($root . '/app/services/custom_css/visual_background_save.php');
        rmdir($root . '/app/services/custom_css');
        unlink($root . '/app/services/custom_css.php');
        foreach (array_reverse($directories) as $directory) rmdir($root . $directory);
    }
}
