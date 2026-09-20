<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Protect controller-to-view presentation ownership.
 * Responsibilities:
 *   - Reject document markup in controllers while retaining protocol response boundaries.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/stage3_controller_presentation_boundary_test.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
/**
 * Protect the Stage 3 controller-to-view presentation boundary.
 *
 * Stage 3 removes HTML/XML document construction from controllers. Controllers
 * may still return protocol/plain-text responses when appropriate, but markup
 * belongs in app/views so request orchestration and presentation remain separate.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$controllerRoot = $root . '/app/controllers';

if (!is_dir($controllerRoot)) {
    throw new RuntimeException('Controller directory is unavailable.');
}

/**
 * Return true when a PHP string literal contains presentation markup.
 */
function stage3_controller_literal_contains_markup(string $literal): bool
{
    if (strlen($literal) < 2) {
        return false;
    }

    $body = substr($literal, 1, -1);
    return preg_match('~</?[A-Za-z!][^>]*>|<\?xml|&(?:nbsp|amp|lt|gt|quot|#\d+);~', $body) === 1;
}

/**
 * Normalize a short source excerpt for deterministic failure output.
 */
function stage3_controller_excerpt(string $value): string
{
    $value = (string) preg_replace('~\s+~', ' ', $value);
    return strlen($value) > 140 ? substr($value, 0, 137) . '...' : $value;
}

$violations = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($controllerRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $source = file_get_contents($file->getPathname());
    if ($source === false) {
        throw new RuntimeException('Unable to read controller: ' . $file->getPathname());
    }

    $tokens = token_get_all($source);
    foreach ($tokens as $token) {
        if (!is_array($token)) {
            continue;
        }

        [$tokenId, $text, $line] = $token;
        if ($tokenId === T_INLINE_HTML && trim($text) !== '') {
            $violations[] = [
                'file' => substr($file->getPathname(), strlen($root) + 1),
                'line' => $line,
                'kind' => 'inline HTML',
                'excerpt' => stage3_controller_excerpt($text),
            ];
            continue;
        }

        if ($tokenId === T_CONSTANT_ENCAPSED_STRING && stage3_controller_literal_contains_markup($text)) {
            $violations[] = [
                'file' => substr($file->getPathname(), strlen($root) + 1),
                'line' => $line,
                'kind' => 'markup string',
                'excerpt' => stage3_controller_excerpt(substr($text, 1, -1)),
            ];
        }
    }
}

if ($violations !== []) {
    $lines = ['Stage 3 controller presentation boundary violations:'];
    foreach ($violations as $violation) {
        $lines[] = sprintf(
            '  - %s:%d [%s] %s',
            $violation['file'],
            $violation['line'],
            $violation['kind'],
            $violation['excerpt']
        );
    }
    throw new RuntimeException(implode(PHP_EOL, $lines));
}

fwrite(STDOUT, "Stage 3 controller presentation boundary checks passed.\n");
