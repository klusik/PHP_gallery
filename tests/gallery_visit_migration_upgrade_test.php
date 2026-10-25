<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_visit_migration_upgrade_test.php
 * Module Type: Upgrade Integration Test
 * Purpose: Execute the real content migration across a loaded-old-model swap.
 * Responsibilities: Detect undefined callbacks and verify SQL seed/replay safety
 *   using a private application copy and an explicit isolated PDO connection.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_safety.php';

use function GalleryWorkflow\check;
use function GalleryWorkflow\databaseOptions;
use function GalleryWorkflow\removeFixture;
use function GalleryWorkflow\validateDatabaseName;

/**
 * Match the shared fixture owner's fixed 24-hex-character directory identity.
 * Type: int. Units: random bytes. Scope: this disposable application copy.
 * Consumers: bin2hex and removeFixture ownership verification.
 * Rationale: 12 bytes produce the safety helper's required 24 hex characters.
 */
const VISIT_UPGRADE_TOKEN_BYTES = 12;
/**
 * Keep the disposable model copy private to the executing test account.
 * Type: int. Units: POSIX mode bits. Scope: this test's owned directories.
 * Consumers: mkdir. Rationale: 0700 prevents unrelated accounts traversing data.
 */
const VISIT_UPGRADE_DIRECTORY_MODE = 0700;

/** Execute real fixture SQL with only SQLite duplicate-key spelling adapted. */
final class VisitMigrationUpgradePdo extends PDO
{
    /**
     * Create an isolated database without reading installation configuration.
     * @param string $dsn In-memory SQLite or validated fixture-only MySQL DSN.
     * @param string $username Disposable database account, empty for SQLite.
     * @param string $password Disposable database password, empty for SQLite.
     * @return void Initializes the explicit connection and SQLite-only adapter.
     */
    public function __construct(
        string $dsn,
        string $username = '',
        string $password = '',
    ) {
        parent::__construct($dsn, $username, $password);
        $this->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->sqliteCreateFunction('CONCAT',
                static fn(string|int|null ...$parts): string => implode('', $parts));
        }
    }

    /**
     * Execute actual seed SQL with SQLite's equivalent duplicate-key spelling.
     * @param string $statement Model-owned SQL, never installation input.
     * @return int|false Affected rows, or false on a non-exception driver failure.
     */
    public function exec(string $statement): int|false
    {
        return parent::exec($this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $statement) : $statement);
    }

    /**
     * Prepare actual model SQL with only duplicate-key spelling adapted.
     * @param string $query Model-owned parameterized SQL.
     * @param array{} $options Empty driver options used by this fixed fixture.
     * @return PDOStatement|false Native statement, or false on driver failure.
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? str_replace('INSERT IGNORE', 'INSERT OR IGNORE', $query) : $query, $options);
    }
}

/**
 * Build populated SQL inputs reaching photo, Smart, PDF and music seed branches.
 * @param array{host:string,port:int,user:string,password:string,charset:string}|null
 *     $options Validated disposable database transport, null for source-only SQLite.
 * @param PDO|null $server Explicit fixture-only server, null for SQLite.
 * @param list<string> $ownedDatabases Created database names retained for cleanup.
 * @return VisitMigrationUpgradePdo Explicit private PDO with no runtime db owner.
 */
