<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/schema_inspection_model_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies explicit three-state schema inspection without a live database.
 *
 * Responsibilities:
 *   - Cover available, missing, and unknown object results
 *   - Cover tables, columns, and indexes
 *   - Cover identifier validation and safe diagnostics
 *   - Cover request-local caching and explicit cache reset
 *   - Cover feature aggregation and unknown-state precedence
 *   - Cover production metadata query definitions and service registration
 *   - Remain executable with plain PHP in an isolated process
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
 *   - The test executor seam prevents any connection to a local database.
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/models/schema_inspection.php';
require_once __DIR__ . '/../app/services/schema_inspection.php';

use const Gallery\Services\SCHEMA_INSPECTION_AVAILABLE;
use const Gallery\Services\SCHEMA_INSPECTION_ERROR_FAILED;
use const Gallery\Services\SCHEMA_INSPECTION_MISSING;
use const Gallery\Services\SCHEMA_INSPECTION_OBJECT_COLUMN;
use const Gallery\Services\SCHEMA_INSPECTION_OBJECT_INDEX;
use const Gallery\Services\SCHEMA_INSPECTION_OBJECT_TABLE;
use const Gallery\Services\SCHEMA_INSPECTION_UNKNOWN;
use function Gallery\Services\schema_inspection_column;
use function Gallery\Services\schema_inspection_column_nullable;
use function Gallery\Services\schema_inspection_feature;
use function Gallery\Services\schema_inspection_index;
use function Gallery\Services\schema_inspection_is_available;
use function Gallery\Services\schema_inspection_is_missing;
use function Gallery\Services\schema_inspection_is_unknown;
use function Gallery\Services\schema_inspection_query_definition;
use function Gallery\Services\schema_inspection_reset_request_cache;
use function Gallery\Services\schema_inspection_set_query_executor_for_tests;
use function Gallery\Services\schema_inspection_table;

/**
 * Throw when one strict schema-inspection expectation fails.
 *
 * @template TExpected
 * @template TActual
 * @param TExpected $expected Value expected to match strictly, including its collection shape.
 * @param TActual $actual Value compared by strict equality, including its collection shape.
 * @param string $label Assertion label.
 * @return void Returns normally only when expected and actual match strictly.
 */
function schema_assert_same(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . ' expected ' . var_export($expected, true) . ', got '
                . var_export($actual, true),
        );
    }
}

/**
 * Throw when one schema-inspection condition is false.
 *
 * @param bool $condition Condition to verify.
 * @param string $label Assertion label.
 */
function schema_assert_true(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Assert that one callback rejects invalid input with InvalidArgumentException.
 *
 * @param callable $callback Callback expected to throw.
 * @param string $label Assertion label.
 */
function schema_assert_invalid(callable $callback, string $label): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }

    throw new RuntimeException($label . ' did not reject invalid input.');
}

$tableQuery = schema_inspection_query_definition(
    SCHEMA_INSPECTION_OBJECT_TABLE,
    'users',
    'users',
);
schema_assert_true(
    str_contains($tableQuery['sql'], 'information_schema.TABLES'),
    'table query metadata source',
);
schema_assert_true(
    str_contains($tableQuery['sql'], 'TABLE_SCHEMA = DATABASE()'),
    'table query active database scope',
);
schema_assert_same(
    ['users'],
    $tableQuery['parameters'],
    'table query parameters',
);

$columnQuery = schema_inspection_query_definition(
    SCHEMA_INSPECTION_OBJECT_COLUMN,
    'galleries',
    'nsfw_enabled',
);
schema_assert_true(
    str_contains($columnQuery['sql'], 'information_schema.COLUMNS'),
    'column query metadata source',
);
schema_assert_true(
    str_contains($columnQuery['sql'], 'COLUMN_NAME = ?'),
    'column query object predicate',
);
schema_assert_same(
    ['galleries', 'nsfw_enabled'],
    $columnQuery['parameters'],
    'column query parameters',
);

$indexQuery = schema_inspection_query_definition(
    SCHEMA_INSPECTION_OBJECT_INDEX,
    'images',
    'images_gallery_sort_index',
);
schema_assert_true(
    str_contains($indexQuery['sql'], 'information_schema.STATISTICS'),
    'index query metadata source',
);
schema_assert_true(
    str_contains($indexQuery['sql'], 'INDEX_NAME = ?'),
    'index query object predicate',
);
schema_assert_same(
    ['images', 'images_gallery_sort_index'],
    $indexQuery['parameters'],
    'index query parameters',
);
schema_assert_invalid(
    static fn(): array => schema_inspection_query_definition(
        'constraint',
        'images',
        'constraint_name',
    ),
    'unsupported query object type',
);

