<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_gallery_report/gps.php
 * Module Type: Service
 *
 * Purpose:
 *   Approximate GPS clustering and known-place resolution for report maps.
 *
 * Responsibilities:
 *   - Cluster image coordinates into bounded approximate areas
 *   - Exclude probable simulator or game captures from real-world clusters
 *   - Resolve the nearest known place label for a cluster
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
 *   - Loaded by app/services/admin_gallery_report.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_gallery_report.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;
use function Gallery\Core\cms_config;
use function Gallery\Core\cms_current_version;
use function Gallery\Core\db;

/**
 * Return whether the image GPS point is probably from a simulator or game capture.
 *
 * @param array $row Image row.
 * @return bool True when the GPS point should not be used for real-world place clustering.
 */
function admin_gallery_report_is_probable_game_gps(array $row): bool
{
    $filename = strtolower((string) ($row['filename'] ?? $row['relative_path'] ?? ''));
    $extension = admin_gallery_report_file_extension($filename);
    $mime = strtolower((string) ($row['mime_type'] ?? ''));
    $camera = trim((string) ($row['exif_camera_make'] ?? '') . ' ' . (string) ($row['exif_camera_model'] ?? '') . ' ' . (string) ($row['exif_lens_model'] ?? ''));
    $hasCameraExif = $camera !== '' || trim((string) ($row['exif_focal_length'] ?? '')) !== '' || trim((string) ($row['exif_aperture'] ?? '')) !== '' || trim((string) ($row['exif_exposure_time'] ?? '')) !== '' || (int) ($row['exif_iso'] ?? 0) > 0;
    $nameLooksLikeCapture = preg_match('/(msfs|flight[ _-]?sim|simconnect|xplane|x-plane|dcs|elite[ _-]?dangerous|screenshot|screen[ _-]?shot|steam|geforce|nvidia|2537590_[0-9]{14})/i', $filename) === 1;
    if ($nameLooksLikeCapture) {
        return true;
    }
    if (!$hasCameraExif && in_array($extension, ['png', 'webp', 'bmp'], true)) {
        return true;
    }
    if (!$hasCameraExif && str_contains($mime, 'png')) {
        return true;
    }
    return false;
}

/**
 * Add a GPS point to an approximate 20 km area cluster.
 *
 * @param array $clusters Cluster accumulator.
 * @param array $row Image row.
 * @param float $lat Latitude.
 * @param float $lng Longitude.
 */
function admin_gallery_report_accumulate_gps_cluster(array &$clusters, array $row, float $lat, float $lng): void
{
    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
        return;
    }

    $key = admin_gallery_report_find_gps_cluster_key($clusters, $lat, $lng);
    if ($key === '') {
        $key = admin_gallery_report_new_gps_cluster_key($clusters);
        $clusters[$key] = admin_gallery_report_empty_gps_cluster($lat, $lng);
    }

    $cluster = &$clusters[$key];
    $cluster['count'] = (int) ($cluster['count'] ?? 0) + 1;
    $cluster['lat_sum'] = (float) ($cluster['lat_sum'] ?? 0.0) + $lat;
    $cluster['lng_sum'] = (float) ($cluster['lng_sum'] ?? 0.0) + $lng;
    $cluster['lat_min'] = min((float) ($cluster['lat_min'] ?? $lat), $lat);
    $cluster['lat_max'] = max((float) ($cluster['lat_max'] ?? $lat), $lat);
    $cluster['lng_min'] = min((float) ($cluster['lng_min'] ?? $lng), $lng);
    $cluster['lng_max'] = max((float) ($cluster['lng_max'] ?? $lng), $lng);
    $galleryId = (int) ($row['gallery_id'] ?? $row['image_gallery_id'] ?? 0);
    if ($galleryId > 0) {
        $cluster['gallery_ids'][$galleryId] = true;
        $label = trim((string) ($row['gallery_title'] ?? ''));
        if ($label === '') {
            $label = trim((string) ($row['gallery_folder_path'] ?? ''));
        }
        if ($label !== '' && count($cluster['gallery_labels']) < 8) {
            $cluster['gallery_labels'][$label] = true;
        }
    }
    $date = (string) ($row['exif_taken_at'] ?? '');
    if (admin_gallery_report_valid_datetime($date)) {
        admin_gallery_report_update_date_range($cluster, 'first_date', 'last_date', $date);
    }
    if (count($cluster['sample_images']) < 6) {
        $cluster['sample_images'][] = (string) ($row['relative_path'] ?? $row['filename'] ?? '');
    }
}

