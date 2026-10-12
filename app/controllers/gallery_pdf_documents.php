<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/gallery_pdf_documents.php
 * Module Type: Controller
 * Purpose: Prepare protected gallery document actions for public and Admin views.
 * Responsibilities: Map trusted attachments to same-origin URLs and presentation data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Controllers;

/**
 * Prepare documents without exposing local paths or remote source URLs.
 * @param array<string,mixed> $gallery Authorized physical gallery with id and folder_path.
 * @param bool $editor Whether attachment management is being rendered in the editor.
 * @return array{gallery_id:int,admin:bool,editor:bool,action_url:string,csrf_html:string,operation_key:string,dpi:int,browser_config:string,language:string,documents:list<array{id:string,title:string,filename:string,size:int,hidden:bool,version:string,view_url:string,download_url:string}>} Safe gallery document presentation.
 */
function gallery_pdf_documents_view_model(array $gallery, bool $editor = false): array
{
    require_once __DIR__ . '/../services/gallery_pdf_documents.php';
    require_once __DIR__ . '/../services/simbrief_ofp_attachments.php';
    require_once __DIR__ . '/../services/gallery_pdf_settings.php';
    $id = (int) $gallery['id'];
    $base = rtrim(\Gallery\Core\request_script_base_path(), '/') . '/index.php';
    $documents = [];
    $user = \Gallery\Core\current_user();
    $admin = is_array($user) && ($user['role'] ?? '') === 'admin' && !\Gallery\Core\admin_anonymous_preview_active();
    try {
        $entries = \Gallery\Services\gallery_pdf_documents_list($gallery);
    } catch (\Throwable) {
        $entries = [];
    }
    foreach ($entries as $entry) {
        $hidden = (bool) $entry['hidden'];
        if ($hidden && !$admin) {
            continue;
        }
        $query = ['page' => 'gallery_ofp_pdf', 'id' => $id, 'document' => $entry['id']];
        $documents[] = [
            'id' => $entry['id'], 'title' => $entry['title'], 'filename' => $entry['filename'], 'size' => $entry['size'], 'hidden' => $hidden, 'version' => $entry['sha256'],
            'view_url' => $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            'download_url' => $base . '?' . http_build_query($query + ['download' => 1], '', '&', PHP_QUERY_RFC3986),
        ];
    }
    $ofpPath = \Gallery\Services\simbrief_ofp_local_pdf_path($gallery);
    $ofpHidden = \Gallery\Services\gallery_pdf_documents_hidden($gallery, 'ofp');
    if ($ofpPath !== null && (!$ofpHidden || $admin)) {
        $query = ['page' => 'gallery_ofp_pdf', 'id' => $id];
        $documents[] = ['id' => 'ofp', 'title' => 'SimBrief OFP', 'filename' => 'simbrief-ofp.pdf', 'size' => (int) @filesize($ofpPath), 'hidden' => $ofpHidden, 'version' => (string) @hash_file('sha256', $ofpPath),
            'view_url' => $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            'download_url' => $base . '?' . http_build_query($query + ['download' => 1], '', '&', PHP_QUERY_RFC3986)];
    }
    $controls = ['operation_key' => '', 'browser_config' => '{}'];
    if ($admin) {
        \Gallery\Core\cms_runtime_kernel()->load('domain-admin-uploads');
        $controls = gallery_pdf_documents_admin_controls();
    }
    return ['gallery_id' => $id, 'admin' => $admin, 'editor' => $editor,
        'action_url' => $base . '?' . http_build_query(['page' => 'admin_edit_gallery', 'id' => $id]),
        'csrf_html' => $admin ? \Gallery\Core\csrf_field() : '',
        'operation_key' => $controls['operation_key'],
        'dpi' => \Gallery\Services\gallery_pdf_render_dpi(),
        'browser_config' => $controls['browser_config'],
        'language' => \Gallery\Services\translation_active_language(),
        'documents' => $documents];
}

/**
 * Prepare upload controls only after the authenticated Admin runtime module loads.
 * @return array{operation_key:string,browser_config:string} Fresh intent identity and bounded uploader configuration.
 */
function gallery_pdf_documents_admin_controls(): array
{
    return ['operation_key' => \Gallery\Services\admin_operation_form_key(null),
        'browser_config' => (string) json_encode(\Gallery\Services\browser_upload_browser_config(), JSON_UNESCAPED_SLASHES)];
}

/**
 * Render the editor-owned document fragment after an in-place attachment mutation.
 * @param array<string,mixed> $gallery Authorized physical gallery.
 * @param bool $editor Whether the refreshed fragment includes editor-only attachment controls; defaults to true.
 * @return string Documents card HTML including delegated forms.
 */
function admin_gallery_pdf_documents_html(array $gallery, bool $editor = true): string
{
    require_once __DIR__ . '/../views/gallery_pdf_documents.php';
    ob_start();
    \Gallery\Views\view_render_gallery_pdf_documents(gallery_pdf_documents_view_model($gallery, $editor));
    return (string) ob_get_clean();
}
