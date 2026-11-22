<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/media_renamer/candidate_plan.php
 * Module Type: Service Part
 * Purpose: Persist short-lived, bounded candidate snapshots for rename execution.
 * Responsibilities:
 *   - Bind candidate pages to owner, gallery, pattern and catalogue revision.
 *   - Advance a durable candidate cursor only after confirmed physical batches.
 *   - Keep candidate reads limited to compact metadata and requested chunks.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use FilesystemIterator;
use RuntimeException;
use function Gallery\Services\media_renamer_report_bytes;
use function Gallery\Services\media_renamer_report_publish;
use const Gallery\Services\MEDIA_RENAMER_REPORT_JSON_DEPTH;
use const Gallery\Services\MEDIA_RENAMER_REPORT_TOKEN_BYTES;
use const Gallery\Core\MEDIA_RENAMER_PHYSICAL_BATCH_SIZE;

/**
 * Expire plans quickly because their proposal data is transient execution state.
 * Type: int. Units: seconds. Scope: one candidate plan. Consumers: plan loads
 * and garbage collection. Rationale: 1800 seconds bounds stale proposals while
 * allowing a normal multi-batch operation to continue.
 */
const MEDIA_RENAMER_CANDIDATE_PLAN_TTL = 1800;

/**
 * Bound candidate manifest allocation independently of the candidate chunks.
 * Type: int. Units: UTF-8 bytes. Scope: one candidate plan manifest.
 * Consumers: manifest readers/writers. Rationale: 8192 bytes holds identity,
 * cursor and bounded chunk references without permitting gallery-sized indexes.
 */
const MEDIA_RENAMER_CANDIDATE_PLAN_INDEX_BYTES = 8192;

/**
 * Bound each independently addressable candidate ID/sequence chunk.
 * Type: int. Units: candidate rows. Scope: one candidate plan chunk.
 * Consumers: creation and page reads. Rationale: one aligned request reads
 * 500 rows at most; an unaligned physical batch can cross one chunk boundary.
 */
const MEDIA_RENAMER_CANDIDATE_PLAN_CHUNK_ROWS = MEDIA_RENAMER_CANDIDATE_PAGE_SIZE;

/**
 * Bound encoded storage for one chunk of maximum-width integer pairs.
 * Type: int. Units: UTF-8 bytes. Scope: one chunk. Consumers: chunk writers/readers.
 * Rationale: 500 pairs of two 64-bit positive integers and JSON syntax fit
 * within 22 KiB, including separators and enclosing brackets.
 */
const MEDIA_RENAMER_CANDIDATE_PLAN_CHUNK_BYTES = 22 * 1024;

/**
 * Bound the total encoded snapshot retained for one plan.
 * Type: int. Units: UTF-8 bytes. Scope: one candidate plan. Consumers: creation.
 * Rationale: 1 MiB covers forty maximum-width chunks and caps encoded input.
 */
const MEDIA_RENAMER_CANDIDATE_PLAN_TOTAL_BYTES = 1024 * 1024;

/**
 * Bound the number of retained plan directories below the private cache root.
 * Type: int. Units: plan entries. Scope: all owners. Consumers: admission/GC.
 * Rationale: 32 bounds storage independently of gallery size and request rate.
 */
const MEDIA_RENAMER_CANDIDATE_PLAN_MAX_ENTRIES = 32;

/**
 * Bound housekeeping performed when admitting a new plan.
 * Type: float. Units: seconds. Scope: one creation request. Consumers: GC.
 * Rationale: 0.05 seconds prevents cleanup from delaying the primary request.
 */
const MEDIA_RENAMER_CANDIDATE_PLAN_PRUNE_SECONDS = 0.05;

/**
 * Signal that a candidate proposal cannot safely continue.
 * Purpose: Give callers one transport-independent error for invalid, stale,
 * expired, corrupted, or unavailable plans.
 */
final class MediaRenamerCandidatePlanUnavailable extends RuntimeException
{
}

/**
 * Resolve the private candidate-plan root.
 * @return string Absolute project-cache directory protected by root web rules.
 */
function media_renamer_candidate_plan_root(): string
{
    return dirname(__DIR__, 3) . '/cache/media-renamer-candidates';
}

/**
 * Reject symlinked roots before creating or enumerating plan state.
 * @return string Validated root path.
 * @throws MediaRenamerCandidatePlanUnavailable When the root is unsafe/unavailable.
 */
function media_renamer_candidate_plan_ensure_root(): string
{
    $root = media_renamer_candidate_plan_root();
    if (is_link(dirname($root)) || is_link($root)
        || (!is_dir($root) && !@mkdir($root, 0700, true)) || is_link($root)) {
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is unavailable.');
    }
    return $root;
}

