<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/media_renamer/reports.php
 * Module Type: Service Part
 * Purpose: Retain bounded rename outcomes for authenticated lazy detail reads.
 * Responsibilities:
 *   - Write detail chunks only while executing physical rename batches.
 *   - Keep compact per-gallery counters separate from requested detail pages.
 *   - Bind reports to the initiating account/session and expire owned records.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use FilesystemIterator;
use RuntimeException;
use const Gallery\Core\MEDIA_RENAMER_PHYSICAL_BATCH_SIZE;

/**
 * Retain an authenticated report long enough for post-operation inspection.
 * Type: int. Units: seconds. Scope: one Admin rename report.
 * Consumers: report reads and pruning. Rationale: 86400 allows review for one
 * day without turning transient filename history into permanent storage.
 */
const MEDIA_RENAMER_REPORT_TTL = 86400;

/**
 * Bound the compact report index independently of stored filename chunks.
 * Type: int. Units: UTF-8 bytes. Scope: one report manifest.
 * Consumers: report load/store. Rationale: 1 MiB accommodates many galleries
 * and thousands of batch references without an unbounded JSON allocation.
 */
const MEDIA_RENAMER_REPORT_INDEX_BYTES = 1024 * 1024;

/**
 * Bound one batch's encoded old/new filename evidence.
 * Type: int. Units: UTF-8 bytes. Scope: one report detail chunk.
 * Consumers: report append/read. Rationale: 512 KiB preserves ordinary bounded
 * batches and warnings; oversized evidence is refused rather than truncated.
 */
const MEDIA_RENAMER_REPORT_CHUNK_BYTES = 512 * 1024;

/**
 * Keep report JSON parsing close to its shallow owned format.
 * Type: int. Units: nested JSON levels. Scope: report files.
 * Consumers: report decoders. Rationale: 16 exceeds the six levels of the
 * manifest/notes schema while bounding malformed externally changed input.
 */
const MEDIA_RENAMER_REPORT_JSON_DEPTH = 16;

/**
 * Allocate unguessable report identifiers without exposing session material.
 * Type: int. Units: random bytes. Scope: new report directories.
 * Consumers: report append. Rationale: 16 bytes supply 128 bits of entropy;
 * authorization additionally requires the exact authenticated owner hash.
 */
const MEDIA_RENAMER_REPORT_TOKEN_BYTES = 16;

/**
 * Limit housekeeping performed alongside an actual rename operation.
 * Type: float. Units: seconds. Scope: one new report.
 * Consumers: report prune. Rationale: 0.05 seconds bounds new work admission;
 * expired reports remain unreadable even if deletion needs a later pass.
 */
const MEDIA_RENAMER_REPORT_PRUNE_SECONDS = 0.05;

/**
 * Resolve the private report root; analysis never calls this filesystem owner.
 * @return string Absolute project-cache directory protected by the root web rules.
 */
function media_renamer_report_root(): string
{
    // This service part is one directory deeper than its historical entry.
    return dirname(__DIR__, 3) . '/cache/media-renamer-reports';
}

/**
 * Read an owned bounded JSON file without following a named symbolic link.
 * @param string $path Internal manifest/chunk path below the validated report root.
 * @param int $limit Maximum complete encoded bytes accepted by this file format.
 * @return string Complete UTF-8 JSON bytes; no truncated evidence is accepted.
 * @throws RuntimeException When the file is missing, unsafe or oversized.
 */
function media_renamer_report_bytes(string $path, int $limit): string
{
    if (is_link($path) || !is_file($path)) {
        throw new RuntimeException('Rename report is unavailable.');
    }
    $body = @file_get_contents($path, false, null, 0, $limit + 1);
    if (!is_string($body) || strlen($body) > $limit) {
        throw new RuntimeException('Rename report is unavailable.');
    }
    return $body;
}

