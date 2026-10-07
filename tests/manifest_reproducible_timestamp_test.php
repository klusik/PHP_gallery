<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/manifest_reproducible_timestamp_test.php
 * Module Type: Regression Test
 * Purpose: Verify the actual manifest generator's reproducible timestamp and invalid-input refusal.
 * Responsibilities:
 *   - Exercise a stable build epoch and the ordinary current-clock behavior
 *   - Refuse malformed, negative and out-of-range build timestamps
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/scripts/generate_manifest.php');
if (preg_match('/function manifest_generation_timestamp\(\): string\n\{[\s\S]*?\n\}/', $source, $match) !== 1) {
    throw new RuntimeException('Manifest timestamp declaration is unavailable.');
}
// Compile the actual isolated declaration, without executing the generator's writes.
eval($match[0]);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$previous = getenv('SOURCE_DATE_EPOCH');
try {
    putenv('SOURCE_DATE_EPOCH=1700000000');
    $assert(manifest_generation_timestamp() === '2023-11-14T22:13:20+00:00', 'Stable build epoch was not preserved.');
    $assert(manifest_generation_timestamp() === manifest_generation_timestamp(), 'Repeated generation changed the timestamp.');
    putenv('SOURCE_DATE_EPOCH=0');
    $assert(manifest_generation_timestamp() === '1970-01-01T00:00:00+00:00', 'Zero is a valid Unix epoch.');
    foreach (['-1', 'abc', '1.5', '01', '253402300800', '99999999999999999999'] as $invalid) {
        putenv('SOURCE_DATE_EPOCH=' . $invalid);
        $refused = false;
        try {
            manifest_generation_timestamp();
        } catch (InvalidArgumentException) {
            $refused = true;
        }
        $assert($refused, 'Invalid SOURCE_DATE_EPOCH was accepted.');
    }
    putenv('SOURCE_DATE_EPOCH');
    $before = time();
    $current = strtotime(manifest_generation_timestamp());
    $assert($current !== false && $current >= $before && $current <= time(), 'Ordinary generation lost current-clock behavior.');
} finally {
    putenv($previous === false ? 'SOURCE_DATE_EPOCH' : 'SOURCE_DATE_EPOCH=' . $previous);
}
echo "PASS reproducible manifest timestamp and invalid build epoch refusal\n";
