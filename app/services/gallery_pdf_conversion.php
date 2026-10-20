<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/gallery_pdf_conversion.php
 * Module Type: Service Module
 *
 * Purpose:
 *   Own browser-rendered PDF page conversion lifecycle for private children and source-gallery appends.
 *
 * Responsibilities:
 *   - Bind a conversion to an indexed source PDF, its content hash and render settings
 *   - Create and protect an ordinary private physical child or append to the source gallery
 *   - Validate prepared browser batches and require complete registered pages to finalize
 *   - Preserve completed or manually changed child galleries during retries
 *
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes:
 *   - PNG ingestion remains owned by the ordinary browser upload service.
 *   - Keep comments and docstrings intact when modifying this file.
 * Last Updated: 2026-10-12
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use function Gallery\Core\normalize_relative_path;

require_once dirname(__DIR__) . '/policy_constants.php';

/**
 * Return the source-side lifecycle marker path for one conversion identity.
 *
 * @param array<string,mixed> $gallery Persisted source gallery row with folder_path.
 * @param string $conversionId Stable 64-character conversion identity.
 * @return string Absolute source-side marker path owned by this service.
 */
function gallery_pdf_conversion_marker_path(
    array $gallery,
    string $conversionId,
): string {
    if (preg_match('/^[a-f0-9]{64}$/D', $conversionId) !== 1) {
        throw new RuntimeException('The PDF conversion identity is malformed.');
    }
    $galleryPath = gallery_abs_path((string) ($gallery['folder_path'] ?? ''));
    $galleryRoot = realpath($galleryPath);
    if (
        $galleryRoot === false
        || is_link($galleryPath)
        || !is_dir($galleryRoot)
    ) {
        throw new RuntimeException(
            'The source gallery directory is unavailable for conversion state.',
        );
    }
    return (
        $galleryRoot
            . DIRECTORY_SEPARATOR
            . '.gallery-pdf-conversions'
            . DIRECTORY_SEPARATOR
            . $conversionId
            . '.json'
    );
}

/**
 * Read a bounded source-side conversion marker without following links.
 *
 * @param array<string,mixed> $gallery Persisted source gallery row.
 * @param string $conversionId Stable conversion identity.
 * @return array{format:string,conversion_id:string,render_key?:string,generation?:int,session_token:string,session_token_sha256:string,source_gallery_id:int,source_document_id:string,source_sha256:string,source_document_metadata?:array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string},dpi:int,page_count:int,png:array{extension:string,prefix:string,digits:int},target_mode:'child'|'append',status:'uploading'|'complete'|'cancelled',child_gallery_id:int,gallery_id:int,created_at?:string,updated_at?:string,completed_at?:string,cancelled_at?:string,acknowledged_image_ids?:list<int>,acknowledged_pages?:array<int,string>,next_batch_index?:int,registered_page_ids?:list<int>}|null Valid v1 lifecycle marker or null when absent or malformed.
 */
function gallery_pdf_conversion_marker_read(
    array $gallery,
    string $conversionId,
): ?array {
    $path = gallery_pdf_conversion_marker_path($gallery, $conversionId);
    $galleryRoot = realpath(gallery_abs_path(
        (string) ($gallery['folder_path'] ?? ''),
    ));
    $directory = dirname($path);
    $resolvedDirectory = realpath($directory);
    // Bound one administrator-side state read while allowing a large per-page hash map without imposing a PDF page-count limit.
    // Type: int. Units: bytes. Scope: one lifecycle marker JSON document.
    // Consumers: gallery_pdf_conversion_marker_read.
    // Rationale: 64 MiB contains extensive page receipts while limiting one corrupted sidecar read.
    $maximumMarkerBytes = \Gallery\Core\GALLERY_PDF_CONVERSION_MAX_MARKER_BYTES;
    if (
        $galleryRoot === false
        || is_link($directory)
        || $resolvedDirectory
            !== $galleryRoot . DIRECTORY_SEPARATOR . '.gallery-pdf-conversions'
        || !is_file($path)
        || is_link($path)
        || (int) @filesize($path) > $maximumMarkerBytes
    ) {
        return null;
    }
    $decoded = json_decode((string) @file_get_contents($path), true);
    if (
        !is_array($decoded)
        || ($decoded['format'] ?? '') !== 'php_gallery_pdf_conversion_v1'
        || !is_string($decoded['conversion_id'] ?? null)
        || $decoded['conversion_id'] !== $conversionId
        || preg_match('/^[a-f0-9]{64}$/D', $decoded['conversion_id']) !== 1
        || !is_string($decoded['session_token'] ?? null)
        || !is_string($decoded['session_token_sha256'] ?? null)
        || !is_int($decoded['source_gallery_id'] ?? null)
        || !is_string($decoded['source_document_id'] ?? null)
        || !is_string($decoded['source_sha256'] ?? null)
        || (
            array_key_exists('render_key', $decoded)
            && (
                !is_string($decoded['render_key'])
                || preg_match('/^[a-f0-9]{64}$/D', $decoded['render_key']) !== 1
            )
        )
        || (
            array_key_exists('generation', $decoded)
            && (
                !is_int($decoded['generation'])
                || $decoded['generation'] < 0
            )
        )
        || !is_int($decoded['dpi'] ?? null)
        || !is_int($decoded['page_count'] ?? null)
        || !is_array($decoded['png'] ?? null)
        || !is_string($decoded['png']['extension'] ?? null)
        || !is_string($decoded['png']['prefix'] ?? null)
        || !is_int($decoded['png']['digits'] ?? null)
        || !in_array($decoded['target_mode'] ?? null, ['child', 'append'], true)
        || !in_array(
            $decoded['status'] ?? null,
            ['uploading', 'complete', 'cancelled'],
            true,
        )
        || !is_int($decoded['child_gallery_id'] ?? null)
        || !is_int($decoded['gallery_id'] ?? null)
    ) {
        return null;
    }
    return $decoded;
}

/**
 * Persist conversion state atomically in the source gallery's denied sidecar directory.
 *
 * @param array<string,mixed> $gallery Persisted source gallery row.
 * @param array{format:string,conversion_id:string,render_key?:string,generation?:int,session_token:string,session_token_sha256:string,source_gallery_id:int,source_document_id:string,source_sha256:string,source_document_metadata?:array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string},dpi:int,page_count:int,png:array{extension:string,prefix:string,digits:int},target_mode:'child'|'append',status:'uploading'|'complete'|'cancelled',child_gallery_id:int,gallery_id:int,created_at?:string,updated_at?:string,completed_at?:string,cancelled_at?:string,acknowledged_image_ids?:list<int>,acknowledged_pages?:array<string,string>,next_batch_index?:int,registered_page_ids?:list<int>} $marker Complete lifecycle marker fields.
 * @return void
 * @throws RuntimeException When the marker cannot be safely written.
 */
function gallery_pdf_conversion_marker_write(
    array $gallery,
    array $marker,
): void {
    $conversionId = (string) ($marker['conversion_id'] ?? '');
    $path = gallery_pdf_conversion_marker_path($gallery, $conversionId);
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true)) {
        throw new RuntimeException(
            'Could not prepare private PDF conversion state storage.',
        );
    }
    $galleryRoot = realpath(gallery_abs_path(
        (string) ($gallery['folder_path'] ?? ''),
    ));
    $resolvedDirectory = realpath($directory);
    if (
        $galleryRoot === false
        || is_link($directory)
        || $resolvedDirectory
            !== $galleryRoot . DIRECTORY_SEPARATOR . '.gallery-pdf-conversions'
    ) {
        throw new RuntimeException(
            'PDF conversion state directory escapes the source gallery.',
        );
    }
    $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.json';
    $json =
        json_encode(
            $marker,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . "\n";
    if (
        @file_put_contents($temporary, $json, LOCK_EX) === false
        || !@rename($temporary, $path)
    ) {
        @unlink($temporary);
        throw new RuntimeException('Could not persist PDF conversion state.');
    }
}

