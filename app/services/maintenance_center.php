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

require_once __DIR__ . '/cache_lifecycle_storage.php';

use JsonException;
use RuntimeException;
use Throwable;

/** Registry revision bound into every analyzed plan. */
/**
 * Invalidate plans predating bounded music catalog orphan cleanup.
 * Type: string. Units: registry revision identifier. Scope: Maintenance Center plans.
 * Consumers: plan freshness and execution admission.
 * Rationale: require fresh approval before the music task removes proven
 *     unbacked logical song records after preserving existing local originals.
 */
const MAINTENANCE_CENTER_REGISTRY_REVISION = '2026-10-24.music-orphans';

/** Bound diagnostic identifiers without retaining raw exception messages.
 * Type: int. Units: ASCII characters. Scope: one maintenance failure event.
 * Consumers: maintenance_center_exception_diagnostic class, symbol and site.
 * Rationale: 160 retains useful PHP identifiers within the lifecycle log cap.
 * Value: 160 characters per safe identifier.
 */
const MAINTENANCE_CENTER_DIAGNOSTIC_IDENTIFIER_LIMIT = 160;

/** Bound the stable reason vocabulary used by maintenance failure events.
 * Type: int. Units: ASCII characters. Scope: one maintenance failure code.
 * Consumers: maintenance_center_exception_diagnostic reason validation.
 * Rationale: 80 preserves the existing bounded reason contract for log readers.
 * Value: 80 characters per machine-readable failure code.
 */
const MAINTENANCE_CENTER_DIAGNOSTIC_CODE_LIMIT = 80;

/**
 * Reserve a small progress weight for the explicitly selected music move.
 * Type: int. Units: relative plan work units. Scope: one maintenance plan.
 * Consumers: music.repository registry and read-only analyzer.
 * Rationale: five units retain visible progress without claiming a file count
 *     before the bounded source discovery has run. Value: 5 work units.
 */
const MAINTENANCE_CENTER_MUSIC_WEIGHT = 5;

/** Reserve progress weight for the bounded media-usage reconciliation.
 * Type: int. Units: relative plan work units. Scope: one maintenance plan.
 * Consumers: media.attachment_usage registry and read-only analyzer.
 * Rationale: 25 is the worker's per-step gallery bound, not a gallery count.
 * Value: 25 work units.
 */
const MAINTENANCE_CENTER_ATTACHMENT_USAGE_WEIGHT = 25;

/**
 * Bound cache lifecycle work before yielding to unrelated central tasks.
 * Type: int. Units: browser slices. Scope: one central maintenance job.
 * Consumers: download/cache execution and its persisted continuation policy.
 * Rationale: 32 short slices allow useful progress without waiting indefinitely
 *     for rotating regions or retry backoff. Durable cache cursors remain owned
 *     by the cache worker and continue on later maintenance opportunities.
 */
const MAINTENANCE_CENTER_CACHE_LIFECYCLE_SLICES = 32;

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

/**
 * Encode a bounded Maintenance Center state object with stable failure semantics.
 *
 * @param array<string,mixed> $value JSON-compatible state fields.
 * @return string UTF-8 JSON encoding; throws RuntimeException when encoding fails.
 */
function maintenance_center_json_encode(array $value): string
{
    try {
        return json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_THROW_ON_ERROR,
        );
    } catch (JsonException $exception) {
        throw new RuntimeException(
            'Maintenance Center state could not be encoded.',
            0,
            $exception,
        );
    }
}

/**
 * Decode one persisted Maintenance Center state object.
 *
 * @param ?string $value Persisted JSON object, or null for no saved state.
 * @return array<string,mixed> Decoded state fields, or an empty array for absent state; throws RuntimeException for invalid JSON or non-object data.
 */
function maintenance_center_json_decode(?string $value): array
{
    if ($value === null || trim($value) === '') {
        return [];
    }
    try {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException(
            'Maintenance Center persisted state is invalid.',
            0,
            $exception,
        );
    }
    if (!is_array($decoded)) {
        throw new RuntimeException(
            'Maintenance Center persisted state is not an object.',
        );
    }
    return $decoded;
}

/** Return the current application version used for stale-plan protection. */
function maintenance_center_app_version(): string
{
    $constant = 'Gallery\\Core\\CMS_VERSION';
    return defined($constant) ? (string) constant($constant) : 'unknown';
}

/**
 * Return a monotonic percentage between zero and one hundred.
 *
 * @param float $previous Previously reported percentage.
 * @param int $done Completed work units.
 * @param int $total Total work units.
 * @return float Rounded percentage that never decreases below the previous value.
 */
