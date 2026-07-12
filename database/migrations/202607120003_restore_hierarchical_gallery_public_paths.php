<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202607120003_restore_hierarchical_gallery_public_paths.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Restores complete hierarchical public paths after the first URL hardening repair.
 *
 * Responsibilities:
 *   - Infer gallery parents from canonical filesystem folder nesting
 *   - Repair historical null or stale parent_id values
 *   - Rebuild every gallery url_slug, url_path, and url_path_hash
 *   - Verify nested filesystem galleries remain nested in their public URLs
 *   - Remain harmless on fresh installations with no gallery rows
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
 *   2026-07-12
 */

declare(strict_types=1);

return [
    'statements' => [],
    'after' => static function (\PDO $pdo): void {
        $galleryCount = (int) $pdo->query('SELECT COUNT(*) FROM galleries')->fetchColumn();
        if ($galleryCount === 0) {
            return;
        }
        if (!function_exists('Gallery\\Services\\regenerate_gallery_public_paths')) {
            $projectRoot = dirname(__DIR__, 2);
            require_once $projectRoot . '/app/helpers.php';
            require_once $projectRoot . '/app/services/public_paths.php';
        }
        if (!function_exists('Gallery\\Services\\regenerate_gallery_public_paths')) {
            throw new \RuntimeException('Hierarchical gallery path repair is unavailable. Deploy the complete application patch before running migrations.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            \Gallery\Services\regenerate_gallery_public_paths($pdo);

            $nestedRows = $pdo->query("SELECT id, folder_path, url_path FROM galleries WHERE folder_path LIKE '%/%'")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($nestedRows as $row) {
                $urlPath = trim((string) ($row['url_path'] ?? ''), '/');
                if ($urlPath === '' || !str_contains($urlPath, '/')) {
                    throw new \RuntimeException(
                        'Hierarchical public path repair failed for gallery #' . (int) ($row['id'] ?? 0)
                        . ' (' . (string) ($row['folder_path'] ?? '') . ').'
                    );
                }
            }

            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    },
];
