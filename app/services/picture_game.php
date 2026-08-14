<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/picture_game.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for gallery data, media, settings, or maintenance workflows.
 *
 * Responsibilities:
 *   - Keep domain logic reusable outside controllers
 *   - Protect existing behavior with small focused functions
 *   - Return predictable values for callers
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
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Services;

use PDOException;
use RuntimeException;
use function Gallery\Core\cms_config;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\normalize_relative_path;
use function Gallery\Core\now_sql;
use function Gallery\Core\visitor_hash;

/**
 * Picture game and gallery-voting service layer.
 *
 * This module contains only persistence and selection logic for the public
 * A/B picture game. It intentionally keeps rendering and routing outside of
 * the service layer so the legacy controller functions can continue to call
 * the same global function names through app/services.php.
 */

/**
 * Return whether the current database has the picture-game migration applied.
 *
 * @return bool True when the condition matches.
 */
function picture_game_schema_ready(): bool
{
    return presentation_schema_render_available(presentation_picture_game_schema_status(), 'picture_game_render');
}

/**
 * Return whether gallery voting columns are available.
 *
 * @return bool True when the condition matches.
 */
function gallery_voting_schema_ready(): bool
{
    return presentation_schema_render_available(presentation_voting_schema_status(), 'image_voting_render');
}

/**
 * Return true when a gallery allows public voting controls.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function gallery_voting_allowed(array $gallery): bool
{
    if (function_exists('Gallery\\Services\\feature_flag_enabled') && !feature_flag_enabled('image_voting')) {
        return false;
    }
    return gallery_voting_schema_ready() && (int) ($gallery['voting_enabled'] ?? 0) === 1;
}

/**
 * Repair gallery voting/game inconsistencies when the admin dashboard is loaded.
 *
 * @return int Integer result for the caller.
 */
function sync_gallery_voting_game_state(): int
{
    $schemaStatus = presentation_picture_game_schema_status();
    presentation_schema_assert_known($schemaStatus, 'picture_game_state_sync');
    if (!schema_inspection_is_available($schemaStatus)) {
        return 0;
    }
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare('UPDATE galleries SET voting_enabled = 1, updated_at = ? WHERE picture_game_enabled = 1 AND voting_enabled = 0');
    $stmt->execute([now_sql()]);
    return $stmt->rowCount();
}

/**
 * Return gallery IDs in one gallery subtree that are enabled for picture game.
 *
 * Enabling a parent gallery makes its public descendants available for that
 * gallery's game, so meta-galleries can opt in their whole visible branch.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return array Structured result data for the caller.
 */
function picture_game_gallery_ids(array $gallery): array
{
    // Variable $folderPath stores this steps working value.
    $folderPath = normalize_relative_path((string) $gallery['folder_path']);
    try {
        // Variable $stmt stores this steps working value.
        $listingCondition = public_gallery_listing_sql_fragment('g');
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->prepare("SELECT g.* FROM galleries g WHERE $listingCondition AND (g.folder_path = ? OR g.folder_path LIKE ?) ORDER BY g.folder_path");
        $stmt->execute([$folderPath, $folderPath . '/%']);
    } catch (PDOException) {
        return [];
    }
    // Variable $enabledPaths stores this steps working value.
    $enabledPaths = [];
    // Variable $ids stores this steps working value.
    $ids = [];
    foreach ($stmt->fetchAll() as $candidate) {
        if (!visitor_can_access_gallery($candidate)) {
            continue;
        }
        // Variable $candidatePath stores this steps working value.
        $candidatePath = normalize_relative_path((string) $candidate['folder_path']);
        if ((int) ($candidate['picture_game_enabled'] ?? 0) === 1) {
            $enabledPaths[] = $candidatePath;
        }
        foreach ($enabledPaths as $enabledPath) {
            if ($candidatePath === $enabledPath || str_starts_with($candidatePath, $enabledPath . '/')) {
                $ids[] = (int) $candidate['id'];
                break;
            }
        }
    }
    return array_values(array_unique($ids));
}

/**
 * Return public direct images that may participate in one gallery's game.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return array Structured result data for the caller.
 */
