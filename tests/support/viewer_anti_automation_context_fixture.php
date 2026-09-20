<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/viewer_anti_automation_context_fixture.php
 * Module Type: Test Fixture
 * Purpose: Isolate ticket policy and actual Viewer controller boundaries without storage or mail.
 * Responsibilities:
 *   - Supply deterministic dependencies and capture prepared presentation without printing authority.
 * Author: Rudolf Klusal
 */
declare(strict_types=1);

namespace Gallery\Tests\ViewerTicketContext {
    /** Represent only the fixture's intercepted controller redirect; no response or session is created. */
    final class Redirect extends \RuntimeException {}

    /**
     * Assert one bounded invariant without serializing private ticket/session state.
     * @param bool $condition Expected invariant.
     * @param string $message Static assertion explanation, never a runtime response or authority value.
     * @return void Count the assertion or throw its safe explanation.
     */
    function check(bool $condition, string $message): void
    {
        $GLOBALS['ticket_context_assertions'] = ($GLOBALS['ticket_context_assertions'] ?? 0) + 1;
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    /**
     * Reset dependency controls without touching the caller's contexts or PHP session.
     * @return void Configure anonymous, enabled, allowed, non-throwing dependencies and empty captures.
     */
    function reset(): void
    {
        $GLOBALS['ticket_context_fixture'] = [
            'enabled' => true, 'available' => true, 'viewer' => null, 'limiter_allowed' => true,
            'limiter_calls' => 0, 'business_calls' => 0, 'business_throws' => false,
            'signature_calls' => 0, 'signature_throw_at' => 0, 'events' => [], 'renders' => [],
        ];
    }

    /**
     * Capture a prepared view model privately, never render or print ticket payloads.
     * @param string $kind Fixed presentation identity chosen by a fixture view.
     * @param array<string,mixed> $model Controller-prepared public presentation fields.
     * @return void Append one private render record.
     */
    function render(string $kind, array $model): void
    {
        $GLOBALS['ticket_context_fixture']['renders'][] = ['kind' => $kind, 'model' => $model];
    }
}

namespace Gallery\Services {
    /**
     * Supply deterministic normalized policy, with only its enabled switch controllable.
     * @return array{anti_automation_enabled:bool,anti_automation_min_form_age_seconds:int,anti_automation_form_lifetime_seconds:int,anti_automation_pow_min_bits:int,anti_automation_pow_max_bits:int} Fixture policy in seconds/bits.
     */
    function viewer_accounts_config(): array
    {
        return ['anti_automation_enabled' => $GLOBALS['ticket_context_fixture']['enabled'],
            'anti_automation_min_form_age_seconds' => 2, 'anti_automation_form_lifetime_seconds' => 600,
            'anti_automation_pow_min_bits' => 10, 'anti_automation_pow_max_bits' => 12];
    }

    /**
     * Sign with a public test-only key, optionally failing a specific ticket-signature operation.
     * @param string $scope Existing domain-separation scope.
     * @param string $value Ticket bytes or nonce, kept private.
     * @return string Deterministic scoped HMAC; the selected fault throws before returning it.
     */
    function viewer_security_fingerprint(string $scope, string $value): string
    {
        if ($scope === 'viewer-anti-automation-ticket-v1') {
            $count = ++$GLOBALS['ticket_context_fixture']['signature_calls'];
            if ($count === $GLOBALS['ticket_context_fixture']['signature_throw_at']) {
                throw new \RuntimeException('Fixture signing interruption.');
            }
        }
        return hash_hmac('sha256', $scope . "\0" . $value, 'public-ticket-context-test-key');
    }

