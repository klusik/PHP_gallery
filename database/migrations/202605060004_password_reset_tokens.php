<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605060004_password_reset_tokens.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds one-time admin password reset token storage for future recovery email flow.
 *
 * Responsibilities:
 *   - Store only hashed reset token material
 *   - Tie reset tokens to existing app users without a brittle cross-host foreign key
 *   - Allow token expiry and one-time use tracking
 *   - Avoid update failures on installs where users.id differs from the assumed signed INT shape
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
 *   2026-05-06
 */

declare(strict_types=1);

return [
    "CREATE TABLE password_reset_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        selector VARCHAR(32) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        requested_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        request_hash CHAR(64) NULL,
        UNIQUE KEY password_reset_selector_unique (selector),
        KEY password_reset_user_idx (user_id),
        KEY password_reset_expiry_idx (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "DELETE prt FROM password_reset_tokens prt LEFT JOIN users u ON u.id = prt.user_id WHERE u.id IS NULL",
];
