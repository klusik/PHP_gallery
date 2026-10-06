<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/source_contracts/debt_ratchet.php
 * Module Type: Source Contract Policy
 * Purpose:
 *   Apply deterministic historical category caps to already-computed source-contract reports.
 * @phpstan-type SourceDebtJsonValue null|bool|int|float|string|array<array-key, SourceDebtJsonValue>
 * Responsibilities:
 *   - Validate report and baseline schemas before classifying findings
 *   - Keep reliable category budgets separate from advisory heuristic counts
 *   - Permit only explicit decrease-only baseline refreshes
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file)
 */
declare(strict_types=1);

namespace PhpGallery\SourceDebt;

use InvalidArgumentException;
use RuntimeException;

/**
 * Classifier format version included in every baseline.
 * Type: positive integer. Units: schema revision. Scope: category key mapping.
 * Consumers: baseline creation, validation, evaluation, and decrease-only refresh.
 * Rationale: a classifier change must be explicitly reviewed before old budgets apply.
 */
const CLASSIFIER_VERSION = 1;

/**
 * Return the explicit rule and dimension classification used by the ratchet.
 *
 * @return array<string, mixed> Deterministic classifier definition for hash comparison.
 */
function classifier_definition(): array
{
    return [
        'version' => CLASSIFIER_VERSION,
        'families' => [
            'documentation' => [
                'rules' => [
                    'documentation.missing', 'documentation.summary',
                    'parameter.description', 'parameter.duplicate', 'parameter.extra',
                    'parameter.missing', 'parameter.pattern_review', 'parameter.type',
                    'parameter.type_mismatch', 'parameter.unparsed', 'return.description',
                    'return.missing', 'return.type_mismatch', 'shape.unspecified',
                    'header.author', 'header.file', 'header.module_type', 'header.project',
                    'header.purpose', 'header.repository', 'header.responsibilities',
                    'parameter.format', 'return.duplicate', 'return.format', 'return.type',
                    'typing.parameter_missing', 'typing.return_missing',
                ],
                'kinds' => ['bound_callback', 'callable', 'callback', 'class', 'enum', 'function', 'interface', 'property', 'trait', 'header'],
            ],
            'policy' => [
                'rules' => [
                    'constant.documentation', 'constant.duplicate_name_review',
                    'policy.css_duration_review', 'policy.named_numeric_assignment',
                    'policy.script_assignment_review', 'policy.timer_literal',
                ],
                'kinds' => ['none'],
            ],
        ],
        'scopes' => ['native-assets', 'other', 'runtime', 'tests', 'tooling'],
        'scope_prefixes' => [
            'tests/' => 'tests',
            'scripts/' => 'tooling',
            'winapp/' => 'native-assets',
            'app/' => 'runtime',
            'public/' => 'runtime',
        ],
        'scope_exact_paths' => ['index.php' => 'runtime'],
        'default_scope' => 'other',
        'extensions' => ['bat', 'cmd', 'cjs', 'css', 'htaccess', 'htm', 'html', 'js', 'mjs', 'php', 'ps1', 'psm1', 'py', 'pyw', 'sh', 'sql', 'svg', 'tex', 'yaml', 'yml'],
        'advisory_rules' => ['constant.duplicate_name_review', 'policy.css_duration_review', 'policy.script_assignment_review'],
        'detail_suffix_rules' => [
            'parameter.description', 'parameter.duplicate', 'parameter.extra',
            'parameter.missing', 'parameter.type', 'parameter.type_mismatch',
            'typing.parameter_missing',
        ],
        'category_fields' => ['family', 'scope', 'ext', 'kind', 'rulefamily'],
        'rule_suffix' => 'Aggregate only documented parameter-detail suffixes; reject every other suffix.',
    ];
}

/**
 * Hash the canonical classifier definition for baseline drift checks.
 *
 * @param array<string, mixed>|null $definition Optional classifier candidate used by focused validation fixtures.
 * @return string Lowercase SHA-256 digest of the stable classifier JSON.
 */
