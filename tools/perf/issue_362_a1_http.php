<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tools/perf/issue_362_a1_http.php
 * Module Type: Owned HTTP Benchmark
 * Purpose: Measure actual bearer-authorized gallery/media reads on disposable CI.
 * Responsibilities:
 *   - Reuse the project's isolated application and MariaDB fixture.
 *   - Exercise real PHP routes with bounded cold/warm media and tree samples.
 *   - Export non-secret individual request and PHP loader timing observations.
 *   - Keep the staging/production performance boundary explicit.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/tests/support/gallery_workflow_fixture.php';
require_once dirname(__DIR__, 2) . '/app/services/security_tokens.php';
require_once dirname(__DIR__, 2) . '/app/services/app_linking_policy.php';

/**
 * Bound synthetic tree sizes to the documented tree-v1 row contract.
 * Type: list<int>. Units: gallery rows. Scope: issue #362 A1.
 * Consumers: a1_http_run. Rationale: cover 500, 2000 and 10000 galleries.
 */
const A1_HTTP_TREE_SIZES = [500, 2000, 10000];

/**
 * Cross the existing maximum tree row count by exactly one fixture row.
 * Type: int. Units: rows. Scope: A1 over-limit compatibility check.
 * Consumers: a1_http_run. Rationale: prove bounded 503 and paged fallback.
 */
const A1_HTTP_TREE_OVERFLOW_ROWS = 10001;

/**
 * Bound the number of HTTP observations for one tree outcome.
 * Type: int. Units: samples. Scope: A1 per-tree experiments.
 * Consumers: a1_http_run. Rationale: three samples capture basic variability.
 */
const A1_HTTP_TRIALS = 3;

/**
 * Fix the minimum distinct media corpus required for A1 cold reads.
 * Type: int. Units: images. Scope: A1 native-media experiment.
 * Consumers: a1_http_run. Rationale: 120 distinct photos match issue #362.
 */
const A1_HTTP_MIN_IMAGES = 120;

/**
 * Protect a single isolated HTTP request from a stalled fixture.
 * Type: int. Units: seconds. Scope: A1 curl reads.
 * Consumers: a1_http_request. Rationale: stay within the native deadline.
 */
const A1_HTTP_TIMEOUT_SECONDS = 12;

/**
 * Protect one isolated seeded fixture child from an unbounded scan.
 * Type: int. Units: seconds. Scope: A1 local seed.
 * Consumers: a1_http_run. Rationale: 120 tiny originals are finite.
 */
const A1_HTTP_SEED_TIMEOUT_SECONDS = 120;

/**
 * Bound one real WinApp Python child executing against the owned fixture.
 * Type: int. Units: seconds. Scope: isolated client sample.
 * Consumers: a1_http_client_run. Rationale: 240 accommodates 120 serial
 * thumbnail reads and cache verification without an unbounded process.
 */
const A1_HTTP_CLIENT_TIMEOUT_SECONDS = 240;

/**
 * Bound the complete paced 201-page client with genuine rate-limit waits.
 * Type: int. Units: seconds. Scope: one A1 overflow child process.
 * Consumers: a1_http_client_run. Rationale: 1800 admits the 16-minute
 * cadence and one 300-second rate-limit recovery without unlimited loops.
 */
const A1_HTTP_OVERFLOW_TIMEOUT_SECONDS = 1800;

/**
 * Fetch one authorized isolated route without recording secrets or raw URLs.
 *
 * @param string $origin Exact localhost origin of the owned application copy.
 * @param string $query Canonical allowlisted route and synthetic selectors.
 * @param string $bearer Synthetic bearer from this disposable fixture only.
 * @param list<string> $additionalHeaders Optional current-page validators.
 * @return array{body:string,status:int,ttfb_ms:float,elapsed_ms:float,bytes:int}
 *     Complete response data and individually measured client-visible phases.
 */
