<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_attachment_usage.php
 * Module Type: Service Module
 * Purpose: Reconcile public attachment counts manually and in background.
 * Responsibilities:
 *   - Read bounded authoritative sidecars and publish fail-closed projections
 *   - Persist one-time automatic progress, private ticket leases and completion
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/**
 * Bound gallery snapshots inspected by one manual or automatic worker step.
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

/** Restrict private attachment metadata and automatic-worker coordination.
 * Type: int. Units: POSIX permission bits. Scope: private manifests and worker lock.
 * Consumers: gallery_attachment_usage_store_ofp_digest, gallery_attachment_usage_background_lock.
 * Rationale: Flight metadata and worker coordination remain owner-only.
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

/** Name the durable receipt for the one-time traffic-driven count scan.
 * Type: string. Units: app_settings key. Scope: one installation.
 * Consumers: automatic kickoff, continuation admission and durable progress.
 * Rationale: completion must survive cache cleanup and application restarts.
 * Value: gallery_attachment_usage.background_state.
 */
const GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING =
    'gallery_attachment_usage.background_state';

/** Bound the admission lease for a private media-count worker request.
 * Type: int. Units: seconds. Scope: one installation-wide ticket.
 * Consumers: automatic start, worker ticket validation and retry admission.
 * Rationale: tolerate delayed loopback requests while recovering lost handoffs.
 * Value: 120 seconds.
 */
const GALLERY_ATTACHMENT_USAGE_BACKGROUND_LEASE_SECONDS = 120;

/** Space automatic retries after storage, transport or source failures.
 * Type: int. Units: seconds. Scope: the next automatic media-count attempt.
 * Consumers: background start and private worker failure handling.
 * Rationale: avoid adding work to every public request during an outage.
 * Value: 300 seconds.
 */
const GALLERY_ATTACHMENT_USAGE_BACKGROUND_RETRY_SECONDS = 300;

/** Limit ticket entropy for persisted autonomous count work.
 * Type: int. Units: random bytes. Scope: one media-count worker admission.
 * Consumers: durable queued-state ticket generation.
 * Rationale: a 256-bit opaque ticket is unguessable and compact to validate.
 * Value: 32 random bytes.
 */
const GALLERY_ATTACHMENT_USAGE_BACKGROUND_TICKET_BYTES = 32;

/**
 * Rebuild ready/dirty digest snapshots for a bounded page of physical galleries.
 * @param array{cursor_gallery_id?:int,scanned?:int,ready?:int,failed?:int} $state Persisted manual or automatic cursor and cumulative counters.
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

/** Report whether the first automatic usage reconciliation may be admitted.
 * @return bool True when durable state is absent, due, or has a stale lease; false on completion, a live lease, backoff, or unknown settings storage.
 */
function gallery_attachment_usage_background_due(): bool
{
    try {
        $state = gallery_attachment_usage_background_read_state();
        if ($state === null) {
            return true;
        }
        $now = time();
        if ($state['status'] === 'complete') {
            return false;
        }
        if (
            in_array($state['status'], ['queued', 'running'], true)
            && $state['queued_until'] > $now
        ) {
            return false;
        }
        return $state['next_attempt_at'] <= $now;
    } catch (\Throwable) {
        return false;
    }
}

/** Start or recover one durable automatic usage reconciliation chain.
 * @param callable(string):bool $dispatch Sends one opaque ticket to the independent authenticated worker.
 * @return array{ok:bool,queued:bool,done:bool,reason:string,scanned:int,ready:int,failed:int,completed:bool} Admission result; queued means the dispatcher accepted the handoff.
 */