function classifier_fingerprint(?array $definition = null): string
{
    $json = json_encode($definition ?? classifier_definition(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return hash('sha256', $json);
}

/**
 * Derive an explicit repository ownership scope from a validated relative path.
 *
 * @param string $path Normalized repository-relative source path.
 * @return string Stable scope label used only to partition historical budgets.
 */
function source_scope(string $path): string
{
    $definition = classifier_definition();
    if (isset($definition['scope_exact_paths'][$path])) {
        return $definition['scope_exact_paths'][$path];
    }
    foreach ($definition['scope_prefixes'] as $prefix => $scope) {
        if (str_starts_with($path, $prefix)) {
            return $scope;
        }
    }
    return $definition['default_scope'];
}

/**
 * Validate and normalize one report into sorted category counts.
 *
 * @param string $family Report owner, exactly documentation or policy.
 * @param array<string, mixed> $report Already-decoded analyzer JSON report.
 * @return array<string, int> Sorted category keys mapped to finding counts.
 */
function normalize_report(string $family, array $report): array
{
    $definition = classifier_definition();
    if (!array_key_exists($family, $definition['families'])) {
        throw new InvalidArgumentException('Unknown source-contract report family.');
    }
    $reportKeys = $family === 'documentation'
        ? ['coverage', 'findings', 'inventory', 'summary']
        : ['coverage', 'definitions', 'findings', 'inventory', 'summary'];
    validate_exact_keys($report, $reportKeys, 'Source-contract report');
    $inventory = $report['inventory'];
    $summary = $report['summary'];
    $findings = $report['findings'];
    if (!is_array($inventory) || !is_array($summary) || !is_array($report['coverage'])
        || !is_array($findings) || !array_is_list($findings)) {
        throw new InvalidArgumentException('Source-contract report has malformed summary or findings.');
    }
    validate_report_shape($family, $report);
    $files = $inventory['files'];

    $ruleCounts = [];
    $categoryCounts = [];
    foreach ($findings as $finding) {
        if (!is_array($finding) || !isset($finding['path'], $finding['line'], $finding['name'], $finding['rule'])
            || !is_string($finding['path']) || !is_int($finding['line']) || $finding['line'] < 1
            || !is_string($finding['name']) || !is_string($finding['rule'])) {
            throw new InvalidArgumentException('Source-contract report contains a malformed finding.');
        }
        $path = $finding['path'];
        validate_source_path($path);
        if (!array_key_exists($path, $files)) {
            throw new InvalidArgumentException('Finding path is outside the report inventory.');
        }
        $rule = $finding['rule'];
        $ruleFamily = explode(':', $rule, 2)[0];
        if (!in_array($ruleFamily, $definition['families'][$family]['rules'], true)) {
            throw new InvalidArgumentException('Source-contract report contains an unknown rule family.');
        }
        $ruleParts = explode(':', $rule, 2);
        if (count($ruleParts) === 2) {
            if ($family !== 'documentation' || !in_array($ruleFamily, $definition['detail_suffix_rules'], true)
                || $ruleParts[1] === '' || str_contains($ruleParts[1], ':')) {
                throw new InvalidArgumentException('Source-contract rule has an unsupported detail suffix.');
            }
        }
        $ruleCounts[$ruleFamily] = ($ruleCounts[$ruleFamily] ?? 0) + 1;

        if ($family === 'documentation') {
            validate_exact_keys($finding, ['kind', 'line', 'name', 'path', 'rule'], 'Documentation finding');
            if (!isset($finding['kind']) || !is_string($finding['kind'])
                || !in_array($finding['kind'], $definition['families'][$family]['kinds'], true)) {
                throw new InvalidArgumentException('Documentation finding has an unknown declaration kind.');
            }
            $kind = $finding['kind'];
            if ($finding['name'] === '' && $kind !== 'header') {
                throw new InvalidArgumentException('Named documentation finding is missing its declaration identity.');
            }
            if (($kind === 'header') !== str_starts_with($ruleFamily, 'header.')) {
                throw new InvalidArgumentException('Header rule family and declaration kind do not agree.');
            }
        } else {
            $policyFindingKeys = $ruleFamily === 'constant.documentation'
                ? ['line', 'missing', 'name', 'path', 'rule']
                : ['line', 'name', 'path', 'rule'];
            validate_exact_keys($finding, $policyFindingKeys, 'Policy finding');
            if (isset($finding['missing']) && (!is_array($finding['missing']) || !array_is_list($finding['missing']))) {
                throw new InvalidArgumentException('Policy constant documentation details are malformed.');
            }
            foreach ($finding['missing'] ?? [] as $missingField) {
                if (!is_string($missingField) || $missingField === '') {
                    throw new InvalidArgumentException('Policy constant documentation detail is malformed.');
                }
            }
            if ($finding['name'] === '') {
                throw new InvalidArgumentException('Policy finding is missing its site identity.');
            }
            if (array_key_exists('kind', $finding)) {
                throw new InvalidArgumentException('Policy finding does not match its declared report shape.');
            }
            $kind = 'none';
        }
        $key = implode('|', [$family, source_scope($path), $files[$path], $kind, $ruleFamily]);
        $categoryCounts[$key] = ($categoryCounts[$key] ?? 0) + 1;
    }

    validate_rule_summary($summary['rules'], $ruleCounts);
    ksort($categoryCounts, SORT_STRING);
    return $categoryCounts;
}

/**
 * Require a report object to contain exactly its documented schema fields.
 *
 * @param array<string, mixed> $value Report or nested object to validate.
 * @param list<string> $required Exact allowed field names.
 * @param string $label Human-readable schema label for failures.
 * @return void Throws when required fields are missing or unexpected fields appear.
 */
function validate_exact_keys(array $value, array $required, string $label): void
{
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    sort($required, SORT_STRING);
    if ($actual !== $required) {
        throw new InvalidArgumentException($label . ' does not match its complete schema.');
    }
}

/**
 * Validate complete inventory and family-specific analyzer report accounting.
 *
 * @param string $family Report owner, exactly documentation or policy.
 * @param array<string, mixed> $report Complete analyzer report.
 * @return void Throws when any inventory, summary, coverage, or definition count is incomplete.
 */
function validate_report_shape(string $family, array $report): void
{
    $inventory = $report['inventory'];
    $summary = $report['summary'];
    $findings = $report['findings'];
    $inventoryKeys = ['excluded', 'files', 'other_formats', 'provenance'];
    validate_exact_keys($inventory, $inventoryKeys, 'Source inventory');
    $summaryKeys = $family === 'documentation'
        ? ['declarations', 'files_with_findings', 'finding_count', 'languages', 'rules', 'source_files']
        : ['definitions', 'finding_count', 'languages', 'rules', 'source_files'];
    validate_exact_keys($summary, $summaryKeys, 'Source report summary');
    $coverageKeys = $family === 'documentation'
        ? ['headers', 'javascript', 'manual_declaration_languages', 'manual_semantics', 'other_formats', 'php', 'python', 'scripts', 'typing']
        : ['css', 'ownership', 'php_js', 'scripts', 'semantics'];
    validate_exact_keys($report['coverage'], $coverageKeys, 'Source report coverage');
    foreach ($report['coverage'] as $coverageKey => $description) {
        if (!is_string($description) || trim($description) === '') {
            throw new InvalidArgumentException('Source report coverage description is empty.');
        }
    }

    $files = $inventory['files'];
    if (!is_array($files) || !is_array($inventory['other_formats']) || !is_array($inventory['excluded'])
        || !is_array($inventory['provenance']) || !is_array($summary['languages']) || !is_array($summary['rules'])
        || !is_int($summary['source_files']) || $summary['source_files'] < 0
        || !is_int($summary['finding_count']) || $summary['finding_count'] !== count($findings)) {
        throw new InvalidArgumentException('Source report has malformed inventory or count fields.');
    }
    if ($summary['source_files'] !== count($files)) {
        throw new InvalidArgumentException('Source report source count does not match its inventory.');
    }

    $actualLanguages = [];
    foreach ($files as $path => $extension) {
        validate_source_path($path);
        if (!is_string($extension) || !in_array($extension, classifier_definition()['extensions'], true)
            || $extension !== source_extension($path)) {
            throw new InvalidArgumentException('Source inventory contains an unknown or mismatched extension.');
        }
        $actualLanguages[$extension] = ($actualLanguages[$extension] ?? 0) + 1;
    }
    ksort($actualLanguages, SORT_STRING);
    $languages = $summary['languages'];
    validate_nonnegative_count_map($languages, 'Source report language counts');
    ksort($languages, SORT_STRING);
    if ($languages !== $actualLanguages) {
        throw new InvalidArgumentException('Source report language totals do not match its inventory.');
    }
    validate_inventory_side_maps($inventory);

    if ($family === 'documentation') {
        $declarations = $summary['declarations'];
        if (!is_array($declarations)) {
            throw new InvalidArgumentException('Documentation declaration totals are malformed.');
        }
        validate_nonnegative_count_map($declarations, 'Documentation declaration totals');
        foreach ($declarations as $declaration => $_count) {
            $parts = explode('.', $declaration, 2);
            if (count($parts) !== 2
                || !in_array($parts[0], classifier_definition()['extensions'], true)
                || !in_array($parts[1], classifier_definition()['families']['documentation']['kinds'], true)
                || $parts[1] === 'header') {
                throw new InvalidArgumentException('Documentation declaration summary contains an unknown kind.');
            }
        }
        if (!is_int($summary['files_with_findings']) || $summary['files_with_findings'] < 0
            || $summary['files_with_findings'] > $summary['source_files']) {
            throw new InvalidArgumentException('Documentation finding-file count is malformed.');
        }
        $findingPaths = [];
        foreach ($findings as $finding) {
            if (is_array($finding) && is_string($finding['path'] ?? null)) {
                $findingPaths[$finding['path']] = true;
            }
        }
        if ($summary['files_with_findings'] !== count($findingPaths)) {
            throw new InvalidArgumentException('Documentation finding-file count does not match findings.');
        }
    } else {
        if (!is_array($report['definitions']) || !array_is_list($report['definitions'])
            || !is_int($summary['definitions']) || $summary['definitions'] !== count($report['definitions'])) {
            throw new InvalidArgumentException('Policy definition count does not match its complete definition list.');
        }
        foreach ($report['definitions'] as $record) {
            if (!is_array($record)) {
                throw new InvalidArgumentException('Policy definition record is malformed.');
            }
            validate_exact_keys($record, ['lexical_consumers', 'line', 'missing_documentation', 'name', 'path'],
                'Policy definition');
            if (!is_string($record['path']) || !isset($files[$record['path']])
                || !is_int($record['line']) || $record['line'] < 1
                || !is_string($record['name']) || $record['name'] === ''
                || !is_array($record['missing_documentation']) || !array_is_list($record['missing_documentation'])
                || !is_array($record['lexical_consumers']) || !array_is_list($record['lexical_consumers'])) {
                throw new InvalidArgumentException('Policy definition record fields are malformed.');
            }
            foreach (array_merge($record['missing_documentation'], $record['lexical_consumers']) as $item) {
                if (!is_string($item)) {
                    throw new InvalidArgumentException('Policy definition detail is malformed.');
                }
            }
            foreach ($record['lexical_consumers'] as $consumerPath) {
                validate_source_path($consumerPath);
                if (!isset($files[$consumerPath])) {
                    throw new InvalidArgumentException('Policy definition consumer is outside the inventory.');
                }
            }
        }
    }
}

/**
 * Validate a string-keyed map of nonnegative integer totals.
 *
 * @param array<string, mixed> $counts Candidate category or language totals.
 * @param string $label Field description for a validation failure.
 * @return void Throws when keys or counts are malformed.
 */
function validate_nonnegative_count_map(array $counts, string $label): void
{
    foreach ($counts as $key => $count) {
        if (!is_string($key) || $key === '' || !is_int($count) || $count < 0) {
            throw new InvalidArgumentException($label . ' contain a malformed key or count.');
        }
    }
}

/**
 * Validate inventory metadata that is not part of the ratcheted source file set.
 *
 * @param array<string, mixed> $inventory Complete analyzer inventory.
 * @return void Throws for malformed exclusion, format, or provenance accounting.
 */
function validate_inventory_side_maps(array $inventory): void
{
    validate_nonnegative_count_map($inventory['other_formats'], 'Other-format inventory');
    foreach ($inventory['excluded'] as $path => $reason) {
        validate_inventory_path($path);
        if (!is_string($reason) || trim($reason) === '') {
            throw new InvalidArgumentException('Excluded inventory entry has no reason.');
        }
    }
    foreach ($inventory['provenance'] as $path => $details) {
        validate_inventory_path($path);
        if (!is_array($details)) {
            throw new InvalidArgumentException('Inventory provenance record is malformed.');
        }
        validate_exact_keys($details, ['license', 'origin'], 'Inventory provenance');
        if (!is_string($details['origin']) || trim($details['origin']) === ''
            || !is_string($details['license']) || trim($details['license']) === '') {
            throw new InvalidArgumentException('Inventory provenance details are empty.');
        }
    }
}

/**
 * Validate a relative inventory path that may name an excluded directory.
 *
 * @param SourceDebtJsonValue $path File or excluded-directory path from decoded report metadata.
 * @return void Throws when the path is unsafe or not normalized.
 */
function validate_inventory_path(mixed $path): void
{
    if (!is_string($path)) {
        throw new InvalidArgumentException('Inventory metadata path must be a string.');
    }
    $normalized = str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
    validate_source_path($normalized);
}

/**
 * Validate a normalized report path before it can affect scope or inventory checks.
 *
 * @param SourceDebtJsonValue $path Candidate path from the decoded report.
 * @return void Throws when the value is not a safe normalized relative path.
 */
function validate_source_path(mixed $path): void
{
    if (!is_string($path) || $path === '' || str_contains($path, '\\') || str_starts_with($path, '/')
        || preg_match('/^[A-Za-z]:/', $path) === 1 || str_contains($path, '//')) {
        throw new InvalidArgumentException('Source path is not normalized and repository-relative.');
    }
    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new InvalidArgumentException('Source path contains an invalid path segment.');
        }
    }
}

