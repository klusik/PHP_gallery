<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Exercise replay, actor binding and original completion responses with isolated SQL and mutation seams.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_operation_keys_test.php
 * Module Type: Regression Test
 * Purpose: Verify create/classic-upload replay through real controllers, service, and model.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: SQL, authentication, and domain mutation seams are isolated; no live database or config.
 */
declare(strict_types=1);

namespace Gallery\Core {
    require_once __DIR__ . '/support/admin_operation_fixture.php';

    /**
     * Current fixture administrator, independent of ledger rows.
     *
     * @return array<string,mixed>|null Current fixture administrator, independent of ledger rows.
     */
    function current_user(): ?array { return $GLOBALS['operation_fixture_actor']; }
    /**
     * Current synthetic HTTP request method.
     *
     * @return string Current synthetic HTTP request method.
     */
    function request_method(): string { return $_SERVER['REQUEST_METHOD'] ?? 'GET'; }
    /**
     * Refuse the fixture request before ledger access unless currently admin.
     *
     * @return void Refuse the fixture request before ledger access unless currently admin.
     */
    function require_admin(): void
    {
        if (!current_user() || current_user()['role'] !== 'admin') {
            http_response_code(401);
            throw new \RuntimeException('fixture_auth_refused');
        }
    }
    /**
     * Require the current fixture CSRF value independently of the operation key.
     *
     * @return void Require the current fixture CSRF value independently of the operation key.
     */
    function verify_csrf(): void
    {
        if (($_POST['csrf'] ?? '') !== $GLOBALS['operation_fixture_csrf']) {
            http_response_code(403);
            throw new \RuntimeException('fixture_csrf_refused');
        }
    }
    /**
     * Deterministic local URL.
     *
     * @param string $route Named application route.
     * @param array<string,mixed> $params Query values.
     * @return string Deterministic local URL.
     */
    function url_for(string $route, array $params = []): string { return '/?' . http_build_query(['r' => $route] + $params); }
    /**
     * Stable local public URL.
     *
     * @param array<string,mixed> $gallery Fixture gallery.
     * @return string Stable local public URL.
     */
    function gallery_public_url(array $gallery): string { return '/index.php?page=gallery&public_path=fixture-' . $gallery['id']; }
    /**
     * Escaped fixture markup.
     *
     * @param scalar|\Stringable|null $value Fixture presentation value converted to escaped text.
     * @return string Escaped fixture markup.
     */
    function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
    /**
     * Fixture CSRF form field; it must not enter ledger payloads.
     *
     * @return string Fixture CSRF form field; it must not enter ledger payloads.
     */
    function csrf_field(): string { return '<input name="csrf" value="fixture-csrf-secret">'; }
    /**
     * Render a minimal fixture document start.
     *
     * @param string $title Page title.
     * @return void Render a minimal fixture document start.
     */
    function render_header(string $title): void { echo '<main>'; }
    /**
     * Close a minimal fixture document.
     *
     * @return void Close a minimal fixture document.
     */
    function render_footer(): void { echo '</main>'; }
}

