<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/media_renamer_database_preview_test.php
 * Module Type: Regression Test
 * Purpose: Exercise database-only previews, stable physical batches and journals.
 * Responsibilities:
 *   - Run the real SQL/naming owners without an accessible gallery filesystem.
 *   - Check paging, report ownership/integrity and caller transaction ownership.
 *   - Preserve sequence identity through long names and actual staged batches.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Observe rows returned by the exact production preview SELECT.
     * Purpose: Count actual catalogue rows materialized by each service call.
     */
    final class RenamePreviewStatement extends \PDOStatement
    {
        /** @var int Number of rows returned by the naming query. */
        public static int $previewRows = 0;
        /** @var int Complete catalogue queries made by the tested planner. */
        public static int $previewPasses = 0;

        /** Count rows returned by fetchAll for the scoped naming query.
         * @param int $mode PDO fetch mode.
         * @param class-string|list<scalar|null> ...$arguments Optional PDO
         *     class name or constructor arguments. The fixture uses neither.
         * @return list<array<string,scalar|null>> Associative SQL rows.
         */
        public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$arguments): array
        {
            $rows = parent::fetchAll($mode, ...$arguments);
            if (str_contains($this->queryString, 'FROM images WHERE gallery_id = ? ORDER BY sort_order, filename')) {
                self::$previewPasses++;
                self::$previewRows += count($rows);
            }
            return $rows;
        }
    }

    /** Return the disposable SQLite connection.
     * @return \PDO Fixture-owned database, never the installation connection.
     */
    function db(): \PDO { return $GLOBALS['rename_preview_db']; }
}

