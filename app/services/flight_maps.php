<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/flight_maps.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides reusable application logic for route-based flight maps.
 *
 * Responsibilities:
 *   - Store one resolved flight path per gallery container
 *   - Resolve route text during admin save, not during public display
 *   - Return already-resolved map payloads for the shared Leaflet renderer
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
 *   2026-05-21
 */

declare(strict_types=1);

namespace Gallery\Services;

use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;

const GALLERY_MAP_SOURCE_EXIF_POINT = 'exif_point';
const GALLERY_MAP_SOURCE_FLIGHT_PATH = 'flight_path';
const FLIGHT_MAP_NAVDATA_SOURCE_OURAIRPORTS = 'ourairports';
const FLIGHT_MAP_NAVDATA_URL_AIRPORTS = 'https://davidmegginson.github.io/ourairports-data/airports.csv';
const FLIGHT_MAP_NAVDATA_URL_NAVAIDS = 'https://davidmegginson.github.io/ourairports-data/navaids.csv';

/**
 * Return whether the flight map storage migration has been applied.
 *
 * The public site can run before migrations are applied, so callers use this
 * guard instead of assuming that route-map tables already exist.
 *
 * @return bool True when the condition matches.
 */
function flight_map_schema_ready(): bool
{
    return presentation_schema_render_available(presentation_flight_map_schema_status(), 'flight_map_render');
}

/**
 * Return whether optional admin-time nav point lookup storage is available.
 *
 * @return bool True when the condition matches.
 */
function flight_map_navdata_schema_ready(): bool
{
    return presentation_schema_render_available(presentation_flight_navdata_schema_status(), 'flight_navdata_render');
}

/**
 * Return the supported map source identifiers.
 *
 * @return array Structured result data for the caller.
 */
function gallery_map_source_types(): array
{
    return [GALLERY_MAP_SOURCE_EXIF_POINT, GALLERY_MAP_SOURCE_FLIGHT_PATH];
}

/**
 * Fetch the stored route map row for one gallery.
 *
 * @param int $galleryId Gallery identifier.
 * @return ?array Structured result data for the caller.
 */
