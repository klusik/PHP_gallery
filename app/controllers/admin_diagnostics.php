<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_diagnostics.php
 * Module Type: Controller
 *
 * Purpose:
 *   Renders an admin-only runtime diagnostics page for image conversion and host capability checks.
 *
 * Responsibilities:
 *   - Require admin authentication
 *   - Summarize PHP, extensions, and image-format support in a compact form
 *   - Help operators verify HEIC, DNG, and WebP availability on shared hosting
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
 *   2026-06-04
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Imagick;
use Throwable;
use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\dng_conversion_attempt_order;
use function Gallery\Services\dng_conversion_color_policy;
use function Gallery\Services\dng_conversion_color_policy_options;
use function Gallery\Services\dng_conversion_runtime_capabilities;
use function Gallery\Services\dng_conversion_source_policy;
use function Gallery\Services\dng_conversion_source_policy_options;
use function Gallery\Services\dng_derivative_generation_status;
use function Gallery\Services\dng_normalize_conversion_color_policy;
use function Gallery\Services\dng_normalize_conversion_source_policy;
use function Gallery\Services\set_app_setting;
use function Gallery\Services\t;
use function Gallery\Services\admin_log_event;

/**
 * Handle cms admin diagnostics.
 *
 * Used by HTTP controller routing for this workflow.
 */
