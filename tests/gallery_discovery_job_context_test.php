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

namespace Gallery\Core {
    /**
     * Preserve an already normalized fixture path.
     * @param string $path Synthetic relative path.
     * @return string The same path.
     */
    function normalize_relative_path(string $path): string
    {
        return $path;
    }
}

namespace Gallery\Services {
    const LINKED_VISIBILITY_PENDING_MARKER = '.gallery-visibility-pending.json';

    /**
     * Simulate bounded marker observation without a filesystem or database.
     * @param string $relativePath Synthetic marker path.
     * @param int|null $remainingEntries Shared entry budget, decremented by reference.
     * @return string Central missing or unknown observation state.
     */
    function gallery_creation_target_observation(
        string $relativePath,
        ?int &$remainingEntries = null,
    ): string {
        $cost = $GLOBALS['discovery_fixture_costs'][$relativePath] ?? 1;
        if ($remainingEntries !== null && $cost >= $remainingEntries) {
            $remainingEntries = 0;
            return \Gallery\Core\FILESYSTEM_OBSERVATION_UNKNOWN;
        }
        if ($remainingEntries !== null) {
            $remainingEntries -= $cost;
        }
        return in_array($relativePath, $GLOBALS['discovery_fixture_unknown'] ?? [], true)
            ? \Gallery\Core\FILESYSTEM_OBSERVATION_UNKNOWN
            : \Gallery\Core\FILESYSTEM_OBSERVATION_MISSING;
    }
}

namespace {
require_once dirname(__DIR__) . '/app/services/admin_gallery_discovery.php';
use const Gallery\Core\ADMIN_GALLERY_DISCOVERY_JOB_TTL_SECONDS;
use const Gallery\Core\ADMIN_GALLERY_DISCOVERY_RETAINED_JOB_LIMIT;
use function Gallery\Services\admin_gallery_discovery_cleanup_jobs;
use function Gallery\Services\admin_gallery_discovery_begin_marker_validation;
use function Gallery\Services\admin_gallery_discovery_job_status;
use function Gallery\Services\admin_gallery_discovery_public_state;
use function Gallery\Services\admin_gallery_discovery_read_job;
use function Gallery\Services\admin_gallery_discovery_validate_marker_batch;
use function Gallery\Services\admin_gallery_discovery_write_job;

/**
 * Require a discovery-state invariant without opening a session or database.
 * @param bool $condition Expected map/state postcondition.
 * @param string $message Safe fixture assertion label.
 * @return void
 */
function discovery_context_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$now = time();
$token = str_repeat('a', 24);
$jobs = [];
$otherCaller = [];
$_SESSION = ['sentinel' => 'not-service-owned'];
$job = [
    'token' => $token,
    'status' => 'running',
    'started_at' => $now,
    'updated_at' => $now,
    'queue' => [''],
    'queue_index' => 0,
];
admin_gallery_discovery_write_job($jobs, $job);
discovery_context_assert(
    admin_gallery_discovery_read_job($jobs, $token) === $job,
    'Checkpoint changed during storage.',
);
discovery_context_assert(
    admin_gallery_discovery_read_job($otherCaller, $token) === null,
    'A caller discovered another caller\'s checkpoint.',
);
discovery_context_assert(
    $_SESSION === ['sentinel' => 'not-service-owned'],
    'Domain service accessed the session transport.',
);
$jobs[$token]['updated_at'] =
    $now - ADMIN_GALLERY_DISCOVERY_JOB_TTL_SECONDS - 2;
discovery_context_assert(
    admin_gallery_discovery_read_job($jobs, $token) === null
        && !isset($jobs[$token]),
    'Expired checkpoint survived admission.',
);
discovery_context_assert(
    admin_gallery_discovery_job_status($token, $jobs)['status'] === 'missing',
    'Missing job status changed.',
);
for (
    $index = 0;
    $index < (ADMIN_GALLERY_DISCOVERY_RETAINED_JOB_LIMIT + 2);
    $index++
) {
    $key = str_pad(dechex($index + 1), 24, '0', STR_PAD_LEFT);
    $jobs[$key] = ['token' => $key, 'updated_at' => $now - $index];
}
$jobs['malformed'] = false;
admin_gallery_discovery_cleanup_jobs($jobs);
discovery_context_assert(
    count($jobs) === ADMIN_GALLERY_DISCOVERY_RETAINED_JOB_LIMIT,
    'Discovery retention cap changed.',
);
discovery_context_assert(
    array_column(array_values($jobs), 'updated_at') === range(
        $now,
        $now - ADMIN_GALLERY_DISCOVERY_RETAINED_JOB_LIMIT + 1,
    ),
    'Newest checkpoint order changed.',
);
$source = (string) file_get_contents(
    dirname(__DIR__) . '/app/services/admin_gallery_discovery.php',
);
discovery_context_assert(
    !str_contains($source, '$_SESSION'),
    'Discovery domain module still extracts session transport.',
);
$candidates = [
    ['folder_path' => 'one'],
    ['folder_path' => 'two/child'],
    ['folder_path' => 'three'],
];
$verification = admin_gallery_discovery_begin_marker_validation([
    'status' => 'complete', 'candidates' => $candidates,
]);
foreach ([1, 2] as $expectedCursor) {
    $verification = admin_gallery_discovery_validate_marker_batch($verification, 1);
    discovery_context_assert(
        $verification['status'] === 'running'
            && $verification['marker_validation_cursor'] === $expectedCursor
            && admin_gallery_discovery_public_state($verification, true)['candidates'] === [],
        'Partial marker batches must advance without exposing candidate rows.',
    );
}
$verification = admin_gallery_discovery_validate_marker_batch($verification, 1);
discovery_context_assert(
    $verification['status'] === 'complete'
        && $verification['marker_validation_complete'] === true
        && $verification['candidates'] === $candidates,
    'Only fully verified batches may publish the complete candidate snapshot.',
);
discovery_context_assert(
    admin_gallery_discovery_begin_marker_validation($verification) === $verification,
    'An already verified checkpoint must not restart validation on status reads.',
);
$GLOBALS['discovery_fixture_unknown'] = ['two/.gallery-visibility-pending.json'];
$verification = admin_gallery_discovery_begin_marker_validation([
    'status' => 'complete', 'candidates' => $candidates,
]);
$verification = admin_gallery_discovery_validate_marker_batch($verification, 3);
discovery_context_assert(
    $verification['status'] === 'error' && $verification['candidates'] === [],
    'A marker or unknown ancestor must fail without exposing earlier rows.',
);
$GLOBALS['discovery_fixture_unknown'] = [];
$GLOBALS['discovery_fixture_costs'] = [
    'one/.gallery-visibility-pending.json' =>
        \Gallery\Services\ADMIN_GALLERY_DISCOVERY_MARKER_ENTRY_BUDGET,
];
$verification = admin_gallery_discovery_begin_marker_validation([
    'status' => 'complete', 'candidates' => $candidates,
]);
$verification = admin_gallery_discovery_validate_marker_batch($verification, 3);
discovery_context_assert(
    $verification['status'] === 'error' && $verification['candidates'] === [],
    'Entry-budget exhaustion cannot masquerade as confirmed marker absence.',
);
echo "Gallery discovery caller-context checks passed.\n";
}
