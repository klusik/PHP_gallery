<?php

/**
 * Regression coverage for thumbnail format policy at public metadata boundaries.
 */

declare(strict_types=1);

namespace Gallery\Core {
    final class ThumbnailFormatMetadataStatement
    {
        /** @var array<int,array<string,mixed>> */
        private array $rows;

        /** @param array<int,array<string,mixed>> $rows */
        public function __construct(array $rows)
        {
            $this->rows = $rows;
        }

        /** Record bound parameters for the fake metadata query. */
        public function execute(array $params = []): bool
        {
            $GLOBALS['thumbnail_format_metadata_params'][] = $params;
            return true;
        }

        /** @return array<int,array<string,mixed>> */
        public function fetchAll(): array
        {
            return $this->rows;
        }
    }

    final class ThumbnailFormatMetadataDatabase
    {
        /** @var array<int,array<string,mixed>> */
        public array $rows = [];

        /** Prepare one fake metadata statement and retain its SQL. */
        public function prepare(string $sql): ThumbnailFormatMetadataStatement
        {
            $GLOBALS['thumbnail_format_metadata_queries'][] = $sql;
            return new ThumbnailFormatMetadataStatement($this->rows);
        }
    }

    /** Return the fake metadata database. */
    function db(): ThumbnailFormatMetadataDatabase
    {
        return $GLOBALS['thumbnail_format_metadata_db'];
    }

    /** Escape one HTML test value. */
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Build one deterministic test route. */
    function url_for(string $route, array $params = []): string
    {
        return '/' . $route . '/' . (int) ($params['id'] ?? 0);
    }

    /** Append the deterministic derivative revision to one test URL. */
    function image_public_asset_url_with_version(string $url, array $image): string
    {
        return $url . '?v=' . (int) ($image['derivative_version'] ?? 0);
    }

    /** Build one deterministic public media URL. */
    function image_public_media_url(array $image, array $gallery): string
    {
        return '/media/' . (int) $image['id'];
    }

    /** Build one deterministic public thumbnail URL. */
    function image_public_thumbnail_url(array $image, array $gallery, int $size, string $format): string
    {
        return '/thumb-' . $size . '.' . $format . '?v=' . (int) ($image['derivative_version'] ?? 0);
    }

    /** Build one deterministic public image base URL. */
    function image_public_url(array $image, array $gallery): string
    {
        return '/gallery/photo';
    }
}

