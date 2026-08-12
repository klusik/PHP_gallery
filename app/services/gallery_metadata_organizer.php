<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_metadata_organizer.php
 * Module Type: Service
 *
 * Purpose:
 *   Builds and applies metadata-driven gallery organization drafts.
 *
 * Responsibilities:
 *   - Read already-scanned image metadata from the database
 *   - Build safe preview plans before any filesystem operation happens
 *   - Create or reuse child galleries for metadata groups
 *   - Move source images through the existing transactional image-move service
 *   - Keep the grouping model extensible for future GPS-place grouping
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
 *   2026-06-16
 */

declare(strict_types=1);

namespace Gallery\Services;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use function Gallery\Core\db;

/**
 * Return true when image rows expose capture-date metadata needed by the organizer.
 *
 * @return bool True when the condition matches.
 */
function gallery_metadata_organizer_schema_ready(): bool
{
    return presentation_schema_render_available(
        presentation_metadata_organizer_schema_status(),
        'gallery_metadata_organizer.capture_date'
    );
}

/**
 * Return how many direct image rows belong to one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @return int Integer result for the caller.
 */
function gallery_metadata_organizer_source_image_count(int $galleryId): int
{
    if (!gallery_metadata_organizer_schema_ready()) {
        return 0;
    }

    $stmt = db()->prepare('SELECT COUNT(*) FROM images WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Return the default lower capture-date boundary used to avoid unset camera clocks.
 *
 * @return string Date in YYYY-MM-DD format.
 */
function gallery_metadata_organizer_default_min_date(): string
{
    return '1990-01-01';
}

/**
 * Return the default upper capture-date boundary for organizer previews.
 *
 * @return string Date in YYYY-MM-DD format.
 */
function gallery_metadata_organizer_default_max_date(): string
{
    return (new DateTimeImmutable('today'))->format('Y-m-d');
}

/**
 * Validate one organizer date boundary.
 *
 * @param mixed $value Value to process.
 * @param string $fallback Fallback date in YYYY-MM-DD format.
 * @return string Date in YYYY-MM-DD format.
 */
function gallery_metadata_organizer_boundary_date(mixed $value, string $fallback): string
{
    // $date stores the submitted boundary before strict validation.
    $date = trim((string) $value);
    if ($date === '') {
        $date = $fallback;
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
        throw new InvalidArgumentException(t('admin.metadata_organizer.invalid_date', 'Enter valid minimum and maximum dates.'));
    }

    return $date;
}

/**
 * Normalize metadata-organizer options from query or form values.
 *
 * @param array $input Input value.
 * @return array{primary:string,secondary:string,min_date:string,max_date:string} Structured result data for the caller.
 */
function gallery_metadata_organizer_options(array $input): array
{
    // $primary stores the future-proof first grouping dimension. Date is the only implemented mode now.
    $primary = strtolower(trim((string) ($input['primary_grouping'] ?? 'date')));
    if ($primary !== 'date') {
        $primary = 'date';
    }

    // $secondary stores the future-proof second grouping dimension. It is intentionally inactive in phase 1.
    $secondary = strtolower(trim((string) ($input['secondary_grouping'] ?? 'none')));
    if (!in_array($secondary, ['none', 'location'], true)) {
        $secondary = 'none';
    }
    if ($secondary === 'location') {
        $secondary = 'none';
    }

    $minDate = gallery_metadata_organizer_boundary_date($input['min_date'] ?? '', gallery_metadata_organizer_default_min_date());
    $maxDate = gallery_metadata_organizer_boundary_date($input['max_date'] ?? '', gallery_metadata_organizer_default_max_date());
    if (strcmp($maxDate, $minDate) < 0) {
        throw new InvalidArgumentException(t('admin.metadata_organizer.invalid_range', 'The maximum date cannot be before the minimum date.'));
    }

    return [
        'primary' => $primary,
        'secondary' => $secondary,
        'min_date' => $minDate,
        'max_date' => $maxDate,
    ];
}

/**
 * Return a date title used for daily destination galleries.
 *
 * @param string $date Date in YYYY-MM-DD format.
 * @return string Text result for the caller.
 */
function gallery_metadata_organizer_date_title(string $date): string
{
    if (function_exists('Gallery\\Services\\gallery_date_display_value')) {
        $label = gallery_date_display_value($date);
        if ($label !== '') {
            return $label;
        }
    }

    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed ? $parsed->format('j. n. Y') : $date;
}

/**
 * Return a stable folder name for a daily destination gallery.
 *
 * @param string $date Date in YYYY-MM-DD format.
 * @return string Text result for the caller.
 */
function gallery_metadata_organizer_date_folder_name(string $date): string
{
    return $date;
}

/**
 * Return the next append-style sort order for children of one gallery.
 *
 * @param int $parentGalleryId Parent gallery identifier.
 * @return int Integer result for the caller.
 */
function gallery_metadata_organizer_next_child_sort_order(int $parentGalleryId): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM galleries WHERE parent_id = ?');
    $stmt->execute([$parentGalleryId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Find a direct child gallery by the title that the organizer would create.
 *
 * @param int $parentGalleryId Parent gallery identifier.
 * @param string $title Gallery title.
 * @return array<string,mixed>|null Structured result data for the caller.
 */
function gallery_metadata_organizer_find_child_by_title(int $parentGalleryId, string $title): ?array
{
    $stmt = db()->prepare('SELECT * FROM galleries WHERE parent_id = ? AND title = ? ORDER BY sort_order, id LIMIT 1');
    $stmt->execute([$parentGalleryId, $title]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return direct image rows owned by a gallery for metadata organization.
 *
 * @param int $galleryId Gallery identifier.
 * @return array<int,array<string,mixed>> Structured result data for the caller.
 */
function gallery_metadata_organizer_source_images(int $galleryId): array
{
    return gallery_metadata_organizer_source_images_page($galleryId, 0, 0);
}

/**
 * Return a deterministic page of direct image rows owned by a gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $offset Row offset for preview pagination.
 * @param int $limit Maximum rows to return, or zero for all rows.
 * @return array<int,array<string,mixed>> Structured result data for the caller.
 */
function gallery_metadata_organizer_source_images_page(int $galleryId, int $offset = 0, int $limit = 0): array
{
    if (!gallery_metadata_organizer_schema_ready()) {
        return [];
    }

    $offset = max(0, $offset);
    $limit = max(0, $limit);
    $sql = "SELECT id, gallery_id, relative_path, filename, title, exif_taken_at, sort_order, visibility
        FROM images
        WHERE gallery_id = ?
        ORDER BY exif_taken_at, sort_order, filename, id";
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([$galleryId]);
    return $stmt->fetchAll();
}

/**
 * Return one image row enriched with the date organizer fields used by previews.
 *
 * @param array $image Image row or image data.
 * @param string $date Date in YYYY-MM-DD format.
 * @return array<string,mixed> Structured result data for the caller.
 */
function gallery_metadata_organizer_preview_image(array $image, string $date): array
{
    return [
        'id' => (int) ($image['id'] ?? 0),
        'filename' => (string) ($image['filename'] ?? ''),
        'relative_path' => (string) ($image['relative_path'] ?? ''),
        'title' => (string) ($image['title'] ?? ''),
        'visibility' => (string) ($image['visibility'] ?? ''),
        'exif_taken_at' => (string) ($image['exif_taken_at'] ?? ''),
        'group_date' => $date,
    ];
}

/**
 * Build a DB-driven organizer plan for date-based child galleries.
 *
 * The plan reads only existing image rows and does not scan media files. Images
 * without usable EXIF capture dates, and images outside the configured date
 * interval, are reported as ignored instead of being moved.
 *
 * @param int $galleryId Gallery identifier.
 * @param array $input Input value.
 * @return array<string,mixed> Structured result data for the caller.
 */
function gallery_metadata_organizer_build_date_plan(int $galleryId, array $input = [], int $offset = 0, int $limit = 0): array
{
    $offset = max(0, $offset);
    $limit = max(0, $limit);
    if (!gallery_metadata_organizer_schema_ready()) {
        return [
            'schema_ready' => false,
            'groups' => [],
            'options' => gallery_metadata_organizer_options($input),
            'total_images' => 0,
            'batch_total_images' => 0,
            'candidate_images' => 0,
            'ignored_without_date' => 0,
            'ignored_before_min' => 0,
            'ignored_after_max' => 0,
            'batch' => [
                'offset' => $offset,
                'limit' => $limit,
                'returned' => 0,
                'next_offset' => $offset,
                'done' => true,
            ],
        ];
    }

    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        throw new RuntimeException(t('admin.metadata_organizer.gallery_missing', 'Gallery was not found.'));
    }

    $options = gallery_metadata_organizer_options($input);
    $groups = [];
    $ignoredWithoutDate = 0;
    $ignoredBeforeMin = 0;
    $ignoredAfterMax = 0;
    $candidateImages = 0;
    $totalImages = gallery_metadata_organizer_source_image_count($galleryId);
    $sourceImages = $limit > 0
        ? gallery_metadata_organizer_source_images_page($galleryId, $offset, $limit)
        : gallery_metadata_organizer_source_images($galleryId);

    foreach ($sourceImages as $image) {
        // $takenAt stores the already-scanned capture timestamp from the images table.
        $takenAt = trim((string) ($image['exif_taken_at'] ?? ''));
        if ($takenAt === '') {
            $ignoredWithoutDate++;
            continue;
        }

        // $date stores the calendar date portion used by phase 1 grouping.
        $date = substr($takenAt, 0, 10);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            $ignoredWithoutDate++;
            continue;
        }
        if (strcmp($date, $options['min_date']) < 0) {
            $ignoredBeforeMin++;
            continue;
        }
        if (strcmp($date, $options['max_date']) > 0) {
            $ignoredAfterMax++;
            continue;
        }

        $candidateImages++;
        $title = gallery_metadata_organizer_date_title($date);
        if (!isset($groups[$date])) {
            $existingChild = gallery_metadata_organizer_find_child_by_title($galleryId, $title);
            $groups[$date] = [
                'key' => $date,
                'date' => $date,
                'title' => $title,
                'folder_name' => gallery_metadata_organizer_date_folder_name($date),
                'destination_gallery_id' => is_array($existingChild) ? (int) ($existingChild['id'] ?? 0) : 0,
                'destination_status' => is_array($existingChild) ? 'existing' : 'new',
                'destination_folder_path' => is_array($existingChild) ? (string) ($existingChild['folder_path'] ?? '') : '',
                'image_count' => 0,
                'images' => [],
            ];
        }
        $groups[$date]['image_count'] = (int) $groups[$date]['image_count'] + 1;
        $groups[$date]['images'][] = gallery_metadata_organizer_preview_image($image, $date);
    }

    ksort($groups);

    return [
        'schema_ready' => true,
        'gallery' => $gallery,
        'options' => $options,
        'groups' => array_values($groups),
        'total_images' => $totalImages,
        'batch_total_images' => count($sourceImages),
        'candidate_images' => $candidateImages,
        'ignored_without_date' => $ignoredWithoutDate,
        'ignored_before_min' => $ignoredBeforeMin,
        'ignored_after_max' => $ignoredAfterMax,
        'group_count' => count($groups),
        'batch' => [
            'offset' => $offset,
            'limit' => $limit,
            'returned' => count($sourceImages),
            'next_offset' => $offset + count($sourceImages),
            'done' => $limit <= 0 || ($offset + count($sourceImages)) >= $totalImages,
        ],
    ];
}

/**
 * Create one child gallery for a date group, inheriting safe defaults from the parent.
 *
 * @param array $sourceGallery Source gallery value.
 * @param array $group Organizer group value.
 * @param int $sortOrder Sort order value.
 * @return array<string,mixed> Structured result data for the caller.
 */
function gallery_metadata_organizer_create_child_gallery(array $sourceGallery, array $group, int $sortOrder): array
{
    $input = [
        'title' => (string) ($group['title'] ?? ''),
        'folder_name' => (string) ($group['folder_name'] ?? ''),
        'description' => '',
        'visibility' => gallery_visibility_storage_value((string) ($sourceGallery['visibility'] ?? 'unpublished')),
        'parent_id' => (int) ($sourceGallery['id'] ?? 0),
        'sort_order' => $sortOrder,
        'voting_enabled' => (int) ($sourceGallery['voting_enabled'] ?? 0) === 1,
        'show_filenames' => gallery_shows_filenames($sourceGallery),
    ];

    if (gallery_date_schema_ready()) {
        $input['gallery_date'] = (string) ($group['date'] ?? '');
        $input['gallery_date_end'] = (string) ($group['date'] ?? '');
    }
    if (gallery_count_badge_schema_ready()) {
        $input['count_badge_visibility'] = gallery_count_badge_storage_value($sourceGallery['count_badge_visibility'] ?? null) ?? 'inherit';
    }
    presentation_schema_assert_known(
        presentation_lightbox_override_schema_status(),
        'gallery_metadata_organizer.inherit_lightbox_override'
    );
    if (gallery_lightbox_browsing_mode_schema_ready()) {
        $input['lightbox_browsing_mode'] = gallery_lightbox_browsing_mode_storage_value($sourceGallery['lightbox_browsing_mode'] ?? null) ?? 'inherit';
    }

    return create_empty_gallery($input);
}

/**
 * Return image ids from an organizer group.
 *
 * @param array $group Organizer group value.
 * @return array<int> Image identifiers.
 */
function gallery_metadata_organizer_group_image_ids(array $group): array
{
    $ids = [];
    foreach ((array) ($group['images'] ?? []) as $image) {
        $imageId = (int) ($image['id'] ?? 0);
        if ($imageId > 0) {
            $ids[] = $imageId;
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Return the next candidate photos that still belong to the source gallery.
 *
 * The apply workflow deliberately does not use offsets because each successful
 * batch changes gallery ownership. Selecting the next remaining candidate keeps
 * repeated AJAX calls stable even after earlier photos have moved.
 *
 * @param int $galleryId Gallery identifier.
 * @param array $input Input value.
 * @param int $limit Maximum number of candidate rows.
 * @return array<int,array<string,mixed>> Structured result data for the caller.
 */
function gallery_metadata_organizer_candidate_images_batch(int $galleryId, array $input, int $limit): array
{
    if (!gallery_metadata_organizer_schema_ready()) {
        return [];
    }

    $options = gallery_metadata_organizer_options($input);
    $limit = max(1, min(100, $limit));
    $stmt = db()->prepare("SELECT id, gallery_id, relative_path, filename, title, exif_taken_at, sort_order, visibility
        FROM images
        WHERE gallery_id = ?
          AND exif_taken_at IS NOT NULL
          AND TRIM(exif_taken_at) <> ''
          AND SUBSTR(exif_taken_at, 1, 10) >= ?
          AND SUBSTR(exif_taken_at, 1, 10) <= ?
        ORDER BY exif_taken_at, sort_order, filename, id
        LIMIT " . $limit);
    $stmt->execute([$galleryId, $options['min_date'], $options['max_date']]);

    $rows = [];
    foreach ($stmt->fetchAll() as $image) {
        $takenAt = trim((string) ($image['exif_taken_at'] ?? ''));
        $date = substr($takenAt, 0, 10);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            continue;
        }
        $rows[] = $image;
    }
    return $rows;
}

/**
 * Return how many candidate photos remain in the source gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @param array $input Input value.
 * @return int Integer result for the caller.
 */
function gallery_metadata_organizer_remaining_candidate_count(int $galleryId, array $input): int
{
    if (!gallery_metadata_organizer_schema_ready()) {
        return 0;
    }

    $options = gallery_metadata_organizer_options($input);
    $stmt = db()->prepare("SELECT COUNT(*)
        FROM images
        WHERE gallery_id = ?
          AND exif_taken_at IS NOT NULL
          AND TRIM(exif_taken_at) <> ''
          AND SUBSTR(exif_taken_at, 1, 10) >= ?
          AND SUBSTR(exif_taken_at, 1, 10) <= ?");
    $stmt->execute([$galleryId, $options['min_date'], $options['max_date']]);
    return (int) $stmt->fetchColumn();
}

/**
 * Build date groups from a small set of image rows.
 *
 * @param int $galleryId Source gallery identifier.
 * @param array<int,array<string,mixed>> $images Image rows.
 * @param array<string,mixed> $options Organizer options.
 * @return array<int,array<string,mixed>> Structured result data for the caller.
 */
function gallery_metadata_organizer_groups_from_images(int $galleryId, array $images, array $options): array
{
    $groups = [];
    foreach ($images as $image) {
        $takenAt = trim((string) ($image['exif_taken_at'] ?? ''));
        $date = substr($takenAt, 0, 10);
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            continue;
        }
        if (strcmp($date, (string) ($options['min_date'] ?? '')) < 0 || strcmp($date, (string) ($options['max_date'] ?? '')) > 0) {
            continue;
        }

        $title = gallery_metadata_organizer_date_title($date);
        if (!isset($groups[$date])) {
            $existingChild = gallery_metadata_organizer_find_child_by_title($galleryId, $title);
            $groups[$date] = [
                'key' => $date,
                'date' => $date,
                'title' => $title,
                'folder_name' => gallery_metadata_organizer_date_folder_name($date),
                'destination_gallery_id' => is_array($existingChild) ? (int) ($existingChild['id'] ?? 0) : 0,
                'destination_status' => is_array($existingChild) ? 'existing' : 'new',
                'destination_folder_path' => is_array($existingChild) ? (string) ($existingChild['folder_path'] ?? '') : '',
                'image_count' => 0,
                'images' => [],
            ];
        }
        $groups[$date]['image_count'] = (int) $groups[$date]['image_count'] + 1;
        $groups[$date]['images'][] = gallery_metadata_organizer_preview_image($image, $date);
    }
    ksort($groups);
    return array_values($groups);
}

/**
 * Apply one AJAX-sized date organizer batch.
 *
 * @param int $galleryId Gallery identifier.
 * @param array $input Input value.
 * @param int $limit Maximum candidate photos to move in this request.
 * @return array<string,mixed> Structured result data for the caller.
 */
function gallery_metadata_organizer_apply_date_plan_batch(int $galleryId, array $input = [], int $limit = 1): array
{
    $requestStartedAt = microtime(true);
    if (!gallery_metadata_organizer_schema_ready()) {
        throw new RuntimeException(t('admin.metadata_organizer.schema_unavailable', 'Metadata organizer requires scanned EXIF capture-date data in the image database.'));
    }

    $sourceGallery = find_gallery($galleryId, true);
    if (!$sourceGallery) {
        throw new RuntimeException(t('admin.metadata_organizer.gallery_missing', 'Gallery was not found.'));
    }

    $limit = max(1, min(10, $limit));
    $optionsStartedAt = microtime(true);
    $options = gallery_metadata_organizer_options($input);
    $remainingBefore = gallery_metadata_organizer_remaining_candidate_count($galleryId, $input);
    $candidateRows = gallery_metadata_organizer_candidate_images_batch($galleryId, $input, $limit);
    $selectionMs = gallery_metadata_organizer_elapsed_ms($optionsStartedAt);
    if ($remainingBefore > 0 && !$candidateRows) {
        throw new RuntimeException(t('admin.metadata_organizer.no_valid_candidates_left', 'The organizer found remaining database candidates, but none of them had a valid EXIF date. Re-scan metadata or narrow the date range.'));
    }
    $groups = gallery_metadata_organizer_groups_from_images($galleryId, $candidateRows, $options);

    $nextSortOrder = gallery_metadata_organizer_next_child_sort_order($galleryId);
    $createdGalleries = 0;
    $reusedGalleries = 0;
    $requestedImages = 0;
    $movedImages = 0;
    $originalsMoved = 0;
    $derivativesMoved = 0;
    $groupResults = [];
    $failures = [];
    $touchedDestinationIds = [];
    $moveMs = 0;
    $maintenanceMs = 0;
    $maintenance = [
        'ran' => false,
        'thumbnail_cache_cleared' => false,
        'gallery_public_paths' => 0,
        'image_public_slugs' => 0,
        'sidecars_written' => 0,
    ];

    foreach ($groups as $group) {
        $groupStartedAt = microtime(true);
        $imageIds = gallery_metadata_organizer_group_image_ids($group);
        if (!$imageIds) {
            continue;
        }

        $requestedImages += count($imageIds);
        $createdThisGallery = false;
        $destinationGallery = gallery_metadata_organizer_find_child_by_title($galleryId, (string) ($group['title'] ?? ''));
        if (!$destinationGallery) {
            $destinationGallery = gallery_metadata_organizer_create_child_gallery($sourceGallery, $group, $nextSortOrder);
            $nextSortOrder += 10;
            $createdGalleries++;
            $createdThisGallery = true;
        } else {
            $reusedGalleries++;
        }

        $destinationGalleryId = (int) ($destinationGallery['id'] ?? 0);
        if ($destinationGalleryId <= 0) {
            $failures[] = (string) ($group['title'] ?? '') . ': destination gallery could not be resolved.';
            continue;
        }
        $touchedDestinationIds[$destinationGalleryId] = $destinationGalleryId;

        try {
            $moveResult = move_gallery_images($galleryId, $destinationGalleryId, $imageIds, ['defer_maintenance' => true]);
        } catch (Throwable $exception) {
            if ($createdThisGallery) {
                delete_gallery_subtrees([$destinationGalleryId]);
            }
            $failures[] = (string) ($group['title'] ?? '') . ': ' . $exception->getMessage();
            continue;
        }

        if (!empty($moveResult['failures'])) {
            if ($createdThisGallery) {
                delete_gallery_subtrees([$destinationGalleryId]);
            }
            foreach ((array) ($moveResult['failures'] ?? []) as $failure) {
                $failures[] = (string) ($group['title'] ?? '') . ': ' . (string) $failure;
            }
            continue;
        }

        $groupMoveMs = gallery_metadata_organizer_elapsed_ms($groupStartedAt);
        $moveMs += $groupMoveMs;
        $movedImages += (int) ($moveResult['moved'] ?? 0);
        $originalsMoved += (int) ($moveResult['originals_moved'] ?? 0);
        $derivativesMoved += (int) ($moveResult['derivatives_moved'] ?? 0);
        $groupResults[] = [
            'title' => (string) ($group['title'] ?? ''),
            'date' => (string) ($group['date'] ?? ''),
            'destination_gallery_id' => $destinationGalleryId,
            'destination_status' => $createdThisGallery ? 'created' : 'existing',
            'requested' => count($imageIds),
            'moved' => (int) ($moveResult['moved'] ?? 0),
            'originals_moved' => (int) ($moveResult['originals_moved'] ?? 0),
            'derivatives_moved' => (int) ($moveResult['derivatives_moved'] ?? 0),
            'duration_ms' => $groupMoveMs,
        ];
    }

    $remainingAfter = gallery_metadata_organizer_remaining_candidate_count($galleryId, $input);
    $done = $remainingAfter <= 0;
    if ($done || $failures) {
        $maintenanceStartedAt = microtime(true);
        $maintenance = gallery_metadata_organizer_finalize_move_maintenance($galleryId, array_values($touchedDestinationIds));
        $maintenanceMs = gallery_metadata_organizer_elapsed_ms($maintenanceStartedAt);
    }

    if (function_exists('Gallery\\Services\\admin_log_event')) {
        admin_log_event($failures ? 'warning' : 'info', 'metadata_organizer.date_apply_batch', 'Admin applied one metadata organizer date batch.', [
            'gallery_id' => $galleryId,
            'batch_limit' => $limit,
            'candidate_rows' => count($candidateRows),
            'created_galleries' => $createdGalleries,
            'reused_galleries' => $reusedGalleries,
            'requested_images' => $requestedImages,
            'moved_images' => $movedImages,
            'originals_moved' => $originalsMoved,
            'derivatives_moved' => $derivativesMoved,
            'remaining_before' => $remainingBefore,
            'remaining_after' => $remainingAfter,
            'duration_ms' => gallery_metadata_organizer_elapsed_ms($requestStartedAt),
            'selection_ms' => $selectionMs,
            'move_ms' => $moveMs,
            'maintenance_ms' => $maintenanceMs,
            'failures' => array_slice($failures, 0, 20),
        ], ['category' => 'media', 'severity' => $failures ? 'warning' : 'info']);
    }

    return [
        'created_galleries' => $createdGalleries,
        'reused_galleries' => $reusedGalleries,
        'requested_images' => $requestedImages,
        'moved_images' => $movedImages,
        'originals_moved' => $originalsMoved,
        'derivatives_moved' => $derivativesMoved,
        'remaining_before' => $remainingBefore,
        'remaining_after' => $remainingAfter,
        'done' => $done,
        'batch_limit' => $limit,
        'candidate_rows' => count($candidateRows),
        'groups_processed' => count($groups),
        'selection_ms' => $selectionMs,
        'move_ms' => $moveMs,
        'maintenance_ms' => $maintenanceMs,
        'duration_ms' => gallery_metadata_organizer_elapsed_ms($requestStartedAt),
        'maintenance' => $maintenance,
        'group_results' => $groupResults,
        'failures' => $failures,
    ];
}

/**
 * Return elapsed milliseconds since a monotonic-style request timestamp.
 *
 * @param float $startedAt Timestamp from microtime(true).
 * @return int Integer result for the caller.
 */
function gallery_metadata_organizer_elapsed_ms(float $startedAt): int
{
    return max(0, (int) round((microtime(true) - $startedAt) * 1000));
}

/**
 * Run shared move maintenance once after AJAX-sized organizer moves.
 *
 * @param int $sourceGalleryId Source gallery identifier.
 * @param array<int> $destinationGalleryIds Destination galleries touched by the current request.
 * @return array<string,mixed> Structured result data for the caller.
 */
function gallery_metadata_organizer_finalize_move_maintenance(int $sourceGalleryId, array $destinationGalleryIds = []): array
{
    $result = [
        'ran' => true,
        'thumbnail_cache_cleared' => false,
        'gallery_public_paths' => 0,
        'image_public_slugs' => 0,
        'sidecars_written' => 0,
    ];

    thumbnail_maintenance_summary_cache_clear();
    $result['thumbnail_cache_cleared'] = true;

    $directChildIds = gallery_metadata_organizer_direct_child_ids($sourceGalleryId);
    $affectedGalleryIds = array_values(array_unique(array_filter(array_merge([$sourceGalleryId], $directChildIds, array_map('intval', $destinationGalleryIds)), static fn (int $galleryId): bool => $galleryId > 0)));

    if (public_path_schema_ready()) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $result['gallery_public_paths'] = regenerate_gallery_public_paths($pdo);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        foreach ($affectedGalleryIds as $galleryId) {
            $result['image_public_slugs'] += regenerate_gallery_image_public_slugs($galleryId);
        }
    }

    foreach ($affectedGalleryIds as $galleryId) {
        $gallery = find_gallery($galleryId, true);
        if ($gallery && write_gallery_sidecar($gallery)) {
            $result['sidecars_written']++;
        }
    }

    return $result;
}

/**
 * Return direct child gallery identifiers for a source gallery.
 *
 * @param int $sourceGalleryId Source gallery identifier.
 * @return array<int> Integer identifiers.
 */
function gallery_metadata_organizer_direct_child_ids(int $sourceGalleryId): array
{
    if ($sourceGalleryId <= 0) {
        return [];
    }

    $stmt = db()->prepare('SELECT id FROM galleries WHERE parent_id = ? ORDER BY sort_order, id');
    $stmt->execute([$sourceGalleryId]);
    return array_values(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
}

/**
 * Apply a date organizer plan by creating or reusing child galleries and moving files.
 *
 * @param int $galleryId Gallery identifier.
 * @param array $input Input value.
 * @return array<string,mixed> Structured result data for the caller.
 */
function gallery_metadata_organizer_apply_date_plan(int $galleryId, array $input = []): array
{
    $plan = gallery_metadata_organizer_build_date_plan($galleryId, $input);
    if (empty($plan['schema_ready'])) {
        throw new RuntimeException(t('admin.metadata_organizer.schema_unavailable', 'Metadata organizer requires scanned EXIF capture-date data in the image database.'));
    }

    $sourceGallery = is_array($plan['gallery'] ?? null) ? $plan['gallery'] : find_gallery($galleryId, true);
    if (!$sourceGallery) {
        throw new RuntimeException(t('admin.metadata_organizer.gallery_missing', 'Gallery was not found.'));
    }

    $nextSortOrder = gallery_metadata_organizer_next_child_sort_order($galleryId);
    $createdGalleries = 0;
    $reusedGalleries = 0;
    $requestedImages = 0;
    $movedImages = 0;
    $originalsMoved = 0;
    $derivativesMoved = 0;
    $groupResults = [];
    $failures = [];

    foreach ((array) ($plan['groups'] ?? []) as $group) {
        $imageIds = gallery_metadata_organizer_group_image_ids($group);
        if (!$imageIds) {
            continue;
        }

        $requestedImages += count($imageIds);
        $createdThisGallery = false;
        $destinationGallery = gallery_metadata_organizer_find_child_by_title($galleryId, (string) ($group['title'] ?? ''));
        if (!$destinationGallery) {
            $destinationGallery = gallery_metadata_organizer_create_child_gallery($sourceGallery, $group, $nextSortOrder);
            $nextSortOrder += 10;
            $createdGalleries++;
            $createdThisGallery = true;
        } else {
            $reusedGalleries++;
        }

        $destinationGalleryId = (int) ($destinationGallery['id'] ?? 0);
        if ($destinationGalleryId <= 0) {
            $failures[] = (string) ($group['title'] ?? '') . ': destination gallery could not be resolved.';
            continue;
        }

        try {
            $moveResult = move_gallery_images($galleryId, $destinationGalleryId, $imageIds);
        } catch (Throwable $exception) {
            if ($createdThisGallery) {
                delete_gallery_subtrees([$destinationGalleryId]);
            }
            $failures[] = (string) ($group['title'] ?? '') . ': ' . $exception->getMessage();
            continue;
        }

        if (!empty($moveResult['failures'])) {
            if ($createdThisGallery) {
                delete_gallery_subtrees([$destinationGalleryId]);
            }
            foreach ((array) ($moveResult['failures'] ?? []) as $failure) {
                $failures[] = (string) ($group['title'] ?? '') . ': ' . (string) $failure;
            }
            continue;
        }

        $movedImages += (int) ($moveResult['moved'] ?? 0);
        $originalsMoved += (int) ($moveResult['originals_moved'] ?? 0);
        $derivativesMoved += (int) ($moveResult['derivatives_moved'] ?? 0);
        $groupResults[] = [
            'title' => (string) ($group['title'] ?? ''),
            'date' => (string) ($group['date'] ?? ''),
            'destination_gallery_id' => $destinationGalleryId,
            'destination_status' => $createdThisGallery ? 'created' : 'existing',
            'requested' => count($imageIds),
            'moved' => (int) ($moveResult['moved'] ?? 0),
        ];
    }

    if (function_exists('Gallery\\Services\\admin_log_event')) {
        admin_log_event($failures ? 'warning' : 'info', 'metadata_organizer.date_apply', 'Admin applied metadata organizer date plan.', [
            'gallery_id' => $galleryId,
            'created_galleries' => $createdGalleries,
            'reused_galleries' => $reusedGalleries,
            'requested_images' => $requestedImages,
            'moved_images' => $movedImages,
            'failures' => array_slice($failures, 0, 20),
        ], ['category' => 'media', 'severity' => $failures ? 'warning' : 'info']);
    }

    // Refresh the source gallery sidecar after all groups were processed.
    $updatedSourceGallery = find_gallery($galleryId, true);
    if ($updatedSourceGallery) {
        write_gallery_sidecar($updatedSourceGallery);
    }

    return [
        'plan' => $plan,
        'created_galleries' => $createdGalleries,
        'reused_galleries' => $reusedGalleries,
        'requested_images' => $requestedImages,
        'moved_images' => $movedImages,
        'originals_moved' => $originalsMoved,
        'derivatives_moved' => $derivativesMoved,
        'group_results' => $groupResults,
        'failures' => $failures,
    ];
}

/**
 * Build the flash message for an applied organizer result.
 *
 * @param array $result Apply result value.
 * @return string Text result for the caller.
 */
function gallery_metadata_organizer_apply_notice(array $result): string
{
    $message = t('admin.metadata_organizer.apply_notice', 'Created {created} subgallery/subgalleries, reused {reused}, moved {moved} of {requested} photo(s), including {originals} original file(s) and {derivatives} derivative file(s).', [
        'created' => (string) (int) ($result['created_galleries'] ?? 0),
        'reused' => (string) (int) ($result['reused_galleries'] ?? 0),
        'moved' => (string) (int) ($result['moved_images'] ?? 0),
        'requested' => (string) (int) ($result['requested_images'] ?? 0),
        'originals' => (string) (int) ($result['originals_moved'] ?? 0),
        'derivatives' => (string) (int) ($result['derivatives_moved'] ?? 0),
    ]);

    $failures = array_values(array_filter(array_map('strval', (array) ($result['failures'] ?? []))));
    if ($failures) {
        $message .= ' ' . t('admin.metadata_organizer.apply_warning', 'Warnings: {warnings}', [
            'warnings' => implode(' | ', array_slice($failures, 0, 5)),
        ]);
    }

    return $message;
}
