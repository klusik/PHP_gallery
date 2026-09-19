<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/recovery_assurance_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Exercise recovery evidence, corruption detection, isolation and CLI refusal behavior.
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

/** Behavioral recovery tooling regression; synthetic temporary files only, no bootstrap/database. */
require_once dirname(__DIR__) . '/scripts/recovery/cli.php';

use function Gallery\Recovery\check_set;
use function Gallery\Recovery\drill;
use function Gallery\Recovery\fingerprint;
use function Gallery\Recovery\inventory;
use function Gallery\Recovery\json_text;
use function Gallery\Recovery\observations_template;
use function Gallery\Recovery\read_json;
use function Gallery\Recovery\set_template;
use function Gallery\Recovery\validate;
use function Gallery\Recovery\write_json;

$failures = [];
$assertions = 0;
$assert = static function (bool $condition, string $label) use (&$failures, &$assertions): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $label;
    }
};
$rejects = static function (callable $operation, string $label) use ($assert): void {
    try {
        $operation();
        $assert(false, $label);
    } catch (Throwable $error) {
        $assert(true, $label);
    }
};
$status = static function (array $report, string $id): ?string {
    foreach ($report['checks'] as $check) {
        if ($check['id'] === $id) {
            return $check['status'];
        }
    }
    return null;
};

/** Commands execute as argument arrays: no shell interpolation or inherited config bootstrap. */
$cli = static function (array $arguments): array {
    $pipes = [];
    $process = proc_open(array_merge([PHP_BINARY, dirname(__DIR__) . '/scripts/recovery.php'], $arguments), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not run the recovery CLI fixture.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => $stdout . $stderr];
};

// Cleanup is restricted to this unique directory, checks containment and never follows links.
$temporary = sys_get_temp_dir() . '/gallery-recovery-test-' . bin2hex(random_bytes(10));
if (!mkdir($temporary, 0700)) {
    throw new RuntimeException('Could not create recovery test directory.');
}
$remove = static function (string $path) use (&$remove, $temporary): void {
    $parent = realpath(dirname($path));
    $base = realpath($temporary);
    if ($base === false || $parent === false || ($path !== $temporary && !\Gallery\Core\path_inside($base, $parent))) {
        throw new RuntimeException('Unsafe fixture cleanup refused.');
    }
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $remove($path . '/' . $entry);
        }
    }
    rmdir($path);
};

