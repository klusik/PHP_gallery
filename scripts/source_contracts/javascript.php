<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/source_contracts/javascript.php
 * Module Type: JavaScript Source Contract Scanner
 * Purpose:
 *   Inventory JavaScript declarations with comment/string-aware token boundaries.
 * Responsibilities:
 *   - Recognize exported functions, classes, methods, and bound/inline arrows.
 *   - Report unsupported parameter patterns for manual review.
 * Author:
 *   Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\SourceContracts;

/**
 * Tokenize JavaScript conservatively without treating literal contents as code.
 *
 * Template expression bodies are recursively tokenized; literal chunks stay inert.
 * This is not a full ECMAScript parser: private/computed members and ambiguous
 * regular-expression contexts still require explicit coverage or manual review.
 *
 * @param string $source JavaScript module or script text.
 * @param bool $expressionOnly Stop before the unmatched closing brace of a template expression.
 * @param int|null $consumed Receives the byte count consumed from this source fragment.
 * @return list<array{id:int,text:string,line:int,offset:int}> Tokens with PHP-compatible comment/identifier categories.
 */
function javascript_tokens(string $source, bool $expressionOnly = false, ?int &$consumed = null): array
{
    $tokens = [];
    $length = strlen($source);
    $offset = 0;
    $line = 1;
    $previous = '';
    $braceDepth = 0;
    while ($offset < $length) {
        $start = $offset;
        $id = 0;
        $char = $source[$offset];
        if ($expressionOnly && $char === '}' && $braceDepth === 0) {
            break;
        }
        if (preg_match('/\G\s+/A', $source, $match, 0, $offset) === 1) {
            $offset += strlen($match[0]);
            $line += substr_count($match[0], "\n");
            continue;
        }
        if (substr($source, $offset, 2) === '/*') {
            $end = strpos($source, '*/', $offset + 2);
            $offset = $end === false ? $length : $end + 2;
            $id = substr($source, $start, 3) === '/**' ? T_DOC_COMMENT : T_COMMENT;
        } elseif (substr($source, $offset, 2) === '//' || ($offset === 0 && substr($source, $offset, 2) === '#!')) {
            $end = strpos($source, "\n", $offset);
            $offset = $end === false ? $length : $end;
            $id = T_COMMENT;
        } elseif (ord($char) === 96) {
            $offset++;
            $literalStart = $start;
            $literalLine = $line;
            while ($offset < $length) {
                if ($source[$offset] === '\\') {
                    $offset = min($length, $offset + 2);
                    continue;
                }
                if (ord($source[$offset]) === 96) {
                    $offset++;
                    break;
                }
                if (substr($source, $offset, 2) === '$' . '{') {
                    $literal = substr($source, $literalStart, $offset - $literalStart);
                    $tokens[] = ['id' => T_CONSTANT_ENCAPSED_STRING, 'text' => $literal, 'line' => $literalLine, 'offset' => $literalStart];
                    $line += substr_count($literal, "\n");
                    $tokens[] = ['id' => 0, 'text' => '{', 'line' => $line, 'offset' => $offset + 1];
                    $expressionStart = $offset + 2;
                    $expressionLength = null;
                    $expression = javascript_tokens(substr($source, $expressionStart), true, $expressionLength);
                    foreach ($expression as $part) {
                        $part['line'] += $line - 1;
                        $part['offset'] += $expressionStart;
                        $tokens[] = $part;
                    }
                    $line += substr_count(substr($source, $expressionStart, $expressionLength ?? 0), "\n");
                    $offset = $expressionStart + ($expressionLength ?? 0);
                    if (($source[$offset] ?? '') === '}') {
                        $tokens[] = ['id' => 0, 'text' => '}', 'line' => $line, 'offset' => $offset++];
                    }
                    $literalStart = $offset;
                    $literalLine = $line;
                    continue;
                }
                $offset++;
            }
            $literal = substr($source, $literalStart, $offset - $literalStart);
            $tokens[] = ['id' => T_CONSTANT_ENCAPSED_STRING, 'text' => $literal, 'line' => $literalLine, 'offset' => $literalStart];
            $line += substr_count($literal, "\n");
            $previous = 'template';
            continue;
        } elseif ($char === "'" || $char === '"'
            || ($char === '/' && in_array($previous, ['', '=', '(', '[', ',', ':', '!', 'return', '=>', '&&', '||', '?'], true))) {
            $quote = $char;
            $inClass = false;
            $offset++;
            while ($offset < $length) {
                $current = $source[$offset++];
                if ($current === '\\') {
                    $offset = min($length, $offset + 1);
                    continue;
                }
                if ($quote === '/') {
                    if ($current === '[') {
                        $inClass = true;
                    } elseif ($current === ']') {
                        $inClass = false;
                    }
                }
                if ($current === $quote && !$inClass) {
                    break;
                }
            }
            if ($quote === '/') {
                while ($offset < $length && ctype_alpha($source[$offset])) {
                    $offset++;
                }
            }
            $id = T_CONSTANT_ENCAPSED_STRING;
        } elseif (preg_match('/\G[A-Za-z_$][A-Za-z0-9_$]*/A', $source, $match, 0, $offset) === 1) {
            $offset += strlen($match[0]);
            $id = T_STRING;
        } elseif (preg_match('/\G(?:0[xX][0-9a-fA-F]+|\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)/A', $source, $match, 0, $offset) === 1) {
            $offset += strlen($match[0]);
            $id = T_LNUMBER;
        } elseif (preg_match('/\G(?:=>|\.\.\.|\?\.|===|!==|==|!=|&&|\|\||\?\?|\+\+|--)/A', $source, $match, 0, $offset) === 1) {
            $offset += strlen($match[0]);
        } else {
            $offset++;
        }
        $text = substr($source, $start, $offset - $start);
        if ($id === 0) {
            $braceDepth += $text === '{' ? 1 : ($text === '}' ? -1 : 0);
        }
        $tokens[] = ['id' => $id, 'text' => $text, 'line' => $line, 'offset' => $start];
        $line += substr_count($text, "\n");
        if (!in_array($id, [T_COMMENT, T_DOC_COMMENT], true)) {
            $previous = $text;
        }
    }
    $consumed = $offset;
    return $tokens;
}

