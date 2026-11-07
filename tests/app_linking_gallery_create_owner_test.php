<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_gallery_create_owner_test.php
 * Module Type: Database and Filesystem Regression Test
 * Purpose: Exercise linked gallery creation against an owned workflow fixture.
 * Responsibilities:
 *   - Preserve other galleries while assigning only the new canonical path.
 *   - Refuse lost transactions, collisions and unsafe catalog reconciliation.
 *   - Retain an ambiguous operation claim after a rolled-back filesystem write.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_safety.php';

use function GalleryWorkflow\check;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\validateFixture;

/**
 * Bound collision-free names owned by this disposable fixture invocation.
 * Type: int. Units: random bytes. Scope: each isolated test gallery identity.
 * Consumers: random_bytes. Rationale: 12 bytes match the fixture's 96-bit
 * database identity and make accidental naming collisions negligible.
 */
const LINKED_CREATE_FIXTURE_RANDOM_BYTES = 12;

/**
 * Bound the lifetime of one isolated create bearer.
 * Type: int. Units: seconds. Scope: disposable workflow grant only.
 * Consumers: linked create service fixture. Rationale: 900 seconds matches
 * the ordinary short-lived linking token while covering the isolated test.
 */
const LINKED_CREATE_FIXTURE_GRANT_SECONDS = 900;

/**
 * Bound one isolated CLI child.
 * Type: int. Units: seconds. Scope: one inspection process.
 * Consumers: linked_create_fixture_inspection. Rationale: 15 seconds allows
 * disposable bootstrap and a single database read without an unbounded hang.
 */
const LINKED_CREATE_INSPECTION_TIMEOUT_SECONDS = 15;

/**
 * Bound private output retained from one CLI child.
 * Type: int. Units: bytes per stream. Scope: inspection fixture.
 * Consumers: linked_create_fixture_inspection. Rationale: 4096 comfortably
 * contains the expected tiny JSON or generic error and bounds pipe memory.
 */
const LINKED_CREATE_INSPECTION_OUTPUT_BYTES = 4096;

/**
 * Bound the isolated inspection child's polling interval.
 * Type: int. Units: microseconds. Scope: inspection fixture polling.
 * Consumers: linked_create_fixture_inspection. Rationale: 50000 avoids busy waiting.
 */
const LINKED_CREATE_INSPECTION_POLL_US = 50000;

/**
 * Run the actual fixture CLI with bounded private input and output.
 *
 * @param string $directory Validated disposable installation root.
 * @param string $json Private evidence delivered only through stdin.
 * @return array{status:int,stdout:string,stderr:string} Child result; never logged.
 */
