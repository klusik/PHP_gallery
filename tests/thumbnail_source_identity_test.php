<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/thumbnail_source_identity_test.php
 * Module Type: Regression Test
 * Purpose:
 *   Verify canonical and safe legacy derivative source ownership.
 * Responsibilities:
 *   - Keep thumbnail source identities stable and access-safe.
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Notes:
 *   - Preserve existing thumbnails without bulk regeneration.
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Provide e for the thumbnail identity workflow.
     * @param string $value value input for this operation.
     * @return string Result produced by this operation.
     */
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    /**
     * Provide url for for the thumbnail identity workflow.
     * @param string $route route input for this operation.
     * @param array<string,mixed> $parameters parameters input for this operation.
     * @return string Result produced by this operation.
     */
    function url_for(string $route, array $parameters = []): string { return '/' . $route . '?' . http_build_query($parameters); }
    /**
     * Provide image public asset url with version for the thumbnail identity workflow.
     * @param string $url url input for this operation.
     * @param array<string,mixed> $image image input for this operation.
     * @return string Result produced by this operation.
     */
    function image_public_asset_url_with_version(string $url, array $image): string { return $url; }
}

namespace Gallery\Services {
    /**
     * Select explicit display-master fixtures without an image decoder.
     * @param array<string,mixed> $image Disposable source row.
     * @return bool Whether this fixture owns a DNG display master.
     */
    function image_uses_dng_display_derivatives(array $image): bool { return !empty($image['dng_fixture']); }

    /**
     * Return the disposable image-ID-owned display-master path.
     * @param array<string,mixed> $image Disposable source row.
     * @param array<string,mixed> $gallery Owning fixture gallery.
     * @param bool $create Whether a parent directory should be created.
     * @return string Existing fixture display-master path.
     */
    function dng_display_master_abs_path(array $image, array $gallery, bool $create = false): string { return $GLOBALS['identity_fixture_root'] . '/thumbs/display_' . (int) $image['id'] . '.webp'; }
    /**
     * Return current rows from the disposable identity fixture.
     * @param int $id Persisted fixture identifier.
     * @param bool $refresh Request a current row rather than a cached row.
     * @return array<string,mixed>|null Current fixture row or null.
     */
    function find_image(int $id, bool $refresh = false): ?array
    {
        foreach ($GLOBALS['identity_rows'] ?? [] as $row) {
            if ((int) $row['id'] === $id) { return $row; }
        }
        return null;
    }
    /**
     * Provide schema inspection column for the thumbnail identity workflow.
     * @param string $table table input for this operation.
     * @param string $column column input for this operation.
     * @return array<string,mixed> Result produced by this operation.
     */
    function schema_inspection_column(string $table, string $column): array { return ['state' => $GLOBALS['identity_schema_state'] ?? 'available']; }
    /**
     * Provide schema inspection is unknown for the thumbnail identity workflow.
     * @param array<string,mixed> $status status input for this operation.
     * @return bool Result produced by this operation.
     */
    function schema_inspection_is_unknown(array $status): bool { return $status['state'] === 'unknown'; }
    /**
     * Provide schema inspection is available for the thumbnail identity workflow.
     * @param array<string,mixed> $status status input for this operation.
     * @return bool Result produced by this operation.
     */
    function schema_inspection_is_available(array $status): bool { return $status['state'] === 'available'; }
    /**
     * Provide gallery abs path for the thumbnail identity workflow.
     * @param string $path path input for this operation.
     * @return string Result produced by this operation.
     */
    function gallery_abs_path(string $path): string { return $GLOBALS['identity_fixture_root']; }
    /**
     * Provide find gallery for the thumbnail identity workflow.
     * @param int $id id input for this operation.
     * @return array<string,mixed> Result produced by this operation.
     */
    function find_gallery(int $id): array { return ['id' => $id, 'folder_path' => 'fixture']; }
    /**
     * Provide public path schema ready for the thumbnail identity workflow.
     * @return bool Result produced by this operation.
     */
    function public_path_schema_ready(): bool { return false; }
    /**
     * Provide public render profile count for the thumbnail identity workflow.
     * @param string $name name input for this operation.
     * @param int $amount amount input for this operation.
     * @return void Result produced by this operation.
     */
    function public_render_profile_count(string $name, int $amount = 1): void {}
    /**
     * Provide public render profile span for the thumbnail identity workflow.
     * @template T
     * @param string $name name input for this operation.
     * @param callable():T $callback callback input for this operation.
     * @return T Result produced by this operation.
     */
    function public_render_profile_span(string $name, callable $callback): mixed { return $callback(); }
    /**
     * Provide public render profile is file for the thumbnail identity workflow.
     * @param string $path path input for this operation.
     * @return bool Result produced by this operation.
     */
    function public_render_profile_is_file(string $path): bool { return is_file($path); }
    /**
     * Provide public render profile record thumbnail purpose for the thumbnail identity workflow.
     * @param mixed[] $arguments arguments input for this operation.
     * @return void Result produced by this operation.
     */
    function public_render_profile_record_thumbnail_purpose(mixed ...$arguments): void {}
    /**
     * Provide thumbnail policy requested formats for the thumbnail identity workflow.
     * @return array<string,mixed> Result produced by this operation.
     */
    function thumbnail_policy_requested_formats(): array { return ['jpg', 'webp']; }
    /**
     * Provide thumbnail warmup candidate attributes for the thumbnail identity workflow.
     * @param array<string,mixed> $image image input for this operation.
     * @param array<string,mixed> $gallery gallery input for this operation.
     * @param array<int,int> $sizes sizes input for this operation.
     * @return string Result produced by this operation.
     */
    function thumbnail_warmup_candidate_attributes(array $image, array $gallery, array $sizes): string { return ''; }
    /**
     * Provide t for the thumbnail identity workflow.
     * @param string $key key input for this operation.
     * @return string Result produced by this operation.
     */
    function t(string $key): string { return $key; }
}

