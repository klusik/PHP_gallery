<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_report/render.php
 * Module Type: Service
 *
 * Purpose:
 *   Renders the self-contained HTML report document.
 *
 * Responsibilities:
 *   - Render the complete report document and its per-section markup
 *   - Provide reusable metric card, definition list, and table fragments
 *   - Inline the report stylesheet so the download stays self-contained
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
 *   - Loaded by app/services/admin_gallery_report.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_gallery_report.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;

/**
 * Render the complete standalone report HTML.
 *
 * @param array $report Report data.
 * @return string HTML document.
 */
function admin_gallery_report_render_html(array $report): string
{
    $site = is_array($report['site'] ?? null) ? $report['site'] : [];
    $runtime = is_array($report['runtime'] ?? null) ? $report['runtime'] : [];
    $storage = is_array($report['storage'] ?? null) ? $report['storage'] : [];
    $database = is_array($report['database'] ?? null) ? $report['database'] : [];
    $galleries = is_array($report['galleries'] ?? null) ? $report['galleries'] : [];
    $images = is_array($report['image_summary'] ?? null) ? $report['image_summary'] : [];
    $telemetry = is_array($report['telemetry'] ?? null) ? $report['telemetry'] : [];
    $logs = is_array($report['logs'] ?? null) ? $report['logs'] : [];
    $title = t('admin.gallery_report.export.title', 'PHP Gallery complete overview report');

    $html = '<!doctype html><html lang="' . admin_gallery_report_h((string) ($site['language'] ?? 'en')) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . admin_gallery_report_h($title) . '</title><style>' . admin_gallery_report_css() . '</style></head><body><main>';
    $html .= '<header><p class="eyebrow">' . admin_gallery_report_h(t('admin.gallery_report.export.kicker', 'PHP Gallery Admin Report')) . '</p><h1>' . admin_gallery_report_h($title) . '</h1><p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.generated_note', 'Generated {time} UTC. This is a single self-contained HTML file. It was generated in browser-driven batches and was not saved as a report file on the server.', ['time' => (string) ($site['generated_at_utc'] ?? gmdate('c'))])) . '</p><div class="print-note">' . admin_gallery_report_h(t('admin.gallery_report.export.print_note', 'Use the browser print dialog to save this HTML as PDF when needed.')) . '</div></header>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.executive_overview', 'Executive overview')) . '</h2><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.version', 'Version'), (string) ($site['version'] ?? ''), (string) ($site['site_name'] ?? 'PHP Gallery'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.galleries', 'Galleries'), admin_gallery_report_n($galleries['total'] ?? 0), t('admin.gallery_report.export.root_nested', '{root} root, {nested} nested', ['root' => admin_gallery_report_n($galleries['root_count'] ?? 0), 'nested' => admin_gallery_report_n($galleries['nested_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.images', 'Images'), admin_gallery_report_n($images['image_count'] ?? 0), t('admin.gallery_report.export.public_images', '{count} public', ['count' => admin_gallery_report_n($images['public_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.source_photos', 'Source photos'), admin_gallery_report_bytes($storage['original_bytes'] ?? 0), t('admin.gallery_report.export.average_size', 'Average {size}', ['size' => admin_gallery_report_bytes($storage['average_original_bytes'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.generated_media', 'Generated media'), admin_gallery_report_bytes($storage['generated_bytes'] ?? 0), t('admin.gallery_report.export.thumbnail_count', '{count} thumbnails', ['count' => admin_gallery_report_n($storage['generated_thumbnail_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.database', 'Database'), admin_gallery_report_bytes((int) ($database['usage']['total_bytes'] ?? 0)), (string) ($database['database_name'] ?? ''))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.exif_date', 'EXIF date'), admin_gallery_report_percent($images['exif_date_count'] ?? 0, $images['image_count'] ?? 0), t('admin.gallery_report.export.image_count', '{count} images', ['count' => admin_gallery_report_n($images['exif_date_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.gps', 'GPS'), admin_gallery_report_percent($images['gps_count'] ?? 0, $images['image_count'] ?? 0), t('admin.gallery_report.export.image_count', '{count} images', ['count' => admin_gallery_report_n($images['gps_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.telemetry_window', 'Telemetry window'), t('admin.gallery_report.export.day_count', '{count} days', ['count' => admin_gallery_report_n($telemetry['days'] ?? 0)]), !empty($telemetry['available']) ? t('admin.gallery_report.export.available', 'available') : t('admin.gallery_report.export.not_available', 'not available'))
        . '</div></section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.generation_runtime', 'Generation and runtime')) . '</h2><div class="split"><div>'
        . admin_gallery_report_definition_list([
            t('admin.gallery_report.export.report_duration', 'Report duration') => admin_gallery_report_duration((int) ($report['duration_seconds'] ?? 0)),
            t('admin.gallery_report.export.images_processed', 'Images processed') => admin_gallery_report_n($report['processed'] ?? 0) . ' / ' . admin_gallery_report_n($report['total'] ?? 0),
            'PHP' => (string) ($runtime['php_version'] ?? ''),
            'SAPI' => (string) ($runtime['php_sapi'] ?? ''),
            t('admin.gallery_report.export.server_software', 'Server software') => (string) ($runtime['server_software'] ?? ''),
            'OS' => (string) ($runtime['os'] ?? ''),
            'MySQL/MariaDB' => (string) ($runtime['mysql_version'] ?? ''),
            t('admin.gallery_report.export.memory_limit', 'Memory limit') => (string) ($runtime['memory_limit'] ?? ''),
            t('admin.gallery_report.export.peak_memory_used', 'Peak memory used') => admin_gallery_report_bytes($runtime['peak_memory_usage_bytes'] ?? 0),
            t('admin.gallery_report.export.server_ram', 'Server RAM') => admin_gallery_report_server_memory_label(is_array($runtime['server_memory'] ?? null) ? $runtime['server_memory'] : []),
            t('admin.gallery_report.export.load_average', 'Load average') => admin_gallery_report_load_average_label(is_array($runtime['load_average'] ?? null) ? $runtime['load_average'] : []),
            'GD' => admin_gallery_report_gd_label(is_array($runtime['gd_info'] ?? null) ? $runtime['gd_info'] : []),
            'Imagick' => (string) ($runtime['imagick_version'] ?? ''),
            t('admin.gallery_report.export.max_execution_time', 'Max execution time') => (string) ($runtime['max_execution_time'] ?? '') . ' s',
            t('admin.gallery_report.export.upload_max_filesize', 'Upload max filesize') => (string) ($runtime['upload_max_filesize'] ?? ''),
            t('admin.gallery_report.export.post_max_size', 'Post max size') => (string) ($runtime['post_max_size'] ?? ''),
            t('admin.gallery_report.export.timezone', 'Timezone') => (string) ($runtime['timezone'] ?? ''),
        ]) . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.php_extensions', 'PHP extensions')) . '</h3>' . admin_gallery_report_table($runtime['extensions'] ?? [], [
            ['key' => 'extension', 'label' => t('admin.gallery_report.export.extension', 'Extension')],
            ['key' => 'loaded', 'label' => t('admin.gallery_report.export.loaded', 'Loaded')],
        ]) . '</div></div></section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.storage_details', 'Storage details')) . '</h2><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.original_sources', 'Original sources'), admin_gallery_report_bytes($storage['original_bytes'] ?? 0), t('admin.gallery_report.export.known_sizes', '{count} known sizes', ['count' => admin_gallery_report_n($storage['known_source_size_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.thumbnails', 'Thumbnails'), admin_gallery_report_bytes($storage['generated_thumbnail_bytes'] ?? 0), t('admin.gallery_report.export.file_count', '{count} files', ['count' => admin_gallery_report_n($storage['generated_thumbnail_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.display_masters', 'Display masters'), admin_gallery_report_bytes($storage['display_master_bytes'] ?? 0), t('admin.gallery_report.export.file_count', '{count} files', ['count' => admin_gallery_report_n($storage['display_master_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.total_picture_storage', 'Total picture storage'), admin_gallery_report_bytes($storage['total_picture_bytes'] ?? 0), t('admin.gallery_report.export.generated_original_ratio', 'Generated/original {percent} %', ['percent' => admin_gallery_report_n($storage['generated_to_original_percent'] ?? 0, 1)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.largest_source', 'Largest source'), admin_gallery_report_bytes($storage['largest_original_bytes'] ?? 0), (string) ($storage['largest_original_name'] ?? ''))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.scan_errors', 'Scan errors'), admin_gallery_report_n($storage['thumbnail_scan_errors'] ?? 0), !empty($storage['thumbnail_metadata_used']) ? t('admin.gallery_report.export.thumbnail_metadata_used', 'thumbnail metadata used') : t('admin.gallery_report.export.filesystem_checks_used', 'filesystem checks used'))
        . '</div><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.source_types', 'Source types')) . '</h3>' . admin_gallery_report_bar_table($storage['type_rows'] ?? [], 'count', 'bytes') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.generated_media_types', 'Generated media types')) . '</h3>' . admin_gallery_report_bar_table($storage['generated_type_rows'] ?? [], 'count', 'bytes') . '</div></div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.configured_data_paths', 'Configured data paths')) . '</h3>' . admin_gallery_report_table($report['data_paths']['paths'] ?? [], [
            ['key' => 'label', 'label' => t('admin.gallery_report.export.path', 'Path')],
            ['key' => 'path', 'label' => t('admin.gallery_report.export.filesystem_value', 'Filesystem value')],
            ['key' => 'exists', 'label' => t('admin.gallery_report.export.exists', 'Exists')],
            ['key' => 'readable', 'label' => t('admin.gallery_report.export.readable', 'Readable')],
            ['key' => 'writable', 'label' => t('admin.gallery_report.export.writable', 'Writable')],
            ['key' => 'free_bytes', 'label' => t('admin.gallery_report.export.free', 'Free'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
            ['key' => 'total_bytes', 'label' => t('admin.gallery_report.export.total', 'Total'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
        ]) . '</section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.database_overview', 'Database overview')) . '</h2><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.total_db_size', 'Total DB size'), admin_gallery_report_bytes($database['usage']['total_bytes'] ?? 0), t('admin.gallery_report.export.data_indexes', 'data + indexes'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.gallery_db_size', 'Gallery DB size'), admin_gallery_report_bytes($database['usage']['gallery_bytes'] ?? 0), t('admin.gallery_report.export.content_related_tables', 'content-related tables'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.operational_db_size', 'Operational DB size'), admin_gallery_report_bytes($database['usage']['operational_bytes'] ?? 0), t('admin.gallery_report.export.operational_tables_hint', 'logs, telemetry, settings, users'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.rows_exact', 'Rows exact'), !empty($database['usage']['table_rows_exact_available']) ? admin_gallery_report_n($database['usage']['table_rows_exact'] ?? 0) : admin_gallery_report_n($database['usage']['table_rows_estimate'] ?? 0), !empty($database['usage']['table_rows_exact_available']) ? t('admin.gallery_report.export.count_across_tables', 'COUNT(*) across DB tables') : t('admin.gallery_report.export.engine_estimate', 'engine estimate'))
        . '</div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.database_tables', 'Database tables')) . '</h3>' . admin_gallery_report_table($database['usage']['table_rows'] ?? [], [
            ['key' => 'table_name', 'label' => t('admin.gallery_report.export.table', 'Table')],
            ['key' => 'rows_exact', 'label' => t('admin.gallery_report.export.rows_exact', 'Rows exact'), 'format' => fn($v): string => $v === null ? '' : admin_gallery_report_n($v)],
            ['key' => 'table_rows_estimate', 'label' => t('admin.gallery_report.export.rows_estimate', 'Rows estimate'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'data_bytes', 'label' => t('admin.gallery_report.export.data', 'Data'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
            ['key' => 'index_bytes', 'label' => t('admin.gallery_report.export.index', 'Index'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
            ['key' => 'total_bytes', 'label' => t('admin.gallery_report.export.total', 'Total'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
            ['key' => 'engine', 'label' => t('admin.gallery_report.export.engine', 'Engine')],
            ['key' => 'row_count_source', 'label' => t('admin.gallery_report.export.row_source', 'Row source')],
        ]) . '<h3>' . admin_gallery_report_h(t('admin.gallery_report.export.application_table_counts', 'Application table counts')) . '</h3>' . admin_gallery_report_table($report['tables'] ?? [], [
            ['key' => 'table_name', 'label' => t('admin.gallery_report.export.table', 'Table')],
            ['key' => 'exists', 'label' => t('admin.gallery_report.export.exists', 'Exists')],
            ['key' => 'rows', 'label' => t('admin.gallery_report.export.rows', 'Rows'), 'format' => fn($v): string => $v === null ? '' : admin_gallery_report_n($v)],
            ['key' => 'error', 'label' => t('admin.gallery_report.export.error', 'Error')],
        ]) . '</section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.gallery_structure', 'Gallery structure')) . '</h2><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.visibility', 'Visibility')) . '</h3>' . admin_gallery_report_bar_table($galleries['visibility_rows'] ?? [], 'count') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.access_mode', 'Access mode')) . '</h3>' . admin_gallery_report_bar_table($galleries['access_rows'] ?? [], 'count') . '</div></div><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.empty_galleries', 'Empty galleries'), admin_gallery_report_n($galleries['empty_count'] ?? 0), t('admin.gallery_report.export.empty_galleries_hint', 'direct image count is zero'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.deepest_nesting', 'Deepest nesting'), admin_gallery_report_n($galleries['deepest_depth'] ?? 0), t('admin.gallery_report.export.parent_chain_depth', 'parent chain depth'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.dated_galleries', 'Dated galleries'), admin_gallery_report_n($galleries['dated_gallery_count'] ?? 0), t('admin.gallery_report.export.manual_date_range_present', 'manual date range present'))
        . '</div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.largest_galleries', 'Largest galleries')) . '</h3>' . admin_gallery_report_table($galleries['largest_rows'] ?? [], [
            ['key' => 'id', 'label' => 'ID'],
            ['key' => 'title', 'label' => t('admin.gallery_report.export.title_column', 'Title')],
            ['key' => 'folder_path', 'label' => t('admin.gallery_report.export.folder', 'Folder')],
            ['key' => 'visibility', 'label' => t('admin.gallery_report.export.visibility', 'Visibility')],
            ['key' => 'image_count', 'label' => t('admin.gallery_report.export.images', 'Images'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'source_bytes', 'label' => t('admin.gallery_report.export.source_bytes', 'Source bytes'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
        ]) . '<h3>' . admin_gallery_report_h(t('admin.gallery_report.export.complete_gallery_list', 'Complete gallery list')) . '</h3>' . admin_gallery_report_table($report['gallery_rows'] ?? [], [
            ['key' => 'id', 'label' => 'ID'],
            ['key' => 'parent_id', 'label' => t('admin.gallery_report.export.parent', 'Parent')],
            ['key' => 'title', 'label' => t('admin.gallery_report.export.title_column', 'Title')],
            ['key' => 'folder_path', 'label' => t('admin.gallery_report.export.folder', 'Folder')],
            ['key' => 'visibility', 'label' => t('admin.gallery_report.export.visibility', 'Visibility')],
            ['key' => 'access_mode', 'label' => t('admin.gallery_report.export.access', 'Access')],
            ['key' => 'access_listing', 'label' => t('admin.gallery_report.export.listing', 'Listing')],
            ['key' => 'image_count', 'label' => t('admin.gallery_report.export.images', 'Images'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'source_bytes', 'label' => t('admin.gallery_report.export.source', 'Source'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
            ['key' => 'gps_images', 'label' => t('admin.gallery_report.export.gps_images', 'GPS images'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'date_start', 'label' => t('admin.gallery_report.export.date_from', 'Date from')],
            ['key' => 'date_end', 'label' => t('admin.gallery_report.export.date_to', 'Date to')],
            ['key' => 'gps_map_enabled', 'label' => t('admin.gallery_report.export.gps_map_override', 'GPS map override')],
            ['key' => 'picture_game_enabled', 'label' => t('admin.gallery_report.export.game', 'Game')],
            ['key' => 'updated_at', 'label' => t('admin.gallery_report.export.updated', 'Updated')],
        ]) . '</section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.images_exif_statistics', 'Images and EXIF statistics')) . '</h2><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.images_with_exif_date', 'Images with EXIF date'), admin_gallery_report_n($images['exif_date_count'] ?? 0), admin_gallery_report_percent($images['exif_date_count'] ?? 0, $images['image_count'] ?? 0))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.images_with_gps', 'Images with GPS'), admin_gallery_report_n($images['gps_count'] ?? 0), admin_gallery_report_percent($images['gps_count'] ?? 0, $images['image_count'] ?? 0))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.both_date_gps', 'Both date and GPS'), admin_gallery_report_n($images['both_exif_date_and_gps_count'] ?? 0), admin_gallery_report_percent($images['both_exif_date_and_gps_count'] ?? 0, $images['image_count'] ?? 0))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.neither_date_gps', 'Neither date nor GPS'), admin_gallery_report_n($images['neither_exif_date_nor_gps_count'] ?? 0), admin_gallery_report_percent($images['neither_exif_date_nor_gps_count'] ?? 0, $images['image_count'] ?? 0))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.known_camera', 'Known camera'), admin_gallery_report_n($images['camera_known_count'] ?? 0), admin_gallery_report_percent($images['camera_known_count'] ?? 0, $images['image_count'] ?? 0))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.known_lens', 'Known lens'), admin_gallery_report_n($images['lens_known_count'] ?? 0), admin_gallery_report_percent($images['lens_known_count'] ?? 0, $images['image_count'] ?? 0))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.average_megapixels', 'Average megapixels'), admin_gallery_report_n($images['average_megapixels'] ?? 0, 2) . ' MP', t('admin.gallery_report.export.images_with_dimensions', '{count} images with dimensions', ['count' => admin_gallery_report_n($images['known_dimensions_count'] ?? 0)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.exif_date_range', 'EXIF date range'), admin_gallery_report_short_date((string) ($images['exif_date_min'] ?? '')) . ' ' . t('admin.gallery_report.export.to', 'to') . ' ' . admin_gallery_report_short_date((string) ($images['exif_date_max'] ?? '')), t('admin.gallery_report.export.from_imported_metadata', 'from imported metadata'))
        . '</div><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.image_types', 'Image types')) . '</h3>' . admin_gallery_report_bar_table($images['type_rows'] ?? [], 'count', 'bytes') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.orientation', 'Orientation')) . '</h3>' . admin_gallery_report_bar_table($images['dimension_rows'] ?? [], 'count') . '</div></div><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.top_cameras', 'Top cameras')) . '</h3>' . admin_gallery_report_bar_table($images['camera_rows'] ?? [], 'count') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.top_lenses', 'Top lenses')) . '</h3>' . admin_gallery_report_bar_table($images['lens_rows'] ?? [], 'count') . '</div></div><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.iso_buckets', 'ISO buckets')) . '</h3>' . admin_gallery_report_bar_table($images['iso_rows'] ?? [], 'count') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.top_focal_lengths', 'Top focal lengths')) . '</h3>' . admin_gallery_report_bar_table($images['focal_rows'] ?? [], 'count') . '</div></div></section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.gps_place_overview', 'GPS place overview')) . '</h2><p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.gps_clustering_note', 'GPS points are clustered into approximate {km} km areas. Probable simulator or game captures are excluded from place clustering using filename, file type, and missing camera EXIF heuristics.', ['km' => admin_gallery_report_n(ADMIN_GALLERY_REPORT_GPS_AREA_KM, 0)])) . '</p><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.gps_points_found', 'GPS points found'), admin_gallery_report_n($images['gps_count'] ?? 0), t('admin.gallery_report.export.all_images_coordinates', 'all images with coordinates'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.clustered_real_places', 'Clustered as real places'), admin_gallery_report_n($images['clustered_gps_count'] ?? 0), t('admin.gallery_report.export.used_place_overview', 'used in place overview'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.excluded_probable_game_gps', 'Excluded probable game GPS'), admin_gallery_report_n($images['probable_game_gps_count'] ?? 0), t('admin.gallery_report.export.not_used_real_world_frequency', 'not used for real-world place frequency'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.latitude_range', 'Latitude range'), admin_gallery_report_n($images['gps_lat_min'] ?? 0, 4) . ' ' . t('admin.gallery_report.export.to', 'to') . ' ' . admin_gallery_report_n($images['gps_lat_max'] ?? 0, 4), t('admin.gallery_report.export.coordinate_bounds', 'coordinate bounds'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.longitude_range', 'Longitude range'), admin_gallery_report_n($images['gps_lng_min'] ?? 0, 4) . ' ' . t('admin.gallery_report.export.to', 'to') . ' ' . admin_gallery_report_n($images['gps_lng_max'] ?? 0, 4), t('admin.gallery_report.export.coordinate_bounds', 'coordinate bounds'))
        . '</div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.frequent_gps_areas', 'Frequent GPS areas')) . '</h3>' . admin_gallery_report_table($images['gps_cluster_rows'] ?? [], [
            ['key' => 'label', 'label' => t('admin.gallery_report.export.area', 'Area')],
            ['key' => 'nearest_reference', 'label' => t('admin.gallery_report.export.reference', 'Reference')],
            ['key' => 'place_match', 'label' => t('admin.gallery_report.export.match', 'Match')],
            ['key' => 'place_distance_km', 'label' => t('admin.gallery_report.export.distance', 'Distance'), 'format' => fn($v): string => $v === null || $v === '' ? '' : admin_gallery_report_n($v, 1) . ' km'],
            ['key' => 'count', 'label' => t('admin.gallery_report.export.images', 'Images'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'lat', 'label' => t('admin.gallery_report.export.center_lat', 'Center lat')],
            ['key' => 'lng', 'label' => t('admin.gallery_report.export.center_lng', 'Center lng')],
            ['key' => 'gallery_count', 'label' => t('admin.gallery_report.export.galleries', 'Galleries'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'gallery_labels', 'label' => t('admin.gallery_report.export.example_galleries', 'Example galleries'), 'format' => fn($v): string => is_array($v) ? implode(', ', array_slice($v, 0, 6)) : (string) $v],
            ['key' => 'first_date', 'label' => t('admin.gallery_report.export.first_exif_date', 'First EXIF date'), 'format' => fn($v): string => admin_gallery_report_short_date((string) $v)],
            ['key' => 'last_date', 'label' => t('admin.gallery_report.export.last_exif_date', 'Last EXIF date'), 'format' => fn($v): string => admin_gallery_report_short_date((string) $v)],
            ['key' => 'sample_images', 'label' => t('admin.gallery_report.export.sample_images', 'Sample images'), 'format' => fn($v): string => is_array($v) ? implode(', ', array_slice($v, 0, 4)) : (string) $v],
        ]) . '</section>';

    $html .= admin_gallery_report_render_telemetry_section($telemetry);
    $html .= admin_gallery_report_render_logs_section($logs);

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.tags_votes_largest', 'Tags, votes, and largest images')) . '</h2><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.gallery_tags', 'Gallery tags')) . '</h3>' . admin_gallery_report_bar_table($report['tags']['gallery_tag_rows'] ?? [], 'count') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.image_tags', 'Image tags')) . '</h3>' . admin_gallery_report_bar_table($report['tags']['image_tag_rows'] ?? [], 'count') . '</div></div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.largest_source_images', 'Largest source images')) . '</h3>' . admin_gallery_report_table($report['top_images'] ?? [], [
            ['key' => 'id', 'label' => 'ID'],
            ['key' => 'filename', 'label' => t('admin.gallery_report.export.filename', 'Filename')],
            ['key' => 'gallery_title', 'label' => t('admin.gallery_report.export.gallery', 'Gallery')],
            ['key' => 'relative_path', 'label' => t('admin.gallery_report.export.relative_path', 'Relative path')],
            ['key' => 'mime_type', 'label' => 'MIME'],
            ['key' => 'file_size', 'label' => t('admin.gallery_report.export.size', 'Size'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
            ['key' => 'width', 'label' => t('admin.gallery_report.export.width', 'Width')],
            ['key' => 'height', 'label' => t('admin.gallery_report.export.height', 'Height')],
            ['key' => 'visibility', 'label' => t('admin.gallery_report.export.visibility', 'Visibility')],
        ]) . '</section>';

    $html .= '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.feature_settings_overview', 'Feature and settings overview')) . '</h2><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.selected_application_settings', 'Selected application settings')) . '</h3>' . admin_gallery_report_table($report['features']['settings_rows'] ?? [], [
            ['key' => 'setting_key', 'label' => t('admin.gallery_report.export.key', 'Key')],
            ['key' => 'setting_value', 'label' => t('admin.gallery_report.export.value', 'Value')],
            ['key' => 'updated_at', 'label' => t('admin.gallery_report.export.updated', 'Updated')],
        ]) . '</section>';

    $html .= '<footer class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.report_notes', 'Report notes')) . '</h2><ul><li>' . admin_gallery_report_h(t('admin.gallery_report.export.note_gps_labels', 'GPS area labels use an expanded offline place list with city, town, and regional references. If a cluster is outside every configured place radius, the report still shows the nearest known reference and marks it as a nearest fallback.')) . '</li><li>' . admin_gallery_report_h(t('admin.gallery_report.export.note_game_gps', 'Simulator and game GPS exclusion is heuristic. It avoids obvious PNG/WebP captures without camera EXIF and names containing simulator or screenshot markers.')) . '</li><li>' . admin_gallery_report_h(t('admin.gallery_report.export.note_database_sizes', 'Database sizes come from MySQL/MariaDB table metadata. Row counts use exact COUNT(*) values when the host allows them; engine estimates are shown separately.')) . '</li><li>' . admin_gallery_report_h(t('admin.gallery_report.export.note_no_server_file', 'No report file was written to the server. The browser received this HTML and can save it locally.')) . '</li></ul></footer>';
    $html .= '</main></body></html>';
    return $html;
}

