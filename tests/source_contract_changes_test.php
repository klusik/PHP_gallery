<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/source_contract_changes_test.php
 * Module Type: Changed Declaration Gate Regression Test
 * Purpose: Prove read-only changed-declaration enforcement against disposable inputs.
 * Responsibilities:
 *   - Protect stable identities, unchanged legacy handling and unknown Git coverage.
 *   - Exercise typed contracts and unsupported syntax without staging or committing.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/check_source_documentation.php';

use function PhpGallery\SourceContracts\changed_documentation_report;
use function PhpGallery\SourceContracts\changed_source_contracts;
use function PhpGallery\SourceContracts\declaration_issues;
use function PhpGallery\SourceContracts\php_declarations;

/**
 * Stop fixture verification on a violated changed-source contract.
 * @param bool $condition Required scanner behavior.
 * @param string $message Safe diagnostic without fixture source values.
 * @return void
 */
function source_changes_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$legacy = '<?php function legacy(int $count): int { return $count; }';
$headerOnly = str_replace('<?php', "<?php\n/** Project: PHP Gallery\n * File: fixture.php\n * Author: Rudolf Klusal\n */\n", $legacy);
$result = changed_source_contracts($legacy, $headerOnly, 'fixture.php');
source_changes_assert($result['unchanged'] === 1 && $result['findings'] === [], 'Header metadata and line shifts must not rebaseline or reopen unchanged legacy declarations.');
$result = changed_source_contracts($legacy, str_replace('return $count;', 'return $count + 1;', $legacy), 'fixture.php');
source_changes_assert($result['changed'] === 1 && count($result['findings']) === 1, 'A legacy body change must require its current missing documentation.');
$documented = <<<'PHP'
<?php
/**
 * Return the supplied item count without mutation.
 * @param int $count Count of selected records.
 * @return int Selected record count.
 */
function documented(int $count): int { return $count; }
PHP;
$result = changed_source_contracts('', $documented, 'fixture.php');
source_changes_assert($result['added'] === 1 && $result['findings'] === [], 'A fully documented addition must pass.');
$removedDoc = '<?php function documented(int $count): int { return $count; }';
$result = changed_source_contracts($documented, $removedDoc, 'fixture.php');
source_changes_assert($result['unchanged'] === 1 && $result['doc_regressions'] === 1 && $result['findings'] !== [], 'Removing documentation must fail even without executable changes.');
$changedSignature = str_replace('int $count): int', 'string $count): int', $documented);
$result = changed_source_contracts($documented, $changedSignature, 'fixture.php');
source_changes_assert($result['changed'] === 1 && in_array('parameter.type_mismatch:count', array_column($result['findings'], 'rule'), true), 'Native signature changes must expose contradictory parameter tags.');
$missingType = str_replace('@param int $count', '@param $count', $documented);
source_changes_assert(in_array('parameter.type:count', declaration_issues(php_declarations($missingType)[0]), true), 'An untyped named parameter tag must not borrow its description as a type.');
$looseShape = str_replace(['@param int $count', '@return int', 'int $count): int'], ['@param array $count', '@return array', 'array $count): array'], $documented);
source_changes_assert(in_array('shape.unspecified', declaration_issues(php_declarations($looseShape)[0]), true), 'New bare array contracts must require a shape or element contract.');
$opaque = <<<'PHP'
<?php
/**
 * Return fixture rows without interpreting inherited driver arguments.
 * @param mixed ...$args Unrestricted inherited PDO arguments, intentionally unused.
 * @source-contract-opaque-param $args Unused inherited driver arguments; never inspected, forwarded, stored or logged.
 * @return list<array{id:int}> Disposable rows.
 */
function fixtureFetch(mixed ...$args): array { return []; }
PHP;
source_changes_assert(declaration_issues(php_declarations($opaque)[0]) === [], 'Proven-unused inherited mixed arguments may document unrestricted opaque values honestly.');
$usedOpaque = str_replace('return [];', 'return $args;', $opaque);
source_changes_assert(in_array('shape.unspecified', declaration_issues(php_declarations($usedOpaque)[0]), true),
    'An opaque marker cannot hide values read or forwarded by the body.');
$introspectedOpaque = str_replace('return [];', 'return func_get_args();', $opaque);
source_changes_assert(in_array('shape.unspecified', declaration_issues(php_declarations($introspectedOpaque)[0]), true),
    'Implicit argument introspection must prevent an unused-argument proof.');
$unexplainedOpaque = str_replace('@source-contract-opaque-param', '@unrecognized-marker', $opaque);
source_changes_assert(in_array('shape.unspecified', declaration_issues(php_declarations($unexplainedOpaque)[0]), true),
    'Unused mixed arguments still need an explicit semantic rationale.');
