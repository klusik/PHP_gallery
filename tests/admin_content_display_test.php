<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_content_display_test.php
 * Module Type: Regression Test
 * Purpose: Verify compact Content and display forms and isolated GPS reset semantics.
 * Responsibilities:
 *   - Render production controls from disposable prepared models without installation data.
 *   - Preserve feature/readiness gates, POST authority and owned return destinations.
 *   - Prove bulk override reset preserves the independent global GPS display preference.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape fixture presentation strings.
     * @param string $value Untrusted prepared text.
     * @return string Escaped markup value.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Build deterministic route destinations without configuration reads.
     * @param string $route Existing route identifier.
     * @param array<string,mixed> $params Prepared query parameters.
     * @return string Local fixture destination.
     */
    function url_for(string $route, array $params = []): string
    {
        return '/index.php?' . http_build_query(['page' => $route] + $params);
    }

    /**
     * Provide observable authority markup to the production forms.
     * @return string Disposable hidden CSRF input.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="content-fixture">';
    }

    /**
     * Stop denied fixture requests before any storage observation.
     * @return void Records authentication or throws on explicit denial.
     */
    function require_admin(): void
    {
        $GLOBALS['content_auth']++;
        if ($GLOBALS['content_denied']) {
            throw new \RuntimeException('fixture authentication refusal', 401);
        }
    }

    /**
     * Record the mandatory CSRF boundary before fixture writes.
     * @return void Increments the request guard count.
     */
    function verify_csrf(): void
    {
        $GLOBALS['content_csrf']++;
    }

    /**
     * Supply the isolated fixture request method.
     * @return string Current GET or POST fixture transport.
     */
    function request_method(): string
    {
        return $GLOBALS['content_method'];
    }

    /**
     * Record safe notices without writing a live session.
     * @param string $key Notice namespace.
     * @param string|null $message Optional new notice.
     * @return string|null Stored fixture notice.
     */
    function flash_message(string $key, ?string $message = null): ?string
    {
        if ($message !== null) {
            $GLOBALS['content_notice'] = $message;
        }
        return $GLOBALS['content_notice'];
    }

    /**
     * Capture controller-selected navigation without leaving the isolated request.
     * @param string $url Prepared fallback destination.
     * @return void Stops fixture execution with a redirect sentinel.
     */
    function redirect_to(string $url): void
    {
        $GLOBALS['content_redirect'] = $url;
        throw new \RuntimeException('fixture redirect', 302);
    }
}

namespace Gallery\Services {
    /**
     * Supply the public-search fixture capability without consulting persisted configuration.
     * @param string $feature Canonical optional capability identifier.
     * @return bool Injected effective fixture availability.
     */
    function feature_capability_effective_enabled(string $feature): bool
    {
        return $GLOBALS['content_search_available'];
    }

    /**
     * Record public-search preference updates in disposable state.
     * @param bool $enabled Requested public-search preference.
     * @return void Writes no installation settings.
     */
    function set_public_home_search_enabled(bool $enabled): void
    {
        $GLOBALS['content_setting_writes']['search'] = $enabled;
    }

    /**
     * Read the updated public-search fixture preference for bounded audit context.
     * @return bool Current disposable search preference.
     */
    function public_home_search_enabled(): bool
    {
        return $GLOBALS['content_setting_writes']['search'] ?? false;
    }

    /**
     * Record rewrite preference updates without changing application configuration.
     * @param bool $enabled Requested clean-URL preference.
     * @return void Writes only fixture state.
     */
    function set_url_rewrite_enabled(bool $enabled): void
    {
        $GLOBALS['content_setting_writes']['rewrite'] = $enabled;
    }

    /**
     * Record crawler safety preference updates without touching settings storage.
     * @param bool $enabled Requested crawler guard preference.
     * @return void Writes only fixture state.
     */
    function set_seo_request_guard_enabled(bool $enabled): void
    {
        $GLOBALS['content_setting_writes']['crawler'] = $enabled;
    }