try {
    $work = $temporary . '/round-trip';
    $run = $cli(['drill', '--work=' . $work]);
    $assert($run['exit'] === 0 && str_contains($run['output'], 'coverage=fixture_only'), 'CLI fixture drill passes with explicit limited coverage');
    $drill = read_json($work . '/drill-evidence.json');
    $assert($drill['status'] === 'PASS' && $drill['recovery_readiness'] === 'not_proven', 'Synthetic pass never proves production recovery');
    foreach ($drill['assertions'] as $name => $value) {
        $assert($value === true, 'Drill assertion: ' . $name);
    }
    $rejects(static fn () => drill($work), 'Existing fixture directory is never overwritten');
    $second = drill($temporary . '/second-round-trip');
    $assert($second['status'] === 'PASS', 'Independent fixture drill is repeatable');
    $set = read_json($work . '/set.json');
    $root = $work . '/restored';
    $marker = read_json($root . '/.recovery-isolated.json');
    $point = strtotime($set['recovery_point']);
    $now = $point + 120;

    $initial = inventory(set_template(), time());
    $assert($initial['status'] === 'INCOMPLETE', 'Unknown provider inventory stays incomplete');
    $completeInventory = inventory($set, $now);
    $assert($completeInventory['status'] === 'PASS' && $completeInventory['recovery_readiness'] === 'not_proven', 'Complete receipts do not prove a restore');
    $missing = $set;
    $missing['components']['database']['state'] = 'absent';
    $assert(inventory($missing, $now)['status'] === 'FAIL', 'Missing database fails inventory');
    $mixed = $set;
    $mixed['components']['database']['snapshot_id'] = 'different-set';
    $rejects(static fn () => check_set($mixed), 'Mixed snapshots rejected');
    $receiptMissing = $set;
    $receiptMissing['components']['data']['receipt_sha256'] = null;
    $assert(inventory($receiptMissing, $now)['status'] === 'INCOMPLETE', 'Component presence without provider receipt is incomplete');
    $old = $set;
    $old['targets']['rpo_seconds'] = 30;
    $assert($status(inventory($old, $now), 'rpo_met') === 'FAIL', 'Old backup exceeds agreed RPO');
    $future = $set;
    $future['recovery_point'] = gmdate('Y-m-d\TH:i:s\Z', $now + 1);
    $assert($status(inventory($future, $now), 'recovery_point') === 'FAIL', 'Future recovery point cannot pass');
    $unhashed = $set;
    foreach ($unhashed['originals'] as &$entry) {
        $entry['sha256'] = null;
    }
    unset($entry);
    $assert($status(inventory($unhashed, $now), 'original_hash_sample') === 'INCOMPLETE', 'Hash sampling is required for a nonempty catalog');

    $pending = validate($set, $root, null, $now);
    $assert($pending['status'] === 'INCOMPLETE' && $status($pending, 'private_gallery_denial') === 'INCOMPLETE', 'Files cannot imply protected HTTP access');
    $assert($pending['originals_hashed'] === 2 && $pending['application_files_checked'] === 2, 'Validation reads restored originals and application files');
    $assert($status($pending, 'rto_met') === 'INCOMPLETE', 'No operator timestamps means unknown RTO');

    $observed = observations_template($set, $marker);
    $observed['started_at'] = gmdate('Y-m-d\TH:i:s\Z', $point + 30);
    $observed['completed_at'] = gmdate('Y-m-d\TH:i:s\Z', $point + 80);
    $observed['counts'] = $set['counts'];
    $observed['checks'] = array_fill_keys(\Gallery\Recovery\OPERATOR_CHECKS, 'pass');
    $observed['orphan_images'] = 0;
    $observed['orphan_galleries'] = 0;
    $observed['pending_migrations'] = 0;
    $observed['evidence_sha256'] = hash('sha256', 'synthetic operator evidence');
    $valid = validate($set, $root, $observed, $now);
    $assert($valid['status'] === 'PASS' && $valid['recovery_readiness'] === 'operator_attested', 'Complete evidence remains explicitly operator attested');
    $assert($valid['recovery_duration_seconds'] === 50 && $valid['backup_age_seconds'] === 120 && $valid['data_loss_window_seconds'] === 30, 'Backup age, RTO and data-loss window are distinct');
    $assert($status($valid, 'admin_login') === 'PASS', 'Operator status is recorded without running authentication');
    $badLogin = $observed;
    $badLogin['checks']['admin_login'] = 'fail';
    $assert(validate($set, $root, $badLogin, $now)['status'] === 'FAIL', 'Failed operator check cannot be masked by file success');
    $slow = $observed;
    $slow['completed_at'] = gmdate('Y-m-d\TH:i:s\Z', $point + 100);
    $assert($status(validate($set, $root, $slow, $now), 'rto_met') === 'FAIL', 'Slow restore exceeds RTO');
    foreach (['orphan_images', 'orphan_galleries', 'pending_migrations'] as $name) {
        $bad = $observed;
        $bad[$name] = 1;
        $assert($status(validate($set, $root, $bad, $now), $name) === 'FAIL', 'Nonzero ' . $name . ' fails');
    }
    $bad = $observed;
    $bad['counts']['images']++;
    $assert($status(validate($set, $root, $bad, $now), 'database_count_images') === 'FAIL', 'Catalog and restored database count disagreement fails');
    $bad = $observed;
    $bad['run_id'] = str_repeat('0', 32);
    $rejects(static fn () => validate($set, $root, $bad, $now), 'Other drill evidence rejected');
    $changed = $set;
    $changed['targets']['rto_seconds']++;
    $rejects(static fn () => validate($changed, $root, $observed, $now), 'Editing the set invalidates old isolation and evidence');
    $bad = $observed;
    $bad['completed_at'] = gmdate('Y-m-d\TH:i:s\Z', $now + 1);
    $rejects(static fn () => validate($set, $root, $bad, $now), 'Future completion rejected');
    $bad = $observed;
    $bad['started_at'] = gmdate('Y-m-d\TH:i:s\Z', $point - 1);
    $rejects(static fn () => validate($set, $root, $bad, $now), 'Start before snapshot rejected');

    foreach (['../config.php', 'galleries/../config.php', 'galleries/config.php', 'galleries/x:secret.png', '/galleries/a.png', 'galleries/one/./one.png', 'galleries/one/one.png.', 'galleries/NUL.png'] as $path) {
        $unsafe = $set;
        $unsafe['originals'][0]['path'] = $path;
        $rejects(static fn () => check_set($unsafe), 'Unsafe catalog path rejected');
    }
    $duplicate = $set;
    $duplicate['originals'][1] = $duplicate['originals'][0];
    $rejects(static fn () => check_set($duplicate), 'Duplicate original entries rejected');
    $wrongCount = $set;
    $wrongCount['counts']['images']++;
    $rejects(static fn () => check_set($wrongCount), 'Declared counts must match catalog length');
    $injected = $set;
    $injected['password'] = 'DO-NOT-LEAK-SYNTHETIC';
    $rejects(static fn () => check_set($injected), 'Unrecognized fields rejected');
    $rejects(static fn () => validate($set, dirname(__DIR__), null, $now), 'Current installation refused before opening anything');
    $rejects(static fn () => validate($set, dirname(__DIR__) . '/galleries', null, $now), 'Live gallery root refused');
    $rejects(static fn () => validate($set, dirname(dirname(__DIR__)), null, $now), 'Parent of current installation refused');
    if (PHP_OS_FAMILY === 'Windows') {
        $rejects(static fn () => \Gallery\Recovery\external_directory(strtoupper(dirname(__DIR__))), 'Case changes cannot bypass installation overlap');
    }
    $rejects(static fn () => read_json($temporary . '/config.php:stream.json'), 'Alternate streams rejected before any file read');
    mkdir($temporary . '/unmarked', 0700);
    $rejects(static fn () => validate($set, $temporary . '/unmarked', null, $now), 'Missing isolated marker refused');

    $unsafeMarker = $marker;
    $unsafeMarker['controls']['network_egress_blocked'] = false;
    file_put_contents($root . '/.recovery-isolated.json', json_text($unsafeMarker));
    $rejects(static fn () => validate($set, $root, null, $now), 'Unconfirmed isolation refused');
    file_put_contents($root . '/.recovery-isolated.json', json_text($marker));

    $core = $root . '/index.php';
    $originalCore = file_get_contents($core);
    file_put_contents($core, str_replace("\n", "\r\n", $originalCore));
    $assert($status(validate($set, $root, null, $now), 'application_files') === 'PASS', 'Existing canonical integrity normalization reused');
    file_put_contents($core, 'corrupted synthetic application');
    $assert($status(validate($set, $root, null, $now), 'application_files') === 'FAIL', 'Application corruption detected');
    file_put_contents($core, $originalCore);
    $manifest = file_get_contents($root . '/app/core-manifest.json');
    file_put_contents($root . '/app/core-manifest.json', $manifest . ' ');
    $assert($status(validate($set, $root, null, $now), 'application_files') === 'FAIL', 'Manifest drift detected before trusting its paths');
    file_put_contents($root . '/app/core-manifest.json', $manifest);

    $linked = $temporary . '/linked.json';
    if (@symlink($work . '/set.json', $linked)) {
        $rejects(static fn () => read_json($linked), 'Symbolic input link rejected');
    }
    $hardlinked = $temporary . '/hardlinked.json';
    if (@link($work . '/set.json', $hardlinked)) {
        $rejects(static fn () => read_json($hardlinked), 'Hardlinked input rejected');
        unlink($hardlinked);
    }

    $privateBefore = fingerprint($set);
    $reportFile = $temporary . '/validate-evidence.json';
    $run = $cli(['validate', '--set=' . $work . '/set.json', '--root=' . $root, '--out=' . $reportFile]);
    $assert($run['exit'] === 2 && str_contains($run['output'], 'INCOMPLETE'), 'CLI incomplete exit code is distinct');
    $evidence = (string) file_get_contents($reportFile);
    $assert(!str_contains($evidence, $root) && !str_contains($evidence, 'galleries/one') && !str_contains($evidence, 'Synthetic fixture must'), 'Evidence omits paths, names and content');
    $assert(fingerprint(read_json($work . '/set.json')) === $privateBefore, 'Validation does not mutate its inputs');
    $rejects(static fn () => write_json($reportFile, []), 'Evidence is never overwritten');
    $assert(file_get_contents($reportFile) === $evidence, 'Existing evidence bytes preserved');
    $run = $cli(['validate', '--set=' . $work . '/set.json', '--root=' . $root, '--out=' . $root . '/must-not-write.json']);
    $assert($run['exit'] === 1 && !file_exists($root . '/must-not-write.json'), 'Evidence cannot mutate restored tree');
    $run = $cli(['inventory', '--set=' . $work . '/set.json', '--out=' . $temporary . '/inventory.json']);
    $assert($run['exit'] === 0 && str_contains($run['output'], 'inventory_only'), 'CLI inventory success retains limited coverage');
    write_json($temporary . '/injected.json', $injected);
    $run = $cli(['inventory', '--set=' . $temporary . '/injected.json', '--out=' . $temporary . '/bad-evidence.json']);
    $assert($run['exit'] === 1 && !str_contains($run['output'], 'DO-NOT-LEAK') && !str_contains($run['output'], $temporary), 'Invalid input never exposes values or paths');
    $run = $cli(['inventory', '--set=' . dirname(__DIR__) . '/config.php', '--out=' . $temporary . '/forbidden.json']);
    $assert($run['exit'] === 1 && !str_contains($run['output'], 'config.php'), 'Config input rejected without opening or printing it');
    $run = $cli(['inventory', '--set=a', '--set=b', '--out=' . $temporary . '/duplicate.json']);
    $assert($run['exit'] === 1, 'Duplicate CLI options rejected');
    $run = $cli(['template', '--kind=set', '--out=' . $temporary . '/new-template.json']);
    $assert($run['exit'] === 0 && read_json($temporary . '/new-template.json')['components']['database']['state'] === 'unknown', 'Generated templates never invent evidence');
} catch (Throwable $error) {
    // Fixture-only diagnostics never include paths or underlying I/O messages.
    $code = preg_match('/^[a-z_]+$/D', $error->getMessage()) === 1 ? $error->getMessage() : 'unexpected_error';
    $failures[] = 'Unexpected recovery fixture exception: ' . get_class($error) . ' ' . $code . ' at ' . basename($error->getFile()) . ':' . $error->getLine();
} finally {
    $remove($temporary);
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo 'Recovery assurance behavioral tests passed (' . $assertions . " assertions).\n";