/**
 * Find one source-side conversion record owned by the supplied session token.
 *
 * @param array<string,mixed> $parent Persisted source gallery row.
 * @param string $token Conversion session token.
 * @param int $galleryId Expected upload target gallery ID.
 * @return array{marker:array{format:string,conversion_id:string,render_key?:string,generation?:int,session_token:string,session_token_sha256:string,source_gallery_id:int,source_document_id:string,source_sha256:string,source_document_metadata?:array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string},dpi:int,page_count:int,png:array{extension:string,prefix:string,digits:int},target_mode:'child'|'append',status:'uploading'|'complete'|'cancelled',child_gallery_id:int,gallery_id:int,created_at?:string,updated_at?:string,completed_at?:string,cancelled_at?:string,acknowledged_image_ids?:list<int>,acknowledged_pages?:array<string,string>,next_batch_index?:int,registered_page_ids?:list<int>},path:string}|null Matching marker and path, or null when no conversion owns the token.
 */
function gallery_pdf_conversion_marker_find(
    array $parent,
    string $token,
    int $galleryId,
): ?array {
    $directory =
        gallery_abs_path((string) ($parent['folder_path'] ?? ''))
        . DIRECTORY_SEPARATOR
        . '.gallery-pdf-conversions';
    foreach (glob(
        $directory . DIRECTORY_SEPARATOR . str_repeat('[a-f0-9]', 64) . '.json',
    ) ?: [] as $path) {
        $name = basename($path, '.json');
        $marker = gallery_pdf_conversion_marker_read($parent, $name);
        if (
            $marker !== null
            && (int) ($marker['gallery_id'] ?? 0) === $galleryId
            && hash_equals((string) (
                $marker['session_token_sha256'] ?? ''
            ), hash('sha256', $token))
        ) {
            return ['marker' => $marker, 'path' => $path];
        }
    }
    return null;
}

/**
 * Resolve the canonical indexed source PDF and verify the document remains unchanged.
 *
 * @param array<string,mixed> $parent Persisted source gallery row.
 * @param string $documentId Stable gallery PDF document identifier.
 * @param string|null $expectedHash Optional expected SHA-256 carried by a saved conversion marker.
 * @return array{path:string,metadata:array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string}} Validated PDF path and indexed document metadata.
 * @throws RuntimeException When the document is absent, malformed or changed.
 */
function gallery_pdf_conversion_source(
    array $parent,
    string $documentId,
    ?string $expectedHash = null,
): array {
    // Direct conversion consumers need the same confined gallery path owner as the delivery route.
    if (
        !function_exists(__NAMESPACE__ . '\\gallery_abs_path')
        && !function_exists(__NAMESPACE__ . '\\galleries_root')
    ) {
        require_once __DIR__ . '/gallery_paths.php';
    }
    if ($documentId === 'ofp') {
        // The Admin PDF-action request does not render the editor tab that normally loads the OFP attachment owner.
        if (!function_exists(__NAMESPACE__ . '\\simbrief_ofp_local_pdf_path')) {
            require_once __DIR__ . '/simbrief_ofp_attachments.php';
        }
        if (!function_exists(__NAMESPACE__ . '\\simbrief_ofp_local_pdf_path')) {
            throw new RuntimeException(
                'The SimBrief OFP PDF source is unavailable.',
            );
        }
        $path = (string) (simbrief_ofp_local_pdf_path($parent) ?? '');
        $modifiedAt = $path !== '' && is_file($path)
            ? @filemtime($path)
            : false;
        $size = $path !== '' && is_file($path) ? @filesize($path) : false;
        $hash = $path !== '' && is_file($path)
            ? @hash_file('sha256', $path)
            : false;
        if (
            $path === ''
            || !is_file($path)
            || is_link($path)
            || !is_int($modifiedAt)
            || !is_int($size)
            || !is_string($hash)
        ) {
            throw new RuntimeException(
                'The selected SimBrief OFP PDF is unavailable.',
            );
        }
        $metadata = [
            'id' => 'ofp',
            'size' => $size,
            'sha256' => strtolower($hash),
            'filename' => 'simbrief-ofp.pdf',
            'title' => 'SimBrief OFP',
            'provenance' => 'simbrief_ofp',
            'created_at' => gmdate('c', $modifiedAt),
            'updated_at' => gmdate('c', $modifiedAt),
        ];
    } else {
        if (!function_exists(__NAMESPACE__ . '\\gallery_pdf_documents_read')) {
            require_once __DIR__ . '/gallery_pdf_documents.php';
        }
        if (!function_exists(__NAMESPACE__ . '\\gallery_pdf_documents_read')) {
            throw new RuntimeException('PDF document storage is unavailable.');
        }
        $document = gallery_pdf_documents_read($parent, $documentId);
        if (!is_array($document) || !is_array($document['metadata'] ?? null)) {
            throw new RuntimeException(
                'The selected source PDF is unavailable.',
            );
        }
        $metadata = $document['metadata'];
        $path = (string) ($document['path'] ?? '');
    }
    $hash = strtolower((string) ($metadata['sha256'] ?? ''));
    if (
        (string) ($metadata['id'] ?? '') !== $documentId
        || $path === ''
        || !is_file($path)
        || is_link($path)
        || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
        || !hash_equals($hash, (string) hash_file('sha256', $path))
        || (
            $expectedHash !== null
            && !hash_equals($expectedHash, $hash)
        )
    ) {
        throw new RuntimeException(
            'The source PDF changed or failed integrity validation.',
        );
    }
    return ['path' => $path, 'metadata' => $metadata];
}

/**
 * Archive an empty child-conversion marker whose target gallery and folder disappeared.
 *
 * Submitting Convert again for the exact deterministic source and render key
 * authorizes recovery of an empty missing target without the separate
 * changed-settings confirmation. Any marker with progress, a cached success,
 * registered images, or a remaining child folder is preserved.
 *
 * @param array{id:int,folder_path:string} $parent Authoritative source gallery identity and relative folder.
 * @param array{conversion_id:string,gallery_id:int,target_mode:'child'|'append',status:'uploading'|'cancelled'|'complete',source_gallery_id:int,source_document_id:string,child_gallery_id:int,acknowledged_pages?:array<int,string>,acknowledged_image_ids?:list<int>,registered_page_ids?:list<int>,next_batch_index?:int,session_token:string} $marker Valid source-side identity, progress and receipt coordinates.
 * @param string $documentId Source document identity selected by this request.
 * @return bool True when the marker was safely archived; false when it is not an empty missing child.
 * @throws RuntimeException When its state cannot be safely inspected or the archive rename fails.
 */
