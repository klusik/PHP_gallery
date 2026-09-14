<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_render_profiler.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders the Admin dashboard request-profiler panel.
 *
 * Responsibilities:
 *   - Render profiler counters from prepared data
 *   - Render profiler timing rows from prepared data
 *   - Keep diagnostic markup outside the Service layer
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
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Services\t;

/**
 * Render the admin-only dashboard profile diagnostic panel.
 *
 * @param array<string, mixed>|null $model Prepared profile model.
 */
function view_render_admin_render_profile_panel(?array $model): void
{
    if ($model === null) {
        return;
    }
    $counters = is_array($model['counters'] ?? null) ? $model['counters'] : [];
    $timers = is_array($model['timers'] ?? null) ? $model['timers'] : [];

    echo '<details class="admin-render-profile" data-admin-render-profile open>';
    echo '<summary>' . e(t('dev.admin_render_profile.title', 'Admin render profile'));
    if ((string) ($model['route'] ?? '') !== '') {
        echo ' · ' . e((string) $model['route']);
    }
    echo '</summary>';
    echo '<div class="admin-render-profile-grid">';
    echo '<section><h2>' . e(t('dev.admin_render_profile.counters', 'Counters')) . '</h2><table><tbody>';
    foreach ($counters as $name => $value) {
        echo '<tr><th>' . e(str_replace('_', ' ', (string) $name)) . '</th><td>' . number_format((float) $value, 0, '.', ' ') . '</td></tr>';
    }
    echo '</tbody></table></section>';
    echo '<section><h2>' . e(t('dev.admin_render_profile.timers', 'Timers')) . '</h2><table><thead><tr><th>' . e(t('dev.admin_render_profile.name', 'Name')) . '</th><th>' . e(t('dev.admin_render_profile.count', 'Count')) . '</th><th>' . e(t('dev.admin_render_profile.total_ms', 'Total ms')) . '</th><th>' . e(t('dev.admin_render_profile.max_ms', 'Max ms')) . '</th></tr></thead><tbody>';
    foreach ($timers as $name => $timer) {
        echo '<tr><th>' . e(str_replace('_', ' ', (string) $name)) . '</th><td>' . (int) $timer['count'] . '</td><td>' . number_format((float) $timer['total_ms'], 2, '.', ' ') . '</td><td>' . number_format((float) $timer['max_ms'], 2, '.', ' ') . '</td></tr>';
    }
    echo '</tbody></table></section>';
    echo '</div>';
    echo '<p class="admin-render-profile-note">' . e(t('dev.admin_render_profile.admin_only_note', 'Admin-only diagnostics for /index.php?page=admin. Use this output to identify first-load cost before optimizing.')) . '</p>';
    echo '</details>';
}
