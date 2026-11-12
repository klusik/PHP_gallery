<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tools/perf/issue_362_d_old_server.php
 * Module Type: Disposable HTTP Compatibility Qualification
 * Purpose: Run the current client against a real archived PHP 1.0.10 fixture.
 * Responsibilities:
 *   - Extract and seed the exact immutable historical server source.
 *   - Compare current session reads with actual historical single-route reads.
 *   - Retain only sanitized compatibility evidence.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/services/security_tokens.php';

/**
 * Run the reciprocal source compatibility cohort in its own disposable server.
 *
 * @param \GalleryWorkflow\Fixture $parentFixture Existing owned B2 fixture.
 * @return array{
 *   schema:string,client_source_commit:string,server_source_commit:string,
 *   server_version:string,assertions:string,batch_capability_advertised:bool,
 *   current_session_batch_enabled:bool,reference_mime_sha256_matches:bool,
 *   published_images:int,legacy_thumbnail_gets:int,batch_posts:int,
 *   routes:array<string,int>,statuses:array<int,int>
 * } Sanitized current-client to archived-server evidence.
 * @throws \Throwable Owned fixture, database or client qualification failed.
 */
function d_old_server_compatibility(
    \GalleryWorkflow\Fixture $parentFixture,
): array
{
    $sourceRoot = $parentFixture->directory . '/d-old-server-source';
    $parentFixture->run(
        ['python3', __DIR__ . '/issue_362_d_old_server_client.py', 'extract'],
        B3_CLIENT_TIMEOUT_SECONDS,
        'D archived PHP source extraction',
        true,
    );
    \GalleryWorkflow\check(
        is_file($sourceRoot . '/app/core-manifest.json')
            && is_file($sourceRoot . '/tests/support/gallery_workflow_seed.php'),
        'Archived PHP source closure is incomplete.',
    );
    $manifest = json_decode(
        (string) file_get_contents($sourceRoot . '/app/core-manifest.json'),
        true,
        B2_LIMITS['json_depth'],
        JSON_THROW_ON_ERROR,
    );
    \GalleryWorkflow\check(is_array($manifest)
        && ($manifest['version'] ?? null) === '1.0.10',
        'Archived PHP manifest version mismatch.');

    $fixture = new \GalleryWorkflow\Fixture($sourceRoot, getenv());
    try {
        $fixture->start();
        d_old_server_prepare_router($fixture);
        $origin = d_old_server_localhost_origin($fixture);
        d_old_server_set_base_url($fixture, $origin);
        $seed = json_decode(
            (string) file_get_contents($fixture->directory . '/seed.json'),
            true,
            B2_LIMITS['json_depth'],
            JSON_THROW_ON_ERROR,
        );
        \GalleryWorkflow\check(is_array($seed)
            && is_int($seed['root_id'] ?? null),
            'Archived server seed record is invalid.');
        $galleryId = $seed['root_id'];
        $db = \GalleryWorkflow\fixtureDatabase(
            $fixture->directory,
            $fixture->token,
        );
        $userId = $db->query(
            "SELECT id FROM users WHERE username = 'workflow_admin'",
        )->fetchColumn();
        \GalleryWorkflow\check((is_string($userId) || is_int($userId))
            && (int) $userId > 0,
            'Archived server fixture account is unavailable.');
        // The immutable seed creates two direct photos; no larger corpus is needed.
        $photoQuery = $db->prepare(
            'SELECT id FROM images WHERE gallery_id = ? ORDER BY id LIMIT 2',
        );
        $photoQuery->execute([$galleryId]);
        $photoIds = array_map(
            static fn(string|int $value): string => (string) $value,
            $photoQuery->fetchAll(PDO::FETCH_COLUMN),
        );
        \GalleryWorkflow\check(count($photoIds) === 2
            && $photoIds[0] !== $photoIds[1],
            'Archived server fixture photos are unavailable.');
        $bearer = \Gallery\Services\app_linking_access_token_generate();
        $grant = $db->prepare(
            'INSERT INTO app_link_grants '
                . '(user_id, client_id, issuer, scope, created_at, '
                . 'access_token_hash, access_issued_at, access_expires_at) '
                . 'VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), '
                . 'DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))',
        );
        $grant->execute([
            (int) $userId,
            'php-gallery-winapp',
            $origin,
            'link:identity galleries:list media:read',
            \Gallery\Services\app_linking_access_token_hash($bearer),
            B2_LIMITS['grant_seconds'],
        ]);
        $references = [];
        foreach ($photoIds as $photoId) {
            $response = b2_request(
                $origin,
                'page=app_linking_media&gallery_id=' . $galleryId
                    . '&kind=thumbnail&image_id=' . $photoId,
                'GET',
                $bearer,
            );
            \GalleryWorkflow\check($response['status'] === 200
                && isset($response['headers']['content-type'])
                && preg_match(
                    '/\Aimage\/[a-zA-Z0-9.+-]{1,64}\z/D',
                    $response['headers']['content-type'],
                ) === 1
                && $response['body'] !== '',
                'Archived server reference GET failed.');
            $references[] = [
                'mime' => $response['headers']['content-type'],
                'sha256' => hash('sha256', $response['body']),
            ];
        }
        $report = d_old_server_current_client(
            $fixture,
            $origin,
            $bearer,
            $galleryId,
            $photoIds,
            $references,
        );
        return $report;
    } finally {
        $fixture->close();
    }
}

