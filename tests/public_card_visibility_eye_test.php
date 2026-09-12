<?php

/**
 * Protect the public-card visibility eye rendering and in-place mutation wiring.
 */

declare(strict_types=1);

/** Fail one visibility-eye source contract with a readable message. */
function visibility_eye_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$cards = (string) file_get_contents(__DIR__ . '/../app/controllers/public_gallery_cards.php');
$page = (string) file_get_contents(__DIR__ . '/../app/controllers/public_gallery_page.php');
$inline = (string) file_get_contents(__DIR__ . '/../app/controllers/admin_public_inline.php');
$browser = (string) file_get_contents(__DIR__ . '/../public/assets/gallery-modules/admin-side-panel.js');
$styles = (string) file_get_contents(__DIR__ . '/../public/assets/styles/public.css');

visibility_eye_expect(str_contains($cards, 'render_public_gallery_admin_visibility_menu($gallery)'), 'Gallery cards must render the visibility eye.');
visibility_eye_expect(substr_count($page, 'render_public_image_admin_visibility_menu($image)') === 2, 'Normal and NSFW image cards must render the visibility eye.');
foreach (['public', 'unpublished', 'private'] as $visibility) {
    visibility_eye_expect(str_contains($cards, $visibility), 'Visibility menu is missing ' . $visibility . '.');
}
visibility_eye_expect(str_contains($cards, 'data-public-admin-card-action data-public-admin-visibility-menu'), 'Visibility menu clicks must be excluded from the public lightbox opener.');
visibility_eye_expect(str_contains($cards, 'data-public-admin-visibility-form'), 'Visibility choices must retain POST fallbacks.');
visibility_eye_expect(str_contains($cards, 'public-admin-visibility-icon-unpublished'), 'Unpublished visibility icon must use fixed aligned icon markup.');
visibility_eye_expect(str_contains($cards, 'public-admin-visibility-icon-private'), 'Private visibility icon must use fixed aligned icon markup.');
visibility_eye_expect(str_contains($browser, 'data-public-admin-visibility-form'), 'Dynamic visibility forms must use delegated interception.');
visibility_eye_expect(str_contains($browser, 'completeCoreGalleryMutationInCurrentView(result)'), 'Visibility mutations must use the shared completion coordinator.');
visibility_eye_expect(str_contains($inline, 'gallery.visibility'), 'Gallery visibility must return typed mutation metadata.');
visibility_eye_expect(str_contains($inline, 'image.visibility'), 'Image visibility must return typed mutation metadata.');
visibility_eye_expect(str_contains($inline, 'draft'), 'Image unpublished state must map to legacy draft storage.');
visibility_eye_expect(str_contains($styles, '.public-admin-visibility-menu:hover .public-admin-visibility-options'), 'Pointer hover must expose the visibility submenu.');
visibility_eye_expect(str_contains($styles, '.public-admin-visibility-icon-unpublished::after'), 'Unpublished icon must use an aligned CSS slash overlay.');
visibility_eye_expect(str_contains($styles, '.public-admin-visibility-icon-private::after'), 'Private icon must use an aligned CSS lock overlay.');

echo 'Public card visibility eye contracts passed.' . PHP_EOL;
