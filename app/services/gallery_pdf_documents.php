<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_pdf_documents.php
 * Module Type: Service Module
 * Purpose: Store independent original PDF documents inside physical galleries.
 * Responsibilities:
 *   - Validate gallery confinement, PDF bytes and document metadata
 *   - Serialize and atomically publish gallery-scoped attachment operations
 *   - Keep general PDF documents separate from SimBrief OFP manifests
 *   - Measure confined PDF/music originals for explicit Admin storage jobs
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Last Updated: 2026-10-12
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;

/**
 * Measure indexed attachment originals without decoding or hashing payloads.
 *
 * Storage accounting is independent of visibility, download permission and
 * feature state. Each physical copy consumes bytes; a Smart playlist consumes
 * none. Invalid indexes remain untouched and contribute a warning, while other
 * independent categories are still measured. Unindexed staging/backup files
 * and sidecar metadata are intentionally outside these original-media totals.
 *
 * @param array{id:int,folder_path:string} $gallery Registered physical gallery.
 * @return array{pdf_count:int,pdf_bytes:int,music_count:int,music_bytes:int,
 *     errors:int} Known confined originals and partial-accounting warnings.
 */
function gallery_attachment_storage_summary(array $gallery): array
{
    $summary = ['pdf_count' => 0, 'pdf_bytes' => 0,
        'music_count' => 0, 'music_bytes' => 0, 'errors' => 0];
    $root = gallery_pdf_documents_gallery_directory($gallery);
    if ($root === null) {
        $summary['errors']++;
        return $summary;
    }
    foreach (['pdf', 'music'] as $kind) {
        try {
            $entries = $kind === 'pdf'
                ? gallery_pdf_documents_read_index($root)
                : \Gallery\Models\gallery_music_model_read(
                    $root . '/.gallery-music'
                )['tracks'];
        } catch (RuntimeException) {
            $summary['errors']++;
            continue;
        }
        foreach ($entries as $entry) {
            $relative = $kind === 'pdf'
                ? '/.gallery-pdf-documents/' . $entry['id'] . '.pdf'
                : '/.gallery-music/' . $entry['id'] . '.bin';
            $bytes = gallery_attachment_storage_file_size($root, $relative);
            if ($bytes === null) {
                $summary['errors']++;
                continue;
            }
            if ($bytes !== $entry['size']) $summary['errors']++;
            $summary[$kind . '_count']++;
            $summary[$kind . '_bytes'] += $bytes;
        }
    }
    $ofp = simbrief_ofp_local_pdf_path($gallery);
    if ($ofp !== null) {
        $bytes = gallery_attachment_storage_file_size(
            $root, '/simbrief-ofp.pdf'
        );
        if ($bytes === null) {
            $summary['errors']++;
        } else {
            $summary['pdf_count']++;
            $summary['pdf_bytes'] += $bytes;
        }
    } elseif (file_exists($root . '/simbrief-ofp.pdf')
        || is_link($root . '/simbrief-ofp.pdf')) {
        $summary['errors']++;
    }
    return $summary;
}

/**
 * Stat one expected original while refusing symlinks and escaped paths.
 * @param string $root Canonical confined physical gallery directory.
 * @param string $relative Fixed sidecar path built from a validated opaque ID.
 * @return int|null Actual bytes, including zero, or null for an unsafe/missing
 *     or unreadable original. This is accounting, not media integrity proof.
 */
function gallery_attachment_storage_file_size(
    string $root,
    string $relative
): ?int {
    $candidate = $root . $relative;
    $resolved = realpath($candidate);
    if ($resolved === false || is_link($candidate)
        || is_link(dirname($candidate)) || !is_file($resolved)
        || !\Gallery\Core\path_inside($root, $resolved)) {
        return null;
    }
    $bytes = @filesize($resolved);
    return $bytes === false ? null : max(0, $bytes);
}

/**
 * Maximum size accepted for one general gallery PDF document.
 * @var int
 * Units: bytes.
 * Scope: one uploaded gallery PDF document.
 * Consumers: gallery_pdf_documents_validate_pdf_source.
 * Rationale: Match the existing OFP attachment limit and bound gallery storage use.
 */
