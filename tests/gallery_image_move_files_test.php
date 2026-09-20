<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Check exclusive moves and recovery without overwriting original fixture bytes.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_image_move_files_test.php
 * Module Type: Regression Test
 * Purpose: Verify exclusive same-filesystem movement and conservative recovery of original bytes.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
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
    require_once dirname(__DIR__) . '/app/services/gallery_image_move_journal.php';

    use function Gallery\Services\gallery_image_move_file_exclusive;
    use function Gallery\Services\gallery_image_move_fingerprint;
    use function Gallery\Services\gallery_image_move_reconcile_file;

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
    moveFilesCheck(mkdir($root, 0700), 'Could not allocate move fixture.');
    try {
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
        file_put_contents($root . '/target.jpg', 'unrelated-photo');
        $refused = false;
        try {
            gallery_image_move_file_exclusive($root . '/source.jpg', $root . '/target.jpg', $identity);
        } catch (RuntimeException) {
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
        echo "PASS image move exclusive link, rollback replay, collision and changed-identity preservation\n";
    } finally {
        foreach (['source.jpg', 'target.jpg'] as $leaf) {
            if (is_file($root . '/' . $leaf)) {
                unlink($root . '/' . $leaf);
            }
        }
        rmdir($root);
    }
}