function picture_game_images(array $gallery): array
{
    static $cache = [];
    // $cacheKey stores an intermediate value used by the surrounding gallery workflow.
    $cacheKey = (int) $gallery['id'];
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }
    // Variable $galleryIds stores this steps working value.
    $galleryIds = picture_game_gallery_ids($gallery);
    if (!$galleryIds) {
        return $cache[$cacheKey] = [];
    }
    // Variable $placeholders stores this steps working value.
    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    // Variable $filenameSelect stores this steps working value.
    $filenameSelect = gallery_filename_display_schema_ready() ? 'g.show_filenames AS gallery_show_filenames' : '0 AS gallery_show_filenames';
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT i.*, g.title AS gallery_title, g.folder_path AS gallery_folder_path, $filenameSelect FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE i.gallery_id IN ($placeholders) AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%' ORDER BY g.folder_path, i.sort_order, i.filename");
    $stmt->execute($galleryIds);
    $rows = $stmt->fetchAll();
    $galleryCache = [(int) $gallery['id'] => $gallery];
    $visible = [];
    foreach ($rows as $image) {
        $imageGalleryId = (int) $image['gallery_id'];
        if (!array_key_exists($imageGalleryId, $galleryCache)) {
            $galleryCache[$imageGalleryId] = find_gallery($imageGalleryId) ?: $gallery;
        }
        if (public_image_visible_to_current_visitor($image, $galleryCache[$imageGalleryId])) {
            $visible[] = $image;
        }
    }
    return $cache[$cacheKey] = $visible;
}

/**
 * Count visible public images until the picture game availability threshold is known.
 *
 * Public gallery pages only need to know whether the game button should be
 * visible. Loading every eligible image from a large gallery branch is reserved
 * for the game route itself, where the full candidate set is actually needed.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param int $minimum Minimum number of visible images required.
 * @return int Integer result for the caller.
 */
function picture_game_available_image_count(array $gallery, int $minimum = 2): int
{
    // $minimum stores the smallest useful threshold for this availability check.
    $minimum = max(1, $minimum);
    // Variable $galleryIds stores this steps working value.
    $galleryIds = picture_game_gallery_ids($gallery);
    if (!$galleryIds) {
        return 0;
    }

    // Variable $placeholders stores this steps working value.
    $placeholders = implode(',', array_fill(0, count($galleryIds), '?'));
    // Variable $filenameSelect stores this steps working value.
    $filenameSelect = gallery_filename_display_schema_ready() ? 'g.show_filenames AS gallery_show_filenames' : '0 AS gallery_show_filenames';
    // Variable $sql stores this steps working value.
    $sql = "SELECT i.*, g.title AS gallery_title, g.folder_path AS gallery_folder_path, $filenameSelect FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE i.gallery_id IN ($placeholders) AND i.visibility = 'public' AND i.relative_path NOT LIKE '%/%'";
    // Variable $params stores this steps working value.
    $params = $galleryIds;
    if (nsfw_guard_schema_ready() && !visitor_can_access_nsfw_content()) {
        $sql .= ' AND COALESCE(i.nsfw_enabled, 0) = 0';
    }
    $sql .= ' ORDER BY g.folder_path, i.sort_order, i.filename LIMIT ' . $minimum;

    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    // $galleryCache stores gallery rows reused for final visitor-specific checks.
    $galleryCache = [(int) $gallery['id'] => $gallery];
    // $visibleCount stores how many visible candidates have been found.
    $visibleCount = 0;
    foreach ($stmt->fetchAll() as $image) {
        // $imageGalleryId stores the owning gallery id for this candidate image.
        $imageGalleryId = (int) $image['gallery_id'];
        if (!array_key_exists($imageGalleryId, $galleryCache)) {
            $galleryCache[$imageGalleryId] = find_gallery($imageGalleryId) ?: $gallery;
        }
        if (public_image_visible_to_current_visitor($image, $galleryCache[$imageGalleryId])) {
            $visibleCount++;
        }
        if ($visibleCount >= $minimum) {
            break;
        }
    }

    return $visibleCount;
}

