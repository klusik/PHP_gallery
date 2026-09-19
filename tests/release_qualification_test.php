<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/release_qualification_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects release evidence identity, truthful status reporting and CLI behavior.
 *
 * Responsibilities:
 *   - Exercise isolated synthetic records without granting actual release approvals
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

require_once dirname(__DIR__) . '/scripts/release_qualification_lib.php';

use function PhpGallery\Audit\run_process;
use function PhpGallery\ReleaseQualification\assert_fingerprint;
use function PhpGallery\ReleaseQualification\assert_local_path;
use function PhpGallery\ReleaseQualification\cache_path;
use function PhpGallery\ReleaseQualification\encode;
use function PhpGallery\ReleaseQualification\initialize;
use function PhpGallery\ReleaseQualification\load_record;
use function PhpGallery\ReleaseQualification\preview_pages;
use function PhpGallery\ReleaseQualification\qualification_status;
use function PhpGallery\ReleaseQualification\record_audit;
use function PhpGallery\ReleaseQualification\record_manual;
use function PhpGallery\ReleaseQualification\render_previews;
use function PhpGallery\ReleaseQualification\render_summary;
use function PhpGallery\ReleaseQualification\snapshot;
use const PhpGallery\ReleaseQualification\MANUAL_CHECKS;

/** Keep assertions active regardless of the PHP assertions.ini configuration. */
function qualification_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Expect invalid evidence to fail explicitly, never to become a passing record. */
function qualification_rejects(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($message);
}

/** Create only synthetic fixture data underneath the unique test directory. */
function qualification_write(string $root, string $relative, string $contents): void
{
    $path = $root . '/' . $relative;
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    if (file_put_contents($path, $contents) === false) {
        throw new RuntimeException('Unable to write isolated qualification fixture.');
    }
}

/** Exercise the same dispatcher as the real CLI against an isolated fixture root. */
function qualification_cli(string $root, array $arguments): array
{
    return run_process([PHP_BINARY, $root . '/cache/fixture-cli.php', ...$arguments], $root, 15);
}

