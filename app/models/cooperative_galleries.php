<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/cooperative_galleries.php
 * Module Type: Model
 * Purpose: Persist cooperative identities, isolated credentials and revisioned aggregates.
 * Responsibilities: Own SQL, row mapping and optimistic concurrency without HTTP policy.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use function Gallery\Core\db;

/** Atomically install a singleton identity; concurrent callers retain the winning identity.
 *
 * @param string $candidate Fresh opaque identifier used only if no identity exists.
 * @return string Canonical identifier, digest or secret as described above.
 */
function cooperative_model_identity(string $candidate): string
{
    $pdo = db();
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'INSERT OR IGNORE INTO cooperative_identity (singleton_id, instance_id) VALUES (1, ?)'
        : 'INSERT INTO cooperative_identity (singleton_id, instance_id) VALUES (1, ?) ON DUPLICATE KEY UPDATE singleton_id = singleton_id';
    $pdo->prepare($sql)->execute([$candidate]);
    return (string) $pdo->query('SELECT instance_id FROM cooperative_identity WHERE singleton_id = 1')->fetchColumn();
}

/** Insert one pending peer; duplicate identities fail without replacing credentials.
 *
 * @param array<string,mixed> $peer Internal peer record containing pairing state and credential material.
 * @param string $now UTC timestamp formatted as Y-m-d H:i:s for persistence.
 * @return void No return value; failure raises an exception.
 */
