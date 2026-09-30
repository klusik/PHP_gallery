<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_cooperative_proposals.php
 * Module Type: Controller
 * Purpose: Present local collaboration proposals for explicit administrator review.
 * Responsibilities: Prepare translated view models and preserve JSON, drawer and no-JavaScript boundaries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

use Gallery\Core as Core;
use Gallery\Services as Service;

/** Translate the review vocabulary independently from domain decisions.
 * @return array<string,string> Prepared labels for proposal rows and participant state.
 */
function admin_cooperative_proposals_labels(): array
{
    $labels = admin_cooperative_labels();
    foreach ([
        'title' => 'Album collaborations',
        'compose' => 'Create proposal',
        'compose_help' => 'Each participant selects an album and sends its reference code to the coordinator. Create a proposal from your album and the other codes. References propose the selected permissions; approval and delivery are separate.',
        'choose_album' => 'Local album',
        'reference' => 'Prepare album reference',
        'references' => 'Other participants’ reference codes (one per line)',
        'reference_result' => 'Your album reference code — it does not grant access',
        'first_albums' => 'First albums',
        'next_albums' => 'More albums',

        'intro' => 'Review all participants and permissions before approving. Each installation decides independently. Invitations expire after one day; technical verification renews independently.',
        'photos' => 'Include thumbnails and photo previews (no originals)',
        'synchronize' => 'Deliver and verify', 'email' => 'Email invitation', 'email_address' => 'Participant email',
        'mail_accepted' => 'Invitation accepted by the mail server.',
        'open_shared' => 'Open shared photographs', 'expand' => 'Invite another participant',
        'expand_help' => 'Create a new proposal with all existing albums and one additional participant. Everyone approves again. The existing collaboration remains available until you withdraw from it.',
        'new_reference' => 'New participant’s album reference',
        'mail_help' => 'Uses the email delivery configured in Account settings. Email links require sign-in and explicit approval.',
        'empty' => 'No album collaboration proposals yet.',
        'approve' => 'Approve this collaboration', 'decline' => 'Decline / withdraw my consent',
        'advance' => 'Continue verification', 'deliver' => 'Send proposal', 'refresh_peer' => 'Check participant decision',
        'local' => 'This installation', 'album' => 'Album reference', 'permissions' => 'Proposed permissions',
        'participants' => 'Participants', 'own' => 'Your decision', 'observed' => 'Last observed decision (UTC)',
        'pending' => 'Waiting for approval', 'approved' => 'Approved', 'declined' => 'Declined',
        'expired' => 'Proposal expired', 'consent_stale' => 'Consent needs review: check the album and friendships',
        'unknown' => 'Not verified', 'active' => 'Membership approved', 'suspended' => 'Collaboration suspended',
        'authorized' => 'Current technical verification is valid', 'paused' => 'Sharing is paused until verification completes',
        'expires' => 'Accept and activate before (UTC)', 'waiting' => 'Waiting for another participant; check again after (UTC)',
        'metadata' => 'Album information', 'thumbnail' => 'Thumbnails', 'preview' => 'Photo previews', 'original' => 'Original photographs',
        'missing_album' => 'Local album unavailable', 'missing_peer' => 'Direct friendship unavailable',
        'friendships' => 'Manage friendships',
    ] as $key => $fallback) {
        $labels[$key] = Service\t('admin.cooperative.review.' . $key, $fallback);
    }
    return $labels;
}

/** Prepare the read-only inbox and presentation-only action labels.
 * @param string $cursor Exclusive group cursor.
 * @param array<string,mixed> $result Optional completed mutation result.
 * @param int $albumCursor Exclusive local album picker cursor.
 * @return array<string,mixed> View model with bounded escaped-at-render rows.
 */
