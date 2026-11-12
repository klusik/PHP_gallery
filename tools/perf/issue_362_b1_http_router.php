<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tools/perf/issue_362_b1_http_router.php
 * Module Type: Disposable Loopback HTTP Transport Harness
 * Purpose: Measure experimental thumbnail bodies over isolated real HTTP.
 * Responsibilities:
 *   - Serve synthetic B1.1 data through PHP's temporary development server.
 *   - Provide bounded, explicitly simulated status and corruption scenarios.
 *   - Record PHP producer measurements without altering response wire format.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/issue_362_b1_producer.php';

/**
 * Bound a single intentionally delayed transport fixture.
 * Type: int. Units: microseconds per item. Scope: CI-only loopback HTTP.
 * Consumers: HTTP after-item callback. Rationale: 25 ms makes progressive
 * delivery observable without sustained server activity.
 */
const B1_HTTP_ITEM_DELAY_US = 25000;
/**
 * Bound one simulated response stall before the first body byte.
 * Type: int. Units: microseconds. Scope: CI-only cancellation probes.
 * Consumers: delayed response branches. Rationale: 300 ms exceeds the
 * fixture's short cancellation timeout without creating a long-running job.
 */
const B1_HTTP_STALL_US = 300000;
/**
 * Bound metric filenames and prevent arbitrary query-controlled paths.
 * Type: int. Units: request sequence identifiers. Scope: one CI invocation.
 * Consumers: metric writing. Rationale: 9999 covers the finite local matrix.
 */
const B1_HTTP_MAX_SEQUENCE = 9999;
/**
 * Bound the permitted benchmark fixture count to the B1 wire maximum.
 * Type: list<int>. Units: images/request. Scope: synthetic manifest reads.
 * Consumers: batch route. Rationale: only the requested 8/16/24/32 matrix.
 */
const B1_HTTP_COUNTS = [8, 16, 24, 32];
/**
 * Limit fault injection to named, reproducible, non-production cases.
 * Type: list<string>. Units: fault labels. Scope: one disposable HTTP response.
 * Consumers: batch route. Rationale: reject arbitrary wire mutation input.
 */
const B1_HTTP_FAULTS = [
    'none', 'html', 'wrong_type', 'no_type', 'wrong_length',
    'short_header', 'short_image', 'between_items', 'missing_end',
    'bad_digest', 'unknown_version', 'duplicate_id', 'bad_etag',
    'invalid_body', 'oversize_length', 'stall_before_headers',
    'stall_before_body', 'stall_between_items',
];

/**
 * Record sanitized measurements in the owned disposable fixture directory.
 *
 * @param string $root Canonical temporary fixture directory.
 * @param int $sequence Validated bounded request sequence.
 * @param array<string,int|float|string> $metrics Producer and response metrics.
 * @return void
 */
function b1_http_record(string $root, int $sequence, array $metrics): void
{
    $encoded = json_encode($metrics, JSON_THROW_ON_ERROR);
    $file = $root . '/metric_' . $sequence . '.json';
    if (file_put_contents($file, $encoded, LOCK_EX) === false) {
        error_log('B1 HTTP metrics write failed');
    }
}

/**
 * Produce a deterministic invalid wire variant from an actual B1.1 response.
 *
 * @param string $body Complete, bounded B1.1 response body.
 * @param string $format Framed or multipart.
 * @param string $fault Named artificial fault, never a production behavior.
 * @return string Deterministically altered or truncated wire bytes.
 */
