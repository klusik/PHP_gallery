<?php

/** Smart Gallery Admin CRUD, preview, public listing, and rating endpoints. */

declare(strict_types=1);

namespace Gallery\Controllers;

use InvalidArgumentException;
use Throwable;
use Gallery\Services\MutationSchemaUnavailableException;

use function Gallery\Core\csrf_field;
use function Gallery\Core\e;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\image_alt_text;
use function Gallery\Core\image_public_url;
use function Gallery\Services\find_gallery;
use function Gallery\Services\pagination_current_page;
use function Gallery\Services\pagination_grid_columns_class;
use function Gallery\Services\pagination_model;
use function Gallery\Services\render_pagination_controls;
use function Gallery\Services\smart_galleries_all;
use function Gallery\Services\smart_gallery_count_images;
use function Gallery\Services\smart_gallery_delete;
use function Gallery\Services\smart_gallery_duplicate;
use function Gallery\Services\smart_gallery_empty_rules;
use function Gallery\Services\smart_gallery_find;
use function Gallery\Services\smart_gallery_find_public;
use function Gallery\Services\smart_gallery_query_images;
use function Gallery\Services\smart_gallery_placement_galleries;
use function Gallery\Services\smart_gallery_remove_from_gallery;
use function Gallery\Services\smart_gallery_rule_catalog;
use function Gallery\Services\smart_gallery_rules_from_json;
use function Gallery\Services\smart_gallery_rules_from_search;
use function Gallery\Services\smart_gallery_save;
use function Gallery\Services\t;
use function Gallery\Services\thumbnail_bundle;
use function Gallery\Services\public_thumbnail_render_picture_html;
use function Gallery\Services\public_thumbnail_rendering_mode;
use function Gallery\Services\admin_tag_rows;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\current_votes_for_images;
use function Gallery\Services\gallery_voting_allowed;
use function Gallery\Services\public_image_display_title;
use function Gallery\Services\thumbnail_bundle_url;
use function Gallery\Services\tags_for_entities;
use function Gallery\Services\theme_lightbox_browsing_mode;

