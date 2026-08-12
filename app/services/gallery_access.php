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
 *   - Preserve explicit three-state policy for access, visibility, NSFW, and share tokens
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

use RuntimeException;

use function Gallery\Core\cms_config;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

/**
 * Gallery access model.
 * 
 * This module owns password protection, share token handling, visitor access checks, and public listing rules for galleries. It does not alter theme or admin styling settings.
 */


/**
 * Base exception for public access policy that cannot be verified safely.
 *
 * The exception exposes only a stable feature key. Database exception messages,
 * SQL text, credentials, paths, tokens, and other private state never cross the
 * controller boundary. The dispatcher converts this exception into the generic
 * route-appropriate 503 response used by protected public requests.
 */
class PublicSchemaPolicyUnavailableException extends RuntimeException
{
    private string $feature;
    private string $schemaState;
    private string $errorCode;

    public function __construct(string $feature, string $schemaState = 'unknown', string $errorCode = 'inspection_failed')
    {
        $this->feature = preg_match('/^[A-Za-z0-9_.-]{1,120}$/D', $feature) === 1 ? $feature : 'public_schema';
        $this->schemaState = in_array($schemaState, ['missing', 'unknown'], true) ? $schemaState : 'unknown';
        $this->errorCode = preg_match('/^[a-z0-9_]{1,80}$/D', $errorCode) === 1 ? $errorCode : 'inspection_failed';
        parent::__construct('Public schema policy is unavailable for ' . $this->feature . '.');
    }

    /**
     * Return the stable feature key used by bounded logging and diagnostics.
     *
     * @return string Feature identifier.
     */
    public function feature(): string
    {
        return $this->feature;
    }

    /**
     * Return the bounded schema state without exposing database diagnostics.
     *
     * @return string Either missing or unknown.
     */
    public function schemaState(): string
    {
        return $this->schemaState;
    }

    /**
     * Return the bounded operational reason used by protected-request logging.
     *
     * @return string Stable machine-readable reason.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

/**
 * Signal that gallery password/access policy cannot be verified safely.
 */
final class GalleryAccessSchemaUnavailableException extends PublicSchemaPolicyUnavailableException
{
    public function __construct(string $schemaState = 'unknown', string $errorCode = 'inspection_failed')
    {
        parent::__construct('gallery_access', $schemaState, $errorCode);
    }
}

/**
 * Signal that gallery visibility compatibility cannot be verified safely.
 */
final class GalleryVisibilitySchemaUnavailableException extends PublicSchemaPolicyUnavailableException
{
    public function __construct()
    {
        parent::__construct('gallery_visibility');
    }
}

/**
 * Signal that share-token schema policy cannot be verified safely.
 */
final class GalleryShareTokenSchemaUnavailableException extends PublicSchemaPolicyUnavailableException
{
    public function __construct()
    {
        parent::__construct('gallery_share_token');
    }
}

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
    $status = gallery_visibility_schema_status();
    if (schema_inspection_is_unknown($status)) {
        throw new GalleryVisibilitySchemaUnavailableException();
    }
    if ($visibility === 'unpublished' && schema_inspection_is_missing($status)) {
        // A successful inspection that lacks the unpublished enum value is the
        // proven pre-migration vocabulary. Store the historical draft value.
        return 'draft';
    }
    return $visibility;
}

/**
 * Inspect whether galleries.visibility supports the canonical unpublished value.
 *
 * Confirmed absence means the installation uses the historical draft vocabulary.
 * Unknown means the application could not inspect the enum definition and must
 * not guess which value a public or mutation path should use.
 *
 * @return array{state:string,feature:string,requirements:array} Aggregate schema status.
 */
function gallery_visibility_schema_status(): array
{
    return schema_inspection_feature('gallery_visibility', [
        schema_inspection_column_definition_contains('galleries', 'visibility', 'unpublished'),
    ]);
}

/**
 * Return true when the current galleries.visibility enum accepts unpublished.
 *
 * This compatibility wrapper is retained for audited callers that only need a
 * boolean after policy has already been established. New security-sensitive
 * callers should consume gallery_visibility_schema_status().
 *
 * @return bool True when the condition matches.
 */
function gallery_visibility_schema_supports_unpublished(): bool
{
    return schema_inspection_is_available(gallery_visibility_schema_status());
}

/**
 * Refuse public visibility decisions when the enum definition cannot be inspected.
 *
 * Confirmed missing support is intentionally compatible: legacy draft values are
 * normalized to unpublished by normalize_gallery_visibility().
 */
