<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/updater_recovery_migration.php
 * Module Type: Migration Fixture
 * Purpose: Exercise real interrupted DDL and deterministic callback failures.
 * Responsibilities: Keep all SQL within a disposable updater test database.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    'statements' => ['CREATE TABLE IF NOT EXISTS recovery_probe (id INTEGER PRIMARY KEY)'],
    'after' => static function (\PDO $pdo): void {
        if (($GLOBALS['recovery_migration_failure'] ?? '') === 'undefined') {
            \Gallery\Models\gallery_visit_content_model_seed();
        }
        if (($GLOBALS['recovery_migration_failure'] ?? '') === 'transient') {
            throw new \RuntimeException('Migration transport temporarily unavailable.');
        }
        $pdo->exec('DELETE FROM recovery_probe');
        $pdo->exec('INSERT INTO recovery_probe VALUES (1)');
    },
];
