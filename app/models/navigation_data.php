<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/navigation_data.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns persistence for hybrid flight-navigation data and per-admin Navigraph sessions.
 *
 * Responsibilities:
 *   - Read aggregate cache and local-navigation status
 *   - Resolve local and cached navigation-data rows
 *   - Persist shared remote navigation-data cache entries
 *   - Read, upsert, and delete per-admin Navigraph account rows
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
 *   - OAuth, encryption, provider policy, and point normalization remain in services.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Models;

use function Gallery\Core\db;

/**
 * Return aggregate status for the shared remote navigation cache.
 *
 * @return array{count:int,last_update:string}.
 */
function navigation_data_model_cache_status(): array
{
    return [
        'count' => (int) db()->query('SELECT COUNT(*) FROM navigation_data_cache')->fetchColumn(),
        'last_update' => (string) (db()->query('SELECT MAX(updated_at) FROM navigation_data_cache')->fetchColumn() ?: ''),
    ];
}

/** Return the number of imported local navigation points. */
function navigation_data_model_local_point_count(): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM flight_map_nav_points');
    $stmt->execute();
    return (int) $stmt->fetchColumn();
}

/** Return the preferred imported local navigation point for one identifier. */
function navigation_data_model_local_point(string $ident): ?array
{
    $stmt = db()->prepare("SELECT ident, kind, region, latitude, longitude, source, cycle FROM flight_map_nav_points
        WHERE ident = ?
        ORDER BY CASE kind
            WHEN 'airport' THEN 0
            WHEN 'vor' THEN 1
            WHEN 'navaid' THEN 2
            WHEN 'ndb' THEN 3
            WHEN 'fix' THEN 4
            WHEN 'waypoint' THEN 5
            ELSE 6
        END, id
        LIMIT 1");
    $stmt->execute([$ident]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Return one non-expired cached remote navigation point. */
function navigation_data_model_cached_point(string $ident, string $now): ?array
{
    $stmt = db()->prepare("SELECT payload_json, source, cycle FROM navigation_data_cache
        WHERE ident = ? AND (expires_at IS NULL OR expires_at >= ?)
        ORDER BY CASE source WHEN 'navigraph' THEN 0 ELSE 1 END, updated_at DESC
        LIMIT 1");
    $stmt->execute([$ident, $now]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Persist one normalized shared remote navigation-data cache entry. */
function navigation_data_model_cache_upsert(
    string $cacheKey,
    string $ident,
    string $kind,
    string $source,
    string $cycle,
    string $payloadJson,
    string $expiresAt,
    string $now
): void {
    $stmt = db()->prepare("INSERT INTO navigation_data_cache (
        cache_key,
        ident,
        kind,
        source,
        cycle,
        payload_json,
        expires_at,
        created_at,
        updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        kind = VALUES(kind),
        source = VALUES(source),
        cycle = VALUES(cycle),
        payload_json = VALUES(payload_json),
        expires_at = VALUES(expires_at),
        updated_at = VALUES(updated_at)");
    $stmt->execute([$cacheKey, $ident, $kind, $source, $cycle, $payloadJson, $expiresAt, $now, $now]);
}

/** Return whether one persistent Navigraph account row exists. */
function navigation_data_model_account_exists(int $userId): bool
{
    $stmt = db()->prepare("SELECT 1 FROM navigation_data_accounts WHERE user_id = ? AND provider = 'navigraph' LIMIT 1");
    $stmt->execute([$userId]);
    return (bool) $stmt->fetchColumn();
}

/** Return one persistent Navigraph account row. */
function navigation_data_model_account(int $userId): ?array
{
    $stmt = db()->prepare("SELECT * FROM navigation_data_accounts WHERE user_id = ? AND provider = 'navigraph' LIMIT 1");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/** Persist encrypted Navigraph credentials and provider metadata. */
function navigation_data_model_account_upsert(int $userId, array $values, string $now): void
{
    $stmt = db()->prepare("INSERT INTO navigation_data_accounts (
        user_id,
        provider,
        access_token_cipher,
        refresh_token_cipher,
        id_token_cipher,
        token_expires_at,
        scope_text,
        claims_json,
        subscription_json,
        package_cycle,
        package_status,
        package_format,
        package_checked_at,
        connected_at,
        updated_at
    ) VALUES (?, 'navigraph', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        access_token_cipher = VALUES(access_token_cipher),
        refresh_token_cipher = VALUES(refresh_token_cipher),
        id_token_cipher = VALUES(id_token_cipher),
        token_expires_at = VALUES(token_expires_at),
        scope_text = VALUES(scope_text),
        claims_json = VALUES(claims_json),
        subscription_json = VALUES(subscription_json),
        package_cycle = VALUES(package_cycle),
        package_status = VALUES(package_status),
        package_format = VALUES(package_format),
        package_checked_at = VALUES(package_checked_at),
        updated_at = VALUES(updated_at)");
    $stmt->execute([
        $userId,
        (string) ($values['access_token_cipher'] ?? ''),
        (string) ($values['refresh_token_cipher'] ?? ''),
        (string) ($values['id_token_cipher'] ?? ''),
        max(0, (int) ($values['token_expires_at'] ?? 0)),
        (string) ($values['scope_text'] ?? ''),
        (string) ($values['claims_json'] ?? '{}'),
        $values['subscription_json'] ?? null,
        (string) ($values['package_cycle'] ?? ''),
        (string) ($values['package_status'] ?? ''),
        (string) ($values['package_format'] ?? ''),
        $values['package_checked_at'] ?? null,
        $now,
        $now,
    ]);
}

/** Delete one persistent Navigraph account row. */
function navigation_data_model_account_delete(int $userId): void
{
    $stmt = db()->prepare("DELETE FROM navigation_data_accounts WHERE user_id = ? AND provider = 'navigraph'");
    $stmt->execute([$userId]);
}
