<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_security_operations_phase44_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the Phase 4.4 aggregate Viewer security operations and Phase 4 closure boundary.
 *
 * Responsibilities:
 *   - Verify fixed capability, capacity, event, trend, and rate-limit aggregate output
 *   - Verify storage failures remain distinguishable from legitimate zero activity
 *   - Verify the Admin surface remains read-only, privacy-safe, and route-local
 *   - Verify public telemetry, third-party security services, and new persistence are not introduced
 *   - Protect Phase 4.0 through Phase 4.3 registration, token, anti-automation, and identity boundaries
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This focused fixture uses deterministic aggregate database responses and requires no live database.
 *
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

namespace Gallery\Services {
    const SCHEMA_INSPECTION_AVAILABLE = 'available';
    const SCHEMA_INSPECTION_MISSING = 'missing';
    const SCHEMA_INSPECTION_UNKNOWN = 'unknown';
    const VIEWER_ACCOUNT_CAPACITY_STATE_KEY = 'accounts';
    const VIEWER_REGISTRATION_STATE_KEY = 'pending_requests';

    /** Return deterministic Phase 4.4 Viewer configuration. */
    function viewer_accounts_config(): array
    {
        return [
            'anti_automation_enabled' => true,
            'anti_automation_min_form_age_seconds' => 2,
            'anti_automation_form_lifetime_seconds' => 600,
            'anti_automation_pow_min_bits' => 12,
            'anti_automation_pow_max_bits' => 15,
        ];
    }

    /** Return deterministic master-feature state for focused status tests. */
    function viewer_accounts_master_feature_enabled(): bool
    {
        return !empty($GLOBALS['viewer_phase44_master_enabled']);
    }

    /** Return deterministic registration mode for focused status tests. */
    function viewer_registration_mode(): string
    {
        return (string) ($GLOBALS['viewer_phase44_registration_mode'] ?? 'disabled');
    }

    /** Return deterministic open-registration HTTP availability. */
    function viewer_http_open_registration_available(): bool
    {
        return viewer_accounts_master_feature_enabled() && viewer_registration_mode() === 'open';
    }

    /** Return deterministic resend HTTP availability. */
    function viewer_http_verification_resend_available(): bool
    {
        return viewer_accounts_master_feature_enabled()
            && in_array(viewer_registration_mode(), ['invite_only', 'open'], true);
    }

    /** Return deterministic local anti-automation state. */
    function viewer_anti_automation_enabled(): bool
    {
        return true;
    }

    /** Return a deterministic schema capability result. */
    function viewer_phase44_schema(string $key): array
    {
        return ['state' => (string) ($GLOBALS['viewer_phase44_schema'][$key] ?? 'available')];
    }

    /** Return deterministic Viewer authentication capability. */
    function viewer_auth_schema_status(): array
    {
        return viewer_phase44_schema('auth');
    }

    /** Return deterministic Viewer registration capability. */
    function viewer_registration_schema_status(): array
    {
        return viewer_phase44_schema('registration');
    }

    /** Return deterministic Viewer security-event capability. */
    function viewer_security_event_schema_status(): array
    {
        return viewer_phase44_schema('events');
    }

    /** Return deterministic Viewer rate-limit capability. */
    function viewer_rate_limit_schema_status(): array
    {
        return viewer_phase44_schema('rate_limits');
    }

    /** Return deterministic Viewer Admin account capability. */
    function viewer_admin_account_schema_status(): array
    {
        return viewer_phase44_schema('accounts');
    }

    /** Return the deterministic Viewer account hard cap. */
    function viewer_account_cap(): int
    {
        return 250;
    }

    /** Return the deterministic staged-registration hard cap. */
    function viewer_registration_request_cap(): int
    {
        return 250;
    }

