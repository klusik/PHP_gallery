<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Verify exact actor/key uniqueness observation and pre-claim fail-closed behavior.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_operation_primary_key_test.php
 * Module Type: Regression Test
 * Purpose: Reject malformed or unknown ledger indexes even when PRIMARY existence is available.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: Real service/model code uses isolated metadata/SQL seams, never a live database.
 */
declare(strict_types=1);

require_once __DIR__ . '/support/admin_operation_fixture.php';
require_once __DIR__ . '/../app/services/admin_operation_keys.php';

use Gallery\Core\AdminOperationFixtureDatabase;
use Gallery\Services\AdminOperationRefusal;
use function Gallery\Models\admin_operation_model_primary_state;
use function Gallery\Services\admin_operation_assert_storage;
use function Gallery\Services\admin_operation_begin;
use function Gallery\Services\admin_operation_release;
use function Gallery\Services\schema_inspection_set_query_executor_for_tests;

/**
 * Assert one metadata/admission invariant without exposing private fixture values.
 * @param bool $condition Required invariant.
 * @param string $message Safe failure explanation.
 * @return void Throw when the expected guarantee is absent.
 */
function operation_primary_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Supply independent generic named-object observations, not PRIMARY definitions.
 * @return bool Confirmed presence or absence; failures simulate unknown metadata.
 */
function operation_primary_generic_schema(): bool
{
    if ($GLOBALS['operation_primary_generic_state'] === 'unknown') {
        throw new RuntimeException('Private fixture metadata failure.');
    }
    return $GLOBALS['operation_primary_generic_state'] === 'available';
}

/**
 * Verify the existing safe storage refusal occurs before any mutation admission.
 * @param callable():(array<string,mixed>|null) $operation Preflight or claim attempt expected to throw; an admitted call would return null or a claim.
 * @param AdminOperationFixtureDatabase $database Isolated query/row observations.
 * @return void Fail if a refusal leaks native details or admits target execution.
 */
function operation_primary_refused(callable $operation, AdminOperationFixtureDatabase $database): void
{
    $admitted = false;
    try {
        $operation();
        $admitted = true;
    } catch (AdminOperationRefusal $exception) {
        operation_primary_expect($exception->reason === 'operation_storage_unavailable', 'Wrong refusal category for unverified uniqueness.');
        operation_primary_expect($exception->getPrevious() === null && !str_contains($exception->getMessage(), 'Private fixture'), 'Native inspection detail crossed the service boundary.');
    }
    operation_primary_expect(!$admitted, 'Unverified uniqueness admitted an operation.');
    operation_primary_expect($database->claimAttempts === 0 && $database->lockAttempts === 0
        && $database->rows === [] && $database->locks === [], 'Refused uniqueness reached lock or ledger mutation.');
}

$GLOBALS['operation_primary_generic_state'] = 'available';
schema_inspection_set_query_executor_for_tests('operation_primary_generic_schema');
$valid = (new AdminOperationFixtureDatabase())->primaryDefinition;
$key = str_repeat('c', 64);
$payloadHash = str_repeat('d', 64);

foreach (['integer', 'string'] as $numericFormat) {
    $GLOBALS['operation_fixture_db'] = $database = new AdminOperationFixtureDatabase();
    if ($numericFormat === 'string') {
        foreach ($database->primaryDefinition as &$definition) {
            $definition['SEQ_IN_INDEX'] = (string) $definition['SEQ_IN_INDEX'];
            $definition['NON_UNIQUE'] = (string) $definition['NON_UNIQUE'];
        }
        unset($definition);
    }
    operation_primary_expect(admin_operation_model_primary_state() === 'available', 'Exact full unique actor/key definition was rejected.');
    $claim = admin_operation_begin(7, $key, 'gallery.create', $payloadHash);
    operation_primary_expect($database->claimAttempts === 1 && $claim['state'] === 'pending', 'Valid uniqueness did not admit exactly one claim.');
    admin_operation_release($claim);
    operation_primary_expect($database->locks === [], 'Valid definition test leaked an operation lock.');
}

