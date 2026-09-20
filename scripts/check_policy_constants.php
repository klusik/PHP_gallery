<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/check_policy_constants.php
 * Module Type: Policy Constant Inventory CLI
 * Purpose:
 *   Find definition owners and semantic literal candidates without exposing values.
 * Responsibilities:
 *   - Distinguish inventory evidence from reviewed policy violations.
 * Author:
 *   Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\SourceContracts;

require_once __DIR__ . '/check_source_documentation.php';
require_once __DIR__ . '/source_contracts/policy_changes.php';

/**
 * Recognize operational time/size/budget names instead of searching every digit.
 * @param string $name Source identifier, never its value.
 * @return bool Whether a semantic policy name warrants review.
 */
function policy_name(string $name): bool
{
    return preg_match('/timeout|delay|retr(?:y|ies)|retention|threshold|budget|ttl|limit|batch|chunk|bytes|pixels|seconds|millis|duration|concurrency|(?:^|[_.])(?:min|max)(?:[_.]|$)/i', $name) === 1;
}

/**
 * Inventory tokenized PHP/JS definitions and narrowly selected literal use sites.
 * @param string $source First-party source, never executed.
 * @param string $path Relative source identity.
 * @return array{definitions:list<array<string,mixed>>,findings:list<array<string,mixed>>,identifiers:list<string>}
 */
function policy_source(string $source, string $path): array
{
    $php = pathinfo($path, PATHINFO_EXTENSION) === 'php';
    $tokens = $php ? php_tokens($source) : javascript_tokens($source);
    $pairs = delimiter_pairs($tokens);
    $definitions = [];
    $findings = [];
    $identifiers = [];
    foreach ($tokens as $index => $token) {
        if (in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $parts = explode('\\', $token['text']);
            $identifiers[(string) end($parts)] = true;
        }
        $name = '';
        if (($php && $token['id'] === T_CONST && ($tokens[$index - 1]['id'] ?? 0) !== T_USE)
            || (!$php && $token['text'] === 'const')) {
            if (($tokens[$index + 1]['id'] ?? 0) === T_STRING && ($tokens[$index + 2]['text'] ?? '') === '=') {
                $name = $tokens[$index + 1]['text'];
            }
        } elseif ($php && strtolower($token['text']) === 'define' && ($tokens[$index + 1]['text'] ?? '') === '('
            && ($tokens[$index + 2]['id'] ?? 0) === T_CONSTANT_ENCAPSED_STRING
            && !in_array($tokens[$index - 1]['text'] ?? '', ['->', '::', 'function'], true)) {
            $name = trim($tokens[$index + 2]['text'], "'" . chr(34));
        }
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $name) === 1) {
            $doc = attached_doc($tokens, $index, $pairs);
            if ($doc === '') {
                for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
                    if (in_array($tokens[$cursor]['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
                        $doc = $tokens[$cursor]['text'] . "\n" . $doc;
                    } elseif ($tokens[$cursor]['text'] !== 'export') {
                        break;
                    }
                }
            }
            $missing = [];
            foreach (['type' => '/@var\s+\S+|\btype\s*:/i', 'units' => '/\bunits?\s*:/i',
                'scope' => '/\bscope\s*:/i', 'consumers' => '/\bconsumers?\s*:/i', 'rationale' => '/\brationale\s*:/i'] as $field => $pattern) {
                if (preg_match($pattern, $doc) !== 1) {
                    $missing[] = $field;
                }
            }
            $definitions[] = ['path' => $path, 'line' => $token['line'], 'name' => $name, 'missing_documentation' => $missing];
        }
        if (in_array($token['id'], [T_VARIABLE, T_STRING], true) && policy_name($token['text'])
            && ($tokens[$index + 1]['text'] ?? '') === '='
            && in_array($tokens[$index + 2]['id'] ?? 0, [T_LNUMBER, T_DNUMBER], true)
            && !in_array($tokens[$index + 2]['text'], ['0', '1', '0.0', '1.0'], true)
            && ($tokens[$index - 1]['text'] ?? '') !== 'const') {
            $findings[] = ['path' => $path, 'line' => $token['line'], 'name' => $token['text'], 'rule' => 'policy.named_numeric_assignment'];
        }
        $parameter = match (strtolower($token['text'])) {
            'sleep', 'usleep', 'set_time_limit' => $php ? 0 : null,
            'settimeout', 'setinterval' => !$php ? 1 : null,
            default => null,
        };
        if ($parameter !== null && ($tokens[$index + 1]['text'] ?? '') === '(') {
            $segments = parameter_segments($tokens, $index + 1, $pairs);
            $argument = $segments[$parameter] ?? [];
            if (count($argument) === 1 && in_array($argument[0]['id'], [T_LNUMBER, T_DNUMBER], true)) {
                $findings[] = ['path' => $path, 'line' => $token['line'], 'name' => $token['text'], 'rule' => 'policy.timer_literal'];
            }
        }
    }
    return ['definitions' => $definitions, 'findings' => $findings, 'identifiers' => array_keys($identifiers)];
}

/**
 * Inventory constant owners, lexical consumers, and explicitly advisory candidates.
 * @param string $root Repository or disposable fixture root.
 * @param list<string> $paths Optional exact relative paths.
 * @return array<string,mixed> Inventory, summary, definitions, findings and coverage limits.
 */
