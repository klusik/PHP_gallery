<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Execute classic ingestion and client-prepared/server-completion paths through the real decoder policy.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/image_decode_upload_pipeline_test.php
 * Module Type: Regression Test
 * Purpose: Prove predecode refusals preserve accepted originals and prepared variants in upload workflows.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: Tiny disposable images; isolated SQL/scanner/auth/transport seams, no live bootstrap or database.
 */
declare(strict_types=1);

namespace Gallery\Core {
    require_once __DIR__ . '/support/admin_operation_fixture.php';

    /** Supply the current isolated administrator. @return array{id:int,role:string}|null Fixture authentication state. */
    function current_user(): ?array { return $GLOBALS['decode_upload_actor']; }
    /** Report the simulated multipart request verb. @return string Always POST in this fixture. */
    function request_method(): string { return 'POST'; }
    /** Enforce the fixture administrator boundary before classic ingestion. @return void Refuse a missing or non-admin identity. */
    function require_admin(): void
    {
        \decode_upload_expect((current_user()['role'] ?? '') === 'admin', 'Classic fixture reached ingestion without administrator authorization.');
    }
    /** Enforce current fixture CSRF independently of an operation key. @return void Refuse a changed request token. */
    function verify_csrf(): void
    {
        \decode_upload_expect(($_POST['csrf_token'] ?? '') === $_SESSION['csrf_token'], 'Classic fixture reached ingestion without current CSRF.');
    }
    /** Build fixture URLs without reading installation settings. @param string $route Controller route. @param array<string,int|string> $parameters Semantic query values. @return string Local relative URL. */
    function url_for(string $route, array $parameters = []): string { return '/index.php?' . http_build_query(['page' => $route] + $parameters); }
    /** Resolve the fixture's public gallery context. @param array{id:int,...} $gallery Authorized fixture row. @return string Canonical local gallery URL. */
    function gallery_public_url(array $gallery): string { return url_for('gallery', ['id' => $gallery['id']]); }
    /** Normalize only fixture-relative separators. @param string $path Controlled relative filename. @return string Slash-normalized filename. */
    function normalize_relative_path(string $path): string { return str_replace('\\', '/', $path); }
    /** Admit the fixture's PNG upload vocabulary. @param string $path Submitted filename. @return bool Whether the filename has the fixture raster extension. */
    function is_supported_image_path(string $path): bool { return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'png'; }
    /** Keep the executed upload path on ordinary raster processing. @param string $path Submitted filename. @return bool False; RAW delegates are never entered. */
    function is_dng_image_path(string $path): bool { return false; }
    /** Sanitize controlled fixture basenames without application settings. @param string $value Filename stem. @return string Safe lowercase stem. */
    function slugify(string $value): string { return strtolower((string) preg_replace('/[^a-zA-Z0-9-]/', '-', $value)); }
    /** Supply policy defaults and deliberate small-budget overrides. @param string $key Canonical runtime-limit key. @return int Central default or isolated override. */
    function cms_runtime_limit(string $key): int
    {
        $defaults = require __DIR__ . '/../app/configuration_defaults.php';
        return $GLOBALS['decode_upload_limits'][$key] ?? $defaults['runtime_limits'][$key];
    }
    /** Suspend at the real controller's redirect boundary without exiting the test process. @param string $url Completed controller redirect. @return never The request fiber is discarded rather than resumed. */
    function redirect_to(string $url): never
    {
        \Fiber::suspend($url);
        throw new \LogicException('A completed redirect fixture must never resume.');
    }
}

namespace Gallery\Models {
    /** Resolve scanner-produced fixture rows at the real upload model boundary. @param int $galleryId Owning gallery. @param string $pathHash Relative-filename SHA-256. @return array<string,mixed>|null Exact isolated image row. */
    function image_model_find_by_path_hash(int $galleryId, string $pathHash): ?array
    {
        foreach ($GLOBALS['decode_upload_images'] as $row) {
            if ($row['gallery_id'] === $galleryId && hash('sha256', $row['filename']) === $pathHash) { return $row; }
        }
        return null;
    }
}