/**
 * Load the small index and enforce authenticated ownership and expiry.
 * @param string $token Exact lowercase report identifier.
 * @param ?string $owner SHA-256 account/session binding; null is internal GC only.
 * @param bool $allowExpired Permit producer verification for internal GC only.
 * @return array{version:int,owner:string,created_at:int,galleries:array<int|string,array{gallery_id:int,gallery:string,total:int,renamed:int,skipped:int,failed:int,pages:list<array{key:string,count:int}>}>} Validated compact report index.
 * @throws RuntimeException When identity, ownership, format or lifetime is invalid.
 */
function media_renamer_report_load(
    string $token, ?string $owner, bool $allowExpired = false,
): array {
    $root = media_renamer_report_root();
    $directory = $root . '/' . $token;
    if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1
        || is_link($root) || is_link(dirname($root)) || is_link($directory)
        || !is_dir($directory)) {
        throw new RuntimeException('Rename report is unavailable.');
    }
    $record = json_decode(media_renamer_report_bytes(
        $directory . '/manifest.json', MEDIA_RENAMER_REPORT_INDEX_BYTES,
    ), true, MEDIA_RENAMER_REPORT_JSON_DEPTH, JSON_THROW_ON_ERROR);
    if (!is_array($record) || ($record['version'] ?? null) !== 1
        || !is_string($record['owner'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $record['owner']) !== 1
        || ($owner !== null && !hash_equals($record['owner'], $owner))
        || !is_int($record['created_at'] ?? null) || $record['created_at'] <= 0
        || $record['created_at'] > time()
        || (!$allowExpired && time() - $record['created_at'] >= MEDIA_RENAMER_REPORT_TTL)
        || !is_array($record['galleries'] ?? null)) {
        throw new RuntimeException('Rename report is unavailable or expired.');
    }
    foreach ($record['galleries'] as $key => $gallery) {
        if (!is_array($gallery) || !is_int($gallery['gallery_id'] ?? null)
            || (string) $key !== (string) $gallery['gallery_id']
            || $gallery['gallery_id'] <= 0 || !is_string($gallery['gallery'] ?? null)
            || !is_array($gallery['pages'] ?? null) || !array_is_list($gallery['pages'])) {
            throw new RuntimeException('Rename report is unavailable.');
        }
        foreach (['total', 'renamed', 'skipped', 'failed'] as $counter) {
            if (!is_int($gallery[$counter] ?? null) || $gallery[$counter] < 0) {
                throw new RuntimeException('Rename report is unavailable.');
            }
        }
        $count = 0;
        foreach ($gallery['pages'] as $page) {
            if (!is_array($page) || !is_string($page['key'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $page['key']) !== 1
                || !is_int($page['count'] ?? null) || $page['count'] <= 0) {
                throw new RuntimeException('Rename report is unavailable.');
            }
            $count += $page['count'];
        }
        if ($count !== $gallery['total']
            || $gallery['renamed'] + $gallery['skipped'] + $gallery['failed'] !== $count) {
            throw new RuntimeException('Rename report is unavailable.');
        }
    }
    return $record;
}

/**
 * Atomically publish an already encoded file while holding the report lease.
 * @param string $path Exact owned manifest/chunk destination.
 * @param string $body Complete encoded JSON bytes.
 * @param int $limit Positive format-specific byte ceiling.
 * @return void Replaces only the owned file after its complete temporary write.
 * @throws RuntimeException When encoding size or atomic publication is refused.
 */
function media_renamer_report_publish(string $path, string $body, int $limit): void
{
    $temporary = $path . '.tmp';
    if (strlen($body) > $limit || is_link($path) || is_link($temporary)
        || @file_put_contents($temporary, $body, LOCK_EX) !== strlen($body)) {
        throw new RuntimeException('Rename detail report could not be saved.');
    }
    @chmod($temporary, 0600);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Rename detail report could not be saved.');
    }
}

/**
 * Save completed batch details and return only the small report identity.
 * @param string $owner SHA-256 binding to the initiating authenticated session.
 * @param string $token Existing report token, or empty for the first batch.
 * @param list<array{gallery_id:int,gallery:string,image_id:int,old:string,new:string,status:string,notes:list<string>}> $details Actual confirmed executor outcomes.
 * @return string Existing or newly allocated report identity; filenames remain server-side.
 * @throws RuntimeException When storage, owner validation or publication fails.
 */
