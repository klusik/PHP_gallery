<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_features.php
 * Module Type: View
 *
 * Purpose:
 *   Renders the Admin feature settings page from controller-prepared presentation data.
 *
 * Responsibilities:
 *   - Render dense feature tables, autosave controls and factory comparison
 *   - Render configured/effective state without querying feature policy
 *   - Keep request handling and feature registry lookups outside the view
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
 *   - This view consumes presentation-ready groups and labels only.
 *
 * Last Updated:
 *   2026-09-13
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;

/**
 * Render the compact feature workspace from controller-prepared labels and groups.
 *
 * @param array{title:string,description:string,endpoint:string,notice:string,save_error:string,summary:string,registry_revision:string,groups:list<FeatureGroup>,save_label:string,factory_label:string,factory_help:string,saving_label:string,saved_label:string,failed_label:string,reload_label:string} $viewModel Localized page model; FeatureGroup is documented on the group renderer.
 * @return void Emit the workspace and an explicit-save compatibility form for browsers without JavaScript.
 */
function view_render_admin_features_page(array $viewModel): void
{
    echo '<section class="admin-features" data-admin-features'
        . ' data-saving="' . e($viewModel['saving_label']) . '"'
        . ' data-saved="' . e($viewModel['saved_label']) . '"'
        . ' data-failed="' . e($viewModel['failed_label']) . '">';
    echo '<header class="admin-features-heading"><div><h1>'
        . e($viewModel['title']) . '</h1><p>'
        . e($viewModel['description']) . '</p></div></header>';
    echo '<form method="post" action="' . e($viewModel['endpoint'])
        . '" class="admin-feature-form">' . csrf_field()
        . '<input type="hidden" name="feature_registry_revision" value="'
        . e($viewModel['registry_revision']) . '">';
    echo '<div class="admin-features-toolbar"><span data-feature-summary>'
        . e($viewModel['summary']) . '</span>'
        . '<button type="submit" name="feature_action" value="factory_defaults"'
        . ' class="admin-features-reset" id="admin-features-reset" data-feature-reset title="'
        . e($viewModel['factory_help']) . '"><span aria-hidden="true">↺</span> '
        . e($viewModel['factory_label']) . '</button></div>';
    echo '<div class="admin-features-feedback"><span role="status"'
        . ' aria-live="polite" data-feature-feedback>'
        . e($viewModel['save_error'] ?: $viewModel['notice']) . '</span>'
        . '<button type="button" id="admin-features-reload" data-feature-reload hidden>'
        . e($viewModel['reload_label']) . '</button></div>';
    echo '<div data-feature-groups>';
    view_render_admin_feature_groups($viewModel['groups']);
    echo '</div><noscript><button type="submit">'
        . e($viewModel['save_label']) . '</button></noscript></form></section>';
}

/**
 * Render all authoritative groups for the initial page or an autosave response.
 *
 * @param list<FeatureGroup> $groups Prepared groups as documented by view_render_admin_feature_group().
 * @return void Emit all feature groups without querying policy or persistence.
 */
function view_render_admin_feature_groups(array $groups): void
{
    foreach ($groups as $group) {
        view_render_admin_feature_group($group);
    }
}

/**
 * Render a dense feature table and its configured-state group switch.
 *
 * @phpstan-type FeatureGroup array{key:string,label:string,description:string,features:list<FeatureRow>,editable_count:int,configured_count:int,toggle_label:string,columns:array{feature:string,factory:string}}
 * @param FeatureGroup $group Prepared group; FeatureRow is documented on the row renderer.
 * @return void Emit one labelled table with a native mixed-state group checkbox.
 */
function view_render_admin_feature_group(array $group): void
{
    $id = 'admin-feature-group-' . $group['key'];
    echo '<section class="admin-feature-group" id="' . e($id) . '">'
        . '<header class="admin-feature-group-heading">'
        . '<label class="admin-feature-switch admin-feature-group-switch">'
        . '<input type="checkbox" data-feature-group="' . e($group['key'])
        . '" id="' . e($id) . '-switch" aria-label="'
        . e($group['toggle_label']) . '" data-mixed="'
        . ($group['configured_count'] > 0
            && $group['configured_count'] < $group['editable_count'] ? '1' : '0')
        . '"' . ($group['configured_count'] === $group['editable_count']
            ? ' checked' : '')
        . ($group['editable_count'] === 0 ? ' disabled' : '')
        . '><span class="admin-feature-track" aria-hidden="true"></span>'
        . '</label><div class="admin-feature-group-title">'
        . '<h2 id="' . e($id) . '-title">'
        . e($group['label']) . '</h2></div>';
    view_render_admin_feature_help(
        $id . '-help',
        $group['label'],
        [$group['description']],
    );
    echo '<span class="admin-feature-group-count">'
        . $group['configured_count'] . '/' . $group['editable_count']
        . '</span></header><table aria-labelledby="' . e($id) . '-title">'
        . '<thead><tr><th scope="col">' . e($group['columns']['feature'])
        . '</th><th scope="col">' . e($group['columns']['factory'])
        . '</th></tr></thead><tbody>';
    foreach ($group['features'] as $feature) {
        view_render_admin_feature_card($feature);
    }
    echo '</tbody></table></section>';
}