/**
 * Read complete bounded candidate-plan JSON without following a named link.
 * @param string $path Internal file path below the validated plan root.
 * @param int $limit Maximum encoded bytes accepted for this file.
 * @return string Complete JSON bytes.
 * @throws MediaRenamerCandidatePlanUnavailable When absent, linked, or oversized.
 */
function media_renamer_candidate_plan_bytes(string $path, int $limit): string
{
    try {
        return media_renamer_report_bytes($path, $limit);
    } catch (RuntimeException $error) {
        throw new MediaRenamerCandidatePlanUnavailable(
            'Candidate plan is unavailable.', 0, $error,
        );
    }
}

/**
 * Atomically replace one owned candidate-plan file.
 * @param string $path Exact manifest or chunk destination.
 * @param string $body Complete encoded JSON body.
 * @param int $limit Format-specific byte ceiling.
 * @return void Publishes only a complete bounded temporary file.
 * @throws MediaRenamerCandidatePlanUnavailable When publication is unsafe/fails.
 */
function media_renamer_candidate_plan_publish(string $path, string $body, int $limit): void
{
    try {
        media_renamer_report_publish($path, $body, $limit);
    } catch (RuntimeException $error) {
        $temporary = $path . '.tmp';
        if (!is_link($temporary) && is_file($temporary)) {
            @unlink($temporary);
        }
        throw new MediaRenamerCandidatePlanUnavailable(
            'Candidate plan could not be saved.', 0, $error,
        );
    }
}

/**
 * Load and validate the compact identity/cursor manifest.
 * @param string $token Exact lowercase 128-bit plan identifier.
 * @param string|null $owner Authenticated account/session SHA-256 binding.
 *     Null is reserved for internal garbage collection of validated records.
 * @param bool $allowExpired Allow expiry inspection for internal GC only.
 * @return array{version:int,owner:string,gallery_id:int,pattern_hash:string,revision:string,created_at:int,total:int,cursor:int,chunks:list<array{key:string,count:int}>,bytes:int} Validated plan metadata.
 * @throws MediaRenamerCandidatePlanUnavailable When identity or metadata is invalid.
 */
