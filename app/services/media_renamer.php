<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/media_renamer.php
 * Module Type: Service
 *
 * Purpose:
 *   Builds and executes deterministic context-aware image filename rename plans.
 *
 * Responsibilities:
 *   - Generate safe ASCII filenames from gallery context and image order
 *   - Preview physical file renames without changing disk or database state
 *   - Rename originals and generated derivatives with rollback on failure
 *   - Update image database rows and invalidate dependent generated assets
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
 *   2026-06-02
 */

declare(strict_types=1);

/**
 * Return gallery rows with direct-image counts for the site-wide renamer UI.
 */
function media_renamer_gallery_rows(bool $hideEmptyGalleries = false): array
{
    $having = $hideEmptyGalleries ? ' HAVING direct_image_count > 0' : '';
    $stmt = db()->query("SELECT g.*, COUNT(i.id) AS direct_image_count
        FROM galleries g
        LEFT JOIN images i ON i.gallery_id = g.id AND i.relative_path NOT LIKE '%/%'
        GROUP BY g.id" . $having . "
        ORDER BY CHAR_LENGTH(g.folder_path), g.folder_path, g.title, g.id");
    return $stmt->fetchAll();
}

/**
 * Return every gallery id in stable filesystem order.
 *
 * @return array<int>
 */
function media_renamer_all_gallery_ids(bool $hideEmptyGalleries = false): array
{
    if (!$hideEmptyGalleries) {
        return array_map('intval', db()->query('SELECT id FROM galleries ORDER BY CHAR_LENGTH(folder_path), folder_path, id')->fetchAll(PDO::FETCH_COLUMN));
    }

    $stmt = db()->query("SELECT g.id
        FROM galleries g
        INNER JOIN images i ON i.gallery_id = g.id AND i.relative_path NOT LIKE '%/%'
        GROUP BY g.id
        ORDER BY CHAR_LENGTH(g.folder_path), g.folder_path, g.id");
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}


/**
 * Add pending-rename counts to gallery rows and optionally keep only galleries with rename candidates.
 *
 * This scan is intentionally separated from the normal gallery-list query because each gallery needs
 * a deterministic dry-run plan for the current filename pattern.
 *
 * @param array<int,array<string,mixed>> $galleryRows
 * @return array{rows:array<int,array<string,mixed>>,availability:array<int,array<string,int>>}
 */
function media_renamer_gallery_rows_with_rename_availability(array $galleryRows, string $pattern = '', bool $hideWithoutRenameCandidates = false): array
{
    $rows = [];
    $availability = [];
    $pattern = media_renamer_normalize_pattern($pattern);

    foreach ($galleryRows as $galleryRow) {
        $galleryId = (int) ($galleryRow['id'] ?? 0);
        if ($galleryId <= 0) {
            continue;
        }

        try {
            $plan = media_renamer_plan_for_gallery($galleryId, $pattern);
            $summary = (array) ($plan['summary'] ?? []);
            $renameCount = (int) ($summary['rename'] ?? 0);
            $warningCount = (int) (($summary['warnings'] ?? 0) + ($summary['missing'] ?? 0) + ($summary['collision'] ?? 0) + ($summary['skipped'] ?? 0));
        } catch (Throwable $exception) {
            $renameCount = 0;
            $warningCount = 1;
        }

        $availability[$galleryId] = [
            'rename_count' => $renameCount,
            'warning_count' => $warningCount,
        ];

        if ($hideWithoutRenameCandidates && $renameCount <= 0) {
            continue;
        }

        $galleryRow['rename_candidate_count'] = $renameCount;
        $galleryRow['rename_warning_count'] = $warningCount;
        $rows[] = $galleryRow;
    }

    return [
        'rows' => $rows,
        'availability' => $availability,
    ];
}



/**
 * Return pending-rename availability counts for selected gallery ids.
 *
 * @param array<int|string> $galleryIds
 * @return array<int,array<string,int>>
 */
function media_renamer_availability_for_gallery_ids(array $galleryIds, string $pattern = ''): array
{
    $availability = [];
    $pattern = media_renamer_normalize_pattern($pattern);

    foreach (media_renamer_existing_gallery_ids($galleryIds) as $galleryId) {
        try {
            $plan = media_renamer_plan_for_gallery($galleryId, $pattern);
            $summary = (array) ($plan['summary'] ?? []);
            $availability[$galleryId] = [
                'rename_count' => (int) ($summary['rename'] ?? 0),
                'warning_count' => (int) (($summary['warnings'] ?? 0) + ($summary['missing'] ?? 0) + ($summary['collision'] ?? 0) + ($summary['skipped'] ?? 0)),
            ];
        } catch (Throwable $exception) {
            $availability[$galleryId] = [
                'rename_count' => 0,
                'warning_count' => 1,
            ];
        }
    }

    return $availability;
}

/**
 * Add previously checked pending-rename counts to gallery rows and optionally filter them.
 *
 * @param array<int,array<string,mixed>> $galleryRows
 * @param array<int,array<string,int>> $availability
 * @return array<int,array<string,mixed>>
 */
function media_renamer_gallery_rows_with_submitted_availability(array $galleryRows, array $availability, bool $hideWithoutRenameCandidates = false): array
{
    $rows = [];

    foreach ($galleryRows as $galleryRow) {
        $galleryId = (int) ($galleryRow['id'] ?? 0);
        $counts = (array) ($availability[$galleryId] ?? []);
        $renameCount = (int) ($counts['rename_count'] ?? 0);
        $warningCount = (int) ($counts['warning_count'] ?? 0);

        if ($hideWithoutRenameCandidates && $renameCount <= 0) {
            continue;
        }

        $galleryRow['rename_candidate_count'] = $renameCount;
        $galleryRow['rename_warning_count'] = $warningCount;
        $rows[] = $galleryRow;
    }

    return $rows;
}

/**
 * Filter gallery ids to galleries that still have at least one pending rename candidate.
 *
 * @param array<int|string> $galleryIds
 * @return array<int>
 */
function media_renamer_gallery_ids_with_pending_renames(array $galleryIds, string $pattern = ''): array
{
    $filtered = [];
    $pattern = media_renamer_normalize_pattern($pattern);

    foreach (media_renamer_existing_gallery_ids($galleryIds) as $galleryId) {
        try {
            $plan = media_renamer_plan_for_gallery($galleryId, $pattern);
            $summary = (array) ($plan['summary'] ?? []);
            if ((int) ($summary['rename'] ?? 0) > 0) {
                $filtered[] = $galleryId;
            }
        } catch (Throwable $exception) {
            // Unreadable galleries are not silently selected by the availability filter.
        }
    }

    return $filtered;
}

/**
 * Normalize requested gallery ids to existing galleries only.
 *
 * @param array<int|string> $galleryIds
 * @return array<int>
 */
function media_renamer_existing_gallery_ids(array $galleryIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare('SELECT id FROM galleries WHERE id IN (' . $placeholders . ') ORDER BY CHAR_LENGTH(folder_path), folder_path, id');
    $stmt->execute($ids);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Build rename plans for several galleries.
 *
 * @param array<int|string> $galleryIds
 * @return array<int,array<string,mixed>>
 */
function media_renamer_plans_for_galleries(array $galleryIds, string $pattern = ''): array
{
    $plans = [];
    foreach (media_renamer_existing_gallery_ids($galleryIds) as $galleryId) {
        $plans[] = media_renamer_plan_for_gallery($galleryId, $pattern);
    }
    return $plans;
}

/**
 * Build a complete dry-run rename plan for one gallery.
 *
 * @return array<string,mixed>
 */
function media_renamer_plan_for_gallery(int $galleryId, string $pattern = ''): array
{
    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        throw new RuntimeException(t('admin.media_renamer.error_gallery_missing', 'Gallery was not found.'));
    }

    $images = gallery_images($galleryId, false);
    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    $pattern = media_renamer_normalize_pattern($pattern);
    $contextBase = media_renamer_gallery_context_base($gallery);
    $selectedImageIds = array_fill_keys(array_map(static fn (array $image): int => (int) $image['id'], $images), true);
    $currentFileKeys = media_renamer_current_file_keys($images, $gallery);
    $usedTargetPaths = [];
    $items = [];
    $sequence = 1;

    foreach ($images as $image) {
        $item = media_renamer_plan_item(
            $gallery,
            $galleryRoot,
            $contextBase,
            $pattern,
            $image,
            $sequence,
            $selectedImageIds,
            $currentFileKeys,
            $usedTargetPaths
        );
        if (!media_renamer_plan_item_is_hidden_noop($item)) {
            $items[] = $item;
        }
        $sequence++;
    }

    return [
        'gallery' => $gallery,
        'items' => $items,
        'summary' => media_renamer_summarize_items($items),
        'context_base' => $contextBase,
        'pattern' => $pattern,
    ];
}

/**
 * Return true for no-op rows that should stay out of previews and apply details.
 *
 * Already-renamed files are intentionally treated as invisible no-ops. This keeps
 * repeated preview/apply runs focused only on files that still need a physical
 * rename or require operator attention, while the deterministic sequence still
 * advances according to the gallery image order.
 */
function media_renamer_plan_item_is_hidden_noop(array $item): bool
{
    return (string) ($item['status'] ?? '') === 'already_matches'
        && empty($item['warnings'])
        && empty($item['can_rename']);
}

/**
 * Build one row inside a dry-run plan.
 *
 * @param array<int,bool> $selectedImageIds
 * @param array<string,bool> $currentFileKeys
 * @param array<string,int> $usedTargetPaths
 * @return array<string,mixed>
 */
function media_renamer_plan_item(array $gallery, string $galleryRoot, string $contextBase, string $pattern, array $image, int $sequence, array $selectedImageIds, array $currentFileKeys, array &$usedTargetPaths): array
{
    $oldRelativePath = normalize_relative_path((string) ($image['relative_path'] ?? ''));
    $oldFilename = (string) ($image['filename'] ?? basename($oldRelativePath));
    $warnings = [];

    if ($oldRelativePath === '' || str_contains($oldRelativePath, '/')) {
        return media_renamer_item_result($gallery, $image, $oldRelativePath, $oldFilename, '', '', 'skipped', [t('admin.media_renamer.warning_nested_path', 'Only direct image files in the gallery folder are renamed.')], false);
    }

    $sourcePath = image_abs_path($image, $gallery);
    if (!thumbnail_path_inside_existing_gallery($galleryRoot, $sourcePath)) {
        return media_renamer_item_result($gallery, $image, $oldRelativePath, $oldFilename, '', '', 'skipped', [t('admin.media_renamer.warning_source_outside_gallery', 'Source file is outside the gallery folder.')], false);
    }

    if (!is_file($sourcePath)) {
        return media_renamer_item_result($gallery, $image, $oldRelativePath, $oldFilename, '', '', 'missing', [t('admin.media_renamer.warning_missing_file', 'Source file is missing on disk.')], false);
    }

    $extension = media_renamer_safe_extension($oldFilename);
    $target = media_renamer_unique_target($gallery, $galleryRoot, $image, $contextBase, $pattern, $sequence, $extension, $selectedImageIds, $currentFileKeys, $usedTargetPaths, $warnings);
    if ($target === null) {
        return media_renamer_item_result($gallery, $image, $oldRelativePath, $oldFilename, '', '', 'collision', array_merge($warnings, [t('admin.media_renamer.warning_no_collision_free_target', 'No safe collision-free target filename could be found.')]), false);
    }

    $newFilename = $target['filename'];
    $newRelativePath = $target['relative_path'];
    $targetPath = $galleryRoot . DIRECTORY_SEPARATOR . $newFilename;
    if (!thumbnail_path_inside_existing_gallery($galleryRoot, $targetPath)) {
        return media_renamer_item_result($gallery, $image, $oldRelativePath, $oldFilename, $newRelativePath, $newFilename, 'collision', [t('admin.media_renamer.warning_target_outside_gallery', 'Generated target path is outside the gallery folder.')], false);
    }

    if ($oldRelativePath === $newRelativePath) {
        return media_renamer_item_result($gallery, $image, $oldRelativePath, $oldFilename, $newRelativePath, $newFilename, 'already_matches', $warnings, false);
    }

    return media_renamer_item_result($gallery, $image, $oldRelativePath, $oldFilename, $newRelativePath, $newFilename, 'rename', $warnings, true);
}

/**
 * Return a normalized plan row.
 *
 * @return array<string,mixed>
 */
function media_renamer_item_result(array $gallery, array $image, string $oldRelativePath, string $oldFilename, string $newRelativePath, string $newFilename, string $status, array $warnings, bool $canRename): array
{
    $targetImage = $image;
    if ($newRelativePath !== '') {
        $targetImage['relative_path'] = $newRelativePath;
        $targetImage['relative_path_hash'] = hash('sha256', $newRelativePath);
        $targetImage['filename'] = $newFilename;
    }

    return [
        'gallery_id' => (int) ($gallery['id'] ?? 0),
        'image_id' => (int) ($image['id'] ?? 0),
        'image' => $image,
        'target_image' => $targetImage,
        'old_relative_path' => $oldRelativePath,
        'old_filename' => $oldFilename,
        'new_relative_path' => $newRelativePath,
        'new_filename' => $newFilename,
        'status' => $status,
        'warnings' => array_values(array_filter(array_map('strval', $warnings), static fn (string $warning): bool => trim($warning) !== '')),
        'can_rename' => $canRename,
    ];
}

/**
 * Find a safe target filename for one image and reserve it inside the plan.
 *
 * @param array<int,bool> $selectedImageIds
 * @param array<string,bool> $currentFileKeys
 * @param array<string,int> $usedTargetPaths
 * @param array<int,string> $warnings
 * @return array{filename:string,relative_path:string}|null
 */
function media_renamer_unique_target(array $gallery, string $galleryRoot, array $image, string $contextBase, string $pattern, int $sequence, string $extension, array $selectedImageIds, array $currentFileKeys, array &$usedTargetPaths, array &$warnings): ?array
{
    $collisionCount = 0;
    for ($suffix = 0; $suffix <= 99; $suffix++) {
        $filename = media_renamer_build_filename($contextBase, $sequence, $extension, $suffix, $pattern, $gallery, $image);
        $relativePath = $filename;
        $targetPath = $galleryRoot . DIRECTORY_SEPARATOR . $filename;
        $targetKey = media_renamer_path_key($targetPath);

        if (isset($usedTargetPaths[$targetKey])) {
            $collisionCount++;
            continue;
        }

        $conflict = find_image_by_path((int) $gallery['id'], $relativePath);
        if ($conflict && (int) ($conflict['id'] ?? 0) !== (int) ($image['id'] ?? 0) && !isset($selectedImageIds[(int) $conflict['id']])) {
            $collisionCount++;
            continue;
        }

        if (file_exists($targetPath) && !isset($currentFileKeys[$targetKey])) {
            $collisionCount++;
            continue;
        }

        $targetImage = $image;
        $targetImage['relative_path'] = $relativePath;
        $targetImage['filename'] = $filename;
        if (media_renamer_target_derivative_conflicts($image, $targetImage, $gallery, $currentFileKeys)) {
            $collisionCount++;
            continue;
        }

        $usedTargetPaths[$targetKey] = (int) ($image['id'] ?? 0);
        if ($collisionCount > 0 || $suffix > 0) {
            $warnings[] = t('admin.media_renamer.warning_collision_adjusted', 'The preferred filename was already occupied, so a deterministic suffix was added.');
        }
        return ['filename' => $filename, 'relative_path' => $relativePath];
    }

    return null;
}

/**
 * Return true when generated target derivative paths would overwrite stale files.
 *
 * @param array<string,bool> $currentFileKeys
 */
function media_renamer_target_derivative_conflicts(array $sourceImage, array $targetImage, array $gallery, array $currentFileKeys): bool
{
    foreach (thumbnail_sizes() as $size) {
        foreach (['jpg', 'webp'] as $format) {
            $targetPath = thumbnail_abs_path($targetImage, $gallery, (int) $size, $format);
            $targetKey = media_renamer_path_key($targetPath);
            if (file_exists($targetPath) && !isset($currentFileKeys[$targetKey])) {
                return true;
            }
        }
    }

    if (function_exists('image_uses_dng_display_derivatives') && image_uses_dng_display_derivatives($sourceImage)) {
        $targetPath = dng_display_master_abs_path($targetImage, $gallery, false);
        $targetKey = media_renamer_path_key($targetPath);
        if (file_exists($targetPath) && !isset($currentFileKeys[$targetKey])) {
            return true;
        }
    }

    return false;
}

/**
 * Return current original and derivative file keys for selected images.
 *
 * @param array<int,array<string,mixed>> $images
 * @return array<string,bool>
 */
function media_renamer_current_file_keys(array $images, array $gallery): array
{
    $keys = [];
    foreach ($images as $image) {
        try {
            $keys[media_renamer_path_key(image_abs_path($image, $gallery))] = true;
            foreach (thumbnail_sizes() as $size) {
                foreach (['jpg', 'webp'] as $format) {
                    $keys[media_renamer_path_key(thumbnail_abs_path($image, $gallery, (int) $size, $format))] = true;
                }
            }
            if (function_exists('image_uses_dng_display_derivatives') && image_uses_dng_display_derivatives($image)) {
                $keys[media_renamer_path_key(dng_display_master_abs_path($image, $gallery, false))] = true;
            }
        } catch (Throwable) {
        }
    }
    return $keys;
}

/**
 * Return a platform-aware comparable key for a filesystem path.
 */
function media_renamer_path_key(string $path): string
{
    $normalized = function_exists('gallery_normalize_filesystem_path') ? gallery_normalize_filesystem_path($path) : str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
}

/**
 * Build the normalized context prefix from the gallery folder hierarchy.
 */
function media_renamer_gallery_context_base(array $gallery): string
{
    $folderPath = normalize_relative_path((string) ($gallery['folder_path'] ?? ''));
    $base = media_renamer_ascii_slug(str_replace('/', '_', $folderPath));
    if ($base !== '') {
        return $base;
    }

    $title = media_renamer_ascii_slug((string) ($gallery['title'] ?? ''));
    return $title !== '' ? $title : 'gallery';
}

/**
 * Convert text into a lowercase ASCII underscore identifier usable in filenames.
 */
function media_renamer_ascii_slug(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') {
            $value = $converted;
        }
    }

    $value = strtolower($value);
    $value = str_replace(['/', '\\'], '_', $value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
    $value = trim($value, '_');
    $value = preg_replace('/_+/', '_', $value) ?? '';
    return $value;
}

/**
 * Preserve the source extension while keeping the final suffix filesystem-safe.
 */
function media_renamer_safe_extension(string $filename): string
{
    $extension = (string) pathinfo($filename, PATHINFO_EXTENSION);
    $extension = preg_replace('/[^A-Za-z0-9]/', '', $extension) ?? '';
    return $extension !== '' ? $extension : 'jpg';
}

/**
 * Return the default wildcard pattern used by the renamer UI.
 */
function media_renamer_default_pattern(): string
{
    return '{gallery_path}_{seq4}';
}

/**
 * Normalize a submitted wildcard pattern while preserving its placeholders.
 */
function media_renamer_normalize_pattern(string $pattern): string
{
    $pattern = trim($pattern);
    return $pattern !== '' ? $pattern : media_renamer_default_pattern();
}

/**
 * Render a short readable list of wildcard placeholders for the admin UI.
 */
function media_renamer_pattern_help_text(): string
{
    return '{gallery_path}, {gallery_title}, {photo_title}, {old_name}, {old_stem}, {image_id}, {seq}, {seq2}, {seq3}, {seq4}, {seq5}';
}

/**
 * Build the target filename stem from a wildcard pattern.
 */
function media_renamer_pattern_stem(string $pattern, string $contextBase, int $sequence, array $gallery, array $image): string
{
    $pattern = media_renamer_normalize_pattern($pattern);
    $oldFilename = (string) ($image['filename'] ?? basename((string) ($image['relative_path'] ?? '')));
    $oldStem = (string) pathinfo($oldFilename, PATHINFO_FILENAME);
    $photoTitle = trim((string) ($image['title'] ?? ''));
    $galleryTitle = trim((string) ($gallery['title'] ?? ''));
    $replacements = [
        '{gallery_path}' => $contextBase,
        '{gallery_title}' => media_renamer_ascii_slug($galleryTitle),
        '{photo_title}' => media_renamer_ascii_slug($photoTitle),
        '{old_name}' => media_renamer_ascii_slug($oldFilename),
        '{old_stem}' => media_renamer_ascii_slug($oldStem),
        '{image_id}' => (string) max(0, (int) ($image['id'] ?? 0)),
        '{seq}' => (string) max(1, $sequence),
        '{seq2}' => str_pad((string) max(1, $sequence), 2, '0', STR_PAD_LEFT),
        '{seq3}' => str_pad((string) max(1, $sequence), 3, '0', STR_PAD_LEFT),
        '{seq4}' => str_pad((string) max(1, $sequence), 4, '0', STR_PAD_LEFT),
        '{seq5}' => str_pad((string) max(1, $sequence), 5, '0', STR_PAD_LEFT),
    ];

    $stem = strtr($pattern, $replacements);
    $stem = media_renamer_ascii_slug($stem);
    return $stem !== '' ? $stem : $contextBase . '_' . str_pad((string) max(1, $sequence), 4, '0', STR_PAD_LEFT);
}

/**
 * Build a filename with context, order, optional collision suffix, and extension.
 */
function media_renamer_build_filename(string $contextBase, int $sequence, string $extension, int $suffix = 0, string $pattern = '', array $gallery = [], array $image = []): string
{
    $extensionPart = '.' . $extension;
    $suffixPart = $suffix > 0 ? '_' . ($suffix + 1) : '';
    $stem = media_renamer_pattern_stem($pattern, $contextBase, $sequence, $gallery, $image);
    $maxStemLength = max(16, 240 - strlen($suffixPart) - strlen($extensionPart));
    $stem = substr($stem, 0, $maxStemLength);
    $stem = trim($stem, '_');
    if ($stem === '') {
        $stem = 'gallery_' . str_pad((string) max(1, $sequence), 4, '0', STR_PAD_LEFT);
    }
    return $stem . $suffixPart . $extensionPart;
}

/**
 * Summarize plan item states for UI and final flash messages.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<string,int>
 */
function media_renamer_summarize_items(array $items): array
{
    $summary = [
        'total' => count($items),
        'rename' => 0,
        'already_matches' => 0,
        'missing' => 0,
        'collision' => 0,
        'skipped' => 0,
        'warnings' => 0,
    ];

    foreach ($items as $item) {
        $status = (string) ($item['status'] ?? 'skipped');
        if (!array_key_exists($status, $summary)) {
            $summary[$status] = 0;
        }
        $summary[$status]++;
        if (!empty($item['warnings'])) {
            $summary['warnings'] += count((array) $item['warnings']);
        }
    }

    return $summary;
}

/**
 * Execute a rename operation for one gallery from a freshly generated plan.
 *
 * @return array<string,mixed>
 */
function media_renamer_execute_gallery(int $galleryId, string $pattern = ''): array
{
    return media_renamer_execute_plan(media_renamer_plan_for_gallery($galleryId, $pattern));
}

/**
 * Execute rename operations for several galleries.
 *
 * @param array<int|string> $galleryIds
 * @return array<string,mixed>
 */
function media_renamer_execute_galleries(array $galleryIds, string $pattern = ''): array
{
    $result = [
        'galleries_requested' => count($galleryIds),
        'galleries_processed' => 0,
        'renamed' => 0,
        'already_matches' => 0,
        'missing' => 0,
        'skipped' => 0,
        'collisions' => 0,
        'derivatives_moved' => 0,
        'zip_archives_deleted' => 0,
        'titles_updated' => 0,
        'details' => [],
        'failures' => [],
    ];

    foreach (media_renamer_existing_gallery_ids($galleryIds) as $galleryId) {
        try {
            $galleryResult = media_renamer_execute_gallery($galleryId, $pattern);
            $result['galleries_processed']++;
            $result['renamed'] += (int) ($galleryResult['renamed'] ?? 0);
            $result['already_matches'] += (int) ($galleryResult['already_matches'] ?? 0);
            $result['missing'] += (int) ($galleryResult['missing'] ?? 0);
            $result['skipped'] += (int) ($galleryResult['skipped'] ?? 0);
            $result['collisions'] += (int) ($galleryResult['collisions'] ?? 0);
            $result['derivatives_moved'] += (int) ($galleryResult['derivatives_moved'] ?? 0);
            $result['zip_archives_deleted'] += (int) ($galleryResult['zip_archives_deleted'] ?? 0);
            $result['titles_updated'] += (int) ($galleryResult['titles_updated'] ?? 0);
            foreach ((array) ($galleryResult['details'] ?? []) as $detail) {
                $result['details'][] = $detail;
            }
            foreach ((array) ($galleryResult['failures'] ?? []) as $failure) {
                $result['failures'][] = (string) $failure;
            }
        } catch (Throwable $exception) {
            $gallery = find_gallery($galleryId, true);
            $label = $gallery ? (string) ($gallery['folder_path'] ?? ('#' . $galleryId)) : ('#' . $galleryId);
            $result['failures'][] = $label . ': ' . $exception->getMessage();
        }
    }

    return $result;
}

/**
 * Execute one precomputed plan with physical file renames and database updates.
 *
 * @return array<string,mixed>
 */
function media_renamer_execute_plan(array $plan): array
{
    $gallery = (array) ($plan['gallery'] ?? []);
    $galleryId = (int) ($gallery['id'] ?? 0);
    if ($galleryId <= 0) {
        throw new RuntimeException(t('admin.media_renamer.error_gallery_missing', 'Gallery was not found.'));
    }

    $items = array_values(array_filter((array) ($plan['items'] ?? []), static fn (array $item): bool => !empty($item['can_rename']) && (string) ($item['status'] ?? '') === 'rename'));
    $summary = (array) ($plan['summary'] ?? []);
    $result = [
        'gallery_id' => $galleryId,
        'renamed' => 0,
        'already_matches' => (int) ($summary['already_matches'] ?? 0),
        'missing' => (int) ($summary['missing'] ?? 0),
        'skipped' => (int) ($summary['skipped'] ?? 0),
        'collisions' => (int) ($summary['collision'] ?? 0),
        'derivatives_moved' => 0,
        'zip_archives_deleted' => 0,
        'titles_updated' => 0,
        'details' => media_renamer_execution_details_for_plan($plan, []),
        'failures' => [],
    ];

    if (!$items) {
        return $result;
    }

    $galleryRoot = gallery_abs_path((string) $gallery['folder_path']);
    $manifest = media_renamer_file_manifest($gallery, $galleryRoot, $items);
    $stagedFiles = [];
    $finalFiles = [];

    try {
        foreach ($manifest as $entry) {
            $stagingPath = media_renamer_staging_path((string) $entry['from']);
            if (!@rename((string) $entry['from'], $stagingPath)) {
                throw new RuntimeException(t('admin.media_renamer.error_stage_failed', 'Could not stage file for rename: {file}', ['file' => basename((string) $entry['from'])]));
            }
            $entry['staging'] = $stagingPath;
            $stagedFiles[] = $entry;
        }

        foreach ($stagedFiles as $entry) {
            $targetDir = dirname((string) $entry['to']);
            if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw new RuntimeException(t('admin.media_renamer.error_target_dir_failed', 'Could not create target folder for generated files.'));
            }
            if (!@rename((string) $entry['staging'], (string) $entry['to'])) {
                throw new RuntimeException(t('admin.media_renamer.error_final_failed', 'Could not finish file rename: {file}', ['file' => basename((string) $entry['to'])]));
            }
            $finalFiles[] = $entry;
        }

        $databaseResult = media_renamer_update_database_rows($items);
    } catch (Throwable $exception) {
        media_renamer_rollback_file_moves($finalFiles, $stagedFiles);
        throw $exception;
    }

    $result['renamed'] = count($items);
    $result['titles_updated'] = (int) ($databaseResult['titles_updated'] ?? 0);
    $result['details'] = media_renamer_execution_details_for_plan($plan, array_map(static fn (array $item): int => (int) ($item['image_id'] ?? 0), $items));
    $result['derivatives_moved'] = count(array_filter($manifest, static fn (array $entry): bool => (string) ($entry['kind'] ?? '') === 'derivative'));
    $result['zip_archives_deleted'] = media_renamer_clear_download_archives([$galleryId]);

    if (function_exists('thumbnail_maintenance_summary_cache_clear')) {
        thumbnail_maintenance_summary_cache_clear();
    }
    if (function_exists('regenerate_public_paths') && public_path_schema_ready()) {
        regenerate_public_paths();
    }
    $updatedGallery = find_gallery($galleryId, true);
    if ($updatedGallery && function_exists('write_gallery_sidecar')) {
        write_gallery_sidecar($updatedGallery);
    }

    return $result;
}

