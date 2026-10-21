<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/image_quick_tag_failure_model.php
 * Module Type: Model Regression Fixture
 * Purpose: Exercise image-tag rollback through the production tag model without privileged database features.
 * Responsibilities:
 *   - Provide an isolated PDO double for the canonical tag persistence owner.
 *   - Verify tag-row rollback when a targeted assignment write fails.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return the isolated transaction double used by the tag model test.
     * @return \PDO In-memory PDO subclass owned by the current regression case.
     */
    function db(): \PDO
    {
        $connection = $GLOBALS['image_quick_tag_failure_connection'] ?? null;
        if (!$connection instanceof \PDO) {
            throw new \RuntimeException(
                'The image-tag failure connection was not initialized.',
            );
        }
        return $connection;
    }
}

namespace {
    /** Execute the production tag model against an in-memory transactional state. */
    final class ImageQuickTagFailurePdo extends PDO
    {
        /** @var array<int,array{id:int,name:string,slug:string,media_type:string}> */
        public array $tags = [
            20 => [
                'id' => 20,
                'name' => 'Existing image tag',
                'slug' => 'existing-image-tag',
                'media_type' => 'image',
            ],
        ];

        /** @var list<array{image_id:int,tag_id:int}> */
        public array $assignments = [['image_id' => 77, 'tag_id' => 20]];

        /** @var list<string> Ordered transaction operations observed by this test. */
        public array $events = [];

        /** @var array{tags:array<int,array{id:int,name:string,slug:string,media_type:string}>,assignments:list<array{image_id:int,tag_id:int}>}|null */
        private ?array $before = null;

        private int $lastInsertIdValue = 20;

        /** Construct the detached double without opening a database connection.
         * @return void Initializes only in-memory fixture state.
         */
        public function __construct() {}

        /** Prepare a query accepted by the production tag model mutation path.
         * @param string $query SQL selected by the real model persistence owner.
         * @param array<int,mixed> $options PDO options passed by the model.
         * @return PDOStatement|false A fixture statement or false for unsupported SQL.
         */
        public function prepare(
            string $query,
            array $options = [],
        ): PDOStatement|false {
            return new ImageQuickTagFailureStatement($this, $query);
        }

        /** Start a fixture transaction and snapshot both persistence collections.
         * @return bool True after transaction ownership is established.
         */
        public function beginTransaction(): bool
        {
            $this->events[] = 'begin';
            $this->before = [
                'tags' => $this->tags,
                'assignments' => $this->assignments,
            ];
            return true;
        }

        /** Report whether the fixture transaction is currently active.
         * @return bool True while rollback or commit remains available.
         */
        public function inTransaction(): bool
        {
            return $this->before !== null;
        }

        /** Commit the in-memory transaction.
         * @return bool True when the snapshot is discarded.
         */
        public function commit(): bool
        {
            $this->events[] = 'commit';
            $this->before = null;
            return true;
        }

        /** Restore all rows to their state before the production mutation began.
         * @return bool True when the snapshot was restored.
         */
        public function rollBack(): bool
        {
            $this->events[] = 'rollback';
            if ($this->before === null) {
                return false;
            }
            $this->tags = $this->before['tags'];
            $this->assignments = $this->before['assignments'];
            $this->before = null;
            return true;
        }

        /** Return the identifier assigned by the last fixture tag insert.
         * @param ?string $name Optional sequence name ignored by this fixture.
         * @return string Last assigned identifier in PDO's string representation.
         */
        public function lastInsertId(?string $name = null): string|false
        {
            return (string) $this->lastInsertIdValue;
        }

        /** Resolve one fixture tag by its canonical slug.
         * @param string $slug Candidate tag slug.
         * @return int Existing identifier or zero when no row matches.
         */
        public function tagIdBySlug(string $slug): int
        {
            foreach ($this->tags as $tag) {
                if ($tag['slug'] === $slug) {
                    return $tag['id'];
                }
            }
            return 0;
        }

        /** Record one production tag row as a database INSERT would.
         * @param string $name Display label requested by the model.
         * @param string $slug Canonical slug requested by the model.
         * @param string $mediaType Typed tag category.
         * @return void Stores one row and advances the fixture identifier.
         */
        public function insertTag(
            string $name,
            string $slug,
            string $mediaType,
        ): void {
            $id = max(array_keys($this->tags)) + 1;
            $this->tags[$id] = [
                'id' => $id,
                'name' => $name,
                'slug' => $slug,
                'media_type' => $mediaType,
            ];
            $this->lastInsertIdValue = $id;
        }
    }

    /** Interpret the narrow prepared SQL surface used by one real model mutation.
     * Class: PDOStatement test double.
     */
    final class ImageQuickTagFailureStatement extends PDOStatement
    {
        /** @var list<array{id:int,name:string,slug:string,media_type:string}> Rows returned by the last supported tag query. */
        private array $rows = [];

