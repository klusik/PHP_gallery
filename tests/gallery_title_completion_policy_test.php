<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_title_completion_policy_test.php
 * Module Type: Regression Test
 * Purpose: Verify Core-owned title work ceilings against the real disposable model/service.
 * Responsibilities:
 *   - Assert immutable namespace ownership, browser cap parity and bounded examined/fetched rows.
 *   - Preserve partial sibling suppression without loading configuration or an application database.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

use const Gallery\Core\GALLERY_TITLE_COMPLETION_MAX_CANDIDATES;
use const Gallery\Core\GALLERY_TITLE_COMPLETION_SCAN_BUDGET;
use const Gallery\Core\GALLERY_TITLE_COMPLETION_SIBLING_BUDGET;
use const Gallery\Core\GALLERY_TITLE_COMPLETION_PAGE_SIZE;
use function Gallery\Services\gallery_title_completion_candidates;
use function Gallery\Models\gallery_model_title_completion_rows;
use function Gallery\Tests\title_completion_fixture_rows;

require_once dirname(__DIR__) . '/app/policy_constants.php';

/**
 * Fail an isolated immutable-policy assertion without disclosing application state.
 * @param bool $condition Whether the policy contract holds.
 * @param string $message Fixture-owned failure description.
 * @return void Completes normally or raises a fixture assertion.
 */
function title_policy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @var array<string,int> $budgets Independent fixture expectations for immutable Core symbol values. */
$budgets = [
    'GALLERY_TITLE_COMPLETION_MAX_CANDIDATES' => 8,
    'GALLERY_TITLE_COMPLETION_SCAN_BUDGET' => 1024,
    'GALLERY_TITLE_COMPLETION_SIBLING_BUDGET' => 512,
    'GALLERY_TITLE_COMPLETION_PAGE_SIZE' => 512,
];
foreach ($budgets as $name => $expected) {
    title_policy_assert(constant('Gallery\\Core\\' . $name) === $expected, 'Core retains the exact reviewed title budget.');
}
title_policy_assert(GALLERY_TITLE_COMPLETION_SIBLING_BUDGET <= GALLERY_TITLE_COMPLETION_SCAN_BUDGET,
    'Sibling allowance fits the complete scan ceiling.');
title_policy_assert(GALLERY_TITLE_COMPLETION_PAGE_SIZE <= GALLERY_TITLE_COMPLETION_SCAN_BUDGET,
    'At least one service page fits the complete scan ceiling.');
$browserPolicy = (string) file_get_contents(dirname(__DIR__) . '/public/assets/gallery-modules/admin-interaction-policy.js');
title_policy_assert(preg_match('/export const GALLERY_TITLE_COMPLETION_RESULT_LIMIT = ([0-9]+);/', $browserPolicy, $match) === 1
    && (int) $match[1] === GALLERY_TITLE_COMPLETION_MAX_CANDIDATES, 'Browser guard agrees with the server response cap.');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "SKIP: title policy runtime fixture requires pdo_sqlite.\n");
    exit(0);
}
require_once __DIR__ . '/support/gallery_title_completion_fixture.php';
require_once dirname(__DIR__) . '/app/models/galleries.php';
require_once dirname(__DIR__) . '/app/services/gallery_picker.php';
foreach ($budgets as $name => $expected) {
    title_policy_assert(!defined('Gallery\\Services\\' . $name), 'The service has no duplicate constant owner.');
}
$fixture = new Gallery\Tests\TitleCompletionFixtureDatabase();
$GLOBALS['title_completion_fixture'] = $fixture;
$fixture->seed(title_completion_fixture_rows(10000));
$result = gallery_title_completion_candidates('no-match', 99999);
title_policy_assert($result['ok'] && $result['truncated'] && $result['candidates'] === [],
    'Absent-parent no-match search ends explicitly at the fixed fallback budget.');
title_policy_assert(count($fixture->queries) === 3, 'One empty sibling query plus two fallback pages.');
title_policy_assert(array_sum(array_column($fixture->queries, 'rows')) <= GALLERY_TITLE_COMPLETION_SCAN_BUDGET + 3,
    'Materialized pages stay within examined-row policy plus per-query lookahead.');
foreach ($fixture->queries as $query) {
    title_policy_assert(preg_match('/ LIMIT ([0-9]+)$/', $query['sql'], $match) === 1
        && (int) $match[1] <= GALLERY_TITLE_COMPLETION_PAGE_SIZE + 1, 'Each actual SQL page obeys the named page ceiling plus lookahead.');
}
$fixture->queries = [];
title_policy_assert(count(gallery_model_title_completion_rows(99999, false, PHP_INT_MAX))
    === GALLERY_TITLE_COMPLETION_PAGE_SIZE + 1, 'Independent model clamp remains aligned with the service page budget.');

$rows = title_completion_fixture_rows(GALLERY_TITLE_COMPLETION_SIBLING_BUDGET + 1);
foreach ($rows as &$row) {
    $row['parent_id'] = 7;
    $row['title'] = $row['id'] === 1 ? 'Needle oldest sibling' : 'Other sibling';
}
unset($row);
$rows[] = ['id' => 10001, 'parent_id' => null, 'title' => 'Needle fallback', 'created_at' => '2026-09-20 12:00:00'];
$fixture->seed($rows);
$result = gallery_title_completion_candidates('needle', 7);
title_policy_assert($result['truncated'] && $result['candidates'] === [] && count($fixture->queries) === 1,
    'An unexamined old sibling cannot be outranked by fallback after its allowance is exhausted.');

$fixture->seed(title_completion_fixture_rows(100));
$result = gallery_title_completion_candidates('fl', 7);
title_policy_assert(count($result['candidates']) === GALLERY_TITLE_COMPLETION_MAX_CANDIDATES
    && $result['truncated'], 'Matching still stops at exactly eight optional suggestions.');
unset($GLOBALS['title_completion_fixture']);
fwrite(STDOUT, "PASS: Core title ceilings, client parity, model cap and sibling-first truncation on disposable SQLite.\n");
