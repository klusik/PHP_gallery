<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: database/migrations/202611040002_app_linking_push_requests.php
 * Module Type: Database Migration
 * Purpose: Store bounded, short-lived browser linking requests.
 * Responsibilities:
 *   - Retain only claim-code hashes and authenticated ciphertext for state.
 *   - Support atomic claim and bounded, serialized storage cleanup.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

return [
    "CREATE TABLE IF NOT EXISTS app_link_request_capacity (
        id TINYINT UNSIGNED NOT NULL PRIMARY KEY
    ) ENGINE=InnoDB",
    "CREATE TABLE IF NOT EXISTS app_link_requests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        claim_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        issuer VARBINARY(512) NOT NULL,
        client_id VARBINARY(64) NOT NULL,
        scope VARBINARY(64) NOT NULL,
        redirect_uri VARBINARY(512) NOT NULL,
        pkce_s256 CHAR(43) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        state_nonce BINARY(12) NOT NULL,
        state_cipher VARBINARY(1024) NOT NULL,
        state_tag BINARY(16) NOT NULL,
        created_at DATETIME(6) NOT NULL,
        expires_at DATETIME(6) NOT NULL,
        claimed_at DATETIME(6) NULL,
        claimed_user_id BIGINT UNSIGNED NULL,
        session_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
        consent_consumed_at DATETIME(6) NULL,
        UNIQUE KEY app_link_requests_claim (claim_hash),
        KEY app_link_requests_expiry (expires_at, id)
    ) ENGINE=InnoDB",
];
