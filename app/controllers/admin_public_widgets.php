<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_public_widgets.php
 * Module Type: Controller
 * Purpose: Own isolated Admin widget CRUD without mutating the Theme form.
 * Responsibilities:
 *   - Require admin authentication and verify CSRF on all widget POST actions.
 *   - Use revision-checked services for editing, duplicating, deleting and ordering.
 *   - Retain rejected form drafts and prepare safe server-side Markdown previews.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

use Gallery\Services\PublicWidgetInvalidField;
use Throwable;
use function Gallery\Core\csrf_field;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\render_footer;
use function Gallery\Core\render_header;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_settings_url;
use function Gallery\Services\public_widget_admin_list;
use function Gallery\Services\public_widget_create;
use function Gallery\Services\public_widget_delete;
use function Gallery\Services\public_widget_duplicate;
use function Gallery\Services\public_widget_id;
use function Gallery\Services\public_widget_markdown_html;
use function Gallery\Services\public_widget_normalize;
use function Gallery\Services\public_widget_reorder;
use function Gallery\Services\public_widget_save;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_public_widgets;

require_once dirname(__DIR__) . '/services/public_content_widgets.php';
require_once dirname(__DIR__) . '/views/admin_public_widgets.php';

/**
 * Whitelist semantic fields before invoking the independent widget service.
 *
 * @param array<string,mixed> $post Untrusted widget editor form data.
 * @return array<string,mixed> Semantic content and placement without action/CSRF metadata.
 */
function admin_public_widget_submitted_draft(array $post): array
{
    $fields = [
        'title', 'content_md', 'status', 'page_scope', 'placement_mode',
        'flow_slot', 'floating_anchor', 'x_permille', 'y_permille',
        'width_px', 'sort_order', 'mobile_fallback', 'appearance', 'source_language',
    ];
    $draft = [];
    foreach ($fields as $field) {
        if (array_key_exists($field, $post)) {
            $draft[$field] = $post[$field];
        }
    }
    return $draft;
}

/**
 * Find an authorized editor row, resolving the literal new-draft sentinel.
 *
 * @param list<array<string,mixed>> $rows Bounded database rows for this administrator.
 * @param scalar|array<array-key,mixed>|object|resource|null $requested Selected GET/POST identifier or sentinel.
 * @return array<string,mixed>|null Matching stored row or a new-draft selection.
 */
function admin_public_widget_select(array $rows, mixed $requested): ?array
{
    if ($requested === 'new' || $requested === '' || $rows === []) {
        return null;
    }
    $id = $requested === null ? (string) ($rows[0]['widget_id'] ?? '') : public_widget_id($requested);
    foreach ($rows as $row) {
        if (($row['widget_id'] ?? '') === $id) {
            return $row;
        }
    }
    throw new PublicWidgetInvalidField('widget_id', 'The selected widget is no longer available.');
}

/**
 * Route independent widget edits through the existing authenticated Theme page.
 *
 * A read-only preview always follows CSRF validation and never invokes a model
 * write. All successful mutations follow post/redirect/get, preserving revision
 * guards against concurrent editors. Unauthorized routes never reach this action.
 *
 * @return void Renders an editor or redirects after a successful mutation.
 */
