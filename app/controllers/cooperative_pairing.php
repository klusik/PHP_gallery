<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/cooperative_pairing.php
 * Module Type: Controller
 * Purpose: Expose bounded peer protocol and administrator pairing adapters.
 * Responsibilities: Own authentication, CSRF, body limits, JSON headers and canonical mutation envelopes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

use Gallery\Core as Core;
use Gallery\Services as Service;
use Gallery\Services\CooperativeException;

/** Emit a noncacheable protocol or administrative JSON response.
 *
 * @param array<string,mixed> $payload Internal structured payload; null selects discovery where supported.
 * @param int $status HTTP response status.
 * @return void No return value; refusals raise an exception.
 */
function cooperative_pairing_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

/** Read bounded object JSON, rejecting unsupported media types and oversized bodies.
 *
 * @return array<string,mixed> Result described by the operation above.
 */
function cooperative_pairing_request_body(): array
{
    if (strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0])) !== 'application/json') {
        throw new CooperativeException('json_required');
    }
    $stream = fopen('php://input', 'rb');
    if ($stream === false) {
        throw new CooperativeException('invalid_message');
    }
    try {
        $raw = stream_get_contents($stream, Service\COOPERATIVE_PAIRING_MAX_BYTES + 1);
    } finally {
        fclose($stream);
    }
    if (!is_string($raw) || strlen($raw) > Service\COOPERATIVE_PAIRING_MAX_BYTES) {
        throw new CooperativeException('message_too_large');
    }
    try {
        $body = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        throw new CooperativeException('invalid_message');
    }
    if (!is_array($body) || array_is_list($body)) {
        throw new CooperativeException('invalid_message');
    }
    return $body;
}

/** Resolve only an explicit Authorization header; cookies and query tokens never authorize peers.
 *
 * @return string Result described by the operation above.
 */
function cooperative_pairing_bearer(): string
{
    $value = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    return preg_match('/\ABearer ([A-Za-z0-9_-]{32,128})\z/', $value, $match) === 1 ? $match[1] : '';
}

/** Return a bounded status code without exposing storage or transport exception details.
 *
 * @param string $reason Bounded domain refusal identifier.
 * @return int Result described by the operation above.
 */
function cooperative_pairing_error_status(string $reason): int
{
    return match ($reason) {
        'feature_disabled', 'pairing_not_found' => 404,
        'pairing_unauthorized', 'wrong_invitation_recipient' => 403,
        'revision_conflict', 'request_conflict', 'exchange_conflict', 'peer_already_registered' => 409,
        'retry_not_due' => 429,
        'schema_missing', 'schema_unknown', 'peer_unavailable', 'peer_response_invalid',
        'pairing_secret_unavailable', 'secret_unavailable', 'canonical_base_required' => 503,
        default => 400,
    };
}

/** Dispatch the public peer endpoint with system credentials, independently of administrator cookies.
 *
 * @return void No return value; refusals raise an exception.
 */
function cms_cooperative_peer_api(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    try {
        $method = Core\request_method();
        if ($method === 'GET') {
            cooperative_pairing_json(Service\cooperative_pairing_identity());
            return;
        }
        if ($method !== 'POST') {
            cooperative_pairing_json(['ok' => false, 'error_code' => 'method_not_allowed'], 405);
            return;
        }
        cooperative_pairing_json(Service\cooperative_pairing_receive(cooperative_pairing_request_body(), cooperative_pairing_bearer()));
    } catch (CooperativeException $error) {
        cooperative_pairing_json(['ok' => false, 'error_code' => $error->reason], cooperative_pairing_error_status($error->reason));
    } catch (\Throwable) {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'pairing_unavailable'], 503);
    }
}

/** Read the prepared administrator list without exposing invitation codes or long-lived keys.
 *
 * @return void No return value; refusals raise an exception.
 */
function cms_admin_cooperative_state(): void
{
    Core\require_admin();
    if (Core\request_method() !== 'GET') {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'method_not_allowed'], 405);
        return;
    }
    try {
        $cursor = filter_var($_GET['after_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($cursor === false || $cursor < 0) {
            throw new CooperativeException('invalid_cursor');
        }
        cooperative_pairing_json(['ok' => true, 'state' => Service\cooperative_pairing_admin_state($cursor)]);
    } catch (CooperativeException $error) {
        cooperative_pairing_json(['ok' => false, 'error_code' => $error->reason], cooperative_pairing_error_status($error->reason));
    } catch (\Throwable) {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'pairing_unavailable'], 503);
    }
}

