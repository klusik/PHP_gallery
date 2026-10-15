<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/audit_change_evidence_test.php
 * Module Type: Regression Test
 * Purpose: Prove that exact-SHA authored Git change provenance fails closed.
 * Responsibilities:
 *   - Validate NUL-separated changed-path records, including renames and bad data
 *   - Exercise fork-point comparison and generated-commit separation in an owned Git fixture
 *   - Reject dirty, stale, modified-history, untrusted and unexpected candidate input
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/audit_source_evidence.php';

use function PhpGallery\Audit\audit_source_change_impact;
use function PhpGallery\Audit\parse_git_name_status_z;
use function PhpGallery\Audit\parse_git_raw_delta_z;
use function PhpGallery\Audit\audit_cross_stage_tree_delta;
use function PhpGallery\Audit\resolve_executable;
use function PhpGallery\Audit\run_process;

$parserCases = [
    ['single M', "M\0docs/PHP_Gallery_Manual.tex\0", [['status' => 'M', 'path' => 'docs/PHP_Gallery_Manual.tex']]],
    ['tab and newline path', "M\0docs/odd\tname\n.tex\0", [['status' => 'M', 'path' => "docs/odd\tname\n.tex"]]],
    ['rename with source and destination', "R100\0docs/old.tex\0docs/new.tex\0", [['status' => 'R100', 'path' => 'docs/new.tex']]],
    ['copy with source and destination', "C90\0docs/old.tex\0docs/new.tex\0", [['status' => 'C90', 'path' => 'docs/new.tex']]],
    ['empty', '', []],
    ['unterminated', 'M' . "\0" . 'docs/PHP_Gallery_Manual.tex', null],
    ['incomplete rename', "R100\0docs/old.tex\0", null],
    ['invalid status', "not-a-status\0docs/file\0", null],
    ['empty path', "M\0\0", null],
    ['traversal', "M\0../docs/file\0", null],
    ['absolute path', "M\0/var/tmp/file\0", null],
    ['extra field', "M\0docs/file\0surplus\0", null],
    ['overlong', str_repeat('x', 1048577), null],
];
foreach ($parserCases as [$label, $raw, $expected]) {
    if (parse_git_name_status_z($raw) !== $expected) {
        throw new RuntimeException('Git diff NUL-parser disagreement: ' . $label);
    }
}



$blobA = str_repeat('a', 40);
$blobB = str_repeat('b', 40);
$zeroBlob = str_repeat('0', 40);
$rawModify = ":100644 100644 {$blobA} {$blobB} M\0app/bootstrap.php\0";
$rawAdd = ":000000 100644 {$zeroBlob} {$blobB} A\0docs/new.tex\0";
$rawDelete = ":100644 000000 {$blobA} {$zeroBlob} D\0docs/obsolete.tex\0";
$rawExpected = [[
    'status' => 'M', 'path' => 'app/bootstrap.php',
    'before_mode' => '100644', 'after_mode' => '100644',
    'before_blob' => $blobA, 'after_blob' => $blobB,
]];
$rawCases = [
    ['complete change with exact old/new blobs', $rawModify, $rawExpected],
    ['two complete changes', $rawAdd . $rawDelete, [
        ['status' => 'A', 'path' => 'docs/new.tex',
            'before_mode' => '000000', 'after_mode' => '100644',
            'before_blob' => $zeroBlob, 'after_blob' => $blobB],
        ['status' => 'D', 'path' => 'docs/obsolete.tex',
            'before_mode' => '100644', 'after_mode' => '000000',
            'before_blob' => $blobA, 'after_blob' => $zeroBlob],
    ]],
    ['empty complete diff', '', []],
    ['missing final NUL', substr($rawModify, 0, -1), null],
    ['missing path', ":100644 100644 {$blobA} {$blobB} M\0", null],
    ['duplicate path', $rawModify . $rawModify, null],
    ['rename instead of explicit add/delete', ":100644 100644 {$blobA} {$blobB} R100\0docs/old.tex\0docs/new.tex\0", null],
    ['traversal path', ":100644 100644 {$blobA} {$blobB} M\0../outside\0", null],
    ['absolute path', ":100644 100644 {$blobA} {$blobB} M\0/absolute\0", null],
    ['backslash path', ":100644 100644 {$blobA} {$blobB} M\0app\\weird.php\0", null],
    ['unsupported mode', ":100644 200000 {$blobA} {$blobB} M\0file\0", null],
    ['false add with previous blob', ":100644 100644 {$blobA} {$blobB} A\0file\0", null],
    ['false delete with remaining blob', ":100644 100644 {$blobA} {$blobB} D\0file\0", null],
    ['truncated hash', ":100644 100644 a {$blobB} M\0file\0", null],
    ['odd metadata field', $rawModify . "surplus\0", null],
    ['oversized raw diff', str_repeat('x', 1048577), null],
];
foreach ($rawCases as [$label, $raw, $expected]) {
    if (parse_git_raw_delta_z($raw) !== $expected) {
        throw new RuntimeException('Cross-stage blob-delta parser disagreement: ' . $label);
    }
}