namespace Gallery\Services {
    /** Read one in-memory compatibility setting. */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $GLOBALS['thumbnail_format_metadata_settings'])
            ? (string) $GLOBALS['thumbnail_format_metadata_settings'][$key]
            : $default;
    }

    /** Persist one in-memory compatibility setting. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['thumbnail_format_metadata_settings'][$key] = $value;
    }

    /** Return the bounded thumbnail sizes used by this fixture. */
    function thumbnail_sizes(): array
    {
        return [300, 600];
    }

    /** Report durable metadata as available for this fixture. */
    function thumbnail_metadata_schema_ready(): bool
    {
        return true;
    }

    /** Report whether the fake metadata schema has one optional column. */
    function thumbnail_metadata_variant_column_exists(string $column): bool
    {
        return $column === 'derivative_version';
    }

    /** Apply current-revision renderability to a fake metadata row. */
    function thumbnail_metadata_row_is_renderable(array $row, array $image): bool
    {
        return ($row['status'] ?? '') === 'valid'
            && (int) ($row['derivative_version'] ?? 0) === (int) ($image['derivative_version'] ?? 0);
    }

    /** Keep URL generation on the deterministic query-route fixture path. */
    function public_path_schema_ready(): bool
    {
        return false;
    }

    /** Disable clean URL rewriting for this isolated fixture. */
    function url_rewrite_should_emit_clean_urls(): bool
    {
        return false;
    }

    /** Build one deterministic generated-thumbnail serving URL. */
    function thumbnail_serving_url(array $image, array $gallery, int $size, string $format = 'jpg'): string
    {
        return '/thumb-' . $size . '.' . $format . '?v=' . (int) ($image['derivative_version'] ?? 0);
    }

    /** Record any unexpected direct-route filesystem discovery. */
    function thumbnail_abs_path(array $image, array $gallery, int $size, string $format = 'jpg'): string
    {
        $GLOBALS['thumbnail_format_metadata_filesystem_lookups']++;
        if (($GLOBALS['thumbnail_format_generation_root'] ?? '') !== '') {
            return $GLOBALS['thumbnail_format_generation_root'] . DIRECTORY_SEPARATOR . $size . '.' . $format;
        }
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'thumbnail-format-metadata-' . $size . '.' . $format;
    }

    /** Return the isolated source image path used by generation failure tests. */
    function image_abs_path(array $image, array $gallery): string
    {
        return (string) ($GLOBALS['thumbnail_format_generation_source'] ?? '');
    }

    /** Create and return the isolated thumbnail directory. */
    function gallery_thumbs_dir(array $gallery, bool $create = false): string
    {
        $root = (string) ($GLOBALS['thumbnail_format_generation_root'] ?? '');
        if ($create && $root !== '' && !is_dir($root)) {
            mkdir($root, 0775, true);
        }
        return $root;
    }

    /** Keep the generation fixture on the ordinary raster path. */
    function image_uses_dng_display_derivatives(array $image): bool
    {
        return false;
    }

    /** Return active policy formats for the test JPEG source. */
    function thumbnail_target_formats_for_source(string $sourcePath, string $mime): array
    {
        return thumbnail_policy_requested_formats();
    }

    /** Report no intentionally skipped WebP variant in this capable runtime. */
    function thumbnail_intentionally_skipped_webp_count(string $sourcePath, string $mime): int
    {
        return 0;
    }

    /** Bypass database schema setup in the isolated generation fixture. */
    function thumbnail_metadata_preflight_write_schema(string $operation): void
    {
    }

    /** Record metadata invalidation independently for each failed format. */
    function thumbnail_metadata_delete_variant(array|int $image, int $size, string $format): void
    {
        $GLOBALS['thumbnail_format_generation_metadata_deleted'][] = $size . ':' . $format;
    }

    /** Record valid metadata only after a published derivative exists. */
    function thumbnail_metadata_record_file(array $image, array $gallery, int $size, string $format, string $thumbnailPath, ?string $sourcePath = null, bool $deleteInvalid = false): array
    {
        $valid = is_file($thumbnailPath);
        if ($valid) {
            $GLOBALS['thumbnail_format_generation_metadata_valid'][] = $size . ':' . $format;
        }
        return ['status' => $valid ? 'valid' : 'missing', 'valid' => $valid, 'deleted' => false, 'metadata_written' => $valid];
    }

    /** Omit signed warmup data from isolated renderer output. */
    function thumbnail_warmup_candidate_attributes(array $image, array $gallery, array $sizes): string
    {
        return '';
    }

    /** Ignore profiler counters in this isolated regression. */
    function public_render_profile_count(string $name, int $increment = 1): void
    {
    }

    /** Ignore profiler purpose records in this isolated regression. */
    function public_render_profile_record_thumbnail_purpose(?string $purpose, int $size, string $format, string $source): void
    {
    }

    /** Execute one profiled callback directly. */
    function public_render_profile_span(string $name, callable $callback): mixed
    {
        return $callback();
    }

    /** Execute one profiled database callback directly. */
    function public_render_profile_db(string $name, callable $callback): mixed
    {
        return $callback();
    }
}

namespace {
    use Gallery\Core\ThumbnailFormatMetadataDatabase;
    use function Gallery\Services\public_gallery_media_manifest_metadata_bundle;
    use function Gallery\Services\public_gallery_media_manifest_renderable_rows;
    use function Gallery\Services\set_thumbnail_compatibility_mode;
    use function Gallery\Services\thumbnail_picture_html;
    use function Gallery\Services\thumbnail_policy_requested_formats;
    use function Gallery\Services\thumbnail_progressive_picture_html;

    require_once __DIR__ . '/../app/services/thumbnail_compatibility.php';
    require_once __DIR__ . '/../app/services/thumbnail_bundles.php';
    require_once __DIR__ . '/../app/services/public_gallery_media_manifest.php';
    require_once __DIR__ . '/../app/services/thumbnail_html.php';
    require_once __DIR__ . '/../app/services/thumbnail_generation.php';

    /** Throw when one thumbnail format consistency expectation fails. */
    function thumbnail_format_metadata_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /** Render one image through the batched manifest and selected real HTML renderer. */
    function thumbnail_format_metadata_render(array $image, array $gallery, string $renderer): array
    {
        $rows = public_gallery_media_manifest_renderable_rows([(int) $image['id'] => $image], [300 => 300, 600 => 600]);
        $bundle = public_gallery_media_manifest_metadata_bundle($image, $gallery, [300 => 300, 600 => 600], $rows[(int) $image['id']], '/gallery/photo');
        $html = $renderer === 'progressive'
            ? thumbnail_progressive_picture_html($image, 300, [300, 600], '50vw', '50vw', 'Photo', '', $bundle)
            : thumbnail_picture_html($image, 300, [300, 600], '50vw', 'Photo', '', $bundle);
        return ['rows' => $rows, 'bundle' => $bundle, 'html' => $html];
    }

