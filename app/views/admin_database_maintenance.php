<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_database_maintenance.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders the explicit administrator database maintenance workflow.
 *
 * Responsibilities:
 *   - Keep full inspection off ordinary dashboard rendering
 *   - Display schema inventory, migration/code audit, and cleanup candidates
 *   - Separate logical cleanup, schema repair, ANALYZE TABLE, and OPTIMIZE TABLE
 *   - Show table-specific ownership, retention, duplicate, and protection rules
 *   - Require visible confirmations for destructive operations
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
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-07-25
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\url_for;
use function Gallery\Services\admin_dashboard_format_bytes;
use function Gallery\Services\t;

/**
 * Render the database maintenance area.
 *
 * @param ?array $report Latest explicit inspection report.
 * @param array<string, mixed> $cleanupState Latest resumable cleanup state.
 * @param array<string, mixed> $repairReadiness Dedicated repair migration state.
 */
function view_render_admin_database_maintenance_panel(?array $report, array $cleanupState, array $repairReadiness): void
{
    echo '<section class="panel admin-database-maintenance-intro">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.database_maintenance.kicker', 'Database maintenance')) . '</p><h2>' . e(t('admin.database_maintenance.title', 'Inspect before changing anything')) . '</h2></div><p class="muted">' . e(t('admin.database_maintenance.description', 'The full schema, migration, code-reference, cleanup, and storage audit runs only after this explicit request. Ordinary dashboard rendering remains fast.')) . '</p></div>';
    echo '<form method="post" action="' . e(url_for('admin_database_maintenance_inspect')) . '" class="inline-action-form">';
    echo csrf_field();
    echo '<button type="submit" class="button">' . e(t('admin.database_maintenance.inspect_button', 'Inspect database')) . '</button>';
    echo '</form></section>';

    if ($report === null || empty($report['ok'])) {
        echo '<section class="panel"><p class="muted">' . e(t('admin.database_maintenance.no_report', 'No database audit report is cached yet. Inspection is read-only and must be started manually.')) . '</p></section>';
        return;
    }

    $inventory = (array) ($report['inventory'] ?? []);
    $tables = (array) ($inventory['tables'] ?? []);
    $candidates = (array) ($report['cleanup_candidates'] ?? []);
    $legacyFindings = (array) ($report['legacy_schema_findings'] ?? []);
    $tableSpecificAudit = (array) ($report['table_specific_audit'] ?? []);
    $candidateCount = array_sum(array_map(static fn (array $candidate): int => (int) ($candidate['candidate_count'] ?? 0), $candidates));
    $totalBytes = array_sum(array_map(static fn (array $table): int => (int) ($table['total_bytes'] ?? 0), $tables));
    $reclaimableBytes = array_sum(array_map(static fn (array $table): int => (int) ($table['reclaimable_bytes_estimate'] ?? 0), $tables));

    echo '<section class="panel admin-database-maintenance-summary">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.database_maintenance.report_kicker', 'Latest report')) . '</p><h2>' . e(t('admin.database_maintenance.report_title', 'Database audit summary')) . '</h2></div><p class="muted">' . e(t('admin.database_maintenance.generated_at', 'Generated {time}; duration {seconds} seconds.', [
        'time' => (string) ($report['generated_at_utc'] ?? ''),
        'seconds' => number_format((float) ($report['duration_seconds'] ?? 0.0), 3),
    ])) . '</p></div>';
    echo '<div class="admin-storage-summary-grid">';
    view_render_admin_storage_summary_card(t('admin.database_maintenance.tables', 'Audited tables'), (string) count($tables), t('admin.database_maintenance.tables_hint', 'Every table dynamically discovered in the active schema.'));
    view_render_admin_storage_summary_card(t('admin.database_maintenance.total_size', 'Allocated database'), admin_dashboard_format_bytes($totalBytes), t('admin.database_maintenance.total_size_hint', 'Data plus indexes reported by information_schema.'));
    view_render_admin_storage_summary_card(t('admin.database_maintenance.candidates', 'Safe candidates'), number_format($candidateCount), t('admin.database_maintenance.candidates_hint', 'Rows matching explicit high-confidence cleanup rules.'));
    view_render_admin_storage_summary_card(t('admin.database_maintenance.data_free', 'Engine data_free'), admin_dashboard_format_bytes($reclaimableBytes), t('admin.database_maintenance.data_free_hint', 'Engine estimate only. Reclamation requires a separately confirmed physical operation.'));
    echo '</div>';
    echo '<p class="muted">' . e((string) ($report['physical_optimization_note'] ?? '')) . '</p>';
    echo '</section>';

    view_render_admin_database_thumbnail_distribution_panel((array) ($tableSpecificAudit['image_thumbnail_variants'] ?? []));
    view_render_admin_database_cleanup_panel($candidates, $cleanupState);
    view_render_admin_database_schema_repair_panel($legacyFindings, $repairReadiness);
    view_render_admin_database_physical_operations_panel($tables);
    view_render_admin_database_inventory_panel($tables);
}