function media_renamer_candidate_plan_load(
    string $token, ?string $owner, bool $allowExpired = false,
): array {
    $root = media_renamer_candidate_plan_root();
    $directory = $root . '/' . $token;
    if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1
        || is_link(dirname($root)) || is_link($root) || is_link($directory)
        || !is_dir($directory)) {
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is unavailable.');
    }
    try {
        $record = json_decode(media_renamer_candidate_plan_bytes(
            $directory . '/manifest.json', MEDIA_RENAMER_CANDIDATE_PLAN_INDEX_BYTES,
        ), true, MEDIA_RENAMER_REPORT_JSON_DEPTH, JSON_THROW_ON_ERROR);
    } catch (\Throwable $error) {
        throw new MediaRenamerCandidatePlanUnavailable(
            'Candidate plan is unavailable.', 0, $error,
        );
    }
    if (!is_array($record) || ($record['version'] ?? null) !== 1
        || !is_string($record['owner'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $record['owner']) !== 1
        || ($owner !== null && !hash_equals($owner, $record['owner']))
        || !is_int($record['gallery_id'] ?? null) || $record['gallery_id'] <= 0
        || !is_string($record['pattern_hash'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $record['pattern_hash']) !== 1
        || !is_string($record['revision'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $record['revision']) !== 1
        || !is_int($record['created_at'] ?? null) || $record['created_at'] <= 0
        || $record['created_at'] > time()
        || (!$allowExpired && time() - $record['created_at'] >= MEDIA_RENAMER_CANDIDATE_PLAN_TTL)
        || !is_int($record['total'] ?? null) || $record['total'] < 0
        || $record['total'] > MEDIA_RENAMER_CANDIDATE_PLAN_MAX_CANDIDATES
        || !is_int($record['cursor'] ?? null) || $record['cursor'] < 0
        || $record['cursor'] > $record['total']
        || !is_array($record['chunks'] ?? null) || !array_is_list($record['chunks'])
        || !is_int($record['bytes'] ?? null) || $record['bytes'] < 0
        || $record['bytes'] > MEDIA_RENAMER_CANDIDATE_PLAN_TOTAL_BYTES) {
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is unavailable or expired.');
    }
    $sum = 0;
    $lastIndex = count($record['chunks']) - 1;
    foreach ($record['chunks'] as $index => $chunk) {
        $expectedCount = $index === $lastIndex
            ? $record['total'] - $index * MEDIA_RENAMER_CANDIDATE_PLAN_CHUNK_ROWS
            : MEDIA_RENAMER_CANDIDATE_PLAN_CHUNK_ROWS;
        if (!is_array($chunk) || !is_string($chunk['key'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $chunk['key']) !== 1
            || !is_int($chunk['count'] ?? null) || $chunk['count'] !== $expectedCount) {
            throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is unavailable.');
        }
        $sum += $chunk['count'];
    }
    if ($sum !== $record['total']) {
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is unavailable.');
    }
    return $record;
}

/**
 * Reclaim expired or completed snapshots while holding gallery writer ownership.
 * @return void Removes only files declared by the expired producer manifest.
 */
function media_renamer_candidate_plan_prune(): void
{
    $root = media_renamer_candidate_plan_root();
    if (is_link(dirname($root)) || is_link($root) || !is_dir($root)) {
        return;
    }
    $deadline = microtime(true) + MEDIA_RENAMER_CANDIDATE_PLAN_PRUNE_SECONDS;
    try {
        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $token = $entry->getFilename();
            if (preg_match('/\A[a-f0-9]{32}\z/D', $token) !== 1 || $entry->isLink()) {
                continue;
            }
            try {
                $record = media_renamer_candidate_plan_load($token, null, true);
            } catch (MediaRenamerCandidatePlanUnavailable) {
                continue;
            }
            if ($record['cursor'] < $record['total']
                && time() - $record['created_at'] < MEDIA_RENAMER_CANDIDATE_PLAN_TTL) {
                continue;
            }
            $directory = $root . '/' . $token;
            $lockPath = $directory . '/.lock';
            if (is_link($lockPath) || !is_file($lockPath)
                || ($lock = @fopen($lockPath, 'r+b')) === false) {
                continue;
            }
            $complete = false;
            try {
                if (!flock($lock, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $complete = true;
                $ownedNames = ['manifest.json.tmp' => true];
                foreach ($record['chunks'] as $chunkRef) {
                    $ownedNames[$chunkRef['key'] . '.json'] = true;
                    $ownedNames[$chunkRef['key'] . '.json.tmp'] = true;
                }
                foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
                    $name = $file->getFilename();
                    if ($name === '.lock' || $name === 'manifest.json') {
                        continue;
                    }
                    if (microtime(true) >= $deadline || !isset($ownedNames[$name])
                        || $file->isLink() || !$file->isFile() || !@unlink($file->getPathname())) {
                        $complete = false;
                        break;
                    }
                }
                // Keep ownership proof for the next bounded cleanup slice.
                $complete = $complete && !is_link($directory . '/manifest.json')
                    && @unlink($directory . '/manifest.json');
            } finally {
                fclose($lock);
            }
            if ($complete && !is_link($lockPath)) {
                @unlink($lockPath);
                @rmdir($directory);
            }
        }
    } catch (\Throwable) {
        // Housekeeping does not make another plan available or unreadable.
    }
}

/**
 * Create and atomically publish one bounded candidate snapshot.
 * @param string $owner SHA-256 binding to the authenticated account/session.
 * @param int $galleryId Exact positive gallery identity.
 * @param string $pattern Requested naming pattern, normalized by preview owner.
 * @param string $revision Opaque catalogue revision supplied under writer lease.
 * @return string Opaque lowercase 32-hex plan token.
 * @throws MediaRenamerCandidatePlanUnavailable When validation or bounded storage fails.
 */
function media_renamer_candidate_plan_create(
    string $owner, int $galleryId, string $pattern, string $revision,
): string {
    if (preg_match('/\A[a-f0-9]{64}\z/D', $owner) !== 1 || $galleryId <= 0
        || preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1) {
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is unavailable.');
    }
    $root = media_renamer_candidate_plan_ensure_root();
    media_renamer_candidate_plan_prune();
    $entries = 0;
    foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $entry->getFilename()) === 1
            && !$entry->isLink()) $entries++;
    }
    if ($entries >= MEDIA_RENAMER_CANDIDATE_PLAN_MAX_ENTRIES) {
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan capacity is exhausted.');
    }
    try {
        $preview = media_renamer_database_preview(
            $galleryId, $pattern, candidatesOnly: true, collectCandidates: true,
        );
        $ids = $preview['candidate_image_ids'];
        $sequences = $preview['candidate_sequences'];
    } catch (\Throwable $error) {
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is unavailable.', 0, $error);
    }
    if (count($ids) !== count($sequences)
        || count($ids) > MEDIA_RENAMER_CANDIDATE_PLAN_MAX_CANDIDATES) {
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is unavailable.');
    }
    $token = bin2hex(random_bytes(MEDIA_RENAMER_REPORT_TOKEN_BYTES));
    $directory = $root . '/' . $token;
    if (!@mkdir($directory, 0700)) {
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan could not be saved.');
    }
    $lockPath = $directory . '/.lock';
    $lock = is_link($lockPath) ? false : @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        if (is_resource($lock)) fclose($lock);
        if (!is_link($lockPath)) @unlink($lockPath);
        @rmdir($directory);
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is busy.');
    }
    $published = [];
    try {
        $chunks = [];
        $encodedChunks = [];
        $encodedTotal = 0;
        foreach (array_chunk($ids, MEDIA_RENAMER_CANDIDATE_PLAN_CHUNK_ROWS) as $index => $idChunk) {
            $sequenceChunk = array_slice($sequences,
                $index * MEDIA_RENAMER_CANDIDATE_PLAN_CHUNK_ROWS, count($idChunk));
            $pairs = [];
            foreach ($idChunk as $position => $id) {
                if (!is_int($id) || $id <= 0 || !is_int($sequenceChunk[$position] ?? null)
                    || $sequenceChunk[$position] <= 0) {
                    throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is invalid.');
                }
                $pairs[] = [$id, $sequenceChunk[$position]];
            }
            $body = json_encode($pairs, JSON_THROW_ON_ERROR);
            $encodedTotal += strlen($body);
            if ($encodedTotal > MEDIA_RENAMER_CANDIDATE_PLAN_TOTAL_BYTES) {
                throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is too large.');
            }
            $key = hash('sha256', $body);
            $encodedChunks[$key] = $body;
            $chunks[] = ['key' => $key, 'count' => count($pairs)];
        }
        $manifest = ['version' => 1, 'owner' => $owner, 'gallery_id' => $galleryId,
            'pattern_hash' => hash('sha256', $preview['pattern']), 'revision' => $revision,
            'created_at' => time(), 'total' => count($ids), 'cursor' => 0,
            'chunks' => $chunks, 'bytes' => $encodedTotal];
        // Retain ownership proof even if a process stops while writing the
        // chunks. No request receives the token until publication finishes.
        media_renamer_candidate_plan_publish($directory . '/manifest.json',
            json_encode($manifest, JSON_THROW_ON_ERROR),
            MEDIA_RENAMER_CANDIDATE_PLAN_INDEX_BYTES);
        $published[] = 'manifest.json';
        foreach ($encodedChunks as $key => $body) {
            media_renamer_candidate_plan_publish($directory . '/' . $key . '.json', $body,
                MEDIA_RENAMER_CANDIDATE_PLAN_CHUNK_BYTES);
            $published[] = $key . '.json';
        }
    } catch (\Throwable $error) {
        $cleaned = true;
        foreach (array_reverse($published) as $name) {
            if (is_link($directory . '/' . $name) || !@unlink($directory . '/' . $name)) {
                // Keep the manifest available to the bounded collector.
                $cleaned = false;
                break;
            }
        }
        fclose($lock);
        if ($cleaned && !is_link($lockPath)) {
            @unlink($lockPath);
            @rmdir($directory);
        }
        if ($error instanceof MediaRenamerCandidatePlanUnavailable) throw $error;
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan could not be saved.', 0, $error);
    }
    fclose($lock);
    return $token;
}

