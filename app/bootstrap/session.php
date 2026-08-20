<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/bootstrap/session.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Owns admin session naming, lifetime policy, cookie parameters, and session startup.
 *
 * Responsibilities:
 *   - Support shared project infrastructure
 *   - Keep behavior compatible with existing controllers and services
 *   - Avoid unnecessary coupling to presentation code
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
 *   2026-05-04
 */

declare(strict_types=1);

namespace Gallery\Core;

use function Gallery\Services\auth_admin_session_lifetime_seconds;

/**
 * Start the configured admin session without changing the existing lifetime or cookie policy.
 *
 * @param array $config Application configuration.
 */
function cms_start_session(array $config): void
{
    session_name((string) $config['admin_session_name']);
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // $adminSessionLifetime stores the browser cookie and PHP session lifetime for admin sessions.
        $adminSessionLifetime = function_exists('Gallery\\Services\\auth_admin_session_lifetime_seconds') ? auth_admin_session_lifetime_seconds() : 1209600;
        ini_set('session.gc_maxlifetime', (string) $adminSessionLifetime);
        ini_set('session.cookie_lifetime', (string) $adminSessionLifetime);
        session_cache_limiter('');
        session_set_cookie_params([
            'lifetime' => $adminSessionLifetime,
            'path' => '/',
            'secure' => request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (function_exists('Gallery\\Services\\gallery_benchmark_trace_mark')) {
            \Gallery\Services\gallery_benchmark_trace_mark('session_start_begin', [
                'cookie_present' => isset($_COOKIE[session_name()]),
                'save_handler' => (string) ini_get('session.save_handler'),
            ]);
        }
        session_start();
        if (function_exists('Gallery\\Services\\gallery_benchmark_trace_mark')) {
            \Gallery\Services\gallery_benchmark_trace_mark('session_start_end', [
                'session_active' => session_status() === PHP_SESSION_ACTIVE,
                'session_id_present' => session_id() !== '',
            ]);
        }
    } elseif (function_exists('Gallery\\Services\\gallery_benchmark_trace_mark')) {
        \Gallery\Services\gallery_benchmark_trace_mark('session_already_active');
    }

}