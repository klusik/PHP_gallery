<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/music_catalog.php
 * Module Type: Model
 * Purpose: Persist normalized songs, physical track references and albums.
 * Responsibilities: Own catalog SQL, transactions and revision comparisons.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

use PDO;
use Closure;
use function Gallery\Core\db;

/** Find candidate songs included by the current authoritative gallery sources.
 * @param int $galleryId Physical gallery identity.
 * @param list<string> $trackIds Current direct source identities.
 * @param list<int> $albumIds Current live album identities.
 * @param list<int> $songIds Bounded candidate song identities to check.
 * @return list<int> Candidate songs present directly or through a live album.
 */
function music_catalog_model_selected_song_ids(
    int $galleryId,
    array $trackIds,
    array $albumIds,
    array $songIds,
): array {
    if ($songIds === [] || ($trackIds === [] && $albumIds === [])) {
        return [];
    }
    $queries = [];
    $parameters = [];
    if ($trackIds !== []) {
        $marks = implode(',', array_fill(0, count($trackIds), '?'));
        $queries[] = 'SELECT song_id FROM music_track_instances'
            . ' WHERE gallery_id = ? AND active = 1 AND track_id IN ('
            . $marks . ')';
        $parameters = array_merge([$galleryId], $trackIds);
    }
    if ($albumIds !== []) {
        $marks = implode(',', array_fill(0, count($albumIds), '?'));
        $queries[] = 'SELECT song_id FROM music_album_songs'
            . ' WHERE album_id IN (' . $marks . ')';
        $parameters = array_merge($parameters, $albumIds);
    }
    $marks = implode(',', array_fill(0, count($songIds), '?'));
    $statement = db()->prepare('SELECT DISTINCT song_id FROM ('
        . implode(' UNION ALL ', $queries) . ') selected_music'
        . ' WHERE song_id IN (' . $marks . ')');
    $statement->execute(array_merge($parameters, $songIds));
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/** Read the persisted global catalog revision.
 * @return string Decimal revision token, or initial token when the row is absent.
 */
function music_catalog_model_revision(): string
{
    $row = db()->query("SELECT revision FROM music_catalog_state WHERE state_key = 'catalog'")->fetch(PDO::FETCH_ASSOC);
    return (string) ($row['revision'] ?? '1');
}

/** Read catalog identities and complete usage counts for one page.
 * @param string $query Search text already bounded by the service.
 * @param int $limit Maximum returned songs.
 * @param int $offset Zero-based row offset.
 * @param string $sort Semantic sort key: title, files, locations, or tags.
 * @param string $direction Ascending or descending order: asc or desc.
 * @param bool $showExtensions Whether filename-derived titles retain extensions.
 * @param list<string> $audioExtensions Recognized audio suffixes from the title policy.
 * @param string $untitledTemplate Localized fallback containing the {id} placeholder.
 * @param bool $repositoryReady Whether repository asset metadata is fully available.
 * @return array{revision:string,songs:list<array{id:int,custom_title:?string,indexed_title:?string,filename:?string,track_count:int|string,album_count:int|string,direct_gallery_count:int|string}>,total:int} Catalog page and total match count.
 * @throws \InvalidArgumentException When the semantic ordering is not supported.
 */
function music_catalog_model_list(
    string $query,
    int $limit,
    int $offset,
    string $sort,
    string $direction,
    bool $showExtensions,
    array $audioExtensions,
    string $untitledTemplate,
    bool $repositoryReady = false,
): array {
    $orders = [
        'title' => '',
        'files' => 'c.track_count',
        'locations' => $repositoryReady
            ? '(c.direct_gallery_count + c.album_count + (SELECT COUNT(DISTINCT l.gallery_id) FROM music_gallery_album_links l JOIN music_album_songs m ON m.album_id = l.album_id WHERE m.song_id = c.id AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries dirty_link WHERE dirty_link.gallery_id = l.gallery_id) AND NOT EXISTS (SELECT 1 FROM music_track_instances direct_ref WHERE direct_ref.song_id = c.id AND direct_ref.gallery_id = l.gallery_id AND direct_ref.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries dirty_direct WHERE dirty_direct.gallery_id = direct_ref.gallery_id))))'
            : '(c.direct_gallery_count + c.album_count)',
        'tags' => '(SELECT MIN(t.name) FROM music_song_tags st '
            . 'JOIN tags t ON t.id = st.tag_id '
            . 'WHERE st.song_id = c.id AND t.media_type = "music")',
    ];
    if (!isset($orders[$sort]) || !in_array($direction, ['asc', 'desc'], true)) {
        throw new \InvalidArgumentException('Invalid music catalog ordering.');
    }
    $needle = '%' . $query . '%';
    $parameters = [$needle, $needle, $needle, $needle];
    $repositorySearch = '';
    if ($repositoryReady) {
        $repositorySearch = ' OR EXISTS (SELECT 1 FROM music_repository_assets a WHERE a.song_id = s.id AND (a.filename LIKE ? OR a.indexed_title LIKE ?))';
        $parameters[] = $needle;
        $parameters[] = $needle;
    }
    // SQL search placeholders precede ORDER BY title placeholders. Keep this
    // prefix intact before appending extension/title ordering parameters.
    $searchParameters = $parameters;
    $order = $orders[$sort];
    if ($sort === 'title') {
        // Sort the whole SQL result before pagination using the same semantic
        // title inputs as the public presentation owner; no filesystem reads.
        $basename = "SUBSTRING_INDEX(REPLACE(COALESCE(c.filename, ''), "
            . "CHAR(92), '/'), '/', -1)";
        $automatic = $basename;
        if (!$showExtensions && $audioExtensions !== []) {
            $marks = implode(',', array_fill(0, count($audioExtensions), '?'));
            $extension = "SUBSTRING_INDEX($basename, '.', -1)";
            $automatic = "CASE WHEN LOCATE('.', $basename) > 0 "
                . "AND LOWER($extension) IN ($marks) "
                . "THEN LEFT($basename, CHAR_LENGTH($basename) "
                . "- CHAR_LENGTH($extension) - 1) ELSE $basename END";
            array_push($parameters, ...$audioExtensions);
        }
        $indexed = "CASE WHEN c.indexed_title IS NOT NULL "
            . "AND c.indexed_title <> '' "
            . "AND BINARY c.indexed_title <> BINARY c.filename "
            . "AND BINARY c.indexed_title <> BINARY $basename "
            . "THEN c.indexed_title ELSE $automatic END";
        $order = "COALESCE(NULLIF(COALESCE(NULLIF(c.custom_title, ''), "
            . "$indexed), ''), REPLACE(?, '{id}', CAST(c.id AS CHAR)))";
        $parameters[] = $untitledTemplate;
    }
    $trackCount = $repositoryReady
        ? '(SELECT COUNT(*) FROM music_repository_assets a WHERE a.song_id = s.id) + COUNT(DISTINCT CASE WHEN i.asset_id IS NULL AND NOT EXISTS (SELECT 1 FROM music_repository_assets a WHERE a.origin_gallery_id = i.gallery_id AND a.origin_track_id = i.track_id) THEN CONCAT(i.gallery_id, ":", i.track_id) END)'
        : 'COUNT(DISTINCT CONCAT(i.gallery_id, ":", i.track_id))';
    $assetTitle = $repositoryReady
        ? '(SELECT a.indexed_title FROM music_repository_assets a WHERE a.song_id = s.id ORDER BY a.created_at, a.id LIMIT 1)'
        : 'NULL';
    $assetFilename = $repositoryReady
        ? '(SELECT a.filename FROM music_repository_assets a WHERE a.song_id = s.id ORDER BY a.created_at, a.id LIMIT 1)'
        : 'NULL';
    $source =
        'SELECT s.id, s.title AS custom_title, COALESCE((SELECT q.indexed_title FROM music_track_instances q WHERE q.song_id = s.id AND q.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = q.gallery_id) ORDER BY q.gallery_id, q.track_id LIMIT 1), ' . $assetTitle . ') AS indexed_title, COALESCE((SELECT q.filename FROM music_track_instances q WHERE q.song_id = s.id AND q.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = q.gallery_id) ORDER BY q.gallery_id, q.track_id LIMIT 1), ' . $assetFilename . ') AS filename, ' . $trackCount . ' AS track_count, '
        . '(SELECT COUNT(*) FROM music_album_songs mas WHERE mas.song_id = s.id) AS album_count, '
        . 'COUNT(DISTINCT i.gallery_id) AS direct_gallery_count '
        . 'FROM music_songs s LEFT JOIN music_track_instances i ON i.song_id = s.id AND i.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id) '
        . 'WHERE COALESCE(s.title, "") LIKE ? OR EXISTS (SELECT 1 FROM music_track_instances q WHERE q.song_id = s.id AND q.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = q.gallery_id) AND (q.filename LIKE ? OR q.indexed_title LIKE ?)) OR EXISTS (SELECT 1 FROM music_song_tags st JOIN tags t ON t.id = st.tag_id WHERE st.song_id = s.id AND t.media_type = "music" AND t.name LIKE ?)' . $repositorySearch . ' GROUP BY s.id, s.title';
    $statement = db()->prepare(
        'SELECT c.* FROM (' . $source . ') c ORDER BY '
            . $order . ' ' . strtoupper($direction) . ', c.id ASC LIMIT ? OFFSET ?',
    );
    foreach ($parameters as $index => $value) {
        $statement->bindValue($index + 1, $value);
    }
    $statement->bindValue(count($parameters) + 1, $limit, PDO::PARAM_INT);
    $statement->bindValue(count($parameters) + 2, $offset, PDO::PARAM_INT);
    $statement->execute();
    $songs = $statement->fetchAll(PDO::FETCH_ASSOC);
    $count = db()->prepare('SELECT COUNT(*) FROM music_songs s WHERE COALESCE(s.title, "") LIKE ? OR EXISTS (SELECT 1 FROM music_track_instances q WHERE q.song_id = s.id AND q.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = q.gallery_id) AND (q.filename LIKE ? OR q.indexed_title LIKE ?)) OR EXISTS (SELECT 1 FROM music_song_tags st JOIN tags t ON t.id = st.tag_id WHERE st.song_id = s.id AND t.media_type = "music" AND t.name LIKE ?)' . $repositorySearch);
    $count->execute($searchParameters);
    return ['revision' => music_catalog_model_revision(), 'songs' => $songs, 'total' => (int) $count->fetchColumn()];
}

/** Attach or refresh one gallery-scoped track reference.
 * @param array{gallery_id:int,track_id:string,title:string,filename:string,sha256:string,asset_id?:string|null} $track Indexed metadata and optional central asset link.
 * @return int Existing or newly allocated song identity.
 */
function music_catalog_model_attach_track(array $track): int
{
    $find = db()->prepare('SELECT song_id FROM music_track_instances WHERE gallery_id = ? AND track_id = ? FOR UPDATE');
    $find->execute([$track['gallery_id'], $track['track_id']]);
    $existing = $find->fetchColumn();
    if ($existing !== false) {
        db()->prepare('UPDATE music_track_instances SET filename = ?, indexed_title = ?, file_sha256 = ?, active = 1, updated_at = NOW() WHERE gallery_id = ? AND track_id = ?')->execute([
            $track['filename'],
            $track['title'],
            $track['sha256'],
            $track['gallery_id'],
            $track['track_id'],
        ]);
        return (int) $existing;
    }
    $songId = null;
    if (!empty($track['asset_id'])) {
        $assetSong = db()->prepare('SELECT song_id FROM music_repository_assets WHERE id = ?');
        $assetSong->execute([$track['asset_id']]);
        $assetSongId = $assetSong->fetchColumn();
        if ($assetSongId === false) {
            throw new \RuntimeException('music.repository_conflict');
        }
        $songId = (int) $assetSongId;
    }
    if ($songId === null) {
        $insert = db()->prepare('INSERT INTO music_songs (title, created_at, updated_at) VALUES (NULL, NOW(), NOW())');
        $insert->execute();
        $songId = (int) db()->lastInsertId();
    }
    if (!empty($track['asset_id'])) {
        $attach = db()->prepare('INSERT INTO music_track_instances (gallery_id, track_id, song_id, asset_id, filename, indexed_title, file_sha256, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $attach->execute([$track['gallery_id'], $track['track_id'], $songId, $track['asset_id'], $track['filename'], $track['title'], $track['sha256']]);
    } else {
        $attach = db()->prepare('INSERT INTO music_track_instances (gallery_id, track_id, song_id, filename, indexed_title, file_sha256, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $attach->execute([$track['gallery_id'], $track['track_id'], $songId, $track['filename'], $track['title'], $track['sha256']]);
    }
    return $songId;
}

/** Find a current logical song for one gallery-scoped physical reference.
 * @param int $galleryId Stable gallery identity.
 * @param string $trackId Stable track identity within that gallery.
 * @return int|null Song identity, or null when the reference is not indexed.
 */
function music_catalog_model_track_song_id(int $galleryId, string $trackId): ?int
{
    $statement = db()->prepare('SELECT song_id FROM music_track_instances WHERE gallery_id = ? AND track_id = ? AND active = 1');
    $statement->execute([$galleryId, $trackId]);
    $songId = $statement->fetchColumn();
    return $songId === false ? null : (int) $songId;
}

/** Read immutable repository metadata by its independent asset identity.
 * @param string $assetId Stable 32-character repository asset ID.
 * @return array{id:string,song_id:int,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null}|null Asset metadata, or null when absent.
 */
function music_repository_model_asset(string $assetId): ?array
{
    $statement = db()->prepare('SELECT id, song_id, filename, indexed_title, mime, byte_size, sha256, origin_gallery_id, origin_track_id FROM music_repository_assets WHERE id = ?');
    $statement->execute([$assetId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    return [
        'id' => (string) $row['id'],
        'song_id' => (int) $row['song_id'],
        'filename' => (string) $row['filename'],
        'indexed_title' => (string) $row['indexed_title'],
        'mime' => (string) $row['mime'],
        'byte_size' => (int) $row['byte_size'],
        'sha256' => (string) $row['sha256'],
        'origin_gallery_id' => $row['origin_gallery_id'] === null
            ? null
            : (int) $row['origin_gallery_id'],
        'origin_track_id' => $row['origin_track_id'] === null
            ? null
            : (string) $row['origin_track_id'],
    ];
}

/** List immutable assets attached to one logical song in stable order.
 * @param int $songId Stable normalized song identity.
 * @return list<array{id:string,song_id:int,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null}> Repository asset rows.
 */
function music_repository_model_assets_for_song(int $songId): array
{
    $statement = db()->prepare('SELECT id, song_id, filename, indexed_title, mime, byte_size, sha256, origin_gallery_id, origin_track_id FROM music_repository_assets WHERE song_id = ? ORDER BY created_at, id');
    $statement->execute([$songId]);
    $assets = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $assets[] = [
            'id' => (string) $row['id'],
            'song_id' => (int) $row['song_id'],
            'filename' => (string) $row['filename'],
            'indexed_title' => (string) $row['indexed_title'],
            'mime' => (string) $row['mime'],
            'byte_size' => (int) $row['byte_size'],
            'sha256' => (string) $row['sha256'],
            'origin_gallery_id' => $row['origin_gallery_id'] === null
                ? null
                : (int) $row['origin_gallery_id'],
            'origin_track_id' => $row['origin_track_id'] === null
                ? null
                : (string) $row['origin_track_id'],
        ];
    }
    return $assets;
}

/** Read assets for one bounded logical-song batch in a single SQL statement.
 * @param list<int> $songIds Distinct positive song IDs, capped at 100.
 * @return array<int,list<array{id:string,song_id:int,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null}>> Asset rows grouped by song identity.
 */
function music_repository_model_assets_for_songs(array $songIds): array
{
    if ($songIds === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($songIds), '?'));
    $statement = db()->prepare("SELECT id, song_id, filename, indexed_title, mime, byte_size, sha256, origin_gallery_id, origin_track_id FROM music_repository_assets WHERE song_id IN ($marks) ORDER BY song_id, created_at, id");
    $statement->execute($songIds);
    $assets = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $songId = (int) $row['song_id'];
        $assets[$songId][] = [
            'id' => (string) $row['id'],
            'song_id' => $songId,
            'filename' => (string) $row['filename'],
            'indexed_title' => (string) $row['indexed_title'],
            'mime' => (string) $row['mime'],
            'byte_size' => (int) $row['byte_size'],
            'sha256' => (string) $row['sha256'],
            'origin_gallery_id' => $row['origin_gallery_id'] === null ? null : (int) $row['origin_gallery_id'],
            'origin_track_id' => $row['origin_track_id'] === null ? null : (string) $row['origin_track_id'],
        ];
    }
    return $assets;
}

/** Read assets for a bounded set of independent central asset IDs.
 * @param list<string> $assetIds Distinct lowercase 32-hex asset IDs, capped at 100.
 * @return array<string,array{id:string,song_id:int,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null}> Existing rows keyed by asset ID.
 */
function music_repository_model_assets_by_ids(array $assetIds): array
{
    if ($assetIds === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($assetIds), '?'));
    $statement = db()->prepare("SELECT id, song_id, filename, indexed_title, mime, byte_size, sha256, origin_gallery_id, origin_track_id FROM music_repository_assets WHERE id IN ($marks)");
    $statement->execute($assetIds);
    $assets = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $assetId = (string) $row['id'];
        $assets[$assetId] = [
            'id' => $assetId,
            'song_id' => (int) $row['song_id'],
            'filename' => (string) $row['filename'],
            'indexed_title' => (string) $row['indexed_title'],
            'mime' => (string) $row['mime'],
            'byte_size' => (int) $row['byte_size'],
            'sha256' => (string) $row['sha256'],
            'origin_gallery_id' => $row['origin_gallery_id'] === null ? null : (int) $row['origin_gallery_id'],
            'origin_track_id' => $row['origin_track_id'] === null ? null : (string) $row['origin_track_id'],
        ];
    }
    return $assets;
}

