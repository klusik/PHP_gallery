<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/database_maintenance_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies pure database maintenance inventory, policy, audit, and cleanup logic.
 *
 * Responsibilities:
 *   - Cover information_schema inventory normalization
 *   - Cover compact and legacy thumbnail schema detection
 *   - Cover orphan, duplicate, and explicit-expiry cleanup classifications
 *   - Confirm deterministic duplicate survivor selection
 *   - Confirm content, audit logs, telemetry, and unknown tables remain protected
 *   - Confirm cleanup rules have no filesystem side effects
 *   - Confirm migration and Admin authentication/CSRF integration contracts
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

use function Gallery\Services\database_maintenance_cleanup_rules;
use function Gallery\Services\database_maintenance_inventory_has;
use function Gallery\Services\database_maintenance_legacy_schema_findings;
use function Gallery\Services\database_maintenance_migration_audit;
use function Gallery\Services\database_maintenance_normalize_thumbnail_distribution;
use function Gallery\Services\database_maintenance_normalize_inventory;
use function Gallery\Services\database_maintenance_protected_tables;
use function Gallery\Services\database_maintenance_record_live_progress;
use function Gallery\Services\database_maintenance_selected_tables;
use function Gallery\Services\database_maintenance_sql_literals;
use function Gallery\Services\database_maintenance_table_operation_plan;

require_once __DIR__ . '/../app/migration_definitions.php';
require_once __DIR__ . '/../app/services/admin_database_usage.php';
require_once __DIR__ . '/../app/services/database_maintenance.php';

/**
 * Throw when a database-maintenance expectation fails.
 */
