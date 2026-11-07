<?php
/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Exercise token fidelity, schema refusal and redacted review without a live database.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_edit_concurrency_test.php
 * Module Type: Regression Test
 * Purpose: Verify token fidelity, schema refusal and secret-free conflict review without a database.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_edit_runtime.php';
require_once __DIR__ . '/support/gallery_workflow_safety.php';
require_once dirname(__DIR__) . '/app/views/admin_chrome.php';
require_once dirname(__DIR__) . '/app/views/admin_ui.php';
require_once dirname(__DIR__) . '/app/views/admin_gallery_edit_tabs.php';
require_once dirname(__DIR__) . '/app/views/public_gallery_lightbox.php';

use Gallery\Services\GalleryEditUnavailable;
use function Gallery\Services\gallery_edit_begin;
use function Gallery\Services\gallery_edit_comparison;
use function Gallery\Services\gallery_edit_revision;
use function Gallery\Services\gallery_edit_review_draft;
use function Gallery\Services\gallery_edit_schema_state;
use function Gallery\Services\schema_inspection_set_query_executor_for_tests;
use function Gallery\Views\view_render_admin_gallery_edit_conflict;
use function Gallery\Views\view_render_admin_gallery_editor_form_close;
use function Gallery\Views\view_render_admin_gallery_editor_form_open;
use function Gallery\Views\view_render_admin_gallery_identity_tab;
use function GalleryWorkflow\check;

/**
 * Separate disposable filesystem-fence fixture names from unrelated files.
 * Type: int. Units: random bytes. Scope: one temporary test directory.
 * Consumers: random_bytes. Rationale: 12 bytes make collisions negligible.
 */
const GALLERY_EDIT_FENCE_FIXTURE_RANDOM_BYTES = 12;

$fenceRoot = realpath(sys_get_temp_dir());
check(is_string($fenceRoot) && is_dir($fenceRoot), 'Temporary fence root is unavailable.');
$fenceDirectory = $fenceRoot . DIRECTORY_SEPARATOR . 'gallery-fence-'
    . bin2hex(random_bytes(GALLERY_EDIT_FENCE_FIXTURE_RANDOM_BYTES));
check(mkdir($fenceDirectory, 0700), 'Could not create the disposable fence fixture.');
$fenceDirectoryStat = lstat($fenceDirectory);
$fencePath = $fenceDirectory . DIRECTORY_SEPARATOR
    . \Gallery\Services\GALLERY_EDIT_FILESYSTEM_FENCE_FILENAME;
$fenceHandle = fopen($fencePath, 'x+b');
check(is_resource($fenceHandle), 'Could not create the fixture lock.');
check(fwrite($fenceHandle, 'fixture-owned') === strlen('fixture-owned'),
    'Could not write the fixture sentinel.');
