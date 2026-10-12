<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_pdf_documents_service_test.php
 * Module Type: Regression Test
 * Purpose: Exercise independent gallery PDF document storage and lifecycle.
 * Responsibilities:
 *   - Verify exact-byte attachment and gallery-scoped resolution
 *   - Verify replacement and deletion require explicit confirmation
 *   - Verify backwards-compatible visibility defaults and index-state preservation
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Check whether one canonical filesystem path is beneath another.
     * @param string $root Canonical parent path.
     * @param string $candidate Canonical candidate path.
     * @return bool True when the candidate is a strict descendant of the root.
     */
    function path_inside(string $root, string $candidate): bool
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return str_starts_with($candidate, $prefix);
    }
}

namespace Gallery\Services {
    /**
     * Resolve one gallery-relative fixture path beneath the test galleries root.
     * @param string $relative Gallery-relative folder path.
     * @return string Absolute fixture path.
     */
    function gallery_abs_path(string $relative): string
    {
        return $GLOBALS['gallery_pdf_documents_test_root'] . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * Return the isolated test galleries directory.
     * @return string Absolute galleries root.
     */
    function galleries_root(): string
    {
        return $GLOBALS['gallery_pdf_documents_test_root'];
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/gallery_pdf_documents.php';

    /**
     * Stop the service test with a clear failure when an expectation is false.
     * @param bool $condition Expected condition.
     * @param string $message Failure description.
     * @return void
     */
    function gallery_pdf_documents_test_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gallery-pdf-documents-' . bin2hex(random_bytes(8));
    $galleryDirectory = $root . DIRECTORY_SEPARATOR . 'gallery';
    if (!mkdir($galleryDirectory, 0700, true)) {
        throw new RuntimeException('Could not create the isolated PDF documents test directory.');
    }
    $GLOBALS['gallery_pdf_documents_test_root'] = $root;
    $source = $root . DIRECTORY_SEPARATOR . 'first.pdf';
    $replacement = $root . DIRECTORY_SEPARATOR . 'replacement.pdf';
    $firstBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    $replacementBytes = "%PDF-1.7\n2 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    file_put_contents($source, $firstBytes);
    file_put_contents($replacement, $replacementBytes);
    $gallery = ['id' => 7, 'folder_path' => 'gallery'];

    try {
        $first = \Gallery\Services\gallery_pdf_documents_attach(
            $gallery, $source, 'scan.pdf', 'Original scan', 'manually_supplied'
        );
        gallery_pdf_documents_test_assert($first['size'] === strlen($firstBytes)
            && $first['sha256'] === hash('sha256', $firstBytes), 'Attachment metadata must describe exact original bytes.');
        gallery_pdf_documents_test_assert($first['hidden'] === false
            && !\Gallery\Services\gallery_pdf_documents_hidden($gallery, $first['id']),
            'New documents must default to visible in metadata and visibility reads.');
        $resolved = \Gallery\Services\gallery_pdf_documents_read($gallery, $first['id']);
        gallery_pdf_documents_test_assert(is_array($resolved)
            && file_get_contents($resolved['path']) === $firstBytes
            && $resolved['metadata']['id'] === $first['id'], 'Read must resolve the exact gallery-scoped original PDF.');
        gallery_pdf_documents_test_assert(count(\Gallery\Services\gallery_pdf_documents_list($gallery)) === 1,
            'List must return the attached general document.');

        $indexPath = $galleryDirectory . DIRECTORY_SEPARATOR . '.gallery-pdf-documents' . DIRECTORY_SEPARATOR . 'index.json';
        $legacyIndex = json_decode((string) file_get_contents($indexPath), true);
        unset($legacyIndex['documents'][$first['id']]['hidden'], $legacyIndex['ofp_hidden']);
        file_put_contents($indexPath, json_encode($legacyIndex));
        $legacyRead = \Gallery\Services\gallery_pdf_documents_read($gallery, $first['id']);
        gallery_pdf_documents_test_assert(is_array($legacyRead) && $legacyRead['metadata']['hidden'] === false
            && !\Gallery\Services\gallery_pdf_documents_hidden($gallery, $first['id'])
            && !\Gallery\Services\gallery_pdf_documents_hidden($gallery, 'ofp'),
            'Records written before visibility metadata existed must remain visible by default.');

        gallery_pdf_documents_test_assert(\Gallery\Services\gallery_pdf_documents_set_hidden($gallery, $first['id'], true)
            && \Gallery\Services\gallery_pdf_documents_hidden($gallery, $first['id']),
            'Manual PDF visibility must be hideable through the persisted index.');
        gallery_pdf_documents_test_assert(\Gallery\Services\gallery_pdf_documents_set_hidden($gallery, $first['id'], false)
            && !\Gallery\Services\gallery_pdf_documents_hidden($gallery, $first['id']),
            'Manual PDF visibility must be restorable through the persisted index.');
        gallery_pdf_documents_test_assert(\Gallery\Services\gallery_pdf_documents_set_ofp_hidden($gallery, true)
            && \Gallery\Services\gallery_pdf_documents_hidden($gallery, 'ofp'),
            'Legacy OFP visibility must be stored in the gallery PDF index.');
        $additional = \Gallery\Services\gallery_pdf_documents_attach(
            $gallery, $source, 'additional.pdf', 'Additional scan', 'manually_supplied'
        );
        gallery_pdf_documents_test_assert(\Gallery\Services\gallery_pdf_documents_hidden($gallery, 'ofp'),
            'Attaching a manual PDF must preserve the independent OFP visibility flag.');
        gallery_pdf_documents_test_assert(\Gallery\Services\gallery_pdf_documents_delete($gallery, $additional['id'], true)
            && \Gallery\Services\gallery_pdf_documents_hidden($gallery, 'ofp'),
            'Deleting a manual PDF must preserve the independent OFP visibility flag.');

        $replacementConfirmationRequired = false;
        try {
            \Gallery\Services\gallery_pdf_documents_attach(
                $gallery, $replacement, 'updated.pdf', 'Updated scan', 'manually_supplied', $first['id']
            );
        } catch (RuntimeException $exception) {
            $replacementConfirmationRequired = str_contains($exception->getMessage(), 'confirmation');
        }
        gallery_pdf_documents_test_assert($replacementConfirmationRequired,
            'Replacement must be refused until explicit confirmation is supplied.');
        $updated = \Gallery\Services\gallery_pdf_documents_attach(
            $gallery, $replacement, 'updated.pdf', 'Updated scan', 'manually_supplied', $first['id'], true
        );
        gallery_pdf_documents_test_assert($updated['id'] === $first['id']
            && $updated['sha256'] === hash('sha256', $replacementBytes)
            && $updated['hidden'] === false
            && \Gallery\Services\gallery_pdf_documents_hidden($gallery, 'ofp'),
            'Replacement must preserve the stable id and manual visibility, and retain the separate OFP flag.');
        $resolvedUpdated = \Gallery\Services\gallery_pdf_documents_read($gallery, $first['id']);
        gallery_pdf_documents_test_assert(is_array($resolvedUpdated)
            && file_get_contents($resolvedUpdated['path']) === $replacementBytes,
            'Replacement must preserve the uploaded original bytes.');
        gallery_pdf_documents_test_assert(\Gallery\Services\gallery_pdf_documents_set_hidden($gallery, $first['id'], true),
            'The replacement record must remain eligible for visibility changes.');
        $hiddenReplacement = \Gallery\Services\gallery_pdf_documents_attach(
            $gallery, $source, 'hidden-replacement.pdf', 'Hidden scan', 'manually_supplied', $first['id'], true
        );
        gallery_pdf_documents_test_assert($hiddenReplacement['hidden'] === true
            && \Gallery\Services\gallery_pdf_documents_hidden($gallery, $first['id'])
            && \Gallery\Services\gallery_pdf_documents_hidden($gallery, 'ofp'),
            'Replacing a hidden PDF must preserve its hidden state and the independent OFP flag.');
        $resolvedHiddenReplacement = \Gallery\Services\gallery_pdf_documents_read($gallery, $first['id']);
        gallery_pdf_documents_test_assert(is_array($resolvedHiddenReplacement)
            && file_get_contents($resolvedHiddenReplacement['path']) === $firstBytes,
            'Replacing a hidden PDF must preserve the newly uploaded original bytes.');

        $deleteConfirmationRequired = false;
        try {
            \Gallery\Services\gallery_pdf_documents_delete($gallery, $first['id']);
        } catch (RuntimeException $exception) {
            $deleteConfirmationRequired = str_contains($exception->getMessage(), 'confirmation');
        }
        gallery_pdf_documents_test_assert($deleteConfirmationRequired,
            'Deletion must be refused until explicit confirmation is supplied.');
        gallery_pdf_documents_test_assert(\Gallery\Services\gallery_pdf_documents_delete($gallery, $first['id'], true),
            'Confirmed deletion must remove the document.');
        gallery_pdf_documents_test_assert(\Gallery\Services\gallery_pdf_documents_read($gallery, $first['id']) === null
            && \Gallery\Services\gallery_pdf_documents_list($gallery) === []
            && \Gallery\Services\gallery_pdf_documents_hidden($gallery, 'ofp'),
            'Deleting a manual PDF must remove it while preserving the OFP visibility flag.');
    } finally {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($root);
    }

    echo "PASS gallery PDF documents service lifecycle\n";
}
