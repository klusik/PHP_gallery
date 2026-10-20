<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/audit_runner_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the central audit runner's deterministic registry and normalized helper contracts.
 *
 * Responsibilities:
 *   - Keep every standalone Node regression script explicitly registered
 *   - Preserve quick/full/release profile composition
 *   - Exercise bounded child scheduling, exclusive barriers and outcome normalization
 *   - Keep the curated PHP feedback and isolated browser fixture registries consistent
 *   - Verify command-line parsing and SKIP classification helpers
 *   - Prevent accidental browser or slow-test inclusion in the quick profile
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This test intentionally validates registry coverage without executing the child suites recursively.
 *
 * Last Updated:
 *   2026-10-12
 */

declare(strict_types=1);

require_once __DIR__ . '/support/php_source.php';
require_once __DIR__ . '/support/gallery_edit_writer_source.php';

require_once dirname(__DIR__) . '/scripts/audit_lib.php';
require_once dirname(__DIR__) . '/scripts/audit_winapp.php';
$registry = require dirname(__DIR__) . '/scripts/audit_registry.php';

use function PhpGallery\Audit\output_is_skip;
use function PhpGallery\Audit\parse_python_unittest_summary;
use function PhpGallery\Audit\audit_validate_winapp_report;
use function PhpGallery\Audit\audit_evaluate_winapp_report;
use function PhpGallery\Audit\parse_options;
use function PhpGallery\Audit\performance_metric_problems;
use function PhpGallery\Audit\python_command_is_usable;
use function PhpGallery\Audit\relative_path;
use function PhpGallery\Audit\resolve_python_command_from_candidates;
use function PhpGallery\Audit\resolve_browser_executable;
use function PhpGallery\Audit\browser_required;
use function PhpGallery\Audit\process_status;
use function PhpGallery\Audit\run_process_pool;
use function PhpGallery\Audit\run_file_checks;
use function PhpGallery\Audit\worker_count;
use function PhpGallery\Audit\node_failure_detail;
use function PhpGallery\Audit\missing_required_php_test_passes;
use function PhpGallery\Audit\audit_hosted_full_suite_ownership;
use function PhpGallery\Audit\audit_hosted_parallel_owner;
use function PhpGallery\Audit\audit_partition_php_tests;
use function PhpGallery\Audit\audit_required_suite_pass;
use function PhpGallery\Audit\audit_delegated_checkout_integrity;
use function PhpGallery\Audit\render_console_problems;
use function PhpGallery\Audit\render_markdown_report;

/**
 * Throw when an audit-runner contract is not satisfied.
 *
 * @param bool $condition Assertion condition.
 * @param string $message Failure message.
 * @return void
 */
function audit_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$actualNodeFiles = glob(__DIR__ . '/*_test.mjs') ?: [];
$actualNodeNames = array_map('basename', $actualNodeFiles);
sort($actualNodeNames, SORT_STRING);
$applicationNodeNames = array_keys($registry['node_tests'] ?? []);
$websiteNodeNames = array_keys($registry['website_tests'] ?? []);
$registeredNodeNames = array_merge($applicationNodeNames, $websiteNodeNames);
sort($registeredNodeNames, SORT_STRING);
audit_test_assert(
    $actualNodeNames === $registeredNodeNames,
    'Every tests/*_test.mjs file must have exactly one explicit audit registry entry.',
);
audit_test_assert(
    count($registeredNodeNames) === count(array_unique($registeredNodeNames)),
    'A Node test must have exactly one owner across application and website suites.',
);
audit_test_assert(
    $websiteNodeNames !== []
        && in_array('project_website_test.mjs', $websiteNodeNames, true)
        && !in_array('project_website_test.mjs', $applicationNodeNames, true),
    'Website artifact fixtures must be registered centrally without entering CMS regressions.',
);
foreach ($registry['website_tests'] as $websiteDefinition) {
    audit_test_assert(
        ($websiteDefinition['browser'] ?? false) === true,
        'The isolated website suite must require actual Chromium coverage.',
    );
}

$profiles = $registry['profiles'] ?? [];
$runnerSource = str_replace(
    "\r\n",
    "\n",
    (string) file_get_contents(dirname(__DIR__) . '/scripts/audit.php'),
);
$auditLibrarySource = str_replace(
    "\r\n",
    "\n",
    (string) file_get_contents(dirname(__DIR__) . '/scripts/audit_lib.php'),
);
$legacyCandidatePreflight = [
    'php-lint',
    'js-lint',
    'source-documentation-changed',
    'source-policy-changed',
    'source-contract-inventory',
    'python-import-policy',
    'mvc-boundaries',
    'mutation-contracts',
    'ci-workflow-contract',
    'manifest',
];
$parallelCandidatePreflight = ['ci-workflow-contract', 'manifest'];
$parallelSourceProfile = [
    'php-lint',
    'js-lint',
    'source-documentation-changed',
    'source-policy-changed',
    'source-contract-inventory',
    'python-import-policy',
    'mvc-boundaries',
    'mutation-contracts',
    'ci-workflow-contract',
    'version-audit',
];
$parallelHostedFullProfile = [
    'php-regression',
    'runtime-performance',
    'php-lint',
];
$hasParallelProfileKeys =
    array_key_exists('source-contracts', $profiles)
    || array_key_exists('portable', $profiles)
    || array_key_exists('hosted-full', $profiles)
    || array_key_exists('hosted_full_php_shard_count', $registry);
$parallelImplementationPresent =
    function_exists('PhpGallery\\Audit\\audit_hosted_parallel_owner')
    && function_exists('PhpGallery\\Audit\\audit_partition_php_tests')
    && php_source_contains(
        $runnerSource,
        "\$profileLabel === 'hosted-full' ? \$phpShard : null",
    )
    && php_source_contains($auditLibrarySource, 'function audit_partition_php_tests(');
$parallelProfileFingerprint =
    ($profiles['candidate-preflight'] ?? null) === $parallelCandidatePreflight
    && ($profiles['source-contracts'] ?? null) === $parallelSourceProfile
    && ($profiles['portable'] ?? null) === ['node-full', 'winapp']
    && ($profiles['hosted-full'] ?? null) === $parallelHostedFullProfile
    && ($registry['hosted_full_php_shard_count'] ?? null) === 4;
$legacyProfileFingerprint =
    ($profiles['candidate-preflight'] ?? null) === $legacyCandidatePreflight
    && !$hasParallelProfileKeys;
$legacyImplementationPresent =
    !function_exists('PhpGallery\\Audit\\audit_hosted_parallel_owner')
    && !function_exists('PhpGallery\\Audit\\audit_partition_php_tests')
    && !php_source_contains($runnerSource, 'hosted-full')
    && !php_source_contains(
        $auditLibrarySource,
        'function audit_partition_php_tests(',
    );
$isParallelAuditContract =
    $parallelProfileFingerprint && $parallelImplementationPresent;
$isLegacyAuditContract =
    $legacyProfileFingerprint && $legacyImplementationPresent;
audit_test_assert(
    $isParallelAuditContract !== $isLegacyAuditContract,
    'Audit runner fixture must recognize exactly the complete installed legacy contract or the complete parallel contract; partial or mixed ownership is unsupported.',
);
audit_test_assert(
    ($profiles['basic'] ?? null) === [
        'php-lint',
        'js-lint',
        'source-documentation-changed',
    ],
    'Basic must run only PHP/JavaScript syntax and changed named-declaration documentation.',
);
audit_test_assert(
    count(array_intersect($profiles['basic'], [
        'php-regression',
        'node-fast',
        'node-full',
        'winapp',
        'browser-map',
        'manifest',
        'source-contract-inventory',
        'source-policy-changed',
        'python-import-policy',
    ])) === 0,
    'Basic must not run regression, runtime, inventory, policy, browser, WinApp, or manifest suites.',
);
audit_test_assert(
    in_array('php-regression', $profiles['full'], true)
        && in_array('browser-map', $profiles['full'], true)
        && in_array('php-regression', $profiles['release'], true)
        && in_array('browser-map', $profiles['release'], true),
    'Adding Basic must preserve full and release functional coverage.',
);
audit_test_assert(
    ($profiles['release-preflight'] ?? []) === [
        'php-lint',
        'js-lint',
        'source-documentation-changed',
        'source-policy-changed',
        'source-contract-inventory',
        'python-import-policy',
        'ci-workflow-contract',
    ],
    'Release preflight must own the complete cheap static gate centrally, without expensive artifact-dependent suites.',
);
audit_test_assert(
    ($profiles['release-stage-b'] ?? null) === [
        'mvc-boundaries',
        'mutation-contracts',
    ],
    'Release Stage B must select only pre-generation-safe medium-cost suites from the central registry.',
);
audit_test_assert(
    count(array_intersect($profiles['release-stage-b'], [
        'manifest',
        'release-consistency',
        'version-audit',
        'php-regression',
        'node-full',
        'browser-map',
    ])) === 0,
    'Stage B must not depend on generated release artifacts or replace final qualification.',
);
audit_test_assert(
    isset($profiles['quick'], $profiles['full'], $profiles['release']),
    'Audit registry must retain quick, full, and release profiles.',
);
if ($isParallelAuditContract) {
    audit_test_assert(
        ($profiles['candidate-preflight'] ?? null)
            === $parallelCandidatePreflight
            && ($profiles['source-contracts'] ?? null)
                === $parallelSourceProfile
            && ($profiles['portable'] ?? null) === ['node-full', 'winapp']
            && ($profiles['hosted-full'] ?? null) === $parallelHostedFullProfile
            && ($registry['hosted_full_php_shard_count'] ?? null) === 4,
        'Parallel candidate-preflight, source, portable and PHP shard profiles must match their exact contract.',
    );
    audit_test_assert(
        php_source_contains(
            $runnerSource,
            "\$phpShard['index'] !== 1 || PHP_VERSION_ID >= 80500",
        ),
        'PHP 8.1 syntax coverage must remain on shard one while the shared source owner lints on PHP 8.5.',
    );
} else {
    audit_test_assert(
        ($profiles['candidate-preflight'] ?? null) === $legacyCandidatePreflight
            && !$hasParallelProfileKeys,
        'Installed BASIC must retain the complete original candidate-preflight source and manifest gate.',
    );
}
audit_test_assert(
    in_array('manifest', $profiles['full'], true),
    'Authoritative full handoff must refuse stale runtime plans, production inventory and manifest.',
);
audit_test_assert(
    php_source_contains(
        (string) file_get_contents(dirname(__DIR__) . '/scripts/audit.php'),
        "'scripts/prepare_candidate.php', ['--check']",
    ),
    'Central manifest suite must use the common read-only candidate preparation contract.',
);
// Source spelling contracts use canonical newlines across Windows and hosted checkouts.
audit_test_assert(
    php_source_contains(
        $runnerSource,
        "'website' => audit_run_node_suite('website', 'Static project website', \$registry['website_tests'], \$node, \$browser)",
    ),
    'The central website suite must execute only its explicitly owned registry.',
);
foreach (['basic', 'quick', 'full', 'release'] as $applicationProfile) {
    audit_test_assert(
        !in_array('website', $profiles[$applicationProfile], true),
        'CMS source and regression profiles must not duplicate website artifact qualification.',
    );
}
$supportSource = str_replace(
    "\r\n",
    "\n",
    (string) file_get_contents(dirname(__DIR__) . '/scripts/audit_lib.php'),
);
audit_test_assert(
    php_source_contains(
        $runnerSource,
        "'php-lint-changed' => audit_run_php_lint(true, \$basicChangedLintBase, \$basicChangedLintRequiresBase)",
    )
        && php_source_contains(
            $runnerSource,
            "'js-lint-changed' => audit_run_js_lint(true, \$node, \$basicChangedLintBase, \$basicChangedLintRequiresBase)",
        )
        && php_source_contains(
            $runnerSource,
            "\$python = \$isMinimalProfile ? null : resolve_python_command()",
        )
        && php_source_contains(
            $runnerSource,
            "\$browser = \$isMinimalProfile ? null : resolve_browser_executable()",
        )
        && php_source_contains(
            $runnerSource,
            "\$environment = \$isMinimalProfile\n    ? [",
        )
        && php_source_contains($runnerSource, "'component' => 'PHP GD'")
        && php_source_contains(
            $runnerSource,
            "if (\$suiteId === 'source-documentation-changed' && \$basicChangedLintRequiresBase && \$basicChangedLintBase === null)",
        )
        && php_source_contains(
            $supportSource,
            "'--no-renames', \$baseSha, 'HEAD', '--'",
        )
        && php_source_contains(
            $supportSource,
            "'merge-base', '--is-ancestor', \$baseSha, 'HEAD'",
        )
        && php_source_contains(
            $supportSource,
            "'reason' => 'immutable-baseline-unavailable'",
        ),
    'Basic --changed syntax checks must cover the immutable comparison-base delta and block if its Git evidence is unavailable.',
);

