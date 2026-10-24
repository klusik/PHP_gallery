<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/presentation_links_test.php
 * Module Type: Regression Test
 * Purpose: Verify immutable gallery-scoped short presentation persistence.
 * Responsibilities: Exercise deduplication, collisions, scope and schema refusal.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Supply isolated real SQLite persistence without installation data.
     * @return \PDO In-memory snapshot store shared by the production models.
     */
    function db(): \PDO
    {
        return $GLOBALS['presentation_link_fixture_db'];
    }

    /**
     * Supply language and mount configuration without installation secrets.
     * @return array{language:array{default:string},base_url:string} Fixture settings.
     */
    function cms_config(): array
    {
        return [
            'language' => ['default' => 'en'],
            'base_url' => 'https://gallery.test/Galerie',
        ];
    }
}

namespace Gallery\Services {
    /**
     * Select the route-mode branch without modifying application settings.
     * @return bool Whether this URL vector uses the clean root path.
     */
    function url_rewrite_should_emit_clean_urls(): bool
    {
        return $GLOBALS['presentation_link_fixture_clean'] ?? true;
    }

    /**
     * Capture bounded schema diagnostics without the installation log writer.
     * @param string $level Safe log level.
     * @param string $eventKey Stable schema event identifier.
     * @param string $message Generic diagnostic message.
     * @param array<string,string|int|list<string>> $context Redacted schema fields.
     * @param array<string,string|int|bool> $options Log writer options.
     * @return void Record only the event key for this schema-refusal fixture.
     */
    function admin_log_event(
        string $level,
        string $eventKey,
        string $message,
        array $context = [],
        array $options = [],
    ): void {
        $GLOBALS['presentation_link_fixture_logs'][] = $eventKey;
    }
}

namespace {
    require_once __DIR__ . '/../app/models/galleries.php';
    require_once __DIR__ . '/../app/helpers_runtime.php';
    require_once __DIR__ . '/../app/helpers_request.php';
    require_once __DIR__ . '/../app/services/gallery_paths.php';
    require_once __DIR__ . '/../app/helpers_public_urls.php';
    require_once __DIR__ . '/../app/controllers/public_gallery_controls.php';
    require_once __DIR__ . '/../app/services/gallery_editor_mutations.php';
    require_once __DIR__ . '/../app/services/schema_inspection.php';
    require_once __DIR__ . '/../app/services/presentation_schema_policy.php';
    require_once __DIR__ . '/../app/services/translations.php';
    require_once __DIR__ . '/../app/services/gallery_lightbox_mode.php';
    require_once __DIR__ . '/../app/bootstrap/routing.php';
    require_once __DIR__ . '/../app/views/public_gallery_lightbox.php';

    use function Gallery\Services\presentation_link_create;
    use function Gallery\Services\presentation_link_resolve;
    use function Gallery\Services\presentation_link_snapshot;
    use function Gallery\Services\schema_inspection_set_query_executor_for_tests;

    /**
     * Fail with a concrete behavior mismatch from the isolated production owner.
     * @param bool $condition Whether the expected persistence behavior holds.
     * @param string $message Diagnostic identifying the violated contract.
     * @return void Continue on success or throw on a regression.
     */
    function presentation_link_assert(bool $condition, string $message): void
    {
        if (!$condition) throw new RuntimeException($message);
    }

