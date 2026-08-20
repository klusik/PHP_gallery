<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/viewer_verification_resend_phase42_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Protects the Phase 4.2 first-party verification-resend and recovery boundary.
 *
 * Responsibilities:
 *   - Verify resend route availability, routing, CSRF wiring, and generic public output
 *   - Exercise the existing resend-specific limiter and service-owned child-token lifecycle
 *   - Prove the historical Phase 4.1 primary token survives successful and failed resend attempts
 *   - Prove primary and resent authorities can coexist until the first explicit confirmation wins
 *   - Verify current registration-mode revalidation, invitation-backed resend, and anti-resurrection behavior
 *   - Protect Viewer/Admin principal separation and the zero-third-party security requirement
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This focused fixture does not require a live database or mail server.
 *
 * Last Updated:
 *   2026-08-20
 */

declare(strict_types=1);

namespace Gallery\Tests\Phase42 {
    /** Minimal mutable PDO-like fixture for the Phase 4.2 registration authority lifecycle. */
    final class ResendPdo
    {
        /** @var ?array<string,mixed> Authoritative staged registration row. */
        public ?array $request = null;

        /** @var array<int,array<string,mixed>> Child resend authority rows keyed by id. */
        public array $children = [];

        /** @var array<int,array<string,mixed>> Invitation rows keyed by id. */
        public array $invitations = [];

        /** @var array<string,int> Durable accounts keyed by normalized email. */
        public array $accounts = [];

        /** Whether a synthetic transaction is active. */
        public bool $transaction = false;

        /** Synthetic singleton registration count. */
        public int $activeRequestCount = 1;

        /** Next child token id. */
        public int $nextChildId = 100;

        /** Last synthetic insert id. */
        public int $lastInsertIdValue = 0;

        /** @var ?array<string,mixed> Transaction snapshot for rollback. */
        private ?array $snapshot = null;

        /** Begin one synthetic transaction with rollback state. */
        public function beginTransaction(): bool
        {
            if ($this->transaction) {
                return false;
            }
            $this->transaction = true;
            $this->snapshot = [
                'request' => $this->request,
                'children' => $this->children,
                'invitations' => $this->invitations,
                'accounts' => $this->accounts,
                'activeRequestCount' => $this->activeRequestCount,
                'nextChildId' => $this->nextChildId,
                'lastInsertIdValue' => $this->lastInsertIdValue,
            ];
            return true;
        }

        /** Return whether one synthetic transaction is active. */
        public function inTransaction(): bool
        {
            return $this->transaction;
        }

        /** Commit one synthetic transaction. */
        public function commit(): bool
        {
            $this->transaction = false;
            $this->snapshot = null;
            return true;
        }

        /** Roll back one synthetic transaction. */
        public function rollBack(): bool
        {
            if ($this->snapshot !== null) {
                $this->request = $this->snapshot['request'];
                $this->children = $this->snapshot['children'];
                $this->invitations = $this->snapshot['invitations'];
                $this->accounts = $this->snapshot['accounts'];
                $this->activeRequestCount = $this->snapshot['activeRequestCount'];
                $this->nextChildId = $this->snapshot['nextChildId'];
                $this->lastInsertIdValue = $this->snapshot['lastInsertIdValue'];
            }
            $this->transaction = false;
            $this->snapshot = null;
            return true;
        }

        /** Prepare one SQL contract used by the focused service fixture. */
        public function prepare(string $sql): ResendStatement
        {
            return new ResendStatement($this, $sql);
        }

        /** Return the latest synthetic auto-increment id. */
        public function lastInsertId(): string
        {
            return (string) $this->lastInsertIdValue;
        }
    }

    /** Minimal statement interpreter for Phase 4.2 service SQL. */
    final class ResendStatement
    {
        /** @var mixed Latest scalar result. */
        private mixed $scalar = false;

        /** @var ?array<string,mixed> Latest row result. */
        private ?array $row = null;

        /** Number of affected synthetic rows. */
        private int $rowCount = 0;

        /** Store the fixture and normalized SQL. */
        public function __construct(private ResendPdo $pdo, private string $sql)
        {
            $this->sql = preg_replace('/\s+/', ' ', trim($this->sql)) ?? trim($this->sql);
        }