/**
 * Keep the archived server's routing boundary loopback-aware like the B2 owner.
 *
 * @param \GalleryWorkflow\Fixture $fixture Started archived source fixture.
 * @return void The owned router supplies SERVER_ADDR for rate-policy checks.
 * @throws \Throwable The expected archived router entry is unavailable.
 */
function d_old_server_prepare_router(
    \GalleryWorkflow\Fixture $fixture,
): void
{
    $routerPath = $fixture->directory . '/router.php';
    $router = (string) file_get_contents($routerPath);
    $entry = "require __DIR__ . '/public/index.php';";
    \GalleryWorkflow\check(substr_count($router, $entry) === 1,
        'Unexpected archived fixture router.');
    $injection = <<<'PHP'
if (PHP_SAPI === 'cli-server' && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
    && getenv('GALLERY_WORKFLOW_ENABLE') === 'disposable-only'
    && !isset($_SERVER['SERVER_ADDR'])) {
    $_SERVER['SERVER_ADDR'] = '127.0.0.1';
}
PHP;
    file_put_contents(
        $routerPath,
        str_replace($entry, $injection . "\n" . $entry, $router),
    );
}

/**
 * Return the historical fixture origin with the hostname expected by WinApp.
 *
 * @param \GalleryWorkflow\Fixture $fixture Started archived source fixture.
 * @return string Canonical HTTP localhost origin for the owned port.
 * @throws \Throwable The fixture origin is not loopback.
 */
function d_old_server_localhost_origin(
    \GalleryWorkflow\Fixture $fixture,
): string
{
    \GalleryWorkflow\check(str_starts_with($fixture->url, 'http://127.0.0.1:'),
        'Unexpected archived fixture origin.');
    return 'http://localhost:'
        . substr($fixture->url, strlen('http://127.0.0.1:'));
}

/**
 * Set only the archived disposable application's canonical base URL.
 *
 * @param \GalleryWorkflow\Fixture $fixture Started archived source fixture.
 * @param string $origin Canonical loopback localhost origin.
 * @return void Persist the owned source copy's fixture configuration.
 * @throws \Throwable The fixture configuration or origin is invalid.
 */
function d_old_server_set_base_url(
    \GalleryWorkflow\Fixture $fixture,
    string $origin,
): void
{
    $configPath = $fixture->directory . '/config.php';
    $config = require $configPath;
    \GalleryWorkflow\check(is_array($config)
        && preg_match('/\Ahttp:\/\/localhost:[1-9][0-9]{0,4}\z/D', $origin) === 1,
        'Archived fixture configuration is invalid.');
    $config['base_url'] = $origin;
    file_put_contents($configPath, "<?php\nreturn "
        . var_export($config, true) . ";\n");
}

