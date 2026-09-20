<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/source_contracts/changes.php
 * Module Type: Changed Declaration Contract Gate
 * Purpose: Enforce contracts on declarations added or changed since Git HEAD.
 * Responsibilities:
 *   - Compare syntax identities and executable tokens without checkout/index writes.
 *   - Preserve visible legacy debt and block unknown comparison coverage.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\SourceContracts;

/**
 * Run read-only Git without a shell or exposing diagnostic source/configuration text.
 * @param list<string> $arguments Git subcommand and literal arguments.
 * @param string $root Repository working directory.
 * @return array{status:int,stdout:string} Process status and captured data.
 */
function source_git_read(array $arguments, string $root): array
{
    // Git may emit one CRLF warning per changed file. Discard that untrusted
    // diagnostic stream directly; sequentially draining two pipes can deadlock.
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $process = @proc_open(array_merge(['git', '--no-optional-locks', '-C', $root], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $nullDevice, 'w']], $pipes, $root);
    if (!is_resource($process)) {
        return ['status' => 2, 'stdout' => ''];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return ['status' => proc_close($process), 'stdout' => is_string($stdout) ? $stdout : ''];
}

/**
 * Remove native leading metadata without hiding a changed shebang or markup prolog.
 * @param string $source Complete file contents.
 * @param string $extension Native source extension.
 * @return string Preamble plus body with header comments and line endings normalized.
 */
function source_without_header(string $source, string $extension): string
{
    $source = str_replace("\r\n", "\n", $source);
    $offset = null;
    leading_header($source, $extension, $offset);
    preg_match_all('/^(?:#![^\n]*|<\?xml[^>]*\?>|<!doctype[^>]*>)/im', substr($source, 0, $offset ?? 0), $preambles);
    return implode("\n", $preambles[0]) . "\n" . substr($source, $offset ?? 0);
}

/**
 * Build namespace/owner identities and comment-free executable fingerprints.
 * Repeated callbacks share an identity but independent hashes, avoiding positional
 * renumbering when a new sibling is inserted.
 * @param string $source PHP or JavaScript source, never executed.
 * @param string $extension Supported PHP/JS module extension.
 * @return list<array{identity:string,fingerprint:string,record:array<string,mixed>,uncertain:bool}> Internal records; no source values are printed.
 */
