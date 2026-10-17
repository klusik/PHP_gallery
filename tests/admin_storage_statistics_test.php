<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_storage_statistics_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies Admin media accounting and resumable jobs with isolated storage.
 *
 * Responsibilities:
 *   - Cover source file extension normalization
 *   - Cover source file-size bucket selection
 *   - Cover chart row sorting and percentage calculation
 *   - Keep the test database-free for plain PHP execution
 *   - Exercise actual attachment readers over private temporary originals
 *   - Verify bounded gallery cursors, stale jobs and partial-accounting warnings
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-06-08
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Check canonical fixture confinement without application configuration.
     * @param string $root Owned fixture directory.
     * @param string $candidate Canonical expected media path.
     * @return bool Whether the candidate is beneath the owned root.
     */
    function path_inside(string $root, string $candidate): bool
    {
        return str_starts_with($candidate, $root . DIRECTORY_SEPARATOR);
    }
}

namespace Gallery\Models {
    /**
     * Observe fixture data for the actual storage-job fingerprint.
     * @return array{0:array{image_count:int,original_bytes:int},
     *     1:array{gallery_count:int}} Deterministic source/owner aggregates.
     */
    function admin_storage_statistics_model_fingerprint_rows(): array
    {
        $images = $GLOBALS['storage_fixture_images'] ?? [];
        return [['image_count' => count($images),
            'original_bytes' => array_sum(array_column($images, 'file_size'))],
            ['gallery_count' => admin_storage_statistics_model_gallery_count()]];
    }

    /**
     * Count fixture owners, including galleries without images.
     * @return int Number of registered fixture galleries.
     */
    function admin_storage_statistics_model_gallery_count(): int
    {
        return count($GLOBALS['storage_fixture_galleries'] ?? []);
    }

    /**
     * Return one exclusive fixture gallery keyset batch.
     * @param int $lastGalleryId Last observed positive gallery ID.
     * @param int $limit Service-owned observation cap.
     * @return list<array{id:int,folder_path:string}> Ordered owners after cursor.
     */
    function admin_storage_statistics_model_gallery_rows_after_id(
        int $lastGalleryId,
        int $limit
    ): array {
        $GLOBALS['storage_gallery_limits'][] = $limit;
        return array_slice(array_values(array_filter(
            $GLOBALS['storage_fixture_galleries'] ?? [],
            static fn(array $row): bool => $row['id'] > $lastGalleryId
        )), 0, $limit);
    }

    /**
     * Return indexed fixture photo source sizes.
     * @param bool $hasDerivativeVersion Whether the caller resolved that column.
     * @return list<array{image_id:int,gallery_id:int,filename:string,
     *     file_size:int}> Minimal rows consumed by the real source accumulator.
     */
    function admin_storage_statistics_model_image_rows(
        bool $hasDerivativeVersion
    ): array {
        return $GLOBALS['storage_fixture_images'] ?? [];
    }

    /**
     * Return one exclusive fixture photo batch.
     * @param int $lastImageId Last observed image ID.
     * @param int $limit Service-owned image batch cap.
     * @param bool $hasDerivativeVersion Whether derivative metadata exists.
     * @return list<array{image_id:int,gallery_id:int,filename:string,
     *     file_size:int}> Photo observations after the cursor.
     */
    function admin_storage_statistics_model_image_rows_after_id(
        int $lastImageId,
        int $limit,
        bool $hasDerivativeVersion
    ): array {
        return array_slice(array_values(array_filter(
            admin_storage_statistics_model_image_rows($hasDerivativeVersion),
            static fn(array $row): bool => $row['image_id'] > $lastImageId
        )), 0, $limit);
    }
}

namespace Gallery\Services {
    /**
     * Select an explicit master state for isolated SimBrief file persistence.
     * @param string $key Canonical capability identity.
     * @return bool Requested fixture state, enabled unless explicitly changed.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return $GLOBALS['storage_media_features'][$key] ?? true;
    }

    /**
     * Resolve a physical fixture gallery beneath its private root.
     * @param string $relative Registered fixture-relative path.
     * @return string Absolute fixture gallery path.
     */
    function gallery_abs_path(string $relative): string
    {
        return galleries_root() . '/' . $relative;
    }

    /**
     * Return the directory owned by this fixture.
     * @return string Private temporary galleries root.
     */
    function galleries_root(): string
    {
        return $GLOBALS['storage_fixture_root'];
    }

