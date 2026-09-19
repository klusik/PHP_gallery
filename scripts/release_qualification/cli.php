<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/release_qualification/cli.php
 * Module Type: Release Qualification Command Dispatch
 *
 * Purpose:
 *   Exposes evidence recording and a compact, truthful release handoff.
 *
 * Responsibilities:
 *   - Parse strict CLI options and report outstanding reviews separately from audits
 *
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace PhpGallery\ReleaseQualification;

use RuntimeException;

/** CLI syntax deliberately keeps the reviewed fingerprint explicit on evidence writes. */
function usage(): string
{
    return <<<'TEXT'
Usage: php scripts/release_qualification.php <command> <version> [arguments]
  init VERSION
  check VERSION [--phase=pre-publication|post-publication] [--json]
  record VERSION CHECK pending|pass|fail --fingerprint=HASH --reviewer=NAME --evidence=TEXT
  record-audit VERSION --fingerprint=HASH [--report=cache/test-audit/latest.json]
  render VERSION --pages=1-3,120-122 [--dpi=110] [--renderer=pdftoppm]

init creates an ignored record; changed content starts with all reviews pending.
record requires an actual human review; rendering/text extraction never approves it.
record-audit imports the central release audit run AFTER init without rerunning tests.
check returns 0 only for complete phase evidence with no coverage gaps, 1 otherwise.
Invalid input/tooling errors return 2. No command performs Git or publication actions.
TEXT;
}

/** Accept only documented options, with no silently ignored or repeated arguments. */
function parse_options(array $arguments, array $allowed): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if ($argument === '--json' && in_array('json', $allowed, true)) {
            $name = 'json';
            $value = true;
        } elseif (preg_match('/^--([a-z-]+)=(.+)$/sD', $argument, $match)) {
            $name = $match[1];
            $value = $match[2];
        } else {
            throw new RuntimeException('Invalid option: ' . $argument);
        }
        if (!in_array($name, $allowed, true) || array_key_exists($name, $options) || ($name === 'json' && $value !== true)) {
            throw new RuntimeException('Unknown, repeated or invalid option: ' . $argument);
        }
        $options[$name] = $value;
    }
    return $options;
}

/** Require an option rather than interpreting missing reviewer context as approval. */
function required_option(array $options, string $name): string
{
    if (!isset($options[$name]) || !is_string($options[$name])) {
        throw new RuntimeException('Missing --' . $name . '=VALUE.');
    }
    return $options[$name];
}

/** Render summaries from captured task results; never read passing child-process logs. */
function render_summary(array $status): string
{
    $lines = [
        'PHP Gallery qualification | Version: ' . $status['version'] . ' | Phase: ' . $status['phase'],
        'Content SHA-256: ' . $status['fingerprint'],
    ];
    if (!$status['initialized']) {
        $lines[] = 'No record for current content. Prior fingerprints cannot supply sign-off; run init.';
    }
    $automated = $status['automated'];
    $lines[] = 'Automated audit: ' . ($automated === null ? 'PENDING (no bound release report)' : $automated['report']['status']);
    if ($automated !== null) {
        $lines[] = 'Audit report SHA-256: ' . $automated['report_sha256'];
        foreach ($automated['report']['tasks'] as $task) {
            $lines[] = '  ' . $task['status'] . ' ' . $task['label'] . ': ' . $task['summary'];
            foreach ($task['details']['problems'] ?? [] as $problem) {
                $lines[] = '    ' . $problem;
            }
        }
    }
    $lines[] = 'Manual reviews:';
    foreach ($status['manual'] as $id => $review) {
        $later = in_array($id, $status['later'], true) ? ' (after publication)' : '';
        $lines[] = '  ' . strtoupper($review['status']) . ' ' . $id . $later
            . ($review['reviewer'] !== '' ? ' - ' . $review['reviewer'] . ': ' . $review['evidence'] : '');
    }
    $lines[] = 'Outstanding this phase: ' . ($status['unresolved'] === [] ? 'none' : implode(', ', $status['unresolved']));
    $lines[] = 'Coverage gaps: ' . count($status['coverage_gaps']);
    foreach ($status['coverage_gaps'] as $gap) {
        $lines[] = '  ' . $gap;
    }
    $lines[] = 'Qualification: ' . $status['status'] . ' | Automated PASS alone is not manual approval.';
    return implode("\n", $lines) . "\n";
}

