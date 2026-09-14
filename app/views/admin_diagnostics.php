<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_diagnostics.php
 * Module Type: View
 *
 * Purpose:
 *   Renders Admin runtime diagnostics from controller-prepared environment data.
 *
 * Responsibilities:
 *   - Render runtime, schema-health, DNG, Imagick, and GD diagnostics
 *   - Render the copy-report UI without collecting runtime state
 *   - Keep request handling and capability probing outside the view
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
 *   - Translation, escaping, CSRF fields, and URL generation are presentation helpers only.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\url_for;
use function Gallery\Services\t;

/**
 * Render the Admin runtime diagnostics page body.
 *
 * @param array<string, mixed> $viewModel Prepared diagnostics presentation model.
 */
function view_render_admin_diagnostics_page(array $viewModel): void
{
    $diagnostics = (array) ($viewModel['diagnostics'] ?? []);
    $dngPolicy = (array) ($viewModel['dng_policy'] ?? []);
    $securitySchemaHealth = (array) ($viewModel['security_schema_health'] ?? []);
    $mutationSchemaHealth = (array) ($viewModel['mutation_schema_health'] ?? []);
    $presentationSchemaHealth = (array) ($viewModel['presentation_schema_health'] ?? []);
    $schemaFeatureLabels = (array) ($viewModel['schema_feature_labels'] ?? []);
    $reportText = (string) ($viewModel['report_text'] ?? '');
    $notice = (string) ($viewModel['notice'] ?? '');
    $imagickLoaded = !empty($viewModel['imagick_loaded']);
    $imagickFormats = (array) ($viewModel['imagick_formats'] ?? []);
    $gdInfo = (array) ($viewModel['gd_info'] ?? []);
    $sourcePolicyOptions = (array) ($viewModel['source_policy_options'] ?? []);
    $colorPolicyOptions = (array) ($viewModel['color_policy_options'] ?? []);
    echo '<section class="hero"><div><p class="admin-kicker">' . e(t('admin.diagnostics.kicker', 'Advanced tools')) . '</p><h1>' . e(t('admin.diagnostics.title', 'Runtime diagnostics')) . '</h1><p class="muted">' . e(t('admin.diagnostics.description', 'Use this page to check whether the current hosting environment can read HEIC, DNG, and WebP through PHP. This page is admin-only and intended for troubleshooting image conversion support.')) . '</p></div><div class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin_search_diagnostics')) . '">' . e(t('admin.search_diagnostics.open_button', 'Open search diagnostics')) . '</a><a class="button secondary" href="' . e(url_for('admin_update')) . '#admin-update-tab-advanced">' . e(t('admin.diagnostics.back_to_advanced', 'Back to advanced tools')) . '</a></div></section>';
    echo '<section class="panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.diagnostics.copy_kicker', 'Clipboard')) . '</p><h2>' . e(t('admin.diagnostics.copy_title', 'Copy full report')) . '</h2></div><div class="admin-hero-actions"><button type="button" class="secondary" data-diagnostics-copy>' . e(t('admin.diagnostics.copy_button', 'Copy everything')) . '</button></div></div><p class="muted">' . e(t('admin.diagnostics.copy_hint', 'Copies a plain-text report that is safe to paste into chat or issue reports.')) . '</p><textarea readonly class="admin-diagnostics-copy-source" data-diagnostics-copy-source rows="18">' . e($reportText) . '</textarea></section>';

    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }

    echo '<section class="panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.diagnostics.security_schema_kicker', 'Database protection')) . '</p><h2>' . e(t('admin.diagnostics.security_schema_title', 'Security and authentication database status')) . '</h2></div><p class="muted">' . e(t('admin.diagnostics.security_schema_description', 'These checks distinguish verified schema, confirmed pending migrations, and temporary metadata-inspection failures. Security-sensitive operations never use an unknown state as a legacy fallback.')) . '</p></div>';
    foreach ($securitySchemaHealth as $feature => $schemaHealth) {
        if (!is_array($schemaHealth)) {
            continue;
        }
        $state = (string) ($schemaHealth['state'] ?? 'unknown');
        echo '<div class="account-settings-readiness ' . ($state === 'available' || $state === 'disabled' ? 'is-ready' : 'is-incomplete') . '"><strong>' . e($schemaFeatureLabels[$feature] ?? $feature) . '</strong> ';
        if ($feature === 'nsfw_guard') {
            if ($state === 'available') {
                echo e(t('admin.dashboard.nsfw_schema_available', 'Required gallery and image protection columns are installed and verified.'));
            } elseif ($state === 'missing') {
                echo e(t('admin.dashboard.nsfw_schema_missing', 'Database inspection succeeded and confirmed that an NSFW Guard column is missing. Apply pending database migrations before enabling this protection.'));
            } elseif ($state === 'disabled') {
                echo e(t('admin.dashboard.nsfw_schema_disabled', 'This feature is intentionally disabled by configuration. Its database readiness does not currently affect public requests.'));
            } else {
                echo e(t('admin.dashboard.nsfw_schema_unknown', 'The application could not inspect the database schema required by NSFW Guard. Check database connectivity, the selected database, and schema-inspection permissions. Public NSFW-sensitive requests are temporarily refused.'));
            }
        } elseif ($state === 'available') {
            echo e(t('admin.dashboard.security_schema_available', 'Required database objects are installed and verified.'));
        } elseif ($state === 'missing') {
            echo e(t('admin.dashboard.security_schema_missing', 'Database inspection succeeded and confirmed required objects are missing. Apply pending migrations; only explicitly documented legacy compatibility remains active.'));
        } elseif ($state === 'disabled') {
            echo e(t('admin.dashboard.security_schema_disabled', 'This integration is intentionally disabled by configuration.'));
        } else {
            echo e(t('admin.dashboard.security_schema_unknown', 'Required database schema could not be verified. Security-sensitive operations for this capability are temporarily refused until connectivity, selected database, and metadata permissions are healthy.'));
        }
        $affectedObjects = array_values(array_filter(array_map('strval', (array) ($schemaHealth['affected_objects'] ?? []))));
        if ($affectedObjects !== []) {
            echo '<small>' . e(t('admin.dashboard.security_schema_objects', 'Affected database objects: {objects}', ['objects' => implode(', ', $affectedObjects)])) . '</small>';
        }
        if ((string) ($schemaHealth['request_id'] ?? '') !== '') {
            echo '<small>' . e(t('public.request_reference', 'Reference: {request_id}', ['request_id' => (string) $schemaHealth['request_id']])) . '</small>';
        }
        echo '</div>';
    }
    echo '</section>';

    echo '<section class="panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.diagnostics.mutation_schema_kicker', 'Mutation safety')) . '</p><h2>' . e(t('admin.diagnostics.mutation_schema_title', 'Destructive and ingestion database status')) . '</h2></div><p class="muted">' . e(t('admin.diagnostics.mutation_schema_description', 'These checks cover deletion, moves, uploads, migration, thumbnail maintenance, database repair, and update activation. Unknown schema state pauses the affected mutation before irreversible filesystem or credential changes.')) . '</p></div>';
    foreach ($mutationSchemaHealth as $feature => $schemaHealth) {
        if (!is_array($schemaHealth)) {
            continue;
        }
        $state = (string) ($schemaHealth['state'] ?? 'unknown');
        echo '<div class="account-settings-readiness ' . ($state === 'available' ? 'is-ready' : 'is-incomplete') . '"><strong>' . e($schemaFeatureLabels[$feature] ?? $feature) . '</strong> ';
        if ($state === 'available') {
            echo e(t('admin.dashboard.mutation_schema_available', 'Required mutation database objects are installed and verified.'));
        } elseif ($state === 'missing') {
            echo e(t('admin.dashboard.mutation_schema_missing', 'Database inspection succeeded and confirmed required mutation objects are missing. Apply pending migrations before using this workflow, except where a documented legacy compatibility path explicitly applies.'));
        } else {
            echo e(t('admin.dashboard.mutation_schema_unknown', 'Required database schema could not be verified. This mutation is temporarily refused so files, rows, credentials, migration state, or active application files are not changed on an indeterminate schema.'));
        }
        $affectedObjects = array_values(array_filter(array_map('strval', (array) ($schemaHealth['affected_objects'] ?? []))));
        if ($affectedObjects !== []) {
            echo '<small>' . e(t('admin.dashboard.security_schema_objects', 'Affected database objects: {objects}', ['objects' => implode(', ', $affectedObjects)])) . '</small>';
        }
        if ((string) ($schemaHealth['request_id'] ?? '') !== '') {
            echo '<small>' . e(t('public.request_reference', 'Reference: {request_id}', ['request_id' => (string) $schemaHealth['request_id']])) . '</small>';
        }
        echo '</div>';
    }
    echo '</section>';

    echo '<section class="panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.diagnostics.presentation_schema_kicker', 'Optional features')) . '</p><h2>' . e(t('admin.diagnostics.presentation_schema_title', 'Presentation and reporting database status')) . '</h2></div><p class="muted">' . e(t('admin.diagnostics.presentation_schema_description', 'These checks cover maps, voting, picture game, lightbox overrides, AI assistance, navigation data, telemetry reports, SimBrief route persistence, and the complete Admin report. Unknown read-only capabilities may be omitted safely; dependent writes are refused.')) . '</p></div>';
    foreach ($presentationSchemaHealth as $feature => $schemaHealth) {
        if (!is_array($schemaHealth)) {
            continue;
        }
        $state = (string) ($schemaHealth['state'] ?? 'unknown');
        echo '<div class="account-settings-readiness ' . ($state === 'available' || $state === 'disabled' ? 'is-ready' : 'is-incomplete') . '"><strong>' . e($schemaFeatureLabels[$feature] ?? $feature) . '</strong> ';
        if ($state === 'available') {
            echo e(t('admin.dashboard.presentation_schema_available', 'Required optional database objects are installed and verified.'));
        } elseif ($state === 'missing') {
            echo e(t('admin.dashboard.presentation_schema_missing', 'Database inspection succeeded and confirmed optional objects are missing. The affected presentation/report feature is omitted until its migration is applied.'));
        } elseif ($state === 'disabled') {
            echo e(t('admin.dashboard.presentation_schema_disabled', 'This optional feature is intentionally disabled by configuration.'));
        } else {
            echo e(t('admin.dashboard.presentation_schema_unknown', 'Optional database schema could not be verified. Safe core pages may continue without the affected presentation feature, while writes that depend on it are refused until inspection is healthy.'));
        }
        $affectedObjects = array_values(array_filter(array_map('strval', (array) ($schemaHealth['affected_objects'] ?? []))));
        if ($affectedObjects !== []) {
            echo '<small>' . e(t('admin.dashboard.security_schema_objects', 'Affected database objects: {objects}', ['objects' => implode(', ', $affectedObjects)])) . '</small>';
        }
        if ((string) ($schemaHealth['request_id'] ?? '') !== '') {
            echo '<small>' . e(t('public.request_reference', 'Reference: {request_id}', ['request_id' => (string) $schemaHealth['request_id']])) . '</small>';
        }
        echo '</div>';
    }
    echo '</section>';

    echo '<section class="panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.diagnostics.dng_policy_kicker', 'DNG conversion')) . '</p><h2>' . e(t('admin.diagnostics.dng_policy_title', 'DNG conversion policy')) . '</h2></div><p class="muted">' . e(t('admin.diagnostics.dng_policy_description', 'Choose whether DNG web derivatives prefer the full RAW decode or the embedded camera preview. The default keeps the original fallback behavior.')) . '</p></div>';
    echo '<form method="post" class="settings-form">' . csrf_field();
    echo '<div class="form-grid"><label><span>' . e(t('admin.diagnostics.dng_source_policy', 'Source policy')) . '</span><select name="dng_conversion_source_policy">';
    $sourceLabels = [
        'auto_fallback' => t('admin.diagnostics.dng_source_auto', 'Auto fallback: full RAW first, then preview'),
        'prefer_raw' => t('admin.diagnostics.dng_source_raw', 'Prefer full RAW decode'),
        'prefer_preview' => t('admin.diagnostics.dng_source_preview', 'Prefer embedded camera preview'),
    ];
    foreach ($sourcePolicyOptions as $option) {
        echo '<option value="' . e($option) . '"' . ($dngPolicy['source'] === $option ? ' selected' : '') . '>' . e((string) ($sourceLabels[$option] ?? $option)) . '</option>';
    }
    echo '</select></label><label><span>' . e(t('admin.diagnostics.dng_color_policy', 'Color handling')) . '</span><select name="dng_conversion_color_policy">';
    $colorLabels = [
        'force_srgb' => t('admin.diagnostics.dng_color_srgb', 'Force browser-safe sRGB'),
        'preserve_look' => t('admin.diagnostics.dng_color_preserve', 'Preserve decoded look as much as possible'),
        'camera_white_balance' => t('admin.diagnostics.dng_color_camera_wb', 'Use camera white balance when available'),
    ];
    foreach ($colorPolicyOptions as $option) {
        echo '<option value="' . e($option) . '"' . ($dngPolicy['color'] === $option ? ' selected' : '') . '>' . e((string) ($colorLabels[$option] ?? $option)) . '</option>';
    }
    echo '</select></label></div>';
    echo '<p class="muted">' . e((string) ($dngPolicy['status']['reason'] ?? '')) . '</p>';
    echo '<div class="bulk-row"><button type="submit">' . e(t('admin.diagnostics.dng_policy_save', 'Save DNG policy')) . '</button></div></form></section>';

    echo '<section class="panel"><h2>' . e(t('admin.diagnostics.summary_title', 'Environment summary')) . '</h2><div class="admin-metric-grid">';
    foreach ($diagnostics as $row) {
        echo '<article class="admin-metric-card"><span>' . e((string) $row['label']) . '</span><strong>' . e((string) $row['value']) . '</strong></article>';
    }
    echo '</div></section>';

    echo '<section class="panel"><h2>' . e(t('admin.diagnostics.imagick_title', 'Imagick format support')) . '</h2>';
    if (!$imagickLoaded) {
        echo '<p class="muted">' . e(t('admin.diagnostics.imagick_missing', 'Imagick is not loaded on this server. HEIC, DNG, and WebP conversion will not be available through the PHP image pipeline unless the host enables it.')) . '</p>';
    } else {
        echo '<div class="admin-language-table-wrap"><table class="admin-table"><thead><tr><th>' . e(t('admin.diagnostics.format', 'Format')) . '</th><th>' . e(t('admin.diagnostics.support', 'Support')) . '</th></tr></thead><tbody>';
        foreach ($imagickFormats as $format => $formats) {
            $supported = !empty($formats) ? t('admin.diagnostics.support_yes', 'Yes') : t('admin.diagnostics.support_no', 'No');
            echo '<tr><td><code>' . e($format) . '</code></td><td>' . e($supported) . '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<p class="muted">' . e(t('admin.diagnostics.imagick_note', 'A format may appear here only when the underlying ImageMagick build includes the required delegate libraries.')) . '</p>';
    }
    echo '</section>';

    echo '<section class="panel"><h2>' . e(t('admin.diagnostics.gd_title', 'GD capabilities')) . '</h2>';
    if (empty($gdInfo)) {
        echo '<p class="muted">' . e(t('admin.diagnostics.gd_missing', 'GD is not available on this server.')) . '</p>';
    } else {
        echo '<div class="admin-language-table-wrap"><table class="admin-table"><thead><tr><th>' . e(t('admin.diagnostics.metric', 'Metric')) . '</th><th>' . e(t('admin.diagnostics.value', 'Value')) . '</th></tr></thead><tbody>';
        foreach ($gdInfo as $label => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map(static fn ($item): string => (string) $item, $value));
            }
            echo '<tr><td>' . e((string) $label) . '</td><td>' . e((string) $value) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
    echo '<script>(function(){var button=document.querySelector("[data-diagnostics-copy]");var source=document.querySelector("[data-diagnostics-copy-source]");if(!button||!source){return;}button.addEventListener("click",function(){var text=source.value||"";var original=button.textContent||"";var done=function(){button.textContent=' . json_encode(t('admin.diagnostics.copy_done', 'Copied')) . ';setTimeout(function(){button.textContent=original;},1400);};if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(text).then(done).catch(function(){source.focus();source.select();try{document.execCommand("copy");done();}catch(e){}});return;}source.focus();source.select();try{document.execCommand("copy");done();}catch(e){}});})();</script>';
}