/**
 * Build human-readable execution details for every row in a plan.
 *
 * @param array<int,int> $renamedImageIds Image ids that were physically renamed.
 * @return array<int,array<string,mixed>>
 */
function media_renamer_execution_details_for_plan(array $plan, array $renamedImageIds): array
{
    $renamedMap = array_fill_keys(array_map('intval', $renamedImageIds), true);
    $details = [];
    $gallery = (array) ($plan['gallery'] ?? []);
    $galleryLabel = (string) ($gallery['folder_path'] ?? $gallery['title'] ?? '');

    foreach ((array) ($plan['items'] ?? []) as $item) {
        $imageId = (int) ($item['image_id'] ?? 0);
        $plannedStatus = (string) ($item['status'] ?? 'skipped');
        $status = isset($renamedMap[$imageId]) ? 'renamed' : $plannedStatus;
        $notes = (array) ($item['warnings'] ?? []);
        if ($status === 'renamed') {
            $notes[] = t('admin.media_renamer.detail_renamed', 'Renamed on disk and database row updated.');
        } elseif ($status === 'already_matches') {
            $notes[] = t('admin.media_renamer.detail_already_matches', 'No action needed because the file already has the generated name.');
        } elseif ($status === 'missing') {
            $notes[] = t('admin.media_renamer.detail_missing', 'Skipped because the source file was not found on disk.');
        } elseif ($status === 'collision') {
            $notes[] = t('admin.media_renamer.detail_collision', 'Skipped because no safe target name was available.');
        } elseif ($status === 'skipped') {
            $notes[] = t('admin.media_renamer.detail_skipped', 'Skipped by safety rules.');
        }
        $details[] = [
            'gallery_id' => (int) ($item['gallery_id'] ?? ($gallery['id'] ?? 0)),
            'gallery' => $galleryLabel,
            'image_id' => $imageId,
            'old' => (string) ($item['old_relative_path'] ?? ''),
            'new' => (string) ($item['new_relative_path'] ?? ''),
            'status' => $status,
            'notes' => array_values(array_unique(array_filter(array_map('strval', $notes), static fn (string $note): bool => trim($note) !== ''))),
        ];
    }

    return $details;
}

