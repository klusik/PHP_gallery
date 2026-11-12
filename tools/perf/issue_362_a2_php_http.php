<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tools/perf/issue_362_a2_php_http.php
 * Module Type: Disposable Hosted HTTP Regression
 * Purpose: Verify A2 PHP profiling on real owned HTTP and MariaDB routes.
 * Responsibilities:
 *   - Refuse invocation outside an explicit disposable GitHub Actions runner.
 *   - Exercise live authorized tree, media page and thumbnail/304 protocols.
 *   - Assert anonymous requests never obtain passive timing headers.
 *   - Export fixed numeric statuses only; never credentials, SQL or bodies.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tests/support/gallery_workflow_fixture.php';
require_once dirname(__DIR__, 2) . '/app/services/security_tokens.php';
require_once dirname(__DIR__, 2) . '/app/services/app_linking_policy.php';

/**
 * Bound a disposable HTTP request without hitting production.
 * Type: int. Units: seconds. Scope: owned loopback cURL.
 * Consumers: a2_profile_request. Rationale: Stop stalled fixture transfers.
 */
const A2_PROFILE_HTTP_TIMEOUT = 12;

/**
 * Reject a fixed fixture failure without displaying any request data.
 *
 * @param bool $valid Expected condition for the synthetic regression.
 * @param string $case Static test identifier, never remote content.
 * @return void Throws an opaque failure if the assertion fails.
 */
function a2_profile_expect(bool $valid, string $case): void
{
    if (!$valid) {
        throw new RuntimeException('A2 owned PHP condition failed: ' . $case);
    }
}

/**
 * Send one canonical synthetic bearer read and collect bounded response facts.
 *
 * @param string $origin The verified temporary localhost issuer.
 * @param string $query Canonical synthetic query string with strict key names.
 * @param string $bearer Ephemeral fixture token, never logged or exported.
 * @param string|null $validator Prior exact synthetic media ETag for 304.
 * @return array{status:int,body:string,timing:?string,etag:?string}
 *     Test-only bounded body and header values; exported report is sanitized.
 */
function a2_profile_request(
    string $origin, string $query, string $bearer,
    ?string $validator = null,
): array {
    a2_profile_expect(
        str_starts_with($origin, 'http://localhost:')
        && preg_match('/\Apage=app_linking_[a-z_]+(?:&[a-z_]+=[a-z0-9]+)*\z/D', $query) === 1,
        'canonical_owned_query',
    );
    $headers = [
        'Authorization: Bearer ' . $bearer,
        'Accept: application/json, image/webp, image/jpeg, image/png, image/gif',
    ];
    if ($validator !== null) {
        a2_profile_expect(
            preg_match('/\A"[a-f0-9]{64}"\z/D', $validator) === 1,
            'canonical_etag',
        );
        $headers[] = 'If-None-Match: ' . $validator;
    }
    $timing = null;
    $etag = null;
    $curl = curl_init($origin . '/index.php?' . $query);
    a2_profile_expect($curl !== false, 'curl_handle');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => A2_PROFILE_HTTP_TIMEOUT,
        CURLOPT_PROXY => '',
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (
            &$timing, &$etag,
        ): int {
            if (strncasecmp($line, 'Server-Timing:', 14) === 0) {
                $timing = trim(substr($line, 14));
            } elseif (strncasecmp($line, 'ETag:', 5) === 0) {
                $etag = trim(substr($line, 5));
            }
            return strlen($line);
        },
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    unset($curl);
    a2_profile_expect(is_string($body), 'complete_response');
    return ['status' => $status, 'body' => $body,
        'timing' => $timing, 'etag' => $etag];
}

/**
 * Verify a fixed, private-free Server-Timing contract on an owned read.
 *
 * @param array{status:int,body:string,timing:?string,etag:?string} $response
 *     Already received fixture result, never an exported application record.
 * @param string $case Fixed scenario label.
 * @return void Refuse missing SQL counts, malformed or sensitive metadata.
 */
function a2_profile_timing_check(array $response, string $case): void
{
    $value = $response['timing'];
    a2_profile_expect(
        is_string($value)
        && strlen($value) < 512
        && preg_match('/\Agallery-php;dur=[0-9.]+/', $value) === 1
        && preg_match('/gallery-sql;dur=[0-9.]+;desc="n=[1-9][0-9]*;max_ms=[0-9.]+;failed=[0-9]+;cap=0"/', $value) === 1
        && str_contains($value, 'gallery-tree;dur=')
        && str_contains($value, 'gallery-hash;dur=')
        && !str_contains($value, 'SELECT')
        && !str_contains($value, 'Seed')
        && !str_contains($value, 'Protected')
        && !str_contains($value, 'pgla_'),
        $case,
    );
}

/**
 * Exercise the real application routes inside the owned MariaDB and HTTP copy.
 *
 * @param GalleryWorkflow\Fixture $fixture Verified disposable application owner.
 * @return array<string,int|bool> Safe numeric status/boolean assertions only.
 */
