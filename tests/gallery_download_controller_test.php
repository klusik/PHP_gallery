<?php

declare(strict_types=1);

namespace Gallery\Services {
    final class GalleryDownloadManifestException extends \RuntimeException {}

/** Test double for find_gallery(). */
    function find_gallery(int $id): ?array
    {
        return $GLOBALS['gallery_download_controller_galleries'][$id] ?? null;
    }

/** Test double for visitor_can_access_gallery(). */
    function visitor_can_access_gallery(array $gallery): bool
    {
        return !empty($gallery['allowed']);
    }

/** Test double for gallery_download_manifest(). */
    function gallery_download_manifest(array $gallery): array
    {
        if (!empty($GLOBALS['gallery_download_controller_manifest_error'])) {
            throw new GalleryDownloadManifestException('manifest failed safely');
        }
        return $GLOBALS['gallery_download_controller_manifest'];
    }

/** Test double for gallery_download_legacy_manifest_is_safe(). */
    function gallery_download_legacy_manifest_is_safe(array $manifest): bool
    {
        return (int) ($manifest['total_files'] ?? 0) <= 1000
            && (int) ($manifest['total_bytes'] ?? 0) <= 268435456;
    }

/** Test double for gallery_download_authorized_source(). */
    function gallery_download_authorized_source(int $galleryId, int $imageId): ?array
    {
        return $GLOBALS['gallery_download_controller_sources'][$galleryId . ':' . $imageId] ?? null;
    }

/** Test double for build_gallery_zip(). */
    function build_gallery_zip(int $galleryId, bool $publicOnly): string
    {
        $GLOBALS['gallery_download_controller_build_calls']++;
        return '/tmp/unused.zip';
    }

/** Test double for send_download(). */
    function send_download(string $filePath, string $downloadName): void
    {
        $GLOBALS['gallery_download_controller_send_calls']++;
    }

/** Test double for t(). */
    function t(string $key, string $fallback, array $replace = []): string
    {
        return $fallback;
    }

/** Test double for admin_log_event(). */
    function admin_log_event(string $level, string $event, string $message, array $context = [], array $options = []): void {}
/** Test double for build_all_zip(). */
    function build_all_zip(): string { return '/tmp/unused.zip'; }
/** Test double for build_smart_gallery_zip(). */
    function build_smart_gallery_zip(array $gallery): string { return '/tmp/unused.zip'; }
/** Test double for build_selected_images_zip(). */
    function build_selected_images_zip(int $galleryId, array $ids): string { return '/tmp/unused.zip'; }
/** Test double for smart_gallery_effective_presentation(). */
    function smart_gallery_effective_presentation(array $gallery): array { return []; }
/** Test double for smart_gallery_find_public_by_id(). */
    function smart_gallery_find_public_by_id(int $id): ?array { return null; }
/** Test double for smart_gallery_zip_failure_reason(). */
    function smart_gallery_zip_failure_reason(\Throwable $exception): string { return 'test'; }
}

namespace Gallery\Core {
/** Test double for slugify(). */
    function slugify(string $value): string { return 'gallery'; }
/** Test double for require_admin(). */
    function require_admin(): void {}
/** Test double for verify_csrf(). */
    function verify_csrf(): void {}
}

namespace Gallery\Controllers {
/** Test double for cms_not_found(). */
    function cms_not_found(): void
    {
        http_response_code(404);
        echo 'not found';
    }

/** Test double for picture_manager_image_ids_from_post(). */
    function picture_manager_image_ids_from_post(): array { return []; }
/** Test double for picture_manager_require_logged_in_user(). */
    function picture_manager_require_logged_in_user(): void {}
/** Test double for picture_manager_source_gallery_from_post(). */
    function picture_manager_source_gallery_from_post(): array { return []; }

    require_once dirname(__DIR__) . '/app/controllers/downloads.php';
}

namespace Gallery\Tests {
    use function Gallery\Controllers\cms_download_gallery;
    use function Gallery\Controllers\cms_download_gallery_file;
    use function Gallery\Controllers\cms_download_gallery_manifest;

/** Test double for expect(). */
    function expect(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
    }

/** Test double for runRequest(). */
    function runRequest(callable $callback): array
    {
        http_response_code(200);
        header_remove();
        ob_start();
        $callback();
        return [http_response_code(), (string) ob_get_clean()];
    }

