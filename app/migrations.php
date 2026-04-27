<?php

declare(strict_types=1);

/**
 * Apply all pending database migrations in filename order.
 *
 * MySQL can auto-commit DDL statements such as CREATE TABLE, so migrations are
 * not wrapped in an explicit transaction. Each migration records its version
 * only after every SQL statement in that file has executed successfully.
 */
function run_migrations(): array
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(64) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied = array_flip($applied);
    $files = glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [];
    sort($files);
    $ran = [];

    foreach ($files as $file) {
        $version = basename($file, '.php');
        if (isset($applied[$version])) {
            continue;
        }
        $statements = require $file;
        try {
            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }
            $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)');
            $stmt->execute([$version, now_sql()]);
            $ran[] = $version;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    return $ran;
}

