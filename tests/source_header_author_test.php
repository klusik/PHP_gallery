<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/source_header_author_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects the repository-wide first-party source attribution header contract.
 *
 * Responsibilities:
 *   - Require the standard PHP Gallery project marker on first-party source files
 *   - Require Author attribution to Rudolf Klusal in every audited source header
 *   - Exclude runtime, third-party, generated, and agent-local directories
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$extensions = ['php', 'js', 'mjs', 'css', 'py', 'pyw', 'sh', 'bat', 'cmd', 'ps1'];
$excludedFiles = [
    'config.php',
];
$excludedDirectories = [
    '.git',
    '.claude',
    '.codex',
    '.agent',
    '.agent-local',
    'cache',
    'galleries',
    'data',
    'node_modules',
    'vendor',
];

$failures = [];
$audited = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
        continue;
    }

    $extension = strtolower($fileInfo->getExtension());
    if (!in_array($extension, $extensions, true)) {
        continue;
    }

    $path = $fileInfo->getPathname();
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if (in_array($relative, $excludedFiles, true)) {
        continue;
    }
    $parts = explode('/', $relative);
    if (array_intersect($excludedDirectories, $parts) !== []) {
        continue;
    }

    $source = file_get_contents($path);
    if (!is_string($source)) {
        $failures[] = $relative . ': unable to read source file';
        continue;
    }

    $audited++;
    $header = substr($source, 0, 6000);
    if (!str_contains($header, 'Project: PHP Gallery')) {
        $failures[] = $relative . ': missing Project: PHP Gallery header';
        continue;
    }

    if (!preg_match('/Author:\s*(?:\R\s*(?:\*|#|rem\b)?\s*)?Rudolf Klusal\b/i', $header)) {
        $failures[] = $relative . ': missing Author: Rudolf Klusal attribution';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Source header author contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo 'PASS source_header_author: audited ' . $audited . " first-party source files\n";