function visit_upgrade_database(
    ?array $options,
    ?PDO $server,
    array &$ownedDatabases,
): VisitMigrationUpgradePdo {
    if ($options !== null && $server !== null) {
        $database = 'gallery_workflow_' . bin2hex(random_bytes(VISIT_UPGRADE_TOKEN_BYTES));
        validateDatabaseName($database);
        $server->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4');
        $ownedDatabases[] = $database;
        $pdo = new VisitMigrationUpgradePdo('mysql:host=127.0.0.1;port='
            . $options['port'] . ';dbname=' . $database . ';charset=utf8mb4',
            $options['user'], $options['password']);
    } else {
        $pdo = new VisitMigrationUpgradePdo('sqlite::memory:');
    }
    // Two physical galleries, two images and one Smart definition are structural
    // ownership vectors, not operational size policies.
    foreach ([
        'CREATE TABLE galleries (id INTEGER PRIMARY KEY,parent_id INTEGER,'
            . 'visibility TEXT,access_listing TEXT,nsfw_enabled INTEGER)',
        'CREATE TABLE images (id INTEGER PRIMARY KEY,gallery_id INTEGER,'
            . 'visibility TEXT,nsfw_enabled INTEGER)',
        'CREATE TABLE smart_galleries (id INTEGER PRIMARY KEY,rules_json TEXT,'
            . 'enabled INTEGER,visibility TEXT,placement_mode TEXT)',
        'CREATE TABLE smart_gallery_placements (smart_gallery_id INTEGER,gallery_id INTEGER)',
        'CREATE TABLE gallery_attachment_usage_state (gallery_id INTEGER,kind TEXT,state TEXT)',
        'CREATE TABLE gallery_attachment_usage_items (gallery_id INTEGER,kind TEXT,'
            . 'hidden INTEGER,sha256 TEXT,asset_id TEXT,album_id INTEGER)',
        'CREATE TABLE music_album_songs (album_id INTEGER,song_id INTEGER)',
        'CREATE TABLE music_repository_assets (id TEXT,song_id INTEGER,sha256 TEXT,created_at TEXT)',
        'CREATE TABLE gallery_visit_content_state (id INTEGER PRIMARY KEY,'
            . 'generation INTEGER,revision INTEGER,dirty INTEGER)',
        'CREATE TABLE gallery_visit_content_pending (scope_key VARCHAR(32) PRIMARY KEY)',
        'CREATE TABLE gallery_visit_content_members (node_key VARCHAR(32),item_key VARCHAR(128),'
            . 'lane_key VARCHAR(48),revision INTEGER,PRIMARY KEY(node_key,item_key,lane_key))',
        "INSERT INTO galleries VALUES (1,NULL,'public','listed',0),(2,1,'public','listed',0)",
        "INSERT INTO images VALUES (1,1,'public',0),(2,2,'public',1)",
        'INSERT INTO gallery_visit_content_state VALUES (1,1,0,1)',
        "INSERT INTO gallery_visit_content_pending VALUES ('g:1')",
        "INSERT INTO gallery_attachment_usage_state VALUES (1,'pdf','ready'),"
            . "(1,'music','ready'),(2,'pdf','ready'),(2,'music','ready')",
        // Digest labels identify fixture media; they never refer to real files.
        "INSERT INTO gallery_attachment_usage_items VALUES (1,'pdf',0,'pdf-digest',NULL,NULL),"
            . "(2,'music',0,'music-digest',NULL,NULL)",
    ] as $statement) {
        $pdo->exec($statement);
    }
    $insert = $pdo->prepare('INSERT INTO smart_galleries VALUES (1,?,1,?,?)');
    $insert->execute([json_encode(['version' => 1, 'root' => [
        'type' => 'group', 'operator' => 'AND', 'children' => [],
    ]], JSON_THROW_ON_ERROR), 'public', 'root']);
    return $pdo;
}

$root = dirname(__DIR__);
$options = getenv('GALLERY_WORKFLOW_FIXTURE') ? databaseOptions(getenv()) : null;
$token = bin2hex(random_bytes(VISIT_UPGRADE_TOKEN_BYTES));
$directory = sys_get_temp_dir() . '/gallery-workflow-' . $token;
check(mkdir($directory, VISIT_UPGRADE_DIRECTORY_MODE), 'Cannot allocate upgrade fixture.');
check(file_put_contents($directory . '/.workflow-owner', $token) === strlen($token),
    'Cannot mark upgrade fixture ownership.');
