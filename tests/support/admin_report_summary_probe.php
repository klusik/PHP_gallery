<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/admin_report_summary_probe.php
 * Module Type: Regression Fixture
 * Purpose: Exercise real report publication and listing in a private cache tree.
 * Responsibilities: Count authoritative body reads and preserve legacy semantics.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply the isolated authenticated administrator boundary.
     * @return array{id:int,role:string} Fixture administrator identity.
     */
    function current_user(): array
    {
        return ['id' => 7, 'role' => 'admin'];
    }
}

namespace Gallery\Services {
    /**
     * Count authoritative body reads while delegating actual filesystem I/O.
     * @param string $filename Requested file path.
     * @param bool $useIncludePath Whether PHP should search its include path.
     * @param resource|null $context Optional stream context.
     * @param int $offset Starting byte offset.
     * @param int|null $length Maximum requested bytes, or null for the whole body.
     * @return string|false Actual file contents, or false for a failed read.
     */
    function file_get_contents(
        string $filename,
        bool $useIncludePath = false,
        mixed $context = null,
        int $offset = 0,
        ?int $length = null,
    ): string|false {
        if (basename($filename) === 'report.json') {
            $GLOBALS['summary_probe_body_reads']++;
        }
        return \file_get_contents($filename, $useIncludePath, $context, $offset, $length);
    }
}

namespace {
    /**
     * Fail the isolated probe when a behavior differs from its contract.
     * @param bool $condition Observed contract result.
     * @param string $message Failure description.
     * @return void Throws on failure; otherwise continues.
     */
    function summary_probe_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Delete only the private fixture tree created by this process.
     * @param string $directory Private fixture directory.
     * @return void Removes fixture files and empty directories.
     */
    function summary_probe_remove(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            if (is_dir($path) && !is_link($path)) {
                summary_probe_remove($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }

    /**
     * Populate a representative retained history beyond the latest-target window.
     * Type: int. Units: runs. Scope: private summary fixture.
     * Consumers: history publication loop.
     * Rationale: 24 runs cross the existing latest-twenty boundary.
     */
    const SUMMARY_PROBE_RUNS = 24;
    /**
     * Make each authoritative body materially larger than compact metadata.
     * Type: int. Units: bytes. Scope: private summary fixture.
     * Consumers: report payload publication.
     * Rationale: 2 MiB exercises the original multi-MiB decode cost per run.
     */
    const SUMMARY_PROBE_BODY_BYTES = 2097152;
    $fixture = sys_get_temp_dir() . '/gallery-report-summary-' . bin2hex(random_bytes(8));
    mkdir($fixture . '/app/services/admin_test_runs', 0775, true);
    try {
        $services = dirname(__DIR__, 2) . '/app/services';
        copy($services . '/../request_data.php', $fixture . '/app/request_data.php');
        copy($services . '/admin_test_runs.php', $fixture . '/app/services/admin_test_runs.php');
        foreach (glob($services . '/admin_test_runs/*.php') ?: [] as $source) {
            copy($source, $fixture . '/app/services/admin_test_runs/' . basename($source));
        }
        require $fixture . '/app/services/admin_test_runs.php';
        $GLOBALS['summary_probe_body_reads'] = 0;
        $tokens = [];
        for ($index = 1; $index <= SUMMARY_PROBE_RUNS; $index++) {
            $token = sprintf('%032x', $index);
            $tokens[] = $token;
            \Gallery\Services\admin_test_run_directory($token, true);
            $report = [
                'admin' => ['id' => 7],
                'created_at' => '2026-01-01T00:00:00Z',
                'finalized_at' => sprintf('2026-01-%02dT00:00:00Z', $index),
                'target' => ['page' => 'gallery', 'request_target' => '/?gallery=' . $index],
                'request_lifecycle' => ['completed_count' => $index, 'all_completed_cleanly' => true],
                'request_concurrency' => ['peak_concurrent_php_requests' => 1],
                'client' => ['trace' => str_repeat('x', SUMMARY_PROBE_BODY_BYTES)],
            ];
            \Gallery\Services\admin_test_run_write_json(
                \Gallery\Services\admin_test_run_report_path($token), $report,
            );
        }
        $rows = \Gallery\Services\admin_test_run_recent_reports(SUMMARY_PROBE_RUNS);
        summary_probe_assert(count($rows) === SUMMARY_PROBE_RUNS, 'All retained rows must remain visible.');
        summary_probe_assert($rows[0]['token'] === end($tokens), 'Newest finalized report must sort first.');
        summary_probe_assert($GLOBALS['summary_probe_body_reads'] === 0, 'Compact history must read zero full bodies.');
        summary_probe_assert(\Gallery\Services\admin_test_run_latest_for_target('/?gallery=1') === null, 'Latest-target must retain its twenty-row window.');
        summary_probe_assert(\Gallery\Services\admin_test_run_latest_for_target('/?gallery=24')['token'] === end($tokens), 'Newest matching target must remain available.');
        $GLOBALS['summary_probe_body_reads'] = 0;
        foreach (['missing', 'corrupt', 'stale', 'shape', 'oversized'] as $index => $case) {
            $directory = \Gallery\Services\admin_test_run_directory($tokens[$index]);
            $sidecar = $directory . '/report-summary.json';
            if ($case === 'missing') {
                unlink($sidecar);
            } elseif ($case === 'corrupt') {
                file_put_contents($sidecar, '{invalid');
            } elseif ($case === 'oversized') {
                // One byte past the documented admission limit exercises fallback.
                file_put_contents($sidecar, str_repeat('x', \Gallery\Services\ADMIN_TEST_RUN_SUMMARY_MAX_BYTES + 1));
            } else {
                $summary = json_decode(\file_get_contents($sidecar), true);
                if ($case === 'stale') {
                    $summary['source']['size']++;
                } else {
                    $summary['report']['admin']['id'] = ['invalid'];
                }
                file_put_contents($sidecar, json_encode($summary));
            }
        }
        summary_probe_assert(\Gallery\Services\admin_test_run_recent_reports(SUMMARY_PROBE_RUNS) === $rows, 'Legacy and damaged sidecars must preserve exact rows.');
        summary_probe_assert($GLOBALS['summary_probe_body_reads'] === 5, 'Only five damaged/missing sidecars require full bodies.');
        $lastToken = end($tokens);
        $path = \Gallery\Services\admin_test_run_report_path($lastToken);
        $report = json_decode(\file_get_contents($path), true);
        $before = \file_get_contents($path);
        $report['admin']['id'] = 8;
        \Gallery\Services\admin_test_run_write_json($path, $report);
        $GLOBALS['summary_probe_body_reads'] = 0;
        $updated = \Gallery\Services\admin_test_run_recent_reports(SUMMARY_PROBE_RUNS);
        summary_probe_assert(count($updated) === SUMMARY_PROBE_RUNS - 1, 'Same-length owner update must refresh its summary.');
        summary_probe_assert($updated[0]['token'] === $tokens[SUMMARY_PROBE_RUNS - 2], 'Another administrator must not leak into listing.');
        $report['admin']['id'] = 7;
        \Gallery\Services\admin_test_run_write_json($path, $report);
        summary_probe_assert(\file_get_contents($path) === $before, 'Summary publication must preserve full JSON bytes.');
        unlink($path);
        summary_probe_assert(count(\Gallery\Services\admin_test_run_recent_reports(SUMMARY_PROBE_RUNS)) === SUMMARY_PROBE_RUNS - 1, 'Orphan sidecar must not invent report availability.');
        fwrite(STDOUT, "Compact report summary probe passed.\n");
    } finally {
        summary_probe_remove($fixture);
    }
}
