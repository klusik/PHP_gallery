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
 *   2026-09-05
 */

declare(strict_types=1);

namespace Gallery\Core;

/**
 * Identify the installed application release for compatibility and update checks.
 *
 * @var string
 * Units: dotted release identifier. Scope: installation-wide application identity.
 * Consumers: runtime diagnostics, release checks, updater compatibility, public assets and documentation.
 * Rationale: keep one canonical machine-readable version synchronized by the release preparation workflow.
 */
const CMS_VERSION = '0.119';
const CMS_GITHUB_REPOSITORY = 'klusik/PHP_gallery';
const CMS_UPDATE_BRANCHES = ['main', 'master'];

require_once __DIR__ . '/runtime/autoload.php';
require_once __DIR__ . '/runtime/bridge.php';

function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('configuration'); require __DIR__ . '/bootstrap/configuration.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('configuration');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('helpers'); require __DIR__ . '/helpers.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('helpers');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('database'); require __DIR__ . '/database.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('database');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('security'); require __DIR__ . '/security.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('security');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('routing_bootstrap'); require __DIR__ . '/bootstrap/routing.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('routing_bootstrap');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('session_bootstrap'); require __DIR__ . '/bootstrap/session.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('session_bootstrap');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('request_bootstrap'); require __DIR__ . '/bootstrap/request.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('request_bootstrap');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('maintenance_bootstrap'); require __DIR__ . '/bootstrap/maintenance.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('maintenance_bootstrap');
function_exists('Gallery\Diagnostics\admin_test_run_early_phase_start') && \Gallery\Diagnostics\admin_test_run_early_phase_start('dispatch_bootstrap'); require __DIR__ . '/bootstrap/dispatch.php'; function_exists('Gallery\Diagnostics\admin_test_run_early_phase_end') && \Gallery\Diagnostics\admin_test_run_early_phase_end('dispatch_bootstrap');

/**
 * Start the session, resolve the requested route, and dispatch to a controller.
 *
 * The project intentionally uses a small route table instead of a framework so
 * it remains easy to run on shared hosting.
 * @return void Delegates the request lifecycle to the request-local runtime kernel.
 */
function cms_run(): void
{
    cms_runtime_kernel()->run();
}