function a1_http_request(
    string $origin,
    string $query,
    string $bearer,
    array $additionalHeaders = [],
): array {
    if (!str_starts_with($query, 'page=app_linking_')
        || preg_match('/\A[a-zA-Z0-9_=&]+\z/D', $query) !== 1) {
        throw new RuntimeException('Noncanonical benchmark route.');
    }
    $handle = curl_init($origin . '/index.php?' . $query);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize isolated HTTP request.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => A1_HTTP_TIMEOUT_SECONDS,
        CURLOPT_PROXY => '',
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $bearer,
            'Accept: application/json, image/webp, image/jpeg, image/png, image/gif',
            ...$additionalHeaders,
        ],
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $ttfb = (float) curl_getinfo($handle, CURLINFO_STARTTRANSFER_TIME);
    $elapsed = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME);
    unset($handle);
    if (!is_string($body)) {
        throw new RuntimeException('Isolated HTTP transfer failed.');
    }
    return [
        'body' => $body, 'status' => $status,
        'ttfb_ms' => round($ttfb * 1000, 6),
        'elapsed_ms' => round($elapsed * 1000, 6),
        'bytes' => strlen($body),
    ];
}

/**
 * Record a single sanitized request observation without resource identifiers.
 *
 * @param list<array<string,int|float|string>> $records Existing observations.
 * @param string $route A short fixed logical route category.
 * @param int $trial Index in its explicitly bounded measurement case.
 * @param array{body:string,status:int,ttfb_ms:float,elapsed_ms:float,bytes:int} $response Verified HTTP result.
 * @param int $rows Current synthetic tree size or zero for media.
 * @return void Adds numeric metadata without URLs, tokens or photo identities.
 */
function a1_http_observe(
    array &$records, string $route, int $trial,
    array $response, int $rows = 0,
): void {
    $records[] = [
        'route' => $route,
        'trial' => $trial,
        'rows' => $rows,
        'status' => $response['status'],
        'ttfb_ms' => $response['ttfb_ms'],
        'elapsed_ms' => $response['elapsed_ms'],
        'body_bytes' => $response['bytes'],
    ];
}

/**
 * Add only deterministic physical gallery names to an owned MariaDB database.
 *
 * @param PDO $database Connection to the isolated and verified fixture DB.
 * @param int $alreadyAdded Synthetic galleries already generated by this call.
 * @param int $target Desired total number of synthetic and seed gallery rows.
 * @param int $seedRows Count of previously generated seed gallery rows.
 * @return int New cumulative synthetic row count after bounded inserts.
 */
function a1_http_seed_tree(
    PDO $database, int $alreadyAdded, int $target, int $seedRows,
): int {
    $statement = $database->prepare(
        'INSERT INTO galleries (folder_path, folder_path_hash, slug, title, '
        . 'visibility, created_at, updated_at) '
        . "VALUES (?, ?, ?, ?, 'private', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
    );
    $needed = max(0, $target - $seedRows);
    for ($index = $alreadyAdded; $index < $needed; ++$index) {
        $id = sprintf('issue_362_a1_%05d', $index);
        $statement->execute([
            $id, hash('sha256', $id), $id, 'A1 Synthetic ' . $index,
        ]);
    }
    return $needed;
}

/**
 * Run one actual WinApp client scenario inside the owned disposable fixture.
 *
 * @param GalleryWorkflow\Fixture $fixture Already verified temporary PHP/MariaDB owner.
 * @param string $origin Confined localhost origin of the copied PHP application.
 * @param string $token Ephemeral access token, passed privately and never exported.
 * @param string $scenario Fixed tree, overflow, media, fault or fairness case.
 * @param int $selector Tree row count or approved physical seed-gallery identity.
 * @param string|null $watcherToken Optional isolated upload-scope bearer for T8.
 * @return void The child persists only redacted metrics and must exit successfully.
 */