function maintenance_center_monotonic_percent(
    float $previous,
    int $done,
    int $total,
): float {
    $calculated = $total > 0
        ? min(100.0, max(0.0, ($done / $total) * 100.0))
        : ($done > 0 ? 100.0 : 0.0);
    return round(max($previous, $calculated), 2);
}

/**
 * Resolve one persisted/runtime message through the active Admin language.
 *
 * @param string $key Translation key.
 * @param string $fallback English fallback text.
 * @param array<string,string|int|float> $parameters Scalar placeholder values.
 * @return string Localized message, or the interpolated fallback when translation is unavailable.
 */
function maintenance_center_runtime_text(
    string $key,
    string $fallback,
    array $parameters = [],
): string {
    if (function_exists(__NAMESPACE__ . '\\t')) {
        return t($key, $fallback, $parameters);
    }
    foreach ($parameters as $name => $value) {
        $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
    }
    return $fallback;
}

/**
 * Append one bounded recent-activity entry to persisted state.
 *
 * @param array<string,mixed> $state Mutable maintenance state.
 * @param string $level Activity severity label.
 * @param string $message Human-readable activity text.
 * @param array<string,mixed> $context Structured activity details.
 * @return void Appends the activity entry and applies the configured history limit.
 */
function maintenance_center_activity(
    array &$state,
    string $level,
    string $message,
    array $context = [],
): void {
    $entries = is_array($state['activity'] ?? null) ? $state['activity'] : [];
    $entries[] = [
        'at' => date('Y-m-d H:i:s'),
        'level' => in_array($level, ['info', 'warning', 'error'], true)
            ? $level
            : 'info',
        'message' => mb_substr(trim($message), 0, 220),
        'context' => array_slice($context, 0, 8, true),
    ];
    if (count($entries) > MAINTENANCE_CENTER_ACTIVITY_LIMIT) {
        $entries = array_slice($entries, -MAINTENANCE_CENTER_ACTIVITY_LIMIT);
    }
    $state['activity'] = $entries;
}

/**
 * Add a unique bounded warning code/message to persisted state.
 *
 * @param array<string,mixed> $state Mutable maintenance state.
 * @param string $code Stable warning identifier.
 * @param string $message Human-readable warning text.
 * @return void Adds the warning unless its code is already present.
 */
function maintenance_center_warning(
    array &$state,
    string $code,
    string $message,
): void {
    $warnings = is_array($state['warnings'] ?? null) ? $state['warnings'] : [];
    foreach ($warnings as $warning) {
        if (is_array($warning) && (string) ($warning['code'] ?? '') === $code) {
            return;
        }
    }
    $warnings[] = [
        'code' => mb_substr($code, 0, 64),
        'message' => mb_substr(trim($message), 0, 255),
    ];
    $state['warnings'] = array_slice($warnings, -40);
}

/**
 * Record a bounded service-level error without persisting exception traces.
 *
 * @param array<string,mixed> $state Mutable maintenance state.
 * @param string $code Stable error identifier.
 * @param string $message Human-readable error text.
 * @return void Appends the error and enforces the persisted history limit.
 */
function maintenance_center_error(
    array &$state,
    string $code,
    string $message,
): void {
    $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];
    $errors[] = [
        'code' => mb_substr($code, 0, 64),
        'message' => mb_substr(trim($message), 0, 255),
    ];
    $state['errors'] = array_slice($errors, -20);
}

/**
 * Return bounded machine-readable exception diagnostics for Admin maintenance logs.
 *
 * Raw exception messages and traces are intentionally excluded because PDO and
 * filesystem exceptions may contain SQL, private paths, or hosting details.
 * Application exceptions exposing a stable reason() contract may contribute that
 * already-bounded reason without parsing localized text.
 *
 * @param Throwable $exception Failure whose bounded provenance is recorded.
 * @return array{exception_class:string,error_code:string,error_site:string,error_line:int,error_symbol?:string} Safe class, reason, source basename/line and an optional missing PHP identifier.
 */
