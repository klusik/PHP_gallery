<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/route_navdata_background_test.php
 * Module Type: Regression Test
 * Purpose: Verify saved-route completion after independent navigation-data refreshes.
 * Responsibilities: Exercise due policy, concurrency, local resolution, OFP preservation and conditional model writes offline.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return the offline persistence fixture. @return \RouteNavDatabase Captured model storage. */
    function db(): \RouteNavDatabase { return $GLOBALS['route_nav_db']; }
    /** Return a deterministic resolution time. @return string SQL timestamp. */
    function now_sql(): string { return '2026-10-05 12:00:00'; }
}
namespace Gallery\Services {
    const GALLERY_MAP_SOURCE_FLIGHT_PATH = 'flight_path';
    /** Read freshness state. @param string $key Setting identity. @param string $fallback Default value. @return string Fixture state. */
    function app_setting(string $key, string $fallback): string { return $GLOBALS['route_nav_settings'][$key] ?? $fallback; }
    /** Persist attempt state. @param string $key Setting identity. @param string $value Persisted value. @return void Captures state. */
    function set_app_setting(string $key, string $value): void { $GLOBALS['route_nav_settings'][$key] = $value; }
    /** Simulate a successful trusted import. @return array<string,int> Import counters. */
    function flight_map_update_navdata_from_ourairports(): array {
        $GLOBALS['route_nav_imports']++;
        if ($GLOBALS['route_nav_fail']) throw new \RuntimeException('Offline import failure');
        $GLOBALS['route_nav_settings']['flight_map_navdata_last_update'] = date('Y-m-d H:i:s');
        return ['airports' => 2];
    }
    /** Return schema state. @return array<string,string> Explicit schema result. */
    function presentation_flight_navdata_schema_status(): array { return ['state' => $GLOBALS['route_nav_schema']]; }
    /** Return saved-route write readiness. @return array<string,string> Explicit route schema state. */
    function presentation_flight_map_schema_status(): array { return ['state' => $GLOBALS['route_nav_map_schema_state']]; }
    /** Refuse unavailable import storage. @param array<string,string> $status Explicit schema state. @param string $action Mutation intent. @return void Throws for unknown/missing state. */
    function presentation_schema_assert_write_available(array $status, string $action): void { if ($status['state'] !== 'available') throw new \RuntimeException('Schema unavailable'); }
    /** Return effective route feature state. @param string $key Capability identity. @return bool Fixture availability. */
    function feature_capability_effective_enabled(string $key): bool { return $GLOBALS['route_nav_enabled']; }
    /** Return verified route schema availability. @return bool Fixture storage readiness. */
    function flight_map_schema_ready(): bool { return $GLOBALS['route_nav_map_schema']; }
    /** Read a captured saved route. @param int $id Gallery identity. @return ?array Stored route row. */
    function gallery_flight_map_row(int $id): ?array { return $GLOBALS['route_nav_db']->row; }
    /** Read captured points. @param array<string,mixed> $row Stored route. @return list<array<string,mixed>> Coordinates. */
    function gallery_flight_map_points_from_row(array $row): array { return json_decode($row['resolved_points_json'], true); }
    /** Read unresolved tokens. @param array<string,mixed> $row Stored route. @return list<array<string,mixed>> Tokens. */
    function gallery_flight_map_unresolved_from_row(array $row): array { return json_decode($row['unresolved_points_json'], true); }
    /** Identify captured OFP geometry. @param list<array<string,mixed>> $points Stored coordinates. @return bool All coordinates are from OFP. */
    function flight_map_points_are_simbrief_ofp(array $points): bool { return $points !== [] && array_reduce($points, static fn(bool $all, array $point): bool => $all && ($point['source'] ?? '') === 'simbrief_ofp', true); }
    /** Simulate local-only resolution. @param string $text Captured route text. @return array<string,array> Ordered local result. */
    function resolve_flight_route_text(string $text): array {
        $GLOBALS['route_nav_resolutions']++;
        if ($GLOBALS['route_nav_concurrent']) $GLOBALS['route_nav_db']->row['route_text'] = 'NEW ROUTE';
        return $GLOBALS['route_nav_resolved'];
    }
    /** Capture cache invalidation after actual persistence. @return void Records invalidation. */
    function flight_map_clear_runtime_cache(): void { $GLOBALS['route_nav_invalidations']++; }
}
namespace {
    /** Record guarded model persistence without requiring live storage. */
    final class RouteNavDatabase {
        public ?array $row = null;
        public array $writes = [];
        /** Capture a production model statement. @param string $sql Model SQL. @return RouteNavStatement Recorded statement. */
        public function prepare(string $sql): RouteNavStatement { return new RouteNavStatement($this, $sql); }
    }
    /** Apply production bound values only if the captured route still matches. */
    final class RouteNavStatement {
        private int $affected = 0;
        /** Capture statement ownership. @param RouteNavDatabase $database Offline storage. @param string $sql Production SQL. @return void Initializes the statement. */
        public function __construct(private RouteNavDatabase $database, private string $sql) {}
        /** Exercise the model's snapshot comparison. @param array<int,mixed> $values Bound SQL parameters. @return bool Successful statement execution. */
        public function execute(array $values): bool {
            route_nav_assert(str_contains($this->sql, 'BINARY route_text = BINARY ?') && str_contains($this->sql, 'BINARY resolved_points_json = BINARY ?') && str_contains($this->sql, 'BINARY unresolved_points_json = BINARY ?'), 'Model must compare the actual payload with byte-sensitive SQL predicates.');
            $row = $this->database->row;
            if ($row === null || array_slice($values, 5) !== [$row['gallery_id'], $row['map_source_type'], $row['route_text'], $row['resolved_points_json'], $row['unresolved_points_json'], $row['updated_at']]) return true;
            $this->database->writes[] = $values;
            $this->database->row['resolved_points_json'] = $values[0];
            $this->database->row['unresolved_points_json'] = $values[1];
            $this->database->row['updated_at'] = $values[4];
            $this->affected = 1;
            return true;
        }
        /** Report a matched conditional update. @return int Affected fixture rows. */
        public function rowCount(): int { return $this->affected; }
    }
    /** Require a background behavior. @param bool $value Expected condition. @param string $message Failure description. @return void Throws on failure. */
    function route_nav_assert(bool $value, string $message): void { if (!$value) throw new \RuntimeException($message); }
    require_once __DIR__ . '/../app/models/flight_maps.php';
    require_once __DIR__ . '/../app/services/flight_maps/navdata_update.php';
    $GLOBALS['route_nav_db'] = new RouteNavDatabase();
    $GLOBALS['route_nav_imports'] = $GLOBALS['route_nav_resolutions'] = $GLOBALS['route_nav_invalidations'] = 0;
    $GLOBALS['route_nav_fail'] = $GLOBALS['route_nav_concurrent'] = false;
    $GLOBALS['route_nav_enabled'] = $GLOBALS['route_nav_map_schema'] = true;
    $GLOBALS['route_nav_schema'] = 'available';
    $GLOBALS['route_nav_map_schema_state'] = 'available';
    $GLOBALS['route_nav_settings'] = [];
    $old = ['name' => 'LKPR', 'latitude' => 50.1, 'longitude' => 14.3];
    $new = ['name' => 'EDDF', 'latitude' => 50.0, 'longitude' => 8.6];
    $row = ['gallery_id' => 41, 'map_source_type' => 'flight_path', 'route_text' => 'LKPR DCT EDDF', 'resolved_points_json' => json_encode([$old]), 'unresolved_points_json' => json_encode([['name' => 'EDDF']]), 'updated_at' => '2026-10-05 11:00:00'];
    $GLOBALS['route_nav_db']->row = $row;
    $GLOBALS['route_nav_resolved'] = ['points' => [array_replace($old, ['latitude' => 50.2]), $new], 'unresolved' => []];
    $outcome = \Gallery\Services\flight_map_navdata_refresh(true, 41);
    route_nav_assert($outcome['state'] === 'updated' && $outcome['route_updated'] && $GLOBALS['route_nav_imports'] === 1, 'Empty navdata is downloaded before completing an already saved manual route.');
    route_nav_assert(json_decode($GLOBALS['route_nav_db']->row['resolved_points_json'], true)[0] === $old && $GLOBALS['route_nav_invalidations'] === 1, 'Completion adds missing coordinates while preserving captured ones and invalidating map cache.');
    route_nav_assert(!\Gallery\Services\flight_map_navdata_refresh(true, 41)['route_updated'] && $GLOBALS['route_nav_imports'] === 1, 'Fresh data and complete routes require no download or rewrite.');
    $GLOBALS['route_nav_db']->row = $row;
    $GLOBALS['route_nav_concurrent'] = true;
    route_nav_assert(!\Gallery\Services\flight_map_navdata_refresh(true, 41)['route_updated'] && $GLOBALS['route_nav_db']->row['route_text'] === 'NEW ROUTE', 'Completion cannot overwrite a concurrent route edit.');
    $GLOBALS['route_nav_concurrent'] = false;
    $GLOBALS['route_nav_db']->row = null;
    route_nav_assert(!\Gallery\Services\flight_map_navdata_refresh(true, 41)['route_updated'], 'Deleted routes remain deleted after background completion.');
    $GLOBALS['route_nav_db']->row = $row;
    $GLOBALS['route_nav_resolved'] = ['points' => [$new, ['name' => 'NEW', 'latitude' => 1.0, 'longitude' => 2.0]], 'unresolved' => []];
    route_nav_assert(!\Gallery\Services\flight_map_navdata_refresh(true, 41)['route_updated'], 'Incomplete providers cannot discard an already captured route point.');
    $GLOBALS['route_nav_resolved'] = ['points' => [$old, $new], 'unresolved' => []];
    $GLOBALS['route_nav_map_schema_state'] = 'unknown';
    $before = count($GLOBALS['route_nav_db']->writes);
    try { \Gallery\Services\flight_map_navdata_refresh(true, 41); throw new \LogicException('Expected route schema refusal'); }
    catch (\RuntimeException) { route_nav_assert($before === count($GLOBALS['route_nav_db']->writes), 'Route completion explicitly verifies write schema before conditional persistence.'); }
    $GLOBALS['route_nav_map_schema_state'] = 'available';
    $GLOBALS['route_nav_db']->row = array_replace($row, ['resolved_points_json' => json_encode([array_replace($old, ['source' => 'simbrief_ofp'])])]);
    $before = $GLOBALS['route_nav_resolutions'];
    route_nav_assert(!\Gallery\Services\flight_map_navdata_refresh(true, 41)['route_updated'] && $before === $GLOBALS['route_nav_resolutions'], 'OFP geometry is never re-resolved through the local database.');
    $lock = fopen(__DIR__ . '/../cache/navdata-update.lock', 'c');
    flock($lock, LOCK_EX);
    route_nav_assert(\Gallery\Services\flight_map_navdata_refresh(true, 41)['state'] === 'busy', 'Saved-route checks respect an active importer even after attempt backoff starts.');
    flock($lock, LOCK_UN); fclose($lock);
    $GLOBALS['route_nav_settings'] = [];
    $GLOBALS['route_nav_fail'] = true;
    try { \Gallery\Services\flight_map_navdata_refresh(true, 41); throw new \LogicException('Expected import failure'); }
    catch (\RuntimeException) { route_nav_assert(!\Gallery\Services\flight_map_navdata_refresh_due(), 'Failed imports retain retry backoff.'); }
    $GLOBALS['route_nav_fail'] = false;
    $GLOBALS['route_nav_settings'] = [];
    $GLOBALS['route_nav_schema'] = 'unknown';
    $before = $GLOBALS['route_nav_imports'];
    try { \Gallery\Services\flight_map_navdata_refresh(true, 41); throw new \LogicException('Expected schema refusal'); }
    catch (\RuntimeException) { route_nav_assert($before === $GLOBALS['route_nav_imports'], 'Unknown schema prevents network import.'); }
    $GLOBALS['route_nav_enabled'] = false;
    route_nav_assert(!\Gallery\Services\flight_map_complete_saved_route(41), 'Disabled flight maps cannot mutate stored route data.');
    echo "Route navdata background: PASS\n";
}
