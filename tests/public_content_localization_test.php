<?php

/** Protect public multilingual rendering boundaries and access ordering. */

declare(strict_types=1);

require_once __DIR__ . '/support/module_source.php';

$root = dirname(__DIR__);
$galleryPage = (string) file_get_contents($root . '/app/controllers/public_gallery_page.php');
$lightbox = (string) file_get_contents($root . '/app/controllers/gallery_lightbox.php');
$service = (string) file_get_contents($root . '/app/services/content_localization.php');
$sidecars = (string) file_get_contents($root . '/app/services/gallery_sidecars.php');
$search = (string) file_get_contents($root . '/app/services/public_search.php');
// The gallery migration service is split into part files; assert against the whole module.
$migration = module_source($root . '/app/services/gallery_migration.php');

/**
 * Assert one public localization source or ordering contract.
 *
 * @param bool $condition Assertion result.
 * @param string $label Failure label.
 */
function public_content_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

public_content_assert(str_contains($galleryPage, "content_localize_entity('gallery'"), 'Selected gallery is not localized.');
public_content_assert(str_contains($galleryPage, "content_localize_entities('gallery'"), 'Physical subgalleries are not batch localized.');
public_content_assert(str_contains($galleryPage, "content_localize_entities('image'"), 'Visible images are not batch localized.');
public_content_assert(str_contains($galleryPage, "'lang' => \$contentLanguage"), 'Lazy single-photo metadata does not preserve the active content language.');
public_content_assert(str_contains($lightbox, "content_localize_entities('image'"), 'Lazy lightbox images are not localized.');
public_content_assert(str_contains($lightbox, "'description' => (string) (\$image['description'] ?? '')"), 'Lightbox payload no longer consumes resolved descriptions.');
public_content_assert(strpos($galleryPage, 'visitor_can_access_gallery($gallery)') < strpos($galleryPage, "content_localize_entity('gallery'"), 'Localization must happen after gallery access enforcement.');
public_content_assert(strpos($lightbox, 'visitor_can_access_gallery($gallery)') < strrpos($lightbox, "content_localize_entity('gallery'"), 'Lightbox localization must happen after access enforcement.');
public_content_assert(str_contains($service, "\$entity['source_title']") && str_contains($service, "\$entity['source_description']"), 'Resolved rows do not preserve source values for Admin/debug context.');
public_content_assert(!str_contains($service, 'slug =') && !str_contains($service, 'folder_path ='), 'Localization service must not mutate URL or filesystem identity.');
public_content_assert(str_contains($sidecars, "\$data['translations']") && str_contains($sidecars, "\$data['content_language']"), 'Gallery sidecars do not preserve multilingual content.');
public_content_assert(str_contains($search, 'image_translations content_image_translation') && str_contains($search, 'gallery_translations content_gallery_translation'), 'Public search does not match active-language translations.');
public_content_assert(str_contains($search, "content_localize_entities('image'") && str_contains($search, "content_localize_entities('gallery'"), 'Public search results are not batch localized.');
public_content_assert(substr_count($migration, 'content_save_localizations(') >= 2 && substr_count($migration, "['translations']") >= 2, 'Gallery migration does not preserve gallery and image translations.');

echo "Public content localization checks passed.\n";
