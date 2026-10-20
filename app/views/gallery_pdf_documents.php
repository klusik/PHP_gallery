<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/views/gallery_pdf_documents.php
 * Module Type: View
 * Purpose: Present gallery PDF attachments and explicit conversion destinations.
 * Responsibilities: Render prepared document links, management and progress controls.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Views;

require_once __DIR__ . '/public_gallery_lightbox.php';

use function Gallery\Core\e;
use function Gallery\Core\format_bytes;
use function Gallery\Services\t;

/**
 * Render a persisted DPI selection and its visible or collapsed guidance.
 * @param int $dpi Current administrator resolution in dots per inch.
 * @param bool $compactHelp Whether to collapse the longer DPI explanation.
 * @return void Emit the select and a visible or collapsible help paragraph.
 */
function view_render_gallery_pdf_resolution(
    int $dpi,
    bool $compactHelp = false,
): void
{
    echo
        '<label>'
            . e(t('pdf.dpi', 'PDF page rendering resolution (DPI)'))
            . '<select name="dpi">';
    foreach ([96, 150, 200, 300, 600, 1200, 2400] as $choice) {
        echo
            '<option value="'
                . $choice
                . '"'
                . ($choice === $dpi ? ' selected' : '')
                . '>'
                . $choice
                . ' DPI</option>';
    }
    $help = e(t(
        'pdf.dpi_help',
        'Higher DPI increases dimensions, memory, upload size and'
            . ' processing time. PNG pages are lossless.',
    ));
    echo '</select></label>';
    echo $compactHelp
        ? '<details class="gallery-pdf-help"><summary>'
            . e(t('pdf.dpi', 'PDF page rendering resolution (DPI)'))
            . '</summary><p class="muted">' . $help . '</p></details>'
        : '<p class="muted">' . $help . '</p>';
}

/**
 * Render explicit page destinations; retaining only the PDF remains the upload default.
 * @param bool $upload Whether the attachment-only option applies to this upload.
 * @return void Emit the conversion destination selector.
 */
function view_render_gallery_pdf_destination(bool $upload): void
{
    echo
        '<label>'
            . e(t('pdf.destination', 'PNG page destination'))
            . '<select name="target_mode">';
    if ($upload) {
        echo
            '<option value="none">'
                . e(t('pdf.attach_only', 'Keep PDF only'))
                . '</option>';
    }
    echo
        '<option value="child">'
            . e(t('pdf.private_child', 'Create private subgallery'))
            . '</option>';
    echo
        '<option value="append">'
            . e(t('pdf.append', 'Append after the last photo in this gallery'))
            . '</option></select></label>';
}

/**
 * Render the inline editor list or public document disclosure and conversion controls.
 * @param array{gallery_id:int,admin:bool,editor:bool,action_url:string,
 *     csrf_html:string,operation_key:string,dpi:int,browser_config:string,
 *     language:string,documents:list<array{id:string,title:string,
 *     filename:string,size:int,hidden:bool,version:string,view_url:string,
 *     download_url:string}>} $model Safe documents with source versions for
 *     page-count cache identity.
 * @return void Emit an inline editor table/forms or public media disclosure,
 *     with attachment actions independent of the gallery settings form.
 */