/**
 * Find an existing GPS cluster whose centroid is close enough for city-scale grouping.
 *
 * @param array $clusters Cluster accumulator.
 * @param float $lat Latitude.
 * @param float $lng Longitude.
 * @return string Existing cluster key or empty string.
 */
function admin_gallery_report_find_gps_cluster_key(array $clusters, float $lat, float $lng): string
{
    $bestKey = '';
    $bestDistance = ADMIN_GALLERY_REPORT_GPS_AREA_KM;
    foreach ($clusters as $key => $cluster) {
        if (!is_array($cluster)) {
            continue;
        }
        $count = max(1, (int) ($cluster['count'] ?? 0));
        $clusterLat = (float) ($cluster['lat_sum'] ?? 0.0) / $count;
        $clusterLng = (float) ($cluster['lng_sum'] ?? 0.0) / $count;
        $distance = admin_gallery_report_haversine_km($lat, $lng, $clusterLat, $clusterLng);
        if ($distance <= ADMIN_GALLERY_REPORT_GPS_AREA_KM && $distance <= $bestDistance) {
            $bestKey = (string) $key;
            $bestDistance = $distance;
        }
    }
    return $bestKey;
}

/**
 * Return a new GPS cluster key.
 *
 * @param array $clusters Cluster accumulator.
 * @return string New cluster key.
 */
function admin_gallery_report_new_gps_cluster_key(array $clusters): string
{
    return 'cluster-' . (count($clusters) + 1);
}

/**
 * Return an empty GPS cluster accumulator.
 *
 * @param float $lat Initial latitude.
 * @param float $lng Initial longitude.
 * @return array<string, mixed> Cluster accumulator.
 */
function admin_gallery_report_empty_gps_cluster(float $lat, float $lng): array
{
    return [
        'count' => 0,
        'lat_sum' => 0.0,
        'lng_sum' => 0.0,
        'lat_min' => $lat,
        'lat_max' => $lat,
        'lng_min' => $lng,
        'lng_max' => $lng,
        'gallery_ids' => [],
        'gallery_labels' => [],
        'first_date' => null,
        'last_date' => null,
        'sample_images' => [],
    ];
}

/**
 * Return finalized GPS cluster rows.
 *
 * @param array $clusters Cluster accumulator.
 * @return array<int, array<string, mixed>> Cluster rows.
 */
function admin_gallery_report_finalize_gps_clusters(array $clusters): array
{
    $rows = [];
    foreach ($clusters as $cluster) {
        if (!is_array($cluster)) {
            continue;
        }
        $count = max(1, (int) ($cluster['count'] ?? 0));
        $lat = (float) ($cluster['lat_sum'] ?? 0.0) / $count;
        $lng = (float) ($cluster['lng_sum'] ?? 0.0) / $count;
        $place = admin_gallery_report_nearest_known_place($lat, $lng);
        $rows[] = [
            'label' => $place['label'],
            'nearest_reference' => $place['nearest_reference'],
            'place_kind' => $place['place_kind'],
            'place_match' => $place['place_match'] ?? '',
            'place_distance_km' => $place['distance_km'],
            'count' => $count,
            'lat' => round($lat, 5),
            'lng' => round($lng, 5),
            'lat_min' => round((float) ($cluster['lat_min'] ?? $lat), 5),
            'lat_max' => round((float) ($cluster['lat_max'] ?? $lat), 5),
            'lng_min' => round((float) ($cluster['lng_min'] ?? $lng), 5),
            'lng_max' => round((float) ($cluster['lng_max'] ?? $lng), 5),
            'gallery_count' => count(is_array($cluster['gallery_ids'] ?? null) ? $cluster['gallery_ids'] : []),
            'gallery_labels' => array_keys(is_array($cluster['gallery_labels'] ?? null) ? $cluster['gallery_labels'] : []),
            'first_date' => $cluster['first_date'] ?? '',
            'last_date' => $cluster['last_date'] ?? '',
            'sample_images' => is_array($cluster['sample_images'] ?? null) ? $cluster['sample_images'] : [],
        ];
    }
    usort($rows, static fn (array $a, array $b): int => ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0)));
    return array_slice($rows, 0, 120);
}

