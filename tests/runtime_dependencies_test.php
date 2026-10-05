<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/runtime_dependencies_test.php
 * Module Type: Regression Test
 * Purpose: Verify token-based dependency extraction for aliases and dynamic references.
 * Responsibilities: Exercise declaration ownership, grouped aliases, callbacks and typed symbol edges.
 * Author: Rudolf Klusal
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/runtime_dependencies.php';

use function Gallery\Tools\RuntimeDependencies\build_symbol_index;
use function Gallery\Tools\RuntimeDependencies\collect_symbol_dependencies;
use function Gallery\Tools\RuntimeDependencies\collect_declarations;
use function Gallery\Tools\RuntimeDependencies\parse_file_context;
use function Gallery\Tools\RuntimeDependencies\tokenize;

/**
 * Assert one scanner fixture condition and stop with an actionable error.
 *
 * @param bool $condition Condition expected to hold.
 * @param string $message Failure context.
 * @return void
 */
function runtime_dependency_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$sources = [
    'fixture/targets.php' => <<<'PHP'
<?php
namespace Gallery\Core;
function public_base_url(): string { return '/'; }
function classify(string $value): string { return $value; }
function optional_probe(): void {}
function application_update_beta_commit(): void {}
function admin_test_run_finalize(): void {}
function callback_probe(): void {}
class Path
{
    /** Build one path value for the dependency fixture. */
    public static function build(): void {}
}
class Runtime {}
const SITE_ROOT = '/';
PHP,
    'fixture/entry.php' => <<<'PHP'
<?php
namespace Fixture;
use function Gallery\Core\public_base_url;
use function Gallery\Core\classify as classifyValue;
use Gallery\Core\{Path as PublicPath, Runtime as RuntimeThing};
use const Gallery\Core\SITE_ROOT as SITE_BASE;
const LOCAL_FLAG = true;
function local_probe(): void {}
function callback_probe(): void {}
function application_update_beta_commit(): void {}
function admin_test_run_finalize(): void {}
function entry(callable $callback): string
{
    $path = new PublicPath();
    $runtime = new RuntimeThing();
    PublicPath::build();
    $base = public_base_url() . SITE_BASE;
    $kind = classifyValue($base);
    $setting = app_setting('application_update_beta_commit');
    $route = 'admin_test_run_finalize';
    if (function_exists(__NAMESPACE__ . '\\local_probe')) {
        local_probe();
    }
    call_user_func('Gallery\\Core\\classify');
    call_user_func('callback_probe');
    \\call_user_func('callback_probe');
    array_filter([], 'callback_probe');
    is_callable('callback_probe');
    $callback();
    return $kind . (LOCAL_FLAG ? '' : '');
}
PHP,
];

$files = [];
foreach ($sources as $path => $source) {
    $tokens = tokenize($source);
    $file = ['path' => $path, 'tokens' => $tokens, 'context' => parse_file_context($tokens)];
    $file['declarations'] = collect_declarations($file)['symbols'];
    $files[$path] = $file;
}
$index = build_symbol_index($files);
$entryId = 'fixture\\entry';
$entry = $index['symbols'][$entryId] ?? null;
runtime_dependency_assert(is_array($entry), 'entry declaration must be indexed.');
runtime_dependency_assert(isset($files['fixture/entry.php']['context']['constants']['site_base']), 'SITE_BASE import alias must be parsed.');
runtime_dependency_assert(isset($index['symbols']['const:Gallery\\Core\\SITE_ROOT']), 'target constant must be indexed.');
$analysis = collect_symbol_dependencies($files['fixture/entry.php'], $entry, $index['symbols']);
$targets = array_column($analysis['edges'], 'target');

runtime_dependency_assert(in_array('Gallery\\Core\\public_base_url', $targets, true), 'an imported function with an internal "as" substring must resolve.');
runtime_dependency_assert(in_array('Gallery\\Core\\classify', $targets, true), 'an aliased function import must resolve.');
runtime_dependency_assert(in_array('Gallery\\Core\\Path', $targets, true), 'a class alias inside a grouped import must resolve.');
runtime_dependency_assert(in_array('Gallery\\Core\\Runtime', $targets, true), 'all names in a grouped class import must resolve.');
runtime_dependency_assert(in_array('Gallery\\Core\\Path::build', $targets, true), 'an imported class static method call must resolve to its method declaration.');
runtime_dependency_assert(in_array('Gallery\\Core\\SITE_ROOT', $targets, true), 'an aliased imported constant must resolve; targets=' . implode(', ', $targets));
runtime_dependency_assert(in_array('Fixture\\LOCAL_FLAG', $targets, true), 'a local namespace constant must resolve.');
runtime_dependency_assert(in_array('Fixture\\local_probe', $targets, true), '__NAMESPACE__ concatenated function_exists references must resolve.');
runtime_dependency_assert(in_array('Gallery\\Core\\classify', $targets, true), 'a literal callback string must resolve.');
runtime_dependency_assert(in_array('Fixture\\callback_probe', $targets, true), 'plain callable literals in recognized callback APIs must resolve in the current namespace.');
runtime_dependency_assert(!in_array('Fixture\\application_update_beta_commit', $targets, true), 'plain setting-value strings matching a same-namespace function must not create callable edges.');
runtime_dependency_assert(!in_array('Fixture\\admin_test_run_finalize', $targets, true), 'plain route/data strings matching a same-namespace function must not create callable edges.');
runtime_dependency_assert(count(array_filter($analysis['unresolved'], static fn (array $item): bool => $item['kind'] === 'variable_callable')) === 1, 'a variable callback must be surfaced as unresolved.');

fwrite(STDOUT, "PASS: runtime dependency tokenizer fixture\n");
