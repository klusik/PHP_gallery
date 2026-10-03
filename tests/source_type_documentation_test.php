<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/source_type_documentation_test.php
 * Module Type: Source Type Documentation Regression Test
 * Purpose: Verify documentation and native annotation coverage without source execution.
 * Responsibilities:
 *   - Exercise PHP, JavaScript and isolated Python declaration contracts.
 *   - Protect documentation-only regression and safe parser failure behavior.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/check_source_documentation.php';
require_once dirname(__DIR__) . '/scripts/source_contracts/python.php';

use function PhpGallery\SourceContracts\changed_source_contracts;
use function PhpGallery\SourceContracts\changed_documentation_report;
use function PhpGallery\SourceContracts\declaration_issues;
use function PhpGallery\SourceContracts\javascript_declarations;
use function PhpGallery\SourceContracts\php_declarations;
use function PhpGallery\SourceContracts\python_declaration_snapshots;

/**
 * Fail a fixture assertion with a bounded diagnostic.
 * @param bool $condition Required contract behavior.
 * @param string $message Safe explanation excluding source contents.
 * @return void
 */
function source_type_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Check a declaration's required and forbidden stable rule identifiers.
 * @param array<string,mixed> $record Scanner declaration with documentation and signature.
 * @param list<string> $required Findings that must be emitted.
 * @param list<string> $forbidden Findings that must not be emitted.
 * @return void
 */
function source_type_rules(array $record, array $required, array $forbidden = []): void
{
    $issues = declaration_issues($record);
    source_type_assert(array_diff($required, $issues) === [], 'Required documentation or typing rule was absent.');
    source_type_assert(array_intersect($forbidden, $issues) === [], 'A valid contract emitted an unexpected rule.');
}

$php = <<<'PHP'
<?php
/**
 * Preserve the supplied record count without mutation.
 * @param int $count Selected record count.
 * @return int Preserved record count.
 */
function preserve(int $count): int { return $count; }
PHP;
$record = php_declarations($php)[0];
source_type_assert(declaration_issues($record) === [], 'A complete PHP signature must pass.');
source_type_rules(php_declarations('<?php function absent($count) { return $count; }')[0],
    ['documentation.missing', 'typing.parameter_missing:count', 'typing.return_missing']);
source_type_rules(php_declarations(str_replace('int $count): int', '$count)', $php))[0],
    ['typing.parameter_missing:count', 'typing.return_missing']);
source_type_rules(php_declarations(str_replace('function preserve(int $count): int', 'function __construct(int $count)', $php))[0],
    [], ['typing.return_missing']);
source_type_rules(php_declarations(str_replace('@param int $count Selected record count.', '@param string $other Another record count.', $php))[0],
    ['parameter.missing:count', 'parameter.extra:other']);
source_type_rules(php_declarations(str_replace('@param int $count Selected record count.', '@param string $count Wrong count type.', $php))[0],
    ['parameter.type_mismatch:count']);
source_type_rules(php_declarations(str_replace('@return int Preserved record count.', '@return string Wrong result type.', $php))[0],
    ['return.type_mismatch']);
source_type_rules(php_declarations(str_replace('@return int Preserved record count.', "@return int\n * @return int Another count.", $php))[0],
    ['return.description', 'return.duplicate']);
source_type_rules(php_declarations(str_replace('@param int $count Selected record count.', "@param int \$count\n * @param int \$count Duplicate count.", $php))[0],
    ['parameter.description:count', 'parameter.duplicate:count']);
source_type_rules(php_declarations(str_replace('@return int Preserved record count.', '@return {} Missing result type.', $php))[0], ['return.type']);
source_type_rules(php_declarations(str_replace('@param int $count Selected record count.', '@param list<int $count Broken container delimiter.', $php))[0], ['parameter.type:count']);

$javascript = <<<'JS'
/**
 * Collect a typed count with optional additional values.
 * @param {number} [count=1] Initial selected count.
 * @param {...number} extra Additional selected counts.
 * @returns {number} Combined selected count.
 */
