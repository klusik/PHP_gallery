<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/policy_constants_changes_test.php
 * Module Type: Disposable Policy Gate Regression
 * Purpose: Prove bounded runtime policy enforcement without repository or live-data writes.
 * Responsibilities:
 *   - Exercise syntax matching, explanation attachment, redaction and unknown coverage.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/check_policy_constants.php';

use function PhpGallery\SourceContracts\changed_policy_report;
use function PhpGallery\SourceContracts\changed_policy_source;
use function PhpGallery\SourceContracts\compare_declaration_snapshots;
use function PhpGallery\SourceContracts\policy_site_snapshots;

/**
 * Fail an isolated source-contract assertion without printing fixture source.
 * @param bool $condition Expected fixture invariant.
 * @param string $message Bounded diagnostic without literal values.
 * @return void Throws when the invariant fails.
 */
function policy_changes_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Describe a synthetic operational bound using the canonical policy contract.
 * @return string Native docblock with substantive type, units, scope, consumers and rationale.
 */
function policy_changes_explanation(): string
{
    return "/**\n * Bound one disposable worker wait independently of request rendering.\n"
        . " * @var int\n * Units: seconds. Scope: disposable worker scheduling.\n"
        . " * Consumers: fixture wait loop.\n"
        . " * Rationale: Keep the fixture's finite wait separate from its retry count.\n */\n";
}

$doc = policy_changes_explanation();
$oldHeader = "/** Project: PHP Gallery\n * Repository: https://github.com/klusik/PHP_gallery\n * Purpose: Explain the old native module role.\n */\nconst LEGACY_LIMIT = 9;";
$newHeader = str_replace(' * Repository:', " * File: public/assets/fixture.js\n * Repository:", $oldHeader);
policy_changes_assert(changed_policy_source($oldHeader, $newHeader, 'public/assets/fixture.js')['findings'] === [],
    'Completing a legacy file header must not fabricate a policy documentation regression.');
$interpolated = <<<'PHP'
<?php
$label = "{$row['title']}}";
$timeoutSeconds = 7;
PHP;
policy_changes_assert(changed_policy_source('', $interpolated, 'app/fixture.php')['added'] === 1,
    'PHP parser-validated interpolation with literal braces must not be blocked by JavaScript delimiter assumptions.');
$legacy = '<?php namespace Fixture; const WAIT_SECONDS = 9; function run(): void { $retryLimit = 4; usleep(20); }';
$result = changed_policy_source($legacy, "<?php /* Leading attribution only. */" . substr($legacy, 5), 'app/fixture.php');
policy_changes_assert($result['unchanged'] === 3 && $result['findings'] === [], 'Header-only edits must not enforce unchanged legacy policy sites.');
$result = changed_policy_source($legacy, str_replace('usleep(20);', 'usleep(20); $label = "unrelated";', $legacy), 'app/fixture.php');
policy_changes_assert($result['unchanged'] === 3 && $result['findings'] === [], 'Unrelated body statements must not turn legacy candidates into new policy.');
$result = changed_policy_source($legacy, str_replace('WAIT_SECONDS = 9', 'WAIT_SECONDS = 10', $legacy), 'app/fixture.php');
policy_changes_assert($result['changed'] === 1 && count($result['findings']) === 6, 'An unexplained changed uppercase initializer must fail all missing semantic fields.');
$explained = "<?php namespace Fixture;\n" . $doc . "const WAIT_SECONDS = 9;\n";
policy_changes_assert(changed_policy_source('', $explained, 'app/policy_constants.php')['findings'] === [], 'A documented immutable owner must not need a path exemption.');
policy_changes_assert(changed_policy_source('', '<?php ' . $doc . '$retryLimit = 4;', 'app/fixture.php')['findings'] === [], 'An attached substantive local policy explanation is recognized without a local-file exemption.');
$result = changed_policy_source($explained, str_replace('Units: seconds.', 'Units:', $explained), 'app/policy_constants.php');
policy_changes_assert($result['doc_regressions'] === 1 && count($result['findings']) === 1
    && $result['findings'][0]['rule'] === 'constant.documentation:units', 'An empty field must not borrow the following scope or revive every legacy issue.');
