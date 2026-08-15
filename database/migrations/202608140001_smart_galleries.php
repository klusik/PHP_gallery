<?php

/**
 * Add persisted Smart Gallery definitions and private editorial image ratings.
 *
 * Smart Galleries store only a versioned rule document. Their image membership
 * remains dynamic and no image or filesystem row is copied into this table.
 */

declare(strict_types=1);

return [
    "CREATE TABLE smart_galleries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        slug VARCHAR(255) NOT NULL,
        description TEXT NULL,
        rules_json MEDIUMTEXT NOT NULL,
        rule_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        visibility ENUM('private', 'public') NOT NULL DEFAULT 'private',
        sort_mode VARCHAR(32) NOT NULL DEFAULT 'capture_date',
        sort_direction ENUM('asc', 'desc') NOT NULL DEFAULT 'desc',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY smart_galleries_slug_unique (slug),
        KEY smart_galleries_public_listing (enabled, visibility, title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "ALTER TABLE images
        ADD COLUMN editorial_rating TINYINT UNSIGNED NULL AFTER visibility,
        ADD KEY images_editorial_rating_lookup (editorial_rating, gallery_id, visibility)",
];
