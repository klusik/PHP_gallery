<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_anti_automation_phase43_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the Phase 4.3 fully first-party adaptive anti-automation boundary.
 *
 * Responsibilities:
 *   - Exercise signed, action-bound, session-bound, expiring, single-use form/challenge tickets
 *   - Verify server-measured form-age, randomized honeypot, deterministic escalation, and bounded session state
 *   - Verify bounded SHA-256 proof-of-work and the first-party no-JavaScript fallback
 *   - Verify registration/resend controllers short-circuit suppression before existing expensive services
 *   - Protect Phase 4.0 through 4.2 rate-limit, generic-response, token, mode, scanner-safe, and principal boundaries
 *   - Reject third-party CAPTCHA, remote challenge, fingerprinting, Composer/npm, Redis, and Memcached integration
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This focused fixture requires no live database, mail server, remote service, or browser.
 *
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

namespace Gallery\Services {
    /** Return deterministic normalized Phase 4.3 configuration for the focused fixture. */
    function viewer_accounts_config(): array
    {
        return $GLOBALS['viewer_phase43_config'];
    }

    /** Return an installation-specific HMAC fingerprint for focused ticket tests. */
    function viewer_security_fingerprint(string $scope, string $value): string
    {
        return hash_hmac('sha256', $scope . "\0" . $value, 'phase43-installation-secret');
    }

