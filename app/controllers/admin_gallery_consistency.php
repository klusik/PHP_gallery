<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_gallery_consistency.php
 * Module Type: Controller
 * Purpose: Serve authenticated consistency reports and explicit repair batches.
 * Responsibilities: Enforce CSRF, session ownership and non-replayed receipts.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

use RuntimeException;
use Throwable;
use function Gallery\Core\require_admin;
use function Gallery\Core\request_method;
use function Gallery\Core\redirect_to;
use function Gallery\Core\url_for;
use function Gallery\Services\gallery_consistency_start;
use function Gallery\Services\gallery_consistency_step;
use function Gallery\Services\gallery_consistency_prepare_repairs;
use function Gallery\Views\view_render_gallery_consistency_page;
use function Gallery\Views\view_render_gallery_consistency_results;

/**
 * Render the explicit consistency page or advance one session-owned job.
 * @return void Sends private HTML/JSON; never permits a GET to repair media.
 */
function cms_admin_gallery_consistency(): void
{
    require_admin();
    header('Cache-Control: private, no-store');
    $job = $_SESSION['gallery_consistency_job'] ?? null;
    $job = is_array($job) ? $job : null;
    if ($job !== null) {
        $job = \Gallery\Services\gallery_consistency_presentation($job);
    }
    if (request_method() !== 'POST') {
        if (($_GET['format'] ?? '') === 'json' && $job !== null) {
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="gallery-consistency.json"');
            // Never export the raw catalog snapshot: it includes access policy.
            echo json_encode([
                'started_at' => $job['started_at'],
                'status' => $job['status'], 'mode' => $job['mode'],
                'scope' => $job['scope'] ?? 'content',
                'groups' => $job['groups'], 'results' => $job['results'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            return;
        }
        if (($_GET['page'] ?? '') !== 'admin_maintenance_center') {
            redirect_to(url_for('admin_maintenance_center', [
                'tab' => 'consistency',
            ]));
            return;
        }
        view_render_gallery_consistency_page(
            $job === null ? [] : cms_admin_gallery_consistency_payload($job, 0),
            [
                'installation_url' => url_for('admin_maintenance_center'),
                'tools_url' => url_for('admin_maintenance_center', ['tab' => 'tools']),
                'consistency_url' => url_for('admin_maintenance_center', [
                    'tab' => 'consistency',
                ]),
            ],
            cms_admin_gallery_consistency_browser_config(),
        );
        return;
    }
    try {
        $csrf = (string) ($_POST['csrf_token'] ?? '');
        if ($csrf === '' || !hash_equals(
            (string) ($_SESSION['csrf_token'] ?? ''), $csrf,
        )) {
            throw new RuntimeException('consistency_failed');
        }
        $action = (string) ($_POST['action'] ?? 'status');
        if ($action === 'start') {
            $lock = \Gallery\Services\gallery_edit_writer_begin();
            try {
                $job = gallery_consistency_start((string) ($_POST['scope'] ?? 'files'));
            } finally {
                \Gallery\Services\gallery_edit_writer_end($lock);
            }
        } else {
            if ($job === null || ($action !== 'status' && !hash_equals(
                $job['token'], (string) ($_POST['token'] ?? ''),
            ))) {
                throw new RuntimeException('consistency_report_required');
            }
            if ($action === 'repair') {
                if ((int) ($_POST['sequence'] ?? -1) !== $job['sequence']) {
                    throw new RuntimeException('consistency_stale');
                }
                $keys = is_array($_POST['groups'] ?? null)
                    ? array_values(array_filter($_POST['groups'], 'is_string'))
                    : [];
                gallery_consistency_prepare_repairs(
                    $job, $keys, ($_POST['all'] ?? '') === '1',
                    cms_admin_gallery_consistency_browser_config()['enabled'],
                );
            } elseif ($action === 'browser_verify') {
                if ((int) ($_POST['sequence'] ?? -1) === $job['sequence']) {
                    \Gallery\Services\gallery_consistency_browser_verify($job);
                }
            } elseif ($action === 'step') {
                $sequence = (int) ($_POST['sequence'] ?? -1);
                if ($job['status'] === 'running'
                    && $sequence === $job['sequence']
                ) {
                    $job['sequence']++;
                    // Persist admission before work. A killed PHP request leaves
                    // an explicit uncertain receipt, never a replayable write.
                    $checkpoint = $job;
                    $checkpoint['status'] = 'executing';
                    $_SESSION['gallery_consistency_job'] = $checkpoint;
                    session_write_close();
                    try {
                        gallery_consistency_step($job);
                    } catch (Throwable $exception) {
                        $job['status'] = 'blocked';
                        throw $exception;
                    } finally {
                        session_start();
                        $current = $_SESSION['gallery_consistency_job'] ?? [];
                        if (($current['token'] ?? '') === $job['token']
                            && ($current['sequence'] ?? -1) === $job['sequence']
                        ) {
                            $_SESSION['gallery_consistency_job'] = $job;
                        } else {
                            throw new RuntimeException('consistency_stale');
                        }
                    }
                }
            } elseif (!in_array($action, ['status', 'page'], true)) {
                throw new RuntimeException('consistency_action_unsupported');
            }
        }
        $_SESSION['gallery_consistency_job'] = $job;
        $page = max(0, (int) ($_POST['result_page'] ?? 0));
        $payload = cms_admin_gallery_consistency_payload($job, $page);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        // Only stable product error keys cross the authenticated JSON boundary.
        $known = [
            'consistency_stale', 'consistency_report_required',
            'consistency_selection_empty', 'consistency_uncertain',
            'consistency_browser_unavailable', 'consistency_browser_unsupported',
            'consistency_browser_stale', 'consistency_browser_incomplete',
        ];
        $code = in_array($exception->getMessage(), $known, true)
            ? $exception->getMessage() : 'consistency_failed';
        http_response_code(409);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'code' => $code,
        ], JSON_THROW_ON_ERROR);
    }
}

/**
 * Prepare safe progress receipts and an optional compact result fragment.
 * @param \Gallery\Services\ConsistencyJob $job Session-owned job state.
 * @param int $page Zero-based result page requested by the browser.
 * @return array{ok:bool,token:string,sequence:int,mode:string,status:string,
 *     scope:string,phase:string,processed:int,total:int,disk_checked:int,repaired:int,
 *     failed:int,groups:int,repairable_groups:int,html:string,
 *     moves:list<array{key:string,path:string,leaf:string}>,
 *     browser:?array{image_id:int,variants:int},browser_groups:list<string>,
 *     confirmed_variants:int}
 *     Public Admin job receipt.
 */
function cms_admin_gallery_consistency_payload(array $job, int $page): array
{
    $repairable = 0;
    $browserGroups = [];
    $confirmedVariants = 0;
    foreach (array_slice($job['results'], $job['results_start'] ?? 0) as $result) {
        if ($result['status'] === 'repaired') {
            $confirmedVariants += $result['variants'] ?? 0;
        }
    }
    foreach ($job['groups'] as $key => $group) {
        if (array_filter($group['issues'], static fn(array $issue): bool =>
            in_array($issue['repair'], ['image', 'source'], true)) !== []
        ) $browserGroups[] = $key;
        foreach ($group['issues'] as $issue) {
            if ($issue['repair'] !== '') {
                $repairable++;
                break;
            }
        }
    }
    return [
        'ok' => true, 'token' => $job['token'],
        'sequence' => $job['sequence'], 'mode' => $job['mode'],
        'scope' => $job['scope'] ?? 'content',
        'status' => $job['status'], 'phase' => $job['phase'],
        'processed' => $job['processed'], 'total' => $job['total'],
        'disk_checked' => $job['disk_checked'],
        'repaired' => $job['repaired'], 'failed' => $job['failed'],
        'confirmed_variants' => $confirmedVariants,
        'groups' => count($job['groups']),
        'repairable_groups' => $repairable,
        'browser_groups' => $browserGroups,
        'browser' => isset($job['browser_pending']) ? [
            'image_id' => $job['browser_pending']['image_id'],
            'variants' => count($job['browser_pending']['variants']),
        ] : null,
        'moves' => $job['status'] === 'complete'
            ? \Gallery\Services\gallery_consistency_proposed_moves($job) : [],
        'html' => $job['status'] !== 'running'
            && $job['status'] !== 'executing'
            && $job['status'] !== 'browser'
            ? view_render_gallery_consistency_results($job, $page) : '',
    ];
}

/**
 * Load the existing browser rebuild owner and prepare its unchanged settings.
 * @return \Gallery\Views\ConsistencyBrowserConfig Existing authenticated
 *     endpoints, worker/upload limits, sizes, formats and encoder qualities.
 */
function cms_admin_gallery_consistency_browser_config(): array
{
    \Gallery\Core\cms_runtime_kernel()->load('domain-admin-dashboard');
    return \Gallery\Services\browser_thumbnail_rebuild_browser_config();
}
