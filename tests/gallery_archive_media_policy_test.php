<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_archive_media_policy_test.php
 * Module Type: Regression Test
 * Purpose: Protect opt-in original-media ZIP preference inheritance.
 * Responsibilities:
 *   - Verify PDF and Music remain archive-disabled without saved preferences
 *   - Cover global, source-gallery and per-item permissive override precedence
 *   - Keep standalone music downloadability separate from archive permission
 *   - Reject corrupt preference maps and deduplicate repeated music originals
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/gallery_archive_media.php';

/**
 * Use an explicit settings snapshot through the production request-local
 * application settings cache without opening a database connection.
 *
 * @param array<string,string> $settings Saved values for the current case.
 * @return void Replace the request's stable, already-primed settings snapshot.
 */
function archive_media_test_settings(array $settings): void
{
    $GLOBALS['cms_app_settings_cache'] = $settings;
    $GLOBALS['cms_app_settings_cache_loaded'] = true;
}

/**
 * Assert an isolated, database-free ZIP policy result.
 *
 * @param bool $condition True only when the real service respected the policy.
 * @param string $message Diagnostic for a failed policy assertion.
 * @return void Throw when the expectation is not met.
 */
function archive_media_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdf = str_repeat('a', 32);
$music = str_repeat('b', 32);
$other = str_repeat('c', 32);
$source = 8;

archive_media_test_settings([]);
archive_media_test_assert(
    !\Gallery\Services\gallery_archive_media_allowed('pdf', $source, $pdf)
        && !\Gallery\Services\gallery_archive_media_allowed(
            'music',
            $source,
            $music,
            true,
        ),
    'Missing upgrade preferences must keep both media types out of ZIPs.',
);

archive_media_test_settings([
    'download_archive_pdf_global' => '1',
    'download_archive_pdf_gallery_8' => '0',
    'download_archive_pdf_items_8' => '{}',
]);
archive_media_test_assert(
    \Gallery\Services\gallery_archive_media_allowed('pdf', $source, $pdf)
        && !\Gallery\Services\gallery_archive_media_allowed(
            'music',
            $source,
            $music,
        ),
    'Global PDF opt-in must override local OFF without enabling Music.',
);

archive_media_test_settings([
    'download_archive_music_global' => '0',
    'download_archive_music_gallery_8' => '1',
    'download_archive_music_items_8' => '{}',
]);
archive_media_test_assert(
    \Gallery\Services\gallery_archive_media_allowed('music', $source, $music)
        && !\Gallery\Services\gallery_archive_media_allowed(
            'music',
            9,
            $music,
        ),
    'Gallery ON must override item OFF, but not an unrelated gallery.',
);

archive_media_test_settings([
    'download_archive_pdf_global' => '0',
    'download_archive_pdf_gallery_8' => '0',
    'download_archive_pdf_items_8' => json_encode(
        [$pdf => true, 'ofp' => true],
        JSON_THROW_ON_ERROR,
    ),
]);
archive_media_test_assert(
    \Gallery\Services\gallery_archive_media_allowed('pdf', $source, $pdf)
        && \Gallery\Services\gallery_archive_media_allowed(
            'pdf',
            $source,
            'ofp',
        )
        && !\Gallery\Services\gallery_archive_media_allowed(
            'pdf',
            $source,
            $other,
        ),
    'Per-original PDF and OFP opt-ins must not authorize adjacent files.',
);

// A higher global preference must not erase saved lower preferences.
archive_media_test_settings([
    'download_archive_pdf_global' => '1',
    'download_archive_pdf_gallery_8' => '0',
    'download_archive_pdf_items_8' => json_encode(
        [$pdf => true],
        JSON_THROW_ON_ERROR,
    ),
]);
archive_media_test_assert(
    \Gallery\Services\gallery_archive_media_allowed('pdf', $source, $other),
    'Global ON must allow eligible originals independently of item settings.',
);
archive_media_test_settings([
    'download_archive_pdf_global' => '0',
    'download_archive_pdf_gallery_8' => '0',
    'download_archive_pdf_items_8' => json_encode(
        [$pdf => true],
        JSON_THROW_ON_ERROR,
    ),
]);
archive_media_test_assert(
    \Gallery\Services\gallery_archive_media_allowed('pdf', $source, $pdf)
        && !\Gallery\Services\gallery_archive_media_allowed(
            'pdf',
            $source,
            $other,
        ),
    'Turning global OFF must reveal, not destroy, retained item preferences.',
);

archive_media_test_settings([
    'download_archive_music_items_8' => json_encode(
        [$music => true],
        JSON_THROW_ON_ERROR,
    ),
]);
archive_media_test_assert(
    \Gallery\Services\gallery_archive_media_allowed(
        'music',
        $source,
        $music,
        false,
    )
        && !\Gallery\Services\gallery_archive_media_allowed(
            'music',
            $source,
            $other,
            true,
        ),
    'ZIP opt-in must remain independent of standalone track downloadability.',
);

archive_media_test_settings([
    'download_archive_pdf_items_8' => '{invalid json',
    'download_archive_music_items_8' => '{"' . $music . '":"true"}',
]);
archive_media_test_assert(
    !\Gallery\Services\gallery_archive_media_allowed('pdf', $source, $pdf)
        && !\Gallery\Services\gallery_archive_media_allowed(
            'music',
            $source,
            $music,
        ),
    'Corrupt or non-boolean item preferences must fail closed.',
);

$duplicated = [
    [
        'gallery' => ['id' => 8],
        'media_id' => $music,
        'digest' => str_repeat('d', 64),
    ],
    [
        'gallery' => ['id' => 9],
        'media_id' => $other,
        'digest' => str_repeat('d', 64),
    ],
    [
        'gallery' => ['id' => 8],
        'media_id' => $pdf,
        'digest' => str_repeat('e', 64),
    ],
];
$unique = \Gallery\Services\gallery_archive_music_deduplicate($duplicated);
archive_media_test_assert(
    count($unique) === 2
        && array_column($unique, 'media_id') === [$music, $pdf],
    'Repeated authorized music bytes must enter a ZIP only once.',
);

// A shared digest can bridge otherwise distinct catalog song IDs.
// Deduplication must be transitive and keep the first allowed representative.
$linkedSongs = [
    [
        'gallery' => ['id' => 8],
        'media_id' => $music,
        'digest' => str_repeat('a', 64),
        'song_id' => 71,
    ],
    [
        'gallery' => ['id' => 9],
        'media_id' => $other,
        'digest' => str_repeat('a', 64),
        'song_id' => 72,
    ],
    [
        'gallery' => ['id' => 10],
        'media_id' => $pdf,
        'digest' => str_repeat('b', 64),
        'song_id' => 72,
    ],
    [
        'gallery' => ['id' => 11],
        'media_id' => str_repeat('f', 32),
        'digest' => str_repeat('c', 64),
        'song_id' => 73,
    ],
];
$unique = \Gallery\Services\gallery_archive_music_deduplicate($linkedSongs);
archive_media_test_assert(
    array_column($unique, 'media_id') === [
        $music,
        str_repeat('f', 32),
    ],
    'Album song IDs and shared content must form a transitive ZIP deduplication union.',
);

echo "gallery_archive_media_policy_test: ok\n";
