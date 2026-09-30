<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_content/policy.php
 * Module Type: Service
 * Purpose: Centralize anonymous source and per-photo authorization.
 * Responsibilities: Enforce explicit public cooperative grants without exposing system credentials.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;


/** Require verified image storage; incomplete schemas have no export compatibility path.
 * @return void Refuse missing or unknown image policy fields.
 */
function cooperative_content_schema_assert(): void
{
    $requirements = [];
    foreach (['id', 'gallery_id', 'filename', 'relative_path', 'visibility', 'nsfw_enabled'] as $column) {
        $requirements[] = schema_inspection_column('images', $column);
    }
    if (!schema_inspection_is_available(schema_inspection_feature('cooperative_photos', $requirements))) {
        throw new CooperativeException('content_unavailable');
    }
}

/** Check fresh photo ownership and anonymous policy without any session bypass.
 * @param array<string,mixed>|null $image Fresh model row.
 * @param int $galleryId Authorized local source.
 * @return bool Whether the direct public non-NSFW photo can be exported.
 */
function cooperative_content_image_allowed(?array $image, int $galleryId): bool
{
    return $image !== null && (int) ($image['gallery_id'] ?? 0) === $galleryId
        && ($image['visibility'] ?? '') === 'public' && in_array($image['nsfw_enabled'] ?? null, [0, '0'], true)
        && is_string($image['relative_path'] ?? null) && $image['relative_path'] !== ''
        && !str_contains($image['relative_path'], '/') && !str_contains($image['relative_path'], '\\')
        && !in_array($image['relative_path'], ['.', '..'], true);
}

/** Seal source-owned data for one purpose without disclosing local database identifiers.
 * @param array<string,mixed> $data Bounded ticket or cursor payload.
 * @param string $purpose Fixed internal purpose selected by the service.
 * @return string URL-safe authenticated ciphertext, never a system credential.
 */
function cooperative_content_seal(array $data, string $purpose): string
{
    return rtrim(strtr(base64_encode(security_secret_seal(json_encode($data, JSON_THROW_ON_ERROR),
        cooperative_storage_key(), 'cooperative-content:' . $purpose . ':' . cooperative_instance_id())), '+/', '-_'), '=');
}

/** Open bounded source-owned data with purpose separation.
 * @param string $value Opaque URL-safe value received from a browser or peer.
 * @param string $purpose Fixed expected internal purpose.
 * @return array<string,mixed> Authenticated source payload.
 */
function cooperative_content_open(string $value, string $purpose): array
{
    if (strlen($value) > 1600 || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
        throw new CooperativeException('content_unauthorized');
    }
    try {
        $envelope = base64_decode(strtr($value, '-_', '+/'), true);
        $plain = $envelope === false ? null : security_secret_open($envelope, cooperative_storage_key(),
            'cooperative-content:' . $purpose . ':' . cooperative_instance_id());
        $data = json_decode($plain ?? '', true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data)) { throw new \RuntimeException(); }
        return $data;
    } catch (\Throwable) { throw new CooperativeException('content_unauthorized'); }
}

/** Advance one bounded renewal step without granting or inventing administrator consent.
 * @param string $groupId Locally persisted and explicitly approved group.
 * @return array<string,mixed> Fresh aggregate; a missing lease remains unavailable to readers.
 */
function cooperative_content_progress(string $groupId): array
{
    $stored = cooperative_proposal_exchange_load($groupId);
    if (!cooperative_activation_lease_valid($stored['group'])
        || ($stored['group']['exchange']['lease']['expires_at'] ?? 0) - time() <= COOPERATIVE_RENEWAL_WINDOW) {
        $plan = cooperative_maintenance_plan($stored['group']);
        if (in_array($plan['action'], ['start', 'verify', 'finalize'], true)) {
            cooperative_maintenance_step($groupId, $stored['storage_revision']);
            $stored = cooperative_proposal_exchange_load($groupId);
        }
    }
    if (!cooperative_activation_lease_valid($stored['group'])
        && in_array(cooperative_maintenance_plan($stored['group'])['action'], ['blocked', 'waiting'], true)) {
        throw new CooperativeException('content_unavailable');
    }
    return $stored['group'];
}
