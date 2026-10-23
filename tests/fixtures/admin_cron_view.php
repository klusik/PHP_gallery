<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/admin_cron_view.php
 * Module Type: Render Fixture
 * Purpose: Render production Cron Overview markup from a prepared isolated model.
 * Responsibilities: Keep browser tests independent of live database or credentials.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /** Escape fixture display text. @param string $value Plain text. @return string Safe HTML attribute/text. */
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    /**
     * Leave the outer browser document with its fixture owner.
     * @param string $title Prepared page title, unused by this renderer.
     * @param array<string,mixed>|null $currentGallery Optional selected gallery, unused by this renderer.
     * @param bool $publicOnly Public branding choice, unused by this renderer.
     * @return void Emits no wrapper; retains the production header signature.
     */
    function render_header(
        string $title, ?array $currentGallery = null, bool $publicOnly = true,
    ): void {}
    /** Leave the outer browser document with its fixture owner. @return void Emits no wrapper. */
    function render_footer(): void {}
}
namespace Gallery\Services {
    /** Return English fixture labels without installation catalogs. @param string $key Catalog identity, unused in this isolated English renderer. @param string $fallback English display text. @return string English display text. */
    function t(string $key, string $fallback = ''): string { return $fallback; }
}
namespace {
    require_once __DIR__ . '/../../app/views/admin_ui.php';
    require_once __DIR__ . '/../../app/views/admin_cron.php';
    $rows = [];
    // Deliberately unordered display strings and timestamps detect accidental
    // localized-label sorting; null must remain after real due thresholds.
    foreach ([['updates', 'Zulu updates', null], ['logs', 'Alpha logs', 200],
        ['cache', 'Beta cache', 100]] as [$id, $name, $next]) {
        $rows[] = ['id' => $id, 'name' => $name, 'cadence' => 'Every 5 min',
            'cadence_seconds' => 300, 'next_at' => $next, 'last_at' => null,
            'state' => 'due', 'state_label' => 'Due', 'can_run' => true,
            'detail' => 'Awaiting visitor', 'next_label' => $id . ' display',
            'last_label' => 'Unknown', 'settings_url' => '/settings/' . $id];
    }
    \Gallery\Views\view_render_admin_cron([
        'rows' => $rows, 'csrf' => 'fixture-csrf', 'status_url' => '/status',
        'run_url' => '/run', 'token_url' => '/token', 'cron_url' => '',
    ]);
}
