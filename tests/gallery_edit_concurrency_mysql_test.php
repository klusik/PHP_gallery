<?php
/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Verify application-owned revision updates and stale-editor conflicts across independent fixture sessions.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_edit_concurrency_mysql_test.php
 * Module Type: Integration Test
 * Purpose: Verify actual MySQL application revision enforcement and independent-session HTTP conflicts in a disposable clone.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_http.php';
require_once __DIR__ . '/support/gallery_edit_runtime.php';
require_once __DIR__ . '/support/gallery_edit_writer_inventory.php';
require_once dirname(__DIR__) . '/app/migrations.php';

use GalleryWorkflow\Http;
use Gallery\Services\GalleryEditConflict;
use function GalleryWorkflow\check;
use function GalleryWorkflow\envelope;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\row;
use function GalleryWorkflow\validateFixture;
use function Gallery\Services\gallery_edit_begin;
use function Gallery\Services\gallery_edit_end;
use function Gallery\Services\gallery_edit_schema_state;
use function Gallery\Services\gallery_edit_writer_begin;
use function Gallery\Services\gallery_edit_writer_end;

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP') . " gallery edit concurrency requires disposable MySQL and HTTP fixture\n";
    exit($required ? 1 : 0);
}

/**
 * Serialize the actual full-page editor's enabled successful controls.
 *
 * @param array<string,mixed> $response Real editor GET response.
 * @return array<string,mixed> Form fields including the revision attached to the rendered row.
 */
function galleryEditFixtureFields(array $response): array
{
    check($response['status'] === 200, 'Editor form did not load.');
    $document = new DOMDocument();
    @$document->loadHTML($response['body']);
    $xpath = new DOMXPath($document);
    $form = $xpath->query('//form[contains(@class,"admin-edit-gallery-form")]')->item(0);
    check($form instanceof DOMElement, 'Actual shared editor form is missing.');
    $fields = [];
    foreach ($xpath->query('.//input[@name]|.//textarea[@name]|.//select[@name]', $form) as $control) {
        if ($control->hasAttribute('disabled')) continue;
        $type = strtolower($control->getAttribute('type'));
        if (in_array($type, ['submit', 'button', 'file'], true)) continue;
        if (in_array($type, ['checkbox', 'radio'], true) && !$control->hasAttribute('checked')) continue;
        $name = $control->getAttribute('name');
        if ($control->tagName === 'select') {
            $option = $xpath->query('.//option[@selected]', $control)->item(0)
                ?? $xpath->query('.//option', $control)->item(0);
            $fields[$name] = $option?->getAttribute('value') ?? '';
        } else {
            $fields[$name] = $control->tagName === 'textarea' ? $control->textContent : $control->getAttribute('value');
        }
    }
    check(preg_match('/^[1-9][0-9]*$/D', (string) ($fields['edit_revision'] ?? '')) === 1, 'Actual form lacks a decimal revision.');
    return $fields;
}

