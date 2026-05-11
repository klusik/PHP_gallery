<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605110001_auth_rate_limits.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds privacy-safe rate-limit storage for admin login and password reset flows.
 *
 * Responsibilities:
 *   - Store only hashed visitor and identifier subjects
 *   - Track rolling authentication attempts
 *   - Allow temporary lockouts without storing raw IP addresses or submitted usernames
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
 *   2026-05-11
 */

declare(strict_types=1);

return [
    "CREATE TABLE auth_rate_limits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        bucket VARCHAR(64) NOT NULL,
        subject_hash CHAR(64) NOT NULL,
        attempts INT NOT NULL DEFAULT 0,
        first_attempt_at DATETIME NOT NULL,
        last_attempt_at DATETIME NOT NULL,
        locked_until DATETIME NULL,
        UNIQUE KEY auth_rate_bucket_subject_unique (bucket, subject_hash),
        KEY auth_rate_locked_until_idx (locked_until),
        KEY auth_rate_last_attempt_idx (last_attempt_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