$servicesSource = file_get_contents(__DIR__ . '/../app/services.php');
schema_assert_true(
    is_string($servicesSource),
    'service registration source is readable',
);
$inspectionRegistration = strpos(
    $servicesSource,
    "require_once __DIR__ . '/services/schema_inspection.php';",
);
$firstAuditedConsumer = strpos(
    $servicesSource,
    "require_once __DIR__ . '/services/gallery_mutations.php';",
);
schema_assert_true(
    $inspectionRegistration !== false,
    'schema inspection service is registered',
);
schema_assert_true(
    $firstAuditedConsumer !== false
        && $inspectionRegistration < $firstAuditedConsumer,
    'schema inspection service loads before feature consumers',
);

$objects = [
    'table:users:users' => true,
    'column:galleries:nsfw_enabled' => true,
    'index:images:images_gallery_sort_index' => true,
];
$queryCount = 0;
schema_inspection_set_query_executor_for_tests(
    static function (string $objectType, string $table, string $object) use (
        &$objects,
        &$queryCount,
    ): bool {
        $queryCount++;
        return $objects[$objectType . ':' . $table . ':' . $object] ?? false;
    },
);

$availableTable = schema_inspection_table('users');
schema_assert_same(
    SCHEMA_INSPECTION_AVAILABLE,
    $availableTable['state'],
    'existing table state',
);
schema_assert_same(
    SCHEMA_INSPECTION_OBJECT_TABLE,
    $availableTable['object_type'],
    'table object type',
);
schema_assert_same('users', $availableTable['table'], 'table owner value');
schema_assert_same('users', $availableTable['object'], 'table object value');
schema_assert_same(
    '',
    $availableTable['error_code'],
    'available table error code',
);
schema_assert_true(
    schema_inspection_is_available($availableTable),
    'available predicate',
);
schema_assert_true(
    !schema_inspection_is_missing($availableTable),
    'available is not missing',
);
schema_assert_true(
    !schema_inspection_is_unknown($availableTable),
    'available is not unknown',
);

$missingTable = schema_inspection_table('missing_table');
schema_assert_same(
    SCHEMA_INSPECTION_MISSING,
    $missingTable['state'],
    'missing table state',
);
schema_assert_true(
    schema_inspection_is_missing($missingTable),
    'missing predicate',
);
schema_assert_true(
    !schema_inspection_is_available($missingTable),
    'missing is not available',
);
schema_assert_true(
    !schema_inspection_is_unknown($missingTable),
    'missing is not unknown',
);

$availableColumn = schema_inspection_column('galleries', 'nsfw_enabled');
schema_assert_same(
    SCHEMA_INSPECTION_AVAILABLE,
    $availableColumn['state'],
    'existing column state',
);
schema_assert_same(
    SCHEMA_INSPECTION_OBJECT_COLUMN,
    $availableColumn['object_type'],
    'column object type',
);

$missingColumn = schema_inspection_column('galleries', 'missing_column');
schema_assert_same(
    SCHEMA_INSPECTION_MISSING,
    $missingColumn['state'],
    'missing column state',
);

$availableIndex = schema_inspection_index(
    'images',
    'images_gallery_sort_index',
);
schema_assert_same(
    SCHEMA_INSPECTION_AVAILABLE,
    $availableIndex['state'],
    'existing index state',
);
schema_assert_same(
    SCHEMA_INSPECTION_OBJECT_INDEX,
    $availableIndex['object_type'],
    'index object type',
);

$missingIndex = schema_inspection_index('images', 'missing_index');
schema_assert_same(
    SCHEMA_INSPECTION_MISSING,
    $missingIndex['state'],
    'missing index state',
);
schema_assert_same(
    6,
    $queryCount,
    'object type, table, and object identities use independent cache keys',
);

$queriesBeforeCachedRead = $queryCount;
schema_inspection_table('users');
schema_inspection_table('missing_table');
schema_assert_same(
    $queriesBeforeCachedRead,
    $queryCount,
    'repeated inspection uses request cache',
);

schema_inspection_reset_request_cache();
schema_inspection_table('users');
schema_assert_same(
    $queriesBeforeCachedRead + 1,
    $queryCount,
    'cache reset forces fresh inspection',
);

