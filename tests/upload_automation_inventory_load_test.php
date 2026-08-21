<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/upload_automation_inventory_load_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Verifies that routine upload-automation inventory checks stay database-only
 *   and that the filesystem fallback is reserved for bounded recovery probes.
 *
 * Responsibilities:
 *   - Protect shared hosting from routine gallery-directory scans during preflight
 *   - Preserve the explicit deep-check recovery path after ambiguous uploads
 *   - Keep the deep-check candidate budget bounded
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This test uses small namespace shims and does not require a live database.
 */

declare(strict_types=1);

namespace Gallery\Core {
    final class UploadAutomationInventoryFakeStatement
    {
        /**
         * Accept one prepared-statement execution without using a live database.
         *
         * @param array<int,mixed> $params Bound parameters.
         * @return bool Always true for the test shim.
         */
        public function execute(array $params = []): bool
        {
            return true;
        }

        /**
         * Return no checksum matches for the inventory lookup.
         *
         * @return array<int,array<string,mixed>> Empty result set.
         */
        public function fetchAll(): array
        {
            return [];
        }

        /**
         * Return the aggregate row consumed by the inventory fingerprint helper.
         *
         * @return array<string,mixed> Deterministic empty-gallery aggregate.
         */
        public function fetch(): array
        {
            return [
                'image_count' => 0,
                'newest_update' => '',
                'total_size' => '0',
            ];
        }
    }

    final class UploadAutomationInventoryFakeDb
    {
        /**
         * Return the statement shim for any prepared query used by this contract.
         *
         * @param string $sql SQL text supplied by the service.
         * @return UploadAutomationInventoryFakeStatement Prepared-statement shim.
         */
        public function prepare(string $sql): UploadAutomationInventoryFakeStatement
        {
            return new UploadAutomationInventoryFakeStatement();
        }
    }

    /**
     * Return the deterministic database shim used by the service under test.
     *
     * @return UploadAutomationInventoryFakeDb Database shim.
     */
    function db(): UploadAutomationInventoryFakeDb
    {
        static $db;
        if (!$db instanceof UploadAutomationInventoryFakeDb) {
            $db = new UploadAutomationInventoryFakeDb();
        }
        return $db;
    }

    /**
     * Treat test filenames as supported images.
     *
     * @param string $path Candidate image path.
     * @return bool Always true for this isolated contract.
     */
    function is_supported_image_path(string $path): bool
    {
        return true;
    }

    /**
     * Normalize path separators for deterministic inventory output.
     *
     * @param string $path Relative path.
     * @return string Normalized path.
     */
    function normalize_relative_path(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Return a deterministic SQL timestamp for the response payload.
     *
     * @return string Fixed timestamp.
     */
    function now_sql(): string
    {
        return '2026-08-21 08:00:00';
    }
}

namespace Gallery\Services {
    $GLOBALS['upload_automation_inventory_gallery_abs_path_calls'] = 0;

    /**
     * Track whether the service enters the expensive filesystem fallback.
     *
     * @param string $relativePath Gallery-relative path.
     * @return string Nonexistent deterministic directory path.
     */
    function gallery_abs_path(string $relativePath): string
    {
        $GLOBALS['upload_automation_inventory_gallery_abs_path_calls']++;
        return __DIR__ . '/nonexistent-upload-automation-inventory-gallery';
    }

    require_once __DIR__ . '/../app/services/upload_automation.php';

    /**
     * Emit one compact assertion result and fail the standalone test on mismatch.
     *
     * @param bool $condition Assertion condition.
     * @param string $label Human-readable assertion label.
     */
    function upload_automation_inventory_load_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$label}\n");
            exit(1);
        }
        fwrite(STDOUT, "PASS: {$label}\n");
    }

    $candidates = [];
    for ($index = 0; $index < 20; $index++) {
        $candidates[] = [
            'client_id' => 'candidate-' . $index,
            'filename' => 'candidate-' . $index . '.jpg',
            'size' => 1000 + $index,
            'sha256' => hash('sha256', 'candidate-' . $index),
        ];
    }

    $gallery = ['folder_path' => 'gallery'];

    $routine = upload_automation_gallery_inventory_response(7, $gallery, $candidates, false);
    upload_automation_inventory_load_assert(
        $GLOBALS['upload_automation_inventory_gallery_abs_path_calls'] === 0,
        'routine inventory does not enter the filesystem fallback'
    );
    upload_automation_inventory_load_assert(
        ($routine['deep_check_applied'] ?? null) === false,
        'routine inventory reports that no deep check ran'
    );

    $GLOBALS['upload_automation_inventory_gallery_abs_path_calls'] = 0;
    $deep = upload_automation_gallery_inventory_response(7, $gallery, $candidates, true);
    upload_automation_inventory_load_assert(
        $GLOBALS['upload_automation_inventory_gallery_abs_path_calls'] === 1,
        'explicit deep inventory enters the filesystem fallback exactly once'
    );
    upload_automation_inventory_load_assert(
        ($deep['deep_check_applied'] ?? null) === true,
        'explicit deep inventory reports that recovery checking was applied'
    );

    $source = file_get_contents(__DIR__ . '/../app/services/upload_automation.php') ?: '';
    upload_automation_inventory_load_assert(
        str_contains($source, 'array_slice($candidates, 0, 8)'),
        'deep filesystem recovery is capped to eight candidates'
    );

    fwrite(STDOUT, "Upload automation inventory load tests passed.\n");
}