        /**
         * Execute one SQL shape used by the registration resend implementation.
         *
         * @param array<int,mixed> $parameters Bound values.
         */
        public function execute(array $parameters = []): bool
        {
            $this->scalar = false;
            $this->row = null;
            $this->rowCount = 0;

            if (str_starts_with($this->sql, 'INSERT INTO viewer_registration_state ')) {
                return true;
            }
            if (str_starts_with($this->sql, 'SELECT active_request_count FROM viewer_registration_state ')) {
                $this->scalar = $this->pdo->activeRequestCount;
                return true;
            }
            if (str_contains($this->sql, 'FROM viewer_registration_requests WHERE normalized_email = ?')) {
                $candidate = (string) ($parameters[0] ?? '');
                if ($this->pdo->request !== null
                    && (string) ($this->pdo->request['normalized_email'] ?? '') === $candidate) {
                    $this->row = $this->pdo->request;
                }
                return true;
            }
            if (str_contains($this->sql, 'FROM viewer_registration_requests WHERE verification_token_hash = ?')) {
                $hash = (string) ($parameters[0] ?? '');
                if ($this->pdo->request !== null
                    && hash_equals((string) ($this->pdo->request['verification_token_hash'] ?? ''), $hash)) {
                    $this->row = $this->pdo->request;
                }
                return true;
            }
            if (str_starts_with($this->sql, 'SELECT * FROM viewer_invitations WHERE id = ?')) {
                $id = (int) ($parameters[0] ?? 0);
                $this->row = $this->pdo->invitations[$id] ?? null;
                return true;
            }
            if (str_starts_with($this->sql, 'SELECT id FROM viewer_accounts WHERE normalized_email = ?')) {
                $email = (string) ($parameters[0] ?? '');
                $this->scalar = $this->pdo->accounts[$email] ?? false;
                return true;
            }
            if (str_starts_with($this->sql, 'DELETE FROM viewer_registration_verification_tokens WHERE viewer_registration_request_id = ? AND expires_at < ?')) {
                $requestId = (int) ($parameters[0] ?? 0);
                $cutoff = strtotime((string) ($parameters[1] ?? '')) ?: 0;
                foreach ($this->pdo->children as $id => $child) {
                    $expiry = strtotime((string) ($child['expires_at'] ?? '')) ?: 0;
                    if ((int) ($child['viewer_registration_request_id'] ?? 0) === $requestId && $expiry < $cutoff) {
                        unset($this->pdo->children[$id]);
                        $this->rowCount++;
                    }
                }
                return true;
            }
            if (str_starts_with($this->sql, 'SELECT COUNT(*) FROM viewer_registration_verification_tokens WHERE viewer_registration_request_id = ?')) {
                $requestId = (int) ($parameters[0] ?? 0);
                $this->scalar = count(array_filter(
                    $this->pdo->children,
                    static fn (array $child): bool => (int) ($child['viewer_registration_request_id'] ?? 0) === $requestId
                ));
                return true;
            }
            if (str_starts_with($this->sql, 'INSERT INTO viewer_registration_verification_tokens ')) {
                $id = $this->pdo->nextChildId++;
                $this->pdo->lastInsertIdValue = $id;
                $this->pdo->children[$id] = [
                    'id' => $id,
                    'viewer_registration_request_id' => (int) ($parameters[0] ?? 0),
                    'token_hash' => (string) ($parameters[1] ?? ''),
                    'expires_at' => (string) ($parameters[2] ?? ''),
                    'created_at' => (string) ($parameters[3] ?? ''),
                    'sent_at' => null,
                ];
                $this->rowCount = 1;
                return true;
            }
            if (str_contains($this->sql, 'FROM viewer_registration_verification_tokens vrvt INNER JOIN viewer_registration_requests vrr')
                && str_contains($this->sql, 'WHERE vrr.id = ? AND vrvt.id = ?')) {
                $requestId = (int) ($parameters[0] ?? 0);
                $authorityId = (int) ($parameters[1] ?? 0);
                $child = $this->pdo->children[$authorityId] ?? null;
                if ($this->pdo->request !== null && $child !== null
                    && (int) ($this->pdo->request['id'] ?? 0) === $requestId
                    && (int) ($child['viewer_registration_request_id'] ?? 0) === $requestId) {
                    $this->row = array_merge($this->pdo->request, [
                        'resend_token_id' => $authorityId,
                        'resend_token_expires_at' => $child['expires_at'],
                        'resend_token_sent_at' => $child['sent_at'],
                    ]);
                }
                return true;
            }
            if (str_contains($this->sql, 'FROM viewer_registration_verification_tokens vrvt INNER JOIN viewer_registration_requests vrr')
                && str_contains($this->sql, 'WHERE vrvt.token_hash = ?')) {
                $hash = (string) ($parameters[0] ?? '');
                foreach ($this->pdo->children as $authorityId => $child) {
                    if (!hash_equals((string) ($child['token_hash'] ?? ''), $hash)) {
                        continue;
                    }
                    if ($this->pdo->request !== null
                        && (int) ($this->pdo->request['id'] ?? 0) === (int) ($child['viewer_registration_request_id'] ?? 0)) {
                        $this->row = array_merge($this->pdo->request, [
                            'resend_token_id' => $authorityId,
                            'resend_token_expires_at' => $child['expires_at'],
                            'resend_token_sent_at' => $child['sent_at'],
                        ]);
                    }
                    break;
                }
                return true;
            }
            if (str_starts_with($this->sql, 'DELETE FROM viewer_registration_verification_tokens WHERE id = ? AND viewer_registration_request_id = ? AND sent_at IS NULL')) {
                $authorityId = (int) ($parameters[0] ?? 0);
                $requestId = (int) ($parameters[1] ?? 0);
                $child = $this->pdo->children[$authorityId] ?? null;
                if ($child !== null
                    && (int) ($child['viewer_registration_request_id'] ?? 0) === $requestId
                    && empty($child['sent_at'])) {
                    unset($this->pdo->children[$authorityId]);
                    $this->rowCount = 1;
                }
                return true;
            }
            if (str_starts_with($this->sql, 'UPDATE viewer_registration_verification_tokens SET sent_at = ?')) {
                $sentAt = (string) ($parameters[0] ?? '');
                $authorityId = (int) ($parameters[1] ?? 0);
                $requestId = (int) ($parameters[2] ?? 0);
                $cutoff = strtotime((string) ($parameters[3] ?? '')) ?: PHP_INT_MAX;
                $child = $this->pdo->children[$authorityId] ?? null;
                $expiry = $child ? (strtotime((string) ($child['expires_at'] ?? '')) ?: 0) : 0;
                if ($child !== null
                    && (int) ($child['viewer_registration_request_id'] ?? 0) === $requestId
                    && empty($child['sent_at'])
                    && $expiry >= $cutoff) {
                    $this->pdo->children[$authorityId]['sent_at'] = $sentAt;
                    $this->rowCount = 1;
                }
                return true;
            }
            if (str_starts_with($this->sql, 'UPDATE viewer_registration_requests SET verification_send_count = verification_send_count + 1')) {
                $requestId = (int) ($parameters[2] ?? 0);
                if ($this->pdo->request !== null
                    && (int) ($this->pdo->request['id'] ?? 0) === $requestId
                    && (string) ($this->pdo->request['status'] ?? '') === (string) ($parameters[3] ?? '')) {
                    $this->pdo->request['verification_send_count'] = (int) ($this->pdo->request['verification_send_count'] ?? 0) + 1;
                    $this->pdo->request['verification_last_sent_at'] = (string) ($parameters[0] ?? '');
                    $this->pdo->request['updated_at'] = (string) ($parameters[1] ?? '');
                    $this->rowCount = 1;
                }
                return true;
            }
            if (str_starts_with($this->sql, 'UPDATE viewer_registration_requests SET status = ?')) {
                $requestId = (int) ($parameters[5] ?? 0);
                if ($this->pdo->request !== null
                    && (int) ($this->pdo->request['id'] ?? 0) === $requestId
                    && (string) ($this->pdo->request['status'] ?? '') === (string) ($parameters[6] ?? '')
                    && empty($this->pdo->request['verification_token_consumed_at'])
                    && empty($this->pdo->request['cancelled_at'])) {
                    $this->pdo->request['status'] = (string) ($parameters[0] ?? '');
                    $this->pdo->request['verification_token_consumed_at'] = (string) ($parameters[1] ?? '');
                    $this->pdo->request['verified_at'] = (string) ($parameters[2] ?? '');
                    $this->pdo->request['expires_at'] = (string) ($parameters[3] ?? '');
                    $this->pdo->request['updated_at'] = (string) ($parameters[4] ?? '');
                    $this->rowCount = 1;
                }
                return true;
            }
            if (str_starts_with($this->sql, 'DELETE FROM viewer_registration_verification_tokens WHERE viewer_registration_request_id = ?')) {
                $requestId = (int) ($parameters[0] ?? 0);
                foreach ($this->pdo->children as $id => $child) {
                    if ((int) ($child['viewer_registration_request_id'] ?? 0) === $requestId) {
                        unset($this->pdo->children[$id]);
                        $this->rowCount++;
                    }
                }
                return true;
            }

            throw new \RuntimeException('Unexpected Phase 4.2 fixture SQL: ' . $this->sql);
        }

