<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605280001_ai_image_analysis_queue.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds a server-owned queue for client-side AI image analysis.
 *
 * Responsibilities:
 *   - Store one row per image analysis job and its lease state
 *   - Store generated internal metadata separately from human descriptions
 *   - Preserve model, source checksum, source timestamp, and retry diagnostics
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
 *   2026-05-28
 */

return [
    "CREATE TABLE IF NOT EXISTS image_ai_metadata (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        image_id BIGINT UNSIGNED NOT NULL,
        model_name VARCHAR(120) NOT NULL,
        model_version VARCHAR(120) NOT NULL,
        source_checksum_sha256 CHAR(64) NULL,
        source_file_size BIGINT UNSIGNED NULL,
        source_modified_at DATETIME NULL,
        metadata_json MEDIUMTEXT NOT NULL,
        searchable_text MEDIUMTEXT NULL,
        generated_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY image_ai_metadata_image_model_unique (image_id, model_name, model_version),
        KEY image_ai_metadata_model_index (model_name, model_version, generated_at),
        KEY image_ai_metadata_source_index (image_id, source_checksum_sha256, source_file_size, source_modified_at),
        CONSTRAINT image_ai_metadata_image_id_foreign FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS image_ai_analysis_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        gallery_id BIGINT UNSIGNED NOT NULL,
        image_id BIGINT UNSIGNED NOT NULL,
        job_key CHAR(64) NOT NULL,
        model_name VARCHAR(120) NOT NULL,
        model_version VARCHAR(120) NOT NULL,
        source_checksum_sha256 CHAR(64) NULL,
        source_file_size BIGINT UNSIGNED NULL,
        source_modified_at DATETIME NULL,
        state ENUM('queued','claimed','succeeded','failed','cancelled') NOT NULL DEFAULT 'queued',
        claim_owner VARCHAR(190) NULL,
        claim_token_hash CHAR(64) NULL,
        claim_expires_at DATETIME NULL,
        claimed_at DATETIME NULL,
        heartbeat_at DATETIME NULL,
        progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
        progress_message VARCHAR(500) NULL,
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
        available_at DATETIME NULL,
        completed_at DATETIME NULL,
        last_error TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY image_ai_jobs_job_key_unique (job_key),
        KEY image_ai_jobs_claim_index (gallery_id, model_name, model_version, state, available_at, attempt_count, created_at),
        KEY image_ai_jobs_image_source_index (image_id, model_name, model_version, source_checksum_sha256, source_file_size, source_modified_at),
        KEY image_ai_jobs_claim_owner_index (claim_owner, claim_expires_at),
        CONSTRAINT image_ai_jobs_gallery_id_foreign FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
        CONSTRAINT image_ai_jobs_image_id_foreign FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