/** Read direct and album-member asset metadata together in one bounded query.
 * @param list<string> $assetIds Direct asset IDs requested by the playlist.
 * @param list<int> $songIds Logical song IDs expanded from live album sources.
 * @return array{assets_by_id:array<string,array{id:string,song_id:int,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null}>,assets_by_song:array<int,list<array{id:string,song_id:int,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null}>>} Direct requested assets and one canonical asset per requested song.
 */
function music_repository_model_projection_assets(array $assetIds, array $songIds): array
{
    $conditions = [];
    $parameters = [];
    if ($assetIds !== []) {
        $conditions[] = 'id IN (' . implode(',', array_fill(0, count($assetIds), '?')) . ')';
        array_push($parameters, ...$assetIds);
    }
    if ($songIds !== []) {
        $songMarks = implode(',', array_fill(0, count($songIds), '?'));
        $conditions[] = '(song_id IN (' . $songMarks . ') AND NOT EXISTS (SELECT 1 FROM music_repository_assets prior WHERE prior.song_id = music_repository_assets.song_id AND (prior.created_at < music_repository_assets.created_at OR (prior.created_at = music_repository_assets.created_at AND prior.id < music_repository_assets.id))))';
        array_push($parameters, ...$songIds);
    }
    if ($conditions === []) {
        return ['assets_by_id' => [], 'assets_by_song' => []];
    }
    $sql = 'SELECT id, song_id, filename, indexed_title, mime, byte_size, sha256, origin_gallery_id, origin_track_id FROM music_repository_assets WHERE ' . implode(' OR ', $conditions) . ' ORDER BY created_at, id';
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    $byId = [];
    $bySong = [];
    $requestedSongs = array_fill_keys($songIds, true);
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $assetId = (string) $row['id'];
        $songId = (int) $row['song_id'];
        $asset = [
            'id' => $assetId,
            'song_id' => $songId,
            'filename' => (string) $row['filename'],
            'indexed_title' => (string) $row['indexed_title'],
            'mime' => (string) $row['mime'],
            'byte_size' => (int) $row['byte_size'],
            'sha256' => (string) $row['sha256'],
            'origin_gallery_id' => $row['origin_gallery_id'] === null ? null : (int) $row['origin_gallery_id'],
            'origin_track_id' => $row['origin_track_id'] === null ? null : (string) $row['origin_track_id'],
        ];
        $byId[$assetId] = $asset;
        if (isset($requestedSongs[$songId])) {
            $bySong[$songId][] = $asset;
        }
    }
    return ['assets_by_id' => $byId, 'assets_by_song' => $bySong];
}

