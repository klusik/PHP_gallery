<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_report_policy_test.php
 * Module Type: Regression Test
 * Purpose: Execute report query bounds and central output-policy consumers without a database.
 * Responsibilities:
 *   - Capture model SQL and integer bindings; preserve historical limits and grouping order.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Supply the non-networked report query recorder. @return \PDO Isolated PDO-compatible recorder. */
    function db(): \PDO { return $GLOBALS['report_policy_db']; }
}

namespace {
    require_once dirname(__DIR__) . '/app/models/admin_gallery_report.php';
    require_once dirname(__DIR__) . '/app/services/admin_gallery_report.php';

    /** Record report queries without constructing a real database connection. */
    final class ReportPolicyPdo extends PDO
    {
        /** @var list<string> Ordered SQL issued by the report model. */
        public array $queries = [];
        /** @var ReportPolicyStatement|null Most recently prepared statement and its integer bindings. */
        public ?ReportPolicyStatement $last = null;
        /** Create a recorder with no database constructor side effects. @return void */
        public function __construct() {}
        /**
         * Record one model-owned SQL statement.
         * @param string $query SQL generated internally by the report model.
         * @param array<int,mixed> $options Driver options; unused because this recorder has no driver.
         * @return PDOStatement Non-executing recorder.
         */
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $this->queries[] = $query;
            return $this->last = new ReportPolicyStatement();
        }
    }

    /** Record statement bindings and return empty, successful report sections. */
    final class ReportPolicyStatement extends PDOStatement
    {
        /** @var array<int|string,int|string> Captured bound values indexed by placeholder. */
        public array $bindings = [];
        /**
         * Capture only the integer pagination values used by the report model.
         * @param string|int $param Placeholder index or name.
         * @param int $value Required pagination integer; the native mixed signature is checked before recording.
         * @param int $type PDO binding type, required to be PARAM_INT.
         * @return bool True after checking the integer contract.
         */
        public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
        {
            report_policy_expect(is_int($value) && $type === PDO::PARAM_INT, 'Report pagination lost integer binding.');
            $this->bindings[$param] = $value;
            return true;
        }
        /** Acknowledge the captured query without I/O. @param array<array-key,scalar|null>|null $params Unused driver parameter map. @return bool Always true. */
        public function execute(?array $params = null): bool { return true; }
        /**
         * Return an empty result without executing captured SQL.
         * @param int $mode Unused native fetch mode.
         * @param mixed ...$args Unused native fetch arguments, never inspected by this recorder.
         * @source-contract-opaque-param args Unused PDO compatibility arguments are never inspected or propagated.
         * @return array<int,array<string,mixed>> Empty fixture result.
         */
        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return []; }
        /** Return an empty scalar section. @param int $column Unused column index. @return false No rows exist in this recorder. */
        public function fetchColumn(int $column = 0): mixed { return false; }
    }

    /** Require one safe report-policy invariant. @param bool $condition Expected invariant. @param string $message Failure label. @return void */
    function report_policy_expect(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    $GLOBALS['report_policy_db'] = $db = new ReportPolicyPdo();
    foreach ([0 => 1, 250 => 250, 1000 => \Gallery\Core\ADMIN_GALLERY_REPORT_MAX_BATCH_SIZE] as $requested => $expected) {
        \Gallery\Models\admin_gallery_report_model_image_rows_after_id(-1, $requested, []);
        report_policy_expect($db->last->bindings === [1 => 0, 2 => $expected], 'Image cursor/batch clamp changed.');
    }
    \Gallery\Models\admin_gallery_report_model_largest_images(1000);
    report_policy_expect(str_ends_with($db->queries[array_key_last($db->queries)], 'LIMIT ' . \Gallery\Core\ADMIN_GALLERY_REPORT_ROW_LIMITS['max_top_images']), 'Top-image section escaped its own output ceiling.');
    \Gallery\Models\admin_gallery_report_model_feature_settings();
    report_policy_expect(str_ends_with($db->queries[array_key_last($db->queries)], 'LIMIT ' . \Gallery\Core\ADMIN_GALLERY_REPORT_ROW_LIMITS['feature_settings']), 'Feature settings lost their central row bound.');
    $groups = [
        ['label' => 'Beta', 'count' => 2],
        ['label' => 'Alpha', 'count' => 2],
        ['label' => 'Largest', 'count' => 3],
        null,
    ];
    $rows = \Gallery\Services\admin_gallery_report_finalize_group_rows($groups, 'count', 2);
    report_policy_expect(array_column($rows, 'label') === ['Largest', 'Alpha'], 'Central policy extraction changed ranking, invalid-row filtering or truncation.');
    report_policy_expect(\Gallery\Core\ADMIN_GALLERY_REPORT_TELEMETRY_COMPARISON_WINDOWS === [7, 30, 90, 365], 'Telemetry comparison periods changed.');
    echo "Gallery report policy execution checks passed.\n";
}
