<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/upload_auto_rename_selection_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects upload-time automatic renaming from planning a bounded upload batch
 *   as if every older gallery image were going to move in the same transaction.
 *
 * Responsibilities:
 *   - Preserve full-gallery sequence numbering for newly uploaded images
 *   - Treat non-selected gallery files and derivatives as fixed collision boundaries
 *   - Keep upload-time rename policy failures visible to API/browser callers
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

/**
 * Throw when an upload auto-rename source contract is not satisfied.
 *
 * @param bool $condition Assertion condition.
 * @param string $label Assertion label.
 */
function assert_upload_auto_rename_selection(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$renamerSource = (string) file_get_contents(__DIR__ . '/../app/services/media_renamer.php');
$uploadsSource = (string) file_get_contents(__DIR__ . '/../app/services/uploads.php');

assert_upload_auto_rename_selection(
    str_contains($renamerSource, 'function media_renamer_plan_for_gallery_selection(')
        && str_contains($renamerSource, '$selectedImages = $selectAll')
        && str_contains($renamerSource, '$currentFileKeys = media_renamer_current_file_keys($selectedImages, $gallery);'),
    'A bounded rename plan must mark only selected images as movable filesystem sources.'
);

assert_upload_auto_rename_selection(
    str_contains($renamerSource, 'if ($selectAll || isset($selectedImageIds[$imageId]))')
        && str_contains($renamerSource, '$sequence++;'),
    'A bounded rename plan must still advance sequence numbers across the complete gallery order.'
);

$batchStart = strpos($renamerSource, 'function media_renamer_execute_gallery_image_batch(');
$batchEnd = strpos($renamerSource, '/**', $batchStart + 20);
$batchSource = $batchStart === false ? '' : substr($renamerSource, $batchStart, ($batchEnd === false ? strlen($renamerSource) : $batchEnd) - $batchStart);
assert_upload_auto_rename_selection(
    str_contains($batchSource, 'media_renamer_plan_for_gallery_selection($galleryId, $requested, $pattern)')
        && !str_contains($batchSource, 'media_renamer_plan_for_gallery($galleryId, $pattern)'),
    'Upload-time batches must be planned as bounded selections instead of filtering a full movable-gallery plan afterward.'
);

assert_upload_auto_rename_selection(
    str_contains($uploadsSource, 'Automatic upload rename could not enforce the filename policy')
        && str_contains($uploadsSource, "in_array(\$status, ['collision', 'missing', 'skipped'], true)"),
    'Any upload row that remains unrenamed for a safety reason must be returned as an explicit rename failure.'
);

fwrite(STDOUT, "Upload auto-rename selection tests passed.\n");
