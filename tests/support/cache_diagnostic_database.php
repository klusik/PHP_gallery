<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/cache_diagnostic_database.php
 * Module Type: Regression Support
 * Purpose: Share the owned database double used with real Admin log services and models.
 * Responsibilities: Expose installed columns, retain insert field order, reproduce width rejection and optionally persist normalized rows through a test-owned receipt sink.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

/** Supply isolated schema queries and rejecting insert statements to the real log model. */
class CacheDiagnosticDatabase {
    /**
     * Expose the installed log columns without querying a server.
     * @param string $sql Model-owned schema query.
     * @return CacheDiagnosticColumns Statement double whose fetchAll returns column records.
     */
    public function query(string $sql): CacheDiagnosticColumns {
        return new CacheDiagnosticColumns();
    }
    /**
     * Prepare a log insert double retaining its actual field order.
     * @param string $sql Model-owned insert statement.
     * @return CacheDiagnosticInsert Statement double with an execute method.
     */
    public function prepare(string $sql): CacheDiagnosticInsert {
        $statement = new CacheDiagnosticInsert();
        preg_match('/admin_logs \(([^)]+)\)/', $sql, $match);
        $statement->fields = explode(', ', $match[1]);
        return $statement;
    }
}

/** Expose installed field names without contacting a database. */
class CacheDiagnosticColumns {
    /**
     * Return fields available in the fixture log schema.
     * @return list<array{Field:string}> Installed log field names.
     */
    public function fetchAll(): array {
        return array_map(static fn (string $field): array => ['Field' => $field],
            ['status','user_id','level','category','severity','event_key','message','subject_type','subject_id','request_id','route_name','context_json','created_at']);
    }
}

/** Emulate the installed request-ID width and capture successfully persisted rows. */
class CacheDiagnosticInsert {
    public array $fields = [];
    /**
     * Capture normalized rows or emulate the observed MySQL width failure.
     * @param list<int|string|null> $params Values matching the prepared field order.
     * @return bool True for accepted rows, or the optional test-owned receipt sink's result; throws PDOException on explicit rejection or an oversized request ID.
     */
    public function execute(array $params): bool {
        $row = array_combine($this->fields, $params);
        if ($GLOBALS['cache_diagnostic_refuse'] || strlen((string) ($row['request_id'] ?? '')) > 26) {
            $exception = new \PDOException("Data too long for column 'request_id'; PRIVATE_SQL_TOKEN", 22001);
            $exception->errorInfo = ['22001', 1406, 'PRIVATE_SQL_TOKEN'];
            throw $exception;
        }
        $GLOBALS['cache_diagnostic_rows'][] = $row;
        // An HTTP fixture can supply Closure(array<string,int|string|null>):bool
        // to retain normalized receipts across requests without replacing the
        // production service or model declarations.
        return isset($GLOBALS['cache_diagnostic_sink']) ? ($GLOBALS['cache_diagnostic_sink'])($row) : true;
    }
}
