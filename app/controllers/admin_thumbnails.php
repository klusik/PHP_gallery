<?php

declare(strict_types=1);

/**
 * Admin thumbnail controller model.
 * 
 * This module renders thumbnail maintenance notices and handles manual or batched thumbnail generation requests.
 */

function render_admin_thumbnail_maintenance_notice(array $summary): void
{
    if (($summary['images_with_missing'] ?? 0) <= 0 && ($summary['webp_skipped'] ?? 0) <= 0) {
        return;
    }
    echo '<div class="notice">';
    if (($summary['images_with_missing'] ?? 0) > 0) {
        echo '<strong>Thumbnail maintenance required.</strong> ';
        echo e((string) $summary['images_with_missing']) . ' image(s) are missing optimized thumbnails or have stale thumbnail files. ';
        echo e((string) $summary['missing_variants']) . ' thumbnail variant(s) need to be created. ';
        if (!empty($summary['limited'])) {
            echo 'Only the first ' . e((string) $summary['images_scanned']) . ' image(s) were checked, so more may be pending. ';
        }
        echo 'Public visitors will not generate these thumbnails while browsing. Use <strong>Create all thumbnails</strong> in the admin toolbar.';
    }
    if (($summary['webp_skipped'] ?? 0) > 0) {
        echo (($summary['images_with_missing'] ?? 0) > 0 ? '<br>' : '');
        echo 'Some WebP variants are intentionally skipped because the source images contain EXIF metadata and this server cannot preserve EXIF during WebP conversion.';
    }
    echo '</div>';
}

/**
 * Handles cms admin create thumbnails logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_create_thumbnails(): void
{
    require_admin();
    verify_csrf();
    if (!empty($_POST['ajax']) || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
        cms_admin_create_thumbnails_batch();
        return;
    }
    // Variable $count stores this steps working value.
    $count = 0;
    if (($_POST['scope'] ?? '') === 'all') {
        // Variable $count stores this steps working value.
        $count = create_all_thumbnails();
        flash_message('admin_notice', 'Created ' . $count . ' thumbnail(s).');
        redirect_to(url_for('admin'));
    }
    // Variable $galleryId stores this steps working value.
    $galleryId = (int) ($_POST['thumbnail_gallery_id'] ?? $_POST['gallery_id'] ?? 0);
    // Variable $gallery stores this steps working value.
    $gallery = $galleryId > 0 ? find_gallery($galleryId) : null;
    if ($gallery && empty($_POST['thumbnail_gallery_id']) && !empty($_POST['image_ids'])) {
        foreach (array_map('intval', $_POST['image_ids']) as $imageId) {
            // Variable $image stores this steps working value.
            $image = find_image($imageId);
            if ($image && (int) $image['gallery_id'] === $galleryId) {
                $count += create_image_thumbnails($image, $gallery);
            }
        }
        flash_message('admin_notice', 'Created ' . $count . ' thumbnail(s).');
        redirect_to(url_for('admin_edit_gallery', ['id' => $galleryId]));
    }
    if ($gallery) {
        // Variable $count stores this steps working value.
        $count = create_gallery_thumbnails($galleryId);
        flash_message('admin_notice', 'Created ' . $count . ' thumbnail(s).');
        redirect_to(url_for('admin'));
    }
    redirect_to(url_for('admin'));
}

/**
 * Handles cms admin create thumbnails batch logic for the gallery application.
 * @return mixed Result produced by this operation.
 */
function cms_admin_create_thumbnails_batch(): void
{
    // Variable $imageIds stores this steps working value.
    $imageIds = thumbnail_request_image_ids($_POST);
    // Variable $total stores this steps working value.
    $total = count($imageIds);
    // Variable $offset stores this steps working value.
    $offset = max(0, (int) ($_POST['offset'] ?? 0));
    // Variable $batchSize stores this steps working value.
    $batchSize = max(1, min(12, (int) ($_POST['batch_size'] ?? 6)));
    // Variable $batch stores this steps working value.
    $batch = array_slice($imageIds, $offset, $batchSize);
    // Variable $created stores this steps working value.
    $created = 0;
    // Variable $skipped stores this steps working value.
    $skipped = 0;
    // Variable $webpSkipped stores this steps working value.
    $webpSkipped = 0;
    // Variable $galleryCache stores this steps working value.
    $galleryCache = [];
    foreach ($batch as $imageId) {
        // Variable $image stores this steps working value.
        $image = find_image((int) $imageId);
        if (!$image) {
            continue;
        }
        // Variable $galleryId stores this steps working value.
        $galleryId = (int) $image['gallery_id'];
        if (!array_key_exists($galleryId, $galleryCache)) {
            $galleryCache[$galleryId] = find_gallery($galleryId);
        }
        if (!$galleryCache[$galleryId]) {
            continue;
        }
        // Variable $result stores this steps working value.
        $result = create_image_thumbnails_result($image, $galleryCache[$galleryId]);
        $created += (int) $result['created'];
        $skipped += (int) $result['skipped'];
        $webpSkipped += (int) ($result['webp_skipped'] ?? 0);
    }
    // Variable $processed stores this steps working value.
    $processed = min($total, $offset + count($batch));
    header('Content-Type: application/json');
    echo json_encode([
        'total' => $total,
        'processed' => $processed,
        'next_offset' => $processed,
        'webp_skipped' => $webpSkipped,
        'created' => $created,
        'skipped' => $skipped,
        'done' => $processed >= $total,
    ]);
}

/**
 * Handles thumbnail request image ids logic for the gallery application.
 * @param mixed $post Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function thumbnail_request_image_ids(array $post): array
{
    if (($post['scope'] ?? '') === 'all') {
        return all_image_ids();
    }
    if (!empty($post['gallery_ids']) && is_array($post['gallery_ids'])) {
        return image_ids_for_galleries($post['gallery_ids']);
    }
    // Variable $thumbnailGalleryId stores this steps working value.
    $thumbnailGalleryId = (int) ($post['thumbnail_gallery_id'] ?? 0);
    if ($thumbnailGalleryId > 0) {
        return image_ids_for_galleries([$thumbnailGalleryId]);
    }
    // Variable $galleryId stores this steps working value.
    $galleryId = (int) ($post['gallery_id'] ?? 0);
    if (!empty($post['image_ids']) && is_array($post['image_ids'])) {
        // Variable $ids stores this steps working value.
        $ids = [];
        foreach (array_map('intval', $post['image_ids']) as $imageId) {
            // Variable $image stores this steps working value.
            $image = find_image($imageId);
            if (!$image) {
                continue;
            }
            if ($galleryId > 0 && (int) $image['gallery_id'] !== $galleryId) {
                continue;
            }
            $ids[] = $imageId;
        }
        return array_values(array_unique($ids));
    }
    if ($galleryId > 0) {
        return image_ids_for_galleries([$galleryId]);
    }
    return [];
}

