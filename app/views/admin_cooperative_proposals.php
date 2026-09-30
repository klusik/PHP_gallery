<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/admin_cooperative_proposals.php
 * Module Type: View
 * Purpose: Render explicit album collaboration review from prepared presentation data.
 * Responsibilities: Escape participants and consent scope; delegate all actions to the shared panel workflow.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;

/** Render one explicit proposal action with the exact displayed revision and digest.
 * @param array<string,mixed> $model Prepared page model.
 * @param array<string,mixed> $row Prepared proposal row.
 * @param string $action Allowlisted action supplied by the review service.
 * @param string $peer Optional participant identity for a direct request.
 * @return void Emit an ordinary form enhanced by shared capture-phase delegation.
 */
function view_cooperative_proposal_action(array $model, array $row, string $action, string $peer = ''): void
{
    $label = $model['labels'][$action === 'refresh' ? 'refresh_peer' : $action];
    echo '<form method="post" action="' . e($model['action_url']) . '" data-cooperative-form data-cooperative-group="' . e($row['group_id']) . '" data-cooperative-action="' . e($action) . '">' . $model['csrf_html'];
    foreach (['cooperative_proposal_ui' => '1', 'action' => $action, 'after_id' => $model['cursor'],
        'group_id' => $row['group_id'], 'revision' => (string) $row['revision'], 'digest' => $row['digest'], 'peer_id' => $peer] as $key => $value) {
        echo '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
    }
    if ($action === 'email') { echo '<label>' . e($model['labels']['email_address']) . '<input type="email" name="email" required maxlength="254" autocomplete="email"></label>'; }
    echo '<button type="submit" class="secondary">' . e($label) . '</button></form>';
}

/** Render the proposal inbox without calling policy, storage or request helpers.
 * @param array<string,mixed> $model Prepared controller model.
 * @return void Emit escaped proposal cards and in-place navigation.
 */
function view_render_admin_cooperative_proposals(array $model): void
{
    $labels = $model['labels'];
    echo '<section class="cooperative-panel" data-cooperative-panel data-cooperative-error="' . e($labels['unavailable']) . '" data-cooperative-busy="' . e($labels['busy']) . '">';
    echo '<h1 tabindex="-1" data-cooperative-heading>' . e($labels['title']) . '</h1><p class="muted">' . e($labels['intro']) . '</p>';
    echo '<p role="status" aria-live="polite" data-cooperative-status>' . e($model['notice']) . '</p><p role="alert" data-cooperative-error-message>' . e($model['error']) . '</p>';
    view_cooperative_composition($model);
    echo '<p class="muted">' . e($labels['mail_help']) . '</p>';
    if ($model['rows'] === [] && $model['error'] === '') { echo '<p>' . e($labels['empty']) . '</p>'; }
    foreach ($model['rows'] as $row) {
        echo '<article class="cooperative-card"><h2>' . e($row['title']) . '</h2><p><strong>' . e($row['status']) . '</strong></p>';
        echo '<p>' . e($labels['own']) . ': ' . e($row['own']) . '</p>';
        if ($row['expires'] !== '') { echo '<p>' . e($labels['expires']) . ': ' . e($row['expires']) . '</p>'; }
        if ($row['authorization'] !== '') { echo '<p>' . e($row['authorization']) . '</p>'; }
        if ($row['waiting'] !== '') { echo '<p>' . e($labels['waiting']) . ': ' . e($row['waiting']) . '</p>'; }
        echo '<h3>' . e($labels['participants']) . '</h3><ul>';
        foreach ($row['participants'] as $participant) {
            echo '<li><strong>' . e($participant['label']) . '</strong><p>' . e($labels['album']) . ': <code>' . e($participant['album_id']) . '</code></p>';
            echo '<p>' . e($labels['permissions']) . ': ' . e($participant['permissions']) . '</p><p>' . e($participant['status']) . '</p>';
            if ($participant['observed'] !== '') { echo '<p class="muted">' . e($labels['observed']) . ': ' . e($participant['observed']) . '</p>'; }
            if ($participant['mail_accepted']) { echo '<p>' . e($labels['mail_accepted']) . '</p>'; }
            foreach ($participant['actions'] as $action) { view_cooperative_proposal_action($model, $row, $action, $participant['instance_id']); }
            echo '</li>';
        }
        echo '</ul>';
        if ($row['public_url'] !== '') { echo '<p><a href="' . e($row['public_url']) . '" target="_blank" rel="noopener">' . e($labels['open_shared']) . '</a></p>'; }
        if ($row['can_expand']) { view_cooperative_expansion($model, $row); }
        foreach ($row['actions'] as $action) { view_cooperative_proposal_action($model, $row, $action); }
        echo '</article>';
    }
    echo '<nav aria-label="' . e($labels['title']) . '">';
    foreach (['refresh_url' => 'refresh', 'first_url' => 'first', 'next_url' => 'next'] as $key => $label) {
        if ($model[$key] !== '') {
            echo '<a class="button secondary" href="' . e($model[$key]) . '" data-cooperative-refresh>' . e($labels[$label]) . '</a> ';
        }
    }
    echo '<a class="button secondary" href="' . e($model['friendships_url']) . '" data-gallery-side-panel-link data-admin-side-panel-workflow="cooperative_pairing" data-admin-side-panel-title="' . e($labels['friendships']) . '" data-gallery-side-panel-url="' . e($model['friendships_url'] . '&panel=1') . '">' . e($labels['friendships']) . '</a>';
    echo '</nav></section>';
}

