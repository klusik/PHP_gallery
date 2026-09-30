<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_album_sources_test.php
 * Module Type: Regression Test
 * Purpose: Verify local album selection cannot bypass public source restrictions.
 * Responsibilities: Cover inherited protection, stale policy, schema refusal, pagination and Admin boundaries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Return only the disposable fixture database.
     * @return \PDO Isolated SQLite connection; production configuration is never loaded.
     */
    function db(): \PDO
    {
        $GLOBALS['source_db_calls']++;
        return $GLOBALS['source_db'];
    }
    /** Enforce the fixture administrator boundary.
     * @return void Refuse anonymous requests before any storage query.
     */
    function require_admin(): void
    {
        if (!$GLOBALS['source_admin']) {
            throw new \RuntimeException('admin_required');
        }
    }
    /** Read the explicit fixture request method.
     * @return string Current test request method.
     */
    function request_method(): string { return $_SERVER['REQUEST_METHOD'] ?? 'GET'; }
}
namespace Gallery\Services {
    /** Resolve only the tested optional capability.
     * @param string $key Requested capability key.
     * @return bool Whether cooperative galleries are enabled in this fixture.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return $key === 'cooperative_galleries' && $GLOBALS['source_enabled'];
    }
}
namespace {
    use Gallery\Services as S;
    require_once dirname(__DIR__) . '/app/services/schema_inspection.php';
    require_once dirname(__DIR__) . '/app/services/security_tokens.php';
    require_once dirname(__DIR__) . '/app/services/gallery_access.php';
    require_once dirname(__DIR__) . '/app/models/galleries.php';
    require_once dirname(__DIR__) . '/app/models/cooperative_galleries.php';
    require_once dirname(__DIR__) . '/app/services/cooperative_galleries.php';
    require_once dirname(__DIR__) . '/app/services/cooperative_pairing.php';
    require_once dirname(__DIR__) . '/app/controllers/cooperative_pairing.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_cooperative_albums.php';

    /** Require one source selection invariant.
     * @param bool $condition Observed assertion result.
     * @param string $message Bounded diagnostic for a failed fixture expectation.
     * @return void Throw on a regression.
     */
    function source_check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }
    /** Require a specific refusal from member preparation.
     * @param int $id Local fixture gallery identity.
     * @param string $reason Expected bounded domain error.
     * @param list<string> $scopes Requested proposal scopes.
     * @return void Fail when identity preparation grants invalid authority.
     */
    function source_refuses(int $id, string $reason, array $scopes = ['metadata']): void
    {
        try {
            S\cooperative_album_source_member($id, $scopes);
        } catch (S\CooperativeException $error) {
            source_check($error->reason === $reason, 'Unexpected source refusal: ' . $error->reason);
            return;
        }
        throw new \RuntimeException('Expected source refusal: ' . $reason);
    }
    /** Capture the actual controller response without a web server.
     * @return array<string,mixed> Decoded private response body.
     */
    function source_http(): array
    {
        ob_start();
        try {
            \Gallery\Controllers\cms_admin_cooperative_albums();
            return json_decode((string) ob_get_contents(), true, 16, JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
    }
    /** Install bounded schema observations, including injected failures.
     * @param string $mode Available, missing, unknown or missing identity mapping store.
     * @return void Replace only the schema observer in this isolated process.
     */
    function source_schema(string $mode): void
    {
        S\schema_inspection_set_query_executor_for_tests(
            /** Observe fixture schema without production metadata queries.
             * @param string $type Schema object type.
             * @param string $table Schema table identifier.
             * @param string $object Schema column or index identifier.
             * @return bool Whether the object is present, unless observation fails.
             */
            static function (string $type, string $table, string $object) use ($mode): bool {
                $GLOBALS['source_schema_calls']++;
                if ($mode === 'unknown' && $table === 'galleries' && $object === 'access_mode') {
                    throw new \RuntimeException('private SQL diagnostic /secret/path');
                }
                if ($mode === 'missing' && $table === 'galleries' && $object === 'nsfw_enabled') {
                    return false;
                }
                if ($mode === 'mapping_missing' && $table === 'cooperative_albums') {
                    return false;
                }
                return true;
            }
        );
    }

    $GLOBALS['source_db_calls'] = 0;
    $GLOBALS['source_schema_calls'] = 0;
    $GLOBALS['source_enabled'] = true;
    $GLOBALS['source_admin'] = true;
    $db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
    $GLOBALS['source_db'] = $db;
    $db->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY, title TEXT, parent_id INTEGER,
        visibility TEXT DEFAULT "public", access_mode TEXT DEFAULT "normal", access_listing TEXT DEFAULT "listed",
        nsfw_enabled INTEGER DEFAULT 0, folder_path TEXT DEFAULT "private/path", access_password_hash TEXT DEFAULT "secret-password")');
    $db->exec('CREATE TABLE cooperative_identity (singleton_id INTEGER PRIMARY KEY, instance_id TEXT UNIQUE)');
    $db->exec('CREATE TABLE cooperative_albums (gallery_id INTEGER PRIMARY KEY, album_id TEXT UNIQUE)');
    $insert = $db->prepare('INSERT INTO galleries (id, title) VALUES (?, ?)');
    for ($id = 1; $id <= 51; $id++) {
        $insert->execute([$id, 'Album ' . $id]);
    }
    $db->exec('UPDATE galleries SET parent_id = 1 WHERE id = 2');
    source_schema('available');
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $response = source_http();
    source_check($response['ok'] && count($response['state']['items']) === 50 && $response['state']['next_cursor'] === 50, 'Source pagination lost its lookahead.');
    source_check($response['state']['items'][1]['eligible'], 'Normal nested public album was refused.');
    $_GET = ['after_id' => 50];
    $lastPage = source_http();
    source_check(count($lastPage['state']['items']) === 1 && $lastPage['state']['next_cursor'] === null, 'Final picker page has a phantom cursor.');
    $wire = json_encode($response, JSON_THROW_ON_ERROR);
    source_check(!str_contains($wire, 'private/path') && !str_contains($wire, 'secret-password') && !str_contains($wire, 'album_id'), 'Picker disclosed sensitive rows or issued public identities.');
    source_check((int) $db->query('SELECT COUNT(*) FROM cooperative_identity')->fetchColumn() === 0
        && (int) $db->query('SELECT COUNT(*) FROM cooperative_albums')->fetchColumn() === 0, 'Read-only picker mutated identities.');
    source_schema('mapping_missing');
    source_refuses(2, 'schema_missing');
    source_check((int) $db->query('SELECT COUNT(*) FROM cooperative_identity')->fetchColumn() === 0, 'Mapping preflight partially created installation identity.');
    source_schema('available');
    source_refuses(2, 'invalid_scopes', ['metadata', 'metadata']);
    source_refuses(2, 'invalid_scopes', ['original']);
    source_refuses(2, 'invalid_scopes', ['metadata', 'upload']);
    $member = S\cooperative_album_source_member(2, ['preview', 'metadata']);
    source_check($member === S\cooperative_album_source_member(2, ['metadata', 'preview']), 'Repeated preparation changed identity or semantic scopes.');
    source_check(array_keys($member) === ['instance_id', 'album_id', 'scopes'] && !isset($member['gallery_id']), 'Local identifier leaked into proposal membership.');
    source_check(S\cooperative_album_source_policy($member['album_id'])['allowed'], 'Valid local opaque identity did not resolve.');
    source_check(!S\cooperative_album_source_policy(str_repeat('f', 32))['allowed'], 'Unknown remote album resolved as a local source.');

    // Even a supposedly unlocked Admin session cannot weaken the export audience.
    $_SESSION = ['admin_id' => 1, 'nsfw_guard_adult_acknowledged' => time(), 'gallery_access_1' => time()];
    $_GET = ['share_token' => 'fixture-token'];
    foreach ([['visibility', 'private', 'not_public'], ['visibility', 'unpublished', 'not_public'],
        ['access_listing', 'unlisted', 'not_public'], ['access_mode', 'password', 'access_restricted'],
        ['access_mode', 'unexpected', 'access_restricted'], ['nsfw_enabled', 1, 'nsfw_restricted']] as [$column, $value, $reason]) {
        $db->prepare('UPDATE galleries SET ' . $column . ' = ? WHERE id = 1')->execute([$value]);
        source_refuses(2, $reason);
        source_check(!S\cooperative_album_source_policy($member['album_id'])['allowed'], 'Prepared identity bypassed a subsequent ancestor restriction.');
        $db->exec('UPDATE galleries SET visibility = "public", access_listing = "listed", access_mode = "normal", nsfw_enabled = 0 WHERE id = 1');
    }
    $db->exec('UPDATE galleries SET parent_id = 2 WHERE id = 1');
    source_refuses(2, 'invalid_hierarchy');
    $db->exec('UPDATE galleries SET parent_id = 999 WHERE id = 1');
    source_refuses(2, 'invalid_hierarchy');
    $db->exec('UPDATE galleries SET parent_id = NULL WHERE id = 1');
    source_refuses(999, 'gallery_missing');
    source_refuses(0, 'invalid_gallery');
    $db->exec('DELETE FROM galleries WHERE id = 2');
    source_check(!S\cooperative_album_source_policy($member['album_id'])['allowed'], 'Deleted album retained source access.');

    foreach (['missing', 'unknown'] as $mode) {
        source_schema($mode);
        $before = $GLOBALS['source_db_calls'];
        source_refuses(1, 'schema_' . $mode);
        $_GET = [];
        $error = source_http();
        source_check(!$error['ok'] && $error['error_code'] === 'schema_' . $mode && http_response_code() === 503, 'Schema refusal lost its typed HTTP boundary.');
        source_check($GLOBALS['source_db_calls'] === $before && !str_contains(json_encode($error), 'secret'), 'Schema refusal queried sources or disclosed raw diagnostics.');
    }
    source_schema('available');
    $GLOBALS['source_enabled'] = false;
    $before = [$GLOBALS['source_db_calls'], $GLOBALS['source_schema_calls']];
    $disabled = source_http();
    source_check(!$disabled['ok'] && http_response_code() === 404
        && $before === [$GLOBALS['source_db_calls'], $GLOBALS['source_schema_calls']], 'Disabled picker touched storage.');
    $GLOBALS['source_enabled'] = true;
    $_GET = ['after_id' => ['invalid']];
    source_check(source_http()['error_code'] === 'invalid_cursor', 'Array cursor was coerced.');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    source_check(source_http()['error_code'] === 'method_not_allowed' && http_response_code() === 405, 'Read-only route accepted a mutation.');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $GLOBALS['source_admin'] = false;
    $before = $GLOBALS['source_db_calls'];
    try {
        source_http();
        throw new \RuntimeException('Anonymous source selector accepted.');
    } catch (\RuntimeException $error) {
        source_check($error->getMessage() === 'admin_required' && $before === $GLOBALS['source_db_calls'], 'Anonymous request reached source data.');
    }
    echo "PASS cooperative album sources\n";
}
