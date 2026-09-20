<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/source_contracts/policy_scan.php
 * Module Type: Changed Policy Site Scanner
 * Purpose: Identify bounded runtime policy sites without executing or disclosing values.
 * Responsibilities:
 *   - Tokenize uppercase definitions, operational assignments and direct timers.
 *   - Attach substantive policy documentation and scope-aware site identities.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\SourceContracts;

/**
 * Select application runtime ownership independently of tooling and fixture paths.
 * @param string $path Discovered repository-relative path.
 * @return bool Whether the application or a served asset owns this source.
 */
function policy_runtime_path(string $path): bool
{
    return str_starts_with($path, 'app/') || str_starts_with($path, 'public/') || $path === 'index.php';
}

/**
 * Validate explanatory policy fields without accepting empty labels as documentation.
 * @param string $doc Attached native documentation, never included in report output.
 * @return list<string> Missing purpose/type/units/scope/consumers/rationale fields.
 */
function policy_missing_explanation(string $doc): array
{
    $clean = trim(preg_replace('~^\s*(?:/\*+|\*+/|//|\*)\s?~m', '', $doc) ?? $doc);
    $clean = preg_replace('/\bScope\/consumers\s*:\s*([^\r\n]+)/i', 'Scope: $1 Consumers: $1', $clean) ?? $clean;
    $clean = preg_replace('/\bCompatibility\s*:/i', 'Rationale:', $clean) ?? $clean;
    $labels = 'type|units?|scope|consumers?|rationale';
    $summary = trim(preg_split('/@|(?:\b(?:' . $labels . ')\s*:)/i', $clean, 2)[0] ?? '');
    $missing = [];
    if (strlen($summary) < 12 || preg_match('/^(?:TODO|FIXME)\b|^Handles .+ logic for the gallery application\./i', $summary) === 1) {
        $missing[] = 'purpose';
    }
    $fields = [];
    preg_match_all('/\b(' . $labels . ')\s*:\s*(.*?)(?=\b(?:' . $labels . ')\s*:|\s+@\w|$)/si', $clean, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        $field = strtolower($match[1]);
        $field = $field === 'unit' ? 'units' : ($field === 'consumer' ? 'consumers' : $field);
        $value = trim($match[2], " \t\r\n*/");
        if ($value !== '' && preg_match('/^(?:TODO|FIXME|TBD|[-?]+)[.!]?$/i', $value) !== 1) {
            $fields[$field] = true;
        }
    }
    if (preg_match('/@(?:var|type)\s+(\{[^}]+\}|[^\s@]+)/', $clean, $type) === 1
        && !str_ends_with($type[1], ':')) {
        $fields['type'] = true;
    }
    foreach (['type', 'units', 'scope', 'consumers', 'rationale'] as $field) {
        if (!isset($fields[$field])) {
            $missing[] = $field;
        }
    }
    return $missing;
}

/**
 * Collect immediately preceding comments across declaration modifiers only.
 * @param list<array<string,mixed>> $tokens Original comment-preserving tokens.
 * @param int $start Original declaration or assignment start.
 * @param array<int,int> $pairs Original delimiter index.
 * @return string Attached documentation; file headers and intervening code are rejected.
 */