/** Register immutable asset metadata, optionally creating a fresh song.
 * @param array{id:string,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string} $asset Verified metadata for an already published immutable original.
 * @param int|null $songId Existing logical song, or null to create one.
 * @param array{gallery_id:int,track_id:string}|null $origin Stable legacy source used for idempotent promotion.
 * @return array{id:string,song_id:int,filename:string,indexed_title:string,mime:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null} Persisted row, possibly the prior row for the same origin.
 * @throws \RuntimeException When immutable ID/origin metadata conflicts.
 */
function music_repository_model_register_asset(array $asset, ?int $songId, ?array $origin): array
{
    $pdo = db();
    if ($origin !== null) {
        $originLookup = $pdo->prepare('SELECT id FROM music_repository_assets WHERE origin_gallery_id = ? AND origin_track_id = ? FOR UPDATE');
        $originLookup->execute([$origin['gallery_id'], $origin['track_id']]);
        $originAssetId = $originLookup->fetchColumn();
        if (is_string($originAssetId)) {
            $existing = music_repository_model_asset($originAssetId);
            if (
                $existing === null
                || $existing['sha256'] !== $asset['sha256']
                || $existing['byte_size'] !== $asset['byte_size']
                || $existing['filename'] !== $asset['filename']
                || ($songId !== null && $existing['song_id'] !== $songId)
            ) {
                throw new \RuntimeException('music.repository_conflict');
            }
            return $existing;
        }
    }
    $existing = music_repository_model_asset($asset['id']);
    if ($existing !== null) {
        if (
            $existing['sha256'] !== $asset['sha256']
            || $existing['byte_size'] !== $asset['byte_size']
            || $existing['filename'] !== $asset['filename']
            || $existing['indexed_title'] !== $asset['indexed_title']
            || $existing['mime'] !== $asset['mime']
            || ($songId !== null && $existing['song_id'] !== $songId)
        ) {
            throw new \RuntimeException('music.repository_conflict');
        }
        return $existing;
    }
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        if ($songId === null) {
            $pdo->prepare('INSERT INTO music_songs (title, created_at, updated_at) VALUES (NULL, NOW(), NOW())')->execute();
            $songId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO music_repository_assets (id, song_id, filename, indexed_title, mime, byte_size, sha256, origin_gallery_id, origin_track_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')->execute([
            $asset['id'],
            $songId,
            $asset['filename'],
            $asset['indexed_title'],
            $asset['mime'],
            $asset['byte_size'],
            $asset['sha256'],
            $origin['gallery_id'] ?? null,
            $origin['track_id'] ?? null,
        ]);
        $registered = music_repository_model_asset($asset['id'])
            ?? throw new \RuntimeException('music.repository_conflict');
        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $registered;
    } catch (\Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/** Read active gallery-local originals available for explicit Admin promotion.
 * @param int $songId Stable normalized song identity.
 * @return list<array{gallery_id:int,folder_path:string,track_id:string,filename:string,indexed_title:string,sha256:string}> Legacy source records; MIME and size come from the locked authoritative playlist.
 */
function music_repository_model_song_sources(int $songId): array
{
    $statement = db()->prepare('SELECT i.gallery_id, g.folder_path, i.track_id, i.filename, i.indexed_title, i.file_sha256 AS sha256 FROM music_track_instances i JOIN galleries g ON g.id = i.gallery_id WHERE i.song_id = ? AND i.active = 1 AND i.asset_id IS NULL AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id) ORDER BY i.gallery_id, i.track_id');
    $statement->execute([$songId]);
    $sources = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sources[] = [
            'gallery_id' => (int) $row['gallery_id'],
            'folder_path' => (string) $row['folder_path'],
            'track_id' => (string) $row['track_id'],
            'filename' => (string) $row['filename'],
            'indexed_title' => (string) $row['indexed_title'],
            'sha256' => (string) $row['sha256'],
        ];
    }
    return $sources;
}

/** Detect a legacy source withheld until its gallery snapshot is reconciled.
 * @param int $songId Stable normalized song identity.
 * @return bool Whether a dirty gallery owns an active legacy candidate.
 */
