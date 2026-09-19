<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/recovery/contracts.php
 * Module Type: Recovery Evidence Contracts
 *
 * Purpose:
 *   Validate coordinated recovery-set and operator-observation schemas.
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

require_once __DIR__ . '/io.php';

const COMPONENTS = ['database', 'originals', 'configuration', 'data', 'custom_assets', 'application'];
const CONTROLS = ['network_egress_blocked', 'email_disabled', 'jobs_disabled', 'integrations_disabled', 'uploads_isolated', 'database_isolated'];
const OPERATOR_CHECKS = ['database_import', 'gallery_relationships', 'private_gallery_denial', 'admin_login', 'trash_restore', 'migrations_current', 'configuration_rekeyed', 'custom_assets', 'public_routes', 'thumbnails', 'uploads'];
const COUNT_FIELDS = ['images', 'galleries', 'trash_entries'];

/** Create an unverified inventory whose missing evidence remains explicit. */
function set_template(): array
{
    $components = [];
    foreach (COMPONENTS as $name) {
        $components[$name] = ['state' => 'unknown', 'snapshot_id' => null, 'receipt_sha256' => null];
    }
    return [
        'schema' => 1,
        'set_id' => 'replace-with-opaque-set-id',
        'recovery_point' => gmdate('Y-m-d\TH:i:s\Z'),
        'backup' => ['method' => 'unknown', 'consistent' => false, 'off_host' => false, 'retention_days' => null, 'previous_restore_verified' => false],
        'targets' => ['rto_seconds' => null, 'rpo_seconds' => null],
        'components' => $components,
        'application' => ['version' => 'unknown', 'manifest_sha256' => null],
        'counts' => ['images' => 0, 'galleries' => 0, 'trash_entries' => 0],
        'originals' => [],
    ];
}

/** Validate recovery-set shape, receipt coordination and original catalog safety. */
function check_set(array $set): void
{
    keys($set, ['schema', 'set_id', 'recovery_point', 'backup', 'targets', 'components', 'application', 'counts', 'originals']);
    demand($set['schema'] === 1, 'unsupported_schema');
    demand(is_string($set['set_id']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $set['set_id']) === 1, 'invalid_set_id');
    timestamp($set['recovery_point']);
    keys($set['backup'], ['method', 'consistent', 'off_host', 'retention_days', 'previous_restore_verified']);
    demand(in_array($set['backup']['method'], ['unknown', 'provider_snapshot', 'maintenance_window'], true), 'invalid_snapshot_method');
    foreach (['consistent', 'off_host', 'previous_restore_verified'] as $key) {
        demand(is_bool($set['backup'][$key]), 'invalid_backup_value');
    }
    demand($set['backup']['retention_days'] === null || (nonnegative($set['backup']['retention_days']) && $set['backup']['retention_days'] > 0), 'invalid_retention');
    keys($set['targets'], ['rto_seconds', 'rpo_seconds']);
    foreach ($set['targets'] as $target) {
        demand($target === null || nonnegative($target), 'invalid_target');
    }
    keys($set['components'], COMPONENTS);
    foreach ($set['components'] as $name => $component) {
        keys($component, ['state', 'snapshot_id', 'receipt_sha256']);
        demand(in_array($component['state'], ['unknown', 'present', 'absent', 'not_used'], true), 'invalid_component_state');
        demand($component['state'] !== 'not_used' || $name === 'custom_assets', 'required_component');
        demand($component['snapshot_id'] === null || $component['snapshot_id'] === $set['set_id'], 'mixed_recovery_set');
        demand($component['receipt_sha256'] === null || digest($component['receipt_sha256']), 'invalid_receipt_digest');
    }
    keys($set['application'], ['version', 'manifest_sha256']);
    demand(is_string($set['application']['version']) && preg_match('/^[A-Za-z0-9][A-Za-z0-9.+_-]{0,63}$/D', $set['application']['version']) === 1, 'invalid_version');
    demand($set['application']['manifest_sha256'] === null || digest($set['application']['manifest_sha256']), 'invalid_manifest_digest');
    keys($set['counts'], COUNT_FIELDS);
    foreach ($set['counts'] as $count) {
        demand(nonnegative($count), 'invalid_count');
    }
    demand(is_array($set['originals']) && array_is_list($set['originals']) && count($set['originals']) <= 100000, 'invalid_catalog');
    demand(count($set['originals']) === $set['counts']['images'], 'catalog_count_mismatch');
    $seen = [];
    foreach ($set['originals'] as $original) {
        keys($original, ['path', 'sha256']);
        $path = relative_path($original['path']);
        demand(str_starts_with($path, 'galleries/') && preg_match('/\.(jpe?g|png|gif|webp|heic|heif|dng)$/iD', $path) === 1, 'invalid_original_path');
        demand(!isset($seen[strtolower($path)]), 'duplicate_original');
        $seen[strtolower($path)] = true;
        demand($original['sha256'] === null || digest($original['sha256']), 'invalid_original_digest');
    }
}

