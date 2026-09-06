<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_test_run_analysis/request_analysis.php
 * Module Type: Service
 *
 * Purpose:
 *   Summarizes post-response work and session lock contention.
 *
 * Responsibilities:
 *   - Measure work performed after the logical response finished
 *   - Detect session lock contention between overlapping requests
 *   - Keep summaries derived only from recorded request boundaries
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
 *   - Loaded by app/services/admin_test_run_analysis.php; do not require this file directly.
 *   - Shared constants for this module live in app/services/admin_test_run_analysis.php.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-09-06
 */

declare(strict_types=1);

namespace Gallery\Services;

use Throwable;

/**
 * Return request lifecycle response-tail metrics.
 *
 * @param array<int,array<string,mixed>> $requests Request sidecars.
 * @return array<string,mixed>
 */
function admin_test_run_post_response_summary(array $requests): array
{
    $rows = [];
    $max = 0.0;
    foreach ($requests as $request) {
        if (!is_array($request)) {
            continue;
        }
        $logical = (float) ($request['response_lifecycle']['logical_response_finished_at_unix'] ?? 0.0);
        $detach = (float) ($request['response_lifecycle']['detach_called_at_unix'] ?? 0.0);
        $basis = $logical > 0.0 ? $logical : $detach;
        $shutdown = (float) ($request['finished_at_unix'] ?? $request['response_lifecycle']['shutdown_at_unix'] ?? 0.0);
        $recordedTail = $request['response_lifecycle']['response_to_shutdown_ms'] ?? null;
        $tail = is_numeric($recordedTail)
            ? max(0.0, (float) $recordedTail)
            : ($basis > 0.0 && $shutdown >= $basis ? ($shutdown - $basis) * 1000 : null);
        if ($tail !== null) {
            $max = max($max, $tail);
        }
        $rows[] = [
            'request_id' => (string) ($request['request_id'] ?? ''),
            'uri' => (string) ($request['request']['uri'] ?? ''),
            'logical_response_finished_at_unix' => $logical > 0.0 ? $logical : null,
            'fastcgi_or_litespeed_detach_called_at_unix' => $detach > 0.0 ? $detach : null,
            'shutdown_at_unix' => $shutdown > 0.0 ? $shutdown : null,
            'response_to_shutdown_ms' => $tail,
            'post_response_work_observed' => $tail !== null && $tail >= 25.0,
            'detach_mechanism' => (string) ($request['response_lifecycle']['detach_mechanism'] ?? ''),
        ];
    }
    usort($rows, static fn (array $a, array $b): int => (float) ($b['response_to_shutdown_ms'] ?? -1) <=> (float) ($a['response_to_shutdown_ms'] ?? -1));
    return [
        'request_count' => count($rows),
        'max_response_to_shutdown_ms' => $max,
        'requests' => $rows,
    ];
}

/**
 * Summarize PHP session-start latency and early release coverage for media fanout.
 *
 * session_start duration includes lock acquisition, session-file I/O, and decode
 * work, so the report deliberately calls this probable contention rather than
 * claiming exact lock-wait time. With the files save handler, many overlapping
 * thumbnail requests showing large session_start durations are nevertheless a
 * strong shared-host contention signal.
 *
 * @param array<int,array<string,mixed>> $requests Request sidecars.
 * @return array<string,mixed>
 */
