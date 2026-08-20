<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608200001_viewer_registration_verification_tokens.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds bounded sibling verification authority for explicit viewer registration resend.
 *
 * Responsibilities:
 *   - Preserve the existing primary verification token stored on viewer_registration_requests
 *   - Allow multiple temporarily valid hashed verification authorities for one pending request
 *   - Record successful mail handoff separately from authority creation
 *   - Cascade resend-token cleanup when the owning staged registration is retired
 *   - Keep token lookup and per-request cleanup indexed
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
 *   - Plaintext verification capabilities are never persisted.
 *   - The existing viewer_registration_requests verification columns remain authoritative for historical Phase 4.1 links.
 *
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS viewer_registration_verification_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        viewer_registration_request_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        sent_at DATETIME NULL,
        UNIQUE KEY viewer_registration_verification_tokens_hash_unique (token_hash),
        KEY viewer_registration_verification_tokens_request_index (viewer_registration_request_id, id),
        KEY viewer_registration_verification_tokens_expiry_index (expires_at),
        CONSTRAINT viewer_registration_verification_tokens_request_foreign FOREIGN KEY (viewer_registration_request_id) REFERENCES viewer_registration_requests(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