/**
 * Render telemetry section.
 *
 * @param array $telemetry Telemetry data.
 * @return string HTML fragment.
 */
function admin_gallery_report_render_telemetry_section(array $telemetry): string
{
    if (empty($telemetry['available'])) {
        return '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.telemetry', 'Telemetry')) . '</h2><p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.telemetry_unavailable', 'Telemetry is not available on this installation or the schema is not migrated yet.')) . '</p></section>';
    }
    $session = is_array($telemetry['session_summary'] ?? null) ? $telemetry['session_summary'] : [];
    $html = '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.telemetry_usage', 'Telemetry and usage')) . '</h2><p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.telemetry_window_note', 'Telemetry window: last {days} days. Public telemetry is {state}.', ['days' => (string) ($telemetry['days'] ?? 0), 'state' => !empty($telemetry['public_enabled']) ? t('admin.gallery_report.export.enabled', 'enabled') : t('admin.gallery_report.export.disabled', 'disabled')])) . '</p><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.sessions', 'Sessions'), admin_gallery_report_n($session['sessions'] ?? 0), t('admin.gallery_report.export.anonymous_session_hashes', 'anonymous session hashes'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.page_views', 'Page views'), admin_gallery_report_n($session['page_views'] ?? 0), t('admin.gallery_report.export.per_session', '{count} per session', ['count' => admin_gallery_report_n($session['avg_pages_per_session'] ?? 0, 2)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.photo_views', 'Photo views'), admin_gallery_report_n($session['photo_views'] ?? 0), t('admin.gallery_report.export.per_session', '{count} per session', ['count' => admin_gallery_report_n($session['avg_photos_per_session'] ?? 0, 2)]))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.duration', 'Duration'), admin_gallery_report_duration((int) ($session['duration_seconds'] ?? 0)), t('admin.gallery_report.export.capped_total', 'capped total'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.bounced_sessions', 'Bounced sessions'), admin_gallery_report_n($session['bounced_sessions'] ?? 0), t('admin.gallery_report.export.single_page_sessions', 'single-page sessions'))
        . '</div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.telemetry_windows', 'Telemetry windows')) . '</h3>' . admin_gallery_report_table($telemetry['windows'] ?? [], [
            ['key' => 'days', 'label' => t('admin.gallery_report.export.days', 'Days')],
            ['key' => 'sessions', 'label' => t('admin.gallery_report.export.sessions', 'Sessions'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'page_views', 'label' => t('admin.gallery_report.export.page_views', 'Page views'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'photo_views', 'label' => t('admin.gallery_report.export.photo_views', 'Photo views'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'duration_seconds', 'label' => t('admin.gallery_report.export.duration', 'Duration'), 'format' => fn($v): string => admin_gallery_report_duration((int) $v)],
            ['key' => 'db_queries', 'label' => t('admin.gallery_report.export.db_queries', 'DB queries'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'db_slow', 'label' => t('admin.gallery_report.export.slow_db', 'Slow DB'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'db_failed', 'label' => t('admin.gallery_report.export.failed_db', 'Failed DB'), 'format' => fn($v): string => admin_gallery_report_n($v)],
        ]) . '<div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.top_galleries', 'Top galleries')) . '</h3>' . admin_gallery_report_table($telemetry['top_galleries'] ?? [], [
            ['key' => 'id', 'label' => 'ID'],
            ['key' => 'title', 'label' => t('admin.gallery_report.export.title_column', 'Title')],
            ['key' => 'page_views', 'label' => t('admin.gallery_report.export.page_views', 'Page views'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'photo_views', 'label' => t('admin.gallery_report.export.photo_views', 'Photo views'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'photo_seconds', 'label' => t('admin.gallery_report.export.photo_time', 'Photo time'), 'format' => fn($v): string => admin_gallery_report_duration((int) $v)],
            ['key' => 'media_bytes', 'label' => t('admin.gallery_report.export.media_bytes', 'Media bytes'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
        ]) . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.top_routes', 'Top routes')) . '</h3>' . admin_gallery_report_table($telemetry['top_routes'] ?? [], [
            ['key' => 'route_name', 'label' => t('admin.gallery_report.export.route', 'Route')],
            ['key' => 'page_views', 'label' => t('admin.gallery_report.export.page_views', 'Page views'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'photo_views', 'label' => t('admin.gallery_report.export.photo_views', 'Photo views'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'client_errors', 'label' => t('admin.gallery_report.export.errors', 'Errors'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'media_bytes', 'label' => t('admin.gallery_report.export.media_bytes', 'Media bytes'), 'format' => fn($v): string => admin_gallery_report_bytes($v)],
        ]) . '</div></div><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.browsers', 'Browsers')) . '</h3>' . admin_gallery_report_bar_table($telemetry['browsers'] ?? [], 'sessions') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.devices', 'Devices')) . '</h3>' . admin_gallery_report_bar_table($telemetry['devices'] ?? [], 'sessions') . '</div></div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.performance', 'Performance')) . '</h3>' . admin_gallery_report_table($telemetry['performance'] ?? [], [
            ['key' => 'metric_name', 'label' => t('admin.gallery_report.export.metric', 'Metric')],
            ['key' => 'samples', 'label' => t('admin.gallery_report.export.samples', 'Samples'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'avg_value', 'label' => t('admin.gallery_report.export.average', 'Average'), 'format' => fn($v): string => admin_gallery_report_n($v, 2)],
            ['key' => 'min_value', 'label' => t('admin.gallery_report.export.min', 'Min'), 'format' => fn($v): string => admin_gallery_report_n($v, 2)],
            ['key' => 'max_value', 'label' => t('admin.gallery_report.export.max', 'Max'), 'format' => fn($v): string => admin_gallery_report_n($v, 2)],
        ]) . '<h3>' . admin_gallery_report_h(t('admin.gallery_report.export.client_errors', 'Client errors')) . '</h3>' . admin_gallery_report_table($telemetry['client_errors'] ?? [], [
            ['key' => 'error_kind', 'label' => t('admin.gallery_report.export.error_kind', 'Error kind')],
            ['key' => 'route_name', 'label' => t('admin.gallery_report.export.route', 'Route')],
            ['key' => 'events', 'label' => t('admin.gallery_report.export.events', 'Events'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'last_seen', 'label' => t('admin.gallery_report.export.last_seen', 'Last seen')],
        ]) . '<h3>' . admin_gallery_report_h(t('admin.gallery_report.export.database_hot_spots', 'Database telemetry hot spots')) . '</h3>' . admin_gallery_report_table($telemetry['database_summary'] ?? [], [
            ['key' => 'route_name', 'label' => t('admin.gallery_report.export.route', 'Route')],
            ['key' => 'operation', 'label' => t('admin.gallery_report.export.operation', 'Operation')],
            ['key' => 'table_name', 'label' => t('admin.gallery_report.export.table', 'Table')],
            ['key' => 'query_count', 'label' => t('admin.gallery_report.export.queries', 'Queries'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'failed_count', 'label' => t('admin.gallery_report.export.failed', 'Failed'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'slow_count', 'label' => t('admin.gallery_report.export.slow', 'Slow'), 'format' => fn($v): string => admin_gallery_report_n($v)],
            ['key' => 'latency_ms_sum', 'label' => t('admin.gallery_report.export.latency_sum', 'Latency sum'), 'format' => fn($v): string => admin_gallery_report_n($v) . ' ms'],
            ['key' => 'latency_ms_max', 'label' => t('admin.gallery_report.export.max_latency', 'Max latency'), 'format' => fn($v): string => admin_gallery_report_n($v) . ' ms'],
        ]) . '<h3>' . admin_gallery_report_h(t('admin.gallery_report.export.recent_telemetry_events', 'Recent telemetry events')) . '</h3>' . admin_gallery_report_table($telemetry['recent_events'] ?? [], [
            ['key' => 'occurred_at', 'label' => t('admin.gallery_report.export.time', 'Time')],
            ['key' => 'event_name', 'label' => t('admin.gallery_report.export.event', 'Event')],
            ['key' => 'route_name', 'label' => t('admin.gallery_report.export.route', 'Route')],
            ['key' => 'page_kind', 'label' => t('admin.gallery_report.export.kind', 'Kind')],
            ['key' => 'gallery_id', 'label' => t('admin.gallery_report.export.gallery', 'Gallery')],
            ['key' => 'image_id', 'label' => t('admin.gallery_report.export.image', 'Image')],
            ['key' => 'referrer_category', 'label' => t('admin.gallery_report.export.referrer', 'Referrer')],
            ['key' => 'browser_family', 'label' => t('admin.gallery_report.export.browser', 'Browser')],
            ['key' => 'device_type', 'label' => t('admin.gallery_report.export.device', 'Device')],
            ['key' => 'http_status', 'label' => 'HTTP'],
            ['key' => 'error_kind', 'label' => t('admin.gallery_report.export.error', 'Error')],
        ]) . '</section>';
    return $html;
}

/**
 * Render operational log section.
 *
 * @param array $logs Log data.
 * @return string HTML fragment.
 */
function admin_gallery_report_render_logs_section(array $logs): string
{
    if (empty($logs['available'])) {
        return '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.operational_logs', 'Operational logs')) . '</h2><p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.admin_log_unavailable', 'Admin log table is not available.')) . '</p></section>';
    }
    return '<section class="panel"><h2>' . admin_gallery_report_h(t('admin.gallery_report.export.operational_logs', 'Operational logs')) . '</h2><div class="grid">'
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.log_records', 'Log records'), admin_gallery_report_n($logs['total'] ?? 0), t('admin.gallery_report.export.all_time', 'all time'))
        . admin_gallery_report_metric_card(t('admin.gallery_report.export.last_7_days', 'Last 7 days'), admin_gallery_report_n($logs['last_7_days'] ?? 0), t('admin.gallery_report.export.recent_operational_events', 'recent operational events'))
        . '</div><div class="split"><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.levels', 'Levels')) . '</h3>' . admin_gallery_report_bar_table($logs['level_rows'] ?? [], 'count') . '</div><div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.categories', 'Categories')) . '</h3>' . admin_gallery_report_bar_table($logs['category_rows'] ?? [], 'count') . '</div></div><h3>' . admin_gallery_report_h(t('admin.gallery_report.export.top_events', 'Top events')) . '</h3>' . admin_gallery_report_bar_table($logs['top_events'] ?? [], 'count') . '<h3>' . admin_gallery_report_h(t('admin.gallery_report.export.recent_errors_critical', 'Recent errors and critical events')) . '</h3>' . admin_gallery_report_table($logs['recent_errors'] ?? [], [
            ['key' => 'created_at', 'label' => t('admin.gallery_report.export.created', 'Created')],
            ['key' => 'level', 'label' => t('admin.gallery_report.export.level', 'Level')],
            ['key' => 'severity', 'label' => t('admin.gallery_report.export.severity', 'Severity')],
            ['key' => 'event_key', 'label' => t('admin.gallery_report.export.event', 'Event')],
            ['key' => 'route_name', 'label' => t('admin.gallery_report.export.route', 'Route')],
            ['key' => 'message', 'label' => t('admin.gallery_report.export.message', 'Message')],
        ]) . '</section>';
}

