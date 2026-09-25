<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/simbrief_description_drafts.php
 * Module Type: Service
 *
 * Purpose:
 *   Keep private short-lived OFP drafts for pre-create SimBrief previews.
 *
 * Responsibilities:
 *   - Bind bounded drafts to an administrator and session
 *   - Validate expiry and attach a previewed OFP after gallery creation
 *
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Last Updated:
 *   2026-09-25
 */

declare(strict_types=1);

namespace Gallery\Services;

use DirectoryIterator;
use RuntimeException;

/**
 * Bound the lifetime of a private pre-create OFP reference.
 * Type: int.
 * Units: seconds.
 * Scope: one administrator's pending gallery creation.
 * Consumers: draft creation, validation, and pruning.
 * Rationale: short expiry limits retained personal flight data.
 */
const SIMBRIEF_CREATE_DRAFT_TTL = 1800;
/**
 * Bound the JSON document written to the private draft cache.
 * Type: int.
 * Units: bytes.
 * Scope: one private SimBrief draft file.
 * Consumers: draft serialization and reading.
 * Rationale: cap disk use and reject unexpectedly large OFP payloads.
 */
const SIMBRIEF_CREATE_DRAFT_MAX_BYTES = 8388608;

/**
 * Return the protected draft directory under the application's cache root.
 *
 * @return string Absolute private cache directory.
 */
function simbrief_description_draft_directory(): string
{
    return dirname(__DIR__, 2) . '/cache/simbrief-drafts';
}

/**
 * Persist one bounded OFP for a single authenticated administrator.
 *
 * @param int $userId Authenticated administrator ID.
 * @param array<string,mixed> $payload Fetched OFP data.
 * @param array<string,mixed> $identifier Validated SimBrief identity.
 * @param array<string,mixed> $details Extracted flight details.
 * @return string Opaque draft reference.
 */
function simbrief_description_draft_create(int $userId, array $payload, array $identifier, array $details): string
{
    if ($userId < 1) {
        throw new RuntimeException('Administrator identity is required for a SimBrief draft.');
    }
    $sessionId = session_id();
    if ($sessionId === '') {
        throw new RuntimeException('An active administrator session is required for a SimBrief draft.');
    }
    $directory = simbrief_description_draft_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('SimBrief draft storage is unavailable.');
    }
    $token = bin2hex(random_bytes(24));
    $document = json_encode([
        'user_id' => $userId,
        'session_hash' => hash('sha256', $sessionId),
        'expires_at' => time() + SIMBRIEF_CREATE_DRAFT_TTL,
        'payload' => $payload,
        'identifier' => $identifier,
        'details' => $details,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($document) || strlen($document) > SIMBRIEF_CREATE_DRAFT_MAX_BYTES) {
        throw new RuntimeException('The SimBrief OFP is too large to preview.');
    }
    $path = $directory . '/' . $token . '.json';
    if (file_put_contents($path, $document, LOCK_EX) === false) {
        throw new RuntimeException('SimBrief draft storage is unavailable.');
    }
    @chmod($path, 0600);
    simbrief_description_draft_prune($directory);
    return $token;
}

/**
 * Read a still-valid draft only for the administrator who created it.
 *
 * @param int $userId Authenticated administrator ID.
 * @param string $token Opaque reference returned by draft creation.
 * @return array<string,mixed> Validated private draft document.
 */
function simbrief_description_draft_read(int $userId, string $token): array
{
    if ($userId < 1 || !preg_match('/\A[a-f0-9]{48}\z/D', $token)) {
        throw new RuntimeException('This SimBrief draft is invalid. Import the flight again.');
    }
    $path = simbrief_description_draft_directory() . '/' . $token . '.json';
    if (!is_file($path) || filesize($path) > SIMBRIEF_CREATE_DRAFT_MAX_BYTES) {
        throw new RuntimeException('This SimBrief draft has expired. Import the flight again.');
    }
    $document = file_get_contents($path);
    $draft = is_string($document) ? json_decode($document, true) : null;
    if (!is_array($draft) || (int) ($draft['user_id'] ?? 0) !== $userId
        || session_id() === '' || !hash_equals((string) ($draft['session_hash'] ?? ''), hash('sha256', session_id()))
        || (int) ($draft['expires_at'] ?? 0) < time()
        || !is_array($draft['payload'] ?? null) || !is_array($draft['identifier'] ?? null) || !is_array($draft['details'] ?? null)) {
        throw new RuntimeException('This SimBrief draft is unavailable. Import the flight again.');
    }
    return $draft;
}

/**
 * Remove a small bounded set of expired draft files during new imports.
 *
 * @param string $directory Private draft directory.
 * @return void Prune expired matching files.
 */
function simbrief_description_draft_prune(string $directory): void
{
    $checked = 0;
    foreach (new DirectoryIterator($directory) as $entry) {
        if ($checked++ >= 32) {
            break;
        }
        if (!$entry->isFile() || !preg_match('/\A[a-f0-9]{48}\.json\z/D', $entry->getFilename())) {
            continue;
        }
        if ($entry->getMTime() < time() - SIMBRIEF_CREATE_DRAFT_TTL - 60) {
            @unlink($entry->getPathname());
        }
    }
}

/**
 * Attach a previously previewed OFP to the newly created gallery.
 *
 * @param array<string,mixed> $gallery Persisted gallery row.
 * @param array<string,mixed> $draft Validated private draft.
 * @return array{route:array<string,mixed>,ofp:array<string,mixed>} Attachment outcomes.
 */
function simbrief_description_draft_attach(array $gallery, array $draft): array
{
    $payload = (array) $draft['payload'];
    $details = (array) $draft['details'];
    $route = simbrief_description_save_route_map_from_ofp((int) $gallery['id'], $payload, $details);
    $ofp = simbrief_description_save_ofp_for_gallery($gallery, $payload, (array) $draft['identifier'], $details, $route);
    return ['route' => $route, 'ofp' => $ofp];
}
