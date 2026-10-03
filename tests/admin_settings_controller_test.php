<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_settings_controller_test.php
 * Module Type: Regression Test
 * Purpose: Exercise Settings HTTP boundaries without installation or database mutations.
 * Responsibilities: Verify category whitelists, validation, legacy URLs and JSON completions.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Enforce fixture authentication. @return void Throws before any write when denied. */
    function require_admin(): void { if ($GLOBALS['settings_denied'] === 'auth') throw new \RuntimeException('auth'); }
    /** Enforce fixture CSRF validation. @return void Throws before any write when denied. */
    function verify_csrf(): void { if ($GLOBALS['settings_denied'] === 'csrf') throw new \RuntimeException('csrf'); }
    /** Return the fixture transport method. @return string GET or POST. */
    function request_method(): string { return $GLOBALS['settings_method']; }
    /** Return the fixture administrator. @return array{id:int} Fixture identity. */
    function current_user(): array { return ['id' => 1]; }
    /** Record a cookie intent without emitting browser cookies. @param array<int,array<string,mixed>> $intents Transport intents. @return void Records the canonical service output. */
    function apply_cookie_intents(array $intents): void { $GLOBALS['settings_cookies'] = $intents; }
    /** Provide the ordinary POST notice seam. @param string $key Notice name. @param ?string $value Optional notice. @return ?string Notice value. */
    function flash_message(string $key, ?string $value = null): ?string { return $value; }
    /** Stop at the fallback redirect boundary. @param string $url Destination. @return never Throws a fixture marker. */
    function redirect_to(string $url): never { throw new \LogicException('redirect:' . $url); }
    /** Build a local fixture URL. @param string $route Route name. @param array<string,mixed> $params Query fields. @return string Local URL. */
    function url_for(string $route, array $params = []): string { return '/index.php?' . http_build_query(['page' => $route] + $params); }
}
namespace Gallery\Services {
    /** Build a small canonical registry seam. @param bool $includeSessionSettings Whether the Admin preference belongs in this surface. @return array<string,array<string,mixed>> Entries. */
    function admin_settings_registry(bool $includeSessionSettings = false): array {
        $entries = [];
        foreach (['base_url' => 'site', 'site_name' => 'general', 'public_language' => 'general', 'url_rewrite_enabled' => 'general', 'thumbnail' => 'media'] as $id => $group) {
            $entries[$id] = ['id' => $id, 'group' => $group, 'current' => $GLOBALS['settings_values'][$id], 'central_editable' => true, 'input_type' => $id === 'url_rewrite_enabled' ? 'checkbox' : 'text'];
        }
        if ($includeSessionSettings) $entries['admin_language'] = ['id' => 'admin_language', 'group' => 'general', 'current' => $GLOBALS['settings_values']['admin_language'], 'central_editable' => true, 'input_type' => 'select', 'validation' => ['allowed' => ['en', 'cs']]];
        $entries['secret'] = ['id' => 'secret', 'group' => 'general', 'current' => 'Configured', 'central_editable' => false];
        return $entries;
    }
    /** Normalize before mutation in the controller fixture. @param array<string,mixed> $entry Whitelisted entry. @param string|bool|int|null $value Submitted scalar. @return string Validated scalar. */
    function admin_settings_normalize_editable_value(array $entry, mixed $value): string {
        if ($value === 'invalid') throw new \InvalidArgumentException('Invalid value');
        return $entry['input_type'] === 'checkbox' ? (!empty($value) ? '1' : '0') : (string) $value;
    }
    /** Record one canonical save. @param string $id Identifier. @param string $value Normalized scalar. @return void Persists to the isolated fixture only. */
    function admin_settings_save_editable_value(string $id, mixed $value): void {
        if ($GLOBALS['settings_fail'] === $id) throw new \RuntimeException('private fixture exception');
        $GLOBALS['settings_writes'][] = $id;
        $GLOBALS['settings_values'][$id] = $value;
    }
    /** Normalize fixture category input. @param string|null $section Category input. @return string Known category. */
    function admin_settings_section_normalize(mixed $section): string { return in_array($section, ['general', 'site', 'media', 'advanced'], true) ? $section : 'general'; }
    /** Provide category presentation. @return array<string,array<string,string>> Sections. */
    function admin_settings_sections(): array {
        $sections = [];
        foreach (['general', 'site', 'media', 'advanced'] as $id) $sections[$id] = ['label_key' => '', 'label' => $id, 'description_key' => '', 'description' => $id];
        return $sections;
    }
    /** Return a stable panel ID. @param string $section Category. @return string Panel ID. */
    function admin_settings_section_id(string $section): string { return 'settings-' . $section; }
    /** Build fixture deep links. @param ?string $section Category. @param ?string $return Return context. @param bool $fragment Fragment mode. @return string Local URL. */
    function admin_settings_url(?string $section = null, ?string $return = null, bool $fragment = false): string { return '/index.php?page=admin_settings&section=' . $section; }
    /** Return maintained language names. @return array<string,array{name:string}> Presentations. */
    function translation_language_presentation(): array { return ['en' => ['name' => 'English'], 'cs' => ['name' => 'Čeština']]; }
    /** Provide the already prepared selector model. @return array<string,mixed> Selector model. */
    function translation_public_language_selector_view_data(): array { return []; }
    /** Return safe cookie intents from the language owner seam. @param string $language Admin preference. @return array<int,array<string,mixed>> Fixture cookie intent. */
    function translation_admin_language_cookie_intents(string $language): array { return [['language' => $language]]; }
    /** Return a translated public fallback. @param string $key Key. @param string $fallback Public text. @return string Fallback text. */
    function t(string $key, string $fallback = ''): string { return $fallback; }
    /** Record bounded controller logs. @param string $level Severity. @param string $event Stable event. @param string $message Safe summary. @param array<string,mixed> $context Safe context. @param array<string,mixed> $metadata Metadata. @return void Captures emitted context. */
    function admin_log_event(string $level, string $event, string $message, array $context, array $metadata): void { $GLOBALS['settings_log'] = $context; }
}
namespace Gallery\Views {
    /** Capture the full-page model. @param array<string,mixed> $model Prepared model. @return void Stores without rendering installation state. */
    function view_render_admin_settings_page(array $model): void { $GLOBALS['settings_model'] = $model; }
    /** Render a synthetic fragment at the existing view boundary. @param string $section Category. @param array<string,mixed> $entries Registry entries. @param array<string,mixed> $errors Errors. @param array<string,mixed> $submitted Values. @param array<string,mixed> $model Page model. @return void Emits only the requested category marker. */
    function view_render_admin_settings_section(string $section, array $entries, array $errors, array $submitted, array $model): void { echo '<form data-fixture-section="' . $section . '"></form>'; }
}
namespace {
    require_once __DIR__ . '/../app/helpers_mutation.php';
    require_once __DIR__ . '/../app/controllers/admin_settings.php';
    /** Assert an observed controller boundary. @param bool $condition Expected behavior. @param string $message Failure detail. @return void Throws a test failure. */
    function settings_check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    /** Reset all disposable request state. @return void Clears fixture writes and request headers. */
    function settings_reset(): void {
        $GLOBALS['settings_values'] = ['base_url' => 'http://fixture.test/gallery', 'site_name' => 'Original', 'admin_language' => 'en', 'public_language' => 'en', 'url_rewrite_enabled' => '1', 'thumbnail' => 'progressive'];
        $GLOBALS['settings_writes'] = $GLOBALS['settings_cookies'] = $GLOBALS['settings_log'] = [];
        $GLOBALS['settings_denied'] = $GLOBALS['settings_fail'] = '';
        $GLOBALS['settings_method'] = 'POST';
        $_GET = ['section' => 'general'];
        $_POST = ['settings' => ['base_url' => 'http://fixture.test/gallery', 'site_name' => 'Updated', 'admin_language' => 'cs', 'public_language' => 'en']];
        $_SESSION = [];
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
    }
    /** Run the production controller and decode its JSON output. @return array<string,mixed> Completion envelope. */
    function settings_request(): array {
        ob_start();
        try { \Gallery\Controllers\cms_admin_settings(); return json_decode((string) ob_get_contents(), true, 512, JSON_THROW_ON_ERROR); }
        finally { ob_end_clean(); }
    }