function cooperative_model_peer_insert(array $peer, string $now): void
{
    db()->prepare('INSERT INTO cooperative_peers (instance_id, base_url, state, revision, incoming_hash, outgoing_cipher, confirmations_json, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$peer['instance_id'], $peer['base_url'], $peer['state'], $peer['revision'], $peer['incoming_hash'], $peer['outgoing_cipher'], json_encode($peer['confirmations'], JSON_THROW_ON_ERROR), $now]);
}

/** Read a peer credential record for internal service use only.
 *
 * @param string $id Stable public identifier of the persisted domain object.
 * @return array<string,mixed>|null Validated domain state, or null when no usable record exists.
 */
function cooperative_model_peer_find(string $id): ?array
{
    $statement = db()->prepare('SELECT * FROM cooperative_peers WHERE instance_id = ?');
    $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['revision'] = (int) $row['revision'];
    $row['confirmations'] = json_decode($row['confirmations_json'], true, 16, JSON_THROW_ON_ERROR);
    unset($row['confirmations_json'], $row['updated_at']);
    return $row;
}

/** Replace a peer only at the expected revision; no lost updates or stale credential revival.
 *
 * @param array<string,mixed> $peer Internal peer record containing pairing state and credential material.
 * @param int $expected Expected optimistic storage revision, distinct from membership revision.
 * @param string $now UTC timestamp formatted as Y-m-d H:i:s for persistence.
 * @return bool Whether the exact validation or authorization contract holds.
 */
function cooperative_model_peer_save(array $peer, int $expected, string $now): bool
{
    $statement = db()->prepare('UPDATE cooperative_peers SET base_url = ?, state = ?, revision = ?, incoming_hash = ?, outgoing_cipher = ?, confirmations_json = ?, updated_at = ? WHERE instance_id = ? AND revision = ?');
    $statement->execute([$peer['base_url'], $peer['state'], $expected + 1, $peer['incoming_hash'], $peer['outgoing_cipher'], json_encode($peer['confirmations'], JSON_THROW_ON_ERROR), $now, $peer['instance_id'], $expected]);
    return $statement->rowCount() === 1;
}

/** Revoke with a deliberately narrow schema dependency; also invalidates in-flight writers.
 *
 * @param string $id Stable public identifier of the persisted domain object.
 * @return void No return value; failure raises an exception.
 */
function cooperative_model_peer_revoke(string $id): void
{
    db()->prepare("UPDATE cooperative_peers SET state = 'revoked', incoming_hash = NULL, revision = revision + 1 WHERE instance_id = ?")
        ->execute([$id]);
}

/** Insert a new collaboration aggregate without overwriting an existing group.
 *
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @param string $now UTC timestamp formatted as Y-m-d H:i:s for persistence.
 * @return void No return value; failure raises an exception.
 */
function cooperative_model_group_insert(array $group, string $now): void
{
    db()->prepare('INSERT INTO cooperative_groups (group_id, storage_revision, state_json, updated_at) VALUES (?, 1, ?, ?)')
        ->execute([$group['group_id'], json_encode($group, JSON_THROW_ON_ERROR), $now]);
}

/** Fetch a group and its independent optimistic storage revision.
 *
 * @param string $id Stable public identifier of the persisted domain object.
 * @return array<string,mixed>|null Validated domain state, or null when no usable record exists.
 */
function cooperative_model_group_find(string $id): ?array
{
    $statement = db()->prepare('SELECT storage_revision, state_json FROM cooperative_groups WHERE group_id = ?');
    $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return $row ? ['storage_revision' => (int) $row['storage_revision'], 'group' => json_decode($row['state_json'], true, 32, JSON_THROW_ON_ERROR)] : null;
}

/** Atomically persist one domain transition; stale callers must reload and revalidate.
 *
 * @param array<string,mixed> $group Trusted local collaboration aggregate, not a remotely supplied snapshot.
 * @param int $expected Expected optimistic storage revision, distinct from membership revision.
 * @param string $now UTC timestamp formatted as Y-m-d H:i:s for persistence.
 * @return bool Whether the exact validation or authorization contract holds.
 */
function cooperative_model_group_save(array $group, int $expected, string $now): bool
{
    $statement = db()->prepare('UPDATE cooperative_groups SET state_json = ?, storage_revision = storage_revision + 1, updated_at = ? WHERE group_id = ? AND storage_revision = ?');
    $statement->execute([json_encode($group, JSON_THROW_ON_ERROR), $now, $group['group_id'], $expected]);
    return $statement->rowCount() === 1;
}
/** Allocate one public album identity without exposing the local sequential ID.
 *
 * @param int $galleryId Existing local gallery primary key, after ownership authorization.
 * @param string $candidate Fresh opaque identifier used only if no identity exists.
 * @return string Canonical identifier, digest or secret as described above.
 */
function cooperative_model_album_identity(int $galleryId, string $candidate): string
{
    $pdo = db();
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'INSERT INTO cooperative_albums (gallery_id, album_id) VALUES (?, ?) ON CONFLICT(gallery_id) DO NOTHING'
        : 'INSERT INTO cooperative_albums (gallery_id, album_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE gallery_id = gallery_id';
    $pdo->prepare($sql)->execute([$galleryId, $candidate]);
    $statement = $pdo->prepare('SELECT album_id FROM cooperative_albums WHERE gallery_id = ?');
    $statement->execute([$galleryId]);
    return (string) $statement->fetchColumn();
}

/** Resolve a public identity to a local gallery; resolution itself grants no access.
 *
 * @param string $albumId Stable opaque public album identity.
 * @return ?int Resolved local gallery ID, or null when absent.
 */
function cooperative_model_album_local_id(string $albumId): ?int
{
    $statement = db()->prepare('SELECT gallery_id FROM cooperative_albums WHERE album_id = ?');
    $statement->execute([$albumId]);
    $id = $statement->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** Insert an immutable candidate without replacing a competing or replayed aggregate.
 * @param array<string,mixed> $group Locally validated initial proposal aggregate.
 * @param string $now UTC persistence timestamp.
 * @return void Caller reloads and compares the winning immutable body.
 */
function cooperative_model_group_insert_once(array $group, string $now): void
{
    $pdo = db();
    $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? 'INSERT INTO cooperative_groups (group_id, storage_revision, state_json, updated_at) VALUES (?, 1, ?, ?) ON CONFLICT(group_id) DO NOTHING'
        : 'INSERT INTO cooperative_groups (group_id, storage_revision, state_json, updated_at) VALUES (?, 1, ?, ?) ON DUPLICATE KEY UPDATE group_id = group_id';
    $pdo->prepare($sql)->execute([$group['group_id'], json_encode($group, JSON_THROW_ON_ERROR), $now]);
}

/** Fetch one bounded lexical page of locally persisted groups for Admin projection.
 * @param string $afterId Exclusive group-ID cursor, empty for the first page.
 * @param int $limit Maximum rows including service-owned lookahead.
 * @return list<array<string,mixed>> Internal aggregates; never serialize raw rows to peers.
 */
function cooperative_model_groups_page(string $afterId, int $limit): array
{
    if ($limit < 1) {
        throw new \InvalidArgumentException('Invalid group page limit.');
    }
    $statement = db()->prepare('SELECT storage_revision, state_json FROM cooperative_groups WHERE group_id > ? ORDER BY group_id LIMIT ' . $limit);
    $statement->execute([$afterId]);
    $result = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[] = ['storage_revision' => (int) $row['storage_revision'], 'group' => json_decode($row['state_json'], true, 32, JSON_THROW_ON_ERROR)];
    }
    return $result;
}

/** Read a stable bounded page of direct public non-NSFW photos for an authorized album.
 * @param int $galleryId Exact local gallery identity chosen by the policy service.
 * @param int $afterId Exclusive primary-key cursor; insertions do not reorder earlier pages.
 * @param int $limit Bounded page size including lookahead.
 * @return list<array<string,mixed>> Source rows for service-level filtering and whitelisting.
 */
function cooperative_model_photos_page(int $galleryId, int $afterId, int $limit): array
{
    if ($limit < 1 || $limit > 9 || $galleryId < 1 || $afterId < 0) { throw new \InvalidArgumentException('Invalid photo page.'); }
    $statement = db()->prepare("SELECT id, gallery_id, filename, relative_path, visibility, nsfw_enabled FROM images
        WHERE gallery_id = ? AND id > ? AND visibility = 'public' AND nsfw_enabled = 0
        AND relative_path NOT LIKE '%/%' ORDER BY id LIMIT " . $limit);
    $statement->execute([$galleryId, $afterId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** Find a bounded set of collaborations associated with one local source.
 * @param int $galleryId Local source primary key.
 * @return list<string> Active or locally approved group IDs for public discovery.
 */
function cooperative_model_gallery_groups(int $galleryId): array
{
    $pdo = db();
    $extract = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
        ? "json_extract(state_json, '$.exchange.gallery_id')"
        : "CAST(JSON_UNQUOTE(JSON_EXTRACT(state_json, '$.exchange.gallery_id')) AS UNSIGNED)";
    $statement = $pdo->prepare('SELECT group_id FROM cooperative_groups WHERE ' . $extract . ' = ? ORDER BY group_id LIMIT 32');
    $statement->execute([$galleryId]);
    return $statement->fetchAll(PDO::FETCH_COLUMN);
}