        /** Return one synthetic row or false. */
        public function fetch(): mixed
        {
            return $this->row ?? false;
        }

        /** Return one synthetic scalar. */
        public function fetchColumn(): mixed
        {
            return $this->scalar;
        }

        /** Return the synthetic affected-row count. */
        public function rowCount(): int
        {
            return $this->rowCount;
        }
    }
}

namespace Gallery\Core {
    /** Return the mutable Phase 4.2 PDO fixture. */
    function db(): object
    {
        return $GLOBALS['viewer_phase42_pdo'];
    }

    /** Return a current SQL timestamp for lifecycle comparisons. */
    function now_sql(): string
    {
        return date('Y-m-d H:i:s');
    }
}

namespace Gallery\Services {
    /** Return whether the synthetic master/policy combination exposes Viewer Accounts. */
    function viewer_accounts_enabled(): bool
    {
        return !empty($GLOBALS['viewer_phase42_master'])
            && in_array((string) ($GLOBALS['viewer_phase42_mode'] ?? 'disabled'), ['invite_only', 'open'], true);
    }

    /** Return the mutable synthetic registration mode. */
    function viewer_registration_mode(): string
    {
        if (empty($GLOBALS['viewer_phase42_master'])) {
            return 'disabled';
        }
        return (string) ($GLOBALS['viewer_phase42_mode'] ?? 'disabled');
    }

    /** Return the mutable secure-transport state. */
    function viewer_security_transport_allowed(): bool
    {
        return !empty($GLOBALS['viewer_phase42_transport']);
    }

    /** Return the mutable auth-storage state. */
    function viewer_auth_storage_available(): bool
    {
        return !empty($GLOBALS['viewer_phase42_auth_storage']);
    }

    /** Return aggregate schema availability for the focused fixture. */
    function schema_inspection_feature(string $feature, array $requirements): array
    {
        return ['state' => !empty($GLOBALS['viewer_phase42_registration_storage']) ? 'available' : 'missing'];
    }

    /** Return one available table requirement fixture. */
    function schema_inspection_table(string $table): array
    {
        return ['state' => 'available'];
    }

    /** Return one available column requirement fixture. */
    function schema_inspection_column(string $table, string $column): array
    {
        return ['state' => 'available'];
    }

    /** Interpret the focused aggregate schema fixture. */
    function schema_inspection_is_available(array $status): bool
    {
        return ($status['state'] ?? '') === 'available';
    }

