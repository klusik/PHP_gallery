<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_dates.php
 * Module Type: Service
 *
 * Purpose:
 *   Handles optional manual dates and date ranges assigned to galleries by admins.
 *
 * Responsibilities:
 *   - Normalize date and date-range input from admin forms
 *   - Validate YYYY-MM-DD storage values
 *   - Format stored gallery dates and date ranges for public display
 *   - Build EXIF-derived date-range suggestions from already scanned images
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
 *   2026-06-07
 */

declare(strict_types=1);

/**
 * Return true when gallery rows can store the optional manual gallery date.
 *
 * @return bool True when the condition matches.
 */
function gallery_date_schema_ready(): bool
{
    return db_column_exists('galleries', 'gallery_date');
}

/**
 * Return true when gallery rows can store a manual date range end value.
 *
 * @return bool True when the condition matches.
 */
function gallery_date_range_schema_ready(): bool
{
    return gallery_date_schema_ready() && db_column_exists('galleries', 'gallery_date_end');
}

/**
 * Return true when scanned image EXIF capture dates can drive gallery date suggestions.
 *
 * @return bool True when the condition matches.
 */
function gallery_date_exif_suggestions_schema_ready(): bool
{
    return gallery_date_schema_ready() && db_column_exists('images', 'exif_taken_at');
}

/**
 * Normalize an admin-submitted gallery date for database storage.
 *
 * @param mixed $value Value to process.
 * @return ?string Text result for the caller.
 */
function gallery_date_storage_value(mixed $value): ?string
{
    // $date stores the trimmed form value before strict validation.
    $date = trim((string) $value);
    if ($date === '') {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException(t('admin.gallery_editor.invalid_gallery_date', 'Enter a valid gallery date.'));
    }
    return $date;
}

/**
 * Normalize a submitted gallery date range for database storage.
 *
 * @param mixed $startValue Start value value.
 * @param mixed $endValue End value value.
 * @return array{start:?string,end:?string} Structured result data for the caller.
 */
function gallery_date_range_storage_values(mixed $startValue, mixed $endValue): array
{
    // $start stores the normalized start date persisted in the legacy gallery_date column.
    $start = gallery_date_storage_value($startValue);
    // $end stores the optional range end date persisted in gallery_date_end when available.
    $end = gallery_date_storage_value($endValue);

    if ($start !== null && $end !== null && strcmp($end, $start) < 0) {
        throw new InvalidArgumentException(t('admin.gallery_editor.invalid_gallery_date_range', 'The gallery date range end cannot be before the start date.'));
    }

    return ['start' => $start, 'end' => $end];
}

/**
 * Normalize a sidecar gallery date without letting malformed legacy metadata stop scanning.
 *
 * @param mixed $value Value to process.
 * @return ?string Text result for the caller.
 */
function gallery_date_sidecar_value(mixed $value): ?string
{
    try {
        return gallery_date_storage_value($value);
    } catch (InvalidArgumentException) {
        return null;
    }
}

/**
 * Normalize sidecar gallery date-range metadata without letting malformed values stop scanning.
 *
 * @param mixed $startValue Start value value.
 * @param mixed $endValue End value value.
 * @return array{start:?string,end:?string} Structured result data for the caller.
 */
function gallery_date_sidecar_range_values(mixed $startValue, mixed $endValue): array
{
    try {
        return gallery_date_range_storage_values($startValue, $endValue);
    } catch (InvalidArgumentException) {
        return ['start' => gallery_date_sidecar_value($startValue), 'end' => null];
    }
}

/**
 * Return a valid input[type=date] value for admin forms.
 *
 * @param mixed $value Value to process.
 * @return string Text result for the caller.
 */
function gallery_date_input_value(mixed $value): string
{
    try {
        return gallery_date_storage_value($value) ?? '';
    } catch (InvalidArgumentException) {
        return '';
    }
}

/**
 * Format one stored gallery date for public display.
 *
 * @param mixed $value Value to process.
 * @return string Text result for the caller.
 */
