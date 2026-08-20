<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_open_registration_policy_phase40_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects Phase 4.0 open-registration policy and staged-lifecycle foundations.
 *
 * Responsibilities:
 *   - Verify bounded disabled/invite_only/open policy normalization behind the master wrapper
 *   - Verify invitation-backed versus open-origin staging uses viewer_invitation_id only
 *   - Verify current-mode authorization is enforced by verification and final activation services
 *   - Verify restrictive mode transitions durably cancel only open-origin staging
 *   - Verify cleanup failure cannot fail open or resurrect stale open-origin authority
 *   - Protect Phase 4.0 policy authority after the Phase 4.1 public HTTP wiring is added
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
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

namespace Gallery\Tests\Phase40 {
    /**
     * Minimal deterministic PDO-like fixture for registration-mode cleanup.
     */
    final class RegistrationPolicyPdo
    {
        /** @var array<int,array<string,mixed>> Mutable staged-registration fixture rows. */
        public array $rows = [];

        /** Whether one synthetic transaction is active. */
        public bool $transaction = false;

        /** Force the open-origin cancellation UPDATE to fail. */
        public bool $failCancellation = false;

        /** @var array<int,string> Registration mode observed when cancellation SQL executed. */
        public array $cancellationObservedModes = [];

        /**
         * Return whether the fixture transaction is active.
         */
        public function inTransaction(): bool
        {
            return $this->transaction;
        }

        /**
         * Begin one fixture transaction.
         */
        public function beginTransaction(): bool
        {
            $this->transaction = true;
            return true;
        }

        /**
         * Commit one fixture transaction.
         */
        public function commit(): bool
        {
            $this->transaction = false;
            return true;
        }

        /**
         * Roll back one fixture transaction.
         */
        public function rollBack(): bool
        {
            $this->transaction = false;
            return true;
        }

        /**
         * Prepare one SQL statement used by the focused lifecycle cleanup contract.
         */
        public function prepare(string $sql): RegistrationPolicyStatement
        {
            return new RegistrationPolicyStatement($this, $sql);
        }
    }

    /**
     * Minimal deterministic statement fixture for the Phase 4.0 cleanup path.
     */
    final class RegistrationPolicyStatement
    {
        /** Number of rows affected by the latest execution. */
        private int $rowCount = 0;

        /** Scalar result used by the singleton capacity-lock SELECT. */
        private mixed $scalar = false;

        /**
         * Store the parent fixture and SQL text.
         */
        public function __construct(private RegistrationPolicyPdo $pdo, private string $sql)
        {
        }

        /**
         * Execute one supported focused SQL contract.
         *
         * @param array<int,mixed> $parameters Bound SQL parameters.
         */
        public function execute(array $parameters = []): bool
        {
            $this->rowCount = 0;
            $this->scalar = false;

            if (str_contains($this->sql, 'INSERT INTO viewer_registration_state')) {
                return true;
            }

            if (str_contains($this->sql, 'SELECT active_request_count FROM viewer_registration_state')) {
                $this->scalar = count($this->pdo->rows);
                return true;
            }

            if (str_contains($this->sql, 'WHERE viewer_invitation_id IS NULL AND status IN (?, ?)')) {
                $this->pdo->cancellationObservedModes[] = (string) ($GLOBALS['viewer_phase40_settings'][\Gallery\Services\VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY] ?? '');
                if ($this->pdo->failCancellation) {
                    throw new \RuntimeException('Synthetic cancellation failure.');
                }

                $cancelledStatus = (string) ($parameters[0] ?? '');
                $cancelledAt = (string) ($parameters[1] ?? '');
                $eligibleStatuses = [(string) ($parameters[3] ?? ''), (string) ($parameters[4] ?? '')];
                foreach ($this->pdo->rows as &$row) {
                    if (($row['viewer_invitation_id'] ?? null) !== null
                        || !in_array((string) ($row['status'] ?? ''), $eligibleStatuses, true)
                        || !empty($row['cancelled_at'])) {
                        continue;
                    }
                    $row['status'] = $cancelledStatus;
                    $row['cancelled_at'] = $cancelledAt;
                    $this->rowCount++;
                }
                unset($row);
                return true;
            }

            throw new \RuntimeException('Unexpected Phase 4.0 fixture SQL: ' . $this->sql);
        }

