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
 * @param scalar|array<array-key,mixed>|object|resource|null $value Draft value that may be an untrusted array.
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
 * @param scalar|array<array-key,mixed>|object|resource|null $current Submitted value or persisted state.
 * @param array<string,string> $options Stable allowed values and human labels.
 * @return void Emits a labeled select with escaped option text.
 */
function view_public_widget_select(string $name, mixed $current, array $options): void
{
    $label = t('admin.widgets.field.' . $name, ucwords(str_replace('_', ' ', $name)));
    echo '<label for="public-widget-' . e($name) . '">' . e($label) . '<select id="public-widget-' . e($name) . '" name="' . e($name) . '" data-widget-field="' . e($name) . '">';
    foreach ($options as $value => $text) {
        if ($name === 'flow_slot') {
            $text = t('admin.widgets.slot.' . $value, $text);
        } elseif ($name === 'floating_anchor') {
            $text = t('admin.widgets.anchor.' . $value, $text);
        } elseif ($name === 'appearance') {
            $text = t('admin.widgets.appearance.' . $value, $text);
        } elseif ($name === 'mobile_fallback' && $value === 'flow') {
            $text = t('admin.widgets.mobile_flow', $text);
        }
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
    $browserLabels = [
        'loading' => t('admin.widgets.preview_while_loading', 'Rendering preview…'),
        'failure' => t('admin.widgets.preview_failure', 'Preview unavailable.'),
        'desktop' => t('admin.widgets.preview_position_desktop', 'Desktop'),
        'tablet' => t('admin.widgets.preview_position_tablet', 'Tablet'),
        'mobile' => t('admin.widgets.preview_position_mobile', 'Mobile'),
        'coordinates' => t('admin.widgets.preview_coordinates', 'Floating widget position preview. Click or use arrow keys to set coordinates.'),
        'stage_hint' => t('admin.widgets.preview_stage_hint', 'Choose a device. Click the preview or use arrow keys to position a floating widget.'),
        'mobile_hint' => t('admin.widgets.preview_mobile_hint', 'On mobile the widget falls back to accessible in-page content.'),
        'flow_hint' => t('admin.widgets.preview_flow_hint', 'An in-page widget follows the normal layout and selected content zone.'),
        'floating_hint' => t('admin.widgets.preview_floating_hint', 'Drag, click or use arrow keys to customize the floating position.'),
        'reset_position' => t('admin.widgets.reset_position', 'Reset floating position'),
        'preview_marker' => t('admin.widgets.preview_marker', 'Widget'),
        'page_label' => t('admin.widgets.preview_page_label', 'Placement warning page'),
        'page_home' => t('admin.widgets.preview_page_home', 'Homepage'),
        'page_gallery' => t('admin.widgets.preview_page_gallery', 'Gallery page'),
        'guide_header' => t('admin.widgets.preview_guide_header', 'Illustrative reserved header'),
        'guide_controls' => t('admin.widgets.preview_guide_controls', 'Illustrative fixed-controls area'),
        'peer_label' => t('admin.widgets.preview_peer_label', 'Another published widget'),
        'warning_fallback' => t('admin.widgets.preview_warning_fallback', 'This device uses the in-page fallback, not a fixed overlay.'),
        'warning_header' => t('admin.widgets.preview_warning_header', 'This position is near the header. The public placement solver may move the panel below navigation.'),
        'warning_edge' => t('admin.widgets.preview_warning_edge', 'Custom coordinates are near a viewport edge. The actual panel will be clamped or left in page flow.'),
        'warning_collision' => t('admin.widgets.preview_warning_collision', 'Another published widget uses a nearby position on this page. The public layout may move one panel or retain it in flow.'),
        'warning_limit' => t('admin.widgets.preview_warning_limit', 'Only two published floating panels can be active per page; additional panels remain in page flow.'),
        'warning_invalid' => t('admin.widgets.preview_warning_invalid', 'Choose numeric coordinates from 0 to 1000 before saving.'),
        'warning_disclaimer' => t('admin.widgets.preview_warning_disclaimer', 'Illustrative safety guides only. Actual Theme geometry and widget height determine final placement.'),
        'theme_loading' => t('admin.widgets.preview_theme_loading', 'Loading protected public-page Theme preview…'),
        'theme_unavailable' => t('admin.widgets.preview_theme_unavailable', 'The protected public-page preview is unavailable; the editor remains usable.'),
        'theme_gallery' => t('admin.widgets.preview_theme_gallery', 'Gallery Theme preview is unavailable; the editor remains usable.'),
        'theme_floating' => t('admin.widgets.preview_theme_floating', 'Floating placement uses measured public-page geometry when supported; otherwise the widget stays in page flow.'),
        'theme_ready' => t('admin.widgets.preview_theme_ready', 'Actual public Theme and widget position shown. Preview only: changes are not saved.'),
        'theme_close' => t('lightbox.close', 'Close'),
    ];
    $browserLabelsJson = json_encode($browserLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $publishedPeersJson = json_encode((array) ($model['published_peers'] ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
    echo '<form action="' . e($url) . '" method="post" class="public-widgets-form" data-public-widget-editor data-widget-i18n="' . e($browserLabelsJson) . '" data-widget-published-peers="' . e($publishedPeersJson) . '">' . $csrf;
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
    echo '<button type="submit" class="secondary" name="widget_action" value="preview" formnovalidate>' . e(t('admin.widgets.preview_action', 'Preview without saving')) . '</button>';
    echo '<a class="button secondary" href="' . e((string) ($model['discard_url'] ?? '')) . '">' . e(t('admin.widgets.discard', 'Discard unsaved changes')) . '</a></div>';
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
    $homePreviewUrl = (string) ($model['preview_home_url'] ?? '');
    $galleryPreviewUrl = (string) ($model['preview_gallery_url'] ?? '');
    echo '<section class="public-widgets-theme-preview" data-widget-theme-preview data-widget-preview-url="' . e($homePreviewUrl) . '" data-widget-preview-home-url="' . e($homePreviewUrl) . '" data-widget-preview-gallery-url="' . e($galleryPreviewUrl) . '">';
    echo '<h3>' . e(t('admin.widgets.preview_theme_title', 'Actual public-page Theme preview')) . '</h3>';
    echo '<p class="muted">' . e(t('admin.widgets.preview_theme_hint', 'The protected preview uses real public Home or Gallery markup and Theme CSS. Only sanitized draft content is inserted; no data is saved.')) . '</p>';
    echo '<p role="status" data-widget-theme-status></p><div class="public-widgets-theme-frame-wrap" data-widget-theme-frame-wrap hidden></div></section>';
    echo '</section></div>';
}
