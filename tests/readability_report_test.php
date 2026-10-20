<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/readability_report_test.php
 * Module Type: Advisory Report Regression
 * Purpose: Execute the real Python readability contracts under the central audit.
 * Responsibilities: Retain complete child output and require actual success.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/audit_lib.php';

/**
 * Bound the Python readability regression process and retain its child output.
 * @var int
 * Units: seconds. Scope: one invocation of this PHP regression bridge.
 * Consumers: the central run_process() call below.
 * Rationale: retain 75 seconds for the complete fixture set, above its 45-second
 * report child budgets and below the optional profile's 90-second outer budget.
 */
const READABILITY_CONTRACT_CHILD_TIMEOUT_SECONDS = 75;

$python = \PhpGallery\Audit\resolve_python_command();
if ($python === null) {
    fwrite(STDERR, "BLOCKED: Python is required for readability contracts.\n");
    exit(2);
}
$process = \PhpGallery\Audit\run_process(
    array_merge($python, [
        __DIR__ . '/readability_report_test.py',
    ]),
    dirname(__DIR__),
    READABILITY_CONTRACT_CHILD_TIMEOUT_SECONDS,
);
echo $process['stdout'];
fwrite(STDERR, $process['stderr']);
if ($process['timed_out'] || $process['exit_code'] !== 0) {
    exit(1);
}
echo "PASS: advisory readability contracts.\n";