/** Render and process Smart Gallery administration. */
function cms_admin_smart_galleries(): void
{
    require_admin();
    $id = max(0, (int) ($_GET['id'] ?? 0));
    $selected = $id > 0 ? smart_gallery_find($id) : null;
    $error = '';
    $previewCount = null;
    if (request_method() === 'POST') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'save');
        try {
            if ($action === 'delete') {
                $deletedId = max(0, (int) ($_POST['id'] ?? 0));
                smart_gallery_delete($deletedId);
                admin_log_event('info', 'smart_gallery.deleted', 'Admin deleted a Smart Gallery definition.', ['action' => 'delete'], ['category' => 'gallery', 'subject_type' => 'smart_gallery', 'subject_id' => $deletedId]);
                flash_message('smart_gallery_notice', t('smart_gallery.deleted', 'Smart Gallery deleted.'));
                redirect_to(url_for('admin_smart_galleries'));
            }
            if ($action === 'duplicate') {
                $sourceId = max(0, (int) ($_POST['id'] ?? 0));
                $copy = smart_gallery_duplicate($sourceId);
                admin_log_event('info', 'smart_gallery.duplicated', 'Admin duplicated a Smart Gallery definition.', ['action' => 'duplicate', 'source_id' => $sourceId], ['category' => 'gallery', 'subject_type' => 'smart_gallery', 'subject_id' => (int) $copy['id']]);
                flash_message('smart_gallery_notice', t('smart_gallery.duplicated', 'A private disabled copy was created.'));
                redirect_to(url_for('admin_smart_galleries', ['id' => $copy['id']]));
            }
            if ($action === 'remove_placement') {
                $smartGalleryId = max(0, (int) ($_POST['id'] ?? 0));
                $galleryId = max(0, (int) ($_POST['gallery_id'] ?? 0));
                $removed = smart_gallery_remove_from_gallery($smartGalleryId, $galleryId);
                admin_log_event('info', 'smart_gallery.placement_removed', 'Admin removed one physical Smart Gallery placement.', ['action' => 'remove_placement', 'gallery_id' => $galleryId, 'removed' => $removed], ['category' => 'gallery', 'subject_type' => 'smart_gallery', 'subject_id' => $smartGalleryId]);
                flash_message('smart_gallery_notice', t('smart_gallery.hidden_from_gallery', 'Smart Gallery hidden from that physical gallery.'));
                redirect_to(url_for('admin_smart_galleries', ['id' => $smartGalleryId]));
            }
            $input = smart_gallery_admin_input();
            if ($action === 'preview') {
                $rules = smart_gallery_rules_from_json($input['rules_json']);
                $preview = $selected ?: ['rules_json' => ''];
                $preview = array_merge($preview, $input, ['rules_json' => json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                $previewCount = smart_gallery_count_images($preview, false);
                $selected = array_merge($preview, ['id' => $id]);
                admin_log_event('info', 'smart_gallery.previewed', 'Admin previewed Smart Gallery rules.', array_merge(smart_gallery_admin_log_context($input, 'preview'), ['matched_images' => $previewCount]), ['category' => 'gallery', 'subject_type' => 'smart_gallery', 'subject_id' => $id > 0 ? $id : null]);
            } else {
                $saveAction = $id > 0 ? 'update' : 'create';
                $selected = smart_gallery_save($input, $id);
                admin_log_event('info', 'smart_gallery.saved', 'Admin saved a Smart Gallery definition.', smart_gallery_admin_log_context($input, $saveAction), ['category' => 'gallery', 'subject_type' => 'smart_gallery', 'subject_id' => (int) $selected['id']]);
                flash_message('smart_gallery_notice', t('smart_gallery.saved', 'Smart Gallery saved.'));
                redirect_to(url_for('admin_smart_galleries', ['id' => $selected['id']]));
            }
        } catch (InvalidArgumentException $exception) {
            admin_log_event('warning', 'smart_gallery.validation_failed', 'Smart Gallery Admin action failed validation.', array_merge(smart_gallery_admin_log_context(smart_gallery_admin_input(), $action), ['reason' => substr($exception->getMessage(), 0, 240)]), ['category' => 'gallery', 'severity' => 'warning', 'subject_type' => 'smart_gallery', 'subject_id' => $id > 0 ? $id : null]);
            $error = $exception->getMessage();
            if (in_array($action, ['save', 'preview'], true)) $selected = array_merge($selected ?: [], smart_gallery_admin_input(), ['id' => $id]);
        } catch (MutationSchemaUnavailableException $exception) {
            admin_log_event('warning', 'smart_gallery.schema_unavailable', 'Smart Gallery Admin action was refused because required schema was unavailable.', array_merge(smart_gallery_admin_log_context(smart_gallery_admin_input(), $action), ['feature' => $exception->feature, 'schema_state' => $exception->state, 'operation' => $exception->operation]), ['category' => 'database', 'severity' => 'warning', 'subject_type' => 'smart_gallery', 'subject_id' => $id > 0 ? $id : null]);
            $error = $exception->getMessage();
            if (in_array($action, ['save', 'preview'], true)) $selected = array_merge($selected ?: [], smart_gallery_admin_input(), ['id' => $id]);
        } catch (Throwable $exception) {
            admin_log_event('error', 'smart_gallery.action_failed', 'Smart Gallery Admin action failed unexpectedly.', array_merge(smart_gallery_admin_log_context(smart_gallery_admin_input(), $action), ['exception_class' => get_class($exception), 'exception_code' => (string) $exception->getCode()]), ['category' => 'gallery', 'severity' => 'error', 'subject_type' => 'smart_gallery', 'subject_id' => $id > 0 ? $id : null]);
            $error = t('smart_gallery.unexpected_error', 'The Smart Gallery could not be saved. Check Admin Logs for the request diagnostic.');
            if (in_array($action, ['save', 'preview'], true)) $selected = array_merge($selected ?: [], smart_gallery_admin_input(), ['id' => $id]);
        }
    }
    if (!$selected && isset($_GET['from_search'])) {
        $selected = ['title' => '', 'slug' => '', 'description' => '', 'rules_json' => json_encode(smart_gallery_rules_from_search((string) $_GET['from_search'])), 'enabled' => 1, 'visibility' => 'private', 'placement_mode' => 'unlisted', 'sort_mode' => 'capture_date', 'sort_direction' => 'desc'];
    }
    render_header(t('smart_gallery.admin_title', 'Smart Galleries'));
    echo '<section class="hero"><div><p class="admin-kicker">' . e(t('smart_gallery.kicker', 'Saved dynamic collections')) . '</p><h1>' . e(t('smart_gallery.admin_title', 'Smart Galleries')) . '</h1><p class="muted">' . e(t('smart_gallery.intro', 'Images stay in their physical galleries. Membership changes immediately when metadata changes.')) . '</p></div><a class="button" href="' . e(url_for('admin_smart_galleries', ['new' => 1])) . '">' . e(t('smart_gallery.create', 'Create Smart Gallery')) . '</a></section>';
    $notice = flash_message('smart_gallery_notice');
    if ($notice) echo '<p class="success">' . e($notice) . '</p>';
    if ($error !== '') echo '<p class="error">' . e($error) . '</p>';
    echo '<div class="admin-smart-gallery-layout"><section class="panel"><h2>' . e(t('smart_gallery.existing', 'Existing Smart Galleries')) . '</h2>';
    $all = smart_galleries_all();
    if (!$all) echo '<p class="muted">' . e(t('smart_gallery.none', 'No Smart Galleries have been created.')) . '</p>';
    foreach ($all as $row) {
        echo '<a class="admin-smart-gallery-row" href="' . e(url_for('admin_smart_galleries', ['id' => $row['id']])) . '"><strong>' . e($row['title']) . '</strong><span>' . e($row['visibility'] === 'public' ? t('smart_gallery.public', 'Published') : t('smart_gallery.private', 'Private')) . ($row['enabled'] ? '' : ' · ' . e(t('smart_gallery.disabled', 'Disabled'))) . '</span></a>';
    }
    echo '</section><section class="panel">';
    if ($selected || isset($_GET['new'])) smart_gallery_render_editor($selected ?: [] , $previewCount); else echo '<h2>' . e(t('smart_gallery.select', 'Select a Smart Gallery or create a new one.')) . '</h2>';
    echo '</section></div>';
    render_footer();
}

