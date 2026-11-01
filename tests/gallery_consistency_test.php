<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_consistency_test.php
 * Module Type: Regression Test
 * Purpose: Exercise real report cursors, disk authority and selected repairs.
 * Responsibilities: Prove dry runs preserve bytes and failures never look clean.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Read the isolated active repair checkpoint without a real session.
     * @param string $key Expected consistency session key.
     * @return \Gallery\Services\ConsistencyJob|null Fixture checkpoint.
     */
    function session_context_get(string $key): ?array
    {
        return $GLOBALS['consistency_session_job'] ?? null;
    }

    /** Persist one isolated consistency checkpoint.
     * @param string $key Expected consistency session key.
     * @param \Gallery\Services\ConsistencyJob $value Updated fixture checkpoint.
     * @return void Retains only this fixture's active job.
     */
    function session_context_set(string $key, array $value): void
    {
        $GLOBALS['consistency_session_job'] = $value;
    }
    /**
     * Return the disposable gallery root, without production configuration.
     * @return array{galleries_root:string} Fixture storage configuration.
     */
    function cms_config(): array
    {
        return ['galleries_root' => $GLOBALS['consistency_root']];
    }

    /**
     * Model ASCII slug inputs at the path-policy adapter boundary.
     * @param string $text Primary title from this fixture's ASCII vectors.
     * @return string Deterministic fixture leaf; production transliteration has
     *     its own existing coverage.
     */
    function slugify(string $text): string
    {
        return trim(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $text)), '-');
    }
}

namespace Gallery\Models {
    /**
     * Supply the existing journal model at the deferred-owner adapter boundary.
     * @return list<array{operation_id:string}> No pending fixture operations.
     */
    function gallery_image_move_model_pending(): array { return []; }

    /**
     * Return fixture catalog rows without a database connection.
     * @return list<array{id:int,title:string,folder_path:string,parent_id:null}>
     *     Current fixture gallery identities.
     */
    function gallery_consistency_model_galleries(): array
    {
        return $GLOBALS['consistency_galleries'];
    }

    /**
     * Return the deterministic current catalog lease.
     * @param bool $includeImages Whether source membership belongs in the lease.
     * @return array{fingerprint:string,images:int} Fixture catalog snapshot.
     */
    function gallery_consistency_model_snapshot(bool $includeImages = true): array
    {
        $GLOBALS['consistency_source_snapshots'][] = $includeImages;
        return ['fingerprint' => $GLOBALS['consistency_fingerprint'],
            'images' => $includeImages ? count($GLOBALS['consistency_images']) : 0];
    }

    /**
     * Return a bounded fixture source batch.
     * @param int $afterId Exclusive image cursor.
     * @param int $limit Requested batch ceiling.
     * @return list<array{id:int,gallery_id:int,filename:string,
     *     relative_path:string,file_size:int}> Matching source rows.
     */
    function gallery_consistency_model_images(int $afterId, int $limit): array
    {
        $GLOBALS['consistency_source_reads']++;
        return array_slice(array_values(array_filter(
            $GLOBALS['consistency_images'],
            static fn(array $row): bool => $row['id'] > $afterId,
        )), 0, $limit);
    }

    /**
     * Read the fixture's metadata adapter state.
     * @param int $imageId Source identity being inspected.
     * @return list<array{size_px:int,format:string,status:string,width:int,
     *     height:int,file_size:int}> Current metadata rows.
     */
    function gallery_consistency_model_variants(int $imageId): array
    {
        return $GLOBALS['consistency_variants'][$imageId] ?? [];
    }

    /**
     * Return the explicit orphan-metadata vector.
     * @return int Fixture orphan count.
     */
    function gallery_consistency_model_orphan_variants(): int
    {
        return $GLOBALS['consistency_orphans'];
    }
}

namespace Gallery\Services {
    /** Supply one fixture source through the existing lookup seam.
     * @param int $id Source catalog identity.
     * @param bool $fresh Whether a current row was requested.
     * @return array{id:int,gallery_id:int,filename:string,relative_path:string,
     *     file_size:int}|null Matching source, or null.
     */
    function find_image(int $id, bool $fresh = false): ?array
    {
        foreach ($GLOBALS['consistency_images'] as $image) {
            if ($image['id'] === $id) return $image;
        }
        return null;
    }