    /**
     * Read fixture settings without touching installation data.
     * @param string $key Persisted snapshot/job/revision identity.
     * @param string|null $default Missing-value fallback, initially null.
     * @return string|null Stored JSON/scalar or the supplied fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $GLOBALS['storage_fixture_settings'][$key] ?? $default;
    }

    /**
     * Persist fixture state or inject an optional reporting write failure.
     * @param string $key Snapshot/job/revision key.
     * @param string $value Exact JSON or revision bytes.
     * @return void Store the value; throw when failure injection is enabled.
     */
    function set_app_setting(string $key, string $value): void
    {
        if (!empty($GLOBALS['storage_fail_write'])) {
            throw new \RuntimeException('Owned statistics write failure.');
        }
        $GLOBALS['storage_fixture_settings'][$key] = $value;
    }
}

namespace {
    /**
     * Keep temporary media and indexes private to the executing fixture account.
     * Type: int. Units: POSIX permission bits. Scope: this fixture's directories.
     * Consumers: fixture root/gallery/music-sidecar creation below.
     * Rationale: Mode 0700 isolates the owned temporary originals from other users.
     */
    const ADMIN_STORAGE_FIXTURE_DIRECTORY_MODE = 0700;

    use function Gallery\Services\admin_storage_statistics_add_group_value;
    use function Gallery\Services\admin_storage_statistics_compact_source_summary;
    use function Gallery\Services\admin_storage_statistics_finalize_group_rows;
    use function Gallery\Services\admin_storage_statistics_normalize_file_extension;
    use function Gallery\Services\admin_storage_statistics_normalize_generated_summary;
    use function Gallery\Services\admin_storage_statistics_size_bucket_key;
    use function Gallery\Services\admin_storage_statistics_snapshot_from_summaries;
    use function Gallery\Services\admin_storage_statistics_source_summary;

    require_once __DIR__ . '/../app/services/admin_storage_statistics.php';

    /**
     * Throw when a storage-statistics expectation fails.
     *
     * @param int|float|string|bool $expected Required scalar observation.
     * @param int|float|string|bool $actual Observed total, state or retained bytes.
     * @param string $label Safe explanation of the accounting contract.
     * @return void Throw with both scalar observations when they differ.
     */
    function assert_admin_storage_same(
        int|float|string|bool $expected,
        int|float|string|bool $actual,
        string $label
    ): void {
        if ($expected !== $actual) {
            throw new RuntimeException($label . ' expected '
                . var_export($expected, true) . ', got '
                . var_export($actual, true));
        }
    }

    foreach ([
        'IMG_0001.JPEG' => 'jpg',
        'scan.TIF' => 'tiff',
        'README' => 'unknown',
        'photo.bad-extension-name' => 'other',
    ] as $path => $expected) {
        assert_admin_storage_same($expected,
            admin_storage_statistics_normalize_file_extension($path),
            'extension normalization for ' . $path);
    }
    // Exact byte vectors exercise each established size-range boundary.
    foreach ([
        [0, 'unknown'],
        [512 * 1024, 'under_1mb'],
        [2 * 1024 * 1024, '1_3mb'],
        [5 * 1024 * 1024, '3_8mb'],
        [12 * 1024 * 1024, '8_20mb'],
        [30 * 1024 * 1024, '20_50mb'],
        [90 * 1024 * 1024, 'over_50mb'],
    ] as [$bytes, $expected]) {
        assert_admin_storage_same($expected,
            admin_storage_statistics_size_bucket_key($bytes),
            'size bucket for ' . $bytes);
    }

    $groups = [];
    admin_storage_statistics_add_group_value($groups, 'jpg', 'JPG', 2, 400, ['extension' => 'jpg']);
    admin_storage_statistics_add_group_value($groups,
        'webp', 'WEBP', 1, 100, ['extension' => 'webp']);
    admin_storage_statistics_add_group_value($groups, 'jpg', 'JPG', 1, 100, ['extension' => 'jpg']);
    $rows = admin_storage_statistics_finalize_group_rows($groups, 'bytes');

    assert_admin_storage_same('jpg', (string) ($rows[0]['key'] ?? ''),
        'largest byte group sorts first');
    assert_admin_storage_same(3, (int) ($rows[0]['count'] ?? 0),
        'duplicate group counts accumulate');
    assert_admin_storage_same(500, (int) ($rows[0]['bytes'] ?? 0),
        'duplicate group bytes accumulate');
    assert_admin_storage_same(83.3, (float) ($rows[0]['percent'] ?? 0.0),
        'percentage calculation uses total grouped bytes');
    assert_admin_storage_same(16.7, (float) ($rows[1]['percent'] ?? 0.0),
        'smaller row percentage remains stable');