/** Render source selection and non-authorizing reference exchange forms.
 * @param array<string,mixed> $model Prepared picker, translated labels and retained intent.
 * @return void Emit bounded forms without discovering source policy in the view.
 */
function view_cooperative_composition(array $model): void
{
    $labels = $model['labels'];
    echo '<div class="cooperative-card"><h2>' . e($labels['compose']) . '</h2><p>' . e($labels['compose_help']) . '</p>';
    foreach (['reference' => 'reference', 'compose' => 'proposal'] as $action => $draft) {
        echo '<form method="post" action="' . e($model['action_url']) . '" data-cooperative-form data-cooperative-draft="' . e($draft) . '">' . $model['csrf_html'];
        echo '<fieldset' . (!$model['source_ready'] ? ' disabled' : '') . '>';
        foreach (['action' => $action, 'cooperative_proposal_ui' => '1', 'after_id' => $model['cursor'], 'album_after' => (string) $model['album_cursor']] as $key => $value) {
            echo '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
        }
        echo '<label>' . e($labels['choose_album']) . '<select name="gallery_id" required data-cooperative-retain><option value="">—</option>';
        foreach ($model['sources'] as $source) {
            echo '<option value="' . (int) $source['gallery_id'] . '"' . (!$source['eligible'] ? ' disabled' : '')
                . ($source['gallery_id'] === $model['source_selected'] ? ' selected' : '') . '>' . e($source['title'])
                . (!$source['eligible'] ? ' (' . e($labels['missing_album']) . ')' : '') . '</option>';
        }
        echo '</select></label>';
        echo '<label><input type="checkbox" name="photos" value="1" data-cooperative-retain' . ($model['composition_photos'] ? ' checked' : '') . '>' . e($labels['photos']) . '</label>';
        if ($action === 'compose') {
            echo '<input type="hidden" name="request_id" value="' . e($model['composition_request']) . '" data-cooperative-retain>';
            echo '<label>' . e($labels['references']) . '<textarea name="references" rows="4" maxlength="16384" required autocomplete="off" spellcheck="false" data-cooperative-retain>' . e($model['composition_references']) . '</textarea></label>';
        }
        echo '<button type="submit">' . e($labels[$action]) . '</button></fieldset></form>';
    }
    if (!$model['source_ready']) { echo '<p role="alert">' . e($labels['unavailable']) . '</p>'; }
    echo '<label>' . e($labels['reference_result']) . '<textarea readonly name="reference_code" rows="3" autocomplete="off" spellcheck="false">' . e($model['reference_code']) . '</textarea></label>';
    foreach (['source_first' => 'first_albums', 'source_next' => 'next_albums'] as $key => $label) {
        if ($model[$key] !== '') { echo '<a class="button secondary" data-cooperative-refresh href="' . e($model[$key]) . '">' . e($labels[$label]) . '</a> '; }
    }
    echo '</div>';
}

/** Render a retryable, explicitly new proposal for one extra participant.
 * @param array<string,mixed> $model Prepared panel model.
 * @param array<string,mixed> $row Existing active collaboration.
 * @return void Emit a draft-preserving expansion form.
 */
function view_cooperative_expansion(array $model, array $row): void
{
    echo '<details><summary>' . e($model['labels']['expand']) . '</summary><p>' . e($model['labels']['expand_help']) . '</p>';
    echo '<form method="post" action="' . e($model['action_url']) . '" data-cooperative-form data-cooperative-draft="expand-' . e($row['group_id']) . '">' . $model['csrf_html'];
    foreach (['action' => 'expand', 'cooperative_proposal_ui' => '1', 'group_id' => $row['group_id'],
        'revision' => (string) $row['revision'], 'request_id' => $row['expansion_request']] as $key => $value) {
        echo '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '"' . ($key === 'request_id' ? ' data-cooperative-retain' : '') . '>';
    }
    echo '<label>' . e($model['labels']['new_reference']) . '<textarea name="reference" required maxlength="512" data-cooperative-retain>' . e($row['expansion_reference']) . '</textarea></label>';
    echo '<button type="submit">' . e($model['labels']['expand']) . '</button></form></details>';
}