function a2_profile_verify_http(GalleryWorkflow\Fixture $fixture): array
{
    $fixture->start();
    // PHP CLI-server omits SERVER_ADDR. Patch only this verified router copy,
    // exactly as the previously accepted A1 disposable HTTP benchmark does.
    $routerPath = $fixture->directory . '/router.php';
    $router = (string) file_get_contents($routerPath);
    $entry = "require __DIR__ . '/public/index.php';";
    a2_profile_expect(substr_count($router, $entry) === 1, 'owned_router');
    $metadata = <<<'PHP'
if (PHP_SAPI === 'cli-server'
    && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
    && !isset($_SERVER['SERVER_ADDR'])) {
    $_SERVER['SERVER_ADDR'] = '127.0.0.1';
}
PHP;
    file_put_contents(
        $routerPath, str_replace($entry, $metadata . "\n" . $entry, $router),
    );
    a2_profile_expect(
        str_starts_with($fixture->url, 'http://127.0.0.1:'),
        'owned_listener',
    );
    $origin = 'http://localhost:' . substr(
        $fixture->url, strlen('http://127.0.0.1:'),
    );
    $configFile = $fixture->directory . '/config.php';
    $config = require $configFile;
    a2_profile_expect(is_array($config), 'owned_configuration');
    $config['base_url'] = $origin;
    file_put_contents(
        $configFile, "<?php\nreturn " . var_export($config, true) . ";\n",
    );

    $options = $fixture->environment();
    $pdo = new PDO(
        $options['GALLERY_TEST_MYSQL_DSN'],
        $options['GALLERY_TEST_MYSQL_USER'],
        $options['GALLERY_TEST_MYSQL_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $userId = (int) $pdo->query(
        "SELECT id FROM users WHERE username = 'workflow_admin'",
    )->fetchColumn();
    $seed = json_decode(
        (string) file_get_contents($fixture->directory . '/seed.json'),
        true, flags: JSON_THROW_ON_ERROR,
    );
    $galleryId = (int) ($seed['root_id'] ?? 0);
    a2_profile_expect($userId > 0 && $galleryId > 0, 'synthetic_gallery');
    $token = \Gallery\Services\app_linking_access_token_generate();
    $hash = \Gallery\Services\app_linking_access_token_hash($token);
    a2_profile_expect(is_string($hash), 'synthetic_bearer');
    $grant = $pdo->prepare(
        'INSERT INTO app_link_grants (user_id, client_id, issuer, scope, '
        . 'created_at, access_token_hash, access_issued_at, access_expires_at) '
        . 'VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), '
        . 'DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 2400 SECOND))',
    );
    $grant->execute([
        $userId, 'php-gallery-winapp', $origin,
        'link:identity galleries:list media:read', $hash,
    ]);
    $anonymous = a2_profile_request(
        $origin, 'page=app_linking_galleries&tree=1',
        'pgla_' . str_repeat('A', 43),
    );
    a2_profile_expect(
        $anonymous['status'] === 401 && $anonymous['timing'] === null,
        'anonymous_no_timing',
    );

    $full = a2_profile_request(
        $origin, 'page=app_linking_galleries&tree=1', $token,
    );
    a2_profile_expect($full['status'] === 200, 'authorized_tree_full');
    $tree = json_decode($full['body'], true, flags: JSON_THROW_ON_ERROR);
    a2_profile_expect(
        ($tree['unchanged'] ?? null) === false
        && count($tree['galleries'] ?? []) >= 2
        && preg_match('/\A[a-f0-9]{64}\z/D', (string) ($tree['revision'] ?? '')) === 1,
        'fresh_tree_shape',
    );
    a2_profile_timing_check($full, 'full_tree_timing');
    $unchanged = a2_profile_request(
        $origin,
        'page=app_linking_galleries&tree=1&revision=' . $tree['revision'],
        $token,
    );
    a2_profile_expect($unchanged['status'] === 200, 'unchanged_200');
    $confirmed = json_decode(
        $unchanged['body'], true, flags: JSON_THROW_ON_ERROR,
    );
    a2_profile_expect(
        ($confirmed['unchanged'] ?? null) === true
        && !isset($confirmed['galleries']),
        'unchanged_shape',
    );
    a2_profile_timing_check($unchanged, 'unchanged_tree_timing');

    $page = a2_profile_request(
        $origin, 'page=app_linking_galleries', $token,
    );
    a2_profile_expect($page['status'] === 200, 'paged_200');
    a2_profile_timing_check($page, 'paged_timing');
    $media = a2_profile_request(
        $origin, 'page=app_linking_media&gallery_id=' . $galleryId
        . '&kind=photos', $token,
    );
    a2_profile_expect($media['status'] === 200, 'media_page_200');
    a2_profile_timing_check($media, 'media_page_timing');
    // media:read alone never grants the stricter Admin-only revision route.
    $denied = a2_profile_request(
        $origin, 'page=app_linking_media&gallery_id=' . $galleryId
        . '&kind=revision', $token,
    );
    a2_profile_expect(
        $denied['status'] === 404 && $denied['timing'] === null,
        'admin_only_denial_has_no_timing',
    );

    $imageId = (int) $pdo->query(
        'SELECT id FROM images WHERE gallery_id = ' . $galleryId
        . ' ORDER BY id LIMIT 1',
    )->fetchColumn();
    a2_profile_expect($imageId > 0, 'synthetic_photo');
    $photoRoute = 'page=app_linking_media&gallery_id=' . $galleryId
        . '&kind=thumbnail&image_id=' . $imageId;
    $photo = a2_profile_request($origin, $photoRoute, $token);
    a2_profile_expect(
        $photo['status'] === 200
        && is_string($photo['etag'])
        && preg_match('/\A"[a-f0-9]{64}"\z/D', $photo['etag']) === 1,
        'thumbnail_200_with_etag',
    );
    a2_profile_timing_check($photo, 'thumbnail_timing');
    a2_profile_expect(
        preg_match('/gallery-hash;dur=[0-9.]+;desc="bytes=[1-9][0-9]*"/',
            (string) $photo['timing']) === 1,
        'measured_hash_bytes',
    );
    $notModified = a2_profile_request(
        $origin, $photoRoute, $token, $photo['etag'],
    );
    a2_profile_expect(
        $notModified['status'] === 304 && $notModified['body'] === '',
        'conditional_304',
    );
    a2_profile_timing_check($notModified, 'conditional_timing');
    return [
        'anonymous_401_without_header' => true,
        'admin_media_404_without_header' => true,
        'tree_full' => $full['status'],
        'tree_unchanged' => $unchanged['status'],
        'gallery_page' => $page['status'],
        'media_page' => $media['status'],
        'thumbnail' => $photo['status'],
        'thumbnail_conditional' => $notModified['status'],
        'nonzero_actual_hash_bytes' => true,
    ];
}

$fixture = null;
$root = dirname(__DIR__, 2);
try {
    if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('GALLERY_WORKFLOW_ENABLE') !== 'disposable-only'
        || getenv('GALLERY_WORKFLOW_DB_HOST') !== '127.0.0.1'
        || getenv('GALLERY_WORKFLOW_DB_PORT') !== '13316') {
        throw new RuntimeException('A2 disposable GitHub-only runner required.');
    }
    $password = bin2hex(random_bytes(24));
    putenv('GALLERY_WORKFLOW_DB_PASSWORD=' . $password);
    $settings = \GalleryWorkflow\databaseOptions(getenv());
    $bootstrapPassword = (string) getenv('GALLERY_WORKFLOW_CI_ROOT_PASSWORD');
    a2_profile_expect($bootstrapPassword !== '', 'ci_root_credential');
    $admin = new PDO(
        'mysql:host=127.0.0.1;port=' . $settings['port'],
        'root', $bootstrapPassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    a2_profile_expect(
        $admin->query('SELECT @@hostname')->fetchColumn()
            === 'gallery-workflow-ci',
        'known_ci_mariadb',
    );
    $admin->exec(
        "CREATE USER 'gallery_workflow_runner'@'%' IDENTIFIED BY "
        . $admin->quote($password),
    );
    $schema = chr(96) . 'gallery\_workflow\_%' . chr(96);
    $admin->exec(
        'GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, '
        . 'CREATE TEMPORARY TABLES, ALTER, DROP, INDEX, REFERENCES ON '
        . $schema . ".* TO 'gallery_workflow_runner'@'%'",
    );
    $admin = null;
    $bootstrapPassword = '';
    putenv('GALLERY_WORKFLOW_CI_ROOT_PASSWORD');
    putenv('PHP_GALLERY_A2_PASSIVE_PROFILE=1');
    $fixture = new \GalleryWorkflow\Fixture($root, getenv());
    $results = a2_profile_verify_http($fixture);
    $report = [
        'schema' => 'issue-362-a2-php-passive-v1',
        'sha' => (string) (getenv('GITHUB_SHA') ?: 'unknown'),
        'at_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'scope' => 'isolated cloned application and MariaDB on GitHub loopback',
        'results' => $results,
        'limits' => [
            'No WEDOS proxy or hosting worker capacity observed.',
            'Header timing ends before binary streaming or response completion.',
            'Peak PHP memory is process-local, not a host occupancy metric.',
            'No tokens, URLs, SQL text, galleries or image bytes are exported.',
        ],
    ];
    $path = $root . '/cache/test-audit/issue-362-a2-php.json';
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents(
        $path, json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
    );
    echo "PASS gallery workflow A2 isolated PHP and MariaDB profiler\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL gallery workflow A2 at '
        . basename($error->getFile()) . ':' . $error->getLine() . "\n");
    exit(1);
} finally {
    if ($fixture instanceof \GalleryWorkflow\Fixture) {
        $fixture->close();
    }
}
