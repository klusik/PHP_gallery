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
): array {
    $orders = [
        'title' => '',
        'files' => 'c.track_count',
        'locations' => '(c.direct_gallery_count + c.album_count)',
        'tags' => '(SELECT MIN(t.name) FROM music_song_tags st '
            . 'JOIN tags t ON t.id = st.tag_id '
            . 'WHERE st.song_id = c.id AND t.media_type = "music")',
    ];
    if (!isset($orders[$sort]) || !in_array($direction, ['asc', 'desc'], true)) {
        throw new \InvalidArgumentException('Invalid music catalog ordering.');
    }
    $needle = '%' . $query . '%';
    $parameters = [$needle, $needle, $needle, $needle];
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
    $source =
        'SELECT s.id, s.title AS custom_title, (SELECT q.indexed_title FROM music_track_instances q WHERE q.song_id = s.id AND q.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = q.gallery_id) ORDER BY q.gallery_id, q.track_id LIMIT 1) AS indexed_title, (SELECT q.filename FROM music_track_instances q WHERE q.song_id = s.id AND q.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = q.gallery_id) ORDER BY q.gallery_id, q.track_id LIMIT 1) AS filename, COUNT(DISTINCT CONCAT(i.gallery_id, ":", i.track_id)) AS track_count, '
        . '(SELECT COUNT(*) FROM music_album_songs mas WHERE mas.song_id = s.id) AS album_count, '
        . 'COUNT(DISTINCT i.gallery_id) AS direct_gallery_count '
        . 'FROM music_songs s LEFT JOIN music_track_instances i ON i.song_id = s.id AND i.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id) '
        . 'WHERE COALESCE(s.title, "") LIKE ? OR EXISTS (SELECT 1 FROM music_track_instances q WHERE q.song_id = s.id AND q.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = q.gallery_id) AND (q.filename LIKE ? OR q.indexed_title LIKE ?)) OR EXISTS (SELECT 1 FROM music_song_tags st JOIN tags t ON t.id = st.tag_id WHERE st.song_id = s.id AND t.media_type = "music" AND t.name LIKE ?) GROUP BY s.id, s.title';
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
    $count = db()->prepare('SELECT COUNT(*) FROM music_songs s WHERE COALESCE(s.title, "") LIKE ? OR EXISTS (SELECT 1 FROM music_track_instances q WHERE q.song_id = s.id AND q.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = q.gallery_id) AND (q.filename LIKE ? OR q.indexed_title LIKE ?)) OR EXISTS (SELECT 1 FROM music_song_tags st JOIN tags t ON t.id = st.tag_id WHERE st.song_id = s.id AND t.media_type = "music" AND t.name LIKE ?)');
    $needle = '%' . $query . '%';
    $count->execute([$needle, $needle, $needle, $needle]);
    return ['revision' => music_catalog_model_revision(), 'songs' => $songs, 'total' => (int) $count->fetchColumn()];
}

/** Attach or refresh one gallery-scoped track reference.
 * @param array{gallery_id:int,track_id:string,title:string,filename:string,sha256:string} $track Indexed source metadata.
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
    $insert = db()->prepare('INSERT INTO music_songs (title, created_at, updated_at) VALUES (NULL, NOW(), NOW())');
    $insert->execute();
    $songId = (int) db()->lastInsertId();
    $attach = db()->prepare('INSERT INTO music_track_instances (gallery_id, track_id, song_id, filename, indexed_title, file_sha256, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $attach->execute([$track['gallery_id'], $track['track_id'], $songId, $track['filename'], $track['title'], $track['sha256']]);
    return $songId;
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
        db()->prepare('UPDATE music_track_instances i JOIN music_songs s ON s.id = ? SET i.song_id = ? WHERE i.gallery_id = ? AND i.track_id = ?')
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
 * @return array{direct_galleries:list<array{gallery_id:int,title:string,track_count:int|string}>,albums:list<array{album_id:int,title:string,track_count:int|string}>} Distinct usage rows.
 */
function music_catalog_model_usage(int $songId): array
{
    $galleries = db()->prepare('SELECT g.id AS gallery_id, g.title, COUNT(*) AS track_count FROM music_track_instances i JOIN galleries g ON g.id = i.gallery_id WHERE i.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id) AND i.song_id = ? GROUP BY g.id, g.title ORDER BY g.title, g.id');
    $galleries->execute([$songId]);
    $albums = db()->prepare('SELECT a.id AS album_id, a.title, COUNT(*) AS track_count FROM music_album_songs x JOIN music_albums a ON a.id = x.album_id WHERE x.song_id = ? GROUP BY a.id, a.title ORDER BY a.title, a.id');
    $albums->execute([$songId]);
    return ['direct_galleries' => $galleries->fetchAll(PDO::FETCH_ASSOC), 'albums' => $albums->fetchAll(PDO::FETCH_ASSOC)];
}

/** Preview selected identities and their usage without changing them.
 * @param list<int> $songIds Distinct positive identities selected for merge.
 * @param int $survivorId Identity whose custom title survives.
 * @return array{survivor_id:int,songs:list<array{id:int,title:?string,indexed_title:?string,filename:?string,track_count:int|string,album_count:int|string}>} Existing selected rows.
 */