function admin_cooperative_proposals_model(string $cursor = '', array $result = [], int $albumCursor = 0): array
{
    $labels = admin_cooperative_proposals_labels();
    $page = ['items' => [], 'next_cursor' => null];
    $error = '';
    try {
        $page = Service\cooperative_proposal_review_inbox($cursor, (string) ($result['proposal']['group_id'] ?? ''));
    } catch (\Throwable) {
        $error = $labels['unavailable'];
    }
    $rows = [];
    foreach ($page['items'] as $item) {
        $proposal = $item['proposal'];
        $participants = [];
        foreach ($item['participants'] as $participant) {
            $participant['label'] = $participant['local'] ? $labels['local'] : ($participant['base_url'] ?: $labels['missing_peer']);
            $participant['status'] = $labels[$participant['decision']] ?? $labels['unknown'];
            $participant['permissions'] = implode(', ', array_map(
                /** Format only allowlisted scope names supplied by the review service.
                 * @param string $scope Canonical domain scope.
                 * @return string Translated human-readable permission.
                 */ static fn(string $scope): string => $labels[$scope] ?? $labels['unknown'], $participant['scopes']));
            $participant['observed'] = $participant['observed_at'] !== null ? gmdate('Y-m-d H:i:s', $participant['observed_at']) : '';
            $participants[] = $participant;
        }
        $rows[] = ['group_id' => $proposal['group_id'], 'revision' => $proposal['revision'], 'digest' => $proposal['digest'],
            'title' => $item['title'] ?: $labels['missing_album'], 'status' => $labels[$proposal['state']] ?? $labels['unknown'],
            'own' => $labels[$proposal['own']['decision']] ?? $labels['unknown'], 'actions' => $item['actions'],
            'authorization' => $proposal['state'] === 'active' ? $labels[$proposal['sharing_active'] ? 'authorized' : 'paused'] : '',
            'expires' => $proposal['state'] === 'pending' ? gmdate('Y-m-d H:i:s', $proposal['body']['expires_at']) : '',
            'waiting' => $proposal['maintenance']['action'] === 'waiting' ? gmdate('Y-m-d H:i:s', $proposal['maintenance']['retry_at']) : '',
            'participants' => $participants,
            'public_url' => $proposal['state'] === 'active' && $proposal['own']['decision'] === 'approved' ? Core\url_for('cooperative_gallery', ['group_id' => $proposal['group_id']]) : '',
            'can_expand' => $proposal['state'] === 'active' && $proposal['own']['decision'] === 'approved',
            'expansion_request' => ($_POST['action'] ?? '') === 'expand' && ($_POST['group_id'] ?? '') === $proposal['group_id']
                && is_string($_POST['request_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/', $_POST['request_id']) === 1
                ? $_POST['request_id'] : Service\cooperative_id_generate(),
            'expansion_reference' => ($_POST['action'] ?? '') === 'expand' && ($_POST['group_id'] ?? '') === $proposal['group_id']
                && is_string($_POST['reference'] ?? null) ? substr($_POST['reference'], 0, 512) : ''];
    }
    return ['labels' => $labels, 'rows' => $rows, 'cursor' => $cursor, 'error' => $error,
        'notice' => (string) ($result['message'] ?? ''), 'csrf_html' => Core\csrf_field(),
        'action_url' => Core\url_for('admin_cooperative_proposal_action'),
        'refresh_url' => Core\url_for('admin_cooperative_collaborations', ['after_id' => $cursor]),
        'first_url' => $cursor !== '' ? Core\url_for('admin_cooperative_collaborations') : '',
        'next_url' => $page['next_cursor'] !== null ? Core\url_for('admin_cooperative_collaborations', ['after_id' => $page['next_cursor']]) : '',
        'friendships_url' => Core\url_for('admin_cooperative_galleries')]
        + admin_cooperative_composition_model($cursor, $albumCursor, $result);
}

/** Capture only the proposal component for canonical panel completion.
 * @param array<string,mixed> $model Prepared presentation data.
 * @return string Escaped server-rendered fragment.
 */
function admin_cooperative_proposals_html(array $model): string
{
    ob_start();
    try {
        \Gallery\Views\view_render_admin_cooperative_proposals($model);
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

/** Render a private full page or the current drawer's fragment.
 * @param array<string,mixed> $model Prepared presentation data.
 * @return void Emit HTML with an ordinary-page fallback.
 */
function admin_cooperative_proposals_render(array $model): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    $panel = !empty($_GET['panel']) || !empty($_POST['panel']);
    if (!$panel) { Core\render_header($model['labels']['title']); }
    \Gallery\Views\view_render_admin_cooperative_proposals($model);
    if (!$panel) { Core\render_footer(); }
}

/** Serve the local review inbox without contacting participants or changing approvals.
 * @return void Emit a private Admin page, fragment or bounded method/cursor refusal.
 */
function cms_admin_cooperative_collaborations(): void
{
    Core\require_admin();
    Service\cooperative_require_enabled();
    if (Core\request_method() !== 'GET') {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'method_not_allowed'], 405);
        return;
    }
    $cursor = $_GET['after_id'] ?? '';
    if (!is_string($cursor) || ($cursor !== '' && preg_match('/\A[a-f0-9]{32}\z/', $cursor) !== 1)) {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'invalid_cursor'], 400);
        return;
    }
    $albumCursor = filter_var($_GET['album_after'] ?? 0, FILTER_VALIDATE_INT);
    if ($albumCursor === false || $albumCursor < 0) {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'invalid_cursor'], 400);
        return;
    }
    admin_cooperative_proposals_render(admin_cooperative_proposals_model($cursor, ['proposal' => ['group_id' => is_string($_GET['focus_group'] ?? null) ? $_GET['focus_group'] : '']], $albumCursor));
}