$secret = 'password=NeverExposeThisFixtureSecret;token=PrivateToken;path=C:\\private\\config.php';
$unknownQueryCount = 0;
schema_inspection_set_query_executor_for_tests(
    static function () use ($secret, &$unknownQueryCount): bool {
        $unknownQueryCount++;
        throw new RuntimeException(
            'Connection failed with ' . $secret . ' at private-host.local',
        );
    },
);
$unknown = schema_inspection_column('galleries', 'nsfw_enabled');
schema_inspection_column('galleries', 'nsfw_enabled');
schema_assert_same(
    SCHEMA_INSPECTION_UNKNOWN,
    $unknown['state'],
    'unexpected failure state',
);
schema_assert_same(
    SCHEMA_INSPECTION_ERROR_FAILED,
    $unknown['error_code'],
    'unexpected failure category',
);
schema_assert_true(schema_inspection_is_unknown($unknown), 'unknown predicate');
schema_assert_true(
    !schema_inspection_is_available($unknown),
    'unknown is not available',
);
schema_assert_true(
    !schema_inspection_is_missing($unknown),
    'unknown is not missing',
);
schema_assert_true(
    !str_contains(var_export($unknown, true), $secret),
    'unknown result redacts fixture secret',
);
schema_assert_true(
    !str_contains(var_export($unknown, true), 'private-host.local'),
    'unknown result redacts fixture host',
);
schema_assert_same(
    1,
    $unknownQueryCount,
    'unknown inspection result is cached',
);

schema_inspection_set_query_executor_for_tests(static fn(): bool => true);
$availableAfterExecutorReset = schema_inspection_column(
    'galleries',
    'nsfw_enabled',
);
schema_assert_same(
    SCHEMA_INSPECTION_AVAILABLE,
    $availableAfterExecutorReset['state'],
    'executor replacement resets cached unknown state',
);

schema_inspection_set_query_executor_for_tests(
    static function (): bool {
        $exception = new PDOException(
            'Connection failed for mysql:host=secret-host;password=SecretValue',
        );
        $exception->errorInfo = ['HY000', 2002, 'connection refused'];
        throw $exception;
    },
);
$connectionUnknown = schema_inspection_table('users');
schema_assert_same(
    SCHEMA_INSPECTION_UNKNOWN,
    $connectionUnknown['state'],
    'connection failure state',
);
schema_assert_same(
    'HY000',
    $connectionUnknown['error_code'],
    'connection failure SQLSTATE',
);
schema_assert_true(
    !str_contains(var_export($connectionUnknown, true), 'SecretValue'),
    'connection details are redacted',
);

schema_inspection_set_query_executor_for_tests(
    static function (): bool {
        $exception = new PDOException(
            'Access denied with password=AnotherSecret',
        );
        $exception->errorInfo = ['42000', 1142, 'permission denied'];
        throw $exception;
    },
);
$permissionUnknown = schema_inspection_table('users');
schema_assert_same(
    SCHEMA_INSPECTION_UNKNOWN,
    $permissionUnknown['state'],
    'permission failure state',
);
schema_assert_same(
    '42000',
    $permissionUnknown['error_code'],
    'permission failure SQLSTATE',
);
schema_assert_true(
    !str_contains(var_export($permissionUnknown, true), 'AnotherSecret'),
    'PDO message is redacted',
);

$callsBeforeInvalid = 0;
schema_inspection_set_query_executor_for_tests(
    static function () use (&$callsBeforeInvalid): bool {
        $callsBeforeInvalid++;
        return true;
    },
);
schema_assert_invalid(static fn(): array => schema_inspection_table(
    '',
), 'empty table');
schema_assert_invalid(static fn(): array => schema_inspection_table(
    'users;DROP_TABLE',
), 'punctuated table');
schema_assert_invalid(static fn(): array => schema_inspection_column(
    'galleries',
    "bad\ncolumn",
), 'control-character column');
schema_assert_invalid(
    static fn(): array => schema_inspection_index('images', str_repeat(
        'a',
        65,
    )),
    'oversized index',
);
schema_assert_same(
    0,
    $callsBeforeInvalid,
    'invalid identifiers execute no query',
);

