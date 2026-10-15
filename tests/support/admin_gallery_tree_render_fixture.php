<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/admin_gallery_tree_render_fixture.php
 * Module Type: Test Fixture
 * Purpose: Render the real gallery workspace without application bootstrap or storage.
 * Responsibilities: Supply six disposable prepared rows and deterministic presentation helpers.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Escape prepared fixture text.
     * @param string $value Presentation text.
     * @return string Safe HTML.
     */
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    /** Build synthetic route URLs.
     * @param string $route Route identifier.
     * @param array<string,mixed> $params Route parameters.
     * @return string Loopback-only relative destination.
     */
    function url_for(string $route, array $params = []): string { return '/index.php?' . http_build_query(['page' => $route] + $params); }
    /** Supply disposable form authority.
     * @return string Hidden fixture token.
     */
    function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="tree-fixture">'; }
    /** Supply disposable tree authority.
     * @return string Fixture token.
     */
    function csrf_token(): string { return 'tree-fixture'; }
    /** Preserve canonical public path structure without configuration reads.
     * @param array<string,mixed> $gallery Prepared row.
     * @return string Synthetic public URL.
     */
    function gallery_public_url(array $gallery): string { return '/gallery/' . $gallery['folder_path'] . '/'; }
}
namespace Gallery\Services {
    /** Resolve fixture labels from local language data when requested, without service reads.
     * @param string $key Translation identifier.
     * @param string $fallback English label.
     * @param array<string,string|int> $parameters Optional interpolation values.
     * @return string Localized fixture label or English fallback.
     */
    function t(string $key, string $fallback = '', array $parameters = []): string { return $GLOBALS['cache_region_fixture_labels'][$key] ?? $fallback; }
}
namespace {
    require_once dirname(__DIR__, 2) . '/app/views/admin_ui.php';
    require_once dirname(__DIR__, 2) . '/app/views/admin_dashboard.php';
    if (($argv[1] ?? '') === '--cache-regions') {
        require_once dirname(__DIR__, 2) . '/app/views/admin_dashboard_sections.php';
        $regions = [
            ['path'=>'test-audit','phase'=>'inventory','round'=>2,'last_serviced_at'=>1700000060,'last_completed_at'=>1700000000,
                'next_due_at'=>1700000300,'retry_at'=>1700000900,'failures'=>2,'cursor_path'=>'test-audit/<img src=x onerror=alert(1)> & "žluťoučký"','cursor_offset'=>24],
            ['path'=>'@discovery','phase'=>'complete','round'=>1,'last_serviced_at'=>1700000060,'last_completed_at'=>0,
                'next_due_at'=>0,'retry_at'=>0,'failures'=>0,'cursor_path'=>'','cursor_offset'=>0],
            ['path'=>'constructor','phase'=>'unfamiliar <state>'], ['path'=>'@files','phase'=>'cleanup'],
        ];
        foreach (['en','cs','de','sv'] as $language) {
            $GLOBALS['cache_region_fixture_labels'] = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/app/lang/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
            echo '<section id="cache-regions-' . $language . '" lang="' . $language . '">';
            \Gallery\Views\view_render_admin_dashboard_cache_regions($regions);
            echo '</section>';
        }
        exit;
    }
    $rows = [];
    foreach ([[1, 0, 'alpha'], [2, 1, 'alpha/child'], [3, 2, 'alpha/child/leaf'], [4, 0, 'beta'], [5, 4, 'beta/child'], [6, 0, 'gamma']] as [$id, $parent, $folder]) {
        $rows[] = ['id' => $id, 'parent_id' => $parent, 'folder_path' => $folder,
            'title' => $id === 1 ? 'Alpha <safe>' : 'Gallery ' . $id, 'parent_title' => $parent ? 'Gallery ' . $parent : '',
            'view_visibility' => $id === 6 ? 'private' : 'public', 'image_count' => $id * 2,
            'view_gps_map_enabled' => $id % 2 === 1, 'view_background_source_set' => $id === 2,
            'show_filenames' => 1, 'voting_enabled' => $id % 2, 'picture_game_enabled' => 1,
            'access_mode' => $id === 6 ? 'password' : 'normal', 'access_password_hash' => $id === 6 ? 'fixture-noncredential' : '',
            'preview_url' => $id === 1 ? 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg"/%3E' : ''];
    }
    \Gallery\Views\view_render_admin_dashboard_galleries_panel(['galleries' => $rows,
        'children_by_parent' => [1 => [2], 2 => [3], 4 => [5]], 'collapsed_ids' => [], 'gallery_trash_enabled' => true,
        'picture_game_ready' => true, 'gps_map_ready' => true, 'gps_map_override_ready' => true,
        'voting_ready' => true, 'filename_display_ready' => true, 'access_ready' => true,
        'gallery_controls' => ['feature_plan_url' => '/fixture-feature-plan', 'feature_apply_url' => '/fixture-feature-apply']]);
}
