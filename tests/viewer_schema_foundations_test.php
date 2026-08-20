<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_schema_foundations_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies the Phase 0 viewer database model and migration safety contract.
 *
 * Responsibilities:
 *   - Confirm all viewer tables are additive and replay-safe
 *   - Protect identity separation, token hashing, canonical image references, and deletion semantics
 *   - Protect database constraints for favourites, collections, share capabilities, sessions, and throttling
 *   - Confirm the feature remains disabled and unrouted after migration
 */

declare(strict_types=1);

/**
 * Throw when one viewer schema expectation fails.
 *
 * @param bool $condition Condition value.
 * @param string $label Assertion label.
 */
function viewer_schema_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Return the CREATE TABLE statement for one expected viewer table.
 *
 * @param array<int,string> $statements Migration SQL statements.
 * @param string $table Table name.
 * @return string Matching SQL statement.
 */
function viewer_schema_statement(array $statements, string $table): string
{
    foreach ($statements as $statement) {
        if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+' . preg_quote($table, '/') . '\s*\(/i', $statement) === 1) {
            return $statement;
        }
    }
    throw new RuntimeException('Missing viewer schema table: ' . $table);
}

$root = dirname(__DIR__);
$migrationPath = $root . '/database/migrations/202608180001_viewer_security_foundations.php';
$statements = require $migrationPath;
viewer_schema_assert(is_array($statements) && $statements !== [], 'Viewer foundation migration must return SQL statements.');

$authMigrationPath = $root . '/database/migrations/202608180003_viewer_authentication_foundations.php';
$authStatements = require $authMigrationPath;
viewer_schema_assert(is_array($authStatements) && count($authStatements) === 1, 'Phase 0.6 authentication migration must remain one small additive migration.');
$accountState = viewer_schema_statement($authStatements, 'viewer_account_state');
viewer_schema_assert(str_contains($accountState, 'account_count INT UNSIGNED') && str_contains($accountState, 'PRIMARY KEY'), 'Durable viewer-account capacity must use one lockable singleton counter table.');
viewer_schema_assert(str_contains($accountState, 'ENGINE=InnoDB'), 'Viewer account-cap serialization must use InnoDB row locking.');

foreach ($statements as $statement) {
    viewer_schema_assert(is_string($statement) && str_starts_with(ltrim($statement), 'CREATE TABLE IF NOT EXISTS '), 'Phase 0 migration must contain only additive replay-safe CREATE TABLE IF NOT EXISTS statements.');
    viewer_schema_assert(stripos($statement, 'ALTER TABLE users') === false, 'Phase 0 must not alter the existing admin users table.');
    viewer_schema_assert(stripos($statement, 'ALTER TABLE galleries') === false, 'Phase 0 must not alter existing gallery authorization columns.');
    viewer_schema_assert(stripos($statement, 'ALTER TABLE images') === false, 'Phase 0 must not rewrite canonical image storage.');
}

$expectedTables = [
    'viewer_accounts',
    'viewer_email_verification_tokens',
    'viewer_password_reset_tokens',
    'viewer_remember_tokens',
    'viewer_sessions',
    'viewer_security_events',
    'viewer_rate_limit_buckets',
    'viewer_rate_limits',
    'viewer_favourites',
    'viewer_collections',
    'viewer_collection_items',
    'viewer_collection_share_tokens',
    'viewer_passkeys',
];
foreach ($expectedTables as $table) {
    viewer_schema_assert(viewer_schema_statement($statements, $table) !== '', 'Expected viewer table must exist: ' . $table);
}

$accounts = viewer_schema_statement($statements, 'viewer_accounts');
viewer_schema_assert(str_contains($accounts, 'normalized_email') && str_contains($accounts, 'viewer_accounts_normalized_email_unique'), 'Viewer email uniqueness must be enforced by a database constraint.');
viewer_schema_assert(str_contains($accounts, "DEFAULT 'pending_verification'") && str_contains($accounts, 'security_version'), 'Viewer accounts must default pending and carry security-version invalidation state.');
viewer_schema_assert(stripos($accounts, 'REFERENCES users') === false && stripos($accounts, 'role') === false, 'Viewer accounts must not share the admin users/role domain.');
viewer_schema_assert(!str_contains($accounts, 'birth') && !str_contains($accounts, 'gender') && !str_contains($accounts, 'phone') && !str_contains($accounts, 'address'), 'Viewer account schema must avoid unnecessary personal profile data.');

$verification = viewer_schema_statement($statements, 'viewer_email_verification_tokens');
viewer_schema_assert(str_contains($verification, 'token_hash CHAR(64)') && !preg_match('/\n\s*token\s+VARCHAR/i', $verification), 'Email verification authority must be stored only as a hash.');
viewer_schema_assert(str_contains($verification, 'expires_at') && str_contains($verification, 'consumed_at') && str_contains($verification, 'invalidated_at'), 'Email verification tokens must support expiry, single use, and invalidation.');