    /** Return representative authoritative Viewer limiter policies. */
    function viewer_rate_limit_policies(): array
    {
        return [
            'viewer_register_ip' => ['max_attempts' => 5, 'window_seconds' => 3600, 'lock_seconds' => 3600],
            'viewer_register_subnet' => ['max_attempts' => 20, 'window_seconds' => 3600, 'lock_seconds' => 3600],
            'viewer_register_identifier' => ['max_attempts' => 3, 'window_seconds' => 3600, 'lock_seconds' => 3600],
            'viewer_register_global_day' => ['max_attempts' => 50, 'window_seconds' => 86400, 'lock_seconds' => 3600],
            'viewer_resend_verification_identifier' => ['max_attempts' => 3, 'window_seconds' => 3600, 'lock_seconds' => 3600],
            'viewer_automation_ip' => ['max_attempts' => 8, 'window_seconds' => 600, 'lock_seconds' => 900],
            'viewer_automation_subnet' => ['max_attempts' => 48, 'window_seconds' => 600, 'lock_seconds' => 900],
            'viewer_verify_mail_email_cooldown' => ['max_attempts' => 1, 'window_seconds' => 600, 'lock_seconds' => 600],
            'viewer_verify_mail_email_hour' => ['max_attempts' => 3, 'window_seconds' => 3600, 'lock_seconds' => 3600],
            'viewer_verify_mail_email_day' => ['max_attempts' => 5, 'window_seconds' => 86400, 'lock_seconds' => 3600],
            'viewer_verify_mail_ip_hour' => ['max_attempts' => 10, 'window_seconds' => 3600, 'lock_seconds' => 3600],
            'viewer_verify_mail_ip_day' => ['max_attempts' => 25, 'window_seconds' => 86400, 'lock_seconds' => 3600],
            'viewer_verify_mail_subnet_hour' => ['max_attempts' => 25, 'window_seconds' => 3600, 'lock_seconds' => 3600],
            'viewer_verify_mail_subnet_day' => ['max_attempts' => 60, 'window_seconds' => 86400, 'lock_seconds' => 3600],
            'viewer_verify_mail_global_day' => ['max_attempts' => 50, 'window_seconds' => 86400, 'lock_seconds' => 3600],
            'viewer_login_ip' => ['max_attempts' => 30, 'window_seconds' => 900, 'lock_seconds' => 900],
        ];
    }

    /** Return a minimal localized fallback for focused Admin rendering. */
    function t(string $key, string $fallback = '', array $replace = []): string
    {
        $text = $fallback !== '' ? $fallback : $key;
        foreach ($replace as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }
}

namespace Gallery\Core {
    /** Return the deterministic aggregate database fixture. */
    function db(): object
    {
        return $GLOBALS['viewer_phase44_db'];
    }

    /** Escape focused Admin HTML using the application output contract. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

namespace {
    final class ViewerPhase44Statement
    {
        private array $rows;
        private array $parameters = [];
        private string $sql;

        /** Construct one deterministic prepared statement fixture. */
        public function __construct(string $sql, array $rows)
        {
            $this->sql = $sql;
            $this->rows = $rows;
        }

        /** Capture prepared-statement parameters without mutating persistent state. */
        public function execute(array $parameters = []): bool
        {
            $this->parameters = $parameters;
            $GLOBALS['viewer_phase44_prepared_calls'][] = [$this->sql, $parameters];
            return true;
        }

        /** Return the first aggregate result row. */
        public function fetch(): array|false
        {
            return $this->rows[0] ?? false;
        }

        /** Return the bounded aggregate result set. */
        public function fetchAll(): array
        {
            return $this->rows;
        }
    }

    final class ViewerPhase44Db
    {
        public bool $failEventQuery = false;
        public array $queries = [];
        public array $persistentState = [
            'viewer_rate_limits' => [
                ['bucket' => 'viewer_register_global_day', 'subject_hash' => 'global-subject-sentinel', 'attempts' => 23],
            ],
            'viewer_rate_limit_buckets' => [
                ['bucket' => 'viewer_register_global_day', 'entry_count' => 1],
            ],
            'viewer_registration_requests' => [
                ['id' => 71, 'verification_token_hash' => 'primary-token-a-sentinel', 'status' => 'pending_email'],
            ],
            'viewer_registration_verification_tokens' => [
                ['id' => 91, 'token_hash' => 'sibling-token-b-sentinel', 'sent_at' => '2026-08-20 10:00:00'],
            ],
            'viewer_security_events' => [
                ['id' => 101, 'event_key' => 'viewer.registration_requested'],
            ],
        ];