$noRationale = str_replace(" * Rationale: Keep the fixture's finite wait separate from its retry count.\n", '', $explained);
policy_changes_assert(changed_policy_source($explained, $noRationale, 'app/policy_constants.php')['doc_regressions'] === 1, 'Removing a policy explanation must fail even when executable tokens are unchanged.');
$php = <<<'PHP'
<?php
/* $retryLimit = 999; usleep(999); const FAKE = 9; */
$text = 'const HIDDEN = 999; $retryLimit = 999; usleep(999);';
for ($index = 0; $index < 20; $index++) { $retryCount = 1; }
$bytesLimit = 64 * 1024;
\usleep(4 * 1000);
$driver->usleep(55);
PHP;
$result = changed_policy_source('', $php, 'app/fixture.php');
policy_changes_assert($result['added'] === 2, 'Only operational assignment/arithmetic and direct qualified timers qualify, not strings, loops or object methods.');
$definitions = policy_site_snapshots("<?php\n" . $doc . 'const FIRST_LIMIT = 4, SECOND_LIMIT = 6;', 'app/fixture.php');
policy_changes_assert(count($definitions['snapshots']) === 2 && $definitions['snapshots'][1]['record']['missing'] !== [],
    'Multi-constant declarations must discover every name without borrowing an earlier sibling explanation.');
$define = "<?php\n" . $doc . "\\define('WAIT_SECONDS', 9);";
policy_changes_assert(changed_policy_source('', $define, 'app/fixture.php')['findings'] === [], 'Fully qualified static-name define must attach canonical documentation.');
$dynamic = policy_site_snapshots('<?php define($selectedName, 55);', 'app/fixture.php');
policy_changes_assert(($dynamic['review']['dynamic_or_qualified_define'] ?? 0) === 1, 'Dynamic definitions require explicit review, not a false completeness claim.');
$map = policy_site_snapshots("<?php return ['retry_limit' => 8];", 'app/configuration_defaults.php');
policy_changes_assert($map['snapshots'] === [] && ($map['review']['numeric_policy_map_entry'] ?? 0) === 1,
    'Deployment-tunable map entries stay an explicit coverage gap rather than arbitrary constant violations.');
$namespaced = '<?php namespace First; const LIMIT = 2; namespace Second; const LIMIT = 3;';
$result = changed_policy_source($namespaced, str_replace('LIMIT = 3', 'LIMIT = 4', $namespaced), 'app/fixture.php');
policy_changes_assert($result['changed'] === 1 && $result['unchanged'] === 1 && str_contains($result['findings'][0]['identity'], 'Second'),
    'Namespace identities must separate equal constant names.');
$before = policy_site_snapshots($legacy, 'app/original.php')['snapshots'];
$moved = policy_site_snapshots($legacy, 'app/moved.php')['snapshots'];
$result = compare_declaration_snapshots($before, $moved, 'PhpGallery\\SourceContracts\\policy_site_issues');
policy_changes_assert($result['moved'] === 3 && $result['findings'] === [], 'Unchanged moved policy sites retain legacy status.');
$result = compare_declaration_snapshots($before, array_merge($before, $moved), 'PhpGallery\\SourceContracts\\policy_site_issues');
policy_changes_assert($result['added'] === 3 && $result['findings'] !== [], 'Copying alongside an original must not consume its unchanged legacy identity twice.');
$jsDoc = str_replace('@var int', '@var {number}', $doc);
$js = $jsDoc . 'export const WORKER_WAIT_MS = 20;';
policy_changes_assert(changed_policy_source('', "/** Project: PHP Gallery\n * File: public/assets/worker-policy.js\n */\n" . $js, 'public/assets/worker-policy.js')['findings'] === [],
    'A separate leading attribution block must not swallow a first policy definition docblock.');