const GALLERY_PDF_DOCUMENT_MAX_BYTES = 26214400;

/**
 * Resolve a physical gallery directory without following a gallery symlink.
 *
 * @param array<string,mixed> $gallery Persisted physical gallery row with id and folder_path.
 * @return ?string Confined absolute directory, or null if the gallery is unsafe.
 */
function gallery_pdf_documents_gallery_directory(array $gallery): ?string
{
    if ((int) ($gallery['id'] ?? 0) < 1 || !function_exists(__NAMESPACE__ . '\\gallery_abs_path')
        || !function_exists(__NAMESPACE__ . '\\galleries_root') || !function_exists('Gallery\\Core\\path_inside')) {
        return null;
    }
    $relative = trim((string) ($gallery['folder_path'] ?? ''));
    if ($relative === '' || str_contains($relative, "\0")) {
        return null;
    }
    $candidate = gallery_abs_path($relative);
    $resolved = realpath($candidate);
    $base = realpath(galleries_root());
    if ($resolved === false || $base === false || is_link($candidate)
        || !\Gallery\Core\path_inside($base, $resolved)) {
        return null;
    }
    return $resolved;
}

/**
 * Validate a local source file using the existing OFP PDF byte policy.
 *
 * @param string $source Local source file selected by an authenticated caller.
 * @return array{size:int,sha256:string} Validated source size and exact-byte digest.
 * @throws RuntimeException When the source is not a bounded, complete PDF.
 */
function gallery_pdf_documents_validate_pdf_source(string $source): array
{
    if (!is_file($source) || is_link($source)) {
        throw new RuntimeException('Select a readable PDF file.');
    }
    $size = @filesize($source);
    if ($size === false || $size < 16 || $size > GALLERY_PDF_DOCUMENT_MAX_BYTES) {
        throw new RuntimeException('The PDF must be between 16 bytes and 25 MiB.');
    }
    $handle = @fopen($source, 'rb');
    if ($handle === false) {
        throw new RuntimeException('The uploaded PDF cannot be read.');
    }
    try {
        $magic = fread($handle, 5);
        if (fseek($handle, max(0, $size - 4096), SEEK_SET) !== 0) {
            throw new RuntimeException('The uploaded PDF is incomplete.');
        }
        $tail = stream_get_contents($handle);
    } finally {
        fclose($handle);
    }
    $mime = function_exists('finfo_open') ? (new \finfo(FILEINFO_MIME_TYPE))->file($source) : null;
    if ($magic !== '%PDF-' || !is_string($tail) || !str_contains($tail, '%%EOF')
        || ($mime !== null && !in_array($mime, ['application/pdf', 'application/x-pdf'], true))) {
        throw new RuntimeException('The file is not a valid PDF document.');
    }
    $digest = hash_file('sha256', $source);
    if (!is_string($digest)) {
        throw new RuntimeException('The PDF digest could not be calculated.');
    }
    return ['size' => $size, 'sha256' => $digest];
}

/**
 * Read document metadata and reserved legacy-OFP visibility from the trusted index.
 *
 * @param string $root Existing confined gallery directory.
 * @return array{documents:array<string,array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string,hidden:bool}>,ofp_hidden:bool} Valid manual records and the legacy OFP visibility value.
 * @throws RuntimeException When the index or storage directory is unsafe.
 */