    /**
     * Read the updated crawler fixture preference for audit context.
     * @return bool Current disposable crawler preference.
     */
    function seo_request_guard_enabled(): bool
    {
        return $GLOBALS['content_setting_writes']['crawler'] ?? false;
    }

    /**
     * Record sampled crawler logging preferences without producing real events.
     * @param bool $enabled Requested sampling preference.
     * @return void Writes only fixture state.
     */
    function set_seo_request_guard_logging_enabled(bool $enabled): void
    {
        $GLOBALS['content_setting_writes']['crawler_logging'] = $enabled;
    }

    /**
     * Read the updated sampled logging fixture preference.
     * @return bool Current disposable logging preference.
     */
    function seo_request_guard_logging_enabled(): bool
    {
        return $GLOBALS['content_setting_writes']['crawler_logging'] ?? false;
    }

    /**
     * Simulate public-path maintenance at its domain boundary without filesystem or database writes.
     * @return array{galleries:int,images:int} Synthetic updated path counts.
     */
    function regenerate_public_paths(): array
    {
        if ($GLOBALS['content_paths_fail']) {
            throw new \RuntimeException('synthetic regeneration refusal');
        }
        $GLOBALS['content_setting_writes']['paths'] = true;
        return ['galleries' => 2, 'images' => 7];
    }

    /**
     * Interpolate plain fixture translation prose.
     * @param string $key Translation identity.
     * @param string $fallback Default prose.
     * @param array<string,mixed> $parameters Prepared placeholder values.
     * @return string Unescaped presentation text.
     */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        foreach ($parameters as $name => $value) {
            $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
        }
        return $fallback;
    }

    /**
     * Supply schema observations without inspecting a live database.
     * @return array{state:string} Injected GPS override storage state.
     */
    function presentation_gps_override_schema_status(): array
    {
        $GLOBALS['content_schema_reads']++;
        return ['state' => $GLOBALS['content_schema_state']];
    }

    /**
     * Interpret the explicit available fixture state.
     * @param array{state:string} $status Prepared schema observation.
     * @return bool Whether required storage is confirmed available.
     */
    function schema_inspection_is_available(array $status): bool
    {
        return $status['state'] === 'available';
    }

    /**
     * Interpret the explicit unknown fixture state.
     * @param array{state:string} $status Prepared schema observation.
     * @return bool Whether storage observation failed.
     */
    function schema_inspection_is_unknown(array $status): bool
    {
        return $status['state'] === 'unknown';
    }

    /**
     * Retain degradation reporting at the service seam without persisting logs.
     * @param array{state:string} $status Prepared schema state.
     * @param string $operation Bounded operation identity.
     * @return void Records a fixture observation only.
     */
    function presentation_schema_log_degraded(array $status, string $operation): void
    {
        $GLOBALS['content_degraded'] = [$status, $operation];
    }

    /**
     * Read the independent global GPS preference.
     * @return bool Current fixture default.
     */
    function exif_gps_default_enabled(): bool
    {
        return $GLOBALS['content_gps_default'];
    }

    /**
     * Record global GPS preference changes at the domain boundary.
     * @param bool $enabled Requested independent default.
     * @return void Updates only disposable fixture state.
     */
    function set_exif_gps_default_enabled(bool $enabled): void
    {
        $GLOBALS['content_default_writes']++;
        $GLOBALS['content_gps_default'] = $enabled;
    }

    /**
     * Simulate reset of explicit gallery overrides without touching galleries.
     * @return int Number of fixture overrides removed.
     */
    function reset_all_gallery_gps_map_overrides(): int
    {
        $GLOBALS['content_reset_writes']++;
        return 5;
    }

    /**
     * Capture bounded controller audit context without creating real log events.
     * @param string $level Severity.
     * @param string $event Stable event identity.
     * @param string $message Safe event prose.
     * @param array<string,mixed> $context Bounded observation fields.
     * @param array<string,mixed> $options Optional logging metadata.
     * @return void Stores fixture event context.
     */
    function admin_log_event(string $level, string $event, string $message, array $context = [], array $options = []): void
    {
        $GLOBALS['content_log'] = [$level, $event, $message, $context, $options];
    }
}