policy_changes_assert(changed_policy_source('', $jsDoc . 'const timer = setTimeout(run, 20);', 'public/assets/fixture.js')['findings'] === [],
    'An explanation above a timer assignment attaches to that direct call site.');
policy_changes_assert(changed_policy_source('', 'service.window.setTimeout(run, 20);', 'public/assets/fixture.js')['added'] === 0,
    'A nested object property named window is not the browser global timer owner.');
$combined = str_replace(['Scope: disposable worker scheduling.', 'Consumers: fixture wait loop.', 'Rationale:'],
    ['Scope/consumers: disposable worker scheduling.', '', 'Compatibility:'], $doc);
policy_changes_assert(changed_policy_source('', '<?php ' . $combined . 'const LIMIT = 5;', 'app/fixture.php')['findings'] === [],
    'Explicit combined scope/consumer labels and compatibility rationale retain their meaning.');
$visibility = '<?php class Limits { public const BATCH_LIMIT = 4; }';
policy_changes_assert(changed_policy_source($visibility, str_replace('public const', 'private const', $visibility), 'app/fixture.php')['changed'] === 1,
    'Changed policy visibility is a material definition change, not formatting.');
policy_changes_assert(changed_policy_source('', $js, 'public/assets/worker-policy.js')['findings'] === [],
    'Focused documented browser policy modules pass by contract, not filename exception.');
$jsSites = 'const delayMs = 45; let retryLimit = 5; window.setTimeout(() => {}, 20); '
    . 'service.setTimeout(() => {}, 20); const note = "setTimeout(f, 88)";';
policy_changes_assert(changed_policy_source('', $jsSites, 'public/assets/fixture.js')['added'] === 3,
    'Lowercase JS declarations and browser-global timers qualify; unrelated methods and quoted code do not.');
$template = 'const text = ' . chr(96) . 'setTimeout(f, 55)' . '$' . '{setTimeout(f, 5)}' . chr(96) . ';';
policy_changes_assert(changed_policy_source('', $template, 'public/assets/fixture.js')['added'] === 1,
    'Nested executable template expressions must be scanned while raw template text stays inert.');
$redaction = changed_policy_source('', '<?php const PRIVATE_TEST_VALUE = "never-print-policy-sentinel"; $timeoutSeconds = 987654321;', 'app/fixture.php');
$json = json_encode($redaction, JSON_THROW_ON_ERROR);
policy_changes_assert(!str_contains($json, '987654321') && !str_contains($json, 'never-print-policy-sentinel')
    && !str_contains($json, 'fingerprint'), 'Reports must expose identities/rules, never values, source snippets or hashes.');

