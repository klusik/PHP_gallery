<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610190001_telemetry_compact_indexes.php
 * Module Type: Database Migration
 * Purpose: Reduce replicated telemetry keys and separate SQL diagnostic retention.
 * Responsibilities:
 *   - Preserve aggregate uniqueness and every existing measurement.
 *   - Rebuild secondary indexes around compact physical row identifiers.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

if (!defined('GALLERY_TELEMETRY_COMPACT_MIGRATION_RETENTION_DAYS')) {
    /**
     * Seed independent SQL diagnostics without changing usage retention.
     * Type: integer.
     * Units: days.
     * Scope: immutable migration seed for previously absent settings.
     * Consumers: telemetry settings INSERT below.
     * Rationale: seven days matches the runtime default for recent diagnostics.
     */
    define('GALLERY_TELEMETRY_COMPACT_MIGRATION_RETENTION_DAYS', 7);
}

$dimensions =
    'metric_name, route_name, page_kind, gallery_id, image_id,'
    . ' browser_family, os_family, device_type, viewport_class, country_code,'
    . ' referrer_category, media_variant, cache_result';

// Each ALTER is atomic for its table. Migration replay recognizes the added
// column and skips an already completed ALTER after a later-table failure.
return [
    'ALTER TABLE telemetry_hourly_metrics DROP PRIMARY KEY,'
        . ' ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        . " ADD UNIQUE KEY telemetry_hourly_identity (bucket_start, {$dimensions})",
    'ALTER TABLE telemetry_daily_metrics DROP PRIMARY KEY,'
        . ' ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        . " ADD UNIQUE KEY telemetry_daily_identity (bucket_date, {$dimensions})",
    'ALTER TABLE telemetry_db_query_metrics DROP PRIMARY KEY,'
        . ' ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,'
        . ' ADD UNIQUE KEY telemetry_db_query_identity'
        . ' (bucket_start, route_name, operation, table_name, query_fingerprint)',
    'INSERT INTO telemetry_settings (setting_key, setting_value, updated_at)'
        . " VALUES ('telemetry_database_retention_days', '"
        . GALLERY_TELEMETRY_COMPACT_MIGRATION_RETENTION_DAYS
        . "', NOW())"
        . ' ON DUPLICATE KEY UPDATE setting_key = VALUES(setting_key)',
];