        /**
         * Return one scalar fixture value.
         */
        public function fetchColumn(): mixed
        {
            return $this->scalar;
        }

        /**
         * Return the affected-row count from the latest execution.
         */
        public function rowCount(): int
        {
            return $this->rowCount;
        }
    }
}

namespace Gallery\Core {
    /**
     * Return mutable viewer-account configuration for the Phase 4.0 policy test.
     *
     * @return array<string,mixed> Fixture configuration.
     */
    function cms_config(): array
    {
        return $GLOBALS['viewer_phase40_config'] ?? [];
    }

    /**
     * Return the deterministic Phase 4.0 database fixture.
     */
    function db(): object
    {
        return $GLOBALS['viewer_phase40_pdo'];
    }

    /**
     * Return one deterministic SQL timestamp for cancellation assertions.
     */
    function now_sql(): string
    {
        return '2026-08-20 07:45:00';
    }
}

namespace Gallery\Services {
    /**
     * Return the mutable master Viewer Accounts feature state.
     */
    function feature_flag_enabled(string $feature): bool
    {
        return $feature === 'viewer_accounts' && !empty($GLOBALS['viewer_phase40_master_enabled']);
    }

    /**
     * Return one mutable in-memory application setting.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $GLOBALS['viewer_phase40_settings'] ?? [])
            ? (string) $GLOBALS['viewer_phase40_settings'][$key]
            : $default;
    }

    /**
     * Persist one mutable in-memory application setting.
     */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['viewer_phase40_settings'][$key] = $value;
    }

    /**
     * Return an available aggregate schema fixture.
     *
     * @param string $feature Feature identifier.
     * @param array<int,mixed> $requirements Ignored focused requirements.
     * @return array<string,string> Available schema status.
     */
    function schema_inspection_feature(string $feature, array $requirements): array
    {
        return ['state' => 'available'];
    }

    /**
     * Return one available table requirement fixture.
     *
     * @return array<string,string> Available table fixture.
     */
    function schema_inspection_table(string $table): array
    {
        return ['state' => 'available'];
    }

    /**
     * Return one available column requirement fixture.
     *
     * @return array<string,string> Available column fixture.
     */
    function schema_inspection_column(string $table, string $column): array
    {
        return ['state' => 'available'];
    }

    /**
     * Resolve the focused aggregate schema fixture as available.
     *
     * @param array<string,mixed> $status Aggregate schema status.
     */
    function schema_inspection_is_available(array $status): bool
    {
        return (string) ($status['state'] ?? '') === 'available';
    }
}

namespace {
    use Gallery\Tests\Phase40\RegistrationPolicyPdo;
    use function Gallery\Services\viewer_accounts_enabled;
    use function Gallery\Services\viewer_accounts_set_admin_registration_mode;
    use function Gallery\Services\viewer_registration_mode;
    use function Gallery\Services\viewer_registration_mode_normalize;
    use function Gallery\Services\viewer_registration_request_allowed_by_current_mode;
    use function Gallery\Services\viewer_registration_request_is_invitation_backed;
    use function Gallery\Services\viewer_registration_request_is_open_origin;

