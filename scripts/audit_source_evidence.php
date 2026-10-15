<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/audit_source_evidence.php
 * Module Type: Audit Source Evidence
 * Purpose: Identify audited source and render complete failed source-contract evidence.
 * Responsibilities:
 *   - Resolve the comparison base once without executing inspected source.
 *   - Reuse existing value-free findings and category-budget reports.
 *   - Keep passing suites and unrelated child logs out of failure evidence.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\Audit;

require_once __DIR__ . '/audit_lib.php';
require_once __DIR__ . '/source_contracts/debt_ratchet.php';

/**
 * Resolve source HEAD, dirty state and the immutable requested comparison base.
 * @param string $root Checkout root used by the audit.
 * @param string|null $git Available Git executable, or null when unavailable.
 * @param string $base Requested comparison ref, normally the branch merge-base SHA.
 * @return array{head_sha:?string,comparison_base:?string,requested_base:string,worktree_dirty:?bool} Observed identity; null fields mean unavailable evidence.
 */
function audit_source_identity(string $root, ?string $git, string $base): array
{
    $identity = ['head_sha' => null, 'comparison_base' => null,
        'requested_base' => $base, 'worktree_dirty' => null];
    if ($git === null) {
        return $identity;
    }
    $checkout = run_process([$git, 'rev-parse', '--show-toplevel'], $root, 10);
    if ($checkout['exit_code'] !== 0
        || strcasecmp(str_replace('\\', '/', trim($checkout['stdout'])), str_replace('\\', '/', realpath($root) ?: $root)) !== 0) {
        return $identity;
    }
    foreach (['head_sha' => 'HEAD', 'comparison_base' => $base] as $key => $ref) {
        if (preg_match('/^[A-Za-z0-9_][A-Za-z0-9_\/.^~{}@-]*$/D', $ref) !== 1) {
            continue;
        }
        $result = run_process([$git, 'rev-parse', '--verify', $ref . '^{commit}'], $root, 10);
        $sha = trim($result['stdout']);
        if ($result['exit_code'] === 0 && preg_match('/^[a-f0-9]{40,64}$/D', $sha) === 1) {
            $identity[$key] = $sha;
        }
    }
    $status = run_process([$git, 'status', '--porcelain', '--untracked-files=normal'], $root, 10);
    if ($status['exit_code'] === 0) {
        $identity['worktree_dirty'] = trim($status['stdout']) !== '';
    }
    return $identity;
}

/**
 * Select recorded inventory findings belonging to categories above their caps.
 * @param string $family Existing classifier family: documentation or policy.
 * @param array<string,mixed> $inventory Complete existing value-free inventory report.
 * @param array<string,array{cap:int,current:int,increase:int}> $violations Existing ratchet evaluation keyed by category.
 * @return list<array<string,mixed>> Findings in failed categories, retaining declaration identities and missing fields.
 */
function audit_budget_findings(string $family, array $inventory, array $violations): array
{
    $selected = [];
    foreach ($inventory['findings'] ?? [] as $finding) {
        $path = $finding['path'];
        $category = implode('|', [$family, \PhpGallery\SourceDebt\source_scope($path),
            $inventory['inventory']['files'][$path], $finding['kind'] ?? 'none',
            explode(':', $finding['rule'], 2)[0]]);
        if (isset($violations[$category])) {
            $selected[] = $finding;
        }
    }
    return $selected;
}

/**
 * Render all failed source findings from existing reports without rescanning source.
 * @param array<string,mixed> $report Central audit report with source identity and normalized tasks.
 * @param array<string,array<string,mixed>> $artifacts Decoded value-free reports keyed by their recorded paths.
 * @return string Markdown containing every finding from failed changed-source suites and failed budget categories.
 */
function render_source_failures(array $report, array $artifacts): string
{
    $source = $report['source'];
    $lines = ['# Source contract failures', '',
        '- Profile: `' . $report['profile'] . '`',
        '- Source HEAD: `' . ($source['head_sha'] ?? 'unavailable') . '`',
        '- Comparison base: `' . ($source['comparison_base'] ?? 'unavailable') . '`',
        '- Working tree: ' . match ($source['worktree_dirty']) {
            true => 'modified; this is feedback for checkout bytes, not qualification of clean HEAD.',
            false => 'clean at audit start.', default => 'unavailable.',
        }, '', 'Fix the complete batch before rerunning. Inventory findings below include historical',
        'debt in failed categories; they are not all newly introduced defects.', ''];
    foreach ($report['tasks'] as $task) {
        if (!in_array($task['id'], ['source-documentation-changed', 'source-policy-changed',
            'source-contract-inventory', 'python-import-policy'], true)
            || !in_array($task['status'], [STATUS_FAIL, STATUS_BLOCKED], true)) {
            continue;
        }
        $lines[] = '## ' . $task['label'] . ' (' . $task['status'] . ')';
        $lines[] = '';
        $lines[] = $task['summary'];
        $lines[] = '';
        if (!empty($task['log'])) {
            $lines[] = 'Failed suite log: `' . $task['log'] . '`';
        }
        $paths = $task['details']['artifacts'] ?? [];
        if ($paths === []) {
            foreach ($task['details']['problems'] ?? [] as $problem) {
                $lines[] = '- ' . $problem;
            }
        }
        $violations = $artifacts[$paths['debt_ratchet_json'] ?? '']['evaluation']['violations'] ?? [];
        foreach ($paths as $key => $path) {
            $lines[] = '';
            $lines[] = 'Complete JSON: `' . $path . '`';
            if (!isset($artifacts[$path])) {
                $lines[] = 'Evidence unavailable or unreadable; inspect the failed suite diagnostics.';
                continue;
            }
            $data = $artifacts[$path];
            $findings = $data['findings'] ?? [];
            if ($task['id'] === 'source-contract-inventory' && in_array($key, ['documentation_json', 'policy_json'], true)) {
                $findings = audit_budget_findings($key === 'documentation_json' ? 'documentation' : 'policy', $data, $violations);
            }
            foreach ($findings as $finding) {
                $lines[] = '- `' . $finding['path'] . ':' . $finding['line'] . '` `' . $finding['rule'] . '`'
                    . (($finding['name'] ?? '') !== '' ? ' — `' . $finding['name'] . '`' : '')
                    . (isset($finding['missing']) ? ' (missing: ' . implode(', ', $finding['missing']) . ')' : '');
            }
            foreach ($data['blocked'] ?? [] as $blocked) {
                $lines[] = '- BLOCKED `' . $blocked['path'] . '`: ' . $blocked['reason'];
            }
        }
        $lines[] = '';
    }
    return implode("\n", $lines) . "\n";
}

