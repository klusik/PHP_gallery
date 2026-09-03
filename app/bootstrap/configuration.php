<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/bootstrap/configuration.php
 * Module Type: Core Module
 *
 * Purpose:
 *   Owns config-file discovery, installer redirection, and per-request config loading.
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
 *   2026-09-03
 */

declare(strict_types=1);

namespace Gallery\Core;


/**
 * Return the expected application config path.
 *
 * @return string Text result for the caller.
 */
function cms_config_path(): string
{
    return dirname(__DIR__, 2) . '/config.php';
}

/**
 * Return true when the application has a real local configuration file.
 *
 * @return bool True when the condition matches.
 */
function cms_has_config(): bool
{
    return is_file(cms_config_path());
}

/**
 * Send first-run browser requests to the one-time installer.
 */
function cms_redirect_to_installer(): void
{
    // $base stores an intermediate value used by the surrounding gallery workflow.
    $base = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($base === '/public') {
        // $base stores an intermediate value used by the surrounding gallery workflow.
        $base = '';
    } elseif (str_ends_with($base, '/public')) {
        // $base stores an intermediate value used by the surrounding gallery workflow.
        $base = substr($base, 0, -7);
    }
    // $target stores an intermediate value used by the surrounding gallery workflow.
    $target = ($base === '' ? '' : $base) . '/install.php';
    header('Location: ' . ($target === '/install.php' ? 'install.php' : $target));
    exit;
}

/**
 * Load the application configuration once per request.
 *
 * Production installs should provide config.php. The example config remains a
 * fallback for manual tooling, while browser requests without config.php are
 * redirected to the one-time installer before this function is called.
 *
 * @return array Structured result data for the caller.
 */
function cms_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    // Variable $configFile stores this steps working value.
    $configFile = cms_config_path();
    if (!is_file($configFile)) {
        // Variable $configFile stores this steps working value.
        $configFile = dirname(__DIR__, 2) . '/config.example.php';
    }

    // Central operational defaults are merged first so existing config.php files
    // automatically receive newly introduced safe limits after an update. Local
    // configuration wins only for explicitly overridden keys.
    $defaults = require dirname(__DIR__) . '/configuration_defaults.php';
    $loaded = require $configFile;
    if (!is_array($loaded)) {
        $loaded = [];
    }

    // Variable $config stores this steps working value.
    $config = array_replace_recursive($defaults, $loaded);
    return $config;
}

/**
 * Return one centralized operational runtime limit.
 *
 * Runtime limits are deliberately addressed by stable dotted keys so feature
 * modules never redeclare numeric policy defaults locally.
 *
 * @return int|float Numeric runtime-limit value.
 */
function cms_runtime_limit(string $key): int|float
{
    $config = cms_config();
    $limits = is_array($config['runtime_limits'] ?? null) ? $config['runtime_limits'] : [];
    if (!array_key_exists($key, $limits) || !is_numeric($limits[$key])) {
        $defaults = require dirname(__DIR__) . '/configuration_defaults.php';
        $defaultLimits = is_array($defaults['runtime_limits'] ?? null) ? $defaults['runtime_limits'] : [];
        if (!array_key_exists($key, $defaultLimits) || !is_numeric($defaultLimits[$key])) {
            throw new \InvalidArgumentException('Unknown runtime limit: ' . $key);
        }
        return $defaultLimits[$key] + 0;
    }
    return $limits[$key] + 0;
}
