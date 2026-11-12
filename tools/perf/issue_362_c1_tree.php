<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tools/perf/issue_362_c1_tree.php
 * Module Type: Disposable HTTP Measurement
 * Purpose: Measure and qualify the existing complete gallery-tree route.
 * Responsibilities:
 *   - Use only an owned copied PHP installation and disposable MariaDB.
 *   - Record bounded tree timings and strict mutation, authority and paging proof.
 *   - Retain only sanitized numeric measurements and fixed result metadata.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

/** @phpstan-type C1Timing array{php_ms:float,sql_ms:float,sql_count:int,sql_max_ms:float,sql_failed:int,sql_capped:bool,auth_ms:float,tree_ms:float,hash_ms:float,hash_bytes:int,media_ms:float} */
/** @phpstan-type C1Observation array{case:string,rows:int,status:int,ttfb_ms:float,elapsed_ms:float,body_bytes:int,server_timing:C1Timing|null,server_timing_header_present:bool,server_timing_unknown:bool} */
/** @phpstan-type C1FullClientResult array{identity_live:bool,tree_complete:bool,tree_rows:int,unchanged_confirmed:bool,unchanged_rows:int} */
/** @phpstan-type C1OverflowClientResult array{optional_tree_unavailable:bool,fallback_page_count:int,fallback_rows:int,cursor_advanced:bool,duplicate_ids:bool} */
/** @phpstan-type C1Denied401ClientResult array{grant_rejected:bool,session_expired:bool,request_started:bool,caller_fallback_attempted:bool} */
/** @phpstan-type C1Denied403ClientResult array{refusal_status:int,caller_fallback_attempted:bool} */
/** @phpstan-type C1ClientProof array{scenario:'full'|'overflow-row'|'overflow-byte'|'denied-401'|'denied-403',head_sha:string,source_base:string,run_id:string,result:C1FullClientResult|C1OverflowClientResult|C1Denied401ClientResult|C1Denied403ClientResult} */
/** @phpstan-type C1Report array{schema:string,status:'PASS',started_utc:string,ended_utc:string,head_sha:string,source_base:string,github_run_id:string,php_version:string,sapi:string,scope:string,fixture_baseline_rows:int,populations:list<int>,trials_per_population:int,measurements:list<C1Observation>,client_proofs:list<C1ClientProof>,timing_limits:list<string>} */

require_once dirname(__DIR__, 2) . '/tests/support/gallery_workflow_fixture.php';
require_once dirname(__DIR__, 2) . '/app/services/security_tokens.php';
require_once dirname(__DIR__, 2) . '/app/services/app_linking_policy.php';

/**
 * Bound representative catalogue populations without changing production caps.
 * Type: list<int>. Units: total physical gallery rows. Scope: C1 tree samples.
 * Consumers: c1_measure_populations. Rationale: retain the existing 500, 2000
 * and 10000 row comparison points from A1 on the current candidate.
 */
const C1_TREE_POPULATIONS = [500, 2000, 10000];

/**
 * Keep the number of observations fixed for each route and population.
 * Type: int. Units: full/unchanged pairs. Scope: C1 tree timing evidence.
 * Consumers: c1_measure_populations. Rationale: three samples provide a small
 * diagnostic distribution without pretending to establish stable tail timing.
 */
const C1_TREE_TRIALS = 3;

/**
 * Bound one loopback request and its returned body in the owned fixture.
 * Type: array<string,int>. Units: seconds and bytes. Scope: C1 HTTP requests.
 * Consumers: c1_request and C1 fixture shaping. Rationale: 3 seconds bounds
 * local connection setup; 30 seconds admits 10k-row local PHP processing;
 * 8 MiB is twice the production whole-tree cap for measurement responses;
 * depth 8 matches the simple envelope; 50 preserves existing page size;
 * 10001 crosses the row guard by one; 4300 rows with 255 four-byte characters
 * exceed 4 MiB while staying under the row guard; 255 matches title VARCHAR.
 */
const C1_HTTP_LIMITS = [
    'connect_seconds' => 3,
    'request_seconds' => 30,
    'response_bytes' => 8388608,
    'json_depth' => 8,
    'legacy_page_rows' => 50,
    'row_overflow' => 10001,
    'byte_population' => 4300,
    'title_characters' => 255,
];

/**
 * Bound owned bulk inserts so fixture SQL packets stay small and predictable.
 * Type: int. Units: gallery rows per INSERT. Scope: C1 synthetic tree seeding.
 * Consumers: c1_seed_to. Rationale: 250 bounded gallery rows fit comfortably
 * in the disposable MariaDB packet budget, even for four-byte UTF-8 titles.
 */
const C1_INSERT_ROWS = 250;

/**
 * Bound diagnostic timing values to the passive profiler's published ceiling.
 * Type: float. Units: milliseconds. Scope: parsed Server-Timing metric values.
 * Consumers: c1_parse_server_timing. Rationale: match the existing profiler's
 * 600000 ms cap and reject malformed, negative or non-finite diagnostics.
 */
const C1_TIMING_MAX_MS = 600000.0;

/**
 * Keep passive Server-Timing header parsing bounded before the strict grammar.
 * Type: int. Units: bytes. Scope: one authorized loopback response header.
 * Consumers: c1_parse_server_timing. Rationale: the fixed six-field header is
 * far shorter; 1024 bytes leaves room while rejecting unexpected data.
 */
const C1_TIMING_HEADER_MAX_BYTES = 1024;

/**
 * Match the bounded fixture grant lifetime used by existing A1/B2 owners.
 * Type: int. Units: seconds. Scope: synthetic app-linking grants only.
 * Consumers: c1_run and c1_issue_grant. Rationale: 2400 seconds covers the
 * bounded C1 suite without creating durable or reusable authority.
 */
const C1_GRANT_TTL_SECONDS = 2400;

/**
 * Bound one real WinApp protocol child under the central C1 audit owner.
 * Type: int. Units: seconds. Scope: one discovery/tree/fallback proof process.
 * Consumers: c1_client_run through Fixture::run.
 * Rationale: each small loopback scenario stays finite; all seven serial client
 * proofs fit within the unchanged 300-second central suite bound.
 */
const C1_CLIENT_TIMEOUT_SECONDS = 25;

