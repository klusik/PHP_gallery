<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/upload_flight_reports.php
 * Module Type: Service Module
 * Purpose: Attach flight report PDFs through the gallery-scoped uploader API.
 * Responsibilities:
 *   - Reuse the protected gallery PDF validation, storage and viewer index
 *   - Reconcile stable report identities and exact checksums after retries
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use function Gallery\Services\gallery_pdf_documents_read;

/**
 * Purpose: Limit report identity inputs to compact opaque ASCII values.
 * Type: int; Units: characters; Scope: report and session identifiers.
 * Consumers: upload_flight_report_identity and upload_flight_report_attach.
 * Rationale: An 80-character bound keeps request metadata and audit records small.
 */
const UPLOAD_FLIGHT_REPORT_MAX_ID_LENGTH = 80;

/**
 * Normalize a report identity into the existing PDF provenance field.
 *
 * @param string $reportId Stable report identity supplied by the companion.
 * @param string $language Two-letter supported report language.
 * @return string Stable provenance value stored in the PDF index.
 * @throws RuntimeException When either identity value is unsupported.
 */
function upload_flight_report_identity(
    string $reportId,
    string $language,
): string {
    $reportId = trim($reportId);
    $language = strtolower(trim($language));
    if (
        strlen($reportId) > UPLOAD_FLIGHT_REPORT_MAX_ID_LENGTH
        || preg_match('/^[A-Za-z0-9_-]+$/D', $reportId) !== 1
        || !in_array($language, ['en', 'cs', 'de', 'sv'], true)
    ) {
        throw new RuntimeException(
            'The flight report identity or language is invalid.',
        );
    }

    return 'flight-v1:' . hash('sha256', $reportId . "\0" . $language);
}

/**
 * Find an existing report attachment by stable identity and expected checksum.
 *
 * @param array{id:int,folder_path:string} $gallery Physical gallery authorized by the token.
 * @param string $reportId Stable report identity supplied by the companion.
 * @param string $language Two-letter supported report language.
 * @param string $sha256 Expected lowercase SHA-256 of the PDF bytes.
 * @return array{found:bool,conflict:bool,report:?array{id:string,sha256:string,size:int,filename:string}} A verified receipt on match/conflict; null report when no verified attachment is listed.
 * @throws RuntimeException When identity/checksum input or the attachment index is invalid, or a listed PDF fails a second integrity read.
 */
function upload_flight_report_status(
    array $gallery,
    string $reportId,
    string $language,
    string $sha256,
): array {
    $provenance = upload_flight_report_identity($reportId, $language);
    if (preg_match('/^[a-f0-9]{64}$/D', strtolower($sha256)) !== 1) {
        throw new RuntimeException('The flight report checksum is invalid.');
    }

    $matches = [];
    foreach (gallery_pdf_documents_list($gallery) as $document) {
        if (($document['provenance'] ?? '') === $provenance) {
            $matches[] = $document;
        }
    }
    if (count($matches) > 1) {
        return ['found' => false, 'conflict' => true, 'report' => null];
    }
    if ($matches === []) {
        return ['found' => false, 'conflict' => false, 'report' => null];
    }

    $document = $matches[0];
    $verified = gallery_pdf_documents_read($gallery, (string) $document['id']);
    if ($verified === null) {
        throw new RuntimeException(
            'The indexed flight report is missing or failed integrity validation.',
        );
    }
    $stored = $verified['metadata'];
    if (($stored['provenance'] ?? '') !== $provenance) {
        throw new RuntimeException(
            'The flight report identity is inconsistent.',
        );
    }
    $metadata = [
        'id' => (string) $stored['id'],
        'sha256' => (string) $stored['sha256'],
        'size' => (int) $stored['size'],
        'filename' => (string) $stored['filename'],
    ];
    return [
        'found' => hash_equals($metadata['sha256'], strtolower($sha256)),
        'conflict' => !hash_equals($metadata['sha256'], strtolower($sha256)),
        'report' => $metadata,
    ];
}

/**
 * Validate and attach one PDF report or return its prior idempotent receipt.
 *
 * @param array{id:int,folder_path:string} $gallery Physical gallery authorized by the token.
 * @param string $source PHP upload temporary path for the PDF.
 * @param string $reportId Stable report identity supplied by the companion.
 * @param string $sessionId Local flight session identity for request validation.
 * @param string $language Two-letter supported report language.
 * @param string $sha256 Expected lowercase SHA-256 of the PDF bytes.
 * @return array{found:bool,conflict:bool,report:?array{id:string,sha256:string,size:int,filename:string}} Stored receipt or an identity/checksum conflict.
 * @throws RuntimeException When the PDF is invalid, oversized, or cannot be safely stored.
 */
function upload_flight_report_attach(
    array $gallery,
    string $source,
    string $reportId,
    string $sessionId,
    string $language,
    string $sha256,
): array {
    upload_flight_report_identity($reportId, $language);
    $sessionId = trim($sessionId);
    if (
        strlen($sessionId) > UPLOAD_FLIGHT_REPORT_MAX_ID_LENGTH
        || preg_match('/^[A-Za-z0-9_-]+$/D', $sessionId) !== 1
    ) {
        throw new RuntimeException('The flight session identity is invalid.');
    }

    $validated = gallery_pdf_documents_validate_pdf_source($source);
    $sha256 = strtolower(trim($sha256));
    if (!hash_equals($validated['sha256'], $sha256)) {
        throw new RuntimeException(
            'The flight report checksum does not match its PDF bytes.',
        );
    }

    $existing = upload_flight_report_status(
        $gallery,
        $reportId,
        $language,
        $sha256,
    );
    if ($existing['found'] || $existing['conflict']) {
        return $existing;
    }

    $language = strtoupper($language);
    $identity = substr(hash('sha256', $reportId), 0, 16);
    $filename =
        'flight-report-' . $identity . '-' . strtolower($language) . '.pdf';
    $document = gallery_pdf_documents_attach(
        $gallery,
        $source,
        $filename,
        'Flight report (' . $language . ')',
        upload_flight_report_identity($reportId, $language),
    );

    return [
        'found' => true,
        'conflict' => false,
        'report' => [
            'id' => (string) $document['id'],
            'sha256' => (string) $document['sha256'],
            'size' => (int) $document['size'],
            'filename' => (string) $document['filename'],
        ],
    ];
}
