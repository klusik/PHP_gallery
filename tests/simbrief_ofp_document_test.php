<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/simbrief_ofp_document_test.php
 * Module Type: Standalone Regression Test
 *
 * Purpose:
 *   Exercise manifest-backed OFP attachment resolution and private subgallery
 *   idempotency without a database, network request or ImageMagick installation.
 *
 * Responsibilities:
 *   - Reject invalid files and marker ownership from the document path
 *   - Guarantee conversion does not overwrite an existing generated gallery
 *   - Check PHP route/CSS/JS contracts for independent OFP viewing
 *
 * Author:
 *   Rudolf Klusal
 * Contact:
 *   https://github.com/klusik
 * License:
 *   MIT License (see LICENSE file in repository)
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 * Last Updated:
 *   2026-10-07
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape fixture values exactly as the Admin presentation layer expects.
     * @param string $value Escaped HTML source string.
     * @return string Safe HTML attribute or text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Check an absolute fixture path against the fixture root.
     *
     * @param string $root Allowed root.
     * @param string $path Path to validate.
     * @return bool Whether the path remains within the root.
     */
    function path_inside(string $root, string $path): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    /**
     * Resolve a synthetic gallery URL for the isolated conversion fixture.
     *
     * @param array<string,mixed> $gallery Fixture gallery row.
     * @return string Fixture URL.
     */
    function gallery_public_url(array $gallery): string
    {
        return '/g/' . (int) $gallery['id'];
    }
}

namespace Gallery\Services {
    /**
     * Return the maintained English fallback in isolated presentation tests.
     * @param string $key Translation identity, unused in this English fixture.
     * @param string $fallback English fallback to display.
     * @return string Fallback user-visible label.
     */
    function t(string $key, string $fallback = ''): string
    {
        return $fallback;
    }

    /**
     * Fixture-owned galleries directory.
     *
     * @return string Absolute temporary fixture root.
     */
    function galleries_root(): string
    {
        return (string) $GLOBALS['ofp_test_root'];
    }

