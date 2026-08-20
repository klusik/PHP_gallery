<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608180005_viewer_invitation_admin_management.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Stores the intended invitation email for administrator-facing invitation management.
 *
 * Responsibilities:
 *   - Keep the exact normalized intended email available in the Admin invitation list
 *   - Preserve the existing HMAC fingerprint as the invitation authorization binding
 *   - Leave existing invitations compatible by allowing a null display email
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
 *   - The plaintext email is administrator-only metadata, not authorization authority.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

return [
    "ALTER TABLE viewer_invitations ADD COLUMN target_email VARCHAR(190) NULL AFTER token_hash",
];