$nullableQueryCount = 0;
schema_inspection_set_query_executor_for_tests(
    static function (
        string $objectType,
        string $table,
        string $object,
        string $token = '',
    ) use (&$nullableQueryCount): bool {
        $nullableQueryCount++;
        if ($objectType === 'column') {
            return $object !== 'missing_nullable_column';
        }
        if ($objectType === 'column_nullable') {
            return $object === 'gps_map_enabled' && $token === 'YES';
        }
        return true;
    },
);
$nullableColumn = schema_inspection_column_nullable(
    'galleries',
    'gps_map_enabled',
);
schema_assert_same(
    SCHEMA_INSPECTION_AVAILABLE,
    $nullableColumn['state'],
    'nullable column state',
);
schema_assert_same(
    2,
    $nullableQueryCount,
    'nullable inspection verifies column existence and nullability once',
);
schema_inspection_column_nullable('galleries', 'gps_map_enabled');
schema_assert_same(
    2,
    $nullableQueryCount,
    'nullable inspection uses request cache',
);
$nonNullableColumn = schema_inspection_column_nullable(
    'galleries',
    'show_filenames',
);
schema_assert_same(
    SCHEMA_INSPECTION_MISSING,
    $nonNullableColumn['state'],
    'non-nullable column state',
);
schema_assert_same(
    4,
    $nullableQueryCount,
    'non-nullable inspection uses one existence and one nullability probe',
);
$missingNullableColumn = schema_inspection_column_nullable(
    'galleries',
    'missing_nullable_column',
);
schema_assert_same(
    SCHEMA_INSPECTION_MISSING,
    $missingNullableColumn['state'],
    'missing nullable column state',
);
schema_assert_same(
    5,
    $nullableQueryCount,
    'missing column does not issue a second nullability probe',
);

$nullableSecret = 'NullableSecretMustNotLeak';
schema_inspection_set_query_executor_for_tests(
    static function (
        string $objectType,
        string $table,
        string $object,
        string $token = '',
    ) use ($nullableSecret): bool {
        if ($objectType === 'column_nullable') {
            throw new RuntimeException('metadata failure password='
                . $nullableSecret);
        }
        return true;
    },
);
$unknownNullable = schema_inspection_column_nullable(
    'galleries',
    'gps_map_enabled',
);
schema_assert_same(
    SCHEMA_INSPECTION_UNKNOWN,
    $unknownNullable['state'],
    'nullable inspection failure state',
);
schema_assert_true(
    !str_contains(var_export($unknownNullable, true), $nullableSecret),
    'nullable inspection result redacts raw exception text',
);

schema_inspection_set_query_executor_for_tests(
    static fn(string $type, string $table, string $object): bool => (
        $object !== 'missing_requirement'
    ),
);
$featureAvailable = schema_inspection_feature('feature.available', [
    schema_inspection_table('users'),
    schema_inspection_column('galleries', 'nsfw_enabled'),
]);
schema_assert_same(
    SCHEMA_INSPECTION_AVAILABLE,
    $featureAvailable['state'],
    'available feature state',
);
schema_assert_same(
    2,
    count($featureAvailable['requirements']),
    'feature preserves requirements',
);

$featureMissing = schema_inspection_feature('feature.missing', [
    schema_inspection_table('users'),
    schema_inspection_column('galleries', 'missing_requirement'),
]);
schema_assert_same(
    SCHEMA_INSPECTION_MISSING,
    $featureMissing['state'],
    'missing feature state',
);

schema_inspection_set_query_executor_for_tests(
    static function (string $type, string $table, string $object): bool {
        if ($object === 'unknown_requirement') {
            throw new RuntimeException('temporary failure');
        }
        return $object !== 'missing_requirement';
    },
);
$featureUnknown = schema_inspection_feature('feature.unknown_precedence', [
    schema_inspection_column('galleries', 'missing_requirement'),
    schema_inspection_column('galleries', 'unknown_requirement'),
]);
schema_assert_same(
    SCHEMA_INSPECTION_UNKNOWN,
    $featureUnknown['state'],
    'unknown feature state takes precedence',
);
schema_assert_same(
    2,
    count($featureUnknown['requirements']),
    'unknown feature preserves all requirements',
);

schema_assert_invalid(static fn(): array => schema_inspection_feature('', [
    $availableTable,
]), 'empty feature key');
schema_assert_invalid(
    static fn(): array => schema_inspection_feature('feature.empty', []),
    'empty feature requirements',
);
schema_assert_invalid(
    static fn(): array => schema_inspection_feature('feature.invalid', [[
        'state' => 'other',
    ]]),
    'invalid feature requirement',
);
schema_assert_invalid(
    static fn(): array => schema_inspection_feature('feature.incomplete', [[
        'state' => SCHEMA_INSPECTION_AVAILABLE,
    ]]),
    'incomplete feature requirement',
);