    $sourceRows = [
        [
            'image_id' => 1,
            'gallery_id' => 10,
            'image_gallery_id' => 10,
            'relative_path' => 'Trip/IMG_0001.JPG',
            'filename' => 'IMG_0001.JPG',
            'mime_type' => 'image/jpeg',
            'file_size' => 2 * 1024 * 1024,
            'gallery_title' => 'Trip',
            'gallery_folder_path' => 'trip',
        ],
        [
            'image_id' => 2,
            'gallery_id' => 10,
            'image_gallery_id' => 10,
            'relative_path' => 'Trip/RAW_0002.DNG',
            'filename' => 'RAW_0002.DNG',
            'mime_type' => 'image/x-adobe-dng',
            'file_size' => 55 * 1024 * 1024,
            'gallery_title' => 'Trip',
            'gallery_folder_path' => 'trip',
        ],
    ];
    $sourceSummary = admin_storage_statistics_compact_source_summary(
        admin_storage_statistics_source_summary($sourceRows)
    );
    assert_admin_storage_same(2, (int) ($sourceSummary['image_count'] ?? 0),
        'source summary counts rows');
    assert_admin_storage_same(57 * 1024 * 1024,
        (int) ($sourceSummary['original_bytes'] ?? 0),
        'source summary totals source bytes');
    assert_admin_storage_same('Trip/RAW_0002.DNG',
        (string) ($sourceSummary['largest_original_name'] ?? ''),
        'source summary tracks largest source');
    assert_admin_storage_same('source-type-dng',
        (string) (($sourceSummary['type_rows'][0]['key'] ?? '')),
        'source type rows sort by bytes');

    $generatedSummary = admin_storage_statistics_normalize_generated_summary([
        'thumbnail_bytes' => 300,
        'thumbnail_count' => 2,
        'display_master_bytes' => 700,
        'display_master_count' => 1,
        'scan_errors' => 0,
        'generated_type_groups' => [],
    ]);
    $generatedSummary['generated_bytes'] = 1000;
    $snapshot = admin_storage_statistics_snapshot_from_summaries(
        'abc', $sourceSummary, $generatedSummary
    );
    assert_admin_storage_same(57 * 1024 * 1024 + 1000,
        (int) ($snapshot['total_picture_bytes'] ?? 0),
        'snapshot combines source and generated bytes');
    assert_admin_storage_same(1000, (int) ($snapshot['generated_bytes'] ?? 0),
        'snapshot stores generated bytes');

    require_once __DIR__ . '/../app/services/gallery_pdf_documents.php';
    require_once __DIR__ . '/../app/services/simbrief_ofp_attachments.php';
    require_once __DIR__ . '/../app/models/gallery_music.php';
    require_once __DIR__ . '/../app/services/gallery_pdf_settings.php';
    require_once __DIR__ . '/../app/services/simbrief_descriptions.php';

    $fixtureRoot = sys_get_temp_dir() . '/gallery-storage-'
        . bin2hex(random_bytes(8));
    $GLOBALS['storage_fixture_root'] = $fixtureRoot;
    $GLOBALS['storage_fixture_galleries'] = [
        ['id' => 2, 'folder_path' => 'documents'],
        ['id' => 5, 'folder_path' => 'empty'],
        ['id' => 9, 'folder_path' => 'music-only'],
    ];
    $GLOBALS['storage_fixture_images'] = [
        ['image_id' => 1, 'gallery_id' => 2,
            'filename' => 'photo.jpg', 'file_size' => 123],
    ];
    $GLOBALS['storage_fixture_settings'] = [];
    $GLOBALS['storage_gallery_limits'] = [];
    foreach ($GLOBALS['storage_fixture_galleries'] as $owner) {
        mkdir($fixtureRoot . '/' . $owner['folder_path'],
            ADMIN_STORAGE_FIXTURE_DIRECTORY_MODE, true);
    }
    $pdfBytes = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
    $pdfSource = $fixtureRoot . '/source.pdf';
    file_put_contents($pdfSource, $pdfBytes);
    $musicDirectory = $fixtureRoot . '/music-only/.gallery-music';
    mkdir($musicDirectory, ADMIN_STORAGE_FIXTURE_DIRECTORY_MODE);
    // Deliberately arbitrary payload bytes prove accounting never decodes audio.
    $audioBytes = 'Stored original audio bytes';
    $audioTracks = [];
    foreach ([false, true] as $position => $hidden) {
        $trackId = sprintf('%032x', $position + 1);
        file_put_contents($musicDirectory . '/' . $trackId . '.bin', $audioBytes);
        $audioTracks[] = ['id' => $trackId, 'filename' => 'Same.wav',
            'title' => 'Same.wav', 'mime' => 'audio/wav',
            'size' => strlen($audioBytes), 'sha256' => hash('sha256', $audioBytes),
            'hidden' => $hidden, 'downloadable' => false];
    }
    \Gallery\Models\gallery_music_model_write($musicDirectory, [
        'revision' => str_repeat('a', 32), 'enabled' => false,
        'tracks' => $audioTracks,
    ]);