$fixture = sys_get_temp_dir() . '/gallery qualification-' . getmypid() . '-' . bin2hex(random_bytes(5));
mkdir($fixture, 0775, true);
try {
    qualification_write($fixture, 'app/bootstrap.php', "<?php\nconst CMS_VERSION = '0.104';\n");
    qualification_write($fixture, 'app/core-manifest.json', '{"version":"0.104","files":{}}');
    qualification_write($fixture, 'release-metadata.json', '{"0.104":{"tag":"v_0.104"}}');
    qualification_write($fixture, 'docs/PHP_Gallery_Manual.tex', "Synthetic manual source\n");
    qualification_write($fixture, 'docs/PHP_Gallery_Manual.pdf', "%PDF-1.4 synthetic bytes A\n");
    qualification_write($fixture, 'public/assets/gallery.js', "export const sample = 'A';\n");
    qualification_write($fixture, 'scripts/audit_registry.php', "<?php\nreturn ['profiles' => ['release' => ['php-regression', 'browser-map']]];\n");
    qualification_write($fixture, 'cache/fixture-cli.php', "<?php\nrequire "
        . var_export(dirname(__DIR__) . '/scripts/release_qualification_lib.php', true) . ";\n"
        . 'exit(\PhpGallery\ReleaseQualification\run_cli(' . var_export($fixture, true) . ', array_slice($argv, 1)));' . "\n");

    $first = snapshot($fixture, '0.104');
    qualification_assert($first === snapshot($fixture, '0.104'), 'Fingerprint discovery must be deterministic.');
    $process = qualification_cli($fixture, ['check', '0.104', '--json']);
    qualification_assert($process['exit_code'] === 1, 'Missing record must be incomplete.');
    $status = json_decode($process['stdout'], true, 512, JSON_THROW_ON_ERROR);
    qualification_assert(!$status['initialized'] && $status['automated'] === null, 'Check must not invent audit evidence.');
    qualification_assert(!is_dir($fixture . '/cache/release-qualification'), 'Read-only check must not initialize cache state.');
    qualification_assert(qualification_cli($fixture, ['init', '0.104'])['exit_code'] === 0, 'CLI init must succeed.');
    $record = load_record($fixture, $first);
    qualification_assert($record !== null && $record['automated'] === null, 'Init must leave automated evidence pending.');
    foreach ($record['manual'] as $review) {
        qualification_assert($review['status'] === 'pending', 'All manual reviews must start pending.');
    }
    qualification_assert(initialize($fixture, $first) === $record, 'Repeated init must not erase evidence or refresh timestamps.');

    qualification_rejects(fn() => snapshot($fixture, '../0.104'), 'Traversal-like version must fail.');
    qualification_rejects(fn() => snapshot($fixture, '0.105'), 'Runtime version mismatch must fail.');
    qualification_rejects(fn() => assert_fingerprint($first, str_repeat('0', 64)), 'Mismatched fingerprint must fail.');
    qualification_rejects(fn() => cache_path($fixture, '../outside.json', true), 'Cache traversal must fail.');
    qualification_rejects(fn() => cache_path($fixture, 'C:/outside.json', true), 'Cache drive paths must fail.');
    qualification_rejects(fn() => cache_path($fixture, 'record.json:stream', true), 'Windows alternate data streams must fail.');
    qualification_rejects(fn() => assert_local_path($fixture, dirname($fixture)), 'Resolved parent paths must be refused.');
    qualification_rejects(fn() => record_manual($fixture, $first, 'pdf-title', 'pass', '', 'visual inspection'),
        'Pass without a reviewer must fail.');
    qualification_rejects(fn() => record_manual($fixture, $first, 'pdf-title', 'pass', 'Fixture reviewer', ''),
        'Pass without evidence must fail.');
    qualification_rejects(fn() => record_manual($fixture, $first, 'pdf-title', 'skip', 'Fixture reviewer', 'not run'),
        'Manual skip must not masquerade as approval.');
    qualification_rejects(fn() => record_manual($fixture, $first, 'unknown-check', 'pass', 'Fixture reviewer', 'not real'),
        'Unknown manual check must fail.');

    $cli = qualification_cli($fixture, ['record', '0.104', 'pdf-title', 'pass',
        '--fingerprint=' . $first['fingerprint'], '--reviewer=Fixture reviewer',
        '--evidence=SYNTHETIC TEST ONLY: title page reviewed']);
    qualification_assert($cli['exit_code'] === 0, 'CLI must preserve reviewer/evidence arguments containing spaces.');
    $record = record_manual($fixture, $first, 'pdf-title', 'fail', 'Fixture reviewer', 'SYNTHETIC: clipped title');
    qualification_assert($record['manual']['pdf-title']['status'] === 'fail', 'Failed human review must remain failed.');
    $record = record_manual($fixture, $first, 'pdf-title', 'pending', 'Fixture reviewer', 'SYNTHETIC: waiting for review');
    qualification_assert(count($record['history']) === 3, 'Manual status changes must retain prior evidence.');
    foreach (MANUAL_CHECKS as $id => $definition) {
        if ($definition['phase'] === 'pre-publication') {
            $record = record_manual($fixture, $first, $id, 'pass', 'Fixture reviewer', 'SYNTHETIC TEST ONLY: reviewed fixture');
        }
    }
    qualification_assert(qualification_status($record, 'pre-publication')['status'] === 'INCOMPLETE',
        'Manual passes alone must not replace automated audit evidence.');

    $task = static fn(string $id, string $state): array => [
        'id' => $id, 'label' => $id, 'status' => $state, 'counts' => [],
        'summary' => $state === 'SKIP' ? 'Browser unavailable in fixture' : 'Fixture checks completed',
        'details' => [],
    ];
    $report = [
        'schema_version' => 1, 'profile' => 'release', 'status' => 'PASS',
        'source_fingerprint_before' => $first['fingerprint'],
        'source_fingerprint_after' => $first['fingerprint'],
        'started_at' => $record['created_at'], 'duration_seconds' => 0,
        'environment' => [],
        'tasks' => [$task('php-regression', 'PASS'), $task('browser-map', 'SKIP')],
    ];
    $report['tasks'][0]['counts'] = ['skipped' => 1];
    $report['tasks'][0]['details'] = ['gaps' => ['Fixture MySQL DSN absent']];
    qualification_write($fixture, 'cache/report.json', encode($report));
    $record = record_audit($fixture, $first, $fixture . '/cache/report.json');
    $status = qualification_status($record, 'pre-publication');
    qualification_assert($status['automated']['report']['status'] === 'PASS' && $status['status'] === 'INCOMPLETE',
        'Automated PASS with skips must not qualify the release.');
    $summary = render_summary($status);
    qualification_assert(str_contains($summary, 'Fixture MySQL DSN absent')
        && str_contains($summary, 'Browser unavailable in fixture')
        && str_contains($summary, 'post-publication-smoke (after publication)'),
        'Handoff must expose nested and whole-suite skips and later manual work.');

    $bad = $report;
    $bad['started_at'] = gmdate(DATE_ATOM, strtotime($record['created_at']) - 60);
    qualification_write($fixture, 'cache/report.json', encode($bad));
    qualification_rejects(fn() => record_audit($fixture, $first, $fixture . '/cache/report.json'), 'Old audit must not be rebound.');
    foreach (['source_fingerprint_before', 'source_fingerprint_after'] as $field) {
        $bad = $report;
        unset($bad[$field]);
        qualification_write($fixture, 'cache/report.json', encode($bad));
        qualification_rejects(fn() => record_audit($fixture, $first, $fixture . '/cache/report.json'),
            'Unstamped legacy report must not become artifact-bound evidence.');
        $bad[$field] = str_repeat('0', 64);
        qualification_write($fixture, 'cache/report.json', encode($bad));
        qualification_rejects(fn() => record_audit($fixture, $first, $fixture . '/cache/report.json'),
            'Changed or unrelated audit tree must not be rebound to current content.');
    }
    $bad = $report;
    $bad['started_at'] = gmdate(DATE_ATOM, time() + 3600);
    qualification_write($fixture, 'cache/report.json', encode($bad));
    qualification_rejects(fn() => record_audit($fixture, $first, $fixture . '/cache/report.json'), 'Future audit must fail.');
    foreach (['quick', 'full', 'release + custom'] as $profile) {
        $bad = $report;
        $bad['profile'] = $profile;
        qualification_write($fixture, 'cache/report.json', encode($bad));
        qualification_rejects(fn() => record_audit($fixture, $first, $fixture . '/cache/report.json'), 'Only full release profile may attach.');
    }
    $bad = $report;
    array_pop($bad['tasks']);
    qualification_write($fixture, 'cache/report.json', encode($bad));
    qualification_rejects(fn() => record_audit($fixture, $first, $fixture . '/cache/report.json'), 'Partial release report must fail.');
    $bad = $report;
    $bad['tasks'][] = $bad['tasks'][0];
    qualification_write($fixture, 'cache/report.json', encode($bad));
    qualification_rejects(fn() => record_audit($fixture, $first, $fixture . '/cache/report.json'), 'Duplicate suites must fail.');
    $bad = $report;
    $bad['tasks'][0]['status'] = 'FAIL';
    qualification_write($fixture, 'cache/report.json', encode($bad));
    qualification_rejects(fn() => record_audit($fixture, $first, $fixture . '/cache/report.json'), 'False PASS summary must fail.');

    $report['tasks'] = [$task('php-regression', 'PASS'), $task('browser-map', 'PASS')];
    qualification_write($fixture, 'cache/report.json', encode($report));
    $cli = qualification_cli($fixture, ['record-audit', '0.104', '--fingerprint=' . $first['fingerprint'], '--report=cache/report.json']);
    qualification_assert($cli['exit_code'] === 0, 'CLI must import an existing central report.');
    $record = load_record($fixture, $first);
    qualification_assert(qualification_status($record, 'pre-publication')['status'] === 'COMPLETE', 'Complete pre-publication evidence must qualify.');
    qualification_assert(qualification_status($record, 'post-publication')['status'] === 'INCOMPLETE', 'Post-publication smoke remains a separate pending phase.');
    qualification_assert(qualification_cli($fixture, ['check', '0.104'])['exit_code'] === 0, 'Complete CLI check must return zero.');
    qualification_assert(qualification_cli($fixture, ['check', '0.104', '--phase=post-publication'])['exit_code'] === 1,
        'Later-phase check must remain incomplete.');
    qualification_write($fixture, 'cache/report.json', '{}');
    qualification_assert(load_record($fixture, $first)['automated'] === $record['automated'], 'Replacing latest report must not replace captured evidence.');

    $before = snapshot($fixture, '0.104');
    qualification_write($fixture, 'cache/arbitrary.json', 'runtime state');
    qualification_write($fixture, 'config.php', 'local secret placeholder');
    qualification_write($fixture, 'public/assets/custom.css', 'local style');
    qualification_write($fixture, 'docs/PHP_Gallery_Manual.aux', 'compiler output');
    qualification_assert(snapshot($fixture, '0.104') === $before, 'Runtime state and LaTeX intermediates must not invalidate content.');
    qualification_write($fixture, 'app/services/galleries/source.php', "<?php\n// Real source directory, not runtime media.\n");
    qualification_write($fixture, 'app/services/cache/source.php', "<?php\n// Real source directory, not cache storage.\n");
    qualification_write($fixture, '.github/workflows/test.yml', "name: Synthetic CI\n");
    $nestedSource = snapshot($fixture, '0.104');
    qualification_assert(isset($nestedSource['files']['app/services/galleries/source.php'])
        && isset($nestedSource['files']['app/services/cache/source.php'])
        && isset($nestedSource['files']['.github/workflows/test.yml']),
        'CI and modules named galleries/cache must remain in the source fingerprint.');
    unlink($fixture . '/app/services/galleries/source.php');
    unlink($fixture . '/app/services/cache/source.php');
    unlink($fixture . '/.github/workflows/test.yml');
    qualification_assert(snapshot($fixture, '0.104') === $before, 'Removing synthetic source inputs must restore the initial fingerprint.');
    $mtime = filemtime($fixture . '/docs/PHP_Gallery_Manual.pdf');
    qualification_write($fixture, 'docs/PHP_Gallery_Manual.pdf', "%PDF-1.4 synthetic bytes B\n");
    touch($fixture . '/docs/PHP_Gallery_Manual.pdf', $mtime);
    $rebuilt = snapshot($fixture, '0.104');
    qualification_assert($rebuilt['fingerprint'] !== $first['fingerprint'], 'Rebuilt same-size, same-timestamp PDF must invalidate sign-off.');
    qualification_assert(load_record($fixture, $rebuilt) === null, 'New PDF may not reuse prior visual approval.');
    qualification_assert(qualification_cli($fixture, ['record', '0.104', 'pdf-title', 'pass', '--fingerprint=' . $first['fingerprint'],
        '--reviewer=Fixture reviewer', '--evidence=SYNTHETIC outdated review'])['exit_code'] === 2, 'Stale CLI fingerprint must refuse the write.');
    $newRecord = initialize($fixture, $rebuilt);
    qualification_assert($newRecord['manual']['pdf-title']['status'] === 'pending' && $newRecord['automated'] === null,
        'Changed content starts fresh manual and automated evidence.');
    qualification_assert(load_record($fixture, $first)['manual']['pdf-title']['status'] === 'pass', 'Old snapshot evidence must remain recoverable.');

    qualification_write($fixture, 'public/assets/new-module.js', "export const added = true;\n");
    $added = snapshot($fixture, '0.104');
    qualification_assert($added['fingerprint'] !== $rebuilt['fingerprint'], 'New source files must invalidate sign-off.');
    unlink($fixture . '/public/assets/new-module.js');
    qualification_assert(snapshot($fixture, '0.104') === $rebuilt, 'Removing an added input must restore exact content identity.');
    qualification_write($fixture, 'public/assets/gallery.js', "export const sample = 'B';\n");
    $changed = snapshot($fixture, '0.104');
    qualification_assert($changed['fingerprint'] !== $rebuilt['fingerprint'], 'Browser code changes must invalidate smoke results.');
    unlink($fixture . '/public/assets/gallery.js');
    $deleted = snapshot($fixture, '0.104');
    qualification_assert($deleted['fingerprint'] !== $changed['fingerprint'], 'Deleted source files must invalidate evidence.');
    qualification_write($fixture, 'docs/PHP_Gallery_Manual.tex', "Revised synthetic source\n");
    $texChanged = snapshot($fixture, '0.104');
    qualification_assert($texChanged['fingerprint'] !== $deleted['fingerprint'], 'Manual source edits must invalidate even before rebuilding the PDF.');

    qualification_assert(preview_pages('3,1-2,2') === [1, 2, 3], 'Physical preview pages must be sorted and deduplicated.');
    foreach (['0', '3-1', '../1', '1;whoami', '1-300', ''] as $invalid) {
        qualification_rejects(fn() => preview_pages($invalid), 'Unsafe or unbounded page selection must fail.');
    }
    initialize($fixture, $texChanged);
    qualification_rejects(fn() => render_previews($fixture, $texChanged, [1], 110, $fixture . '/missing-pdftoppm'),
        'Unavailable renderer must be a tooling failure, never a visual pass.');
    qualification_assert(load_record($fixture, $texChanged)['manual']['pdf-title']['status'] === 'pending',
        'Rendering failures must not approve human reviews.');
    $attempts = glob($fixture . '/cache/release-qualification/0.104/' . $texChanged['fingerprint'] . '/previews/*/preview.json');
    qualification_assert(count($attempts) === 1
        && json_decode(file_get_contents($attempts[0]), true)['status'] === 'failed',
        'Failed preview attempts must retain diagnostics in ignored cache.');

    foreach ([
        ['init', '0.104', '--unknown=yes'], ['check', '0.104', '--json=false'],
        ['check', '0.104', '--phase=invalid'], ['record', '0.104', 'pdf-title', 'pass'],
        ['render', '0.104', '--pages=1', '--dpi=110x'],
    ] as $arguments) {
        qualification_assert(qualification_cli($fixture, $arguments)['exit_code'] === 2, 'Invalid CLI arguments must fail explicitly.');
    }
} finally {
    // Only remove the exact private fixture allocated by this process.
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($fixture);
}
fwrite(STDOUT, "Release qualification contracts passed.\n");
