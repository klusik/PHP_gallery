<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_consistency/inspection.php
 * Module Type: Service Part
 * Purpose: Inspect catalog, originals, derivatives, sidecars and physical folders.
 * Responsibilities: Report evidence without creating, deleting or renaming media.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Notes: Loaded only by gallery_consistency.php; report shapes belong there.
 */
declare(strict_types=1);

namespace Gallery\Services;

use GdImage;
use DirectoryIterator;
use Throwable;
use function Gallery\Core\is_supported_image_path;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Models\gallery_consistency_model_variants;

/**
 * Compare catalog names/parents and expose the exact disk-authoritative proposal.
 * @param ConsistencyJob $job Catalog path map and grouped findings.
 * @param array{id:int|string,title:string,folder_path:string,
 *     parent_id:int|string|null,...} $gallery Current catalog identity.
 * @return void Adds catalog findings and a concrete before/after gallery plan;
 *     this function performs no filesystem reads or writes.
 */
function gallery_consistency_check_catalog_gallery(
    array &$job,
    array $gallery,
): void {
    $id = (int) $gallery['id'];
    $path = (string) $gallery['folder_path'];
    $title = (string) $gallery['title'];
    $leaf = gallery_folder_name_from_path($path);
    if (gallery_folder_segment($title) !== $leaf) {
        gallery_consistency_add($job, $id, $path, 'name_mismatch', '', 0, 'gallery');
    }
    $parentPath = normalize_relative_path(dirname($path));
    $parentPath = $parentPath === '.' ? '' : $parentPath;
    $parentId = $job['paths'][$parentPath] ?? 0;
    if ($parentPath !== '' && $parentId === 0) {
        gallery_consistency_add($job, $id, $path, 'parent_missing', '', 0, 'gallery');
    }
    if ((int) ($gallery['parent_id'] ?? 0) !== $parentId) {
        gallery_consistency_add($job, $id, $path, 'parent_mismatch', '', 0, 'gallery');
    }
    // The same pure title policy is consumed by the actual guarded repair.
    $proposedTitle = gallery_disk_source_title($path, $title);
    $segments = explode('/', $path);
    $prefix = [];
    $proposed = [];
    foreach ($segments as $index => $segment) {
        $prefix[] = $segment;
        // Existing unselected parents retain their names. Only the selected
        // leaf and ancestors newly registered by its repair are normalized.
        $proposed[] = $index === array_key_last($segments)
            || !isset($job['paths'][implode('/', $prefix)])
                ? gallery_folder_segment($segment) : $segment;
    }
    $proposedPath = implode('/', $proposed);
    $changes = [];
    if ($title !== $proposedTitle) {
        $changes[] = ['field' => 'title_value',
            'current' => $title, 'proposed' => $proposedTitle];
    }
    if ($path !== $proposedPath) {
        $changes[] = ['field' => 'leaf_value',
            'current' => $leaf, 'proposed' => gallery_folder_segment($proposedTitle)];
        $changes[] = ['field' => 'folder_value',
            'current' => $path, 'proposed' => $proposedPath];
    }
    if ((int) ($gallery['parent_id'] ?? 0) !== $parentId
        || ($parentPath !== '' && $parentId === 0)
    ) {
        $currentParent = '';
        foreach ($job['galleries'] as $candidate) {
            if ((int) $candidate['id'] === (int) ($gallery['parent_id'] ?? 0)) {
                $currentParent = (string) $candidate['folder_path'];
                break;
            }
        }
        $changes[] = ['field' => 'parent_value', 'current' => $currentParent,
            'proposed' => dirname($proposedPath) === '.'
                ? '' : dirname($proposedPath)];
    }
    if (isset($job['groups']['g' . $id])) {
        $job['groups']['g' . $id]['changes'] = $changes;
    }
}