fclose($fenceHandle);
$fenceFileStat = lstat($fencePath);
$fence = new \Gallery\Services\GalleryEditFilesystemFence($fenceDirectory);
$fenceAcquire = new ReflectionMethod($fence, 'acquireOwned');
$fenceHeld = 0;
$competitor = null;
$extraLink = $fenceDirectory . DIRECTORY_SEPARATOR . 'fixture-link';
try {
    check(\Gallery\Services\GALLERY_EDIT_FILESYSTEM_FENCE_ENABLED === false,
        'The unqualified filesystem fence must remain disabled.');
    $disabledRefused = false;
    try {
        $fence->acquire();
    } catch (RuntimeException) {
        $disabledRefused = true;
    }
    check($disabledRefused && file_get_contents($fencePath) === 'fixture-owned',
        'Public fence admission must refuse without changing the file.');
    $fenceAcquire->invoke($fence);
    $fenceHeld++;
    $fenceAcquire->invoke($fence);
    $fenceHeld++;
    $competitor = fopen($fencePath, 'rb');
    check(is_resource($competitor) && !flock($competitor, LOCK_EX | LOCK_NB),
        'A second open handle must not acquire an owned fence.');
    $fence->release();
    $fenceHeld--;
    check(!flock($competitor, LOCK_EX | LOCK_NB),
        'A reentrant fence must remain held until its last release.');
    $fence->release();
    $fenceHeld--;
    check(flock($competitor, LOCK_EX | LOCK_NB),
        'A competing handle must acquire after the final release.');
    flock($competitor, LOCK_UN);
    fclose($competitor);
    $competitor = null;
    if (function_exists('link') && @link($fencePath, $extraLink)) {
        $hardlinkRefused = false;
        try {
            $fenceAcquire->invoke($fence);
            $fenceHeld++;
        } catch (RuntimeException) {
            $hardlinkRefused = true;
        }
        check($hardlinkRefused && file_get_contents($fencePath) === 'fixture-owned',
            'A multiply linked fence must be refused without writing.');
        check(unlink($extraLink), 'Could not remove the owned hardlink.');
    }
    $symlinkTarget = $fenceDirectory . DIRECTORY_SEPARATOR . 'fixture-symlink-target';
    check(rename($fencePath, $symlinkTarget), 'Could not isolate the owned lock.');
    $linkCreated = false;
    try {
        if (function_exists('symlink') && @symlink($symlinkTarget, $fencePath)) {
            $linkCreated = true;
            $symlinkRefused = false;
            try {
                $fenceAcquire->invoke($fence);
                $fenceHeld++;
            } catch (RuntimeException) {
                $symlinkRefused = true;
            }
            check($symlinkRefused && file_get_contents($symlinkTarget) === 'fixture-owned',
                'A linked fence must be refused without writing to its target.');
        }
    } finally {
        if ($linkCreated) {
            check(is_link($fencePath) && readlink($fencePath) === $symlinkTarget
                && unlink($fencePath), 'The owned fixture symlink changed.');
        }
        check(rename($symlinkTarget, $fencePath), 'Could not restore the owned lock.');
    }
    $fenceAcquire->invoke($fence);
    $fenceHeld++;
    $backup = $fenceDirectory . DIRECTORY_SEPARATOR . 'fixture-original';
    check(rename($fencePath, $backup), 'Could not move the owned lock for the identity check.');
    $replacement = fopen($fencePath, 'x+b');
    check(is_resource($replacement), 'Could not create the owned replacement.');
    $replacementStat = fstat($replacement);
    fclose($replacement);
    try {
        $replacementRefused = false;
        try {
            $fence->release();
            $fenceHeld--;
        } catch (RuntimeException) {
            $replacementRefused = true;
        }
        check($replacementRefused, 'A replaced fence inode must not be accepted.');
    } finally {
        clearstatcache(true, $fencePath);
        $currentReplacement = lstat($fencePath);
        check(is_array($replacementStat) && is_array($currentReplacement)
            && !is_link($fencePath) && $currentReplacement['dev'] === $replacementStat['dev']
            && $currentReplacement['ino'] === $replacementStat['ino'] && unlink($fencePath),
            'The owned replacement changed identity.');
        check(rename($backup, $fencePath), 'Could not restore the owned lock.');
    }
    $fence->release();
    $fenceHeld--;
    check(file_get_contents($fencePath) === 'fixture-owned',
        'The fence must never truncate or replace its lock file.');
} finally {
    if (is_resource($competitor)) {
        fclose($competitor);
    }
    while ($fenceHeld > 0) {
        $fence->release();
        $fenceHeld--;
    }
    clearstatcache(true, $fencePath);
    $fenceAfter = lstat($fencePath);
    $directoryAfter = lstat($fenceDirectory);
    check(is_array($fenceFileStat) && is_array($fenceAfter) && !is_link($fencePath)
        && $fenceAfter['dev'] === $fenceFileStat['dev']
        && $fenceAfter['ino'] === $fenceFileStat['ino'] && unlink($fencePath),
        'The fixture lock changed ownership.');
    check(is_array($fenceDirectoryStat) && is_array($directoryAfter)
        && !is_link($fenceDirectory) && $directoryAfter['dev'] === $fenceDirectoryStat['dev']
        && $directoryAfter['ino'] === $fenceDirectoryStat['ino'] && rmdir($fenceDirectory),
        'The fixture directory changed ownership.');
}