/** Dispatch against an explicit root to support isolated, database-free regression fixtures. */
function run_cli(string $root, array $arguments): int
{
    try {
        if ($arguments === ['--help'] || $arguments === ['-h']) {
            fwrite(STDOUT, usage() . "\n");
            return 0;
        }
        $command = array_shift($arguments);
        $version = array_shift($arguments);
        if (!in_array($command, ['init', 'check', 'record', 'record-audit', 'render'], true) || !is_string($version)) {
            throw new RuntimeException(usage());
        }
        $id = $command === 'record' ? array_shift($arguments) : null;
        $reviewStatus = $command === 'record' ? array_shift($arguments) : null;
        $allowed = match ($command) {
            'init' => [],
            'check' => ['phase', 'json'],
            'record' => ['fingerprint', 'reviewer', 'evidence'],
            'record-audit' => ['fingerprint', 'report'],
            'render' => ['pages', 'dpi', 'renderer'],
        };
        $options = parse_options($arguments, $allowed);
        $current = snapshot($root, $version);
        if ($command === 'init') {
            initialize($root, $current);
            fwrite(STDOUT, 'Initialized ' . $version . ' | Content SHA-256: ' . $current['fingerprint'] . "\n"
                . 'Record: cache/release-qualification/' . record_relative($current) . "\n");
        } elseif ($command === 'check') {
            $phase = $options['phase'] ?? 'pre-publication';
            if (!in_array($phase, ['pre-publication', 'post-publication'], true)) {
                throw new RuntimeException('Invalid qualification phase.');
            }
            $record = load_record($root, $current);
            $status = qualification_status($record ?? empty_record($current), $phase, $record !== null);
            fwrite(STDOUT, isset($options['json']) ? encode($status) : render_summary($status));
            return $status['status'] === 'COMPLETE' ? 0 : 1;
        } elseif ($command === 'record') {
            if (!is_string($id) || !is_string($reviewStatus)) {
                throw new RuntimeException('Supply a manual check and pending|pass|fail.');
            }
            assert_fingerprint($current, required_option($options, 'fingerprint'));
            record_manual($root, $current, $id, $reviewStatus,
                required_option($options, 'reviewer'), required_option($options, 'evidence'));
            fwrite(STDOUT, 'Recorded ' . $id . ': ' . $reviewStatus . ' for ' . $current['fingerprint'] . "\n");
        } elseif ($command === 'record-audit') {
            assert_fingerprint($current, required_option($options, 'fingerprint'));
            $path = $options['report'] ?? 'cache/test-audit/latest.json';
            $absolute = str_starts_with($path, '/') || preg_match('~^(?:[A-Za-z]:[\\\\/]|\\\\\\\\)~', $path) === 1;
            $record = record_audit($root, $current, $absolute ? $path : $root . '/' . $path);
            fwrite(STDOUT, 'Captured automated ' . $record['automated']['report']['status'] . '; manual states are unchanged.' . "\n");
        } else {
            $dpi = $options['dpi'] ?? '110';
            if (!preg_match('/^[0-9]{2,3}$/D', $dpi)) {
                throw new RuntimeException('DPI must be an integer from 72 to 200.');
            }
            $result = render_previews($root, $current, preview_pages(required_option($options, 'pages')),
                (int) $dpi, $options['renderer'] ?? 'pdftoppm');
            fwrite(STDOUT, 'Rendered previews: ' . $result['directory'] . "\n"
                . 'Content SHA-256: ' . $current['fingerprint'] . "\nManual reviews remain unchanged.\n");
        }
        return 0;
    } catch (\Throwable $exception) {
        fwrite(STDERR, 'Qualification error: ' . $exception->getMessage() . "\n");
        return 2;
    }
}
