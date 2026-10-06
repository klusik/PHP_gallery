<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/database_engine_contract_test.php
 * Module Type: Regression Test
 * Purpose: Verify engine-sensitive SQL behavior against the owned disposable database.
 * Responsibilities:
 *   - Check migrated storage metadata, JSON semantics, vote constraints, and named locks.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_safety.php';

use function GalleryWorkflow\check;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\validateFixture;

/**
 * Assert one database-engine postcondition without exposing diagnostic data.
 *
 * @param bool $condition Whether the observed database state satisfies the contract.
 * @param string $message Bounded assertion text that contains no connection details.
 * @return void Throws a safe runtime assertion when the condition is false.
 */
function database_engine_contract_assert(bool $condition, string $message): void
{
    check($condition, $message);
}

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP') . " database engine contract requires disposable fixture\n";
    exit($required ? 1 : 0);
}

$stage = 'fixture validation';
$failure = false;
$fixtureDirectory = '';
$token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
$pdo = null;
$lockConnection = null;
$temporaryTable = '';
$lockName = '';
$primaryLockOwned = false;
$secondaryLockOwned = false;
try {
    $fixtureDirectory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
    $pdo = fixtureDatabase($fixtureDirectory, $token);
    $lockConnection = fixtureDatabase($fixtureDirectory, $token);

    $stage = 'migrated storage metadata';
    $tableMetadata = $pdo->prepare(
        'SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $tableMetadata->execute(['galleries']);
    $galleryTable = $tableMetadata->fetch(\PDO::FETCH_ASSOC);
    database_engine_contract_assert(is_array($galleryTable)
        && strcasecmp((string) ($galleryTable['ENGINE'] ?? ''), 'InnoDB') === 0
        && str_starts_with(strtolower((string) ($galleryTable['TABLE_COLLATION'] ?? '')), 'utf8mb4_'),
        'Migrated galleries table must report InnoDB and utf8mb4 through INFORMATION_SCHEMA.');
    $columnMetadata = $pdo->prepare(
        'SELECT CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $columnMetadata->execute(['galleries', 'title']);
    $galleryTitle = $columnMetadata->fetch(\PDO::FETCH_ASSOC);
    database_engine_contract_assert(is_array($galleryTitle)
        && strtolower((string) ($galleryTitle['CHARACTER_SET_NAME'] ?? '')) === 'utf8mb4'
        && str_starts_with(strtolower((string) ($galleryTitle['COLLATION_NAME'] ?? '')), 'utf8mb4_'),
        'Migrated gallery titles must report utf8mb4 metadata through INFORMATION_SCHEMA.');

    $stage = 'temporary JSON table';
    $suffix = bin2hex(random_bytes(12));
    $temporaryTable = 'gallery_engine_contract_' . $suffix;
    $quotedTable = '`' . $temporaryTable . '`';
    $pdo->exec('CREATE TEMPORARY TABLE ' . $quotedTable . ' ('
        . 'id INT NOT NULL PRIMARY KEY, payload JSON NOT NULL'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $unicodeValue = 'Příliš žluťoučký kůň 🛩';
    $json = json_encode(['label' => $unicodeValue, 'nested' => ['value' => 7]],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $insertJson = $pdo->prepare('INSERT INTO ' . $quotedTable . ' (id, payload) VALUES (?, ?)');
    $insertJson->execute([1, $json]);
    $roundTrip = $pdo->query(
        'SELECT JSON_VALID(payload) AS is_valid, JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.label\')) AS label '
        . 'FROM ' . $quotedTable . ' WHERE id = 1'
    )->fetch(\PDO::FETCH_ASSOC);
    database_engine_contract_assert(is_array($roundTrip)
        && (int) ($roundTrip['is_valid'] ?? 0) === 1
        && ($roundTrip['label'] ?? null) === $unicodeValue,
        'Database JSON validation and extraction must preserve Unicode text.');

    $stage = 'malformed JSON rejection';
    $malformedRejected = false;
    try {
        $insertJson->execute([2, '{"invalid":']);
    } catch (\PDOException) {
        $malformedRejected = true;
    }
    database_engine_contract_assert($malformedRejected, 'JSON columns must reject malformed JSON input.');
    database_engine_contract_assert((int) $pdo->query('SELECT COUNT(*) FROM ' . $quotedTable)->fetchColumn() === 1,
        'Rejected malformed JSON must leave the temporary row count unchanged.');

    $stage = 'migrated vote check rejection';
    $seedImageId = $pdo->query('SELECT id FROM images ORDER BY id LIMIT 1')->fetchColumn();
    database_engine_contract_assert($seedImageId !== false, 'Disposable migration seed must contain an image for the vote constraint check.');
    $visitorHash = bin2hex(random_bytes(32));
    $voteCount = $pdo->prepare('SELECT COUNT(*) FROM image_votes WHERE image_id = ? AND visitor_hash = ?');
    $voteCount->execute([(int) $seedImageId, $visitorHash]);
    database_engine_contract_assert((int) $voteCount->fetchColumn() === 0, 'Generated vote identity must be unused.');
    $voteRow = $pdo->prepare('SELECT vote FROM image_votes WHERE image_id = ? AND visitor_hash = ?');
    $pdo->beginTransaction();
    $invalidVoteRejected = false;
    try {
        $validVote = $pdo->prepare(
            'INSERT INTO image_votes (image_id, user_id, visitor_hash, vote, created_at, updated_at) '
            . 'VALUES (?, NULL, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $validVote->execute([(int) $seedImageId, $visitorHash]);
        $voteRow->execute([(int) $seedImageId, $visitorHash]);
        database_engine_contract_assert((int) $voteRow->fetchColumn() === 1,
            'A valid migrated image vote must persist before checking its constraint.');
        $invalidVote = $pdo->prepare('UPDATE image_votes SET vote = 0 WHERE image_id = ? AND visitor_hash = ?');
        try {
            $invalidVote->execute([(int) $seedImageId, $visitorHash]);
        } catch (\PDOException) {
            $invalidVoteRejected = true;
        }
        database_engine_contract_assert($invalidVoteRejected,
            'Migrated image vote constraint must reject values outside -1 and 1.');
        $voteRow->execute([(int) $seedImageId, $visitorHash]);
        database_engine_contract_assert((int) $voteRow->fetchColumn() === 1,
            'Rejected invalid vote update must preserve the prior valid row value.');
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    $voteCount->execute([(int) $seedImageId, $visitorHash]);
    database_engine_contract_assert((int) $voteCount->fetchColumn() === 0,
        'Rolled-back vote contract row must leave the migrated table unchanged.');

    $stage = 'named lock exclusion and reacquisition';
    $lockName = 'gallery_engine_' . $suffix;
    $getLock = static function (\PDO $connection, string $name): ?int {
        $statement = $connection->prepare('SELECT GET_LOCK(?, 0)');
        $statement->execute([$name]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    };
    $releaseLock = static function (\PDO $connection, string $name): int {
        $statement = $connection->prepare('SELECT RELEASE_LOCK(?)');
        $statement->execute([$name]);
        return (int) $statement->fetchColumn();
    };
    database_engine_contract_assert($getLock($pdo, $lockName) === 1, 'First fixture connection must acquire its unique named lock.');
    $primaryLockOwned = true;
    database_engine_contract_assert($getLock($lockConnection, $lockName) === 0,
        'A second fixture connection must not acquire an already-owned named lock.');
    database_engine_contract_assert($releaseLock($pdo, $lockName) === 1, 'First fixture connection must release its named lock.');
    $primaryLockOwned = false;
    database_engine_contract_assert($getLock($lockConnection, $lockName) === 1,
        'Second fixture connection must acquire the named lock after release.');
    $secondaryLockOwned = true;
    database_engine_contract_assert($releaseLock($lockConnection, $lockName) === 1,
        'Second fixture connection must release its named lock.');
    $secondaryLockOwned = false;
} catch (\Throwable) {
    $failure = true;
} finally {
    if ($primaryLockOwned && $pdo instanceof \PDO && $lockName !== '') {
        try {
            $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lockName]);
        } catch (\Throwable) {
            $failure = true;
        }
    }
    if ($secondaryLockOwned && $lockConnection instanceof \PDO && $lockName !== '') {
        try {
            $statement = $lockConnection->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lockName]);
        } catch (\Throwable) {
            $failure = true;
        }
    }
    if ($pdo instanceof \PDO && $temporaryTable !== '') {
        try {
            $pdo->exec('DROP TEMPORARY TABLE IF EXISTS `' . $temporaryTable . '`');
        } catch (\Throwable) {
            $failure = true;
        }
    }
    $lockConnection = null;
    $pdo = null;
}

if ($failure) {
    fwrite(STDERR, 'FAIL database engine contract at ' . $stage . "\n");
    exit(1);
}
fwrite(STDOUT, "PASS database engine contract metadata JSON vote CHECK and named locks\n");
