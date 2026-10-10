<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610100001_public_content_widgets.php
 * Module Type: Database Migration
 * Purpose: Create dormant, durable public content widget storage.
 * Responsibilities:
 *   - Preserve stable widget identities, safe placement configuration and revisions.
 *   - Leave existing public pages unchanged until a widget is explicitly published.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS public_content_widgets (
        widget_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        title VARCHAR(180) NOT NULL DEFAULT '',
        content_md MEDIUMTEXT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'draft',
        page_scope VARCHAR(12) NOT NULL DEFAULT 'home',
        placement_mode VARCHAR(12) NOT NULL DEFAULT 'flow',
        flow_slot VARCHAR(24) NOT NULL DEFAULT 'home_after_grid',
        floating_anchor VARCHAR(24) NOT NULL DEFAULT 'bottom-right',
        x_permille SMALLINT UNSIGNED NOT NULL DEFAULT 900,
        y_permille SMALLINT UNSIGNED NOT NULL DEFAULT 900,
        width_px SMALLINT UNSIGNED NOT NULL DEFAULT 320,
        mobile_fallback VARCHAR(12) NOT NULL DEFAULT 'flow',
        appearance VARCHAR(12) NOT NULL DEFAULT 'card',
        sort_order INT UNSIGNED NOT NULL DEFAULT 0,
        source_language CHAR(2) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'en',
        revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (widget_id),
        KEY public_content_widgets_public (status, page_scope, sort_order, widget_id),
        KEY public_content_widgets_order (sort_order, widget_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