function cms_admin_diagnostics(): void
{
    require_admin();

    if (request_method() === 'POST') {
        verify_csrf();
        $sourcePolicy = dng_normalize_conversion_source_policy((string) ($_POST['dng_conversion_source_policy'] ?? 'auto_fallback'));
        $colorPolicy = dng_normalize_conversion_color_policy((string) ($_POST['dng_conversion_color_policy'] ?? 'force_srgb'));
        set_app_setting('dng_conversion_source_policy', $sourcePolicy);
        set_app_setting('dng_conversion_color_policy', $colorPolicy);
        admin_log_event('info', 'diagnostics.dng_policy_updated', 'Admin updated DNG conversion policy.', [
            'source_policy' => $sourcePolicy,
            'color_policy' => $colorPolicy,
        ]);
        flash_message('admin_notice', t('admin.diagnostics.dng_policy_saved', 'DNG conversion policy saved.'));
        redirect_to(url_for('admin_diagnostics', ['saved' => 1]));
    }

    $imagickLoaded = class_exists('Imagick');
    $imagickFormats = [];
    if ($imagickLoaded) {
        foreach (['HEIC', 'HEIF', 'DNG', 'WEBP', 'JPEG', 'JPG', 'PNG', 'TIFF'] as $format) {
            try {
                $formats = Imagick::queryFormats($format);
                $imagickFormats[$format] = is_array($formats) ? array_values(array_unique(array_map('strtoupper', $formats))) : [];
            } catch (Throwable) {
                $imagickFormats[$format] = [];
            }
        }
    }

    $gdInfo = function_exists('gd_info') ? gd_info() : [];
    $diagnostics = [
        ['label' => 'PHP version', 'value' => phpversion() ?: PHP_VERSION],
        ['label' => 'SAPI', 'value' => PHP_SAPI],
        ['label' => 'Loaded extensions', 'value' => implode(', ', get_loaded_extensions())],
        ['label' => 'Imagick loaded', 'value' => $imagickLoaded ? 'yes' : 'no'],
        ['label' => 'GD loaded', 'value' => function_exists('imagecreatetruecolor') ? 'yes' : 'no'],
        ['label' => 'WebP function', 'value' => function_exists('imagewebp') ? 'yes' : 'no'],
        ['label' => 'EXIF function', 'value' => function_exists('exif_read_data') ? 'yes' : 'no'],
        ['label' => 'Shell exec', 'value' => function_exists('shell_exec') ? 'yes' : 'no'],
        ['label' => 'upload_max_filesize', 'value' => (string) ini_get('upload_max_filesize')],
        ['label' => 'post_max_size', 'value' => (string) ini_get('post_max_size')],
        ['label' => 'memory_limit', 'value' => (string) ini_get('memory_limit')],
    ];
    $dngPolicy = [
        'source' => dng_conversion_source_policy(),
        'color' => dng_conversion_color_policy(),
        'status' => dng_derivative_generation_status(),
        'capabilities' => dng_conversion_runtime_capabilities(),
        'attempts' => dng_conversion_attempt_order(dng_conversion_source_policy(), dng_conversion_runtime_capabilities()),
    ];
    $reportLines = [
        'PHP Gallery runtime diagnostics',
        'PHP version: ' . ($diagnostics[0]['value'] ?? ''),
        'SAPI: ' . ($diagnostics[1]['value'] ?? ''),
        'Loaded extensions: ' . ($diagnostics[2]['value'] ?? ''),
        'Imagick loaded: ' . ($diagnostics[3]['value'] ?? ''),
        'GD loaded: ' . ($diagnostics[4]['value'] ?? ''),
        'WebP function: ' . ($diagnostics[5]['value'] ?? ''),
        'EXIF function: ' . ($diagnostics[6]['value'] ?? ''),
        'Shell exec: ' . ($diagnostics[7]['value'] ?? ''),
        'upload_max_filesize: ' . ($diagnostics[8]['value'] ?? ''),
        'post_max_size: ' . ($diagnostics[9]['value'] ?? ''),
        'memory_limit: ' . ($diagnostics[10]['value'] ?? ''),
        '',
        'DNG conversion policy',
        'Source policy: ' . $dngPolicy['source'],
        'Color policy: ' . $dngPolicy['color'],
        'Runtime paths: ' . implode(', ', $dngPolicy['attempts']),
        'Status: ' . (string) ($dngPolicy['status']['reason'] ?? ''),
        '',
        'Imagick format support',
    ];
    if (!$imagickLoaded) {
        $reportLines[] = 'Imagick: not loaded';
    } else {
        foreach ($imagickFormats as $format => $formats) {
            $reportLines[] = $format . ': ' . (!empty($formats) ? 'yes (' . implode(', ', $formats) . ')' : 'no');
        }
    }
    $reportLines[] = '';
    $reportLines[] = 'GD capabilities';
    if (empty($gdInfo)) {
        $reportLines[] = 'GD: not available';
    } else {
        foreach ($gdInfo as $label => $value) {
            if (is_array($value)) {
                $value = implode(', ', array_map(static fn ($item): string => (string) $item, $value));
            }
            $reportLines[] = (string) $label . ': ' . (string) $value;
        }
    }
    $reportText = implode("\n", $reportLines);

    render_header(t('admin.diagnostics.page_title', 'Runtime diagnostics'));
    echo '<section class="hero"><div><p class="admin-kicker">' . e(t('admin.diagnostics.kicker', 'Advanced tools')) . '</p><h1>' . e(t('admin.diagnostics.title', 'Runtime diagnostics')) . '</h1><p class="muted">' . e(t('admin.diagnostics.description', 'Use this page to check whether the current hosting environment can read HEIC, DNG, and WebP through PHP. This page is admin-only and intended for troubleshooting image conversion support.')) . '</p></div><div class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin_update')) . '#admin-update-tab-advanced">' . e(t('admin.diagnostics.back_to_advanced', 'Back to advanced tools')) . '</a></div></section>';
    echo '<section class="panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.diagnostics.copy_kicker', 'Clipboard')) . '</p><h2>' . e(t('admin.diagnostics.copy_title', 'Copy full report')) . '</h2></div><div class="admin-hero-actions"><button type="button" class="secondary" data-diagnostics-copy>' . e(t('admin.diagnostics.copy_button', 'Copy everything')) . '</button></div></div><p class="muted">' . e(t('admin.diagnostics.copy_hint', 'Copies a plain-text report that is safe to paste into chat or issue reports.')) . '</p><textarea readonly class="admin-diagnostics-copy-source" data-diagnostics-copy-source rows="18">' . e($reportText) . '</textarea></section>';

    $notice = (string) flash_message('admin_notice');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }

    echo '<section class="panel"><div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.diagnostics.dng_policy_kicker', 'DNG conversion')) . '</p><h2>' . e(t('admin.diagnostics.dng_policy_title', 'DNG conversion policy')) . '</h2></div><p class="muted">' . e(t('admin.diagnostics.dng_policy_description', 'Choose whether DNG web derivatives prefer the full RAW decode or the embedded camera preview. The default keeps the original fallback behavior.')) . '</p></div>';
    echo '<form method="post" class="settings-form">' . csrf_field();
    echo '<div class="form-grid"><label><span>' . e(t('admin.diagnostics.dng_source_policy', 'Source policy')) . '</span><select name="dng_conversion_source_policy">';
    $sourceLabels = [
        'auto_fallback' => t('admin.diagnostics.dng_source_auto', 'Auto fallback: full RAW first, then preview'),
        'prefer_raw' => t('admin.diagnostics.dng_source_raw', 'Prefer full RAW decode'),
        'prefer_preview' => t('admin.diagnostics.dng_source_preview', 'Prefer embedded camera preview'),
    ];
    foreach (dng_conversion_source_policy_options() as $option) {
        echo '<option value="' . e($option) . '"' . ($dngPolicy['source'] === $option ? ' selected' : '') . '>' . e((string) ($sourceLabels[$option] ?? $option)) . '</option>';
    }
    echo '</select></label><label><span>' . e(t('admin.diagnostics.dng_color_policy', 'Color handling')) . '</span><select name="dng_conversion_color_policy">';
    $colorLabels = [
        'force_srgb' => t('admin.diagnostics.dng_color_srgb', 'Force browser-safe sRGB'),
        'preserve_look' => t('admin.diagnostics.dng_color_preserve', 'Preserve decoded look as much as possible'),
        'camera_white_balance' => t('admin.diagnostics.dng_color_camera_wb', 'Use camera white balance when available'),
    ];
    foreach (dng_conversion_color_policy_options() as $option) {
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

    render_footer();
}
