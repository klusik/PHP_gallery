<?php

declare(strict_types=1);

namespace Gallery\Services {
    final class GalleryDownloadManifestException extends \RuntimeException
    {
        /** Return the stable fixture reason expected by the production controller. */
        public function reason(): string { return 'manifest_failed'; }
    }

    /** Minimal capability-validation exception used by the controller fixture. */
    final class DownloadCapabilityException extends \RuntimeException {}

    const DOWNLOAD_CAPABILITY_RESOURCE_GALLERY = 'gallery';
    const DOWNLOAD_CAPABILITY_RESOURCE_SMART_GALLERY = 'smart_gallery';
    const DOWNLOAD_CAPABILITY_SCOPE_PROGRESSIVE = 'progressive';
    const DOWNLOAD_CAPABILITY_SCOPE_LEGACY = 'legacy';

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

/** Test double for build_legacy_gallery_zip(). */
    function build_legacy_gallery_zip(int $galleryId, array $manifest): string
    {
        $GLOBALS['gallery_download_controller_build_calls']++;
        return '/tmp/unused.zip';
    }

/** Test double for send_legacy_download_artifact(). */
    function send_legacy_download_artifact(string $filePath, string $downloadName): void
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
/** Accept the fixture capability while exercising the production transport and scope checks. */
    function download_capability_validate(string $token, string $resourceType, int $resourceId, string $scope): array
    {
        if ($token !== 'valid-capability') {
            throw new DownloadCapabilityException('invalid fixture capability');
        }
        return [];
    }
/** Issue a deterministic fixture capability for confirmation rendering paths. */
    function download_capability_issue(string $resourceType, int $resourceId, string $scope, ?int $issuedAt = null): string { return 'valid-capability'; }
/** Keep manifest profiling outside this controller-only fixture. */
    function download_manifest_profile_begin(string $resourceType, int $resourceId): void {}
/** Keep manifest profiling outside this controller-only fixture. */
    function download_manifest_profile_finish(): void {}
/** Keep manifest profiling outside this controller-only fixture. */
    function download_manifest_profile_emit_headers(): void {}
/** Keep manifest-cache invalidation observable only in dedicated cache tests. */
    function download_manifest_cache_invalidate_source_mismatch(string $resourceType, int $resourceId, string $revision, int $imageId, string $version, int $expectedSize, int $actualSize): bool { return false; }
/** Return a stable generic archive diagnostic for this isolated controller fixture. */
    function gallery_zip_failure_reason(\Throwable $exception): string { return 'test'; }
/** Return a deterministic request identifier for bounded failure diagnostics. */
    function telemetry_request_id(): string { return 'download-controller-test'; }
/** Return a deterministic client address for bounded failure diagnostics. */
    function request_client_ip(): string { return '127.0.0.1'; }
/** Return a deterministic privacy-safe fingerprint for bounded failure diagnostics. */
    function viewer_security_fingerprint(string $purpose, string $value): string { return 'fixture-fingerprint'; }
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
/** Return the fixture request method used by the production download controller. */
    function request_method(): string { return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')); }
/** Return centralized runtime limits without duplicating production numeric policy. */
    function cms_runtime_limit(string $key): int|float
    {
        static $limits = null;
        if ($limits === null) {
            $defaults = require dirname(__DIR__) . '/app/configuration_defaults.php';
            $limits = is_array($defaults['runtime_limits'] ?? null) ? $defaults['runtime_limits'] : [];
        }
        if (!array_key_exists($key, $limits) || !is_numeric($limits[$key])) {
            throw new \InvalidArgumentException('Unknown runtime limit in download controller fixture: ' . $key);
        }
        return $limits[$key] + 0;
    }
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

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['id' => '999', 'capability' => 'valid-capability'];
    [$status] = runRequest(static fn () => cms_download_gallery_manifest());
    expect($status === 404, 'Unknown gallery manifest must return 404.');

    $_GET = ['id' => '2', 'capability' => 'valid-capability'];
    [$status] = runRequest(static fn () => cms_download_gallery_manifest());
    expect($status === 404, 'Unauthorized gallery manifest must return 404.');

    $_GET = ['id' => '1', 'capability' => 'valid-capability'];
    [$status, $body] = runRequest(static fn () => cms_download_gallery_manifest());
    expect($status === 200, 'Authorized gallery manifest must return 200.');
    $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    expect(($decoded['ok'] ?? false) === true && ($decoded['total_bytes'] ?? -1) === 4, 'Authorized manifest JSON must be returned intact.');

    $GLOBALS['gallery_download_controller_manifest_error'] = true;
    $_GET = ['id' => '1', 'capability' => 'valid-capability'];
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
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['id' => '1', 'capability' => 'valid-capability'];
    $_GET = [];
    [$status] = runRequest(static fn () => cms_download_gallery());
    expect($status === 422, 'Oversized legacy POST download must fail before server ZIP construction.');
    expect($GLOBALS['gallery_download_controller_build_calls'] === 0, 'Oversized legacy download must not call build_legacy_gallery_zip().');

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
    $_GET = ['gallery_id' => '1', 'image_id' => '999', 'v' => 'aaaaaaaaaaaaaaaa', 'capability' => 'valid-capability'];
    [$status] = runRequest(static fn () => cms_download_gallery_file());
    expect($status === 404, 'Unknown or unauthorized image source must return 404.');

    $GLOBALS['gallery_download_controller_sources']['1:10'] = [
        'path' => '/tmp/unused.jpg',
        'filename' => 'photo.jpg',
        'size' => 4,
        'version' => 'aaaaaaaaaaaaaaaa',
    ];
    $_GET = ['gallery_id' => '1', 'image_id' => '10', 'v' => 'bbbbbbbbbbbbbbbb', 'capability' => 'valid-capability'];
    [$status] = runRequest(static fn () => cms_download_gallery_file());
    expect($status === 409, 'Changed source version must return 409 after authorization.');

    $sourceFixture = sys_get_temp_dir() . '/gallery-download-controller-source-' . bin2hex(random_bytes(4)) . '.jpg';
    file_put_contents($sourceFixture, 'TEST');
    try {
        $GLOBALS['gallery_download_controller_sources']['1:10'] = [
            'path' => $sourceFixture,
            'filename' => 'quoted"\\name.jpg',
            'size' => 4,
            'version' => 'aaaaaaaaaaaaaaaa',
        ];
        $_GET = ['gallery_id' => '1', 'image_id' => '10', 'v' => 'aaaaaaaaaaaaaaaa', 'capability' => 'valid-capability'];
        [$status, $body] = runRequest(static fn () => cms_download_gallery_file());
        expect($status === 200 && $body === 'TEST', 'Authorized source route must stream the original bytes successfully.');
    } finally {
        @unlink($sourceFixture);
    }

    echo "gallery_download_controller_test: ok\n";
}