function gallery_pdf_documents_read_index_state(string $root): array
{
    $directory = $root . DIRECTORY_SEPARATOR . '.gallery-pdf-documents';
    if (is_link($directory)) {
        throw new RuntimeException('PDF document storage is unsafe.');
    }
    if (!is_dir($directory)) {
        return ['documents' => [], 'ofp_hidden' => false];
    }
    $indexPath = $directory . DIRECTORY_SEPARATOR . 'index.json';
    if (is_link($indexPath)) {
        throw new RuntimeException('PDF document index is unsafe.');
    }
    if (!is_file($indexPath)) {
        return ['documents' => [], 'ofp_hidden' => false];
    }
    if ((int) @filesize($indexPath) > 262144) {
        throw new RuntimeException('PDF document index exceeds its storage limit.');
    }
    $decoded = json_decode((string) @file_get_contents($indexPath), true);
    if (!is_array($decoded) || ($decoded['format'] ?? '') !== 'php_gallery_pdf_documents_v1'
        || !is_array($decoded['documents'] ?? null)
        || (array_key_exists('ofp_hidden', $decoded) && !is_bool($decoded['ofp_hidden']))) {
        throw new RuntimeException('PDF document index is invalid and was preserved.');
    }
    $documents = [];
    foreach ($decoded['documents'] as $id => $entry) {
        if (!is_string($id) || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || !is_array($entry)
            || ($entry['id'] ?? null) !== $id || !is_int($entry['size'] ?? null)
            || !is_string($entry['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $entry['sha256']) !== 1
            || !is_string($entry['filename'] ?? null) || !is_string($entry['title'] ?? null)
            || !is_string($entry['provenance'] ?? null) || !is_string($entry['created_at'] ?? null)
            || !is_string($entry['updated_at'] ?? null)
            || (array_key_exists('hidden', $entry) && !is_bool($entry['hidden']))) {
            throw new RuntimeException('PDF document index contains an invalid record and was preserved.');
        }
        $entry['hidden'] = $entry['hidden'] ?? false;
        $documents[$id] = $entry;
    }
    return ['documents' => $documents, 'ofp_hidden' => (bool) ($decoded['ofp_hidden'] ?? false)];
}

/**
 * Read only manual PDF records from the gallery attachment index.
 * @param string $root Existing confined gallery directory.
 * @return array<string,array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string,hidden:bool}> Valid records keyed by opaque id.
 * @throws RuntimeException When the index or storage directory is unsafe.
 */
function gallery_pdf_documents_read_index(string $root): array
{
    return gallery_pdf_documents_read_index_state($root)['documents'];
}

/**
 * List valid general PDF document metadata for one physical gallery.
 *
 * @param array<string,mixed> $gallery Persisted physical gallery row.
 * @return list<array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string,hidden:bool}> Ordered document metadata; an unsafe or missing gallery has no entries.
 */
function gallery_pdf_documents_list(array $gallery): array
{
    $root = gallery_pdf_documents_gallery_directory($gallery);
    if ($root === null) {
        return [];
    }
    $documents = gallery_pdf_documents_read_index($root);
    $result = [];
    foreach ($documents as $id => $metadata) {
        $path = $root . DIRECTORY_SEPARATOR . '.gallery-pdf-documents' . DIRECTORY_SEPARATOR . $id . '.pdf';
        if (is_link($path) || !is_file($path) || (int) @filesize($path) !== $metadata['size']
            || !hash_equals($metadata['sha256'], (string) @hash_file('sha256', $path))) {
            continue;
        }
        $result[] = $metadata;
    }
    return $result;
}

/**
 * Attach a new original PDF or replace one after explicit confirmation.
 *
 * @param array<string,mixed> $gallery Persisted physical gallery row.
 * @param string $source Local validated upload temporary file.
 * @param string $filename Original user-facing filename; stored as metadata only.
 * @param string $title User-facing document title.
 * @param string $provenance Short origin label describing how the PDF was obtained.
 * @param ?string $replaceId Existing opaque document id to replace, or null to add.
 * @param bool $confirmed Whether the caller received explicit replacement confirmation.
 * @return array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string,hidden:bool} Saved document metadata.
 * @throws RuntimeException When validation, confirmation, locking or atomic publication fails.
 */
function gallery_pdf_documents_attach(
    array $gallery,
    string $source,
    string $filename,
    string $title,
    string $provenance,
    ?string $replaceId = null,
    bool $confirmed = false
): array {
    $root = gallery_pdf_documents_gallery_directory($gallery);
    if ($root === null) {
        throw new RuntimeException('The selected gallery folder is unavailable.');
    }
    $validated = gallery_pdf_documents_validate_pdf_source($source);
    $filename = basename(str_replace('\\', '/', trim($filename)));
    $filename = preg_replace('/[\x00-\x1F\x7F]/u', '', $filename) ?? '';
    $filename = trim(substr($filename, 0, 240));
    $title = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $title) ?? '');
    $provenance = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $provenance) ?? '');
    if ($filename === '' || $title === '' || strlen($title) > 240 || $provenance === '' || strlen($provenance) > 80) {
        throw new RuntimeException('Provide a filename, a title and a short document origin.');
    }
    if ($replaceId !== null && preg_match('/^[a-f0-9]{32}$/D', $replaceId) !== 1) {
        throw new RuntimeException('The selected PDF document id is invalid.');
    }
    $directory = $root . DIRECTORY_SEPARATOR . '.gallery-pdf-documents';
    if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700))) {
        throw new RuntimeException('PDF document storage is unavailable or unsafe.');
    }
    $lockPath = $directory . DIRECTORY_SEPARATOR . '.lock';
    if (is_link($lockPath)) {
        throw new RuntimeException('PDF document storage is unsafe.');
    }
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('PDF document storage cannot be locked.');
    }
    @chmod($lockPath, 0600);
    $stagePdf = '';
    $stageIndex = '';
    $backupPdf = '';
    $backupIndex = '';
    $restoreTarget = '';
    $committed = false;
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('PDF document storage is busy.');
        }
        $indexState = gallery_pdf_documents_read_index_state($root);
        $documents = $indexState['documents'];
        if ($replaceId !== null && !isset($documents[$replaceId])) {
            throw new RuntimeException('The PDF document no longer exists. Reload the gallery editor.');
        }
        if ($replaceId !== null && !$confirmed) {
            throw new RuntimeException('Replacing an existing PDF requires explicit confirmation.');
        }
        if ($replaceId === null) {
            $id = bin2hex(random_bytes(16));
            while (isset($documents[$id]) || file_exists($directory . DIRECTORY_SEPARATOR . $id . '.pdf')) {
                $id = bin2hex(random_bytes(16));
            }
        } else {
            $id = $replaceId;
        }
        $now = gmdate('Y-m-d\\TH:i:s\\Z');
        $old = $documents[$id] ?? null;
        $entry = [
            'id' => $id,
            'size' => $validated['size'],
            'sha256' => $validated['sha256'],
            'filename' => $filename,
            'title' => $title,
            'provenance' => $provenance,
            'hidden' => is_array($old) ? (bool) ($old['hidden'] ?? false) : false,
            'created_at' => is_array($old) ? $old['created_at'] : $now,
            'updated_at' => $now,
        ];
        $documents[$id] = $entry;
        $json = json_encode(['format' => 'php_gallery_pdf_documents_v1', 'documents' => $documents, 'ofp_hidden' => $indexState['ofp_hidden']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 262144) {
            throw new RuntimeException('PDF document index cannot be stored safely.');
        }
        $suffix = bin2hex(random_bytes(12));
        $targetPdf = $directory . DIRECTORY_SEPARATOR . $id . '.pdf';
        $indexPath = $directory . DIRECTORY_SEPARATOR . 'index.json';
        if (is_link($targetPdf) || is_link($indexPath)) {
            throw new RuntimeException('PDF document storage is unsafe.');
        }
        $stagePdf = $directory . DIRECTORY_SEPARATOR . '.' . $suffix . '.pdf.tmp';
        $stageIndex = $directory . DIRECTORY_SEPARATOR . '.' . $suffix . '.index.tmp';
        if (!gallery_pdf_documents_copy_source($source, $stagePdf, $validated['size'])
            || !hash_equals($validated['sha256'], (string) @hash_file('sha256', $stagePdf))
            || @file_put_contents($stageIndex, $json . "\n", LOCK_EX) !== strlen($json) + 1) {
            throw new RuntimeException('The PDF document could not be staged completely.');
        }
        @chmod($stageIndex, 0600);
        if (is_file($targetPdf)) {
            $backupPdf = $directory . DIRECTORY_SEPARATOR . '.' . $suffix . '.pdf.bak';
            if (!@rename($targetPdf, $backupPdf)) {
                throw new RuntimeException('The existing PDF could not be protected for replacement.');
            }
            $restoreTarget = $targetPdf;
        }
        if (is_file($indexPath)) {
            $backupIndex = $directory . DIRECTORY_SEPARATOR . '.' . $suffix . '.index.bak';
            if (!@copy($indexPath, $backupIndex)) {
                if ($backupPdf !== '') @rename($backupPdf, $targetPdf);
                $backupPdf = '';
                throw new RuntimeException('The existing PDF index could not be backed up.');
            }
            @chmod($backupIndex, 0600);
        }
        if (!@rename($stagePdf, $targetPdf)) {
            throw new RuntimeException('The PDF document could not be installed.');
        }
        $stagePdf = '';
        if (!@rename($stageIndex, $indexPath)) {
            @unlink($targetPdf);
            if ($backupPdf !== '') @rename($backupPdf, $targetPdf);
            if ($backupIndex !== '') @unlink($backupIndex);
            $backupPdf = '';
            $backupIndex = '';
            throw new RuntimeException('The PDF index could not be installed; the prior document was restored.');
        }
        $stageIndex = '';
        foreach ([$backupPdf, $backupIndex] as $backup) {
            if ($backup !== '') @unlink($backup);
        }
        $backupPdf = '';
        $backupIndex = '';
        $committed = true;
        return $entry;
    } finally {
        if (!$committed && $backupPdf !== '' && $restoreTarget !== '') {
            if (is_file($restoreTarget)) @unlink($restoreTarget);
            @rename($backupPdf, $restoreTarget);
            $backupPdf = '';
        }
        foreach ([$stagePdf, $stageIndex, $backupPdf, $backupIndex] as $temporary) {
            if ($temporary !== '' && is_file($temporary)) @unlink($temporary);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Copy a source PDF into a private exclusive staging file with a byte ceiling.
 *
 * @param string $source Validated local source path.
 * @param string $destination New staging path that must not already exist.
 * @param int $expectedSize Expected source byte count from validation.
 * @return bool True when the exact expected number of bytes was copied.
 */
function gallery_pdf_documents_copy_source(string $source, string $destination, int $expectedSize): bool
{
    $input = @fopen($source, 'rb');
    $output = @fopen($destination, 'xb');
    if ($input === false || $output === false) {
        if (is_resource($input)) fclose($input);
        if (is_resource($output)) fclose($output);
        return false;
    }
    @chmod($destination, 0600);
    $copied = 0;
    try {
        while (!feof($input)) {
            $chunk = fread($input, 65536);
            if (!is_string($chunk) || ($chunk === '' && !feof($input))) return false;
            $copied += strlen($chunk);
            if ($copied > $expectedSize || fwrite($output, $chunk) !== strlen($chunk)) return false;
        }
        return fflush($output) && $copied === $expectedSize;
    } finally {
        fclose($input);
        fclose($output);
    }
}

/**
 * Resolve one verified original PDF by its opaque gallery-scoped document id.
 *
 * @param array<string,mixed> $gallery Persisted physical gallery row.
 * @param string $id Opaque 32-character lowercase hexadecimal document id.
 * @return ?array{path:string,metadata:array{id:string,size:int,sha256:string,filename:string,title:string,provenance:string,created_at:string,updated_at:string,hidden:bool}} Confined original path and trusted metadata, or null when unavailable.
 */
function gallery_pdf_documents_read(array $gallery, string $id): ?array
{
    if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
        return null;
    }
    $root = gallery_pdf_documents_gallery_directory($gallery);
    if ($root === null) {
        return null;
    }
    $documents = gallery_pdf_documents_read_index($root);
    $metadata = $documents[$id] ?? null;
    if (!is_array($metadata)) {
        return null;
    }
    $directory = $root . DIRECTORY_SEPARATOR . '.gallery-pdf-documents';
    $path = $directory . DIRECTORY_SEPARATOR . $id . '.pdf';
    $resolvedDirectory = realpath($directory);
    $resolved = realpath($path);
    if ($resolvedDirectory === false || $resolved === false || is_link($directory) || is_link($path)
        || !\Gallery\Core\path_inside($root, $resolvedDirectory)
        || !\Gallery\Core\path_inside($resolvedDirectory, $resolved)
        || !is_file($resolved) || (int) @filesize($resolved) !== $metadata['size']
        || !hash_equals($metadata['sha256'], (string) @hash_file('sha256', $resolved))) {
        return null;
    }
    return ['path' => $resolved, 'metadata' => $metadata];
}

/**
 * Change whether one indexed manual PDF is visible to gallery visitors.
 * @param array<string,mixed> $gallery Persisted physical gallery row.
 * @param string $id Opaque document id selected from the gallery's trusted index.
 * @param bool $hidden Whether public and shared visitors must be denied the PDF.
 * @return bool True when the stored visibility changed; false when the document is absent.
 * @throws RuntimeException When the storage cannot be safely locked or atomically updated.
 */
function gallery_pdf_documents_set_hidden(array $gallery, string $id, bool $hidden): bool
{
    $root = gallery_pdf_documents_gallery_directory($gallery);
    if ($root === null || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) return false;
    $directory = $root . DIRECTORY_SEPARATOR . '.gallery-pdf-documents';
    if (is_link($directory) || !is_dir($directory)) return false;
    $lockPath = $directory . DIRECTORY_SEPARATOR . '.lock';
    if (is_link($lockPath)) throw new RuntimeException('PDF document storage is unsafe.');
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException('PDF document storage cannot be locked.');
    $stage = '';
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('PDF document storage is busy.');
        $indexState = gallery_pdf_documents_read_index_state($root);
        $documents = $indexState['documents'];
        if (!isset($documents[$id])) return false;
        $documents[$id]['hidden'] = $hidden;
        $documents[$id]['updated_at'] = gmdate('Y-m-d\\TH:i:s\\Z');
        $json = json_encode(['format' => 'php_gallery_pdf_documents_v1', 'documents' => $documents, 'ofp_hidden' => $indexState['ofp_hidden']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 262144) throw new RuntimeException('PDF document index cannot be stored safely.');
        $indexPath = $directory . DIRECTORY_SEPARATOR . 'index.json';
        if (is_link($indexPath)) throw new RuntimeException('PDF document index is unsafe.');
        $stage = $directory . DIRECTORY_SEPARATOR . '.' . bin2hex(random_bytes(12)) . '.index.tmp';
        if (@file_put_contents($stage, $json . "\n", LOCK_EX) !== strlen($json) + 1) throw new RuntimeException('Updated PDF visibility could not be staged.');
        @chmod($stage, 0600);
        if (!@rename($stage, $indexPath)) throw new RuntimeException('Updated PDF visibility could not be installed.');
        $stage = '';
        return true;
    } finally {
        if ($stage !== '' && is_file($stage)) @unlink($stage);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Read the persisted hidden state for a manual PDF or the legacy SimBrief OFP.
 * @param array<string,mixed> $gallery Persisted physical gallery row.
 * @param string $id Opaque manual document id or the reserved legacy id `ofp`.
 * @return bool Whether the document is hidden; old or missing metadata defaults to visible.
 */
function gallery_pdf_documents_hidden(array $gallery, string $id): bool
{
    $root = gallery_pdf_documents_gallery_directory($gallery);
    if ($root === null) return false;
    try {
        $state = gallery_pdf_documents_read_index_state($root);
    } catch (\Throwable) {
        return true;
    }
    if ($id === 'ofp') return $state['ofp_hidden'];
    return (bool) ($state['documents'][$id]['hidden'] ?? false);
}

/**
 * Change visibility of the legacy gallery-local SimBrief OFP without deleting it.
 * @param array<string,mixed> $gallery Persisted physical gallery row.
 * @param bool $hidden Whether public and shared visitors must be denied the OFP.
 * @return bool True when the visibility marker was atomically stored.
 * @throws RuntimeException When the gallery storage cannot be safely locked or updated.
 */
function gallery_pdf_documents_set_ofp_hidden(array $gallery, bool $hidden): bool
{
    $root = gallery_pdf_documents_gallery_directory($gallery);
    if ($root === null) throw new RuntimeException('The selected gallery folder is unavailable.');
    $directory = $root . DIRECTORY_SEPARATOR . '.gallery-pdf-documents';
    if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700))) throw new RuntimeException('PDF document storage is unavailable or unsafe.');
    $lockPath = $directory . DIRECTORY_SEPARATOR . '.lock';
    if (is_link($lockPath)) throw new RuntimeException('PDF document storage is unsafe.');
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException('PDF document storage cannot be locked.');
    $stage = '';
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('PDF document storage is busy.');
        $indexPath = $directory . DIRECTORY_SEPARATOR . 'index.json';
        if (is_link($indexPath)) throw new RuntimeException('PDF document index is unsafe.');
        $indexState = gallery_pdf_documents_read_index_state($root);
        $indexState['ofp_hidden'] = $hidden;
        $stage = $directory . DIRECTORY_SEPARATOR . '.' . bin2hex(random_bytes(12)) . '.index.tmp';
        $json = json_encode(['format' => 'php_gallery_pdf_documents_v1', 'documents' => $indexState['documents'], 'ofp_hidden' => $hidden], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || strlen($json) > 262144 || @file_put_contents($stage, $json . "\n", LOCK_EX) !== strlen($json) + 1) throw new RuntimeException('OFP visibility could not be staged.');
        @chmod($stage, 0600);
        if (!@rename($stage, $indexPath)) throw new RuntimeException('OFP visibility could not be installed.');
        $stage = '';
        return true;
    } finally {
        if ($stage !== '' && is_file($stage)) @unlink($stage);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/**
 * Delete one original PDF only after explicit confirmation.
 *
 * @param array<string,mixed> $gallery Persisted physical gallery row.
 * @param string $id Opaque document id selected from the gallery's trusted index.
 * @param bool $confirmed Whether the caller received explicit deletion confirmation.
 * @return bool True when the document was deleted; false when it no longer exists.
 * @throws RuntimeException When confirmation is missing or safe atomic deletion fails.
 */
function gallery_pdf_documents_delete(array $gallery, string $id, bool $confirmed = false): bool
{
    $root = gallery_pdf_documents_gallery_directory($gallery);
    if ($root === null || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
        return false;
    }
    if (!$confirmed) {
        throw new RuntimeException('Deleting a PDF document requires explicit confirmation.');
    }
    $directory = $root . DIRECTORY_SEPARATOR . '.gallery-pdf-documents';
    if (is_link($directory) || !is_dir($directory)) return false;
    $lockPath = $directory . DIRECTORY_SEPARATOR . '.lock';
    if (is_link($lockPath)) throw new RuntimeException('PDF document storage is unsafe.');
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) throw new RuntimeException('PDF document storage cannot be locked.');
    $backupPdf = '';
    $stageIndex = '';
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('PDF document storage is busy.');
        $indexState = gallery_pdf_documents_read_index_state($root);
        $documents = $indexState['documents'];
        if (!isset($documents[$id])) return false;
        $path = $directory . DIRECTORY_SEPARATOR . $id . '.pdf';
        $indexPath = $directory . DIRECTORY_SEPARATOR . 'index.json';
        if (is_link($path) || is_link($indexPath) || !is_file($path)) {
            throw new RuntimeException('PDF document storage is unsafe or incomplete.');
        }
        unset($documents[$id]);
        $json = json_encode(['format' => 'php_gallery_pdf_documents_v1', 'documents' => $documents, 'ofp_hidden' => $indexState['ofp_hidden']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new RuntimeException('PDF document index cannot be stored safely.');
        $suffix = bin2hex(random_bytes(12));
        $backupPdf = $directory . DIRECTORY_SEPARATOR . '.' . $suffix . '.pdf.bak';
        $stageIndex = $directory . DIRECTORY_SEPARATOR . '.' . $suffix . '.index.tmp';
        if (@file_put_contents($stageIndex, $json . "\n", LOCK_EX) !== strlen($json) + 1) {
            throw new RuntimeException('Updated PDF document index could not be staged.');
        }
        @chmod($stageIndex, 0600);
        if (!@rename($path, $backupPdf)) throw new RuntimeException('The original PDF could not be protected for deletion.');
        if (!@rename($stageIndex, $indexPath)) {
            @rename($backupPdf, $path);
            $backupPdf = '';
            throw new RuntimeException('The updated PDF index could not be installed; the original was restored.');
        }
        $stageIndex = '';
        @unlink($backupPdf);
        $backupPdf = '';
        return true;
    } finally {
        foreach ([$backupPdf, $stageIndex] as $temporary) {
            if ($temporary !== '' && is_file($temporary)) @unlink($temporary);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
