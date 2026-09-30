<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/cooperative_proposals.php
 * Module Type: Controller
 * Purpose: Adapt initial proposal exchange and administrator decisions to bounded HTTP.
 * Responsibilities: Own bearer, Admin, CSRF, method and canonical mutation response boundaries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

use Gallery\Core as Core;
use Gallery\Services as Service;
use Gallery\Services\CooperativeException;

/** Map proposal errors without exposing storage or remote diagnostics.
 * @param string $reason Stable domain refusal code.
 * @return int Appropriate bounded HTTP status.
 */
function cooperative_proposal_error_status(string $reason): int
{
    return match ($reason) {
        'proposal_not_found' => 404,
        'proposal_unauthorized' => 403,
        'proposal_mismatch', 'proposal_declined', 'proposal_revision_unsupported', 'proposal_already_active',
        'verification_unavailable', 'verification_expired', 'consent_missing', 'consent_stale', 'friendship_changed' => 409,
        'source_unavailable', 'friendship_unavailable', 'credential_unavailable' => 503,
        default => cooperative_pairing_error_status($reason),
    };
}

/** Accept only direct authenticated proposal messages; no GET discovery or cookie authority.
 * @return void Emit a private JSON reply with this installation's own decision only.
 */
function cms_cooperative_proposal_api(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (Core\request_method() !== 'POST') {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'method_not_allowed'], 405);
        return;
    }
    try {
        cooperative_pairing_json(Service\cooperative_proposal_receive(cooperative_pairing_request_body(), cooperative_pairing_bearer()));
    } catch (CooperativeException $error) {
        cooperative_pairing_json(['ok' => false, 'error_code' => $error->reason], cooperative_proposal_error_status($error->reason));
    } catch (\Throwable) {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'proposal_unavailable'], 503);
    }
}

/** Read the local proposal inbox or one exact group's state without peer requests.
 * @return void Emit nonsecret administrator state without granting group access.
 */
function cms_admin_cooperative_proposals(): void
{
    Core\require_admin();
    if (Core\request_method() !== 'GET') {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'method_not_allowed'], 405);
        return;
    }
    try {
        $id = $_GET['group_id'] ?? '';
        $cursor = $_GET['after_id'] ?? '';
        if (!is_string($id) || !is_string($cursor)) {
            throw new CooperativeException('invalid_input');
        }
        $state = $id === '' ? Service\cooperative_proposal_exchange_inbox($cursor)
            : Service\cooperative_proposal_exchange_projection(Service\cooperative_proposal_exchange_load($id));
        cooperative_pairing_json(['ok' => true, 'state' => $state]);
    } catch (CooperativeException $error) {
        cooperative_pairing_json(['ok' => false, 'error_code' => $error->reason], cooperative_proposal_error_status($error->reason));
    } catch (\Throwable) {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'proposal_unavailable'], 503);
    }
}

/** Parse one bounded Admin JSON-list form field without choosing domain behavior.
 * @param string $key Allowlisted form field selected by the controller.
 * @return list<mixed> Submitted list for subsequent domain validation.
 */
function cooperative_proposal_form_list(string $key): array
{
    $raw = cooperative_pairing_form_string($key, Service\COOPERATIVE_PAIRING_MAX_BYTES);
    try {
        $value = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        throw new CooperativeException('invalid_input');
    }
    if (!is_array($value) || !array_is_list($value)) {
        throw new CooperativeException('invalid_input');
    }
    return $value;
}

/** Execute explicit Admin intent with CSRF and exactly one remote operation at most.
 * @return void Emit canonical completion, including the local gallery when its public cooperation link changes.
 */
