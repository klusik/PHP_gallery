<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_attachment_usage.php
 * Module Type: Service Module
 * Purpose: Reconcile derived digest snapshots for public gallery-card usage.
 * Responsibilities: Read bounded authoritative sidecars and publish fail-closed projections.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/**
 * Bound gallery snapshots inspected by one Maintenance Center worker step.
 * Type: int. Units: galleries. Scope: one resumable worker call.
 * Consumers: gallery_attachment_usage_reconcile_batch.
 * Rationale: Keeps filesystem reads and SQL replacement bounded per step.
 * Value: 25 gallery snapshots per worker call.
 */
const GALLERY_ATTACHMENT_USAGE_BATCH_SIZE = 25;

/** Bound the legacy OFP manifest during one-time metadata backfill.
 * Type: int. Units: bytes. Scope: one confined manifest read and write.
 * Consumers: gallery_attachment_usage_store_ofp_digest.
 * Rationale: Matches the small public OFP manifest contract and caps JSON parsing.
 * Value: 32 KiB per manifest.
 */
const GALLERY_ATTACHMENT_USAGE_MANIFEST_BYTES = 32768;

/** Bound random staging names used by the legacy OFP digest backfill.
 * Type: int. Units: random bytes. Scope: one temporary manifest filename.
 * Consumers: gallery_attachment_usage_store_ofp_digest.
 * Rationale: A 96-bit random nonce prevents collisions between concurrent workers.
 * Value: 12 random bytes per staging name.
 */
const GALLERY_ATTACHMENT_USAGE_STAGE_NONCE_BYTES = 12;

/** Restrict the permissions of a staged legacy OFP metadata file.
 * Type: int. Units: POSIX permission bits. Scope: one private temporary manifest.
 * Consumers: gallery_attachment_usage_store_ofp_digest.
 * Rationale: The manifest may contain private flight metadata and must remain owner-only.
 * Value: 0600 file permissions.
 */
const GALLERY_ATTACHMENT_USAGE_PRIVATE_FILE_MODE = 0600;

/** Reject truncated and implausibly short legacy PDF originals before hashing.
 * Type: int. Units: bytes. Scope: one legacy OFP digest backfill.
 * Consumers: gallery_attachment_usage_store_ofp_digest.
 * Rationale: A PDF needs a complete header and must fit the existing upload cap.
 * Value: 8 bytes minimum; GALLERY_PDF_DOCUMENT_MAX_BYTES maximum.
 */
const GALLERY_ATTACHMENT_USAGE_MIN_PDF_BYTES = 8;

/**
 * Rebuild ready/dirty digest snapshots for a bounded page of physical galleries.
 * @param array{cursor_gallery_id?:int,scanned?:int,ready?:int,failed?:int} $state Persisted Maintenance Center cursor and cumulative counters.
 * @return array{done:bool,cursor_gallery_id:int,scanned:int,ready:int,failed:int} Resumable progress; ready counts include empty snapshots, failures stay omitted from public badges.
 */
