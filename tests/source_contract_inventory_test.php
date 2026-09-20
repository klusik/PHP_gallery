<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/source_contract_inventory_test.php
 * Module Type: Source Contract Regression Test
 * Purpose:
 *   Exercise source-scanner boundaries against disposable fixtures.
 * Responsibilities:
 *   - Protect declaration attachment, parameter checks, exclusions and value redaction.
 * Author:
 *   Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/check_policy_constants.php';
require_once __DIR__ . '/function_documentation_test.php';
require_once dirname(__DIR__) . '/scripts/check_mvc_boundaries.php';

use function PhpGallery\SourceContracts\declaration_issues;
use function PhpGallery\SourceContracts\documentation_report;
use function PhpGallery\SourceContracts\header_issues;
use function PhpGallery\SourceContracts\inventory;
use function PhpGallery\SourceContracts\javascript_declarations;
use function PhpGallery\SourceContracts\php_declarations;
use function PhpGallery\SourceContracts\policy_report;
use function PhpGallery\SourceContracts\policy_source;

/**
 * Fail on a violated scanner contract without disclosing fixture source values.
 * @param bool $condition Expected invariant.
 * @param string $message Non-sensitive contract description.
 * @return void
 */
function source_inventory_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Build a valid source header in the requested native comment format.
 * @param string $path Relative fixture identity.
 * @param string $style Native format: php, block, hash, powershell, xml or tex.
 * @return string Header text with all required identity and purpose fields.
 */
function source_inventory_fixture_header(string $path, string $style = 'php'): string
{
    $text = "Project: PHP Gallery\nRepository: https://github.com/klusik/PHP_gallery\nFile: " . $path
        . "\nModule Type: Disposable Fixture\nPurpose:\n  Exercise source discovery.\nResponsibilities:\n  - Protect parser behavior.\nAuthor:\n  Rudolf Klusal\n";
    if ($style === 'hash') {
        return '# ' . str_replace("\n", "\n# ", rtrim($text)) . "\n";
    }
    if ($style === 'powershell') {
        return "<#\n" . $text . "#>\n";
    }
    if ($style === 'xml') {
        return "<!--\n" . $text . "-->\n";
    }
    if ($style === 'tex') {
        return '% ' . str_replace("\n", "\n% ", rtrim($text)) . "\n";
    }
    return ($style === 'php' ? "<?php\n" : '') . "/**\n * " . str_replace("\n", "\n * ", rtrim($text)) . "\n */\n";
}

