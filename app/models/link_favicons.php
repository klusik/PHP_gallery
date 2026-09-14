<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/link_favicons.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database persistence used by the external-link favicon cache.
 *
 * Responsibilities:
 *   - Load source and translated gallery descriptions for favicon discovery
 *   - Read favicon refresh and replacement metadata by hostname
 *   - Persist bounded favicon cache metadata
 *   - Resolve the cached favicon filename used by public rendering
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
 *   - Filesystem and network operations remain in the service layer.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use function Gallery\Core\db;

/** Return the source gallery description stored for one gallery. */
function link_favicons_model_gallery_description(int $galleryId): string
{
    $stmt = db()->prepare('SELECT description FROM galleries WHERE id = ?');
    $stmt->execute([$galleryId]);
    return (string) ($stmt->fetchColumn() ?: '');
}

/** Return translated descriptions stored for one gallery. */
function link_favicons_model_gallery_translation_descriptions(int $galleryId): array
{
    $stmt = db()->prepare('SELECT description FROM gallery_translations WHERE gallery_id = ? AND description IS NOT NULL');
    $stmt->execute([$galleryId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** Return refresh-state metadata for one cached hostname. */
function link_favicons_model_refresh_state(string $host): ?array
{
    $stmt = db()->prepare('SELECT status, icon_file, retry_after FROM link_favicon_cache WHERE hostname = ?');
    $stmt->execute([$host]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** Return the previous cache metadata needed to replace one hostname. */
function link_favicons_model_existing_row(string $host): ?array
{
    $stmt = db()->prepare('SELECT status, icon_file, mime_type, source_url, content_sha256, fetched_at FROM link_favicon_cache WHERE hostname = ?');
    $stmt->execute([$host]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

/** Persist one completed favicon-fetch attempt. */
function link_favicons_model_upsert(string $host, array $values): void
{
    $stmt = db()->prepare(
        'INSERT INTO link_favicon_cache (hostname, status, icon_file, mime_type, source_url, content_sha256, fetched_at, last_attempt_at, retry_after, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), icon_file = VALUES(icon_file), mime_type = VALUES(mime_type), source_url = VALUES(source_url), content_sha256 = VALUES(content_sha256), fetched_at = VALUES(fetched_at), last_attempt_at = VALUES(last_attempt_at), retry_after = VALUES(retry_after), updated_at = VALUES(updated_at)'
    );
    $stmt->execute([
        $host,
        (string) ($values['status'] ?? 'failed'),
        $values['icon_file'] ?? null,
        $values['mime_type'] ?? null,
        $values['source_url'] ?? null,
        $values['content_sha256'] ?? null,
        $values['fetched_at'] ?? null,
        (string) ($values['last_attempt_at'] ?? ''),
        (string) ($values['retry_after'] ?? ''),
        (string) ($values['updated_at'] ?? ''),
    ]);
}

/** Return the cached favicon filename available for public rendering. */
function link_favicons_model_public_file(string $host): ?string
{
    $stmt = db()->prepare("SELECT icon_file FROM link_favicon_cache WHERE hostname = ? AND status = 'ok' LIMIT 1");
    $stmt->execute([$host]);
    $file = $stmt->fetchColumn();
    return $file === false ? null : (string) $file;
}
