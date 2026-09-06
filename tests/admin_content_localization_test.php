<?php

/** Protect Admin multilingual form and side-panel ownership contracts. */

declare(strict_types=1);

require_once __DIR__ . '/support/module_source.php';

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/app/views/admin_gallery_forms.php');
// The edit page is split into part files; assert against the whole module.
$galleryPage = module_source($root . '/app/controllers/admin_galleries_edit_page.php');
$gallerySave = (string) file_get_contents($root . '/app/controllers/admin_galleries_edit_actions.php');
$imageEdit = (string) file_get_contents($root . '/app/controllers/admin_public_inline.php');
$sidePanel = (string) file_get_contents($root . '/public/assets/gallery-modules/admin-side-panel.js');
$openaiService = (string) file_get_contents($root . '/app/services/openai_text_assist.php');
$openaiBrowser = (string) file_get_contents($root . '/public/assets/gallery-modules/admin-openai-text-assist.js');

/**
 * Assert one Admin localization source or workflow contract.
 *
 * @param bool $condition Assertion result.
 * @param string $label Failure label.
 */
function admin_content_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

admin_content_assert(str_contains($view, 'function view_render_content_localization_fields'), 'Reusable localization form renderer missing.');
admin_content_assert(str_contains($view, 'name="content_language"'), 'Source-language selector missing.');
admin_content_assert(str_contains($view, 'translations['), 'Nested translated fields missing.');
admin_content_assert(str_contains($view, '<details class="admin-content-translations"'), 'Optional translations are not hidden behind a disclosure control.');
admin_content_assert(str_contains($galleryPage, "view_render_content_localization_fields('gallery'"), 'Gallery editor does not render localization controls.');
admin_content_assert(str_contains($imageEdit, "view_render_content_localization_fields('image'"), 'Image editor does not render localization controls.');
admin_content_assert(str_contains($gallerySave, "content_save_localizations('gallery'"), 'Gallery save does not persist localizations.');
admin_content_assert(str_contains($imageEdit, "content_save_localizations('image'"), 'Image save does not persist localizations.');
admin_content_assert(str_contains($sidePanel, "body.set('ajax', '1')") && str_contains($sidePanel, 'new FormData(form)'), 'Side-panel edit forms do not retain AJAX FormData submission.');
admin_content_assert(!str_contains($view, 'window.location'), 'Localization renderer must not navigate the browser.');
admin_content_assert(str_contains($view, 'view_render_content_translation_suggestion_tool') && str_contains($view, 'translate_text'), 'Optional translation suggestion controls missing.');
admin_content_assert(str_contains($openaiService, "'translate_text' =>") && str_contains($openaiService, "'de' =>") && str_contains($openaiService, "'sv' =>"), 'OpenAI translation task does not cover all maintained languages.');
admin_content_assert(str_contains($openaiBrowser, "task === 'translate_text' ? sourceText"), 'Translation suggestion does not send the source field.');
admin_content_assert(str_contains($view, 'data-openai-status') && !str_contains($view, 'content_save_localizations('), 'Suggestion UI must remain draft-only.');

echo "Admin content localization checks passed.\n";
