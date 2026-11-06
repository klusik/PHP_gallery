<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/app_linking.php
 * Module Type: View
 * Purpose: Present the isolated native application linking flow.
 * Responsibilities:
 *   - Render a nonce-restricted claim page with explicit ordinary-Admin consent.
 *   - Present owner-scoped revocation without exposing credential material.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Services\t;

/**
 * Open the minimal isolated linking document with compact Theme surfaces.
 *
 * @param string $title Localized page title prepared for this document.
 * @param string $nonce CSP nonce produced for this HTTP response.
 * @param string $language Active language prepared by the controller.
 * @param array{ink:string,paper:string,panel:string,accent:string} $theme
 *     Sanitized active Theme colors prepared by the controller.
 * @return void Writes the document opening and feature-scoped style.
 */
function view_app_linking_open(
    string $title, string $nonce, string $language, array $theme,
): void {
    echo '<!doctype html><html lang="' . e($language)
        . '"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . '</title><style nonce="' . e($nonce) . '">';
    echo ':root{--ink:' . e($theme['ink']) . ';--paper:' . e($theme['paper'])
        . ';--panel:' . e($theme['panel']) . ';--accent:' . e($theme['accent'])
        . ';--line:color-mix(in srgb,var(--ink) 24%,transparent)}';
    echo 'body{margin:0;padding:24px;font:1rem system-ui,sans-serif;'
        . 'color:var(--ink);background:var(--paper)}'
        . 'main{max-width:34rem;margin:3rem auto;padding:1rem;'
        . 'background:color-mix(in srgb,var(--panel,#263744) 42%,transparent);'
        . 'border:1px solid var(--line,#52616c);border-radius:8px;'
        . 'backdrop-filter:blur(12px)}'
        . 'h1{font-size:1rem;margin:0 0 .65rem}'
        . 'p{line-height:1.45}strong,dd{overflow-wrap:anywhere}'
        . '.link-context{margin:.6rem 0;font-size:.875rem}'
        . '.link-context div{display:flex;flex-wrap:wrap;gap:.2rem .65rem;'
        . 'padding:.3rem 0;border-bottom:1px solid var(--line)}'
        . '.link-context dt{font-weight:650}.link-context dd{margin:0;min-width:0}'
        . '#consent:focus{outline:none}#consent p{margin:.6rem 0}'
        . '.link-client,.link-duration{font-size:.875rem}'
        . '.link-actions{display:flex;flex-wrap:wrap;justify-content:flex-end;'
        . 'gap:.5rem;margin-top:.85rem}'
        . '.link-actions button{min-height:28px;margin:0;padding:.2rem .7rem;'
        . 'font:inherit;font-size:.875rem}#status:empty{display:none}'
        . 'a{color:var(--accent);text-decoration:none}'
        . 'input{box-sizing:border-box;max-width:100%;color:inherit;'
        . 'background:transparent;border:1px solid var(--line);'
        . 'border-radius:5px;padding:.55rem}button{color:inherit;background:transparent;'
        . 'border:1px solid var(--line,#73818a);border-radius:5px;'
        . 'padding:.55rem .75rem;margin-right:.5rem;cursor:pointer}'
        . '#approve{border-color:var(--accent);'
        . 'background:color-mix(in srgb,var(--accent) 8%,transparent)}'
        . 'button:focus-visible{outline:2px solid var(--accent,#80d7eb);'
        . 'outline-offset:2px}@media(max-width:40rem){body{padding:12px}'
        . 'main{margin:1rem auto}}@media(max-width:40rem),(pointer:coarse){'
        . 'button,.link-actions button{min-height:44px}}';
    echo '</style></head><body><main>';
}

