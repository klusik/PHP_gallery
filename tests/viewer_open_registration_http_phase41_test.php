<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_open_registration_http_phase41_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the Phase 4.1 public verified-email open-registration HTTP boundary.
 *
 * Responsibilities:
 *   - Verify open registration is exposed only through the master/policy/transport/storage gate
 *   - Verify the generic route stages open-origin requests without an invitation capability
 *   - Verify public orchestration remains CSRF-protected, generic, scanner-safe, and identity-separated
 *   - Verify invitation-backed registration/verification remains available in invite_only and open
 *   - Verify duplicate submission preserves an already mailed usable verification authority
 *   - Verify the Admin selector exposes only disabled, invite_only, and open through the existing mode service
 *   - Verify open-only public discoverability and the absence of CAPTCHA/Phase 5 surfaces
 *
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

namespace Gallery\Tests\Phase41 {
    /** Minimal deterministic PDO-like fixture for staged-registration request tests. */
    final class RegistrationHttpPdo
    {
        /** @var ?array<string,mixed> Mutable registration row. */
        public ?array $row = null;

        /** Whether one synthetic transaction is active. */
        public bool $transaction = false;

        /** Synthetic active-request count. */
        public int $activeRequestCount = 0;

        /** Last synthetic insert id. */
        public int $lastInsertIdValue = 41;

        /** Begin one fixture transaction. */
        public function beginTransaction(): bool
        {
            $this->transaction = true;
            return true;
        }

        /** Return whether one fixture transaction is active. */
        public function inTransaction(): bool
        {
            return $this->transaction;
        }

        /** Commit one fixture transaction. */
        public function commit(): bool
        {
            $this->transaction = false;
            return true;
        }

        /** Roll back one fixture transaction. */
        public function rollBack(): bool
        {
            $this->transaction = false;
            return true;
        }

        /** Prepare one SQL statement used by the focused request-begin fixture. */
        public function prepare(string $sql): RegistrationHttpStatement
        {
            return new RegistrationHttpStatement($this, $sql);
        }

        /** Return the synthetic last insert id. */
        public function lastInsertId(): string
        {
            return (string) $this->lastInsertIdValue;
        }
    }

    /** Minimal statement fixture for the Phase 4.1 registration-request path. */
    final class RegistrationHttpStatement
    {
        /** @var mixed Scalar result from the latest execution. */
        private mixed $scalar = false;

        /** @var ?array<string,mixed> Row result from the latest execution. */
        private ?array $row = null;

        /** Number of affected fixture rows. */
        private int $rowCount = 0;

        /** Store parent fixture and SQL. */
        public function __construct(private RegistrationHttpPdo $pdo, private string $sql)
        {
        }