/**
 * Prepare selectable ancestor spelling changes for browser-only path previews.
 * @param ConsistencyJob $job Captured report and repairable gallery findings.
 * @return list<array{key:string,path:string,leaf:string}> Exact per-gallery
 *     spelling proposals; browser selection never authorizes a server target.
 */
function gallery_consistency_proposed_moves(array $job): array
{
    $moves = [];
    foreach ($job['groups'] as $key => $group) {
        foreach ($group['issues'] as $issue) {
            if ($issue['repair'] !== 'gallery') continue;
            $moves[] = ['key' => $key, 'path' => $group['path'],
                'leaf' => gallery_folder_segment(gallery_disk_source_title(
                    $group['path'], $group['title'],
                ))];
            break;
        }
    }
    return $moves;
}

/**
 * Adopt completed repair lists and add missing proposal presentation.
 * @param ConsistencyJob $job Captured catalog/evidence; never replaced by live
 *     filesystem observations when merely presenting an older report.
 * @return ConsistencyJob Presentation copy. Successful completed repairs remove
 *     only their confirmed findings and refresh catalog proposals; an older
 *     untouched report retains its captured findings without a filesystem scan.
 */
function gallery_consistency_presentation(array $job): array
{
    gallery_consistency_reconcile_repairs($job);
    if ($job['status'] !== 'complete') return $job;
    $galleries = [];
    foreach ($job['galleries'] as $gallery) {
        $galleries[(int) $gallery['id']] = $gallery;
    }
    foreach ($job['groups'] as $key => $group) {
        if (isset($group['changes']) || !isset($galleries[$group['id']])) continue;
        $galleryIssues = array_filter($group['issues'],
            static fn(array $issue): bool => $issue['repair'] === 'gallery');
        if ($galleryIssues === []) continue;
        try {
            // Reuse the actual catalog policy without adding duplicate findings
            // to the presented report or inspecting the current filesystem.
            $probe = $job;
            gallery_consistency_check_catalog_gallery($probe, $galleries[$group['id']]);
            $job['groups'][$key]['changes'] = $probe['groups'][$key]['changes'] ?? [];
            foreach ($job['groups'][$key]['issues'] as &$issue) {
                if ($issue['code'] === 'name_mismatch') $issue['details'] = '';
            }
            unset($issue);
        } catch (Throwable) {
            // Unsafe snapshot paths keep their original finding and no invented
            // destination; repair admission still revalidates the actual path.
        }
    }
    return $job;
}

/**
 * Inspect a physical gallery and compare catalog/sidecar identity with disk.
 * @param ConsistencyJob $job Current report accumulator.
 * @param array{id:int|string,title:string,folder_path:string,
 *     parent_id:int|string|null,...} $gallery Persisted gallery identity.
 * @return void Records missing/unreadable directories and identity discrepancies.
 */
function gallery_consistency_check_gallery(array &$job, array $gallery): void
{
    $id = (int) $gallery['id'];
    $path = (string) $gallery['folder_path'];
    try {
        $absolute = gallery_abs_path($path);
        if (is_link($absolute)) {
            gallery_consistency_add($job, $id, $path, 'symlink');
            return;
        }
        if (!is_dir($absolute)) {
            gallery_consistency_add($job, $id, $path, 'directory_missing');
            return;
        }
        if (!is_readable($absolute)) {
            gallery_consistency_add($job, $id, $path, 'directory_unreadable');
        }
        gallery_consistency_check_catalog_gallery($job, $gallery);
        $sidecarPath = $absolute . DIRECTORY_SEPARATOR . 'gallery.json';
        $metadata = is_file($sidecarPath)
            ? json_decode((string) file_get_contents($sidecarPath), true)
            : null;
        if (!is_array($metadata)) {
            gallery_consistency_add(
                $job, $id, $path, 'sidecar_invalid', 'gallery.json',
                0, 'gallery',
            );
        } elseif ((string) ($metadata['title'] ?? '') !== $gallery['title']) {
            gallery_consistency_add(
                $job, $id, $path, 'sidecar_mismatch', 'gallery.json',
                0, 'gallery',
            );
        }
        if (isset($job['groups']['g' . $id]) && (!is_array($metadata)
            || ($metadata['title'] ?? '') !== $gallery['title']
        )) {
            $job['groups']['g' . $id]['changes'][] = [
                'field' => 'sidecar_value',
                'current' => is_array($metadata)
                    ? (string) ($metadata['title'] ?? '') : '',
                'proposed' => gallery_disk_source_title($path, (string) $gallery['title']),
            ];
        }
    } catch (Throwable) {
        gallery_consistency_add($job, $id, $path, 'unsafe_path');
    }
}