if ($isParallelAuditContract) {
    audit_test_assert(
        php_source_contains(
            $runnerSource,
            "!in_array(\$profileLabel, ['full', 'release', 'source-contracts', 'portable', 'hosted-full'], true)",
        ),
        'Parallel profiles must preserve full-tree lint selection instead of converting it to changed-only checks.',
    );
    audit_test_assert(
        php_source_contains(
            $runnerSource,
            "putenv('PHP_GALLERY_SOURCE_BASE=' . \$sourceIdentity['comparison_base'])",
        ),
        'Independent contract children must consume the exact resolved report base, including manual HEAD-parent requests.',
    );
}

$hostedDatabase = [
    'GITHUB_ACTIONS' => 'true',
    'GITHUB_REPOSITORY' => 'klusik/PHP_gallery',
    'GALLERY_WORKFLOW_ENABLE' => 'disposable-only',
    'GALLERY_WORKFLOW_REQUIRED' => '1',
    'GALLERY_WORKFLOW_DB_HOST' => '127.0.0.1',
    'GALLERY_WORKFLOW_DB_PORT' => '13316',
    'GALLERY_WORKFLOW_DB_USER' => 'gallery_workflow_runner',
    'GALLERY_RUNTIME_NORMALIZATION' => 'unicode',
    'GALLERY_SESSION_REQUIRED' => '1',
];
if ($isParallelAuditContract) {
    $parallelCandidate = str_repeat('a', 40);
    $parallelBase = str_repeat('b', 40);
    $parallelBaseEnvironment = [
        'GITHUB_ACTIONS' => 'true',
        'GITHUB_REPOSITORY' => 'klusik/PHP_gallery',
        'PHP_GALLERY_AUDIT_CANDIDATE' => $parallelCandidate,
    ];
    $sourceOwner = audit_hosted_parallel_owner(
        array_replace($parallelBaseEnvironment, [
            'PHP_GALLERY_SOURCE_OWNER' => 'shared-source-contracts',
        ]),
        'source-contracts',
        $parallelCandidate,
        $parallelBase,
        4,
    );
    $portableOwner = audit_hosted_parallel_owner(
        array_replace($parallelBaseEnvironment, [
            'PHP_GALLERY_PORTABLE_OWNER' => 'portable-node-winapp',
        ]),
        'portable',
        $parallelCandidate,
        $parallelBase,
        4,
    );
    audit_test_assert(
        $sourceOwner['valid']
            && $sourceOwner['owner'] === 'shared-source-contracts'
            && $portableOwner['valid']
            && $portableOwner['owner'] === 'portable-node-winapp',
        'Shared source and portable profiles must require their explicit trusted exact-candidate owner bindings.',
    );
    $shardEnvironment = array_replace($parallelBaseEnvironment, [
        'PHP_GALLERY_AUDIT_OWNER' => 'hosted-full',
        'PHP_GALLERY_SOURCE_OWNER' => 'shared-source-contracts',
        'PHP_GALLERY_PORTABLE_OWNER' => 'portable-node-winapp',
        'PHP_GALLERY_AUDIT_SHARD_INDEX' => '2',
        'PHP_GALLERY_AUDIT_SHARD_TOTAL' => '4',
    ]);
    $validShard = audit_hosted_parallel_owner(
        $shardEnvironment,
        'hosted-full',
        $parallelCandidate,
        $parallelBase,
        4,
    );
    audit_test_assert(
        $validShard['valid']
            && $validShard['index'] === 2
            && $validShard['total'] === 4,
        'Hosted PHP shard coordinates must be one-based and bound to the exact checked-out candidate.',
    );
    $sameBaseOwner = audit_hosted_parallel_owner(
        $shardEnvironment,
        'hosted-full',
        $parallelCandidate,
        $parallelCandidate,
        4,
    );
    audit_test_assert(
        !$sameBaseOwner['valid'],
        'Hosted split owners must reject a missing-delta base equal to the candidate HEAD.',
    );
    $ownerMarkdown = render_markdown_report([
        'status' => 'PASS',
        'profile' => 'hosted-full',
        'started_at' => '2026-10-15T00:00:00+00:00',
        'duration_seconds' => 1.0,
        'source' => [
            'head_sha' => $parallelCandidate,
            'comparison_base' => $parallelBase,
            'worktree_dirty' => false,
        ],
        'parallel_owner' => [
            'owner' => 'hosted-full',
            'candidate' => $parallelCandidate,
            'comparison_base' => $parallelBase,
            'shard_index' => 2,
            'shard_total' => 4,
        ],
        'delegated_to_parallel_owners' => [[
            'owner' => 'portable-node-winapp',
            'profile' => 'portable',
            'candidate' => $parallelCandidate,
            'comparison_base' => $parallelBase,
            'suites' => ['node-full', 'winapp'],
        ]],
        'environment' => [],
        'tasks' => [],
        'slowest' => [],
        'report_files' => [
            'json' => 'cache/test-audit/report.json',
            'run_directory' => 'cache/test-audit/run',
        ],
    ]);
    audit_test_assert(
        str_contains(
            $ownerMarkdown,
            '- Owner candidate: `' . $parallelCandidate . '`',
        )
            && str_contains(
                $ownerMarkdown,
                '- Owner comparison base: `' . $parallelBase . '`',
            )
            && str_contains($ownerMarkdown, '- PHP shard: `2/4`')
            && str_contains(
                $ownerMarkdown,
                'Expected separate owner: `portable-node-winapp`',
            )
            && str_contains($ownerMarkdown, 'suites `node-full, winapp`'),
        'Split-owner handoff Markdown must expose exact candidate/base, shard identity, and delegated owner coverage.',
    );
    foreach ([
        ['PHP_GALLERY_AUDIT_CANDIDATE' => str_repeat('b', 40)],
        ['PHP_GALLERY_AUDIT_OWNER' => 'source-contracts'],
        ['PHP_GALLERY_SOURCE_OWNER' => 'unowned-source'],
        ['PHP_GALLERY_PORTABLE_OWNER' => 'unowned-portable'],
        ['PHP_GALLERY_AUDIT_SHARD_INDEX' => '0'],
        ['PHP_GALLERY_AUDIT_SHARD_INDEX' => '5'],
        ['PHP_GALLERY_AUDIT_SHARD_TOTAL' => '3'],
        ['GITHUB_ACTIONS' => 'false'],
    ] as $invalidShardOverride) {
        $invalidShard = audit_hosted_parallel_owner(
            array_replace($shardEnvironment, $invalidShardOverride),
            'hosted-full',
            $parallelCandidate,
            $parallelBase,
            4,
        );
        audit_test_assert(
            !$invalidShard['valid'],
            'Hosted PHP shard must reject a mismatched owner or candidate binding.',
        );
    }
    $shardNames = [
        'z_test.php',
        'a_test.php',
        'm_test.php',
        'b_test.php',
        'q_test.php',
        'c_test.php',
        'd_test.php',
    ];
    $shards = [];
    for ($shardIndex = 1; $shardIndex <= 4; $shardIndex++) {
        $shards[$shardIndex] = audit_partition_php_tests(
            $shardNames,
            $shardIndex,
            4,
        );
    }
    $flattenedShards = array_merge(...array_values($shards));
    $sortedShardNames = $shardNames;
    sort($sortedShardNames, SORT_STRING);
    $flattenedSorted = $flattenedShards;
    sort($flattenedSorted, SORT_STRING);
    audit_test_assert(
        $flattenedSorted === $sortedShardNames
            && count($flattenedShards) === count(array_unique(
                $flattenedShards,
            )),
        'Hosted PHP shards must form a deterministic complete disjoint partition of the registry-discovered test names.',
    );
    foreach ($shards as $shardIndex => $shardNamesForIndex) {
        audit_test_assert(
            $shardNamesForIndex === audit_partition_php_tests(
                $shardNames,
                $shardIndex,
                4,
            ),
            'Repeated hosted PHP partition selection must remain deterministic.',
        );
    }
    $badPartitionBlocked = false;
    try {
        audit_partition_php_tests($shardNames, 5, 4);
    } catch (InvalidArgumentException) {
        $badPartitionBlocked = true;
    }
    audit_test_assert(
        $badPartitionBlocked,
        'Hosted PHP partition selection must reject unknown shard coordinates.',
    );
}
$delegated = audit_hosted_full_suite_ownership(
    $profiles,
    'full',
    $hostedDatabase,
);
audit_test_assert(
    $delegated['delegated_to_preflight'] === ['manifest']
        && !in_array('manifest', $delegated['suites'], true)
        && count($delegated['suites']) === (count($profiles['full']) - 1)
        && in_array('php-regression', $delegated['suites'], true)
        && in_array('node-full', $delegated['suites'], true)
        && in_array('winapp', $delegated['suites'], true),
    'Only repeated manifest execution may move from a hosted disposable database job to required preflight.',
);
foreach (['quick', 'release', 'candidate-preflight'] as $retainedProfile) {
    $retained = audit_hosted_full_suite_ownership(
        $profiles,
        $retainedProfile,
        $hostedDatabase,
    );
    audit_test_assert(
        $retained['suites'] === $profiles[$retainedProfile]
            && $retained['delegated_to_preflight'] === [],
        'Mandatory non-full profiles must not delegate their manifest owner: '
            . $retainedProfile,
    );
}
$invalidContexts = [
    ['GITHUB_ACTIONS' => 'false'],
    ['GITHUB_REPOSITORY' => 'untrusted/fork'],
    ['GALLERY_WORKFLOW_ENABLE' => ''],
    ['GALLERY_WORKFLOW_REQUIRED' => '0'],
    ['GALLERY_WORKFLOW_DB_HOST' => 'localhost'],
    ['GALLERY_WORKFLOW_DB_PORT' => '3306'],
    ['GALLERY_WORKFLOW_DB_USER' => 'root'],
    ['GALLERY_RUNTIME_NORMALIZATION' => 'ascii'],
    ['GALLERY_SESSION_REQUIRED' => '0'],
];
foreach ($invalidContexts as $override) {
    $retained = audit_hosted_full_suite_ownership(
        $profiles,
        'full',
        array_replace($hostedDatabase, $override),
    );
    audit_test_assert(
        $retained['suites'] === $profiles['full']
            && $retained['delegated_to_preflight'] === [],
        'Unknown or invalid hosted environment must retain complete full manifest coverage.',
    );
}
$unownedProfiles = $profiles;
$unownedProfiles['candidate-preflight'] = array_values(array_diff($profiles['candidate-preflight'], [
    'manifest',
]));
audit_test_assert(
    audit_hosted_full_suite_ownership(
        $unownedProfiles,
        'full',
        $hostedDatabase,
    )['delegated_to_preflight'] === [],
    'A missing mandatory preflight manifest owner must prohibit delegation.',
);
$unownedProfiles = $profiles;
$unownedProfiles['release'] = array_values(array_diff($profiles['release'], [
    'manifest',
]));
audit_test_assert(
    audit_hosted_full_suite_ownership(
        $unownedProfiles,
        'full',
        $hostedDatabase,
    )['delegated_to_preflight'] === [],
    'Release manifest ownership must not disappear during hosted consolidation.',
);
$manifestPass = [['id' => 'manifest', 'status' => 'PASS']];
audit_test_assert(
    audit_required_suite_pass($manifestPass, 'manifest'),
    'One real central manifest PASS is the only accepted preflight owner evidence.',
);
foreach (['SKIP', 'BLOCKED', 'FAIL'] as $forbiddenStatus) {
    audit_test_assert(
        !audit_required_suite_pass([[
            'id' => 'manifest',
            'status' => $forbiddenStatus,
        ]], 'manifest'),
        'Required preflight manifest owner must reject '
            . $forbiddenStatus
            . '.',
    );
}
audit_test_assert(
    !audit_required_suite_pass([], 'manifest')
        && !audit_required_suite_pass([
            ['id' => 'manifest', 'status' => 'PASS'],
            ['id' => 'manifest', 'status' => 'PASS'],
        ], 'manifest')
        && !audit_required_suite_pass([[
            'id' => 'other',
            'status' => 'PASS',
        ]], 'manifest'),
    'Missing, duplicated or unrelated successes cannot replace required manifest PASS.',
);

