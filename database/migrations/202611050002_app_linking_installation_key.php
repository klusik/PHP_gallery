<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202611050002_app_linking_installation_key.php
 * Module Type: Database Migration
 * Purpose: Provision a durable secret during installation and update.
 * Responsibilities:
 *   - Generate independent key material using the platform random source.
 *   - Preserve the original installation key across retries and upgrades.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

if (!defined('GALLERY_APP_LINK_INSTALLATION_MIGRATION_KEY_BYTES')) {
    /**
     * Generate an independent installation key during provisioning.
     * Type: int. Units: random bytes. Scope: one installation key.
     * Consumers: this migration's key insert and the linking HKDF.
     * Rationale: 32 bytes provide 256 bits of entropy and fill BINARY(32).
     */
    define('GALLERY_APP_LINK_INSTALLATION_MIGRATION_KEY_BYTES', 32);
}

return [
    'statements' => [
        'CREATE TABLE IF NOT EXISTS app_link_installation_key (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            secret BINARY(32) NOT NULL
        ) ENGINE=InnoDB',
    ],
    'after' => static function (\PDO $pdo): void {
        // Keep this callback independent of the loaded runtime during updates.
        // Row 1 is the fixed installation identity. A replay keeps its key.
        $insert = $pdo->prepare(
            'INSERT INTO app_link_installation_key (id, secret) VALUES (1, UNHEX(?)) '
                . 'ON DUPLICATE KEY UPDATE id = id',
        );
        $insert->execute([
            bin2hex(random_bytes(GALLERY_APP_LINK_INSTALLATION_MIGRATION_KEY_BYTES)),
        ]);
    },
];
