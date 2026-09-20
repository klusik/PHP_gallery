<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/runtime_support_test.php
 * Module Type: Regression Test
 * Purpose: Verify offline PHP lifecycle policy boundaries.
 * Responsibilities:
 *   - Exercise date edges and bounded diagnostic states deterministically.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Deterministic offline lifecycle boundaries and bounded diagnostics contracts.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/runtime_support.php';

use function Gallery\Services\runtime_support_status;

/**
 * Refuse an incorrect advisory result with a non-sensitive assertion message.
 *
 * @param bool $condition Whether the lifecycle contract holds.
 * @param string $message Static description of the failing contract.
 * @return void
 */
function runtime_support_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$review = new DateTimeImmutable('2026-09-20T12:00:00Z');
foreach ([80134 => 'end_of_life', 80200 => 'security_only', 80330 => 'security_only',
    80400 => 'active', 80500 => 'active', 80600 => 'unknown', 90000 => 'unknown', -1 => 'unknown'] as $version => $state) {
    $status = runtime_support_status($version, $review);
    runtime_support_assert($status['state'] === $state, 'Reviewed PHP branch lifecycle mismatch.');
    runtime_support_assert($status['action_required'] === !$status['deployment_recommended'], 'Advisory action must follow deployment policy.');
    runtime_support_assert(count($status['suggested_checks']) <= 2 && strlen(json_encode($status, JSON_THROW_ON_ERROR)) < 1100,
        'Diagnostics must remain bounded.');
}
runtime_support_assert(runtime_support_status(80134, $review)['compatible'], 'PHP 8.1 compatibility must remain supported.');
runtime_support_assert(!runtime_support_status(80030, $review)['compatible'], 'Older syntax compatibility must not be promised.');
runtime_support_assert(!runtime_support_status(80200, $review)['deployment_recommended'], 'Upstream support alone does not define the deployment recommendation.');
runtime_support_assert(runtime_support_status(80300, $review)['deployment_recommended'], 'PHP 8.3 is the maintained deployment minimum.');
runtime_support_assert(!runtime_support_status(80600, $review)['deployment_recommended'], 'Unknown future branches need review.');
runtime_support_assert(runtime_support_status(80500, new DateTimeImmutable('2026-09-19T23:59:59Z'))['state'] === 'unknown', 'Pre-review evaluation must not invent historical support.');
runtime_support_assert(runtime_support_status(80400, new DateTimeImmutable('2026-12-31T23:59:59Z'))['state'] === 'active', 'Active support end day is inclusive.');
runtime_support_assert(runtime_support_status(80400, new DateTimeImmutable('2027-01-01T00:00:00Z'))['state'] === 'security_only', 'Active support expires at the next UTC day.');
runtime_support_assert(runtime_support_status(80300, new DateTimeImmutable('2027-12-31T23:59:59Z'))['deployment_recommended'], 'Security support end day is inclusive.');
runtime_support_assert(runtime_support_status(80300, new DateTimeImmutable('2028-01-01T00:00:00Z'))['state'] === 'end_of_life', 'Expired maintained minimum must warn without changing the runtime minimum.');
runtime_support_assert(runtime_support_status(80300, new DateTimeImmutable('2028-01-01T01:00:00+02:00'))['state'] === 'security_only', 'Lifecycle boundaries use UTC rather than host timezone.');
runtime_support_assert(runtime_support_status(80500, new DateTimeImmutable('2030-01-01T00:00:00Z'))['action_required'], 'Expired preferred branches require a policy review.');
echo "PASS runtime support offline lifecycle boundaries and bounded diagnostics\n";
