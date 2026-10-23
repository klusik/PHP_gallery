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
require_once dirname(__DIR__) . '/app/models/galleries.php';
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
    echo
        ($required ? 'BLOCKED' : 'SKIP')
            . " gallery edit concurrency requires disposable MySQL and HTTP fixture\n";
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
    $form = $xpath
        ->query('//form[contains(@class,"admin-edit-gallery-form")]')
        ->item(0);
    check($form instanceof DOMElement, 'Actual shared editor form is missing.');
    $fields = [];
    foreach ($xpath->query(
        './/input[@name]|.//textarea[@name]|.//select[@name]',
        $form,
    ) as $control) {
        if ($control->hasAttribute('disabled'))
            continue;
        $type = strtolower($control->getAttribute('type'));
        if (in_array($type, ['submit', 'button', 'file'], true))
            continue;
        if (
            in_array($type, ['checkbox', 'radio'], true)
            && !$control->hasAttribute('checked')
        )
            continue;
        $name = $control->getAttribute('name');
        if ($control->tagName === 'select') {
            $option = $xpath->query('.//option[@selected]', $control)->item(0)
                ?? $xpath->query('.//option', $control)->item(0);
            $fields[$name] = $option?->getAttribute('value') ?? '';
        } else {
            $fields[$name] = $control->tagName === 'textarea'
                ? $control->textContent
                : $control->getAttribute('value');
        }
    }
    check(
        preg_match(
            '/^[1-9][0-9]*$/D',
            (string) ($fields['edit_revision'] ?? ''),
        ) === 1,
        'Actual form lacks a decimal revision.',
    );
    return $fields;
}