function admin_test_run_session_lock_contention_summary(array $requests): array
{
    $allDurations = [];
    $mediaDurations = [];
    $mediaHoldDurations = [];
    $mediaCount = 0;
    $thumbnailCount = 0;
    $releaseEligible = 0;
    $releaseObserved = 0;
    $over50 = 0;
    $over200 = 0;
    $saveHandlers = [];
    $slowest = [];
    $mediaPages = ['thumb', 'public_thumb', 'media', 'public_media', 'gallery_cover_asset', 'gallery_branding_asset'];

    foreach ($requests as $request) {
        if (!is_array($request)) {
            continue;
        }
        $sessionBegin = null;
        $sessionEnd = null;
        $releaseEnd = null;
        $page = '';
        $saveHandler = '';
        foreach ((array) ($request['marks'] ?? []) as $mark) {
            if (!is_array($mark)) {
                continue;
            }
            $name = (string) ($mark['name'] ?? '');
            $at = isset($mark['at_unix']) && is_numeric($mark['at_unix']) ? (float) $mark['at_unix'] : null;
            $context = is_array($mark['context'] ?? null) ? $mark['context'] : [];
            if ($name === 'session_start_begin') {
                $sessionBegin = $at;
                $saveHandler = (string) ($context['save_handler'] ?? '');
            } elseif ($name === 'session_start_end') {
                $sessionEnd = $at;
            } elseif ($name === 'request_initialize_end') {
                $page = (string) ($context['page'] ?? $page);
            } elseif ($name === 'read_only_media_session_release_end') {
                $releaseEnd = $at;
                if ($page === '') {
                    $page = (string) ($context['page'] ?? '');
                }
            }
        }
        if ($page === '') {
            $uri = strtolower((string) ($request['request']['uri'] ?? ''));
            if (str_contains($uri, 'page=public_thumb') || str_contains($uri, '/thumb-')) {
                $page = 'public_thumb';
            } elseif (str_contains($uri, 'page=thumb')) {
                $page = 'thumb';
            } elseif (str_contains($uri, 'page=public_media') || str_ends_with(parse_url($uri, PHP_URL_PATH) ?: '', '/media')) {
                $page = 'public_media';
            } elseif (str_contains($uri, 'page=media')) {
                $page = 'media';
            }
        }
        if ($sessionBegin === null || $sessionEnd === null || $sessionEnd < $sessionBegin) {
            continue;
        }

        $durationMs = max(0.0, ($sessionEnd - $sessionBegin) * 1000.0);
        $allDurations[] = $durationMs;
        if ($saveHandler !== '') {
            $saveHandlers[$saveHandler] = ($saveHandlers[$saveHandler] ?? 0) + 1;
        }
        if (!in_array($page, $mediaPages, true)) {
            continue;
        }

        $mediaCount++;
        if (in_array($page, ['thumb', 'public_thumb'], true)) {
            $thumbnailCount++;
        }
        $mediaDurations[] = $durationMs;
        $releaseEligible++;
        if ($durationMs >= 50.0) {
            $over50++;
        }
        if ($durationMs >= 200.0) {
            $over200++;
        }
        if ($releaseEnd !== null && $releaseEnd >= $sessionEnd) {
            $releaseObserved++;
            $mediaHoldDurations[] = max(0.0, ($releaseEnd - $sessionEnd) * 1000.0);
        }
        $slowest[] = [
            'request_id' => (string) ($request['request_id'] ?? ''),
            'page' => $page,
            'session_start_ms' => $durationMs,
            'session_held_after_start_ms' => $releaseEnd !== null && $releaseEnd >= $sessionEnd
                ? max(0.0, ($releaseEnd - $sessionEnd) * 1000.0)
                : null,
        ];
    }

    usort($slowest, static fn (array $a, array $b): int => (float) $b['session_start_ms'] <=> (float) $a['session_start_ms']);
    $probableContentionThreshold = max(4, (int) ceil($mediaCount * 0.10));
    $filesHandlerObserved = isset($saveHandlers['files']);
    $probableContention = $mediaCount >= 8 && $filesHandlerObserved && $over50 >= $probableContentionThreshold;

    return [
        'measurement_note' => 'session_start_ms includes session-lock acquisition, session storage I/O, and decode time; exact lock-wait time is not exposed by PHP.',
        'request_count_with_session_timing' => count($allDurations),
        'high_fanout_media_request_count' => $mediaCount,
        'thumbnail_request_count' => $thumbnailCount,
        'save_handler_distribution' => $saveHandlers,
        'session_start_ms' => [
            'p50' => admin_test_run_percentile($mediaDurations, 0.50),
            'p95' => admin_test_run_percentile($mediaDurations, 0.95),
            'max' => $mediaDurations !== [] ? max($mediaDurations) : null,
            'aggregate' => array_sum($mediaDurations),
            'requests_at_or_above_50_ms' => $over50,
            'requests_at_or_above_200_ms' => $over200,
        ],
        'early_session_release' => [
            'eligible_media_requests' => $releaseEligible,
            'observed_media_requests' => $releaseObserved,
            'coverage_ratio' => $releaseEligible > 0 ? $releaseObserved / $releaseEligible : null,
            'session_held_after_start_ms_p50' => admin_test_run_percentile($mediaHoldDurations, 0.50),
            'session_held_after_start_ms_p95' => admin_test_run_percentile($mediaHoldDurations, 0.95),
            'session_held_after_start_ms_max' => $mediaHoldDurations !== [] ? max($mediaHoldDurations) : null,
        ],
        'probable_contention' => $probableContention,
        'probable_contention_threshold_requests' => $probableContentionThreshold,
        'slowest_media_requests' => array_slice($slowest, 0, 12),
    ];
}
