<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/migration_repairs.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides deterministic PHP data repairs used by database migrations.
 *
 * Responsibilities:
 *   - Keep migration repair callbacks reusable by the normal updater and installer
 *   - Preserve compatibility with the former SQL-only migration runner
 *   - Run gallery public-path repairs transactionally
 *   - Verify that nested galleries retain complete hierarchical public URLs
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

namespace Gallery\Core;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Build a migration definition for the gallery public-path repair.
 *
 * The optional legacy PDO argument is intentional. Migration files released in
 * July 2026 may be loaded by either the current definition-aware runner or the
 * former SQL-only runner. The former runner includes migration files directly
 * from a scope containing `$pdo`. In that case the repair is executed while the
 * file is loaded and an empty SQL statement list is returned, preventing arrays
 * from being passed to apply_migration_statement().
 *
 * @param PDO|null $legacyPdo Database connection exposed by the former runner.
 * @param bool $verifyHierarchy Whether nested physical galleries must have nested public paths.
 * @return array{statements: array<int,string>, after: callable|null}|array<int,string> Migration definition.
 */
function gallery_public_path_repair_migration_definition(?PDO $legacyPdo, bool $verifyHierarchy): array
{
    $repair = static function (PDO $pdo) use ($verifyHierarchy): void {
        run_gallery_public_path_repair($pdo, $verifyHierarchy);
    };

    if ($legacyPdo !== null) {
        $repair($legacyPdo);
        return [];
    }

    return [
        'statements' => [],
        'after' => $repair,
    ];
}

/**
 * Regenerate gallery public paths and optionally verify their hierarchy.
 *
 * @param PDO $pdo Database connection.
 * @param bool $verifyHierarchy Whether nested physical galleries must have nested public paths.
 */
function run_gallery_public_path_repair(PDO $pdo, bool $verifyHierarchy): void
{
    $galleryCount = (int) $pdo->query('SELECT COUNT(*) FROM galleries')->fetchColumn();
    if ($galleryCount === 0) {
        return;
    }

    if (!function_exists('Gallery\\Services\\regenerate_gallery_public_paths')) {
        $projectRoot = dirname(__DIR__);
        require_once $projectRoot . '/app/helpers.php';
        require_once $projectRoot . '/app/services/public_paths.php';
    }
    if (!function_exists('Gallery\\Services\\regenerate_gallery_public_paths')) {
        throw new RuntimeException('Gallery public path repair is unavailable. Deploy the complete migration compatibility patch before running migrations.');
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        \Gallery\Services\regenerate_gallery_public_paths($pdo);
        if ($verifyHierarchy) {
            verify_hierarchical_gallery_public_paths($pdo);
        }
        if ($ownsTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/**
 * Verify that every nested filesystem gallery also has a nested public path.
 *
 * @param PDO $pdo Database connection.
 */
function verify_hierarchical_gallery_public_paths(PDO $pdo): void
{
    $nestedRows = $pdo->query("SELECT id, folder_path, url_path FROM galleries WHERE folder_path LIKE '%/%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($nestedRows as $row) {
        $urlPath = trim((string) ($row['url_path'] ?? ''), '/');
        if ($urlPath !== '' && str_contains($urlPath, '/')) {
            continue;
        }

        throw new RuntimeException(
            'Hierarchical public path repair failed for gallery #' . (int) ($row['id'] ?? 0)
            . ' (' . (string) ($row['folder_path'] ?? '') . ').'
        );
    }
}
