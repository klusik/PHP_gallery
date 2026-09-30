<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_updates_ui_test.php
 * Module Type: Regression Test
 * Purpose: Verify passive post-update presentation without installing files or contacting GitHub.
 * Responsibilities: Exercise the real controller summary, fragment endpoint and compact page markup.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return the synthetic activated version. @return string Fixture version. */
    function cms_current_version(): string { return '0.113.1'; }
    /** Return disposable CSRF markup. @return string Fixture field. */
    function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="fixture-csrf">'; }
    /** Escape fixture presentation. @param string $value Visible text. @return string HTML-safe text. */
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    /** Record the endpoint authentication boundary. @return void Updates a fixture counter. */
    function require_admin(): void { $GLOBALS['update_ui_auth_checks']++; }
    /** Return the fixture request method. @return string Read-only method. */
    function request_method(): string { return 'GET'; }
    /** Return the fixture updater route. @param string $route Route ID. @return string Local URL. */
    function url_for(string $route): string { return '/index.php?page=' . $route; }
    /** Delegate fixture tabs to the real chrome view.
     * @param list<array<string, mixed>> $tabs Prepared tab entries. @param string $active Selected tab. @return void Emits markup.
     */
    function render_admin_tabs(array $tabs, string $active): void { \Gallery\Views\view_render_admin_tabs($tabs, $active); }
    /** Delegate fixture panels to the real chrome view.
     * @param string $id Panel ID. @param string $html Panel markup. @param bool $active Selected state. @return void Emits markup.
     */
    function render_admin_tab_panel(string $id, string $html, bool $active): void { \Gallery\Views\view_render_admin_tab_panel($id, $html, $active); }
}

namespace Gallery\Services {
    /** Resolve bundled English labels without loading application state.
     * @param string $key Translation key. @param string|array<string, string>|null $fallback Fallback text or parameters.
     * @param array<string, string> $parameters Replacement values. @return string Presentation text.
     */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        static $labels = null;
        $labels ??= array_merge(json_decode((string) file_get_contents(dirname(__DIR__) . '/app/lang/en.json'), true, 512, JSON_THROW_ON_ERROR), require dirname(__DIR__) . '/app/lang/en.php');
        if (is_array($fallback)) { $parameters = $fallback; $fallback = null; }
        $text = (string) ($labels[$key] ?? $fallback ?? $key);
        foreach ($parameters as $name => $value) { $text = str_replace('{' . $name . '}', (string) $value, $text); }
        return $text;
    }
    /** Return the fixture channel. @return bool Stable channel. */
    function application_update_beta_active(): bool { return false; }
    /** Return the fixture beta label. @return string No beta label. */
    function application_update_beta_commit(): string { return ''; }
    /** Return the fixture installer availability.
     * @param string $key Capability identifier. @return bool Fixture preference.
     */
    function feature_capability_effective_enabled(string $key): bool { return $GLOBALS['update_ui_installer_enabled']; }
    /** Return bounded synthetic error details.
     * @param string $error Fixture error. @return array{reference:string} Safe reference.
     */
    function application_update_safe_error(string $error): array { return ['reference' => 'fixture-reference']; }
    /** Require passive metadata use.
     * @param bool $force Whether a remote check was requested. @return array<string, mixed> Cached fixture metadata.
     */
    function application_update_status_for_admin(bool $force): array
    {
        if ($force) { throw new \RuntimeException('Presentation must not force GitHub discovery.'); }
        $GLOBALS['update_ui_passive_reads']++;
        return ['latest_version' => '0.113.1', 'current_version' => '0.112.0', 'update_available' => true];
    }
}

namespace {
    require_once __DIR__ . '/../app/views/admin_chrome.php';
    require_once __DIR__ . '/../app/views/admin_updates.php';
    require_once __DIR__ . '/../app/controllers/updates.php';

