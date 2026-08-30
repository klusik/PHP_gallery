<?php

/** Verify canonical full-media URLs keep one route identity across rewrite modes. */

declare(strict_types=1);

namespace Gallery\Services {
    function url_rewrite_should_emit_clean_urls(): bool
    {
        return !empty($GLOBALS['php_gallery_test_clean_urls']);
    }
}

namespace Gallery\Core {
    function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-') ?: 'item';
    }

    function public_path_segment(string $path): string
    {
        return implode('/', array_map('rawurlencode', array_filter(explode('/', trim($path, '/')), static fn (string $part): bool => $part !== '')));
    }

    function public_base_url(): string
    {
        return 'https://gallery.example.test';
    }

    function url_for(string $page, array $params = []): string
    {
        return '/index.php?' . http_build_query(['page' => $page] + $params, '', '&', PHP_QUERY_RFC3986);
    }
}

namespace {
    $root = dirname(__DIR__);
    require $root . '/app/helpers_public_urls.php';

    $image = [
        'id' => 995,
        'url_slug' => 'Photo One',
        'filename' => 'photo-one.jpg',
        'checksum_sha256' => str_repeat('a', 64),
        'modified_at' => '2026-08-30 18:00:00',
        'file_size' => 12345,
        'relative_path_hash' => str_repeat('b', 64),
        'thumbnail_derivative_version' => 4,
    ];
    $gallery = [
        'id' => 23,
        'url_path' => 'nested/gallery',
        'slug' => 'gallery',
        'folder_path' => 'nested/gallery',
        'title' => 'Gallery',
    ];

    $GLOBALS['php_gallery_test_clean_urls'] = false;
    $queryUrl = \Gallery\Core\image_public_media_url($image, $gallery);
    $GLOBALS['php_gallery_test_clean_urls'] = true;
    $cleanUrl = \Gallery\Core\image_public_media_url($image, $gallery);
    $version = \Gallery\Core\image_public_asset_version($image);

    parse_str((string) parse_url($queryUrl, PHP_URL_QUERY), $query);
    parse_str((string) parse_url($cleanUrl, PHP_URL_QUERY), $cleanQuery);

    $failures = [];
    if (($query['page'] ?? '') !== 'public_media') $failures[] = 'Query mode must use public_media.';
    if (($query['public_path'] ?? '') !== 'nested/gallery/photo-one') $failures[] = 'Query mode must keep the canonical public path.';
    if (($query['v'] ?? '') !== $version) $failures[] = 'Query mode must carry the stable cache identity.';
    if (($cleanQuery['v'] ?? '') !== $version) $failures[] = 'Clean mode must carry the same stable cache identity.';
    if ((string) parse_url($cleanUrl, PHP_URL_PATH) !== '/gallery/nested/gallery/photo-one/media') $failures[] = 'Clean mode must keep the canonical media route.';

    $controller = file_get_contents($root . '/app/controllers/public_media.php') ?: '';
    if (str_contains($controller, '$_GET[\'v\']') || str_contains($controller, '$_REQUEST[\'v\']')) {
        $failures[] = 'Media controller must not use v to change authorization or payload semantics.';
    }

    if ($failures !== []) {
        foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
        exit(1);
    }

    echo "PASS: public media cache-version routing invariants\n";
}
