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
 *   2026-08-18
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
    // Operational limits are centralized in app/configuration_defaults.php.
    // A deployment may override individual values by adding a runtime_limits
    // array to config.php. Existing legacy smart_gallery_zip_max_source_bytes
    // overrides remain supported for backward compatibility.
    'admin_session_name' => 'gallery_admin_session',
    'visitor_vote_secret' => 'replace-with-a-long-random-secret',
    'setup_key' => 'replace-with-a-temporary-setup-key',

    // Admin authentication is intentionally durable because shared hosting can
    // clean PHP session files aggressively. The DB-backed persistent login token
    // restores the admin session when the browser still has a valid cookie.
    'auth' => [
        'session_lifetime_days' => 14,
        'remember_lifetime_days' => 30,
        'persistent_login_enabled' => true,
        'persistent_login_default_checked' => true,
    ],

    // Viewer accounts remain disabled by default. When enabled, registration_mode controls
    // whether registration is disabled, Admin-invitation-only, or open with verified email.
    // The global viewer_accounts feature remains the master switch for all viewer HTTP surfaces.
    'viewer_accounts' => [
        'enabled' => false,
        'registration_mode' => 'disabled', // disabled, invite_only, or open
        'require_https' => true,
        'session_lifetime_seconds' => 86400,
        'remember_lifetime_days' => 30,
        'security_event_retention_days' => 180,
        'rate_limit_max_subjects_per_bucket' => 5000,
        // First-party adaptive anti-automation gate for anonymous registration/resend.
        'anti_automation_enabled' => true,
        'anti_automation_min_form_age_seconds' => 2,
        'anti_automation_form_lifetime_seconds' => 600,
        'anti_automation_pow_min_bits' => 12,
        'anti_automation_pow_max_bits' => 15,
        'max_viewer_accounts' => 250,
        'max_active_viewer_sessions_per_account' => 10,
        'max_active_viewer_remember_tokens_per_account' => 10,
        'max_pending_registration_requests' => 250,
        'registration_request_lifetime_minutes' => 1440,
        'verified_registration_lifetime_minutes' => 60,
        'registration_activation_lifetime_minutes' => 20,
        'verification_token_lifetime_minutes' => 60,
        'password_reset_authorization_lifetime_minutes' => 15,
        'password_reset_token_lifetime_minutes' => 60,
        'viewer_reauthentication_lifetime_minutes' => 15,
        'email_change_request_lifetime_minutes' => 60,
        'email_change_confirmation_lifetime_minutes' => 15,
        'invitation_lifetime_days' => 7,
        'registration_global_daily_limit' => 50,
        'verification_mail_email_cooldown_seconds' => 600,
        'verification_mail_email_hourly_limit' => 3,
        'verification_mail_email_daily_limit' => 5,
        'verification_mail_ip_hourly_limit' => 10,
        'verification_mail_ip_daily_limit' => 25,
        'verification_mail_subnet_hourly_limit' => 25,
        'verification_mail_subnet_daily_limit' => 60,
        'verification_mail_global_daily_limit' => 50,
        'password_reset_mail_email_cooldown_seconds' => 600,
        'password_reset_mail_email_hourly_limit' => 3,
        'password_reset_mail_email_daily_limit' => 5,
        'password_reset_mail_ip_hourly_limit' => 5,
        'password_reset_mail_ip_daily_limit' => 20,
        'password_reset_mail_subnet_hourly_limit' => 15,
        'password_reset_mail_subnet_daily_limit' => 40,
        'password_reset_mail_global_daily_limit' => 50,
        'invitation_mail_email_daily_limit' => 3,
        'invitation_mail_global_daily_limit' => 50,
        // Viewer content resource boundaries. Phase 1.1 uses the favourites limit; collection limits remain reserved for later phases.
        'max_viewer_favourites_per_account' => 5000,
        'max_viewer_collections_per_account' => 25,
        'max_viewer_items_per_collection' => 500,
        'max_active_viewer_collection_shares_per_collection' => 1,
    ],

    // Forwarded client-IP headers are ignored unless both the direct peer and
    // header family are explicitly trusted here. Exact IPs and CIDRs are valid.
    // This leaves normal non-proxy installations on REMOTE_ADDR behavior.
    'security' => [
        'trusted_proxies' => [],
        'trusted_proxy_headers' => [], // x-forwarded-for, x-real-ip, cf-connecting-ip
        'trusted_proxy_protocol_headers' => [], // x-forwarded-proto, x-forwarded-ssl
    ],

    // Google login uses OpenID Connect. Create a Google OAuth 2.0 Web
    // application client and add this callback URL in Google Cloud Console:
    // https://your-domain.example/index.php?page=admin_google_callback
    // The account must first be linked from Admin -> Account before Google
    // login is accepted on the public login screen.
    'google_login' => [
        'enabled' => false,
        'client_id' => '',
        'client_secret' => '',
        'redirect_uri' => '',
        'prompt' => 'select_account',
    ],

    // Navigation data is offline-first. SimBrief-generated route maps use the
    // OFP coordinates saved with each gallery. The bundled CSV stays available
    // as a small fallback lookup table for manually entered route text.
    'navigation_data' => [
        'bundled_navdata_path' => __DIR__ . '/data/navdata/local_nav_points.csv',
        'cache_ttl_seconds' => 2592000,
    ],

    // English is the canonical source and fallback language. English, Czech,
    // German, and Swedish are the currently maintained selectable languages.
    // Other app/lang files may exist as dormant future packs, but their presence
    // does not make them selectable. PHP files are used only when JSON is absent.
    'language' => [
        'default' => 'en',
        'available' => ['en', 'cs', 'de', 'sv'],
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