namespace Gallery\Controllers {
    /**
     * Record the GET refusal used by the production mutation boundary.
     * @return void Marks the fixture request as not found.
     */
    function cms_not_found(): void
    {
        $GLOBALS['content_not_found'] = true;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/views/admin_ui.php';
    require_once dirname(__DIR__) . '/app/views/admin_dashboard.php';
    require_once dirname(__DIR__) . '/app/views/admin_dashboard_sections.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_dashboard.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_galleries_bulk.php';

    /**
     * Fail an observable display or transport contract.
     * @param bool $condition Expected invariant.
     * @param string $message Fixed failure description.
     * @return void Throws when the contract is broken.
     */
    function content_display_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Render and parse the actual compact Content and display controls.
     * @param array<string,mixed> $model Prepared feature and compatibility state.
     * @return DOMXPath Queryable production-rendered fixture DOM.
     */
    function content_display_render(array $model): DOMXPath
    {
        ob_start();
        try {
            \Gallery\Views\view_render_admin_dashboard_content_display_tools($model);
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<!doctype html><html><meta charset="utf-8"><body>' . $html . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }

    /**
     * Reset all isolated request observations before controller invocation.
     * @param bool $default Current persisted global fixture preference.
     * @return void Clears fixture counters and request variables only.
     */
    function content_display_reset(bool $default): void
    {
        $GLOBALS['content_auth'] = $GLOBALS['content_csrf'] = $GLOBALS['content_schema_reads'] = $GLOBALS['content_default_writes'] = $GLOBALS['content_reset_writes'] = 0;
        $GLOBALS['content_gps_default'] = $default;
        $GLOBALS['content_denied'] = $GLOBALS['content_not_found'] = false;
        $GLOBALS['content_search_available'] = true;
        $GLOBALS['content_paths_fail'] = false;
        $GLOBALS['content_setting_writes'] = [];
        $GLOBALS['content_schema_state'] = 'available';
        $GLOBALS['content_method'] = 'POST';
        $GLOBALS['content_redirect'] = $GLOBALS['content_notice'] = '';
        $_GET = [];
        $_POST = ['maintenance_return' => 'content'];
    }

    /**
     * Invoke a production mutation handler and capture only its redirect sentinel.
     * @param callable():void|null $handler Controller under observation, or the default GPS handler.
     * @return void Rejects unexpected exceptions instead of masking controller errors.
     */
    function content_display_gps_request(?callable $handler = null): void
    {
        try {
            $handler ??= 'Gallery\\Controllers\\cms_admin_exif_gps_settings';
            $handler();
        } catch (RuntimeException $exception) {
            content_display_assert($exception->getCode() === 302, 'Controller failed before selecting its fallback destination.');
        }
    }

    $model = ['feature_enabled' => ['public_search' => true, 'gallery_maps' => true, 'exif_gallery_date_suggestions' => true], 'gps_map_override_ready' => true, 'gallery_date_range_ready' => true, 'exif_gps_default_enabled' => true, 'exif_gps_override_count' => 5, 'public_home_search_enabled' => true, 'url_rewrite_enabled' => true, 'url_rewrite_compatibility' => ['enabled' => true, 'status' => 'unsupported', 'reasons' => ['<script>fixture reason</script>']]];
    $dom = content_display_render($model);
    foreach (['public-display', 'gallery-metadata', 'urls-protection'] as $group) {
        content_display_assert($dom->query('//*[@data-admin-content-group="' . $group . '"]')->length === 1, 'Content tools lost an owned compact group.');
    }
    foreach ($dom->query('//form') as $form) {
        content_display_assert(strtolower($form->getAttribute('method')) === 'post', 'Content mutations must stay POST-only.');
        content_display_assert($dom->query('.//input[@name="csrf_token" and @value="content-fixture"]', $form)->length === 1, 'Content form lost its CSRF field.');
        content_display_assert($dom->query('.//input[@name="maintenance_return" and @value="content"]', $form)->length === 1, 'Content form lost its owned return context.');
    }
    $reset = $dom->query('//form[.//input[@name="reset_gallery_overrides_only" and @value="1"]]');
    content_display_assert($reset->length === 1, 'Gallery override reset must have its own explicit form.');
    content_display_assert($dom->query('.//input[@name="reset_gallery_overrides" and @value="1"]', $reset->item(0))->length === 1 && $dom->query('.//input[@name="exif_gps_default_enabled"]', $reset->item(0))->length === 0, 'Reset must not submit or rewrite the independent global preference.');
    content_display_assert($dom->query('//form[.//input[@name="exif_gps_default_enabled"] and .//input[@name="reset_gallery_overrides"]]')->length === 0, 'Default editing must not contain the bulk reset action.');
    content_display_assert($dom->query('//script')->length === 0 && str_contains($dom->document->textContent, '<script>fixture reason</script>'), 'Rewrite compatibility reasons must be preserved as escaped prose.');
    content_display_assert($dom->query('//*[contains(@class,"is-alert") and contains(.,"support was not detected")]')->length >= 1, 'Unsupported rewrite warning must remain visible.');
    content_display_assert($dom->query('//a[@href="/index.php?page=admin_gallery_dates"]')->length === 1 && $dom->query('//form[@action="/index.php?page=admin_regenerate_paths"]')->length === 1, 'Metadata date workflow or public-path action was lost.');
    content_display_assert(str_contains($reset->item(0)->textContent, '5'), 'The bulk reset must show its affected override count.');
    foreach (['public_home_search_enabled', 'exif_gps_default_enabled', 'url_rewrite_enabled', 'seo_request_guard_enabled', 'seo_request_guard_logging_enabled'] as $name) {
        content_display_assert($dom->query('//input[@name="' . $name . '"]')->length === 1, 'Content preference changed its existing submitted field name.');
    }
    content_display_assert($dom->query('//details//*[contains(@class,"is-alert")]')->length === 0, 'Unsupported rewrite guidance must remain outside collapsed details.');

    $zero = content_display_render(array_replace($model, ['exif_gps_override_count' => 0]));
    content_display_assert($zero->query('//input[@name="reset_gallery_overrides_only"]')->length === 0, 'Zero overrides must not expose a misleading bulk action.');
    $disabled = content_display_render(array_replace($model, ['feature_enabled' => ['public_search' => false, 'gallery_maps' => false, 'exif_gallery_date_suggestions' => false], 'gps_map_override_ready' => false, 'gallery_date_range_ready' => false]));
    content_display_assert($disabled->query('//input[@name="public_home_search_enabled" or @name="exif_gps_default_enabled" or @name="reset_gallery_overrides_only"]')->length === 0 && $disabled->query('//a[@href="/index.php?page=admin_gallery_dates"]')->length === 0, 'Disabled or unready optional display controls escaped their existing gates.');
    $datesDisabled = content_display_render(array_replace($model, ['feature_enabled' => ['public_search' => true, 'exif_gallery_date_suggestions' => false]]));
    content_display_assert($datesDisabled->query('//a[@href="/index.php?page=admin_gallery_dates"]')->length === 0, 'Date readiness must not override the date-suggestion capability switch.');
    $unready = content_display_render(array_replace($model, ['gps_map_override_ready' => false, 'gallery_date_range_ready' => false]));
    content_display_assert($unready->query('//input[@name="exif_gps_default_enabled" or @name="reset_gallery_overrides_only"]')->length === 0 && $unready->query('//a[@href="/index.php?page=admin_gallery_dates"]')->length === 0, 'Enabled capabilities must still respect unresolved storage readiness.');

    foreach ([true, false] as $default) {
        content_display_reset($default);
        $_POST += ['reset_gallery_overrides' => '1', 'reset_gallery_overrides_only' => '1'];
        $_POST['exif_gps_default_enabled'] = $default ? '0' : '1';
        content_display_gps_request();
        content_display_assert($GLOBALS['content_gps_default'] === $default && $GLOBALS['content_default_writes'] === 0 && $GLOBALS['content_reset_writes'] === 1, 'Reset-only request changed the independent GPS default.');
        content_display_assert($GLOBALS['content_auth'] === 1 && $GLOBALS['content_csrf'] === 1 && str_contains($GLOBALS['content_redirect'], 'maintenance_tab=content'), 'Reset request lost its guards or Content return destination.');
    }
    foreach (['missing', 'unknown'] as $state) {
        content_display_reset(true);
        $GLOBALS['content_schema_state'] = $state;
        $_POST += ['reset_gallery_overrides' => '1', 'reset_gallery_overrides_only' => '1'];
        content_display_gps_request();
        content_display_assert($GLOBALS['content_default_writes'] === 0 && $GLOBALS['content_reset_writes'] === 0 && str_contains($GLOBALS['content_redirect'], 'maintenance_tab=content'), 'Unverified override storage permitted writes or lost Content navigation.');
    }
    content_display_reset(false);
    $_POST['exif_gps_default_enabled'] = '1';
    content_display_gps_request();
    content_display_assert($GLOBALS['content_gps_default'] && $GLOBALS['content_default_writes'] === 1 && $GLOBALS['content_reset_writes'] === 0, 'Ordinary default editing must remain independent of bulk reset.');
    content_display_reset(false);
    $_POST += ['exif_gps_default_enabled' => '1', 'reset_gallery_overrides' => '1'];
    content_display_gps_request();
    content_display_assert($GLOBALS['content_gps_default'] && $GLOBALS['content_default_writes'] === 1 && $GLOBALS['content_reset_writes'] === 1, 'Legacy combined default-and-reset submissions must remain compatible.');
    content_display_reset(true);
    $_POST = [];
    content_display_gps_request();
    content_display_assert($GLOBALS['content_redirect'] === '/index.php?page=admin', 'Unmarked legacy requests must keep their existing fallback destination.');
    content_display_reset(true);
    $GLOBALS['content_method'] = 'GET';
    content_display_gps_request();
    content_display_assert($GLOBALS['content_not_found'] && $GLOBALS['content_schema_reads'] === 0 && $GLOBALS['content_csrf'] === 0, 'GET must never inspect or mutate GPS override storage.');
    content_display_reset(true);
    $GLOBALS['content_denied'] = true;
    try {
        \Gallery\Controllers\cms_admin_exif_gps_settings();
        throw new RuntimeException('Denied request reached controller work.');
    } catch (RuntimeException $exception) {
        content_display_assert($exception->getCode() === 401 && $GLOBALS['content_schema_reads'] === 0 && $GLOBALS['content_reset_writes'] === 0, 'Authentication must precede all GPS storage work.');
    }
    foreach (['cms_admin_url_rewrite', 'cms_admin_public_search_settings', 'cms_admin_seo_guard_settings', 'cms_admin_regenerate_paths'] as $handler) {
        content_display_reset(true);
        content_display_gps_request('Gallery\\Controllers\\' . $handler);
        content_display_assert($GLOBALS['content_auth'] === 1 && $GLOBALS['content_csrf'] === 1 && str_contains($GLOBALS['content_redirect'], 'dashboard_tab=maintenance') && str_contains($GLOBALS['content_redirect'], 'maintenance_tab=content'), 'Content mutation lost its guards or owned dashboard return: ' . $handler);
    }
    content_display_reset(true);
    $GLOBALS['content_search_available'] = false;
    content_display_gps_request('Gallery\\Controllers\\cms_admin_public_search_settings');
    content_display_assert($GLOBALS['content_setting_writes'] === [] && str_contains($GLOBALS['content_redirect'], 'maintenance_tab=content'), 'Disabled public search must refuse mutation while retaining Content navigation.');
    content_display_reset(true);
    $GLOBALS['content_paths_fail'] = true;
    content_display_gps_request('Gallery\\Controllers\\cms_admin_regenerate_paths');
    content_display_assert($GLOBALS['content_setting_writes'] === [] && str_contains($GLOBALS['content_redirect'], 'maintenance_tab=content'), 'Path regeneration refusal lost its Content return destination.');
    echo "Content and display production controls: PASS\n";
}
