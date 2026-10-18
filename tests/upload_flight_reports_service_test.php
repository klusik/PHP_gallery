<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/upload_flight_reports_service_test.php
 * Module Type: Regression Test
 * Purpose: Verify gallery-scoped flight PDF admission and retry behavior.
 * Responsibilities:
 *   - Cover identity, checksum, MIME and byte-limit boundaries
 *   - Confirm report attachments remain confined to the token-selected gallery
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Check whether one canonical path is beneath its canonical parent.
     *
     * @param string $root Canonical parent directory.
     * @param string $candidate Canonical candidate path.
     * @return bool True when candidate is a strict descendant of root.
     */
    function path_inside(string $root, string $candidate): bool
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($candidate, $prefix);
    }
}

namespace Gallery\Services {
    /**
     * Resolve one gallery-relative fixture path beneath the isolated test root.
     *
     * @param string $relative Gallery-relative folder path.
     * @return string Absolute fixture path.
     */
    function gallery_abs_path(string $relative): string
    {
        return $GLOBALS['flight_report_test_root']
            . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * Return the isolated root accepted by the real PDF path policy.
     *
     * @return string Absolute test galleries root.
     */
    function galleries_root(): string
    {
        return $GLOBALS['flight_report_test_root'];
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/gallery_pdf_documents.php';
    require_once dirname(__DIR__) . '/app/services/upload_flight_reports.php';

    /**
     * Purpose: Cross the existing PDF byte ceiling by one exact byte.
     * Type: int; Units: bytes; Scope: oversized report fixture.
     * Consumers: flight_report_test_create_oversized_pdf.
     * Rationale: One byte above the shared service limit must be rejected.
     */
    const FLIGHT_REPORT_TEST_OVERSIZE_BYTES =
        \Gallery\Services\GALLERY_PDF_DOCUMENT_MAX_BYTES + 1;

    /**
     * Stop this service fixture with a clear failure when an expectation fails.
     *
     * @param bool $condition Expected condition.
     * @param string $message Failure description.
     * @return void
     * @throws RuntimeException When the expectation is false.
     */
    function flight_report_test_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Create a sparse PDF-shaped file one byte above the shared quota.
     *
     * @param string $path New fixture path.
     * @return void
     * @throws RuntimeException When the fixture cannot be written.
     */
    function flight_report_test_create_oversized_pdf(string $path): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not create the oversized report fixture.');
        }
        try {
            if (fwrite($handle, "%PDF-1.4\n") === false
                || !ftruncate($handle, FLIGHT_REPORT_TEST_OVERSIZE_BYTES)
                || fseek($handle, FLIGHT_REPORT_TEST_OVERSIZE_BYTES - 8) !== 0
                || fwrite($handle, "%%EOF\n") === false) {
                throw new RuntimeException('Could not size the oversized report fixture.');
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Remove the isolated fixture tree after assertions finish.
     *
     * @param string $root Isolated test directory.
     * @return void Removes regular files and directories from the fixture tree.
     */
    function flight_report_test_remove_tree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $root,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($root);
    }

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
        . 'gallery-flight-report-' . bin2hex(random_bytes(8));
    $galleryAPath = $root . DIRECTORY_SEPARATOR . 'gallery-a';
    $galleryBPath = $root . DIRECTORY_SEPARATOR . 'gallery-b';
    if (!mkdir($galleryAPath, 0700, true)
        || !mkdir($galleryBPath, 0700, true)) {
        throw new RuntimeException('Could not create isolated report galleries.');
    }
    $GLOBALS['flight_report_test_root'] = $root;

    $source = $root . DIRECTORY_SEPARATOR . 'report.pdf';
    $invalidSource = $root . DIRECTORY_SEPARATOR . 'not-a-pdf.pdf';
    $wrongMimeSource = $root . DIRECTORY_SEPARATOR . 'image-as-report.pdf';
    $oversizedSource = $root . DIRECTORY_SEPARATOR . 'oversized.pdf';
    $pdfBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    file_put_contents($source, $pdfBytes);
    file_put_contents($invalidSource, "%PDF-1.4\nthis file has no PDF trailer");
    file_put_contents($wrongMimeSource, "\x89PNG\r\n\x1a\nnot a PDF payload");
    $galleryA = ['id' => 41, 'folder_path' => 'gallery-a'];
    $galleryB = ['id' => 42, 'folder_path' => 'gallery-b'];

    try {
        $digest = hash('sha256', $pdfBytes);
        $first = \Gallery\Services\upload_flight_report_attach(
            $galleryA,
            $source,
            'report-01',
            'session-01',
            'en',
            $digest
        );
        $retry = \Gallery\Services\upload_flight_report_attach(
            $galleryA,
            $source,
            'report-01',
            'session-01',
            'en',
            $digest
        );
        flight_report_test_assert(
            $first['found'] && $retry['found']
                && $first['report']['id'] === $retry['report']['id']
                && count(\Gallery\Services\gallery_pdf_documents_list($galleryA)) === 1,
            'An exact retry must return one durable attachment receipt.'
        );

        $status = \Gallery\Services\upload_flight_report_status(
            $galleryA,
            'report-01',
            'en',
            $digest
        );
        flight_report_test_assert(
            $status['found'] && !$status['conflict']
                && $status['report']['sha256'] === $digest,
            'Status must reconcile the stored report by identity and checksum.'
        );

        $changedStatus = \Gallery\Services\upload_flight_report_status(
            $galleryA,
            'report-01',
            'en',
            str_repeat('0', 64)
        );
        flight_report_test_assert(
            $changedStatus['conflict'] && !$changedStatus['found'],
            'A changed checksum must conflict instead of replacing the report.'
        );

        $verifiedReport = \Gallery\Services\gallery_pdf_documents_read(
            $galleryA,
            $first['report']['id']
        );
        flight_report_test_assert(
            is_array($verifiedReport),
            'The persisted receipt must resolve to a confined, verified PDF.'
        );
        unlink($verifiedReport['path']);
        $deletedStatus = \Gallery\Services\upload_flight_report_status(
            $galleryA,
            'report-01',
            'en',
            $digest
        );
        flight_report_test_assert(
            $deletedStatus['found'] === false
                && $deletedStatus['conflict'] === false
                && $deletedStatus['report'] === null,
            'Status must not return a receipt for a deleted indexed PDF.'
        );

        $recoveredReport = \Gallery\Services\upload_flight_report_attach(
            $galleryA,
            $source,
            'report-01',
            'session-01',
            'en',
            $digest
        );
        $visibleReports = \Gallery\Services\gallery_pdf_documents_list($galleryA);
        $recoveredRead = \Gallery\Services\gallery_pdf_documents_read(
            $galleryA,
            $recoveredReport['report']['id']
        );
        flight_report_test_assert(
            $recoveredReport['found']
                && !$recoveredReport['conflict']
                && $recoveredReport['report']['id'] !== $first['report']['id']
                && count($visibleReports) === 1
                && is_array($recoveredRead)
                && hash_equals(
                    $digest,
                    (string) hash_file('sha256', $recoveredRead['path'])
                )
                && hash_equals($digest, $recoveredRead['metadata']['sha256']),
            'Same-identity retry must restore one fresh confined, checksum-valid report.'
        );
        $recoveredStatus = \Gallery\Services\upload_flight_report_status(
            $galleryA,
            'report-01',
            'en',
            $digest
        );
        flight_report_test_assert(
            $recoveredStatus['found']
                && !$recoveredStatus['conflict']
                && $recoveredStatus['report']['id']
                    === $recoveredReport['report']['id']
                && $recoveredStatus['report']['sha256'] === $digest,
            'Status must reconcile the recovered report after an exact retry.'
        );

        // Keep the byte length fixed so integrity rejection exercises SHA-256,
        // rather than succeeding solely because the stored size no longer fits.
        $corruptPdfBytes = str_replace('/Catalog', '/CatXlog', $pdfBytes);
        flight_report_test_assert(
            strlen($corruptPdfBytes) === strlen($pdfBytes)
                && hash('sha256', $corruptPdfBytes) !== $digest,
            'The corrupt PDF vector must retain its size and change its checksum.'
        );
        file_put_contents($recoveredRead['path'], $corruptPdfBytes);
        $corruptStatus = \Gallery\Services\upload_flight_report_status(
            $galleryA,
            'report-01',
            'en',
            $digest
        );
        flight_report_test_assert(
            $corruptStatus['found'] === false
                && $corruptStatus['conflict'] === false
                && $corruptStatus['report'] === null,
            'Status must return no receipt for PDF bytes that fail checksum validation.'
        );

        $otherGallery = \Gallery\Services\upload_flight_report_status(
            $galleryB,
            'report-01',
            'en',
            $digest
        );
        flight_report_test_assert(
            !$otherGallery['found'] && !$otherGallery['conflict']
                && \Gallery\Services\gallery_pdf_documents_list($galleryB) === [],
            'A report in one gallery must not appear in another gallery.'
        );

        $invalidRejected = false;
        try {
            \Gallery\Services\upload_flight_report_attach(
                $galleryA,
                $invalidSource,
                'report-invalid',
                'session-01',
                'cs',
                hash_file('sha256', $invalidSource)
            );
        } catch (RuntimeException) {
            $invalidRejected = true;
        }
        flight_report_test_assert(
            $invalidRejected,
            'A PDF without a complete trailer must be rejected.'
        );

        $wrongMimeRejected = false;
        try {
            \Gallery\Services\upload_flight_report_attach(
                $galleryA,
                $wrongMimeSource,
                'report-wrong-mime',
                'session-01',
                'de',
                hash_file('sha256', $wrongMimeSource)
            );
        } catch (RuntimeException) {
            $wrongMimeRejected = true;
        }
        flight_report_test_assert(
            $wrongMimeRejected,
            'Image bytes named as a PDF report must be rejected by content validation.'
        );

        flight_report_test_create_oversized_pdf($oversizedSource);
        $oversizedRejected = false;
        try {
            \Gallery\Services\upload_flight_report_attach(
                $galleryA,
                $oversizedSource,
                'report-large',
                'session-01',
                'de',
                str_repeat('0', 64)
            );
        } catch (RuntimeException) {
            $oversizedRejected = true;
        }
        flight_report_test_assert(
            $oversizedRejected,
            'A report above the existing PDF byte ceiling must be rejected.'
        );

        $controllerPath = dirname(__DIR__)
            . '/app/controllers/upload_automation.php';
        $controller = file_get_contents($controllerPath);
        flight_report_test_assert(
            is_string($controller)
                && str_contains($controller, "'flight_reports_v1' => true")
                && str_contains(
                    $controller,
                    '$galleryId = (int) ($tokenRow[\'gallery_id\'] ?? 0);'
                )
                && str_contains(
                    $controller,
                    "upload_flight_report_attach("
                ),
            'The API must advertise and dispatch reports within token gallery scope.'
        );
        $handlerStart = strpos(
            $controller,
            'function upload_automation_handle_flight_report_action'
        );
        $handlerEnd = strpos(
            $controller,
            'function cms_upload_automation_upload'
        );
        $handlers = is_int($handlerStart) && is_int($handlerEnd)
            ? substr($controller, $handlerStart, $handlerEnd - $handlerStart)
            : '';
        flight_report_test_assert(
            $handlers !== '' && !str_contains($handlers, 'require_admin('),
            'Report delivery must use the upload key without Admin escalation.'
        );
        flight_report_test_assert(
            str_contains($handlers, 'upload_automation_with_gallery_lock('),
            'Concurrent report uploads must serialize through the gallery upload lock.'
        );
    } finally {
        flight_report_test_remove_tree($root);
    }

    echo "PASS flight report gallery delivery service\n";
}