        /**
         * Execute one focused SQL contract.
         *
         * @param array<int,mixed> $parameters Bound values.
         */
        public function execute(array $parameters = []): bool
        {
            $this->scalar = false;
            $this->row = null;
            $this->rowCount = 0;

            if (str_contains($this->sql, 'INSERT INTO viewer_registration_state')) {
                return true;
            }
            if (str_contains($this->sql, 'SELECT active_request_count FROM viewer_registration_state')) {
                $this->scalar = $this->pdo->activeRequestCount;
                return true;
            }
            if (str_contains($this->sql, 'SELECT id FROM viewer_accounts WHERE normalized_email')) {
                $this->scalar = false;
                return true;
            }
            if (str_contains($this->sql, 'SELECT * FROM viewer_registration_requests WHERE normalized_email')) {
                $this->row = $this->pdo->row;
                return true;
            }
            if (str_starts_with($this->sql, 'UPDATE viewer_registration_requests SET ')) {
                if ($this->pdo->row === null) {
                    throw new \RuntimeException('Synthetic update requires an existing registration row.');
                }
                $this->pdo->row = array_merge($this->pdo->row, [
                    'email' => (string) ($parameters[0] ?? ''),
                    'normalized_email' => (string) ($parameters[1] ?? ''),
                    'email_fingerprint' => (string) ($parameters[2] ?? ''),
                    'viewer_invitation_id' => $parameters[3] ?? null,
                    'status' => (string) ($parameters[4] ?? ''),
                    'request_ip_hash' => $parameters[5] ?? null,
                    'verification_token_hash' => (string) ($parameters[6] ?? ''),
                    'verification_token_expires_at' => (string) ($parameters[7] ?? ''),
                    'verification_token_consumed_at' => null,
                    'expires_at' => (string) ($parameters[8] ?? ''),
                    'verified_at' => null,
                    'cancelled_at' => null,
                    'updated_at' => (string) ($parameters[9] ?? ''),
                ]);
                $this->rowCount = 1;
                return true;
            }
            if (str_starts_with($this->sql, 'INSERT INTO viewer_registration_requests ')) {
                $this->pdo->row = [
                    'id' => $this->pdo->lastInsertIdValue,
                    'email' => (string) ($parameters[0] ?? ''),
                    'normalized_email' => (string) ($parameters[1] ?? ''),
                    'email_fingerprint' => (string) ($parameters[2] ?? ''),
                    'viewer_invitation_id' => $parameters[3] ?? null,
                    'status' => (string) ($parameters[4] ?? ''),
                    'request_ip_hash' => $parameters[5] ?? null,
                    'verification_token_hash' => (string) ($parameters[6] ?? ''),
                    'verification_token_expires_at' => (string) ($parameters[7] ?? ''),
                    'verification_token_consumed_at' => null,
                    'verification_send_count' => 0,
                    'expires_at' => (string) ($parameters[8] ?? ''),
                    'created_at' => (string) ($parameters[9] ?? ''),
                    'updated_at' => (string) ($parameters[10] ?? ''),
                    'verified_at' => null,
                    'cancelled_at' => null,
                ];
                $this->pdo->activeRequestCount++;
                $this->rowCount = 1;
                return true;
            }
            if (str_contains($this->sql, 'UPDATE viewer_registration_state SET active_request_count = active_request_count + 1')) {
                return true;
            }

            throw new \RuntimeException('Unexpected Phase 4.1 fixture SQL: ' . $this->sql);
        }

        /** Return one row fixture. */
        public function fetch(): mixed
        {
            return $this->row === null ? false : $this->row;
        }

        /** Return one scalar fixture. */
        public function fetchColumn(): mixed
        {
            return $this->scalar;
        }

        /** Return the affected-row count. */
        public function rowCount(): int
        {
            return $this->rowCount;
        }
    }
}

namespace Gallery\Core {
    /** Return the mutable Phase 4.1 PDO fixture. */
    function db(): object
    {
        return $GLOBALS['viewer_phase41_pdo'];
    }

    /** Return a valid SQL timestamp for request refreshes. */
    function now_sql(): string
    {
        return date('Y-m-d H:i:s');
    }
}

namespace Gallery\Services {
    /** Return the mutable effective Viewer Accounts state. */
    function viewer_accounts_enabled(): bool
    {
        return !empty($GLOBALS['viewer_phase41_accounts_enabled']);
    }

    /** Return the mutable registration mode. */
    function viewer_registration_mode(): string
    {
        return (string) ($GLOBALS['viewer_phase41_mode'] ?? 'disabled');
    }

    /** Return the mutable secure-transport state. */
    function viewer_security_transport_allowed(): bool
    {
        return !empty($GLOBALS['viewer_phase41_transport_allowed']);
    }

    /** Return the mutable viewer authentication storage state. */
    function viewer_auth_storage_available(): bool
    {
        return !empty($GLOBALS['viewer_phase41_auth_storage']);
    }

    /** Return an aggregate available registration schema fixture. */
    function schema_inspection_feature(string $feature, array $requirements): array
    {
        return ['state' => !empty($GLOBALS['viewer_phase41_registration_storage']) ? 'available' : 'missing'];
    }

    /** Return one table requirement fixture. */
    function schema_inspection_table(string $table): array
    {
        return ['state' => 'available'];
    }

    /** Return one column requirement fixture. */
    function schema_inspection_column(string $table, string $column): array
    {
        return ['state' => 'available'];
    }

    /** Interpret the focused aggregate schema fixture. */
    function schema_inspection_is_available(array $status): bool
    {
        return ($status['state'] ?? '') === 'available';
    }

    /** Return minimal registration configuration needed by request_begin(). */
    function viewer_accounts_config(): array
    {
        return [
            'max_pending_registration_requests' => 250,
            'registration_request_lifetime_minutes' => 1440,
            'verification_token_lifetime_minutes' => 60,
            'verified_registration_lifetime_minutes' => 60,
            'registration_activation_lifetime_minutes' => 20,
            'invitation_lifetime_days' => 7,
        ];
    }