// Phase 2b: synthetic GitHub API response shapes test deterministic
// correlation only. They are not independently authenticated hosted records.
$proofSource = str_repeat('a', 40);
$proofCandidate = str_repeat('b', 40);
$proofBranch = 'feature/docs';
$proofHistory = [
    'source_sha' => $proofSource,
    'candidate_sha' => $proofCandidate,
    'generated_paths' => ['app/core-manifest.json'],
    'provenance' => 'requires-hosted-proof',
];
$proofStepMap = [
    'Prepare source candidate at event SHA' => [
        'Check branch identity and source provenance',
        'Cheap source-policy preflight before generation',
        'Prepare and verify canonical candidate artifacts',
        'Commit only generated artifacts with a non-forced branch lease',
    ],
    'Full CI for the published prepared SHA / Read-only generated-state and source preflight' => [
        'Verify exact candidate checkout', 'Central candidate preflight (read only)',
    ],
    'Full CI for the published prepared SHA / PHP 8.1 source (unicode)' => ['Central source audit'],
    'Full CI for the published prepared SHA / PHP 8.5 source (ascii)' => ['Central source audit'],
    'Full CI for the published prepared SHA / PHP 8.3 workflows (mysql:8.4, Unicode)' => [
        'Discover Chromium for the real application workflow',
        'Central audit with mandatory real workflows and database races',
    ],
    'Full CI for the published prepared SHA / PHP 8.3 workflows (mariadb:10.11, Unicode)' => [
        'Central audit with mandatory real workflows and database races',
    ],
    'Full CI for the published prepared SHA / PHP 8.5 workflows (mariadb:11.4, Unicode)' => [
        'Central audit with mandatory real workflows and database races',
    ],
    'Full CI for the published prepared SHA / Required Chromium fixtures' => [
        'Discover and verify required Chromium', 'Central required browser audit',
    ],
    'Full CI for the published prepared SHA / Positive production package (ubuntu-24.04)' => [
        'Require current production membership and integrity data',
        'Build production folder with Bash', 'Inspect exact artifact membership',
    ],
    'Full CI for the published prepared SHA / Positive production package (windows-2025)' => [
        'Require current production membership and integrity data',
        'Build production folder with PowerShell', 'Inspect exact artifact membership',
    ],
    'Full CI for the published prepared SHA / Positive production package (macos-latest)' => [
        'Require current production membership and integrity data',
        'Build production folder with Bash', 'Inspect exact artifact membership',
    ],
    'Full CI for the published prepared SHA / Complete required CI matrix' => [
        'Refuse failed, blocked, missing or skipped mandatory owners',
    ],
    'Reject superseded qualification' => [
        'Verify successful qualification and unchanged published branch head',
        'Publish SHA-bound hosted qualification result',
    ],
];
$proofJobs = [];
foreach ($proofStepMap as $name => $stepNames) {
    $proofJobs[] = [
        'id' => 3000 + count($proofJobs),
        'name' => $name,
        'run_id' => 424242,
        'run_attempt' => 1,
        'head_sha' => $proofSource,
        'workflow_name' => 'Candidate preparation',
        'status' => 'completed',
        'conclusion' => 'success',
        'steps' => array_map(static fn(string $step): array => [
            'name' => $step, 'status' => 'completed', 'conclusion' => 'success',
        ], $stepNames),
    ];
}
$proofJobs[] = [
    'id' => 3013,
    'name' => 'Full CI for the published prepared SHA / Authoritative release audit',
    'run_id' => 424242,
    'run_attempt' => 1,
    'head_sha' => $proofSource,
    'workflow_name' => 'Candidate preparation',
    'status' => 'completed',
    'conclusion' => 'skipped',
    'steps' => [],
];
$proofChecks = [];
foreach (['Candidate qualification', 'Complete required CI matrix'] as $name) {
    $proofChecks[] = [
        'id' => 4000 + count($proofChecks),
        'name' => $name,
        'head_sha' => $proofCandidate,
        'status' => 'completed',
        'conclusion' => 'success',
        'external_id' => 'candidate:424242:1:' . $proofCandidate,
        'app' => ['id' => 15368],
        'check_suite' => ['id' => 3301],
    ];
}
// The source event and generated candidate have distinct SHA-bound suites.
// Check-run payloads expose only the suite ID, so retrieve suite identity separately.
$proofSuites = [[
    'id' => 3301,
    'head_sha' => $proofCandidate,
    'head_commit' => ['id' => $proofCandidate],
    'repository' => ['full_name' => 'klusik/PHP_gallery'],
    'app' => ['id' => 15368],
    'status' => 'completed',
    'conclusion' => 'success',
]];
$proof = [
    'run' => [
        'id' => 424242,
        'run_attempt' => 1,
        'name' => 'Candidate preparation',
        'repository' => ['full_name' => 'klusik/PHP_gallery'],
        'head_branch' => $proofBranch,
        'head_sha' => $proofSource,
        'head_commit' => ['id' => $proofSource],
        'workflow_id' => 377897247,
        'path' => '.github/workflows/candidate-preparation.yml',
        'event' => 'push',
        'status' => 'completed',
        'conclusion' => 'success',
        'check_suite_id' => 2201,
        'referenced_workflows' => [[
            'path' => 'klusik/PHP_gallery/.github/workflows/gallery-workflows.yml@' . $proofSource,
            'sha' => $proofSource,
        ]],
    ],
    'refs' => [
        'expected_head' => str_repeat('c', 40),
        'branch_before' => str_repeat('c', 40),
        'branch_after' => str_repeat('c', 40),
        'develop_before' => str_repeat('d', 40),
        'develop_after' => str_repeat('d', 40),
    ],
    'checks_pages' => [[
        'page' => 1, 'per_page' => 100,
        'response' => ['total_count' => count($proofChecks), 'check_runs' => $proofChecks],
    ]],
    'candidate_suites_pages' => [[
        'page' => 1, 'per_page' => 100,
        'response' => ['total_count' => count($proofSuites), 'check_suites' => $proofSuites],
    ]],
    'jobs_pages' => [[
        'page' => 1, 'per_page' => 100,
        'response' => ['total_count' => count($proofJobs), 'jobs' => $proofJobs],
    ]],
];
$proofCases = 0;
$correlate = static function (array $snapshot, array $boundary = []) use (
    $proofHistory, $proofBranch
): array {
    return \PhpGallery\Audit\audit_correlate_historical_candidate(
        $boundary === [] ? $proofHistory : $boundary, $proofBranch, $snapshot
    );
};
$assertProof = static function (array $snapshot, string $reason, string $label) use (
    $correlate, &$proofCases
): void {
    $actual = $correlate($snapshot);
    if ($actual['scope'] !== 'full' || $actual['reason'] !== $reason
        || $actual['correlated'] !== ($reason === 'workflow-authority-not-independently-attested')) {
        throw new RuntimeException('Hosted evidence fixture ' . $label . ': ' . json_encode($actual));
    }
    $proofCases++;
};
$assertProof($proof, 'workflow-authority-not-independently-attested',
    'distinct source and candidate suites correlate but remain advisory');