function gallery_pdf_conversion_archive_empty_orphan(
    array $parent,
    array $marker,
    string $documentId,
): bool {
    $conversionId = (string) ($marker['conversion_id'] ?? '');
    $targetId = (int) ($marker['gallery_id'] ?? 0);
    if (
        ($marker['target_mode'] ?? '') !== 'child'
        || !in_array($marker['status'] ?? '', ['uploading', 'cancelled'], true)
        || (int) ($marker['source_gallery_id'] ?? 0)
            !== (int) ($parent['id'] ?? 0)
        || (string) ($marker['source_document_id'] ?? '') !== $documentId
        || (int) ($marker['child_gallery_id'] ?? 0) !== $targetId
        || $targetId < 1
        || !is_array($marker['acknowledged_pages'] ?? [])
        || ($marker['acknowledged_pages'] ?? []) !== []
        || !is_array($marker['acknowledged_image_ids'] ?? [])
        || ($marker['acknowledged_image_ids'] ?? []) !== []
        || !is_array($marker['registered_page_ids'] ?? [])
        || ($marker['registered_page_ids'] ?? []) !== []
        || (int) ($marker['next_batch_index'] ?? 0) !== 0
    ) {
        return false;
    }

    if (find_gallery($targetId, true) !== null) {
        return false;
    }
    $childFolder = gallery_child_folder_path(
        $parent,
        'pdf-pages-' . $conversionId,
    );
    $childPath = gallery_abs_path($childFolder);
    if (
        file_exists($childPath)
        || is_link($childPath)
        || gallery_images($targetId, false) !== []
    ) {
        return false;
    }

    if (!function_exists(__NAMESPACE__
        . '\\browser_upload_cached_batch_response')) {
        require_once __DIR__ . '/browser_uploads.php';
    }
    if (
        !function_exists(__NAMESPACE__
            . '\\browser_upload_cached_batch_response')
        || browser_upload_cached_batch_response(
            $targetId,
            (string) ($marker['session_token'] ?? ''),
            0,
        ) !== null
    ) {
        return false;
    }

    $markerPath = gallery_pdf_conversion_marker_path($parent, $conversionId);
    $directory = dirname($markerPath);
    $galleryRoot = realpath(gallery_abs_path(
        (string) ($parent['folder_path'] ?? ''),
    ));
    if (
        $galleryRoot === false
        || is_link($directory)
        || realpath($directory)
            !== $galleryRoot . DIRECTORY_SEPARATOR . '.gallery-pdf-conversions'
        || !is_file($markerPath)
        || is_link($markerPath)
    ) {
        throw new RuntimeException(
            'The missing PDF child marker could not be safely archived.',
        );
    }
    // Keep the JSON record denied beside active markers while ensuring the active-marker glob cannot rediscover it.
    // Type: string. Units: filename suffix with a UTC timestamp and twelve hexadecimal characters.
    // Scope: one explicitly confirmed empty-target recovery archive.
    // Consumers: the filesystem rename below; active marker discovery excludes this name.
    // Rationale: the random suffix separates repeated recoveries within one second; it is not a session credential.
    $archivePath =
        $markerPath
        . '.recovered-'
        . gmdate('YmdHis')
        . '-'
        . bin2hex(random_bytes(6))
        . '.json';
    if (!@rename($markerPath, $archivePath)) {
        throw new RuntimeException(
            'The empty orphaned PDF child marker could not be archived.',
        );
    }
    return true;
}

/**
 * Begin or resume one browser PDF conversion in a reserved private child gallery.
 *
 * The source PDF is resolved by its opaque document ID and hashed on the server.
 * Repeated starts for the same document, DPI and page count return the same active
 * child and token; completed children are returned read-only.
 *
 * @param array<string,mixed> $sourceGallery Persisted parent gallery row.
 * @param string $documentId Opaque source PDF document ID, including reserved ID "ofp" when applicable.
 * @param int $dpi Browser rendering resolution in dots per inch.
 * @param int $pageCount Positive page count reported by the browser PDF parser.
 * @param string $targetMode Whether generated PNGs go into a private child or append to the source gallery.
 * @param string|null $sessionToken Optional caller-retained token for idempotent resume of the same conversion.
 * @param bool $allowNew Whether the caller confirmed new output after changed source/settings or an unavailable child target.
 * @param bool $resumeCancelled Whether this explicit begin request resumes a cancelled, otherwise unchanged conversion.
 * @return array{conversion_id:string,session_token:string,session_id:string,gallery_id:int,child_gallery_id:int,target_mode:string,page_count:int,prefix:string,page_digits:int,start_page:int,next_batch_index:int,status:string,created:bool} Authenticated lifecycle coordinates and target gallery identity.
 * @throws RuntimeException When inputs are invalid or the existing target is unavailable, changed or immutable.
 */