function music_repository_model_song_has_dirty_sources(int $songId): bool
{
    // LIMIT 1 expresses existence; no source list or filesystem scan is needed.
    $statement = db()->prepare(
        'SELECT 1 FROM music_track_instances i'
            . ' JOIN music_catalog_dirty_galleries d ON d.gallery_id = i.gallery_id'
            . ' JOIN galleries g ON g.id = i.gallery_id'
            . ' WHERE i.song_id = ? AND i.active = 1 AND i.asset_id IS NULL'
            . ' LIMIT 1',
    );
    $statement->execute([$songId]);
    return $statement->fetchColumn() !== false;
}

/** Bind one stable gallery track to a previously registered repository asset.
 * @param int $galleryId Physical gallery identity.
 * @param string $trackId Stable gallery-scoped track identity.
 * @param string $assetId Independent central repository asset identity.
 * @return void Update the existing instance to the asset's logical song.
 * @throws \RuntimeException When the asset or physical instance is missing.
 */
function music_repository_model_link_track(int $galleryId, string $trackId, string $assetId): void
{
    $statement = db()->prepare('SELECT song_id, sha256 FROM music_repository_assets WHERE id = ? FOR UPDATE');
    $statement->execute([$assetId]);
    $asset = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($asset)) {
        throw new \RuntimeException('music.repository_conflict');
    }
    $update = db()->prepare('UPDATE music_track_instances SET song_id = ?, asset_id = ?, updated_at = NOW() WHERE gallery_id = ? AND track_id = ? AND file_sha256 = ?');
    $update->execute([(int) $asset['song_id'], $assetId, $galleryId, $trackId, $asset['sha256']]);
    if ($update->rowCount() === 0) {
        $check = db()->prepare('SELECT 1 FROM music_track_instances WHERE gallery_id = ? AND track_id = ? AND song_id = ? AND asset_id = ? AND file_sha256 = ?');
        $check->execute([$galleryId, $trackId, (int) $asset['song_id'], $assetId, $asset['sha256']]);
        if ($check->fetchColumn() === false) {
            throw new \RuntimeException('music.repository_conflict');
        }
    }
}

/** Replace one gallery's derived live-album assignment snapshot.
 * @param int $galleryId Gallery whose authoritative sidecar was committed.
 * @param list<array{id:string,album_id:int,hidden:bool,downloadable:bool}> $links Normalized live album references.
 * @return void Store references only; playlist JSON remains authoritative.
 */
function music_repository_model_sync_album_links(int $galleryId, array $links): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM music_gallery_album_links WHERE gallery_id = ?')->execute([$galleryId]);
    $insert = $pdo->prepare('INSERT INTO music_gallery_album_links (gallery_id, assignment_id, album_id, updated_at) VALUES (?, ?, ?, NOW())');
    $exists = $pdo->prepare('SELECT 1 FROM music_albums WHERE id = ?');
    foreach ($links as $link) {
        $exists->execute([$link['album_id']]);
        if ($exists->fetchColumn() === false) {
            continue;
        }
        $insert->execute([$galleryId, $link['id'], $link['album_id']]);
    }
}

/** Read local song identities for selected physical playlist tracks.
 * @param int $galleryId Physical gallery whose current playlist was read.
 * @param list<string> $trackIds Current authoritative playlist IDs.
 * @return array<string,int> Known local song identities keyed by track ID.
 */
function music_catalog_model_trash_track_refs(int $galleryId, array $trackIds): array
{
    if ($trackIds === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($trackIds), '?'));
    $statement = db()->prepare("SELECT track_id, song_id FROM music_track_instances WHERE gallery_id = ? AND active = 1 AND track_id IN ($marks)");
    $statement->execute(array_merge([$galleryId], $trackIds));
    $refs = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $refs[(string) $row['track_id']] = (int) $row['song_id'];
    }
    return $refs;
}

/** Restore trusted local song links after newly allocated gallery IDs exist.
 * @param int $galleryId Recreated physical gallery identity.
 * @param array<string,int> $trackRefs Saved local song IDs keyed by physical track ID.
 * @return void Rebind only links whose song rows still exist.
 */
function music_catalog_model_restore_trash_track_refs(int $galleryId, array $trackRefs): void
{
    foreach ($trackRefs as $trackId => $songId) {
        if ($songId <= 0) {
            continue;
        }
        db()->prepare('UPDATE music_track_instances i JOIN music_songs s ON s.id = ? SET i.song_id = ? WHERE i.gallery_id = ? AND i.track_id = ? AND i.asset_id IS NULL')
            ->execute([$songId, $songId, $galleryId, $trackId]);
    }
}

/** Run one catalog write atomically with its expected global revision.
 * @param string $revision Expected decimal catalog revision.
 * @param Closure():int|void $mutation Model-owned write callback on the active transaction.
 * @return array{revision:string,value:int|null} Committed revision and optional integer result.
 * @throws \RuntimeException When the revision is stale or the mutation fails.
 */
function music_catalog_model_transaction(string $revision, Closure $mutation): array
{
    db()->beginTransaction();
    try {
        $advance = db()->prepare("UPDATE music_catalog_state SET revision = revision + 1, updated_at = NOW() WHERE state_key = 'catalog' AND revision = ?");
        $advance->execute([(int) $revision]);
        if ($advance->rowCount() !== 1) {
            throw new \RuntimeException('music.conflict');
        }
        $value = $mutation();
        $next = music_catalog_model_revision();
        db()->commit();
        return ['revision' => $next, 'value' => $value];
    } catch (\Throwable $error) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $error;
    }
}

/** Read direct physical gallery and album usage for one song.
 * @param int $songId Stable normalized song identity.
 * @param bool $repositoryReady Whether live gallery album links are available.
 * @return array{direct_galleries:list<array{gallery_id:int,title:string,track_count:int|string}>,albums:list<array{album_id:int,title:string,track_count:int|string}>,live_albums:list<array{gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>} Physical, global album and live gallery album usage.
 */
function music_catalog_model_usage(int $songId, bool $repositoryReady = false): array
{
    $galleries = db()->prepare('SELECT g.id AS gallery_id, g.title, COUNT(*) AS track_count FROM music_track_instances i JOIN galleries g ON g.id = i.gallery_id WHERE i.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id) AND i.song_id = ? GROUP BY g.id, g.title ORDER BY g.title, g.id');
    $galleries->execute([$songId]);
    $albums = db()->prepare('SELECT a.id AS album_id, a.title, COUNT(*) AS track_count FROM music_album_songs x JOIN music_albums a ON a.id = x.album_id WHERE x.song_id = ? GROUP BY a.id, a.title ORDER BY a.title, a.id');
    $albums->execute([$songId]);
    $liveRows = [];
    if ($repositoryReady) {
        $live = db()->prepare('SELECT l.gallery_id, g.title AS gallery_title, a.id AS album_id, a.title AS album_title, COUNT(*) AS track_count FROM music_gallery_album_links l JOIN galleries g ON g.id = l.gallery_id JOIN music_albums a ON a.id = l.album_id JOIN music_album_songs m ON m.album_id = a.id WHERE m.song_id = ? AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = l.gallery_id) GROUP BY l.gallery_id, g.title, a.id, a.title ORDER BY g.title, a.title, l.gallery_id, a.id');
        $live->execute([$songId]);
        $liveRows = $live->fetchAll(PDO::FETCH_ASSOC);
    }
    return [
        'direct_galleries' => $galleries->fetchAll(PDO::FETCH_ASSOC),
        'albums' => $albums->fetchAll(PDO::FETCH_ASSOC),
        'live_albums' => $liveRows,
    ];
}

/** Preview selected identities and their usage without changing them.
 * @param list<int> $songIds Distinct positive identities selected for merge.
 * @param int $survivorId Identity whose custom title survives.
 * @param bool $repositoryReady Whether repository-only title metadata is available.
 * @return array{survivor_id:int,songs:list<array{id:int,title:?string,indexed_title:?string,filename:?string,track_count:int|string,album_count:int|string}>} Existing selected rows.
 */
