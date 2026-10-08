<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/agent_authoring_examples_test.php
 * Module Type: Agent Authoring Regression Test
 * Purpose: Validate published examples and complete source-failure evidence with existing scanners.
 * Responsibilities:
 *   - Parse documented examples without executing their source.
 *   - Preserve failures for incomplete declarations and policy explanations.
 *   - Verify immutable comparison evidence and complete failure rendering.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/check_policy_constants.php';
require_once dirname(__DIR__) . '/scripts/audit_source_evidence.php';

use function PhpGallery\SourceContracts\declaration_issues;
use function PhpGallery\SourceContracts\changed_documentation_report;
use function PhpGallery\SourceContracts\header_issues;
use function PhpGallery\SourceContracts\javascript_declarations;
use function PhpGallery\SourceContracts\php_declarations;
use function PhpGallery\SourceContracts\python_declaration_snapshots;
use function PhpGallery\SourceContracts\policy_site_snapshots;
use function PhpGallery\SourceContracts\policy_source;
use function PhpGallery\Audit\audit_source_identity;
use function PhpGallery\Audit\render_source_failures;
use function PhpGallery\Audit\run_process;
use function PhpGallery\Audit\resolve_executable;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$root = dirname(__DIR__);
$document = (string) file_get_contents($root . '/docs/AGENT_AUTHORING.md');
preg_match_all('/<!-- checked-example: ([a-z-]+) -->\s*```(\w+)\n(.*?)\n```/s', $document, $matches, PREG_SET_ORDER);
$examples = [];
foreach ($matches as $match) {
    $assert(!isset($examples[$match[1]]), 'Published example identifiers must be unique.');
    $examples[$match[1]] = $match[3];
}
$assert(array_keys($examples) === ['php-declaration', 'js-declaration', 'python-declaration',
    'php-policy', 'js-policy', 'php-header'], 'Every promised authoring example must be checked.');

foreach ([php_declarations($examples['php-declaration']),
    javascript_declarations($examples['js-declaration']),
    array_column(python_declaration_snapshots($examples['python-declaration']), 'record')] as $records) {
    $named = array_values(array_filter($records, static fn(array $record): bool => $record['kind'] !== 'callback'));
    $assert(count($named) === 1, 'A documented declaration example must contain exactly one named declaration.');
    foreach ($records as $record) {
        $assert(declaration_issues($record) === [], 'Published declaration examples must pass their existing scanner on first inspection.');
    }
}
$tagOnly = preg_replace('/^ \* Find the selected file occurrence[^\n]*\n/m', '', $examples['js-declaration']);
$assert(in_array('documentation.summary', declaration_issues(javascript_declarations($tagOnly)[0]), true),
    'Removing the purpose summary must still fail.');
$added = str_replace('bool $panelMode = false', 'bool $panelMode = false, bool $includePending = false', $examples['php-declaration']);
$assert(in_array('parameter.missing:includePending', declaration_issues(php_declarations($added)[0]), true),
    'A newly added defaulted parameter needs documentation.');
$opaque = str_replace('Array<{id: string, file: File, source: string}>', 'Object', $examples['js-declaration']);
$assert(in_array('shape.unspecified', declaration_issues(javascript_declarations($opaque)[0]), true),
    'Replacing a concrete collection shape with Object must still fail.');

foreach (['php-policy' => 'app/services/example_preview.php', 'js-policy' => 'public/assets/example-preview.js'] as $id => $path) {
    $snapshots = policy_site_snapshots($examples[$id], $path)['snapshots'];
    $assert(count($snapshots) === 1 && $snapshots[0]['record']['missing'] === [],
        'Published policy examples must explain all six strict policy fields.');
    $definitions = policy_source($examples[$id], $path)['definitions'];
    $assert(count($definitions) === 1 && $definitions[0]['missing_documentation'] === [],
        'Published policy examples must also pass the whole-tree inventory.');
    $missing = preg_replace('~/\*\*.*?\*/~s', '', $examples[$id], 1);
    $negative = policy_site_snapshots($missing, $path)['snapshots'][0]['record']['missing'];
    $assert($negative === ['purpose', 'type', 'units', 'scope', 'consumers', 'rationale'],
        'An undocumented runtime constant must retain every missing-field diagnostic.');
}
$assert(header_issues($examples['php-header'], 'app/services/example_preview.php') === [],
    'The published attribution header must pass for its documented path.');
$assert(header_issues($examples['php-header'], 'app/services/other.php') !== [],
    'Copying a header without correcting the file identity must fail.');

