<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/flight_maps.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns durable flight-map and navigation-data persistence.
 *
 * Responsibilities:
 *   - Read, upsert, and delete gallery flight-map rows
 *   - Return bounded navdata aggregate status
 *   - Atomically upsert one trusted navdata snapshot and remove stale source rows
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
 *   - HTTP fetching, CSV parsing, route resolution, and presentation policy remain in services.
 */

declare(strict_types=1);

namespace Gallery\Models;

use Throwable;
use function Gallery\Core\db;

/**
 * Return one stored gallery flight-map row.
 *
 * @param int $galleryId Gallery identifier.
 * @return ?array Stored flight-map row, or null when none exists.
 */
function flight_maps_model_find(int $galleryId): ?array
{
    $stmt = db()->prepare('SELECT * FROM gallery_flight_maps WHERE gallery_id = ? LIMIT 1');
    $stmt->execute([$galleryId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Delete one gallery flight-map row.
 *
 * @param int $galleryId Gallery identifier.
 */
function flight_maps_model_delete(int $galleryId): void
{
    $stmt = db()->prepare('DELETE FROM gallery_flight_maps WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
}

/**
 * Persist one fully resolved gallery flight-map payload.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $sourceType Map source type.
 * @param string $routeText Human-readable route text.
 * @param array $points Resolved route points.
 * @param array $unresolved Unresolved route tokens.
 * @param string $now Current SQL timestamp.
 */
function flight_maps_model_upsert(int $galleryId, string $sourceType, string $routeText, array $points, array $unresolved, string $now, ?string $resolvedAt = null): void
{
    $stmt = db()->prepare("INSERT INTO gallery_flight_maps (
        gallery_id,
        map_source_type,
        route_text,
        resolved_points_json,
        unresolved_points_json,
        point_count,
        resolved_at,
        created_at,
        updated_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        map_source_type = VALUES(map_source_type),
        route_text = VALUES(route_text),
        resolved_points_json = VALUES(resolved_points_json),
        unresolved_points_json = VALUES(unresolved_points_json),
        point_count = VALUES(point_count),
        resolved_at = VALUES(resolved_at),
        updated_at = VALUES(updated_at)");
    $stmt->execute([
        $galleryId,
        $sourceType,
        $routeText,
        json_encode($points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode(array_values($unresolved), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        count($points),
        $resolvedAt !== null && trim($resolvedAt) !== '' ? $resolvedAt : $now,
        $now,
        $now,
    ]);
}

/**
 * Return aggregate status for imported navigation data.
 *
 * @return array{total:int,by_kind:array<string,int>,by_source:array<string,int>} Aggregate navdata status.
 */
function flight_maps_model_navdata_status(): array
{
    $total = (int) db()->query('SELECT COUNT(*) FROM flight_map_nav_points')->fetchColumn();
    $byKind = [];
    $stmt = db()->query('SELECT kind, COUNT(*) AS row_count FROM flight_map_nav_points GROUP BY kind ORDER BY kind');
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $byKind[(string) ($row['kind'] ?? 'unknown')] = (int) ($row['row_count'] ?? 0);
    }
    $bySource = [];
    $stmt = db()->query('SELECT source, COUNT(*) AS row_count FROM flight_map_nav_points GROUP BY source ORDER BY source');
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $source = trim((string) ($row['source'] ?? ''));
        $bySource[$source !== '' ? $source : 'manual'] = (int) ($row['row_count'] ?? 0);
    }
    return ['total' => $total, 'by_kind' => $byKind, 'by_source' => $bySource];
}

/**
 * Atomically persist one normalized navdata source snapshot.
 *
 * @param array<int,array{ident:string,kind:string,region:string,latitude:float,longitude:float,source:string,cycle:string,created_at:string,updated_at:string}> $rows
 * @return int Number of stale source rows deleted after the upsert pass.
 */
function flight_maps_model_replace_navdata(array $rows, string $source, string $now): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO flight_map_nav_points (
            ident,
            kind,
            region,
            latitude,
            longitude,
            source,
            cycle,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            latitude = VALUES(latitude),
            longitude = VALUES(longitude),
            source = VALUES(source),
            cycle = VALUES(cycle),
            updated_at = VALUES(updated_at)");
        foreach ($rows as $row) {
            $stmt->execute([
                $row['ident'],
                $row['kind'],
                $row['region'],
                $row['latitude'],
                $row['longitude'],
                $row['source'],
                $row['cycle'],
                $row['created_at'],
                $row['updated_at'],
            ]);
        }
        $deleteStmt = $pdo->prepare('DELETE FROM flight_map_nav_points WHERE source = ? AND updated_at <> ?');
        $deleteStmt->execute([$source, $now]);
        $deleted = (int) $deleteStmt->rowCount();
        $pdo->commit();
        return $deleted;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
