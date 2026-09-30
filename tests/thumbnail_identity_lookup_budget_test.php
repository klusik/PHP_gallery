<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/thumbnail_identity_lookup_budget_test.php
 * Module Type: Regression Test
 * Purpose: Bound thumbnail identity candidate reads independently of gallery size.
 * Responsibilities: Exercise indexed semantic candidate lookup on disposable SQLite data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Supply only this fixture's isolated database.
     * @return \PDO Disposable in-memory fixture database.
     */
    function db(): \PDO { return $GLOBALS['identity_budget_database']; }
}

namespace {
    require_once dirname(__DIR__) . '/app/models/images.php';

    /** Count database prepares made by the model under test.
     */
    class IdentityBudgetPDO extends PDO
    {
        /** Count prepared statements in one measured candidate lookup.
         * @var int Number of prepares since the fixture last reset the counter.
         */
        public int $prepares = 0;

        /** Prepare one statement while counting model database round trips.
         * @param string $query SQL prepared by the model.
         * @param array<string|int,mixed> $options Driver preparation options.
         * @return PDOStatement|false Prepared statement or driver failure.
         */
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            ++$this->prepares;
            return parent::prepare($query, $options);
        }
    }

    /** Require one candidate-budget invariant.
     * @param bool $condition Expected invariant.
     * @param string $message Safe failure explanation.
     * @return void No return value.
     */
    function identity_budget_check(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    $pdo = new IdentityBudgetPDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $GLOBALS['identity_budget_database'] = $pdo;
    $pdo->exec('CREATE TABLE images (id INTEGER PRIMARY KEY, gallery_id INTEGER NOT NULL, filename TEXT NOT NULL,
        relative_path TEXT NOT NULL, relative_path_hash TEXT NOT NULL, thumbnail_source_identity_version INTEGER NOT NULL,
        visibility TEXT NOT NULL, nsfw_enabled INTEGER NOT NULL)');
    $pdo->exec('CREATE INDEX idx_images_gallery_filename ON images (gallery_id, filename)');
    $pdo->exec('CREATE UNIQUE INDEX idx_images_gallery_path ON images (gallery_id, relative_path_hash)');
    $insert = $pdo->prepare('INSERT INTO images VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $pdo->beginTransaction();
    for ($id = 1; $id <= 10000; ++$id) {
        $path = 'bulk/unrelated-' . $id . '.jpg';
        $insert->execute([$id, 1, basename($path), $path, hash('sha256', $path), 0, 'public', 0]);
    }
    $canonicalPath = 'new/photo.jpg';
    $canonicalHash = hash('sha256', $canonicalPath);
    foreach ([
        [10001, 'same.jpg', 'a/same.jpg', 0, 'unpublished', 1],
        [10002, 'same.png', 'b/same.png', 0, 'private', 1],
        [10003, 'photo_' . $canonicalHash . '.jpg', 'old/crafted.jpg', 0, 'private', 1],
        [10004, 'photo.jpg', $canonicalPath, 1, 'public', 0],
        [10005, 'a%_b.jpg', 'odd/a%_b.jpg', 0, 'public', 0],
        [10006, 'axxb.jpg', 'odd/axxb.jpg', 0, 'public', 0],
        [10008, 'čapka.png', 'private/čapka.png', 0, 'private', 1],
        [10009, 'фото.jpg', 'private/фото.jpg', 0, 'unpublished', 1],
        [10010, 'čapka', 'private/noext', 0, 'private', 1],
        [10011, 'фото', 'private/noext-cyrillic', 0, 'private', 1],
        [10012, 'mixedcase.PNG', 'private/mixedcase.PNG', 0, 'private', 1],
        [10013, 'Kelvin.jpg', 'private/kelvin.jpg', 0, 'private', 1],
        [10014, 'ſun.png', 'private/long-s.png', 0, 'private', 1],
    ] as [$id, $name, $path, $version, $visibility, $nsfw]) {
        $insert->execute([$id, 1, $name, $path, hash('sha256', $path), $version, $visibility, $nsfw]);
    }
    $insert->execute([10007, 2, 'same.jpg', 'other/same.jpg', hash('sha256', 'other/same.jpg'), 0, 'public', 0]);
    $pdo->commit();
    $pdo->prepares = 0;
    $rows = \Gallery\Models\image_model_thumbnail_identity_candidates(1, [1],
        ['same', 'photo_' . $canonicalHash, 'a%_b', 'Čapka', 'ФОТО', 'MixedCase'], [$canonicalHash], true, 17);
    $ids = array_map('intval', array_column($rows, 'id'));
    sort($ids);
    identity_budget_check($ids === [1, 10001, 10002, 10003, 10004, 10005, 10006, 10008, 10009, 10010, 10011, 10012],
        'Lookup lost Unicode conflicts or applied access filters to the candidate superset.');
    identity_budget_check($pdo->prepares === 1 && count($rows) < 17, 'Candidate lookup scanned returned the complete 10k gallery.');
    $literal = \Gallery\Models\image_model_thumbnail_identity_candidates(1, [], ['a%_b'], [], true, 17);
    identity_budget_check(array_map('intval', array_column($literal, 'id')) === [10005], 'Literal ASCII wildcard characters broadened the stem.');
    identity_budget_check(preg_match('/\Akelvin\z/iu', 'Kelvin') === 1
        && preg_match('/\Asun\z/iu', 'ſun') === 1, 'Fixture does not reproduce Unicode ASCII-equivalent case folds.');
    $folded = \Gallery\Models\image_model_thumbnail_identity_candidates(1, [], ['kelvin', 'sun'], [], true, 17);
    $foldedIds = array_map('intval', array_column($folded, 'id'));
    sort($foldedIds);
    identity_budget_check($foldedIds === [10013, 10014], 'Reverse Kelvin or long-S equivalence candidate was omitted.');
    $limited = \Gallery\Models\image_model_thumbnail_identity_candidates(1, [], ['same'], [], false, 1);
    identity_budget_check(count($limited) === 1 && (int) $limited[0]['thumbnail_source_identity_version'] === 0,
        'Shared limit or legacy marker projection failed.');
    $pdo->exec('CREATE TABLE legacy_images (id INTEGER PRIMARY KEY, gallery_id INTEGER NOT NULL, filename TEXT NOT NULL, relative_path TEXT NOT NULL)');
    $pdo->exec("INSERT INTO legacy_images VALUES (1, 1, 'legacy.jpg', 'legacy.jpg')");
    $pdo->exec('ALTER TABLE images RENAME TO modern_images');
    $pdo->exec('ALTER TABLE legacy_images RENAME TO images');
    $legacy = \Gallery\Models\image_model_thumbnail_identity_candidates(1, [1], ['legacy'], [$canonicalHash], false, 17);
    identity_budget_check(count($legacy) === 1 && (int) $legacy[0]['thumbnail_source_identity_version'] === 0,
        'Confirmed legacy schema queried the unavailable path-hash column.');
    $pdo->exec('DROP TABLE images');
    $pdo->exec('ALTER TABLE modern_images RENAME TO images');
    identity_budget_check(\Gallery\Models\image_model_thumbnail_identity_candidates(1, [], [], [], false, 17) === [], 'Empty identities queried unrelated rows.');
    foreach ([0, 4098] as $invalidLimit) {
        try {
            \Gallery\Models\image_model_thumbnail_identity_candidates(1, [1], [], [], true, $invalidLimit);
            throw new RuntimeException('Invalid model limit accepted.');
        } catch (InvalidArgumentException $error) { /* Expected semantic refusal. */ }
    }
    $pdo->exec('ALTER TABLE images RENAME TO lookup_images');
    $pdo->exec('CREATE TABLE images (id INTEGER PRIMARY KEY, gallery_id INTEGER NOT NULL, filename TEXT NOT NULL,
        relative_path TEXT NOT NULL, relative_path_hash TEXT NOT NULL, thumbnail_source_identity_version INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NULL, updated_at TEXT NULL)');
    $scannerId = \Gallery\Models\image_model_insert_scan_row([
        'gallery_id' => 1, 'filename' => 'scanner.jpg', 'relative_path' => 'scanner.jpg',
        'relative_path_hash' => hash('sha256', 'scanner.jpg'),
    ]);
    $migrationId = \Gallery\Models\image_model_migration_upsert(1, null, [
        'filename' => 'import.jpg', 'relative_path' => 'import.jpg', 'relative_path_hash' => hash('sha256', 'import.jpg'),
    ], '2026-09-30 19:00:00');
    $pdo->exec("INSERT INTO images (gallery_id, filename, relative_path, relative_path_hash) VALUES (1, 'legacy.jpg', 'legacy.jpg', 'legacy')");
    $legacyId = (int) $pdo->lastInsertId();
    \Gallery\Models\image_model_migration_upsert(1, $legacyId, ['filename' => 'updated.jpg'], '2026-09-30 19:00:01');
    $markers = $pdo->query('SELECT id, thumbnail_source_identity_version FROM images')->fetchAll(PDO::FETCH_KEY_PAIR);
    identity_budget_check((int) $markers[$scannerId] === 1 && (int) $markers[$migrationId] === 1
        && (int) $markers[$legacyId] === 0, 'Interrupted default DDL changed canonical insert or legacy update marker policy.');
    try {
        \Gallery\Models\image_model_migration_upsert(1, $legacyId, ['thumbnail_source_identity_version' => 1], '2026-09-30 19:00:02');
        throw new RuntimeException('Imported marker overwrite accepted.');
    } catch (InvalidArgumentException $error) { /* Imported naming markers are forbidden. */ }
    echo "Thumbnail identity indexed lookup budget contracts passed.\n";
}
