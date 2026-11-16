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

    /** Preserve English fallback text at the localization boundary.
     * @param string $key Translation identity.
     * @param string $fallback Prepared English text.
     * @return string English fixture text.
     */
    function t(string $key, string $fallback = ''): string { return $fallback; }

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
    if (!extension_loaded('pdo_sqlite')) {
        throw new \RuntimeException('Required disposable SQLite driver is unavailable.');
    }
    $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    $GLOBALS['rename_preview_db'] = $pdo;
    $pdo->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY,title TEXT,folder_path TEXT,
        folder_path_hash TEXT,parent_id INTEGER,updated_at TEXT,edit_revision INTEGER)');
    // Deliberately omit EXIF/payload columns: analysis must select only naming facts.
    $pdo->exec('CREATE TABLE images (id INTEGER PRIMARY KEY,gallery_id INTEGER,
        relative_path TEXT,filename TEXT,title TEXT,sort_order INTEGER)');
    $pdo->exec("INSERT INTO galleries VALUES (7,'Journeys','collections/journeys','old',NULL,'before',9)");
    $insert = $pdo->prepare('INSERT INTO images VALUES (?,7,?,?,?,?)');
    // 601 crosses both the 50-name detail page and 500-ID apply page boundaries.
    foreach (range(1, 601) as $id) {
        $filename = 'camera_' . $id . '.jpg';
        $insert->execute([$id, $filename, $filename, 'Photo ' . $id, $id]);
    }
    $insert->execute([900, 'nested/kept.jpg', 'kept.jpg', 'Nested', 900]);

    // Copy the complete owner and its declared parts, preserving production
    // relative includes while confining real report writes to an owned root.
    $owned = sys_get_temp_dir() . '/gallery-rename-preview-' . bin2hex(random_bytes(8));
    $paths = ['app/services/media_renamer.php',
        'app/services/media_renamer/preview.php', 'app/services/media_renamer/reports.php'];
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
        // Keep the title below the real 255-character limit; the parent path
        // supplies the remaining context which made the reported name too long.
        $longTitle = str_repeat('Distant worlds and long chapter names ', 6);
        $galleryInsert = $pdo->prepare('INSERT INTO galleries VALUES (8,?,?,?,NULL,?,1)');
        $galleryInsert->execute([$longTitle,
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
        $longInsert = $pdo->prepare('INSERT INTO images VALUES (?,8,?,?,?,0)');
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
