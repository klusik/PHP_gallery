<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tools/perf/issue_362_b1_producer.php
 * Module Type: Isolated Protocol Benchmark Producer
 * Purpose: Emit disposable B1 framed or multipart thumbnail bodies on CI.
 * Responsibilities:
 *   - Validate synthetic fixture metadata and containment.
 *   - Stream bounded items without buffering a complete batch.
 *   - Report monotonic producer time and PHP peak memory.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

/**
 * Cap the experimental response including framing and MIME headers.
 * Type: int. Units: bytes. Scope: one B1.1 response.
 * Consumers: b1_emit, b1_copy. Rationale: 16 MiB is a bounded trial ceiling,
 * not an assumed hosting limit or a production selection.
 */
const B1_RESPONSE_MAX_BYTES = 16 * 1024 * 1024;
/**
 * Bound one decoded thumbnail before its complete integrity validation.
 * Type: int. Units: bytes. Scope: one B1.1 item.
 * Consumers: b1_prepare_item. Rationale: 4 MiB bounds parser retention.
 */
const B1_ITEM_MAX_BYTES = 4 * 1024 * 1024;
/**
 * Bound one request's item count and associated server execution cost.
 * Type: int. Units: items. Scope: one B1.1 request.
 * Consumers: b1_run. Rationale: 32 covers the largest requested experiment.
 */
const B1_MAX_ITEMS = 32;
/**
 * Bound per-item JSON envelope before it reaches the wire.
 * Type: int. Units: bytes. Scope: one B1.1 item.
 * Consumers: b1_run. Rationale: 2048 is ample for fixed safe metadata.
 */
const B1_MAX_META_BYTES = 2048;
/**
 * Keep file-copy operations small regardless of image count.
 * Type: int. Units: bytes. Scope: one producer file-read operation.
 * Consumers: b1_copy. Rationale: 8192 prevents whole-image PHP allocations.
 */
const B1_COPY_CHUNK_BYTES = 8192;
/**
 * Select a deterministic MIME test boundary; not a production identifier.
 * Type: string. Units: ASCII boundary characters. Scope: B1.1 fixture only.
 * Consumers: b1_run. Rationale: a fixed 36-character safe token makes
 * byte-count comparisons reproducible; production must choose per-response
 * unpredictable boundaries and check for delimiter collisions.
 */
const B1_BOUNDARY = 'pgb1_362_benchmark_v1_boundary_2026';
/**
 * Only permit image types exercised by the synthetic fixture generator.
 * Type: list<string>. Units: MIME tokens. Scope: isolated B1.1.
 * Consumers: b1_prepare_item. Rationale: JPEG and WebP are display formats.
 */
const B1_IMAGE_MIMES = ['image/jpeg', 'image/webp'];
/**
 * Enumerate safe per-item status values for this exact wire version.
 * Type: list<string>. Units: status tokens. Scope: isolated B1.1.
 * Consumers: b1_prepare_item. Rationale: unknown status fails closed.
 */
const B1_STATUSES = ['ok', 'not_modified', 'unavailable', 'oversize'];

/**
 * Write all supplied bytes or fail instead of silently emitting a short frame.
 *
 * @param resource $stream Open writable synthetic output file.
 * @param string $bytes Bytes to write, including binary content.
 * @return void
 */
