<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/source_contracts/policy_changes.php
 * Module Type: Changed Runtime Policy Gate
 * Purpose: Enforce newly unexplained runtime policy sites against immutable Git HEAD.
 * Responsibilities:
 *   - Keep whole-tree policy inventory and scoped change enforcement separate.
 *   - Report unsupported coverage without reading private configuration or printing values.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\SourceContracts;

require_once __DIR__ . '/policy_scan.php';

/**
 * Compare runtime policy sites using the declaration gate's move/copy multiset rules.
 * @param string $before Immutable HEAD source; empty for a new source file.
 * @param string $after Current PHP or JavaScript runtime source.
 * @param string $path Relative source identity, also used by disposable fixtures.
 * @return array<string,mixed> Added/changed/unchanged sites and value-free contract findings.
 */
function changed_policy_source(string $before, string $after, string $path): array
{
    $old = policy_site_snapshots($before, $path);
    $current = policy_site_snapshots($after, $path);
    return compare_declaration_snapshots($old['snapshots'], $current['snapshots'], __NAMESPACE__ . '\\policy_site_issues');
}

/**
 * Enforce the bounded runtime policy slice without Git writes or legacy baselines.
 * @param string $root Exact repository root or disposable fixture root.
 * @param list<string> $paths Optional exact discovered sources; privacy exclusions cannot be overridden.
 * @param callable(list<string>,string):array{status:int,stdout:string}|null $git Read-only Git transport, replaceable only by fixtures.
 * @return array<string,mixed> Scoped status, counters, findings, explicit review gaps and safe blockers.
 */
