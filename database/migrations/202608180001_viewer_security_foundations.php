<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202608180001_viewer_security_foundations.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds dormant viewer-account, security-token, collection-reference, and abuse-control foundations.
 *
 * Responsibilities:
 *   - Keep viewer identities completely separate from administrator users
 *   - Store authority-bearing viewer secrets only as one-way hashes
 *   - Reference canonical image ids without copying gallery authorization state
 *   - Provide indexed, bounded storage for future viewer sessions, throttling, and cleanup
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
 *   - Viewer authentication must never satisfy administrator authorization.
 *   - A collection reference is not an authorization grant.
 *
 * Last Updated:
 *   2026-08-18
 */

declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS viewer_accounts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        normalized_email VARCHAR(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
        password_hash VARCHAR(255) NULL,
        status ENUM('pending_verification','active','suspended','disabled') NOT NULL DEFAULT 'pending_verification',
        security_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
        email_verified_at DATETIME NULL,
        password_changed_at DATETIME NULL,
        last_login_at DATETIME NULL,
        suspended_at DATETIME NULL,
        disabled_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY viewer_accounts_normalized_email_unique (normalized_email),
        KEY viewer_accounts_status_updated_index (status, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_email_verification_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        viewer_account_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        email_fingerprint CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME NULL,
        invalidated_at DATETIME NULL,
        UNIQUE KEY viewer_email_verification_token_hash_unique (token_hash),
        KEY viewer_email_verification_account_expiry_index (viewer_account_id, expires_at, consumed_at, invalidated_at),
        KEY viewer_email_verification_expiry_index (expires_at),
        CONSTRAINT viewer_email_verification_account_foreign FOREIGN KEY (viewer_account_id) REFERENCES viewer_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_password_reset_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        viewer_account_id BIGINT UNSIGNED NOT NULL,
        token_hash CHAR(64) NOT NULL,
        security_version BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME NULL,
        invalidated_at DATETIME NULL,
        UNIQUE KEY viewer_password_reset_token_hash_unique (token_hash),
        KEY viewer_password_reset_account_expiry_index (viewer_account_id, expires_at, consumed_at, invalidated_at),
        KEY viewer_password_reset_expiry_index (expires_at),
        CONSTRAINT viewer_password_reset_account_foreign FOREIGN KEY (viewer_account_id) REFERENCES viewer_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_remember_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        viewer_account_id BIGINT UNSIGNED NOT NULL,
        selector CHAR(36) NOT NULL,
        verifier_hash CHAR(64) NOT NULL,
        security_version BIGINT UNSIGNED NOT NULL,
        user_agent_hash CHAR(64) NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        UNIQUE KEY viewer_remember_tokens_selector_unique (selector),
        KEY viewer_remember_tokens_account_expiry_index (viewer_account_id, expires_at, revoked_at),
        KEY viewer_remember_tokens_expiry_index (expires_at),
        CONSTRAINT viewer_remember_tokens_account_foreign FOREIGN KEY (viewer_account_id) REFERENCES viewer_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        viewer_account_id BIGINT UNSIGNED NOT NULL,
        session_hash CHAR(64) NOT NULL,
        security_version BIGINT UNSIGNED NOT NULL,
        ip_hash CHAR(64) NULL,
        user_agent_hash CHAR(64) NULL,
        created_at DATETIME NOT NULL,
        last_seen_at DATETIME NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        UNIQUE KEY viewer_sessions_session_hash_unique (session_hash),
        KEY viewer_sessions_account_expiry_index (viewer_account_id, expires_at, revoked_at),
        KEY viewer_sessions_expiry_index (expires_at),
        CONSTRAINT viewer_sessions_account_foreign FOREIGN KEY (viewer_account_id) REFERENCES viewer_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_security_events (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        viewer_account_id BIGINT UNSIGNED NULL,
        event_key VARCHAR(100) NOT NULL,
        outcome VARCHAR(32) NULL,
        ip_hash CHAR(64) NULL,
        user_agent_hash CHAR(64) NULL,
        request_id VARCHAR(64) NULL,
        context_json VARCHAR(2000) NULL,
        created_at DATETIME NOT NULL,
        retention_until DATETIME NOT NULL,
        KEY viewer_security_events_account_created_index (viewer_account_id, created_at),
        KEY viewer_security_events_event_created_index (event_key, created_at),
        KEY viewer_security_events_retention_index (retention_until),
        CONSTRAINT viewer_security_events_account_foreign FOREIGN KEY (viewer_account_id) REFERENCES viewer_accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_rate_limit_buckets (
        bucket VARCHAR(64) NOT NULL PRIMARY KEY,
        entry_count INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_rate_limits (
        bucket VARCHAR(64) NOT NULL,
        subject_hash CHAR(64) NOT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        first_attempt_at DATETIME NOT NULL,
        last_attempt_at DATETIME NOT NULL,
        locked_until DATETIME NULL,
        PRIMARY KEY (bucket, subject_hash),
        KEY viewer_rate_limits_locked_until_index (locked_until),
        KEY viewer_rate_limits_last_attempt_index (last_attempt_at),
        CONSTRAINT viewer_rate_limits_bucket_foreign FOREIGN KEY (bucket) REFERENCES viewer_rate_limit_buckets(bucket) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_favourites (
        viewer_account_id BIGINT UNSIGNED NOT NULL,
        image_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (viewer_account_id, image_id),
        KEY viewer_favourites_image_account_index (image_id, viewer_account_id),
        CONSTRAINT viewer_favourites_account_foreign FOREIGN KEY (viewer_account_id) REFERENCES viewer_accounts(id) ON DELETE CASCADE,
        CONSTRAINT viewer_favourites_image_foreign FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_collections (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        viewer_account_id BIGINT UNSIGNED NOT NULL,
        title VARCHAR(160) NOT NULL,
        description VARCHAR(2000) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY viewer_collections_account_updated_index (viewer_account_id, updated_at, id),
        CONSTRAINT viewer_collections_account_foreign FOREIGN KEY (viewer_account_id) REFERENCES viewer_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_collection_items (
        viewer_collection_id BIGINT UNSIGNED NOT NULL,
        image_id BIGINT UNSIGNED NOT NULL,
        position INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (viewer_collection_id, image_id),
        KEY viewer_collection_items_order_index (viewer_collection_id, position, image_id),
        KEY viewer_collection_items_image_index (image_id),
        CONSTRAINT viewer_collection_items_collection_foreign FOREIGN KEY (viewer_collection_id) REFERENCES viewer_collections(id) ON DELETE CASCADE,
        CONSTRAINT viewer_collection_items_image_foreign FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_collection_share_tokens (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        viewer_collection_id BIGINT UNSIGNED NOT NULL,
        created_by_viewer_account_id BIGINT UNSIGNED NULL,
        token_hash CHAR(64) NOT NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        expires_at DATETIME NULL,
        revoked_at DATETIME NULL,
        UNIQUE KEY viewer_collection_share_token_hash_unique (token_hash),
        KEY viewer_collection_share_collection_state_index (viewer_collection_id, revoked_at, expires_at),
        KEY viewer_collection_share_creator_state_index (created_by_viewer_account_id, revoked_at, expires_at),
        KEY viewer_collection_share_expiry_index (expires_at),
        CONSTRAINT viewer_collection_share_collection_foreign FOREIGN KEY (viewer_collection_id) REFERENCES viewer_collections(id) ON DELETE CASCADE,
        CONSTRAINT viewer_collection_share_creator_foreign FOREIGN KEY (created_by_viewer_account_id) REFERENCES viewer_accounts(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS viewer_passkeys (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        viewer_account_id BIGINT UNSIGNED NOT NULL,
        credential_id VARBINARY(1024) NOT NULL,
        credential_id_hash CHAR(64) NOT NULL,
        public_key TEXT NOT NULL,
        sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
        transports VARCHAR(255) NULL,
        aaguid CHAR(36) NULL,
        friendly_name VARCHAR(100) NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY viewer_passkeys_credential_hash_unique (credential_id_hash),
        KEY viewer_passkeys_account_created_index (viewer_account_id, created_at),
        CONSTRAINT viewer_passkeys_account_foreign FOREIGN KEY (viewer_account_id) REFERENCES viewer_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
