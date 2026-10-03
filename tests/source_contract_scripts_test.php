<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/source_contract_scripts_test.php
 * Module Type: Native Script Documentation Regression Test
 * Purpose: Verify changed Bash and PowerShell function comments without executing scripts.
 * Responsibilities:
 *   - Protect literal boundaries, documentation regressions and immutable Git comparison.
 *   - Keep unsupported script forms and incomplete lexical coverage blocked.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/check_source_documentation.php';

use function PhpGallery\SourceContracts\changed_documentation_report;
use function PhpGallery\SourceContracts\changed_source_contracts;

/**
 * Stop scanner regression verification when a required behavior is absent.
 * @param bool $condition Expected contract result.
 * @param string $message Safe failure description, without fixture source values.
 * @return void
 */
function script_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$shell = <<<'SH'
#!/usr/bin/env bash
# Return success only for the exact installation-owned stylesheet.
should_skip() {
    local path="$1"
    if [[ "$path" == 'public/assets/custom.css' ]]; then
        return 0
    fi
    printf '%s' '${value#prefix} and a literal } # marker'
    cat <<'HELP'
fake_function() { missing documentation; }
HELP
    return 1
}
SH;
$powershell = <<<'PS'
<#
.SYNOPSIS
Exclude the installation-owned stylesheet before applying include rules.
#>
function Should-Skip($Path) {
    if ($Path -eq 'public/assets/custom.css') {
        return $true
    }
    $example = 'It''s a literal } # marker'
    $help = @'
function Fake-Function { missing documentation }
'@
    return $false
}
PS;
foreach (['fixture.sh' => $shell, 'fixture.ps1' => $powershell, 'fixture.psm1' => $powershell] as $path => $source) {
    $result = changed_source_contracts('', $source, $path);
    script_contract_assert($result['added'] === 1 && $result['findings'] === [],
        'Documented native function must pass without counting declarations inside literals.');
    $changed = str_replace('public/assets/custom.css', 'public/assets/another.css', $source);
    $result = changed_source_contracts($source, $changed, $path);
    script_contract_assert($result['changed'] === 1 && $result['findings'] === [],
        'Quoted policy values must participate in executable fingerprints.');
    $withoutComment = substr($source, strpos($source, $path === 'fixture.sh' ? 'should_skip()' : 'function Should-Skip'));
    $result = changed_source_contracts($source, $withoutComment, $path);
    script_contract_assert($result['unchanged'] === 1 && $result['doc_regressions'] === 1
        && array_column($result['findings'], 'rule') === ['documentation.missing'],
        'Removing native function documentation must fail even when executable tokens are unchanged.');
    $result = changed_source_contracts('', $withoutComment, $path);
    script_contract_assert(array_column($result['findings'], 'rule') === ['documentation.missing'],
        'An undocumented native function addition must fail.');
    $commentOnly = str_replace('    return', "    # Explain the existing return without altering execution.\n    return", $source);
    $result = changed_source_contracts($source, $commentOnly, $path);
    script_contract_assert($result['unchanged'] === 1 && $result['findings'] === [],
        'Body comment additions must not manufacture executable changes.');
}

$unsupported = [
    ['fixture.sh', "printf ready; inline_function() { return 0; }\n"],
    ['fixture.sh', "# Describe a subshell-backed function.\nsubshell() ( return 0; )\n"],
    ['fixture.sh', "# Describe a dynamically generated function.\neval 'generated() { return 0; }'\n"],
    ['fixture.sh', "# Describe a malformed ordinary function.\ninvalid() { printf 'unterminated\n"],
    ['fixture.sh', "# Describe an incomplete here-document.\ninvalid() { cat <<HELP\nmissing terminator\n"],
    ['fixture.ps1', "# Describe an unsupported filter declaration.\nfilter Read-Value { \$_ }\n"],
    ['fixture.ps1', "# Describe an unsupported scoped function.\nfunction script:Read-Value { return 1 }\n"],
    ['fixture.ps1', "# Describe dynamic function creation.\nInvoke-Expression 'function Created { return 1 }'\n"],
    ['fixture.ps1', "# Describe an unbalanced function.\nfunction Invalid { if (\$true) { return 1 }\n"],
];
foreach ($unsupported as [$path, $source]) {
    $blocked = false;
    try {
        changed_source_contracts('', $source, $path);
    } catch (RuntimeException) {
        $blocked = true;
    }
    script_contract_assert($blocked, 'Unsupported native script syntax must not silently pass.');
}

$root = sys_get_temp_dir() . '/gallery-script-contract-' . bin2hex(random_bytes(8));
mkdir($root);
$head = ['fixture.sh' => $shell, 'fixture.ps1' => $powershell];
try {
    foreach ($head as $path => $source) {
        file_put_contents($root . '/' . $path, str_replace('public/assets/custom.css', 'public/assets/another.css', $source));
    }
    /**
     * Supply immutable historical scripts using only the gate's read-only Git verbs.
     * @param list<string> $arguments Literal Git request.
     * @param string $directory Disposable comparison root.
     * @return array{status:int,stdout:string} Fixture Git response.
     */
    $git = static function (array $arguments, string $directory) use ($head): array {
        if ($arguments[0] === 'rev-parse') {
            return ['status' => 0, 'stdout' => $directory];
        }
        if ($arguments[0] === 'ls-tree') {
            $tree = '';
            foreach (array_keys($head) as $path) {
                $tree .= '100644 blob ' . str_repeat('a', 40) . "\t" . $path . "\0";
            }
            return ['status' => 0, 'stdout' => $tree];
        }
        if ($arguments[0] === 'diff') {
            return ['status' => 0, 'stdout' => implode("\0", array_keys($head)) . "\0"];
        }
        if ($arguments[0] === 'cat-file') {
            return ['status' => 0, 'stdout' => $head[substr($arguments[2], strlen('HEAD:'))]];
        }
        throw new RuntimeException('Unexpected Git operation in script fixture.');
    };
    $report = changed_documentation_report($root, [], $git);
    script_contract_assert($report['status'] === 'PASS' && $report['summary']['changed'] === 2,
        'Central changed-source reporting must admit both documented native script changes.');
    file_put_contents($root . '/fixture.ps1', 'function Broken {');
    $report = changed_documentation_report($root, ['fixture.ps1'], $git);
    script_contract_assert($report['status'] === 'BLOCKED' && $report['blocked'] !== [],
        'Incomplete native script coverage must remain BLOCKED in the central report.');
} finally {
    foreach (array_keys($head) as $path) {
        unlink($root . '/' . $path);
    }
    rmdir($root);
}

echo "PASS source_contract_scripts: documented functions, literal boundaries and blocked unsupported syntax.\n";
