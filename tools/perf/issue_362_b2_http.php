<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tools/perf/issue_362_b2_http.php
 * Module Type: Disposable HTTP qualification
 * Purpose: Exercise the real thumbnail batch route against owned MariaDB and HTTP.
 * Responsibilities: Verify scoped protocol, authorization, shared rate accounting,
 *   sanitized synthetic comparisons and the archived WinApp client without
 *   production network access; verify the current client against archived PHP.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tests/support/gallery_workflow_fixture.php';
require_once dirname(__DIR__, 2) . '/app/services/security_tokens.php';
require_once dirname(__DIR__, 2) . '/app/services/app_linking_policy.php';
require_once __DIR__ . '/issue_362_d_old_server.php';

/**
 * Bound the disposable HTTP qualification resources.
 * Type: array<string,int>. Units: seconds, images, and bytes as named.
 * Scope: one isolated B2 HTTP fixture.
 * Consumers: b2_request, b2_run, and b2_parse.
 * Rationale: 3 seconds bounds connection setup, 12 seconds bounds one transfer,
 *     and 120 seconds bounds seeding. 120 images matches A1 and 8 MiB caps
 *     response reads. A 2400-second synthetic grant covers the bounded suite.
 *     The 2048-byte metadata and 4 MiB image bounds mirror protocol v1,
 *     while JSON depth 8 bounds fixture decoding. The 600-attempt value
 *     represents the existing five-minute IP policy for the refusal test.
 */
const B2_LIMITS = ['connect_seconds' => 3, 'request_seconds' => 12,
    'seed_seconds' => 120, 'grant_seconds' => 2400, 'images' => 120,
    'body_bytes' => 8388608, 'metadata_bytes' => 2048,
    'image_bytes' => 4194304, 'json_depth' => 8, 'ip_attempts' => 600];

/**
 * Choose representative batch sizes for real owned HTTP experiments.
 * Type: list<int>. Units: photos per batch. Scope: B2 synthetic timings.
 * Consumers: b2_run. Rationale: 8, 16, 24 and 32 span initial, planned and
 *     absolute protocol groups while retaining a finite 120-photo corpus.
 */
const B2_GROUP_SIZES = [8, 16, 24, 32];

/**
 * Bound each owned Python child of the existing HTTP audit.
 * Type: int. Units: seconds. Scope: one client/archive qualification process.
 * Consumers: Fixture::run for B3 and both D source-compatibility cohorts.
 * Rationale: 240 seconds leaves the existing 600-second audit suite bounded
 * while covering 600 local thumbnail transfers and the small legacy cohort.
 */
const B3_CLIENT_TIMEOUT_SECONDS = 240;

/**
 * Issue one loopback HTTP request with bounded time and without redirects.
 *
 * @param string $origin Exact fixture localhost origin.
 * @param string $query Exact synthetic route query.
 * @param string $method HTTP method.
 * @param string|null $token Fixture-only bearer or null.
 * @param string|null $body Request body or null.
 * @param string $contentType Explicit request content type.
 * @param list<string> $extraHeaders Explicit fixture-only optional headers.
 * @return array{status:int,headers:array<string,string>,body:string,elapsed_ms:float} Response and timing.
 */