/**
 * Name the passive server-side profiler opt-in inherited by the owned server.
 * Type: string. Units: environment-variable name. Scope: C1 fixture process.
 * Consumers: c1_run. Rationale: use A2's existing pre-start opt-in without
 * adding query flags, request headers or production instrumentation.
 */
const C1_PASSIVE_PROFILE_ENVIRONMENT = 'PHP_GALLERY_A2_PASSIVE_PROFILE';

/**
 * Identify only rows created by this disposable C1 tree owner.
 * Type: string. Units: escaped SQL LIKE pattern. Scope: one fixture DB.
 * Consumers: c1_seed_to and controlled row reset/title operations.
 * Rationale: escape each underscore so cleanup cannot match unrelated rows.
 */
const C1_SYNTHETIC_PATH_PATTERN = 'issue!_362!_c1!_%';

/**
 * Issue a bounded authenticated tree or legacy page request to the fixture.
 *
 * @param string $origin Exact localhost origin of the owned copied app.
 * @param string $query Fixed route query assembled by this file.
 * @param string|null $token Ephemeral fixture bearer, or null for anonymous.
 * @return array{status:int,body:string,bytes:int,ttfb_ms:float,elapsed_ms:float,server_timing:?string}
 *     Response status, bounded body and client-side numeric measurements.
 * @throws RuntimeException When cURL fails or the response exceeds its cap.
 */