    $GLOBALS['gallery_download_controller_galleries'] = [
        1 => ['id' => 1, 'title' => 'Allowed', 'allowed' => true],
        2 => ['id' => 2, 'title' => 'Denied', 'allowed' => false],
    ];
    $GLOBALS['gallery_download_controller_manifest'] = [
        'ok' => true,
        'filename' => 'allowed.zip',
        'files' => [['name' => 'a.jpg', 'size' => 4, 'url' => '/source']],
        'total_files' => 1,
        'total_bytes' => 4,
    ];
    $GLOBALS['gallery_download_controller_manifest_error'] = false;
    $GLOBALS['gallery_download_controller_sources'] = [];
    $GLOBALS['gallery_download_controller_build_calls'] = 0;
    $GLOBALS['gallery_download_controller_send_calls'] = 0;

    $_GET = ['id' => '999'];
    [$status] = runRequest(static fn () => cms_download_gallery_manifest());
    expect($status === 404, 'Unknown gallery manifest must return 404.');

    $_GET = ['id' => '2'];
    [$status] = runRequest(static fn () => cms_download_gallery_manifest());
    expect($status === 404, 'Unauthorized gallery manifest must return 404.');

    $_GET = ['id' => '1'];
    [$status, $body] = runRequest(static fn () => cms_download_gallery_manifest());
    expect($status === 200, 'Authorized gallery manifest must return 200.');
    $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    expect(($decoded['ok'] ?? false) === true && ($decoded['total_bytes'] ?? -1) === 4, 'Authorized manifest JSON must be returned intact.');

    $GLOBALS['gallery_download_controller_manifest_error'] = true;
    $_GET = ['id' => '1'];
    [$status, $body] = runRequest(static fn () => cms_download_gallery_manifest());
    expect($status === 422 && str_contains($body, 'manifest failed safely'), 'Manifest preparation failure must return bounded 422 JSON.');
    $GLOBALS['gallery_download_controller_manifest_error'] = false;

    $GLOBALS['gallery_download_controller_manifest'] = [
        'ok' => true,
        'filename' => 'large.zip',
        'files' => [],
        'total_files' => 1001,
        'total_bytes' => 1,
    ];
    $_GET = ['id' => '1'];
    [$status] = runRequest(static fn () => cms_download_gallery());
    expect($status === 422, 'Oversized legacy download must fail before server ZIP construction.');
    expect($GLOBALS['gallery_download_controller_build_calls'] === 0, 'Oversized legacy download must not call build_gallery_zip().');

    $_GET = ['gallery_id' => '1', 'image_id' => '999', 'v' => 'anything'];
    [$status] = runRequest(static fn () => cms_download_gallery_file());
    expect($status === 404, 'Unknown or unauthorized image source must return 404.');

    $GLOBALS['gallery_download_controller_sources']['1:10'] = [
        'path' => '/tmp/unused.jpg',
        'filename' => 'photo.jpg',
        'size' => 4,
        'version' => 'expected-version',
    ];
    $_GET = ['gallery_id' => '1', 'image_id' => '10', 'v' => 'changed-version'];
    [$status] = runRequest(static fn () => cms_download_gallery_file());
    expect($status === 409, 'Changed source version must return 409 after authorization.');

    $sourceFixture = sys_get_temp_dir() . '/gallery-download-controller-source-' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents($sourceFixture, 'TEST');
    try {
        $GLOBALS['gallery_download_controller_sources']['1:10'] = [
            'path' => $sourceFixture,
            'filename' => 'quoted"\\name.jpg',
            'size' => 4,
            'version' => 'expected-version',
        ];
        $_GET = ['gallery_id' => '1', 'image_id' => '10', 'v' => 'expected-version'];
        [$status, $body] = runRequest(static fn () => cms_download_gallery_file());
        expect($status === 200 && $body === 'TEST', 'Authorized source route must stream the original bytes successfully.');
    } finally {
        @unlink($sourceFixture);
    }

    echo "gallery_download_controller_test: ok\n";
}
