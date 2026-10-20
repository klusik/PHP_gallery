<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/gallery_pdf_conversion_lifecycle_test.php
 * Module Type: Focused Service Contract Test
 *
 * Purpose:
 *   Verify source-side PDF conversion markers remain bounded, atomic and token-scoped.
 *
 * Responsibilities:
 *   - Exercise marker write/read and opaque conversion identity validation
 *   - Verify lifecycle lookup matches both the session token and target gallery
 *
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Resolve a fixture translation without loading installation language storage.
     * @param string $key Translation key used by the service.
     * @param string $fallback English text asserted by the fixture.
     * @return string The supplied safe English fallback.
     */
    function t(string $key, string $fallback): string
    {
        return $fallback;
    }

    /**
     * Resolve a test gallery path beneath its disposable fixture root.
     *
     * @param string $relativePath Relative fixture gallery path.
     * @return string Absolute fixture path.
     */
    function gallery_abs_path(string $relativePath): string
    {
        return (
            $GLOBALS['gallery_pdf_conversion_test_root']
                . DIRECTORY_SEPARATOR
                . $relativePath
        );
    }

    /**
     * Acquire the disposable fixture's simulated gallery writer lease.
     *
     * @return string Fixture lease identity.
     */
    function gallery_edit_writer_begin(): string
    {
        return 'fixture-writer';
    }

    /**
     * Release the disposable fixture's simulated gallery writer lease.
     *
     * @param string $writer Fixture lease identity.
     * @return void
     */
    function gallery_edit_writer_end(string $writer): void {}

    /**
     * Return a fixture gallery row by ID.
     *
     * @param int $id Gallery identity.
     * @param bool $fresh Whether the caller requested a fresh row.
     * @return array<string,mixed>|null Fixture row or null when absent.
     */
    function find_gallery(int $id, bool $fresh = false): ?array
    {
        return $GLOBALS['gallery_pdf_conversion_test_galleries'][$id] ?? null;
    }

    /**
     * Find a fixture gallery by its relative folder path.
     *
     * @param string $folderPath Relative gallery folder.
     * @param bool $fresh Whether the caller requested a fresh row.
     * @return array<string,mixed>|null Matching row or null when absent.
     */
    function find_gallery_by_folder_path(
        string $folderPath,
        bool $fresh = false,
    ): ?array {
        foreach ($GLOBALS['gallery_pdf_conversion_test_galleries'] as $gallery) {
            if ($gallery['folder_path'] === $folderPath) {
                return $gallery;
            }
        }
        return null;
    }

    /**
     * Build a child folder path for a fixture gallery.
     *
     * @param array<string,mixed>|null $parent Parent row or null for a root gallery.
     * @param string $folderName Requested child folder component.
     * @return string Relative folder path.
     */
    function gallery_child_folder_path(
        ?array $parent,
        string $folderName,
    ): string {
        return ($parent['folder_path'] ?? '') !== ''
            ? $parent['folder_path'] . '/' . $folderName
            : $folderName;
    }

    /**
     * Return the persisted visibility value for a fixture gallery.
     *
     * @param array<string,mixed> $gallery Persisted fixture row.
     * @return string Effective fixture visibility.
     */
    function gallery_effective_visibility(array $gallery): string
    {
        return (string) ($gallery['visibility'] ?? 'private');
    }

    /**
     * Create a physical child row in the disposable gallery catalog.
     *
     * @param array<string,mixed> $input Normalized gallery fields.
     * @return array<string,mixed> Created fixture row.
     */
    function create_empty_gallery(array $input): array
    {
        $id = ++$GLOBALS['gallery_pdf_conversion_test_next_id'];
        $parent = find_gallery((int) ($input['parent_id'] ?? 0), true);
        $row = [
            'id' => $id,
            'parent_id' => (int) ($input['parent_id'] ?? 0),
            'folder_path' => gallery_child_folder_path(
                $parent,
                (string) $input['folder_name'],
            ),
            'title' => (string) $input['title'],
            'visibility' => (string) $input['visibility'],
        ];
        mkdir(gallery_abs_path($row['folder_path']), 0700, true);
        $GLOBALS['gallery_pdf_conversion_test_galleries'][$id] = $row;
        $GLOBALS['gallery_pdf_conversion_test_images'][$id] = [];
        return $row;
    }

    /**
     * Read fixture image rows for one gallery.
     *
     * @param int $galleryId Gallery identity.
     * @param bool $publicOnly Whether to return only public rows.
     * @return list<array{id:int,filename:string,relative_path:string}> Registered fixture image rows.
     */
    function gallery_images(int $galleryId, bool $publicOnly): array
    {
        return $GLOBALS['gallery_pdf_conversion_test_images'][$galleryId] ?? [];
    }

    /**
     * Resolve one fixture-owned PDF document by its stable opaque identity.
     *
     * @param array<string,mixed> $gallery Source gallery row.
     * @param string $documentId Opaque fixture document identity.
     * @return array{path:string,metadata:array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string}}|null Document descriptor or null when absent.
     */
    function gallery_pdf_documents_read(
        array $gallery,
        string $documentId,
    ): ?array {
        return (
            $GLOBALS['gallery_pdf_conversion_test_documents'][$documentId]
                ?? null
        );
    }

    /**
     * Resolve the fixture's reserved SimBrief OFP source PDF.
     *
     * @param array<string,mixed> $gallery Source gallery row.
     * @return string|null Fixture OFP path or null when the fixture has no OFP.
     */
    function simbrief_ofp_local_pdf_path(array $gallery): ?string
    {
        return $GLOBALS['gallery_pdf_conversion_test_ofp_path'] ?? null;
    }

    /**
     * Read a cached prepared-upload success receipt for a conversion fixture.
     *
     * @param int $galleryId Upload target gallery identity.
     * @param string $sessionId Browser upload session identity.
     * @param int $batchIndex Zero-based upload batch identity.
     * @return array<string,mixed>|null Cached fixture response or null when absent.
     */
    function browser_upload_cached_batch_response(
        int $galleryId,
        string $sessionId,
        int $batchIndex,
    ): ?array {
        return (
            $GLOBALS['gallery_pdf_conversion_test_cached_batches'][$galleryId
                . ':'
                . $sessionId
                . ':'
                . $batchIndex] ?? null
        );
    }

    require_once dirname(__DIR__) . '/app/services/gallery_pdf_conversion.php';
    require_once dirname(__DIR__) . '/app/services/gallery_pdf_settings.php';
    require_once dirname(__DIR__) . '/app/services/gallery_pdf_publication.php';
}