function gallery_flight_map_row(int $galleryId): ?array
{
    if ($galleryId <= 0 || !flight_map_schema_ready()) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM gallery_flight_maps WHERE gallery_id = ? LIMIT 1');
    $stmt->execute([$galleryId]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Return true when one gallery has a usable stored flight path.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return bool True when the condition matches.
 */
function gallery_has_flight_path_map(array $gallery): bool
{
    $row = gallery_flight_map_row((int) ($gallery['id'] ?? 0));
    if ($row === null) {
        return false;
    }

    if ((int) ($row['point_count'] ?? 0) > 0) {
        return true;
    }

    // Some early route-map rows may have valid JSON with an empty legacy count.
    // Check stored points defensively so the public viewer exposes the gallery
    // map as soon as at least one resolved coordinate exists.
    return count(gallery_flight_map_points_from_row($row)) > 0;
}

/**
 * Delete the route map assigned to one gallery.
 *
 * @param int $galleryId Gallery identifier.
 */
function delete_gallery_flight_path_map(int $galleryId): void
{
    if ($galleryId <= 0) {
        return;
    }
    $schemaStatus = presentation_flight_map_schema_status();
    presentation_schema_assert_known($schemaStatus, 'flight_map_delete');
    if (!schema_inspection_is_available($schemaStatus)) {
        return;
    }
    $stmt = db()->prepare('DELETE FROM gallery_flight_maps WHERE gallery_id = ?');
    $stmt->execute([$galleryId]);
    flight_map_clear_runtime_cache();
}

/**
 * Persist a gallery route after resolving all route points once.
 *
 * Tokens that cannot be resolved are saved separately for the admin summary,
 * but they are not sent to the public renderer. The displayed route therefore
 * only contains valid coordinates and can be reused without AIRAC lookup.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $routeText Route text value.
 * @return array Structured result data for the caller.
 */
function save_gallery_flight_path_route(int $galleryId, string $routeText): array
{
    if ($galleryId <= 0) {
        return ['point_count' => 0, 'unresolved_count' => 0, 'points' => [], 'unresolved' => []];
    }
    $schemaStatus = presentation_flight_map_schema_status();
    presentation_schema_assert_known($schemaStatus, 'flight_map_save');
    if (!schema_inspection_is_available($schemaStatus)) {
        return ['point_count' => 0, 'unresolved_count' => 0, 'points' => [], 'unresolved' => []];
    }

    $routeText = trim($routeText);
    if ($routeText === '') {
        delete_gallery_flight_path_map($galleryId);
        return ['point_count' => 0, 'unresolved_count' => 0, 'points' => [], 'unresolved' => []];
    }

    $preserved = flight_map_preserve_existing_ofp_route($galleryId, $routeText);
    if ($preserved !== null) {
        return $preserved;
    }

    $resolved = resolve_flight_route_text($routeText);
    $points = $resolved['points'];
    $unresolved = $resolved['unresolved'];
    $now = now_sql();

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
        GALLERY_MAP_SOURCE_FLIGHT_PATH,
        $routeText,
        json_encode($points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode($unresolved, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        count($points),
        $now,
        $now,
        $now,
    ]);

    flight_map_clear_runtime_cache();

    return [
        'point_count' => count($points),
        'unresolved_count' => count($unresolved),
        'points' => $points,
        'unresolved' => $unresolved,
    ];
}

/**
 * Keep SimBrief OFP geometry when the visible route text was not changed.
 *
 * The SimBrief generator writes a human-readable point list to the editor so the
 * user can see normal route identifiers. A later gallery save must not resolve
 * that text through the fallback nav database and overwrite OFP coordinates.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $routeText Route text value.
 * @return array{point_count: int, unresolved_count: int, points: array<int, array<string, mixed>>, unresolved: array<int, array<string, mixed>>}|null.
 */
function flight_map_preserve_existing_ofp_route(int $galleryId, string $routeText): ?array
{
    $row = gallery_flight_map_row($galleryId);
    if ($row === null) {
        return null;
    }

    $storedRouteText = trim((string) ($row['route_text'] ?? ''));
    if ($storedRouteText === '' || $storedRouteText !== trim($routeText)) {
        return null;
    }

    $points = gallery_flight_map_points_from_row($row);
    if ($points === [] || !flight_map_points_are_simbrief_ofp($points)) {
        return null;
    }

    $unresolved = gallery_flight_map_unresolved_from_row($row);
    return [
        'point_count' => count($points),
        'unresolved_count' => count($unresolved),
        'points' => $points,
        'unresolved' => $unresolved,
    ];
}

/**
 * Return true when all stored points came from a SimBrief OFP import.
 *
 * @param array $points Points value.
 * @return bool True when the condition matches.
 */
function flight_map_points_are_simbrief_ofp(array $points): bool
{
    if ($points === []) {
        return false;
    }

    foreach ($points as $point) {
        if (!is_array($point) || (string) ($point['source'] ?? '') !== 'simbrief_ofp') {
            return false;
        }
    }
    return true;
}


/**
 * Persist a gallery route from already-resolved OFP or external coordinates.
 *
 * This path is used by SimBrief imports where the OFP already provides the
 * waypoint geometry. It avoids resolving AIRAC identifiers again and stores only
 * the safe public map payload in the database.
 *
 * @param int $galleryId Gallery identifier.
 * @param string $routeText Human-readable route point list.
 * @param array $points Points value.
 * @param array $unresolved Unresolved value.
 * @return array{point_count: int, unresolved_count: int, points: array<int, array<string, mixed>>, unresolved: array<int, array<string, mixed>>}.
 */
function save_gallery_flight_path_resolved_points(int $galleryId, string $routeText, array $points, array $unresolved = []): array
{
    if ($galleryId <= 0) {
        return ['point_count' => 0, 'unresolved_count' => 0, 'points' => [], 'unresolved' => $unresolved];
    }
    $schemaStatus = presentation_flight_map_schema_status();
    presentation_schema_assert_known($schemaStatus, 'flight_map_resolved_save');
    if (!schema_inspection_is_available($schemaStatus)) {
        return ['point_count' => 0, 'unresolved_count' => 0, 'points' => [], 'unresolved' => $unresolved];
    }

    $normalizedPoints = [];
    foreach ($points as $point) {
        if (!is_array($point)) {
            continue;
        }
        $normalizedPoint = flight_map_normalize_point($point);
        if ($normalizedPoint !== null && !flight_route_is_duplicate_last_point($normalizedPoints, $normalizedPoint)) {
            $normalizedPoints[] = $normalizedPoint;
        }
    }

    if ($normalizedPoints === []) {
        delete_gallery_flight_path_map($galleryId);
        return ['point_count' => 0, 'unresolved_count' => count($unresolved), 'points' => [], 'unresolved' => $unresolved];
    }

    if (count($normalizedPoints) > 1) {
        $normalizedPoints[0]['role'] = 'start';
        $normalizedPoints[count($normalizedPoints) - 1]['role'] = 'end';
        foreach ($normalizedPoints as $index => $point) {
            if ($index > 0 && $index < count($normalizedPoints) - 1) {
                $normalizedPoints[$index]['role'] = 'via';
            }
        }
    }

    $now = now_sql();
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
        GALLERY_MAP_SOURCE_FLIGHT_PATH,
        trim($routeText),
        json_encode($normalizedPoints, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        json_encode(array_values($unresolved), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        count($normalizedPoints),
        $now,
        $now,
        $now,
    ]);

    flight_map_clear_runtime_cache();

    return [
        'point_count' => count($normalizedPoints),
        'unresolved_count' => count($unresolved),
        'points' => $normalizedPoints,
        'unresolved' => $unresolved,
    ];
}

/**
 * Clear generated map metadata after a flight route changes.
 */
function flight_map_clear_runtime_cache(): void
{
    if (function_exists('Gallery\\Services\\gallery_map_cache_clear_all')) {
        gallery_map_cache_clear_all();
    }
}

/**
 * Resolve route text into a stable list of latitude and longitude points.
 *
 * @param string $routeText Route text value.
 * @return array Structured result data for the caller.
 */
function resolve_flight_route_text(string $routeText): array
{
    $tokens = flight_route_tokens($routeText);
    $points = [];
    $unresolved = [];

    foreach ($tokens as $token) {
        if (flight_route_token_is_control_word($token)) {
            continue;
        }

        $point = flight_route_resolve_token($token);
        if ($point !== null) {
            $normalizedPoint = flight_map_normalize_point($point);
            if ($normalizedPoint !== null && !flight_route_is_duplicate_last_point($points, $normalizedPoint)) {
                $points[] = $normalizedPoint;
            }
            continue;
        }

        $unresolved[] = [
            'name' => flight_route_clean_token($token),
            'reason' => 'not_found',
        ];
    }

    return ['points' => $points, 'unresolved' => $unresolved];
}

/**
 * Split pasted flight plan text into route tokens.
 *
 * @param string $routeText Route text value.
 * @return array Structured result data for the caller.
 */
function flight_route_tokens(string $routeText): array
{
    $normalized = preg_replace('/[\r\n\t]+/', ' ', $routeText) ?? $routeText;
    $normalized = str_replace([';', '|'], ' ', $normalized);
    $parts = preg_split('/\s+/', trim($normalized)) ?: [];

    return array_values(array_filter(array_map(static function (string $token): string {
        return trim($token, " \t\n\r\0\x0B,");
    }, $parts), static fn (string $token): bool => $token !== ''));
}

/**
 * Return a sanitized route token suitable for lookup or diagnostics.
 *
 * @param string $token Token value.
 * @return string Text result for the caller.
 */
function flight_route_clean_token(string $token): string
{
    $token = trim($token);
    if (str_contains($token, '/')) {
        $token = explode('/', $token, 2)[0];
    }
    return strtoupper(trim($token, " \t\n\r\0\x0B.,;:"));
}

/**
 * Return true when a route token is syntax, not a point identifier.
 *
 * @param string $token Token value.
 * @return bool True when the condition matches.
 */
function flight_route_token_is_control_word(string $token): bool
{
    $clean = flight_route_clean_token($token);
    if ($clean === '') {
        return true;
    }

    if (in_array($clean, ['DCT', 'DIRECT', 'TO', 'VIA', 'SID', 'STAR', 'DEP', 'ARR', 'ROUTE'], true)) {
        return true;
    }

    if (preg_match('/^(N|M)?\d{3,4}F\d{2,3}$/', $clean) === 1) {
        return true;
    }

    return preg_match('/^[A-Z]{1,3}\d{1,4}[A-Z]?$/', $clean) === 1;
}

/**
 * Resolve one token using inline coordinates first, then optional navdata.
 *
 * @param string $token Token value.
 * @return ?array Structured result data for the caller.
 */
function flight_route_resolve_token(string $token): ?array
{
    $inlinePoint = flight_route_parse_inline_coordinate($token);
    if ($inlinePoint !== null) {
        return $inlinePoint;
    }

    $aviationPoint = flight_route_parse_aviation_coordinate($token);
    if ($aviationPoint !== null) {
        return $aviationPoint;
    }

    return flight_route_lookup_nav_point($token);
}

/**
 * Parse decimal coordinate route tokens.
 *
 * Supported forms include NAME@50.0755,14.4378, NAME(50.0755,14.4378),
 * and a bare 50.0755,14.4378 point for quick manual entry.
 *
 * @param string $token Token value.
 * @return ?array Structured result data for the caller.
 */
function flight_route_parse_inline_coordinate(string $token): ?array
{
    $raw = trim($token);

    if (preg_match('/^([A-Za-z0-9_.-]{1,64})[@=](-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)$/', $raw, $match) === 1) {
        return flight_map_point_from_values($match[1], (float) $match[2], (float) $match[3], 'manual');
    }

    if (preg_match('/^([A-Za-z0-9_.-]{1,64})\((-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)\)$/', $raw, $match) === 1) {
        return flight_map_point_from_values($match[1], (float) $match[2], (float) $match[3], 'manual');
    }

    if (preg_match('/^(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)$/', $raw, $match) === 1) {
        return flight_map_point_from_values('Point', (float) $match[1], (float) $match[2], 'manual');
    }

    return null;
}

/**
 * Parse compact aviation coordinate tokens commonly seen in flight plans.
 *
 * @param string $token Token value.
 * @return ?array Structured result data for the caller.
 */
function flight_route_parse_aviation_coordinate(string $token): ?array
{
    $clean = flight_route_clean_token($token);

    if (preg_match('/^([NS])(\d{2})(\d{2}(?:\.\d+)?)([EW])(\d{3})(\d{2}(?:\.\d+)?)$/', $clean, $match) === 1) {
        $latitude = (float) $match[2] + ((float) $match[3] / 60.0);
        $longitude = (float) $match[5] + ((float) $match[6] / 60.0);
        if ($match[1] === 'S') {
            $latitude *= -1;
        }
        if ($match[4] === 'W') {
            $longitude *= -1;
        }
        return flight_map_point_from_values($clean, $latitude, $longitude, 'coordinate');
    }

    if (preg_match('/^(\d{2})(\d{2})([NS])(\d{3})(\d{2})([EW])$/', $clean, $match) === 1) {
        $latitude = (float) $match[1] + ((float) $match[2] / 60.0);
        $longitude = (float) $match[4] + ((float) $match[5] / 60.0);
        if ($match[3] === 'S') {
            $latitude *= -1;
        }
        if ($match[6] === 'W') {
            $longitude *= -1;
        }
        return flight_map_point_from_values($clean, $latitude, $longitude, 'coordinate');
    }

    if (preg_match('/^([NS])(\d{2}(?:\.\d+)?)([EW])(\d{3}(?:\.\d+)?)$/', $clean, $match) === 1) {
        $latitude = (float) $match[2];
        $longitude = (float) $match[4];
        if ($match[1] === 'S') {
            $latitude *= -1;
        }
        if ($match[3] === 'W') {
            $longitude *= -1;
        }
        return flight_map_point_from_values($clean, $latitude, $longitude, 'coordinate');
    }

    if (preg_match('/^(\d{2})([NS])(\d{3})([EW])$/', $clean, $match) === 1) {
        $latitude = (float) $match[1];
        $longitude = (float) $match[3];
        if ($match[2] === 'S') {
            $latitude *= -1;
        }
        if ($match[4] === 'W') {
            $longitude *= -1;
        }
        return flight_map_point_from_values($clean, $latitude, $longitude, 'coordinate');
    }

    if (preg_match('/^([NS])(\d{2})([EW])(\d{3})$/', $clean, $match) === 1) {
        $latitude = (float) $match[2];
        $longitude = (float) $match[4];
        if ($match[1] === 'S') {
            $latitude *= -1;
        }
        if ($match[3] === 'W') {
            $longitude *= -1;
        }
        return flight_map_point_from_values($clean, $latitude, $longitude, 'coordinate');
    }

    return null;
}

/**
 * Resolve one route token through the composite navigation-data resolver.
 *
 * Local DB rows and bundled offline data are tried first. A logged-in admin with
 * a linked Navigraph account may receive cached or remote-enhanced data, while
 * public rendering continues to use already persisted coordinates.
 *
 * @param string $token Token value.
 * @return ?array Structured result data for the caller.
 */
function flight_route_lookup_nav_point(string $token): ?array
{
    $ident = flight_route_clean_token($token);
    if ($ident === '') {
        return null;
    }

    if (function_exists('Gallery\\Services\\navigation_data_resolve_ident')) {
        $point = navigation_data_resolve_ident($ident, [
            'allow_remote' => false,
        ]);
        if ($point !== null) {
            return flight_map_point_from_values(
                (string) ($point['ident'] ?? $ident),
                (float) ($point['latitude'] ?? 0.0),
                (float) ($point['longitude'] ?? 0.0),
                (string) ($point['kind'] ?? $point['source'] ?? 'navdata')
            );
        }
    }

    return null;
}

/**
 * Build a point array when coordinates are valid.
 *
 * @param string $name Name value.
 * @param float $latitude Latitude value.
 * @param float $longitude Longitude value.
 * @param string $kind Kind value.
 * @return ?array Structured result data for the caller.
 */
function flight_map_point_from_values(string $name, float $latitude, float $longitude, string $kind): ?array
{
    if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
        return null;
    }

    return [
        'name' => trim($name) !== '' ? trim($name) : 'Point',
        'latitude' => $latitude,
        'longitude' => $longitude,
        'kind' => trim($kind) !== '' ? trim($kind) : 'route',
    ];
}

/**
 * Normalize a stored route point to the public display contract.
 *
 * @param array $point Point value.
 * @return ?array Structured result data for the caller.
 */
function flight_map_normalize_point(array $point): ?array
{
    $latitude = isset($point['latitude']) ? (float) $point['latitude'] : (isset($point['lat']) ? (float) $point['lat'] : null);
    $longitude = isset($point['longitude']) ? (float) $point['longitude'] : (isset($point['lng']) ? (float) $point['lng'] : null);
    if ($latitude === null || $longitude === null || $latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
        return null;
    }

    $name = substr(trim((string) ($point['name'] ?? $point['title'] ?? 'Point')), 0, 64);
    $kind = substr(trim((string) ($point['kind'] ?? 'route')), 0, 32);
    $role = substr(trim((string) ($point['role'] ?? '')), 0, 16);
    $source = substr(trim((string) ($point['source'] ?? '')), 0, 32);

    $normalized = [
        'name' => $name !== '' ? $name : 'Point',
        'latitude' => round($latitude, 7),
        'longitude' => round($longitude, 7),
        'kind' => $kind !== '' ? $kind : 'route',
    ];
    if (in_array($role, ['start', 'end', 'via'], true)) {
        $normalized['role'] = $role;
    }
    if ($source !== '') {
        $normalized['source'] = $source;
    }
    return $normalized;
}

/**
 * Return true when a new point repeats the previous point exactly enough.
 *
 * @param array $points Points value.
 * @param array $point Point value.
 * @return bool True when the condition matches.
 */
function flight_route_is_duplicate_last_point(array $points, array $point): bool
{
    if (!$points) {
        return false;
    }

    $last = $points[count($points) - 1];
    return strtoupper((string) ($last['name'] ?? '')) === strtoupper((string) ($point['name'] ?? ''))
        && abs((float) ($last['latitude'] ?? 0.0) - (float) ($point['latitude'] ?? 0.0)) < 0.0000001
        && abs((float) ($last['longitude'] ?? 0.0) - (float) ($point['longitude'] ?? 0.0)) < 0.0000001;
}


/**
 * Return current imported navdata status for admin maintenance UI.
 *
 * @return array Structured result data for the caller.
 */
function flight_map_navdata_status(): array
{
    $status = [
        'ready' => flight_map_navdata_schema_ready(),
        'total' => 0,
        'by_kind' => [],
        'by_source' => [],
        'last_update' => app_setting('flight_map_navdata_last_update', ''),
        'last_source' => app_setting('flight_map_navdata_last_source', ''),
        'last_airports' => (int) app_setting('flight_map_navdata_last_airports', '0'),
        'last_navaids' => (int) app_setting('flight_map_navdata_last_navaids', '0'),
        'last_skipped' => (int) app_setting('flight_map_navdata_last_skipped', '0'),
        'last_deleted' => (int) app_setting('flight_map_navdata_last_deleted', '0'),
        'hybrid' => function_exists('Gallery\\Services\\navigation_data_status') ? navigation_data_status() : [],
    ];

    if (!$status['ready']) {
        return $status;
    }

    try {
        $stmt = db()->prepare('SELECT COUNT(*) FROM flight_map_nav_points');
        $stmt->execute();
        $status['total'] = (int) $stmt->fetchColumn();

        $stmt = db()->prepare('SELECT kind, COUNT(*) AS row_count FROM flight_map_nav_points GROUP BY kind ORDER BY kind');
        $stmt->execute();
        $kindRows = $stmt->fetchAll();
        foreach ($kindRows ?: [] as $row) {
            $status['by_kind'][(string) ($row['kind'] ?? 'unknown')] = (int) ($row['row_count'] ?? 0);
        }

        $stmt = db()->prepare('SELECT source, COUNT(*) AS row_count FROM flight_map_nav_points GROUP BY source ORDER BY source');
        $stmt->execute();
        $sourceRows = $stmt->fetchAll();
        foreach ($sourceRows ?: [] as $row) {
            $source = trim((string) ($row['source'] ?? ''));
            $status['by_source'][$source !== '' ? $source : 'manual'] = (int) ($row['row_count'] ?? 0);
        }
    } catch (PDOException) {
        $status['ready'] = false;
    }

    return $status;
}

/**
 * Download OurAirports CSV data and persist route lookup rows in the database.
 *
 * The importer intentionally stores only final coordinates used by route save.
 * The public gallery viewer never downloads OurAirports data and never performs
 * nav lookup while rendering a map.
 *
 * @return array Structured result data for the caller.
 */
function flight_map_update_navdata_from_ourairports(): array
{
    if (!flight_map_navdata_schema_ready()) {
        throw new RuntimeException('Flight-map navdata storage is not ready. Run database migrations first.');
    }

    if (function_exists('set_time_limit')) {
        @set_time_limit(180);
    }

    $airportCsv = flight_map_fetch_navdata_csv(FLIGHT_MAP_NAVDATA_URL_AIRPORTS);
    $navaidCsv = flight_map_fetch_navdata_csv(FLIGHT_MAP_NAVDATA_URL_NAVAIDS);
    $now = now_sql();
    $cycle = gmdate('Ymd');
    $result = [
        'airports' => 0,
        'navaids' => 0,
        'skipped' => 0,
        'deleted' => 0,
        'total' => 0,
        'source' => FLIGHT_MAP_NAVDATA_SOURCE_OURAIRPORTS,
        'updated_at' => $now,
    ];

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

        $airportResult = flight_map_import_ourairports_airports($airportCsv, $stmt, $cycle, $now);
        $navaidResult = flight_map_import_ourairports_navaids($navaidCsv, $stmt, $cycle, $now);

        $deleteStmt = $pdo->prepare('DELETE FROM flight_map_nav_points WHERE source = ? AND updated_at <> ?');
        $deleteStmt->execute([FLIGHT_MAP_NAVDATA_SOURCE_OURAIRPORTS, $now]);
        $deleted = (int) $deleteStmt->rowCount();

        $pdo->commit();

        $result['airports'] = (int) $airportResult['imported'];
        $result['navaids'] = (int) $navaidResult['imported'];
        $result['skipped'] = (int) $airportResult['skipped'] + (int) $navaidResult['skipped'];
        $result['deleted'] = $deleted;
        $result['total'] = $result['airports'] + $result['navaids'];

        set_app_setting('flight_map_navdata_last_update', $now);
        set_app_setting('flight_map_navdata_last_source', FLIGHT_MAP_NAVDATA_SOURCE_OURAIRPORTS);
        set_app_setting('flight_map_navdata_last_airports', (string) $result['airports']);
        set_app_setting('flight_map_navdata_last_navaids', (string) $result['navaids']);
        set_app_setting('flight_map_navdata_last_skipped', (string) $result['skipped']);
        set_app_setting('flight_map_navdata_last_deleted', (string) $result['deleted']);

        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Fetch one trusted navdata CSV URL with the existing updater HTTP client.
 *
 * @param string $url URL used by this workflow.
 * @return string Text result for the caller.
 */
function flight_map_fetch_navdata_csv(string $url): string
{
    if (!str_starts_with($url, 'https://davidmegginson.github.io/ourairports-data/')) {
        throw new RuntimeException('Unsupported navdata source URL.');
    }

    $body = function_exists('Gallery\\Services\\http_fetch') ? http_fetch($url, 60) : flight_map_basic_https_fetch($url, 60);
    if (trim($body) === '' || !str_contains($body, ',')) {
        throw new RuntimeException('Downloaded navdata CSV is empty or invalid.');
    }

    return $body;
}

/**
 * Fetch a trusted HTTPS resource when the update service is unavailable.
 *
 * @param string $url URL used by this workflow.
 * @param int $timeoutSeconds Timeout seconds value.
 * @return string Text result for the caller.
 */
function flight_map_basic_https_fetch(string $url, int $timeoutSeconds): string
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize navdata HTTP client.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 15),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'PHP-Gallery-CMS/' . (function_exists('Gallery\\Core\\cms_current_version') ? cms_current_version() : 'dev'),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status >= 400) {
            throw new RuntimeException($error !== '' ? $error : 'Navdata HTTP request failed with status ' . $status . '.');
        }
        return (string) $body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => "User-Agent: PHP-Gallery-CMS\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Navdata HTTP request failed. Enable curl or allow_url_fopen.');
    }
    return (string) $body;
}

