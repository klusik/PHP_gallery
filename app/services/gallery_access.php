<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_access.php
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

/**
 * Gallery access model.
 * 
 * This module owns password protection, share token handling, visitor access checks, and public listing rules for galleries. It does not alter theme or admin styling settings.
 */


/**
 * Return canonical gallery visibility values used by the simplified public model.
 *
 * @return array Structured result data for the caller.
 */
function gallery_visibility_values(): array
{
    return ['public', 'unpublished', 'private'];
}

/**
 * Normalize legacy and current gallery visibility values to the public model.
 *
 * @param string $visibility Visibility value.
 * @return string Text result for the caller.
 */
function normalize_gallery_visibility(string $visibility): string
{
    $visibility = strtolower(trim($visibility));
    if ($visibility === 'draft' || $visibility === 'unlisted') {
        return 'unpublished';
    }
    return in_array($visibility, gallery_visibility_values(), true) ? $visibility : 'unpublished';
}

/**
 * Return the effective visibility for a gallery row, including legacy listing flags.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return string Text result for the caller.
 */
function gallery_effective_visibility(array $gallery): string
{
    $visibility = normalize_gallery_visibility((string) ($gallery['visibility'] ?? 'unpublished'));
    if ($visibility === 'public' && (string) ($gallery['access_listing'] ?? 'listed') === 'unlisted') {
        return 'unpublished';
    }
    return $visibility;
}

/**
 * Return the database value to store for one gallery visibility.
 *
 * @param string $visibility Visibility value.
 * @return string Text result for the caller.
 */
function gallery_visibility_storage_value(string $visibility): string
{
    $visibility = normalize_gallery_visibility($visibility);
    if ($visibility === 'unpublished' && !gallery_visibility_schema_supports_unpublished()) {
        return 'draft';
    }
    return $visibility;
}

/**
 * Return true when the current galleries.visibility enum accepts unpublished.
 *
 * @return bool True when the condition matches.
 */
function gallery_visibility_schema_supports_unpublished(): bool
{
    static $supports = null;
    if ($supports !== null) {
        return $supports;
    }
    try {
        // $column stores the database enum definition used by compatibility installs.
        $column = db()->query("SHOW COLUMNS FROM galleries LIKE 'visibility'")->fetch();
        return $supports = $column && str_contains((string) ($column['Type'] ?? ''), "'unpublished'");
    } catch (Throwable) {
        return $supports = false;
    }
}

/**
 * Return the UI label for one canonical gallery visibility value.
 *
 * @param string $visibility Visibility value.
 * @return string Text result for the caller.
 */
function gallery_visibility_label(string $visibility): string
{
    return match (normalize_gallery_visibility($visibility)) {
        'public' => t('gallery.visibility.public', 'public'),
        'private' => t('gallery.visibility.private', 'private'),
        default => t('gallery.visibility.unpublished', 'unpublished'),
    };
}

/**
 * Return true when anonymous visitors may request this gallery by its normal URL.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function gallery_allows_direct_public_request(array $gallery): bool
{
    return in_array(gallery_effective_visibility($gallery), ['public', 'unpublished'], true);
}

/**
 * Handle admin feature schema ready.
 *
 * Part of the related application service.
 *
 * @return bool True when the condition matches.
 */
function admin_feature_schema_ready(): bool
{
    return picture_game_schema_ready() && admin_log_schema_ready() && exif_gps_schema_ready() && gallery_access_schema_ready() && nsfw_guard_schema_ready();
}

/**
 * Handles gallery access schema ready logic for the gallery application.
 *
 * @return mixed Result produced by this operation.
 */
function gallery_access_schema_ready(): bool
{
    try {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'access_mode'");
        if (!$stmt || !$stmt->fetch()) {
            return false;
        }
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'access_token_hash'");
        return $stmt && (bool) $stmt->fetch();
    } catch (PDOException) {
        return false;
    }
}


/**
 * Return true when the NSFW Guard database columns are available.
 *
 * @return bool True when the condition matches.
 */
function nsfw_guard_schema_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        // $galleryColumn stores the galleries schema probe result.
        $galleryColumn = db()->query("SHOW COLUMNS FROM galleries LIKE 'nsfw_enabled'");
        if (!$galleryColumn || !$galleryColumn->fetch()) {
            return $ready = false;
        }
        // $imageColumn stores the images schema probe result.
        $imageColumn = db()->query("SHOW COLUMNS FROM images LIKE 'nsfw_enabled'");
        return $ready = $imageColumn && (bool) $imageColumn->fetch();
    } catch (PDOException) {
        return $ready = false;
    }
}

