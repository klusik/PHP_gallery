<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/models/gallery_visit_content_migration.php
 * Module Type: Migration Model
 * Purpose: Seed initial content evidence without the updater's loaded runtime model.
 * Responsibilities: Own bounded, replay-safe migration SQL on the explicit PDO.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Models;

use PDO;

/**
 * Freeze the initial content migration's galleries admission policy.
 * Type: int. Units: galleries. Scope: migration 202610250001 only.
 * Consumers: gallery_visit_migration_model_seed and its bounded SQL helpers.
 * Rationale: 2048 admits the original complete hierarchy within shared-host
 * memory. This migration must remain
 * independent of an older runtime model already loaded by the updater.
 */
const VISIT_MIGRATION_NODE_LIMIT = 2048;

/**
 * Freeze the initial content migration's images admission policy.
 * Type: int. Units: images. Scope: migration 202610250001 only.
 * Consumers: gallery_visit_migration_model_seed and its bounded SQL helpers.
 * Rationale: 20000 retains the original image bound without truncating evidence.
 * This migration must remain
 * independent of an older runtime model already loaded by the updater.
 */
const VISIT_MIGRATION_IMAGE_LIMIT = 20000;

/**
 * Freeze the initial content migration's memberships admission policy.
 * Type: int. Units: memberships. Scope: migration 202610250001 only.
 * Consumers: gallery_visit_migration_model_seed and its bounded SQL helpers.
 * Rationale: 50000 retains the original physical and virtual identity bound.
 * This migration must remain
 * independent of an older runtime model already loaded by the updater.
 */
const VISIT_MIGRATION_MEMBER_LIMIT = 50000;

/**
 * Freeze the initial content migration's definitions admission policy.
 * Type: int. Units: definitions. Scope: migration 202610250001 only.
 * Consumers: gallery_visit_migration_model_seed and its bounded SQL helpers.
 * Rationale: 128 retains the original cap on virtual membership evaluation.
 * This migration must remain
 * independent of an older runtime model already loaded by the updater.
 */
const VISIT_MIGRATION_SMART_LIMIT = 128;

/**
 * Freeze the initial content migration's JSON containers admission policy.
 * Type: int. Units: JSON containers. Scope: migration 202610250001 only.
 * Consumers: gallery_visit_migration_model_seed and its bounded SQL helpers.
 * Rationale: 512 preserves the original decoder limit and rule grammar.
 * This migration must remain
 * independent of an older runtime model already loaded by the updater.
 */
const VISIT_MIGRATION_RULE_DEPTH = 512;

/** Signal unfinished optional migration evidence while preserving core upgrades. */
final class GalleryVisitMigrationNotReady extends \RuntimeException {}

/**
 * Seed an initially empty addition index on the migration's explicit connection.
 * This one-time authoring step is independent of sessions/configuration and
 * never runs from a visitor read. Existing successful revisions are preserved.
 * @param PDO $pdo Installer/updater-owned database connection.
 * @return void Seeds current identities transactionally; an oversized or failed
 *     seed remains dirty and retryable without touching authored media.
 */