function gallery_pdf_conversion_begin(
    array $sourceGallery,
    string $documentId,
    int $dpi,
    int $pageCount,
    string $targetMode = 'child',
    ?string $sessionToken = null,
    bool $allowNew = false,
    bool $resumeCancelled = false,
): array {
    $writer = gallery_edit_writer_begin();
    try {
        $parent = find_gallery((int) ($sourceGallery['id'] ?? 0), true);
        if (
            !$parent
            || $dpi < 1
            || $pageCount < 1
            || $documentId === ''
            || !in_array($targetMode, ['child', 'append'], true)
        ) {
            throw new RuntimeException(
                'A valid source PDF, DPI and page count are required.',
            );
        }
        $dpi = gallery_pdf_render_dpi_validate($dpi);
        if (
            $sessionToken !== null
            && preg_match('/^[a-f0-9]{64}$/D', $sessionToken) !== 1
        ) {
            throw new RuntimeException(
                'The PDF conversion session token is malformed.',
            );
        }
        $source = gallery_pdf_conversion_source($parent, $documentId);
        $sourceHash = (string) $source['metadata']['sha256'];
        $renderKey = hash(
            'sha256',
            (int) $parent['id']
                . ':'
                . $documentId
                . ':'
                . $sourceHash
                . ':'
                . $dpi
                . ':'
                . $pageCount
                . ':'
                . $targetMode,
        );
        $conversionKey = $renderKey;
        $existingMarker = gallery_pdf_conversion_marker_read(
            $parent,
            $conversionKey,
        );
        $markerDirectory =
            gallery_abs_path((string) $parent['folder_path'])
            . DIRECTORY_SEPARATOR
            . '.gallery-pdf-conversions';
        $latestGeneration = 0;
        foreach (glob(
            $markerDirectory
                . DIRECTORY_SEPARATOR
                . str_repeat('[a-f0-9]', 64)
                . '.json',
        ) ?: [] as $markerPath) {
            $candidateMarker = gallery_pdf_conversion_marker_read($parent, basename(
                $markerPath,
                '.json',
            ));
            if (
                $candidateMarker === null
                || (string) ($candidateMarker['source_document_id'] ?? '')
                    !== $documentId
                || (string) ($candidateMarker['source_sha256'] ?? '')
                    !== $sourceHash
                || (int) ($candidateMarker['dpi'] ?? 0) !== $dpi
                || (int) ($candidateMarker['page_count'] ?? 0) !== $pageCount
                || (string) ($candidateMarker['target_mode'] ?? '')
                    !== $targetMode
            ) {
                continue;
            }
            $candidateRenderKey = (string) (
                $candidateMarker['render_key']
                ?? $candidateMarker['conversion_id']
                ?? ''
            );
            if (!hash_equals($renderKey, $candidateRenderKey)) {
                continue;
            }
            $candidateGeneration = max(
                0,
                (int) ($candidateMarker['generation'] ?? 0),
            );
            $latestGeneration = max($latestGeneration, $candidateGeneration);
            if (
                $existingMarker === null
                || $candidateGeneration
                    > (int) ($existingMarker['generation'] ?? 0)
            ) {
                $existingMarker = $candidateMarker;
            }
        }
        $recoveredEmptyOrphan = false;
        if (
            $existingMarker !== null
            && $targetMode === 'child'
            && find_gallery((int) ($existingMarker['gallery_id'] ?? 0), true)
                === null
            && gallery_pdf_conversion_archive_empty_orphan(
                $parent,
                $existingMarker,
                $documentId,
            )
        ) {
            $existingMarker = null;
            $recoveredEmptyOrphan = true;
            $sessionToken = null;
        }
        $startNewGeneration = $recoveredEmptyOrphan && $latestGeneration > 0;
        if ($existingMarker !== null && $targetMode === 'child') {
            $existingTarget = find_gallery(
                (int) ($existingMarker['gallery_id'] ?? 0),
                true,
            );
            $targetUnavailable =
                $existingTarget === null
                || (
                    ($existingMarker['status'] ?? '') !== 'complete'
                    && gallery_effective_visibility($existingTarget)
                    !== 'private'
                );
            if ($targetUnavailable && $allowNew) {
                $startNewGeneration = true;
                $existingMarker = null;
                $sessionToken = null;
            }
        }
        // Recovering this exact source/render key retains independent append or
        // different-resolution results without requiring a new-result override.
        if ($existingMarker === null && !$allowNew && !$recoveredEmptyOrphan) {
            foreach (glob(
                $markerDirectory
                    . DIRECTORY_SEPARATOR
                    . str_repeat('[a-f0-9]', 64)
                    . '.json',
            ) ?: [] as $markerPath) {
                $candidateMarker = gallery_pdf_conversion_marker_read($parent, basename(
                    $markerPath,
                    '.json',
                ));
                if (
                    $candidateMarker !== null
                    && (string) ($candidateMarker['source_document_id'] ?? '')
                        === $documentId
                    && (
                        (string) ($candidateMarker['source_sha256'] ?? '')
                        !== $sourceHash
                        || (int) ($candidateMarker['dpi'] ?? 0) !== $dpi
                        || (int) ($candidateMarker['page_count'] ?? 0)
                        !== $pageCount
                        || (string) ($candidateMarker['target_mode'] ?? '')
                        !== $targetMode
                    )
                ) {
                    throw new RuntimeException(
                        'A conversion with different render settings already exists. Explicitly confirm a new conversion before continuing.',
                    );
                }
            }
        }
        if ($allowNew && $existingMarker === null && $targetMode === 'child') {
            foreach (glob(
                $markerDirectory
                    . DIRECTORY_SEPARATOR
                    . str_repeat('[a-f0-9]', 64)
                    . '.json',
            ) ?: [] as $markerPath) {
                $candidateMarker = gallery_pdf_conversion_marker_read($parent, basename(
                    $markerPath,
                    '.json',
                ));
                if (
                    $candidateMarker !== null
                    && (string) ($candidateMarker['source_document_id'] ?? '')
                        === $documentId
                    && (string) ($candidateMarker['conversion_id'] ?? '')
                        !== $conversionKey
                    && gallery_pdf_conversion_archive_empty_orphan(
                        $parent,
                        $candidateMarker,
                        $documentId,
                    )
                ) {
                    $sessionToken = null;
                }
            }
        }
        if ($existingMarker !== null) {
            if (
                (int) ($existingMarker['page_count'] ?? 0) !== $pageCount
                || (string) ($existingMarker['target_mode'] ?? '')
                    !== $targetMode
            ) {
                throw new RuntimeException(
                    'The source PDF page count or target changed. Confirm a new conversion before replacing its lifecycle.',
                );
            }
            $target = find_gallery(
                (int) ($existingMarker['gallery_id'] ?? 0),
                true,
            );
            if (
                !$target
                || (
                    ($existingMarker['status'] ?? '') !== 'complete'
                    && $targetMode === 'child'
                    && gallery_effective_visibility($target) !== 'private'
                )
            ) {
                throw new RuntimeException(
                    'The existing conversion target is unavailable or no longer private.',
                );
            }
            $sessionToken ??= (string) ($existingMarker['session_token'] ?? '');
            if (
                $sessionToken === ''
                || !hash_equals((string) (
                    $existingMarker['session_token_sha256'] ?? ''
                ), hash('sha256', $sessionToken))
            ) {
                throw new RuntimeException(
                    'Resume requires the original conversion session token.',
                );
            }
            // A cancelled conversion is read-only until an explicit resume request.
            // Inspect acknowledged page bytes only when continuing an active upload or when the caller explicitly asks to resume.
            if (
                ($existingMarker['status'] ?? '') !== 'complete'
                && (
                    ($existingMarker['status'] ?? '') !== 'cancelled'
                    || $resumeCancelled
                )
            ) {
                $registered = gallery_images((int) $target['id'], false);
                $prefix = preg_quote(
                    (string) ($existingMarker['png']['prefix'] ?? ''),
                    '/',
                );
                $acknowledgedPages = is_array(
                    $existingMarker['acknowledged_pages'] ?? null,
                )
                    ? $existingMarker['acknowledged_pages']
                    : [];
                foreach ($registered as $image) {
                    $filename = basename(
                        (string) (
                            $image['relative_path']
                            ?? $image['filename']
                            ?? ''
                        ),
                    );
                    $digits = (int) ($existingMarker['png']['digits'] ?? 6);
                    if (
                        preg_match(
                            '/^' . $prefix . '([0-9]{' . $digits . '})\.png$/D',
                            $filename,
                            $match,
                        ) !== 1
                    ) {
                        if ($targetMode === 'append') {
                            continue;
                        }
                        throw new RuntimeException(
                            'The incomplete child was edited. Existing data was preserved and the conversion cannot resume.',
                        );
                    }
                    $absolute =
                        gallery_abs_path((string) $target['folder_path'])
                        . DIRECTORY_SEPARATOR
                        . $filename;
                    $knownHash = (string) (
                        $acknowledgedPages[(string) (int) $match[1]] ?? ''
                    );
                    $actualHash = is_file($absolute) && !is_link($absolute)
                        ? hash_file('sha256', $absolute)
                        : false;
                    if (
                        $knownHash !== ''
                        && (
                            !is_string($actualHash)
                            || !hash_equals($knownHash, $actualHash)
                        )
                    ) {
                        throw new RuntimeException(
                            'A previously acknowledged page was edited. Existing data was preserved and the conversion cannot resume.',
                        );
                    }
                }
            }
            if (
                ($existingMarker['status'] ?? '') === 'cancelled'
                && $resumeCancelled
            ) {
                $existingMarker['status'] = 'uploading';
                unset($existingMarker['cancelled_at']);
                $existingMarker['updated_at'] = gmdate('c');
                gallery_pdf_conversion_marker_write($parent, $existingMarker);
            }
            if (($existingMarker['status'] ?? '') === 'uploading') {
                $existingMarker = gallery_pdf_conversion_reconcile_cached_batches(
                    $parent,
                    $target,
                    $existingMarker,
                );
            }
            return gallery_pdf_conversion_begin_result(
                $existingMarker + ['session_token' => $sessionToken],
                $target,
                false,
            );
        }
        $generation = $startNewGeneration
            ? $latestGeneration + 1
            : $latestGeneration;
        $conversionId = $generation > 0 || $startNewGeneration
            ? bin2hex(random_bytes(32))
            : $conversionKey;
        $childName = 'pdf-pages-' . $conversionId;
        $childPath = $targetMode === 'child'
            ? gallery_child_folder_path($parent, $childName)
            : (string) $parent['folder_path'];
        $child = $targetMode === 'child'
            ? find_gallery_by_folder_path($childPath, true)
            : null;
        if ($child !== null) {
            if (!$allowNew || $targetMode !== 'child') {
                throw new RuntimeException(
                    'The reserved PDF page gallery exists without a lifecycle marker. Existing data was not changed.',
                );
            }
            $generation = max($generation, $latestGeneration + 1);
            $conversionId = bin2hex(random_bytes(32));
            $childName = 'pdf-pages-' . $conversionId;
            $childPath = gallery_child_folder_path($parent, $childName);
            $child = find_gallery_by_folder_path($childPath, true);
            if (
                $child !== null
                || file_exists(gallery_abs_path($childPath))
                || is_link(gallery_abs_path($childPath))
            ) {
                throw new RuntimeException(
                    'Could not reserve a fresh private PDF page gallery. Existing data was not changed.',
                );
            }
        }
        if (
            $targetMode === 'child'
            && file_exists(gallery_abs_path($childPath))
        ) {
            if (!$allowNew) {
                throw new RuntimeException(
                    'The reserved PDF page folder exists outside the gallery catalog. Reconcile it before conversion.',
                );
            }
            $generation = max($generation, $latestGeneration + 1);
            $conversionId = bin2hex(random_bytes(32));
            $childName = 'pdf-pages-' . $conversionId;
            $childPath = gallery_child_folder_path($parent, $childName);
            if (
                find_gallery_by_folder_path($childPath, true) !== null
                || file_exists(gallery_abs_path($childPath))
                || is_link(gallery_abs_path($childPath))
            ) {
                throw new RuntimeException(
                    'Could not reserve a fresh private PDF page gallery. Existing data was not changed.',
                );
            }
        }
        $created = $targetMode === 'child'
            ? create_empty_gallery([
                'title' =>
                    'PDF pages - '
                        . (string) ($source['metadata']['title'] ?? 'document'),
                'folder_name' => $childName,
                'description' => 'PNG pages rendered in the browser from an attached PDF. The source PDF remains in the parent gallery.',
                'visibility' => 'private',
                'parent_id' => (int) $parent['id'],
                'voting_enabled' => false,
                'show_filenames' => true,
            ]) : $parent;
        if (
            (int) ($created['id'] ?? 0) < 1
            || (
                $targetMode === 'child'
                && gallery_effective_visibility($created) !== 'private'
            )
            || (string) ($created['folder_path'] ?? '') !== $childPath
        ) {
            throw new RuntimeException(
                'Could not guarantee a private physical PDF page gallery.',
            );
        }
        $token = $sessionToken ?? bin2hex(random_bytes(32));
        $prefix =
            'pdf-'
            . substr(hash('sha256', $documentId), 0, 8)
            . '-'
            . substr($conversionId, 0, 8)
            . '-page-';
        // Keep page numbers lexically sortable while allowing width to grow with any declared page count.
        // Type: int. Units: decimal filename digits. Scope: this conversion's source page names.
        // Consumers: browser filename generation, ZIP admission and final gallery validation.
        // Rationale: a minimum width of four sorts ordinary PDFs; longer documents retain every digit without a page cap.
        $pageDigits = max(4, strlen((string) $pageCount));
        $marker = [
            'format' => 'php_gallery_pdf_conversion_v1',
            'conversion_id' => $conversionId,
            'render_key' => $renderKey,
            'generation' => $generation,
            'session_token' => $token,
            'session_token_sha256' => hash('sha256', $token),
            'source_gallery_id' => (int) $parent['id'],
            'source_document_id' => $documentId,
            'source_sha256' => $sourceHash,
            'source_document_metadata' => $source['metadata'],
            'dpi' => $dpi,
            'page_count' => $pageCount,
            'png' => [
                'extension' => 'png',
                'prefix' => $prefix,
                'digits' => $pageDigits,
            ],
            'target_mode' => $targetMode,
            'status' => 'uploading',
            'child_gallery_id' => $targetMode === 'child'
                ? (int) $created['id']
                : 0,
            'gallery_id' => (int) $created['id'],
            'created_at' => gmdate('c'),
        ];
        gallery_pdf_conversion_marker_write($parent, $marker);
        return gallery_pdf_conversion_begin_result(
            $marker + ['session_token' => $token],
            $created,
            true,
        );
    } finally {
        gallery_edit_writer_end($writer);
    }
}

