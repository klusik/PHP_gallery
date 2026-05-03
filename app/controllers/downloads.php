<?php
/**
 * Public download controller model.
 *
 * This module contains the routes that produce gallery and site-wide ZIP
 * downloads. It depends on the download service functions and keeps all
 * request handling for archive downloads away from public gallery rendering.
 *
 * Route names, permissions, HTTP responses, and redirect behaviour are kept
 * identical to the previous app/controllers.php implementation.
 */

/**
 * Download a public ZIP for one gallery.
 */
function cms_download_gallery(): void
{
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) ($_GET['id'] ?? 0));
    if (!$gallery || !visitor_can_access_gallery($gallery)) {
        cms_not_found();
        return;
    }
    // Variable $zip stores this steps working value.
    $zip = build_gallery_zip((int) $gallery['id'], true);
    send_download($zip, slugify((string) $gallery['title']) . '.zip');
}

/**
 * Download an admin ZIP containing all imported galleries.
 */
function cms_download_all(): void
{
    require_admin();
    // Variable $zip stores this steps working value.
    $zip = build_all_zip();
    send_download($zip, 'all-galleries.zip');
}