// Preserve the archived PHP 8.3 snapshot as history. The current PHP 8.5
// maintenance workflow has a separate authority path and cannot be inferred
// from this deliberately historical, non-authoritative correlator.
$currentMaintenance = $proof;
$currentMaintenance['run']['name'] = 'Basic source checks';
$currentMaintenance['run']['event'] = 'workflow_dispatch';
foreach ($currentMaintenance['jobs_pages'][0]['response']['jobs'] as &$currentJob) {
    $currentJob['workflow_name'] = 'Basic source checks';
    $currentJob['name'] = str_replace('PHP 8.3 workflows', 'PHP 8.5 workflows', $currentJob['name']);
    if (str_contains($currentJob['name'], '/ Positive production package (')) {
        $currentJob['steps'] = array_map(static fn(string $step): array => [
            'name' => $step, 'status' => 'completed', 'conclusion' => 'success',
        ], ['Central mandatory package policy, smoke and complete folder/ZIP integrity',
            'Upload exact-candidate packaging evidence and timings']);
    }
}
unset($currentJob);
$currentMaintenance['jobs_pages'][0]['response']['jobs'][] = [
    'id' => 3014, 'name' => 'Basic source checks', 'run_id' => 424242, 'run_attempt' => 1,
    'head_sha' => $proofSource, 'workflow_name' => 'Basic source checks',
    'status' => 'completed', 'conclusion' => 'success', 'steps' => [
        ['name' => 'Central BASIC syntax and declaration checks', 'status' => 'completed', 'conclusion' => 'success'],
    ],
];
$currentMaintenance['jobs_pages'][0]['response']['total_count']++;
$assertProof($currentMaintenance, 'invalid-source-workflow-run',
    'current PHP 8.5 maintenance shape cannot inherit historical advisory authority');
$assertProof(array_replace_recursive($proof, ['run' => ['event' => 'pull_request']]),
    'invalid-source-workflow-run', 'wrong event');
$assertProof(array_replace_recursive($proof, ['run' => ['head_sha' => str_repeat('f', 40)]]),
    'invalid-source-workflow-run', 'wrong source parent');
$assertProof(array_replace_recursive($proof, ['run' => ['run_attempt' => 2]]),
    'invalid-or-conflicting-candidate-check', 'wrong attempt');
$assertProof(array_replace_recursive($proof, ['run' => ['path' => '.github/workflows/other.yml']]),
    'invalid-source-workflow-run', 'wrong workflow');
$assertProof(array_replace_recursive($proof, ['run' => ['workflow_id' => 1]]),
    'invalid-source-workflow-run', 'wrong workflow identity');
$assertProof(array_replace_recursive($proof, ['run' => ['status' => 'in_progress']]),
    'invalid-source-workflow-run', 'pending workflow');
$assertProof(array_replace_recursive($proof, ['run' => ['conclusion' => 'failure']]),
    'invalid-source-workflow-run', 'failed workflow');
$assertProof(array_replace_recursive($proof, ['refs' => ['branch_after' => str_repeat('e', 40)]]),
    'refs-changed-or-unavailable', 'branch moved');
$assertProof(array_replace_recursive($proof, ['refs' => ['develop_after' => str_repeat('e', 40)]]),
    'refs-changed-or-unavailable', 'develop moved');