/**
 * Classify conversion failures that can be resolved by explicitly starting a fresh child generation.
 *
 * @param \Throwable $error Failure raised while beginning a child conversion.
 * @return bool True only for a missing/non-private child target or a reserved child path collision.
 */
function gallery_pdf_conversion_restart_available(\Throwable $error): bool
{
    $message = $error->getMessage();
    foreach ([
        'The existing conversion target is unavailable or no longer private.',
        'The reserved PDF page gallery exists without a lifecycle marker. Existing data was not changed.',
        'The reserved PDF page folder exists outside the gallery catalog. Reconcile it before conversion.',
    ] as $restartableMessage) {
        if (hash_equals($restartableMessage, $message)) {
            return true;
        }
    }
    return false;
}

/**
 * Reconcile consecutive ordinary-upload successes whose PDF marker write was lost.
 *
 * @param array<string,mixed> $parent Persisted source gallery row.
 * @param array<string,mixed> $target Persisted conversion target gallery row.
 * @param array{format:string,conversion_id:string,render_key?:string,generation?:int,session_token:string,session_token_sha256:string,source_gallery_id:int,source_document_id:string,source_sha256:string,source_document_metadata?:array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string},dpi:int,page_count:int,png:array{extension:string,prefix:string,digits:int},target_mode:'child'|'append',status:'uploading'|'complete'|'cancelled',child_gallery_id:int,gallery_id:int,created_at?:string,updated_at?:string,completed_at?:string,cancelled_at?:string,acknowledged_image_ids?:list<int>,acknowledged_pages?:array<string,string>,next_batch_index?:int,registered_page_ids?:list<int>} $marker Active conversion marker to advance from cached upload receipts.
 * @return array{format:string,conversion_id:string,render_key?:string,generation?:int,session_token:string,session_token_sha256:string,source_gallery_id:int,source_document_id:string,source_sha256:string,source_document_metadata?:array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string},dpi:int,page_count:int,png:array{extension:string,prefix:string,digits:int},target_mode:'child'|'append',status:'uploading'|'complete'|'cancelled',child_gallery_id:int,gallery_id:int,created_at?:string,updated_at?:string,completed_at?:string,cancelled_at?:string,acknowledged_image_ids?:list<int>,acknowledged_pages?:array<string,string>,next_batch_index?:int,registered_page_ids?:list<int>} Marker after every consecutive cached success has been acknowledged.
 * @throws RuntimeException When a cached receipt is malformed or disagrees with its registered page files.
 */