function a1_http_client_run(
    GalleryWorkflow\Fixture $fixture,
    string $origin,
    string $token,
    string $scenario,
    int $selector,
    ?string $watcherToken = null,
): void {
    if (getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('GALLERY_WORKFLOW_ENABLE') !== 'disposable-only'
        || !str_starts_with($origin, 'http://localhost:')
        || !in_array($scenario, ['tree', 'overflow', 'media', 'fault', 'fairness', 'gui', 'mutate'], true)
        || ($scenario === 'fairness' && $watcherToken === null)) {
        throw new RuntimeException('Connected A1 requires an owned CI fixture.');
    }
    // Never provide the synthetic bearer to the Actions shell or artifacts.
    // The fixture launches the child directly and owns its private log.
    putenv('A1_CLIENT_ISSUER=' . $origin);
    putenv('A1_CLIENT_BEARER=' . $token);
    if ($scenario === 'mutate') {
        putenv('A1_CLIENT_FIXTURE_NONCE=' . $fixture->token);
    }
    if ($watcherToken !== null) {
        putenv('A1_WATCHER_BEARER=' . $watcherToken);
    }
    try {
        $fixture->run(
            $scenario === 'gui'
                ? [
                    'xvfb-run', '-a', 'python',
                    __DIR__ . '/issue_362_a1_connected.py',
                    $scenario, (string) $selector,
                ] : [
                    'python', __DIR__ . '/issue_362_a1_connected.py',
                    $scenario, (string) $selector,
                ],
            $scenario === 'overflow'
                ? A1_HTTP_OVERFLOW_TIMEOUT_SECONDS : A1_HTTP_CLIENT_TIMEOUT_SECONDS,
            'A1 real WinApp client and PHP transport',
            true,
        );
    } finally {
        putenv('A1_CLIENT_ISSUER');
        putenv('A1_CLIENT_BEARER');
        putenv('A1_CLIENT_FIXTURE_NONCE');
        putenv('A1_WATCHER_BEARER');
    }
}

/**
 * Add per-stage timing exclusively to the owned server copy of tree-v1.
 *
 * SQL SELECT count comes from the live MariaDB session status; the measured
 * model interval includes non-SQL PHP work and is not mislabeled SQL time.
 * No production source, public route or persisted credential is altered.
 *
 * @param GalleryWorkflow\Fixture $fixture Exact disposable installation.
 * @return void The private trace is emitted only by the HTTP server copy.
 */