function b1_http_corrupt(string $body, string $format, string $fault): string
{
    if ($fault === 'invalid_body') {
        return '<broken-protocol>';
    }
    if ($fault === 'short_header') {
        return substr($body, 0, 3);
    }
    if ($fault === 'missing_end') {
        return substr(
            $body, 0, -($format === 'framed' ? 3 : strlen(B1_BOUNDARY) + 6)
        );
    }
    if ($fault === 'unknown_version') {
        if ($format === 'framed') {
            $body[4] = chr(2);
            return $body;
        }
        return preg_replace('/"v":1/', '"v":2', $body, 1) ?? '';
    }
    if ($fault === 'duplicate_id') {
        return str_replace('"image_id":"2"', '"image_id":"1"', $body);
    }
    if ($fault === 'bad_etag') {
        // JSON escapes the strong validator's inner quotes. Mutate one hex
        // digit in the first actual ETag, preserving the encoded byte count.
        $etagStart = strpos($body, '"etag":');
        if ($etagStart === false) {
            throw new RuntimeException('missing_fixture_etag');
        }
        $hashOffset = $etagStart + strlen('"etag":') + 3;
        if (!isset($body[$hashOffset])
            || !ctype_xdigit($body[$hashOffset])) {
            throw new RuntimeException('invalid_fixture_etag_position');
        }
        $body[$hashOffset] = 'z';
        return $body;
    }
    if ($format === 'framed') {
        $headerLength = unpack('n', substr($body, 8, 2))[1];
        $payloadLength = unpack('N', substr($body, 10, 4))[1];
        $payloadStart = 14 + $headerLength;
        $itemEnd = $payloadStart + $payloadLength;
    } else {
        $marker = strpos($body, "\r\n\r\n");
        if ($marker === false) {
            throw new RuntimeException('missing_fixture_part_header');
        }
        $payloadStart = $marker + 4;
        $nextMarker = strpos(
            $body, "\r\n--" . B1_BOUNDARY, $payloadStart
        );
        if ($nextMarker === false) {
            throw new RuntimeException('missing_fixture_part_tail');
        }
        $itemEnd = $nextMarker + 2;
    }
    if ($fault === 'short_image') {
        return substr($body, 0, $payloadStart + 1);
    }
    if ($fault === 'between_items') {
        return substr($body, 0, $itemEnd);
    }
    if ($fault === 'bad_digest') {
        $body[$payloadStart] = chr(ord($body[$payloadStart]) ^ 1);
    }
    return $body;
}

