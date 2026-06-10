<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views.php
 * Module Type: View Loader
 *
 * Purpose:
 *   Loads presentation modules after services have prepared the domain helpers
 *   and before controllers start handling requests.
 *
 * Responsibilities:
 *   - Keep view rendering code out of controllers and services
 *   - Preserve the existing function-based application style
 *   - Provide a single include point for template and presenter helpers
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
 *   2026-05-24
 */

declare(strict_types=1);

require_once __DIR__ . '/views/seo.php';
require_once __DIR__ . '/views/admin_chrome.php';
require_once __DIR__ . '/views/admin_ui.php';
require_once __DIR__ . '/views/admin_storage_statistics.php';
require_once __DIR__ . '/views/admin_upload_settings.php';
require_once __DIR__ . '/views/admin_database_usage.php';
require_once __DIR__ . '/views/admin_dashboard_sections.php';
require_once __DIR__ . '/views/admin_dashboard.php';
require_once __DIR__ . '/views/navigation_data.php';
require_once __DIR__ . '/views/admin_gallery_forms.php';
require_once __DIR__ . '/views/admin_gallery_migration.php';
require_once __DIR__ . '/views/gallery_descriptions.php';
require_once __DIR__ . '/views/simbrief_descriptions.php';
require_once __DIR__ . '/views/layout.php';
