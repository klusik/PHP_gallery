<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/release_qualification/record.php
 * Module Type: Release Qualification Evidence Store
 *
 * Purpose:
 *   Stores operator attestations in ignored, fingerprint-specific local records.
 *
 * Responsibilities:
 *   - Keep old evidence recoverable and separate pending, pass and fail states
 *
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace PhpGallery\ReleaseQualification;

use RuntimeException;
use function PhpGallery\Release\read_text;
use function PhpGallery\Release\write_text;

/** Resolve a cache descendant, refusing symlink escapes through existing parents. */
function cache_path(string $root, string $relative, bool $create = false): string
{
    $segments = explode('/', 'cache/release-qualification/' . $relative);
    foreach ($segments as $segment) {
        if ($segment === '.' || $segment === '..' || !preg_match('/^[a-zA-Z0-9._-]+$/D', $segment)) {
            throw new RuntimeException('Invalid qualification cache path.');
        }
    }
    $path = $root;
    foreach ($segments as $index => $segment) {
        $path .= '/' . $segment;
        if (is_link($path)) {
            throw new RuntimeException('Qualification cache paths must not be linked.');
        }
        assert_local_path($root, $path);
        if ($create && $index < count($segments) - 1 && !is_dir($path)
            && !mkdir($path, 0775) && !is_dir($path)) {
            throw new RuntimeException('Unable to create qualification cache directory.');
        }
    }
    return $path;
}

/** Keep one durable record per version and exact content fingerprint. */
function record_relative(array $snapshot): string
{
    return $snapshot['version'] . '/' . $snapshot['fingerprint'] . '/record.json';
}

/** Start with every human check pending; no machine output can approve it. */
function empty_record(array $snapshot): array
{
    $manual = [];
    foreach (MANUAL_CHECKS as $id => $definition) {
        $manual[$id] = ['status' => 'pending', 'reviewer' => '', 'evidence' => '', 'recorded_at' => null];
    }
    return [
        'schema_version' => SCHEMA_VERSION,
        'snapshot' => $snapshot,
        'created_at' => gmdate(DATE_ATOM),
        'manual' => $manual,
        'automated' => null,
        'history' => [],
    ];
}

/** Reject malformed local state instead of silently converting it into an approval. */
function validate_record(array $record, array $snapshot): void
{
    if (($record['schema_version'] ?? null) !== SCHEMA_VERSION
        || ($record['snapshot'] ?? null) !== $snapshot
        || !is_string($record['created_at'] ?? null)
        || strtotime($record['created_at']) === false
        || !is_array($record['manual'] ?? null)
        || array_keys($record['manual']) !== array_keys(MANUAL_CHECKS)
        || !is_array($record['history'] ?? null)
        || !array_key_exists('automated', $record)) {
        throw new RuntimeException('Invalid qualification record or content identity.');
    }
    foreach ($record['manual'] as $review) {
        if (!is_array($review) || !in_array($review['status'] ?? null, ['pending', 'pass', 'fail'], true)
            || !is_string($review['reviewer'] ?? null) || !is_string($review['evidence'] ?? null)
            || ($review['status'] !== 'pending' && (trim($review['reviewer']) === '' || trim($review['evidence']) === ''
                || !is_string($review['recorded_at'] ?? null) || strtotime($review['recorded_at']) === false))) {
            throw new RuntimeException('Invalid manual evidence in qualification record.');
        }
    }
    if ($record['automated'] !== null) {
        validate_audit_evidence($record['automated']);
        assert_audit_fingerprint($record['automated']['report'], $snapshot);
    }
}

/** Read current evidence only; previous fingerprints cannot satisfy current checks. */
function load_record(string $root, array $snapshot): ?array
{
    $path = cache_path($root, record_relative($snapshot));
    if (!is_file($path)) {
        return null;
    }
    $record = json_decode(read_text($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($record)) {
        throw new RuntimeException('Invalid qualification record JSON.');
    }
    validate_record($record, $snapshot);
    return $record;
}

/** Serialize local updates under a lock and atomically replace only the owned record. */
function update_record(string $root, array $snapshot, callable $update): array
{
    $path = cache_path($root, record_relative($snapshot), true);
    $lockPath = cache_path($root, dirname(record_relative($snapshot)) . '/record.lock', true);
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Unable to lock qualification record.');
    }
    try {
        $record = $update(load_record($root, $snapshot));
        validate_record($record, $snapshot);
        assert_fingerprint(snapshot($root, $snapshot['version']), $snapshot['fingerprint']);
        write_text($path, encode($record));
        return $record;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Initialize idempotently; changed bytes select a new all-pending record. */
function initialize(string $root, array $snapshot): array
{
    return update_record($root, $snapshot, static fn(?array $existing): array => $existing ?? empty_record($snapshot));
}

/** Record an explicit human result, preserving earlier entries in the local history. */
function record_manual(string $root, array $snapshot, string $id, string $status, string $reviewer, string $evidence): array
{
    if (!isset(MANUAL_CHECKS[$id]) || !in_array($status, ['pending', 'pass', 'fail'], true)) {
        throw new RuntimeException('Unknown manual check or status; use pending, pass or fail.');
    }
    $reviewer = trim($reviewer);
    $evidence = trim($evidence);
    if ($reviewer === '' || $evidence === '' || strlen($reviewer) > 160 || strlen($evidence) > 2000
        || preg_match('/[\x00-\x1f\x7f]/', $reviewer . $evidence)) {
        throw new RuntimeException('Supply a reviewer (1-160 bytes) and concise single-line evidence (1-2000 bytes).');
    }
    return update_record($root, $snapshot, static function (?array $record) use ($id, $status, $reviewer, $evidence): array {
        if ($record === null) {
            throw new RuntimeException('Run init for this fingerprint before recording evidence.');
        }
        $review = ['status' => $status, 'reviewer' => $reviewer, 'evidence' => $evidence, 'recorded_at' => gmdate(DATE_ATOM)];
        $record['manual'][$id] = $review;
        $record['history'][] = ['kind' => 'manual', 'check' => $id] + $review;
        return $record;
    });
}

/** Summarize current manual state and audited coverage without equating their statuses. */
function qualification_status(array $record, string $phase, bool $initialized = true): array
{
    $unresolved = [];
    $later = [];
    foreach (MANUAL_CHECKS as $id => $definition) {
        if ($record['manual'][$id]['status'] === 'pass') {
            continue;
        }
        if ($phase === 'pre-publication' && $definition['phase'] === 'post-publication') {
            $later[] = $id;
        } else {
            $unresolved[] = $id;
        }
    }
    $automated = $record['automated'];
    $gaps = $automated === null ? [] : audit_gaps($automated['report']);
    $complete = $initialized && $unresolved === [] && $automated !== null
        && $automated['report']['status'] === 'PASS' && $gaps === [];
    return [
        'status' => $complete ? 'COMPLETE' : 'INCOMPLETE',
        'phase' => $phase,
        'initialized' => $initialized,
        'version' => $record['snapshot']['version'],
        'fingerprint' => $record['snapshot']['fingerprint'],
        'automated' => $automated,
        'manual' => $record['manual'],
        'unresolved' => $unresolved,
        'later' => $later,
        'coverage_gaps' => $gaps,
    ];
}
