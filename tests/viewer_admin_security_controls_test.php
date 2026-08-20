<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_admin_security_controls_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies Phase 2.5 administrator viewer-account security controls and lifecycle reuse.
 *
 * Responsibilities:
 *   - Exercise real suspend/restore/logout-all viewer lifecycle helpers against a deterministic PDO fixture
 *   - Prove Admin and viewer session namespaces remain independent during forced viewer invalidation
 *   - Prove suspension/restoration preserve viewer-owned favourites/collections and forced first-login state
 *   - Verify Admin HTTP wiring is POST/CSRF/authenticated, ID-bounded, localized, and SQL-free
 *   - Verify feature-disable and schema-uncertainty behavior remain fail-closed and operationally isolated
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This fixture uses a PDO subclass so no external database driver is required.
 *
 * Last Updated:
 *   2026-08-19
 */

declare(strict_types=1);

namespace {
    /**
     * Minimal deterministic PDO fixture for the account-state SQL used by Phase 2.5 helpers.
     */
    final class ViewerPhase25Pdo extends \PDO
    {
        /** @var array<string,mixed> */
        public array $data;
        private bool $transaction = false;

        /**
         * @param array<string,mixed> $data Fixture tables/state.
         */
        public function __construct(array $data)
        {
            $this->data = $data;
        }

        /**
         * Start the fixture transaction.
         *
         * @return bool True after the fixture transaction is active.
         */
        public function beginTransaction(): bool
        {
            $this->transaction = true;
            return true;
        }

        /**
         * Commit the fixture transaction.
         *
         * @return bool True after the fixture transaction is closed.
         */
        public function commit(): bool
        {
            $this->transaction = false;
            return true;
        }

        /**
         * Roll back the fixture transaction.
         *
         * @return bool True after the fixture transaction is closed.
         */
        public function rollBack(): bool
        {
            $this->transaction = false;
            return true;
        }

        /**
         * Report whether the fixture transaction is active.
         *
         * @return bool True while a fixture transaction is active.
         */
        public function inTransaction(): bool
        {
            return $this->transaction;
        }

        /**
         * Prepare one fixture SQL statement.
         *
         * @param string $query SQL statement.
         * @param array<mixed> $options Ignored PDO options.
         * @return \PDOStatement|false Prepared fixture statement.
         */
        public function prepare(string $query, array $options = []): \PDOStatement|false
        {
            return new ViewerPhase25Statement($this, $query);
        }
    }

    /**
     * Prepared-statement fixture implementing only SQL shapes used by viewer account invalidation helpers.
     */
    final class ViewerPhase25Statement extends \PDOStatement
    {
        private ViewerPhase25Pdo $pdo;
        private string $sql;
        /** @var mixed */
        private $result = false;
        private int $affected = 0;

        /**
         * Create one prepared-statement fixture.
         *
         * @param ViewerPhase25Pdo $pdo Fixture database.
         * @param string $sql SQL statement.
         */
        public function __construct(ViewerPhase25Pdo $pdo, string $sql)
        {
            $this->pdo = $pdo;
            $this->sql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        }