foreach (['failure', 'cancelled', 'skipped', null] as $outcome) {
    $bad = $proof;
    $bad['checks_pages'][0]['response']['check_runs'][0]['conclusion'] = $outcome;
    $assertProof($bad, 'invalid-or-conflicting-candidate-check',
        'non-success custom check ' . ($outcome ?? 'pending'));
}
$bad = $proof;
$bad['checks_pages'][0]['response']['check_runs'][0]['status'] = 'queued';
$assertProof($bad, 'invalid-or-conflicting-candidate-check', 'pending custom check');
$bad = $proof;
$bad['checks_pages'][0]['response']['check_runs'][0]['head_sha'] = $proofSource;
$assertProof($bad, 'invalid-or-conflicting-candidate-check', 'stale check SHA');
$bad = $proof;
$bad['checks_pages'][0]['response']['check_runs'][0]['app']['id'] = 123;
$assertProof($bad, 'invalid-or-conflicting-candidate-check', 'wrong publisher');
$bad = $proof;
$bad['checks_pages'][0]['response']['check_runs'][0]['external_id'] = 'candidate:1:1:' . $proofCandidate;
$assertProof($bad, 'invalid-or-conflicting-candidate-check', 'wrong source run ID');
$bad = $proof;
$bad['checks_pages'][0]['response']['check_runs'][0]['check_suite']['id'] = 1;
$assertProof($bad, 'invalid-or-conflicting-candidate-check', 'wrong check suite');
$bad = $proof;
array_pop($bad['checks_pages'][0]['response']['check_runs']);
$bad['checks_pages'][0]['response']['total_count'] = 1;
$assertProof($bad, 'missing-candidate-check', 'missing required check');
$bad = $proof;
$bad['checks_pages'][0]['response']['check_runs'][] = array_replace(
    $proofChecks[0], ['id' => 4002]
);
$bad['checks_pages'][0]['response']['total_count'] = 3;
$assertProof($bad, 'invalid-or-conflicting-candidate-check', 'duplicate same-name check');
$bad = $proof;
$bad['checks_pages'][0]['response']['total_count'] = 3;
$assertProof($bad, 'incomplete-or-conflicting-api-pages', 'missing result in API page');
$bad = $proof;
$bad['checks_pages'][0]['response']['check_runs'][1]['id'] = 4000;
$assertProof($bad, 'incomplete-or-conflicting-api-pages', 'repeated API record ID');

$bad = $proof;
$bad['jobs_pages'][0]['response']['jobs'][1]['conclusion'] = 'skipped';
$assertProof($bad, 'unexpected-or-failed-required-job', 'required job skipped');
$bad = $proof;
$bad['jobs_pages'][0]['response']['jobs'][1]['steps'][0]['conclusion'] = 'failure';
$assertProof($bad, 'failed-or-missing-required-job-step', 'required step failed');
$bad = $proof;
$bad['jobs_pages'][0]['response']['jobs'][1]['run_attempt'] = 2;
$assertProof($bad, 'invalid-or-conflicting-workflow-job', 'stale job attempt');
$bad = $proof;
$bad['jobs_pages'][0]['response']['jobs'][13]['conclusion'] = 'success';
$assertProof($bad, 'invalid-release-only-job', 'release-only job unexpectedly executed');
$bad = $proof;
array_pop($bad['jobs_pages'][0]['response']['jobs']);
$bad['jobs_pages'][0]['response']['total_count'] = 13;
$assertProof($bad, 'missing-required-workflow-job', 'required release-only record missing');
$bad = $proof;
$bad['jobs_pages'][0]['response']['total_count'] = 15;
$assertProof($bad, 'incomplete-or-conflicting-api-pages', 'incomplete jobs response');
$bad = $proof;
unset($bad['run']);
$assertProof($bad, 'invalid-source-workflow-run', 'GitHub API unavailable');
$bad = $proof;
$bad['run']['referenced_workflows'][0]['sha'] = str_repeat('f', 40);
$assertProof($bad, 'invalid-source-workflow-run', 'unexpected changed reusable workflow revision');

$manyChecks = $proofChecks;
for ($extra = 0; $extra < 99; $extra++) {
    $manyChecks[] = ['id' => 5000 + $extra, 'name' => 'Unrelated ' . $extra];
}
$paged = $proof;
$paged['checks_pages'] = [
    ['page' => 1, 'per_page' => 100, 'response' => [
        'total_count' => 101, 'check_runs' => array_slice($manyChecks, 0, 100),
    ]],
    ['page' => 2, 'per_page' => 100, 'response' => [
        'total_count' => 101, 'check_runs' => array_slice($manyChecks, 100),
    ]],
];
$assertProof($paged, 'workflow-authority-not-independently-attested',
    'two complete ordered API pages');
array_pop($paged['checks_pages']);
$assertProof($paged, 'incomplete-or-conflicting-api-pages', 'page two missing');

// Separate suite retrieval must preserve complete pagination and candidate identity.
$bad = $proof;
unset($bad['candidate_suites_pages']);
$assertProof($bad, 'incomplete-or-conflicting-api-pages', 'candidate suites API unavailable');
$bad = $proof;
$bad['candidate_suites_pages'][0]['response'] = ['total_count' => 0, 'check_suites' => []];
$assertProof($bad, 'invalid-or-missing-candidate-suite', 'referenced candidate suite missing');