/**
 * Import airport rows from OurAirports airports.csv.
 *
 * @param string $csvBody Csv body value.
 * @param PDOStatement $stmt Stmt value.
 * @param string $cycle Cycle value.
 * @param string $now Now value.
 * @return array Structured result data for the caller.
 */
function flight_map_import_ourairports_airports(string $csvBody, PDOStatement $stmt, string $cycle, string $now): array
{
    $imported = 0;
    $skipped = 0;
    $seen = [];

    flight_map_each_csv_row($csvBody, static function (array $row) use ($stmt, $cycle, $now, &$imported, &$skipped, &$seen): void {
        $latitude = flight_map_csv_float($row, ['latitude_deg', 'latitude', 'lat']);
        $longitude = flight_map_csv_float($row, ['longitude_deg', 'longitude', 'lon', 'lng']);
        $region = flight_map_csv_text($row, ['iso_country', 'continent']);
        $idents = flight_map_airport_ident_candidates($row);

        if ($latitude === null || $longitude === null || $idents === []) {
            $skipped++;
            return;
        }

        foreach ($idents as $ident) {
            $dedupeKey = 'airport|' . $region . '|' . $ident;
            if (isset($seen[$dedupeKey])) {
                continue;
            }
            $seen[$dedupeKey] = true;
            if (flight_map_insert_nav_point($stmt, $ident, 'airport', $region, $latitude, $longitude, $cycle, $now)) {
                $imported++;
            } else {
                $skipped++;
            }
        }
    });

    return ['imported' => $imported, 'skipped' => $skipped];
}

