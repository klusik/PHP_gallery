<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/audit_route_probe_registry.php
 * Module Type: Audit Configuration
 * Purpose: Define stable representative requests for route-lifecycle performance probes.
 * Responsibilities: Keep route identity, expected result, authentication and ceilings explicit.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

return [
    'robots' => [
        'response_kind' => 'robots',
        'route' => 'robots',
        'request_uri' => '/robots.txt',
        'expected_outcome' => 'success',
        'auth_mode' => 'anonymous',
        'max_included_php_files' => 160,
        'max_peak_memory_bytes' => 33554432,
    ],
    'home' => [
        'response_kind' => 'html',
        'response_markers' => ['data-public-gallery-index-grid', 'Workflow seed'],
        'route' => 'home',
        'request_uri' => '/',
        'expected_outcome' => 'success',
        'auth_mode' => 'anonymous',
        'max_included_php_files' => 230,
        'max_peak_memory_bytes' => 41943040,
    ],
    'gallery' => [
        'response_kind' => 'html',
        'response_markers' => ['data-public-gallery-id="', 'data-public-photo-order-item'],
        'route' => 'gallery',
        'request_uri' => '/gallery/{gallery_path}',
        'expected_outcome' => 'success',
        'auth_mode' => 'anonymous',
        'max_included_php_files' => 300,
        'max_peak_memory_bytes' => 50331648,
    ],
    'thumb' => [
        'response_kind' => 'image',
        'route' => 'public_thumb',
        'request_uri' => '/gallery/{gallery_path}/{image_slug}/thumb-300.webp',
        'expected_outcome' => 'success',
        'auth_mode' => 'anonymous',
        'max_included_php_files' => 200,
        'max_peak_memory_bytes' => 41943040,
    ],
    'media' => [
        'response_kind' => 'image',
        'route' => 'public_media',
        'request_uri' => '/gallery/{gallery_path}/{image_slug}/media',
        'expected_outcome' => 'success',
        'auth_mode' => 'anonymous',
        'max_included_php_files' => 200,
        'max_peak_memory_bytes' => 41943040,
    ],
    'admin' => [
        'response_kind' => 'html',
        'response_markers' => ['data-admin-dashboard-workspace'],
        'route' => 'admin',
        'request_uri' => '/?page=admin',
        'expected_outcome' => 'success',
        'auth_mode' => 'admin',
        'max_included_php_files' => 270,
        'max_peak_memory_bytes' => 50331648,
    ],
    'admin_telemetry' => [
        'response_kind' => 'html',
        'response_markers' => ['class="admin-telemetry-page"', 'telemetry-privacy-note'],
        'route' => 'admin_telemetry',
        'request_uri' => '/?page=admin_telemetry',
        'expected_outcome' => 'success',
        'auth_mode' => 'admin',
        'max_included_php_files' => 200,
        'max_peak_memory_bytes' => 41943040,
    ],
    'admin_denied' => [
        'response_kind' => 'denial',
        'route' => 'admin',
        'request_uri' => '/?page=admin',
        'expected_outcome' => 'denied',
        'auth_mode' => 'anonymous',
        'max_included_php_files' => 270,
        'max_peak_memory_bytes' => 50331648,
    ],
    'admin_telemetry_denied' => [
        'response_kind' => 'denial',
        'route' => 'admin_telemetry',
        'request_uri' => '/?page=admin_telemetry',
        'expected_outcome' => 'denied',
        'auth_mode' => 'anonymous',
        'max_included_php_files' => 200,
        'max_peak_memory_bytes' => 41943040,
    ],
];