function b2_request(string $origin, string $query, string $method, ?string $token = null,
    ?string $body = null, string $contentType = 'application/json', array $extraHeaders = []): array
{
    $handle = curl_init($origin . '/index.php?' . $query);
    if ($handle === false) {
        throw new RuntimeException('Cannot initialize fixture HTTP.');
    }
    $headers = [];
    $responseBody = '';
    $requestHeaders = ['Content-Type: ' . $contentType, ...$extraHeaders];
    if ($token !== null) {
        $requestHeaders[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => B2_LIMITS['connect_seconds'], CURLOPT_TIMEOUT => B2_LIMITS['request_seconds'],
        CURLOPT_PROXY => '', CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_WRITEFUNCTION => static function (\CurlHandle $curl, string $chunk) use (&$responseBody): int {
            if (strlen($responseBody) + strlen($chunk) > B2_LIMITS['body_bytes']) {
                return 0;
            }
            $responseBody .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $elapsed = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000;
    unset($handle);
    if ($response === false) {
        throw new RuntimeException('Fixture HTTP transfer failed or exceeded bounds.');
    }
    return ['status' => $status, 'headers' => $headers, 'body' => $responseBody,
        'elapsed_ms' => $elapsed];
}

/**
 * Encode one canonical production batch request.
 *
 * @param int $galleryId Owned physical gallery identifier.
 * @param list<string> $ids Ordered canonical owned image identifiers.
 * @return string Compact canonical JSON request.
 */
function b2_body(int $galleryId, array $ids): string
{
    return json_encode(['v' => 1, 'gallery_id' => (string) $galleryId,
        'image_ids' => $ids], JSON_THROW_ON_ERROR);
}

/**
 * Decode and independently verify all framed response bytes and metadata.
 *
 * @param string $body Complete response body.
 * @param list<string> $ids Expected ordered canonical identifiers.
 * @return list<array{metadata:array{v:int,image_id:string,request_index:int,status:string,mime:string,length:int,sha256:?string,etag:?string},bytes:string}> Verified item records.
 */
function b2_parse(string $body, array $ids): array
{
    \GalleryWorkflow\check(strlen($body) >= 10 && substr($body, 0, 5) === "PGB1\x01",
        'Invalid framed header.');
    // PGB1 has a 7-byte header and each item has a 7-byte prefix; the
    // completion marker is E plus a two-byte count, all network byte order.
    $count = unpack('nvalue', substr($body, 5, 2))['value'];
    \GalleryWorkflow\check($count === count($ids), 'Unexpected frame count.');
    $offset = 7;
    $items = [];
    foreach ($ids as $index => $id) {
        \GalleryWorkflow\check(substr($body, $offset, 1) === 'I'
            && strlen($body) >= $offset + 7, 'Missing item frame.');
        $lengths = unpack('nmeta/Ndata', substr($body, $offset + 1, 6));
        $offset += 7;
        $metaLength = $lengths['meta'];
        $dataLength = $lengths['data'];
        \GalleryWorkflow\check($metaLength <= B2_LIMITS['metadata_bytes'] && $dataLength <= B2_LIMITS['image_bytes']
            && strlen($body) >= $offset + $metaLength + $dataLength, 'Frame bounds failed.');
        $meta = json_decode(substr($body, $offset, $metaLength), true, B2_LIMITS['json_depth'], JSON_THROW_ON_ERROR);
        $offset += $metaLength;
        $bytes = substr($body, $offset, $dataLength);
        $offset += $dataLength;
        \GalleryWorkflow\check(is_array($meta) && array_keys($meta) === [
            'v', 'image_id', 'request_index', 'status', 'mime', 'length', 'sha256', 'etag',
        ] && $meta['v'] === 1 && $meta['image_id'] === $id
            && $meta['request_index'] === $index && $meta['status'] === 'ok'
            && in_array($meta['mime'], ['image/jpeg', 'image/webp'], true)
            && $meta['length'] === $dataLength && $dataLength > 0
            && hash_equals(hash('sha256', $bytes), (string) $meta['sha256']),
            'Frame metadata or digest failed.');
        $items[] = ['metadata' => $meta, 'bytes' => $bytes];
    }
    \GalleryWorkflow\check(substr($body, $offset) === 'E' . pack('n', $count),
        'Missing completion or trailing data.');
    return $items;
}

/**
 * Clear only owned fixture app-linking limiter rows between experiments.
 *
 * @param PDO $db Verified owned fixture connection.
 * @return void Reset only selected isolated bucket counters and rows.
 */
function b2_reset_rates(PDO $db): void
{
    $db->exec("DELETE FROM viewer_rate_limits WHERE LEFT(bucket, 9) = 'app_link_'");
    $db->exec("UPDATE viewer_rate_limit_buckets SET entry_count = 0 WHERE LEFT(bucket, 9) = 'app_link_'");
}

/**
 * Capture total attempts in each shared photo bucket for this owned database.
 *
 * @param PDO $db Verified owned fixture connection.
 * @return array<string,int> Bucket-to-attempts mapping.
 */
function b2_rate_totals(PDO $db): array
{
    $rows = $db->query("SELECT bucket, SUM(attempts) AS total FROM viewer_rate_limits "
        . "WHERE bucket IN ('app_link_photo_ip','app_link_photo_global','app_link_photo_account') GROUP BY bucket")
        ->fetchAll(PDO::FETCH_ASSOC);
    $totals = [];
    foreach ($rows as $row) {
        $totals[$row['bucket']] = (int) $row['total'];
    }
    return $totals;
}

/**
 * Verify a small opaque HTTP error without exposing response contents.
 *
 * @param array{status:int,headers:array<string,string>,body:string,elapsed_ms:float} $response Actual response.
 * @param int $status Required HTTP status.
 * @param string|null $code Required stable error code when applicable.
 * @return void Throw a sanitized failure if the contract is not met.
 */
function b2_error(array $response, int $status, ?string $code = null): void
{
    $body = json_decode($response['body'], true, B2_LIMITS['json_depth']);
    $noFrames = !str_starts_with($response['body'], 'PGB1');
    $noStore = str_contains(strtolower($response['headers']['cache-control'] ?? ''), 'no-store');
    $validCode = $code === null || (is_array($body) && ($body['error'] ?? null) === $code);
    if (!$noFrames || !$noStore || $response['status'] !== $status || !$validCode) {
        // Only numeric statuses and booleans from this fixture are safe to emit.
        fwrite(STDERR, 'B2 refusal mismatch expected=' . $status
            . ' actual=' . $response['status'] . ' no_frames=' . (int) $noFrames
            . ' no_store=' . (int) $noStore . ' code_match=' . (int) $validCode . "\n");
        throw new RuntimeException('Fixture refusal contract failed.');
    }
}

/**
 * Run and validate the pinned old WinApp client inside the owned HTTP fixture.
 *
 * @param \GalleryWorkflow\Fixture $fixture Owned isolated installation.
 * @param string $origin Exact fixture localhost origin.
 * @param string $publicBearer Owned public-media bearer.
 * @param string $adminBearer Owned Admin-media bearer.
 * @param int $galleryId Owned physical gallery identifier.
 * @param string $publicImageId Public synthetic photo identifier.
 * @param string $privateImageId Synthetic photo temporarily marked private.
 * @param string $publicSha256 Existing single-route SHA-256 for the public photo.
 * @param string $privateSha256 Existing single-route SHA-256 for the private photo.
 * @return array{
 *   schema:string,source_commit:string,source_version:string,assertions:string,
 *   batch_capability_advertised:bool,legacy_client_batch_method:bool,
 *   public_photo_sha256_match:bool,public_photo_mime_match:bool,
 *   public_private_refusal_status:int,admin_private_photo_sha256_match:bool,
 *   admin_private_photo_mime_match:bool,legacy_thumbnail_gets:int,
 *   batch_posts:int,routes:array<string,int>
 * } Validated sanitized compatibility evidence.
 */
function b2_archived_legacy_client(
    \GalleryWorkflow\Fixture $fixture,
    string $origin,
    string $publicBearer,
    string $adminBearer,
    int $galleryId,
    string $publicImageId,
    string $privateImageId,
    string $publicSha256,
    string $privateSha256,
): array
{
    $reportPath = $fixture->directory . '/issue-362-d-legacy-client.json';
    $childEnvironment = [
        'PHP_GALLERY_D_LEGACY_ISSUER' => $origin,
        'PHP_GALLERY_D_LEGACY_PUBLIC_BEARER' => $publicBearer,
        'PHP_GALLERY_D_LEGACY_ADMIN_BEARER' => $adminBearer,
        'PHP_GALLERY_D_LEGACY_GALLERY_ID' => (string) $galleryId,
        'PHP_GALLERY_D_LEGACY_PUBLIC_IMAGE_ID' => $publicImageId,
        'PHP_GALLERY_D_LEGACY_PRIVATE_IMAGE_ID' => $privateImageId,
        'PHP_GALLERY_D_LEGACY_PUBLIC_SHA256' => $publicSha256,
        'PHP_GALLERY_D_LEGACY_PRIVATE_SHA256' => $privateSha256,
        'PHP_GALLERY_D_LEGACY_REPORT' => $reportPath,
    ];
    $previousEnvironment = [];
    foreach ($childEnvironment as $name => $_value) {
        $previousEnvironment[$name] = getenv($name);
        if ($previousEnvironment[$name] !== false) {
            throw new RuntimeException('Archived client environment collision.');
        }
    }
    try {
        foreach ($childEnvironment as $name => $value) {
            putenv($name . '=' . $value);
        }
        $fixture->run(
            ['python3', __DIR__ . '/issue_362_d_legacy_client.py'],
            B3_CLIENT_TIMEOUT_SECONDS,
            'D archived legacy WinApp client',
            true,
        );
    } finally {
        foreach ($previousEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
    }
    $reportText = file_get_contents($reportPath);
    \GalleryWorkflow\check(is_string($reportText), 'Archived client report missing.');
    $report = json_decode(
        $reportText, true, B2_LIMITS['json_depth'], JSON_THROW_ON_ERROR,
    );
    \GalleryWorkflow\check(is_array($report)
        && ($report['schema'] ?? null) === 'issue-362-d-legacy-client-v1'
        && ($report['source_commit'] ?? null)
            === '1c1e2e9145cd92cb2f0ee731da5655c900d4d3e1'
        && ($report['source_version'] ?? null) === '1.0.10'
        && ($report['assertions'] ?? null) === 'passed'
        && ($report['batch_capability_advertised'] ?? null) === true
        && ($report['legacy_client_batch_method'] ?? null) === false
        && ($report['public_photo_sha256_match'] ?? null) === true
        && ($report['public_photo_mime_match'] ?? null) === true
        && ($report['public_private_refusal_status'] ?? null) === 404
        && ($report['admin_private_photo_sha256_match'] ?? null) === true
        && ($report['admin_private_photo_mime_match'] ?? null) === true
        && ($report['legacy_thumbnail_gets'] ?? null) === 3
        && ($report['batch_posts'] ?? null) === 0
        && is_array($report['routes'] ?? null)
        && ($report['routes']['app_linking_discovery'] ?? null) === 1
        && ($report['routes']['app_linking_identity'] ?? null) === 2
        && ($report['routes']['app_linking_galleries'] ?? null) === 2
        && ($report['routes']['app_linking_media'] ?? null) === 5
        && count($report['routes']) === 4,
        'Archived client report contract failed.',
    );
    return $report;
}

/**
 * Run real isolated HTTP, SQL and media behavior against the prepared application.
 *
 * @param \GalleryWorkflow\Fixture $fixture Owned isolated installation.
 * @return array{
 *   schema:string,head_sha:string,assertions:string,timings_ms:array<string,float>,
 *   legacy_client:array{
 *     schema:string,source_commit:string,source_version:string,assertions:string,
 *     batch_capability_advertised:bool,legacy_client_batch_method:bool,
 *     public_photo_sha256_match:bool,public_photo_mime_match:bool,
 *     public_private_refusal_status:int,admin_private_photo_sha256_match:bool,
 *     admin_private_photo_mime_match:bool,legacy_thumbnail_gets:int,
 *     batch_posts:int,routes:array<string,int>
 *   },
 *   legacy_server:array{
 *     schema:string,client_source_commit:string,server_source_commit:string,
 *     server_version:string,assertions:string,batch_capability_advertised:bool,
 *     current_session_batch_enabled:bool,reference_mime_sha256_matches:bool,
 *     published_images:int,legacy_thumbnail_gets:int,batch_posts:int,
 *     routes:array<string,int>,statuses:array<int,int>
 *   },
 *   client:array{
 *     schema:string,assertions:string,profile_count:int,image_count:int,
 *     equal_sha_mime_items_per_profile:int,
 *     measurement:array{cold_definition:string,server_cache_state:string,server_timing_scope:string},
 *     profiles:list<array{
 *       name:string,group_size:int|null,items:int,
 *       proof_binding:array{metadata_pages:int,metadata_rows_observed:int,selected_count:int},
 *       cold:array{
 *         phase:string,items:int,http_requests:int,response_bytes:int,
 *         cache_hits:int,equal_items:int,cache_writes:int,
 *         cache_written_bytes:int,request_size_histogram:array<string,int>,
 *         elapsed_ms:float,first_ready_ms:float|null,
 *         first_wire_frame_ms:float|null,
 *         network_request_ms:array{count:int,samples_ms:list<float>,min_ms:float|null,max_ms:float|null,p50_ms:float|null,p95_ms:float|null,p99_ms:float|null},
 *         ttfb_ms:array{count:int,samples_ms:list<float>,min_ms:float|null,max_ms:float|null,p50_ms:float|null,p95_ms:float|null,p99_ms:float|null},
 *         body_ms:array{count:int,samples_ms:list<float>,min_ms:float|null,max_ms:float|null,p50_ms:float|null,p95_ms:float|null,p99_ms:float|null},
 *         operation_ms:array{count:int,samples_ms:list<float>,min_ms:float|null,max_ms:float|null,p50_ms:float|null,p95_ms:float|null,p99_ms:float|null},
 *         server_timing_header_snapshot:list<array<string,bool|int|float>|null>
 *       },
 *       warm:array{
 *         phase:string,items:int,http_requests:int,response_bytes:int,
 *         cache_hits:int,equal_items:int,cache_writes:int,
 *         cache_written_bytes:int,request_size_histogram:array<string,int>,
 *         elapsed_ms:float,first_ready_ms:float|null,
 *         first_wire_frame_ms:float|null,
 *         network_request_ms:array{count:int,samples_ms:list<float>,min_ms:float|null,max_ms:float|null,p50_ms:float|null,p95_ms:float|null,p99_ms:float|null},
 *         ttfb_ms:array{count:int,samples_ms:list<float>,min_ms:float|null,max_ms:float|null,p50_ms:float|null,p95_ms:float|null,p99_ms:float|null},
 *         body_ms:array{count:int,samples_ms:list<float>,min_ms:float|null,max_ms:float|null,p50_ms:float|null,p95_ms:float|null,p99_ms:float|null},
 *         operation_ms:array{count:int,samples_ms:list<float>,min_ms:float|null,max_ms:float|null,p50_ms:float|null,p95_ms:float|null,p99_ms:float|null},
 *         server_timing_header_snapshot:list<array<string,bool|int|float>|null>
 *       }
 *     }>,
 *     scope:string
 *   },
 *   scope:string
 * } Sanitized evidence.
 */
function b2_run(\GalleryWorkflow\Fixture $fixture): array
{
    $fixture->start();
    $routerPath = $fixture->directory . '/router.php';
    $router = (string) file_get_contents($routerPath);
    $entry = "require __DIR__ . '/public/index.php';";
    \GalleryWorkflow\check(substr_count($router, $entry) === 1, 'Unexpected owned router.');
    $injection = <<<'PHP'
if (PHP_SAPI === 'cli-server' && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
    && getenv('GALLERY_WORKFLOW_ENABLE') === 'disposable-only') {
    if (!isset($_SERVER['SERVER_ADDR'])) {
        $_SERVER['SERVER_ADDR'] = '127.0.0.1';
    }
    if (is_file(__DIR__ . '/.b2-env-zero')) {
        putenv('PHP_GALLERY_THUMBNAIL_BATCH_V1=0');
    } elseif (is_file(__DIR__ . '/.b2-env-one')) {
        putenv('PHP_GALLERY_THUMBNAIL_BATCH_V1=1');
    } else {
        putenv('PHP_GALLERY_THUMBNAIL_BATCH_V1');
    }
}
PHP;
    file_put_contents($routerPath, str_replace($entry, $injection . "\n" . $entry, $router));
    $configPath = $fixture->directory . '/config.php';
    $config = require $configPath;
    \GalleryWorkflow\check(is_array($config) && str_starts_with($fixture->url, 'http://127.0.0.1:'),
        'Unexpected fixture origin.');
    $origin = 'http://localhost:' . substr($fixture->url, strlen('http://127.0.0.1:'));
    $config['base_url'] = $origin;
    file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");
    $fixture->run([PHP_BINARY, __DIR__ . '/issue_362_a1_seed.php',
        $fixture->directory, $fixture->token], B2_LIMITS['seed_seconds'], 'B2 owned image seed');
    $db = \GalleryWorkflow\fixtureDatabase($fixture->directory, $fixture->token);
    $seed = json_decode((string) file_get_contents($fixture->directory . '/seed.json'), true, B2_LIMITS['json_depth'], JSON_THROW_ON_ERROR);
    $galleryId = (int) $seed['root_id'];
    $protectedId = (int) $seed['protected_id'];
    $userId = (int) $db->query("SELECT id FROM users WHERE username = 'workflow_admin'")->fetchColumn();
    $query = $db->prepare("SELECT id FROM images WHERE gallery_id = ? AND visibility = 'public' ORDER BY id LIMIT "
        . B2_LIMITS['images']);
    $query->execute([$galleryId]);
    $ids = array_map(static fn(string|int $id): string => (string) $id, $query->fetchAll(PDO::FETCH_COLUMN));
    \GalleryWorkflow\check(count($ids) === B2_LIMITS['images'] && $userId > 0, 'Synthetic public corpus unavailable.');
    $insert = $db->prepare('INSERT INTO app_link_grants (user_id, client_id, issuer, scope, created_at, '
        . 'access_token_hash, access_issued_at, access_expires_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?, '
        . 'UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))');
    $tokens = [];
    foreach (['public' => 'link:identity galleries:list media:read',
        'admin' => 'link:identity galleries:list media:admin', 'identity' => 'link:identity'] as $kind => $scope) {
        $tokens[$kind] = \Gallery\Services\app_linking_access_token_generate();
        $insert->execute([$userId, 'php-gallery-winapp', $origin, $scope,
            \Gallery\Services\app_linking_access_token_hash($tokens[$kind]),
            B2_LIMITS['grant_seconds']]);
    }
    $route = 'page=app_linking_thumbnail_batch';
    $body = b2_body($galleryId, [$ids[0]]);
    $discovery = b2_request($origin, 'page=app_linking_discovery', 'GET');
    $discoveryData = json_decode($discovery['body'], true, B2_LIMITS['json_depth']);
    \GalleryWorkflow\check($discovery['status'] === 200
        && ($discoveryData['app_linking']['available'] ?? false) === true
        && in_array('media:thumbnail-batch-v1', $discoveryData['app_linking']['capabilities'] ?? [], true), 'Missing-setting default-on discovery failed.');
    $defaultResponse = b2_request($origin, $route, 'POST', $tokens['public'], $body);
    \GalleryWorkflow\check($defaultResponse['status'] === 200
        && ($defaultResponse['headers']['content-type'] ?? '') === 'application/vnd.php-gallery.thumbnail-batch.v1',
        'Missing-setting default-on HTTP response failed.');
    b2_parse($defaultResponse['body'], [$ids[0]]);
    $preference = $db->prepare('INSERT INTO app_settings (setting_key, setting_value, updated_at) '
        . 'VALUES (?, ?, UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), '
        . 'updated_at = VALUES(updated_at)');
    $preference->execute(['feature_flag.thumbnail_batch.enabled', '0']);
    file_put_contents($fixture->directory . '/.b2-env-one', '1');
    $disabledDiscovery = b2_request($origin, 'page=app_linking_discovery', 'GET');
    $disabledData = json_decode($disabledDiscovery['body'], true, B2_LIMITS['json_depth']);
    \GalleryWorkflow\check($disabledDiscovery['status'] === 200
        && !in_array('media:thumbnail-batch-v1', $disabledData['app_linking']['capabilities'] ?? [], true),
        'Saved OFF must override legacy environment ON in discovery.');
    b2_error(b2_request($origin, $route, 'POST', $tokens['public'], $body), 404, 'unsupported_capability');
    $preference->execute(['feature_flag.thumbnail_batch.enabled', '1']);
    file_put_contents($fixture->directory . '/.b2-env-zero', '0');
    $discovery = b2_request($origin, 'page=app_linking_discovery', 'GET');
    $discoveryData = json_decode($discovery['body'], true, B2_LIMITS['json_depth']);
    \GalleryWorkflow\check($discovery['status'] === 200
        && ($discoveryData['app_linking']['available'] ?? false) === true
        && in_array('media:thumbnail-batch-v1', $discoveryData['app_linking']['capabilities'] ?? [], true), 'Enabled discovery failed.');
    $method = b2_request($origin, $route, 'GET');
    b2_error($method, 405);
    \GalleryWorkflow\check(($method['headers']['allow'] ?? '') === 'POST', 'Allow header missing.');
    b2_error(b2_request($origin, $route, 'POST', $tokens['public'], '{}'), 400);
    b2_error(b2_request($origin, $route, 'POST', $tokens['public'], '{"v":1,"v":1,"gallery_id":"1","image_ids":["1"]}'), 400);
    b2_error(b2_request($origin, $route . '&unexpected=1', 'POST', $tokens['public'], $body), 400);
    b2_error(b2_request($origin, $route, 'POST', $tokens['public'], $body, 'text/plain'), 415);
    b2_error(b2_request($origin, $route, 'POST', null, $body), 401);
    b2_error(b2_request($origin, $route, 'POST', 'invalid', $body), 401);
    b2_error(b2_request($origin, $route, 'POST', $tokens['identity'], $body), 403);
    b2_error(b2_request($origin, $route, 'POST', $tokens['public'], b2_body($protectedId, [$ids[0]])), 404, 'target_unavailable');
    $other = $db->prepare('SELECT id FROM images WHERE gallery_id = ? ORDER BY id LIMIT 1');
    $other->execute([$protectedId]);
    $otherId = (string) $other->fetchColumn();
    \GalleryWorkflow\check($otherId !== '' && $otherId !== '0', 'Cross-gallery fixture missing.');
    b2_error(b2_request($origin, $route, 'POST', $tokens['public'], b2_body($galleryId, [$ids[0], $otherId])), 404, 'target_unavailable');
    b2_error(b2_request($origin, $route, 'POST', $tokens['public'], b2_body($galleryId, ['9223372036854775807'])), 404, 'target_unavailable');
    foreach ([1, 8, 24, 32] as $count) {
        b2_reset_rates($db);
        $selected = array_reverse(array_slice($ids, 0, $count));
        $response = b2_request($origin, $route, 'POST', $tokens['public'], b2_body($galleryId, $selected));
        \GalleryWorkflow\check($response['status'] === 200
            && ($response['headers']['content-type'] ?? '') === 'application/vnd.php-gallery.thumbnail-batch.v1'
            && ($response['headers']['cache-control'] ?? '') === 'private, no-store'
            && str_contains(strtolower($response['headers']['vary'] ?? ''), 'authorization')
            && ($response['headers']['x-content-type-options'] ?? '') === 'nosniff', 'Batch headers failed.');
        b2_parse($response['body'], $selected);
        $rates = b2_rate_totals($db);
        foreach (['app_link_photo_ip', 'app_link_photo_global', 'app_link_photo_account'] as $bucket) {
            \GalleryWorkflow\check(($rates[$bucket] ?? 0) === $count, 'Weighted shared rate charge failed.');
        }
    }
    $private = $db->prepare("UPDATE images SET visibility = 'private' WHERE id = ?");
    $private->execute([(int) $ids[0]]);
    b2_error(b2_request($origin, $route, 'POST', $tokens['public'], $body), 404, 'target_unavailable');
    $admin = b2_request($origin, $route, 'POST', $tokens['admin'], $body);
    $adminItems = b2_parse($admin['body'], [$ids[0]]);
    \GalleryWorkflow\check($admin['status'] === 200 && $adminItems[0]['metadata']['etag'] === null,
        'Restricted Admin validator failed.');
    $preload = b2_request($origin, $route, 'POST', $tokens['admin'], $body,
        'application/json', ['X-PHP-Gallery-Preload: admin-v1']);
    $preloadItems = b2_parse($preload['body'], [$ids[0]]);
    \GalleryWorkflow\check($preload['status'] === 200
        && $preloadItems[0]['metadata']['etag'] === '"' . hash('sha256', $preloadItems[0]['bytes']) . '"',
        'Independently allowed Admin preload validator failed.');
    $db->prepare("UPDATE images SET visibility = 'public' WHERE id = ?")->execute([(int) $ids[0]]);
    $legacyPublicReference = b2_request($origin,
        'page=app_linking_media&gallery_id=' . $galleryId
            . '&kind=thumbnail&image_id=' . $ids[1],
        'GET', $tokens['public']);
    $legacyPrivateReference = b2_request($origin,
        'page=app_linking_media&gallery_id=' . $galleryId
            . '&kind=thumbnail&image_id=' . $ids[0],
        'GET', $tokens['public']);
    \GalleryWorkflow\check($legacyPublicReference['status'] === 200
        && ($legacyPublicReference['headers']['content-type'] ?? '') === 'image/jpeg'
        && $legacyPrivateReference['status'] === 200
        && ($legacyPrivateReference['headers']['content-type'] ?? '') === 'image/jpeg',
        'Archived client reference response failed.');
    $legacyPublicSha256 = hash('sha256', $legacyPublicReference['body']);
    $legacyPrivateSha256 = hash('sha256', $legacyPrivateReference['body']);
    $db->prepare("UPDATE images SET visibility = 'private' WHERE id = ?")
        ->execute([(int) $ids[0]]);
    try {
        $legacyClient = b2_archived_legacy_client(
            $fixture, $origin, $tokens['public'], $tokens['admin'],
            $galleryId, $ids[1], $ids[0], $legacyPublicSha256,
            $legacyPrivateSha256,
        );
    } finally {
        $db->prepare("UPDATE images SET visibility = 'public' WHERE id = ?")
            ->execute([(int) $ids[0]]);
    }
    // Start the connected client from clean shared photo buckets. One single
    // profile and four batch profiles must total 600 IP/global charges; only
    // batch routes charge the account bucket, for 480 charges in total.
    b2_reset_rates($db);
    $clientEnvironment = [
        'PHP_GALLERY_B3_ISSUER' => $origin,
        'PHP_GALLERY_B3_BEARER' => $tokens['public'],
        'PHP_GALLERY_B3_GALLERY_ID' => (string) $galleryId,
    ];
    $previousClientEnvironment = [];
    foreach ($clientEnvironment as $name => $_value) {
        $previousClientEnvironment[$name] = getenv($name);
        if ($previousClientEnvironment[$name] !== false) {
            throw new RuntimeException('Connected client environment collision.');
        }
    }
    $auditDirectory = dirname(__DIR__, 2) . '/cache/test-audit';
    if (!is_dir($auditDirectory)) {
        mkdir($auditDirectory, 0700, true);
    }
    $clientArtifact = $auditDirectory . '/issue-362-b3-client.json';
    $partialArtifact = $auditDirectory . '/issue-362-b3-client-partial.json';
    try {
        foreach ($clientEnvironment as $name => $value) {
            putenv($name . '=' . $value);
        }
        try {
            $fixture->run(
                ['python3', __DIR__ . '/issue_362_b3_connected.py'],
                B3_CLIENT_TIMEOUT_SECONDS,
                'B3 connected thumbnail client',
            );
        } catch (Throwable $error) {
            $partialPath = $fixture->directory . '/issue-362-b3-client.json';
            if (is_file($partialPath)) {
                $partial = json_decode(
                    (string) file_get_contents($partialPath), true,
                    B2_LIMITS['json_depth'], JSON_THROW_ON_ERROR,
                );
                if (is_array($partial)
                    && ($partial['schema'] ?? null) === 'php-gallery-b3-client-v1') {
                    file_put_contents(
                        $partialArtifact,
                        json_encode($partial, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
                    );
                }
            }
            throw $error;
        }
        $clientText = file_get_contents(
            $fixture->directory . '/issue-362-b3-client.json',
        );
        \GalleryWorkflow\check(is_string($clientText), 'Connected client report missing.');
        $client = json_decode(
            $clientText, true, B2_LIMITS['json_depth'], JSON_THROW_ON_ERROR,
        );
        \GalleryWorkflow\check(is_array($client)
            && ($client['schema'] ?? null) === 'php-gallery-b3-client-v1'
            && ($client['assertions'] ?? null) === 'passed'
            && ($client['profile_count'] ?? null) === 1 + count(B2_GROUP_SIZES)
            && ($client['image_count'] ?? null) === B2_LIMITS['images']
            && ($client['equal_sha_mime_items_per_profile'] ?? null) === B2_LIMITS['images']
            && ($client['measurement']['cold_definition'] ?? null)
                === 'empty isolated client disk cache; discovery, identity and media-page proof binding excluded'
            && ($client['measurement']['server_cache_state'] ?? null)
                === 'not controlled or reset'
            && ($client['measurement']['server_timing_scope'] ?? null)
                === 'strict parsed response-header snapshot before body stream; not total PHP runtime or worker occupancy'
            && is_array($client['profiles'] ?? null)
            && count($client['profiles']) === 1 + count(B2_GROUP_SIZES),
            'Connected client report contract failed.');
        $expectedProfiles = ['single' => null];
        foreach (B2_GROUP_SIZES as $groupSize) {
            $expectedProfiles['batch_' . $groupSize] = $groupSize;
        }
        $seenProfiles = [];
        foreach ($client['profiles'] as $profile) {
            \GalleryWorkflow\check(is_array($profile)
                && is_string($profile['name'] ?? null)
                && array_key_exists($profile['name'], $expectedProfiles)
                && !isset($seenProfiles[$profile['name']]),
                'Connected client profile identity failed.');
            $name = $profile['name'];
            $groupSize = $expectedProfiles[$name];
            $expectedRequests = $groupSize === null
                ? B2_LIMITS['images']
                : intdiv(B2_LIMITS['images'] + $groupSize - 1, $groupSize);
            $expectedHistogram = $groupSize === null
                ? ['1' => B2_LIMITS['images']]
                : [(string) $groupSize => intdiv(B2_LIMITS['images'], $groupSize)];
            if ($groupSize !== null
                && B2_LIMITS['images'] % $groupSize !== 0) {
                $expectedHistogram[(string) (B2_LIMITS['images'] % $groupSize)] = 1;
            }
            $cold = $profile['cold'] ?? null;
            $warm = $profile['warm'] ?? null;
            $proofBinding = $profile['proof_binding'] ?? null;
            \GalleryWorkflow\check($profile['group_size'] === $groupSize
                && $profile['items'] === B2_LIMITS['images']
                && is_array($proofBinding)
                && is_int($proofBinding['metadata_pages'] ?? null)
                && $proofBinding['metadata_pages'] > 0
                && is_int($proofBinding['metadata_rows_observed'] ?? null)
                && $proofBinding['metadata_rows_observed'] >= B2_LIMITS['images']
                && ($proofBinding['selected_count'] ?? null) === B2_LIMITS['images']
                && is_array($cold) && is_array($warm)
                && $cold['http_requests'] === $expectedRequests
                && $cold['request_size_histogram'] === $expectedHistogram
                && $cold['response_bytes'] > 0
                && $cold['cache_writes'] === B2_LIMITS['images']
                && $cold['cache_written_bytes'] > 0
                && $warm['http_requests'] === 0
                && $warm['request_size_histogram'] === []
                && $warm['response_bytes'] === 0
                && $warm['cache_hits'] === B2_LIMITS['images']
                && $warm['cache_writes'] === 0
                && $warm['cache_written_bytes'] === 0
                && $warm['equal_items'] === B2_LIMITS['images']
                && ($groupSize === null
                    ? $cold['first_wire_frame_ms'] === null
                    : is_numeric($cold['first_wire_frame_ms'])),
                'Connected client cohort contract failed.');
            foreach ([$cold, $warm] as $phase) {
                foreach ([
                    'network_request_ms', 'ttfb_ms', 'body_ms', 'operation_ms',
                ] as $distributionName) {
                    $distribution = $phase[$distributionName] ?? null;
                    \GalleryWorkflow\check(is_array($distribution)
                        && is_int($distribution['count'] ?? null)
                        && is_array($distribution['samples_ms'] ?? null)
                        && array_is_list($distribution['samples_ms'])
                        && count($distribution['samples_ms']) === $distribution['count'],
                        'Connected client sample count failed.');
                    foreach ($distribution['samples_ms'] as $sample) {
                        \GalleryWorkflow\check(is_float($sample)
                            && is_finite($sample) && $sample >= 0.0,
                            'Connected client duration sample failed.');
                    }
                }
                $timingSnapshots = $phase['server_timing_header_snapshot'] ?? null;
                \GalleryWorkflow\check(is_array($timingSnapshots)
                    && array_is_list($timingSnapshots)
                    && count($timingSnapshots) === $phase['http_requests'],
                    'Connected client server timing samples failed.');
                foreach ($timingSnapshots as $timingSnapshot) {
                    \GalleryWorkflow\check($timingSnapshot === null
                        || (is_array($timingSnapshot)
                            && array_keys($timingSnapshot) === [
                                'php_ms', 'sql_ms', 'sql_count', 'sql_max_ms',
                                'sql_failed', 'sql_capped', 'auth_ms', 'tree_ms',
                                'hash_ms', 'hash_bytes', 'media_ms',
                            ]
                            && is_float($timingSnapshot['php_ms'] ?? null)
                            && is_float($timingSnapshot['sql_ms'] ?? null)
                            && is_int($timingSnapshot['sql_count'] ?? null)
                            && is_float($timingSnapshot['sql_max_ms'] ?? null)
                            && is_int($timingSnapshot['sql_failed'] ?? null)
                            && is_bool($timingSnapshot['sql_capped'] ?? null)
                            && is_float($timingSnapshot['auth_ms'] ?? null)
                            && is_float($timingSnapshot['tree_ms'] ?? null)
                            && is_float($timingSnapshot['hash_ms'] ?? null)
                            && is_int($timingSnapshot['hash_bytes'] ?? null)
                            && is_float($timingSnapshot['media_ms'] ?? null)),
                        'Connected client Server-Timing field failed.');
                }
            }
            $seenProfiles[$name] = true;
        }
        \GalleryWorkflow\check(count($seenProfiles) === count($expectedProfiles),
            'Connected client cohort set failed.');
        file_put_contents(
            $clientArtifact,
            json_encode($client, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
        );
    } finally {
        foreach ($previousClientEnvironment as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }
    }
    $clientRates = b2_rate_totals($db);
    $expectedSharedCharges = B2_LIMITS['images'] * (count(B2_GROUP_SIZES) + 1);
    $expectedAccountCharges = B2_LIMITS['images'] * count(B2_GROUP_SIZES);
    \GalleryWorkflow\check(
        ($clientRates['app_link_photo_ip'] ?? 0) === $expectedSharedCharges
        && ($clientRates['app_link_photo_global'] ?? 0) === $expectedSharedCharges
        && ($clientRates['app_link_photo_account'] ?? 0) === $expectedAccountCharges,
        'Connected client shared-rate deltas failed.',
    );
    // The reciprocal fixture owns a separate database and has no bearing on
    // the already asserted current-server B3 rate deltas.
    $legacyServer = d_old_server_compatibility($fixture);
    // Restore the fixture's established refusal/revocation sequence budget.
    b2_reset_rates($db);
    $response = b2_request($origin, $route, 'POST', $tokens['public'], $body);
    $batchItem = b2_parse($response['body'], [$ids[0]])[0];
    $single = b2_request($origin, 'page=app_linking_media&gallery_id=' . $galleryId
        . '&kind=thumbnail&image_id=' . $ids[0], 'GET', $tokens['public']);
    \GalleryWorkflow\check($single['status'] === 200 && $single['body'] === $batchItem['bytes'],
        'Single thumbnail bytes differ from batch.');
    $db->prepare("UPDATE viewer_rate_limits SET attempts = ? WHERE bucket = 'app_link_photo_ip'")
        ->execute([B2_LIMITS['ip_attempts']]);
    $limited = b2_request($origin, $route, 'POST', $tokens['public'], $body);
    b2_error($limited, 429);
    \GalleryWorkflow\check((int) ($limited['headers']['retry-after'] ?? 0) > 0, 'Rate retry header missing.');
    $timings = [];
    b2_reset_rates($db);
    $total = 0.0;
    $singleHashes = [];
    foreach ($ids as $id) {
        $singleResponse = b2_request($origin, 'page=app_linking_media&gallery_id=' . $galleryId
            . '&kind=thumbnail&image_id=' . $id, 'GET', $tokens['public']);
        \GalleryWorkflow\check($singleResponse['status'] === 200, 'Sequential thumbnail read failed.');
        $singleHashes[$id] = hash('sha256', $singleResponse['body']);
        $total += $singleResponse['elapsed_ms'];
    }
    // Three decimal places preserve millisecond samples without noisy precision.
    $timings['single_120'] = round($total, 3);
    foreach (B2_GROUP_SIZES as $groupSize) {
        b2_reset_rates($db);
        $total = 0.0;
        foreach (array_chunk($ids, $groupSize) as $group) {
            $groupResponse = b2_request($origin, $route, 'POST', $tokens['public'], b2_body($galleryId, $group));
            \GalleryWorkflow\check($groupResponse['status'] === 200, 'Grouped thumbnail read failed.');
            $groupItems = b2_parse($groupResponse['body'], $group);
            foreach ($groupItems as $index => $item) {
                \GalleryWorkflow\check(hash_equals($singleHashes[$group[$index]],
                    hash('sha256', $item['bytes'])), 'Grouped bytes differ from single reads.');
            }
            $total += $groupResponse['elapsed_ms'];
        }
        $timings['group_' . $groupSize . '_120'] = round($total, 3);
    }
    b2_reset_rates($db);
    $db->prepare('UPDATE app_link_grants SET revoked_at = UTC_TIMESTAMP(6) WHERE access_token_hash = ?')->execute([
        \Gallery\Services\app_linking_access_token_hash($tokens['public']),
    ]);
    b2_error(b2_request($origin, $route, 'POST', $tokens['public'], $body), 401);
    // The real users.role enum only supports admin; remove this owned account
    // to prove loss of account authority without weakening its schema.
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    b2_error(b2_request($origin, $route, 'POST', $tokens['admin'], $body), 401);
    return ['schema' => 'php-gallery-b2-http-v1',
        'head_sha' => (string) (getenv('GITHUB_SHA') ?: 'UNKNOWN'),
        'assertions' => 'passed', 'timings_ms' => $timings,
        'legacy_client' => $legacyClient, 'legacy_server' => $legacyServer,
        'client' => $client,
        'scope' => 'owned loopback PHP CLI and disposable MariaDB; no FPM, HTTPS termination, proxy or production acceptance; legacy PHP cohorts are single observations, connected-client p95/p99 require 120 request samples; client ready times are not GUI paint'];
}

$fixture = null;
$failed = false;
try {
    if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('GALLERY_WORKFLOW_ENABLE') !== 'disposable-only') {
        throw new RuntimeException('Owned disposable CI is required.');
    }
    $root = dirname(__DIR__, 2);
    $fixture = new \GalleryWorkflow\Fixture($root, getenv());
    $report = b2_run($fixture);
    $target = $root . '/cache/test-audit/issue-362-b2-http.json';
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0700, true);
    }
    file_put_contents($target, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    echo "PASS B2 isolated HTTP qualification\n";
} catch (Throwable $error) {
    // Never expose an exception message: PDO and HTTP failures can contain
    // fixture credentials. Only bounded PHP filenames and line numbers are safe.
    $locations = [['file' => $error->getFile(), 'line' => $error->getLine()], ...$error->getTrace()];
    $printed = 0;
    fwrite(STDERR, "FAIL B2 isolated HTTP qualification\n");
    foreach ($locations as $location) {
        $file = basename((string) ($location['file'] ?? ''));
        $line = $location['line'] ?? null;
        if (preg_match('/\A[a-zA-Z0-9_.-]+\.php\z/D', $file) === 1
            && is_int($line) && $line > 0) {
            fwrite(STDERR, '  at ' . $file . ':' . $line . "\n");
            if (++$printed >= 3) {
                break;
            }
        }
    }
    $failed = true;
} finally {
    if ($fixture instanceof \GalleryWorkflow\Fixture) {
        try {
            $fixture->close();
        } catch (Throwable) {
            fwrite(STDERR, "FAIL B2 fixture cleanup\n");
            $failed = true;
        }
    }
}
if ($failed) {
    exit(1);
}