$rootEnvironment = getenv('B1_HTTP_FIXTURE_ROOT');
$root = is_string($rootEnvironment) ? realpath($rootEnvironment) : false;
if (PHP_SAPI !== 'cli-server'
    || getenv('GITHUB_ACTIONS') !== 'true'
    || getenv('GALLERY_WORKFLOW_ENABLE') !== 'disposable-only'
    || $root === false
    || !is_dir($root)
    || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
    http_response_code(403);
    exit;
}

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if ($path === '/health') {
    header('Content-Type: text/plain');
    echo 'ok';
    return true;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    return true;
}
$sequence = filter_var(
    $_GET['sequence'] ?? null, FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => B1_HTTP_MAX_SEQUENCE]]
);
if ($path === '/status') {
    $code = filter_var($_GET['code'] ?? null, FILTER_VALIDATE_INT);
    if (!in_array($code, [400, 401, 403, 404, 405, 429, 502, 503], true)) {
        http_response_code(400);
        return true;
    }
    http_response_code($code);
    header('Content-Type: text/plain');
    if ($code === 429 && isset($_GET['retry'])) {
        $retry = (string) $_GET['retry'];
        if (in_array($retry, ['2', 'bad', '-5', ''], true)) {
            header('Retry-After: ' . $retry);
        }
    }
    echo 'synthetic_http_failure';
    return true;
}
if ($path !== '/batch' && $path !== '/single') {
    http_response_code(404);
    return true;
}
if ($sequence === false || $sequence === null) {
    http_response_code(400);
    return true;
}
$start = hrtime(true);
try {
    if ($path === '/single') {
        $index = filter_var(
            $_GET['index'] ?? null, FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0, 'max_range' => 31]]
        );
        if ($index === false || $index === null) {
            http_response_code(400);
            return true;
        }
        $fixturePath = $root . '/manifest_32_cold.json';
        $document = json_decode(
            (string) file_get_contents($fixturePath), true,
            flags: JSON_THROW_ON_ERROR
        );
        $item = b1_prepare_item($document['items'][$index], $root, 0);
        $meta = json_decode($item['meta'], true, flags: JSON_THROW_ON_ERROR);
        header('Content-Type: ' . $item['mime']);
        header('ETag: ' . $meta['etag']);
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $meta['etag']) {
            http_response_code(304);
            b1_http_record($root, $sequence, [
                'producer_ms' => (hrtime(true) - $start) / 1000000,
                'php_peak_bytes' => memory_get_peak_usage(true),
                'wire_bytes' => 0, 'sha256_ms' => $item['hash_ms'],
                'mode' => 'single_304',
            ]);
            return true;
        }
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('http_output_open_failed');
        }
        $total = 0;
        b1_copy($output, $item['path'], $item['length'], $total);
        fflush($output);
        flush();
        fclose($output);
        b1_http_record($root, $sequence, [
            'producer_ms' => (hrtime(true) - $start) / 1000000,
            'php_peak_bytes' => memory_get_peak_usage(true),
            'wire_bytes' => $total, 'sha256_ms' => $item['hash_ms'],
            'mode' => 'single',
        ]);
        return true;
    }

    $format = (string) ($_GET['protocol'] ?? '');
    $case = (string) ($_GET['case'] ?? '');
    $mode = (string) ($_GET['mode'] ?? '');
    $fault = (string) ($_GET['fault'] ?? 'none');
    $count = filter_var($_GET['count'] ?? null, FILTER_VALIDATE_INT);
    if (!in_array($format, ['framed', 'multipart'], true)
        || !in_array($count, B1_HTTP_COUNTS, true)
        || !in_array($case, ['cold', 'warm'], true)
        || !in_array($mode, ['stream', 'buffer', 'delayed'], true)
        || !in_array($fault, B1_HTTP_FAULTS, true)) {
        http_response_code(400);
        return true;
    }
    $manifest = $root . '/manifest_' . $count . '_' . $case . '.json';
    if (!is_file($manifest)) {
        http_response_code(400);
        return true;
    }
    if ($fault === 'stall_before_headers') {
        usleep(B1_HTTP_STALL_US);
    }
    if ($fault === 'html') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<html><body>synthetic</body></html>';
        return true;
    }
    $type = $format === 'framed'
        ? 'application/vnd.php-gallery.thumbnail-batch.v1'
        : 'multipart/mixed; boundary=' . B1_BOUNDARY;
    if ($fault !== 'no_type') {
        header('Content-Type: ' . (
            $fault === 'wrong_type' ? 'text/plain' : $type
        ));
    } else {
        header_remove('Content-Type');
    }
    if ($fault === 'stall_before_body') {
        flush();
        usleep(B1_HTTP_STALL_US);
    }
    $bufferFault = in_array($fault, [
        'wrong_length', 'short_header', 'short_image', 'between_items',
        'missing_end', 'bad_digest', 'unknown_version', 'duplicate_id',
        'bad_etag', 'invalid_body', 'oversize_length',
    ], true);
    $buffer = $mode === 'buffer' || $bufferFault;
    if ($buffer) {
        ob_start();
    }
    $afterItem = static function () use ($mode, $fault, $buffer): void {
        if (!$buffer) {
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
        if ($mode === 'delayed' || $fault === 'stall_between_items') {
            usleep(
                $fault === 'stall_between_items'
                    ? B1_HTTP_STALL_US : B1_HTTP_ITEM_DELAY_US
            );
        }
    };
    $producer = b1_run($format, $manifest, 'php://output', $afterItem);
    if ($buffer) {
        $body = (string) ob_get_clean();
        if ($bufferFault && $fault !== 'oversize_length'
            && $fault !== 'wrong_length') {
            $body = b1_http_corrupt($body, $format, $fault);
        }
        if ($fault === 'wrong_length') {
            header('Content-Length: ' . (strlen($body) + 100));
        } elseif ($fault === 'oversize_length') {
            header('Content-Length: ' . (B1_RESPONSE_MAX_BYTES + 1));
        }
        echo $body;
    }
    b1_http_record($root, $sequence, [
        'producer_ms' => $producer['producer_ms'],
        'php_peak_bytes' => memory_get_peak_usage(true),
        'wire_bytes' => $producer['bytes'],
        'sha256_ms' => $producer['sha256_ms'], 'mode' => $mode,
    ]);
} catch (Throwable $error) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
    }
    error_log('B1 HTTP isolated fixture refused: ' . $error->getMessage());
}
return true;
