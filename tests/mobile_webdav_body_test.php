<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/mobile_webdav_body_test.php
 * Module Type: Test
 * Purpose: Exercise WebDAV request-body ownership and recoverable refusal with tiny isolated files.
 * Responsibilities: Run real controller, staging, authentication, schema policy and writer facade against fake persistence.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: No HTTP server, live database, configuration or gallery storage is opened.
 */
declare(strict_types=1);

namespace Gallery\Core {
    require_once __DIR__ . '/support/admin_operation_fixture.php';
    /** Supply deterministic time without application bootstrap. @return string Fixture SQL timestamp. */
    function now_sql(): string { return '2026-09-20 12:00:00'; }
}

namespace Gallery\Models {
    /** Resolve only the isolated credential through real service authentication. @param string $pathToken Fixture URL credential. @return array{id:int,gallery_id:int,username:string,password_hash:string}|null Fake credential row. */
    function mobile_webdav_model_find_active_by_path_token(string $pathToken): ?array
    {
        return $pathToken === 'fixture-path-token' ? $GLOBALS['webdav_body_token'] : null;
    }
    /** Record successful credential use without SQL. @param int $tokenId Fixture credential identity. @param string $usedAt Deterministic timestamp. @return void */
    function mobile_webdav_model_mark_used(int $tokenId, string $usedAt): void { ++$GLOBALS['webdav_body_used']; }
}