$php = <<<'PHP'
<?php
/** Own the fixture record lifecycle. */
final class Record {
    /** The public display name. @var string */
    public string $name;
    /**
     * Collect identifiers under an attributed signature.
     * @param string $title Display title.
     * @param array<int,int> $ids Ordered identifiers.
     * @return array<int,int> Identifiers unchanged.
     */
    #[Example([1, 2])]
    public static function &collect(#[SensitiveParameter] string &$title, array $ids = [1, 2]): array { return $ids; }
    /** Construct a record from its stable identifier.
     * @param int $id Stable record identifier.
     * @return void
     */
    public function __construct(public readonly int $id) {}
}
/** Transform a stable input without side effects.
 * @param int $value Input count.
 * @return int Count unchanged.
 */
$mapped = static fn(int $value): int => $value;
$literal = 'function fabricated($secret) {}';
/* function commented() {} */
PHP;
$records = php_declarations($php);
source_inventory_assert(array_column($records, 'name') === ['Record', '$name', 'collect', '__construct', '$mapped'], 'PHP declarations must include attributes, references, properties and assigned arrows exactly once.');
foreach ($records as $record) {
    source_inventory_assert(declaration_issues($record) === [], 'Documented PHP fixture must have matching signature coverage: ' . $record['name']);
}
$bad = php_declarations('<?php /** Perform a bounded fixture operation. @param string $wrong Wrong name. */ function work(int $right): array { return []; }')[0];
$issues = declaration_issues($bad);
source_inventory_assert(in_array('parameter.missing:right', $issues, true) && in_array('parameter.extra:wrong', $issues, true)
    && in_array('return.missing', $issues, true), 'Mismatched parameters and missing returns must fail.');
$unrelated = php_declarations('<?php /** Earlier declaration. */ function before() {} function after() {}');
source_inventory_assert($unrelated[1]['doc'] === '', 'An earlier docblock cannot document a later declaration.');
$jsAttachment = '/** First callable only. */ function first() {} function second() {}';
source_inventory_assert(!function_documentation_js_has_docblock($jsAttachment, (int) strpos($jsAttachment, 'function second')), 'Historical JS gate must not borrow an earlier completed JSDoc across code.');
$wrongType = php_declarations('<?php /** Return the supplied record count. @param string $count Record count. */ function amount(int $count): int { return $count; }')[0];
source_inventory_assert(in_array('parameter.type_mismatch:count', declaration_issues($wrongType), true), 'Definite native/doc primitive type mismatch must be reported.');
$boilerplate = php_declarations('<?php /** Handles amount logic for the gallery application. @return int */ function amount(): int { return 1; }')[0];
source_inventory_assert(in_array('documentation.summary', declaration_issues($boilerplate), true), 'Generated handles-operation summaries must not satisfy meaningful documentation.');

$js = <<<'JS'
/** Own the browser fixture lifecycle. */
export class Widget {
    /** Construct an inert widget.
     * @return {void}
     */
    constructor() {}
    /** Render the provided identifier.
     * @param {string} value Display identifier.
     * @return {string} Identifier unchanged.
     */
    render(value) { return value; }
}
/** Yield a supplied identifier.
 * @param {string} value Display identifier.
 * @return {Generator<string>} One identifier.
 */
export async function* names(value) { yield value; }
/** Normalize a bounded retry input.
 * @param {number} value Retry count.
 * @return {number} Retry count unchanged.
 */
export const normalize = (value = pair(1, 2)) => value;
const literal = "function phantom() {}";
const pattern = /function fake\(\)\s*\{\}/;
/* function commented() {} */
JS;
$records = javascript_declarations($js);
source_inventory_assert(array_column($records, 'name') === ['Widget', 'constructor', 'render', 'names', 'normalize'], 'JS lexer must ignore comments/strings/regex and include module/class/arrow forms.');
foreach ($records as $record) {
    source_inventory_assert(declaration_issues($record) === [], 'Documented JS fixture must have matching signature coverage: ' . $record['name']);
}
$root = sys_get_temp_dir() . '/gallery-source-inventory-' . bin2hex(random_bytes(8));
$files = [];
$directories = [$root, $root . '/app', $root . '/vendor', $root . '/cache', $root . '/.agent-local',
    $root . '/public', $root . '/public/assets', $root . '/public/assets/flags'];
try {
    foreach ($directories as $directory) {
        mkdir($directory);
    }
    $fixtures = [
        'app/sample.php' => source_inventory_fixture_header('app/sample.php') . 'declare(strict_types=1);',
        'module.mjs' => source_inventory_fixture_header('module.mjs', 'block') . $js,
        'common.cjs' => source_inventory_fixture_header('common.cjs', 'block'),
        'tool.psm1' => source_inventory_fixture_header('tool.psm1', 'powershell'),
        'tool.py' => "#!/usr/bin/env python3\n" . source_inventory_fixture_header('tool.py', 'hash'),
        'workflow.yml' => source_inventory_fixture_header('workflow.yml', 'hash') . "name: Fixture\n",
        '.htaccess' => source_inventory_fixture_header('.htaccess', 'hash') . "Options -Indexes\n",
        'manual.tex' => source_inventory_fixture_header('manual.tex', 'tex') . '\\documentclass{article}',
        'page.html' => "<!doctype html>\n" . source_inventory_fixture_header('page.html', 'xml') . '<html></html>',
        'vector.svg' => "<?xml version='1.0'?>\n" . source_inventory_fixture_header('vector.svg', 'xml') . '<svg/>',
        'public/assets/flags/cz.svg' => '<svg>third-party-fixture-sentinel</svg>',
        'config.php' => '<?php throw new Exception("private-fixture-sentinel");',
        'vendor/foreign.php' => '<?php function undocumented_vendor() {}',
        'cache/generated.php' => '<?php function undocumented_generated() {}',
        '.agent-local/local.php' => '<?php function private_tool() {}',
        'metadata.json' => '{"format":"no source comments"}',
    ];
    foreach ($fixtures as $path => $source) {
        $files[] = $root . '/' . $path;
        file_put_contents($root . '/' . $path, $source);
    }
    $inventory = inventory($root);
    source_inventory_assert(count($inventory['files']) === 10 && $inventory['other_formats']['json'] === 1, 'Discovery must include native markup/config formats while pruning private and third-party source.');
    source_inventory_assert(isset($inventory['provenance']['public/assets/flags/cz.svg']), 'Third-party SVG exclusions must retain an explicit origin and license.');
    source_inventory_assert(header_issues($fixtures['app/sample.php'], 'app/sample.php') === [], 'Complete PHP header must pass.');
    source_inventory_assert(header_issues(str_replace('<?php', '<?php declare(strict_types=1);', $fixtures['app/sample.php']), 'app/sample.php') === [], 'PHP strict-types preamble must not hide a valid header.');
    source_inventory_assert(header_issues($fixtures['tool.py'], 'tool.py') === [], 'Python shebang/hash header must pass.');
    source_inventory_assert(header_issues($fixtures['tool.psm1'], 'tool.psm1') === [], 'PowerShell block header must pass.');
    foreach (['workflow.yml', '.htaccess', 'manual.tex', 'page.html', 'vector.svg'] as $path) {
        source_inventory_assert(header_issues($fixtures[$path], $path) === [], 'Native config/markup header must pass: ' . $path);
    }
    source_inventory_assert(in_array('header.author', header_issues('<svg><text>Author: Rudolf Klusal</text></svg>', 'vector.svg'), true), 'SVG element text cannot substitute for a native attribution comment.');
    $batchHeader = str_replace('# ', 'rem ', source_inventory_fixture_header('tool.bat', 'hash'));
    source_inventory_assert(header_issues("@echo off\n" . $batchHeader, 'tool.bat') === [], 'Batch echo preamble must not hide attribution.');
    source_inventory_assert(in_array('header.file', header_issues($fixtures['app/sample.php'], 'app/other.php'), true), 'Copied file identity must fail.');
    $sharedIdentity = 'public/assets/usage.js (canonical); public/assets/telemetry.js (compatibility copy)';
    $compatibilityHeader = source_inventory_fixture_header($sharedIdentity, 'block');
    source_inventory_assert(header_issues($compatibilityHeader, 'public/assets/usage.js') === []
        && header_issues($compatibilityHeader, 'public/assets/telemetry.js') === [], 'Byte-identical telemetry assets require a truthful shared two-role identity.');
    source_inventory_assert(in_array('header.file', header_issues($compatibilityHeader, 'other.js'), true), 'Shared asset identity cannot exempt an unrelated source path.');
    source_inventory_assert(in_array('header.responsibilities', header_issues(str_replace('Responsibilities:', 'Other:', $fixtures['app/sample.php']), 'app/sample.php'), true), 'A missing required header field must fail even when attribution is present.');
    $emptyPurpose = str_replace('  Exercise source discovery.', '', $fixtures['app/sample.php']);
    $emptyPurpose = str_replace("Responsibilities:\n *   - Protect parser behavior.", 'Responsibilities: Protect parser behavior.', $emptyPurpose);
    source_inventory_assert(in_array('header.purpose', header_issues($emptyPurpose, 'app/sample.php'), true), 'An empty purpose cannot borrow a following populated header field.');
    source_inventory_assert(in_array('header.author', header_issues('<?php $value = ' . var_export($fixtures['app/sample.php'], true) . ';', 'app/sample.php'), true), 'Attribution in a string is not a source header.');
    $report = documentation_report($root);
    source_inventory_assert(!str_contains(json_encode($report, JSON_THROW_ON_ERROR), 'private-fixture-sentinel'), 'Reports must not include private source/config values.');
    source_inventory_assert($report['summary']['source_files'] === 10, 'Selected coverage must match discovered source.');
    $policy = policy_report($root);
    source_inventory_assert($policy['summary']['source_files'] === 10, 'Policy and documentation use the same discovery boundary.');
    ob_start();
    $strictStatus = \PhpGallery\SourceContracts\documentation_main(['test', '--root=' . $root, '--path=app/sample.php', '--strict', '--json']);
    $strictOutput = (string) ob_get_clean();
    source_inventory_assert($strictStatus === 0 && json_decode($strictOutput, true)['summary']['finding_count'] === 0, 'Strict CLI must accept a fully documented selected file.');
    $files[] = $root . '/missing.php';
    file_put_contents($root . '/missing.php', '<?php function undocumented(int $value): int { return $value; }');
    ob_start();
    $strictStatus = \PhpGallery\SourceContracts\documentation_main(['test', '--root=' . $root, '--path=missing.php', '--strict', '--json']);
    $strictOutput = (string) ob_get_clean();
    source_inventory_assert($strictStatus === 1 && json_decode($strictOutput, true)['summary']['finding_count'] > 0, 'Strict CLI must reject debt and preserve complete machine-readable evidence.');
    $rejected = false;
    try {
        documentation_report($root, ['config.php']);
    } catch (RuntimeException) {
        $rejected = true;
    }
    source_inventory_assert($rejected, 'Explicit paths cannot override private exclusions.');
    $persistenceSource = <<<'PHP'
<?php
/* SELECT id FROM examples: comments must not become violations. */
function fixture(): void {
    $statement = \Gallery\Core\db()->prepare('SELECT id FROM examples');
    $connection = new \PDO('fixture-only-not-connected');
}
PHP;
    $files[] = $root . '/app/helpers_probe.php';
    file_put_contents($root . '/app/helpers_probe.php', $persistenceSource);
    $strictMvc = \PhpGallery\MvcBoundary\scan_project($root);
    $mvcRules = array_column($strictMvc, 'rule');
    source_inventory_assert(count($strictMvc) === 4
        && in_array('core.direct_db', $mvcRules, true)
        && in_array('core.pdo_method', $mvcRules, true)
        && in_array('core.sql_literal', $mvcRules, true)
        && in_array('core.pdo_construction', $mvcRules, true), 'Project discovery must enforce SQL/PDO rules in core helper files.');
    source_inventory_assert(\PhpGallery\MvcBoundary\core_persistence_boundary_path('app/helpers_runtime/part.php'), 'Split helper parts must inherit strict persistence ownership.');
    source_inventory_assert(!\PhpGallery\MvcBoundary\core_persistence_boundary_path('app/database.php'), 'Database infrastructure must not be reclassified as a generic helper.');
    $adapterSource = <<<'PHP'
<?php
/** Compatibility request adapter; SQL/PDO words here are documentation only. */
function adapter(PDO $connection): void {
    $_SESSION['fixture'] = $_GET['id'] ?? 0;
    header('Content-Type: application/json');
    echo '{}';
}
PHP;
    source_inventory_assert(\PhpGallery\MvcBoundary\scan_source($adapterSource, 'app/security.php') === [], 'Core HTTP/session compatibility behavior and PDO parameter types remain outside the narrow persistence rule.');
    source_inventory_assert(\PhpGallery\MvcBoundary\scan_source($persistenceSource, 'app/database.php') === [], 'The promoted rule must preserve the explicit infrastructure boundary.');
    $securityWrite = \PhpGallery\MvcBoundary\scan_source('<?php \mkdir("fixture");' . "\n" . 'file_put_contents("fixture", "marker");', 'app/security.php');
    source_inventory_assert(count($securityWrite) === 2 && $securityWrite[0]['rule'] === 'core.security_filesystem_mutation',
        'Migrated security facade must not regain direct setup-lock filesystem mutations.');
    source_inventory_assert(count(\PhpGallery\MvcBoundary\scan_source('<?php unlink("fixture");', 'app/security/part.php')) === 1,
        'Future security split parts inherit the converted filesystem boundary.');
    source_inventory_assert(\PhpGallery\MvcBoundary\scan_source('<?php is_file("fixture"); $owner->mkdir("fixture"); /* mkdir("fixture"); */', 'app/security.php') === [],
        'Read-only probes, member calls and inert comments are outside the direct-write guard.');
    source_inventory_assert(\PhpGallery\MvcBoundary\scan_source('<?php mkdir("fixture");', 'app/helpers_runtime.php') === [],
        'Security write promotion must not create unreviewed helper filesystem baseline debt.');
    $discoverySession = \PhpGallery\MvcBoundary\scan_source('<?php $_SESSION["fixture"] = [];', 'app/services/admin_gallery_discovery.php');
    source_inventory_assert(count($discoverySession) === 1 && $discoverySession[0]['rule'] === 'services.discovery_session_global',
        'Converted discovery service must not regain direct session state.');
    source_inventory_assert(count(\PhpGallery\MvcBoundary\scan_source('<?php $_SESSION["fixture"] = [];', 'app/services/admin_gallery_discovery/part.php')) === 1,
        'Split discovery parts must inherit the session boundary.');
    source_inventory_assert(\PhpGallery\MvcBoundary\scan_source('<?php function work(array &$jobs): void { $jobs = []; }', 'app/services/admin_gallery_discovery.php') === [],
        'Caller-owned discovery maps remain valid domain inputs.');
    $googleSession = \PhpGallery\MvcBoundary\scan_source('<?php $_SESSION["oauth"] = [];', 'app/services/google_auth.php');
    source_inventory_assert(count($googleSession) === 1 && $googleSession[0]['rule'] === 'services.google_auth_session_global',
        'Converted Google OAuth service must not regain direct session state.');
    $reportSession = \PhpGallery\MvcBoundary\scan_source('<?php $_SESSION["report"] = [];', 'app/services/admin_gallery_report/job.php');
    source_inventory_assert(count($reportSession) === 1 && $reportSession[0]['rule'] === 'services.report_job_session_global',
        'Converted report-job lifecycle must not regain direct session state.');
    source_inventory_assert(count(\PhpGallery\MvcBoundary\scan_source('<?php unset($_SESSION["report"]);', 'app/services/admin_gallery_report/job/part.php')) === 1,
        'A future report-job split must retain the converted session boundary.');
    source_inventory_assert(\PhpGallery\MvcBoundary\scan_source('<?php function clear(?array &$checkpoint): void { $checkpoint = null; }', 'app/services/admin_gallery_report/job.php') === [],
        'Caller-owned nullable report checkpoints remain valid service inputs.');
    source_inventory_assert(\PhpGallery\MvcBoundary\scan_source('<?php /* $_SESSION is controller-owned. */ $label = \'$_SESSION\';', 'app/services/admin_gallery_report/job.php') === [],
        'Report session documentation and inert strings are not global access.');
    source_inventory_assert(\PhpGallery\MvcBoundary\scan_source('<?php $_SESSION["fixture"] = [];', 'app/services/admin_gallery_report/other.php') === [],
        'Report-job promotion must not silently broaden into an unreviewed sibling owner.');
    $detectorSession = \PhpGallery\MvcBoundary\scan_source('<?php $_SESSION["detector"] = [];', 'app/services/duplicate_photo_detector.php');
    source_inventory_assert(count($detectorSession) === 1 && $detectorSession[0]['rule'] === 'services.duplicate_detector_session_global',
        'Converted duplicate detector must not regain direct session ownership.');
    source_inventory_assert(count(\PhpGallery\MvcBoundary\scan_source('<?php unset($_SESSION["detector"]);', 'app/services/duplicate_photo_detector/part.php')) === 1,
        'A future duplicate-detector split must inherit its strict session boundary.');
    source_inventory_assert(\PhpGallery\MvcBoundary\scan_source('<?php function prune(array &$jobs): void { $jobs = []; }', 'app/services/duplicate_photo_detector.php') === [],
        'Caller-owned duplicate-detector maps remain valid domain inputs.');
    $viewerSession = \PhpGallery\MvcBoundary\scan_source('<?php $_SESSION["tickets"] = [];', 'app/services/viewer_anti_automation.php');
    source_inventory_assert(count($viewerSession) === 1 && $viewerSession[0]['rule'] === 'services.viewer_anti_automation_session_global',
        'Converted anti-automation policy must not regain controller-owned ticket storage.');
    source_inventory_assert(count(\PhpGallery\MvcBoundary\scan_source('<?php unset($_SESSION["tickets"]);', 'app/services/viewer_anti_automation/part.php')) === 1,
        'Future viewer policy split parts inherit the session boundary.');
    $mobileWrites = \PhpGallery\MvcBoundary\scan_source('<?php unlink("fixture");' . "\n" . '\file_put_contents("fixture", "body");', 'app/controllers/mobile_webdav.php');
    source_inventory_assert(count($mobileWrites) === 2 && $mobileWrites[0]['rule'] === 'controllers.mobile_webdav_filesystem_mutation',
        'Converted WebDAV controller must delegate direct temporary-body mutation to its service.');
    source_inventory_assert(count(\PhpGallery\MvcBoundary\scan_source('<?php mkdir("fixture");', 'app/controllers/mobile_webdav/part.php')) === 1,
        'Future WebDAV controller split parts inherit filesystem ownership.');
    source_inventory_assert(\PhpGallery\MvcBoundary\scan_source('<?php $stream = fopen("php://input", "rb"); fclose($stream); $owner->unlink("fixture"); /* unlink("fixture"); */', 'app/controllers/mobile_webdav.php') === [],
        'WebDAV request-stream reads, member calls and inert comments remain outside the direct mutation guard.');
    source_inventory_assert(\PhpGallery\MvcBoundary\scan_source('<?php unlink("fixture");', 'app/services/mobile_webdav.php') === [],
        'The WebDAV service remains the allowed filesystem owner.');
} finally {
    foreach (array_reverse($files) as $file) {
        unlink($file);
    }
    foreach (array_reverse($directories) as $directory) {
        rmdir($directory);
    }
}
$policy = policy_source(<<<'PHP'
<?php
/** Operational retry budget.
 * @var int Units: attempts. Scope: fixture. Consumers: work().
 * Rationale: Permit one bounded retry after the first attempt.
 */
const RETRY_ATTEMPTS = 2;
$retryDelay = 250;
$totalBytes = 0;
usleep(500);
$text = 'usleep(800); private-value-sentinel';
/* sleep(900); */
PHP, 'fixture.php');
source_inventory_assert(count($policy['definitions']) === 1 && $policy['definitions'][0]['missing_documentation'] === [], 'Documented policy definition must satisfy semantic fields.');
source_inventory_assert(count($policy['findings']) === 2, 'Policy candidates must ignore numeric strings/comments and documented definitions.');
source_inventory_assert(!str_contains(json_encode($policy, JSON_THROW_ON_ERROR), 'private-value-sentinel'), 'Policy evidence must omit literal values.');
foreach (['docs/CODE_DOCUMENTATION.md', 'docs/SOURCE_CONTRACT_INVENTORY.md'] as $path) {
    source_inventory_assert(header_issues((string) file_get_contents(dirname(__DIR__) . '/' . $path), $path) === [], 'The new source guide must carry native author metadata: ' . $path);
}
echo "PASS source_contract_inventory: disposable discovery, native headers, tokenized declarations, signatures, policy evidence and redaction.\n";