$cleanIdentity = [
    'head_sha' => str_repeat('a', 40),
    'comparison_base' => str_repeat('b', 40),
    'requested_base' => str_repeat('b', 40),
    'worktree_dirty' => false,
];
$cleanIntegrity = audit_delegated_checkout_integrity(
    $cleanIdentity,
    $cleanIdentity,
);
audit_test_assert(
    $cleanIntegrity === [
        'status' => 'PASS',
        'reason' => 'unchanged-clean-checkout',
    ],
    'Post-test delegated manifest integrity must accept an unchanged clean exact checkout.',
);

$integrityFailures = [
    [
        'start' => ['worktree_dirty' => true],
        'end' => [],
        'status' => 'FAIL',
        'reason' => 'checkout-dirty-at-start',
    ],
    [
        'start' => [],
        'end' => ['worktree_dirty' => true],
        'status' => 'FAIL',
        'reason' => 'checkout-dirty-after-tests',
    ],
    [
        'start' => [],
        'end' => ['head_sha' => str_repeat('c', 40)],
        'status' => 'FAIL',
        'reason' => 'checkout-identity-changed-during-tests',
    ],
    [
        'start' => [],
        'end' => ['comparison_base' => str_repeat('c', 40)],
        'status' => 'FAIL',
        'reason' => 'checkout-identity-changed-during-tests',
    ],
    [
        'start' => [],
        'end' => ['requested_base' => str_repeat('c', 40)],
        'status' => 'FAIL',
        'reason' => 'checkout-identity-changed-during-tests',
    ],
    [
        'start' => ['head_sha' => null],
        'end' => [],
        'status' => 'BLOCKED',
        'reason' => 'git-identity-unavailable',
    ],
    [
        'start' => [],
        'end' => ['head_sha' => null],
        'status' => 'BLOCKED',
        'reason' => 'git-identity-unavailable',
    ],
    [
        'start' => [],
        'end' => ['head_sha' => 'not-a-sha'],
        'status' => 'BLOCKED',
        'reason' => 'git-identity-unavailable',
    ],
    [
        'start' => [],
        'end' => ['comparison_base' => null],
        'status' => 'BLOCKED',
        'reason' => 'git-identity-unavailable',
    ],
    [
        'start' => [],
        'end' => ['requested_base' => ''],
        'status' => 'BLOCKED',
        'reason' => 'git-identity-unavailable',
    ],
    [
        'start' => [],
        'end' => ['worktree_dirty' => null],
        'status' => 'BLOCKED',
        'reason' => 'git-identity-unavailable',
    ],
];
foreach ($integrityFailures as $case) {
    $result = audit_delegated_checkout_integrity(
        array_replace($cleanIdentity, $case['start']),
        array_replace($cleanIdentity, $case['end']),
    );
    audit_test_assert(
        $result === ['status' => $case['status'], 'reason' => $case['reason']],
        'Post-test delegated manifest integrity must reject invalid or mutated Git identity: '
            . $case['reason'],
    );
}
$centralRunner = (string) file_get_contents(
    dirname(__DIR__) . '/scripts/audit.php',
);
$sourcePatternOffset = static function (
    string $source,
    string $pattern,
): int|false {
    $matched = preg_match(
        $pattern,
        $source,
        $match,
        PREG_OFFSET_CAPTURE,
    );
    return $matched === 1 ? $match[0][1] : false;
};
$integrityInvocation = $sourcePatternOffset(
    $centralRunner,
    '/audit_delegated_checkout_integrity\s*\(\s*\$sourceIdentity\s*,\s*\$finalSourceIdentity\s*,?\s*\)/',
);
$overallInvocation = $sourcePatternOffset(
    $centralRunner,
    '/\$status\s*=\s*overall_status\s*\(\s*\$tasks\s*\)/',
);
audit_test_assert(
    $integrityInvocation !== false
        && $overallInvocation !== false
        && $integrityInvocation < $overallInvocation
        && php_source_contains($centralRunner, 'if ($delegatedToPreflight !== [])'),
    'Only delegated central audits may evaluate the mandatory post-test checkout guard before overall qualification.',
);

audit_test_assert(
    in_array('php-fast', $profiles['quick'], true)
        && !in_array('php-regression', $profiles['quick'], true),
    'Quick must use curated PHP feedback instead of the complete regression tree.',
);
foreach (['full', 'release'] as $profile) {
    audit_test_assert(
        in_array('php-regression', $profiles[$profile], true),
        'Full and release must retain complete PHP regression coverage.',
    );
    audit_test_assert(
        in_array('source-contract-inventory', $profiles[$profile], true),
        'Full and release must expose the complete source-contract inventory.',
    );
    audit_test_assert(
        in_array('winapp', $profiles[$profile], true),
        'Full and release must retain WinApp regression coverage.',
    );
}
audit_test_assert(
    !in_array('source-contract-inventory', $profiles['quick'], true)
        && !in_array('winapp', $profiles['quick'], true),
    'Quick must avoid advisory tree scans and unrelated WinApp discovery.',
);
audit_test_assert(
    in_array('node-fast', $profiles['quick'], true),
    'Quick profile must use the fast Node suite.',
);
audit_test_assert(
    in_array('node-full', $profiles['full'], true),
    'Full profile must include slow deterministic Node coverage.',
);
audit_test_assert(
    in_array('browser-map', $profiles['full'], true),
    'Full handoff must exercise available Chromium fixtures.',
);
audit_test_assert(
    !in_array('browser-map', $profiles['quick'], true),
    'Quick profile must not launch browsers.',
);
audit_test_assert(
    in_array('browser-map', $profiles['release'], true),
    'Release profile must include browser integration coverage.',
);
audit_test_assert(
    in_array('release-consistency', $profiles['release'], true),
    'Release profile must verify release metadata and documentation consistency.',
);
audit_test_assert(
    in_array('manifest', $profiles['release'], true),
    'Release profile must verify the core manifest.',
);
foreach (['quick', 'full', 'release'] as $profile) {
    audit_test_assert(
        in_array('runtime-performance', $profiles[$profile], true),
        'Every profile must enforce changed-source operational policy.',
    );
    audit_test_assert(
        in_array('python-import-policy', $profiles[$profile], true),
        'Every central profile must forbid future annotations across all admitted Python sources.',
    );
    audit_test_assert(
        in_array('source-documentation-changed', $profiles[$profile], true),
        'Every central profile must enforce added and materially changed declaration documentation.',
    );
    audit_test_assert(
        in_array('source-policy-changed', $profiles[$profile], true),
        'Every central profile must enforce recognized new or materially changed runtime policy sites.',
    );
}

$phpRegistry = require dirname(__DIR__) . '/scripts/audit_php_registry.php';
$quickTests = $phpRegistry['quick_tests'];
/**
 * Bound the explicit feedback subset without the complete module-loading matrix.
 * @var int Units: registered test cases. Scope: central quick registry contract.
 * Consumers: curated feedback cardinality assertion below.
 * Rationale: the reviewed subset has 48 cases, including shared HTTPS proxy/cookie
 * policy, the HTTP-level anonymous OFP regression and the description renderer. Complete compilation
 * and isolated comparison of every runtime module
 * remain covered by full/release rather than repeated during each edit cycle.
 */
const QUICK_REGISTRY_CASE_LIMIT = 48;

audit_test_assert(
    count($quickTests) >= 15
        && count($quickTests) <= QUICK_REGISTRY_CASE_LIMIT
        && count($quickTests) === count(array_unique($quickTests)),
    'Curated feedback must be a small explicit duplicate-free PHP list.',
);
audit_test_assert(
    !in_array('runtime_module_plan_test.php', $quickTests, true)
        && in_array('runtime_dependencies_test.php', $quickTests, true)
        && in_array('runtime_plan_ratchet_test.php', $quickTests, true),
    'Quick must retain dependency/graph contracts while reserving the complete clean-child module matrix for full/release.',
);
foreach ($quickTests as $testName) {
    audit_test_assert(
        basename($testName) === $testName && is_file(__DIR__ . '/' . $testName),
        'Every curated PHP entry must identify an existing standalone test.',
    );
}
foreach ($phpRegistry['serial_tests'] as $testName => $reason) {
    audit_test_assert(
        is_file(__DIR__ . '/' . $testName) && trim($reason) !== '',
        'Every exclusive PHP exception requires an existing test and a concrete reason.',
    );
}

$harnessSource = file_get_contents(__DIR__
    . '/admin_panel_lifecycle_browser_test.mjs') ?: '';
preg_match(
    "/const fixtureName = \[(.*?)\]\\.includes\\(process\\.argv\\[3\\]\\)/s",
    $harnessSource,
    $fixtureMatch,
);
$harnessFixtures = [];
if (isset($fixtureMatch[1])) {
    preg_match_all("/'([^']+)'/", $fixtureMatch[1], $fixtureNames);
    $harnessFixtures = $fixtureNames[1] ?? [];
}
foreach ($registry['node_tests'] as $nodeName => $nodeConfig) {
    if (empty($nodeConfig['php_argument'])) {
        continue;
    }
    $wrapperSource = file_get_contents(__DIR__ . '/' . $nodeName) ?: '';
    if (!php_source_contains(
        $wrapperSource,
        'admin_panel_lifecycle_browser_test.mjs',
    )) {
        continue;
    }
    audit_test_assert(
        preg_match(
            "/process\\.argv\\[3\\]\\s*=\\s*'([^']+)'/",
            $wrapperSource,
            $wrapperMatch,
        ) === 1,
        'PHP-rendered wrapper must select its harness fixture with a literal process.argv[3] assignment: '
            . $nodeName,
    );
    audit_test_assert(
        in_array($wrapperMatch[1], $harnessFixtures, true),
        'PHP-rendered wrapper fixture must be allowlisted by the shared browser harness: '
            . $nodeName,
    );
}

audit_test_assert(
    !empty($registry['node_tests']['gallery_download_zip64_test.mjs']['slow']),
    'ZIP64 boundary coverage must stay classified as slow.',
);
audit_test_assert(
    !empty($registry['node_tests']['lightbox_map_browser_test.mjs']['browser']),
    'The real Chromium lightbox test must stay classified as browser integration.',
);
audit_test_assert(
    !empty($registry['node_tests']['admin_gallery_title_completion_browser_test.mjs']['browser']),
    'Title completion must retain actual DOM-event browser coverage.',
);
audit_test_assert(
    !empty($registry['node_tests']['gallery_picker_parent_integration_browser_test.mjs']['php_argument']),
    'PHP-rendered picker browser fixture must receive the current PHP binary even outside PATH.',
);
audit_test_assert(
    (
        $registry['node_tests']['gallery_download_zip_test.mjs']['temporary_output']
        ?? ''
    ) !== '',
    'ZIP writer regression must receive a temporary output path.',
);
audit_test_assert(
    (
        $registry['php_test_requirements']['gallery_workflow_integration_test.php']['timeout']
        ?? 0
    ) >= 120,
    'The opt-in migrated HTTP workflow needs a bounded setup-aware timeout.',
);

