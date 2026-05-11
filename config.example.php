<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: config.example.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides core bootstrap, configuration, helper, security, database, or routing functionality.
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

return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'gallery_cms',
        'user' => 'gallery_user',
        'password' => 'change-me',
        'charset' => 'utf8mb4',
    ],
    'base_url' => '',
    'galleries_root' => __DIR__ . '/galleries',
    'zip_cache_path' => __DIR__ . '/cache/zips',
    'admin_session_name' => 'gallery_admin_session',
    'visitor_vote_secret' => 'replace-with-a-long-random-secret',
    'setup_key' => 'replace-with-a-temporary-setup-key',

    // Language configuration is intentionally small for the first localization
    // foundation. Add app/lang/<code>.json files and list their codes here.
    // Legacy app/lang/<code>.php files are still accepted as fallback dictionaries.
    'language' => [
        'default' => 'en',
        'available' => ['en', 'cs'],
        'show_missing_keys_to_admins' => false,
        'append_missing_keys_to_ui' => false,
    ],

    // Password reset email is disabled by default because many shared hosts
    // require explicit mail setup. Enable it only after the From address works.
    // mail_transport currently supports php_mail. SMTP can be added later without
    // changing the controller flow. log_reset_links is for temporary local testing
    // only because it writes live reset URLs into admin logs.
    'password_reset' => [
        'enabled' => false,
        'from_email' => 'no-reply@example.com',
        'from_name' => 'PHP Gallery',
        'token_lifetime_minutes' => 60,
        'mail_transport' => 'php_mail',
        'log_reset_links' => false,
    ],
];