const collect = (count = 1, ...extra) => count + extra.length;
JS;
$records = javascript_declarations($javascript);
source_type_assert(count($records) === 1 && declaration_issues($records[0]) === [], 'JavaScript defaults/rest arrow contract must pass.');
source_type_rules(javascript_declarations(str_replace('{number}', 'number', $javascript))[0], ['parameter.format', 'return.format']);
source_type_rules(javascript_declarations(str_replace('@returns {number} Combined selected count.', '@returns {number Missing brace.', $javascript))[0], ['return.type']);
source_type_rules(javascript_declarations(str_replace('@param {number} [count=1] Initial selected count.', '@param {list[int} count Broken container delimiter.', $javascript))[0], ['parameter.type:count']);
source_type_rules(javascript_declarations(str_replace('@param {number} [count=1] Initial selected count.', "@param {number} [count=1] Initial selected count.\n * @param {number} bogus.child Undeclared nested root.", $javascript))[0], ['parameter.extra:bogus.child']);

$python = <<<'PY'
raise RuntimeError("SOURCE_MUST_NEVER_EXECUTE")
class Collector:
    """Collect annotated values through explicit callable contracts."""
    @classmethod
    async def collect(cls, value: int, /, *extra: int, flag: bool = False, **options: str) -> int:
        """Collect selected counts with named behavior options.

        Args:
            value (int): Initial selected count.
            extra (int): Additional selected counts.
            flag (bool): Optional selection mode.
            options (str): Named selection options.
        Returns:
            int: Combined selected count.
        """
        def nested(item: int) -> int:
            """Preserve a nested selected count.
            @param int item Selected nested count.
            @return int Preserved selected count.
            """
            return item
        return nested(value)
    @staticmethod
    def static(self: int) -> int:
        """Preserve an explicit static argument.
        @param int self Explicit selected count.
        @return int Preserved selected count.
        """
        return self
PY;
$snapshots = python_declaration_snapshots($python);
source_type_assert(count($snapshots) === 4, 'Python classes, async methods and nested functions must all be scanned.');
foreach ($snapshots as $snapshot) {
    source_type_assert(declaration_issues($snapshot['record']) === [], 'Typed Python Google/tag documentation must pass.');
}
source_type_assert(array_column($snapshots[1]['record']['params'], 'name') === ['value', 'flag', 'extra', 'options'], 'Python signature must include every explicit argument and exclude the bound receiver.');
source_type_assert(array_column($snapshots[3]['record']['params'], 'name') === ['self'], 'Static methods must retain an explicit receiver-shaped parameter.');
$qualifiedStatic = str_replace('@staticmethod', '@builtins.staticmethod', $python);
$qualifiedSnapshots = python_declaration_snapshots($qualifiedStatic);
source_type_assert(array_column($qualifiedSnapshots[3]['record']['params'], 'name') === ['self'], 'Qualified builtin static decorators must retain their explicit first argument.');
source_type_rules(python_declaration_snapshots(str_replace('def static(self: int)', 'def static(self)', $qualifiedStatic))[3]['record'], ['typing.parameter_missing:self']);
source_type_rules(python_declaration_snapshots(str_replace('value: int', 'value', $python))[1]['record'], ['typing.parameter_missing:value']);
source_type_rules(python_declaration_snapshots(str_replace('value (int)', 'value (str)', $python))[1]['record'], ['parameter.type_mismatch:value']);
source_type_rules(python_declaration_snapshots(str_replace('int: Combined', 'str: Combined', $python))[1]['record'], ['return.type_mismatch']);
source_type_rules(python_declaration_snapshots("def absent(value):\n    return value\n")[0]['record'],
    ['documentation.missing', 'typing.parameter_missing:value', 'typing.return_missing']);
$optional = <<<'PY'
def optional(value: int | None) -> int | None:
    """Preserve an optional selected count.
    @param Optional[int] value Optional selected count.
    @return Optional[int] Preserved selected count.
    """
    return value
PY;
source_type_assert(declaration_issues(python_declaration_snapshots($optional)[0]['record']) === [], 'Python Optional and union annotations must compare equivalently.');
$generator = <<<'PY'
def selected() -> Iterator[int]:
    """Yield selected counts through an annotated iterator.
    Yields:
        int: One selected count per iteration.
    """
    yield 1
PY;
source_type_rules(python_declaration_snapshots($generator)[0]['record'], ['return.missing'], ['return.type_mismatch']);
$docRegression = changed_source_contracts($optional, str_replace('@param Optional[int] value Optional selected count.', '', $optional), 'fixture.py');
source_type_assert($docRegression['unchanged'] === 1 && $docRegression['doc_regressions'] === 1, 'Python doc-only regressions must preserve executable identity and fail.');
$bodyChange = changed_source_contracts($optional, str_replace('return value', 'return None', $optional), 'fixture.py');
source_type_assert($bodyChange['changed'] === 1, 'Python executable changes must require the current declaration contract.');
$syntaxBlocked = false;
try {
    python_declaration_snapshots("def broken(\nSECRET_SOURCE_SENTINEL\n");
} catch (RuntimeException $error) {
    $syntaxBlocked = true;
    source_type_assert(!str_contains($error->getMessage(), 'SECRET_SOURCE_SENTINEL'), 'Parser failure must not expose raw source.');
}
source_type_assert($syntaxBlocked, 'Invalid Python syntax must block coverage.');