function gallery_attachment_usage_background_start(
    callable $dispatch,
): array {
    $lock = false;
    $ticket = '';
    $queuedState = null;
    try {
        $lock = gallery_attachment_usage_background_lock();
        if ($lock === false) {
            return gallery_attachment_usage_background_result(false, false, false, 'busy');
        }
        $state = gallery_attachment_usage_background_read_state();
        $state ??= gallery_attachment_usage_background_empty_state();
        $now = time();
        if ($state['status'] === 'complete') {
            return gallery_attachment_usage_background_result(
                true,
                false,
                true,
                'complete',
                $state,
            );
        }
        if (
            in_array($state['status'], ['queued', 'running'], true)
            && $state['queued_until'] > $now
        ) {
            return gallery_attachment_usage_background_result(
                true,
                false,
                false,
                'already_queued',
                $state,
            );
        }
        if ($state['next_attempt_at'] > $now) {
            return gallery_attachment_usage_background_result(
                true,
                false,
                false,
                'retry_deferred',
                $state,
            );
        }
        $state['status'] = 'queued';
        $state['ticket'] = bin2hex(
            random_bytes(GALLERY_ATTACHMENT_USAGE_BACKGROUND_TICKET_BYTES),
        );
        $state['queued_until'] = $now
            + GALLERY_ATTACHMENT_USAGE_BACKGROUND_LEASE_SECONDS;
        $state['next_attempt_at'] = 0;
        gallery_attachment_usage_background_write_state($state);
        $ticket = $state['ticket'];
        $queuedState = $state;
    } catch (\Throwable) {
        error_log('Gallery attachment usage worker admission failed safely.');
        return gallery_attachment_usage_background_result(
            false,
            false,
            false,
            'state_unavailable',
        );
    } finally {
        gallery_attachment_usage_background_unlock($lock);
    }
    if ($ticket === '' || !is_array($queuedState)) {
        return gallery_attachment_usage_background_result(
            false,
            false,
            false,
            'state_unavailable',
        );
    }
    $accepted = false;
    try {
        $accepted = $dispatch($ticket);
    } catch (\Throwable) {
        // A timed-out transport may still have delivered the private ticket.
    }
    if ($accepted) {
        return gallery_attachment_usage_background_result(
            true,
            true,
            false,
            'queued',
            $queuedState,
        );
    }
    $state = gallery_attachment_usage_background_retire_ticket($ticket);
    return gallery_attachment_usage_background_result(
        false,
        false,
        false,
        'dispatch_failed',
        $state,
    );
}

/** Process one authenticated, leased automatic usage reconciliation step.
 * @param string $ticket Opaque 64-character lowercase hexadecimal ticket from the durable queue state.
 * @param callable(string):bool $dispatch Sends exactly one continuation ticket to the configured-origin worker.
 * @return array{ok:bool,queued:bool,done:bool,reason:string,scanned:int,ready:int,failed:int,completed:bool} Bounded worker progress and whether a continuation was accepted.
 */
