<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/runtime_support_surfaces_test.php
 * Module Type: Regression Test
 * Purpose: Render Admin runtime-support diagnostics deterministically.
 * Responsibilities:
 *   - Check both surfaces without configuration, sessions or database access.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Render both Admin runtime surfaces with deterministic offline lifecycle data.
 * No application configuration, session, database or live request is loaded.
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Use the selected real catalog or mark fallback labels for deterministic view tests.
     *
     * @param string $key Translation identity; fallback runtime keys get a fixture prefix.
     * @param string $fallback English source text used without a live translation catalog.
     * @param array<string,mixed> $parameters Placeholder values from the prepared policy.
     * @return string Plain translated fixture text, deliberately unescaped.
     */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        $catalog = $GLOBALS['runtime_surface_catalog'] ?? null;
        $fallback = is_array($catalog) ? (string) ($catalog[$key] ?? $fallback) : $fallback;
        foreach ($parameters as $name => $value) {
            $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
        }
        return ($catalog === null && str_starts_with($key, 'admin.runtime_support.') ? '[localized] ' : '') . $fallback;
    }
}

namespace Gallery\Core {
    /**
     * Escape fixture presentation with the application's ordinary HTML semantics.
     *
     * @param string $value Plain prepared label or URL.
     * @return string Escaped HTML text/attribute value.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Provide a local dummy route without loading routing configuration.
     *
     * @param string $page Fixed view route identity.
     * @param array<string,mixed> $parameters Optional view query values.
     * @return string Local query URL used only in rendered test markup.
     */
    function url_for(string $page, array $parameters = []): string
    {
        return '/index.php?' . http_build_query(['page' => $page] + $parameters);
    }

    /**
     * Omit unrelated DNG-form authority from a read-only markup fixture.
     *
     * @return string Empty fixture field; this test submits no forms.
     */
    function csrf_field(): string
    {
        return '';
    }
}

namespace Gallery\Views {
    /**
     * Omit unrelated section-introduction markup while rendering real health cards.
     *
     * @param array<string,mixed> $model Prepared section introduction, unused by this test.
     * @return void
     */
    function view_render_admin_tab_intro(array $model): void
    {
    }

    /**
     * Exclude unrelated developer controls without invoking host configuration.
     *
     * @param string $className Presentation wrapper, unused by this test.
     * @param array<string,mixed> $model Prepared dashboard data, unused by this test.
     * @return void
     */
    function view_render_admin_devmode_card(string $className, array $model = []): void
    {
    }
}

namespace {
    require_once __DIR__ . '/support/module_source.php';
    require_once dirname(__DIR__) . '/app/services/runtime_support.php';
    require_once dirname(__DIR__) . '/app/views/admin_dashboard_sections.php';
    require_once dirname(__DIR__) . '/app/views/admin_diagnostics.php';