function gallery_date_single_display_value(mixed $value): string
{
    try {
        $date = gallery_date_storage_value($value);
    } catch (InvalidArgumentException) {
        return '';
    }
    if ($date === null) {
        return '';
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed) {
        return '';
    }
    return $parsed->format('j. n. Y');
}

/**
 * Format a stored gallery date or date range for public display.
 *
 * @return string Text result for the caller.
 */
function gallery_date_range_separator(): string
{
    return ' – ';
}

/**
 * Format a stored gallery date or date range for public display.
 *
 * @param mixed $startValue Start value value.
 * @param mixed $endValue End value value.
 * @return string Text result for the caller.
 */
function gallery_date_range_display_value(mixed $startValue, mixed $endValue = null): string
{
    // $startDisplay stores the visitor-facing start date string.
    $startDisplay = gallery_date_single_display_value($startValue);
    // $endDisplay stores the visitor-facing end date string.
    $endDisplay = gallery_date_single_display_value($endValue);
    // $start stores normalized storage values for equality checks.
    $start = gallery_date_sidecar_value($startValue);
    // $end stores normalized storage values for equality checks.
    $end = gallery_date_sidecar_value($endValue);

    if ($startDisplay === '' && $endDisplay === '') {
        return '';
    }
    if ($endDisplay === '' || $start === $end) {
        return $startDisplay;
    }
    if ($startDisplay === '') {
        return t('gallery.date_until', 'Until {date}', ['date' => $endDisplay]);
    }
    return $startDisplay . gallery_date_range_separator() . $endDisplay;
}

/**
 * Format a stored gallery date for public display.
 *
 * @param mixed $value Value to process.
 * @return string Text result for the caller.
 */
function gallery_date_display_value(mixed $value): string
{
    return gallery_date_single_display_value($value);
}

/**
 * Return a compact storage label for admin tables.
 *
 * @param mixed $startValue Start value value.
 * @param mixed $endValue End value value.
 * @return string Text result for the caller.
 */
function gallery_date_range_storage_label(mixed $startValue, mixed $endValue = null): string
{
    // $start stores a normalized date value for admin diagnostics.
    $start = gallery_date_sidecar_value($startValue);
    // $end stores a normalized date value for admin diagnostics.
    $end = gallery_date_sidecar_value($endValue);

    if ($start === null && $end === null) {
        return '';
    }
    if ($end === null || $start === $end) {
        return (string) $start;
    }
    if ($start === null) {
        return t('gallery.date_until', 'Until {date}', ['date' => $end]);
    }
    return $start . gallery_date_range_separator() . $end;
}

/**
 * Return true when the current stored gallery range equals the suggested range.
 *
 * @param mixed $currentStart Current start value.
 * @param mixed $currentEnd Current end value.
 * @param mixed $suggestedStart Suggested start value.
 * @param mixed $suggestedEnd Suggested end value.
 * @return bool True when the condition matches.
 */
function gallery_date_range_matches(mixed $currentStart, mixed $currentEnd, mixed $suggestedStart, mixed $suggestedEnd): bool
{
    return gallery_date_sidecar_value($currentStart) === gallery_date_sidecar_value($suggestedStart)
        && gallery_date_sidecar_value($currentEnd) === gallery_date_sidecar_value($suggestedEnd);
}

/**
 * Persist a validated gallery date range and refresh its sidecar metadata.
 *
 * @param int $galleryId Gallery identifier.
 * @param mixed $startValue Start value value.
 * @param mixed $endValue End value value.
 * @return array{gallery:array<string,mixed>,start:?string,end:?string} Structured result data for the caller.
 */