namespace Gallery\Services {
    /** Read the fixture gallery through its external catalogue boundary.
     * @param int $id Selected physical gallery ID.
     * @param bool $fresh Whether the caller bypasses request-local caching.
     * @return array{id:int,title:string,folder_path:string}|null Fixture row.
     */
    function find_gallery(int $id, bool $fresh = false): ?array
    {
        $query = \Gallery\Core\db()->prepare('SELECT id,title,folder_path FROM galleries WHERE id=?');
        $query->execute([$id]);
        return $query->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Render English fallback text and substitute its named values.
     * @param string $key Translation identity.
     * @param string $fallback Prepared English text.
     * @param array<string,string> $parameters Values for placeholders in the text.
     * @return string Rendered English fixture text.
     */
    function t(
        string $key,
        string $fallback = '',
        array $parameters = [],
    ): string {
        $replacements = [];
        foreach ($parameters as $name => $value) {
            $replacements['{' . $name . '}'] = $value;
        }
        return strtr($fallback, $replacements);
    }

    /** Refuse gallery IO during analysis; resolve only the owned execution phase.
     * @param string $path Requested gallery-relative path.
     * @return string Owned physical fixture directory after explicit phase setup.
     * @throws \LogicException Any filesystem access during the analysis phase.
     */
    function gallery_abs_path(string $path): string
    {
        if (!isset($GLOBALS['rename_physical_root'])) {
            throw new \LogicException('Database preview touched the gallery filesystem.');
        }
        return $GLOBALS['rename_physical_root'];
    }

    /** Read current rows at the external image catalogue boundary.
     * @param int $id Owned fixture gallery identity.
     * @param bool $includeNested Whether nested paths are retained.
     * @return list<array{id:int,gallery_id:int,relative_path:string,filename:string,title:string,sort_order:int}> Fresh naming rows in production SQL order.
     */
    function gallery_images(int $id, bool $includeNested = true): array
    {
        return array_values(array_filter(\Gallery\Models\media_renamer_model_preview_rows($id),
            static fn(array $row): bool => $includeNested || !str_contains($row['relative_path'], '/')));
    }

    /** Resolve an original within the isolated flat fixture directory.
     * @param array{relative_path:string,...} $image Current fixture image.
     * @param array{folder_path:string,...} $gallery Current fixture gallery.
     * @return string Owned original absolute path.
     */
    function image_abs_path(array $image, array $gallery): string
    {
        return gallery_abs_path($gallery['folder_path']) . '/' . $image['relative_path'];
    }

    /** Constrain the external path boundary to an owned flat directory.
     * @param string $root Exact fixture root.
     * @param string $path Existing source or prospective sibling target.
     * @return bool Whether the path is a nonredirected direct child of the root.
     */
    function thumbnail_path_inside_existing_gallery(string $root, string $path): bool
    {
        return realpath($root) === realpath(dirname($path)) && !is_link($path);
    }

    /** Model the deliberate absence of generated derivatives in this fixture.
     * @return list<int> Empty because only owned original files are created.
     */
    function thumbnail_sizes(): array { return []; }

    /** Reload committed image ownership for the real executor's derivative phase.
     * @param int $imageId Exact image row from the committed rename.
     * @param bool $fresh Whether to bypass request-local identity caching.
     * @return array{id:int,gallery_id:int,relative_path:string,filename:string}|null Persisted identity.
     */
    function find_image(int $imageId, bool $fresh = false): ?array
    {
        $query = \Gallery\Core\db()->prepare(
            'SELECT id,gallery_id,relative_path,filename FROM images WHERE id=?',
        );
        $query->execute([$imageId]);
        return $query->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** Model the absence of generated legacy cache ownership in this fixture.
     * @param array{id:int,gallery_id:int,relative_path:string,filename:string,...} $image Source identity.
     * @return bool False because the fixture has no legacy cache files.
     */
    function thumbnail_legacy_identity_owned(array $image): bool { return false; }

    /** Observe the original source stem in the no-derivative fixture.
     * @param array{filename:string,...} $image Current source identity.
     * @return string Original filename stem.
     */
    function thumbnail_source_identity_invalidation_stem(array $image): string
    {
        return pathinfo($image['filename'], PATHINFO_FILENAME);
    }

    /** Keep the no-derivative fixture's identity cache empty.
     * @return void The fixture has no legacy cache to clear.
     */
    function thumbnail_legacy_identity_cache_clear(): void {}

    /** Describe the minimum catalogue schema used by this fixture.
     * @return bool False because this SQLite catalogue has no public slug columns.
     */
    function public_path_schema_ready(): bool { return false; }

    /** Read current indexed ownership for a potential collision.
     * @param int $galleryId Exact fixture gallery.
     * @param string $relativePath Prospective filename.
     * @return array{id:int}|null Current owner, or null for a free target.
     */
    function find_image_by_path(int $galleryId, string $relativePath): ?array
    {
        $query = \Gallery\Core\db()->prepare('SELECT id FROM images WHERE gallery_id=? AND relative_path=?');
        $query->execute([$galleryId, $relativePath]);
        return $query->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}

namespace {
    require_once __DIR__ . '/../app/policy_constants.php';
    require_once __DIR__ . '/../app/helpers_files.php';
    require_once __DIR__ . '/../app/helpers_runtime.php';
    require_once __DIR__ . '/../app/models/media_renamer.php';
    require_once __DIR__ . '/../app/models/gallery_mutations.php';
    require_once __DIR__ . '/../app/controllers/admin_media_renamer.php';

    /** Allow several bounded housekeeping opportunities under parallel DB load.
     * Type: float. Units: seconds. Scope: fixture completion observation only.
     * Consumers: expired report reclaim assertion. Rationale: 3 seconds admits
     * repeated unchanged 50 ms production slices without enlarging that policy.
     */
    const RENAME_REPORT_RECLAIM_TEST_SECONDS = 3.0;
    /** Yield between separate housekeeping opportunities.
     * Type: int. Units: microseconds. Scope: fixture reclaim observation.
     * Consumers: reclaim retry loop. Rationale: 10000 yields to other database
     * children rather than busy-looping when one production slice is exhausted.
     */
    const RENAME_REPORT_RECLAIM_TEST_POLL_MICROS = 10000;

    /** Reproduce the reported gallery size above collision-suffix capacity.
     * Type: int. Units: original images. Scope: long-context batch regression.
     * Consumers: physical planner/staging loop. Rationale: 922 reproduces the
     * report and spans both candidate-page and physical-batch boundaries.
     */
    const RENAME_LONG_CONTEXT_IMAGE_COUNT = 922;
    /** Compare repeated query work at representative catalogue sizes.
     * Type: list<int>. Units: direct catalogue image rows. Scope: service regression measurement.
     * Consumers: repeated-page baseline and retained candidate-plan worker.
     * Rationale: 100 through 10000 covers small galleries and the reported large-catalogue range.
     */
    const RENAME_CANDIDATE_MEASUREMENT_SIZES = [100, 2000, 5000, 10000];
    /** Keep candidate measurement page width equal to the production cap.
     * Type: int. Units: candidate rows per page. Scope: service regression measurement.
     * Consumers: both repeated baseline and cached candidate pages.
     * Rationale: 500 matches MEDIA_RENAMER_CANDIDATE_PAGE_SIZE.
     */
    const RENAME_CANDIDATE_MEASUREMENT_PAGE_SIZE = 500;

    $check = static function (bool $ok, string $message): void {
        if (!$ok) throw new \RuntimeException($message);
    };
    $refused = static function (callable $operation, string $message) use ($check): void {
        try { $operation(); } catch (\RuntimeException) { return; }
        $check(false, $message);
    };
    foreach ([['collisions' => 69], ['missing' => 1], ['failures' => ['Failed']],
        ['derivative_failures' => 1]] as $unresolved) {
        $check(\Gallery\Controllers\admin_media_renamer_result_log_severity($unresolved) === 'warning',
            'An unresolved rename outcome was logged as an ordinary success.');
    }
    $check(\Gallery\Controllers\admin_media_renamer_result_log_severity(['renamed' => 1]) === 'info',
        'A confirmed rename without unresolved outcomes was marked as a warning.');
    $notice = \Gallery\Controllers\admin_media_renamer_result_notice([
        'already_matches' => 1, 'collisions' => 2, 'skipped' => 3,
    ]);
    $check(
        str_contains($notice, 'Already matching the pattern (1)')
            && str_contains($notice, 'Filename conflicts (2)')
            && str_contains($notice, 'skipped 3 row(s)'),
        'Completion must explain name matches and count conflicts separately.',
    );
    if (!extension_loaded('pdo_sqlite')) {
        throw new \RuntimeException('Required disposable SQLite driver is unavailable.');
    }
    $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_STATEMENT_CLASS => [\Gallery\Core\RenamePreviewStatement::class]]);
    $GLOBALS['rename_preview_db'] = $pdo;
    $GLOBALS['rename_preview_lock_name'] = null;
    $GLOBALS['rename_preview_lock_depth'] = 0;
    $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'media-renamer-fixture', 0);
    $pdo->sqliteCreateFunction('GET_LOCK', static function (string $name, int $seconds): int {
        if ($seconds !== \Gallery\Core\GALLERY_EDIT_LOCK_WAIT_SECONDS
            || ($GLOBALS['rename_preview_lock_name'] !== null
                && $GLOBALS['rename_preview_lock_name'] !== $name)) return 0;
        $GLOBALS['rename_preview_lock_name'] = $name;
        $GLOBALS['rename_preview_lock_depth']++;
        return 1;
    }, 2);
    $pdo->sqliteCreateFunction('RELEASE_LOCK', static function (string $name): int {
        if ($GLOBALS['rename_preview_lock_name'] !== $name
            || $GLOBALS['rename_preview_lock_depth'] <= 0) return 0;
        $GLOBALS['rename_preview_lock_depth']--;
        if ($GLOBALS['rename_preview_lock_depth'] === 0) $GLOBALS['rename_preview_lock_name'] = null;
        return 1;
    }, 1);
    $pdo->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY,title TEXT,folder_path TEXT,
        folder_path_hash TEXT,parent_id INTEGER,updated_at TEXT,edit_revision INTEGER)');
    // Deliberately omit EXIF/payload columns: analysis must select only naming facts.
    $pdo->exec('CREATE TABLE images (id INTEGER PRIMARY KEY,gallery_id INTEGER,
        relative_path TEXT,filename TEXT,title TEXT,sort_order INTEGER)');
    $pdo->exec('CREATE TABLE zip_archives (id INTEGER PRIMARY KEY,scope TEXT,
        gallery_id INTEGER,file_path TEXT)');
    $pdo->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY,
        setting_value TEXT NOT NULL,updated_at TEXT)');
    $pdo->exec("INSERT INTO galleries VALUES (7,'Journeys','collections/journeys','old',NULL,'before',9)");
    $insert = $pdo->prepare('INSERT INTO images VALUES (?,7,?,?,?,?)');
    // 601 crosses both the 50-name detail page and 500-ID apply page boundaries.
    foreach (range(1, 601) as $id) {
        $filename = 'camera_' . $id . '.jpg';
        $insert->execute([$id, $filename, $filename, 'Photo ' . $id, $id]);
    }
    $insert->execute([900, 'nested/kept.jpg', 'kept.jpg', 'Nested', 900]);
    $pdo->exec('ALTER TABLE images ADD COLUMN relative_path_hash TEXT');
    $pdo->exec('ALTER TABLE images ADD COLUMN updated_at TEXT');

    // Copy the complete owner and its declared parts, preserving production
    // relative includes while confining real report writes to an owned root.
    $owned = sys_get_temp_dir() . '/gallery-rename-preview-' . bin2hex(random_bytes(8));
    $paths = ['app/services/media_renamer.php',
        'app/services/media_renamer/preview.php', 'app/services/media_renamer/reports.php',
        'app/services/media_renamer/candidate_plan.php'];
    try {
        foreach ($paths as $relative) {
            $target = $owned . '/' . $relative;
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
            $check(copy(dirname(__DIR__) . '/' . $relative, $target), 'Owner copy failed.');
        }
        // Forward the external dependency to its canonical real owner instead
        // of defining a second copy of its process-wide policy declarations.
        file_put_contents($owned . '/app/services/gallery_edit_concurrency.php',
            "<?php\nrequire_once " . var_export(dirname(__DIR__)
                . '/app/services/gallery_edit_concurrency.php', true) . ";\n");
        require $owned . '/app/services/media_renamer.php';
        $summary = \Gallery\Services\media_renamer_database_preview(7);
        $check($summary['summary']['total'] === 601 && $summary['summary']['rename'] === 601,
            'Preview must analyze direct DB rows without visiting nonexistent originals.');
        $check($summary['items'] === [] && $summary['candidate_image_ids'] === []
            && $summary['selected_image_ids'] === [], 'Initial analysis materialized filenames/IDs.');
        $check(!is_dir($owned . '/cache'), 'Read-only analysis created disk state.');
        $page = \Gallery\Services\media_renamer_database_preview(7, '', 0, 50);
        $check(count($page['items']) === 50 && $page['next_offset'] === 50
            && $page['items'][0]['new_relative_path'] === 'collections_journeys_0001.jpg'
            && $page['items'][49]['image_id'] === 50, 'First detail page changed canonical names/order.');
        $last = \Gallery\Services\media_renamer_database_preview(7, '', 600, 50);
        $check(count($last['items']) === 1 && $last['next_offset'] === null, 'Detail paging leaked nested rows.');
        $ids = \Gallery\Services\media_renamer_database_preview(7, '', 0, 500, true);
        $tail = \Gallery\Services\media_renamer_database_preview(7, '', 500, 500, true);
        $check(count($ids['candidate_image_ids']) === 500 && $ids['next_offset'] === 500
            && count($tail['candidate_image_ids']) === 101 && $tail['next_offset'] === null
            && $ids['items'] === [], 'Apply preparation must retain bounded IDs only.');
        $check($ids['candidate_sequences'] === range(1, 500)
            && $tail['candidate_sequences'] === range(501, 601),
            'Apply positions must preserve original numbering across pages.');

        // Compare identical catalogue/pattern inputs. The initial compact
        // overview is measured separately; only the old repeated apply pages
        // and new one-time snapshot plus cached pages contribute to this matrix.
        $measurementInsert = $pdo->prepare('INSERT INTO images
            (id,gallery_id,relative_path,filename,title,sort_order) VALUES (?,77,?,?,?,?)');
        $measurementUpdate = $pdo->prepare('DELETE FROM images WHERE gallery_id=77');
        $measurement = [];
        foreach (RENAME_CANDIDATE_MEASUREMENT_SIZES as $size) {
            $measurementUpdate->execute();
            $pdo->prepare('DELETE FROM galleries WHERE id=77')->execute();
            $pdo->prepare('INSERT INTO galleries VALUES (77,\'Measurement\',\'measurement\',\'hash\',NULL,\'before\',1)')->execute();
            for ($imageId = 1; $imageId <= $size; $imageId++) {
                $filename = 'source_' . $imageId . '.jpg';
                $measurementInsert->execute([
                    100000 + $imageId, $filename, $filename, 'Photo ' . $imageId, $imageId,
                ]);
            }
            $overviewCount = \Gallery\Core\RenamePreviewStatement::$previewRows;
            $overviewPasses = \Gallery\Core\RenamePreviewStatement::$previewPasses;
            $overviewStart = hrtime(true);
            $overview = \Gallery\Services\media_renamer_database_preview(77);
            $overviewMs = (hrtime(true) - $overviewStart) / 1_000_000;
            $overviewRows = \Gallery\Core\RenamePreviewStatement::$previewRows - $overviewCount;
            $overviewPasses = \Gallery\Core\RenamePreviewStatement::$previewPasses - $overviewPasses;
            $check($overview['summary']['total'] === $size && $overview['items'] === []
                && $overview['candidate_image_ids'] === [] && $overviewPasses === 1,
                'Compact overview changed its no-materialized-ID contract.');

            \Gallery\Core\RenamePreviewStatement::$previewRows = 0;
            \Gallery\Core\RenamePreviewStatement::$previewPasses = 0;
            $oldStart = hrtime(true);
            $oldIds = [];
            $oldSequences = [];
            for ($offset = 0; $offset < $size; $offset += RENAME_CANDIDATE_MEASUREMENT_PAGE_SIZE) {
                $oldPage = \Gallery\Services\media_renamer_database_preview(
                    77, '', $offset, RENAME_CANDIDATE_MEASUREMENT_PAGE_SIZE, true,
                );
                array_push($oldIds, ...$oldPage['candidate_image_ids']);
                array_push($oldSequences, ...$oldPage['candidate_sequences']);
            }
            $oldElapsedMs = (hrtime(true) - $oldStart) / 1_000_000;
            $oldSourceRows = \Gallery\Core\RenamePreviewStatement::$previewRows;
            $oldPasses = \Gallery\Core\RenamePreviewStatement::$previewPasses;

            \Gallery\Core\RenamePreviewStatement::$previewRows = 0;
            \Gallery\Core\RenamePreviewStatement::$previewPasses = 0;
            $newStart = hrtime(true);
            $owner = hash('sha256', 'renamer-measurement-owner');
            $measurementPattern = \Gallery\Services\media_renamer_normalize_pattern('');
            $newPage = \Gallery\Services\media_renamer_confirmed_candidate_page(
                $owner, '', 77, $measurementPattern, 0,
            );
            $firstPagePasses = \Gallery\Core\RenamePreviewStatement::$previewPasses;
            $firstPageRows = \Gallery\Core\RenamePreviewStatement::$previewRows;
            $newIds = $newPage['candidate_image_ids'];
            $newSequences = $newPage['candidate_sequences'];
            $token = $newPage['plan_token'];
            while ($newPage['next_offset'] !== null) {
                $newPage = \Gallery\Services\media_renamer_confirmed_candidate_page(
                    $owner, $token, 77, $measurementPattern, $newPage['next_offset'],
                );
                array_push($newIds, ...$newPage['candidate_image_ids']);
                array_push($newSequences, ...$newPage['candidate_sequences']);
            }
            $newElapsedMs = (hrtime(true) - $newStart) / 1_000_000;
            $newSourceRows = \Gallery\Core\RenamePreviewStatement::$previewRows;
            $newPasses = \Gallery\Core\RenamePreviewStatement::$previewPasses;
            $check($oldIds === $newIds && $oldSequences === $newSequences
                && count($newIds) === $size && $newSequences === range(1, $size),
                'Cached candidate pages changed IDs or original sequence positions.');
            $check($oldPasses === (int) ceil($size / RENAME_CANDIDATE_MEASUREMENT_PAGE_SIZE)
                && $oldSourceRows === $size * $oldPasses && $firstPagePasses === 1,
                'Repeated baseline/new one-pass SQL row counts differ from the contract.');
            $check($firstPageRows === $size && $newSourceRows === $firstPageRows
                && $newPasses === $firstPagePasses,
                'Reading cached candidate pages queried the full catalogue.');
            $measurement[] = [
                'catalogue_rows' => $size,
                'overview' => ['passes' => $overviewPasses, 'sql_rows' => $overviewRows,
                    'elapsed_ms' => round($overviewMs, 3)],
                'old_repeated_pages' => [
                    'passes' => $oldPasses,
                    'source_rows_inspected' => $oldSourceRows,
                    'elapsed_ms' => round($oldElapsedMs, 3),
                ],
                'new_snapshot_plus_pages' => [
                    'passes' => $newPasses,
                    'source_rows_inspected' => $newSourceRows,
                    'elapsed_ms' => round($newElapsedMs, 3),
                    'cached_page_source_rows' => $newSourceRows - $firstPageRows,
                ],
            ];
        }
        echo 'candidate-page-measurement ' . json_encode($measurement, JSON_THROW_ON_ERROR) . "\n";

        $pdo->exec("UPDATE images SET relative_path='collections_journeys_0001.jpg',
            filename='collections_journeys_0001.jpg' WHERE id=1");
        $noOp = \Gallery\Services\media_renamer_database_preview(7, '', 0, 50);
        $check($noOp['summary']['already_matches'] === 1 && $noOp['items'][0]['image_id'] === 2,
            'Already matching names should count without expanding the detail list.');

        // Actual original files exist only during execution. Initial and detail
        // analysis above must still fail if they inspect the gallery filesystem.
        $physical = $owned . '/originals';
        mkdir($physical);
        $GLOBALS['rename_physical_root'] = $physical;
        // Exercise the confirmed services with the real writer/revision model,
        // an SQLite lock adapter and the real physical executor.
        $galleryInsert = $pdo->prepare('INSERT INTO galleries VALUES (?,?,?,?,NULL,?,1)');
        $galleryInsert->execute([10, 'Series', 'collections/series', 'series', 'before']);
        $serviceInsert = $pdo->prepare('INSERT INTO images
            (id,gallery_id,relative_path,filename,title,sort_order) VALUES (?,10,?,?,?,?)');
        $serviceIds = [];
        foreach (range(1, 30) as $position) {
            $imageId = 30000 + $position;
            $filename = 'source_' . $position . '.jpg';
            $serviceInsert->execute([$imageId, $filename, $filename, 'Series ' . $position, $position]);
            file_put_contents($physical . '/' . $filename, 'series original ' . $imageId);
            $serviceIds[] = $imageId;
        }
        $serviceOwner = hash('sha256', 'admin/session-renamer-service');
        $servicePattern = '{gallery_context}_{seq4}';
        $serviceGallery = \Gallery\Services\find_gallery(10, true);
        $gapSourceId = $serviceIds[1];
        $gapSource = $pdo->prepare('SELECT id,gallery_id,relative_path,filename,title,sort_order '
            . 'FROM images WHERE id=?');
        $gapSource->execute([$gapSourceId]);
        $gapImage = $gapSource->fetch(\PDO::FETCH_ASSOC);
        $gapName = \Gallery\Services\media_renamer_build_filename(
            \Gallery\Services\media_renamer_gallery_context_base($serviceGallery),
            2, 'jpg', 0, \Gallery\Services\media_renamer_normalize_pattern($servicePattern),
            $serviceGallery, $gapImage,
        );
        rename($physical . '/' . $gapImage['relative_path'], $physical . '/' . $gapName);
        $pdo->prepare('UPDATE images SET relative_path=?,filename=? WHERE id=?')
            ->execute([$gapName, $gapName, $gapSourceId]);
        $confirmed = \Gallery\Services\media_renamer_confirmed_candidate_page(
            $serviceOwner, '', 10, $servicePattern, 0,
        );
        $serviceCandidateIds = array_values(array_diff($serviceIds, [$gapSourceId]));
        $serviceSequences = array_merge([1], range(3, 30));
        $check($confirmed['candidate_image_ids'] === $serviceCandidateIds
            && $confirmed['candidate_sequences'] === $serviceSequences
            && count(array_unique($confirmed['candidate_image_ids'])) === count($serviceCandidateIds)
            && $confirmed['applied_offset'] === 0,
            'Confirmation must freeze exact candidate IDs and original positions.');
        $refused(static fn(): array => \Gallery\Services\media_renamer_confirmed_candidate_page(
            hash('sha256', 'admin/session-other'), $confirmed['plan_token'],
            10, $servicePattern, 0,
        ), 'A confirmation token was readable by another login session.');
        $refused(static fn(): array => \Gallery\Services\media_renamer_confirmed_candidate_page(
            $serviceOwner, $confirmed['plan_token'], 10, '{gallery_title}_{seq4}', 0,
        ), 'A confirmation token accepted a changed filename pattern.');
        $refused(static fn(): array => \Gallery\Services\media_renamer_confirmed_candidate_page(
            $serviceOwner, $confirmed['plan_token'], 7, $servicePattern, 0,
        ), 'A confirmation token accepted a different gallery identity.');
        $firstSource = $pdo->query('SELECT id,gallery_id,relative_path,filename,title,sort_order '
            . 'FROM images WHERE id=' . $serviceIds[0])->fetch(\PDO::FETCH_ASSOC);
        $serviceContext = \Gallery\Services\media_renamer_gallery_context_base($serviceGallery);
        $occupiedTarget = \Gallery\Services\media_renamer_build_filename(
            $serviceContext, 1, 'jpg', 0,
            \Gallery\Services\media_renamer_normalize_pattern($servicePattern),
            $serviceGallery, $firstSource,
        );
        file_put_contents($physical . '/' . $occupiedTarget, 'arrived after confirmation');
        $firstBatch = array_slice($confirmed['candidate_image_ids'], 0,
            \Gallery\Core\MEDIA_RENAMER_PHYSICAL_BATCH_SIZE);
        $physicalRows = \Gallery\Core\RenamePreviewStatement::$previewRows;
        $physicalPasses = \Gallery\Core\RenamePreviewStatement::$previewPasses;
        $firstBatchStart = hrtime(true);
        $firstResult = \Gallery\Services\media_renamer_execute_candidate_batch(
            $serviceOwner, $confirmed['plan_token'], 10, $servicePattern, 0, $firstBatch,
        );
        $firstBatchNanos = hrtime(true) - $firstBatchStart;
        $check($firstResult['renamed'] === count($firstBatch)
            && $firstResult['failures'] === [], 'First confirmed physical batch did not complete.');
        foreach ($firstBatch as $imageId) {
            $sourceRow = $pdo->prepare('SELECT relative_path FROM images WHERE id=?');
            $sourceRow->execute([$imageId]);
            $newName = (string) $sourceRow->fetchColumn();
            $check(file_get_contents($physical . '/' . $newName) === 'series original ' . $imageId,
                'The real executor changed bytes or lost original ownership.');
            if ($imageId === $serviceIds[0]) {
                $check($newName !== $occupiedTarget
                    && file_get_contents($physical . '/' . $occupiedTarget) === 'arrived after confirmation',
                    'The executor overwrote a target that appeared after confirmation.');
            }
        }
        unlink($physical . '/' . $occupiedTarget);
        $secondPage = \Gallery\Services\media_renamer_confirmed_candidate_page(
            $serviceOwner, $confirmed['plan_token'], 10, $servicePattern,
            \Gallery\Core\MEDIA_RENAMER_PHYSICAL_BATCH_SIZE,
        );
        $check($secondPage['applied_offset'] === count($firstBatch),
            'Confirmed cursor did not advance by the completed physical batch.');
        $secondBatch = array_slice($secondPage['candidate_image_ids'], 0,
            \Gallery\Core\MEDIA_RENAMER_PHYSICAL_BATCH_SIZE);
        $secondBatchStart = hrtime(true);
        $secondResult = \Gallery\Services\media_renamer_execute_candidate_batch(
            $serviceOwner, $confirmed['plan_token'], 10, $servicePattern,
            count($firstBatch), $secondBatch,
        );
        $physicalNanos = $firstBatchNanos + hrtime(true) - $secondBatchStart;
        $check($secondResult['renamed'] === count($secondBatch)
            && $secondResult['failures'] === [], 'Second confirmed physical batch did not complete.');
        $refused(static fn(): array => \Gallery\Services\media_renamer_execute_candidate_batch(
            $serviceOwner, $confirmed['plan_token'], 10, $servicePattern, 0, $firstBatch,
        ), 'A previously confirmed physical batch was replayed.');
        $physicalMeasurement = [
            'confirmed_batches' => [count($firstBatch), count($secondBatch)],
            'safety_planning_passes' => \Gallery\Core\RenamePreviewStatement::$previewPasses - $physicalPasses,
            'naming_rows_materialized' => \Gallery\Core\RenamePreviewStatement::$previewRows - $physicalRows,
            'elapsed_ms' => round($physicalNanos / 1_000_000, 3),
        ];
        $reanalysis = \Gallery\Services\media_renamer_database_preview(10, $servicePattern);
        $check($reanalysis['summary']['already_matches'] === count($serviceIds)
            && $reanalysis['summary']['rename'] === 0,
            'Re-analysis should recognize all originals after both physical batches.');

        $stalePlan = \Gallery\Services\media_renamer_confirmed_candidate_page(
            $serviceOwner, '', 7, $servicePattern, 0,
        );
        $check($stalePlan['next_offset'] === RENAME_CANDIDATE_MEASUREMENT_PAGE_SIZE,
            'Stale-plan fixture must span candidate pages.');
        $refused(static fn(): array => \Gallery\Services\media_renamer_confirmed_candidate_page(
            $serviceOwner, $stalePlan['plan_token'], 7, '{gallery_title}_{seq4}',
            $stalePlan['next_offset'],
        ), 'Changing the pattern between pages continued an obsolete plan.');
        $writer = \Gallery\Services\gallery_edit_writer_begin();
        try {
            $pdo->exec('UPDATE images SET sort_order = 0 WHERE id = 601');
        } finally {
            \Gallery\Services\gallery_edit_writer_end($writer);
        }
        $refused(static fn(): array => \Gallery\Services\media_renamer_confirmed_candidate_page(
            $serviceOwner, $stalePlan['plan_token'], 7, $servicePattern, $stalePlan['next_offset'],
        ), 'A cooperating catalogue writer did not stale an outstanding plan.');
        $expiry = \Gallery\Services\media_renamer_confirmed_candidate_page(
            $serviceOwner, '', 7, $servicePattern, 0,
        );
        $manifestPath = $owned . '/cache/media-renamer-candidates/'
            . $expiry['plan_token'] . '/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 16, JSON_THROW_ON_ERROR);
        $manifest['created_at'] = time() - \Gallery\Services\MEDIA_RENAMER_CANDIDATE_PLAN_TTL;
        file_put_contents($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR));
        $refused(static fn(): array => \Gallery\Services\media_renamer_confirmed_candidate_page(
            $serviceOwner, $expiry['plan_token'], 7, $servicePattern, $expiry['next_offset'],
        ), 'An expired plan remained readable.');

        // Keep the title below the real 255-character limit; the parent path
        // supplies the remaining context which made the reported name too long.
        $longTitle = str_repeat('Distant worlds and long chapter names ', 6);
        $galleryInsert->execute([8, $longTitle,
            'games/' . str_repeat('deep-space-route/', 8) . 'chapter', 'long', 'before']);
        $longGallery = \Gallery\Services\find_gallery(8);
        $context = \Gallery\Services\media_renamer_gallery_context_base($longGallery);
        // Token position is structural: numeric identities must survive at
        // either edge and between repeated descriptive placeholders.
        foreach (['{gallery_context}_{seq4}', '{seq4}_{gallery_context}',
            '{gallery_title}_{seq4}_{gallery_context}', '{gallery_context}_{image_id}'] as $pattern) {
            $targets = [];
            foreach ([1, RENAME_LONG_CONTEXT_IMAGE_COUNT] as $position) {
                $target = \Gallery\Services\media_renamer_build_filename($context, $position,
                    'png', 0, $pattern, $longGallery, ['id' => $position]);
                $identity = str_contains($pattern, '{seq4}')
                    ? str_pad((string) $position, 4, '0', STR_PAD_LEFT) : (string) $position;
                $check(in_array($identity, explode('_', pathinfo($target, PATHINFO_FILENAME)), true)
                    && strlen($target) <= \Gallery\Services\MEDIA_RENAMER_FILENAME_MAX_BYTES,
                    'Long descriptive expansion removed a numeric token or exceeded the byte budget.');
                $targets[] = $target;
            }
            $check($targets[0] !== $targets[1], 'Distinct identities collapsed into one long filename.');
        }
        $longInsert = $pdo->prepare('INSERT INTO images
            (id,gallery_id,relative_path,filename,title,sort_order) VALUES (?,8,?,?,?,0)');
        foreach (range(1, RENAME_LONG_CONTEXT_IMAGE_COUNT) as $position) {
            $name = 'a_' . str_pad((string) $position, 4, '0', STR_PAD_LEFT) . '.png';
            // This reserved synthetic ID range cannot overlap gallery 7's rows.
            $id = 10000 + $position;
            $longInsert->execute([$id, $name, $name, 'Original ' . $position]);
            file_put_contents($physical . '/' . $name, 'owned original ' . $id);
        }
        $frozen = [];
        $offset = 0;
        do {
            $candidates = \Gallery\Services\media_renamer_database_preview(8, '', $offset,
                \Gallery\Services\MEDIA_RENAMER_CANDIDATE_PAGE_SIZE, true);
            $frozen += array_combine($candidates['candidate_image_ids'], $candidates['candidate_sequences']);
            $offset = $candidates['next_offset'];
        } while ($offset !== null);
        $check(count($frozen) === RENAME_LONG_CONTEXT_IMAGE_COUNT,
            'Long context collapsed distinct sequence candidates to collision suffixes.');
        $renameUpdate = $pdo->prepare('UPDATE images SET relative_path=?,filename=? WHERE id=?');
        foreach (array_chunk($frozen, \Gallery\Core\MEDIA_RENAMER_PHYSICAL_BATCH_SIZE, true) as $positions) {
            $plan = \Gallery\Services\media_renamer_plan_for_gallery_selection(8, array_keys($positions), '', $positions);
            $fresh = \Gallery\Services\media_renamer_refresh_execution_plan($plan);
            $check($fresh['items'] === $plan['items'], 'Safety refresh discarded frozen positions.');
            $check(count($plan['items']) === count($positions) && $plan['summary']['collision'] === 0,
                'A later physical batch drifted into filenames produced by an earlier batch.');
            $staged = [];
            foreach ($plan['items'] as $item) {
                $suffix = '_' . str_pad((string) $positions[$item['image_id']], 4, '0', STR_PAD_LEFT) . '.png';
                $check($item['status'] === 'rename' && str_ends_with($item['new_filename'], $suffix)
                    && strlen($item['new_filename']) <= \Gallery\Services\MEDIA_RENAMER_FILENAME_MAX_BYTES,
                    'Long-name fitting discarded sequence digits or exceeded its byte budget.');
                $source = $physical . '/' . $item['old_filename'];
                $temporary = \Gallery\Services\media_renamer_staging_path($source);
                $check(rename($source, $temporary), 'Owned original could not be staged.');
                $staged[] = [$item, $temporary];
            }
            foreach ($staged as [$item, $temporary]) {
                $target = $physical . '/' . $item['new_filename'];
                $check(rename($temporary, $target), 'Staged original could not be published.');
                $check(file_get_contents($target) === 'owned original ' . $item['image_id'],
                    'Batch rename changed original bytes or image ownership.');
                $renameUpdate->execute([$item['new_filename'], $item['new_filename'], $item['image_id']]);
            }
        }
        $settled = \Gallery\Services\media_renamer_database_preview(8);
        $check($settled['summary']['already_matches'] === RENAME_LONG_CONTEXT_IMAGE_COUNT
            && $settled['summary']['rename'] === 0 && $settled['summary']['collision'] === 0,
            'A repeated long-context run did not settle to an idempotent no-op.');
        $settledBatch = \Gallery\Services\media_renamer_plan_for_gallery_selection(
            8, array_keys($positions), '', $positions,
        );
        $check($settledBatch['summary']['total'] === count($positions)
            && $settledBatch['summary']['already_matches'] === count($positions)
            && $settledBatch['summary']['rename'] === 0
            && $settledBatch['items'] === [],
            'Confirmed matching names must count in a physical batch without detail rows.');

        // Reproduce an upload whose preferred name is occupied on disk only.
        $numberedGalleryId = 9;
        $numberedImageId = 901;
        $galleryInsert->execute([
            $numberedGalleryId, 'Night Flight', 'collections/night-flight', 'night', 'before',
        ]);
        $originalName = 'camera-flight.png';
        $preferredName = 'collections_night_flight_0001.png';
        $numberedName = 'collections_night_flight_0001_2.png';
        $createImage = $pdo->prepare('INSERT INTO images
            (id,gallery_id,relative_path,filename,title,sort_order) VALUES (?, ?, ?, ?, ?, ?)');
        $createImage->execute([
            $numberedImageId, $numberedGalleryId, $originalName, $originalName, 'Night', 1,
        ]);
        file_put_contents($physical . '/' . $originalName, 'uploaded original');
        file_put_contents($physical . '/' . $preferredName, 'unindexed occupant');
        $uploadPlan = \Gallery\Services\media_renamer_plan_for_gallery_selection(
            $numberedGalleryId, [$numberedImageId],
        );
        $check($uploadPlan['summary']['rename'] === 1
            && $uploadPlan['items'][0]['new_filename'] === $numberedName,
            'An unindexed occupied target must allocate the first collision suffix.');
        $check(rename($physical . '/' . $originalName, $physical . '/' . $numberedName),
            'Could not complete the synthetic upload rename.');
        $renameUpdate->execute([$numberedName, $numberedName, $numberedImageId]);

        // The preview and availability check have no filesystem access.
        unset($GLOBALS['rename_physical_root']);
        $numberedPreview = \Gallery\Services\media_renamer_database_preview(
            $numberedGalleryId, '', 0, 1,
        );
        $numberedCandidates = \Gallery\Services\media_renamer_database_preview(
            $numberedGalleryId, '', 0, 1, true,
        );
        $check($numberedPreview['summary']['already_matches'] === 1
            && $numberedPreview['summary']['rename'] === 0
            && $numberedPreview['summary']['warnings'] === 0
            && $numberedPreview['items'] === []
            && $numberedCandidates['candidate_image_ids'] === []
            && \Gallery\Services\media_renamer_db_availability_for_gallery($numberedGalleryId)
                === ['rename_count' => 0, 'warning_count' => 0],
            'The preview and apply list must recognize the server-generated suffix.');
        $differentPattern = \Gallery\Services\media_renamer_database_preview(
            $numberedGalleryId, '{gallery_title}_{seq4}',
        );
        $check($differentPattern['summary']['rename'] === 1,
            'A new pattern must still offer a real name change.');

        $GLOBALS['rename_physical_root'] = $physical;
        $check(file_get_contents($physical . '/' . $preferredName) === 'unindexed occupant'
            && unlink($physical . '/' . $preferredName),
            'The synthetic upload rename touched a file it did not own.');
        $numberedRepeat = \Gallery\Services\media_renamer_plan_for_gallery(
            $numberedGalleryId,
        );
        $check($numberedRepeat['summary']['already_matches'] === 1
            && $numberedRepeat['summary']['rename'] === 0
            && $numberedRepeat['items'] === []
            && file_get_contents($physical . '/' . $numberedName) === 'uploaded original',
            'The numbered name must stay settled when the earlier blocker disappears.');
        $differentPosition = \Gallery\Services\media_renamer_plan_for_gallery_selection(
            $numberedGalleryId, [$numberedImageId], '', [$numberedImageId => 2],
        );
        $check($differentPosition['summary']['rename'] === 1
            && $differentPosition['items'][0]['new_filename'] === 'collections_night_flight_0002.png',
            'Filename matching must compare the original position as well as the suffix.');
        unset($GLOBALS['rename_physical_root']);

        $owner = hash('sha256', 'admin/session-A');
        $token = '';
        foreach (array_chunk(range(1, 61), 15) as $chunk) {
            $details = array_map(static fn(int $id): array => ['gallery_id' => 7,
                'gallery' => 'Journeys', 'image_id' => $id, 'old' => 'old_' . $id . '.jpg',
                'new' => 'new_' . $id . '.jpg', 'status' => 'renamed', 'notes' => []], $chunk);
            $token = \Gallery\Services\media_renamer_report_append($owner, $token, $details);
        }
        $record = \Gallery\Services\media_renamer_report_load($token, $owner);
        $check($record['galleries'][7]['renamed'] === 61, 'Report overview lost confirmed batch counters.');
        $check(!str_contains(json_encode($record), 'old_'), 'Overview must not contain filenames.');
        $report = \Gallery\Services\media_renamer_report_page($owner, $token, 7, 0);
        $check(count($report['rows']) === 50 && $report['next_offset'] === 50
            && $report['rows'][49]['image_id'] === 50, 'Report page crossed its bound/order.');
        $refused(static fn(): array => \Gallery\Services\media_renamer_report_load($token,
            hash('sha256', 'admin/session-B')), 'Another login session read the report.');
        $directory = $owned . '/cache/media-renamer-reports/' . $token;
        $firstChunk = $directory . '/' . $record['galleries'][7]['pages'][0]['key'] . '.json';
        $firstBytes = file_get_contents($firstChunk);
        file_put_contents($firstChunk, 'corrupt');
        $tail = \Gallery\Services\media_renamer_report_page($owner, $token, 7, 50);
        $check(count($tail['rows']) === 11 && $tail['next_offset'] === null,
            'A later page inspected an unrelated earlier detail chunk.');
        $refused(static fn(): array => \Gallery\Services\media_renamer_report_page($owner, $token, 7, 0),
            'Corrupt report detail bytes were accepted.');
        file_put_contents($firstChunk, $firstBytes);
        $record['created_at'] = time() - \Gallery\Services\MEDIA_RENAMER_REPORT_TTL;
        file_put_contents($directory . '/manifest.json', json_encode($record));
        $refused(static fn(): array => \Gallery\Services\media_renamer_report_load($token, $owner),
            'Expired report remained readable.');
        // A production slice is an admission budget, not a one-call completion
        // promise. Retain the exact limit and verify completion across new
        // opportunities, including real stat-cache refresh after directory IO.
        $unknown = $owned . '/cache/media-renamer-reports/unrelated.txt';
        file_put_contents($unknown, 'unowned by the report collector');
        $deadline = microtime(true) + RENAME_REPORT_RECLAIM_TEST_SECONDS;
        do {
            \Gallery\Services\media_renamer_report_prune();
            clearstatcache(true, $directory);
            if (!is_dir($directory)) break;
            usleep(RENAME_REPORT_RECLAIM_TEST_POLL_MICROS);
        } while (microtime(true) < $deadline);
        $check(!is_dir($directory), 'Producer-proven expired report was not reclaimed.');
        $check(file_get_contents($unknown) === 'unowned by the report collector',
            'Housekeeping removed unrelated storage.');

        $update = [['id' => 7, 'folder_path' => 'renamed',
            'folder_path_hash' => hash('sha256', 'renamed'), 'parent_id' => null, 'title' => 'Renamed']];
        $pdo->beginTransaction();
        \Gallery\Models\gallery_mutation_model_update_gallery_paths($update, 'now');
        $check($pdo->inTransaction(), 'Path model committed the caller-owned grant transaction.');
        $pdo->rollBack();
        $check($pdo->query('SELECT title FROM galleries WHERE id=7')->fetchColumn() === 'Journeys',
            'Caller rollback did not restore the complete gallery path/title map.');
        \Gallery\Models\gallery_mutation_model_update_gallery_paths($update, 'now');
        $check(!$pdo->inTransaction()
            && $pdo->query('SELECT edit_revision FROM galleries WHERE id=7')->fetchColumn() == 10,
            'Standalone path model must own one commit and one revision increment.');
        if (getenv('GITHUB_ACTIONS') === 'true') {
            // The central audit intentionally omits stdout from successful PHP
            // children. Publish compact timings in its existing artifact root.
            $evidence = json_encode([
                'candidate_sha' => getenv('PHP_GALLERY_AUDIT_CANDIDATE'),
                'source_base' => getenv('PHP_GALLERY_SOURCE_BASE'),
                'php' => PHP_VERSION,
                'sqlite' => $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),
                'candidate_paging' => $measurement,
                'physical_batches' => $physicalMeasurement,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
            $evidencePath = dirname(__DIR__) . '/cache/test-audit/media-renamer-measurement-'
                . getmypid() . '.json';
            $check(file_put_contents($evidencePath, $evidence) === strlen($evidence),
                'Hosted rename measurement evidence could not be persisted.');
        }
        echo "database-only media rename preview and lazy report tests passed\n";
    } finally {
        if (is_dir($owned)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $owned, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $entry) {
                $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($owned);
        }
    }
}
