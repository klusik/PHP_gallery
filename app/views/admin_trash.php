<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_trash.php
 * Module Type: View
 *
 * Purpose:
 *   Renders the Admin gallery trash bin listing, actions, and retention settings.
 *
 * Responsibilities:
 *   - Present recoverable gallery subtrees with optional automatic-purge status/countdown
 *   - Offer restore and permanent deletion as explicit POST forms
 *   - Keep every control usable without JavaScript
 *   - Escape all rendered gallery-authored values
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-09-07
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\url_for;
use function Gallery\Services\t;

/**
 * Format a byte count for the trash listing.
 *
 * @param int $bytes Raw byte count.
 * @return string Short human-readable size.
 */
function admin_trash_format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    // $units stores the ordered size suffixes used by the listing.
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    // $power stores the chosen unit index for this value.
    $power = (int) min(count($units) - 1, floor(log($bytes, 1024)));
    return (
        number_format($bytes / (1024 ** $power), $power > 1 ? 1 : 0, '.', ' ')
            . ' '
            . $units[$power]
    );
}

/**
 * Render the complete Admin trash bin page or side-panel fragment.
 *
 * @param list<array{trash_token?:string,title?:string,original_folder_path?:string,
 *     status?:string,view_days_remaining?:int,deleted_by_username?:string|null,
 *     deleted_by_user_id?:int|null,subtree_gallery_count?:int,image_count?:int,
 *     byte_size?:int,deleted_at?:string,last_error_code?:string|null,purge_after?:string,
 *     view_can_purge?:bool}> $entries Prepared active/recovery records and action permissions.
 * @param array{available?:bool,active_count?:int,bytes?:int,purgeable_count?:int,
 *     count?:int,problem_count?:int} $summary Verified availability and counters.
 * @param bool $panelOnly True to omit page chrome for the side panel.
 * @param array{enabled?:bool,auto_purge_enabled?:bool,auto_purge_active?:bool,retention_days?:int,purge_batch_size?:int} $settings Prepared Trash enablement and cleanup preferences.
 * @return void Emits escaped compact Trash markup and the existing lifecycle-safe forms.
 */
