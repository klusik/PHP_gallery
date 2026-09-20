<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/source_contracts/php.php
 * Module Type: PHP Source Contract Scanner
 * Purpose:
 *   Inspect declarations and signatures without including application code.
 * Responsibilities:
 *   - Tokenize PHP declarations, attributes, closures, fields, and documentation.
 * Author:
 *   Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\SourceContracts;

/**
 * Normalize PHP tokens while retaining comments and original source coordinates.
 * @param string $source PHP source, including its opening tag.
 * @return list<array{id:int,text:string,line:int,offset:int}> Whitespace-free tokens.
 */
function php_tokens(string $source): array
{
    $result = [];
    $offset = 0;
    $line = 1;
    foreach (token_get_all($source) as $token) {
        $id = is_array($token) ? $token[0] : 0;
        $text = is_array($token) ? $token[1] : $token;
        if ($id !== T_WHITESPACE) {
            $result[] = ['id' => $id, 'text' => $text, 'line' => $line, 'offset' => $offset];
        }
        $offset += strlen($text);
        $line += substr_count($text, "\n");
    }
    return $result;
}

/**
 * Index matched delimiters so nested defaults/attributes do not alter signatures.
 * @param list<array{id:int,text:string,line:int,offset:int}> $tokens Normalized tokens.
 * @return array<int,int> Bidirectional matching token indices.
 */
function delimiter_pairs(array $tokens): array
{
    $stack = [];
    $pairs = [];
    foreach ($tokens as $index => $token) {
        $text = $token['text'];
        if (in_array($text, ['(', '[', '{', '#['], true)) {
            $stack[] = [$index, $text === '#[' ? '[' : $text];
        } elseif (in_array($text, [')', ']', '}'], true)) {
            $expected = [')' => '(', ']' => '[', '}' => '{'][$text];
            if ($stack !== [] && $stack[count($stack) - 1][1] === $expected) {
                [$open] = array_pop($stack);
                $pairs[$open] = $index;
                $pairs[$index] = $open;
            }
        }
    }
    return $pairs;
}

/**
 * Locate attached documentation across modifiers and PHP attributes.
 * @param list<array{id:int,text:string,line:int,offset:int}> $tokens Normalized tokens.
 * @param int $index Declaration start index.
 * @param array<int,int> $pairs Matched delimiters.
 * @return string Attached docblock, excluding unrelated preceding declarations.
 */
function attached_doc(array $tokens, int $index, array $pairs): string
{
    $modifiers = ['public', 'protected', 'private', 'static', 'final', 'abstract', 'readonly', 'export', 'default', 'async', 'get', 'set', '*'];
    for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
        $token = $tokens[$cursor];
        if ($token['id'] === T_DOC_COMMENT) {
            if (str_contains($token['text'], 'Project: PHP Gallery') && str_contains($token['text'], 'File:')) {
                return '';
            }
            return $token['text'];
        }
        if (in_array(strtolower($token['text']), $modifiers, true)) {
            continue;
        }
        if ($token['text'] === ']' && isset($pairs[$cursor]) && $tokens[$pairs[$cursor]]['text'] === '#[') {
            $cursor = $pairs[$cursor];
            continue;
        }
        break;
    }
    return '';
}

/**
 * Split parameter tokens at top-level commas, skipping nested default expressions.
 * @param list<array{id:int,text:string,line:int,offset:int}> $tokens Normalized tokens.
 * @param int $open Opening parenthesis index.
 * @param array<int,int> $pairs Matched delimiters.
 * @return list<list<array{id:int,text:string,line:int,offset:int}>> Parameter segments.
 */
function parameter_segments(array $tokens, int $open, array $pairs): array
{
    $segments = [];
    $start = $open + 1;
    $end = $pairs[$open] ?? $open;
    for ($cursor = $start; $cursor < $end; $cursor++) {
        if (isset($pairs[$cursor]) && $pairs[$cursor] > $cursor) {
            $cursor = $pairs[$cursor];
        } elseif ($tokens[$cursor]['text'] === ',') {
            $segments[] = array_slice($tokens, $start, $cursor - $start);
            $start = $cursor + 1;
        }
    }
    if ($start < $end) {
        $segments[] = array_slice($tokens, $start, $end - $start);
    }
    return $segments;
}