/**
 * Build physical file moves for originals and existing generated derivatives.
 *
 * @param array<int,array<string,mixed>> $items
 * @return array<int,array{from:string,to:string,kind:string,label:string}>
 */
function media_renamer_file_manifest(array $gallery, string $galleryRoot, array $items): array
{
    $manifest = [];
    $targetKeys = [];
    $sourceImages = array_map(static fn (array $item): array => (array) ($item['image'] ?? []), $items);
    $occupiedSourceKeys = media_renamer_current_file_keys($sourceImages, $gallery);

    foreach ($items as $item) {
        $image = (array) ($item['image'] ?? []);
        $targetImage = (array) ($item['target_image'] ?? []);
        $label = (string) ($item['old_relative_path'] ?? ('#' . (int) ($item['image_id'] ?? 0)));

        $sourceOriginal = image_abs_path($image, $gallery);
        $targetOriginal = image_abs_path($targetImage, $gallery);
        media_renamer_add_manifest_entry($manifest, $targetKeys, $occupiedSourceKeys, $galleryRoot, $sourceOriginal, $targetOriginal, 'original', $label, true);

        foreach (thumbnail_sizes() as $size) {
            foreach (['jpg', 'webp'] as $format) {
                $sourceThumbnail = thumbnail_abs_path($image, $gallery, (int) $size, $format);
                $targetThumbnail = thumbnail_abs_path($targetImage, $gallery, (int) $size, $format);
                media_renamer_add_manifest_entry($manifest, $targetKeys, $occupiedSourceKeys, $galleryRoot, $sourceThumbnail, $targetThumbnail, 'derivative', $label, false);
            }
        }

        if (function_exists('image_uses_dng_display_derivatives') && image_uses_dng_display_derivatives($image)) {
            $sourceDisplayMaster = dng_display_master_abs_path($image, $gallery, false);
            $targetDisplayMaster = dng_display_master_abs_path($targetImage, $gallery, false);
            media_renamer_add_manifest_entry($manifest, $targetKeys, $occupiedSourceKeys, $galleryRoot, $sourceDisplayMaster, $targetDisplayMaster, 'derivative', $label, false);
        }
    }

    return $manifest;
}

