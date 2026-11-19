<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/telemetry_database_observer_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects central privacy-safe database query telemetry and recursion suppression.
 *
 * Responsibilities:
 *   - Verify normal and Admin PDO paths observe executed statements centrally
 *   - Verify request-local aggregation stores only safe SQL shape metadata
 *   - Verify SELECT rowCount values are not misreported as rows returned
 *   - Verify telemetry's own tables are excluded from observation
 *   - Verify shutdown-style persistence is suppression guarded and batched by fingerprint
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Return deterministic request data for the isolated observer fixture. */
    function request_data(?string $bucket = null): array
    {
        return $bucket === 'query' ? ['page' => 'gallery'] : [];
    }

    /** Return deterministic persistence time for the isolated observer fixture. */
    function now_sql(): string
    {
        return '2026-09-19 09:00:00';
    }
}

namespace Gallery\Core {
    /** Capture prepared telemetry statements without connecting to a database. */
    class TelemetryObserverFixturePDO extends \PDO
    {
        /**
         * Construct an isolated PDO boundary without opening a connection.
         * @return void Initializes only the fixture object.
         */
        public function __construct()
        {
        }

        /**
         * Capture generated SQL and return an execution recorder.
         * @param string $query Model-owned SQL statement.
         * @param array<int|string,int|string|bool> $options Unused driver options.
         * @return \PDOStatement|false Fixture execution recorder.
         */
        public function prepare(string $query, array $options = []): \PDOStatement|false
        {
            $GLOBALS['telemetry_db_observer_fixture_sql'][] = $query;
            return new TelemetryObserverFixtureStatement();
        }
    }

    /** Record real model execution parameters and exercise observer suppression. */
    class TelemetryObserverFixtureStatement extends \PDOStatement
    {
        /**
         * Construct a statement recorder without a native prepared statement.
         * @return void Initializes only the fixture object.
         */
        public function __construct()
        {
        }

        /**
         * Capture a batch or inject an execution failure.
         * @param list<string|int>|null $params Flattened aggregate parameters.
         * @return bool True after recording; injected failures throw.
         */
        public function execute(?array $params = null): bool
        {
            if (!empty($GLOBALS['telemetry_db_observer_fixture_fail'])) {
                throw new \RuntimeException('private driver error');
            }
            $GLOBALS['telemetry_db_observer_fixture_batches'][] = $params;
            // Each row has thirteen schema columns, matching the model INSERT.
            foreach (array_chunk($params ?? [], 13) as $row) {
                $GLOBALS['telemetry_db_observer_fixture_writes'][] = $row;
            }
            \Gallery\Services\telemetry_observe_db_query(
                'INSERT INTO images (filename) VALUES (?)', 3.0, true, 1, null,
            );
            return true;
        }
    }

    /**
     * Supply the isolated PDO boundary to the real telemetry model.
     * @return \PDO Capturing fixture connection.
     */
    function db(): \PDO
    {
        return new TelemetryObserverFixturePDO();
    }
}

namespace Gallery\Services {
    /** Keep the telemetry capability enabled for the isolated fixture. */
    function feature_capability_effective_enabled(string $feature): bool
    {
        return $feature === 'telemetry';
    }

    /** Report the complete telemetry schema as available for the fixture. */
    function telemetry_schema_ready(): bool
    {
        return true;
    }

    /** Return deterministic DB telemetry settings without any database access. */
    function telemetry_all_settings(): array
    {
        return $GLOBALS['telemetry_db_observer_fixture_settings'] ?? [
            'telemetry_enabled' => '1',
            'telemetry_database_enabled' => '1',
            'telemetry_slow_query_threshold_ms' => '250',
        ];
    }

    /**
     * Normalize one bounded identifier for the isolated fixture.
     *
     * @param string|int|float|bool|\Stringable|null $value Identifier value before normalization.
     * @param int $maxLength Maximum normalized identifier length.
     * @return string|null Normalized identifier, or null when it is empty.
     */
    function telemetry_short_identifier(
        mixed $value,
        int $maxLength = 80,
    ): ?string {
        $text = trim((string) $value);
        return $text === '' ? null : substr($text, 0, $maxLength);
    }
}

