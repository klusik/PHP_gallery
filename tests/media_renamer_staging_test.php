<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/media_renamer_staging_test.php
 * Module Type: Regression Test
 * Purpose: Exercise long original names through real rename staging and rollback.
 * Responsibilities:
 *   - Keep temporary basenames within filesystem component limits
 *   - Preserve original bytes through staged and finalized rollback
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/../app/services/media_renamer.php';

use function Gallery\Services\media_renamer_rollback_file_moves;
use function Gallery\Services\media_renamer_staging_path;

/**
 * Refuse a broken filesystem regression with a bounded diagnostic.
 *
 * @param bool $condition Whether the observed filesystem behavior is correct.
 * @param string $message Test-authored description of the expected behavior.
 * @return void Throws RuntimeException when the condition is false.
 */
function media_staging_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/gallery-renamer-' . uniqid();
media_staging_expect(
    mkdir($root),
    'Could not create the owned test directory.',
);
$ownedPaths = [];

try {
    // 171 is the old last-valid boundary; 172 caused a 256-byte staging name.
    // 240 is the generated-name ceiling; 255 is the filesystem component limit.
    foreach ([171, 172, 240, 255] as $nameBytes) {
        $name = str_repeat('a', $nameBytes - strlen('.png')) . '.png';
        $source = $root . '/' . $name;
        $payload = 'Original bytes for filename boundary ' . $nameBytes;
        $ownedPaths[] = $source;
        media_staging_expect(
            file_put_contents($source, $payload) === strlen($payload),
            'Could not write a boundary original.',
        );

        $staging = media_renamer_staging_path($source);
        $other = media_renamer_staging_path($source);
        $ownedPaths[] = $staging;
        media_staging_expect(
            dirname($staging) === dirname($source)
                && strlen(basename($staging)) <= 255,
            'Staging escaped the directory or exceeded the component limit.',
        );
        media_staging_expect(
            $staging !== $other
                && !file_exists($staging)
                && !file_exists($other)
                && is_file($source),
            'Allocation reused a name or changed files before staging.',
        );
        media_staging_expect(
            rename($source, $staging),
            'Long-name staging failed.',
        );
        media_renamer_rollback_file_moves([], [
            ['from' => $source, 'staging' => $staging],
        ]);
        media_staging_expect(
            file_get_contents($source) === $payload && !file_exists($staging),
            'Staged rollback did not preserve the original bytes.',
        );

        $final = $root . '/renamed_' . $nameBytes . '.png';
        $ownedPaths[] = $final;
        media_staging_expect(rename($source, $staging), 'Restaging failed.');
        media_staging_expect(rename($staging, $final), 'Final rename failed.');
        media_staging_expect(
            file_get_contents($final) === $payload,
            'The final rename changed original bytes.',
        );
        media_renamer_rollback_file_moves(
            [['from' => $source, 'to' => $final]],
            [['from' => $source, 'staging' => $staging]],
        );
        media_staging_expect(
            file_get_contents($source) === $payload
                && !file_exists($final)
                && !file_exists($staging),
            'Finalized rollback did not restore the original filename and bytes.',
        );
    }
    echo "PASS media renamer long-name staging and physical rollback\n";
} finally {
    foreach ($ownedPaths as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    rmdir($root);
}