/**
 * Return whether one gallery has enough opted-in public images for a game.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param ?array $images Images value.
 * @return bool True when the condition matches.
 */
function picture_game_available(array $gallery, ?array $images = null): bool
{
    if ($images !== null) {
        return count($images) >= 2;
    }
    return picture_game_available_image_count($gallery, 2) >= 2;
}

/**
 * Stable voter key for picture-game pair history.
 *
 * @return string Text result for the caller.
 */
function picture_game_voter_hash(): string
{
    // Variable $user stores this steps working value.
    $user = current_user();
    if ($user) {
        return hash('sha256', 'user|' . (int) $user['id'] . '|' . (string) cms_config()['visitor_vote_secret']);
    }
    return visitor_hash();
}

/**
 * Normalize a pair of image IDs so A/B order cannot create duplicate pairs.
 *
 * @param int $firstImageId First image id identifier.
 * @param int $secondImageId Second image id identifier.
 * @return array Structured result data for the caller.
 */
function picture_game_pair_key(int $firstImageId, int $secondImageId): array
{
    return [min($firstImageId, $secondImageId), max($firstImageId, $secondImageId)];
}

/**
 * Return the next unplayed image pair for this voter in one gallery context.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param ?array $images Images value.
 * @return ?array Structured result data for the caller.
 */
function next_picture_game_pair(array $gallery, ?array $images = null): ?array
{
    $schemaStatus = presentation_picture_game_schema_status();
    presentation_schema_assert_write_available($schemaStatus, 'picture_game_pair_display');
    // Variable $images stores this steps working value.
    $images ??= picture_game_images($gallery);
    if (count($images) < 2) {
        return null;
    }
    // Variable $imageById stores this steps working value.
    $imageById = [];
    foreach ($images as $image) {
        $imageById[(int) $image['id']] = $image;
    }
    // Variable $ids stores this steps working value.
    $ids = array_keys($imageById);
    // Variable $voterHash stores this steps working value.
    $voterHash = picture_game_voter_hash();
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('SELECT image_a_id, image_b_id FROM picture_game_votes WHERE gallery_id = ? AND voter_hash = ?');
    $stmt->execute([(int) $gallery['id'], $voterHash]);
    // Variable $seen stores this steps working value.
    $seen = [];
    foreach ($stmt->fetchAll() as $row) {
        $seen[(int) $row['image_a_id'] . ':' . (int) $row['image_b_id']] = true;
    }
    // Variable $pairs stores this steps working value.
    $pairs = [];
    for ($i = 0, $count = count($ids); $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            [$imageAId, $imageBId] = picture_game_pair_key((int) $ids[$i], (int) $ids[$j]);
            // $key stores an intermediate value used by the surrounding gallery workflow.
            $key = $imageAId . ':' . $imageBId;
            if (!isset($seen[$key])) {
                $pairs[] = [$imageAId, $imageBId];
            }
        }
    }
    if (!$pairs) {
        return null;
    }
    // Variable $pair stores this steps working value.
    $pair = $pairs[random_int(0, count($pairs) - 1)];
    // Record display immediately so a voter does not keep seeing the same pair.
    db()->prepare('INSERT IGNORE INTO picture_game_votes (gallery_id, image_a_id, image_b_id, winner_image_id, voter_hash, created_at) VALUES (?, ?, ?, NULL, ?, ?)')->execute([
        (int) $gallery['id'],
        (int) $pair[0],
        (int) $pair[1],
        $voterHash,
        now_sql(),
    ]);
    return [
        'left' => $imageById[$pair[0]],
        'right' => $imageById[$pair[1]],
        'remaining_pairs' => count($pairs),
        'total_pairs' => (int) (count($ids) * (count($ids) - 1) / 2),
    ];
}

/**
 * Record one picture-game selection and upvote only the chosen image.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param int $leftImageId Left image id identifier.
 * @param int $rightImageId Right image id identifier.
 * @param int $winnerImageId Winner image id identifier.
 * @param ?array $images Images value.
 */