/**
 * Import navaid rows from OurAirports navaids.csv.
 *
 * @param string $csvBody Csv body value.
 * @param PDOStatement $stmt Stmt value.
 * @param string $cycle Cycle value.
 * @param string $now Now value.
 * @return array Structured result data for the caller.
 */
function flight_map_import_ourairports_navaids(string $csvBody, PDOStatement $stmt, string $cycle, string $now): array
{
    $imported = 0;
    $skipped = 0;
    $seen = [];

    flight_map_each_csv_row($csvBody, static function (array $row) use ($stmt, $cycle, $now, &$imported, &$skipped, &$seen): void {
        $ident = flight_map_normalize_nav_ident(flight_map_csv_text($row, ['ident']));
        $latitude = flight_map_csv_float($row, ['latitude_deg', 'latitude', 'lat']);
        $longitude = flight_map_csv_float($row, ['longitude_deg', 'longitude', 'lon', 'lng']);
        $region = flight_map_csv_text($row, ['iso_country', 'associated_airport']);
        $kind = flight_map_navaid_kind_from_row($row);

        if ($ident === '' || $latitude === null || $longitude === null) {
            $skipped++;
            return;
        }

        $dedupeKey = $kind . '|' . $region . '|' . $ident;
        if (isset($seen[$dedupeKey])) {
            return;
        }
        $seen[$dedupeKey] = true;

        if (flight_map_insert_nav_point($stmt, $ident, $kind, $region, $latitude, $longitude, $cycle, $now)) {
            $imported++;
        } else {
            $skipped++;
        }
    });

    return ['imported' => $imported, 'skipped' => $skipped];
}