$cases = ['missing' => [], 'unrelated_primary' => [array_replace($valid[0], ['COLUMN_NAME' => 'id'])],
    'only_actor' => [$valid[0]],
    'reordered' => [array_replace($valid[0], ['COLUMN_NAME' => 'key_hash']), array_replace($valid[1], ['COLUMN_NAME' => 'actor_id'])],
    'extra_column' => [...$valid, ['COLUMN_NAME' => 'operation_name', 'SEQ_IN_INDEX' => 3, 'NON_UNIQUE' => 0, 'SUB_PART' => null]],
    'unrelated_two_columns' => [array_replace($valid[0], ['COLUMN_NAME' => 'id']), array_replace($valid[1], ['COLUMN_NAME' => 'owner_hash'])],
    'duplicate_sequence' => [$valid[0], array_replace($valid[1], ['SEQ_IN_INDEX' => 1])],
    'nonunique' => [$valid[0], array_replace($valid[1], ['NON_UNIQUE' => 1])],
    'prefixed_key' => [$valid[0], array_replace($valid[1], ['SUB_PART' => 32])],
    'prefixed_actor' => [array_replace($valid[0], ['SUB_PART' => 4]), $valid[1]],
    'zero_prefix_not_null' => [$valid[0], array_replace($valid[1], ['SUB_PART' => '0'])],
    'null_column' => [$valid[0], array_replace($valid[1], ['COLUMN_NAME' => null])],
    'coercible_nonunique' => [$valid[0], array_replace($valid[1], ['NON_UNIQUE' => '0unsafe'])],
    'coercible_sequence' => [$valid[0], array_replace($valid[1], ['SEQ_IN_INDEX' => '2unsafe'])]];
foreach ($cases as $name => $definition) {
    $GLOBALS['operation_fixture_db'] = $database = new AdminOperationFixtureDatabase();
    $database->primaryDefinition = $definition;
    operation_primary_expect(admin_operation_model_primary_state() === 'missing', 'Malformed PRIMARY was treated as verified: ' . $name);
    operation_primary_refused(/** Reinspect required ledger storage before any claim or lock. @return void Return only if the complete uniqueness guarantee is observed. */ static fn () => admin_operation_assert_storage(), $database);
    operation_primary_refused(/** Attempt claim/replay using the captured actor, key and fingerprint. @return array<string,mixed> Claim or original response only if admission unexpectedly succeeds. */ static fn () => admin_operation_begin(7, $key, 'gallery.create', $payloadHash), $database);
}

foreach (['query_failure', 'incomplete_metadata'] as $failure) {
    $GLOBALS['operation_fixture_db'] = $database = new AdminOperationFixtureDatabase();
    if ($failure === 'query_failure') {
        $database->fault = 'primary_unknown';
    } else {
        unset($database->primaryDefinition[1]['SUB_PART']);
    }
    operation_primary_expect(admin_operation_model_primary_state() === 'unknown', 'Uncertain definition was not reported as unknown.');
    operation_primary_refused(/** Reinspect required ledger storage before any claim or lock. @return void Return only if the complete uniqueness guarantee is observed. */ static fn () => admin_operation_assert_storage(), $database);
    operation_primary_refused(/** Attempt claim/replay using the captured actor, key and fingerprint. @return array<string,mixed> Claim or original response only if admission unexpectedly succeeds. */ static fn () => admin_operation_begin(7, $key, 'gallery.create', $payloadHash), $database);
}

foreach (['missing', 'unknown'] as $genericState) {
    $GLOBALS['operation_fixture_db'] = $database = new AdminOperationFixtureDatabase();
    $GLOBALS['operation_primary_generic_state'] = $genericState;
    schema_inspection_set_query_executor_for_tests('operation_primary_generic_schema');
    operation_primary_refused(/** Attempt claim/replay using the captured actor, key and fingerprint. @return array<string,mixed> Claim or original response only if admission unexpectedly succeeds. */ static fn () => admin_operation_begin(7, $key, 'gallery.create', $payloadHash), $database);
    operation_primary_expect($database->primaryReads === 0, 'Missing/unknown prerequisite performed unnecessary definition reads.');
}

// A cached successful existence observation must not hide a changed definition.
$GLOBALS['operation_primary_generic_state'] = 'available';
schema_inspection_set_query_executor_for_tests('operation_primary_generic_schema');
$GLOBALS['operation_fixture_db'] = $database = new AdminOperationFixtureDatabase();
admin_operation_assert_storage();
$database->primaryDefinition = $cases['unrelated_two_columns'];
operation_primary_refused(/** Attempt claim/replay using the captured actor, key and fingerprint. @return array<string,mixed> Claim or original response only if admission unexpectedly succeeds. */ static fn () => admin_operation_begin(7, $key, 'gallery.create', $payloadHash), $database);
operation_primary_expect($database->primaryReads === 2, 'PRIMARY definition was incorrectly reused from request-local existence cache.');

echo "PASS exact PRIMARY definition and pre-claim available/missing/unknown refusals.\n";