/**
 * Return the nearest gallery that applies an inherited NSFW restriction.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return ?array Structured result data for the caller.
 */
function gallery_nsfw_requirement(array $gallery): ?array
{
    if (!nsfw_guard_schema_ready()) {
        return null;
    }
    // $current stores the gallery currently being inspected while walking upward.
    $current = $gallery;
    while ($current) {
        if ((int) ($current['nsfw_enabled'] ?? 0) === 1) {
            return $current;
        }
        if (empty($current['parent_id'])) {
            return null;
        }
        // $current stores the parent gallery used for inherited NSFW policy.
        $current = find_gallery((int) $current['parent_id']);
    }
    return null;
}

/**
 * Return true when one image is restricted by its own flag or by gallery ancestry.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function image_nsfw_restricted(array $image, array $gallery): bool
{
    if (!nsfw_guard_schema_ready()) {
        return false;
    }
    return (int) ($image['nsfw_enabled'] ?? 0) === 1 || gallery_nsfw_requirement($gallery) !== null;
}

/**
 * Return true when one public image may be exposed to the current visitor.
 *
 * @param array $image Image row or image data.
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function public_image_visible_to_current_visitor(array $image, array $gallery): bool
{
    if ((string) ($image['visibility'] ?? '') !== 'public') {
        return false;
    }
    if (!visitor_can_access_gallery($gallery)) {
        return false;
    }
    if (image_nsfw_restricted($image, $gallery) && !visitor_can_access_nsfw_content()) {
        return false;
    }
    return true;
}

/**
 * Return true when public media for one gallery needs private cache semantics.
 *
 * @param array $gallery Gallery row or gallery data.
 * @param ?array $image Image row or image data.
 * @return bool True when the condition matches.
 */
function public_media_needs_private_cache(array $gallery, ?array $image = null): bool
{
    if (gallery_access_requirement($gallery) || gallery_nsfw_requirement($gallery)) {
        return true;
    }
    return $image !== null && image_nsfw_restricted($image, $gallery);
}

/**
 * Build the single session key used for NSFW age acknowledgment.
 *
 * @return string Text result for the caller.
 */
function nsfw_guard_session_key(): string
{
    return 'nsfw_guard_adult_acknowledged';
}

/**
 * Store the current session-level 18+ acknowledgment.
 */
function grant_nsfw_guard_access(): void
{
    $_SESSION[nsfw_guard_session_key()] = time();
}

/**
 * Return true when this visitor already confirmed the NSFW warning in session.
 *
 * @return bool True when the condition matches.
 */
function nsfw_guard_session_is_valid(): bool
{
    return (int) ($_SESSION[nsfw_guard_session_key()] ?? 0) > 0;
}

/**
 * Return true when a logged-in account is explicitly known to be under 18.
 *
 * The current CMS user schema does not define age or birth-date columns. This
 * helper still supports future installs that add one of the common fields, and
 * otherwise treats age as unknown so the normal session confirmation is used.
 *
 * @return bool True when the condition matches.
 */
function current_user_is_known_under_18(): bool
{
    // $user stores the authenticated user record, usually the admin account.
    $user = current_user();
    if (!$user) {
        return false;
    }
    if (isset($user['is_adult'])) {
        return (int) $user['is_adult'] !== 1;
    }
    if (isset($user['age'])) {
        return (int) $user['age'] < 18;
    }
    // $birthDate stores the first supported date-of-birth field found on the user record.
    $birthDate = (string) ($user['date_of_birth'] ?? $user['birthdate'] ?? $user['birthday'] ?? '');
    if ($birthDate === '') {
        return false;
    }
    // $birthTimestamp stores the parsed birth date used for the age calculation.
    $birthTimestamp = strtotime($birthDate);
    if ($birthTimestamp === false) {
        return false;
    }
    // $adultThreshold stores the latest birth date that is at least eighteen years old today.
    $adultThreshold = strtotime('-18 years');
    return $birthTimestamp > $adultThreshold;
}

/**
 * Return true when the current visitor may pass NSFW Guard.
 *
 * @return bool True when the condition matches.
 */
function visitor_can_access_nsfw_content(): bool
{
    if (current_user() && !current_user_is_known_under_18()) {
        return true;
    }
    if (current_user_is_known_under_18()) {
        return false;
    }
    return nsfw_guard_session_is_valid();
}