$suiteCases = [
    ['wrong candidate SHA', ['head_sha' => $proofSource]],
    ['wrong suite commit', ['head_commit' => ['id' => $proofSource]]],
    ['wrong suite app', ['app' => ['id' => 123]]],
    ['foreign suite repository', ['repository' => ['full_name' => 'other/PHP_gallery']]],
    ['pending suite', ['status' => 'queued']],
    ['red suite', ['conclusion' => 'failure']],
    ['skipped suite', ['conclusion' => 'skipped']],
    ['cancelled suite', ['conclusion' => 'cancelled']],
    ['unfinished suite', ['conclusion' => null]],
];
foreach ($suiteCases as [$label, $change]) {
    $bad = $proof;
    $bad['candidate_suites_pages'][0]['response']['check_suites'][0] = array_replace_recursive(
        $proofSuites[0], $change
    );
    $assertProof($bad, 'invalid-or-missing-candidate-suite', $label);
}
foreach (['head_sha', 'head_commit', 'app', 'repository', 'status', 'conclusion'] as $field) {
    $bad = $proof;
    unset($bad['candidate_suites_pages'][0]['response']['check_suites'][0][$field]);
    $assertProof($bad, 'invalid-or-missing-candidate-suite', 'suite identity missing ' . $field);
}
foreach ([0, -1, '3301', null] as $suiteId) {
    $bad = $proof;
    $bad['checks_pages'][0]['response']['check_runs'][0]['check_suite']['id'] = $suiteId;
    $assertProof($bad, 'invalid-or-conflicting-candidate-check', 'invalid referenced suite ID');
    $bad = $proof;
    $bad['candidate_suites_pages'][0]['response']['check_suites'][0]['id'] = $suiteId;
    $assertProof($bad, 'incomplete-or-conflicting-api-pages', 'invalid suite API record ID');
}
$bad = $proof;
$bad['candidate_suites_pages'][0]['response']['check_suites'][] = $proofSuites[0];
$bad['candidate_suites_pages'][0]['response']['total_count'] = 2;
$assertProof($bad, 'incomplete-or-conflicting-api-pages', 'duplicate suite API record ID');
$bad = $proof;
$bad['candidate_suites_pages'][0]['response']['total_count'] = 2;
$assertProof($bad, 'incomplete-or-conflicting-api-pages', 'truncated suite response');
$bad = $proof;
$bad['run']['check_suite_id'] = 3301;
$assertProof($bad, 'invalid-or-missing-candidate-suite', 'source and candidate suite identity conflated');

$otherSuite = array_replace_recursive($proofSuites[0], [
    'id' => 3302, 'app' => ['id' => 34598], 'status' => 'queued', 'conclusion' => null,
]);
$withOtherSuite = $proof;
$withOtherSuite['candidate_suites_pages'][0]['response']['check_suites'][] = $otherSuite;
$withOtherSuite['candidate_suites_pages'][0]['response']['total_count'] = 2;
$assertProof($withOtherSuite, 'workflow-authority-not-independently-attested',
    'unrelated queued Pages suite does not override candidate qualification');
$bad = $withOtherSuite;
$bad['candidate_suites_pages'][0]['response']['check_suites'][1] = array_replace(
    $proofSuites[0], ['id' => 3302]
);
$bad['checks_pages'][0]['response']['check_runs'][1]['check_suite']['id'] = 3302;
$assertProof($bad, 'invalid-or-conflicting-candidate-check', 'required checks disagree on valid candidate suite');

$manySuites = $proofSuites;
for ($extra = 0; $extra < 100; $extra++) {
    $manySuites[] = ['id' => 6000 + $extra, 'app' => ['id' => 34598]];
}
$pagedSuites = $proof;
$pagedSuites['candidate_suites_pages'] = [
    ['page' => 1, 'per_page' => 100, 'response' => [
        'total_count' => 101, 'check_suites' => array_slice($manySuites, 0, 100),
    ]],
    ['page' => 2, 'per_page' => 100, 'response' => [
        'total_count' => 101, 'check_suites' => array_slice($manySuites, 100),
    ]],
];
$assertProof($pagedSuites, 'workflow-authority-not-independently-attested',
    'two complete candidate-suite API pages');
array_pop($pagedSuites['candidate_suites_pages']);
$assertProof($pagedSuites, 'incomplete-or-conflicting-api-pages', 'candidate-suite page two missing');

$invalidHistory = $proofHistory;
$invalidHistory['generated_paths'][] = '.github/workflows/candidate-preparation.yml';
$rejected = $correlate($proof, $invalidHistory);
if ($rejected['scope'] !== 'full' || $rejected['reason'] !== 'invalid-historical-candidate') {
    throw new RuntimeException('A generated-looking workflow modification was accepted.');
}
$proofCases++;