/**
 * Return one bounded candidate page without changing the confirmed cursor.
 * @param string $owner Authenticated account/session hash.
 * @param string $token Opaque candidate-plan identity.
 * @param int $galleryId Gallery identity expected by the caller.
 * @param string $pattern Normalized pattern expected by the caller.
 * @param string $revision Current opaque catalogue revision.
 * @param int $offset Requested candidate displacement, including batch interiors.
 * @param int $limit Maximum returned candidates, from 1 through the page cap.
 * @return array{candidate_image_ids:list<int>,candidate_sequences:list<int>,next_offset:?int,applied_offset:int} One page and next unread offset.
 * @throws MediaRenamerCandidatePlanUnavailable When ownership, binding, cursor, or page is invalid.
 */
function media_renamer_candidate_plan_page(
    string $owner, string $token, int $galleryId, string $pattern, string $revision,
    int $offset, int $limit = MEDIA_RENAMER_CANDIDATE_PAGE_SIZE,
): array {
    try {
        $record = media_renamer_candidate_plan_load($token, $owner);
        if ($record['gallery_id'] !== $galleryId
            || !hash_equals($record['pattern_hash'], hash('sha256', $pattern))
            || !hash_equals($record['revision'], $revision) || $offset < $record['cursor']
            || $offset > $record['total'] || $limit <= 0
            || $limit > MEDIA_RENAMER_CANDIDATE_PAGE_SIZE) {
            throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is stale.');
        }
        $ids = [];
        $sequences = [];
        $skip = $offset;
        foreach ($record['chunks'] as $chunkRef) {
            if ($skip >= $chunkRef['count']) { $skip -= $chunkRef['count']; continue; }
            $path = media_renamer_candidate_plan_root() . '/' . $token . '/'
                . $chunkRef['key'] . '.json';
            $body = media_renamer_candidate_plan_bytes($path,
                MEDIA_RENAMER_CANDIDATE_PLAN_CHUNK_BYTES);
            if (!hash_equals($chunkRef['key'], hash('sha256', $body))) {
                throw new MediaRenamerCandidatePlanUnavailable('Candidate plan integrity failed.');
            }
            $pairs = json_decode($body, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($pairs) || !array_is_list($pairs) || count($pairs) !== $chunkRef['count']) {
                throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is unavailable.');
            }
            $take = array_slice($pairs, $skip, $limit - count($ids));
            foreach ($take as $pair) {
                if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2
                    || !is_int($pair[0]) || $pair[0] <= 0
                    || !is_int($pair[1]) || $pair[1] <= 0) {
                    throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is unavailable.');
                }
                $ids[] = $pair[0]; $sequences[] = $pair[1];
            }
            $skip = 0;
            if (count($ids) >= $limit) break;
        }
        if (count($ids) !== min($limit, $record['total'] - $offset)
            || count($ids) !== count($sequences)) {
            throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is incomplete.');
        }
        $next = $offset + count($ids) < $record['total'] ? $offset + count($ids) : null;
        return ['candidate_image_ids' => $ids, 'candidate_sequences' => $sequences,
            'next_offset' => $next, 'applied_offset' => $record['cursor']];
    } catch (MediaRenamerCandidatePlanUnavailable $error) { throw $error; }
    catch (\Throwable $error) {
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is unavailable.', 0, $error);
    }
}