function render_admin_trash_page(
    array $entries,
    array $summary,
    bool $panelOnly = false,
    array $settings = [],
): void {
    $autoPurgeActive = !empty($settings['auto_purge_active']);
    if (!$panelOnly) {
        echo
            '<section class="hero admin-trash-page-heading"><h1>'
                . view_admin_trash_icon()
                . e(t('admin.trash.title', 'Trash'))
                . '</h1><nav class="nav">';
        echo
            '<a class="button secondary" href="'
                . e(url_for('admin'))
                . '">'
                . e(t('admin.common.back_to_dashboard', 'Back to dashboard'))
                . '</a>';
        echo '</nav></section>';
    }

    echo
        '<section class="panel admin-trash-workspace" data-admin-trash-panel data-admin-trash-refresh-url="'
            . e(url_for('admin_trash', ['panel' => 1]))
            . '">';
    echo
        '<header class="admin-trash-heading"><div><h2>'
            . view_admin_trash_icon()
            . e(t('admin.trash.title', 'Trash'))
            . '</h2></div>';
    render_admin_trash_help(
        'admin-trash-scope-help',
        t('admin.trash.title', 'Trash'),
        $autoPurgeActive
            ? t(
                'admin.trash.intro_auto_purge',
                'Deleted galleries stay here with all their subgalleries and photos until you restore them, delete them permanently, or their retention window ends.',
            )
            : t(
                'admin.trash.intro_manual',
                'Deleted galleries stay here with all their subgalleries and photos until you restore them or delete them permanently. Automatic deletion is disabled.',
            ),
    );
    if (!empty($summary['available']) && $entries !== []) {
        echo
            '<p class="admin-trash-summary"><strong>'
                . e(t(
                    'admin.trash.summary',
                    '{count} gallery folder(s) in the trash, {size} total.',
                    [
                        'count' => (int) (
                            $summary['active_count'] ?? count($entries)
                        ),
                        'size' => admin_trash_format_bytes(
                            (int) ($summary['bytes'] ?? 0),
                        ),
                    ],
                ))
                . '</strong></p>';
    }
    $purgeableCount = (int) (
        $summary['purgeable_count']
        ?? $summary['count']
        ?? 0
    );
    if (
        !empty($summary['available'])
        && $entries !== []
        && $purgeableCount > 0
    ) {
        $emptyConfirm = t(
            'admin.trash.empty_confirm',
            'Permanently delete all {count} purgeable galleries in the trash? This cannot be undone.',
            ['count' => $purgeableCount],
        );
        $emptySuccessTemplate = t(
            'admin.trash.empty_success',
            'Permanently deleted {count} trashed gallery folder(s).',
            ['count' => '{count}'],
        );
        echo
            '<form method="post" action="'
                . e(url_for('admin_trash_empty'))
                . '" class="inline-action-form" data-admin-trash-empty-form data-admin-trash-success-template="'
                . e($emptySuccessTemplate)
                . '" onsubmit="return confirm('
                . e(json_encode(
                    $emptyConfirm,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ))
                . ');">'
                . csrf_field();
        echo
            '<button type="submit" class="danger">'
                . e(t('admin.trash.empty', 'Empty trash'))
                . '</button></form>';
    }
    echo '</header>';
    echo '<p class="notice" data-admin-trash-status hidden></p>';

    if (empty($summary['available'])) {
        echo
            '<p class="notice">'
                . e(t(
                    'admin.galleries.trash_requires_migration',
                    'The trash bin needs a database migration. Run pending migrations, then try again.',
                ))
                . '</p>';
        echo '</section>';
        return;
    }

    if ((int) ($summary['problem_count'] ?? 0) > 0) {
        echo
            '<p class="notice">'
                . e(t(
                    'admin.trash.problem_summary',
                    '{count} trash item(s) need attention or are waiting for crash recovery.',
                    [
                        'count' => (int) ($summary['problem_count'] ?? 0),
                    ],
                ))
                . '</p>';
    }

    render_admin_trash_settings_form($settings);
    if (!$entries) {
        echo
            '<p class="muted">'
                . e(t('admin.trash.empty_state', 'The trash is empty.'))
                . '</p></section>';
        return;
    }

    echo
        '<div class="admin-trash-list admin-content-group"><h3>'
            . e(t('admin.trash.column_gallery', 'Gallery'))
            . '</h3><div class="table-scroll"><table class="admin-table admin-trash-table" role="table"><thead><tr>';
    echo '<th scope="col">' . e(t('admin.trash.column_gallery', 'Gallery')) . '</th>';
    echo '<th scope="col">' . e(t('admin.trash.column_contents', 'Contents')) . '</th>';
    echo '<th scope="col">' . e(t('admin.trash.column_deleted', 'Deleted')) . '</th>';
    echo '<th scope="col">' . e(t('admin.trash.column_deleted_by', 'Deleted by')) . '</th>';
    echo '<th scope="col">' . e(t('admin.trash.column_state', 'State')) . '</th>';
    echo
        '<th scope="col">'
            . e(
                $autoPurgeActive
                    ? t('admin.trash.column_purges_in', 'Purges in')
                    : t('admin.trash.column_auto_purge', 'Automatic purge'),
            )
            . '</th>';
    echo '<th scope="col">' . e(t('admin.common.actions', 'Actions')) . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($entries as $entry) {
        render_admin_trash_row($entry, $autoPurgeActive);
    }

    echo '</tbody></table></div></div>';
    echo '</section>';
}

/**
 * Return a translated lifecycle label for one visible trash entry.
 *
 * @param string $status Stored trash lifecycle state.
 * @return string Human-readable lifecycle label.
 */
function admin_trash_status_label(string $status): string
{
    return match ($status) {
        'broken' => t('admin.trash.status_broken', 'Needs attention'),
        'preparing' => t('admin.trash.status_preparing', 'Preparing'),
        'restoring' => t('admin.trash.status_restoring', 'Restoring'),
        'purging' => t('admin.trash.status_purging', 'Deleting permanently'),
        default => t('admin.trash.status_trashed', 'Recoverable'),
    };
}