/**
 * Resolve the explicit inventory extension for a validated relative source path.
 *
 * @param string $path Repository-relative source path already checked for traversal.
 * @return string Lowercase extension label, including the repository's htaccess special case.
 */
function source_extension(string $path): string
{
    if (basename($path) === '.htaccess') {
        return 'htaccess';
    }
    return strtolower(pathinfo($path, PATHINFO_EXTENSION));
}

/**
 * Confirm rule totals exactly match the list of findings.
 *
 * @param array<string, mixed> $reported Analyzer summary rule counts.
 * @param array<string, int> $actual Counts accumulated from findings.
 * @return void Throws when rules are missing, extra, or inaccurately counted.
 */
function validate_rule_summary(array $reported, array $actual): void
{
    foreach ($reported as $rule => $count) {
        if (!is_string($rule) || !is_int($count) || $count < 0) {
            throw new InvalidArgumentException('Source-contract report has malformed rule counts.');
        }
    }
    ksort($reported, SORT_STRING);
    ksort($actual, SORT_STRING);
    if ($reported !== $actual) {
        throw new InvalidArgumentException('Source-contract report rule counts do not match findings.');
    }
}

/**
 * Normalize both existing analyzer reports into one sorted count map.
 *
 * @param array<string, array<string, mixed>> $reports Reports keyed by documentation and policy.
 * @return array<string, int> Sorted category counts across both report families.
 */
