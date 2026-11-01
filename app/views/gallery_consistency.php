<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/gallery_consistency.php
 * Module Type: View
 * Purpose: Render dense consistency findings and explicit repair controls.
 * Responsibilities: Keep progress accessible and technical evidence disclosed.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * @phpstan-type ConsistencyBrowserConfig array{enabled:bool,source_endpoint:string,
 *     upload_endpoint:string,worker_count:int,max_worker_count:int,hard_worker_cap:int,
 *     upload_limit_bytes:int,batch_target_bytes:int,max_items_per_batch:int,
 *     source_chunk_bytes:int,source_chunk_item_cap:int,thumbnail_sizes:list<int>,
 *     thumbnail_formats:list<string>,jpeg_quality:int,webp_quality:int,
 *     supported_mime_types:list<string>}
 */
declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_token;
use function Gallery\Core\e;
use function Gallery\Core\render_header;
use function Gallery\Core\render_footer;
use function Gallery\Core\url_for;
use function Gallery\Services\t;

/** Bound initial markup independently of installation/gallery size.
 * Type: int. Units: gallery rows. Scope: one consistency result page.
 * Consumers: view_render_gallery_consistency_results. Rationale: 40 keeps the
 * compact report usable while every finding remains available through export.
 */
const GALLERY_CONSISTENCY_RESULT_PAGE = 40;

/** Bound expanded samples without truncating the stored/exported report.
 * Type: int. Units: findings. Scope: one gallery disclosure.
 * Consumers: view_render_gallery_consistency_results. Rationale: 12 samples
 * expose causes without inserting thousands of photo rows into the document.
 */
const GALLERY_CONSISTENCY_FINDING_SAMPLES = 12;

/**
 * Resolve a consistency UI label in the active Admin language.
 * @param string $key Consistency catalog suffix authored by this feature.
 * @return string Localized label, or the stable suffix if a catalog is damaged.
 */
function view_gallery_consistency_text(string $key): string
{
    return t('admin.consistency.' . $key, $key);
}

/**
 * Render the explicit report/repair page from a prepared safe receipt.
 * @param array{token?:string,sequence?:int,mode?:string,status?:string,
 *     scope?:string,phase?:string,processed?:int,total?:int,disk_checked?:int,
 *     repaired?:int,failed?:int,groups?:int,repairable_groups?:int,
 *     html?:string,ok?:bool,
 *     moves?:list<array{key:string,path:string,leaf:string}>,
 *     browser?:array{image_id:int,variants:int}|null,browser_groups?:list<string>,
 *     confirmed_variants?:int}
 *     $receipt
 *     Safe session progress and already-rendered result fragment, or empty.
 * @param array{installation_url:string,tools_url?:string,
 *     consistency_url:string}|array{}
 *     $navigation Maintenance Center tabs, or empty for isolated previews.
 * @param ConsistencyBrowserConfig|array{} $browserConfig Existing worker
 *     configuration; empty only for isolated non-mutating previews.
 * @return void Emits the compact accessible workspace and its initial state.
 */
