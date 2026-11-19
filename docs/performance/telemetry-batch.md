# Bounded database telemetry persistence

The request-local database observer still groups executed statements by route,
operation, table and normalized fingerprint. It retains the existing per-query
rounding, slow threshold, failures, sums and maximum latency. SELECT row counts
remain deliberately zero. Raw SQL, bound values and driver errors never enter
aggregate parameters.

The telemetry model now writes up to 64 aggregate rows per parameterized INSERT
into `telemetry_db_query_metrics`. Its thirteen columns use 832 placeholders at
that bound. This reduces N nonempty aggregate buckets from N INSERT executions
to ceil(N / 64). The observed local 33-bucket workload would require one rather
than 33 INSERTs; the observed production 78-bucket workload would require two
rather than 78. These are query-count predictions, not measured latency gains.
The reported local shutdown tail was about 40 ms; the production observation
contained 78 UPSERTs before FPM detach and 130/331 ms timings. Batching does not
move response finishing, release a session or change shutdown ordering.

SQL ownership stays in `app/models/telemetry.php`. The existing MySQL/MariaDB
`ON DUPLICATE KEY UPDATE` dialect adds supplied counters/sums and takes the
greatest latency. There is no schema, index, setting or migration change. The
placeholder bound also stays below SQLite's historical 999-variable limit,
but this writer already uses MySQL-specific SQL: this change introduces no
SQLite execution support or untested driver-specific fallback.

The historical single-aggregate writer delegates to the same batch owner.
Malformed row widths are rejected before any statement is prepared. Empty
batches perform no SQL. The observer clears its buffer before persistence and
suppresses all self-observation for the complete flush. A database exception
still stops subsequent work, attempts the existing privacy-safe warning and
leaves the request fail-open; suppression is released in `finally`. Completed
batches remain committed under the existing connection semantics. Failed
multi-row statements may discard more unpersisted buckets together than the
former single-row statement; there is no retry, new transaction or replay that
could double counters. Error paths remain best-effort telemetry, not durable
business writes.

The registered `telemetry_database_observer_contract_test.php` exercises the
real model through a capturing PDO boundary: exact aggregation parameters,
placeholder batching across the bound, empty and malformed batches, recursive
suppression, privacy and failure cleanup. It does not execute database SQL.
The existing registered `telemetry_storage_compaction_database_test.php` also
executes the real UPSERT through its validated disposable MySQL/MariaDB
connection and temporary shadow table. It covers duplicate keys within one
statement, duplicates across the configured 64-row boundary, independent route
and fingerprint identities, exact counter/sum/max/timestamp values, and both
single-row compatibility writers. Its existing required/optional environment
and safety checks are unchanged. This coverage has been authored but not run
locally. Exact-candidate hosted database results and target-host latency
measurements remain required before claiming qualified database compatibility
or observed performance gains. No local tests were run for this batch.

No TeX manual impact: this internal persistence optimization changes neither
administrator controls, metric semantics nor documented operating procedures;
this reference records its implementation and qualification limits.