function record_picture_game_vote(array $gallery, int $leftImageId, int $rightImageId, int $winnerImageId, ?array $images = null): void
{
    presentation_schema_assert_write_available(presentation_picture_game_schema_status(), 'picture_game_vote');
    [$imageAId, $imageBId] = picture_game_pair_key($leftImageId, $rightImageId);
    if (!in_array($winnerImageId, [$imageAId, $imageBId], true)) {
        throw new RuntimeException('Selected image is not part of this pair.');
    }
    // Variable $allowedIds stores this steps working value.
    $images ??= picture_game_images($gallery);
    // $allowedIds stores an intermediate value used by the surrounding gallery workflow.
    $allowedIds = array_map(static fn (array $image): int => (int) $image['id'], $images);
    if (!in_array($imageAId, $allowedIds, true) || !in_array($imageBId, $allowedIds, true)) {
        throw new RuntimeException('Image pair is not available in this game.');
    }
    // Variable $voterHash stores this steps working value.
    $voterHash = picture_game_voter_hash();
    // Variable $existing stores this steps working value.
    $existing = db()->prepare('SELECT winner_image_id FROM picture_game_votes WHERE gallery_id = ? AND voter_hash = ? AND image_a_id = ? AND image_b_id = ?');
    $existing->execute([(int) $gallery['id'], $voterHash, $imageAId, $imageBId]);
    // Variable $winner stores this steps working value.
    $winner = $existing->fetchColumn();
    if ($winner !== false && $winner !== null) {
        return;
    }
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare('INSERT INTO picture_game_votes (gallery_id, image_a_id, image_b_id, winner_image_id, voter_hash, created_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE winner_image_id = VALUES(winner_image_id)');
    $stmt->execute([(int) $gallery['id'], $imageAId, $imageBId, $winnerImageId, $voterHash, now_sql()]);
    // Variable $user stores this steps working value.
    $user = current_user();
    if ($user) {
        // $vote stores an intermediate value used by the surrounding gallery workflow.
        $vote = db()->prepare('INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) VALUES (?, ?, NULL, 1, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)');
        $vote->execute([$winnerImageId, (int) $user['id'], now_sql(), now_sql()]);
        return;
    }
    // $vote stores an intermediate value used by the surrounding gallery workflow.
    $vote = db()->prepare('INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) VALUES (?, NULL, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE vote = VALUES(vote), updated_at = VALUES(updated_at)');
    $vote->execute([$winnerImageId, visitor_hash(), now_sql(), now_sql()]);
}

/**
 * Return top global picture-game winners for one gallery context.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param int $limit Maximum number of items.
 * @param ?array $images Images value.
 * @return array Structured result data for the caller.
 */
function picture_game_top_images(array $gallery, int $limit = 3, ?array $images = null): array
{
    // Variable $images stores this steps working value.
    $images ??= picture_game_images($gallery);
    if (!$images) {
        return [];
    }
    // Variable $ids stores this steps working value.
    $ids = array_map(static fn (array $image): int => (int) $image['id'], $images);
    // Variable $placeholders stores this steps working value.
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    // Variable $filenameSelect stores this steps working value.
    $filenameSelect = gallery_filename_display_schema_ready() ? 'g.show_filenames AS gallery_show_filenames' : '0 AS gallery_show_filenames';
    // Variable $stmt stores this steps working value.
    $stmt = db()->prepare("SELECT i.*, g.title AS gallery_title, $filenameSelect,
            (SELECT COUNT(*) FROM picture_game_votes pgv WHERE pgv.winner_image_id = i.id) AS game_wins,
            (SELECT COALESCE(SUM(iv.vote), 0) FROM image_votes iv WHERE iv.image_id = i.id) AS score
        FROM images i
        JOIN galleries g ON g.id = i.gallery_id
        WHERE i.id IN ($placeholders)
            AND (SELECT COUNT(*) FROM picture_game_votes pgv WHERE pgv.winner_image_id = i.id) > 0
        ORDER BY game_wins DESC, score DESC, i.sort_order, i.filename
        LIMIT " . max(1, $limit));
    $stmt->execute($ids);
    return $stmt->fetchAll();
}
