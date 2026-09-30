<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_cooperative_galleries.php
 * Module Type: Controller
 * Purpose: Prepare and render friendship management pages and mutation fragments.
 * Responsibilities: Keep presentation, request flow and domain authority in their owning layers.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

use Gallery\Core as Core;
use Gallery\Services as Service;

/** Translate the fixed presentation vocabulary.
 * @return array<string,string> Labels and safe errors; no domain discovery in views.
 */
function admin_cooperative_labels(): array
{
    return [
        'collaborations' => Service\t('admin.cooperative.review.title', 'Album collaborations'),
        'title' => Service\t('admin.cooperative.ui.title', 'Friendly galleries'),
        'intro' => Service\t('admin.cooperative.ui.intro', 'Connect two installations by invitation. Friendship alone does not share any albums or photographs.'),
        'show_invitation' => Service\t('admin.cooperative.ui.show_invitation', 'Show invitation code'),
        'invite' => Service\t('admin.cooperative.ui.invite', 'Create invitation'),
        'url' => Service\t('admin.cooperative.ui.url', 'Other gallery address (HTTPS)'),
        'import' => Service\t('admin.cooperative.ui.import', 'Import invitation'),
        'code' => Service\t('admin.cooperative.ui.code', 'Invitation code'),
        'import_help' => Service\t('admin.cooperative.ui.import_help', 'Import first to review the sender. You will confirm friendship separately.'),
        'share' => Service\t('admin.cooperative.ui.share', 'Send this code to the administrator of the invited gallery. It is valid for one day.'),
        'empty' => Service\t('admin.cooperative.ui.empty', 'No friendships or invitations yet.'),
        'accept' => Service\t('admin.cooperative.ui.accept', 'Accept friendship'),
        'revoke' => Service\t('admin.cooperative.ui.revoke', 'Disconnect / cancel invitation'),
        'retry' => Service\t('admin.cooperative.ui.retry', 'Retry exchange'),
        'refresh' => Service\t('admin.cooperative.ui.refresh', 'Refresh status'),
        'next' => Service\t('admin.cooperative.ui.next', 'Next page'),
        'first' => Service\t('admin.cooperative.ui.first', 'First page'),
        'expires' => Service\t('admin.cooperative.ui.expires', 'Invitation expires (UTC)'),
        'retry_at' => Service\t('admin.cooperative.ui.retry_at', 'Next retry after (UTC)'),
        'expired' => Service\t('admin.cooperative.ui.expired', 'Invitation expired. Cancel it before creating a new one.'),
        'invited' => Service\t('admin.cooperative.ui.invited', 'Invitation created — waiting for acceptance'),
        'received' => Service\t('admin.cooperative.ui.received', 'Invitation received — your approval is required'),
        'accepting' => Service\t('admin.cooperative.ui.accepting', 'Accepted — exchanging credentials'),
        'offered' => Service\t('admin.cooperative.ui.offered', 'Peer verified — waiting for confirmation'),
        'confirming' => Service\t('admin.cooperative.ui.confirming', 'Exchange completed — waiting for acknowledgement'),
        'active' => Service\t('admin.cooperative.ui.active', 'Friendship active'),
        'revoking' => Service\t('admin.cooperative.ui.revoking', 'Disconnected locally — notifying the other gallery'),
        'revoked' => Service\t('admin.cooperative.ui.revoked', 'Disconnected'),
        'unknown' => Service\t('admin.cooperative.ui.unknown', 'Status unavailable'),
        'unavailable' => Service\t('admin.cooperative.ui.unavailable', 'Could not verify the current state. Refresh to check it before repeating an action. Your input has been kept.'),
        'schema' => Service\t('admin.cooperative.ui.schema', 'Pairing storage is unavailable. Apply pending migrations or check database diagnostics.'),
        'config' => Service\t('admin.cooperative.ui.config', 'Configure this installation\'s canonical HTTPS address and enable cURL and OpenSSL before creating invitations.'),
        'conflict' => Service\t('admin.cooperative.ui.conflict', 'The relationship changed. Refresh its status before trying again.'),
        'invalid' => Service\t('admin.cooperative.ui.invalid', 'Check the address or invitation code and its intended recipient.'),
        'wait' => Service\t('admin.cooperative.ui.wait', 'Wait until the displayed retry time, then refresh the status.'),
        'local_only' => Service\t('admin.cooperative.ui.local_only', 'Access was revoked locally; delivery to the other gallery is unavailable.'),
        'busy' => Service\t('admin.cooperative.ui.busy', 'Working…'),
    ];
}

