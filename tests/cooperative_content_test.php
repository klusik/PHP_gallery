<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_content_test.php
 * Module Type: Regression Test
 * Purpose: Exercise real public cooperative policy across isolated installations.
 * Responsibilities: Cover pagination, direct authority, media revocation and unavailable sources.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /** Resolve the disposable gallery root independently of derivative storage.
     * @param string $relative Fixture gallery folder name.
     * @return string Test-only source root.
     */
    function gallery_abs_path(string $relative): string { return $GLOBALS['content_gallery_dir']; }
}
namespace {
require_once __DIR__ . '/support/cooperative_exchange_fixture.php';
require_once dirname(__DIR__) . '/app/helpers_files.php';
require_once dirname(__DIR__) . '/app/services/thumbnail_sources.php';
require_once dirname(__DIR__) . '/app/models/images.php';
require_once dirname(__DIR__) . '/app/services/cooperative_content.php';
require_once dirname(__DIR__) . '/app/controllers/cooperative_content.php';
require_once dirname(__DIR__) . '/app/views/cooperative_gallery.php';
require_once dirname(__DIR__) . '/app/controllers/http_helpers.php';
use Gallery\Services as S;
use Gallery\Controllers as C;
exchange_fixture();
/** Advance only the disposable admission clock without sleeps or production bypasses.
 * @param string $groupId Fixture group with a completed transport.
 * @return void Clear elapsed admission spacing in the isolated database.
 */
function content_fixture_admission_elapsed(string $groupId): void
{
    $stored = S\cooperative_proposal_exchange_load($groupId);
    $group = $stored['group'];
    $group['exchange']['content_retry_at'] = time() - 1;
    S\cooperative_proposal_exchange_save($stored, $group);
}
exchange_friend('a', 'b');
exchange_friend('a', 'c');
exchange_friend('b', 'c');
$codes = [];
foreach (['a', 'b', 'c'] as $node) {
    exchange_node($node);
    $codes[$node] = S\cooperative_album_reference_code(1, true);
    $db = $GLOBALS['exchange_nodes'][$node]['db'];
    $db->exec("ALTER TABLE galleries ADD COLUMN folder_path TEXT DEFAULT 'fixture'");
    $db->exec('CREATE TABLE images (id INTEGER PRIMARY KEY, gallery_id INTEGER, filename TEXT, relative_path TEXT, relative_path_hash TEXT, visibility TEXT, nsfw_enabled INTEGER, thumbnail_source_identity_version INTEGER NOT NULL DEFAULT 1)');
    for ($i = 1; $i <= 19; $i++) {
        $db->prepare('INSERT INTO images (id, gallery_id, filename, relative_path, relative_path_hash, visibility, nsfw_enabled) VALUES (?, 1, ?, ?, ?, ?, ?)')->execute([$i, 'Photo ' . $i, $i . '.jpg', hash('sha256', $i . '.jpg'), $i === 18 ? 'private' : 'public', $i === 19 ? 1 : 0]);
    }
}
exchange_node('a');
$id = S\cooperative_id_generate();
$state = S\cooperative_proposal_compose($id, 1, $codes['b'] . "\n" . $codes['c'], true);
exchange_refuses(/** Public rendering cannot substitute for the local decision. @return array<string,mixed> Refused shell. */
    static fn() => S\cooperative_content_page($id), 'content_unauthorized');
foreach (['b', 'c'] as $node) { $state = S\cooperative_proposal_exchange_contact($id, $state['revision'], $GLOBALS['exchange_nodes'][$node]['id'], 'offer'); }
foreach (['a', 'b', 'c'] as $node) {
    exchange_node($node); $state = exchange_state($id);
    S\cooperative_proposal_exchange_decide($id, $state['revision'], $state['digest'], 'approved');
}

exchange_node('a');
exchange_refuses(/** Even unanimous stored decisions cannot publish participants before fresh activation. @return array<string,mixed> Refused shell. */
    static fn() => S\cooperative_content_page($id), 'content_unauthorized');
for ($step = 0; $step < 5; $step++) {
    $state = exchange_state($id);
    if ($state['sharing_active']) { break; }
    S\cooperative_proposal_synchronize($id, $state['revision']);
}
exchange_check(exchange_state($id)['sharing_active'], 'Initial public shell was not explicitly activated.');
$transport = &S\cooperative_content_transport_override();
$transport = /** Route actual catalog services between independent fixture databases.
 * @param string $base Stored paired base.
 * @param array<string,mixed> $message Exact peer request.
 * @param string $bearer Directed fixture system credential.
 * @return array<string,mixed> Actual correlated source response.
 */ static function(string $base, array $message, string $bearer): array {
    $caller = $GLOBALS['exchange_node'];
    $target = str_contains($base, 'b.example') ? 'b' : 'c';
    exchange_check($base === 'https://' . $target . '.example/gallery', 'Content target escaped registered origin.');
    exchange_node($target);
    try { return S\cooperative_content_export($message, $bearer); }
    finally { exchange_node($caller); }
};
exchange_node('a');
$b = $GLOBALS['exchange_nodes']['b']['id'];
$result = [];
for ($step = 0; $step < 12; $step++) {
    content_fixture_admission_elapsed($id);
    $result = S\cooperative_content_read($id, $b);
    if (!$result['pending']) { break; }
}
exchange_check(!$result['pending'] && count($result['catalog']['photos']) === 8, 'Automatic bounded activation did not reach a source page.');
exchange_check($result['base'] === 'https://b.example/gallery' && $result['catalog']['next_cursor'] !== null, 'Paired origin or cursor missing.');
$catalogJson = json_encode($result['catalog']);
exchange_check(!str_contains($catalogJson, 'pgc_') && !str_contains($catalogJson, 'gallery_id') && !str_contains($catalogJson, 'relative_path'), 'Catalog leaked credentials or raw rows.');
content_fixture_admission_elapsed($id);
$second = S\cooperative_content_read($id, $b, $result['catalog']['next_cursor']);
content_fixture_admission_elapsed($id);
$last = S\cooperative_content_read($id, $b, $second['catalog']['next_cursor']);
exchange_check(count($second['catalog']['photos']) === 8 && count($last['catalog']['photos']) === 1 && $last['catalog']['next_cursor'] === null, 'Pagination exported private or NSFW media.');
$model = C\cooperative_content_catalog_model($result, $id, $b, C\cooperative_content_labels());
exchange_check(str_starts_with($model['photos'][0]['src'], 'https://b.example/gallery/index.php?page=cooperative_media&'), 'Client accepted an arbitrary media target.');
ob_start(); \Gallery\Views\view_cooperative_source($model); $html = ob_get_clean();
exchange_check(str_contains($html, '<img ') && str_contains($html, 'referrerpolicy="no-referrer"') && str_contains($html, 'data-cooperative-more'), 'No-JS semantic source rendering failed.');
exchange_node('b');
$group = S\cooperative_proposal_exchange_load($id)['group'];
$gallery = S\cooperative_proposal_local_member(S\cooperative_proposal_document($group)['body']);
$a = $GLOBALS['exchange_nodes']['a']['id'];
$ticket = $result['catalog']['photos'][0]['ticket'];
$GLOBALS['content_gallery_dir'] = sys_get_temp_dir() . '/gallery-cooperative-' . bin2hex(random_bytes(6));
mkdir($GLOBALS['content_gallery_dir']);
$GLOBALS['content_dir'] = $GLOBALS['content_gallery_dir'] . '/thumbs';
mkdir($GLOBALS['content_dir']);
$image = \Gallery\Models\image_model_find_by_id(1);
$localGallery = \Gallery\Models\gallery_model_find_by_id(1);
$file = S\thumbnail_abs_path($image, $localGallery, 600, 'jpg');
$fixtureImage = imagecreatetruecolor(2, 2);
ob_start(); imagejpeg($fixtureImage); $fixtureBytes = (string) ob_get_clean(); imagedestroy($fixtureImage);
file_put_contents($file, $fixtureBytes);
try {
    $media = S\cooperative_content_media($ticket, 'thumbnail');
    exchange_check($media['mime'] === 'image/jpeg' && getimagesizefromstring($media['bytes']) !== false, 'Authorized derivative unavailable.');
    exchange_check(S\cooperative_content_media($ticket, 'preview')['bytes'] === $media['bytes'], 'Preview fallback to an existing thumbnail failed.');
    exchange_refuses(/** No original scope is implemented by the media endpoint. @return array<string,mixed> Refused original. */
        static fn() => S\cooperative_content_media($ticket, 'original'), 'content_unauthorized');
    exchange_refuses(/** A modified capability cannot choose another file. @return array<string,mixed> Refused media. */
        static fn() => S\cooperative_content_media('X' . substr($ticket, 1), 'thumbnail'), 'content_unauthorized');

    $expired = S\cooperative_content_open($ticket, 'media');
    $expired['expires'] = time() - 1;
    $expired = S\cooperative_content_seal($expired, 'media');
    exchange_refuses(/** Renewal cannot revive an expired issued capability. @return array<string,mixed> Refused media. */
        static fn() => S\cooperative_content_media($expired, 'thumbnail'), 'content_unauthorized');
    $db = $GLOBALS['exchange_nodes']['b']['db'];
    // Existing unique photos keep their already generated files; no GET rewrites them.
    $db->exec("UPDATE images SET filename = '1.jpg', thumbnail_source_identity_version = 0 WHERE id = 1");
    S\thumbnail_legacy_identity_cache_clear();
    $legacyFile = $GLOBALS['content_dir'] . '/1_thumb600.jpg';
    file_put_contents($legacyFile, $fixtureBytes);
    try {
        $legacyHash = hash_file('sha256', $legacyFile);
        exchange_check(S\cooperative_content_media($ticket, 'thumbnail')['bytes'] === $media['bytes']
            && hash_file('sha256', $legacyFile) === $legacyHash, 'Existing thumbnail was refused or rewritten.');
        $db->exec("UPDATE images SET filename = '1.png', thumbnail_source_identity_version = 0 WHERE id = 18");
        S\thumbnail_legacy_identity_cache_clear();
        exchange_refuses(/** Private legacy siblings cannot own the same exported derivative.
         * @return array<string,mixed> Refused ambiguous derivative.
         */ static fn() => S\cooperative_content_media($ticket, 'thumbnail'), 'content_unavailable');
    } finally {
        unlink($legacyFile);
        $db->exec("UPDATE images SET filename = 'Photo 1', thumbnail_source_identity_version = 1 WHERE id = 1");
        $db->exec("UPDATE images SET filename = 'Photo 18', thumbnail_source_identity_version = 1 WHERE id = 18");
        S\thumbnail_legacy_identity_cache_clear();
    }
    foreach (["UPDATE images SET visibility = 'private' WHERE id = 1", 'UPDATE images SET nsfw_enabled = 1 WHERE id = 1',
        "UPDATE galleries SET access_mode = 'password' WHERE id = 1"] as $sql) {
        $db->exec($sql);
        exchange_refuses(/** Existing tickets immediately respect changed source policy. @return array<string,mixed> Refused media. */
            static fn() => S\cooperative_content_media($ticket, 'thumbnail'), 'content_unauthorized');
        $db->exec("UPDATE images SET visibility = 'public', nsfw_enabled = 0 WHERE id = 1");
        $db->exec("UPDATE galleries SET access_mode = 'normal' WHERE id = 1");
    }
    S\schema_inspection_set_query_executor_for_tests(/** Unknown policy storage refuses old tickets. @return never Fixture failure. */
        static fn() => throw new RuntimeException('private database exception'));
    exchange_refuses(/** Unknown storage cannot disclose bytes. @return array<string,mixed> Refused media. */
        static fn() => S\cooperative_content_media($ticket, 'thumbnail'), 'content_unauthorized');
    S\schema_inspection_set_query_executor_for_tests(/** Restore fixture schema. @return bool Verified fixture. */ static fn(): bool => true);
    S\cooperative_peer_revoke($a);
    exchange_refuses(/** Directed friendship revocation closes old tickets immediately. @return array<string,mixed> Refused media. */
        static fn() => S\cooperative_content_media($ticket, 'thumbnail'), 'content_unauthorized');
} finally { unlink($file); rmdir($GLOBALS['content_dir']); rmdir($GLOBALS['content_gallery_dir']); }
exchange_node('a');
content_fixture_admission_elapsed($id);
exchange_refuses(/** A failed remote source is surfaced without a credential-bearing error. @return array<string,mixed> Refused page. */
    static fn() => S\cooperative_content_read($id, $b), 'peer_unavailable');
$GLOBALS['exchange_enabled'] = false;
$before = $GLOBALS['exchange_db_calls'];
exchange_check(S\cooperative_content_gallery_groups(1) === [] && $GLOBALS['exchange_db_calls'] === $before, 'OFF path probed optional storage.');
exchange_refuses(/** Capability OFF rejects media before optional storage. @return array<string,mixed> Refused media. */
    static fn() => S\cooperative_content_media($ticket, 'thumbnail'), 'feature_disabled');

exchange_check(C\public_schema_unavailable_response_format('cooperative_media') === 'text'
    && C\public_schema_unavailable_response_format('cooperative_content_api') === 'json', 'Cooperative schema failure used the wrong representation.');
$_GET['fragment'] = '1';
exchange_check(C\public_schema_unavailable_response_format('cooperative_gallery') === 'json', 'Fragment schema failure returned HTML.');
echo "PASS cooperative public content, pagination and media policy\n";

}
