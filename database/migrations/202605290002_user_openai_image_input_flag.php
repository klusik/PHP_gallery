<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: database/migrations/202605290002_user_openai_image_input_flag.php
 * Module Type: Database Migration
 *
 * Purpose:
 *   Adds an optional user-level consent flag for sending generated thumbnails to OpenAI.
 *
 * Responsibilities:
 *   - Store whether the user allows small generated thumbnails to be sent to OpenAI
 *   - Keep the setting separate from the text-assistance enable toggle
 *   - Preserve a default-off posture for image input
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
    "ALTER TABLE user_openai_text_settings ADD COLUMN allow_image_input TINYINT(1) NOT NULL DEFAULT 0 AFTER model",
];