/**
 * Render one trash listing row with lifecycle-safe controls.
 *
 * @param array{trash_token?:string,title?:string,original_folder_path?:string,
 *     status?:string,view_days_remaining?:int,deleted_by_username?:string|null,
 *     deleted_by_user_id?:int|null,subtree_gallery_count?:int,image_count?:int,
 *     byte_size?:int,deleted_at?:string,last_error_code?:string|null,purge_after?:string,
 *     view_can_purge?:bool} $entry Prepared identity, counts, lifecycle evidence
 *     and the service's permanent-purge permission.
 * @param bool $autoPurgeActive Whether automatic purge is active.
 * @return void Emits all entry metadata, diagnostics and authorized lifecycle actions.
 */
function render_admin_trash_row(
    array $entry,
    bool $autoPurgeActive = false,
): void {
    // $token stores the trash entry identifier submitted by both action forms.
    $token = (string) ($entry['trash_token'] ?? '');
    // $title stores the gallery title captured at deletion time.
    $title = (string) ($entry['title'] ?? '');
    // $folderPath stores the original gallery folder path.
    $folderPath = (string) ($entry['original_folder_path'] ?? '');
    // $status stores the lifecycle state used to decide which actions are safe.
    $status = (string) ($entry['status'] ?? 'trashed');
    // $daysRemaining stores whole days left before scheduled maintenance purges a recoverable entry.
    $daysRemaining = (int) ($entry['view_days_remaining'] ?? 0);
    // $deletedBy stores the stable username when the deleting account still exists.
    $deletedBy = trim((string) ($entry['deleted_by_username'] ?? ''));
    if ($deletedBy === '') {
        $deletedBy = (int) ($entry['deleted_by_user_id'] ?? 0) > 0
            ? t('admin.trash.deleted_by_unknown_user', 'Former admin account')
            : t('admin.trash.deleted_by_system', 'System / unknown');
    }

    echo '<tr' . ($status !== 'trashed' ? ' class="is-attention"' : '') . '>';
    echo
        '<td data-label="' . e(t('admin.trash.column_gallery', 'Gallery'))
            . '"><strong>'
            . e($title)
            . '</strong><br><span class="muted">'
            . e($folderPath)
            . '</span></td>';
    echo
        '<td data-label="' . e(t('admin.trash.column_contents', 'Contents')) . '">'
            . e(t(
                'admin.trash.contents_value',
                '{galleries} folder(s), {images} photo(s), {size}',
                [
                    'galleries' => (int) ($entry['subtree_gallery_count'] ?? 0),
                    'images' => (int) ($entry['image_count'] ?? 0),
                    'size' => admin_trash_format_bytes(
                        (int) ($entry['byte_size'] ?? 0),
                    ),
                ],
            ))
            . '</td>';
    echo '<td data-label="' . e(t('admin.trash.column_deleted', 'Deleted')) . '">'
        . e((string) ($entry['deleted_at'] ?? '')) . '</td>';
    echo '<td data-label="' . e(t('admin.trash.column_deleted_by', 'Deleted by'))
        . '">' . e($deletedBy) . '</td>';
    echo '<td data-label="' . e(t('admin.trash.column_state', 'State'))
        . '"><strong>' . e(admin_trash_status_label($status)) . '</strong>';
    if (
        $status !== 'trashed'
        && trim((string) ($entry['last_error_code'] ?? '')) !== ''
    ) {
        echo
            '<br><span class="muted">'
                . e(t('admin.trash.problem_code', 'Diagnostic: {code}', [
                    'code' => (string) $entry['last_error_code'],
                ]))
                . '</span>';
    }
    echo '</td>';
    if ($status === 'trashed' && $autoPurgeActive) {
        echo
            '<td data-label="' . e(t('admin.trash.column_purges_in', 'Purges in')) . '">'
                . e(
                    $daysRemaining > 0
                        ? t('admin.trash.days_remaining', '{days} day(s)', [
                            'days' => $daysRemaining,
                        ])
                        : t('admin.trash.due_now', 'At next maintenance'),
                )
                . '<br><span class="muted">'
                . e((string) ($entry['purge_after'] ?? ''))
                . '</span></td>';
    } elseif ($status === 'trashed') {
        echo
            '<td data-label="' . e(t('admin.trash.column_auto_purge', 'Automatic purge'))
                . '"><span title="'
                . e(t(
                    'admin.trash.auto_purge_disabled',
                    'Disabled; kept until manually deleted',
                ))
                . '">'
                . e(t('admin.trash.auto_purge_disabled_short', 'Disabled'))
                . '</span></td>';
    } else {
        echo
            '<td data-label="' . e(t('admin.trash.column_auto_purge', 'Automatic purge')) . '">'
                . e(t(
                    'admin.trash.auto_purge_paused',
                    'Automatic purge paused',
                ))
                . '</td>';
    }
    echo '<td class="admin-trash-actions" data-label="'
        . e(t('admin.common.actions', 'Actions')) . '">';

    if ($status === 'trashed') {
        $restoreConfirm = t(
            'admin.trash.restore_confirm',
            'Restore "{title}" and its subgalleries to {path}?',
            ['title' => $title, 'path' => $folderPath],
        );
        echo
            '<form method="post" action="'
                . e(url_for('admin_trash_restore'))
                . '" class="inline-action-form" data-admin-trash-restore-form onsubmit="return confirm('
                . e(json_encode(
                    $restoreConfirm,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ))
                . ');">'
                . csrf_field();
        echo
            '<input type="hidden" name="trash_token" value="'
                . e($token)
                . '">';
        echo
            '<button type="submit">'
                . e(t('admin.trash.restore', 'Restore'))
                . '</button>';
        echo '</form>';
    } elseif ($status === 'broken') {
        echo
            '<span class="muted">'
                . e(t(
                    'admin.trash.restore_unavailable_broken',
                    'Automatic restore is unavailable for this problem entry.',
                ))
                . '</span>';
    } else {
        echo
            '<span class="muted">'
                . e(t(
                    'admin.trash.operation_pending',
                    'Recovery/maintenance operation pending.',
                ))
                . '</span>';
    }

    $canPurge = !empty($entry['view_can_purge']);
    if ($canPurge) {
        $purgeConfirm = t(
            'admin.trash.purge_confirm',
            'Permanently delete "{title}"? This cannot be undone.',
            ['title' => $title],
        );
        echo
            '<form method="post" action="'
                . e(url_for('admin_trash_purge'))
                . '" class="inline-action-form" data-admin-trash-purge-form onsubmit="return confirm('
                . e(json_encode(
                    $purgeConfirm,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ))
                . ');">'
                . csrf_field();
        echo
            '<input type="hidden" name="trash_token" value="'
                . e($token)
                . '">';
        echo
            '<button type="submit" class="danger">'
                . e(t('admin.trash.purge', 'Delete permanently'))
                . '</button>';
        echo '</form>';
    } elseif ($status === 'broken') {
        echo
            '<br><span class="muted">'
                . e(t(
                    'admin.trash.purge_unavailable_live_overlap',
                    'Permanent deletion is blocked because this problem entry still overlaps live gallery data.',
                ))
                . '</span>';
    }

    echo '</td></tr>';
}

