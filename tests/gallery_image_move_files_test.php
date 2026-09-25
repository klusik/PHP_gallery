<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Check exclusive moves and recovery without overwriting original fixture bytes.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_image_move_files_test.php
 * Module Type: Regression Test
 * Purpose: Verify link-free physical movement and conservative recovery of original bytes.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Return the configured disposable gallery root for this test.
     * @return string Absolute fixture root.
     */
    function galleries_root(): string
    {
        return $GLOBALS['moveFilesFixture'];
    }

    /**
     * Resolve a fixture gallery path using the application's real relative-path normalizer.
     * @param string $relativePath Stored gallery path.
     * @return string Absolute fixture gallery path.
     */
    function gallery_abs_path(string $relativePath): string
    {
        return galleries_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, \Gallery\Core\normalize_relative_path($relativePath));
    }

    /**
     * Keep the isolated fixture's path check scoped to its physical gallery directory.
     * @param string $galleryRoot Existing fixture gallery directory.
     * @param string $path Candidate file path.
     * @return bool Whether the candidate is a descendant of that directory.
     */
    function thumbnail_path_inside_existing_gallery(string $galleryRoot, string $path): bool
    {
        return is_dir($galleryRoot) && str_starts_with($path, rtrim($galleryRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    /**
     * Detect any forbidden hard-link attempt during file movement or recovery.
     * @param string $from Source fixture file.
     * @param string $to Destination fixture file.
     * @return bool This operation is always refused by the fixture.
     */
    function link(string $from, string $to): bool
    {
        $GLOBALS['moveFilesLinkCalls'] = (int) ($GLOBALS['moveFilesLinkCalls'] ?? 0) + 1;
        throw new RuntimeException('Hard links are forbidden for image moves.');
    }

    /**
     * Accept only the explicitly owned temporary fixture as a storage boundary.
     *
     * @param string $path Directory resolved by the move primitive.
     * @return bool Whether the resolved path remains within the fixture.
     */
    function gallery_filesystem_path_inside_root(string $path): bool
    {
        $root = realpath($GLOBALS['moveFilesFixture']);
        $resolved = realpath($path);
        return is_string($root) && is_string($resolved)
            && ($resolved === $root || str_starts_with($resolved, $root . DIRECTORY_SEPARATOR));
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_files.php';
    require_once dirname(__DIR__) . '/app/services/gallery_image_move_journal.php';

    use function Gallery\Services\gallery_image_move_file_exclusive;
    use function Gallery\Services\gallery_image_move_exception_context;
    use function Gallery\Services\gallery_image_move_fingerprint;
    use function Gallery\Services\gallery_image_move_last_warning;
    use function Gallery\Services\gallery_image_move_manifest_path;
    use function Gallery\Services\gallery_image_move_reconcile_file;
    use Gallery\Services\ImageMoveDiagnosticFailure;

    /**
     * Require a file-safety postcondition without printing absolute fixture paths.
     *
     * @param bool $condition Required postcondition.
     * @param string $message Safe assertion label.
     * @return void
     */
    function moveFilesCheck(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $root = sys_get_temp_dir() . '/gallery-image-move-files-' . bin2hex(random_bytes(12));
    $GLOBALS['moveFilesFixture'] = $root;
    $GLOBALS['moveFilesLinkCalls'] = 0;
    moveFilesCheck(mkdir($root, 0700), 'Could not allocate move fixture.');
    try {
        moveFilesCheck(mkdir($root . '/event', 0700) && mkdir($root . '/event/day', 0700), 'Could not allocate nested gallery fixture.');
        file_put_contents($root . '/event/day/original.jpg', 'legacy-gallery-photo');
        $legacyPath = gallery_image_move_manifest_path('event/day/original.jpg', 'event\\day');
        moveFilesCheck($legacyPath === $root . DIRECTORY_SEPARATOR . 'event' . DIRECTORY_SEPARATOR . 'day' . DIRECTORY_SEPARATOR . 'original.jpg',
            'Legacy gallery separators incorrectly rejected a valid journal path.');
        $refusedLegacyEscape = false;
        try {
            gallery_image_move_manifest_path('event/other/original.jpg', 'event\\day');
        } catch (ImageMoveDiagnosticFailure $exception) {
            $refusedLegacyEscape = $exception->reason === 'manifest_path_invalid';
        }
        moveFilesCheck($refusedLegacyEscape, 'Normalized gallery prefix accepted another gallery path.');
        $refusedTraversal = false;
        try {
            gallery_image_move_manifest_path('event/day/../original.jpg', 'event\\day');
        } catch (ImageMoveDiagnosticFailure $exception) {
            $refusedTraversal = $exception->reason === 'manifest_path_invalid';
        }
        moveFilesCheck($refusedTraversal, 'Manifest traversal did not receive a safe refusal.');
        $privateCause = new RuntimeException('PRIVATE_DATABASE_PATH_AND_SECRET');
        $safeFailure = new ImageMoveDiagnosticFailure('file_transfer', 'file_rename_failed', str_repeat('a', 32), 2, 'derivative', $privateCause);
        moveFilesCheck(str_contains($safeFailure->getMessage(), 'derivative file 2')
            && str_contains($safeFailure->getMessage(), str_repeat('a', 32))
            && !str_contains($safeFailure->getMessage(), 'PRIVATE_')
            && $safeFailure->getPrevious() === $privateCause, 'Journal diagnostic lost safe context or exposed its private cause.');
        $detailedFailure = new ImageMoveDiagnosticFailure('file_transfer', 'storage_boundary_unverified', str_repeat('a', 32), 1, 'original',
            $privateCause, ['failed_step' => 'resolve_source', 'source_relative_path' => 'event/day/original.jpg']);
        $details = gallery_image_move_exception_context($detailedFailure);
        moveFilesCheck(($details['file_context']['source_relative_path'] ?? '') === 'event/day/original.jpg'
            && ($details['exceptions'][1]['message'] ?? '') === 'PRIVATE_DATABASE_PATH_AND_SECRET'
            && ($details['exceptions'][1]['php_file'] ?? '') === __FILE__
            && ($details['exceptions'][1]['php_line'] ?? 0) > 0,
            'Private Admin diagnostic did not retain the exact file and exception origin.');
        $databaseFailure = new ImageMoveDiagnosticFailure('database_commit', 'ownership_update_failed', previous: new PDOException('PRIVATE_SQL_PASSWORD'));
        $databaseDetails = gallery_image_move_exception_context($databaseFailure);
        moveFilesCheck(($databaseDetails['exceptions'][1]['message'] ?? '') === '[database exception message withheld]',
            'Database exception details escaped into the Admin log context.');
        error_clear_last();
        @trigger_error('PRIVATE_FILESYSTEM_WARNING', E_USER_WARNING);
        $nativeWarning = gallery_image_move_last_warning();
        moveFilesCheck($nativeWarning instanceof ErrorException
            && $nativeWarning->getMessage() === 'PRIVATE_FILESYSTEM_WARNING'
            && $nativeWarning->getFile() === __FILE__
            && $nativeWarning->getLine() > 0,
            'Suppressed filesystem warning lost its exact message or PHP origin.');
        file_put_contents($root . '/source.jpg', 'original-photo-fixture');
        $identity = gallery_image_move_fingerprint($root . '/source.jpg');
        $outside = $root . '-outside/nested/target.jpg';
        $refused = false;
        try {
            gallery_image_move_file_exclusive($root . '/source.jpg', $outside, $identity);
        } catch (RuntimeException) {
            $refused = true;
        }
        moveFilesCheck($refused && !file_exists($root . '-outside'), 'Refused destination created directories outside trusted storage.');
        gallery_image_move_file_exclusive($root . '/source.jpg', $root . '/target.jpg', $identity);
        moveFilesCheck(!file_exists($root . '/source.jpg')
            && hash_file('sha256', $root . '/target.jpg') === $identity['sha256'], 'Exclusive movement lost original bytes.');
        gallery_image_move_reconcile_file($root . '/source.jpg', $root . '/target.jpg', $identity);
        moveFilesCheck(!file_exists($root . '/target.jpg')
            && hash_file('sha256', $root . '/source.jpg') === $identity['sha256'], 'Rollback did not restore original bytes.');
        gallery_image_move_reconcile_file($root . '/source.jpg', $root . '/target.jpg', $identity);
        file_put_contents($root . '/target.jpg', 'original-photo-fixture');
        gallery_image_move_reconcile_file($root . '/source.jpg', $root . '/target.jpg', $identity);
        moveFilesCheck(is_file($root . '/source.jpg') && !file_exists($root . '/target.jpg'),
            'Rollback did not remove a verified interrupted destination copy.');
        file_put_contents($root . '/target.jpg', 'original-photo-fixture');
        gallery_image_move_reconcile_file($root . '/target.jpg', $root . '/source.jpg', $identity);
        moveFilesCheck(!file_exists($root . '/source.jpg') && is_file($root . '/target.jpg'),
            'Committed recovery did not retain the destination as the physical original.');
        gallery_image_move_reconcile_file($root . '/source.jpg', $root . '/target.jpg', $identity);
        moveFilesCheck($GLOBALS['moveFilesLinkCalls'] === 0, 'Image move or recovery attempted to create a hard link.');
        file_put_contents($root . '/target.jpg', 'unrelated-photo');
        $refused = false;
        try {
            gallery_image_move_file_exclusive($root . '/source.jpg', $root . '/target.jpg', $identity);
        } catch (ImageMoveDiagnosticFailure $exception) {
            moveFilesCheck($exception->reason === 'destination_occupied' && !str_contains($exception->getMessage(), $root), 'Occupied-target diagnostic leaked a path.');
            $refused = true;
        }
        moveFilesCheck($refused && file_get_contents($root . '/target.jpg') === 'unrelated-photo'
            && hash_file('sha256', $root . '/source.jpg') === $identity['sha256'], 'Occupied destination was overwritten.');
        $refused = false;
        try {
            gallery_image_move_reconcile_file($root . '/source.jpg', $root . '/target.jpg', $identity);
        } catch (RuntimeException) {
            $refused = true;
        }
        moveFilesCheck($refused && file_get_contents($root . '/target.jpg') === 'unrelated-photo', 'Recovery removed an unverified file.');
        unlink($root . '/target.jpg');
        file_put_contents($root . '/source.jpg', 'changed-original');
        $refused = false;
        try {
            gallery_image_move_file_exclusive($root . '/source.jpg', $root . '/target.jpg', $identity);
        } catch (RuntimeException) {
            $refused = true;
        }
        moveFilesCheck($refused && !file_exists($root . '/target.jpg'), 'Changed source was moved under stale intent.');
        echo "PASS image move physical rename, rollback replay, collision and changed-identity preservation\n";
    } finally {
        foreach (['source.jpg', 'target.jpg'] as $leaf) {
            if (is_file($root . '/' . $leaf)) {
                unlink($root . '/' . $leaf);
            }
        }
        @unlink($root . '/event/day/original.jpg');
        @rmdir($root . '/event/day');
        @rmdir($root . '/event');
        rmdir($root);
    }
}