    $GLOBALS['thumbnail_format_metadata_settings'] = [];
    $GLOBALS['thumbnail_format_metadata_queries'] = [];
    $GLOBALS['thumbnail_format_metadata_params'] = [];
    $GLOBALS['thumbnail_format_metadata_filesystem_lookups'] = 0;
    $GLOBALS['thumbnail_format_generation_root'] = '';
    $GLOBALS['thumbnail_format_generation_source'] = '';
    $GLOBALS['thumbnail_format_generation_metadata_deleted'] = [];
    $GLOBALS['thumbnail_format_generation_metadata_valid'] = [];
    $GLOBALS['thumbnail_format_metadata_db'] = new ThumbnailFormatMetadataDatabase();
    $GLOBALS['thumbnail_format_metadata_db']->rows = [
        ['image_id' => 41, 'size_px' => 300, 'format' => 'jpg', 'width' => 300, 'height' => 225, 'status' => 'valid', 'derivative_version' => 9],
        ['image_id' => 41, 'size_px' => 300, 'format' => 'webp', 'width' => 300, 'height' => 225, 'status' => 'valid', 'derivative_version' => 9],
        ['image_id' => 41, 'size_px' => 600, 'format' => 'jpg', 'width' => 600, 'height' => 450, 'status' => 'valid', 'derivative_version' => 9],
        ['image_id' => 41, 'size_px' => 600, 'format' => 'webp', 'width' => 600, 'height' => 450, 'status' => 'valid', 'derivative_version' => 9],
    ];
    $image = ['id' => 41, 'gallery_id' => 7, 'filename' => 'photo.jpg', 'derivative_version' => 9, 'display_width' => 1600, 'display_height' => 1200];
    $gallery = ['id' => 7];

    thumbnail_format_metadata_assert(thumbnail_policy_requested_formats() === ['webp'], 'No stored compatibility setting must default to WebP only.');
    $settingsBeforeOldJpegRequest = $GLOBALS['thumbnail_format_metadata_settings'];
    $oldJpeg = \Gallery\Services\thumbnail_ensure_image_thumbnail_variant_file($image, $gallery, 300, 'jpg');
    thumbnail_format_metadata_assert($oldJpeg === null, 'An old JPEG URL must be unavailable in modern mode.');
    thumbnail_format_metadata_assert($GLOBALS['thumbnail_format_metadata_filesystem_lookups'] === 0, 'An old JPEG URL must be rejected before filesystem discovery or generation in modern mode.');
    thumbnail_format_metadata_assert($GLOBALS['thumbnail_format_metadata_settings'] === $settingsBeforeOldJpegRequest, 'An old JPEG URL must not mutate compatibility settings.');
    foreach (['responsive', 'progressive'] as $renderer) {
        $modern = thumbnail_format_metadata_render($image, $gallery, $renderer);
        thumbnail_format_metadata_assert(str_contains($modern['html'], '.webp?v=9'), ucfirst($renderer) . ' modern markup must keep valid WebP candidates.');
        thumbnail_format_metadata_assert(!str_contains($modern['html'], '.jpg?v=9'), ucfirst($renderer) . ' modern markup advertised a stale generated JPEG candidate.');
        thumbnail_format_metadata_assert(($modern['bundle']['variants']['jpg'] ?? []) === [], ucfirst($renderer) . ' modern manifest bundle retained historical JPEG metadata.');
    }

    $historicalJpeg = tempnam(sys_get_temp_dir(), 'gallery-historical-jpeg-');
    thumbnail_format_metadata_assert(is_string($historicalJpeg), 'Historical JPEG fixture could not be created.');
    file_put_contents($historicalJpeg, 'historical generated jpeg');
    $modernWithFile = thumbnail_format_metadata_render($image, $gallery, 'responsive');
    thumbnail_format_metadata_assert(!str_contains($modernWithFile['html'], '.jpg?v=9'), 'A historical physical JPEG must not override modern format policy.');
    @unlink($historicalJpeg);

    set_thumbnail_compatibility_mode('legacy');
    foreach (['responsive', 'progressive'] as $renderer) {
        $legacy = thumbnail_format_metadata_render($image, $gallery, $renderer);
        thumbnail_format_metadata_assert(str_contains($legacy['html'], '.jpg?v=9'), ucfirst($renderer) . ' legacy markup must retain JPEG compatibility candidates.');
        thumbnail_format_metadata_assert(str_contains($legacy['html'], '.webp?v=9'), ucfirst($renderer) . ' legacy markup must retain WebP candidates.');
    }