function gallery_pdf_conversion_reconcile_cached_batches(
    array $parent,
    array $target,
    array $marker,
): array {
    if (!function_exists(__NAMESPACE__
        . '\\browser_upload_cached_batch_response')) {
        require_once __DIR__ . '/browser_uploads.php';
    }
    if (!function_exists(__NAMESPACE__
        . '\\browser_upload_cached_batch_response')) {
        throw new RuntimeException(
            'The prepared upload receipt store is unavailable for safe PDF resume.',
        );
    }
    $batchIndex = max(0, (int) ($marker['next_batch_index'] ?? 0));
    while (
        ($response = browser_upload_cached_batch_response(
            (int) $target['id'],
            (string) $marker['session_token'],
            $batchIndex,
        )) !== null
    ) {
        $receiptPages = array_values(array_map(
            'intval',
            (array) ($response['pdf_page_numbers'] ?? []),
        ));
        $filenames = array_values(array_map(
            'strval',
            (array) ($response['filenames'] ?? []),
        ));
        $ids = array_values(array_map(
            'intval',
            (array) ($response['image_ids'] ?? []),
        ));
        if (
            !is_string($response['manifest_sha256'] ?? null)
            || preg_match(
                '/^[a-f0-9]{64}$/D',
                (string) $response['manifest_sha256'],
            ) !== 1
            || $receiptPages === []
            || count($receiptPages) !== count($filenames)
            || count($ids) !== count($filenames)
            || count(array_unique($receiptPages)) !== count($receiptPages)
        ) {
            throw new RuntimeException(
                'A stored PDF upload receipt is incomplete; existing pages were preserved for review.',
            );
        }
        gallery_pdf_conversion_acknowledge_batch(
            $parent,
            (string) $marker['session_token'],
            (int) $target['id'],
            $response,
            $batchIndex,
            $receiptPages,
        );
        $freshMarker = gallery_pdf_conversion_marker_read(
            $parent,
            (string) $marker['conversion_id'],
        );
        if ($freshMarker === null) {
            throw new RuntimeException(
                'The PDF conversion marker disappeared while reconciling a stored upload receipt.',
            );
        }
        $marker = $freshMarker;
        $batchIndex = max(
            $batchIndex + 1,
            (int) ($marker['next_batch_index'] ?? 0),
        );
    }
    return $marker;
}

/**
 * Shape the authenticated begin response with its recoverable admin session token.
 *
 * @param array{format:string,conversion_id:string,render_key?:string,generation?:int,session_token:string,session_token_sha256:string,source_gallery_id:int,source_document_id:string,source_sha256:string,source_document_metadata?:array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string},dpi:int,page_count:int,png:array{extension:string,prefix:string,digits:int},target_mode:'child'|'append',status:'uploading'|'complete'|'cancelled',child_gallery_id:int,gallery_id:int,created_at?:string,updated_at?:string,completed_at?:string,cancelled_at?:string,acknowledged_image_ids?:list<int>,acknowledged_pages?:array<int,string>,next_batch_index?:int,registered_page_ids?:list<int>} $marker Lifecycle marker containing the recoverable admin session token.
 * @param array<string,mixed> $child Persisted upload target gallery row.
 * @param bool $created Whether this call created the child.
 * @return array{conversion_id:string,session_token:string,session_id:string,gallery_id:int,child_gallery_id:int,target_mode:string,page_count:int,prefix:string,page_digits:int,start_page:int,next_batch_index:int,status:string,created:bool} Public lifecycle coordinates.
 */
function gallery_pdf_conversion_begin_result(
    array $marker,
    array $child,
    bool $created,
): array {
    $token = (string) ($marker['session_token'] ?? '');
    if ($token === '') {
        throw new RuntimeException(
            'The active conversion token is unavailable; start a new conversion.',
        );
    }
    $prefix = (string) ($marker['png']['prefix'] ?? 'page-');
    $digits = (int) ($marker['png']['digits'] ?? 6);
    $acknowledged = is_array($marker['acknowledged_pages'] ?? null)
        ? $marker['acknowledged_pages']
        : [];
    $lastPage = 0;
    for ($page = 1; $page <= (int) $marker['page_count']; ++$page) {
        if (!isset($acknowledged[(string) $page])) {
            break;
        }
        ++$lastPage;
    }
    return [
        'conversion_id' => (string) $marker['conversion_id'],
        'session_token' => $token,
        'session_id' => $token,
        'gallery_id' => (int) ($marker['gallery_id'] ?? $child['id']),
        'child_gallery_id' => (int) (
            $marker['child_gallery_id'] ?? $child['id']
        ),
        'target_mode' => (string) ($marker['target_mode'] ?? 'child'),
        'page_count' => (int) $marker['page_count'],
        'prefix' => (string) ($marker['png']['prefix'] ?? 'page-'),
        'page_digits' => $digits,
        'start_page' => $lastPage + 1,
        'next_batch_index' => (int) ($marker['next_batch_index'] ?? 0),
        'status' => (string) $marker['status'],
        'created' => $created,
    ];
}

/**
 * Authenticate an in-flight conversion against its source and private child.
 *
 * @param array<string,mixed> $parent Persisted source gallery row.
 * @param string $token 64-character secret returned by gallery_pdf_conversion_begin().
 * @param int $childGalleryId Posted target child gallery ID.
 * @param bool $allowComplete Whether a completed conversion may be read for idempotent finalization.
 * @param bool $allowCancelled Whether a cancelled conversion may be read for idempotent cancellation.
 * @return array{parent:array<string,mixed>,child:array<string,mixed>,marker:array{format:string,conversion_id:string,render_key?:string,generation?:int,session_token:string,session_token_sha256:string,source_gallery_id:int,source_document_id:string,source_sha256:string,source_document_metadata?:array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string},dpi:int,page_count:int,png:array{extension:string,prefix:string,digits:int},target_mode:'child'|'append',status:'uploading'|'complete'|'cancelled',child_gallery_id:int,gallery_id:int,created_at?:string,updated_at?:string,completed_at?:string,cancelled_at?:string,acknowledged_image_ids?:list<int>,acknowledged_pages?:array<int,string>,next_batch_index?:int,registered_page_ids?:list<int>},source:array{path:string,metadata:array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string}}|null} Authenticated context; completed reads may omit the current source after its historical conversion is safely finalized.
 * @throws RuntimeException When the token, target, source or active state is invalid.
 */
function gallery_pdf_conversion_context(
    array $parent,
    string $token,
    int $childGalleryId,
    bool $allowComplete = false,
    bool $allowCancelled = false,
): array {
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        throw new RuntimeException('The PDF conversion token is invalid.');
    }
    $freshParent = find_gallery((int) ($parent['id'] ?? 0), true);
    $child = $childGalleryId > 0
        ? find_gallery($childGalleryId, true)
        : $freshParent;
    $candidate = $child;
    if (!$freshParent || !$candidate) {
        throw new RuntimeException('The PDF page gallery is unavailable.');
    }
    $found = gallery_pdf_conversion_marker_find(
        $freshParent,
        $token,
        $childGalleryId,
    );
    $marker = $found['marker'] ?? null;
    $child = $candidate;
    if (
        $marker === null
        || (int) ($marker['source_gallery_id'] ?? 0)
            !== (int) $freshParent['id']
        || (int) ($marker['gallery_id'] ?? 0) !== $childGalleryId
        || ($marker['target_mode'] ?? '') === 'child'
        && (int) ($child['parent_id'] ?? 0) !== (int) $freshParent['id']
        || (
            ($marker['status'] ?? '') !== 'complete'
            && ($marker['target_mode'] ?? '') === 'child'
            && gallery_effective_visibility($child) !== 'private'
        )
    ) {
        throw new RuntimeException(
            'The PDF conversion token does not own this child gallery.',
        );
    }
    if (
        ($marker['status'] ?? '') !== 'uploading'
        && !(
            $allowComplete
            && ($marker['status'] ?? '') === 'complete'
        )
        && !(
            $allowCancelled
            && ($marker['status'] ?? '') === 'cancelled'
        )
    ) {
        throw new RuntimeException(
            'The PDF conversion is no longer accepting page uploads.',
        );
    }
    $source = $allowComplete && ($marker['status'] ?? '') === 'complete'
        ? null
        : gallery_pdf_conversion_source(
            $freshParent,
            (string) $marker['source_document_id'],
            (string) $marker['source_sha256'],
        );
    return [
        'parent' => $freshParent,
        'child' => $child,
        'marker' => $marker,
        'source' => $source,
    ];
}

