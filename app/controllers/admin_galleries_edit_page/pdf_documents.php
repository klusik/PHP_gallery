<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_galleries_edit_page/pdf_documents.php
 * Module Type: Controller Part
 * Purpose: Handle explicit gallery PDF attachment and browser conversion actions.
 * Responsibilities: Normalize authenticated input and return canonical mutations.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Notes: Loaded only by admin_galleries_edit_page.php.
 */
declare(strict_types=1);
namespace Gallery\Controllers;

/**
 * Dispatch one administrator and CSRF-guarded gallery PDF mutation.
 * @param array{id:int,folder_path:string} $gallery Authorized physical source.
 * @return void Emit canonical AJAX results or the direct-page fallback redirect.
 */
function admin_edit_gallery_handle_pdf_action(array $gallery): void
{
    require_once __DIR__ . '/../../services/gallery_pdf_documents.php';
    require_once __DIR__ . '/../../services/gallery_pdf_conversion.php';
    require_once __DIR__ . '/../../services/simbrief_ofp_attachments.php';
    $ajax = admin_wants_json();
    $action = (string) ($_POST['pdf_action'] ?? '');
    $galleryId = (int) $gallery['id'];
    $editUrl = \Gallery\Core\url_for('admin_edit_gallery', [
        'id' => $galleryId,
        'tab' => 'admin-edit-documents',
    ]);
    try {
        if ((int) ($_POST['id'] ?? 0) !== $galleryId) {
            throw new \RuntimeException(
                'The source gallery changed. Reload the editor.',
            );
        }
        $documentId = (string) ($_POST['document_id'] ?? '');
        $result = [];
        if (in_array($action, ['upload', 'replace'], true)) {
            $upload = $_FILES['pdf_file'] ?? null;
            if (
                !is_array($upload)
                || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !is_string($upload['tmp_name'] ?? null)
                || !is_uploaded_file($upload['tmp_name'])
            ) {
                throw new \RuntimeException(
                    'Select a PDF of up to 25 MiB and check PHP upload limits.',
                );
            }
            $title = trim((string) ($_POST['pdf_title'] ?? ''));
            if ($title === '') {
                $previous = $action === 'replace'
                    ? \Gallery\Services\gallery_pdf_documents_read(
                        $gallery,
                        $documentId,
                    )
                    : null;
                $title = (string) (
                    $previous['metadata']['title']
                    ?? $upload['name']
                    ?? 'document.pdf'
                );
            }
            $document = \Gallery\Services\gallery_pdf_documents_attach(
                $gallery,
                $upload['tmp_name'],
                (string) ($upload['name'] ?? 'document.pdf'),
                $title,
                'manual',
                $action === 'replace' ? $documentId : null,
                (string) ($_POST['confirmed'] ?? '') === '1',
            );
            $result['document_id'] = $document['id'];
            $result['panel_html'] = admin_gallery_pdf_documents_html(
                $gallery,
                (string) ($_POST['pdf_surface'] ?? 'editor') !== 'public',
            );
        } elseif ($action === 'visibility') {
            $hiddenValue = (string) ($_POST['hidden'] ?? '');
            if (!in_array($hiddenValue, ['0', '1'], true)) {
                throw new \RuntimeException(
                    'Choose whether this PDF is hidden from gallery visitors.',
                );
            }
            $hidden = $hiddenValue === '1';
            if ($documentId === 'ofp') {
                if (
                    \Gallery\Services\simbrief_ofp_local_pdf_path($gallery)
                    === null
                ) {
                    throw new \RuntimeException(
                        'The SimBrief OFP is no longer available.',
                    );
                }
                \Gallery\Services\gallery_pdf_documents_set_ofp_hidden(
                    $gallery,
                    $hidden,
                );
            } elseif (!\Gallery\Services\gallery_pdf_documents_set_hidden(
                $gallery,
                $documentId,
                $hidden,
            )) {
                throw new \RuntimeException(
                    'The PDF document no longer exists. Reload the gallery editor.',
                );
            }
            $result['hidden'] = $hidden;
            $editorSurface =
                (string) ($_POST['pdf_surface'] ?? 'editor') !== 'public';
            $result['panel_html'] = admin_gallery_pdf_documents_html(
                $gallery,
                $editorSurface,
            );
        } elseif ($action === 'delete') {
            if ($documentId === 'ofp') {
                require_once
                    __DIR__ . '/../../services/simbrief_ofp_attachments.php';
                \Gallery\Services\simbrief_ofp_delete_manual_pdf(
                    $gallery,
                    (string) ($_POST['confirmed'] ?? '') === '1',
                );
            } else {
                \Gallery\Services\gallery_pdf_documents_delete(
                    $gallery,
                    $documentId,
                    (string) ($_POST['confirmed'] ?? '') === '1',
                );
            }
            $result['panel_html'] = admin_gallery_pdf_documents_html(
                $gallery,
                (string) ($_POST['pdf_surface'] ?? 'editor') !== 'public',
            );
        } elseif ($action === 'begin') {
            $dpi = \Gallery\Services\gallery_pdf_render_dpi_validate(
                (string) (
                    $_POST['dpi'] ?? \Gallery\Services\gallery_pdf_render_dpi()
                ),
            );
            $result = \Gallery\Services\gallery_pdf_conversion_begin(
                $gallery,
                $documentId,
                $dpi,
                (int) ($_POST['page_count'] ?? 0),
                (string) ($_POST['target_mode'] ?? 'child'),
                null,
                (string) ($_POST['allow_new'] ?? '') === '1',
                true,
            );
        } elseif (in_array($action, ['finalize', 'cancel'], true)) {
            $token = (string) ($_POST['pdf_conversion_token'] ?? '');
            $targetId = (int) ($_POST['target_gallery_id'] ?? 0);
            $result = $action === 'finalize'
                ? \Gallery\Services\gallery_pdf_conversion_finalize(
                    $gallery,
                    $token,
                    $targetId,
                )
                : \Gallery\Services\gallery_pdf_conversion_cancel(
                    $gallery,
                    $token,
                    $targetId,
                );
            $target = \Gallery\Services\find_gallery($targetId);
            $result['target_url'] = $target
                ? \Gallery\Core\gallery_public_url($target)
                : '';
        } else {
            throw new \RuntimeException(
                'Enable JavaScript to convert PDF pages in this browser.',
            );
        }
        \Gallery\Services\gallery_attachment_storage_changed();
        $message = \Gallery\Services\t('pdf.saved', 'PDF operation saved.');
        $targetId = (int) (
            $result['gallery_id']
            ?? $result['child_gallery_id']
            ?? 0
        );
        $contexts = [\Gallery\Core\admin_mutation_public_gallery_context(
            $galleryId,
            \Gallery\Core\gallery_public_url($gallery),
        )];
        $entityIds = [$galleryId];
        if ($targetId > 0) {
            $target = \Gallery\Services\find_gallery($targetId);
            if (is_array($target)) {
                $entityIds[] = $targetId;
                $contexts[] = \Gallery\Core\admin_mutation_public_gallery_context(
                    $targetId,
                    \Gallery\Core\gallery_public_url($target),
                    \Gallery\Core\admin_mutation_postcondition('gallery_image_count', [
                        'gallery_id' => $targetId,
                        'count' => count(\Gallery\Services\gallery_images(
                            $targetId,
                            false,
                        )),
                    ]),
                );
                if ($targetId !== $galleryId) {
                    $contexts[] = \Gallery\Core\admin_mutation_public_gallery_context(
                        $galleryId,
                        \Gallery\Core\gallery_public_url($gallery),
                        \Gallery\Core\admin_mutation_gallery_membership_postcondition(
                            $targetId,
                            $galleryId,
                            true,
                        ),
                    );
                }
            }
        }
        $descriptor = $action === 'finalize'
            ? \Gallery\Core\admin_mutation_descriptor(
                'image.upload',
                'image',
                'upload',
                (array) ($result['image_ids'] ?? []),
            )
            : \Gallery\Core\admin_mutation_descriptor(
                'gallery.pdf.' . $action,
                'gallery',
                'update',
                $entityIds,
            );
        $envelope = \Gallery\Core\admin_mutation_success_envelope(
            $message,
            $descriptor,
            \Gallery\Core\admin_mutation_panel_metadata(
                'gallery-edit',
                $editUrl,
                true,
            ),
            $contexts,
            ['redirect_url' => $editUrl],
        );
        $result = array_merge($result, $envelope);
        if ($ajax) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
            echo json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
            return;
        }
        \Gallery\Core\flash_message('admin_notice', $message);
    } catch (\Throwable $exception) {
        // Record lifecycle refusals before any upload batch exists. Never include
        // session tokens, CSRF values, PDF bytes or multipart request bodies.
        \Gallery\Services\admin_log_event(
            'error',
            'gallery.pdf_operation_failed',
            'Gallery PDF operation failed.',
            [
                'action' => $action,
                'gallery_id' => $galleryId,
                'document_id' => (string) ($_POST['document_id'] ?? ''),
                'target_gallery_id' => (int) ($_POST['target_gallery_id'] ?? 0),
                'target_mode' => (string) ($_POST['target_mode'] ?? ''),
                'dpi' => (int) ($_POST['dpi'] ?? 0),
                'page_count' => (int) ($_POST['page_count'] ?? 0),
                'error' => $exception->getMessage(),
            ],
        );
        $message = \Gallery\Services\gallery_pdf_operation_error_message(
            $exception,
            (string) ($_POST['pdf_ui_language'] ?? ''),
        );
        if ($ajax) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: private, no-store');
            $failure = \Gallery\Core\admin_mutation_error_envelope(
                $message,
                'pdf_operation_failed',
                \Gallery\Core\admin_mutation_descriptor(
                    'gallery.pdf.' . $action,
                    'gallery',
                    'update',
                    [$galleryId],
                ),
            );
            $failure['pdf_restart_available'] =
                $action === 'begin'
                && (string) ($_POST['target_mode'] ?? 'child') === 'child'
                && \Gallery\Services\gallery_pdf_conversion_restart_available(
                    $exception,
                );
            echo json_encode($failure, JSON_UNESCAPED_UNICODE);
            return;
        }
        \Gallery\Core\flash_message('admin_notice', $message);
    }
    \Gallery\Core\redirect_to($editUrl);
}
