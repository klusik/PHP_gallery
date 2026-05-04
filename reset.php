<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: reset.php
 * Module Type: Setup Utility
 *
 * Purpose:
 *   Provides setup, installation, or recovery functionality for PHP Gallery.
 *
 * Responsibilities:
 *   - Guide setup or recovery workflow
 *   - Validate configuration before writing changes
 *   - Avoid exposing sensitive details to public users
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
 *   2026-05-04
 */

/* Simple reset script */
declare(strict_types=1);

$_GET['page'] = 'admin_reset';
require __DIR__ . '/public/index.php';
