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
    use function Gallery\Models\app_link_model_watch_key_open;
    use function Gallery\Models\app_link_model_watch_key_close;
    use function Gallery\Models\app_link_model_watch_key_valid;
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

    /**
     * Model an ordinary rapid screenshot capture below the new storage ceiling.
     * Type: int. Units: originals per client in one minute. Scope: FIFO fixture.
     * Consumers: app_link_test_fifo_rate_budgets.
     * Rationale: 45 exceeds the historical thirty-image shared-budget boundary
     * while leaving explicit head-reconciliation and multi-client allowance.
     */
    const APP_LINK_FIFO_FIXTURE_ORDINARY_ORIGINALS = 45;

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

    /**
     * Verify actual database rate windows against ordinary FIFO request budgets.
     *
     * @param \PDO $pdo Validated disposable workflow database connection.
     * @param string $owner Random fixture identity used only for owned rate rows.
     * @return void Confirms old exhaustion, new dual-client budgets and exact release.
     */
    function app_link_test_fifo_rate_budgets(\PDO $pdo, string $owner): void
    {
        $prefix = 'fifo_' . substr(hash('sha256', $owner), 0, 16);
        $owned = [];
        $clock = time();
        $policies = \Gallery\Services\APP_LINKING_RATE_POLICIES;
        $consume = static function (
            string $policyName,
            string $subject,
            int $timestamp,
            int $cost = 1,
        ) use (&$owned, $prefix, $policies): array {
            $bucket = $prefix . '_' . $policyName;
            $owned[$bucket] = true;
            return \Gallery\Models\app_link_model_rate_limit_consume(
                $bucket,
                hash('sha256', $subject),
                $policies[$policyName],
                \Gallery\Services\APP_LINKING_RATE_SUBJECT_CAP,
                \Gallery\Services\APP_LINKING_RATE_CLEANUP_BATCH,
                date('Y-m-d H:i:s', $timestamp - \Gallery\Services\APP_LINKING_RATE_STALE_SECONDS),
                date('Y-m-d H:i:s', $timestamp),
                $timestamp,
                $cost,
            );
        };
        try {
            // Two clients behind one IP, each with 45 originals, 45 inventory
            // checks and two safe extra reconciliation checks. Each route is
            // charged separately; no UI-log copy becomes another request.
            $clients = ['first', 'second'];
            foreach ($clients as $client) {
                for ($index = 0; $index < APP_LINK_FIFO_FIXTURE_ORDINARY_ORIGINALS; $index++) {
                    foreach (['inventory', 'upload'] as $operation) {
                        foreach ([
                            'app_link_transfer_ip' => 'same-ip',
                            'app_link_transfer_global' => 'global',
                            'app_link_' . $operation . '_binding' => $client,
                            'app_link_' . $operation . '_account' => 'same-account',
                            'app_link_' . $operation . '_ip' => 'same-ip',
                            'app_link_' . $operation . '_global' => 'global',
                        ] as $policyName => $subject) {
                            check($consume($policyName, $subject, $clock)['allowed'],
                                'Ordinary serial FIFO budget was throttled.');
                        }
                    }
                }
                foreach (['watch_open', 'watch_close', 'reconcile_a', 'reconcile_a_again'] as $control) {
                    check($consume('app_link_transfer_ip', 'same-ip', $clock)['allowed']
                        && $consume('app_link_transfer_global', 'global', $clock)['allowed'],
                        'Control/reconciliation admission exhausted the normal budget.');
                    if (str_starts_with($control, 'reconcile_')) {
                        foreach ([
                            'app_link_inventory_binding' => $client,
                            'app_link_inventory_account' => 'same-account',
                            'app_link_inventory_ip' => 'same-ip',
                            'app_link_inventory_global' => 'global',
                        ] as $policyName => $subject) {
                            check($consume($policyName, $subject, $clock)['allowed'],
                                'Safe head reconciliation exhausted its independent budget.');
                        }
                    }
                }
            }
            // The two ordinary clients used 45 * 2 = 90 global uploads.
            // Fill the remaining actual policy allowance, then prove the
            // expensive global storage circuit breaker still refuses abuse.
            $ordinaryUploads = APP_LINK_FIFO_FIXTURE_ORDINARY_ORIGINALS * count($clients);
            for ($index = $ordinaryUploads;
                 $index < $policies['app_link_upload_global']['max_attempts']; $index++) {
                check($consume('app_link_upload_global', 'global', $clock)['allowed'],
                    'Global upload budget refused before its configured ceiling.');
            }
            check(!$consume('app_link_upload_global', 'global', $clock)['allowed'],
                'Global upload circuit breaker did not bound additional load.');
            // The old shared 60/300 bucket's 61st request is exactly the
            // inventory request after thirty inventory/upload pairs.
            $oldBucket = $prefix . '_historical_shared';
            $owned[$oldBucket] = true;
            /**
             * Retain the historical policy solely as a reproduction vector.
             * Type: array{max_attempts:int,window_seconds:int,lock_seconds:int}.
             * Units: requests/seconds. Scope: this owned historical bucket.
             * Consumers: app_link_model_rate_limit_consume below.
             * Rationale: 60 requests/300 seconds and a 300-second lock reproduce
             * the observed thirty-image exhaustion, never a new runtime policy.
             */
            $oldPolicy = ['max_attempts' => 60, 'window_seconds' => 300, 'lock_seconds' => 300];
            for ($request = 1; $request <= 61; $request++) {
                $decision = \Gallery\Models\app_link_model_rate_limit_consume(
                    $oldBucket, hash('sha256', 'old-ip'), $oldPolicy,
                    \Gallery\Services\APP_LINKING_RATE_SUBJECT_CAP,
                    \Gallery\Services\APP_LINKING_RATE_CLEANUP_BATCH,
                    date('Y-m-d H:i:s', $clock - \Gallery\Services\APP_LINKING_RATE_STALE_SECONDS),
                    date('Y-m-d H:i:s', $clock), $clock,
                );
                check($decision['allowed'] === ($request <= 60),
                    'Historical shared limiter no longer reproduces its exact boundary.');
            }
            // Same-grant burst reaches its actual configured ceiling, then
            // releases exactly when Retry-After permits; early probes do not
            // prolong the original lock or admit another image.
            $limit = $policies['app_link_upload_binding']['max_attempts'];
            for ($attempt = 0; $attempt < $limit; $attempt++) {
                check($consume('app_link_upload_binding', 'burst', $clock)['allowed'],
                    'Authenticated upload burst refused below its threshold.');
            }
            $refused = $consume('app_link_upload_binding', 'burst', $clock);
            check(!$refused['allowed'] && $refused['retry_after_seconds'] > 0,
                'A real rate refusal must report its actual retry delay.');
            $early = $consume('app_link_upload_binding', 'burst', $clock + 1);
            check(!$early['allowed']
                && $early['retry_after_seconds'] === $refused['retry_after_seconds'] - 1,
                'An early probe changed or extended the original lock.');
            check($consume('app_link_upload_binding', 'burst',
                $clock + $refused['retry_after_seconds'])['allowed'],
                'Exact Retry-After release incorrectly started another lock.');
            // A supported 100-original multipart must not count as one cheap
            // storage operation in an aggregate bucket. These are structural
            // vectors at the existing multipart boundary, never client batching.
            $multipartCost = 100;
            $aggregateLimit = $policies['app_link_upload_account']['max_attempts'];
            $weighted = $consume('app_link_upload_account', 'multipart', $clock, $multipartCost);
            check($weighted['allowed'] && $weighted['attempts'] === $multipartCost,
                'First multipart admission did not charge every original.');
            $remaining = $aggregateLimit - $multipartCost;
            check($consume('app_link_upload_account', 'multipart', $clock, $remaining)['allowed'],
                'Weighted admission refused within its aggregate allowance.');
            $weightedRefusal = $consume('app_link_upload_account', 'multipart', $clock);
            check(!$weightedRefusal['allowed'],
                'Multipart originals bypassed the aggregate storage ceiling.');
            $reset = $consume('app_link_upload_account', 'multipart',
                $clock + $weightedRefusal['retry_after_seconds'], $multipartCost);
            check($reset['allowed'] && $reset['attempts'] === $multipartCost,
                'Weighted admission did not reset to its actual cost at release.');
        } finally {
            $subjects = $pdo->prepare('DELETE FROM viewer_rate_limits WHERE bucket = ?');
            $buckets = $pdo->prepare('DELETE FROM viewer_rate_limit_buckets WHERE bucket = ?');
            foreach (array_keys($owned) as $bucket) {
                $subjects->execute([$bucket]);
                $buckets->execute([$bucket]);
            }
        }
    }

    require_once __DIR__ . '/support/gallery_workflow_safety.php';
    require_once __DIR__ . '/../app/policy_constants.php';
    require_once __DIR__ . '/../app/models/gallery_edit_concurrency.php';
    require_once __DIR__ . '/../app/services/gallery_edit_concurrency.php';
    require_once __DIR__ . '/../app/models/app_linking.php';
    require_once __DIR__ . '/../app/models/viewer_rate_limits.php';
    require_once __DIR__ . '/../app/services/app_linking_rate_limits.php';
    require_once __DIR__ . '/../app/models/app_linking_push.php';
    require_once __DIR__ . '/../app/models/app_linking_refresh.php';
    require_once __DIR__ . '/../app/services/app_linking_refresh.php';
    require_once __DIR__ . '/../app/services/app_linking_policy.php';
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
        app_link_test_fifo_rate_budgets($pdo, $username);
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
        $watchValid = static fn(int $candidateGrant, string $candidateToken, string $digest): bool =>
            app_link_model_watch_key_valid(
                $candidateGrant,
                $userId,
                $candidateToken,
                $issuer,
                $uploadScope,
                $galleryId,
                $digest,
            );
        $watchDigest = hash('sha256', 'fixture-watcher-generation-one');
        check(app_link_model_watch_key_open(
            $grantId, $userId, $uploadToken, $issuer, $uploadScope,
            $galleryId, $watchDigest,
        ) && $watchValid($grantId, $uploadToken, $watchDigest),
            'An explicitly opened watcher must authorize only its exact live grant and gallery.');
        $storedWatchHash = $pdo->prepare(
            'SELECT key_hash FROM app_linking_watch_keys '
                . 'WHERE grant_id = ? AND gallery_id = ?',
        );
        $storedWatchHash->execute([$grantId, $galleryId]);
        check($storedWatchHash->fetchColumn() === $watchDigest,
            'Watcher storage must retain only the supplied key digest.');
        check(!app_link_model_watch_key_open(
            $grantId, $userId, hash('sha256', 'fixture-wrong-token'), $issuer,
            $uploadScope, $galleryId, hash('sha256', 'fixture-rejected-open'),
        ), 'Watcher opening must bind atomically to the live exact access token.');
        $replacementDigest = hash('sha256', 'fixture-watcher-generation-two');
        check(app_link_model_watch_key_open(
            $grantId, $userId, $uploadToken, $issuer, $uploadScope,
            $galleryId, $replacementDigest,
        ), 'A new watcher generation must replace the prior exact pair.');
        app_link_model_watch_key_close($grantId, $galleryId, $watchDigest);
        check(!$watchValid($grantId, $uploadToken, $watchDigest)
            && $watchValid($grantId, $uploadToken, $replacementDigest),
            'A stale close must not revoke the replacement generation.');
        check(!app_link_model_watch_key_open(
            $grantId, $userId, $uploadToken, $issuer, $uploadScope,
            PHP_INT_MAX, hash('sha256', 'fixture-watcher-wrong-gallery'),
        ), 'Watcher opening must refuse a nonexistent physical gallery.');
        $expireWatch = $pdo->prepare(
            "UPDATE app_linking_watch_keys SET expires_at = '2000-01-01 00:00:00' "
                . 'WHERE grant_id = ? AND gallery_id = ?',
        );
        $expireWatch->execute([$grantId, $galleryId]);
        check(!$watchValid($grantId, $uploadToken, $replacementDigest),
            'An expired watcher key must not authorize a request.');
        check(!app_link_model_watch_key_valid(
            $grantId, $userId, $uploadToken, $issuer, $uploadScope,
            PHP_INT_MAX, $replacementDigest,
        ), 'A key for another physical gallery must not authorize this gallery.');
        check(app_link_model_watch_key_open(
            $grantId, $userId, $uploadToken, $issuer, $uploadScope,
            $galleryId, $replacementDigest,
        ), 'An active watcher may be explicitly reopened after expiry.');
        $otherToken = hash('sha256', 'fixture-other-upload-token');
        $insertOtherGrant = $pdo->prepare(
            'INSERT INTO app_link_grants '
                . '(user_id, client_id, issuer, scope, created_at, access_token_hash, '
                . 'access_issued_at, access_expires_at) '
                . 'VALUES (?, ?, ?, ?, UTC_TIMESTAMP(6), ?, UTC_TIMESTAMP(6), '
                . 'DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 900 SECOND))',
        );
        $insertOtherGrant->execute([
            $userId, $client, $issuer, $uploadScope, $otherToken,
        ]);
        $otherGrantId = (int) $pdo->lastInsertId();
        check(app_link_model_watch_key_open(
            $otherGrantId, $userId, $otherToken, $issuer, $uploadScope,
            $galleryId, $replacementDigest,
        ) && $watchValid($grantId, $uploadToken, $replacementDigest)
            && $watchValid($otherGrantId, $otherToken, $replacementDigest),
            'The same digest may exist independently for two exact grants.');
        app_link_model_watch_key_close($grantId, $galleryId, $replacementDigest);
        check(!$watchValid($grantId, $uploadToken, $replacementDigest)
            && $watchValid($otherGrantId, $otherToken, $replacementDigest),
            'Closing one grant must never affect another grant row.');
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
            ) === null && !$watchValid(
                $grantId, $uploadToken, $replacementDigest,
            ), 'Revocation must remove upload and Watcher authority immediately.');
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
        // storage. A wrong Watcher key must never reach the upload workflow.
        $guardCalled = false;
        $guardHeldLock = false;
        $guardHeldWriter = false;
        $wrongWatchDigest = hash('sha256', 'fixture-wrong-watcher-key');
        $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $writerName = hash('sha256', $databaseName . \Gallery\Core\GALLERY_EDIT_LOCK_SUFFIX);
        try {
            upload_automation_store_images(
                $galleryId, $target, [], [], [], null,
                static function () use (
                    $pdo, $galleryId, $writerName, &$guardCalled,
                    &$guardHeldLock, &$guardHeldWriter, $otherGrantId,
                    $wrongWatchDigest, $watchValid, $otherToken,
                ): bool {
                    $guardCalled = true;
                    $lock = $pdo->prepare('SELECT IS_USED_LOCK(?) = CONNECTION_ID()');
                    $lock->execute(['php_gallery_upload_automation_' . $galleryId]);
                    $guardHeldLock = (int) $lock->fetchColumn() === 1;
                    $lock->execute([$writerName]);
                    $guardHeldWriter = (int) $lock->fetchColumn() === 1;
                    return $watchValid(
                        $otherGrantId, $otherToken, $wrongWatchDigest,
                    );
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
        $denyUploadGuard = static function (
            int $watchGalleryId,
            string $watchDigest,
        ) use (
            $galleryId,
            $target,
            $otherGrantId,
            $userId,
            $otherToken,
            $issuer,
            $uploadScope,
        ): void {
            try {
                upload_automation_store_images(
                    $galleryId, $target, [], [], [], null,
                    static fn(): bool => app_link_model_watch_key_valid(
                        $otherGrantId,
                        $userId,
                        $otherToken,
                        $issuer,
                        $uploadScope,
                        $watchGalleryId,
                        $watchDigest,
                    ),
                );
                check(false, 'A denied Watcher key must stop the upload workflow.');
            } catch (\RuntimeException $exception) {
                check($exception->getMessage() === 'Upload authority is unavailable.',
                    'Watcher refusal must retain the opaque upload authorization error.');
            }
        };
        $expireOtherWatchForUpload = $pdo->prepare(
            "UPDATE app_linking_watch_keys SET expires_at = '2000-01-01 00:00:00' "
                . 'WHERE grant_id = ? AND gallery_id = ?',
        );
        $expireOtherWatchForUpload->execute([$otherGrantId, $galleryId]);
        $denyUploadGuard($galleryId, $replacementDigest);
        check(app_link_model_watch_key_open(
            $otherGrantId, $userId, $otherToken, $issuer, $uploadScope,
            $galleryId, $replacementDigest,
        ), 'The live second grant must restore its watcher after upload expiry coverage.');
        $denyUploadGuard(PHP_INT_MAX, $replacementDigest);
        $countImages->execute([$galleryId]);
        check((int) $countImages->fetchColumn() === 0,
            'Expired and wrong-gallery watcher refusals must not write images.');
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
        $inventoryCandidates = [[
            'client_id' => 'watch-fixture',
            'filename' => 'first.jpg',
            'size' => 1,
            'sha256' => $firstHash,
        ]];
        $firstIndexedMatch = null;
        foreach ($matches as $match) {
            if (($match['checksum_sha256'] ?? null) === $firstHash) {
                $firstIndexedMatch = $match;
                break;
            }
        }
        check(is_array($firstIndexedMatch),
            'An indexed row is required to seed the inventory recheck fixture.');
        $inventoryInitial = ['existing' => [[
            'sha256' => $firstHash,
            'remote_image_id' => (int) $firstIndexedMatch['id'],
            'remote_relative_path' => (string) $firstIndexedMatch['relative_path'],
            'size' => (int) $firstIndexedMatch['file_size'],
        ]]];
        check($inventoryInitial['existing'] !== []
            && \Gallery\Services\app_linking_inventory_confirm_indexed(
                $galleryId,
                $target,
                $inventoryCandidates,
                $inventoryInitial,
                static fn(): ?array => $watchValid(
                    $otherGrantId, $otherToken, $wrongWatchDigest,
                ) ? $target : null,
            ) === null,
            'A wrong watcher key must fail the locked inventory recheck before file evidence.');
        $expireOtherWatch = $pdo->prepare(
            "UPDATE app_linking_watch_keys SET expires_at = '2000-01-01 00:00:00' "
                . 'WHERE grant_id = ? AND gallery_id = ?',
        );
        $expireOtherWatch->execute([$otherGrantId, $galleryId]);
        check(\Gallery\Services\app_linking_inventory_confirm_indexed(
            $galleryId,
            $target,
            $inventoryCandidates,
            $inventoryInitial,
            static fn(): ?array => $watchValid(
                $otherGrantId, $otherToken, $replacementDigest,
            ) ? $target : null,
        ) === null, 'An expired watcher must fail the locked inventory recheck.');
        check(app_link_model_watch_key_open(
            $otherGrantId, $userId, $otherToken, $issuer, $uploadScope,
            $galleryId, $replacementDigest,
        ), 'The second live grant must reopen its watcher after the expiry case.');
        $revokeOtherGrant = $pdo->prepare(
            'UPDATE app_link_grants SET revoked_at = UTC_TIMESTAMP(6) WHERE id = ?',
        );
        $revokeOtherGrant->execute([$otherGrantId]);
        $denyUploadGuard($galleryId, $replacementDigest);
        check(\Gallery\Services\app_linking_inventory_confirm_indexed(
            $galleryId,
            $target,
            $inventoryCandidates,
            $inventoryInitial,
            static fn(): ?array => $watchValid(
                $otherGrantId, $otherToken, $replacementDigest,
            ) ? $target : null,
        ) === null, 'A revoked grant must fail the locked inventory recheck.');
        $countImages->execute([$galleryId]);
        check((int) $countImages->fetchColumn() === 4,
            'A revoked watcher refusal must leave the existing inventory unchanged.');
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
        $familyTimes = $pdo->prepare(
            'SELECT expires_at, idle_expires_at, issued_count '
                . 'FROM app_link_refresh_families WHERE grant_id = ?',
        );
        $familyTimes->execute([$offlineId]);
        $legacyTimes = $familyTimes->fetch(\PDO::FETCH_ASSOC);
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
        $familyTimes->execute([$offlineId]);
        check($familyTimes->fetch(\PDO::FETCH_ASSOC)['expires_at'] ===
            $legacyTimes['expires_at'], 'Legacy rotation must preserve its absolute cap.');
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

        $rollingScope = \Gallery\Services\APP_LINKING_GALLERIES_ROLLING_SCOPE;
        $rollingIssuer = 'http://localhost:8888/Galerie';
        $rollingTtl = \Gallery\Services\APP_LINK_REFRESH_IDLE_TTL_SECONDS;
        $rollingCode = hash('sha256', 'fixture-rolling-code');
        $rollingAccess = hash('sha256', 'fixture-rolling-access');
        $rollingRefresh = hash('sha256', 'fixture-rolling-refresh');
        check(app_link_model_issue(
            $userId, $client, $rollingIssuer, $rollingScope,
            static fn(): string => $rollingCode, $redirect, $challenge,
            \Gallery\Services\APP_LINKING_CODE_TTL_SECONDS,
        ) !== null, 'Rolling authorization code must be issued.');
        $rollingGrant = app_link_model_exchange(
            $rollingCode, $client, $rollingIssuer, $redirect, $challenge,
            [$rollingScope], static fn(): string => $rollingAccess,
            \Gallery\Services\APP_LINKING_ACCESS_TTL_SECONDS,
            [$rollingScope], static fn(): string => $rollingRefresh,
            'ready', $rollingTtl, $rollingTtl, $rollingScope,
        );
        check(is_array($rollingGrant) && $rollingGrant['scope'] === $rollingScope
            && $rollingGrant['refresh_expires_in'] > 0
            && $rollingGrant['refresh_expires_in'] <= $rollingTtl,
            'Rolling exchange must report the explicitly approved window.');
        $rollingId = $rollingGrant['grant_id'];
        $familyTimes->execute([$rollingId]);
        $initialTimes = $familyTimes->fetch(\PDO::FETCH_ASSOC);
        check($initialTimes['expires_at'] === $initialTimes['idle_expires_at'],
            'Rolling exchange must initialize equal deadlines.');
        // Shorten a live window without sleeping or changing the database clock.
        $shortenRolling = $pdo->prepare(
            'UPDATE app_link_refresh_families SET '
                . 'expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), '
                . 'idle_expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND) '
                . 'WHERE grant_id = ?',
        );
        $shortWindow = \Gallery\Services\APP_LINKING_ACCESS_TTL_SECONDS;
        $shortenRolling->execute([$shortWindow, $shortWindow, $rollingId]);
        $familyTimes->execute([$rollingId]);
        $shortened = $familyTimes->fetch(\PDO::FETCH_ASSOC);
        $renewRolling = static fn(string $access, string $origin): bool =>
            \Gallery\Models\app_link_refresh_model_extend_on_access(
                $rollingId, $userId, $access, $client, $origin, $rollingScope,
                $rollingTtl,
            );
        check(!$renewRolling($rollingAccess, $issuer),
            'Wrong issuer must not extend a rolling family.');
        $familyTimes->execute([$rollingId]);
        check($familyTimes->fetch(\PDO::FETCH_ASSOC) === $shortened,
            'Rejected access must leave both deadlines untouched.');
        check($renewRolling($rollingAccess, $rollingIssuer),
            'Authenticated access must renew the rolling family.');
        $familyTimes->execute([$rollingId]);
        $renewed = $familyTimes->fetch(\PDO::FETCH_ASSOC);
        check($renewed['expires_at'] > $shortened['expires_at']
            && $renewed['expires_at'] === $renewed['idle_expires_at'],
            'Authenticated use must extend both deadlines together.');
        $storedRefresh->execute([$rollingId]);
        check($storedRefresh->fetchAll(\PDO::FETCH_COLUMN) === [$rollingRefresh],
            'Using a bearer must preserve the current active refresh hash.');

        $rotateRolling = static fn(
            string $refresh, string $nextAccess, string $nextRefresh,
        ): ?array => \Gallery\Models\app_link_refresh_model_rotate(
            $refresh, $client, $rollingIssuer, [$rollingScope],
            static fn(): array => [
                'access_hash' => $nextAccess, 'refresh_hash' => $nextRefresh,
            ],
            \Gallery\Services\APP_LINKING_ACCESS_TTL_SECONDS, $rollingTtl,
            [$rollingScope],
        );
        $rollingAccessTwo = hash('sha256', 'fixture-rolling-access-two');
        $rollingRefreshTwo = hash('sha256', 'fixture-rolling-refresh-two');
        $shortenRolling->execute([$shortWindow, $shortWindow, $rollingId]);
        $familyTimes->execute([$rollingId]);
        $shortened = $familyTimes->fetch(\PDO::FETCH_ASSOC);
        $firstRolling = $rotateRolling($rollingRefresh, $rollingAccessTwo, $rollingRefreshTwo);
        check(($firstRolling['status'] ?? null) === 'rotated', 'Current rolling refresh must rotate.');
        $familyTimes->execute([$rollingId]);
        $rotatedTimes = $familyTimes->fetch(\PDO::FETCH_ASSOC);
        check($rotatedTimes['expires_at'] > $shortened['expires_at']
            && $rotatedTimes['expires_at'] === $rotatedTimes['idle_expires_at']
            && !$renewRolling($rollingAccess, $rollingIssuer),
            'Rotation must extend both deadlines and invalidate the prior access token.');

        // Move the consumed hash beyond its usable window. A later rotation
        // may discard only this aged hash while retaining fresh replay evidence.
        $ageConsumed = $pdo->prepare(
            "UPDATE app_link_refresh_tokens SET consumed_at = '2000-01-01 00:00:00' "
                . 'WHERE grant_id = ? AND token_hash = ? AND active_slot IS NULL',
        );
        $ageConsumed->execute([$rollingId, $rollingRefresh]);
        $rollingAccessThree = hash('sha256', 'fixture-rolling-access-three');
        $rollingRefreshThree = hash('sha256', 'fixture-rolling-refresh-three');
        $secondRolling = $rotateRolling(
            $rollingRefreshTwo, $rollingAccessThree, $rollingRefreshThree,
        );
        check(($secondRolling['status'] ?? null) === 'rotated', 'A rolling family must rotate after aging replay evidence.');
        $history->execute([$rollingId]);
        $rollingRows = $history->fetchAll(\PDO::FETCH_ASSOC);
        $familyTimes->execute([$rollingId]);
        $retained = $familyTimes->fetch(\PDO::FETCH_ASSOC);
        check(count($rollingRows) === 2
            && (int) $retained['issued_count'] === count($rollingRows)
            && $rollingRows[0]['token_hash'] === $rollingRefreshTwo
            && $rollingRows[0]['consumed_at'] !== null
            && $rollingRows[1]['token_hash'] === $rollingRefreshThree
            && (int) $rollingRows[1]['active_slot'] === 1,
            'Rolling pruning must keep the active hash and recent replay evidence.');
        check($rotateRolling($rollingRefresh, $rollingAccess, $rollingRefresh) === null
            && $renewRolling($rollingAccessThree, $rollingIssuer),
            'A pruned refresh hash must not consume or revoke the active family.');
        $expireRolling = $pdo->prepare(
            "UPDATE app_link_refresh_families SET expires_at = '2000-01-01 00:00:00', "
                . "idle_expires_at = '2000-01-01 00:00:00' WHERE grant_id = ?",
        );
        $expireRolling->execute([$rollingId]);
        check(!$renewRolling($rollingAccessThree, $rollingIssuer)
            && $rotateRolling($rollingRefreshThree, $rollingAccess, $rollingRefresh) === null,
            'Even a live bearer must not resurrect an expired rolling family.');
        $shortenRolling->execute([$shortWindow, $shortWindow, $rollingId]);
        $rollingReplay = $rotateRolling($rollingRefreshTwo, $rollingAccess, $rollingRefresh);
        check(($rollingReplay['status'] ?? null) === 'replayed'
            && !$renewRolling($rollingAccessThree, $rollingIssuer),
            'A recently consumed rolling hash must still revoke the whole family.');

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
