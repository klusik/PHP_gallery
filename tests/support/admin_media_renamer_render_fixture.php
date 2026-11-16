<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/admin_media_renamer_render_fixture.php
 * Module Type: Browser Render Fixture
 * Purpose: Render actual compact rename previews, lazy tables and reports.
 * Responsibilities: Supply deterministic controller-prepared view models only.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /** Escape fixture text using the production view's external boundary.
     * @param string $value Prepared scalar text.
     * @return string Escaped HTML text/attribute.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
namespace Gallery\Services {
    /** Keep deterministic English fallback labels.
     * @param string $key Translation key.
     * @param string $fallback Prepared label.
     * @return string English fixture label.
     */
    function t(string $key, string $fallback = ''): string { return $fallback; }
}
namespace {
    require_once dirname(__DIR__, 2) . '/app/views/admin_media_renamer.php';
    $mode = $argv[1] ?? 'preview';
    $token = str_repeat('a', 32);
    $galleries = [7 => ['Journeys · mountains and coast', 'collections/journeys', 61],
        8 => ['Evening departures', 'collections/evening-departures', 2]];
    if (in_array($mode, ['first', 'tail', 'second', 'report-first'], true)) {
        $start = $mode === 'tail' ? 51 : 1;
        $end = $mode === 'tail' ? 61 : ($mode === 'second' ? 2 : 50);
        $rows = [];
        foreach (range($start, $end) as $id) {
            $rows[] = ['old' => 'camera_' . $id . '.jpg',
                'new' => 'collections_journeys_' . str_pad((string) $id, 4, '0', STR_PAD_LEFT) . '.jpg',
                'status_label' => $mode === 'report-first' ? 'Renamed' : 'Will rename',
                'notes_text' => $id === 1 ? '<untrusted filename note>' : ''];
        }
        \Gallery\Views\view_render_admin_media_renamer_file_page(['rows' => $rows]);
        exit;
    }
    ob_start();
    if ($mode === 'report') {
        $rows = [];
        foreach ($galleries as $id => [$title, $path, $count]) {
            $rows[] = ['gallery_id' => $id, 'gallery' => $title, 'total' => $count,
                'renamed' => $count, 'skipped' => 0, 'failed' => 0, 'detail_url' => '/details'];
        }
        \Gallery\Views\view_render_admin_media_renamer_execution_details([
            'galleries' => $rows, 'report_token' => $token, 'details' => [],
        ]);
    } else {
        $plans = [];
        foreach ($galleries as $id => [$title, $path, $count]) {
            $plans[] = ['preview_only' => true, 'gallery' => ['id' => $id, 'title' => $title,
                'folder_path' => $path], 'items' => [], 'pattern' => '{gallery_context}_{seq4}',
                'summary' => ['total' => $count, 'rename' => $count, 'warnings' => 0],
                'detail_url' => '/details'];
        }
        \Gallery\Views\view_render_admin_media_renamer_plan_table([
            'plans' => $plans, 'aggregate' => ['total' => 63, 'rename' => 63],
        ]);
    }
    $fragment = (string) ob_get_clean();
    ob_start();
    \Gallery\Views\view_render_admin_media_renamer_apply_form([
        'rename_count' => 63, 'candidate_image_ids' => [], 'selected_gallery_ids' => [7, 8],
        'action_url' => '/rename', 'csrf_html' => '<input type="hidden" name="csrf_token" value="fixture">',
        'pattern' => '{gallery_context}_{seq4}',
    ]);
    $apply = (string) ob_get_clean();
    \Gallery\Views\view_render_admin_media_renamer_site_workspace([
        'detail_url' => '/rename', 'csrf_html' => '<input type="hidden" name="csrf_token" value="fixture">',
        'notice' => $mode === 'report' ? 'Renamed 63 files in 2 galleries.' : '',
        'has_plans' => $mode !== 'report', 'plan_table_html' => $mode === 'report' ? '' : $fragment,
        'execution_details_html' => $mode === 'report' ? $fragment : '', 'apply_form_html' => $apply,
    ]);
}