/**
 * Render automatic request binding and explicit consent without code entry.
 *
 * @param string $claimUrl Fixed local claim endpoint prepared by the controller.
 * @param string $consentUrl Fixed local consent endpoint prepared by the controller.
 * @param string $csrf Current Admin session CSRF token.
 * @param string $nonce CSP nonce produced for this HTTP response.
 * @param string $language Active language prepared by the controller.
 * @param array{ink:string,paper:string,panel:string,accent:string} $theme
 *     Sanitized active Theme colors prepared by the controller.
 * @param string $issuer Trusted installation origin and mount.
 * @param string $username Current ordinary Admin account display name.
 * @param array{request_handle?:string,pending?:array{ok:bool,attempt:string,scope:string}|null,access_lifetime?:int} $flow
 *     Non-authorizing browser handle, resumable consent and access lifetime
 *     in seconds, all prepared by the controller.
 * @return void Writes an isolated page without implicit form submission.
 */
function view_render_app_linking_start(
    string $claimUrl,
    string $consentUrl,
    string $csrf,
    string $nonce,
    string $language,
    array $theme,
    string $issuer,
    string $username,
    array $flow = [],
): void {
    $title = t('app_linking.start.title', 'Link PHP Gallery');
    view_app_linking_open($title, $nonce, $language, $theme);
    echo '<h1>' . e($title) . '</h1><dl class="link-context"><div><dt>'
        . e(t('app_linking.consent.installation', 'Installation')) . '</dt><dd>'
        . e($issuer) . '</dd></div><div><dt>'
        . e(t('app_linking.consent.account', 'Administrator')) . '</dt><dd>'
        . e($username) . '</dd></div></dl>';
    echo '<div id="linking" data-claim="' . e($claimUrl)
        . '" data-consent="' . e($consentUrl) . '" data-csrf="' . e($csrf)
        . '" data-request="' . e($flow['request_handle'] ?? '')
        . '" data-pending="' . e(json_encode(
            $flow['pending'] ?? null, JSON_THROW_ON_ERROR,
        ))
        . '" data-error="' . e(t('app_linking.start.failed',
            'The request could not be completed. Start again in the application.'))
        . '">';
    echo '<section id="consent" hidden tabindex="-1"><p class="link-client">'
        . e(t('app_linking.consent.client',
            'Request from PHP Gallery for Windows (unverified app).'))
        . '</p>';
    if (($flow['access_lifetime'] ?? 0) > 0) {
        $duration = sprintf(t('app_linking.consent.duration',
            'Access lasts up to %d minutes. Renewal requires separate approval.'),
            (int) ceil($flow['access_lifetime'] / 60),
        );
    }
    echo '<p id="scope-identity" hidden>' . e(t('app_linking.consent.scope',
            'This grants a short-lived identity check only. It does not grant gallery or upload access.'))
        . '</p><p id="scope-galleries" hidden>'
        . e(t('app_linking.consent.galleries_scope',
            'Read your account name and list galleries, including private and unpublished galleries.'))
        . '</p><p id="scope-upload" hidden>'
        . e(t('app_linking.consent.upload_scope',
            'Read your account name, list galleries, and upload images, including to private and unpublished galleries.'))
        . '</p><p id="scope-read" hidden>'
        . e(t('app_linking.consent.read_scope',
            'This permits listing all galleries and reading each gallery\'s stored visibility and access settings, including private and unpublished galleries. It does not permit changes.'))
        . '</p><p id="scope-read-upload" hidden>'
        . e(t('app_linking.consent.read_upload_scope',
            'This permits reading stored gallery settings and uploading images to all galleries, including private and unpublished ones. It does not permit changing gallery settings.'))
        . '</p><p id="scope-create" hidden>'
        . e(t('app_linking.consent.create_scope',
            'This permits listing all galleries, including private and unpublished ones, and creating new galleries. It does not permit reading stored settings or uploading images.'))
        . '</p><p id="scope-read-create" hidden>'
        . e(t('app_linking.consent.read_create_scope',
            'This permits listing all galleries, reading their stored access settings, and creating new galleries. It does not permit uploading images.'))
        . '</p><p id="scope-upload-create" hidden>'
        . e(t('app_linking.consent.upload_create_scope',
            'This permits listing all galleries, creating galleries and uploading images, including to private and unpublished galleries. It does not permit reading stored settings.'))
        . '</p><p id="scope-read-upload-create" hidden>'
        . e(t('app_linking.consent.read_upload_create_scope',
            'This permits listing all galleries, reading stored access settings, creating galleries and uploading images, including private and unpublished galleries.'))
        . '</p><p id="scope-read-visibility" hidden>'
        . e(t('app_linking.consent.read_visibility_scope',
            'This permits listing all galleries, reading stored visibility and access settings, and changing gallery visibility, including for private and unpublished galleries. It does not permit uploads or gallery creation.'))
        . '</p><p id="scope-read-upload-visibility" hidden>'
        . e(t('app_linking.consent.read_upload_visibility_scope',
            'This permits listing all galleries, reading stored visibility and access settings, changing gallery visibility, and uploading images, including to private and unpublished galleries. It does not permit gallery creation.'))
        . '</p><p id="scope-read-create-visibility" hidden>'
        . e(t('app_linking.consent.read_create_visibility_scope',
            'This permits listing all galleries, reading stored visibility and access settings, changing gallery visibility, and creating galleries, including private and unpublished galleries. It does not permit image uploads.'))
        . '</p><p id="scope-read-upload-create-visibility" hidden>'
        . e(t('app_linking.consent.read_upload_create_visibility_scope',
            'This permits listing all galleries, reading stored visibility and access settings, changing gallery visibility, creating galleries, and uploading images, including to private and unpublished galleries.'))
        . '</p><p id="scope-visibility-notice" hidden>'
        . e(t('app_linking.consent.visibility_notice',
            'Only stored gallery visibility can be changed. Other settings remain unchanged, and inherited access restrictions and other protections still apply.'))
        . '</p><p id="scope-search" hidden>'
        . e(t('app_linking.consent.search_scope',
            'Search those galleries by name, description and tags.'))
        . '</p><p id="scope-offline" hidden>'
        . e(t('app_linking.consent.offline_scope',
            'This also permits the app to renew access while the browser is closed, for up to 30 days and no more than seven days of inactivity. You can revoke the connection.'))
        . '</p><p id="scope-media" hidden>'
        . e(t('app_linking.consent.media_scope',
            'This permits browsing listed public galleries and their non-sensitive photos. Private, unpublished, unlisted, password-protected and NSFW content remains unavailable.'))
        . '</p><p id="scope-presentation" hidden>'
        . e(t('app_linking.consent.presentation_scope',
            'This also permits authorized slideshow and visible gallery music playback for listed public galleries. Private, unpublished, unlisted, password-protected and NSFW content remains unavailable.'))
        . '</p>';
    if (isset($duration)) {
        echo '<p class="link-duration">' . e($duration) . '</p>';
    }
    echo '<div class="link-actions"><button id="deny" type="button">'
        . e(t('app_linking.consent.cancel', 'Cancel')) . '</button>'
        . '<button id="approve" type="button">'
        . e(t('app_linking.consent.approve', 'Approve')) . '</button></div></section>';
    echo '<p id="status" role="status" aria-live="polite"></p></div><noscript>'
        . e(t('app_linking.start.javascript_required',
            'JavaScript is required to connect securely.'))
        . '</noscript><script nonce="' . e($nonce) . '">';
    echo <<<'JS'
(() => {
    'use strict';
    const root = document.getElementById('linking');
    const consent = document.getElementById('consent');
    const identityScope = document.getElementById('scope-identity');
    const galleriesScope = document.getElementById('scope-galleries');
    const uploadScope = document.getElementById('scope-upload');
    const readScope = document.getElementById('scope-read');
    const readUploadScope = document.getElementById('scope-read-upload');
    const createScope = document.getElementById('scope-create');
    const readCreateScope = document.getElementById('scope-read-create');
    const uploadCreateScope = document.getElementById('scope-upload-create');
    const readUploadCreateScope = document.getElementById('scope-read-upload-create');
    const readVisibilityScope = document.getElementById('scope-read-visibility');
    const readUploadVisibilityScope = document.getElementById('scope-read-upload-visibility');
    const readCreateVisibilityScope = document.getElementById('scope-read-create-visibility');
    const readUploadCreateVisibilityScope = document.getElementById('scope-read-upload-create-visibility');
    const visibilityNotice = document.getElementById('scope-visibility-notice');
    const searchScope = document.getElementById('scope-search');
    const offlineScope = document.getElementById('scope-offline');
    const mediaScope = document.getElementById('scope-media');
    const presentationScope = document.getElementById('scope-presentation');
    const approve = document.getElementById('approve');
    const deny = document.getElementById('deny');
    const status = document.getElementById('status');
    let attempt = '';
    const lock = () => { approve.disabled = deny.disabled = true; };
    const fail = () => { lock(); status.textContent = root.dataset.error; };
    const post = async (url, fields) => {
        if (navigator.serviceWorker?.controller) throw new Error('service worker');
        const target = new URL(url, location.href);
        if (target.origin !== location.origin) throw new Error('origin');
        const body = new URLSearchParams({csrf_token: root.dataset.csrf, ...fields});
        // Send Origin for the server check while omitting the Referer URL.
        const response = await fetch(target.href, {
            method: 'POST', mode: 'same-origin', credentials: 'same-origin',
            redirect: 'error', cache: 'no-store',
            referrer: '', referrerPolicy: 'same-origin',
            headers: {'Accept': 'application/json'}, body
        });
        if (!response.ok || !response.headers.get('content-type')
            ?.toLowerCase().startsWith('application/json')) throw new Error('response');
        return response.json();
    };
    const showConsent = result => {
        const baseScopes = ['link:identity', 'link:identity galleries:list',
            'link:identity galleries:list gallery:upload',
            'link:identity galleries:list gallery:read',
            'link:identity galleries:list gallery:read gallery:upload',
            'link:identity galleries:list galleries:create',
            'link:identity galleries:list gallery:read galleries:create',
            'link:identity galleries:list gallery:upload galleries:create',
            'link:identity galleries:list gallery:read gallery:upload galleries:create',
            'link:identity galleries:list gallery:read gallery:visibility',
            'link:identity galleries:list gallery:read gallery:upload gallery:visibility',
            'link:identity galleries:list gallery:read galleries:create gallery:visibility',
            'link:identity galleries:list gallery:read gallery:upload galleries:create gallery:visibility'];
        const requestedScope = result.scope;
        const search = typeof requestedScope === 'string' &&
            requestedScope.endsWith(' galleries:search');
        const scopeWithoutSearch = search
            ? requestedScope.slice(0, -' galleries:search'.length) : requestedScope;
        const offline = typeof requestedScope === 'string' &&
            requestedScope.endsWith(' offline_access');
        const scopeWithoutOffline = offline
            ? requestedScope.slice(0, -' offline_access'.length) : scopeWithoutSearch;
        const mediaSuffixes = [
            ' media:read presentation:read', ' media:read',
        ];
        const mediaSuffix = typeof scopeWithoutOffline === 'string'
            ? mediaSuffixes.find(suffix => scopeWithoutOffline.endsWith(suffix))
            : undefined;
        const baseScope = mediaSuffix
            ? scopeWithoutOffline.slice(0, -mediaSuffix.length)
            : scopeWithoutOffline;
        const mediaRequested = typeof mediaSuffix === 'string';
        const presentationRequested = mediaSuffix ===
            ' media:read presentation:read';
        if (result.ok !== true || typeof result.attempt !== 'string' ||
            !result.attempt || !baseScopes.includes(baseScope) ||
            (search && !['link:identity galleries:list',
                'link:identity galleries:list gallery:upload'].includes(scopeWithoutSearch)) ||
            (mediaRequested && (baseScope === 'link:identity' || offline)) ||
            requestedScope !== scopeWithoutOffline + (offline ? ' offline_access' : '')
                + (search ? ' galleries:search' : '')) {
            throw new Error('claim');
        }
        identityScope.hidden = baseScope !== 'link:identity';
        galleriesScope.hidden = baseScope !== 'link:identity galleries:list';
        uploadScope.hidden = baseScope !==
            'link:identity galleries:list gallery:upload';
        readScope.hidden = baseScope !==
            'link:identity galleries:list gallery:read';
        readUploadScope.hidden = baseScope !==
            'link:identity galleries:list gallery:read gallery:upload';
        createScope.hidden = baseScope !==
            'link:identity galleries:list galleries:create';
        readCreateScope.hidden = baseScope !==
            'link:identity galleries:list gallery:read galleries:create';
        uploadCreateScope.hidden = baseScope !==
            'link:identity galleries:list gallery:upload galleries:create';
        readUploadCreateScope.hidden = baseScope !==
            'link:identity galleries:list gallery:read gallery:upload galleries:create';
        readVisibilityScope.hidden = baseScope !==
            'link:identity galleries:list gallery:read gallery:visibility';
        readUploadVisibilityScope.hidden = baseScope !==
            'link:identity galleries:list gallery:read gallery:upload gallery:visibility';
        readCreateVisibilityScope.hidden = baseScope !==
            'link:identity galleries:list gallery:read galleries:create gallery:visibility';
        readUploadCreateVisibilityScope.hidden = baseScope !==
            'link:identity galleries:list gallery:read gallery:upload galleries:create gallery:visibility';
        visibilityNotice.hidden = ![
            'link:identity galleries:list gallery:read gallery:visibility',
            'link:identity galleries:list gallery:read gallery:upload gallery:visibility',
            'link:identity galleries:list gallery:read galleries:create gallery:visibility',
            'link:identity galleries:list gallery:read gallery:upload galleries:create gallery:visibility',
        ].includes(baseScope);
        offlineScope.hidden = !offline;
        searchScope.hidden = !search;
        mediaScope.hidden = !mediaRequested;
        presentationScope.hidden = !presentationRequested;
        attempt = result.attempt;
        consent.hidden = false;
        consent.focus();
    };
    const decide = async decision => {
        lock();
        try {
            const result = await post(root.dataset.consent, {attempt, decision});
            if (result.ok !== true) throw new Error('consent');
            const callback = new URL(result.callback);
            if (callback.protocol !== 'http:' ||
                !['127.0.0.1', '[::1]'].includes(callback.hostname) ||
                !callback.port || callback.username || callback.password || callback.hash) {
                throw new Error('callback');
            }
            location.replace(callback.href);
        } catch (_) { fail(); }
    };
    approve.addEventListener('click', () => decide('approve'));
    deny.addEventListener('click', () => decide('deny'));
    try {
        const pending = JSON.parse(root.dataset.pending);
        const handle = root.dataset.request;
        delete root.dataset.request;
        if (pending) showConsent(pending);
        else if (handle) post(root.dataset.claim, {request_handle: handle})
            .then(showConsent).catch(fail);
        else fail();
    } catch (_) { fail(); }
})();
JS;
    echo '</script></main></body></html>';
}