function a1_http_instrument_tree_phases(GalleryWorkflow\Fixture $fixture): void
{
    $root = \GalleryWorkflow\validateFixture(
        $fixture->directory, $fixture->token,
    );
    $path = $root . '/app/services/app_linking.php';
    $source = file_get_contents($path);
    if (!is_string($source)) {
        throw new RuntimeException('Owned tree source is unreadable.');
    }
    $begin = strpos($source, 'function app_linking_gallery_tree(');
    if ($begin === false) {
        throw new RuntimeException('Owned tree function is unavailable.');
    }
    $end = strpos($source, "\n}\n\n/**", $begin);
    if ($end === false || $end <= $begin) {
        throw new RuntimeException('Unexpected owned tree function structure.');
    }
    $function = substr($source, $begin, $end - $begin + 2);
    $replace = static function (
        string $old, string $new,
    ) use (&$function): void {
        if (substr_count($function, $old) !== 1) {
            throw new RuntimeException('Owned tree phase anchor differs.');
        }
        $function = str_replace($old, $new, $function);
    };

    $replace(
        "): ?array {\n    if (\$knownRevision !== null",
        "): ?array {\n"
        . "    \$a1Started = hrtime(true);\n"
        . "    \$a1SelectStart = (int) \\Gallery\\Core\\db()\n"
        . "        ->query(\"SHOW SESSION STATUS LIKE 'Com_select'\")\n"
        . "        ->fetchColumn(1);\n"
        . "    if (\$knownRevision !== null",
    );
    $replace(
        '    $identity = app_linking_authenticate($token, $issuer);',
        '    $a1AuthStarted = hrtime(true);' . "\n"
        . '    $identity = app_linking_authenticate($token, $issuer);' . "\n"
        . '    $a1AuthMs = (hrtime(true) - $a1AuthStarted) / 1e6;',
    );
    $replace(
        '    $rows = gallery_model_linked_client_page(',
        '    $a1ModelStarted = hrtime(true);' . "\n"
        . '    $rows = gallery_model_linked_client_page(',
    );
    $replace(
        '        $identity[\'scope\'], 0, APP_LINKING_GALLERY_TREE_MAX_ROWS + 1,'
        . "\n    );",
        '        $identity[\'scope\'], 0, APP_LINKING_GALLERY_TREE_MAX_ROWS + 1,'
        . "\n    );\n"
        . '    $a1ModelMs = (hrtime(true) - $a1ModelStarted) / 1e6;',
    );
    $replace(
        '    $galleries = [];',
        '    $a1ReconcileStarted = hrtime(true);' . "\n"
        . '    $galleries = [];',
    );
    $replace(
        '    $revision = hash(\'sha256\', json_encode([',
        '    $a1ReconcileMs = (hrtime(true) - $a1ReconcileStarted) / 1e6;' . "\n"
        . '    $a1RevisionJsonStarted = hrtime(true);' . "\n"
        . '    $a1RevisionBody = json_encode([',
    );
    $replace(
        '    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));',
        '    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);' . "\n"
        . '    $a1RevisionJsonMs = (hrtime(true) - $a1RevisionJsonStarted) / 1e6;'
        . "\n    \$a1HashStarted = hrtime(true);\n"
        . '    $revision = hash(\'sha256\', $a1RevisionBody);' . "\n"
        . '    $a1HashMs = (hrtime(true) - $a1HashStarted) / 1e6;',
    );
    $replace(
        '    if (strlen(json_encode(',
        '    $a1ResponseJsonStarted = hrtime(true);' . "\n"
        . '    if (strlen(json_encode(',
    );
    $replace(
        '    $current = app_linking_authenticate($token, $issuer);',
        '    $a1ResponseJsonMs = (hrtime(true) - $a1ResponseJsonStarted) / 1e6;'
        . "\n    \$a1ReauthStarted = hrtime(true);\n"
        . '    $current = app_linking_authenticate($token, $issuer);' . "\n"
        . '    $a1ReauthMs = (hrtime(true) - $a1ReauthStarted) / 1e6;',
    );
    $replace(
        '    return $result;',
        <<<'PHP'
    $a1SelectEnd = (int) \Gallery\Core\db()
        ->query("SHOW SESSION STATUS LIKE 'Com_select'")
        ->fetchColumn(1);
    $a1PhaseRow = [
        'rows' => count($galleries),
        'unchanged' => $result['unchanged'],
        'sql_select_count' => max(0, $a1SelectEnd - $a1SelectStart),
        'sql_ms' => null,
        'auth_initial_ms' => $a1AuthMs,
        'auth_recheck_ms' => $a1ReauthMs,
        'model_with_sql_ms' => $a1ModelMs,
        'tree_reconciliation_ms' => $a1ReconcileMs,
        'revision_json_ms' => $a1RevisionJsonMs,
        'revision_sha256_ms' => $a1HashMs,
        'response_json_ms' => $a1ResponseJsonMs,
        'service_ms' => (hrtime(true) - $a1Started) / 1e6,
        'peak_php_bytes' => memory_get_peak_usage(true),
    ];
    \file_put_contents(
        __DIR__ . '/../../.issue362-tree-phases.jsonl',
        json_encode($a1PhaseRow, JSON_THROW_ON_ERROR) . "\n",
        FILE_APPEND | LOCK_EX,
    );
    return $result;
PHP,
    );
    $modified = substr_replace(
        $source, $function, $begin, $end - $begin + 2,
    );
    if (file_put_contents($path, $modified) !== strlen($modified)) {
        throw new RuntimeException('Could not write owned tree-phase copy.');
    }
}

/**
 * Measure bearer-authorized HTTP requests through the actual copied PHP app.
 *
 * @param GalleryWorkflow\Fixture $fixture Newly created owned test installation.
 * @return array<string,mixed> Bounded, sanitized metrics and explicit limitations.
 */