/**
 * Inventory common callable and class forms without regex-searching literal text.
 * @param string $source JavaScript source including optional module exports.
 * @return list<array{kind:string,name:string,line:int,params:list<array{name:string,type:string,tuple_arity?:int}>,return_type:string,doc:string,start_token:int,end_token:int}> Contracts, flat tuple arities and inclusive executable token spans.
 */
function javascript_declarations(string $source): array
{
    $tokens = javascript_tokens($source);
    $pairs = delimiter_pairs($tokens);
    $records = [];
    $claimed = [];
    foreach ($tokens as $index => $token) {
        if (in_array($token['id'], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING], true)) {
            continue;
        }
        $text = $token['text'];
        $start = $index;
        $name = '';
        $kind = 'callable';
        $open = null;
        if ($text === 'class') {
            $name = ($tokens[$index + 1]['id'] ?? 0) === T_STRING ? $tokens[$index + 1]['text'] : '(anonymous class)';
            $kind = 'class';
        } elseif ($text === '[' && isset($pairs[$index])
            && ($tokens[$pairs[$index] + 1]['text'] ?? '') === '('
            && isset($pairs[$pairs[$index] + 1])
            && ($tokens[$pairs[$pairs[$index] + 1] + 1]['text'] ?? '') === '{') {
            $name = '(computed method)';
            $open = $pairs[$index] + 1;
        } elseif ($text === 'function') {
            $cursor = $index + 1;
            if (($tokens[$cursor]['text'] ?? '') === '*') {
                $cursor++;
            }
            if (($tokens[$cursor]['id'] ?? 0) === T_STRING) {
                $name = $tokens[$cursor++]['text'];
            } else {
                $kind = 'callback';
                $name = '(anonymous)';
            }
            $open = $cursor;
            $claimed[$open] = true;
        } elseif ($text === '=>') {
            $kind = 'callback';
            $name = '(anonymous)';
            $previous = $index - 1;
            if (($tokens[$previous]['text'] ?? '') === ')' && isset($pairs[$previous])) {
                $open = $pairs[$previous];
                $start = $open;
            } elseif (($tokens[$previous]['id'] ?? 0) === T_STRING) {
                $start = $previous;
            } else {
                continue;
            }
        } elseif ($token['id'] === T_STRING && ($tokens[$index + 1]['text'] ?? '') === '('
            && !isset($claimed[$index + 1]) && isset($pairs[$index + 1])
            && ($tokens[$pairs[$index + 1] + 1]['text'] ?? '') === '{'
            && !in_array($text, ['if', 'for', 'while', 'switch', 'catch', 'with', 'function'], true)
            && !in_array($tokens[$index - 1]['text'] ?? '', ['.', '?.', 'new', 'function'], true)) {
            $name = $text;
            $open = $index + 1;
        } else {
            continue;
        }
        if ($kind === 'callback') {
            if (($tokens[$start - 1]['text'] ?? '') === 'async') {
                $start--;
            }
            if (in_array($tokens[$start - 1]['text'] ?? '', ['=', ':'], true)
                && ($tokens[$start - 2]['id'] ?? 0) === T_STRING) {
                $start -= 2;
                $name = $tokens[$start]['text'];
                $kind = 'bound_callback';
                while (($tokens[$start - 1]['text'] ?? '') === '.'
                    && ($tokens[$start - 2]['id'] ?? 0) === T_STRING) {
                    $start -= 2;
                }
                if (in_array($tokens[$start - 1]['text'] ?? '', ['const', 'let', 'var'], true)) {
                    $start--;
                }
            }
        }
        $params = [];
        if ($open !== null && isset($pairs[$open])) {
            foreach (parameter_segments($tokens, $open, $pairs) as $segment) {
                $first = $segment[0] ?? [];
                if (($first['text'] ?? '') === '...') {
                    $first = $segment[1] ?? [];
                }
                $parameter = ['name' => ($first['id'] ?? 0) === T_STRING ? $first['text'] : '(pattern)', 'type' => ''];
                $tupleArity = javascript_flat_tuple_arity($segment);
                if ($tupleArity !== null) {
                    $parameter['tuple_arity'] = $tupleArity;
                }
                $params[] = $parameter;
            }
        } elseif ($text === '=>' && ($tokens[$index - 1]['id'] ?? 0) === T_STRING) {
            $params[] = ['name' => $tokens[$index - 1]['text'], 'type' => ''];
        }
        $bodyStart = $text === '=>' ? $index + 1 : (($pairs[$open ?? -1] ?? $index) + 1);
        if ($kind === 'class') {
            while ($bodyStart < count($tokens) && $tokens[$bodyStart]['text'] !== '{') {
                $bodyStart++;
            }
        }
        $records[] = ['kind' => $kind, 'name' => $name, 'line' => $token['line'],
            'params' => $params, 'return_type' => '', 'doc' => attached_doc($tokens, $start, $pairs),
            'start_token' => declaration_start($tokens, $start, $pairs),
            'end_token' => declaration_end($tokens, $bodyStart, $pairs)];
    }
    return $records;
}

/**
 * Recognize dense fixed-length array destructuring without inventing binding names.
 * @param list<array{id:int,text:string,line:int,offset:int}> $segment One signature parameter.
 * @return int|null Binding count, or null for object/nested/rest/default/sparse patterns.
 */
function javascript_flat_tuple_arity(array $segment): ?int
{
    if (($segment[0]['text'] ?? '') !== '[' || ($segment[count($segment) - 1]['text'] ?? '') !== ']') {
        return null;
    }
    $expectName = true;
    $count = 0;
    foreach (array_slice($segment, 1, -1) as $token) {
        if (in_array($token['id'], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($expectName && $token['id'] === T_STRING) {
            $count++;
            $expectName = false;
        } elseif (!$expectName && $token['text'] === ',') {
            $expectName = true;
        } else {
            return null;
        }
    }
    return $count > 0 ? $count : null;
}
