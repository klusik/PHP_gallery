<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_attachment_storage_database_test.php
 * Module Type: Database Regression Test
 * Purpose: Verify attachment accounting on the owned migrated database.
 * Responsibilities: Prove import collision semantics, totals and empty coverage.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Expose only the validated disposable connection to the production model.
     * @return \PDO Owned workflow fixture connection.
     */
    function db(): \PDO
    {
        return $GLOBALS['attachment_storage_fixture_db'];
    }
}

namespace Gallery\Services {
    /**
     * Keep advisory revision writes in memory beside the real SQL ledger test.
     * @param string $key Advisory settings identity.
     * @param string $value Opaque revision nonce.
     * @return void Record only fixture-owned settings.
     */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['attachment_storage_fixture_settings'][$key] = $value;
    }
}

namespace {
    require_once __DIR__ . '/support/gallery_workflow_safety.php';
    require_once __DIR__ . '/../app/models/admin_storage_statistics.php';
    require_once __DIR__ . '/../app/services/gallery_pdf_settings.php';

    use function GalleryWorkflow\check;
    use function GalleryWorkflow\fixtureDatabase;
    use function GalleryWorkflow\validateFixture;
    use function Gallery\Models\gallery_attachment_storage_model_forget;
    use function Gallery\Models\gallery_attachment_storage_model_pending_count;
    use function Gallery\Models\gallery_attachment_storage_model_pending;
    use function Gallery\Models\gallery_attachment_storage_model_store;
    use function Gallery\Models\gallery_attachment_storage_model_summary;

    if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
        $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
        echo ($required ? 'BLOCKED' : 'SKIP')
            . " attachment accounting requires an owned disposable database\n";
        exit($required ? 1 : 0);
    }

    $pdo = null;
    try {
        $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
        $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
        $pdo = fixtureDatabase($directory, $token);
        $GLOBALS['attachment_storage_fixture_db'] = $pdo;
        $pdo->beginTransaction();
        // Two source identities prove case-insensitive relative-path selection
        // and reject a misleading filename; all edits roll back with the ledger.
        $photoIds = $pdo->query('SELECT id FROM images ORDER BY id LIMIT 2')
            ->fetchAll(\PDO::FETCH_COLUMN);
        check(count($photoIds) === 2, 'Keyset fixture needs two indexed photos.');
        $setPath = $pdo->prepare('UPDATE images SET relative_path = ?, filename = ? WHERE id = ?');
        $setPath->execute(['fixture.DnG', 'ordinary.jpg', $photoIds[0]]);
        $setPath->execute(['ordinary.jpg', 'misleading.dng', $photoIds[1]]);
        $originalEmulation = (bool) $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
        try {
            // Exercise both supported driver modes with the actual LIMIT owner.
            foreach ([true, false] as $emulated) {
                $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $emulated);
                $rows = \Gallery\Models\admin_storage_statistics_model_image_rows_after_id(
                    0, 1, true
                );
                check(count($rows) === 1 && (int) $rows[0]['image_id'] > 0,
                    'Image batches must return their bounded first row in both PDO modes.');
                $afterLast = \Gallery\Models\admin_storage_statistics_model_image_rows_after_id(
                    PHP_INT_MAX, 1, true
                );
                check($afterLast === [],
                    'A successful exhausted keyset must still return an empty batch.');
                $displayRows = \Gallery\Models\admin_storage_statistics_model_image_rows_after_id(
                    0, 1, true, true
                );
                check(count($displayRows) === 1
                    && (int) $displayRows[0]['image_id'] === (int) $photoIds[0],
                    'DNG-only keysets must honor case-insensitive paths and integer LIMIT.');
                $afterDng = \Gallery\Models\admin_storage_statistics_model_image_rows_after_id(
                    (int) $photoIds[0], 1, true, true
                );
                check($afterDng === []
                    || (int) $afterDng[0]['image_id'] !== (int) $photoIds[1],
                    'A DNG filename must not override the ordinary relative path.');
            }
        } finally {
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $originalEmulation);
        }
        $galleryId = (int) $pdo->query('SELECT id FROM galleries ORDER BY id LIMIT 1')
            ->fetchColumn();
        check($galleryId > 0, 'Accounting fixture needs a migrated gallery.');
        foreach (['pdf', 'music', 'ofp'] as $kind) {
            gallery_attachment_storage_model_forget($galleryId, $kind);
        }
        $before = gallery_attachment_storage_model_summary();
        $pendingBefore = gallery_attachment_storage_model_pending_count();
        $pendingOwners = gallery_attachment_storage_model_pending($pendingBefore);
        $selected = array_values(array_filter($pendingOwners,
            static fn(array $row): bool => $row['id'] === $galleryId));
        check(count($selected) === 1
            && $selected[0]['missing_kinds'] === ['pdf', 'music', 'ofp'],
            'Import selection must name only the missing canonical categories.');
        // Distinct structural values distinguish PDF, OFP and music summation.
        gallery_attachment_storage_model_store($galleryId, 'pdf', 1, 10);
        gallery_attachment_storage_model_store($galleryId, 'music', 1, 30);
        gallery_attachment_storage_model_store($galleryId, 'ofp', 1, 20);
        gallery_attachment_storage_model_store($galleryId, 'pdf', 9, 999, 0, true);
        $after = gallery_attachment_storage_model_summary();
        check($after['pdf_count'] === $before['pdf_count'] + 2
            && $after['pdf_bytes'] === $before['pdf_bytes'] + 30,
            'Import must preserve the writer row and combine PDF/OFP once.');
        check($after['music_count'] === $before['music_count'] + 1
            && $after['music_bytes'] === $before['music_bytes'] + 30,
            'Music totals must remain separate from PDF/OFP.');
        check(gallery_attachment_storage_model_pending_count() === $pendingBefore - 1,
            'Three category rows must complete one physical owner.');
        \Gallery\Services\gallery_attachment_storage_record($galleryId, 'music', []);
        $deleted = gallery_attachment_storage_model_summary();
        check($deleted['music_bytes'] === $before['music_bytes']
            && gallery_attachment_storage_model_pending_count() === $pendingBefore - 1,
            'Deleting the last track must retain complete empty coverage.');
        check(isset($GLOBALS['attachment_storage_fixture_settings']
            ['gallery_attachment_storage_revision']),
            'Committed category publication must invalidate cached report revisions.');
        gallery_attachment_storage_model_forget($galleryId, 'pdf');
        check(gallery_attachment_storage_model_pending_count() === $pendingBefore,
            'Invalidation must schedule only missing derived accounting.');
        echo "Attachment storage database contracts passed.\n";
    } finally {
        if ($pdo instanceof \PDO && $pdo->inTransaction()) $pdo->rollBack();
    }
}