function gallery_visit_migration_model_seed(PDO $pdo): void
{
    $revision = (int) $pdo->query(
        'SELECT revision FROM gallery_visit_content_state WHERE id = 1',
    )->fetchColumn();
    if ($revision > 0) {
        return;
    }
    $galleryIds = array_map('intval', $pdo->query(
        'SELECT id FROM galleries ORDER BY id LIMIT ' . (VISIT_MIGRATION_NODE_LIMIT + 1),
    )->fetchAll(PDO::FETCH_COLUMN));
    if (count($galleryIds) > VISIT_MIGRATION_NODE_LIMIT
        || (int) $pdo->query("SELECT COUNT(*) FROM images WHERE visibility = 'public'")
            ->fetchColumn() > VISIT_MIGRATION_IMAGE_LIMIT) {
        throw new GalleryVisitMigrationNotReady('Initial visit content exceeds its bound.');
    }
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM gallery_visit_content_members');
        // Revision one denotes the initial observation, not a wall-clock date.
        $pdo->exec(
            'INSERT INTO gallery_visit_content_members '
            . '(node_key, item_key, lane_key, revision) '
            . "SELECT CONCAT('g:', i.gallery_id), CONCAT('i:', i.id), "
            . "CONCAT('m:', i.gallery_id, ':1'), 1 FROM images i "
            . 'JOIN galleries g ON g.id = i.gallery_id WHERE i.visibility = \'public\'',
        );
        $listed = array_map('intval', $pdo->query(
            "SELECT id FROM galleries WHERE visibility = 'public' "
            . "AND access_listing = 'listed'",
        )->fetchAll(PDO::FETCH_COLUMN));
        $definitions = $pdo->query(
            "SELECT id, rules_json FROM smart_galleries WHERE enabled = 1 "
            . "AND visibility = 'public' LIMIT " . (VISIT_MIGRATION_SMART_LIMIT + 1),
        )->fetchAll(PDO::FETCH_ASSOC);
        if (count($definitions) > VISIT_MIGRATION_SMART_LIMIT) {
            throw new GalleryVisitMigrationNotReady('Initial Smart inventory exceeds its bound.');
        }
        foreach ($definitions as $definition) {
            $rules = json_decode((string) $definition['rules_json'], true,
                VISIT_MIGRATION_RULE_DEPTH, JSON_THROW_ON_ERROR);
            $query = smart_gallery_model_result_query(
                $rules, $listed, true, true, 'created_at', 'asc',
            );
            $insert = $pdo->prepare(
                'INSERT INTO gallery_visit_content_members '
                . '(node_key, item_key, lane_key, revision) SELECT ?, '
                . "CONCAT('i:', i.id), CONCAT('m:', i.gallery_id, ':1'), 1 "
                . 'FROM images i JOIN galleries g ON g.id = i.gallery_id WHERE '
                . $query['where'],
            );
            $insert->execute(array_merge(['s:' . (int) $definition['id']], $query['params']));
            gallery_visit_migration_model_assert_bound($pdo);
        }
        // Lane one retains membership independent of NSFW metadata. Lane zero
        // tracks safe availability; lane two records restricted-item presence.
        $pdo->exec(
            'INSERT INTO gallery_visit_content_members '
            . '(node_key,item_key,lane_key,revision) SELECT m.node_key,m.item_key, '
            . "CONCAT('m:',i.gallery_id,':',CASE WHEN "
            . 'COALESCE(i.nsfw_enabled,0) <> 0 OR COALESCE(g.nsfw_enabled,0) <> 0 '
            . 'THEN 2 ELSE 0 END),1 FROM gallery_visit_content_members m '
            . "JOIN images i ON m.item_key=CONCAT('i:',i.id) "
            . 'JOIN galleries g ON g.id=i.gallery_id '
            . "WHERE m.lane_key=CONCAT('m:',i.gallery_id,':1')",
        );
        gallery_visit_migration_model_assert_bound($pdo);
        $virtualSources = [];
        foreach ($pdo->query(
            "SELECT DISTINCT node_key, lane_key FROM gallery_visit_content_members "
            . "WHERE node_key LIKE 's:%'",
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $source = (int) explode(':', $row['lane_key'])[1];
            $virtualSources[$source][$row['node_key']] = $row['node_key'];
        }
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO gallery_visit_content_members '
            . '(node_key,item_key,lane_key,revision) VALUES (?,?,?,1)',
        );
        if ($galleryIds !== []) {
            gallery_visit_migration_model_assert_attachment_bound($galleryIds, $pdo);
            foreach (['pdf', 'music'] as $kind) {
                $usage = gallery_visit_migration_model_attachment_rows($pdo, $galleryIds, $kind);
                foreach ($usage['states'] as $state) {
                    if ($state !== 'ready') {
                        throw new GalleryVisitMigrationNotReady('Initial attachment evidence is dirty.');
                    }
                }
                foreach ($usage['items'] as $item) {
                    if ($item['hidden'] || !is_string($item['sha256'])) {
                        continue;
                    }
                    $source = (int) $item['gallery_id'];
                    $nodes = array_merge(['g:' . $source],
                        array_values($virtualSources[$source] ?? []));
                    foreach ($nodes as $node) {
                        $insert->execute([$node, $kind . ':' . $item['sha256'], 'a:' . $source]);
                    }
                }
            }
        }
        $pdo->exec(
            'INSERT IGNORE INTO gallery_visit_content_members '
            . '(node_key,item_key,lane_key,revision) '
            . "SELECT CASE WHEN parent_id IS NULL THEN 'r:0' ELSE CONCAT('g:',parent_id) END, "
            . "CONCAT('g:',id),CONCAT('e:g:',id),1 FROM galleries "
            . "WHERE visibility='public' AND access_listing='listed'",
        );
        $pdo->exec(
            'INSERT IGNORE INTO gallery_visit_content_members '
            . '(node_key,item_key,lane_key,revision) '
            . "SELECT 'r:0',CONCAT('s:',id),CONCAT('e:s:',id),1 FROM smart_galleries "
            . "WHERE enabled=1 AND visibility='public' AND placement_mode='root'",
        );
        $pdo->exec(
            'INSERT IGNORE INTO gallery_visit_content_members '
            . '(node_key,item_key,lane_key,revision) '
            . "SELECT CONCAT('g:',p.gallery_id),CONCAT('s:',s.id),CONCAT('e:s:',s.id),1 "
            . 'FROM smart_gallery_placements p JOIN smart_galleries s ON s.id=p.smart_gallery_id '
            . "WHERE s.enabled=1 AND s.visibility='public' AND s.placement_mode='gallery'",
        );
        gallery_visit_migration_model_assert_bound($pdo);
        $pdo->exec('UPDATE gallery_visit_content_state SET revision=1,dirty=0 WHERE id=1');
        $pdo->exec('DELETE FROM gallery_visit_content_pending');
        $pdo->commit();
    } catch (\Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

/**
 * Refuse an oversized migration seed before publishing it.
 * @param PDO $pdo Migration-owned transaction connection.
 * @return void Continues below the retained membership bound; otherwise throws.
 */
function gallery_visit_migration_model_assert_bound(PDO $pdo): void
{
    if ((int) $pdo->query('SELECT COUNT(*) FROM gallery_visit_content_members')
        ->fetchColumn() > VISIT_MIGRATION_MEMBER_LIMIT) {
        throw new GalleryVisitMigrationNotReady('Initial visit membership exceeds its bound.');
    }
}

/**
 * Bound attachment expansion before allocating resolved digest rows.
 * @param list<int> $galleryIds Explicit physical attachment sources.
 * @param PDO $pdo Explicit migration connection; no runtime database lookup.
 * @return void Continues below the membership bound; oversized input throws.
 */
function gallery_visit_migration_model_assert_attachment_bound(
    array $galleryIds, PDO $pdo,
): void {
    if ($galleryIds === []) {
        return;
    }
    $marks = implode(',', array_fill(0, count($galleryIds), '?'));
    $statement = $pdo->prepare(
        'SELECT COALESCE(SUM(CASE WHEN album_id IS NULL THEN 1 ELSE '
        . '(SELECT COUNT(*) FROM music_album_songs a WHERE a.album_id=u.album_id) '
        . 'END),0) FROM gallery_attachment_usage_items u WHERE gallery_id IN ('
        . $marks . ')',
    );
    $statement->execute($galleryIds);
    if ((int) $statement->fetchColumn() > VISIT_MIGRATION_MEMBER_LIMIT) {
        throw new GalleryVisitMigrationNotReady('Visit attachment expansion exceeds its bound.');
    }
}

/** Read digest rows for ready snapshots in the requested descendant set.
 * @param PDO $pdo Explicit migration-owned connection.
 * @param list<int> $galleryIds Physical sources admitted by the bounded seed.
 * @param string $kind Attachment kind: `pdf` or `music`.
 * @return array{states:array<int,string>,items:list<array{
 *     gallery_id:int,sha256:?string,hidden:bool}>}
 *     Snapshot statuses and canonical public-visibility source digests.
 */
function gallery_visit_migration_model_attachment_rows(
    PDO $pdo,
    array $galleryIds,
    string $kind,
): array {
    if ($galleryIds === [] || !in_array($kind, ['pdf', 'music'], true)) {
        return ['states' => [], 'items' => []];
    }
    $marks = implode(',', array_fill(0, count($galleryIds), '?'));
    $stateMap = [];
    $musicJoins = $kind === 'music'
        ? ' LEFT JOIN music_album_songs mas ON mas.album_id = u.album_id '
            . 'LEFT JOIN music_repository_assets album_asset ON '
            . 'album_asset.id = (SELECT canonical_asset.id '
            . 'FROM music_repository_assets canonical_asset '
            . 'WHERE canonical_asset.song_id = mas.song_id '
            . 'ORDER BY canonical_asset.created_at, canonical_asset.id LIMIT 1) '
            . 'LEFT JOIN music_repository_assets direct_asset '
            . 'ON direct_asset.id = u.asset_id '
        : '';
    $digestExpression = $kind === 'music'
        ? 'CASE WHEN u.asset_id IS NOT NULL THEN direct_asset.sha256 '
            . 'WHEN u.album_id IS NOT NULL THEN album_asset.sha256 '
            . 'ELSE u.sha256 END'
        : 'u.sha256';
    $items = $pdo->prepare(
        'SELECT s.gallery_id, s.state, ' . $digestExpression
        . ' AS sha256, u.hidden FROM gallery_attachment_usage_state s '
        . 'LEFT JOIN gallery_attachment_usage_items u '
        . 'ON u.gallery_id = s.gallery_id AND u.kind = s.kind '
        . $musicJoins
        . "WHERE s.kind = ? AND s.gallery_id IN ($marks)",
    );
    $items->execute(array_merge([$kind], $galleryIds));
    $rows = [];
    foreach ($items->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $galleryId = (int) $row['gallery_id'];
        $stateMap[$galleryId] = (string) $row['state'];
        if ((string) $row['state'] !== 'ready' || $row['hidden'] === null) {
            continue;
        }
        $rows[] = [
            'gallery_id' => $galleryId,
            'sha256' => is_string($row['sha256']) ? $row['sha256'] : null,
            'hidden' => (bool) $row['hidden'],
        ];
    }
    return ['states' => $stateMap, 'items' => $rows];
}