/** Decorate explicit UI mutations without changing the original JSON consumer contract.
 * @param array<string,mixed> $envelope Canonical response from the existing mutation controller.
 * @param int $status HTTP response status.
 * @return void Emit a canonical AJAX fragment response or the no-JavaScript HTML result.
 */
function admin_cooperative_proposals_response(array $envelope, int $status = 200): void
{
    $labels = admin_cooperative_proposals_labels();
    $cursor = $_POST['after_id'] ?? '';
    $cursor = is_string($cursor) && preg_match('/\A[a-f0-9]{32}\z/', $cursor) === 1 ? $cursor : '';
    $albumCursor = filter_var($_POST['album_after'] ?? 0, FILTER_VALIDATE_INT);
    $albumCursor = $albumCursor !== false && $albumCursor >= 0 ? $albumCursor : 0;
    if (!$envelope['ok']) {
        $envelope['message'] = $envelope['error'] = $labels['unavailable'];
    } else {
        $envelope['panel'] = Core\admin_mutation_panel_metadata('cooperative_proposals', Core\url_for('admin_cooperative_collaborations', ['panel' => 1, 'after_id' => $cursor]), true);
        $envelope['fallback'] = ['redirect_url' => Core\url_for('admin_cooperative_collaborations', ['after_id' => $cursor])];
    }
    if (Core\admin_wants_json()) {
        if ($envelope['ok']) {
            $envelope['panel_html'] = admin_cooperative_proposals_html(admin_cooperative_proposals_model($cursor, $envelope, $albumCursor));
        }
        cooperative_pairing_json($envelope, $status);
        return;
    }
    http_response_code($status);
    admin_cooperative_proposals_render(admin_cooperative_proposals_model($cursor, $envelope, $albumCursor));
}

/** Prepare a bounded local album picker and retain submitted composition intent.
 * @param string $cursor Current proposal inbox cursor.
 * @param int $albumCursor Current source picker cursor.
 * @param array<string,mixed> $result Optional mutation result with a reference code.
 * @return array<string,mixed> Presentation-only composition form data.
 */
function admin_cooperative_composition_model(string $cursor, int $albumCursor, array $result): array
{
    $sources = ['items' => [], 'next_cursor' => null];
    $ready = true;
    try { $sources = Service\cooperative_album_sources_state($albumCursor); }
    catch (\Throwable) { $ready = false; }
    $requestId = $_POST['request_id'] ?? '';
    $retain = ($result['mutation']['action'] ?? '') !== 'compose';
    $requestId = $retain && is_string($requestId) && preg_match('/\A[a-f0-9]{32}\z/', $requestId) === 1 ? $requestId : Service\cooperative_id_generate();
    $references = $_POST['references'] ?? '';
    $references = $retain && is_string($references) ? substr($references, 0, Service\COOPERATIVE_PAIRING_MAX_BYTES) : '';
    $selected = filter_var($_POST['gallery_id'] ?? 0, FILTER_VALIDATE_INT);
    return ['sources' => $sources['items'], 'source_ready' => $ready, 'album_cursor' => $albumCursor,
        'source_next' => $sources['next_cursor'] !== null ? Core\url_for('admin_cooperative_collaborations', ['after_id' => $cursor, 'album_after' => $sources['next_cursor']]) : '',
        'source_first' => $albumCursor > 0 ? Core\url_for('admin_cooperative_collaborations', ['after_id' => $cursor]) : '',
        'composition_photos' => !isset($_POST['action']) || ($_POST['photos'] ?? '') === '1',
        'composition_request' => $requestId, 'reference_code' => (string) ($result['reference_code'] ?? ''),
        'composition_references' => $references, 'source_selected' => $selected !== false ? $selected : 0];
}