$multiline = <<<'PHP'
<?php
/**
 * Return the canonical bounded display record.
 * @param array{
 *   id:int,
 *   label:string
 * } $record Prepared public display fields.
 * @return array{id:int,label:string} Same display record.
 */
function display(array $record): array { return $record; }
PHP;
source_changes_assert(declaration_issues(php_declarations($multiline)[0]) === [], 'Multiline shapes must retain the actual parameter name and type.');
$property = php_declarations('<?php class Fixture { /** @var array<string,string> Private child environment, never printed. */ public array $environment; }')[1];
source_changes_assert(declaration_issues($property) === [], 'A typed var-tag description is a valid substantive property contract.');
$bareProperty = php_declarations('<?php class Fixture { /** @var array<string,string> */ public array $environment; }')[1];
source_changes_assert(in_array('documentation.summary', declaration_issues($bareProperty), true), 'A bare property type must still require its meaning.');
$namespaces = '<?php namespace One; function same() {} namespace Two; function same() {}';
$result = changed_source_contracts($namespaces, str_replace('namespace Two; function same() {}', 'namespace Two; function same() { return 1; }', $namespaces), 'fixture.php');
source_changes_assert($result['unchanged'] === 1 && $result['changed'] === 1
    && $result['findings'][0]['identity'] === 'Two/callable:same', 'Namespace identities must distinguish same-named declarations.');
$classes = '<?php class One { public function same() {} } class Two { public function same() {} }';
$result = changed_source_contracts($classes, str_replace('class Two { public function same() {} }', 'class Two { public function same() { return 1; } }', $classes), 'fixture.php');
source_changes_assert($result['changed'] === 2 && $result['unchanged'] === 2, 'A changed method must affect its owning class, not another same-named method.');
$closures = '<?php consume(fn(int $x): int => $x + 1); consume(fn(int $x): int => $x + 2);';
$result = changed_source_contracts($closures, str_replace('<?php ', '<?php consume(fn(int $x): int => $x + 3); ', $closures), 'fixture.php');
source_changes_assert($result['added'] === 1 && $result['unchanged'] === 2, 'Anonymous sibling insertion must not renumber or recheck unchanged callback debt.');
$originalSnapshots = \PhpGallery\SourceContracts\source_declaration_snapshots($legacy, 'old.php');
$movedSnapshots = \PhpGallery\SourceContracts\source_declaration_snapshots($legacy, 'new.php');
$move = \PhpGallery\SourceContracts\compare_declaration_snapshots($originalSnapshots, $movedSnapshots);
source_changes_assert($move['moved'] === 1 && $move['findings'] === [], 'An unchanged moved declaration retains visible legacy debt without becoming a new failure.');
$copy = \PhpGallery\SourceContracts\compare_declaration_snapshots($originalSnapshots, array_merge($movedSnapshots, $originalSnapshots));
source_changes_assert($copy['moved'] === 0 && $copy['added'] === 1 && $copy['findings'][0]['path'] === 'new.php',
    'An unchanged source copy is new code: same-path matches must consume originals before cross-file matching.');
source_changes_assert(!\PhpGallery\SourceContracts\source_head_declaration_allowed('vendor/deleted.php')
    && !\PhpGallery\SourceContracts\source_head_declaration_allowed('cache/deleted.php')
    && !\PhpGallery\SourceContracts\source_head_declaration_allowed('config.php'), 'Deleted HEAD paths must preserve discovery privacy boundaries.');
$result = changed_source_contracts($legacy, $legacy . "\n" . '$text = ' . var_export('function invented($secret) {}', true) . ';', 'fixture.php');
source_changes_assert($result['added'] === 0 && $result['findings'] === [], 'Declaration-looking string contents cannot create a changed declaration.');
$js = 'export class Widget { render(value) { return value; } }';
$result = changed_source_contracts($js, "/* Header only */\n" . str_replace('return value;', 'return   value;', $js), 'fixture.mjs');
source_changes_assert($result['unchanged'] === 2 && $result['findings'] === [], 'JavaScript header/ordinary spacing changes must preserve class and method identities.');
$result = changed_source_contracts($js, str_replace('return value;', "return\nvalue;", $js), 'fixture.mjs');
source_changes_assert($result['changed'] === 2, 'An ASI-sensitive return newline must count as an executable change.');
$jsTyped = <<<'JS'
/**
 * Return a prepared public label without mutation.
 * @param {{id:number,label:string}} value Prepared display record.
 * @return {string} Public label.
 */
