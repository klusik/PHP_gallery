<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_gallery_create_post_mkdir_test.php
 * Module Type: Database and Filesystem Regression Test
 * Purpose: Exercise a real linked-create failure after mkdir is attempted.
 * Responsibilities: Use only a disposable installation and remove owned fixtures.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_safety.php';

use function GalleryWorkflow\check;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\validateFixture;

/**
 * Bound the isolated gallery and credential identities.
 * Type: int. Units: random bytes. Scope: one disposable fault fixture.
 * Consumers: random_bytes. Rationale: 12 bytes give a 96-bit unique name.
 */
const LINKED_CREATE_POST_MKDIR_RANDOM_BYTES = 12;

/**
 * Bound the lifetime of the fixture's real bearer grant.
 * Type: int. Units: seconds. Scope: one disposable grant.
 * Consumers: fixture grant INSERT. Rationale: 900 seconds matches the short
 * linking lifetime while allowing the isolated service call to finish.
 */
const LINKED_CREATE_POST_MKDIR_GRANT_SECONDS = 900;

if (PHP_OS_FAMILY === 'Windows') {
    echo "SKIP linked create post-mkdir fault requires POSIX permission semantics\n";
    exit(0);
}
if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP')
        . " linked create post-mkdir fault requires an owned disposable database\n";
    exit($required ? 1 : 0);
}

$directory = validateFixture(
    (string) getenv('GALLERY_WORKFLOW_FIXTURE'),
    (string) getenv('GALLERY_WORKFLOW_TOKEN'),
);
$verified = fixtureDatabase($directory, (string) getenv('GALLERY_WORKFLOW_TOKEN'));
$configuration = require $directory . '/config.php';
$root = realpath((string) ($configuration['galleries_root'] ?? ''));
check(is_string($root) && $root === $directory . '/galleries' && !is_link($root),
    'The owned gallery fixture root is unavailable.');

// Prove that the current OS account actually observes mode-000 restrictions.
// Privileged accounts or filesystems that ignore permissions cannot establish
// the intended failure and must skip instead of manufacturing a result.
$nonce = bin2hex(random_bytes(LINKED_CREATE_POST_MKDIR_RANDOM_BYTES));
$probe = $root . '/linked-permission-probe-' . $nonce;
check(mkdir($probe, 0700), 'The permission probe directory could not be created.');
$probeStat = lstat($probe);
check(is_array($probeStat), 'The permission probe identity is unavailable.');
$allowed = false;
try {
    check(chmod($probe, 0000), 'The permission probe could not be restricted.');
    $probeFile = $probe . '/probe';
    $handle = @fopen($probeFile, 'x+b');
    if (is_resource($handle)) {
        $allowed = true;
        $created = fstat($handle);
        fclose($handle);
        $observed = lstat($probeFile);
        check(is_array($created) && is_array($observed)
            && $created['dev'] === $observed['dev'] && $created['ino'] === $observed['ino']
            && unlink($probeFile), 'The owned permission probe changed.');
    }
} finally {
    check(chmod($probe, 0700), 'The permission probe could not be restored.');
    clearstatcache(true, $probe);
    $current = lstat($probe);
    check(is_array($current) && !is_link($probe)
        && $current['dev'] === $probeStat['dev'] && $current['ino'] === $probeStat['ino']
        && rmdir($probe), 'The owned permission probe changed or was not empty.');
}
if ($allowed) {
    echo "SKIP linked create post-mkdir permission refusal is not enforceable here\n";
    exit(0);
}

require_once $directory . '/app/bootstrap_full.php';
require_once $directory . '/app/models/app_linking.php';
require_once $directory . '/app/services/admin_operation_keys.php';
require_once $directory . '/app/services/app_linking_policy.php';
require_once $directory . '/app/services/app_linking_create.php';
require_once $directory . '/app/services/app_linking_schema.php';
require_once $directory . '/app/services/app_linking.php';
$pdo = \Gallery\Core\db();
check($pdo->query('SELECT DATABASE()')->fetchColumn()
    === $verified->query('SELECT DATABASE()')->fetchColumn(),
    'The application did not connect to the owned fixture database.');
