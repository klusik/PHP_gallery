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
 * @return array{state:string,result:array<string,mixed>} Import outcome or passive skip.
 */
function flight_map_navdata_refresh(bool $onlyIfDue = false): array
{
    if ($onlyIfDue && !flight_map_navdata_refresh_due()) {
        return ['state' => 'current', 'result' => []];
    }
    $lock = @fopen(dirname(__DIR__, 3) . '/cache/navdata-update.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        return ['state' => 'busy', 'result' => []];
    }
    try {
        // Repeat the age decision after acquiring ownership across browser tabs.
        if ($onlyIfDue && !flight_map_navdata_refresh_due()) {
            return ['state' => 'current', 'result' => []];
        }
        presentation_schema_assert_write_available(presentation_flight_navdata_schema_status(), 'flight_navdata_import');
        set_app_setting('flight_map_navdata_last_attempt', (string) time());
        return ['state' => 'updated', 'result' => flight_map_update_navdata_from_ourairports()];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
