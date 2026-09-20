<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/maintenance_center.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Provides the persisted, browser-driven Maintenance Center orchestrator.
 *
 * Responsibilities:
 *   - Define shared Maintenance Center constants and JSON/state helpers
 *   - Load the canonical task registry, read-only analysis, execution, and presentation modules
 *   - Keep central maintenance orchestration reusable outside HTTP controllers
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
 *   - Controllers own HTTP/auth/CSRF; SQL remains model-owned.
 *   - Task part files are loaded only by this module entry point.
 *
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace Gallery\Services;

use JsonException;
use RuntimeException;

/** Registry revision bound into every analyzed plan. */
const MAINTENANCE_CENTER_REGISTRY_REVISION = '2026-09-20.1';
/** Terminal job history retained by normal maintenance. */
const MAINTENANCE_CENTER_HISTORY_RETENTION_DAYS = 90;
/** Conservative web-maintenance classification threshold for one physical DB table operation. */
const MAINTENANCE_CENTER_LARGE_TABLE_BYTES = 268435456;
/** Minimum DATA_FREE signal used for the normal recommended OPTIMIZE set. */
const MAINTENANCE_CENTER_RECOMMEND_RECLAIM_BYTES = 1048576;
/** Logical database cleanup batch size per browser request. */
const MAINTENANCE_CENTER_DB_CLEANUP_BATCH = 250;
/** Maximum recent activity entries persisted in one job row. */
const MAINTENANCE_CENTER_ACTIVITY_LIMIT = 80;

/** Encode bounded Maintenance Center persistence JSON with stable failure semantics. */
function maintenance_center_json_encode(array $value): string
{
    try {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Maintenance Center state could not be encoded.', 0, $exception);
    }
}

/** Decode one persisted Maintenance Center JSON object. */
function maintenance_center_json_decode(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }
    try {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Maintenance Center persisted state is invalid.', 0, $exception);
    }
    if (!is_array($decoded)) {
        throw new RuntimeException('Maintenance Center persisted state is not an object.');
    }
    return $decoded;
}

/** Return the current application version used for stale-plan protection. */
function maintenance_center_app_version(): string
{
    $constant = 'Gallery\\Core\\CMS_VERSION';
    return defined($constant) ? (string) constant($constant) : 'unknown';
}

/** Return a monotonic bounded percentage. */
function maintenance_center_monotonic_percent(float $previous, int $done, int $total): float
{
    $calculated = $total > 0 ? min(100.0, max(0.0, ($done / $total) * 100.0)) : ($done > 0 ? 100.0 : 0.0);
    return round(max($previous, $calculated), 2);
}

/** Resolve one persisted/runtime Maintenance Center message through the active Admin language. */
function maintenance_center_runtime_text(string $key, string $fallback, array $parameters = []): string
{
    if (function_exists(__NAMESPACE__ . '\\t')) {
        return t($key, $fallback, $parameters);
    }
    foreach ($parameters as $name => $value) {
        $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
    }
    return $fallback;
}

/** Append one bounded recent-activity entry to persisted state. */
function maintenance_center_activity(array &$state, string $level, string $message, array $context = []): void
{
    $entries = is_array($state['activity'] ?? null) ? $state['activity'] : [];
    $entries[] = [
        'at' => date('Y-m-d H:i:s'),
        'level' => in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info',
        'message' => mb_substr(trim($message), 0, 220),
        'context' => array_slice($context, 0, 8, true),
    ];
    if (count($entries) > MAINTENANCE_CENTER_ACTIVITY_LIMIT) {
        $entries = array_slice($entries, -MAINTENANCE_CENTER_ACTIVITY_LIMIT);
    }
    $state['activity'] = $entries;
}

/** Add a unique bounded warning code/message to persisted state. */
function maintenance_center_warning(array &$state, string $code, string $message): void
{
    $warnings = is_array($state['warnings'] ?? null) ? $state['warnings'] : [];
    foreach ($warnings as $warning) {
        if (is_array($warning) && (string) ($warning['code'] ?? '') === $code) {
            return;
        }
    }
    $warnings[] = ['code' => mb_substr($code, 0, 64), 'message' => mb_substr(trim($message), 0, 255)];
    $state['warnings'] = array_slice($warnings, -40);
}

/** Record a bounded service-level error without persisting exception traces. */
function maintenance_center_error(array &$state, string $code, string $message): void
{
    $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];
    $errors[] = ['code' => mb_substr($code, 0, 64), 'message' => mb_substr(trim($message), 0, 255)];
    $state['errors'] = array_slice($errors, -20);
}

require_once __DIR__ . '/maintenance_center/registry.php';
require_once __DIR__ . '/maintenance_center/analysis.php';
require_once __DIR__ . '/maintenance_center/execution.php';
require_once __DIR__ . '/maintenance_center/status.php';
