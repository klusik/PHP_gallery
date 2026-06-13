<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/navigation_data.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides an offline-first navigation-data resolver for flight route maps.
 *
 * Responsibilities:
 *   - Resolve airports, fixes, VORs, NDBs, and future airway points through a provider chain
 *   - Keep bundled navigation data available without internet access
 *   - Optionally enhance lookups through a user-authorized Navigraph session
 *   - Cache remote results so public route rendering never depends on live API calls
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
 *   2026-05-27
 */

declare(strict_types=1);

namespace Gallery\Services;

use PDOException;
use RuntimeException;
use Throwable;
use function Gallery\Core\absolute_public_url;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\current_user;
use function Gallery\Core\db;
use function Gallery\Core\now_sql;
use function Gallery\Core\url_for;

const NAVIGATION_DATA_SOURCE_LOCAL_DB = 'local_db';
const NAVIGATION_DATA_SOURCE_BUNDLED = 'bundled';
const NAVIGATION_DATA_SOURCE_REMOTE_CACHE = 'remote_cache';
const NAVIGATION_DATA_SOURCE_NAVIGRAPH = 'navigraph';
const NAVIGATION_DATA_DEFAULT_CACHE_TTL_SECONDS = 2592000;
const NAVIGATION_DATA_BUNDLED_CYCLE = 'offline';

/**
 * Return the effective navigation-data configuration.
 *
 * Existing installations may not have a navigation_data section in config.php.
 * This helper supplies conservative defaults so the feature degrades cleanly.
 *
 * @return array<string mixed>.
 */
function navigation_data_config(): array
{
    $config = function_exists('Gallery\\Core\\cms_config') ? cms_config() : [];
    $navigation = is_array($config['navigation_data'] ?? null) ? $config['navigation_data'] : [];
    $navigraph = is_array($navigation['navigraph'] ?? null) ? $navigation['navigraph'] : [];

    $defaultNavigraph = [
        'enabled' => false,
        'client_id' => '',
        'client_secret' => '',
        'scope' => 'openid profile offline_access',
        'redirect_uri' => '',
        'authorization_endpoint' => 'https://identity.api.navigraph.com/connect/authorize',
        'token_endpoint' => 'https://identity.api.navigraph.com/connect/token',
        'packages_endpoint' => 'https://api.navigraph.com/v1/navdata/packages',
        'package_format' => '',
        'lookup_endpoint_template' => '',
        'token_encryption_key' => '',
    ];

    return [
        'bundled_navdata_path' => (string) ($navigation['bundled_navdata_path'] ?? navigation_data_default_bundled_path()),
        'cache_ttl_seconds' => max(3600, (int) ($navigation['cache_ttl_seconds'] ?? NAVIGATION_DATA_DEFAULT_CACHE_TTL_SECONDS)),
        'navigraph' => $navigraph + $defaultNavigraph,
    ];
}

/**
 * Return the default bundled navigation-data CSV path.
 *
 * @return string Text result for the caller.
 */
function navigation_data_default_bundled_path(): string
{
    return dirname(__DIR__, 2) . '/data/navdata/local_nav_points.csv';
}

/**
 * Return whether the optional remote cache migration has been applied.
 *
 * @return bool True when the condition matches.
 */
function navigation_data_cache_schema_ready(): bool
{
    try {
        $table = db()->query("SHOW TABLES LIKE 'navigation_data_cache'");
        if (!$table || !$table->fetch()) {
            return false;
        }
        $column = db()->query("SHOW COLUMNS FROM navigation_data_cache LIKE 'cache_key'");
        return $column && (bool) $column->fetch();
    } catch (PDOException) {
        return false;
    }
}

/**
 * Return whether per-admin Navigraph account storage is available.
 *
 * @return bool True when the condition matches.
 */
function navigation_data_account_schema_ready(): bool
{
    try {
        $table = db()->query("SHOW TABLES LIKE 'navigation_data_accounts'");
        if (!$table || !$table->fetch()) {
            return false;
        }
        $column = db()->query("SHOW COLUMNS FROM navigation_data_accounts LIKE 'refresh_token_cipher'");
        return $column && (bool) $column->fetch();
    } catch (PDOException) {
        return false;
    }
}

/**
 * Return whether the existing local nav point table can be used.
 *
 * @return bool True when the condition matches.
 */
function navigation_data_local_db_schema_ready(): bool
{
    try {
        $table = db()->query("SHOW TABLES LIKE 'flight_map_nav_points'");
        if (!$table || !$table->fetch()) {
            return false;
        }
        $column = db()->query("SHOW COLUMNS FROM flight_map_nav_points LIKE 'ident'");
        return $column && (bool) $column->fetch();
    } catch (PDOException) {
        return false;
    }
}

/**
 * Build an admin and diagnostics status snapshot for the hybrid navdata layer.
 *
 * @return array<string mixed>.
 */
function navigation_data_status(): array
{
    $bundled = navigation_data_bundled_index();
    $status = [
        'cache_ready' => navigation_data_cache_schema_ready(),
        'local_db_ready' => navigation_data_local_db_schema_ready(),
        'bundled_path' => navigation_data_config()['bundled_navdata_path'],
        'bundled_count' => count($bundled),
        'cache_count' => 0,
        'cache_last_update' => '',
        'local_db_count' => 0,
        'navigraph' => navigation_data_navigraph_status(),
    ];

    if ($status['cache_ready']) {
        try {
            $status['cache_count'] = (int) db()->query('SELECT COUNT(*) FROM navigation_data_cache')->fetchColumn();
            $status['cache_last_update'] = (string) (db()->query('SELECT MAX(updated_at) FROM navigation_data_cache')->fetchColumn() ?: '');
        } catch (PDOException) {
            $status['cache_ready'] = false;
        }
    }

    if ($status['local_db_ready']) {
        try {
            $stmt = db()->prepare('SELECT COUNT(*) FROM flight_map_nav_points');
            $stmt->execute();
            $status['local_db_count'] = (int) $stmt->fetchColumn();
        } catch (PDOException) {
            $status['local_db_ready'] = false;
        }
    }

    return $status;
}