function linked_create_fixture_inspection(string $directory, string $json): array
{
    $pipes = [];
    $child = proc_open(
        [PHP_BINARY, $directory . '/scripts/reconcile_admin_operations.php',
            '--inspect-linked-create'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $directory,
    );
    check(is_resource($child), 'The isolated inspection CLI did not start.');
    $output = ['', ''];
    try {
        check(fwrite($pipes[0], $json) === strlen($json),
            'The isolated inspection evidence could not be delivered.');
        fclose($pipes[0]);
        unset($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $deadline = microtime(true) + LINKED_CREATE_INSPECTION_TIMEOUT_SECONDS;
        do {
            foreach ([1, 2] as $index) {
                $output[$index - 1] .= (string) stream_get_contents(
                    $pipes[$index], LINKED_CREATE_INSPECTION_OUTPUT_BYTES + 1,
                );
                check(strlen($output[$index - 1]) <= LINKED_CREATE_INSPECTION_OUTPUT_BYTES,
                    'The inspection child exceeded its output limit.');
            }
            $status = proc_get_status($child);
            if (!$status['running']) {
                foreach ([1, 2] as $index) {
                    $output[$index - 1] .= (string) stream_get_contents(
                        $pipes[$index], LINKED_CREATE_INSPECTION_OUTPUT_BYTES + 1,
                    );
                    check(strlen($output[$index - 1]) <= LINKED_CREATE_INSPECTION_OUTPUT_BYTES,
                        'The inspection child exceeded its output limit.');
                }
                return ['status' => $status['exitcode'],
                    'stdout' => $output[0], 'stderr' => $output[1]];
            }
            usleep(LINKED_CREATE_INSPECTION_POLL_US);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('The inspection child timed out.');
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $status = proc_get_status($child);
        if ($status['running']) {
            proc_terminate($child, 9);
        }
        proc_close($child);
    }
}

/**
 * Snapshot only stable gallery fields that creation must not change.
 *
 * @param PDO $connection Validated disposable workflow database connection.
 * @return list<array{id:int|string,parent_id:int|string|null,folder_path:string,slug:string,title:string,url_slug:?string,url_path:?string,url_path_hash:?string,edit_revision:int|string}> Ordered persisted gallery identities and addresses.
 */
function linked_create_fixture_snapshot(PDO $connection): array
{
    $statement = $connection->query(
        'SELECT id, parent_id, folder_path, slug, title, url_slug, url_path, '
            . 'url_path_hash, edit_revision FROM galleries ORDER BY id',
    );
    if ($statement === false) {
        throw new RuntimeException('Fixture gallery snapshot is unavailable.');
    }
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Remove only a test-owned gallery directory and its expected sidecar.
 *
 * @param string $root Canonical owned workflow gallery root.
 * @param string $leaf Unique direct-child name generated by this test.
 * @param string $title Exact title this test wrote into the sidecar.
 * @return void Removes the expected file and empty folder when present.
 */
function linked_create_fixture_remove(string $root, string $leaf, string $title): void
{
    check(preg_match('/\Alinked-create-[a-f0-9]{24}\z/D', $leaf) === 1,
        'Unexpected fixture gallery identity.');
    $path = $root . DIRECTORY_SEPARATOR . $leaf;
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    check(!is_link($path) && realpath($path) === $path && dirname($path) === $root,
        'Fixture gallery directory changed ownership.');
    $sidecar = $path . DIRECTORY_SEPARATOR . 'gallery.json';
    if (file_exists($sidecar) || is_link($sidecar)) {
        check(is_file($sidecar) && !is_link($sidecar),
            'Fixture sidecar changed ownership.');
        $metadata = json_decode((string) file_get_contents($sidecar), true);
        check(is_array($metadata) && ($metadata['title'] ?? null) === $title,
            'Fixture sidecar does not match its creation intent.');
        check(unlink($sidecar), 'Fixture sidecar could not be removed.');
    }
    check(rmdir($path), 'Fixture gallery directory was not empty or removable.');
}

/**
 * Remove the exact staging file returned by this fixture's preparation.
 *
 * @param array{stage:string,stage_dev:int,stage_ino:int,...} $stage
 *     Private staged sidecar proof owned by the disposable fixture.
 * @return void Removes that regular file or refuses unexpected evidence.
 */
function linked_create_fixture_remove_visibility_stage(array $stage): void
{
    clearstatcache(true, $stage['stage']);
    $info = lstat($stage['stage']);
    check(is_array($info) && !is_link($stage['stage']) && is_file($stage['stage'])
        && $info['dev'] === $stage['stage_dev'] && $info['ino'] === $stage['stage_ino']
        && unlink($stage['stage']), 'The owned staged fixture changed.');
}

/**
 * Reserve a test-owned target in the active disposable database transaction.
 *
 * @param string $folder Exact relative fixture target.
 * @param int $actor Owning fixture administrator.
 * @return array{folder:string,actor_id:int,key_hash:string,payload_hash:string} Reservation identity.
 */
function linked_create_fixture_reserve(string $folder, int $actor): array
{
    $reservation = [
        'folder' => $folder,
        'actor_id' => $actor,
        'key_hash' => hash('sha256', random_bytes(LINKED_CREATE_FIXTURE_RANDOM_BYTES)),
        'payload_hash' => hash('sha256', random_bytes(LINKED_CREATE_FIXTURE_RANDOM_BYTES)),
    ];
    \Gallery\Models\gallery_model_linked_reservation_insert(
        $folder, $actor, $reservation['key_hash'], $reservation['payload_hash'],
    );
    return $reservation;
}

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP')
        . " linked gallery creation requires an owned disposable database\n";
    exit($required ? 1 : 0);
}

$connection = null;
$writer = null;
$claim = null;
$claimReleased = false;
$abandonFixtureKeys = [];
$reservationFixtureKeys = [];
$reservationClaim = null;
$noEffectClaim = null;
$createdRows = [];
$ownedFolders = [];
$root = null;
try {
    $owner = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $owner);
    $verified = fixtureDatabase($directory, $owner);
    $configuration = require $directory . '/config.php';
    $root = realpath((string) ($configuration['galleries_root'] ?? ''));
    check(is_string($root) && $root === $directory . '/galleries'
        && !is_link($root), 'The owned gallery fixture root is unavailable.');

    // Load the actual owners from the validated disposable installation.
    require_once $directory . '/app/bootstrap_full.php';
    require_once $directory . '/app/models/app_linking.php';
    require_once $directory . '/app/services/admin_operation_keys.php';
    require_once $directory . '/app/services/app_linking_policy.php';
    require_once $directory . '/app/services/app_linking_create.php';
    require_once $directory . '/app/services/app_linking_schema.php';
    require_once $directory . '/app/services/app_linking.php';
    require_once $directory . '/app/services/gallery_visibility_inspection.php';
    require_once $directory . '/app/controllers/admin_galleries_discovery.php';
    $connection = \Gallery\Core\db();
    check($connection->query('SELECT DATABASE()')->fetchColumn()
        === $verified->query('SELECT DATABASE()')->fetchColumn(),
        'The application did not connect to the owned fixture database.');
    check(realpath(\Gallery\Services\galleries_root()) === $root,
        'The application did not use the owned fixture gallery root.');

    $before = linked_create_fixture_snapshot($connection);
    check($before !== [], 'The workflow fixture must contain existing galleries.');
    $actor = (int) $connection->query(
        "SELECT id FROM users WHERE username = 'workflow_admin' AND role = 'admin'",
    )->fetchColumn();
    check($actor > 0, 'The owned workflow administrator is unavailable.');
    check(\Gallery\Services\gallery_linked_reservation_schema_state() === 'available',
        'The disposable database must have the exact reservation schema.');
    // A nonexistent token lets both schema paths reach the real restore owner
    // without claiming an entry or touching a trash payload.
    $absentTrashToken = str_repeat('0', 32);
    $trashLookup = $connection->prepare(
        'SELECT COUNT(*) FROM gallery_trash_entries WHERE trash_token = ?',
    );
    $trashLookup->execute([$absentTrashToken]);
    check((int) $trashLookup->fetchColumn() === 0,
        'The restore compatibility token must not identify an entry.');
    // Confirm the existing empty-selection results on current and genuinely
    // pre-migration schema paths; no fixture objects are changed.
    foreach (['available', 'legacy'] as $schemaState) {
        if ($schemaState === 'legacy') {
            \Gallery\Services\schema_inspection_set_query_executor_for_tests(
                static fn(string $kind, string $table): bool => !(
                    $kind === 'table' && $table === 'linked_gallery_create_reservations'
                ),
            );
        }
        try {
            check(\Gallery\Services\gallery_linked_reservation_schema_state() === $schemaState,
                'The discovery compatibility scenario must use its intended schema state.');
            $emptyMove = \Gallery\Services\admin_gallery_discovery_move_requested_photos([], 0);
            $emptyDelete = \Gallery\Services\admin_gallery_discovery_delete_requested_paths([]);
            check($emptyMove['ok'] === false && $emptyMove['action'] === 'move_photos'
                && $emptyDelete['ok'] === true && $emptyDelete['deleted_folders'] === 0
                && linked_create_fixture_snapshot($connection) === $before,
                'Unblocked discovery actions must retain empty-selection behavior.');
            $emptyTrash = \Gallery\Services\move_gallery_subtrees_to_trash([]);
            check($emptyTrash['root_count'] === 0 && $emptyTrash['entries'] === []
                && linked_create_fixture_snapshot($connection) === $before,
                'Trash movement must retain empty-selection behavior on current and legacy schemas.');
            foreach (['batch', 'complete'] as $organizerMode) {
                $normalRefusal = false;
                try {
                    if ($organizerMode === 'batch') {
                        \Gallery\Services\gallery_metadata_organizer_apply_date_plan_batch(0);
                    } else {
                        \Gallery\Services\gallery_metadata_organizer_apply_date_plan(0);
                    }
                } catch (\Gallery\Services\LinkedGalleryReservationUnavailable) {
                    throw new RuntimeException('Legacy or current organizer admission was refused.');
                } catch (RuntimeException $exception) {
                    $normalRefusal = $exception->getMessage() === \Gallery\Services\t(
                        'admin.metadata_organizer.gallery_missing',
                        'Gallery was not found.',
                    );
                }
                check($normalRefusal && linked_create_fixture_snapshot($connection) === $before,
                    'Both organizer modes must retain normal invalid-gallery behavior without a pending reservation.');
            }
            $restoreReason = '';
            try {
                \Gallery\Services\restore_gallery_trash_entry($absentTrashToken);
            } catch (RuntimeException $exception) {
                $restoreReason = $exception->getMessage();
            }
            check($restoreReason === 'Trash entry was not found.'
                && linked_create_fixture_snapshot($connection) === $before,
                'Unblocked restore must retain its normal missing-entry behavior on current and legacy schemas.');
        } finally {
            \Gallery\Services\schema_inspection_set_query_executor_for_tests(null);
        }
    }
    foreach (['missing', 'unknown'] as $schemaState) {
        \Gallery\Services\schema_inspection_set_query_executor_for_tests(
            static function (
                string $kind, string $table, string $object, mixed $definition = null,
            ) use ($schemaState): bool {
                if ($kind === 'column_exact'
                    && $table === 'linked_gallery_create_reservations'
                    && $object === 'active_path_hash') {
                    if ($schemaState === 'unknown') {
                        throw new RuntimeException('Fixture reservation metadata is unavailable.');
                    }
                    return false;
                }
                return true;
            },
        );
        try {
            check(\Gallery\Services\gallery_linked_reservation_schema_state() === 'unavailable',
                'A partial or unknown reservation schema must not authorize writes.');
            $schemaRefused = false;
            try {
                \Gallery\Services\gallery_linked_reservation_assert_admin_write();
            } catch (RuntimeException) {
                $schemaRefused = true;
            }
            check($schemaRefused && linked_create_fixture_snapshot($connection) === $before,
                'A partial or unknown reservation schema must block covered writes.');
            $reason = '';
            try {
                \Gallery\Services\move_gallery_subtrees_to_trash([]);
            } catch (RuntimeException $exception) {
                $reason = $exception->getMessage();
            }
            check($reason === 'A linked gallery creation needs reconciliation before this operation.'
                && linked_create_fixture_snapshot($connection) === $before,
                'Trash movement must refuse partial or unknown reservation schema before its owned work.');
            foreach (['batch', 'complete'] as $organizerMode) {
                $reason = '';
                try {
                    if ($organizerMode === 'batch') {
                        \Gallery\Services\gallery_metadata_organizer_apply_date_plan_batch(0);
                    } else {
                        \Gallery\Services\gallery_metadata_organizer_apply_date_plan(0);
                    }
                } catch (RuntimeException $exception) {
                    $reason = $exception->getMessage();
                }
                check($reason === 'A linked gallery creation needs reconciliation before this operation.'
                    && linked_create_fixture_snapshot($connection) === $before,
                    'Both organizer modes must refuse partial or unknown reservation storage.');
            }
            foreach (['move', 'delete'] as $discoveryAction) {
                $reason = '';
                try {
                    if ($discoveryAction === 'move') {
                        \Gallery\Services\admin_gallery_discovery_move_requested_photos([], 0);
                    } else {
                        \Gallery\Services\admin_gallery_discovery_delete_requested_paths([]);
                    }
                } catch (RuntimeException $exception) {
                    $reason = $exception->getMessage();
                }
                check($reason === 'A linked gallery creation needs reconciliation before this operation.'
                    && linked_create_fixture_snapshot($connection) === $before,
                    'Discovery disk actions must refuse partial or unknown reservation schema.');
            }
            foreach (['images', 'subtrees'] as $copyAction) {
                $reason = '';
                try {
                    if ($copyAction === 'images') {
                        \Gallery\Services\copy_gallery_images(0, 0, []);
                    } else {
                        \Gallery\Services\picture_manager_copy_gallery_subtrees(0, 0, []);
                    }
                } catch (RuntimeException $exception) {
                    $reason = $exception->getMessage();
                }
                check($reason === 'A linked gallery creation needs reconciliation before this operation.'
                    && linked_create_fixture_snapshot($connection) === $before,
                    'Picture manager copies must refuse partial or unknown reservation schema.');
            }
            $reason = '';
            try {
                \Gallery\Services\restore_gallery_trash_entry($absentTrashToken);
            } catch (RuntimeException $exception) {
                $reason = $exception->getMessage();
            }
            check($reason === 'A linked gallery creation needs reconciliation before this operation.'
                && linked_create_fixture_snapshot($connection) === $before,
                'Trash restore must refuse partial or unknown reservation schema before claiming an entry.');
        } finally {
            \Gallery\Services\schema_inspection_set_query_executor_for_tests(null);
        }
    }
    foreach (['missing', 'unknown'] as $schemaState) {
        \Gallery\Services\schema_inspection_set_query_executor_for_tests(
            static function (
                string $kind, string $table, string $object, mixed $definition = null,
            ) use ($schemaState): bool {
                if ($kind === 'column_exact' && $table === 'admin_operation_keys'
                    && $object === 'state') {
                    if ($schemaState === 'unknown') {
                        throw new RuntimeException('Fixture metadata is unavailable.');
                    }
                    return false;
                }
                return true;
            },
        );
        try {
            $schemaRefused = false;
            try {
                \Gallery\Services\app_linking_create_require_abandon_schema();
            } catch (Throwable) {
                $schemaRefused = true;
            }
            check($schemaRefused && linked_create_fixture_snapshot($connection) === $before,
                'Missing or unknown tombstone storage must refuse without gallery changes.');
        } finally {
            \Gallery\Services\schema_inspection_set_query_executor_for_tests(null);
        }
    }

    // Reach the real create catch after reservation commit but before mkdir:
    // one owned catalog hash is temporarily made inconsistent and restored by
    // exact compare-and-set, never by scanning or changing unrelated rows.
    $realIssuer = 'https://gallery.example.test/Galerie';
    $realBearer = \Gallery\Services\app_linking_access_token_generate();
    $realTokenHash = \Gallery\Services\app_linking_access_token_hash($realBearer);
    check(is_string($realTokenHash), 'The fixture bearer is unavailable.');
    $realKey = \Gallery\Services\admin_operation_new_key();
    $realClaimHash = hash('sha256', \Gallery\Services\app_linking_create_ledger_key(
        $realKey, $realIssuer,
    ));
    $reservationFixtureKeys[] = $realClaimHash;
    $abandonFixtureKeys[] = $realClaimHash;
    $realTitle = 'Linked Preflight ' . bin2hex(random_bytes(LINKED_CREATE_FIXTURE_RANDOM_BYTES));
    $realLeaf = \Gallery\Services\gallery_folder_segment($realTitle);
    check(!file_exists($root . '/' . $realLeaf) && !is_link($root . '/' . $realLeaf),
        'The preflight fixture target is occupied.');
    $seedId = (int) $before[0]['id'];
    $savedHash = $before[0]['url_path_hash'];
    check(is_string($savedHash) && strlen($savedHash) === 64,
        'The fixture catalog hash is unavailable.');
    $driftHash = hash('sha256', random_bytes(LINKED_CREATE_FIXTURE_RANDOM_BYTES));
    $grantId = 0;
    $catalogModified = false;
    try {
        $grantInsert = $connection->prepare(
            'INSERT INTO app_link_grants '
            . '(user_id, client_id, issuer, scope, created_at, access_token_hash, '
            . 'access_issued_at, access_expires_at) VALUES (?, ?, ?, ?, '
            . 'UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))',
        );
        $grantInsert->execute([
            $actor, \Gallery\Services\APP_LINKING_WINAPP_CLIENT_ID, $realIssuer,
            \Gallery\Services\APP_LINKING_CREATE_SCOPE, $realTokenHash,
            LINKED_CREATE_FIXTURE_GRANT_SECONDS,
        ]);
        $grantId = (int) $connection->lastInsertId();
        check($grantId > 0, 'The fixture grant was not created.');
        $tamperReal = $connection->prepare(
            'UPDATE galleries SET url_path_hash = ? WHERE id = ? AND BINARY url_path_hash = BINARY ?',
        );
        $tamperReal->execute([$driftHash, $seedId, $savedHash]);
        check($tamperReal->rowCount() === 1, 'The owned catalog drift was not installed.');
        $catalogModified = true;
        $refused = false;
        try {
            \Gallery\Services\app_linking_create_gallery(
                $realBearer, $realIssuer, $realKey,
                ['title' => $realTitle, 'parent_id' => 0],
            );
        } catch (Throwable) {
            $refused = true;
        }
        $claimRow = \Gallery\Models\admin_operation_model_find($actor, $realClaimHash);
        $reservationQuery = $connection->prepare(
            'SELECT folder_path, state, active_path_hash FROM linked_gallery_create_reservations '
            . 'WHERE actor_id = ? AND key_hash = ?',
        );
        $reservationQuery->execute([$actor, $realClaimHash]);
        $realReservation = $reservationQuery->fetch(PDO::FETCH_ASSOC);
        check($refused && is_array($claimRow) && $claimRow['state'] === 'abandoned'
            && is_array($realReservation) && $realReservation['folder_path'] === $realLeaf
            && $realReservation['state'] === 'no_effect'
            && $realReservation['active_path_hash'] === null
            && !file_exists($root . '/' . $realLeaf) && !is_link($root . '/' . $realLeaf),
            'The real pre-mkdir failure must atomically close its owned reservation.');
        \Gallery\Services\gallery_linked_reservation_assert_admin_write();
        $driftSnapshot = linked_create_fixture_snapshot($connection);
        check(\Gallery\Services\app_linking_create_status(
            $realBearer, $realIssuer, $realKey,
        ) === ['state' => 'unknown'],
            'Status must not report a completed gallery for a closed no-effect claim.');
        check(\Gallery\Services\app_linking_create_abandon(
            $realBearer, $realIssuer, $realKey,
            ['title' => $realTitle, 'parent_id' => 0],
        ) === ['state' => 'abandoned'],
            'Explicit close must confirm the retained original tombstone.');
        \Gallery\Services\gallery_linked_reservation_assert_admin_write();
        $replayRefused = false;
        try {
            \Gallery\Services\app_linking_create_gallery(
                $realBearer, $realIssuer, $realKey,
                ['title' => $realTitle, 'parent_id' => 0],
            );
        } catch (Throwable) {
            $replayRefused = true;
        }
        $reservationQuery->execute([$actor, $realClaimHash]);
        check($replayRefused
            && \Gallery\Models\admin_operation_model_find($actor, $realClaimHash) === $claimRow
            && $reservationQuery->fetch(PDO::FETCH_ASSOC) === $realReservation
            && linked_create_fixture_snapshot($connection) === $driftSnapshot
            && !file_exists($root . '/' . $realLeaf) && !is_link($root . '/' . $realLeaf),
            'The same closed key must not start new database or filesystem work.');
    } finally {
        try {
            if ($catalogModified) {
                $restoreReal = $connection->prepare(
                    'UPDATE galleries SET url_path_hash = ? WHERE id = ? AND BINARY url_path_hash = BINARY ?',
                );
                $restoreReal->execute([$savedHash, $seedId, $driftHash]);
                check($restoreReal->rowCount() === 1, 'The owned catalog value could not be restored.');
            }
        } finally {
            if ($grantId > 0) {
                $removeGrant = $connection->prepare(
                    'DELETE FROM app_link_grants WHERE id = ? AND user_id = ? AND access_token_hash = ?',
                );
                $removeGrant->execute([$grantId, $actor, $realTokenHash]);
            }
        }
    }
    check(linked_create_fixture_snapshot($connection) === $before,
        'The preflight fixture must restore all original gallery fields.');

    // The model guards must refuse both writes after a real transaction ends.
    $lostTransaction = false;
    try {
        \Gallery\Models\gallery_model_insert_linked_owned(['title' => 'unused']);
    } catch (RuntimeException) {
        $lostTransaction = true;
    }
    check($lostTransaction && linked_create_fixture_snapshot($connection) === $before,
        'A linked insert without its transaction must leave all rows unchanged.');
    $lostTransaction = false;
    try {
        \Gallery\Models\public_path_model_assign_linked_creation(
            (int) $before[0]['id'], 'blocked', 'blocked', 'unpublished', [], true, true,
        );
    } catch (RuntimeException) {
        $lostTransaction = true;
    }
    check($lostTransaction && linked_create_fixture_snapshot($connection) === $before,
        'A linked path update without its transaction must leave all rows unchanged.');

    $writer = \Gallery\Services\gallery_edit_writer_begin();
    $suffix = bin2hex(random_bytes(LINKED_CREATE_FIXTURE_RANDOM_BYTES));
    $title = 'Linked Create ' . $suffix;
    $leaf = \Gallery\Services\gallery_folder_segment($title);
    check(!file_exists($root . '/' . $leaf) && !is_link($root . '/' . $leaf),
        'The unique success fixture folder already exists.');
    $ownedFolders[] = [$leaf, $title];
    $connection->beginTransaction();
    $reservation = linked_create_fixture_reserve($leaf, $actor);
    $wrongReservation = $reservation;
    $wrongReservation['folder'] .= '-other';
    $reservationRefused = false;
    $filesystemStarted = false;
    try {
        \Gallery\Services\create_empty_gallery_linked_owned([
            'title' => $title, 'parent_id' => 0,
        ], $wrongReservation, $filesystemStarted);
    } catch (RuntimeException) {
        $reservationRefused = true;
    }
    check($reservationRefused && !$filesystemStarted && !file_exists($root . '/' . $leaf)
        && linked_create_fixture_snapshot($connection) === $before,
        'A mismatched reservation must refuse before any gallery or filesystem write.');
    $filesystemStarted = false;
    $created = \Gallery\Services\create_empty_gallery_linked_owned([
        'title' => $title, 'parent_id' => 0,
    ], $reservation, $filesystemStarted);
    check($filesystemStarted, 'Successful creation must cross the filesystem boundary.');
    $newId = (int) $created['id'];
    check($newId > 0 && $created['title'] === $title
        && ($created['parent_id'] ?? null) === null,
        'The owner did not return the intended root gallery.');
    $createdRows[] = [$newId, $leaf];
    $proof = $created['_linked_creation_fs'];
    \Gallery\Services\gallery_linked_creation_assert_filesystem($proof);
    $existingBytes = file_get_contents($proof['sidecar']);
    $existingRefused = false;
    try {
        \Gallery\Services\gallery_linked_creation_write_sidecar(
            $proof['sidecar'], 'replacement must not be written',
        );
    } catch (RuntimeException) {
        $existingRefused = true;
    }
    check($existingRefused && file_get_contents($proof['sidecar']) === $existingBytes,
        'Exclusive sidecar creation must preserve an existing file.');

    // Substitute only objects created by this fixture, then restore the exact
    // original file; a final symlink must not be followed or accepted as proof.
    $sidecarBackup = $proof['sidecar'] . '.fixture-original';
    check(rename($proof['sidecar'], $sidecarBackup),
        'Could not isolate the owned sidecar for replacement checks.');
    $fixtureLink = false;
    try {
        if (function_exists('symlink') && @symlink($sidecarBackup, $proof['sidecar'])) {
            $fixtureLink = true;
            $linkWriteRefused = false;
            try {
                \Gallery\Services\gallery_linked_creation_write_sidecar(
                    $proof['sidecar'], 'replacement must not be written',
                );
            } catch (RuntimeException) {
                $linkWriteRefused = true;
            }
            $linkProofRefused = false;
            try {
                \Gallery\Services\gallery_linked_creation_assert_filesystem($proof);
            } catch (RuntimeException) {
                $linkProofRefused = true;
            }
            check($linkWriteRefused && $linkProofRefused
                && file_get_contents($sidecarBackup) === $existingBytes,
                'A final sidecar symlink must be refused without modifying its target.');
        }
    } finally {
        if ($fixtureLink) {
            check(is_link($proof['sidecar'])
                && readlink($proof['sidecar']) === $sidecarBackup
                && unlink($proof['sidecar']),
                'The fixture symlink changed ownership or could not be removed.');
        }
        check(rename($sidecarBackup, $proof['sidecar']),
            'Could not restore the owned sidecar.');
    }
    \Gallery\Services\gallery_linked_creation_assert_filesystem($proof);

    // A replacement directory at the same name must not pass a pre-commit
    // identity check, even if it is still inside the trusted gallery root.
    $folderBackup = $proof['folder'] . '-fixture-original';
    check(rename($proof['folder'], $folderBackup),
        'Could not isolate the owned gallery directory.');
    $replacementStat = null;
    try {
        check(mkdir($proof['folder']), 'Could not create the replacement fixture directory.');
        $replacementStat = lstat($proof['folder']);
        check(is_array($replacementStat), 'Could not identify the replacement directory.');
        $folderRefused = false;
        try {
            \Gallery\Services\gallery_linked_creation_assert_filesystem($proof);
        } catch (RuntimeException) {
            $folderRefused = true;
        }
        check($folderRefused, 'A substituted gallery directory must be refused.');
    } finally {
        if (is_array($replacementStat)) {
            clearstatcache(true, $proof['folder']);
            $observedReplacement = lstat($proof['folder']);
            check(is_array($observedReplacement) && !is_link($proof['folder'])
                && $observedReplacement['dev'] === $replacementStat['dev']
                && $observedReplacement['ino'] === $replacementStat['ino']
                && rmdir($proof['folder']),
                'The replacement fixture directory changed ownership.');
        }
        check(rename($folderBackup, $proof['folder']),
            'Could not restore the owned gallery directory.');
    }
    \Gallery\Services\gallery_linked_creation_assert_filesystem($proof);
    // This direct-owner scenario has no ledger claim; remove only its own
    // transactional reservation before committing the test gallery.
    $deleteReservation = $connection->prepare(
        'DELETE FROM linked_gallery_create_reservations WHERE actor_id = ? AND key_hash = ?',
    );
    $deleteReservation->execute([$actor, $reservation['key_hash']]);
    check($deleteReservation->rowCount() === 1, 'The owned reservation must be removed.');
    $connection->commit();
    $after = linked_create_fixture_snapshot($connection);
    $newRows = array_values(array_filter($after,
        static fn(array $row): bool => (int) $row['id'] === $newId));
    $others = array_values(array_filter($after,
        static fn(array $row): bool => (int) $row['id'] !== $newId));
    check($others === $before && count($newRows) === 1,
        'Creating a linked gallery must not change any existing row or revision.');
    check(is_string($newRows[0]['url_path']) && $newRows[0]['url_path'] !== ''
        && hash_equals(hash('sha256', $newRows[0]['url_path']),
            (string) $newRows[0]['url_path_hash'])
        && is_file($root . '/' . $leaf . '/gallery.json'),
        'The new gallery must have a canonical persisted path and sidecar.');
    $settings = $connection->prepare(
        'SELECT visibility, access_mode, access_listing, scheduled_publish_at '
            . 'FROM galleries WHERE id = ?',
    );
    $settings->execute([$newId]);
    $stored = $settings->fetch(PDO::FETCH_ASSOC);
    check(is_array($stored)
        && in_array($stored['visibility'], ['unpublished', 'draft'], true)
        && $stored['access_mode'] === 'normal'
        && $stored['access_listing'] === 'listed'
        && $stored['scheduled_publish_at'] === null,
        'Creation must retain unpublished visibility and independent access defaults without a schedule.');
    // Escaped keys, nested homonyms and large numeric literals stay byte-exact.
    $jsonBefore = '{"\\u0076isibility":"draft","nested":{"visibility":"private"},'
        . '"large":18446744073709551616,"number":0.1234567890123456789}';
    $jsonAfter = '{"\\u0076isibility":"public","nested":{"visibility":"private"},'
        . '"large":18446744073709551616,"number":0.1234567890123456789}';
    check(\Gallery\Services\gallery_sidecar_visibility_replacement(
        $jsonBefore, 'draft', 'public',
    ) === $jsonAfter, 'A visibility patch must preserve every unrelated JSON byte.');
    $duplicateRefused = false;
    try {
        \Gallery\Services\gallery_sidecar_visibility_replacement(
            '{"visibility":"draft","\\u0076isibility":"draft"}', 'draft', 'private',
        );
    } catch (Throwable) {
        $duplicateRefused = true;
    }
    check($duplicateRefused, 'Duplicate top-level visibility keys must be refused.');
    $sidecarPath = $root . '/' . $leaf . '/gallery.json';
    $originalSidecar = file_get_contents($sidecarPath);
    check(is_string($originalSidecar), 'The created sidecar must be readable.');
    $connection->beginTransaction();
    try {
        $createdGallery = \Gallery\Models\gallery_model_linked_visibility_lock($newId);
        check(is_array($createdGallery), 'The committed gallery row must remain available.');
        $connection->commit();
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
    $nested = $root . '/' . $leaf . '/fixture-nested';
    check(mkdir($nested), 'Could not create the owned marker subtree fixture.');
    $nestedMarker = $nested . '/' . \Gallery\Services\LINKED_VISIBILITY_PENDING_MARKER;
    $nestedHandle = fopen($nestedMarker, 'x+b');
    check(is_resource($nestedHandle), 'Could not create the owned pending marker.');
    fclose($nestedHandle);
    try {
        $nestedRefused = false;
        try {
            \Gallery\Services\gallery_visibility_marker_assert_importable(
                $leaf . '/fixture-nested',
            );
        } catch (RuntimeException) {
            $nestedRefused = true;
        }
        $treeRefused = false;
        try {
            \Gallery\Services\gallery_visibility_marker_assert_subtree_clear($leaf);
        } catch (RuntimeException) {
            $treeRefused = true;
        }
        check($nestedRefused && $treeRefused,
            'A descendant marker must block its import and full subtree observation.');
    } finally {
        check(is_file($nestedMarker) && !is_link($nestedMarker) && unlink($nestedMarker),
            'The owned marker fixture could not be removed.');
        check(rmdir($nested), 'The owned marker directory could not be removed.');
    }
    \Gallery\Services\gallery_visibility_marker_assert_importable($leaf);
    \Gallery\Services\gallery_visibility_marker_assert_subtree_clear($leaf);
    $folderStat = stat($root . '/' . $leaf);
    $sidecarStat = stat($sidecarPath);
    check(is_array($folderStat) && is_array($sidecarStat),
        'The owned visibility fixture must have observable identities.');
    $syncAvailable = true;
    try {
        \Gallery\Services\gallery_visibility_sync_directory(
            $root . '/' . $leaf, $folderStat['dev'], $folderStat['ino'],
        );
        \Gallery\Services\gallery_visibility_sync_file(
            $sidecarPath, $sidecarStat['dev'], $sidecarStat['ino'],
        );
    } catch (Throwable) {
        $syncAvailable = false;
    }
    if ($syncAvailable) {
    $protection = \Gallery\Services\gallery_visibility_marker_prepare($createdGallery, 'public');
    try {
        \Gallery\Services\gallery_visibility_marker_assert_owned($protection['marker']);
        $privateSidecar = file_get_contents($sidecarPath);
        check(is_string($privateSidecar)
            && json_decode($privateSidecar, true)['visibility'] === 'private',
            'A durable marker and private sidecar must precede a target update.');
        $markedRefused = false;
        try {
            \Gallery\Services\gallery_visibility_marker_assert_importable($leaf);
        } catch (RuntimeException) {
            $markedRefused = true;
        }
        check($markedRefused, 'The persistent marker must refuse import observation.');
        $observed = \Gallery\Services\gallery_visibility_inspect($newId);
        check($observed['gallery_id'] === $newId
            && $observed['database_visibility'] === $createdGallery['visibility']
            && $observed['sidecar_visibility'] === 'private'
            && $observed['marker_retained'] === true
            && file_get_contents($sidecarPath) === $privateSidecar
            && file_get_contents($protection['marker']['path']) === $protection['marker']['content'],
            'Inspection must report current states while preserving marker and sidecar bytes.');
        foreach (['missing', 'unknown'] as $inspectionSchemaState) {
            \Gallery\Services\schema_inspection_set_query_executor_for_tests(
                static function (
                    string $kind, string $table, string $object, mixed $definition = null,
                ) use ($inspectionSchemaState): bool {
                    if ($kind === 'column' && $table === 'galleries'
                        && $object === 'edit_revision') {
                        if ($inspectionSchemaState === 'unknown') {
                            throw new RuntimeException('Fixture metadata is unavailable.');
                        }
                        return false;
                    }
                    return true;
                },
            );
            try {
                $inspectionRefused = false;
                try {
                    \Gallery\Services\gallery_visibility_inspect($newId);
                } catch (Throwable) {
                    $inspectionRefused = true;
                }
                check($inspectionRefused
                    && file_get_contents($sidecarPath) === $privateSidecar
                    && file_get_contents($protection['marker']['path']) === $protection['marker']['content'],
                    'Missing or unknown inspection schema must retain all evidence.');
            } finally {
                \Gallery\Services\schema_inspection_set_query_executor_for_tests(null);
            }
        }
        file_put_contents($protection['marker']['path'], $protection['marker']['content'] . ' ');
        try {
            $inspectionRefused = false;
            try {
                \Gallery\Services\gallery_visibility_inspect($newId);
            } catch (Throwable) {
                $inspectionRefused = true;
            }
            check($inspectionRefused
                && file_get_contents($protection['marker']['path']) === $protection['marker']['content'] . ' '
                && file_get_contents($sidecarPath) === $privateSidecar,
                'A noncanonical marker must be refused without changing evidence.');
        } finally {
            file_put_contents($protection['marker']['path'], $protection['marker']['content']);
        }
        $markerBackup = $protection['marker']['path'] . '.fixture-original';
        check(rename($protection['marker']['path'], $markerBackup),
            'The owned marker could not be isolated for the symlink check.');
        $markerLink = false;
        try {
            if (function_exists('symlink') && @symlink($markerBackup, $protection['marker']['path'])) {
                $markerLink = true;
                $inspectionRefused = false;
                try {
                    \Gallery\Services\gallery_visibility_inspect($newId);
                } catch (Throwable) {
                    $inspectionRefused = true;
                }
                check($inspectionRefused && file_get_contents($markerBackup) === $protection['marker']['content']
                    && file_get_contents($sidecarPath) === $privateSidecar,
                    'Inspection must refuse a marker symlink without changing its target.');
            }
        } finally {
            if ($markerLink) {
                check(is_link($protection['marker']['path'])
                    && readlink($protection['marker']['path']) === $markerBackup
                    && unlink($protection['marker']['path']),
                    'The owned marker symlink changed.');
            }
            check(rename($markerBackup, $protection['marker']['path']),
                'The owned marker could not be restored.');
        }
        file_put_contents($sidecarPath, $privateSidecar . ' ');
        try {
            $inspectionRefused = false;
            try {
                \Gallery\Services\gallery_visibility_inspect($newId);
            } catch (Throwable) {
                $inspectionRefused = true;
            }
            check($inspectionRefused
                && file_get_contents($sidecarPath) === $privateSidecar . ' '
                && file_get_contents($protection['marker']['path']) === $protection['marker']['content'],
                'Inspection must refuse unrecognized sidecar bytes without repairing evidence.');
        } finally {
            file_put_contents($sidecarPath, $privateSidecar);
        }
    } finally {
        linked_create_fixture_remove_visibility_stage($protection['sidecar']);
        $marker = $protection['marker'];
        \Gallery\Services\gallery_visibility_marker_assert_owned($marker);
        check(unlink($marker['path']), 'The owned marker fixture could not be removed.');
        file_put_contents($sidecarPath, $originalSidecar);
    }
    $prepared = \Gallery\Services\gallery_sidecar_visibility_prepare($createdGallery, 'private');
    try {
        check(file_get_contents($sidecarPath) === $originalSidecar,
            'Preparing a visibility change must not modify the original sidecar.');
        file_put_contents($prepared['stage'], 'incomplete');
        $truncatedRefused = false;
        try {
            \Gallery\Services\gallery_sidecar_visibility_publish($prepared);
        } catch (RuntimeException) {
            $truncatedRefused = true;
        }
        check($truncatedRefused && file_get_contents($sidecarPath) === $originalSidecar,
            'A truncated stage must not replace the original sidecar.');
    } finally {
        @unlink($prepared['stage']);
    }
    $prepared = \Gallery\Services\gallery_sidecar_visibility_prepare($createdGallery, 'private');
    try {
        \Gallery\Services\gallery_sidecar_visibility_publish($prepared);
        check(file_get_contents($sidecarPath) === $prepared['replacement'],
            'Publication must read back the complete verified replacement.');
    } finally {
        @unlink($prepared['stage']);
        file_put_contents($sidecarPath, $originalSidecar);
    }
    } else {
        $refused = false;
        try {
            \Gallery\Services\gallery_visibility_marker_prepare($createdGallery, 'private');
        } catch (Throwable) {
            $refused = true;
        }
        check($refused && file_get_contents($sidecarPath) === $originalSidecar
            && !file_exists($root . '/' . $leaf . '/'
                . \Gallery\Services\LINKED_VISIBILITY_PENDING_MARKER),
            'Unavailable synchronization must refuse before marker or sidecar changes.');
    }
    if ($syncAvailable) {
        $baseRow = $createdGallery;
        $baseRevision = \Gallery\Services\gallery_edit_revision($baseRow);
        $baseVisibility = $baseRow['visibility'];

        $connection->beginTransaction();
        try {
            $lockedRow = \Gallery\Models\gallery_model_linked_visibility_lock($newId);
            check($lockedRow === $baseRow,
                'The recovery fixture must start from its exact locked database row.');
            $connection->commit();
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }
        $recovery = \Gallery\Services\gallery_visibility_marker_prepare(
            $lockedRow, 'public',
        );
        try {
            $privateEvidence = file_get_contents($sidecarPath);
            check(is_string($privateEvidence)
                && json_decode($privateEvidence, true)['visibility'] === 'private',
                'Recovery must retain the private sidecar before database publication.');
            $review = \Gallery\Services\gallery_visibility_inspect($newId);
            check($review['database_visibility'] === $baseVisibility
                && $review['sidecar_visibility'] === 'private'
                && $review['revision'] === $baseRevision
                && $review['marker_retained'] === true
                && is_string($review['review_fingerprint']),
                'Pre-commit inspection must offer review without changing the row.');
            $reviewFingerprint = $review['review_fingerprint'];
            $markerBytes = file_get_contents($recovery['marker']['path']);
            $staleRefused = false;
            try {
                \Gallery\Services\gallery_visibility_inspect(
                    $newId, hash('sha256', 'stale visibility review'),
                );
            } catch (Throwable) {
                $staleRefused = true;
            }
            check($staleRefused && file_get_contents($sidecarPath) === $privateEvidence
                && file_get_contents($recovery['marker']['path']) === $markerBytes,
                'A stale review fingerprint must preserve the marker and sidecar.');

            file_put_contents($sidecarPath, $privateEvidence . ' ');
            try {
                $driftRefused = false;
                try {
                    \Gallery\Services\gallery_visibility_inspect(
                        $newId, $reviewFingerprint,
                    );
                } catch (Throwable) {
                    $driftRefused = true;
                }
                check($driftRefused
                    && file_get_contents($sidecarPath) === $privateEvidence . ' '
                    && file_get_contents($recovery['marker']['path']) === $markerBytes,
                    'Sidecar drift must refuse reviewed restoration without changing evidence.');
            } finally {
                file_put_contents($sidecarPath, $privateEvidence);
            }

            $restored = \Gallery\Services\gallery_visibility_inspect(
                $newId, $reviewFingerprint,
            );
            check($restored['database_visibility'] === $baseVisibility
                && $restored['sidecar_visibility'] === $baseVisibility
                && $restored['sidecar_matches_database'] === true
                && $restored['marker_retained'] === false
                && $restored['revision'] === $baseRevision
                && $restored['review_fingerprint'] === null
                && file_get_contents($sidecarPath) === $originalSidecar
                && !file_exists($recovery['marker']['path']),
                'Reviewed pre-commit recovery must restore the original sidecar and retire its marker.');
            $connection->beginTransaction();
            try {
                $afterRestore = \Gallery\Models\gallery_model_linked_visibility_lock($newId);
                $connection->commit();
            } finally {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
            }
            check($afterRestore === $baseRow,
                'Pre-commit sidecar recovery must not write or revise the database row.');
        } finally {
            linked_create_fixture_remove_visibility_stage($recovery['sidecar']);
            if (file_exists($recovery['marker']['path'])) {
                $ownedMarker = $recovery['marker'];
                \Gallery\Services\gallery_visibility_marker_assert_owned($ownedMarker);
                unlink($ownedMarker['path']);
            }
            file_put_contents($sidecarPath, $originalSidecar);
        }

        $postCommitMarker = null;
        try {
            $connection->beginTransaction();
            try {
                $lockedRow = \Gallery\Models\gallery_model_linked_visibility_lock($newId);
                check($lockedRow === $baseRow,
                    'The committed-recovery fixture must start from its original row.');
                $postCommitMarker = \Gallery\Services\gallery_visibility_marker_prepare(
                    $lockedRow, 'public',
                );
                check(\Gallery\Models\gallery_model_linked_visibility_update(
                    $newId, $baseRevision, 'public',
                ), 'The recovery fixture could not commit its target visibility.');
                $connection->commit();
            } finally {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
            }
            $committedRow = \Gallery\Models\gallery_model_visibility_inspect(
                $newId,
                static fn(array $row): array => $row,
            );
            $committedRevision = \Gallery\Services\gallery_edit_revision($committedRow);
            $postCommitReview = \Gallery\Services\gallery_visibility_inspect($newId);
            check($postCommitReview['database_visibility'] === 'public'
                && $postCommitReview['sidecar_visibility'] === 'private'
                && $postCommitReview['revision'] === $committedRevision
                && $postCommitReview['marker_retained'] === true
                && is_string($postCommitReview['review_fingerprint']),
                'A committed target with private sidecar must remain reviewable after interruption.');
            $postCommitResult = \Gallery\Services\gallery_visibility_inspect(
                $newId, $postCommitReview['review_fingerprint'],
            );
            check($postCommitResult['database_visibility'] === 'public'
                && $postCommitResult['sidecar_visibility'] === 'public'
                && $postCommitResult['sidecar_matches_database'] === true
                && $postCommitResult['revision'] === $committedRevision
                && $postCommitResult['marker_retained'] === false
                && $postCommitResult['review_fingerprint'] === null
                && json_decode((string) file_get_contents($sidecarPath), true)['visibility'] === 'public'
                && !file_exists($postCommitMarker['marker']['path']),
                'Reviewed post-commit recovery must publish the target sidecar and retire its marker.');
            $afterPostCommitRecovery = \Gallery\Models\gallery_model_visibility_inspect(
                $newId,
                static fn(array $row): array => $row,
            );
            check($afterPostCommitRecovery === $committedRow,
                'Sidecar reconciliation must not change the completed database revision.');
        } finally {
            if (is_array($postCommitMarker)) {
                linked_create_fixture_remove_visibility_stage($postCommitMarker['sidecar']);
                if (file_exists($postCommitMarker['marker']['path'])) {
                    \Gallery\Services\gallery_visibility_marker_assert_owned(
                        $postCommitMarker['marker'],
                    );
                    unlink($postCommitMarker['marker']['path']);
                }
            }
            $restore = $connection->prepare(
                'UPDATE galleries SET visibility = ?, updated_at = ?, edit_revision = ? WHERE id = ?',
            );
            $restore->execute([
                $baseRow['visibility'], $baseRow['updated_at'],
                $baseRow['edit_revision'], $newId,
            ]);
            file_put_contents($sidecarPath, $originalSidecar);
        }
        $connection->beginTransaction();
        try {
            $restoredRow = \Gallery\Models\gallery_model_linked_visibility_lock($newId);
            $connection->commit();
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }
        check($restoredRow === $baseRow
            && !file_exists($postCommitMarker['marker']['path'])
            && file_get_contents($sidecarPath) === $originalSidecar,
            'The owned committed-recovery fixture must restore its exact row and sidecar.');
    }
    $backup = $sidecarPath . '.fixture-backup';
    check(rename($sidecarPath, $backup), 'Could not isolate sidecar symlink fixture.');
    try {
        if (function_exists('symlink') && @symlink($backup, $sidecarPath)) {
            $symlinkRefused = false;
            try {
                \Gallery\Services\gallery_sidecar_visibility_prepare($createdGallery, 'private');
            } catch (Throwable) {
                $symlinkRefused = true;
            }
            check($symlinkRefused && file_get_contents($backup) === $originalSidecar,
                'A final sidecar symlink must not be followed or overwritten.');
            unlink($sidecarPath);
        }
    } finally {
        if (is_link($sidecarPath)) {
            unlink($sidecarPath);
        }
        check(rename($backup, $sidecarPath), 'Could not restore the owned sidecar fixture.');
    }
    $withoutTransaction = false;
    try {
        \Gallery\Models\gallery_model_linked_visibility_update($newId, '1', 'private');
    } catch (RuntimeException) {
        $withoutTransaction = true;
    }
    check($withoutTransaction && linked_create_fixture_snapshot($connection) === $after,
        'A linked visibility write without its transaction must be refused.');
    $connection->beginTransaction();
    try {
        $originalRow = \Gallery\Models\gallery_model_linked_visibility_lock($newId);
        check(is_array($originalRow), 'The created gallery must be lockable.');
        $revision = \Gallery\Services\gallery_edit_revision($originalRow);
        check(!\Gallery\Models\gallery_model_linked_visibility_update($newId, '999999', 'private'),
            'A stale precondition must not update the selected gallery.');
        check(\Gallery\Models\gallery_model_linked_visibility_update($newId, $revision, 'private'),
            'The exact revision must update the selected gallery.');
        $changedRow = \Gallery\Models\gallery_model_linked_visibility_lock($newId);
        check(is_array($changedRow) && $changedRow['visibility'] === 'private'
            && \Gallery\Services\gallery_edit_revision($changedRow)
                === \Gallery\Services\app_linking_visibility_next_revision($revision),
            'A visibility write must advance the exact revision.');
        foreach (['visibility', 'updated_at', 'edit_revision'] as $key) {
            unset($originalRow[$key], $changedRow[$key]);
        }
        check($originalRow === $changedRow,
            'Visibility must preserve every other stored gallery field.');
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
    check(linked_create_fixture_snapshot($connection) === $after,
        'Rolling back the visibility fixture must restore the original gallery.');

    // A durable receipt keeps the original title; a later rename must not be
    // returned as a currently matching result. Roll back the isolated edit.
    $receipt = ['id' => $newId, 'title' => $title, 'parent_id' => null];
    check(\Gallery\Services\app_linking_create_receipt_is_current($receipt),
        'An unchanged created gallery must match its receipt.');
    $receiptBackup = $sidecarPath . '.receipt-fixture';
    check(rename($sidecarPath, $receiptBackup),
        'Could not isolate the receipt sidecar fixture.');
    $receiptLink = false;
    try {
        check(!\Gallery\Services\app_linking_create_receipt_is_current($receipt),
            'A missing sidecar must not be reported as a current receipt.');
        if (function_exists('symlink') && @symlink($receiptBackup, $sidecarPath)) {
            $receiptLink = true;
            check(!\Gallery\Services\app_linking_create_receipt_is_current($receipt),
                'A redirected sidecar must not be reported as a current receipt.');
        }
    } finally {
        if ($receiptLink) {
            check(is_link($sidecarPath) && readlink($sidecarPath) === $receiptBackup
                && unlink($sidecarPath),
                'The receipt fixture symlink changed ownership.');
        }
        check(rename($receiptBackup, $sidecarPath),
            'Could not restore the receipt sidecar fixture.');
    }
    check(\Gallery\Services\app_linking_create_receipt_is_current($receipt),
        'Restoring the current sidecar must restore receipt availability.');
    $connection->beginTransaction();
    $rename = $connection->prepare('UPDATE galleries SET title = ? WHERE id = ?');
    $rename->execute([$title . ' changed', $newId]);
    check(!\Gallery\Services\app_linking_create_receipt_is_current($receipt),
        'A renamed gallery must not match a historical creation receipt.');
    $connection->rollBack();
    check(\Gallery\Services\app_linking_create_receipt_is_current($receipt)
        && linked_create_fixture_snapshot($connection) === $after,
        'Rolling back the fixture rename must restore the original receipt match.');
    $connection->beginTransaction();
    $move = $connection->prepare('UPDATE galleries SET parent_id = ? WHERE id = ?');
    $move->execute([(int) $before[0]['id'], $newId]);
    check(!\Gallery\Services\app_linking_create_receipt_is_current($receipt),
        'A moved gallery must not match its original creation receipt.');
    $connection->rollBack();
    check(\Gallery\Services\app_linking_create_receipt_is_current($receipt)
        && linked_create_fixture_snapshot($connection) === $after,
        'Rolling back the fixture move must restore the original receipt match.');

    // A one-candidate boundary must refuse an occupied base without trying a
    // suffixed candidate or writing any gallery row.
    $slugLimitReached = false;
    try {
        \Gallery\Models\gallery_model_unique_slug((string) $newRows[0]['slug'], 0, 1);
    } catch (RuntimeException) {
        $slugLimitReached = true;
    }
    check($slugLimitReached && linked_create_fixture_snapshot($connection) === $after,
        'An exhausted slug search must refuse without changing gallery rows.');

    // An existing physical destination must never create a replacement row.
    $collision = false;
    $connection->beginTransaction();
    $reservation = linked_create_fixture_reserve($leaf, $actor);
    $filesystemStarted = false;
    try {
        \Gallery\Services\create_empty_gallery_linked_owned([
            'title' => $title, 'parent_id' => 0,
        ], $reservation, $filesystemStarted);
    } catch (Throwable) {
        $collision = true;
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
    check($collision && !$filesystemStarted && linked_create_fixture_snapshot($connection) === $after,
        'A folder collision must preserve all existing gallery rows.');

    // An already inconsistent stored URL is not silently repaired by creation.
    $blockedTitle = 'Linked Create ' . bin2hex(random_bytes(LINKED_CREATE_FIXTURE_RANDOM_BYTES));
    $blockedLeaf = \Gallery\Services\gallery_folder_segment($blockedTitle);
    check(!file_exists($root . '/' . $blockedLeaf) && !is_link($root . '/' . $blockedLeaf),
        'The unique refusal fixture folder already exists.');
    $ownedFolders[] = [$blockedLeaf, $blockedTitle];
    $connection->beginTransaction();
    $tamper = $connection->prepare('UPDATE galleries SET url_path_hash = ? WHERE id = ?');
    $tamper->execute([str_repeat('f', 64), $newId]);
    $blocked = false;
    $reservation = linked_create_fixture_reserve($blockedLeaf, $actor);
    $filesystemStarted = false;
    try {
        \Gallery\Services\create_empty_gallery_linked_owned([
            'title' => $blockedTitle, 'parent_id' => 0,
        ], $reservation, $filesystemStarted);
    } catch (Throwable) {
        $blocked = true;
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
    check($blocked && !$filesystemStarted && !file_exists($root . '/' . $blockedLeaf)
        && linked_create_fixture_snapshot($connection) === $after,
        'Catalog drift must be refused before creating a folder or changing rows.');

    // The actual linked workflow claims the operation before taking its
    // gallery writer lease; exercise the same ordering below.
    \Gallery\Services\gallery_edit_writer_end($writer);
    $writer = null;

    // Exercise the reservation and ledger together using a genuine claim.
    $reservationKey = \Gallery\Services\admin_operation_new_key();
    $reservationHash = hash('sha256', $reservationKey);
    $reservationFixtureKeys[] = $reservationHash;
    $abandonFixtureKeys[] = $reservationHash;
    $reservationPayload = ['title' => $blockedTitle, 'parent_id' => 0];
    $reservationFingerprint = \Gallery\Services\admin_operation_fingerprint(
        'gallery.create', $reservationPayload,
    );
    $reservationClaim = \Gallery\Services\admin_operation_begin(
        $actor, $reservationKey, 'gallery.create', $reservationFingerprint,
    );
    check($reservationClaim['replay'] === false, 'The reservation claim must be fresh.');
    $writer = \Gallery\Services\gallery_edit_writer_begin();
    $connection->beginTransaction();
    \Gallery\Models\gallery_model_linked_reservation_insert(
        $blockedLeaf, $actor, $reservationHash, $reservationFingerprint,
    );
    $connection->commit();
    $adminRefused = false;
    try {
        \Gallery\Services\gallery_linked_reservation_assert_admin_write();
    } catch (RuntimeException) {
        $adminRefused = true;
    }
    check($adminRefused && linked_create_fixture_snapshot($connection) === $after,
        'A durable pending reservation must block covered Admin writes without changing galleries.');
    \Gallery\Services\gallery_edit_writer_end($writer);
    $writer = null;
    $existingSidecarBefore = file_get_contents($root . '/' . $leaf . '/gallery.json');
    check(is_string($existingSidecarBefore), 'The existing sidecar must be readable.');
    $trashCountBefore = (int) $connection->query(
        'SELECT COUNT(*) FROM gallery_trash_entries',
    )->fetchColumn();
    $reason = '';
    try {
        \Gallery\Services\move_gallery_subtrees_to_trash([$newId]);
    } catch (RuntimeException $exception) {
        $reason = $exception->getMessage();
    }
    check($reason === 'A linked gallery creation needs reconciliation before this operation.'
        && is_dir($root . '/' . $leaf)
        && file_get_contents($root . '/' . $leaf . '/gallery.json') === $existingSidecarBefore
        && linked_create_fixture_snapshot($connection) === $after
        && (int) $connection->query('SELECT COUNT(*) FROM gallery_trash_entries')->fetchColumn()
            === $trashCountBefore,
        'Trash movement must preserve the existing gallery, sidecar, catalog and trash ledger while pending.');
    $reason = '';
    try {
        \Gallery\Services\restore_gallery_trash_entry($absentTrashToken);
    } catch (RuntimeException $exception) {
        $reason = $exception->getMessage();
    }
    check($reason === 'A linked gallery creation needs reconciliation before this operation.'
        && linked_create_fixture_snapshot($connection) === $after
        && file_get_contents($root . '/' . $leaf . '/gallery.json') === $existingSidecarBefore
        && !file_exists($root . '/' . $blockedLeaf),
        'Trash restore must refuse pending reservations before any live gallery effect.');
    foreach (['import', 'import_without_thumbnails', 'create'] as $adminPath) {
        $reason = '';
        try {
            if ($adminPath === 'import') {
                \Gallery\Services\import_galleries([$leaf]);
            } elseif ($adminPath === 'import_without_thumbnails') {
                \Gallery\Services\import_galleries_without_thumbnails([$leaf]);
            } else {
                $unexpectedGallery = \Gallery\Services\create_empty_gallery([
                    'title' => $blockedTitle, 'parent_id' => 0,
                ]);
                if (isset($unexpectedGallery['id'])) {
                    $createdRows[] = [(int) $unexpectedGallery['id'], $blockedLeaf];
                }
            }
        } catch (RuntimeException $exception) {
            $reason = $exception->getMessage();
        }
        check($reason === 'A linked gallery creation needs reconciliation before this operation.'
            && linked_create_fixture_snapshot($connection) === $after
            && file_get_contents($root . '/' . $leaf . '/gallery.json') === $existingSidecarBefore
            && !file_exists($root . '/' . $blockedLeaf),
            'Each covered Admin path must refuse the pending reservation before DB or filesystem effects.');
    }
    $writer = \Gallery\Services\gallery_edit_writer_begin();

    // The unique active path applies independently of a different operation key.
    $connection->beginTransaction();
    $duplicateRefused = false;
    try {
        linked_create_fixture_reserve($blockedLeaf, $actor);
    } catch (Throwable) {
        $duplicateRefused = true;
    } finally {
        $connection->rollBack();
    }
    check($duplicateRefused, 'Two active reservations must not own the same path.');

    $connection->beginTransaction();
    \Gallery\Models\admin_operation_model_complete_owned(
        $reservationClaim, '{"fixture":true}', gmdate('Y-m-d H:i:s'),
    );
    \Gallery\Models\gallery_model_linked_reservation_complete(
        $blockedLeaf, $actor, $reservationHash, $reservationFingerprint,
    );
    $connection->rollBack();
    $rolledBackClaim = \Gallery\Models\admin_operation_model_find($actor, $reservationHash);
    check(is_array($rolledBackClaim) && $rolledBackClaim['state'] === 'pending'
        && \Gallery\Models\gallery_model_linked_reservation_conflicts(),
        'Rolling back completion must retain both pending claim and reservation.');

    $connection->beginTransaction();
    \Gallery\Models\admin_operation_model_complete_owned(
        $reservationClaim, '{"fixture":true}', gmdate('Y-m-d H:i:s'),
    );
    \Gallery\Models\gallery_model_linked_reservation_complete(
        $blockedLeaf, $actor, $reservationHash, $reservationFingerprint,
    );
    $connection->commit();
    $completedClaim = \Gallery\Models\admin_operation_model_find($actor, $reservationHash);
    check(is_array($completedClaim) && $completedClaim['state'] === 'completed'
        && !\Gallery\Models\gallery_model_linked_reservation_conflicts(),
        'Committed completion must preserve a matching completed claim and reservation.');
    \Gallery\Services\gallery_linked_reservation_assert_admin_write();
    $connection->beginTransaction();
    linked_create_fixture_reserve($blockedLeaf, $actor);
    $connection->rollBack();

    // A malformed persisted reservation must never appear as a clear catalog.
    $connection->beginTransaction();
    $corruptReservation = $connection->prepare(
        'UPDATE linked_gallery_create_reservations SET state = ? WHERE actor_id = ? AND key_hash = ?',
    );
    $corruptReservation->execute(['unknown', $actor, $reservationHash]);
    $malformedRefused = false;
    try {
        \Gallery\Services\gallery_linked_reservation_assert_admin_write();
    } catch (RuntimeException) {
        $malformedRefused = true;
    } finally {
        $connection->rollBack();
    }
    check($malformedRefused, 'A malformed reservation must block covered Admin writes.');
    \Gallery\Services\admin_operation_release($reservationClaim);
    $reservationClaim = null;
    \Gallery\Services\gallery_edit_writer_end($writer);
    $writer = null;

    // A proven pre-filesystem refusal can close only the exact owned claim and
    // its reservation in one transaction. Rollback must retain both pending.
    $noEffectKey = \Gallery\Services\admin_operation_new_key();
    $noEffectHash = hash('sha256', $noEffectKey);
    $reservationFixtureKeys[] = $noEffectHash;
    $abandonFixtureKeys[] = $noEffectHash;
    $noEffectClaim = \Gallery\Services\admin_operation_begin(
        $actor, $noEffectKey, 'gallery.create', $reservationFingerprint,
    );
    check($noEffectClaim['replay'] === false, 'The no-effect claim must be fresh.');
    $writer = \Gallery\Services\gallery_edit_writer_begin();
    $connection->beginTransaction();
    \Gallery\Models\gallery_model_linked_reservation_insert(
        $blockedLeaf, $actor, $noEffectHash, $reservationFingerprint,
    );
    $connection->commit();
    $wrongClaim = $noEffectClaim;
    $wrongClaim['owner_hash'] = hash('sha256', 'different fixture owner');
    $connection->beginTransaction();
    $foreignRefused = false;
    try {
        \Gallery\Models\admin_operation_model_abandon_before_filesystem(
            $wrongClaim, gmdate('Y-m-d H:i:s'),
        );
    } catch (RuntimeException) {
        $foreignRefused = true;
    } finally {
        $connection->rollBack();
    }
    check($foreignRefused && \Gallery\Models\gallery_model_linked_reservation_conflicts(),
        'A different claim owner must not close the pending reservation.');

    $connection->beginTransaction();
    $identityRefused = false;
    try {
        \Gallery\Models\gallery_model_linked_reservation_complete(
            $blockedLeaf, $actor, $noEffectHash, hash('sha256', 'different fixture payload'),
            'no_effect',
        );
    } catch (RuntimeException) {
        $identityRefused = true;
    } finally {
        $connection->rollBack();
    }
    check($identityRefused, 'A different reservation fingerprint must not close the path.');

    $connection->beginTransaction();
    \Gallery\Models\gallery_model_linked_reservation_complete(
        $blockedLeaf, $actor, $noEffectHash, $reservationFingerprint, 'no_effect',
    );
    check(\Gallery\Models\gallery_model_linked_reservation_conflicts(),
        'A no-effect reservation paired with a pending ledger must stay blocked.');
    $connection->rollBack();
    $connection->beginTransaction();
    \Gallery\Models\admin_operation_model_abandon_before_filesystem(
        $noEffectClaim, gmdate('Y-m-d H:i:s'),
    );
    check(\Gallery\Models\gallery_model_linked_reservation_conflicts(),
        'An abandoned ledger paired with a pending reservation must stay blocked.');
    $connection->rollBack();

    $connection->beginTransaction();
    \Gallery\Models\admin_operation_model_abandon_before_filesystem(
        $noEffectClaim, gmdate('Y-m-d H:i:s'),
    );
    \Gallery\Models\gallery_model_linked_reservation_complete(
        $blockedLeaf, $actor, $noEffectHash, $reservationFingerprint, 'no_effect',
    );
    $connection->rollBack();
    $noEffectRow = \Gallery\Models\admin_operation_model_find($actor, $noEffectHash);
    check(is_array($noEffectRow) && $noEffectRow['state'] === 'pending'
        && \Gallery\Models\gallery_model_linked_reservation_conflicts(),
        'Rollback must retain the pending claim and its reservation.');
    $lostTransactionRefused = false;
    try {
        \Gallery\Models\admin_operation_model_abandon_before_filesystem(
            $noEffectClaim, gmdate('Y-m-d H:i:s'),
        );
    } catch (RuntimeException) {
        $lostTransactionRefused = true;
    }
    check($lostTransactionRefused && \Gallery\Models\gallery_model_linked_reservation_conflicts(),
        'A lost transaction must not close the pending claim or reservation.');

    $connection->beginTransaction();
    \Gallery\Models\admin_operation_model_abandon_before_filesystem(
        $noEffectClaim, gmdate('Y-m-d H:i:s'),
    );
    \Gallery\Models\gallery_model_linked_reservation_complete(
        $blockedLeaf, $actor, $noEffectHash, $reservationFingerprint, 'no_effect',
    );
    $connection->commit();
    $noEffectRow = \Gallery\Models\admin_operation_model_find($actor, $noEffectHash);
    check(is_array($noEffectRow) && $noEffectRow['state'] === 'abandoned'
        && !\Gallery\Models\gallery_model_linked_reservation_conflicts(),
        'Committed no-effect reservation must match its abandoned claim.');
    \Gallery\Services\gallery_linked_reservation_assert_admin_write();
    $connection->beginTransaction();
    check(\Gallery\Models\admin_operation_model_abandon_linked_create(
        $actor, $noEffectHash, $reservationFingerprint,
        hash('sha256', 'subsequent fixture owner'), gmdate('Y-m-d H:i:s'),
    ) === 'abandoned', 'Repeated abandonment must return the retained tombstone.');
    $connection->commit();
    \Gallery\Services\admin_operation_release($noEffectClaim);
    $noEffectClaim = null;
    \Gallery\Services\gallery_edit_writer_end($writer);
    $writer = null;

    // A rollback after the real filesystem write must leave a durable claim.
    $uncertainTitle = 'Linked Create ' . bin2hex(random_bytes(LINKED_CREATE_FIXTURE_RANDOM_BYTES));
    $uncertainLeaf = \Gallery\Services\gallery_folder_segment($uncertainTitle);
    check(!file_exists($root . '/' . $uncertainLeaf) && !is_link($root . '/' . $uncertainLeaf),
        'The unique uncertain fixture folder already exists.');
    $ownedFolders[] = [$uncertainLeaf, $uncertainTitle];
    $key = \Gallery\Services\admin_operation_new_key();
    $payload = ['title' => $uncertainTitle, 'parent_id' => 0];
    $fingerprint = \Gallery\Services\admin_operation_fingerprint('gallery.create', $payload);
    $claim = \Gallery\Services\admin_operation_begin($actor, $key, 'gallery.create', $fingerprint);
    check($claim['replay'] === false, 'A fresh fixture key must own a pending claim.');
    $competingLock = $verified->prepare('SELECT GET_LOCK(?, 0)');
    $competingLock->execute([$claim['lock_name']]);
    check((int) $competingLock->fetchColumn() === 0,
        'A competing connection must not close an active operation.');
    $writer = \Gallery\Services\gallery_edit_writer_begin();
    $connection->beginTransaction();
    try {
        $pendingRefused = false;
        try {
            \Gallery\Models\admin_operation_model_abandon_linked_create(
                $actor, hash('sha256', $key), $fingerprint,
                hash('sha256', 'fixture-owner'), gmdate('Y-m-d H:i:s'),
            );
        } catch (RuntimeException) {
            $pendingRefused = true;
        }
        check($pendingRefused, 'A pending worker must not be tombstoned.');
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
    $reservation = [
        'folder' => $uncertainLeaf,
        'actor_id' => $actor,
        'key_hash' => hash('sha256', $key),
        'payload_hash' => $fingerprint,
    ];
    $reservationFixtureKeys[] = $reservation['key_hash'];
    $connection->beginTransaction();
    \Gallery\Models\gallery_model_linked_reservation_insert(
        $uncertainLeaf, $actor, $reservation['key_hash'], $fingerprint,
    );
    $connection->commit();
    $connection->beginTransaction();
    $filesystemStarted = false;
    $uncertain = \Gallery\Services\create_empty_gallery_linked_owned(
        $payload, $reservation, $filesystemStarted,
    );
    check($filesystemStarted && is_file($root . '/' . $uncertainLeaf . '/gallery.json'),
        'The uncertain scenario must reach the actual filesystem write.');
    $connection->rollBack();
    \Gallery\Services\admin_operation_fail($claim);
    $claimReleased = true;
    $retained = \Gallery\Models\admin_operation_model_find($actor, hash('sha256', $key));
    check(is_array($retained)
        && in_array($retained['state'] ?? null, ['pending', 'needs_reconciliation'], true)
        && linked_create_fixture_snapshot($connection) === $after,
        'Rollback must retain an unresolved claim and preserve gallery rows.');
    check(\Gallery\Models\gallery_model_linked_reservation_conflicts(),
        'A rolled-back gallery write must retain its durable pending reservation.');
    $replayRefused = false;
    try {
        \Gallery\Services\admin_operation_begin($actor, $key, 'gallery.create', $fingerprint);
    } catch (\Gallery\Services\AdminOperationRefusal) {
        $replayRefused = true;
    }
    check($replayRefused && is_dir($root . '/' . $uncertainLeaf),
        'An unresolved attempt must not replay or erase its filesystem evidence.');
    check((int) $uncertain['id'] > 0, 'The rolled-back operation must have reached row insertion.');

    // A worker-marked failure may be closed only after both leases are held;
    // it never claims that the retained filesystem effects are absent.
    \Gallery\Services\gallery_edit_writer_end($writer);
    $writer = null;
    // Discovery must not move or delete the real, unindexed orphan. The
    // pending reservation protects it before either action touches the disk.
    $orphanSidecar = $root . '/' . $uncertainLeaf . '/gallery.json';
    $orphanBytes = file_get_contents($orphanSidecar);
    check(is_string($orphanBytes), 'The uncertain sidecar must remain readable.');
    foreach (['batch', 'complete'] as $organizerMode) {
        $reason = '';
        try {
            if ($organizerMode === 'batch') {
                \Gallery\Services\gallery_metadata_organizer_apply_date_plan_batch($newId);
            } else {
                \Gallery\Services\gallery_metadata_organizer_apply_date_plan($newId);
            }
        } catch (RuntimeException $exception) {
            $reason = $exception->getMessage();
        }
        check($reason === 'A linked gallery creation needs reconciliation before this operation.'
            && is_dir($root . '/' . $uncertainLeaf)
            && file_get_contents($orphanSidecar) === $orphanBytes
            && linked_create_fixture_snapshot($connection) === $after,
            'Both organizer modes must refuse pending creation before any effect.');
    }
    $known = \Gallery\Services\admin_gallery_discovery_known_path_job_state(
        \Gallery\Services\admin_gallery_discovery_known_gallery_paths(),
    );
    check(\Gallery\Services\admin_gallery_discovery_selected_unmanaged_paths(
        [$uncertainLeaf], $known,
    ) === [$uncertainLeaf], 'The orphan must be eligible for the discovery actions.');
    foreach (['move', 'delete'] as $discoveryAction) {
        $reason = '';
        try {
            if ($discoveryAction === 'move') {
                \Gallery\Services\admin_gallery_discovery_move_requested_photos(
                    [$uncertainLeaf], $newId,
                );
            } else {
                \Gallery\Services\admin_gallery_discovery_delete_requested_paths(
                    [$uncertainLeaf],
                );
            }
        } catch (RuntimeException $exception) {
            $reason = $exception->getMessage();
        }
        check($reason === 'A linked gallery creation needs reconciliation before this operation.'
            && is_dir($root . '/' . $uncertainLeaf)
            && file_get_contents($orphanSidecar) === $orphanBytes
            && linked_create_fixture_snapshot($connection) === $after,
            'Discovery disk actions must refuse pending creation before any effect.');
    }
    foreach (['images', 'subtrees'] as $copyAction) {
        $reason = '';
        try {
            if ($copyAction === 'images') {
                \Gallery\Services\copy_gallery_images($newId, $newId, []);
            } else {
                \Gallery\Services\picture_manager_copy_gallery_subtrees($newId, $newId, []);
            }
        } catch (RuntimeException $exception) {
            $reason = $exception->getMessage();
        }
        check($reason === 'A linked gallery creation needs reconciliation before this operation.'
            && is_dir($root . '/' . $uncertainLeaf)
            && file_get_contents($orphanSidecar) === $orphanBytes
            && linked_create_fixture_snapshot($connection) === $after,
            'Picture manager copies must refuse pending creation before any effect.');
    }
    foreach (['move_photos', 'delete_from_disk', 'import_in_place'] as $action) {
        foreach ([false, true] as $createThumbnails) {
            $response = \Gallery\Controllers\admin_gallery_discovery_import_action_result(
                $action,
                ['folders' => [$uncertainLeaf], 'target_gallery_id' => $newId],
                $createThumbnails,
            );
            check($response['ok'] === false && $response['action'] === $action
                && is_string($response['error']) && $response['error'] !== ''
                && is_dir($root . '/' . $uncertainLeaf)
                && file_get_contents($orphanSidecar) === $orphanBytes
                && linked_create_fixture_snapshot($connection) === $after,
                'Discovery controller must return a safe refusal without altering the orphan.');
        }
    }
    $operationLock = \Gallery\Models\admin_operation_model_lock($actor, hash('sha256', $key));
    check(is_string($operationLock), 'The stopped operation lease must be available.');
    try {
        $writer = \Gallery\Services\gallery_edit_writer_begin();
        $connection->beginTransaction();
        $closed = \Gallery\Models\admin_operation_model_abandon_linked_create(
            $actor, hash('sha256', $key), $fingerprint,
            hash('sha256', 'fixture-owner'), gmdate('Y-m-d H:i:s'),
        );
        check($closed === 'abandoned', 'A stopped failed operation must close.');
        $connection->commit();
        check(is_dir($root . '/' . $uncertainLeaf),
            'Closing an uncertain operation must not remove its folder.');
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        if ($writer !== null) {
            \Gallery\Services\gallery_edit_writer_end($writer);
            $writer = null;
        }
        \Gallery\Models\admin_operation_model_unlock($operationLock);
    }

    // A close arriving before a delayed create installs the key tombstone.
    $newKey = \Gallery\Services\admin_operation_new_key();
    $newLedgerKey = \Gallery\Services\app_linking_create_ledger_key(
        $newKey, 'https://gallery.example.test/Galerie',
    );
    $newHash = hash('sha256', $newLedgerKey);
    $abandonFixtureKeys[] = $newHash;
    $operationLock = \Gallery\Models\admin_operation_model_lock($actor, $newHash);
    check(is_string($operationLock), 'The new operation lease must be available.');
    try {
        $writer = \Gallery\Services\gallery_edit_writer_begin();
        $connection->beginTransaction();
        check(\Gallery\Models\admin_operation_model_abandon_linked_create(
            $actor, $newHash, $fingerprint,
            hash('sha256', 'fixture-owner'), gmdate('Y-m-d H:i:s'),
        ) === 'abandoned', 'An absent claim must receive a durable tombstone.');
        $connection->commit();
        $connection->beginTransaction();
        check(\Gallery\Models\admin_operation_model_abandon_linked_create(
            $actor, $newHash, $fingerprint,
            hash('sha256', 'fixture-other-owner'), gmdate('Y-m-d H:i:s'),
        ) === 'abandoned', 'An exact repeated closure must remain idempotent.');
        $connection->commit();
        $connection->beginTransaction();
        $mismatchRefused = false;
        try {
            \Gallery\Models\admin_operation_model_abandon_linked_create(
                $actor, $newHash, hash('sha256', 'different input'),
                hash('sha256', 'fixture-owner'), gmdate('Y-m-d H:i:s'),
            );
        } catch (RuntimeException) {
            $mismatchRefused = true;
        } finally {
            $connection->rollBack();
        }
        check($mismatchRefused, 'A different fingerprint must not close the key.');
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        if ($writer !== null) {
            \Gallery\Services\gallery_edit_writer_end($writer);
            $writer = null;
        }
        \Gallery\Models\admin_operation_model_unlock($operationLock);
    }
    $delayedRefused = false;
    try {
        \Gallery\Services\admin_operation_begin(
            $actor, $newLedgerKey, 'gallery.create', $fingerprint,
        );
    } catch (\Gallery\Services\AdminOperationRefusal) {
        $delayedRefused = true;
    }
    check($delayedRefused && linked_create_fixture_snapshot($connection) === $after,
        'A delayed create must not reuse the closed key or change gallery rows.');

    $beforeHealth = \Gallery\Models\admin_operation_model_pending_counts();
    $corrupt = $connection->prepare(
        'UPDATE admin_operation_keys SET owner_hash = ? WHERE actor_id = ? AND key_hash = ?',
    );
    $corrupt->execute([str_repeat('g', 64), $actor, $newHash]);
    try {
        $afterHealth = \Gallery\Models\admin_operation_model_pending_counts();
        check((int) $afterHealth['unknown'] === (int) $beforeHealth['unknown'] + 1,
            'An invalid tombstone must remain visible as unknown health.');
        $connection->beginTransaction();
        $corruptRefused = false;
        try {
            \Gallery\Models\admin_operation_model_abandon_linked_create(
                $actor, $newHash, $fingerprint,
                hash('sha256', 'fixture-owner'), gmdate('Y-m-d H:i:s'),
            );
        } catch (RuntimeException) {
            $corruptRefused = true;
        } finally {
            $connection->rollBack();
        }
        check($corruptRefused, 'An invalid tombstone must not be acknowledged.');
    } finally {
        $corrupt->execute([hash('sha256', 'fixture-owner'), $actor, $newHash]);
    }
    $privateEvidence = [
        'actor_id' => $actor,
        'issuer' => 'https://gallery.example.test/Galerie',
        'operation_key' => $newKey,
        'title' => $uncertainTitle,
        'parent_id' => null,
    ];
    $beforeInspection = \Gallery\Models\admin_operation_model_find($actor, $newHash);
    check(\Gallery\Services\admin_operation_inspect_linked_create($privateEvidence)
        === ['matched' => true, 'state' => 'abandoned'],
        'The exact linked input must correlate to the retained tombstone.');
    $wrongIssuer = $privateEvidence;
    $wrongIssuer['issuer'] = 'https://other.example.test/Galerie';
    $wrongTitle = $privateEvidence;
    $wrongTitle['title'] .= ' changed';
    foreach ([$wrongIssuer, $wrongTitle] as $mismatchedEvidence) {
        check(\Gallery\Services\admin_operation_inspect_linked_create($mismatchedEvidence)
            === ['matched' => false, 'state' => 'unknown'],
            'A different issuer or payload must not correlate.');
    }
    check(\Gallery\Models\admin_operation_model_find($actor, $newHash) === $beforeInspection,
        'Private linked-create correlation must not modify the ledger.');
    // A fresh PHP process must use the CLI's own bootstrap, not this fixture's
    // already-loaded linking services. The private evidence travels by stdin.
    $privateJson = json_encode($privateEvidence,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $paddedJson = " \n" . $privateJson . "\n";
    $childResult = linked_create_fixture_inspection($directory, $paddedJson);
    check($childResult['status'] === 0 && $childResult['stderr'] === ''
        && json_decode($childResult['stdout'], true) === ['matched' => true, 'state' => 'abandoned']
        && !str_contains($childResult['stdout'], $newKey),
        'The clean CLI process must return only the correlated ledger state.');
    check(\Gallery\Models\admin_operation_model_find($actor, $newHash) === $beforeInspection,
        'The isolated inspection CLI must not modify the ledger.');
    $invalidDocuments = [
        substr($privateJson, 0, -1) . ',"title":"other"}',
        str_replace('"issuer":', '"\\u0069ssuer":', $privateJson),
        str_replace('"issuer":', '"issuer" :', $privateJson),
    ];
    foreach ($invalidDocuments as $invalidJson) {
        $childResult = linked_create_fixture_inspection($directory, $invalidJson);
        check($childResult['status'] !== 0 && $childResult['stdout'] === ''
            && !str_contains($childResult['stderr'], $newKey),
            'Noncanonical evidence must fail without disclosing the key.');
    }
    $completedHash = hash('sha256', \Gallery\Services\admin_operation_new_key());
    $abandonFixtureKeys[] = $completedHash;
    $completedInsert = $connection->prepare(
        'INSERT INTO admin_operation_keys (actor_id, key_hash, operation_name, '
        . 'payload_hash, owner_hash, state, response_json, created_at, updated_at) '
        . "VALUES (?, ?, 'gallery.create', ?, ?, 'completed', ?, ?, ?)",
    );
    $completedInsert->execute([
        $actor, $completedHash, $fingerprint, hash('sha256', 'fixture-owner'),
        '{"fixture":true}', gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s'),
    ]);
    $completedBefore = \Gallery\Models\admin_operation_model_find($actor, $completedHash);
    $connection->beginTransaction();
    try {
        check(\Gallery\Models\admin_operation_model_abandon_linked_create(
            $actor, $completedHash, $fingerprint,
            hash('sha256', 'fixture-other-owner'), gmdate('Y-m-d H:i:s'),
        ) === 'completed', 'A completed operation must retain its receipt.');
        $connection->commit();
    } finally {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
    }
    check(\Gallery\Models\admin_operation_model_find($actor, $completedHash)
        === $completedBefore, 'Closing must not change a completed ledger row.');
    echo "linked gallery creation owner tests passed\n";
} finally {
    if ($connection instanceof PDO && $connection->inTransaction()) {
        $connection->rollBack();
    }
    if (!$claimReleased && is_array($claim)) {
        \Gallery\Services\admin_operation_fail($claim);
    }
    if (is_array($reservationClaim)) {
        \Gallery\Services\admin_operation_release($reservationClaim);
    }
    if (is_array($noEffectClaim)) {
        \Gallery\Services\admin_operation_release($noEffectClaim);
    }
    if ($writer !== null) {
        \Gallery\Services\gallery_edit_writer_end($writer);
    }
    if ($connection instanceof PDO) {
        foreach ($reservationFixtureKeys as $fixtureHash) {
            $delete = $connection->prepare(
                'DELETE FROM linked_gallery_create_reservations WHERE actor_id = ? AND key_hash = ?',
            );
            $delete->execute([$actor, $fixtureHash]);
        }
        foreach ($createdRows as [$id, $leaf]) {
            $delete = $connection->prepare('DELETE FROM galleries WHERE id = ? AND folder_path = ?');
            $delete->execute([$id, $leaf]);
        }
        if (is_array($claim) && isset($actor, $key)) {
            $delete = $connection->prepare(
                'DELETE FROM admin_operation_keys WHERE actor_id = ? AND key_hash = ?',
            );
            $delete->execute([$actor, hash('sha256', $key)]);
        }
        foreach ($abandonFixtureKeys as $fixtureHash) {
            $delete = $connection->prepare(
                'DELETE FROM admin_operation_keys WHERE actor_id = ? AND key_hash = ?',
            );
            $delete->execute([$actor, $fixtureHash]);
        }
    }
    if (is_string($root)) {
        foreach ($ownedFolders as [$leaf, $title]) {
            linked_create_fixture_remove($root, $leaf, $title);
        }
    }
}
