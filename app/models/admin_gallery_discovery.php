<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/models/admin_gallery_discovery.php
 * Module Type: Model
 *
 * Purpose:
 *   Owns database reads used by Admin gallery-discovery duplicate/path checks.
 *
 * Responsibilities:
 *   - Return indexed gallery folder paths
 *   - Return compact existing gallery identity rows
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
 */

declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use function Gallery\Core\db;

/** Return every indexed gallery folder path. */
function admin_gallery_discovery_model_folder_paths(): array
{
    $stmt = db()->prepare('SELECT folder_path FROM galleries');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return is_array($rows) ? $rows : [];
}

/** Return compact existing gallery rows used by title/path duplicate detection. */
function admin_gallery_discovery_model_existing_gallery_rows(): array
{
    $stmt = db()->prepare('SELECT id, title, folder_path FROM galleries');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
}