    settings_reset();
    $result = settings_request();
    settings_check($result['ok'] && $result['mutation']['type'] === 'settings.update' && str_contains($result['html'], 'general'), 'JSON save must return the canonical envelope and category fragment');
    settings_check(!in_array('base_url', $GLOBALS['settings_writes'], true), 'An unchanged URL must not rewrite config');
    settings_check(!in_array('thumbnail', $GLOBALS['settings_writes'], true) && !in_array('secret', $GLOBALS['settings_writes'], true) && !in_array('unknown', $GLOBALS['settings_writes'], true), 'Category and editability whitelist must reject injected writes');
    settings_check($GLOBALS['settings_values']['url_rewrite_enabled'] === '0', 'Omitted checkbox must retain standard unchecked semantics');
    settings_check($GLOBALS['settings_values']['admin_language'] === 'cs' && $GLOBALS['settings_values']['public_language'] === 'en' && $GLOBALS['settings_cookies'] === [['language' => 'cs']] && $result['language_url'] !== '', 'Admin and visitor languages must remain independent');
    foreach (['thumbnail', 'secret', 'unknown'] as $injectedId) {
        settings_reset(); $_POST['settings'][$injectedId] = 'injected';
        $result = settings_request();
        settings_check(!$result['ok'] && isset($result['errors'][$injectedId]) && $GLOBALS['settings_writes'] === [], 'Wrong-section, non-editable, and unknown settings must reject the complete submission');
    }
    foreach (['auth', 'csrf'] as $boundary) {
        settings_reset(); $GLOBALS['settings_denied'] = $boundary;
        try { settings_request(); throw new \LogicException('Boundary accepted'); }
        catch (\RuntimeException $exception) { settings_check($exception->getMessage() === $boundary && $GLOBALS['settings_writes'] === [], 'Authentication and CSRF must precede writes'); }
    }
    settings_reset(); $_POST['settings']['site_name'] = 'invalid';
    $result = settings_request();
    settings_check(!$result['ok'] && http_response_code() === 422 && isset($result['errors']['site_name']) && $GLOBALS['settings_writes'] === [] && !isset($result['html']), 'Validation must complete before the first write and keep the client draft');
    settings_reset(); $_POST['settings']['base_url'] = 'http://fixture.test/new';
    $result = settings_request();
    settings_check(end($GLOBALS['settings_writes']) === 'base_url' && $result['address_url'] === 'http://fixture.test/new/index.php?page=admin_settings&section=general', 'Changed installation URL must save last and expose an explicit continuation link');
    settings_reset(); $_POST['settings']['base_url'] = 'http://fixture.test/new'; $GLOBALS['settings_fail'] = 'site_name';
    $result = settings_request();
    settings_check(!$result['ok'] && $GLOBALS['settings_values']['base_url'] === 'http://fixture.test/gallery' && !str_contains(json_encode($result), 'private fixture exception') && !isset($GLOBALS['settings_log']['exception']), 'A failed ordinary save must not move the URL or leak private exceptions');
    settings_reset(); unset($_POST['settings']['admin_language'], $_POST['settings']['base_url']);
    $result = settings_request();
    settings_check($result['ok'] && $GLOBALS['settings_values']['admin_language'] === 'en' && !in_array('base_url', $GLOBALS['settings_writes'], true), 'Older General clients must preserve newly added language and address fields');
    settings_reset(); $_GET['section'] = 'site'; $_POST['settings'] = ['base_url' => 'http://fixture.test/new'];
    $result = settings_request();
    settings_check($result['ok'] && $result['section'] === 'general' && $GLOBALS['settings_writes'] === ['base_url'], 'Historical site POSTs must keep their narrow whitelist');
    settings_reset(); $_SERVER['HTTP_ACCEPT'] = 'text/html';
    try { settings_request(); throw new \RuntimeException('No fallback redirect'); }
    catch (\LogicException $exception) { settings_check($exception->getMessage() === 'redirect:/index.php?page=admin_settings&section=general', 'No-JavaScript saves must preserve the ordinary redirect fallback'); }
    echo "Admin Settings controller tests passed.\n";
}