        /**
         * Execute one supported viewer-security SQL shape against fixture state.
         *
         * @param ?array<mixed> $params Bound parameters.
         * @return bool True after successful fixture execution.
         */
        public function execute(?array $params = null): bool
        {
            $params ??= [];
            $this->result = false;
            $this->affected = 0;
            $sql = $this->sql;

            if (str_starts_with($sql, 'SELECT id, username, role FROM users WHERE id = ?')
                || str_starts_with($sql, 'SELECT id, username, email, role FROM users WHERE id = ?')) {
                $id = (int) ($params[0] ?? 0);
                $this->result = $this->pdo->data['users'][$id] ?? false;
                return true;
            }

            if (str_starts_with($sql, 'SELECT * FROM viewer_accounts WHERE id = ?')) {
                $id = (int) ($params[0] ?? 0);
                $this->result = $this->pdo->data['viewer_accounts'][$id] ?? false;
                return true;
            }

            if (str_starts_with($sql, 'SELECT security_version FROM viewer_accounts WHERE id = ?')) {
                $id = (int) ($params[0] ?? 0);
                $row = $this->pdo->data['viewer_accounts'][$id] ?? null;
                $this->result = is_array($row) ? (int) $row['security_version'] : false;
                return true;
            }

            if (str_starts_with($sql, 'SELECT id, email, password_hash, must_change_password, status, security_version, email_verified_at FROM viewer_accounts WHERE id = ?')) {
                $id = (int) ($params[0] ?? 0);
                $row = $this->pdo->data['viewer_accounts'][$id] ?? null;
                if (!is_array($row)) {
                    $this->result = false;
                    return true;
                }
                $this->result = [
                    'id' => $id,
                    'email' => $row['email'],
                    'password_hash' => $row['password_hash'],
                    'must_change_password' => $row['must_change_password'],
                    'status' => $row['status'],
                    'security_version' => $row['security_version'],
                    'email_verified_at' => $row['email_verified_at'],
                ];
                return true;
            }

            if (str_starts_with($sql, 'SELECT va.id, va.email, va.normalized_email')) {
                $accountId = (int) ($params[0] ?? 0);
                $sessionHash = (string) ($params[1] ?? '');
                $account = $this->pdo->data['viewer_accounts'][$accountId] ?? null;
                if (!is_array($account)) {
                    $this->result = false;
                    return true;
                }
                foreach ($this->pdo->data['viewer_sessions'] as $session) {
                    if ((int) $session['viewer_account_id'] !== $accountId || (string) $session['session_hash'] !== $sessionHash) {
                        continue;
                    }
                    $this->result = [
                        'id' => $accountId,
                        'email' => $account['email'],
                        'normalized_email' => $account['normalized_email'],
                        'password_hash' => $account['password_hash'],
                        'must_change_password' => $account['must_change_password'],
                        'status' => $account['status'],
                        'security_version' => $account['security_version'],
                        'email_verified_at' => $account['email_verified_at'],
                        'viewer_session_id' => $session['id'],
                        'session_security_version' => $session['security_version'],
                        'expires_at' => $session['expires_at'],
                        'revoked_at' => $session['revoked_at'],
                    ];
                    return true;
                }
                $this->result = false;
                return true;
            }

            if (str_starts_with($sql, 'UPDATE viewer_accounts SET status = ?, security_version = security_version + 1')) {
                $id = (int) ($params[4] ?? 0);
                if (!isset($this->pdo->data['viewer_accounts'][$id])) {
                    return true;
                }
                $row =& $this->pdo->data['viewer_accounts'][$id];
                $row['status'] = (string) $params[0];
                $row['security_version'] = (int) $row['security_version'] + 1;
                $row['suspended_at'] = $params[1];
                $row['disabled_at'] = $params[2];
                $row['updated_at'] = $params[3];
                $this->affected = 1;
                return true;
            }

            if (str_starts_with($sql, 'UPDATE viewer_accounts SET security_version = security_version + 1')) {
                $id = (int) ($params[1] ?? 0);
                if (!isset($this->pdo->data['viewer_accounts'][$id])) {
                    return true;
                }
                $this->pdo->data['viewer_accounts'][$id]['security_version']++;
                $this->pdo->data['viewer_accounts'][$id]['updated_at'] = $params[0];
                $this->affected = 1;
                return true;
            }

            $updates = [
                'UPDATE viewer_sessions SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL' => ['viewer_sessions', 'viewer_account_id', 'revoked_at'],
                'UPDATE viewer_remember_tokens SET revoked_at = ? WHERE viewer_account_id = ? AND revoked_at IS NULL' => ['viewer_remember_tokens', 'viewer_account_id', 'revoked_at'],
                'UPDATE viewer_password_reset_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL' => ['viewer_password_reset_tokens', 'viewer_account_id', 'invalidated_at'],
                'UPDATE viewer_email_verification_tokens SET invalidated_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND invalidated_at IS NULL' => ['viewer_email_verification_tokens', 'viewer_account_id', 'invalidated_at'],
                'UPDATE viewer_email_change_requests SET cancelled_at = ? WHERE viewer_account_id = ? AND consumed_at IS NULL AND cancelled_at IS NULL' => ['viewer_email_change_requests', 'viewer_account_id', 'cancelled_at'],
                'UPDATE viewer_collection_share_tokens SET revoked_at = ? WHERE created_by_viewer_account_id = ? AND revoked_at IS NULL' => ['viewer_collection_share_tokens', 'created_by_viewer_account_id', 'revoked_at'],
            ];
            if (isset($updates[$sql])) {
                [$table, $ownerField, $targetField] = $updates[$sql];
                $now = $params[0] ?? null;
                $accountId = (int) ($params[1] ?? 0);
                foreach ($this->pdo->data[$table] as &$row) {
                    if ((int) ($row[$ownerField] ?? 0) !== $accountId || !empty($row[$targetField])) {
                        continue;
                    }
                    if ($table === 'viewer_password_reset_tokens' && !empty($row['consumed_at'])) {
                        continue;
                    }
                    if ($table === 'viewer_email_verification_tokens' && !empty($row['consumed_at'])) {
                        continue;
                    }
                    if ($table === 'viewer_email_change_requests' && (!empty($row['consumed_at']) || !empty($row['cancelled_at']))) {
                        continue;
                    }
                    $row[$targetField] = $now;
                    $this->affected++;
                }
                unset($row);
                return true;
            }

            throw new \RuntimeException('Unexpected Phase 2.5 fixture SQL: ' . $sql);
        }