/**
 * Validate a prepared ZIP as a bounded set of correctly named browser-rendered PNG pages.
 *
 * @param array<string,mixed> $parent Persisted source gallery row.
 * @param string $token Conversion session token.
 * @param int $childGalleryId Target private child gallery ID.
 * @param array{error?:int,tmp_name?:string,size?:int,name?:string,type?:string} $uploadedZip PHP upload descriptor for the prepared ZIP.
 * @param string $sessionId Browser upload session identifier, which must equal the conversion token.
 * @param int $batchIndex Zero-based prepared upload batch index.
 * @return array{page_numbers:list<int>,item_count:int} Validated page numbers represented by this batch.
 * @throws RuntimeException When any item is not an owned, correctly named PNG page.
 */
function gallery_pdf_conversion_validate_batch(
    array $parent,
    string $token,
    int $childGalleryId,
    array $uploadedZip,
    string $sessionId,
    int $batchIndex,
): array {
    $context = gallery_pdf_conversion_context($parent, $token, $childGalleryId);
    if (!hash_equals($token, $sessionId) || $batchIndex < 0) {
        throw new RuntimeException(
            'The prepared page batch does not match the active conversion session.',
        );
    }
    $tmpName = (string) ($uploadedZip['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        throw new RuntimeException(
            'The prepared PDF page package is unavailable.',
        );
    }
    $entries = browser_upload_parse_store_zip(
        $tmpName,
        browser_upload_server_upload_limit_bytes(),
    );
    $manifest = browser_upload_manifest_from_entries($entries);
    browser_upload_validate_manifest_identity(
        $manifest,
        $sessionId,
        $batchIndex,
    );
    $pages = [];
    foreach (browser_upload_manifest_items_in_source_order(
        (array) ($manifest['items'] ?? []),
    ) as $item) {
        $filename = basename((string) ($item['prepared_name'] ?? ''));
        $prefix = preg_quote((string) $context['marker']['png']['prefix'], '/');
        $digits = (int) $context['marker']['png']['digits'];
        if (
            preg_match(
                '/^' . $prefix . '([0-9]{' . $digits . '})\.png$/D',
                $filename,
                $match,
            ) !== 1
        ) {
            throw new RuntimeException(
                'The PDF page batch contains a filename outside the conversion page sequence.',
            );
        }
        $page = (int) $match[1];
        $sourceIndex = browser_upload_manifest_source_index(
            $item,
            (int) ($item['_manifest_order_index'] ?? 0),
        );
        $path = normalize_relative_path(
            (string) ($item['original_path'] ?? ''),
        );
        $bytes = (string) ($entries[$path] ?? '');
        if (
            $page < 1
            || $page > (int) $context['marker']['page_count']
            || in_array($page, $pages, true)
            || ($sourceIndex + 1) !== $page
            || strlen($bytes) < 8
            || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n"
        ) {
            throw new RuntimeException(
                'The PDF page batch contains an invalid, repeated or unexpected PNG page.',
            );
        }
        $pages[] = $page;
    }
    if ($pages === []) {
        throw new RuntimeException('The prepared PDF page package is empty.');
    }
    return ['page_numbers' => $pages, 'item_count' => count($pages)];
}

/**
 * Record successful ordinary-upload acknowledgment for an accepted conversion batch.
 *
 * @param array<string,mixed> $parent Persisted source gallery row.
 * @param string $token Conversion session token.
 * @param int $childGalleryId Target private child gallery ID.
 * @param array{ok?:bool,gallery_id?:int,image_ids?:list<int>,filenames?:list<string>,manifest_sha256?:string,pdf_page_numbers?:list<int>,pdf_page_sha256?:array<string,string>} $response Canonical ordinary-upload acknowledgment and conversion replay receipt.
 * @param int|null $batchIndex Optional zero-based acknowledged batch index used to resume the upload sequence.
 * @param array<int,int>|null $expectedPages Ordered one-based page numbers validated from the submitted ZIP; required for conversion ingestion.
 * @return void
 * @throws RuntimeException When the ordinary uploader did not acknowledge a successful batch.
 */
function gallery_pdf_conversion_acknowledge_batch(
    array $parent,
    string $token,
    int $childGalleryId,
    array $response,
    ?int $batchIndex = null,
    ?array $expectedPages = null,
): void {
    $writer = gallery_edit_writer_begin();
    try {
        $context = gallery_pdf_conversion_context(
            $parent,
            $token,
            $childGalleryId,
        );
        $ids = array_values(array_filter(
            array_map('intval', (array) ($response['image_ids'] ?? [])),
            static fn(int $id): bool => $id > 0,
        ));
        $filenames = array_values(array_map(
            'strval',
            (array) ($response['filenames'] ?? []),
        ));
        if (
            empty($response['ok'])
            || (int) ($response['gallery_id'] ?? 0) !== $childGalleryId
            || $ids === []
            || count($ids) !== count($filenames)
            || count(array_unique($ids)) !== count($ids)
            || $expectedPages === null
            || $expectedPages === []
            || !is_string($response['manifest_sha256'] ?? null)
            || preg_match(
                '/^[a-f0-9]{64}$/D',
                (string) $response['manifest_sha256'],
            ) !== 1
        ) {
            throw new RuntimeException(
                'The ordinary image upload service did not acknowledge every PDF page.',
            );
        }
        $prefix = preg_quote((string) $context['marker']['png']['prefix'], '/');
        $digits = (int) $context['marker']['png']['digits'];
        $ackPages = [];
        foreach ($filenames as $filename) {
            if (
                preg_match(
                    '/^' . $prefix . '([0-9]{' . $digits . '})\.png$/D',
                    basename($filename),
                    $match,
                ) !== 1
                || (int) $match[1] < 1
                || (int) $match[1] > (int) $context['marker']['page_count']
                || in_array((int) $match[1], $ackPages, true)
            ) {
                throw new RuntimeException(
                    'The ordinary image upload service acknowledged an unrelated filename.',
                );
            }
            $ackPages[] = (int) $match[1];
        }
        $expectedPages = array_values(array_map('intval', $expectedPages));
        $receiptPages = array_values(array_map(
            'intval',
            (array) ($response['pdf_page_numbers'] ?? []),
        ));
        if ($ackPages !== $expectedPages || $receiptPages !== $expectedPages) {
            throw new RuntimeException(
                'The ordinary upload receipt does not match the pages validated from this ZIP.',
            );
        }
        $marker = $context['marker'];
        $acknowledged = is_array($marker['acknowledged_image_ids'] ?? null)
            ? $marker['acknowledged_image_ids']
            : [];
        $marker['acknowledged_image_ids'] = array_values(array_unique(array_merge(
            $acknowledged,
            $ids,
        )));
        $acknowledgedPages = is_array($marker['acknowledged_pages'] ?? null)
            ? $marker['acknowledged_pages']
            : [];
        foreach ($ackPages as $index => $page) {
            $filename = basename($filenames[$index]);
            $absolute =
                gallery_abs_path((string) $context['child']['folder_path'])
                . DIRECTORY_SEPARATOR
                . $filename;
            $contents = is_file($absolute) && !is_link($absolute)
                ? @file_get_contents($absolute)
                : false;
            if (
                !is_string($contents)
                || substr($contents, 0, 8) !== "\x89PNG\r\n\x1a\n"
            ) {
                throw new RuntimeException(
                    'An acknowledged conversion page is absent or is not a PNG.',
                );
            }
            $actualHash = hash('sha256', $contents);
            $receiptHashes = (array) ($response['pdf_page_sha256'] ?? []);
            if (
                !is_string($receiptHashes[(string) $page] ?? null)
                || !hash_equals($receiptHashes[(string) $page], $actualHash)
            ) {
                throw new RuntimeException(
                    'An acknowledged conversion page differs from the ordinary upload receipt.',
                );
            }
            $acknowledgedPages[(string) $page] = $actualHash;
        }
        $marker['acknowledged_pages'] = $acknowledgedPages;
        if ($batchIndex !== null) {
            $marker['next_batch_index'] = max(
                (int) ($marker['next_batch_index'] ?? 0),
                $batchIndex + 1,
            );
        }
        $marker['updated_at'] = gmdate('c');
        gallery_pdf_conversion_marker_write($context['parent'], $marker);
    } finally {
        gallery_edit_writer_end($writer);
    }
}

