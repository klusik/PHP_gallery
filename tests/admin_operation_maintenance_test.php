<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_operation_maintenance_test.php
 * Module Type: Regression Test
 * Purpose: Verify bounded pending inspection and explicit reconciliation without reexecution.
 * Responsibilities:
 *   - Exercise real maintenance/model logic with isolated rows, lock ownership and safe metadata.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: No live configuration, database, CLI apply or target files are accessed.
 */
declare(strict_types=1);

namespace Gallery\Core {
    require_once __DIR__ . '/support/admin_operation_fixture.php';
}
namespace Gallery\Services {
    /**
     * Deterministic fixture refusal.
     *
     * @param string $key Translation key.
     * @param string $fallback Safe explanation.
     * @return string Deterministic fixture refusal.
     */
    function t(string $key, string $fallback = ''): string { return $fallback; }
    /**
     * Fixture result reference.
     *
     * @param int $id Independently verified gallery identity.
     * @param bool $fresh Required reference lookup.
     * @return array<string,mixed>|null Fixture result reference.
     */
    function find_gallery(int $id, bool $fresh = false): ?array { return $id === 10 ? ['id' => 10, 'parent_id' => 0] : null; }
}
namespace {
    require_once __DIR__ . '/../app/services/admin_operation_keys.php';

