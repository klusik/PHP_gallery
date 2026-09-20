<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_operation_keys_http_test.php
 * Module Type: Regression Test
 * Purpose: Verify replay keys through real authenticated HTTP and disposable persistence.
 * Responsibilities:
 *   - Check original identities, multipart hashes, reauthentication and retained pending claims.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: Requires the parent-owned disposable runner; never loads checkout configuration.
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_http.php';
require_once __DIR__ . '/../app/policy_constants.php';

use GalleryWorkflow\Http;
use function GalleryWorkflow\check;
use function GalleryWorkflow\countRows;
use function GalleryWorkflow\envelope;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\row;
use function GalleryWorkflow\validateFixture;

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP') . " admin operation HTTP replay requires the disposable workflow runner\n";
    exit($required ? 1 : 0);
}

$stage = 'fixture validation';
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
    $pdo = fixtureDatabase($directory, $token);
    $seed = json_decode((string) file_get_contents($directory . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
    $origin = json_decode((string) file_get_contents($directory . '/endpoint.json'), true, 512, JSON_THROW_ON_ERROR)['url'];
    $admin = new Http($origin);
    $csrf = $admin->login($seed);
    $actor = (int) row($pdo, 'SELECT id FROM users WHERE username = ?', [$seed['username']])['id'];
    $createRoute = '/index.php?page=admin_new_gallery';
    $uploadRoute = '/index.php?page=admin_upload';
    $intent = 'Operation replay ' . bin2hex(random_bytes(6));
    $fields = ['csrf_token' => $csrf, 'title' => $intent, 'parent_id' => $seed['root_id'], 'visibility' => 'public', 'panel' => '1'];
    $before = countRows($pdo, 'galleries');

    $stage = 'mandatory key and current authorization';
    $missing = $admin->request($createRoute, $fields, true);
    check($missing['status'] === 409 && ($missing['json']['error_code'] ?? '') === 'operation_key_required', 'Missing create key was not refused.');
    $fields['operation_key'] = $admin->operationKey($createRoute . '&panel=1');
    $anonymous = new Http($origin);
    check($anonymous->request($createRoute, $fields, true)['status'] === 401, 'Operation key bypassed authentication.');
    check($admin->request($createRoute, array_replace($fields, ['csrf_token' => 'invalid']), true)['status'] === 400, 'Operation key bypassed CSRF.');
    check(!row($pdo, 'SELECT state FROM admin_operation_keys WHERE actor_id = ? AND key_hash = ?', [$actor, hash('sha256', $fields['operation_key'])]), 'Unauthorized attempt claimed an operation.');
    check(countRows($pdo, 'galleries') === $before, 'Refused request changed the catalog.');

    $stage = 'durable create replay and new intent';
    $created = envelope($admin->request($createRoute, $fields, true), 'Operation create');
    $id = (int) $created['gallery_id'];
    $ledger = row($pdo, 'SELECT * FROM admin_operation_keys WHERE actor_id = ? AND key_hash = ?', [$actor, hash('sha256', $fields['operation_key'])]);
    check($ledger['state'] === 'completed' && json_decode($ledger['response_json'], true, 512, JSON_THROW_ON_ERROR) === $created, 'Sent response was not already durably stored.');
    check(!str_contains($ledger['response_json'], $csrf) && !str_contains($ledger['response_json'], $seed['password']), 'Completion ledger contains fixture credentials.');
    check(envelope($admin->request($createRoute, $fields, true), 'Create replay') === $created && countRows($pdo, 'galleries') === $before + 1, 'Same-key create repeated work or changed the result.');
    $conflict = $admin->request($createRoute, array_replace($fields, ['title' => $intent . ' changed']), true);
    check($conflict['status'] === 409 && ($conflict['json']['error_code'] ?? '') === 'operation_payload_conflict', 'Changed payload reused a completed key.');
    $another = envelope($admin->request($createRoute, array_replace($fields, ['operation_key' => $admin->operationKey($createRoute . '&panel=1')]), true), 'Fresh create');
    check((int) $another['gallery_id'] !== $id && countRows($pdo, 'galleries') === $before + 2, 'Fresh key suppressed an intentional duplicate.');
    $reauthenticated = new Http($origin);
    $newCsrf = $reauthenticated->login($seed);
    check(envelope($reauthenticated->request($createRoute, array_replace($fields, ['csrf_token' => $newCsrf]), true), 'Reauthenticated replay') === $created, 'Same actor in a new session lost the original result.');

    $stage = 'independent connection exclusion and retained interrupted claim';
    $lock = hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . ':admin_operation:' . $actor . ':' . hash('sha256', $fields['operation_key']));
    $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $statement->execute([$lock]);
    check((int) $statement->fetchColumn() === 1, 'Could not own disposable operation lock.');
    try {
        $busy = $admin->request($createRoute, $fields, true);
        check($busy['status'] === 409 && ($busy['json']['error_code'] ?? '') === 'operation_pending', 'HTTP request ignored another connection owning its key.');
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
    $orphanKey = $admin->operationKey($createRoute . '&panel=1');
    $pdo->prepare("INSERT INTO admin_operation_keys (actor_id, key_hash, operation_name, payload_hash, owner_hash, state, created_at, updated_at) VALUES (?, ?, 'gallery.create', ?, ?, 'pending', '2000-01-01', '2000-01-01')")
        ->execute([$actor, hash('sha256', $orphanKey), $ledger['payload_hash'], hash('sha256', random_bytes(32))]);
    $pending = $admin->request($createRoute, array_replace($fields, ['operation_key' => $orphanKey]), true);
    check($pending['status'] === 409 && ($pending['json']['error_code'] ?? '') === 'operation_needs_reconciliation' && countRows($pdo, 'galleries') === $before + 2, 'Old pending claim expired into repeated creation.');

    $stage = 'multipart original identities and byte-bound conflict';
    $sample = $directory . '/galleries/seed/sample-1.jpg';
    $upload = ['csrf_token' => $csrf, 'gallery_id' => (string) $id, 'upload_mode' => 'existing',
        'images[]' => new CURLFile($sample, 'image/jpeg', 'operation-photo.jpg'),
        'operation_key' => $admin->operationKey($uploadRoute . '&gallery_id=' . $id)];
    $beforeImages = countRows($pdo, 'images');
    $uploaded = envelope($admin->request($uploadRoute, $upload, true), 'Classic upload');
    check(envelope($admin->request($uploadRoute, $upload, true), 'Classic replay') === $uploaded && countRows($pdo, 'images') === $beforeImages + 1, 'Multipart replay duplicated images or changed completion.');
    $gallery = row($pdo, 'SELECT folder_path FROM galleries WHERE id = ?', [$id]);
    $image = row($pdo, 'SELECT relative_path FROM images WHERE id = ?', [$uploaded['image_ids'][0]]);
    $original = $directory . '/galleries/' . $gallery['folder_path'] . '/' . $image['relative_path'];
    check(hash_file('sha256', $original) === hash_file('sha256', $sample), 'Replay changed original bytes.');
    $different = array_replace($upload, ['images[]' => new CURLFile($directory . '/galleries/seed/sample-2.jpg', 'image/jpeg', 'operation-photo.jpg')]);
    check($admin->request($uploadRoute, $different, true)['status'] === 409 && countRows($pdo, 'images') === $beforeImages + 1, 'Same filename with different bytes reused an upload key.');
    envelope($admin->request($uploadRoute, array_replace($upload, ['operation_key' => $admin->operationKey($uploadRoute . '&gallery_id=' . $id)]), true), 'Fresh upload');
    check(countRows($pdo, 'images') === $beforeImages + 2 && count(glob(dirname($original) . '/*.jpg') ?: []) === 2, 'Fresh upload key did not preserve intentional duplicate originals.');

    $stage = 'combined upload and global writer exclusion';
    $combined = array_replace($fields, ['title' => $intent . ' combined', 'upload_mode' => 'new', 'images[]' => $upload['images[]'],
        'operation_key' => $admin->operationKey($uploadRoute . '&upload_mode=new&parent_id=' . $seed['root_id'])]);
    $writerLock = hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . \Gallery\Core\GALLERY_EDIT_LOCK_SUFFIX);
    $statement->execute([$writerLock]);
    check((int) $statement->fetchColumn() === 1, 'Could not own disposable gallery writer lock.');
    try {
        $blocked = $admin->request($uploadRoute, $combined, true);
        check($blocked['status'] !== 200 && countRows($pdo, 'galleries') === $before + 2 && countRows($pdo, 'images') === $beforeImages + 2, 'Combined upload mutated under another writer lease.');
    } finally {
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$writerLock]);
    }
    check($admin->request($uploadRoute, $combined, true)['status'] === 409, 'Retained failed claim automatically restarted after lock release.');
    $combined['operation_key'] = $admin->operationKey($uploadRoute . '&upload_mode=new&parent_id=' . $seed['root_id']);
    $combinedResult = envelope($admin->request($uploadRoute, $combined, true), 'Combined upload');
    check(envelope($admin->request($uploadRoute, $combined, true), 'Combined replay') === $combinedResult
        && countRows($pdo, 'galleries') === $before + 3 && countRows($pdo, 'images') === $beforeImages + 3, 'Combined replay repeated creation or upload.');

    $stage = 'direct-page fallback replay';
    $direct = array_replace($fields, ['title' => $intent . ' direct', 'operation_key' => $admin->operationKey($createRoute)]);
    unset($direct['panel']);
    $firstDirect = $admin->request($createRoute, $direct);
    $secondDirect = $admin->request($createRoute, $direct);
    check($firstDirect['status'] === 302 && $secondDirect['status'] === 302
        && $firstDirect['headers']['location'] === $secondDirect['headers']['location']
        && countRows($pdo, 'galleries') === $before + 4, 'No-JavaScript fallback repeated creation or changed its original destination.');
    echo "PASS admin operation HTTP create/classic/combined replay, current auth, writer exclusion and original-byte preservation\n";
} catch (Throwable $exception) {
    // Only assertion messages or source locations leave this owned fixture; never SQL/HTTP/config data.
    $detail = get_class($exception) === RuntimeException::class ? $exception->getMessage()
        : basename($exception->getFile()) . ' line ' . $exception->getLine();
    fwrite(STDERR, 'FAIL admin operation HTTP ' . $stage . ': ' . $detail . "\n");
    exit(1);
}
