<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_duplicate_photos.php
 * Module Type: View
 *
 * Purpose:
 *   Renders the read-only Admin duplicate photo detector in full-page and side-panel contexts.
 *
 * Responsibilities:
 *   - Render selected-gallery and optional global-search controls
 *   - Render bounded scan progress with a normal POST continuation fallback
 *   - Render exact and possible duplicate groups with decision-making metadata
 *   - Reuse existing thumbnail URL, translation, escaping, and side-panel helpers
 *   - Expose no destructive actions
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
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\image_public_url;
use function Gallery\Core\url_for;
use function Gallery\Services\admin_dashboard_format_bytes;
use function Gallery\Services\duplicate_photo_detector_normalize_checksum;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_url;

/**
 * Return a translated display label for one normalized EXIF fingerprint field.
 *
 * @param string $name Normalized EXIF component name.
 * @return string User-facing field label.
 */
function view_duplicate_photo_exif_component_label(string $name): string
{
    return match ($name) {
        'taken_at' => t('admin.duplicate_photos.capture_date', 'Capture date'),
        'camera_make' => t('admin.duplicate_photos.camera_make', 'Camera make'),
        'camera_model' => t('admin.duplicate_photos.camera_model', 'Camera model'),
        'lens_model' => t('admin.duplicate_photos.lens', 'Lens'),
        'focal_length' => t('admin.duplicate_photos.focal_length', 'Focal length'),
        'aperture' => t('admin.duplicate_photos.aperture', 'Aperture'),
        'exposure_time' => t('admin.duplicate_photos.exposure_time', 'Exposure time'),
        'iso' => t('admin.duplicate_photos.iso', 'ISO'),
        'gps' => t('admin.duplicate_photos.gps', 'GPS coordinates'),
        default => $name,
    };
}

/**
 * Format normalized EXIF component names for matching-reason text.
 *
 * @param array<string,string> $components Common normalized EXIF components.
 * @return string Comma-separated translated labels.
 */
function view_duplicate_photo_exif_component_list(array $components): string
{
    $labels = [];
    foreach (array_keys($components) as $name) {
        $labels[] = view_duplicate_photo_exif_component_label((string) $name);
    }
    return implode(', ', $labels);
}

/**
 * Return a readable raw camera make/model label for one image row.
 *
 * @param array<string,mixed> $image Detailed image row.
 * @return string Camera label, or an empty string when unavailable.
 */
function view_duplicate_photo_camera_label(array $image): string
{
    $parts = [];
    foreach (['exif_camera_make', 'exif_camera_model'] as $column) {
        $value = trim((string) ($image[$column] ?? ''));
        if ($value !== '' && !in_array($value, $parts, true)) {
            $parts[] = $value;
        }
    }
    return implode(' ', $parts);
}

/**
 * Return a readable dimension label for one image row.
 *
 * @param array<string,mixed> $image Detailed image row.
 * @return string Pixel dimensions, or a translated unavailable label.
 */
function view_duplicate_photo_dimensions_label(array $image): string
{
    $width = (int) ($image['width'] ?? 0);
    $height = (int) ($image['height'] ?? 0);
    return $width > 0 && $height > 0
        ? $width . ' × ' . $height . ' px'
        : t('admin.duplicate_photos.unavailable', 'Unavailable');
}

/**
 * Render one result-group matching explanation and confidence badges.
 *
 * @param array<string,mixed> $group Duplicate group view model.
 */