function gallery_attachment_usage_reconcile_batch(array $state): array
{
    if (
        !function_exists(__NAMESPACE__ . '\\presentation_gallery_attachment_usage_schema_status')
        || !function_exists(__NAMESPACE__ . '\\presentation_schema_assert_write_available')
    ) {
        throw new \RuntimeException(
            'Attachment usage schema policy is unavailable.',
        );
    }
    presentation_schema_assert_write_available(
        presentation_gallery_attachment_usage_schema_status(),
        'maintenance.gallery_attachment_usage',
    );
    $cursor = max(0, (int) ($state['cursor_gallery_id'] ?? 0));
    $scanned = max(0, (int) ($state['scanned'] ?? 0));
    $ready = max(0, (int) ($state['ready'] ?? 0));
    $failed = max(0, (int) ($state['failed'] ?? 0));
    $page = \Gallery\Models\gallery_model_source_picker_page(
        $cursor,
        GALLERY_ATTACHMENT_USAGE_BATCH_SIZE + 1,
    );
    $hasLookahead = count($page) > GALLERY_ATTACHMENT_USAGE_BATCH_SIZE;
    $page = array_slice($page, 0, GALLERY_ATTACHMENT_USAGE_BATCH_SIZE);
    $hashBudgetUsed = false;
    $stoppedForHashBudget = false;
    $processedCursor = $cursor;
    foreach ($page as $candidate) {
        $galleryId = (int) ($candidate['id'] ?? 0);
        $gallery = $galleryId > 0
            ? \Gallery\Models\gallery_model_find_by_id($galleryId)
            : null;
        if ($gallery === null) {
            $failed++;
            $scanned++;
            $processedCursor = $galleryId;
            continue;
        }
        try {
            $result = gallery_attachment_usage_reconcile_gallery(
                $gallery,
                $hashBudgetUsed,
            );
        } catch (\Throwable) {
            // A permanent storage failure can precede source-lock acquisition.
            // Retire any older ready snapshot as well: an explicit reconcile
            // must not leave stale counts visible for an unreadable source.
            foreach (['pdf', 'music'] as $kind) {
                try {
                    \Gallery\Models\gallery_model_attachment_usage_mark_dirty(
                        $galleryId,
                        $kind,
                    );
                } catch (\Throwable) {
                    // A concurrent deletion or unavailable DB leaves no
                    // publishable replacement; public schema/read policy
                    // continues to omit unavailable projection data.
                }
            }
            error_log('Gallery attachment usage reconciliation failed safely.');
            $result = ['deferred' => false, 'ready' => 0, 'failed' => 1];
        }
        if (!empty($result['deferred'])) {
            $stoppedForHashBudget = true;
            break;
        }
        $ready += (int) $result['ready'];
        $failed += (int) $result['failed'];
        $scanned++;
        $processedCursor = $galleryId;
    }
    $done = !$hasLookahead && !$stoppedForHashBudget;
    return [
        'done' => $done,
        'cursor_gallery_id' => $done ? 0 : $processedCursor,
        'scanned' => $scanned,
        'ready' => $ready,
        'failed' => $failed,
    ];
}

/** Reconcile one gallery while holding the PDF/OFP and music source locks.
 * @param array{id:int|string,folder_path:string} $gallery Registered physical gallery.
 * @param bool $hashBudgetUsed Whether this call already performed a legacy OFP hash.
 * @return array{deferred:bool,ready:int,failed:int} Snapshot outcomes; a busy lock defers the cursor without changing source generations.
 */
function gallery_attachment_usage_reconcile_gallery(
    array $gallery,
    bool &$hashBudgetUsed,
): array {
    $galleryId = (int) $gallery['id'];
    $pdfLock = gallery_attachment_usage_acquire_root_lock($gallery);
    if ($pdfLock === false) {
        return ['deferred' => true, 'ready' => 0, 'failed' => 0];
    }
    $musicLock = null;
    try {
        $musicLock = gallery_music_acquire_lock([
            'id' => (int) $gallery['id'],
            'folder_path' => (string) $gallery['folder_path'],
        ], true);
        if ($musicLock === false) {
            return ['deferred' => true, 'ready' => 0, 'failed' => 0];
        }
        try {
            $needsLegacyHash = gallery_attachment_usage_requires_legacy_digest(
                $gallery,
            );
        } catch (\Throwable) {
            return ['deferred' => false, 'ready' => 0, 'failed' => 1];
        }
        if ($needsLegacyHash && $hashBudgetUsed) {
            return ['deferred' => true, 'ready' => 0, 'failed' => 0];
        }
        $pdfReady = false;
        $musicReady = false;
        $failed = false;
        try {
            $revision = \Gallery\Models\gallery_model_attachment_usage_mark_dirty(
                $galleryId,
                'pdf',
            );
            $items = gallery_attachment_usage_pdf_items(
                $gallery,
                $hashBudgetUsed,
            );
            if (
                $items === null
                || !\Gallery\Models\gallery_model_attachment_usage_replace(
                    $galleryId,
                    'pdf',
                    $items,
                    $revision,
                )
            ) {
                $failed = true;
            } else {
                $pdfReady = true;
            }
        } catch (\Throwable) {
            error_log('Gallery PDF usage snapshot reconciliation failed safely.');
            $failed = true;
        }
        try {
            $revision = \Gallery\Models\gallery_model_attachment_usage_mark_dirty(
                $galleryId,
                'music',
            );
            $items = gallery_music_attachment_usage_items(
                gallery_music_state($gallery),
            );
            if (!\Gallery\Models\gallery_model_attachment_usage_replace(
                $galleryId,
                'music',
                $items,
                $revision,
            )) {
                $failed = true;
            } else {
                $musicReady = true;
            }
        } catch (\Throwable) {
            error_log('Gallery music usage snapshot reconciliation failed safely.');
            $failed = true;
        }
        return [
            'deferred' => false,
            'ready' => $pdfReady && $musicReady ? 1 : 0,
            'failed' => $failed ? 1 : 0,
        ];
    } finally {
        if (is_resource($musicLock)) {
            gallery_music_release_lock($musicLock);
        }
        flock($pdfLock, LOCK_UN);
        fclose($pdfLock);
    }
}