namespace Gallery\Services {
    /** Format safe fixture messages without language/configuration persistence. @param string $key Translation key. @param string|array<string,string>|null $fallback Default text or replacements. @param array<string,string> $parameters Text substitutions. @return string Interpolated fixture message. */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        $replace = [];
        foreach ($parameters as $name => $value) { $replace['{' . $name . '}'] = $value; }
        return strtr(is_string($fallback) ? $fallback : $key, $replace);
    }
    /** Resolve the single isolated upload destination. @param int $id Requested gallery. @param bool $fresh Cache-bypass request. @return array<string,mixed>|null Current fixture gallery, never live catalog data. */
    function find_gallery(int $id, bool $fresh = false): ?array { return $id === 10 ? $GLOBALS['decode_upload_gallery'] : null; }
    /** Read an image registered by the isolated scanner. @param int $id Stored image ID. @param bool $fresh Requested cache bypass. @return array<string,mixed>|null Fixture row. */
    function find_image(int $id, bool $fresh = false): ?array { return $GLOBALS['decode_upload_images'][$id] ?? null; }
    /** Support real unique-filename selection with fixture row lookup. @param int $galleryId Owning gallery. @param string $path Relative filename. @return array<string,mixed>|null Matching accepted image. */
    function find_image_by_path(int $galleryId, string $path): ?array { return \Gallery\Models\image_model_find_by_path_hash($galleryId, hash('sha256', $path)); }
    /** Resolve storage only inside the unique disposable gallery. @param string $folder Logical fixture folder. @return string Owned gallery directory. */
    function gallery_abs_path(string $folder): string
    {
        \decode_upload_expect($folder === 'gallery', 'Fixture attempted a non-owned gallery directory.');
        return $GLOBALS['decode_upload_root'] . '/gallery';
    }
    /** Resolve accepted originals without opening live storage. @param array{filename:string,...} $image Fixture image row. @param array<string,mixed> $gallery Fixture ownership context. @return string Disposable source path. */
    function image_abs_path(array $image, array $gallery): string { return gallery_abs_path('gallery') . '/' . $image['filename']; }
    /** Model PHP upload provenance for files created by this fixture only. @param string $path Temporary upload source. @return bool Whether an owned upload file exists. */
    function is_uploaded_file(string $path): bool { return str_starts_with($path, $GLOBALS['decode_upload_root'] . '/incoming/') && is_file($path); }
    /** Move tiny fixture uploads at the native transport seam while checking writer ownership. @param string $source Owned temporary upload. @param string $target Owned gallery target. @return bool Result of the isolated filesystem move. */
    function move_uploaded_file(string $source, string $target): bool
    {
        $lockName = hash('sha256', 'disposable_operation_fixture' . \Gallery\Core\GALLERY_EDIT_LOCK_SUFFIX);
        \decode_upload_expect(isset($GLOBALS['operation_fixture_db']->locks[$lockName]), 'Upload file mutation escaped global writer ownership.');
        \decode_upload_expect(is_uploaded_file($source) && str_starts_with(str_replace('\\', '/', $target), gallery_abs_path('gallery') . '/'), 'Upload move escaped disposable storage.');
        ++$GLOBALS['decode_upload_moves'];
        return rename($source, $target);
    }
    /** Register only the files actually stored by the real upload facade. @param int $galleryId Owning fixture gallery. @param list<string> $filenames Accepted relative filenames. @return int Number of isolated scanner rows inserted. */
    function scan_gallery_selected_uploaded_images(int $galleryId, array $filenames): int
    {
        foreach ($filenames as $filename) {
            \decode_upload_expect(is_file(gallery_abs_path('gallery') . '/' . $filename), 'Scanner seam ran before accepted original storage.');
            $id = count($GLOBALS['decode_upload_images']) + 1;
            $GLOBALS['decode_upload_images'][$id] = ['id' => $id, 'gallery_id' => $galleryId, 'filename' => $filename];
        }
        return count($filenames);
    }
    /** Supply verified ingestion schema without a live database. @return array{state:string} Controlled readiness observation. */
    function upload_ingestion_schema_status(): array { return ['state' => 'available']; }
    /** Preserve the schema preflight boundary in the isolated upload path. @param array{state:string} $status Readiness observation. @param string $operation Domain operation. @param string $missing Missing-schema message. @param string $unknown Unknown-schema message. @return void Verify the fixture has explicitly available storage. */
    function mutation_schema_assert_available(array $status, string $operation, string $missing, string $unknown): void { \decode_upload_expect($status['state'] === 'available', 'Unexpected ingestion prerequisite.'); }
    /** Observe thumbnail write preflight without persistence. @param string $operation Operation being checked. @return void Record this executed schema boundary. */
    function thumbnail_metadata_preflight_write_schema(string $operation): void { $GLOBALS['decode_upload_preflights'][] = $operation; }
    /** Resolve only the isolated upload rename preference and supplied fallbacks. @param string $key Domain setting name. @param string $fallback Value for unrelated settings. @return string Disabled fixture renaming or the caller fallback. */
    function app_setting(string $key, string $fallback = ''): string { return $key === 'admin_upload_auto_rename_enabled' ? '0' : $fallback; }
    /** Supply an authoritative isolated completion postcondition. @param array<string,mixed> $gallery Owning gallery. @param bool $public Public-only selector. @param bool $cache Cache selector. @return int Accepted fixture image count. */
    function gallery_lightbox_total_count(array $gallery, bool $public, bool $cache): int { return count($GLOBALS['decode_upload_images']); }
    /** Capture controller diagnostics without writing application logs. @param string $level Severity. @param string $event Event identity. @param string $message Safe event message. @param array<string,mixed> $context Processing details. @param array<string,mixed> $options Optional logging classification. @return void Retain only isolated test diagnostics. */
    function admin_log_event(string $level, string $event, string $message, array $context = [], array $options = []): void { $GLOBALS['decode_upload_logs'][] = ['event' => $event, 'context' => $context]; }
    /** Inject only source-header observations; target geometry always uses the real tiny image. @param string $path Fixture source or derivative. @return array<array-key,mixed>|false Controlled source metadata or native header metadata. */
    function getimagesize(string $path): array|false
    {
        ++$GLOBALS['decode_upload_metadata_reads'];
        $path = str_replace('\\', '/', $path);
        if (str_ends_with($path, '.png') && !str_contains($path, '/incoming/') && $GLOBALS['decode_upload_extreme']) {
            return [24000000, 18000000, 'mime' => 'image/png'];
        }
        return \getimagesize($path);
    }
    /** Count entry into the real PNG decoder and trap forbidden allocations. @param string $path Tiny accepted PNG source. @return \GdImage|false Native small-image result when admission permits. */
    function imagecreatefrompng(string $path): \GdImage|false
    {
        ++$GLOBALS['decode_upload_decoder_calls'];
        \decode_upload_expect(!$GLOBALS['decode_upload_forbid_decoder'], 'Extreme/low-budget upload reached a native decoder.');
        return \imagecreatefrompng($path);
    }
    /** Exclude separate RAW converters from the raster fixture. @param array<string,mixed> $image Accepted fixture row. @return bool Always false for PNG originals. */
    function image_uses_dng_display_derivatives(array $image): bool { return false; }
    /** Limit the real generator to two tiny target sizes. @return list<int> Fixture standard sides. */
    function thumbnail_sizes(): array { return [32, 64]; }
    /** Use the universally tested JPEG target in this fixture. @param string $path Source path. @param string $mime Observed source format. @return list<string> JPEG-only target format. */
    function thumbnail_target_formats_for_source(string $path, string $mime): array { return ['jpg']; }
    /** Omit unrelated WebP policy skips. @param string $path Source path. @param string $mime Source format. @return int No intentionally skipped variants. */
    function thumbnail_intentionally_skipped_webp_count(string $path, string $mime): int { return 0; }
    /** Resolve/create only the disposable thumbnail directory. @param array<string,mixed> $gallery Owning fixture context. @param bool $create Whether the caller needs the directory. @return string Owned derivative directory. */
    function gallery_thumbs_dir(array $gallery, bool $create = false): string
    {
        $path = gallery_abs_path('gallery') . '/thumbs';
        if ($create && !is_dir($path)) { mkdir($path); }
        return $path;
    }
    /** Resolve a fixture derivative by accepted identity and standard side. @param array{id:int,...} $image Stored identity. @param array<string,mixed> $gallery Owning gallery. @param int $size Standard side. @param string $format Target extension. @return string Disposable variant path. */
    function thumbnail_abs_path(array $image, array $gallery, int $size, string $format): string { return gallery_thumbs_dir($gallery) . '/' . $image['id'] . '-' . $size . '.' . $format; }
    /** Keep maintenance invalidation inside the test process. @return void Record invalidation without touching app cache. */
    function thumbnail_maintenance_summary_cache_clear(): void { ++$GLOBALS['decode_upload_invalidations']; }
}