namespace Gallery\Services {
    /** Preserve safe fallback messages without loading translations/configuration. @param string $key Translation identifier. @param string $fallback English message. @return string Safe message. */
    function t(string $key, string $fallback = ''): string { return $fallback; }
    /** Restrict native staging to the unique fixture directory. @return string Owned disposable directory. */
    function sys_get_temp_dir(): string { return $GLOBALS['webdav_body_root']; }
    /** Inject allocation refusal or an unowned returned path at the native seam. @param string $directory Owned staging directory. @param string $prefix Existing WebDAV basename prefix. @return string|false New temp path or controlled fault. */
    function tempnam(string $directory, string $prefix): string|false
    {
        ++$GLOBALS['webdav_body_allocations'];
        if ($GLOBALS['webdav_body_fault'] === 'allocation') { return false; }
        if ($GLOBALS['webdav_body_fault'] === 'unowned') { return $directory . '/unrelated.bin'; }
        return \tempnam($directory, $prefix);
    }
    /** Inject inability to open the already-created output file. @param string $path Owned staging file. @param string $mode Requested output mode. @return resource|false Native file handle or controlled refusal. */
    function fopen(string $path, string $mode): mixed
    {
        return $GLOBALS['webdav_body_fault'] === 'output_open' ? false : \fopen($path, $mode);
    }
    /** Copy tiny real bytes, optionally failing or changing schema after initial preflight. @param resource $input Caller-owned input. @param resource $output Request-owned output. @return int|false Bytes copied or injected error. */
    function stream_copy_to_stream(mixed $input, mixed $output): int|false
    {
        if ($GLOBALS['webdav_body_fault'] === 'copy') { return false; }
        if ($GLOBALS['webdav_body_fault'] === 'partial') { return \stream_copy_to_stream($input, $output, 1); }
        $copied = \stream_copy_to_stream($input, $output);
        if ($GLOBALS['webdav_body_late_state'] !== '') {
            $GLOBALS['webdav_body_states'][$GLOBALS['webdav_body_late_table']] = $GLOBALS['webdav_body_late_state'];
        }
        return $copied;
    }
    /** Inject a flush refusal without allocating a large body. @param resource $output Owned output stream. @return bool Whether the tiny body was flushed. */
    function fflush(mixed $output): bool { return $GLOBALS['webdav_body_fault'] !== 'flush' && \fflush($output); }
    /** Verify that request cleanup touches only its allocated staging file. @param string $path Candidate cleanup path. @return bool Native unlink outcome. */
    function unlink(string $path): bool
    {
        \webdav_body_expect(realpath(dirname($path)) === realpath($GLOBALS['webdav_body_root']) && str_starts_with(basename($path), 'pg-'), 'Cleanup attempted an unowned path.');
        ++$GLOBALS['webdav_body_deletions'];
        return \unlink($path);
    }
    /** Exercise real moves and the copy fallback under the established writer guard. @param string $source Staged body. @param string $target Disposable gallery target. @return bool Move result, or controlled fallback request. */
    function rename(string $source, string $target): bool
    {
        \webdav_body_assert_writer();
        return $GLOBALS['webdav_body_fault'] === 'rename' ? false : \rename($source, $target);
    }
    /** Prove fallback copies remain inside writer ownership. @param string $source Staged body. @param string $target Disposable gallery target. @return bool Native copy result. */
    function copy(string $source, string $target): bool { \webdav_body_assert_writer(); return \copy($source, $target); }
    /** Read the current fixture gallery only while guarded. @param int $id Destination identity. @param bool $fresh Requested cache bypass. @return array{id:int,folder_path:string} Fresh fixture row. */
    function find_gallery(int $id, bool $fresh = false): array
    {
        \webdav_body_assert_writer();
        \webdav_body_expect($id === 10 && $fresh, 'Destination was not freshly resolved under ownership.');
        return ['id' => 10, 'folder_path' => 'fixture'];
    }
    /** Select a destination entirely within disposable storage. @param array{id:int,folder_path:string} $gallery Fixture gallery row. @param string $filename Sanitized upload name. @return array{string,string} Stored filename and owned target path. */
    function unique_gallery_upload_target(array $gallery, string $filename): array
    {
        ++$GLOBALS['webdav_body_targets'];
        return [$filename, $GLOBALS['webdav_body_root'] . '/stored-' . $filename];
    }
    /** Model registration after bytes are installed without a live scanner/database. @param int $galleryId Destination identity. @return int One fixture image registered. */
    function scan_gallery_images(int $galleryId): int { \webdav_body_assert_writer(); ++$GLOBALS['webdav_body_scans']; return 1; }
    /** Return the identity assigned by the isolated scanner. @param int $galleryId Destination identity. @param list<string> $filenames Stored filenames. @return list<int> Fixture image identity. */
    function uploaded_gallery_image_ids(int $galleryId, array $filenames): array { return [101]; }
    /** Keep unrelated auto-renaming disabled without reading live settings. @return bool Always false in this fixture. */
    function admin_upload_auto_rename_enabled(): bool { return false; }
    /** Capture safe operational diagnostics without writing application logs. @param string $level Log level. @param string $event Stable event code. @param string $message Safe summary. @param array<string,mixed> $context Diagnostic projection. @param array<string,string> $options Logger classification. @return void */
    function admin_log_event(string $level, string $event, string $message, array $context = [], array $options = []): void
    {
        $GLOBALS['webdav_body_logs'][] = [$event, $message, $context];
    }
    /** Supply explicit table states to the real schema policy without SQL. @param string $table Requested storage owner. @return array{state:string,table:string,object:string,object_type:string} Safe observation. */
    function schema_inspection_table(string $table): array
    {
        return ['state' => $GLOBALS['webdav_body_states'][$table] ?? 'available', 'table' => $table, 'object' => $table, 'object_type' => 'table'];
    }
    /** Make columns follow their fixture table state. @param string $table Storage owner. @param string $column Required field. @return array{state:string,table:string,object:string,object_type:string} Safe observation. */
    function schema_inspection_column(string $table, string $column): array
    {
        return ['state' => $GLOBALS['webdav_body_states'][$table] ?? 'available', 'table' => $table, 'object' => $column, 'object_type' => 'column'];
    }
    /** Aggregate controlled observations for the real refusal policy. @param string $feature Capability identity. @param list<array{state:string,table:string,object:string,object_type:string}> $requirements Required objects. @return array{state:string,feature:string,requirements:list<array{state:string,table:string,object:string,object_type:string}>} Aggregate three-state fixture. */
    function schema_inspection_feature(string $feature, array $requirements): array
    {
        $states = array_column($requirements, 'state');
        $state = in_array('unknown', $states, true) ? 'unknown' : (in_array('missing', $states, true) ? 'missing' : 'available');
        return ['state' => $state, 'feature' => $feature, 'requirements' => $requirements];
    }
    /** Apply the available predicate to a fixture observation. @param array{state:string,...} $status Observed state. @return bool True only for available. */
    function schema_inspection_is_available(array $status): bool { return $status['state'] === 'available'; }
    /** Apply the missing predicate to a fixture observation. @param array{state:string,...} $status Observed state. @return bool True only for missing. */
    function schema_inspection_is_missing(array $status): bool { return $status['state'] === 'missing'; }
    /** Apply the unknown predicate to a fixture observation. @param array{state:string,...} $status Observed state. @return bool True only for unknown. */
    function schema_inspection_is_unknown(array $status): bool { return $status['state'] === 'unknown'; }
}