/** Normalize the Smart Gallery editor POST payload. */
function smart_gallery_admin_input(): array
{
    return ['title' => (string) ($_POST['title'] ?? ''), 'slug' => (string) ($_POST['slug'] ?? ''), 'description' => (string) ($_POST['description'] ?? ''), 'rules_json' => (string) ($_POST['rules_json'] ?? ''), 'enabled' => isset($_POST['enabled']), 'visibility' => (string) ($_POST['visibility'] ?? 'private'), 'placement_mode' => (string) ($_POST['placement_mode'] ?? 'unlisted'), 'sort_mode' => (string) ($_POST['sort_mode'] ?? 'capture_date'), 'sort_direction' => (string) ($_POST['sort_direction'] ?? 'desc')];
}

/**
 * Build a bounded, value-free summary of one submitted Smart Gallery intent.
 *
 * Rule values and raw JSON are deliberately excluded because they may contain
 * private descriptions, filenames, AI search text, or other visitor data.
 */
function smart_gallery_admin_log_context(array $input, string $action): array
{
    $title = trim((string) ($input['title'] ?? ''));
    $summary = [
        'action' => preg_match('/^[a-z_]{1,32}$/D', $action) === 1 ? $action : 'unknown',
        'title' => function_exists('mb_substr') ? mb_substr($title, 0, 160, 'UTF-8') : substr($title, 0, 160),
        'slug_mode' => trim((string) ($input['slug'] ?? '')) === '' ? 'automatic' : 'custom',
        'enabled' => !empty($input['enabled']),
        'visibility' => in_array($input['visibility'] ?? '', ['private', 'public'], true) ? (string) $input['visibility'] : 'invalid',
        'placement_mode' => in_array($input['placement_mode'] ?? '', ['unlisted', 'root', 'gallery'], true) ? (string) $input['placement_mode'] : 'invalid',
        'rule_json_valid' => false,
        'condition_count' => 0,
        'group_counts' => ['AND' => 0, 'OR' => 0, 'NOT' => 0],
        'maximum_depth' => 0,
        'fields' => [],
        'operators' => [],
    ];
    $decoded = json_decode((string) ($input['rules_json'] ?? ''), true);
    if (!is_array($decoded) || !is_array($decoded['root'] ?? null)) {
        return $summary;
    }
    $summary['rule_json_valid'] = true;
    $stack = [[$decoded['root'], 0]];
    $visited = 0;
    while ($stack !== [] && $visited < 100) {
        [$node, $depth] = array_pop($stack);
        if (!is_array($node)) continue;
        $visited++;
        $summary['maximum_depth'] = max((int) $summary['maximum_depth'], (int) $depth);
        if (($node['type'] ?? '') === 'group') {
            $operator = strtoupper((string) ($node['operator'] ?? ''));
            if (array_key_exists($operator, $summary['group_counts'])) $summary['group_counts'][$operator]++;
            foreach (array_reverse((array) ($node['children'] ?? [])) as $child) $stack[] = [$child, $depth + 1];
            continue;
        }
        if (($node['type'] ?? '') !== 'condition') continue;
        $summary['condition_count']++;
        $field = (string) ($node['field'] ?? '');
        $operator = (string) ($node['operator'] ?? '');
        if (preg_match('/^[a-z0-9_]{1,64}$/D', $field) === 1) $summary['fields'][] = $field;
        if (preg_match('/^[a-z0-9_]{1,64}$/D', $operator) === 1) $summary['operators'][] = $operator;
    }
    $summary['fields'] = array_slice(array_values(array_unique($summary['fields'])), 0, 32);
    $summary['operators'] = array_slice(array_values(array_unique($summary['operators'])), 0, 32);
    return $summary;
}

