<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_visit_content_model_test.php
 * Module Type: Regression Test
 * Purpose: Verify addition-only identity persistence and scoped publication.
 * Responsibilities: Exercise real SQL, source moves, deletion, stale leases
 *   and direct/shared music source selection.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the isolated SQLite connection owned by this fixture.
     * @return \PDO Disposable in-memory database, never installation storage.
     */
    function db(): \PDO
    {
        return $GLOBALS['visit_content_fixture_database'];
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/models/galleries.php';
    require_once dirname(__DIR__) . '/app/models/music_catalog.php';

    /**
     * Refuse a failed content-index behavior with a bounded fixture message.
     * @param bool $condition Actual production-model assertion.
     * @param string $message Safe fixture-authored diagnostic.
     * @return void Continues on success, otherwise throws RuntimeException.
     */
    function visit_content_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Bound this deliberately tiny persistence vector.
     * Type: int. Units: memberships. Scope: this isolated SQL regression.
     * Consumers: production gallery_visit_content_model_publish.
     * Rationale: eight admits the three named identities plus source-class moves.
     */
    const VISIT_CONTENT_FIXTURE_LIMIT = 8;

    $pdo = new \PDO('sqlite::memory:');
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $GLOBALS['visit_content_fixture_database'] = $pdo;
    visit_content_assert(!\Gallery\Models\gallery_visit_content_model_transaction_active(),
        'Autocommit permits the index to own its publication transaction.');
    $pdo->beginTransaction();
    visit_content_assert(\Gallery\Models\gallery_visit_content_model_transaction_active(),
        'An unfinished authoring transaction must defer index publication.');
    $pdo->rollBack();
    $pdo->exec('CREATE TABLE gallery_visit_content_state '
        . '(id INTEGER PRIMARY KEY,generation INTEGER,revision INTEGER,dirty INTEGER)');
    $pdo->exec('INSERT INTO gallery_visit_content_state VALUES (1,1,1,0)');
    $pdo->exec('CREATE TABLE gallery_visit_content_pending (scope_key TEXT PRIMARY KEY)');
    $pdo->exec('CREATE TABLE gallery_visit_content_members '
        . '(node_key TEXT,item_key TEXT,lane_key TEXT,revision INTEGER,'
        . 'PRIMARY KEY(node_key,item_key,lane_key))');
    $members = [
        'g:1|i:1|m:1:0' => ['node' => 'g:1', 'item' => 'i:1', 'lane' => 'm:1:0'],
        's:1|i:1|m:1:0' => ['node' => 's:1', 'item' => 'i:1', 'lane' => 'm:1:0'],
        'g:2|i:2|m:2:0' => ['node' => 'g:2', 'item' => 'i:2', 'lane' => 'm:2:0'],
    ];
    visit_content_assert(\Gallery\Models\gallery_visit_content_model_publish(
        $members, 1, VISIT_CONTENT_FIXTURE_LIMIT, [], [], true,
    ), 'Initial identity publication must commit.');
    $firstRevision = (int) $pdo->query(
        "SELECT revision FROM gallery_visit_content_members WHERE node_key='g:1'",
    )->fetchColumn();
    \Gallery\Models\gallery_visit_content_model_dirty([1], []);
    $state = \Gallery\Models\gallery_visit_content_model_state();
    visit_content_assert($state['dirty'], 'A pending authored scope must suppress stale evidence.');
    visit_content_assert(\Gallery\Models\gallery_visit_content_model_publish(
        array_slice($members, 0, 2, true), $state['generation'],
        VISIT_CONTENT_FIXTURE_LIMIT, [1], [],
    ), 'A physical source must reconcile its virtual membership lanes.');
    visit_content_assert((int) $pdo->query(
        "SELECT revision FROM gallery_visit_content_members WHERE node_key='g:1'",
    )->fetchColumn() === $firstRevision, 'Cosmetic saves must retain addition revisions.');
    visit_content_assert((int) $pdo->query(
        "SELECT COUNT(*) FROM gallery_visit_content_members WHERE node_key='g:2'",
    )->fetchColumn() === 1, 'Scoped publication must preserve unrelated galleries.');

    \Gallery\Models\gallery_visit_content_model_dirty([1, 2], []);
    $state = \Gallery\Models\gallery_visit_content_model_state();
    $moved = ['s:1|i:1|m:2:0' => ['node' => 's:1', 'item' => 'i:1', 'lane' => 'm:2:0']];
    visit_content_assert(\Gallery\Models\gallery_visit_content_model_publish(
        $moved, $state['generation'], VISIT_CONTENT_FIXTURE_LIMIT, [1, 2], [],
    ), 'A two-source move must reconcile both old and new scopes.');
    visit_content_assert((int) $pdo->query(
        "SELECT revision FROM gallery_visit_content_members WHERE node_key='s:1'",
    )->fetchColumn() === $firstRevision,
        'Moving an image that remains in a Smart result must not mint a new membership.');
    $staleGeneration = $state['generation'];
    \Gallery\Models\gallery_visit_content_model_dirty([], [1]);
    visit_content_assert(!\Gallery\Models\gallery_visit_content_model_publish(
        [], $staleGeneration, VISIT_CONTENT_FIXTURE_LIMIT, [], [1],
    ), 'A concurrent generation must refuse an older snapshot.');
    visit_content_assert(\Gallery\Models\gallery_visit_content_model_state()['dirty'],
        'A refused publication must retain pending evidence.');
    $state = \Gallery\Models\gallery_visit_content_model_state();
    visit_content_assert(\Gallery\Models\gallery_visit_content_model_publish(
        [], $state['generation'], VISIT_CONTENT_FIXTURE_LIMIT, [], [1],
    ), 'Deletion-only reconciliation must commit without a new membership.');
    visit_content_assert((int) $pdo->query('SELECT COUNT(*) FROM gallery_visit_content_members')
        ->fetchColumn() === 0, 'Removed items must not continue producing New.');
    // Independent tiny ownership vectors: direct song source 1, linked album
    // source 2, explicit snapshot source 3 and previously pending source 4.
    $pdo->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY)');
    $pdo->exec('INSERT INTO galleries VALUES (1),(2),(3),(4)');
    $pdo->exec('CREATE TABLE music_catalog_dirty_galleries (gallery_id INTEGER)');
    $pdo->exec('CREATE TABLE music_track_instances (gallery_id INTEGER,song_id INTEGER)');
    $pdo->exec('INSERT INTO music_track_instances VALUES (1,10)');
    $pdo->exec('CREATE TABLE music_gallery_album_links (gallery_id INTEGER,album_id INTEGER)');
    $pdo->exec('INSERT INTO music_gallery_album_links VALUES (2,7)');
    $pdo->exec('CREATE TABLE music_album_songs (album_id INTEGER,song_id INTEGER)');
    $pdo->exec('INSERT INTO music_album_songs VALUES (7,10)');
    visit_content_assert(\Gallery\Models\music_catalog_model_visit_dirty_sources(
        VISIT_CONTENT_FIXTURE_LIMIT, ['songs' => [10]], true,
    ) === [1, 2], 'Shared song edits must include direct and live-album sources.');
    visit_content_assert(\Gallery\Models\music_catalog_model_visit_dirty_sources(
        VISIT_CONTENT_FIXTURE_LIMIT, ['songs' => [10]], false,
    ) === [1], 'Legacy catalog scope must not require live repository links.');
    visit_content_assert(\Gallery\Models\music_catalog_model_visit_dirty_sources(
        VISIT_CONTENT_FIXTURE_LIMIT, ['albums' => [7]], true,
    ) === [2], 'Album membership changes must retain their explicit consumers.');
    visit_content_assert(\Gallery\Models\music_catalog_model_visit_dirty_sources(
        VISIT_CONTENT_FIXTURE_LIMIT, ['galleries' => [3]], true,
    ) === [3], 'A clean snapshot must retain its explicitly supplied source.');
    $pdo->exec('INSERT INTO music_catalog_dirty_galleries VALUES (4)');
    visit_content_assert(\Gallery\Models\music_catalog_model_visit_dirty_sources(
        VISIT_CONTENT_FIXTURE_LIMIT,
    ) === [4], 'Previously pending catalog sources remain recoverable.');
    // A one-source boundary deliberately admits one additional overflow sentinel.
    visit_content_assert(\Gallery\Models\music_catalog_model_visit_dirty_sources(
        1, ['songs' => [10], 'galleries' => [3]], true,
    ) === [1, 2], 'Combined music source selection must obey its sentinel bound.');
    echo "gallery_visit_content_model_test: ok\n";
}