function gallery_attachment_usage_background_run(
    string $ticket,
    callable $dispatch,
): array {
    if (preg_match('/^[a-f0-9]{64}$/D', $ticket) !== 1) {
        return gallery_attachment_usage_background_result(
            false,
            false,
            false,
            'invalid_ticket',
        );
    }
    $lock = false;
    try {
        $lock = gallery_attachment_usage_background_lock();
        if ($lock === false) {
            return gallery_attachment_usage_background_result(
                false,
                false,
                false,
                'busy',
            );
        }
        $state = gallery_attachment_usage_background_read_state();
        $now = time();
        if (
            $state === null
            || $state['status'] !== 'queued'
            || $state['queued_until'] <= $now
            || !hash_equals($state['ticket'], $ticket)
        ) {
            return gallery_attachment_usage_background_result(
                false,
                false,
                false,
                'stale_ticket',
                $state,
            );
        }
        $state['status'] = 'running';
        $state['queued_until'] = $now
            + GALLERY_ATTACHMENT_USAGE_BACKGROUND_LEASE_SECONDS;
        gallery_attachment_usage_background_write_state($state);

        if (gallery_attachment_usage_background_owner_busy()) {
            $state = gallery_attachment_usage_background_retry_state($state, $now);
            gallery_attachment_usage_background_write_state($state);
            return gallery_attachment_usage_background_result(
                true,
                false,
                false,
                'maintenance_busy',
                $state,
            );
        }
        $progress = gallery_attachment_usage_reconcile_batch($state);
        // A busy source lock can defer the very first gallery. Do not create
        // a tight chain of empty requests while another writer holds it.
        $stalled = !$progress['done']
            && $progress['scanned'] === $state['scanned']
            && $progress['cursor_gallery_id'] === $state['cursor_gallery_id'];
        $state['cursor_gallery_id'] = $progress['cursor_gallery_id'];
        $state['scanned'] = $progress['scanned'];
        $state['ready'] = $progress['ready'];
        $state['failed'] = $progress['failed'];
        if ($stalled) {
            $state = gallery_attachment_usage_background_retry_state($state, $now);
            gallery_attachment_usage_background_write_state($state);
            return gallery_attachment_usage_background_result(
                true,
                false,
                false,
                'source_busy',
                $state,
            );
        }
        if ($progress['done'] && $progress['failed'] === 0) {
            $state['status'] = 'complete';
            $state['ticket'] = '';
            $state['queued_until'] = 0;
            $state['next_attempt_at'] = 0;
            $state['completed_at'] = $now;
            gallery_attachment_usage_background_write_state($state);
            return gallery_attachment_usage_background_result(
                true,
                false,
                true,
                'complete',
                $state,
            );
        }
        if ($progress['done']) {
            $state = gallery_attachment_usage_background_retry_state(
                $state,
                $now,
                true,
            );
            gallery_attachment_usage_background_write_state($state);
            return gallery_attachment_usage_background_result(
                true,
                false,
                false,
                'retry_scheduled',
                $state,
            );
        }
        $state['status'] = 'queued';
        $state['ticket'] = bin2hex(
            random_bytes(GALLERY_ATTACHMENT_USAGE_BACKGROUND_TICKET_BYTES),
        );
        $state['queued_until'] = $now
            + GALLERY_ATTACHMENT_USAGE_BACKGROUND_LEASE_SECONDS;
        gallery_attachment_usage_background_write_state($state);
        $nextTicket = $state['ticket'];
        gallery_attachment_usage_background_unlock($lock);
        $lock = false;
        $accepted = false;
        try {
            $accepted = $dispatch($nextTicket);
        } catch (\Throwable) {
            // The queued lease safely recovers an uncertain loopback result.
        }
        if ($accepted) {
            return gallery_attachment_usage_background_result(
                true,
                true,
                false,
                'continued',
                $state,
            );
        }
        $state = gallery_attachment_usage_background_retire_ticket($nextTicket);
        return gallery_attachment_usage_background_result(
            false,
            false,
            false,
            'continuation_failed',
            $state,
        );
    } catch (\Throwable) {
        error_log('Gallery attachment usage worker step failed safely.');
        return gallery_attachment_usage_background_result(
            false,
            false,
            false,
            'worker_unavailable',
        );
    } finally {
        gallery_attachment_usage_background_unlock($lock);
    }
}

/** Read raw durable state without trusting a request-local app-setting cache.
 * @return array{version:int,status:string,cursor_gallery_id:int,scanned:int,ready:int,failed:int,attempt:int,ticket:string,queued_until:int,next_attempt_at:int,completed_at:int}|null Valid state or null when the receipt has never been written.
 * @throws \RuntimeException When state storage is unavailable or corrupt.
 */
function gallery_attachment_usage_background_read_state(): ?array
{
    if (!function_exists('Gallery\\Models\\app_settings_model_get')) {
        throw new \RuntimeException('Background state model is unavailable.');
    }
    $raw = \Gallery\Models\app_settings_model_get(
        GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING,
    );
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    $state = json_decode($raw, true);
    if (
        !is_array($state)
        || ($state['version'] ?? null) !== 1
        || !in_array(
            $state['status'] ?? null,
            ['pending', 'queued', 'running', 'retry', 'complete'],
            true,
        )
    ) {
        throw new \RuntimeException('Background state receipt is invalid.');
    }
    $normalized = gallery_attachment_usage_background_empty_state();
    foreach ([
        'cursor_gallery_id',
        'scanned',
        'ready',
        'failed',
        'attempt',
        'queued_until',
        'next_attempt_at',
        'completed_at',
    ] as $field) {
        if (!is_numeric($state[$field] ?? null)) {
            throw new \RuntimeException('Background state receipt is invalid.');
        }
        $normalized[$field] = max(0, (int) $state[$field]);
    }
    $ticket = $state['ticket'] ?? '';
    if (
        !is_string($ticket)
        || $ticket !== '' && preg_match('/^[a-f0-9]{64}$/D', $ticket) !== 1
    ) {
        throw new \RuntimeException('Background state receipt is invalid.');
    }
    $normalized['status'] = $state['status'];
    $normalized['ticket'] = $ticket;
    return $normalized;
}

