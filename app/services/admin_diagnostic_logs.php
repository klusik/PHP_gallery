<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_diagnostic_logs.php
 * Module Type: Service
 *
 * Purpose:
 *   Provides an independent best-effort Admin log path for diagnostics that must survive normal logger failures.
 *
 * Responsibilities:
 *   - Normalize diagnostic log level/category/severity values
 *   - Build a schema-tolerant semantic log row
 *   - Delegate direct persistence to the Admin log model
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
 *   - HTTP-derived values are supplied explicitly by the controller.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Services;

use function Gallery\Core\now_sql;
use function Gallery\Models\admin_log_model_insert_available;

/**
 * Persist one diagnostic Admin log entry independently of admin_log_event().
 *
 * @param string $level Log level.
 * @param string $eventKey Event key.
 * @param string $message Human-readable message.
 * @param array<string,mixed> $context Structured context.
 * @param array<string,mixed> $options Log options.
 * @param array<string,mixed> $requestContext Explicit actor/request metadata from the HTTP boundary.
 * @return bool True when the direct insert succeeded.
 */
function admin_diagnostic_log_write(string $level, string $eventKey, string $message, array $context = [], array $options = [], array $requestContext = []): bool
{
    $safeLevel = in_array($level, ['info', 'warning', 'error'], true) ? $level : 'error';
    $severity = (string) ($options['severity'] ?? $safeLevel);
    if (!in_array($severity, ['debug', 'info', 'notice', 'warning', 'error', 'critical'], true)) {
        $severity = $safeLevel;
    }
    if (in_array($severity, ['error', 'critical'], true)) {
        $safeLevel = 'error';
    } elseif ($severity === 'warning') {
        $safeLevel = 'warning';
    }

    $category = (string) ($options['category'] ?? 'media');
    if (!in_array($category, ['system', 'gallery', 'media', 'upload', 'thumbnail', 'update', 'security', 'database', 'telemetry', 'admin', 'other'], true)) {
        $category = 'media';
    }

    return admin_log_model_insert_available([
        'user_id' => isset($requestContext['user_id']) ? (int) $requestContext['user_id'] : null,
        'level' => $safeLevel,
        'category' => $category,
        'severity' => $severity,
        'event_key' => substr($eventKey, 0, 160),
        'message' => substr($message, 0, 1000),
        'subject_type' => (string) ($options['subject_type'] ?? 'media_renamer'),
        'subject_id' => isset($options['subject_id']) ? (int) $options['subject_id'] : null,
        'request_id' => $requestContext['request_id'] ?? null,
        'route_name' => substr((string) ($options['route_name'] ?? ($requestContext['route_name'] ?? '')), 0, 80),
        'fingerprint' => hash('sha256', $eventKey . '|' . $message . '|' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'http_method' => substr((string) ($requestContext['http_method'] ?? ''), 0, 12),
        'is_ajax' => !empty($requestContext['is_ajax']) ? 1 : 0,
        'context_json' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'created_at' => now_sql(),
    ]);
}