function view_render_duplicate_photo_group_reason(array $group): void
{
    $confidence = (string) ($group['confidence'] ?? 'possible');
    $signals = is_array($group['signals'] ?? null) ? $group['signals'] : [];
    $commonExif = is_array($signals['common_exif'] ?? null) ? $signals['common_exif'] : [];
    $exifList = view_duplicate_photo_exif_component_list($commonExif);

    echo '<div class="admin-duplicate-photo-group-heading">';
    if ($confidence === 'exact') {
        echo '<span class="admin-duplicate-photo-confidence is-exact">' . e(t('admin.duplicate_photos.exact_duplicate', 'Exact duplicate')) . '</span>';
        if (!empty($signals['strong_corroboration'])) {
            echo '<span class="admin-duplicate-photo-confidence is-strong">' . e(t('admin.duplicate_photos.strong_candidate', 'Strong candidate signals')) . '</span>';
        }
    } else {
        echo '<span class="admin-duplicate-photo-confidence is-possible">' . e(t('admin.duplicate_photos.possible_duplicate', 'Possible duplicate')) . '</span>';
    }
    echo '<span class="muted">' . e(t('admin.duplicate_photos.group_members', '{count} image(s)', ['count' => (string) (int) ($group['member_count'] ?? 0)])) . '</span>';
    echo '</div>';

    echo '<ul class="admin-duplicate-photo-signals">';
    if ($confidence === 'exact') {
        $images = is_array($group['images'] ?? null) ? $group['images'] : [];
        $checksum = $images !== [] ? duplicate_photo_detector_normalize_checksum($images[0]['checksum_sha256'] ?? null) : null;
        echo '<li><strong>' . e(t('admin.duplicate_photos.signal_checksum', 'SHA-256')) . ':</strong> ' . e(t('admin.duplicate_photos.reason_exact', 'All images have the same non-empty content checksum.'));
        if ($checksum !== null) {
            echo ' <code>' . e($checksum) . '</code>';
        }
        echo '</li>';
        if (!empty($signals['strong_corroboration'])) {
            echo '<li>' . e(t('admin.duplicate_photos.reason_strong', 'The exact checksum match is also corroborated by matching file size, dimensions, and compatible non-empty EXIF metadata: {fields}.', [
                'fields' => $exifList !== '' ? $exifList : t('admin.duplicate_photos.metadata', 'metadata'),
            ])) . '</li>';
        }
    } else {
        echo '<li>' . e(t('admin.duplicate_photos.reason_possible', 'The images have the same sufficiently complete normalized EXIF fingerprint: {fields}. File size is shown only as additional evidence and may differ.', [
            'fields' => $exifList !== '' ? $exifList : t('admin.duplicate_photos.metadata', 'metadata'),
        ])) . '</li>';
    }
    echo '</ul>';
}

/**
 * Rebuild the minimal gallery row required by public URL helpers.
 *
 * @param array<string,mixed> $image Detailed image and joined gallery row.
 * @return array<string,mixed> Gallery data for gallery_public_url().
 */
function view_duplicate_photo_gallery_row(array $image): array
{
    return [
        'id' => (int) ($image['gallery_id'] ?? 0),
        'title' => (string) ($image['gallery_title'] ?? ''),
        'folder_path' => (string) ($image['gallery_folder_path'] ?? ''),
        'slug' => (string) ($image['gallery_slug'] ?? ''),
        'url_path' => (string) ($image['gallery_url_path'] ?? ''),
    ];
}

/**
 * Return the preferred public gallery URL for one duplicate result image.
 *
 * @param array<string,mixed> $image Detailed image and joined gallery row.
 * @return string Public gallery URL.
 */
function view_duplicate_photo_gallery_url(array $image): string
{
    return gallery_public_url(view_duplicate_photo_gallery_row($image));
}

/**
 * Return the preferred public image URL for one duplicate result image.
 *
 * @param array<string,mixed> $image Detailed image and joined gallery row.
 * @return string Public image-detail URL.
 */
function view_duplicate_photo_image_url(array $image): string
{
    return image_public_url($image, view_duplicate_photo_gallery_row($image));
}

/**
 * Render one image decision card inside a duplicate group.
 *
 * @param array<string,mixed> $image Detailed image and gallery row.
 * @param string $jobToken Opaque detector job token used by the delete fallback form.
 * @param int $resultsPage Current detector results page.
 * @param bool $ledgerReady Whether persistent ledger controls are available.
 * @param string $sideLabel Left/right comparison label.
 */
