<?php

/**
 * Project: PHP Gallery
 * Module Type: Test Fixture
 * Purpose: Provide disposable title-completion catalogs and query measurements.
 * Responsibilities:
 *   - Create isolated SQLite data for bounded matching and service contracts.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_title_completion_fixture.php
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Isolated SQLite fixture and query measurements for title completion only.
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Supply the isolated database to the real gallery model. */
    function db(): \Gallery\Tests\TitleCompletionFixtureDatabase
    {
        return $GLOBALS['title_completion_fixture'];
    }
}

namespace Gallery\Tests {
    /** Execute real bounded model SQL while recording its cost and result size. */
    final class TitleCompletionFixtureDatabase
    {
        /** @var \PDO Disposable SQLite connection; never an application database. */
        public \PDO $pdo;
        /** @var array<int,array<string,mixed>> Recorded model queries and measurements. */
        public array $queries = [];
        /** @var ?int One-based query number at which to simulate a storage failure. */
        public ?int $failAtQuery = null;

        /** Create an isolated gallery schema with its existing parent index. */
        public function __construct()
        {
            $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $this->pdo->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY, parent_id INTEGER NULL, title VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL)');
            $this->pdo->exec('CREATE INDEX galleries_parent_id_index ON galleries(parent_id)');
        }

        /** Reset a synthetic catalog; setup statements are outside measurements. */
        public function seed(array $rows): void
        {
            $this->pdo->exec('DELETE FROM galleries');
            $stmt = $this->pdo->prepare('INSERT INTO galleries (id, parent_id, title, created_at) VALUES (?, ?, ?, ?)');
            $this->pdo->beginTransaction();
            foreach ($rows as $row) {
                $stmt->execute([$row['id'], $row['parent_id'], $row['title'], $row['created_at']]);
            }
            $this->pdo->commit();
            $this->queries = [];
            $this->failAtQuery = null;
        }

        /** Wrap a real model statement so execution and fetch costs are captured. */
        public function prepare(string $sql): TitleCompletionFixtureStatement
        {
            return new TitleCompletionFixtureStatement($this, $sql);
        }
    }

    /** Statement wrapper measures execution/fetch without mocking SQL semantics. */
    final class TitleCompletionFixtureStatement
    {
        /** @var \PDOStatement Executed SQLite statement for the current model page. */
        private \PDOStatement $statement;
        /** @var int Offset of this statement in the fixture's query measurements. */
        private int $queryIndex;
        /** @var int Monotonic execution start time in nanoseconds. */
        private int $started;

        /** Retain the fixture and model SQL without executing a query yet. */
        public function __construct(private TitleCompletionFixtureDatabase $database, private string $sql)
        {
        }

        /** Execute parameterized model SQL or raise the configured fixture failure. */
        public function execute(array $params): void
        {
            $this->queryIndex = count($this->database->queries);
            $this->database->queries[] = ['sql' => $this->sql, 'params' => $params, 'rows' => 0, 'milliseconds' => 0.0];
            if ($this->database->failAtQuery === $this->queryIndex + 1) {
                throw new \RuntimeException('Private database error: /secret/path password=never-expose');
            }
            $this->started = hrtime(true);
            $this->statement = $this->database->pdo->prepare($this->sql);
            $this->statement->execute($params);
        }

        /** Fetch the bounded page and record materialized rows and elapsed time. */
        public function fetchAll(int $mode): array
        {
            $rows = $this->statement->fetchAll($mode);
            $this->database->queries[$this->queryIndex]['rows'] = count($rows);
            $this->database->queries[$this->queryIndex]['milliseconds'] = (hrtime(true) - $this->started) / 1000000;
            return $rows;
        }
    }

    /** Stable ties exercise the id component of the newest-first keyset. */
    function title_completion_fixture_rows(int $size): array
    {
        $rows = [];
        for ($id = 1; $id <= $size; $id++) {
            $rows[] = [
                'id' => $id,
                'parent_id' => $id % 4 === 0 ? 7 : null,
                'title' => sprintf('Flight %05d', $id),
                'created_at' => '2026-09-20 12:00:00',
            ];
        }
        return $rows;
    }
}