$stage = 'fixture validation';
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
    $pdo = fixtureDatabase($directory, $token);
    $GLOBALS['gallery_edit_fixture_connection'] = $pdo;
    $peer = fixtureDatabase($directory, $token);
    check(gallery_edit_schema_state() === 'available', 'Actual migration revision storage is unavailable.');
    $revisionMigrationPath = dirname(__DIR__) . '/database/migrations/202609200002_gallery_edit_revision.php';
    $revisionVersion = pathinfo($revisionMigrationPath, PATHINFO_FILENAME);
    $revisionMigration = require $revisionMigrationPath;
    check(count($revisionMigration) === 1, 'Revision migration must contain only the portable column addition.');
    foreach ($revisionMigration as $statement) {
        check(preg_match('/^\s*ALTER\s+TABLE\s+galleries\s+ADD\s+COLUMN\s+edit_revision\b/i', $statement) === 1
            && preg_match('/\b(?:TRIGGER|SUPER)\b|@@GLOBAL|log_bin/i', $statement) !== 1,
            'Revision migration retained privileged or server-global SQL.');
    }
    $removeLedger = $pdo->prepare('DELETE FROM schema_migrations WHERE version = ?');
    $removeLedger->execute([$revisionVersion]);
    check($removeLedger->rowCount() === 1, 'Disposable fixture did not contain the applied revision migration ledger row.');
    $recoveredVersions = \Gallery\Core\run_migrations();
    check(in_array($revisionVersion, $recoveredVersions, true), 'Full migration runner did not recover the partial duplicate-column installation.');
    $ledgerCount = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = ?');
    $ledgerCount->execute([$revisionVersion]);
    check((int) $ledgerCount->fetchColumn() === 1, 'Recovered revision migration was not recorded exactly once.');
    check(gallery_edit_schema_state() === 'available', 'Migration replay invalidated revision storage.');
    echo "PASS gallery edit portable duplicate-column migration replay and ledger recovery\n";
    $seed = json_decode((string) file_get_contents($directory . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
    $origin = json_decode((string) file_get_contents($directory . '/endpoint.json'), true, 512, JSON_THROW_ON_ERROR)['url'];
    $adminA = new Http($origin);
    $adminB = new Http($origin);
    $adminA->login($seed);
    $adminB->login($seed);

    // Dedicated fixture rows/files; no application configuration or live data is loaded.
    $relative = 'seed/edit-concurrency-' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare('INSERT INTO galleries (parent_id, folder_path, folder_path_hash, slug, title, description, sort_order, visibility, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())');
    $stmt->execute([$seed['root_id'], $relative, hash('sha256', $relative), basename($relative), 'Concurrency original', 'Original description', 'public']);
    $id = (int) $pdo->lastInsertId();
    $folder = $directory . '/galleries/' . $relative;
    check(mkdir($folder) && copy($directory . '/galleries/seed/sample-1.jpg', $folder . '/original.jpg'), 'Could not create owned concurrency file fixture.');
    $hash = hash_file('sha256', $folder . '/original.jpg');
    $route = '/index.php?page=admin_edit_gallery&id=' . $id;

    $stage = 'two independent sessions and stale side-effect refusal';
    $fieldsA = galleryEditFixtureFields($adminA->request($route));
    $fieldsB = galleryEditFixtureFields($adminB->request($route));
    check($fieldsA['edit_revision'] === $fieldsB['edit_revision'], 'Independent sessions did not read the same revision.');
    check($fieldsA['csrf_token'] !== $fieldsB['csrf_token'], 'Concurrency fixture reused one administrator session.');
    $fieldsA['title'] = 'Accepted first session';
    $success = envelope($adminA->request($route, $fieldsA, true), 'First session edit');
    $saved = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    check(($success['edit_revision'] ?? '') === (string) $saved['edit_revision'], 'Success response did not carry its exact persisted revision.');
    $sidecar = hash_file('sha256', $folder . '/gallery.json');
    $fieldsB['title'] = 'Keep rejected draft';
    $fieldsB['description'] = 'Rejected description stays in the browser';
    $fieldsB['folder_name'] = 'must-not-move';
    $fieldsB['access_password'] = 'private-rejected-password';
    $fieldsB['cover_upload'] = new CURLFile($directory . '/galleries/seed/sample-1.jpg', 'image/jpeg', 'rejected-cover.jpg');
    $rejected = $adminB->request($route, $fieldsB, true);
    check($rejected['status'] === 409 && ($rejected['json']['error_code'] ?? '') === 'gallery_edit_conflict', 'Stale edit was not a typed 409.');
    check(($rejected['json']['conflict']['latest']['title'] ?? '') === $fieldsA['title'], 'Conflict omitted latest comparison values.');
    check(!str_contains($rejected['body'], 'access_password_hash') && !str_contains($rejected['body'], 'access_token_hash')
        && !str_contains($rejected['body'], 'private-rejected-password'), 'Conflict leaked credential material.');
    check(row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]) === $saved
        && is_dir($folder) && !is_dir(dirname($folder) . '/must-not-move')
        && hash_file('sha256', $folder . '/original.jpg') === $hash
        && hash_file('sha256', $folder . '/gallery.json') === $sidecar, 'Rejected request changed a row, folder, original or sidecar.');
    unset($fieldsB['cover_upload']);
    $noJs = $adminB->request($route, $fieldsB);
    check($noJs['status'] === 409 && str_contains($noJs['body'], 'data-gallery-edit-conflict')
        && str_contains($noJs['body'], 'Keep rejected draft') && str_contains($noJs['body'], 'target="_blank"')
        && !str_contains($noJs['body'], 'private-rejected-password'), 'No-JavaScript conflict lost the draft or safe recovery.');
    echo "PASS gallery edit independent sessions, typed conflict, no filesystem changes and no-JavaScript review\n";

    $stage = 'application revision reservation and whole-operation exclusion';
    $lease = gallery_edit_begin($saved, (string) $saved['edit_revision']);
    try {
        check(!$pdo->inTransaction(), 'Editor retained an outer database transaction across filesystem work.');
        $reserved = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
        check((int) $reserved['edit_revision'] === (int) $saved['edit_revision'] + 1,
            'Application reservation did not durably advance exactly one revision before side effects.');
        $currentFields = galleryEditFixtureFields($adminB->request($route));
        $busy = $adminB->request($route, $currentFields, true);
        check($busy['status'] === 409 && !empty($busy['json']['conflict']['busy']), 'Concurrent HTTP edit was not rejected while first ownership remained active.');
        check(row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]) === $reserved, 'Competing writer changed the reserved row.');
        $nested = gallery_edit_writer_begin();
        gallery_edit_writer_end($nested);
        $statement = $peer->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lease['lock']]);
        check((int) $statement->fetchColumn() === 0, 'Nested writer released the outer operation lock.');

        // Load service definitions only; the fixture DB adapter remains the sole
        // connection owner. Every real entry must refuse before domain validation,
        // cached reads, target files, sidecars or its delegated implementation.
        $stage = 'all filesystem writer entry points refuse a competing connection';
        $inventory = gallery_edit_writer_fixture_inventory();
        foreach (array_keys($inventory) as $module) {
            require_once dirname(__DIR__) . '/app/services/' . $module;
        }
        $GLOBALS['gallery_edit_fixture_connection'] = $peer;
        $refusedWriters = 0;
        try {
            foreach ($inventory as $names) {
                foreach ($names as $name) {
                    $function = new ReflectionFunction('Gallery\\Services\\' . $name);
                    try {
                        $function->invokeArgs(gallery_edit_writer_fixture_arguments($function));
                        throw new RuntimeException('Filesystem writer bypassed active editor ownership.');
                    } catch (GalleryEditConflict $exception) {
                        check($exception->busy, 'Filesystem writer did not return a typed busy conflict.');
                        $refusedWriters++;
                    }
                }
            }
        } finally {
            $GLOBALS['gallery_edit_fixture_connection'] = $pdo;
        }
        check($refusedWriters === array_sum(array_map('count', $inventory)), 'Writer refusal inventory was incomplete.');
        check(row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]) === $reserved
            && hash_file('sha256', $folder . '/original.jpg') === $hash
            && hash_file('sha256', $folder . '/gallery.json') === $sidecar,
            'Refused filesystem writer changed fixture rows or files.');
        echo 'PASS gallery edit actual early busy refusal for ' . $refusedWriters . " filesystem writer entry points\n";
    } finally {
        gallery_edit_end($lease);
    }
    $before = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    $applicationLease = gallery_edit_begin($before, (string) $before['edit_revision']);
    gallery_edit_end($applicationLease);
    $after = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    check((int) $after['edit_revision'] === (int) $before['edit_revision'] + 1
        && $after['updated_at'] === $before['updated_at'], 'Explicit application reservation changed domain fields or skipped its revision increment.');
    try {
        gallery_edit_begin($before, (string) $before['edit_revision']);
        throw new RuntimeException('Application revision reservation did not invalidate old forms.');
    } catch (GalleryEditConflict $exception) {
        check(!$exception->busy, 'Released application reservation was misclassified as an active writer.');
        check(row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]) === $after, 'Stale reservation changed the gallery row.');
    }
    echo "PASS gallery edit application revision, concurrent writer refusal, nested ownership and durable reservation\n";

    $stage = 'reviewed fresh retry';
    $reviewed = galleryEditFixtureFields($adminB->request($route));
    $reviewed['description'] = 'Explicitly reviewed retry';
    envelope($adminB->request($route, $reviewed, true), 'Reviewed retry');
    check(row($pdo, 'SELECT title, description FROM galleries WHERE id = ?', [$id]) === [
        'title' => 'Accepted first session', 'description' => 'Explicitly reviewed retry',
    ], 'Reviewed retry silently restored stale fields.');
    echo "PASS gallery edit fresh reviewed retry preserves prior title\n";

    $stage = 'fresh rename plan revalidation on the actual HTTP writer';
    $statement = $pdo->prepare('INSERT INTO images (gallery_id, relative_path, relative_path_hash, filename, mime_type, checksum_sha256, visibility, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
    $statement->execute([$id, 'original.jpg', hash('sha256', 'original.jpg'), 'original.jpg', 'image/jpeg', $hash, 'public']);
    $renameImageId = (int) $pdo->lastInsertId();
    $renameRoute = '/index.php?page=admin_media_renamer';
    $rename = $adminB->request($renameRoute, [
        'csrf_token' => $adminB->token($renameRoute),
        'renamer_action' => 'apply_batch',
        'confirm_media_rename' => '1',
        'batch_image_ids' => (string) $renameImageId,
        'renamer_pattern' => 'concurrency-{sequence}',
    ], true);
    check($rename['status'] === 200 && !empty($rename['json']['ok'])
        && (int) ($rename['json']['result']['renamed'] ?? 0) === 1
        && empty($rename['json']['result']['failures']),
        'Fresh canonical rename plan was rejected during ownership revalidation.');
    $renamedImage = row($pdo, 'SELECT gallery_id, relative_path FROM images WHERE id = ?', [$renameImageId]);
    check((int) $renamedImage['gallery_id'] === $id && $renamedImage['relative_path'] !== 'original.jpg'
        && hash_file('sha256', $folder . '/' . $renamedImage['relative_path']) === $hash,
        'Validated rename lost original identity or contents.');
    echo "PASS gallery edit fresh rename plan executes without losing selection or original contents\n";
} catch (Throwable $exception) {
    // Print locations only: SQL, HTTP bodies and fixture credentials remain private.
    fwrite(STDERR, 'FAIL gallery edit ' . $stage . ' at ' . basename($exception->getFile()) . ':' . $exception->getLine() . "\n");
    exit(1);
}