/**
 * Return a stable navaid kind from one OurAirports navaids.csv row.
 *
 * @param array $row Row data.
 * @return string Text result for the caller.
 */
function flight_map_navaid_kind_from_row(array $row): string
{
    $type = strtoupper(trim(flight_map_csv_text($row, ['type', 'nav_type'])));
    if (str_contains($type, 'NDB')) {
        return 'ndb';
    }
    if (str_contains($type, 'VOR')) {
        return 'vor';
    }
    return 'navaid';
}

/**
 * Iterate CSV rows with normalized lower-case header names.
 *
 * @param string $csvBody Csv body value.
 * @param callable $callback Callback invoked by this workflow.
 */
function flight_map_each_csv_row(string $csvBody, callable $callback): void
{
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('Could not open temporary CSV parser stream.');
    }

    try {
        fwrite($stream, $csvBody);
        rewind($stream);
        $headers = fgetcsv($stream);
        if (!is_array($headers)) {
            throw new RuntimeException('Navdata CSV is missing a header row.');
        }
        $normalizedHeaders = array_map(static fn ($header): string => strtolower(trim((string) $header)), $headers);

        while (($values = fgetcsv($stream)) !== false) {
            if (!is_array($values)) {
                continue;
            }
            $row = [];
            foreach ($normalizedHeaders as $index => $name) {
                if ($name !== '') {
                    $row[$name] = $values[$index] ?? '';
                }
            }
            $callback($row);
        }
    } finally {
        fclose($stream);
    }
}