    /** Generate one URL-safe high-entropy token using the same native randomness contract. */
    function security_opaque_token_generate(int $entropyBytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($entropyBytes)), '+/', '-_'), '=');
    }

    /** Normalize a focused IPv4/IPv6 subject or return an empty string. */
    function request_client_ip_normalize(string $ip): string
    {
        $ip = trim($ip);
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? '' : $ip;
    }

    /** Consume one deterministic in-memory representation of the existing viewer limiter. */
    function viewer_rate_limit_consume(string $bucket, string $subjectKind, string $subject): array
    {
        $GLOBALS['viewer_phase43_limiter_calls'][] = [$bucket, $subjectKind, $subject];
        if (!empty($GLOBALS['viewer_phase43_force_limiter_denial'])) {
            return ['allowed' => false, 'retry_after_seconds' => 900, 'attempts' => 99, 'reason' => 'locked'];
        }
        $key = $bucket . '|' . $subjectKind . '|' . $subject;
        $attempts = (int) (($GLOBALS['viewer_phase43_limiter_attempts'][$key] ?? 0) + 1);
        $GLOBALS['viewer_phase43_limiter_attempts'][$key] = $attempts;
        $maximum = $bucket === 'viewer_automation_ip' ? 8 : 48;
        return [
            'allowed' => $attempts <= $maximum,
            'retry_after_seconds' => $attempts <= $maximum ? 0 : 900,
            'attempts' => $attempts,
            'reason' => $attempts <= $maximum ? 'ok' : 'locked',
        ];
    }

    /** Return no authenticated Viewer principal for anonymous controller fixtures. */
    function current_viewer(): ?array
    {
        return null;
    }

    /** Return a deterministic valid client IP for anonymous controller fixtures. */
    function request_client_ip(): string
    {
        return '192.0.2.55';
    }

    /** Return a minimal localized fallback string for controller rendering. */
    function t(string $key, string $fallback = '', array $replace = []): string
    {
        $text = $fallback !== '' ? $fallback : $key;
        foreach ($replace as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** Verify the deterministic Viewer/pre-auth CSRF token used by controller fixtures. */
    function viewer_csrf_verify(string $token): bool
    {
        return hash_equals('phase43-csrf', $token);
    }

    /** Return the deterministic Viewer/pre-auth CSRF token used by controller fixtures. */
    function viewer_csrf_token(): string
    {
        return 'phase43-csrf';
    }

    /** Normalize a syntactically valid focused email lookup candidate. */
    function viewer_email_normalize(string $email): ?string
    {
        $email = strtolower(trim($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : $email;
    }

    /** Keep the open-registration controller route available in focused fixtures. */
    function viewer_http_open_registration_available(): bool
    {
        return true;
    }

    /** Keep the resend controller route/link available in focused fixtures. */
    function viewer_http_verification_resend_available(): bool
    {
        return true;
    }

    /** Count any accidental registration-service call made during suppression fixtures. */
    function viewer_registration_request_begin(string $email, ?string $invitationToken, string $clientIp): array
    {
        $GLOBALS['viewer_phase43_registration_begin_calls']++;
        return ['accepted' => false];
    }

    /** Count any accidental resend-service call made during suppression fixtures. */
    function viewer_registration_verification_resend_prepare(string $email): array
    {
        $GLOBALS['viewer_phase43_resend_prepare_calls']++;
        return ['mail_eligible' => false];
    }

    /** Capture bounded security events without requiring persistent storage. */
    function viewer_security_event_record_best_effort(
        string $eventKey,
        ?int $viewerAccountId = null,
        string $outcome = '',
        array $context = []
    ): void {
        $GLOBALS['viewer_phase43_events'][] = [$eventKey, $viewerAccountId, $outcome, $context];
    }
}

namespace Gallery\Core {
    /** Return the deterministic request method used by controller fixtures. */
    function request_method(): string
    {
        return (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** Escape focused rendered output using the application HTML contract. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Render no surrounding layout in the focused controller fixture. */
    function render_header(string $title = ''): void
    {
    }

    /** Render no surrounding layout footer in the focused controller fixture. */
    function render_footer(): void
    {
    }

    /** Build a deterministic local route for focused controller output. */
    function url_for(string $page, array $params = []): string
    {
        return '/index.php?page=' . rawurlencode($page);
    }

    /** Clear no headers in CLI-focused controller fixtures. */
    function clear_response_cache_headers(): void
    {
    }

    /** Return a deterministic local asset path for any unexpected challenge render. */
    function asset_url(string $path): string
    {
        return '/' . ltrim($path, '/');
    }
}

namespace Gallery\Controllers {
    /** Clear no response-cache headers in the focused CLI controller fixture. */
    function clear_response_cache_headers(): void
    {
    }
}

namespace {
    use function Gallery\Services\viewer_anti_automation_authorize_submission;
    use function Gallery\Services\viewer_anti_automation_challenge_issue;
    use function Gallery\Services\viewer_anti_automation_form_issue;
    use function Gallery\Services\viewer_anti_automation_policy_decision;
    use function Gallery\Services\viewer_anti_automation_pow_verify;
    use function Gallery\Services\viewer_anti_automation_session_cleanup;
    use function Gallery\Services\viewer_anti_automation_ticket_decode;
    use function Gallery\Services\viewer_anti_automation_ticket_validate;
    use function Gallery\Services\viewer_anti_automation_base64url_encode;
    use const Gallery\Services\VIEWER_ANTI_AUTOMATION_ACTION_REGISTER;
    use const Gallery\Services\VIEWER_ANTI_AUTOMATION_ACTION_RESEND;
    use const Gallery\Services\VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE;
    use const Gallery\Services\VIEWER_ANTI_AUTOMATION_KIND_FORM;
    use const Gallery\Services\VIEWER_ANTI_AUTOMATION_MAX_COUNTER;
    use const Gallery\Services\VIEWER_ANTI_AUTOMATION_OUTSTANDING_CAP;
    use const Gallery\Services\VIEWER_ANTI_AUTOMATION_RESULT_ALLOW;
    use const Gallery\Services\VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED;
    use const Gallery\Services\VIEWER_ANTI_AUTOMATION_RESULT_INVALID;
    use const Gallery\Services\VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS;

    $root = dirname(__DIR__);
    $GLOBALS['viewer_phase43_config'] = [
        'anti_automation_enabled' => true,
        'anti_automation_min_form_age_seconds' => 2,
        'anti_automation_form_lifetime_seconds' => 600,
        'anti_automation_pow_min_bits' => 10,
        'anti_automation_pow_max_bits' => 12,
    ];
    $GLOBALS['viewer_phase43_limiter_calls'] = [];
    $GLOBALS['viewer_phase43_limiter_attempts'] = [];
    $GLOBALS['viewer_phase43_force_limiter_denial'] = false;
    $GLOBALS['viewer_phase43_events'] = [];
    $GLOBALS['viewer_phase43_registration_begin_calls'] = 0;
    $GLOBALS['viewer_phase43_resend_prepare_calls'] = 0;
    $_SESSION = [];

    require_once $root . '/app/services/viewer_anti_automation.php';

    /** Fail the focused test with one explicit message. */
    function viewer_phase43_fail(string $message): void
    {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }

    /** Assert one Phase 4.3 invariant. */
    function viewer_phase43_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            viewer_phase43_fail($message);
        }
    }

    /** Return a ticket with one authenticated field changed but the old signature retained. */
    function viewer_phase43_tamper_ticket(string $ticket, string $field, mixed $value): string
    {
        [$encoded, $signature] = explode('.', $ticket, 2);
        $padding = strlen($encoded) % 4;
        $padded = $encoded . ($padding === 0 ? '' : str_repeat('=', 4 - $padding));
        $json = base64_decode(strtr($padded, '-_', '+/'), true);
        $payload = json_decode((string) $json, true);
        if (!is_array($payload)) {
            viewer_phase43_fail('Could not decode focused ticket fixture.');
        }
        $payload[$field] = $value;
        $tamperedJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return viewer_anti_automation_base64url_encode((string) $tamperedJson) . '.' . $signature;
    }

    /** Return one valid proof counter for the supplied server-issued challenge. */
    function viewer_phase43_find_pow_counter(string $action, string $challenge, int $difficulty): string
    {
        for ($counter = 0; $counter <= VIEWER_ANTI_AUTOMATION_MAX_COUNTER; $counter++) {
            if (viewer_anti_automation_pow_verify($action, $challenge, $difficulty, (string) $counter)) {
                return (string) $counter;
            }
        }
        viewer_phase43_fail('Focused proof fixture did not find a bounded solution.');
    }

    /** Return one counter that definitely does not satisfy the supplied proof target. */
    function viewer_phase43_find_invalid_pow_counter(string $action, string $challenge, int $difficulty): string
    {
        for ($counter = 0; $counter <= VIEWER_ANTI_AUTOMATION_MAX_COUNTER; $counter++) {
            if (!viewer_anti_automation_pow_verify($action, $challenge, $difficulty, (string) $counter)) {
                return (string) $counter;
            }
        }
        viewer_phase43_fail('Focused proof fixture did not find a bounded invalid counter.');
    }

    /** Reset focused limiter state between independent policy scenarios. */
    function viewer_phase43_reset_limits(): void
    {
        $GLOBALS['viewer_phase43_limiter_calls'] = [];
        $GLOBALS['viewer_phase43_limiter_attempts'] = [];
        $GLOBALS['viewer_phase43_force_limiter_denial'] = false;
    }

    // Form-ticket issuance, bounded expiry, randomness, action binding, and randomized honeypot metadata.
    $formA = viewer_anti_automation_form_issue(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, 1000);
    $formB = viewer_anti_automation_form_issue(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, 1000);
    viewer_phase43_assert($formA['ticket'] !== $formB['ticket'], 'Form tickets must contain cryptographic randomness.');
    viewer_phase43_assert($formA['honeypot_field'] !== $formB['honeypot_field'], 'Honeypot field identifiers must vary per form.');
    viewer_phase43_assert($formA['expires_at'] - $formA['issued_at'] === 600, 'Form ticket expiry must be bounded by normalized configuration.');
    viewer_phase43_assert(
        viewer_anti_automation_ticket_validate($formA['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM, VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, false, 1001) !== null,
        'Fresh registration form ticket must validate in its issuing session.'
    );
    viewer_phase43_assert(
        viewer_anti_automation_ticket_validate($formA['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM, VIEWER_ANTI_AUTOMATION_ACTION_RESEND, false, 1001) === null,
        'Registration form ticket must not authorize resend.'
    );

    // Signature tampering over every authoritative form field must fail.
    $decodedForm = viewer_anti_automation_ticket_decode($formA['ticket']);
    viewer_phase43_assert(is_array($decodedForm), 'Issued form ticket must decode after signature validation.');
    foreach ([
        'a' => VIEWER_ANTI_AUTOMATION_ACTION_RESEND,
        'n' => str_repeat('A', 32),
        'i' => 999,
        'e' => 1700,
        'h' => 'vf_' . str_repeat('0', 16),
    ] as $field => $value) {
        viewer_phase43_assert(
            viewer_anti_automation_ticket_validate(
                viewer_phase43_tamper_ticket($formA['ticket'], $field, $value),
                VIEWER_ANTI_AUTOMATION_KIND_FORM,
                VIEWER_ANTI_AUTOMATION_ACTION_REGISTER,
                false,
                1001
            ) === null,
            'Tampered signed form field must fail: ' . $field
        );
    }

    // Session binding and replay protection do not expose or require a session id inside the ticket.
    $bound = viewer_anti_automation_form_issue(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, 1100);
    $issuingSession = $_SESSION;
    $_SESSION = [];
    viewer_phase43_assert(
        viewer_anti_automation_ticket_validate($bound['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM, VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, true, 1101) === null,
        'Ticket issued in one PHP session must not authorize another session.'
    );
    $_SESSION = $issuingSession;
    viewer_phase43_assert(!str_contains($bound['ticket'], 'session'), 'Browser ticket must not expose a PHP session identifier field.');
    viewer_phase43_assert(
        viewer_anti_automation_ticket_validate($bound['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM, VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, true, 1101) !== null,
        'Matching issuing session must consume a valid ticket once.'
    );
    viewer_phase43_assert(
        viewer_anti_automation_ticket_validate($bound['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM, VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, true, 1101) === null,
        'Consumed form ticket must not replay.'
    );

    // Outstanding session authority stays bounded and expired entries are removed opportunistically.
    $_SESSION = [];
    for ($index = 0; $index < 40; $index++) {
        viewer_anti_automation_form_issue(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, 1200 + $index);
    }
    $entries = $_SESSION['viewer_anti_automation']['entries'] ?? [];
    viewer_phase43_assert(is_array($entries) && count($entries) <= VIEWER_ANTI_AUTOMATION_OUTSTANDING_CAP, 'Session ticket state must remain bounded.');
    viewer_anti_automation_session_cleanup(2000);
    viewer_phase43_assert(($_SESSION['viewer_anti_automation']['entries'] ?? []) === [], 'Expired session ticket entries must be removed.');

    // Server-measured form age is an escalation signal, not a browser-supplied timer.
    $neutralSignals = ['allowed' => true, 'reason' => 'ok', 'ip_attempts' => 1, 'subnet_attempts' => 1, 'retry_after_seconds' => 0];
    viewer_phase43_assert(
        viewer_anti_automation_policy_decision(5, false, $neutralSignals)['result'] === VIEWER_ANTI_AUTOMATION_RESULT_ALLOW,
        'Ordinary server-measured age and clean first attempt must remain challenge-free.'
    );
    viewer_phase43_assert(
        viewer_anti_automation_policy_decision(0, false, $neutralSignals)['result'] === VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED,
        'Implausibly fast server-measured submission must escalate rather than permanently deny.'
    );
    viewer_phase43_assert(
        viewer_anti_automation_ticket_validate($formA['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM, VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, false, 1700) === null,
        'Expired form state must not authorize expensive work.'
    );

    // Populated randomized honeypot short-circuits before limiter/database-backed downstream work.
    $_SESSION = [];
    viewer_phase43_reset_limits();
    $trap = viewer_anti_automation_form_issue(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, 2000);
    $trapDecision = viewer_anti_automation_authorize_submission(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, [
        'viewer_aa_form_ticket' => $trap['ticket'],
        $trap['honeypot_field'] => 'filled-by-automation',
    ], '192.0.2.10', 2005);
    viewer_phase43_assert($trapDecision['result'] === VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS, 'Populated honeypot must suppress the request.');
    viewer_phase43_assert($GLOBALS['viewer_phase43_limiter_calls'] === [], 'Honeypot suppression must occur before even local rate-limit work.');

    // Clean first attempts pass, repeated local attempts escalate, and hard local limits suppress.
    $_SESSION = [];
    viewer_phase43_reset_limits();
    $lastDecision = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $form = viewer_anti_automation_form_issue(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, 3000 + ($attempt * 10));
        $lastDecision = viewer_anti_automation_authorize_submission(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, [
            'viewer_aa_form_ticket' => $form['ticket'],
            $form['honeypot_field'] => '',
        ], '192.0.2.20', 3005 + ($attempt * 10));
        if ($attempt < 3) {
            viewer_phase43_assert($lastDecision['result'] === VIEWER_ANTI_AUTOMATION_RESULT_ALLOW, 'First two ordinary local requests must remain challenge-free.');
        }
    }
    viewer_phase43_assert($lastDecision['result'] === VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED, 'Repeated local request must escalate to a challenge.');
    viewer_phase43_assert(is_array($lastDecision['challenge'] ?? null), 'Escalation must issue bounded first-party challenge state.');

    $challenge = $lastDecision['challenge'];
    $validCounter = viewer_phase43_find_pow_counter(
        VIEWER_ANTI_AUTOMATION_ACTION_REGISTER,
        (string) $challenge['challenge'],
        (int) $challenge['difficulty']
    );
    $challengePost = [
        'viewer_aa_challenge_ticket' => $challenge['ticket'],
        'viewer_aa_pow_counter' => $validCounter,
    ];
    $challengePass = viewer_anti_automation_authorize_submission(
        VIEWER_ANTI_AUTOMATION_ACTION_REGISTER,
        $challengePost,
        '192.0.2.20',
        3040
    );
    viewer_phase43_assert($challengePass['result'] === VIEWER_ANTI_AUTOMATION_RESULT_ALLOW, 'One valid local proof must authorize continuation only.');
    $challengeReplay = viewer_anti_automation_authorize_submission(
        VIEWER_ANTI_AUTOMATION_ACTION_REGISTER,
        $challengePost,
        '192.0.2.20',
        3040
    );
    viewer_phase43_assert($challengeReplay['result'] === VIEWER_ANTI_AUTOMATION_RESULT_INVALID, 'Consumed challenge must not replay.');

    $_SESSION = [];
    viewer_phase43_reset_limits();
    $hardLimitedForm = viewer_anti_automation_form_issue(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, 3060);
    $GLOBALS['viewer_phase43_force_limiter_denial'] = true;
    $hardLimitedDecision = viewer_anti_automation_authorize_submission(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, [
        'viewer_aa_form_ticket' => $hardLimitedForm['ticket'],
        $hardLimitedForm['honeypot_field'] => '',
    ], '192.0.2.21', 3065);
    viewer_phase43_assert($hardLimitedDecision['result'] === VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS, 'Hard anti-automation limiter denial must suppress before downstream work.');
    viewer_phase43_reset_limits();

    // Challenge authority is action-bound, expiry-bound, difficulty-bound, and counter-bounded.
    $_SESSION = [];
    $challengeState = viewer_anti_automation_challenge_issue(VIEWER_ANTI_AUTOMATION_ACTION_RESEND, 10, 4000);
    viewer_phase43_assert(
        viewer_anti_automation_ticket_validate($challengeState['ticket'], VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE, VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, false, 4001) === null,
        'Resend challenge must not authorize registration.'
    );
    viewer_phase43_assert(
        viewer_anti_automation_ticket_validate(
            viewer_phase43_tamper_ticket($challengeState['ticket'], 'd', 12),
            VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE,
            VIEWER_ANTI_AUTOMATION_ACTION_RESEND,
            false,
            4001
        ) === null,
        'Tampered challenge difficulty must fail signature validation.'
    );
    viewer_phase43_assert(
        viewer_anti_automation_ticket_validate(
            viewer_phase43_tamper_ticket($challengeState['ticket'], 'n', str_repeat('B', 32)),
            VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE,
            VIEWER_ANTI_AUTOMATION_ACTION_RESEND,
            false,
            4001
        ) === null,
        'Tampered challenge nonce must fail signature validation.'
    );
    $challengeIssuingSession = $_SESSION;
    $_SESSION = [];
    viewer_phase43_assert(
        viewer_anti_automation_ticket_validate($challengeState['ticket'], VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE, VIEWER_ANTI_AUTOMATION_ACTION_RESEND, true, 4001) === null,
        'Challenge issued in one PHP session must not authorize another session.'
    );
    $_SESSION = $challengeIssuingSession;
    viewer_phase43_assert(
        viewer_anti_automation_ticket_validate($challengeState['ticket'], VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE, VIEWER_ANTI_AUTOMATION_ACTION_RESEND, false, 4181) === null,
        'Expired challenge must not authorize continuation.'
    );
    viewer_phase43_assert(
        !viewer_anti_automation_pow_verify(VIEWER_ANTI_AUTOMATION_ACTION_RESEND, (string) $challengeState['challenge'], 10, (string) (VIEWER_ANTI_AUTOMATION_MAX_COUNTER + 1)),
        'Counter beyond the hard bound must be rejected before proof acceptance.'
    );
    // Wrong submitted proof is one-shot and results in a fresh challenge rather than server-side brute force.
    $_SESSION = [];
    $wrongProofChallenge = viewer_anti_automation_challenge_issue(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, 10, 5000);
    $wrongCounter = viewer_phase43_find_invalid_pow_counter(
        VIEWER_ANTI_AUTOMATION_ACTION_REGISTER,
        (string) $wrongProofChallenge['challenge'],
        (int) $wrongProofChallenge['difficulty']
    );
    $wrongProof = viewer_anti_automation_authorize_submission(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, [
        'viewer_aa_challenge_ticket' => $wrongProofChallenge['ticket'],
        'viewer_aa_pow_counter' => $wrongCounter,
    ], '192.0.2.30', 5001);
    viewer_phase43_assert($wrongProof['result'] === VIEWER_ANTI_AUTOMATION_RESULT_CHALLENGE_REQUIRED, 'Wrong proof must not authorize downstream work.');
    viewer_phase43_assert(is_array($wrongProof['challenge'] ?? null), 'Wrong proof should require fresh one-time challenge state.');

    $_SESSION = [];
    viewer_phase43_reset_limits();
    $challengeLimited = viewer_anti_automation_challenge_issue(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, 10, 5050);
    $challengeLimitedCounter = viewer_phase43_find_pow_counter(
        VIEWER_ANTI_AUTOMATION_ACTION_REGISTER,
        (string) $challengeLimited['challenge'],
        (int) $challengeLimited['difficulty']
    );
    $GLOBALS['viewer_phase43_force_limiter_denial'] = true;
    viewer_phase43_assert(
        viewer_anti_automation_authorize_submission(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, [
            'viewer_aa_challenge_ticket' => $challengeLimited['ticket'],
            'viewer_aa_pow_counter' => $challengeLimitedCounter,
        ], '192.0.2.31', 5051)['result'] === VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS,
        'Active challenge submission must remain subject to the existing local anti-automation limiter.'
    );

    // First-party no-JavaScript fallback remains short-lived, session-bound, single-use, age-gated, and rate-limited.
    $_SESSION = [];
    viewer_phase43_reset_limits();
    $fallbackChallenge = viewer_anti_automation_challenge_issue(VIEWER_ANTI_AUTOMATION_ACTION_RESEND, 10, 6000);
    $fallbackPost = [
        'viewer_aa_challenge_ticket' => $fallbackChallenge['ticket'],
        'viewer_aa_fallback' => '1',
    ];
    $fallbackPass = viewer_anti_automation_authorize_submission(
        VIEWER_ANTI_AUTOMATION_ACTION_RESEND,
        $fallbackPost,
        '192.0.2.40',
        6004
    );
    viewer_phase43_assert($fallbackPass['result'] === VIEWER_ANTI_AUTOMATION_RESULT_ALLOW, 'Aged first-party fallback must permit bounded continuation.');
    viewer_phase43_assert(count($GLOBALS['viewer_phase43_limiter_calls']) === 2, 'Fallback must consume existing IP and subnet limiter dimensions.');
    viewer_phase43_assert(
        viewer_anti_automation_authorize_submission(VIEWER_ANTI_AUTOMATION_ACTION_RESEND, $fallbackPost, '192.0.2.40', 6004)['result'] === VIEWER_ANTI_AUTOMATION_RESULT_INVALID,
        'Fallback challenge must remain single-use.'
    );
    $_SESSION = [];
    viewer_phase43_reset_limits();
    $fallbackLimited = viewer_anti_automation_challenge_issue(VIEWER_ANTI_AUTOMATION_ACTION_RESEND, 10, 6100);
    $GLOBALS['viewer_phase43_force_limiter_denial'] = true;
    viewer_phase43_assert(
        viewer_anti_automation_authorize_submission(VIEWER_ANTI_AUTOMATION_ACTION_RESEND, [
            'viewer_aa_challenge_ticket' => $fallbackLimited['ticket'],
            'viewer_aa_fallback' => '1',
        ], '192.0.2.41', 6104)['result'] === VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS,
        'Fallback must fail closed when the existing limiter denies it.'
    );

    // Normalized production config hard-bounds lifetime and proof difficulty.
    $accountsSource = (string) file_get_contents($root . '/app/services/viewer_accounts.php');
    viewer_phase43_assert(str_contains($accountsSource, "'anti_automation_pow_min_bits'") && str_contains($accountsSource, '10, 16'), 'Proof difficulty must have hard normalized bounds.');
    viewer_phase43_assert(str_contains($accountsSource, "'anti_automation_form_lifetime_seconds'") && str_contains($accountsSource, '120, 1800'), 'Form lifetime must have hard normalized bounds.');

    // Integration: register and resend both require Viewer CSRF before the anti-automation service and existing business services.
    $controller = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
    $registerStart = strpos($controller, 'function cms_viewer_register(): void');
    $inviteStart = strpos($controller, 'function cms_viewer_invite(): void');
    $resendStart = strpos($controller, 'function cms_viewer_resend_verification(): void');
    $verifyStart = strpos($controller, 'function cms_viewer_verify(): void');
    viewer_phase43_assert(is_int($registerStart) && is_int($inviteStart) && is_int($resendStart) && is_int($verifyStart), 'Protected controller functions must remain present.');
    $registerBlock = substr($controller, $registerStart, $inviteStart - $registerStart);
    $resendBlock = substr($controller, $resendStart, $verifyStart - $resendStart);
    viewer_phase43_assert(strpos($registerBlock, 'viewer_verify_csrf_or_render_error()') < strpos($registerBlock, 'viewer_anti_automation_authorize_submission('), 'Registration must verify Viewer CSRF before anti-automation authorization.');
    viewer_phase43_assert(strpos($registerBlock, 'viewer_email_normalize($email)') < strpos($registerBlock, 'viewer_anti_automation_authorize_submission('), 'Registration must perform local email syntax validation before anti-automation authorization.');
    viewer_phase43_assert(strpos($registerBlock, 'viewer_anti_automation_authorize_submission(') < strpos($registerBlock, 'viewer_registration_request_begin('), 'Registration anti-automation gate must precede registration service work.');
    viewer_phase43_assert(strpos($resendBlock, 'viewer_verify_csrf_or_render_error()') < strpos($resendBlock, 'viewer_anti_automation_authorize_submission('), 'Resend must verify Viewer CSRF before anti-automation authorization.');
    viewer_phase43_assert(strpos($resendBlock, 'viewer_email_normalize($email)') < strpos($resendBlock, 'viewer_anti_automation_authorize_submission('), 'Resend must perform local email syntax validation before anti-automation authorization.');
    viewer_phase43_assert(strpos($resendBlock, 'viewer_anti_automation_authorize_submission(') < strpos($resendBlock, 'viewer_registration_verification_resend_prepare('), 'Resend anti-automation gate must precede resend authority creation.');
    viewer_phase43_assert(str_contains($registerBlock, 'viewer_anti_automation_form_fields(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER)'), 'Registration GET/form must carry signed first-party form state.');
    viewer_phase43_assert(str_contains($resendBlock, 'viewer_anti_automation_form_fields(VIEWER_ANTI_AUTOMATION_ACTION_RESEND)'), 'Resend GET/form must carry signed first-party form state.');
    viewer_phase43_assert(str_contains($controller, 'viewer_csrf_field();') && str_contains($controller, 'viewer_aa_challenge_ticket'), 'Challenge continuation and fallback must remain Viewer-CSRF protected.');

    // Suppress branches must not call registration/resend or mail work before returning generic completion state.
    $registerSuppressPos = strpos($registerBlock, 'VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS');
    $registerBeginPos = strpos($registerBlock, 'viewer_registration_request_begin(');
    $resendSuppressPos = strpos($resendBlock, 'VIEWER_ANTI_AUTOMATION_RESULT_SUPPRESS');
    $resendPreparePos = strpos($resendBlock, 'viewer_registration_verification_resend_prepare(');
    viewer_phase43_assert(is_int($registerSuppressPos) && is_int($registerBeginPos) && $registerSuppressPos < $registerBeginPos, 'Registration suppression branch must be decided before request staging.');
    viewer_phase43_assert(is_int($resendSuppressPos) && is_int($resendPreparePos) && $resendSuppressPos < $resendPreparePos, 'Resend suppression branch must be decided before resend authority preparation.');
    viewer_phase43_assert(substr_count($registerBlock, 'viewer.register.request_received') === 1, 'Registration must retain one generic valid-submission completion notice.');
    viewer_phase43_assert(substr_count($resendBlock, 'viewer.resend.request_received') === 1, 'Resend must retain one generic valid-submission completion notice.');

    require_once $root . '/app/controllers/viewer_accounts.php';

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SESSION = [];
    viewer_phase43_reset_limits();
    $GLOBALS['viewer_phase43_registration_begin_calls'] = 0;
    $registerTrap = viewer_anti_automation_form_issue(VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, time());
    $_POST = [
        'viewer_csrf_token' => 'phase43-csrf',
        'email' => 'person@example.test',
        'viewer_aa_form_ticket' => $registerTrap['ticket'],
        $registerTrap['honeypot_field'] => 'automation',
    ];
    ob_start();
    \Gallery\Controllers\cms_viewer_register();
    $registerSuppressedOutput = (string) ob_get_clean();
    viewer_phase43_assert($GLOBALS['viewer_phase43_registration_begin_calls'] === 0, 'Hard registration suppression must not reach viewer_registration_request_begin().');
    viewer_phase43_assert(str_contains($registerSuppressedOutput, 'If the registration request can be accepted'), 'Hard registration suppression must retain the generic public completion wording.');

    $_SESSION = [];
    viewer_phase43_reset_limits();
    $GLOBALS['viewer_phase43_resend_prepare_calls'] = 0;
    $resendTrap = viewer_anti_automation_form_issue(VIEWER_ANTI_AUTOMATION_ACTION_RESEND, time());
    $_POST = [
        'viewer_csrf_token' => 'phase43-csrf',
        'email' => 'person@example.test',
        'viewer_aa_form_ticket' => $resendTrap['ticket'],
        $resendTrap['honeypot_field'] => 'automation',
    ];
    ob_start();
    \Gallery\Controllers\cms_viewer_resend_verification();
    $resendSuppressedOutput = (string) ob_get_clean();
    viewer_phase43_assert($GLOBALS['viewer_phase43_resend_prepare_calls'] === 0, 'Hard resend suppression must not reach viewer_registration_verification_resend_prepare().');
    viewer_phase43_assert(str_contains($resendSuppressedOutput, 'If a verification message can be sent'), 'Hard resend suppression must retain the generic public completion wording.');
    $_POST = [];

    // Existing registration, resend-identifier, and verification-mail limiter families remain present and authoritative.
    $rateSource = (string) file_get_contents($root . '/app/services/viewer_rate_limits.php');
    foreach ([
        'viewer_register_ip',
        'viewer_register_subnet',
        'viewer_register_identifier',
        'viewer_register_global_day',
        'viewer_resend_verification_identifier',
        'viewer_verify_mail_email_cooldown',
        'viewer_verify_mail_email_hour',
        'viewer_verify_mail_email_day',
        'viewer_verify_mail_ip_hour',
        'viewer_verify_mail_ip_day',
        'viewer_verify_mail_subnet_hour',
        'viewer_verify_mail_subnet_day',
        'viewer_verify_mail_global_day',
        'viewer_automation_ip',
        'viewer_automation_subnet',
    ] as $bucket) {
        viewer_phase43_assert(str_contains($rateSource, "'{$bucket}'"), 'Required existing/local limiter bucket must remain allowlisted: ' . $bucket);
    }
    $antiSource = (string) file_get_contents($root . '/app/services/viewer_anti_automation.php');
    viewer_phase43_assert(str_contains($antiSource, "viewer_rate_limit_consume('viewer_automation_ip'") && str_contains($antiSource, "viewer_rate_limit_consume('viewer_automation_subnet'"), 'Phase 4.3 must reuse viewer_rate_limit_consume() rather than a controller/session-only limiter.');
    viewer_phase43_assert(!preg_match('/INSERT\s+INTO|UPDATE\s+viewer_registration|DELETE\s+FROM\s+viewer_registration|SELECT\s+.*viewer_registration/is', $antiSource), 'Anti-automation service must not own registration/verification SQL.');

    // The local solver is dependency-free, native-Web-Crypto-only, bounded, and fingerprint-free.
    $javascript = (string) file_get_contents($root . '/public/assets/viewer-anti-automation.js');
    viewer_phase43_assert(str_contains($javascript, "crypto.subtle.digest('SHA-256'"), 'Local solver must use native Web Crypto SHA-256.');
    viewer_phase43_assert(!preg_match('/\b(import|require)\s*\(|\bimport\s+[^;]+from\b/i', $javascript), 'Local solver must not import a hashing/npm dependency.');
    $javascriptRuntime = preg_replace('#/\*\*[\s\S]*?\*/#', '', $javascript) ?? $javascript;
    viewer_phase43_assert(!preg_match('#https?://#i', $javascriptRuntime), 'Local challenge JavaScript must not contact or import a remote origin.');
    foreach (['canvas', 'webgl', 'gpu', 'font', 'audiocontext', 'hardwareconcurrency', 'deviceMemory', 'fingerprint'] as $fingerprintTerm) {
        viewer_phase43_assert(!str_contains(strtolower($javascriptRuntime), strtolower($fingerprintTerm)), 'Local solver must not fingerprint the browser: ' . $fingerprintTerm);
    }
    viewer_phase43_assert(str_contains($javascript, 'maxCounter') && str_contains($javascript, 'counter <= maxCounter'), 'Browser proof loop must obey the server-supplied bounded counter ceiling.');

    // No third-party anti-bot runtime dependency or daemon/package integration is introduced by the affected Phase 4.3 files.
    $affectedRuntime = strtolower($antiSource . "\n" . $controller . "\n" . $javascript . "\n" . $rateSource . "\n" . $accountsSource);
    foreach (['turnstile', 'recaptcha', 'hcaptcha', 'friendly captcha', 'arkose', 'akismet', 'redis', 'memcached'] as $forbidden) {
        viewer_phase43_assert(!str_contains($affectedRuntime, $forbidden), 'Forbidden Phase 4.3 runtime integration detected: ' . $forbidden);
    }
    viewer_phase43_assert(!is_file($root . '/composer.json') && !is_file($root . '/package.json'), 'Phase 4.3 must not introduce Composer or npm package manifests.');
    viewer_phase43_assert(!preg_match('/curl_|file_get_contents\s*\(\s*[\'\"]https?:/i', $antiSource . $javascript), 'Anti-automation implementation must not make remote security requests.');

    // Principal/token/scanner-safe boundaries remain independent of the new gate.
    viewer_phase43_assert(!str_contains($antiSource, 'viewer_session_establish') && !str_contains($antiSource, "\$_SESSION['user_id']") && !str_contains($antiSource, 'VIEWER_SESSION_NAMESPACE'), 'Anti-automation success must create neither Viewer nor Admin identity.');
    foreach (['viewer_registration_request_begin', 'viewer_registration_verification_resend_prepare', 'viewer_registration_verification_confirm', 'viewer_invitation_issue', 'viewer_registration_activate_verified'] as $forbiddenAuthority) {
        viewer_phase43_assert(!str_contains($antiSource, $forbiddenAuthority), 'Anti-automation service must not become registration/invitation/verification authority: ' . $forbiddenAuthority);
    }
    $verifyBlock = substr($controller, $verifyStart, strpos($controller, 'function cms_viewer_login(): void', $verifyStart) - $verifyStart);
    viewer_phase43_assert(str_contains($verifyBlock, 'viewer_registration_verification_validate($token)') && str_contains($verifyBlock, "\$action === 'authorize'"), 'Scanner-safe verification GET/explicit POST structure must remain present.');
    viewer_phase43_assert(!str_contains($verifyBlock, 'viewer_anti_automation_authorize_submission'), 'Emailed verification GET/POST must remain outside the Phase 4.3 anti-automation gate.');
    viewer_phase43_assert(str_contains($registerBlock, 'viewer_registration_request_begin($email, null, request_client_ip())'), 'Challenge success must still delegate open-origin policy to the existing registration service.');
    viewer_phase43_assert(str_contains($resendBlock, 'viewer_registration_verification_resend_prepare($email)'), 'Challenge success must still delegate resend current-mode/token policy to Phase 4.2 service.');
    $inviteBlock = substr($controller, $inviteStart, $resendStart - $inviteStart);
    viewer_phase43_assert(str_contains($inviteBlock, 'viewer_registration_request_begin($email, $token, request_client_ip())'), 'Invitation registration must retain its original invitation-token admission authority.');
    viewer_phase43_assert(!str_contains($inviteBlock, 'viewer_anti_automation_authorize_submission'), 'Phase 4.3 challenge authority must not replace or reinterpret invitation authority.');

    // Secret material must not be rendered or logged by Phase 4.3 presentation/events.
    foreach (['verification_token_hash', 'invitation secret', 'session_id(', 'viewer_security_secret()'] as $secretPattern) {
        viewer_phase43_assert(!str_contains(strtolower($controller . $javascript), strtolower($secretPattern)), 'Phase 4.3 rendered output must not expose secret material: ' . $secretPattern);
    }
    viewer_phase43_assert(!str_contains($antiSource, "'email' =>") && !str_contains($antiSource, "'token' =>") && !str_contains($antiSource, "'csrf' =>"), 'Anti-automation security-event context must not contain plaintext email, verification token, or CSRF fields.');

    echo "Viewer anti-automation Phase 4.3 tests passed.\n";
}