/** Render the non-programmer rule-builder editor and standard POST fallback. */
function smart_gallery_render_editor(array $gallery, ?int $previewCount): void
{
    $rulesJson = (string) ($gallery['rules_json'] ?? json_encode(smart_gallery_empty_rules()));
    $catalog = smart_gallery_rule_catalog();
    foreach ($catalog as $field => &$definition) {
        $definition['label'] = t('smart_gallery.field.' . $field, ucfirst(str_replace('_', ' ', $field)));
        $definition['operator_labels'] = [];
        foreach ($definition['operators'] as $operator) {
            $definition['operator_labels'][$operator] = t('smart_gallery.operator.' . $operator, ucfirst(str_replace('_', ' ', $operator)));
        }
    }
    unset($definition);
    $tags = admin_tag_rows('name', 'asc');
    $galleries = \Gallery\Core\db()->query('SELECT id,title,folder_path FROM galleries ORDER BY folder_path,title')->fetchAll();
    echo '<form method="post" data-smart-gallery-editor data-smart-gallery-catalog="' . e(json_encode($catalog)) . '" data-smart-gallery-tags="' . e(json_encode($tags)) . '" data-smart-gallery-galleries="' . e(json_encode($galleries)) . '">' . csrf_field();
    echo '<label>' . e(t('smart_gallery.title', 'Title')) . '<input name="title" required value="' . e((string) ($gallery['title'] ?? '')) . '"></label>';
    echo '<label>' . e(t('smart_gallery.slug', 'Public URL slug')) . '<input name="slug" value="' . e((string) ($gallery['slug'] ?? '')) . '"><span class="muted">' . e(t('smart_gallery.slug_help', 'Leave blank to generate it automatically from the title.')) . '</span></label>';
    echo '<label>' . e(t('smart_gallery.description', 'Description')) . '<textarea name="description">' . e((string) ($gallery['description'] ?? '')) . '</textarea></label>';
    echo '<fieldset class="admin-smart-gallery-placement"><legend>' . e(t('smart_gallery.placement', 'Public placement')) . '</legend><label>' . e(t('smart_gallery.placement_mode', 'Show this Smart Gallery as')) . '<select name="placement_mode" data-smart-gallery-placement-mode>';
    foreach (['unlisted', 'root', 'gallery'] as $placementMode) {
        echo '<option value="' . e($placementMode) . '"' . (($gallery['placement_mode'] ?? 'unlisted') === $placementMode ? ' selected' : '') . '>' . e(t('smart_gallery.placement_' . $placementMode, match ($placementMode) { 'root' => 'Root gallery on the homepage', 'gallery' => 'Subgallery of a physical gallery', default => 'Unlisted (URL only)' })) . '</option>';
    }
    echo '</select></label><p class="muted">' . e(t('smart_gallery.placement_help', 'Root lists the Smart Gallery on the homepage. Subgallery mode lets you attach it beneath any number of physical galleries from each gallery editor.')) . '</p></fieldset>';
    echo '<div class="admin-smart-gallery-options"><label><input type="checkbox" name="enabled" value="1"' . (!array_key_exists('enabled', $gallery) || $gallery['enabled'] ? ' checked' : '') . '> ' . e(t('smart_gallery.enabled', 'Enabled')) . '</label><label>' . e(t('smart_gallery.visibility', 'Visibility')) . '<select name="visibility"><option value="private">' . e(t('smart_gallery.private', 'Private')) . '</option><option value="public"' . (($gallery['visibility'] ?? '') === 'public' ? ' selected' : '') . '>' . e(t('smart_gallery.public', 'Published')) . '</option></select></label><label>' . e(t('smart_gallery.sort', 'Sort')) . '<select name="sort_mode">';
    foreach (['capture_date', 'filename', 'created_at', 'title', 'rating', 'default'] as $mode) echo '<option value="' . e($mode) . '"' . (($gallery['sort_mode'] ?? 'capture_date') === $mode ? ' selected' : '') . '>' . e(t('smart_gallery.sort_' . $mode, ucfirst(str_replace('_', ' ', $mode)))) . '</option>';
    echo '</select></label><label>' . e(t('smart_gallery.direction', 'Direction')) . '<select name="sort_direction"><option value="desc">' . e(t('smart_gallery.desc', 'Descending')) . '</option><option value="asc"' . (($gallery['sort_direction'] ?? '') === 'asc' ? ' selected' : '') . '>' . e(t('smart_gallery.asc', 'Ascending')) . '</option></select></label></div>';
    echo '<input type="hidden" name="rules_json" value="' . e($rulesJson) . '" data-smart-gallery-rules><div class="smart-rule-builder" data-smart-rule-builder></div><noscript><p class="error">' . e(t('smart_gallery.javascript_required', 'JavaScript is required for the visual nested rule editor. Existing rules remain safe and can still be previewed or saved unchanged.')) . '</p></noscript>';
    if ($previewCount !== null) echo '<p class="notice">' . e(t('smart_gallery.preview_count', 'This Smart Gallery currently matches {count} images.', ['count' => $previewCount])) . '</p>';
    echo '<div class="button-row"><button name="action" value="preview" class="button secondary">' . e(t('smart_gallery.preview', 'Preview count')) . '</button><button name="action" value="save" class="button">' . e(t('smart_gallery.save', 'Save Smart Gallery')) . '</button></div></form>';
    if (!empty($gallery['id'])) {
        $placements = smart_gallery_placement_galleries((int) $gallery['id']);
        echo '<section class="admin-smart-gallery-placements"><h3>' . e(t('smart_gallery.used_in', 'Used in physical galleries')) . '</h3><p class="muted">' . e(t('smart_gallery.used_in_help', 'These galleries currently show this Smart Gallery as a subgallery. Removing one location does not affect the others.')) . '</p>';
        if ($placements === []) {
            echo '<p class="muted">' . e(t('smart_gallery.used_nowhere', 'This Smart Gallery is not currently attached beneath a physical gallery.')) . '</p>';
        } else {
            echo '<div class="admin-smart-gallery-placement-list">';
            foreach ($placements as $placement) {
                echo '<div class="admin-smart-gallery-placement-row"><a href="' . e(url_for('admin_edit_gallery', ['id' => (int) $placement['id']])) . '"><strong>' . e((string) $placement['title']) . '</strong><small>' . e((string) $placement['folder_path']) . '</small></a><form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '"><input type="hidden" name="gallery_id" value="' . (int) $placement['id'] . '"><button name="action" value="remove_placement" class="button secondary">' . e(t('smart_gallery.hide_from_here', 'Hide from here')) . '</button></form></div>';
            }
            echo '</div>';
        }
        echo '</section>';
        if (($gallery['visibility'] ?? '') === 'public' && !empty($gallery['enabled'])) {
            echo '<p><a class="button secondary" href="' . e(url_for('smart_gallery', ['slug' => $gallery['slug']])) . '">' . e(t('smart_gallery.open_public', 'Open published Smart Gallery')) . '</a></p>';
        }
        echo '<div class="button-row"><form method="post">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '"><button name="action" value="duplicate" class="button secondary">' . e(t('smart_gallery.duplicate', 'Duplicate')) . '</button></form><form method="post" onsubmit="return confirm(this.dataset.confirm)" data-confirm="' . e(t('smart_gallery.delete_confirm', 'Delete this Smart Gallery definition? Images and files will not be deleted.')) . '">' . csrf_field() . '<input type="hidden" name="id" value="' . (int) $gallery['id'] . '"><button name="action" value="delete" class="button danger">' . e(t('smart_gallery.delete', 'Delete')) . '</button></form></div>';
    }
}

