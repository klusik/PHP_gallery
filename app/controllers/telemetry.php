<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/telemetry.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the related gallery feature.
 *
 * Responsibilities:
 *   - Validate and route incoming request data
 *   - Call service-layer functions where possible
 *   - Return redirects, rendered views, or HTTP responses
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

/**
 * Public anonymous telemetry ingestion controller.
 *
 * This route accepts only compact JSON batches from the first-party gallery UI.
 * It is intentionally not CSRF-protected because it receives public analytics,
 * but it is size-limited, allowlisted, normalized, and disabled by default.
 */

/**
 * Handle anonymous telemetry ingestion requests.
 */
function cms_telemetry_ingest(): void
{
    if (request_method() !== 'POST') {
        http_response_code(404);
        return;
    }
    if (telemetry_request_excluded()) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'stored' => 0]);
        return;
    }
    // $contentLength stores the declared payload length used for a cheap size guard.
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 32768) {
        http_response_code(413);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => t('telemetry.collect.error_payload_too_large', 'Payload too large.')]);
        return;
    }
    // $rawBody stores the raw JSON body only long enough to decode the event batch.
    $rawBody = (string) file_get_contents('php://input');
    if ($rawBody === '' || strlen($rawBody) > 32768) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => t('telemetry.collect.error_invalid_payload', 'Invalid payload.')]);
        return;
    }
    // $payload stores the decoded telemetry batch.
    $payload = json_decode($rawBody, true);
    if (!is_array($payload) || !isset($payload['events']) || !is_array($payload['events'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => t('telemetry.collect.error_invalid_payload', 'Invalid payload.')]);
        return;
    }
    // $storedCount stores the number of accepted events.
    $storedCount = 0;
    foreach (array_slice($payload['events'], 0, 30) as $event) {
        if (!is_array($event)) {
            continue;
        }
        telemetry_record_event($event);
        $storedCount++;
    }
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'stored' => $storedCount]);
}