function policy_site_documentation(array $tokens, int $start, array $pairs): string
{
    $start = declaration_start($tokens, $start, $pairs);
    $comments = [];
    for ($index = $start - 1; $index >= 0; $index--) {
        if (!in_array($tokens[$index]['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
            break;
        }
        if (str_contains($tokens[$index]['text'], 'Project: PHP Gallery')
            && (str_contains($tokens[$index]['text'], 'File:') || str_contains($tokens[$index]['text'], 'Repository:'))) {
            break;
        }
        $comments[] = $tokens[$index]['text'];
        if ($tokens[$index]['id'] === T_DOC_COMMENT) {
            break; // A preceding file/sibling docblock must not invalidate or enlarge this contract.
        }
    }
    $doc = implode("\n", array_reverse($comments));
    return $doc;
}

/**
 * Bound one policy initializer without consuming a later comma-separated declaration.
 * @param list<array<string,mixed>> $tokens Significant source tokens.
 * @param int $start Initializer start.
 * @param array<int,int> $pairs Significant delimiter index.
 * @param bool $php Whether PHP rather than JavaScript syntax applies.
 * @return int Inclusive expression end, excluding separators.
 */
function policy_expression_end(array $tokens, int $start, array $pairs, bool $php): int
{
    for ($index = $start; $index < count($tokens); $index++) {
        if (!$php && $tokens[$index]['id'] === 0 && in_array($tokens[$index]['text'], ['(', '[', '{'], true) && !isset($pairs[$index])) {
            throw new \RuntimeException('Unbalanced JavaScript policy initializer.');
        }
        if (in_array($tokens[$index]['text'], [';', ',', ')', ']', '}'], true)) {
            return $index - 1;
        }
        if (!$php && $index > $start && $tokens[$index]['line'] > $tokens[$index - 1]['line']
            && in_array($tokens[$index]['text'], ['const', 'let', 'var', 'export', 'import', 'function', 'return'], true)) {
            return $index - 1;
        }
        if (isset($pairs[$index]) && $pairs[$index] > $index) {
            $index = $pairs[$index];
        }
    }
    return count($tokens) - 1;
}

/**
 * Recognize direct numeric expressions, excluding names, calls and arbitrary digits.
 * @param list<array<string,mixed>> $tokens One significant initializer/argument.
 * @return bool Whether every token belongs to a literal arithmetic expression.
 */
function policy_numeric_expression(array $tokens): bool
{
    $number = false;
    foreach ($tokens as $token) {
        if (in_array($token['id'], [T_LNUMBER, T_DNUMBER], true)) {
            $number = true;
        } elseif (!in_array($token['text'], ['+', '-', '*', '/', '%', '**', '<<', '>>', '(', ')'], true)) {
            return false;
        }
    }
    return $number;
}

/**
 * Find the namespace and closest callable/class owner of a policy site.
 * @param list<array<string,mixed>> $original Comment-preserving source tokens.
 * @param int $index Original site index.
 * @param list<array<string,mixed>> $declarations Shared syntax-aware declaration snapshots.
 * @param bool $php Whether PHP namespaces apply.
 * @return string Namespace/containing declaration identity without source values.
 */
function policy_site_owner(array $original, int $index, array $declarations, bool $php): string
{
    $owner = '';
    if ($php) {
        for ($cursor = 0; $cursor < $index; $cursor++) {
            if ($original[$cursor]['id'] === T_NAMESPACE) {
                $owner = '';
                while (++$cursor < $index && !in_array($original[$cursor]['text'], [';', '{'], true)) {
                    $owner .= $original[$cursor]['text'];
                }
            }
        }
    }
    $nearest = -1;
    foreach ($declarations as $declaration) {
        $record = $declaration['record'];
        if ($record['start_token'] < $index && $record['end_token'] >= $index
            && $record['start_token'] > $nearest && $record['kind'] !== 'property') {
            $owner = $declaration['identity'];
            $nearest = $record['start_token'];
        }
    }
    return $owner;
}

/**
 * Tokenize policy sites for scoped comparison; hashes remain internal evidence only.
 * @param string $source Complete PHP or JavaScript runtime source, never executed.
 * @param string $path Discovered repository-relative identity.
 * @return array{snapshots:list<array<string,mixed>>,review:array<string,int>} Comparable sites and explicitly unclassified map/dynamic sites.
 */
function policy_site_snapshots(string $source, string $path): array
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $php = $extension === 'php';
    if ($php && $source !== '') {
        token_get_all($source, TOKEN_PARSE);
    }
    $original = $php ? php_tokens($source) : javascript_tokens($source);
    $originalPairs = delimiter_pairs($original);
    $tokens = [];
    foreach ($original as $index => $token) {
        if (!in_array($token['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
            $token['source_index'] = $index;
            $tokens[] = $token;
        }
    }
    $pairs = delimiter_pairs($tokens);
    $declarations = declaration_snapshots($source, $extension);
    $sites = [];
    $definitions = [];
    $review = [];
    foreach ($tokens as $index => $token) {
        $previous = $tokens[$index - 1]['text'] ?? '';
        $keyword = ($php && $token['id'] === T_CONST && $previous !== 'use')
            || (!$php && $token['id'] === T_STRING && in_array($token['text'], ['const', 'let', 'var'], true));
        if ($keyword) {
            $flavor = $token['text'];
            $prefixStart = declaration_start($original, $token['source_index'], $originalPairs);
            for ($prefix = $prefixStart; $prefix < $token['source_index']; $prefix++) {
                if (!in_array($original[$prefix]['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $flavor .= ':' . $original[$prefix]['text'];
                }
            }
            $nameIndex = $index + 1;
            while (($tokens[$nameIndex]['id'] ?? 0) === T_STRING && ($tokens[$nameIndex + 1]['text'] ?? '') === '=') {
                $end = policy_expression_end($tokens, $nameIndex + 2, $pairs, $php);
                if (preg_match('/^[A-Z_][A-Z0-9_]*$/D', $tokens[$nameIndex]['text']) === 1) {
                    $definitions[$nameIndex] = true;
                    $sites[] = ['kind' => 'definition', 'name' => $tokens[$nameIndex]['text'], 'start' => $nameIndex,
                        'end' => $end, 'doc_start' => $nameIndex === $index + 1 ? $index : $nameIndex,
                        'flavor' => $flavor];
                }
                if (($tokens[$end + 1]['text'] ?? '') !== ',') {
                    break;
                }
                $nameIndex = $end + 2;
            }
        }
        if ($php && in_array($token['id'], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            && strtolower(ltrim($token['text'], '\\')) === 'define'
            && ($tokens[$index + 1]['text'] ?? '') === '('
            && !in_array($previous, ['->', '?->', '::', 'function'], true)) {
            $segments = parameter_segments($tokens, $index + 1, $pairs);
            $literal = $segments[0] ?? [];
            $name = count($literal) === 1 && $literal[0]['id'] === T_CONSTANT_ENCAPSED_STRING
                ? trim($literal[0]['text'], "'" . chr(34)) : '';
            if (preg_match('/^[A-Z_][A-Z0-9_]*$/D', $name) === 1) {
                $sites[] = ['kind' => 'definition', 'name' => $name, 'start' => $index,
                    'end' => $pairs[$index + 1], 'doc_start' => $index, 'flavor' => 'define'];
            } else {
                $review['dynamic_or_qualified_define'] = ($review['dynamic_or_qualified_define'] ?? 0) + 1;
            }
        }
    }
    foreach ($tokens as $index => $token) {
        if (in_array($token['id'], [T_VARIABLE, T_STRING], true) && policy_name($token['text'])
            && ($tokens[$index + 1]['text'] ?? '') === '=' && !isset($definitions[$index])) {
            $end = policy_expression_end($tokens, $index + 2, $pairs, $php);
            $expression = array_slice($tokens, $index + 2, max(0, $end - $index - 1));
            $single = count($expression) === 1 ? $expression[0]['text'] : '';
            if (policy_numeric_expression($expression) && !in_array($single, ['0', '1', '0.0', '1.0'], true)) {
                $start = $index;
                while ($start >= 2 && in_array($tokens[$start - 1]['text'], ['.', '->', '::'], true)
                    && in_array($tokens[$start - 2]['id'], [T_STRING, T_VARIABLE], true)) {
                    $start -= 2;
                }
                $docStart = !$php && in_array($tokens[$start - 1]['text'] ?? '', ['const', 'let', 'var'], true) ? $start - 1 : $start;
                $sites[] = ['kind' => 'assignment', 'name' => $token['text'], 'start' => $start,
                    'end' => $end, 'doc_start' => $docStart, 'flavor' => 'assignment'];
            }
        }
        // Configurable maps and object properties are disclosed, not digit-scanned as violations.
        if (in_array($token['id'], [T_CONSTANT_ENCAPSED_STRING, T_STRING], true)
            && policy_name(trim($token['text'], "'" . chr(34)))
            && in_array($tokens[$index + 1]['text'] ?? '', ['=>', ':'], true)
            && in_array($tokens[$index + 2]['id'] ?? 0, [T_LNUMBER, T_DNUMBER], true)) {
            $review['numeric_policy_map_entry'] = ($review['numeric_policy_map_entry'] ?? 0) + 1;
        }
        if (!in_array($token['id'], [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            || ($tokens[$index + 1]['text'] ?? '') !== '(') {
            continue;
        }
        $name = strtolower(ltrim($token['text'], '\\'));
        $parameter = $php ? (in_array($name, ['sleep', 'usleep', 'set_time_limit'], true) ? 0 : null)
            : (in_array($name, ['settimeout', 'setinterval'], true) ? 1 : null);
        $previous = $tokens[$index - 1]['text'] ?? '';
        if ($parameter === null || in_array($previous, ['function', '->', '?->', '::'], true)
            || (!$php && $previous === '.' && (!in_array($tokens[$index - 2]['text'] ?? '', ['window', 'globalThis', 'self'], true)
                || in_array($tokens[$index - 3]['text'] ?? '', ['.', '?.'], true)))) {
            continue;
        }
        if (!isset($pairs[$index + 1])) {
            throw new \RuntimeException('Unbalanced runtime timer policy call.');
        }
        $segments = parameter_segments($tokens, $index + 1, $pairs);
        if (policy_numeric_expression($segments[$parameter] ?? [])) {
            $start = !$php && $previous === '.' ? $index - 2 : $index;
            $docStart = $start;
            if (($tokens[$start - 1]['text'] ?? '') === '='
                && in_array($tokens[$start - 2]['id'] ?? 0, [T_STRING, T_VARIABLE], true)) {
                $docStart = $start - 2;
                if (!$php && in_array($tokens[$docStart - 1]['text'] ?? '', ['const', 'let', 'var'], true)) {
                    $docStart--;
                }
            }
            $sites[] = ['kind' => 'timer', 'name' => $name, 'start' => $start,
                'end' => $pairs[$index + 1], 'doc_start' => $docStart, 'flavor' => 'timer'];
        }
    }
    $snapshots = [];
    foreach ($sites as $site) {
        $originalIndex = $tokens[$site['start']]['source_index'];
        $owner = policy_site_owner($original, $originalIndex, $declarations, $php);
        $identity = ($php ? 'php:' : 'js:') . $owner . '/' . $site['kind'] . ':' . $site['name'];
        $doc = policy_site_documentation($original, $tokens[$site['doc_start']]['source_index'], $originalPairs);
        $executable = [$site['flavor']];
        foreach (array_slice($tokens, $site['start'], $site['end'] - $site['start'] + 1) as $part) {
            $executable[] = [$part['id'], $part['text']];
        }
        $snapshots[] = ['path' => $path, 'identity' => $identity,
            'fingerprint' => hash('sha256', json_encode($executable, JSON_THROW_ON_ERROR)), 'uncertain' => false,
            'record' => ['kind' => $site['kind'], 'name' => $site['name'], 'line' => $tokens[$site['start']]['line'],
                'missing' => policy_missing_explanation($doc)]];
    }
    return ['snapshots' => $snapshots, 'review' => $review];
}

/**
 * Convert one site's missing explanation into stable value-free enforcement rules.
 * @param array{kind:string,name:string,line:int,missing:list<string>} $record Policy site contract.
 * @return list<string> Empty for explained sites, otherwise a bounded actionable rule.
 */
function policy_site_issues(array $record): array
{
    if ($record['missing'] === []) {
        return [];
    }
    $rule = match ($record['kind']) {
        'definition' => 'constant.documentation',
        'timer' => 'policy.timer_literal',
        default => 'policy.named_numeric_assignment',
    };
    $issues = [];
    foreach ($record['missing'] as $field) {
        $issues[] = $rule . ':' . $field;
    }
    return $issues;
}
