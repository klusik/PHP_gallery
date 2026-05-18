<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/upload_automation.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles API-key based upload automation requests and admin key management.
 *
 * Responsibilities:
 *   - Accept authenticated upload automation POST requests
 *   - Reuse the existing gallery upload pipeline
 *   - Render a small gallery-scoped API-key management panel
 *   - Allow admins to rotate or revoke watcher API keys safely
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
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-16
 */

declare(strict_types=1);

/**
 * Upload automation controller model.
 *
 * The public upload route is authenticated by a gallery-scoped API key. Browser
 * admin sessions are used only for generating and revoking those keys.
 */

/**
 * Send a JSON response for the upload automation endpoint.
 */
function upload_automation_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
}

/**
 * Handle POST uploads from the Windows folder watcher app.
 */
function cms_upload_automation_upload(): void
{
    if (request_method() !== 'POST') {
        upload_automation_json(['ok' => false, 'error' => 'Method not allowed.'], 405);
        return;
    }

    if (!upload_automation_schema_ready()) {
        upload_automation_json(['ok' => false, 'error' => 'Upload automation is not installed. Run pending database migrations first.'], 503);
        return;
    }

    // $token stores the raw API key supplied by the watcher app.
    $token = upload_automation_request_token();
    // $tokenRow stores the database row that authorizes exactly one target gallery.
    $tokenRow = find_upload_automation_token($token);
    if (!$tokenRow) {
        admin_log_event('warning', 'upload_automation.unauthorized', 'Upload automation request used a missing or invalid API key.', [
            'remote_addr_present' => isset($_SERVER['REMOTE_ADDR']) && (string) $_SERVER['REMOTE_ADDR'] !== '',
            'user_agent_present' => isset($_SERVER['HTTP_USER_AGENT']) && (string) $_SERVER['HTTP_USER_AGENT'] !== '',
        ]);
        upload_automation_json(['ok' => false, 'error' => 'Invalid or revoked API key.'], 401);
        return;
    }

    // $galleryId stores the gallery scope embedded in the API key.
    $galleryId = (int) ($tokenRow['gallery_id'] ?? 0);
    // $gallery stores the target gallery. The Python app does not choose this independently.
    $gallery = find_gallery($galleryId, true) ?: find_gallery($galleryId);
    if (!$gallery) {
        upload_automation_json(['ok' => false, 'error' => 'The gallery assigned to this API key no longer exists.'], 404);
        return;
    }

    // $action stores the requested automation command. Revoke is allowed when the request is authenticated by the current API key.
    $action = (string) ($_POST['action'] ?? 'upload');
    if ($action === 'revoke') {
        $tokenId = (int) ($tokenRow['id'] ?? 0);
        if ($tokenId <= 0 || !revoke_gallery_upload_automation_token($galleryId, $tokenId)) {
            admin_log_event('error', 'upload_automation.revoke_failed', 'Upload automation API-key revocation failed.', [
                'token_id' => $tokenId,
                'gallery_id' => $galleryId,
            ]);
            upload_automation_json(['ok' => false, 'error' => 'API key could not be revoked.'], 422);
            return;
        }

        admin_log_event('info', 'upload_automation.token_revoked', 'Upload automation API key revoked through the companion app.', [
            'token_id' => $tokenId,
            'gallery_id' => $galleryId,
        ]);
        upload_automation_json([
            'ok' => true,
            'action' => 'revoke',
            'gallery_id' => $galleryId,
            'token_id' => $tokenId,
            'message' => 'API key revoked.',
        ]);
        return;
    }

    // $submittedGalleryId stores an optional client-side assertion, not the source of authority.
    $submittedGalleryId = (int) ($_POST['gallery_id'] ?? 0);
    if ($submittedGalleryId > 0 && $submittedGalleryId !== $galleryId) {
        admin_log_event('warning', 'upload_automation.gallery_mismatch', 'Upload automation request tried to use an API key for a different gallery.', [
            'token_id' => (int) $tokenRow['id'],
            'token_gallery_id' => $galleryId,
            'submitted_gallery_id' => $submittedGalleryId,
        ]);
        upload_automation_json(['ok' => false, 'error' => 'API key is not allowed to upload to the submitted gallery.'], 403);
        return;
    }

    try {
        // $files stores the multipart files in the same shape used by the browser upload form.
        $files = upload_automation_uploaded_files();
        // $imageClientIds stores optional request-local IDs aligned with images[].
        $imageClientIds = upload_automation_image_client_ids();
        // $clientThumbnailEntries stores optional thumbnails generated by the companion app.
        $clientThumbnailEntries = upload_automation_client_thumbnail_entries();
        // $entries stores validated upload entries returned by the existing upload validator.
        $entries = gallery_upload_entries($files);
        // $uploadResult stores the gallery mutation result produced under a
        // short gallery-scoped advisory lock. Manual bulk upload can run several
        // HTTP requests in parallel, but the existing scanner reconciles the
        // whole target folder. The lock prevents two PHP workers from inserting
        // the same discovered image row concurrently.
        $uploadResult = upload_automation_with_gallery_lock($galleryId, function () use ($galleryId, $gallery, $entries, $clientThumbnailEntries, $imageClientIds): array {
            // $stored stores the existing upload pipeline result after filesystem storage and image scan.
            $stored = store_uploaded_gallery_images($galleryId, $entries);
            // $clientThumbnailResult stores the thumbnails installed from the client request.
            $clientThumbnailResult = upload_automation_install_client_thumbnails($galleryId, $gallery, $clientThumbnailEntries, $imageClientIds, $stored);
            return [$stored, $clientThumbnailResult];
        });
        // $stored stores the existing upload pipeline result after filesystem storage and image scan.
        $stored = $uploadResult[0];
        // $clientThumbnailResult stores the thumbnails installed from the client request.
        $clientThumbnailResult = $uploadResult[1];
        // $createThumbnails stores whether the watcher asked the server to create derivatives immediately.
        $createThumbnails = upload_automation_bool($_POST['create_thumbnails'] ?? '1', true);
        // $thumbnails stores the count of generated thumbnails and display derivatives.
        $thumbnails = 0;
        // $thumbnailFailed stores derivative failures reported by the thumbnail service.
        $thumbnailFailed = 0;
        // $thumbnailErrors stores concise thumbnail diagnostics for JSON and logs.
        $thumbnailErrors = [];

        if ($createThumbnails) {
            foreach ((array) ($stored['image_ids'] ?? []) as $imageId) {
                // $image stores the image row created or updated by the scan.
                $image = find_image((int) $imageId);
                if (!$image) {
                    continue;
                }
                // $thumbnailResult stores per-image derivative generation counts.
                $thumbnailResult = create_image_thumbnails_result($image, $gallery);
                $thumbnails += (int) ($thumbnailResult['created'] ?? 0);
                $thumbnailFailed += (int) ($thumbnailResult['failed'] ?? 0);
                foreach ((array) ($thumbnailResult['errors'] ?? []) as $thumbnailError) {
                    $thumbnailErrors[] = (string) $thumbnailError;
                }
            }
        }

        mark_upload_automation_token_used((int) $tokenRow['id']);
        admin_log_event('info', 'upload_automation.images_uploaded', 'Upload automation stored images through the existing gallery upload pipeline.', [
            'token_id' => (int) $tokenRow['id'],
            'gallery_id' => $galleryId,
            'folder_path' => (string) ($gallery['folder_path'] ?? ''),
            'uploaded' => (int) ($stored['uploaded'] ?? 0),
            'scanned' => (int) ($stored['scanned'] ?? 0),
            'thumbnails' => $thumbnails,
            'thumbnail_failed' => $thumbnailFailed,
            'client_thumbnails_installed' => (int) ($clientThumbnailResult['installed'] ?? 0),
            'client_thumbnails_skipped' => (int) ($clientThumbnailResult['skipped'] ?? 0),
            'client_thumbnails_failed' => (int) ($clientThumbnailResult['failed'] ?? 0),
            'filenames' => array_values((array) ($stored['filenames'] ?? [])),
        ]);

        upload_automation_json([
            'ok' => true,
            'gallery_id' => $galleryId,
            'gallery_title' => (string) ($gallery['title'] ?? ''),
            'gallery_url' => gallery_public_url($gallery),
            'edit_url' => admin_edit_gallery_tab_url($galleryId, 'admin-edit-images'),
            'uploaded' => (int) ($stored['uploaded'] ?? 0),
            'scanned' => (int) ($stored['scanned'] ?? 0),
            'image_ids' => array_map('intval', (array) ($stored['image_ids'] ?? [])),
            'filenames' => array_values((array) ($stored['filenames'] ?? [])),
            'thumbnails' => $thumbnails,
            'thumbnail_failed' => $thumbnailFailed,
            'thumbnail_errors' => array_values(array_unique(array_filter($thumbnailErrors))),
            'client_thumbnails' => [
                'installed' => (int) ($clientThumbnailResult['installed'] ?? 0),
                'skipped' => (int) ($clientThumbnailResult['skipped'] ?? 0),
                'failed' => (int) ($clientThumbnailResult['failed'] ?? 0),
                'errors' => array_values((array) ($clientThumbnailResult['errors'] ?? [])),
            ],
        ]);
    } catch (Throwable $exception) {
        admin_log_event('error', 'upload_automation.upload_failed', 'Upload automation request failed.', [
            'token_id' => (int) $tokenRow['id'],
            'gallery_id' => $galleryId,
            'error' => $exception->getMessage(),
        ]);
        upload_automation_json(['ok' => false, 'error' => $exception->getMessage()], 422);
    }
}