/**
 * Render the thumbnail metadata size, format, and status distribution.
 *
 * @param array<string, mixed> $audit Thumbnail-specific audit.
 */
function view_render_admin_database_thumbnail_distribution_panel(array $audit): void
{
    echo '<section class="panel admin-database-thumbnail-distribution">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.database_maintenance.thumbnail_kicker', 'Thumbnail metadata')) . '</p><h2>' . e(t('admin.database_maintenance.thumbnail_title', 'Variant size, format, and status distribution')) . '</h2></div><p class="muted">' . e(t('admin.database_maintenance.thumbnail_hint', 'This table stores metadata only, never image bytes. Unsupported variants are reported for review and are not deleted automatically.')) . '</p></div>';
    if (empty($audit['available'])) {
        echo '<p class="muted">' . e((string) ($audit['reason'] ?? t('admin.database_maintenance.thumbnail_unavailable', 'Thumbnail distribution is unavailable.'))) . '</p></section>';
        return;
    }

    echo '<div class="admin-storage-facts">';
    echo '<span><strong>' . e(t('admin.database_maintenance.configured_sizes', 'Configured sizes')) . '</strong> ' . e(implode(', ', array_map('strval', (array) ($audit['configured_sizes'] ?? [])))) . '</span>';
    echo '<span><strong>' . e(t('admin.database_maintenance.supported_formats', 'Supported formats')) . '</strong> ' . e(implode(', ', (array) ($audit['supported_formats'] ?? []))) . '</span>';
    echo '<span><strong>' . e(t('admin.database_maintenance.variant_rows', 'Metadata rows')) . '</strong> ' . e(number_format((int) ($audit['total_rows'] ?? 0))) . '</span>';
    echo '<span><strong>' . e(t('admin.database_maintenance.unsupported_rows', 'Unsupported rows')) . '</strong> ' . e(number_format((int) ($audit['unsupported_row_count'] ?? 0))) . '</span>';
    echo '</div>';

    $distribution = (array) ($audit['distribution'] ?? []);
    if ($distribution !== []) {
        echo '<div class="table-wrap"><table><thead><tr><th>' . e(t('admin.database_maintenance.size', 'Size')) . '</th><th>' . e(t('admin.database_maintenance.format', 'Format')) . '</th><th>' . e(t('admin.database_maintenance.status', 'Status')) . '</th><th>' . e(t('admin.database_maintenance.rows', 'Rows')) . '</th><th>' . e(t('admin.database_maintenance.current_policy', 'Current policy')) . '</th></tr></thead><tbody>';
        foreach ($distribution as $row) {
            echo '<tr><td>' . e(number_format((int) ($row['size_px'] ?? 0))) . ' px</td><td>' . e(strtoupper((string) ($row['format'] ?? ''))) . '</td><td>' . e((string) ($row['status'] ?? '')) . '</td><td>' . e(number_format((int) ($row['row_count'] ?? 0))) . '</td><td>' . e(!empty($row['supported_by_current_policy']) ? t('admin.database_maintenance.supported', 'supported') : t('admin.database_maintenance.report_only', 'unsupported, report only')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
}

/**
 * Render cleanup candidates and bounded cleanup controls.
 *
 * @param array<int, array<string, mixed>> $candidates Candidate rows.
 * @param array<string, mixed> $cleanupState Cleanup state.
 */
function view_render_admin_database_cleanup_panel(array $candidates, array $cleanupState): void
{
    echo '<section class="panel admin-database-cleanup-panel">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.database_maintenance.cleanup_kicker', 'Logical cleanup')) . '</p><h2>' . e(t('admin.database_maintenance.cleanup_title', 'Clean safe database data')) . '</h2></div><p class="muted">' . e(t('admin.database_maintenance.cleanup_hint', 'Only high-confidence orphan, deterministic duplicate, and explicitly expired temporary rows are eligible. Content, accounts, logs, telemetry, unknown tables, and filesystem files are protected.')) . '</p></div>';

    $visibleCandidates = array_values(array_filter($candidates, static fn (array $candidate): bool => (int) ($candidate['candidate_count'] ?? 0) > 0 || (string) ($candidate['inspection_error'] ?? '') !== ''));
    if ($visibleCandidates === []) {
        echo '<p class="muted">' . e(t('admin.database_maintenance.no_cleanup_candidates', 'The latest inspection found no safe cleanup candidates.')) . '</p>';
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>' . e(t('admin.database_maintenance.table', 'Table')) . '</th><th>' . e(t('admin.database_maintenance.category', 'Category')) . '</th><th>' . e(t('admin.database_maintenance.reason', 'Reason')) . '</th><th>' . e(t('admin.database_maintenance.confidence', 'Confidence')) . '</th><th>' . e(t('admin.database_maintenance.rows', 'Rows')) . '</th></tr></thead><tbody>';
        foreach ($visibleCandidates as $candidate) {
            echo '<tr><td><code>' . e((string) ($candidate['table_name'] ?? '')) . '</code></td><td>' . e((string) ($candidate['category'] ?? '')) . '</td><td>' . e((string) (($candidate['inspection_error'] ?? '') !== '' ? $candidate['inspection_error'] : ($candidate['reason'] ?? ''))) . '</td><td>' . e((string) ($candidate['confidence'] ?? '')) . '</td><td>' . e(number_format((int) ($candidate['candidate_count'] ?? 0))) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    if ($cleanupState !== []) {
        $status = !empty($cleanupState['failed']) ? t('admin.database_maintenance.state_failed', 'failed') : (!empty($cleanupState['completed']) ? t('admin.database_maintenance.state_completed', 'completed') : t('admin.database_maintenance.state_resumable', 'resumable'));
        echo '<div class="admin-storage-facts">';
        echo '<span><strong>' . e(t('admin.database_maintenance.operation', 'Operation')) . '</strong> ' . e((string) ($cleanupState['operation_id'] ?? '')) . '</span>';
        echo '<span><strong>' . e(t('admin.database_maintenance.status', 'Status')) . '</strong> ' . e($status) . '</span>';
        echo '<span><strong>' . e(t('admin.database_maintenance.deleted_rows', 'Deleted rows')) . '</strong> ' . e(number_format((int) ($cleanupState['deleted_rows'] ?? 0))) . '</span>';
        echo '<span><strong>' . e(t('admin.database_maintenance.filesystem_deletions', 'Filesystem deletions')) . '</strong> ' . e(number_format((int) ($cleanupState['filesystem_deletions'] ?? 0))) . '</span>';
        echo '</div>';
        if ((string) ($cleanupState['error'] ?? '') !== '') {
            echo '<p class="notice error">' . e((string) $cleanupState['error']) . '</p>';
        }
        $processedRules = (array) ($cleanupState['processed_rules'] ?? []);
        if ($processedRules !== []) {
            echo '<div class="table-wrap"><table><thead><tr><th>' . e(t('admin.database_maintenance.rule', 'Rule')) . '</th><th>' . e(t('admin.database_maintenance.table', 'Table')) . '</th><th>' . e(t('admin.database_maintenance.before', 'Before')) . '</th><th>' . e(t('admin.database_maintenance.deleted', 'Deleted')) . '</th><th>' . e(t('admin.database_maintenance.remaining', 'Remaining')) . '</th><th>' . e(t('admin.database_maintenance.result', 'Result')) . '</th></tr></thead><tbody>';
            foreach ($processedRules as $processedRule) {
                $resultText = (string) ($processedRule['error'] ?? '');
                if ($resultText === '') {
                    $resultText = !empty($processedRule['dry_run']) ? t('admin.database_maintenance.dry_run_only', 'dry-run only') : t('admin.database_maintenance.committed', 'committed and audited');
                }
                echo '<tr><td><code>' . e((string) ($processedRule['key'] ?? '')) . '</code></td><td><code>' . e((string) ($processedRule['table_name'] ?? '')) . '</code></td><td>' . e(number_format((int) ($processedRule['candidate_count'] ?? 0))) . '</td><td>' . e(number_format((int) ($processedRule['deleted_count'] ?? 0))) . '</td><td>' . e(array_key_exists('remaining_count', $processedRule) ? number_format((int) $processedRule['remaining_count']) : 'n/a') . '</td><td>' . e($resultText) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
    }

    echo '<div class="admin-storage-chart-grid">';
    echo '<article class="admin-storage-chart-card"><h3>' . e(t('admin.database_maintenance.dry_run_title', 'Dry-run')) . '</h3><p class="muted">' . e(t('admin.database_maintenance.dry_run_hint', 'Recounts every supported rule and records what would be removed. No DELETE statement runs.')) . '</p>';
    echo '<form method="post" action="' . e(url_for('admin_database_maintenance_cleanup')) . '">';
    echo csrf_field();
    echo '<input type="hidden" name="dry_run" value="1"><input type="hidden" name="restart" value="1">';
    echo '<label>' . e(t('admin.database_maintenance.batch_size', 'Batch size')) . '<input type="number" name="batch_size" min="1" max="1000" value="250"></label>';
    echo '<button type="submit" class="button secondary">' . e(t('admin.database_maintenance.run_dry_run', 'Run cleanup dry-run')) . '</button></form></article>';

    echo '<article class="admin-storage-chart-card"><h3>' . e(t('admin.database_maintenance.live_cleanup_title', 'Confirmed cleanup batch')) . '</h3><p class="muted">' . e(t('admin.database_maintenance.live_cleanup_hint', 'Each request processes one bounded rule batch. Retry or continue safely until the persisted state reports completion.')) . '</p>';
    echo '<form method="post" action="' . e(url_for('admin_database_maintenance_cleanup')) . '">';
    echo csrf_field();
    echo '<label>' . e(t('admin.database_maintenance.batch_size', 'Batch size')) . '<input type="number" name="batch_size" min="1" max="1000" value="250"></label>';
    echo '<label>' . e(t('admin.database_maintenance.type_clean', 'Type CLEAN to confirm')) . '<input type="text" name="confirmation_text" autocomplete="off"></label>';
    echo '<label class="admin-database-checkbox-label"><input type="checkbox" name="restart" value="1"> ' . e(t('admin.database_maintenance.restart_operation', 'Start a new operation instead of continuing the current state')) . '</label>';
    echo '<button type="submit" class="button danger">' . e(t('admin.database_maintenance.clean_button', 'Clean safe database data')) . '</button></form></article>';
    echo '</div></section>';
}

/**
 * Render schema repair findings and confirmation control.
 *
 * @param array<int, array<string, mixed>> $findings Findings.
 * @param array<string, mixed> $readiness Migration readiness.
 */
function view_render_admin_database_schema_repair_panel(array $findings, array $readiness): void
{
    echo '<section class="panel admin-database-schema-repair-panel">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.database_maintenance.schema_kicker', 'Legacy schema')) . '</p><h2>' . e(t('admin.database_maintenance.schema_title', 'Repair legacy schema')) . '</h2></div><p class="muted">' . e(t('admin.database_maintenance.schema_hint', 'The dedicated migration inspects every object before alteration, preserves source geometry first, and tolerates legacy, partial, and already repaired schemas. DDL may auto-commit.')) . '</p></div>';

    if ($findings === []) {
        echo '<p class="muted">' . e(t('admin.database_maintenance.no_schema_findings', 'The inspected schema contains no known legacy thumbnail metadata objects.')) . '</p>';
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>' . e(t('admin.database_maintenance.object', 'Object')) . '</th><th>' . e(t('admin.database_maintenance.status', 'Status')) . '</th><th>' . e(t('admin.database_maintenance.confidence', 'Confidence')) . '</th><th>' . e(t('admin.database_maintenance.reason', 'Reason')) . '</th></tr></thead><tbody>';
        foreach ($findings as $finding) {
            echo '<tr><td><code>' . e((string) ($finding['table_name'] ?? '')) . '.' . e((string) ($finding['object_name'] ?? '')) . '</code><br><small>' . e((string) ($finding['object_type'] ?? '')) . '</small></td><td>' . e((string) ($finding['status'] ?? '')) . '</td><td>' . e((string) ($finding['confidence'] ?? '')) . '</td><td>' . e((string) ($finding['reason'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    echo '<form method="post" action="' . e(url_for('admin_database_maintenance_repair')) . '" class="inline-action-form">';
    echo csrf_field();
    echo '<input type="hidden" name="dry_run" value="1">';
    echo '<button type="submit" class="button secondary">' . e(t('admin.database_maintenance.repair_dry_run_button', 'Run repair dry-run')) . '</button></form>';

    $blocked = (array) ($readiness['blocked_by_other_migrations'] ?? []);
    if ($blocked !== []) {
        echo '<p class="notice warning">' . e(t('admin.database_maintenance.repair_blocked', 'Repair is blocked until normal pending migrations are applied: {versions}', ['versions' => implode(', ', $blocked)])) . '</p>';
    } elseif (!empty($readiness['already_applied'])) {
        echo '<p class="muted">' . e(t('admin.database_maintenance.repair_applied', 'The dedicated repair migration is already recorded. Reinspect after any database restore or manual schema change.')) . '</p>';
    } else {
        echo '<form method="post" action="' . e(url_for('admin_database_maintenance_repair')) . '" class="inline-action-form">';
        echo csrf_field();
        echo '<label>' . e(t('admin.database_maintenance.type_repair', 'Type REPAIR to confirm')) . '<input type="text" name="confirmation_text" autocomplete="off"></label>';
        echo '<button type="submit" class="button danger">' . e(t('admin.database_maintenance.repair_button', 'Repair legacy schema')) . '</button></form>';
    }
    echo '</section>';
}

/**
 * Render selected-table ANALYZE and OPTIMIZE controls.
 *
 * @param array<string, array<string, mixed>> $tables Inventory tables.
 */
function view_render_admin_database_physical_operations_panel(array $tables): void
{
    echo '<section class="panel admin-database-physical-panel">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.database_maintenance.physical_kicker', 'Physical maintenance')) . '</p><h2>' . e(t('admin.database_maintenance.physical_title', 'Statistics and table space')) . '</h2></div><p class="muted">' . e(t('admin.database_maintenance.physical_hint', 'ANALYZE refreshes optimizer metadata. OPTIMIZE may lock or rebuild tables and may be expensive on shared hosting. Neither action runs automatically.')) . '</p></div>';

    echo '<div class="admin-storage-chart-grid">';
    echo '<article class="admin-storage-chart-card"><h3>' . e(t('admin.database_maintenance.analyze_title', 'Refresh database statistics')) . '</h3>';
    view_render_admin_database_table_selection_form('admin_database_maintenance_analyze', $tables, false);
    echo '</article>';
    echo '<article class="admin-storage-chart-card"><h3>' . e(t('admin.database_maintenance.optimize_title', 'Reclaim table space')) . '</h3><p class="muted">' . e(t('admin.database_maintenance.optimize_warning', 'The database engine may rebuild and lock selected tables. The displayed data_free value is an estimate, not a guaranteed reduction.')) . '</p>';
    view_render_admin_database_table_selection_form('admin_database_maintenance_optimize', $tables, true);
    echo '</article></div></section>';
}

/**
 * Render a selected-table maintenance form.
 *
 * @param string $route Route name.
 * @param array<string, array<string, mixed>> $tables Inventory tables.
 * @param bool $requiresConfirmation Whether OPTIMIZE confirmation is required.
 */
function view_render_admin_database_table_selection_form(string $route, array $tables, bool $requiresConfirmation): void
{
    echo '<form method="post" action="' . e(url_for($route)) . '">';
    echo csrf_field();
    echo '<div class="admin-database-table-selection">';
    foreach ($tables as $tableName => $table) {
        echo '<label><input type="checkbox" name="tables[]" value="' . e((string) $tableName) . '"> <code>' . e((string) $tableName) . '</code> <small>' . e(admin_dashboard_format_bytes((int) ($table['total_bytes'] ?? 0))) . ' · data_free ' . e(admin_dashboard_format_bytes((int) ($table['reclaimable_bytes_estimate'] ?? 0))) . '</small></label>';
    }
    echo '</div>';
    if ($requiresConfirmation) {
        echo '<label>' . e(t('admin.database_maintenance.type_optimize', 'Type OPTIMIZE to confirm')) . '<input type="text" name="confirmation_text" autocomplete="off"></label>';
        echo '<div class="admin-database-operation-actions">';
        echo '<button type="submit" name="dry_run" value="1" class="button secondary">' . e(t('admin.database_maintenance.optimize_dry_run_button', 'Preview selected optimization')) . '</button>';
        echo '<button type="submit" class="button danger">' . e(t('admin.database_maintenance.optimize_button', 'Optimize selected tables')) . '</button>';
        echo '</div>';
    } else {
        echo '<button type="submit" class="button">' . e(t('admin.database_maintenance.analyze_button', 'Analyze selected tables')) . '</button>';
    }
    echo '</form>';
}

/**
 * Render the complete table-centered schema and policy inventory.
 *
 * @param array<string, array<string, mixed>> $tables Inventory tables.
 */
function view_render_admin_database_inventory_panel(array $tables): void
{
    echo '<section class="panel admin-database-inventory-panel">';
    echo '<div class="admin-panel-heading"><div><p class="admin-kicker">' . e(t('admin.database_maintenance.inventory_kicker', 'Inventory')) . '</p><h2>' . e(t('admin.database_maintenance.inventory_title', 'Every discovered table')) . '</h2></div><p class="muted">' . e(t('admin.database_maintenance.inventory_hint', 'Open a table to inspect columns, keys, ownership, retention, duplicate handling, and physical-maintenance policy. Unknown tables remain protected.')) . '</p></div>';

    foreach ($tables as $tableName => $table) {
        echo '<details class="admin-database-table-detail"><summary><code>' . e((string) $tableName) . '</code> <span>' . e((string) ($table['category'] ?? '')) . '</span><small>' . e(number_format((int) ($table['estimated_rows'] ?? 0))) . ' rows · ' . e(admin_dashboard_format_bytes((int) ($table['total_bytes'] ?? 0))) . '</small></summary>';
        echo '<div class="admin-storage-facts">';
        echo '<span><strong>' . e(t('admin.database_maintenance.engine', 'Engine')) . '</strong> ' . e((string) ($table['engine'] ?? '')) . '</span>';
        echo '<span><strong>' . e(t('admin.database_maintenance.charset', 'Charset')) . '</strong> ' . e((string) ($table['charset'] ?? '')) . '</span>';
        echo '<span><strong>' . e(t('admin.database_maintenance.collation', 'Collation')) . '</strong> ' . e((string) ($table['collation'] ?? '')) . '</span>';
        echo '<span><strong>' . e(t('admin.database_maintenance.auto_increment', 'Auto increment')) . '</strong> ' . e((string) ($table['auto_increment'] ?? '')) . '</span>';
        echo '<span><strong>' . e(t('admin.database_maintenance.data_size', 'Data')) . '</strong> ' . e(admin_dashboard_format_bytes((int) ($table['data_bytes'] ?? 0))) . '</span>';
        echo '<span><strong>' . e(t('admin.database_maintenance.index_size', 'Indexes')) . '</strong> ' . e(admin_dashboard_format_bytes((int) ($table['index_bytes'] ?? 0))) . '</span>';
        echo '<span><strong>' . e(t('admin.database_maintenance.data_free', 'Engine data_free')) . '</strong> ' . e(admin_dashboard_format_bytes((int) ($table['reclaimable_bytes_estimate'] ?? 0))) . '</span>';
        echo '<span><strong>' . e(t('admin.database_maintenance.created_at', 'Created')) . '</strong> ' . e((string) ($table['created_at'] ?? '')) . '</span>';
        echo '<span><strong>' . e(t('admin.database_maintenance.updated_at', 'Updated')) . '</strong> ' . e((string) ($table['updated_at'] ?? '')) . '</span>';
        echo '</div>';

        $policy = (array) ($table['policy'] ?? []);
        echo '<dl class="admin-database-policy-list">';
        view_render_admin_database_policy_item(t('admin.database_maintenance.owner', 'Owner/parent'), (string) ($policy['owner'] ?? ''));
        view_render_admin_database_policy_item(t('admin.database_maintenance.orphan_rule', 'Safe orphan rule'), (string) ($policy['orphan_rule'] ?? ''));
        view_render_admin_database_policy_item(t('admin.database_maintenance.retention_rule', 'Retention rule'), (string) ($policy['retention_rule'] ?? ''));
        view_render_admin_database_policy_item(t('admin.database_maintenance.duplicate_rule', 'Duplicate rule'), (string) ($policy['duplicate_rule'] ?? ''));
        view_render_admin_database_policy_item(t('admin.database_maintenance.protected_rule', 'Protected data'), (string) ($policy['protected_rule'] ?? ''));
        view_render_admin_database_policy_item(t('admin.database_maintenance.cleanup_mode', 'Cleanup mode'), (string) ($policy['cleanup_mode'] ?? 'disabled'));
        view_render_admin_database_policy_item(t('admin.database_maintenance.physical_optimization', 'Physical optimization'), (string) ($policy['physical_optimization'] ?? 'manual'));
        echo '</dl>';

        echo '<div class="table-wrap"><table><thead><tr><th>' . e(t('admin.database_maintenance.column', 'Column')) . '</th><th>' . e(t('admin.database_maintenance.type', 'Type')) . '</th><th>' . e(t('admin.database_maintenance.null_default', 'NULL/default')) . '</th><th>' . e(t('admin.database_maintenance.extra', 'Extra')) . '</th><th>' . e(t('admin.database_maintenance.migration_history', 'Migration history')) . '</th><th>' . e(t('admin.database_maintenance.code_references', 'Code / SQL refs')) . '</th></tr></thead><tbody>';
        foreach ((array) ($table['columns'] ?? []) as $column) {
            $default = array_key_exists('default', $column) && $column['default'] !== null ? (string) $column['default'] : 'NULL';
            $columnMigration = (array) ($column['migration_audit'] ?? []);
            $migrationText = 'added: ' . implode(', ', (array) ($columnMigration['added_versions'] ?? []));
            if ((array) ($columnMigration['dropped_versions'] ?? []) !== []) {
                $migrationText .= '; dropped: ' . implode(', ', (array) $columnMigration['dropped_versions']);
            }
            echo '<tr><td><code>' . e((string) ($column['name'] ?? '')) . '</code></td><td>' . e((string) ($column['column_type'] ?? $column['data_type'] ?? '')) . '</td><td>' . (!empty($column['nullable']) ? 'NULL' : 'NOT NULL') . ' / ' . e($default) . '</td><td>' . e((string) ($column['extra'] ?? '')) . '</td><td>' . e($migrationText) . '</td><td>' . e(number_format(count((array) ($column['code_reference_files'] ?? [])))) . ' / ' . e(number_format(count((array) ($column['production_sql_reference_files'] ?? [])))) . ' prod SQL</td></tr>';
        }
        echo '</tbody></table></div>';

        $historicalAbsent = (array) ($table['historical_columns_absent_from_current_schema'] ?? []);
        if ($historicalAbsent !== []) {
            echo '<details><summary>' . e(t('admin.database_maintenance.historical_absent_columns', 'Historical columns absent from the current schema')) . '</summary><ul>';
            foreach ($historicalAbsent as $columnName => $history) {
                echo '<li><code>' . e((string) $columnName) . '</code>: ' . e('added ' . implode(', ', (array) ($history['added_versions'] ?? [])) . '; dropped ' . implode(', ', (array) ($history['dropped_versions'] ?? [])) . '; current code references ' . count((array) ($history['current_code_reference_files'] ?? [])) . '; production SQL references ' . count((array) ($history['production_sql_reference_files'] ?? []))) . '</li>';
            }
            echo '</ul></details>';
        }

        echo '<p class="muted"><strong>' . e(t('admin.database_maintenance.primary_key', 'Primary key')) . ':</strong> ' . e(view_admin_database_index_columns((array) ($table['primary_key'] ?? []))) . '<br>';
        echo '<strong>' . e(t('admin.database_maintenance.unique_keys', 'Unique keys')) . ':</strong> ' . e(implode(', ', array_keys((array) ($table['unique_keys'] ?? [])))) . '<br>';
        echo '<strong>' . e(t('admin.database_maintenance.secondary_indexes', 'Secondary indexes')) . ':</strong> ' . e(implode(', ', array_keys((array) ($table['secondary_indexes'] ?? [])))) . '<br>';
        echo '<strong>' . e(t('admin.database_maintenance.foreign_keys', 'Foreign keys')) . ':</strong> ' . e(view_admin_database_foreign_key_summary((array) ($table['foreign_keys'] ?? []))) . '<br>';
        echo '<strong>' . e(t('admin.database_maintenance.text_blob_json', 'Text/BLOB/JSON columns')) . ':</strong> ' . e(implode(', ', (array) ($table['text_blob_json_columns'] ?? []))) . '<br>';
        echo '<strong>' . e(t('admin.database_maintenance.enum_set', 'ENUM/SET definitions')) . ':</strong> ' . e(view_admin_database_enum_set_summary((array) ($table['enum_set_columns'] ?? []))) . '<br>';
        echo '<strong>' . e(t('admin.database_maintenance.migrations', 'Migration versions')) . ':</strong> ' . e(implode(', ', (array) ($table['migration_audit']['versions'] ?? []))) . '<br>';
        echo '<strong>' . e(t('admin.database_maintenance.code_references', 'Code reference files')) . ':</strong> ' . e((string) count((array) ($table['code_reference_files'] ?? []))) . '; production SQL ' . e((string) count((array) ($table['production_sql_reference_files'] ?? []))) . '; test SQL ' . e((string) count((array) ($table['test_sql_reference_files'] ?? []))) . '</p>';
        echo '</details>';
    }
    echo '</section>';
}

/**
 * Render one table policy definition item.
 */
function view_render_admin_database_policy_item(string $term, string $description): void
{
    echo '<div><dt>' . e($term) . '</dt><dd>' . e($description) . '</dd></div>';
}

/**
 * Format foreign-key ownership and delete rules.
 *
 * @param array<string, array<string, mixed>> $foreignKeys Foreign keys.
 */
function view_admin_database_foreign_key_summary(array $foreignKeys): string
{
    $summaries = [];
    foreach ($foreignKeys as $name => $foreignKey) {
        $columns = [];
        foreach ((array) ($foreignKey['columns'] ?? []) as $column) {
            $columns[] = (string) ($column['column'] ?? '') . ' -> ' . (string) ($foreignKey['referenced_table'] ?? '') . '.' . (string) ($column['referenced_column'] ?? '');
        }
        $summaries[] = (string) $name . ' [' . implode(', ', $columns) . '; delete ' . (string) ($foreignKey['delete_rule'] ?? '') . ']';
    }
    return implode(', ', $summaries);
}

/**
 * Format ENUM and SET definitions.
 *
 * @param array<string, string> $definitions Definitions keyed by column.
 */
function view_admin_database_enum_set_summary(array $definitions): string
{
    $summaries = [];
    foreach ($definitions as $columnName => $definition) {
        $summaries[] = (string) $columnName . ': ' . (string) $definition;
    }
    return implode(', ', $summaries);
}

/**
 * Format primary-key/index column rows.
 *
 * @param array<int, array<string, mixed>> $columns Columns.
 */
function view_admin_database_index_columns(array $columns): string
{
    $names = [];
    foreach ($columns as $column) {
        $name = trim((string) ($column['name'] ?? $column['column'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return implode(', ', $names);
}