    $generationRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'thumbnail-format-generation-' . bin2hex(random_bytes(6));
    mkdir($generationRoot, 0775, true);
    $generationSource = $generationRoot . DIRECTORY_SEPARATOR . 'source.jpg';
    $sourceImage = imagecreatetruecolor(32, 24);
    imagefilledrectangle($sourceImage, 0, 0, 31, 23, imagecolorallocate($sourceImage, 30, 120, 180));
    imagejpeg($sourceImage, $generationSource, 90);
    imagedestroy($sourceImage);
    $GLOBALS['thumbnail_format_generation_root'] = $generationRoot;
    $GLOBALS['thumbnail_format_generation_source'] = $generationSource;

    mkdir($generationRoot . DIRECTORY_SEPARATOR . '300.jpg');
    $GLOBALS['thumbnail_format_generation_metadata_deleted'] = [];
    $GLOBALS['thumbnail_format_generation_metadata_valid'] = [];
    $legacyFailure = \Gallery\Services\create_image_thumbnails_result($image, $gallery, [300], ['prefer_imagick_webp_exif' => false]);
    $legacyWebpExists = is_file($generationRoot . DIRECTORY_SEPARATOR . '300.webp');
    $legacyMetadataValid = $GLOBALS['thumbnail_format_generation_metadata_valid'];
    $legacyMetadataDeleted = $GLOBALS['thumbnail_format_generation_metadata_deleted'];
    @unlink($generationRoot . DIRECTORY_SEPARATOR . '300.webp');
    @rmdir($generationRoot . DIRECTORY_SEPARATOR . '300.jpg');
    thumbnail_format_metadata_assert(in_array('jpg_write_failed', $legacyFailure['errors'], true), 'Legacy JPEG publication failure must be reported.');
    thumbnail_format_metadata_assert($legacyWebpExists, 'Legacy JPEG publication failure must not invalidate successful WebP publication.');
    thumbnail_format_metadata_assert(in_array('300:webp', $legacyMetadataValid, true), 'Successful legacy WebP publication must become valid metadata.');
    thumbnail_format_metadata_assert(!in_array('300:jpg', $legacyMetadataValid, true), 'Failed legacy JPEG publication must not become valid metadata.');
    thumbnail_format_metadata_assert(in_array('300:jpg', $legacyMetadataDeleted, true), 'Failed legacy JPEG publication must invalidate JPEG metadata.');

    set_thumbnail_compatibility_mode('modern');
    mkdir($generationRoot . DIRECTORY_SEPARATOR . '300.webp');
    $GLOBALS['thumbnail_format_generation_metadata_deleted'] = [];
    $GLOBALS['thumbnail_format_generation_metadata_valid'] = [];
    $modernSettingsBeforeFailure = $GLOBALS['thumbnail_format_metadata_settings'];
    $modernFailure = \Gallery\Services\create_image_thumbnails_result($image, $gallery, [300], ['prefer_imagick_webp_exif' => false]);
    $modernJpegExists = is_file($generationRoot . DIRECTORY_SEPARATOR . '300.jpg');
    $modernMetadataValid = $GLOBALS['thumbnail_format_generation_metadata_valid'];
    @rmdir($generationRoot . DIRECTORY_SEPARATOR . '300.webp');
    @unlink($generationSource);
    @rmdir($generationRoot);
    $GLOBALS['thumbnail_format_generation_root'] = '';
    $GLOBALS['thumbnail_format_generation_source'] = '';
    thumbnail_format_metadata_assert($modernFailure['target_formats'] === ['webp'], 'Modern WebP failure must keep a WebP-only target set.');
    thumbnail_format_metadata_assert(in_array('webp_write_failed', $modernFailure['errors'], true), 'Modern WebP publication failure must be reported.');
    thumbnail_format_metadata_assert(!$modernJpegExists, 'Modern WebP publication failure must not create a JPEG fallback.');
    thumbnail_format_metadata_assert(!in_array('300:jpg', $modernMetadataValid, true), 'Modern WebP publication failure must not create valid JPEG metadata.');
    thumbnail_format_metadata_assert($GLOBALS['thumbnail_format_metadata_settings'] === $modernSettingsBeforeFailure, 'Modern WebP publication failure must not change compatibility mode.');

    thumbnail_format_metadata_assert(count($GLOBALS['thumbnail_format_metadata_queries']) === 5, 'Each manifest render must keep one batched metadata query.');
    echo "Thumbnail format metadata consistency tests passed.\n";
}