/**
 * Handles gallery has password policy logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_has_password_policy(array $gallery): bool
{
    return gallery_access_schema_ready() && (string) ($gallery['access_mode'] ?? 'normal') === 'password';
}

/**
 * Handles gallery access requirement logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_access_requirement(array $gallery): ?array
{
    if (!gallery_access_schema_ready()) {
        return null;
    }
    // $current stores an intermediate value used by the surrounding gallery workflow.
    $current = $gallery;
    while ($current) {
        if ((string) ($current['access_mode'] ?? 'normal') === 'password') {
            return $current;
        }
        if (empty($current['parent_id'])) {
            return null;
        }
        // $current stores an intermediate value used by the surrounding gallery workflow.
        $current = find_gallery((int) $current['parent_id']);
    }
    return null;
}

/**
 * Handles gallery access session key logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_access_session_key(int $galleryId): string
{
    return 'gallery_access_' . $galleryId;
}

/**
 * Handles gallery access lifetime seconds logic for the gallery application.
 *
 * @return mixed Result produced by this operation.
 */
function gallery_access_lifetime_seconds(): int
{
    return 600;
}

/**
 * Handles grant gallery public access logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 */
function grant_gallery_public_access(int $galleryId): void
{
    $_SESSION[gallery_access_session_key($galleryId)] = time();
}

/**
 * Handles gallery public access session is valid logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_public_access_session_is_valid(int $galleryId): bool
{
    // $key stores an intermediate value used by the surrounding gallery workflow.
    $key = gallery_access_session_key($galleryId);
    // $unlockedAt stores an intermediate value used by the surrounding gallery workflow.
    $unlockedAt = (int) ($_SESSION[$key] ?? 0);
    if ($unlockedAt <= 0) {
        return false;
    }
    if ((time() - $unlockedAt) > gallery_access_lifetime_seconds()) {
        unset($_SESSION[$key]);
        return false;
    }
    return true;
}

/**
 * Handles request share token allows gallery logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function request_share_token_allows_gallery(array $gallery): bool
{
    // $token stores an intermediate value used by the surrounding gallery workflow.
    $token = trim((string) ($_GET['share'] ?? $_GET['token'] ?? ''));
    if ($token === '') {
        return false;
    }
    // $requirement stores an intermediate value used by the surrounding gallery workflow.
    $requirement = gallery_access_requirement($gallery);
    if (!$requirement || empty($requirement['access_token_hash'])) {
        return false;
    }
    if (!empty($requirement['access_token_expires_at']) && strtotime((string) $requirement['access_token_expires_at']) < time()) {
        return false;
    }
    if (!hash_equals((string) $requirement['access_token_hash'], hash('sha256', $token))) {
        return false;
    }
    grant_gallery_public_access((int) $requirement['id']);
    return true;
}

/**
 * Handles visitor can access gallery logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function visitor_can_access_gallery(array $gallery): bool
{
    if (current_user() && !current_user_is_known_under_18()) {
        return true;
    }
    // $requirement stores an intermediate value used by the surrounding gallery workflow.
    $requirement = gallery_access_requirement($gallery);
    if (!gallery_allows_direct_public_request($gallery)) {
        if (!$requirement) {
            return false;
        }
        if (!gallery_public_access_session_is_valid((int) $requirement['id']) && !request_share_token_allows_gallery($gallery)) {
            return false;
        }
    }
    if (gallery_nsfw_requirement($gallery) !== null && !visitor_can_access_nsfw_content()) {
        return false;
    }
    if (!$requirement) {
        return true;
    }
    if (gallery_public_access_session_is_valid((int) $requirement['id'])) {
        return true;
    }
    return request_share_token_allows_gallery($gallery);
}

/**
 * Handles gallery is public listed logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_is_public_listed(array $gallery): bool
{
    if (gallery_effective_visibility($gallery) !== 'public') {
        return false;
    }
    if (!gallery_access_schema_ready()) {
        return true;
    }
    return (string) ($gallery['access_listing'] ?? 'listed') === 'listed';
}

/**
 * Handles regenerate gallery share token logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 * @param mixed $expiresAt Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function regenerate_gallery_share_token(int $galleryId, ?string $expiresAt): string
{
    // $token stores an intermediate value used by the surrounding gallery workflow.
    $token = bin2hex(random_bytes(24));
    // $storedToken stores an intermediate value used by the surrounding gallery workflow.
    $storedToken = encrypt_gallery_share_token($token);
    // $shareColumn stores an intermediate value used by the surrounding gallery workflow.
    $shareColumn = gallery_access_share_token_schema_ready() ? 'access_share_token = ?, ' : '';
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare('UPDATE galleries SET ' . $shareColumn . 'access_token_hash = ?, access_token_expires_at = ?, updated_at = ? WHERE id = ?');
    // $params stores an intermediate value used by the surrounding gallery workflow.
    $params = gallery_access_share_token_schema_ready()
        ? [$storedToken, hash('sha256', $token), $expiresAt, now_sql(), $galleryId]
        : [hash('sha256', $token), $expiresAt, now_sql(), $galleryId];
    $stmt->execute($params);
    return $token;
}

/**
 * Handles revoke gallery share token logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 */
