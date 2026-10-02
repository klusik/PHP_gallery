<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_dashboard_overview_totals_test.php
 * Module Type: Regression Test
 * Purpose: Verify indexed dashboard reads and canonical gallery visibility totals.
 * Responsibilities: Exercise production Model/Service boundaries using disposable metadata.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return a disposable metadata reader. @return \DashboardOverviewDatabase Fixture database. */
    function db(): \DashboardOverviewDatabase { return $GLOBALS['overview_database']; }
}
namespace {
    /** Minimal statement seam returning fixture rows without reading installation data. */
    final class DashboardOverviewStatement {
        /** Store prepared fixture records. @param array<int,array<string,mixed>> $rows Rows. @return void Initializes fixture rows. */
        public function __construct(private array $rows) {}
        /** Return gallery rows. @return array<int,array<string,mixed>> Raw records. */
        public function fetchAll(): array { return $this->rows; }
        /** Return indexed image aggregates. @return array<string,mixed> Aggregate record. */
        public function fetch(): array { return $this->rows[0]; }
    }
    /** Record the bounded production queries, with a seam for unavailable storage. */
    final class DashboardOverviewDatabase {
        /** Executed read statements in request order. @var list<string> */
        public array $queries = [];
        /** Fail a metadata read without exposing a fake successful zero. @var bool */
        public bool $fail = false;
        /** Execute one recorded fixture query. @param string $sql Production SQL. @return DashboardOverviewStatement Metadata rows. */
        public function query(string $sql): DashboardOverviewStatement {
            $this->queries[] = $sql;
            if ($this->fail) throw new \RuntimeException('unavailable storage');
            return new DashboardOverviewStatement(str_contains($sql, 'FROM images')
                ? [['total_images' => 17, 'original_bytes' => 4096]]
                : [
                    ['visibility' => 'public', 'access_listing' => 'listed'],
                    ['visibility' => 'public', 'access_listing' => 'unlisted'],
                    ['visibility' => 'private', 'access_listing' => 'listed'],
                    ['visibility' => 'draft', 'access_listing' => 'listed'],
                    ['visibility' => 'unlisted', 'access_listing' => 'listed'],
                    ['visibility' => 'invalid', 'access_listing' => 'listed'],
                ]);
        }
    }
    /** Assert a metadata/policy boundary. @param bool $condition Expected behavior. @param string $message Failure context. @return void Throws on regression. */
    function overview_totals_check(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    require_once __DIR__ . '/../app/models/admin_dashboard.php';
    require_once __DIR__ . '/../app/services/gallery_access.php';
    require_once __DIR__ . '/../app/services/admin_dashboard.php';
    $GLOBALS['overview_database'] = new DashboardOverviewDatabase();
    $totals = \Gallery\Services\admin_dashboard_overview_totals(true);
    overview_totals_check($totals === ['total_galleries' => 6, 'total_images' => 17, 'unpublished_galleries' => 4, 'private_galleries' => 1, 'original_bytes' => 4096], 'Effective visibility must include unlisted, legacy and invalid rows');
    $queries = $GLOBALS['overview_database']->queries;
    overview_totals_check(count($queries) === 2 && !str_contains($queries[0], 'JOIN') && !str_contains($queries[0], 'cover'), 'Overview reads minimal visibility metadata without cover queries');
    overview_totals_check(str_contains($queries[1], "relative_path NOT LIKE '%/%'") && str_contains($queries[1], 'g.id IS NOT NULL') && str_contains($queries[1], 'SUM(i.file_size)'), 'Image totals preserve top-level gallery membership and indexed original storage');
    \Gallery\Models\admin_dashboard_model_overview_totals(false);
    overview_totals_check(str_contains($GLOBALS['overview_database']->queries[2], "'listed' AS access_listing"), 'Unavailable optional access columns use the established listing fallback');
    $GLOBALS['overview_database']->fail = true;
    try { \Gallery\Services\admin_dashboard_overview_totals(true); throw new \LogicException('False zero returned'); }
    catch (\RuntimeException $exception) { overview_totals_check($exception->getMessage() === 'unavailable storage', 'Failed reads must propagate to the retryable endpoint'); }
    echo "Dashboard Overview totals tests passed.\n";
}
