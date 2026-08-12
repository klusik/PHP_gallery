<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/bootstrap/maintenance.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Owns update and maintenance request triggers that run after request initialization.
 *
 * Responsibilities:
 *   - Support shared project infrastructure
 *   - Keep behavior compatible with existing controllers and services
 *   - Avoid unnecessary coupling to presentation code
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

declare(strict_types=1);

namespace Gallery\Core;

use function Gallery\Services\application_autoupdate_maybe_run;
use function Gallery\Services\admin_log_archive_register_request_trigger;
use function Gallery\Services\site_maintenance_register_request_trigger;

/**
 * Run automatic update and maintenance request triggers in the legacy startup order.
 *
 * @param string $page Resolved page identifier.
 */
function cms_run_request_maintenance(string $page): void
{
    application_autoupdate_maybe_run();
    if (function_exists('Gallery\\Services\\admin_log_archive_register_request_trigger')) {
        admin_log_archive_register_request_trigger($page);
    }
    if (function_exists('Gallery\\Services\\site_maintenance_register_request_trigger')) {
        site_maintenance_register_request_trigger($page);
    }

}