    /**
     * Generate genuine random URL-safe nonces without persistent storage.
     * @param int $entropyBytes Requested native random byte count.
     * @return string Unpadded base64url nonce, never printed by the fixture.
     */
    function security_opaque_token_generate(int $entropyBytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($entropyBytes)), '+/', '-_'), '=');
    }

    /**
     * Validate the test's semantic IP input without reading request globals.
     * @param string $ip Candidate address supplied by the service.
     * @return string Valid address or empty refusal input.
     */
    function request_client_ip_normalize(string $ip): string
    {
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? '' : $ip;
    }

    /**
     * Observe use of the real limiter boundary without querying any database.
     * @param string $bucket Existing anti-automation policy key.
     * @param string $subjectKind IP/subnet subject category.
     * @param string $subject Private synthetic address, never printed.
     * @return array{allowed:bool,attempts:int,retry_after_seconds:int,reason:string} Controlled allow/refuse signal.
     */
    function viewer_rate_limit_consume(string $bucket, string $subjectKind, string $subject): array
    {
        $GLOBALS['ticket_context_fixture']['limiter_calls']++;
        $allowed = $GLOBALS['ticket_context_fixture']['limiter_allowed'];
        return ['allowed' => $allowed, 'attempts' => 1, 'retry_after_seconds' => $allowed ? 0 : 900,
            'reason' => $allowed ? 'ok' : 'locked'];
    }

    /**
     * Capture only the anti-automation service's bounded event envelope.
     * @param string $eventKey Existing event identifier.
     * @param ?int $viewerAccountId Nullable Viewer identity; this gate must keep it null.
     * @param string $outcome Fixed diagnostic category.
     * @param array<string,int|string> $context Bounded action/reason/attempt counts.
     * @return void Retain the record privately without persistence or logging.
     */
    function viewer_security_event_record_best_effort(string $eventKey, ?int $viewerAccountId = null, string $outcome = '', array $context = []): void
    {
        $GLOBALS['ticket_context_fixture']['events'][] = [$eventKey, $viewerAccountId, $outcome, $context];
    }

    /**
     * Keep route-readiness policy controllable without reading configuration.
     * @return bool Whether the fixture permits entry to open registration.
     */
    function viewer_http_open_registration_available(): bool { return $GLOBALS['ticket_context_fixture']['available']; }
    /**
     * Keep resend readiness controllable independently of ticket storage.
     * @return bool Whether the fixture permits entry to verification resend.
     */
    function viewer_http_verification_resend_available(): bool { return $GLOBALS['ticket_context_fixture']['available']; }
    /**
     * Expose only the explicitly selected fixture Viewer to the actual controller.
     * @return ?array{id:int} Existing fixture Viewer or null for anonymous access; never creates identity.
     */
    function current_viewer(): ?array { return $GLOBALS['ticket_context_fixture']['viewer']; }
    /**
     * Provide a fixed semantic client address for controller-to-service calls.
     * @return string Synthetic controller-resolved client address; no request or network lookup.
     */
    function request_client_ip(): string { return '192.0.2.77'; }
    /**
     * Supply the isolated Viewer/pre-auth token to controller form preparation.
     * @return string Public test-only CSRF fixture value, kept out of test output.
     */
    function viewer_csrf_token(): string { return 'fixture-viewer-csrf'; }

    /**
     * Verify the isolated Viewer/pre-auth token, never administrator CSRF authority.
     * @param string $token Submitted fixture token.
     * @return bool Whether this fixture's exact Viewer token was supplied.
     */
    function viewer_csrf_verify(string $token): bool { return hash_equals(viewer_csrf_token(), $token); }

    /**
     * Perform only the real controller's required local email syntax distinction.
     * @param string $email Synthetic submitted address.
     * @return ?string Normalized valid address or null.
     */
    function viewer_email_normalize(string $email): ?string
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : strtolower($email);
    }

    /**
     * Count registration entry and optionally interrupt downstream work.
     * @param string $email Syntactically valid synthetic address, never mailed.
     * @param ?string $invitationToken Existing invitation argument; open registration supplies null.
     * @param string $clientIp Synthetic controller-resolved IP.
     * @return array{accepted:bool} Refusal without any registration, verification or mail authority.
     */
    function viewer_registration_request_begin(string $email, ?string $invitationToken, string $clientIp): array
    {
        $GLOBALS['ticket_context_fixture']['business_calls']++;
        if ($GLOBALS['ticket_context_fixture']['business_throws']) {
            throw new \RuntimeException('Fixture downstream interruption.');
        }
        return ['accepted' => false];
    }

    /**
     * Count resend entry and optionally interrupt it without staging verification or sending mail.
     * @param string $email Valid synthetic address.
     * @return array{mail_eligible:bool} Always ineligible for mail transport.
     */
    function viewer_registration_verification_resend_prepare(string $email): array
    {
        $GLOBALS['ticket_context_fixture']['business_calls']++;
        if ($GLOBALS['ticket_context_fixture']['business_throws']) {
            throw new \RuntimeException('Fixture downstream interruption.');
        }
        return ['mail_eligible' => false];
    }

    /**
     * Use controller fallback wording without loading installation language configuration.
     * @param string $key Translation identifier.
     * @param string $fallback Default public wording.
     * @param array<string,int|string> $replace Named presentation substitutions.
     * @return string Local fallback with ordinary substitutions.
     */
    function t(string $key, string $fallback = '', array $replace = []): string
    {
        foreach ($replace as $name => $value) {
            $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
        }
        return $fallback !== '' ? $fallback : $key;
    }
}

