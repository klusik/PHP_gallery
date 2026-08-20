<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608180002_viewer_registration_foundations.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds dormant pending-registration, invitation, and bounded registration-capacity storage.
 *
 * Responsibilities:
 *   - Keep unverified anonymous requests outside viewer_accounts
 *   - Store invitation and verification authority only as one-way hashes
 *   - Deduplicate pending requests by canonical normalized email
 *   - Bound staged-registration row growth with an explicit locked capacity counter
 *   - Provide indexed expiry fields for scheduled cleanup
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
 *   - This migration creates no viewer account and exposes no route.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS viewer_invitations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL,
        target_email_fingerprint CHAR(64) NULL,
        created_by_admin_user_id BIGINT UNSIGNED NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        claimed_at DATETIME NULL,
        revoked_at DATETIME NULL,
        UNIQUE KEY viewer_invitations_token_hash_unique (token_hash),
        KEY viewer_invitations_state_expiry_index (revoked_at, claimed_at, expires_at),
        KEY viewer_invitations_creator_created_index (created_by_admin_user_id, created_at),
        KEY viewer_invitations_expiry_index (expires_at),
        CONSTRAINT viewer_invitations_admin_foreign FOREIGN KEY (created_by_admin_user_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_registration_state (
        state_key VARCHAR(64) NOT NULL PRIMARY KEY,
        active_request_count INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_registration_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        normalized_email VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        email_fingerprint CHAR(64) NOT NULL,
        viewer_invitation_id BIGINT UNSIGNED NULL,
        status ENUM('pending_verification','email_verified','cancelled') NOT NULL DEFAULT 'pending_verification',
        request_ip_hash CHAR(64) NULL,
        verification_token_hash CHAR(64) NOT NULL,
        verification_token_expires_at DATETIME NOT NULL,
        verification_token_consumed_at DATETIME NULL,
        verification_send_count INT UNSIGNED NOT NULL DEFAULT 0,
        verification_last_sent_at DATETIME NULL,
        expires_at DATETIME NOT NULL,
        verified_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY viewer_registration_normalized_email_unique (normalized_email),
        UNIQUE KEY viewer_registration_verification_hash_unique (verification_token_hash),
        UNIQUE KEY viewer_registration_invitation_unique (viewer_invitation_id),
        KEY viewer_registration_status_expiry_index (status, expires_at),
        KEY viewer_registration_expiry_index (expires_at),
        KEY viewer_registration_token_expiry_index (verification_token_expires_at, verification_token_consumed_at),
        CONSTRAINT viewer_registration_invitation_foreign FOREIGN KEY (viewer_invitation_id) REFERENCES viewer_invitations(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
