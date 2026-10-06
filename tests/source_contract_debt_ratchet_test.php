<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/source_contract_debt_ratchet_test.php
 * Module Type: Source Contract Regression Fixture
 * Purpose:
 *   Verify category-count ratchet classification, growth limits, and fail-closed validation.
 * Responsibilities:
 *   - Exercise advisory and reliable category behavior across scopes
 *   - Reject malformed reports and unsafe baseline refreshes
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/source_contracts/debt_ratchet.php';

use function PhpGallery\SourceDebt\category_is_advisory;
use function PhpGallery\SourceDebt\classifier_definition;
use function PhpGallery\SourceDebt\classifier_fingerprint;
use function PhpGallery\SourceDebt\create_initial_baseline;
use function PhpGallery\SourceDebt\evaluate;
use function PhpGallery\SourceDebt\normalize_report;
use function PhpGallery\SourceDebt\normalize_reports;
use function PhpGallery\SourceDebt\refresh_decrease_only;
use function PhpGallery\SourceDebt\validate_baseline;

/**
 * Build a minimal report with counts derived from its findings.
 *
 * @param string $family Source analyzer family used to select its finding shape.
 * @param array<int, array<string, mixed>> $findings Findings to include in the report.
 * @return array<string, mixed> Complete report fixture accepted by the ratchet schema.
 */
function debt_test_report(string $family, array $findings): array
{
    $files = [];
    $rules = [];
    $languages = [];
    $declarations = [];
    $findingPaths = [];
    $definitions = [];
    foreach ($findings as $finding) {
        $files[$finding['path']] = strtolower(pathinfo($finding['path'], PATHINFO_EXTENSION)) ?: 'htaccess';
        $ruleFamily = explode(':', $finding['rule'], 2)[0];
        $rules[$ruleFamily] = ($rules[$ruleFamily] ?? 0) + 1;
        $findingPaths[$finding['path']] = true;
        if (isset($finding['kind']) && $finding['kind'] !== 'header') {
            $extension = strtolower(pathinfo($finding['path'], PATHINFO_EXTENSION));
            $declarations[$extension . '.' . $finding['kind']] = 1;
        }
        if (str_starts_with($finding['rule'], 'constant.')) {
            $definitionKey = $finding['path'] . '|' . $finding['name'];
            $definitions[$definitionKey] = [
                'path' => $finding['path'],
                'line' => $finding['line'],
                'name' => $finding['name'],
                'missing_documentation' => $finding['rule'] === 'constant.documentation' ? ['type'] : [],
                'lexical_consumers' => [],
            ];
        }
    }
    foreach ($files as $extension) {
        $languages[$extension] = ($languages[$extension] ?? 0) + 1;
    }
    ksort($files, SORT_STRING);
    ksort($rules, SORT_STRING);
    ksort($languages, SORT_STRING);
    ksort($declarations, SORT_STRING);
    $inventory = ['files' => $files, 'other_formats' => [], 'excluded' => [], 'provenance' => []];
    $summary = [
        'source_files' => count($files),
        'languages' => $languages,
        'finding_count' => count($findings),
        'rules' => $rules,
    ];
    if ($family === 'documentation') {
        $summary['declarations'] = $declarations;
        $summary['files_with_findings'] = count($findingPaths);
        $coverage = [
            'headers' => 'Native leading file metadata.',
            'php' => 'Named PHP declaration documentation.',
            'javascript' => 'Named JavaScript declaration documentation.',
            'python' => 'Python docstring and annotation coverage.',
            'typing' => 'Native parameter and return annotations.',
            'scripts' => 'Changed-source script function coverage.',
            'manual_declaration_languages' => 'Manual declaration interpretation coverage.',
            'manual_semantics' => 'Semantic documentation review coverage.',
            'other_formats' => 'Other source format accounting.',
        ];
        return ['inventory' => $inventory, 'summary' => $summary, 'findings' => $findings, 'coverage' => $coverage];
    }
    $summary['definitions'] = count($definitions);
    $coverage = [
        'php_js' => 'Named constants and numeric policy candidates.',
        'css' => 'Visual duration review candidates.',
        'scripts' => 'Script assignment review candidates.',
        'ownership' => 'Lexical ownership candidates.',
        'semantics' => 'Semantic interpretation limits.',
    ];
    return ['inventory' => $inventory, 'summary' => $summary, 'definitions' => array_values($definitions),
        'findings' => $findings, 'coverage' => $coverage];
}

