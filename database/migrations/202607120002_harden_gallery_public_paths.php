<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202607120002_harden_gallery_public_paths.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Rebuilds clean public gallery paths using the current deterministic slug rules.
 *
 * Responsibilities:
 *   - Repair galleries whose url_path was never generated after creation or movement
 *   - Remove spaces, diacritics, combining marks, and unsafe characters from public URLs
 *   - Preserve hierarchical gallery URLs while resolving sibling collisions deterministically
 *   - Remain harmless on a fresh installation with no gallery rows
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
            throw new \RuntimeException('Gallery public path repair is unavailable. Deploy the complete application patch before running migrations.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            \Gallery\Services\regenerate_gallery_public_paths($pdo);
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