namespace Gallery\Services {
    /**
     * Controlled ingestion prerequisite observation.
     *
     * @return array<string,mixed> Controlled ingestion prerequisite observation.
     */
    function upload_ingestion_schema_status(): array { return ['state' => $GLOBALS['operation_fixture_ingestion_state'] ?? 'available']; }
    /**
     * Refuse before combined creation in the fixture.
     *
     * @param array<string,mixed> $status Prerequisite observation.
     * @param string $operation Bounded operation name.
     * @param string $missing Missing explanation.
     * @param string $unknown Unknown explanation.
     * @return void Refuse before combined creation in the fixture.
     */
    function mutation_schema_assert_available(array $status, string $operation, string $missing, string $unknown): void
    {
        if ($status['state'] !== 'available') { throw new \RuntimeException('fixture ingestion unavailable'); }
    }
    /**
     * Represent verified thumbnail metadata without loading live storage.
     *
     * @param string $operation Bounded preflight identity.
     * @return void Represent verified thumbnail metadata without loading live storage.
     */
    function thumbnail_metadata_preflight_write_schema(string $operation): void {}
    /**
     * Simulated global writer lease, without production SQL or filesystem access.
     *
     * @return string Simulated global writer lease, without production SQL or filesystem access.
     */
    function gallery_edit_writer_begin(): string
    {
        ++$GLOBALS['operation_fixture_writer_depth'];
        ++$GLOBALS['operation_fixture_writer_begins'];
        if (!empty($GLOBALS['operation_fixture_change_on_lock'])) {
            $id = $GLOBALS['operation_fixture_change_on_lock'];
            $GLOBALS['operation_fixture_galleries'][$id]['title'] = 'Current after writer acquisition';
            $GLOBALS['operation_fixture_change_on_lock'] = null;
        }
        return 'fixture-writer';
    }
    /**
     * Verify paired release.
     *
     * @param string $name Exact simulated writer lease.
     * @return void Verify paired release.
     */
    function gallery_edit_writer_end(string $name): void
    {
        \operation_assert($name === 'fixture-writer' && $GLOBALS['operation_fixture_writer_depth'] > 0, 'Writer lease was released without ownership.');
        --$GLOBALS['operation_fixture_writer_depth'];
    }
    /**
     * Safe fixture translation.
     *
     * @param string $key Translation key.
     * @param string|array|null $fallback Optional fallback.
     * @param array<string,mixed> $parameters Placeholder values.
     * @return string Safe fixture translation.
     */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string { return is_string($fallback) ? $fallback : $key; }
    /**
     * Fixture catalog row.
     *
     * @param int $id Gallery identity.
     * @param bool $fresh Request uncached data.
     * @return array<string,mixed>|null Fixture catalog row.
     */
    function find_gallery(int $id, bool $fresh = false): ?array
    {
        if (($GLOBALS['operation_fixture_writer_depth'] ?? 0) > 0) {
            \operation_assert($fresh, 'Controller read a cached gallery under upload ownership.');
        }
        return $GLOBALS['operation_fixture_galleries'][$id] ?? null;
    }
    /**
     * Fixture image row.
     *
     * @param int $id Image identity.
     * @param bool $fresh Request uncached data.
     * @return array<string,mixed>|null Fixture image row.
     */
    function find_image(int $id, bool $fresh = false): ?array { return $GLOBALS['operation_fixture_images'][$id] ?? null; }
    /**
     * Fixture canonical visibility.
     *
     * @param string $value Submitted visibility.
     * @return string Fixture canonical visibility.
     */
    function gallery_visibility_storage_value(string $value): string { return $value; }
    /**
     * Public fixture membership.
     *
     * @param array<string,mixed> $gallery Fixture gallery.
     * @return bool Public fixture membership.
     */
    function gallery_is_public_listed(array $gallery): bool { return ($gallery['visibility'] ?? '') === 'public'; }
    /**
     * Constant fixture predicate.
     *
     * @param string $alias Model query alias.
     * @return string Constant fixture predicate.
     */
    function public_gallery_listing_sql_fragment(string $alias): string { return '1=1'; }
    /**
     * Authoritative fixture membership count, without SQL in the helper/controller.
     *
     * @param int $parentGalleryId Public context identity.
     * @return int Authoritative fixture membership count, without SQL in the helper/controller.
     */
    function gallery_mutation_context_count(int $parentGalleryId): int
    {
        $count = 0;
        foreach ($GLOBALS['operation_fixture_galleries'] as $gallery) {
            $count += (int) ($gallery['parent_id'] ?? 0) === $parentGalleryId ? 1 : 0;
        }
        return $count;
    }
    /**
     * Capture isolated diagnostics.
     *
     * @param string $level Log level.
     * @param string $event Event name.
     * @param string $message Safe message.
     * @param array<string,mixed> $details Event data.
     * @return void Capture isolated diagnostics.
     */
    function admin_log_event(string $level, string $event, string $message, array $details = []): void { $GLOBALS['operation_fixture_logs'][] = $details; }
    /**
     * Newly created fixture catalog row.
     *
     * @param array<string,mixed> $input Creation fields.
     * @return array<string,mixed> Newly created fixture catalog row.
     */
    function create_empty_gallery(array $input): array
    {
        if (!empty($GLOBALS['operation_fixture_expect_writer'])) {
            \operation_assert($GLOBALS['operation_fixture_writer_depth'] > 0, 'Combined upload created its gallery before taking the outer writer lease.');
        }
        $id = 1000 + ++$GLOBALS['operation_fixture_create_count'];
        return $GLOBALS['operation_fixture_galleries'][$id] = ['id' => $id, 'parent_id' => (int) ($input['parent_id'] ?? 0), 'folder_path' => 'fixture-' . $id, 'title' => (string) $input['title'], 'visibility' => $input['visibility'] ?? 'public'];
    }
    /**
     * Already-validated disposable entries.
     *
     * @param array<mixed>|null $files Fixture multipart payload.
     * @return list<array<string,mixed>> Already-validated disposable entries.
     */
    function gallery_upload_entries(?array $files): array { return $files['entries'] ?? []; }
    /**
     * Disposable entries or empty create-only input.
     *
     * @param array<mixed>|null $files Optional fixture multipart payload.
     * @return list<array<string,mixed>> Disposable entries or empty create-only input.
     */
    function gallery_upload_entries_or_empty(?array $files): array { return gallery_upload_entries($files); }
    /**
     * Stable upload identities and counts.
     *
     * @param int $galleryId Fixture destination.
     * @param list<array<string,mixed>> $entries Disposable input files.
     * @return array<string,mixed> Stable upload identities and counts.
     */
    function store_uploaded_gallery_images(int $galleryId, array $entries): array
    {
        \operation_assert($GLOBALS['operation_fixture_writer_depth'] > 0, 'Upload file mutation ran outside the writer lease.');
        $ids = [];
        $filenames = [];
        foreach ($entries as $entry) {
            $id = 2000 + ++$GLOBALS['operation_fixture_upload_count'];
            $filename = 'stored-' . $id . '.jpg';
            copy($entry['tmp_name'], $GLOBALS['operation_fixture_root'] . '/' . $filename);
            $GLOBALS['operation_fixture_images'][$id] = ['id' => $id, 'gallery_id' => $galleryId];
            $ids[] = $id;
            $filenames[] = $filename;
        }
        if (!empty($GLOBALS['operation_fixture_upload_throw'])) {
            throw new \RuntimeException('/private/file?token=fixture-secret');
        }
        return ['uploaded' => count($ids), 'scanned' => count($ids), 'image_ids' => $ids, 'filenames' => $filenames, 'upload_events' => [['error' => 'private diagnostic fixture-secret']]];
    }
    /**
     * Current fixture image count.
     *
     * @param array<string,mixed> $gallery Fixture gallery.
     * @param bool $public Public-only filter.
     * @param bool $cache Cache selector.
     * @return int Current fixture image count.
     */
    function gallery_lightbox_total_count(array $gallery, bool $public, bool $cache): int
    {
        $count = 0;
        foreach ($GLOBALS['operation_fixture_images'] as $image) {
            $count += $image['gallery_id'] === $gallery['id'] ? 1 : 0;
        }
        return $count;
    }
}