function normalize_reports(array $reports): array
{
    if (array_keys($reports) !== ['documentation', 'policy']) {
        $keys = array_keys($reports);
        sort($keys, SORT_STRING);
        if ($keys !== ['documentation', 'policy']) {
            throw new InvalidArgumentException('Both documentation and policy reports are required exactly once.');
        }
    }
    $counts = [];
    foreach (['documentation', 'policy'] as $family) {
        foreach (normalize_report($family, $reports[$family]) as $key => $count) {
            $counts[$key] = $count;
        }
    }
    ksort($counts, SORT_STRING);
    return $counts;
}

/**
 * Create the explicit first baseline from complete validated source reports.
 *
 * @param array<string, array<string, mixed>> $reports Complete current reports from both analyzers.
 * @param string $initialBaseSha Immutable Git base used for the initial review.
 * @param string $checkpointSha Full Git SHA of the current reviewed checkpoint.
 * @return array<string, mixed> Baseline with independent observed counts and reliable caps.
 */
function create_initial_baseline(array $reports, string $initialBaseSha, string $checkpointSha): array
{
    if (preg_match('/^[0-9a-f]{40,64}$/i', $initialBaseSha) !== 1
        || preg_match('/^[0-9a-f]{40,64}$/i', $checkpointSha) !== 1) {
        throw new InvalidArgumentException('Initial baseline provenance must name both reviewed commits.');
    }
    $counts = normalize_reports($reports);
    $caps = [];
    foreach ($counts as $category => $count) {
        if (!category_is_advisory($category)) {
            $caps[$category] = $count;
        }
    }
    return [
        'schema_version' => 1,
        'classifier_version' => CLASSIFIER_VERSION,
        'classifier_sha256' => classifier_fingerprint(),
        'provenance' => ['initial_base_sha' => strtolower($initialBaseSha), 'checkpoint_sha' => strtolower($checkpointSha)],
        'counts' => $counts,
        'caps' => $caps,
    ];
}

