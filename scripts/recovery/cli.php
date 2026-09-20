<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Validate command intent and route it to the isolated recovery operations.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/recovery/cli.php
 * Module Type: Recovery CLI Dispatch
 *
 * Purpose:
 *   Parse explicit CLI inputs and record bounded, private recovery evidence.
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Never bootstrap a restored installation or read installation credentials.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Recovery;

use Throwable;

require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/fixture.php';

/**
 * Describe commands, private output requirements and limited evidence claims.
 * @return string Static CLI help text; does not print, inspect paths or run recovery operations.
 */
function usage(): string
{
    return <<<'HELP'
Recovery assurance (PHP 8.1+, CLI only)
  php scripts/recovery.php template --kind=set --out=/private/set.json
  php scripts/recovery.php inventory --set=/private/set.json --out=/private/inventory.json
  php scripts/recovery.php template --kind=isolation --set=/private/set.json --out=/isolated/.recovery-isolated.json
  php scripts/recovery.php template --kind=observations --set=/private/set.json --root=/isolated --out=/private/observations.json
  php scripts/recovery.php validate --set=/private/set.json --root=/isolated [--observations=/private/observations.json] --out=/private/evidence.json
  php scripts/recovery.php drill --work=/private/new-fixture-directory

Options require --name=value. Evidence files and the drill directory must be new.
Exit: 0 PASS (see coverage), 1 FAIL/invalid input, 2 INCOMPLETE.
Never reads config.php, connects to a database, executes restored PHP, or calls HTTP.
Isolation is an operator prerequisite, not something this tool can enforce.
See docs/RECOVERY_ASSURANCE.md and docs/RECOVERY_OFF_HOST.md.
HELP;
}

/** Accept documented options once each without permissive shell-style parsing. */
function parse_arguments(array $argv): array
{
    $command = $argv[1] ?? 'help';
    $allowed = [
        'template' => ['kind', 'set', 'root', 'out'],
        'inventory' => ['set', 'out'],
        'validate' => ['set', 'root', 'observations', 'out'],
        'drill' => ['work'],
    ];
    demand(isset($allowed[$command]), 'invalid_command');
    $options = [];
    foreach (array_slice($argv, 2) as $argument) {
        demand(preg_match('/^--([a-z]+)=(.+)$/sD', $argument, $matches) === 1, 'invalid_option');
        demand(in_array($matches[1], $allowed[$command], true) && !isset($options[$matches[1]]), 'invalid_option');
        $options[$matches[1]] = $matches[2];
    }
    return [$command, $options];
}

/** Require an explicit option instead of inferring an installation or output. */
function required_option(array $options, string $name): string
{
    demand(isset($options[$name]), 'missing_option');
    return $options[$name];
}

/** Dispatch CLI-only evidence work with bounded output and stable exit statuses. */
function main(array $argv): int
{
    if (!isset($argv[1]) || in_array($argv[1], ['help', '--help', '-h'], true)) {
        echo usage() . "\n";
        return 0;
    }
    // Suppress path-bearing PHP warnings, including I/O and malformed JSON diagnostics.
    set_error_handler(static function (): never {
        throw new \RuntimeException('io_failed');
    });
    try {
        [$command, $options] = parse_arguments($argv);
        if ($command === 'drill') {
            $report = drill(required_option($options, 'work'));
        } else {
            $out = required_option($options, 'out');
            $kind = $options['kind'] ?? '';
            $set = $command === 'template' && $kind === 'set' ? set_template() : read_json(required_option($options, 'set'));
            check_set($set);
            if ($command === 'template') {
                if ($kind === 'set') {
                    $value = $set;
                } elseif ($kind === 'isolation') {
                    $value = isolation_template($set);
                } else {
                    demand($kind === 'observations', 'invalid_template');
                    $root = external_directory(required_option($options, 'root'));
                    $value = observations_template($set, check_isolation($root, $set));
                }
                write_json($out, $value);
                echo "PASS template_created (unverified; complete the documented operator steps)\n";
                return 0;
            }
            if ($command === 'inventory') {
                $report = inventory($set, time());
            } else {
                $root = external_directory(required_option($options, 'root'));
                $destination = external_directory(dirname($out));
                demand(!contains_path($root, $destination), 'evidence_inside_restore');
                $observations = isset($options['observations']) ? read_json($options['observations']) : null;
                $report = validate($set, $root, $observations, time());
            }
            write_json($out, $report);
        }
        echo $report['status'] . ' coverage=' . $report['coverage'] . ' recovery_readiness=' . $report['recovery_readiness'] . "\n";
        return ['PASS' => 0, 'FAIL' => 1, 'INCOMPLETE' => 2][$report['status']];
    } catch (Throwable $error) {
        // Never echo external exception text, command arguments, file contents or paths.
        fwrite(STDERR, "FAIL recovery_input_or_io; check schema, isolation, paths and fresh output (see recovery documentation).\n");
        return 1;
    } finally {
        restore_error_handler();
    }
}