// Legacy callers retain their prior auto-increment check; explicit plain
// storage refuses generated and every other unexpected EXTRA attribute.
schema_assert_true(
    \Gallery\Services\schema_inspection_extra_matches('', false, true),
    'plain storage accepts empty metadata',
);
foreach (['VIRTUAL GENERATED', 'STORED GENERATED', 'DEFAULT_GENERATED'] as $extra) {
    schema_assert_true(
        !\Gallery\Services\schema_inspection_extra_matches($extra, false, true)
            && \Gallery\Services\schema_inspection_extra_matches($extra, false, false),
        'plain storage rejects generated metadata without changing legacy checks',
    );
}
schema_assert_true(
    \Gallery\Services\schema_inspection_extra_matches('auto_increment', true, false)
        && !\Gallery\Services\schema_inspection_extra_matches('auto_increment', true, true),
    'plain storage never permits auto increment',
);

// Query provenance is explicitly opt-in and preserves all three result states.
$provenanceExecutorCalls = 0;
schema_inspection_set_query_executor_for_tests(
    static function (string $kind, string $table, string $object) use (&$provenanceExecutorCalls): bool {
        $provenanceExecutorCalls++;
        if ($object === 'unknown_column') {
            throw new RuntimeException('private driver detail must not be recorded');
        }
        return $object !== 'missing_column';
    },
);
unset($GLOBALS['cms_schema_query_provenance']);
schema_assert_same(
    SCHEMA_INSPECTION_AVAILABLE,
    schema_inspection_table('provenance_disabled')['state'],
    'disabled provenance preserves query result',
);
schema_assert_true(
    !isset($GLOBALS['cms_schema_query_provenance']),
    'ordinary queries must not allocate diagnostic observations',
);
$GLOBALS['cms_schema_query_provenance'] = [
    'scope' => 'fixture', 'entries' => [], 'dropped_attempts' => 0,
];
schema_assert_same(SCHEMA_INSPECTION_AVAILABLE, schema_inspection_table('provenance')['state'], 'observed table available');
schema_assert_same(SCHEMA_INSPECTION_MISSING, schema_inspection_column('provenance', 'missing_column')['state'], 'observed missing column');
schema_assert_same(SCHEMA_INSPECTION_UNKNOWN, schema_inspection_column('provenance', 'unknown_column')['state'], 'observed failed column unknown');
$observed = $GLOBALS['cms_schema_query_provenance']['entries'];
schema_assert_same(3, count($observed), 'only actual query attempts produce entries');
schema_assert_same('', $observed['table:provenance:']['object'], 'table identity omits unused object field');
schema_assert_same(1, $observed['column:provenance:missing_column']['succeeded'], 'missing is successful metadata read');
schema_assert_same(1, $observed['column:provenance:unknown_column']['failed'], 'thrown execution records failure');
schema_assert_true(
    $observed['column:provenance:unknown_column']['elapsed_ms_sum'] >= 0.0,
    'failed execution retains bounded nonnegative timing',
);
$beforeCached = $provenanceExecutorCalls;
schema_inspection_column('provenance', 'missing_column');
schema_assert_same($beforeCached, $provenanceExecutorCalls, 'cache hit remains query free');
schema_assert_same($observed, $GLOBALS['cms_schema_query_provenance']['entries'], 'cache hits do not enter query provenance');
schema_assert_invalid(
    static fn(): array => schema_inspection_column('private.invalid', 'column'),
    'invalid table remains rejected before inspection',
);
// The direct executor seam demonstrates recording revalidation cannot replace
// an execution result, even if a future caller bypasses the public validator.
schema_assert_same(
    true,
    \Gallery\Services\schema_inspection_execute_query('column', 'invalid.name', 'column'),
    'diagnostic validation cannot change successful execution',
);
schema_assert_same(3, count($GLOBALS['cms_schema_query_provenance']['entries']), 'invalid identifiers never enter provenance');
\Gallery\Services\schema_inspection_record_query_provenance('unsupported', 'provenance', 'column', true, 0.0);
schema_assert_same(3, count($GLOBALS['cms_schema_query_provenance']['entries']), 'unsupported object types never enter provenance');
$encodedProvenance = json_encode($GLOBALS['cms_schema_query_provenance']);
schema_assert_true(
    is_string($encodedProvenance)
        && !str_contains($encodedProvenance, 'private driver detail')
        && !str_contains($encodedProvenance, 'invalid.name'),
    'diagnostics retain neither exception text nor unvalidated names',
);
$GLOBALS['cms_schema_query_provenance'] = [
    'scope' => 'fixture', 'entries' => [], 'dropped_attempts' => 0,
];
$provenanceLimit = \Gallery\Services\SCHEMA_INSPECTION_QUERY_PROVENANCE_LIMIT;
// One identity beyond the production bound must increment drops only.
for ($identity = 0; $identity <= $provenanceLimit; $identity++) {
    \Gallery\Services\schema_inspection_execute_query('column', 'bounded', 'column_' . $identity);
}
schema_assert_same($provenanceLimit, count($GLOBALS['cms_schema_query_provenance']['entries']), 'distinct identity bound');
schema_assert_same(1, $GLOBALS['cms_schema_query_provenance']['dropped_attempts'], 'overflow attempt counted');
\Gallery\Services\schema_inspection_execute_query('column', 'bounded', 'column_0');
schema_assert_same(2, $GLOBALS['cms_schema_query_provenance']['entries']['column:bounded:column_0']['attempts'], 'existing identity still accumulates after bound');
schema_assert_same(1, $GLOBALS['cms_schema_query_provenance']['dropped_attempts'], 'retained identity never consumes a drop');
unset($GLOBALS['cms_schema_query_provenance']);

