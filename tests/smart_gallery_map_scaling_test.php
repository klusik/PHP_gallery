<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/smart_gallery_map_scaling_test.php
 * Module Type: Disposable database regression
 * Purpose: Exercise Smart Gallery GPS availability and ordered result queries at several sizes.
 * Responsibilities: Compare exact and short GPS reads, keep authorization filters, and report descriptive timings.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply the actual persistence owner with the fixture's private connection.
     *
     * @return \PDO Runner-validated connection with uncommitted test rows.
     */
    function db(): \PDO
    {
        return $GLOBALS['smart_map_scale_db'];
    }
}

namespace {
    require_once __DIR__ . '/support/gallery_workflow_safety.php';
    require_once __DIR__ . '/../app/models/smart_galleries.php';
    require_once __DIR__ . '/../app/services/smart_galleries.php';

    use function Gallery\Models\smart_gallery_model_count_map_images;
    use function Gallery\Models\smart_gallery_model_query_images;
    use function Gallery\Models\smart_gallery_model_source_summary_rows;
    use function GalleryWorkflow\check;
    use function GalleryWorkflow\fixtureDatabase;

    /** Exercise the four requested representative gallery sizes.
     * @var list<int>
     * Units: photos. Scope: one synthetic source at each size.
     * Consumers: this test's seed loop and exact count assertions.
     * Rationale: 120, 500, 2000 and 10000 span one page and multi-page results.
     */
    const SMART_MAP_SCALE_COUNTS = [120, 500, 2000, 10000];

    /** Preserve the measured localhost first-page size.
     * @var int
     * Units: photos. Scope: one test page.
     * Consumers: ordered fixed-page projection probes.
     * Rationale: 120 keeps result materialization comparable as the source grows.
     */
    const SMART_MAP_SCALE_PAGE_SIZE = 120;

    /** Bound fixture insert size independently of total gallery size.
     * @var int
     * Units: rows. Scope: one INSERT statement.
     * Consumers: synthetic seed statements.
     * Rationale: batches of 100 keep native PDO parameters and memory bounded.
     */
    const SMART_MAP_SCALE_INSERT_ROWS = 100;

    /** Sample repeatable query-only timings without a wall-time pass threshold.
     * @var int
     * Units: executions per probe. Scope: one size and SQL shape.
     * Consumers: focused audit evidence.
     * Rationale: five warm observations give an odd-sized median without a tail claim.
     */
    const SMART_MAP_SCALE_SAMPLES = 5;

