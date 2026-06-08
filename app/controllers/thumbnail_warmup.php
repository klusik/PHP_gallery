<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/thumbnail_warmup.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles small public background thumbnail warmup requests.
 *
 * Responsibilities:
 *   - Accept browser-submitted warmup candidates from rendered public pages
 *   - Keep the endpoint JSON-only and safe for anonymous visitors
 *   - Delegate token validation, access checks, locking, and generation to the service layer
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
 *   2026-06-08
 */

declare(strict_types=1);

/**
 * Process a guarded thumbnail warmup request.
 */
function cms_thumbnail_warmup(): void
{
    if (request_method() !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'POST required'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }

    // $rawItemsJson stores the JSON candidate list posted by the browser module.
    $rawItemsJson = (string) ($_POST['items'] ?? '');
    // $decodedItems stores the decoded item array before strict normalization.
    $decodedItems = $rawItemsJson !== '' ? json_decode($rawItemsJson, true) : [];
    // $items stores accepted candidate shapes capped by the warmup service.
    $items = thumbnail_warmup_normalize_items($decodedItems);

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    if (!$items) {
        $response = ['ok' => true, 'enabled' => thumbnail_warmup_enabled(), 'accepted' => 0, 'processed' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'busy' => false];
        if ($rawItemsJson !== '') {
            thumbnail_warmup_log_event(
                'info',
                'thumbnail_warmup.empty',
                'Background thumbnail warmup endpoint was triggered without usable candidates.',
                array_merge(thumbnail_warmup_log_request_context(), $response, [
                    'raw_items_json_length' => strlen($rawItemsJson),
                    'json_error' => json_last_error_msg(),
                ]),
                ['severity' => 'notice']
            );
        }
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    try {
        echo json_encode(thumbnail_warmup_process_items($items), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $exception) {
        thumbnail_warmup_log_event(
            'error',
            'thumbnail_warmup.exception',
            'Background thumbnail warmup failed before a normal batch response could be returned.',
            array_merge(thumbnail_warmup_log_request_context(), [
                'accepted' => count($items),
                'candidates' => thumbnail_warmup_log_candidate_summary($items),
                'exception_class' => get_class($exception),
                'exception_message' => $exception->getMessage(),
            ]),
            ['severity' => 'error']
        );
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Thumbnail warmup failed.',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