function revoke_gallery_share_token(int $galleryId): void
{
    // $shareColumn stores an intermediate value used by the surrounding gallery workflow.
    $shareColumn = gallery_access_share_token_schema_ready() ? 'access_share_token = NULL, ' : '';
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare('UPDATE galleries SET ' . $shareColumn . 'access_token_hash = NULL, access_token_expires_at = NULL, updated_at = ? WHERE id = ?');
    $stmt->execute([now_sql(), $galleryId]);
}

/**
 * Handles gallery access share token schema ready logic for the gallery application.
 *
 * @return mixed Result produced by this operation.
 */
function gallery_access_share_token_schema_ready(): bool
{
    try {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'access_share_token'");
        return $stmt && (bool) $stmt->fetch();
    } catch (PDOException) {
        return false;
    }
}

/**
 * Handles gallery share token for admin logic for the gallery application.
 *
 * @param mixed $gallery Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function gallery_share_token_for_admin(array $gallery): ?string
{
    // $stored stores an intermediate value used by the surrounding gallery workflow.
    $stored = (string) ($gallery['access_share_token'] ?? '');
    // $hash stores an intermediate value used by the surrounding gallery workflow.
    $hash = (string) ($gallery['access_token_hash'] ?? '');
    if ($stored === '' || $hash === '') {
        return null;
    }

    // $token stores an intermediate value used by the surrounding gallery workflow.
    $token = decrypt_gallery_share_token($stored);
    if ($token !== null && hash_equals($hash, hash('sha256', $token))) {
        return $token;
    }

    if (hash_equals($hash, hash('sha256', $stored))) {
        // $encrypted stores an intermediate value used by the surrounding gallery workflow.
        $encrypted = encrypt_gallery_share_token($stored);
        if ($encrypted === null) {
            return null;
        }
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->prepare('UPDATE galleries SET access_share_token = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$encrypted, now_sql(), (int) $gallery['id']]);
        return $stored;
    }

    return null;
}

/**
 * Handles encrypt gallery share token logic for the gallery application.
 *
 * @param mixed $token Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function encrypt_gallery_share_token(string $token): ?string
{
    if (!function_exists('openssl_encrypt')) {
        return null;
    }
    // $iv stores an intermediate value used by the surrounding gallery workflow.
    $iv = random_bytes(12);
    // $tag stores an intermediate value used by the surrounding gallery workflow.
    $tag = '';
    // $ciphertext stores an intermediate value used by the surrounding gallery workflow.
    $ciphertext = openssl_encrypt($token, 'aes-256-gcm', gallery_share_token_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false || $tag === '') {
        return null;
    }
    return 'enc:v1:' . base64_encode($iv . $tag . $ciphertext);
}

/**
 * Handles decrypt gallery share token logic for the gallery application.
 *
 * @param mixed $stored Input used by this operation.
 * @return mixed Result produced by this operation.
 */
function decrypt_gallery_share_token(string $stored): ?string
{
    if (!str_starts_with($stored, 'enc:v1:') || !function_exists('openssl_decrypt')) {
        return null;
    }
    // $payload stores an intermediate value used by the surrounding gallery workflow.
    $payload = base64_decode(substr($stored, 7), true);
    if ($payload === false || strlen($payload) <= 28) {
        return null;
    }
    // $iv stores an intermediate value used by the surrounding gallery workflow.
    $iv = substr($payload, 0, 12);
    // $tag stores an intermediate value used by the surrounding gallery workflow.
    $tag = substr($payload, 12, 16);
    // $ciphertext stores an intermediate value used by the surrounding gallery workflow.
    $ciphertext = substr($payload, 28);
    // $token stores an intermediate value used by the surrounding gallery workflow.
    $token = openssl_decrypt($ciphertext, 'aes-256-gcm', gallery_share_token_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $token === false ? null : $token;
}

/**
 * Handles gallery share token key logic for the gallery application.
 *
 * @return mixed Result produced by this operation.
 */
function gallery_share_token_key(): string
{
    return hash('sha256', 'gallery-share-token|' . (string) cms_config()['visitor_vote_secret'], true);
}

