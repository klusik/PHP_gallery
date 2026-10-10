<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/admin_public_widgets.php
 * Module Type: View
 * Purpose: Render the Admin widget management and preview workspace.
 * Responsibilities:
 *   - Present controller-prepared records without SQL or request globals.
 *   - Preserve explicit publication, deletion, ordering and preview controls.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Core\e;
use function Gallery\Services\t;

/**
 * Keep malformed form input inert instead of coercing arrays into strings.
 *
 * @param mixed $value Draft value that may be an untrusted array.
 * @return string Printable field text, or empty on invalid types.
 */
function view_public_widget_field(mixed $value): string
{
    return is_string($value) || is_int($value) ? (string) $value : '';
}

/**
 * Render a bounded enum selector without exposing raw CSS or DOM identifiers.
 *
 * @param string $name Known semantic widget field.
 * @param mixed $current Submitted value or persisted state.
 * @param array<string,string> $options Stable allowed values and human labels.
 * @return void Emits a labeled select with escaped option text.
 */
function view_public_widget_select(string $name, mixed $current, array $options): void
{
    $label = t('admin.widgets.field.' . $name, ucwords(str_replace('_', ' ', $name)));
    echo '<label for="public-widget-' . e($name) . '">' . e($label) . '<select id="public-widget-' . e($name) . '" name="' . e($name) . '" data-widget-field="' . e($name) . '">';
    foreach ($options as $value => $text) {
        echo '<option value="' . e($value) . '"' . ($current === $value ? ' selected' : '') . '>' . e($text) . '</option>';
    }
    echo '</select></label>';
}

/**
 * Render a separate, revision-aware widget workspace within Theme / Appearance.
 *
 * @param array<string,mixed> $model Prepared records, URLs, draft fields, notices, and safe preview HTML.
 * @return void Emits the independent widget editor and read-only preview.
 */