    /** Model the acquired writer lease without a host lock file.
     * @return array{fixture:bool} Owned fixture lease.
     */
    function gallery_edit_writer_begin(): array { return ['fixture' => true]; }

    /** Release the isolated writer lease.
     * @param array{fixture:bool} $lock Previously acquired fixture lease.
     * @return void Performs no host filesystem operation.
     */
    function gallery_edit_writer_end(array $lock): void {}

    /** Admit only the fixture's explicitly available thumbnail schema.
     * @param string $operation Safe operation label.
     * @return void No schema mutation is performed.
     */
    function thumbnail_metadata_preflight_write_schema(string $operation): void {}

    /** Refresh fake metadata from real fixture thumbnail headers only.
     * @param array{id:int,filename:string,...} $image Fixture source row.
     * @param array{folder_path:string,...} $gallery Fixture directory owner.
     * @param list<int>|null $sizes Optional configured sizes, null means all.
     * @param bool $force Unused force flag retained at the adapter boundary.
     * @return array{updated:int} Number of actual fixture files inspected.
     */
    function thumbnail_metadata_refresh_image(
        array $image, array $gallery, ?array $sizes = null, bool $force = false,
    ): array {
        $rows = [];
        foreach ($sizes ?? thumbnail_sizes() as $size) {
            foreach (['jpg', 'webp'] as $format) {
                $path = thumbnail_abs_path($image, $gallery, $size, $format);
                if (!is_file($path)) continue;
                $rows[] = ['size_px' => $size, 'format' => $format,
                    'status' => 'valid', 'width' => 1, 'height' => 1,
                    'file_size' => filesize($path)];
            }
        }
        $GLOBALS['consistency_variants'][$image['id']] = $rows;
        return ['updated' => count($rows)];
    }
    /**
     * Normalize an explicit fixture locale at the shared-layout boundary.
     * @param string $code Requested locale code.
     * @return string Lowercase fixture locale.
     */
    function translation_normalize_language_code(string $code): string
    {
        return strtolower($code);
    }

    /**
     * Admit only the two dictionary-overlay vectors used below.
     * @param string $code Requested locale.
     * @return bool Whether this is one of the fixture locales.
     */
    function translation_language_allowed(string $code): bool
    {
        return in_array($code, ['en', 'cs'], true);
    }

    /**
     * Supply dictionaries and record whether an identical locale is reloaded.
     * @param string $code Requested locale.
     * @return array<string,string> Greeting and default-only fallback strings.
     */
    function translation_load_language(string $code): array
    {
        $GLOBALS['consistency_language_reads'][] = $code;
        return $code === 'cs'
            ? ['greeting' => 'Ahoj']
            : ['greeting' => 'Hello', 'default_only' => 'Default'];
    }

    /**
     * Supply the preloaded cache owner without mutating a real installation.
     * @return void No cache is persisted by this fixture adapter.
     */
    function thumbnail_maintenance_summary_cache_clear(): void {}