function media_renamer_report_append(string $owner, string $token, array $details): string
{
    if (preg_match('/\A[a-f0-9]{64}\z/D', $owner) !== 1) {
        throw new RuntimeException('Rename report owner is unavailable.');
    }
    $root = media_renamer_report_root();
    if (is_link($root) || is_link(dirname($root))
        || (!is_dir($root) && !@mkdir($root, 0700, true))) {
        throw new RuntimeException('Rename detail report could not be saved.');
    }
    $created = $token === '';
    if ($created) {
        media_renamer_report_prune();
        $token = bin2hex(random_bytes(MEDIA_RENAMER_REPORT_TOKEN_BYTES));
        if (!@mkdir($root . '/' . $token, 0700)) {
            throw new RuntimeException('Rename detail report could not be saved.');
        }
    } else {
        media_renamer_report_load($token, $owner);
    }
    $directory = $root . '/' . $token;
    $lockPath = $directory . '/.lock';
    if (is_link($lockPath) || ($lock = @fopen($lockPath, 'c')) === false) {
        throw new RuntimeException('Rename report is busy.');
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Rename report is busy.');
        }
        $record = $created ? ['version' => 1, 'owner' => $owner,
            'created_at' => time(), 'galleries' => []] : media_renamer_report_load($token, $owner);
        if ($created) {
            media_renamer_report_publish($directory . '/manifest.json',
                json_encode($record, JSON_THROW_ON_ERROR), MEDIA_RENAMER_REPORT_INDEX_BYTES);
        }
        $groups = [];
        foreach ($details as $row) {
            $groups[$row['gallery_id']][] = $row;
        }
        foreach ($groups as $id => $rows) {
            // Classic non-JavaScript apply can confirm many files at once.
            // Keep its journal chunks identical to the AJAX physical batch.
            foreach (array_chunk($rows, MEDIA_RENAMER_PHYSICAL_BATCH_SIZE) as $batch) {
                $encoded = json_encode($batch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $key = hash('sha256', $encoded);
                $gallery = $record['galleries'][$id] ?? ['gallery_id' => (int) $id,
                    'gallery' => $batch[0]['gallery'], 'total' => 0, 'renamed' => 0,
                    'skipped' => 0, 'failed' => 0, 'pages' => []];
                if (in_array($key, array_column($gallery['pages'], 'key'), true)) {
                    continue;
                }
                media_renamer_report_publish($directory . '/' . $key . '.json',
                    $encoded, MEDIA_RENAMER_REPORT_CHUNK_BYTES);
                foreach ($batch as $row) {
                    $gallery['total']++;
                    $counter = $row['status'] === 'renamed' ? 'renamed'
                        : (in_array($row['status'], ['failed', 'missing', 'collision'], true)
                            ? 'failed' : 'skipped');
                    $gallery[$counter]++;
                }
                $gallery['pages'][] = ['key' => $key, 'count' => count($batch)];
                $record['galleries'][$id] = $gallery;
            }
        }
        media_renamer_report_publish($directory . '/manifest.json',
            json_encode($record, JSON_THROW_ON_ERROR), MEDIA_RENAMER_REPORT_INDEX_BYTES);
    } finally {
        fclose($lock);
    }
    return $token;
}

/**
 * Fetch only explicitly requested outcomes, opening no unrelated detail chunk.
 * @param string $owner Authenticated account/session hash.
 * @param string $token Opaque report identity.
 * @param int $galleryId Gallery whose summary the user expanded.
 * @param int $offset Nonnegative detail offset in that gallery.
 * @return array{rows:list<array{gallery_id:int,gallery:string,image_id:int,old:string,new:string,status:string,notes:list<string>}>,next_offset:?int} At most one detail page and its continuation.
 * @throws RuntimeException When owner, paging, integrity or storage is invalid.
 */