function view_render_gallery_consistency_page(
    array $receipt,
    array $navigation = [],
    array $browserConfig = [],
): void {
    $title = view_gallery_consistency_text('title');
    render_header($navigation === [] ? $title
        : t('admin.maintenance_center.title', 'Maintenance Center'));
    if ($navigation !== []) {
        echo '<section class="hero admin-maintenance-center-hero"><h1>'
            . e(t('admin.maintenance_center.title', 'Maintenance Center'))
            . '</h1></section>';
        view_render_maintenance_center_tabs($navigation, 'consistency');
    }
    $html = (string) ($receipt['html'] ?? '');
    unset($receipt['html']);
    $labels = [];
    foreach (['title', 'analyze', 'selected', 'all', 'resume', 'busy',
        'report_done', 'repair_done', 'galleries', 'images', 'disk', 'repairs',
        'stale', 'blocked', 'uncertain', 'consistency_failed',
        'consistency_stale', 'consistency_report_required',
        'consistency_selection_empty', 'consistency_uncertain',
        'select_first', 'failed', 'scope_hint_names', 'scope_hint_files',
        'scope_hint_content', 'scope_names', 'scope_files', 'scope_content',
        'report_scope', 'browser', 'consistency_browser_unavailable',
        'consistency_browser_unsupported', 'consistency_browser_stale',
        'consistency_browser_incomplete', 'variants_confirmed'] as $key) {
        $labels[$key] = view_gallery_consistency_text($key);
    }
    echo '<section class="gallery-consistency" data-gallery-consistency'
        . ' data-endpoint="' . e(url_for('admin_gallery_consistency')) . '"'
        . ' data-csrf="' . e(csrf_token()) . '"'
        . ' data-state="' . e(json_encode($receipt, JSON_THROW_ON_ERROR)) . '"'
        . ' data-labels="' . e(json_encode($labels, JSON_THROW_ON_ERROR)) . '">';
    if ($navigation === []) {
        echo '<header class="consistency-toolbar"><h1>' . e($title) . '</h1>'
            . '<a href="' . e(url_for('admin_maintenance_center')) . '">'
            . e(view_gallery_consistency_text('back')) . '</a></header>';
    }
    echo '<div class="consistency-toolbar"><label class="consistency-scope">'
        . '<span>' . e(view_gallery_consistency_text('scope')) . '</span>'
        . '<select data-consistency-scope>';
    foreach (['names', 'files', 'content'] as $scope) {
        echo '<option value="' . $scope . '">'
            . e(view_gallery_consistency_text('scope_' . $scope)) . '</option>';
    }
    echo '</select></label><button type="button"'
        . ' data-consistency-action="start">'
        . e(view_gallery_consistency_text('analyze')) . '</button>'
        . '<button type="button" data-consistency-action="resume" hidden>'
        . e(view_gallery_consistency_text('resume')) . '</button>'
        . '<details class="consistency-help"><summary aria-label="'
        . e(view_gallery_consistency_text('help_label')) . '">?</summary><p>'
        . e(view_gallery_consistency_text('help')) . '</p></details>'
        . '<a data-consistency-export hidden href="'
        . e(url_for('admin_gallery_consistency', ['format' => 'json'])) . '">'
        . e(view_gallery_consistency_text('export')) . '</a></div>';
    echo '<p class="consistency-scope-hint" data-consistency-scope-hint></p>';
    echo '<p class="consistency-scope-hint" data-consistency-report-scope hidden></p>';
    echo '<p class="consistency-scope-hint" data-consistency-browser-notice hidden>'
        . e(view_gallery_consistency_text('browser_notice')) . '</p>';
    echo '<div class="consistency-progress" data-consistency-progress hidden>'
        . '<progress max="100" aria-label="' . e($title) . '"></progress>'
        . '<span data-consistency-count></span></div>'
        . '<p role="status" aria-live="polite" data-consistency-status></p>';
    echo '<div class="consistency-browser-progress"'
        . ' data-consistency-browser-progress hidden>'
        . '<progress max="100" value="0" data-thumbnail-progress-fill'
        . ' aria-label="' . e(view_gallery_consistency_text('browser')) . '"></progress>'
        . '<p role="status" data-thumbnail-progress-text></p></div>';
    echo '<div class="consistency-toolbar" data-consistency-repair-toolbar hidden>'
        . '<button type="button" data-consistency-action="repair">'
        . e(view_gallery_consistency_text('selected')) . '</button>'
        . '<button type="button" data-consistency-action="all">'
        . e(view_gallery_consistency_text('all')) . '</button></div>';
    echo '<div data-consistency-results>' . $html . '</div>'
        . '<form data-consistency-browser-form hidden>'
        . '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="consistency_token">'
        . '<input type="hidden" name="consistency_sequence">'
        . '<input type="hidden" data-browser-thumbnail-rebuild-toggle'
        . ' data-browser-thumbnail-rebuild-config="'
        . e(json_encode($browserConfig, JSON_THROW_ON_ERROR)) . '">'
        . '</form>'
        . '<noscript><p>' . e(view_gallery_consistency_text('javascript'))
        . '</p></noscript></section>';
    render_footer();
}

/**
 * Render one page of affected galleries with exact counts and bounded samples.
 * @param \Gallery\Services\ConsistencyJob $job Completed safe report model;
 *     only finding groups and repair receipts are rendered.
 * @param int $page Zero-based affected-gallery page, clamped to available rows.
 * @return string Escaped HTML fragment retaining native selection/disclosures.
 */