function music_catalog_model_merge_preview(array $songIds, int $survivorId, bool $repositoryReady = false): array
{
    $marks = implode(',', array_fill(0, count($songIds), '?'));
    $assetTitle = $repositoryReady
        ? '(SELECT MIN(a.indexed_title) FROM music_repository_assets a WHERE a.song_id = s.id)'
        : 'NULL';
    $assetFilename = $repositoryReady
        ? '(SELECT MIN(a.filename) FROM music_repository_assets a WHERE a.song_id = s.id)'
        : 'NULL';
    $legacyCount = $repositoryReady
        ? "(SELECT COUNT(*) FROM music_track_instances i WHERE i.song_id = s.id AND i.active = 1 AND i.asset_id IS NULL AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id) AND NOT EXISTS (SELECT 1 FROM music_repository_assets r WHERE r.origin_gallery_id = i.gallery_id AND r.origin_track_id = i.track_id))"
        : '(SELECT COUNT(*) FROM music_track_instances i WHERE i.song_id = s.id AND i.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id))';
    $assetCount = $repositoryReady
        ? '(SELECT COUNT(*) FROM music_repository_assets r WHERE r.song_id = s.id)'
        : '0';
    $statement = db()->prepare("SELECT s.id, s.title, COALESCE((SELECT MIN(i.indexed_title) FROM music_track_instances i WHERE i.song_id = s.id AND i.active = 1), $assetTitle) AS indexed_title, COALESCE((SELECT MIN(i.filename) FROM music_track_instances i WHERE i.song_id = s.id AND i.active = 1), $assetFilename) AS filename, ($legacyCount + $assetCount) AS track_count, (SELECT COUNT(*) FROM music_album_songs a WHERE a.song_id = s.id) AS album_count FROM music_songs s WHERE s.id IN ($marks) ORDER BY s.id");
    $statement->execute($songIds);
    return ['survivor_id' => $survivorId, 'songs' => $statement->fetchAll(PDO::FETCH_ASSOC)];
}

/** Move references, album membership and tags to the selected survivor.
 * @param list<int> $songIds Valid selected song IDs including the survivor.
 * @param int $survivorId Existing song that retains its canonical title.
 * @param string $tagPolicy Either union all selected tags or keep survivor tags.
 * @param bool $repositoryReady Whether repository assets must be retargeted.
 * @return void The caller owns the surrounding revision transaction.
 */
function music_catalog_model_merge(array $songIds, int $survivorId, string $tagPolicy, bool $repositoryReady = false): void
{
    $others = array_values(array_filter($songIds, static fn(int $id): bool => $id !== $survivorId));
    if ($others === []) {
        return;
    }
    $marks = implode(',', array_fill(0, count($others), '?'));
    db()->prepare("UPDATE music_track_instances SET song_id = ? WHERE song_id IN ($marks)")->execute(array_merge([$survivorId], $others));
    if ($repositoryReady) {
        db()->prepare("UPDATE music_repository_assets SET song_id = ?, updated_at = NOW() WHERE song_id IN ($marks)")->execute(array_merge([$survivorId], $others));
    }
    db()->prepare("INSERT IGNORE INTO music_album_songs (album_id, song_id, sort_order) SELECT album_id, ?, sort_order FROM music_album_songs WHERE song_id IN ($marks)")->execute(array_merge([$survivorId], $others));
    db()->prepare("DELETE FROM music_album_songs WHERE song_id IN ($marks)")->execute($others);
    if ($tagPolicy === 'union') {
        db()->prepare("INSERT IGNORE INTO music_song_tags (song_id, tag_id) SELECT ?, tag_id FROM music_song_tags WHERE song_id IN ($marks)")->execute(array_merge([$survivorId], $others));
    }
    db()->prepare("DELETE FROM music_song_tags WHERE song_id IN ($marks)")->execute($others);
    db()->prepare("DELETE FROM music_songs WHERE id IN ($marks)")->execute($others);
}

/** Return ordered album rows joined to their member IDs.
 * @param int $limit Maximum album rows; caller requests one sentinel row above policy.
 * @return list<array{id:int,parent_id:int|null,title:string,description:string|null,sort_order:int,revision:int,song_count:int|string}> Flat hierarchy rows with bounded membership counts.
 */
function music_catalog_model_albums(int $limit): array
{
    $statement = db()->prepare('SELECT a.id, a.parent_id, a.title, a.description, a.sort_order, a.revision, (SELECT COUNT(*) FROM music_album_songs x WHERE x.album_id = a.id) AS song_count FROM music_albums a ORDER BY a.parent_id, a.sort_order, a.id LIMIT ?');
    $statement->bindValue(1, $limit, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** Read a bounded batch of album nodes needed for assigned-album breadcrumbs.
 * @param list<int> $albumIds Distinct positive album IDs, capped by the gallery source policy.
 * @return list<array{id:int,parent_id:int|null,title:string,song_count:int}> Existing requested or ancestor nodes with member counts.
 */
function music_catalog_model_album_nodes(array $albumIds): array
{
    if ($albumIds === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($albumIds), '?'));
    $statement = db()->prepare("SELECT a.id, a.parent_id, a.title, (SELECT COUNT(*) FROM music_album_songs m WHERE m.album_id = a.id) AS song_count FROM music_albums a WHERE a.id IN ($marks)");
    $statement->execute($albumIds);
    $rows = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'id' => (int) $row['id'],
            'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'title' => (string) $row['title'],
            'song_count' => (int) $row['song_count'],
        ];
    }
    return $rows;
}

/** Return one song's active physical track references.
 * @param int $songId Stable normalized song identity.
 * @param bool $repositoryReady Whether the optional asset_id column exists.
 * @return list<array{gallery_id:int,track_id:string,filename:string,asset_id:string|null,title:?string}> Active physical instances.
 */