        /** @var int|string|false Single scalar returned by fetchColumn(). */
        private int|string|false $column = false;

        /** Number of rows changed by the last fixture write. */
        private int $affectedRows = 0;

        /** Retain the fake connection and actual SQL text selected by production.
         * @param ImageQuickTagFailurePdo $connection Transaction state owner.
         * @param string $query Prepared SQL emitted by the tag model.
         * @return void Stores the detached execution context.
         */
        public function __construct(
            private ImageQuickTagFailurePdo $connection,
            private string $query,
        ) {}

        /** Execute one supported query or fail at the targeted assignment write.
         * @param ?array<int,mixed> $params Positional values from the production model.
         * @return bool True when the fixture operation completed.
         * @throws PDOException On the deliberate image_tags persistence failure.
         */
        public function execute(?array $params = null): bool
        {
            $this->rows = [];
            $this->column = false;
            $this->affectedRows = 0;
            if (str_starts_with($this->query, 'SELECT gallery_id FROM images')) {
                $this->column = (int) ($params[0] ?? 0) === 77 ? 88 : false;
                return true;
            }
            if (str_starts_with($this->query, 'SELECT id FROM tags WHERE slug = ?')) {
                $this->column = $this->connection->tagIdBySlug(
                    (string) ($params[0] ?? ''),
                ) ?: false;
                return true;
            }
            if (str_starts_with($this->query, 'INSERT INTO tags')) {
                $this->connection->insertTag(
                    (string) ($params[0] ?? ''),
                    (string) ($params[1] ?? ''),
                    (string) ($params[2] ?? ''),
                );
                $this->affectedRows = 1;
                return true;
            }
            if (str_starts_with($this->query, 'SELECT * FROM tags WHERE id = ?')) {
                $tag = $this->connection->tags[(int) ($params[0] ?? 0)] ?? null;
                $this->rows = is_array($tag) ? [$tag] : [];
                return true;
            }
            if (str_starts_with($this->query, 'SELECT media_type FROM tags WHERE id = ?')) {
                $this->column = $this->connection->tags[
                    (int) ($params[0] ?? 0)
                ]['media_type'] ?? false;
                return true;
            }
            if (str_starts_with($this->query, 'SELECT t.* FROM tags t JOIN image_tags')) {
                foreach ($this->connection->assignments as $assignment) {
                    $tag = $this->connection->tags[$assignment['tag_id']] ?? null;
                    if (
                        $assignment['image_id'] === (int) ($params[0] ?? 0)
                        && is_array($tag)
                        && $tag['media_type'] === (string) ($params[1] ?? '')
                    ) {
                        $this->rows[] = $tag;
                    }
                }
                return true;
            }
            if (str_starts_with($this->query, 'INSERT IGNORE INTO image_tags')) {
                throw new PDOException('Injected assignment write failure.');
            }
            throw new RuntimeException(
                'Unexpected SQL reached the image-tag fixture double.',
            );
        }

        /** Return the first queried fixture row.
         * @param int $mode PDO fetch mode requested by the production model.
         * @param int $cursorOrientation PDO cursor orientation requested by the caller.
         * @param int $cursorOffset PDO cursor offset requested by the caller.
         * @return array{id:int,name:string,slug:string,media_type:string}|false One typed tag row or false when absent.
         */
        public function fetch(
            int $mode = PDO::FETCH_DEFAULT,
            int $cursorOrientation = PDO::FETCH_ORI_NEXT,
            int $cursorOffset = 0,
        ): array|false {
            return array_shift($this->rows) ?? false;
        }

        /** Return the fixture scalar selected by the production model.
         * @param int $columnNumber Zero-based column position.
         * @return int|string|false The selected scalar or false when missing.
         */
        public function fetchColumn(int $columnNumber = 0): int|string|false
        {
            return $this->column;
        }

        /** Return all rows selected by the production tag read.
         * @param int $mode PDO fetch mode requested by the production model.
         * @param mixed ...$args PDO fetch-mode arguments accepted by the native
         *     signature; ignored because every fixture row is associative.
         * @source-contract-opaque-param $args Unused PDO mode-specific fetch
         *     arguments are intentionally ignored by this associative-row fixture.
         * @return list<array{id:int,name:string,slug:string,media_type:string}> Ordered typed tag rows; empty when absent.
         */
        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
        {
            return $this->rows;
        }

        /** Report the number of fixture rows inserted or removed.
         * @return int Affected-row count for the last statement.
         */
        public function rowCount(): int
        {
            return $this->affectedRows;
        }
    }

    require_once dirname(__DIR__, 2) . '/app/models/tags.php';
}