function view_render_gallery_consistency_results(array $job, int $page = 0): string
{
    $count = count($job['groups']);
    $pages = max(1, (int) ceil($count / GALLERY_CONSISTENCY_RESULT_PAGE));
    $page = max(0, min($page, $pages - 1));
    ob_start();
    echo '<p class="consistency-summary">'
        . e(view_gallery_consistency_text('affected')) . ': ' . $count . '</p>';
    if ($job['mode'] === 'repair') {
        echo '<p class="consistency-scope-hint">'
            . e(view_gallery_consistency_text($count > 0
                ? 'report_snapshot' : 'remaining_clear')) . '</p>';
    }
    foreach (array_slice(
        $job['groups'], $page * GALLERY_CONSISTENCY_RESULT_PAGE,
        GALLERY_CONSISTENCY_RESULT_PAGE, true,
    ) as $key => $group) {
        $repairable = count(array_filter(
            $group['issues'], static fn(array $issue): bool => $issue['repair'] !== '',
        ));
        $manual = count($group['issues']) - $repairable;
        echo '<article class="consistency-row"><label class="consistency-select">'
            . '<input type="checkbox" data-consistency-group="' . e($key) . '"'
            . ($repairable > 0 ? '' : ' disabled') . ' aria-label="'
            . e(view_gallery_consistency_text('select') . ': ' . $group['title'])
            . '"></label><details><summary><span class="consistency-identity">'
            . '<strong>' . e($group['title'] ?: ($group['path'] ?: 'galleries/'))
            . '</strong>'
            . '<small>' . e($group['path'] ?: 'galleries/') . '</small></span>'
            . '<span class="consistency-counts">'
            . e(view_gallery_consistency_text('fixable')) . ': ' . $repairable;
        if ($manual > 0) {
            echo ' · ' . e(view_gallery_consistency_text('manual')) . ': ' . $manual;
        }
        echo '</span></summary>';
        if (($group['changes'] ?? []) !== []
        ) {
            echo '<div class="consistency-plan"><p>'
                . e(view_gallery_consistency_text('plan_note')) . '</p>';
            foreach ($group['changes'] as $change) {
                echo '<strong>' . e(view_gallery_consistency_text($change['field']))
                    . '</strong><dl class="consistency-change">';
                foreach (['current', 'proposed'] as $field) {
                    $pathAttributes = $field === 'proposed'
                        && in_array($change['field'], ['folder_value', 'parent_value'], true)
                        && $change[$field] !== ''
                        ? ' data-consistency-path-source="' . e($group['path'])
                            . '" data-consistency-path-base="' . e($change[$field]) . '"'
                        : '';
                    echo '<div><dt>' . e(view_gallery_consistency_text($field))
                        . '</dt><dd' . $pathAttributes . '>' . e($change[$field] === ''
                            ? view_gallery_consistency_text('empty_value')
                            : $change[$field]) . '</dd></div>';
                }
                echo '</dl>';
            }
            echo '</div>';
        }
        echo '<ul>';
        foreach (array_slice(
            $group['issues'], 0, GALLERY_CONSISTENCY_FINDING_SAMPLES,
        ) as $issue) {
            echo '<li><span>' . e(view_gallery_consistency_text($issue['code']))
                . '</span>';
            $evidence = array_filter([$issue['path'], $issue['details']],
                static fn(string $value): bool => $value !== '');
            if ($evidence !== []) {
                echo '<small>' . e(implode(' · ', $evidence)) . '</small>';
            }
            echo '<small>' . e(view_gallery_consistency_text(
                $issue['repair'] === '' ? 'manual'
                    : 'action_' . $issue['repair'],
            )) . '</small></li>';
        }
        if (count($group['issues']) > GALLERY_CONSISTENCY_FINDING_SAMPLES) {
            echo '<li>' . e(view_gallery_consistency_text('export_more')) . '</li>';
        }
        foreach ($job['results'] as $result) {
            if ($result['key'] === $key) {
                echo '<li class="consistency-receipt">'
                    . e(view_gallery_consistency_text($result['status'])) . ' · '
                    . e(isset($result['variants'])
                        ? view_gallery_consistency_text('variants_confirmed')
                            . ': ' . $result['variants']
                        : view_gallery_consistency_text($result['code'])) . '</li>';
                $reason = $result['failure']['reason'] ?? '';
                if (in_array($reason, ['consistency_browser_unavailable',
                    'consistency_browser_unsupported', 'consistency_browser_stale',
                    'consistency_browser_incomplete'], true)
                ) {
                    echo '<li>' . e(view_gallery_consistency_text($reason)) . '</li>';
                }
            }
        }
        echo '</ul></details></article>';
    }
    if ($pages > 1) {
        echo '<nav class="consistency-toolbar" aria-label="'
            . e(view_gallery_consistency_text('pages')) . '">';
        foreach ([-1, 1] as $direction) {
            $next = $page + $direction;
            echo '<button type="button" data-consistency-page="' . $next . '"'
                . ($next < 0 || $next >= $pages ? ' disabled' : '') . '>'
                . e(view_gallery_consistency_text($direction < 0 ? 'previous' : 'next'))
                . '</button>';
        }
        echo '<span>' . ($page + 1) . ' / ' . $pages . '</span></nav>';
    }
    return (string) ob_get_clean();
}
