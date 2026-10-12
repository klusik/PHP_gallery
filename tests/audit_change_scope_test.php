<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/audit_change_scope_test.php
 * Module Type: Regression Test
 * Purpose: Verify that future change-aware audit scope classification fails closed.
 * Responsibilities:
 *   - Admit only verified modifications to maintained TeX manual sources
 *   - Reject mixed, unknown, renamed and deleted changes without skipping tests
 *   - Keep all classification assertions independent of Git and hosted CI state
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/audit_lib.php';

use function PhpGallery\Audit\classify_source_change_scope;

$manuals = [
    'docs/PHP_Gallery_Manual.tex',
    'docs/PHP_Gallery_Manual_CZ.tex',
    'docs/PHP_Gallery_Manual_DE.tex',
    'docs/PHP_Gallery_Manual_SV.tex',
];
$modifiedManual = [['status' => 'M', 'path' => $manuals[0]]];
$cases = [
    ['name' => 'single maintained manual', 'verified' => true, 'changes' => $modifiedManual, 'scope' => 'manual-source', 'reason' => 'maintained-manual-modifications-only'],
    ['name' => 'four maintained manuals', 'verified' => true,
        'changes' => array_map(static fn(string $path): array => ['status' => 'M', 'path' => $path], $manuals),
        'scope' => 'manual-source', 'reason' => 'maintained-manual-modifications-only'],
    ['name' => 'unverified history', 'verified' => false, 'changes' => $modifiedManual, 'scope' => 'full', 'reason' => 'unverified-baseline'],
    ['name' => 'no changed files', 'verified' => true, 'changes' => [], 'scope' => 'full', 'reason' => 'empty-diff'],
    ['name' => 'manual plus PHP', 'verified' => true,
        'changes' => [...$modifiedManual, ['status' => 'M', 'path' => 'app/services/gallery_access.php']],
        'scope' => 'full', 'reason' => 'path-out-of-scope'],
    ['name' => 'manual plus test', 'verified' => true,
        'changes' => [...$modifiedManual, ['status' => 'M', 'path' => 'tests/gallery_workflow_browser_test.php']],
        'scope' => 'full', 'reason' => 'path-out-of-scope'],
    ['name' => 'manual plus workflow', 'verified' => true,
        'changes' => [...$modifiedManual, ['status' => 'M', 'path' => '.github/workflows/gallery-workflows.yml']],
        'scope' => 'full', 'reason' => 'path-out-of-scope'],
    ['name' => 'manual plus generated manifest', 'verified' => true,
        'changes' => [...$modifiedManual, ['status' => 'M', 'path' => 'manifest.json']],
        'scope' => 'full', 'reason' => 'path-out-of-scope'],
    ['name' => 'migration modification', 'verified' => true,
        'changes' => [['status' => 'M', 'path' => 'migrations/202609250001_gallery_creation_preferences.php']],
        'scope' => 'full', 'reason' => 'path-out-of-scope'],
    ['name' => 'other documentation', 'verified' => true,
        'changes' => [['status' => 'M', 'path' => 'docs/RELEASE_LIFECYCLE.md']],
        'scope' => 'full', 'reason' => 'path-out-of-scope'],
    ['name' => 'non-maintained TeX', 'verified' => true,
        'changes' => [['status' => 'M', 'path' => 'docs/Some_Other_Manual.tex']],
        'scope' => 'full', 'reason' => 'path-out-of-scope'],
    ['name' => 'renamed manual', 'verified' => true,
        'changes' => [['status' => 'R100', 'path' => $manuals[0]]],
        'scope' => 'full', 'reason' => 'non-modification-status'],
    ['name' => 'deleted manual', 'verified' => true,
        'changes' => [['status' => 'D', 'path' => $manuals[0]]],
        'scope' => 'full', 'reason' => 'non-modification-status'],
    ['name' => 'added manual', 'verified' => true,
        'changes' => [['status' => 'A', 'path' => $manuals[0]]],
        'scope' => 'full', 'reason' => 'non-modification-status'],
    ['name' => 'unknown status', 'verified' => true,
        'changes' => [['status' => 'U', 'path' => $manuals[0]]],
        'scope' => 'full', 'reason' => 'non-modification-status'],
    ['name' => 'missing path', 'verified' => true,
        'changes' => [['status' => 'M']],
        'scope' => 'full', 'reason' => 'path-out-of-scope'],
    ['name' => 'malformed path', 'verified' => true,
        'changes' => [['status' => 'M', 'path' => '../docs/PHP_Gallery_Manual.tex']],
        'scope' => 'full', 'reason' => 'path-out-of-scope'],
    ['name' => 'case mismatch', 'verified' => true,
        'changes' => [['status' => 'M', 'path' => 'docs/php_gallery_manual.tex']],
        'scope' => 'full', 'reason' => 'path-out-of-scope'],
    ['name' => 'backslash path', 'verified' => true,
        'changes' => [['status' => 'M', 'path' => 'docs\\PHP_Gallery_Manual.tex']],
        'scope' => 'full', 'reason' => 'path-out-of-scope'],
];

foreach ($cases as $case) {
    $result = classify_source_change_scope($case['changes'], $case['verified']);
    if ($result['scope'] !== $case['scope'] || $result['reason'] !== $case['reason']) {
        fwrite(STDERR, 'FAIL audit change scope: ' . $case['name']
            . '; expected ' . $case['scope'] . '/' . $case['reason']
            . ', received ' . ($result['scope'] ?? '(missing)') . '/' . ($result['reason'] ?? '(missing)') . "\n");
        exit(1);
    }
}

echo 'PASS audit change scope: ' . count($cases) . " conservative positive/negative cases\n";
