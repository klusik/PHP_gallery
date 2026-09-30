<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202609270002_cooperative_pairing.php
 * Module Type: Database Migration
 * Purpose: Persist resumable bilateral pairing without exposing system keys.
 * Responsibilities: Keep invitations, revisions and bounded retry state durable.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS cooperative_pairings (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        invitation_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        peer_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        role VARCHAR(16) NOT NULL,
        state VARCHAR(16) NOT NULL,
        revision BIGINT UNSIGNED NOT NULL,
        expires_at BIGINT UNSIGNED NOT NULL,
        secret_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        payload_cipher TEXT NOT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        next_attempt_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(48) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL,
        UNIQUE KEY cooperative_pairings_invitation (invitation_id),
        UNIQUE KEY cooperative_pairings_peer (peer_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