    /**
     * Resolve a fixture gallery-relative path.
     *
     * @param string $relative Relative gallery folder path.
     * @return string Absolute fixture path.
     */
    function gallery_abs_path(string $relative): string
    {
        return galleries_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    /**
     * Return an existing fixture child by its exact normalized path.
     *
     * @param string $path Gallery-relative folder path.
     * @param bool $refresh Whether to bypass normal lookup caching.
     * @return ?array<string,mixed> Fixture child row.
     */
    function find_gallery_by_folder_path(string $path, bool $refresh = false): ?array
    {
        return (array) ($GLOBALS['ofp_test_child'] ?? []) ?: null;
    }

    /**
     * Return the existing parent fixture by its ID.
     *
     * @param int $id Requested gallery ID.
     * @param bool $refresh Whether to bypass normal lookup caching.
     * @return ?array<string,mixed> Fixture parent.
     */
    function find_gallery(int $id, bool $refresh = false): ?array
    {
        $parent = (array) ($GLOBALS['ofp_test_parent'] ?? []);
        return $id === (int) ($parent['id'] ?? -1) ? $parent : null;
    }

    /**
     * Return the reserved OFP child folder path.
     *
     * @param ?array<string,mixed> $parent Source gallery.
     * @param string $segment Child folder name.
     * @return string Relative child folder path.
     */
    function gallery_child_folder_path(?array $parent, string $segment): string
    {
        return (string) $parent['folder_path'] . '/' . $segment;
    }

    /**
     * Simulate an owned gallery write lease.
     *
     * @return string Fixture lock ID.
     */
    function gallery_edit_writer_begin(): string
    {
        return 'ofp-test-writer';
    }

    /**
     * Finish a fixture write lease.
     *
     * @param string $lockName Fixture lock ID.
     * @return void
     */
    function gallery_edit_writer_end(string $lockName): void
    {
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/services/simbrief_ofp_attachments.php';
    require_once dirname(__DIR__) . '/app/services/simbrief_ofp_conversion.php';
    require_once dirname(__DIR__) . '/app/views/admin_gallery_edit_tabs.php';

    /**
     * Fail loudly if one OFP access or isolation postcondition fails.
     *
     * @param bool $ok Required result.
     * @param string $message Failure reason.
     * @return void
     */
    function ofp_document_assert(bool $ok, string $message): void
    {
        if (!$ok) {
            throw new RuntimeException($message);
        }
    }

    $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gallery-ofp-' . bin2hex(random_bytes(10));
    $GLOBALS['ofp_test_root'] = $root;
    $GLOBALS['ofp_test_parent'] = ['id' => 42, 'folder_path' => 'flight', 'visibility' => 'public'];
    $GLOBALS['ofp_test_child'] = null;
    $parent = $GLOBALS['ofp_test_parent'];
    $folder = $root . '/flight';
    $childFolder = $folder . '/ofp-pages';
    $manifestPath = $folder . '/simbrief-ofp-manifest.json';
    $pdfPath = $folder . '/simbrief-ofp.pdf';
    ofp_document_assert(mkdir($root, 0700) && mkdir($folder, 0700), 'Cannot create OFP fixture.');
    try {
        $manifest = ['format' => 'php_gallery_simbrief_ofp_manifest_v1', 'ofp_pdf_file' => 'simbrief-ofp.pdf'];
        file_put_contents($manifestPath, json_encode($manifest));
        file_put_contents($pdfPath, "%PDF-1.5\n" . str_repeat('x', 128));

        ofp_document_assert(
            \Gallery\Services\simbrief_ofp_local_pdf_path($parent) === realpath($pdfPath),
            'Valid local PDF attachment was not resolved.'
        );
        file_put_contents($pdfPath, 'not a PDF');
        ofp_document_assert(\Gallery\Services\simbrief_ofp_local_pdf_path($parent) === null, 'Invalid PDF was exposed.');
        file_put_contents($pdfPath, "%PDF-1.5\n" . str_repeat('x', 128));

        $manifest['ofp_pdf_file'] = '';
        file_put_contents($manifestPath, json_encode($manifest));
        ofp_document_assert(\Gallery\Services\simbrief_ofp_local_pdf_path($parent) === null, 'Manifest-deleted PDF remained visible.');
        $manifest['ofp_pdf_file'] = 'simbrief-ofp.pdf';
        file_put_contents($manifestPath, json_encode($manifest));

        if (DIRECTORY_SEPARATOR === '/') {
            unlink($pdfPath);
            if (@symlink($root . '/target.pdf', $pdfPath)) {
                file_put_contents($root . '/target.pdf', "%PDF-1.5\n" . str_repeat('x', 128));
                ofp_document_assert(\Gallery\Services\simbrief_ofp_local_pdf_path($parent) === null, 'PDF symlink was accepted.');
                unlink($pdfPath);
                unlink($root . '/target.pdf');
            }
            file_put_contents($pdfPath, "%PDF-1.5\n" . str_repeat('x', 128));
        }

        // The generated child is already present: a repeat must be a read-only no-op.
        mkdir($childFolder, 0700);
        $child = ['id' => 99, 'folder_path' => 'flight/ofp-pages', 'parent_id' => 42, 'visibility' => 'private'];
        $GLOBALS['ofp_test_child'] = $child;
        $marker = [
            'format' => 'php_gallery_simbrief_pages_v1',
            'source_gallery_id' => 42,
            'page_count' => 2,
        ];
        $markerPath = $childFolder . '/simbrief-ofp-pages.json';
        file_put_contents($markerPath, json_encode($marker));
        file_put_contents($childFolder . '/ofp-page-001.jpg', 'edited-original');
        file_put_contents($childFolder . '/ofp-page-002.jpg', 'edited-original');
        $converted = \Gallery\Services\simbrief_ofp_create_private_subgallery($parent);
        ofp_document_assert($converted['created'] === false && $converted['gallery_id'] === 99 && $converted['pages'] === 2,
            'Repeat conversion modified an already generated child.');
        ofp_document_assert(file_get_contents($childFolder . '/ofp-page-001.jpg') === 'edited-original',
            'Repeat conversion overwrote an edited image.');

        $marker['source_gallery_id'] = 43;
        file_put_contents($markerPath, json_encode($marker));
        try {
            \Gallery\Services\simbrief_ofp_existing_generated_gallery($parent, 'flight/ofp-pages');
            throw new RuntimeException('Unrelated subgallery was treated as owned by this OFP.');
        } catch (RuntimeException $error) {
            ofp_document_assert(str_contains($error->getMessage(), 'reserved OFP'), 'Unexpected refusal error.');
        }

        // Security/presentation contracts cannot accidentally regress to a public
        // subgallery or mixed photo/PDF navigation during later refactors.
        $service = file_get_contents(dirname(__DIR__) . '/app/services/simbrief_ofp_conversion.php');
        $viewer = file_get_contents(dirname(__DIR__) . '/public/assets/gallery-modules/simbrief-ofp-viewer.js');
        $htaccess = file_get_contents(dirname(__DIR__) . '/galleries/.htaccess');
        ofp_document_assert(str_contains($service, "'visibility' => 'private'"), 'Generated OFP gallery must be genuinely private.');
        ofp_document_assert(str_contains($htaccess, 'json|txt|zip|pdf'), 'Static gallery PDFs are not blocked.');
        ofp_document_assert(str_contains($viewer, 'state.page') && str_contains($viewer, 'state.total')
            && !str_contains($viewer, 'galleryImageIndex'), 'OFP pages were mixed into photo navigation.');

        // UI/gesture policy is also a security and accessibility boundary:
        // photo navigation and page/browser zoom must not hijack this PDF.
        $publicView = (string) file_get_contents(dirname(__DIR__) . '/app/views/public_gallery_controls.php');
        $viewerCss = (string) file_get_contents(dirname(__DIR__) . '/public/assets/styles/lightbox.css');
        ofp_document_assert(
            str_contains($viewer, "|| !event.ctrlKey")
                && str_contains($viewer, "if (event.cancelable) event.preventDefault();")
                && str_contains($viewer, "stage.addEventListener('wheel'")
                && str_contains($viewer, "['gesturestart', 'gesturechange', 'gestureend']")
                && str_contains($viewer, 'state.touchPanAfterPinch')
                && !str_contains($viewer, 'Math.abs(event.deltaY) >= 48'),
            'OFP wheel must scroll natively; Ctrl+wheel, Safari trackpad pinch and mobile handoff must remain distinct.'
        );
        ofp_document_assert(
            str_contains($publicView, 'simbrief-ofp-primary-actions')
                && str_contains($publicView, 'simbrief-ofp-secondary-actions')
                && str_contains($publicView, 'data-ofp-info-toggle')
                && str_contains($publicView, 'simbrief.ofp.convert_help')
                && str_contains($viewer, "event.key !== 'Escape'"),
            'Conversion must keep a localized dismissible question-mark help apart from public OFP actions.'
        );
        ofp_document_assert(
            str_contains($viewerCss, 'dialog.lightbox.simbrief-ofp-dialog')
                && str_contains($viewerCss, 'color: var(--ofp-foreground) !important;')
                && str_contains($viewerCss, 'background: #1d4ed8 !important;')
                && str_contains($viewerCss, 'touch-action: none;'),
            'OFP HUD contrast must be theme-independent and PDF touch events must be isolated.'
        );
        

        // #104: historical prefill is local, uncertainty-labelled and bounded.
        $legacyFolder = $root . '/historical';
        ofp_document_assert(mkdir($legacyFolder, 0700), 'Could not stage historical OFP fixture.');
        try {
            $historical = [
                'id' => 47, 'folder_path' => 'historical', 'gallery_date' => '2026-06-03',
                'gallery_date_end' => null, 'title' => 'Never infer LKPR and ESSA from this title',
            ];
            $prefill = \Gallery\Services\simbrief_ofp_dispatch_prefill($historical);
            ofp_document_assert(!$prefill['has_snapshot']
                && $prefill['fields']['orig']['value'] === ''
                && $prefill['fields']['dest']['value'] === ''
                && $prefill['fields']['date']['value'] === '2026-06-03'
                && $prefill['fields']['date']['uncertain'] === true,
                'Legacy gallery metadata was guessed as confirmed flight data.');
            ofp_document_assert(
                \Gallery\Services\simbrief_ofp_dispatch_value('orig', 'LKPR') === 'LKPR'
                && \Gallery\Services\simbrief_ofp_dispatch_value('orig', 'flight') === ''
                && \Gallery\Services\simbrief_ofp_dispatch_value('fl', 'FL350') === '350'
                && \Gallery\Services\simbrief_ofp_dispatch_value('fl', '35000') === '350'
                && \Gallery\Services\simbrief_ofp_dispatch_value('date', '2026-02-30') === ''
                && \Gallery\Services\simbrief_ofp_dispatch_value('date', '03JUN26') === '2026-06-03'
                && \Gallery\Services\simbrief_ofp_dispatch_value('route', 'DCT OKL DCT') === 'DCT OKL DCT'
                && \Gallery\Services\simbrief_ofp_dispatch_value('route', 'https://example.com/') === '',
                'Dispatch parameter normalization accepted an invalid field.'
            );

            $snapshot = ['origin' => ['icao_code' => 'LKPR'], 'destination' => ['icao_code' => 'ESSA'],
                'aircraft' => ['icao_code' => 'A320'], 'general' => ['route' => 'DCT OKL DCT'],
                'params' => ['fl' => '35000', 'deph' => '07', 'depm' => '20']];
            $rawSnapshot = json_encode($snapshot, JSON_PRETTY_PRINT) . "\n";
            file_put_contents($legacyFolder . '/simbrief-ofp.json', $rawSnapshot);
            $routeMap = ['route_text' => 'LKPR@50.1,14.2 DCT ESSA@59.6,17.9'];
            $prefill = \Gallery\Services\simbrief_ofp_dispatch_prefill($historical, $routeMap);
            ofp_document_assert($prefill['has_snapshot']
                && $prefill['fields']['orig']['value'] === 'LKPR'
                && $prefill['fields']['dest']['value'] === 'ESSA'
                && $prefill['fields']['type']['value'] === 'A320'
                && $prefill['fields']['route']['source'] === 'saved_ofp'
                && $prefill['fields']['deph']['value'] === '07'
                && $prefill['fields']['depm']['value'] === '20',
                'Saved OFP data was not preferred to less-certain gallery fields.');
            $panelInput = [
                'gallery' => $historical, 'has_pdf' => false, 'occupied' => false,
                'csrf_html' => '<input type="hidden" name="csrf_token" value="fixture">',
                'action_url' => '/fixture-editor', 'prefill' => $prefill,
            ];
            ob_start();
            \Gallery\Views\view_render_admin_simbrief_legacy_panel($panelInput);
            $missingPdfHtml = (string) ob_get_clean();
            ofp_document_assert(
                str_contains($missingPdfHtml, 'data-simbrief-dispatch-open')
                    && str_contains($missingPdfHtml, 'data-simbrief-dispatch-template')
                    && str_contains($missingPdfHtml, 'admin-simbrief-dispatch-field-date')
                    && !str_contains($missingPdfHtml, 'name="confirm_replace"'),
                'A gallery without a PDF must offer reviewed dispatch, a native date field and initial attachment.'
            );

            $legacyPdf = $legacyFolder . '/simbrief-ofp.pdf';
            $legacyManifest = $legacyFolder . '/simbrief-ofp-manifest.json';
            $uploadedPdf = $root . '/manual-upload.pdf';
            file_put_contents($uploadedPdf, "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n");
            $initial = \Gallery\Services\simbrief_ofp_attach_manual_pdf(
                $historical, $uploadedPdf, 'retrospective_user_generated'
            );
            ofp_document_assert($initial['bytes'] === filesize($uploadedPdf)
                && $initial['sha256'] === hash_file('sha256', $legacyPdf)
                && \Gallery\Services\simbrief_ofp_local_pdf_path($historical) === realpath($legacyPdf),
                'Manual OFP upload was not stored via the canonical authorized resolver.');
            $existingPanelInput = array_replace($panelInput, [
                'has_pdf' => true, 'occupied' => true, 'provenance' => 'retrospective_user_generated',
                'pdf_view_url' => '/fixture-ofp', 'pdf_download_url' => '/fixture-ofp?download=1',
            ]);
            ob_start();
            \Gallery\Views\view_render_admin_simbrief_legacy_panel($existingPanelInput);
            $existingPdfHtml = (string) ob_get_clean();
            ofp_document_assert(
                str_contains($existingPdfHtml, 'Prepare revised OFP in SimBrief')
                    && str_contains($existingPdfHtml, 'data-simbrief-dispatch-open')
                    && str_contains($existingPdfHtml, 'data-simbrief-dispatch-template')
                    && str_contains($existingPdfHtml, 'admin-simbrief-dispatch-field-date')
                    && str_contains($existingPdfHtml, 'Origin of the uploaded PDF (record only)')
                    && str_contains($existingPdfHtml, 'This selector only records')
                    && str_contains($existingPdfHtml, 'name="confirm_replace"')
                    && strpos($existingPdfHtml, 'data-simbrief-dispatch-open')
                        < strpos($existingPdfHtml, 'name="simbrief_ofp_pdf"'),
                'Existing OFP must expose an independent reviewed re-dispatch and explain manual replacement provenance.'
            );
            $firstPdf = file_get_contents($legacyPdf);
            $savedManifest = json_decode((string) file_get_contents($legacyManifest), true);
            ofp_document_assert($savedManifest['format'] === 'php_gallery_simbrief_ofp_manifest_v1'
                && $savedManifest['ofp_pdf_file'] === 'simbrief-ofp.pdf'
                && $savedManifest['pdf_provenance'] === 'retrospective_user_generated'
                && $savedManifest['ofp_file'] === 'simbrief-ofp.json'
                && file_get_contents($legacyFolder . '/simbrief-ofp.json') === $rawSnapshot,
                'Manual attachment lost provenance or modified the saved original JSON.');

            $rejectedDuplicate = false;
            try {
                \Gallery\Services\simbrief_ofp_attach_manual_pdf($historical, $uploadedPdf, 'manually_supplied');
            } catch (RuntimeException $error) {
                $rejectedDuplicate = str_contains($error->getMessage(), 'already attached');
            }
            ofp_document_assert($rejectedDuplicate && file_get_contents($legacyPdf) === $firstPdf,
                'A repeated ordinary upload overwrote a prior OFP.');
            $rejectedUnconfirmed = false;
            try {
                \Gallery\Services\simbrief_ofp_attach_manual_pdf(
                    ['id' => 48, 'folder_path' => '../not-this-gallery'], $uploadedPdf, 'manually_supplied'
                );
            } catch (RuntimeException $error) {
                $rejectedUnconfirmed = true;
            }
            ofp_document_assert($rejectedUnconfirmed, 'Wrong-gallery traversal was not rejected.');
            file_put_contents($uploadedPdf, "not a pdf");
            $rejectedInvalid = false;
            try {
                \Gallery\Services\simbrief_ofp_attach_manual_pdf($historical, $uploadedPdf, 'manually_supplied', true);
            } catch (RuntimeException $error) {
                $rejectedInvalid = true;
            }
            ofp_document_assert($rejectedInvalid && file_get_contents($legacyPdf) === $firstPdf,
                'Malformed PDF replaced the original attachment.');
            file_put_contents($uploadedPdf, "%PDF-1.7\n1 0 obj\n<< /Type /Pages >>\nendobj\n%%EOF\n");
            $replaced = \Gallery\Services\simbrief_ofp_attach_manual_pdf(
                $historical, $uploadedPdf, 'manually_supplied', true
            );
            $replacementManifest = json_decode((string) file_get_contents($legacyManifest), true);
            ofp_document_assert($replaced['provenance'] === 'manually_supplied'
                && file_get_contents($legacyPdf) !== $firstPdf
                && $replacementManifest['pdf_provenance'] === 'manually_supplied'
                && file_get_contents($legacyFolder . '/simbrief-ofp.json') === $rawSnapshot,
                'Explicit replacement did not preserve JSON and gallery isolation.');

            $legacyJs = (string) file_get_contents(dirname(__DIR__) . '/public/assets/gallery-modules/admin-simbrief-description.js');
            $legacyView = (string) file_get_contents(dirname(__DIR__) . '/app/views/admin_gallery_edit_tabs.php');
            $legacyPost = (string) file_get_contents(dirname(__DIR__) . '/app/controllers/admin_galleries_edit_page/post_actions.php');
            ofp_document_assert(str_contains($legacyJs, 'dialog.showModal()')
                && str_contains($legacyJs, 'https://dispatch.simbrief.com/options/custom')
                && str_contains($legacyJs, "window.open(url, '_blank', 'noopener,noreferrer')")
                && str_contains($legacyView, 'data-simbrief-dispatch-template')
                && str_contains($legacyView, 'data-simbrief-ofp-upload-form')
                && str_contains($legacyPost, 'is_uploaded_file(')
                && str_contains($legacyPost, "['upload_pdf', 'replace_pdf']"),
                'Legacy OFP flow lost modal confirmation, fixed redirect or admin upload isolation.');
            foreach (['en', 'cs', 'de', 'sv'] as $language) {
                $pack = json_decode((string) file_get_contents(dirname(__DIR__) . "/app/lang/$language.json"), true);
                ofp_document_assert(is_array($pack)
                    && isset($pack['admin.legacy_ofp.review_title'], $pack['admin.legacy_ofp.historical_warning'], $pack['admin.legacy_ofp.confirm_replace']),
                    'A maintained UI language is missing the retrospective OFP dialog or PDF controls.');
            }
        } finally {
            foreach (glob($legacyFolder . '/*') ?: [] as $path) {
                if (is_file($path) || is_link($path)) @unlink($path);
            }
            foreach (glob($legacyFolder . '/.*') ?: [] as $path) {
                if ($path !== $legacyFolder . '/.' && $path !== $legacyFolder . '/..' && is_file($path)) @unlink($path);
            }
            if (is_file($root . '/manual-upload.pdf')) @unlink($root . '/manual-upload.pdf');
            if (is_dir($legacyFolder)) @rmdir($legacyFolder);
        }

        echo "SimBrief OFP attachment/private subgallery contracts: PASS\n";
    } finally {
        foreach ([$childFolder . '/ofp-page-001.jpg', $childFolder . '/ofp-page-002.jpg',
            $childFolder . '/simbrief-ofp-pages.json', $pdfPath, $manifestPath] as $path) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
        }
        if (is_dir($childFolder)) @rmdir($childFolder);
        if (is_dir($folder)) @rmdir($folder);
        if (is_dir($root)) @rmdir($root);
    }
}