function music_catalog_model_song_tracks(int $songId, bool $repositoryReady = false): array
{
    $assetColumn = $repositoryReady ? 'i.asset_id' : 'NULL AS asset_id';
    $statement = db()->prepare('SELECT i.gallery_id, i.track_id, i.filename, ' . $assetColumn . ', s.title FROM music_track_instances i JOIN music_songs s ON s.id = i.song_id WHERE s.id = ? AND i.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id) ORDER BY i.gallery_id, i.track_id');
    $statement->execute([$songId]);
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** Return the ordered song identities in one album.
 * @param int $albumId Stable album identity.
 * @return list<int> Song IDs ordered by membership position.
 */
function music_catalog_model_album_songs(int $albumId): array
{
    $statement = db()->prepare('SELECT song_id FROM music_album_songs WHERE album_id = ? ORDER BY sort_order, song_id');
    $statement->execute([$albumId]);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/** Check membership before deciding whether a prospective album add grows playlists.
 * @param int $albumId Stable album identity.
 * @param int $songId Stable logical song identity.
 * @return bool True when the album already contains the song.
 */
function music_catalog_model_album_has_song(int $albumId, int $songId): bool
{
    $statement = db()->prepare('SELECT 1 FROM music_album_songs WHERE album_id = ? AND song_id = ?');
    $statement->execute([$albumId, $songId]);
    return $statement->fetchColumn() !== false;
}

/** Check that a prospective song assignment names an existing album.
 * @param int $albumId Stable album identity.
 * @return bool True when the album row exists.
 */
function music_catalog_model_album_exists(int $albumId): bool
{
    $statement = db()->prepare('SELECT 1 FROM music_albums WHERE id = ?');
    $statement->execute([$albumId]);
    return $statement->fetchColumn() !== false;
}

/** List every live gallery assignment and its occurrence count for one album.
 * @param int $albumId Stable globally shared album identity.
 * @return list<array{gallery_id:int,folder_path:string,occurrences:int}> Galleries linked through one or more derived assignments.
 */
function music_catalog_model_album_assignment_galleries(int $albumId): array
{
    $statement = db()->prepare('SELECT g.id AS gallery_id, g.folder_path, COUNT(*) AS occurrences FROM music_gallery_album_links l JOIN galleries g ON g.id = l.gallery_id WHERE l.album_id = ? GROUP BY g.id, g.folder_path ORDER BY g.id');
    $statement->execute([$albumId]);
    $galleries = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $galleries[] = [
            'gallery_id' => (int) $row['gallery_id'],
            'folder_path' => (string) $row['folder_path'],
            'occurrences' => (int) $row['occurrences'],
        ];
    }
    return $galleries;
}

/** Return one bounded album member page with title source components.
 * @param int $albumId Stable album identity.
 * @param int $limit Maximum member rows.
 * @param int $offset Zero-based member offset.
 * @param bool $repositoryReady Whether repository-only titles are available.
 * @return list<array{id:int,membership_order:int,custom_title:?string,indexed_title:?string,filename:?string}> Member title components in stored order.
 */
function music_catalog_model_album_members(int $albumId, int $limit, int $offset, bool $repositoryReady = false): array
{
    $assetJoin = $repositoryReady
        ? ' LEFT JOIN music_repository_assets r ON r.song_id = s.id'
        : '';
    $assetTitle = $repositoryReady ? 'MIN(r.indexed_title)' : 'NULL';
    $assetFilename = $repositoryReady ? 'MIN(r.filename)' : 'NULL';
    $statement = db()->prepare('SELECT s.id, m.sort_order AS membership_order, s.title AS custom_title, COALESCE(MIN(i.indexed_title), ' . $assetTitle . ') AS indexed_title, COALESCE(MIN(i.filename), ' . $assetFilename . ') AS filename FROM music_album_songs m JOIN music_songs s ON s.id = m.song_id LEFT JOIN music_track_instances i ON i.song_id = s.id AND i.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id)' . $assetJoin . ' WHERE m.album_id = ? GROUP BY s.id, s.title, m.sort_order ORDER BY m.sort_order, s.id LIMIT ? OFFSET ?');
    $statement->bindValue(1, $albumId, PDO::PARAM_INT);
    $statement->bindValue(2, $limit, PDO::PARAM_INT);
    $statement->bindValue(3, $offset, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** Return a bounded gallery identity page for filesystem reconciliation.
 * @param int $afterGalleryId Exclusive cursor from the previous page.
 * @param int $limit Maximum gallery rows.
 * @param bool $trashSchemaAvailable True when gallery trash path state is verified.
 * @return list<array{id:int,folder_path:string}> Physical gallery identities and paths.
 */
function music_catalog_model_galleries(int $afterGalleryId, int $limit, bool $trashSchemaAvailable): array
{
    $sql = 'SELECT g.id, g.folder_path FROM galleries g WHERE g.id > ?';
    if ($trashSchemaAvailable) {
        $sql .= " AND NOT EXISTS (SELECT 1 FROM gallery_trash_entries t WHERE t.status IN ('preparing', 'trashed', 'restoring', 'purging', 'broken') AND (t.original_folder_path = g.folder_path OR g.folder_path LIKE CONCAT(t.original_folder_path, '/%')))";
    }
    $statement = db()->prepare($sql . ' ORDER BY g.id LIMIT ?');
    $statement->bindValue(1, $afterGalleryId, PDO::PARAM_INT);
    $statement->bindValue(2, $limit, PDO::PARAM_INT);
    $statement->execute();
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

/** Mark absent physical references inactive after a complete index read.
 * @param int $galleryId Physical gallery whose index was read successfully.
 * @param list<string> $trackIds Active track IDs found in that index.
 * @return void Preserve inactive rows so a restored track keeps its identity.
 */
function music_catalog_model_prune_gallery(int $galleryId, array $trackIds): void
{
    if ($trackIds === []) {
        db()->prepare('UPDATE music_track_instances SET active = 0 WHERE gallery_id = ?')->execute([$galleryId]);
        return;
    }
    $marks = implode(',', array_fill(0, count($trackIds), '?'));
    db()->prepare("UPDATE music_track_instances SET active = 0 WHERE gallery_id = ? AND track_id NOT IN ($marks)")->execute(array_merge([$galleryId], $trackIds));
}

/** Delete bounded orphan identities inside the caller's catalog transaction.
 * A repository row preserves a song even if its file is missing/unreadable.
 * Dirty gallery snapshots cannot prove absence and also preserve the identity.
 * @param int $limit Service-validated positive song deletion bound.
 * @return int Actual removed songs; existing foreign keys remove only their
 *     inactive track metadata, album memberships and song-tag links.
 * @throws \PDOException When selection, locking or deletion fails.
 */
function music_catalog_model_prune_unbacked_songs(int $limit): int
{
    $predicate = 'NOT EXISTS (SELECT 1 FROM music_repository_assets a'
        . ' WHERE a.song_id = music_songs.id)'
        . ' AND NOT EXISTS (SELECT 1 FROM music_track_instances i'
        . ' LEFT JOIN music_catalog_dirty_galleries d'
        . ' ON d.gallery_id = i.gallery_id'
        . ' WHERE i.song_id = music_songs.id'
        . ' AND (i.active = 1 OR d.gallery_id IS NOT NULL))';
    $select = db()->prepare(
        'SELECT id FROM music_songs WHERE ' . $predicate
            . ' ORDER BY id LIMIT ? FOR UPDATE',
    );
    $select->bindValue(1, $limit, PDO::PARAM_INT);
    $select->execute();
    $ids = array_map('intval', $select->fetchAll(PDO::FETCH_COLUMN));
    if ($ids === []) {
        return 0;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    // Recheck all guards at deletion; repository FKs additionally refuse a
    // concurrent asset registration rather than deleting its logical owner.
    $delete = db()->prepare(
        'DELETE FROM music_songs WHERE id IN (' . $marks . ') AND '
            . $predicate,
    );
    $delete->execute($ids);
    return $delete->rowCount();
}

/** Record one committed physical playlist change for later reconciliation.
 * @param int $galleryId Physical gallery whose playlist changed.
 * @return void Upsert its dirty marker.
 */
function music_catalog_model_mark_gallery_dirty(int $galleryId): void
{
    db()->prepare('INSERT INTO music_catalog_dirty_galleries (gallery_id, changed_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE changed_at = VALUES(changed_at)')->execute([$galleryId]);
}

/** Clear a dirty marker after successful reconciliation.
 * @param int $galleryId Physical gallery whose index was reconciled.
 * @return void Delete the marker inside the catalog transaction.
 */
function music_catalog_model_clear_gallery_dirty(int $galleryId): void
{
    db()->prepare('DELETE FROM music_catalog_dirty_galleries WHERE gallery_id = ?')->execute([$galleryId]);
}

/** Count galleries whose catalog snapshot still needs reconciliation.
 * @return int Number of durable pending gallery snapshots.
 */
function music_catalog_model_dirty_gallery_count(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM music_catalog_dirty_galleries')->fetchColumn();
}

/**
 * Read pending and explicitly affected music sources before catalog mutation.
 * @param int $limit Maximum admitted resource IDs, plus an overflow sentinel.
 * @param array{galleries?:list<int>,albums?:list<int>,songs?:list<int>} $scope
 *     Semantic gallery, album or song identities changed by this operation.
 * @param bool $repositoryReady Whether live repository/album links are verified.
 * @return list<int> Pending or explicitly affected physical source identities.
 */
function music_catalog_model_visit_dirty_sources(
    int $limit,
    array $scope = [],
    bool $repositoryReady = false,
): array
{
    $queries = ['SELECT gallery_id FROM music_catalog_dirty_galleries'];
    $values = [];
    foreach (['galleries', 'albums', 'songs'] as $kind) {
        $ids = array_values(array_unique($scope[$kind] ?? []));
        if ($ids === [] || ($kind === 'albums' && !$repositoryReady)) {
            continue;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $queries[] = match ($kind) {
            'galleries' => 'SELECT id AS gallery_id FROM galleries '
                . 'WHERE id IN (' . $marks . ')',
            'albums' => 'SELECT gallery_id FROM music_gallery_album_links '
                . 'WHERE album_id IN (' . $marks . ')',
            'songs' => 'SELECT gallery_id FROM music_track_instances '
                . 'WHERE song_id IN (' . $marks . ')',
        };
        array_push($values, ...$ids);
        if ($kind === 'songs' && $repositoryReady) {
            $queries[] = 'SELECT l.gallery_id FROM music_gallery_album_links l '
                . 'JOIN music_album_songs m ON m.album_id=l.album_id '
                . 'WHERE m.song_id IN (' . $marks . ')';
            array_push($values, ...$ids);
        }
    }
    $statement = db()->prepare('SELECT DISTINCT gallery_id FROM ('
        . implode(' UNION ALL ', $queries) . ') visit_sources '
        . 'ORDER BY gallery_id LIMIT ' . ($limit + 1));
    $statement->execute($values);
    return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/** Read normalized song identity and title data for physical references.
 * @param list<array{gallery_id:int,track_id:string}> $references Gallery-scoped IDs, bounded by the caller.
 * @return array<string,array{song_id:int,custom:string,indexed:string}> Rows keyed by gallery ID and track ID.
 */
function music_catalog_model_titles(array $references): array
{
    if ($references === []) {
        return [];
    }
    $conditions = [];
    $values = [];
    foreach ($references as $reference) {
        $conditions[] = '(i.gallery_id = ? AND i.track_id = ?)';
        $values[] = $reference['gallery_id'];
        $values[] = $reference['track_id'];
    }
    $statement = db()->prepare('SELECT i.gallery_id, i.track_id, i.song_id, s.title, i.indexed_title FROM music_track_instances i JOIN music_songs s ON s.id = i.song_id WHERE i.active = 1 AND (' . implode(' OR ', $conditions) . ')');
    $statement->execute($values);
    $titles = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $titles[$row['gallery_id'] . ':' . $row['track_id']] = [
            'song_id' => (int) $row['song_id'],
            'custom' => (string) ($row['title'] ?? ''),
            'indexed' => (string) $row['indexed_title'],
        ];
    }
    return $titles;
}

/** Update or clear one song's canonical title override.
 * @param int $songId Stable normalized song identity.
 * @param string $title Exact override text, or empty to clear it.
 * @return void Persist the title without trimming user-entered text.
 */
function music_catalog_model_set_title(int $songId, string $title): void
{
    db()->prepare('UPDATE music_songs SET title = ?, updated_at = NOW() WHERE id = ?')->execute([$title === '' ? null : $title, $songId]);
}

/** Create one album under its validated parent.
 * @param string $title Album label.
 * @param int|null $parentId Parent album, or null for a root.
 * @param string $description Optional plain-text description.
 * @param int $maximumAlbums Maximum global album count enforced in transaction.
 * @return int Newly allocated album identity.
 */
function music_catalog_model_album_create(string $title, ?int $parentId, string $description, int $maximumAlbums): int
{
    $count = (int) db()->query('SELECT COUNT(*) FROM music_albums')->fetchColumn();
    if ($count >= $maximumAlbums) {
        throw new \RuntimeException('music.album_limit');
    }
    $order = db()->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM music_albums WHERE parent_id <=> ?');
    $order->execute([$parentId]);
    db()->prepare('INSERT INTO music_albums (parent_id, title, description, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())')->execute([$parentId, $title, $description, (int) $order->fetchColumn()]);
    return (int) db()->lastInsertId();
}

/** Apply one album operation from the fixed domain vocabulary.
 * @param string $operation update, move, delete, song_add or song_remove.
 * @param int $albumId Target album identity.
 * @param int|null $parentId Parent identity or song identity for membership operations.
 * @param int|null $position Requested order for move/add; null appends a new
 *   song membership without moving an existing one. Other operations use int.
 * @param string $title Title for update, otherwise ignored.
 * @param string $description Description for update, otherwise ignored.
 * @return void Execute only the selected model-owned SQL operation.
 * @throws \InvalidArgumentException When append is used for another operation.
 */
function music_catalog_model_album_mutate(
    string $operation,
    int $albumId,
    ?int $parentId,
    ?int $position,
    string $title,
    string $description = '',
): void
{
    if ($position === null && $operation !== 'song_add') {
        throw new \InvalidArgumentException('Append requires a song membership.');
    }
    if ($operation === 'update') {
        db()->prepare('UPDATE music_albums SET title = ?, description = ?, revision = revision + 1, updated_at = NOW() WHERE id = ?')->execute([$title, $description, $albumId]);
    } elseif ($operation === 'move') {
        $current = db()->prepare('SELECT parent_id FROM music_albums WHERE id = ? FOR UPDATE');
        $current->execute([$albumId]);
        $oldParent = $current->fetchColumn();
        if ($oldParent === false) {
            throw new \RuntimeException('music.album_missing');
        }
        $siblings = db()->prepare('SELECT id FROM music_albums WHERE parent_id <=> ? AND id <> ? ORDER BY sort_order, id FOR UPDATE');
        $siblings->execute([$parentId, $albumId]);
        $newIds = array_map('intval', $siblings->fetchAll(PDO::FETCH_COLUMN));
        array_splice($newIds, min($position, count($newIds)), 0, [$albumId]);
        if ($oldParent !== null && $oldParent !== false) {
            $oldSiblings = db()->prepare('SELECT id FROM music_albums WHERE parent_id <=> ? AND id <> ? ORDER BY sort_order, id FOR UPDATE');
            $oldSiblings->execute([(int) $oldParent, $albumId]);
            music_catalog_model_reorder_albums(array_map('intval', $oldSiblings->fetchAll(PDO::FETCH_COLUMN)), (int) $oldParent);
        } elseif ($oldParent === null) {
            $oldSiblings = db()->prepare('SELECT id FROM music_albums WHERE parent_id IS NULL AND id <> ? ORDER BY sort_order, id FOR UPDATE');
            $oldSiblings->execute([$albumId]);
            music_catalog_model_reorder_albums(array_map('intval', $oldSiblings->fetchAll(PDO::FETCH_COLUMN)), null);
        }
        db()->prepare('UPDATE music_albums SET parent_id = ?, revision = revision + 1, updated_at = NOW() WHERE id = ?')->execute([$parentId, $albumId]);
        music_catalog_model_reorder_albums($newIds, $parentId);
    } elseif ($operation === 'delete') {
        db()->prepare('DELETE FROM music_albums WHERE id = ?')->execute([$albumId]);
    } elseif ($operation === 'song_add') {
        $members = db()->prepare('SELECT song_id FROM music_album_songs WHERE album_id = ? ORDER BY sort_order, song_id FOR UPDATE');
        $members->execute([$albumId]);
        $songIds = array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN));
        // Contextual Add must preserve the order of an existing membership.
        // The catalog revision lock and these row locks also serialize appends.
        if ($position === null && in_array((int) $parentId, $songIds, true)) {
            return;
        }
        $songIds = array_values(array_filter(
            $songIds,
            static fn(int $songId): bool => $songId !== (int) $parentId,
        ));
        $targetPosition = $position ?? count($songIds);
        array_splice(
            $songIds,
            min($targetPosition, count($songIds)),
            0,
            [(int) $parentId],
        );
        foreach ($songIds as $index => $songId) {
            db()->prepare('INSERT INTO music_album_songs (album_id, song_id, sort_order) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)')->execute([$albumId, $songId, $index]);
        }
    } elseif ($operation === 'song_remove') {
        db()->prepare('DELETE FROM music_album_songs WHERE album_id = ? AND song_id = ?')->execute([$albumId, $parentId]);
        $members = db()->prepare('SELECT song_id FROM music_album_songs WHERE album_id = ? ORDER BY sort_order, song_id FOR UPDATE');
        $members->execute([$albumId]);
        foreach (array_map('intval', $members->fetchAll(PDO::FETCH_COLUMN)) as $index => $songId) {
            db()->prepare('UPDATE music_album_songs SET sort_order = ? WHERE album_id = ? AND song_id = ?')->execute([$index, $albumId, $songId]);
        }
    }
}

/** Persist contiguous sibling order for one parent album.
 * @param list<int> $albumIds Sibling identities in their requested order.
 * @param int|null $parentId Parent identity, or null for root albums.
 * @return void Update each sibling's stable sort position.
 */
function music_catalog_model_reorder_albums(array $albumIds, ?int $parentId): void
{
    foreach ($albumIds as $position => $albumId) {
        db()->prepare('UPDATE music_albums SET parent_id = ?, sort_order = ? WHERE id = ?')->execute([$parentId, $position, $albumId]);
    }
}

/** Move selected references to a new identity and copy title metadata.
 * @param int $songId Existing source identity whose tags and albums are copied.
 * @param list<array{gallery_id:int,track_id:string,filename:string,asset_id?:string|null,title:?string}> $tracks Exact active physical references to move.
 * @param bool $repositoryReady Whether immutable assets must move with all references.
 * @return int Newly allocated song identity.
 */
function music_catalog_model_split(int $songId, array $tracks, bool $repositoryReady = false): int
{
    $assetSelections = [];
    foreach ($tracks as $track) {
        if (!empty($track['asset_id'])) {
            $assetSelections[$track['asset_id']] = ($assetSelections[$track['asset_id']] ?? 0) + 1;
        }
    }
    foreach ($assetSelections as $assetId => $selectedCount) {
        if (!$repositoryReady) {
            throw new \RuntimeException('music.repository_conflict');
        }
        $references = db()->prepare('SELECT COUNT(*) FROM music_track_instances WHERE asset_id = ?');
        $references->execute([$assetId]);
        if ((int) $references->fetchColumn() !== $selectedCount) {
            throw new \RuntimeException('music.repository_split_shared_asset');
        }
    }
    $first = $tracks[0];
    db()->prepare('INSERT INTO music_songs (title, created_at, updated_at) VALUES (?, NOW(), NOW())')->execute([$first['title']]);
    $newId = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO music_song_tags (song_id, tag_id) SELECT ?, tag_id FROM music_song_tags WHERE song_id = ?')->execute([$newId, $songId]);
    db()->prepare('INSERT INTO music_album_songs (album_id, song_id, sort_order) SELECT album_id, ?, sort_order FROM music_album_songs WHERE song_id = ?')->execute([$newId, $songId]);
    foreach ($tracks as $track) {
        db()->prepare('UPDATE music_track_instances SET song_id = ? WHERE gallery_id = ? AND track_id = ?')->execute([$newId, $track['gallery_id'], $track['track_id']]);
        if (!empty($track['asset_id'])) {
            db()->prepare('UPDATE music_repository_assets SET song_id = ?, updated_at = NOW() WHERE id = ?')->execute([$newId, $track['asset_id']]);
        }
    }
    return $newId;
}

/** Read track, gallery and distinct usage projections for one bounded batch.
 * @param list<int> $songIds Positive normalized identities.
 * @param bool $repositoryReady Whether live gallery album links are available.
 * @return array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string,asset_id:string|null}>,repository_assets:list<array{id:string,filename:string,byte_size:int,sha256:string,origin_gallery_id:int|null,origin_track_id:string|null}>,direct_galleries:list<array{song_id:int,gallery_id:int,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int,title:string,track_count:int|string}>,live_albums:list<array{song_id:int,gallery_id:int,gallery_title:string,album_id:int,album_title:string,track_count:int|string}>,galleries:array<int,array{id:int|string,url_path:string|null,slug:string|null,folder_path:string|null,title:string|null}>}> Details keyed by song ID, including existing gallery rows for every physical or repository origin reference.
 */
function music_catalog_model_song_details(array $songIds, bool $repositoryReady = false): array
{
    if ($songIds === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($songIds), '?'));
    $details = [];
    foreach ($songIds as $songId) {
        $details[$songId] = [
            'tracks' => [],
            'repository_assets' => [],
            'direct_galleries' => [],
            'albums' => [],
            'live_albums' => [],
            'galleries' => [],
        ];
    }
    $assetColumn = $repositoryReady ? 'asset_id' : 'NULL AS asset_id';
    $tracks = db()->prepare("SELECT song_id, gallery_id, track_id, filename, $assetColumn FROM music_track_instances i WHERE active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id) AND song_id IN ($marks) ORDER BY gallery_id, track_id");
    $tracks->execute($songIds);
    foreach ($tracks->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details[(int) $row['song_id']]['tracks'][] = [
            'gallery_id' => (int) $row['gallery_id'],
            'track_id' => $row['track_id'],
            'filename' => $row['filename'],
            'asset_id' => $row['asset_id'] === null ? null : (string) $row['asset_id'],
        ];
    }
    if ($repositoryReady) {
        $assets = db()->prepare("SELECT id, song_id, filename, byte_size, sha256, origin_gallery_id, origin_track_id FROM music_repository_assets WHERE song_id IN ($marks) ORDER BY id");
        $assets->execute($songIds);
        foreach ($assets->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $details[(int) $row['song_id']]['repository_assets'][] = [
                'id' => (string) $row['id'],
                'filename' => (string) $row['filename'],
                'byte_size' => (int) $row['byte_size'],
                'sha256' => (string) $row['sha256'],
                'origin_gallery_id' => $row['origin_gallery_id'] === null
                    ? null
                    : (int) $row['origin_gallery_id'],
                'origin_track_id' => $row['origin_track_id'] === null
                    ? null
                    : (string) $row['origin_track_id'],
            ];
        }
    }
    $galleries = db()->prepare("SELECT i.song_id, g.id AS gallery_id, g.title, COUNT(*) AS track_count FROM music_track_instances i JOIN galleries g ON g.id = i.gallery_id WHERE i.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id) AND i.song_id IN ($marks) GROUP BY i.song_id, g.id, g.title ORDER BY g.title, g.id");
    $galleries->execute($songIds);
    foreach ($galleries->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details[(int) $row['song_id']]['direct_galleries'][] = $row;
    }
    $albums = db()->prepare("SELECT x.song_id, a.id AS album_id, a.title, COUNT(*) AS track_count FROM music_album_songs x JOIN music_albums a ON a.id = x.album_id WHERE x.song_id IN ($marks) GROUP BY x.song_id, a.id, a.title ORDER BY a.title, a.id");
    $albums->execute($songIds);
    foreach ($albums->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details[(int) $row['song_id']]['albums'][] = $row;
    }
    if ($repositoryReady) {
        $live = db()->prepare("SELECT m.song_id, l.gallery_id, g.title AS gallery_title, a.id AS album_id, a.title AS album_title, COUNT(*) AS track_count FROM music_gallery_album_links l JOIN galleries g ON g.id = l.gallery_id JOIN music_albums a ON a.id = l.album_id JOIN music_album_songs m ON m.album_id = a.id WHERE NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = l.gallery_id) AND m.song_id IN ($marks) GROUP BY m.song_id, l.gallery_id, g.title, a.id, a.title ORDER BY g.title, a.title, l.gallery_id, a.id");
        $live->execute($songIds);
        foreach ($live->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $details[(int) $row['song_id']]['live_albums'][] = $row;
        }
    }
    $galleryIds = [];
    $galleryIdsBySong = [];
    foreach ($details as $songId => $songDetail) {
        $songGalleryIds = [];
        foreach ($songDetail['tracks'] as $track) {
            $galleryId = (int) $track['gallery_id'];
            $galleryIds[$galleryId] = $galleryId;
            $songGalleryIds[$galleryId] = $galleryId;
        }
        foreach ($songDetail['direct_galleries'] as $gallery) {
            $galleryId = (int) $gallery['gallery_id'];
            $galleryIds[$galleryId] = $galleryId;
            $songGalleryIds[$galleryId] = $galleryId;
        }
        foreach ($songDetail['live_albums'] as $placement) {
            $galleryId = (int) $placement['gallery_id'];
            $galleryIds[$galleryId] = $galleryId;
            $songGalleryIds[$galleryId] = $galleryId;
        }
        foreach ($songDetail['repository_assets'] as $asset) {
            $originId = (int) ($asset['origin_gallery_id'] ?? 0);
            if ($originId > 0) {
                $galleryIds[$originId] = $originId;
                $songGalleryIds[$originId] = $originId;
            }
        }
        $galleryIdsBySong[(int) $songId] = $songGalleryIds;
    }
    if ($galleryIds !== []) {
        $galleryMarks = implode(',', array_fill(0, count($galleryIds), '?'));
        $galleryRows = db()->prepare(
            "SELECT id, url_path, slug, folder_path, title FROM galleries WHERE id IN ($galleryMarks)",
        );
        $galleryRows->execute(array_values($galleryIds));
        $galleriesById = [];
        foreach ($galleryRows->fetchAll(PDO::FETCH_ASSOC) as $galleryRow) {
            $galleriesById[(int) $galleryRow['id']] = $galleryRow;
        }
        foreach ($details as $songId => &$songDetail) {
            $songDetail['galleries'] = array_intersect_key(
                $galleriesById,
                $galleryIdsBySong[(int) $songId],
            );
        }
        unset($songDetail);
    }
    return $details;
}