function view_render_duplicate_photo_image_card(array $image, string $jobToken, int $resultsPage, bool $ledgerReady, string $sideLabel): void
{
    $imageId = (int) ($image['id'] ?? 0);
    $relativePath = (string) ($image['relative_path'] ?? '');
    $filename = (string) ($image['filename'] ?? '');
    $galleryTitle = (string) ($image['gallery_title'] ?? ('#' . (int) ($image['gallery_id'] ?? 0)));
    $galleryPath = (string) ($image['gallery_folder_path'] ?? '');
    $camera = view_duplicate_photo_camera_label($image);
    $lens = trim((string) ($image['exif_lens_model'] ?? ''));
    $captureDate = trim((string) ($image['exif_taken_at'] ?? ''));
    $mimeType = trim((string) ($image['mime_type'] ?? ''));
    $fileSize = max(0, (int) ($image['file_size'] ?? 0));
    $galleryUrl = view_duplicate_photo_gallery_url($image);
    $imageUrl = view_duplicate_photo_image_url($image);
    $openGalleryTitle = t('admin.duplicate_photos.open_gallery', 'Open gallery in a new tab');
    $openImageTitle = t('admin.duplicate_photos.open_image', 'Open photo in a new tab');

    echo '<article class="admin-duplicate-photo-image-card">';
    echo '<div class="admin-duplicate-photo-side-label">' . e($sideLabel) . '</div>';
    echo '<a class="admin-duplicate-photo-preview" href="' . e($imageUrl) . '" target="_blank" rel="noopener" title="' . e($openImageTitle) . '"><img src="' . e(thumbnail_url($image, 300)) . '" alt="' . e(t('admin.duplicate_photos.preview_alt', 'Preview of {file}', ['file' => $filename !== '' ? $filename : $relativePath])) . '" loading="lazy" decoding="async"></a>';
    echo '<div class="admin-duplicate-photo-image-body">';
    echo '<div class="admin-duplicate-photo-image-title"><strong>#' . $imageId . '</strong> <a href="' . e($imageUrl) . '" target="_blank" rel="noopener" title="' . e($openImageTitle) . '">' . e($filename !== '' ? $filename : $relativePath) . '</a></div>';
    echo '<dl class="admin-duplicate-photo-metadata">';
    echo '<div><dt>' . e(t('admin.duplicate_photos.gallery', 'Gallery')) . '</dt><dd><a href="' . e($galleryUrl) . '" target="_blank" rel="noopener" title="' . e($openGalleryTitle) . '">' . e($galleryTitle) . '</a></dd></div>';
    echo '<div><dt>' . e(t('admin.duplicate_photos.gallery_path', 'Gallery path')) . '</dt><dd><a href="' . e($galleryUrl) . '" target="_blank" rel="noopener" title="' . e($openGalleryTitle) . '"><code>' . e($galleryPath !== '' ? $galleryPath : '/') . '</code></a></dd></div>';
    echo '<div><dt>' . e(t('admin.duplicate_photos.relative_path', 'Gallery-relative path')) . '</dt><dd><a href="' . e($imageUrl) . '" target="_blank" rel="noopener" title="' . e($openImageTitle) . '"><code>' . e($relativePath) . '</code></a></dd></div>';
    echo '<div><dt>' . e(t('admin.duplicate_photos.file_size', 'File size')) . '</dt><dd>' . e($fileSize > 0 ? admin_dashboard_format_bytes($fileSize) : t('admin.duplicate_photos.unavailable', 'Unavailable')) . '</dd></div>';
    echo '<div><dt>' . e(t('admin.duplicate_photos.dimensions', 'Dimensions')) . '</dt><dd>' . e(view_duplicate_photo_dimensions_label($image)) . '</dd></div>';
    echo '<div><dt>' . e(t('admin.duplicate_photos.mime_type', 'MIME type')) . '</dt><dd>' . e($mimeType !== '' ? $mimeType : t('admin.duplicate_photos.unavailable', 'Unavailable')) . '</dd></div>';
    echo '<div><dt>' . e(t('admin.duplicate_photos.capture_date', 'Capture date')) . '</dt><dd>' . e($captureDate !== '' ? $captureDate : t('admin.duplicate_photos.unavailable', 'Unavailable')) . '</dd></div>';
    echo '<div><dt>' . e(t('admin.duplicate_photos.camera', 'Camera')) . '</dt><dd>' . e($camera !== '' ? $camera : t('admin.duplicate_photos.unavailable', 'Unavailable')) . '</dd></div>';
    echo '<div><dt>' . e(t('admin.duplicate_photos.lens', 'Lens')) . '</dt><dd>' . e($lens !== '' ? $lens : t('admin.duplicate_photos.unavailable', 'Unavailable')) . '</dd></div>';
    echo '</dl>';
    if ($jobToken !== '' && $imageId > 0) {
        echo '<div class="admin-duplicate-photo-card-actions">';
        echo '<form method="post" action="' . e(url_for('admin_duplicate_photos')) . '" class="admin-duplicate-photo-delete-form" data-duplicate-photo-delete-form>';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="delete">';
        echo '<input type="hidden" name="job_token" value="' . e($jobToken) . '">';
        echo '<input type="hidden" name="image_id" value="' . $imageId . '">';
        echo '<input type="hidden" name="results_page" value="' . max(1, $resultsPage) . '">';
        echo '<button type="submit" class="button danger">' . e(t('admin.duplicate_photos.delete_this', 'Delete this')) . '</button>';
        echo '</form>';
        if ($ledgerReady) {
            echo '<form method="post" action="' . e(url_for('admin_duplicate_photos')) . '" class="admin-duplicate-photo-ledger-form" data-duplicate-photo-ledger-form>';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="ignore_gallery">';
            echo '<input type="hidden" name="job_token" value="' . e($jobToken) . '">';
            echo '<input type="hidden" name="image_id" value="' . $imageId . '">';
            echo '<input type="hidden" name="results_page" value="' . max(1, $resultsPage) . '">';
            echo '<button type="submit" class="button secondary">' . e(t('admin.duplicate_photos.ignore_gallery', 'Ignore all from this gallery')) . '</button>';
            echo '</form>';
        }
        echo '</div>';
    }
    echo '</div></article>';
}