function gallery_date_save_range(int $galleryId, mixed $startValue, mixed $endValue): array
{
    if (!gallery_date_schema_ready()) {
        throw new RuntimeException(t('admin.gallery_dates.requires_migration', 'Gallery date maintenance will be available after the database migration is applied.'));
    }

    // $gallery stores the row being updated and re-exported to gallery.json.
    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        throw new RuntimeException(t('admin.gallery_dates.error_gallery_missing', 'Gallery #{id} no longer exists.', ['id' => (string) $galleryId]));
    }

    // $range stores normalized database values accepted by the gallery date columns.
    $range = gallery_date_range_storage_values($startValue, $endValue);
    // $fields stores the exact date columns available on this installation.
    $fields = ['gallery_date = ?'];
    // $values stores SQL values in the same order as $fields.
    $values = [$range['start']];
    if (gallery_date_range_schema_ready()) {
        $fields[] = 'gallery_date_end = ?';
        $values[] = $range['end'];
    }
    $fields[] = 'updated_at = ?';
    $values[] = now_sql();
    $values[] = $galleryId;

    $stmt = db()->prepare('UPDATE galleries SET ' . implode(', ', $fields) . ' WHERE id = ?');
    $stmt->execute($values);

    // $updatedGallery stores the post-save row so sidecar metadata mirrors the DB state.
    $updatedGallery = find_gallery($galleryId, true) ?: $gallery;
    if (function_exists('write_gallery_sidecar')) {
        write_gallery_sidecar($updatedGallery);
    }

    return ['gallery' => $updatedGallery, 'start' => $range['start'], 'end' => $range['end']];
}

/**
 * Return the best stored end date for a gallery row on partially migrated installs.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return ?string Text result for the caller.
 */
function gallery_date_end_value(array $gallery): ?string
{
    if (!gallery_date_range_schema_ready()) {
        return null;
    }
    return gallery_date_sidecar_value($gallery['gallery_date_end'] ?? null);
}

/**
 * Render the optional public gallery date or date range when one was manually assigned.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param string $class Class value.
 */
function render_gallery_date(array $gallery, string $class = 'gallery-date'): void
{
    if (!gallery_date_schema_ready()) {
        return;
    }
    // $start stores the normalized date range start for machine-readable markup.
    $start = gallery_date_sidecar_value($gallery['gallery_date'] ?? null);
    // $end stores the normalized date range end for machine-readable markup.
    $end = gallery_date_end_value($gallery);
    // $displayDate stores the visitor-facing date string. Empty values are intentionally silent.
    $displayDate = gallery_date_range_display_value($start, $end);
    if ($displayDate === '') {
        return;
    }
    $attributes = ' class="' . e($class) . '"';
    if ($start !== null) {
        $attributes .= ' datetime="' . e($start) . '" data-date-start="' . e($start) . '"';
    }
    if ($end !== null) {
        $attributes .= ' data-date-end="' . e($end) . '"';
    }
    echo '<time' . $attributes . '>' . e($displayDate) . '</time>';
}

/**
 * Return all gallery rows with columns needed for EXIF date-range suggestions.
 *
 * @return array<int array<string, mixed>>.
 */
function gallery_date_suggestion_gallery_rows(): array
{
    $selects = [
        'id',
        'parent_id',
        'folder_path',
        'title',
        'gallery_date',
    ];
    if (gallery_date_range_schema_ready()) {
        $selects[] = 'gallery_date_end';
    } else {
        $selects[] = 'NULL AS gallery_date_end';
    }

    return db()->query('SELECT ' . implode(', ', $selects) . ' FROM galleries ORDER BY folder_path')->fetchAll();
}

/**
 * Return direct gallery EXIF date aggregates from scanned image rows.
 *
 * @return array<int array<string, mixed>>.
 */
function gallery_date_direct_exif_ranges(): array
{
    if (!gallery_date_exif_suggestions_schema_ready()) {
        return [];
    }

    $sql = "SELECT gallery_id, MIN(DATE(exif_taken_at)) AS suggested_start, MAX(DATE(exif_taken_at)) AS suggested_end, COUNT(*) AS image_count
        FROM images
        WHERE exif_taken_at IS NOT NULL AND exif_taken_at > '1000-01-01 00:00:00'
        GROUP BY gallery_id";
    return db()->query($sql)->fetchAll();
}

/**
 * Merge one direct EXIF date aggregate into one ancestor gallery suggestion.
 *
 * @param array $suggestion Suggestion value.
 * @param array $directRange Direct range value.
 */