function view_render_gallery_pdf_documents(array $model): void
{
    if (!$model['editor'] && $model['documents'] === []) {
        return;
    }
    echo
        '<section class="gallery-pdf-documents'
            . ($model['editor'] ? ' gallery-pdf-inline-editor' : '')
            . '" data-gallery-pdf-documents>';
    if (!$model['editor']) {
        // Summarize only the controller-authorized list, including its OFP.
        echo
            '<dl class="gallery-media-summary">'
                . '<div><dt>'
                . e(t('media_summary.files'))
                . '</dt><dd>'
                . count($model['documents'])
                . '</dd></div><div><dt>'
                . e(t('pdf.size'))
                . '</dt><dd>'
                . e(format_bytes((int) array_sum(array_column(
                    $model['documents'],
                    'size',
                ))))
                . '</dd></div><div><dt>'
                . e(t('pdf.pages'))
                . '</dt><dd data-pdf-page-total title="'
                . e(t('pdf.pages_unavailable'))
                . '">…</dd></div></dl>';
    }
    $surface = $model['editor'] ? 'editor' : 'public';
    view_media_panel_start(
        'gallery-pdf-list-' . $model['gallery_id'] . '-' . $surface,
        t('pdf.documents', 'Documents / PDFs'),
        count($model['documents']),
        view_music_control_icon('document'),
        $model['editor'],
    );
    echo
        '<table class="gallery-pdf-table'
            . ($model['admin'] ? ' is-admin' : '')
            . '"><colgroup><col class="gallery-pdf-title-column">'
            . '<col class="gallery-pdf-size-column">'
            . '<col class="gallery-pdf-pages-column">'
            . '<col class="gallery-pdf-actions-column"></colgroup><thead>'
            . '<tr><th scope="col">'
            . e(t('pdf.title', 'Document title'))
            . '</th><th scope="col">'
            . e(t('pdf.size', 'Size'))
            . '</th><th scope="col">'
            . e(t('pdf.pages', 'Pages'))
            . '</th><th scope="col"><span class="visually-hidden">'
            . e(t('common.actions', 'Actions'))
            . '</span></th></tr></thead><tbody>';
    foreach ($model['documents'] as $document) {
        $controlsId =
            'gallery-pdf-controls-'
            . $model['gallery_id']
            . '-'
            . $document['id']
            . '-'
            . ($model['editor'] ? 'editor' : 'public');
        echo
            '<tr><th scope="row" class="gallery-pdf-name"><a href="'
                . e($document['view_url'])
                . '" title="'
                . e($document['title'])
                . '" data-simbrief-ofp-open data-ofp-url="'
                . e($document['view_url'])
                . '">'
                . e($document['title'])
                . '</a>';
        if (!empty($document['hidden'])) {
            echo
                '<small class="visually-hidden">'
                    . e(t('pdf.hidden', 'Hidden'))
                    . '</small>';
        }
        echo
            '</th><td class="gallery-pdf-number">'
                . e(format_bytes($document['size']))
                . '</td><td class="gallery-pdf-number">'
                . '<span data-pdf-page-count data-pdf-url="'
                . e($document['view_url'])
                . '" data-pdf-version="'
                . e($document['version'])
                . '" data-page-count-title="'
                . e(t('pdf.pages_unavailable', 'Page count unavailable'))
                . '">—</span></td><td><div class="gallery-pdf-actions">';
        echo
            '<a class="gallery-pdf-icon" href="'
                . e($document['view_url'])
                . '" data-simbrief-ofp-open data-ofp-url="'
                . e($document['view_url'])
                . '" aria-label="'
                . e(t('pdf.view', 'View PDF'))
                . '" title="'
                . e(t('pdf.view', 'View PDF'))
                . '">'
                . view_gallery_pdf_icon('view')
                . '</a>';
        echo
            '<a class="gallery-pdf-icon" href="'
                . e($document['download_url'])
                . '" download="'
                . e($document['filename'])
                . '" aria-label="'
                . e(t('pdf.download', 'Download original PDF'))
                . '" title="'
                . e(t('pdf.download', 'Download original PDF'))
                . '">'
                . view_gallery_pdf_icon('download')
                . '</a>';
        if ($model['admin']) {
            echo
                '<button type="button" class="gallery-pdf-icon"'
                    . ' data-pdf-toggle aria-controls="'
                    . e($controlsId)
                    . '" aria-expanded="false" aria-label="'
                    . e(t('pdf.convert', 'Convert PDF pages to PNG'))
                    . '" title="'
                    . e(t('pdf.convert', 'Convert PDF pages to PNG'))
                    . '">'
                    . view_gallery_pdf_icon('convert')
                    . '</button>';
            $visibilityLabel = !empty($document['hidden'])
                ? t('pdf.show', 'Show PDF to visitors')
                : t('pdf.hide', 'Hide PDF from visitors');
            foreach (['visibility', 'delete'] as $action) {
                $label = $action === 'delete'
                    ? t('pdf.delete', 'Delete PDF')
                    : $visibilityLabel;
                echo
                    '<form method="post" action="'
                        . e($model['action_url'])
                        . '" data-pdf-attachment-form'
                        . (
                            $action === 'delete'
                                ? ' data-pdf-delete-confirm="'
                                . e(t(
                                    'pdf.delete_confirm',
                                    'Delete this original PDF?'
                                        . ' Generated photos and galleries are preserved.',
                                ))
                                . '"'
                                : ''
                        )
                        . '>'
                        . $model['csrf_html'];
                echo
                    '<input type="hidden" name="id" value="'
                        . $model['gallery_id']
                        . '"><input type="hidden" name="document_id" value="'
                        . e($document['id'])
                        . '"><input type="hidden" name="pdf_action" value="'
                        . $action
                        . '"><input type="hidden" name="pdf_surface" value="'
                        . $surface
                        . '"><input type="hidden" name="pdf_ui_language" value="'
                        . e($model['language'])
                        . '">';
                echo
                    $action === 'delete'
                        ? '<input type="hidden" name="confirmed" value="0">'
                        : '<input type="hidden" name="hidden" value="'
                            . (!empty($document['hidden']) ? '0' : '1')
                            . '">';
                echo
                    '<button type="submit" class="gallery-pdf-icon" title="'
                        . e($label)
                        . '" aria-label="'
                        . e($label)
                        . '">'
                        . view_gallery_pdf_icon(
                            $action === 'delete'
                                ? 'delete'
                                : (
                                    !empty($document['hidden'])
                                        ? 'show'
                                        : 'hide'
                                ),
                        )
                        . '</button><output data-pdf-status role="status"></output>'
                        . '</form>';
            }
        }
        echo '</div></td></tr>';
        if ($model['admin']) {
            echo
                '<tr id="'
                    . e($controlsId)
                    . '" data-pdf-controls hidden><td colspan="4">';
            $convertHelp = e(t(
                'pdf.convert_help',
                'Render pages in this browser;'
                    . ' choose a private subgallery or append after this'
                    . ' gallery’s last photo. The original PDF remains'
                    . ' unchanged.',
            ));
            echo $model['editor']
                ? '<details class="gallery-pdf-help"><summary>'
                    . e(t('pdf.convert', 'Convert PDF pages to PNG'))
                    . '</summary><p class="muted">'
                    . $convertHelp
                    . '</p></details>'
                : '<p class="muted">' . $convertHelp . '</p>';
            echo
                '<form method="post" action="'
                    . e($model['action_url'])
                    . '" data-pdf-convert-form data-pdf-url="'
                    . e($document['view_url'])
                    . '">';
            echo
                '<input type="hidden" name="pdf_ui_language" value="'
                    . e($model['language'])
                    . '"><input type="hidden" name="pdf_surface" value="'
                    . $surface
                    . '">';
            echo
                $model['csrf_html']
                    . '<input type="hidden" name="pdf_action"'
                    . ' value="browser_required">'
                    . '<input type="hidden" name="id" value="'
                    . $model['gallery_id']
                    . '"><input type="hidden" name="document_id" value="'
                    . e($document['id'])
                    . '"><input type="hidden" name="operation_key" value="'
                    . e($model['operation_key'])
                    . '">';
            echo
                '<input type="hidden" name="gallery_id" value="'
                    . $model['gallery_id']
                    . '">'
                    . '<input type="checkbox" hidden checked'
                    . ' name="create_thumbnails" value="1">'
                    . '<input type="checkbox" hidden checked'
                    . ' data-browser-upload-toggle data-browser-upload-config="'
                    . e($model['browser_config'])
                    . '">';
            view_render_gallery_pdf_destination(false);
            view_render_gallery_pdf_resolution(
                $model['dpi'],
                $model['editor'],
            );
            echo
                '<label><input type="checkbox" name="allow_new" value="1"> '
                    . e(t(
                        'pdf.confirm_new',
                        'Create a new result if the source, DPI or destination'
                            . ' differs; preserve earlier results.',
                    ))
                    . '</label>';
            echo
                '<button type="submit" class="gallery-pdf-icon" title="'
                    . e(t('pdf.convert'))
                    . '" aria-label="'
                    . e(t('pdf.convert'))
                    . '">'
                    . view_gallery_pdf_icon('convert')
                    . '</button>'
                    . '<button type="button" class="gallery-pdf-icon"'
                    . ' title="'
                    . e(t('pdf.cancel'))
                    . '" aria-label="'
                    . e(t('pdf.cancel'))
                    . '" data-pdf-cancel hidden>'
                    . view_gallery_pdf_icon('cancel')
                    . '</button><div data-upload-progress hidden></div>'
                    . '<output role="status" aria-live="polite" data-pdf-status>'
                    . '</output></form>';
            if ($model['editor'] && $document['id'] !== 'ofp') {
                echo
                    '<details><summary>'
                        . e(t('pdf.manage', 'Replace / delete PDF'))
                        . '</summary>'
                        . '<form method="post" enctype="multipart/form-data" action="'
                        . e($model['action_url'])
                        . '" data-pdf-attachment-form>';
                echo
                    $model['csrf_html']
                        . '<input type="hidden" name="id" value="'
                        . $model['gallery_id']
                        . '"><input type="hidden" name="document_id" value="'
                        . e($document['id'])
                        . '">';
                echo
                    '<label>'
                        . e(t('pdf.choose', 'Choose PDF (maximum 25 MiB)'))
                        . '<input type="file" name="pdf_file"'
                        . ' accept=".pdf,application/pdf"></label><label>'
                        . '<input type="checkbox" name="confirmed" value="1" required> '
                        . e(t(
                            'pdf.confirm_manage',
                            'Confirm replacement or deletion'
                                . ' of this PDF. Generated photos are preserved.',
                        ))
                        . '</label>';
                echo
                    '<button name="pdf_action" value="replace" type="submit"'
                        . ' class="gallery-pdf-icon" title="'
                        . e(t('pdf.replace'))
                        . '" aria-label="'
                        . e(t('pdf.replace'))
                        . '">'
                        . view_gallery_pdf_icon('replace')
                        . '</button>'
                        . '<button name="pdf_action" value="delete" type="submit"'
                        . ' class="gallery-pdf-icon" title="'
                        . e(t('pdf.delete'))
                        . '" aria-label="'
                        . e(t('pdf.delete'))
                        . '">'
                        . view_gallery_pdf_icon('delete')
                        . '</button>'
                        . '<output data-pdf-status role="status" aria-live="polite">'
                        . '</output></form></details>';
            }
            echo '</td></tr>';
        }
    }
    echo '</tbody></table>';
    if ($model['admin'] && $model['editor']) {
        echo
            '<form method="post" enctype="multipart/form-data" action="'
                . e($model['action_url'])
                . '" class="gallery-pdf-upload-form"'
                . ' data-pdf-attachment-form>'
                . $model['csrf_html'];
        echo
            '<input type="hidden" name="pdf_action" value="upload">'
                . '<input type="hidden" name="id" value="'
                . $model['gallery_id']
                . '">';
        echo
            '<label>'
                . e(t('pdf.title', 'Document title'))
                . '<input name="pdf_title" maxlength="200"></label><label>'
                . e(t('pdf.choose', 'Choose PDF (maximum 25 MiB)'))
                . '<input type="file" name="pdf_file"'
                . ' accept=".pdf,application/pdf" required></label>';
        echo
            '<details class="gallery-pdf-upload-advanced"><summary>'
                . e(t('pdf.upload.advanced', 'Advanced'))
                . '</summary>';
        view_render_gallery_pdf_destination(true);
        view_render_gallery_pdf_resolution($model['dpi'], true);
        echo '</details>';
        echo
            '<button type="submit" class="gallery-pdf-icon" title="'
                . e(t('pdf.upload'))
                . '" aria-label="'
                . e(t('pdf.upload'))
                . '">'
                . view_gallery_pdf_icon('upload')
                . '</button>'
                . '<output data-pdf-status role="status" aria-live="polite">'
                . '</output></form>';
    }
    view_media_panel_end($model['editor']);
    echo '</section>';
}

/**
 * Return a decorative text-sized SVG for one gallery PDF action.
 * @param string $action View, download, convert, upload, replace, cancel,
 *     delete, show or hide action identifier.
 * @return string Static SVG markup; accessible names belong to the enclosing control.
 */
function view_gallery_pdf_icon(string $action): string
{
    $path = match ($action) {
        'upload' => '<path d="M12 16V3m-5 5 5-5 5 5M4 16v5h16v-5"/>',
        'replace'
            => '<path d="M4 10a8 8 0 0 1 14-4l2 2M20 3v5h-5M20 14a8 8 0 0 1-14 4l-2-2M4 21v-5h5"/>',
        'cancel' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'download' => '<path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/>',
        'convert'
            => '<path d="M4 4h11v6M4 4v16h16v-9M14 3l6 6h-6M8 13h8m-8 4h5"/>',
        'delete'
            => '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>',
        'hide'
            => '<path d="m3 3 18 18M10 5h2c6 0 10 7 10 7s-1 3-4 5M6 6C3 8 2 12 2 12s4 7 10 7h2M9 9a4 4 0 0 0 6 6"/>',
        default => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    };
    return (
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"'
            . ' aria-hidden="true" focusable="false">'
            . $path
            . '</svg>'
    );
}
