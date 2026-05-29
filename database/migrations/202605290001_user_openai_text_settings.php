<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605290001_user_openai_text_settings.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds user-level OpenAI text-assistance settings.
 *
 * Responsibilities:
 *   - Store whether a user enabled OpenAI text assistance
 *   - Store the encrypted OpenAI API key payload separately from normal profile data
 *   - Store a privacy-safe key hint and preferred model id for profile display
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
 *   2026-05-29
 */

return [
    "CREATE TABLE IF NOT EXISTS user_openai_text_settings (
        user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        api_key_cipher MEDIUMTEXT NULL,
        api_key_hint VARCHAR(32) NULL,
        model VARCHAR(120) NOT NULL DEFAULT 'gpt-5.4-mini',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY user_openai_text_settings_enabled_index (enabled),
        CONSTRAINT user_openai_text_settings_user_id_foreign FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