$mandatoryFixture = [
    'id' => 'php-regression',
    'status' => 'PASS',
    'counts' => [
        'passed' => 2,
        'failed' => 0,
        'skipped' => 0,
        'blocked' => 0,
        'total' => 2,
    ],
    'details' => [
        'tests' => [
            [
                'name' => 'database_engine_contract_test.php',
                'status' => 'PASS',
                'exit_code' => 0,
                'timed_out' => false,
            ],
            [
                'name' => 'gallery_workflow_integration_test.php',
                'status' => 'PASS',
                'exit_code' => 0,
                'timed_out' => false,
            ],
        ],
    ],
];
$mandatoryNames = [
    'database_engine_contract_test.php',
    'gallery_workflow_integration_test.php',
];
audit_test_assert(
    missing_required_php_test_passes($mandatoryFixture, $mandatoryNames) === [],
    'Complete structured PHP PASS results must satisfy the mandatory disposable DB owner.',
);

$generalRequired = $registry['hosted_full_required_php_tests'] ?? null;
audit_test_assert(
    $generalRequired === ['runtime_module_plan_test.php'],
    'Hosted PHP regressions must retain their required PHP-specific module owner after independent packaging moves.',
);
$generalFixture = $mandatoryFixture;
$generalFixture['details']['tests'] = [$generalFixture['details']['tests'][0]];
$generalFixture['details']['tests'][0]['name'] = $generalRequired[0];
$generalFixture['counts']['passed'] = $generalFixture['counts']['total'] = 1;
audit_test_assert(
    missing_required_php_test_passes($generalFixture, $generalRequired) === [],
    'Required hosted general regressions must qualify with an individual structured PASS.',
);
foreach ([
    'SKIP' => 'skipped',
    'BLOCKED' => 'blocked',
    'FAIL' => 'failed',
] as $status => $counter) {
    $generalFailure = $generalFixture;
    $generalFailure['details']['tests'][0]['status'] = $status;
    $generalFailure['counts']['passed'] = 0;
    $generalFailure['counts'][$counter] = 1;
    audit_test_assert(
        missing_required_php_test_passes(
            $generalFailure,
            $generalRequired,
        ) === [$generalRequired[0]],
        'Hosted module coverage must reject '
            . $status
            . ' instead of hiding it within full PASS.',
    );
}
$missingModule = $generalFixture;
array_pop($missingModule['details']['tests']);
$missingModule['counts']['passed'] = 0;
$missingModule['counts']['total'] = 0;
audit_test_assert(
    missing_required_php_test_passes($missingModule, $generalRequired) === [
        $generalRequired[0],
    ],
    'A missing PHP-specific module plan child must not qualify the hosted full suite.',
);
$zeroExitLie = $generalFixture;
$zeroExitLie['details']['tests'][0]['exit_code'] = 1;
audit_test_assert(
    missing_required_php_test_passes($zeroExitLie, $generalRequired) === [
        $generalRequired[0],
    ],
    'A misleading module-plan PASS with nonzero exit must be rejected.',
);
$runnerSource = (string) file_get_contents(
    dirname(__DIR__) . '/scripts/audit.php',
);
audit_test_assert(
    php_source_contains($runnerSource, "getenv('GITHUB_ACTIONS') === 'true'")
        && php_source_contains($runnerSource, "'hosted_full_required_php_tests'")
        && php_source_contains(
            $runnerSource,
            $isParallelAuditContract
                ? 'missing_required_php_test_passes($task, $ownedRequired)'
                : 'missing_required_php_test_passes($task, $required)',
        ),
    'The existing central hosted full PHP runner must enforce structured required general child PASS.',
);
if ($isParallelAuditContract) {
    audit_test_assert(
        php_source_contains(
            $runnerSource,
            '? array_values(array_intersect($required, array_map(\'basename\', $files)))',
        )
            && php_source_contains($runnerSource, ': $required;'),
        'The owning shard must enforce its exact required children while unsharded audits retain every required child.',
    );
}

$packagingNames = $registry['packaging_tests'] ?? [];
audit_test_assert(
    $packagingNames === [
        'production_file_policy_test.php',
        'production_package_test.php',
    ]
        && in_array('packaging', $profiles['full'], true)
        && in_array('packaging', $profiles['release'], true)
        && !isset(
            $registry['php_test_requirements']['deploy_app_packaging_test.php'],
        ),
    'Policy and real compact packaging proofs need explicit central ownership without retired deploy registration.',
);
$packagingFixture = $mandatoryFixture;
$packagingFixture['id'] = 'packaging';
foreach ($packagingNames as $index => $name) {
    $packagingFixture['details']['tests'][$index]['name'] = $name;
}
audit_test_assert(
    missing_required_php_test_passes($packagingFixture, $packagingNames) === [],
    'Packaging owner must accept its own individual successful children.',
);
foreach (['SKIP', 'BLOCKED', 'FAIL'] as $status) {
    $badPackaging = $packagingFixture;
    $badPackaging['details']['tests'][1]['status'] = $status;
    audit_test_assert(
        missing_required_php_test_passes($badPackaging, $packagingNames) !== [],
        'Mandatory compact packaging must reject ' . $status . '.',
    );
}
$packagingEnvironment = [
    'GITHUB_ACTIONS' => 'true',
    'GITHUB_REPOSITORY' => 'klusik/PHP_gallery',
    'GITHUB_JOB' => 'real-database-browser',
    'PHP_GALLERY_PACKAGING_OWNER' => 'production-package',
    'PHP_GALLERY_PACKAGING_CANDIDATE' => str_repeat('a', 40),
];
foreach ([
    'real-database-browser',
    'runtime-compatibility',
    'release-audit',
] as $ownerJob) {
    $owned = \PhpGallery\Audit\audit_hosted_packaging_ownership(
        $profiles['full'],
        'full',
        array_replace($packagingEnvironment, ['GITHUB_JOB' => $ownerJob]),
        str_repeat('a', 40),
    );
    audit_test_assert(
        $owned['delegated_to_packaging'] === ['packaging']
            && !in_array('packaging', $owned['suites'], true)
            && in_array('php-regression', $owned['suites'], true),
        'Only DB-independent packaging may move to the same-candidate native package owner.',
    );
}
foreach ([
    ['GITHUB_ACTIONS' => 'false'],
    ['GITHUB_REPOSITORY' => 'fork/repo'],
    ['GITHUB_JOB' => 'unknown'],
    ['PHP_GALLERY_PACKAGING_OWNER' => ''],
    ['PHP_GALLERY_PACKAGING_CANDIDATE' => str_repeat('b', 40)],
] as $override) {
    $retained = \PhpGallery\Audit\audit_hosted_packaging_ownership(
        $profiles['full'],
        'full',
        array_replace($packagingEnvironment, $override),
        str_repeat('a', 40),
    );
    audit_test_assert(
        $retained['suites'] === $profiles['full']
            && $retained['delegated_to_packaging'] === [],
        'Unknown owner or mismatched candidate must retain full packaging execution.',
    );
}

$nonrequiredSkip = $mandatoryFixture;
$nonrequiredSkip['details']['tests'][] = [
    'name' => 'optional_fixture_test.php',
    'status' => 'SKIP',
    'exit_code' => 0,
    'timed_out' => false,
];
$nonrequiredSkip['counts'] = [
    'passed' => 2,
    'failed' => 0,
    'skipped' => 1,
    'blocked' => 0,
    'total' => 3,
];
audit_test_assert(
    missing_required_php_test_passes($nonrequiredSkip, $mandatoryNames) === [],
    'An unrelated optional SKIP must not invalidate independently required PASS evidence.',
);

$missingChild = $mandatoryFixture;
array_pop($missingChild['details']['tests']);
$missingChild['counts'] = [
    'passed' => 1,
    'failed' => 0,
    'skipped' => 0,
    'blocked' => 0,
    'total' => 1,
];
audit_test_assert(
    missing_required_php_test_passes($missingChild, $mandatoryNames)
    === ['gallery_workflow_integration_test.php'],
    'Missing required child must be rejected.',
);

foreach ([
    'SKIP' => 'skipped',
    'BLOCKED' => 'blocked',
    'FAIL' => 'failed',
] as $status => $counter) {
    $badChild = $mandatoryFixture;
    $badChild['details']['tests'][1]['status'] = $status;
    $badChild['counts']['passed'] = 1;
    $badChild['counts'][$counter] = 1;
    audit_test_assert(
        missing_required_php_test_passes($badChild, $mandatoryNames)
        === ['gallery_workflow_integration_test.php'],
        'Required PHP child ' . $status . ' must not qualify.',
    );
}
foreach (['exit_code' => 1, 'timed_out' => true] as $field => $value) {
    $badChild = $mandatoryFixture;
    $badChild['details']['tests'][1][$field] = $value;
    audit_test_assert(
        missing_required_php_test_passes($badChild, $mandatoryNames)
        === ['gallery_workflow_integration_test.php'],
        'Misleading PASS with bad ' . $field . ' must be refused.',
    );
}
$duplicateChild = $mandatoryFixture;
$duplicateChild['details']['tests'][] = $duplicateChild['details']['tests'][1];
$duplicateChild['counts']['passed'] = 3;
$duplicateChild['counts']['total'] = 3;
audit_test_assert(
    missing_required_php_test_passes($duplicateChild, $mandatoryNames)
        === $mandatoryNames,
    'Duplicate PHP child evidence must invalidate the complete owner.',
);
foreach (['id', 'status', 'counts', 'details'] as $field) {
    $badReport = $mandatoryFixture;
    if ($field === 'id') {
        $badReport['id'] = 'node-full';
    } elseif ($field === 'status') {
        $badReport['status'] = 'SKIP';
    } elseif ($field === 'counts') {
        $badReport['counts']['passed'] = 1;
    } else {
        unset($badReport['details']['tests']);
    }
    audit_test_assert(
        missing_required_php_test_passes($badReport, $mandatoryNames)
            === $mandatoryNames,
        'Missing or conflicting PHP report '
            . $field
            . ' must invalidate its required PASS owner.',
    );
}

$options = parse_options([
    'audit.php',
    '--profile',
    'quick',
    '--changed',
    '--report=cache/custom.md',
]);
audit_test_assert(
    $options['profile'] === 'quick',
    'Profile parser must accept separated option values.',
);
audit_test_assert(
    $options['changed'] === true,
    'Changed-file flag must be retained.',
);
audit_test_assert(
    $options['report'] === 'cache/custom.md',
    'Report parser must accept inline option values.',
);

audit_test_assert(
    output_is_skip("SKIP browser unavailable\n"),
    'SKIP output at the first line must be recognized.',
);
audit_test_assert(
    output_is_skip("setup\nSKIP: pdo_mysql unavailable\n"),
    'SKIP output after setup text must be recognized.',
);
audit_test_assert(
    !output_is_skip("All tests passed.\n"),
    'Ordinary successful output must not be classified as SKIP.',
);

$previousBrowser = getenv('PHP_GALLERY_BROWSER');
try {
    putenv('PHP_GALLERY_BROWSER=disabled');
    audit_test_assert(
        resolve_browser_executable() === null,
        'Explicit browser disablement must prevent fallback discovery.',
    );
    putenv('PHP_GALLERY_BROWSER=' . PHP_BINARY);
    audit_test_assert(
        resolve_browser_executable() !== null,
        'An explicit local executable must retain ordinary override discovery after disablement.',
    );
} finally {
    putenv(
        $previousBrowser === false
            ? 'PHP_GALLERY_BROWSER'
            : 'PHP_GALLERY_BROWSER=' . $previousBrowser,
    );
}