/** Render a published Smart Gallery using physical-gallery media URLs and shared thumbnail cards. */
function cms_smart_gallery(): void
{
    $gallery = smart_gallery_find_public((string) ($_GET['slug'] ?? ''));
    if (!$gallery) { cms_not_found(); return; }
    try { $total = smart_gallery_count_images($gallery, true); } catch (InvalidArgumentException) { cms_not_found(); return; }
    $pagination = pagination_model($total, pagination_current_page('photo_page'), 4, 6, 'photo_page', ['page' => 'smart_gallery', 'slug' => $gallery['slug']]);
    $images = smart_gallery_query_images($gallery, true, (int) $pagination['limit'], (int) $pagination['offset']);
    $imageIds = array_map(static fn (array $image): int => (int) $image['id'], $images);
    $votes = current_votes_for_images($imageIds);
    $tags = tags_for_entities('image', $imageIds);
    render_header((string) $gallery['title']);
    echo '<section class="hero"><div><p class="admin-kicker">' . e(t('smart_gallery.public_kicker', 'Smart Gallery')) . '</p><h1>' . e($gallery['title']) . '</h1><p>' . e((string) $gallery['description']) . '</p><p class="muted">' . e(t('smart_gallery.dynamic_count', '{count} matching images', ['count' => $total])) . '</p></div></section>';
    render_pagination_controls($pagination, t('pagination.photo_pages', 'Photo pages'));
    $lightboxMode = theme_lightbox_browsing_mode();
    echo '<section class="grid gallery-image-grid' . e(pagination_grid_columns_class(['columns' => 4])) . '" data-gallery-image-list data-lightbox-config data-lightbox-total="' . count($images) . '" data-lightbox-browsing-mode="' . e($lightboxMode) . '">';
    foreach ($images as $index => $image) {
        $source = find_gallery((int) $image['gallery_id']);
        if (!$source) continue;
        $url = image_public_url($image, $source);
        $bundle = thumbnail_bundle($image);
        $title = public_image_display_title($image, $source);
        $mediaUrl = url_for('media', ['id' => $image['id']]);
        $previewUrl = thumbnail_bundle_url($bundle, 1600);
        $voting = gallery_voting_allowed($source);
        $attributes = lightbox_image_data_attributes($image, $source, $mediaUrl, $previewUrl, $url, $title, (int) ($image['score'] ?? 0), (int) ($votes[(int) $image['id']] ?? 0), null, 'data-lightbox-image', $voting, $index);
        echo '<article class="image-card" ' . $attributes . '><div class="image-stage"><a class="image-preview-link" href="' . e($url) . '">' . public_thumbnail_render_picture_html($image, 300, [300,600,800,960], '(max-width: 720px) 50vw, 25vw', image_alt_text($image, $source, $index + 1 + (int) $pagination['offset']), $index, $bundle, public_thumbnail_rendering_mode()) . '</a>';
        render_vote_form((int) $image['id'], (int) ($image['score'] ?? 0), (int) ($votes[(int) $image['id']] ?? 0), $voting);
        if ($title !== '' || trim((string) ($image['description'] ?? '')) !== '' || !empty($tags[(int) $image['id']])) {
            echo '<div class="image-meta image-meta-overlay">' . ($title !== '' ? '<h2>' . e($title) . '</h2>' : '') . (trim((string) ($image['description'] ?? '')) !== '' ? '<p>' . e($image['description']) . '</p>' : '');
            \Gallery\Controllers\render_tag_list($tags[(int) $image['id']] ?? []);
            echo '</div>';
        }
        echo '</div></article>';
    }
    echo '</section>';
    render_pagination_controls($pagination, t('pagination.photo_pages', 'Photo pages'));
    render_lightbox(true, false, '', (string) $gallery['title'], $lightboxMode);
    render_footer();
}
