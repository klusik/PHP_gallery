<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/helpers_mutation.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Defines the canonical Admin mutation response contract shared by enhanced browser workflows.
 *
 * Responsibilities:
 *   - Detect JSON/AJAX Admin mutation requests consistently
 *   - Build typed mutation, panel, context, and postcondition metadata
 *   - Build canonical success and expected-error envelopes without changing service ownership
 *   - Preserve direct-page redirect metadata as fallback information only
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
 *   - The mutation envelope is intentionally typed around gallery workflows, not a generic browser command language.
 *
 * Last Updated:
 *   2026-09-02
 */

declare(strict_types=1);

namespace Gallery\Core;

/**
 * Return whether the current Admin mutation request expects a JSON response.
 *
 * Direct-page and non-JavaScript POSTs intentionally remain outside this branch.
 * Existing callers may continue using admin_wants_json() while controller-specific
 * request detection is migrated onto this single implementation.
 *
 * @return bool True when the request expects JSON/AJAX completion semantics.
 */
function admin_wants_json(): bool
{
    return !empty($_POST['ajax'])
        || !empty($_GET['ajax'])
        || !empty($_POST['panel'])
        || !empty($_GET['panel'])
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

/**
 * Build canonical typed metadata describing the persisted mutation itself.
 *
 * @param string $type Stable mutation type such as gallery.create or image.upload.
 * @param string $entity Stable entity family such as gallery or image.
 * @param string $action Stable action such as create, update, delete, or upload.
 * @param array<int, int|string> $entityIds Persisted stable entity identifiers.
 * @return array{type:string,entity:string,action:string,entity_ids:array<int,int>}
 */
function admin_mutation_descriptor(string $type, string $entity, string $action, array $entityIds = []): array
{
    $normalizedIds = [];
    foreach ($entityIds as $entityId) {
        $id = (int) $entityId;
        if ($id > 0 && !in_array($id, $normalizedIds, true)) {
            $normalizedIds[] = $id;
        }
    }

    return [
        'type' => trim($type),
        'entity' => trim($entity),
        'action' => trim($action),
        'entity_ids' => $normalizedIds,
    ];
}

/**
 * Build side-panel completion metadata for an enhanced Admin mutation.
 *
 * @param string $workflow Side-panel workflow that owns the refreshed fragment.
 * @param string $refreshUrl Server-render URL used to refresh that workflow.
 * @param bool $keepOpen Whether the drawer remains mounted after persistence.
 * @return array{workflow:string,refresh_url:string,keep_open:bool}
 */
function admin_mutation_panel_metadata(string $workflow, string $refreshUrl = '', bool $keepOpen = true): array
{
    return [
        'workflow' => trim($workflow),
        'refresh_url' => trim($refreshUrl),
        'keep_open' => $keepOpen,
    ];
}

/**
 * Build one typed observable postcondition used to verify a refreshed public context.
 *
 * The supported vocabulary is deliberately small. Add a new type only when a real
 * persisted mutation requires a new observable server-rendered invariant.
 *
 * @param string $type Supported postcondition type.
 * @param array<string, mixed> $data Type-specific values.
 * @return array<string, mixed>
 */
function admin_mutation_postcondition(string $type, array $data = []): array
{
    $type = trim($type);
    $supportedTypes = [
        'gallery_present',
        'gallery_absent',
        'gallery_membership',
        'gallery_identity',
        'image_present',
        'image_absent',
        'image_order',
        'image_updated_at',
        'cover_image',
        'gallery_visibility',
        'gallery_updated_at',
        'image_visibility',
        'image_nsfw',
        'gallery_image_count',
        'gallery_image_revision',
        'smart_gallery_presence',
        'tag_identity',
    ];
    if (!in_array($type, $supportedTypes, true)) {
        throw new \InvalidArgumentException('Unsupported mutation postcondition type.');
    }

    $postcondition = ['type' => $type];
    if (isset($data['gallery_id'])) {
        $galleryId = (int) $data['gallery_id'];
        if ($galleryId > 0) {
            $postcondition['gallery_id'] = $galleryId;
        }
    }
    if (isset($data['tag_id'])) {
        $tagId = (int) $data['tag_id'];
        if ($tagId > 0) {
            $postcondition['tag_id'] = $tagId;
        }
    }
    if (isset($data['smart_gallery_id'])) {
        $smartGalleryId = (int) $data['smart_gallery_id'];
        if ($smartGalleryId > 0) {
            $postcondition['smart_gallery_id'] = $smartGalleryId;
        }
    }
    if (array_key_exists('image_id', $data)) {
        $imageId = (int) $data['image_id'];
        if ($imageId > 0 || $type === 'cover_image') {
            $postcondition['image_id'] = max(0, $imageId);
        }
    }
    if (isset($data['image_ids']) && is_array($data['image_ids'])) {
        $imageIds = [];
        foreach ($data['image_ids'] as $imageId) {
            $normalizedImageId = (int) $imageId;
            if ($normalizedImageId > 0 && !in_array($normalizedImageId, $imageIds, true)) {
                $imageIds[] = $normalizedImageId;
            }
        }
        $postcondition['image_ids'] = $imageIds;
    }

    if (isset($data['visibility'])) {
        $visibility = trim((string) $data['visibility']);
        if ($visibility !== '') {
            $postcondition['visibility'] = $visibility;
        }
    }
    if (isset($data['updated_at'])) {
        $updatedAt = trim((string) $data['updated_at']);
        if ($updatedAt !== '') {
            $postcondition['updated_at'] = $updatedAt;
        }
    }
    if (isset($data['revision'])) {
        $revision = trim((string) $data['revision']);
        if ($revision !== '') {
            $postcondition['revision'] = $revision;
        }
    }
    if (array_key_exists('enabled', $data)) {
        $postcondition['enabled'] = !empty($data['enabled']);
    }
    if (array_key_exists('present', $data)) {
        $postcondition['present'] = !empty($data['present']);
    }
    if (array_key_exists('count', $data)) {
        $postcondition['count'] = max(0, (int) $data['count']);
    }
    if (isset($data['placement'])) {
        $placement = trim((string) $data['placement']);
        if (in_array($placement, ['top', 'bottom'], true)) {
            $postcondition['placement'] = $placement;
        }
    }
    if (array_key_exists('placement_order', $data)) {
        $postcondition['placement_order'] = max(0, (int) $data['placement_order']);
    }

    return $postcondition;
}

/**
 * Count physical gallery cards owned by one Admin-visible public context.
 *
 * Nested gallery pages are rendered with Admin visibility while the side panel is
 * available, so every direct physical child belongs to that context. The root
 * gallery index intentionally remains the public listed root set for every viewer.
 * A direct database count avoids request-local child-gallery caches becoming stale
 * after create/reparent/delete mutations in the same request.
 *
 * @param int $parentGalleryId Stable parent gallery id, or zero for the root index.
 * @return int Full physical-gallery count for the rendered context.
 */
function admin_mutation_gallery_context_count(int $parentGalleryId): int
{
    if ($parentGalleryId > 0) {
        $stmt = db()->prepare('SELECT COUNT(*) FROM galleries WHERE parent_id = ?');
        $stmt->execute([$parentGalleryId]);
        return max(0, (int) $stmt->fetchColumn());
    }

    $listingCondition = \Gallery\Services\public_gallery_listing_sql_fragment('g');
    $stmt = db()->query('SELECT COUNT(*) FROM galleries g WHERE g.parent_id IS NULL AND ' . $listingCondition);
    return max(0, (int) $stmt->fetchColumn());
}

/**
 * Return whether a gallery row is expected to render as a physical card in one context.
 *
 * @param array<string, mixed> $gallery Persisted gallery row after the mutation.
 * @param int $parentGalleryId Stable parent gallery id, or zero for the root index.
 * @return bool True when the card belongs to the rendered context.
 */
function admin_mutation_gallery_is_rendered_in_context(array $gallery, int $parentGalleryId): bool
{
    $actualParentId = max(0, (int) ($gallery['parent_id'] ?? 0));
    if ($parentGalleryId > 0) {
        return $actualParentId === $parentGalleryId;
    }
    return $actualParentId === 0 && \Gallery\Services\gallery_is_public_listed($gallery);
}

/**
 * Build a pagination-safe physical gallery membership postcondition.
 *
 * The full server-side context count prevents an off-page target card from causing
 * false negatives while still rejecting a stale response whose membership count is
 * from before the successful mutation.
 *
 * @param int $galleryId Stable physical gallery id.
 * @param int $parentGalleryId Stable parent id, or zero for the root index.
 * @param bool $present Whether the target card should belong to this context.
 * @return array<string, mixed> Canonical gallery_membership postcondition.
 */
function admin_mutation_gallery_membership_postcondition(int $galleryId, int $parentGalleryId, bool $present): array
{
    return admin_mutation_postcondition('gallery_membership', [
        'gallery_id' => $galleryId,
        'present' => $present,
        'count' => admin_mutation_gallery_context_count($parentGalleryId),
    ]);
}

/**
 * Build metadata for one affected public gallery render context.
 *
 * A positive gallery id identifies a concrete gallery by stable identity. Gallery
 * id zero represents the root gallery index, which has no persisted gallery row.
 *
 * @param int $galleryId Stable gallery id, or zero for the root gallery index.
 * @param string $renderUrl Authoritative server-render URL for this context.
 * @param ?array<string, mixed> $postcondition Optional observable completion invariant.
 * @param string $renderMode preserve_view keeps pagination/filter state; canonical forces render_url.
 * @return array<string, mixed>
 */
function admin_mutation_public_gallery_context(int $galleryId, string $renderUrl, ?array $postcondition = null, string $renderMode = 'preserve_view'): array
{
    $normalizedRenderMode = $renderMode === 'canonical' ? 'canonical' : 'preserve_view';
    $context = [
        'type' => $galleryId > 0 ? 'gallery' : 'gallery_index',
        'gallery_id' => $galleryId > 0 ? $galleryId : null,
        'render_url' => trim($renderUrl),
        'render_mode' => $normalizedRenderMode,
        'postcondition' => $postcondition,
    ];
    return $context;
}

/**
 * Build metadata for one affected public tag landing-page render context.
 *
 * Tag identity remains stable across slug changes. The browser may therefore fetch
 * the new canonical tag URL while leaving the visible browser URL unchanged.
 *
 * @param int $tagId Stable tag id.
 * @param string $renderUrl Authoritative server-render URL for this context.
 * @param ?array<string, mixed> $postcondition Optional observable completion invariant.
 * @param string $renderMode preserve_view keeps the current URL; canonical forces render_url.
 * @return array<string, mixed>
 */
function admin_mutation_public_tag_context(int $tagId, string $renderUrl, ?array $postcondition = null, string $renderMode = 'preserve_view'): array
{
    $normalizedRenderMode = $renderMode === 'canonical' ? 'canonical' : 'preserve_view';
    $context = [
        'type' => 'tag',
        'tag_id' => $tagId > 0 ? $tagId : null,
        'render_url' => trim($renderUrl),
        'render_mode' => $normalizedRenderMode,
        'postcondition' => $postcondition,
    ];
    return $context;
}

/**
 * Build the canonical success envelope used by enhanced Admin mutation workflows.
 *
 * @param string $message Human-readable success message.
 * @param array<string, mixed> $mutation Typed mutation descriptor.
 * @param ?array<string, mixed> $panel Side-panel refresh metadata.
 * @param array<int, array<string, mixed>> $contexts Affected public render contexts.
 * @param array<string, mixed> $fallback Direct-page fallback metadata such as redirect_url.
 * @return array<string, mixed>
 */
function admin_mutation_success_envelope(string $message, array $mutation, ?array $panel = null, array $contexts = [], array $fallback = []): array
{
    return [
        'ok' => true,
        'message' => $message,
        'mutation' => $mutation,
        'panel' => $panel,
        'contexts' => array_values($contexts),
        'fallback' => $fallback,
    ];
}

/**
 * Build the canonical expected-error envelope used by enhanced Admin mutation workflows.
 *
 * The top-level error string is an intentional human-readable alias used by existing
 * AJAX error surfaces, while error_code provides a stable machine-readable category.
 *
 * @param string $message Safe human-readable error message.
 * @param string $errorCode Stable bounded error category.
 * @param ?array<string, mixed> $mutation Optional mutation descriptor when known.
 * @param array<string, mixed> $fallback Direct-page fallback metadata when useful.
 * @return array<string, mixed>
 */
function admin_mutation_error_envelope(string $message, string $errorCode = 'mutation_failed', ?array $mutation = null, array $fallback = []): array
{
    $normalizedErrorCode = strtolower(trim($errorCode));
    $normalizedErrorCode = preg_replace('/[^a-z0-9_.-]+/', '_', $normalizedErrorCode) ?? '';
    $normalizedErrorCode = trim($normalizedErrorCode, '._-');
    if ($normalizedErrorCode === '') {
        $normalizedErrorCode = 'mutation_failed';
    }

    return [
        'ok' => false,
        'message' => $message,
        'error' => $message,
        'error_code' => $normalizedErrorCode,
        'mutation' => $mutation,
        'panel' => null,
        'contexts' => [],
        'fallback' => $fallback,
    ];
}
