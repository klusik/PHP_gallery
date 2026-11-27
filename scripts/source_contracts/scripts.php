<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/source_contracts/scripts.php
 * Module Type: Native Script Declaration Scanner
 * Purpose: Compare documented native-script callables and bounded batch labels without execution.
 * Responsibilities:
 *   - Keep comments separate from quoted values and here-document contents.
 *   - Fingerprint batch entrypoints and labels while checking static call targets.
 *   - Reject unsupported declaration forms instead of claiming complete language coverage.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\SourceContracts;

/**
 * Lex script boundaries while preserving literal values only in internal fingerprints.
 * This deliberately covers ordinary named functions, not a complete script grammar.
 * @param string $source Normalized source text; never evaluated or printed.
 * @param bool $powershell Whether PowerShell quoting and block comments apply.
 * @return array{structure:string,tokens:list<array{start:int,end:int,text:string}>} Masked declaration text and executable tokens.
 */
function script_source_tokens(string $source, bool $powershell): array
{
    $structure = $source;
    $tokens = [];
    $length = strlen($source);
    $pendingSpace = '';
    for ($cursor = 0; $cursor < $length;) {
        $start = $cursor;
        $character = $source[$cursor];
        $comment = false;
        $masked = false;
        if (ctype_space($character)) {
            $pendingSpace =
                $character === "\n" || $pendingSpace === "\n" ? "\n" : ' ';
            $cursor++;
            continue;
        }
        if ($powershell && substr($source, $cursor, 2) === '<#') {
            $end = strpos($source, '#>', $cursor + 2);
            if ($end === false) {
                throw new \RuntimeException(
                    'Unterminated script block comment.',
                );
            }
            $cursor = $end + 2;
            $comment = true;
        } elseif (
            $character === '#'
            && (
                $powershell
                || $cursor === 0
                || ctype_space($source[$cursor - 1])
                || str_contains(';|&()', $source[$cursor - 1])
            )
        ) {
            $cursor = strpos($source, "\n", $cursor) ?: $length;
            $comment = true;
        } elseif (
            !$powershell
            && substr($source, $cursor, 2) === '<<'
            && substr($source, $cursor, 3) !== '<<<'
        ) {
            if (
                preg_match(
                    '/\A<<(-?)[ \t]*([\'"]?)([A-Za-z_][A-Za-z0-9_]*)\2[^\n]*\n/',
                    substr($source, $cursor),
                    $match,
                ) !== 1
            ) {
                throw new \RuntimeException(
                    'Unsupported script here-document delimiter.',
                );
            }
            $bodyStart = $cursor + strlen($match[0]);
            $terminator =
                '/^'
                . ($match[1] === '-' ? '\t*' : '')
                . preg_quote($match[3], '/')
                . '(?:\n|$)/m';
            if (
                preg_match(
                    $terminator,
                    $source,
                    $closing,
                    PREG_OFFSET_CAPTURE,
                    $bodyStart,
                ) !== 1
            ) {
                throw new \RuntimeException(
                    'Unterminated script here-document.',
                );
            }
            $cursor = $closing[0][1] + strlen(rtrim($closing[0][0], "\n"));
            $masked = true;
        } elseif (
            $powershell
            && $character === '@'
            && in_array($source[$cursor + 1] ?? '', ['"', "'"], true)
        ) {
            $quote = $source[$cursor + 1];
            if (
                preg_match(
                    '/^' . preg_quote($quote . '@', '/') . '(?:\n|$)/m',
                    $source,
                    $closing,
                    PREG_OFFSET_CAPTURE,
                    $cursor + 2,
                ) !== 1
            ) {
                throw new \RuntimeException(
                    'Unterminated PowerShell here-string.',
                );
            }
            $cursor = $closing[0][1] + strlen(rtrim($closing[0][0], "\n"));
            $masked = true;
        } elseif (in_array($character, ['"', "'"], true)) {
            $quote = $character;
            $closed = false;
            for ($cursor++; $cursor < $length; $cursor++) {
                if (
                    (
                        $powershell
                        && $source[$cursor] === '`'
                    )
                    || (
                        !$powershell
                        && $quote === '"'
                        && $source[$cursor] === '\\'
                    )
                ) {
                    $cursor++;
                } elseif ($source[$cursor] === $quote) {
                    if (
                        $powershell
                        && ($source[$cursor + 1] ?? '') === $quote
                    ) {
                        $cursor++;
                        continue;
                    }
                    $cursor++;
                    $closed = true;
                    break;
                }
            }
            if (!$closed) {
                throw new \RuntimeException('Unterminated script string.');
            }
            $masked = true;
        } elseif ($character === ($powershell ? '`' : '\\')) {
            $cursor += 2;
            $masked = true;
        } elseif (!$powershell && $character === '`') {
            throw new \RuntimeException(
                'Legacy shell command substitution requires additional scanner coverage.',
            );
        } elseif (
            preg_match(
                '/\A[A-Za-z_][A-Za-z0-9_:-]*/',
                substr($source, $cursor),
                $word,
            ) === 1
        ) {
            $cursor += strlen($word[0]);
        } else {
            $cursor++;
        }
        $text = substr($source, $start, $cursor - $start);
        if ($comment || $masked) {
            $structure = substr_replace(
                $structure,
                preg_replace('/[^\n]/', ' ', $text),
                $start,
                strlen($text),
            );
        }
        if (!$comment) {
            if ($pendingSpace !== '' && $tokens !== []) {
                $tokens[] = [
                    'start' => $start,
                    'end' => $start,
                    'text' => $pendingSpace,
                ];
            }
            $tokens[] = ['start' => $start, 'end' => $cursor, 'text' => $text];
            $pendingSpace = '';
        } elseif (str_contains($text, "\n")) {
            $pendingSpace = "\n";
        }
    }
    return ['structure' => $structure, 'tokens' => $tokens];
}