$previousWorkflowBrowser = getenv('GALLERY_WORKFLOW_BROWSER');
$previousWorkflowRequired = getenv('GALLERY_WORKFLOW_REQUIRED');
$previousWorkflowBrowserRequired = getenv('GALLERY_WORKFLOW_BROWSER_REQUIRED');
try {
    putenv('GALLERY_WORKFLOW_BROWSER=disabled');
    putenv('GALLERY_WORKFLOW_REQUIRED=1');
    // The optional browser-only probe must override the required-browser job environment in its child.
    putenv('GALLERY_WORKFLOW_BROWSER_REQUIRED=0');
    $disabledWorkflow = \PhpGallery\Audit\run_process(
        [PHP_BINARY, __DIR__ . '/gallery_workflow_browser_test.php'],
        dirname(__DIR__),
        5,
    );
    audit_test_assert(
        $disabledWorkflow['exit_code'] === 0
            && output_is_skip($disabledWorkflow['stdout'])
            && str_contains($disabledWorkflow['stdout'], 'explicitly disabled'),
        'Explicit browser-only opt-out must SKIP even when database/HTTP workflows remain required.',
    );
    putenv('GALLERY_WORKFLOW_BROWSER_REQUIRED=1');
    $requiredDisabledWorkflow = \PhpGallery\Audit\run_process(
        [PHP_BINARY, __DIR__ . '/gallery_workflow_browser_test.php'],
        dirname(__DIR__),
        5,
    );
    audit_test_assert(
        $requiredDisabledWorkflow['exit_code'] !== 0
            && str_contains($requiredDisabledWorkflow['stdout'], 'BLOCKED'),
        'A job that requires Chromium must block when its browser is explicitly disabled.',
    );
} finally {
    putenv(
        $previousWorkflowBrowser === false
            ? 'GALLERY_WORKFLOW_BROWSER'
            : 'GALLERY_WORKFLOW_BROWSER=' . $previousWorkflowBrowser,
    );
    putenv(
        $previousWorkflowRequired === false
            ? 'GALLERY_WORKFLOW_REQUIRED'
            : 'GALLERY_WORKFLOW_REQUIRED=' . $previousWorkflowRequired,
    );
    putenv(
        $previousWorkflowBrowserRequired === false
            ? 'GALLERY_WORKFLOW_BROWSER_REQUIRED'
            : 'GALLERY_WORKFLOW_BROWSER_REQUIRED='
            . $previousWorkflowBrowserRequired,
    );
}

$unittestFailure = parse_python_unittest_summary(
    "Ran 36 tests in 0.320s\n\nFAILED (failures=1, errors=2, skipped=3)\n",
);
audit_test_assert(
    $unittestFailure['total'] === 36,
    'Python unittest parser must retain the reported total.',
);
audit_test_assert(
    $unittestFailure['passed'] === 30,
    'Python unittest parser must derive passed tests from failure/error/skip counters.',
);
audit_test_assert(
    $unittestFailure['failed'] === 1,
    'Python unittest parser must retain failure counts.',
);
audit_test_assert(
    $unittestFailure['errors'] === 2,
    'Python unittest parser must retain error counts.',
);
audit_test_assert(
    $unittestFailure['skipped'] === 3,
    'Python unittest parser must retain skip counts.',
);

$unittestSuccess = parse_python_unittest_summary(
    "Ran 36 tests in 0.200s\n\nOK\n",
);
audit_test_assert(
    $unittestSuccess['passed'] === 36,
    'Successful Python unittest output must classify all non-skipped tests as passed.',
);

$winappReport = [
    'schema_version' => 1,
    'successful' => true,
    'runtime' => [
        'python_version' => '3.12.11',
        'python_implementation' => 'CPython',
        'platform' => 'Linux-6.8.0-x86_64',
        'executable' => '/usr/bin/python3',
        'suite_settings' => [
            'start_directory' => 'winapp/tests',
            'pattern' => 'test_*.py',
            'runner' => 'unittest.TextTestRunner',
            'verbosity' => 1,
        ],
    ],
    'counts' => [
        'total' => 3,
        'passed' => 1,
        'failed' => 0,
        'errors' => 0,
        'skipped' => 1,
        'expected_failures' => 1,
        'unexpected_successes' => 0,
        'subtest_failures' => 0,
        'subtest_errors' => 0,
        'subtest_skips' => 0,
        'fixture_errors' => 0,
        'fixture_skips' => 0,
    ],
    'tests' => [
        [
            'id' => 'suite.TestWidget.test_ready',
            'status' => 'PASS',
            'duration_seconds' => 0.004,
            'reason' => null,
            'subtests' => [],
        ],
        [
            'id' => 'suite.TestWidget.test_platform',
            'status' => 'SKIP',
            'duration_seconds' => 0.001,
            'reason' => 'Requires a Windows-only API.',
            'subtests' => [],
        ],
        [
            'id' => 'suite.TestWidget.test_known_issue',
            'status' => 'EXPECTED_FAILURE',
            'duration_seconds' => 0.003,
            'reason' => 'Known upstream limitation.',
            'subtests' => [],
        ],
    ],
    'fixture_errors' => [],
    'fixture_skips' => [],
];
$validatedWinapp = audit_validate_winapp_report($winappReport);
audit_test_assert(
    $validatedWinapp['valid']
        && $validatedWinapp['successful'] === true
        && $validatedWinapp['counts']['passed'] === 1
        && $validatedWinapp['counts']['skipped'] === 1
        && $validatedWinapp['counts']['expected_failures'] === 1
        && $validatedWinapp['tests'][1]['status'] === 'SKIP'
        && count($validatedWinapp['gaps']) === 2,
    'WinApp evidence must preserve factual pass, named host-dependent skip, and expected-failure outcomes separately.',
);
$winappSuccess = audit_evaluate_winapp_report(json_encode($winappReport), [
    'exit_code' => 0,
    'timed_out' => false,
    'duration_seconds' => 0.1,
]);
audit_test_assert(
    $winappSuccess['status'] === 'PASS'
        && $winappSuccess['tests'] === $validatedWinapp['tests'],
    'Only a complete valid successful report plus a clean process result may pass WinApp coverage.',
);

$invalidWinappCases = [];
$badWinapp = $winappReport;
$badWinapp['tests'] = [];
$badWinapp['counts']['total'] = 0;
$badWinapp['counts']['passed'] = 0;
$badWinapp['counts']['skipped'] = 0;
$badWinapp['counts']['expected_failures'] = 0;
$invalidWinappCases['empty suite'] = $badWinapp;
$badWinapp = $winappReport;
$badWinapp['tests'][2]['id'] = $badWinapp['tests'][1]['id'];
$invalidWinappCases['duplicate test identity'] = $badWinapp;
$badWinapp = $winappReport;
$badWinapp['tests'][0]['status'] = 'MAYBE';
$invalidWinappCases['unknown outcome'] = $badWinapp;
$badWinapp = $winappReport;
$badWinapp['tests'][0]['duration_seconds'] = -0.01;
$invalidWinappCases['negative duration'] = $badWinapp;
$badWinapp = $winappReport;
$badWinapp['tests'][0]['duration_seconds'] = INF;
$invalidWinappCases['non-finite duration'] = $badWinapp;
$badWinapp = $winappReport;
$badWinapp['counts']['passed'] = 2;
$invalidWinappCases['count mismatch'] = $badWinapp;
$badWinapp = $winappReport;
$badWinapp['successful'] = false;
$invalidWinappCases['success flag mismatch'] = $badWinapp;
$badWinapp = $winappReport;
$badWinapp['schema_version'] = 2;
$invalidWinappCases['unsupported schema'] = $badWinapp;
$badWinapp = $winappReport;
$badWinapp['tests'][0]['subtests'] = [
    ['id' => 'case-1', 'status' => 'SKIP', 'reason' => 'Expected condition.'],
    ['id' => 'case-1', 'status' => 'SKIP', 'reason' => 'Duplicate identity.'],
];
$invalidWinappCases['duplicate subtest identity'] = $badWinapp;
foreach ($invalidWinappCases as $label => $invalidWinapp) {
    audit_test_assert(
        !audit_validate_winapp_report($invalidWinapp)['valid'],
        'WinApp report validator must reject ' . $label . '.',
    );
}

$winappFailureReport = $winappReport;
$winappFailureReport['successful'] = false;
$winappFailureReport['counts']['failed'] = 1;
$winappFailureReport['counts']['passed'] = 0;
$winappFailureReport['counts']['subtest_failures'] = 1;
$winappFailureReport['tests'][0]['status'] = 'FAIL';
$winappFailureReport['tests'][0]['reason'] = 'Assertion failed.';
$winappFailureReport['tests'][0]['subtests'] = [
    [
        'id' => 'case-1',
        'status' => 'FAIL',
        'reason' => 'Subtest assertion failed.',
    ],
];
$validatedWinappFailure = audit_validate_winapp_report($winappFailureReport);
audit_test_assert(
    $validatedWinappFailure['valid']
        && !$validatedWinappFailure['successful']
        && $validatedWinappFailure['counts']['subtest_failures'] === 1,
    'WinApp report must retain parent and subtest failure evidence without deriving invented child exit codes.',
);
$winappFailingChildWithPassParent = $winappReport;
$winappFailingChildWithPassParent['counts']['subtest_failures'] = 1;
$winappFailingChildWithPassParent['tests'][0]['subtests'] = [[
    'id' => 'suite.TestWidget.test_ready subTest#1',
    'status' => 'FAIL',
    'reason' => 'Child assertion failed.',
]];
$invalidWinappCases['passing parent with failing child'] =
    $winappFailingChildWithPassParent;
$winappSkippedChildWithPassParent = $winappReport;
$winappSkippedChildWithPassParent['counts']['subtest_skips'] = 1;
$winappSkippedChildWithPassParent['tests'][0]['subtests'] = [[
    'id' => 'suite.TestWidget.test_ready subTest#1',
    'status' => 'SKIP',
    'reason' => 'Child requires a Windows-only API.',
]];
$invalidWinappCases['passing parent with skipped child'] =
    $winappSkippedChildWithPassParent;
$invalidWinappCases['duplicate fixture identity'] = array_replace($winappReport, [
    'fixture_errors' => [
        [
            'phase' => 'setUpModule',
            'id' => 'suite',
            'reason' => 'First fixture error.',
        ],
        [
            'phase' => 'setUpModule',
            'id' => 'suite',
            'reason' => 'Duplicate fixture error.',
        ],
    ],
]);
foreach ([
    'passing parent with failing child',
    'passing parent with skipped child',
    'duplicate fixture identity',
] as $label) {
    audit_test_assert(
        !audit_validate_winapp_report($invalidWinappCases[$label])['valid'],
        'WinApp report validator must reject ' . $label . '.',
    );
}
$invalidFixtureSkipPhase = array_replace($winappReport, [
    'fixture_skips' => [[
        'phase' => 'unknownFixturePhase',
        'id' => 'unknown fixture owner',
        'reason' => 'Unsupported phase.',
    ]],
    'counts' => array_replace($winappReport['counts'], ['fixture_skips' => 1]),
]);
audit_test_assert(
    !audit_validate_winapp_report($invalidFixtureSkipPhase)['valid'],
    'WinApp fixture skips must use an actual class or module fixture phase.',
);