$fixtureRoot = sys_get_temp_dir() . '/gallery-type-docs-' . bin2hex(random_bytes(8));
source_type_assert(mkdir($fixtureRoot), 'Disposable comparison fixture could not be created.');
try {
    file_put_contents($fixtureRoot . '/fixture.php', $php);
    $gitCalls = [];
    /**
     * Supply a bounded synthetic Git tree for comparison-ref verification.
     * @param list<string> $arguments Read-only Git operation arguments.
     * @param string $root Fixture repository identity.
     * @return array{status:int,stdout:string} Synthetic process status and requested blob.
     */
    $gitReader = static function (array $arguments, string $root) use (&$gitCalls, $php): array {
        $gitCalls[] = $arguments;
        return ['status' => 0, 'stdout' => match ($arguments[0]) {
            'rev-parse' => $root,
            'ls-tree' => "100644 blob " . str_repeat('a', 40) . "\tfixture.php\0",
            'diff' => "fixture.php\0",
            'cat-file' => str_replace('return $count;', 'return $count + 1;', $php),
            default => '',
        }];
    };
    $comparison = changed_documentation_report($fixtureRoot, ['fixture.php'], $gitReader, 'HEAD^');
    source_type_assert($comparison['status'] === 'PASS' && $comparison['summary']['changed'] === 1, 'Chosen parent ref must enforce executable changes.');
    source_type_assert(in_array(['cat-file', 'blob', 'HEAD^:fixture.php'], $gitCalls, true), 'Blob reads must use the chosen comparison ref.');
    $gitCalls = [];
    $invalid = changed_documentation_report($fixtureRoot, ['fixture.php'], $gitReader, '--all');
    source_type_assert($invalid['status'] === 'BLOCKED' && $gitCalls === [], 'Flag-shaped refs must be rejected before Git invocation.');
    /**
     * Simulate absent parent history without invoking Git or exposing source.
     * @param list<string> $arguments Read-only Git operation arguments.
     * @param string $root Fixture repository identity.
     * @return array{status:int,stdout:string} Unavailable tree after a valid root query.
     */
    $missingParent = static function (array $arguments, string $root): array {
        return ['status' => $arguments[0] === 'rev-parse' ? 0 : 1, 'stdout' => $arguments[0] === 'rev-parse' ? $root : ''];
    };
    $missing = changed_documentation_report($fixtureRoot, ['fixture.php'], $missingParent, 'HEAD^');
    source_type_assert($missing['status'] === 'BLOCKED', 'Missing parent history must block comparison coverage.');
    /**
     * Return an invalid historical PHP body to exercise valid syntax repairs.
     * @param list<string> $arguments Read-only Git operation arguments.
     * @param string $root Fixture repository identity.
     * @return array{status:int,stdout:string} Historical blob and synthetic Git metadata.
     */
    $invalidHistorical = static function (array $arguments, string $root) use ($php): array {
        return ['status' => 0, 'stdout' => match ($arguments[0]) {
            'rev-parse' => $root,
            'ls-tree' => "100644 blob " . str_repeat('a', 40) . "\tfixture.php\0",
            'diff' => "fixture.php\0",
            'cat-file' => str_replace('return $count; }', 'return $count;', $php),
            default => '',
        }];
    };
    $repair = changed_documentation_report($fixtureRoot, ['fixture.php'], $invalidHistorical, 'HEAD^');
    source_type_assert($repair['status'] === 'PASS' && $repair['summary']['changed'] === 1,
        'A fully documented valid repair must pass even when historical PHP syntax was invalid.');
    file_put_contents($fixtureRoot . '/fixture.php', str_replace('return $count; }', 'return $count;', $php));
    $invalidCurrent = changed_documentation_report($fixtureRoot, ['fixture.php'], $gitReader, 'HEAD^');
    source_type_assert($invalidCurrent['status'] === 'BLOCKED', 'Invalid current PHP syntax must block declaration coverage.');
} finally {
    unlink($fixtureRoot . '/fixture.php');
    rmdir($fixtureRoot);
}

echo "Source type documentation fixtures passed.\n";