function maintenance_center_exception_diagnostic(Throwable $exception): array
{
    $errorCode = '';
    if (method_exists($exception, 'reason')) {
        try {
            $candidate = $exception->reason();
            if (
                is_string($candidate)
                && strlen($candidate) <= MAINTENANCE_CENTER_DIAGNOSTIC_CODE_LIMIT
                && preg_match('/^[a-z0-9_.-]+$/D', $candidate) === 1
            ) {
                $errorCode = $candidate;
            }
        } catch (Throwable) {
            $errorCode = '';
        }
    }

    if ($errorCode === '') {
        $errorCode = match (true) {
            $exception instanceof \PDOException => 'database_exception',
            $exception instanceof \UnexpectedValueException
                => 'filesystem_iterator_exception',
            $exception instanceof \JsonException => 'json_exception',
            $exception instanceof \RuntimeException => 'runtime_exception',
            $exception instanceof \Error => 'php_error',
            default => 'unclassified_exception',
        };
    }

    $diagnostic = [
        'exception_class' => substr(
            get_class($exception),
            0,
            MAINTENANCE_CENTER_DIAGNOSTIC_IDENTIFIER_LIMIT,
        ),
        'error_code' => substr(
            $errorCode,
            0,
            MAINTENANCE_CENTER_DIAGNOSTIC_CODE_LIMIT,
        ),
        'error_site' => substr(
            preg_replace(
                '/[^A-Za-z0-9_.-]/',
                '',
                basename(str_replace('\\', '/', $exception->getFile())),
            ) ?? 'unknown',
            0,
            MAINTENANCE_CENTER_DIAGNOSTIC_IDENTIFIER_LIMIT,
        ),
        'error_line' => max(0, $exception->getLine()),
    ];
    // Only engine-shaped missing identifiers are safe to retain. Arbitrary
    // runtime/PDO messages may contain private paths, values or credentials.
    if ($exception instanceof \Error && preg_match(
        '~^(?:Call to undefined function ([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)\(\)|Class "([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)" not found|Undefined constant "([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)")$~D',
        $exception->getMessage(),
        $matches,
    ) === 1) {
        foreach (array_slice($matches, 1) as $symbol) {
            if ($symbol !== '') {
                $diagnostic['error_symbol'] = substr(
                    $symbol,
                    0,
                    MAINTENANCE_CENTER_DIAGNOSTIC_IDENTIFIER_LIMIT,
                );
                break;
            }
        }
    }
    return $diagnostic;
}

/**
 * Return reviewed deferred runtime modules required by one Maintenance Center task.
 *
 * Analysis and execution run as separate browser requests, so heavy subsystem
 * owners must be loaded again for the current task. The mapping intentionally
 * reuses existing logical runtime modules instead of bypassing selective loading
 * through legacy models.php/services.php umbrella loaders.
 *
 * @param string $taskKey Stable Maintenance Center task key.
 * @param string $phase Current task phase: analysis or execution.
 * @return list<string> Logical runtime module identifiers to load before the task callback.
 */
function maintenance_center_task_runtime_modules(
    string $taskKey,
    string $phase,
): array {
    if (!in_array($phase, ['analysis', 'execution'], true)) {
        return [];
    }

    if ($taskKey === 'preflight') {
        return $phase === 'analysis' ? ['site-maintenance-work'] : [];
    }
    if ($taskKey === 'music.repository') {
        // The complete existing attachment owner supplies both the passive
        // readiness reader and the explicitly approved original move worker.
        return ['gallery-pdf-documents'];
    }
    if ($taskKey === 'media.attachment_usage') {
        return ['gallery-pdf-documents'];
    }
    if (in_array(
        $taskKey,
        [
            'trash.reconcile',
            'telemetry.retention',
            'trash.retention',
            'security.cleanup',
            'downloads.cache',
            'thumbnail.metadata',
        ],
        true,
    )) {
        return ['site-maintenance-work'];
    }
    if ($taskKey === 'media.deep_verify') {
        return ['domain-admin-thumbnails'];
    }
    if ($taskKey === 'logs.retention') {
        return ['archive-maintenance-work'];
    }
    if (in_array(
        $taskKey,
        [
            'database.logical',
            'database.inventory',
            'database.analyze',
            'database.optimize',
        ],
        true,
    )) {
        return ['domain-admin-database-maintenance'];
    }
    if ($taskKey === 'verify') {
        return (
            $phase === 'execution' ? ['domain-admin-database-maintenance'] : []
        );
    }
    return [];
}

/**
 * Load reviewed heavy dependencies for one bounded Maintenance Center task.
 *
 * A null loader is supported for full-bootstrap CLI/tests where every dependency
 * is already present. Selective web routes inject the request-local runtime kernel
 * loader from the controller.
 *
 * @param string $taskKey Stable Maintenance Center task key.
 * @param string $phase Current task phase: analysis or execution.
 * @param callable(string):void|null $loadDependencies Optional logical-module loader.
 * @return void Loads all modules required by this task and phase.
 */
function maintenance_center_load_task_dependencies(
    string $taskKey,
    string $phase,
    ?callable $loadDependencies = null,
): void {
    if ($loadDependencies === null) {
        return;
    }
    foreach (maintenance_center_task_runtime_modules(
        $taskKey,
        $phase,
    ) as $module) {
        $loadDependencies($module);
    }
}

require_once __DIR__ . '/maintenance_center/registry.php';
require_once __DIR__ . '/maintenance_center/analysis.php';
require_once __DIR__ . '/maintenance_center/execution.php';
require_once __DIR__ . '/maintenance_center/status.php';
