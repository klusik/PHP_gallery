<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/image_editor_mutations.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides image editor persistence use-cases above the image model layer.
 *
 * Responsibilities:
 *   - Persist semantic editable image fields
 *   - Keep timestamps and image-table SQL outside HTTP controllers
 *   - Share one persistence path between full Admin edit and inline edit workflows
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
 *   - Localization, tags, public-path regeneration, and responses stay with their existing orchestrators.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\now_sql;
use function Gallery\Models\image_model_update_fields;

/**
 * Persist semantic image editor fields and update the row timestamp.
 *
 * @param int $imageId Image identifier.
 * @param array<string,mixed> $fields Editable image column-value map.
 */
function image_editor_update_fields(int $imageId, array $fields): void
{
    image_model_update_fields($imageId, $fields, now_sql());
}