namespace Gallery\Controllers {
    /**
     * Synthetic selected gallery.
     *
     * @param string $key Query parameter.
     * @return int Synthetic selected gallery.
     */
    function selected_gallery_id_from_query(string $key): int { return (int) ($_GET[$key] ?? 0); }
    /**
     * Minimal fixture option markup.
     *
     * @param string $value Selected visibility.
     * @return string Minimal fixture option markup.
     */
    function visibility_options(string $value): string { return '<option value="public">Public</option>'; }
    /**
     * Bounded create-form presentation fixture.
     *
     * @param string $entityType Editor domain.
     * @return array<string,mixed> Bounded create-form presentation fixture.
     */
    function admin_gallery_form_view_model(string $entityType): array { return ['date' => ['schema_ready' => false], 'count_badge' => ['schema_ready' => false]]; }
    /**
     * Prepared bounded parent-picker fixture.
     *
     * @param int $selectedId Committed parent.
     * @return string Prepared bounded parent-picker fixture.
     */
    function render_gallery_parent_picker(int $selectedId): string { return '<div data-fixture-parent-picker><input name="parent_id" value="' . $selectedId . '"></div>'; }
}

namespace {
    use Gallery\Core\AdminOperationFixtureDatabase;
    use Gallery\Services\AdminOperationRefusal;
    use function Gallery\Services\admin_operation_begin;
    use function Gallery\Services\admin_operation_complete;
    use function Gallery\Services\admin_operation_fail;
    use function Gallery\Services\admin_operation_fingerprint;
    use function Gallery\Services\admin_operation_form_key;
    use function Gallery\Services\admin_operation_new_key;
    use function Gallery\Services\admin_operation_reconcile_completed;
    use function Gallery\Services\admin_operation_release;
    use function Gallery\Services\admin_operation_refresh_url;
    use function Gallery\Services\schema_inspection_set_query_executor_for_tests;