/** Acquire the persistent root OFP/PDF serialization lock without waiting.
 * @param array{id:int|string,folder_path:string} $gallery Registered physical gallery.
 * @return resource|false Open lock handle, or false when another owner must finish first.
 */
function gallery_attachment_usage_acquire_root_lock(array $gallery): mixed
{
    $root = gallery_pdf_documents_gallery_directory($gallery);
    if ($root === null) {
        throw new \RuntimeException('Gallery attachment path is unavailable.');
    }
    $path = $root . DIRECTORY_SEPARATOR . '.simbrief-ofp-upload.lock';
    if (is_link($path)) {
        throw new \RuntimeException('Gallery attachment lock is unsafe.');
    }
    $lock = @fopen($path, 'c');
    if ($lock === false) {
        throw new \RuntimeException('Gallery attachment lock is unavailable.');
    }
    @chmod($path, GALLERY_PDF_DOCUMENTS_USAGE_LOCK_MODE);
    $wouldBlock = 0;
    if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
        fclose($lock);
        if ($wouldBlock !== 0) {
            return false;
        }
        throw new \RuntimeException('Gallery attachment lock is unavailable.');
    }
    return $lock;
}

/** Detect whether the current legacy OFP needs its one-time backfill digest.
 * @param array{id:int|string,folder_path:string} $gallery Registered physical gallery.
 * @return bool True when a registered legacy OFP manifest lacks a valid digest.
 * @throws \RuntimeException When the manifest owner is not loaded or metadata is unsafe.
 */
function gallery_attachment_usage_requires_legacy_digest(array $gallery): bool
{
    if (!function_exists(__NAMESPACE__ . '\\simbrief_ofp_gallery_manifest')) {
        throw new \RuntimeException('The OFP manifest owner is unavailable.');
    }
    $manifest = simbrief_ofp_gallery_manifest($gallery);
    return ($manifest['ofp_pdf_file'] ?? '') === 'simbrief-ofp.pdf'
        && (
            !is_string($manifest['ofp_pdf_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $manifest['ofp_pdf_sha256']) !== 1
        );
}

/** Build PDF item rows from the authoritative sidecar and legacy OFP manifest.
 * @param array{id:int|string,folder_path:string} $gallery Registered physical gallery.
 * @param bool $legacyHashUsed Whether this worker call has already hashed one legacy OFP.
 * @return list<array{source_id:string,sha256:?string,asset_id:?string,album_id:?int,hidden:bool}> Valid digest rows, or an empty list for known empty state; null defers when this call's legacy hash budget is spent.
 * @throws \RuntimeException When the trusted PDF sidecar or confined original is unsafe.
 */
function gallery_attachment_usage_pdf_items(
    array $gallery,
    bool &$legacyHashUsed,
): ?array {
    $root = gallery_pdf_documents_gallery_directory($gallery);
    if ($root === null) {
        throw new \RuntimeException('Gallery PDF storage is unavailable.');
    }
    $state = gallery_pdf_documents_read_index_state($root);
    $items = [];
    foreach ($state['documents'] as $documentId => $document) {
        $items[] = [
            'source_id' => 'document:' . $documentId,
            'sha256' => $document['sha256'],
            'asset_id' => null,
            'album_id' => null,
            'hidden' => $document['hidden'],
        ];
    }
    if (!function_exists(__NAMESPACE__ . '\\simbrief_ofp_gallery_manifest')) {
        throw new \RuntimeException('The OFP manifest owner is unavailable.');
    }
    $ofp = simbrief_ofp_gallery_manifest($gallery);
    if (($ofp['ofp_pdf_file'] ?? '') === 'simbrief-ofp.pdf') {
        $path = $root . DIRECTORY_SEPARATOR . 'simbrief-ofp.pdf';
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('Legacy OFP attachment is unsafe.');
        }
        $digest = $ofp['ofp_pdf_sha256'] ?? null;
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            if ($legacyHashUsed) {
                return null;
            }
            // Spend the per-step hash budget before touching the original so a
            // manifest write failure cannot trigger a second full-file hash.
            $legacyHashUsed = true;
            $digest = gallery_attachment_usage_store_ofp_digest($root, $path);
        }
        $items[] = [
            'source_id' => 'ofp',
            'sha256' => $digest,
            'asset_id' => null,
            'album_id' => null,
            'hidden' => $state['ofp_hidden'],
        ];
    }
    return $items;
}

