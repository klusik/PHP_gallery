<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Report recovery mismatches without loading restored credentials or application code.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/recovery/validation.php
 * Module Type: Recovery Evidence Validation
 *
 * Purpose:
 *   Compare isolated restored files with backup receipts and operator evidence.
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Never bootstrap a restored installation or read installation credentials.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Recovery;

use Throwable;

require_once __DIR__ . '/contracts.php';

/** Append one bounded result with an explicit evidence source. */
function add_check(array &$report, string $id, string $status, string $source): void
{
    $report['checks'][] = ['id' => $id, 'status' => $status, 'source' => $source];
}

/** Aggregate failures and gaps without treating inventory as recovery proof. */
function finish_report(array $report): array
{
    $statuses = array_column($report['checks'], 'status');
    $report['status'] = in_array('FAIL', $statuses, true) ? 'FAIL' : (in_array('INCOMPLETE', $statuses, true) ? 'INCOMPLETE' : 'PASS');
    $report['recovery_readiness'] = $report['status'] === 'PASS' && $report['coverage'] === 'isolated_files_and_operator_evidence'
        ? 'operator_attested' : 'not_proven';
    return $report;
}

/** Inventory is an assessment of provider/operator receipts, never a backup implementation. */
function inventory(array $set, int $now, ?int $restoreStart = null): array
{
    check_set($set);
    $point = timestamp($set['recovery_point']);
    $report = [
        'schema' => 1, 'command' => 'inventory', 'coverage' => 'inventory_only',
        'set_sha256' => fingerprint($set), 'recorded_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
        'backup_age_seconds' => max(0, $now - $point),
        'data_loss_window_seconds' => max(0, ($restoreStart ?? $now) - $point),
        'recovery_duration_seconds' => null,
        'previous_restore_verified' => $set['backup']['previous_restore_verified'],
        'original_count' => count($set['originals']),
        'sample_count' => count(array_filter($set['originals'], static fn (array $item): bool => $item['sha256'] !== null)),
        'checks' => [],
    ];
    add_check($report, 'recovery_point', $point <= $now ? 'PASS' : 'FAIL', 'clock');
    add_check($report, 'consistent_snapshot', $set['backup']['consistent'] && $set['backup']['method'] !== 'unknown' ? 'PASS' : 'INCOMPLETE', 'operator');
    add_check($report, 'off_host_copy', $set['backup']['off_host'] ? 'PASS' : 'INCOMPLETE', 'operator');
    add_check($report, 'retention', $set['backup']['retention_days'] !== null ? 'PASS' : 'INCOMPLETE', 'operator');
    foreach ($set['components'] as $name => $component) {
        $status = 'INCOMPLETE';
        if ($component['state'] === 'absent') {
            $status = 'FAIL';
        } elseif ($component['state'] === 'not_used' || ($component['state'] === 'present' && $component['snapshot_id'] === $set['set_id'] && $component['receipt_sha256'] !== null)) {
            $status = 'PASS';
        }
        add_check($report, 'component_' . $name, $status, 'operator_receipt');
    }
    add_check($report, 'exact_application', $set['application']['version'] !== 'unknown' && $set['application']['manifest_sha256'] !== null ? 'PASS' : 'INCOMPLETE', 'operator_receipt');
    add_check($report, 'original_hash_sample', $set['counts']['images'] === 0 || $report['sample_count'] > 0 ? 'PASS' : 'INCOMPLETE', 'operator_receipt');
    add_check($report, 'rto_agreed', $set['targets']['rto_seconds'] !== null ? 'PASS' : 'INCOMPLETE', 'operator');
    $rpo = $set['targets']['rpo_seconds'];
    add_check($report, 'rpo_met', $rpo === null ? 'INCOMPLETE' : ($report['data_loss_window_seconds'] <= $rpo ? 'PASS' : 'FAIL'), 'clock_and_operator_target');
    return finish_report($report);
}