namespace Gallery\Controllers {
    /** Open a tiny input stream at the real controller's transport seam. @param string $path Expected php://input resource. @param string $mode Expected read mode. @return resource|false Input stream or controlled transport failure. */
    function fopen(string $path, string $mode): mixed
    {
        \webdav_body_expect($path === 'php://input' && $mode === 'rb', 'Controller attempted filesystem body ownership.');
        ++$GLOBALS['webdav_body_input_opens'];
        if ($GLOBALS['webdav_body_fault'] === 'input_open') { return false; }
        $input = \webdav_body_input($GLOBALS['webdav_body_bytes']);
        $GLOBALS['webdav_body_last_input'] = $input;
        return $input;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/mutation_schema_policy.php';
    require_once dirname(__DIR__) . '/app/services/mobile_webdav.php';
    require_once dirname(__DIR__) . '/app/controllers/mobile_webdav.php';

    /** Fail immediately without printing private fixture paths. @param bool $condition Required invariant. @param string $message Safe failure description. @return void */
    function webdav_body_expect(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    /** Verify the real model's simulated global advisory lock remains held. @return void */
    function webdav_body_assert_writer(): void
    {
        $name = hash('sha256', 'disposable_operation_fixture' . \Gallery\Core\GALLERY_EDIT_LOCK_SUFFIX);
        webdav_body_expect(isset($GLOBALS['operation_fixture_db']->locks[$name]), 'WebDAV target mutation escaped writer ownership.');
    }
    /** Prepare a small caller-owned body without filesystem allocation. @param string $bytes Tiny image or malformed payload. @return resource Rewound readable stream. */
    function webdav_body_input(string $bytes): mixed
    {
        $input = fopen('php://memory', 'w+b');
        fwrite($input, $bytes);
        rewind($input);
        return $input;
    }
    /** Reset fault/observation state without deleting any retained source. @return void */
    function webdav_body_reset(): void
    {
        $GLOBALS['webdav_body_fault'] = '';
        $GLOBALS['webdav_body_late_state'] = '';
        $GLOBALS['webdav_body_late_table'] = 'images';
        $GLOBALS['webdav_body_states'] = [];
        $GLOBALS['webdav_body_logs'] = [];
        foreach (['allocations', 'deletions', 'input_opens', 'targets', 'scans', 'used'] as $counter) {
            $GLOBALS['webdav_body_' . $counter] = 0;
        }
        $GLOBALS['webdav_body_last_input'] = null;
    }
    /** Read only staged files in the known disposable directory. @return list<string> Retained request-body paths for fixture assertions, never product output. */
    function webdav_body_staged(): array { return array_values(glob($GLOBALS['webdav_body_root'] . '/pg-*') ?: []); }
    /** Invoke the real route with isolated globals and capture its response. @param string $target Requested resource path. @param string $password Fixture Basic Auth password. @return array{status:int,body:string} Captured protocol outcome. */
    function webdav_body_request(string $target = 'photo.png', string $password = 'fixture-password'): array
    {
        $_GET = ['token' => 'fixture-path-token', 'target_path' => $target];
        $_SERVER = ['REQUEST_METHOD' => 'PUT', 'PHP_AUTH_USER' => 'fixture-user', 'PHP_AUTH_PW' => $password];
        http_response_code(200);
        ob_start();
        try { \Gallery\Controllers\cms_mobile_webdav(); return ['status' => http_response_code(), 'body' => (string) ob_get_contents()]; }
        finally { ob_end_clean(); }
    }

    $GLOBALS['operation_fixture_db'] = new \Gallery\Core\AdminOperationFixtureDatabase();
    $GLOBALS['webdav_body_root'] = str_replace('\\', '/', sys_get_temp_dir()) . '/gallery-webdav-body-' . bin2hex(random_bytes(8));
    mkdir($GLOBALS['webdav_body_root'], 0700);
    $GLOBALS['webdav_body_token'] = ['id' => 7, 'gallery_id' => 10, 'username' => 'fixture-user', 'password_hash' => password_hash('fixture-password', PASSWORD_DEFAULT)];
    $GLOBALS['webdav_body_bytes'] = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX6kAAAAASUVORK5CYII=');
    file_put_contents($GLOBALS['webdav_body_root'] . '/unrelated.bin', 'untouched fixture sentinel');
    try {
        webdav_body_reset();
        $response = webdav_body_request('folder/photo.png');
        webdav_body_expect($response === ['status' => 201, 'body' => ''], 'Successful PUT response changed.');
        webdav_body_expect(file_get_contents($GLOBALS['webdav_body_root'] . '/stored-photo.png') === $GLOBALS['webdav_body_bytes'], 'Stored bytes differ from the staged body.');
        webdav_body_expect(webdav_body_staged() === [] && $GLOBALS['webdav_body_used'] === 1 && $GLOBALS['webdav_body_scans'] === 1, 'Success left temp input or skipped registration.');
        webdav_body_expect(!is_resource($GLOBALS['webdav_body_last_input']) && $GLOBALS['operation_fixture_db']->locks === [], 'Controller stream or writer lease leaked.');

        webdav_body_reset();
        $GLOBALS['webdav_body_fault'] = 'rename';
        webdav_body_expect(webdav_body_request('copy.png')['status'] === 201 && webdav_body_staged() === [], 'Guarded copy fallback did not consume its body.');

        foreach (['input_open', 'allocation', 'unowned', 'output_open', 'copy', 'partial', 'flush'] as $fault) {
            webdav_body_reset();
            $GLOBALS['webdav_body_fault'] = $fault;
            $response = webdav_body_request();
            webdav_body_expect($response['status'] === 500 && $GLOBALS['webdav_body_targets'] === 0 && webdav_body_staged() === [], 'Staging refusal changed status, mutated target, or leaked ordinary temp input.');
            webdav_body_expect(!str_contains($response['body'], $GLOBALS['webdav_body_root']) && !is_resource($GLOBALS['webdav_body_last_input']), 'Staging failure exposed a path or leaked its transport stream.');
        }
        webdav_body_expect(file_get_contents($GLOBALS['webdav_body_root'] . '/unrelated.bin') === 'untouched fixture sentinel', 'Request cleanup removed unrelated content.');

        webdav_body_reset();
        webdav_body_expect(webdav_body_request('photo.png', 'wrong-password')['status'] === 401 && $GLOBALS['webdav_body_input_opens'] === 0 && $GLOBALS['webdav_body_allocations'] === 0, 'Unauthorized PUT consumed or staged the body.');
        foreach (['missing' => 409, 'unknown' => 503] as $state => $status) {
            webdav_body_reset();
            $GLOBALS['webdav_body_states']['images'] = $state;
            webdav_body_expect(webdav_body_request()['status'] === $status && $GLOBALS['webdav_body_input_opens'] === 0 && $GLOBALS['webdav_body_allocations'] === 0, 'Controller schema preflight moved after body consumption.');
        }

        foreach (['images', 'mobile_webdav_upload_tokens'] as $table) {
            foreach (['missing' => 422, 'unknown' => 503] as $state => $status) {
                webdav_body_reset();
                $before = webdav_body_staged();
                $GLOBALS['webdav_body_late_table'] = $table;
                $GLOBALS['webdav_body_late_state'] = $state;
                $response = webdav_body_request();
                $retained = array_values(array_diff(webdav_body_staged(), $before));
                webdav_body_expect($response['status'] === $status && count($retained) === 1, 'Late schema refusal lost the recoverable body or changed HTTP mapping.');
                webdav_body_expect(file_get_contents($retained[0]) === $GLOBALS['webdav_body_bytes'] && $GLOBALS['webdav_body_targets'] === 0 && $GLOBALS['webdav_body_deletions'] === 0, 'Schema refusal altered bytes or destination.');
                webdav_body_expect(!is_resource($GLOBALS['webdav_body_last_input']) && $GLOBALS['operation_fixture_db']->locks === [], 'Schema refusal leaked stream or writer lease.');
                $diagnostics = $response['body'] . json_encode($GLOBALS['webdav_body_logs']);
                webdav_body_expect(!str_contains($diagnostics, basename($retained[0])) && !str_contains($diagnostics, 'fixture-password'), 'Refusal exposed a private path or credential.');
            }
        }

        webdav_body_reset();
        $retained = webdav_body_staged();
        $input = webdav_body_input('invalid-image');
        try {
            \Gallery\Services\mobile_webdav_store_put_stream($GLOBALS['webdav_body_token'], 'bad.png', $input);
            throw new RuntimeException('Malformed body unexpectedly installed.');
        } catch (RuntimeException $exception) {
            webdav_body_expect($exception->getMessage() === 'One uploaded file is not a valid image.', 'Ordinary service error mapping changed.');
            webdav_body_expect(is_resource($input) && webdav_body_staged() === $retained, 'Service closed caller input or deleted a previous retained body.');
        } finally { fclose($input); }
        webdav_body_expect(webdav_body_request('bad.txt')['status'] === 422 && webdav_body_staged() === $retained, 'Ordinary validation refusal changed its cleanup or status.');

        webdav_body_reset();
        try {
            \Gallery\Services\mobile_webdav_store_put_stream($GLOBALS['webdav_body_token'], 'photo.png', '/unowned/path');
            throw new RuntimeException('A pathname was accepted as an input stream.');
        } catch (\Gallery\Services\MobileWebdavBodyException) {
            webdav_body_expect($GLOBALS['webdav_body_allocations'] === 0 && webdav_body_staged() === $retained, 'Invalid stream allocated or removed body files.');
        }
        echo "PASS WebDAV body ownership, transport mapping, guarded installation and schema-refusal retention\n";
    } finally {
        // Only this fixture's unique flat directory is removed; retained production files are never inspected.
        foreach (new DirectoryIterator($GLOBALS['webdav_body_root']) as $entry) {
            if ($entry->isFile()) { unlink($entry->getPathname()); }
        }
        rmdir($GLOBALS['webdav_body_root']);
    }
}
