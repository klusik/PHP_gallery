<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/core_persistence_delegation_test.php
 * Module Type: Regression Test
 * Purpose: Verify legacy Core adapters preserve results while models own persistence.
 * Responsibilities:
 *   - Exercise caller-owned slug connections, safe session projections and context counts.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Return the explicit in-memory test connection, never installation configuration.
     * @return \PDO Isolated SQLite connection used by canonical model functions.
     */
    function db(): \PDO
    {
        return $GLOBALS['core_delegation_connection'];
    }
}

namespace Gallery\Services {
    /**
     * Select the test's explicit three-state optional-email observation.
     * @return array{state:string} Available, missing or unknown fixture status.
     */
    function auth_user_email_schema_status(): array
    {
        return ['state' => $GLOBALS['core_delegation_email_state']];
    }
    /**
     * Match available state without treating missing or unknown as success.
     * @param array{state:string} $status Explicit fixture observation.
     * @return bool Whether optional-column selection is authorized.
     */
    function schema_inspection_is_available(array $status): bool { return $status['state'] === 'available'; }
    /**
     * Identify uncertainty for bounded logging.
     * @param array{state:string} $status Explicit fixture observation.
     * @return bool Whether metadata inspection was inconclusive.
     */
    function schema_inspection_is_unknown(array $status): bool { return $status['state'] === 'unknown'; }
    /**
     * Capture bounded capability/operation identities, without account data.
     * @param string $capability Schema capability identifier.
     * @param string $operation Stable observation identifier.
     * @return void
     */
    function auth_log_schema_unavailable(string $capability, string $operation): void
    {
        $GLOBALS['core_delegation_logs'][] = [$capability, $operation];
    }
    /**
     * Record the root visibility preflight.
     * @return void
     */
    function gallery_visibility_assert_public_policy_available(): void { $GLOBALS['core_delegation_preflights']++; }
    /**
     * Record the root access preflight.
     * @return void
     */
    function gallery_access_assert_public_policy_available(): void { $GLOBALS['core_delegation_preflights']++; }
    /**
     * Require listed roots in this fixture.
     * @return bool True because the test creates access_listing explicitly.
     */
    function gallery_access_schema_ready(): bool { return true; }
}

namespace {
    require_once dirname(__DIR__) . '/app/helpers_runtime.php';
    require_once dirname(__DIR__) . '/app/models/galleries.php';
    require_once dirname(__DIR__) . '/app/models/auth.php';
    require_once dirname(__DIR__) . '/app/services/auth_accounts.php';
    require_once dirname(__DIR__) . '/app/services/gallery_lookup.php';

    /**
     * Fail on a changed adapter behavior with a non-sensitive fixture message.
     * @param bool $condition Expected invariant.
     * @param string $message Regression description.
     * @return void
     */
    function core_delegation_assert(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    $connection = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $GLOBALS['core_delegation_connection'] = $connection;
    $GLOBALS['core_delegation_logs'] = [];
    $GLOBALS['core_delegation_preflights'] = 0;
    $connection->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY, slug TEXT, parent_id INTEGER, visibility TEXT, access_listing TEXT)');
    $connection->exec("INSERT INTO galleries VALUES (1,'sample',NULL,'public','listed'), (2,'sample-2',NULL,'public','unlisted'), (3,'child',1,'unpublished','listed')");
    $connection->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, role TEXT, email TEXT, password_hash TEXT)');
    $connection->exec("INSERT INTO users VALUES (1,'fixture-admin','admin','fixture@example.invalid','not-a-real-credential')");

    core_delegation_assert(Gallery\Core\unique_slug($connection, 'Sample') === 'sample-3', 'Slug suffix sequence changed.');
    core_delegation_assert(Gallery\Core\unique_slug($connection, 'Sample', 1) === 'sample', 'Excluded gallery lost its slug.');
    $otherConnection = new PDO('sqlite::memory:');
    $otherConnection->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY, slug TEXT)');
    core_delegation_assert(Gallery\Core\unique_slug($otherConnection, 'Sample') === 'sample', 'Legacy adapter ignored its caller-owned connection.');
    foreach (['available', 'missing', 'unknown'] as $state) {
        $GLOBALS['core_delegation_email_state'] = $state;
        $user = Gallery\Services\auth_account_session_user(1);
        core_delegation_assert(array_keys($user) === ($state === 'available' ? ['id', 'username', 'email', 'role'] : ['id', 'username', 'role', 'email']), 'Session projection changed or exposed an extra field.');
        core_delegation_assert($user['email'] === ($state === 'available' ? 'fixture@example.invalid' : null), 'Optional email policy changed.');
    }
    core_delegation_assert(count($GLOBALS['core_delegation_logs']) === 1, 'Only unknown optional schema should log.');
    core_delegation_assert(Gallery\Services\auth_account_admin_exists(), 'Setup cannot observe the fixture Admin.');
    core_delegation_assert(Gallery\Services\gallery_mutation_context_count(0) === 1, 'Root count lost public/listed filtering.');
    core_delegation_assert(Gallery\Services\gallery_mutation_context_count(1) === 1, 'Admin parent count incorrectly filtered unpublished children.');
    core_delegation_assert($GLOBALS['core_delegation_preflights'] === 2, 'Root preflight was skipped or repeated for an Admin parent.');
    echo "Core persistence delegation checks passed.\n";
}