function view_render_admin_public_widgets(array $model): void
{
    $rows = (array) ($model['rows'] ?? []);
    $selected = is_array($model['selected'] ?? null) ? $model['selected'] : null;
    $draft = (array) ($model['draft'] ?? []);
    $editing = $selected !== null;
    $csrf = (string) ($model['csrf_html'] ?? '');
    $url = (string) ($model['editor_url'] ?? '');
    $id = $editing ? (string) ($selected['widget_id'] ?? '') : '';
    $revision = $editing ? (int) ($selected['revision'] ?? 0) : 0;

    echo '<section class="panel public-widgets-heading"><p class="admin-kicker">' . e(t('admin.widgets.kicker', 'Appearance / Widgets')) . '</p>';
    echo '<h1>' . e(t('admin.widgets.title', 'Public content widgets')) . '</h1>';
    echo '<p class="muted">' . e(t('admin.widgets.intro', 'Publish curated text, link collections and announcements. Widget changes are independent of Theme settings.')) . '</p>';
    echo '<nav class="bulk-row"><a class="button secondary" href="' . e((string) ($model['theme_url'] ?? '')) . '">' . e(t('admin.widgets.back_theme', 'Back to Theme')) . '</a>';
    echo '<a class="button secondary" href="' . e((string) ($model['settings_url'] ?? '')) . '">' . e(t('admin.widgets.settings', 'Appearance settings')) . '</a>';
    echo '<a class="button" href="' . e((string) ($model['new_url'] ?? '')) . '">' . e(t('admin.widgets.add', 'Add widget')) . '</a></nav></section>';
    if ((string) ($model['notice'] ?? '') !== '') {
        echo '<p class="success" role="status">' . e((string) $model['notice']) . '</p>';
    }
    if ((string) ($model['error'] ?? '') !== '') {
        echo '<p class="error" role="alert" data-widget-error-field="' . e((string) ($model['error_field'] ?? '')) . '">' . e((string) $model['error']) . '</p>';
    }
    echo '<div class="public-widgets-workspace" data-public-widgets-admin>';
    echo '<section class="panel public-widgets-list"><h2>' . e(t('admin.widgets.existing', 'Existing widgets')) . '</h2>';
    if ($rows === []) {
        echo '<p class="muted">' . e(t('admin.widgets.empty', 'No stored widgets yet. Public pages remain unchanged.')) . '</p>';
    } else {
        echo '<ol class="public-widgets-items">';
        foreach ($rows as $row) {
            $rowId = (string) ($row['widget_id'] ?? '');
            $name = trim((string) ($row['title'] ?? '')) ?: t('admin.widgets.untitled', 'Untitled widget');
            $status = (string) ($row['status'] ?? 'draft');
            echo '<li><a' . ($rowId === $id ? ' aria-current="page"' : '') . ' href="' . e((string) ($row['edit_url'] ?? '')) . '"><strong>' . e($name) . '</strong>';
            echo '<small>' . e(t('admin.widgets.state.' . $status, ucfirst($status))) . ' · ' . e((string) ($row['page_scope'] ?? 'home')) . ' · ' . e((string) ($row['placement_mode'] ?? 'flow')) . '</small></a></li>';
        }
        echo '</ol>';
        if (count($rows) > 1) {
            echo '<form action="' . e($url) . '" method="post" class="public-widgets-order-form">' . $csrf . '<fieldset><legend>' . e(t('admin.widgets.reorder', 'Widget order')) . '</legend>';
            foreach ($rows as $index => $row) {
                $pos = (int) $index;
                echo '<label>' . e(trim((string) ($row['title'] ?? '')) ?: t('admin.widgets.untitled', 'Untitled widget'));
                echo '<input type="hidden" name="ordering[' . $pos . '][widget_id]" value="' . e((string) ($row['widget_id'] ?? '')) . '">';
                echo '<input type="hidden" name="ordering[' . $pos . '][revision]" value="' . (int) ($row['revision'] ?? 0) . '">';
                echo '<input type="number" name="ordering[' . $pos . '][sort_order]" min="0" max="10000" value="' . ($pos * 10) . '" required></label>';
            }
            echo '</fieldset><button type="submit" name="widget_action" value="reorder">' . e(t('admin.widgets.save_order', 'Save order')) . '</button></form>';
        }
    }
    echo '</section>';
    echo '<section class="panel public-widgets-editor"><h2>' . e($editing ? t('admin.widgets.edit', 'Edit widget') : t('admin.widgets.create', 'New widget')) . '</h2>';
    echo '<form action="' . e($url) . '" method="post" class="public-widgets-form" data-public-widget-editor>' . $csrf;
    echo '<input type="hidden" name="widget_id" value="' . e($id) . '"><input type="hidden" name="revision" value="' . $revision . '">';
    echo '<label for="public-widget-title">' . e(t('admin.widgets.field.title', 'Title (optional)')) . '<input id="public-widget-title" name="title" type="text" maxlength="180" value="' . e(view_public_widget_field($draft['title'] ?? '')) . '"></label>';
    echo '<label for="public-widget-content">' . e(t('admin.widgets.field.content_md', 'Formatted text and links')) . '</label><div class="public-widgets-toolbar" role="toolbar" aria-label="' . e(t('admin.widgets.toolbar', 'Formatting tools')) . '">';
    foreach (['heading' => 'Heading', 'bold' => 'Bold', 'italic' => 'Italic', 'list' => 'Bulleted list', 'ordered' => 'Numbered list', 'link' => 'Link'] as $command => $fallback) {
        echo '<button type="button" class="secondary" data-widget-format="' . e($command) . '">' . e(t('admin.widgets.toolbar.' . $command, $fallback)) . '</button>';
    }
    echo '</div><textarea id="public-widget-content" name="content_md" data-widget-source maxlength="16384" rows="11">' . e(view_public_widget_field($draft['content_md'] ?? '')) . '</textarea>';
    echo '<p class="muted">' . e(t('admin.widgets.markdown_help', 'Supports headings, bold, italic, lists and custom-text Markdown links. HTML and scripts cannot run.')) . '</p>';
    view_public_widget_select('status', $draft['status'] ?? 'draft', [
        'draft' => t('admin.widgets.state.draft', 'Draft'),
        'published' => t('admin.widgets.state.published', 'Published'),
        'disabled' => t('admin.widgets.state.disabled', 'Disabled'),
    ]);
    view_public_widget_select('page_scope', $draft['page_scope'] ?? 'home', [
        'home' => t('admin.widgets.scope.home', 'Homepage'),
        'gallery' => t('admin.widgets.scope.gallery', 'Gallery pages'),
        'all' => t('admin.widgets.scope.all', 'Homepage and galleries'),
    ]);
    view_public_widget_select('placement_mode', $draft['placement_mode'] ?? 'flow', [
        'flow' => t('admin.widgets.mode.flow', 'In page'), 'floating' => t('admin.widgets.mode.floating', 'Floating'),
    ]);
    echo '<fieldset data-widget-flow-controls><legend>' . e(t('admin.widgets.flow_heading', 'In-page location')) . '</legend>';
    view_public_widget_select('flow_slot', $draft['flow_slot'] ?? 'home_after_grid', [
        'content_top' => 'Top of content', 'content_bottom' => 'Bottom of content',
        'left_rail' => 'Left rail', 'right_rail' => 'Right rail',
        'home_before_grid' => 'Homepage before grid', 'home_after_grid' => 'Homepage after grid',
        'footer' => 'Footer',
    ]);
    echo '</fieldset><fieldset data-widget-floating-controls><legend>' . e(t('admin.widgets.floating_heading', 'Floating anchor and coordinates')) . '</legend>';
    view_public_widget_select('floating_anchor', $draft['floating_anchor'] ?? 'bottom-right', [
        'top-left' => 'Top left', 'top-center' => 'Top center', 'top-right' => 'Top right',
        'middle-left' => 'Middle left', 'middle-right' => 'Middle right',
        'bottom-left' => 'Bottom left', 'bottom-center' => 'Bottom center',
        'bottom-right' => 'Bottom right', 'custom' => 'Custom X/Y',
    ]);
    foreach (['x_permille', 'y_permille'] as $axis) {
        echo '<label for="public-widget-' . $axis . '">' . e(t('admin.widgets.field.' . $axis, strtoupper(substr($axis, 0, 1)) . ' (0 to 1000)'));
        echo '<input id="public-widget-' . $axis . '" name="' . $axis . '" type="number" min="0" max="1000" value="' . e(view_public_widget_field($draft[$axis] ?? 900)) . '" required></label>';
    }
    echo '</fieldset><div class="public-widgets-dimensions">';
    foreach (['width_px' => [180, 480, 320, 'Panel width (px)'], 'sort_order' => [0, 10000, 0, 'Order within position']] as $field => $spec) {
        echo '<label for="public-widget-' . e($field) . '">' . e(t('admin.widgets.field.' . $field, $spec[3])) . '<input id="public-widget-' . e($field) . '" type="number" name="' . e($field) . '" min="' . $spec[0] . '" max="' . $spec[1] . '" value="' . e(view_public_widget_field($draft[$field] ?? $spec[2])) . '" required></label>';
    }
    echo '</div>';
    view_public_widget_select('mobile_fallback', $draft['mobile_fallback'] ?? 'flow', ['flow' => 'Accessible in-page fallback']);
    view_public_widget_select('appearance', $draft['appearance'] ?? 'card', ['card' => 'Card', 'minimal' => 'Minimal']);
    view_public_widget_select('source_language', $draft['source_language'] ?? 'en', ['en' => 'English', 'cs' => 'Čeština', 'de' => 'Deutsch', 'sv' => 'Svenska']);
    echo '<div class="public-widgets-actions"><button type="submit" name="widget_action" value="' . ($editing ? 'save' : 'create') . '">' . e($editing ? t('admin.widgets.save', 'Save widget') : t('admin.widgets.create_action', 'Create widget')) . '</button>';
    echo '<button type="submit" class="secondary" name="widget_action" value="preview" formnovalidate>' . e(t('admin.widgets.preview_action', 'Preview without saving')) . '</button></div>';
    if ($editing) {
        echo '<div class="public-widgets-danger"><button type="submit" name="widget_action" value="duplicate" class="secondary" formnovalidate>' . e(t('admin.widgets.duplicate', 'Duplicate saved version as draft')) . '</button>';
        echo '<label><input type="checkbox" name="confirm_delete" value="1">' . e(t('admin.widgets.confirm_delete', 'Confirm permanent deletion')) . '</label>';
        echo '<button type="submit" name="widget_action" value="delete" class="secondary" formnovalidate>' . e(t('admin.widgets.delete', 'Delete')) . '</button></div>';
    }
    echo '</form><section class="public-widgets-preview" data-widget-preview><h3>' . e(t('admin.widgets.preview_heading', 'Read-only content preview')) . '</h3>';
    echo '<p class="muted">' . e(t('admin.widgets.preview_hint', 'Safe server-side Markdown preview. Public-page placement is a separate integration stage.')) . '</p>';
    echo '<article class="public-content-widget public-content-widget--' . e(in_array((string) ($draft['appearance'] ?? 'card'), ['card', 'minimal'], true) ? (string) $draft['appearance'] : 'card') . '">';
    if (view_public_widget_field($draft['title'] ?? '') !== '') {
        echo '<h4 class="public-content-widget-title">' . e(view_public_widget_field($draft['title'])) . '</h4>';
    }
    echo '<div class="public-content-widget-body" data-widget-preview-body>' . (string) ($model['preview_html'] ?? '') . '</div></article></section>';
    echo '</section></div>';
}
