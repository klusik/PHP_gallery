<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_inline_image_delete_contract_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Prevents the public inline editor from deleting only an image database row.
 *
 * Responsibilities:
 *   - Require inline image deletion to use the filesystem-safe deletion service
 *   - Prevent the historical direct DELETE FROM images statement from returning
 *   - Keep original files and generated thumbnail cleanup on one mutation path
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 */

declare(strict_types=1);

$path = __DIR__ . '/../app/controllers/admin_public_inline.php';
$source = file_get_contents($path);
if (!is_string($source) || $source === '') {
    throw new RuntimeException('Could not read public inline admin controller.');
}

if (!str_contains($source, 'delete_gallery_images($galleryId, [(int) $image[\'id\']])')) {
    throw new RuntimeException('Public inline image deletion must use delete_gallery_images().');
}
if (str_contains($source, "DELETE FROM images WHERE id = ?")) {
    throw new RuntimeException('Public inline image deletion must not delete only the images database row.');
}

echo "Public inline image delete contract tests passed.\n";