function policy_report(string $root, array $paths = []): array
{
    $inventory = inventory($root);
    $selected = $paths === [] ? $inventory['files'] : array_intersect_key($inventory['files'], array_fill_keys($paths, true));
    if (array_diff($paths, array_keys($selected)) !== []) {
        throw new \RuntimeException('Selected source is absent or excluded.');
    }
    $definitions = [];
    $findings = [];
    $consumers = [];
    $languages = [];
    foreach ($selected as $path => $extension) {
        $languages[$extension] = ($languages[$extension] ?? 0) + 1;
        $source = file_get_contents($root . '/' . $path);
        if (!is_string($source)) {
            throw new \RuntimeException('Unable to read inventoried source.');
        }
        if (in_array($extension, ['php', 'js', 'mjs', 'cjs'], true)) {
            $result = policy_source($source, $path);
            array_push($definitions, ...$result['definitions']);
            array_push($findings, ...$result['findings']);
            foreach ($result['identifiers'] as $name) {
                $consumers[$name][] = $path;
            }
        } elseif ($extension === 'css') {
            $source = preg_replace_callback('~/\*[\s\S]*?\*/~',
                /**
                 * Mask CSS comments without changing original line coordinates.
                 * @param array<int,string> $match Complete comment match.
                 * @return string Newline separators occupying the same source lines.
                 */
                static fn(array $match): string => str_repeat("\n", substr_count($match[0], "\n")), $source) ?? $source;
            preg_match_all('/(?:transition|animation)(?:-duration|-delay)?\s*:[^;{}]*\b\d+(?:\.\d+)?m?s\b/i', $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$text, $offset]) {
                $findings[] = ['path' => $path, 'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                    'name' => 'css-duration', 'rule' => 'policy.css_duration_review'];
            }
        } elseif (in_array($extension, ['py', 'pyw', 'ps1', 'psm1', 'sh', 'bat', 'cmd'], true)) {
            preg_match_all('/^[ \t]*(?:export\s+|set\s+)?\$?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*\d+/mi', $source, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[1] as [$name, $offset]) {
                if (policy_name($name)) {
                    $findings[] = ['path' => $path, 'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                        'name' => $name, 'rule' => 'policy.script_assignment_review'];
                }
            }
        }
    }
    $owners = [];
    foreach ($definitions as $definition) {
        $owners[$definition['name']][] = $definition['path'];
        if ($definition['missing_documentation'] !== []) {
            $findings[] = ['path' => $definition['path'], 'line' => $definition['line'], 'name' => $definition['name'],
                'rule' => 'constant.documentation', 'missing' => $definition['missing_documentation']];
        }
    }
    foreach ($definitions as &$definition) {
        $definition['lexical_consumers'] = array_values(array_diff($consumers[$definition['name']] ?? [], [$definition['path']]));
        if (count($owners[$definition['name']]) > 1) {
            $findings[] = ['path' => $definition['path'], 'line' => $definition['line'], 'name' => $definition['name'],
                'rule' => 'constant.duplicate_name_review'];
        }
    }
    unset($definition);
    $rules = [];
    foreach ($findings as $finding) {
        $rules[$finding['rule']] = ($rules[$finding['rule']] ?? 0) + 1;
    }
    ksort($languages);
    ksort($rules);
    return ['inventory' => $inventory, 'summary' => ['source_files' => count($selected), 'languages' => $languages,
        'definitions' => count($definitions), 'finding_count' => count($findings), 'rules' => $rules],
        'definitions' => $definitions, 'findings' => $findings,
        'coverage' => [
            'php_js' => 'Tokenized uppercase const/define; semantic numeric assignments and timer arguments. Multi-constant declarations, dynamic names and property constants require further coverage.',
            'css' => 'Duration candidates only; visual literals are not automatically violations.',
            'scripts' => 'Assignment review only; no Python/PowerShell/shell parser, so strings/here-docs may yield false positives.',
            'ownership' => 'Same names and lexical consumers are evidence, not resolved symbols. Namespaces, classes, conditional guards and independent modules may share names.',
            'semantics' => 'No values or snippets emitted. Equal numbers are not equivalent meanings. Semantic strings and configurable-map entries require owner review.',
        ]];
}

/**
 * Run advisory whole-tree inventory or strict HEAD-based runtime policy enforcement.
 * @param list<string> $argv Process arguments.
 * @return int Zero for inventory success or bounded gate PASS, one for strict findings, two for blocked coverage/errors.
 */
function policy_main(array $argv): int
{
    try {
        $options = report_options($argv);
        if ($options['changed']) {
            $report = changed_policy_report($options['root'], $options['paths']);
            print_report($report, $options['json'], 'Changed runtime policy gate ' . $report['status']);
            return match ($report['status']) {
                'PASS' => 0,
                'FAIL' => 1,
                default => 2,
            };
        }
        $report = policy_report($options['root'], $options['paths']);
        print_report($report, $options['json'], 'Policy constant inventory');
        return $options['strict'] && $report['findings'] !== [] ? 1 : 0;
    } catch (\Throwable $error) {
        fwrite(STDERR, "Policy constant inventory could not complete; check options and source readability.\n");
        return 2;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(policy_main($argv));
}
