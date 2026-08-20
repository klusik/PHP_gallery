<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608180004_viewer_account_lifecycle_foundations.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds staged viewer email-change storage for the final dormant account lifecycle foundation.
 *
 * Responsibilities:
 *   - Keep a proposed login/recovery email separate from the durable verified account identity
 *   - Store only hashed email-change verification secrets
 *   - Bind each request to the account security version and bounded expiry
 *   - Retire all staged requests automatically when the viewer account is deleted
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This migration adds no route, UI, or mail transport.
 *   - Existing viewer content tables remain reference-only foundations.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS viewer_email_change_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        viewer_account_id BIGINT UNSIGNED NOT NULL,
        new_email VARCHAR(190) NOT NULL,
        normalized_new_email VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        selector CHAR(36) NOT NULL,
        verification_token_hash CHAR(64) NOT NULL,
        security_version BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME NULL,
        cancelled_at DATETIME NULL,
        UNIQUE KEY viewer_email_change_selector_unique (selector),
        UNIQUE KEY viewer_email_change_token_hash_unique (verification_token_hash),
        KEY viewer_email_change_account_state_index (viewer_account_id, consumed_at, cancelled_at, expires_at),
        KEY viewer_email_change_target_state_index (normalized_new_email, consumed_at, cancelled_at, expires_at),
        KEY viewer_email_change_expiry_index (expires_at),
        CONSTRAINT viewer_email_change_account_foreign FOREIGN KEY (viewer_account_id) REFERENCES viewer_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