function gallery_date_merge_direct_range(array &$suggestion, array $directRange): void
{
    // $start stores the direct image minimum date in YYYY-MM-DD form.
    $start = gallery_date_sidecar_value($directRange['suggested_start'] ?? null);
    // $end stores the direct image maximum date in YYYY-MM-DD form.
    $end = gallery_date_sidecar_value($directRange['suggested_end'] ?? null);
    if ($start === null && $end === null) {
        return;
    }
    if ($start !== null && ($suggestion['suggested_start'] === null || strcmp($start, (string) $suggestion['suggested_start']) < 0)) {
        $suggestion['suggested_start'] = $start;
    }
    if ($end !== null && ($suggestion['suggested_end'] === null || strcmp($end, (string) $suggestion['suggested_end']) > 0)) {
        $suggestion['suggested_end'] = $end;
    }
    $suggestion['exif_image_count'] = (int) ($suggestion['exif_image_count'] ?? 0) + (int) ($directRange['image_count'] ?? 0);
    $suggestion['source_gallery_count'] = (int) ($suggestion['source_gallery_count'] ?? 0) + 1;
}

/**
 * Return true when one gallery belongs to another gallery branch.
 *
 * @param array $byId By id identifier.
 * @param int $galleryId Gallery identifier.
 * @param int $branchRootId Branch root id identifier.
 * @return bool True when the condition matches.
 */
function gallery_date_gallery_is_in_branch(array $byId, int $galleryId, int $branchRootId): bool
{
    // $currentId walks from the candidate gallery toward the root gallery.
    $currentId = $galleryId;
    while ($currentId > 0 && isset($byId[$currentId])) {
        if ($currentId === $branchRootId) {
            return true;
        }
        $currentId = (int) ($byId[$currentId]['parent_id'] ?? 0);
    }
    return false;
}

/**
 * Build editable EXIF-derived date-range suggestions for gallery branches.
 *
 * Each gallery receives the minimum and maximum capture date from its own images
 * and all descendant galleries. Galleries without usable EXIF capture dates are
 * omitted because they cannot provide a safe suggestion. When $scopeGalleryId is
 * provided, only that gallery and its descendants are returned.
 *
 * @param ?int $scopeGalleryId Scope gallery id identifier.
 * @return array<int array<string, mixed>>.
 */
function gallery_date_exif_suggestion_rows(?int $scopeGalleryId = null): array
{
    if (!gallery_date_exif_suggestions_schema_ready()) {
        return [];
    }

    // $galleries stores every gallery row indexed by id for parent traversal.
    $galleries = gallery_date_suggestion_gallery_rows();
    $byId = [];
    foreach ($galleries as $gallery) {
        $galleryId = (int) ($gallery['id'] ?? 0);
        if ($galleryId > 0) {
            $byId[$galleryId] = $gallery;
        }
    }

    if ($scopeGalleryId !== null && !isset($byId[$scopeGalleryId])) {
        return [];
    }

    // $suggestions stores branch-level aggregate suggestions by ancestor gallery id.
    $suggestions = [];
    foreach ($byId as $galleryId => $gallery) {
        $suggestions[$galleryId] = [
            'id' => $galleryId,
            'title' => (string) ($gallery['title'] ?? ''),
            'folder_path' => (string) ($gallery['folder_path'] ?? ''),
            'current_start' => gallery_date_sidecar_value($gallery['gallery_date'] ?? null),
            'current_end' => gallery_date_sidecar_value($gallery['gallery_date_end'] ?? null),
            'suggested_start' => null,
            'suggested_end' => null,
            'exif_image_count' => 0,
            'source_gallery_count' => 0,
        ];
    }

    foreach (gallery_date_direct_exif_ranges() as $directRange) {
        // $currentId walks from the direct image gallery up to the root gallery.
        $currentId = (int) ($directRange['gallery_id'] ?? 0);
        while ($currentId > 0 && isset($byId[$currentId], $suggestions[$currentId])) {
            gallery_date_merge_direct_range($suggestions[$currentId], $directRange);
            $currentId = (int) ($byId[$currentId]['parent_id'] ?? 0);
        }
    }

    // $rows stores only galleries where at least one scanned image provided an EXIF date.
    $rows = [];
    foreach ($suggestions as $suggestion) {
        $suggestionId = (int) ($suggestion['id'] ?? 0);
        if ($scopeGalleryId !== null && !gallery_date_gallery_is_in_branch($byId, $suggestionId, $scopeGalleryId)) {
            continue;
        }
        if ((int) ($suggestion['exif_image_count'] ?? 0) <= 0 || $suggestion['suggested_start'] === null) {
            continue;
        }
        $suggestion['matches_current'] = gallery_date_range_matches(
            $suggestion['current_start'],
            $suggestion['current_end'],
            $suggestion['suggested_start'],
            $suggestion['suggested_end']
        );
        $suggestion['has_current_range'] = $suggestion['current_start'] !== null || $suggestion['current_end'] !== null;
        $rows[] = $suggestion;
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) ($a['folder_path'] ?? ''), (string) ($b['folder_path'] ?? ''));
    });

    return $rows;
}