/**
 * Advance the durable cursor after the caller confirms physical batch success.
 * @param string $owner Authenticated account/session hash.
 * @param string $token Opaque candidate-plan identity.
 * @param int $galleryId Gallery identity expected by the caller.
 * @param string $pattern Normalized pattern expected by the caller.
 * @param string $previousRevision Revision currently stored in the manifest.
 * @param string $revision New revision after the confirmed physical batch.
 * @param int $offset Expected current applied cursor.
 * @param int $count Confirmed candidate count in this physical batch.
 * @return void Stores cursor and new revision atomically under the plan lock.
 * @throws MediaRenamerCandidatePlanUnavailable When expected state or bounds differ.
 */
function media_renamer_candidate_plan_advance(
    string $owner, string $token, int $galleryId, string $pattern,
    string $previousRevision, string $revision, int $offset, int $count,
): void {
    try {
        $directory = media_renamer_candidate_plan_root() . '/' . $token;
        $lockPath = $directory . '/.lock';
        if (is_link($lockPath) || ($lock = @fopen($lockPath, 'c')) === false) {
            throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is busy.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new MediaRenamerCandidatePlanUnavailable('Candidate plan is busy.');
            }
            $record = media_renamer_candidate_plan_load($token, $owner);
            if ($record['gallery_id'] !== $galleryId
                || !hash_equals($record['pattern_hash'], hash('sha256', $pattern))
                || !hash_equals($record['revision'], $previousRevision)
                || $record['cursor'] !== $offset || $count <= 0
                || $count > MEDIA_RENAMER_PHYSICAL_BATCH_SIZE
                || $offset + $count > $record['total']
                || preg_match('/\A[a-f0-9]{64}\z/D', $revision) !== 1) {
                throw new MediaRenamerCandidatePlanUnavailable('Candidate plan cursor is stale.');
            }
            $record['cursor'] = $offset + $count;
            $record['revision'] = $revision;
            media_renamer_candidate_plan_publish($directory . '/manifest.json',
                json_encode($record, JSON_THROW_ON_ERROR),
                MEDIA_RENAMER_CANDIDATE_PLAN_INDEX_BYTES);
        } finally { fclose($lock); }
    } catch (MediaRenamerCandidatePlanUnavailable $error) { throw $error; }
    catch (\Throwable $error) {
        throw new MediaRenamerCandidatePlanUnavailable('Candidate plan could not advance.', 0, $error);
    }
}