/**
 * Compare complete current reports with the historical reliable caps.
 *
 * @param array<string, mixed> $baseline Previously approved category counts and caps.
 * @param array<string, array<string, mixed>> $reports Complete current analyzer reports.
 * @return array<string, mixed> Deterministic counts, advisory deltas, caps, and violations.
 */
function evaluate(array $baseline, array $reports): array
{
    validate_baseline($baseline);
    $current = normalize_reports($reports);
    $allKeys = array_unique(array_merge(array_keys($baseline['counts']), array_keys($current)));
    sort($allKeys, SORT_STRING);
    $caps = $baseline['caps'];
    ksort($caps, SORT_STRING);
    $counts = [];
    $violations = [];
    $advisory = [];
    foreach ($allKeys as $category) {
        $count = $current[$category] ?? 0;
        $counts[$category] = $count;
        if (category_is_advisory($category)) {
            $advisory[$category] = [
                'previous' => $baseline['counts'][$category] ?? 0,
                'current' => $count,
                'delta' => $count - ($baseline['counts'][$category] ?? 0),
            ];
            continue;
        }
        $cap = $caps[$category] ?? 0;
        if ($count > $cap) {
            $violations[$category] = ['cap' => $cap, 'current' => $count, 'increase' => $count - $cap];
        }
    }
    return [
        'status' => $violations === [] ? 'PASS' : 'FAIL',
        'counts' => $counts,
        'caps' => $caps,
        'advisory' => $advisory,
        'violations' => $violations,
    ];
}