function changed_policy_report(string $root, array $paths = [], ?callable $git = null): array
{
    $report = ['status' => 'BLOCKED', 'summary' => ['base' => 'HEAD', 'source_files' => 0, 'runtime_files' => 0,
        'changed_files' => 0, 'added' => 0, 'changed' => 0, 'unchanged' => 0, 'moved' => 0,
        'doc_regressions' => 0, 'finding_count' => 0, 'sites_with_findings' => 0, 'coverage_review_count' => 0],
        'findings' => [], 'blocked' => [], 'coverage_review' => [], 'coverage' => [
            'scope' => 'Only app/, public/ and root index.php PHP/JS/MJS/CJS policy sites. PASS means these recognized change rules passed, not complete policy compliance.',
            'rules' => 'Uppercase const/static-name define and JS const/let/var definitions; operationally named direct numeric assignments; direct sleep/usleep/set_time_limit and global setTimeout/setInterval numeric arguments.',
            'matching' => 'Read-only HEAD, scope identities and site-token fingerprints. Header/formatting and unrelated body edits preserve unchanged legacy sites. Exact moves are matched after same-path originals; copies remain additions.',
            'legacy' => 'Unchanged legacy explanation debt is not enforced here; check_policy_constants.php without --changed remains its separate whole-tree advisory inventory.',
            'maps' => 'Configurable-map and object-property policy entries are counted for review, not enforced or silently declared compliant. configuration_defaults.php remains the tunable owner; policy_constants.php remains immutable.',
            'languages' => 'PHP/JS source only. Embedded scripts, CSS, Python, shell, PowerShell and other formats retain explicit review gaps. Private/generated/vendor content is never read.',
            'semantics' => 'Conservative JS lexer; dynamic definitions, numeric syntax outside recognized arithmetic, imported/shadowed timer names, resolved duplicate ownership and explanation truthfulness require review. No values, hashes, snippets or documentation text are emitted.',
        ]];
    $activePath = '';
    try {
        $root = realpath($root) ?: $root;
        $inventory = inventory($root);
        $selected = $paths === [] ? $inventory['files'] : array_intersect_key($inventory['files'], array_fill_keys($paths, true));
        if (array_diff($paths, array_keys($selected)) !== []) {
            throw new \RuntimeException('Selected source absent or excluded.');
        }
        $report['summary']['source_files'] = count($selected);
        $git ??= __NAMESPACE__ . '\\source_git_read';
        $top = $git(['rev-parse', '--show-toplevel'], $root);
        if ($top['status'] !== 0 || strcasecmp(str_replace('\\', '/', trim($top['stdout'])), str_replace('\\', '/', $root)) !== 0) {
            throw new \RuntimeException('Git root unavailable.');
        }
        $tree = $git(['ls-tree', '-r', '-z', 'HEAD'], $root);
        $delta = $git(['diff', '--no-ext-diff', '--no-renames', '--name-only', '-z', 'HEAD', '--'], $root);
        if ($tree['status'] !== 0 || $delta['status'] !== 0) {
            throw new \RuntimeException('Git HEAD/change list unavailable.');
        }
        $tracked = [];
        foreach (explode("\0", $tree['stdout']) as $entry) {
            if ($entry !== '' && preg_match('/^([0-9]+) blob [a-f0-9]+\t(.*)$/s', $entry, $match) === 1) {
                $tracked[$match[2]] = $match[1];
            }
        }
        $changedPaths = array_fill_keys(explode("\0", $delta['stdout']), true);
        $oldSites = [];
        $currentSites = [];
        foreach ($selected as $path => $extension) {
            $activePath = $path;
            if (!policy_runtime_path($path)) {
                $report['coverage_review'][] = ['path' => $path, 'reason' => 'outside_runtime_gate_scope'];
                continue;
            }
            if (!in_array($extension, ['php', 'js', 'mjs', 'cjs'], true)) {
                $report['coverage_review'][] = ['path' => $path, 'reason' => 'native_format_not_policy_parsed'];
                continue;
            }
            $report['summary']['runtime_files']++;
            $after = file_get_contents($root . '/' . $path);
            if (!is_string($after)) {
                throw new \RuntimeException('Current runtime source unreadable.');
            }
            $before = '';
            if (isset($tracked[$path])) {
                if (!in_array($tracked[$path], ['100644', '100755'], true)) {
                    throw new \RuntimeException('Unsupported HEAD source mode.');
                }
                if (!isset($changedPaths[$path])) {
                    $before = $after; // Git confirms no byte change; still consume original sites before copy matching.
                } else {
                    $blob = $git(['cat-file', 'blob', 'HEAD:' . $path], $root);
                    if ($blob['status'] !== 0) {
                        throw new \RuntimeException('Required HEAD blob unreadable.');
                    }
                    $before = $blob['stdout'];
                }
            }
            $report['summary']['changed_files'] += $before !== $after ? 1 : 0;
            $current = policy_site_snapshots($after, $path);
            $old = $before === $after ? $current : policy_site_snapshots($before, $path);
            array_push($oldSites, ...$old['snapshots']);
            array_push($currentSites, ...$current['snapshots']);
            foreach ($current['review'] as $reason => $count) {
                $report['coverage_review'][] = ['path' => $path, 'reason' => $reason, 'count' => $count];
            }
            if ($extension === 'php' && str_contains($after, '<script')) {
                $report['coverage_review'][] = ['path' => $path, 'reason' => 'embedded_javascript_requires_separate_review'];
            }
        }
        if ($paths === []) {
            foreach ($tracked as $path => $mode) {
                if (!isset($changedPaths[$path]) || isset($inventory['files'][$path])
                    || !policy_runtime_path($path) || !source_head_declaration_allowed($path)
                    || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'js', 'mjs', 'cjs'], true)
                    || !in_array($mode, ['100644', '100755'], true)) {
                    continue;
                }
                $blob = $git(['cat-file', 'blob', 'HEAD:' . $path], $root);
                if ($blob['status'] !== 0) {
                    throw new \RuntimeException('Deleted HEAD source unreadable.');
                }
                array_push($oldSites, ...policy_site_snapshots($blob['stdout'], $path)['snapshots']);
            }
        }
        $result = compare_declaration_snapshots($oldSites, $currentSites, __NAMESPACE__ . '\\policy_site_issues');
        foreach (['added', 'changed', 'unchanged', 'moved', 'doc_regressions'] as $counter) {
            $report['summary'][$counter] = $result[$counter];
        }
        $report['findings'] = $result['findings'];
        $report['blocked'] = $result['blocked'];
        $report['summary']['finding_count'] = count($report['findings']);
        $sites = [];
        foreach ($report['findings'] as $finding) {
            $sites[$finding['path'] . ':' . $finding['line'] . ':' . $finding['identity']] = true;
        }
        $report['summary']['sites_with_findings'] = count($sites);
        $report['summary']['coverage_review_count'] = count($report['coverage_review']);
        $report['status'] = $report['blocked'] !== [] ? 'BLOCKED' : ($report['findings'] !== [] ? 'FAIL' : 'PASS');
    } catch (\Throwable $error) {
        $report['blocked'][] = ['path' => $activePath, 'reason' => 'Git HEAD/runtime policy comparison could not complete; coverage unknown.'];
        $report['status'] = 'BLOCKED';
    }
    return $report;
}