/** Persist a one-time legacy OFP digest while holding the attachment owner lock.
 * @param string $root Confined registered gallery directory.
 * @param string $pdfPath Existing legacy OFP original within the gallery.
 * @return string The digest is computed while the OFP writer lock is held, then persisted atomically for later runs.
 * @throws \RuntimeException When the manifest cannot be safely locked or updated.
 */
function gallery_attachment_usage_store_ofp_digest(
    string $root,
    string $pdfPath,
): string {
    if (
        is_link($pdfPath)
        || !is_file($pdfPath)
    ) {
        throw new \RuntimeException('Legacy OFP metadata is unsafe.');
    }
    $stage = '';
    try {
        $manifestPath = $root . DIRECTORY_SEPARATOR . 'simbrief-ofp-manifest.json';
        if (is_link($manifestPath) || !is_file($manifestPath)) {
            throw new \RuntimeException('Legacy OFP manifest is unavailable.');
        }
        $manifestSize = @filesize($manifestPath);
        if (
            $manifestSize === false
            || $manifestSize > GALLERY_ATTACHMENT_USAGE_MANIFEST_BYTES
        ) {
            throw new \RuntimeException('Legacy OFP manifest exceeds its storage limit.');
        }
        $bytes = @file_get_contents(
            $manifestPath,
            false,
            null,
            0,
            GALLERY_ATTACHMENT_USAGE_MANIFEST_BYTES + 1,
        );
        $manifest = is_string($bytes)
            && strlen($bytes) <= GALLERY_ATTACHMENT_USAGE_MANIFEST_BYTES
            ? json_decode($bytes, true)
            : null;
        if (
            !is_array($manifest)
            || ($manifest['format'] ?? '') !== 'php_gallery_simbrief_ofp_manifest_v1'
            || ($manifest['ofp_pdf_file'] ?? '') !== 'simbrief-ofp.pdf'
        ) {
            throw new \RuntimeException('Legacy OFP manifest is invalid.');
        }
        $size = @filesize($pdfPath);
        if (
            $size === false
            || $size < GALLERY_ATTACHMENT_USAGE_MIN_PDF_BYTES
            || $size > GALLERY_PDF_DOCUMENT_MAX_BYTES
        ) {
            throw new \RuntimeException('Legacy OFP attachment exceeds its digest limit.');
        }
        $digest = @hash_file('sha256', $pdfPath);
        if (!is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
            throw new \RuntimeException('Legacy OFP digest could not be computed.');
        }
        $manifest['ofp_pdf_sha256'] = $digest;
        $json = json_encode(
            $manifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        if (
            !is_string($json)
            // The persisted trailing newline belongs to the reader's byte cap.
            || strlen($json) + 1 > GALLERY_ATTACHMENT_USAGE_MANIFEST_BYTES
        ) {
            throw new \RuntimeException('Legacy OFP manifest exceeds its storage limit.');
        }
        $stage = $root . DIRECTORY_SEPARATOR . '.simbrief-ofp-usage-'
            . bin2hex(random_bytes(GALLERY_ATTACHMENT_USAGE_STAGE_NONCE_BYTES))
            . '.tmp';
        if (@file_put_contents($stage, $json . "\n", LOCK_EX) !== strlen($json) + 1) {
            throw new \RuntimeException('Legacy OFP digest could not be staged.');
        }
        @chmod($stage, GALLERY_ATTACHMENT_USAGE_PRIVATE_FILE_MODE);
        if (!@rename($stage, $manifestPath)) {
            throw new \RuntimeException('Legacy OFP digest could not be installed.');
        }
        $stage = '';
        return $digest;
    } finally {
        if ($stage !== '' && is_file($stage)) {
            @unlink($stage);
        }
    }
}
