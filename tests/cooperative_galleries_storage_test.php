<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_galleries_storage_test.php
 * Module Type: Regression Test
 * Purpose: Verify isolated cooperative persistence and schema refusal.
 * Responsibilities: Exercise atomic compare-and-set, credential lifecycle and album identity.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Supply a disposable database; this fixture never loads production configuration.
 *
 * @return \PDO Validated domain result described above; null represents absence or refusal where allowed.
 */
    function db(): \PDO
    {
        return $GLOBALS['cooperative_test_database'];
    }

    /** Provide deterministic test-only key material.
 *
 * @return array<string,mixed> Validated domain result described above; null represents absence or refusal where allowed.
 */
    function cms_config(): array
    {
        return ['visitor_vote_secret' => array_key_exists('cooperative_test_secret', $GLOBALS)
            ? $GLOBALS['cooperative_test_secret'] : str_repeat('fixture-only-secret-', 4)];
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/security_tokens.php';
    require_once dirname(__DIR__) . '/app/services/schema_inspection.php';
    require_once dirname(__DIR__) . '/app/models/cooperative_galleries.php';
    require_once dirname(__DIR__) . '/app/services/cooperative_galleries.php';

    use Gallery\Services\CooperativeException;
    use function Gallery\Services\cooperative_instance_id;
    use function Gallery\Services\cooperative_album_identity;
    use function Gallery\Services\cooperative_album_local_id;
    use function Gallery\Services\cooperative_peer_register;
    use function Gallery\Services\cooperative_peer_prepare_credentials;
    use function Gallery\Services\cooperative_peer_record_confirmation;
    use function Gallery\Services\cooperative_peer_authenticate;
    use function Gallery\Services\cooperative_peer_outbound_credential;
    use function Gallery\Services\cooperative_peer_suspend;
    use function Gallery\Services\cooperative_peer_revoke;
    use function Gallery\Services\cooperative_credential_generate;
    use function Gallery\Services\cooperative_group_create;
    use function Gallery\Services\cooperative_group_update;
    use function Gallery\Services\cooperative_group_read;
    use function Gallery\Services\cooperative_group_propose;
    use function Gallery\Services\cooperative_storage_status;
    use function Gallery\Services\schema_inspection_query_executor_override;
    use function Gallery\Services\schema_inspection_reset_request_cache;
    use function Gallery\Models\cooperative_model_peer_find;

    /** Fail the isolated fixture on a violated persistence contract.
 *
 * @param bool $condition Behavioral assertion that must hold for the fixture to pass.
 * @param string $message Diagnostic describing the violated fixture contract.
 * @return void No return value; failure raises an exception.
 */
    function coop_storage_check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /** Require one stable refusal from a trusted service operation.
 *
 * @param callable $operation Trusted internal operation; never selected from a remote callback payload.
 * @param string $reason Stable bounded refusal reason without user data or secrets.
 * @return void No return value; failure raises an exception.
 */
    function coop_storage_refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
        } catch (CooperativeException $error) {
            coop_storage_check($error->reason === $reason, 'Wrong refusal: ' . $error->reason);
            return;
        }
        throw new \RuntimeException('Expected refusal: ' . $reason);
    }

    foreach ([null, '', str_repeat('a', 32), str_repeat('ab12', 16), str_repeat(' ', 40),
        'replace-with-a-long-random-secret', 'REPLACE-WITH-A-LONG-RANDOM-SECRET',
        'replace-with-a-temporary-setup-key'] as $invalidSecret) {
        $GLOBALS['cooperative_test_secret'] = $invalidSecret;
        coop_storage_check(!\Gallery\Services\cooperative_storage_secret_valid($invalidSecret), 'Weak storage material accepted.');
        coop_storage_refuses(/** Exercise configured-key refusal without persistent changes.
         * @return string Derived key, unless the expected refusal is raised.
         */ static fn() => \Gallery\Services\cooperative_storage_key(), 'secret_unavailable');
    }
    foreach ([str_repeat('fixture-only-secret-', 4), hash('sha256', 'existing installation fixture'),
        'An existing long passphrase with punctuation!'] as $validSecret) {
        $GLOBALS['cooperative_test_secret'] = $validSecret;
        coop_storage_check(\Gallery\Services\cooperative_storage_key()
            === hash_hkdf('sha256', $validSecret, 32, 'cooperative-galleries-storage-v1'), 'Existing key derivation changed.');
    }
    unset($GLOBALS['cooperative_test_secret']);

    $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $GLOBALS['cooperative_test_database'] = $pdo;
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY)');
    $pdo->exec('INSERT INTO galleries (id) VALUES (1), (2)');
    // Execute the production table definitions with only MySQL storage/type syntax removed.
    $migration = require dirname(__DIR__) . '/database/migrations/202609270001_cooperative_galleries_foundations.php';
    foreach ($migration as $sql) {
        $sql = preg_replace('/ CHARACTER SET ascii COLLATE ascii_bin| UNSIGNED/', '', $sql);
        $sql = preg_replace('/UNIQUE KEY [a-z_]+ \(([^)]+)\)/', 'UNIQUE ($1)', $sql);
        $sql = preg_replace('/\) ENGINE=InnoDB.*$/s', ')', $sql);
        $pdo->exec($sql);
    }
    $executor = &schema_inspection_query_executor_override();
    $executor = /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool Whether the exact validation or authorization contract holds.
 */ static fn(): bool => true;
    $local = cooperative_instance_id();
    coop_storage_check(cooperative_instance_id() === $local, 'Installation identity changed.');
    $album = cooperative_album_identity(1);
    coop_storage_check(cooperative_album_identity(1) === $album, 'Album identity changed.');
    coop_storage_check(cooperative_album_local_id($album) === 1, 'Album mapping lost.');
    coop_storage_check(cooperative_album_local_id(str_repeat('f', 32)) === null, 'Unknown album resolved.');

    $remote = str_repeat('b', 32);
    $other = str_repeat('c', 32);
    cooperative_peer_register($remote, 'https://remote.example/gallery');
    cooperative_peer_register($other, 'https://other.example');
    $half = cooperative_peer_prepare_credentials($other, 1);
    $halfHash = cooperative_model_peer_find($other)['incoming_hash'];
    coop_storage_check(cooperative_model_peer_find($other)['outgoing_cipher'] === null, 'Initiator required an unknown remote key.');
    $otherRemoteToken = cooperative_credential_generate();
    \Gallery\Services\cooperative_peer_accept_remote_credential($other, 2, $otherRemoteToken);
    coop_storage_check(cooperative_model_peer_find($other)['incoming_hash'] === $halfHash, 'Completing exchange replaced already delivered key.');
    coop_storage_check(\Gallery\Services\cooperative_peer_pairing_credential($other, 3) === $otherRemoteToken, 'Completed outbound key differs.');
    $otherRevision = 3;
    foreach (['local_consent', 'remote_consent', 'inbound_verified', 'outbound_verified'] as $fact) {
        cooperative_peer_record_confirmation($other, $otherRevision++, $fact);
    }
    coop_storage_check(cooperative_peer_authenticate($other, $half['token']) !== null, 'Second direct peer did not activate.');
    $remoteToken = cooperative_credential_generate();
    $issued = cooperative_peer_prepare_credentials($remote, 1, $remoteToken);
    $secret = $issued['token'];
    $stored = cooperative_model_peer_find($remote);
    coop_storage_check(!str_contains(json_encode($stored), $secret) && !str_contains(json_encode($stored), $remoteToken), 'Plaintext credentials persisted.');
    coop_storage_check(cooperative_peer_authenticate($remote, $secret) === null, 'Pending credential accepted.');
    coop_storage_check(\Gallery\Services\cooperative_peer_pairing_credential($remote, 2) === $remoteToken, 'Pending handshake cannot resume.');
    try {
        \Gallery\Services\cooperative_peer_verify_pairing_token($remote, 2, cooperative_credential_generate());
        throw new \RuntimeException('Wrong pairing credential accepted.');
    } catch (CooperativeException $error) {
        coop_storage_check($error->reason === 'pairing_credential_invalid', 'Wrong pairing refusal.');
    }
    \Gallery\Services\cooperative_peer_verify_pairing_token($remote, 2, $secret);
    $revision = 3;
    foreach (['local_consent', 'remote_consent', 'outbound_verified'] as $fact) {
        $summary = cooperative_peer_record_confirmation($remote, $revision++, $fact);
    }
    coop_storage_check($summary['state'] === 'active', 'Complete handshake inactive.');
    $principal = cooperative_peer_authenticate($remote, $secret);
    coop_storage_check($principal !== null && !isset($principal['outgoing_cipher']), 'Authentication leaks credential state.');
    coop_storage_check(cooperative_peer_authenticate($other, $secret) === null, 'Credential crossed friendship.');
    coop_storage_check(cooperative_peer_outbound_credential($remote) === $remoteToken, 'Outbound secret changed.');
    $activePeer = cooperative_model_peer_find($remote);
    $GLOBALS['cooperative_test_secret'] = 'replace-with-a-long-random-secret';
    coop_storage_refuses(/** Exercise decryption refusal for unusable configured key material.
     * @return string Outbound credential, unless the expected refusal is raised.
     */ static fn() => cooperative_peer_outbound_credential($remote), 'secret_unavailable');
    coop_storage_refuses(/** Exercise credential rotation refusal before any persistent change.
     * @return array<string,mixed> Prepared credentials, unless the expected refusal is raised.
     */ static fn() => cooperative_peer_prepare_credentials($remote, 6, $remoteToken), 'secret_unavailable');
    coop_storage_check(cooperative_model_peer_find($remote) === $activePeer, 'Invalid storage key changed existing authority.');
    coop_storage_check($GLOBALS['cooperative_test_secret'] === 'replace-with-a-long-random-secret', 'Invalid secret was automatically rewritten.');
    unset($GLOBALS['cooperative_test_secret']);
    coop_storage_check(cooperative_peer_outbound_credential($remote) === $remoteToken, 'Refusal damaged existing encrypted authority.');
    coop_storage_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => cooperative_peer_suspend($remote, 2), 'revision_conflict');
    cooperative_peer_suspend($remote, 6);
    coop_storage_check(cooperative_peer_authenticate($remote, $secret) === null, 'Suspended peer authenticated.');
    $rotated = cooperative_peer_prepare_credentials($remote, 7, cooperative_credential_generate());
    coop_storage_check(cooperative_peer_authenticate($remote, $secret) === null, 'Rotation retained old authority.');
    coop_storage_check(cooperative_model_peer_find($remote)['confirmations'] === [], 'Rotation retained old proofs.');

    // Unknown full schema must refuse before creating a credential or identity.
    schema_inspection_reset_request_cache();
    $executor = /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => throw new \RuntimeException('private raw database diagnostic');
    $before = cooperative_model_peer_find($remote);
    coop_storage_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => cooperative_peer_prepare_credentials($remote, 8, cooperative_credential_generate()), 'schema_unknown');
    coop_storage_check(cooperative_model_peer_find($remote) === $before, 'Unknown schema partially mutated credential.');
    coop_storage_check(cooperative_storage_status('peers')['state'] === 'unknown', 'Unknown schema collapsed to missing.');

    // Revocation does not depend on outbound encrypted storage or pairing metadata.
    schema_inspection_reset_request_cache();
    $executor = /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @param string $type Schema object type supplied by the shared inspector.
 * @param string $table Validated schema table identifier.
 * @param string $object Validated schema column or index identifier.
 * @return bool Whether the exact validation or authorization contract holds.
 */ static fn(string $type, string $table, string $object): bool => !in_array($object, ['outgoing_cipher', 'confirmations_json'], true);
    coop_storage_check(cooperative_storage_status('peers')['state'] === 'missing', 'Missing schema not preserved.');
    $GLOBALS['cooperative_test_secret'] = 'replace-with-a-long-random-secret';
    cooperative_peer_revoke($remote);
    unset($GLOBALS['cooperative_test_secret']);
    $revoked = cooperative_model_peer_find($remote);
    coop_storage_check($revoked['state'] === 'revoked' && $revoked['incoming_hash'] === null, 'Narrow revocation failed.');
    coop_storage_check(!\Gallery\Models\cooperative_model_peer_save($before, $before['revision'], '2026-09-27 00:00:00'), 'Stale peer writer revived revoked authority.');
    coop_storage_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => cooperative_peer_prepare_credentials($other, 1, $remoteToken), 'schema_missing');

    schema_inspection_reset_request_cache();
    $executor = /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool Whether the exact validation or authorization contract holds.
 */ static fn(): bool => true;
    coop_storage_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => cooperative_peer_prepare_credentials($remote, $revoked['revision'], $remoteToken), 'peer_revoked');
    coop_storage_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => cooperative_peer_outbound_credential($remote), 'peer_inactive');

    $created = cooperative_group_create();
    $groupId = $created['group']['group_id'];
    $members = [
        ['instance_id' => $local, 'album_id' => $album, 'scopes' => ['metadata']],
        ['instance_id' => $remote, 'album_id' => str_repeat('d', 32), 'scopes' => ['metadata']],
    ];
    $updated = cooperative_group_update($groupId, 1,
        /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @return array<string,mixed> Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn(array $group): array => cooperative_group_propose($group, $local, $members, 1800000000));
    coop_storage_check($updated['storage_revision'] === 2 && $updated['group']['revision'] === 0, 'Storage and membership revisions conflated.');
    coop_storage_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => cooperative_group_update($groupId, 1, /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @return array<string,mixed> Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn(array $group): array => $group), 'revision_conflict');
    coop_storage_check(cooperative_group_read($groupId) === $updated, 'Stale writer changed stored group.');
    coop_storage_check(!\Gallery\Models\cooperative_model_group_save($created['group'], 1, '2026-09-27 00:00:00'), 'Storage CAS lost a concurrent group update.');
    coop_storage_refuses(/**
 * Evaluate the captured cooperative fixture operation without external state.
 * @return bool|string|array<string,mixed>|null Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn() => cooperative_group_update($groupId, 2, /**
 * Evaluate the captured cooperative fixture operation without external state.
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @return array<string,mixed> Validated domain result described above; null represents absence or refusal where allowed.
 */ static fn(array $group): array => array_replace($group, ['coordinator_id' => $remote])), 'invalid_group_transition');
    coop_storage_check(cooperative_group_read($groupId) === $updated, 'Refused transition persisted.');
    $pdo->exec('DELETE FROM galleries WHERE id = 1');
    coop_storage_check(cooperative_album_local_id($album) === null, 'Deleted gallery still resolves.');
    $pdo->exec('INSERT INTO galleries (id) VALUES (1)');
    coop_storage_check(cooperative_album_identity(1) !== $album, 'Reused local ID revived old public identity.');
    echo "Cooperative galleries isolated persistence contracts passed.\n";
}