$actor = (int) $pdo->query(
    "SELECT id FROM users WHERE username = 'workflow_admin' AND role = 'admin'",
)->fetchColumn();
check($actor > 0, 'The owned workflow administrator is unavailable.');
$issuer = 'https://gallery.example.test/Galerie';
$bearer = \Gallery\Services\app_linking_access_token_generate();
$tokenHash = \Gallery\Services\app_linking_access_token_hash($bearer);
check(is_string($tokenHash), 'The fixture bearer is unavailable.');
$key = \Gallery\Services\admin_operation_new_key();
$claimHash = hash('sha256', \Gallery\Services\app_linking_create_ledger_key($key, $issuer));
$title = 'Linked Post Mkdir ' . $nonce;
$leaf = \Gallery\Services\gallery_folder_segment($title);
$target = $root . '/' . $leaf;
check(!file_exists($target) && !is_link($target), 'The owned target is occupied.');
$grantId = 0;
$targetIdentity = null;
try {
    $insert = $pdo->prepare('INSERT INTO app_link_grants '
        . '(user_id, client_id, issuer, scope, created_at, access_token_hash, '
        . 'access_issued_at, access_expires_at) VALUES (?, ?, ?, ?, '
        . 'UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))');
    $insert->execute([
        $actor, \Gallery\Services\APP_LINKING_WINAPP_CLIENT_ID, $issuer,
        \Gallery\Services\APP_LINKING_CREATE_SCOPE, $tokenHash,
        LINKED_CREATE_POST_MKDIR_GRANT_SECONDS,
    ]);
    $grantId = (int) $pdo->lastInsertId();
    check($grantId > 0, 'The fixture grant was not created.');
    $oldMask = umask(0777);
    $refused = false;
    try {
        try {
            \Gallery\Services\app_linking_create_gallery(
                $bearer, $issuer, $key, ['title' => $title, 'parent_id' => 0],
            );
        } catch (Throwable) {
            $refused = true;
        }
    } finally {
        umask($oldMask);
    }
    clearstatcache(true, $target);
    $targetIdentity = @lstat($target);
    $claim = \Gallery\Models\admin_operation_model_find($actor, $claimHash);
    $query = $pdo->prepare('SELECT folder_path, state, active_path_hash '
        . 'FROM linked_gallery_create_reservations WHERE actor_id = ? AND key_hash = ?');
    $query->execute([$actor, $claimHash]);
    $reservation = $query->fetch(PDO::FETCH_ASSOC);
    check($refused && is_array($targetIdentity) && !is_link($target) && is_dir($target)
        && is_array($claim) && in_array($claim['state'], ['pending', 'needs_reconciliation'], true)
        && is_array($reservation) && $reservation['folder_path'] === $leaf
        && $reservation['state'] === 'pending'
        && $reservation['active_path_hash'] === hash('sha256', $leaf),
        'A real post-mkdir refusal must retain its uncertain claim and reservation.');
    $gallery = $pdo->prepare('SELECT id FROM galleries WHERE folder_path_hash = ?');
    $gallery->execute([hash('sha256', $leaf)]);
    check($gallery->fetchColumn() === false,
        'A failed post-mkdir transaction must not claim a committed gallery.');
    echo "PASS linked create retains reservation after real mkdir failure\n";
} finally {
    $targetRemoved = false;
    $reservationRemoved = false;
    $databaseReady = false;
    try {
        if ($pdo->inTransaction()) {
            check($pdo->rollBack() && !$pdo->inTransaction(),
                'The fixture transaction could not be rolled back.');
        }
        $databaseReady = !$pdo->inTransaction();
        check($databaseReady, 'The fixture transaction remains active.');
        if (is_array($targetIdentity)) {
            clearstatcache(true, $target);
            $current = @lstat($target);
            check(is_array($current) && !is_link($target)
                && $current['dev'] === $targetIdentity['dev'] && $current['ino'] === $targetIdentity['ino']
                && chmod($target, 0700) && rmdir($target),
                'The owned empty target changed or could not be removed.');
            $targetRemoved = true;
        }
    } finally {
        try {
            if ($targetRemoved && $databaseReady) {
                $deleteReservation = $pdo->prepare('DELETE FROM linked_gallery_create_reservations '
                    . 'WHERE actor_id = ? AND key_hash = ? AND folder_path = ?');
                $deleteReservation->execute([$actor, $claimHash, $leaf]);
                $reservationRemoved = $deleteReservation->rowCount() === 1;
                check($reservationRemoved, 'The owned reservation changed before cleanup.');
            }
        } finally {
            try {
                if ($reservationRemoved && $databaseReady) {
                    $deleteClaim = $pdo->prepare('DELETE FROM admin_operation_keys WHERE actor_id = ? AND key_hash = ?');
                    $deleteClaim->execute([$actor, $claimHash]);
                }
            } finally {
                if ($grantId > 0) {
                    $deleteGrant = $pdo->prepare('DELETE FROM app_link_grants '
                        . 'WHERE id = ? AND user_id = ? AND access_token_hash = ?');
                    $deleteGrant->execute([$grantId, $actor, $tokenHash]);
                }
            }
        }
    }
}