/**
 * Generate or revoke a gallery-scoped upload automation API key from the admin UI.
 */
function cms_admin_upload_automation_token(): void
{
    // $wantsJson records side-panel and AJAX requests before any legacy redirect helper can emit HTML.
    $wantsJson = upload_automation_token_request_wants_json();

    if ($wantsJson) {
        // $user stores the current user for JSON requests. Anonymous or expired sessions must return JSON, not a login page.
        $user = current_user();
        if (!$user || (string) ($user['role'] ?? '') !== 'admin') {
            upload_automation_token_json_response([
                'ok' => false,
                'error' => t('auth.admin_required', 'Admin access is required.'),
            ], 403);
            return;
        }

        if (!upload_automation_token_csrf_valid()) {
            upload_automation_token_json_response([
                'ok' => false,
                'error' => t('security.invalid_csrf', 'Invalid CSRF token.'),
            ], 400);
            return;
        }
    } else {
        require_admin();
        verify_csrf();
    }

    // $galleryId stores the gallery whose automation keys are being changed.
    $galleryId = (int) ($_POST['gallery_id'] ?? 0);
    // $gallery stores the gallery being edited.
    $gallery = find_gallery($galleryId, true) ?: find_gallery($galleryId);
    if (!$gallery) {
        if ($wantsJson) {
            upload_automation_token_json_response([
                'ok' => false,
                'error' => t('admin.gallery_not_found', 'Gallery not found.'),
            ], 404);
            return;
        }
        cms_not_found();
        return;
    }

    // $action stores the requested token management action.
    $action = (string) ($_POST['action'] ?? 'create');
    // $tokenId stores the created or revoked database row for JSON callers.
    $tokenId = 0;
    // $notice stores the message returned to the caller after a mutation.
    $notice = '';
    // $actionOk records whether the mutation completed or failed before the response is emitted.
    $actionOk = true;

    try {
        if ($action === 'revoke') {
            // $tokenId stores the database row to revoke.
            $tokenId = (int) ($_POST['token_id'] ?? 0);
            if (!revoke_gallery_upload_automation_token($galleryId, $tokenId)) {
                throw new RuntimeException(t('upload_automation.error.revoke_failed', 'The selected API key could not be revoked.'));
            }
            admin_log_event('info', 'upload_automation.token_revoked', 'Admin revoked a gallery upload automation API key.', [
                'gallery_id' => $galleryId,
                'token_id' => $tokenId,
            ]);
            $notice = t('upload_automation.notice.revoked', 'Upload automation API key revoked.');
            flash_message('admin_notice', $notice);
        } else {
            // $user stores the current admin user that created the key.
            $user = current_user();
            // $created stores the new API key. The raw token is put into the session for one display only.
            $created = create_gallery_upload_automation_token($galleryId, $user ? (int) $user['id'] : null, (string) ($_POST['label'] ?? ''));
            $tokenId = (int) $created['id'];
            $_SESSION['upload_automation_new_token_' . $galleryId] = $created['token'];
            admin_log_event('info', 'upload_automation.token_created', 'Admin created a gallery upload automation API key.', [
                'gallery_id' => $galleryId,
                'token_id' => $tokenId,
                'label' => (string) $created['label'],
            ]);
            $notice = t('upload_automation.notice.created', 'Upload automation API key created. Copy it now. It will not be shown again.');
            flash_message('admin_notice', $notice);
        }
    } catch (Throwable $exception) {
        $actionOk = false;
        $notice = $exception->getMessage();
        admin_log_event('error', 'upload_automation.token_action_failed', 'Upload automation API-key management failed.', [
            'gallery_id' => $galleryId,
            'error' => $notice,
        ]);
        flash_message('admin_notice', $notice);
    }

    // $returnUrl stores the page or panel route that should be refreshed after the mutation.
    $returnUrl = upload_automation_token_return_url($galleryId);
    if ($wantsJson) {
        upload_automation_token_json_response([
            'ok' => $actionOk,
            'message' => $notice,
            'refresh_url' => $returnUrl,
            'gallery_id' => $galleryId,
            'action' => $action === 'revoke' ? 'revoke' : 'create',
            'token_id' => $tokenId,
        ], $actionOk ? 200 : 422);
        return;
    }

    redirect_to($returnUrl);
}