/**
 * Decode a safe raster into a small orientation-aware comparison surface.
 * @param string $path Authorized source or derivative path.
 * @return list<int>|null RGB samples, or null for unavailable/refused decoding.
 */
function gallery_consistency_signature(string $path): ?array
{
    if (!extension_loaded('gd')) {
        return null;
    }
    $info = @getimagesize($path);
    if (!is_array($info) || empty($info['mime'])) {
        return null;
    }
    $side = GALLERY_CONSISTENCY_SIGNATURE_SIDE;
    $image = image_create_from_path($path, (string) $info['mime'], $side);
    if (!$image instanceof GdImage) {
        return null;
    }
    $image = thumbnail_apply_gd_exif_orientation(
        $path, $image, (string) $info['mime'],
    );
    $sample = imagecreatetruecolor($side, $side);
    if (!$sample instanceof GdImage) {
        return null;
    }
    // Match the generator's white backing for transparent source pixels.
    imagefill($sample, 0, 0, imagecolorallocate($sample, 255, 255, 255));
    imagecopyresampled(
        $sample, $image, 0, 0, 0, 0, $side, $side,
        imagesx($image), imagesy($image),
    );
    $pixels = [];
    for ($y = 0; $y < $side; $y++) {
        for ($x = 0; $x < $side; $x++) {
            $color = imagecolorat($sample, $x, $y);
            // RGB is a three-channel byte representation, not a policy bound.
            $pixels[] = ($color >> 16) & 255;
            $pixels[] = ($color >> 8) & 255;
            $pixels[] = $color & 255;
        }
    }
    return $pixels;
}

/**
 * Compare small RGB surfaces without claiming cryptographic content provenance.
 * @param list<int> $source Oriented source RGB samples.
 * @param list<int> $target Oriented derivative RGB samples.
 * @return float Mean channel distance normalized to the byte range, or 1 for
 *     incompatible signatures.
 */
function gallery_consistency_signature_distance(
    array $source,
    array $target,
): float {
    if ($source === [] || count($source) !== count($target)) {
        return 1.0;
    }
    $distance = 0;
    foreach ($source as $index => $value) {
        $distance += abs($value - $target[$index]);
    }
    return $distance / (count($source) * 255);
}

/**
 * Inspect one original and every expected derivative without changing either.
 * @param ConsistencyJob $job Report and exact expected disk ownership map.
 * @param array{id:int|string,gallery_id:int|string,filename:string,
 *     relative_path:string,checksum_sha256?:string|null,
 *     file_size?:int|string,...} $image Persisted source identity.
 * @return void Records source loss, stale/invalid caches, metadata and visual
 *     uncertainty; unexpected failures are findings rather than clean results.
 */