$gallery = [
    'id' => 8,
    'edit_revision' => '17',
    'title' => 'Original',
    'access_password_hash' => 'private-password-hash',
    'access_token_hash' => 'private-token-hash',
    'access_share_token' => 'private-share-token',
];
check(
    gallery_edit_revision($gallery) === '17',
    'Rendered revision must belong to the supplied row.',
);
foreach ([
    null,
    [],
    '',
    '0',
    '01',
    '-1',
    '1e3',
    '17 ',
    '0x10',
    '100000000000000000000',
] as $invalid) {
    check(gallery_edit_revision(['edit_revision' => $invalid])
        === '', 'Malformed revision accepted.');
}
$comparison = gallery_edit_comparison($gallery);
check(
    $comparison === ['id' => 8, 'edit_revision' => '17', 'title' => 'Original'],
    'Comparison exposed non-allowlisted fields.',
);
$draft = gallery_edit_review_draft(
    $gallery
        + [
            'access_password' => 'private-submitted-password',
            'csrf_token' => 'private-csrf',
            'folder_name' => '</textarea><script>unsafe</script>',
            'tags' => 'keep these tags',
            'gallery_thumbnail_bounds_recursive' => '1',
            'remove_branding_logo' => '1',
            'gallery_presentation_present' => '1',
            'gallery_presentation_music' => '0',
            'gallery_presentation_interval' => 'fast',
        ],
);
check(
    !isset($draft['id'], $draft['edit_revision'], $draft['access_password']),
    'Draft contains transport or credential fields.',
);
check(
    ($draft['gallery_thumbnail_bounds_recursive'] ?? '') === '1'
        && ($draft['remove_branding_logo'] ?? '') === '1',
    'Review dropped an entered recursive or asset-removal choice.',
);
check(
    ($draft['gallery_presentation_music'] ?? '') === '0'
        && ($draft['gallery_presentation_interval'] ?? '') === 'fast',
    'Conflict review dropped entered presentation preferences.',
);
ob_start();
view_render_admin_gallery_editor_form_open([
    'gallery_id' => 8,
    'edit_revision' => gallery_edit_revision($gallery),
]);
view_render_admin_gallery_identity_tab([
    'active' => true,
    'gallery' => $gallery + ['slug' => 'original'],
    'folder_name' => 'original',
    'labels' => ['title' => 'Title', 'description' => 'Description'],
    'intro' => ['title' => 'Identity'],
]);
view_render_admin_gallery_editor_form_close([
    'save_label' => 'Save gallery',
    'help' => 'Save every shared tab.',
]);
$form = (string) ob_get_clean();
ob_start();
\Gallery\Views\view_render_admin_gallery_presentation([
    'gallery_id' => 8,
    'ready' => true,
    'settings' => [
        'version' => 1,
        'enabled' => true,
        'autoplay' => true,
        'music' => false,
        'interval' => 'fast',
        'transition' => 'slow',
    ],
    'url' => 'https://gallery.test/?presentation=1&presentation_music=0',
]);
$presentation = (string) ob_get_clean();
check(
    str_contains($form, 'id="admin-gallery-settings-8"')
        && substr_count($presentation, 'form="admin-gallery-settings-8"') === 7
        && !str_contains($presentation, '<form')
        && str_contains($presentation, 'data-gallery-presentation-editor'),
    'Presentation must use native association with the existing guarded form.',
);
check(
    str_contains($form, 'name="edit_revision" value="17"'),
    'Form omitted exact prepared revision.',
);
check(
    str_contains($form, 'data-admin-gallery-settings-form')
        && str_contains($form, 'id="admin-edit-identity"')
        && str_contains($form, 'data-admin-tab-panel')
        && str_contains($form, 'data-admin-gallery-savebar')
        && preg_match(
            '/<form[^>]*data-admin-gallery-settings-form[^>]*>.*data-admin-gallery-savebar.*<\/form>/s',
            $form,
        ) === 1,
    'Gallery settings form must own a stable, server-rendered save bar.',
);