        /**
         * Fetch the current fixture row.
         *
         * @param int $mode PDO fetch mode.
         * @param int $cursorOrientation Cursor orientation.
         * @param int $cursorOffset Cursor offset.
         * @return mixed Fixture row or false.
         */
        public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
        {
            return is_array($this->result) ? $this->result : false;
        }

        /**
         * Fetch one column from the current fixture result.
         *
         * @param int $column Zero-based result column.
         * @return mixed Column value or false.
         */
        public function fetchColumn(int $column = 0): mixed
        {
            return is_array($this->result) ? (array_values($this->result)[$column] ?? false) : $this->result;
        }

        /**
         * Return the fixture affected-row count.
         *
         * @return int Number of affected fixture rows.
         */
        public function rowCount(): int
        {
            return $this->affected;
        }
    }
}

namespace Gallery\Core {
    /**
     * Return mutable Phase 2.5 fixture configuration.
     *
     * @return array<string,mixed> Fixture configuration.
     */
    function cms_config(): array
    {
        return $GLOBALS['viewer_phase25_config'];
    }

    /**
     * Return the deterministic Phase 2.5 PDO fixture.
     */
    function db(): \PDO
    {
        return $GLOBALS['viewer_phase25_db'];
    }

    /**
     * Return a deterministic SQL timestamp for lifecycle mutation assertions.
     */
    function now_sql(): string
    {
        return '2026-08-19 16:00:00';
    }
}

namespace {
    use function Gallery\Services\current_viewer;
    use function Gallery\Services\schema_inspection_set_query_executor_for_tests;
    use function Gallery\Services\viewer_account_can_authenticate;
    use function Gallery\Services\viewer_account_can_mutate_content;
    use function Gallery\Services\viewer_account_restore;
    use function Gallery\Services\viewer_account_suspend;
    use function Gallery\Services\viewer_first_login_password_context;
    use function Gallery\Services\viewer_first_login_password_state;
    use function Gallery\Services\viewer_session_revoke_all;