function gallery_consistency_check_image(array &$job, array $image): void
{
    $id = (int) $image['id'];
    $galleryId = (int) $image['gallery_id'];
    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        gallery_consistency_add(
            $job, $galleryId, '', 'image_parent_missing',
            (string) $image['relative_path'], $id,
        );
        return;
    }
    $path = (string) $gallery['folder_path'];
    $relative = (string) ($image['relative_path'] ?: $image['filename']);
    $firstFinding = count($job['groups']['g' . $galleryId]['issues'] ?? []);
    try {
        $source = image_abs_path($image, $gallery);
        $diskPath = normalize_relative_path($path . '/' . $relative);
        $diskKey = gallery_consistency_path_key($job, $diskPath);
        if (isset($job['expected'][$diskKey])
            && !in_array($job['expected'][$diskKey], [$id, 'i' . $id], true)
        ) {
            gallery_consistency_add(
                $job, $galleryId, $path, 'source_collision', $relative, $id,
            );
        }
        $job['expected'][$diskKey] = isset($job['scope']) ? $id : 'i' . $id;
        if (is_link($source)) {
            gallery_consistency_add($job, $galleryId, $path, 'symlink', $relative, $id);
            return;
        }
        if (!is_file($source)) {
            gallery_consistency_add(
                $job, $galleryId, $path, 'original_missing', $relative, $id,
            );
            return;
        }
        $deep = ($job['scope'] ?? 'content') === 'content';
        $hash = $deep ? hash_file('sha256', $source) : '';
        if (!is_readable($source) || $hash === false) {
            gallery_consistency_add(
                $job, $galleryId, $path, 'original_unreadable', $relative, $id,
            );
            return;
        }
        $storedHash = (string) ($image['checksum_sha256'] ?? '');
        if (
            ($deep && $storedHash !== '' && !hash_equals($storedHash, $hash))
            || (int) ($image['file_size'] ?? 0) !== filesize($source)
        ) {
            gallery_consistency_add(
                $job, $galleryId, $path, 'source_changed', $relative,
                $id, 'source',
            );
        }
        thumbnail_assert_source_identity_owned($image);
        $sourceSignature = $deep ? gallery_consistency_signature($source) : null;
        if ($deep && $sourceSignature === null) {
            gallery_consistency_add(
                $job, $galleryId, $path, 'content_unchecked', $relative, $id,
            );
        }
        $geometry = thumbnail_source_geometry_dimensions($source, $image);
        $mime = image_source_mime_for_derivatives($source, $image);
        $formats = thumbnail_target_formats_for_source($source, $mime);
        $metadataReady = schema_inspection_is_available(
            thumbnail_metadata_mutation_schema_status(),
        );
        $variants = $metadataReady
            ? gallery_consistency_model_variants($id) : [];
        $indexed = [];
        foreach ($variants as $variant) {
            $variantKey = (int) $variant['size_px'] . ':' . $variant['format'];
            if (isset($indexed[$variantKey])) {
                gallery_consistency_add(
                    $job, $galleryId, $path, 'metadata_duplicate',
                    $relative, $id,
                );
            }
            $indexed[$variantKey] = $variant;
            if (
                !in_array((int) $variant['size_px'], thumbnail_sizes(), true)
                || !in_array((string) $variant['format'], $formats, true)
            ) {
                gallery_consistency_add(
                    $job, $galleryId, $path, 'metadata_extra',
                    $relative, $id, '', $variantKey,
                );
            }
        }
        if (!$metadataReady) {
            gallery_consistency_add(
                $job, $galleryId, $path, 'metadata_unavailable', $relative, $id,
            );
        }
        foreach (thumbnail_sizes() as $size) {
            // Retain known legacy formats in the ownership map even in Modern
            // mode. An unused generated cache is evidence, never deletion proof.
            foreach (['jpg', 'webp'] as $knownFormat) {
                $job['expected'][gallery_consistency_path_key($job,
                    $path . '/thumbs/' . thumbnail_filename(
                        $image, $size, $knownFormat,
                    ),
                )] = -$galleryId;
            }
            foreach ($formats as $format) {
                $target = thumbnail_abs_path($image, $gallery, $size, $format);
                $name = basename($target);
                $variantKey = $size . ':' . $format;
                if (!is_file($target)) {
                    gallery_consistency_add(
                        $job, $galleryId, $path, 'thumbnail_missing',
                        $name, $id, 'image', $variantKey,
                    );
                    continue;
                }
                $valid = is_array($geometry)
                    && !empty(thumbnail_file_geometry_status(
                        $target, (int) $geometry['width'],
                        (int) $geometry['height'], $size,
                    )['valid']);
                if (!$valid || filemtime($target) < filemtime($source)) {
                    gallery_consistency_add(
                        $job, $galleryId, $path,
                        $valid ? 'thumbnail_stale' : 'thumbnail_invalid',
                        $name, $id, 'image', $variantKey,
                    );
                    continue;
                }
                $variant = $indexed[$variantKey] ?? null;
                $actual = @getimagesize($target);
                if ($metadataReady && (
                    $variant === null
                    || ($variant['status'] ?? '') !== 'valid'
                    || (int) ($variant['file_size'] ?? 0) !== filesize($target)
                    || (int) ($variant['width'] ?? 0) !== (int) ($actual[0] ?? 0)
                    || (int) ($variant['height'] ?? 0) !== (int) ($actual[1] ?? 0)
                    || !thumbnail_metadata_row_matches_image_source($variant, $image)
                )) {
                    gallery_consistency_add(
                        $job, $galleryId, $path, 'metadata_mismatch',
                        $name, $id, 'metadata', $variantKey,
                    );
                }
                if ($sourceSignature !== null) {
                    $targetSignature = gallery_consistency_signature($target);
                    if ($targetSignature === null) {
                        gallery_consistency_add(
                            $job, $galleryId, $path, 'thumbnail_decode_failed',
                            $name, $id, 'image', $variantKey,
                        );
                    } elseif (gallery_consistency_signature_distance(
                        $sourceSignature, $targetSignature,
                    ) > GALLERY_CONSISTENCY_CONTENT_DISTANCE) {
                        gallery_consistency_add(
                            $job, $galleryId, $path, 'content_suspect',
                            $name, $id, '', $variantKey,
                        );
                    }
                }
            }
        }
        if (image_uses_dng_display_derivatives($image)) {
            $master = dng_display_master_abs_path($image, $gallery, false);
            $job['expected'][gallery_consistency_path_key(
                $job, $path . '/thumbs/' . basename($master),
            )] = -$galleryId;
            if (!is_file($master) || filemtime($master) < filemtime($source)) {
                gallery_consistency_add(
                    $job, $galleryId, $path, 'display_master_missing',
                    basename($master), $id, 'image',
                );
            }
        }
        // Capture byte leases only for sources with admitted repair tasks.
        // Routine file/header checks do not read every original twice.
        $needsHash = false;
        $findingCount = count($job['groups']['g' . $galleryId]['issues'] ?? []);
        for ($index = $firstFinding; $index < $findingCount; $index++) {
            $finding = $job['groups']['g' . $galleryId]['issues'][$index];
            if ($finding['image_id'] === $id && $finding['repair'] !== '') {
                $needsHash = true;
                break;
            }
        }
        if ($needsHash) {
            $hash = $hash !== '' ? $hash : hash_file('sha256', $source);
            if ($hash === false) throw new \RuntimeException('source_unreadable');
            $job['source_hashes'][$id] = $hash;
        }
        clearstatcache(true, $source);
        if ($deep && !hash_equals($hash, (string) hash_file('sha256', $source))) {
            unset($job['source_hashes'][$id]);
            gallery_consistency_add(
                $job, $galleryId, $path, 'source_changed_during_check', $relative, $id,
            );
        }
    } catch (Throwable) {
        gallery_consistency_add(
            $job, $galleryId, $path, 'identity_unverified', $relative, $id,
        );
    }
}