namespace {
    require_once __DIR__ . '/../app/helpers_mutation.php';
    require_once __DIR__ . '/../app/services/uploads.php';
    require_once __DIR__ . '/../app/services/thumbnail_generation.php';
    require_once __DIR__ . '/../app/services/upload_automation.php';
    require_once __DIR__ . '/../app/controllers/admin_uploads.php';
    require_once __DIR__ . '/../app/controllers/admin_galleries_discovery.php';
    require_once __DIR__ . '/../app/controllers/admin_thumbnails.php';

    /** Fail a pipeline invariant without dumping paths or private fixture fields. @param bool $condition Expected behavior. @param string $message Safe diagnostic. @return void Throw on a violated invariant. */
    function decode_upload_expect(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    /** Execute a native-form classic upload until its completed redirect boundary. @param string $name Unique fixture basename. @param bool $thumbnails Whether the real controller requests server derivatives. @return array{response:array<string,mixed>,path:string,original_hash:string} Stored completion and accepted original evidence. */
    function decode_upload_classic(string $name, bool $thumbnails): array
    {
        $incoming = $GLOBALS['decode_upload_root'] . '/incoming/' . $name . '.png';
        imagepng($GLOBALS['decode_upload_pixels'], $incoming);
        $originalHash = hash_file('sha256', $incoming);
        $key = \Gallery\Services\admin_operation_new_key();
        $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'upload_mode' => 'existing', 'gallery_id' => 10,
            'operation_key' => $key, 'create_thumbnails' => $thumbnails ? '1' : '0', 'browser_client_upload' => '0'];
        $_FILES = ['images' => ['name' => [$name . '.png'], 'tmp_name' => [$incoming], 'error' => [UPLOAD_ERR_OK], 'size' => [filesize($incoming)]]];
        $_GET = [];
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'text/html', 'HTTP_HOST' => 'fixture.test'];
        http_response_code(200);
        $request = new Fiber(
            /** Run the real no-JavaScript upload route. @return void The fixture redirect suspends this completed request. */
            static function (): void { \Gallery\Controllers\cms_admin_upload(); }
        );
        $redirect = $request->start();
        decode_upload_expect($request->isSuspended() && is_string($redirect), 'Classic upload did not reach its completed redirect.');
        $row = $GLOBALS['operation_fixture_db']->rows['1:' . hash('sha256', $key)] ?? [];
        $response = json_decode((string) ($row['response_json'] ?? ''), true);
        decode_upload_expect(($row['state'] ?? '') === 'completed' && is_array($response) && ($response['ok'] ?? false), 'Classic upload failed to persist canonical completion before redirect.');
        decode_upload_expect($redirect === $response['redirect_url'] && $GLOBALS['operation_fixture_db']->locks === [], 'Classic upload redirected before durable completion or writer release.');
        decode_upload_expect($response['uploaded'] === 1 && $response['scanned'] === 1, 'Real upload storage/scanner boundary did not accept exactly one original.');
        $path = \Gallery\Services\gallery_abs_path('gallery') . '/' . $response['filenames'][0];
        decode_upload_expect(is_file($path) && hash_file('sha256', $path) === $originalHash, 'Classic generation changed or removed its accepted original.');
        // Discard the suspended request; never resume past the simulated HTTP redirect.
        unset($request);
        return ['response' => $response, 'path' => $path, 'original_hash' => $originalHash];
    }

    /** Execute the authenticated batch endpoint used for requested server completion. @param int $imageId Accepted original with client-prepared derivatives. @param array<string,mixed> $overrides Deliberate invalid-CSRF or scope fixture values. @return array{status:int,response:array<string,mixed>} Actual controller JSON and HTTP status. */
    function decode_upload_completion(int $imageId, array $overrides = []): array
    {
        $_POST = array_replace(['ajax' => '1', 'csrf_token' => $_SESSION['csrf_token'], 'gallery_id' => 10,
            'image_ids' => [$imageId], 'batch_size' => 1, 'offset' => 0], $overrides);
        $_FILES = [];
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => 'application/json'];
        http_response_code(200);
        ob_start();
        try {
            \Gallery\Controllers\cms_admin_create_thumbnails();
            $response = json_decode((string) ob_get_contents(), true);
        } finally {
            ob_end_clean();
        }
        decode_upload_expect(is_array($response), 'Server completion did not return structured controller JSON.');
        return ['status' => http_response_code(), 'response' => $response];
    }

    if (!extension_loaded('gd') || !function_exists('imagepng') || !function_exists('imagejpeg')) {
        echo "SKIP: Upload decoder integration requires GD PNG/JPEG.\n";
        exit(0);
    }

    $root = str_replace('\\', '/', sys_get_temp_dir()) . '/gallery-upload-decode-' . bin2hex(random_bytes(8));
    mkdir($root);
    mkdir($root . '/incoming');
    mkdir($root . '/gallery');
    $GLOBALS['decode_upload_root'] = $root;
    $GLOBALS['operation_fixture_db'] = new \Gallery\Core\AdminOperationFixtureDatabase();
    $GLOBALS['decode_upload_gallery'] = ['id' => 10, 'parent_id' => 0, 'title' => 'Fixture gallery', 'folder_path' => 'gallery'];
    $GLOBALS['decode_upload_actor'] = ['id' => 1, 'role' => 'admin'];
    $GLOBALS['decode_upload_images'] = [];
    $GLOBALS['decode_upload_limits'] = [];
    $GLOBALS['decode_upload_preflights'] = [];
    $GLOBALS['decode_upload_logs'] = [];
    $GLOBALS['decode_upload_moves'] = 0;
    $GLOBALS['decode_upload_metadata_reads'] = 0;
    $GLOBALS['decode_upload_decoder_calls'] = 0;
    $GLOBALS['decode_upload_invalidations'] = 0;
    $GLOBALS['decode_upload_extreme'] = false;
    $GLOBALS['decode_upload_forbid_decoder'] = true;
    $_SESSION = ['csrf_token' => 'isolated-decode-fixture-csrf'];
    \Gallery\Services\schema_inspection_set_query_executor_for_tests(
        /** Observe verified schema through the isolated SQL fixture. @return bool All named ledger prerequisites exist. */
        static fn (): bool => true
    );
    $pixels = imagecreatetruecolor(64, 48);
    imagefilledrectangle($pixels, 0, 0, 63, 47, imagecolorallocate($pixels, 80, 140, 200));
    $GLOBALS['decode_upload_pixels'] = $pixels;

    try {
        foreach (['extreme', 'low-budget', 'ordinary'] as $case) {
            $GLOBALS['decode_upload_extreme'] = $case === 'extreme';
            $GLOBALS['decode_upload_limits'] = $case === 'low-budget' ? ['image_decode.max_memory_bytes' => 1] : [];
            $GLOBALS['decode_upload_forbid_decoder'] = $case !== 'ordinary';
            $calls = $GLOBALS['decode_upload_decoder_calls'];
            $upload = decode_upload_classic($case, true);
            $response = $upload['response'];
            if ($case === 'ordinary') {
                decode_upload_expect($response['thumbnails'] === 2 && $response['thumbnail_failed'] === 0
                    && $GLOBALS['decode_upload_decoder_calls'] === $calls + 1, 'Admitted classic upload did not generate both tiny targets through one real decode.');
            } else {
                decode_upload_expect($response['thumbnails'] === 0 && $response['thumbnail_failed'] === 2
                    && $GLOBALS['decode_upload_decoder_calls'] === $calls, 'Rejected classic upload entered GD or hid required derivative failures.');
                decode_upload_expect($response['thumbnail_failed_filenames'] === [$response['filenames'][0]], 'Classic refusal lost safe partial-success filename feedback.');
                $lastLog = $GLOBALS['decode_upload_logs'][count($GLOBALS['decode_upload_logs']) - 1];
                $reason = $case === 'extreme' ? 'source_decode_dimension_limit' : 'source_decode_memory_budget';
                decode_upload_expect(in_array($reason, $lastLog['context']['thumbnail_errors'] ?? [], true), 'Classic upload did not propagate the actual shared-policy reason.');
            }
        }

        $GLOBALS['decode_upload_extreme'] = false;
        $GLOBALS['decode_upload_limits'] = [];
        $GLOBALS['decode_upload_forbid_decoder'] = true;
        $prepared = decode_upload_classic('prepared-original', false);
        $imageId = $prepared['response']['image_ids'][0];
        $image = $GLOBALS['decode_upload_images'][$imageId];
        $gallery = $GLOBALS['decode_upload_gallery'];
        $incomingPreview = $root . '/incoming/client-preview.jpg';
        $preview = imagecreatetruecolor(32, 24);
        imagecopyresampled($preview, $pixels, 0, 0, 0, 0, 32, 24, 64, 48);
        imagejpeg($preview, $incomingPreview);
        imagedestroy($preview);
        $lease = \Gallery\Services\gallery_edit_writer_begin();
        try {
            $installed = \Gallery\Services\upload_automation_install_client_thumbnails(10, $gallery,
                [['client_id' => 'prepared-photo', 'size_px' => 32, 'format' => 'jpg', 'tmp_name' => $incomingPreview]],
                ['prepared-photo'], $prepared['response']);
        } finally {
            \Gallery\Services\gallery_edit_writer_end($lease);
        }
        decode_upload_expect($installed['installed'] === 1 && $installed['failed'] === 0, 'Real client-prepared thumbnail installation failed.');
        $preparedPath = \Gallery\Services\thumbnail_abs_path($image, $gallery, 32, 'jpg');
        $missingPath = \Gallery\Services\thumbnail_abs_path($image, $gallery, 64, 'jpg');
        $preparedHash = hash_file('sha256', $preparedPath);
        $calls = $GLOBALS['decode_upload_decoder_calls'];
        foreach (['extreme', 'low-budget'] as $case) {
            $GLOBALS['decode_upload_extreme'] = $case === 'extreme';
            $GLOBALS['decode_upload_limits'] = $case === 'low-budget' ? ['image_decode.max_memory_bytes' => 1] : [];
            $result = decode_upload_completion($imageId);
            $body = $result['response'];
            $reason = $case === 'extreme' ? 'source_decode_dimension_limit' : 'source_decode_memory_budget';
            decode_upload_expect($result['status'] === 200 && $body['created'] === 0 && $body['skipped'] === 1
                && $body['failed'] === 1 && in_array($reason, $body['errors'], true), 'Prepared/server completion bypassed policy or lost cached/refused counts.');
            decode_upload_expect($GLOBALS['decode_upload_decoder_calls'] === $calls && !is_file($missingPath)
                && hash_file('sha256', $preparedPath) === $preparedHash && hash_file('sha256', $prepared['path']) === $prepared['original_hash'], 'Refused server completion changed an original/prepared variant or decoded pixels.');
        }

        $reads = $GLOBALS['decode_upload_metadata_reads'];
        $GLOBALS['decode_upload_actor'] = null;
        decode_upload_expect(decode_upload_completion($imageId)['status'] === 403, 'Server completion omitted current administrator authorization.');
        $GLOBALS['decode_upload_actor'] = ['id' => 1, 'role' => 'admin'];
        decode_upload_expect(decode_upload_completion($imageId, ['csrf_token' => 'invalid'])['status'] === 400, 'Server completion omitted current CSRF validation.');
        decode_upload_expect($GLOBALS['decode_upload_metadata_reads'] === $reads && $GLOBALS['decode_upload_decoder_calls'] === $calls, 'Unauthorized completion reached source inspection/decoding.');

        $GLOBALS['decode_upload_extreme'] = false;
        $GLOBALS['decode_upload_limits'] = [];
        $GLOBALS['decode_upload_forbid_decoder'] = false;
        $completed = decode_upload_completion($imageId);
        decode_upload_expect($completed['status'] === 200 && $completed['response']['created'] === 1
            && $completed['response']['skipped'] === 1 && $completed['response']['failed'] === 0
            && $GLOBALS['decode_upload_decoder_calls'] === $calls + 1, 'Admitted server completion did not decode once and fill only the missing prepared target.');
        decode_upload_expect(is_file($missingPath) && hash_file('sha256', $preparedPath) === $preparedHash
            && hash_file('sha256', $prepared['path']) === $prepared['original_hash'], 'Successful completion altered an existing prepared derivative or original.');
        decode_upload_expect($GLOBALS['decode_upload_preflights'] !== [] && $GLOBALS['operation_fixture_db']->locks === [], 'Pipeline lost schema preflight or leaked writer ownership.');
        echo "PASS real classic upload and client-prepared/server-completion decoder admission (tiny GD, isolated SQL/scanner/auth).\n";
    } finally {
        imagedestroy($pixels);
        foreach ([$root . '/gallery/thumbs', $root . '/gallery', $root . '/incoming', $root] as $directory) {
            if (!is_dir($directory)) { continue; }
            foreach (glob($directory . '/*') ?: [] as $path) {
                if (is_file($path)) { unlink($path); }
            }
            rmdir($directory);
        }
    }
}
