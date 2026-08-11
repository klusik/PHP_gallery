<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/tag_metadata_mysql_compatibility_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies that Admin tag usage SQL remains compatible with MySQL servers that strictly validate DISTINCT ordering.
 *
 * Responsibilities:
 *   - Keep the image ordering column present in the DISTINCT select list
 *   - Preserve deterministic Admin tag image ordering by gallery, image order, filename, and id
 *   - Prevent the MySQL error 3065 regression seen when ORDER BY references an unselected expression
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
 *   2026-08-11
 */

declare(strict_types=1);

/**
 * Require one source fragment to remain present.
 *
 * @param string $source Source text.
 * @param string $needle Required fragment.
 * @param string $label Assertion label.
 */
function assert_tag_metadata_mysql_source_contains(string $source, string $needle, string $label): void
{
    if (!str_contains($source, $needle)) {
        throw new RuntimeException($label . ' is missing required source fragment: ' . $needle);
    }
}

$serviceSource = (string) file_get_contents(__DIR__ . '/../app/services/tag_metadata.php');
$adminTagsSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_tags.php');

assert_tag_metadata_mysql_source_contains(
    $serviceSource,
    'SELECT DISTINCT i.id, i.relative_path, i.filename, i.gallery_id, i.sort_order AS image_sort_order, g.title AS gallery_title, g.slug AS gallery_slug',
    'Admin tag image usage DISTINCT projection'
);
assert_tag_metadata_mysql_source_contains(
    $serviceSource,
    'SELECT DISTINCT g.id, g.title, g.slug, g.url_path, g.folder_path',
    'Admin tag gallery usage includes the stored clean public path'
);
assert_tag_metadata_mysql_source_contains(
    $serviceSource,
    '\'public_url\' => gallery_public_url($row)',
    'Admin tag gallery usage exposes the preferred public URL'
);
assert_tag_metadata_mysql_source_contains(
    $adminTagsSource,
    'e((string) $gallery[\'public_url\']) . \'" target="_blank" rel="noopener"',
    'Admin tag gallery usage links to the public gallery URL'
);
assert_tag_metadata_mysql_source_contains(
    $serviceSource,
    'ORDER BY g.title, i.sort_order, i.filename, i.id',
    'Admin tag image usage deterministic ordering'
);

echo "Tag metadata MySQL compatibility regression test passed.\n";