    try {
        $owner = $GLOBALS['storage_fixture_galleries'][0];
        $attached = \Gallery\Services\gallery_pdf_documents_attach(
            $owner, $pdfSource, 'Original.pdf', 'Original', 'manual'
        );
        \Gallery\Services\gallery_pdf_documents_set_hidden(
            $owner, $attached['id'], true
        );
        file_put_contents($fixtureRoot . '/documents/simbrief-ofp.pdf', $pdfBytes);
        file_put_contents($fixtureRoot . '/documents/simbrief-ofp-manifest.json',
            json_encode(['format' => 'php_gallery_simbrief_ofp_manifest_v1',
                'ofp_pdf_file' => 'simbrief-ofp.pdf'], JSON_THROW_ON_ERROR));
        file_put_contents($musicDirectory . '/unindexed.bin', 'Excluded orphan');

        $state = \Gallery\Services\admin_storage_statistics_start_job();
        assert_admin_storage_same(4, $state['total'],
            'job counts one photo plus three independent physical owners');
        $state = \Gallery\Services\admin_storage_statistics_process_job(
            \Gallery\Services\ADMIN_STORAGE_STATISTICS_MAX_BATCH_SIZE
        );
        assert_admin_storage_same('running', $state['status'],
            'gallery ceiling keeps the music-only gallery for the next step');
        assert_admin_storage_same(3, $state['processed'],
            'progress combines observed photo and gallery records');
        assert_admin_storage_same(
            \Gallery\Services\ADMIN_STORAGE_STATISTICS_GALLERY_BATCH_SIZE,
            max($GLOBALS['storage_gallery_limits']),
            'a large photo batch never widens attachment work'
        );
        $state = \Gallery\Services\admin_storage_statistics_process_job();
        assert_admin_storage_same('complete', $state['status'],
            'all gallery cursors complete');
        $snapshot = $state['snapshot'];
        assert_admin_storage_same(2, $snapshot['pdf_count'],
            'hidden manual PDF and OFP are separate physical originals');
        assert_admin_storage_same(2 * strlen($pdfBytes), $snapshot['pdf_bytes'],
            'PDF originals have exact physical byte accounting');
        assert_admin_storage_same(2, $snapshot['music_count'],
            'same-byte stored copies, hidden and locked tracks all consume disk');
        assert_admin_storage_same(2 * strlen($audioBytes), $snapshot['music_bytes'],
            'music-only owner is measured without counting index or orphan bytes');
        assert_admin_storage_same(123, $snapshot['total_picture_bytes'],
            'attachment accounting preserves photograph-only total semantics');
        assert_admin_storage_same(123 + 2 * strlen($pdfBytes)
            + 2 * strlen($audioBytes), $snapshot['total_media_bytes'],
            'overall total adds each physical media category once');
        assert_admin_storage_same(0, $snapshot['attachment_scan_errors'],
            'complete confined indexes have no accounting warnings');
        $observations = count($GLOBALS['storage_gallery_limits']);
        \Gallery\Services\admin_storage_statistics();
        \Gallery\Services\admin_storage_statistics_cached_snapshot();
        assert_admin_storage_same($observations,
            count($GLOBALS['storage_gallery_limits']),
            'passive cached reads never start attachment scanning');

        $GLOBALS['storage_media_features'] = [
            'gallery_pdf_documents' => false, 'gallery_music' => false,
        ];
        $disabledTotals = \Gallery\Services\gallery_attachment_storage_summary($owner);
        assert_admin_storage_same(2, $disabledTotals['pdf_count'],
            'global PDF OFF cannot remove stored bytes from accounting');
        $savedOfp = \Gallery\Services\simbrief_description_save_ofp_for_gallery(
            $owner, ['files' => ['pdf' =>
                'https://example.invalid/never-fetch-this.pdf']], [], []
        );
        assert_admin_storage_same(true, $savedOfp['saved'],
            'PDF OFF preserves independent SimBrief JSON import');
        assert_admin_storage_same(false, $savedOfp['pdf_saved'],
            'PDF OFF performs no optional original download');
        $manifest = json_decode(file_get_contents(
            $fixtureRoot . '/documents/simbrief-ofp-manifest.json'
        ), true, flags: JSON_THROW_ON_ERROR);
        assert_admin_storage_same('simbrief-ofp.pdf', $manifest['ofp_pdf_file'],
            'an old PDF remains discoverable after re-enabling');
        assert_admin_storage_same($pdfBytes,
            file_get_contents($fixtureRoot . '/documents/simbrief-ofp.pdf'),
            'independent JSON refresh never rewrites an old disabled PDF');
        $deniedPdf = \Gallery\Services\simbrief_description_save_pdf_for_gallery(
            'https://example.invalid/never-fetch-this.pdf',
            $fixtureRoot . '/must-not-exist.pdf'
        );
        assert_admin_storage_same('Gallery PDF documents are disabled.',
            $deniedPdf['error'], 'the direct PDF fetch owner honors global OFF');
        assert_admin_storage_same(false,
            file_exists($fixtureRoot . '/must-not-exist.pdf'),
            'disabled direct imports cannot create an original');
        $GLOBALS['storage_media_features'] = [];

        $GLOBALS['storage_fixture_images'] = [];
        $emptyPhotoJob = \Gallery\Services\admin_storage_statistics_start_job();
        assert_admin_storage_same('running', $emptyPhotoJob['status'],
            'a photo-free installation still starts its gallery accounting phase');
        assert_admin_storage_same(3, $emptyPhotoJob['total'],
            'the photo-free job includes every registered physical gallery');

        \Gallery\Services\admin_storage_statistics_start_job();
        \Gallery\Services\gallery_attachment_storage_changed();
        $state = \Gallery\Services\admin_storage_statistics_process_job();
        assert_admin_storage_same('stale', $state['status'],
            'attachment mutation revision invalidates a running scan');
        assert_admin_storage_same(true,
            \Gallery\Services\admin_storage_statistics_cached_snapshot()['cache_stale'],
            'the last successful snapshot remains visible and explicitly stale');
        $GLOBALS['storage_fail_write'] = true;
        \Gallery\Services\gallery_attachment_storage_changed();
        $GLOBALS['storage_fail_write'] = false;

        $indexPath = $musicDirectory . '/index.json';
        $originalIndex = file_get_contents($indexPath);
        file_put_contents($indexPath, '{damaged');
        $partial = \Gallery\Services\gallery_attachment_storage_summary(
            $GLOBALS['storage_fixture_galleries'][2]
        );
        assert_admin_storage_same(1, $partial['errors'],
            'a damaged index is a visible accounting warning');
        assert_admin_storage_same('{damaged', file_get_contents($indexPath),
            'accounting never repairs damaged metadata');
        file_put_contents($indexPath, $originalIndex);
        $missingOriginal = $musicDirectory . '/' . $audioTracks[0]['id'] . '.bin';
        unlink($missingOriginal);
        $partial = \Gallery\Services\gallery_attachment_storage_summary(
            $GLOBALS['storage_fixture_galleries'][2]
        );
        assert_admin_storage_same(1, $partial['music_count'],
            'missing indexed bytes are excluded without losing other tracks');
        assert_admin_storage_same(1, $partial['errors'],
            'missing originals are explicit partial coverage');
        if (function_exists('symlink') && @symlink($pdfSource, $missingOriginal)) {
            $partial = \Gallery\Services\gallery_attachment_storage_summary(
                $GLOBALS['storage_fixture_galleries'][2]
            );
            assert_admin_storage_same(1, $partial['music_count'],
                'symlink originals cannot inflate media totals');
            assert_admin_storage_same($pdfBytes, file_get_contents($pdfSource),
                'an accounting probe never mutates the symlink target');
        }
    } finally {
        $ownedPaths = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixtureRoot,
                FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($ownedPaths as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                rmdir($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($fixtureRoot);
    }

    echo "Admin storage statistics helper tests passed.\n";

}