        /** Prepare one recognized read-only Phase 4.4 aggregate query. */
        public function prepare(string $sql): ViewerPhase44Statement
        {
            $this->queries[] = $sql;
            if (preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) === 1) {
                throw new RuntimeException('Phase 4.4 attempted a mutation query.');
            }
            if (str_contains($sql, 'FROM viewer_accounts')) {
                return new ViewerPhase44Statement($sql, [[
                    'current_count' => 17,
                    'capacity_counter_count' => 17,
                ]]);
            }
            if (str_contains($sql, 'FROM viewer_registration_requests')) {
                return new ViewerPhase44Statement($sql, [[
                    'current_count' => 9,
                    'open_origin_count' => 6,
                    'invitation_backed_count' => 3,
                    'capacity_counter_count' => 9,
                ]]);
            }
            if (str_contains($sql, 'SUM(CASE WHEN created_at')) {
                if ($this->failEventQuery) {
                    throw new RuntimeException('event aggregate unavailable');
                }
                return new ViewerPhase44Statement($sql, [
                    ['event_key' => 'viewer.registration_requested', 'count_24h' => 4, 'count_7d' => 12],
                    ['event_key' => 'viewer.verification_sent', 'count_24h' => 3, 'count_7d' => 10],
                    ['event_key' => 'viewer.verification_resend_requested', 'count_24h' => 2, 'count_7d' => 8],
                    ['event_key' => 'viewer.verification_resent', 'count_24h' => 1, 'count_7d' => 5],
                    ['event_key' => 'viewer.verification_resend_suppressed', 'count_24h' => 1, 'count_7d' => 3],
                    ['event_key' => 'viewer.automation_challenge_required', 'count_24h' => 7, 'count_7d' => 20],
                    ['event_key' => 'viewer.automation_challenge_passed', 'count_24h' => 6, 'count_7d' => 17],
                    ['event_key' => 'viewer.automation_challenge_failed', 'count_24h' => 1, 'count_7d' => 4],
                    ['event_key' => 'viewer.automation_request_suppressed', 'count_24h' => 2, 'count_7d' => 9],
                ]);
            }
            if (str_contains($sql, 'DATE(created_at) AS activity_date')) {
                return new ViewerPhase44Statement($sql, [
                    ['activity_date' => '2026-08-18', 'event_key' => 'viewer.registration_requested', 'event_count' => 2],
                    ['activity_date' => '2026-08-18', 'event_key' => 'viewer.verification_sent', 'event_count' => 2],
                    ['activity_date' => '2026-08-19', 'event_key' => 'viewer.verification_resent', 'event_count' => 1],
                    ['activity_date' => '2026-08-20', 'event_key' => 'viewer.automation_challenge_required', 'event_count' => 3],
                    ['activity_date' => '2026-08-20', 'event_key' => 'viewer.automation_request_suppressed', 'event_count' => 2],
                ]);
            }
            throw new RuntimeException('Unexpected prepared query: ' . $sql);
        }

