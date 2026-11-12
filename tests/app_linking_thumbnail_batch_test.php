<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_thumbnail_batch_test.php
 * Module Type: Regression test
 * Purpose: Verify canonical batch admission and weighted shared rate accounting.
 * Responsibilities: Exercise production parsing and rate orchestration through
 *   an isolated limiter seam without touching application storage.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Supply a fixed non-sensitive test address.
     *
     * @return string Canonical fixture address.
     */
    function request_client_ip(): string
    {
        return '127.0.0.1';
    }

    /**
     * Record a weighted admission and optionally simulate refusal.
     *
     * @param string $bucket Selected production bucket.
     * @param string $subject Privacy-sensitive subject replaced by fixture data.
     * @param int $cost Requested work units.
     * @return array{allowed:bool,retry_after_seconds:int,attempts:int,reason:string} Fixture result.
     */
    function app_linking_rate_limit_consume(string $bucket, string $subject, int $cost = 1): array
    {
        $GLOBALS['batch_calls'][] = [$bucket, $subject, $cost];
        return ['allowed' => $bucket !== ($GLOBALS['batch_denied'] ?? ''),
            'retry_after_seconds' => 0, 'attempts' => $cost, 'reason' => 'fixture'];
    }
}

namespace {
    require_once __DIR__ . '/../app/controllers/app_linking.php';

    use function Gallery\Services\app_linking_batch_admit;
    use function Gallery\Controllers\app_linking_batch_parse;

    /**
     * Fail the isolated fixture with a descriptive message.
     *
     * @param bool $condition Assertion result.
     * @param string $message Failure context.
     * @return void Exits on failure.
     */
    function batch_test_check(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, $message . "\n");
            exit(1);
        }
    }

    $valid = '{"v":1,"gallery_id":"42","image_ids":["101","102"]}';
    batch_test_check(app_linking_batch_parse($valid) === [
        'gallery_id' => 42, 'image_ids' => ['101', '102'],
    ], 'Valid canonical request must preserve order');
    foreach ([
        '{"v":1,"v":1,"gallery_id":"42","image_ids":["101"]}',
        '{"v":1,"gallery_id":"42","image_ids":["101","101"]}',
        '{"v":1,"gallery_id":"042","image_ids":["101"]}',
        '{"v":1,"gallery_id":"9223372036854775808","image_ids":["101"]}',
        '{"v":1,"gallery_id":"42","image_ids":[101]}',
        $valid . '{}',
        '{"v":1, "gallery_id":"42","image_ids":["101"]}',
        '{"v":true,"gallery_id":"42","image_ids":["101"]}',
        '{"v":1,"gallery_id":"42","image_ids":["101","9223372036854775808"]}',
        '{"v":1,"gallery_id":"42","image_ids":[' . implode(',', array_map(static fn(int $id): string => '"' . $id . '"', range(1, 33))) . ']}',
        '{"v":1,"gallery_id":"42","image_ids":[]}',
    ] as $invalid) {
        batch_test_check(app_linking_batch_parse($invalid) === null, 'Invalid request accepted');
    }
    foreach ([1, 8, 24, 32] as $count) {
        $GLOBALS['batch_calls'] = [];
        batch_test_check(app_linking_batch_admit(17, $count)['allowed'], 'Expected weighted admission');
        batch_test_check(array_column($GLOBALS['batch_calls'], 0) === [
            'app_link_photo_ip', 'app_link_photo_global', 'app_link_photo_account',
        ], 'Unexpected rate buckets');
        batch_test_check(array_column($GLOBALS['batch_calls'], 2) === [$count, $count, $count],
            'Every bucket must receive exactly the photo count');
    }
    $GLOBALS['batch_calls'] = [];
    $GLOBALS['batch_denied'] = 'app_link_photo_global';
    batch_test_check(!app_linking_batch_admit(17, 8)['allowed'], 'Denied rate budget must fail closed');
    batch_test_check(count($GLOBALS['batch_calls']) === 2, 'Refusal must stop admission');
    fwrite(STDOUT, "thumbnail batch admission checks passed\n");
}