$reset = viewer_schema_statement($statements, 'viewer_password_reset_tokens');
viewer_schema_assert(str_contains($reset, 'token_hash CHAR(64)') && str_contains($reset, 'security_version'), 'Password reset tokens must be hashed and bound to account security version.');
viewer_schema_assert(str_contains($reset, 'expires_at') && str_contains($reset, 'consumed_at') && str_contains($reset, 'invalidated_at'), 'Password reset tokens must support expiry, single use, and invalidation.');

$remember = viewer_schema_statement($statements, 'viewer_remember_tokens');
viewer_schema_assert(str_contains($remember, 'selector CHAR(36)') && str_contains($remember, 'verifier_hash CHAR(64)') && !preg_match('/\n\s*verifier\s+/i', $remember), 'Remember tokens must use selector/verifier with only verifier hash stored.');
viewer_schema_assert(str_contains($remember, 'revoked_at') && str_contains($remember, 'security_version'), 'Remember tokens must be revocable and security-version bound.');

$sessions = viewer_schema_statement($statements, 'viewer_sessions');
viewer_schema_assert(str_contains($sessions, 'session_hash CHAR(64)') && str_contains($sessions, 'revoked_at') && str_contains($sessions, 'security_version'), 'Viewer sessions must be server-side revocable and security-version bound.');
viewer_schema_assert(str_contains($sessions, 'viewer_sessions_account_expiry_index') && str_contains($sessions, 'viewer_sessions_expiry_index'), 'Viewer session cleanup/access queries must have deliberate expiry/account indexes.');

$favourites = viewer_schema_statement($statements, 'viewer_favourites');
viewer_schema_assert(str_contains($favourites, 'PRIMARY KEY (viewer_account_id, image_id)'), 'Duplicate favourites must be rejected by a database uniqueness constraint.');
viewer_schema_assert(str_contains($favourites, 'REFERENCES images(id) ON DELETE CASCADE'), 'Deleting canonical media must predictably remove stale favourite references.');
viewer_schema_assert(str_contains($favourites, 'REFERENCES viewer_accounts(id) ON DELETE CASCADE'), 'Deleting a viewer account must clean its favourites without touching media.');

$collections = viewer_schema_statement($statements, 'viewer_collections');
viewer_schema_assert(str_contains($collections, 'viewer_account_id') && str_contains($collections, 'title VARCHAR(160)') && str_contains($collections, 'description VARCHAR(2000)'), 'Collections must be owner-scoped with bounded plain-text metadata fields.');
viewer_schema_assert(!str_contains(strtolower($collections), 'html') && !str_contains(strtolower($collections), 'markdown'), 'Collection schema must not introduce user-generated markup semantics.');

$items = viewer_schema_statement($statements, 'viewer_collection_items');
viewer_schema_assert(str_contains($items, 'PRIMARY KEY (viewer_collection_id, image_id)'), 'Collection duplicate media references must be prevented by a database constraint.');
viewer_schema_assert(str_contains($items, 'viewer_collection_items_order_index (viewer_collection_id, position, image_id)'), 'Collection ordering must be deterministic even when positions tie.');
viewer_schema_assert(str_contains($items, 'REFERENCES images(id) ON DELETE CASCADE'), 'Collection items must reference canonical images and disappear when media is deleted.');
foreach (['permission', 'authorization', 'visibility', 'access_mode', 'access_token', 'gallery_password'] as $forbiddenPermissionColumn) {
    viewer_schema_assert(stripos($items, $forbiddenPermissionColumn) === false, 'Collection items must never store copied authorization state: ' . $forbiddenPermissionColumn);
}

$share = viewer_schema_statement($statements, 'viewer_collection_share_tokens');
viewer_schema_assert(str_contains($share, 'token_hash CHAR(64)') && !preg_match('/\n\s*token\s+VARCHAR/i', $share), 'Collection share authority must be an opaque token hash, never a sequential id or plaintext secret.');
viewer_schema_assert(str_contains($share, 'revoked_at') && str_contains($share, 'expires_at'), 'Collection share capabilities must support revocation and expiry.');
viewer_schema_assert(str_contains($share, 'viewer_collection_share_creator_state_index (created_by_viewer_account_id, revoked_at, expires_at)'), 'Future per-account active-share quotas must have an owner/state index.');
viewer_schema_assert(!str_contains($share, 'image_id') && !str_contains($share, 'gallery_id'), 'Collection share tokens must authorize only the collection container, never media/gallery access.');

$passkeys = viewer_schema_statement($statements, 'viewer_passkeys');
viewer_schema_assert(str_contains($passkeys, 'credential_id VARBINARY(1024)') && str_contains($passkeys, 'credential_id_hash CHAR(64)') && str_contains($passkeys, 'public_key TEXT'), 'Passkey schema must store credential/public-key material with indexed credential hashing.');
viewer_schema_assert(stripos($passkeys, 'private_key') === false && stripos($passkeys, 'private key') === false, 'Passkey schema must never store a private passkey key.');