/**
 * Return whether the API-key mutation request expects a JSON response.
 *
 * This mirrors the shared admin JSON detector, but it is local to this
 * controller so the token endpoint can decide before legacy helpers have a
 * chance to redirect to an HTML login or error page.
 *
 * @return bool True when the request came from the side panel or another AJAX caller.
 */
function upload_automation_token_request_wants_json(): bool
{
    return !empty($_POST['ajax'])
        || !empty($_GET['ajax'])
        || !empty($_POST['panel'])
        || !empty($_GET['panel'])
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
}

/**
 * Return whether the submitted API-key form contains the current CSRF token.
 *
 * @return bool True when the submitted token matches the active session token.
 */
function upload_automation_token_csrf_valid(): bool
{
    // $token stores the submitted CSRF value from the API-key create or revoke form.
    $token = (string) ($_POST['csrf_token'] ?? '');
    return $token !== '' && hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $token);
}

/**
 * Emit a JSON response for side-panel API-key mutations and stop no further processing.
 *
 * @param array<string,mixed> $payload JSON-safe response payload.
 * @param int $status HTTP status code to send with the response.
 * @return void
 */
function upload_automation_token_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Resolve the post-action URL for upload automation API-key forms.
 *
 * @param int $galleryId Gallery id submitted by the token-management form.
 * @return string Safe same-site admin URL that preserves the API manager context.
 */