/**
 * Read the contiguous native comment immediately before a function declaration.
 * @param string $source Complete normalized script source.
 * @param int $offset First byte of the declaration line.
 * @return string Hash comments or PowerShell comment-based help, without execution.
 */
function script_leading_documentation(string $source, int $offset): string
{
    $prefix = rtrim(substr($source, 0, $offset));
    if (str_ends_with($prefix, '#>')) {
        $start = strrpos($prefix, '<#');
        return $start === false ? '' : substr($prefix, $start);
    }
    $comments = [];
    $lines = explode("\n", $prefix);
    while (
        $lines !== []
        && str_starts_with(ltrim($lines[count($lines) - 1]), '#')
    ) {
        array_unshift($comments, array_pop($lines));
    }
    return implode("\n", $comments);
}

/**
 * Locate native script declarations and compare their executable bodies.
 * Function signatures and argument/result semantics remain a native-script review;
 * this gate enforces meaningful function comments and unchanged-body matching.
 * @param string $source Current or historical Bash, PowerShell or batch source.
 * @param string $extension Native extension: sh, ps1, psm1, bat or cmd.
 * @return list<array{identity:string,fingerprint:string,record:array<string,mixed>,uncertain:bool}> Function snapshots without source values in public reports.
 */
function script_declaration_snapshots(string $source, string $extension): array
{
    $source = str_replace("\r\n", "\n", $source);
    if (in_array($extension, ['bat', 'cmd'], true)) {
        return batch_declaration_snapshots($source, $extension);
    }
    $powershell = $extension !== 'sh';
    $lexed = script_source_tokens($source, $powershell);
    $structure = $lexed['structure'];
    if (
        preg_match(
            '/\b(?:class|filter|workflow|configuration)\s+[A-Za-z_]/i',
            $structure,
        ) === 1
        || preg_match('/\b(?:eval|Invoke-Expression)\b/i', $structure) === 1
    ) {
        throw new \RuntimeException(
            'Unsupported or dynamically generated script declarations.',
        );
    }
    $pattern = $powershell
        ? '/^[ \t]*function[ \t]+([A-Za-z_][A-Za-z0-9_-]*)[ \t]*(?:\([^\n{}]*\)[ \t]*)?\s*\{/mi'
        : '/^[ \t]*(?:function[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*\([ \t]*\)[ \t]*\s*\{/m';
    preg_match_all($pattern, $structure, $matches, PREG_OFFSET_CAPTURE);
    $candidatePattern = $powershell
        ? '/\bfunction\b/i'
        : '/\bfunction\b|\b[A-Za-z_][A-Za-z0-9_]*[ \t]*\([ \t]*\)/i';
    preg_match_all(
        $candidatePattern,
        $structure,
        $keywords,
        PREG_OFFSET_CAPTURE,
    );
    foreach ($keywords[0] as $keyword) {
        $covered = false;
        foreach ($matches[0] as $match) {
            $covered =
                $covered
                || (
                    $keyword[1] >= $match[1]
                    && $keyword[1] < ($match[1] + strlen($match[0]))
                );
        }
        if (!$covered) {
            throw new \RuntimeException(
                'Unsupported script function declaration.',
            );
        }
    }
    $stack = [];
    $pairs = [];
    for ($cursor = 0; $cursor < strlen($structure); $cursor++) {
        if ($structure[$cursor] === '{') {
            $stack[] = $cursor;
        } elseif ($structure[$cursor] === '}') {
            $open = array_pop($stack);
            if ($open === null) {
                throw new \RuntimeException('Unbalanced script body.');
            }
            $pairs[$open] = $cursor;
        }
    }
    if ($stack !== []) {
        throw new \RuntimeException('Unbalanced script body.');
    }
    $snapshots = [];
    foreach ($matches[0] as $index => $match) {
        $start = $match[1];
        $open = $start + strlen($match[0]) - 1;
        $end = $pairs[$open] ?? null;
        if ($end === null) {
            throw new \RuntimeException('Missing script function boundary.');
        }
        $name = $matches[1][$index][0];
        $executable = [];
        foreach ($lexed['tokens'] as $token) {
            if ($token['start'] >= $start && $token['end'] <= ($end + 1)) {
                $executable[] = $token['text'];
            }
        }
        $snapshots[] = [
            'identity' => $extension . '/function:' . $name,
            'fingerprint' => hash('sha256', json_encode(
                $executable,
                JSON_THROW_ON_ERROR,
            )),
            'record' => [
                'kind' => 'function',
                'name' => $name,
                'line' => substr_count(substr($source, 0, $start), "\n") + 1,
                'doc' => script_leading_documentation($source, $start),
                'language' => 'script',
            ],
            'uncertain' => false,
        ];
    }
    return $snapshots;
}