/**
 * Render the persistent ignore action for one displayed duplicate pair.
 *
 * @param array<string,mixed> $group Pair-oriented duplicate finding.
 * @param string $jobToken Opaque detector job token.
 * @param int $resultsPage Current result page.
 * @param bool $ledgerReady Whether ledger controls are available.
 * @return void
 */
function view_render_duplicate_photo_pair_ledger_action(array $group, string $jobToken, int $resultsPage, bool $ledgerReady): void
{
    $images = is_array($group['images'] ?? null) ? array_values($group['images']) : [];
    if (!$ledgerReady || $jobToken === '' || count($images) !== 2) {
        return;
    }

    $leftImageId = (int) ($images[0]['id'] ?? 0);
    $rightImageId = (int) ($images[1]['id'] ?? 0);
    if ($leftImageId <= 0 || $rightImageId <= 0 || $leftImageId === $rightImageId) {
        return;
    }

    echo '<div class="admin-duplicate-photo-pair-actions">';
    echo '<form method="post" action="' . e(url_for('admin_duplicate_photos')) . '" data-duplicate-photo-ledger-form>';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="ignore_pair">';
    echo '<input type="hidden" name="job_token" value="' . e($jobToken) . '">';
    echo '<input type="hidden" name="left_image_id" value="' . $leftImageId . '">';
    echo '<input type="hidden" name="right_image_id" value="' . $rightImageId . '">';
    echo '<input type="hidden" name="results_page" value="' . max(1, $resultsPage) . '">';
    echo '<button type="submit" class="button secondary">' . e(t('admin.duplicate_photos.ignore_pair', 'Ignore this pair from now on')) . '</button>';
    echo '</form>';
    echo '</div>';
}

/**
 * Render result pagination while preserving the selected gallery and session job.
 *
 * @param array<string,mixed> $gallery Selected gallery row.
 * @param array<string,mixed> $job Completed detector job.
 * @param array<string,mixed> $resultPage Result page model.
 */