$root = sys_get_temp_dir() . '/gallery-policy-changes-' . bin2hex(random_bytes(8));
$directories = ['app', 'public', 'public/assets', 'tests'];
$files = ['app/fixture.php', 'app/new.php', 'app/configuration_defaults.php', 'public/assets/theme.css', 'public/assets/tool.py', 'tests/outside.php', 'config.php'];
mkdir($root);
try {
    foreach ($directories as $directory) {
        mkdir($root . '/' . $directory);
    }
    file_put_contents($root . '/app/fixture.php', $legacy);
    file_put_contents($root . '/app/new.php', $explained);
    file_put_contents($root . '/app/configuration_defaults.php', "<?php return ['retry_limit' => 8];");
    file_put_contents($root . '/public/assets/theme.css', '.fixture { transition-duration: 2s; }');
    file_put_contents($root . '/public/assets/tool.py', 'timeout_seconds = 50');
    file_put_contents($root . '/tests/outside.php', '<?php const FIXTURE_SAMPLE = 13;');
    file_put_contents($root . '/config.php', 'private-config-sentinel');
    $head = ['app/fixture.php' => $legacy, 'app/deleted.php' => $explained, 'config.php' => 'private-head-sentinel'];
    $verbs = [];
    /**
     * Provide immutable Git metadata without staging, commits or checkout operations.
     * @param list<string> $arguments Requested read-only Git operation.
     * @param string $directory Disposable repository root.
     * @return array{status:int,stdout:string} Synthetic HEAD data or bounded failure.
     */
    $git = static function (array $arguments, string $directory) use (&$head, &$verbs): array {
        $verbs[] = $arguments[0];
        if ($arguments[0] === 'rev-parse') {
            return ['status' => 0, 'stdout' => $directory . "\n"];
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
            $path = substr($arguments[2], strlen('HEAD:'));
            policy_changes_assert($path !== 'config.php', 'Private HEAD content must never be requested.');
            return isset($head[$path]) ? ['status' => 0, 'stdout' => $head[$path]] : ['status' => 1, 'stdout' => ''];
        }
        throw new RuntimeException('Unexpected mutating Git operation.');
    };
    $report = changed_policy_report($root, [], $git);
    policy_changes_assert($report['status'] === 'PASS' && $report['summary']['moved'] === 1
        && $report['summary']['coverage_review_count'] === 4, 'Whole fixture scope must match moves and explicitly disclose map, native-format and tooling gaps.');
    policy_changes_assert(array_diff($verbs, ['rev-parse', 'ls-tree', 'diff', 'cat-file']) === [], 'Policy gate must not write Git state.');
    policy_changes_assert(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'private-'), 'No private worktree or HEAD values may enter artifacts.');
    file_put_contents($root . '/app/new.php', '<?php const NEW_LIMIT = 5;');
    $report = changed_policy_report($root, ['app/new.php'], $git);
    policy_changes_assert($report['status'] === 'FAIL' && $report['summary']['sites_with_findings'] === 1, 'Untracked unexplained runtime policy must fail.');
    file_put_contents($root . '/app/new.php', '<?php const BROKEN = ;');
    policy_changes_assert(changed_policy_report($root, ['app/new.php'], $git)['status'] === 'BLOCKED', 'Unparseable changed PHP cannot pass.');
    file_put_contents($root . '/public/assets/added.js', 'setTimeout(run, 55;');
    $files[] = 'public/assets/added.js';
    policy_changes_assert(changed_policy_report($root, ['public/assets/added.js'], $git)['status'] === 'BLOCKED',
        'Unbalanced JavaScript policy syntax must disclose blocked coverage.');
    /**
     * Simulate unavailable immutable history without exposing transport diagnostics.
     * @param list<string> $arguments Requested operation.
     * @param string $directory Disposable root.
     * @return array{status:int,stdout:string} Unavailable Git response.
     */
    $unavailable = static function (array $arguments, string $directory): array {
        return ['status' => 1, 'stdout' => 'untrusted-git-sentinel'];
    };
    $blocked = changed_policy_report($root, [], $unavailable);
    policy_changes_assert($blocked['status'] === 'BLOCKED' && !str_contains(json_encode($blocked, JSON_THROW_ON_ERROR), 'untrusted-git-sentinel'),
        'Unavailable Git must block rather than pass or disclose transport output.');
    policy_changes_assert(changed_policy_report($root, ['config.php'], $git)['status'] === 'BLOCKED', 'Focused paths cannot override discovery privacy.');
    ob_start();
    $status = PhpGallery\SourceContracts\policy_main(['fixture', '--changed', '--json', '--root=' . $root]);
    $cli = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    policy_changes_assert($status === 2 && $cli['status'] === 'BLOCKED', 'CLI changed mode must preserve strict blocked status for a root without Git HEAD.');
} finally {
    foreach (array_reverse($files) as $file) {
        unlink($root . '/' . $file);
    }
    foreach (array_reverse($directories) as $directory) {
        rmdir($root . '/' . $directory);
    }
    rmdir($root);
}
echo "PASS policy_constants_changes: semantic sites, explanations, HEAD matching, privacy and coverage gaps.\n";