ob_start();
view_render_admin_gallery_edit_conflict([
    'message' => 'Conflict',
    'reload_url' => '?id=8',
    'latest' => $comparison,
    'draft' => $draft,
    'labels' => [
        'draft' => 'Entered',
        'latest' => 'Latest',
        'open' => 'Review',
        'help' => 'Keep this page',
    ],
]);
$html = (string) ob_get_clean();
check(
    str_contains($html, 'keep these tags')
        && str_contains($html, 'target="_blank"'),
    'No-JavaScript review lost entered values or latest-editor recovery.',
);
check(
    !str_contains($html, '<script>') && !str_contains($html, 'private-'),
    'Conflict markup leaked secrets or unescaped values.',
);

schema_inspection_set_query_executor_for_tests(
    /**
     * Simulate a conclusive missing revision column without connecting to any database.
     * @param string $objectType Metadata object kind.
     * @param string $table Owning table.
     * @param string $object Requested column.
     * @return bool Confirmed absence.
     * @author Rudolf Klusal
     */
    static function (string $objectType, string $table, string $object): bool {
        return false;
    },
);
check(
    gallery_edit_schema_state() === 'missing',
    'Missing revision state was not preserved.',
);
try {
    gallery_edit_begin($gallery, '17');
    throw new RuntimeException('Missing revision schema allowed a mutation.');
} catch (GalleryEditUnavailable $exception) {
    check(
        str_contains($exception->getMessage(), 'migration'),
        'Missing schema should explain migration recovery.',
    );
}

schema_inspection_set_query_executor_for_tests(
    /**
     * Simulate an unavailable metadata source; exception text must never escape.
     * @param string $objectType Metadata object kind.
     * @param string $table Owning table.
     * @param string $object Requested column.
     * @return bool Never returns; throws the injected metadata exception.
     * @author Rudolf Klusal
     */
    static function (string $objectType, string $table, string $object): bool {
        throw new RuntimeException('private-database-detail');
    },
);
check(
    gallery_edit_schema_state() === 'unknown',
    'Inspection uncertainty was collapsed into missing.',
);
try {
    gallery_edit_begin($gallery, '17');
    throw new RuntimeException('Unknown revision schema allowed a mutation.');
} catch (GalleryEditUnavailable $exception) {
    check(
        !str_contains($exception->getMessage(), 'private-'),
        'Raw metadata exception escaped.',
    );
}
schema_inspection_set_query_executor_for_tests(null);
foreach (['en', 'cs', 'de', 'sv'] as $language) {
    $json = json_decode(
        (string) file_get_contents(
            dirname(__DIR__) . '/app/lang/' . $language . '.json',
        ),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $fallback = require dirname(__DIR__) . '/app/lang/' . $language . '.php';
    foreach ([
        'busy',
        'conflict',
        'schema_missing',
        'schema_unknown',
        'connection_lost',
        'review_title',
        'review_draft',
        'review_latest',
        'review_open',
        'review_help',
    ] as $suffix) {
        $key = 'admin.gallery_editor.edit_' . $suffix;
        check(
            is_string($json[$key] ?? null)
                && $json[$key] !== ''
                && ($fallback[$key] ?? null) === $json[$key],
            'A maintained conflict translation or matching PHP fallback is missing.',
        );
    }
}
echo
    "PASS gallery edit revision fidelity, secret-free review, missing and unknown refusal\n";