/**
 * Add one source-to-target file move to a manifest after safety checks.
 *
 * @param array<int,array{from:string,to:string,kind:string,label:string}> $manifest
 * @param array<string,bool> $targetKeys
 */
function media_renamer_add_manifest_entry(array &$manifest, array &$targetKeys, array $occupiedSourceKeys, string $galleryRoot, string $sourcePath, string $targetPath, string $kind, string $label, bool $required): void
{
    if (media_renamer_path_key($sourcePath) === media_renamer_path_key($targetPath)) {
        return;
    }
    if (!thumbnail_path_inside_existing_gallery($galleryRoot, $sourcePath) || !thumbnail_path_inside_existing_gallery($galleryRoot, $targetPath)) {
        throw new RuntimeException($label . ': ' . t('admin.media_renamer.error_manifest_outside_gallery', 'A planned file path is outside the gallery folder.'));
    }
    if (!is_file($sourcePath)) {
        if ($required) {
            throw new RuntimeException($label . ': ' . t('admin.media_renamer.error_source_missing', 'Source file disappeared before rename.'));
        }
        return;
    }

    $targetKey = media_renamer_path_key($targetPath);
    if (isset($targetKeys[$targetKey])) {
        throw new RuntimeException($label . ': ' . t('admin.media_renamer.error_duplicate_target', 'Two planned files target the same path.'));
    }
    if (file_exists($targetPath)) {
        $sourceKey = media_renamer_path_key($sourcePath);
        if ($sourceKey !== $targetKey && !isset($occupiedSourceKeys[$targetKey])) {
            throw new RuntimeException($label . ': ' . t('admin.media_renamer.error_target_exists', 'Target file already exists.'));
        }
    }

    $targetKeys[$targetKey] = true;
    $manifest[] = ['from' => $sourcePath, 'to' => $targetPath, 'kind' => $kind, 'label' => $label];
}

