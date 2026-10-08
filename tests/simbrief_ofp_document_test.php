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
