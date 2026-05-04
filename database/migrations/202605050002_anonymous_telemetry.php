<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605050002_anonymous_telemetry.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Applies an incremental database schema or data update for PHP Gallery.
 *
 * Responsibilities:
 *   - Describe and execute one database change
 *   - Remain safe to run through the migration system
 *   - Avoid changing unrelated schema objects
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
    "CREATE TABLE IF NOT EXISTS telemetry_settings (
        setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS telemetry_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        session_hash BINARY(16) NOT NULL,
        started_at DATETIME NOT NULL,
        last_seen_at DATETIME NOT NULL,
        first_route_name VARCHAR(80) NULL,
        last_route_name VARCHAR(80) NULL,
        first_gallery_id BIGINT UNSIGNED NULL,
        last_gallery_id BIGINT UNSIGNED NULL,
        first_image_id BIGINT UNSIGNED NULL,
        last_image_id BIGINT UNSIGNED NULL,
        entry_referrer_category ENUM('direct','internal','search','social','external','unknown') NOT NULL DEFAULT 'unknown',
        browser_family ENUM('chrome','edge','firefox','safari','opera','other','unknown') NOT NULL DEFAULT 'unknown',
        browser_major_bucket SMALLINT UNSIGNED NULL,
        os_family ENUM('windows','macos','ios','android','linux','chromeos','other','unknown') NOT NULL DEFAULT 'unknown',
        device_type ENUM('desktop','tablet','phone','bot','unknown') NOT NULL DEFAULT 'unknown',
        viewport_class ENUM('xs','sm','md','lg','xl','xxl','unknown') NOT NULL DEFAULT 'unknown',
        locale_bucket VARCHAR(16) NULL,
        country_code CHAR(2) NULL,
        page_view_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        photo_view_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        duration_seconds_capped INT UNSIGNED NOT NULL DEFAULT 0,
        bounced TINYINT(1) NOT NULL DEFAULT 0,
        exit_route_name VARCHAR(80) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY telemetry_sessions_hash_unique (session_hash),
        KEY telemetry_sessions_started_index (started_at),
        KEY telemetry_sessions_last_seen_index (last_seen_at),
        KEY telemetry_sessions_gallery_index (first_gallery_id, started_at),
        KEY telemetry_sessions_country_index (country_code, started_at),
        CONSTRAINT telemetry_sessions_first_gallery_foreign FOREIGN KEY (first_gallery_id) REFERENCES galleries(id) ON DELETE SET NULL,
        CONSTRAINT telemetry_sessions_last_gallery_foreign FOREIGN KEY (last_gallery_id) REFERENCES galleries(id) ON DELETE SET NULL,
        CONSTRAINT telemetry_sessions_first_image_foreign FOREIGN KEY (first_image_id) REFERENCES images(id) ON DELETE SET NULL,
        CONSTRAINT telemetry_sessions_last_image_foreign FOREIGN KEY (last_image_id) REFERENCES images(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS telemetry_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        occurred_at DATETIME NOT NULL,
        received_at DATETIME NOT NULL,
        event_name VARCHAR(120) NOT NULL,
        source ENUM('client','server','job') NOT NULL,
        session_hash BINARY(16) NULL,
        request_id CHAR(26) NULL,
        route_name VARCHAR(80) NULL,
        page_kind ENUM('home','gallery','subgallery','photo','media','admin','download','api','other','unknown') NOT NULL DEFAULT 'unknown',
        gallery_id BIGINT UNSIGNED NULL,
        image_id BIGINT UNSIGNED NULL,
        job_name VARCHAR(80) NULL,
        referrer_category ENUM('direct','internal','search','social','external','unknown') NOT NULL DEFAULT 'unknown',
        browser_family ENUM('chrome','edge','firefox','safari','opera','other','unknown') NOT NULL DEFAULT 'unknown',
        browser_major_bucket SMALLINT UNSIGNED NULL,
        os_family ENUM('windows','macos','ios','android','linux','chromeos','other','unknown') NOT NULL DEFAULT 'unknown',
        device_type ENUM('desktop','tablet','phone','bot','unknown') NOT NULL DEFAULT 'unknown',
        viewport_class ENUM('xs','sm','md','lg','xl','xxl','unknown') NOT NULL DEFAULT 'unknown',
        locale_bucket VARCHAR(16) NULL,
        country_code CHAR(2) NULL,
        duration_ms_capped INT UNSIGNED NULL,
        value_count INT UNSIGNED NULL,
        value_bytes BIGINT UNSIGNED NULL,
        value_ms INT UNSIGNED NULL,
        value_bucket VARCHAR(40) NULL,
        cache_result ENUM('hit','miss','bypass','stale','evicted','discarded','unknown') NOT NULL DEFAULT 'unknown',
        media_variant ENUM('original','thumb_300','thumb_600','thumb_800','thumb_960','thumb_1200','thumb_1600','webp','jpg','unknown') NOT NULL DEFAULT 'unknown',
        http_status SMALLINT UNSIGNED NULL,
        error_kind VARCHAR(80) NULL,
        sampled_rate DECIMAL(5,4) NOT NULL DEFAULT 1.0000,
        context_json JSON NULL,
        KEY telemetry_events_time_index (occurred_at),
        KEY telemetry_events_name_time_index (event_name, occurred_at),
        KEY telemetry_events_route_time_index (route_name, occurred_at),
        KEY telemetry_events_gallery_time_index (gallery_id, occurred_at),
        KEY telemetry_events_image_time_index (image_id, occurred_at),
        KEY telemetry_events_session_time_index (session_hash, occurred_at),
        KEY telemetry_events_request_index (request_id),
        KEY telemetry_events_cache_time_index (cache_result, occurred_at),
        KEY telemetry_events_error_time_index (error_kind, occurred_at),
        CONSTRAINT telemetry_events_gallery_foreign FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE SET NULL,
        CONSTRAINT telemetry_events_image_foreign FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS telemetry_hourly_metrics (
        bucket_start DATETIME NOT NULL,
        metric_name VARCHAR(120) NOT NULL,
        route_name VARCHAR(80) NOT NULL DEFAULT '',
        page_kind ENUM('home','gallery','subgallery','photo','media','admin','download','api','other','unknown') NOT NULL DEFAULT 'unknown',
        gallery_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        image_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        browser_family ENUM('chrome','edge','firefox','safari','opera','other','unknown') NOT NULL DEFAULT 'unknown',
        os_family ENUM('windows','macos','ios','android','linux','chromeos','other','unknown') NOT NULL DEFAULT 'unknown',
        device_type ENUM('desktop','tablet','phone','bot','unknown') NOT NULL DEFAULT 'unknown',
        viewport_class ENUM('xs','sm','md','lg','xl','xxl','unknown') NOT NULL DEFAULT 'unknown',
        country_code CHAR(2) NOT NULL DEFAULT '',
        referrer_category ENUM('direct','internal','search','social','external','unknown') NOT NULL DEFAULT 'unknown',
        media_variant ENUM('original','thumb_300','thumb_600','thumb_800','thumb_960','thumb_1200','thumb_1600','webp','jpg','unknown') NOT NULL DEFAULT 'unknown',
        cache_result ENUM('hit','miss','bypass','stale','evicted','discarded','unknown') NOT NULL DEFAULT 'unknown',
        sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        value_sum DECIMAL(20,4) NOT NULL DEFAULT 0,
        value_min DECIMAL(20,4) NULL,
        value_max DECIMAL(20,4) NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (bucket_start, metric_name, route_name, page_kind, gallery_id, image_id, browser_family, os_family, device_type, viewport_class, country_code, referrer_category, media_variant, cache_result),
        KEY telemetry_hourly_metric_time_index (metric_name, bucket_start),
        KEY telemetry_hourly_gallery_time_index (gallery_id, bucket_start),
        KEY telemetry_hourly_image_time_index (image_id, bucket_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS telemetry_daily_metrics (
        bucket_date DATE NOT NULL,
        metric_name VARCHAR(120) NOT NULL,
        route_name VARCHAR(80) NOT NULL DEFAULT '',
        page_kind ENUM('home','gallery','subgallery','photo','media','admin','download','api','other','unknown') NOT NULL DEFAULT 'unknown',
        gallery_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        image_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        browser_family ENUM('chrome','edge','firefox','safari','opera','other','unknown') NOT NULL DEFAULT 'unknown',
        os_family ENUM('windows','macos','ios','android','linux','chromeos','other','unknown') NOT NULL DEFAULT 'unknown',
        device_type ENUM('desktop','tablet','phone','bot','unknown') NOT NULL DEFAULT 'unknown',
        viewport_class ENUM('xs','sm','md','lg','xl','xxl','unknown') NOT NULL DEFAULT 'unknown',
        country_code CHAR(2) NOT NULL DEFAULT '',
        referrer_category ENUM('direct','internal','search','social','external','unknown') NOT NULL DEFAULT 'unknown',
        media_variant ENUM('original','thumb_300','thumb_600','thumb_800','thumb_960','thumb_1200','thumb_1600','webp','jpg','unknown') NOT NULL DEFAULT 'unknown',
        cache_result ENUM('hit','miss','bypass','stale','evicted','discarded','unknown') NOT NULL DEFAULT 'unknown',
        sample_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        event_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        value_sum DECIMAL(20,4) NOT NULL DEFAULT 0,
        value_min DECIMAL(20,4) NULL,
        value_max DECIMAL(20,4) NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (bucket_date, metric_name, route_name, page_kind, gallery_id, image_id, browser_family, os_family, device_type, viewport_class, country_code, referrer_category, media_variant, cache_result),
        KEY telemetry_daily_metric_date_index (metric_name, bucket_date),
        KEY telemetry_daily_gallery_date_index (gallery_id, bucket_date),
        KEY telemetry_daily_image_date_index (image_id, bucket_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS telemetry_db_query_metrics (
        bucket_start DATETIME NOT NULL,
        route_name VARCHAR(80) NOT NULL DEFAULT '',
        operation ENUM('select','insert','update','delete','replace','ddl','transaction','connect','other','unknown') NOT NULL DEFAULT 'unknown',
        table_name VARCHAR(80) NOT NULL DEFAULT '',
        query_fingerprint CHAR(16) NOT NULL DEFAULT '',
        query_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        failed_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        slow_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        latency_ms_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
        latency_ms_max INT UNSIGNED NOT NULL DEFAULT 0,
        rows_returned_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
        rows_affected_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (bucket_start, route_name, operation, table_name, query_fingerprint),
        KEY telemetry_db_query_metric_time_index (bucket_start),
        KEY telemetry_db_query_slow_index (slow_count, bucket_start),
        KEY telemetry_db_query_failed_index (failed_count, bucket_start)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS telemetry_job_runs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        job_name VARCHAR(80) NOT NULL,
        status ENUM('started','completed','failed','cancelled') NOT NULL,
        started_at DATETIME NOT NULL,
        finished_at DATETIME NULL,
        duration_ms INT UNSIGNED NULL,
        gallery_id BIGINT UNSIGNED NULL,
        image_id BIGINT UNSIGNED NULL,
        item_count INT UNSIGNED NULL,
        retry_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        error_kind VARCHAR(80) NULL,
        context_json JSON NULL,
        KEY telemetry_job_name_started_index (job_name, started_at),
        KEY telemetry_job_status_started_index (status, started_at),
        KEY telemetry_job_gallery_started_index (gallery_id, started_at),
        KEY telemetry_job_image_started_index (image_id, started_at),
        CONSTRAINT telemetry_job_gallery_foreign FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE SET NULL,
        CONSTRAINT telemetry_job_image_foreign FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "INSERT INTO telemetry_settings (setting_key, setting_value, updated_at) VALUES
        ('telemetry_enabled', '0', NOW()),
        ('telemetry_public_usage_enabled', '0', NOW()),
        ('telemetry_performance_enabled', '0', NOW()),
        ('telemetry_database_enabled', '1', NOW()),
        ('telemetry_cache_enabled', '1', NOW()),
        ('telemetry_geo_enabled', '0', NOW()),
        ('telemetry_raw_retention_days', '7', NOW()),
        ('telemetry_session_retention_days', '30', NOW()),
        ('telemetry_hourly_retention_days', '90', NOW()),
        ('telemetry_daily_retention_days', '730', NOW()),
        ('telemetry_max_photo_view_seconds', '900', NOW()),
        ('telemetry_client_sample_rate', '1.0', NOW()),
        ('telemetry_performance_sample_rate', '0.25', NOW()),
        ('telemetry_error_sample_rate', '1.0', NOW()),
        ('telemetry_slow_request_threshold_ms', '1000', NOW()),
        ('telemetry_slow_query_threshold_ms', '250', NOW()),
        ('telemetry_respect_dnt', '1', NOW()),
        ('telemetry_admin_excluded', '1', NOW())
    ON DUPLICATE KEY UPDATE setting_value = setting_value"
];