/**
 * Return a documentation finding with a stable named-declaration shape.
 *
 * @param string $path Repository-relative source path.
 * @param string $suffix Parameter-specific rule suffix proving family aggregation.
 * @return array<string, mixed> Valid documentation analyzer finding.
 */
function debt_test_doc_finding(string $path, string $suffix): array
{
    return ['path' => $path, 'line' => 8, 'kind' => 'callable', 'name' => 'sample', 'rule' => 'parameter.missing:' . $suffix];
}

/**
 * Return an advisory policy finding for delta-display assertions.
 *
 * @param string $path Repository-relative policy source path.
 * @param string $rule Stable policy rule family emitted by the analyzer.
 * @return array<string, mixed> Valid policy analyzer finding.
 */
function debt_test_policy_finding(string $path, string $rule = 'policy.script_assignment_review'): array
{
    $finding = ['path' => $path, 'line' => 12, 'name' => '$timeout', 'rule' => $rule];
    if ($rule === 'constant.documentation') {
        $finding['missing'] = ['type'];
    }
    return $finding;
}

/**
 * Assert an operation throws the requested exception type.
 *
 * @param callable(): mixed $operation Operation expected to reject its input.
 * @param class-string<Throwable> $exceptionClass Expected exception type.
 * @return void Fails the fixture when the operation accepts invalid input.
 */
function debt_test_assert_throws(callable $operation, string $exceptionClass): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        if ($error instanceof $exceptionClass) {
            return;
        }
        throw $error;
    }
    throw new RuntimeException('Expected ' . $exceptionClass . ' was not thrown.');
}

/**
 * Run the pure ratchet scenarios and print a concise result.
 *
 * @return void Exits nonzero by exception when an invariant fails.
 */
