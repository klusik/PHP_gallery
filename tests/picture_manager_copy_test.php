<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/picture_manager_copy_test.php
 * Module Type: Regression Test
 * Purpose: Verify copied photos opt into canonical identities while preserving existing derivative bytes.
 * Responsibilities: Cover source ownership, stale targets, clone mapping and preflight ordering.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Core {
    /** Normalize isolated relative paths. @param string $path Fixture relative path. @return string Normalized path. */
    function normalize_relative_path(string $path): string { return str_replace('\\', '/', trim($path, '/\\')); }
    /** Supply a deterministic fixture timestamp. @return string SQL-shaped time. */
    function now_sql(): string { return '2026-09-30 12:00:00'; }
}
namespace Gallery\Models {
    /** Supply additive image copy columns without SQL. @return list<string> Fixture column names. */
    function picture_manager_model_image_table_columns(): array { return ['id', 'gallery_id', 'filename', 'relative_path', 'relative_path_hash', 'sort_order', 'thumbnail_source_identity_version', 'created_at', 'updated_at']; }
    /** Read independent fixture catalogs. @param int $galleryId Fixture gallery. @param bool $publicOnly Unused selector. @return list<array<string,mixed>> Fixture source rows. */
    function image_model_rows_for_gallery(int $galleryId, bool $publicOnly): array { return $GLOBALS['copy_rows'][$galleryId] ?? []; }
}
namespace Gallery\Services {
    /** Supply one bounded fixture size. @return list<int> Configured size. */
    function thumbnail_sizes(): array { return [32]; }
    /** Observe persisted source naming version. @param array<string,mixed> $image Existing source. @return int Explicit fixture version. */
    function thumbnail_source_identity_version(array $image): int { return $image['thumbnail_source_identity_version']; }
    /** Resolve pure effective naming for provisional comparison without ownership grants.
     * @param array<string,mixed> $image Explicitly versioned fixture row.
     * @return string Legacy or canonical effective stem.
     */
    function thumbnail_filename_stem(array $image): string
    {
        $stem = pathinfo($image['filename'], PATHINFO_FILENAME);
        return $image['thumbnail_source_identity_version'] === 1 ? $stem . '_' . hash('sha256', $image['relative_path']) : $stem;
    }
    /** Observe bounded destination candidates without filtering private images.
     * @param int $galleryId Fixture gallery.
     * @param list<int> $ids Requested source identities.
     * @param list<string> $stems Requested derivative stems.
     * @param int $limit Overflow sentinel bound.
     * @return list<array<string,mixed>>|null Fixture candidates or unknown/overflow refusal.
     */
    function thumbnail_identity_candidates(int $galleryId, array $ids, array $stems, int $limit): ?array
    {
        $GLOBALS['copy_candidate_limit'] = $limit;
        return $GLOBALS['copy_candidate_failure'] ? null : ($GLOBALS['copy_rows'][$galleryId] ?? []);
    }
    /** Resolve isolated galleries. @param string $path Relative fixture folder. @return string Absolute temporary path. */
    function gallery_abs_path(string $path): string { return $GLOBALS['copy_root'] . '/' . $path; }
    /** Build names using the tested naming contract without storage lookup. @param array<string,mixed> $image Source or provisional row. @param array<string,mixed> $gallery Fixture gallery. @param int $size Fixture size. @param string $format Variant format. @return string Absolute variant path. */
    function thumbnail_abs_path(array $image, array $gallery, int $size, string $format): string
    {
        $stem = pathinfo($image['filename'], PATHINFO_FILENAME);
        if ($image['thumbnail_source_identity_version'] === 1) { $stem .= '_' . hash('sha256', $image['relative_path']); }
        return gallery_abs_path($gallery['folder_path']) . '/thumbs/' . $stem . '_thumb' . $size . '.' . $format;
    }
    /** Enforce the fixture source ownership ledger. @param array<string,mixed> $image Existing row. @return void Refuse ambiguous or nonexistent source ownership. */
    function thumbnail_assert_source_identity_owned(array $image): void
    {
        $GLOBALS['copy_asserted_ids'][] = $image['id'];
        if ($image['id'] <= 0 || in_array($image['id'], $GLOBALS['copy_ambiguous'], true)) { throw new \RuntimeException('Ambiguous source.'); }
    }
    /** Enforce temporary gallery boundaries. @param string $root Temporary gallery root. @param string $path Variant path. @return bool Whether the variant remains under its gallery. */
    function thumbnail_path_inside_existing_gallery(string $root, string $path): bool { return str_starts_with($path, $root . '/'); }
    /** Invalidate only the isolated ledger. @return void No persistent state changes. */
    function thumbnail_legacy_identity_cache_clear(): void {}
    /** Capture catalog batches sent to the shared bounded ownership preloader.
     * @param list<array<string,mixed>> $images Already selected source or clone rows.
     * @return void Retain only fixture IDs to verify source and destination coverage.
     */
    function thumbnail_source_identity_preload(array $images): void
    {
        $GLOBALS['copy_preloaded'][] = array_column($images, 'id');
    }
    /** Observe metadata recording without generating or decoding bytes. @param array<string,mixed> $image Existing target row. @param array<string,mixed> $gallery Fixture gallery. @param int $size Fixture size. @param string $format Variant format. @param string $path Existing variant bytes. @param ?string $source Optional original path. @param bool $deleteInvalid Must remain false. @return array<string,mixed> Fixture record outcome. */
    function thumbnail_metadata_record_file(array $image, array $gallery, int $size, string $format, string $path, ?string $source = null, bool $deleteInvalid = false): array
    {
        \copy_check(!$deleteInvalid && is_file($path), 'Clone metadata attempted to replace derivative bytes.');
        $GLOBALS['copy_recorded'][] = ['id' => $image['id'], 'hash' => hash_file('sha256', $path)];
        return ['metadata_written' => true];
    }
}
namespace {
    require_once dirname(__DIR__) . '/app/services/picture_manager.php';
    require_once dirname(__DIR__) . '/app/services/gallery_mutations.php';
    require_once __DIR__ . '/support/module_source.php';
    use Gallery\Services as S;
    /** Assert an isolated copy invariant. @param bool $condition Expected behavior. @param string $message Failure detail. @return void Throw on regression. */
    function copy_check(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
    $root = str_replace('\\', '/', sys_get_temp_dir()) . '/gallery-copy-identity-' . bin2hex(random_bytes(6));
    $GLOBALS['copy_root'] = $root;
    $GLOBALS['copy_asserted_ids'] = [];
    $GLOBALS['copy_ambiguous'] = [];
    $GLOBALS['copy_recorded'] = [];
    $GLOBALS['copy_candidate_failure'] = false;
    $GLOBALS['copy_preloaded'] = [];
    $sourceGallery = ['id' => 1, 'folder_path' => 'source'];
    $targetGallery = ['id' => 2, 'folder_path' => 'target'];
    $source = ['id' => 11, 'gallery_id' => 1, 'filename' => 'photo.jpg', 'relative_path' => 'photo.jpg', 'thumbnail_source_identity_version' => 0];
    $target = $source;
    $target['id'] = 22;
    $target['gallery_id'] = 2;
    $target['thumbnail_source_identity_version'] = 1;
    $GLOBALS['copy_rows'] = [1 => [$source], 2 => [$target]];
    mkdir($root . '/source/thumbs', 0775, true);
    mkdir($root . '/target/thumbs', 0775, true);
    $sourcePath = S\thumbnail_abs_path($source, $sourceGallery, 32, 'jpg');
    $targetPath = S\thumbnail_abs_path($target, $targetGallery, 32, 'jpg');
    file_put_contents($sourcePath, 'existing derivative bytes unchanged');
    $staleLegacy = $root . '/target/thumbs/photo_thumb32.webp';
    file_put_contents($staleLegacy, 'stale unrelated legacy derivative');
    try {
        // Moving a shared legacy artifact must fail before any original is moved.
        $GLOBALS['copy_ambiguous'] = [11];
        $refused = false;
        try { S\gallery_image_derivative_move_paths($source, $sourceGallery, $targetGallery, $root . '/source', $root . '/target'); }
        catch (RuntimeException) { $refused = true; }
        copy_check($refused && is_file($sourcePath) && !is_file($root . '/target/thumbs/photo_thumb32.jpg'),
            'Ambiguous legacy source derivative became movable into a public destination.');
        $GLOBALS['copy_ambiguous'] = [];
        $GLOBALS['copy_rows'][2] = [['id' => 33, 'filename' => 'PHOTO.png', 'relative_path' => 'PHOTO.png', 'thumbnail_source_identity_version' => 0]];
        $refused = false;
        try { S\gallery_image_derivative_move_paths($source, $sourceGallery, $targetGallery, $root . '/source', $root . '/target'); }
        catch (RuntimeException) { $refused = true; }
        copy_check($refused, 'Legacy move ignored a case-insensitive destination owner without existing thumbnails.');
        $GLOBALS['copy_rows'][2] = [];
        $GLOBALS['copy_candidate_failure'] = true;
        $refused = false;
        try { S\gallery_image_derivative_move_paths($source, $sourceGallery, $targetGallery, $root . '/source', $root . '/target'); }
        catch (RuntimeException) { $refused = true; }
        copy_check($refused && $GLOBALS['copy_candidate_limit'] === 65, 'Legacy move accepted unknown/overflow destination ownership.');
        $GLOBALS['copy_candidate_failure'] = false;
        unlink($staleLegacy);
        $GLOBALS['copy_rows'][2] = [['id' => 33, 'filename' => 'photo.png', 'relative_path' => 'photo.png', 'thumbnail_source_identity_version' => 1]];
        $movePlan = S\gallery_image_derivative_move_paths($source, $sourceGallery, $targetGallery, $root . '/source', $root . '/target');
        copy_check(count($movePlan) === 1 && str_ends_with($movePlan[0]['to'], '/photo_thumb32.jpg'),
            'Safe legacy move changed naming or required thumbnail regeneration.');
        $craftedLegacy = ['id' => 44, 'filename' => S\thumbnail_filename_stem($target) . '.png',
            'relative_path' => S\thumbnail_filename_stem($target) . '.png', 'thumbnail_source_identity_version' => 0];
        $GLOBALS['copy_rows'][2] = [$craftedLegacy];
        $refused = false;
        try { S\gallery_image_derivative_move_paths($target, $sourceGallery, $targetGallery, $root . '/source', $root . '/target'); }
        catch (RuntimeException) { $refused = true; }
        copy_check($refused, 'Canonical move ignored a crafted competing legacy effective stem.');
        $GLOBALS['copy_rows'][2] = [['id' => 55, 'filename' => 'other.jpg', 'relative_path' => 'other.jpg', 'thumbnail_source_identity_version' => 2]];
        $refused = false;
        try { S\gallery_image_derivative_move_paths($source, $sourceGallery, $targetGallery, $root . '/source', $root . '/target'); }
        catch (RuntimeException) { $refused = true; }
        copy_check($refused, 'Move accepted a destination candidate with unknown identity version.');
        file_put_contents($staleLegacy, 'stale unrelated legacy derivative');
        $GLOBALS['copy_rows'][2] = [$target];
        $row = S\picture_manager_image_copy_row($source, 2, 10);
        copy_check($row['thumbnail_source_identity_version'] === 1 && !isset($row['id']), 'New image copy inherited legacy naming.');
        $plan = S\picture_manager_image_derivative_copy_paths($source, $sourceGallery, $targetGallery, $root . '/source', $root . '/target');
        copy_check(count($plan) === 1 && $plan[0]['from'] === $sourcePath && $plan[0]['to'] === $targetPath
            && !in_array(0, $GLOBALS['copy_asserted_ids'], true), 'Copy plan asserted a nonexistent target or retained legacy names.');
        $staleCanonical = S\thumbnail_abs_path($target, $targetGallery, 32, 'webp');
        file_put_contents($staleCanonical, 'stale canonical derivative without a source variant');
        $refused = false;
        try { S\picture_manager_image_derivative_copy_paths($source, $sourceGallery, $targetGallery, $root . '/source', $root . '/target'); }
        catch (RuntimeException) { $refused = true; }
        copy_check($refused, 'Missing source variant inherited an existing canonical target.');
        $refused = false;
        try { S\picture_manager_clone_thumbnail_bytes($sourceGallery, $targetGallery); }
        catch (RuntimeException) { $refused = true; }
        copy_check($refused, 'Clone inherited stale canonical bytes without a verified source variant.');
        // The refused clone may have copied its valid JPG before detecting the
        // unowned WebP; production's enclosing clone rollback removes the folder.
        if (is_file($targetPath)) { unlink($targetPath); }
        $GLOBALS['copy_recorded'] = [];
        unlink($staleCanonical);
        $GLOBALS['copy_preloaded'] = [];
        copy_check(S\picture_manager_clone_thumbnail_bytes($sourceGallery, $targetGallery) === 1
            && hash_file('sha256', $sourcePath) === hash_file('sha256', $targetPath)
            && count($GLOBALS['copy_recorded']) === 1 && $GLOBALS['copy_preloaded'] === [[11], [22]],
            'Clone changed existing bytes, omitted metadata or failed to preload source/target ownership.');
        copy_check(S\picture_manager_clone_thumbnail_bytes($sourceGallery, $targetGallery) === 0, 'Identical cloned canonical bytes were treated as a conflict.');
        unlink($targetPath);
        $GLOBALS['copy_ambiguous'] = [11];
        copy_check(S\picture_manager_clone_thumbnail_bytes($sourceGallery, $targetGallery) === 0 && !file_exists($targetPath)
            && is_file($staleLegacy), 'Ambiguous source legacy bytes were promoted into canonical ownership.');
        $service = module_source(dirname(__DIR__) . '/app/services/picture_manager.php');
        $copyStart = strpos($service, 'function copy_gallery_images_owned');
        $preflight = strpos($service, 'upload_ingestion_schema_status()', $copyStart);
        $copyWrite = strpos($service, 'copy((string) $entry', $copyStart);
        copy_check($preflight !== false && $copyWrite !== false && $preflight < $copyWrite, 'New photo source identity preflight follows file copies.');
        $cloneStart = strpos($service, 'function picture_manager_copy_gallery_subtrees_owned');
        copy_check(strpos($service, 'upload_ingestion_schema_status()', $cloneStart) < strpos($service, 'gallery_trash_copy_directory(', $cloneStart), 'Clone source identity preflight follows directory copy.');
        $moveSource = module_source(dirname(__DIR__) . '/app/services/gallery_mutations.php');
        $moveStart = strpos($moveSource, 'function gallery_move_images_locked');
        copy_check(strpos($moveSource, 'gallery_image_derivative_move_paths(', $moveStart)
            < strpos($moveSource, 'gallery_image_move_prepare(', $moveStart)
            && strpos($moveSource, 'gallery_image_move_prepare(', $moveStart)
                < strpos($moveSource, 'gallery_image_move_execute_files(', $moveStart),
            'MOVE source derivative ownership is checked after irreversible file mutation.');
    } finally {
        foreach ([$sourcePath, $targetPath, $staleLegacy] as $file) { if (is_file($file)) { unlink($file); } }
        rmdir($root . '/source/thumbs'); rmdir($root . '/target/thumbs'); rmdir($root . '/source'); rmdir($root . '/target'); rmdir($root);
    }
    echo "PASS picture manager canonical copy identities and byte-preserving clone derivatives\n";
}
