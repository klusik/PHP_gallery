<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/duplicate_detector_job_context_test.php
 * Module Type: Regression Test
 * Purpose: Verify detector checkpoint ownership independently of HTTP sessions.
 * Responsibilities:
 *   - Preserve empty-scope completion, caller isolation, expiry and retention ordering.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models {
    /**
     * Supply a confirmed empty global metadata scope without opening a database.
     * @param list<int> $galleryIds Empty for this authorized global-scope fixture.
     * @return array{total:int,max_image_id:int} Empty immutable scan bounds.
     */
    function duplicate_photo_model_scope_snapshot(array $galleryIds): array
    {
        if ($galleryIds !== []) { throw new \RuntimeException('Unexpected fixture scope.'); }
        return ['total' => 0, 'max_image_id' => 0];
    }
}
namespace {
    require_once dirname(__DIR__) . '/app/services/duplicate_photo_detector.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_duplicate_photos.php';
    use const Gallery\Core\DUPLICATE_PHOTO_DETECTOR_JOB_TTL_SECONDS;
    use const Gallery\Core\DUPLICATE_PHOTO_DETECTOR_MAX_SESSION_JOBS;
    use const Gallery\Core\DUPLICATE_PHOTO_DETECTOR_TOKEN_BYTES;
    use function Gallery\Services\duplicate_photo_detector_cleanup_jobs;
    use function Gallery\Services\duplicate_photo_detector_process_job;
    use function Gallery\Services\duplicate_photo_detector_read_job;
    use function Gallery\Services\duplicate_photo_detector_remove_image_from_job;
    use function Gallery\Services\duplicate_photo_detector_start_job;
    use function Gallery\Controllers\admin_duplicate_photos_job_store;

    /**
     * Require a detector checkpoint invariant without accessing live media or rows.
     * @param bool $condition Expected checkpoint or boundary property.
     * @param string $message Safe assertion label.
     * @return void
     */
    function detector_context_assert(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    $_SESSION = ['sentinel' => 'controller-owned'];
    $jobs = [];
    $otherCaller = [];
    $state = duplicate_photo_detector_start_job(['gallery_id' => 7, 'search_all' => true], $jobs);
    $token = $state['job_token'];
    detector_context_assert($state['done'] === true && $state['total'] === 0 && isset($jobs[$token]), 'Empty-scope completion lost its checkpoint.');
    detector_context_assert(strlen($token) === DUPLICATE_PHOTO_DETECTOR_TOKEN_BYTES * 2 && ctype_xdigit($token), 'Detector identifier entropy/encoding changed.');
    detector_context_assert(duplicate_photo_detector_read_job($token, $otherCaller) === null, 'Detector checkpoint leaked across callers.');
    detector_context_assert(duplicate_photo_detector_process_job($token, $jobs) === $state, 'Completed job resumed database work or changed its result.');
    detector_context_assert(duplicate_photo_detector_remove_image_from_job($token, 99, $jobs) !== null, 'Checkpoint pruning lost the selected completed job.');
    detector_context_assert($_SESSION === ['sentinel' => 'controller-owned'], 'Detector policy accessed session transport.');
    $jobs[$token]['updated_at'] = time() - DUPLICATE_PHOTO_DETECTOR_JOB_TTL_SECONDS - 2;
    detector_context_assert(duplicate_photo_detector_read_job($token, $jobs) === null && !isset($jobs[$token]), 'Expired job remained readable.');
    $now = time();
    for ($index = 0; $index < DUPLICATE_PHOTO_DETECTOR_MAX_SESSION_JOBS + 2; $index++) {
        $key = str_pad(dechex($index + 1), DUPLICATE_PHOTO_DETECTOR_TOKEN_BYTES * 2, '0', STR_PAD_LEFT);
        $jobs[$key] = ['token' => $key, 'updated_at' => $now - $index];
    }
    $jobs['malformed'] = false;
    duplicate_photo_detector_cleanup_jobs($jobs);
    detector_context_assert(count($jobs) === DUPLICATE_PHOTO_DETECTOR_MAX_SESSION_JOBS, 'Pre-start retention cap changed.');
    detector_context_assert(array_column(array_values($jobs), 'updated_at') === range($now, $now - DUPLICATE_PHOTO_DETECTOR_MAX_SESSION_JOBS + 1), 'Retention stopped preserving newest-first order.');
    $httpState = duplicate_photo_detector_start_job(['gallery_id' => 7, 'search_all' => true], admin_duplicate_photos_job_store());
    detector_context_assert(isset($_SESSION['admin_duplicate_photo_detector_jobs'][$httpState['job_token']]) && $_SESSION['sentinel'] === 'controller-owned', 'Controller adapter failed to publish the service checkpoint by reference.');
    $source = (string) file_get_contents(dirname(__DIR__) . '/app/services/duplicate_photo_detector.php');
    detector_context_assert(!str_contains($source, '$_SESSION'), 'Detector domain module still extracts session state.');
    echo "Duplicate detector caller-context checks passed.\n";
}