/**
 * Return candidate airport identifiers from one OurAirports row.
 *
 * @param array $row Row data.
 * @return array Structured result data for the caller.
 */
function flight_map_airport_ident_candidates(array $row): array
{
    $candidates = [];
    foreach (['ident', 'gps_code', 'iata_code'] as $field) {
        $ident = flight_map_normalize_nav_ident((string) ($row[$field] ?? ''));
        if ($ident !== '' && strlen($ident) <= 8) {
            $candidates[] = $ident;
        }
    }
    return array_values(array_unique($candidates));
}

/**
 * Return normalized text from the first present CSV field.
 *
 * @param array $row Row data.
 * @param array $names Names value.
 * @return string Text result for the caller.
 */
function flight_map_csv_text(array $row, array $names): string
{
    foreach ($names as $name) {
        $value = trim((string) ($row[$name] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/**
 * Return a valid float from the first present CSV field.
 *
 * @param array $row Row data.
 * @param array $names Names value.
 * @return ?float Numeric result for the caller.
 */
function flight_map_csv_float(array $row, array $names): ?float
{
    foreach ($names as $name) {
        $value = trim((string) ($row[$name] ?? ''));
        if ($value === '' || !is_numeric($value)) {
            continue;
        }
        $float = (float) $value;
        return is_finite($float) ? $float : null;
    }
    return null;
}

/**
 * Normalize airport and navaid identifiers for lookup.
 *
 * @param string $ident Ident value.
 * @return string Text result for the caller.
 */
function flight_map_normalize_nav_ident(string $ident): string
{
    $ident = strtoupper(trim($ident));
    $ident = preg_replace('/[^A-Z0-9_-]/', '', $ident) ?? '';
    return substr($ident, 0, 32);
}

/**
 * Insert or update one nav point row when coordinates are valid.
 *
 * @param PDOStatement $stmt Stmt value.
 * @param string $ident Ident value.
 * @param string $kind Kind value.
 * @param string $region Region value.
 * @param float $latitude Latitude value.
 * @param float $longitude Longitude value.
 * @param string $cycle Cycle value.
 * @param string $now Now value.
 * @return bool True when the condition matches.
 */
function flight_map_insert_nav_point(PDOStatement $stmt, string $ident, string $kind, string $region, float $latitude, float $longitude, string $cycle, string $now): bool
{
    $point = flight_map_point_from_values($ident, $latitude, $longitude, $kind);
    if ($point === null) {
        return false;
    }

    $stmt->execute([
        flight_map_normalize_nav_ident($ident),
        $kind,
        substr(strtoupper(trim($region)) !== '' ? strtoupper(trim($region)) : 'ZZ', 0, 32),
        round((float) $point['latitude'], 7),
        round((float) $point['longitude'], 7),
        FLIGHT_MAP_NAVDATA_SOURCE_OURAIRPORTS,
        $cycle,
        $now,
        $now,
    ]);

    return true;
}

/**
 * Decode stored resolved points for one route map row.
 *
 * @param array $row Row data.
 * @return array Structured result data for the caller.
 */
function gallery_flight_map_points_from_row(array $row): array
{
    $decoded = json_decode((string) ($row['resolved_points_json'] ?? '[]'), true);
    if (!is_array($decoded)) {
        return [];
    }

    $points = [];
    foreach ($decoded as $point) {
        if (!is_array($point)) {
            continue;
        }
        $normalizedPoint = flight_map_normalize_point($point);
        if ($normalizedPoint !== null) {
            $points[] = $normalizedPoint;
        }
    }

    return $points;
}

/**
 * Decode stored unresolved point diagnostics for the admin editor.
 *
 * @param array $row Row data.
 * @return array Structured result data for the caller.
 */
function gallery_flight_map_unresolved_from_row(array $row): array
{
    $decoded = json_decode((string) ($row['unresolved_points_json'] ?? '[]'), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Return a JSON-ready flight path payload for the shared map renderer.
 *
 * @param array $gallery Gallery row or gallery data.
 * @return ?array Structured result data for the caller.
 */
function gallery_flight_map_payload(array $gallery): ?array
{
    $row = gallery_flight_map_row((int) ($gallery['id'] ?? 0));
    if ($row === null) {
        return null;
    }

    $storedPoints = gallery_flight_map_points_from_row($row);
    if (!$storedPoints) {
        return null;
    }

    $displayPoints = [];
    foreach ($storedPoints as $index => $point) {
        $kind = (string) ($point['kind'] ?? 'route');
        $name = (string) ($point['name'] ?? ('Point ' . ($index + 1)));
        $role = (string) ($point['role'] ?? '');
        if ($role === '') {
            $role = $index === 0 ? 'start' : ($index === count($storedPoints) - 1 ? 'end' : 'via');
        }
        $pointType = $role === 'start' ? 'route_start' : ($role === 'end' ? 'route_end' : 'route_via');
        $description = $role === 'start' ? 'Departure' : ($role === 'end' ? 'Arrival' : 'Route point');
        $displayPoints[] = [
            'id' => 'flight-' . (int) ($gallery['id'] ?? 0) . '-' . $index,
            'lat' => (float) $point['latitude'],
            'lng' => (float) $point['longitude'],
            'name' => $name,
            'title' => $name,
            'kind' => $kind,
            'role' => $role,
            'description' => $description,
            'type' => $pointType,
            'point_type' => $pointType,
            'source_type' => GALLERY_MAP_SOURCE_FLIGHT_PATH,
            'map_source_type' => GALLERY_MAP_SOURCE_FLIGHT_PATH,
        ];
    }

    return [
        'gallery_id' => (int) ($gallery['id'] ?? 0),
        'title' => (string) ($gallery['title'] ?? 'Flight route'),
        'source_type' => GALLERY_MAP_SOURCE_FLIGHT_PATH,
        'map_source_type' => GALLERY_MAP_SOURCE_FLIGHT_PATH,
        'render_path' => count($displayPoints) > 1,
        'points' => $displayPoints,
        'geometry' => [
            'type' => 'polyline',
            'points' => $displayPoints,
        ],
        'unresolved_count' => count(gallery_flight_map_unresolved_from_row($row)),
    ];
}
