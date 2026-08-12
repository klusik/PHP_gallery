<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/bootstrap.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Provides core bootstrap, configuration, helper, security, database, or routing functionality.
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

const CMS_VERSION = '0.88';
const CMS_GITHUB_REPOSITORY = 'klusik/PHP_gallery';
const CMS_UPDATE_BRANCHES = ['main', 'master'];

require __DIR__ . '/bootstrap/configuration.php';
require __DIR__ . '/helpers.php';
require __DIR__ . '/database.php';
require __DIR__ . '/security.php';
require __DIR__ . '/migrations.php';
require __DIR__ . '/services.php';
require __DIR__ . '/views.php';
require __DIR__ . '/integrity.php';
require __DIR__ . '/controllers.php';
require __DIR__ . '/bootstrap/routing.php';
require __DIR__ . '/bootstrap/session.php';
require __DIR__ . '/bootstrap/request.php';
require __DIR__ . '/bootstrap/maintenance.php';
require __DIR__ . '/bootstrap/dispatch.php';

/**
 * Start the session, resolve the requested route, and dispatch to a controller.
 *
 * The project intentionally uses a small route table instead of a framework so
 * it remains easy to run on shared hosting.
 */
function cms_run(): void
{
    if (!cms_has_config()) {
        cms_redirect_to_installer();
    }

    // Variable $config stores this steps working value.
    $config = cms_config();
    cms_start_session($config);
    $page = cms_initialize_request();
    cms_run_request_maintenance($page);
    cms_dispatch_page($page);
}