/**
 * Persist a complete source-failure view using only artifacts from this audit run.
 * @param array<string,mixed> $report Central report containing normalized tasks and source identity.
 * @param string $root Checkout root for recorded relative artifact paths.
 * @param string $runDirectory Owned directory of the current audit run.
 * @return string|null Repository-relative Markdown path, or null when no source suite failed.
 */
function write_source_failures(array $report, string $root, string $runDirectory): ?string
{
    $artifacts = [];
    $failed = false;
    foreach ($report['tasks'] as $task) {
        if (!in_array($task['id'], ['source-documentation-changed', 'source-policy-changed',
            'source-contract-inventory', 'python-import-policy'], true)
            || !in_array($task['status'], [STATUS_FAIL, STATUS_BLOCKED], true)) {
            continue;
        }
        $failed = true;
        foreach ($task['details']['artifacts'] ?? [] as $path) {
            $absolute = $root . '/' . $path;
            if (dirname($absolute) !== $runDirectory || !is_file($absolute) || is_link($absolute)) {
                continue;
            }
            $data = json_decode((string) file_get_contents($absolute), true);
            if (is_array($data)) {
                $artifacts[$path] = $data;
            }
        }
    }
    if (!$failed) {
        return null;
    }
    $path = $runDirectory . '/source-failures.md';
    write_text_file($path, render_source_failures($report, $artifacts));
    return relative_path($path, $root);
}

/**
 * Parse Git's NUL-delimited name/status diff without splitting unusual path names.
 *
 * Rename and copy records contain old and new paths; retain the destination and
 * the original status so the advisory classifier conservatively selects full.
 * Corrupted, oversized, or ambiguous records are never partially accepted.
 *
 * @param string $raw Complete output of git diff --name-status -z --find-renames.
 * @return list<array{status:string,path:string}>|null Ordered changes, or null for invalid evidence.
 */
function parse_git_name_status_z(string $raw): ?array
{
    if ($raw === '') {
        return [];
    }
    if (strlen($raw) > 1048576 || !str_ends_with($raw, "\0")) {
        return null;
    }
    $tokens = explode("\0", $raw);
    array_pop($tokens);
    $entries = [];
    $count = count($tokens);
    for ($index = 0; $index < $count;) {
        $status = $tokens[$index++];
        if (preg_match('/^[A-Z](?:[0-9]{1,3})?$/D', $status) !== 1 || $index >= $count) {
            return null;
        }
        $path = $tokens[$index++];
        if ($status[0] === 'R' || $status[0] === 'C') {
            if ($index >= $count) {
                return null;
            }
            $path = $tokens[$index++];
        }
        if ($path === '' || str_starts_with($path, '/')
            || preg_match('#(^|/)\.{1,2}(/|$)#', $path) === 1) {
            return null;
        }
        $entries[] = ['status' => $status, 'path' => $path];
        if (count($entries) > 4096) {
            return null;
        }
    }
    return $entries;
}

/**
 * Derive a fail-closed advisory change scope from exact source and candidate commits.
 *
 * The caller supplies immutable authenticated source/candidate identities. The
 * current checkout must be clean and equal to the candidate. The comparison
 * point is the unique merge-base with origin/develop, never origin/main. A
 * separate candidate commit is accepted only with the exact reviewed
 * generator author/committer identity, a single source parent and modified generated outputs.
 * Even a valid result cannot authorize CI skips without the hosted status gate.
 *
 * @param string $root Absolute path to the exact candidate checkout.
 * @param ?string $git Resolved Git executable; null means no usable Git.
 * @param string $sourceSha Exact authored source commit supplied by the caller.
 * @param string $candidateSha Exact prepared candidate commit supplied by the caller.
 * @return array{scope:string,reason:string,source_sha:string,candidate_sha:string,develop_sha:?string,comparison_base:?string,changes:list<array{status:string,path:string}>,generated_paths:list<string>,authored_changes:list<array{status:string,path:string}>,historical_candidates:list<array{source_sha:string,candidate_sha:string,generated_paths:list<string>,provenance:string}>} Advisory evidence and conservative scope.
 */
