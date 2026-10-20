<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/telemetry_storage_compaction_database_test.php
 * Module Type: Database Regression Test
 * Purpose: Exercise populated telemetry upgrade and atomic archival on real engines.
 * Responsibilities:
 *   - Preserve aggregate identity, totals, segments and unknown metric dimensions.
 *   - Verify migration replay, compaction replay and rollback after insert failure.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply the independently validated disposable fixture connection.
     * @return \PDO Connection whose telemetry tables are private temporary copies.
     */
    function db(): \PDO
    {
        return $GLOBALS['telemetry_compact_pdo'];
    }
}

namespace {
    require_once __DIR__ . '/support/gallery_workflow_safety.php';
    require_once dirname(__DIR__) . '/app/models/telemetry.php';
    require_once dirname(__DIR__) . '/app/migrations.php';
    use function GalleryWorkflow\check;
    use function GalleryWorkflow\fixtureDatabase;
    use function Gallery\Models\telemetry_model_rollup_daily;
    use function Gallery\Models\telemetry_model_compact_daily;

    if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
        $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
        echo
            ($required ? 'BLOCKED' : 'SKIP')
                . " telemetry compaction needs disposable database\n";
        exit($required ? 1 : 0);
    }
    $pdo = fixtureDatabase(
        (string) getenv('GALLERY_WORKFLOW_FIXTURE'),
        (string) getenv('GALLERY_WORKFLOW_TOKEN'),
    );
    $GLOBALS['telemetry_compact_pdo'] = $pdo;
    // Shadow real fixture tables on this connection, isolating concurrent shards.
    $original = require
        dirname(__DIR__)
            . '/database/migrations/202605050002_anonymous_telemetry.php';
    foreach ($original as $sql) {
        if (
            preg_match(
                '/^CREATE TABLE IF NOT EXISTS telemetry_(hourly_metrics|daily_metrics|db_query_metrics|settings) /',
                $sql,
            ) === 1
        ) {
            $pdo->exec(str_replace(
                'CREATE TABLE IF NOT EXISTS',
                'CREATE TEMPORARY TABLE',
                $sql,
            ));
        }
    }
    // Same photo/hour/segment with different legacy browser dimensions must merge.
    $insert = $pdo->prepare(
        'INSERT INTO telemetry_hourly_metrics'
            . ' (bucket_start, metric_name, image_id, device_type, browser_family,'
            . ' sample_count, event_count, value_sum, value_min, value_max, updated_at)'
            . " VALUES ('2026-01-01 01:00:00', ?, 42, ?, ?, 1, 2, 3.5, 1, 4, NOW())",
    );
    $insert->execute(['photo.views', 'desktop', 'chrome']);
    $insert->execute(['photo.views', 'desktop', 'firefox']);
    $insert->execute(['photo.views', 'bot', 'chrome']);
    $insert->execute(['future.metric', 'desktop', 'chrome']);
    $insert->execute(['future.metric', 'desktop', 'firefox']);
    $columns = \Gallery\Models\telemetry_model_daily_columns();
    $pdo->exec(
        'INSERT INTO telemetry_daily_metrics ('
            . $columns
            . ') SELECT '
            . str_replace('bucket_date', 'DATE(bucket_start)', $columns)
            . ' FROM telemetry_hourly_metrics',
    );
    $migration = require
        dirname(__DIR__)
            . '/database/migrations/202610190001_telemetry_compact_indexes.php';
    foreach ($migration as $sql) {
        \Gallery\Core\apply_migration_statement($pdo, $sql);
    }
    foreach ($migration as $sql) {
        \Gallery\Core\apply_migration_statement($pdo, $sql);
    }
    check(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM telemetry_hourly_metrics',
        )->fetchColumn() === 5,
        'Populated upgrade and DDL replay must preserve every hourly row.',
    );
    // The retained logical unique key must still reject duplicate aggregate identities.
    $duplicateRejected = false;
    try {
        $insert->execute(['photo.views', 'desktop', 'chrome']);
    } catch (PDOException) {
        $duplicateRejected = true;
    }
    check(
        $duplicateRejected,
        'Compact primary identity must retain logical uniqueness.',
    );
    telemetry_model_compact_daily('2026-01-01');
    check(
        (int) $pdo->query(
            'SELECT COUNT(*) FROM telemetry_daily_metrics',
        )->fetchColumn() === 4
            && (int) $pdo->query(
                'SELECT SUM(event_count) FROM telemetry_daily_metrics',
            )->fetchColumn() === 10,
        'Populated historical daily upgrade merges redundant dimensions without changing totals.',
    );
    telemetry_model_rollup_daily(
        '2026-01-01',
        '2026-01-01',
        '2026-01-02 00:00:00',
    );
    $rows = $pdo->query(
        'SELECT * FROM telemetry_daily_metrics ORDER BY metric_name, device_type, browser_family',
    )->fetchAll(PDO::FETCH_ASSOC);
    check(
        count($rows) === 4,
        'Known browser dimensions merge; bot and future dimensions remain.',
    );
    $desktop = $pdo->query(
        "SELECT * FROM telemetry_daily_metrics WHERE metric_name = 'photo.views' AND device_type = 'desktop'",
    )->fetch(PDO::FETCH_ASSOC);
    check(
        (int) $desktop['event_count'] === 4
            && (int) $desktop['sample_count'] === 2
            && (float) $desktop['value_sum'] === 7.0
            && (float) $desktop['value_min'] === 1.0
            && (float) $desktop['value_max'] === 4.0
            && (int) $desktop['image_id'] === 42,
        'Daily normalization preserves all measurements and photo ranking identity.',
    );
    telemetry_model_compact_daily('2026-01-01');
    telemetry_model_compact_daily('2026-01-01');
    check(
        (int) $pdo->query(
            'SELECT SUM(event_count) FROM telemetry_daily_metrics',
        )->fetchColumn() === 10,
        'Repeated compaction must neither lose nor duplicate counts.',
    );
    // Historical archive has no hourly source and must compact from daily data alone.
    $pdo->exec("UPDATE telemetry_daily_metrics SET bucket_date = '2025-12-01'");
    telemetry_model_compact_daily('2025-12-01');
    check(
        (int) $pdo->query(
            "SELECT SUM(event_count) FROM telemetry_daily_metrics WHERE bucket_date = '2025-12-01'",
        )->fetchColumn() === 10,
        'Expired hourly source cannot erase historical daily totals.',
    );
    telemetry_model_rollup_daily(
        '2026-01-01',
        '2026-01-01',
        '2026-01-02 00:00:00',
    );
    // A real driver failure after DELETE must roll the whole daily replacement back.
    $pdo->exec(
        'ALTER TABLE telemetry_daily_metrics ADD CONSTRAINT telemetry_fixture_event_cap CHECK (event_count <= 4)',
    );
    $pdo->exec(
        "UPDATE telemetry_hourly_metrics SET event_count = 9 WHERE metric_name = 'photo.views'",
    );
    $failed = false;
    try {
        telemetry_model_rollup_daily(
            '2026-01-01',
            '2026-01-01',
            '2026-01-02 00:00:00',
        );
    } catch (PDOException) {
        $failed = true;
    }
    check(
        $failed
            && (int) $pdo->query(
                "SELECT SUM(event_count) FROM telemetry_daily_metrics WHERE bucket_date = '2026-01-01'",
            )->fetchColumn() === 10,
        'Failed archival insert must restore the previous full daily partition.',
    );
    echo "telemetry_storage_compaction_database_test: ok\n";
}
