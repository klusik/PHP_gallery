<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_report_job_context_test.php
 * Module Type: Regression Test
 * Purpose: Verify report checkpoints do not depend on session transport.
 * Responsibilities:
 *   - Check caller isolation, complete replacement, clearing and missing-job handling.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/admin_gallery_report.php';
require_once __DIR__ . '/support/module_source.php';
use function Gallery\Services\admin_gallery_report_job_clear;
use function Gallery\Services\admin_gallery_report_job_read;
use function Gallery\Services\admin_gallery_report_job_write;
use function Gallery\Services\admin_gallery_report_process_job;

/**
 * Require a checkpoint invariant without a session, database or generated report.
 * @param bool $condition Expected caller-state property.
 * @param string $message Safe assertion label.
 * @return void
 */
function report_context_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$_SESSION = ['sentinel' => 'request-owned'];
$checkpoint = null;
$otherCaller = null;
$state = admin_gallery_report_process_job($checkpoint);
report_context_assert($state['ok'] === false && $state['status'] === 'missing', 'Absent checkpoint was not handled without storage.');
$first = ['job_id' => 'fixture-job', 'status' => 'running', 'processed' => 1, 'total' => 2, 'last_image_id' => 7];
admin_gallery_report_job_write($checkpoint, $first);
report_context_assert(admin_gallery_report_job_read($checkpoint) === $first, 'Published checkpoint changed.');
report_context_assert(admin_gallery_report_job_read($otherCaller) === null, 'Another caller inherited report state.');
$read = admin_gallery_report_job_read($checkpoint);
$read['processed'] = 2;
report_context_assert($checkpoint['processed'] === 1, 'Reading a checkpoint exposed an implicit writable alias.');
admin_gallery_report_job_write($checkpoint, $read);
report_context_assert($checkpoint['processed'] === 2, 'Explicit batch publication was lost.');
admin_gallery_report_job_clear($checkpoint);
report_context_assert($checkpoint === null && admin_gallery_report_process_job($checkpoint)['status'] === 'missing', 'Completed checkpoint survived clearing.');
report_context_assert($_SESSION === ['sentinel' => 'request-owned'], 'Report service changed session transport.');
$source = module_source(dirname(__DIR__) . '/app/services/admin_gallery_report.php');
report_context_assert(!str_contains($source, '$_SESSION'), 'A report module part still extracts session state.');
echo "Gallery report caller-context checks passed.\n";
