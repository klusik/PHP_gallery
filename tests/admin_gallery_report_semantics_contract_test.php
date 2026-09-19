<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_gallery_report_semantics_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects precise unavailable, emptiness, access, and wide-image report semantics.
 *
 * Responsibilities:
 *   - Distinguish structural containers from genuinely empty leaf galleries
 *   - Preserve unknown filesystem capacity as null instead of numeric zero
 *   - Keep image-row visibility wording separate from effective anonymous access
 *   - Avoid claiming panorama detection from aspect ratio alone
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

/** Throw when one Admin report semantic contract fails. */
function admin_gallery_report_semantics_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$root = dirname(__DIR__);
$model = (string) file_get_contents($root . '/app/models/admin_gallery_report.php');
$system = (string) file_get_contents($root . '/app/services/admin_gallery_report/system_summary.php');
$image = (string) file_get_contents($root . '/app/services/admin_gallery_report/image_summary.php');
$view = (string) file_get_contents($root . '/app/views/admin_gallery_report_export.php');

admin_gallery_report_semantics_assert(
    str_contains($model, "'zero_direct_image_count'")
        && str_contains($model, "'empty_leaf_count'")
        && str_contains($model, "'structural_container_count'")
        && str_contains($model, 'NOT EXISTS (SELECT 1 FROM galleries c WHERE c.parent_id = g.id)')
        && str_contains($model, 'EXISTS (SELECT 1 FROM galleries c WHERE c.parent_id = g.id)'),
    'Gallery summary must distinguish zero-direct-image rows, empty leaves, and structural containers.'
);
admin_gallery_report_semantics_assert(
    str_contains($system, 'function admin_gallery_report_disk_free_bytes(string $path): ?int')
        && str_contains($system, 'function admin_gallery_report_disk_total_bytes(string $path): ?int')
        && substr_count($system, 'return null;') >= 2,
    'Unavailable filesystem capacity must remain null instead of being rendered as zero bytes.'
);
admin_gallery_report_semantics_assert(
    str_contains($view, 'admin_gallery_report_optional_bytes')
        && str_contains($view, "admin.gallery_report.export.unavailable")
        && str_contains($view, "admin.gallery_report.export.public_image_rows")
        && str_contains($view, "admin.gallery_report.export.image_access_policy_note"),
    'Report presentation must expose unavailable capacity and distinguish public image rows from effective access policy.'
);
admin_gallery_report_semantics_assert(
    str_contains($image, "'Wide (>= 2:1)'")
        && str_contains($image, 'panorama_count remains a compatibility counter'),
    'Aspect-ratio classification must be presented as wide rather than asserted as photographic panorama detection.'
);

echo "admin_gallery_report_semantics_contract_test: ok\n";