/**
 * Resolve a navigation identifier through local, cached, and optional remote providers.
 *
 * The default order is intentionally offline-first. Navigraph is used only when
 * the point is not known locally or when a caller explicitly asks for remote data.
 *
 * @param string $ident Ident value.
 * @param array $options Optional behavior flags.
 * @return array<string mixed>|null.
 */
function navigation_data_resolve_ident(string $ident, array $options = []): ?array
{
    $normalizedIdent = navigation_data_normalize_ident($ident);
    if ($normalizedIdent === '') {
        return null;
    }

    $forceRemote = !empty($options['force_remote']);
    $allowRemote = !array_key_exists('allow_remote', $options) || (bool) $options['allow_remote'];

    if (!$forceRemote) {
        $localPoint = navigation_data_local_lookup($normalizedIdent);
        if ($localPoint !== null) {
            return $localPoint;
        }
    }

    $cachedPoint = navigation_data_cache_read($normalizedIdent);
    if ($cachedPoint !== null) {
        return $cachedPoint;
    }

    if ($allowRemote && navigation_data_navigraph_connected()) {
        $remotePoint = navigation_data_navigraph_lookup($normalizedIdent);
        if ($remotePoint !== null) {
            navigation_data_cache_write($normalizedIdent, $remotePoint, NAVIGATION_DATA_SOURCE_NAVIGRAPH, navigation_data_navigraph_cycle());
            return $remotePoint;
        }
    }

    return $forceRemote ? navigation_data_local_lookup($normalizedIdent) : null;
}

/**
 * Resolve one identifier using only local offline providers.
 *
 * @param string $ident Ident value.
 * @return array<string mixed>|null.
 */
function navigation_data_local_lookup(string $ident): ?array
{
    $normalizedIdent = navigation_data_normalize_ident($ident);
    if ($normalizedIdent === '') {
        return null;
    }

    $dbPoint = navigation_data_local_db_lookup($normalizedIdent);
    if ($dbPoint !== null) {
        return $dbPoint;
    }

    return navigation_data_bundled_lookup($normalizedIdent);
}

/**
 * Resolve one identifier from the admin-imported local nav point table.
 *
 * @param string $ident Ident value.
 * @return array<string mixed>|null.
 */
function navigation_data_local_db_lookup(string $ident): ?array
{
    if (!navigation_data_local_db_schema_ready()) {
        return null;
    }

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
    if (!is_array($row)) {
        return null;
    }

    return navigation_data_point_from_values(
        (string) $row['ident'],
        (float) $row['latitude'],
        (float) $row['longitude'],
        (string) ($row['kind'] ?? 'navdata'),
        NAVIGATION_DATA_SOURCE_LOCAL_DB,
        (string) ($row['cycle'] ?? ''),
        (string) ($row['region'] ?? '')
    );
}

/**
 * Resolve one identifier from the bundled offline CSV file.
 *
 * @param string $ident Ident value.
 * @return array<string mixed>|null.
 */
function navigation_data_bundled_lookup(string $ident): ?array
{
    $index = navigation_data_bundled_index();
    $normalizedIdent = navigation_data_normalize_ident($ident);
    return $index[$normalizedIdent] ?? null;
}

/**
 * Load the compact bundled navigation-data index.
 *
 * The bundled dataset is deliberately small and safe to scan into memory. Large
 * imports belong in the database table, which supports indexed incremental lookup.
 *
 * @return array<string array<string, mixed>>.
 */
function navigation_data_bundled_index(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $cache = [];
    $path = (string) navigation_data_config()['bundled_navdata_path'];
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return $cache;
    }

    $stream = fopen($path, 'r');
    if ($stream === false) {
        return $cache;
    }

    try {
        $headers = fgetcsv($stream);
        if (!is_array($headers)) {
            return $cache;
        }
        $headers = array_map(static fn ($header): string => strtolower(trim((string) $header)), $headers);

        while (($values = fgetcsv($stream)) !== false) {
            if (!is_array($values)) {
                continue;
            }
            $row = [];
            foreach ($headers as $index => $name) {
                if ($name !== '') {
                    $row[$name] = $values[$index] ?? '';
                }
            }

            $ident = navigation_data_normalize_ident((string) ($row['ident'] ?? ''));
            $latitude = navigation_data_float_or_null($row['latitude'] ?? $row['lat'] ?? null);
            $longitude = navigation_data_float_or_null($row['longitude'] ?? $row['lon'] ?? $row['lng'] ?? null);
            if ($ident === '' || $latitude === null || $longitude === null) {
                continue;
            }

            $candidateIdents = navigation_data_bundled_ident_candidates($row, $ident);
            foreach ($candidateIdents as $candidateIdent) {
                $point = navigation_data_point_from_values(
                    $candidateIdent,
                    $latitude,
                    $longitude,
                    (string) ($row['kind'] ?? 'waypoint'),
                    (string) ($row['source'] ?? NAVIGATION_DATA_SOURCE_BUNDLED),
                    (string) ($row['cycle'] ?? NAVIGATION_DATA_BUNDLED_CYCLE),
                    (string) ($row['region'] ?? ''),
                    (string) ($row['name'] ?? '')
                );
                if ($point !== null && !isset($cache[$candidateIdent])) {
                    $cache[$candidateIdent] = $point;
                }
            }
        }
    } finally {
        fclose($stream);
    }

    return $cache;
}