function declaration_snapshots(string $source, string $extension): array
{
    $php = $extension === 'php';
    $tokens = $php ? php_tokens($source) : javascript_tokens($source);
    $pairs = delimiter_pairs($tokens);
    $records = $php ? php_declarations($source) : javascript_declarations($source);
    $snapshots = [];
    foreach ($records as $record) {
        $owner = '';
        if ($php) {
            for ($index = 0; $index < $record['start_token']; $index++) {
                if ($tokens[$index]['id'] !== T_NAMESPACE) {
                    continue;
                }
                $owner = '';
                for ($index++; $index < $record['start_token'] && !in_array($tokens[$index]['text'], [';', '{'], true); $index++) {
                    $owner .= $tokens[$index]['text'];
                }
            }
        }
        $nearest = -1;
        foreach ($snapshots as $snapshot) {
            $candidate = $snapshot['record'];
            if ($candidate['start_token'] < $record['start_token'] && $candidate['end_token'] >= $record['end_token']
                && $candidate['start_token'] > $nearest && $candidate['kind'] !== 'property') {
                $owner = $snapshot['identity'];
                $nearest = $candidate['start_token'];
            }
        }
        $identity = ($owner === '' ? '' : $owner . '/') . $record['kind'] . ':' . $record['name'];
        $executable = [];
        $uncertain = false;
        for ($index = $record['start_token']; $index <= $record['end_token'] && isset($tokens[$index]); $index++) {
            $token = $tokens[$index];
            if (in_array($token['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $previous = $tokens[$index - 1] ?? null;
            $restrictedLineBreak = !$php && $previous !== null && $token['line'] > $previous['line']
                && (in_array($previous['text'], ['return', 'throw', 'yield', 'break', 'continue', 'async'], true)
                    || in_array($token['text'], ['++', '--'], true));
            $executable[] = [$token['id'], $token['text'], $restrictedLineBreak];
            if (!$php && ($token['text'][0] ?? '') === '#') {
                $uncertain = true;
            }
            if (!$php && $token['text'] === '[' && isset($pairs[$index])) {
                $afterName = $pairs[$index] + 1;
                if (($tokens[$afterName]['text'] ?? '') === '('
                    && ($tokens[($pairs[$afterName] ?? -2) + 1]['text'] ?? '') === '{') {
                    $uncertain = true;
                }
            }
        }
        $snapshots[] = ['identity' => $identity, 'fingerprint' => hash('sha256', json_encode($executable, JSON_THROW_ON_ERROR)),
            'record' => $record, 'uncertain' => $uncertain];
    }
    return $snapshots;
}

/**
 * Match declaration multisets and enforce changed bodies or doc-only regressions.
 * @param list<array<string,mixed>> $old HEAD snapshots carrying relative source paths.
 * @param list<array<string,mixed>> $current Worktree snapshots carrying relative source paths.
 * @param callable(array<string,mixed>):list<string>|null $issueCheck Optional policy-site contract evaluator; declaration rules remain the default.
 * @return array{added:int,changed:int,unchanged:int,moved:int,doc_regressions:int,findings:list<array<string,mixed>>,blocked:list<array{path:string,reason:string}>} Comparison counts and value-free findings.
 */
function compare_declaration_snapshots(array $old, array $current, ?callable $issueCheck = null): array
{
    $issueCheck ??= __NAMESPACE__ . '\\declaration_issues';
    $result = ['added' => 0, 'changed' => 0, 'unchanged' => 0, 'moved' => 0, 'doc_regressions' => 0, 'findings' => [], 'blocked' => []];
    $matches = [];
    $used = [];
    // Match unchanged duplicates before a new callback can consume their counterpart.
    foreach ([true, false] as $samePathOnly) {
        foreach ($current as $index => $snapshot) {
            if (isset($matches[$index])) {
                continue;
            }
            foreach ($old as $oldIndex => $candidate) {
                if (!isset($used[$oldIndex]) && $snapshot['identity'] === $candidate['identity']
                    && $snapshot['fingerprint'] === $candidate['fingerprint']
                    && (!$samePathOnly || $snapshot['path'] === $candidate['path'])) {
                    $matches[$index] = $oldIndex;
                    $used[$oldIndex] = true;
                    break;
                }
            }
        }
    }
    foreach ($current as $index => $snapshot) {
        $path = $snapshot['path'];
        $same = isset($matches[$index]);
        $oldIndex = $matches[$index] ?? null;
        if (!$same) {
            foreach ($old as $candidateIndex => $candidate) {
                if (!isset($used[$candidateIndex]) && $snapshot['identity'] === $candidate['identity']) {
                    $oldIndex = $candidateIndex;
                    $used[$candidateIndex] = true;
                    break;
                }
            }
        }
        $record = $snapshot['record'];
        $issues = $issueCheck($record);
        if ($same) {
            $result['unchanged']++;
            $result['moved'] += $path !== $old[$oldIndex]['path'] ? 1 : 0;
            $issues = array_values(array_diff($issues, $issueCheck($old[$oldIndex]['record'])));
            if ($issues !== []) {
                $result['doc_regressions']++;
            }
        } else {
            $result[$oldIndex === null ? 'added' : 'changed']++;
            if ($snapshot['uncertain']) {
                $result['blocked'][] = ['path' => $path, 'reason' => 'Changed JavaScript declaration contains private/computed syntax requiring parser coverage.'];
            }
        }
        foreach ($issues as $rule) {
            $result['findings'][] = ['path' => $path, 'line' => $record['line'], 'identity' => $snapshot['identity'],
                'name' => $record['name'], 'kind' => $record['kind'], 'rule' => $rule];
        }
    }
    return $result;
}

/**
 * Attach source identity to tokenized declarations for cross-file move matching.
 * @param string $source Current or HEAD source text.
 * @param string $path Relative source identity.
 * @return list<array<string,mixed>> Declaration snapshots carrying the source path.
 */
function source_declaration_snapshots(string $source, string $path): array
{
    if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['html', 'htm'], true)) {
        $snapshots = [];
        foreach (html_script_sources($source) as $script) {
            foreach (declaration_snapshots($script['source'], 'js') as $snapshot) {
                $snapshot['record']['line'] += $script['line'] - 1;
                $snapshot['path'] = $path;
                $snapshots[] = $snapshot;
            }
        }
        return $snapshots;
    }
    $snapshots = declaration_snapshots($source, strtolower(pathinfo($path, PATHINFO_EXTENSION)));
    foreach ($snapshots as &$snapshot) {
        $snapshot['path'] = $path;
    }
    unset($snapshot);
    return $snapshots;
}

/**
 * Extract executable inline HTML scripts while excluding comments and data scripts.
 * @param string $source Native HTML fixture/source text.
 * @return list<array{source:string,line:int}> JavaScript bodies and their one-based origin lines.
 */
function html_script_sources(string $source): array
{
    $scripts = [];
    $cursor = 0;
    while (preg_match('/<!--|<script\b/i', $source, $start, PREG_OFFSET_CAPTURE, $cursor) === 1) {
        $offset = $start[0][1];
        if ($start[0][0] === '<!--') {
            $close = strpos($source, '-->', $offset + 4);
            $cursor = $close === false ? strlen($source) : $close + 3;
            continue;
        }
        if (preg_match('/\G<script\b((?:"[^"]*"|\'[^\']*\'|[^\'">])*)>/Ai', $source, $opening, 0, $offset) !== 1) {
            throw new \RuntimeException('Unclassified HTML script opening.');
        }
        $bodyStart = $offset + strlen($opening[0]);
        if (preg_match('/<\/script\s*>/i', $source, $closing, PREG_OFFSET_CAPTURE, $bodyStart) !== 1) {
            throw new \RuntimeException('Unclassified HTML script boundary.');
        }
        $cursor = $closing[0][1] + strlen($closing[0][0]);
        $attributes = $opening[1];
        if (preg_match('/\btype\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attributes, $type) === 1) {
            $kind = strtolower(trim($type[1] !== '' ? $type[1] : (($type[2] ?? '') !== '' ? $type[2] : ($type[3] ?? ''))));
            if (in_array($kind, ['application/json', 'application/ld+json', 'importmap', 'speculationrules'], true)) {
                continue;
            }
            if (!in_array($kind, ['', 'module', 'text/javascript', 'application/javascript'], true)) {
                throw new \RuntimeException('Unclassified HTML script language.');
            }
        }
        if (preg_match('/\bsrc\s*=/i', $attributes) === 1) {
            continue;
        }
        $scripts[] = ['source' => substr($source, $bodyStart, $closing[0][1] - $bodyStart),
            'line' => substr_count(substr($source, 0, $bodyStart), "\n") + 1];
    }
    return $scripts;
}

/**
 * Compare one source pair using the same identity/multiset gate as the project.
 * @param string $before HEAD source, empty for an added file.
 * @param string $after Current source.
 * @param string $path Relative source identity.
 * @return array<string,mixed> Changed declaration counts, findings and coverage blockers.
 */
function changed_source_contracts(string $before, string $after, string $path): array
{
    return compare_declaration_snapshots(source_declaration_snapshots($before, $path), source_declaration_snapshots($after, $path));
}

/**
 * Apply discovery privacy boundaries to deleted HEAD files before reading blobs.
 * @param string $path Repository-relative Git path.
 * @return bool Whether a regular deleted PHP/JavaScript source may supply move evidence.
 */
function source_head_declaration_allowed(string $path): bool
{
    $parts = explode('/', $path);
    $filename = array_pop($parts);
    return $path !== 'config.php' && !str_starts_with((string) $filename, '.env')
        && array_intersect($parts, source_excluded_directories()) === []
        && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'js', 'mjs', 'cjs', 'html', 'htm'], true);
}


