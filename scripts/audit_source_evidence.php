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