export const label = value => value.label;
JS;
$result = changed_source_contracts('', $jsTyped, 'fixture.mjs');
source_changes_assert($result['added'] === 1 && $result['findings'] === [], 'Nested braced JSDoc shapes must satisfy a bound-arrow contract.');
$memberCallback = <<<'JS'
/**
 * Format an isolated numeric fixture identity without changing global state.
 * @param {number} value Synthetic identity.
 * @return {string} Printable identity.
 */
globalThis.fixture.format = value => String(value);
JS;
source_changes_assert(changed_source_contracts('', $memberCallback, 'fixture.mjs')['findings'] === [],
    'JSDoc above a static member assignment must attach to its actual callback declaration.');
$tupleCallback = <<<'JS'
pairs.forEach(/** Bind a control and display selector from a prepared pair.
 * @param {[string, string]} selectors Tuple destructured into control and display selectors.
 * @return {void} Connects the isolated control and display.
 */ ([controlSelector, displaySelector]) => { bind(controlSelector, displaySelector); });
JS;
source_changes_assert(changed_source_contracts('', $tupleCallback, 'fixture.js')['findings'] === [],
    'A positional fixed tuple contract must describe its aggregate input without rewriting runtime destructuring.');
foreach (['[string]', 'string[]', '[any, string]', '[string, ...string[]]'] as $wrongTuple) {
    $tupleIssues = changed_source_contracts('', str_replace('[string, string]', $wrongTuple, $tupleCallback), 'fixture.js')['findings'];
    source_changes_assert(in_array('parameter.pattern_review', array_column($tupleIssues, 'rule'), true),
        'Wrong-arity, loose or variable-length tuple contracts remain review findings.');
}
foreach (['[controlSelector, ...displaySelector]', '[controlSelector, [displaySelector]]', '[, displaySelector]', '{controlSelector, displaySelector}'] as $unsupportedPattern) {
    $tupleIssues = changed_source_contracts('', str_replace('[controlSelector, displaySelector]', $unsupportedPattern, $tupleCallback), 'fixture.js')['findings'];
    source_changes_assert(in_array('parameter.pattern_review', array_column($tupleIssues, 'rule'), true),
        'Unsupported destructuring must not receive a blanket documentation exemption.');
}
$result = changed_source_contracts('', str_replace('{{id:number,label:string}}', '{Object}', $jsTyped), 'fixture.mjs');
source_changes_assert(in_array('shape.unspecified', array_column($result['findings'], 'rule'), true), 'Bare JavaScript object types must not pass a new record contract.');
$result = changed_source_contracts('', 'class Widget { [computed]() {} }', 'fixture.mjs');
source_changes_assert($result['blocked'] !== [], 'Computed method syntax must report unknown coverage rather than hiding an unparsed method.');
$computedObject = changed_source_contracts('', 'const object = { [key]() {} };', 'fixture.mjs');
source_changes_assert($computedObject['blocked'] !== [], 'Top-level computed object methods must not evade the explicit coverage blocker.');
$template = 'const markup = ' . chr(96) . 'literal function fake() {} ' . '$' . '{items.map(item => item.id)}' . chr(96) . ';';
$result = changed_source_contracts('', $template, 'fixture.mjs');
source_changes_assert($result['added'] === 1 && $result['blocked'] === [], 'Template interpolation callbacks must be scanned while raw template text stays inert.');
$nestedTemplate = 'const markup = ' . chr(96) . '$' . '{items.map(item => ' . chr(96) . '$' . '{item.id}' . chr(96) . ')}' . chr(96) . ';';
$result = changed_source_contracts('', $nestedTemplate, 'fixture.mjs');
source_changes_assert($result['added'] === 1 && $result['blocked'] === [], 'Nested template literals must preserve the expression callback boundary.');
$literalKeyword = 'const text = ' . chr(96) . '$' . '{first}class' . '$' . '{second}' . chr(96) . ';';
source_changes_assert(changed_source_contracts('', $literalKeyword, 'fixture.mjs')['added'] === 0,
    'A raw template chunk equal to a declaration keyword must remain inert.');
$html = "<!doctype html>\n<p>Static fixture</p>\n<script>\nfunction inlineFixture(value) { return value; }\n</script>";
$result = changed_source_contracts('', $html, 'fixture.html');
source_changes_assert($result['added'] === 1 && $result['findings'][0]['line'] === 4, 'HTML inline scripts must expose declarations at original source lines.');
$dataHtml = '<!-- <script>function fake() {}</script> --><script type="application/json">{"label":"function fake() {}"}</script><p>Static</p>';
source_changes_assert(changed_source_contracts('', $dataHtml, 'fixture.html')['added'] === 0, 'Inert HTML comments, data scripts and ordinary markup have no callable declarations.');
$quotedComment = '<script>function label() { return "<!-- first -->"; }</script>';
$result = changed_source_contracts($quotedComment, str_replace('first', 'second', $quotedComment), 'fixture.html');
source_changes_assert($result['changed'] === 1, 'HTML comment syntax inside JavaScript strings must remain executable fingerprint data.');
$unknownScript = false;
try {
    \PhpGallery\SourceContracts\html_script_sources('<script type=text/unknown>opaque fixture</script>');
} catch (RuntimeException) {
    $unknownScript = true;
}
source_changes_assert($unknownScript, 'Unknown quoted or unquoted HTML script languages must not be treated as JavaScript.');

