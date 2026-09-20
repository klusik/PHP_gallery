<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_discovery_job_context_test.php
 * Module Type: Regression Test
 * Purpose: Prove discovery checkpoint policy is independent of session transport.
 * Responsibilities:
 *   - Preserve caller isolation, expiry, retention ordering and missing-job responses.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/admin_gallery_discovery.php';
use const Gallery\Core\ADMIN_GALLERY_DISCOVERY_JOB_TTL_SECONDS;
use const Gallery\Core\ADMIN_GALLERY_DISCOVERY_RETAINED_JOB_LIMIT;
use function Gallery\Services\admin_gallery_discovery_cleanup_jobs;
use function Gallery\Services\admin_gallery_discovery_job_status;
use function Gallery\Services\admin_gallery_discovery_read_job;
use function Gallery\Services\admin_gallery_discovery_write_job;

/**
 * Require a discovery-state invariant without opening a session or database.
 * @param bool $condition Expected map/state postcondition.
 * @param string $message Safe fixture assertion label.
 * @return void
 */
function discovery_context_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$now = time();
$token = str_repeat('a', 24);
$jobs = [];
$otherCaller = [];
$_SESSION = ['sentinel' => 'not-service-owned'];
$job = ['token' => $token, 'status' => 'running', 'started_at' => $now, 'updated_at' => $now, 'queue' => [''], 'queue_index' => 0];
admin_gallery_discovery_write_job($jobs, $job);
discovery_context_assert(admin_gallery_discovery_read_job($jobs, $token) === $job, 'Checkpoint changed during storage.');
discovery_context_assert(admin_gallery_discovery_read_job($otherCaller, $token) === null, 'A caller discovered another caller\'s checkpoint.');
discovery_context_assert($_SESSION === ['sentinel' => 'not-service-owned'], 'Domain service accessed the session transport.');
$jobs[$token]['updated_at'] = $now - ADMIN_GALLERY_DISCOVERY_JOB_TTL_SECONDS - 2;
discovery_context_assert(admin_gallery_discovery_read_job($jobs, $token) === null && !isset($jobs[$token]), 'Expired checkpoint survived admission.');
discovery_context_assert(admin_gallery_discovery_job_status($token, $jobs)['status'] === 'missing', 'Missing job status changed.');
for ($index = 0; $index < ADMIN_GALLERY_DISCOVERY_RETAINED_JOB_LIMIT + 2; $index++) {
    $key = str_pad(dechex($index + 1), 24, '0', STR_PAD_LEFT);
    $jobs[$key] = ['token' => $key, 'updated_at' => $now - $index];
}
$jobs['malformed'] = false;
admin_gallery_discovery_cleanup_jobs($jobs);
discovery_context_assert(count($jobs) === ADMIN_GALLERY_DISCOVERY_RETAINED_JOB_LIMIT, 'Discovery retention cap changed.');
discovery_context_assert(array_column(array_values($jobs), 'updated_at') === range($now, $now - ADMIN_GALLERY_DISCOVERY_RETAINED_JOB_LIMIT + 1), 'Newest checkpoint order changed.');
$source = (string) file_get_contents(dirname(__DIR__) . '/app/services/admin_gallery_discovery.php');
discovery_context_assert(!str_contains($source, '$_SESSION'), 'Discovery domain module still extracts session transport.');
echo "Gallery discovery caller-context checks passed.\n";