function run_source_contract_debt_ratchet_tests(): void
{
    $documentation = debt_test_report('documentation', [
        debt_test_doc_finding('app/services/example.php', 'first'),
        debt_test_doc_finding('app/services/example.php', 'second'),
        debt_test_doc_finding('tests/example.php', 'third'),
    ]);
    $policy = debt_test_report('policy', [
        debt_test_policy_finding('scripts/review.py'),
        debt_test_policy_finding('custom_css/review.css', 'policy.css_duration_review'),
        debt_test_policy_finding('app/policy.php', 'constant.duplicate_name_review'),
        debt_test_policy_finding('app/constants.php', 'constant.documentation'),
        debt_test_policy_finding('app/numeric_policy.php', 'policy.named_numeric_assignment'),
        debt_test_policy_finding('app/timer_policy.php', 'policy.timer_literal'),
    ]);
    $reports = ['documentation' => $documentation, 'policy' => $policy];
    $counts = normalize_reports($reports);
    debt_test_assert(count(array_filter(array_keys($counts), static fn(string $key): bool => str_contains($key, '|parameter.missing'))) === 2,
        'parameter-specific rule details aggregate by category while scope remains distinct');
    debt_test_assert(array_keys($counts) === array_keys(normalize_reports(['policy' => $policy, 'documentation' => $documentation])),
        'report family input order does not affect sorted category output');
    $headerReport = debt_test_report('documentation', [[
        'path' => 'app/header.php', 'line' => 1, 'kind' => 'header', 'name' => '', 'rule' => 'header.project',
    ]]);
    debt_test_assert(count(normalize_reports(['documentation' => $headerReport, 'policy' => debt_test_report('policy', [])])) === 1,
        'native-header rules preserve their intentionally empty declaration name');

    $baseSha = str_repeat('a', 40);
    $baseline = create_initial_baseline($reports, $baseSha, str_repeat('b', 40));
    validate_baseline($baseline);
    $advisoryKeys = array_values(array_filter(array_keys($counts), static fn(string $key): bool => category_is_advisory($key)));
    debt_test_assert(count($advisoryKeys) === 3, 'all and only the three reviewed policy heuristics are advisory');
    $capsBefore = $baseline['caps'];
    $changed = $reports;
    $changed['policy'] = debt_test_report('policy', [
        debt_test_policy_finding('scripts/review.py'),
        debt_test_policy_finding('scripts/review.py'),
        debt_test_policy_finding('custom_css/review.css', 'policy.css_duration_review'),
        debt_test_policy_finding('custom_css/review.css', 'policy.css_duration_review'),
        debt_test_policy_finding('app/policy.php', 'constant.duplicate_name_review'),
        debt_test_policy_finding('app/policy.php', 'constant.duplicate_name_review'),
        debt_test_policy_finding('app/constants.php', 'constant.documentation'),
        debt_test_policy_finding('app/numeric_policy.php', 'policy.named_numeric_assignment'),
        debt_test_policy_finding('app/timer_policy.php', 'policy.timer_literal'),
    ]);
    $advisoryEvaluation = evaluate($baseline, $changed);
    debt_test_assert($advisoryEvaluation['status'] === 'PASS', 'all three advisory policy heuristics can grow without blocking');
    foreach ($advisoryKeys as $advisoryKey) {
        debt_test_assert($advisoryEvaluation['advisory'][$advisoryKey]['delta'] === 1,
            'each advisory policy increase remains visible as a delta');
    }
    foreach (['constant.documentation', 'policy.named_numeric_assignment', 'policy.timer_literal'] as $reliableRule) {
        $category = 'policy|runtime|php|none|' . $reliableRule;
        debt_test_assert(isset($baseline['caps'][$category]), 'reliable policy categories retain blocking caps');
    }
    $movedScope = $reports;
    $movedScope['policy'] = debt_test_report('policy', [
        debt_test_policy_finding('scripts/review.py'),
        debt_test_policy_finding('custom_css/review.css', 'policy.css_duration_review'),
        debt_test_policy_finding('app/policy.php', 'constant.duplicate_name_review'),
        debt_test_policy_finding('scripts/constants.php', 'constant.documentation'),
        debt_test_policy_finding('app/numeric_policy.php', 'policy.named_numeric_assignment'),
        debt_test_policy_finding('app/timer_policy.php', 'policy.timer_literal'),
    ]);
    $scopeEvaluation = evaluate($baseline, $movedScope);
    debt_test_assert($scopeEvaluation['status'] === 'FAIL'
        && ($scopeEvaluation['violations']['policy|tooling|php|none|constant.documentation']['cap'] ?? null) === 0,
        'moving reliable debt from runtime to tooling cannot escape its zero-budget category');

    $reliable = $reports;
    $reliable['documentation'] = debt_test_report('documentation', array_merge($documentation['findings'], [
        debt_test_doc_finding('app/services/example.php', 'fourth'),
    ]));
    debt_test_assert(evaluate($baseline, $reliable)['status'] === 'FAIL', 'one reliable finding above its category cap fails');
    debt_test_assert_throws(static fn(): array => refresh_decrease_only($baseline, $reliable), RuntimeException::class);

    $decreased = $reports;
    $decreased['documentation'] = debt_test_report('documentation', [
        debt_test_doc_finding('app/services/example.php', 'first'),
        debt_test_doc_finding('tests/example.php', 'third'),
    ]);
    $lowered = refresh_decrease_only($baseline, $decreased);
    foreach ($capsBefore as $category => $cap) {
        debt_test_assert(($lowered['caps'][$category] ?? 0) <= $cap, 'refresh never raises an existing cap');
    }
    debt_test_assert(array_key_exists('documentation|runtime|php|callable|parameter.missing', $lowered['caps']),
        'a disappeared known category remains in the baseline with a zero cap');

    $newCategory = $reports;
    $newCategory['documentation'] = debt_test_report('documentation', array_merge($documentation['findings'], [[
        'path' => 'app/services/new.php', 'line' => 1, 'kind' => 'callable', 'name' => 'new', 'rule' => 'return.missing',
    ]]));
    $newEvaluation = evaluate($baseline, $newCategory);
    debt_test_assert($newEvaluation['status'] === 'FAIL', 'a new reliable category starts with a zero budget');
    debt_test_assert(($newEvaluation['violations']['documentation|runtime|php|callable|return.missing']['cap'] ?? null) === 0,
        'new category failure reports its implicit zero cap');

    $wrongFindingCount = $documentation;
    $wrongFindingCount['summary']['finding_count']++;
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $wrongFindingCount, 'policy' => $policy]), InvalidArgumentException::class);
    $wrongSourceCount = $documentation;
    $wrongSourceCount['summary']['source_files']++;
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $wrongSourceCount, 'policy' => $policy]), InvalidArgumentException::class);
    $wrongRuleCount = $documentation;
    $wrongRuleCount['summary']['rules']['parameter.missing']++;
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $wrongRuleCount, 'policy' => $policy]), InvalidArgumentException::class);
    $missingInventory = $documentation;
    unset($missingInventory['inventory']);
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $missingInventory, 'policy' => $policy]), InvalidArgumentException::class);
    $missingSummary = $documentation;
    unset($missingSummary['summary']);
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $missingSummary, 'policy' => $policy]), InvalidArgumentException::class);
    $extraReportField = $documentation;
    $extraReportField['unrecognized'] = [];
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $extraReportField, 'policy' => $policy]), InvalidArgumentException::class);
    $outsideInventory = $documentation;
    $outsideInventory['findings'][0]['path'] = 'app/outside.php';
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $outsideInventory, 'policy' => $policy]), InvalidArgumentException::class);
    $unsafePath = $documentation;
    $unsafePath['findings'][0]['path'] = '../outside.php';
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $unsafePath, 'policy' => $policy]), InvalidArgumentException::class);
    $wrongExtension = $documentation;
    $wrongExtension['inventory']['files']['app/services/example.php'] = 'js';
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $wrongExtension, 'policy' => $policy]), InvalidArgumentException::class);
    $unknownKind = $documentation;
    $unknownKind['findings'][0]['kind'] = 'method';
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $unknownKind, 'policy' => $policy]), InvalidArgumentException::class);
    debt_test_assert_throws(static fn(): array => normalize_report('unknown', $documentation), InvalidArgumentException::class);
    $unknownRule = $policy;
    $unknownRule['findings'][0]['rule'] = 'policy.new_heuristic';
    $unknownRule['summary']['rules'] = ['policy.new_heuristic' => 1];
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $documentation, 'policy' => $unknownRule]), InvalidArgumentException::class);
    $policySuffix = $policy;
    $policySuffix['findings'][0]['rule'] .= ':unreviewed';
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $documentation, 'policy' => $policySuffix]), InvalidArgumentException::class);
    $wrongDefinitionCount = $policy;
    $wrongDefinitionCount['summary']['definitions']++;
    debt_test_assert_throws(static fn(): array => normalize_reports(['documentation' => $documentation, 'policy' => $wrongDefinitionCount]), InvalidArgumentException::class);
    debt_test_assert_throws(static fn(): array => evaluate([], $reports), InvalidArgumentException::class);
    $missingCap = $baseline;
    unset($missingCap['caps']['documentation|runtime|php|callable|parameter.missing']);
    debt_test_assert_throws(static fn(): array => evaluate($missingCap, $reports), InvalidArgumentException::class);
    $overCap = $baseline;
    $overCap['caps']['documentation|runtime|php|callable|parameter.missing']++;
    debt_test_assert_throws(static fn(): array => evaluate($overCap, $reports), InvalidArgumentException::class);
    $drifted = $baseline;
    $drifted['classifier_sha256'] = str_repeat('0', 64);
    debt_test_assert_throws(static fn(): array => evaluate($drifted, $reports), InvalidArgumentException::class);
    $changedClassifier = classifier_definition();
    $changedClassifier['scope_prefixes']['scripts/'] = 'tests';
    debt_test_assert(classifier_fingerprint($changedClassifier) !== $baseline['classifier_sha256'],
        'semantic scope mapping changes alter the classifier fingerprint');

    fwrite(STDOUT, "Source-contract debt ratchet: PASS\n");
}

/**
 * Stop the fixture immediately when a ratchet contract is not met.
 *
 * @param bool $condition Assertion result.
 * @param string $message Short failure explanation.
 * @return void Throws a runtime exception when the assertion is false.
 */
function debt_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

run_source_contract_debt_ratchet_tests();
