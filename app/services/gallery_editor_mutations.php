<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_editor_mutations.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides gallery editor persistence use-cases above the gallery model layer.
 *
 * Responsibilities:
 *   - Generate unique gallery slugs without exposing database handles to controllers
 *   - Persist semantic gallery field maps through the gallery model
 *   - Persist title-picture changes and refresh sidecar state
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
 *   - Request parsing and response rendering remain controller responsibilities.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;

require_once __DIR__ . '/gallery_edit_concurrency.php';

use function Gallery\Core\now_sql;
use function Gallery\Core\slugify;
use function Gallery\Models\gallery_model_clear_background_sources;
use function Gallery\Models\gallery_model_slug_exists;
use function Gallery\Models\gallery_model_update_fields;

/**
 * Generate a unique gallery slug while excluding the gallery currently edited.
 *
 * @param string $value User supplied slug or title fallback.
 * @param int $excludeGalleryId Existing gallery identifier excluded from collisions.
 * @param ?callable(string):bool $collision Optional model-read adapter for a legacy caller-owned connection.
 * @return string Unique normalized gallery slug.
 */
function gallery_editor_unique_slug(
    string $value,
    int $excludeGalleryId = 0,
    ?callable $collision = null,
): string {
    $base = slugify($value);
    $candidate = $base;
    $counter = 2;
    while (
        $collision !== null
            ? $collision($candidate)
            : gallery_model_slug_exists($candidate, $excludeGalleryId)
    ) {
        $candidate = $base . '-' . $counter;
        $counter++;
    }
    return $candidate;
}

/**
 * Persist semantic gallery editor fields and update the row timestamp.
 *
 * @param int $galleryId Gallery identifier.
 * @param array<string,mixed> $fields Editable gallery column-value map.
 * @return void Persist the guarded field update and release its writer lease.
 */
