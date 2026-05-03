<?php

declare(strict_types=1);

/**
 * Public media controller model.
 * 
 * This module streams thumbnails, media files, cover assets, robots.txt, and sitemap XML. Theme CSS, theme background assets, and favicon assets intentionally remain in the legacy controller file for now.
 */

function cms_thumb(): void
{
    // Variable $image stores this steps working value.
    $image = find_image((int) ($_GET['id'] ?? 0));
    // Variable $size stores this steps working value.
    $size = (int) ($_GET['size'] ?? 0);
    // Variable $format stores this steps working value.
    $format = (string) ($_GET['format'] ?? 'jpg');
    if (!$image || !in_array($size, thumbnail_sizes(), true) || !in_array($format, ['jpg', 'webp'], true)) {
        cms_not_found();
        return;
    }
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) $image['gallery_id']);
    if (!$gallery || (($image['visibility'] !== 'public' || !visitor_can_access_gallery($gallery)) && !current_user())) {
        cms_not_found();
        return;
    }
    try {
        // Variable $path stores this steps working value.
        $path = thumbnail_abs_path($image, $gallery, $size, $format);
    } catch (RuntimeException) {
        cms_not_found();
        return;
    }
    if (!is_file($path)) {
        cms_not_found();
        return;
    }
    header('Content-Type: ' . ($format === 'webp' ? 'image/webp' : 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    $cacheControl = gallery_access_requirement($gallery) && !current_user() ? 'private, max-age=300' : 'public, max-age=31536000, immutable';
    send_conditional_file_headers($path, $cacheControl);
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

function cms_public_thumb(): void
{
    $resolved = resolve_public_gallery_path((string) ($_GET['public_path'] ?? ''), !current_user());
    $gallery = $resolved['gallery'];
    $image = $resolved['image'];
    $size = (int) ($_GET['size'] ?? 0);
    $format = (string) ($_GET['format'] ?? 'jpg');

    if (!$gallery || !$image || !in_array($size, thumbnail_sizes(), true) || !in_array($format, ['jpg', 'webp'], true)) {
        cms_not_found();
        return;
    }
    if (($image['visibility'] !== 'public' || !visitor_can_access_gallery($gallery)) && !current_user()) {
        cms_not_found();
        return;
    }

    try {
        $path = thumbnail_abs_path($image, $gallery, $size, $format);
    } catch (RuntimeException) {
        cms_not_found();
        return;
    }
    if (!is_file($path)) {
        cms_not_found();
        return;
    }

    header('Content-Type: ' . ($format === 'webp' ? 'image/webp' : 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename($path) . '"');
    $cacheControl = gallery_access_requirement($gallery) && !current_user() ? 'private, max-age=300' : 'public, max-age=31536000, immutable';
    send_conditional_file_headers($path, $cacheControl);
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

function cms_public_media(): void
{
    $resolved = resolve_public_gallery_path((string) ($_GET['public_path'] ?? ''), !current_user());
    $gallery = $resolved['gallery'];
    $image = $resolved['image'];

    if (!$gallery || !$image) {
        cms_not_found();
        return;
    }
    if (($image['visibility'] !== 'public' || !visitor_can_access_gallery($gallery)) && !current_user()) {
        cms_not_found();
        return;
    }

    $path = image_abs_path($image, $gallery);
    if (!is_file($path)) {
        cms_not_found();
        return;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($finfo->file($path) ?: mime_content_type($path));
    if (!str_starts_with($mime, 'image/')) {
        cms_not_found();
        return;
    }

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename((string) $image['filename']) . '"');
    $cacheControl = gallery_access_requirement($gallery) && !current_user() ? 'private, max-age=300' : 'public, max-age=31536000, immutable';
    send_conditional_file_headers($path, $cacheControl);
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

function cms_gallery_cover_asset(): void
{
    $gallery = find_gallery((int) ($_GET['id'] ?? 0));
    if (!$gallery) {
        cms_not_found();
        return;
    }
    $coverPath = gallery_cover_path($gallery);
    if ($coverPath === null) {
        cms_not_found();
        return;
    }
    $path = gallery_abs_path((string) $gallery['folder_path']) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $coverPath);
    if (!is_file($path)) {
        cms_not_found();
        return;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($finfo->file($path) ?: mime_content_type($path));
    if (!str_starts_with($mime, 'image/')) {
        cms_not_found();
        return;
    }
    if (extension_loaded('gd')) {
        $info = @getimagesize($path);
        $source = $info !== false ? image_create_from_path($path, (string) ($info['mime'] ?? $mime)) : false;
        if ($info !== false && $source && ((int) $info[0] > 800 || (int) $info[1] > 800)) {
            $scale = min(1.0, 800 / max((int) $info[0], (int) $info[1]));
            $targetWidth = max(1, (int) round((int) $info[0] * $scale));
            $targetHeight = max(1, (int) round((int) $info[1] * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            $white = imagecolorallocate($target, 255, 255, 255);
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $white);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, (int) $info[0], (int) $info[1]);
            imageinterlace($target, true);
            header('Content-Type: image/jpeg');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: public, max-age=86400');
            imagejpeg($target, null, 82);
            imagedestroy($target);
            imagedestroy($source);
            return;
        }
        if ($source) {
            imagedestroy($source);
        }
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=86400');
    readfile($path);
}

function cms_media(): void
{
    // Variable $image stores this steps working value.
    $image = find_image((int) ($_GET['id'] ?? 0));
    if (!$image) {
        cms_not_found();
        return;
    }
    // Variable $gallery stores this steps working value.
    $gallery = find_gallery((int) $image['gallery_id']);
    if (!$gallery || (($image['visibility'] !== 'public' || !visitor_can_access_gallery($gallery)) && !current_user())) {
        cms_not_found();
        return;
    }
    // Variable $path stores this steps working value.
    $path = image_abs_path($image, $gallery);
    if (!is_file($path)) {
        cms_not_found();
        return;
    }
    // Variable $finfo stores this steps working value.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    // Variable $mime stores this steps working value.
    $mime = (string) ($finfo->file($path) ?: mime_content_type($path));
    if (!str_starts_with($mime, 'image/')) {
        cms_not_found();
        return;
    }
    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="' . basename((string) $image['filename']) . '"');
    $cacheControl = gallery_access_requirement($gallery) && !current_user() ? 'private, max-age=300' : 'public, max-age=31536000, immutable';
    send_conditional_file_headers($path, $cacheControl);
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

function cms_robots_txt(): void
{
    header('Content-Type: text/plain; charset=utf-8');
    echo "User-agent: *\n";
    echo "Allow: /\n";
    echo "Disallow: /index.php?page=admin\n";
    echo "Disallow: /index.php?page=admin_\n";
    echo "Disallow: /admin/\n";
    echo "Sitemap: " . public_base_url() . "/sitemap.xml\n";
}

function cms_sitemap_xml(): void
{
    output_sitemap_xml();
}