    $_SERVER['SCRIPT_NAME'] = '/Galerie/index.php';
    $_SERVER['HTTP_HOST'] = 'gallery.test';
    $_SERVER['HTTPS'] = 'on';
    $connection = new PDO('sqlite::memory:');
    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $GLOBALS['presentation_link_fixture_db'] = $connection;
    $connection->exec('CREATE TABLE presentation_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL,
        fingerprint TEXT NOT NULL, gallery_kind TEXT NOT NULL,
        gallery_id INTEGER NOT NULL, settings_json TEXT NOT NULL,
        created_at TEXT NOT NULL)');
    $connection->exec('CREATE UNIQUE INDEX presentation_links_code
        ON presentation_links(code)');
    $connection->exec('CREATE UNIQUE INDEX presentation_links_fingerprint
        ON presentation_links(fingerprint)');
    schema_inspection_set_query_executor_for_tests(static fn(
        string $type, string $table, string $object, string $token = '',
    ): bool => true);

    $input = [
        'autoplay' => '1', 'music' => '0', 'fullscreen' => '1',
        'exclusive' => '0', 'interval' => 'slow', 'transition' => 'fast',
        'lang' => 'en', 'source_gallery_id' => '0',
    ];
    $snapshot = presentation_link_snapshot($input);
    ob_start();
    \Gallery\Views\view_render_presentation_snapshot('{"lang":"</script>"}');
    $metadata = (string) ob_get_clean();
    presentation_link_assert(str_contains($metadata, '&quot;lang&quot;')
        && !str_contains($metadata, '</script>'),
        'The shared lightbox view must escape metadata attributes.');
    $code = presentation_link_create('gallery', 10, $snapshot);
    presentation_link_assert(preg_match('/^[a-f0-9]{8}$/D', $code) === 1,
        'Stored codes must contain exactly eight lowercase hex characters.');
    presentation_link_assert(
        presentation_link_create('gallery', 10, presentation_link_snapshot(
            array_reverse($input, true),
        )) === $code,
        'Equivalent controls in a different key order must reuse one code.',
    );
    presentation_link_assert((int) $connection->query(
        'SELECT COUNT(*) FROM presentation_links',
    )->fetchColumn() === 1, 'Repeated sharing must not duplicate persistence.');
    presentation_link_assert(presentation_link_resolve($code, 'gallery', 10)
        === $snapshot, 'Stored snapshots must restore every explicit option.');
    presentation_link_assert(presentation_link_create('gallery', 11, $snapshot)
        !== $code, 'A different gallery must receive its own code.');
    $smartCode = presentation_link_create('smart', 10, $snapshot);
    presentation_link_assert($smartCode !== $code,
        'Smart and physical identities must never alias.');
    $physicalDestination = \Gallery\Services\presentation_link_destination($code);
    $smartDestination = \Gallery\Services\presentation_link_destination($smartCode);
    presentation_link_assert($physicalDestination['gallery_kind'] === 'gallery'
        && (int) $physicalDestination['gallery_id'] === 10
        && $smartDestination['gallery_kind'] === 'smart',
        'Root codes must discover their unique gallery namespace and identity.');
    presentation_link_assert(\Gallery\Core\presentation_public_url($code)
        === 'https://gallery.test/Galerie/presentation=' . $code,
        'Clean output must not include a gallery path or playback query.');
    $GLOBALS['presentation_link_fixture_clean'] = false;
    presentation_link_assert(\Gallery\Core\presentation_public_url($code)
        === 'https://gallery.test/Galerie/index.php?presentation=' . $code,
        'Non-rewrite output must contain only the presentation query key.');
    $GLOBALS['presentation_link_fixture_clean'] = true;
    $_GET = ['presentation' => $code];
    presentation_link_assert(\Gallery\Controllers\public_presentation_access_return_url(
        ['id' => 10, 'slug' => 'album'],
    ) === \Gallery\Core\presentation_public_url($code),
        'Password/age approval must return to the matching root presentation.');
    presentation_link_assert(\Gallery\Controllers\public_presentation_access_return_url(
        ['id' => 11, 'slug' => 'other'],
    ) === 'https://gallery.test/Galerie/gallery/other/',
        'An access return code must not redirect to another gallery.');
    $_GET = [];
    presentation_link_assert(presentation_link_resolve($code, 'gallery', 11)
        === null && presentation_link_resolve($code, 'smart', 10) === null,
        'A code copied to another gallery must not resolve.');
    foreach (['unknown', 'ABCDEF12', '123', '123456789', '<script>'] as $invalid) {
        presentation_link_assert(presentation_link_resolve($invalid, 'gallery', 10)
            === null, 'Malformed codes must be refused before storage lookup.');
    }
    presentation_link_assert(presentation_link_resolve('00000000', 'gallery', 10)
        === null, 'Unknown well-formed codes must not use gallery defaults.');

    // Reserve the exact next prefix for different content to force a collision.
    $collisionSnapshot = presentation_link_snapshot(['exclusive' => '1'] + $input);
    $collisionJson = json_encode($collisionSnapshot, JSON_UNESCAPED_SLASHES);
    $fingerprint = hash('sha256', 'gallery:12:' . $collisionJson);
    $prefix = substr($fingerprint, 0, 8); // Eight-character public wire format.
    \Gallery\Models\presentation_link_model_insert(
        $prefix, str_repeat('f', 64), 'gallery', 99, '{}', '2026-10-24 00:00:00',
    );
    $collisionCode = presentation_link_create('gallery', 12, $collisionSnapshot);
    presentation_link_assert($collisionCode !== $prefix,
        'A short-prefix collision must allocate another code.');
    presentation_link_assert(presentation_link_create('gallery', 12, $collisionSnapshot)
        === $collisionCode, 'A collided snapshot must reuse its allocated code.');
    presentation_link_assert(\Gallery\Models\presentation_link_model_find(
        $prefix, 'gallery', 99,
    )['settings_json'] === '{}', 'Collision handling must never overwrite a row.');
    presentation_link_assert(!\Gallery\Models\presentation_link_model_insert(
        'eeeeeeee', $fingerprint, 'gallery', 12, $collisionJson,
        '2026-10-24 00:00:00',
    ), 'An identical concurrent fingerprint must be refused by database UNIQUE.');

    foreach (['music' => 'yes', 'interval' => 'arbitrary', 'lang' => 'invalid',
        'source_gallery_id' => '-1'] as $key => $value) {
        try {
            presentation_link_snapshot([$key => $value] + $input);
            throw new RuntimeException('Invalid option was silently normalized: ' . $key);
        } catch (InvalidArgumentException) {
            // Each invalid vector must fail before any snapshot insert.
        }
    }
    $beforeRefusal = (int) $connection->query(
        'SELECT COUNT(*) FROM presentation_links',
    )->fetchColumn();
    foreach (['missing', 'unknown'] as $state) {
        schema_inspection_set_query_executor_for_tests(static function (
            string $type, string $table, string $object, string $token = '',
        ) use ($state): bool {
            if ($state === 'unknown') throw new RuntimeException('fixture outage');
            return false;
        });
        foreach (['create', 'resolve'] as $operation) {
            try {
                if ($operation === 'create') presentation_link_create('gallery', 20, $snapshot);
                else presentation_link_resolve($code, 'gallery', 10);
                throw new RuntimeException('Unavailable snapshot storage was accepted.');
            } catch (\Gallery\Services\PresentationSchemaUnavailableException $exception) {
                presentation_link_assert($exception->state() === $state,
                    'Missing and unknown schema must remain distinguishable.');
            }
        }
    }
    presentation_link_assert((int) $connection->query(
        'SELECT COUNT(*) FROM presentation_links',
    )->fetchColumn() === $beforeRefusal, 'Schema refusals must not write snapshots.');

    $_GET = [];
    $_SERVER['SCRIPT_NAME'] = '/Galerie/index.php';
    foreach ([$code, $smartCode] as $rootCode) {
        $_SERVER['REQUEST_URI'] = '/Galerie/presentation=' . $rootCode;
        $route = \Gallery\Core\cms_route_from_request();
        presentation_link_assert($route['page'] === 'gallery'
            && $route['params'] === ['presentation' => $rootCode],
            'Clean short URLs must be rooted at the mount without a gallery path.');
    }
    $_GET = ['presentation' => $code];
    $_SERVER['REQUEST_URI'] = '/Galerie/index.php?presentation=' . $code;
    presentation_link_assert(\Gallery\Core\cms_route_from_request()['page']
        === 'gallery', 'Non-rewrite root query links must use the same boundary.');
    foreach (['ae684832' => 'gallery-ae684832',
        'AE684832' => 'gallery-ae684832', 'p-ae684832' => 'gallery-p-ae684832',
        'presentation=ae684832' => 'gallery-presentation-ae684832',
        'Summer album' => 'summer-album'] as $value => $expected) {
        presentation_link_assert(\Gallery\Services\gallery_url_slug_base($value)
            === $expected, 'Gallery URL normalization must reserve presentation names.');
    }
    presentation_link_assert(\Gallery\Services\gallery_editor_unique_slug(
        'ae684832', 0, static fn(string $candidate): bool => false,
    ) === 'gallery-ae684832', 'The actual editor must reserve hash-like slugs.');

    // A public share POST must retain JSON security failures without loading
    // the optional Admin adapter. Also verify parity after that owner is loaded.
    require_once __DIR__ . '/../app/security.php';
    presentation_link_assert(!function_exists('Gallery\\Core\\admin_wants_json'),
        'The public security vector must start without the Admin mutation owner.');
    $_GET = [];
    $_POST = [];
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    presentation_link_assert(\Gallery\Core\security_request_wants_json(),
        'Public JSON sharing must be recognized without Admin helpers.');
    $_SERVER['HTTP_ACCEPT'] = 'text/html';
    presentation_link_assert(!\Gallery\Core\security_request_wants_json(),
        'Ordinary public forms must retain their non-JSON security response.');
    require_once __DIR__ . '/../app/helpers_mutation.php';
    presentation_link_assert(!\Gallery\Core\security_request_wants_json(),
        'Loading the Admin adapter must preserve ordinary form classification.');
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    presentation_link_assert(\Gallery\Core\security_request_wants_json(),
        'The loaded Admin adapter must retain the same JSON sharing response.');
    echo "Presentation snapshot persistence, collision, scope and schema contracts passed.\n";
}