/** Verify the restored manifest against the receipt before trusting any of its paths. */
function check_application(array &$report, array $set, string $root): void
{
    if ($set['application']['manifest_sha256'] === null) {
        add_check($report, 'application_files', 'INCOMPLETE', 'filesystem');
        return;
    }
    try {
        $manifestFile = contained_file($root, 'app/core-manifest.json');
        demand(hash_file('sha256', $manifestFile) === $set['application']['manifest_sha256'], 'manifest_mismatch');
        $manifest = read_json($manifestFile);
        demand(($manifest['version'] ?? null) === $set['application']['version'] && ($manifest['hash_mode'] ?? null) === 'normalized-text-sha256' && ($manifest['algorithm'] ?? null) === 'sha256', 'manifest_format');
        demand(is_array($manifest['files'] ?? null) && count($manifest['files']) > 0 && count($manifest['files']) <= 50000, 'manifest_files');
        demand(isset($manifest['files']['app/bootstrap.php']), 'manifest_bootstrap');
        $count = 0;
        foreach ($manifest['files'] as $relative => $expected) {
            relative_path($relative);
            demand(preg_match('#^(app/|database/|public/|scripts/|docs/|[^/]+$)#D', $relative) === 1, 'manifest_scope');
            demand(is_string($expected) && preg_match('/^sha256:[a-f0-9]{64}$/D', $expected) === 1, 'manifest_hash');
            $file = contained_file($root, $relative);
            // Preserve the canonical CRLF/BOM normalization used by the release owner.
            demand(\Gallery\Core\integrity_hash_file($file) === $expected, 'application_mismatch');
            $count++;
        }
        $report['application_files_checked'] = $count;
        add_check($report, 'application_files', 'PASS', 'filesystem');
    } catch (Throwable $error) {
        add_check($report, 'application_files', 'FAIL', 'filesystem');
    }
}

/** Verify catalog file presence and selected raw hashes, reporting only ordinals. */
function check_originals(array &$report, array $set, string $root): void
{
    $present = 0;
    $hashed = 0;
    $failed = [];
    foreach ($set['originals'] as $index => $original) {
        try {
            $file = contained_file($root, $original['path']);
            $present++;
            if ($original['sha256'] !== null) {
                demand(hash_file('sha256', $file) === $original['sha256'], 'original_mismatch');
                $hashed++;
            }
        } catch (Throwable $error) {
            // Ordinals locate records in the private catalog without disclosing filenames.
            if (count($failed) < 50) {
                $failed[] = $index + 1;
            }
        }
    }
    $report['originals_present'] = $present;
    $report['originals_hashed'] = $hashed;
    $report['failed_original_ordinals'] = $failed;
    add_check($report, 'original_files', $failed === [] ? 'PASS' : 'FAIL', 'filesystem');
}

/** Compare operator counts, checks and measured duration with the recovery set. */
function check_operator_evidence(array &$report, array $set, array $observations): void
{
    foreach ($observations['checks'] as $name => $status) {
        add_check($report, $name, ['pass' => 'PASS', 'fail' => 'FAIL', 'pending' => 'INCOMPLETE'][$status], 'operator');
    }
    foreach (COUNT_FIELDS as $name) {
        $value = $observations['counts'][$name];
        add_check($report, 'database_count_' . $name, $value === null ? 'INCOMPLETE' : ($value === $set['counts'][$name] ? 'PASS' : 'FAIL'), 'operator');
    }
    foreach (['orphan_images', 'orphan_galleries', 'pending_migrations'] as $name) {
        $value = $observations[$name];
        add_check($report, $name, $value === null ? 'INCOMPLETE' : ($value === 0 ? 'PASS' : 'FAIL'), 'operator');
    }
    add_check($report, 'operator_evidence_receipt', $observations['evidence_sha256'] === null ? 'INCOMPLETE' : 'PASS', 'operator_receipt');
    $duration = $observations['started_at'] !== null && $observations['completed_at'] !== null
        ? timestamp($observations['completed_at']) - timestamp($observations['started_at']) : null;
    $report['recovery_duration_seconds'] = $duration;
    add_check($report, 'rto_met', $duration === null || $set['targets']['rto_seconds'] === null ? 'INCOMPLETE' : ($duration <= $set['targets']['rto_seconds'] ? 'PASS' : 'FAIL'), 'operator_timing');
}

/** Read only an attested isolated installation. No PHP from the restored tree is executed. */
function validate(array $set, string $root, ?array $observations, int $now): array
{
    check_set($set);
    $root = external_directory($root);
    $marker = check_isolation($root, $set);
    $observations ??= observations_template($set, $marker);
    check_observations($observations, $set, $marker, $now);
    $started = $observations['started_at'] === null ? null : timestamp($observations['started_at']);
    $report = inventory($set, $now, $started);
    $report['command'] = 'validate';
    $report['coverage'] = 'isolated_files_and_operator_evidence';
    $report['run_id'] = $marker['run_id'];
    $report['observations_sha256'] = fingerprint($observations);
    $report['isolation'] = 'operator_attested_not_enforced';
    check_application($report, $set, $root);
    check_originals($report, $set, $root);
    check_operator_evidence($report, $set, $observations);
    return finish_report($report);
}