/** Create a fresh run identity with every isolation control unconfirmed. */
function isolation_template(array $set): array
{
    return ['schema' => 1, 'set_sha256' => fingerprint($set), 'run_id' => bin2hex(random_bytes(16)), 'controls' => array_fill_keys(CONTROLS, false)];
}

/** Require a marker bound to this set and affirmative operator isolation controls. */
function check_isolation(string $root, array $set): array
{
    $marker = read_json(contained_file($root, '.recovery-isolated.json'));
    keys($marker, ['schema', 'set_sha256', 'run_id', 'controls']);
    demand($marker['schema'] === 1 && $marker['set_sha256'] === fingerprint($set), 'isolation_set_mismatch');
    demand(is_string($marker['run_id']) && preg_match('/^[a-f0-9]{32}$/D', $marker['run_id']) === 1, 'invalid_run_id');
    keys($marker['controls'], CONTROLS);
    foreach ($marker['controls'] as $control) {
        demand($control === true, 'isolation_not_attested');
    }
    return $marker;
}

/** Create pending evidence for the current isolated run without implying success. */
function observations_template(array $set, array $marker): array
{
    return [
        'schema' => 1, 'set_sha256' => fingerprint($set), 'run_id' => $marker['run_id'],
        'started_at' => null, 'completed_at' => null,
        'counts' => array_fill_keys(COUNT_FIELDS, null),
        'orphan_images' => null, 'orphan_galleries' => null, 'pending_migrations' => null,
        'checks' => array_fill_keys(OPERATOR_CHECKS, 'pending'),
        'evidence_sha256' => null,
    ];
}

/** Validate observation identity, bounded results and chronological timestamps. */
function check_observations(array $observations, array $set, array $marker, int $now): void
{
    keys($observations, ['schema', 'set_sha256', 'run_id', 'started_at', 'completed_at', 'counts', 'orphan_images', 'orphan_galleries', 'pending_migrations', 'checks', 'evidence_sha256']);
    demand($observations['schema'] === 1 && $observations['set_sha256'] === fingerprint($set) && $observations['run_id'] === $marker['run_id'], 'observation_binding_mismatch');
    $started = $observations['started_at'] === null ? null : timestamp($observations['started_at']);
    $completed = $observations['completed_at'] === null ? null : timestamp($observations['completed_at']);
    demand($started === null || ($started >= timestamp($set['recovery_point']) && $started <= $now), 'invalid_restore_start');
    demand($completed === null || ($started !== null && $completed >= $started && $completed <= $now), 'invalid_restore_end');
    keys($observations['counts'], COUNT_FIELDS);
    foreach (array_merge(array_values($observations['counts']), [$observations['orphan_images'], $observations['orphan_galleries'], $observations['pending_migrations']]) as $count) {
        demand($count === null || nonnegative($count), 'invalid_observed_count');
    }
    keys($observations['checks'], OPERATOR_CHECKS);
    foreach ($observations['checks'] as $status) {
        demand(in_array($status, ['pass', 'fail', 'pending'], true), 'invalid_observed_status');
    }
    demand($observations['evidence_sha256'] === null || digest($observations['evidence_sha256']), 'invalid_evidence_digest');
}