function gallery_visibility_assert_public_policy_available(): void
{
    if (schema_inspection_is_unknown(gallery_visibility_schema_status())) {
        throw new GalleryVisibilitySchemaUnavailableException();
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
    gallery_visibility_assert_public_policy_available();
    return in_array(gallery_effective_visibility($gallery), ['public', 'unpublished'], true);
}

/**
 * Return the legacy aggregate Admin-readiness boolean.
 *
 * This compatibility helper is intentionally retained for older UI callers only.
 * It must not be used for security decisions, optional-feature writes, or mutation
 * authorization because it collapses unrelated capabilities into one boolean. Use
 * the exact named security, mutation, or presentation schema status instead.
 *
 * @return bool True only when all historical aggregate capabilities are available.
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
function gallery_access_schema_status(): array
{
    return schema_inspection_feature('gallery_access', [
        schema_inspection_column('galleries', 'access_mode'),
        schema_inspection_column('galleries', 'access_listing'),
        schema_inspection_column('galleries', 'access_password_hash'),
        schema_inspection_column('galleries', 'access_token_hash'),
        schema_inspection_column('galleries', 'access_token_expires_at'),
    ]);
}

/**
 * Return true only when every gallery password/access column is verified.
 *
 * @return bool True when the complete capability is available.
 */
function gallery_access_schema_ready(): bool
{
    return schema_inspection_is_available(gallery_access_schema_status());
}

/**
 * Return true only for the proven pre-password-protection schema.
 *
 * A partially applied migration is intentionally not considered legacy. If one
 * access column exists while another is missing, rows may already contain
 * protection state and public requests must not substitute permissive defaults.
 *
 * @param ?array $status Optional previously inspected aggregate status.
 * @return bool True when every required access column is confirmed absent.
 */
function gallery_access_schema_is_confirmed_legacy(?array $status = null): bool
{
    $status = $status ?? gallery_access_schema_status();
    if (!schema_inspection_is_missing($status)) {
        return false;
    }
    $requirements = (array) ($status['requirements'] ?? []);
    if ($requirements === []) {
        return false;
    }
    foreach ($requirements as $requirement) {
        if (!is_array($requirement) || !schema_inspection_is_missing($requirement)) {
            return false;
        }
    }
    return true;
}

/**
 * Refuse public gallery access decisions for unknown or partial schema state.
 *
 * Complete current schema preserves existing password/token behavior. Complete
 * confirmed legacy schema preserves the historical no-password path because it
 * could not have stored gallery access restrictions. Unknown and partial schema
 * fail closed.
 */
function gallery_access_assert_public_policy_available(): void
{
    $status = gallery_access_schema_status();
    if (schema_inspection_is_unknown($status)) {
        throw new GalleryAccessSchemaUnavailableException();
    }
    if (schema_inspection_is_missing($status) && !gallery_access_schema_is_confirmed_legacy($status)) {
        throw new GalleryAccessSchemaUnavailableException('missing', 'partial_schema');
    }
}


/**
 * Signal that NSFW Guard cannot make a safe public access decision.
 *
 * The exception carries no database message or private context. The central
 * request dispatcher converts it into the route-appropriate generic 503
 * response, while Admin System Health exposes bounded diagnostic guidance.
 */
final class NsfwGuardSchemaUnavailableException extends PublicSchemaPolicyUnavailableException
{
    public function __construct()
    {
        parent::__construct('nsfw_guard');
    }
}

/**
 * Inspect every database column required by NSFW Guard.
 *
 * The aggregate preserves the distinction between a confirmed pre-migration
 * schema and an operational inspection failure. Results are request-cached by
 * the shared schema inspection service.
 *
 * @return array{state:string,feature:string,requirements:array} Aggregate schema status.
 */
function nsfw_guard_schema_status(): array
{
    return schema_inspection_feature('nsfw_guard', [
        schema_inspection_column('galleries', 'nsfw_enabled'),
        schema_inspection_column('images', 'nsfw_enabled'),
    ]);
}

/**
 * Return true only when the complete NSFW Guard schema was verified.
 *
 * This compatibility predicate remains useful for Admin control visibility.
 * Public access decisions use the full status through
 * nsfw_guard_assert_public_policy_available().
 *
 * @return bool True when every required column is available.
 */
function nsfw_guard_schema_ready(): bool
{
    return schema_inspection_is_available(nsfw_guard_schema_status());
}

/**
 * Refuse public NSFW-sensitive processing when schema state is unknown.
 *
 * Confirmed missing columns preserve the historical pre-NSFW compatibility
 * path because such schemas could not have stored NSFW flags. Unknown means
 * the application could not establish whether restrictions exist and must
 * therefore fail closed.
 */
function nsfw_guard_assert_public_policy_available(): void
{
    if (schema_inspection_is_unknown(nsfw_guard_schema_status())) {
        throw new NsfwGuardSchemaUnavailableException();
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
    nsfw_guard_assert_public_policy_available();
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
    nsfw_guard_assert_public_policy_available();
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
    gallery_access_assert_public_policy_available();
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
    gallery_access_assert_public_policy_available();
    if (!gallery_access_schema_ready()) {
        // Only the fully confirmed legacy schema can reach this compatibility path.
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
    // Share-token use is optional, but it must not interpret an unavailable or
    // pre-migration persistence capability as a valid protected-token path.
    $shareSchemaStatus = gallery_access_share_token_schema_status();
    if (schema_inspection_is_unknown($shareSchemaStatus)) {
        throw new GalleryShareTokenSchemaUnavailableException();
    }
    if (schema_inspection_is_missing($shareSchemaStatus)) {
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
    gallery_visibility_assert_public_policy_available();
    gallery_access_assert_public_policy_available();
    if (gallery_effective_visibility($gallery) !== 'public') {
        return false;
    }
    if (!gallery_access_schema_ready()) {
        // Fully confirmed legacy schemas had no access_listing column.
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
    gallery_share_token_assert_mutation_available();
    // $token stores an intermediate value used by the surrounding gallery workflow.
    $token = bin2hex(random_bytes(24));
    // $storedToken stores an intermediate value used by the surrounding gallery workflow.
    $storedToken = encrypt_gallery_share_token($token);
    // $stmt stores an intermediate value used by the surrounding gallery workflow.
    $stmt = db()->prepare('UPDATE galleries SET access_share_token = ?, access_token_hash = ?, access_token_expires_at = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$storedToken, hash('sha256', $token), $expiresAt, now_sql(), $galleryId]);
    return $token;
}

/**
 * Handles revoke gallery share token logic for the gallery application.
 *
 * @param mixed $galleryId Input used by this operation.
 */
function revoke_gallery_share_token(int $galleryId): void
{
    // Revocation is security-tightening. Once the core hash/expiry columns are
    // verified, they can always be cleared even when the optional encrypted
    // display-token column is confirmed missing or cannot be inspected.
    $accessStatus = gallery_access_schema_status();
    if (!schema_inspection_is_available($accessStatus)) {
        if (schema_inspection_is_unknown($accessStatus)) {
            throw new GalleryAccessSchemaUnavailableException();
        }
        throw new RuntimeException('Gallery password/access schema is not ready for share-token revocation.');
    }

    $shareStatus = gallery_access_share_token_schema_status();
    if (schema_inspection_is_available($shareStatus)) {
        // $stmt stores an intermediate value used by the surrounding gallery workflow.
        $stmt = db()->prepare('UPDATE galleries SET access_share_token = NULL, access_token_hash = NULL, access_token_expires_at = NULL, updated_at = ? WHERE id = ?');
    } else {
        // Clearing the validating hash is sufficient to revoke every copy of the token.
        $stmt = db()->prepare('UPDATE galleries SET access_token_hash = NULL, access_token_expires_at = NULL, updated_at = ? WHERE id = ?');
    }
    $stmt->execute([now_sql(), $galleryId]);
}

/**
 * Handles gallery access share token schema ready logic for the gallery application.
 *
 * @return mixed Result produced by this operation.
 */
function gallery_access_share_token_schema_status(): array
{
    return schema_inspection_feature('gallery_share_token', [
        schema_inspection_column('galleries', 'access_share_token'),
    ]);
}

/**
 * Return true when encrypted share-token persistence is verified.
 *
 * @return bool True when the column is available.
 */
function gallery_access_share_token_schema_ready(): bool
{
    return schema_inspection_is_available(gallery_access_share_token_schema_status());
}

/**
 * Require the share-token persistence column before token generation.
 *
 * Confirmed missing schema receives migration guidance rather than silently
 * issuing a hash-only link. Unknown schema is an operational failure. Revocation
 * has a separate security-tightening path that can always clear the verified
 * validating hash once the core access schema is available.
 */
function gallery_share_token_assert_mutation_available(): void
{
    $accessStatus = gallery_access_schema_status();
    if (schema_inspection_is_unknown($accessStatus)) {
        throw new GalleryAccessSchemaUnavailableException();
    }
    if (!schema_inspection_is_available($accessStatus)) {
        throw new RuntimeException('Gallery password/access schema is not ready for share-token mutation.');
    }
    $status = gallery_access_share_token_schema_status();
    if (schema_inspection_is_unknown($status)) {
        throw new GalleryShareTokenSchemaUnavailableException();
    }
    if (schema_inspection_is_missing($status)) {
        throw new RuntimeException('Gallery share-token migration has not been applied.');
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
    $status = gallery_access_share_token_schema_status();
    if (schema_inspection_is_unknown($status)) {
        throw new GalleryShareTokenSchemaUnavailableException();
    }
    if (!schema_inspection_is_available($status)) {
        return null;
    }
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

