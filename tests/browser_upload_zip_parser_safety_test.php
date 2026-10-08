<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/browser_upload_zip_parser_safety_test.php
 * Module Type: Regression Test
 * Purpose: Reject ambiguous normalized ZIP file paths without misreading directory records.
 * Responsibilities: Build valid store-only ZIP fixtures and exercise real browser upload ZIP parsing.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Limit the isolated parser fixture to a bounded number of ZIP entries.
     *
     * @param string $key Runtime limit identifier.
     * @return int Maximum entries for this fixture.
     */
    function cms_runtime_limit(string $key): int
    {
        return 16;
    }
}

namespace Gallery\Services {
    /**
     * Provide deterministic error text in the isolated parser regression.
     *
     * @param string $key Translation key.
     * @param string $fallback Default English text.
     * @return string Fallback text when provided.
     */
    function t(string $key, string $fallback = ''): string
    {
        return $fallback !== '' ? $fallback : $key;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_files.php';
    require_once dirname(__DIR__) . '/app/services/browser_uploads.php';

    /**
     * Assert a parser safety invariant.
     *
     * @param bool $condition Actual evaluated condition.
     * @param string $label Regression case label.
     * @return void No value when the condition holds.
     */
    function browser_zip_parser_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
    }

    /**
     * Encode a standards-compliant small store-only ZIP without requiring ZipArchive.
     *
     * Duplicate names are intentionally preserved in the archive to test rejection.
     * This includes local headers, matching central directory and the end record.
     *
     * @param list<array{name:string,data:string}> $entries Ordered archive records.
     * @return string Complete ZIP byte stream with stored compression.
     */
    function browser_zip_parser_fixture(array $entries): string
    {
        $localRecords = '';
        $centralDirectory = '';
        foreach ($entries as $entry) {
            $name = $entry['name'];
            $data = $entry['data'];
            $length = strlen($data);
            $nameLength = strlen($name);
            $crc = crc32($data);
            $localOffset = strlen($localRecords);
            $localRecords .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0, $crc, $length, $length, $nameLength, 0) . $name . $data;
            $centralDirectory .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0, $crc, $length, $length, $nameLength, 0, 0, 0, 0, 0, $localOffset) . $name;
        }
        $count = count($entries);
        return $localRecords . $centralDirectory
            . pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($centralDirectory), strlen($localRecords), 0);
    }

    /**
     * Invoke the real parser on raw disposable ZIP bytes, including damaged fixtures.
     *
     * @param string $zipBytes Full store-only ZIP payload.
     * @return array<string,string> Normalized stored entries on success.
     */
    function browser_zip_parser_read_bytes(string $zipBytes): array
    {
        $temp = tempnam(sys_get_temp_dir(), 'gallery-zip-parser-');
        if ($temp === false) {
            throw new \RuntimeException('Cannot create temporary ZIP fixture.');
        }
        try {
            if (file_put_contents($temp, $zipBytes) === false) {
                throw new \RuntimeException('Cannot write temporary ZIP fixture.');
            }
            return \Gallery\Services\browser_upload_parse_store_zip($temp, 1048576);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Invoke the real parser on a complete ZIP assembled from named fixture entries.
     *
     * @param list<array{name:string,data:string}> $entries ZIP payload records.
     * @return array<string,string> Normalized stored entries on success.
     */
    function browser_zip_parser_read(array $entries): array
    {
        return browser_zip_parser_read_bytes(browser_zip_parser_fixture($entries));
    }

    /**
     * Require rejection of damaged ZIP bytes by the real parser.
     *
     * @param string $zipBytes Complete corrupted ZIP payload.
     * @param string $label Regression case label.
     * @return void No value on expected rejection.
     */
    function browser_zip_parser_reject_bytes(string $zipBytes, string $label): void
    {
        try {
            browser_zip_parser_read_bytes($zipBytes);
        } catch (\RuntimeException) {
            return;
        }
        throw new \RuntimeException($label . ': invalid ZIP was accepted.');
    }

    /**
     * Verify that a malformed duplicate ZIP fails rather than silently overwriting content.
     *
     * @param list<array{name:string,data:string}> $entries ZIP payload records.
     * @param string $label Regression case label.
     * @return void No return after expected rejection.
     */
    function browser_zip_parser_reject_duplicates(array $entries, string $label): void
    {
        try {
            browser_zip_parser_read($entries);
        } catch (\RuntimeException) {
            return;
        }
        throw new \RuntimeException($label . ': duplicate normalized ZIP path was not rejected.');
    }

    $normal = browser_zip_parser_read([
        ['name' => 'manifest.json', 'data' => '{"items":[]}'],
        ['name' => 'photos/', 'data' => ''],
        ['name' => 'photos/camera.jpg', 'data' => 'jpeg-fixture'],
    ]);
    browser_zip_parser_assert(
        $normal === ['manifest.json' => '{"items":[]}', 'photos/camera.jpg' => 'jpeg-fixture'],
        'Directory entry must be ignored and unique file entry contents preserved.'
    );

    browser_zip_parser_reject_duplicates([
        ['name' => 'photos/camera.jpg', 'data' => 'first'],
        ['name' => 'photos/./camera.jpg', 'data' => 'second'],
    ], 'Equivalent dot component');

    browser_zip_parser_reject_duplicates([
        ['name' => 'manifest.json', 'data' => '{"items":[]}'],
        ['name' => 'manifest.json', 'data' => '{"items":[{"bad":1}]}'],
    ], 'Overwritten manifest');

    browser_zip_parser_reject_duplicates([
        ['name' => 'photos/camera.jpg', 'data' => 'first'],
        ['name' => 'photos\\camera.jpg', 'data' => 'second'],
    ], 'Backslash-normalized collision');

    // A damaged manifest or image must be rejected before any upload mutations.
    // The ZIP headers and central directory are otherwise untouched and valid.
    $validZip = browser_zip_parser_fixture([
        ['name' => 'photo.jpg', 'data' => 'jpeg-fixture'],
    ]);
    $payloadOffset = strpos($validZip, 'jpeg-fixture');
    browser_zip_parser_assert(is_int($payloadOffset), 'Could not locate the ZIP test payload.');
    browser_zip_parser_reject_bytes(
        substr_replace($validZip, 'X', $payloadOffset, 1),
        'Payload differs from declared CRC-32'
    );
    $badHeaderCrc = $validZip;
    $badHeaderCrc[14] = chr(ord($badHeaderCrc[14]) ^ 1);
    browser_zip_parser_reject_bytes($badHeaderCrc, 'Local ZIP header CRC-32 was modified');

    // Central records and the classic end marker must describe the exact local
    // data that was validated. A prefix containing valid local records alone
    // is not a complete ZIP and must not reach the upload mutation pipeline.
    $validStructure = browser_zip_parser_fixture([
        ['name' => 'manifest.json', 'data' => '{"items":[]}'],
        ['name' => 'photos/', 'data' => ''],
        ['name' => 'photos/photo.jpg', 'data' => 'jpeg-fixture'],
    ]);
    $centralOffset = strpos($validStructure, "\x50\x4b\x01\x02");
    $endOffset = strrpos($validStructure, "\x50\x4b\x05\x06");
    browser_zip_parser_assert(is_int($centralOffset) && is_int($endOffset), 'Could not locate ZIP directory records.');
    browser_zip_parser_reject_bytes(substr($validStructure, 0, $centralOffset), 'Missing central directory and EOCD');
    browser_zip_parser_reject_bytes(substr($validStructure, 0, $endOffset), 'Missing EOCD');
    browser_zip_parser_reject_bytes(substr($validStructure, 0, $centralOffset + 4), 'Incomplete central directory');

    $wrongCentralOffset = $validStructure;
    $wrongCentralOffset[$centralOffset + 42] = chr(ord($wrongCentralOffset[$centralOffset + 42]) ^ 1);
    browser_zip_parser_reject_bytes($wrongCentralOffset, 'Central record points at an unrelated local header');

    $wrongDirectorySize = $validStructure;
    $wrongDirectorySize[$endOffset + 12] = chr(ord($wrongDirectorySize[$endOffset + 12]) ^ 1);
    browser_zip_parser_reject_bytes($wrongDirectorySize, 'End record announces the wrong central directory size');

    $wrongEntryCount = $validStructure;
    $wrongEntryCount[$endOffset + 10] = chr(ord($wrongEntryCount[$endOffset + 10]) ^ 1);
    browser_zip_parser_reject_bytes($wrongEntryCount, 'End record count differs from parsed entries');

    browser_zip_parser_reject_bytes($validStructure . 'junk', 'Unaccounted trailing archive data');
    browser_zip_parser_assert(
        browser_zip_parser_read_bytes($validStructure)['photos/photo.jpg'] === 'jpeg-fixture',
        'Valid archive with a directory and complete central index was rejected.'
    );

    echo "Store-only ZIP parser path, CRC and directory integrity passed.\n";
}
