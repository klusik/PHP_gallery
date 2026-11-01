<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/store_zip.php
 * Module Type: Regression Test Support
 * Purpose: Package supplied fixture bytes as a portable store-only browser ZIP.
 * Responsibilities: Preserve entry bytes and emit matching local/central records.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

/**
 * Encode a standards-compliant small store-only ZIP without requiring ZipArchive.
 *
 * Fixed signatures, version fields and offsets below are ZIP specification
 * structure, not runtime policy. No image encoding or application writer runs.
 * This includes local headers, matching central directory and the end record.
 *
 * @param list<array{name:string,data:string}> $entries Ordered archive records.
 * @return string Complete ZIP byte stream with stored compression.
 */
function gallery_workflow_store_zip(array $entries): string
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
        $localRecords .=
            pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $length,
                $length,
                $nameLength,
                0,
            )
            . $name
            . $data;
        $centralDirectory .=
            pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                0,
                0,
                0,
                0,
                $crc,
                $length,
                $length,
                $nameLength,
                0,
                0,
                0,
                0,
                0,
                $localOffset,
            ) . $name;
    }
    $count = count($entries);
    return $localRecords
        . $centralDirectory
        . pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $count,
            $count,
            strlen($centralDirectory),
            strlen($localRecords),
            0,
        );
}