/**
 * Lower historical reliable caps only when every current category stays within them.
 *
 * @param array<string, mixed> $baseline Existing baseline that must pass validation.
 * @param array<string, array<string, mixed>> $reports Complete current analyzer reports.
 * @return array<string, mixed> Decreased baseline preserving its original provenance.
 */
function refresh_decrease_only(array $baseline, array $reports): array
{
    $evaluation = evaluate($baseline, $reports);
    if ($evaluation['status'] !== 'PASS') {
        throw new RuntimeException('Decrease-only refresh refused because reliable debt exceeds a cap.');
    }
    $counts = $evaluation['counts'];
    $caps = $baseline['caps'];
    $categories = array_unique(array_merge(array_keys($baseline['counts']), array_keys($counts)));
    sort($categories, SORT_STRING);
    foreach ($categories as $category) {
        $count = $counts[$category] ?? 0;
        if (category_is_advisory($category)) {
            continue;
        }
        $caps[$category] = min($caps[$category] ?? 0, $count);
    }
    ksort($counts, SORT_STRING);
    ksort($caps, SORT_STRING);
    $baseline['counts'] = $counts;
    $baseline['caps'] = $caps;
    validate_baseline($baseline);
    return $baseline;
}

/**
 * Validate baseline schema, category identities, provenance, and classifier identity.
 *
 * @param array<string, mixed> $baseline Candidate baseline data.
 * @return void Throws rather than resetting or repairing malformed historical state.
 */
