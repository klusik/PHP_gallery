<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_cover_selection_test.php
 * Module Type: Regression Test
 * Purpose: Validate automatic and explicit cover selection against real bounded image SQL.
 * Responsibilities:
 *   - Preserve legacy direct-cover and parent-child collage rendering.
 *   - Test public-only filtering, ownership, deterministic order, and stale-ID recovery.
 *   - Confirm automatic direct pictures reuse the existing child-cover collage resolver.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the disposable SQLite connection shared by real image model reads.
     *
     * @return \PDO Initialized in-memory photo catalog.
     */
    function db(): \PDO
    {
        return $GLOBALS['cover_fixture_db'];
    }
}

namespace Gallery\Services {
    /**
     * Read an isolated gallery row in the same shape as the production lookup.
     *
     * @param int $galleryId Physical gallery identifier.
     * @param bool $fresh Ignored cache bypass in this immutable test catalog.
     * @return array{id:int,cover_image_id:?int,cover_image_ids:?string,locked?:bool}|null Stored gallery or null.
     */
    function find_gallery(int $galleryId, bool $fresh = false): ?array
    {
        return $GLOBALS['cover_fixture_galleries'][$galleryId] ?? null;
    }

    /**
     * Read an image from the real SQL fixture, including its owning gallery.
     *
     * @param int $imageId Image identifier.
     * @param bool $fresh Ignored cache bypass in this isolated SQLite fixture.
     * @return array{id:int|string,gallery_id:int|string,relative_path:string,visibility:string}|null Photo row or null.
     */
    function find_image(int $imageId, bool $fresh = false): ?array
    {
        $query = \Gallery\Core\db()->prepare(
            'SELECT * FROM images WHERE id = ?',
        );
        $query->execute([$imageId]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * Enforce visitor-visible and NSFW rules when resolving explicit covers.
     *
     * @param array{visibility?:string,nsfw_enabled?:int|string|null} $image Candidate image.
     * @param array{locked?:bool,not_public?:bool} $gallery Owning gallery.
     * @return bool Whether the simulated anonymous viewer may see this photo.
     */
    function public_image_visible_to_current_visitor_without_admin_bypass(
        array $image,
        array $gallery,
    ): bool {
        return (
            ($image['visibility'] ?? '') === 'public'
                && empty($image['nsfw_enabled'])
                && empty($gallery['locked'])
                && empty($gallery['not_public'])
        );
    }

    /**
     * Enumerate previously configured children without consulting outside state.
     *
     * @param int $galleryId Parent gallery identifier.
     * @param bool $publicOnly Restrict to public child galleries.
     * @return list<array{id:int,cover_image_id:?int,cover_image_ids:?string,locked?:bool}> Direct child rows.
     */
    function child_galleries(int $galleryId, bool $publicOnly): array
    {
        $rows = array_map(
            static fn(int $id): array => $GLOBALS['cover_fixture_galleries'][$id],
            $GLOBALS['cover_fixture_children'][$galleryId] ?? [],
        );
        return array_values(array_filter(
            $rows,
            static fn(array $g): bool => (
                !$publicOnly || empty($g['not_public'])
            ),
        ));
    }

    /**
     * Identify an inaccessible child; the real collage logic must reject it.
     *
     * @param array{locked?:bool} $gallery Child gallery policy fixture.
     * @return string|null Password requirement or open access.
     */
    function gallery_access_requirement(array $gallery): ?string
    {
        return !empty($gallery['locked']) ? 'password' : null;
    }
}

namespace {
    use function Gallery\Services\gallery_cover_image;
    use function Gallery\Services\gallery_direct_cover_image;
    use function Gallery\Services\gallery_cover_collage_images;
    use function Gallery\Services\gallery_cover_selection_ids;
    use function Gallery\Services\gallery_cover_selection_mode;
    use function Gallery\Services\gallery_cover_selection_revision;
    use function Gallery\Services\gallery_cover_selection_storage_valid;

    require_once dirname(__DIR__) . '/app/models/images.php';
    require_once dirname(__DIR__) . '/app/services/gallery_covers.php';

    /**
     * Reject any mismatch in deterministic cover choice or mode.
     *
     * @param int|string|bool|null|list<int> $expected Expected tested scalar or numeric ID list.
     * @param int|string|bool|null|list<int> $actual Actual scalar or ordered ID list.
     * @param string $case User-visible regression case description.
     * @return void Throw before reporting a false positive.
     */
    function cover_selection_assert(
        mixed $expected,
        mixed $actual,
        string $case,
    ): void {
        if ($expected !== $actual) {
            throw new \RuntimeException(
                $case
                    . ': expected '
                    . var_export($expected, true)
                    . ', got '
                    . var_export($actual, true),
            );
        }
    }

    $db = new \PDO('sqlite::memory:');
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    $db->exec(
        'CREATE TABLE images (id INTEGER PRIMARY KEY, gallery_id INTEGER NOT NULL,
        relative_path TEXT NOT NULL, filename TEXT NOT NULL, visibility TEXT NOT NULL, sort_order INTEGER NOT NULL, nsfw_enabled INTEGER DEFAULT 0)',
    );
    $insert = $db->prepare(
        'INSERT INTO images (id,gallery_id,relative_path,filename,visibility,sort_order) VALUES (?,?,?,?,?,?)',
    );
    foreach ([
        [105, 1, 'restricted.jpg', 'restricted.jpg', 'public', -1],
        [101, 1, 'one.jpg', 'one.jpg', 'public', 1],
        [102, 1, 'two.jpg', 'two.jpg', 'public', 2],
        [103, 1, 'private.jpg', 'private.jpg', 'unpublished', 0],
        [104, 1, 'nested/extra.jpg', 'extra.jpg', 'public', 0],
        [201, 2, 'manual.jpg', 'manual.jpg', 'public', 1],
        [205, 2, 'nsfw-manual.jpg', 'nsfw-manual.jpg', 'public', 0],
        [202, 2, 'hidden.jpg', 'hidden.jpg', 'unpublished', 2],
        [203, 3, 'moved.jpg', 'moved.jpg', 'public', 1],
        [204, 3, 'second.jpg', 'second.jpg', 'public', 2],
        [601, 6, 'fallback-1.jpg', 'fallback-1.jpg', 'public', 1],
        [602, 6, 'fallback-2.jpg', 'fallback-2.jpg', 'public', 2],
        [701, 7, 'legacy.jpg', 'legacy.jpg', 'public', 1],
        [702, 7, 'other.jpg', 'other.jpg', 'public', 2],
        [901, 9, 'protected.jpg', 'protected.jpg', 'public', 1],
        [1001, 10, 'local.jpg', 'local.jpg', 'public', 1],
        [1101, 11, 'restricted.jpg', 'restricted.jpg', 'public', 1],
        [1102, 11, 'visible.jpg', 'visible.jpg', 'public', 2],
        [1201, 12, 'unpublished.jpg', 'unpublished.jpg', 'unpublished', 1],
        [1202, 12, 'fallback.jpg', 'fallback.jpg', 'public', 2],
    ] as $fixtureImage) {
        $insert->execute($fixtureImage);
    }

    $db->exec('UPDATE images SET nsfw_enabled = 1 WHERE id IN (105,205,1101)');
    $GLOBALS['cover_fixture_db'] = $db;
    $GLOBALS['cover_fixture_galleries'] = [
        1 => ['id' => 1, 'cover_image_id' => null, 'cover_image_ids' => '[]'],
        2 => [
            'id' => 2,
            'cover_image_id' => 201,
            'cover_image_ids' => '[201,205,202,203]',
        ],
        3 => ['id' => 3, 'cover_image_id' => 203, 'cover_image_ids' => null],
        4 => ['id' => 4, 'cover_image_id' => null, 'cover_image_ids' => null],
        5 => ['id' => 5, 'cover_image_id' => null, 'cover_image_ids' => '[]'],
        6 => ['id' => 6, 'cover_image_id' => 999, 'cover_image_ids' => '[999]'],
        7 => ['id' => 7, 'cover_image_id' => 701, 'cover_image_ids' => null],
        8 => ['id' => 8, 'cover_image_id' => null, 'cover_image_ids' => '[]'],
        9 => [
            'id' => 9,
            'cover_image_id' => 901,
            'cover_image_ids' => null,
            'locked' => true,
        ],
        10 => ['id' => 10, 'cover_image_id' => 203, 'cover_image_ids' => null],
        11 => ['id' => 11, 'cover_image_id' => 1101, 'cover_image_ids' => null],
        12 => ['id' => 12, 'cover_image_id' => 1201, 'cover_image_ids' => null],
    ];
    $GLOBALS['cover_fixture_children'] = [
        4 => [2, 3],
        5 => [2, 3],
        8 => [9, 3],
    ];

    $ids = static fn(array $images): array => array_map(
        static fn(array $image): int => (int) $image['id'],
        $images,
    );

    cover_selection_assert(
        'automatic',
        gallery_cover_selection_mode($GLOBALS['cover_fixture_galleries'][1]),
        'Explicit automatic mode',
    );
    cover_selection_assert(
        'multi',
        gallery_cover_selection_mode($GLOBALS['cover_fixture_galleries'][2]),
        'Blue selection mode',
    );
    cover_selection_assert(
        'single',
        gallery_cover_selection_mode($GLOBALS['cover_fixture_galleries'][7]),
        'Legacy single-cover mode',
    );
    cover_selection_assert(
        'legacy',
        gallery_cover_selection_mode($GLOBALS['cover_fixture_galleries'][4]),
        'Unconfigured parent legacy behavior',
    );
    cover_selection_assert(
        [201, 205, 202, 203],
        gallery_cover_selection_ids($GLOBALS['cover_fixture_galleries'][2]),
        'Ordered selected IDs',
    );
    cover_selection_assert(
        [],
        gallery_cover_selection_ids(['cover_image_ids' => 'invalid json']),
        'Invalid JSON does not select photos',
    );
    cover_selection_assert(
        false,
        gallery_cover_selection_storage_valid(['cover_image_ids' => '[1,1]']),
        'Duplicate persisted IDs are not exportable as valid state',
    );
    cover_selection_assert(
        false,
        gallery_cover_selection_storage_valid([
            'cover_image_ids' => '[1,2,3,4,5]',
        ]),
        'More than four persisted IDs are not exportable as valid state',
    );

    cover_selection_assert(
        null,
        gallery_cover_image(1, true),
        'Automatic collage does not degrade to single photo',
    );
    cover_selection_assert(
        null,
        gallery_direct_cover_image(9, true),
        'Public direct cover rejects an inaccessible root gallery',
    );
    cover_selection_assert(
        [],
        gallery_cover_collage_images(9, true),
        'Public collage rejects an inaccessible root gallery',
    );
    cover_selection_assert(
        [101, 102],
        $ids(gallery_cover_collage_images(1, true)),
        'Public direct automatic excludes NSFW photos and still returns two',
    );
    cover_selection_assert(
        [105, 101],
        $ids(gallery_cover_collage_images(1, false)),
        'Admin sees all eligible and NSFW direct photos',
    );
    cover_selection_assert(
        [201],
        $ids(gallery_cover_collage_images(2, true)),
        'Private, NSFW or moved selected photos do not leak',
    );
    cover_selection_assert(
        [201, 205, 202],
        $ids(gallery_cover_collage_images(2, false)),
        'Editor sees explicitly selected NSFW and unpublished photos',
    );
    cover_selection_assert(
        [201, 203],
        $ids(gallery_cover_collage_images(4, true)),
        'Legacy parent still borrows child covers',
    );
    cover_selection_assert(
        [201, 203],
        $ids(gallery_cover_collage_images(5, true)),
        'Automatic parent reuses the child collage',
    );
    cover_selection_assert(
        [601, 602],
        $ids(gallery_cover_collage_images(6, true)),
        'Stale multi-choice falls back to two public photos',
    );
    cover_selection_assert(
        701,
        (int) gallery_cover_image(7, true)['id'],
        'Legacy direct cover remains exclusive',
    );
    cover_selection_assert(
        1001,
        (int) gallery_cover_image(10, true)['id'],
        'A moved scalar cover is rejected and replaced with a local direct photo',
    );
    cover_selection_assert(
        1102,
        (int) gallery_cover_image(11, true)['id'],
        'Public single-cover fallback skips NSFW direct photos',
    );
    cover_selection_assert(
        1202,
        (int) gallery_cover_image(12, true)['id'],
        'Public single-cover fallback skips unpublished direct photos',
    );
    cover_selection_assert(
        [203],
        $ids(gallery_cover_collage_images(8, true)),
        'Protected children cannot contribute covers',
    );
    cover_selection_assert(
        false,
        gallery_cover_selection_revision($GLOBALS['cover_fixture_galleries'][1])
        === gallery_cover_selection_revision(
            $GLOBALS['cover_fixture_galleries'][4],
        ),
        'Automatic and legacy refresh fingerprints are distinct',
    );
    $revisionBefore = gallery_cover_selection_revision([
        'cover_image_id' => 701,
        'cover_image_ids' => null,
        'edit_revision' => '12',
    ]);
    $revisionAfter = gallery_cover_selection_revision([
        'cover_image_id' => 701,
        'cover_image_ids' => null,
        'edit_revision' => '13',
    ]);
    cover_selection_assert(
        false,
        $revisionBefore === $revisionAfter,
        'Writer revision changes invalidate an otherwise identical cover selection fingerprint',
    );

    echo "gallery cover selection tests passed\n";
}