/**
 * Return nearest known place from an offline reference list.
 *
 * @param float $lat Latitude.
 * @param float $lng Longitude.
 * @return array<string, mixed> Place label and distance.
 */
function admin_gallery_report_nearest_known_place(float $lat, float $lng): array
{
    $places = admin_gallery_report_known_places();
    $best = null;
    $nearest = null;
    foreach ($places as $place) {
        $distance = admin_gallery_report_haversine_km($lat, $lng, (float) $place['lat'], (float) $place['lng']);
        $candidate = [
            'name' => (string) $place['name'],
            'label' => admin_gallery_report_place_display_label($place),
            'kind' => (string) ($place['kind'] ?? 'city'),
            'distance_km' => $distance,
            'priority' => (float) ($place['priority'] ?? 0.0),
        ];

        if ($nearest === null || $distance < (float) $nearest['distance_km']) {
            $nearest = $candidate;
        }

        $radius = (float) ($place['radius_km'] ?? ADMIN_GALLERY_REPORT_PLACE_MATCH_DEFAULT_RADIUS_KM);
        if ($distance > $radius) {
            continue;
        }
        $score = $distance - (float) $candidate['priority'];
        if ($best === null || $score < (float) $best['score']) {
            $best = $candidate + ['score' => $score];
        }
    }

    if ($best !== null) {
        return [
            'label' => (string) $best['label'],
            'nearest_reference' => (string) $best['name'],
            'place_kind' => (string) $best['kind'],
            'place_match' => t('admin.gallery_report.export.within_radius', 'within radius'),
            'distance_km' => round((float) $best['distance_km'], 1),
        ];
    }

    if ($nearest !== null) {
        return [
            'label' => t('admin.gallery_report.export.closest_known_area', 'Closest known area: {area}', ['area' => (string) $nearest['label']]),
            'nearest_reference' => (string) $nearest['name'],
            'place_kind' => (string) $nearest['kind'],
            'place_match' => t('admin.gallery_report.export.nearest_fallback', 'nearest fallback'),
            'distance_km' => round((float) $nearest['distance_km'], 1),
        ];
    }

    return [
        'label' => t('admin.gallery_report.export.area_around_coordinates', 'Area around {lat}, {lng}', ['lat' => number_format($lat, 3, '.', ''), 'lng' => number_format($lng, 3, '.', '')]),
        'nearest_reference' => '',
        'place_kind' => 'coordinate fallback',
        'place_match' => t('admin.gallery_report.export.coordinate_fallback', 'coordinate fallback'),
        'distance_km' => null,
    ];
}

/**
 * Return the public label for an offline place reference.
 *
 * @param array $place Place definition.
 * @return string Human-readable place label.
 */
function admin_gallery_report_place_display_label(array $place): string
{
    $label = trim((string) ($place['label'] ?? ''));
    if ($label !== '') {
        return $label;
    }
    $name = trim((string) ($place['name'] ?? ''));
    if ($name !== '') {
        return t('admin.gallery_report.export.named_area', '{name} area', ['name' => $name]);
    }
    return t('admin.gallery_report.export.known_place_area', 'Known place area');
}