namespace Gallery\Models {
    /**
     * Emulate a bounded case-insensitive candidate storage query.
     * @param int $galleryId Owning fixture gallery.
     * @param array<int,int> $imageIds Requested source identifiers.
     * @param array<int,string> $effectiveStems Possible shared derivative stems.
     * @param array<int,string> $candidatePathHashes Canonical digest collision candidates.
     * @param bool $hasIdentityVersion Verified marker schema availability.
     * @param int $limit Row limit including an overflow sentinel.
     * @return array<int,array<string,mixed>> Bounded fixture competitors across every access class.
     */
    function image_model_thumbnail_identity_candidates(int $galleryId, array $imageIds, array $effectiveStems, array $candidatePathHashes, bool $hasIdentityVersion, int $limit): array
    {
        $GLOBALS['identity_candidate_calls'][] = ['ids' => $imageIds, 'stems' => $effectiveStems, 'limit' => $limit];
        $result = [];
        foreach ($GLOBALS['identity_rows'] ?? [] as $row) {
            if ((int) ($row['gallery_id'] ?? 0) !== $galleryId) { continue; }
            $selected = in_array((int) $row['id'], $imageIds, true)
                || in_array(hash('sha256', (string) $row['relative_path']), $candidatePathHashes, true);
            foreach ($effectiveStems as $stem) {
                if (preg_match('/^' . preg_quote($stem, '/') . '(?:\\.|$)/iuD', (string) $row['filename']) === 1) { $selected = true; }
            }
            if ($selected) {
                if (!$hasIdentityVersion) { $row['thumbnail_source_identity_version'] = 0; }
                $result[] = $row;
            }
        }
        return array_slice($result, 0, $limit);
    }
}