    /** Require an observable presentation behavior.
     * @param bool $condition Expected behavior. @param string $message Failure description. @return void Throws on failure.
     */
    function update_ui_assert(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    /** Render the real summary with a prepared model.
     * @param array<string, mixed> $model Presentation state. @return string Captured HTML.
     */
    function update_ui_summary(array $model): string
    {
        ob_start();
        \Gallery\Views\view_render_update_release_summary($model);
        return (string) ob_get_clean();
    }

    $GLOBALS['update_ui_installer_enabled'] = true;
    $GLOBALS['update_ui_auth_checks'] = 0;
    $GLOBALS['update_ui_passive_reads'] = 0;
    $cached = \Gallery\Services\application_update_status_for_admin(false);
    $current = \Gallery\Controllers\cms_update_release_view_model($cached);
    update_ui_assert($current['status']['update_available'] === false, 'Activated version must clear stale cached availability.');
    update_ui_assert(!str_contains(update_ui_summary($current), 'value="stable_update"'), 'Completed release must not retain Update.');
    $pending = \Gallery\Controllers\cms_update_release_view_model(['latest_version' => '0.114.0', 'update_available' => false]);
    update_ui_assert($pending['status']['update_available'] === true, 'A genuinely newer release must remain installable.');
    update_ui_assert(str_contains(update_ui_summary($pending), 'value="stable_update"'), 'Pending release needs its existing install form.');
    $GLOBALS['update_ui_installer_enabled'] = false;
    $disabled = \Gallery\Controllers\cms_update_release_view_model(['latest_version' => '0.114.0']);
    update_ui_assert(!str_contains(update_ui_summary($disabled), 'value="stable_update"'), 'Disabled installer must stay read-only.');
    $GLOBALS['update_ui_installer_enabled'] = true;

    $_GET = ['update_status_fragment' => '1'];
    ob_start();
    \Gallery\Controllers\cms_admin_update();
    $fragment = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    update_ui_assert($GLOBALS['update_ui_auth_checks'] === 1 && $GLOBALS['update_ui_passive_reads'] === 2, 'Fragment must authenticate and read only passive metadata.');
    update_ui_assert($fragment['ok'] === true && !str_contains($fragment['html'], 'value="stable_update"'), 'Passive fragment must report the activated release.');
    update_ui_assert(str_contains($fragment['html'], 'action="/index.php?page=admin_update"'), 'Refreshed forms must preserve the updater endpoint inside public-page drawers.');

    $model = array_merge($pending, [
        'repository' => 'klusik/PHP_gallery',
        'github_project_url' => 'https://github.com/klusik/PHP_gallery',
        'autoupdate_status' => ['enabled' => true, 'last_checked_label' => '2026-10-01 00:15:00', 'last_result' => 'no_update'],
        'github_api_status' => ['last_checked_label' => '2026-10-01 00:15:00', 'remaining' => 33, 'limit' => 60, 'used' => 27, 'resource' => 'core', 'last_status' => 200, 'etag' => '"fixture-etag"', 'reset_label' => '2026-10-01 00:35:00'],
        'urls' => ['status_fragment' => '?page=admin_update&update_status_fragment=1', 'admin' => '?page=admin', 'update' => '?page=admin_update'],
        'active_update_job' => ['id' => 'fixture-job', 'stage' => 'download', 'stage_label' => 'Downloading package', 'status' => 'running', 'progress' => ['percent' => 42, 'message' => 'Downloading package.'], 'attempts' => 3, 'can_resume' => true],
    ]);
    ob_start();
    \Gallery\Views\view_render_admin_update_page($model);
    $html = (string) ob_get_clean();
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    update_ui_assert($xpath->query('//*[@data-update-release-summary]//input[@value="force_check"]')->length === 1, 'Force check belongs beside Update.');
    update_ui_assert($xpath->query('//*[@data-update-release-summary]//input[@value="stable_update"]')->length === 1, 'Update belongs in the release summary.');
    update_ui_assert($xpath->query('//details[contains(@class,"admin-update-diagnostics") and not(@open)]')->length === 1, 'Technical diagnostics must start collapsed.');
    update_ui_assert($xpath->query('//*[@data-update-job-scope]//*[@data-update-job-complete]')->length === 2, 'Both job surfaces need completion placeholders before the job finishes.');
    update_ui_assert(strpos($html, 'value="stable_update"') < strpos($html, 'admin-update-diagnostics'), 'Diagnostics must follow primary controls.');

    if (in_array('--preview', $argv ?? [], true)) {
        $styles = ['base', 'admin', 'admin-layout', 'admin-dashboard', 'admin-maintenance-center', 'admin-patch-notes', 'admin-update', 'side-panel', 'admin-cinematic'];
        $head = '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Updates layout preview</title><style>:root{--font-family:"Segoe UI",Arial,sans-serif}</style>';
        foreach ($styles as $style) { $head .= '<link rel="stylesheet" href="/public/assets/styles/' . $style . '.css">'; }
        $head .= '<script type="module">import {setupAdminTabs} from "/public/assets/gallery-modules/admin-tabs.js";setupAdminTabs();</script>';
        $preview = $head . '<body class="admin-page"><main style="max-width:1420px;margin:20px auto;padding:10px">' . $html . '</main></body></html>';
        file_put_contents(dirname(__DIR__) . '/cache/admin-updates-ui-preview.html', $preview);
    }
    echo "Admin updates UI tests passed.\n";
}
