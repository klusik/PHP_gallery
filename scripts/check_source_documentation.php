<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/check_source_documentation.php
 * Module Type: Source Documentation CLI
 * Purpose:
 *   Report source discovery and explicit documentation coverage/debt.
 * Responsibilities:
 *   - Check native headers and PHP/JavaScript declaration documentation.
 *   - Produce value-free JSON evidence and opt-in strict enforcement.
 * Author:
 *   Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace PhpGallery\SourceContracts;

require_once __DIR__ . '/source_contracts/inventory.php';
require_once __DIR__ . '/source_contracts/php.php';
require_once __DIR__ . '/source_contracts/javascript.php';
require_once __DIR__ . '/source_contracts/changes.php';

/**
 * Console truncation leaves complete evidence available through --json.
 * @var int Units: rows. Scope: CLI output. Consumers: print_report().
 * Rationale: 30 examples keep whole-tree debt readable without hiding JSON findings.
 */
const CONSOLE_FINDING_LIMIT = 30;

/**
 * Collect attribution/declaration issues without running source files.
 * @param string $root Repository or fixture root.
 * @param list<string> $paths Optional exact relative paths to enforce during migration.
 * @return array<string,mixed> Inventory, summary, findings and explicit coverage limits.
 */
function documentation_report(string $root, array $paths = []): array
{
    $inventory = inventory($root);
    $findings = [];
    $languages = [];
    $declarations = [];
    $rules = [];
    $filesWithIssues = [];
    $selected = $paths === [] ? $inventory['files'] : array_intersect_key($inventory['files'], array_fill_keys($paths, true));
    if (array_diff($paths, array_keys($selected)) !== []) {
        throw new \RuntimeException('Selected source is absent or excluded.');
    }
    foreach ($selected as $path => $extension) {
        $source = file_get_contents($root . '/' . $path);
        if (!is_string($source)) {
            throw new \RuntimeException('Unable to read an inventoried source.');
        }
        $languages[$extension] = ($languages[$extension] ?? 0) + 1;
        $issues = [];
        foreach (header_issues($source, $path) as $rule) {
            $issues[] = ['path' => $path, 'line' => 1, 'kind' => 'header', 'name' => '', 'rule' => $rule];
        }
        $records = match ($extension) {
            'php' => php_declarations($source),
            'js', 'mjs', 'cjs' => javascript_declarations($source),
            'html', 'htm' => array_column(source_declaration_snapshots($source, $path), 'record'),
            default => [],
        };
        foreach ($records as $record) {
            $key = $extension . '.' . $record['kind'];
            $declarations[$key] = ($declarations[$key] ?? 0) + 1;
            foreach (declaration_issues($record) as $rule) {
                $issues[] = ['path' => $path, 'line' => $record['line'], 'kind' => $record['kind'], 'name' => $record['name'], 'rule' => $rule];
            }
        }
        foreach ($issues as $issue) {
            $family = explode(':', $issue['rule'], 2)[0];
            $rules[$family] = ($rules[$family] ?? 0) + 1;
            $filesWithIssues[$path] = true;
            $findings[] = $issue;
        }
    }
    ksort($languages);
    ksort($declarations);
    ksort($rules);
    return [
        'inventory' => $inventory,
        'summary' => ['source_files' => count($selected), 'languages' => $languages, 'declarations' => $declarations,
            'finding_count' => count($findings), 'files_with_findings' => count($filesWithIssues), 'rules' => $rules],
        'findings' => $findings,
        'coverage' => [
            'headers' => 'Leading native comments: identity, purpose, responsibilities, exact author; all inventoried source extensions.',
            'php' => 'Tokenizer: named/anonymous callables, references, attributes, enums/classes/traits/interfaces, properties; promoted fields use constructor parameters.',
            'javascript' => 'Conservative lexer: functions/generators, classes, methods, bound/inline arrows and nested template expressions; js/mjs/cjs and executable inline HTML scripts. Destructured parameters require review.',
            'manual_declaration_languages' => 'Python/PYW, PowerShell and shell/batch declaration/docstring semantics remain manual. SQL, YAML, SVG, TeX and Apache htaccess receive native header checks, not application-callable claims.',
            'manual_semantics' => 'Primitive PHP type disagreement is detected; review aliases/subtypes, truthfulness, invariants, side effects, exceptions, field semantics, ambiguous JS regex and computed/private fields.',
            'other_formats' => 'Metadata, docs and binaries are extension-counted without reading contents; attribution belongs to their owning source/generator.',
        ],
    ];
}

/**
 * Render bounded console evidence or complete value-free JSON.
 * @param array<string,mixed> $report Report containing summary and findings.
 * @param bool $json Whether machine-readable stdout was requested.
 * @param string $label Diagnostic label, never a claim of semantic completeness.
 * @return void
 */
function print_report(array $report, bool $json, string $label): void
{
    if ($json) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
        return;
    }
    echo $label, ' | ', json_encode($report['summary'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    foreach (array_slice($report['findings'], 0, CONSOLE_FINDING_LIMIT) as $finding) {
        echo $finding['path'], ':', $finding['line'], ' ', $finding['rule'], ' ', $finding['name'] ?? '', "\n";
    }
    if (isset($report['status'])) {
        foreach ($report['blocked'] as $blocked) {
            echo 'BLOCKED ', $blocked['path'], ': ', $blocked['reason'], "\n";
        }
        echo "Changed-source gate only; whole-tree legacy and semantic review remain separate.\n";
    } else {
        echo "Inventory only unless --strict is selected. Complete findings: --json. Semantic review remains required.\n";
    }
}

/**
 * Parse the shared read-only inventory CLI, rejecting unknown options.
 * @param list<string> $argv Process arguments, including the executable script.
 * @return array{root:string,json:bool,strict:bool,changed:bool,paths:list<string>} Shared CLI modes and exact path selection.
 */
function report_options(array $argv): array
{
    $options = ['root' => dirname(__DIR__), 'json' => false, 'strict' => false, 'changed' => false, 'paths' => []];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--json') {
            $options['json'] = true;
        } elseif ($argument === '--strict') {
            $options['strict'] = true;
        } elseif ($argument === '--changed') {
            $options['changed'] = true;
        } elseif (str_starts_with($argument, '--root=')) {
            $options['root'] = substr($argument, strlen('--root='));
        } elseif (str_starts_with($argument, '--path=')) {
            $options['paths'][] = str_replace('\\', '/', substr($argument, strlen('--path=')));
        } else {
            throw new \InvalidArgumentException('Expected --json, --strict, --changed, --root=PATH, or repeated --path=RELATIVE.');
        }
    }
    return $options;
}

/**
 * Run documentation inventory with explicit debt-sensitive strict mode.
 * @param list<string> $argv Process arguments.
 * @return int Zero for inventory success, one for strict findings, two for scan errors.
 */
function documentation_main(array $argv): int
{
    try {
        $options = report_options($argv);
        if ($options['changed']) {
            $report = changed_documentation_report($options['root'], $options['paths']);
            print_report($report, $options['json'], 'Changed declaration gate ' . $report['status']);
            return match ($report['status']) {
                'PASS' => 0,
                'FAIL' => 1,
                default => 2,
            };
        }
        $report = documentation_report($options['root'], $options['paths']);
        print_report($report, $options['json'], 'Source documentation inventory');
        return $options['strict'] && $report['findings'] !== [] ? 1 : 0;
    } catch (\Throwable $error) {
        fwrite(STDERR, "Source documentation inventory could not complete; check options and source readability.\n");
        return 2;
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(documentation_main($argv));
}
