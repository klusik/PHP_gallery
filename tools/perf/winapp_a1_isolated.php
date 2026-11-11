<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tools/perf/winapp_a1_isolated.php
 * Module Type: Disposable CLI Benchmark
 * Purpose: Disposable PHP CLI subcomponent measurements for issue #362 A1.
 * Responsibilities:
 *   - Measure bounded synthetic tree revision and encoding work.
 *   - Measure fixture-only image hashing without real gallery traffic.
 *   - Retain isolated timing evidence without external HTTP or SQL access.
 * Limitations: No real gallery code, database, authorization, HTTP, or Tk UI.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

/**
 * Render exactly the tree-v1 encoding and revision work, without SQL/auth.
 *
 * @param list<array{id:int,parent_id:?int,title:string}> $rows Synthetic rows.
 * @param string|null $knownRevision Prior revision, or null for full response.
 * @return array{version:int,revision:string,unchanged:bool,galleries?:array}
 *     Validated complete or unchanged synthetic tree envelope.
 */
function a1_tree_core(array $rows, ?string $knownRevision): array
{
    if (count($rows) > 10000) {
        throw new OverflowException('row_limit');
    }
    $revision = hash('sha256', json_encode([
        'version' => 1,
        'issuer' => 'https://fixture.example.test/Galerie',
        'account' => 7,
        'scope' => 'link:identity galleries:list',
        'galleries' => $rows,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    $response = [
        'version' => 1, 'revision' => $revision,
        'unchanged' => false, 'galleries' => $rows,
    ];
    if (strlen(json_encode(
        $response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
    )) > 4 * 1024 * 1024) {
        throw new OverflowException('byte_limit');
    }
    if ($knownRevision !== null && hash_equals($revision, $knownRevision)) {
        unset($response['galleries']);
        $response['unchanged'] = true;
    }
    return $response;
}

/**
 * Measure a complete tree and a rehashed unchanged revision on the same rows.
 *
 * @param int $rowCount Deterministic synthetic catalogue size.
 * @param int $trial Trial index, zero denotes discarded warmup.
 * @return list<array<string,int|float|string>> Two elapsed observations.
 */
function a1_measure_tree(int $rowCount, int $trial): array
{
    $rows = [];
    for ($id = 1; $id <= $rowCount; ++$id) {
        $rows[] = [
            'id' => $id, 'parent_id' => $id === 1 ? null : max(1, intdiv($id, 10)),
            'title' => str_repeat('A', 80) . (string) $id,
        ];
    }
    $records = [];
    $revision = null;
    foreach (['full', 'unchanged'] as $outcome) {
        $start = hrtime(true);
        $result = a1_tree_core($rows, $revision);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        if ($outcome === 'unchanged' && !$result['unchanged']) {
            throw new RuntimeException('Unchanged revision failed.');
        }
        $revision = $result['revision'];
        $records[] = [
            'kind' => 'tree_encoding_hash', 'rows' => $rowCount,
            'outcome' => $outcome, 'trial' => $trial,
            'elapsed_ms' => round($elapsedMs, 6),
            'wire_bytes' => strlen(json_encode($result, JSON_THROW_ON_ERROR)),
        ];
    }
    return $records;
}

/**
 * Measure sequential SHA-256 streaming over 120 disposable 64-KiB files.
 *
 * @param list<resource> $streams Already created, explicitly owned temporary files.
 * @param int $trial Trial index, zero denotes discarded warmup.
 * @return array<string,mixed> Raw per-file durations and aggregate throughput.
 */
function a1_measure_hashes(array $streams, int $trial): array
{
    $durations = [];
    $begin = hrtime(true);
    foreach ($streams as $stream) {
        if (!rewind($stream)) {
            throw new RuntimeException('Could not rewind fixture.');
        }
        $hash = hash_init('sha256');
        $start = hrtime(true);
        $read = hash_update_stream($hash, $stream, 65536);
        $durations[] = round((hrtime(true) - $start) / 1_000_000, 6);
        if ($read !== 65536 || strlen(hash_final($hash)) !== 64) {
            throw new RuntimeException('Fixture hash read was incomplete.');
        }
    }
    return [
        'kind' => 'hash_update_stream', 'trial' => $trial,
        'files' => count($streams), 'bytes' => count($streams) * 65536,
        'elapsed_ms' => round((hrtime(true) - $begin) / 1_000_000, 6),
        'per_file_ms' => $durations,
    ];
}

$startedUtc = gmdate('Y-m-d\TH:i:s\Z');
$observations = [];
foreach ([500, 2000, 10000] as $rowCount) {
    for ($trial = 0; $trial <= 3; ++$trial) {
        foreach (a1_measure_tree($rowCount, $trial) as $record) {
            if ($trial !== 0) {
                $observations[] = $record;
            }
        }
    }
}
$streams = [];
try {
    for ($i = 0; $i < 120; ++$i) {
        $stream = tmpfile();
        if ($stream === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }
        $body = str_repeat(hash('sha256', 'a1-fixture-' . $i, true), 2048);
        if (fwrite($stream, $body) !== 65536) {
            fclose($stream);
            throw new RuntimeException('Unable to populate temporary file.');
        }
        $streams[] = $stream;
    }
    for ($trial = 0; $trial <= 3; ++$trial) {
        $record = a1_measure_hashes($streams, $trial);
        if ($trial !== 0) {
            $observations[] = $record;
        }
    }
} finally {
    foreach ($streams as $stream) {
        fclose($stream);
    }
}

echo json_encode([
    'schema' => 'php-gallery-a1-isolated-v1',
    'scope' => 'synthetic CLI JSON/hash plus temp-file SHA; not WinApp/API baseline',
    'started_utc' => $startedUtc,
    'ended_utc' => gmdate('Y-m-d\TH:i:s\Z'),
    'commit' => getenv('A1_COMMIT_SHA') ?: 'UNKNOWN',
    'php_version' => PHP_VERSION,
    'php_sapi' => PHP_SAPI,
    'os_family' => PHP_OS_FAMILY,
    'warmups_discarded' => 1,
    'measured_trials_per_case' => 3,
    'peak_memory_bytes' => memory_get_peak_usage(true),
    'observations' => $observations,
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