function view_render_duplicate_photo_pagination(array $gallery, array $job, array $resultPage): void
{
    $page = (int) ($resultPage['page'] ?? 1);
    $pageCount = (int) ($resultPage['page_count'] ?? 1);
    if ($pageCount <= 1) {
        return;
    }

    $token = (string) ($job['token'] ?? '');
    echo '<nav class="admin-duplicate-photo-pagination" aria-label="' . e(t('admin.duplicate_photos.pagination_aria', 'Duplicate result pages')) . '">';
    if ($page > 1) {
        $url = url_for('admin_duplicate_photos', ['gallery_id' => (int) $gallery['id'], 'job_token' => $token, 'results_page' => $page - 1]);
        echo '<a class="button secondary" href="' . e($url) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="duplicate-detector" data-admin-side-panel-kicker="' . e(t('admin.duplicate_photos.kicker', 'Gallery tools')) . '" data-admin-side-panel-title="' . e(t('admin.duplicate_photos.page_title', 'Duplicate Photo Detector')) . '" data-gallery-side-panel-url="' . e($url) . '">' . e(t('admin.duplicate_photos.previous', 'Previous')) . '</a>';
    }
    echo '<span>' . e(t('admin.duplicate_photos.page_status', 'Page {page} of {pages}', ['page' => (string) $page, 'pages' => (string) $pageCount])) . '</span>';
    if ($page < $pageCount) {
        $url = url_for('admin_duplicate_photos', ['gallery_id' => (int) $gallery['id'], 'job_token' => $token, 'results_page' => $page + 1]);
        echo '<a class="button secondary" href="' . e($url) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="duplicate-detector" data-admin-side-panel-kicker="' . e(t('admin.duplicate_photos.kicker', 'Gallery tools')) . '" data-admin-side-panel-title="' . e(t('admin.duplicate_photos.page_title', 'Duplicate Photo Detector')) . '" data-gallery-side-panel-url="' . e($url) . '">' . e(t('admin.duplicate_photos.next', 'Next')) . '</a>';
    }
    echo '</nav>';
}

/**
 * Render the reusable Admin duplicate photo detector component.
 *
 * @param array<string,mixed> $gallery Selected gallery row.
 * @param array<string,mixed>|null $job Current detector session job, if any.
 * @param array<string,mixed>|null $resultPage Completed result page model, if available.
 * @param array<string,mixed> $ledger Persistent review-ledger snapshot.
 */