/**
 * Finalize only after all expected PNG pages are physically present and registered.
 *
 * @param array<string,mixed> $parent Persisted source gallery row.
 * @param string $token Conversion session token.
 * @param int $childGalleryId Target private child gallery ID.
 * @return array{status:string,gallery_id:int,child_gallery_id:int,page_count:int,image_ids:list<int>,complete:bool} Finalized upload target state and acknowledged generated image IDs.
 * @throws RuntimeException When source identity changed, pages are missing/edited, or the child is no longer private.
 */
function gallery_pdf_conversion_finalize(
    array $parent,
    string $token,
    int $childGalleryId,
): array {
    $writer = gallery_edit_writer_begin();
    try {
        $context = gallery_pdf_conversion_context(
            $parent,
            $token,
            $childGalleryId,
            true,
        );
        if (($context['marker']['status'] ?? '') === 'complete') {
            return [
                'status' => 'complete',
                'gallery_id' => (int) $context['marker']['gallery_id'],
                'child_gallery_id' => (int) (
                    $context['marker']['child_gallery_id'] ?? 0
                ),
                'page_count' => (int) $context['marker']['page_count'],
                'image_ids' => array_values(array_map(
                    'intval',
                    (array) ($context['marker']['registered_page_ids'] ?? []),
                )),
                'complete' => true,
            ];
        }
        $expectedCount = (int) $context['marker']['page_count'];
        $images = gallery_images($childGalleryId, false);
        $byFilename = [];
        $acknowledgedIds = array_flip(array_map(
            'intval',
            (array) ($context['marker']['acknowledged_image_ids'] ?? []),
        ));
        $prefix = preg_quote((string) $context['marker']['png']['prefix'], '/');
        $digits = (int) $context['marker']['png']['digits'];
        foreach ($images as $image) {
            $filename = basename(
                (string) ($image['relative_path'] ?? $image['filename'] ?? ''),
            );
            if (
                preg_match(
                    '/^' . $prefix . '([0-9]{' . $digits . '})\.png$/D',
                    $filename,
                    $match,
                ) !== 1
            ) {
                if (($context['marker']['target_mode'] ?? '') === 'append') {
                    continue;
                }
                throw new RuntimeException(
                    'The private child contains an unrelated image; conversion was left incomplete.',
                );
            }
            $page = (int) $match[1];
            if (isset($byFilename[$page])) {
                throw new RuntimeException(
                    'The private child contains duplicate page registrations.',
                );
            }
            $absolute =
                gallery_abs_path((string) $context['child']['folder_path'])
                . DIRECTORY_SEPARATOR
                . $filename;
            $handle = is_file($absolute) && !is_link($absolute)
                ? @fopen($absolute, 'rb')
                : false;
            $signature = $handle !== false ? fread($handle, 8) : false;
            if ($handle !== false) {
                fclose($handle);
            }
            if (
                $page < 1
                || $page > $expectedCount
                || $signature !== "\x89PNG\r\n\x1a\n"
            ) {
                throw new RuntimeException(
                    'A registered PDF page is missing or is no longer a valid PNG.',
                );
            }
            $recordedHash = (string) (
                ($context['marker']['acknowledged_pages'] ?? [])[(string) $page]
                ?? ''
            );
            if (
                $recordedHash === ''
                || !hash_equals(
                    $recordedHash,
                    (string) hash_file('sha256', $absolute),
                )
            ) {
                throw new RuntimeException(
                    'A PDF page changed after its upload acknowledgment.',
                );
            }
            $imageId = (int) ($image['id'] ?? 0);
            if ($imageId < 1 || !isset($acknowledgedIds[$imageId])) {
                throw new RuntimeException(
                    'A registered page lacks the ordinary uploader acknowledgment.',
                );
            }
            $byFilename[$page] = $imageId;
        }
        $prefixText = (string) $context['marker']['png']['prefix'];
        foreach (glob(
            gallery_abs_path((string) $context['child']['folder_path'])
                . DIRECTORY_SEPARATOR
                . $prefixText
                . '*.png',
        ) ?: [] as $path) {
            $filename = basename($path);
            if (
                preg_match(
                    '/^' . $prefix . '([0-9]{' . $digits . '})\.png$/D',
                    $filename,
                    $match,
                ) !== 1
                || (int) $match[1] < 1
                || (int) $match[1] > $expectedCount
            ) {
                throw new RuntimeException(
                    'An unregistered or unexpected PDF page file remains in the conversion target.',
                );
            }
        }
        if (count($byFilename) !== $expectedCount) {
            throw new RuntimeException(
                'Not every PDF page has been uploaded and registered. The conversion remains resumable.',
            );
        }
        for ($page = 1; $page <= $expectedCount; ++$page) {
            if (!isset($byFilename[$page])) {
                throw new RuntimeException(
                    'A PDF page is missing from the registered page sequence.',
                );
            }
        }
        $marker = $context['marker'];
        $marker['status'] = 'complete';
        $marker['completed_at'] = gmdate('c');
        $marker['registered_page_ids'] = array_values($byFilename);
        gallery_pdf_conversion_marker_write($context['parent'], $marker);
        return [
            'status' => 'complete',
            'gallery_id' => $childGalleryId,
            'child_gallery_id' => (int) ($marker['child_gallery_id'] ?? 0),
            'page_count' => $expectedCount,
            'image_ids' => array_values($byFilename),
            'complete' => true,
        ];
    } finally {
        gallery_edit_writer_end($writer);
    }
}

/**
 * Cancel an active conversion while preserving its private child for safe review.
 *
 * @param array<string,mixed> $parent Persisted source gallery row.
 * @param string $token Conversion session token.
 * @param int $childGalleryId Target private child gallery ID.
 * @return array{status:string,gallery_id:int,child_gallery_id:int,cancelled:bool} Cancellation state.
 * @throws RuntimeException When the token does not own an active private child.
 */
function gallery_pdf_conversion_cancel(
    array $parent,
    string $token,
    int $childGalleryId,
): array {
    $writer = gallery_edit_writer_begin();
    try {
        $context = gallery_pdf_conversion_context(
            $parent,
            $token,
            $childGalleryId,
            false,
            true,
        );
        $marker = $context['marker'];
        if (($marker['status'] ?? '') === 'cancelled') {
            return [
                'status' => 'cancelled',
                'gallery_id' => $childGalleryId,
                'child_gallery_id' => (int) ($marker['child_gallery_id'] ?? 0),
                'cancelled' => true,
            ];
        }
        $marker['status'] = 'cancelled';
        $marker['cancelled_at'] = gmdate('c');
        gallery_pdf_conversion_marker_write($context['parent'], $marker);
        return [
            'status' => 'cancelled',
            'gallery_id' => $childGalleryId,
            'child_gallery_id' => (int) ($marker['child_gallery_id'] ?? 0),
            'cancelled' => true,
        ];
    } finally {
        gallery_edit_writer_end($writer);
    }
}