    /**
     * Fail one shared health contract using a static, non-sensitive description.
     *
     * @param bool $condition Whether the expected observable behavior occurred.
     * @param string $message Description of the contract, never request or host data.
     * @return void
     */
    function runtime_surface_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Capture a real dashboard or diagnostics surface using the same prepared model.
     *
     * @param string $surface Fixed dashboard/diagnostics view selection.
     * @param array<string,mixed> $health Localized runtime_support_health_status() result.
     * @return string Actual HTML emitted by the selected production view.
     */
    function runtime_surface_render(string $surface, array $health): string
    {
        ob_start();
        try {
            $model = ['runtime_support_status' => $health, 'feature_enabled' => [],
                'report_text' => implode("\n", $health['report_lines'])];
            if ($surface === 'dashboard') {
                Gallery\Views\view_render_admin_dashboard_system_tools($model);
            } else {
                Gallery\Views\view_render_admin_diagnostics_page($model);
            }
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Read executable source tokens without comments for lightweight wiring assertions.
     *
     * @param string $relative Repository-relative first-party file under test.
     * @return string Source with comments removed; inspected only, never executed.
     */
    function runtime_surface_source(string $relative): string
    {
        $source = '';
        foreach (token_get_all(module_source(dirname(__DIR__) . '/' . $relative)) as $token) {
            if (!is_array($token)) {
                $source .= $token;
            } elseif (!in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $source .= $token[1];
            }
        }
        return $source;
    }

    /**
     * Normalize a translation's interpolation names for cross-catalog comparison.
     *
     * @param string $text One plain translation template.
     * @return list<string> Sorted placeholder names, retaining repeated placeholders.
     */
    function runtime_surface_placeholders(string $text): array
    {
        preg_match_all('/\{([A-Za-z0-9_]+)\}/', $text, $matches);
        $names = $matches[1];
        sort($names, SORT_STRING);
        return $names;
    }

    $date = new DateTimeImmutable('2026-09-20T12:00:00Z');
    $cases = [80134 => ['end_of_life', true], 80200 => ['security_only', true],
        80330 => ['security_only', false], 80400 => ['active', false], 80600 => ['unknown', true]];
    foreach ($cases as $version => [$state, $action]) {
        $health = Gallery\Services\runtime_support_health_status($version, $date);
        runtime_surface_assert($health['policy'] === Gallery\Services\runtime_support_status($version, $date),
            'Health preparation must preserve the canonical policy unchanged.');
        runtime_surface_assert(count($health['report_lines']) === 7 && strlen(json_encode($health, JSON_THROW_ON_ERROR)) < 3500,
            'Shared health card/report must remain bounded.');
        foreach (['dashboard', 'diagnostics'] as $surface) {
            $html = runtime_surface_render($surface, $health);
            runtime_surface_assert(substr_count($html, 'data-runtime-support ') === 1, 'Each surface must render exactly one runtime card.');
            runtime_surface_assert(str_contains($html, 'data-runtime-state="' . $state . '"'), 'Rendered lifecycle state must follow policy.');
            runtime_surface_assert(str_contains($html, Gallery\Core\e($health['labels']['summary']))
                && str_contains($html, '[localized] PHP runtime support'), 'Both surfaces must use the centrally localized labels.');
            runtime_surface_assert(str_contains($html, '<span class="admin-tab-badge">Action</span>') === $action,
                'The runtime Action badge must follow the prepared advisory.');
            if ($surface === 'dashboard') {
                runtime_surface_assert(str_contains($html, '<strong>System ready</strong>') === !$action,
                    'Runtime warnings must suppress the otherwise-ready dashboard card.');
            } else {
                runtime_surface_assert(str_contains($html, Gallery\Core\e(implode("\n", $health['report_lines']))),
                    'Diagnostics copy report must preserve the same localized summary and reviewed dates.');
            }
        }
    }
    $unsafeLabels = Gallery\Services\runtime_support_health_status(80134, $date);
    $unsafeLabels['labels']['title'] = '<script>untrusted translation</script>';
    foreach (['dashboard', 'diagnostics'] as $surface) {
        $html = runtime_surface_render($surface, $unsafeLabels);
        runtime_surface_assert(!str_contains($html, '<script>untrusted translation</script>')
            && str_contains($html, '&lt;script&gt;untrusted translation&lt;/script&gt;'), 'Localized labels must be escaped on both surfaces.');
    }

    $english = json_decode((string) file_get_contents(dirname(__DIR__) . '/app/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);
    $runtimeKeys = [];
    foreach (array_keys($english) as $key) {
        if (str_starts_with($key, 'admin.runtime_support.')) {
            $runtimeKeys[] = $key;
        }
    }
    runtime_surface_assert(count($runtimeKeys) === 14, 'The complete runtime catalog requires fourteen canonical labels.');
    foreach (['en', 'cs', 'de', 'sv'] as $language) {
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/lang/' . $language . '.json');
        $catalog = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        runtime_surface_assert(preg_match_all('/"admin\\.runtime_support\\.[a-z_]+"\\s*:/', $source) === count($runtimeKeys),
            'Each runtime translation key must occur once in every maintained JSON catalog.');
        foreach ($runtimeKeys as $key) {
            runtime_surface_assert(isset($catalog[$key]) && is_string($catalog[$key]) && $catalog[$key] !== '',
                'Maintained JSON catalogs must contain every runtime label.');
            runtime_surface_assert(runtime_surface_placeholders($catalog[$key]) === runtime_surface_placeholders($english[$key]),
                'Runtime translation placeholders must agree across all maintained catalogs.');
        }
        if (in_array($language, ['en', 'cs'], true)) {
            $fallback = require dirname(__DIR__) . '/app/lang/' . $language . '.php';
            foreach ($runtimeKeys as $key) {
                runtime_surface_assert(($fallback[$key] ?? null) === $catalog[$key], 'Legacy PHP fallbacks must agree with the maintained runtime labels.');
            }
        }
        $GLOBALS['runtime_surface_catalog'] = $catalog;
        foreach ($cases as $version => [$state, $action]) {
            $health = Gallery\Services\runtime_support_health_status($version, $date);
            runtime_surface_assert($health['labels']['title'] === $catalog['admin.runtime_support.title'],
                'Shared health labels must use the selected maintained catalog.');
            foreach (['dashboard', 'diagnostics'] as $surface) {
                $html = runtime_surface_render($surface, $health);
                runtime_surface_assert(str_contains($html, Gallery\Core\e($catalog['admin.runtime_support.title']))
                    && str_contains($html, Gallery\Core\e($health['labels']['summary'])), 'Both real surfaces must render the selected language.');
            }
        }
    }
    unset($GLOBALS['runtime_surface_catalog']);

    $loader = runtime_surface_source('app/services.php');
    $dashboard = runtime_surface_source('app/services/admin_dashboard.php');
    $controller = runtime_surface_source('app/controllers/admin_diagnostics.php');
    runtime_surface_assert(substr_count($loader, "'/services/runtime_support.php'") === 1, 'Runtime policy must be loaded once through the service entry point.');
    runtime_surface_assert(str_contains($dashboard, "'runtime_support_status' => runtime_support_health_status()"),
        'Dashboard service must prepare the shared runtime model.');
    $authorizationPosition = strpos($controller, 'require_admin();');
    $runtimePosition = strpos($controller, '$runtimeSupport = runtime_support_health_status();');
    runtime_surface_assert(is_int($authorizationPosition) && is_int($runtimePosition) && $authorizationPosition < $runtimePosition
        && str_contains($controller, "'runtime_support_status' => \$runtimeSupport")
        && str_contains($controller, "\$runtimeSupport['report_lines']"), 'Authenticated diagnostics controller must wire the shared card and copy report.');
    foreach (['app/views/admin_dashboard.php', 'app/views/admin_dashboard_sections.php', 'app/views/admin_diagnostics.php'] as $view) {
        $source = runtime_surface_source($view);
        runtime_surface_assert(!str_contains($source, 'runtime_support_status(') && !str_contains($source, 'runtime_support_health_status('),
            'Views must not resolve domain runtime policy.');
    }
    runtime_surface_assert(str_contains(runtime_surface_source('app/views/admin_dashboard.php'), "!empty(\$runtimeSupport['policy']['action_required'])"),
        'Initial Maintenance badge must expose a runtime warning before deferred content opens.');
    runtime_surface_assert(str_contains(runtime_surface_source('app/views/admin_dashboard_sections.php'), "\$migrationPending || !empty(\$runtimeSupport['policy']['action_required'])"),
        'System Health subtab badge must include runtime action state.');
    $registry = require dirname(__DIR__) . '/scripts/audit_registry.php';
    runtime_surface_assert(($registry['php_test_requirements']['session_contention_test.php']['timeout'] ?? null) === 90,
        'The disposable session comparison must have its registered bounded timeout.');
    echo "PASS runtime support five lifecycle cases on both surfaces, four JSON catalogs, two PHP fallbacks, report, escaping and MVC wiring\n";
}