function upload_automation_token_return_url(int $galleryId): string
{
    // $fallbackUrl keeps legacy gallery-editor forms working when no explicit return URL was submitted.
    $fallbackUrl = admin_edit_gallery_tab_url($galleryId, 'admin-edit-api');
    // $returnUrl stores the current admin page submitted by newer API-manager forms.
    $returnUrl = trim((string) ($_POST['return_url'] ?? ''));
    if ($returnUrl !== '') {
        // $safeReturnUrl is same-site only and rejects login/setup targets through the existing redirect sanitizer.
        $safeReturnUrl = sanitize_login_return_target($returnUrl, $fallbackUrl);
        if (upload_automation_return_url_allowed($safeReturnUrl, $galleryId)) {
            return $safeReturnUrl;
        }
    }

    // $returnContext stores an explicit stable context for forms that should not map to gallery edit tabs.
    $returnContext = (string) ($_POST['return_context'] ?? '');
    if ($returnContext === 'api_manager') {
        return url_for('admin_api_manager');
    }

    // $returnTab stores the older gallery-editor tab target used by existing forms.
    $returnTab = admin_edit_gallery_tab_id((string) ($_POST['return_tab'] ?? '')) ?: 'admin-edit-api';
    return admin_edit_gallery_tab_url($galleryId, $returnTab);
}