/**
 * Enforce current declarations against readable Git HEAD without repository writes.
 * Deleted files are irrelevant; untracked sources are additions. Private/generated
 * paths are never read from either HEAD or the worktree.
 * @param string $root Exact repository root, or a disposable fixture root.
 * @param list<string> $paths Optional exact discovered paths for focused enforcement.
 * @param callable(list<string>,string):array{status:int,stdout:string}|null $git Injectable read-only Git transport for fixtures.
 * @return array{status:string,summary:array<string,mixed>,findings:list<array<string,mixed>>,blocked:list<array{path:string,reason:string}>,coverage:array<string,string>} Bounded PASS/FAIL/BLOCKED evidence.
 */
function changed_documentation_report(string $root, array $paths = [], ?callable $git = null): array
{
    $report = ['status' => 'BLOCKED', 'summary' => ['base' => 'HEAD', 'source_files' => 0, 'changed_files' => 0,
        'added' => 0, 'changed' => 0, 'unchanged' => 0, 'moved' => 0, 'doc_regressions' => 0, 'finding_count' => 0],
        'findings' => [], 'blocked' => [], 'coverage' => [
            'scope' => 'Added/materially changed PHP, JS/MJS/CJS and inline HTML script declarations; doc-only regressions. Unchanged legacy debt remains visible in the whole-tree inventory.',
            'identity' => 'Namespace/containing declaration identity and executable-token fingerprints; no line-number identity or baseline.',
            'limitations' => 'Conservative JS lexer, complex types and semantic truthfulness require review. Changed Python/shell/PowerShell bodies and missing Git history mean BLOCKED coverage. CSS/YAML/SVG/TeX/Apache use the separate native-header gate, not an application-callable gate.',
        ]];
    try {
        $root = realpath($root) ?: $root;
        $inventory = inventory($root);
        $selected = $paths === [] ? $inventory['files'] : array_intersect_key($inventory['files'], array_fill_keys($paths, true));
        if (array_diff($paths, array_keys($selected)) !== []) {
            throw new \RuntimeException('Selected source is absent or excluded.');
        }
        $report['summary']['source_files'] = count($selected);
        $git ??= __NAMESPACE__ . '\\source_git_read';
        $top = $git(['rev-parse', '--show-toplevel'], $root);
        if ($top['status'] !== 0 || strcasecmp(str_replace('\\', '/', trim($top['stdout'])), str_replace('\\', '/', $root)) !== 0) {
            throw new \RuntimeException('Git repository root unavailable or mismatched.');
        }
        $tree = $git(['ls-tree', '-r', '-z', 'HEAD'], $root);
        $delta = $git(['diff', '--no-ext-diff', '--no-renames', '--name-only', '-z', 'HEAD', '--'], $root);
        if ($tree['status'] !== 0 || $delta['status'] !== 0) {
            throw new \RuntimeException('Git HEAD or change list unavailable.');
        }
        $tracked = [];
        foreach (explode("\0", $tree['stdout']) as $entry) {
            if ($entry !== '' && preg_match('/^([0-9]+) blob [a-f0-9]+\t(.*)$/s', $entry, $match) === 1) {
                $tracked[$match[2]] = $match[1];
            }
        }
        $changedPaths = array_fill_keys(explode("\0", $delta['stdout']), true);
        $oldDeclarations = [];
        $currentDeclarations = [];
        foreach ($selected as $path => $extension) {
            if (isset($tracked[$path]) && !isset($changedPaths[$path])) {
                continue;
            }
            $before = '';
            if (isset($tracked[$path])) {
                if (!in_array($tracked[$path], ['100644', '100755'], true)) {
                    throw new \RuntimeException('Unsupported HEAD source mode.');
                }
                $blob = $git(['cat-file', 'blob', 'HEAD:' . $path], $root);
                if ($blob['status'] !== 0) {
                    throw new \RuntimeException('Required HEAD blob unreadable.');
                }
                $before = $blob['stdout'];
            }
            $after = file_get_contents($root . '/' . $path);
            if (!is_string($after)) {
                throw new \RuntimeException('Current source unreadable.');
            }
            if ($before === $after) {
                continue;
            }
            $supported = in_array($extension, ['php', 'js', 'mjs', 'cjs', 'html', 'htm'], true);
            if (in_array($extension, ['css', 'yml', 'yaml', 'svg', 'tex', 'htaccess'], true)) {
                continue; // Native header contract owns these non-application-callable formats.
            }
            if (!$supported && source_without_header($before, $extension) === source_without_header($after, $extension)) {
                continue;
            }
            $report['summary']['changed_files']++;
            if (!$supported) {
                $report['blocked'][] = ['path' => $path, 'reason' => 'Changed source body has no declaration parser; coverage unknown.'];
                continue;
            }
            if ($extension === 'php') {
                token_get_all($after, TOKEN_PARSE);
                if ($before !== '') {
                    token_get_all($before, TOKEN_PARSE);
                }
            }
            array_push($oldDeclarations, ...source_declaration_snapshots($before, $path));
            array_push($currentDeclarations, ...source_declaration_snapshots($after, $path));
        }
        if ($paths === []) {
            foreach ($tracked as $path => $mode) {
                if (!isset($changedPaths[$path]) || isset($inventory['files'][$path])
                    || !source_head_declaration_allowed($path) || !in_array($mode, ['100644', '100755'], true)) {
                    continue;
                }
                $blob = $git(['cat-file', 'blob', 'HEAD:' . $path], $root);
                if ($blob['status'] !== 0) {
                    throw new \RuntimeException('Deleted HEAD source unreadable.');
                }
                array_push($oldDeclarations, ...source_declaration_snapshots($blob['stdout'], $path));
            }
        }
        $result = compare_declaration_snapshots($oldDeclarations, $currentDeclarations);
        foreach (['added', 'changed', 'unchanged', 'moved', 'doc_regressions'] as $counter) {
            $report['summary'][$counter] = $result[$counter];
        }
        array_push($report['findings'], ...$result['findings']);
        array_push($report['blocked'], ...$result['blocked']);
        $report['summary']['finding_count'] = count($report['findings']);
        $report['status'] = $report['blocked'] !== [] ? 'BLOCKED' : ($report['findings'] !== [] ? 'FAIL' : 'PASS');
    } catch (\Throwable $error) {
        $report['blocked'][] = ['path' => '', 'reason' => 'Git HEAD/source comparison could not complete; coverage unknown.'];
        $report['summary']['finding_count'] = count($report['findings']);
        $report['status'] = 'BLOCKED';
    }
    return $report;
}