/**
 * Return offline place reference points used for approximate labels.
 *
 * @return array<int, array<string, mixed>> Place rows.
 */
function admin_gallery_report_known_places(): array
{
    return [
        ['name' => 'Friedrichshafen', 'label' => t('admin.gallery_report.export.place_friedrichshafen_bodensee', 'Friedrichshafen / Bodensee area'), 'lat' => 47.6505, 'lng' => 9.4790, 'radius_km' => 45.0, 'kind' => 'regional area', 'priority' => 4.0],
        ['name' => 'Konstanz', 'label' => t('admin.gallery_report.export.place_konstanz_bodensee', 'Konstanz / Bodensee area'), 'lat' => 47.6779, 'lng' => 9.1732, 'radius_km' => 38.0, 'kind' => 'regional area', 'priority' => 3.0],
        ['name' => 'Lindau', 'label' => t('admin.gallery_report.export.place_lindau_bodensee', 'Lindau / Bodensee area'), 'lat' => 47.5460, 'lng' => 9.6830, 'radius_km' => 24.0, 'kind' => 'regional area', 'priority' => 2.0],
        ['name' => 'Ravensburg', 'label' => t('admin.gallery_report.export.place_ravensburg_upper_swabia', 'Ravensburg / Upper Swabia area'), 'lat' => 47.7819, 'lng' => 9.6106, 'radius_km' => 28.0, 'kind' => 'regional area'],
        ['name' => 'Zurich', 'lat' => 47.3769, 'lng' => 8.5417, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'St. Gallen', 'lat' => 47.4245, 'lng' => 9.3767, 'radius_km' => 25.0, 'kind' => 'city'],

        ['name' => 'Prague', 'lat' => 50.0755, 'lng' => 14.4378, 'radius_km' => 40.0, 'kind' => 'city'],
        ['name' => 'Pilsen', 'lat' => 49.7384, 'lng' => 13.3736, 'radius_km' => 42.0, 'kind' => 'city'],
        ['name' => 'Plasy', 'label' => t('admin.gallery_report.export.place_plasy_lkps', 'Plasy / LKPS area'), 'lat' => 49.9346, 'lng' => 13.3906, 'radius_km' => 16.0, 'kind' => 'local area', 'priority' => 5.0],
        ['name' => 'Klatovy', 'lat' => 49.3956, 'lng' => 13.2951, 'radius_km' => 24.0, 'kind' => 'town'],
        ['name' => 'Domažlice', 'lat' => 49.4405, 'lng' => 12.9298, 'radius_km' => 24.0, 'kind' => 'town'],
        ['name' => 'Rokycany', 'lat' => 49.7427, 'lng' => 13.5946, 'radius_km' => 20.0, 'kind' => 'town'],
        ['name' => 'Karlovy Vary', 'lat' => 50.2319, 'lng' => 12.8710, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Mariánské Lázně', 'lat' => 49.9646, 'lng' => 12.7012, 'radius_km' => 22.0, 'kind' => 'town'],
        ['name' => 'České Budějovice', 'lat' => 48.9745, 'lng' => 14.4743, 'radius_km' => 30.0, 'kind' => 'city'],
        ['name' => 'Český Krumlov', 'lat' => 48.8127, 'lng' => 14.3175, 'radius_km' => 18.0, 'kind' => 'town'],
        ['name' => 'Brno', 'lat' => 49.1951, 'lng' => 16.6068, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Ostrava', 'lat' => 49.8209, 'lng' => 18.2625, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Olomouc', 'lat' => 49.5938, 'lng' => 17.2509, 'radius_km' => 30.0, 'kind' => 'city'],
        ['name' => 'Liberec', 'lat' => 50.7663, 'lng' => 15.0543, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Hradec Králové', 'lat' => 50.2092, 'lng' => 15.8328, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Pardubice', 'lat' => 50.0343, 'lng' => 15.7812, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Ústí nad Labem', 'lat' => 50.6611, 'lng' => 14.0326, 'radius_km' => 26.0, 'kind' => 'city'],
        ['name' => 'Jihlava', 'lat' => 49.3961, 'lng' => 15.5903, 'radius_km' => 26.0, 'kind' => 'city'],
        ['name' => 'Zlín', 'lat' => 49.2244, 'lng' => 17.6628, 'radius_km' => 26.0, 'kind' => 'city'],
        ['name' => 'Teplice', 'lat' => 50.6404, 'lng' => 13.8245, 'radius_km' => 22.0, 'kind' => 'city'],

        ['name' => 'Berlin', 'lat' => 52.5200, 'lng' => 13.4050, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Munich', 'lat' => 48.1351, 'lng' => 11.5820, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Stuttgart', 'lat' => 48.7758, 'lng' => 9.1829, 'radius_km' => 38.0, 'kind' => 'city'],
        ['name' => 'Ulm', 'lat' => 48.4011, 'lng' => 9.9876, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Augsburg', 'lat' => 48.3705, 'lng' => 10.8978, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Memmingen', 'lat' => 47.9838, 'lng' => 10.1819, 'radius_km' => 24.0, 'kind' => 'city'],
        ['name' => 'Nuremberg', 'lat' => 49.4521, 'lng' => 11.0767, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Frankfurt', 'lat' => 50.1109, 'lng' => 8.6821, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Dresden', 'lat' => 51.0504, 'lng' => 13.7373, 'radius_km' => 32.0, 'kind' => 'city'],
        ['name' => 'Leipzig', 'lat' => 51.3397, 'lng' => 12.3731, 'radius_km' => 32.0, 'kind' => 'city'],
        ['name' => 'Hamburg', 'lat' => 53.5511, 'lng' => 9.9937, 'radius_km' => 42.0, 'kind' => 'city'],
        ['name' => 'Cologne', 'lat' => 50.9375, 'lng' => 6.9603, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Düsseldorf', 'lat' => 51.2277, 'lng' => 6.7735, 'radius_km' => 32.0, 'kind' => 'city'],

        ['name' => 'Vienna', 'lat' => 48.2082, 'lng' => 16.3738, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Salzburg', 'lat' => 47.8095, 'lng' => 13.0550, 'radius_km' => 30.0, 'kind' => 'city'],
        ['name' => 'Innsbruck', 'lat' => 47.2692, 'lng' => 11.4041, 'radius_km' => 28.0, 'kind' => 'city'],
        ['name' => 'Linz', 'lat' => 48.3069, 'lng' => 14.2858, 'radius_km' => 30.0, 'kind' => 'city'],
        ['name' => 'Graz', 'lat' => 47.0707, 'lng' => 15.4395, 'radius_km' => 30.0, 'kind' => 'city'],
        ['name' => 'Bratislava', 'lat' => 48.1486, 'lng' => 17.1077, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Kraków', 'lat' => 50.0647, 'lng' => 19.9450, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Warsaw', 'lat' => 52.2297, 'lng' => 21.0122, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Budapest', 'lat' => 47.4979, 'lng' => 19.0402, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Ljubljana', 'lat' => 46.0569, 'lng' => 14.5058, 'radius_km' => 32.0, 'kind' => 'city'],
        ['name' => 'Zagreb', 'lat' => 45.8150, 'lng' => 15.9819, 'radius_km' => 35.0, 'kind' => 'city'],

        ['name' => 'Stockholm', 'lat' => 59.3293, 'lng' => 18.0686, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Gothenburg', 'lat' => 57.7089, 'lng' => 11.9746, 'radius_km' => 38.0, 'kind' => 'city'],
        ['name' => 'Malmö', 'lat' => 55.6050, 'lng' => 13.0038, 'radius_km' => 32.0, 'kind' => 'city'],
        ['name' => 'Copenhagen', 'lat' => 55.6761, 'lng' => 12.5683, 'radius_km' => 40.0, 'kind' => 'city'],
        ['name' => 'Oslo', 'lat' => 59.9139, 'lng' => 10.7522, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Helsinki', 'lat' => 60.1699, 'lng' => 24.9384, 'radius_km' => 42.0, 'kind' => 'city'],

        ['name' => 'London', 'lat' => 51.5072, 'lng' => -0.1276, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Bristol', 'lat' => 51.4545, 'lng' => -2.5879, 'radius_km' => 32.0, 'kind' => 'city'],
        ['name' => 'Manchester', 'lat' => 53.4808, 'lng' => -2.2426, 'radius_km' => 40.0, 'kind' => 'city'],
        ['name' => 'Edinburgh', 'lat' => 55.9533, 'lng' => -3.1883, 'radius_km' => 35.0, 'kind' => 'city'],
        ['name' => 'Paris', 'lat' => 48.8566, 'lng' => 2.3522, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Marseille', 'lat' => 43.2965, 'lng' => 5.3698, 'radius_km' => 36.0, 'kind' => 'city'],
        ['name' => 'Lyon', 'lat' => 45.7640, 'lng' => 4.8357, 'radius_km' => 36.0, 'kind' => 'city'],
        ['name' => 'Nice', 'lat' => 43.7102, 'lng' => 7.2620, 'radius_km' => 32.0, 'kind' => 'city'],
        ['name' => 'Amsterdam', 'lat' => 52.3676, 'lng' => 4.9041, 'radius_km' => 42.0, 'kind' => 'city'],
        ['name' => 'Brussels', 'lat' => 50.8503, 'lng' => 4.3517, 'radius_km' => 38.0, 'kind' => 'city'],
        ['name' => 'Milan', 'lat' => 45.4642, 'lng' => 9.1900, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Rome', 'lat' => 41.9028, 'lng' => 12.4964, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Barcelona', 'lat' => 41.3874, 'lng' => 2.1686, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Madrid', 'lat' => 40.4168, 'lng' => -3.7038, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Lisbon', 'lat' => 38.7223, 'lng' => -9.1393, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Athens', 'lat' => 37.9838, 'lng' => 23.7275, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Istanbul', 'lat' => 41.0082, 'lng' => 28.9784, 'radius_km' => 55.0, 'kind' => 'city'],

        ['name' => 'Chicago', 'lat' => 41.8781, 'lng' => -87.6298, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'New York', 'lat' => 40.7128, 'lng' => -74.0060, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Los Angeles', 'lat' => 34.0522, 'lng' => -118.2437, 'radius_km' => 70.0, 'kind' => 'city'],
        ['name' => 'San Francisco', 'lat' => 37.7749, 'lng' => -122.4194, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Miami', 'lat' => 25.7617, 'lng' => -80.1918, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Toronto', 'lat' => 43.6532, 'lng' => -79.3832, 'radius_km' => 50.0, 'kind' => 'city'],
        ['name' => 'Dubai', 'lat' => 25.2048, 'lng' => 55.2708, 'radius_km' => 55.0, 'kind' => 'city'],
        ['name' => 'Singapore', 'lat' => 1.3521, 'lng' => 103.8198, 'radius_km' => 45.0, 'kind' => 'city'],
        ['name' => 'Tokyo', 'lat' => 35.6762, 'lng' => 139.6503, 'radius_km' => 65.0, 'kind' => 'city'],
        ['name' => 'Sydney', 'lat' => -33.8688, 'lng' => 151.2093, 'radius_km' => 60.0, 'kind' => 'city'],
        ['name' => 'Melbourne', 'lat' => -37.8136, 'lng' => 144.9631, 'radius_km' => 60.0, 'kind' => 'city'],
    ];
}

/**
 * Compute distance between two coordinates.
 *
 * @param float $lat1 First latitude.
 * @param float $lng1 First longitude.
 * @param float $lat2 Second latitude.
 * @param float $lng2 Second longitude.
 * @return float Distance in kilometers.
 */
function admin_gallery_report_haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earthKm * 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));
}