/**
 * Return whether a token-management return URL is valid for API-manager workflows.
 *
 * @param string $url Same-site URL after base redirect sanitization.
 * @param int $galleryId Gallery id submitted by the token-management form.
 * @return bool True when the URL points back to the dedicated API manager or this gallery's API editor tab.
 */
function upload_automation_return_url_allowed(string $url, int $galleryId): bool
{
    // $parts stores the URL components used to validate the route without trusting the raw submitted string.
    $parts = parse_url($url);
    if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
        return false;
    }

    // $queryParams stores index.php route parameters when the app is using query-string routing.
    $queryParams = [];
    parse_str((string) ($parts['query'] ?? ''), $queryParams);
    // $page stores the front-controller page name.
    $page = (string) ($queryParams['page'] ?? '');
    if ($page === 'admin_api_manager') {
        return true;
    }

    if ($page !== 'admin_edit_gallery') {
        return false;
    }

    // $postedGalleryId stores the gallery id embedded in the return URL. It must match the form target.
    $postedGalleryId = (int) ($queryParams['id'] ?? 0);
    if ($postedGalleryId !== $galleryId) {
        return false;
    }

    // $tab stores the tab requested by the return URL. Empty is accepted for older URLs, otherwise require a valid tab.
    $tab = (string) ($queryParams['tab'] ?? '');
    return $tab === '' || admin_edit_gallery_tab_id($tab) !== '';
}

/**
 * Render gallery-scoped upload automation controls inside the image editor tab.
 */