    /**
     * Throw when one Phase 4.0 policy expectation fails.
     */
    function viewer_phase40_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    /**
     * Extract one named PHP function source for ordering/static boundary assertions.
     */
    function viewer_phase40_function_source(string $source, string $functionName): string
    {
        $needle = 'function ' . $functionName . '(';
        $start = strpos($source, $needle);
        if ($start === false) {
            return '';
        }
        $brace = strpos($source, '{', $start);
        if ($brace === false) {
            return '';
        }
        $depth = 0;
        $length = strlen($source);
        for ($index = $brace; $index < $length; $index++) {
            if ($source[$index] === '{') {
                $depth++;
            } elseif ($source[$index] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $index - $start + 1);
                }
            }
        }
        return '';
    }

    $root = dirname(__DIR__);
    $GLOBALS['viewer_phase40_master_enabled'] = true;
    $GLOBALS['viewer_phase40_settings'] = [];
    $GLOBALS['viewer_phase40_config'] = [
        'viewer_accounts' => [
            'enabled' => true,
            'registration_mode' => 'disabled',
        ],
    ];
    $GLOBALS['viewer_phase40_pdo'] = new RegistrationPolicyPdo();

    require_once $root . '/app/services/viewer_accounts.php';
    require_once $root . '/app/services/viewer_registration.php';

    // Policy normalization remains bounded and conservative.
    viewer_phase40_assert(viewer_registration_mode_normalize('disabled') === 'disabled', 'disabled must be recognized.');
    viewer_phase40_assert(viewer_registration_mode_normalize('invite_only') === 'invite_only', 'invite_only must be recognized.');
    viewer_phase40_assert(viewer_registration_mode_normalize(' OPEN ') === 'open', 'open must be recognized internally after normalization.');
    viewer_phase40_assert(viewer_registration_mode_normalize('unexpected') === 'disabled', 'Unknown registration policy must fail closed to disabled.');

    $GLOBALS['viewer_phase40_settings'][\Gallery\Services\VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY] = 'open';
    viewer_phase40_assert(viewer_registration_mode() === 'open', 'Backend Admin storage must recognize open while the master feature is enabled.');
    viewer_phase40_assert(viewer_accounts_enabled(), 'An explicit open backend policy must keep viewer authentication available when the master feature is enabled.');
    $GLOBALS['viewer_phase40_master_enabled'] = false;
    viewer_phase40_assert(viewer_registration_mode() === 'disabled', 'Viewer Accounts master OFF must dominate an open subordinate policy.');
    viewer_phase40_assert(!viewer_accounts_enabled(), 'Viewer Accounts master OFF must disable the viewer subsystem regardless of registration mode.');
    $GLOBALS['viewer_phase40_master_enabled'] = true;

    // Registration origin uses the existing nullable invitation foreign key only.
    $openRow = ['viewer_invitation_id' => null];
    $invitedRow = ['viewer_invitation_id' => 42];
    viewer_phase40_assert(viewer_registration_request_is_open_origin($openRow), 'NULL viewer_invitation_id must identify open-origin staging.');
    viewer_phase40_assert(!viewer_registration_request_is_invitation_backed($openRow), 'Open-origin staging must not be invitation-backed.');
    viewer_phase40_assert(viewer_registration_request_is_invitation_backed($invitedRow), 'Positive viewer_invitation_id must identify invitation-backed staging.');
    viewer_phase40_assert(!viewer_registration_request_is_open_origin($invitedRow), 'Invitation-backed staging must not be classified as open-origin.');

    // Current-mode authorization is independent of the page/browser that created staging.
    foreach ([
        'disabled' => [false, false],
        'invite_only' => [false, true],
        'open' => [true, true],
    ] as $mode => [$openAllowed, $invitedAllowed]) {
        $GLOBALS['viewer_phase40_settings'][\Gallery\Services\VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY] = $mode;
        viewer_phase40_assert(viewer_registration_request_allowed_by_current_mode($openRow) === $openAllowed, $mode . ' open-origin authorization mismatch.');
        viewer_phase40_assert(viewer_registration_request_allowed_by_current_mode($invitedRow) === $invitedAllowed, $mode . ' invitation-backed authorization mismatch.');
    }

    // open -> invite_only must restrict first and then cancel only open-origin staging.
    $pdo = $GLOBALS['viewer_phase40_pdo'];
    $pdo->rows = [
        1 => ['id' => 1, 'viewer_invitation_id' => null, 'status' => \Gallery\Services\VIEWER_REGISTRATION_STATUS_PENDING, 'cancelled_at' => null],
        2 => ['id' => 2, 'viewer_invitation_id' => null, 'status' => \Gallery\Services\VIEWER_REGISTRATION_STATUS_EMAIL_VERIFIED, 'cancelled_at' => null],
        3 => ['id' => 3, 'viewer_invitation_id' => 77, 'status' => \Gallery\Services\VIEWER_REGISTRATION_STATUS_PENDING, 'cancelled_at' => null],
    ];
    $pdo->cancellationObservedModes = [];
    $GLOBALS['viewer_phase40_settings'][\Gallery\Services\VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY] = 'open';
    $cancelled = viewer_accounts_set_admin_registration_mode('invite_only');
    viewer_phase40_assert($cancelled === 2, 'open -> invite_only must cancel both pending and email-verified open-origin staging.');
    viewer_phase40_assert(viewer_registration_mode() === 'invite_only', 'open -> invite_only must persist the restrictive policy.');
    viewer_phase40_assert($pdo->cancellationObservedModes === ['invite_only'], 'Restrictive policy must be persisted before cleanup runs.');
    viewer_phase40_assert($pdo->rows[1]['status'] === \Gallery\Services\VIEWER_REGISTRATION_STATUS_CANCELLED, 'Pending open-origin staging must be cancelled.');
    viewer_phase40_assert($pdo->rows[2]['status'] === \Gallery\Services\VIEWER_REGISTRATION_STATUS_CANCELLED, 'Email-verified open-origin staging must be cancelled before password activation.');
    viewer_phase40_assert($pdo->rows[3]['status'] === \Gallery\Services\VIEWER_REGISTRATION_STATUS_PENDING && empty($pdo->rows[3]['cancelled_at']), 'Invitation-backed staging must survive open -> invite_only.');

    // Re-enabling open cleans stale open-origin authority before open becomes effective.
    $pdo->rows[4] = ['id' => 4, 'viewer_invitation_id' => null, 'status' => \Gallery\Services\VIEWER_REGISTRATION_STATUS_PENDING, 'cancelled_at' => null];
    $pdo->cancellationObservedModes = [];
    $cancelled = viewer_accounts_set_admin_registration_mode('open');
    viewer_phase40_assert($cancelled === 1, 'invite_only -> open must retire any stale open-origin staging before enabling open.');
    viewer_phase40_assert($pdo->cancellationObservedModes === ['invite_only'], 'Stale-authority cleanup must run while the restrictive mode is still effective.');
    viewer_phase40_assert(viewer_registration_mode() === 'open', 'Backend transition may enable open only after stale-authority cleanup succeeds.');
    viewer_phase40_assert($pdo->rows[1]['status'] === \Gallery\Services\VIEWER_REGISTRATION_STATUS_CANCELLED && $pdo->rows[4]['status'] === \Gallery\Services\VIEWER_REGISTRATION_STATUS_CANCELLED, 'Old open-origin verification authority must not resurrect when open is re-enabled.');

    // open -> disabled applies the same origin-scoped cancellation rule.
    $pdo->rows[5] = ['id' => 5, 'viewer_invitation_id' => null, 'status' => \Gallery\Services\VIEWER_REGISTRATION_STATUS_PENDING, 'cancelled_at' => null];
    $cancelled = viewer_accounts_set_admin_registration_mode('disabled');
    viewer_phase40_assert($cancelled === 1, 'open -> disabled must cancel newly outstanding open-origin staging.');
    viewer_phase40_assert(viewer_registration_mode() === 'disabled', 'open -> disabled must make registration unavailable.');
    viewer_phase40_assert($pdo->rows[3]['status'] === \Gallery\Services\VIEWER_REGISTRATION_STATUS_PENDING, 'Registration-mode cleanup must never cancel invitation-backed staging.');

    // Cleanup is defense in depth. Failure must leave restrictive policy effective and must block re-enabling open.
    $GLOBALS['viewer_phase40_settings'][\Gallery\Services\VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY] = 'open';
    $pdo->failCancellation = true;
    $restrictFailure = false;
    try {
        viewer_accounts_set_admin_registration_mode('invite_only');
    } catch (RuntimeException) {
        $restrictFailure = true;
    }
    viewer_phase40_assert($restrictFailure, 'Synthetic restrictive-transition cleanup failure must be reported.');
    viewer_phase40_assert(viewer_registration_mode() === 'invite_only', 'Cleanup failure after open -> invite_only must still leave the restrictive policy effective.');

    $enableFailure = false;
    try {
        viewer_accounts_set_admin_registration_mode('open');
    } catch (RuntimeException) {
        $enableFailure = true;
    }
    viewer_phase40_assert($enableFailure, 'Synthetic stale-cleanup failure must prevent re-enabling open.');
    viewer_phase40_assert(viewer_registration_mode() === 'invite_only', 'Open must not become effective when stale-authority cleanup cannot complete.');
    $pdo->failCancellation = false;

    $accountsService = (string) file_get_contents($root . '/app/services/viewer_accounts.php');
    $registrationService = (string) file_get_contents($root . '/app/services/viewer_registration.php');
    $httpService = (string) file_get_contents($root . '/app/services/viewer_http.php');
    $controller = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
    $dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
    $routing = (string) file_get_contents($root . '/app/bootstrap/routing.php');
    $migration = (string) file_get_contents($root . '/database/migrations/202608180002_viewer_registration_foundations.php');

    viewer_phase40_assert(str_contains($migration, 'viewer_invitation_id BIGINT UNSIGNED NULL'), 'Existing schema must already distinguish invitation-backed versus open-origin staging.');
    foreach (['registration_origin', 'registration_type', 'signup_source', 'open_registration_token'] as $unexpectedColumn) {
        viewer_phase40_assert(!str_contains($migration, $unexpectedColumn), 'Phase 4.0 must not require a new registration-origin schema column: ' . $unexpectedColumn);
    }

    $validateSource = viewer_phase40_function_source($registrationService, 'viewer_registration_verification_validate');
    $confirmSource = viewer_phase40_function_source($registrationService, 'viewer_registration_verification_confirm');
    $activateSource = viewer_phase40_function_source($registrationService, 'viewer_registration_activate_verified');
    $beginSource = viewer_phase40_function_source($registrationService, 'viewer_registration_request_begin');
    $cancelSource = viewer_phase40_function_source($registrationService, 'viewer_registration_cancel_open_origin_staging');
    $modeSetSource = viewer_phase40_function_source($accountsService, 'viewer_accounts_set_admin_registration_mode');

    viewer_phase40_assert(str_contains($validateSource, 'viewer_registration_request_allowed_by_current_mode($row)'), 'Verification validation must re-check the current registration policy.');
    viewer_phase40_assert(str_contains($confirmSource, 'viewer_registration_request_allowed_by_current_mode($row)'), 'Verification confirmation must re-check the current registration policy.');
    viewer_phase40_assert(str_contains($activateSource, 'viewer_registration_request_allowed_by_current_mode($request)'), 'Final activation must re-check the current registration policy.');
    viewer_phase40_assert(strpos($activateSource, 'viewer_registration_request_allowed_by_current_mode($request)') < strpos($activateSource, 'INSERT INTO viewer_accounts'), 'Final current-mode authorization must run before durable viewer account creation.');
    viewer_phase40_assert(str_contains($beginSource, '$mode = viewer_registration_mode();') && substr_count($beginSource, '$mode = viewer_registration_mode();') >= 2, 'Staged request creation must re-read current policy after entering its serialized transaction boundary.');
    viewer_phase40_assert(str_contains($cancelSource, 'viewer_invitation_id IS NULL') && !str_contains($cancelSource, 'DELETE FROM viewer_accounts'), 'Mode cleanup must target only open-origin staging and never durable viewer accounts.');
    $openTransitionStart = strpos($modeSetSource, "if (\$normalized === 'open')");
    $restrictiveTransitionStart = strpos($modeSetSource, '// Serialize the restrictive policy write');
    viewer_phase40_assert($openTransitionStart !== false && $restrictiveTransitionStart !== false, 'Backend mode storage must contain explicit open and restrictive transition branches.');
    $openTransitionSource = substr($modeSetSource, $openTransitionStart, $restrictiveTransitionStart - $openTransitionStart);
    viewer_phase40_assert(strpos($openTransitionSource, 'viewer_registration_capacity_lock();') < strpos($openTransitionSource, 'viewer_registration_cancel_open_origin_staging();'), 'Enabling open must hold the serialized registration lock before stale-authority cleanup.');
    viewer_phase40_assert(strpos($openTransitionSource, 'viewer_registration_cancel_open_origin_staging();') < strpos($openTransitionSource, 'set_app_setting(VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY, $normalized);'), 'Open must not be persisted until stale-authority cleanup succeeds.');
    $restrictiveTransitionSource = substr($modeSetSource, $restrictiveTransitionStart);
    viewer_phase40_assert(strpos($restrictiveTransitionSource, 'viewer_registration_capacity_lock();') < strpos($restrictiveTransitionSource, 'set_app_setting(VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY, $normalized);'), 'Leaving open must serialize the restrictive policy write against request creation and final activation.');
    viewer_phase40_assert(strpos($restrictiveTransitionSource, 'set_app_setting(VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY, $normalized);') < strrpos($restrictiveTransitionSource, 'viewer_registration_cancel_open_origin_staging();'), 'Leaving open must make the restrictive policy durable before defense-in-depth cleanup.');

    $inviteInspectSource = viewer_phase40_function_source($registrationService, 'viewer_invitation_inspect');
    viewer_phase40_assert(str_contains($inviteInspectSource, 'viewer_registration_requests_enabled()') && !str_contains($inviteInspectSource, "viewer_registration_mode() !== 'invite_only'"), 'Invitation-backed service validation must remain usable under both invite_only and internal open policy.');
    $inviteHttpSource = viewer_phase40_function_source($httpService, 'viewer_http_invite_registration_available');
    viewer_phase40_assert(str_contains($inviteHttpSource, 'viewer_http_registration_lifecycle_available()'), 'Phase 4.1 invitation HTTP wiring must reuse the shared lifecycle gate without weakening Phase 4.0 invitation authority.');
    viewer_phase40_assert(str_contains($dispatch, "'viewer_register'") && str_contains($routing, "['viewer', 'register']") && str_contains($controller, 'cms_viewer_register'), 'Phase 4.1 may expose generic registration while Phase 4.0 remains the authoritative lifecycle policy.');
    viewer_phase40_assert(!str_contains($controller, 'Turnstile') && !str_contains($controller, 'turnstile') && !str_contains($registrationService, 'captcha'), 'Phase 4.0 must not add Turnstile/CAPTCHA behavior.');

    viewer_phase40_assert(!str_contains($registrationService, "\$_SESSION['user_id']") && !str_contains($registrationService, 'current_user()'), 'Pending registration must never establish or inspect administrator identity.');
    viewer_phase40_assert(!str_contains($confirmSource, 'viewer_session_establish') && !str_contains($confirmSource, 'viewer_user_id'), 'Email verification alone must not establish Viewer identity.');
    viewer_phase40_assert(!str_contains($activateSource, "\$_SESSION['user_id']"), 'Final viewer activation must never write the administrator session identity key.');

    echo "Viewer open-registration Phase 4.0 policy tests passed.\n";
}