/**
 * Include signature modifiers and attributes in an executable declaration span.
 * @param list<array{id:int,text:string,line:int,offset:int}> $tokens Source tokens.
 * @param int $start First declaration/name token.
 * @param array<int,int> $pairs Matched delimiters.
 * @return int First executable token of the declaration, excluding its docblock.
 */
function declaration_start(array $tokens, int $start, array $pairs): int
{
    while ($start > 0) {
        $previous = $tokens[$start - 1];
        if (in_array(strtolower($previous['text']), ['public', 'protected', 'private', 'static', 'final', 'abstract', 'readonly', 'export', 'default', 'async', 'get', 'set', '*'], true)) {
            $start--;
        } elseif ($previous['text'] === ']' && isset($pairs[$start - 1])
            && $tokens[$pairs[$start - 1]]['text'] === '#[') {
            $start = $pairs[$start - 1];
        } else {
            break;
        }
    }
    return $start;
}

/**
 * Bound a declaration body or expression without consuming a following sibling.
 * @param list<array{id:int,text:string,line:int,offset:int}> $tokens Source tokens.
 * @param int $start First body/expression token, or declaration terminator.
 * @param array<int,int> $pairs Matched delimiters.
 * @return int Inclusive last token, skipping nested expression delimiters.
 */
function declaration_end(array $tokens, int $start, array $pairs): int
{
    if (($tokens[$start]['text'] ?? '') === '{' && isset($pairs[$start])) {
        return $pairs[$start];
    }
    for ($cursor = $start; $cursor < count($tokens); $cursor++) {
        if (in_array($tokens[$cursor]['text'], [';', ',', ')', ']', '}'], true)) {
            return max($start, $cursor - ($tokens[$cursor]['text'] === ';' ? 0 : 1));
        }
        if (isset($pairs[$cursor]) && $pairs[$cursor] > $cursor) {
            $cursor = $pairs[$cursor];
        }
    }
    return max($start, count($tokens) - 1);
}

/**
 * Find parameters with no direct body use or recognized argument/scope introspection.
 * This conservative evidence supports an explicit unrestricted-unused contract,
 * not a generic exception for mixed records or forwarded values.
 * @param list<array{id:int,text:string,line:int,offset:int}> $tokens PHP source tokens.
 * @param int $start Body/expression start, after the signature.
 * @param int $end Inclusive body/expression end.
 * @param list<array{name:string,type:string}> $parameters Declared parameters.
 * @return list<string> Names unused by this body; dynamic introspection yields none.
 */