        /** Execute the one fixed aggregate rate-limit query. */
        public function query(string $sql): ViewerPhase44Statement
        {
            $this->queries[] = $sql;
            if (preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) === 1) {
                throw new RuntimeException('Phase 4.4 attempted a mutation query.');
            }
            if (!str_contains($sql, 'FROM viewer_rate_limit_buckets b')) {
                throw new RuntimeException('Unexpected direct query: ' . $sql);
            }
            return new ViewerPhase44Statement($sql, [
                ['bucket' => 'viewer_register_ip', 'entry_count' => 4, 'active_subjects' => 3, 'locked_subjects' => 1, 'current_window_attempts' => 4],
                ['bucket' => 'viewer_register_global_day', 'entry_count' => 1, 'active_subjects' => 1, 'locked_subjects' => 0, 'current_window_attempts' => 23],
                ['bucket' => 'viewer_automation_ip', 'entry_count' => 3, 'active_subjects' => 2, 'locked_subjects' => 1, 'current_window_attempts' => 8],
                ['bucket' => 'viewer_verify_mail_global_day', 'entry_count' => 1, 'active_subjects' => 1, 'locked_subjects' => 1, 'current_window_attempts' => 41],
            ]);
        }
    }

    $root = dirname(__DIR__);
    $GLOBALS['viewer_phase44_master_enabled'] = true;
    $GLOBALS['viewer_phase44_registration_mode'] = 'open';
    $GLOBALS['viewer_phase44_schema'] = [
        'auth' => 'available',
        'registration' => 'available',
        'events' => 'available',
        'rate_limits' => 'available',
        'accounts' => 'available',
    ];
    $GLOBALS['viewer_phase44_prepared_calls'] = [];
    $GLOBALS['viewer_phase44_db'] = new ViewerPhase44Db();
    $_SESSION = [
        'viewer_anti_automation' => [
            'nonce-sentinel' => ['kind' => 'challenge', 'action' => 'register'],
        ],
    ];

    require_once $root . '/app/services/viewer_security_operations.php';
    require_once $root . '/app/controllers/viewer_accounts.php';

    /** Fail the focused Phase 4.4 test with one explicit message. */
    function viewer_phase44_fail(string $message): void
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    /** Assert one Phase 4.4 invariant. */
    function viewer_phase44_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            viewer_phase44_fail($message);
        }
    }

    $now = strtotime('2026-08-20 12:00:00');
    viewer_phase44_assert(is_int($now), 'Focused timestamp must parse.');
    $persistentStateBefore = $GLOBALS['viewer_phase44_db']->persistentState;
    $antiAutomationSessionBefore = $_SESSION['viewer_anti_automation'];

    $snapshot = \Gallery\Services\viewer_security_operations_snapshot($now);
    viewer_phase44_assert($snapshot['status']['master_feature_enabled'] === true, 'Master Viewer feature state must be visible in the operations snapshot.');
    viewer_phase44_assert($snapshot['status']['registration_mode'] === 'open', 'Effective open registration mode must be reported exactly.');
    viewer_phase44_assert($snapshot['status']['open_registration_http_available'] === true, 'Open-registration HTTP availability must use the existing HTTP capability.');
    viewer_phase44_assert($snapshot['status']['verification_resend_http_available'] === true, 'Verification resend HTTP availability must use the existing HTTP capability.');
    viewer_phase44_assert($snapshot['status']['anti_automation']['enabled'] === true, 'First-party anti-automation state must be read-only and visible.');
    viewer_phase44_assert($snapshot['status']['anti_automation']['pow_min_bits'] === 12 && $snapshot['status']['anti_automation']['pow_max_bits'] === 15, 'Bounded proof-of-work range must be normalized without exposing secrets.');
    viewer_phase44_assert($snapshot['capacity']['accounts']['current_count'] === 17 && $snapshot['capacity']['accounts']['hard_cap'] === 250, 'Viewer account count and hard cap must use authoritative storage/configuration.');
    viewer_phase44_assert($snapshot['capacity']['registrations']['current_count'] === 9 && $snapshot['capacity']['registrations']['hard_cap'] === 250, 'Staged registration count and hard cap must use authoritative storage/configuration.');
    viewer_phase44_assert($snapshot['capacity']['registrations']['open_origin_count'] === 6 && $snapshot['capacity']['registrations']['invitation_backed_count'] === 3, 'Aggregate staging origin counts must remain identifier-free.');

    viewer_phase44_assert($snapshot['events']['last_24_hours']['accepted_registrations'] === 4, '24-hour accepted registration aggregate must map the persisted event precisely.');
    viewer_phase44_assert($snapshot['events']['last_7_days']['verification_messages_sent'] === 10, 'Seven-day verification handoff aggregate must map the persisted event precisely.');
    viewer_phase44_assert($snapshot['events']['last_24_hours']['automation_requests_suppressed'] === 2, '24-hour anti-automation suppression count must be visible.');
    viewer_phase44_assert(
        array_values(\Gallery\Services\viewer_security_operations_event_keys()) === [
            'viewer.registration_requested',
            'viewer.verification_sent',
            'viewer.verification_resend_requested',
            'viewer.verification_resent',
            'viewer.verification_resend_suppressed',
            'viewer.automation_challenge_required',
            'viewer.automation_challenge_passed',
            'viewer.automation_challenge_failed',
            'viewer.automation_request_suppressed',
        ],
        'Phase 4.4 must aggregate only the fixed existing Phase 4 event-key allowlist.'
    );
    $trendByDate = [];
    foreach ($snapshot['events']['trend'] as $day) {
        $trendByDate[$day['date']] = $day;
    }
    viewer_phase44_assert(count($snapshot['events']['trend']) === 7, 'The daily trend must contain exactly seven calendar days.');
    viewer_phase44_assert(($trendByDate['2026-08-20']['anti_automation_interventions'] ?? -1) === 5, 'Anti-automation interventions must mean challenge-required plus request-suppressed only.');
    viewer_phase44_assert(($trendByDate['2026-08-18']['accepted_registrations'] ?? -1) === 2, 'Daily accepted registration events must remain in their correct calendar day.');

    $eventAggregateCall = null;
    foreach ($GLOBALS['viewer_phase44_prepared_calls'] as $call) {
        if (str_contains($call[0], 'SUM(CASE WHEN created_at')) {
            $eventAggregateCall = $call;
            break;
        }
    }
    viewer_phase44_assert(is_array($eventAggregateCall), 'Event metrics must use one bounded aggregate SQL query.');
    viewer_phase44_assert(in_array('2026-08-19 12:00:00', $eventAggregateCall[1], true), 'The 24-hour event cutoff must be bound explicitly.');
    viewer_phase44_assert(in_array('2026-08-13 12:00:00', $eventAggregateCall[1], true), 'The rolling seven-day event cutoff must be bound explicitly.');
    viewer_phase44_assert(!str_contains($eventAggregateCall[0], 'SELECT *'), 'Security-event operations must not hydrate complete event rows.');
    viewer_phase44_assert(!in_array('viewer.login_success', $eventAggregateCall[1], true), 'Unrelated/unknown event keys must not pollute Phase 4 aggregates.');

    $rate = $snapshot['rate_limits'];
    viewer_phase44_assert($rate['buckets']['viewer_register_ip']['active_subjects'] === 3, 'Active registration subjects must be aggregate-only.');
    viewer_phase44_assert($rate['buckets']['viewer_register_ip']['locked_subjects'] === 1, 'Currently locked registration subjects must be aggregate-only.');
    viewer_phase44_assert($rate['global_budgets']['viewer_register_global_day']['current_attempts'] === 23, 'Current global registration budget usage must be derived from the active window.');
    viewer_phase44_assert($rate['global_budgets']['viewer_verify_mail_global_day']['current_attempts'] === 41, 'Current global verification-mail budget usage must be derived from the active window.');
    $rateSql = \Gallery\Services\viewer_security_operations_rate_limit_query(\Gallery\Services\viewer_security_operations_rate_limit_policies(), $now);
    viewer_phase44_assert(str_contains($rateSql, "WHEN 'viewer_automation_ip' THEN '2026-08-20 11:50:00'"), 'Automation active-subject cutoff must follow its 600-second policy window.');
    viewer_phase44_assert(str_contains($rateSql, "WHEN 'viewer_register_ip' THEN '2026-08-20 11:00:00'"), 'Registration active-subject cutoff must follow its one-hour policy window.');
    viewer_phase44_assert(str_contains($rateSql, "WHEN 'viewer_register_global_day' THEN '2026-08-19 12:00:00'"), 'Global registration usage must use the current 24-hour policy window.');
    viewer_phase44_assert(str_contains($rateSql, "r.locked_until > '2026-08-20 12:00:00'"), 'Currently locked subjects must require locked_until later than now.');
    viewer_phase44_assert(str_contains($rateSql, "r.last_attempt_at >= '2026-08-19 12:00:00'"), 'Stale limiter rows older than the longest selected window must be excluded from the aggregate join unless locked.');
    viewer_phase44_assert(str_contains($rateSql, 'r.first_attempt_at >= CASE b.bucket'), 'Global current usage must depend on first_attempt_at remaining inside the policy window.');

    $GLOBALS['viewer_phase44_registration_mode'] = 'invite_only';
    $inviteStatus = \Gallery\Services\viewer_security_operations_status_snapshot();
    viewer_phase44_assert($inviteStatus['registration_mode'] === 'invite_only' && !$inviteStatus['open_registration_http_available'] && $inviteStatus['verification_resend_http_available'], 'Invite-only status must preserve existing route semantics.');
    $GLOBALS['viewer_phase44_registration_mode'] = 'disabled';
    $disabledStatus = \Gallery\Services\viewer_security_operations_status_snapshot();
    viewer_phase44_assert($disabledStatus['registration_mode'] === 'disabled' && !$disabledStatus['open_registration_http_available'] && !$disabledStatus['verification_resend_http_available'], 'Disabled status must preserve existing route semantics.');
    $GLOBALS['viewer_phase44_master_enabled'] = false;
    $masterOffStatus = \Gallery\Services\viewer_security_operations_status_snapshot();
    viewer_phase44_assert($masterOffStatus['master_feature_enabled'] === false, 'Viewer Accounts master OFF must remain explicitly observable as the outer state.');
    $GLOBALS['viewer_phase44_master_enabled'] = true;
    $GLOBALS['viewer_phase44_registration_mode'] = 'open';

    $GLOBALS['viewer_phase44_schema']['events'] = 'missing';
    $missingEvents = \Gallery\Services\viewer_security_operations_event_snapshot($now);
    viewer_phase44_assert($missingEvents['status'] === 'unavailable', 'Confirmed missing event storage must be unavailable, not zero.');
    viewer_phase44_assert($missingEvents['last_24_hours']['accepted_registrations'] === null, 'Unavailable event metrics must not be represented as zero.');
    $GLOBALS['viewer_phase44_schema']['events'] = 'unknown';
    $unknownEvents = \Gallery\Services\viewer_security_operations_event_snapshot($now);
    viewer_phase44_assert($unknownEvents['status'] === 'unknown', 'Inspection failure must remain unknown.');
    $GLOBALS['viewer_phase44_schema']['events'] = 'available';
    $GLOBALS['viewer_phase44_schema']['rate_limits'] = 'missing';
    $missingRate = \Gallery\Services\viewer_security_operations_rate_limit_snapshot($now);
    viewer_phase44_assert($missingRate['status'] === 'unavailable' && $missingRate['buckets']['viewer_register_ip']['active_subjects'] === null, 'Missing limiter storage must be unavailable rather than zero pressure.');
    $GLOBALS['viewer_phase44_schema']['rate_limits'] = 'available';
    $GLOBALS['viewer_phase44_schema']['registration'] = 'missing';
    $missingRegistration = \Gallery\Services\viewer_security_operations_capacity_snapshot();
    viewer_phase44_assert($missingRegistration['registrations']['status'] === 'unavailable' && $missingRegistration['registrations']['current_count'] === null, 'Missing registration storage must be unavailable rather than zero pending registrations.');
    $GLOBALS['viewer_phase44_schema']['registration'] = 'available';
    $GLOBALS['viewer_phase44_db']->failEventQuery = true;
    $runtimeUnknown = \Gallery\Services\viewer_security_operations_event_snapshot($now);
    viewer_phase44_assert($runtimeUnknown['status'] === 'unknown' && $runtimeUnknown['last_7_days']['accepted_registrations'] === null, 'Runtime aggregate failure must remain unknown rather than fake zero.');
    $GLOBALS['viewer_phase44_db']->failEventQuery = false;

    ob_start();
    \Gallery\Controllers\viewer_render_admin_security_operations($snapshot);
    $operationsHtml = (string) ob_get_clean();
    viewer_phase44_assert(str_contains($operationsHtml, 'Viewer security status') && str_contains($operationsHtml, 'Rate-limit pressure'), 'Admin Viewer page must render the Phase 4.4 operations summary.');
    foreach (['ip_hash', 'user_agent_hash', 'subject_hash', 'request_id', 'context_json', 'verification_token', 'installation secret', 'person@example.com'] as $forbidden) {
        viewer_phase44_assert(stripos($operationsHtml, $forbidden) === false, 'Rendered operations HTML must not expose ' . $forbidden . '.');
    }
    viewer_phase44_assert($GLOBALS['viewer_phase44_db']->persistentState === $persistentStateBefore, 'Rendering/reading operations must leave limiter, staging, token A, sibling token B, and security-event rows unchanged.');
    viewer_phase44_assert($_SESSION['viewer_anti_automation'] === $antiAutomationSessionBefore, 'Admin operations viewing must not create, consume, or modify Phase 4.3 anti-automation session authority.');

    $operationsService = (string) file_get_contents($root . '/app/services/viewer_security_operations.php');
    $viewerController = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
    $servicesBootstrap = (string) file_get_contents($root . '/app/services.php');
    $dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
    $routing = (string) file_get_contents($root . '/app/bootstrap/routing.php');
    $featureFlags = (string) file_get_contents($root . '/app/services/feature_flags.php');

    viewer_phase44_assert(str_contains($viewerController, 'function cms_admin_viewer_invitations(): void') && str_contains($viewerController, 'require_admin();'), 'Operations must remain behind the existing Admin authentication boundary.');
    viewer_phase44_assert(substr_count($dispatch, "'admin_viewer_invitations' =>") === 1, 'Phase 4.4 must reuse the existing Admin Viewer route.');
    viewer_phase44_assert(!str_contains($dispatch, 'viewer_security_operations') && !str_contains($routing, 'viewer_security_operations'), 'Phase 4.4 must add no public or Viewer metrics route.');
    viewer_phase44_assert(str_contains($featureFlags, "'admin_viewer_invitations' => 'viewer_accounts'"), 'Existing Viewer master feature ownership must remain authoritative for the Admin route.');
    viewer_phase44_assert(str_contains($servicesBootstrap, 'viewer_security_operations.php'), 'The focused operations service must load through the shared service bootstrap.');

    foreach (['viewer_security_events', 'viewer_rate_limit_buckets', 'viewer_rate_limits', 'viewer_registration_requests', 'viewer_registration_state', 'viewer_account_state', 'viewer_accounts'] as $existingStore) {
        viewer_phase44_assert(str_contains($operationsService, $existingStore), 'Phase 4.4 must reuse existing storage: ' . $existingStore . '.');
    }
    viewer_phase44_assert(!preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE)\s+/i', $operationsService), 'The Phase 4.4 operations service must contain no database mutation query.');
    viewer_phase44_assert(substr_count($operationsService, 'viewer_rate_limit_consume(') === 1, 'Displaying limiter pressure must never call viewer_rate_limit_consume(); the only occurrence may be the explicit read-only warning in the file docblock.');
    viewer_phase44_assert(!str_contains($operationsService, 'viewer_anti_automation_form_issue(') && !str_contains($operationsService, 'viewer_anti_automation_challenge_issue('), 'Admin operations viewing must not issue anti-automation authority.');
    foreach (['viewer_registration_verification_confirm(', 'viewer_registration_verification_resend_prepare(', 'viewer_registration_verification_resend_discard(', 'viewer_invitation_issue(', 'viewer_invitation_revoke(', 'viewer_invitation_delete('] as $authorityMutation) {
        viewer_phase44_assert(!str_contains($operationsService, $authorityMutation), 'Admin operations viewing must not invoke registration verification/invitation mutation authority: ' . $authorityMutation);
    }
    viewer_phase44_assert(!str_contains($operationsService, 'telemetry_') && !str_contains($operationsService, 'telemetry.php'), 'Viewer security operations must remain independent of public telemetry.');
    viewer_phase44_assert(!str_contains($operationsService, 'viewer_security_event_record'), 'Viewing operations must not add an Admin page-view or request-attempt security event.');
    viewer_phase44_assert(!str_contains($operationsService, 'SELECT *'), 'Operations queries must remain aggregate/bounded rather than hydrating full rows.');

    $migrationFiles = glob($root . '/database/migrations/*.php') ?: [];
    foreach ($migrationFiles as $migrationFile) {
        viewer_phase44_assert(!str_contains(basename($migrationFile), 'phase44') && !str_contains(basename($migrationFile), 'security_operations'), 'Phase 4.4 must not add a persistence migration.');
    }
    foreach (['viewer_metrics', 'viewer_security_metrics', 'viewer_daily_metrics', 'viewer_registration_statistics', 'viewer_analytics'] as $forbiddenTable) {
        viewer_phase44_assert(stripos($operationsService, $forbiddenTable) === false, 'Phase 4.4 must not introduce a new metrics persistence table.');
    }

    $combinedChangedRuntime = $operationsService . "\n" . $viewerController . "\n" . $servicesBootstrap;
    foreach (['Turnstile', 'reCAPTCHA', 'hCaptcha', 'Friendly Captcha', 'Prometheus', 'Sentry', 'Redis', 'Memcached'] as $dependency) {
        viewer_phase44_assert(stripos($combinedChangedRuntime, $dependency) === false, 'Phase 4.4 runtime must not integrate ' . $dependency . '.');
    }
    viewer_phase44_assert(stripos($combinedChangedRuntime, 'composer require') === false && stripos($combinedChangedRuntime, 'npm install') === false, 'Phase 4.4 must add no Composer/npm security dependency.');

    $phase42Test = (string) file_get_contents($root . '/tests/viewer_verification_resend_phase42_test.php');
    $phase43Test = (string) file_get_contents($root . '/tests/viewer_anti_automation_phase43_test.php');
    viewer_phase44_assert(str_contains($phase42Test, 'token A') || str_contains($phase42Test, 'Token A') || str_contains($phase42Test, 'primary token'), 'Historical Phase 4.2 token-preservation coverage must remain present.');
    viewer_phase44_assert(str_contains($phase43Test, 'viewer_anti_automation_authorize_submission'), 'Historical Phase 4.3 anti-automation authorization coverage must remain present.');

    echo "Viewer security operations Phase 4.4 tests passed.\n";
}
