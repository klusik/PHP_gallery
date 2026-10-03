<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/python_import_policy_test.php
 * Module Type: Python Import Policy Regression Test
 * Purpose: Verify forbidden Python imports through AST and complete source discovery.
 * Responsibilities:
 *   - Cover import spellings, inert text, exclusions and safe parser failures.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/check_python_import_policy.php';

use function PhpGallery\SourceContracts\python_import_findings;
use function PhpGallery\SourceContracts\python_import_policy_report;

/**
 * Assert a policy invariant without printing fixture contents.
 * @param bool $condition Expected policy behavior.
 * @param string $message Safe diagnostic explaining the violated contract.
 * @return void
 */
function python_import_policy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$spellings = [
    "from __future__ import annotations\n",
    "from __future__ import (\n    annotations,\n)\n",
    "from __future__ import \\\n    annotations\n",
    "from __future__ import annotations as delayed\n",
    "from __future__ import division, annotations\n",
    "from __future__ import annotations; value = 1\n",
];
foreach ($spellings as $source) {
    $findings = python_import_findings($source);
    python_import_policy_assert(count($findings) === 1 && $findings[0]['line'] === 1
        && is_string($findings[0]['rule']) && $findings[0]['rule'] !== '', 'Every real forbidden import spelling must produce one located rule.');
}
$nested = python_import_findings("def nested():\n    from __future__ import annotations\n");
python_import_policy_assert(count($nested) === 1 && $nested[0]['line'] === 2, 'Nested syntactic imports must retain their policy occurrence even if future compilation would reject placement.');
$sites = python_import_findings("# Fixture preamble\nfrom __future__ import annotations\n\nfrom __future__ import (annotations)\n");
python_import_policy_assert(array_column($sites, 'line') === [2, 4], 'Separate import sites must retain their exact source lines.');
$allowed = <<<'PY'
"""Mention from __future__ import annotations without importing it."""
# from __future__ import annotations
from __future__ import division, print_function
import concurrent.futures
from concurrent.futures import Future
message = "from __future__ import annotations"
PY;
python_import_policy_assert(python_import_findings($allowed) === [], 'Comments, docstrings, strings and allowed imports must remain inert.');

$root = sys_get_temp_dir() . '/gallery-python-import-policy-' . bin2hex(random_bytes(8));
$files = [];
$directories = [$root];
try {
    mkdir($root);
    $sources = [
        'existing.py' => $spellings[0],
        'desktop.pyw' => $spellings[1],
        'allowed.py' => $allowed,
        'fixture.php' => '<?php $source = "from __future__ import annotations";',
    ];
    foreach (['cache', 'vendor', 'data', 'galleries', 'deploy', '.agent-local', '.venv', '__pycache__'] as $excluded) {
        mkdir($root . '/' . $excluded);
        $directories[] = $root . '/' . $excluded;
        $sources[$excluded . '/excluded.py'] = $spellings[0];
    }
    foreach ($sources as $path => $source) {
        $files[] = $root . '/' . $path;
        file_put_contents($root . '/' . $path, $source);
    }
    $report = python_import_policy_report($root);
    python_import_policy_assert($report['status'] === 'FAIL' && $report['summary']['source_files'] === 3
        && $report['summary']['finding_count'] === 2, 'Complete discovery must reject existing Python and PYW imports without Git metadata and prune excluded trees.');
    $paths = array_column($report['findings'], 'path');
    sort($paths);
    python_import_policy_assert($paths === ['desktop.pyw', 'existing.py'], 'Findings must identify only admitted source files.');
    $repeat = python_import_policy_report($root);
    python_import_policy_assert($repeat['status'] === 'FAIL' && $repeat['summary']['finding_count'] === 2, 'Unchanged files must continue to fail every policy pass.');
    $files[] = $root . '/broken.py';
    file_put_contents($root . '/broken.py', "private_fixture_secret = (\n");
    $blocked = python_import_policy_report($root);
    python_import_policy_assert($blocked['status'] === 'BLOCKED' && $blocked['blocked'] !== [], 'Unparseable admitted source must block complete coverage.');
    python_import_policy_assert(!str_contains(json_encode($blocked, JSON_THROW_ON_ERROR), 'private_fixture_secret'), 'Parser diagnostics must not disclose examined source contents.');
    unlink($root . '/broken.py');
    file_put_contents($root . '/existing.py', $allowed);
    file_put_contents($root . '/desktop.pyw', $allowed);
    $clean = python_import_policy_report($root);
    python_import_policy_assert($clean['status'] === 'PASS' && $clean['summary']['source_files'] === 3
        && $clean['summary']['finding_count'] === 0 && $clean['blocked'] === [], 'Removing prohibited imports must restore a complete PASS without treating excluded imports as findings.');
} finally {
    foreach ($files as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    foreach (array_reverse($directories) as $path) {
        if (is_dir($path)) {
            rmdir($path);
        }
    }
}

echo "Python import policy regression: PASS\n";