function cms_admin_cooperative_proposal_action(): void
{
    Core\require_admin();
    if (Core\request_method() !== 'POST') {
        cooperative_proposal_action_response(['ok' => false, 'error_code' => 'method_not_allowed'], 405);
        return;
    }
    Core\verify_csrf();
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    try {
        $action = cooperative_pairing_form_string('action', 32);
        $id = cooperative_pairing_form_string('group_id', 32);
        $revision = filter_var($_POST['revision'] ?? 0, FILTER_VALIDATE_INT);
        if ($revision === false || $revision < 0) {
            throw new CooperativeException('invalid_revision');
        }
        if (in_array($action, ['prepare', 'reference'], true)) {
            $galleryId = filter_var($_POST['gallery_id'] ?? 0, FILTER_VALIDATE_INT);
            if ($galleryId === false || $galleryId < 1) {
                throw new CooperativeException('invalid_gallery');
            }
            $result = $action === 'reference' ? ['reference_code' => Service\cooperative_album_reference_code($galleryId, ($_POST['photos'] ?? '') === '1')]
                : ['member' => Service\cooperative_album_source_member($galleryId, cooperative_proposal_form_list('scopes'))];
        } else {
            $proposal = match ($action) {
                'compose' => Service\cooperative_proposal_compose(cooperative_pairing_form_string('request_id', 32), cooperative_proposal_gallery_id(), cooperative_pairing_form_string('references', Service\COOPERATIVE_PAIRING_MAX_BYTES), ($_POST['photos'] ?? '') === '1'),
                'expand' => Service\cooperative_proposal_expand($id, $revision, cooperative_pairing_form_string('request_id', 32), cooperative_pairing_form_string('reference', 512)),
                'synchronize' => Service\cooperative_proposal_synchronize($id, $revision),
                'email' => Service\cooperative_proposal_email($id, $revision, cooperative_pairing_form_string('peer_id', 32), cooperative_pairing_form_string('email', 254)),
                'create' => Service\cooperative_proposal_exchange_create(cooperative_pairing_form_string('request_id', 32), cooperative_proposal_form_list('members')),
                'approve', 'decline' => Service\cooperative_proposal_exchange_decide($id, $revision, cooperative_pairing_form_string('digest', 64), $action === 'approve' ? 'approved' : 'declined'),
                'advance' => Service\cooperative_maintenance_step($id, $revision),
                'verify_start' => Service\cooperative_verification_start($id, $revision),
                'verify_peer' => Service\cooperative_verification_peer($id, $revision, cooperative_pairing_form_string('round_id', 32), cooperative_pairing_form_string('peer_id', 32)),
                'activate' => Service\cooperative_activation_finalize($id, $revision, cooperative_pairing_form_string('round_id', 32)),
                'deliver', 'refresh' => Service\cooperative_proposal_exchange_contact($id, $revision, cooperative_pairing_form_string('peer_id', 32), $action === 'deliver' ? 'offer' : 'decision'),
                default => throw new CooperativeException('invalid_action'),
            };
            $galleryId = $proposal['gallery_id'];
            $result = ['proposal' => $proposal];
        }
        $envelope = Core\admin_mutation_success_envelope(
            Service\t('admin.cooperative.proposal.updated', 'Album proposal state updated.'),
            Core\admin_mutation_descriptor('cooperative_proposal.' . $action, 'cooperative_album', $action, [$galleryId]),
            null, (in_array($action, ['approve', 'decline'], true)
                || in_array($action, ['advance', 'activate', 'synchronize'], true) && ($proposal['state'] ?? '') === 'active')
                ? [Core\admin_mutation_public_gallery_context($galleryId, Core\url_for('gallery', ['id' => $galleryId]))] : [], []
        );
        cooperative_proposal_action_response($envelope + $result);
    } catch (CooperativeException $error) {
        cooperative_proposal_action_response(Core\admin_mutation_error_envelope(
            Service\t('admin.cooperative.proposal.refused', 'The album proposal operation could not be completed.'),
            'cooperative.' . $error->reason
        ), cooperative_proposal_error_status($error->reason));
    } catch (\Throwable) {
        cooperative_proposal_action_response(Core\admin_mutation_error_envelope(
            Service\t('admin.cooperative.proposal.refused', 'The album proposal operation could not be completed.'),
            'cooperative.proposal_unavailable'
        ), 503);
    }
}

/** Select the explicit proposal UI adapter while retaining the original JSON API.
 * @param array<string,mixed> $envelope Canonical mutation response or method refusal.
 * @param int $status HTTP response status.
 * @return void Emit JSON or the requested UI representation.
 */
function cooperative_proposal_action_response(array $envelope, int $status = 200): void
{
    if (($_POST['cooperative_proposal_ui'] ?? '') === '1') {
        admin_cooperative_proposals_response($envelope, $status);
        return;
    }
    cooperative_pairing_json($envelope, $status);
}

/** Parse the exact positive local gallery selected by a composition form.
 * @return int Valid local gallery identifier, without accepting partial numeric strings.
 */
function cooperative_proposal_gallery_id(): int
{
    $value = filter_var($_POST['gallery_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($value === false || $value < 1) { throw new CooperativeException('invalid_gallery'); }
    return $value;
}