/**
 * Return a unique temporary path next to a source file.
 */
function media_renamer_staging_path(string $sourcePath): string
{
    $directory = dirname($sourcePath);
    $base = basename($sourcePath);
    for ($index = 0; $index < 100; $index++) {
        $candidate = $directory . DIRECTORY_SEPARATOR . '.media-renamer-' . hash('sha256', $sourcePath . '|' . microtime(true) . '|' . random_int(1, PHP_INT_MAX) . '|' . $index) . '-' . $base . '.tmp';
        if (!file_exists($candidate)) {
            return $candidate;
        }
    }
    throw new RuntimeException(t('admin.media_renamer.error_temp_failed', 'Could not allocate a temporary rename path.'));
}

/**
 * Restore files after a failed physical or database rename step.
 *
 * @param array<int,array<string,string>> $finalFiles
 * @param array<int,array<string,string>> $stagedFiles
 */
function media_renamer_rollback_file_moves(array $finalFiles, array $stagedFiles): void
{
    for ($index = count($finalFiles) - 1; $index >= 0; $index--) {
        $entry = $finalFiles[$index];
        if (is_file((string) ($entry['to'] ?? '')) && !is_file((string) ($entry['from'] ?? ''))) {
            @rename((string) $entry['to'], (string) $entry['from']);
        }
    }

    for ($index = count($stagedFiles) - 1; $index >= 0; $index--) {
        $entry = $stagedFiles[$index];
        if (is_file((string) ($entry['staging'] ?? '')) && !is_file((string) ($entry['from'] ?? ''))) {
            @rename((string) $entry['staging'], (string) $entry['from']);
        }
    }
}