function music_catalog_model_merge_preview(array $songIds, int $survivorId): array
{
    $marks = implode(',', array_fill(0, count($songIds), '?'));
    $statement = db()->prepare("SELECT s.id, s.title, (SELECT MIN(i.indexed_title) FROM music_track_instances i WHERE i.song_id = s.id AND i.active = 1) AS indexed_title, (SELECT MIN(i.filename) FROM music_track_instances i WHERE i.song_id = s.id AND i.active = 1) AS filename, (SELECT COUNT(*) FROM music_track_instances i WHERE i.song_id = s.id AND i.active = 1) AS track_count, (SELECT COUNT(*) FROM music_album_songs a WHERE a.song_id = s.id) AS album_count FROM music_songs s WHERE s.id IN ($marks) ORDER BY s.id");
    $statement->execute($songIds);
    return ['survivor_id' => $survivorId, 'songs' => $statement->fetchAll(PDO::FETCH_ASSOC)];
}

/** Move references, album membership and tags to the selected survivor.
 * @param list<int> $songIds Valid selected song IDs including the survivor.
 * @param int $survivorId Existing song that retains its canonical title.
 * @param string $tagPolicy Either union all selected tags or keep survivor tags.
 * @return void The caller owns the surrounding revision transaction.
 */
function music_catalog_model_merge(array $songIds, int $survivorId, string $tagPolicy): void
{
    $others = array_values(array_filter($songIds, static fn(int $id): bool => $id !== $survivorId));
    if ($others === []) {
        return;
    }
    $marks = implode(',', array_fill(0, count($others), '?'));
    db()->prepare("UPDATE music_track_instances SET song_id = ? WHERE song_id IN ($marks)")->execute(array_merge([$survivorId], $others));
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

/** Return one song's active physical track references.
 * @param int $songId Stable normalized song identity.
 * @return list<array{gallery_id:int,track_id:string,filename:string,title:?string}> Active physical instances.
 */
function music_catalog_model_song_tracks(int $songId): array
{
    $statement = db()->prepare('SELECT i.gallery_id, i.track_id, i.filename, s.title FROM music_track_instances i JOIN music_songs s ON s.id = i.song_id WHERE s.id = ? AND i.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id) ORDER BY i.gallery_id, i.track_id');
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

/** Return one bounded album member page with title source components.
 * @param int $albumId Stable album identity.
 * @param int $limit Maximum member rows.
 * @param int $offset Zero-based member offset.
 * @return list<array{id:int,membership_order:int,custom_title:?string,indexed_title:?string,filename:?string}> Member title components in stored order.
 */
function music_catalog_model_album_members(int $albumId, int $limit, int $offset): array
{
    $statement = db()->prepare('SELECT s.id, m.sort_order AS membership_order, s.title AS custom_title, MIN(i.indexed_title) AS indexed_title, MIN(i.filename) AS filename FROM music_album_songs m JOIN music_songs s ON s.id = m.song_id LEFT JOIN music_track_instances i ON i.song_id = s.id AND i.active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id) WHERE m.album_id = ? GROUP BY s.id, s.title, m.sort_order ORDER BY m.sort_order, s.id LIMIT ? OFFSET ?');
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
 * @param list<array{gallery_id:int,track_id:string,filename:string,title:?string}> $tracks Exact active physical references to move.
 * @return int Newly allocated song identity.
 */
function music_catalog_model_split(int $songId, array $tracks): int
{
    $first = $tracks[0];
    db()->prepare('INSERT INTO music_songs (title, created_at, updated_at) VALUES (?, NOW(), NOW())')->execute([$first['title']]);
    $newId = (int) db()->lastInsertId();
    db()->prepare('INSERT INTO music_song_tags (song_id, tag_id) SELECT ?, tag_id FROM music_song_tags WHERE song_id = ?')->execute([$newId, $songId]);
    db()->prepare('INSERT INTO music_album_songs (album_id, song_id, sort_order) SELECT album_id, ?, sort_order FROM music_album_songs WHERE song_id = ?')->execute([$newId, $songId]);
    foreach ($tracks as $track) {
        db()->prepare('UPDATE music_track_instances SET song_id = ? WHERE gallery_id = ? AND track_id = ?')->execute([$newId, $track['gallery_id'], $track['track_id']]);
    }
    return $newId;
}

/** Read track and distinct usage projections for one bounded batch.
 * @param list<int> $songIds Positive normalized identities.
 * @return array<int,array{tracks:list<array{gallery_id:int,track_id:string,filename:string}>,direct_galleries:list<array{song_id:int,gallery_id:int,title:string,track_count:int|string}>,albums:list<array{song_id:int,album_id:int,title:string,track_count:int|string}>}> Details keyed by song ID.
 */
function music_catalog_model_song_details(array $songIds): array
{
    if ($songIds === []) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($songIds), '?'));
    $details = [];
    foreach ($songIds as $songId) {
        $details[$songId] = [
            'tracks' => [],
            'direct_galleries' => [],
            'albums' => [],
        ];
    }
    $tracks = db()->prepare("SELECT song_id, gallery_id, track_id, filename FROM music_track_instances i WHERE active = 1 AND NOT EXISTS (SELECT 1 FROM music_catalog_dirty_galleries d WHERE d.gallery_id = i.gallery_id) AND song_id IN ($marks) ORDER BY gallery_id, track_id");
    $tracks->execute($songIds);
    foreach ($tracks->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details[(int) $row['song_id']]['tracks'][] = [
            'gallery_id' => (int) $row['gallery_id'],
            'track_id' => $row['track_id'],
            'filename' => $row['filename'],
        ];
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
    return $details;
}