/**
 * Return all lookup identifiers represented by one bundled CSV row.
 *
 * @param array $row Row data.
 * @param string $fallbackIdent Fallback ident value.
 * @return array<int string>.
 */
function navigation_data_bundled_ident_candidates(array $row, string $fallbackIdent): array
{
    $candidates = [$fallbackIdent];
    foreach (['icao', 'iata', 'gps_code', 'local_code'] as $field) {
        $candidate = navigation_data_normalize_ident((string) ($row[$field] ?? ''));
        if ($candidate !== '') {
            $candidates[] = $candidate;
        }
    }
    return array_values(array_unique($candidates));
}

/**
 * Build a normalized navigation point payload.
 *
 * @param string $ident Ident value.
 * @param float $latitude Latitude value.
 * @param float $longitude Longitude value.
 * @param string $kind Kind value.
 * @param string $source Source value.
 * @param string $cycle Cycle value.
 * @param string $region Region value.
 * @param string $name Name value.
 * @return array<string mixed>|null.
 */
function navigation_data_point_from_values(string $ident, float $latitude, float $longitude, string $kind, string $source, string $cycle = '', string $region = '', string $name = ''): ?array
{
    if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
        return null;
    }

    $normalizedIdent = navigation_data_normalize_ident($ident);
    if ($normalizedIdent === '') {
        return null;
    }

    $kind = navigation_data_normalize_kind($kind);
    $source = trim($source) !== '' ? substr(trim($source), 0, 64) : NAVIGATION_DATA_SOURCE_BUNDLED;
    $displayName = trim($name) !== '' ? trim($name) : $normalizedIdent;

    return [
        'ident' => $normalizedIdent,
        'name' => substr($displayName, 0, 128),
        'latitude' => round($latitude, 7),
        'longitude' => round($longitude, 7),
        'kind' => $kind,
        'source' => $source,
        'cycle' => substr(trim($cycle) !== '' ? trim($cycle) : NAVIGATION_DATA_BUNDLED_CYCLE, 0, 32),
        'region' => substr(strtoupper(trim($region)), 0, 32),
    ];
}

/**
 * Normalize an airport, fix, or navaid identifier for provider lookup.
 *
 * @param string $ident Ident value.
 * @return string Text result for the caller.
 */
function navigation_data_normalize_ident(string $ident): string
{
    $ident = strtoupper(trim($ident));
    $ident = preg_replace('/[^A-Z0-9_-]/', '', $ident) ?? '';
    return substr($ident, 0, 32);
}

/**
 * Normalize provider kind labels to stable UI/API values.
 *
 * @param string $kind Kind value.
 * @return string Text result for the caller.
 */
function navigation_data_normalize_kind(string $kind): string
{
    $kind = strtolower(trim($kind));
    $aliases = [
        'intersections' => 'fix',
        'intersection' => 'fix',
        'waypoint' => 'fix',
        'airport_icao' => 'airport',
        'airport_iata' => 'airport',
        'dme' => 'navaid',
        'vor/dme' => 'vor',
        'vordme' => 'vor',
    ];
    $kind = $aliases[$kind] ?? $kind;
    if (!in_array($kind, ['airport', 'fix', 'vor', 'ndb', 'navaid', 'airway', 'manual', 'coordinate'], true)) {
        return 'waypoint';
    }
    return $kind;
}

/**
 * Convert a scalar value to float or null.
 *
 * @param mixed $value Value to process.
 * @return ?float Numeric result for the caller.
 */
function navigation_data_float_or_null(mixed $value): ?float
{
    $text = trim((string) $value);
    if ($text === '' || !is_numeric($text)) {
        return null;
    }
    $float = (float) $text;
    return is_finite($float) ? $float : null;
}

/**
 * Read a cached remote point for one identifier.
 *
 * @param string $ident Ident value.
 * @return array<string mixed>|null.
 */