/**
 * Update image rows after physical files have been moved into their final names.
 *
 * @param array<int,array<string,mixed>> $items
 */
function media_renamer_update_database_rows(array $items): array
{
    $pdo = db();
    $now = now_sql();
    $result = ['titles_updated' => 0];
    $pdo->beginTransaction();
    try {
        $tempStmt = $pdo->prepare('UPDATE images SET relative_path = ?, relative_path_hash = ?, filename = ?, updated_at = ? WHERE id = ?');
        foreach ($items as $item) {
            $imageId = (int) ($item['image_id'] ?? 0);
            $tempRelativePath = '__media_renamer_tmp_' . $imageId . '_' . substr(hash('sha256', (string) microtime(true) . random_int(1, PHP_INT_MAX)), 0, 16) . '.tmp';
            $tempStmt->execute([$tempRelativePath, hash('sha256', $tempRelativePath), $tempRelativePath, $now, $imageId]);
        }

        $finalStmt = $pdo->prepare('UPDATE images SET relative_path = ?, relative_path_hash = ?, filename = ?, title = ?, updated_at = ? WHERE id = ?');
        foreach ($items as $item) {
            $image = (array) ($item['image'] ?? []);
            $finalRelativePath = normalize_relative_path((string) ($item['new_relative_path'] ?? ''));
            $finalFilename = (string) ($item['new_filename'] ?? basename($finalRelativePath));
            $finalTitle = media_renamer_title_after_rename($image, $finalFilename);
            if ($finalTitle !== ($image['title'] ?? null)) {
                $result['titles_updated']++;
            }
            $finalStmt->execute([$finalRelativePath, hash('sha256', $finalRelativePath), $finalFilename, $finalTitle, $now, (int) ($item['image_id'] ?? 0)]);
        }

        $pdo->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Return the stored title after a rename.
 *
 * Titles created automatically from the old filename are moved to the new
 * filename stem. Manually authored titles are preserved.
 */
function media_renamer_title_after_rename(array $image, string $newFilename): ?string
{
    $title = trim((string) ($image['title'] ?? ''));
    if ($title === '') {
        return $image['title'] ?? null;
    }

    $oldFilename = trim((string) ($image['filename'] ?? ''));
    $oldRelativePath = normalize_relative_path((string) ($image['relative_path'] ?? ''));
    $oldStem = trim((string) pathinfo($oldFilename !== '' ? $oldFilename : basename($oldRelativePath), PATHINFO_FILENAME));
    $oldRelativeStem = trim((string) pathinfo(basename($oldRelativePath), PATHINFO_FILENAME));
    if ($title === $oldFilename || $title === $oldStem || $title === $oldRelativeStem) {
        return (string) pathinfo($newFilename, PATHINFO_FILENAME);
    }

    return $image['title'] ?? null;
}

/**
 * Remove stale generated ZIP archives after filenames change.
 *
 * @param array<int> $galleryIds
 */
function media_renamer_clear_download_archives(array $galleryIds): int
{
    if (!function_exists('zip_cache_dir')) {
        return 0;
    }

    $galleryIds = array_values(array_unique(array_filter(array_map('intval', $galleryIds), static fn (int $id): bool => $id > 0)));
    $params = [];
    $where = "scope = 'all'";
    if ($galleryIds) {
        $where .= ' OR gallery_id IN (' . implode(',', array_fill(0, count($galleryIds), '?')) . ')';
        $params = $galleryIds;
    }

    $stmt = db()->prepare('SELECT id, file_path FROM zip_archives WHERE ' . $where);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return 0;
    }

    $deleteIds = [];
    foreach ($rows as $row) {
        $filePath = (string) ($row['file_path'] ?? '');
        if ($filePath !== '' && is_file($filePath) && path_inside(zip_cache_dir(), $filePath)) {
            @unlink($filePath);
        }
        $deleteIds[] = (int) ($row['id'] ?? 0);
    }

    $deleteIds = array_values(array_filter($deleteIds, static fn (int $id): bool => $id > 0));
    if (!$deleteIds) {
        return 0;
    }

    $delete = db()->prepare('DELETE FROM zip_archives WHERE id IN (' . implode(',', array_fill(0, count($deleteIds), '?')) . ')');
    $delete->execute($deleteIds);
    return (int) $delete->rowCount();
}
