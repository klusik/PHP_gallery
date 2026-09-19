<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/release_qualification/audit.php
 * Module Type: Release Qualification Audit Evidence
 *
 * Purpose:
 *   Imports the central runner report without running or reconstructing test suites.
 *
 * Responsibilities:
 *   - Preserve automated PASS, failures and environment skips as distinct evidence
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

/** Validate the existing central report's public schema before trusting its summary. */
function validate_audit_report(array $report): void
{
    if (($report['schema_version'] ?? null) !== 1 || ($report['profile'] ?? null) !== 'release'
        || !in_array($report['status'] ?? null, ['PASS', 'FAIL', 'BLOCKED'], true)
        || !is_string($report['started_at'] ?? null) || strtotime($report['started_at']) === false
        || !is_numeric($report['duration_seconds'] ?? null) || $report['duration_seconds'] < 0
        || !is_array($report['tasks'] ?? null) || $report['tasks'] === []
        || !is_array($report['environment'] ?? null)) {
        throw new RuntimeException('A complete schema-1 central release audit JSON report is required.');
    }
    foreach (['source_fingerprint_before', 'source_fingerprint_after'] as $field) {
        if (!is_string($report[$field] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $report[$field])) {
            throw new RuntimeException('Central release audit must include valid before/after source fingerprints.');
        }
    }
    $ids = [];
    foreach ($report['tasks'] as $task) {
        if (!is_array($task) || !is_string($task['id'] ?? null) || isset($ids[$task['id']])
            || !is_string($task['label'] ?? null) || !is_string($task['summary'] ?? null)
            || !in_array($task['status'] ?? null, ['PASS', 'FAIL', 'SKIP', 'BLOCKED'], true)
            || !is_array($task['counts'] ?? null) || !is_array($task['details'] ?? null)) {
            throw new RuntimeException('Invalid or duplicate central audit task.');
        }
        foreach (['skipped', 'blocked', 'failed', 'errors'] as $counter) {
            $count = $task['counts'][$counter] ?? 0;
            if (!is_int($count) || $count < 0
                || ($task['status'] === 'PASS' && $counter !== 'skipped' && $count > 0)) {
                throw new RuntimeException('Inconsistent central audit task counters.');
            }
        }
        foreach (['gaps', 'problems'] as $field) {
            if (!is_array($task['details'][$field] ?? [])) {
                throw new RuntimeException('Invalid central audit task details.');
            }
            foreach ($task['details'][$field] ?? [] as $detail) {
                if (!is_string($detail)) {
                    throw new RuntimeException('Invalid central audit detail text.');
                }
            }
        }
        $ids[$task['id']] = true;
    }
    foreach ($report['environment'] as $item) {
        if (!is_array($item) || !is_string($item['component'] ?? null) || !is_string($item['value'] ?? null)
            || !in_array($item['status'] ?? null, ['PASS', 'FAIL', 'SKIP', 'BLOCKED'], true)) {
            throw new RuntimeException('Invalid central audit environment entry.');
        }
    }
    if (\PhpGallery\Audit\overall_status($report['tasks']) !== $report['status']) {
        throw new RuntimeException('Central audit summary disagrees with its task results.');
    }
}

/** Require both central runner snapshots to describe this exact qualified content. */
function assert_audit_fingerprint(array $report, array $snapshot): void
{
    foreach (['source_fingerprint_before', 'source_fingerprint_after'] as $field) {
        if (!hash_equals($snapshot['fingerprint'], $report[$field])) {
            throw new RuntimeException('Audit source fingerprint differs from current content or changed during the run.');
        }
    }
}

/** The copied report remains verifiable if latest.json is replaced by a later run. */
function validate_audit_evidence(mixed $evidence): void
{
    if (!is_array($evidence) || !is_array($evidence['report'] ?? null)
        || !is_string($evidence['report_sha256'] ?? null)
        || !is_string($evidence['captured_at'] ?? null)
        || !is_string($evidence['report_json'] ?? null)
        || hash('sha256', $evidence['report_json']) !== $evidence['report_sha256']
        || json_decode($evidence['report_json'], true, 512, JSON_THROW_ON_ERROR) !== $evidence['report']) {
        throw new RuntimeException('Invalid captured audit evidence.');
    }
    validate_audit_report($evidence['report']);
}

/** Surface skips within passing suites as well as whole-suite/environment gaps. */
function audit_gaps(array $report): array
{
    $gaps = [];
    foreach ($report['tasks'] as $task) {
        if (in_array($task['status'], ['SKIP', 'BLOCKED'], true)
            || ($task['counts']['skipped'] ?? 0) > 0 || ($task['counts']['blocked'] ?? 0) > 0
            || ($task['details']['gaps'] ?? []) !== []) {
            $gaps[] = $task['label'] . ': ' . $task['status'] . ' - ' . $task['summary'];
            foreach ($task['details']['gaps'] ?? [] as $gap) {
                $gaps[] = $gap;
            }
        }
    }
    foreach ($report['environment'] as $item) {
        if ($item['status'] !== 'PASS') {
            $gaps[] = $item['component'] . ': ' . $item['status'] . ' - ' . $item['value'];
        }
    }
    return array_values(array_unique($gaps));
}

/**
 * Attach a completed release report only to the snapshot initialized before its run.
 * The maintainer keeps the tree frozen during auditing; this command never launches tests.
 */
function record_audit(string $root, array $snapshot, string $reportPath): array
{
    $json = read_text($reportPath);
    $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($report)) {
        throw new RuntimeException('Central audit report must be a JSON object.');
    }
    validate_audit_report($report);
    assert_audit_fingerprint($report, $snapshot);
    $registry = require $root . '/scripts/audit_registry.php';
    $expected = $registry['profiles']['release'] ?? [];
    $actual = array_column($report['tasks'], 'id');
    sort($expected, SORT_STRING);
    sort($actual, SORT_STRING);
    if ($expected === [] || $expected !== $actual) {
        throw new RuntimeException('Audit must contain every registered release suite exactly once.');
    }
    return update_record($root, $snapshot, static function (?array $record) use ($report, $json): array {
        if ($record === null) {
            throw new RuntimeException('Run init before the central release audit.');
        }
        $started = strtotime($report['started_at']);
        $completed = $started + (float) $report['duration_seconds'];
        if ($started < strtotime($record['created_at']) || $completed > microtime(true) + 1) {
            throw new RuntimeException('Audit predates this snapshot or has not completed. Initialize before running the release audit.');
        }
        $evidence = [
            'report_sha256' => hash('sha256', $json),
            'captured_at' => gmdate(DATE_ATOM),
            'report_json' => $json,
            'report' => $report,
        ];
        $record['automated'] = $evidence;
        $record['history'][] = ['kind' => 'automated'] + $evidence;
        return $record;
    });
}