function cms_admin_public_widgets(): void
{
    require_admin();
    $notice = (string) (flash_message('admin_public_widget_notice') ?? '');
    $error = '';
    $errorField = '';
    $selection = $_GET['id'] ?? null;
    $draft = null;
    if (request_method() === 'POST') {
        verify_csrf();
        $action = is_string($_POST['widget_action'] ?? null) ? $_POST['widget_action'] : '';
        $wantsPreviewJson = $action === 'preview' && ($_POST['widget_preview_json'] ?? null) === '1';
        $selection = $_POST['widget_id'] ?? $selection;
        if ($selection === '') {
            $selection = 'new';
        }
        if (in_array($action, ['create', 'save', 'preview'], true)) {
            $draft = admin_public_widget_submitted_draft($_POST);
        }
        try {
            if ($action === 'create') {
                $id = public_widget_create((array) $draft);
                flash_message('admin_public_widget_notice', t('admin.widgets.created', 'Widget created.'));
                redirect_to(url_for('admin_theme', ['widgets' => '1', 'id' => $id]));
                return;
            }
            if ($action === 'save') {
                $id = public_widget_id($selection);
                public_widget_save($id, $_POST['revision'] ?? null, (array) $draft);
                flash_message('admin_public_widget_notice', t('admin.widgets.saved', 'Widget saved.'));
                redirect_to(url_for('admin_theme', ['widgets' => '1', 'id' => $id]));
                return;
            }
            if ($action === 'duplicate') {
                $id = public_widget_duplicate(public_widget_id($selection));
                flash_message('admin_public_widget_notice', t('admin.widgets.duplicated', 'An independent unpublished copy was created.'));
                redirect_to(url_for('admin_theme', ['widgets' => '1', 'id' => $id]));
                return;
            }
            if ($action === 'delete') {
                if (($_POST['confirm_delete'] ?? null) !== '1') {
                    throw new PublicWidgetInvalidField('confirm_delete', 'Confirm permanent deletion first.');
                }
                public_widget_delete(public_widget_id($selection), $_POST['revision'] ?? null);
                flash_message('admin_public_widget_notice', t('admin.widgets.deleted', 'Widget deleted.'));
                redirect_to(url_for('admin_theme', ['widgets' => '1', 'id' => 'new']));
                return;
            }
            if ($action === 'reorder') {
                $submitted = $_POST['ordering'] ?? null;
                if (!is_array($submitted) || !array_is_list($submitted)) {
                    throw new PublicWidgetInvalidField('sort_order', 'Submit a complete ordered widget list.');
                }
                $changes = [];
                foreach ($submitted as $item) {
                    if (!is_array($item)) {
                        throw new PublicWidgetInvalidField('sort_order', 'Invalid widget ordering input.');
                    }
                    $changes[] = [
                        'widget_id' => $item['widget_id'] ?? null,
                        'revision' => $item['revision'] ?? null,
                        'sort_order' => $item['sort_order'] ?? null,
                    ];
                }
                public_widget_reorder($changes);
                flash_message('admin_public_widget_notice', t('admin.widgets.reordered', 'Widget order saved.'));
                redirect_to(url_for('admin_theme', ['widgets' => '1']));
                return;
            }
            if ($action === 'preview') {
                $validated = public_widget_normalize((array) $draft);
                $notice = t('admin.widgets.preview_only', 'Preview only. No widget data was saved.');
                if ($wantsPreviewJson) {
                    header('Content-Type: application/json; charset=utf-8');
                    header('Cache-Control: no-store');
                    echo json_encode([
                        'ok' => true,
                        'html' => public_widget_markdown_html((string) $validated['content_md']),
                        'title' => (string) $validated['title'],
                        'appearance' => (string) $validated['appearance'],
                        'message' => $notice,
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    return;
                }
            } else {
                throw new PublicWidgetInvalidField('widget_action', 'Choose a supported widget action.');
            }
        } catch (PublicWidgetInvalidField $failure) {
            $errorField = $failure->field;
            $error = $failure->getMessage();
        } catch (Throwable) {
            $error = t('admin.widgets.storage_error', 'Widget storage is unavailable. Check database migrations and try again.');
        }
        if ($wantsPreviewJson && $error !== '') {
            http_response_code($errorField !== '' ? 422 : 503);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode(['ok' => false, 'message' => $error, 'field' => $errorField], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            return;
        }
    }
    try {
        $rows = public_widget_admin_list();
    } catch (Throwable) {
        $rows = [];
        $error = t('admin.widgets.storage_error', 'Widget storage is unavailable. Check database migrations and try again.');
    }
    $selected = null;
    try {
        $selected = admin_public_widget_select($rows, $selection);
    } catch (PublicWidgetInvalidField $failure) {
        $errorField = $failure->field;
        $error = $failure->getMessage();
    }
    if ($draft === null) {
        $draft = $selected ?? public_widget_normalize(['title' => '', 'content_md' => '']);
    }
    $content = is_string($draft['content_md'] ?? null) ? $draft['content_md'] : '';
    foreach ($rows as &$row) {
        $row['edit_url'] = url_for('admin_theme', [
            'widgets' => '1', 'id' => (string) ($row['widget_id'] ?? ''),
        ]);
    }
    unset($row);
    render_header(t('admin.widgets.title', 'Public content widgets'));
    view_render_admin_public_widgets([
        'rows' => $rows,
        'selected' => $selected,
        'draft' => $draft,
        'notice' => $notice,
        'error' => $error,
        'error_field' => $errorField,
        'preview_html' => public_widget_markdown_html($content),
        'csrf_html' => csrf_field(),
        'editor_url' => url_for('admin_theme', ['widgets' => '1']),
        'new_url' => url_for('admin_theme', ['widgets' => '1', 'id' => 'new']),
        'discard_url' => $selected === null
            ? url_for('admin_theme', ['widgets' => '1', 'id' => 'new'])
            : url_for('admin_theme', ['widgets' => '1', 'id' => (string) ($selected['widget_id'] ?? '')]),
        'theme_url' => url_for('admin_theme'),
        'settings_url' => admin_settings_url('appearance'),
    ]);
    render_footer();
}