function audit_source_change_impact(string $root, ?string $git, string $sourceSha, string $candidateSha): array
{
    $result = [
        'scope' => 'full',
        'reason' => 'git-unavailable',
        'source_sha' => $sourceSha,
        'candidate_sha' => $candidateSha,
        'develop_sha' => null,
        'comparison_base' => null,
        'changes' => [],
        'generated_paths' => [],
        'authored_changes' => [],
        'historical_candidates' => [],
    ];
    if ($git === null || $git === '' || !is_file($git)) {
        return $result;
    }
    if (preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $sourceSha) !== 1
        || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $candidateSha) !== 1) {
        $result['reason'] = 'invalid-sha';
        return $result;
    }
    $rootCanonical = realpath($root);
    if ($rootCanonical === false || !is_dir($rootCanonical)) {
        $result['reason'] = 'invalid-checkout-root';
        return $result;
    }
    // Git argv is passed literally to the existing bounded, shell-free audit
    // process owner. No credential, HTTP request, checkout or fetch is performed.
    $run = static fn(array $args): array => run_process(array_merge([$git], $args), $rootCanonical, 15);
    $passed = static fn(array $response): bool =>
        ($response['exit_code'] ?? 127) === 0 && empty($response['timed_out']) && empty($response['blocked']);
    $top = $run(['rev-parse', '--show-toplevel']);
    $observedTop = realpath(trim($top['stdout'] ?? ''));
    if (!$passed($top) || $observedTop === false
        || (DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($observedTop, $rootCanonical) !== 0
            : strcmp($observedTop, $rootCanonical) !== 0)) {
        $result['reason'] = 'wrong-repository';
        return $result;
    }
    $shallow = $run(['rev-parse', '--is-shallow-repository']);
    if (!$passed($shallow) || trim($shallow['stdout']) !== 'false') {
        $result['reason'] = 'shallow-or-unreadable-history';
        return $result;
    }
    foreach ([$sourceSha, $candidateSha] as $sha) {
        $commit = $run(['rev-parse', '--verify', $sha . '^{commit}']);
        if (!$passed($commit) || trim($commit['stdout']) !== $sha) {
            $result['reason'] = 'unavailable-source-commit';
            return $result;
        }
    }
    $head = $run(['rev-parse', '--verify', 'HEAD^{commit}']);
    if (!$passed($head) || trim($head['stdout']) !== $candidateSha) {
        $result['reason'] = 'candidate-head-mismatch';
        return $result;
    }
    $dirty = $run(['status', '--porcelain=v1', '-z', '--untracked-files=all']);
    if (!$passed($dirty) || $dirty['stdout'] !== '') {
        $result['reason'] = 'dirty-or-unreadable-checkout';
        return $result;
    }
    $developRef = 'refs/remotes/origin/develop^{commit}';
    $develop = $run(['rev-parse', '--verify', $developRef]);
    $developSha = trim($develop['stdout'] ?? '');
    if (!$passed($develop) || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $developSha) !== 1) {
        $result['reason'] = 'missing-develop-ref';
        return $result;
    }
    $result['develop_sha'] = $developSha;
    $bases = $run(['merge-base', '--all', $developSha, $sourceSha]);
    $baseList = preg_split('/\s+/', trim($bases['stdout'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$passed($bases) || count($baseList) !== 1
        || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $baseList[0]) !== 1) {
        $result['reason'] = 'missing-or-ambiguous-merge-base';
        return $result;
    }
    $baseSha = $baseList[0];
    $result['comparison_base'] = $baseSha;

    $diffArgs = static fn(string $left, string $right): array => [
        'diff', '--no-ext-diff', '--no-textconv', '--name-status', '-z',
        '--find-renames', $left, $right, '--',
    ];
    if ($candidateSha !== $sourceSha) {
        $parents = $run(['rev-list', '--parents', '-n', '1', $candidateSha]);
        $ancestry = preg_split('/\s+/', trim($parents['stdout'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$passed($parents) || $ancestry !== [$candidateSha, $sourceSha]) {
            $result['reason'] = 'unexpected-candidate-parent';
            return $result;
        }
        $identity = $run(['show', '-s', '--format=%an%n%ae%n%cn%n%ce%n%s', $candidateSha]);
        $identityLines = preg_split('/\r?\n/', rtrim($identity['stdout'] ?? '', "\r\n")) ?: [];
        if (!$passed($identity) || $identityLines !== [
            'github-actions[bot]',
            '41898282+github-actions[bot]@users.noreply.github.com',
            'github-actions[bot]',
            '41898282+github-actions[bot]@users.noreply.github.com',
            'chore(candidate): refresh reviewed generated artifacts',
        ]) {
            $result['reason'] = 'unexpected-generator-identity';
            return $result;
        }
        $generatedDiff = $run($diffArgs($sourceSha, $candidateSha));
        $generated = $passed($generatedDiff)
            ? parse_git_name_status_z($generatedDiff['stdout']) : null;
        if ($generated === null || $generated === []) {
            $result['reason'] = 'missing-or-invalid-generated-diff';
            return $result;
        }
        $generatedAllowlist = [
            'app/runtime/modules.php' => true,
            'app/production-files.json' => true,
            'app/core-manifest.json' => true,
        ];
        foreach ($generated as $change) {
            if ($change['status'] !== 'M' || !isset($generatedAllowlist[$change['path']])) {
                $result['reason'] = 'unexpected-generated-change';
                return $result;
            }
            $result['generated_paths'][] = $change['path'];
        }
    }
    $sourceDiff = $run($diffArgs($baseSha, $sourceSha));
    $changes = $passed($sourceDiff)
        ? parse_git_name_status_z($sourceDiff['stdout']) : null;
    if ($changes === null) {
        $result['reason'] = 'source-diff-unavailable';
        return $result;
    }
    $result['changes'] = $changes;

    // The aggregate tree diff intentionally retains every historical generator
    // change. A separate linear commit walk describes possible candidate
    // boundaries but does not authenticate their GitHub Actions origin.
    // No older generated change may be omitted from gate classification until
    // the corresponding exact SHA is independently qualified by hosted evidence.
    $historyRun = $run(['rev-list', '--reverse', $baseSha . '..' . $sourceSha]);
    $historyShas = preg_split('/\s+/', trim($historyRun['stdout'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$passed($historyRun) || $historyShas === [] || count($historyShas) > 64
        || strlen($historyRun['stdout']) > 8192) {
        $result['reason'] = 'unavailable-or-unbounded-source-history';
        return $result;
    }
    $previous = $baseSha;
    $generatedAllowlist = [
        'app/runtime/modules.php' => true,
        'app/production-files.json' => true,
        'app/core-manifest.json' => true,
    ];
    $botIdentity = [
        'github-actions[bot]',
        '41898282+github-actions[bot]@users.noreply.github.com',
        'github-actions[bot]',
        '41898282+github-actions[bot]@users.noreply.github.com',
        'chore(candidate): refresh reviewed generated artifacts',
    ];
    foreach ($historyShas as $historySha) {
        if (preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $historySha) !== 1) {
            $result['reason'] = 'malformed-source-history';
            return $result;
        }
        $parents = $run(['rev-list', '--parents', '-n', '1', $historySha]);
        $parentShas = preg_split('/\s+/', trim($parents['stdout'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$passed($parents) || $parentShas !== [$historySha, $previous]) {
            $result['reason'] = 'nonlinear-or-rewritten-source-history';
            return $result;
        }
        $oneDiff = $run($diffArgs($previous, $historySha));
        $oneChanges = $passed($oneDiff) ? parse_git_name_status_z($oneDiff['stdout']) : null;
        if ($oneChanges === null || $oneChanges === []) {
            $result['reason'] = 'empty-or-unreadable-history-diff';
            return $result;
        }
        $oneIdentity = $run(['show', '-s', '--format=%an%n%ae%n%cn%n%ce%n%s', $historySha]);
        if (!$passed($oneIdentity)) {
            $result['reason'] = 'unreadable-history-identity';
            return $result;
        }
        $identityFields = preg_split('/\r?\n/', rtrim($oneIdentity['stdout'], "\r\n")) ?: [];
        if ($identityFields === $botIdentity) {
            $generatedPaths = [];
            foreach ($oneChanges as $change) {
                if ($change['status'] !== 'M' || !isset($generatedAllowlist[$change['path']])) {
                    $result['reason'] = 'unexpected-historical-generator-change';
                    return $result;
                }
                $generatedPaths[] = $change['path'];
            }
            $result['historical_candidates'][] = [
                'source_sha' => $previous,
                'candidate_sha' => $historySha,
                'generated_paths' => $generatedPaths,
                'provenance' => 'requires-hosted-proof',
            ];
        } elseif (($identityFields[4] ?? null) === $botIdentity[4]) {
            // A spoofed generator subject cannot be interpreted as authored
            // changes, even if the modified path happens to be a manual.
            $result['reason'] = 'untrusted-historical-generator-identity';
            return $result;
        } else {
            foreach ($oneChanges as $change) {
                $result['authored_changes'][] = $change;
            }
        }
        $previous = $historySha;
    }
    if ($previous !== $sourceSha) {
        $result['reason'] = 'incomplete-source-history';
        return $result;
    }

    // Detect a concurrent checkout mutation or a moved local comparison ref.
    // The CI publisher separately proves the remote branch has not advanced.
    $endHead = $run(['rev-parse', '--verify', 'HEAD^{commit}']);
    $endDevelop = $run(['rev-parse', '--verify', $developRef]);
    $endDirty = $run(['status', '--porcelain=v1', '-z', '--untracked-files=all']);
    if (!$passed($endHead) || !$passed($endDevelop) || !$passed($endDirty)
        || trim($endHead['stdout']) !== $candidateSha
        || trim($endDevelop['stdout']) !== $developSha || $endDirty['stdout'] !== '') {
        $result['reason'] = 'provenance-changed-during-inspection';
        return $result;
    }

    if ($result['historical_candidates'] !== []) {
        $result['reason'] = 'unverified-historical-candidate';
        return $result;
    }
    return array_merge($result, classify_source_change_scope($changes, true));
}


/**
 * Accept only a complete, bounded, contiguous snapshot of paginated GitHub API data.
 *
 * Each page records its requested page/per_page separately from the raw API
 * response. Matching total_count alone cannot prove completeness if records
 * change concurrently; this routine also refuses short middle pages, missing
 * pages, repeated IDs and inconsistent counts. It performs no network calls.
 *
 * @param list<array{page:int,per_page:int,response:array<string,mixed>}> $pages Captured pages in request order.
 * @param string $key Raw GitHub response list field: check_runs, check_suites or jobs.
 * @return list<array<string,mixed>>|null Complete ordered API records, or null when evidence is incomplete.
 */
function audit_complete_hosted_pages(array $pages, string $key): ?array
{
    if (!in_array($key, ['check_runs', 'check_suites', 'jobs'], true)
        || !array_is_list($pages) || $pages === [] || count($pages) > 5) {
        return null;
    }
    $items = [];
    $seen = [];
    $total = null;
    foreach ($pages as $index => $page) {
        if (!is_array($page) || ($page['page'] ?? null) !== $index + 1
            || ($page['per_page'] ?? null) !== 100
            || !is_array($page['response'] ?? null)) {
            return null;
        }
        $response = $page['response'];
        $reported = $response['total_count'] ?? null;
        $batch = $response[$key] ?? null;
        if (!is_int($reported) || $reported < 0 || $reported > 500
            || !is_array($batch) || !array_is_list($batch)
            || count($batch) > 100 || ($total !== null && $total !== $reported)) {
            return null;
        }
        $total = $reported;
        $expectedPages = max(1, intdiv($total + 99, 100));
        $expectedCount = min(100, max(0, $total - $index * 100));
        if ($index >= $expectedPages || count($batch) !== $expectedCount) {
            return null;
        }
        foreach ($batch as $record) {
            $id = is_array($record) ? ($record['id'] ?? null) : null;
            if (!is_int($id) || $id <= 0 || isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $items[] = $record;
        }
    }
    if ($total === null || count($items) !== $total
        || count($pages) !== max(1, intdiv($total + 99, 100))) {
        return null;
    }
    return $items;
}

/**
 * Correlate one historical generated commit with an exact hosted evidence snapshot.
 *
 * This is a read-only evidence *consistency* check, never an authorization
 * oracle. GitHub Actions uses the same app identity for trusted and modified
 * workflows; synthetic/attacker-supplied API-shaped records can also pass
 * correlation. Until the workflow revision and token authority are independently
 * attested, even a perfectly matched snapshot retains scope=full.
 * Source-event native jobs belong to the source run suite. Prepared-SHA checks
 * belong to a separately retrieved candidate suite, whose SHA and publisher
 * are validated instead of assuming equality with the source run suite.
 *
 * @param array{source_sha:string,candidate_sha:string,generated_paths:list<string>,provenance:string} $candidate Historical boundary from the verified Git walk.
 * @param string $branch Expected, explicitly authorized source branch.
 * @param array{run:array<string,mixed>,checks_pages:list<array<string,mixed>>,candidate_suites_pages:list<array{page:int,per_page:int,response:array{total_count:int,check_suites:list<array{id:int,head_sha:string,head_commit:array{id:string},app:array{id:int},repository:array{full_name:string},status:string,conclusion:string|null}>}}>,jobs_pages:list<array<string,mixed>>,refs:array<string,string>} $evidence Captured source run, complete prepared-candidate check/suite and source-job API pages, and independently sampled refs.
 * @return array{scope:string,reason:string,correlated:bool,source_sha:string,candidate_sha:string,matched_checks:list<string>,matched_jobs:list<string>} Advisory diagnostic, never a selectable qualification.
 */
function audit_correlate_historical_candidate(array $candidate, string $branch, array $evidence): array
{
    $source = $candidate['source_sha'] ?? '';
    $prepared = $candidate['candidate_sha'] ?? '';
    $result = [
        'scope' => 'full',
        'reason' => 'invalid-historical-candidate',
        'correlated' => false,
        'source_sha' => is_string($source) ? $source : '',
        'candidate_sha' => is_string($prepared) ? $prepared : '',
        'matched_checks' => [],
        'matched_jobs' => [],
    ];
    $allowed = [
        'app/runtime/modules.php' => true,
        'app/production-files.json' => true,
        'app/core-manifest.json' => true,
    ];
    $paths = $candidate['generated_paths'] ?? null;
    if (!is_string($source) || preg_match('/^[a-f0-9]{40}$/D', $source) !== 1
        || !is_string($prepared) || preg_match('/^[a-f0-9]{40}$/D', $prepared) !== 1
        || $source === $prepared
        || ($candidate['provenance'] ?? null) !== 'requires-hosted-proof'
        || !is_array($paths) || !array_is_list($paths) || $paths === []
        || count($paths) > 3 || count(array_unique($paths)) !== count($paths)) {
        return $result;
    }
    foreach ($paths as $path) {
        if (!is_string($path) || !isset($allowed[$path])) {
            return $result;
        }
    }
    if ($branch === '' || preg_match('~^(?:feature|fix|bugfix|hotfix|codex)/[A-Za-z0-9_.,#/-]+$~D', $branch) !== 1) {
        $result['reason'] = 'invalid-branch';
        return $result;
    }
    $refs = $evidence['refs'] ?? null;
    if (!is_array($refs)
        || !is_string($refs['expected_head'] ?? null)
        || preg_match('/^[a-f0-9]{40}$/D', $refs['expected_head']) !== 1
        || !is_string($refs['develop_before'] ?? null)
        || preg_match('/^[a-f0-9]{40}$/D', $refs['develop_before']) !== 1
        || ($refs['branch_before'] ?? null) !== $refs['expected_head']
        || ($refs['branch_after'] ?? null) !== $refs['expected_head']
        || ($refs['develop_after'] ?? null) !== $refs['develop_before']) {
        $result['reason'] = 'refs-changed-or-unavailable';
        return $result;
    }
    $run = $evidence['run'] ?? null;
    if (!is_array($run)
        || !is_int($run['id'] ?? null) || $run['id'] <= 0
        || !is_int($run['run_attempt'] ?? null) || $run['run_attempt'] <= 0
        || ($run['repository']['full_name'] ?? null) !== 'klusik/PHP_gallery'
        || ($run['head_branch'] ?? null) !== $branch
        || ($run['head_sha'] ?? null) !== $source
        || ($run['head_commit']['id'] ?? null) !== $source
        || ($run['path'] ?? null) !== '.github/workflows/candidate-preparation.yml'
        || ($run['workflow_id'] ?? null) !== 377897247
        || ($run['name'] ?? null) !== 'Candidate preparation'
        || ($run['event'] ?? null) !== 'push'
        || ($run['status'] ?? null) !== 'completed'
        || ($run['conclusion'] ?? null) !== 'success'
        || !is_int($run['check_suite_id'] ?? null) || $run['check_suite_id'] <= 0
        || !is_array($run['referenced_workflows'] ?? null)
        || count($run['referenced_workflows']) !== 1
        || ($run['referenced_workflows'][0]['path'] ?? null)
            !== 'klusik/PHP_gallery/.github/workflows/gallery-workflows.yml@' . $source
        || ($run['referenced_workflows'][0]['sha'] ?? null) !== $source) {
        $result['reason'] = 'invalid-source-workflow-run';
        return $result;
    }
    $checks = audit_complete_hosted_pages($evidence['checks_pages'] ?? [], 'check_runs');
    $jobs = audit_complete_hosted_pages($evidence['jobs_pages'] ?? [], 'jobs');
    $candidateSuites = audit_complete_hosted_pages($evidence['candidate_suites_pages'] ?? [], 'check_suites');
    if ($checks === null || $jobs === null || $candidateSuites === null) {
        $result['reason'] = 'incomplete-or-conflicting-api-pages';
        return $result;
    }
    $checkNames = ['Candidate qualification', 'Complete required CI matrix'];
    $observedChecks = [];
    $candidateSuiteId = null;
    $externalId = 'candidate:' . $run['id'] . ':' . $run['run_attempt'] . ':' . $prepared;
    foreach ($checks as $check) {
        $name = $check['name'] ?? null;
        if (!in_array($name, $checkNames, true)) {
            continue;
        }
        $suiteId = $check['check_suite']['id'] ?? null;
        if (isset($observedChecks[$name])
            || !is_int($suiteId) || $suiteId <= 0
            || ($candidateSuiteId !== null && $suiteId !== $candidateSuiteId)
            || ($check['head_sha'] ?? null) !== $prepared
            || ($check['status'] ?? null) !== 'completed'
            || ($check['conclusion'] ?? null) !== 'success'
            || ($check['external_id'] ?? null) !== $externalId
            || ($check['app']['id'] ?? null) !== 15368) {
            $result['reason'] = 'invalid-or-conflicting-candidate-check';
            return $result;
        }
        $candidateSuiteId = $suiteId;
        $observedChecks[$name] = true;
    }
    if (count($observedChecks) !== count($checkNames)) {
        $result['reason'] = 'missing-candidate-check';
        return $result;
    }
    // A generated candidate has a different commit identity from the source
    // event; its SHA-bound suite cannot also be the source run's native suite.
    // Ignore unrelated suites, such as queued Pages, but require the exact
    // candidate suite referenced by both required custom checks.
    $matchingSuites = array_values(array_filter($candidateSuites,
        static fn(array $suite): bool => $suite['id'] === $candidateSuiteId));
    $suite = $matchingSuites[0] ?? null;
    if (count($matchingSuites) !== 1 || $candidateSuiteId === $run['check_suite_id']
        || ($suite['head_sha'] ?? null) !== $prepared
        || ($suite['head_commit']['id'] ?? null) !== $prepared
        || ($suite['app']['id'] ?? null) !== 15368
        || ($suite['repository']['full_name'] ?? null) !== 'klusik/PHP_gallery'
        || ($suite['status'] ?? null) !== 'completed'
        || ($suite['conclusion'] ?? null) !== 'success') {
        $result['reason'] = 'invalid-or-missing-candidate-suite';
        return $result;
    }
    $result['matched_checks'] = $checkNames;

    // This exact map describes archived Candidate preparation/push snapshots,
    // including their historical PHP 8.3 names and retired package step names.
    // It is neither an active CI registry nor an authority adapter for current
    // PHP 8.5 maintenance runs. Unexpected jobs or skipped required steps
    // invalidate this advisory snapshot rather than silently narrowing it.
    $requiredSteps = [
        'Prepare source candidate at event SHA' => [
            'Check branch identity and source provenance',
            'Cheap source-policy preflight before generation',
            'Prepare and verify canonical candidate artifacts',
            'Commit only generated artifacts with a non-forced branch lease',
        ],
        'Full CI for the published prepared SHA / Read-only generated-state and source preflight' => [
            'Verify exact candidate checkout',
            'Central candidate preflight (read only)',
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
            'Discover and verify required Chromium',
            'Central required browser audit',
        ],
        'Full CI for the published prepared SHA / Positive production package (ubuntu-24.04)' => [
            'Require current production membership and integrity data',
            'Build production folder with Bash',
            'Inspect exact artifact membership',
        ],
        'Full CI for the published prepared SHA / Positive production package (windows-2025)' => [
            'Require current production membership and integrity data',
            'Build production folder with PowerShell',
            'Inspect exact artifact membership',
        ],
        'Full CI for the published prepared SHA / Positive production package (macos-latest)' => [
            'Require current production membership and integrity data',
            'Build production folder with Bash',
            'Inspect exact artifact membership',
        ],
        'Full CI for the published prepared SHA / Complete required CI matrix' => [
            'Refuse failed, blocked, missing or skipped mandatory owners',
        ],
        'Reject superseded qualification' => [
            'Verify successful qualification and unchanged published branch head',
            'Publish SHA-bound hosted qualification result',
        ],
    ];
    $skippedRelease = 'Full CI for the published prepared SHA / Authoritative release audit';
    $matched = [];
    foreach ($jobs as $job) {
        $name = $job['name'] ?? null;
        if (!is_string($name) || isset($matched[$name])
            || ($job['run_id'] ?? null) !== $run['id']
            || ($job['run_attempt'] ?? null) !== $run['run_attempt']
            || ($job['head_sha'] ?? null) !== $source
            || ($job['workflow_name'] ?? null) !== 'Candidate preparation'
            || ($job['status'] ?? null) !== 'completed') {
            $result['reason'] = 'invalid-or-conflicting-workflow-job';
            return $result;
        }
        if ($name === $skippedRelease) {
            if (($job['conclusion'] ?? null) !== 'skipped') {
                $result['reason'] = 'invalid-release-only-job';
                return $result;
            }
        } elseif (isset($requiredSteps[$name]) && ($job['conclusion'] ?? null) === 'success') {
            $steps = $job['steps'] ?? null;
            if (!is_array($steps) || !array_is_list($steps)) {
                $result['reason'] = 'incomplete-required-job-steps';
                return $result;
            }
            foreach ($requiredSteps[$name] as $stepName) {
                $matching = [];
                foreach ($steps as $step) {
                    if (is_array($step) && ($step['name'] ?? null) === $stepName) {
                        $matching[] = $step;
                    }
                }
                if (count($matching) !== 1
                    || ($matching[0]['status'] ?? null) !== 'completed'
                    || ($matching[0]['conclusion'] ?? null) !== 'success') {
                    $result['reason'] = 'failed-or-missing-required-job-step';
                    return $result;
                }
            }
        } else {
            $result['reason'] = 'unexpected-or-failed-required-job';
            return $result;
        }
        $matched[$name] = true;
    }
    if (count($matched) !== count($requiredSteps) + 1
        || !isset($matched[$skippedRelease])) {
        $result['reason'] = 'missing-required-workflow-job';
        return $result;
    }
    $result['matched_jobs'] = array_keys($requiredSteps);
    $result['correlated'] = true;
    $result['reason'] = 'workflow-authority-not-independently-attested';
    return $result;
}


/**
 * Decode a complete NUL-delimited raw Git tree diff without losing blob identities.
 *
 * This inventory deliberately disables rename detection so both deleted and
 * added paths remain explicit inputs to later transitive dependency analysis.
 * It is not a release allowlist or proof of interchangeable test outcomes.
 *
 * @param string $raw Complete output of git diff --raw --no-abbrev -z --no-renames.
 * @return list<array{status:string,path:string,before_mode:string,after_mode:string,before_blob:string,after_blob:string}>|null Full inventory, or null for malformed/bounded evidence.
 */
function parse_git_raw_delta_z(string $raw): ?array
{
    if ($raw === '') {
        return [];
    }
    if (strlen($raw) > 1048576 || !str_ends_with($raw, "\0")) {
        return null;
    }
    $fields = explode("\0", $raw);
    array_pop($fields);
    if (count($fields) % 2 !== 0 || count($fields) > 8192) {
        return null;
    }

    $entries = [];
    $seen = [];
    $oid = '(?:[a-f0-9]{40}|[a-f0-9]{64})';
    $pattern = '/^:([0-7]{6}) ([0-7]{6}) (' . $oid . ') (' . $oid . ') ([AMDT])$/D';
    $validModes = ['000000' => true, '100644' => true, '100755' => true,
        '120000' => true, '160000' => true];
    for ($index = 0, $count = count($fields); $index < $count; $index += 2) {
        $header = $fields[$index];
        $path = $fields[$index + 1];
        if (preg_match($pattern, $header, $matches) !== 1
            || $path === '' || str_starts_with($path, '/')
            || str_contains($path, "\\") || isset($seen[$path])
            || preg_match('#(^|/)\.{1,2}(/|$)#', $path) === 1) {
            return null;
        }
        [, $beforeMode, $afterMode, $beforeBlob, $afterBlob, $status] = $matches;
        if (!isset($validModes[$beforeMode], $validModes[$afterMode])
            || strlen($beforeBlob) !== strlen($afterBlob)) {
            return null;
        }
        $empty = str_repeat('0', strlen($beforeBlob));
        if (($status === 'A' && ($beforeMode !== '000000' || $beforeBlob !== $empty
                || $afterMode === '000000' || $afterBlob === $empty))
            || ($status === 'D' && ($afterMode !== '000000' || $afterBlob !== $empty
                || $beforeMode === '000000' || $beforeBlob === $empty))
            || (($status === 'M' || $status === 'T')
                && ($beforeMode === '000000' || $afterMode === '000000'
                    || $beforeBlob === $empty || $afterBlob === $empty))) {
            return null;
        }
        $entries[] = [
            'status' => $status, 'path' => $path,
            'before_mode' => $beforeMode, 'after_mode' => $afterMode,
            'before_blob' => $beforeBlob, 'after_blob' => $afterBlob,
        ];
        $seen[$path] = true;
    }
    return $entries;
}

/**
 * Inventory an exact same-SHA handoff or a complete ancestry-bound release delta.
 *
 * Only local immutable Git objects are read. This intentionally returns full
 * qualification scope even for identical trees: authenticated workflow authority,
 * original individual child results, environment profiles, transitive inputs,
 * source-base-sensitive checks and remote branch leases are NOT established here.
 * A later gate must independently prove every one of those properties.
 *
 * @param string $root Canonical checkout root containing the target commit at HEAD.
 * @param ?string $git Resolved Git binary, or null when unavailable.
 * @param string $originalSha Previously qualified candidate or release-origin commit.
 * @param string $targetSha Current integration or final prepared release commit.
 * @return array{scope:string,evidence:string,reason:string,original_sha:string,target_sha:string,original_tree:?string,target_tree:?string,tree_equal:?bool,history_sensitive:bool,changes:list<array{status:string,path:string,before_mode:string,after_mode:string,before_blob:string,after_blob:string}>} Read-only inventory; never an authorization to reuse PASS.
 */
function audit_cross_stage_tree_delta(
    string $root,
    ?string $git,
    string $originalSha,
    string $targetSha
): array {
    $result = [
        'scope' => 'full', 'evidence' => 'BLOCKED', 'reason' => 'git-unavailable',
        'original_sha' => $originalSha, 'target_sha' => $targetSha,
        'original_tree' => null, 'target_tree' => null, 'tree_equal' => null,
        'history_sensitive' => $originalSha !== $targetSha, 'changes' => [],
    ];
    if ($git === null || $git === '' || !is_file($git)) {
        return $result;
    }
    if (preg_match('/^[a-f0-9]{40}$/D', $originalSha) !== 1
        || preg_match('/^[a-f0-9]{40}$/D', $targetSha) !== 1) {
        $result['reason'] = 'invalid-sha';
        return $result;
    }
    $checkoutRoot = realpath($root);
    if ($checkoutRoot === false || !is_dir($checkoutRoot)) {
        $result['reason'] = 'invalid-checkout-root';
        return $result;
    }
    $run = static fn(array $args): array =>
        run_process(array_merge([$git], $args), $checkoutRoot, 15);
    $passed = static fn(array $response): bool =>
        ($response['exit_code'] ?? 127) === 0 && empty($response['timed_out']) && empty($response['blocked']);
    $top = $run(['rev-parse', '--show-toplevel']);
    $resolvedTop = realpath(trim($top['stdout'] ?? ''));
    if (!$passed($top) || $resolvedTop === false
        || (DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($resolvedTop, $checkoutRoot) !== 0
            : strcmp($resolvedTop, $checkoutRoot) !== 0)) {
        $result['reason'] = 'wrong-repository';
        return $result;
    }
    $shallow = $run(['rev-parse', '--is-shallow-repository']);
    if (!$passed($shallow) || trim($shallow['stdout'] ?? '') !== 'false') {
        $result['reason'] = 'shallow-or-unreadable-history';
        return $result;
    }
    foreach ([$originalSha, $targetSha] as $sha) {
        $commit = $run(['rev-parse', '--verify', $sha . '^{commit}']);
        if (!$passed($commit) || trim($commit['stdout'] ?? '') !== $sha) {
            $result['reason'] = 'missing-commit';
            return $result;
        }
    }
    $head = $run(['rev-parse', '--verify', 'HEAD^{commit}']);
    if (!$passed($head) || trim($head['stdout'] ?? '') !== $targetSha) {
        $result['reason'] = 'target-head-mismatch';
        return $result;
    }
    $initialStatus = $run(['status', '--porcelain=v1', '-z', '--untracked-files=all']);
    if (!$passed($initialStatus) || $initialStatus['stdout'] !== '') {
        $result['reason'] = 'dirty-or-unreadable-checkout';
        return $result;
    }
    $ancestry = $run(['merge-base', '--is-ancestor', $originalSha, $targetSha]);
    if (!$passed($ancestry)) {
        $result['reason'] = 'unverified-ancestry';
        return $result;
    }
    foreach (['original_tree' => $originalSha, 'target_tree' => $targetSha] as $key => $sha) {
        $tree = $run(['rev-parse', '--verify', $sha . '^{tree}']);
        $treeSha = trim($tree['stdout'] ?? '');
        if (!$passed($tree) || preg_match('/^[a-f0-9]{40}$/D', $treeSha) !== 1) {
            $result['reason'] = 'unreadable-tree';
            return $result;
        }
        $result[$key] = $treeSha;
    }
    $result['tree_equal'] = $result['original_tree'] === $result['target_tree'];
    $diff = $run(['diff', '--raw', '--no-abbrev', '--no-ext-diff', '--no-textconv',
        '--no-renames', '-z', $result['original_tree'], $result['target_tree'], '--']);
    $entries = $passed($diff) ? parse_git_raw_delta_z($diff['stdout']) : null;
    if ($entries === null || ($entries === []) !== $result['tree_equal']) {
        $result['reason'] = 'incomplete-or-invalid-tree-delta';
        $result['changes'] = [];
        return $result;
    }
    $finalHead = $run(['rev-parse', '--verify', 'HEAD^{commit}']);
    $finalStatus = $run(['status', '--porcelain=v1', '-z', '--untracked-files=all']);
    if (!$passed($finalHead) || trim($finalHead['stdout'] ?? '') !== $targetSha
        || !$passed($finalStatus) || $finalStatus['stdout'] !== '') {
        $result['reason'] = 'checkout-changed-during-inventory';
        return $result;
    }
    $result['changes'] = $entries;
    $result['evidence'] = 'RECORDED';
    $result['reason'] = $result['tree_equal'] ? 'tree-identical' : 'tree-delta-recorded';
    return $result;
}