$winappFixtureFailureReport = $winappReport;
$winappFixtureFailureReport['successful'] = false;
$winappFixtureFailureReport['counts']['errors'] = 2;
$winappFixtureFailureReport['counts']['passed'] = 0;
$winappFixtureFailureReport['counts']['fixture_errors'] = 1;
$winappFixtureFailureReport['tests'][0]['status'] = 'ERROR';
$winappFixtureFailureReport['tests'][0]['reason'] = 'setUpModule failed.';
$winappFixtureFailureReport['fixture_errors'] = [[
    'phase' => 'setUpModule',
    'id' => 'suite',
    'reason' => 'Fixture initialization failed.',
]];
$validatedWinappFixtureFailure = audit_validate_winapp_report(
    $winappFixtureFailureReport,
);
audit_test_assert(
    $validatedWinappFixtureFailure['valid']
        && !$validatedWinappFixtureFailure['successful']
        && $validatedWinappFixtureFailure['counts']['fixture_errors'] === 1
        && $validatedWinappFixtureFailure['counts']['errors'] === 2,
    'WinApp fixture errors must remain separately named and counted alongside unittest error records.',
);
$winappFixtureOnlyReport = $winappReport;
$winappFixtureOnlyReport['successful'] = false;
$winappFixtureOnlyReport['tests'] = [];
$winappFixtureOnlyReport['counts'] = array_fill_keys(
    array_keys($winappReport['counts']),
    0,
);
$winappFixtureOnlyReport['counts']['errors'] = 1;
$winappFixtureOnlyReport['counts']['fixture_errors'] = 1;
$winappFixtureOnlyReport['fixture_errors'] = [[
    'phase' => 'setUpModule',
    'id' => 'setUpModule (suite)',
    'reason' => 'Module fixture failed.',
]];
$validatedWinappFixtureOnly = audit_validate_winapp_report(
    $winappFixtureOnlyReport,
);
audit_test_assert(
    $validatedWinappFixtureOnly['valid']
        && !$validatedWinappFixtureOnly['successful']
        && $validatedWinappFixtureOnly['counts']['total'] === 0
        && $validatedWinappFixtureOnly['counts']['fixture_errors'] === 1
        && count($validatedWinappFixtureOnly['fixture_errors']) === 1,
    'A named unittest fixture failure with no started test cases must remain valid failure evidence.',
);
$winappFixtureSkipReport = $winappReport;
$winappFixtureSkipReport['tests'] = [];
$winappFixtureSkipReport['counts'] = array_fill_keys(
    array_keys($winappReport['counts']),
    0,
);
$winappFixtureSkipReport['fixture_skips'] = [[
    'phase' => 'setUpClass',
    'id' => 'setUpClass (suite.TestWidget)',
    'reason' => 'Windows-only host API.',
]];
$winappFixtureSkipReport['counts']['fixture_skips'] = 1;
$validatedWinappFixtureSkip = audit_validate_winapp_report(
    $winappFixtureSkipReport,
);
audit_test_assert(
    $validatedWinappFixtureSkip['valid']
        && $validatedWinappFixtureSkip['successful']
        && $validatedWinappFixtureSkip['counts']['total'] === 0
        && $validatedWinappFixtureSkip['counts']['fixture_skips'] === 1
        && count($validatedWinappFixtureSkip['gaps']) === 1,
    'A named fixture skip without started cases must remain explicit successful skip evidence.',
);
$winappSubtestSkipReport = $winappReport;
$winappSubtestSkipReport['counts']['passed'] = 0;
$winappSubtestSkipReport['counts']['skipped'] = 2;
$winappSubtestSkipReport['counts']['subtest_skips'] = 1;
$winappSubtestSkipReport['tests'][0]['status'] = 'SKIP';
$winappSubtestSkipReport['tests'][0]['reason'] = 'unittest did not report parent success after subtest skip.';
$winappSubtestSkipReport['tests'][0]['subtests'] = [[
    'id' => 'suite.TestWidget.test_ready subTest#1',
    'status' => 'SKIP',
    'reason' => 'Requires a Windows-only API.',
]];
$validatedWinappSubtestSkip = audit_validate_winapp_report(
    $winappSubtestSkipReport,
);
audit_test_assert(
    $validatedWinappSubtestSkip['valid']
        && $validatedWinappSubtestSkip['successful']
        && $validatedWinappSubtestSkip['tests'][0]['status'] === 'SKIP'
        && count($validatedWinappSubtestSkip['gaps']) === 4,
    'Subtest skip must remain a named child gap and must not inherit a parent PASS.',
);

foreach ([
    [
        'label' => 'missing output',
        'contents' => null,
        'process' => [
            'exit_code' => 0,
            'timed_out' => false,
            'duration_seconds' => 0.1,
        ],
        'status' => 'BLOCKED',
    ],
    [
        'label' => 'truncated JSON',
        'contents' => '{"schema_version":1',
        'process' => [
            'exit_code' => 0,
            'timed_out' => false,
            'duration_seconds' => 0.1,
        ],
        'status' => 'BLOCKED',
    ],
    [
        'label' => 'malformed JSON',
        'contents' => 'not JSON',
        'process' => [
            'exit_code' => 0,
            'timed_out' => false,
            'duration_seconds' => 0.1,
        ],
        'status' => 'BLOCKED',
    ],
    [
        'label' => 'nonzero process despite valid report',
        'contents' => json_encode($winappReport),
        'process' => [
            'exit_code' => 2,
            'timed_out' => false,
            'duration_seconds' => 0.1,
        ],
        'status' => 'FAIL',
    ],
    [
        'label' => 'timeout despite valid report',
        'contents' => json_encode($winappReport),
        'process' => [
            'exit_code' => 0,
            'timed_out' => true,
            'duration_seconds' => 90.0,
        ],
        'status' => 'FAIL',
    ],
    [
        'label' => 'unittest failure with zero exit',
        'contents' => json_encode($winappFailureReport),
        'process' => [
            'exit_code' => 0,
            'timed_out' => false,
            'duration_seconds' => 0.1,
        ],
        'status' => 'FAIL',
    ],
] as $case) {
    $evaluatedWinapp = audit_evaluate_winapp_report(
        $case['contents'],
        $case['process'],
    );
    audit_test_assert(
        $evaluatedWinapp['status'] === $case['status'],
        'WinApp report/process evaluator must reject ' . $case['label'] . '.',
    );
}
$winappRunner = (string) file_get_contents(
    dirname(__DIR__) . '/scripts/audit.php',
);
$winappRunnerBody = gallery_writer_functions($winappRunner)[
    'audit_run_winapp'
]['body'] ?? '';
audit_test_assert(
    php_source_contains($winappRunnerBody, "'scripts.audit_winapp_report'")
        && php_source_contains($winappRunnerBody, 'audit_evaluate_winapp_report(')
        && php_source_contains(
            $winappRunnerBody,
            '\'report_file\' => $adapterReportPath',
        )
        && !php_source_contains(
            $winappRunnerBody,
            'parse_python_unittest_summary($output)',
        )
        && php_source_contains($winappRunnerBody, 'run_process($command, $root, 90)'),
    'Central WinApp audit must preserve the existing 90-second discovery owner and consume only its saved structured report.',
);

$python3Runner = static fn(array $command): array => [
    'exit_code' => 0,
    'stdout' => 'Python 3.14.4',
    'stderr' => '',
    'timed_out' => false,
    'duration' => 0.001,
];
$python2Runner = static fn(array $command): array => [
    'exit_code' => 0,
    'stdout' => 'Python 2.7.18',
    'stderr' => '',
    'timed_out' => false,
    'duration' => 0.001,
];
audit_test_assert(
    python_command_is_usable(['python'], $python3Runner),
    'Python 3 version probes must be accepted.',
);
audit_test_assert(
    !python_command_is_usable(['python'], $python2Runner),
    'Python 2 version probes must not satisfy WinApp coverage.',
);

$aliasLikePython = resolve_python_command_from_candidates(
    [['python3'], ['python'], ['py', '-3']],
    static fn(string $name): ?string => null,
    static fn(array $command): bool => $command === ['python'],
);
audit_test_assert(
    $aliasLikePython === ['python'],
    'Runnable PATH commands must remain eligible when filesystem lookup cannot resolve a Windows-style execution alias.',
);

$launcherPython = resolve_python_command_from_candidates(
    [['python3'], ['python'], ['py', '-3']],
    static fn(string $name): ?string => null,
    static fn(array $command): bool => $command === ['py', '-3'],
);
audit_test_assert(
    $launcherPython === ['py', '-3'],
    'The Windows py -3 launcher must remain a supported Python fallback.',
);

$root = dirname(__DIR__);
audit_test_assert(
    relative_path($root . '/tests/audit_runner_test.php', $root)
        === 'tests/audit_runner_test.php',
    'Repository-relative path normalization must remain stable.',
);
$previousWorkerCount = getenv('PHP_GALLERY_AUDIT_WORKERS');
try {
    putenv('PHP_GALLERY_AUDIT_WORKERS');
    audit_test_assert(
        worker_count() === 4,
        'The process pool default must remain four workers.',
    );
    putenv('PHP_GALLERY_AUDIT_WORKERS=7');
    audit_test_assert(
        worker_count() === 7
            && worker_count('2') === 2
            && worker_count() >= 1
            && worker_count() <= 8,
        'Worker count must honor the environment and explicit override within the bounded range.',
    );
    foreach (['0', '9', '1.5', 'many'] as $invalidWorkerCount) {
        $invalidRejected = false;
        try {
            worker_count($invalidWorkerCount);
        } catch (InvalidArgumentException) {
            $invalidRejected = true;
        }
        audit_test_assert(
            $invalidRejected,
            'Invalid worker override must be rejected: ' . $invalidWorkerCount,
        );
    }
} finally {
    putenv(
        $previousWorkerCount === false
            ? 'PHP_GALLERY_AUDIT_WORKERS'
            : 'PHP_GALLERY_AUDIT_WORKERS=' . $previousWorkerCount,
    );
}
$previousBrowserRequired = getenv('PHP_GALLERY_BROWSER_REQUIRED');
try {
    putenv('PHP_GALLERY_BROWSER_REQUIRED=1');
    audit_test_assert(
        browser_required(),
        'The dedicated browser coverage switch must be recognized.',
    );
    putenv('PHP_GALLERY_BROWSER_REQUIRED=0');
    audit_test_assert(
        !browser_required(),
        'Other browser switch values must not require Chromium.',
    );
} finally {
    putenv(
        $previousBrowserRequired === false
            ? 'PHP_GALLERY_BROWSER_REQUIRED'
            : 'PHP_GALLERY_BROWSER_REQUIRED=' . $previousBrowserRequired,
    );
}
audit_test_assert(process_status(['exit_code' => 0, 'stdout' => 'passed'])
    === 'PASS', 'A clean child must normalize to PASS.');
audit_test_assert(process_status([
    'exit_code' => 1,
    'stdout' => 'specific failure',
]) === 'FAIL', 'A nonzero child must normalize to FAIL.');
audit_test_assert(process_status([
    'exit_code' => 0,
    'stdout' => "SKIP unavailable\n",
]) === 'SKIP', 'Optional child skip must remain SKIP.');
audit_test_assert(process_status([
    'exit_code' => 0,
    'stdout' => "SKIP Chromium unavailable\n",
], true) === 'BLOCKED', 'Required child skip must become BLOCKED.');
audit_test_assert(process_status([
    'exit_code' => 0,
    'stdout' => "BLOCKED missing runtime\n",
]) === 'BLOCKED', 'Explicit blocked child output must remain BLOCKED.');
audit_test_assert(process_status(['exit_code' => 124, 'timed_out' => true])
    === 'FAIL', 'Timed out children must normalize to FAIL.');

$numericMetrics = \PhpGallery\Audit\parse_php_audit_metrics(
    "PASS complete\nAUDIT_METRICS {\"compile_seconds\":0.125,\"children\":192,\"zero\":0}\nPASS retained\n",
);
audit_test_assert(
    $numericMetrics === [
        'present' => true,
        'valid' => true,
        'metrics' => [
            'compile_seconds' => 0.125,
            'children' => 192,
            'zero' => 0,
        ],
        'error' => null,
    ],
    'Passing PHP children must retain their finite numeric measurements separately from ordinary stdout.',
);
foreach ([
    '',
    "PASS ordinary fixture\n",
    'Ordinary diagnostic mentions AUDIT_METRICS without emitting a record.',
] as $ordinaryOutput) {
    audit_test_assert(
        \PhpGallery\Audit\parse_php_audit_metrics($ordinaryOutput)
        === [
            'present' => false,
            'valid' => true,
            'metrics' => [],
            'error' => null,
        ],
        'Ordinary tests must not need or fabricate optional metric evidence.',
    );
}
$invalidMetrics = [
    'missing prefix delimiter' => 'AUDIT_METRICS{"seconds":1}',
    'missing payload' => 'AUDIT_METRICS ',
    'invalid JSON' => 'AUDIT_METRICS {broken}',
    'empty metrics' => 'AUDIT_METRICS {}',
    'list instead of object' => 'AUDIT_METRICS [1,2]',
    'string value' => 'AUDIT_METRICS {"path":"/private/source"}',
    'numeric string' => 'AUDIT_METRICS {"seconds":"1.5"}',
    'boolean value' => 'AUDIT_METRICS {"done":true}',
    'null value' => 'AUDIT_METRICS {"seconds":null}',
    'nested object' => 'AUDIT_METRICS {"nested":{"seconds":1}}',
    'nested list' => 'AUDIT_METRICS {"samples":[1,2]}',
    'negative number' => 'AUDIT_METRICS {"seconds":-1}',
    'nonfinite number' => 'AUDIT_METRICS {"seconds":1e999}',
    'unsafe key' => 'AUDIT_METRICS {"../private":1}',
    'uppercase key' => 'AUDIT_METRICS {"Seconds":1}',
    'long key' => 'AUDIT_METRICS {"' . str_repeat('a', 65) . '":1}',
    'duplicate key' => 'AUDIT_METRICS {"seconds":1,"seconds":2}',
    'multiple records' => "AUDIT_METRICS {\"seconds\":1}\nAUDIT_METRICS {\"seconds\":2}\n",
    'oversized record' =>
        'AUDIT_METRICS {' . str_repeat(' ', 4096) . '"seconds":1}',
];
$tooManyMetrics = [];
for ($metricIndex = 0; $metricIndex < 33; $metricIndex++)
    $tooManyMetrics['value_' . $metricIndex] = $metricIndex;