function c1_request(string $origin, string $query, ?string $token): array
{
    $handle = curl_init($origin . '/index.php?' . $query);
    if ($handle === false) {
        throw new RuntimeException('C1 request initialization failed.');
    }
    $body = '';
    $serverTiming = null;
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => C1_HTTP_LIMITS['connect_seconds'],
        CURLOPT_TIMEOUT => C1_HTTP_LIMITS['request_seconds'],
        CURLOPT_PROXY => '',
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_WRITEFUNCTION => static function (
            \CurlHandle $curl,
            string $chunk,
        ) use (&$body): int {
            if (strlen($body) + strlen($chunk)
                > C1_HTTP_LIMITS['response_bytes']) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
        CURLOPT_HEADERFUNCTION => static function (
            \CurlHandle $curl,
            string $line,
        ) use (&$serverTiming): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2
                && strtolower(trim($parts[0])) === 'server-timing') {
                $serverTiming = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    $started = hrtime(true);
    $success = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $ttfb = (float) curl_getinfo($handle, CURLINFO_STARTTRANSFER_TIME);
    $elapsed = (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME);
    unset($handle);
    if ($success !== true) {
        throw new RuntimeException('C1 bounded HTTP transfer failed.');
    }
    $measured = (hrtime(true) - $started) / 1_000_000;
    return [
        'status' => $status,
        'body' => $body,
        'bytes' => strlen($body),
        'ttfb_ms' => round($ttfb * 1000, 3),
        'elapsed_ms' => round(max($elapsed * 1000, $measured), 3),
        'server_timing' => $serverTiming,
    ];
}

/**
 * Parse only the fixed, numeric passive tree Server-Timing header grammar.
 *
 * @param string|null $header Header value from the owned loopback response.
 * @return array{php_ms:float,sql_ms:float,sql_count:int,sql_max_ms:float,sql_failed:int,sql_capped:bool,auth_ms:float,tree_ms:float,hash_ms:float,hash_bytes:int,media_ms:float}|null
 *     Strictly parsed allowlisted metrics, or null when absent or malformed.
 */
function c1_parse_server_timing(?string $header): ?array
{
    if ($header === null || strlen($header) > C1_TIMING_HEADER_MAX_BYTES) {
        return null;
    }
    $number = '(\d{1,6}(?:\.\d{1,3})?)';
    $pattern = '/\Agallery-php;dur=' . $number
        . ', gallery-sql;dur=' . $number
        . ';desc="n=(\d{1,3});max_ms=' . $number
        . ';failed=(\d{1,3});cap=([01])", gallery-auth;dur=' . $number
        . ', gallery-tree;dur=' . $number
        . ', gallery-hash;dur=' . $number
        . ';desc="bytes=(\d{1,8})", gallery-media;dur=' . $number . '\z/D';
    if (preg_match($pattern, $header, $matches) !== 1) {
        return null;
    }
    $durations = [
        (float) $matches[1], (float) $matches[2], (float) $matches[4],
        (float) $matches[7], (float) $matches[8], (float) $matches[9],
        (float) $matches[11],
    ];
    foreach ($durations as $duration) {
        if (!is_finite($duration) || $duration < 0
            || $duration > C1_TIMING_MAX_MS) {
            return null;
        }
    }
    return [
        'php_ms' => (float) $matches[1],
        'sql_ms' => (float) $matches[2],
        'sql_count' => (int) $matches[3],
        'sql_max_ms' => (float) $matches[4],
        'sql_failed' => (int) $matches[5],
        'sql_capped' => $matches[6] === '1',
        'auth_ms' => (float) $matches[7],
        'tree_ms' => (float) $matches[8],
        'hash_ms' => (float) $matches[9],
        'hash_bytes' => (int) $matches[10],
        'media_ms' => (float) $matches[11],
    ];
}

/**
 * Store one sanitized HTTP observation without response or identity contents.
 *
 * @param list<C1Observation> $records Existing safe records.
 * @param string $case Fixed logical route case, never a URL or row identity.
 * @param int $rows Fixture population for full/unchanged/overflow requests;
 *     zero for the paged legacy responses. An overflow value is not rows sent.
 * @param array{status:int,body:string,bytes:int,ttfb_ms:float,elapsed_ms:float,server_timing:?string} $response Actual owned response.
 * @return void Appends numeric data and optional allowlisted server metrics.
 */
function c1_observe(
    array &$records,
    string $case,
    int $rows,
    array $response,
): void {
    $GLOBALS['c1_last_case'] = $case;
    $serverMetrics = c1_parse_server_timing($response['server_timing']);
    $records[] = [
        'case' => $case,
        'rows' => $rows,
        'status' => $response['status'],
        'ttfb_ms' => $response['ttfb_ms'],
        'elapsed_ms' => $response['elapsed_ms'],
        'body_bytes' => $response['bytes'],
        'server_timing' => $serverMetrics,
        'server_timing_header_present' => $response['server_timing'] !== null,
        'server_timing_unknown' => $serverMetrics === null,
    ];
    $root = dirname(__DIR__, 2);
    $directory = $root . '/cache/test-audit';
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents(
        $directory . '/issue-362-c1-tree-http-partial.json',
        json_encode([
            'schema' => 'php-gallery-c1-tree-http-v1-partial',
            'head_sha' => getenv('GITHUB_SHA') ?: 'UNKNOWN',
            'source_base' => getenv('PHP_GALLERY_SOURCE_BASE') ?: 'UNKNOWN',
            'github_run_id' => getenv('GITHUB_RUN_ID') ?: 'UNKNOWN',
            'measurements' => $records,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
    );
}

/**
 * Ensure the owned synthetic catalogue has exactly the requested row count.
 *
 * @param PDO $database Verified connection to the disposable fixture schema.
 * @param int $target Desired total physical gallery rows including fixture seed.
 * @param int $baseline Count of non-C1 fixture galleries retained unchanged.
 * @param string $title Synthetic title of at most the database field limit.
 * @return void Inserts only C1-prefixed owned rows; never edits app data.
 */
function c1_seed_to(PDO $database, int $target, int $baseline, string $title): void
{
    if ($target < $baseline || $target > C1_HTTP_LIMITS['row_overflow']) {
        throw new RuntimeException('C1 population is outside its fixture bound.');
    }
    $count = (int) $database->query(
        "SELECT COUNT(*) FROM galleries WHERE folder_path LIKE '"
            . C1_SYNTHETIC_PATH_PATTERN . "' ESCAPE '!'",
    )->fetchColumn();
    $needed = $target - $baseline;
    if ($count > $needed) {
        $database->exec(
            "DELETE FROM galleries WHERE folder_path LIKE '"
                . C1_SYNTHETIC_PATH_PATTERN . "' ESCAPE '!'",
        );
        $count = 0;
    }
    if ($count === $needed) {
        return;
    }
    $insertCount = $needed - $count;
    $baseIndex = $count;
    for ($offset = 0; $offset < $insertCount;
        $offset += C1_INSERT_ROWS) {
        $batch = min(C1_INSERT_ROWS, $insertCount - $offset);
        $values = [];
        $rows = [];
        for ($row = 0; $row < $batch; ++$row) {
            $index = $baseIndex + $offset + $row;
            $path = sprintf('issue_362_c1_%05d', $index);
            $rows[] = '(?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))';
            array_push($values, $path, hash('sha256', $path), $path, $title,
                'private');
        }
        $sql = 'INSERT INTO galleries '
            . '(folder_path, folder_path_hash, slug, title, visibility, '
            . 'created_at, updated_at) VALUES ' . implode(', ', $rows);
        $database->prepare($sql)->execute($values);
    }
    $actual = (int) $database->query('SELECT COUNT(*) FROM galleries')
        ->fetchColumn();
    if ($actual !== $target) {
        throw new RuntimeException('C1 synthetic row count mismatch.');
    }
}

/**
 * Validate a complete tree body while keeping identities in memory only.
 *
 * @param array{status:int,body:string,bytes:int,ttfb_ms:float,elapsed_ms:float,server_timing:?string} $response Actual loopback response.
 * @param int $expectedRows Expected gallery row count.
 * @return array{revision:string,galleries:list<array{id:int,parent_id:?int,title:string}>} Validated tree used only by this process.
 * @throws RuntimeException When status, revision, row count or row shape differs.
 */
function c1_complete_tree(array $response, int $expectedRows): array
{
    $data = json_decode(
        $response['body'], true, C1_HTTP_LIMITS['json_depth'],
        JSON_THROW_ON_ERROR,
    );
    if ($response['status'] !== 200 || !is_array($data)
        || array_keys($data) !== [
            'version', 'revision', 'unchanged', 'galleries',
        ]
        || ($data['version'] ?? null) !== 1
        || !is_string($data['revision'] ?? null)
        || preg_match('/\A[a-f0-9]{64}\z/D', $data['revision']) !== 1
        || ($data['unchanged'] ?? null) !== false
        || !is_array($data['galleries'] ?? null)
        || count($data['galleries']) !== $expectedRows) {
        throw new RuntimeException('C1 complete tree contract failed.');
    }
    $previousId = 0;
    foreach ($data['galleries'] as $gallery) {
        if (!is_array($gallery) || !is_int($gallery['id'] ?? null)
            || array_keys($gallery) !== ['id', 'parent_id', 'title']
            || $gallery['id'] <= $previousId
            || !array_key_exists('parent_id', $gallery)
            || ($gallery['parent_id'] !== null
                && !is_int($gallery['parent_id']))
            || !is_string($gallery['title'] ?? null)) {
            throw new RuntimeException('C1 tree projection shape failed.');
        }
        $previousId = $gallery['id'];
    }
    return [
        'revision' => $data['revision'],
        'galleries' => $data['galleries'],
    ];
}

/**
 * Read one complete tree and its same-revision unchanged confirmation.
 *
 * @param string $origin Exact owned localhost issuer.
 * @param string $token Synthetic gallery-list bearer.
 * @param int $expectedRows Expected full tree count.
 * @param string $knownRevision Prior revision for this same population, or empty on its first sample.
 * @param string $case Fixed measurement label.
 * @param list<C1Observation> $records Sanitized output records.
 * @return string Current complete-tree revision.
 * @throws RuntimeException When complete or unchanged response proof differs.
 */
function c1_full_and_unchanged(
    string $origin,
    string $token,
    int $expectedRows,
    string $knownRevision,
    string $case,
    array &$records,
): string {
    $full = c1_request($origin, 'page=app_linking_galleries&tree=1', $token);
    c1_observe($records, $case . '_full', $expectedRows, $full);
    $tree = c1_complete_tree($full, $expectedRows);
    if ($knownRevision !== ''
        && !hash_equals($knownRevision, $tree['revision'])) {
        throw new RuntimeException('C1 unchanged population revision drifted.');
    }
    $unchanged = c1_request(
        $origin,
        'page=app_linking_galleries&tree=1&revision=' . $tree['revision'],
        $token,
    );
    c1_observe($records, $case . '_unchanged', $expectedRows, $unchanged);
    $data = json_decode(
        $unchanged['body'], true, C1_HTTP_LIMITS['json_depth'],
        JSON_THROW_ON_ERROR,
    );
    if ($unchanged['status'] !== 200 || !is_array($data)
        || ($data['unchanged'] ?? null) !== true
        || ($data['revision'] ?? null) !== $tree['revision']
        || array_key_exists('galleries', $data)) {
        throw new RuntimeException('C1 unchanged-tree proof failed.');
    }
    return $tree['revision'];
}

/**
 * Verify two forward legacy pages without traversing the complete catalogue.
 *
 * @param string $origin Exact owned localhost issuer.
 * @param string $token Synthetic gallery-list bearer.
 * @param string $case Fixed row- or byte-overflow case label.
 * @param list<C1Observation> $records Sanitized output records.
 * @return void Requires 50 distinct IDs per page and a strictly advancing cursor.
 */
function c1_verify_two_legacy_pages(
    string $origin,
    string $token,
    string $case,
    array &$records,
): void {
    $cursor = null;
    $seen = [];
    for ($pageNumber = 1; $pageNumber <= 2; ++$pageNumber) {
        $query = 'page=app_linking_galleries'
            . ($cursor === null ? '' : '&after=' . $cursor);
        $response = c1_request($origin, $query, $token);
        c1_observe($records, $case . '_legacy_page_' . $pageNumber, 0,
            $response);
        $page = json_decode(
            $response['body'], true, C1_HTTP_LIMITS['json_depth'],
            JSON_THROW_ON_ERROR,
        );
        if ($response['status'] !== 200 || !is_array($page)
            || array_keys($page) !== ['galleries', 'next_after']
            || !is_array($page['galleries'] ?? null)
            || count($page['galleries']) !== C1_HTTP_LIMITS['legacy_page_rows']
            || !is_int($page['next_after'] ?? null)) {
            throw new RuntimeException('C1 legacy page fallback failed.');
        }
        $lastId = $cursor ?? 0;
        foreach ($page['galleries'] as $gallery) {
            $id = $gallery['id'] ?? null;
            if (!is_int($id) || $id <= $lastId || isset($seen[$id])) {
                throw new RuntimeException('C1 legacy pages repeated an ID.');
            }
            $seen[$id] = true;
            $lastId = $id;
        }
        if ($page['next_after'] !== $lastId
            || ($cursor !== null && $page['next_after'] <= $cursor)) {
            throw new RuntimeException('C1 legacy cursor did not advance.');
        }
        $cursor = $page['next_after'];
    }
    if (count($seen) !== 2 * C1_HTTP_LIMITS['legacy_page_rows']) {
        throw new RuntimeException('C1 legacy page coverage was incomplete.');
    }
}

/**
 * Run current-SHA tree measurements and bounded live contract checks.
 *
 * @param \GalleryWorkflow\Fixture $fixture Owned disposable installation.
 * @return C1Report Sanitized current-run measurements and limits.
 * @throws RuntimeException When fixture setup or a required contract fails.
 */
function c1_run(\GalleryWorkflow\Fixture $fixture): array
{
    $fixture->start();
    $routerPath = $fixture->directory . '/router.php';
    $router = (string) file_get_contents($routerPath);
    $entry = "require __DIR__ . '/public/index.php';";
    if (substr_count($router, $entry) !== 1) {
        throw new RuntimeException('Unexpected owned C1 router.');
    }
    $instrumentation = <<<'PHP'
if (PHP_SAPI === 'cli-server'
    && ($_SERVER['REMOTE_ADDR'] ?? null) === '127.0.0.1'
    && getenv('GALLERY_WORKFLOW_ENABLE') === 'disposable-only') {
    $_SERVER['SERVER_ADDR'] = $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
}
PHP;
    file_put_contents($routerPath,
        str_replace($entry, $instrumentation . "\n" . $entry, $router));
    $configPath = $fixture->directory . '/config.php';
    $config = require $configPath;
    \GalleryWorkflow\check(
        is_array($config) && str_starts_with($fixture->url,
            'http://127.0.0.1:'),
        'Unexpected isolated C1 origin.',
    );
    $origin = 'http://localhost:'
        . substr($fixture->url, strlen('http://127.0.0.1:'));
    $config['base_url'] = $origin;
    file_put_contents($configPath,
        "<?php\nreturn " . var_export($config, true) . ";\n");

    $database = \GalleryWorkflow\fixtureDatabase(
        $fixture->directory, $fixture->token,
    );
    $baseline = (int) $database->query('SELECT COUNT(*) FROM galleries')
        ->fetchColumn();
    $userId = (int) $database->query(
        "SELECT id FROM users WHERE username = 'workflow_admin'",
    )->fetchColumn();
    if ($baseline < 1 || $userId <= 0) {
        throw new RuntimeException('C1 disposable Admin fixture is absent.');
    }
    $token = c1_issue_grant(
        $database, $userId, $origin,
        'link:identity galleries:list media:read',
    );
    $tokenHash = \Gallery\Services\app_linking_access_token_hash($token);
    if ($tokenHash === null) {
        throw new RuntimeException('C1 fixture bearer generation failed.');
    }

    $records = [];
    $clientProofs = [];
    $revision = '';
    foreach (C1_TREE_POPULATIONS as $population) {
        $revision = '';
        c1_seed_to($database, $population, $baseline, 'C1 Synthetic');
        $actual = (int) $database->query('SELECT COUNT(*) FROM galleries')
            ->fetchColumn();
        if ($actual !== $population) {
            throw new RuntimeException('C1 seeded population did not match.');
        }
        for ($trial = 1; $trial <= C1_TREE_TRIALS; ++$trial) {
            $revision = c1_full_and_unchanged(
                $origin, $token, $population, $revision,
                'tree_' . $population . '_sample_' . $trial, $records,
            );
        }
        $clientProofs[] = c1_client_run(
            $fixture, 'full', $token,
            'link:identity galleries:list media:read', $population,
        );
        if ($population === C1_TREE_POPULATIONS[0]) {
            $synthetic = $database->query(
                "SELECT id FROM galleries WHERE folder_path LIKE '"
                    . C1_SYNTHETIC_PATH_PATTERN
                    . "' ESCAPE '!' ORDER BY id LIMIT 2",
            )->fetchAll(PDO::FETCH_COLUMN);
            if (count($synthetic) !== 2) {
                throw new RuntimeException('C1 mutation rows are unavailable.');
            }
            $firstId = (int) $synthetic[0];
            $secondId = (int) $synthetic[1];
            $before = $revision;
            $database->prepare(
                'UPDATE galleries SET title = ? WHERE id = ?',
            )->execute(['C1 renamed title', $firstId]);
            $response = c1_request($origin,
                'page=app_linking_galleries&tree=1&revision=' . $before,
                $token);
            c1_observe($records, 'mutation_title', $population, $response);
            $tree = c1_complete_tree($response, $population);
            if (hash_equals($before, $tree['revision'])) {
                throw new RuntimeException('C1 title change kept old revision.');
            }
            $renamed = false;
            foreach ($tree['galleries'] as $gallery) {
                $renamed = $renamed || ($gallery['id'] === $firstId
                    && $gallery['title'] === 'C1 renamed title');
            }
            if (!$renamed) {
                throw new RuntimeException('C1 title mutation body was stale.');
            }
            $revision = $tree['revision'];
            $before = $revision;
            $database->prepare(
                'UPDATE galleries SET parent_id = ? WHERE id = ?',
            )->execute([$secondId, $firstId]);
            $response = c1_request($origin,
                'page=app_linking_galleries&tree=1&revision=' . $before,
                $token);
            c1_observe($records, 'mutation_parent', $population, $response);
            $tree = c1_complete_tree($response, $population);
            if (hash_equals($before, $tree['revision'])) {
                throw new RuntimeException('C1 parent move kept old revision.');
            }
            $moved = false;
            foreach ($tree['galleries'] as $gallery) {
                $moved = $moved || ($gallery['id'] === $firstId
                    && $gallery['parent_id'] === $secondId);
            }
            if (!$moved) {
                throw new RuntimeException('C1 parent mutation body was stale.');
            }
            $revision = $tree['revision'];
            $before = $revision;
            $database->prepare(
                "UPDATE galleries SET visibility = 'public' WHERE id = ?",
            )->execute([$firstId]);
            $response = c1_request($origin,
                'page=app_linking_galleries&tree=1&revision=' . $before,
                $token);
            c1_observe($records, 'mutation_visibility_only', $population, $response);
            $data = json_decode($response['body'], true,
                C1_HTTP_LIMITS['json_depth'], JSON_THROW_ON_ERROR);
            if ($response['status'] !== 200 || !is_array($data)
                || ($data['unchanged'] ?? null) !== true
                || ($data['revision'] ?? null) !== $before
                || array_key_exists('galleries', $data)) {
                throw new RuntimeException(
                    'C1 visibility-only change altered tree revision.',
                );
            }
            $database->prepare(
                "UPDATE galleries SET visibility = 'private' WHERE id = ?",
            )->execute([$firstId]);
            $before = $revision;
            $database->prepare(
                'UPDATE galleries SET title = ? WHERE id = ?',
            )->execute(['C1 added row', $secondId]);
            $insertedId = c1_insert_one($database, 'C1 inserted row');
            $response = c1_request($origin,
                'page=app_linking_galleries&tree=1&revision=' . $before,
                $token);
            c1_observe($records, 'mutation_insert', $population + 1,
                $response);
            $tree = c1_complete_tree($response, $population + 1);
            if (hash_equals($before, $tree['revision'])) {
                throw new RuntimeException('C1 gallery insertion kept revision.');
            }
            if (!in_array($insertedId,
                array_column($tree['galleries'], 'id'), true)) {
                throw new RuntimeException('C1 inserted gallery was absent.');
            }
            $before = $tree['revision'];
            $database->prepare('DELETE FROM galleries WHERE id = ?')
                ->execute([$insertedId]);
            $response = c1_request($origin,
                'page=app_linking_galleries&tree=1&revision=' . $before,
                $token);
            c1_observe($records, 'mutation_delete', $population, $response);
            $tree = c1_complete_tree($response, $population);
            if (hash_equals($before, $tree['revision'])) {
                throw new RuntimeException('C1 gallery deletion kept revision.');
            }
            if (in_array($insertedId,
                array_column($tree['galleries'], 'id'), true)) {
                throw new RuntimeException('C1 deleted gallery remained present.');
            }
            $revision = $tree['revision'];
            $exactScopeToken = c1_issue_grant(
                $database, $userId, $origin, 'link:identity galleries:list',
            );
            $scopeResponse = c1_request(
                $origin,
                'page=app_linking_galleries&tree=1&revision=' . $revision,
                $exactScopeToken,
            );
            c1_observe($records, 'authority_exact_gallery_scope',
                $population, $scopeResponse);
            $scopeTree = c1_complete_tree($scopeResponse, $population);
            if (hash_equals($revision, $scopeTree['revision'])) {
                throw new RuntimeException(
                    'C1 exact scope was omitted from the tree revision.',
                );
            }
            $scopeUnchanged = c1_request(
                $origin,
                'page=app_linking_galleries&tree=1&revision='
                    . $scopeTree['revision'],
                $exactScopeToken,
            );
            c1_observe($records, 'authority_exact_scope_unchanged',
                $population, $scopeUnchanged);
            $scopeData = json_decode(
                $scopeUnchanged['body'], true,
                C1_HTTP_LIMITS['json_depth'], JSON_THROW_ON_ERROR,
            );
            if ($scopeUnchanged['status'] !== 200
                || !is_array($scopeData)
                || ($scopeData['unchanged'] ?? null) !== true
                || ($scopeData['revision'] ?? null)
                    !== $scopeTree['revision']
                || array_key_exists('galleries', $scopeData)) {
                throw new RuntimeException(
                    'C1 exact-scope unchanged proof failed.',
                );
            }
        }
    }

    $accountToken = c1_issue_grant(
        $database, $userId, $origin,
        'link:identity galleries:list media:read',
    );
    $accountProbe = c1_request(
        $origin, 'page=app_linking_galleries&tree=1', $accountToken,
    );
    c1_observe($records, 'authority_live_account',
        C1_TREE_POPULATIONS[count(C1_TREE_POPULATIONS) - 1],
        $accountProbe);
    c1_complete_tree(
        $accountProbe,
        C1_TREE_POPULATIONS[count(C1_TREE_POPULATIONS) - 1],
    );

    c1_seed_to(
        $database, C1_HTTP_LIMITS['row_overflow'], $baseline,
        'C1 Synthetic',
    );
    $rowOverflow = c1_request(
        $origin, 'page=app_linking_galleries&tree=1', $token,
    );
    c1_observe($records, 'row_overflow_tree_refused',
        C1_HTTP_LIMITS['row_overflow'], $rowOverflow);
    c1_expect_overflow($rowOverflow, 'row');
    c1_verify_two_legacy_pages($origin, $token, 'row_overflow', $records);
    $clientProofs[] = c1_client_run(
        $fixture, 'overflow-row', $token,
        'link:identity galleries:list media:read',
    );

    $database->exec(
        "DELETE FROM galleries WHERE folder_path LIKE '"
            . C1_SYNTHETIC_PATH_PATTERN . "' ESCAPE '!'",
    );
    c1_seed_to(
        $database, C1_HTTP_LIMITS['byte_population'], $baseline,
        'C1 Synthetic',
    );
    $largeTitle = str_repeat(
        '🚀', C1_HTTP_LIMITS['title_characters'],
    );
    $database->prepare(
        "UPDATE galleries SET title = ? WHERE folder_path LIKE '"
            . C1_SYNTHETIC_PATH_PATTERN . "' ESCAPE '!'",
    )->execute([$largeTitle]);
    $byteOverflow = c1_request(
        $origin, 'page=app_linking_galleries&tree=1', $token,
    );
    c1_observe($records, 'byte_overflow_tree_refused',
        C1_HTTP_LIMITS['byte_population'], $byteOverflow);
    c1_expect_overflow($byteOverflow, 'byte');
    c1_verify_two_legacy_pages($origin, $token, 'byte_overflow', $records);
    $clientProofs[] = c1_client_run(
        $fixture, 'overflow-byte', $token,
        'link:identity galleries:list media:read',
    );

    $wrongScopeToken = c1_issue_grant(
        $database, $userId, $origin,
        'link:identity galleries:list media:read',
    );
    $wrongScopeHash = \Gallery\Services\app_linking_access_token_hash(
        $wrongScopeToken,
    );
    if ($wrongScopeHash === null) {
        throw new RuntimeException('C1 scope-change grant was unavailable.');
    }
    $scopeDowngrade = $database->prepare(
        'UPDATE app_link_grants SET scope = ? WHERE access_token_hash = ?',
    );
    $scopeDowngrade->execute(['link:identity', $wrongScopeHash]);
    if ($scopeDowngrade->rowCount() !== 1) {
        throw new RuntimeException('C1 grant scope change was not applied.');
    }
    // The connected client keeps its prior galleries:list consent snapshot;
    // only the fixture's current server grant has been downgraded.
    $wrongScope = c1_request(
        $origin, 'page=app_linking_galleries&tree=1', $wrongScopeToken,
    );
    c1_observe($records, 'authority_wrong_scope', 0, $wrongScope);
    if ($wrongScope['status'] !== 403 || $wrongScope['server_timing'] !== null) {
        throw new RuntimeException('C1 insufficient scope was not denied.');
    }
    $clientProofs[] = c1_client_run(
        $fixture, 'denied-403', $wrongScopeToken,
        'link:identity galleries:list media:read', null, 403,
    );
    $wrongIssuerToken = c1_issue_grant(
        $database, $userId, $origin . '/wrong',
        'link:identity galleries:list media:read',
    );
    $wrongIssuer = c1_request(
        $origin, 'page=app_linking_galleries&tree=1', $wrongIssuerToken,
    );
    c1_observe($records, 'authority_wrong_issuer', 0, $wrongIssuer);
    if ($wrongIssuer['status'] !== 401 || $wrongIssuer['server_timing'] !== null) {
        throw new RuntimeException('C1 issuer mismatch was not denied.');
    }
    $clientProofs[] = c1_client_run(
        $fixture, 'denied-401', $wrongIssuerToken,
        'link:identity galleries:list media:read', null, 401,
    );
    $revokedRows = $database->prepare(
        'UPDATE app_link_grants SET revoked_at = UTC_TIMESTAMP(6) '
            . 'WHERE access_token_hash = ?',
    );
    $revokedRows->execute([$tokenHash]);
    if ($revokedRows->rowCount() !== 1) {
        throw new RuntimeException('C1 grant revocation was not applied.');
    }
    $revoked = c1_request(
        $origin, 'page=app_linking_galleries&tree=1', $token,
    );
    c1_observe($records, 'authority_revoked_grant', 0, $revoked);
    if ($revoked['status'] !== 401 || $revoked['server_timing'] !== null) {
        throw new RuntimeException('C1 revoked grant was not denied.');
    }
    $database->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
    $deletedAccount = c1_request(
        $origin, 'page=app_linking_galleries&tree=1', $accountToken,
    );
    c1_observe($records, 'authority_account_removed', 0, $deletedAccount);
    if ($deletedAccount['status'] !== 401
        || $deletedAccount['server_timing'] !== null) {
        throw new RuntimeException('C1 account-removal denial failed.');
    }
    return c1_report($records, $clientProofs, $baseline);
}

/**
 * Run and validate one real LinkingSession proof in a private child environment.
 *
 * @param \GalleryWorkflow\Fixture $fixture Owned disposable app and DB.
 * @param string $scenario One fixed Python allowlisted C1 client scenario.
 * @param string $token Synthetic bearer held in the private child environment.
 * @param string $scope Exact approved scope bound to the bearer.
 * @param int|null $rows Expected population for a full-tree proof, or null.
 * @param int|null $expectedStatus Required 401/403 denial, or null otherwise.
 * @return C1ClientProof Sanitized child proof bound to this workflow run.
 * @throws RuntimeException When process, provenance or child evidence is invalid.
 */
function c1_client_run(
    \GalleryWorkflow\Fixture $fixture,
    string $scenario,
    string $token,
    string $scope,
    ?int $rows = null,
    ?int $expectedStatus = null,
): array {
    if (!in_array($scenario, [
        'full', 'overflow-row', 'overflow-byte', 'denied-401', 'denied-403',
    ], true)) {
        throw new RuntimeException('C1 client scenario is not allowlisted.');
    }
    if (($scenario === 'full' && !in_array(
        $rows, C1_TREE_POPULATIONS, true,
    )) || (str_starts_with($scenario, 'denied-')
        && !in_array($expectedStatus, [401, 403], true))) {
        throw new RuntimeException('C1 client selector is invalid.');
    }
    $GLOBALS['c1_last_case'] = 'client_' . $scenario;
    $environment = [
        'C1_CLIENT_ISSUER' => 'http://localhost:'
            . substr($fixture->url, strlen('http://127.0.0.1:')),
        'C1_CLIENT_BEARER' => $token,
        'C1_CLIENT_SCOPE' => $scope,
    ];
    if ($rows !== null) {
        $environment['C1_CLIENT_ROWS'] = (string) $rows;
    }
    if ($expectedStatus !== null) {
        $environment['C1_CLIENT_EXPECT_STATUS'] = (string) $expectedStatus;
    }
    $previous = [];
    foreach ($environment as $name => $_value) {
        $previous[$name] = getenv($name);
        if ($previous[$name] !== false) {
            throw new RuntimeException('C1 child environment collision.');
        }
    }
    $root = dirname(__DIR__, 2);
    $suffix = $scenario === 'full' ? '-' . (string) $rows : '';
    $reportPath = $root . '/cache/test-audit/issue-362-c1-connected-'
        . $scenario . $suffix . '.json';
    @unlink($reportPath);
    try {
        foreach ($environment as $name => $value) {
            putenv($name . '=' . $value);
        }
        $fixture->run(
            ['python3', __DIR__ . '/issue_362_c1_connected.py', $scenario],
            C1_CLIENT_TIMEOUT_SECONDS,
            'C1 real LinkingSession ' . $scenario,
            true,
        );
    } finally {
        foreach ($environment as $name => $_value) {
            if ($previous[$name] === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $previous[$name]);
            }
        }
    }
    $report = is_file($reportPath)
        ? json_decode((string) file_get_contents($reportPath), true) : null;
    if (!is_array($report)
        || ($report['schema'] ?? null) !== 'php-gallery-c1-connected-v1'
        || ($report['scenario'] ?? null) !== $scenario
        || ($report['status'] ?? null) !== 'PASS'
        || ($report['head_sha'] ?? null) !== getenv('GITHUB_SHA')
        || ($report['source_base'] ?? null)
            !== getenv('PHP_GALLERY_SOURCE_BASE')
        || ($report['run_id'] ?? null) !== getenv('GITHUB_RUN_ID')
        || !is_array($report['result'] ?? null)) {
        throw new RuntimeException('C1 connected report failed validation.');
    }
    $expectedKeys = match ($scenario) {
        'full' => [
            'identity_live', 'tree_complete', 'tree_rows',
            'unchanged_confirmed', 'unchanged_rows',
        ],
        'overflow-row', 'overflow-byte' => [
            'optional_tree_unavailable', 'fallback_page_count',
            'fallback_rows', 'cursor_advanced', 'duplicate_ids',
        ],
        'denied-401' => [
            'grant_rejected', 'session_expired', 'request_started',
            'caller_fallback_attempted',
        ],
        'denied-403' => [
            'refusal_status', 'caller_fallback_attempted',
        ],
        default => [],
    };
    if (array_keys($report['result']) !== $expectedKeys) {
        throw new RuntimeException('C1 connected result shape differed.');
    }
    foreach ($report['result'] as $value) {
        if (!is_bool($value) && !is_int($value)) {
            throw new RuntimeException('C1 connected result was not numeric.');
        }
    }
    $expectedResult = match ($scenario) {
        'full' => [
            'identity_live' => true,
            'tree_complete' => true,
            'tree_rows' => $rows,
            'unchanged_confirmed' => true,
            'unchanged_rows' => 0,
        ],
        'overflow-row', 'overflow-byte' => [
            'optional_tree_unavailable' => true,
            'fallback_page_count' => 2,
            'fallback_rows' => 2 * C1_HTTP_LIMITS['legacy_page_rows'],
            'cursor_advanced' => true,
            'duplicate_ids' => false,
        ],
        'denied-401' => [
            'grant_rejected' => true,
            'session_expired' => true,
            'request_started' => true,
            'caller_fallback_attempted' => false,
        ],
        'denied-403' => [
            'refusal_status' => $expectedStatus,
            'caller_fallback_attempted' => false,
        ],
        default => [],
    };
    if ($report['result'] !== $expectedResult) {
        throw new RuntimeException('C1 connected proof values differed.');
    }
    return [
        'scenario' => $scenario,
        'head_sha' => $report['head_sha'],
        'source_base' => $report['source_base'],
        'run_id' => $report['run_id'],
        'result' => $report['result'],
    ];
}

/**
 * Require the row- or byte-overflow response to be the fixed generic refusal.
 *
 * @param array{status:int,body:string,bytes:int,ttfb_ms:float,elapsed_ms:float,server_timing:?string} $response Owned loopback response.
 * @param 'row'|'byte' $kind Fixed overflow type for safe failure context.
 * @return void Requires HTTP 503 with the generic temporary-unavailable code.
 */
function c1_expect_overflow(array $response, string $kind): void
{
    $data = json_decode(
        $response['body'], true, C1_HTTP_LIMITS['json_depth'],
        JSON_THROW_ON_ERROR,
    );
    if ($response['status'] !== 503 || !is_array($data)
        || ($data['error'] ?? null) !== 'temporarily_unavailable') {
        throw new RuntimeException(
            'C1 ' . $kind . ' overflow was not safely refused.',
        );
    }
}

/**
 * Insert one C1 synthetic gallery and return its private in-process ID.
 *
 * @param PDO $database Verified disposable schema connection.
 * @param string $title Valid bounded gallery title.
 * @return int Newly generated gallery ID, never written to report output.
 */
function c1_insert_one(PDO $database, string $title): int
{
    $path = 'issue_362_c1_mutation_' . bin2hex(random_bytes(4));
    $statement = $database->prepare(
        'INSERT INTO galleries (folder_path, folder_path_hash, slug, title, '
            . 'visibility, created_at, updated_at) '
            . "VALUES (?, ?, ?, ?, 'private', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
    );
    $statement->execute([$path, hash('sha256', $path), $path, $title]);
    return (int) $database->lastInsertId();
}

/**
 * Issue a temporary token whose authority is stored only in the owned schema.
 *
 * @param PDO $database Verified fixture database connection.
 * @param int $userId Existing fixture Admin identifier.
 * @param string $issuer Exact fixture origin or deliberate mismatch value.
 * @param string $scope One fixed scope string for the authorization case.
 * @return string Synthetic bearer retained only in process memory.
 */
function c1_issue_grant(
    PDO $database,
    int $userId,
    string $issuer,
    string $scope,
): string {
    $token = \Gallery\Services\app_linking_access_token_generate();
    $hash = \Gallery\Services\app_linking_access_token_hash($token);
    if ($hash === null) {
        throw new RuntimeException('C1 authority token creation failed.');
    }
    $database->prepare(
        'INSERT INTO app_link_grants '
            . '(user_id, client_id, issuer, scope, created_at, '
            . 'access_token_hash, access_issued_at, access_expires_at) '
            . 'VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), '
            . 'DATE_ADD(UTC_TIMESTAMP(6), INTERVAL '
            . C1_GRANT_TTL_SECONDS . ' SECOND))',
    )->execute([
        $userId, 'php-gallery-winapp', $issuer, $scope, $hash,
    ]);
    return $token;
}

/**
 * Build the sanitized current-SHA evidence document.
 *
 * @param list<C1Observation> $records Existing safe samples.
 * @param list<C1ClientProof> $clientProofs Validated real-client proofs.
 * @param int $baseline Unmodified non-C1 gallery rows in the fixture.
 * @return C1Report Sanitized measurements, provenance and explicit limits.
 */
function c1_report(
    array $records,
    array $clientProofs,
    int $baseline,
): array {
    return [
        'schema' => 'php-gallery-c1-tree-http-v1',
        'status' => 'PASS',
        'started_utc' => (string) ($GLOBALS['c1_started_utc'] ?? 'UNKNOWN'),
        'ended_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        'head_sha' => getenv('GITHUB_SHA') ?: 'UNKNOWN',
        'source_base' => getenv('PHP_GALLERY_SOURCE_BASE') ?: 'UNKNOWN',
        'github_run_id' => getenv('GITHUB_RUN_ID') ?: 'UNKNOWN',
        'php_version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
        'scope' => 'current copied PHP source, disposable MariaDB and localhost HTTP',
        'fixture_baseline_rows' => $baseline,
        'populations' => C1_TREE_POPULATIONS,
        'trials_per_population' => C1_TREE_TRIALS,
        'measurements' => $records,
        'client_proofs' => $clientProofs,
        'timing_limits' => [
            'three observations do not establish percentile tails',
            'Server-Timing ends before the response body is transferred',
            'SQL duration is contained within model/tree work; do not sum or subtract phases',
            'missing Server-Timing values are unknown, not zero',
            'localhost PHP CLI does not model provider FPM, HTTPS or proxy behavior',
            'only the first two 50-row legacy pages are exercised after overflow',
        ],
    ];
}

$root = dirname(__DIR__, 2);
$fixture = null;
$failed = false;
$passiveProfileChanged = false;
$previousPassiveProfile = false;
$GLOBALS['c1_started_utc'] = gmdate('Y-m-d\TH:i:s\Z');
$target = $root . '/cache/test-audit/issue-362-c1-tree-http.json';
$partial = $root . '/cache/test-audit/issue-362-c1-tree-http-partial.json';
try {
    if (PHP_SAPI !== 'cli' || getenv('GITHUB_ACTIONS') !== 'true'
        || getenv('GALLERY_WORKFLOW_ENABLE') !== 'disposable-only') {
        throw new RuntimeException('C1 requires an owned hosted fixture.');
    }
    $sourceSha = (string) (getenv('GITHUB_SHA') ?: '');
    $sourceBase = (string) (getenv('PHP_GALLERY_SOURCE_BASE') ?: '');
    $githubRunId = (string) (getenv('GITHUB_RUN_ID') ?: '');
    if (preg_match('/\A[a-f0-9]{40}\z/iD', $sourceSha) !== 1
        || preg_match('/\A[a-f0-9]{40}\z/iD', $sourceBase) !== 1
        || preg_match('/\A[0-9]+\z/D', $githubRunId) !== 1) {
        throw new RuntimeException('C1 exact-run provenance is unavailable.');
    }
    $previousPassiveProfile = getenv(C1_PASSIVE_PROFILE_ENVIRONMENT);
    putenv(C1_PASSIVE_PROFILE_ENVIRONMENT . '=1');
    $passiveProfileChanged = true;
    $fixture = new \GalleryWorkflow\Fixture($root, getenv());
    $report = c1_run($fixture);
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0700, true);
    }
    file_put_contents($target,
        json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    @unlink($partial);
    echo "PASS C1 current-SHA gallery tree HTTP evidence\n";
} catch (Throwable $error) {
    $failed = true;
    fwrite(STDERR, 'FAIL C1 tree HTTP evidence ('
        . basename($error->getFile()) . ':' . $error->getLine() . ")\n");
    if (!is_dir(dirname($partial))) {
        mkdir(dirname($partial), 0700, true);
    }
    $partialReport = is_file($partial)
        ? json_decode((string) file_get_contents($partial), true) : null;
    if (!is_array($partialReport)) {
        $partialReport = [
            'schema' => 'php-gallery-c1-tree-http-v1-partial',
            'head_sha' => getenv('GITHUB_SHA') ?: 'UNKNOWN',
            'source_base' => getenv('PHP_GALLERY_SOURCE_BASE') ?: 'UNKNOWN',
            'github_run_id' => getenv('GITHUB_RUN_ID') ?: 'UNKNOWN',
            'measurements' => [],
        ];
    }
    $partialReport['failure_code'] = 'owner_exception';
    $partialReport['failure_location'] = basename($error->getFile())
        . ':' . $error->getLine();
    $partialReport['failure_case'] = $GLOBALS['c1_last_case'] ?? 'setup';
    file_put_contents($partial,
        json_encode($partialReport, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)
            . "\n");
} finally {
    if ($passiveProfileChanged) {
        if ($previousPassiveProfile === false) {
            putenv(C1_PASSIVE_PROFILE_ENVIRONMENT);
        } else {
            putenv(C1_PASSIVE_PROFILE_ENVIRONMENT . '=' . $previousPassiveProfile);
        }
    }
    if ($fixture instanceof \GalleryWorkflow\Fixture) {
        try {
            $fixture->close();
        } catch (Throwable) {
            fwrite(STDERR, "FAIL C1 fixture cleanup\n");
            $failed = true;
        }
    }
}
if ($failed) {
    if (is_file($target)) {
        unlink($target);
    }
    exit(1);
}