$root = sys_get_temp_dir() . '/gallery-source-changes-' . bin2hex(random_bytes(8));
$paths = ['fixture.php', 'new.php', 'tool.py', 'config.php', 'theme.css', 'workflow.yml', 'page.html'];
mkdir($root);
try {
    file_put_contents($root . '/fixture.php', $headerOnly);
    file_put_contents($root . '/new.php', $documented);
    file_put_contents($root . '/tool.py', "# Project: PHP Gallery\n# Changed header only\nprint('fixture')\n");
    file_put_contents($root . '/config.php', '<?php throw new Exception("private-gate-sentinel");');
    file_put_contents($root . '/theme.css', '.fixture { color: red; }');
    file_put_contents($root . '/workflow.yml', "name: Fixture\n");
    file_put_contents($root . '/page.html', '<p>Ordinary markup</p>');
    $head = ['fixture.php' => $legacy, 'tool.py' => "# Original header\nprint('fixture')\n", 'config.php' => 'private-head-sentinel'];
    $verbs = [];
    /**
     * Supply immutable HEAD fixtures and record every permitted read-only command.
     * @param list<string> $arguments Literal Git request.
     * @param string $directory Disposable repository root.
     * @return array{status:int,stdout:string} Simulated immutable Git response.
     */
    $git = static function (array $arguments, string $directory) use (&$head, &$verbs): array {
        $verbs[] = $arguments[0];
        if ($arguments[0] === 'rev-parse') {
            return ['status' => 0, 'stdout' => $directory . "\n"];
        }
        if ($arguments[0] === 'ls-tree') {
            $tree = '';
            foreach (array_keys($head) as $path) {
                $tree .= "100644 blob " . str_repeat('a', 40) . "\t" . $path . "\0";
            }
            return ['status' => 0, 'stdout' => $tree];
        }
        if ($arguments[0] === 'diff') {
            return ['status' => 0, 'stdout' => implode("\0", array_keys($head)) . "\0"];
        }
        if ($arguments[0] === 'cat-file') {
            $path = substr($arguments[2], strlen('HEAD:'));
            source_changes_assert($path !== 'config.php', 'Excluded private HEAD blobs must never be requested.');
            return isset($head[$path]) ? ['status' => 0, 'stdout' => $head[$path]] : ['status' => 1, 'stdout' => ''];
        }
        throw new RuntimeException('Unexpected Git operation in read-only fixture.');
    };
    $report = changed_documentation_report($root, [], $git);
    source_changes_assert($report['status'] === 'PASS' && $report['summary']['added'] === 1 && $report['summary']['unchanged'] === 1,
        'Tracked header-only legacy and an untracked documented addition must pass without staged fixture commits.');
    source_changes_assert(array_diff($verbs, ['rev-parse', 'ls-tree', 'diff', 'cat-file']) === [], 'Gate must never write Git state.');
    source_changes_assert(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'private-'), 'Git comparison reports must not expose excluded source values.');
    file_put_contents($root . '/new.php', $legacy);
    $report = changed_documentation_report($root, ['new.php'], $git);
    source_changes_assert($report['status'] === 'FAIL' && $report['summary']['finding_count'] > 0, 'Untracked undocumented source must fail, not disappear from the diff.');
    file_put_contents($root . '/tool.py', "print('changed-fixture')\n");
    $report = changed_documentation_report($root, ['tool.py'], $git);
    source_changes_assert($report['status'] === 'BLOCKED', 'Unsupported changed native source must disclose unknown coverage.');
    /**
     * Simulate missing Git/HEAD without invoking or modifying a repository.
     * @param list<string> $arguments Requested read operation.
     * @param string $directory Disposable root.
     * @return array{status:int,stdout:string} Unavailable command response.
     */
    $unavailable = static function (array $arguments, string $directory): array {
        return ['status' => 1, 'stdout' => ''];
    };
    source_changes_assert(changed_documentation_report($root, [], $unavailable)['status'] === 'BLOCKED',
        'Missing Git history must never pass as complete coverage.');
} finally {
    foreach ($paths as $path) {
        unlink($root . '/' . $path);
    }
    rmdir($root);
}
echo "PASS source_contract_changes: declaration identities, typed contracts, read-only Git fixtures and blocked coverage.\n";