namespace {
    require_once __DIR__ . '/support/php_source.php';
    use function Gallery\Services\telemetry_database_observer_state;
    use function Gallery\Services\telemetry_flush_db_query_buffer;
    use function Gallery\Services\telemetry_observe_db_query;
    use function Gallery\Services\telemetry_sql_fingerprint;

    /**
     * Throw when one database-observer contract fails.
     *
     * @param bool $condition Whether the contract assertion passed.
     * @param string $label Failure message identifying the violated contract.
     * @return void
     */
    function telemetry_database_observer_contract_assert(
        bool $condition,
        string $label,
    ): void {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    $root = dirname(__DIR__);
    require_once $root . '/app/models/telemetry.php';
    require_once $root . '/app/services/database_observer.php';

    unset($GLOBALS['cms_telemetry_database_observer_state']);
    $GLOBALS['telemetry_db_observer_fixture_writes'] = [];
    $GLOBALS['telemetry_db_observer_fixture_settings'] = [
        'telemetry_enabled' => '1',
        'telemetry_database_enabled' => '1',
        'telemetry_slow_query_threshold_ms' => '250',
    ];

    telemetry_observe_db_query(
        "SELECT id FROM images WHERE email = 'private@example.invalid' AND id = 123",
        10.4,
        true,
        99,
        null,
    );
    telemetry_observe_db_query(
        "SELECT id FROM images WHERE email = 'another@example.invalid' AND id = 456",
        300.2,
        true,
        88,
        null,
    );
    telemetry_observe_db_query(
        "UPDATE galleries SET title = 'Private title' WHERE id = 5",
        21.0,
        false,
        0,
        'driver detail that must not persist',
    );
    telemetry_observe_db_query(
        'INSERT INTO images (filename) VALUES (?)',
        12.0,
        true,
        3,
        null,
    );
    telemetry_observe_db_query(
        'INSERT INTO telemetry_events (event_name) VALUES (?)',
        8.0,
        true,
        1,
        null,
    );

    $state = &telemetry_database_observer_state();
    telemetry_database_observer_contract_assert(
        count($state['buffer']) === 3,
        'Equivalent SELECT shapes must aggregate while telemetry table writes remain excluded.',
    );

    $bufferJson = json_encode(
        $state['buffer'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
    );
    telemetry_database_observer_contract_assert(
        is_string($bufferJson),
        'Observer buffer must be serializable for the isolated fixture.',
    );
    telemetry_database_observer_contract_assert(
        !str_contains($bufferJson, 'private@example.invalid'),
        'Observer buffer must never retain SQL string literals.',
    );
    telemetry_database_observer_contract_assert(
        !str_contains($bufferJson, 'Private title'),
        'Observer buffer must never retain user-entered SQL values.',
    );
    telemetry_database_observer_contract_assert(
        !str_contains($bufferJson, 'driver detail'),
        'Observer buffer must never retain database error text.',
    );

    $selectRows = array_values(array_filter(
        $state['buffer'],
        static fn(array $row): bool => ($row['operation'] ?? '') === 'select',
    ));
    telemetry_database_observer_contract_assert(
        count($selectRows) === 1,
        'Equivalent SELECT literals must collapse to one fingerprint bucket.',
    );
    telemetry_database_observer_contract_assert(
        (int) $selectRows[0]['query_count'] === 2,
        'Equivalent SELECT executions must aggregate query count in memory.',
    );
    telemetry_database_observer_contract_assert(
        (int) $selectRows[0]['slow_count'] === 1,
        'Slow threshold must be applied per execution before aggregation.',
    );
    telemetry_database_observer_contract_assert(
        (int) $selectRows[0]['rows_returned_sum'] === 0,
        'PDO rowCount must not be treated as portable SELECT rows returned.',
    );
    telemetry_database_observer_contract_assert(
        (int) $selectRows[0]['latency_ms_max'] === 300,
        'Aggregate must retain the maximum rounded query latency.',
    );

    $fingerprintA = telemetry_sql_fingerprint(
        "SELECT id FROM images WHERE email = 'private@example.invalid' AND id = 123",
    );
    $fingerprintB = telemetry_sql_fingerprint(
        "SELECT id FROM images WHERE email = 'other@example.invalid' AND id = 999",
    );
    telemetry_database_observer_contract_assert(
        $fingerprintA === $fingerprintB
            && preg_match('/^[a-f0-9]{16}$/', $fingerprintA) === 1,
        'SQL fingerprint must be stable across literal values and expose only a short hash.',
    );

    telemetry_flush_db_query_buffer();
    $state = &telemetry_database_observer_state();
    telemetry_database_observer_contract_assert(
        count($GLOBALS['telemetry_db_observer_fixture_writes']) === 3,
        'Flush must persist one row per request-local aggregate bucket.',
    );
    telemetry_database_observer_contract_assert(
        $state['buffer'] === [],
        'Successful flush must clear the request-local aggregate buffer.',
    );
    telemetry_database_observer_contract_assert(
        $state['suppressed'] === false,
        'Observer suppression must be released after flush.',
    );

    telemetry_database_observer_contract_assert(
        count($GLOBALS['telemetry_db_observer_fixture_batches']) === 1
            && count($GLOBALS['telemetry_db_observer_fixture_batches'][0]) === 39,
        'Three aggregates must share one statement with thirteen bound fields each.',
    );
    $selectWrite = $GLOBALS['telemetry_db_observer_fixture_writes'][0];
    telemetry_database_observer_contract_assert(
        array_slice($selectWrite, 5, 7) === [2, 0, 1, 310, 300, 0, 0],
        'Batching must retain exact SELECT counts, rounded sums/max and portable row semantics.',
    );
    telemetry_database_observer_contract_assert(
        array_slice($GLOBALS['telemetry_db_observer_fixture_writes'][1], 5, 7)
            === [1, 1, 0, 21, 21, 0, 0]
            && array_slice($GLOBALS['telemetry_db_observer_fixture_writes'][2], 5, 7)
                === [1, 0, 0, 12, 12, 0, 3],
        'Failure and affected-row counters must survive batch flattening exactly.',
    );
    telemetry_database_observer_contract_assert(
        substr_count($GLOBALS['telemetry_db_observer_fixture_sql'][0], '?')
            === count($GLOBALS['telemetry_db_observer_fixture_batches'][0]),
        'Generated placeholders must match the complete flattened parameter list.',
    );
    $batchRow = $selectWrite;
    $batchLimit = \Gallery\Models\TELEMETRY_MODEL_QUERY_METRIC_BATCH_ROWS;
    $GLOBALS['telemetry_db_observer_fixture_batches'] = [];
    // One row beyond the configured bound must require exactly two statements.
    \Gallery\Models\telemetry_model_upsert_db_query_metric_aggregates(
        array_fill(0, $batchLimit + 1, $batchRow),
    );
    telemetry_database_observer_contract_assert(
        array_map('count', $GLOBALS['telemetry_db_observer_fixture_batches'])
            === [$batchLimit * count($batchRow), count($batchRow)],
        'A boundary overflow must preserve every row in two bounded executions.',
    );
    $beforeEmpty = count($GLOBALS['telemetry_db_observer_fixture_sql']);
    \Gallery\Models\telemetry_model_upsert_db_query_metric_aggregates([]);
    $rejected = false;
    try {
        \Gallery\Models\telemetry_model_upsert_db_query_metric_aggregates(
            [$batchRow, []],
        );
    } catch (\InvalidArgumentException) {
        $rejected = true;
    }
    telemetry_database_observer_contract_assert(
        $rejected && count($GLOBALS['telemetry_db_observer_fixture_sql']) === $beforeEmpty,
        'Empty batches perform no SQL and malformed later rows must reject before any write.',
    );
    telemetry_observe_db_query('SELECT id FROM images', 1.0, true);
    $GLOBALS['telemetry_db_observer_fixture_fail'] = true;
    telemetry_flush_db_query_buffer();
    $GLOBALS['telemetry_db_observer_fixture_fail'] = false;
    telemetry_database_observer_contract_assert(
        $state['buffer'] === [] && $state['suppressed'] === false,
        'Failed persistence must remain fail-open, discard the flushed buffer and release suppression.',
    );

    unset($GLOBALS['cms_telemetry_database_observer_state']);
    $GLOBALS['telemetry_db_observer_fixture_settings']['telemetry_database_enabled'] = '0';
    telemetry_observe_db_query(
        'SELECT id FROM images WHERE id = 1',
        1.0,
        true,
        1,
        null,
    );
    $disabledState = &telemetry_database_observer_state();
    telemetry_database_observer_contract_assert(
        $disabledState['buffer'] === [],
        'Disabled database telemetry must not allocate query aggregates.',
    );

    unset($GLOBALS['cms_telemetry_database_observer_state']);
    $GLOBALS['telemetry_db_observer_fixture_settings'] = [
        'telemetry_enabled' => '0',
        'telemetry_database_enabled' => '1',
        'telemetry_slow_query_threshold_ms' => '250',
    ];
    telemetry_observe_db_query(
        'SELECT id FROM galleries WHERE id = 1',
        1.0,
        true,
        1,
        null,
    );
    $masterDisabledState = &telemetry_database_observer_state();
    telemetry_database_observer_contract_assert(
        $masterDisabledState['buffer'] === [],
        'Telemetry master switch must disable the database observer even when its sub-switch remains enabled.',
    );

    $databaseSource = (string) file_get_contents($root . '/app/database.php');
    $modelSource = (string) file_get_contents($root
        . '/app/models/telemetry.php');
    telemetry_database_observer_contract_assert(
        php_source_contains(
            $databaseSource,
            'class TelemetryPDOStatement extends PDOStatement',
        )
            && php_source_contains($databaseSource, 'class TelemetryPDO extends PDO')
            && php_source_contains(
                $databaseSource,
                '$pdoClass = $traceActive ? AdminTestRunPDO::class : TelemetryPDO::class;',
            )
            && php_source_contains(
                $databaseSource,
                '$statementClass = $traceActive ? AdminTestRunPDOStatement::class : TelemetryPDOStatement::class;',
            )
            && php_source_contains($databaseSource, 'telemetry_observe_db_query'),
        'Normal and Admin PDO execution paths must dispatch central query observations without patching models individually.',
    );
    telemetry_database_observer_contract_assert(
        php_source_contains(
            $modelSource,
            'function telemetry_model_upsert_db_query_metric_aggregate(array $params): void',
        )
            && php_source_contains(
                $modelSource,
                'query_count = query_count + VALUES(query_count)',
            ),
        'Database telemetry persistence must accept request-local aggregate counts instead of one write per executed query.',
    );

    $observerSource = (string) file_get_contents($root
        . '/app/services/database_observer.php');
    telemetry_database_observer_contract_assert(
        php_source_contains(
            $observerSource,
            "\$masterEnabled = (string) (\$settings['telemetry_enabled'] ?? '0') === '1';",
        )
            && php_source_contains(
                $observerSource,
                "\$state['enabled'] = \$masterEnabled && \$databaseEnabled;",
            ),
        'Database observer must stop at the request boundary when the telemetry master switch is disabled.',
    );

    $adminControllerSource = (string) file_get_contents($root
        . '/app/controllers/admin_telemetry.php');
    $adminViewSource = (string) file_get_contents($root
        . '/app/views/admin_telemetry.php');
    telemetry_database_observer_contract_assert(
        php_source_contains(
            $adminControllerSource,
            "telemetry_setting_enabled('telemetry_enabled', '0')",
        )
            && php_source_contains(
                $adminControllerSource,
                "telemetry_setting_enabled('telemetry_database_enabled', '1')",
            )
            && php_source_contains(
                $adminControllerSource,
                'admin.telemetry.export.no_samples',
            )
            && php_source_contains($adminViewSource, "'database_scope_note'")
            && php_source_contains($adminViewSource, '$dbQueryCountDisplay'),
        'Database telemetry reporting must honor the master/database switches, distinguish Disabled/No samples, and disclose the central PDO privacy scope.',
    );

    echo "telemetry_database_observer_contract_test: ok\n";
}