function render_admin_gallery_upload_automation_panel(array $gallery, string $returnTab = 'admin-edit-api'): void
{
    // $galleryId stores the gallery that owns all shown API keys.
    $galleryId = (int) ($gallery['id'] ?? 0);
    if ($galleryId <= 0) {
        return;
    }

    echo '<section class="panel admin-upload-automation-panel">';
    echo '<div class="admin-upload-automation-head"><div><p class="admin-kicker">' . e(t('upload_automation.kicker', 'Automation')) . '</p><h3>' . e(t('upload_automation.title', 'Watched-folder upload API')) . '</h3><p class="muted">' . e(t('upload_automation.help', 'Generate a gallery-scoped API key for the Windows companion app. The key can upload only into this gallery and can be revoked at any time.')) . '</p></div></div>';

    if (!upload_automation_schema_ready()) {
        echo '<div class="notice">' . e(t('upload_automation.migration_required', 'Upload automation needs a pending database migration before API keys can be generated.')) . '</div>';
        echo '</section>';
        return;
    }

    // $newToken stores a raw API key generated in the previous POST request. It is shown once.
    $newToken = (string) ($_SESSION['upload_automation_new_token_' . $galleryId] ?? '');
    unset($_SESSION['upload_automation_new_token_' . $galleryId]);
    // $endpoint stores the absolute upload URL that the companion app can use.
    $endpoint = absolute_public_url(url_for('upload_automation_upload'));

    echo '<div class="admin-upload-automation-grid">';
    echo '<div class="admin-upload-automation-copy">';
    echo '<label><span>' . e(t('upload_automation.endpoint', 'Upload endpoint')) . '</span><input type="text" readonly value="' . e($endpoint) . '"></label>';
    echo '<p class="muted">' . e(t('upload_automation.endpoint_help', 'Use this endpoint with the generated API key in the Python watcher app. The clean URL /api/upload also works when URL rewriting is enabled.')) . '</p>';
    if ($newToken !== '') {
        echo '<label><span>' . e(t('upload_automation.new_key', 'New API key')) . '</span><textarea readonly rows="3" class="admin-upload-automation-key">' . e($newToken) . '</textarea></label>';
        echo '<div class="notice">' . e(t('upload_automation.copy_now', 'Copy this API key now. For security, only its hash is stored and the raw value will not be shown again.')) . '</div>';
    }
    echo '<form method="post" action="' . e(url_for('admin_upload_automation_token')) . '" class="admin-upload-automation-form" data-admin-upload-automation-token-form="1">' . csrf_field();
    echo '<input type="hidden" name="ajax" value="1">';
    echo '<input type="hidden" name="panel" value="1">';
    echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
    echo '<input type="hidden" name="return_tab" value="' . e($returnTab) . '">';
    echo '<input type="hidden" name="return_url" value="' . e(admin_edit_gallery_tab_url($galleryId, $returnTab)) . '">';
    echo '<input type="hidden" name="action" value="create">';
    echo '<label><span>' . e(t('upload_automation.label', 'Label')) . '</span><input type="text" name="label" value="Folder watcher" maxlength="190"></label>';
    echo '<button type="submit" class="button secondary">' . e(t('upload_automation.generate_key', 'Generate API key')) . '</button>';
    echo '</form>';
    echo '</div>';

    // $tokens stores active API keys that can still upload to this gallery.
    $tokens = gallery_upload_automation_tokens($galleryId);
    echo '<div class="admin-upload-automation-list">';
    echo '<h4>' . e(t('upload_automation.active_keys', 'Active API keys')) . '</h4>';
    if (!$tokens) {
        echo '<p class="muted">' . e(t('upload_automation.no_keys', 'No active upload automation keys exist for this gallery.')) . '</p>';
    } else {
        echo '<table><thead><tr><th>' . e(t('upload_automation.label', 'Label')) . '</th><th>' . e(t('upload_automation.created', 'Created')) . '</th><th>' . e(t('upload_automation.last_used', 'Last used')) . '</th><th>' . e(t('upload_automation.action', 'Action')) . '</th></tr></thead><tbody>';
        foreach ($tokens as $token) {
            echo '<tr>';
            echo '<td>' . e((string) ($token['label'] ?? 'Folder watcher')) . '</td>';
            echo '<td>' . e((string) ($token['created_at'] ?? '')) . '</td>';
            echo '<td>' . e((string) ($token['last_used_at'] ?? t('upload_automation.never', 'Never'))) . '</td>';
            echo '<td><form method="post" action="' . e(url_for('admin_upload_automation_token')) . '" class="inline-admin-form" data-admin-upload-automation-token-form="1">' . csrf_field();
            echo '<input type="hidden" name="ajax" value="1">';
            echo '<input type="hidden" name="panel" value="1">';
            echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
            echo '<input type="hidden" name="return_tab" value="' . e($returnTab) . '">';
            echo '<input type="hidden" name="return_url" value="' . e(admin_edit_gallery_tab_url($galleryId, $returnTab)) . '">';
            echo '<input type="hidden" name="action" value="revoke">';
            echo '<input type="hidden" name="token_id" value="' . (int) $token['id'] . '">';
            echo '<button type="submit" class="secondary danger inline-admin-action">' . e(t('upload_automation.revoke', 'Revoke')) . '</button>';
            echo '</form></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

/**
 * Render the admin-wide upload automation manager.
 */
function cms_admin_api_manager(): void
{
    require_admin();
    admin_render_profile_start('admin_api_manager');

    render_header(t('admin.upload_automation.manager_title', 'API manager'));
    echo '<section class="hero admin-dashboard-hero"><div><p class="admin-kicker">' . e(t('upload_automation.kicker', 'Automation')) . '</p><h1>' . e(t('admin.upload_automation.manager_title', 'API manager')) . '</h1><p class="muted">' . e(t('admin.upload_automation.manager_intro', 'Review every active upload automation API key across galleries. Gallery-scoped keys stay available in each gallery editor, and this page gives you a global view for auditing and revocation.')) . '</p></div><nav class="admin-hero-actions"><a class="button secondary" href="' . e(url_for('admin')) . '">' . e(t('admin.upload_automation.back_to_dashboard', 'Back to dashboard')) . '</a></nav></section>';

    $notice = (string) flash_message('admin_notice');
    if ($notice !== '') {
        echo '<div class="notice">' . e($notice) . '</div>';
    }

    echo '<section class="panel admin-upload-automation-manager">';
    echo '<div class="admin-tab-intro"><div><p class="admin-kicker">' . e(t('admin.upload_automation.active_keys_kicker', 'Active keys')) . '</p><h2>' . e(t('admin.upload_automation.active_keys_title', 'Active upload API keys')) . '</h2></div><p class="muted">' . e(t('admin.upload_automation.active_keys_help', 'Keys remain scoped to one gallery. Revoke a key here to disable upload access immediately.')) . '</p></div>';

    if (!upload_automation_schema_ready()) {
        echo '<div class="notice">' . e(t('upload_automation.migration_required', 'Upload automation needs a pending database migration before API keys can be generated.')) . '</div>';
        echo '</section>';
        render_footer();
        return;
    }

    $tokens = upload_automation_tokens_for_manager();
    if (!$tokens) {
        echo '<p class="muted">' . e(t('upload_automation.no_keys', 'No active upload automation keys exist for this gallery.')) . '</p>';
    } else {
        echo '<table class="admin-upload-automation-table"><thead><tr><th>' . e(t('upload_automation.gallery', 'Gallery')) . '</th><th>' . e(t('upload_automation.label', 'Label')) . '</th><th>' . e(t('upload_automation.created', 'Created')) . '</th><th>' . e(t('upload_automation.last_used', 'Last used')) . '</th><th>' . e(t('upload_automation.action', 'Action')) . '</th></tr></thead><tbody>';
        foreach ($tokens as $token) {
            $galleryId = (int) ($token['gallery_id'] ?? 0);
            $galleryTitle = (string) ($token['gallery_title'] ?? '');
            echo '<tr>';
            echo '<td><a href="' . e(admin_edit_gallery_tab_url($galleryId, 'admin-edit-api')) . '">' . e($galleryTitle !== '' ? $galleryTitle : ('#' . $galleryId)) . '</a></td>';
            echo '<td>' . e((string) ($token['label'] ?? 'Folder watcher')) . '</td>';
            echo '<td>' . e((string) ($token['created_at'] ?? '')) . '</td>';
            echo '<td>' . e((string) ($token['last_used_at'] ?? t('upload_automation.never', 'Never'))) . '</td>';
            echo '<td><form method="post" action="' . e(url_for('admin_upload_automation_token')) . '" class="inline-admin-form" data-admin-upload-automation-token-form="1">' . csrf_field();
            echo '<input type="hidden" name="gallery_id" value="' . $galleryId . '">';
            echo '<input type="hidden" name="return_context" value="api_manager">';
            echo '<input type="hidden" name="return_url" value="' . e(url_for('admin_api_manager')) . '">';
            echo '<input type="hidden" name="action" value="revoke">';
            echo '<input type="hidden" name="token_id" value="' . (int) $token['id'] . '">';
            echo '<button type="submit" class="secondary danger inline-admin-action">' . e(t('upload_automation.revoke', 'Revoke')) . '</button>';
            echo '</form></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    echo '</section>';
    render_footer();
}