/** Return fresh state with zero progress and no active lease.
 * @return array{version:int,status:string,cursor_gallery_id:int,scanned:int,ready:int,failed:int,attempt:int,ticket:string,queued_until:int,next_attempt_at:int,completed_at:int} Initial state for the first automatic scan.
 */
function gallery_attachment_usage_background_empty_state(): array
{
    return [
        'version' => 1,
        'status' => 'pending',
        'cursor_gallery_id' => 0,
        'scanned' => 0,
        'ready' => 0,
        'failed' => 0,
        'attempt' => 0,
        'ticket' => '',
        'queued_until' => 0,
        'next_attempt_at' => 0,
        'completed_at' => 0,
    ];
}

/** Persist validated state through the settings service.
 * @param array{version:int,status:string,cursor_gallery_id:int,scanned:int,ready:int,failed:int,attempt:int,ticket:string,queued_until:int,next_attempt_at:int,completed_at:int} $state Complete background state.
 * @return void Stores durable progress before dispatch.
 * @throws \RuntimeException When JSON encoding fails.
 */
function gallery_attachment_usage_background_write_state(array $state): void
{
    $json = json_encode($state, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new \RuntimeException('Background state could not be encoded.');
    }
    set_app_setting(
        GALLERY_ATTACHMENT_USAGE_BACKGROUND_STATE_SETTING,
        $json,
    );
}

/** Schedule a retry while retaining progress, or restart after a failed full pass.
 * @param array{version:int,status:string,cursor_gallery_id:int,scanned:int,ready:int,failed:int,attempt:int,ticket:string,queued_until:int,next_attempt_at:int,completed_at:int} $state Current state being retired.
 * @param int $now Current Unix time.
 * @param bool $restart Whether a completed-but-failed pass restarts from gallery zero.
 * @return array{version:int,status:string,cursor_gallery_id:int,scanned:int,ready:int,failed:int,attempt:int,ticket:string,queued_until:int,next_attempt_at:int,completed_at:int} Retry state with no active ticket and explicit backoff.
 */
function gallery_attachment_usage_background_retry_state(
    array $state,
    int $now,
    bool $restart = false,
): array {
    $state['status'] = 'retry';
    $state['ticket'] = '';
    $state['queued_until'] = 0;
    $state['next_attempt_at'] = $now
        + GALLERY_ATTACHMENT_USAGE_BACKGROUND_RETRY_SECONDS;
    $state['attempt']++;
    if ($restart) {
        $state['cursor_gallery_id'] = 0;
        $state['scanned'] = 0;
        $state['ready'] = 0;
        $state['failed'] = 0;
    }
    return $state;
}

/** Refuse automatic snapshots while a central mutation or updater owns the site.
 * @return bool True when an active owner must finish first.
 * @throws \RuntimeException When ownership cannot be established safely.
 */
function gallery_attachment_usage_background_owner_busy(): bool
{
    if (
        !function_exists('Gallery\\Models\\maintenance_center_model_mutation_owner')
        || !function_exists('Gallery\\Services\\application_update_active_job')
    ) {
        throw new \RuntimeException('Automatic mutation ownership is unavailable.');
    }
    $owner = \Gallery\Models\maintenance_center_model_mutation_owner(true);
    if (
        $owner !== null
        && !in_array(
            $owner['status'] ?? '',
            ['completed', 'failed', 'cancelled'],
            true,
        )
    ) {
        return true;
    }
    return application_update_active_job() !== null;
}

/** Retire a failed handoff only when its ticket still owns the queued state.
 * @param string $ticket Opaque ticket whose dispatcher reported failure.
 * @return array{version:int,status:string,cursor_gallery_id:int,scanned:int,ready:int,failed:int,attempt:int,ticket:string,queued_until:int,next_attempt_at:int,completed_at:int}|null Updated state, null when another worker already replaced the ticket.
 */
