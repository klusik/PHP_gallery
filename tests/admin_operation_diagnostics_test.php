<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_operation_diagnostics_test.php
 * Module Type: Regression Test
 * Purpose: Preserve safe partial-success diagnostics in first delivery and stable replay.
 * Responsibilities:
 *   - Retain filenames/reason counts and legacy UI shapes while excluding native errors and paths.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: Isolated SQL and identity seams; no files are uploaded or decoded.
 */
declare(strict_types=1);

namespace Gallery\Core {
    require_once __DIR__ . '/support/admin_operation_fixture.php';
}

namespace Gallery\Services {
    /**
     * Simulated translation with switchable request language.
     *
     * @param string $key Translation key.
     * @param string $fallback English fallback.
     * @param array<string,string> $parameters Bounded replacements.
     * @return string Simulated translation with switchable request language.
     */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        $map = [];
        foreach ($parameters as $name => $value) { $map['{' . $name . '}'] = $value; }
        return ($GLOBALS['diagnostic_language'] ?? '') . strtr($fallback, $map);
    }
    /**
     * Simulated current result reference.
     *
     * @param int $id Original gallery identity.
     * @param bool $fresh Required lookup mode.
     * @return array<string,mixed>|null Simulated current result reference.
     */
    function find_gallery(int $id, bool $fresh = false): ?array { return $id === 10 ? ['id' => 10, 'parent_id' => 0] : null; }
    /**
     * Simulated current upload ownership.
     *
     * @param int $id Original image identity.
     * @param bool $fresh Required lookup mode.
     * @return array<string,mixed>|null Simulated current upload ownership.
     */
    function find_image(int $id, bool $fresh = false): ?array { return $id === 20 ? ['id' => 20, 'gallery_id' => 10] : null; }
}

namespace {
    require_once __DIR__ . '/../app/services/admin_operation_keys.php';

    /**
     * Assert without dumping private response contents.
     *
     * @param bool $condition Required projection behavior.
     * @param string $message Bounded failure detail.
     * @return void Assert without dumping private response contents.
     */
    function diagnostic_expect(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }
    /**
     * Verified isolated ledger schema.
     *
     * @return bool Verified isolated ledger schema.
     */
    function diagnostic_schema(): bool { return true; }

    $GLOBALS['operation_fixture_db'] = $database = new \Gallery\Core\AdminOperationFixtureDatabase();
    \Gallery\Services\schema_inspection_set_query_executor_for_tests('diagnostic_schema');
    $raw = ['ok' => true, 'message' => 'Upload complete.',
        'mutation' => ['type' => 'image.upload', 'entity_ids' => [20]],
        'panel' => ['keep_open' => true], 'contexts' => [], 'fallback' => ['redirect_url' => '/index.php?page=admin_edit_gallery&id=10'],
        'gallery_id' => 10, 'parent_gallery_id' => 0, 'image_ids' => [20],
        'filenames' => ['uploaded.jpg', '/private/server/hidden.jpg'],
        'uploaded' => 2, 'scanned' => 1, 'renamed' => 0, 'scan_failed' => 2, 'thumbnail_failed' => 3,
        'scan_failed_filenames' => ['no-index.jpg', 'C:\\private\\hidden.jpg'],
        'thumbnail_failed_filenames' => ['žluťoučký.png', '/private/other.jpg'],
        'thumbnail_errors' => ['password=fixture-secret in /private/decoder'],
        'rename_warnings' => ['private-dsn fixture-secret', 'native warning /private/rename'],
        'rename_failures' => ['rename exception password=fixture-secret'],
        'upload_events' => [['message' => 'raw fixture-secret /private/server', 'context' => ['token' => 'fixture-secret'], 'elapsed_ms' => 5]]];
    $safe = \Gallery\Services\admin_operation_response($raw);
    $issues = array_column($safe['upload_diagnostics'], null, 'code');
    diagnostic_expect($safe['scan_failed_filenames'] === ['no-index.jpg'] && $safe['thumbnail_failed_filenames'] === ['žluťoučký.png'], 'Safe failed filenames disappeared.');
    diagnostic_expect($safe['filenames'] === ['uploaded.jpg'] && $safe['filenames_omitted'] === 1, 'Untrusted original filenames were not bounded.');
    diagnostic_expect($issues['scan_failed']['count'] === 2 && $issues['scan_failed']['filenames_omitted'] === 1, 'Scan failure identity/count information disappeared.');
    diagnostic_expect($issues['rename_warning']['count'] === 2 && $issues['rename_failed']['count'] === 1 && $issues['thumbnail_failed']['count'] === 3, 'Typed diagnostic counts did not preserve partial success.');
    diagnostic_expect(count($safe['upload_events']) === 7 && is_string($safe['upload_events'][0]['message']) && count($safe['rename_warnings']) === 1 && count($safe['rename_failures']) === 1 && count($safe['thumbnail_errors']) === 1, 'Existing classic UI lost its compatible diagnostic shapes.');
    $serialized = json_encode($safe, JSON_THROW_ON_ERROR);
    diagnostic_expect(!str_contains($serialized, 'fixture-secret') && !str_contains($serialized, '/private') && !str_contains($serialized, 'private-dsn'), 'Native diagnostics or paths escaped projection.');
    diagnostic_expect(\Gallery\Services\admin_operation_response($safe) === $safe, 'Safe diagnostic preparation is not idempotent.');
    $many = \Gallery\Services\admin_operation_diagnostic_filenames(array_fill(0, 100, 'photo.jpg'));
    diagnostic_expect(count($many['filenames']) === \Gallery\Core\ADMIN_OPERATION_DIAGNOSTIC_MAX_FILENAMES && $many['filenames_omitted'] === 68, 'Diagnostic filenames were not bounded with explicit omissions.');
    diagnostic_expect(\Gallery\Services\admin_operation_diagnostic_filename(str_repeat('x', 300) . '.jpg') === '' && \Gallery\Services\admin_operation_diagnostic_filename("unsafe\n.jpg") === '', 'Oversized/control filenames entered diagnostics.');

    $key = \Gallery\Services\admin_operation_new_key();
    $hash = \Gallery\Services\admin_operation_fingerprint('image.classic_upload', ['gallery_id' => 10]);
    $claim = \Gallery\Services\admin_operation_begin(1, $key, 'image.classic_upload', $hash);
    $first = \Gallery\Services\admin_operation_complete($claim, $raw);
    $GLOBALS['diagnostic_language'] = 'NEW LANGUAGE: ';
    $replayed = \Gallery\Services\admin_operation_begin(1, $key, 'image.classic_upload', $hash);
    diagnostic_expect($replayed['response'] === $first && $first === $safe, 'Replay regenerated or dropped original diagnostic translations.');
    diagnostic_expect($database->locks === [], 'Diagnostic replay leaked operation ownership.');
    foreach (['en', 'cs', 'de', 'sv'] as $language) {
        $catalog = json_decode((string) file_get_contents(__DIR__ . '/../app/lang/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach (['upload_stored', 'upload_scanned', 'upload_renamed', 'scan_failed', 'thumbnail_failed', 'rename_warning', 'rename_failed'] as $code) {
            diagnostic_expect(is_string($catalog['admin.operation.diagnostic_' . $code] ?? null), 'A supported language lacks an upload diagnostic translation.');
        }
    }
    echo "PASS admin operation safe partial-success diagnostics and stable replay\n";
}
