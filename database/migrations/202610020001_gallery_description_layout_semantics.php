<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202610020001_gallery_description_layout_semantics.php
 * Module Type: Database Migration
 * Purpose: Preserve historical card appearance after correcting orientation labels.
 * Responsibilities:
 *   - Delegate replay-safe database and existing-sidecar conversion to the domain owner.
 *   - Keep new installations and inherited overrides distinct from explicit legacy values.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/models/gallery_description_layout_migration.php';
require_once dirname(__DIR__, 2) . '/app/services/gallery_description_layout_compatibility.php';

return ['statements' => [], 'after' => /**
 * Apply the one-time domain conversion before recording the migration version.
 * @param PDO $pdo Canonical migration connection.
 * @return void Preserves appearance or refuses the migration without a completion marker.
 */ static function (PDO $pdo): void {
    \Gallery\Services\gallery_description_layout_migrate_legacy($pdo);
}];
