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

require_once __DIR__ . '/support/module_source.php';
require_once __DIR__ . '/support/gallery_edit_writer_source.php';
require_once __DIR__ . '/../app/services/media_renamer.php';

/**
 * Throw when an upload auto-rename source contract is not satisfied.
 *
 * @param bool $condition Assertion condition.
 * @param string $label Assertion label.
 * @return void Throws with the supplied contract label when the condition fails.
 */
function assert_upload_auto_rename_selection(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$renamerSource = module_source(__DIR__ . '/../app/services/media_renamer.php');
$uploadsSource = module_source(__DIR__ . '/../app/services/uploads.php');

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

// Token boundaries preserve the complete owned body across nested callback
// docblocks; compact executable tokens keep comments from supplying fake proof.
$renamerFunctions = gallery_writer_functions($renamerSource);
$wrapperSource = gallery_writer_compact($renamerFunctions['media_renamer_execute_gallery_image_batch']['body'] ?? '');
assert_upload_auto_rename_selection(
    str_contains($wrapperSource, 'gallery_edit_writer_begin()')
        && str_contains($wrapperSource, gallery_writer_compact('return media_renamer_execute_gallery_image_batch_owned($galleryId, $imageIds, $pattern);'))
        && str_contains($wrapperSource, 'finally')
        && str_contains($wrapperSource, 'gallery_edit_writer_end($writerLock);'),
    'The bounded rename boundary must retain ownership and forward the original gallery, selection and pattern unchanged.'
);
$batchSource = gallery_writer_compact($renamerFunctions['media_renamer_execute_gallery_image_batch_owned']['body'] ?? '');
assert_upload_auto_rename_selection(
    str_contains($batchSource, gallery_writer_compact('media_renamer_plan_for_gallery_selection($galleryId, $requested, $pattern)'))
        && !str_contains($batchSource, gallery_writer_compact('media_renamer_plan_for_gallery($galleryId, $pattern)')),
    'Upload-time batches must be planned as bounded selections instead of filtering a full movable-gallery plan afterward.'
);

// This source is parsed, never executed. A documented callback and a misleading
// comment must not hide the final bounded call or invent an unbounded call.
$extractionFixture = <<<'PHP'
<?php
function upload_selection_fixture_owned($galleryId, $requested, $pattern) {
    /** An interior shape contains braces: array{id:int}. */
    $keep = static fn (int $id): bool => $id > 0;
    /* media_renamer_plan_for_gallery($galleryId, $pattern) is not executable. */
    return media_renamer_plan_for_gallery_selection(
        $galleryId, $requested, $pattern
    );
}
PHP;
$fixtureFunctions = gallery_writer_functions($extractionFixture);
$fixtureBody = gallery_writer_compact($fixtureFunctions['upload_selection_fixture_owned']['body'] ?? '');
assert_upload_auto_rename_selection(
    str_contains($fixtureBody, gallery_writer_compact('media_renamer_plan_for_gallery_selection($galleryId, $requested, $pattern)'))
        && !str_contains($fixtureBody, gallery_writer_compact('media_renamer_plan_for_gallery($galleryId, $pattern)')),
    'Owned-function extraction must survive callback docblocks, ignore comment-only calls and normalize call layout.'
);

assert_upload_auto_rename_selection(
    str_contains($uploadsSource, 'Automatic upload rename could not enforce the filename policy')
        && str_contains($uploadsSource, "in_array(\$status, ['collision', 'missing', 'skipped'], true)"),
    'Any upload row that remains unrenamed for a safety reason must be returned as an explicit rename failure.'
);

// Execute the production planner's selection/order loop with inert row/path
// adapters. Filesystem naming/collisions retain their separate regression owners.
$gallery = ['id' => 606, 'folder_path' => 'flight', 'title' => 'old_gallery'];
$rows = [
    ['id' => 13, 'filename' => 'z.png', 'sort_order' => 10],
    ['id' => 7, 'filename' => 'a.png', 'sort_order' => 20],
];
$planner = gallery_writer_fixture_function(
    $renamerSource,
    'media_renamer_plan_for_gallery_selection',
    [
        'find_gallery' => static function (int $id, bool $refresh) use (&$gallery): array {
            assert_upload_auto_rename_selection(
                $id === $gallery['id'] && $refresh,
                'The planner must resolve the current gallery identity.'
            );
            return $gallery;
        },
        'gallery_images' => static function (int $id, bool $publicOnly) use (&$rows): array {
            assert_upload_auto_rename_selection(
                !$publicOnly,
                'Sequence numbering must include unpublished images.'
            );
            return $rows;
        },
        'gallery_abs_path' => static fn (string $path): string => '/fixture/' . $path,
        'media_renamer_gallery_context_base' => static fn (array $row): string => $row['title'],
        'media_renamer_current_file_keys' => static fn (array $images, array $row): array => [],
        'media_renamer_plan_item' => static function (
            array $row,
            string $root,
            string $context,
            string $pattern,
            array $image,
            int $sequence,
            array $selected,
            array $current,
            array &$used
        ): array {
            return [
                'image_id' => $image['id'],
                'sequence' => $sequence,
                'context' => $context,
                'status' => !empty($image['already_matches'])
                    ? 'already_matches' : 'rename',
            ];
        },
    ]
);
$initial = $planner(606);
assert_upload_auto_rename_selection(
    array_column($initial['items'], 'image_id') === [13, 7]
        && array_column($initial['items'], 'sequence') === [1, 2],
    'Numbering must follow persisted gallery order, not filenames or image IDs.'
);
$gallery['title'] = 'renamed_gallery';
$afterTitleChange = $planner(606);
assert_upload_auto_rename_selection(
    array_column($afterTitleChange['items'], 'sequence') === [1, 2]
        && array_column($afterTitleChange['items'], 'context')
            === ['renamed_gallery', 'renamed_gallery'],
    'Changing gallery context must preserve existing image positions.'
);

// New uploads append after existing sort positions. Hidden no-op rows still
// consume their original sequence numbers across later upload/apply selections.
$rows[0]['already_matches'] = true;
$rows[1]['already_matches'] = true;
$rows[] = ['id' => 90, 'filename' => 'new-a.png', 'sort_order' => 30];
$rows[] = ['id' => 2, 'filename' => 'new-b.png', 'sort_order' => 40];
$before = $rows;
$newUploads = $planner(606, [2, 90]);
$allImages = $planner(606);
$lastBatch = $planner(606, [2]);
assert_upload_auto_rename_selection(
    array_column($newUploads['items'], 'image_id') === [90, 2]
        && array_column($newUploads['items'], 'sequence') === [3, 4]
        && array_column($allImages['items'], 'sequence') === [3, 4]
        && array_column($lastBatch['items'], 'sequence') === [4]
        && $rows === $before,
    'Later uploads and rename batches must retain full-gallery numbering without changing older rows.'
);

fwrite(STDOUT, "Upload auto-rename selection tests passed.\n");