    /** Return only the registration configuration used by the focused lifecycle. */
    function viewer_accounts_config(): array
    {
        return [
            'max_pending_registration_requests' => 250,
            'registration_request_lifetime_minutes' => 1440,
            'verification_token_lifetime_minutes' => 60,
            'verified_registration_lifetime_minutes' => 60,
            'registration_activation_lifetime_minutes' => 30,
            'invitation_lifetime_days' => 7,
        ];
    }

    /** Normalize one synthetic email exactly enough for focused registration tests. */
    function viewer_email_normalize(string $email): ?string
    {
        $email = strtolower(trim($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /** Fingerprint one normalized email without exposing it. */
    function viewer_email_fingerprint(string $email): string
    {
        $normalized = viewer_email_normalize($email);
        return $normalized === null ? '' : hash('sha256', 'email:' . $normalized);
    }

    /** Produce one deterministic synthetic privacy/security fingerprint. */
    function viewer_security_fingerprint(string $purpose, string $value): string
    {
        return hash('sha256', $purpose . "\0" . $value);
    }

    /** Generate deterministic opaque capabilities from the fixture queue. */
    function security_opaque_token_generate(int $bytes = 32): string
    {
        $token = array_shift($GLOBALS['viewer_phase42_token_queue']);
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Phase 4.2 token fixture queue is empty.');
        }
        return $token;
    }

    /** Hash one synthetic authority capability. */
    function security_authority_token_hash(string $token): string
    {
        return hash('sha256', 'authority:' . $token);
    }

    /** Consume one synthetic resend limiter and record the exact bucket used. */
    function viewer_rate_limit_consume(string $bucket, string $kind, string $subject): array
    {
        $GLOBALS['viewer_phase42_rate_calls'][] = [$bucket, $kind, $subject];
        if ($bucket === 'viewer_resend_verification_identifier' && empty($GLOBALS['viewer_phase42_resend_limit_allowed'])) {
            return ['allowed' => false, 'reason' => 'locked', 'retry_after_seconds' => 900];
        }
        return ['allowed' => true, 'reason' => 'ok', 'retry_after_seconds' => 0];
    }
}

namespace {
    use Gallery\Tests\Phase42\ResendPdo;
    use function Gallery\Services\security_authority_token_hash;
    use function Gallery\Services\viewer_http_invite_registration_available;
    use function Gallery\Services\viewer_http_open_registration_available;
    use function Gallery\Services\viewer_http_verification_resend_available;
    use function Gallery\Services\viewer_registration_activation_clear;
    use function Gallery\Services\viewer_registration_verification_confirm;
    use function Gallery\Services\viewer_registration_verification_resend_deliver_locked;
    use function Gallery\Services\viewer_registration_verification_resend_prepare;
    use function Gallery\Services\viewer_registration_verification_validate;

    $GLOBALS['viewer_phase42_master'] = true;
    $GLOBALS['viewer_phase42_mode'] = 'open';
    $GLOBALS['viewer_phase42_transport'] = true;
    $GLOBALS['viewer_phase42_auth_storage'] = true;
    $GLOBALS['viewer_phase42_registration_storage'] = true;
    $GLOBALS['viewer_phase42_resend_limit_allowed'] = true;
    $GLOBALS['viewer_phase42_rate_calls'] = [];
    $GLOBALS['viewer_phase42_token_queue'] = [];
    $GLOBALS['viewer_phase42_pdo'] = new ResendPdo();
    $_SESSION = [];

    require_once __DIR__ . '/../app/services/viewer_registration.php';
    require_once __DIR__ . '/../app/services/viewer_http.php';

    /** Throw when one Phase 4.2 expectation fails. */
    function viewer_phase42_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    /** Return one live synthetic staged request with historical primary token A. */
    function viewer_phase42_request(string $primaryToken, ?int $invitationId = null): array
    {
        $now = time();
        return [
            'id' => 42,
            'email' => 'Person@example.com',
            'normalized_email' => 'person@example.com',
            'email_fingerprint' => hash('sha256', 'email:person@example.com'),
            'viewer_invitation_id' => $invitationId,
            'status' => 'pending_verification',
            'request_ip_hash' => null,
            'verification_token_hash' => security_authority_token_hash($primaryToken),
            'verification_token_expires_at' => date('Y-m-d H:i:s', $now + 1800),
            'verification_token_consumed_at' => null,
            'verification_send_count' => 1,
            'verification_last_sent_at' => date('Y-m-d H:i:s', $now - 120),
            'expires_at' => date('Y-m-d H:i:s', $now + 7200),
            'verified_at' => null,
            'cancelled_at' => null,
            'created_at' => date('Y-m-d H:i:s', $now - 600),
            'updated_at' => date('Y-m-d H:i:s', $now - 120),
        ];
    }

    /** Reset the synthetic database around one staged registration scenario. */
    function viewer_phase42_reset(string $primaryToken = 'token-A', ?int $invitationId = null): ResendPdo
    {
        $pdo = new ResendPdo();
        $pdo->request = viewer_phase42_request($primaryToken, $invitationId);
        if ($invitationId !== null) {
            $pdo->invitations[$invitationId] = [
                'id' => $invitationId,
                'target_email_fingerprint' => hash('sha256', 'email:person@example.com'),
                'expires_at' => date('Y-m-d H:i:s', time() + 7200),
                'claimed_at' => date('Y-m-d H:i:s', time() - 600),
                'revoked_at' => null,
            ];
        }
        $GLOBALS['viewer_phase42_pdo'] = $pdo;
        $GLOBALS['viewer_phase42_rate_calls'] = [];
        $GLOBALS['viewer_phase42_resend_limit_allowed'] = true;
        $_SESSION = [];
        return $pdo;
    }

    /** Extract one named PHP function source for static HTTP-boundary assertions. */
    function viewer_phase42_function_source(string $source, string $function): string
    {
        $needle = 'function ' . $function . '(';
        $start = strpos($source, $needle);
        if ($start === false) {
            throw new RuntimeException('Missing function for Phase 4.2 source inspection: ' . $function);
        }
        $brace = strpos($source, '{', $start);
        if ($brace === false) {
            throw new RuntimeException('Missing function body for Phase 4.2 source inspection: ' . $function);
        }
        $depth = 0;
        $length = strlen($source);
        for ($i = $brace; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start + 1);
                }
            }
        }
        throw new RuntimeException('Unterminated function body for Phase 4.2 source inspection: ' . $function);
    }

    // Route availability uses the shared master/policy/transport/auth/registration-storage boundary.
    $GLOBALS['viewer_phase42_master'] = false;
    $GLOBALS['viewer_phase42_mode'] = 'open';
    viewer_phase42_assert(!viewer_http_verification_resend_available(), 'Master OFF must make resend unavailable.');
    $GLOBALS['viewer_phase42_master'] = true;
    $GLOBALS['viewer_phase42_mode'] = 'disabled';
    viewer_phase42_assert(!viewer_http_verification_resend_available(), 'Disabled registration must make resend unavailable.');
    $GLOBALS['viewer_phase42_mode'] = 'invite_only';
    viewer_phase42_assert(viewer_http_verification_resend_available(), 'Invite-only mode must expose the generic resend route.');
    $GLOBALS['viewer_phase42_mode'] = 'open';
    viewer_phase42_assert(viewer_http_verification_resend_available(), 'Open mode must expose the generic resend route.');

    // Successful resend creates B without rotating the previously mailed Phase 4.1 authority A.
    $pdo = viewer_phase42_reset('token-A');
    $oldHash = (string) $pdo->request['verification_token_hash'];
    $oldExpiry = (string) $pdo->request['verification_token_expires_at'];
    $GLOBALS['viewer_phase42_token_queue'] = ['token-B'];
    $prepared = viewer_registration_verification_resend_prepare('PERSON@example.com');
    viewer_phase42_assert(!empty($prepared['mail_eligible']) && ($prepared['verification_token'] ?? '') === 'token-B', 'Eligible resend must prepare a fresh plaintext capability only for immediate mail orchestration.');
    viewer_phase42_assert($pdo->request['verification_token_hash'] === $oldHash, 'Resend must not rotate primary token A hash.');
    viewer_phase42_assert($pdo->request['verification_token_expires_at'] === $oldExpiry, 'Resend must not rotate primary token A expiry.');
    viewer_phase42_assert(($prepared['recipient_email'] ?? '') === 'person@example.com' && empty($prepared['invitation_backed']), 'Recipient/origin must come from authoritative staged state.');
    viewer_phase42_assert(($GLOBALS['viewer_phase42_rate_calls'][0][0] ?? '') === 'viewer_resend_verification_identifier', 'Resend must consume the existing viewer_resend_verification_identifier limiter.');
    viewer_phase42_assert(viewer_registration_verification_validate('token-B') === null, 'Prepared child B must remain unusable until successful mail handoff is recorded.');

    $delivery = viewer_registration_verification_resend_deliver_locked(
        (int) $prepared['request_id'],
        (int) $prepared['verification_authority_id'],
        static fn (): array => ['sent' => true]
    );
    viewer_phase42_assert(!empty($delivery['sent']), 'Successful mail handoff must mark child B sent.');
    viewer_phase42_assert(viewer_registration_verification_validate('token-A') !== null, 'Historical token A must remain valid after successful resend.');
    viewer_phase42_assert(viewer_registration_verification_validate('token-B') !== null, 'Successfully mailed token B must become a valid sibling authority.');
    viewer_phase42_assert('token-A' !== 'token-B', 'A and B must be distinct capabilities.');

    // Mail failure removes only unsent B and preserves the original primary authority and pending state.
    $pdo = viewer_phase42_reset('token-A');
    $GLOBALS['viewer_phase42_token_queue'] = ['token-B-fail'];
    $prepared = viewer_registration_verification_resend_prepare('person@example.com');
    $delivery = viewer_registration_verification_resend_deliver_locked(
        (int) $prepared['request_id'],
        (int) $prepared['verification_authority_id'],
        static fn (): array => ['sent' => false, 'reason' => 'synthetic_failure']
    );
    viewer_phase42_assert(empty($delivery['sent']), 'Synthetic mail failure must report no resend handoff.');
    viewer_phase42_assert(viewer_registration_verification_validate('token-A') !== null, 'Mail failure must leave token A usable.');
    viewer_phase42_assert(viewer_registration_verification_validate('token-B-fail') === null, 'Failed unsent B must not become usable.');
    viewer_phase42_assert(($pdo->request['status'] ?? '') === 'pending_verification', 'Mail failure must leave the staged request pending.');
    viewer_phase42_assert(!isset($_SESSION['viewer_auth']) && !isset($_SESSION['user_id']), 'Resend/mail failure must establish neither Viewer nor Admin identity.');

    // Primary A wins: request becomes email_verified and every B child is immediately closed.
    $pdo = viewer_phase42_reset('token-A');
    $GLOBALS['viewer_phase42_token_queue'] = ['token-B'];
    $prepared = viewer_registration_verification_resend_prepare('person@example.com');
    viewer_registration_verification_resend_deliver_locked(
        (int) $prepared['request_id'],
        (int) $prepared['verification_authority_id'],
        static fn (): array => ['sent' => true]
    );
    $confirmedA = viewer_registration_verification_confirm('token-A');
    viewer_phase42_assert($confirmedA !== null && ($pdo->request['status'] ?? '') === 'email_verified', 'Confirming A must verify the single staged request.');
    viewer_phase42_assert($pdo->children === [], 'Confirming A must delete every child sibling authority.');
    viewer_phase42_assert(viewer_registration_verification_validate('token-B') === null, 'B must be unusable after A wins.');
    viewer_phase42_assert(!isset($_SESSION['viewer_auth']) && !isset($_SESSION['user_id']), 'Verification confirmation may establish activation authority but not Viewer/Admin identity.');
    viewer_registration_activation_clear();

    // Resent B wins independently: primary A becomes consumed at request level and can no longer confirm.
    $pdo = viewer_phase42_reset('token-A');
    $GLOBALS['viewer_phase42_token_queue'] = ['token-B'];
    $prepared = viewer_registration_verification_resend_prepare('person@example.com');
    viewer_registration_verification_resend_deliver_locked(
        (int) $prepared['request_id'],
        (int) $prepared['verification_authority_id'],
        static fn (): array => ['sent' => true]
    );
    $confirmedB = viewer_registration_verification_confirm('token-B');
    viewer_phase42_assert($confirmedB !== null && ($pdo->request['status'] ?? '') === 'email_verified', 'Confirming B must verify the same staged request.');
    viewer_phase42_assert(!empty($pdo->request['verification_token_consumed_at']), 'B confirmation must close request-level primary authority.');
    viewer_phase42_assert(viewer_registration_verification_validate('token-A') === null, 'A must be unusable after B wins.');
    viewer_registration_activation_clear();

    // Historical pre-migration Phase 4.1 primary authority validates and confirms without any child row.
    $pdo = viewer_phase42_reset('phase41-primary');
    viewer_phase42_assert($pdo->children === [] && viewer_registration_verification_validate('phase41-primary') !== null, 'Existing Phase 4.1 primary links must validate after the migration.');
    viewer_phase42_assert(viewer_registration_verification_confirm('phase41-primary') !== null, 'Existing Phase 4.1 primary links must confirm normally after the migration.');
    viewer_registration_activation_clear();

    // Current-mode revalidation blocks open-origin resend after open -> invite_only, including a prepared-but-not-yet-sent race.
    $pdo = viewer_phase42_reset('token-A');
    $GLOBALS['viewer_phase42_mode'] = 'open';
    $GLOBALS['viewer_phase42_token_queue'] = ['token-B-race'];
    $prepared = viewer_registration_verification_resend_prepare('person@example.com');
    $GLOBALS['viewer_phase42_mode'] = 'invite_only';
    $transportCalled = false;
    $delivery = viewer_registration_verification_resend_deliver_locked(
        (int) $prepared['request_id'],
        (int) $prepared['verification_authority_id'],
        static function () use (&$transportCalled): array {
            $transportCalled = true;
            return ['sent' => true];
        }
    );
    viewer_phase42_assert(empty($delivery['sent']) && !$transportCalled, 'Restrictive mode revalidation must suppress an already-prepared open-origin resend before transport.');
    $GLOBALS['viewer_phase42_token_queue'] = ['token-B-blocked'];
    $blockedInviteOnly = viewer_registration_verification_resend_prepare('person@example.com');
    viewer_phase42_assert(empty($blockedInviteOnly['mail_eligible']), 'Open-origin resend must be forbidden after open -> invite_only.');
    $GLOBALS['viewer_phase42_mode'] = 'disabled';
    viewer_phase42_assert(!viewer_http_verification_resend_available(), 'Disabled mode must remove the resend route.');
    $blockedDisabled = viewer_registration_verification_resend_prepare('person@example.com');
    viewer_phase42_assert(empty($blockedDisabled['mail_eligible']), 'Open-origin resend service authorization must also fail after open -> disabled.');

    // Invitation-backed staged requests may resend in invite_only and open while invitation authority remains live.
    $GLOBALS['viewer_phase42_mode'] = 'invite_only';
    $pdo = viewer_phase42_reset('invite-A', 7);
    $GLOBALS['viewer_phase42_token_queue'] = ['invite-B'];
    $inviteOnlyPrepared = viewer_registration_verification_resend_prepare('person@example.com');
    viewer_phase42_assert(!empty($inviteOnlyPrepared['mail_eligible']) && !empty($inviteOnlyPrepared['invitation_backed']), 'Invitation-backed resend must work in invite_only.');
    $GLOBALS['viewer_phase42_mode'] = 'open';
    $GLOBALS['viewer_phase42_token_queue'] = ['invite-C'];
    $openInvitePrepared = viewer_registration_verification_resend_prepare('person@example.com');
    viewer_phase42_assert(!empty($openInvitePrepared['mail_eligible']) && !empty($openInvitePrepared['invitation_backed']), 'Invitation-backed resend must also work in open mode.');
    viewer_phase42_assert(viewer_http_invite_registration_available() && viewer_http_open_registration_available(), 'Open mode must preserve both invitation and open registration HTTP boundaries.');

    // Representative ineligible registration/account states retain one identical public result code.
    $GLOBALS['viewer_phase42_mode'] = 'open';
    $pdo = viewer_phase42_reset('unknown-A');
    $pdo->request = null;
    $unknown = viewer_registration_verification_resend_prepare('person@example.com');

    $pdo = viewer_phase42_reset('account-A');
    $pdo->accounts['person@example.com'] = 99;
    $existingAccount = viewer_registration_verification_resend_prepare('person@example.com');

    $pdo = viewer_phase42_reset('verified-A');
    $pdo->request['status'] = 'email_verified';
    $pdo->request['verified_at'] = date('Y-m-d H:i:s');
    $verified = viewer_registration_verification_resend_prepare('person@example.com');

    $pdo = viewer_phase42_reset('expired-A');
    $pdo->request['expires_at'] = date('Y-m-d H:i:s', time() - 60);
    $expired = viewer_registration_verification_resend_prepare('person@example.com');

    // Cancelled/stale open-origin state must not resurrect when open is later enabled again.
    $pdo = viewer_phase42_reset('cancelled-A');
    $pdo->request['status'] = 'cancelled';
    $pdo->request['cancelled_at'] = date('Y-m-d H:i:s');
    $cancelled = viewer_registration_verification_resend_prepare('person@example.com');
    viewer_phase42_assert(empty($cancelled['mail_eligible']) && $pdo->children === [], 'Cancelled open-origin staging must never regain resend authority when open is enabled.');

    foreach ([$unknown, $existingAccount, $verified, $expired, $cancelled, $blockedInviteOnly, $blockedDisabled] as $genericOutcome) {
        viewer_phase42_assert(($genericOutcome['public_result'] ?? '') === 'request_received', 'Enumeration-sensitive resend states must retain the same service-level public result code.');
        viewer_phase42_assert(empty($genericOutcome['mail_eligible']) && empty($genericOutcome['verification_token']), 'Ineligible generic outcomes must not expose a verification capability.');
    }

    // The resend-specific limiter fails closed and does not mint B when denied.
    $pdo = viewer_phase42_reset('token-A');
    $GLOBALS['viewer_phase42_resend_limit_allowed'] = false;
    $GLOBALS['viewer_phase42_token_queue'] = ['must-not-be-used'];
    $limited = viewer_registration_verification_resend_prepare('person@example.com');
    viewer_phase42_assert(empty($limited['mail_eligible']) && $pdo->children === [], 'Resend limiter denial must fail closed before child authority creation.');

    $root = dirname(__DIR__);
    $controller = (string) file_get_contents($root . '/app/controllers/viewer_accounts.php');
    $httpService = (string) file_get_contents($root . '/app/services/viewer_http.php');
    $registrationService = (string) file_get_contents($root . '/app/services/viewer_registration.php');
    $mailService = (string) file_get_contents($root . '/app/services/viewer_mail.php');
    $dispatch = (string) file_get_contents($root . '/app/bootstrap/dispatch.php');
    $routing = (string) file_get_contents($root . '/app/bootstrap/routing.php');
    $requestHelpers = (string) file_get_contents($root . '/app/helpers_request.php');
    $migration = (string) file_get_contents($root . '/database/migrations/202608200001_viewer_registration_verification_tokens.php');
    $resendController = viewer_phase42_function_source($controller, 'cms_viewer_resend_verification');
    $resendDelivery = viewer_phase42_function_source($controller, 'viewer_deliver_registration_verification_resend');
    $resendHttp = viewer_phase42_function_source($httpService, 'viewer_http_verification_resend_available');

    // Dispatch and clean routing are explicit and remain under the existing viewer_* feature wrapper.
    viewer_phase42_assert(str_contains($dispatch, "'viewer_resend_verification' => '\\\\Gallery\\\\Controllers\\\\cms_viewer_resend_verification'"), 'Dispatch must expose viewer_resend_verification.');
    viewer_phase42_assert(str_contains($routing, "\$segments === ['viewer', 'resend']") && str_contains($routing, "['page' => 'viewer_resend_verification'"), 'Clean /viewer/resend input routing must resolve correctly.');
    viewer_phase42_assert(str_contains($requestHelpers, "\$page === 'viewer_resend_verification'") && str_contains($requestHelpers, "base_url('viewer/resend')"), 'Clean URL output must emit /viewer/resend.');
    viewer_phase42_assert(str_contains($resendHttp, 'viewer_http_registration_lifecycle_available()'), 'Resend availability must reuse the shared registration lifecycle gate.');

    // GET/POST form contract: email plus viewer/pre-auth CSRF only, with no browser-supplied registration/origin authority.
    viewer_phase42_assert(str_contains($resendController, 'viewer_csrf_field()') && str_contains($resendController, 'viewer_verify_csrf_or_render_error()'), 'Resend GET/POST must use Viewer/pre-auth CSRF.');
    viewer_phase42_assert(str_contains($resendController, "\$_POST['email']") && str_contains($resendController, 'viewer_registration_verification_resend_prepare($email)'), 'Valid resend POST must reach service orchestration from email lookup only.');
    foreach (["\$_POST['request_id']", "\$_POST['viewer_account_id']", "\$_POST['viewer_invitation_id']", "\$_POST['token']", "\$_POST['password']", 'registration_origin'] as $forbiddenInput) {
        viewer_phase42_assert(!str_contains($resendController, $forbiddenInput), 'Resend browser input must not accept authority field: ' . $forbiddenInput);
    }

    // Every syntactically valid outcome reaches the same public notice; internal reasons stay in bounded security events only.
    viewer_phase42_assert(substr_count($resendController, 'viewer.resend.request_received') === 1, 'Resend must expose exactly one generic valid-submission notice.');
    viewer_phase42_assert(str_contains($resendController, 'viewer.resend.invalid_email'), 'Malformed email may use local form validation.');
    viewer_phase42_assert(!str_contains($resendController, "echo \$result['reason']") && !str_contains($resendController, "e(\$result['reason'])"), 'Internal resend reason must never be rendered.');
    viewer_phase42_assert(str_contains($resendDelivery, 'viewer_mail_authorize_send(VIEWER_MAIL_ACTION_VERIFICATION'), 'Resend must reuse existing verification-mail authorization.');
    foreach (['viewer_verify_mail_email_cooldown', 'viewer_verify_mail_email_hour', 'viewer_verify_mail_email_day', 'viewer_verify_mail_ip_hour', 'viewer_verify_mail_ip_day', 'viewer_verify_mail_subnet_hour', 'viewer_verify_mail_subnet_day', 'viewer_verify_mail_global_day'] as $bucket) {
        viewer_phase42_assert(str_contains($mailService, $bucket), 'Existing verification-mail bucket must remain in use: ' . $bucket);
    }

    // Schema keeps historical primary columns and adds one normalized hashed child table with cascade cleanup.
    viewer_phase42_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS viewer_registration_verification_tokens'), 'Phase 4.2 migration must add normalized child authority storage.');
    viewer_phase42_assert(str_contains($migration, 'token_hash CHAR(64) NOT NULL') && str_contains($migration, 'UNIQUE KEY viewer_registration_verification_tokens_hash_unique'), 'Child authority must store only a unique hash.');
    viewer_phase42_assert(str_contains($migration, 'REFERENCES viewer_registration_requests(id) ON DELETE CASCADE'), 'Child authority must be owned and cascade-cleaned with its request.');
    viewer_phase42_assert(str_contains($registrationService, 'viewer_registration_resend_token_cap()') && str_contains($registrationService, 'VIEWER_REGISTRATION_RESEND_TOKEN_CAP'), 'Child authority accumulation must have an explicit per-request cap.');
    viewer_phase42_assert(str_contains($registrationService, 'DELETE FROM viewer_registration_verification_tokens WHERE expires_at < ? LIMIT 1000'), 'Scheduled maintenance must clean expired child authorities.');

    // Scanner safety and principal separation remain unchanged.
    $verifyController = viewer_phase42_function_source($controller, 'cms_viewer_verify');
    viewer_phase42_assert(str_contains($verifyController, 'viewer_registration_verification_validate($token)') && str_contains($verifyController, 'viewer_registration_verification_confirm($token)'), 'Verification GET validation and explicit POST confirmation must remain separate.');
    viewer_phase42_assert(!str_contains($verifyController, 'viewer_session_establish') && !str_contains($resendController, 'viewer_session_establish') && !str_contains($resendController, "\$_SESSION['user_id']"), 'Resend/verification must not create Viewer or Admin identity.');
    viewer_phase42_assert(!str_contains($resendController, 'verification_token_hash') && !str_contains($resendController, 'verification_token]'), 'Resend response/event controller must not render or log token material.');

    // No CAPTCHA/challenge or external runtime security dependency is introduced by Phase 4.2 sources.
    $phase42Sources = strtolower($controller . "\n" . $httpService . "\n" . $registrationService . "\n" . $migration);
    foreach (['turnstile', 'recaptcha', 'hcaptcha', 'friendly captcha', 'akismet', 'risk_score', 'proof-of-work', 'browser fingerprint'] as $forbidden) {
        viewer_phase42_assert(!str_contains($phase42Sources, $forbidden), 'Phase 4.2 must not add external/adaptive challenge behavior: ' . $forbidden);
    }
    viewer_phase42_assert(!str_contains($phase42Sources, 'curl_') && !str_contains($phase42Sources, 'file_get_contents(\'http'), 'Phase 4.2 must not add outbound HTTP security dependencies.');

    $configExample = (string) file_get_contents($root . '/config.example.php');
    viewer_phase42_assert(str_contains($configExample, "'enabled' => false") && str_contains($configExample, "'registration_mode' => 'disabled'"), 'Viewer Accounts must remain OFF by default.');

    foreach (['en', 'cs', 'de', 'sv'] as $language) {
        $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true);
        viewer_phase42_assert(is_array($catalog), 'Translation catalog must decode: ' . $language);
        foreach (['viewer.resend.title', 'viewer.resend.help', 'viewer.resend.button', 'viewer.resend.request_received', 'viewer.resend.invalid_email', 'viewer.resend.prompt', 'viewer.resend.link', 'viewer.verify.invalid_title', 'viewer.verify.invalid_message'] as $key) {
            viewer_phase42_assert(isset($catalog[$key]) && is_string($catalog[$key]) && trim($catalog[$key]) !== '', 'Missing Phase 4.2 translation ' . $key . ' in ' . $language . '.');
        }
    }

    echo "Viewer verification resend Phase 4.2 tests passed.\n";
}