/**
 * Return the EXIF-derived date suggestion for exactly one gallery branch.
 *
 * @param int $galleryId Gallery identifier.
 * @return array<string mixed>|null.
 */
function gallery_date_exif_suggestion_for_gallery(int $galleryId): ?array
{
    foreach (gallery_date_exif_suggestion_rows($galleryId) as $row) {
        if ((int) ($row['id'] ?? 0) === $galleryId) {
            return $row;
        }
    }
    return null;
}

/**
 * Apply the recursive EXIF-derived date suggestion to one gallery.
 *
 * The suggestion uses images from the gallery and all descendants, but the
 * persisted range is written only to the requested gallery. This keeps the
 * operation safe for parent trip galleries while branch review remains
 * available for approving daily child galleries separately.
 *
 * @param int $galleryId Gallery identifier.
 * @return array{gallery:array<string,mixed>,start:?string,end:?string,suggestion:array<string,mixed>,range_label:string} Structured result data for the caller.
 */
function gallery_date_apply_exif_suggestion_to_gallery(int $galleryId): array
{
    if (!gallery_date_range_schema_ready() || !gallery_date_exif_suggestions_schema_ready()) {
        throw new RuntimeException(t('admin.gallery_dates.exif_unavailable', 'EXIF capture-date suggestions require the EXIF/GPS image metadata migration and scanned image rows.'));
    }

    // $gallery stores the existing row so missing ids fail before suggestion work starts.
    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        throw new RuntimeException(t('admin.gallery_dates.error_gallery_missing', 'Gallery #{id} no longer exists.', ['id' => (string) $galleryId]));
    }

    // $suggestion stores the computed recursive range for this exact gallery branch.
    $suggestion = gallery_date_exif_suggestion_for_gallery($galleryId);
    if (!$suggestion) {
        throw new RuntimeException(t('admin.gallery_editor.exif_date_suggestion_empty', 'No scanned EXIF capture dates were found in this gallery branch yet. Scan/import images first if the files were imported before EXIF extraction existed.'));
    }

    // $saveResult stores the persisted values returned by the shared range writer.
    $saveResult = gallery_date_save_range($galleryId, $suggestion['suggested_start'] ?? '', $suggestion['suggested_end'] ?? '');
    // $rangeLabel stores the compact admin-facing storage label, using the central en dash separator.
    $rangeLabel = gallery_date_range_storage_label($suggestion['suggested_start'] ?? null, $suggestion['suggested_end'] ?? null);

    return [
        'gallery' => is_array($saveResult['gallery'] ?? null) ? $saveResult['gallery'] : $gallery,
        'start' => $saveResult['start'] ?? null,
        'end' => $saveResult['end'] ?? null,
        'suggestion' => $suggestion,
        'range_label' => $rangeLabel,
    ];
}

/**
 * Count galleries that can receive an EXIF-derived date suggestion.
 *
 * @param ?int $scopeGalleryId Scope gallery id identifier.
 * @return int Integer result for the caller.
 */
function gallery_date_exif_suggestion_count(?int $scopeGalleryId = null): int
{
    return count(gallery_date_exif_suggestion_rows($scopeGalleryId));
}