$git = resolve_executable('PHP_GALLERY_GIT', ['git']);
if ($git === null) {
    if (getenv('GITHUB_ACTIONS') === 'true') {
        throw new RuntimeException('Mandatory hosted Git fixture cannot run: executable missing.');
    }
    echo 'SKIP Git history fixture: Git is unavailable; ' . count($parserCases) . " parser assertions PASS\n";
    exit(0);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gallery-impact-' . bin2hex(random_bytes(8));
if (!mkdir($root, 0700)) {
    throw new RuntimeException('Failed to allocate private Git fixture directory.');
}
$casesRun = 0;
$call = static function (array $args) use ($root, $git): string {
    $out = run_process(array_merge([$git], $args), $root, 15);
    if (($out['exit_code'] ?? -1) !== 0 || !empty($out['timed_out']) || !empty($out['blocked'])) {
        throw new RuntimeException('Owned fixture Git command failed: ' . implode(' ', $args)
            . ' [exit ' . ($out['exit_code'] ?? -1) . ']');
    }
    return trim($out['stdout']);
};
$check = static function (array $result, string $scope, string $reason, string $label) use (&$casesRun): void {
    if ($result['scope'] !== $scope || $result['reason'] !== $reason) {
        throw new RuntimeException('Change evidence ' . $label . ': expected ' . $scope . '/' . $reason
            . ', got ' . $result['scope'] . '/' . $result['reason']);
    }
    $casesRun++;
};

try {
    $call(['init', '-q']);
    $call(['symbolic-ref', 'HEAD', 'refs/heads/develop']);
    $call(['config', 'user.name', 'Fixture Author']);
    $call(['config', 'user.email', 'fixture@example.test']);
    $call(['config', 'commit.gpgsign', 'false']);
    $call(['config', 'core.autocrlf', 'false']);
    mkdir($root . '/docs', 0700);
    mkdir($root . '/app', 0700);
    file_put_contents($root . '/docs/PHP_Gallery_Manual.tex', "baseline EN\n");
    file_put_contents($root . '/docs/PHP_Gallery_Manual_CZ.tex', "baseline CZ\n");
    file_put_contents($root . '/app/core-manifest.json', "{}\n");
    $call(['add', '--', 'docs', 'app/core-manifest.json']);
    $call(['commit', '-q', '-m', 'fixture: develop base']);
    $base = $call(['rev-parse', 'HEAD']);
    $call(['update-ref', 'refs/remotes/origin/develop', $base]);
    $call(['update-ref', 'refs/remotes/origin/main', $base]);
    $call(['checkout', '-q', '-b', 'feature/docs']);
    file_put_contents($root . '/docs/PHP_Gallery_Manual.tex', "changed EN\n");
    file_put_contents($root . '/docs/PHP_Gallery_Manual_CZ.tex', "changed CZ\n");
    $call(['add', '--', 'docs']);
    $call(['commit', '-q', '-m', 'docs: translate maintained manuals']);
    $source = $call(['rev-parse', 'HEAD']);
    $plain = audit_source_change_impact($root, $git, $source, $source);
    $check($plain, 'manual-source', 'maintained-manual-modifications-only', 'direct authored SHA');
    if ($plain['comparison_base'] !== $base || $plain['develop_sha'] !== $base
        || count($plain['changes']) !== 2 || $plain['generated_paths'] !== []) {
        throw new RuntimeException('Direct authored-source provenance does not match fixture base and paths.');
    }
    $casesRun++;

    file_put_contents($root . '/app/core-manifest.json', "{\"generated\":true}\n");
    $call(['add', '--', 'app/core-manifest.json']);
    $call(['-c', 'user.name=github-actions[bot]',
        '-c', 'user.email=41898282+github-actions[bot]@users.noreply.github.com',
        'commit', '-q', '-m', 'chore(candidate): refresh reviewed generated artifacts']);
    $candidate = $call(['rev-parse', 'HEAD']);
    $prepared = audit_source_change_impact($root, $git, $source, $candidate);
    $check($prepared, 'manual-source', 'maintained-manual-modifications-only', 'prepared candidate');
    if ($prepared['source_sha'] !== $source || $prepared['candidate_sha'] !== $candidate
        || $prepared['comparison_base'] !== $base
        || $prepared['generated_paths'] !== ['app/core-manifest.json']) {
        throw new RuntimeException('Prepared candidate must preserve source provenance and generated output isolation.');
    }
    $casesRun++;


    // Phase 3a: compare exact Git tree/blob inputs without authorizing
    // cross-stage PASS reuse, skipping mandatory jobs, or trusting bot metadata.
    $identical = audit_cross_stage_tree_delta($root, $git, $candidate, $candidate);
    if ($identical['evidence'] !== 'RECORDED' || $identical['reason'] !== 'tree-identical'
        || $identical['scope'] !== 'full' || $identical['history_sensitive'] !== false
        || $identical['changes'] !== [] || $identical['tree_equal'] !== true
        || $identical['original_tree'] !== $identical['target_tree']) {
        throw new RuntimeException('Same candidate tree evidence incorrectly authorized or rejected reuse.');
    }
    $casesRun++;

    $releaseDelta = audit_cross_stage_tree_delta($root, $git, $source, $candidate);
    if ($releaseDelta['evidence'] !== 'RECORDED' || $releaseDelta['scope'] !== 'full'
        || $releaseDelta['reason'] !== 'tree-delta-recorded'
        || $releaseDelta['tree_equal'] !== false || $releaseDelta['history_sensitive'] !== true
        || count($releaseDelta['changes']) !== 1
        || $releaseDelta['changes'][0]['path'] !== 'app/core-manifest.json'
        || $releaseDelta['changes'][0]['status'] !== 'M'
        || $releaseDelta['changes'][0]['before_blob'] === $releaseDelta['changes'][0]['after_blob']) {
        throw new RuntimeException('Changed generated manifest must remain a content-bound full-scope delta.');
    }
    $casesRun++;

    $blockedCases = [
        ['unavailable binary', null, $source, $candidate, 'git-unavailable'],
        ['malformed source SHA', $git, 'not-a-sha', $candidate, 'invalid-sha'],
        ['missing original commit', $git, str_repeat('f', 40), $candidate, 'missing-commit'],
        ['stale checked-out target', $git, $source, $source, 'target-head-mismatch'],
    ];
    foreach ($blockedCases as [$label, $gitPath, $originalSha, $targetSha, $reason]) {
        $blocked = audit_cross_stage_tree_delta($root, $gitPath, $originalSha, $targetSha);
        if ($blocked['evidence'] !== 'BLOCKED' || $blocked['scope'] !== 'full'
            || $blocked['reason'] !== $reason) {
            throw new RuntimeException('Cross-stage unsafe evidence accepted: ' . $label);
        }
        $casesRun++;
    }
    file_put_contents($root . '/untracked-delta.txt', "must reject dirty state\n");
    $dirtyDelta = audit_cross_stage_tree_delta($root, $git, $source, $candidate);
    if ($dirtyDelta['evidence'] !== 'BLOCKED'
        || $dirtyDelta['reason'] !== 'dirty-or-unreadable-checkout') {
        throw new RuntimeException('Dirty checkout falsely advertised a reusable clean tree.');
    }
    unlink($root . '/untracked-delta.txt');
    $casesRun++;


    // A subsequent docs-only authored commit still includes the earlier
    // candidate-generated diff in the branch history. Each generated revision
    // requires an authenticated exact-SHA hosted qualification, not just
    // matching bot metadata. Until then, full is the safe outcome.
    file_put_contents($root . '/docs/PHP_Gallery_Manual.tex', "second authored change\n");
    $call(['add', '--', 'docs/PHP_Gallery_Manual.tex']);
    $call(['commit', '-q', '-m', 'docs: later small update']);
    $laterSource = $call(['rev-parse', 'HEAD']);
    $history = audit_source_change_impact($root, $git, $laterSource, $laterSource);
    $check($history, 'full', 'unverified-historical-candidate',
        'previous generator commits require exact hosted proof');
    if (count($history['historical_candidates']) !== 1
        || $history['historical_candidates'][0] !== [
            'source_sha' => $source,
            'candidate_sha' => $candidate,
            'generated_paths' => ['app/core-manifest.json'],
            'provenance' => 'requires-hosted-proof',
        ] || count($history['authored_changes']) !== 3
        || count($history['changes']) !== 3) {
        throw new RuntimeException('Historical candidate inventory failed to separate authored versus generated paths.');
    }
    $casesRun++;

    // A second generated candidate between authored manual edits must be
    // enumerated separately, without silently accepting its spoofable Git ID.
    file_put_contents($root . '/app/core-manifest.json', "{\"generation\":2}\n");
    $call(['add', '--', 'app/core-manifest.json']);
    $call(['-c', 'user.name=github-actions[bot]',
        '-c', 'user.email=41898282+github-actions[bot]@users.noreply.github.com',
        'commit', '-q', '-m', 'chore(candidate): refresh reviewed generated artifacts']);
    $secondCandidate = $call(['rev-parse', 'HEAD']);
    file_put_contents($root . '/docs/PHP_Gallery_Manual_CZ.tex', "third authored change\n");
    $call(['add', '--', 'docs/PHP_Gallery_Manual_CZ.tex']);
    $call(['commit', '-q', '-m', 'docs: after second preparation']);
    $thirdSource = $call(['rev-parse', 'HEAD']);
    $twice = audit_source_change_impact($root, $git, $thirdSource, $thirdSource);
    $check($twice, 'full', 'unverified-historical-candidate',
        'two historical candidates without hosted proof');
    if (array_column($twice['historical_candidates'], 'candidate_sha') !== [$candidate, $secondCandidate]
        || array_column($twice['historical_candidates'], 'source_sha') !== [$source, $laterSource]
        || count($twice['authored_changes']) !== 4) {
        throw new RuntimeException('Multi-candidate ancestry is missing a generator boundary.');
    }
    $casesRun++;

    // Even a perfect lookalike candidate cannot be trusted on Git metadata.
    $call(['reset', '--hard', $candidate]);

    // Simulate an incomplete object history inside this owned disposable repo.
    // A readable but shallow repository must not invent a trustworthy merge-base.
    file_put_contents($root . '/.git/shallow', $base . "\n");
    $check(audit_source_change_impact($root, $git, $source, $candidate),
        'full', 'shallow-or-unreadable-history', 'shallow repository refused');
    unlink($root . '/.git/shallow');

    $check(audit_source_change_impact($root, null, $source, $candidate),
        'full', 'git-unavailable', 'unavailable Git');
    $check(audit_source_change_impact($root, $git, 'invalid', $candidate),
        'full', 'invalid-sha', 'invalid source SHA');
    $check(audit_source_change_impact($root, $git, $source, $source),
        'full', 'candidate-head-mismatch', 'stale candidate identity');
    $check(audit_source_change_impact($root, $git, $base, $candidate),
        'full', 'unexpected-candidate-parent', 'incorrect authored parent');
    file_put_contents($root . '/docs/PHP_Gallery_Manual.tex', "dirty checkout\n");
    $check(audit_source_change_impact($root, $git, $source, $candidate),
        'full', 'dirty-or-unreadable-checkout', 'dirty tracked checkout');
    $call(['restore', '--', 'docs/PHP_Gallery_Manual.tex']);
    $call(['update-ref', '-d', 'refs/remotes/origin/develop']);
    $check(audit_source_change_impact($root, $git, $source, $candidate),
        'full', 'missing-develop-ref', 'missing develop baseline');
    $call(['update-ref', 'refs/remotes/origin/develop', $base]);

    // An unrelated new commit on develop must not broaden the authored diff
    // to everything introduced on develop after the feature fork point.
    $call(['checkout', '-q', 'develop']);
    file_put_contents($root . '/app/unrelated.php', "<?php // new develop-only source\n");
    $call(['add', '--', 'app/unrelated.php']);
    $call(['commit', '-q', '-m', 'feat: unrelated develop work']);
    $advancedDevelop = $call(['rev-parse', 'HEAD']);
    $call(['update-ref', 'refs/remotes/origin/develop', $advancedDevelop]);
    $call(['checkout', '-q', 'feature/docs']);
    $afterDevelop = audit_source_change_impact($root, $git, $source, $candidate);
    $check($afterDevelop, 'manual-source', 'maintained-manual-modifications-only',
        'unrelated develop advanced');
    if ($afterDevelop['develop_sha'] !== $advancedDevelop || $afterDevelop['comparison_base'] !== $base) {
        throw new RuntimeException('Authored comparison accidentally included unrelated develop commits.');
    }
    $casesRun++;

    // A manufactured candidate identity or unexpected generated path must
    // block narrow classification even if the underlying TeX change is valid.
    $call(['checkout', '-q', '--detach', $source]);
    file_put_contents($root . '/app/core-manifest.json', "{\"not-bot\":true}\n");
    $call(['add', '--', 'app/core-manifest.json']);
    $call(['commit', '-q', '-m', 'not an authorized generator']);
    $notBot = $call(['rev-parse', 'HEAD']);
    $check(audit_source_change_impact($root, $git, $source, $notBot),
        'full', 'unexpected-generator-identity', 'untrusted generator commit');

    // Matching only the Git author is insufficient: the recorded committer
    // must also agree with the exact reviewed candidate generator identity.
    $call(['checkout', '-q', '--detach', $source]);
    file_put_contents($root . '/app/core-manifest.json', "{\"wrong-committer\":true}\n");
    $call(['add', '--', 'app/core-manifest.json']);
    $savedActor = [];
    foreach (['GIT_AUTHOR_NAME', 'GIT_AUTHOR_EMAIL', 'GIT_COMMITTER_NAME', 'GIT_COMMITTER_EMAIL'] as $field) {
        $savedActor[$field] = getenv($field);
    }
    try {
        putenv('GIT_AUTHOR_NAME=github-actions[bot]');
        putenv('GIT_AUTHOR_EMAIL=41898282+github-actions[bot]@users.noreply.github.com');
        putenv('GIT_COMMITTER_NAME=Fixture Committer');
        putenv('GIT_COMMITTER_EMAIL=fixture-committer@example.test');
        $call(['commit', '-q', '-m', 'chore(candidate): refresh reviewed generated artifacts']);
    } finally {
        foreach ($savedActor as $field => $value) {
            putenv($value === false ? $field : $field . '=' . $value);
        }
    }
    $spoofedCommitter = $call(['rev-parse', 'HEAD']);
    $check(audit_source_change_impact($root, $git, $source, $spoofedCommitter),
        'full', 'unexpected-generator-identity', 'bot author with untrusted committer');

    $call(['checkout', '-q', '--detach', $source]);
    file_put_contents($root . '/docs/RELEASE_LIFECYCLE.md', "bad generated extra\n");
    $call(['add', '--', 'docs/RELEASE_LIFECYCLE.md']);
    $call(['-c', 'user.name=github-actions[bot]',
        '-c', 'user.email=41898282+github-actions[bot]@users.noreply.github.com',
        'commit', '-q', '-m', 'chore(candidate): refresh reviewed generated artifacts']);
    $unexpected = $call(['rev-parse', 'HEAD']);
    $check(audit_source_change_impact($root, $git, $source, $unexpected),
        'full', 'unexpected-generated-change', 'unauthorized generated path');

    $call(['checkout', '-q', '--detach', $base]);
    $call(['mv', 'docs/PHP_Gallery_Manual.tex', 'docs/PHP_Gallery_Manual_OLD.tex']);
    $call(['commit', '-q', '-m', 'rename maintained manual']);
    $renamed = $call(['rev-parse', 'HEAD']);
    $check(audit_source_change_impact($root, $git, $renamed, $renamed),
        'full', 'non-modification-status', 'rename cannot be treated as prose change');

    // A mixed source change cannot be narrowed, regardless of the historical
    // count or whether an older clean candidate was qualified.
    $call(['checkout', '-q', '--detach', $source]);
    file_put_contents($root . '/app/runtime-policy.php', "<?php // changed runtime\n");
    $call(['add', '--', 'app/runtime-policy.php']);
    $call(['commit', '-q', '-m', 'mix in runtime logic']);
    $mixed = $call(['rev-parse', 'HEAD']);
    $check(audit_source_change_impact($root, $git, $mixed, $mixed),
        'full', 'non-modification-status', 'new runtime source');
} finally {
    // Delete only files allocated inside this private fixture root, without
    // following symlinks to any other checkout or source tree.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($root);
}

echo 'PASS audit Git change evidence: ' . count($parserCases)
    . ' parser fixtures, ' . $proofCases . ' hosted-proof fixtures and ' . $casesRun . " isolated Git assertions\n";