function b1_write(mixed $stream, string $bytes): void
{
    $offset = 0;
    $length = strlen($bytes);
    while ($offset < $length) {
        $written = fwrite($stream, substr($bytes, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('synthetic_write_failed');
        }
        $offset += $written;
    }
}

/**
 * Enforce the full response cap before writing each encoded fragment.
 *
 * @param resource $stream Open writable synthetic output file.
 * @param string $bytes Next encoded protocol fragment.
 * @param int $total Cumulative written bytes, updated after success.
 * @return void
 */
function b1_emit(mixed $stream, string $bytes, int &$total): void
{
    $size = strlen($bytes);
    if ($size > B1_RESPONSE_MAX_BYTES - $total) {
        throw new RuntimeException('batch_oversize');
    }
    b1_write($stream, $bytes);
    $total += $size;
}

/**
 * Stream an already-validated fixture without full-image PHP allocation.
 *
 * @param resource $destination Open synthetic output file.
 * @param string $path Checked synthetic thumbnail file path.
 * @param int $size Expected image byte length.
 * @param int $total Cumulative bytes, updated after successful transfer.
 * @return void
 */
function b1_copy(mixed $destination, string $path, int $size, int &$total): void
{
    if ($size > B1_RESPONSE_MAX_BYTES - $total) {
        throw new RuntimeException('batch_oversize');
    }
    $source = fopen($path, 'rb');
    if ($source === false) {
        throw new RuntimeException('fixture_open_failed');
    }
    try {
        $remaining = $size;
        while ($remaining > 0) {
            $part = fread($source, min(B1_COPY_CHUNK_BYTES, $remaining));
            if ($part === false || $part === '') {
                throw new RuntimeException('fixture_truncated');
            }
            b1_write($destination, $part);
            $count = strlen($part);
            $total += $count;
            $remaining -= $count;
        }
        if (fread($source, 1) !== '') {
            throw new RuntimeException('fixture_changed');
        }
    } finally {
        fclose($source);
    }
}

/**
 * Validate a canonical ID and a file under the disposable fixture directory.
 *
 * @param array<string,mixed> $item Synthetic item descriptor.
 * @param string $root Canonical temporary fixture directory.
 * @param int $index Request-order slot in the manifest.
 * @return array{meta:string,mime:string,length:int,path:string,hash_ms:float}
 *     Validated encoded metadata and bounded local payload details.
 */
function b1_prepare_item(array $item, string $root, int $index): array
{
    $id = $item['image_id'] ?? null;
    $status = $item['status'] ?? null;
    if (!is_string($id)
        || preg_match('/\A[1-9][0-9]{0,18}\z/D', $id) !== 1
        || (string) (int) $id !== $id
        || !is_string($status)
        || !in_array($status, B1_STATUSES, true)) {
        throw new RuntimeException('invalid_fixture_descriptor');
    }
    $path = '';
    $mime = 'application/octet-stream';
    $length = 0;
    $hash = null;
    $etag = null;
    $hashMs = 0.0;
    if ($status === 'ok') {
        $name = $item['file'] ?? null;
        $requestedMime = $item['mime'] ?? null;
        if (!is_string($name)
            || preg_match('/\A[A-Za-z0-9_.-]{1,120}\z/D', $name) !== 1
            || str_contains($name, '..')
            || !is_string($requestedMime)
            || !in_array($requestedMime, B1_IMAGE_MIMES, true)) {
            throw new RuntimeException('invalid_fixture_file');
        }
        $path = realpath($root . DIRECTORY_SEPARATOR . $name) ?: '';
        if ($path === '' || !str_starts_with(
            $path, $root . DIRECTORY_SEPARATOR
        ) || !is_file($path)) {
            throw new RuntimeException('fixture_outside_root');
        }
        $lengthValue = filesize($path);
        if (!is_int($lengthValue) || $lengthValue < 1
            || $lengthValue > B1_ITEM_MAX_BYTES) {
            throw new RuntimeException('item_oversize');
        }
        $length = $lengthValue;
        $mime = $requestedMime;
        $hashStart = hrtime(true);
        $hashValue = hash_file('sha256', $path);
        $hashMs = (hrtime(true) - $hashStart) / 1_000_000;
        if (!is_string($hashValue)) {
            throw new RuntimeException('fixture_hash_failed');
        }
        $hash = $hashValue;
        $etag = '"' . $hash . '"';
    } elseif ($status === 'not_modified') {
        $provided = $item['etag'] ?? null;
        if (!is_string($provided)
            || preg_match('/\A"[a-f0-9]{64}"\z/D', $provided) !== 1) {
            throw new RuntimeException('invalid_cached_validator');
        }
        $etag = $provided;
    }
    $metadata = [
        'v' => 1, 'image_id' => $id, 'request_index' => $index,
        'status' => $status, 'mime' => $mime, 'length' => $length,
        'sha256' => $hash, 'etag' => $etag,
    ];
    $json = json_encode(
        $metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
    );
    if (strlen($json) > B1_MAX_META_BYTES) {
        throw new RuntimeException('metadata_oversize');
    }
    return ['meta' => $json, 'mime' => $mime, 'length' => $length,
        'path' => $path, 'hash_ms' => $hashMs];
}

/**
 * Emit one safe framed or multipart body from an isolated manifest.
 *
 * @param string $protocol Exact 'framed' or 'multipart' format selector.
 * @param string $manifestPath Absolute disposable JSON manifest path.
 * @param string $outputPath Absolute disposable binary output path or php://output.
 * @param Closure|null $onItem Optional HTTP-only flush/delay callback after each item.
 * @return array{protocol:string,items:int,bytes:int,producer_ms:float,
 *     sha256_ms:float,php_peak_bytes:int,boundary:string|null} Sanitized run metrics.
 */
function b1_run(
    string $protocol, string $manifestPath, string $outputPath,
    ?Closure $onItem = null
): array {
    if (!in_array($protocol, ['framed', 'multipart'], true)) {
        throw new RuntimeException('unknown_protocol');
    }
    $document = json_decode(
        (string) file_get_contents($manifestPath), true,
        flags: JSON_THROW_ON_ERROR
    );
    if (!is_array($document) || !is_string($document['root'] ?? null)
        || !is_array($document['items'] ?? null)) {
        throw new RuntimeException('invalid_manifest');
    }
    $root = realpath($document['root']);
    $items = $document['items'];
    $count = count($items);
    if ($root === false || !is_dir($root)
        || $count < 1 || $count > B1_MAX_ITEMS
        || !array_is_list($items)) {
        throw new RuntimeException('invalid_batch');
    }
    $start = hrtime(true);
    $seen = [];
    $prepared = [];
    $hashTotalMs = 0.0;
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            throw new RuntimeException('invalid_fixture_descriptor');
        }
        $data = b1_prepare_item($item, $root, $index);
        $hashTotalMs += $data['hash_ms'];
        $id = $item['image_id'];
        if (isset($seen[$id])) {
            throw new RuntimeException('duplicate_image_id');
        }
        $seen[$id] = true;
        $prepared[] = $data;
    }
    $output = fopen($outputPath, 'wb');
    if ($output === false) {
        throw new RuntimeException('output_open_failed');
    }
    $total = 0;
    try {
        if ($protocol === 'framed') {
            b1_emit($output, pack('a4Cn', 'PGB1', 1, $count), $total);
        }
        foreach ($prepared as $data) {
            if ($protocol === 'framed') {
                b1_emit(
                    $output, pack('CnN', 73, strlen($data['meta']),
                        $data['length']) . $data['meta'], $total
                );
            } else {
                $header = '--' . B1_BOUNDARY . "\r\n"
                    . 'Content-Type: ' . $data['mime'] . "\r\n"
                    . 'Content-Length: ' . $data['length'] . "\r\n"
                    . 'X-PG-Meta: ' . $data['meta'] . "\r\n\r\n";
                b1_emit($output, $header, $total);
            }
            if ($data['length'] > 0) {
                b1_copy(
                    $output, $data['path'], $data['length'], $total
                );
            }
            if ($protocol === 'multipart') {
                b1_emit($output, "\r\n", $total);
            }
            if ($onItem !== null) {
                $onItem();
            }
        }
        if ($protocol === 'framed') {
            b1_emit($output, pack('Cn', 69, $count), $total);
        } else {
            b1_emit($output, '--' . B1_BOUNDARY . "--\r\n", $total);
        }
    } catch (Throwable $error) {
        fclose($output);
        @unlink($outputPath);
        throw $error;
    }
    fclose($output);
    return [
        'protocol' => $protocol, 'items' => $count, 'bytes' => $total,
        'producer_ms' => (hrtime(true) - $start) / 1_000_000,
        'sha256_ms' => $hashTotalMs,
        'php_peak_bytes' => memory_get_peak_usage(true),
        'boundary' => $protocol === 'multipart' ? B1_BOUNDARY : null,
    ];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
try {
    if (PHP_SAPI !== 'cli'
        || getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('GALLERY_WORKFLOW_ENABLE') !== 'disposable-only'
        || $argc !== 4) {
        throw new RuntimeException('disposable_ci_only');
    }
    $report = b1_run($argv[1], $argv[2], $argv[3]);
    fwrite(STDOUT, json_encode($report, JSON_THROW_ON_ERROR) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'B1 producer refused: ' . $error->getMessage() . "\n");
    exit(1);
}
}