/**
 * Walk a bounded slice of the real tree without following symbolic links.
 * @param ConsistencyJob $job Persistent directory cursors and expected files.
 * @return void Counts observed entries; an unknown tree size stays indeterminate.
 */
function gallery_consistency_disk_step(array &$job): void
{
    $remaining = GALLERY_CONSISTENCY_DISK_BATCH;
    $deadline = microtime(true) + GALLERY_CONSISTENCY_STEP_SECONDS;
    while ($remaining > 0 && $job['stack'] !== []) {
        $index = array_key_last($job['stack']);
        $cursor = $job['stack'][$index];
        try {
            $iterator = new DirectoryIterator(galleries_root()
                . ($cursor['path'] === '' ? '' : '/' . $cursor['path']));
            $iterator->seek($cursor['offset']);
            while ($iterator->valid() && $remaining > 0) {
                $entry = clone $iterator->current();
                $job['stack'][$index]['offset']++;
                $iterator->next();
                $remaining--;
                if ($entry->isDot()) {
                    continue;
                }
                if (microtime(true) >= $deadline) {
                    // The consumed entry must still be inspected below.
                    $remaining = 0;
                }
                $job['disk_checked']++;
                $relative = ltrim($cursor['path'] . '/'
                    . $entry->getFilename(), '/');
                if ($entry->isLink()) {
                    gallery_consistency_add(
                        $job, 0, $cursor['path'], 'symlink', $relative,
                    );
                    continue;
                }
                if ($entry->isDir()) {
                    // Hidden/internal payloads are owned by their existing
                    // workflows; thumbs is traversed for unowned cache files.
                    if (str_starts_with($entry->getFilename(), '.')
                        || in_array(strtolower($entry->getFilename()), [
                            'cache', '_php-gallery-internal',
                        ], true)
                    ) {
                        continue;
                    }
                    $job['stack'][] = ['path' => $relative, 'offset' => 0];
                } elseif ($entry->isFile() && is_supported_image_path($relative)) {
                    gallery_consistency_check_disk_file($job, $relative);
                }
            }
            if (!$iterator->valid()) {
                array_splice($job['stack'], $index, 1);
            }
        } catch (Throwable) {
            gallery_consistency_add(
                $job, (int) ($job['paths'][$cursor['path']] ?? 0),
                $cursor['path'], 'directory_unreadable',
            );
            array_splice($job['stack'], $index, 1);
        }
    }
}