$stage = 'fixture validation';
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture(
        (string) getenv('GALLERY_WORKFLOW_FIXTURE'),
        $token,
    );
    $pdo = fixtureDatabase($directory, $token);
    $GLOBALS['gallery_edit_fixture_connection'] = $pdo;
    $peer = fixtureDatabase($directory, $token);
    check(
        gallery_edit_schema_state() === 'available',
        'Actual migration revision storage is unavailable.',
    );
    $revisionMigrationPath =
        dirname(__DIR__)
        . '/database/migrations/202609200002_gallery_edit_revision.php';
    $revisionVersion = pathinfo($revisionMigrationPath, PATHINFO_FILENAME);
    $revisionMigration = require $revisionMigrationPath;
    check(
        count($revisionMigration) === 1,
        'Revision migration must contain only the portable column addition.',
    );
    foreach ($revisionMigration as $statement) {
        check(
            preg_match(
                '/^\s*ALTER\s+TABLE\s+galleries\s+ADD\s+COLUMN\s+edit_revision\b/i',
                $statement,
            ) === 1
                && preg_match(
                    '/\b(?:TRIGGER|SUPER)\b|@@GLOBAL|log_bin/i',
                    $statement,
                ) !== 1,
            'Revision migration retained privileged or server-global SQL.',
        );
    }
    $removeLedger = $pdo->prepare(
        'DELETE FROM schema_migrations WHERE version = ?',
    );
    $removeLedger->execute([$revisionVersion]);
    check(
        $removeLedger->rowCount() === 1,
        'Disposable fixture did not contain the applied revision migration ledger row.',
    );
    $recoveredVersions = \Gallery\Core\run_migrations();
    check(
        in_array($revisionVersion, $recoveredVersions, true),
        'Full migration runner did not recover the partial duplicate-column installation.',
    );
    $ledgerCount = $pdo->prepare(
        'SELECT COUNT(*) FROM schema_migrations WHERE version = ?',
    );
    $ledgerCount->execute([$revisionVersion]);
    check(
        (int) $ledgerCount->fetchColumn() === 1,
        'Recovered revision migration was not recorded exactly once.',
    );
    check(
        gallery_edit_schema_state() === 'available',
        'Migration replay invalidated revision storage.',
    );
    echo
        "PASS gallery edit portable duplicate-column migration replay and ledger recovery\n";
    $seed = json_decode(
        (string) file_get_contents($directory . '/seed.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    $origin = json_decode(
        (string) file_get_contents($directory . '/endpoint.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    )['url'];
    $adminA = new Http($origin);
    $adminB = new Http($origin);
    $adminA->login($seed);
    $adminB->login($seed);

    // Dedicated fixture rows/files; no application configuration or live data is loaded.
    $relative = 'seed/edit-concurrency-' . bin2hex(random_bytes(4));
    $stmt = $pdo->prepare(
        'INSERT INTO galleries (parent_id, folder_path, folder_path_hash, slug, title, description, sort_order, visibility, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())',
    );
    $stmt->execute([
        $seed['root_id'],
        $relative,
        hash('sha256', $relative),
        basename($relative),
        'Concurrency original',
        'Original description',
        'public',
    ]);
    $id = (int) $pdo->lastInsertId();
    $folder = $directory . '/galleries/' . $relative;
    check(
        mkdir($folder)
            && copy(
                $directory . '/galleries/seed/sample-1.jpg',
                $folder . '/original.jpg',
            ),
        'Could not create owned concurrency file fixture.',
    );
    $hash = hash_file('sha256', $folder . '/original.jpg');
    $route = '/index.php?page=admin_edit_gallery&id=' . $id;

    $stage = 'two independent sessions and stale side-effect refusal';
    $fieldsA = galleryEditFixtureFields($adminA->request($route));
    $fieldsB = galleryEditFixtureFields($adminB->request($route));
    check(
        $fieldsA['edit_revision'] === $fieldsB['edit_revision'],
        'Independent sessions did not read the same revision.',
    );
    check(
        $fieldsA['csrf_token'] !== $fieldsB['csrf_token'],
        'Concurrency fixture reused one administrator session.',
    );
    $fieldsA['title'] = 'Accepted first session';
    $success = envelope(
        $adminA->request($route, $fieldsA, true),
        'First session edit',
    );
    $saved = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    check(
        ($success['edit_revision'] ?? '') === (string) $saved['edit_revision'],
        'Success response did not carry its exact persisted revision.',
    );
    $sidecar = hash_file('sha256', $folder . '/gallery.json');
    $fieldsB['title'] = 'Keep rejected draft';
    $fieldsB['description'] = 'Rejected description stays in the browser';
    $fieldsB['folder_name'] = 'must-not-move';
    $fieldsB['access_password'] = 'private-rejected-password';
    $fieldsB['cover_upload'] = new CURLFile(
        $directory . '/galleries/seed/sample-1.jpg',
        'image/jpeg',
        'rejected-cover.jpg',
    );
    $rejected = $adminB->request($route, $fieldsB, true);
    check(
        $rejected['status'] === 409
            && ($rejected['json']['error_code'] ?? '')
                === 'gallery_edit_conflict',
        'Stale edit was not a typed 409.',
    );
    check(
        ($rejected['json']['conflict']['latest']['title'] ?? '')
            === $fieldsA['title'],
        'Conflict omitted latest comparison values.',
    );
    check(
        !str_contains($rejected['body'], 'access_password_hash')
            && !str_contains($rejected['body'], 'access_token_hash')
            && !str_contains($rejected['body'], 'private-rejected-password'),
        'Conflict leaked credential material.',
    );
    check(
        row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]) === $saved
            && is_dir($folder)
            && !is_dir(dirname($folder) . '/must-not-move')
            && hash_file('sha256', $folder . '/original.jpg') === $hash
            && hash_file('sha256', $folder . '/gallery.json') === $sidecar,
        'Rejected request changed a row, folder, original or sidecar.',
    );
    unset($fieldsB['cover_upload']);
    $noJs = $adminB->request($route, $fieldsB);
    check(
        $noJs['status'] === 409
            && str_contains($noJs['body'], 'data-gallery-edit-conflict')
            && str_contains($noJs['body'], 'Keep rejected draft')
            && str_contains($noJs['body'], 'target="_blank"')
            && !str_contains($noJs['body'], 'private-rejected-password'),
        'No-JavaScript conflict lost the draft or safe recovery.',
    );
    echo
        "PASS gallery edit independent sessions, typed conflict, no filesystem changes and no-JavaScript review\n";

    $stage = 'application revision reservation and whole-operation exclusion';
    $lease = gallery_edit_begin($saved, (string) $saved['edit_revision']);
    try {
        check(
            !$pdo->inTransaction(),
            'Editor retained an outer database transaction across filesystem work.',
        );
        $reserved = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
        check(
            (int) $reserved['edit_revision']
                === ((int) $saved['edit_revision'] + 1),
            'Application reservation did not durably advance exactly one revision before side effects.',
        );
        $currentFields = galleryEditFixtureFields($adminB->request($route));
        $busy = $adminB->request($route, $currentFields, true);
        check(
            $busy['status'] === 409
                && !empty($busy['json']['conflict']['busy']),
            'Concurrent HTTP edit was not rejected while first ownership remained active.',
        );
        check(
            row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id])
                === $reserved,
            'Competing writer changed the reserved row.',
        );
        $nested = gallery_edit_writer_begin();
        gallery_edit_writer_end($nested);
        $statement = $peer->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$lease['lock']]);
        check(
            (int) $statement->fetchColumn() === 0,
            'Nested writer released the outer operation lock.',
        );

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
                    $function = new ReflectionFunction('Gallery\\Services\\'
                        . $name);
                    try {
                        $function->invokeArgs(gallery_edit_writer_fixture_arguments(
                            $function,
                        ));
                        throw new RuntimeException(
                            'Filesystem writer bypassed active editor ownership.',
                        );
                    } catch (GalleryEditConflict $exception) {
                        check(
                            $exception->busy,
                            'Filesystem writer did not return a typed busy conflict.',
                        );
                        $refusedWriters++;
                    }
                }
            }
        } finally {
            $GLOBALS['gallery_edit_fixture_connection'] = $pdo;
        }
        check(
            $refusedWriters === array_sum(array_map('count', $inventory)),
            'Writer refusal inventory was incomplete.',
        );
        check(
            row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id])
                === $reserved
                && hash_file('sha256', $folder . '/original.jpg') === $hash
                && hash_file('sha256', $folder . '/gallery.json') === $sidecar,
            'Refused filesystem writer changed fixture rows or files.',
        );
        echo
            'PASS gallery edit actual early busy refusal for '
                . $refusedWriters
                . " filesystem writer entry points\n";
    } finally {
        gallery_edit_end($lease);
    }
    $before = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    $applicationLease = gallery_edit_begin(
        $before,
        (string) $before['edit_revision'],
    );
    gallery_edit_end($applicationLease);
    $after = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    check(
        (int) $after['edit_revision'] === ((int) $before['edit_revision'] + 1)
            && $after['updated_at'] === $before['updated_at'],
        'Explicit application reservation changed domain fields or skipped its revision increment.',
    );
    try {
        gallery_edit_begin($before, (string) $before['edit_revision']);
        throw new RuntimeException(
            'Application revision reservation did not invalidate old forms.',
        );
    } catch (GalleryEditConflict $exception) {
        check(
            !$exception->busy,
            'Released application reservation was misclassified as an active writer.',
        );
        check(
            row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]) === $after,
            'Stale reservation changed the gallery row.',
        );
    }
    echo
        "PASS gallery edit application revision, concurrent writer refusal, nested ownership and durable reservation\n";

    $stage = 'reviewed fresh retry';
    $reviewed = galleryEditFixtureFields($adminB->request($route));
    $reviewed['description'] = 'Explicitly reviewed retry';
    envelope($adminB->request($route, $reviewed, true), 'Reviewed retry');
    check(
        row(
            $pdo,
            'SELECT title, description FROM galleries WHERE id = ?',
            [$id],
        ) === [
            'title' => 'Accepted first session',
            'description' => 'Explicitly reviewed retry',
        ],
        'Reviewed retry silently restored stale fields.',
    );
    echo "PASS gallery edit fresh reviewed retry preserves prior title\n";

    $stage = 'fresh rename plan revalidation on the actual HTTP writer';
    $statement = $pdo->prepare(
        'INSERT INTO images (gallery_id, relative_path, relative_path_hash, filename, mime_type, checksum_sha256, visibility, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
    );
    $statement->execute([
        $id,
        'original.jpg',
        hash('sha256', 'original.jpg'),
        'original.jpg',
        'image/jpeg',
        $hash,
        'public',
    ]);
    $renameImageId = (int) $pdo->lastInsertId();
    $renameRoute = '/index.php?page=admin_media_renamer';
    $rename = $adminB->request(
        $renameRoute,
        [
            'csrf_token' => $adminB->token($renameRoute),
            'renamer_action' => 'apply_batch',
            'confirm_media_rename' => '1',
            'batch_image_ids' => (string) $renameImageId,
            'renamer_pattern' => 'concurrency-{sequence}',
        ],
        true,
    );
    check(
        $rename['status'] === 200
            && !empty($rename['json']['ok'])
            && (int) ($rename['json']['result']['renamed'] ?? 0) === 1
            && empty($rename['json']['result']['failures']),
        'Fresh canonical rename plan was rejected during ownership revalidation.',
    );
    $renamedImage = row(
        $pdo,
        'SELECT gallery_id, relative_path FROM images WHERE id = ?',
        [$renameImageId],
    );
    check(
        (int) $renamedImage['gallery_id'] === $id
            && $renamedImage['relative_path'] !== 'original.jpg'
            && hash_file(
                'sha256',
                $folder . '/' . $renamedImage['relative_path'],
            ) === $hash,
        'Validated rename lost original identity or contents.',
    );
    echo
        "PASS gallery edit fresh rename plan executes without losing selection or original contents\n";

    $stage = 'real HTTP three-state cover cycle and authoritative reconciliation';
    $coverIds = [];
    $statement = $pdo->prepare(
        'INSERT INTO images (gallery_id, relative_path, relative_path_hash, filename, mime_type, checksum_sha256, visibility, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
    );
    foreach (range(1, 5) as $number) {
        $filename = 'cover-' . $number . '.jpg';
        check(
            copy(
                $folder . '/' . $renamedImage['relative_path'],
                $folder . '/' . $filename,
            ),
            'Could not prepare an isolated cover original.',
        );
        $statement->execute([
            $id,
            $filename,
            hash('sha256', $filename),
            $filename,
            'image/jpeg',
            $hash,
            'public',
        ]);
        $coverIds[] = (int) $pdo->lastInsertId();
    }
    $bulkRoute = '/index.php?page=admin_bulk_images';
    $stateRoute = $bulkRoute . '&id=' . $id . '&cover_state=1&ajax=1';
    $stateResponse = $adminA->request($stateRoute, null, true);
    $state = envelope($stateResponse, 'Read authoritative cover state');
    check(
        str_contains(
            $stateResponse['headers']['cache-control'] ?? '',
            'no-store',
        )
            && $state['panel'] === null,
        'Cover reconciliation must be private, passive and must not replace the drawer.',
    );
    $csrf = $adminA->token($route);
    $cycle = [
        ['cover', $coverIds[1], 'single', [$coverIds[1]]],
        ['cover', $coverIds[2], 'single', [$coverIds[2]]],
        ['cover_arm', $coverIds[2], 'multi', [$coverIds[2]]],
        ['cover_add', $coverIds[1], 'multi', [$coverIds[2], $coverIds[1]]],
        ['cover_reset', $coverIds[1], 'single', [$coverIds[1]]],
        ['cover_arm', $coverIds[1], 'multi', [$coverIds[1]]],
        ['cover_add', $coverIds[2], 'multi', [$coverIds[1], $coverIds[2]]],
        ['cover_reset', $coverIds[2], 'single', [$coverIds[2]]],
    ];
    foreach ($cycle as [$intent, $photoId, $mode, $expectedIds]) {
        $previousRevision = $state['cover_selection_revision'];
        $state = envelope(
            $adminA->request(
                $bulkRoute,
                [
                    'gallery_id' => $id,
                    'csrf_token' => $csrf,
                    'panel' => 1,
                    'action' => $intent . ':' . $photoId,
                    'cover_selection_revision' => $previousRevision,
                ],
                true,
            ),
            'Persist explicit row-star intent without checked bulk boxes',
        );
        check(
            $state['panel'] === null
                && $state['mutation']['type'] === 'gallery.cover'
                && $state['cover_selection_mode'] === $mode
                && $state['cover_selection_ids'] === $expectedIds
                && $state['cover_selection_revision'] !== $previousRevision,
            'The real HTTP star cycle lost its ordered state, revision or stable drawer contract.',
        );
        $reopened = envelope(
            $adminB->request($stateRoute, null, true),
            'Independent-session cover reload',
        );
        check(
            $reopened['cover_selection_ids'] === $expectedIds
                && $reopened['cover_selection_mode'] === $mode
                && $reopened['cover_selection_revision']
                    === $state['cover_selection_revision'],
            'Cover selection did not survive an independent session read.',
        );
    }
    $savedCover = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    $staleCover = $adminA->request(
        $bulkRoute,
        [
            'gallery_id' => $id,
            'csrf_token' => $csrf,
            'panel' => 1,
            'action' => 'cover:' . $coverIds[0],
            'cover_selection_revision' => $previousRevision,
        ],
        true,
    );
    check(
        $staleCover['status'] === 409
            && !empty($staleCover['json']['reference'])
            && !empty($staleCover['json']['reconciliation_required'])
            && row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id])
                === $savedCover,
        'A stale cover request must return a correlated 409 without modifying the row.',
    );
    $badCsrf = $adminA->request(
        $bulkRoute,
        [
            'gallery_id' => $id,
            'csrf_token' => 'invalid',
            'panel' => 1,
            'action' => 'cover:' . $coverIds[0],
        ],
        true,
    );
    check(
        $badCsrf['status'] === 400,
        'An invalid CSRF token was not rejected by the established JSON CSRF contract.',
    );
    check(
        row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id])
            === $savedCover,
        'An invalid CSRF token changed the persisted cover row.',
    );
    $anonymous = new Http($origin);
    $denied = $anonymous->request($stateRoute, null, true);
    check(
        $denied['status'] >= 400
            && !isset($denied['json']['cover_selection_ids']),
        'Passive cover reconciliation exposed selection state to an anonymous visitor.',
    );

    $state = envelope(
        $adminA->request(
            $bulkRoute,
            [
                'gallery_id' => $id,
                'csrf_token' => $csrf,
                'panel' => 1,
                'action' => 'cover',
                'image_ids' => array_slice($coverIds, 0, 4),
                'cover_selection_revision' =>
                    $state['cover_selection_revision'],
            ],
            true,
        ),
        'Four-photo bulk cover',
    );
    check(
        $state['cover_selection_mode'] === 'multi'
            && $state['cover_selection_ids'] === array_slice($coverIds, 0, 4),
        'Bulk title-picture selection lost its four-photo ordered set.',
    );
    $savedCover = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id]);
    $foreignCover = row(
        $pdo,
        'SELECT id FROM images WHERE gallery_id = ? ORDER BY id LIMIT 1',
        [$seed['root_id']],
    );
    foreach ([
        ['cover', $coverIds],
        ['cover_add:' . $coverIds[4], []],
        ['cover', [$coverIds[0], $coverIds[0]]],
        ['cover:' . (int) $foreignCover['id'], []],
    ] as [$intent, $selection]) {
        $invalid = $adminA->request(
            $bulkRoute,
            [
                'gallery_id' => $id,
                'csrf_token' => $csrf,
                'panel' => 1,
                'action' => $intent,
                'image_ids' => $selection,
                'cover_selection_revision' =>
                    $state['cover_selection_revision'],
            ],
            true,
        );
        check(
            $invalid['status'] === 422
                && !empty($invalid['json']['reference'])
                && row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$id])
                    === $savedCover,
            'Invalid/fifth/duplicate/foreign cover selection was not an atomic correlated 422 refusal.',
        );
    }
    $automatic = galleryEditFixtureFields($adminA->request($route));
    $automatic['cover_image_id'] = 'automatic';
    envelope(
        $adminA->request($route, $automatic, true),
        'Explicit automatic cover setting',
    );
    $automaticState = envelope(
        $adminA->request($stateRoute, null, true),
        'Automatic cover reload',
    );
    check(
        $automaticState['cover_selection_mode'] === 'automatic'
            && $automaticState['cover_selection_ids'] === [],
        'Switching to Automatic did not clear the manual selection.',
    );
    echo
        "PASS real HTTP cover cycle, four-photo bulk, reload, concurrency, CSRF, authorization and automatic reset\n";

    $stage = 'real HTTP deletion and move reconcile selected cover identities atomically';
    $state = envelope(
        $adminA->request(
            $bulkRoute,
            [
                'gallery_id' => $id,
                'csrf_token' => $csrf,
                'panel' => 1,
                'action' => 'cover',
                'image_ids' => array_slice($coverIds, 0, 3),
                'cover_selection_revision' =>
                    $automaticState['cover_selection_revision'],
            ],
            true,
        ),
        'Select covers before deletion',
    );
    envelope(
        $adminA->request(
            $bulkRoute,
            [
                'gallery_id' => $id,
                'csrf_token' => $csrf,
                'panel' => 1,
                'action' => 'delete:' . $coverIds[0],
            ],
            true,
        ),
        'Delete first selected cover',
    );
    $afterDelete = row(
        $pdo,
        'SELECT cover_image_id, cover_image_ids FROM galleries WHERE id = ?',
        [$id],
    );
    check(
        (int) $afterDelete['cover_image_id'] === $coverIds[1]
            && json_decode(
                $afterDelete['cover_image_ids'],
                true,
            ) === array_slice($coverIds, 1, 2),
        'Deleting a selected cover did not retain the ordered surviving selection and primary.',
    );
    $targetRelative = $relative . '-destination';
    $statement = $pdo->prepare(
        'INSERT INTO galleries (parent_id, folder_path, folder_path_hash, slug, title, description, sort_order, visibility, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, NOW(), NOW())',
    );
    $statement->execute([
        $seed['root_id'],
        $targetRelative,
        hash('sha256', $targetRelative),
        basename($targetRelative),
        'Cover move destination',
        '',
        'unpublished',
    ]);
    $targetId = (int) $pdo->lastInsertId();
    check(
        mkdir($directory . '/galleries/' . $targetRelative),
        'Could not create an isolated cover move destination.',
    );
    envelope(
        $adminA->request(
            $bulkRoute,
            [
                'gallery_id' => $id,
                'csrf_token' => $csrf,
                'panel' => 1,
                'action' => 'move_existing',
                'destination_gallery_id' => $targetId,
                'image_ids' => array_slice($coverIds, 1, 2),
            ],
            true,
        ),
        'Move remaining selected covers',
    );
    $afterMove = row(
        $pdo,
        'SELECT cover_image_id, cover_image_ids FROM galleries WHERE id = ?',
        [$id],
    );
    check(
        $afterMove['cover_image_id'] === null
            && $afterMove['cover_image_ids'] === '[]',
        'Moving the last selected covers did not atomically switch the source to Automatic.',
    );
    foreach (array_slice($coverIds, 1, 2) as $movedId) {
        check(
            (int) row(
                $pdo,
                'SELECT gallery_id FROM images WHERE id = ?',
                [$movedId],
            )['gallery_id'] === $targetId,
            'The cover move did not preserve image ownership.',
        );
    }
    echo
        "PASS real HTTP selected cover delete/move pruning and automatic fallback\n";
    $stage = 'scheduled publication through actual editor and anonymous crawler';
    $publicationPath = 'seed/publication-' . bin2hex(random_bytes(4));
    $publicationFolder = $directory . '/galleries/' . $publicationPath;
    check(mkdir($publicationFolder), 'Could not create owned publication folder.');
    $publicationInsert = $pdo->prepare(
        'INSERT INTO galleries (parent_id, folder_path, folder_path_hash, slug,'
        . ' title, description, sort_order, visibility, access_listing,'
        . ' created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, NOW(), NOW())',
    );
    $publicationInsert->execute([
        $seed['root_id'], $publicationPath, hash('sha256', $publicationPath),
        basename($publicationPath), 'Scheduled visitor publication', '',
        'unpublished', 'listed',
    ]);
    $publicationId = (int) $pdo->lastInsertId();
    $publicationRoute = '/index.php?page=admin_edit_gallery&id=' . $publicationId;
    $publicationUrl = '/index.php?page=gallery&gallery_path='
        . rawurlencode($publicationPath);
    $publicationParentUrl = '/index.php?page=gallery&gallery_path=seed';
    $publicationVisitor = new Http($origin);
    $publicationCrawler = new Http($origin, 'Googlebot/2.1 (+http://www.google.com/bot.html)');
    $publicationFuture = (new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))
        ->format('Y-m-d\TH:i');
    $publicationFields = galleryEditFixtureFields($adminA->request($publicationRoute));
    check(array_key_exists('scheduled_publish_at', $publicationFields), 'Schedule control is missing.');
    $publicationFields['scheduled_publish_at'] = $publicationFuture;
    envelope($adminA->request($publicationRoute, $publicationFields, true), 'Schedule unpublished gallery');
    $publicationBefore = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationId]);
    check(
        $publicationBefore['visibility'] === 'unpublished'
            && $publicationBefore['scheduled_publish_at'] !== null,
        'Scheduling changed unpublished state or lost its future deadline.',
    );
    $publicationDirect = $publicationVisitor->request($publicationUrl);
    check(
        $publicationDirect['status'] === 200,
        'Unpublished direct URL status before deadline: '
            . $publicationDirect['status'],
    );
    $publicationHome = $publicationVisitor->request($publicationParentUrl);
    check(!str_contains($publicationHome['body'], 'Scheduled visitor publication'), 'Unpublished gallery entered public discovery early.');
    $publicationFields = galleryEditFixtureFields($adminA->request($publicationRoute));
    $publicationFields['scheduled_publish_at'] = '2030-02-30T12:00';
    $publicationFields['folder_name'] = 'publication-must-not-move';
    $publicationFields['title'] = 'Rejected publication title';
    $publicationInvalid = $adminA->request($publicationRoute, $publicationFields, true);
    $publicationAfterInvalid = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationId]);
    unset($publicationBefore['edit_revision'], $publicationAfterInvalid['edit_revision']);
    check(
        ($publicationInvalid['json']['ok'] ?? true) === false
            && $publicationAfterInvalid === $publicationBefore
            && is_dir($publicationFolder),
        'Invalid schedule partly saved editor content or moved its folder.',
    );
    $publicationFields['scheduled_publish_at'] = ['malformed'];
    $publicationInvalidType = $adminA->request(
        $publicationRoute,
        $publicationFields,
        true,
    );
    check(
        ($publicationInvalidType['json']['ok'] ?? true) === false,
        'A structured HTTP value bypassed scalar schedule validation.',
    );

    $publicationArm = $pdo->prepare(
        "UPDATE galleries SET scheduled_publish_at = '2000-01-01 00:00:00',"
        . ' edit_revision = edit_revision + 1 WHERE id = ?',
    );
    $publicationArm->execute([$publicationId]);
    $publicationDue = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationId]);
    $publicationLease = gallery_edit_writer_begin();
    try {
        $publicationCrawler->request($publicationParentUrl);
        check(
            row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationId]) === $publicationDue,
            'A competing crawler bypassed the editor writer lease.',
        );
    } finally {
        gallery_edit_writer_end($publicationLease);
    }
    $publicationTrigger = $publicationCrawler->request($publicationParentUrl);
    $publicationPublished = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationId]);
    check(
        $publicationTrigger['status'] === 200
            && str_contains($publicationTrigger['body'], 'Scheduled visitor publication')
            && $publicationPublished['visibility'] === 'public'
            && $publicationPublished['scheduled_publish_at'] === null
            && (int) $publicationPublished['edit_revision'] === (int) $publicationDue['edit_revision'] + 1,
        'The anonymous crawler triggering page did not observe committed publication.',
    );
    $publicationCrawler->request($publicationParentUrl);
    check(
        row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationId]) === $publicationPublished,
        'Repeated crawler GET republished a consumed schedule.',
    );
    check(
        json_decode((string) file_get_contents($publicationFolder . '/gallery.json'), true)['visibility'] === 'public',
        'Scheduled publication did not update the canonical sidecar.',
    );

    $stage = 'scheduled private protections, cancellation and stale publication';
    $publicationProtect = $pdo->prepare(
        "UPDATE galleries SET visibility = 'private', access_listing = 'unlisted',"
        . " access_mode = 'password', access_password_hash = ?, access_token_hash = ?,"
        . " access_share_token = NULL, access_token_expires_at = '2099-01-01 00:00:00',"
        . " description = 'Protected scheduled publication content',"
        . ' nsfw_enabled = 1, scheduled_publish_at = ?, edit_revision = edit_revision + 1 WHERE id = ?',
    );
    $publicationProtect->execute([
        password_hash('fixture-publication-password', PASSWORD_DEFAULT),
        hash('sha256', 'fixture-publication-share'),
        str_replace('T', ' ', $publicationFuture) . ':00', $publicationId,
    ]);
    $publicationPrivate = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationId]);
    check($publicationVisitor->request($publicationUrl)['status'] === 404, 'Private scheduled gallery opened early.');
    $publicationArm->execute([$publicationId]);
    $publicationProtectedDue = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationId]);
    $publicationHead = $publicationVisitor->request(
        $publicationParentUrl, null, false, 'HEAD',
    );
    $publicationProtectedAfter = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationId]);
    foreach (['parent_id', 'description', 'nsfw_enabled', 'access_mode', 'access_password_hash', 'access_share_token', 'access_token_hash', 'access_token_expires_at'] as $publicationProtectedKey) {
        check(
            $publicationProtectedAfter[$publicationProtectedKey] === $publicationProtectedDue[$publicationProtectedKey],
            'Scheduled publication changed a protected gallery field.',
        );
    }
    check(
        $publicationHead['status'] === 200
            && $publicationProtectedAfter['visibility'] === 'public'
            && $publicationProtectedAfter['access_listing'] === 'listed'
            && $publicationProtectedAfter['scheduled_publish_at'] === null,
        'HEAD publication failed to restore listing and consume the deadline.',
    );
    // Access gates intentionally render HTTP 200. Assert their actual form and
    // absence of protected content rather than mistaking that status for access.
    $publicationGate = $publicationVisitor->request($publicationUrl);
    check(
        $publicationGate['status'] === 200
            && str_contains($publicationGate['body'], 'name="adult_confirmed"')
            && !str_contains($publicationGate['body'], 'Protected scheduled publication content'),
        'Published restricted gallery bypassed its NSFW gate or exposed content.',
    );
    $publicationAgeConfirmation = $publicationVisitor->request(
        '/index.php?page=gallery_access',
        [
            'csrf_token' => $publicationVisitor->token($publicationUrl),
            'gallery_id' => $publicationId,
            'access_action' => 'confirm_nsfw_age',
            'adult_confirmed' => '1',
        ],
    );
    check(
        $publicationAgeConfirmation['status'] === 302,
        'Fixture age confirmation did not use the existing access owner.',
    );
    $publicationPasswordGate = $publicationVisitor->request($publicationUrl);
    check(
        $publicationPasswordGate['status'] === 200
            && str_contains($publicationPasswordGate['body'], 'name="gallery_password"')
            && !str_contains($publicationPasswordGate['body'], 'Protected scheduled publication content'),
        'Age confirmation bypassed the unchanged gallery password protection.',
    );
    // Unrelated editor saves still preserve independent link-only state.
    $pdo->prepare("UPDATE galleries SET access_listing = 'unlisted' WHERE id = ?")
        ->execute([$publicationId]);
    $publicationEditor = $adminA->request($publicationRoute);
    check(str_contains($publicationEditor['body'], 'still accessible only by link'), 'Editor hides the published-but-unlisted state.');
    $publicationFields = galleryEditFixtureFields($publicationEditor);
    envelope($adminA->request($publicationRoute, $publicationFields, true), 'Save unchanged link-only publication');
    check(row($pdo, 'SELECT access_listing FROM galleries WHERE id = ?', [$publicationId])['access_listing'] === 'unlisted', 'An unchanged full form relisted the gallery.');

    $publicationFields = galleryEditFixtureFields($adminA->request($publicationRoute));
    $publicationFields['visibility'] = 'private';
    $publicationFields['scheduled_publish_at'] = $publicationFuture;
    envelope($adminA->request($publicationRoute, $publicationFields, true), 'Schedule private gallery');
    $publicationScheduled = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationId]);
    $publicationFields = galleryEditFixtureFields($adminA->request($publicationRoute));
    $publicationFields['scheduled_publish_at'] = (new DateTimeImmutable('next week', new DateTimeZone('UTC')))->format('Y-m-d\TH:i');
    envelope($adminA->request($publicationRoute, $publicationFields, true), 'Reschedule private gallery');
    check(
        !\Gallery\Models\gallery_publication_model_consume(
            $publicationId, $publicationScheduled['scheduled_publish_at'],
            (string) $publicationScheduled['edit_revision'], '2099-01-01 00:00:00', '2099-01-01 00:00:00',
        ),
        'A stale due snapshot overrode a reschedule.',
    );
    $publicationFields = galleryEditFixtureFields($adminA->request($publicationRoute));
    $publicationFields['scheduled_publish_at'] = '';
    envelope($adminA->request($publicationRoute, $publicationFields, true), 'Cancel publication');
    check(row($pdo, 'SELECT scheduled_publish_at FROM galleries WHERE id = ?', [$publicationId])['scheduled_publish_at'] === null, 'Cancellation retained its pending timestamp.');
    $publicationFields = galleryEditFixtureFields($adminA->request($publicationRoute));
    $publicationFields['scheduled_publish_at'] = $publicationFuture;
    envelope($adminA->request($publicationRoute, $publicationFields, true), 'Rearm private publication');
    $publicationFields = galleryEditFixtureFields($adminA->request($publicationRoute));
    $publicationFields['visibility'] = 'public';
    envelope($adminA->request($publicationRoute, $publicationFields, true), 'Manually publish scheduled gallery');
    check(row($pdo, 'SELECT scheduled_publish_at FROM galleries WHERE id = ?', [$publicationId])['scheduled_publish_at'] === null, 'Manual publication retained its schedule.');

    $stage = 'restricted PDF child and deleted schedule safety';
    $publicationChildPath = $publicationPath . '/pdf-pages-' . str_repeat('a', 64);
    check(mkdir($directory . '/galleries/' . $publicationChildPath), 'Could not create owned PDF child folder.');
    $publicationInsert->execute([
        $publicationId, $publicationChildPath, hash('sha256', $publicationChildPath),
        basename($publicationChildPath), 'Restricted scheduled PDF child', '', 'private', 'unlisted',
    ]);
    $publicationChildId = (int) $pdo->lastInsertId();
    $publicationArm->execute([$publicationChildId]);
    $publicationChildBefore = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationChildId]);
    $publicationCrawler->request('/index.php?page=home');
    check(
        row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationChildId]) === $publicationChildBefore,
        'Scheduled publication bypassed the incomplete PDF child guard.',
    );
    $publicationDelete = $pdo->prepare('DELETE FROM galleries WHERE id = ?');
    $publicationDelete->execute([$publicationChildId]);
    $publicationCrawler->request('/index.php?page=home');
    check(row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$publicationChildId]) === [], 'A deleted due gallery was recreated.');
    echo "PASS scheduled gallery actual editor, anonymous crawler GET, HEAD, concurrency, protection, sidecar, cancellation and PDF/deletion safety\n";
} catch (Throwable $exception) {
    // Assertion messages and their call sites are test-authored; arbitrary
    // application/transport exceptions may contain private fixture details.
    $location = basename($exception->getFile()) . ':' . $exception->getLine();
    $diagnostic = '';
    if (
        $exception instanceof RuntimeException
        && basename($exception->getFile()) === 'gallery_workflow_safety.php'
    ) {
        $assertion = $exception->getTrace()[0] ?? [];
        if (isset($assertion['file'], $assertion['line'])) {
            $location =
                basename((string) $assertion['file'])
                . ':'
                . (int) $assertion['line'];
        }
        $diagnostic = ': ' . $exception->getMessage();
    }
    fwrite(
        STDERR,
        'FAIL gallery edit ' . $stage . ' at ' . $location . $diagnostic . "\n",
    );
    exit(1);
}
