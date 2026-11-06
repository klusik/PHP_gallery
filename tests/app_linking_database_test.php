<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_database_test.php
 * Module Type: Database Regression Test
 * Purpose: Exercise real one-time linking code and grant transitions.
 * Responsibilities:
 *   - Refuse a wrong proof without consuming the authorization code.
 *   - Require a single grant after exchange and reject a repeated exchange.
 *   - Check persisted hashes and owner revocation in an owned fixture.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Expose only the validated disposable workflow database.
     *
     * @return \PDO Owned workflow fixture connection.
     */
    function db(): \PDO
    {
        return $GLOBALS['app_link_test_db'];
    }
}

namespace {
    use function Gallery\Models\app_link_model_exchange;
    use function Gallery\Models\app_link_model_issue;
    use function Gallery\Models\app_link_model_lookup_token;
    use function Gallery\Models\app_link_model_revoke;
    use function Gallery\Models\app_link_push_model_installation_key;
    use function Gallery\Models\gallery_model_linked_client_page;
    use function Gallery\Models\gallery_model_linked_upload_target;
    use function Gallery\Services\upload_automation_store_images;
    use function GalleryWorkflow\check;
    use function GalleryWorkflow\fixtureDatabase;
    use function GalleryWorkflow\validateFixture;

    /**
     * Generate one isolated migration fixture database name.
     * Type: int. Units: random bytes. Scope: one owned database identity.
     * Consumers: random_bytes. Rationale: 12 bytes match the fixture's
     * required 24-character random database suffix.
     */
    const APP_LINK_SCOPE_FIXTURE_ID_BYTES = 12;

    /**
     * Distinguish the preexisting session timeout from the migration's limit.
     * Type: int. Units: seconds. Scope: one owned fixture connection.
     * Consumers: metadata-lock restoration check. Rationale: 42 seconds is
     * different from the five-second migration timeout and is never awaited.
     */
    const APP_LINK_SCOPE_FIXTURE_SESSION_TIMEOUT_SECONDS = 42;

    /**
     * Verify the migration's metadata-wait ceiling against observed sessions.
     * Type: int. Units: seconds. Scope: this migration regression fixture.
     * Consumers: strict session timeout check. Rationale: five matches the
     * migration policy while allowing an existing lower server limit.
     */
    const APP_LINK_SCOPE_FIXTURE_POLICY_MAX_SECONDS = 5;

    /**
     * Bound a metadata-lock contention fixture on a loaded CI host.
     * Type: int. Units: seconds. Scope: one owned timeout attempt.
     * Consumers: elapsed-time assertion. Rationale: 30 seconds accommodates
     * scheduling overhead around the five-second database metadata wait.
     */
    const APP_LINK_SCOPE_FIXTURE_METADATA_BOUND_SECONDS = 30;

    /** Observe the actual session timeout during fixture-owned scope DDL. */
    final class AppLinkScopeFixturePdo extends \PDO
    {
        /** @var list<int> Session timeout observed immediately before each ALTER. */
        public array $ddlTimeouts = [];

        /**
         * Execute fixture SQL and retain timeout evidence at each ALTER.
         *
         * @param string $statement Fixture-owned SQL statement.
         * @return int|false Affected row count, or false on driver failure.
         */
        public function exec(string $statement): int|false
        {
            if (str_starts_with($statement, 'ALTER TABLE')) {
                $query = parent::query('SELECT @@SESSION.lock_wait_timeout');
                if ($query === false) {
                    throw new \RuntimeException('Fixture timeout could not be inspected.');
                }
                $this->ddlTimeouts[] = (int) $query->fetchColumn();
            }
            return parent::exec($statement);
        }
    }