namespace {
    require_once __DIR__ . '/../app/helpers_files.php';
    require_once __DIR__ . '/../app/services/thumbnail_sources.php';
    require_once __DIR__ . '/../app/services/thumbnail_bundles.php';
    require_once __DIR__ . '/../app/services/thumbnail_html.php';
    require_once __DIR__ . '/../app/services/gallery_mutations.php';
    require_once __DIR__ . '/../app/services/media_renamer.php';

    use function Gallery\Services\thumbnail_abs_path;
    use function Gallery\Services\gallery_image_deletion_derivative_paths;
    use function Gallery\Services\thumbnail_bundle;
    use function Gallery\Services\thumbnail_existing_fallback;
    use function Gallery\Services\thumbnail_filename;
    use function Gallery\Services\thumbnail_picture_html;
    use function Gallery\Services\thumbnail_progressive_picture_html;
    use function Gallery\Services\thumbnail_srcset_for_format;

    /**
     * Provide assert identity for the thumbnail identity workflow.
     * @param bool $condition condition input for this operation.
     * @param string $message message input for this operation.
     * @return void Result produced by this operation.
     */
    function assert_identity(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gallery-thumbnail-identity-' . bin2hex(random_bytes(8));
    $GLOBALS['identity_fixture_root'] = $root;
    mkdir($root . DIRECTORY_SEPARATOR . 'thumbs', 0775, true);
    $gallery = ['id' => 1, 'folder_path' => 'fixture'];
    $public = ['id' => 11, 'gallery_id' => 1, 'filename' => 'foto.jpg', 'relative_path' => 'foto.jpg', 'thumbnail_source_identity_version' => 1];
    $private = ['id' => 12, 'gallery_id' => 1, 'filename' => 'foto.png', 'relative_path' => 'foto.png', 'thumbnail_source_identity_version' => 1];
    $nested = $public + [];
    $nested['id'] = 13;
    $nested['relative_path'] = 'nested/foto.jpg';
    $GLOBALS['identity_rows'] = [$public, $private, $nested];
    $created = [];
    try {
        foreach (['jpg', 'webp'] as $format) {
            $publicName = thumbnail_filename($public, 600, $format);
            assert_identity($publicName !== thumbnail_filename($private, 600, $format), 'Different source extensions must never share derivatives.');
            assert_identity($publicName !== thumbnail_filename($nested, 600, $format), 'Different parent directories must never share derivatives.');
            assert_identity($publicName === thumbnail_filename($public, 600, $format), 'Canonical identity must be stable.');
            assert_identity(str_contains($publicName, hash('sha256', 'foto.jpg')), 'Identity must retain the full source path digest.');
            $normalized = $nested;
            $normalized['relative_path'] = './nested\\foto.jpg';
            assert_identity(thumbnail_filename($normalized, 600, $format) === thumbnail_filename($nested, 600, $format), 'Equivalent separators and dot segments must normalize identically.');
            $long = ['filename' => str_repeat('x', 240) . '.jpg', 'thumbnail_source_identity_version' => 1];
            assert_identity(strlen(\Gallery\Services\thumbnail_canonical_filename_stem($long) . '_thumb1600.' . $format) < 140, 'Derivative filename must be bounded.');
            foreach ([$root . '/thumbs/foto_thumb600.' . $format, thumbnail_abs_path($private, $gallery, 600, $format)] as $path) {
                file_put_contents($path, 'private fixture bytes');
                $created[] = $path;
            }
            assert_identity(!is_file(thumbnail_abs_path($public, $gallery, 600, $format)), 'Public canonical path must not alias private bytes.');
            assert_identity(thumbnail_srcset_for_format($public, [600], $format) === '', 'Responsive srcset must refuse ambiguous historical files.');
        }
        assert_identity(thumbnail_existing_fallback($public, $gallery, 600, 'webp') === null, 'Fallback lookup must refuse historical and sibling derivative files.');
        $bundle = thumbnail_bundle($public);
        assert_identity($bundle['variants']['jpg'] === [] && $bundle['variants']['webp'] === [], 'Shared bundle must expose no sibling or legacy candidates.');
        $htmlByRenderer = [
            'responsive' => thumbnail_picture_html($public, 600, [300, 600, 800], '100vw', 'Public photograph', '', $bundle),
            'progressive' => thumbnail_progressive_picture_html($public, 600, [300, 600, 800], '100vw', '100vw', 'Public photograph', '', $bundle),
        ];
        foreach ($htmlByRenderer as $renderer => $html) {
            assert_identity(str_contains($html, '/media?id=11'), $renderer . ' must retain authorized source fallback.');
            assert_identity(!str_contains($html, '/thumb?') && !str_contains($html, 'srcset='), $renderer . ' must not advertise ambiguous derivatives.');
        }
        $owned = thumbnail_abs_path($public, $gallery, 300, 'webp');
        file_put_contents($owned, 'owned fixture bytes');
        $created[] = $owned;
        $cleanup = gallery_image_deletion_derivative_paths($public, $gallery, $root);
        assert_identity($cleanup === [$owned], 'Image cleanup must preserve same-stem sibling and ambiguous historical derivatives.');
        require_once __DIR__ . '/../app/services/thumbnail_metadata.php';
        $versioned = $public;
        $versioned['thumbnail_derivative_version'] = 7;
        assert_identity(\Gallery\Services\thumbnail_metadata_row_matches_image_source(['derivative_version' => 7], $versioned), 'Existing generation semantics must remain unchanged.');
        $canonicalVersion = 7;
        $versioned['thumbnail_derivative_version']++;
        assert_identity(!\Gallery\Services\thumbnail_metadata_row_matches_image_source(['derivative_version' => $canonicalVersion], $versioned), 'Source replacement must invalidate canonical metadata.');
        $old = ['id' => 20, 'gallery_id' => 1, 'filename' => 'old.jpg', 'relative_path' => 'old.jpg', 'thumbnail_source_identity_version' => 0];
        $GLOBALS['identity_rows'] = [$old, $public, $private];
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        assert_identity(thumbnail_filename($old, 600, 'webp') === 'old_thumb600.webp', 'Verified existing image must preserve its thumbnail name.');
        $projection = $old;
        unset($projection['thumbnail_source_identity_version']);
        assert_identity(thumbnail_filename($projection, 600, 'webp') === 'old_thumb600.webp', 'Projected existing image must resolve persisted identity.');
        $newProjection = $public;
        unset($newProjection['thumbnail_source_identity_version']);
        assert_identity(thumbnail_filename($newProjection, 600, 'webp') === thumbnail_filename($public, 600, 'webp'), 'Projected new image must retain canonical identity.');
        $oldRow = ['status' => 'valid', 'size_px' => 600, 'width' => 600, 'height' => 400, 'derivative_version' => 7];
        $old['width'] = 1200;
        $old['height'] = 800;
        $old['thumbnail_derivative_version'] = 7;
        require_once __DIR__ . '/../app/services/thumbnail_generation.php';
        assert_identity(\Gallery\Services\thumbnail_metadata_row_is_renderable($oldRow, $old), 'Safe old metadata must remain renderable without regeneration.');
        $newSibling = $old;
        $newSibling['id'] = 22;
        $newSibling['filename'] = 'old.png';
        $newSibling['relative_path'] = 'old.png';
        $newSibling['thumbnail_source_identity_version'] = 1;
        $GLOBALS['identity_rows'][] = $newSibling;
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        assert_identity(thumbnail_filename($old, 600, 'webp') === 'old_thumb600.webp', 'New canonical same-stem photo must preserve existing legacy identity.');
        $crafted = $old;
        $crafted['id'] = 23;
        $crafted['filename'] = \Gallery\Services\thumbnail_canonical_filename_stem($newSibling) . '.jpg';
        $crafted['relative_path'] = $crafted['filename'];
        $GLOBALS['identity_rows'][] = $crafted;
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        foreach ([$crafted, $newSibling] as $unsafe) {
            $refused = false;
            try { \Gallery\Services\thumbnail_assert_source_identity_owned($unsafe); } catch (RuntimeException) { $refused = true; }
            assert_identity($refused, 'Effective canonical/legacy filename collision must refuse both owners.');
        }
        $collision = $old;
        $collision['id'] = 21;
        $collision['filename'] = 'old.png';
        $collision['relative_path'] = 'private/old.png';
        $GLOBALS['identity_rows'][] = $collision;
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        assert_identity(!\Gallery\Services\thumbnail_metadata_row_is_renderable($oldRow, $old), 'Private or nested sibling stem must invalidate ambiguous legacy metadata.');
        $GLOBALS['identity_schema_state'] = 'unknown';
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        assert_identity(!\Gallery\Services\thumbnail_metadata_row_is_renderable($oldRow, $old), 'Unknown identity schema must refuse legacy derivatives.');
        assert_identity(\Gallery\Services\thumbnail_metadata_bundle_data($old, $gallery, [600])['warmup_sizes'] === [], 'Refused ownership must not schedule automatic regeneration.');
        $GLOBALS['identity_schema_state'] = 'available';
        foreach ([['Český', 'český'], ['ФОТО', 'фото']] as [$upper, $lower]) {
            $unicode = ['id' => 30, 'gallery_id' => 2, 'filename' => $upper . '.jpg', 'relative_path' => $upper . '.jpg', 'thumbnail_source_identity_version' => 0];
            $GLOBALS['identity_rows'] = [$unicode];
            \Gallery\Services\thumbnail_legacy_identity_cache_clear();
            assert_identity(\Gallery\Services\thumbnail_legacy_identity_owned($unicode), 'Unique Unicode legacy image must retain ownership.');
            $unicodeSibling = $unicode;
            $unicodeSibling['id'] = 31;
            $unicodeSibling['filename'] = $lower . '.png';
            $unicodeSibling['relative_path'] = $lower . '.png';
            $GLOBALS['identity_rows'][] = $unicodeSibling;
            \Gallery\Services\thumbnail_legacy_identity_cache_clear();
            assert_identity(!\Gallery\Services\thumbnail_legacy_identity_owned($unicode), 'Unicode case collision must refuse ambiguous legacy ownership.');
        }
        $renamed = $old;
        $renamed['filename'] = 'renamed.jpg';
        $renamed['relative_path'] = 'renamed.jpg';
        $restricted = $renamed;
        $restricted['id'] = 40;
        $restricted['filename'] = 'renamed.png';
        $restricted['relative_path'] = 'renamed.png';
        $GLOBALS['identity_rows'] = [$renamed, $restricted];
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        $approvedOldPath = $root . '/thumbs/old_thumb300.webp';
        $ambiguousTargetPath = $root . '/thumbs/renamed_thumb300.webp';
        foreach ([$approvedOldPath, $ambiguousTargetPath] as $path) {
            file_put_contents($path, 'fixture derivative bytes');
            $created[] = $path;
        }
        $renameCleanup = \Gallery\Services\media_renamer_cleanup_generated_derivatives([['image' => $old, 'target_image' => $renamed]], $gallery, [$approvedOldPath]);
        assert_identity($renameCleanup['cleaned'] === 1 && !is_file($approvedOldPath), 'Rename cleanup must remove preapproved old paths after their database identity changes.');
        assert_identity(is_file($ambiguousTargetPath), 'Rename cleanup must preserve ambiguous fresh target derivatives.');
        $batch = [];
        for ($index = 1; $index <= 100; $index++) {
            $batch[] = ['id' => 1000 + $index, 'gallery_id' => 3, 'filename' => 'photo' . $index . '.jpg', 'relative_path' => 'photo' . $index . '.jpg', 'thumbnail_source_identity_version' => 1];
        }
        $GLOBALS['identity_rows'] = $batch;
        $projectedBatch = $batch;
        foreach ($projectedBatch as &$entry) { unset($entry['thumbnail_source_identity_version']); }
        unset($entry);
        $GLOBALS['identity_candidate_calls'] = [];
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        \Gallery\Services\thumbnail_source_identity_preload($projectedBatch);
        foreach ($projectedBatch as $entry) {
            assert_identity(\Gallery\Services\thumbnail_legacy_identity_owned($entry), 'Bounded batch must authorize each unique canonical source.');
        }
        assert_identity(count($GLOBALS['identity_candidate_calls']) === 2, 'One hundred projected photos must use one marker query and one ownership query.');
        $staleMarker = $batch[0];
        $staleMarker['thumbnail_source_identity_version'] = 0;
        assert_identity(!\Gallery\Services\thumbnail_legacy_identity_owned($staleMarker), 'A stale supplied marker must not reuse a cached canonical permission.');
        $dense = ['id' => 5000, 'gallery_id' => 4, 'filename' => 'dense.jpg', 'relative_path' => 'dense.jpg', 'thumbnail_source_identity_version' => 0];
        $GLOBALS['identity_rows'] = [$dense];
        for ($index = 1; $index <= 128; $index++) {
            $sibling = $dense;
            $sibling['id'] += $index;
            $sibling['filename'] = 'dense.extension' . $index;
            $sibling['relative_path'] = $sibling['filename'];
            $GLOBALS['identity_rows'][] = $sibling;
        }
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        assert_identity(!\Gallery\Services\thumbnail_legacy_identity_owned($dense), 'Candidate cap overflow must refuse ownership instead of scanning a whole gallery.');
        assert_identity(\Gallery\Services\thumbnail_metadata_bundle_data($dense, $gallery, [600])['warmup_sizes'] === [], 'Candidate overflow must not schedule automatic derivative writes.');
        $sharedPublic = ['id' => 6000, 'gallery_id' => 1, 'filename' => 'shared.jpg', 'relative_path' => 'shared.jpg', 'thumbnail_source_identity_version' => 0];
        $sharedPrivate = $sharedPublic;
        $sharedPrivate['id']++;
        $sharedPrivate['filename'] = 'shared.png';
        $sharedPrivate['relative_path'] = 'shared.png';
        $GLOBALS['identity_rows'] = [$sharedPublic, $sharedPrivate];
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        $sharedPath = thumbnail_abs_path($sharedPrivate, $gallery, 600, 'webp');
        file_put_contents($sharedPath, 'contaminated private pixels');
        $created[] = $sharedPath;
        assert_identity(!\Gallery\Services\thumbnail_legacy_identity_owned($sharedPublic), 'Shared legacy artifact must refuse public reads.');
        $invalidation = gallery_image_deletion_derivative_paths($sharedPrivate, $gallery, $root);
        assert_identity(in_array($sharedPath, $invalidation, true), 'Explicit sibling deletion must collect shared bytes before removing its row.');
        foreach ($invalidation as $path) { unlink($path); }
        $GLOBALS['identity_rows'] = [$sharedPublic];
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        assert_identity(\Gallery\Services\thumbnail_legacy_identity_owned($sharedPublic), 'Remaining legacy photo may become uniquely owned after deletion.');
        assert_identity(!is_file(thumbnail_abs_path($sharedPublic, $gallery, 600, 'webp')), 'Uniqueness transition must never resurrect contaminated private pixels.');
        $GLOBALS['identity_schema_state'] = 'unknown';
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        $refusedInvalidation = false;
        try { gallery_image_deletion_derivative_paths($sharedPublic, $gallery, $root); } catch (RuntimeException) { $refusedInvalidation = true; }
        assert_identity($refusedInvalidation, 'Unknown ownership evidence must refuse deletion before source mutation.');
        $swapPublic = ['id' => 7000, 'gallery_id' => 1, 'filename' => 'swap_public.jpg', 'relative_path' => 'swap_public.jpg', 'thumbnail_source_identity_version' => 0, 'dng_fixture' => true];
        $swapPrivate = ['id' => 7001, 'gallery_id' => 1, 'filename' => 'swap_private.jpg', 'relative_path' => 'swap_private.jpg', 'thumbnail_source_identity_version' => 0];
        $reuse = ['id' => 7002, 'gallery_id' => 1, 'filename' => 'reuse_source.jpg', 'relative_path' => 'reuse_source.jpg', 'thumbnail_source_identity_version' => 0];
        $publicTarget = $swapPublic;
        $publicTarget['filename'] = $swapPrivate['filename'];
        $publicTarget['relative_path'] = $swapPrivate['relative_path'];
        $privateTarget = $swapPrivate;
        $privateTarget['filename'] = $swapPublic['filename'];
        $privateTarget['relative_path'] = $swapPublic['relative_path'];
        $reuseTarget = $reuse;
        $reuseTarget['filename'] = 'stale_orphan.jpg';
        $reuseTarget['relative_path'] = 'stale_orphan.jpg';
        $renameItems = [['image' => $swapPublic, 'target_image' => $publicTarget], ['image' => $swapPrivate, 'target_image' => $privateTarget], ['image' => $reuse, 'target_image' => $reuseTarget]];
        $swapPaths = [];
        foreach ([$swapPublic, $swapPrivate, $reuse, $reuseTarget] as $entry) {
            $path = thumbnail_abs_path($entry, $gallery, 600, 'webp');
            file_put_contents($path, 'old private or stale derivative pixels');
            $created[] = $path;
            $swapPaths[] = $path;
        }
        $displayMaster = \Gallery\Services\dng_display_master_abs_path($swapPublic, $gallery);
        file_put_contents($displayMaster, 'image ID owned display pixels');
        $created[] = $displayMaster;
        $GLOBALS['identity_rows'] = [$swapPublic, $swapPrivate, $reuse];
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        $unknownRenameRefused = false;
        try { \Gallery\Services\media_renamer_preinvalidate_thumbnail_caches($renameItems, $gallery, $root); } catch (RuntimeException) { $unknownRenameRefused = true; }
        assert_identity($unknownRenameRefused, 'Unknown source membership must refuse rename invalidation.');
        foreach ($swapPaths as $path) { assert_identity(is_file($path), 'Unknown membership must refuse before deleting any cache bytes.'); }
        $GLOBALS['identity_schema_state'] = 'available';
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        $approvedSwapPaths = \Gallery\Services\media_renamer_preinvalidate_thumbnail_caches($renameItems, $gallery, $root);
        foreach ($swapPaths as $path) { assert_identity(!is_file($path), 'Old and prospective swap/reuse thumbnail bytes must disappear before row changes.'); }
        assert_identity(is_file($displayMaster), 'Image-ID-owned DNG display master must survive thumbnail pre-invalidation.');
        assert_identity(in_array($displayMaster, $approvedSwapPaths['approved_old_derivatives'], true), 'DNG display master remains approved for ordinary later rename cleanup.');
        assert_identity($approvedSwapPaths['cleaned'] === 4, 'Pre-invalidation must report each distinct removed thumbnail for the completion count.');
        $GLOBALS['identity_rows'] = [$publicTarget, $privateTarget, $reuseTarget];
        \Gallery\Services\thumbnail_legacy_identity_cache_clear();
        foreach ([$publicTarget, $privateTarget, $reuseTarget] as $entry) {
            assert_identity(\Gallery\Services\thumbnail_legacy_identity_owned($entry), 'Post-swap source ownership must be independently verified.');
            assert_identity(!is_file(thumbnail_abs_path($entry, $gallery, 600, 'webp')), 'New public ownership must never inherit another source\'s old bytes.');
        }
        echo "thumbnail_source_identity_test: PASS\n";
    } finally {
        foreach ($created as $path) {
            if (is_file($path)) { unlink($path); }
        }
        rmdir($root . DIRECTORY_SEPARATOR . 'thumbs');
        rmdir($root);
    }
}