/**
 * Report a supported disk image that has no known catalog/derivative owner.
 * @param ConsistencyJob $job Expected-file and physical-gallery maps.
 * @param string $relative Safe root-relative path discovered by the disk walker.
 * @return void Leaves attachments, originals and ambiguous caches untouched.
 */
function gallery_consistency_check_disk_file(array &$job, string $relative): void
{
    if (isset($job['expected'][gallery_consistency_path_key($job, $relative)])) {
        return;
    }
    $folder = normalize_relative_path(dirname($relative));
    if (basename($folder) === 'thumbs') {
        $galleryPath = normalize_relative_path(dirname($folder));
        gallery_consistency_add(
            $job, (int) ($job['paths'][$galleryPath] ?? 0), $galleryPath,
            'derivative_unowned', basename($relative),
        );
        return;
    }
    // Branded/cover/attachment images referenced by the existing catalog are
    // not originals to import. Their owning service manages those paths.
    foreach ($job['galleries'] as $gallery) {
        foreach (['cover_image_path', 'logo_image_path', 'separator_image_path',
            'background_image_path'] as $field) {
            if (($gallery[$field] ?? '') === $relative
                || $gallery['folder_path'] . '/' . ($gallery[$field] ?? '') === $relative
            ) {
                return;
            }
        }
    }
    $id = (int) ($job['paths'][$folder] ?? 0);
    $hash = hash_file('sha256', galleries_root() . '/' . $relative);
    if ($hash === false) {
        gallery_consistency_add(
            $job, $id, $folder, 'original_unreadable', basename($relative),
        );
        return;
    }
    $job['disk_hashes'][$relative] = $hash;
    gallery_consistency_add(
        $job, $id, $folder,
        $id > 0 ? 'original_unindexed' : 'directory_unindexed',
        basename($relative), 0, $id > 0 ? 'scan' : 'import',
    );
}
