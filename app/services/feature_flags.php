<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/feature_flags.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides global feature visibility and availability switches.
 *
 * Responsibilities:
 *   - Expose the canonical optional-capability registry through one stable module entry point
 *   - Delegate persisted configured state to feature-flag, app-setting, or established domain adapters
 *   - Provide dependency-aware effective state plus centralized route and UI guards
 *   - Preserve compatibility wrappers for established feature-flag callers
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
 *   - Implementation lives in app/services/feature_flags/; this file is the module entry point.
 *   - The require_once list preserves the historical app/services.php include contract.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-08-19
 */

declare(strict_types=1);

namespace Gallery\Services;

const FEATURE_FLAG_SETTING_PREFIX = 'feature_flag.';
const FEATURE_FLAG_SETTING_SUFFIX = '.enabled';

// This module is split into focused part files under app/services/feature_flags/.
// Canonical definitions and legacy registry compatibility.
require_once __DIR__ . '/feature_flags/registry.php';
// Lazy persistence adapters for existing domain-owned settings and app_settings values.
require_once __DIR__ . '/feature_flags/adapters.php';
// Configured/effective state, dependencies, validation, and legacy state wrappers.
require_once __DIR__ . '/feature_flags/policy.php';
// Admin summary, persistence orchestration, and grouped presentation helpers.
require_once __DIR__ . '/feature_flags/admin.php';
// Central route ownership, effective route gating, and disabled-route rendering.
require_once __DIR__ . '/feature_flags/routes.php';