// Optional priming is availability-only: the executor seam refuses bulk work,
// then ordinary object inspection still supplies available/missing/unknown.
schema_inspection_set_query_executor_for_tests(
    static fn(string $kind, string $table, string $object): bool => $object !== 'absent',
);
schema_assert_same(false, \Gallery\Services\schema_inspection_prime_optional_table_snapshots(['optional_domain']), 'override declines optional bulk');
schema_assert_same(true, $GLOBALS['cms_schema_optional_priming_failed'] ?? false, 'bulk failure latches optional acceleration only');
schema_assert_same(SCHEMA_INSPECTION_AVAILABLE, schema_inspection_table('optional_domain')['state'], 'failed bulk retains normal available fallback');
schema_assert_same(SCHEMA_INSPECTION_MISSING, schema_inspection_column('optional_domain', 'absent')['state'], 'failed bulk retains normal missing fallback');
\Gallery\Services\schema_inspection_store_table_snapshots(['already_ready'], [
    ['TABLE_NAME' => 'already_ready', 'COLUMN_NAME' => 'id'],
]);
schema_assert_same(false, \Gallery\Services\schema_inspection_prime_optional_table_snapshots(['already_ready']), 'latched optional owner does not retry even a ready group');
schema_assert_same(true, \Gallery\Services\schema_inspection_prime_table_snapshots(['already_ready']), 'existing startup primer remains independent');
schema_inspection_reset_request_cache();
schema_assert_true(!isset($GLOBALS['cms_schema_optional_priming_failed']), 'schema reset clears failure latch');
\Gallery\Services\schema_inspection_store_table_snapshots(['ready_domain', 'missing_domain'], [
    ['TABLE_NAME' => 'ready_domain', 'COLUMN_NAME' => 'id'],
]);
schema_assert_same(true, \Gallery\Services\schema_inspection_prime_optional_table_snapshots(['ready_domain', 'missing_domain']), 'existing snapshots satisfy optional owner without bulk execution');
schema_assert_same(SCHEMA_INSPECTION_AVAILABLE, schema_inspection_column('ready_domain', 'id')['state'], 'successful snapshot remains available');
schema_assert_same(SCHEMA_INSPECTION_MISSING, schema_inspection_table('missing_domain')['state'], 'confirmed snapshot absence remains missing');
schema_inspection_set_query_executor_for_tests(
    static function (string $kind, string $table, string $object): bool {
        throw new RuntimeException('fixture unavailable metadata');
    },
);
schema_assert_true(!isset($GLOBALS['cms_schema_optional_priming_failed']), 'executor reset clears failure latch');
schema_assert_same(false, \Gallery\Services\schema_inspection_prime_optional_table_snapshots(['unknown_domain']), 'failed optional bulk declines acceleration');
schema_assert_same(SCHEMA_INSPECTION_UNKNOWN, schema_inspection_table('unknown_domain')['state'], 'failed normal fallback remains unknown');

schema_inspection_set_query_executor_for_tests(null);

echo "Schema inspection model tests passed.\n";