    /**
     * Populate the disposable transaction with ordered public photo rows.
     *
     * @param PDO $pdo Disposable fixture connection owning the test transaction.
     * @param int $galleryId Fixture's public source gallery identifier.
     * @param int $before Number of already inserted numbered public rows.
     * @param int $target Requested total numbered public rows.
     * @return void Inserts only synthetic rows in the rollback-only transaction.
     */
    function smart_map_scale_insert(PDO $pdo, int $galleryId, int $before, int $target): void
    {
        $columns = '(gallery_id, relative_path, relative_path_hash, filename,'
            . ' visibility, nsfw_enabled, gps_lat, gps_lng, created_at, updated_at)';
        for ($first = $before + 1; $first <= $target; $first += SMART_MAP_SCALE_INSERT_ROWS) {
            $size = min(SMART_MAP_SCALE_INSERT_ROWS, $target - $first + 1);
            // SHA2's 256-bit selector and coordinates are fixture data, not limits.
            $statement = $pdo->prepare(
                'INSERT INTO images ' . $columns . ' VALUES '
                . implode(',', array_fill(
                    0, $size, "(?, ?, SHA2(?, 256), ?, 'public', 0, 50, 14, NOW(), NOW())",
                )),
            );
            $params = [];
            for ($index = $first; $index < $first + $size; $index++) {
                $name = sprintf('scale-%05d.jpg', $index);
                array_push($params, $galleryId, $name, $name, $name);
            }
            $statement->execute($params);
        }
    }

    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    if (!getenv('GALLERY_WORKFLOW_FIXTURE') || !extension_loaded('pdo_mysql')) {
        echo ($required ? 'BLOCKED' : 'SKIP') . " Smart map scaling requires an owned database fixture\n";
        exit($required ? 1 : 0);
    }
    $stage = 'disposable identity';
    $pdo = null;
    $exit = 0;
    try {
        $directory = (string) getenv('GALLERY_WORKFLOW_FIXTURE');
        $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
        $pdo = fixtureDatabase($directory, $token);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $GLOBALS['smart_map_scale_db'] = $pdo;
        $stage = 'uncommitted fixture sources and markers';
        // Use the actual migrated schema and indexes in a rollback-only transaction.
        $pdo->beginTransaction();
        $gallery = $pdo->prepare(
            'INSERT INTO galleries (folder_path, folder_path_hash, slug, title,'
            . ' visibility, access_mode, created_at, updated_at)'
            . " VALUES (?, SHA2(?, 256), ?, ?, 'public', ?, NOW(), NOW())",
        );
        $publicName = 'smart-map-scale-public';
        $gallery->execute([$publicName, $publicName, $publicName, $publicName, 'normal']);
        $root = (int) $pdo->lastInsertId();
        $lockedName = 'smart-map-scale-protected';
        $gallery->execute([$lockedName, $lockedName, $lockedName, $lockedName, 'password']);
        $locked = (int) $pdo->lastInsertId();
        $excluded = $pdo->prepare(
            'INSERT INTO images (gallery_id, relative_path, relative_path_hash,'
            . ' filename, visibility, nsfw_enabled, gps_lat, gps_lng, created_at, updated_at)'
            . ' VALUES (?, ?, SHA2(?, 256), ?, ?, ?, 50, 14, NOW(), NOW())',
        );
        foreach ([
            [$root, 'private-excluded.jpg', 'private', 0],
            [$root, 'nsfw-excluded.jpg', 'public', 1],
            [$locked, 'protected-excluded.jpg', 'public', 0],
        ] as [$source, $name, $visibility, $nsfw]) {
            $excluded->execute([$source, $name, $name, $name, $visibility, $nsfw]);
        }
        $pdo->exec(
            'INSERT INTO tags (name, slug, created_at, updated_at)'
            . " VALUES ('Smart map scaling', 'smart-map-scale-fixture', NOW(), NOW())",
        );
        $tagId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO gallery_tags (gallery_id, tag_id) VALUES (?, ?)')
            ->execute([$root, $tagId]);
        $all = [
            'version' => 1,
            'root' => ['type' => 'condition', 'field' => 'tag', 'operator' => 'has_tag', 'value' => $tagId],
        ];
        $noMatch = [
            'version' => 1,
            'root' => [
                'type' => 'condition', 'field' => 'filename',
                'operator' => 'contains', 'value' => '-excluded',
            ],
        ];
        check(
            smart_gallery_model_count_map_images($noMatch, [$root], true, false) === 0
                && smart_gallery_model_count_map_images($noMatch, [$root], true, false, firstOnly: true) === 0,
            'Private, gated and NSFW GPS rows must not enable the public map.',
        );
        $filter = [
            'version' => 1,
            'root' => [
                'type' => 'condition', 'field' => 'filename',
                'operator' => 'contains', 'value' => 'scale-00001.jpg',
            ],
        ];

        $stage = 'growing canonical result';
        $metrics = [];
        $before = 0;
        foreach (SMART_MAP_SCALE_COUNTS as $count) {
            smart_map_scale_insert($pdo, $root, $before, $count);
            $before = $count;
            $probes = [
                'count' => static fn(): int => smart_gallery_model_count_map_images(
                    $all, [$root], true, false,
                ),
                'first_match' => static fn(): int => smart_gallery_model_count_map_images(
                    $all, [$root], true, false, firstOnly: true,
                ),
                'sources' => static fn(): array => smart_gallery_model_source_summary_rows(
                    $all, [$root], true, false,
                ),
                'page' => static fn(): array => smart_gallery_model_query_images(
                    $all, [$root], true, false, 'filename', 'desc',
                    SMART_MAP_SCALE_PAGE_SIZE, 0,
                ),
                'filter' => static fn(): array => smart_gallery_model_query_images(
                    $filter, [$root], true, false, 'filename', 'asc',
                    SMART_MAP_SCALE_PAGE_SIZE, 0,
                ),
            ];
            // Warm each probe once. Reverse execution order in alternate rounds.
            foreach ($probes as $probe) {
                $probe();
            }
            $observations = [];
            foreach (range(0, SMART_MAP_SCALE_SAMPLES - 1) as $sample) {
                $ordered = $sample % 2 === 0 ? $probes : array_reverse($probes, true);
                foreach ($ordered as $name => $probe) {
                    $started = hrtime(true);
                    $results[$name] = $probe();
                    $observations[$name][] = (hrtime(true) - $started) / 1e6;
                }
            }
            foreach ($observations as $name => $samples) {
                sort($samples, SORT_NUMERIC);
                $metrics["scale_{$count}_{$name}_median_ms"] =
                    $samples[intdiv(count($samples), 2)];
            }
            $tail = smart_gallery_model_query_images(
                $all, [$root], true, false, 'filename', 'desc',
                SMART_MAP_SCALE_PAGE_SIZE, $count - 1,
            );
            $metrics["scale_{$count}_peak_bytes"] = memory_get_peak_usage(true);
            check(
                $results['count'] === $count
                    && $results['first_match'] === 1
                    && count($results['sources']) === 1
                    && (int) $results['sources'][0]['gallery_id'] === $root
                    && (int) $results['sources'][0]['image_count'] === $count
                    && count($results['page']) === SMART_MAP_SCALE_PAGE_SIZE
                    && $results['page'][0]['filename'] === sprintf('scale-%05d.jpg', $count)
                    && count($tail) === 1
                    && $tail[0]['filename'] === 'scale-00001.jpg'
                    && count($results['filter']) === 1
                    && $results['filter'][0]['filename'] === 'scale-00001.jpg',
                'Canonical bounds, tag/filter membership or GPS availability changed.',
            );
        }
        $pdo->rollBack();
        echo 'AUDIT_METRICS ' . json_encode($metrics, JSON_THROW_ON_ERROR) . "\n";
        echo "PASS Smart Gallery map availability, source, filter and page SQL on isolated datasets\n";
    } catch (Throwable $failure) {
        fwrite(
            STDERR,
            'FAIL Smart Gallery scaling at ' . $stage . ' ('
                . basename($failure->getFile()) . ':' . $failure->getLine() . ")\n",
        );
        $exit = 1;
    } finally {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    exit($exit);
}