/**
 * Render the trash retention and enablement settings form.
 *
 * @param array{enabled?:bool,auto_purge_enabled?:bool,retention_days?:int,purge_batch_size?:int} $settings Prepared enablement and retention preferences.
 * @return void Emits a flat preference group with contextual help and a classic
 *     Save/POST fallback; the owning browser module binds immediate persistence.
 */
function render_admin_trash_settings_form(array $settings = []): void
{
    if (
        empty($settings['enabled'])
        && !empty($settings['auto_purge_enabled'])
    ) {
        echo
            '<p class="notice">'
                . e(t(
                    'admin.trash.auto_purge_paused_feature_disabled',
                    'Automatic purge is configured but paused while the Trash feature is disabled. Re-enabling Trash will give current recoverable items a fresh full retention period before purge resumes.',
                ))
                . '</p>';
    }
    echo
        '<section class="admin-content-group admin-trash-settings-group"><h3>'
            . e(t('admin.trash.settings_title', 'Trash settings'))
            . '</h3>';
    echo
        '<form method="post" action="'
            . e(url_for('admin_trash_settings'))
            . '" class="admin-trash-settings" data-admin-trash-settings-form'
            . ' data-saving-label="' . e(t('admin.features.saving', 'Saving…'))
            . '" data-saved-label="' . e(t('admin.features.saved', 'Saved'))
            . '" data-failed-label="' . e(t(
                'admin.features.save_unknown',
                'Save could not be verified. Reload state before making another change.',
            )) . '" data-reload-label="'
            . e(t('admin.features.reload_state', 'Reload state')) . '">'
            . csrf_field();
    echo
        '<div class="admin-trash-setting"><label><input type="checkbox" name="gallery_trash_enabled" value="1"'
            . (!empty($settings['enabled']) ? ' checked' : '')
            . '> ';
    echo
        e(t('admin.features.gallery_trash.label', 'Gallery Trash')) . '</label>';
    render_admin_trash_help(
        'admin-trash-enabled-help',
        t('admin.features.gallery_trash.label', 'Gallery Trash'),
        t(
                'admin.trash.enabled_label',
                'Move deleted galleries to the trash instead of deleting them immediately',
        ) . ' ' . t(
                'admin.trash.enabled_hint',
                'Turning this off only changes future deletes. Existing trash contents are kept and remain available here.',
        ),
    );
    echo '</div>';
    echo
        '<div class="admin-trash-setting"><label><input type="checkbox" name="gallery_trash_auto_purge_enabled" value="1"'
            . (!empty($settings['auto_purge_enabled']) ? ' checked' : '')
            . '> ';
    echo
        e(t('admin.trash.column_auto_purge', 'Automatic purge')) . '</label>';
    render_admin_trash_help(
        'admin-trash-auto-purge-help',
        t('admin.trash.column_auto_purge', 'Automatic purge'),
        t(
                'admin.trash.auto_purge_enabled_label',
                'Automatically delete trashed galleries after the retention period',
        ) . ' ' . t(
                'admin.trash.auto_purge_enabled_hint',
                'Off by default. Enabling it gives every currently recoverable item a fresh full retention period before automatic deletion can occur.',
        ),
    );
    echo '</div>';
    echo
        '<label class="admin-trash-number-setting">'
            . e(t(
                'admin.trash.retention_label',
                'Automatic purge retention (days)',
            ))
            . ' ';
    echo
        '<input type="number" name="gallery_trash_retention_days" min="1" max="365" value="'
            . e((string) (int) ($settings['retention_days'] ?? 30))
            . '"></label>';
    echo
        '<label class="admin-trash-number-setting">'
            . e(t('admin.trash.purge_batch_label', 'Cleanup batch size'))
            . ' ';
    echo
        '<input type="number" name="gallery_trash_purge_batch" min="1" max="100" value="'
            . e((string) (int) ($settings['purge_batch_size'] ?? 20))
            . '"></label>';
    echo
        '<button type="submit" class="secondary">'
            . e(t('admin.common.save', 'Save'))
            . '</button>';
    echo '<span role="status" aria-live="polite" data-admin-trash-settings-status>'
        . '</span></form></section>';
}

/**
 * Render one plain question-mark disclosure for the Trash module to enhance.
 * @param string $id Stable document-local help panel identity.
 * @param string $title Localized accessible help subject.
 * @param string $text Localized plain explanation, escaped before output.
 * @return void Emits native no-script help; enhancement anchors it to its trigger.
 */
function render_admin_trash_help(string $id, string $title, string $text): void
{
    echo '<details class="admin-trash-help" data-admin-trash-help>'
        . '<summary aria-label="' . e(t(
            'admin.settings.workspace.help_item',
            'Help: {label}',
            ['label' => $title],
        )) . '">?</summary><div class="admin-trash-help-panel" id="'
        . e($id) . '"><strong>' . e($title) . '</strong><p>' . e($text)
        . '</p></div></details>';
}