namespace Gallery\Core {
    /**
     * Return an inert asset URL for actual controller challenge preparation.
     * @param string $path Existing first-party asset path, never fetched.
     * @return string Inert presentation URL.
     */
    function asset_url(string $path): string { return '/fixture/' . $path; }
    /**
     * Read only the method selected by this in-memory controller fixture.
     * @return string Fixture-selected HTTP method, without starting a web server.
     */
    function request_method(): string { return (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'); }
    /**
     * Build inert local route text for captured presentation.
     * @param string $page Application page identifier.
     * @param array<string,int|string> $params Unused fixture query options; no transport is performed.
     * @return string Inert local route.
     */
    function url_for(string $page, array $params = []): string { return '/fixture/' . $page; }
    /**
     * Intercept an existing-Viewer redirect before ticket or business work.
     * @param string $url Inert local destination; never visited or printed.
     * @return never Signal the redirect to the isolated assertion.
     */
    function redirect_to(string $url): never { throw new \Gallery\Tests\ViewerTicketContext\Redirect('Fixture redirect.'); }
}

namespace Gallery\Controllers {
    /**
     * Leave CLI headers untouched while the actual controller emits its no-store policy.
     * @return void Keep the CLI fixture's header cleanup inert; real controller policy still executes.
     */
    function clear_response_cache_headers(): void {}
}

namespace Gallery\Views {
    /**
     * Capture form presentation from the real controller without disclosing its signed ticket.
     * @param array{ticket:string,honeypot_field:string,issued_at:int,expires_at:int} $state Browser-safe signed form state.
     * @return string Inert markup placeholder for parent view models.
     */
    function view_viewer_anti_automation_form_fields(array $state): string
    {
        \Gallery\Tests\ViewerTicketContext\render('form', $state);
        return '<fixture-form>';
    }
    /**
     * Capture the presentation-only Viewer CSRF field boundary.
     * @param string $token Private fixture token supplied by the controller.
     * @return string Inert markup without the token value.
     */
    function view_viewer_csrf_field(string $token): string { return '<fixture-csrf>'; }
    /**
     * Capture the controller's register presentation without evaluating domain policy.
     * @param array<string,mixed> $model Prepared controller presentation fields; kept private.
     * @return void Record one register rendering decision for isolated assertions.
     */
    function view_render_viewer_register(array $model): void
    {
        \Gallery\Tests\ViewerTicketContext\render('register', $model);
    }

    /**
     * Capture the controller's resend presentation without evaluating domain policy.
     * @param array<string,mixed> $model Prepared controller presentation fields; kept private.
     * @return void Record one resend rendering decision for isolated assertions.
     */
    function view_render_viewer_resend_verification(array $model): void
    {
        \Gallery\Tests\ViewerTicketContext\render('resend', $model);
    }

    /**
     * Capture the controller's challenge presentation without evaluating domain policy.
     * @param array<string,mixed> $model Prepared controller presentation fields; kept private.
     * @return void Record one challenge rendering decision for isolated assertions.
     */
    function view_render_viewer_anti_automation_challenge(array $model): void
    {
        \Gallery\Tests\ViewerTicketContext\render('challenge', $model);
    }

    /**
     * Capture the controller's unavailable presentation without evaluating domain policy.
     * @return void Record one unavailable rendering decision for isolated assertions.
     */
    function view_render_viewer_unavailable(): void
    {
        \Gallery\Tests\ViewerTicketContext\render('unavailable', []);
    }

    /**
     * Capture the controller's invalid request presentation without evaluating domain policy.
     * @return void Record one invalid_request rendering decision for isolated assertions.
     */
    function view_render_viewer_invalid_request(): void
    {
        \Gallery\Tests\ViewerTicketContext\render('invalid_request', []);
    }

}
