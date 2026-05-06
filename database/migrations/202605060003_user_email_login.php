<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605060003_user_email_login.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds optional admin recovery email support and prepares username-or-email login.
 *
 * Responsibilities:
 *   - Add a nullable email column to users for existing installations
 *   - Add a unique email index while still allowing NULL for admins who have not filled it in yet
 *   - Keep the migration safe to replay on hosts where DDL partially succeeded earlier
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
    "ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL AFTER username",
    "ALTER TABLE users ADD UNIQUE KEY users_email_unique (email)",
];
