<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_trash_admin_entries_test.php
 * Module Type: Regression Test
 * Purpose: Verify shared Admin trash row preparation with the actual domain policies.
 * Responsibilities:
 *   - Preserve raw row contracts and bounded list filters
 *   - Verify real retention countdowns and policy-approved purge affordances
 *   - Avoid additional overlap queries for ordinary isolated trashed entries
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Normalize the safe relative identities supplied by this fixture.
     * @param string $path Fixture gallery path.
     * @return string Normalized gallery path.
     */
    function normalize_relative_path(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}

namespace Gallery\Models {
    /**
     * Return isolated fixture rows while capturing bounded list arguments.
     * @param string $status Lifecycle filter selected by the service.
     * @param int $limit Bounded maximum row count.
     * @return list<array<string,mixed>> Stored fixture rows.
     */
    function gallery_trash_model_entries(string $status, int $limit): array
    {
        $GLOBALS['trash_admin_list_args'] = [$status, $limit];
        return $GLOBALS['trash_admin_rows'];
    }

    /**
     * Simulate live database overlap only for a broken restore marker.
     * @param string $path Original gallery identity.
     * @return list<int> IDs whose presence prevents purge.
     */
    function gallery_trash_model_live_row_ids_for_path(string $path): array
    {
        $GLOBALS['trash_admin_overlap_queries'][] = $path;
        return $path === 'live-overlap' ? [12] : [];
    }
}

namespace Gallery\Services {
    /**
     * Provide explicit schema availability for the fixture.
     * @return array{state:string} Schema observation.
     */
    function gallery_trash_schema_status(): array
    {
        return ['state' => $GLOBALS['trash_admin_schema_state']];
    }

    /**
     * Resolve schema availability without treating unknown as missing.
     * @param array{state:string} $status Schema observation.
     * @return bool Whether the schema is verified available.
     */
    function schema_inspection_is_available(array $status): bool
    {
        return $status['state'] === 'available';
    }

    /**
     * Return a nonexistent fixture root for safe filesystem overlap checks.
     * @return string Gallery-root fixture whose child paths do not exist.
     */
    function galleries_root(): string
    {
        return sys_get_temp_dir() . '/php-gallery-nonexistent-admin-trash-root-' . getmypid();
    }

    require_once dirname(__DIR__) . '/app/services/gallery_trash.php';

    /**
     * Assert a shared Admin trash preparation contract.
     * @param bool $condition Expected result.
     * @param string $message Failure description.
     * @return void
     */
    function trash_admin_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    $GLOBALS['trash_admin_schema_state'] = 'available';
    $GLOBALS['trash_admin_overlap_queries'] = [];
    $GLOBALS['trash_admin_rows'] = [
        ['status' => 'trashed', 'purge_after' => date('Y-m-d H:i:s', time() + 7 * 86400), 'original_folder_path' => 'isolated'],
        ['status' => 'restoring', 'purge_after' => date('Y-m-d H:i:s', time() + 2 * 86400), 'original_folder_path' => 'in-progress'],
        ['status' => 'broken', 'purge_after' => '', 'original_folder_path' => 'safe-broken'],
        ['status' => 'broken', 'purge_after' => '', 'original_folder_path' => 'live-overlap'],
    ];
    $raw = gallery_trash_entries(['status' => 'active', 'limit' => 200]);
    $rows = gallery_trash_admin_entries(['status' => 'active', 'limit' => 200]);
    trash_admin_assert($GLOBALS['trash_admin_list_args'] === ['active', 200], 'Shared Admin preparation must preserve the active filter and bounded limit.');
    trash_admin_assert($rows[0]['view_days_remaining'] === 7 && $rows[1]['view_days_remaining'] === 2, 'Shared rows must expose the actual retention countdown rather than a missing-field zero.');
    trash_admin_assert(array_column($rows, 'view_can_purge') === [true, false, true, false], 'Purge affordances must follow ordinary, transitional, isolated broken, and overlapping broken policies.');
    trash_admin_assert($GLOBALS['trash_admin_overlap_queries'] === ['safe-broken', 'live-overlap'], 'Normal and transitional rows must not add live-overlap queries.');
    trash_admin_assert(!array_key_exists('view_can_purge', $raw[0]) && $GLOBALS['trash_admin_rows'] === $raw, 'The historical raw list contract must remain unchanged.');
    foreach ($rows as $index => $row) {
        unset($row['view_days_remaining'], $row['view_can_purge']);
        trash_admin_assert($row === $raw[$index], 'Admin preparation must preserve every existing raw field.');
    }
    $GLOBALS['trash_admin_schema_state'] = 'unknown';
    trash_admin_assert(gallery_trash_admin_entries() === [], 'Unknown schema must retain the existing refusal boundary.');
    echo "Gallery Trash Admin entries tests passed.\n";
}