    require_once __DIR__ . '/../app/helpers_mutation.php';
    require_once __DIR__ . '/../app/controllers/admin_galleries_discovery.php';
    require_once __DIR__ . '/../app/controllers/admin_uploads.php';
    require_once __DIR__ . '/../app/views/admin_gallery_forms.php';
    require_once __DIR__ . '/../app/views/admin_gallery_discovery.php';

    /**
     * Fail a meaningful contract assertion.
     *
     * @param bool $condition Expected result.
     * @param string $message Safe diagnostic.
     * @return void Fail a meaningful contract assertion.
     */
    function operation_assert(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    /**
     * Simulated metadata observation without accessing a database.
     *
     * @return bool Simulated metadata observation without accessing a database.
     */
    function operation_schema_probe(): bool
    {
        if ($GLOBALS['operation_fixture_schema'] === 'unknown') { throw new RuntimeException('private schema error'); }
        return $GLOBALS['operation_fixture_schema'] === 'available';
    }

    /**
     * Actual controller output/status.
     *
     * @param string $controller Real controller entry point.
     * @param array<string,mixed> $post Submitted form.
     * @param list<array<string,mixed>> $files Disposable entries.
     * @param bool $ajax Request JSON.
     * @return array<string,mixed> Actual controller output/status.
     */
    function operation_request(string $controller, array $post, array $files = [], bool $ajax = true): array
    {
        $_POST = array_replace(['csrf' => $GLOBALS['operation_fixture_csrf']], $ajax ? ['ajax' => 1] : [], $post);
        $_GET = [];
        $_FILES = ['images' => ['entries' => $files]];
        $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_ACCEPT' => $ajax ? 'application/json' : 'text/html', 'HTTP_HOST' => 'fixture.test'];
        http_response_code(200);
        $GLOBALS['operation_fixture_expect_writer'] = $controller === 'Gallery\\Controllers\\cms_admin_upload';
        $level = ob_get_level();
        ob_start();
        try {
            $controller();
            $body = (string) ob_get_clean();
            return ['status' => http_response_code(), 'body' => $body, 'json' => json_decode($body, true)];
        } catch (RuntimeException $exception) {
            if (!in_array($exception->getMessage(), ['fixture_auth_refused', 'fixture_csrf_refused'], true)) { throw $exception; }
            return ['status' => http_response_code(), 'body' => '', 'json' => null];
        } finally {
            while (ob_get_level() > $level) { ob_end_clean(); }
            $GLOBALS['operation_fixture_expect_writer'] = false;
            operation_assert($GLOBALS['operation_fixture_writer_depth'] === 0, 'Controller leaked its outer upload writer lease.');
        }
    }

    /**
     * Verify refusal without exposing raw exception data.
     *
     * @param callable():array<string,mixed> $operation Lifecycle operation expected to throw instead of returning a claim or response.
     * @param string $reason Expected bounded reason.
     * @return void Verify refusal without exposing raw exception data.
     */
    function operation_expect_refusal(callable $operation, string $reason): void
    {
        try { $operation(); } catch (AdminOperationRefusal $exception) {
            operation_assert($exception->reason === $reason, 'Unexpected operation refusal: ' . $exception->reason);
            operation_assert(!str_contains($exception->getMessage(), 'fixture-secret'), 'Storage exception detail escaped the service.');
            return;
        }
        throw new RuntimeException('Operation unexpectedly admitted: ' . $reason);
    }

    $root = sys_get_temp_dir() . '/gallery-operation-' . bin2hex(random_bytes(8));
    mkdir($root);
    $GLOBALS['operation_fixture_root'] = $root;
    $GLOBALS['operation_fixture_db'] = $database = new AdminOperationFixtureDatabase();
    $GLOBALS['operation_fixture_actor'] = ['id' => 1, 'role' => 'admin'];
    $GLOBALS['operation_fixture_csrf'] = 'fixture-csrf-secret';
    $GLOBALS['operation_fixture_schema'] = 'available';
    $GLOBALS['operation_fixture_galleries'] = [10 => ['id' => 10, 'parent_id' => 0, 'title' => 'Parent', 'visibility' => 'public']];
    $GLOBALS['operation_fixture_images'] = [];
    $GLOBALS['operation_fixture_create_count'] = 0;
    $GLOBALS['operation_fixture_upload_count'] = 0;
    $GLOBALS['operation_fixture_logs'] = [];
    $GLOBALS['operation_fixture_writer_depth'] = 0;
    $GLOBALS['operation_fixture_writer_begins'] = 0;
    schema_inspection_set_query_executor_for_tests('operation_schema_probe');
    $create = 'Gallery\\Controllers\\cms_admin_new_gallery';
    $upload = 'Gallery\\Controllers\\cms_admin_upload';
    $input = ['title' => 'Intentional duplicate', 'parent_id' => 10, 'visibility' => 'public'];

    try {
        operation_assert(admin_operation_refresh_url('/gallery/example/', '/gallery/example/3/?token=fixture-secret') === '/gallery/example/3/', 'Safe clean photo pagination was lost or leaked credentials.');
        operation_assert(admin_operation_refresh_url('/gallery/example/', '/gallery/example/galleries/2/?photo_page=3&secret=fixture-secret') === '/gallery/example/galleries/2/?photo_page=3', 'Safe combined pagination did not preserve only approved context.');
        operation_assert(admin_operation_refresh_url('/gallery/example/', '/gallery/another/3/?gallery_page=2&csrf=fixture-secret') === '/gallery/example/?gallery_page=2', 'Refresh accepted an unrelated source gallery path.');
        $missing = operation_request($create, $input);
        operation_assert($missing['status'] === 409 && $GLOBALS['operation_fixture_create_count'] === 0, 'Missing create key must refuse before mutation.');
        $missingUpload = operation_request($upload, ['upload_mode' => 'existing', 'gallery_id' => 10]);
        operation_assert($missingUpload['status'] === 409 && $GLOBALS['operation_fixture_upload_count'] === 0, 'Missing upload key must refuse before mutation.');
        $noJs = operation_request($create, $input, [], false);
        operation_assert($noJs['status'] === 409 && str_contains($noJs['body'], 'name="operation_key"') && str_contains($noJs['body'], 'data-fixture-parent-picker'), 'No-JavaScript refusal must render a keyed bounded form.');
        operation_assert(!str_contains($noJs['body'], '<select name="parent_id"'), 'Create form retained an unbounded parent select.');

        $key = admin_operation_new_key();
        $first = operation_request($create, $input + ['operation_key' => $key]);
        operation_assert($first['status'] === 200 && $first['json']['ok'], 'Initial create failed.');
        $galleryId = $first['json']['gallery_id'];
        $replay = operation_request($create, $input + ['operation_key' => $key]);
        operation_assert($replay['json'] === $first['json'] && $GLOBALS['operation_fixture_create_count'] === 1, 'Lost-response create replay changed identities or repeated mutation.');
        $changed = operation_request($create, array_replace($input, ['operation_key' => $key, 'title' => 'Changed']));
        operation_assert($changed['status'] === 409 && $changed['json']['error_code'] === 'operation_payload_conflict', 'Changed create payload must conflict.');
        $fresh = operation_request($create, $input + ['operation_key' => admin_operation_new_key()]);
        operation_assert($fresh['status'] === 200 && $fresh['json']['gallery_id'] !== $galleryId && $GLOBALS['operation_fixture_create_count'] === 2, 'Fresh keys must retain intentional duplicates.');

        $rowsBeforeAuth = count($database->rows);
        $GLOBALS['operation_fixture_actor'] = null;
        operation_assert(operation_request($create, $input + ['operation_key' => $key])['status'] === 401, 'A completed key must not bypass current authentication.');
        operation_assert(operation_request($upload, ['upload_mode' => 'existing', 'gallery_id' => 10, 'operation_key' => $key])['status'] === 401, 'Upload keys must not bypass authentication.');
        operation_assert(count($database->rows) === $rowsBeforeAuth, 'Unauthorized retries touched operation storage.');
        $GLOBALS['operation_fixture_actor'] = ['id' => 1, 'role' => 'admin'];
        operation_assert(operation_request($create, $input + ['operation_key' => $key, 'csrf' => 'old'])['status'] === 403, 'Replay must still require current CSRF.');
        $GLOBALS['operation_fixture_csrf'] = 'renewed-session-csrf';
        operation_assert(operation_request($create, $input + ['operation_key' => $key])['json'] === $first['json'], 'Reauthentication changed an operation bound to the same actor.');
        $GLOBALS['operation_fixture_actor'] = ['id' => 2, 'role' => 'admin'];
        $otherActor = operation_request($create, $input + ['operation_key' => $key]);
        operation_assert($otherActor['json']['gallery_id'] !== $galleryId, 'Another actor received the original actor\'s result.');
        $GLOBALS['operation_fixture_actor'] = ['id' => 1, 'role' => 'admin'];

        $source = $root . '/source.jpg';
        $otherPath = $root . '/another-tmp.jpg';
        file_put_contents($source, 'disposable image payload');
        copy($source, $otherPath);
        $files = [['name' => 'photo.jpg', 'tmp_name' => $source, 'size' => 1]];
        $sameFiles = [['name' => 'photo.jpg', 'tmp_name' => $otherPath, 'size' => 999]];
        $uploadKey = admin_operation_new_key();
        $uploadPost = ['upload_mode' => 'existing', 'gallery_id' => $galleryId, 'operation_key' => $uploadKey, 'source_url' => 'https://fixture.test/private?token=fixture-secret&photo_page=2'];
        $GLOBALS['operation_fixture_change_on_lock'] = $galleryId;
        $uploaded = operation_request($upload, $uploadPost, $files);
        operation_assert($uploaded['status'] === 200 && $uploaded['json']['uploaded'] === 1, 'Initial classic upload failed.');
        operation_assert($uploaded['json']['gallery_title'] === 'Current after writer acquisition', 'Upload response retained the controller row read before ownership.');
        $writerBegins = $GLOBALS['operation_fixture_writer_begins'];
        $uploadReplay = operation_request($upload, $uploadPost, $sameFiles);
        operation_assert($uploadReplay['json'] === $uploaded['json'] && $GLOBALS['operation_fixture_upload_count'] === 1, 'Same bytes in another PHP temporary path repeated upload.');
        operation_assert($GLOBALS['operation_fixture_writer_begins'] === $writerBegins, 'Completed upload replay reacquired mutation ownership.');
        operation_assert(!str_contains(json_encode($uploaded['json']), 'fixture-secret') && !str_contains(json_encode($uploaded['json']), '/private'), 'The durable upload response retained credentials/diagnostics from source_url.');
        file_put_contents($otherPath, 'different uploaded bytes');
        operation_assert(operation_request($upload, $uploadPost, $sameFiles)['status'] === 409, 'Changed uploaded content must conflict even under the same filename.');
        operation_assert(operation_request($upload, array_replace($uploadPost, ['operation_key' => admin_operation_new_key()]), $files)['status'] === 200 && $GLOBALS['operation_fixture_upload_count'] === 2, 'Fresh upload key must preserve intentional duplicate files.');
        operation_assert(operation_request($upload, array_replace($uploadPost, ['operation_key' => $key]), $files)['status'] === 409, 'Operation type is not bound to the key.');

        $createUploadKey = admin_operation_new_key();
        $createUploadPost = $input + ['upload_mode' => 'new', 'operation_key' => $createUploadKey];
        $beforePreflight = [$GLOBALS['operation_fixture_create_count'], $GLOBALS['operation_fixture_upload_count']];
        $GLOBALS['operation_fixture_ingestion_state'] = 'unknown';
        $refusedCombined = operation_request($upload, array_replace($createUploadPost, ['operation_key' => admin_operation_new_key()]), $files);
        $GLOBALS['operation_fixture_ingestion_state'] = 'available';
        operation_assert($refusedCombined['status'] === 503 && $beforePreflight === [$GLOBALS['operation_fixture_create_count'], $GLOBALS['operation_fixture_upload_count']], 'Combined upload created its gallery before ingestion schema preflight.');
        $createdUploaded = operation_request($upload, $createUploadPost, $files);
        $countBeforeReplay = [$GLOBALS['operation_fixture_create_count'], $GLOBALS['operation_fixture_upload_count']];
        operation_assert(operation_request($upload, $createUploadPost, $files)['json'] === $createdUploaded['json'], 'Create plus upload must replay one original combined envelope.');
        operation_assert($countBeforeReplay === [$GLOBALS['operation_fixture_create_count'], $GLOBALS['operation_fixture_upload_count']], 'Combined replay repeated creation or upload.');

        $ambiguousKey = admin_operation_new_key();
        $ambiguousPost = array_replace($uploadPost, ['operation_key' => $ambiguousKey]);
        $GLOBALS['operation_fixture_upload_throw'] = true;
        $ambiguous = operation_request($upload, $ambiguousPost, $files);
        $GLOBALS['operation_fixture_upload_throw'] = false;
        $uploadsAfterFailure = $GLOBALS['operation_fixture_upload_count'];
        operation_assert($ambiguous['status'] === 503 && !str_contains($ambiguous['body'], 'fixture-secret'), 'Partial upload failure must be bounded and marked ambiguous.');
        operation_assert(operation_request($upload, $ambiguousPost, $files)['status'] === 409 && $GLOBALS['operation_fixture_upload_count'] === $uploadsAfterFailure, 'Ambiguous upload must not reexecute.');

        $database->fault = 'complete_after_ack';
        $lostAckKey = admin_operation_new_key();
        $lostAck = operation_request($create, $input + ['operation_key' => $lostAckKey]);
        $createsAfterLostAck = $GLOBALS['operation_fixture_create_count'];
        operation_assert($lostAck['status'] === 503, 'Lost completion acknowledgement must be uncertain to the first request.');
        $afterLostAck = operation_request($create, $input + ['operation_key' => $lostAckKey]);
        operation_assert($afterLostAck['status'] === 200 && $GLOBALS['operation_fixture_create_count'] === $createsAfterLostAck, 'Committed response was destroyed by error handling after lost acknowledgement.');

        foreach (['missing', 'unknown'] as $state) {
            $GLOBALS['operation_fixture_schema'] = $state;
            schema_inspection_set_query_executor_for_tests('operation_schema_probe');
            $before = $GLOBALS['operation_fixture_create_count'];
            operation_assert(operation_request($create, $input + ['operation_key' => admin_operation_new_key()])['status'] === 503 && $GLOBALS['operation_fixture_create_count'] === $before, 'Unverified schema admitted create: ' . $state);
        }
        $GLOBALS['operation_fixture_schema'] = 'available';
        schema_inspection_set_query_executor_for_tests('operation_schema_probe');
        $database->fault = 'claim';
        $before = $GLOBALS['operation_fixture_create_count'];
        operation_assert(operation_request($create, $input + ['operation_key' => admin_operation_new_key()])['status'] === 503 && $GLOBALS['operation_fixture_create_count'] === $before, 'Unavailable claim storage admitted target mutation.');

        $pendingKey = admin_operation_new_key();
        $payloadHash = admin_operation_fingerprint('gallery.create', $input);
        $pending = admin_operation_begin(1, $pendingKey, 'gallery.create', $payloadHash);
        $database->connection = 2;
        operation_expect_refusal(/** Attempt claim/replay using the captured actor, key and fingerprint. @return array<string,mixed> Claim or original response only if admission unexpectedly succeeds. */ static fn () => admin_operation_begin(1, $pendingKey, 'gallery.create', $payloadHash), 'operation_pending');
        operation_expect_refusal(/** Attempt explicit recovery while retaining the captured original binding. @return array<string,mixed> Verified original response only if reconciliation is admitted. */ static fn () => admin_operation_reconcile_completed(1, $pendingKey, $payloadHash, $first['json']), 'operation_pending');
        $database->connection = 1;
        admin_operation_release($pending); // Simulate a worker ending without recording an outcome.
        $database->rows['1:' . hash('sha256', $pendingKey)]['updated_at'] = '2000-01-01 00:00:00';
        operation_expect_refusal(/** Attempt claim/replay using the captured actor, key and fingerprint. @return array<string,mixed> Claim or original response only if admission unexpectedly succeeds. */ static fn () => admin_operation_begin(1, $pendingKey, 'gallery.create', $payloadHash), 'operation_needs_reconciliation');
        $reconciled = admin_operation_reconcile_completed(1, $pendingKey, $payloadHash, $first['json']);
        operation_assert(admin_operation_begin(1, $pendingKey, 'gallery.create', $payloadHash)['response'] === $reconciled, 'Explicit verified reconciliation did not restore original-result replay.');

        $savedGallery = $GLOBALS['operation_fixture_galleries'][$galleryId];
        unset($GLOBALS['operation_fixture_galleries'][$galleryId]);
        operation_assert(operation_request($create, $input + ['operation_key' => $key])['status'] === 409, 'Removed result entity must not be recreated during replay.');
        $GLOBALS['operation_fixture_galleries'][$galleryId] = $savedGallery;
        operation_assert(admin_operation_form_key($key) === $key && admin_operation_form_key() !== admin_operation_form_key(), 'Form rendering must retain a failed key and give new workflows independent keys.');
        $ledgerJson = json_encode($database->rows);
        operation_assert(!str_contains($ledgerJson, 'fixture-secret') && !str_contains($ledgerJson, 'fixture-csrf-secret') && !str_contains($ledgerJson, 'renewed-session-csrf') && !str_contains($ledgerJson, $root), 'Ledger storage contains credential or temporary-path data.');
        operation_assert($database->locks === [], 'An operation path leaked worker ownership.');

        echo "Admin operation key tests passed (real controller/service/model code with isolated SQL/auth/domain seams; no live DB).\n";
    } finally {
        foreach (glob($root . '/*') ?: [] as $path) {
            if (is_file($path)) { unlink($path); }
        }
        rmdir($root);
    }
}