/**
 * Run the current production client and validate its sanitized report contract.
 *
 * @param \GalleryWorkflow\Fixture $fixture Isolated archived server fixture.
 * @param string $origin Exact fixture localhost origin.
 * @param string $bearer Public media grant kept in the child environment.
 * @param int $galleryId Owned archived gallery identifier.
 * @param list<string> $photoIds Two seeded direct-photo identifiers.
 * @param list<array{mime:string,sha256:string}> $references Actual old-server GET proofs.
 * @return array{
 *   schema:string,client_source_commit:string,server_source_commit:string,
 *   server_version:string,assertions:string,batch_capability_advertised:bool,
 *   current_session_batch_enabled:bool,reference_mime_sha256_matches:bool,
 *   published_images:int,legacy_thumbnail_gets:int,batch_posts:int,
 *   routes:array<string,int>,statuses:array<int,int>
 * } Validated sanitized compatibility evidence.
 * @throws \Throwable The child, report or contract validation failed.
 */
function d_old_server_current_client(
    \GalleryWorkflow\Fixture $fixture,
    string $origin,
    string $bearer,
    int $galleryId,
    array $photoIds,
    array $references,
): array
{
    $reportPath = $fixture->directory . '/issue-362-d-old-server.json';
    $childEnvironment = [
        'PHP_GALLERY_D_OLD_SERVER_ISSUER' => $origin,
        'PHP_GALLERY_D_OLD_SERVER_BEARER' => $bearer,
        'PHP_GALLERY_D_OLD_SERVER_GALLERY_ID' => (string) $galleryId,
        'PHP_GALLERY_D_OLD_SERVER_PHOTO_1' => $photoIds[0],
        'PHP_GALLERY_D_OLD_SERVER_PHOTO_2' => $photoIds[1],
        'PHP_GALLERY_D_OLD_SERVER_MIME_1' => $references[0]['mime'],
        'PHP_GALLERY_D_OLD_SERVER_MIME_2' => $references[1]['mime'],
        'PHP_GALLERY_D_OLD_SERVER_SHA256_1' => $references[0]['sha256'],
        'PHP_GALLERY_D_OLD_SERVER_SHA256_2' => $references[1]['sha256'],
        'PHP_GALLERY_D_OLD_SERVER_REPORT' => $reportPath,
    ];
    $previousEnvironment = [];
    foreach ($childEnvironment as $name => $_value) {
        $previousEnvironment[$name] = getenv($name);
        if ($previousEnvironment[$name] !== false) {
            throw new RuntimeException('Old-server child environment collision.');
        }
    }
    try {
        foreach ($childEnvironment as $name => $value) {
            putenv($name . '=' . $value);
        }
        $fixture->run(
            ['python3', __DIR__ . '/issue_362_d_old_server_client.py', 'client'],
            B3_CLIENT_TIMEOUT_SECONDS,
            'D current WinApp client against archived PHP',
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
    \GalleryWorkflow\check(is_string($reportText),
        'Archived server client report is missing.');
    $report = json_decode(
        $reportText,
        true,
        B2_LIMITS['json_depth'],
        JSON_THROW_ON_ERROR,
    );
    \GalleryWorkflow\check(is_array($report)
        && ($report['schema'] ?? null) === 'issue-362-d-old-server-v1'
        && ($report['client_source_commit'] ?? null)
            === (getenv('GITHUB_SHA') ?: null)
        && ($report['server_source_commit'] ?? null)
            === '1c1e2e9145cd92cb2f0ee731da5655c900d4d3e1'
        && ($report['server_version'] ?? null) === '1.0.10'
        && ($report['assertions'] ?? null) === 'passed'
        && ($report['batch_capability_advertised'] ?? null) === false
        && ($report['current_session_batch_enabled'] ?? null) === false
        && ($report['reference_mime_sha256_matches'] ?? null) === true
        && ($report['published_images'] ?? null) === 2
        && ($report['legacy_thumbnail_gets'] ?? null) === 2
        && ($report['batch_posts'] ?? null) === 0
        && is_array($report['routes'] ?? null)
        && ($report['routes']['app_linking_discovery'] ?? null) === 1
        && ($report['routes']['app_linking_identity'] ?? null) === 1
        && ($report['routes']['app_linking_galleries'] ?? null) === 1
        && ($report['routes']['app_linking_media'] ?? null) === 3
        && count($report['routes']) === 4
        && is_array($report['statuses'] ?? null)
        && ($report['statuses']['200'] ?? null) === 6
        && count($report['statuses']) === 1,
        'Archived server client report contract failed.');
    return $report;
}