function view_render_admin_duplicate_photo_detector(array $gallery, ?array $job = null, ?array $resultPage = null, array $ledger = []): void
{
    $galleryId = (int) ($gallery['id'] ?? 0);
    $endpoint = url_for('admin_duplicate_photos');
    $isComplete = is_array($job) && (string) ($job['status'] ?? '') === 'complete';
    $processed = is_array($job) ? max(0, (int) ($job['processed'] ?? 0)) : 0;
    $total = is_array($job) ? max(0, (int) ($job['total'] ?? 0)) : 0;
    $percent = $total > 0 ? min(100.0, round(($processed / $total) * 100, 1)) : ($isComplete ? 100.0 : 0.0);
    $ledgerReady = !empty($ledger['ready']);
    $ledgerPairCount = max(0, (int) ($ledger['pair_count'] ?? 0));
    $ledgerGalleryCount = max(0, (int) ($ledger['gallery_count'] ?? 0));
    $resultsPage = is_array($resultPage) ? max(1, (int) ($resultPage['page'] ?? 1)) : 1;

    echo '<section class="panel admin-duplicate-photo-detector" data-duplicate-photo-detector data-duplicate-photo-endpoint="' . e($endpoint) . '">';
    echo '<div class="admin-duplicate-photo-intro">';
    echo '<p class="admin-section-kicker">' . e(t('admin.duplicate_photos.kicker', 'Gallery tools')) . '</p>';
    echo '<h2>' . e(t('admin.duplicate_photos.page_title', 'Duplicate Photo Detector')) . '</h2>';
    echo '<p>' . e(t('admin.duplicate_photos.description', 'Compare stored image checksums and scanned metadata to find exact duplicates and high-value candidates without changing any files or image records.')) . '</p>';
    echo '<p class="muted">' . e(t('admin.duplicate_photos.selected_gallery', 'Selected gallery: {title} ({path})', [
        'title' => (string) ($gallery['title'] ?? ('#' . $galleryId)),
        'path' => (string) ($gallery['folder_path'] ?? '/'),
    ])) . '</p>';
    echo '<div class="notice">' . e(t('admin.duplicate_photos.report_only', 'Detection itself is read-only. Use Delete this only when you explicitly want to permanently remove one result image, its database row, and generated derivatives.')) . '</div>';
    echo '</div>';

    echo '<div class="admin-duplicate-photo-ledger">';
    echo '<div class="admin-duplicate-photo-ledger-heading"><strong>' . e(t('admin.duplicate_photos.ledger_title', 'Reviewed duplicate ledger')) . '</strong>';
    if ($ledgerReady) {
        echo '<span class="muted">' . e(t('admin.duplicate_photos.ledger_counts', '{pairs} ignored pair(s), {galleries} ignored gallery rule(s)', [
            'pairs' => (string) $ledgerPairCount,
            'galleries' => (string) $ledgerGalleryCount,
        ])) . '</span>';
    }
    echo '</div>';
    if (!$ledgerReady) {
        echo '<div class="notice is-alert">' . e(t('admin.duplicate_photos.ledger_migration_required', 'Run database migrations before using the duplicate review ledger.')) . '</div>';
    } else {
        echo '<p class="muted">' . e(t('admin.duplicate_photos.ledger_help', 'Ignored pairs are not shown by later duplicate searches. Ignoring a gallery suppresses only that exact gallery; parent and child galleries remain independent.')) . '</p>';
        if ($ledgerPairCount > 0 || $ledgerGalleryCount > 0) {
            echo '<form method="post" action="' . e($endpoint) . '" data-duplicate-photo-ledger-form class="admin-duplicate-photo-ledger-clear-form">';
            echo csrf_field();
            echo '<input type="hidden" name="action" value="clear_ledger">';
            echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
            if (is_array($job) && (string) ($job['token'] ?? '') !== '') {
                echo '<input type="hidden" name="job_token" value="' . e((string) $job['token']) . '">';
                echo '<input type="hidden" name="results_page" value="' . $resultsPage . '">';
            }
            echo '<button type="submit" class="button secondary">' . e(t('admin.duplicate_photos.clear_ledger', 'Clear ledger')) . '</button>';
            echo '</form>';
        }
    }
    echo '</div>';

    echo '<form method="post" action="' . e($endpoint) . '" class="admin-duplicate-photo-controls" data-duplicate-photo-start-form>';
    echo csrf_field();
    echo '<input type="hidden" name="action" value="start">';
    echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
    echo '<label class="checkbox-label admin-duplicate-photo-global-toggle"><input type="checkbox" name="search_all" value="1"> ' . e(t('admin.duplicate_photos.search_all', 'Search all galleries')) . '</label>';
    echo '<p class="muted">' . e(t('admin.duplicate_photos.search_all_help', 'Unchecked by default. When off, the selected gallery and all of its subgalleries are scanned. When checked, all galleries available to the authenticated administrator are scanned.')) . '</p>';
    echo '<button type="submit" class="button primary">' . e($job === null ? t('admin.duplicate_photos.start_scan', 'Find duplicate photos') : t('admin.duplicate_photos.start_new_scan', 'Start a new scan')) . '</button>';
    echo '</form>';

    echo '<div class="admin-duplicate-photo-status" data-duplicate-photo-status aria-live="polite">';
    $scopeLabel = !empty($job['search_all'])
        ? t('admin.duplicate_photos.scope_all', 'Scope: all administrator-accessible galleries')
        : t('admin.duplicate_photos.scope_selected', 'Scope: selected gallery and all subgalleries');
    echo '<div class="admin-duplicate-photo-progress-card" data-duplicate-photo-progress-card' . ($job === null ? ' hidden' : '') . '>';
    echo '<div class="admin-duplicate-photo-progress-heading"><strong data-duplicate-photo-scope>' . e($scopeLabel) . '</strong><span data-duplicate-photo-progress-count>' . e(t('admin.duplicate_photos.progress_count', '{processed}/{total} images inspected', [
        'processed' => (string) $processed,
        'total' => (string) $total,
    ])) . '</span></div>';
    echo '<progress max="100" value="' . e((string) $percent) . '" data-duplicate-photo-progress></progress>';
    echo '<p class="muted" data-duplicate-photo-progress-text>' . e($isComplete
        ? t('admin.duplicate_photos.scan_complete', 'Scan complete.')
        : t('admin.duplicate_photos.scan_running', 'The scan is incomplete. Continue to process the next bounded metadata batch.')) . '</p>';
    echo '</div>';
    if ($job === null) {
        echo '<p class="muted">' . e(t('admin.duplicate_photos.stored_metadata_help', 'Detection reads stored image metadata only. If older image rows are missing checksums or EXIF values, use the existing Scan/import images workflow first so the normal scanner can populate them.')) . '</p>';
        echo '</div></section>';
        return;
    }

    if (!$isComplete) {
        echo '<form method="post" action="' . e($endpoint) . '" data-duplicate-photo-step-form>';
        echo csrf_field();
        echo '<input type="hidden" name="action" value="step">';
        echo '<input type="hidden" name="job_token" value="' . e((string) ($job['token'] ?? '')) . '">';
        echo '<button type="submit" class="button secondary">' . e(t('admin.duplicate_photos.continue_scan', 'Continue scan')) . '</button>';
        echo '<p class="muted">' . e(t('admin.duplicate_photos.no_js_help', 'Without JavaScript, use Continue scan repeatedly. With JavaScript, bounded batches continue automatically.')) . '</p>';
        echo '</form>';
        echo '</div></section>';
        return;
    }

    $totalPairs = is_array($resultPage) ? max(0, (int) ($resultPage['total_pairs'] ?? 0)) : 0;
    $exactPairs = is_array($resultPage) ? max(0, (int) ($resultPage['exact_pair_count'] ?? 0)) : 0;
    $possiblePairs = is_array($resultPage) ? max(0, (int) ($resultPage['possible_pair_count'] ?? 0)) : 0;
    echo '<div class="admin-duplicate-photo-summary">';
    echo '<strong>' . e(t('admin.duplicate_photos.result_summary', '{images} image(s) inspected. {pairs} unreviewed duplicate pair(s) remain.', [
        'images' => (string) $total,
        'pairs' => (string) $totalPairs,
    ])) . '</strong>';
    echo '<p class="muted">' . e(t('admin.duplicate_photos.result_pair_breakdown', '{exact} exact pair(s), {possible} possible pair(s) after ledger filtering.', [
        'exact' => (string) $exactPairs,
        'possible' => (string) $possiblePairs,
    ])) . '</p>';
    echo '<p class="muted">' . e(t('admin.duplicate_photos.confidence_help', 'Exact means a matching non-empty SHA-256 checksum. Strong candidate signals are additional corroboration within an exact group. Possible means a sufficiently complete normalized EXIF fingerprint matches, even if file size differs. File size alone never creates a group.')) . '</p>';
    if (!empty($resultPage['reference_limit_reached'])) {
        echo '<div class="notice is-alert">' . e(t('admin.duplicate_photos.pair_limit_reached', 'This scan produced an unusually large number of pair relationships. The first bounded set is shown; use gallery ledger rules to narrow repeated findings.')) . '</div>';
    }
    echo '</div>';

    if ($totalPairs === 0 || !is_array($resultPage) || empty($resultPage['groups'])) {
        echo '<div class="notice">' . e(t('admin.duplicate_photos.no_results', 'No unreviewed duplicate pairs matched the detector rules in this scope.')) . '</div>';
        echo '</div></section>';
        return;
    }

    $jobToken = (string) ($job['token'] ?? '');
    echo '<div class="admin-duplicate-photo-groups">';
    foreach ((array) $resultPage['groups'] as $group) {
        $images = is_array($group['images'] ?? null) ? array_values($group['images']) : [];
        if (count($images) !== 2) {
            continue;
        }
        echo '<article class="admin-duplicate-photo-group">';
        view_render_duplicate_photo_group_reason($group);
        view_render_duplicate_photo_pair_ledger_action($group, $jobToken, $resultsPage, $ledgerReady);
        echo '<div class="admin-duplicate-photo-images is-pair">';
        view_render_duplicate_photo_image_card($images[0], $jobToken, $resultsPage, $ledgerReady, t('admin.duplicate_photos.left_photo', 'Left photo'));
        view_render_duplicate_photo_image_card($images[1], $jobToken, $resultsPage, $ledgerReady, t('admin.duplicate_photos.right_photo', 'Right photo'));
        echo '</div>';
        echo '</article>';
    }
    echo '</div>';
    view_render_duplicate_photo_pagination($gallery, $job, $resultPage);
    echo '</div></section>';
}