$invalidMetrics['too many fields'] =
    'AUDIT_METRICS ' . json_encode($tooManyMetrics, JSON_THROW_ON_ERROR);
foreach ($invalidMetrics as $case => $emittedOutput) {
    $rejectedMetrics =
        \PhpGallery\Audit\parse_php_audit_metrics($emittedOutput);
    audit_test_assert(
        $rejectedMetrics['present']
            && !$rejectedMetrics['valid']
            && $rejectedMetrics['metrics'] === []
            && is_string($rejectedMetrics['error']),
        'Malformed emitted PHP metrics must fail explicitly without retaining arbitrary data: '
            . $case,
    );
}
$retainedChild = [
    'name' => 'owned_fixture_test.php',
    'status' => 'PASS',
    'exit_code' => 0,
    'timed_out' => false,
    'duration_seconds' => 0.25,
    'serial' => false,
    'metrics' => $numericMetrics['metrics'],
];
$retainedReport = json_decode(
    json_encode(['tests' => [$retainedChild]], JSON_THROW_ON_ERROR),
    true,
    8,
    JSON_THROW_ON_ERROR,
);
audit_test_assert(
    $retainedReport['tests'][0]['metrics'] === $numericMetrics['metrics'],
    'Structured child evidence must preserve passing-phase numeric measurements through JSON artifact serialization.',
);

$browserReason = 'BROWSER FAIL #139: Cancel: editor remains open {"drawerOpen":false}';
audit_test_assert(
    node_failure_detail(
        "intro\n" . $browserReason . "\nAssertionError: wrapper failed\n",
    ) === $browserReason,
    'The actual browser assertion and state flags must take precedence over its generic Node wrapper error.',
);
audit_test_assert(
    node_failure_detail(
        "\x1B[31mError: Owned Chromium debugging endpoint unavailable\x1B[0m\n",
    )
        === 'Error: Owned Chromium debugging endpoint unavailable',
    'Startup errors must remain actionable without terminal colors.',
);
audit_test_assert(
    node_failure_detail('BROWSER FAIL #1234: ' . str_repeat('x', 2000)) !== ''
        && strlen(node_failure_detail('BROWSER FAIL: ' . str_repeat('x', 2000)))
            <= 1000,
    'Browser reasons must support every fixture assertion number while bounding console output.',
);
audit_test_assert(
    node_failure_detail('BROWSER PASS: complete') === '',
    'Passing output must not be classified as a failure detail.',
);
$consoleTask = \PhpGallery\Audit\task_result(
    'browser-map',
    'Chromium browser integration',
    'FAIL',
    0.1,
    [],
    '1 fail',
    'cache/test-audit/fixture/browser-map.log',
    ['problems' => ['another_fixture.mjs: exit code 1. ' . $browserReason]],
);
audit_test_assert(
    str_contains(render_console_problems($consoleTask), 'another_fixture.mjs')
        && str_contains(render_console_problems($consoleTask), $browserReason)
        && str_contains(
            render_console_problems($consoleTask),
            'browser-map.log',
        ),
    'Any fixture failure must expose its filename, specific assertion and drill-down log directly in the console.',
);
audit_test_assert(
    render_console_problems(array_replace($consoleTask, ['status' => 'PASS']))
        === '',
    'Passing suites must not print failure details.',
);
$consoleTask['details']['problems'] = array_fill(
    0,
    8,
    "bounded\nproblem\0" . str_repeat('x', 2000),
);
$boundedConsole = render_console_problems($consoleTask);
audit_test_assert(
    substr_count($boundedConsole, '  - ') === 6
        && str_contains($boundedConsole, '3 more problems')
        && !str_contains($boundedConsole, "\0")
        && !str_contains($boundedConsole, "bounded\nproblem"),
    'Console problems must bound line count and length and prevent injected control lines.',
);

$fixtureDirectory =
    sys_get_temp_dir() . '/php-gallery-audit-' . bin2hex(random_bytes(6));
mkdir($fixtureDirectory);
$statePath = $fixtureDirectory . '/state.txt';
$eventPath = $fixtureDirectory . '/events.txt';
$lockPath = $fixtureDirectory . '/state.lock';
try {
    $validSource = $fixtureDirectory . '/valid source.php';
    $invalidSource = $fixtureDirectory . '/invalid source.php';
    $executionSentinel = $fixtureDirectory . '/must-not-execute.txt';
    file_put_contents(
        $validSource,
        '<?php file_put_contents('
            . var_export($executionSentinel, true)
            . ', "executed");',
    );
    file_put_contents($invalidSource, '<?php function broken( {');
    $syntaxResults = run_file_checks(
        [$invalidSource, $validSource],
        [PHP_BINARY, '-n', '-l'],
        $fixtureDirectory,
        2,
        15,
    );
    audit_test_assert(
        process_status($syntaxResults[0]) === 'FAIL'
            && process_status($syntaxResults[1]) === 'PASS'
            && str_contains(
                $syntaxResults[0]['stdout'] . $syntaxResults[0]['stderr'],
                'invalid source.php',
            )
            && str_contains($syntaxResults[1]['stdout'], 'valid source.php')
            && !is_file($executionSentinel),
        'Parallel syntax checks must attribute failures to literal paths with spaces and never execute checked code.',
    );
    file_put_contents($statePath, "0,0\n");
    $makeFixtureJob = static function (
        string $name,
        string $kind = 'parallel',
        int $delayMs = 120,
    ) use ($statePath, $eventPath, $lockPath): array {
        $script =
            '$state='
            . var_export($statePath, true)
            . ';$events='
            . var_export($eventPath, true)
            . ';$lockPath='
            . var_export($lockPath, true)
            . ';$kind='
            . var_export($kind, true)
            . ';$name='
            . var_export($name, true)
            . ';$delay='
            . $delayMs
            . ';'
            . '$lock=fopen($lockPath,"c+");flock($lock,LOCK_EX);$state=file_get_contents('
            . var_export($statePath, true)
            . ');[$active,$max]=array_map("intval",explode(",",trim($state)));$active++;$max=max($max,$active);'
            . 'file_put_contents('
            . var_export($statePath, true)
            . ',"$active,$max\n");if($kind==="serial"){file_put_contents($events,"serial-active:$active\n",FILE_APPEND);}flock($lock,LOCK_UN);fclose($lock);'
            . 'usleep($delay*1000);$lock=fopen($lockPath,"c+");flock($lock,LOCK_EX);$state=file_get_contents('
            . var_export($statePath, true)
            . ');[$active,$max]=array_map("intval",explode(",",trim($state)));$active--;file_put_contents('
            . var_export($statePath, true)
            . ',"$active,$max\n");flock($lock,LOCK_UN);fclose($lock);echo '
            . var_export($name, true)
            . ';';
        return [
            'command' => [PHP_BINARY, '-r', $script],
            'timeout' => 3,
            'serial' => $kind === 'serial',
        ];
    };
    $poolResults = run_process_pool(
        [
            $makeFixtureJob('first', 'parallel'),
            $makeFixtureJob('second', 'parallel', 30),
            $makeFixtureJob('exclusive', 'serial', 40),
            $makeFixtureJob('fourth', 'parallel'),
            $makeFixtureJob('fifth', 'parallel'),
        ],
        $fixtureDirectory,
        2,
    );
    $poolOutputs = array_map(static fn(array $result): string => trim(
        $result['stdout'],
    ), $poolResults);
    $stateValues = array_map('intval', explode(
        ',',
        trim((string) file_get_contents($statePath)),
    ));
    $events = file_get_contents($eventPath) ?: '';
    audit_test_assert(
        $poolOutputs === ['first', 'second', 'exclusive', 'fourth', 'fifth'],
        'Concurrent process results must retain input order after out-of-order completion.',
    );
    audit_test_assert(
        $stateValues[1] === 2 && str_contains($events, 'serial-active:1'),
        'Parallel work must obey its bound and drain before an exclusive child starts.',
    );
    audit_test_assert(
        count(array_filter(
            $poolResults,
            static fn(array $result): bool => (
                process_status($result) === 'PASS'
            ),
        )) === 5,
        'All successful process-pool fixture children must retain independent result status.',
    );
    $invalidPoolRejected = false;
    try {
        run_process_pool([], $fixtureDirectory, 9);
    } catch (InvalidArgumentException) {
        $invalidPoolRejected = true;
    }
    audit_test_assert(
        $invalidPoolRejected,
        'The scheduler itself must reject worker limits outside the supported range.',
    );
    $failureResults = run_process_pool(
        [
            [
                'command' => [
                    PHP_BINARY,
                    '-r',
                    'fwrite(STDERR,"fixture-failure"); exit(7);',
                ],
                'timeout' => 2,
            ],
            [
                'command' => ['php-gallery-command-that-does-not-exist'],
                'timeout' => 2,
            ],
        ],
        $fixtureDirectory,
        2,
    );
    audit_test_assert(
        process_status($failureResults[0]) === 'FAIL'
            && $failureResults[0]['exit_code'] === 7
            && str_contains($failureResults[0]['stderr'], 'fixture-failure'),
        'Failure output and exit status must remain attributable to the corresponding child.',
    );
    audit_test_assert(
        process_status($failureResults[1]) === 'BLOCKED'
            && str_contains(
                $failureResults[1]['stderr'],
                'Unable to allocate capture streams',
            ),
        'Process spawn failure must produce a diagnostic BLOCKED result.',
    );
    $timeoutResults = run_process_pool(
        [
            [
                'command' => [
                    PHP_BINARY,
                    '-r',
                    'usleep(1100000); echo "preceding";',
                ],
                'timeout' => 3,
            ],
            [
                'command' => [PHP_BINARY, '-r', 'echo "started-later";'],
                'timeout' => 1,
            ],
            [
                'command' => [
                    PHP_BINARY,
                    '-r',
                    'usleep(1300000); echo "must-time-out";',
                ],
                'timeout' => 1,
            ],
            [
                'command' => [PHP_BINARY, '-r', 'echo "recovered";'],
                'timeout' => 1,
            ],
        ],
        $fixtureDirectory,
        1,
    );
    audit_test_assert(
        process_status($timeoutResults[1]) === 'PASS'
            && trim($timeoutResults[1]['stdout']) === 'started-later'
            && process_status($timeoutResults[2]) === 'FAIL'
            && $timeoutResults[2]['timed_out']
            && process_status($timeoutResults[3]) === 'PASS',
        'A queued child gets a fresh timeout after its actual start; a timed-out child does not prevent recovery.',
    );

    $treeFixtures = [
        [
            'sentinel' => $fixtureDirectory . '/group-late-sentinel.txt',
            'ready' => $fixtureDirectory . '/group-ready.txt',
            'force_fallback' => false,
        ],
    ];
    if (DIRECTORY_SEPARATOR !== '\\') {
        $treeFixtures[] = [
            'sentinel' => $fixtureDirectory . '/fallback-late-sentinel.txt',
            'ready' => $fixtureDirectory . '/fallback-ready.txt',
            'force_fallback' => true,
        ];
    }
    $treeActive = [];
    try {
        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
        foreach ($treeFixtures as $index => $treeFixture) {
            $grandchildReady =
                $fixtureDirectory . '/grandchild-' . $index . '-ready.txt';
            $grandchildCode =
                '$ready='
                . var_export($grandchildReady, true)
                . ';$sentinel='
                . var_export($treeFixture['sentinel'], true)
                . ';file_put_contents($ready,"ready");usleep(1500000);file_put_contents($sentinel,"survived");';
            $rootCode =
                '$childCommand='
                . var_export([PHP_BINARY, '-r', $grandchildCode], true)
                . ';$null='
                . var_export($nullDevice, true)
                . ';$pipes=[];$child=proc_open($childCommand,[0=>["file",$null,"r"],1=>["file",$null,"w"],2=>["file",$null,"w"]],$pipes,'
                . var_export($root, true)
                . ',null,["bypass_shell"=>true]);if(!is_resource($child)){exit(31);} $deadline=microtime(true)+8;'
                . 'while(!is_file('
                . var_export($grandchildReady, true)
                . ')&&microtime(true)<$deadline){usleep(10000);}file_put_contents('
                . var_export($treeFixture['ready'], true)
                . ',"ready");usleep(3000000);';
            $activeTreeProcess = \PhpGallery\Audit\start_process([
                'command' => [PHP_BINARY, '-r', $rootCode],
                'timeout' => 1,
            ], $root);
            audit_test_assert(
                isset($activeTreeProcess['process']),
                'Process-tree fixture root must start successfully.',
            );
            if ($treeFixture['force_fallback']) {
                $activeTreeProcess['group'] = false;
            }
            $treeActive[$index] = $activeTreeProcess;
        }
        $readyDeadline = (hrtime(true) / 1e9) + 8;
        do {
            $allReady = true;
            foreach ($treeFixtures as $treeFixture) {
                $allReady = $allReady && is_file($treeFixture['ready']);
            }
            if (!$allReady) {
                usleep(10000);
            }
        } while (!$allReady && (hrtime(true) / 1e9) < $readyDeadline);
        audit_test_assert(
            $allReady,
            'Each process-tree fixture must confirm its grandchild started before cleanup.',
        );
        foreach ($treeActive as $index => $activeTreeProcess) {
            // Set the monotonic start just beyond the registered one-second timeout after the fixture is ready.
            $activeTreeProcess['started'] = (hrtime(true) / 1e9) - 1.1;
            \PhpGallery\Audit\terminate_process($activeTreeProcess);
            $treeResults[$index] = \PhpGallery\Audit\finish_process(
                $activeTreeProcess,
                proc_get_status($activeTreeProcess['process']),
                true,
            );
            unset($treeActive[$index]);
        }
        audit_test_assert(
            count($treeResults ?? []) === count($treeFixtures)
                && count(array_filter(
                    $treeResults,
                    static fn(array $result): bool => (
                        $result['timed_out'] && $result['exit_code'] === 124
                    ),
                )) === count($treeFixtures),
            'Process-tree cleanup must return the same timeout result as an expired child deadline.',
        );
        usleep(1700000);
        foreach ($treeFixtures as $treeFixture) {
            audit_test_assert(
                !is_file($treeFixture['sentinel']),
                'A killed child or grandchild must not write after its original delayed side effect deadline: '
                    . basename($treeFixture['sentinel']),
            );
        }
    } finally {
        foreach ($treeActive as $activeTreeProcess) {
            \PhpGallery\Audit\terminate_process($activeTreeProcess);
            \PhpGallery\Audit\finish_process(
                $activeTreeProcess,
                proc_get_status($activeTreeProcess['process']),
                true,
            );
        }
    }
} finally {
    foreach (glob($fixtureDirectory . '/*') ?: [] as $fixtureFile) {
        unlink($fixtureFile);
    }
    rmdir($fixtureDirectory);
}
$noisyProcess = \PhpGallery\Audit\run_process(
    [
        PHP_BINARY,
        '-r',
        'fwrite(STDERR, str_repeat("E", 262144)); fwrite(STDOUT, str_repeat("O", 262144));',
    ],
    $root,
    5,
);
audit_test_assert(
    $noisyProcess['exit_code'] === 0
        && strlen($noisyProcess['stdout']) === 262144
        && strlen($noisyProcess['stderr']) === 262144,
    'Both large output streams must drain without Windows pipe deadlocks.',
);
$timedProcess = \PhpGallery\Audit\run_process(
    [PHP_BINARY, '-r', 'usleep(3000000);'],
    $root,
    1,
);
audit_test_assert(
    $timedProcess['timed_out']
        && $timedProcess['exit_code'] === 124
        && $timedProcess['duration'] < 2.5,
    'A silent child must still observe the hard process timeout.',
);