    /**
     * Throw when one Phase 2.5 expectation fails.
     */
    function viewer_phase25_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new \RuntimeException($label);
        }
    }

    /**
     * Extract one named function source body for focused HTTP/security assertions.
     */
    function viewer_phase25_function_source(string $source, string $functionName): string
    {
        $needle = 'function ' . $functionName . '(';
        $start = strpos($source, $needle);
        if ($start === false) {
            throw new \RuntimeException('Function not found: ' . $functionName);
        }
        $brace = strpos($source, '{', $start);
        if ($brace === false) {
            throw new \RuntimeException('Function body not found: ' . $functionName);
        }
        $depth = 0;
        for ($index = $brace, $length = strlen($source); $index < $length; $index++) {
            if ($source[$index] === '{') {
                $depth++;
            } elseif ($source[$index] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $index - $start + 1);
                }
            }
        }
        throw new \RuntimeException('Unterminated function: ' . $functionName);
    }

    /**
     * Return one active viewer account fixture row.
     *
     * @param int $id Account id.
     * @param int $securityVersion Security version.
     * @param int $mustChangePassword Forced first-login flag.
     * @return array<string,mixed> Account row.
     */
    function viewer_phase25_account(int $id, int $securityVersion = 1, int $mustChangePassword = 0): array
    {
        return [
            'id' => $id,
            'email' => 'viewer' . $id . '@example.test',
            'normalized_email' => 'viewer' . $id . '@example.test',
            'password_hash' => 'fixture-password-hash-' . $id,
            'must_change_password' => $mustChangePassword,
            'status' => 'active',
            'security_version' => $securityVersion,
            'email_verified_at' => '2026-08-18 10:00:00',
            'suspended_at' => null,
            'disabled_at' => null,
            'updated_at' => '2026-08-18 10:00:00',
        ];
    }

    $root = dirname(__DIR__);
    $GLOBALS['viewer_phase25_config'] = [
        'visitor_vote_secret' => 'phase-25-test-secret',
        'viewer_accounts' => [
            'enabled' => true,
            'registration_mode' => 'invite_only',
            'require_https' => false,
        ],
        'security' => [
            'trusted_proxies' => [],
            'trusted_proxy_headers' => [],
            'trusted_proxy_protocol_headers' => [],
        ],
    ];

    require_once $root . '/app/services/schema_inspection.php';
    require_once $root . '/app/services/security_tokens.php';
    require_once $root . '/app/services/client_ip.php';
    require_once $root . '/app/services/viewer_accounts.php';
    require_once $root . '/app/services/viewer_authentication.php';
    require_once $root . '/app/security.php';
    require_once $root . '/app/controllers/viewer_accounts.php';

    schema_inspection_set_query_executor_for_tests(
        static fn (string $objectType, string $table, string $object, ?string $definition = null): bool => true
    );

    $oldViewerToken = 'old-viewer-session-token';
    $GLOBALS['viewer_phase25_db'] = new ViewerPhase25Pdo([
        'viewer_accounts' => [
            7 => viewer_phase25_account(7, 4, 1),
            8 => viewer_phase25_account(8, 10, 0),
            9 => viewer_phase25_account(9, 3, 0),
            10 => viewer_phase25_account(10, 1, 0),
            11 => viewer_phase25_account(11, 2, 0),
        ],
        'viewer_sessions' => [
            ['id' => 701, 'viewer_account_id' => 7, 'session_hash' => hash('sha256', $oldViewerToken), 'security_version' => 4, 'expires_at' => '2099-01-01 00:00:00', 'revoked_at' => null],
            ['id' => 801, 'viewer_account_id' => 8, 'session_hash' => hash('sha256', 'account-8-token'), 'security_version' => 10, 'expires_at' => '2099-01-01 00:00:00', 'revoked_at' => null],
            ['id' => 901, 'viewer_account_id' => 9, 'session_hash' => hash('sha256', 'account-9-token'), 'security_version' => 3, 'expires_at' => '2099-01-01 00:00:00', 'revoked_at' => null],
        ],
        'viewer_remember_tokens' => [
            ['viewer_account_id' => 7, 'revoked_at' => null],
            ['viewer_account_id' => 8, 'revoked_at' => null],
            ['viewer_account_id' => 9, 'revoked_at' => null],
        ],
        'viewer_password_reset_tokens' => [
            ['viewer_account_id' => 7, 'consumed_at' => null, 'invalidated_at' => null],
            ['viewer_account_id' => 8, 'consumed_at' => null, 'invalidated_at' => null],
        ],
        'viewer_email_verification_tokens' => [
            ['viewer_account_id' => 7, 'consumed_at' => null, 'invalidated_at' => null],
        ],
        'viewer_email_change_requests' => [
            ['viewer_account_id' => 7, 'consumed_at' => null, 'cancelled_at' => null],
        ],
        'viewer_collection_share_tokens' => [
            ['created_by_viewer_account_id' => 7, 'revoked_at' => null],
            ['created_by_viewer_account_id' => 8, 'revoked_at' => null],
        ],
        'viewer_favourites' => [
            ['viewer_account_id' => 7, 'image_id' => 101],
            ['viewer_account_id' => 8, 'image_id' => 102],
        ],
        'viewer_collections' => [
            ['id' => 71, 'owner_viewer_account_id' => 7, 'title' => 'Keep me'],
            ['id' => 81, 'owner_viewer_account_id' => 8, 'title' => 'Also keep me'],
        ],
        'viewer_collection_items' => [
            ['collection_id' => 71, 'image_id' => 101],
            ['collection_id' => 81, 'image_id' => 102],
        ],
        'images' => [['id' => 101], ['id' => 102]],
        'galleries' => [['id' => 1]],
        'gallery_share_links' => [['id' => 1]],
        'users' => [91 => ['id' => 91, 'username' => 'admin', 'email' => 'admin@example.test', 'role' => 'admin']],
    ]);

    $db = $GLOBALS['viewer_phase25_db'];
    $preservedBeforeSuspend = [
        'favourites' => $db->data['viewer_favourites'],
        'collections' => $db->data['viewer_collections'],
        'collection_items' => $db->data['viewer_collection_items'],
        'images' => $db->data['images'],
        'galleries' => $db->data['galleries'],
        'gallery_share_links' => $db->data['gallery_share_links'],
        'users' => $db->data['users'],
    ];

    $_SESSION = [
        'user_id' => 91,
        'viewer_auth' => ['account_id' => 7, 'security_version' => 4, 'token' => $oldViewerToken],
        'viewer_reauthentication' => ['account_id' => 7, 'security_version' => 4, 'viewer_session_id' => 701, 'expires_at' => time() + 600],
        'viewer_email_change_confirmation' => ['request_id' => 17],
        'viewer_first_login_password' => [
            'account_id' => 7,
            'security_version' => 4,
            'expires_at' => time() + 600,
            'context' => viewer_first_login_password_context(7, 4, (string) $db->data['viewer_accounts'][7]['password_hash']),
        ],
    ];

    viewer_phase25_assert(viewer_account_suspend(7), 'Active viewer must suspend through the existing lifecycle transition helper.');
    viewer_phase25_assert($db->data['viewer_accounts'][7]['status'] === 'suspended', 'Suspension must set the durable viewer state to suspended.');
    viewer_phase25_assert($db->data['viewer_accounts'][7]['security_version'] === 5, 'Suspension must rotate security_version exactly once.');
    viewer_phase25_assert($db->data['viewer_accounts'][7]['must_change_password'] === 1, 'Suspension must preserve must_change_password.');
    viewer_phase25_assert(!viewer_account_can_authenticate($db->data['viewer_accounts'][7]), 'Suspended viewer account must be ineligible for password authentication.');
    viewer_phase25_assert(!empty($db->data['viewer_sessions'][0]['revoked_at']), 'Suspension must revoke viewer session rows.');
    viewer_phase25_assert(!empty($db->data['viewer_remember_tokens'][0]['revoked_at']), 'Suspension must revoke remember-me rows.');
    viewer_phase25_assert(!empty($db->data['viewer_password_reset_tokens'][0]['invalidated_at']), 'Suspension must invalidate outstanding password-reset authority.');
    viewer_phase25_assert(!empty($db->data['viewer_email_verification_tokens'][0]['invalidated_at']), 'Suspension must invalidate durable account email-verification authority.');
    viewer_phase25_assert(!empty($db->data['viewer_email_change_requests'][0]['cancelled_at']), 'Suspension must cancel pending email-change authority.');
    viewer_phase25_assert(!empty($db->data['viewer_collection_share_tokens'][0]['revoked_at']), 'Suspension must revoke dormant viewer-created collection-share capabilities.');
    viewer_phase25_assert(isset($_SESSION['user_id']) && $_SESSION['user_id'] === 91, 'Suspension must preserve the Admin PHP-session principal.');
    viewer_phase25_assert(!isset($_SESSION['viewer_auth'], $_SESSION['viewer_reauthentication'], $_SESSION['viewer_email_change_confirmation']), 'Suspension must clear only matching local viewer authentication authority.');
    viewer_phase25_assert(current_viewer() === null, 'current_viewer() must be invalid immediately after suspension.');
    viewer_phase25_assert(viewer_first_login_password_state() === null && !isset($_SESSION['viewer_first_login_password']), 'Suspension must make a pre-existing limited first-login state unusable through live state/security-version validation.');
    viewer_phase25_assert($preservedBeforeSuspend['favourites'] === $db->data['viewer_favourites'], 'Suspension must preserve favourites.');
    viewer_phase25_assert($preservedBeforeSuspend['collections'] === $db->data['viewer_collections'], 'Suspension must preserve private collections.');
    viewer_phase25_assert($preservedBeforeSuspend['collection_items'] === $db->data['viewer_collection_items'], 'Suspension must preserve collection items.');
    viewer_phase25_assert($preservedBeforeSuspend['images'] === $db->data['images'] && $preservedBeforeSuspend['galleries'] === $db->data['galleries'], 'Suspension must not mutate images or galleries.');
    viewer_phase25_assert($preservedBeforeSuspend['gallery_share_links'] === $db->data['gallery_share_links'], 'Suspension must not alter existing gallery share links.');
    viewer_phase25_assert($preservedBeforeSuspend['users'] === $db->data['users'], 'Suspension must not alter Admin accounts.');
    $adminAfterSuspend = \Gallery\Core\current_user();
    viewer_phase25_assert(is_array($adminAfterSuspend) && (int) ($adminAfterSuspend['id'] ?? 0) === 91 && (string) ($adminAfterSuspend['role'] ?? '') === 'admin', 'current_user() must remain the authenticated Admin after suspending the simultaneous viewer principal.');

    viewer_phase25_assert(viewer_account_restore(7), 'Suspended viewer must restore through the existing lifecycle transition helper.');
    viewer_phase25_assert($db->data['viewer_accounts'][7]['status'] === 'active', 'Restoration must return the durable state to active.');
    viewer_phase25_assert($db->data['viewer_accounts'][7]['security_version'] === 6, 'Restoration must rotate security_version again.');
    viewer_phase25_assert($db->data['viewer_accounts'][7]['must_change_password'] === 1, 'Restoration must not clear forced first-login password replacement.');
    viewer_phase25_assert(viewer_account_can_authenticate($db->data['viewer_accounts'][7]), 'Restoration must permit a future fresh credential check again.');
    viewer_phase25_assert(!viewer_account_can_mutate_content($db->data['viewer_accounts'][7]), 'Restored Admin-created viewer must still lack normal content authority until the forced password replacement completes.');
    viewer_phase25_assert(!empty($db->data['viewer_sessions'][0]['revoked_at']) && !empty($db->data['viewer_remember_tokens'][0]['revoked_at']), 'Restoration must not resurrect old session or remember authority.');
    viewer_phase25_assert(!empty($db->data['viewer_password_reset_tokens'][0]['invalidated_at']) && !empty($db->data['viewer_collection_share_tokens'][0]['revoked_at']), 'Restoration must not resurrect old reset/share authority.');
    $_SESSION['viewer_auth'] = ['account_id' => 7, 'security_version' => 4, 'token' => $oldViewerToken];
    viewer_phase25_assert(current_viewer() === null && !isset($_SESSION['viewer_auth']), 'A pre-suspension viewer session token must remain unusable after restoration.');

    $account8Preserved = [
        'favourites' => $db->data['viewer_favourites'],
        'collections' => $db->data['viewer_collections'],
        'collection_items' => $db->data['viewer_collection_items'],
        'share' => $db->data['viewer_collection_share_tokens'][1],
        'password_hash' => $db->data['viewer_accounts'][8]['password_hash'],
        'must_change_password' => $db->data['viewer_accounts'][8]['must_change_password'],
    ];
    $_SESSION = [
        'user_id' => 91,
        'viewer_auth' => ['account_id' => 8, 'security_version' => 10, 'token' => 'account-8-token'],
        'viewer_reauthentication' => ['account_id' => 8, 'security_version' => 10, 'viewer_session_id' => 801, 'expires_at' => time() + 600],
    ];
    $versionAfterLogoutAll = viewer_session_revoke_all(8);
    viewer_phase25_assert($versionAfterLogoutAll === 11 && $db->data['viewer_accounts'][8]['security_version'] === 11, 'Sign out everywhere must rotate viewer security_version.');
    viewer_phase25_assert($db->data['viewer_accounts'][8]['status'] === 'active', 'Sign out everywhere must leave an active viewer account active.');
    viewer_phase25_assert(!empty($db->data['viewer_sessions'][1]['revoked_at']) && !empty($db->data['viewer_remember_tokens'][1]['revoked_at']), 'Sign out everywhere must revoke session and remember authority.');
    viewer_phase25_assert(!empty($db->data['viewer_password_reset_tokens'][1]['invalidated_at']), 'Sign out everywhere must invalidate outstanding reset authority according to the existing helper semantics.');
    viewer_phase25_assert(empty($db->data['viewer_sessions'][2]['revoked_at']) && empty($db->data['viewer_remember_tokens'][2]['revoked_at']), 'Sign out everywhere must not affect another viewer.');
    viewer_phase25_assert(isset($_SESSION['user_id']) && $_SESSION['user_id'] === 91 && !isset($_SESSION['viewer_auth'], $_SESSION['viewer_reauthentication']), 'Sign out everywhere must preserve Admin authority while clearing the matching local viewer namespace.');
    viewer_phase25_assert($account8Preserved['favourites'] === $db->data['viewer_favourites'] && $account8Preserved['collections'] === $db->data['viewer_collections'] && $account8Preserved['collection_items'] === $db->data['viewer_collection_items'], 'Sign out everywhere must preserve favourites and collections.');
    viewer_phase25_assert($account8Preserved['share'] === $db->data['viewer_collection_share_tokens'][1], 'Sign out everywhere must not revoke collection-share capability because the account remains active.');
    viewer_phase25_assert($account8Preserved['password_hash'] === $db->data['viewer_accounts'][8]['password_hash'] && $account8Preserved['must_change_password'] === $db->data['viewer_accounts'][8]['must_change_password'], 'Sign out everywhere must not change password or must_change_password.');

    $GLOBALS['viewer_phase25_config']['viewer_accounts']['enabled'] = false;
    viewer_phase25_assert(viewer_account_suspend(10), 'Admin suspension service must remain callable while the viewer frontend is disabled.');
    viewer_phase25_assert(viewer_account_restore(10), 'Admin restoration service must remain callable while the viewer frontend is disabled.');
    viewer_phase25_assert(viewer_session_revoke_all(10) === 4, 'Admin logout-all service must remain callable while the viewer frontend is disabled.');
    viewer_phase25_assert($db->data['viewer_accounts'][10]['status'] === 'active', 'Feature-disable security controls must not change the requested final account status.');
    $GLOBALS['viewer_phase25_config']['viewer_accounts']['enabled'] = true;

    schema_inspection_set_query_executor_for_tests(
        static fn (string $objectType, string $table, string $object, ?string $definition = null): bool => $table !== 'viewer_collection_share_tokens'
    );
    $schemaFailureCaught = false;
    try {
        viewer_account_suspend(11);
    } catch (\RuntimeException) {
        $schemaFailureCaught = true;
    }
    viewer_phase25_assert($schemaFailureCaught, 'Unknown/missing transition schema must fail suspension closed.');
    viewer_phase25_assert($db->data['viewer_accounts'][11]['status'] === 'active' && $db->data['viewer_accounts'][11]['security_version'] === 2, 'Schema failure must not partially mutate the viewer account.');
    schema_inspection_set_query_executor_for_tests(
        static fn (string $objectType, string $table, string $object, ?string $definition = null): bool => true
    );

    viewer_phase25_assert(\Gallery\Controllers\viewer_admin_account_id_parse('1') === 1, 'Positive viewer id must parse.');
    viewer_phase25_assert(\Gallery\Controllers\viewer_admin_account_id_parse((string) PHP_INT_MAX) === PHP_INT_MAX, 'PHP_INT_MAX viewer id must parse without overflow.');
    foreach ([null, '', '0', '-1', '+1', '1.0', 'abc', [], (string) PHP_INT_MAX . '0'] as $invalidId) {
        viewer_phase25_assert(\Gallery\Controllers\viewer_admin_account_id_parse($invalidId) === 0, 'Malformed/overflow viewer account id must be rejected.');
    }

    viewer_phase25_assert(function_exists('Gallery\\Services\\viewer_account_suspend') && function_exists('Gallery\\Services\\viewer_account_restore') && function_exists('Gallery\\Services\\viewer_session_revoke_all'), 'Runtime lifecycle symbols imported by the Admin controller must exist in Gallery\\Services.');

    $controller = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
    $adminPage = viewer_phase25_function_source($controller, 'cms_admin_viewer_invitations');
    $postGuard = strpos($adminPage, "if (request_method() === 'POST')");
    foreach (['suspend_account', 'restore_account', 'revoke_sessions'] as $action) {
        $position = strpos($adminPage, "elseif (\$action === '{$action}')");
        viewer_phase25_assert($postGuard !== false && $position !== false && $postGuard < $position, 'Admin viewer security mutation must remain inside POST handling: ' . $action);
    }
    viewer_phase25_assert(str_contains($adminPage, 'require_admin();') && str_contains($adminPage, 'current_user()') && str_contains($adminPage, "['role'] ?? '') !== 'admin'"), 'Admin viewer security controls must require the administrator principal, not viewer identity.');
    viewer_phase25_assert(str_contains($adminPage, 'verify_csrf();'), 'All Admin viewer security mutations must use Admin CSRF.');
    viewer_phase25_assert(str_contains($adminPage, 'viewer_account_suspend($viewerAccountId)') && str_contains($adminPage, 'viewer_account_restore($viewerAccountId)') && str_contains($adminPage, 'viewer_session_revoke_all($viewerAccountId)'), 'Admin HTTP wiring must delegate to existing authoritative viewer lifecycle/session services.');
    viewer_phase25_assert(!preg_match('/(?:UPDATE|DELETE|INSERT)\s+viewer_/i', $adminPage), 'Admin controller must not duplicate viewer lifecycle SQL.');
    viewer_phase25_assert(str_contains($adminPage, 'viewer.account_admin_suspended') && str_contains($adminPage, 'viewer.account_admin_restored') && str_contains($adminPage, 'viewer.account_admin_sessions_revoked'), 'Admin audit event attribution must cover all Phase 2.5 controls.');
    viewer_phase25_assert(!str_contains($adminPage, 'session_destroy()') && !str_contains($adminPage, "unset(\$_SESSION['user_id'])"), 'Admin security controls must never destroy the shared PHP/Admin session authority.');
    viewer_phase25_assert(str_contains($adminPage, 'VIEWER_ACCOUNT_STATUS_ACTIVE') && str_contains($adminPage, 'VIEWER_ACCOUNT_STATUS_SUSPENDED'), 'Admin account table must present state-specific actions.');
    viewer_phase25_assert(str_contains($adminPage, "value=\"suspend_account\"") && str_contains($adminPage, "value=\"restore_account\"") && str_contains($adminPage, "value=\"revoke_sessions\""), 'Admin account table must render Suspend, Restore, and Sign out everywhere POST forms.');
    viewer_phase25_assert(!str_contains($adminPage, "value=\"disable_account\""), 'Phase 2.5 must not expose a second Admin-facing Disable action.');

    $accountsSource = (string) file_get_contents($root . '/app/services/viewer_accounts.php');
    $transition = viewer_phase25_function_source($accountsSource, 'viewer_account_transition_status');
    $logoutAll = viewer_phase25_function_source($accountsSource, 'viewer_session_revoke_all');
    $invalidate = viewer_phase25_function_source($accountsSource, 'viewer_account_invalidate_authentication');
    viewer_phase25_assert(str_contains($transition, 'FOR UPDATE') && str_contains($transition, 'security_version = security_version + 1'), 'Suspend/restore must remain transactional and security-versioned in the existing service.');
    foreach (['viewer_sessions', 'viewer_remember_tokens', 'viewer_password_reset_tokens', 'viewer_email_verification_tokens', 'viewer_email_change_requests', 'viewer_collection_share_tokens'] as $table) {
        viewer_phase25_assert(str_contains($transition, $table), 'Account transition must retain revocation coverage for ' . $table . '.');
    }
    viewer_phase25_assert(str_contains($logoutAll, 'viewer_account_invalidate_authentication($viewerAccountId)'), 'Sign out everywhere must reuse central authentication invalidation.');
    viewer_phase25_assert(str_contains($invalidate, 'viewer_sessions') && str_contains($invalidate, 'viewer_remember_tokens') && str_contains($invalidate, 'viewer_password_reset_tokens'), 'Central logout-all invalidation must revoke sessions, remember tokens, and reset authority.');
    viewer_phase25_assert(!str_contains($invalidate, 'status =') && !str_contains($invalidate, 'viewer_favourites') && !str_contains($invalidate, 'viewer_collections'), 'Sign out everywhere must not change account status or viewer-owned content.');

    $authenticationSource = (string) file_get_contents($root . '/app/services/viewer_authentication.php');
    $authenticate = viewer_phase25_function_source($authenticationSource, 'viewer_authenticate_password');
    viewer_phase25_assert(str_contains($authenticate, 'viewer_account_can_authenticate($lockedAccount)'), 'Viewer login must continue checking durable account state before authority is established.');
    viewer_phase25_assert(str_contains($authenticationSource, "return 'authentication_failed';"), 'Viewer login must retain the generic public failure code for ineligible/suspended accounts.');

    foreach (['en', 'cs', 'de', 'sv'] as $language) {
        $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ([
            'viewer.admin.accounts.status_active',
            'viewer.admin.accounts.status_suspended',
            'viewer.admin.accounts.suspend_button',
            'viewer.admin.accounts.restore_button',
            'viewer.admin.accounts.sign_out_everywhere_button',
            'viewer.admin.accounts.suspend_confirm',
            'viewer.admin.accounts.restore_confirm',
            'viewer.admin.accounts.sign_out_everywhere_confirm',
            'viewer.admin.accounts.security_action_failed',
        ] as $key) {
            viewer_phase25_assert(isset($catalog[$key]) && is_string($catalog[$key]) && $catalog[$key] !== '', 'Missing Phase 2.5 translation key ' . $key . ' in ' . $language . '.');
        }
    }

    $dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
    viewer_phase25_assert(substr_count($dispatch, "'admin_viewer_invitations' =>") === 1, 'Phase 2.5 must retain the historical Admin viewer-account route rather than adding a parallel subsystem.');
    foreach (['viewer_collection_share', 'viewer_public_collection', 'viewer_profile', 'viewer_upload', 'viewer_totp', 'viewer_oidc', 'viewer_passkey'] as $forbiddenRoute) {
        viewer_phase25_assert(!str_contains($dispatch, "'{$forbiddenRoute}' =>"), 'Phase 2.5 must not expose out-of-scope route: ' . $forbiddenRoute);
    }

    echo "Viewer Phase 2.5 Admin security-control tests passed.\n";
}