function gallery_editor_update_fields(int $galleryId, array $fields): void
{
    $writerLock = gallery_edit_writer_begin();
    try {
        if (array_key_exists('visibility', $fields)) {
            $gallery = find_gallery($galleryId, true);
            if (is_array($gallery)) {
                require_once __DIR__ . '/gallery_pdf_publication.php';
                gallery_pdf_publication_assert_visibility_change(
                    $gallery,
                    (string) $fields['visibility'],
                );
            }
        }
        gallery_model_update_fields($galleryId, $fields, now_sql());
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Persist a gallery title picture and return the refreshed gallery row.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $coverImageId Image identifier used as title picture.
 * @param array{id:int|string,folder_path?:string,title?:string,description?:string,cover_image_id?:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null} $fallbackGallery Legacy caller snapshot retained for signature compatibility; it never chooses a filesystem path.
 * @return array{id:int|string,folder_path:string,title:string,description:string,cover_image_id:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null,edit_revision?:int|string|null} Fresh persisted gallery identity and cover metadata after the sidecar write.
 * @throws GalleryEditConflict When the gallery/photo is missing or the photo is no longer a direct image owned by that gallery.
 */
function gallery_editor_set_cover_image(
    int $galleryId,
    int $coverImageId,
    array $fallbackGallery,
): array {
    $writerLock = gallery_edit_writer_begin();
    try {
        return gallery_editor_set_cover_image_owned(
            $galleryId,
            $coverImageId,
            $fallbackGallery,
        );
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Persist a gallery cover and publish its refreshed sidecar.
 *
 * Internal implementation: enter through gallery_editor_set_cover_image() so
 * reads, early returns and failure cleanup remain inside the same writer lease.
 *
 * @param int $galleryId Gallery identifier.
 * @param int $coverImageId Image identifier selected as title picture.
 * @param array{id:int|string,folder_path?:string,title?:string,description?:string,cover_image_id?:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null} $fallbackGallery Legacy gallery snapshot retained for signature compatibility; it does not choose a filesystem path.
 * @return array{id:int|string,folder_path:string,title:string,description:string,cover_image_id:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null,edit_revision?:int|string|null} Fresh persisted gallery identity and cover metadata; missing rows or changed ownership raise a conflict.
 * @author Rudolf Klusal
 */
function gallery_editor_set_cover_image_owned(
    int $galleryId,
    int $coverImageId,
    array $fallbackGallery,
): array {
    $gallery = find_gallery($galleryId, true);
    $image = find_image($coverImageId, true);
    if (
        !$gallery
        || !$image
        || (int) ($image['gallery_id'] ?? 0) !== $galleryId
        || str_contains((string) ($image['relative_path'] ?? ''), '/')
    ) {
        throw new GalleryEditConflict(gallery_edit_comparison($gallery ?? []));
    }
    $fields = ['cover_image_id' => $coverImageId];
    if (array_key_exists('cover_image_ids', $gallery)) {
        gallery_editor_assert_cover_selection_schema();
        $fields['cover_image_ids'] = null;
    }
    if (array_key_exists('cover_image_path', $gallery)) {
        $fields['cover_image_path'] = null;
    }
    gallery_model_update_fields($galleryId, $fields, now_sql());
    $updated = find_gallery($galleryId, true);
    if (!$updated) {
        throw new GalleryEditConflict([]);
    }
    if (!write_gallery_sidecar($updated)) {
        throw new \RuntimeException(
            'The title pictures were saved, but their sidecar metadata could not be written.',
        );
    }
    return $updated;
}

/**
 * Persist the bulk toolbar's explicit, ordered title-picture selection.
 *
 * Reuses the exclusive single-cover writer for one image. Multi-image selections
 * use the same writer lock, gallery row and sidecar refresh as individual stars.
 * The gallery cover renderer retains its existing maximum of four collage slots.
 *
 * @param int $galleryId Owning physical gallery identifier.
 * @param list<int> $coverImageIds Photo identifiers in administrator selection order.
 * @param array{id:int|string,folder_path?:string,title?:string,description?:string,cover_image_id?:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null} $fallbackGallery Compatibility gallery snapshot passed to the existing single-cover writer.
 * @param string|null $expectedRevision Optional cover fingerprint captured by the action source.
 * @return array{id:int|string,folder_path:string,title:string,description:string,cover_image_id:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null,edit_revision?:int|string|null} Fresh persisted gallery identity and cover metadata after the selection update.
 * @throws \DomainException When the selection is empty, exceeds four, or has invalid identifiers.
 * @throws GalleryEditConflict When an image is missing, moved, nested, or owned by another gallery.
 * @throws GalleryEditUnavailable When multi-cover persistence has not been migrated.
 */
function gallery_editor_set_cover_images(
    int $galleryId,
    array $coverImageIds,
    array $fallbackGallery,
    ?string $expectedRevision = null,
): array {
    $writerLock = gallery_edit_writer_begin();
    try {
        return gallery_editor_set_cover_images_owned(
            $galleryId,
            $coverImageIds,
            $fallbackGallery,
            $expectedRevision,
        );
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Persist an ordered cover selection while the caller holds the writer lease.
 *
 * Internal implementation called by gallery_editor_set_cover_images().
 *
 * @param int $galleryId Owning physical gallery identifier.
 * @param list<int> $coverImageIds Photo identifiers in administrator selection order.
 * @param array{id:int|string,folder_path?:string,title?:string,description?:string,cover_image_id?:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null} $fallbackGallery Compatibility gallery snapshot passed to the existing single-cover writer.
 * @param string|null $expectedRevision Optional cover fingerprint captured by the action source.
 * @return array{id:int|string,folder_path:string,title:string,description:string,cover_image_id:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null,edit_revision?:int|string|null} Fresh persisted gallery identity and cover metadata after the selection update.
 */
function gallery_editor_set_cover_images_owned(
    int $galleryId,
    array $coverImageIds,
    array $fallbackGallery,
    ?string $expectedRevision = null,
): array {
    if (!array_is_list($coverImageIds)) {
        throw new \DomainException(t(
            'admin.gallery_editor.selected_photo_unavailable',
            'The selected photo is unavailable.',
        ));
    }
    $ids = [];
    foreach ($coverImageIds as $imageId) {
        if (
            !is_int($imageId)
            || $imageId <= 0
            || in_array($imageId, $ids, true)
        ) {
            throw new \DomainException(t(
                'admin.gallery_editor.selected_photo_unavailable',
                'The selected photo is unavailable.',
            ));
        }
        $ids[] = $imageId;
    }
    if ($ids === [] || count($ids) > 4) {
        throw new \DomainException(t(
            'admin.gallery_editor.cover_selection_limit',
            'Choose between one and four title-picture photos.',
        ));
    }
    if (count($ids) === 1) {
        $gallery = find_gallery($galleryId, true);
        if ($expectedRevision !== null) {
            gallery_editor_assert_cover_revision($gallery, $expectedRevision);
        }
        return gallery_editor_set_cover_image_owned(
            $galleryId,
            $ids[0],
            $fallbackGallery,
        );
    }

    $gallery = find_gallery($galleryId, true);
    if (!$gallery) {
        throw new GalleryEditConflict([]);
    }
    gallery_editor_assert_cover_revision($gallery, $expectedRevision);
    gallery_editor_assert_cover_selection_schema();
    foreach ($ids as $imageId) {
        $image = find_image($imageId, true);
        if (
            !$image
            || (int) ($image['gallery_id'] ?? 0) !== $galleryId
            || str_contains((string) ($image['relative_path'] ?? ''), '/')
        ) {
            throw new GalleryEditConflict(gallery_edit_comparison($gallery));
        }
    }

    $fields = [
        'cover_image_id' => $ids[0],
        'cover_image_ids' => json_encode($ids, JSON_THROW_ON_ERROR),
    ];
    if (array_key_exists('cover_image_path', $gallery)) {
        $fields['cover_image_path'] = null;
    }
    gallery_model_update_fields($galleryId, $fields, now_sql());
    $updated = find_gallery($galleryId, true);
    if (!$updated) {
        throw new GalleryEditConflict([]);
    }
    if (!write_gallery_sidecar($updated)) {
        throw new \RuntimeException(
            'The title pictures were saved, but their sidecar metadata could not be written.',
        );
    }
    return $updated;
}

/**
 * Transition the title-picture star between accumulation and single mode.
 *
 * Callers pass a single owned photo and an explicit user intent. The writer lease
 * serializes concurrent clicks; stale mode/selection transitions fail closed.
 * This maintains cover_image_id as the compatible primary cover reference.
 *
 * @param int $galleryId Owning gallery.
 * @param int $imageId Clicked direct-photo ID.
 * @param 'arm'|'add'|'reset' $intent State transition requested by one star click.
 * @param string|null $expectedRevision Optional cover fingerprint captured by the clicked star.
 * @return array{id:int|string,folder_path:string,title:string,description:string,cover_image_id:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null,edit_revision?:int|string|null} Fresh persisted gallery identity and cover metadata after the sidecar write.
 */
function gallery_editor_change_cover_selection(
    int $galleryId,
    int $imageId,
    string $intent,
    ?string $expectedRevision = null,
): array {
    $writerLock = gallery_edit_writer_begin();
    try {
        return gallery_editor_change_cover_selection_owned(
            $galleryId,
            $imageId,
            $intent,
            $expectedRevision,
        );
    } finally {
        gallery_edit_writer_end($writerLock);
    }
}

/**
 * Apply one star transition while the caller holds the writer lease.
 *
 * Internal implementation called by gallery_editor_change_cover_selection().
 *
 * @param int $galleryId Owning gallery.
 * @param int $imageId Clicked direct-photo ID.
 * @param 'arm'|'add'|'reset' $intent State transition requested by one star click.
 * @param string|null $expectedRevision Optional cover fingerprint captured by the clicked star.
 * @return array{id:int|string,folder_path:string,title:string,description:string,cover_image_id:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null,edit_revision?:int|string|null} Fresh persisted gallery identity and cover metadata after the sidecar write.
 */
function gallery_editor_change_cover_selection_owned(
    int $galleryId,
    int $imageId,
    string $intent,
    ?string $expectedRevision = null,
): array {
    if (!in_array($intent, ['arm', 'add', 'reset'], true)) {
        throw new \InvalidArgumentException(
            'Unsupported title-picture selection intent.',
        );
    }
    $gallery = find_gallery($galleryId, true);
    $image = find_image($imageId, true);
    if (!$gallery) {
        throw new GalleryEditConflict([]);
    }
    gallery_editor_assert_cover_selection_schema();
    if (!array_key_exists('cover_image_ids', $gallery)) {
        throw new GalleryEditUnavailable(t(
            'admin.gallery_editor.cover_schema_unknown',
            'Cover selection was not changed because its database schema could not be verified. Check database diagnostics and try again.',
        ));
    }
    if (!gallery_cover_selection_storage_valid($gallery)) {
        throw new GalleryEditUnavailable(
            'Stored title-picture selection is invalid and cannot be safely changed.',
        );
    }
    gallery_editor_assert_cover_revision($gallery, $expectedRevision);
    if (
        !$image
        || (int) ($image['gallery_id'] ?? 0) !== $galleryId
        || str_contains((string) ($image['relative_path'] ?? ''), '/')
    ) {
        throw new GalleryEditConflict(gallery_edit_comparison($gallery));
    }
    $mode = gallery_cover_selection_mode($gallery);
    $ids = gallery_cover_selection_ids($gallery);
    if ($intent === 'arm') {
        if (
            $mode !== 'single'
            || (int) ($gallery['cover_image_id'] ?? 0) !== $imageId
        ) {
            throw new GalleryEditConflict(gallery_edit_comparison($gallery));
        }
        $ids = [$imageId];
    } elseif ($intent === 'add') {
        if ($mode !== 'multi' || in_array($imageId, $ids, true)) {
            throw new GalleryEditConflict(gallery_edit_comparison($gallery));
        }
        // Prune stale selections before appending; deleted/moved pictures
        // must not consume scarce visual slots or return as public media.
        $ids = array_values(array_filter($ids, static function (int $id) use (
            $galleryId,
        ): bool {
            $candidate = find_image($id, true);
            return $candidate
                && (int) ($candidate['gallery_id'] ?? 0) === $galleryId
                && !str_contains(
                    (string) ($candidate['relative_path'] ?? ''),
                    '/',
                );
        }));
        if (count($ids) >= 4) {
            throw new \DomainException(t(
                'admin.gallery_editor.cover_selection_limit',
                'Choose between one and four title-picture photos.',
            ));
        }
        $ids[] = $imageId;
    } else {
        if ($mode !== 'multi' || !in_array($imageId, $ids, true)) {
            throw new GalleryEditConflict(gallery_edit_comparison($gallery));
        }
        $ids = [$imageId];
    }
    $fields = [
        'cover_image_id' => $ids[0],
        'cover_image_ids' => $intent === 'reset'
            ? null
            : json_encode($ids, JSON_THROW_ON_ERROR),
    ];
    if (array_key_exists('cover_image_path', $gallery)) {
        $fields['cover_image_path'] = null;
    }
    gallery_model_update_fields($galleryId, $fields, now_sql());
    $updated = find_gallery($galleryId, true);
    if (!$updated) {
        throw new GalleryEditConflict([]);
    }
    if (!write_gallery_sidecar($updated)) {
        throw new \RuntimeException(
            'The title pictures were saved, but their sidecar metadata could not be written.',
        );
    }
    return $updated;
}

/**
 * Refuse cover writes unless selection storage is conclusively available.
 *
 * @return void Continue only when galleries.cover_image_ids is available.
 * @throws GalleryEditUnavailable When the migration is missing or inspection is unknown.
 */
function gallery_editor_assert_cover_selection_schema(): void
{
    $state = gallery_cover_selection_schema_state();
    if ($state !== 'available') {
        $message = $state === 'missing'
            ? t(
                'admin.gallery_editor.cover_migration_required',
                'Multi-photo title pictures require the latest database migration.',
            )
            : t(
                'admin.gallery_editor.cover_schema_unknown',
                'Cover selection was not changed because its database schema could not be verified. Check database diagnostics and try again.',
            );
        throw new GalleryEditUnavailable($message);
    }
}

/**
 * Check that a star mutation still matches the cover state shown by its caller.
 *
 * @param array{id:int|string,cover_image_id?:int|string|null,cover_image_ids?:string|null,cover_image_path?:string|null,edit_revision?:int|string|null}|null $gallery Fresh gallery cover state read under the writer lease.
 * @param string|null $expectedRevision Optional stable revision submitted by newer star handlers.
 * @return void Continue for legacy callers or a matching revision.
 * @throws GalleryEditConflict When the gallery is missing or the submitted selection is stale.
 */
function gallery_editor_assert_cover_revision(
    ?array $gallery,
    ?string $expectedRevision,
): void {
    if ($gallery === null) {
        throw new GalleryEditConflict([]);
    }
    if ($expectedRevision === null) {
        return;
    }
    if (
        preg_match('/^[a-f0-9]{20}$/D', $expectedRevision) !== 1
        || !hash_equals(
            gallery_cover_selection_revision($gallery),
            $expectedRevision,
        )
    ) {
        throw new GalleryEditConflict(gallery_edit_comparison($gallery));
    }
}

/**
 * Clear all explicit gallery background-source overrides.
 *
 * @return int Number of rows reported as changed.
 */
function gallery_clear_all_background_sources(): int
{
    return gallery_model_clear_background_sources(now_sql());
}
