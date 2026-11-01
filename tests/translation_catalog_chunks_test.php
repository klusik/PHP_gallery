<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/translation_catalog_chunks_test.php
 * Module Type: Regression Test
 * Purpose: Preserve native catalog semantics while bounding ordinary lookups.
 * Responsibilities: Exercise large canonical catalogs, duplicate keys, native
 *     JSON/PHP compatibility, invalid JSON and request-local invalidation.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace {
    /** Fail without exposing arbitrary catalog contents.
     * @param bool $condition Expected catalog property.
     * @param string $message Bounded assertion context.
     * @return void Throws when the expected property is absent.
     */
    function chunk_catalog_check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $directory = sys_get_temp_dir() . '/gallery-catalog-' . bin2hex(random_bytes(8));
    mkdir($directory);
    mkdir($directory . '/lang');
    mkdir($directory . '/services');
    $servicePath = $directory . '/services/translations.php';
    $adapterPath = $directory . '/session_context.php';
    copy(__DIR__ . '/../app/services/translations.php', $servicePath);
    copy(__DIR__ . '/../app/session_context.php', $adapterPath);
    // Use the complete actual service with its own isolated language directory.
    require_once $servicePath;
    $GLOBALS['cms_translation_cache_version'] = 0;
    $path = $directory . '/lang/zz.json';
    $phpPath = $directory . '/lang/zz.php';
    $discoveryPath = $directory . '/lang/zy.json';
    try {
        $entries = ['"duplicate": "first"'];
        // Cross a production chunk boundary and repeat a key in the next chunk.
        for ($index = 0;
            $index < \Gallery\Services\TRANSLATION_CATALOG_CHUNK_KEYS;
            $index++
        ) {
            $entries[] = json_encode('key.' . $index) . ': '
                . json_encode('Příliš "žluťoučký" \\ ' . $index);
        }
        $entries[] = '"duplicate": "last"';
        $entries[] = '"empty": ""';
        $entries[] = '"null": null';
        $entries[] = '"nested": {"value": [true, 3]}';
        $json = "{\n" . implode(",\n", $entries) . "\n}\n";
        file_put_contents($path, $json);
        $chunks = \Gallery\Services\translation_catalog_read_chunks($path);
        chunk_catalog_check(is_array($chunks) && count($chunks) > 1,
            'Large canonical catalogs must use bounded chunks.');
        foreach ($chunks as $chunk) {
            chunk_catalog_check(count($chunk)
                <= \Gallery\Services\TRANSLATION_CATALOG_CHUNK_KEYS,
                'An individual lookup table exceeded its configured bound.');
        }
        chunk_catalog_check(\Gallery\Services\translation_load_language('zz')
            === json_decode($json, true),
            'Chunk materialization changed native JSON values or key precedence.');
        chunk_catalog_check(\Gallery\Services\translation_catalog_chunks_count($chunks)
            === count(json_decode($json, true))
            && \Gallery\Services\translation_detected_language_packs()[0]['string_count']
                === count(json_decode($json, true)),
            'Public pack discovery must retain exact counts without flat maps.');
        $metadata = \Gallery\Services\translation_catalog_metadata_chunks('zz');
        chunk_catalog_check(
            \Gallery\Services\translation_catalog_chunks_count($metadata)
                === count(json_decode($json, true))
            && array_filter(array_merge(...$metadata),
                static fn(mixed $value): bool => $value !== null) === [],
            'Metadata discovery must retain keys without all translated values.');
        file_put_contents($discoveryPath, '{"key":"discovery-only"}');
        \Gallery\Services\translation_catalog_chunks('zy', false);
        file_put_contents($discoveryPath, '{"key":"first-real-lookup"}');
        chunk_catalog_check(
            \Gallery\Services\translation_catalog_value('zy', 'key') === 'first-real-lookup',
            'Metadata-only reads must release an otherwise unused language pack.');
        chunk_catalog_check(
            \Gallery\Services\translation_catalog_value('zz', 'duplicate') === 'last'
            && \Gallery\Services\translation_catalog_value('zz', 'empty') === ''
            && \Gallery\Services\translation_catalog_value('zz', 'null') === null,
            'Individual lookups lost duplicate, empty-string or null semantics.');
        foreach ([
            "{\n\"key\": 1,\n}\n",
            "{\n\"key\": 1\n\"other\": 2\n}\n",
            "{\n\"key\": 1\n}\nfalse\n",
        ] as $invalid) {
            file_put_contents($path, $invalid);
            $GLOBALS['cms_translation_cache_version']++;
            chunk_catalog_check(
                \Gallery\Services\translation_catalog_read_chunks($path) === null
                && \Gallery\Services\translation_catalog_chunks('zz') === [],
                'Malformed JSON must not be accepted by the optimized reader.');
        }
        // Editable packs may use minified or multiline values permanently.
        foreach (['{"key":"native"}', "{\n\"key\": [\n1,\n2\n]\n}"] as $editable) {
            file_put_contents($path, $editable);
            $GLOBALS['cms_translation_cache_version']++;
            chunk_catalog_check(array_replace(
                ...\Gallery\Services\translation_catalog_chunks('zz'),
            ) === json_decode($editable, true),
                'The native decoder must retain arbitrary valid formatting.');
        }
        unlink($path);
        file_put_contents($phpPath, '<?php return ["legacy" => "supported"];');
        $GLOBALS['cms_translation_cache_version']++;
        chunk_catalog_check(
            \Gallery\Services\translation_catalog_value('zz', 'legacy') === 'supported',
            'Absent JSON must retain the legacy PHP fallback.');
    } finally {
        foreach ([$path, $phpPath, $discoveryPath, $servicePath, $adapterPath]
            as $fixturePath
        ) {
            if (is_file($fixturePath)) unlink($fixturePath);
        }
        rmdir($directory . '/lang');
        rmdir($directory . '/services');
        rmdir($directory);
    }
    echo "Translation chunk lookup and native compatibility: PASS\n";
}
