<?php

declare(strict_types=1);

/**
 * Gallery access model.
 * 
 * This module owns password protection, share token handling, visitor access checks, and public listing rules for galleries. It does not alter theme or admin styling settings.
 */

function admin_feature_schema_ready(): bool
{
    return picture_game_schema_ready() && admin_log_schema_ready() && exif_gps_schema_ready() && gallery_access_schema_ready();
}

function gallery_access_schema_ready(): bool
{
    try {
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'access_mode'");
        if (!$stmt || !$stmt->fetch()) {
            return false;
        }
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'access_token_hash'");
        return $stmt && (bool) $stmt->fetch();
    } catch (PDOException) {
        return false;
    }
}

function gallery_has_password_policy(array $gallery): bool
{
    return gallery_access_schema_ready() && (string) ($gallery['access_mode'] ?? 'normal') === 'password';
}

function gallery_access_requirement(array $gallery): ?array
{
    if (!gallery_access_schema_ready()) {
        return null;
    }
    $current = $gallery;
    while ($current) {
        if ((string) ($current['access_mode'] ?? 'normal') === 'password') {
            return $current;
        }
        if (empty($current['parent_id'])) {
            return null;
        }
        $current = find_gallery((int) $current['parent_id']);
    }
    return null;
}

function gallery_access_session_key(int $galleryId): string
{
    return 'gallery_access_' . $galleryId;
}

function gallery_access_lifetime_seconds(): int
{
    return 600;
}

function grant_gallery_public_access(int $galleryId): void
{
    $_SESSION[gallery_access_session_key($galleryId)] = time();
}

function gallery_public_access_session_is_valid(int $galleryId): bool
{
    $key = gallery_access_session_key($galleryId);
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

function request_share_token_allows_gallery(array $gallery): bool
{
    $token = trim((string) ($_GET['share'] ?? $_GET['token'] ?? ''));
    if ($token === '') {
        return false;
    }
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

function visitor_can_access_gallery(array $gallery): bool
{
    if (current_user()) {
        return true;
    }
    if ((string) $gallery['visibility'] !== 'public') {
        return false;
    }
    $requirement = gallery_access_requirement($gallery);
    if (!$requirement) {
        return true;
    }
    if (gallery_public_access_session_is_valid((int) $requirement['id'])) {
        return true;
    }
    return request_share_token_allows_gallery($gallery);
}

function gallery_is_public_listed(array $gallery): bool
{
    if ((string) $gallery['visibility'] !== 'public') {
        return false;
    }
    if (!gallery_access_schema_ready()) {
        return true;
    }
    return (string) ($gallery['access_listing'] ?? 'listed') === 'listed';
}

function regenerate_gallery_share_token(int $galleryId, ?string $expiresAt): string
{
    $token = bin2hex(random_bytes(24));
    $storedToken = encrypt_gallery_share_token($token);
    $shareColumn = gallery_access_share_token_schema_ready() ? 'access_share_token = ?, ' : '';
    $stmt = db()->prepare('UPDATE galleries SET ' . $shareColumn . 'access_token_hash = ?, access_token_expires_at = ?, updated_at = ? WHERE id = ?');
    $params = gallery_access_share_token_schema_ready()
        ? [$storedToken, hash('sha256', $token), $expiresAt, now_sql(), $galleryId]
        : [hash('sha256', $token), $expiresAt, now_sql(), $galleryId];
    $stmt->execute($params);
    return $token;
}

function revoke_gallery_share_token(int $galleryId): void
{
    $shareColumn = gallery_access_share_token_schema_ready() ? 'access_share_token = NULL, ' : '';
    $stmt = db()->prepare('UPDATE galleries SET ' . $shareColumn . 'access_token_hash = NULL, access_token_expires_at = NULL, updated_at = ? WHERE id = ?');
    $stmt->execute([now_sql(), $galleryId]);
}

function gallery_access_share_token_schema_ready(): bool
{
    try {
        $stmt = db()->query("SHOW COLUMNS FROM galleries LIKE 'access_share_token'");
        return $stmt && (bool) $stmt->fetch();
    } catch (PDOException) {
        return false;
    }
}

function gallery_share_token_for_admin(array $gallery): ?string
{
    $stored = (string) ($gallery['access_share_token'] ?? '');
    $hash = (string) ($gallery['access_token_hash'] ?? '');
    if ($stored === '' || $hash === '') {
        return null;
    }

    $token = decrypt_gallery_share_token($stored);
    if ($token !== null && hash_equals($hash, hash('sha256', $token))) {
        return $token;
    }

    if (hash_equals($hash, hash('sha256', $stored))) {
        $encrypted = encrypt_gallery_share_token($stored);
        if ($encrypted === null) {
            return null;
        }
        $stmt = db()->prepare('UPDATE galleries SET access_share_token = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$encrypted, now_sql(), (int) $gallery['id']]);
        return $stored;
    }

    return null;
}

function encrypt_gallery_share_token(string $token): ?string
{
    if (!function_exists('openssl_encrypt')) {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($token, 'aes-256-gcm', gallery_share_token_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($ciphertext === false || $tag === '') {
        return null;
    }
    return 'enc:v1:' . base64_encode($iv . $tag . $ciphertext);
}

function decrypt_gallery_share_token(string $stored): ?string
{
    if (!str_starts_with($stored, 'enc:v1:') || !function_exists('openssl_decrypt')) {
        return null;
    }
    $payload = base64_decode(substr($stored, 7), true);
    if ($payload === false || strlen($payload) <= 28) {
        return null;
    }
    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    $token = openssl_decrypt($ciphertext, 'aes-256-gcm', gallery_share_token_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $token === false ? null : $token;
}

function gallery_share_token_key(): string
{
    return hash('sha256', 'gallery-share-token|' . (string) cms_config()['visitor_vote_secret'], true);
}