/**
 * Render one owner's bounded linking-grant list without credential material.
 *
 * @param string $action Installation-local fixed revocation POST URL.
 * @param string $csrf Existing Admin session CSRF value.
 * @param string $nonce CSP nonce produced for this HTTP response.
 * @param string $language Active language prepared by the controller.
 * @param array{ink:string,paper:string,panel:string,accent:string} $theme
 *     Sanitized active Theme colors prepared by the controller.
 * @param list<array{id:int,issuer:string,client:string,scope:string,created_at:string,
 *     expires_at:?string,status:string,revocable:bool}> $rows Owner-scoped
 *     display rows with UTC timestamps and prepared revocation availability.
 * @param bool $truncated Whether additional owner grants could not be displayed.
 * @return void Writes the list and explicit CSRF-protected revoke actions.
 */
function view_render_app_linking_devices(
    string $action,
    string $csrf,
    string $nonce,
    string $language,
    array $theme,
    array $rows,
    bool $truncated,
): void {
    $title = t('app_linking.devices.title', 'Connected applications');
    view_app_linking_open($title, $nonce, $language, $theme);
    echo '<h1>' . e($title) . '</h1><div id="devices" data-action="' . e($action)
        . '" data-csrf="' . e($csrf) . '" data-error="'
        . e(t('app_linking.devices.failed', 'Revocation could not be confirmed. Reload the page.'))
        . '">';
    echo '<p>' . e(t('app_linking.devices.pending_note',
        'Approved codes not yet exchanged are not shown and remain valid for up to 120 seconds. Revoking a grant does not cancel other pending attempts.'))
        . '</p>';
    if ($truncated) {
        echo '<p role="alert">' . e(t('app_linking.devices.truncated',
            'This list is incomplete. Additional grants are not shown.')) . '</p>';
    }
    if ($rows === []) {
        echo '<p>' . e(t('app_linking.devices.empty', 'No application links.'))
            . '</p>';
    }
    foreach ($rows as $row) {
        echo '<section><p><strong>' . e($row['client']) . '</strong><br>'
            . e(t('app_linking.consent.installation', 'Installation:')) . ' '
            . e($row['issuer']) . '<br>' . e($row['status']) . '<br>'
            . e(t('app_linking.devices.scope', 'Scope:')) . ' '
            . e($row['scope']) . '<br>'
            . e(t('app_linking.devices.created', 'Created (UTC):')) . ' '
            . e($row['created_at']);
        if ($row['expires_at'] !== null) {
            echo '<br>' . e(t('app_linking.devices.expires', 'Access expires (UTC):'))
                . ' ' . e($row['expires_at']);
        }
        echo '</p>';
        if ($row['revocable']) {
            echo '<button type="button" data-grant-id="'
                . e((string) $row['id']) . '">'
                . e(t('app_linking.devices.revoke', 'Revoke')) . '</button>';
        }
        echo '</section>';
    }
    echo '<p id="device-status" role="status" aria-live="polite"></p></div>';
    echo '<script nonce="' . e($nonce) . '">';
    echo <<<'JS'
(() => {
    'use strict';
    const root = document.getElementById('devices');
    root.addEventListener('click', async event => {
        const button = event.target.closest('button[data-grant-id]');
        if (!button || button.disabled) return;
        button.disabled = true;
        try {
            const target = new URL(root.dataset.action, location.href);
            if (target.origin !== location.origin) throw new Error('origin');
            // Keep the exact Origin available for the revocation check.
            const response = await fetch(target.href, {
                method: 'POST', mode: 'same-origin', credentials: 'same-origin',
                redirect: 'error', cache: 'no-store',
                referrer: '', referrerPolicy: 'same-origin',
                headers: {'Accept': 'application/json'},
                body: new URLSearchParams({csrf_token: root.dataset.csrf,
                    grant_id: button.dataset.grantId})
            });
            if (!response.ok || !response.headers.get('content-type')
                ?.toLowerCase().startsWith('application/json') ||
                (await response.json()).ok !== true) throw new Error('response');
            button.closest('section').remove();
        } catch (_) {
            document.getElementById('device-status').textContent = root.dataset.error;
        }
    });
})();
JS;
    echo '</script></main></body></html>';
}