    /**
     * Exercise the real migration repeatedly in a separately owned database.
     *
     * @return void Verifies partial replay, bytes, conflicts and preflight.
     */
    function app_link_test_scope_migration(): void
    {
        $options = \GalleryWorkflow\databaseOptions(getenv());
        $name = 'gallery_workflow_' . bin2hex(random_bytes(APP_LINK_SCOPE_FIXTURE_ID_BYTES));
        \GalleryWorkflow\validateDatabaseName($name);
        $server = new \PDO(
            'mysql:host=127.0.0.1;port=' . $options['port'] . ';charset=utf8mb4',
            $options['user'], $options['password'],
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $created = false;
        try {
            $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
            $created = true;
            $fixture = new AppLinkScopeFixturePdo(
                'mysql:host=127.0.0.1;port=' . $options['port'] . ';dbname=' . $name . ';charset=utf8mb4',
                $options['user'], $options['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
            $tables = [
                'app_link_requests', 'app_link_codes', 'app_link_grants',
                'app_link_refresh_families',
            ];
            $scope = 'link:identity galleries:list gallery:upload offline_access';
            foreach ($tables as $table) {
                $fixture->exec('CREATE TABLE `' . $table
                    . '` (scope VARBINARY(64) NOT NULL) ENGINE=InnoDB');
                $insert = $fixture->prepare('INSERT INTO `' . $table . '` (scope) VALUES (?)');
                $insert->execute([$scope]);
            }
            $definition = require __DIR__ . '/../database/migrations/202611040004_app_linking_scope_capacity.php';
            $apply = $definition['after'];
            // Zero through four pre-expanded owners represent each interruption boundary.
            for ($prefix = 0; $prefix <= count($tables); ++$prefix) {
                foreach ($tables as $index => $table) {
                    $width = $index < $prefix ? 255 : 64;
                    $fixture->exec('ALTER TABLE `' . $table
                        . '` MODIFY COLUMN scope VARBINARY(' . $width . ') NOT NULL');
                }
                $apply($fixture);
                $apply($fixture);
                foreach ($tables as $table) {
                    $query = $fixture->prepare(
                        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
                            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'scope'",
                    );
                    $query->execute([$table]);
                    check(strtolower((string) $query->fetchColumn()) === 'varbinary(255)',
                        'Every scope owner must be widened after migration replay.');
                    check($fixture->query('SELECT scope FROM `' . $table . '`')->fetchColumn() === $scope,
                        'Scope migration must preserve exact existing bytes.');
                }
            }
            // A different connection may own the exact migration lease.
            $fixture->exec('ALTER TABLE `app_link_requests` MODIFY scope VARBINARY(64) NOT NULL');
            $other = new \PDO(
                'mysql:host=127.0.0.1;port=' . $options['port'] . ';dbname=' . $name . ';charset=utf8mb4',
                $options['user'], $options['password'],
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
            $lockName = hash('sha256', 'app-link-scope-capacity:' . $name);
            $lock = $other->prepare('SELECT GET_LOCK(?, 0)');
            $lock->execute([$lockName]);
            check((int) $lock->fetchColumn() === 1, 'Fixture must hold the migration lease.');
            try {
                $busy = false;
                try {
                    $apply($fixture);
                } catch (\Throwable) {
                    $busy = true;
                }
                check($busy, 'A competing migration must refuse rather than overlap.');
            } finally {
                $unlock = $other->prepare('SELECT RELEASE_LOCK(?)');
                $unlock->execute([$lockName]);
            }
            // An independent transaction holds a real metadata lock on the
            // first table. DDL must time out and restore the caller's setting.
            $fixture->exec('SET SESSION lock_wait_timeout = '
                . APP_LINK_SCOPE_FIXTURE_SESSION_TIMEOUT_SECONDS);
            $other->beginTransaction();
            try {
                $other->query('SELECT scope FROM app_link_requests')->fetchAll();
                $started = microtime(true);
                $blocked = false;
                try {
                    $apply($fixture);
                } catch (\Throwable) {
                    $blocked = true;
                }
                $elapsed = microtime(true) - $started;
                check($blocked && $elapsed < APP_LINK_SCOPE_FIXTURE_METADATA_BOUND_SECONDS,
                    'An owned metadata lock must bound migration admission.');
                check((int) $fixture->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn()
                    === APP_LINK_SCOPE_FIXTURE_SESSION_TIMEOUT_SECONDS,
                    'Failed DDL must restore the original session timeout.');
            } finally {
                if ($other->inTransaction()) {
                    $other->rollBack();
                }
            }
            $apply($fixture);
            check((int) $fixture->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn()
                === APP_LINK_SCOPE_FIXTURE_SESSION_TIMEOUT_SECONDS,
                'Successful replay must also restore the original session timeout.');
            // MariaDB may accept zero; servers that reject or normalize it use
            // their observed stricter value. Neither may be raised during DDL.
            try {
                $fixture->exec('SET SESSION lock_wait_timeout = 0');
            } catch (\PDOException) {
                $fixture->exec('SET SESSION lock_wait_timeout = 1');
            }
            $strictTimeout = (int) $fixture->query(
                'SELECT @@SESSION.lock_wait_timeout',
            )->fetchColumn();
            check($strictTimeout >= 0 && $strictTimeout <= APP_LINK_SCOPE_FIXTURE_POLICY_MAX_SECONDS,
                'Fixture must observe a stricter supported metadata timeout.');
            $fixture->exec('ALTER TABLE `app_link_requests` MODIFY scope VARBINARY(64) NOT NULL');
            $fixture->ddlTimeouts = [];
            $apply($fixture);
            check($fixture->ddlTimeouts === [$strictTimeout]
                && (int) $fixture->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn() === $strictTimeout,
                'Migration must retain a stricter supported timeout throughout DDL.');
            // An unexpected attribute is rejected before another owner changes.
            $fixture->exec('ALTER TABLE `app_link_requests` MODIFY scope VARBINARY(64) NOT NULL');
            $fixture->exec('ALTER TABLE `app_link_codes` MODIFY scope VARBINARY(64) NULL');
            $failed = false;
            try {
                $apply($fixture);
            } catch (\Throwable) {
                $failed = true;
            }
            $query = $fixture->query(
                "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
                    . "AND TABLE_NAME = 'app_link_requests' AND COLUMN_NAME = 'scope'",
            );
            check($failed && strtolower((string) $query->fetchColumn()) === 'varbinary(64)',
                'Incompatible metadata must fail before any scope owner changes.');
        } finally {
            if ($created) {
                $server->exec('DROP DATABASE `' . $name . '`');
            }
        }
    }

    require_once __DIR__ . '/support/gallery_workflow_safety.php';
    require_once __DIR__ . '/../app/policy_constants.php';
    require_once __DIR__ . '/../app/models/gallery_edit_concurrency.php';
    require_once __DIR__ . '/../app/services/gallery_edit_concurrency.php';
    require_once __DIR__ . '/../app/models/app_linking.php';
    require_once __DIR__ . '/../app/models/app_linking_push.php';
    require_once __DIR__ . '/../app/models/app_linking_refresh.php';
    require_once __DIR__ . '/../app/services/app_linking_refresh.php';
    require_once __DIR__ . '/../app/services/app_linking_recovery.php';
    require_once __DIR__ . '/../app/models/galleries.php';
    require_once __DIR__ . '/../app/models/images.php';
    require_once __DIR__ . '/../app/models/upload_automation.php';
    require_once __DIR__ . '/../app/services/app_linking.php';
    require_once __DIR__ . '/../app/services/upload_automation.php';
    require_once __DIR__ . '/../app/services/uploads.php';

    if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
        $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
        echo ($required ? 'BLOCKED' : 'SKIP')
            . " app linking requires an owned disposable database\n";
        exit($required ? 1 : 0);
    }

    $pdo = null;
    $userId = 0;
    $galleryId = 0;
    try {
        $fixtureToken = (string) getenv('GALLERY_WORKFLOW_TOKEN');
        $directory = validateFixture(
            (string) getenv('GALLERY_WORKFLOW_FIXTURE'),
            $fixtureToken,
        );
        $pdo = fixtureDatabase($directory, $fixtureToken);
        $GLOBALS['app_link_test_db'] = $pdo;
        $key = app_link_push_model_installation_key();
        // The storage contract is one complete 256-bit key after installation.
        check(is_string($key) && strlen($key) === 32, 'Install must seed the linking key.');
        $keyMigration = require __DIR__
            . '/../database/migrations/202611050002_app_linking_installation_key.php';
        $keyMigration['after']($pdo);
        check($key === app_link_push_model_installation_key(), 'Migration replay changed the linking key.');
        app_link_test_scope_migration();
        // The random owner belongs only to this test and is removed in finally.
        $username = 'link_test_' . bin2hex(random_bytes(12));
        $insert = $pdo->prepare(
            'INSERT INTO users (username, password_hash, role, created_at, updated_at) '
                . "VALUES (?, 'fixture-only', 'admin', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        );
        $insert->execute([$username]);
        $userId = (int) $pdo->lastInsertId();
        check($userId > 0, 'An owned Admin fixture is required.');

        $client = 'php-gallery-winapp';
        $issuer = 'https://gallery.example.test/Galerie';
        $scope = 'link:identity';
        $redirect = 'http://127.0.0.1:1234/oauth2redirect/php-gallery';
        // RFC 7636 appendix B vector; the database stores only the digest.
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
        $codeHash = hash('sha256', 'fixture-authorization-code');
        $tokenHash = hash('sha256', 'fixture-access-token');
        $codeId = app_link_model_issue(
            $userId,
            $client,
            $issuer,
            $scope,
            static fn(): string => $codeHash,
            $redirect,
            $challenge,
            \Gallery\Services\APP_LINKING_CODE_TTL_SECONDS,
        );
        check(is_int($codeId) && $codeId > 0, 'Authorization code was not issued.');
        $attempt = static fn(string $proof): ?array => app_link_model_exchange(
            $codeHash,
            $client,
            $issuer,
            $redirect,
            $proof,
            [$scope],
            static fn(): string => $tokenHash,
            \Gallery\Services\APP_LINKING_ACCESS_TTL_SECONDS,
        );
        check($attempt(str_repeat('A', 43)) === null, 'An incorrect proof must fail.');
        $grant = $attempt($challenge);
        check(is_array($grant) && !array_key_exists('refresh_expires_in', $grant),
            'Legacy exchange must succeed without issuing refresh credentials.');
        check($attempt($challenge) === null, 'An exchanged code must never be replayed.');

        $query = $pdo->prepare(
            'SELECT access_token_hash FROM app_link_grants WHERE user_id = ?',
        );
        $query->execute([$userId]);
        $hashes = $query->fetchAll(\PDO::FETCH_COLUMN);
        check($hashes === [$tokenHash], 'Exactly one grant must store only the token hash.');
        check(
            app_link_model_lookup_token($tokenHash, $issuer) !== null
                && app_link_model_lookup_token($tokenHash, 'https://other.example.test') === null,
            'Bearer lookup must remain bound to its issuing installation.',
        );
        check(
            app_link_model_revoke($userId, (int) $grant['grant_id'], null)
                && app_link_model_lookup_token($tokenHash, $issuer) === null,
            'Owner revocation must invalidate the bearer immediately.',
        );
        $listingScope = 'link:identity galleries:list';
        $listingCode = hash('sha256', 'fixture-listing-code');
        $listingToken = hash('sha256', 'fixture-listing-token');
        $listingId = app_link_model_issue(
            $userId, $client, $issuer, $listingScope,
            static fn(): string => $listingCode, $redirect, $challenge,
            \Gallery\Services\APP_LINKING_CODE_TTL_SECONDS,
        );
        check(is_int($listingId) && $listingId > 0, 'Listing code must be issued.');
        $exchangeListing = static fn(array $allowed): ?array => app_link_model_exchange(
            $listingCode, $client, $issuer, $redirect, $challenge, $allowed,
            static fn(): string => $listingToken,
            \Gallery\Services\APP_LINKING_ACCESS_TTL_SECONDS,
        );
        check($exchangeListing([$scope]) === null,
            'Listing permission must not be accepted as an identity-only grant.');
        $listingGrant = $exchangeListing([$scope, $listingScope]);
        check(is_array($listingGrant) && $listingGrant['scope'] === $listingScope
            && !array_key_exists('refresh_expires_in', $listingGrant),
            'Listing exchange must preserve scope without implicit refresh.');
        check($exchangeListing([$scope, $listingScope]) === null,
            'Listing authorization codes must also remain single use.');
        $stored = app_link_model_lookup_token($listingToken, $issuer);
        check(is_array($stored) && $stored['scope'] === $listingScope,
            'The issued token must retain the exact consented scope.');
        // The disposable gallery exists only for this owned fixture and is
        // private so its appearance requires the explicit Admin-list scope.
        $path = 'link_fixture_' . bin2hex(random_bytes(12));
        $insertGallery = $pdo->prepare(
            'INSERT INTO galleries (folder_path, folder_path_hash, slug, title, '
                . 'visibility, created_at, updated_at) '
                . "VALUES (?, ?, ?, ?, 'private', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
        );
        $insertGallery->execute([$path, hash('sha256', $path), $path, 'Private fixture']);
        $galleryId = (int) $pdo->lastInsertId();
        check($galleryId > 0, 'An owned gallery fixture is required.');
        $page = gallery_model_linked_client_page(
            (int) $listingGrant['grant_id'], $userId, $listingToken, $issuer,
            $listingScope, $galleryId - 1, 2,
        );
        check(isset($page[0]) && (int) $page[0]['id'] === $galleryId
            && $page[0]['title'] === 'Private fixture'
            && !array_key_exists('folder_path', $page[0]),
            'Scoped list must include the private gallery without its path.');
        check(gallery_model_linked_client_page(
            (int) $listingGrant['grant_id'], $userId, $listingToken,
            'https://other.example.test', $listingScope, $galleryId - 1, 2,
        ) === null, 'A different issuer must never list galleries.');
        check(gallery_model_linked_client_page(
            (int) $listingGrant['grant_id'], $userId, $listingToken,
            $issuer, $scope, $galleryId - 1, 2,
        ) === null, 'A mismatched scope must never list galleries.');
        $uploadScope = 'link:identity galleries:list gallery:upload';
        check(gallery_model_linked_upload_target(
            (int) $listingGrant['grant_id'], $userId, $listingToken,
            $issuer, $uploadScope, $galleryId,
        ) === null, 'A list-only grant must never authorize upload.');
        check(gallery_model_linked_client_page(
            (int) $listingGrant['grant_id'], $userId, $listingToken,
            $issuer, $listingScope, PHP_INT_MAX, 2,
        ) === [], 'A live grant with no remaining galleries must yield an empty page.');
        // Simulate revocation after a successful initial bearer lookup and
        // before the listing query; the second read must refuse, not say empty.
        check(app_link_model_lookup_token($listingToken, $issuer) !== null,
            'The grant must be live before the revocation boundary.');
        check(app_link_model_revoke($userId, (int) $listingGrant['grant_id'], null)
            && gallery_model_linked_client_page(
                (int) $listingGrant['grant_id'], $userId, $listingToken,
                $issuer, $listingScope, $galleryId - 1, 2,
            ) === null, 'A revoked grant must be refused, not reported as empty.');
        $uploadCode = hash('sha256', 'fixture-upload-code');
        $uploadToken = hash('sha256', 'fixture-upload-token');
        $uploadId = app_link_model_issue(
            $userId, $client, $issuer, $uploadScope,
            static fn(): string => $uploadCode, $redirect, $challenge,
            \Gallery\Services\APP_LINKING_CODE_TTL_SECONDS,
        );
        check(is_int($uploadId) && $uploadId > 0, 'Upload-scoped code must be issued.');
        $uploadGrant = app_link_model_exchange(
            $uploadCode, $client, $issuer, $redirect, $challenge,
            [$scope, $listingScope, $uploadScope],
            static fn(): string => $uploadToken,
            \Gallery\Services\APP_LINKING_ACCESS_TTL_SECONDS,
        );
        check(is_array($uploadGrant) && $uploadGrant['scope'] === $uploadScope,
            'Upload grant must preserve the exact consented scope.');
        $grantId = (int) $uploadGrant['grant_id'];
        $target = gallery_model_linked_upload_target(
            $grantId, $userId, $uploadToken, $issuer, $uploadScope, $galleryId,
        );
        check(is_array($target) && (int) $target['id'] === $galleryId,
            'Explicitly scoped grant must resolve its actual gallery target.');
        check(gallery_model_linked_upload_target(
            $grantId, $userId, $uploadToken, 'https://other.example.test',
            $uploadScope, $galleryId,
        ) === null && gallery_model_linked_upload_target(
            $grantId, $userId + 1, $uploadToken, $issuer, $uploadScope, $galleryId,
        ) === null && gallery_model_linked_upload_target(
            $grantId, $userId, $uploadToken, $issuer, $uploadScope, PHP_INT_MAX,
        ) === null, 'Wrong issuer, owner or gallery must fail closed.');
        check(app_link_model_revoke($userId, $grantId, null)
            && gallery_model_linked_upload_target(
                $grantId, $userId, $uploadToken, $issuer, $uploadScope, $galleryId,
            ) === null, 'Revocation must remove upload authority immediately.');
        check(\Gallery\Services\app_linking_recovery_probe(
            $galleryId,
            $target,
            hash('sha256', 'fixture-content'),
            1,
            static fn(): ?array => gallery_model_linked_upload_target(
                $grantId, $userId, $uploadToken, $issuer, $uploadScope, $galleryId,
            ),
        ) === null, 'A revoked grant must not receive recovery evidence.');
        // The real lock owner executes the guard after acquisition and before
        // storage. A revoked principal must never reach the upload workflow.
        $guardCalled = false;
        $guardHeldLock = false;
        $guardHeldWriter = false;
        $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $writerName = hash('sha256', $databaseName . \Gallery\Core\GALLERY_EDIT_LOCK_SUFFIX);
        try {
            upload_automation_store_images(
                $galleryId, $target, [], [], [], null,
                static function () use (
                    $pdo, $galleryId, $writerName, &$guardCalled,
                    &$guardHeldLock, &$guardHeldWriter,
                ): bool {
                    $guardCalled = true;
                    $lock = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
                    $lock->execute(['php_gallery_upload_automation_' . $galleryId]);
                    $guardHeldLock = (int) $lock->fetchColumn() === 1;
                    $lock->execute([$writerName]);
                    $guardHeldWriter = (int) $lock->fetchColumn() === 1;
                    return false;
                },
            );
            check(false, 'A refused guard must prevent the upload.');
        } catch (\RuntimeException $exception) {
            check($guardCalled && $guardHeldLock && $guardHeldWriter
                && $exception->getMessage() === 'Upload authority is unavailable.',
                'Authorization must be rechecked under both writer and gallery locks.');
        }
        $released = $pdo->prepare('SELECT IS_USED_LOCK(?)');
        $released->execute([$writerName]);
        check($released->fetchColumn() === null,
            'A refused guard must release the global writer lease.');
        $countImages = $pdo->prepare('SELECT COUNT(*) FROM images WHERE gallery_id = ?');
        $countImages->execute([$galleryId]);
        check((int) $countImages->fetchColumn() === 0,
            'A refused upload guard must not create any image.');
        // Duplicate image rows must not expand or truncate delegated matches.
        $firstHash = hash('sha256', 'bounded-inventory-one');
        $secondHash = hash('sha256', 'bounded-inventory-two');
        $nestedHash = hash('sha256', 'bounded-inventory-nested');
        $insertImage = $pdo->prepare(
            'INSERT INTO images (gallery_id, relative_path, relative_path_hash, '
                . 'filename, file_size, checksum_sha256, created_at, updated_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
        );
        foreach ([
            ['first.jpg', $firstHash],
            ['duplicate.jpg', $firstHash],
            ['second.jpg', $secondHash],
            ['nested/other.jpg', $nestedHash],
        ] as [$relative, $checksum]) {
            $insertImage->execute([
                $galleryId, $relative, hash('sha256', $relative),
                basename($relative), 1, $checksum,
            ]);
        }
        $matches = \Gallery\Models\image_model_direct_rows_by_checksums_bounded(
            $galleryId, [$firstHash, $secondHash, $nestedHash],
        );
        $byHash = [];
        foreach ($matches as $match) {
            $byHash[(string) $match['checksum_sha256']] = $match['filename'];
        }
        check(count($matches) === 2 && ($byHash[$firstHash] ?? null) === 'first.jpg'
            && ($byHash[$secondHash] ?? null) === 'second.jpg'
            && !isset($byHash[$nestedHash]),
            'Bounded lookup must include every direct hash exactly once.');
        $tooManyHashes = [];
        for ($index = 0;
            $index <= \Gallery\Models\IMAGE_MODEL_DELEGATED_INVENTORY_HASH_LIMIT;
            $index++) {
            $tooManyHashes[] = hash('sha256', 'bounded-inventory-' . $index);
        }
        try {
            \Gallery\Models\image_model_direct_rows_by_checksums_bounded(
                $galleryId, $tooManyHashes,
            );
            check(false, 'Over-limit inventory must not return a partial result.');
        } catch (\InvalidArgumentException) {
            // An oversized candidate set is refused before database access.
        }
        // Reclaim an old legacy-only grant under confirmed-absent semantics.
        // Its family is absent, so this does not bypass a real family row.
        $ageLegacy = $pdo->prepare(
            "UPDATE app_link_grants SET revoked_at = '2000-01-01 00:00:00' WHERE id = ?",
        );
        $ageLegacy->execute([(int) $grant['grant_id']]);
        $pdo->beginTransaction();
        $lockLegacyOwner = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lockLegacyOwner->execute([$userId]);
        check(!\Gallery\Models\app_link_model_reclaim_one(
            $pdo, $userId, [(int) $grant['grant_id']], 'unknown',
        ) && \Gallery\Models\app_link_model_reclaim_one(
            $pdo, $userId, [(int) $grant['grant_id']], 'absent',
        ), 'Unknown inspection must stop reclaim; confirmed absence permits legacy reclaim.');
        $pdo->commit();

        // Offline consent and initial family insertion share one transaction.
        $offlineScope = 'link:identity offline_access';
        $offlineCode = hash('sha256', 'fixture-offline-code');
        $offlineAccess = hash('sha256', 'fixture-offline-access');
        $refreshOne = hash('sha256', 'fixture-offline-refresh-one');
        $refreshTwo = hash('sha256', 'fixture-offline-refresh-two');
        $accessTwo = hash('sha256', 'fixture-offline-access-two');
        $offlineCodeId = app_link_model_issue(
            $userId, $client, $issuer, $offlineScope,
            static fn(): string => $offlineCode, $redirect, $challenge,
            \Gallery\Services\APP_LINKING_CODE_TTL_SECONDS,
        );
        check(is_int($offlineCodeId), 'Offline authorization code must be issued.');
        $exchangeOffline = static fn(callable $refreshFactory): ?array =>
            app_link_model_exchange(
                $offlineCode, $client, $issuer, $redirect, $challenge,
                [$offlineScope], static fn(): string => $offlineAccess,
                \Gallery\Services\APP_LINKING_ACCESS_TTL_SECONDS,
                [$offlineScope], $refreshFactory, 'ready',
                \Gallery\Services\APP_LINK_REFRESH_FAMILY_TTL_SECONDS,
                \Gallery\Services\APP_LINK_REFRESH_IDLE_TTL_SECONDS,
                $offlineScope,
            );
        check(app_link_model_exchange(
            $offlineCode, $client, $issuer, $redirect, $challenge,
            [$offlineScope], static fn(): string => $offlineAccess,
            \Gallery\Services\APP_LINKING_ACCESS_TTL_SECONDS,
        ) === null, 'Offline scope must not issue without explicit refresh admission.');
        try {
            $exchangeOffline(static function (): string {
                throw new \RuntimeException('Fixture refresh generation failure.');
            });
            check(false, 'A failed refresh issue must abort exchange.');
        } catch (\RuntimeException $exception) {
            check($exception->getMessage() === 'Fixture refresh generation failure.',
                'Only the injected issuance failure is expected.');
        }
        $pending = $pdo->prepare('SELECT consumed_at FROM app_link_codes WHERE id = ?');
        $pending->execute([$offlineCodeId]);
        check($pending->fetchColumn() === null
            && app_link_model_lookup_token($offlineAccess, $issuer) === null,
            'Rollback must preserve the code and remove the provisional grant.');
        $offlineGrant = $exchangeOffline(static fn(): string => $refreshOne);
        check(is_array($offlineGrant) && $offlineGrant['scope'] === $offlineScope
            && $offlineGrant['refresh_expires_in'] > 0
            && $offlineGrant['refresh_expires_in'] <=
                \Gallery\Services\APP_LINK_REFRESH_IDLE_TTL_SECONDS,
            'First refresh must preserve consent and report the idle bound.');
        $offlineId = (int) $offlineGrant['grant_id'];
        $storedRefresh = $pdo->prepare(
            'SELECT token_hash FROM app_link_refresh_tokens WHERE grant_id = ?',
        );
        $storedRefresh->execute([$offlineId]);
        check($storedRefresh->fetchAll(\PDO::FETCH_COLUMN) === [$refreshOne],
            'The refresh table must store only the generated digest.');

        // An expired access token must not make its live family reclaimable.
        $expireAccess = $pdo->prepare(
            "UPDATE app_link_grants SET access_expires_at = '2000-01-01 00:00:00' WHERE id = ?",
        );
        $expireAccess->execute([$offlineId]);
        $pdo->beginTransaction();
        $lockUser = $pdo->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $lockUser->execute([$userId]);
        check(!\Gallery\Models\app_link_model_reclaim_one(
            $pdo, $userId, [$offlineId], 'unknown',
        ) && !\Gallery\Models\app_link_model_reclaim_one(
            $pdo, $userId, [$offlineId], 'ready',
        ), 'Unknown schema and an active family must prevent reclaim.');
        $pdo->commit();

        $rotate = static fn(string $value, string $authority, string $clientId): ?array =>
            \Gallery\Models\app_link_refresh_model_rotate(
                $value, $clientId, $authority, [$offlineScope],
                static fn(): array => [
                    'access_hash' => $accessTwo, 'refresh_hash' => $refreshTwo,
                ],
                \Gallery\Services\APP_LINKING_ACCESS_TTL_SECONDS,
                \Gallery\Services\APP_LINK_REFRESH_IDLE_TTL_SECONDS,
            );
        check($rotate($refreshOne, 'https://other.example.test', $client) === null
            && $rotate($refreshOne, $issuer, 'other-client') === null,
            'Wrong issuer or client must not consume a refresh token.');
        $rotated = $rotate($refreshOne, $issuer, $client);
        check(is_array($rotated) && $rotated['status'] === 'rotated'
            && $rotated['scope'] === $offlineScope
            && $rotated['refresh_expires_in'] > 0
            && $rotated['refresh_expires_in'] <=
                \Gallery\Services\APP_LINK_REFRESH_IDLE_TTL_SECONDS
            && app_link_model_lookup_token($accessTwo, $issuer) !== null,
            'Rotation must issue a scoped bearer with bounded refresh lifetime.');
        $history = $pdo->prepare(
            'SELECT token_hash, active_slot, consumed_at FROM app_link_refresh_tokens '
                . 'WHERE grant_id = ? ORDER BY id',
        );
        $history->execute([$offlineId]);
        $rows = $history->fetchAll(\PDO::FETCH_ASSOC);
        check(count($rows) === 2 && $rows[0]['token_hash'] === $refreshOne
            && $rows[0]['active_slot'] === null && $rows[0]['consumed_at'] !== null
            && $rows[1]['token_hash'] === $refreshTwo
            && (int) $rows[1]['active_slot'] === 1,
            'Rotation must consume the previous hash and keep one active hash.');
        $replay = $rotate($refreshOne, $issuer, $client);
        check(is_array($replay) && $replay['status'] === 'replayed'
            && app_link_model_lookup_token($accessTwo, $issuer) === null
            && $rotate($refreshTwo, $issuer, $client) === null,
            'Replay must revoke the entire family and its active bearer.');

        // Revoke a separate family even after its idle deadline has elapsed.
        $expiredCode = hash('sha256', 'fixture-expired-offline-code');
        $expiredAccess = hash('sha256', 'fixture-expired-offline-access');
        $expiredRefresh = hash('sha256', 'fixture-expired-offline-refresh');
        $expiredId = app_link_model_issue(
            $userId, $client, $issuer, $offlineScope,
            static fn(): string => $expiredCode, $redirect, $challenge,
            \Gallery\Services\APP_LINKING_CODE_TTL_SECONDS,
        );
        check(is_int($expiredId), 'Expiry fixture code must be issued.');
        $expiredGrant = app_link_model_exchange(
            $expiredCode, $client, $issuer, $redirect, $challenge, [$offlineScope],
            static fn(): string => $expiredAccess,
            \Gallery\Services\APP_LINKING_ACCESS_TTL_SECONDS,
            [$offlineScope], static fn(): string => $expiredRefresh, 'ready',
            \Gallery\Services\APP_LINK_REFRESH_FAMILY_TTL_SECONDS,
            \Gallery\Services\APP_LINK_REFRESH_IDLE_TTL_SECONDS, $offlineScope,
        );
        check(is_array($expiredGrant), 'Expiry fixture grant must be issued.');
        $expireFamily = $pdo->prepare(
            "UPDATE app_link_refresh_families SET idle_expires_at = '2000-01-01 00:00:00' WHERE grant_id = ?",
        );
        $expireFamily->execute([(int) $expiredGrant['grant_id']]);
        check($rotate($expiredRefresh, $issuer, $client) === null,
            'Inactivity expiry must refuse rotation.');
        $expireAbsolute = $pdo->prepare(
            "UPDATE app_link_refresh_families SET expires_at = '2000-01-01 00:00:00', "
                . 'idle_expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND) '
                . 'WHERE grant_id = ?',
        );
        $expireAbsolute->execute([
            \Gallery\Services\APP_LINK_REFRESH_IDLE_TTL_SECONDS,
            (int) $expiredGrant['grant_id'],
        ]);
        check($rotate($expiredRefresh, $issuer, $client) === null
            && !\Gallery\Models\app_link_refresh_model_revoke_token(
                $expiredRefresh, $client, 'https://other.example.test',
            )
            && \Gallery\Models\app_link_refresh_model_revoke_token(
                $expiredRefresh, $client, $issuer,
            )
            && app_link_model_lookup_token($expiredAccess, $issuer) === null,
            'Expired refresh must refuse rotation but permit its holder to revoke.');
        // An old revoked grant may release its still-marked-active family.
        $restoreFamilyTimes = $pdo->prepare(
            'UPDATE app_link_refresh_families SET revoked_at = NULL, '
                . 'expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), '
                . 'idle_expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND) '
                . 'WHERE grant_id = ?',
        );
        $restoreFamilyTimes->execute([
            \Gallery\Services\APP_LINK_REFRESH_FAMILY_TTL_SECONDS,
            \Gallery\Services\APP_LINK_REFRESH_IDLE_TTL_SECONDS,
            (int) $expiredGrant['grant_id'],
        ]);
        $ageLegacy->execute([(int) $expiredGrant['grant_id']]);
        $pdo->beginTransaction();
        $lockUser->execute([$userId]);
        check(!\Gallery\Models\app_link_model_reclaim_one(
            $pdo, $userId, [(int) $expiredGrant['grant_id']], 'unknown',
        ) && \Gallery\Models\app_link_model_reclaim_one(
            $pdo, $userId, [(int) $expiredGrant['grant_id']], 'ready',
        ), 'Only verified storage permits reclaim of an old revoked grant.');
        $pdo->commit();
        $storedRefresh->execute([(int) $expiredGrant['grant_id']]);
        check($storedRefresh->fetchAll(\PDO::FETCH_COLUMN) === [],
            'Reclaim of an old revoked grant must cascade to its family.');
        echo "app linking database transition tests passed\n";
    } finally {
        // Do not roll back fixture deletion together with a failed assertion's
        // still-open reclaim transaction when the connection closes.
        if ($pdo instanceof \PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($pdo instanceof \PDO && $galleryId > 0) {
            $deleteGallery = $pdo->prepare('DELETE FROM galleries WHERE id = ? AND slug = ?');
            $deleteGallery->execute([$galleryId, $path]);
        }
        if ($pdo instanceof \PDO && $userId > 0) {
            $delete = $pdo->prepare('DELETE FROM users WHERE id = ? AND username = ?');
            $delete->execute([$userId, $username]);
        }
    }
}