function gallery_attachment_usage_background_retire_ticket(
    string $ticket,
): ?array {
    $lock = false;
    try {
        $lock = gallery_attachment_usage_background_lock();
        if ($lock === false) {
            return null;
        }
        $state = gallery_attachment_usage_background_read_state();
        if (
            $state === null
            || $state['status'] !== 'queued'
            || !hash_equals($state['ticket'], $ticket)
        ) {
            return $state;
        }
        $state = gallery_attachment_usage_background_retry_state(
            $state,
            time(),
        );
        gallery_attachment_usage_background_write_state($state);
        return $state;
    } catch (\Throwable) {
        error_log('Gallery attachment usage retry state failed safely.');
        return null;
    } finally {
        gallery_attachment_usage_background_unlock($lock);
    }
}

/** Acquire the persistent single-chain lock without waiting.
 * @return resource|false Open private lock handle, or false while another worker is active.
 * @throws \RuntimeException When the lock path is unsafe or storage is unavailable.
 */
function gallery_attachment_usage_background_lock(): mixed
{
    $root = defined('CACHE_LIFECYCLE_TEST_ROOT')
        ? (string) constant('CACHE_LIFECYCLE_TEST_ROOT')
        : dirname(__DIR__, 2);
    $relativePath = 'cache/site-maintenance/gallery-attachment-usage.lock';
    if (!function_exists(__NAMESPACE__ . '\\application_update_assert_safe_target')) {
        require_once __DIR__ . '/updates_path_safety.php';
    }
    application_update_assert_safe_target($root, $relativePath, true);
    if (!function_exists(__NAMESPACE__ . '\\site_maintenance_cache_dir')) {
        throw new \RuntimeException('Maintenance control storage is unavailable.');
    }
    $directory = site_maintenance_cache_dir();
    application_update_assert_safe_target($root, $relativePath, true);
    $path = $directory . DIRECTORY_SEPARATOR . 'gallery-attachment-usage.lock';
    if (is_link($path)) {
        throw new \RuntimeException('Background lock path is unsafe.');
    }
    $handle = @fopen($path, 'c');
    if (!is_resource($handle)) {
        throw new \RuntimeException('Background lock is unavailable.');
    }
    @chmod($path, GALLERY_ATTACHMENT_USAGE_PRIVATE_FILE_MODE);
    $wouldBlock = 0;
    if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
        fclose($handle);
        if ($wouldBlock !== 0) {
            return false;
        }
        throw new \RuntimeException('Background lock is unavailable.');
    }
    application_update_assert_safe_target($root, $relativePath);
    return $handle;
}

/** Release a background worker lock after state publication.
 * @param resource|false $lock Resource handle, or false before successful admission.
 * @return void Releases and closes a held handle.
 */
function gallery_attachment_usage_background_unlock(mixed $lock): void
{
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Build the bounded HTTP-safe progress result.
 * @param bool $ok Whether the request was admitted and state handling succeeded.
 * @param bool $queued Whether a private worker handoff was accepted.
 * @param bool $done Whether the scan is durably complete.
 * @param string $reason Stable low-detail outcome identifier.
 * @param array{version:int,status:string,cursor_gallery_id:int,scanned:int,ready:int,failed:int,attempt:int,ticket:string,queued_until:int,next_attempt_at:int,completed_at:int}|null $state Current progress when readable.
 * @return array{ok:bool,queued:bool,done:bool,reason:string,scanned:int,ready:int,failed:int,completed:bool} Safe response without paths or exception details.
 */
function gallery_attachment_usage_background_result(
    bool $ok,
    bool $queued,
    bool $done,
    string $reason,
    ?array $state = null,
): array {
    return [
        'ok' => $ok,
        'queued' => $queued,
        'done' => $done,
        'reason' => $reason,
        'scanned' => (int) ($state['scanned'] ?? 0),
        'ready' => (int) ($state['ready'] ?? 0),
        'failed' => (int) ($state['failed'] ?? 0),
        'completed' => $done,
    ];
}