function a1_http_run(GalleryWorkflow\Fixture $fixture): array
{
    $fixture->start();
    // PHP's CLI development server does not populate SERVER_ADDR, even
    // though the owned fixture listens exclusively on 127.0.0.1.
    // Supply the independently configured listener metadata only inside
    // this disposable router copy, not in the production admission policy.
    $routerPath = $fixture->directory . '/router.php';
    $router = (string) file_get_contents($routerPath);
    $entry = "require __DIR__ . '/public/index.php';";
    if (substr_count($router, $entry) !== 1) {
        throw new RuntimeException('Unexpected isolated HTTP router source.');
    }
    $loopbackMetadata = <<<'PHP'
if (PHP_SAPI === 'cli-server'
    && ($_SERVER['REMOTE_ADDR'] ?? null) === '127.0.0.1'
    && !isset($_SERVER['SERVER_ADDR'])) {
    $_SERVER['SERVER_ADDR'] = '127.0.0.1';
}
PHP;
    // Fault responses are injected only by the owned CLI-server router copy.
    // Successful requests continue into the unchanged production authorization.
    $injectedFaults = <<<'PHP'
if (PHP_SAPI === 'cli-server'
    && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
    && ($_GET['page'] ?? '') === 'app_linking_media'
    && ($_GET['kind'] ?? '') === 'thumbnail'
    && isset($_GET['image_id'])
    && getenv('GALLERY_WORKFLOW_ENABLE') === 'disposable-only') {
    $faultFile = __DIR__ . '/.issue362-a1-fault-mode';
    if (is_file($faultFile)) {
        $faultMode = trim((string) file_get_contents($faultFile));
        if (in_array($faultMode, ['429', '502', '503'], true)) {
            http_response_code((int) $faultMode);
            header('Content-Type: application/json');
            header('Cache-Control: no-store');
            header('Connection: close');
            if ($faultMode === '429') {
                header('Retry-After: 1');
            }
            echo '{"error":"temporarily_unavailable"}';
            return;
        }
        if (in_array($faultMode, ['html_502', 'html_503'], true)) {
            http_response_code((int) substr($faultMode, 5));
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store');
            header('Connection: close');
            echo '<!doctype html><title>disposable gateway failure</title>';
            return;
        }
        if ($faultMode === 'truncated') {
            http_response_code(200);
            header('Content-Type: image/jpeg');
            header('Content-Length: 512');
            header('Connection: close');
            echo "\xff\xd8\xff";
            return;
        }
        if ($faultMode === 'slow') {
            usleep(1500000);
        }
    }
}
PHP;
    file_put_contents(
        $routerPath,
        str_replace(
            $entry, $injectedFaults . "\n" . $loopbackMetadata . "\n" . $entry,
            $router,
        ),
    );
    $configPath = $fixture->directory . '/config.php';
    $config = require $configPath;
    if (!is_array($config) || !str_starts_with($fixture->url, 'http://127.0.0.1:')) {
        throw new RuntimeException('Unexpected fixture origin.');
    }
    $origin = 'http://localhost:' . substr(
        $fixture->url, strlen('http://127.0.0.1:'),
    );
    // The server's existing HTTP exception is exclusively for localhost.
    // This edit applies only to the explicitly owned disposable config copy.
    $config['base_url'] = $origin;
    file_put_contents(
        $configPath,
        "<?php\nreturn " . var_export($config, true) . ";\n",
    );
    a1_http_instrument_tree_phases($fixture);
    $fixture->run(
        [
            PHP_BINARY, dirname(__FILE__) . '/issue_362_a1_seed.php',
            $fixture->directory, $fixture->token,
        ],
        A1_HTTP_SEED_TIMEOUT_SECONDS,
        'A1 owned photo seed',
        true,
    );

    $environment = $fixture->environment();
    $database = new PDO(
        (string) $environment['GALLERY_TEST_MYSQL_DSN'],
        (string) $environment['GALLERY_TEST_MYSQL_USER'],
        (string) $environment['GALLERY_TEST_MYSQL_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $userId = (int) $database->query(
        "SELECT id FROM users WHERE username = 'workflow_admin'",
    )->fetchColumn();
    $seed = json_decode(
        (string) file_get_contents($fixture->directory . '/seed.json'),
        true, flags: JSON_THROW_ON_ERROR,
    );
    $galleryId = (int) $seed['root_id'];
    if ($userId <= 0 || $galleryId <= 0) {
        throw new RuntimeException('Fixture account or gallery unavailable.');
    }

    $token = \Gallery\Services\app_linking_access_token_generate();
    $tokenHash = \Gallery\Services\app_linking_access_token_hash($token);
    if ($tokenHash === null) {
        throw new RuntimeException('Synthetic token validation failed.');
    }
    $grant = $database->prepare(
        'INSERT INTO app_link_grants '
        . '(user_id, client_id, issuer, scope, created_at, '
        . 'access_token_hash, access_issued_at, access_expires_at) '
        . 'VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), '
        . 'DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 2400 SECOND))',
    );
    $grant->execute([
        $userId, 'php-gallery-winapp', $origin,
        'link:identity galleries:list media:read', $tokenHash,
    ]);
    // Separate real upload-capable grant is used exclusively by the Watcher
    // fairness child; the original baseline remains a read-only media grant.
    $watcherToken = \Gallery\Services\app_linking_access_token_generate();
    $watcherHash = \Gallery\Services\app_linking_access_token_hash($watcherToken);
    if ($watcherHash === null) {
        throw new RuntimeException('Synthetic Watcher token validation failed.');
    }
    $grant->execute([
        $userId, 'php-gallery-winapp', $origin,
        'link:identity galleries:list gallery:upload media:read', $watcherHash,
    ]);

    $records = [];
    $treeAdded = 0;
    $seedRows = (int) $database->query(
        'SELECT COUNT(*) FROM galleries',
    )->fetchColumn();
    foreach (A1_HTTP_TREE_SIZES as $target) {
        $treeAdded = a1_http_seed_tree(
            $database, $treeAdded, $target, $seedRows,
        );
        $actualRows = (int) $database->query(
            'SELECT COUNT(*) FROM galleries',
        )->fetchColumn();
        if ($actualRows !== $target) {
            throw new RuntimeException('Incorrect synthetic tree row count.');
        }
        // Each full response establishes the revision for that exact row set.
        for ($trial = 1; $trial <= A1_HTTP_TRIALS; ++$trial) {
            $full = a1_http_request(
                $origin, 'page=app_linking_galleries&tree=1', $token,
            );
            a1_http_observe($records, 'tree_full', $trial, $full, $target);
            $json = json_decode($full['body'], true);
            $revision = $json['revision'] ?? null;
            if ($full['status'] !== 200 || !is_string($revision)
                || preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1
                || !isset($json['galleries'])
                || count($json['galleries']) !== $target) {
                $failureCode = is_array($json)
                    && is_string($json['error'] ?? null)
                    && preg_match('/^[a-z_]{1,40}$/D', $json['error']) === 1
                    ? $json['error'] : 'invalid_shape';
                fwrite(STDERR, 'A1 tree response contract: status='
                    . $full['status'] . ' reason=' . $failureCode
                    . ' rows=' . (is_array($json['galleries'] ?? null)
                        ? count($json['galleries']) : 0) . "\n");
                throw new RuntimeException('Real gallery tree full response failed.');
            }
            $unchanged = a1_http_request(
                $origin,
                'page=app_linking_galleries&tree=1&revision=' . $revision,
                $token,
            );
            a1_http_observe($records, 'tree_unchanged', $trial, $unchanged, $target);
            $same = json_decode($unchanged['body'], true);
            if ($unchanged['status'] !== 200
                || ($same['unchanged'] ?? null) !== true
                || isset($same['galleries'])) {
                throw new RuntimeException('Live unchanged tree was not confirmed.');
            }
        }
        // The connected client observes the exact tree rows already present
        // before the next isolated tree-size mutation.
        a1_http_client_run($fixture, $origin, $token, 'tree', $target);
    }

    // Invalid optional-tree queries must still fail at the original security
    // boundary; allowing the tree-v1 keys never permits unknown parameters.
    foreach ([
        ['page=app_linking_galleries&tree=1&unexpected=1',
            'tree_unknown_key', 404],
        ['page=app_linking_galleries&tree=0',
            'tree_invalid_selector', 400],
        ['page=app_linking_galleries&tree=1&revision=invalid',
            'tree_invalid_revision', 400],
    ] as [$query, $reason, $expectedStatus]) {
        $invalid = a1_http_request($origin, $query, $token);
        a1_http_observe($records, $reason, 1, $invalid);
        if ($invalid['status'] !== $expectedStatus) {
            throw new RuntimeException(
                'Unexpected tree validation refusal: ' . $reason,
            );
        }
    }

    // The controller refuses an oversized optional tree and retains the old
    // authorized 50-row page endpoint. This tests the server-side contract,
    // not the complete WinApp fallback scheduler.
    $treeAdded = a1_http_seed_tree(
        $database, $treeAdded, A1_HTTP_TREE_OVERFLOW_ROWS, $seedRows,
    );
    $overflow = a1_http_request(
        $origin, 'page=app_linking_galleries&tree=1', $token,
    );
    a1_http_observe(
        $records, 'tree_overflow_refused', 1, $overflow,
        A1_HTTP_TREE_OVERFLOW_ROWS,
    );
    $refusal = json_decode($overflow['body'], true);
    if ($overflow['status'] !== 503 || !is_array($refusal)
        || ($refusal['error'] ?? null) !== 'temporarily_unavailable') {
        throw new RuntimeException('Oversized tree was not safely refused.');
    }
    $legacy = a1_http_request(
        $origin, 'page=app_linking_galleries', $token,
    );
    a1_http_observe(
        $records, 'tree_overflow_paged_fallback', 1, $legacy,
        A1_HTTP_TREE_OVERFLOW_ROWS,
    );
    $pageBody = json_decode($legacy['body'], true);
    if ($legacy['status'] !== 200 || !is_array($pageBody)
        || !is_array($pageBody['galleries'] ?? null)
        || count($pageBody['galleries']) !== 50
        || !is_int($pageBody['next_after'] ?? null)) {
        throw new RuntimeException('Oversized tree lost the paginated route.');
    }
    // Keep full fallback probing for last: its real 120/300 s IP rate
    // limiter must not poison the 120-photo and Watcher experiments.
    $imageIds = [];
    $cursor = null;
    $pageCount = 0;
    do {
        $query = 'page=app_linking_media&gallery_id='
            . $galleryId . '&kind=photos';
        if ($cursor !== null) {
            $query .= '&after=' . $cursor;
        }
        $page = a1_http_request(
            $origin, $query, $token,
            ['X-PHP-Gallery-Photo-Page: 64'],
        );
        ++$pageCount;
        a1_http_observe($records, 'photo_metadata', $pageCount, $page);
        $json = json_decode($page['body'], true);
        if ($page['status'] !== 200 || !is_array($json)
            || !is_array($json['photos'] ?? null)) {
            throw new RuntimeException('Real media catalogue page failed.');
        }
        foreach ($json['photos'] as $photo) {
            if (is_array($photo) && isset($photo['id'])
                && is_int($photo['id'])) {
                $imageIds[] = $photo['id'];
            }
        }
        $cursor = $json['next_after'] ?? null;
        if ($pageCount > 4) {
            throw new RuntimeException('Unbounded media page traversal.');
        }
    } while ($cursor !== null);
    if (count(array_unique($imageIds)) < A1_HTTP_MIN_IMAGES) {
        throw new RuntimeException('Fewer than 120 authorized distinct images.');
    }

    // These are first/warmed SERVER reads, not a WinApp RAM/disk cache test.
    foreach (['server_first_get', 'server_repeat_get'] as $round => $label) {
        foreach (array_slice($imageIds, 0, A1_HTTP_MIN_IMAGES) as $imageId) {
            $image = a1_http_request(
                $origin, 'page=app_linking_media&gallery_id='
                    . $galleryId . '&kind=thumbnail&image_id=' . $imageId,
                $token,
                ['X-PHP-Gallery-Photo-Cache: 1'],
            );
            a1_http_observe($records, $label, $round + 1, $image);
            if ($image['status'] !== 200 || $image['bytes'] === 0) {
                throw new RuntimeException('Real authorized thumbnail GET failed.');
            }
        }
    }

    // Read the same synthetic photos with actual WinApp cache and RAM policy.
    // This child has a fresh profile and another session for warm-disk proof.
    a1_http_client_run($fixture, $origin, $token, 'media', $galleryId);
    a1_http_client_run($fixture, $origin, $token, 'gui', $galleryId);
    a1_http_client_run($fixture, $origin, $token, 'fault', $galleryId);
    a1_http_client_run($fixture, $origin, $token, 'mutate', $galleryId);
    a1_http_client_run(
        $fixture, $origin, $token, 'fairness', $galleryId, $watcherToken,
    );
    // Run large 50-row pagination ONLY after all other real HTTP probes;
    // the production gallery-list IP rate limiter is deliberately retained.
    a1_http_client_run(
        $fixture, $origin, $token, 'overflow', A1_HTTP_TREE_OVERFLOW_ROWS,
    );

    $serverPhases = [];
    $tracePath = $fixture->directory . '/loader-metrics.jsonl';
    if (is_file($tracePath)) {
        foreach (file($tracePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $trace = json_decode($line, true);
            if (!is_array($trace) || !is_string($trace['uri'] ?? null)) {
                continue;
            }
            $query = (string) parse_url($trace['uri'], PHP_URL_QUERY);
            parse_str($query, $fields);
            $route = ($fields['page'] ?? '') === 'app_linking_galleries'
                ? 'galleries'
                : (($fields['page'] ?? '') === 'app_linking_media'
                    ? 'media_' . (string) ($fields['kind'] ?? 'unknown')
                    : 'other');
            $serverPhases[] = [
                'route' => $route,
                'wall_ms' => $trace['wall_ms'] ?? null,
                'peak_memory_bytes' => $trace['peak_memory_bytes'] ?? null,
                'included_php_files' => $trace['included_php_files'] ?? null,
            ];
        }
    }

    $treePhases = [];
    $treePhasesPath = $fixture->directory . '/.issue362-tree-phases.jsonl';
    if (is_file($treePhasesPath)) {
        foreach (file(
            $treePhasesPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES,
        ) as $line) {
            $sample = json_decode($line, true);
            if (is_array($sample)
                && isset($sample['rows'], $sample['sql_select_count'])
                && is_int($sample['rows'])
                && is_int($sample['sql_select_count'])) {
                $treePhases[] = $sample;
            }
        }
    }
    if (count($treePhases) < 6) {
        throw new RuntimeException('Owned PHP tree-phase evidence was not recorded.');
    }
    return [
        'schema' => 'php-gallery-a1-http-v1',
        'started_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'scope' => 'isolated copied PHP app + authorized MariaDB + loopback HTTP',
        'php' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'os' => PHP_OS_FAMILY,
        'synthetic_gallery_counts' => A1_HTTP_TREE_SIZES,
        'distinct_gallery_photos' => count(array_unique($imageIds)),
        'metadata_page_count' => $pageCount,
        'requests' => $records,
        'php_server_samples' => $serverPhases,
        'tree_server_phase_samples' => $treePhases,
        'limits' => [
            'No production WEDOS gateway, FPM/Apache worker or provider data.',
            'The copied PHP development server is single-worker by default.',
            'Server warm GET does not represent client RAM or disk caching.',
            'Measured TTFB includes local loopback transport and PHP processing.',
            'SQL query count is from real session SELECT status; model time includes PHP.',
            'SQL per-query duration is not instrumented; UNKNOWN.',
            'No private photo paths, IDs or bearer values are exported.',
        ],
    ];
}

$repositoryRoot = dirname(__DIR__, 2);
$fixture = null;
$startUtc = gmdate('Y-m-d\TH:i:s\Z');
$reportPath = $repositoryRoot . '/cache/test-audit/issue-362-a1-http.json';
try {
    if (PHP_SAPI !== 'cli'
        || getenv('GALLERY_WORKFLOW_ENABLE') !== 'disposable-only') {
        throw new RuntimeException('Explicit owned disposable CI is required.');
    }
    // The fixture router's existing read-only loader diagnostics are isolated.
    putenv('PHP_GALLERY_LOADER_TRACE=1');
    $fixture = new \GalleryWorkflow\Fixture($repositoryRoot, getenv());
    $report = a1_http_run($fixture);
    $report['started_utc'] = $startUtc;
    $report['ended_utc'] = gmdate('Y-m-d\TH:i:s\Z');
    $report['head_sha'] = getenv('GITHUB_SHA') ?: 'UNKNOWN';
    if (!is_dir(dirname($reportPath))) {
        mkdir(dirname($reportPath), 0700, true);
    }
    file_put_contents(
        $reportPath,
        json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
    );
    echo 'PASS A1 isolated PHP HTTP measurements, report saved' . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL A1 isolated PHP HTTP benchmark (' .
        basename($error->getFile()) . ':' . $error->getLine() . ")\n");
    exit(1);
} finally {
    if ($fixture instanceof \GalleryWorkflow\Fixture) {
        $fixture->close();
    }
}