/** Map bounded domain refusals to actionable, nonsecret messages.
 * @param string $reason Stable refusal identifier, without the transport prefix.
 * @param array<string,string> $labels Prepared translations.
 * @return string Human-readable refusal.
 */
function admin_cooperative_error_text(string $reason, array $labels): string
{
    $key = match ($reason) {
        'schema_missing', 'schema_unknown' => 'schema',
        'secret_unavailable', 'canonical_base_required' => 'config',
        'revision_conflict', 'request_conflict', 'exchange_conflict', 'peer_already_registered' => 'conflict',
        'invitation_expired', 'invitation_unavailable' => 'expired',
        'retry_not_due' => 'wait',
        'invalid_peer_url', 'invalid_invitation', 'wrong_invitation_recipient', 'peer_identity_invalid', 'invalid_input' => 'invalid',
        default => 'unavailable',
    };
    return $labels[$key];
}

/** Prepare bounded page data without exposing credential records.
 * @param int $cursor Exclusive local pagination cursor.
 * @param array<string,mixed> $result Optional completed mutation envelope.
 * @return array<string,mixed> Fully prepared presentation model.
 */
function admin_cooperative_view_model(int $cursor = 0, array $result = []): array
{
    $labels = admin_cooperative_labels();
    $error = '';
    $ready = true;
    $snapshot = ['items' => [], 'next_cursor' => null];
    try {
        $snapshot = Service\cooperative_pairing_admin_state($cursor);
    } catch (Service\CooperativeException $failure) {
        $error = admin_cooperative_error_text($failure->reason, $labels);
        $ready = false;
    } catch (\Throwable) {
        $error = $labels['unavailable'];
        $ready = false;
    }
    if ($ready) {
        try {
            Service\cooperative_pairing_local_base();
            Service\cooperative_storage_key();
            if (!function_exists('curl_init') || !function_exists('openssl_encrypt')) {
                throw new \RuntimeException('Transport unavailable.');
            }
        } catch (\Throwable) {
            $ready = false;
            $error = $labels['config'];
        }
    }
    $rows = [];
    foreach ($snapshot['items'] as $row) {
        $actions = [];
        foreach ($row['actions'] as $action) {
            $actions[] = [
                'name' => $action, 'label' => $labels[$action] ?? $labels['unknown'],
                'disabled' => ($action !== 'revoke' && !$ready) || ($action === 'retry' && $row['next_attempt_at'] > time()),
            ];
        }
        if ($ready && $row['role'] === 'inviter' && $row['state'] === 'invited' && !$row['expired']) {
            $actions[] = [
                'name' => 'invite', 'label' => $labels['show_invitation'], 'disabled' => false,
                'request_id' => $row['invitation_id'], 'base_url' => $row['base_url'],
            ];
        }
        $rows[] = [
            'base_url' => $row['base_url'], 'invitation_id' => $row['invitation_id'],
            'revision' => $row['revision'], 'status' => $labels[$row['state']] ?? $labels['unknown'],
            'expired' => $row['expired'], 'actions' => $actions,
            'expires' => in_array($row['state'], ['active', 'revoked', 'revoking'], true) ? '' : gmdate('Y-m-d H:i:s', $row['expires_at']),
            'retry_at' => $row['next_attempt_at'] > 0 ? gmdate('Y-m-d H:i:s', $row['next_attempt_at']) : '',
            'error' => $row['last_error'] !== '' ? admin_cooperative_error_text($row['last_error'], $labels) : '',
        ];
    }
    $notice = (string) ($result['message'] ?? '');
    if (($result['pairing']['delivery_status'] ?? '') === 'unavailable') {
        $notice = $labels['local_only'];
    }
    return [
        'labels' => $labels, 'rows' => $rows, 'error' => $error, 'ready' => $ready,
        'notice' => $notice, 'invitation_code' => (string) ($result['invitation_code'] ?? ''),
        'action_url' => Core\url_for('admin_cooperative_action'),
        'collaborations_url' => Core\url_for('admin_cooperative_collaborations'),
        'refresh_url' => Core\url_for('admin_cooperative_galleries', ['after_id' => $cursor]),
        'first_url' => $cursor > 0 ? Core\url_for('admin_cooperative_galleries') : '',
        'next_url' => $snapshot['next_cursor'] !== null ? Core\url_for('admin_cooperative_galleries', ['after_id' => $snapshot['next_cursor']]) : '',
        'csrf_html' => Core\csrf_field(), 'request_id' => Service\cooperative_id_generate(),
        'cursor' => $cursor, 'draft' => ['base_url' => '', 'invitation_code' => ''],
    ];
}