function validate_baseline(array $baseline): void
{
    $required = ['schema_version', 'classifier_version', 'classifier_sha256', 'provenance', 'counts', 'caps'];
    $keys = array_keys($baseline);
    sort($keys, SORT_STRING);
    $expected = $required;
    sort($expected, SORT_STRING);
    if ($keys !== $expected || ($baseline['schema_version'] ?? null) !== 1
        || ($baseline['classifier_version'] ?? null) !== CLASSIFIER_VERSION
        || ($baseline['classifier_sha256'] ?? null) !== classifier_fingerprint()) {
        throw new InvalidArgumentException('Baseline schema or category classifier has drifted.');
    }
    $provenanceKeys = is_array($baseline['provenance']) ? array_keys($baseline['provenance']) : [];
    sort($provenanceKeys, SORT_STRING);
    if (!is_array($baseline['provenance']) || $provenanceKeys !== ['checkpoint_sha', 'initial_base_sha']
        || !is_string($baseline['provenance']['initial_base_sha'])
        || preg_match('/^[0-9a-f]{40,64}$/', $baseline['provenance']['initial_base_sha']) !== 1
        || !is_string($baseline['provenance']['checkpoint_sha'])
        || preg_match('/^[0-9a-f]{40,64}$/', $baseline['provenance']['checkpoint_sha']) !== 1) {
        throw new InvalidArgumentException('Baseline provenance is malformed.');
    }
    foreach (['counts', 'caps'] as $field) {
        if (!is_array($baseline[$field])) {
            throw new InvalidArgumentException('Baseline category map is malformed.');
        }
        foreach ($baseline[$field] as $category => $value) {
            validate_category_key($category);
            if (!is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Baseline category count or cap is malformed.');
            }
            if ($field === 'caps' && category_is_advisory($category)) {
                throw new InvalidArgumentException('Advisory categories cannot have blocking caps.');
            }
        }
    }
    foreach ($baseline['counts'] as $category => $count) {
        if (!category_is_advisory($category) && !array_key_exists($category, $baseline['caps'])) {
            throw new InvalidArgumentException('Every reliable historical count requires a separate cap.');
        }
    }
    foreach ($baseline['caps'] as $category => $cap) {
        if (!array_key_exists($category, $baseline['counts']) || $cap > $baseline['counts'][$category]) {
            throw new InvalidArgumentException('A reliable cap must not exceed its recorded historical count.');
        }
    }
}

/**
 * Validate one serialized category identity against the versioned classifier.
 *
 * @param SourceDebtJsonValue $category Serialized category value from the decoded baseline.
 * @return void Throws when dimensions or rule family are unknown.
 */
function validate_category_key(mixed $category): void
{
    if (!is_string($category)) {
        throw new InvalidArgumentException('Category key must be a string.');
    }
    $parts = explode('|', $category);
    if (count($parts) !== 5 || in_array('', $parts, true)) {
        throw new InvalidArgumentException('Category key does not have five stable dimensions.');
    }
    [$family, $scope, $extension, $kind, $rule] = $parts;
    $definition = classifier_definition();
    if (!isset($definition['families'][$family])
        || !in_array($scope, $definition['scopes'], true)
        || !in_array($extension, $definition['extensions'], true)
        || !in_array($kind, $definition['families'][$family]['kinds'], true)
        || !in_array($rule, $definition['families'][$family]['rules'], true)) {
        throw new InvalidArgumentException('Category key contains an unknown classifier value.');
    }
}

/**
 * Identify the only explicitly advisory policy heuristics.
 *
 * @param string $category Valid five-part category key.
 * @return bool True only for the three reviewed noisy policy rule families.
 */
function category_is_advisory(string $category): bool
{
    validate_category_key($category);
    $parts = explode('|', $category);
    return $parts[0] === 'policy'
        && in_array($parts[4], classifier_definition()['advisory_rules'], true);
}