    /** Normalize one focused email address. */
    function viewer_email_normalize(string $email): ?string
    {
        $email = strtolower(trim($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /** Return one deterministic email fingerprint. */
    function viewer_email_fingerprint(string $email): string
    {
        return hash('sha256', strtolower(trim($email)));
    }

    /** Return one deterministic scoped fingerprint. */
    function viewer_security_fingerprint(string $scope, string $value): string
    {
        return hash('sha256', $scope . "\0" . $value);
    }

    /** Normalize one focused client IP. */
    function request_client_ip_normalize(string $ip): string
    {
        return filter_var(trim($ip), FILTER_VALIDATE_IP) !== false ? trim($ip) : '';
    }

    /** Return one deterministic client IP. */
    function request_client_ip(): string
    {
        return '192.0.2.41';
    }

    /** Always allow existing registration abuse buckets in this lifecycle fixture. */
    function viewer_rate_limit_consume(string $bucket, string $kind, string $subject): array
    {
        $GLOBALS['viewer_phase41_rate_buckets'][] = $bucket;
        return ['allowed' => true, 'reason' => 'ok', 'retry_after_seconds' => 0];
    }

    /** Return a deterministic unique plaintext authority for focused lifecycle tests. */
    function security_opaque_token_generate(int $entropyBytes = 32): string
    {
        $GLOBALS['viewer_phase41_generated_tokens'] = (int) ($GLOBALS['viewer_phase41_generated_tokens'] ?? 0) + 1;
        return 'phase41-token-' . $GLOBALS['viewer_phase41_generated_tokens'];
    }

    /** Hash one focused authority using the production contract shape. */
    function security_authority_token_hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

namespace {
    use Gallery\Tests\Phase41\RegistrationHttpPdo;
    use function Gallery\Services\viewer_http_invite_registration_available;
    use function Gallery\Services\viewer_http_open_registration_available;
    use function Gallery\Services\viewer_http_registration_verification_available;
    use function Gallery\Services\viewer_registration_request_allowed_by_current_mode;
    use function Gallery\Services\viewer_registration_request_begin;

    /** Throw when one Phase 4.1 expectation fails. */
    function viewer_phase41_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    /**
     * Extract one named function declaration/body for focused static assertions.
     *
     * @param string $source Complete PHP source.
     * @param string $functionName Function name.
     * @return string Function declaration/body source.
     */
    function viewer_phase41_function_source(string $source, string $functionName): string
    {
        $needle = 'function ' . $functionName . '(';
        $start = strpos($source, $needle);
        if ($start === false) {
            throw new RuntimeException('Function not found: ' . $functionName);
        }
        $brace = strpos($source, '{', $start);
        if ($brace === false) {
            throw new RuntimeException('Function body not found: ' . $functionName);
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
        throw new RuntimeException('Unterminated function body: ' . $functionName);
    }

    $root = dirname(__DIR__);
    require_once $root . '/app/services/viewer_registration.php';
    require_once $root . '/app/services/viewer_http.php';

    $GLOBALS['viewer_phase41_accounts_enabled'] = true;
    $GLOBALS['viewer_phase41_mode'] = 'open';
    $GLOBALS['viewer_phase41_transport_allowed'] = true;
    $GLOBALS['viewer_phase41_auth_storage'] = true;
    $GLOBALS['viewer_phase41_registration_storage'] = true;
    $GLOBALS['viewer_phase41_generated_tokens'] = 0;
    $GLOBALS['viewer_phase41_rate_buckets'] = [];

    // Route availability: master state, mode, transport, and both storage capabilities are mandatory.
    $GLOBALS['viewer_phase41_accounts_enabled'] = false;
    viewer_phase41_assert(!viewer_http_open_registration_available(), 'Master OFF must override an open registration mode.');
    $GLOBALS['viewer_phase41_accounts_enabled'] = true;
    $GLOBALS['viewer_phase41_mode'] = 'disabled';
    viewer_phase41_assert(!viewer_http_open_registration_available(), 'Disabled mode must not expose generic registration.');
    $GLOBALS['viewer_phase41_mode'] = 'invite_only';
    viewer_phase41_assert(!viewer_http_open_registration_available(), 'Invite-only mode must not expose generic registration.');
    viewer_phase41_assert(viewer_http_invite_registration_available(), 'Invite-only mode must retain invitation registration.');
    viewer_phase41_assert(viewer_http_registration_verification_available(), 'Invite-only mode must retain the shared verification route.');
    $GLOBALS['viewer_phase41_mode'] = 'open';
    viewer_phase41_assert(viewer_http_open_registration_available(), 'Open mode must expose generic registration when all capabilities are available.');
    viewer_phase41_assert(viewer_http_invite_registration_available(), 'Open mode must retain invitation registration.');
    viewer_phase41_assert(viewer_http_registration_verification_available(), 'Open mode must retain the shared verification route.');
    $GLOBALS['viewer_phase41_transport_allowed'] = false;
    viewer_phase41_assert(!viewer_http_open_registration_available(), 'Open registration must fail closed without allowed security transport.');
    $GLOBALS['viewer_phase41_transport_allowed'] = true;
    $GLOBALS['viewer_phase41_auth_storage'] = false;
    viewer_phase41_assert(!viewer_http_open_registration_available(), 'Open registration must fail closed without viewer authentication storage.');
    $GLOBALS['viewer_phase41_auth_storage'] = true;
    $GLOBALS['viewer_phase41_registration_storage'] = false;
    viewer_phase41_assert(!viewer_http_open_registration_available(), 'Open registration must fail closed without registration storage.');
    $GLOBALS['viewer_phase41_registration_storage'] = true;

    // Current-mode authority: open accepts both origins, invite_only accepts only invitation-backed staging.
    $openOrigin = ['viewer_invitation_id' => null];
    $invitationOrigin = ['viewer_invitation_id' => 17];
    $GLOBALS['viewer_phase41_mode'] = 'open';
    viewer_phase41_assert(viewer_registration_request_allowed_by_current_mode($openOrigin), 'Open mode must permit open-origin staged authority.');
    viewer_phase41_assert(viewer_registration_request_allowed_by_current_mode($invitationOrigin), 'Open mode must permit invitation-backed staged authority.');
    $GLOBALS['viewer_phase41_mode'] = 'invite_only';
    viewer_phase41_assert(!viewer_registration_request_allowed_by_current_mode($openOrigin), 'Open-origin staging must stop after open -> invite_only.');
    viewer_phase41_assert(viewer_registration_request_allowed_by_current_mode($invitationOrigin), 'Invitation-backed staging must remain permitted in invite_only.');
    $GLOBALS['viewer_phase41_mode'] = 'disabled';
    viewer_phase41_assert(!viewer_registration_request_allowed_by_current_mode($openOrigin) && !viewer_registration_request_allowed_by_current_mode($invitationOrigin), 'Disabled mode must block both staged origins.');

    // Generic open request calls the authoritative service with no invitation and persists NULL origin.
    $GLOBALS['viewer_phase41_mode'] = 'open';
    $GLOBALS['viewer_phase41_pdo'] = new RegistrationHttpPdo();
    $openResult = viewer_registration_request_begin('Open.Person@example.com', null, '192.0.2.41');
    viewer_phase41_assert(!empty($openResult['accepted']) && !empty($openResult['mail_eligible']), 'Fresh open registration must stage through the existing registration service.');
    viewer_phase41_assert(array_key_exists('viewer_invitation_id', $GLOBALS['viewer_phase41_pdo']->row ?? []) && $GLOBALS['viewer_phase41_pdo']->row['viewer_invitation_id'] === null, 'Generic open registration must persist viewer_invitation_id IS NULL.');
    viewer_phase41_assert(($GLOBALS['viewer_phase41_pdo']->row['normalized_email'] ?? '') === 'open.person@example.com', 'Open registration must retain canonical staged email identity.');
    foreach (['viewer_register_ip', 'viewer_register_subnet', 'viewer_register_identifier', 'viewer_register_global_day'] as $bucket) {
        viewer_phase41_assert(in_array($bucket, $GLOBALS['viewer_phase41_rate_buckets'], true), 'Open request must reuse existing registration limiter bucket: ' . $bucket);
    }

    // Duplicate submit must not destroy the last successfully mailed, still-usable token.
    $now = time();
    $tokenA = 'already-emailed-token-A';
    $tokenAHash = hash('sha256', $tokenA);
    $tokenAExpiry = date('Y-m-d H:i:s', $now + 1800);
    $requestExpiry = date('Y-m-d H:i:s', $now + 3600);
    $duplicatePdo = new RegistrationHttpPdo();
    $duplicatePdo->activeRequestCount = 1;
    $duplicatePdo->row = [
        'id' => 77,
        'email' => 'duplicate@example.com',
        'normalized_email' => 'duplicate@example.com',
        'email_fingerprint' => hash('sha256', 'duplicate@example.com'),
        'viewer_invitation_id' => null,
        'status' => \Gallery\Services\VIEWER_REGISTRATION_STATUS_PENDING,
        'verification_token_hash' => $tokenAHash,
        'verification_token_expires_at' => $tokenAExpiry,
        'verification_token_consumed_at' => null,
        'verification_send_count' => 1,
        'expires_at' => $requestExpiry,
        'cancelled_at' => null,
    ];
    $GLOBALS['viewer_phase41_pdo'] = $duplicatePdo;
    $generatedBeforeDuplicate = (int) $GLOBALS['viewer_phase41_generated_tokens'];
    $duplicateResult = viewer_registration_request_begin('duplicate@example.com', null, '192.0.2.41');
    viewer_phase41_assert(!empty($duplicateResult['accepted']) && empty($duplicateResult['mail_eligible']), 'Duplicate pending submission with a mailed valid token must remain generic and mail-ineligible.');
    viewer_phase41_assert(($duplicateResult['verification_token'] ?? null) === null, 'Duplicate preservation path must not expose a replacement verification token.');
    viewer_phase41_assert($duplicatePdo->row['verification_token_hash'] === $tokenAHash, 'Duplicate submission must preserve token A hash.');
    viewer_phase41_assert($duplicatePdo->row['verification_token_expires_at'] === $tokenAExpiry, 'Duplicate submission must preserve token A expiry.');
    viewer_phase41_assert((int) $GLOBALS['viewer_phase41_generated_tokens'] === $generatedBeforeDuplicate, 'Duplicate preservation path must not generate token B.');

    // No successfully sent mail means a retry may mint a new token and retry delivery.
    $retryPdo = new RegistrationHttpPdo();
    $retryPdo->activeRequestCount = 1;
    $retryPdo->row = $duplicatePdo->row;
    $retryPdo->row['verification_send_count'] = 0;
    $oldRetryHash = (string) $retryPdo->row['verification_token_hash'];
    $GLOBALS['viewer_phase41_pdo'] = $retryPdo;
    $retryResult = viewer_registration_request_begin('duplicate@example.com', null, '192.0.2.41');
    viewer_phase41_assert(!empty($retryResult['mail_eligible']) && !empty($retryResult['verification_token']), 'Pending request with verification_send_count = 0 may retry with fresh mail authority.');
    viewer_phase41_assert($retryPdo->row['verification_token_hash'] !== $oldRetryHash, 'Unsent retry may rotate the pending verification token.');

    // An already expired emailed authority may be replaced because the old link is unusable.
    $expiredPdo = new RegistrationHttpPdo();
    $expiredPdo->activeRequestCount = 1;
    $expiredPdo->row = $duplicatePdo->row;
    $expiredPdo->row['verification_token_expires_at'] = date('Y-m-d H:i:s', $now - 1);
    $expiredOldHash = (string) $expiredPdo->row['verification_token_hash'];
    $GLOBALS['viewer_phase41_pdo'] = $expiredPdo;
    $expiredResult = viewer_registration_request_begin('duplicate@example.com', null, '192.0.2.41');
    viewer_phase41_assert(!empty($expiredResult['mail_eligible']) && !empty($expiredResult['verification_token']), 'Expired mailed verification authority may be replaced by a fresh token.');
    viewer_phase41_assert($expiredPdo->row['verification_token_hash'] !== $expiredOldHash, 'Expired verification authority may rotate to a fresh hash.');

    $controller = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
    $httpService = (string) file_get_contents($root . '/app/services/viewer_http.php');
    $registrationService = (string) file_get_contents($root . '/app/services/viewer_registration.php');
    $dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
    $routing = (string) file_get_contents($root . '/app/bootstrap/routing.php');
    $requestHelpers = (string) file_get_contents($root . '/app/helpers_request.php');
    $layout = (string) file_get_contents($root . '/app/views/layout.php');
    $accountsService = (string) file_get_contents($root . '/app/services/viewer_accounts.php');
    $featureFlags = (string) file_get_contents($root . '/app/services/feature_flags.php');

    // New route and clean routing are explicit and remain behind the global viewer feature wrapper.
    viewer_phase41_assert(str_contains($dispatch, "'viewer_register' => '\\\\Gallery\\\\Controllers\\\\cms_viewer_register'"), 'Dispatch must expose viewer_register through cms_viewer_register().');
    viewer_phase41_assert(str_contains($routing, "\$segments === ['viewer', 'register']") && str_contains($routing, "['page' => 'viewer_register'"), 'Clean /viewer/register input routing must resolve to viewer_register.');
    viewer_phase41_assert(str_contains($requestHelpers, "\$page === 'viewer_register'") && str_contains($requestHelpers, "base_url('viewer/register')"), 'Clean URL output must support /viewer/register when rewriting is enabled.');
    viewer_phase41_assert(str_contains($featureFlags, "str_starts_with(\$page, 'viewer_')") && str_contains($featureFlags, "return 'viewer_accounts';"), 'Global Viewer Accounts feature wrapper must own the generic registration route.');

    $register = viewer_phase41_function_source($controller, 'cms_viewer_register');
    $deliver = viewer_phase41_function_source($controller, 'viewer_deliver_registration_verification');
    $verify = viewer_phase41_function_source($controller, 'cms_viewer_verify');
    $confirm = viewer_phase41_function_source($registrationService, 'viewer_registration_verification_confirm');
    $activate = viewer_phase41_function_source($registrationService, 'viewer_registration_activate_verified');
    $admin = viewer_phase41_function_source($controller, 'cms_admin_viewer_invitations');
    $openHttp = viewer_phase41_function_source($httpService, 'viewer_http_open_registration_available');
    $lifecycleHttp = viewer_phase41_function_source($httpService, 'viewer_http_registration_lifecycle_available');

    viewer_phase41_assert(str_contains($register, 'viewer_http_open_registration_available()'), 'Generic registration controller must use the narrow open-registration HTTP gate.');
    viewer_phase41_assert(str_contains($lifecycleHttp, 'viewer_accounts_enabled()') && str_contains($lifecycleHttp, "['invite_only', 'open']") && str_contains($lifecycleHttp, 'viewer_security_transport_allowed()') && str_contains($lifecycleHttp, 'viewer_auth_storage_available()') && str_contains($lifecycleHttp, 'viewer_registration_storage_available()'), 'Shared registration HTTP availability must require feature, policy, transport, auth storage, and registration storage.');
    viewer_phase41_assert(str_contains($openHttp, "viewer_registration_mode() === 'open'"), 'Generic registration availability must require exact open mode.');

    // CSRF and origin authority are controller-orchestrated without password/account/session creation.
    viewer_phase41_assert(str_contains($register, 'viewer_csrf_field()'), 'Open registration GET must render the viewer CSRF field.');
    viewer_phase41_assert(str_contains($register, 'viewer_verify_csrf_or_render_error()'), 'Open registration POST must reject invalid viewer CSRF.');
    viewer_phase41_assert(str_contains($register, 'viewer_registration_request_begin($email, null, request_client_ip())'), 'Open registration POST must call the existing registration service with a null invitation.');
    viewer_phase41_assert(!str_contains($register, "name=\"password\"") && !str_contains($register, "\$_POST['password']"), 'Open registration must not collect a password before email verification.');
    viewer_phase41_assert(!str_contains($register, 'viewer_session_establish') && !str_contains($register, "\$_SESSION['user_id']") && !str_contains($register, 'current_user('), 'Registration GET/POST must not establish or inspect Viewer/Admin identity.');
    viewer_phase41_assert(!str_contains($register, 'invitation_token') && !str_contains($register, "\$_POST['token']"), 'Generic registration route must not accept invitation/origin authority from anonymous form input.');

    // All ordinary service/mail outcomes converge on one generic response string with no internal reason disclosure.
    viewer_phase41_assert(substr_count($register, 'viewer.register.request_received') === 1, 'Registration POST must render one generic public outcome notice.');
    foreach (['existing_account', 'rate_limited', 'storage_cap', 'limiter_unavailable', 'pending_verification', 'mail_transport_unavailable'] as $internalReason) {
        viewer_phase41_assert(!str_contains($register, $internalReason), 'Generic registration HTTP output must not branch on internal reason: ' . $internalReason);
    }
    viewer_phase41_assert(!str_contains($register, "['verification_token']") && !str_contains($register, 'verification_token_hash'), 'Registration controller output must never render verification secret material.');

    // Existing mail-abuse and trusted URL services are reused, and successful handoff precedes sent marking.
    $mailAuthorizationPos = strpos($deliver, 'viewer_mail_authorize_send(VIEWER_MAIL_ACTION_VERIFICATION');
    $securityUrlPos = strpos($deliver, "viewer_security_url('index.php'");
    $mailTransportPos = strpos($deliver, 'viewer_send_security_mail(');
    $markSentPos = strpos($deliver, 'viewer_registration_mark_verification_sent(');
    viewer_phase41_assert($mailAuthorizationPos !== false && $securityUrlPos !== false && $mailTransportPos !== false && $markSentPos !== false && $mailAuthorizationPos < $mailTransportPos && $securityUrlPos < $mailTransportPos && $mailTransportPos < $markSentPos, 'Verification mail must be authorized, use trusted URL construction, hand off through configured transport, then mark sent.');
    viewer_phase41_assert(str_contains($deliver, 'viewer.email.open_verification_body') && str_contains($deliver, 'viewer.email.verification_body'), 'Open signup must use neutral verification wording while invitation flow may retain invitation wording.');
    foreach (['viewer_verify_mail_email_cooldown', 'viewer_verify_mail_email_hour', 'viewer_verify_mail_email_day', 'viewer_verify_mail_ip_hour', 'viewer_verify_mail_ip_day', 'viewer_verify_mail_subnet_hour', 'viewer_verify_mail_subnet_day', 'viewer_verify_mail_global_day'] as $mailBucket) {
        $mailService = (string) file_get_contents($root . '/app/services/viewer_mail.php');
        viewer_phase41_assert(str_contains($mailService, $mailBucket), 'Existing verification-mail abuse bucket must remain present: ' . $mailBucket);
    }

    // Scanner-safe verification route is generalized at the HTTP gate, while service policy remains authoritative.
    viewer_phase41_assert(str_contains($verify, 'viewer_http_registration_verification_available()'), 'Verification HTTP route must use the shared registration lifecycle gate.');
    viewer_phase41_assert(str_contains($verify, 'viewer_registration_verification_validate($token)') && str_contains($verify, 'viewer_registration_verification_confirm($token)'), 'Verification must retain scanner-safe GET inspection plus explicit POST confirmation.');
    viewer_phase41_assert(!str_contains($verify, 'viewer_session_establish') && !str_contains($confirm, 'viewer_session_establish'), 'Verification GET/confirmation alone must not establish Viewer identity.');
    viewer_phase41_assert(str_contains($activate, 'viewer_registration_request_allowed_by_current_mode($request)') && strpos($activate, 'viewer_registration_request_allowed_by_current_mode($request)') < strpos($activate, 'INSERT INTO viewer_accounts'), 'Final activation must retain Phase 4.0 current-mode authorization before durable account creation.');
    viewer_phase41_assert(!str_contains($activate, 'viewer_session_establish') && !str_contains($activate, "\$_SESSION['user_id']"), 'Final activation must create a durable viewer account without auto-login or Admin identity.');
    viewer_phase41_assert(str_contains($verify, "redirect_to(url_for('viewer_login', ['activated' => '1']))"), 'Successful activation must return to the separate viewer login ceremony.');

    // Admin UI is exactly three-state and delegates lifecycle-aware persistence to the single existing setting service.
    viewer_phase41_assert(str_contains($admin, '<select name="viewer_accounts_mode" required>'), 'Admin registration mode must be one clear selector.');
    foreach (['disabled', 'invite_only', 'open'] as $mode) {
        viewer_phase41_assert(str_contains($admin, '<option value="' . $mode . '"'), 'Admin selector must expose registration mode: ' . $mode);
    }
    viewer_phase41_assert(str_contains($admin, "in_array(\$requestedMode, ['disabled', 'invite_only', 'open'], true)"), 'Admin POST must explicitly allow only the three registration modes.');
    viewer_phase41_assert(str_contains($admin, 'viewer_accounts_set_admin_registration_mode($requestedMode)'), 'Admin mode mutation must use the lifecycle-aware registration-mode service.');
    viewer_phase41_assert(!str_contains($admin, 'set_app_setting(') && !str_contains($admin, 'INSERT INTO app_settings') && !str_contains($admin, 'UPDATE app_settings'), 'Admin controller must not write registration settings SQL/direct storage.');
    viewer_phase41_assert(str_contains($accountsService, "VIEWER_ACCOUNT_ADMIN_MODE_SETTING_KEY = 'viewer_accounts_admin_mode'"), 'Backend must continue using the single viewer_accounts_admin_mode setting.');
    viewer_phase41_assert(str_contains($admin, "'old_mode' => \$oldMode") && str_contains($admin, "'new_mode' => \$requestedMode") && str_contains($admin, "'cancelled_open_origin_staging_count' => \$cancelledOpenOriginStagingCount"), 'Mode transition audit event must record bounded old/new/cancelled context.');

    // Invitation path remains mandatory-token based in both invite_only and open.
    $invite = viewer_phase41_function_source($controller, 'cms_viewer_invite');
    viewer_phase41_assert(str_contains($invite, 'viewer_http_invite_registration_available()'), 'Invitation route must use the shared invite registration gate.');
    viewer_phase41_assert(str_contains($invite, 'viewer_invitation_inspect($token)') && str_contains($invite, 'viewer_registration_request_begin($email, $token, request_client_ip())'), 'Invitation route must retain mandatory bearer validation and pass the invitation to the existing service.');

    // Discoverability is guarded by exact open availability on login and public header.
    $login = viewer_phase41_function_source($controller, 'cms_viewer_login');
    viewer_phase41_assert(str_contains($login, 'if (viewer_http_open_registration_available())') && str_contains($login, "url_for('viewer_register')"), 'Viewer login must show Create viewer account only when open registration is available.');
    viewer_phase41_assert(str_contains($layout, 'if (viewer_http_open_registration_available())') && str_contains($layout, "url_for('viewer_register')"), 'Anonymous public header must show Register only behind the open-registration availability helper.');

    // Phase 4.2 may add explicit resend, but CAPTCHA, public profiles, and Phase 5 authentication remain absent.
    foreach (["'viewer_profile'", "'viewer_passkey'", "'viewer_totp'", "'viewer_oidc'"] as $forbiddenSurface) {
        viewer_phase41_assert(!str_contains($dispatch . $routing . $controller, $forbiddenSurface), 'Out-of-scope surface must remain absent after Phase 4.1: ' . $forbiddenSurface);
    }
    viewer_phase41_assert(!str_contains($controller . $registrationService, 'turnstile_site_key') && !str_contains($controller . $registrationService, 'turnstile_secret_key') && !str_contains($controller . $registrationService, 'viewer_captcha_') && !str_contains($controller . $registrationService, 'challenge_mode'), 'Phase 4.1 must not introduce Turnstile/CAPTCHA behavior.');

    // No registration-origin schema was added; origin remains authoritative from viewer_invitation_id.
    foreach (glob($root . '/database/migrations/*.php') ?: [] as $migrationFile) {
        $migration = (string) file_get_contents($migrationFile);
        viewer_phase41_assert(!str_contains($migration, 'registration_origin') && !str_contains($migration, 'signup_source') && !str_contains($migration, 'resend_token'), 'Phase 4.1 must not add a registration-origin/resend schema column.');
    }

    // New visible strings are complete in all supported catalogs.
    $translationKeys = [
        'viewer.admin.invites.mode_selector_label',
        'viewer.admin.invites.mode_disabled_label',
        'viewer.admin.invites.mode_invite_only_label',
        'viewer.admin.invites.mode_open_label',
        'viewer.admin.invites.mode_disabled_help',
        'viewer.admin.invites.mode_invite_only_help',
        'viewer.admin.invites.mode_open_help',
        'viewer.admin.invites.mode_open',
        'viewer.admin.invites.mode_invalid',
        'viewer.register.title',
        'viewer.register.help',
        'viewer.register.button',
        'viewer.register.request_received',
        'viewer.email.open_verification_body',
        'viewer.login.register_link',
        'viewer.nav.register',
    ];
    foreach (['en', 'cs', 'de', 'sv'] as $language) {
        $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true);
        viewer_phase41_assert(is_array($catalog), 'Translation catalog failed to decode: ' . $language);
        foreach ($translationKeys as $key) {
            viewer_phase41_assert(isset($catalog[$key]) && is_string($catalog[$key]) && trim($catalog[$key]) !== '', 'Missing Phase 4.1 translation ' . $key . ' in ' . $language . '.');
        }
    }

    echo "Viewer open-registration Phase 4.1 HTTP tests passed.\n";
}
