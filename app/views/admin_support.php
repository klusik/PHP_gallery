<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/admin_support.php
 * Module Type: View Module
 * Purpose: Present temporary Admin support grants and invitation acceptance.
 * Responsibilities:
 *   - Render controller-prepared lifecycle metadata and CSRF-protected controls
 *   - Display the new invitation exactly once, in an owner-only no-store response
 *   - Immediately erase the invitation fragment before explicit POST consent
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Services\t;

/**
 * Render the permanent administrator's grant issuance and revocation page.
 *
 * @param array{
 *     csrf_html:string,
 *     form_url:string,
 *     grants:list<array<string,int|string|null>>,
 *     new_link:string,
 *     new_expiry:string,
 *     notice:string,
 *     error:string
 * } $model Prevalidated owner-only grant details and current response's link.
 * @return void Emits an Admin management page without saved bearer hashes.
 */
function view_render_admin_support(array $model): void
{
    render_header(t('admin.support.title', 'Temporary admin access'));
    echo '<section class="panel admin-support-workspace">';
    echo '<h1>' . e(t(
        'admin.support.title',
        'Temporary admin access',
    )) . '</h1>';
    echo '<p class="muted">' . e(t(
        'admin.support.description',
        'Create a single-use support invitation valid for 24 hours from creation. Only trusted recipients should receive it.',
    )) . '</p>';

    foreach (['notice', 'error'] as $messageType) {
        $message = (string) ($model[$messageType] ?? '');
        if ($message !== '') {
            echo '<div class="notice" role="status">'
                . e($message) . '</div>';
        }
    }

    $link = (string) ($model['new_link'] ?? '');
    if ($link !== '') {
        echo '<div class="panel" style="padding:1rem">';
        echo '<h2>' . e(t(
            'admin.support.new_link',
            'One-time invitation link',
        )) . '</h2>';
        echo '<p class="muted">' . e(t(
            'admin.support.secret_warning',
            'Anyone holding this unused link can accept temporary Admin access. Send it privately. It cannot be shown again.',
        )) . '</p>';
        echo '<label for="admin-support-link">'
            . e(t('admin.support.copy_label', 'Invitation URL'))
            . '</label>';
        echo '<div class="nav">';
        echo '<input id="admin-support-link" type="text" readonly '
            . 'spellcheck="false" autocomplete="off" '
            . 'style="width:min(100%,42rem)" value="'
            . e($link) . '">';
        echo '<button type="button" class="button secondary" '
            . 'id="admin-support-copy">'
            . e(t('admin.support.copy', 'Copy link'))
            . '</button></div>';
        echo '<p>' . e(t('admin.support.expires', 'Expires:'))
            . ' <strong>' . e((string) ($model['new_expiry'] ?? ''))
            . '</strong></p></div>';
        echo '<script>(function(){'
            . 'const b=document.getElementById("admin-support-copy");'
            . 'const i=document.getElementById("admin-support-link");'
            . 'if(!b||!i)return;'
            . 'b.addEventListener("click",async function(){'
            . 'i.select();'
            . 'try{await navigator.clipboard.writeText(i.value);}'
            . 'catch(_error){document.execCommand("copy");}'
            . '});'
            . '})();</script>';
    }

    echo '<form method="post" action="'
        . e((string) $model['form_url'])
        . '" class="admin-support-issue-form">';
    echo (string) $model['csrf_html'];
    echo '<input type="hidden" name="action" value="issue">';
    echo '<button type="submit" class="button">'
        . e(t('admin.support.issue', 'Generate support link'))
        . '</button></form>';

    echo '<h2>' . e(t(
        'admin.support.grants',
        'Recent support invitations',
    )) . '</h2>';
    $grants = (array) ($model['grants'] ?? []);
    if ($grants === []) {
        echo '<p class="muted">' . e(t(
            'admin.support.empty',
            'No support invitations have been issued.',
        )) . '</p>';
    } else {
        echo '<div style="overflow-x:auto"><table class="data-table">'
            . '<thead><tr>';
        foreach ([
            t('admin.support.id', 'Grant'),
            t('admin.support.state', 'Status'),
            t('admin.support.created_at', 'Created (UTC)'),
            t('admin.support.redeemed_at', 'First use (UTC)'),
            t('admin.support.expires_at', 'Expires (UTC)'),
            t('admin.support.actions', 'Actions'),
        ] as $heading) {
            echo '<th scope="col">' . e($heading) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($grants as $row) {
            $id = (int) ($row['id'] ?? 0);
            $state = (string) ($row['state'] ?? 'expired');
            $stateLabels = [
                'unused' => t('admin.support.state_unused', 'Unused'),
                'active' => t('admin.support.state_active', 'Active'),
                'expired' => t('admin.support.state_expired', 'Expired'),
                'revoked' => t('admin.support.state_revoked', 'Revoked'),
            ];
            echo '<tr><td>' . $id . '</td><td>'
                . e($stateLabels[$state] ?? $state)
                . '</td><td>' . e((string) ($row['created_at'] ?? ''))
                . '</td><td>' . e((string) ($row['redeemed_at'] ?? ''))
                . '</td><td>' . e((string) ($row['expires_at'] ?? ''))
                . '</td><td>';
            if (in_array($state, ['unused', 'active'], true)) {
                echo '<form method="post" action="'
                    . e((string) $model['form_url']) . '">';
                echo (string) $model['csrf_html'];
                echo '<input type="hidden" name="action" value="revoke">';
                echo '<input type="hidden" name="grant_id" value="'
                    . $id . '">';
                echo '<button class="button secondary" type="submit">'
                    . e(t('admin.support.revoke', 'Revoke now'))
                    . '</button></form>';
            } else {
                echo '&mdash;';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</section>';
    render_footer();
}

/**
 * Render explicit recipient acceptance and no-JavaScript fallback input.
 *
 * @param array{
 *     csrf_html:string,
 *     error:string,
 *     submit_url:string,
 *     owner_url:string
 * } $model Controller-prepared CSRF markup and safe route URLs.
 * @return void Emits a POST-only invitation exchange form.
 */
function view_render_admin_support_redeem(array $model): void
{
    render_header(t('admin.support.accept_title', 'Accept temporary access'));
    echo '<section class="panel" style="max-width:42rem;margin:auto">';
    echo '<h1>' . e(t(
        'admin.support.accept_title',
        'Accept temporary access',
    )) . '</h1>';
    echo '<p class="muted">' . e(t(
        'admin.support.accept_description',
        'This invitation grants restricted Admin troubleshooting for up to 24 hours from its original creation. The owner may revoke it at any time.',
    )) . '</p>';
    $error = (string) ($model['error'] ?? '');
    if ($error !== '') {
        echo '<div class="notice" role="alert">' . e($error) . '</div>';
    }
    echo '<form id="admin-support-accept" method="post" action="'
        . e((string) $model['submit_url']) . '">';
    echo (string) $model['csrf_html'];
    echo '<label for="support-invitation">'
        . e(t('admin.support.paste_token', 'Invitation token'))
        . '</label>';
    echo '<input type="password" id="support-invitation" name="support_access_token" '
        . 'required minlength="64" maxlength="64" '
        . 'pattern="[a-f0-9]{64}" autocomplete="off" '
        . 'spellcheck="false" style="width:100%">';
    echo '<p class="muted">' . e(t(
        'admin.support.fallback',
        'The token is normally filled from the invitation link. Without JavaScript, paste the 64-character value after #support=.',
    )) . '</p>';
    echo '<button type="submit" class="button">'
        . e(t('admin.support.accept', 'Accept support access'))
        . '</button></form>';
    echo '</section>';
    // Do not put the fragment in any network request, form action, log or
    // rewritten history entry. The only browser submission is explicit POST.
    echo '<script>(function(){ "use strict";'
        . 'const fragment=window.location.hash;'
        . 'if(fragment){window.history.replaceState(null,"",'
        . 'window.location.pathname+window.location.search);}'
        . 'const m=/^#support=([a-f0-9]{64})$/.exec(fragment);'
        . 'if(m){document.getElementById("support-invitation").value=m[1];}'
        . '})();</script>';
    render_footer();
}