function navigation_data_cache_read(string $ident): ?array
{
    if (!navigation_data_cache_schema_ready()) {
        return null;
    }

    $normalizedIdent = navigation_data_normalize_ident($ident);
    if ($normalizedIdent === '') {
        return null;
    }

    $stmt = db()->prepare("SELECT payload_json, source, cycle FROM navigation_data_cache
        WHERE ident = ? AND (expires_at IS NULL OR expires_at >= ?)
        ORDER BY CASE source WHEN 'navigraph' THEN 0 ELSE 1 END, updated_at DESC
        LIMIT 1");
    $stmt->execute([$normalizedIdent, now_sql()]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        return null;
    }

    $decoded = json_decode((string) ($row['payload_json'] ?? ''), true);
    if (!is_array($decoded)) {
        return null;
    }

    $point = navigation_data_point_from_values(
        (string) ($decoded['ident'] ?? $normalizedIdent),
        (float) ($decoded['latitude'] ?? $decoded['lat'] ?? 0.0),
        (float) ($decoded['longitude'] ?? $decoded['lng'] ?? 0.0),
        (string) ($decoded['kind'] ?? 'waypoint'),
        (string) ($row['source'] ?? NAVIGATION_DATA_SOURCE_REMOTE_CACHE),
        (string) ($row['cycle'] ?? $decoded['cycle'] ?? ''),
        (string) ($decoded['region'] ?? ''),
        (string) ($decoded['name'] ?? '')
    );
    if ($point === null) {
        return null;
    }

    $point['source'] = NAVIGATION_DATA_SOURCE_REMOTE_CACHE;
    $point['remote_source'] = (string) ($row['source'] ?? '');
    return $point;
}

/**
 * Persist a resolved remote point in the shared cache table.
 *
 * @param string $ident Ident value.
 * @param array $point Point value.
 * @param string $source Source value.
 * @param string $cycle Cycle value.
 */
function navigation_data_cache_write(string $ident, array $point, string $source, string $cycle): void
{
    if (!navigation_data_cache_schema_ready()) {
        return;
    }

    $normalizedIdent = navigation_data_normalize_ident($ident);
    if ($normalizedIdent === '') {
        return;
    }

    $normalizedPoint = navigation_data_point_from_values(
        (string) ($point['ident'] ?? $normalizedIdent),
        (float) ($point['latitude'] ?? $point['lat'] ?? 0.0),
        (float) ($point['longitude'] ?? $point['lng'] ?? 0.0),
        (string) ($point['kind'] ?? 'waypoint'),
        $source,
        $cycle,
        (string) ($point['region'] ?? ''),
        (string) ($point['name'] ?? '')
    );
    if ($normalizedPoint === null) {
        return;
    }

    $now = now_sql();
    $expiresAt = gmdate('Y-m-d H:i:s', time() + (int) navigation_data_config()['cache_ttl_seconds']);
    $cacheKey = hash('sha256', implode('|', [$source, $cycle, $normalizedIdent, $normalizedPoint['kind']]));

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
    $stmt->execute([
        $cacheKey,
        $normalizedIdent,
        (string) $normalizedPoint['kind'],
        substr(trim($source), 0, 64),
        substr(trim($cycle) !== '' ? trim($cycle) : NAVIGATION_DATA_BUNDLED_CYCLE, 0, 32),
        json_encode($normalizedPoint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $expiresAt,
        $now,
        $now,
    ]);
}

/**
 * Return whether Navigraph is configured enough to start OAuth.
 *
 * @return bool True when the condition matches.
 */
function navigation_data_navigraph_configured(): bool
{
    $config = navigation_data_config()['navigraph'];
    return !empty($config['enabled']) && trim((string) ($config['client_id'] ?? '')) !== '';
}

/**
 * Return whether the current admin session contains a Navigraph token set.
 *
 * @return bool True when the condition matches.
 */
function navigation_data_navigraph_connected(): bool
{
    $session = navigation_data_navigraph_session();
    return trim((string) ($session['access_token'] ?? '')) !== '' || trim((string) ($session['refresh_token'] ?? '')) !== '';
}

/**
 * Return the stored Navigraph session data.
 *
 * @return array<string mixed>.
 */
function navigation_data_navigraph_session(): array
{
    $session = $_SESSION['navigation_data_navigraph'] ?? [];
    if (is_array($session) && (trim((string) ($session['access_token'] ?? '')) !== '' || trim((string) ($session['refresh_token'] ?? '')) !== '')) {
        return $session;
    }

    $storedSession = navigation_data_navigraph_load_account_session();
    if ($storedSession !== []) {
        $_SESSION['navigation_data_navigraph'] = $storedSession;
        return $storedSession;
    }

    return is_array($session) ? $session : [];
}

/**
 * Store Navigraph session data after token exchange or refresh.
 *
 * @param array $tokenPayload Token payload value.
 */
function navigation_data_navigraph_store_tokens(array $tokenPayload): void
{
    $previousSession = navigation_data_navigraph_session();
    $accessToken = (string) ($tokenPayload['access_token'] ?? '');
    $refreshToken = (string) ($tokenPayload['refresh_token'] ?? '');
    $idToken = (string) ($tokenPayload['id_token'] ?? '');
    $expiresIn = max(0, (int) ($tokenPayload['expires_in'] ?? 0));
    $claims = navigation_data_jwt_payload($accessToken);
    if ($claims === [] && $idToken !== '') {
        $claims = navigation_data_jwt_payload($idToken);
    }

    $_SESSION['navigation_data_navigraph'] = [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken !== '' ? $refreshToken : (string) ($previousSession['refresh_token'] ?? ''),
        'id_token' => $idToken !== '' ? $idToken : (string) ($previousSession['id_token'] ?? ''),
        'expires_at' => $expiresIn > 0 ? time() + $expiresIn - 60 : 0,
        'scope' => (string) ($tokenPayload['scope'] ?? ($previousSession['scope'] ?? '')),
        'subscription' => $claims['subscription'] ?? ($previousSession['subscription'] ?? null),
        'claims' => $claims !== [] ? $claims : (is_array($previousSession['claims'] ?? null) ? $previousSession['claims'] : []),
        'package_cycle' => (string) ($previousSession['package_cycle'] ?? ''),
        'package_status' => (string) ($previousSession['package_status'] ?? ''),
        'package_format' => (string) ($previousSession['package_format'] ?? ''),
        'package_checked_at' => (string) ($previousSession['package_checked_at'] ?? ''),
        'updated_at' => now_sql(),
    ];

    navigation_data_navigraph_persist_session($_SESSION['navigation_data_navigraph']);
}

/**
 * Clear the Navigraph token set from the current session.
 */
function navigation_data_navigraph_disconnect(): void
{
    navigation_data_navigraph_delete_account_session();
    unset($_SESSION['navigation_data_navigraph'], $_SESSION['navigation_data_navigraph_oauth']);
}

/**
 * Return a status snapshot for Navigraph UI and diagnostics.
 *
 * @return array<string mixed>.
 */
function navigation_data_navigraph_status(): array
{
    $config = navigation_data_config()['navigraph'];
    $session = navigation_data_navigraph_session();
    $claims = is_array($session['claims'] ?? null) ? $session['claims'] : [];
    $displayName = navigation_data_navigraph_display_name($claims);
    $tokenExpiresAt = (int) ($session['expires_at'] ?? 0);

    return [
        'enabled' => !empty($config['enabled']),
        'configured' => navigation_data_navigraph_configured(),
        'connected' => navigation_data_navigraph_connected(),
        'account_storage_ready' => navigation_data_account_schema_ready(),
        'persistent_connection' => navigation_data_navigraph_account_exists(),
        'client_id_present' => trim((string) ($config['client_id'] ?? '')) !== '',
        'lookup_endpoint_configured' => trim((string) ($config['lookup_endpoint_template'] ?? '')) !== '',
        'packages_endpoint' => (string) ($config['packages_endpoint'] ?? ''),
        'redirect_uri' => navigation_data_navigraph_redirect_uri(),
        'package_cycle' => (string) ($session['package_cycle'] ?? ''),
        'package_status' => (string) ($session['package_status'] ?? ''),
        'package_format' => (string) ($session['package_format'] ?? ''),
        'package_checked_at' => (string) ($session['package_checked_at'] ?? ''),
        'token_expires_at' => $tokenExpiresAt,
        'access_token_valid' => $tokenExpiresAt > time(),
        'scope' => (string) ($session['scope'] ?? ''),
        'display_name' => $displayName,
        'subscription' => $session['subscription'] ?? null,
    ];
}

/**
 * Return the current authenticated admin user id when available.
 *
 * @return int Integer result for the caller.
 */
function navigation_data_current_user_id(): int
{
    if (!function_exists('Gallery\\Core\\current_user')) {
        return 0;
    }
    $user = current_user();
    return is_array($user) ? max(0, (int) ($user['id'] ?? 0)) : 0;
}

/**
 * Return whether a persistent Navigraph account row exists for this admin.
 *
 * @return bool True when the condition matches.
 */
function navigation_data_navigraph_account_exists(): bool
{
    if (!navigation_data_account_schema_ready()) {
        return false;
    }
    $userId = navigation_data_current_user_id();
    if ($userId <= 0) {
        return false;
    }

    try {
        $stmt = db()->prepare("SELECT 1 FROM navigation_data_accounts WHERE user_id = ? AND provider = 'navigraph' LIMIT 1");
        $stmt->execute([$userId]);
        return (bool) $stmt->fetchColumn();
    } catch (PDOException) {
        return false;
    }
}

/**
 * Load a persisted Navigraph session for the current admin user.
 *
 * @return array<string mixed>.
 */
function navigation_data_navigraph_load_account_session(): array
{
    if (!navigation_data_account_schema_ready()) {
        return [];
    }
    $userId = navigation_data_current_user_id();
    if ($userId <= 0) {
        return [];
    }

    try {
        $stmt = db()->prepare("SELECT * FROM navigation_data_accounts WHERE user_id = ? AND provider = 'navigraph' LIMIT 1");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
    } catch (PDOException) {
        return [];
    }

    if (!is_array($row)) {
        return [];
    }

    $accessToken = navigation_data_decrypt_secret((string) ($row['access_token_cipher'] ?? ''));
    $refreshToken = navigation_data_decrypt_secret((string) ($row['refresh_token_cipher'] ?? ''));
    $idToken = navigation_data_decrypt_secret((string) ($row['id_token_cipher'] ?? ''));
    if ($accessToken === '' && $refreshToken === '') {
        return [];
    }

    $claims = json_decode((string) ($row['claims_json'] ?? ''), true);
    $subscription = json_decode((string) ($row['subscription_json'] ?? ''), true);

    return [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'id_token' => $idToken,
        'expires_at' => (int) ($row['token_expires_at'] ?? 0),
        'scope' => (string) ($row['scope_text'] ?? ''),
        'subscription' => is_array($subscription) ? $subscription : null,
        'claims' => is_array($claims) ? $claims : [],
        'package_cycle' => (string) ($row['package_cycle'] ?? ''),
        'package_status' => (string) ($row['package_status'] ?? ''),
        'package_format' => (string) ($row['package_format'] ?? ''),
        'package_checked_at' => (string) ($row['package_checked_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

/**
 * Persist the current Navigraph session for the active admin user.
 *
 * @param array $session Session value.
 */
function navigation_data_navigraph_persist_session(array $session): void
{
    if (!navigation_data_account_schema_ready()) {
        return;
    }
    $userId = navigation_data_current_user_id();
    if ($userId <= 0) {
        return;
    }

    $accessTokenCipher = navigation_data_encrypt_secret((string) ($session['access_token'] ?? ''));
    $refreshTokenCipher = navigation_data_encrypt_secret((string) ($session['refresh_token'] ?? ''));
    $idTokenCipher = navigation_data_encrypt_secret((string) ($session['id_token'] ?? ''));
    if ($accessTokenCipher === '' && $refreshTokenCipher === '') {
        return;
    }

    $now = now_sql();
    $claims = is_array($session['claims'] ?? null) ? $session['claims'] : [];
    $subscription = is_array($session['subscription'] ?? null) ? $session['subscription'] : null;

    try {
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
            $accessTokenCipher,
            $refreshTokenCipher,
            $idTokenCipher,
            max(0, (int) ($session['expires_at'] ?? 0)),
            substr((string) ($session['scope'] ?? ''), 0, 512),
            json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $subscription !== null ? json_encode($subscription, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            substr((string) ($session['package_cycle'] ?? ''), 0, 32),
            substr((string) ($session['package_status'] ?? ''), 0, 64),
            substr((string) ($session['package_format'] ?? ''), 0, 64),
            trim((string) ($session['package_checked_at'] ?? '')) !== '' ? (string) $session['package_checked_at'] : null,
            $now,
            $now,
        ]);
    } catch (PDOException $exception) {
        admin_log_event('warning', 'navigation_data.navigraph_persist_failed', 'Navigraph account session could not be persisted.', [
            'exception' => $exception->getMessage(),
        ]);
    }
}

/**
 * Delete the persisted Navigraph session for the active admin user.
 */
function navigation_data_navigraph_delete_account_session(): void
{
    if (!navigation_data_account_schema_ready()) {
        return;
    }
    $userId = navigation_data_current_user_id();
    if ($userId <= 0) {
        return;
    }

    try {
        $stmt = db()->prepare("DELETE FROM navigation_data_accounts WHERE user_id = ? AND provider = 'navigraph'");
        $stmt->execute([$userId]);
    } catch (PDOException $exception) {
        admin_log_event('warning', 'navigation_data.navigraph_delete_failed', 'Navigraph account session could not be deleted.', [
            'exception' => $exception->getMessage(),
        ]);
    }
}

/**
 * Return a display name from OAuth claims without trusting it for authorization.
 *
 * @param array $claims Claims value.
 * @return string Text result for the caller.
 */
function navigation_data_navigraph_display_name(array $claims): string
{
    foreach (['name', 'preferred_username', 'email', 'sub'] as $field) {
        $value = trim((string) ($claims[$field] ?? ''));
        if ($value !== '') {
            return substr($value, 0, 190);
        }
    }
    return '';
}

/**
 * Encrypt an OAuth token for database storage.
 *
 * @param string $secret Secret value.
 * @return string Text result for the caller.
 */
function navigation_data_encrypt_secret(string $secret): string
{
    if ($secret === '') {
        return '';
    }
    if (!function_exists('openssl_encrypt')) {
        return '';
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($secret, 'aes-256-gcm', navigation_data_secret_key(), OPENSSL_RAW_DATA, $iv, $tag, 'navigation-data');
    if ($cipher === false || $tag === '') {
        return '';
    }

    return 'v1:' . base64_encode(json_encode([
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
        'cipher' => base64_encode($cipher),
    ], JSON_UNESCAPED_SLASHES));
}

/**
 * Decrypt an OAuth token previously written by navigation_data_encrypt_secret().
 *
 * @param string $encoded Encoded value.
 * @return string Text result for the caller.
 */
function navigation_data_decrypt_secret(string $encoded): string
{
    if ($encoded === '' || !str_starts_with($encoded, 'v1:') || !function_exists('openssl_decrypt')) {
        return '';
    }

    $json = base64_decode(substr($encoded, 3), true);
    if ($json === false) {
        return '';
    }
    $payload = json_decode($json, true);
    if (!is_array($payload)) {
        return '';
    }

    $iv = base64_decode((string) ($payload['iv'] ?? ''), true);
    $tag = base64_decode((string) ($payload['tag'] ?? ''), true);
    $cipher = base64_decode((string) ($payload['cipher'] ?? ''), true);
    if ($iv === false || $tag === false || $cipher === false) {
        return '';
    }

    $plain = openssl_decrypt($cipher, 'aes-256-gcm', navigation_data_secret_key(), OPENSSL_RAW_DATA, $iv, $tag, 'navigation-data');
    return $plain === false ? '' : $plain;
}

/**
 * Return the binary encryption key used for optional OAuth token persistence.
 *
 * @return string Text result for the caller.
 */
function navigation_data_secret_key(): string
{
    $config = cms_config();
    $navigation = is_array($config['navigation_data'] ?? null) ? $config['navigation_data'] : [];
    $navigraph = is_array($navigation['navigraph'] ?? null) ? $navigation['navigraph'] : [];
    $configuredKey = trim((string) ($navigraph['token_encryption_key'] ?? ''));
    $keyMaterial = $configuredKey !== '' ? $configuredKey : implode('|', [
        (string) ($config['visitor_vote_secret'] ?? ''),
        (string) ($config['setup_key'] ?? ''),
        (string) ($config['base_url'] ?? ''),
        is_array($config['database'] ?? null) ? (string) ($config['database']['password'] ?? '') : '',
    ]);

    return hash('sha256', $keyMaterial !== '' ? $keyMaterial : __FILE__, true);
}

/**
 * Return the best known Navigraph AIRAC cycle for cache partitioning.
 *
 * @return string Text result for the caller.
 */
function navigation_data_navigraph_cycle(): string
{
    $session = navigation_data_navigraph_session();
    $cycle = trim((string) ($session['package_cycle'] ?? ''));
    return $cycle !== '' ? $cycle : gmdate('Ymd');
}

/**
 * Build the OAuth authorization URL and store PKCE state in the session.
 *
 * @return string Text result for the caller.
 */
function navigation_data_navigraph_authorization_url(): string
{
    if (!navigation_data_navigraph_configured()) {
        throw new RuntimeException('Navigraph OAuth is not configured. Add navigation_data.navigraph.client_id to config.php first.');
    }

    $config = navigation_data_config()['navigraph'];
    $state = navigation_data_random_url_token(32);
    $verifier = navigation_data_random_url_token(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $redirectUri = navigation_data_navigraph_redirect_uri();

    $_SESSION['navigation_data_navigraph_oauth'] = [
        'state' => $state,
        'verifier' => $verifier,
        'created_at' => time(),
    ];

    $query = http_build_query([
        'client_id' => (string) $config['client_id'],
        'response_type' => 'code',
        'redirect_uri' => $redirectUri,
        'scope' => (string) ($config['scope'] ?? 'openid profile offline_access'),
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]);

    return rtrim((string) $config['authorization_endpoint'], '?') . '?' . $query;
}

/**
 * Exchange an OAuth code for Navigraph tokens and store them in the session.
 *
 * @param string $code Code value.
 * @param string $state State value.
 */
function navigation_data_navigraph_exchange_code(string $code, string $state): void
{
    $oauth = $_SESSION['navigation_data_navigraph_oauth'] ?? [];
    if (!is_array($oauth) || !hash_equals((string) ($oauth['state'] ?? ''), $state)) {
        throw new RuntimeException('Navigraph login state did not match. Start the connection again.');
    }

    $createdAt = (int) ($oauth['created_at'] ?? 0);
    if ($createdAt <= 0 || time() - $createdAt > 900) {
        throw new RuntimeException('Navigraph login state expired. Start the connection again.');
    }

    $config = navigation_data_config()['navigraph'];
    $fields = [
        'grant_type' => 'authorization_code',
        'client_id' => (string) $config['client_id'],
        'code' => $code,
        'redirect_uri' => navigation_data_navigraph_redirect_uri(),
        'code_verifier' => (string) ($oauth['verifier'] ?? ''),
    ];
    if (trim((string) ($config['client_secret'] ?? '')) !== '') {
        $fields['client_secret'] = (string) $config['client_secret'];
    }

    $response = navigation_data_http_post_form((string) $config['token_endpoint'], $fields, 30);
    $payload = json_decode($response, true);
    if (!is_array($payload) || trim((string) ($payload['access_token'] ?? '')) === '') {
        throw new RuntimeException('Navigraph token response did not contain an access token.');
    }

    navigation_data_navigraph_store_tokens($payload);
    unset($_SESSION['navigation_data_navigraph_oauth']);
}

/**
 * Refresh the Navigraph access token when possible.
 *
 * @return bool True when the condition matches.
 */
function navigation_data_navigraph_refresh_token_if_needed(): bool
{
    $session = navigation_data_navigraph_session();
    $expiresAt = (int) ($session['expires_at'] ?? 0);
    if ($expiresAt > time() + 120 && trim((string) ($session['access_token'] ?? '')) !== '') {
        return true;
    }

    $refreshToken = trim((string) ($session['refresh_token'] ?? ''));
    if ($refreshToken === '' || !navigation_data_navigraph_configured()) {
        return false;
    }

    $config = navigation_data_config()['navigraph'];
    $fields = [
        'grant_type' => 'refresh_token',
        'client_id' => (string) $config['client_id'],
        'refresh_token' => $refreshToken,
    ];
    if (trim((string) ($config['client_secret'] ?? '')) !== '') {
        $fields['client_secret'] = (string) $config['client_secret'];
    }

    try {
        $response = navigation_data_http_post_form((string) $config['token_endpoint'], $fields, 30);
        $payload = json_decode($response, true);
        if (!is_array($payload) || trim((string) ($payload['access_token'] ?? '')) === '') {
            return false;
        }
        navigation_data_navigraph_store_tokens($payload);
        return true;
    } catch (Throwable $exception) {
        admin_log_event('warning', 'navigation_data.navigraph_refresh_failed', 'Navigraph token refresh failed.', [
            'exception' => $exception->getMessage(),
        ]);
        return false;
    }
}

/**
 * Refresh the cached Navigraph package metadata for the current session.
 *
 * @return array<string mixed>.
 */
function navigation_data_navigraph_refresh_packages(): array
{
    if (!navigation_data_navigraph_refresh_token_if_needed()) {
        throw new RuntimeException('Navigraph is not connected or the token could not be refreshed.');
    }

    $config = navigation_data_config()['navigraph'];
    $url = (string) ($config['packages_endpoint'] ?? '');
    if ($url === '') {
        throw new RuntimeException('Navigraph packages endpoint is not configured.');
    }

    $format = trim((string) ($config['package_format'] ?? ''));
    if ($format !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query(['format' => $format]);
    }

    $session = navigation_data_navigraph_session();
    $response = navigation_data_http_get_json($url, [
        'Authorization: Bearer ' . (string) ($session['access_token'] ?? ''),
        'Accept: application/json',
    ], 30);
    $packages = json_decode($response, true);
    if (!is_array($packages)) {
        throw new RuntimeException('Navigraph packages response was not valid JSON.');
    }

    $bestPackage = navigation_data_navigraph_select_best_package($packages);
    if ($bestPackage === null) {
        throw new RuntimeException('Navigraph packages response did not contain a usable package.');
    }

    $_SESSION['navigation_data_navigraph'] = navigation_data_navigraph_session() + [];
    $_SESSION['navigation_data_navigraph']['package_cycle'] = (string) ($bestPackage['cycle'] ?? '');
    $_SESSION['navigation_data_navigraph']['package_status'] = (string) ($bestPackage['package_status'] ?? '');
    $_SESSION['navigation_data_navigraph']['package_format'] = (string) ($bestPackage['format'] ?? '');
    $_SESSION['navigation_data_navigraph']['package_checked_at'] = now_sql();
    navigation_data_navigraph_persist_session($_SESSION['navigation_data_navigraph']);

    return [
        'cycle' => (string) ($bestPackage['cycle'] ?? ''),
        'status' => (string) ($bestPackage['package_status'] ?? ''),
        'format' => (string) ($bestPackage['format'] ?? ''),
        'file_count' => count((array) ($bestPackage['files'] ?? [])),
    ];
}

/**
 * Select the most useful package from a Navigraph packages response.
 *
 * @param array $packages Packages value.
 * @return array<string mixed>|null.
 */
function navigation_data_navigraph_select_best_package(array $packages): ?array
{
    $flatPackages = array_values(array_filter($packages, static fn ($package): bool => is_array($package)));
    if ($flatPackages === []) {
        return null;
    }

    usort($flatPackages, static function (array $left, array $right): int {
        $statusRank = ['current' => 0, 'outdated' => 1, 'future' => 2];
        $leftRank = $statusRank[(string) ($left['package_status'] ?? '')] ?? 9;
        $rightRank = $statusRank[(string) ($right['package_status'] ?? '')] ?? 9;
        if ($leftRank !== $rightRank) {
            return $leftRank <=> $rightRank;
        }
        return strcmp((string) ($right['cycle'] ?? ''), (string) ($left['cycle'] ?? ''));
    });

    return $flatPackages[0];
}

/**
 * Try a configured Navigraph-compatible point lookup endpoint.
 *
 * Navigraph's official Navigation Data API is package-based. This hook supports
 * approved custom formats or a thin private lookup endpoint without hard-coding
 * a non-existent public point-search API.
 *
 * @param string $ident Ident value.
 * @return array<string mixed>|null.
 */
function navigation_data_navigraph_lookup(string $ident): ?array
{
    $config = navigation_data_config()['navigraph'];
    $template = trim((string) ($config['lookup_endpoint_template'] ?? ''));
    if ($template === '') {
        return null;
    }

    if (!navigation_data_navigraph_refresh_token_if_needed()) {
        return null;
    }

    $session = navigation_data_navigraph_session();
    $url = strtr($template, [
        '{ident}' => rawurlencode($ident),
        '{cycle}' => rawurlencode(navigation_data_navigraph_cycle()),
    ]);

    try {
        $response = navigation_data_http_get_json($url, [
            'Authorization: Bearer ' . (string) ($session['access_token'] ?? ''),
            'Accept: application/json',
        ], 20);
        $payload = json_decode($response, true);
        if (!is_array($payload)) {
            return null;
        }
        $pointPayload = isset($payload[0]) && is_array($payload[0]) ? $payload[0] : $payload;
        return navigation_data_point_from_values(
            (string) ($pointPayload['ident'] ?? $ident),
            (float) ($pointPayload['latitude'] ?? $pointPayload['lat'] ?? 0.0),
            (float) ($pointPayload['longitude'] ?? $pointPayload['lng'] ?? 0.0),
            (string) ($pointPayload['kind'] ?? $pointPayload['type'] ?? 'waypoint'),
            NAVIGATION_DATA_SOURCE_NAVIGRAPH,
            navigation_data_navigraph_cycle(),
            (string) ($pointPayload['region'] ?? ''),
            (string) ($pointPayload['name'] ?? '')
        );
    } catch (Throwable $exception) {
        admin_log_event('warning', 'navigation_data.navigraph_lookup_failed', 'Navigraph point lookup failed.', [
            'ident' => $ident,
            'exception' => $exception->getMessage(),
        ]);
        return null;
    }
}

/**
 * Build the effective Navigraph OAuth callback URI.
 *
 * @return string Text result for the caller.
 */
function navigation_data_navigraph_redirect_uri(): string
{
    $configured = trim((string) (navigation_data_config()['navigraph']['redirect_uri'] ?? ''));
    if ($configured !== '') {
        return $configured;
    }
    return absolute_public_url(url_for('admin_navigraph_callback'));
}

/**
 * Return a random URL-safe token.
 *
 * @param int $bytes Bytes value.
 * @return string Text result for the caller.
 */
function navigation_data_random_url_token(int $bytes): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/**
 * Decode JWT payload claims without using them as proof of identity.
 *
 * The token was received directly from the token endpoint over HTTPS. The decoded
 * payload is used only for diagnostics and UI hints, not for granting admin access.
 *
 * @param string $jwt Jwt value.
 * @return array<string mixed>.
 */
function navigation_data_jwt_payload(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return [];
    }
    $payload = strtr($parts[1], '-_', '+/');
    $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
    $decoded = base64_decode($payload, true);
    if ($decoded === false) {
        return [];
    }
    $json = json_decode($decoded, true);
    return is_array($json) ? $json : [];
}

/**
 * POST an application/x-www-form-urlencoded request.
 *
 * @param string $url URL used by this workflow.
 * @param array $fields Fields value.
 * @param int $timeoutSeconds Timeout seconds value.
 * @return string Text result for the caller.
 */
function navigation_data_http_post_form(string $url, array $fields, int $timeoutSeconds): string
{
    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize HTTP client.');
        }
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 15),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'PHP-Gallery-CMS/' . (function_exists('Gallery\\Core\\cms_current_version') ? cms_current_version() : 'dev'),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status >= 400) {
            throw new RuntimeException($error !== '' ? $error : 'HTTP request failed with status ' . $status . '.');
        }
        return (string) $body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeoutSeconds,
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\nUser-Agent: PHP-Gallery-CMS\r\n",
            'content' => http_build_query($fields),
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('HTTP request failed. Enable curl or allow_url_fopen.');
    }
    return (string) $body;
}

/**
 * GET a JSON endpoint with optional headers.
 *
 * @param string $url URL used by this workflow.
 * @param array $headers Headers value.
 * @param int $timeoutSeconds Timeout seconds value.
 * @return string Text result for the caller.
 */
function navigation_data_http_get_json(string $url, array $headers, int $timeoutSeconds): string
{
    if (function_exists('Gallery\\Services\\http_fetch_with_headers')) {
        return http_fetch_with_headers($url, $timeoutSeconds, $headers);
    }

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Could not initialize HTTP client.');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => min($timeoutSeconds, 15),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'PHP-Gallery-CMS/' . (function_exists('Gallery\\Core\\cms_current_version') ? cms_current_version() : 'dev'),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $status >= 400) {
            throw new RuntimeException($error !== '' ? $error : 'HTTP request failed with status ' . $status . '.');
        }
        return (string) $body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'header' => implode("\r\n", array_merge($headers, ['User-Agent: PHP-Gallery-CMS'])) . "\r\n",
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('HTTP request failed. Enable curl or allow_url_fopen.');
    }
    return (string) $body;
}
