<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/flight_maps/navdata_update.php
 * Module Type: Service Part
 * Purpose: Serialize passive-age and explicit OurAirports refreshes.
 * Responsibilities: Enforce weekly freshness, retry backoff and nonblocking import ownership.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

use function Gallery\Core\now_sql;
use function Gallery\Models\flight_maps_model_complete_route;

/**
 * Purpose: Bound automatic navigation-data refresh frequency.
 * Units: seconds.
 * Scope: OurAirports snapshot for this installation.
 * Consumers: flight_map_navdata_refresh_due().
 * Rationale: daily upstream data changes do not justify downloads on every Admin visit.
 * @var int
 */
const FLIGHT_MAP_NAVDATA_REFRESH_SECONDS = 604800;

/**
 * Purpose: Throttle automatic retries after download or import failure.
 * Units: seconds.
 * Scope: installation-wide OurAirports refresh.
 * Consumers: flight_map_navdata_refresh_due().
 * Rationale: avoid repeatedly retrying an unavailable remote source on page reload.
 * @var int
 */
const FLIGHT_MAP_NAVDATA_RETRY_SECONDS = 3600;

/** Return whether a periodic import is due. @return bool True after a week and outside retry backoff. */
function flight_map_navdata_refresh_due(): bool
{
    $lastUpdate = strtotime((string) app_setting('flight_map_navdata_last_update', '')) ?: 0;
    $lastAttempt = (int) app_setting('flight_map_navdata_last_attempt', '0');
    return time() - $lastUpdate >= FLIGHT_MAP_NAVDATA_REFRESH_SECONDS
        && time() - $lastAttempt >= FLIGHT_MAP_NAVDATA_RETRY_SECONDS;
}

/**
 * Refresh navdata under a nonblocking installation-wide lock.
 *
 * The controller releases its session before entering this operation. A browser
 * closure may disconnect feedback, but PHP can finish the atomic import.
 *
 * @param bool $onlyIfDue Whether a browser requested the periodic freshness check.
 * @param int $galleryId Optional saved route to complete after refreshing local data.
 * @return array{state:string,result:array<string,mixed>,route_updated?:bool} Import outcome or passive skip.
 */
function flight_map_navdata_refresh(bool $onlyIfDue = false, int $galleryId = 0): array
{
    if ($galleryId <= 0 && $onlyIfDue && !flight_map_navdata_refresh_due()) {
        return ['state' => 'current', 'result' => []];
    }
    $lock = @fopen(dirname(__DIR__, 3) . '/cache/navdata-update.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return ['state' => 'busy', 'result' => []];
    }
    try {
        // Repeat the age decision after acquiring ownership across browser tabs.
        $outcome = ['state' => 'current', 'result' => []];
        if (!$onlyIfDue || flight_map_navdata_refresh_due()) {
            presentation_schema_assert_write_available(presentation_flight_navdata_schema_status(), 'flight_navdata_import');
            set_app_setting('flight_map_navdata_last_attempt', (string) time());
            $outcome = ['state' => 'updated', 'result' => flight_map_update_navdata_from_ourairports()];
        }
        if ($galleryId > 0) {
            $outcome['route_updated'] = flight_map_complete_saved_route($galleryId);
        }
        return $outcome;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Fill unresolved saved route points using current local data without replacing OFP geometry.
 *
 * @param int $galleryId Saved gallery identity, never an unsaved browser route.
 * @return bool True after completing a still-current route snapshot.
 */
function flight_map_complete_saved_route(int $galleryId): bool
{
    if (!feature_capability_effective_enabled('flight_maps') || !flight_map_schema_ready()) {
        return false;
    }
    $row = gallery_flight_map_row($galleryId);
    if ($row === null || (string) ($row['map_source_type'] ?? '') !== GALLERY_MAP_SOURCE_FLIGHT_PATH
        || trim((string) ($row['route_text'] ?? '')) === '' || gallery_flight_map_unresolved_from_row($row) === []) {
        return false;
    }
    $oldPoints = gallery_flight_map_points_from_row($row);
    if (flight_map_points_are_simbrief_ofp($oldPoints)) {
        return false;
    }
    $resolved = resolve_flight_route_text((string) $row['route_text']);
    // An incomplete provider must not remove previously captured coordinates.
    if (count($resolved['points']) <= count($oldPoints)
        || count($resolved['unresolved']) >= count(gallery_flight_map_unresolved_from_row($row))) {
        return false;
    }
    foreach ($oldPoints as $oldPoint) {
        $index = array_search((string) $oldPoint['name'], array_column($resolved['points'], 'name'), true);
        if ($index === false) {
            return false;
        }
        $resolved['points'][$index] = $oldPoint;
    }
    presentation_schema_assert_write_available(presentation_flight_map_schema_status(), 'flight_map_navdata_route_complete');
    if (!flight_maps_model_complete_route($row, $resolved['points'], $resolved['unresolved'], now_sql())) {
        return false;
    }
    flight_map_clear_runtime_cache();
    return true;
}
