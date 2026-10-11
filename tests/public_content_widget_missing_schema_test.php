<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_content_widget_missing_schema_test.php
 * Module Type: Regression Test
 * Purpose: Prove public widget reads fail closed when the optional widget table is missing.
 * Responsibilities:
 *   - Exercise the production public row reader and page planner without opening a database.
 *   - Ensure missing-table diagnostics never enter public widget output.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the disconnected PDO fixture used by the public widget model.
     *
     * @return \PDO Connection that reports the missing optional widget table.
     */
    function db(): \PDO
    {
        return $GLOBALS['public_widget_missing_schema_connection'];
    }
}

namespace {
    /**
     * Model a public widget SELECT failing because its optional table is absent.
     */
    final class PublicWidgetMissingSchemaPdo extends \PDO
    {
        /** @var int Number of model SELECT attempts made by the production reader. */
        public int $queryCount = 0;

        /**
         * Construct a disconnected PDO test double.
         *
         * @return void Opens no database connection.
         */
        public function __construct() {}

        /**
         * Raise a synthetic MySQL/MariaDB missing-table error for the widget query.
         *
         * @param string $query Model-owned SQL statement expected to select public widgets.
         * @param int|null $fetchMode Optional PDO fetch mode supplied by the caller.
         * @param scalar|array<array-key,mixed>|object|resource|null ...$fetchModeArgs Optional inherited PDO fetch arguments, accepted and ignored by this disconnected fixture.
         * @return \PDOStatement|false Never returns because the optional table is absent.
         */
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
        {
            if (!str_contains($query, 'FROM public_content_widgets') || $fetchMode !== null) {
                throw new \RuntimeException('Unexpected public widget missing-schema query.');
            }
            $this->queryCount++;
            throw new \PDOException(
                'SQLSTATE[42S02]: Base table or view not found: 1146 Table public_content_widgets does not exist; MISSING_WIDGET_SCHEMA_PRIVATE_DIAGNOSTIC'
            );
        }
    }

    require_once dirname(__DIR__) . '/app/services/public_content_widgets.php';

    use function Gallery\Services\public_widget_public_plan;
    use function Gallery\Services\public_widget_public_rows;

    $check = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    };

    $connection = new PublicWidgetMissingSchemaPdo();
    $GLOBALS['public_widget_missing_schema_connection'] = $connection;
    $regions = [
        'content_top', 'content_bottom', 'left_rail', 'right_rail',
        'home_before_grid', 'home_after_grid', 'footer', 'floating',
    ];

    foreach (['home', 'gallery'] as $pageType) {
        $rows = public_widget_public_rows($pageType);
        $plan = public_widget_public_plan($pageType);
        $check($rows === [], ucfirst($pageType) . ' public rows must be empty when widget storage is missing.');
        $check(array_keys($plan) === $regions, ucfirst($pageType) . ' plan must retain its complete safe region shape.');
        foreach ($plan as $region => $items) {
            $check($items === [], ucfirst($pageType) . ' region ' . $region . ' must contain no widget rows.');
        }
        $encoded = json_encode(['rows' => $rows, 'plan' => $plan], JSON_UNESCAPED_SLASHES);
        $check(is_string($encoded)
            && !str_contains($encoded, 'MISSING_WIDGET_SCHEMA_PRIVATE_DIAGNOSTIC'),
            ucfirst($pageType) . ' public output must not expose database diagnostics.');
    }

    $check($connection->queryCount === 4, 'Both public readers and plans must exercise the missing-table failure path.');
    echo "public_content_widget_missing_schema_test: PASS\n";
}