function assert_database_maintenance(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

$tableRows = [
    ['TABLE_NAME' => 'images', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'TABLE_ROWS' => 10, 'DATA_LENGTH' => 1000, 'INDEX_LENGTH' => 200, 'DATA_FREE' => 300, 'AUTO_INCREMENT' => 11],
    ['TABLE_NAME' => 'image_thumbnail_variants', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'TABLE_ROWS' => 20, 'DATA_LENGTH' => 4000, 'INDEX_LENGTH' => 1000, 'DATA_FREE' => 500, 'AUTO_INCREMENT' => 21],
    ['TABLE_NAME' => 'password_reset_tokens', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'TABLE_ROWS' => 3, 'DATA_LENGTH' => 100, 'INDEX_LENGTH' => 100, 'DATA_FREE' => 0],
    ['TABLE_NAME' => 'navigation_data_cache', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'TABLE_ROWS' => 5, 'DATA_LENGTH' => 200, 'INDEX_LENGTH' => 100, 'DATA_FREE' => 0],
    ['TABLE_NAME' => 'admin_logs', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'TABLE_ROWS' => 1000, 'DATA_LENGTH' => 9000, 'INDEX_LENGTH' => 3000, 'DATA_FREE' => 0],
    ['TABLE_NAME' => 'telemetry_events', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'TABLE_ROWS' => 2000, 'DATA_LENGTH' => 12000, 'INDEX_LENGTH' => 4000, 'DATA_FREE' => 0],
    ['TABLE_NAME' => 'mystery_plugin_rows', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'TABLE_ROWS' => 5000, 'DATA_LENGTH' => 20000, 'INDEX_LENGTH' => 1000, 'DATA_FREE' => 8000],
];

$columnRows = [];
$addColumn = static function (string $table, string $column, string $type, string $columnType, string $nullable = 'NO', mixed $default = null, string $key = '', string $extra = '') use (&$columnRows): void {
    $columnRows[] = [
        'TABLE_NAME' => $table,
        'COLUMN_NAME' => $column,
        'ORDINAL_POSITION' => count($columnRows) + 1,
        'COLUMN_DEFAULT' => $default,
        'IS_NULLABLE' => $nullable,
        'DATA_TYPE' => $type,
        'COLUMN_TYPE' => $columnType,
        'CHARACTER_SET_NAME' => str_contains($type, 'char') || str_contains($type, 'text') ? 'utf8mb4' : null,
        'COLLATION_NAME' => str_contains($type, 'char') || str_contains($type, 'text') ? 'utf8mb4_unicode_ci' : null,
        'COLUMN_KEY' => $key,
        'EXTRA' => $extra,
        'COLUMN_COMMENT' => '',
    ];
};

foreach ([
    ['images', 'id', 'bigint', 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'],
    ['images', 'gallery_id', 'bigint', 'bigint unsigned'],
    ['image_thumbnail_variants', 'id', 'bigint', 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'],
    ['image_thumbnail_variants', 'image_id', 'bigint', 'bigint unsigned'],
    ['image_thumbnail_variants', 'gallery_id', 'bigint', 'bigint unsigned'],
    ['image_thumbnail_variants', 'size_px', 'smallint', 'smallint unsigned'],
    ['image_thumbnail_variants', 'format', 'enum', "enum('jpg','webp')"],
    ['image_thumbnail_variants', 'thumbnail_rel_path', 'varchar', 'varchar(1024)'],
    ['image_thumbnail_variants', 'source_width', 'int', 'int unsigned', 'YES'],
    ['image_thumbnail_variants', 'source_height', 'int', 'int unsigned', 'YES'],
    ['password_reset_tokens', 'id', 'int', 'int', 'NO', null, 'PRI', 'auto_increment'],
    ['password_reset_tokens', 'user_id', 'int', 'int'],
    ['password_reset_tokens', 'expires_at', 'datetime', 'datetime'],
    ['password_reset_tokens', 'used_at', 'datetime', 'datetime', 'YES'],
    ['navigation_data_cache', 'id', 'bigint', 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'],
    ['navigation_data_cache', 'cache_key', 'char', 'char(64)'],
    ['navigation_data_cache', 'expires_at', 'datetime', 'datetime', 'YES'],
    ['admin_logs', 'id', 'bigint', 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'],
    ['telemetry_events', 'id', 'bigint', 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'],
    ['mystery_plugin_rows', 'id', 'bigint', 'bigint unsigned', 'NO', null, 'PRI', 'auto_increment'],
] as $definition) {
    $addColumn(...$definition);
}

$indexRows = [
    ['TABLE_NAME' => 'images', 'INDEX_NAME' => 'PRIMARY', 'NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'id', 'INDEX_TYPE' => 'BTREE'],
    ['TABLE_NAME' => 'image_thumbnail_variants', 'INDEX_NAME' => 'PRIMARY', 'NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'id', 'INDEX_TYPE' => 'BTREE'],
    ['TABLE_NAME' => 'image_thumbnail_variants', 'INDEX_NAME' => 'image_thumbnail_variants_unique', 'NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'image_id', 'INDEX_TYPE' => 'BTREE'],
    ['TABLE_NAME' => 'image_thumbnail_variants', 'INDEX_NAME' => 'image_thumbnail_variants_unique', 'NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => 2, 'COLUMN_NAME' => 'size_px', 'INDEX_TYPE' => 'BTREE'],
    ['TABLE_NAME' => 'image_thumbnail_variants', 'INDEX_NAME' => 'image_thumbnail_variants_unique', 'NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => 3, 'COLUMN_NAME' => 'format', 'INDEX_TYPE' => 'BTREE'],
    ['TABLE_NAME' => 'image_thumbnail_variants', 'INDEX_NAME' => 'image_thumbnail_variants_gallery_index', 'NON_UNIQUE' => 1, 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'gallery_id', 'INDEX_TYPE' => 'BTREE'],
];
$constraintRows = [
    ['TABLE_NAME' => 'image_thumbnail_variants', 'CONSTRAINT_NAME' => 'image_thumbnail_variants_image_id_foreign', 'COLUMN_NAME' => 'image_id', 'ORDINAL_POSITION' => 1, 'REFERENCED_TABLE_NAME' => 'images', 'REFERENCED_COLUMN_NAME' => 'id', 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'CASCADE'],
    ['TABLE_NAME' => 'image_thumbnail_variants', 'CONSTRAINT_NAME' => 'image_thumbnail_variants_gallery_id_foreign', 'COLUMN_NAME' => 'gallery_id', 'ORDINAL_POSITION' => 1, 'REFERENCED_TABLE_NAME' => 'galleries', 'REFERENCED_COLUMN_NAME' => 'id', 'UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'CASCADE'],
];

$inventory = database_maintenance_normalize_inventory('gallery_test', $tableRows, $columnRows, $indexRows, $constraintRows);
assert_database_maintenance((int) $inventory['table_count'] === 7, 'Every dynamically discovered table must be inventoried.');
assert_database_maintenance((string) $inventory['tables']['images']['charset'] === 'utf8mb4', 'Charset must be derived from collation.');
assert_database_maintenance((int) $inventory['tables']['image_thumbnail_variants']['total_bytes'] === 5000, 'Data and index bytes must be combined.');
assert_database_maintenance(isset($inventory['tables']['image_thumbnail_variants']['enum_set_columns']['format']), 'ENUM definitions must be retained.');
assert_database_maintenance(database_maintenance_inventory_has($inventory, 'image_thumbnail_variants', ['image_id', 'size_px', 'format']), 'Inventory table/column lookup must detect present objects.');
assert_database_maintenance(!database_maintenance_inventory_has($inventory, 'image_thumbnail_variants', ['derivative_version']), 'Legacy fixture must detect a missing compact column.');

$thumbnailDistribution = database_maintenance_normalize_thumbnail_distribution([
    ['size_px' => 300, 'format' => 'jpg', 'status' => 'valid', 'row_count' => 12],
    ['size_px' => 600, 'format' => 'webp', 'status' => 'stale', 'row_count' => 2],
    ['size_px' => 777, 'format' => 'avif', 'status' => 'valid', 'row_count' => 3],
], [300, 600], ['jpg', 'webp']);
assert_database_maintenance(!empty($thumbnailDistribution['metadata_only']) && empty($thumbnailDistribution['stores_image_bytes']), 'Thumbnail variants must be explicitly classified as metadata only.');
assert_database_maintenance((int) $thumbnailDistribution['total_rows'] === 17, 'Thumbnail distribution must account for every grouped row.');
assert_database_maintenance((int) $thumbnailDistribution['unsupported_row_count'] === 3, 'Unsupported size/format combinations must be reported.');
assert_database_maintenance((string) $thumbnailDistribution['unsupported_cleanup_mode'] === 'report_only', 'Unsupported variants must never become generic automatic cleanup candidates.');

$codeAudit = [
    'references' => [
        'image_thumbnail_variants' => [
            'columns' => [
                'gallery_id' => ['app/services/thumbnail_metadata.php', 'app/services/gallery_mutations.php'],
                'thumbnail_rel_path' => ['app/services/thumbnail_metadata.php'],
                'source_width' => ['app/services/thumbnail_metadata.php'],
                'source_height' => ['app/services/thumbnail_metadata.php'],
            ],
        ],
    ],
];
$findings = database_maintenance_legacy_schema_findings($inventory, $codeAudit);
$findingNames = array_column($findings, 'object_name');
assert_database_maintenance(in_array('gallery_id', $findingNames, true), 'Legacy gallery_id must be identified.');
assert_database_maintenance(in_array('image_thumbnail_variants_gallery_index', $findingNames, true), 'Legacy gallery index must be identified.');
$galleryFinding = $findings[array_search('gallery_id', $findingNames, true)];
assert_database_maintenance((string) $galleryFinding['status'] === 'obsolete_compatibility_only', 'Conditional compatibility references must not be mistaken for compact-schema requirements.');
assert_database_maintenance((string) $galleryFinding['confidence'] === 'high', 'Historical migration plus compatibility-only code must support high confidence.');

$rules = database_maintenance_cleanup_rules($inventory);
$ruleKeys = array_column($rules, 'key');
assert_database_maintenance(in_array('thumbnail_variants_missing_image', $ruleKeys, true), 'Thumbnail orphan classification must be present.');
assert_database_maintenance(in_array('expired_password_reset_tokens', $ruleKeys, true), 'Explicit password reset expiry classification must be present.');
$passwordExpiryRule = $rules[array_search('expired_password_reset_tokens', $ruleKeys, true)];
assert_database_maintenance(str_contains((string) $passwordExpiryRule['identifiers_sql'], 'ORDER BY `id` LIMIT :batch_size'), 'Predicate cleanup must select a deterministic bounded identifier batch.');
assert_database_maintenance(str_contains((string) $passwordExpiryRule['delete_sql'], 'ORDER BY `id` LIMIT :batch_size'), 'Predicate cleanup must delete the same deterministic bounded order.');
assert_database_maintenance(in_array('expired_navigation_cache', $ruleKeys, true), 'Explicit navigation cache expiry classification must be present.');
assert_database_maintenance(in_array('thumbnail_variant_duplicates', $ruleKeys, true), 'Deterministic thumbnail duplicate classification must be present.');
$duplicateRule = $rules[array_search('thumbnail_variant_duplicates', $ruleKeys, true)];
assert_database_maintenance(str_contains((string) $duplicateRule['delete_sql'], 'MIN(`id`) AS survivor_id'), 'Duplicate cleanup must keep the lowest id deterministically.');
assert_database_maintenance(!str_contains((string) $duplicateRule['delete_sql'], 'AS duplicate_id <>'), 'Duplicate deletion must alias only the selected identifier, not predicate expressions.');
assert_database_maintenance((array) $duplicateRule['identifier_columns'] === ['id'], 'Duplicate cleanup must expose the exact audited row identifier.');
assert_database_maintenance((string) $duplicateRule['survivor_rule'] === 'Keep the lowest id.', 'Duplicate survivor policy must be explicit.');
foreach ($rules as $rule) {
    assert_database_maintenance(empty($rule['filesystem_effects']), 'Database cleanup rules must never delete filesystem files.');
    assert_database_maintenance(!in_array((string) $rule['table_name'], ['admin_logs', 'telemetry_events', 'images'], true), 'Protected tables must not receive automatic cleanup rules.');
}

$protected = database_maintenance_protected_tables($inventory);
assert_database_maintenance(in_array('admin_logs', $protected, true), 'Admin logs must be protected from automatic deletion.');
assert_database_maintenance(in_array('telemetry_events', $protected, true), 'Telemetry must be protected from generic automatic deletion.');
assert_database_maintenance(in_array('mystery_plugin_rows', $protected, true), 'Unknown tables must be protected.');
$policyInventory = $inventory;
$policyInventory['tables']['database_maintenance_audit_log'] = ['policy' => Gallery\Services\database_maintenance_table_policies()['database_maintenance_audit_log']];
assert_database_maintenance(in_array('database_maintenance_audit_log', database_maintenance_protected_tables($policyInventory), true), 'Transactional cleanup audit rows must be protected from generic cleanup.');
assert_database_maintenance((string) $inventory['tables']['mystery_plugin_rows']['category'] === 'unknown/unclassified', 'Unknown table classification must be explicit.');

$sqlLiterals = database_maintenance_sql_literals(<<<'PHP'
<?php
// source_width in a comment is not SQL evidence.
$columnName = 'source_width';
$query = 'SELECT source_width FROM image_thumbnail_variants WHERE image_id = ?';
$message = 'image_thumbnail_variants source_width';
PHP);
assert_database_maintenance(count($sqlLiterals) === 1, 'SQL-reference extraction must ignore comments, identifiers, and non-SQL prose.');
assert_database_maintenance(str_contains($sqlLiterals[0], 'SELECT source_width FROM image_thumbnail_variants'), 'SQL-reference extraction must preserve same-statement table and column evidence.');

$selected = database_maintenance_selected_tables(['images', 'missing', 'images', 'admin_logs'], $inventory);
assert_database_maintenance($selected === ['images', 'admin_logs'], 'Selected physical-maintenance tables must be deduplicated and constrained to the current schema.');
$optimizePlan = database_maintenance_table_operation_plan('OPTIMIZE', ['images', 'missing', 'admin_logs'], $inventory);
assert_database_maintenance(!empty($optimizePlan['dry_run']) && empty($optimizePlan['operation_executed']), 'OPTIMIZE dry-run must validate selections without executing a table operation.');
assert_database_maintenance((int) $optimizePlan['table_count'] === 2, 'Physical-operation dry-run must include only selected current tables.');
assert_database_maintenance((int) $optimizePlan['allocated_bytes'] === 13200, 'Physical-operation dry-run must total allocated data and index bytes.');

$progressState = ['processed_rules' => [], 'candidate_rows' => 0, 'deleted_rows' => 0];
$progressRule = ['key' => 'example_rule', 'table_name' => 'example_rows', 'category' => 'orphaned_rows', 'reason' => 'Fixture reason.'];
$progressState = database_maintenance_record_live_progress($progressState, $progressRule, 600, 250, 350);
$progressState = database_maintenance_record_live_progress($progressState, $progressRule, 350, 250, 100);
assert_database_maintenance(count((array) $progressState['processed_rules']) === 1, 'Resumable state must aggregate batches by rule instead of growing without bound.');
assert_database_maintenance((int) $progressState['candidate_rows'] === 600, 'Initial candidates must not be double-counted across batches.');
assert_database_maintenance((int) $progressState['deleted_rows'] === 500, 'Deleted rows must accumulate across batches.');
assert_database_maintenance((int) $progressState['processed_rules'][0]['remaining_count'] === 100, 'Resumable progress must keep the latest remaining count.');
assert_database_maintenance((int) $progressState['processed_rules'][0]['batch_count'] === 2, 'Resumable progress must count committed batches.');

$compactColumnRows = array_values(array_filter($columnRows, static fn (array $row): bool => !(
    (string) $row['TABLE_NAME'] === 'image_thumbnail_variants'
    && in_array((string) $row['COLUMN_NAME'], ['gallery_id', 'thumbnail_rel_path', 'source_width', 'source_height'], true)
)));
$compactColumnRows[] = ['TABLE_NAME' => 'image_thumbnail_variants', 'COLUMN_NAME' => 'derivative_version', 'ORDINAL_POSITION' => 5, 'COLUMN_DEFAULT' => 1, 'IS_NULLABLE' => 'NO', 'DATA_TYPE' => 'int', 'COLUMN_TYPE' => 'int unsigned', 'CHARACTER_SET_NAME' => null, 'COLLATION_NAME' => null, 'COLUMN_KEY' => '', 'EXTRA' => '', 'COLUMN_COMMENT' => ''];
$compactIndexRows = array_values(array_filter($indexRows, static fn (array $row): bool => (string) $row['INDEX_NAME'] !== 'image_thumbnail_variants_gallery_index'));
$compactConstraintRows = array_values(array_filter($constraintRows, static fn (array $row): bool => (string) $row['CONSTRAINT_NAME'] !== 'image_thumbnail_variants_gallery_id_foreign'));
$compactInventory = database_maintenance_normalize_inventory('gallery_test', $tableRows, $compactColumnRows, $compactIndexRows, $compactConstraintRows);
assert_database_maintenance(database_maintenance_legacy_schema_findings($compactInventory, ['references' => []]) === [], 'Already compact schema must be idempotently clean.');

$migrationAudit = database_maintenance_migration_audit();
assert_database_maintenance((int) $migrationAudit['migration_count'] > 0, 'Migration history audit must discover migration files.');
assert_database_maintenance(in_array('202606130001_compact_thumbnail_variant_metadata', (array) ($migrationAudit['tables']['image_thumbnail_variants']['versions'] ?? []), true), 'Migration audit must identify the historical thumbnail compaction.');
assert_database_maintenance(in_array('202607250001_database_maintenance_schema_repair', (array) $migrationAudit['versions'], true), 'Migration audit must include the new repair migration.');
assert_database_maintenance(in_array('202607250001_database_maintenance_schema_repair', (array) ($migrationAudit['tables']['image_thumbnail_variants']['versions'] ?? []), true), 'Migration audit must associate the conditional repair callback with the thumbnail table.');
assert_database_maintenance(in_array('202607250001_database_maintenance_schema_repair', (array) ($migrationAudit['tables']['database_maintenance_audit_log']['added_columns']['removed_identifiers_json'] ?? []), true), 'Migration audit must include the transactional audit table created by the repair callback.');
assert_database_maintenance(in_array('202604270001_initial_schema', (array) ($migrationAudit['tables']['users']['added_columns']['id'] ?? []), true), 'Migration audit must determine columns added inside CREATE TABLE definitions.');
assert_database_maintenance(in_array('202606130001_compact_thumbnail_variant_metadata', (array) ($migrationAudit['tables']['image_thumbnail_variants']['dropped_columns']['gallery_id'] ?? []), true), 'Migration audit must determine historical dropped columns.');

$controllerSource = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_database_maintenance.php');
assert_database_maintenance(substr_count($controllerSource, 'require_admin();') >= 5, 'Every Admin database maintenance endpoint must require authentication.');
assert_database_maintenance(substr_count($controllerSource, 'verify_csrf();') >= 5, 'Every Admin database maintenance POST endpoint must verify CSRF.');
assert_database_maintenance(str_contains($controllerSource, "!== 'CLEAN'"), 'Logical cleanup must require an explicit confirmation phrase.');
assert_database_maintenance(str_contains($controllerSource, "!== 'OPTIMIZE'"), 'Physical optimization must require a separate confirmation phrase.');
assert_database_maintenance(substr_count($controllerSource, '$dryRun = !empty($_POST[\'dry_run\']);') >= 3, 'Logical cleanup, schema repair, and OPTIMIZE must each expose an explicit dry-run path.');
assert_database_maintenance(str_contains($controllerSource, 'database_maintenance_schema_repair_plan()'), 'Schema repair dry-run must build a non-mutating fresh plan.');
assert_database_maintenance(str_contains($controllerSource, 'database_maintenance_preview_optimize_tables($selectedTables)'), 'OPTIMIZE dry-run must not execute OPTIMIZE TABLE.');

$serviceSource = (string) file_get_contents(__DIR__ . '/../app/services/database_maintenance.php');
assert_database_maintenance(!str_contains($serviceSource, 'DELETE FROM admin_logs'), 'Generic database maintenance must not delete Admin logs.');
assert_database_maintenance(!str_contains($serviceSource, 'DELETE FROM telemetry_'), 'Generic database maintenance must not delete telemetry.');
assert_database_maintenance(str_contains($serviceSource, 'INSERT INTO database_maintenance_audit_log'), 'Every committed cleanup batch must write a transactional audit row.');
assert_database_maintenance(str_contains($serviceSource, '\':removed_identifiers_json\' => json_encode($identifiers'), 'Transactional audit rows must contain every removed row identifier.');
assert_database_maintenance(str_contains($serviceSource, 'identifier count did not match the deleted row count'), 'Identifier and delete counts must be verified before commit.');
assert_database_maintenance(str_contains($serviceSource, 'if (!in_array($operation, [\'ANALYZE\', \'OPTIMIZE\'], true))'), 'Physical table operations must remain confined to the explicit selected-operation function.');

echo "Database maintenance tests passed.\n";
