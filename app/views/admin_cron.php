<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/admin_cron.php
 * Module Type: View
 * Purpose: Render a dense, accessible schedule table from prepared view data.
 * Responsibilities: Keep scheduler policy outside markup and retain native forms.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Views;
use function Gallery\Core\e;
use function Gallery\Core\render_header;
use function Gallery\Core\render_footer;
use function Gallery\Services\t;

/**
 * Render the compact schedule table and optional hosting-credential disclosure.
 * @param array{rows:list<array{id:string,name:string,cadence:string,cadence_seconds:int,next_at:?int,last_at:?int,state:string,state_label:string,can_run:bool,detail:string,next_label:string,last_label:string,settings_url:string}>,csrf:string,status_url:string,run_url:string,token_url:string,cron_url:string} $model Prepared rows, owner links and protected installation-specific endpoints.
 * @return void Emits the page without discovering policy or running tasks.
 */
function view_render_admin_cron(array $model): void
{
    $title = t('admin.cron.title', 'Cron Overview');
    render_header($title);
    echo '<section class="cron-overview" data-cron-overview data-status-url="'
        . e($model['status_url']) . '" data-unknown="'
        . e(t('admin.cron.unknown', 'Unknown')) . '" data-connection-error="'
        . e(t('admin.cron.connection_error', 'Refresh failed; showing last known state.'))
        . '"><header class="cron-heading"><h1>' . e($title)
        . '</h1><button type="button" class="cron-icon cron-preview-toggle" hidden'
        . ' data-cron-preview-toggle aria-pressed="false" data-pause-label="'
        . e(t('admin.cron.pause', 'Pause live preview')) . '" data-resume-label="'
        . e(t('admin.cron.resume', 'Resume live preview')) . '" title="'
        . e(t('admin.cron.pause', 'Pause live preview')) . '" aria-label="'
        . e(t('admin.cron.pause', 'Pause live preview')) . '">'
        . '<span data-cron-pause-icon>' . view_admin_menu_icon('pause')
        . '</span><span data-cron-resume-icon hidden>' . view_admin_menu_icon('play')
        . '</span></button></header><div class="cron-note">'
        . e(t('admin.cron.visitor_note', 'Jobs run when the next visitor opens the site. Hosting cron is optional.'))
        . ' <details class="cron-help"><summary aria-label="'
        . e(t('admin.cron.timing_help',
            'Next eligible is a threshold, not an exact execution guarantee. With no visits, jobs wait.'))
        . '">?</summary><p>' . e(t('admin.cron.timing_help',
            'Next eligible is a threshold, not an exact execution guarantee. With no visits, jobs wait.'))
        . '</p></details></div><div class="cron-table-scroll"><table><thead><tr>';
    foreach (['name' => 'Task', 'cadence_seconds' => 'Cadence',
        'next_at' => 'Next eligible', 'last_at' => 'Last run', 'state' => 'State'] as $key => $fallback) {
        echo '<th scope="col" aria-sort="' . ($key === 'next_at' ? 'ascending' : 'none')
            . '"><button type="button" data-cron-sort="' . e($key) . '">'
            . e(t('admin.cron.column.' . $key, $fallback))
            . ' <span data-sort-glyph aria-hidden="true">'
            . ($key === 'next_at' ? '↑' : '↕') . '</span></button></th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($model['rows'] as $row) {
        echo '<tr data-cron-task="' . e($row['id']) . '" data-state="'
            . e($row['state']) . '"><td><div class="cron-task">'
            . '<form method="post" data-cron-run action="' . e($model['run_url']) . '">'
            . '<input type="hidden" name="csrf_token" value="' . e($model['csrf']) . '">'
            . '<input type="hidden" name="task" value="' . e($row['id']) . '">'
            . '<button type="submit" class="cron-icon cron-play" title="'
            . e(t('admin.cron.run_now', 'Run now') . ': ' . $row['name'])
            . '" aria-label="' . e(t('admin.cron.run_now', 'Run now') . ': ' . $row['name'])
            . '"' . ($row['can_run'] ? '' : ' disabled') . '>'
            . view_admin_menu_icon('play') . '</button></form><a data-cell="name" href="'
            . e($row['settings_url']) . '" title="'
            . e(t('admin.cron.open_settings', 'Open task settings and controls')) . '">'
            . e($row['name']) . '</a></div></td><td data-cell="cadence">' . e($row['cadence'])
            . '</td><td data-cell="next_label">' . e($row['next_label'])
            . '</td><td data-cell="last_label">' . e($row['last_label'])
            . '</td><td data-cell="state_label" aria-label="'
            . e($row['state_label'] . ': ' . $row['detail'])
            . '" title="' . e($row['detail']) . '">'
            . e($row['state_label']) . '</td></tr>';
    }
    echo '</tbody></table></div><p class="cron-feedback" role="status" data-cron-feedback></p>'
        . '<p class="cron-connection" role="status" data-cron-connection></p>'
        . '<details class="cron-host"><summary>'
        . e(t('admin.cron.hosting', 'Optional hosting cron')) . '</summary><p>'
        . e(t('admin.cron.hosting_help',
            'Optional: call this URL every 1–5 minutes. Task throttles and UTC windows still apply. Visitor scheduling always stays enabled.'))
        . '</p><p class="cron-host-safety">'
        . e(t('admin.cron.host_safety',
            'A secret 256-bit token, one accepted call per minute and shared task locks protect this URL from repeated HTTP triggers. Network-level flooding must be handled by the hosting provider.'))
        . '</p><div class="cron-host-controls"><label>' . e(t('admin.cron.url', 'Protected URL'))
        . '<input data-cron-url readonly type="text" autocomplete="off" value="'
        . e($model['cron_url']) . '"></label><button type="button" class="cron-icon" data-cron-copy title="'
        . e(t('admin.cron.copy', 'Copy')) . '" aria-label="'
        . e(t('admin.cron.copy', 'Copy')) . '">' . view_admin_menu_icon('copy')
        . '</button><form method="post" data-cron-token action="' . e($model['token_url'])
        . '"><input type="hidden" name="csrf_token" value="' . e($model['csrf']) . '">'
        . '<button type="submit" class="cron-icon" name="action" value="rotate" title="'
        . e(t('admin.cron.rotate', 'Create / regenerate URL')) . '" aria-label="'
        . e(t('admin.cron.rotate', 'Create / regenerate URL')) . '">'
        . view_admin_menu_icon('update') . '</button> '
        . '<button type="submit" class="cron-icon" name="action" value="revoke" title="'
        . e(t('admin.cron.revoke', 'Revoke URL')) . '" aria-label="'
        . e(t('admin.cron.revoke', 'Revoke URL')) . '">'
        . view_admin_menu_icon('trash') . '</button></form></div><pre data-cron-example>'
        . e($model['cron_url'] !== ''
            ? "curl -fsS '" . $model['cron_url'] . "' >/dev/null" : '')
        . '</pre></details>';
    echo '<script type="application/json" data-cron-initial>' . json_encode(
        $model['rows'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
        . '</script></section>';
    render_footer();
}
