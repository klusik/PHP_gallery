<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/admin_cooperative_galleries.php
 * Module Type: View
 * Purpose: Render prepared friendship management data without domain or storage calls.
 * Responsibilities: Keep presentation, request flow and domain authority in their owning layers.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;

/** Render hidden request context shared by the ordinary and enhanced forms.
 * @param array<string,mixed> $model Prepared controller data.
 * @param string $action Fixed domain action.
 * @return void Emits escaped fields and trusted CSRF markup.
 */
function view_cooperative_form_fields(array $model, string $action): void
{
    echo $model['csrf_html'];
    echo '<input type="hidden" name="cooperative_ui" value="1"><input type="hidden" name="action" value="' . e($action) . '">';
    echo '<input type="hidden" name="after_id" value="' . (int) $model['cursor'] . '">';
}

/** Render a panel-aware read-only navigation link with a normal-page fallback.
 * @param string $url Prepared local route.
 * @param string $label Prepared button label.
 * @param string $title Prepared panel title.
 * @return void Emits an escaped link.
 */
function view_cooperative_link(string $url, string $label, string $title): void
{
    if ($url === '') { return; }
    echo '<a class="button secondary" href="' . e($url) . '" data-cooperative-refresh data-gallery-side-panel-link data-admin-side-panel-workflow="cooperative_pairing" data-admin-side-panel-title="' . e($title) . '" data-gallery-side-panel-url="' . e($url . '&panel=1') . '">' . e($label) . '</a> ';
}

/** Render one self-contained page/drawer fragment from already prepared data.
 * @param array<string,mixed> $model Presentation data with allowlisted rows and labels.
 * @return void Emits forms, state and navigation without policy discovery.
 */
function view_render_admin_cooperative_galleries(array $model): void
{
    $labels = $model['labels'];
    echo '<section class="cooperative-panel" data-cooperative-panel data-cooperative-error="' . e($labels['unavailable']) . '" data-cooperative-busy="' . e($labels['busy']) . '">';
    echo '<h1 tabindex="-1" data-cooperative-heading>' . e($labels['title']) . '</h1><p class="muted">' . e($labels['intro']) . '</p>';
    echo '<p role="status" aria-live="polite" data-cooperative-status>' . e($model['notice']) . '</p>';
    echo '<p role="alert" data-cooperative-error-message>' . e($model['error']) . '</p>';
    if ($model['invitation_code'] !== '') {
        echo '<div class="cooperative-card"><p>' . e($labels['share']) . '</p><label>' . e($labels['code']);
        echo '<textarea readonly rows="5" autocomplete="off" spellcheck="false" data-cooperative-code>' . e($model['invitation_code']) . '</textarea></label></div>';
    }
    echo '<div class="cooperative-card"><h2>' . e($labels['invite']) . '</h2>';
    echo '<form method="post" action="' . e($model['action_url']) . '" data-cooperative-form><fieldset' . (!$model['ready'] ? ' disabled' : '') . '>';
    view_cooperative_form_fields($model, 'invite');
    echo '<input type="hidden" name="request_id" value="' . e($model['request_id']) . '">';
    echo '<label>' . e($labels['url']) . '<input type="url" name="base_url" required maxlength="1024" placeholder="https://gallery.example" autocomplete="url" value="' . e($model['draft']['base_url']) . '"></label>';
    echo '<button type="submit">' . e($labels['invite']) . '</button></fieldset></form></div>';
    echo '<div class="cooperative-card"><h2>' . e($labels['import']) . '</h2><p class="muted">' . e($labels['import_help']) . '</p>';
    echo '<form method="post" action="' . e($model['action_url']) . '" data-cooperative-form><fieldset' . (!$model['ready'] ? ' disabled' : '') . '>';
    view_cooperative_form_fields($model, 'import');
    echo '<label>' . e($labels['code']) . '<textarea name="invitation_code" rows="4" maxlength="8192" required autocomplete="off" spellcheck="false">' . e($model['draft']['invitation_code']) . '</textarea></label>';
    echo '<button type="submit">' . e($labels['import']) . '</button></fieldset></form></div>';
    if ($model['rows'] === [] && $model['error'] === '') {
        echo '<p>' . e($labels['empty']) . '</p>';
    }
    foreach ($model['rows'] as $row) {
        echo '<article class="cooperative-card"><h2>' . e($row['base_url']) . '</h2><p><strong>' . e($row['status']) . '</strong></p>';
        if ($row['expires'] !== '') { echo '<p>' . e($labels['expires']) . ': ' . e($row['expires']) . '</p>'; }
        if ($row['expired']) { echo '<p>' . e($labels['expired']) . '</p>'; }
        if ($row['retry_at'] !== '') { echo '<p>' . e($labels['retry_at']) . ': ' . e($row['retry_at']) . '</p>'; }
        if ($row['error'] !== '') { echo '<p class="muted">' . e($row['error']) . '</p>'; }
        foreach ($row['actions'] as $action) {
            echo '<form method="post" action="' . e($model['action_url']) . '" data-cooperative-form>';
            view_cooperative_form_fields($model, $action['name']);
            if (isset($action['request_id'], $action['base_url'])) {
                echo '<input type="hidden" name="request_id" value="' . e($action['request_id']) . '"><input type="hidden" name="base_url" value="' . e($action['base_url']) . '">';
            }
            echo '<input type="hidden" name="invitation_id" value="' . e($row['invitation_id']) . '"><input type="hidden" name="revision" value="' . (int) $row['revision'] . '">';
            echo '<button type="submit" class="secondary"' . ($action['disabled'] ? ' disabled' : '') . '>' . e($action['label']) . '</button></form>';
        }
        echo '</article>';
    }
    echo '<p><a class="button secondary" href="' . e($model['collaborations_url']) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="cooperative_proposals" data-admin-side-panel-title="' . e($labels['collaborations']) . '" data-gallery-side-panel-url="' . e($model['collaborations_url'] . '&panel=1') . '">' . e($labels['collaborations']) . '</a></p>';
    echo '<nav aria-label="' . e($labels['title']) . '">';
    view_cooperative_link($model['refresh_url'], $labels['refresh'], $labels['title']);
    view_cooperative_link($model['first_url'], $labels['first'], $labels['title']);
    view_cooperative_link($model['next_url'], $labels['next'], $labels['title']);
    echo '</nav></section>';
}