function unused_parameter_names(array $tokens, int $start, int $end, array $parameters): array
{
    $unused = array_fill_keys(array_column($parameters, 'name'), true);
    for ($index = $start; $index <= $end && isset($tokens[$index]); $index++) {
        $token = $tokens[$index];
        if ($token['id'] === T_VARIABLE) {
            unset($unused[ltrim($token['text'], '$')]);
        }
        if ($token['text'] === '$' || in_array($token['id'], [T_EVAL, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            return [];
        }
        if (in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            $parts = explode('\\', strtolower($token['text']));
            if (in_array(end($parts), ['func_get_args', 'func_get_arg', 'get_defined_vars', 'compact', 'debug_backtrace', 'debug_print_backtrace'], true)) {
                return [];
            }
        }
    }
    return array_keys($unused);
}

/**
 * Inventory PHP callables, classes/interfaces/traits/enums, and declared properties.
 *
 * Anonymous callbacks are counted separately so legacy named-callable enforcement
 * does not silently claim to cover them. Class fields exclude local variables.
 *
 * @param string $source Complete PHP text; no runtime code is executed.
 * @return list<array{kind:string,name:string,line:int,params:list<array{name:string,type:string}>,return_type:string,doc:string,start_token:int,end_token:int,unused_params?:list<string>}> Contracts, token spans and conservative unused-parameter evidence.
 */
function php_declarations(string $source): array
{
    $tokens = php_tokens($source);
    $pairs = delimiter_pairs($tokens);
    $records = [];
    $classBodies = [];
    $functionBodies = [];
    $scope = [];
    $count = count($tokens);
    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];
        if ($token['text'] === '{') {
            $scope[] = isset($classBodies[$index]) ? 'class' : (isset($functionBodies[$index]) ? 'function' : 'block');
        } elseif ($token['text'] === '}') {
            array_pop($scope);
        }
        if (in_array($token['id'], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
            && ($tokens[$index - 1]['text'] ?? '') !== '::') {
            $name = ($tokens[$index + 1]['id'] ?? 0) === T_STRING ? $tokens[$index + 1]['text'] : '(anonymous class)';
            $records[] = ['kind' => strtolower(substr(token_name($token['id']), 2)), 'name' => $name, 'line' => $token['line'],
                'params' => [], 'return_type' => '', 'doc' => attached_doc($tokens, $index, $pairs),
                'start_token' => declaration_start($tokens, $index, $pairs), 'end_token' => $index];
            for ($cursor = $index + 1; $cursor < $count; $cursor++) {
                if ($tokens[$cursor]['text'] === '{') {
                    $classBodies[$cursor] = true;
                    $records[count($records) - 1]['end_token'] = $pairs[$cursor] ?? $cursor;
                    break;
                }
                if (isset($pairs[$cursor]) && $pairs[$cursor] > $cursor) {
                    $cursor = $pairs[$cursor];
                }
            }
        }
        if ($token['id'] === T_VARIABLE && end($scope) === 'class') {
            $start = $index;
            while ($start > 0 && !in_array($tokens[$start - 1]['text'], [';', '{', '}'], true) && $tokens[$start - 1]['id'] !== T_DOC_COMMENT) {
                $start--;
            }
            $records[] = ['kind' => 'property', 'name' => $token['text'], 'line' => $token['line'],
                'params' => [], 'return_type' => '', 'doc' => attached_doc($tokens, $start, $pairs),
                'start_token' => $start, 'end_token' => declaration_end($tokens, $index, $pairs)];
        }
        if (!in_array($token['id'], [T_FUNCTION, T_FN], true)) {
            continue;
        }
        $cursor = $index + 1;
        if (($tokens[$cursor]['text'] ?? '') === '&') {
            $cursor++;
        }
        $named = ($tokens[$cursor]['id'] ?? 0) === T_STRING;
        $name = $named ? $tokens[$cursor++]['text'] : '(anonymous)';
        $open = $cursor;
        if (($tokens[$open]['text'] ?? '') !== '(' || !isset($pairs[$open])) {
            continue;
        }
        $params = [];
        foreach (parameter_segments($tokens, $open, $pairs) as $segment) {
            $type = '';
            $attributeDepth = 0;
            foreach ($segment as $part) {
                if ($part['text'] === '#[') {
                    $attributeDepth++;
                } elseif ($attributeDepth > 0) {
                    $attributeDepth += $part['text'] === '[' ? 1 : ($part['text'] === ']' ? -1 : 0);
                } elseif ($part['id'] === T_VARIABLE) {
                    $params[] = ['name' => ltrim($part['text'], '$'), 'type' => $type];
                    break;
                } elseif (!in_array($part['text'], ['public', 'protected', 'private', 'readonly', '&', '...'], true)
                    && !in_array($part['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $type .= $part['text'];
                }
            }
        }
        $cursor = $pairs[$open] + 1;
        if (($tokens[$cursor]['id'] ?? 0) === T_USE && ($tokens[$cursor + 1]['text'] ?? '') === '(') {
            $cursor = ($pairs[$cursor + 1] ?? $cursor + 1) + 1;
        }
        $return = '';
        if (($tokens[$cursor]['text'] ?? '') === ':') {
            for ($cursor++; $cursor < $count && !in_array($tokens[$cursor]['text'], ['{', ';', '=>'], true); $cursor++) {
                $return .= $tokens[$cursor]['text'];
            }
        }
        if (($tokens[$cursor]['text'] ?? '') === '{') {
            $functionBodies[$cursor] = true;
        }
        $docStart = $index;
        if (!$named) {
            if (($tokens[$index - 1]['text'] ?? '') === 'static') {
                $docStart--;
            }
            if (($tokens[$docStart - 1]['text'] ?? '') === '=' && ($tokens[$docStart - 2]['id'] ?? 0) === T_VARIABLE) {
                $docStart -= 2;
                $name = $tokens[$docStart]['text'];
            }
        }
        $bodyStart = $cursor + (($tokens[$cursor]['text'] ?? '') === '=>' ? 1 : 0);
        $bodyEnd = declaration_end($tokens, $bodyStart, $pairs);
        $records[] = ['kind' => $named ? 'callable' : 'callback', 'name' => $name, 'line' => $token['line'],
            'params' => $params, 'return_type' => $return, 'doc' => attached_doc($tokens, $docStart, $pairs),
            'start_token' => declaration_start($tokens, $docStart, $pairs),
            'end_token' => $bodyEnd,
            'unused_params' => strtolower($name) === '__construct' || ($tokens[$bodyStart]['text'] ?? '') === ';'
                ? [] : unused_parameter_names($tokens, $bodyStart, $bodyEnd, $params)];
        // Signature variables are not class properties; promoted fields remain
        // represented by their constructor parameter contracts.
        $index = $cursor - 1;
    }
    return $records;
}

/**
 * Check substantive summaries, parameter agreement, returns, and loose array shapes.
 * Types are checked for presence; semantic subtype compatibility needs review.
 * @param array{kind:string,name:string,line:int,params:list<array{name:string,type:string,tuple_arity?:int}>,return_type:string,doc:string} $record Declaration with optional fixed JavaScript tuple metadata.
 * @return list<string> Stable findings; no source values or documentation text.
 */
function declaration_issues(array $record): array
{
    $doc = $record['doc'];
    if ($doc === '') {
        return ['documentation.missing'];
    }
    $clean = preg_replace('/^\s*\/?\*+\/?\s?/m', '', $doc) ?? $doc;
    $summary = trim(explode('@', $clean, 2)[0]);
    if ($record['kind'] === 'property' && $summary === '') {
        $propertyTags = documentation_tags($clean, 'var');
        $summary = documentation_type_description($propertyTags[0] ?? '');
    }
    $issues = [];
    if (strlen($summary) < 12 || preg_match('/^(?:handles? (?:this|the) operation|todo|fixme|documentation)\W*$/i', $summary) === 1
        || preg_match('/^Handles .+ logic for the gallery application\./i', $summary) === 1) {
        $issues[] = 'documentation.summary';
    }
    if (in_array($record['kind'], ['class', 'interface', 'trait', 'enum'], true)) {
        return $issues;
    }
    if ($record['kind'] === 'property') {
        $tags = documentation_tags($clean, 'var');
        if ($tags === [] || trim($tags[0]) === '') {
            $issues[] = 'property.type';
        } elseif (unspecified_shape($tags[0])) {
            $issues[] = 'shape.unspecified';
        }
        return $issues;
    }
    $parameters = $record['params'];
    $parameterTags = documentation_tags($clean, 'param');
    $expected = array_column($parameters, 'name');
    foreach ($parameters as $position => &$parameter) {
        if ($parameter['name'] !== '(pattern)' || !isset($parameter['tuple_arity'], $parameterTags[$position])) {
            continue;
        }
        $aggregate = documented_parameter($parameterTags[$position], $expected);
        if ($aggregate['name'] !== '' && !str_contains($aggregate['name'], '.')
            && !in_array($aggregate['name'], $expected, true)
            && documented_tuple_arity($aggregate['type']) === $parameter['tuple_arity']) {
            $parameter['name'] = $aggregate['name']; // Positional JSDoc name describes the whole destructured input.
            $expected[$position] = $aggregate['name'];
        }
    }
    unset($parameter);
    $documented = [];
    foreach ($parameterTags as $tag) {
        $parameterDoc = documented_parameter($tag, $expected);
        $name = $parameterDoc['name'];
        if ($name === '') {
            $issues[] = 'parameter.unparsed';
            continue;
        }
        if (isset($documented[$name])) {
            $issues[] = 'parameter.duplicate:' . $name;
        }
        $documented[$name] = $parameterDoc['type'];
        if ($parameterDoc['type'] === '') {
            $issues[] = 'parameter.type:' . $name;
        }
        if ($parameterDoc['description'] === '') {
            $issues[] = 'parameter.description:' . $name;
        }
    }
    foreach ($expected as $name) {
        if ($name === '(pattern)') {
            $issues[] = 'parameter.pattern_review';
        } elseif (!isset($documented[$name])) {
            $issues[] = 'parameter.missing:' . $name;
        }
    }
    foreach ($parameters as $parameter) {
        if (isset($documented[$parameter['name']]) && primitive_type_mismatch($parameter['type'], $documented[$parameter['name']])) {
            $issues[] = 'parameter.type_mismatch:' . $parameter['name'];
        }
    }
    foreach (array_keys($documented) as $name) {
        if (!in_array($name, $expected, true) && !str_contains($name, '.')) {
            $issues[] = 'parameter.extra:' . $name;
        }
    }
    $returnTags = array_merge(documentation_tags($clean, 'return'), documentation_tags($clean, 'returns'));
    if ($returnTags === [] || trim($returnTags[0]) === '') {
        $issues[] = 'return.missing';
    } elseif (primitive_type_mismatch($record['return_type'], $returnTags[0])) {
        $issues[] = 'return.type_mismatch';
    }
    foreach ($documented as $name => $type) {
        if (unspecified_shape($type) && !documented_unused_opaque_parameter($record, $name, $type)) {
            $issues[] = 'shape.unspecified';
        }
    }
    foreach ($returnTags as $type) {
        if (unspecified_shape($type)) {
            $issues[] = 'shape.unspecified';
        }
    }
    return array_values(array_unique($issues));
}

/**
 * Count explicitly typed fixed tuple members without treating a list as a tuple.
 * @param string $type JSDoc type body, excluding its outer documentation braces.
 * @return int|null Fixed member count, or null for loose, rest, optional or unbalanced shapes.
 */
function documented_tuple_arity(string $type): ?int
{
    $type = trim($type);
    if (!str_starts_with($type, '[') || !str_ends_with($type, ']')) {
        return null;
    }
    $depth = 0;
    $members = [];
    $start = 1;
    for ($index = 1; $index < strlen($type) - 1; $index++) {
        $character = $type[$index];
        if ($character === ',' && $depth === 0) {
            $members[] = trim(substr($type, $start, $index - $start));
            $start = $index + 1;
        } else {
            $depth += str_contains('<([{', $character) ? 1 : (str_contains('>)]}', $character) ? -1 : 0);
            if ($depth < 0) {
                return null;
            }
        }
    }
    $members[] = trim(substr($type, $start, strlen($type) - $start - 1));
    foreach ($members as $member) {
        if ($depth !== 0 || $member === '' || str_starts_with($member, '...') || str_ends_with($member, '?') || unspecified_shape($member)) {
            return null;
        }
    }
    return count($members);
}

/**
 * Recognize an honest unrestricted-unused PHP parameter with explicit rationale.
 * The native parameter must be lexically unused; constructors and abstract
 * signatures have no such evidence. Other parameter and return rules still apply.
 * @param array<string,mixed> $record Tokenized declaration including unused_params.
 * @param string $name Documented signature parameter name.
 * @param string $type Explicit parameter documentation type.
 * @return bool Whether mixed is the complete opaque contract for this unused value.
 */
function documented_unused_opaque_parameter(array $record, string $name, string $type): bool
{
    if (strtolower(trim($type)) !== 'mixed' || !in_array($name, $record['unused_params'] ?? [], true)) {
        return false;
    }
    $pattern = '/@source-contract-opaque-param\s+\$?' . preg_quote($name, '/') . '\s+([^\r\n@]+)/';
    return preg_match($pattern, $record['doc'], $match) === 1
        && strlen(trim($match[1])) >= 12
        && preg_match('/\b(?:unused|ignored)\b/i', $match[1]) === 1;
}

/**
 * Extract one documentation tag's body without borrowing a following annotation.
 * @param string $documentation Comment text with decorative line prefixes removed.
 * @param string $name Exact annotation name, without the at-sign.
 * @return list<string> Trimmed bodies, including multiline types and descriptions.
 */
function documentation_tags(string $documentation, string $name): array
{
    preg_match_all('/@' . preg_quote($name, '/') . '\b([^@]*)/', $documentation, $matches);
    return array_map('trim', $matches[1]);
}

/**
 * Read a property's standard var-tag description after its balanced type expression.
 * @param string $tag Var annotation body, including type and optional field name.
 * @return string Field meaning; a bare type never fabricates a summary.
 */
function documentation_type_description(string $tag): string
{
    $tag = trim($tag);
    $depth = 0;
    for ($index = 0; $index < strlen($tag); $index++) {
        $character = $tag[$index];
        $depth += str_contains('<{([', $character) ? 1 : (str_contains('>})]', $character) ? -1 : 0);
        if ($depth === 0 && ctype_space($character)) {
            $description = trim(substr($tag, $index));
            return preg_replace('/^\$[A-Za-z_][A-Za-z0-9_]*\s+/', '', $description) ?? $description;
        }
    }
    return '';
}

/**
 * Parse PHP named parameters and braced JSDoc types, including nested shapes.
 * @param string $tag Annotation body without the param marker.
 * @param list<string> $expected Signature names, used to recognize untyped tags.
 * @return array{name:string,type:string,description:string} Empty type/name exposes incomplete syntax.
 */
function documented_parameter(string $tag, array $expected): array
{
    $tag = trim($tag);
    $type = '';
    if (str_starts_with($tag, '{')) {
        $depth = 0;
        for ($cursor = 0; $cursor < strlen($tag); $cursor++) {
            $depth += $tag[$cursor] === '{' ? 1 : ($tag[$cursor] === '}' ? -1 : 0);
            if ($depth === 0) {
                $type = trim(substr($tag, 1, $cursor - 1));
                $tag = trim(substr($tag, $cursor + 1));
                break;
            }
        }
    } elseif (preg_match('/^(.*?)\s*(?:&|\.\.\.)?\$([A-Za-z_][A-Za-z0-9_]*)\s*(.*)$/s', $tag, $match) === 1) {
        return ['type' => trim($match[1]), 'name' => $match[2], 'description' => trim($match[3])];
    } elseif (preg_match('/^(\S+)\s+([A-Za-z_$][A-Za-z0-9_$]*)(?:\s+(.*))?$/s', $tag, $match) === 1
        && !in_array($match[1], $expected, true)) {
        return ['type' => $match[1], 'name' => $match[2], 'description' => trim($match[3] ?? '')];
    }
    if (preg_match('/^\[?(?:\.\.\.)?([A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z0-9_$]+)*)(?:=[^\]]*)?\]?(?:\s+(.*))?$/s', $tag, $match) === 1) {
        return ['type' => $type, 'name' => ltrim($match[1], '$'), 'description' => trim($match[2] ?? '')];
    }
    return ['name' => '', 'type' => '', 'description' => ''];
}

/**
 * Recognize undocumented container shapes across PHPDoc and braced JSDoc.
 * @param string $type Type expression optionally followed by its description.
 * @return bool Whether a bare array/object/mixed wildcard lacks an element or field contract.
 */
function unspecified_shape(string $type): bool
{
    return preg_match('/^\{?\s*(?:array|object|mixed|any|\*)(?=\s|\}|$)/i', trim($type)) === 1;
}

/**
 * Detect definite primitive type disagreement without pretending to resolve aliases.
 * Class names, generic aliases, intersections and complex shapes need human review.
 * @param string $native Native PHP signature type, empty for JavaScript.
 * @param string $documented Documented type followed by optional description.
 * @return bool Whether both primitive types are known and definitely disagree.
 */
function primitive_type_mismatch(string $native, string $documented): bool
{
    if ($native === '') {
        return false;
    }
    $native = strtolower($native);
    if ($native[0] === '?') {
        $native = substr($native, 1) . '|null';
    }
    $documented = trim($documented);
    if (preg_match('/^(?:\??array)[<{]/', $documented) === 1) {
        // Array element/field types narrow the same native array container.
        return !in_array('array', explode('|', $native), true) && $native !== 'mixed';
    }
    $documented = strtolower(explode(' ', $documented, 2)[0]);
    if (str_starts_with($documented, '?')) {
        $documented = substr($documented, 1) . '|null';
    }
    $aliases = ['integer' => 'int', 'boolean' => 'bool', 'double' => 'float'];
    $known = ['int', 'string', 'float', 'bool', 'array', 'object', 'mixed', 'null', 'void', 'never', 'false', 'true'];
    $nativeAtoms = explode('|', $native);
    $documentedAtoms = explode('|', $documented);
    foreach ($documentedAtoms as &$atom) {
        $atom = $aliases[$atom] ?? $atom;
    }
    unset($atom);
    if (array_diff(array_merge($nativeAtoms, $documentedAtoms), $known) !== []) {
        return false;
    }
    if (in_array('mixed', $nativeAtoms, true)) {
        return false;
    }
    foreach ($documentedAtoms as $atom) {
        if (!in_array($atom, $nativeAtoms, true) && !(in_array($atom, ['false', 'true'], true) && in_array('bool', $nativeAtoms, true))) {
            return true;
        }
    }
    return false;
}