function media_renamer_report_page(
    string $owner, string $token, int $galleryId, int $offset,
): array {
    $record = media_renamer_report_load($token, $owner);
    $gallery = $record['galleries'][$galleryId] ?? null;
    if ($offset < 0 || $gallery === null || $offset > $gallery['total']) {
        throw new RuntimeException('Invalid rename report page.');
    }
    $rows = [];
    $skip = $offset;
    foreach ($gallery['pages'] as $page) {
        if ($skip >= $page['count']) {
            $skip -= $page['count'];
            continue;
        }
        $encoded = media_renamer_report_bytes(media_renamer_report_root()
            . '/' . $token . '/' . $page['key'] . '.json', MEDIA_RENAMER_REPORT_CHUNK_BYTES);
        if (!hash_equals($page['key'], hash('sha256', $encoded))) {
            throw new RuntimeException('Rename report integrity is unavailable.');
        }
        $chunk = json_decode($encoded, true, MEDIA_RENAMER_REPORT_JSON_DEPTH, JSON_THROW_ON_ERROR);
        if (!is_array($chunk) || !array_is_list($chunk) || count($chunk) !== $page['count']) {
            throw new RuntimeException('Rename report is unavailable.');
        }
        foreach ($chunk as $row) {
            if (!is_array($row) || ($row['gallery_id'] ?? null) !== $galleryId
                || !is_int($row['image_id'] ?? null) || $row['image_id'] <= 0
                || !is_string($row['gallery'] ?? null)
                || !is_string($row['old'] ?? null) || !is_string($row['new'] ?? null)
                || !is_string($row['status'] ?? null) || !is_array($row['notes'] ?? null)
                || !array_is_list($row['notes'])
                || array_filter($row['notes'],
                    static fn(string|int|float|bool|array|null $note): bool => !is_string($note)) !== []) {
                throw new RuntimeException('Rename report is unavailable.');
            }
        }
        $rows = array_merge($rows, array_slice($chunk, $skip,
            MEDIA_RENAMER_DETAIL_PAGE_SIZE - count($rows)));
        $skip = 0;
        if (count($rows) === MEDIA_RENAMER_DETAIL_PAGE_SIZE) {
            break;
        }
    }
    return ['rows' => $rows, 'next_offset' => $offset + count($rows) < $gallery['total']
        ? $offset + count($rows) : null];
}

/**
 * Reclaim only expired producer-proven report files under a short time budget.
 * @return void Keeps active, unknown, linked or concurrently owned paths intact.
 */
function media_renamer_report_prune(): void
{
    $root = media_renamer_report_root();
    $deadline = microtime(true) + MEDIA_RENAMER_REPORT_PRUNE_SECONDS;
    foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
        if (microtime(true) >= $deadline) break;
        $token = $entry->getFilename();
        if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1 || $entry->isLink()) continue;
        try {
            $record = media_renamer_report_load($token, null, true);
            if (time() - $record['created_at'] < MEDIA_RENAMER_REPORT_TTL) continue;
            $directory = $root . '/' . $token;
            if (is_link($directory . '/.lock')) continue;
            $lock = @fopen($directory . '/.lock', 'c');
            if ($lock === false) continue;
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) continue;
                $complete = true;
                foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
                    if (in_array($file->getFilename(), ['manifest.json', '.lock'], true)) continue;
                    if (microtime(true) >= $deadline || $file->isLink()
                        || preg_match('/\A(?:[a-f0-9]{64}\.json(?:\.tmp)?|manifest\.json\.tmp)\z/D',
                            $file->getFilename()) !== 1 || !@unlink($file->getPathname())) {
                        $complete = false;
                        break;
                    }
                }
                if ($complete) @unlink($directory . '/manifest.json');
            } finally {
                fclose($lock);
            }
            if ($complete) {
                @unlink($directory . '/.lock');
                @rmdir($directory);
            }
        } catch (\Throwable) {
            // Unknown/corrupt evidence never becomes permission to delete it.
            continue;
        }
    }
}