$server = null;
$ownedDatabases = [];
try {
    if ($options !== null) {
        $server = new PDO('mysql:host=127.0.0.1;port='
            . $options['port'] . ';charset=utf8mb4', $options['user'], $options['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
    foreach (['app/models', 'database/migrations'] as $relative) {
        check(mkdir($directory . '/' . $relative, VISIT_UPGRADE_DIRECTORY_MODE, true),
            'Cannot allocate confined upgrade source directories.');
    }
    $modelPath = $directory . '/app/models/galleries.php';
    check(copy(__DIR__ . '/fixtures/gallery_visit_migration_legacy_model.php', $modelPath),
        'Cannot stage old runtime model.');
    require_once $modelPath;
    check(!function_exists('Gallery\\Models\\gallery_visit_content_model_seed'),
        'The legacy model must predate the missing seed entrypoint.');
    check(copy($root . '/app/models/galleries.php', $modelPath),
        'Cannot replace the model bytes during activation.');
    require_once $modelPath;
    check(!function_exists('Gallery\\Models\\gallery_visit_content_model_seed'),
        'Replacing a file must not reload an already included PHP model.');

    // Negative control reproduces the user's exact pre-fix callback failure.
    $undefinedDetected = false;
    try {
        \Gallery\Models\gallery_visit_content_model_seed(new VisitMigrationUpgradePdo('sqlite::memory:'));
    } catch (Error $error) {
        $undefinedDetected = strtoupper(substr(hash('sha256', $error->getMessage()), 0, 12))
            === 'F05D71A153A2'; // Existing updater reference format and reported failure.
    }
    check($undefinedDetected, 'The upgrade harness must detect the original undefined function.');

    foreach (['app/models/gallery_visit_content_migration.php',
        'app/models/smart_galleries.php',
        'database/migrations/202610250001_gallery_visit_content.php'] as $relative) {
        check(copy($root . '/' . $relative, $directory . '/' . $relative),
            'Cannot stage current migration dependencies.');
    }
    $migration = require $directory
        . '/database/migrations/202610250001_gallery_visit_content.php';
    check(is_callable($migration['after'] ?? null), 'The real migration callback is missing.');
    $pdo = visit_upgrade_database($options, $server, $ownedDatabases);
    $migration['after']($pdo);
    check((int) $pdo->query('SELECT revision FROM gallery_visit_content_state')->fetchColumn() === 1,
        'The real callback must publish its explicit database seed with the old model loaded.');
    foreach (['g:1', 'g:2', 's:1', 'r:0'] as $key) {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM gallery_visit_content_members WHERE node_key=?');
        $statement->execute([$key]);
        check((int) $statement->fetchColumn() > 0, 'A populated seed branch was not executed.');
    }
    // Exactly two independent media identities must survive both physical and
    // virtual placements; counting distinct identities avoids double counting.
    check((int) $pdo->query("SELECT COUNT(DISTINCT item_key) FROM gallery_visit_content_members "
        . "WHERE item_key IN ('pdf:pdf-digest','music:music-digest')")->fetchColumn() === 2,
        'Attachment SQL must use the explicit PDO instead of the poisoned old adapter.');
    $snapshot = $pdo->query('SELECT * FROM gallery_visit_content_members ORDER BY node_key,item_key,lane_key')
        ->fetchAll(PDO::FETCH_ASSOC);
    $migration['after']($pdo);
    check($snapshot === $pdo->query(
        'SELECT * FROM gallery_visit_content_members ORDER BY node_key,item_key,lane_key',
    )->fetchAll(PDO::FETCH_ASSOC), 'A completed seed must remain unchanged on replay.');

    require __DIR__ . '/fixtures/gallery_visit_migration_previous_seed.php';
    $previous = visit_upgrade_database($options, $server, $ownedDatabases);
    $migration['after']($previous);
    check((int) $previous->query('SELECT revision FROM gallery_visit_content_state')->fetchColumn() === 1,
        'An already loaded previous seed must neither collide with nor replace the migration owner.');

    $dirty = visit_upgrade_database($options, $server, $ownedDatabases);
    $dirty->exec("UPDATE gallery_attachment_usage_state SET state='dirty' WHERE kind='pdf'");
    $migration['after']($dirty);
    check((int) $dirty->query('SELECT revision FROM gallery_visit_content_state')->fetchColumn() === 0
        && !$dirty->inTransaction(), 'Unfinished optional evidence must roll back and remain dirty.');

    $broken = visit_upgrade_database($options, $server, $ownedDatabases);
    $broken->exec('DROP TABLE music_repository_assets');
    $refused = false;
    try {
        $migration['after']($broken);
    } catch (PDOException) {
        $refused = true;
    }
    check($refused && !$broken->inTransaction()
        && (int) $broken->query('SELECT COUNT(*) FROM gallery_visit_content_members')->fetchColumn() === 0,
        'SQL failures must propagate and roll back, never masquerade as optional readiness.');
    echo "PASS populated visitor migration after loaded-model activation, undefined negative control, replay and recovery\n";
} finally {
    foreach ($ownedDatabases as $database) {
        validateDatabaseName($database);
        $server?->exec('DROP DATABASE `' . $database . '`');
    }
    removeFixture($directory, $token);
}