/**
 * Return a metric card HTML fragment.
 *
 * @param string $label Metric label.
 * @param string $value Metric value.
 * @param string $hint Optional hint.
 * @return string HTML fragment.
 */
function admin_gallery_report_metric_card(string $label, string $value, string $hint = ''): string
{
    return '<article class="metric"><span>' . admin_gallery_report_h($label) . '</span><strong>' . admin_gallery_report_h($value) . '</strong>' . ($hint !== '' ? '<small>' . admin_gallery_report_h($hint) . '</small>' : '') . '</article>';
}

/**
 * Render a key-value list.
 *
 * @param array<string, string> $items Items to render.
 * @return string HTML fragment.
 */
function admin_gallery_report_definition_list(array $items): string
{
    $html = '<dl class="facts">';
    foreach ($items as $key => $value) {
        $html .= '<dt>' . admin_gallery_report_h((string) $key) . '</dt><dd>' . admin_gallery_report_h((string) $value) . '</dd>';
    }
    return $html . '</dl>';
}

/**
 * Render a data table.
 *
 * @param array $rows Data rows.
 * @param array $columns Column definitions.
 * @return string HTML fragment.
 */
function admin_gallery_report_table(array $rows, array $columns): string
{
    if ($rows === []) {
        return '<p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.no_rows', 'No rows.')) . '</p>';
    }
    $html = '<div class="table-scroll"><table><thead><tr>';
    foreach ($columns as $column) {
        $html .= '<th>' . admin_gallery_report_h((string) ($column['label'] ?? $column['key'] ?? '')) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $html .= '<tr>';
        foreach ($columns as $column) {
            $key = (string) ($column['key'] ?? '');
            $value = $row[$key] ?? '';
            if (isset($column['format']) && is_callable($column['format'])) {
                $value = $column['format']($value, $row);
            } elseif (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $html .= '<td>' . admin_gallery_report_h((string) $value) . '</td>';
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table></div>';
}

/**
 * Render a compact bar table.
 *
 * @param array $rows Data rows.
 * @param string $valueKey Numeric value key.
 * @param string $bytesKey Optional byte key.
 * @return string HTML fragment.
 */
function admin_gallery_report_bar_table(array $rows, string $valueKey = 'count', string $bytesKey = ''): string
{
    if ($rows === []) {
        return '<p class="muted">' . admin_gallery_report_h(t('admin.gallery_report.export.no_rows', 'No rows.')) . '</p>';
    }
    $max = 0.0;
    foreach ($rows as $row) {
        if (is_array($row)) {
            $max = max($max, (float) ($row[$valueKey] ?? 0));
        }
    }
    $html = '<div class="bars">';
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $value = (float) ($row[$valueKey] ?? 0);
        $percent = $max > 0 ? ($value / $max) * 100 : 0;
        $detail = admin_gallery_report_n($value, is_float($row[$valueKey] ?? null) ? 2 : 0);
        if ($bytesKey !== '' && isset($row[$bytesKey])) {
            $detail .= ' / ' . admin_gallery_report_bytes($row[$bytesKey]);
        }
        $html .= '<div class="bar-row"><div class="bar-label">' . admin_gallery_report_h((string) ($row['label'] ?? $row['key'] ?? 'unknown')) . '</div><div class="bar-track"><span style="width:' . admin_gallery_report_h(number_format($percent, 1, '.', '')) . '%"></span></div><div class="bar-value">' . admin_gallery_report_h($detail) . '</div></div>';
    }
    return $html . '</div>';
}

/**
 * Return report CSS.
 *
 * @return string CSS text.
 */
function admin_gallery_report_css(): string
{
    return ':root{color-scheme:light dark;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:#f3f6fb;color:#172033}*{box-sizing:border-box}body{margin:0;padding:28px}main{max-width:1500px;margin:0 auto}header,.panel{background:rgba(255,255,255,.96);border:1px solid rgba(88,106,140,.20);border-radius:24px;box-shadow:0 18px 55px rgba(24,38,67,.10);padding:24px;margin:0 0 22px}h1{font-size:36px;margin:0 0 10px}h2{font-size:24px;margin:0 0 16px}h3{font-size:17px;margin:22px 0 10px}.eyebrow{margin:0 0 8px;text-transform:uppercase;letter-spacing:.11em;font-size:12px;color:#5e73b8;font-weight:700}.muted,p,li{color:#5b6678;line-height:1.55}.print-note{display:inline-flex;border:1px solid rgba(82,116,180,.25);border-radius:999px;padding:8px 12px;color:#415173;background:rgba(82,116,180,.08);font-size:13px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(185px,1fr));gap:14px}.split{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:18px}.metric{border:1px solid rgba(88,106,140,.18);border-radius:18px;padding:16px;background:linear-gradient(180deg,rgba(255,255,255,.98),rgba(246,249,253,.95));min-width:0}.metric span{display:block;color:#637088;font-size:13px}.metric strong{display:block;font-size:25px;margin:4px 0;word-break:break-word}.metric small{display:block;color:#6a7487;word-break:break-word}.facts{display:grid;grid-template-columns:minmax(160px,260px) 1fr;gap:8px 14px;margin:0}.facts dt{font-weight:700;color:#35435c}.facts dd{margin:0;color:#5b6678;word-break:break-word}.table-scroll{overflow:auto;border:1px solid rgba(88,106,140,.18);border-radius:16px;margin:10px 0 16px}table{width:100%;border-collapse:collapse;min-width:760px}th,td{text-align:left;padding:10px 12px;border-bottom:1px solid rgba(88,106,140,.16);vertical-align:top;white-space:nowrap}th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;background:rgba(82,116,180,.10);color:#35435c}td{font-size:13px}tr:last-child td{border-bottom:0}.bars{display:grid;gap:9px;margin:10px 0 16px}.bar-row{display:grid;grid-template-columns:minmax(120px,260px) 1fr minmax(80px,auto);gap:10px;align-items:center}.bar-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#35435c;font-weight:600}.bar-track{height:12px;background:rgba(82,116,180,.14);border-radius:999px;overflow:hidden}.bar-track span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#5877e8,#55b783)}.bar-value{text-align:right;color:#5b6678;font-variant-numeric:tabular-nums}@media print{body{padding:0;background:#fff}.panel,header{box-shadow:none;border-color:#d4d9e4;break-inside:avoid}.table-scroll{overflow:visible}table{min-width:0;font-size:10px}th,td{white-space:normal;padding:6px}.print-note{display:none}}@media (prefers-color-scheme:dark){:root{background:#101521;color:#eef2fb}header,.panel{background:#171e2d;border-color:#303a50}.metric{background:#1c2435;border-color:#303a50}.muted,p,li,.metric span,.metric small,.facts dd,.bar-value{color:#aeb8cc}th{background:#222d43;color:#dbe3f5}.table-scroll,td,th{border-color:#303a50}.bar-track{background:#263148}.bar-label,.facts dt{color:#dce5f7}.print-note{background:#202b42;color:#dbe3f5;border-color:#384864}}';
}