/**
 * Render a configured switch, factory comparison, specialized link and health help.
 *
 * @phpstan-type FeatureRow array{key:string,label:string,description:string,factory:bool,factory_label:string,changed:bool,comparison_label:string,help_label:string,blocked_state:string,configured:bool,effective:bool,editable:bool,card_class:string,badges:list<string>,configured_state_label:string,effective_state_label:string,storage_source_label:string,schema_state_label:string,blocker_label:string,context_hint:string,settings_url:string,settings_panel:bool,settings_label:string,effective_state:string}
 * @param FeatureRow $feature Complete localized feature row supplied by the controller.
 * @return void Emit a single table row and an anchored native help popover.
 */
function view_render_admin_feature_card(array $feature): void
{
    $id = 'admin-feature-' . $feature['key'];
    echo '<tr class="admin-feature-row' . e($feature['card_class'])
        . '" data-feature-row="' . e($feature['key']) . '"><th scope="row">'
        . '<div class="admin-feature-name">';
    echo '<label class="admin-feature-switch"><input type="checkbox"'
        . ' name="enabled_features[]" value="' . e($feature['key'])
        . '" id="' . e($id) . '" aria-label="' . e($feature['label']) . '"'
        . ($feature['configured'] ? ' checked' : '')
        . ($feature['editable'] ? '' : ' disabled') . '>'
        . '<span class="admin-feature-track" aria-hidden="true"></span>'
        . '</label>';
    if ($feature['settings_url'] !== '') {
        $panelAttributes = $feature['settings_panel']
            ? ' data-gallery-side-panel-link'
                . ' data-admin-side-panel-workflow="cooperative_pairing"'
                . ' data-admin-side-panel-title="' . e($feature['label'])
                . '" data-gallery-side-panel-url="'
                . e($feature['settings_url'] . '&panel=1') . '"'
            : '';
        echo '<a class="admin-feature-settings-link" href="'
            . e($feature['settings_url']) . '"' . $panelAttributes
            . ' title="' . e($feature['settings_label']) . '">'
            . e($feature['label'])
            . '<span class="admin-feature-link-icon" aria-hidden="true">↗</span>'
            . '</a>';
    } else {
        echo '<label for="' . e($id) . '">' . e($feature['label']) . '</label>';
    }
    if ($feature['configured'] && !$feature['effective']) {
        echo '<span class="admin-feature-blocked" title="'
            . e($feature['blocker_label']) . '">'
            . e($feature['blocked_state']) . '</span>';
    }
    view_render_admin_feature_help($id . '-help', $feature['help_label'], [
        $feature['description'],
        $feature['configured_state_label'],
        $feature['effective_state_label'],
        $feature['blocker_label'],
        $feature['context_hint'],
        $feature['storage_source_label'],
        $feature['schema_state_label'],
        implode(' · ', $feature['badges']),
        $feature['key'],
    ]);
    echo '</div></th><td class="admin-feature-factory">'
        . '<span>' . e($feature['factory_label']) . '</span>'
        . '<small class="admin-feature-comparison'
        . ($feature['changed'] ? ' is-changed' : '') . '">'
        . e($feature['comparison_label']) . '</small></td>';
    echo '</tr>';
}

/**
 * Render accessible context help using the browser's light-dismiss popover.
 *
 * @param string $id Unique popover identifier paired with its trigger.
 * @param string $label Accessible trigger name and compact panel heading.
 * @param list<string> $details Prepared description, health and registry metadata; empty entries are omitted.
 * @return void Emit the plain question-mark trigger and translucent help content.
 */
function view_render_admin_feature_help(
    string $id,
    string $label,
    array $details,
): void {
    echo '<button type="button" class="admin-feature-help" popovertarget="'
        . e($id) . '" aria-controls="' . e($id) . '" aria-label="'
        . e($label) . '">?</button><div popover id="' . e($id)
        . '" class="admin-feature-help-panel"><strong>' . e($label)
        . '</strong>';
    foreach ($details as $detail) {
        if ($detail !== '') {
            echo '<p>' . e($detail) . '</p>';
        }
    }
    echo '</div>';
}