    /**
     * Look up a fixture gallery at the persistence adapter boundary.
     * @param int $id Requested gallery identity.
     * @param bool $fresh Whether the caller explicitly requested current rows.
     * @return array{id:int,title:string,folder_path:string,parent_id:null}|null
     *     Fixture row, or null when absent.
     */
    function find_gallery(int $id, bool $fresh = false): ?array
    {
        foreach ($GLOBALS['consistency_galleries'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Assert the fixture identity guard instead of querying ownership SQL.
     * @param array{id:int,gallery_id:int,...} $image Source being inspected.
     * @return void Throws for the explicit ambiguous-ownership vector.
     */
    function thumbnail_assert_source_identity_owned(array $image): void
    {
        if (($GLOBALS['consistency_ambiguous'] ?? false) === true) {
            throw new \RuntimeException('Ambiguous fixture ownership');
        }
    }

    /**
     * Return the two size vectors used by this job test.
     * @return list<int> Two production-supported sizes exercise per-source dedup.
     */
    function thumbnail_sizes(): array { return [300, 600]; }

    /**
     * Return a deterministic filename at the naming adapter boundary.
     * @param array{filename:string,...} $image Fixture source identity.
     * @param int $size Requested supported width.
     * @param string $format Requested derivative format.
     * @return string Fixture cache basename.
     */
    function thumbnail_filename(array $image, int $size, string $format): string
    {
        return $image['filename'] . '-' . $size . '.' . $format;
    }

    /**
     * Resolve one cache path inside disposable fixture storage.
     * @param array{filename:string,...} $image Fixture source identity.
     * @param array{folder_path:string,...} $gallery Fixture owner.
     * @param int $size Requested width vector.
     * @param string $format Requested format vector.
     * @return string Authorized fixture derivative path.
     */
    function thumbnail_abs_path(
        array $image, array $gallery, int $size, string $format,
    ): string {
        return gallery_abs_path($gallery['folder_path']) . '/thumbs/'
            . thumbnail_filename($image, $size, $format);
    }

    /**
     * Supply known source geometry at the thumbnail-owner adapter boundary.
     * @param string $path Safe source path.
     * @param array{id:int,...} $image Persisted fixture identity.
     * @return array{width:int,height:int} One-pixel fixture PNG geometry.
     */
    function thumbnail_source_geometry_dimensions(string $path, array $image): array
    {
        return ['width' => 1, 'height' => 1];
    }

    /**
     * Model geometry validation using the actual generated file header.
     * @param string $path Derivative path to inspect.
     * @param int $width Expected source width.
     * @param int $height Expected source height.
     * @param int $size Requested size vector.
     * @return array{valid:bool} Header readability result for this one-pixel PNG.
     */
    function thumbnail_file_geometry_status(
        string $path, int $width, int $height, int $size,
    ): array {
        return ['valid' => @getimagesize($path) !== false];
    }

    /**
     * Supply the fixture's raster MIME without production MIME-policy probing.
     * @param string $path Source path being inspected.
     * @param array{id:int,...} $image Source identity.
     * @return string PNG MIME for the embedded fixture vector.
     */
    function image_source_mime_for_derivatives(string $path, array $image): string
    {
        return 'image/png';
    }

    /**
     * Supply one format so the test stays independent of WebP host policy.
     * @param string $path Fixture source path.
     * @param string $mime Fixture source MIME.
     * @return list<string> One derivative-format vector.
     */
    function thumbnail_target_formats_for_source(string $path, string $mime): array
    {
        return ['jpg'];
    }

    /**
     * Refuse decoding to exercise explicitly incomplete content coverage.
     * @param string $path Safe original path.
     * @param string $mime Expected MIME.
     * @param int|null $side Comparison target size.
     * @return false Explicit unavailable-decoder fixture result.
     */
    function image_create_from_path(
        string $path, string $mime, ?int $side = null,
    ): \GdImage|false
    {
        return false;
    }

    /**
     * Exclude RAW masters from this raster-only fixture.
     * @param array{id:int,...} $image Source identity.
     * @return bool False for the PNG fixture.
     */
    function image_uses_dng_display_derivatives(array $image): bool { return false; }

    /**
     * Return conclusively available metadata storage at the schema boundary.
     * @return array{state:string} Explicit schema state.
     */
    function thumbnail_metadata_mutation_schema_status(): array
    {
        return ['state' => 'available'];
    }

    /**
     * Model the three-state schema predicate.
     * @param array{state:string} $status Fixture schema observation.
     * @return bool True only for available state.
     */
    function schema_inspection_is_available(array $status): bool
    {
        return $status['state'] === 'available';
    }

    /**
     * Supply a matching version at the existing metadata-policy boundary.
     * @param array{size_px:int,...} $row Fixture metadata identity.
     * @param array{id:int,...} $image Fixture source identity.
     * @return bool Matching source/version vector.
     */
    function thumbnail_metadata_row_matches_image_source(array $row, array $image): bool
    {
        return true;
    }

    /**
     * Supply the explicit idle-operation vector without journal SQL.
     * @return list<array{operation_id:string,source_gallery_id:int,
     *     destination_gallery_id:int}> Pending fixture move records.
     */
    function gallery_image_move_pending(): array { return []; }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_files.php';
    require_once dirname(__DIR__) . '/app/services/gallery_paths.php';
    require_once dirname(__DIR__) . '/app/services/gallery_consistency.php';
    require_once dirname(__DIR__) . '/app/services/browser_thumbnail_rebuild.php';
    require_once dirname(__DIR__) . '/app/controllers/shared_layout.php';

    /**
     * Require an observable consistency invariant.
     * @param bool $condition Expected production-owner postcondition.
     * @param string $message Safe failure explanation.
     * @return void Throws on a failed assertion.
     */
    function consistency_check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $root = sys_get_temp_dir() . '/gallery-consistency-' . uniqid();
    $GLOBALS['consistency_root'] = $root;
    $GLOBALS['consistency_fingerprint'] = 'catalog-before';
    $GLOBALS['consistency_galleries'] = [[
        'id' => 1, 'title' => 'Wrong title', 'folder_path' => 'disk-name',
        'parent_id' => null,
    ]];
    $GLOBALS['consistency_images'] = [[
        'id' => 1, 'gallery_id' => 1, 'filename' => 'source.png',
        'relative_path' => 'source.png', 'file_size' => 0,
    ]];
    $GLOBALS['consistency_variants'] = [];
    $GLOBALS['consistency_orphans'] = 0;
    $GLOBALS['consistency_source_reads'] = 0;
    $GLOBALS['consistency_source_snapshots'] = [];
    mkdir($root);
    mkdir($root . '/disk-name');
    mkdir($root . '/disk-name/thumbs');
    $source = $root . '/disk-name/source.png';
    $thumbnail = $root . '/disk-name/thumbs/source.png-300.jpg';
    // The embedded one-pixel PNG is a format vector, not a generation policy.
    $png = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
        . 'AAAAC0lEQVR42mP8/x8AAwMCAO+a1ZsAAAAASUVORK5CYII=',
    );
    $browserFiles = [];
    try {
        $names = \Gallery\Services\gallery_consistency_start('names');
        \Gallery\Services\gallery_consistency_step($names);
        consistency_check($names['status'] === 'complete'
            && $names['total'] === 1 && $names['expected'] === []
            && $names['disk_checked'] === 0
            && $GLOBALS['consistency_source_reads'] === 0
            && $GLOBALS['consistency_source_snapshots'] === [false, false],
            'Names-only analysis must not inspect the photograph catalog or disk.');
        consistency_check($names['groups']['g1']['changes'] === [[
            'field' => 'title_value', 'current' => 'Wrong title',
            'proposed' => 'disk-name',
        ]], 'The displayed proposal must match the actual disk-title repair policy.');
        $GLOBALS['consistency_galleries'][0]['title'] = 'Disk Name';
        $GLOBALS['consistency_galleries'][0]['folder_path'] = 'Disk Name';
        $names = \Gallery\Services\gallery_consistency_start('names');
        \Gallery\Services\gallery_consistency_step($names);
        consistency_check($names['groups']['g1']['changes'] === [[
            'field' => 'leaf_value', 'current' => 'Disk Name',
            'proposed' => 'disk-name',
        ], [
            'field' => 'folder_value', 'current' => 'Disk Name',
            'proposed' => 'disk-name',
        ]], 'A readable matching title must stay unchanged while the folder is sanitized.');
        $legacy = $names;
        unset($legacy['groups']['g1']['changes']);
        $legacyBefore = serialize($legacy);
        $presented = \Gallery\Services\gallery_consistency_presentation($legacy);
        consistency_check($presented['groups']['g1']['changes']
            === $names['groups']['g1']['changes']
            && serialize($legacy) === $legacyBefore,
            'Older completed reports gain the same snapshot proposal without rerunning analysis.');
        $GLOBALS['consistency_galleries'][0]['title'] = 'Wrong title';
        $GLOBALS['consistency_galleries'][0]['folder_path'] = 'disk-name';
        $GLOBALS['consistency_language_reads'] = [];
        $dictionary = \Gallery\Controllers\shared_layout_browser_i18n_model('en', 'en');
        consistency_check($GLOBALS['consistency_language_reads'] === ['en']
            && $dictionary['strings']['greeting'] === 'Hello',
            'Identical browser dictionaries must not be loaded/copied twice.');
        $dictionary = \Gallery\Controllers\shared_layout_browser_i18n_model('cs', 'en');
        consistency_check($dictionary['strings'] === [
            'greeting' => 'Ahoj', 'default_only' => 'Default',
        ], 'Dictionary overlays must retain defaults and translated overrides.');
        $job = \Gallery\Services\gallery_consistency_start('content');
        \Gallery\Services\gallery_consistency_step($job);
        consistency_check($job['processed'] === 1, 'Gallery progress is measured.');
        \Gallery\Services\gallery_consistency_step($job);
        $codes = array_column($job['groups']['g1']['issues'], 'code');
        consistency_check(in_array('original_missing', $codes, true),
            'Missing originals must be findings, never a clean report.');
        consistency_check(!is_file($source), 'Dry check must not create originals.');
        consistency_check(\Gallery\Services\gallery_disk_source_title(
            'disk-name', 'Wrong sidecar title',
        ) === 'disk-name', 'Disk must override a conflicting imported title.');
        consistency_check(\Gallery\Services\gallery_disk_source_title(
            'disk-name', 'Disk Name',
        ) === 'Disk Name', 'Compatible readable titles remain available.');

        file_put_contents($source, $png);
        file_put_contents($thumbnail, $png);
        $GLOBALS['consistency_images'][0]['file_size'] = strlen($png);
        $before = [hash_file('sha256', $thumbnail), filemtime($thumbnail)];
        $job = \Gallery\Services\gallery_consistency_start('content');
        \Gallery\Services\gallery_consistency_step($job);
        \Gallery\Services\gallery_consistency_step($job);
        $codes = array_column($job['groups']['g1']['issues'], 'code');
        consistency_check(in_array('metadata_mismatch', $codes, true),
            'Existing files with absent metadata must be reported.');
        consistency_check(in_array('thumbnail_missing', $codes, true),
            'Every configured variant must be inspected.');
        consistency_check(in_array('content_unchecked', $codes, true),
            'Unsupported decoding must remain explicitly unverified.');
        consistency_check($before === [hash_file('sha256', $thumbnail), filemtime($thumbnail)],
            'Analysis preserves existing thumbnail bytes and timestamps.');
        consistency_check(!is_file($root . '/disk-name/gallery.json'),
            'Analysis must not write sidecars.');
        $fileJob = \Gallery\Services\gallery_consistency_start('files');
        \Gallery\Services\gallery_consistency_step($fileJob);
        \Gallery\Services\gallery_consistency_step($fileJob);
        $fileCodes = array_column($fileJob['groups']['g1']['issues'], 'code');
        consistency_check(in_array('thumbnail_missing', $fileCodes, true)
            && !in_array('content_unchecked', $fileCodes, true)
            && isset($fileJob['source_hashes'][1]),
            'File checks retain missing variants and repair leases without content decoding.');
        foreach (array_keys($fileJob['expected']) as $pathKey) {
            consistency_check(strlen($pathKey) === strlen(hash('sha256', '', true)),
                'New jobs must bound ownership keys independently of path length.');
        }
        $job['status'] = 'complete';
        \Gallery\Services\gallery_consistency_prepare_repairs($job, ['g1']);
        consistency_check($job['processed'] === 0 && $job['mode'] === 'repair',
            'Planning repairs must not perform a write.');
        consistency_check(count($job['queue']) === 3,
            'Gallery, image and metadata findings deduplicate to exact tasks.');
        consistency_check($before === [hash_file('sha256', $thumbnail), filemtime($thumbnail)],
            'Preparing the selected queue must preserve thumbnail bytes.');
        $browserJob = $fileJob;
        // This isolated browser vector starts from a completed file report;
        // physical traversal completion is covered by the real HTTP journey.
        $browserJob['status'] = 'complete';
        \Gallery\Services\gallery_consistency_prepare_repairs($browserJob, ['g1']);
        $imageTask = null;
        foreach ($browserJob['queue'] as $position => $task) {
            if ($task['issue']['repair'] === 'image') {
                $imageTask = $task;
                $browserJob['processed'] = $position;
                break;
            }
        }
        consistency_check($imageTask !== null, 'Missing image repair fixture task.');
        \Gallery\Services\gallery_consistency_repair_target(
            $browserJob, $imageTask['key'], $imageTask['issue'],
        );
        consistency_check($browserJob['status'] === 'browser'
            && $browserJob['repaired'] === 0
            && !in_array('300:jpg', $browserJob['browser_pending']['variants'], true),
            'Image repair must prepare only missing variants without generating on PHP.');
        $forcedVariant = $browserJob['browser_pending']['variants'][0];
        $browserJob['browser_pending']['force'] = [$forcedVariant];
        $GLOBALS['consistency_session_job'] = $browserJob;
        $ticket = \Gallery\Services\gallery_consistency_browser_target(
            $browserJob['token'], $browserJob['sequence'],
        );
        $rejected = false;
        try {
            \Gallery\Services\gallery_consistency_browser_target('wrong', $browserJob['sequence']);
        } catch (RuntimeException $exception) {
            $rejected = $exception->getMessage() === 'consistency_browser_stale';
        }
        consistency_check($rejected, 'A different browser ticket must never select a source.');
        \Gallery\Services\gallery_consistency_browser_verify($browserJob);
        consistency_check($browserJob['status'] === 'browser',
            'Missing outputs must never be counted as a completed task.');
        $browserFiles = [];
        foreach ($ticket['variants'] as $variant) {
            [$size, $format] = explode(':', $variant);
            $path = \Gallery\Services\thumbnail_abs_path(
                $GLOBALS['consistency_images'][0], $GLOBALS['consistency_galleries'][0],
                (int) $size, $format,
            );
            file_put_contents($path, $png);
            $browserFiles[] = $path;
        }
        \Gallery\Services\gallery_consistency_browser_verify($browserJob);
        consistency_check($browserJob['status'] === 'browser',
            'A forced replacement requires an actual server upload acknowledgement.');
        $GLOBALS['consistency_session_job'] = $browserJob;
        \Gallery\Services\browser_thumbnail_rebuild_record_consistency_variant(
            $ticket, $forcedVariant,
        );
        $browserJob = $GLOBALS['consistency_session_job'];
        $beforeProcessed = $browserJob['processed'];
        \Gallery\Services\gallery_consistency_browser_verify($browserJob);
        consistency_check($browserJob['processed'] === $beforeProcessed + 1
            && $browserJob['repaired'] === 1,
            'Only persisted, verified browser output may advance the repair count.');
        \Gallery\Services\gallery_consistency_browser_verify($browserJob);
        consistency_check($browserJob['processed'] === $beforeProcessed + 1,
            'A duplicate completion receipt must not count the task twice.');
        consistency_check($before === [hash_file('sha256', $thumbnail), filemtime($thumbnail)],
            'Browser-scoped repairs must preserve valid unrelated thumbnail bytes/times.');
        foreach ($browserFiles as $path) unlink($path);
        $GLOBALS['consistency_variants'] = [];

        // Isolate list reconciliation from filesystem work: one confirmed image,
        // one untouched repairable gallery and one explicit manual finding.
        $remaining = $fileJob;
        $remaining['mode'] = 'repair';
        $remaining['status'] = 'complete';
        $remaining['processed'] = $remaining['total'] = $remaining['repaired'] = 1;
        $remaining['failed'] = 0;
        $resolvedIssue = ['code' => 'thumbnail_missing', 'path' => 'source.png',
            'image_id' => 1, 'repair' => 'image', 'details' => '300:webp'];
        $remaining['queue'] = [['key' => 'g1', 'issue' => $resolvedIssue]];
        $remaining['results'] = [['key' => 'g1', 'code' => 'thumbnail_missing',
            'status' => 'repaired', 'variants' => 1]];
        $remaining['groups'] = [
            'g1' => ['id' => 1, 'title' => 'Disk Name', 'path' => 'disk-name',
                'issues' => [$resolvedIssue]],
            'g2' => ['id' => 2, 'title' => 'Next', 'path' => 'next', 'issues' => [[
                'code' => 'name_mismatch', 'path' => '', 'image_id' => 0,
                'repair' => 'gallery', 'details' => '',
            ]]],
            'g3' => ['id' => 3, 'title' => 'Manual', 'path' => 'manual', 'issues' => [[
                'code' => 'identity_unverified', 'path' => 'unknown.png',
                'image_id' => 3, 'repair' => '', 'details' => '',
            ]]],
        ];
        $withManual = $remaining;
        $withManual['groups']['g1']['issues'][] =
            $remaining['groups']['g3']['issues'][0];
        $readsBefore = $GLOBALS['consistency_source_reads'];
        \Gallery\Services\gallery_consistency_reconcile_repairs($withManual);
        consistency_check(count($withManual['groups']['g1']['issues']) === 1
            && $withManual['groups']['g1']['issues'][0]['repair'] === '',
            'Manual findings in a repaired gallery must remain visible.');
        $failed = $remaining;
        $failed['failed'] = 1;
        $failed['status'] = 'blocked';
        \Gallery\Services\gallery_consistency_reconcile_repairs($failed);
        consistency_check(isset($failed['groups']['g1']),
            'Failed work must never disappear as a confirmed repair.');
        $presented = \Gallery\Services\gallery_consistency_presentation($remaining);
        consistency_check(!isset($presented['groups']['g1'])
            && isset($presented['groups']['g2'], $presented['groups']['g3'])
            && isset($remaining['groups']['g1']),
            'Older completed checkpoints must adopt remaining rows without changing the input.');
        \Gallery\Services\gallery_consistency_reconcile_repairs($presented);
        consistency_check($GLOBALS['consistency_source_reads'] === $readsBefore
            && count($presented['results']) === 1,
            'Reconciliation must be idempotent and never rescan photograph records.');
        $previousSequence = $presented['sequence'];
        \Gallery\Services\gallery_consistency_prepare_repairs($presented, ['g2']);
        consistency_check(count($presented['queue']) === 1
            && $presented['queue'][0]['key'] === 'g2'
            && $presented['sequence'] === $previousSequence + 1
            && $presented['results_start'] === 1,
            'The next selection must reuse the report and fence older admissions.');

        $singleImage = $GLOBALS['consistency_images'];
        $GLOBALS['consistency_images'] = [];
        for ($id = 1;
            $id <= \Gallery\Services\GALLERY_CONSISTENCY_IMAGE_BATCH + 1;
            $id++
        ) {
            $GLOBALS['consistency_images'][] = [
                'id' => $id, 'gallery_id' => 1,
                'filename' => 'absent-' . $id . '.png',
                'relative_path' => 'absent-' . $id . '.png', 'file_size' => 0,
            ];
        }
        $batched = \Gallery\Services\gallery_consistency_start('files');
        \Gallery\Services\gallery_consistency_step($batched);
        \Gallery\Services\gallery_consistency_step($batched);
        consistency_check($batched['last_image'] > 1
            && $batched['last_image'] <= \Gallery\Services\GALLERY_CONSISTENCY_IMAGE_BATCH,
            'A cheap file batch must inspect multiple sources without per-photo HTTP overhead.');
        $GLOBALS['consistency_images'] = $singleImage;

        $job = \Gallery\Services\gallery_consistency_start();
        $job['status'] = 'complete';
        $GLOBALS['consistency_fingerprint'] = 'catalog-after';
        $refused = false;
        try {
            \Gallery\Services\gallery_consistency_prepare_repairs($job, [], true);
        } catch (RuntimeException $exception) {
            $refused = $exception->getMessage() === 'consistency_stale';
        }
        consistency_check($refused, 'Stale reports must refuse repairs.');
        consistency_check(\Gallery\Services\gallery_consistency_signature_distance(
            [0, 0, 0], [255, 255, 255],
        ) === 1.0, 'Content disagreement remains distinct from file naming.');

        $GLOBALS['consistency_fingerprint'] = 'catalog-before';
        $job = \Gallery\Services\gallery_consistency_start();
        $GLOBALS['consistency_ambiguous'] = true;
        \Gallery\Services\gallery_consistency_check_image(
            $job, $GLOBALS['consistency_images'][0],
        );
        consistency_check(in_array('identity_unverified', array_column(
            $job['groups']['g1']['issues'], 'code',
        ), true), 'Ambiguous ownership cannot become a healthy result.');
        echo "Gallery consistency report, selection and disk authority PASS\n";
    } finally {
        foreach (array_merge($browserFiles, [$thumbnail, $source]) as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        rmdir($root . '/disk-name/thumbs');
        rmdir($root . '/disk-name');
        rmdir($root);
    }
}