$performanceRegistry = require
    $root . '/scripts/audit_performance_registry.php';
audit_test_assert(
    array_keys($performanceRegistry) === [
        'early-runtime',
        'application-bootstrap',
    ],
    'Both registered clean-process bootstrap probes must remain explicit.',
);
foreach ($performanceRegistry as $probeId => $limits) {
    $probeProcess = \PhpGallery\Audit\run_process(
        [PHP_BINARY, $root . '/scripts/audit_runtime_probe.php', $probeId],
        $root,
        15,
    );
    $metrics = json_decode($probeProcess['stdout'], true);
    audit_test_assert(
        $probeProcess['exit_code'] === 0 && is_array($metrics),
        'Registered probe must emit valid JSON from a fresh PHP process: '
            . $probeId,
    );
    audit_test_assert(
        performance_metric_problems($metrics, $probeId, $limits) === [],
        'Registered clean-process metrics must satisfy their schema and deterministic limits: '
            . $probeId,
    );
    audit_test_assert(
        count($metrics['included_paths']) === $metrics['included_php_files']
            && !array_filter(
                $metrics['included_paths'],
                static fn(string $path): bool => (
                    !str_starts_with($path, 'app/')
                    || str_contains($path, 'audit_')
                ),
            ),
        'Probe include inventory must be application PHP only and match its count: '
            . $probeId,
    );

    $observationalWall = $metrics;
    $observationalWall['bootstrap_wall_ms'] = 9999999999.0;
    audit_test_assert(
        performance_metric_problems($observationalWall, $probeId, $limits)
            === [],
        'A large finite wall time must remain observational rather than failing deterministic guards.',
    );
    $includeOverLimit = $metrics;
    $includeOverLimit['included_php_files'] =
        $limits['max_included_php_files'] + 1;
    $includeOverLimit['included_paths'] = array_fill(
        0,
        $includeOverLimit['included_php_files'],
        'app/performance_test_synthetic.php',
    );
    $includeProblems = performance_metric_problems(
        $includeOverLimit,
        $probeId,
        $limits,
    );
    audit_test_assert(
        count($includeProblems) === 1
            && str_contains($includeProblems[0], 'included_php_files'),
        'Exceeding the included-file ceiling must produce a clear failure problem.',
    );
    $memoryOverLimit = $metrics;
    $memoryOverLimit['peak_memory_bytes'] =
        $limits['max_peak_memory_bytes'] + 1;
    $memoryProblems = performance_metric_problems(
        $memoryOverLimit,
        $probeId,
        $limits,
    );
    audit_test_assert(
        count($memoryProblems) === 1
            && str_contains($memoryProblems[0], 'peak_memory_bytes'),
        'Exceeding the peak-memory ceiling must produce a clear failure problem.',
    );
    $malformedMetrics = $metrics;
    unset($malformedMetrics['included_paths']);
    audit_test_assert(
        performance_metric_problems($malformedMetrics, $probeId, $limits)
            !== [],
        'Malformed performance output must fail schema validation.',
    );
}

$previousBrowserRequired = getenv('PHP_GALLERY_BROWSER_REQUIRED');
$previousBrowser = getenv('PHP_GALLERY_BROWSER');
try {
    putenv('PHP_GALLERY_BROWSER_REQUIRED=1');
    putenv('PHP_GALLERY_BROWSER=disabled');
    $requiredBrowserAudit = \PhpGallery\Audit\run_process(
        [
            PHP_BINARY,
            $root . '/scripts/audit.php',
            '--suite=browser-map',
            '--no-report',
        ],
        $root,
        20,
    );
    $requiredBrowserCount = count(array_filter(
        $registry['node_tests'],
        static fn(array $definition): bool => !empty($definition['browser']),
    ));
    audit_test_assert(
        $requiredBrowserAudit['exit_code'] === 2
            && str_contains($requiredBrowserAudit['stdout'], 'Result: BLOCKED')
            && str_contains(
                $requiredBrowserAudit['stdout'],
                $requiredBrowserCount . ' blocked',
            )
            && !str_contains(
                $requiredBrowserAudit['stdout'],
                $requiredBrowserCount . ' skip',
            ),
        'Required browser CLI must exit 2 with every registered unavailable fixture BLOCKED, never silently SKIP.',
    );
    $requiredWebsiteAudit = \PhpGallery\Audit\run_process(
        [
            PHP_BINARY,
            $root . '/scripts/audit.php',
            '--suite=website',
            '--no-report',
        ],
        $root,
        20,
    );
    $requiredWebsiteCount = count($registry['website_tests']);
    audit_test_assert(
        $requiredWebsiteAudit['exit_code'] === 2
            && str_contains($requiredWebsiteAudit['stdout'], 'Result: BLOCKED')
            && str_contains(
                $requiredWebsiteAudit['stdout'],
                $requiredWebsiteCount . ' blocked',
            )
            && !str_contains(
                $requiredWebsiteAudit['stdout'],
                $requiredWebsiteCount . ' skip',
            ),
        'Website qualification must block unavailable Chromium rather than silently skipping its artifact fixtures.',
    );
} finally {
    putenv(
        $previousBrowserRequired === false
            ? 'PHP_GALLERY_BROWSER_REQUIRED'
            : 'PHP_GALLERY_BROWSER_REQUIRED=' . $previousBrowserRequired,
    );
    putenv(
        $previousBrowser === false
            ? 'PHP_GALLERY_BROWSER'
            : 'PHP_GALLERY_BROWSER=' . $previousBrowser,
    );
}

$previousWorkerCount = getenv('PHP_GALLERY_AUDIT_WORKERS');
try {
    putenv('PHP_GALLERY_AUDIT_WORKERS=0');
    foreach (['php-lint-changed', 'js-lint-changed', 'node-fast'] as $suiteId) {
        $invalidWorkerAudit = \PhpGallery\Audit\run_process(
            [
                PHP_BINARY,
                $root . '/scripts/audit.php',
                '--suite=' . $suiteId,
                '--no-report',
            ],
            $root,
            20,
        );
        audit_test_assert(
            $invalidWorkerAudit['exit_code'] === 2
                && str_contains(
                    $invalidWorkerAudit['stdout'],
                    'Result: BLOCKED',
                )
                && str_contains(
                    $invalidWorkerAudit['stdout'],
                    'PHP_GALLERY_AUDIT_WORKERS',
                ),
            'Syntax and Node CLI suites must block an invalid shared worker limit: '
                . $suiteId,
        );
    }
} finally {
    putenv(
        $previousWorkerCount === false
            ? 'PHP_GALLERY_AUDIT_WORKERS'
            : 'PHP_GALLERY_AUDIT_WORKERS=' . $previousWorkerCount,
    );
}

$releaseTask = \PhpGallery\Audit\task_result(
    'release-consistency',
    'Release consistency',
    'PASS',
    0.0,
    [],
    'Consistent.',
);
$identity = ['fingerprint' => str_repeat('a', 64)];
audit_test_assert(
    \PhpGallery\Audit\bind_release_source_identity(
        [$releaseTask],
        $identity,
        $identity,
    ) === [$releaseTask],
    'A frozen release tree keeps its original automated result.',
);
$changedTask = \PhpGallery\Audit\bind_release_source_identity(
    [$releaseTask],
    $identity,
    ['fingerprint' => str_repeat('b', 64)],
)[0];
audit_test_assert(
    $changedTask['status'] === 'FAIL'
        && $changedTask['details']['problems'] !== [],
    'A source change during auditing invalidates release consistency.',
);
$missingTask = \PhpGallery\Audit\bind_release_source_identity(
    [$releaseTask],
    null,
    $identity,
)[0];
audit_test_assert(
    $missingTask['status'] === 'BLOCKED',
    'Unavailable source identity must not qualify a release.',
);
$failedTask = array_replace($releaseTask, ['status' => 'FAIL']);
audit_test_assert(
    \PhpGallery\Audit\bind_release_source_identity(
        [$failedTask],
        null,
        null,
    )[0]['status'] === 'FAIL',
    'Source capture failure cannot downgrade an existing release failure.',
);

echo "Central audit runner contracts passed.\n";