    /**
     * Stop without revealing private values.
     *
     * @param bool $condition Required maintenance invariant.
     * @param string $message Bounded failure.
     * @return void Stop without revealing private values.
     */
    function maintenance_expect(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    /**
     * Verify fail-closed behavior.
     *
     * @param callable():array<string,mixed> $operation Operator action expected to throw instead of returning metadata or a completion response.
     * @param string $reason Closed refusal reason.
     * @return void Verify fail-closed behavior.
     */
    function maintenance_refused(callable $operation, string $reason): void
    {
        try { $operation(); } catch (\Gallery\Services\AdminOperationRefusal $exception) {
            maintenance_expect($exception->reason === $reason, 'Unexpected maintenance refusal category.');
            return;
        }
        throw new RuntimeException('Unsafe maintenance action was admitted.');
    }
    /**
     * Controlled schema observation, without production database queries.
     *
     * @return bool Controlled schema observation, without production database queries.
     */
    function maintenance_schema(): bool
    {
        if (($GLOBALS['maintenance_schema_state'] ?? 'available') === 'unknown') {
            throw new RuntimeException('private schema detail');
        }
        return true;
    }

    $GLOBALS['operation_fixture_db'] = $database = new \Gallery\Core\AdminOperationFixtureDatabase();
    \Gallery\Services\schema_inspection_set_query_executor_for_tests('maintenance_schema');
    for ($index = 1; $index <= 55; ++$index) {
        $key = str_pad(dechex($index), 64, '0', STR_PAD_LEFT);
        $database->rows['1:' . $key] = ['actor_id' => 1, 'key_hash' => $key, 'payload_hash' => str_repeat('a', 64),
            'operation_name' => 'gallery.create', 'owner_hash' => str_repeat('b', 64), 'state' => 'pending',
            'response_json' => 'private response marker', 'created_at' => '2000-01-01 00:00:00', 'updated_at' => '2000-01-01 00:00:00'];
    }
    $database->rows['1:' . str_pad('36', 64, '0', STR_PAD_LEFT)]['state'] = 'needs_reconciliation';
    $database->rows['1:' . str_pad('37', 64, '0', STR_PAD_LEFT)]['state'] = 'unrecognized';
    $unchanged = $database->rows;
    $first = \Gallery\Services\admin_operation_pending_report();
    maintenance_expect(count($first['items']) === 50 && $first['counts'] === ['pending' => 53, 'needs_reconciliation' => 1, 'unknown' => 1] && is_string($first['next_cursor']), 'Pending report lost counts or page bounds.');
    $second = \Gallery\Services\admin_operation_pending_report($first['next_cursor']);
    maintenance_expect(count($second['items']) === 5 && $second['next_cursor'] === null, 'Pending cursor skipped or repeated the final page.');
    $inspection = \Gallery\Services\admin_operation_inspect(1, $first['items'][0]['key_hash']);
    maintenance_expect(!isset($inspection['owner_hash'], $inspection['response_json']) && !str_contains(json_encode($first), 'private response marker'), 'Inspection returned private worker/response contents.');
    maintenance_expect($database->rows === $unchanged, 'Read-only inspection expired or mutated old claims.');
    maintenance_refused(/** Inspect retained operations with the captured cursor and schema state. @return array<string,mixed> Bounded report only if inspection is admitted. */ static fn () => \Gallery\Services\admin_operation_pending_report('invalid-cursor'), 'operation_payload_invalid');

    $response = ['ok' => true, 'message' => 'Verified original creation.',
        'mutation' => ['type' => 'gallery.create', 'entity_ids' => [10]],
        'panel' => ['keep_open' => true], 'contexts' => [], 'fallback' => ['redirect_url' => '/index.php?page=admin_edit_gallery&id=10'],
        'gallery_id' => 10, 'parent_gallery_id' => 0];
    $evidence = ['actor_id' => 1, 'key_hash' => $inspection['key_hash'], 'payload_hash' => $inspection['payload_hash'], 'response' => $response];
    $parsed = \Gallery\Services\admin_operation_reconciliation_document(json_encode($evidence, JSON_THROW_ON_ERROR));
    maintenance_expect($parsed === $evidence, 'Evidence parser changed semantic reconciliation input.');
    maintenance_refused(/** Parse the deliberately invalid captured operator evidence. @return array<string,mixed> Semantic evidence only if validation unexpectedly succeeds. */ static fn () => \Gallery\Services\admin_operation_reconciliation_document(json_encode($evidence + ['password' => 'private'], JSON_THROW_ON_ERROR)), 'operation_payload_invalid');
    maintenance_refused(/** Parse the deliberately invalid captured operator evidence. @return array<string,mixed> Semantic evidence only if validation unexpectedly succeeds. */ static fn () => \Gallery\Services\admin_operation_reconciliation_document(str_repeat('x', \Gallery\Core\ADMIN_OPERATION_INPUT_MAX_BYTES + 1)), 'operation_payload_invalid');
    $preview = \Gallery\Services\admin_operation_reconcile_result(1, $inspection['key_hash'], $inspection['payload_hash'], $response);
    maintenance_expect($preview === $response && $database->rows === $unchanged && $database->locks === [], 'Dry-run changed retained state or leaked a worker lock.');
    $lock = \Gallery\Models\admin_operation_model_lock(1, $inspection['key_hash']);
    $database->connection = 2;
    maintenance_refused(/** Attempt reconciliation with the captured worker, state and binding conditions. @return array<string,mixed> Original completion only if reconciliation is admitted. */ static fn () => \Gallery\Services\admin_operation_reconcile_result(1, $inspection['key_hash'], $inspection['payload_hash'], $response, true), 'operation_pending');
    $database->connection = 1;
    \Gallery\Models\admin_operation_model_unlock($lock);
    maintenance_refused(/** Attempt reconciliation with the captured worker, state and binding conditions. @return array<string,mixed> Original completion only if reconciliation is admitted. */ static fn () => \Gallery\Services\admin_operation_reconcile_result(1, $inspection['key_hash'], str_repeat('c', 64), $response, true), 'operation_payload_conflict');
    $applied = \Gallery\Services\admin_operation_reconcile_result(1, $inspection['key_hash'], $inspection['payload_hash'], $response, true);
    $retained = $database->rows['1:' . $inspection['key_hash']];
    maintenance_expect($applied === $response && $retained['state'] === 'completed' && json_decode($retained['response_json'], true) === $response && count($database->rows) === 55, 'Explicit apply failed to attach exactly one retained result.');
    maintenance_refused(/** Attempt reconciliation with the captured worker, state and binding conditions. @return array<string,mixed> Original completion only if reconciliation is admitted. */ static fn () => \Gallery\Services\admin_operation_reconcile_result(1, $inspection['key_hash'], $inspection['payload_hash'], $response, true), 'operation_payload_conflict');
    maintenance_expect(\Gallery\Services\admin_operation_inspect(1, $inspection['key_hash'])['state'] === 'completed', 'Applied completion cannot be verified independently.');
    $GLOBALS['maintenance_schema_state'] = 'unknown';
    \Gallery\Services\schema_inspection_set_query_executor_for_tests('maintenance_schema');
    maintenance_refused(/** Inspect retained operations with the captured cursor and schema state. @return array<string,mixed> Bounded report only if inspection is admitted. */ static fn () => \Gallery\Services\admin_operation_pending_report(), 'operation_storage_unavailable');
    maintenance_expect(count($database->rows) === 55 && $database->locks === [], 'Unknown schema changed tombstones or leaked ownership.');
    echo "PASS admin operation pending report, inspection, dry-run and explicit reconciliation (isolated SQL seam)\n";
}