namespace {
    /**
     * Store a deterministic sequence of small PNG fixture files in the upload target.
     *
     * @param array<string,mixed> $conversion Begin result returned by the lifecycle service.
     * @param int $pageCount Number of pages to store.
     * @param int $nextImageId Mutable next fixture image identity.
     * @return array{image_ids:list<int>,filenames:list<string>} Ordinary uploader acknowledgment fixture.
     */
    function gallery_pdf_conversion_test_store_pages(
        array $conversion,
        int $pageCount,
        int &$nextImageId,
    ): array {
        $galleryId = (int) $conversion['gallery_id'];
        $gallery =
            $GLOBALS['gallery_pdf_conversion_test_galleries'][$galleryId];
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jHgAAAABJRU5ErkJggg==',
            true,
        );
        $ids = [];
        $filenames = [];
        for ($page = 1; $page <= $pageCount; ++$page) {
            $filename =
                (string) $conversion['prefix']
                . str_pad(
                    (string) $page,
                    (int) $conversion['page_digits'],
                    '0',
                    STR_PAD_LEFT,
                )
                . '.png';
            file_put_contents(
                \Gallery\Services\gallery_abs_path(
                    (string) $gallery['folder_path'],
                )
                    . DIRECTORY_SEPARATOR
                    . $filename,
                $bytes,
            );
            $ids[] = $nextImageId;
            $filenames[] = $filename;
            $GLOBALS['gallery_pdf_conversion_test_images'][$galleryId][] = [
                'id' => $nextImageId++,
                'filename' => $filename,
                'relative_path' => $filename,
            ];
        }
        return ['image_ids' => $ids, 'filenames' => $filenames];
    }

    /**
     * Build an ordinary uploader success receipt for the exact stored PNG pages.
     *
     * @param array<string,mixed> $conversion Conversion begin response.
     * @param array{image_ids:list<int>,filenames:list<string>} $stored Stored page identities.
     * @param list<int> $pages Ordered one-based page numbers represented by this batch.
     * @return array{ok:bool,gallery_id:int,image_ids:list<int>,filenames:list<string>,manifest_sha256:string,pdf_page_numbers:list<int>,pdf_page_sha256:array<string,string>} Synthetic canonical batch acknowledgment.
     */
    function gallery_pdf_conversion_test_ack_receipt(
        array $conversion,
        array $stored,
        array $pages,
    ): array {
        $gallery =
            $GLOBALS['gallery_pdf_conversion_test_galleries'][(int) $conversion['gallery_id']];
        $hashes = [];
        foreach ($pages as $index => $page) {
            $path =
                \Gallery\Services\gallery_abs_path(
                    (string) $gallery['folder_path'],
                )
                . DIRECTORY_SEPARATOR
                . $stored['filenames'][$index];
            $hashes[(string) $page] = hash_file('sha256', (string) $path);
        }
        return [
            'ok' => true,
            'gallery_id' => (int) $conversion['gallery_id'],
            'image_ids' => $stored['image_ids'],
            'filenames' => $stored['filenames'],
            'manifest_sha256' => str_repeat('a', 64),
            'pdf_page_numbers' => $pages,
            'pdf_page_sha256' => $hashes,
        ];
    }

    /**
     * Remove a disposable lifecycle fixture tree without following symbolic links.
     *
     * @param string $path Absolute path beneath the test-owned temporary directory.
     * @return void
     */
    function gallery_pdf_conversion_test_remove_tree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (new DirectoryIterator($path) as $entry) {
            if (!$entry->isDot()) {
                gallery_pdf_conversion_test_remove_tree($entry->getPathname());
            }
        }
        rmdir($path);
    }

    /**
     * Stop the focused lifecycle contract test when one required assertion fails.
     *
     * @param bool $condition Expected contract result.
     * @param string $message Human-readable failure detail.
     * @return void
     */
    function gallery_pdf_conversion_test_assert(
        bool $condition,
        string $message,
    ): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $root =
        sys_get_temp_dir()
        . DIRECTORY_SEPARATOR
        . 'gallery-pdf-conversion-'
        . bin2hex(random_bytes(8));
    $GLOBALS['gallery_pdf_conversion_test_root'] = $root;
    $galleryPath = $root . DIRECTORY_SEPARATOR . 'source';
    $GLOBALS['gallery_pdf_conversion_test_next_id'] = 71;
    $GLOBALS['gallery_pdf_conversion_test_galleries'] = [71 => [
        'id' => 71,
        'parent_id' => 0,
        'folder_path' => 'source',
        'title' => 'Source',
        'visibility' => 'public',
    ]];
    $GLOBALS['gallery_pdf_conversion_test_images'] = [71 => []];
    $GLOBALS['gallery_pdf_conversion_test_documents'] = [];
    $GLOBALS['gallery_pdf_conversion_test_cached_batches'] = [];
    if (!mkdir($root, 0700) || !mkdir($galleryPath, 0700)) {
        throw new RuntimeException(
            'Could not prepare the PDF conversion marker fixture.',
        );
    }
    try {
        $gallery = ['id' => 71, 'folder_path' => 'source'];
        $conversionId = str_repeat('a', 64);
        $token = str_repeat('b', 64);
        $marker = [
            'format' => 'php_gallery_pdf_conversion_v1',
            'conversion_id' => $conversionId,
            'session_token' => $token,
            'session_token_sha256' => hash('sha256', $token),
            'source_gallery_id' => 71,
            'source_document_id' => str_repeat('c', 32),
            'source_sha256' => str_repeat('d', 64),
            'dpi' => 150,
            'page_count' => 3,
            'png' => [
                'extension' => 'png',
                'prefix' => 'pdf-abc12345-abc12345-page-',
                'digits' => 6,
            ],
            'target_mode' => 'child',
            'status' => 'uploading',
            'gallery_id' => 72,
            'child_gallery_id' => 72,
        ];
        \Gallery\Services\gallery_pdf_conversion_marker_write(
            $gallery,
            $marker,
        );
        $stored = \Gallery\Services\gallery_pdf_conversion_marker_read(
            $gallery,
            $conversionId,
        );
        gallery_pdf_conversion_test_assert(
            $stored === $marker,
            'The lifecycle marker did not round-trip without field changes.',
        );
        $found = \Gallery\Services\gallery_pdf_conversion_marker_find(
            $gallery,
            $token,
            72,
        );
        gallery_pdf_conversion_test_assert(
            is_array($found) && $found['marker'] === $marker,
            'The session token did not resolve its target-bound marker.',
        );
        gallery_pdf_conversion_test_assert(
            \Gallery\Services\gallery_pdf_conversion_marker_find(
                $gallery,
                str_repeat('e', 64),
                72,
            ) === null,
            'A different token resolved the marker.',
        );
        gallery_pdf_conversion_test_assert(
            \Gallery\Services\gallery_pdf_conversion_marker_find(
                $gallery,
                $token,
                73,
            ) === null,
            'The marker resolved for a different target gallery.',
        );

        $sourcePdf = $galleryPath . DIRECTORY_SEPARATOR . 'source.pdf';
        file_put_contents($sourcePdf, "%PDF-1.7\nfixture source\n");
        $ofpPdf = $galleryPath . DIRECTORY_SEPARATOR . 'simbrief-ofp.pdf';
        file_put_contents($ofpPdf, "%PDF-1.7\nfixture OFP source\n");
        $GLOBALS['gallery_pdf_conversion_test_ofp_path'] = $ofpPdf;
        $ofp = \Gallery\Services\gallery_pdf_conversion_source($gallery, 'ofp');
        $ofpBytes = (string) file_get_contents($ofpPdf);
        gallery_pdf_conversion_test_assert(
            $ofp['path'] === $ofpPdf
                && $ofp['metadata']['id'] === 'ofp'
                && $ofp['metadata']['provenance'] === 'simbrief_ofp'
                && $ofp['metadata']['size'] === strlen($ofpBytes)
                && hash_equals(
                    hash('sha256', $ofpBytes),
                    $ofp['metadata']['sha256'],
                )
                && $ofp['metadata']['created_at']
                    === $ofp['metadata']['updated_at'],
            'Reserved OFP source did not resolve to stable, content-verified provenance metadata.',
        );
        $ofpConversion = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            'ofp',
            96,
            1,
        );
        gallery_pdf_conversion_test_assert(
            $ofpConversion['status'] === 'uploading',
            'Reserved OFP source could not begin browser conversion.',
        );
        $sourceDocument = static function (string $id) use ($sourcePdf): void {
            $bytes = (string) file_get_contents($sourcePdf);
            $GLOBALS['gallery_pdf_conversion_test_documents'][$id] = [
                'path' => $sourcePdf,
                'metadata' => [
                    'id' => $id,
                    'size' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'filename' => 'source.pdf',
                    'title' => 'Source PDF',
                    'provenance' => 'fixture',
                    'created_at' => '2026-10-12T00:00:00Z',
                    'updated_at' => '2026-10-12T00:00:00Z',
                ],
            ];
        };
        $nextImageId = 1000;
        foreach ([1, 40, 41, 120, 151] as $caseIndex => $pageCount) {
            $documentId = str_pad(
                dechex($caseIndex + 1),
                32,
                '0',
                STR_PAD_LEFT,
            );
            $sourceDocument($documentId);
            $conversion = \Gallery\Services\gallery_pdf_conversion_begin(
                $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
                $documentId,
                150,
                $pageCount,
            );
            gallery_pdf_conversion_test_assert(
                $conversion['status'] === 'uploading'
                    && $conversion['target_mode'] === 'child',
                'Child conversion did not begin in uploading state.',
            );
            gallery_pdf_conversion_test_assert(
                $conversion['page_digits'] === max(
                    4,
                    strlen((string) $pageCount),
                ),
                'Conversion did not provide browser-compatible filename padding.',
            );
            $ack = gallery_pdf_conversion_test_store_pages(
                $conversion,
                $pageCount,
                $nextImageId,
            );
            \Gallery\Services\gallery_pdf_conversion_acknowledge_batch(
                $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
                $conversion['session_token'],
                $conversion['gallery_id'],
                gallery_pdf_conversion_test_ack_receipt(
                    $conversion,
                    $ack,
                    range(1, $pageCount),
                ),
                0,
                range(1, $pageCount),
            );
            if ($pageCount === 1) {
                $duplicate =
                    $GLOBALS['gallery_pdf_conversion_test_images'][$conversion['gallery_id']][0];
                $duplicate['id'] = $nextImageId++;
                $GLOBALS['gallery_pdf_conversion_test_images'][$conversion['gallery_id']][] =
                    $duplicate;
                try {
                    \Gallery\Services\gallery_pdf_conversion_finalize(
                        $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
                        $conversion['session_token'],
                        $conversion['gallery_id'],
                    );
                    throw new RuntimeException(
                        'A duplicate registered PDF page finalized successfully.',
                    );
                } catch (RuntimeException $error) {
                    gallery_pdf_conversion_test_assert(
                        str_contains(
                            $error->getMessage(),
                            'duplicate page registrations',
                        ),
                        'Unexpected duplicate-page refusal.',
                    );
                }
                array_pop(
                    $GLOBALS['gallery_pdf_conversion_test_images'][$conversion['gallery_id']],
                );
            }
            $finalized = \Gallery\Services\gallery_pdf_conversion_finalize(
                $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
                $conversion['session_token'],
                $conversion['gallery_id'],
            );
            gallery_pdf_conversion_test_assert(
                $finalized['complete']
                    && $finalized['page_count'] === $pageCount
                    && $finalized['image_ids'] === $ack['image_ids'],
                'Complete page sequence or generated image IDs did not finalize.',
            );
            if ($pageCount === 1) {
                $editedPath =
                    \Gallery\Services\gallery_abs_path(
                        (string) $GLOBALS['gallery_pdf_conversion_test_galleries'][$conversion['gallery_id']]['folder_path'],
                    )
                    . DIRECTORY_SEPARATOR
                    . $ack['filenames'][0];
                file_put_contents($editedPath, 'manual edit after completion');
            }
            $resumed = \Gallery\Services\gallery_pdf_conversion_begin(
                $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
                $documentId,
                150,
                $pageCount,
            );
            gallery_pdf_conversion_test_assert(
                $resumed['status'] === 'complete'
                    && $resumed['gallery_id'] === $conversion['gallery_id'],
                'Completed retry did not preserve the same child.',
            );
            if ($pageCount === 1) {
                gallery_pdf_conversion_test_assert(
                    file_get_contents($editedPath)
                        === 'manual edit after completion',
                    'A completed child edit was overwritten by a repeated begin.',
                );
            }
        }

        $lostAckId = str_repeat('7', 32);
        $sourceDocument($lostAckId);
        $lostAck = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $lostAckId,
            150,
            1,
        );
        $lostStored = gallery_pdf_conversion_test_store_pages(
            $lostAck,
            1,
            $nextImageId,
        );
        $lostReceipt = gallery_pdf_conversion_test_ack_receipt(
            $lostAck,
            $lostStored,
            [1],
        );
        $GLOBALS['gallery_pdf_conversion_test_cached_batches'][$lostAck['gallery_id']
            . ':'
            . $lostAck['session_token']
            . ':0'] = $lostReceipt;
        try {
            \Gallery\Services\gallery_pdf_conversion_acknowledge_batch(
                $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
                $lostAck['session_token'],
                $lostAck['gallery_id'],
                $lostReceipt,
                0,
                [2],
            );
            throw new RuntimeException(
                'A cached batch receipt acknowledged pages outside the validated ZIP page set.',
            );
        } catch (RuntimeException $error) {
            gallery_pdf_conversion_test_assert(
                str_contains(
                    $error->getMessage(),
                    'does not match the pages validated',
                ),
                'Unexpected refusal for a cached receipt with a different page set.',
            );
        }
        $reconciled = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $lostAckId,
            150,
            1,
        );
        gallery_pdf_conversion_test_assert(
            $reconciled['start_page'] === 2
                && $reconciled['next_batch_index'] === 1
                && $reconciled['status'] === 'uploading',
            'Reload did not reconcile an ordinary cached success whose lifecycle acknowledgment was lost.',
        );
        gallery_pdf_conversion_test_assert(
            count(
                $GLOBALS['gallery_pdf_conversion_test_images'][$lostAck['gallery_id']],
            ) === 1,
            'Cached lost-response reconciliation duplicated an already stored PDF page.',
        );
        $activeChild =
            $GLOBALS['gallery_pdf_conversion_test_galleries'][$lostAck['gallery_id']];
        gallery_pdf_conversion_test_assert(
            \Gallery\Services\gallery_pdf_publication_forces_private(
                $activeChild,
            ),
            'An active marker-bound child was not protected from publication.',
        );
        foreach (['public', 'unpublished', 'draft'] as $publishState) {
            try {
                \Gallery\Services\gallery_pdf_publication_assert_visibility_change(
                    $activeChild,
                    $publishState,
                );
                throw new RuntimeException(
                    'An active conversion child accepted non-private visibility.',
                );
            } catch (RuntimeException $error) {
                gallery_pdf_conversion_test_assert(
                    str_contains($error->getMessage(), 'must remain private'),
                    'An active child used an unexpected publication refusal.',
                );
            }
        }

        $appendId = str_repeat('f', 32);
        $sourceDocument($appendId);
        $existingPhoto = $galleryPath . DIRECTORY_SEPARATOR . 'existing.jpg';
        file_put_contents($existingPhoto, 'existing-photo-bytes');
        $GLOBALS['gallery_pdf_conversion_test_images'][71][] = [
            'id' => 999,
            'filename' => 'existing.jpg',
            'relative_path' => 'existing.jpg',
        ];
        $append = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $appendId,
            150,
            2,
            'append',
        );
        gallery_pdf_conversion_test_assert(
            $append['gallery_id'] === 71 && $append['child_gallery_id'] === 0,
            'Append mode did not target the source gallery.',
        );
        $appendAck = gallery_pdf_conversion_test_store_pages(
            $append,
            2,
            $nextImageId,
        );
        \Gallery\Services\gallery_pdf_conversion_acknowledge_batch(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $append['session_token'],
            71,
            gallery_pdf_conversion_test_ack_receipt(
                $append,
                $appendAck,
                [1, 2],
            ),
            0,
            [1, 2],
        );
        $appendResult = \Gallery\Services\gallery_pdf_conversion_finalize(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $append['session_token'],
            71,
        );
        gallery_pdf_conversion_test_assert(
            $appendResult['complete']
                && file_get_contents($existingPhoto) === 'existing-photo-bytes',
            'Append conversion modified an existing source image.',
        );
        \Gallery\Services\gallery_pdf_publication_assert_visibility_change(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            'public',
        );

        $cancelId = str_repeat('e', 32);
        $sourceDocument($cancelId);
        $cancel = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $cancelId,
            96,
            1,
        );
        $partial = gallery_pdf_conversion_test_store_pages(
            $cancel,
            1,
            $nextImageId,
        );
        $partialPath =
            \Gallery\Services\gallery_abs_path(
                (string) $GLOBALS['gallery_pdf_conversion_test_galleries'][$cancel['gallery_id']]['folder_path'],
            )
            . DIRECTORY_SEPARATOR
            . $partial['filenames'][0];
        try {
            \Gallery\Services\gallery_pdf_conversion_finalize(
                $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
                $cancel['session_token'],
                $cancel['gallery_id'],
            );
            throw new RuntimeException(
                'Unacknowledged partial pages finalized successfully.',
            );
        } catch (RuntimeException $error) {
            gallery_pdf_conversion_test_assert(
                str_contains(
                    $error->getMessage(),
                    'changed after its upload acknowledgment',
                ),
                'Unexpected partial-finalization refusal.',
            );
        }
        \Gallery\Services\gallery_pdf_conversion_acknowledge_batch(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $cancel['session_token'],
            $cancel['gallery_id'],
            gallery_pdf_conversion_test_ack_receipt($cancel, $partial, [1]),
            0,
            [1],
        );
        file_put_contents($partialPath, 'manual edit during conversion');
        try {
            \Gallery\Services\gallery_pdf_conversion_begin(
                $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
                $cancelId,
                96,
                1,
            );
            throw new RuntimeException(
                'An edited in-progress child was allowed to resume.',
            );
        } catch (RuntimeException $error) {
            gallery_pdf_conversion_test_assert(
                str_contains(
                    $error->getMessage(),
                    'previously acknowledged page was edited',
                ),
                'Unexpected edited-child refusal.',
            );
        }
        $cancelled = \Gallery\Services\gallery_pdf_conversion_cancel(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $cancel['session_token'],
            $cancel['gallery_id'],
        );
        gallery_pdf_conversion_test_assert(
            $cancelled['cancelled']
                && file_get_contents($partialPath)
                    === 'manual edit during conversion',
            'Cancellation deleted or failed to preserve partial private pages.',
        );
        $cancelledChild =
            $GLOBALS['gallery_pdf_conversion_test_galleries'][$cancel['gallery_id']];
        gallery_pdf_conversion_test_assert(
            \Gallery\Services\gallery_pdf_publication_forces_private(
                $cancelledChild,
            ),
            'A cancelled conversion child was not protected from publication.',
        );
        $cancelledRetry = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $cancelId,
            96,
            1,
        );
        gallery_pdf_conversion_test_assert(
            $cancelledRetry['status'] === 'cancelled',
            'A normal begin request silently resumed a cancelled conversion.',
        );
        $resumeId = str_repeat('8', 32);
        $sourceDocument($resumeId);
        $resumable = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $resumeId,
            96,
            1,
        );
        $resumableCancel = \Gallery\Services\gallery_pdf_conversion_cancel(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $resumable['session_token'],
            $resumable['gallery_id'],
        );
        $resumedCancelled = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $resumeId,
            96,
            1,
            'child',
            null,
            false,
            true,
        );
        gallery_pdf_conversion_test_assert(
            $resumableCancel['cancelled']
                && $resumedCancelled['status'] === 'uploading'
                && $resumedCancelled['gallery_id'] === $resumable['gallery_id'],
            'Explicit begin did not safely resume the same unchanged cancelled target.',
        );

        $changedId = str_repeat('9', 32);
        $sourceDocument($changedId);
        $original = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $changedId,
            200,
            1,
        );
        $oldChildImage = gallery_pdf_conversion_test_store_pages(
            $original,
            1,
            $nextImageId,
        );
        $oldChildPath =
            \Gallery\Services\gallery_abs_path(
                (string) $GLOBALS['gallery_pdf_conversion_test_galleries'][$original['gallery_id']]['folder_path'],
            )
            . DIRECTORY_SEPARATOR
            . $oldChildImage['filenames'][0];
        file_put_contents($sourcePdf, "%PDF-1.7\nchanged source\n");
        $sourceDocument($changedId);
        try {
            \Gallery\Services\gallery_pdf_conversion_begin(
                $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
                $changedId,
                200,
                1,
            );
            throw new RuntimeException(
                'A changed source PDF did not require explicit confirmation.',
            );
        } catch (RuntimeException $error) {
            gallery_pdf_conversion_test_assert(
                str_contains($error->getMessage(), 'Explicitly confirm'),
                'Unexpected changed-source refusal.',
            );
        }
        $newSource = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $changedId,
            200,
            1,
            'child',
            null,
            true,
        );
        gallery_pdf_conversion_test_assert(
            $newSource['gallery_id'] !== $original['gallery_id']
                && is_file($oldChildPath),
            'Confirmed source replacement overwrote the prior child.',
        );

        $deletedCompleteId = str_repeat('6', 32);
        $sourceDocument($deletedCompleteId);
        $deletedComplete = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $deletedCompleteId,
            150,
            1,
        );
        $deletedCompletePages = gallery_pdf_conversion_test_store_pages(
            $deletedComplete,
            1,
            $nextImageId,
        );
        \Gallery\Services\gallery_pdf_conversion_acknowledge_batch(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $deletedComplete['session_token'],
            $deletedComplete['gallery_id'],
            gallery_pdf_conversion_test_ack_receipt(
                $deletedComplete,
                $deletedCompletePages,
                [1],
            ),
            0,
            [1],
        );
        \Gallery\Services\gallery_pdf_conversion_finalize(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $deletedComplete['session_token'],
            $deletedComplete['gallery_id'],
        );
        $deletedCompleteFolder =
            (string) $GLOBALS['gallery_pdf_conversion_test_galleries'][$deletedComplete['gallery_id']]['folder_path'];
        $deletedCompleteMarker = \Gallery\Services\gallery_pdf_conversion_marker_read(
            $gallery,
            $deletedComplete['conversion_id'],
        );
        gallery_pdf_conversion_test_remove_tree(\Gallery\Services\gallery_abs_path(
            $deletedCompleteFolder,
        ));
        unset(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][$deletedComplete['gallery_id']],
            $GLOBALS['gallery_pdf_conversion_test_images'][$deletedComplete['gallery_id']],
        );
        try {
            \Gallery\Services\gallery_pdf_conversion_begin(
                $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
                $deletedCompleteId,
                150,
                1,
            );
            throw new RuntimeException(
                'A missing completed child resumed without explicit confirmation.',
            );
        } catch (RuntimeException $error) {
            gallery_pdf_conversion_test_assert(
                str_contains($error->getMessage(), 'unavailable'),
                'A missing completed child used an unexpected refusal.',
            );
        }
        $replacement = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $deletedCompleteId,
            150,
            1,
            'child',
            null,
            true,
        );
        $replacementMarker = \Gallery\Services\gallery_pdf_conversion_marker_read(
            $gallery,
            $replacement['conversion_id'],
        );
        gallery_pdf_conversion_test_assert(
            $replacement['created']
                && $replacement['gallery_id'] !== $deletedComplete['gallery_id']
                && $replacement['conversion_id']
                    !== $deletedComplete['conversion_id']
                && $replacementMarker !== null
                && ($replacementMarker['render_key'] ?? '')
                    === (
                        $deletedCompleteMarker['render_key']
                        ?? $deletedComplete['conversion_id']
                    )
                && (int) ($replacementMarker['generation'] ?? 0) === 1
                && \Gallery\Services\gallery_pdf_conversion_marker_read(
                    $gallery,
                    $deletedComplete['conversion_id'],
                ) === $deletedCompleteMarker,
            'Confirmed recovery did not preserve the completed marker and create a separately identified generation.',
        );
        $replacementRetry = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $deletedCompleteId,
            150,
            1,
        );
        gallery_pdf_conversion_test_assert(
            $replacementRetry['gallery_id'] === $replacement['gallery_id']
                && $replacementRetry['conversion_id']
                    === $replacement['conversion_id']
                && !$replacementRetry['created'],
            'A retry did not resume the newest confirmed generation idempotently.',
        );

        $emptyOrphanId = str_repeat('5', 32);
        $sourceDocument($emptyOrphanId);
        $emptyBase = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $emptyOrphanId,
            150,
            1,
        );
        $GLOBALS['gallery_pdf_conversion_test_galleries'][$emptyBase['gallery_id']]['visibility'] = 'public';
        $emptyGenerationOne = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $emptyOrphanId,
            150,
            1,
            'child',
            null,
            true,
        );
        $emptyBaseMarker = \Gallery\Services\gallery_pdf_conversion_marker_read(
            $gallery,
            $emptyBase['conversion_id'],
        );
        $emptyGenerationOneMarker = \Gallery\Services\gallery_pdf_conversion_marker_read(
            $gallery,
            $emptyGenerationOne['conversion_id'],
        );
        $emptyGenerationOnePath = \Gallery\Services\gallery_pdf_conversion_marker_path(
            $gallery,
            $emptyGenerationOne['conversion_id'],
        );
        $emptyGenerationOneFolder =
            (string) $GLOBALS['gallery_pdf_conversion_test_galleries'][$emptyGenerationOne['gallery_id']]['folder_path'];
        gallery_pdf_conversion_test_remove_tree(\Gallery\Services\gallery_abs_path(
            $emptyGenerationOneFolder,
        ));
        unset(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][$emptyGenerationOne['gallery_id']],
            $GLOBALS['gallery_pdf_conversion_test_images'][$emptyGenerationOne['gallery_id']],
        );
        $emptyGenerationTwo = \Gallery\Services\gallery_pdf_conversion_begin(
            $GLOBALS['gallery_pdf_conversion_test_galleries'][71],
            $emptyOrphanId,
            150,
            1,
        );
        $emptyGenerationTwoMarker = \Gallery\Services\gallery_pdf_conversion_marker_read(
            $gallery,
            $emptyGenerationTwo['conversion_id'],
        );
        gallery_pdf_conversion_test_assert(
            (int) ($emptyGenerationOneMarker['generation'] ?? 0) === 1
                && (int) ($emptyGenerationTwoMarker['generation'] ?? 0) === 2
                && $emptyGenerationTwo['conversion_id']
                    !== $emptyBase['conversion_id']
                && \Gallery\Services\gallery_pdf_conversion_marker_read(
                    $gallery,
                    $emptyBase['conversion_id'],
                ) === $emptyBaseMarker
                && !is_file($emptyGenerationOnePath)
                && count(
                    glob($emptyGenerationOnePath . '.recovered-*.json') ?: [],
                ) === 1,
            'Empty orphan recovery reused a prior generation identity or failed to preserve its archived marker.',
        );

        $completeChild =
            $GLOBALS['gallery_pdf_conversion_test_galleries'][$conversion['gallery_id']];
        gallery_pdf_conversion_test_assert(
            !\Gallery\Services\gallery_pdf_publication_forces_private(
                $completeChild,
            ),
            'A complete conversion child remained forced-private.',
        );
        \Gallery\Services\gallery_pdf_publication_assert_visibility_change(
            $completeChild,
            'public',
        );
        $reservedName = 'pdf-pages-' . str_repeat('a', 64);
        $orphanChild = [
            'id' => 900,
            'parent_id' => 71,
            'folder_path' => 'source/' . $reservedName,
            'visibility' => 'unpublished',
        ];
        gallery_pdf_conversion_test_assert(
            \Gallery\Services\gallery_pdf_publication_forces_private(
                $orphanChild,
            ),
            'A reserved child without a valid owner marker did not fail closed.',
        );
        $unrelated = [
            'id' => 901,
            'parent_id' => 0,
            'folder_path' => $reservedName,
            'visibility' => 'public',
        ];
        gallery_pdf_conversion_test_assert(
            !\Gallery\Services\gallery_pdf_publication_forces_private(
                $unrelated,
            ),
            'An unrelated gallery with a reserved-looking name was coerced private.',
        );
        gallery_pdf_conversion_test_assert(
            \Gallery\Services\gallery_pdf_conversion_restart_available(
                new RuntimeException(
                    'The existing conversion target is unavailable or no longer private.',
                ),
            )
                && \Gallery\Services\gallery_pdf_conversion_restart_available(
                    new RuntimeException(
                        'The reserved PDF page folder exists outside the gallery catalog. Reconcile it before conversion.',
                    ),
                )
                && !\Gallery\Services\gallery_pdf_conversion_restart_available(
                    new RuntimeException(
                        'The source PDF changed or failed integrity validation.',
                    ),
                ),
            'Fresh-generation availability classification included an unrelated PDF failure or missed a target failure.',
        );
        try {
            \Gallery\Services\gallery_pdf_conversion_marker_path(
                $gallery,
                'invalid',
            );
            throw new RuntimeException(
                'A malformed conversion ID was accepted.',
            );
        } catch (RuntimeException $error) {
            gallery_pdf_conversion_test_assert(
                str_contains($error->getMessage(), 'identity is malformed'),
                'Unexpected malformed-ID refusal.',
            );
        }
    } finally {
        gallery_pdf_conversion_test_remove_tree($root);
    }
}