$findings = [];
for ($index = 0; $index < 45; $index++) {
    $findings[] = ['path' => 'public/example.js', 'line' => $index + 1,
        'name' => 'example' . $index, 'kind' => 'callable', 'rule' => 'documentation.summary'];
}
$category = 'documentation|runtime|js|callable|documentation.summary';
$tasks = [
    ['id' => 'source-documentation-changed', 'label' => 'Changed declarations', 'status' => 'FAIL',
        'summary' => '45 findings', 'details' => ['artifacts' => ['changed' => 'changed.json']]],
    ['id' => 'source-contract-inventory', 'label' => 'Inventory budgets', 'status' => 'FAIL',
        'summary' => 'One category grew', 'details' => ['artifacts' => [
            'documentation_json' => 'inventory.json', 'debt_ratchet_json' => 'ratchet.json']]],
    ['id' => 'python-import-policy', 'label' => 'Python imports', 'status' => 'BLOCKED',
        'summary' => 'Parser unavailable', 'details' => ['problems' => ['Missing Python coverage']]],
    ['id' => 'source-policy-changed', 'label' => 'Passing policy must stay quiet', 'status' => 'PASS', 'summary' => '0 findings'],
];
$source = ['head_sha' => str_repeat('a', 40), 'comparison_base' => str_repeat('b', 40),
    'requested_base' => 'HEAD', 'worktree_dirty' => true];
$rendered = render_source_failures(['profile' => 'release-preflight', 'source' => $source, 'tasks' => $tasks], [
    'changed.json' => ['findings' => $findings, 'blocked' => []],
    'inventory.json' => ['inventory' => ['files' => ['public/example.js' => 'js', 'scripts/legacy.js' => 'js']],
        'findings' => [$findings[0], ['path' => 'scripts/legacy.js', 'line' => 1,
            'name' => 'unrelatedDebt', 'kind' => 'callable', 'rule' => 'documentation.summary']]],
    'ratchet.json' => ['evaluation' => ['violations' => [$category => ['cap' => 0, 'current' => 1, 'increase' => 1]]]],
]);
$assert(str_contains($rendered, 'example44') && str_contains($rendered, $source['comparison_base'])
    && str_contains($rendered, 'Missing Python coverage') && str_contains($rendered, 'not qualification of clean HEAD'),
    'Complete evidence must retain findings beyond console limits, immutable identity, dirty state and blockers.');
$assert(!str_contains($rendered, 'unrelatedDebt') && !str_contains($rendered, 'Passing policy must stay quiet'),
    'Evidence must omit passing suites and inventory categories that remain within their budgets.');

$git = resolve_executable('PHP_GALLERY_GIT', ['git']);
$assert($git !== null, 'Git is required for the immutable authoring-base regression.');
$fixture = sys_get_temp_dir() . '/gallery-authoring-' . bin2hex(random_bytes(6));
mkdir($fixture, 0700);
try {
    foreach ([['init', '--quiet'], ['config', 'user.name', 'Authoring fixture'],
        ['config', 'user.email', 'fixture@example.invalid']] as $arguments) {
        $assert(run_process(array_merge([$git], $arguments), $fixture, 10)['exit_code'] === 0, 'Disposable Git initialization failed.');
    }
    mkdir($fixture . '/app/services', 0700, true);
    $fixtureSource = $examples['php-header'] . "\n" . substr($examples['php-declaration'], strpos($examples['php-declaration'], '/**'));
    $fixturePath = $fixture . '/app/services/example_preview.php';
    file_put_contents($fixturePath, $fixtureSource);
    $assert(run_process([$git, 'add', 'app/services/example_preview.php'], $fixture, 10)['exit_code'] === 0, 'Fixture membership staging failed.');
    $assert(run_process([$git, 'commit', '--quiet', '-m', 'initial'], $fixture, 10)['exit_code'] === 0, 'Fixture initial commit failed.');
    $initial = trim(run_process([$git, 'rev-parse', 'HEAD'], $fixture, 10)['stdout']);
    file_put_contents($fixturePath, str_replace('bool $panelMode = false',
        'bool $panelMode = false, bool $includePending = false', $fixtureSource));
    $assert(run_process([$git, 'commit', '--quiet', '-am', 'later'], $fixture, 10)['exit_code'] === 0, 'Fixture later commit failed.');
    $identity = audit_source_identity($fixture, $git, $initial);
    $assert($identity['comparison_base'] === $initial && $identity['head_sha'] !== $initial
        && $identity['worktree_dirty'] === false, 'A clean HEAD must not erase the immutable earlier comparison base.');
    $branchReport = changed_documentation_report($fixture, [], null, $identity['comparison_base']);
    $headReport = changed_documentation_report($fixture);
    $assert($branchReport['status'] === 'FAIL' && in_array('parameter.missing:includePending',
        array_column($branchReport['findings'], 'rule'), true) && $headReport['status'] === 'PASS',
        'The branch comparison must catch an already committed omission that an uncommitted-only HEAD check misses.');
    file_put_contents($fixture . '/untracked.txt', 'pending');
    $assert(audit_source_identity($fixture, $git, $initial)['worktree_dirty'] === true, 'Untracked authored files must mark checkout feedback dirty.');
    $assert(audit_source_identity($fixture, $git, 'missing-ref')['comparison_base'] === null,
        'Missing base history must not silently resolve to HEAD.');
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($fixture);
}
echo "PASS checked authoring examples, negative contracts, complete source evidence and immutable base\n";