/** Render a prepared fragment without application shell or secret persistence.
 * @param array<string,mixed> $model Prepared view model.
 * @return string Escaped server-rendered markup.
 */
function admin_cooperative_html(array $model): string
{
    ob_start();
    try {
        \Gallery\Views\view_render_admin_cooperative_galleries($model);
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

/** Render a full page or an explicitly requested drawer fragment.
 * @param array<string,mixed> $model Prepared presentation data.
 * @return void Emits HTML with private no-store caching.
 */
function admin_cooperative_render(array $model): void
{
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Robots-Tag: noindex, nofollow');
    $panel = !empty($_GET['panel']) || !empty($_POST['panel']);
    if (!$panel) {
        Core\render_header($model['labels']['title']);
    }
    \Gallery\Views\view_render_admin_cooperative_galleries($model);
    if (!$panel) {
        Core\render_footer();
    }
}

/** Serve the authenticated friendship page without starting a pairing operation.
 * @return void Renders a page or a bounded refusal.
 */
function cms_admin_cooperative_galleries(): void
{
    Core\require_admin();
    Service\cooperative_pairing_require_enabled();
    if (Core\request_method() !== 'GET') {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'method_not_allowed'], 405);
        return;
    }
    $cursor = filter_var($_GET['after_id'] ?? 0, FILTER_VALIDATE_INT);
    if ($cursor === false || $cursor < 0) {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'invalid_cursor'], 400);
        return;
    }
    admin_cooperative_render(admin_cooperative_view_model($cursor));
}

/** Finish UI requests while preserving the existing pure JSON API contract.
 * @param array<string,mixed> $envelope Canonical mutation response.
 * @param int $status HTTP response status.
 * @return void Sends JSON or renders the non-JavaScript POST result directly.
 */
function admin_cooperative_ui_response(array $envelope, int $status = 200): void
{
    $labels = admin_cooperative_labels();
    if (!$envelope['ok']) {
        $reason = str_replace('cooperative.', '', (string) ($envelope['error_code'] ?? ''));
        $envelope['message'] = $envelope['error'] = admin_cooperative_error_text($reason, $labels);
    }
    $cursor = filter_var($_POST['after_id'] ?? 0, FILTER_VALIDATE_INT);
    $cursor = $cursor !== false && $cursor >= 0 ? $cursor : 0;
    if (Core\admin_wants_json()) {
        if ($envelope['ok']) {
            $envelope['panel_html'] = admin_cooperative_html(admin_cooperative_view_model($cursor, $envelope));
        }
        cooperative_pairing_json($envelope, $status);
        return;
    }
    http_response_code($status);
    $model = admin_cooperative_view_model($cursor, $envelope);
    if (!$envelope['ok']) {
        foreach (['base_url' => 1024, 'invitation_code' => Service\COOPERATIVE_PAIRING_CODE_MAX_BYTES] as $name => $limit) {
            $value = $_POST[$name] ?? '';
            $model['draft'][$name] = is_string($value) ? substr($value, 0, $limit) : '';
        }
        if (is_string($_POST['request_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/', $_POST['request_id']) === 1) {
            $model['request_id'] = $_POST['request_id'];
        }
    }
    admin_cooperative_render($model);
}
