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
 *   - Require project, repository, file identity, module type, purpose and responsibilities
 *   - Require exact Author attribution to Rudolf Klusal in the leading native header
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

require_once dirname(__DIR__) . '/scripts/source_contracts/inventory.php';

$root = dirname(__DIR__);
$failures = [];
$audited = 0;
foreach (\PhpGallery\SourceContracts\inventory($root)['files'] as $relative => $extension) {
    $source = file_get_contents($root . '/' . $relative);
    if (!is_string($source)) {
        $failures[] = $relative . ': unable to read source file';
        continue;
    }

    $audited++;
    foreach (\PhpGallery\SourceContracts\header_issues($source, $relative) as $rule) {
        $failures[] = $relative . ': ' . $rule . ' missing from the leading native header';
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Source header author contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo 'PASS source_header_author: complete native headers in ' . $audited . " first-party source files.\n";