/**
 * Fingerprint a bounded Windows batch file and its static labels without execution.
 * Only standalone labels and literal `goto name` / `call :name` commands are
 * recognized. Other call/goto syntax blocks coverage rather than being guessed.
 * Empty and comment-only input contributes no declaration snapshot.
 * @param string $source Current or historical batch source.
 * @param string $extension Native extension: bat or cmd.
 * @return list<array{identity:string,fingerprint:string,record:array<string,mixed>,uncertain:bool}> Value-free batch snapshots.
 */
function batch_declaration_snapshots(string $source, string $extension): array
{
    $lines = explode("\n", $source);
    $labels = [];
    $commands = [];
    $calls = [];
    $jumps = [];
    foreach ($lines as $index => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || preg_match('/^(?:@?rem\b|::)/i', $trimmed) === 1) {
            continue;
        }
        if (preg_match('/^:([A-Za-z_][A-Za-z0-9_.-]*)\s*$/', $trimmed, $match) === 1) {
            $name = strtolower($match[1]);
            if (isset($labels[$name])) {
                throw new \RuntimeException('Duplicate batch label.');
            }
            $labels[$name] = ['line' => $index, 'name' => $match[1]];
            continue;
        }
        if (str_starts_with($trimmed, ':')) {
            throw new \RuntimeException('Unsupported batch label syntax.');
        }
        $visible = preg_replace('/"(?:[^"]|"")*"/', ' ', $trimmed);
        if (!is_string($visible)) {
            throw new \RuntimeException('Unable to scan batch command.');
        }
        if (preg_match('/\bcall\b/i', $visible) === 1) {
            if (preg_match('/^@?call\s+:([A-Za-z_][A-Za-z0-9_.-]*)(?:\s+.*)?$/i', $trimmed, $match) !== 1) {
                throw new \RuntimeException('Unsupported batch call target.');
            }
            $calls[strtolower($match[1])] = true;
        }
        if (preg_match('/\bgoto\b/i', $visible) === 1) {
            $gotoPatterns = [
                '/^@?goto\s+:?([A-Za-z_][A-Za-z0-9_.-]*|:eof)\s*$/i',
                '/^@?if\s+(?:not\s+)?defined\s+[A-Za-z_][A-Za-z0-9_]*\s+goto\s+:?([A-Za-z_][A-Za-z0-9_.-]*)\s*$/i',
                '/^@?if\s+errorlevel\s+[0-9]+\s+goto\s+:?([A-Za-z_][A-Za-z0-9_.-]*)\s*$/i',
            ];
            $matchedGoto = false;
            foreach ($gotoPatterns as $pattern) {
                if (preg_match($pattern, $trimmed, $match) === 1) {
                    $matchedGoto = true;
                    break;
                }
            }
            if (!$matchedGoto) {
                throw new \RuntimeException('Unsupported batch goto target.');
            }
            $target = strtolower(ltrim($match[1], ':'));
            if ($target !== 'eof') {
                $jumps[$target] = true;
            }
        }
        $commands[$index] = preg_replace('/[ \t]+/', ' ', $trimmed) ?? $trimmed;
    }
    foreach (array_merge(array_keys($calls), array_keys($jumps)) as $target) {
        if (!isset($labels[$target])) {
            throw new \RuntimeException('Batch control target is undefined.');
        }
    }
    // An absent predecessor must not contribute a phantom entrypoint.
    if ($labels === [] && $commands === []) {
        return [];
    }

    $snapshots = [];
    $boundaries = array_values($labels);
    foreach ($boundaries as $position => $label) {
        $name = $label['name'];
        $start = $label['line'];
        $end = $boundaries[$position + 1]['line'] ?? count($lines);
        $body = [];
        for ($line = $start; $line < $end; $line++) {
            if (isset($commands[$line])) {
                $body[] = $commands[$line];
            }
        }
        $callable = isset($calls[strtolower($name)]);
        $snapshots[] = [
            'identity' => $extension . '/' . ($callable ? 'function:' : 'label:') . strtolower($name),
            'fingerprint' => hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)),
            'record' => [
                'kind' => $callable ? 'function' : 'batch_label',
                'name' => $name,
                'line' => $start + 1,
                'doc' => batch_leading_documentation($lines, $start),
                'language' => 'script',
            ],
            'uncertain' => false,
        ];
    }

    $entrypoint = [];
    $firstLabel = $boundaries[0]['line'] ?? count($lines);
    for ($line = 0; $line < $firstLabel; $line++) {
        if (isset($commands[$line])) {
            $entrypoint[] = $commands[$line];
        }
    }
    $snapshots[] = [
        'identity' => $extension . '/script:entrypoint',
        'fingerprint' => hash('sha256', json_encode($entrypoint, JSON_THROW_ON_ERROR)),
        'record' => [
            'kind' => 'batch_entrypoint',
            'name' => 'entrypoint',
            'line' => 1,
            'doc' => '',
            'language' => 'script',
        ],
        'uncertain' => false,
    ];
    return $snapshots;
}

/**
 * Read directly preceding `rem` documentation for a batch callable label.
 * @param list<string> $lines Normalized batch lines.
 * @param int $offset Zero-based label line.
 * @return string Contiguous comments or an empty string.
 */
function batch_leading_documentation(array $lines, int $offset): string
{
    $comments = [];
    for ($line = $offset - 1; $line >= 0; $line--) {
        $text = trim($lines[$line]);
        if (preg_match('/^@?rem(?:\s+(.*))?$/i', $text, $match) !== 1) {
            break;
        }
        array_unshift($comments, trim($match[1] ?? ''));
    }
    return implode("\n", $comments);
}