/** Extract one bounded scalar form input without array-to-string coercion.
 * Purpose: Bound invitation and form strings before domain parsing.
 * Type: integer. Units: bytes. Scope: this controller input helper.
 * Consumers: administrator pairing actions.
 * Rationale: 8192 admits encoded invitations while bounding allocation.
 *
 * @param string $key Canonical setting or form field name.
 * @param int $limit Maximum number of records or bytes to accept.
 * @return string Result described by the operation above.
 */
function cooperative_pairing_form_string(string $key, int $limit = Service\COOPERATIVE_PAIRING_CODE_MAX_BYTES): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || strlen($value) > $limit) {
        throw new CooperativeException('invalid_input');
    }
    return $value;
}

/** Perform authorized panel-ready operations through the canonical completion envelope.
 *
 * @return void No return value; refusals raise an exception.
 */
function cms_admin_cooperative_action(): void
{
    Core\require_admin();
    if (Core\request_method() !== 'POST') {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'method_not_allowed'], 405);
        return;
    }
    Core\verify_csrf();
    // Remote callbacks must not wait on the administrator's writable PHP session.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    try {
        $action = cooperative_pairing_form_string('action', 32);
        $invitationId = cooperative_pairing_form_string('invitation_id', 32);
        $revision = filter_var($_POST['revision'] ?? 0, FILTER_VALIDATE_INT);
        if ($revision === false || $revision < 0) {
            throw new CooperativeException('invalid_revision');
        }
        $result = match ($action) {
            'invite' => Service\cooperative_pairing_create(cooperative_pairing_form_string('base_url', 2048), cooperative_pairing_form_string('request_id', 32)),
            'import' => ['pairing' => Service\cooperative_pairing_import(cooperative_pairing_form_string('invitation_code'))],
            'accept' => ['pairing' => Service\cooperative_pairing_accept($invitationId, $revision)],
            'retry' => ['pairing' => Service\cooperative_pairing_resume($invitationId)],
            'revoke', 'decline' => ['pairing' => Service\cooperative_pairing_revoke($invitationId, $revision)],
            default => throw new CooperativeException('invalid_action'),
        };
        $envelope = Core\admin_mutation_success_envelope(
            Service\t('admin.cooperative.updated', 'Cooperative pairing state updated.'),
            Core\admin_mutation_descriptor('cooperative_pairing.' . $action, 'cooperative_pairing', $action, [$result['pairing']['id']]),
            Core\admin_mutation_panel_metadata('cooperative_pairing', Core\url_for('admin_cooperative_galleries', ['panel' => 1]), true),
            [],
            ['redirect_url' => Core\url_for('admin_cooperative_galleries')]
        );
        // A deliberate invitation-creation response may carry its short-lived share code.
        // System API keys, hashes and encrypted records never enter this UI response.
        $envelope['pairing'] = $result['pairing'];
        if (isset($result['invitation_code'])) {
            $envelope['invitation_code'] = $result['invitation_code'];
        }
        cooperative_pairing_action_response($envelope);
    } catch (CooperativeException $error) {
        cooperative_pairing_action_response(Core\admin_mutation_error_envelope(
            Service\t('admin.cooperative.refused', 'Cooperative pairing could not be completed.'), 'cooperative.' . $error->reason
        ), cooperative_pairing_error_status($error->reason));
    } catch (\Throwable) {
        cooperative_pairing_action_response(Core\admin_mutation_error_envelope(
            Service\t('admin.cooperative.unavailable', 'Cooperative pairing is temporarily unavailable.'), 'cooperative.unavailable'
        ), 503);
    }
}

/** Select the explicit UI adapter without changing existing JSON callers.
 * @param array<string,mixed> $envelope Canonical success or error envelope.
 * @param int $status HTTP response status.
 * @return void Emits the selected transport representation.
 */
function cooperative_pairing_action_response(array $envelope, int $status = 200): void
{
    if (($_POST['cooperative_ui'] ?? '') === '1') {
        admin_cooperative_ui_response($envelope, $status);
        return;
    }
    cooperative_pairing_json($envelope, $status);
}