$rateBuckets = viewer_schema_statement($statements, 'viewer_rate_limit_buckets');
$rateLimits = viewer_schema_statement($statements, 'viewer_rate_limits');
viewer_schema_assert(str_contains($rateBuckets, 'entry_count INT UNSIGNED') && str_contains($rateLimits, 'PRIMARY KEY (bucket, subject_hash)'), 'Viewer throttling must have a hard counted subject boundary and one row per bucket/subject hash.');
viewer_schema_assert(str_contains($rateLimits, 'last_attempt_at') && str_contains($rateLimits, 'locked_until'), 'Viewer throttle rows must be indexed/expirable operational state.');

$events = viewer_schema_statement($statements, 'viewer_security_events');
viewer_schema_assert(str_contains($events, 'retention_until') && str_contains($events, 'viewer_security_events_retention_index'), 'Viewer security events must have indexed retention cleanup.');
viewer_schema_assert(!preg_match('/\bemail\b/i', $events) && !preg_match('/\bpassword\b/i', $events) && !preg_match('/\btoken\b/i', $events), 'Viewer security event table must not dedicate columns to raw email/password/token secrets.');

$initialSchema = (string) file_get_contents($root . '/database/migrations/202604270001_initial_schema.php');
viewer_schema_assert(str_contains($initialSchema, "role ENUM('admin') NOT NULL DEFAULT 'admin'"), 'Existing users table must remain an administrator-only role domain.');
viewer_schema_assert(str_contains($initialSchema, 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY') && str_contains($initialSchema, 'CREATE TABLE IF NOT EXISTS images'), 'Canonical image identifiers must exist before viewer foreign keys are introduced.');

$migrationRunner = (string) file_get_contents($root . '/app/migrations.php');
$setupController = (string) file_get_contents($root . '/app/controllers/setup.php');
viewer_schema_assert(str_contains($migrationRunner, "discover_migration_files(dirname(__DIR__) . '/database/migrations')"), 'Normal migration runner must discover the shared migration directory used by upgrades.');
viewer_schema_assert(str_contains($setupController, 'run_migrations();'), 'Fresh setup must continue applying the shared migration sequence.');
$migrationFiles = glob($root . '/database/migrations/*.php') ?: [];
sort($migrationFiles, SORT_STRING);
$viewerMigrationIndex = array_search($migrationPath, $migrationFiles, true);
$registrationMigrationIndex = array_search($root . '/database/migrations/202608180002_viewer_registration_foundations.php', $migrationFiles, true);
$authMigrationIndex = array_search($authMigrationPath, $migrationFiles, true);
$previousMigrationIndex = array_search($root . '/database/migrations/202608170002_smart_gallery_attachment_ordering.php', $migrationFiles, true);
viewer_schema_assert(is_int($viewerMigrationIndex) && is_int($previousMigrationIndex) && $viewerMigrationIndex > $previousMigrationIndex, 'Viewer foundation migration must be discoverable in deterministic timestamp order for fresh installs and upgrades.');
viewer_schema_assert(is_int($registrationMigrationIndex) && is_int($authMigrationIndex) && $authMigrationIndex > $registrationMigrationIndex, 'Phase 0.6 authentication migration must run after the Phase 0.5 registration foundation.');

$configExample = (string) file_get_contents($root . '/config.example.php');
viewer_schema_assert(str_contains($configExample, "'viewer_accounts' => [") && str_contains($configExample, "'enabled' => false"), 'Viewer functionality must be disabled by default in example configuration.');
viewer_schema_assert(str_contains($configExample, "'registration_mode' => 'disabled'"), 'Viewer registration must default disabled.');
viewer_schema_assert(str_contains($configExample, "'trusted_proxies' => []") && str_contains($configExample, "'trusted_proxy_headers' => []"), 'Forwarded client headers must default untrusted.');

$dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
$viewerController = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
$viewerHttpService = (string) file_get_contents($root . '/app/services/viewer_http.php');
viewer_schema_assert(str_contains($dispatch, "'viewer_login' =>") && str_contains($dispatch, "'viewer_register' =>") && !str_contains($dispatch, "'viewer_signup' =>"), 'Later HTTP phases may expose viewer authentication and the single Phase 4.1 register route without creating a parallel signup subsystem.');
viewer_schema_assert(str_contains($viewerController, 'viewer_accounts_enabled()') && str_contains($viewerHttpService, 'viewer_accounts_enabled()') && str_contains($viewerHttpService, "viewer_registration_mode() === 'open'"), 'Later viewer HTTP wiring must stay gated by the Phase 0 master feature and bounded registration policy helpers.');

$services = (string) file_get_contents($root . '/app/services.php');
foreach (['security_tokens.php', 'client_ip.php', 'viewer_accounts.php', 'viewer_tokens.php', 'viewer_rate_limits.php', 'viewer_security_events.php', 'viewer_maintenance.php'] as $serviceFile) {
    viewer_schema_assert(str_contains($services, $serviceFile), 'Viewer foundation service must be loaded for later internal phases: ' . $serviceFile);
}

$maintenance = (string) file_get_contents($root . '/app/services/site_maintenance.php');
viewer_schema_assert(!str_contains($maintenance, 'viewer_accounts_enabled()') && str_contains($maintenance, 'viewer_security_maintenance_cleanup()'), 'Scheduled viewer security cleanup must continue independently of the viewer feature flag.');

echo "Viewer schema foundation tests passed.\n";
