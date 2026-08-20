<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608180006_viewer_admin_account_management.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds the minimal durable first-login password-change state required for administrator-provisioned viewer accounts.
 *
 * Responsibilities:
 *   - Mark administrator-created temporary viewer passwords as non-durable credentials
 *   - Keep existing invitation-created viewer accounts immediately usable after their chosen password is set
 *   - Allow the authentication service to withhold the normal viewer principal until the temporary password is replaced
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
 *   - Existing rows receive the safe value 0 and therefore preserve current login behavior.
 *   - The flag is server-owned account state and is never accepted from viewer input.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

return [
    "ALTER TABLE viewer_accounts ADD COLUMN must_change_password TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER password_hash",
];
