<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/views/admin_gallery_forms.php
 * Module Type: View Module
 *
 * Purpose:
 *   Renders reusable admin gallery editor form fragments.
 *
 * Responsibilities:
 *   - Keep form HTML out of gallery controllers
 *   - Preserve existing side-panel and full-page form markup
 *   - Render reusable description-related editor tools
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-09-14
 */

declare(strict_types=1);

namespace Gallery\Views;

use function Gallery\Controllers\visibility_options;
use function Gallery\Core\asset_url;
use function Gallery\Core\csrf_field;
use function Gallery\Core\csrf_token;
use function Gallery\Core\current_user;
use function Gallery\Core\e;
use function Gallery\Core\url_for;
use function Gallery\Services\t;

/**
 * Render optional source-language and translated title/description fields.
 *
 * @param string $entityType Gallery or image architecture identifier.
 * @param array<string,mixed> $entity Entity row being edited.
 * @param array<string,mixed> $formModel Prepared localization and saved-default presentation.
 * @param string $section Presentation section: all, source, or translations.
 * @return void Emit the localization fields.
 */
function view_render_content_localization_fields(string $entityType, array $entity, array $formModel = [], string $section = 'all'): void
{
    $localization = is_array($formModel['localization'] ?? null) ? $formModel['localization'] : [];
    if (empty($localization['enabled'])) {
        return;
    }
    if (empty($localization['schema_ready'])) {
        if ($section !== 'source') {
            echo '<p class="muted">' . e(t('admin.content_localization.migration_required', 'Other-language content will be available after the multilingual-content database migration is applied.')) . '</p>';
        }
        return;
    }
    $languages = (array) ($localization['languages'] ?? []);
    $presentation = (array) ($localization['presentation'] ?? []);
    $sourceLanguage = (string) ($entity['content_language'] ?? '');
    $savedLanguage = $entityType === 'gallery' ? (string) (($formModel['creation_preferences'] ?? [])['content_language'] ?? '') : '';
    $usingSavedDefault = $sourceLanguage === '' && $savedLanguage !== '' && in_array($savedLanguage, $languages, true);
    if ($usingSavedDefault) {
        $sourceLanguage = $savedLanguage;
    }
    if ($sourceLanguage === '' && $entityType === 'gallery') {
        $publicLanguage = (string) ($localization['default_source_language'] ?? '');
        if (in_array($publicLanguage, $languages, true)) {
            $sourceLanguage = $publicLanguage;
        }
    }
    $translations = (array) ($localization['translations'] ?? []);

    if ($section === 'source') {
        view_render_content_language_picker($languages, $presentation, $sourceLanguage, $usingSavedDefault, $formModel);
        return;
    }
    if ($section === 'all') {
        echo '<div class="admin-content-localization" data-content-localization>';
        echo '<div class="admin-content-source-row"><div class="admin-content-source-picker">';
        echo '<label class="admin-content-source-language"><span>' . e(t('admin.content_localization.source_language', 'Language of the current title and description')) . '</span><span class="admin-content-source-select">';
        $selectedFlagAsset = trim((string) ($presentation[$sourceLanguage]['flag_asset'] ?? ''));
        echo '<img data-content-language-flag' . ($selectedFlagAsset !== '' ? ' src="' . e(asset_url($selectedFlagAsset)) . '"' : '') . ' alt="" aria-hidden="true" width="24" height="18"' . ($selectedFlagAsset === '' ? ' hidden' : '') . '>';
        echo '<select name="content_language" data-content-language-select>';
        echo '<option value="">' . e(t('admin.content_localization.not_specified', 'Not specified')) . '</option>';
        foreach ($languages as $language) {
            $name = (string) ($presentation[$language]['name'] ?? strtoupper((string) $language));
            $flagAsset = trim((string) ($presentation[$language]['flag_asset'] ?? ''));
            echo '<option value="' . e((string) $language) . '" data-flag-src="' . e($flagAsset !== '' ? asset_url($flagAsset) : '') . '"' . ($sourceLanguage === $language ? ' selected' : '') . '>' . e($name . ' (' . strtoupper((string) $language) . ')') . '</option>';
        }
        echo '</select></span></label>';
        echo '<details class="admin-inline-help"><summary aria-label="' . e(t('admin.content_localization.source_language', 'Language of the current title and description')) . '" title="' . e(t('admin.content_localization.source_language', 'Language of the current title and description')) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e(t('admin.content_localization.source_help', 'Existing fields remain the source content. Missing translations fall back to them.')) . '</div></details>';
        echo '</div>';
        if ($entityType === 'gallery' && !empty($formModel['creation_preferences_available'])) {
            echo '<label class="checkbox-label admin-content-remember"><input type="checkbox" name="remember_content_language" value="1"> ' . e(t('admin.gallery_editor.remember_language', 'Remember this as my default language')) . '</label>';
        }
        if ($usingSavedDefault) {
            echo '<small class="admin-gallery-saved-default-note">' . e(t('admin.gallery_editor.prefilled_default', 'Pre-filled from your saved defaults.')) . '</small>';
        }
        echo '</div>';
    }
    echo '<details class="admin-content-translations"><summary><span aria-hidden="true">&#127760;</span><span>' . e(t('admin.content_localization.other_languages', 'Other languages')) . '</span></summary>';
    echo '<div class="admin-content-translation-list">';
    foreach ($languages as $language) {
        $row = is_array($translations[$language] ?? null) ? $translations[$language] : [];
        $name = (string) ($presentation[$language]['name'] ?? strtoupper((string) $language));
        echo '<fieldset class="admin-content-translation" data-content-translation-language="' . e((string) $language) . '"><legend>' . e($name . ' (' . strtoupper((string) $language) . ')') . '</legend>';
        echo '<label><span>' . e(t('admin.content_localization.translated_title', 'Translated title')) . '</span><input name="translations[' . e((string) $language) . '][title]" value="' . e((string) ($row['title'] ?? '')) . '" maxlength="255" data-content-translation-title="' . e((string) $language) . '"></label>';
        view_render_content_translation_suggestion_tool($entityType, $entity, (string) $language, 'title', $formModel);
        echo '<label><span>' . e(t('admin.content_localization.translated_description', 'Translated description')) . '</span><textarea name="translations[' . e((string) $language) . '][description]" rows="4" data-content-translation-description="' . e((string) $language) . '">' . e((string) ($row['description'] ?? '')) . '</textarea></label>';
        view_render_content_translation_suggestion_tool($entityType, $entity, (string) $language, 'description', $formModel);
        echo '<small class="muted">' . e(t('admin.content_localization.blank_fallback', 'Leave a field blank to use the source content for that field.')) . '</small></fieldset>';
    }
    echo '</div></details>';
    if ($section === 'all') {
        echo '</div>';
    }
}

/**
 * Render a compact source-language picker beside the gallery title.
 *
 * @param list<string> $languages Enabled language codes.
 * @param array<string,array{name:string,flag_asset:string}> $presentation Prepared language names and bundled flag assets.
 * @param string $sourceLanguage Selected source language.
 * @param bool $usingSavedDefault Whether the selected language came from saved defaults.
 * @param array<string,mixed> $formModel Prepared saved-default availability.
 * @return void Emit flag options with a native-select fallback.
 */
function view_render_content_language_picker(array $languages, array $presentation, string $sourceLanguage, bool $usingSavedDefault, array $formModel): void
{
    $label = t('admin.content_localization.source_language', 'Language of the current title and description');
    $emptyLabel = t('admin.content_localization.not_specified', 'Not specified');
    $flagAsset = trim((string) ($presentation[$sourceLanguage]['flag_asset'] ?? ''));
    $selectedLabel = $sourceLanguage === '' ? $emptyLabel : (string) ($presentation[$sourceLanguage]['name'] ?? strtoupper($sourceLanguage));

    echo '<div class="admin-content-language-control"><details class="admin-content-language-picker" data-content-language-picker><summary aria-label="' . e($label) . '" title="' . e($label) . '">';
    echo '<img data-content-language-flag' . ($flagAsset !== '' ? ' src="' . e(asset_url($flagAsset)) . '"' : '') . ' alt="" aria-hidden="true" width="24" height="18"' . ($flagAsset === '' ? ' hidden' : '') . '><span data-content-language-empty aria-hidden="true"' . ($flagAsset !== '' ? ' hidden' : '') . '>🌐</span><span data-content-language-label>' . e($selectedLabel) . '</span><span aria-hidden="true">▾</span></summary>';
    echo '<div class="admin-content-language-options"><p class="admin-content-language-caption">' . e($label) . '</p><label class="admin-content-language-native"><span>' . e($label) . '</span><select name="content_language" data-content-language-select>';
    echo '<option value="">' . e($emptyLabel) . '</option>';
    foreach ($languages as $language) {
        $name = (string) ($presentation[$language]['name'] ?? strtoupper((string) $language));
        $asset = trim((string) ($presentation[$language]['flag_asset'] ?? ''));
        echo '<option value="' . e((string) $language) . '" data-flag-src="' . e($asset !== '' ? asset_url($asset) : '') . '"' . ($sourceLanguage === $language ? ' selected' : '') . '>' . e($name . ' (' . strtoupper((string) $language) . ')') . '</option>';
    }
    echo '</select></label><div class="admin-content-language-choices">';
    foreach (array_merge([''], $languages) as $language) {
        $name = $language === '' ? $emptyLabel : (string) ($presentation[$language]['name'] ?? strtoupper((string) $language));
        $asset = trim((string) ($presentation[$language]['flag_asset'] ?? ''));
        echo '<button type="button" data-content-language-option data-content-language-value="' . e((string) $language) . '" aria-pressed="' . ($sourceLanguage === $language ? 'true' : 'false') . '">';
        echo $asset !== '' ? '<img src="' . e(asset_url($asset)) . '" alt="" aria-hidden="true" width="24" height="18">' : '<span aria-hidden="true">🌐</span>';
        echo '<span>' . e($name) . '</span></button>';
    }
    echo '</div>';
    if (!empty($formModel['creation_preferences_available'])) {
        echo '<label class="checkbox-label admin-content-remember"><input type="checkbox" name="remember_content_language" value="1"> ' . e(t('admin.gallery_editor.remember_language', 'Remember this as my default language')) . '</label>';
    }
    if ($usingSavedDefault) {
        echo '<small class="admin-gallery-saved-default-note">' . e(t('admin.gallery_editor.prefilled_default', 'Pre-filled from your saved defaults.')) . '</small>';
    }
    echo '<p class="muted">' . e(t('admin.content_localization.source_help', 'Existing fields remain the source content. Missing translations fall back to them.')) . '</p></div></details></div>';
}

/**
 * Render an optional OpenAI translation-draft action for one target field.
 *
 * @param string $entityType Gallery or image architecture identifier.
 * @param array<string,mixed> $entity Entity row being edited.
 * @param string $language Target language code.
 * @param string $field Title or description field.
 */
function view_render_content_translation_suggestion_tool(string $entityType, array $entity, string $language, string $field, array $formModel = []): void
{
    $openAi = is_array($formModel['openai'] ?? null) ? $formModel['openai'] : [];
    if (empty($openAi['available'])) {
        return;
    }
    $galleryId = $entityType === 'gallery' ? (int) ($entity['id'] ?? 0) : (int) ($entity['gallery_id'] ?? 0);
    $imageId = $entityType === 'image' ? (int) ($entity['id'] ?? 0) : 0;
    $targetSelector = '[data-content-translation-' . $field . '="' . $language . '"]';
    $sourceSelector = $field === 'title' ? 'input[name="title"]' : 'textarea[name="description"]';
    echo '<div class="admin-openai-text-assist admin-content-translation-suggestion" data-openai-text-assist data-openai-endpoint="' . e(url_for('admin_openai_text_assist')) . '" data-gallery-id="' . $galleryId . '" data-image-id="' . $imageId . '" data-openai-target-selector="' . e($targetSelector) . '" data-openai-source-selector="' . e($sourceSelector) . '">';
    echo '<input type="hidden" value="translate_text" data-openai-task><input type="hidden" value="' . e($language) . '" data-openai-language>';
    echo '<button type="button" class="button secondary" data-openai-generate>' . e(t('admin.content_localization.suggest_translation', 'Suggest translation with OpenAI')) . '</button>';
    echo '<span class="muted" data-openai-status role="status" aria-live="polite"></span></div>';
}

/**
 * Handle view render gallery description formatting hint.
 *
 * Used by server-rendered view helpers.
 *
 * @return void Render the disclosure.
 */
function view_render_gallery_description_formatting_hint(): void
{
    echo '<details class="gallery-description-format-help"><summary aria-label="' . e(t('admin.gallery_editor.description_format_hints', 'Formatting hints')) . '" title="' . e(t('admin.gallery_editor.description_format_hints', 'Formatting hints')) . '"><span aria-hidden="true">?</span></summary><div class="gallery-description-format-help-popover">';
    echo '<p>' . e(t('admin.gallery_editor.description_format_intro', 'Basic formatting is supported in public gallery descriptions.')) . '</p>';
    echo '<ul>';
    echo '<li><code>**' . e(t('admin.gallery_editor.description_format_bold_word', 'bold')) . '**</code> ' . e(t('admin.gallery_editor.description_format_bold', 'makes bold text')) . '</li>';
    echo '<li><code>*' . e(t('admin.gallery_editor.description_format_italic_word', 'italic')) . '*</code> ' . e(t('admin.gallery_editor.description_format_italic', 'makes italic text')) . '</li>';
    echo '<li><code>`code`</code> ' . e(t('admin.gallery_editor.description_format_code', 'uses inline code styling')) . '</li>';
    echo '<li><code>[url]www.example.com[/url]</code> / <code>[link]https://example.com[/link]</code> ' . e(t('admin.gallery_editor.description_format_link', 'creates a clickable external link; URL and LINK tags are case-insensitive')) . '</li>';
    echo '<li><code>[url=https://example.com]' . e(t('admin.gallery_editor.description_format_link_word', 'Link text')) . '[/url]</code> ' . e(t('admin.gallery_editor.description_format_link_named', 'creates a link with custom text; LINK= works the same way')) . '</li>';
    echo '<li><code>[' . e(t('admin.gallery_editor.description_format_link_word', 'Link text')) . '](https://example.com)</code> ' . e(t('admin.gallery_editor.description_format_link_markdown', 'is the equivalent Markdown syntax')) . '</li>';
    echo '<li>' . e(t('admin.gallery_editor.description_format_link_icon', 'Well-known sites such as YouTube, Facebook, X/Twitter, Instagram, Wikipedia, LinkedIn, GitHub, Reddit, TikTok, Discord, Twitch, and Vimeo use bundled local icons. For other HTTP(S) links, saving the gallery attempts to fetch a safe favicon into the shared local cache; when available, it is shown before the link text.')) . '</li>';
    echo '<li>' . e(t('admin.gallery_editor.description_format_newlines', 'A single Enter is preserved as a new line. Empty lines create separate paragraphs.')) . '</li>';
    echo '</ul></div></details>';
}

/**
 * Render the EXIF-derived date suggestion controls for one existing gallery.
 *
 * @param array<string,mixed> $gallery Gallery row or gallery data.
 * @param array<string,mixed> $formModel Prepared EXIF suggestion and date presentation.
 * @return void Emits the suggestion, its in-place apply action, and on-demand explanation.
 */
function view_render_admin_gallery_date_exif_suggestion(array $gallery, array $formModel = []): void
{
    $dateModel = is_array($formModel['date'] ?? null) ? $formModel['date'] : [];
    if (empty($dateModel['exif_suggestion_enabled'])) {
        return;
    }

    // $galleryId stores the branch root whose own images and descendants form the suggestion.
    $galleryId = (int) ($gallery['id'] ?? 0);
    if ($galleryId <= 0) {
        return;
    }

    // $suggestion stores the recursive EXIF date range for this gallery branch.
    $suggestion = is_array($dateModel['exif_suggestion'] ?? null) ? $dateModel['exif_suggestion'] : null;
    $suggestionTitle = t('admin.gallery_editor.exif_date_automatic', 'From photos');
    $suggestionHelp = $suggestion
        ? t('admin.gallery_editor.exif_date_suggestion_help', 'Computed from {images} EXIF photo(s) in this gallery and all subgalleries. Applying it updates this gallery date range only; branch review can also update daily subgalleries.', [
            'images' => (string) (int) ($suggestion['exif_image_count'] ?? 0),
        ])
        : t('admin.gallery_editor.exif_date_suggestion_empty', 'No scanned EXIF capture dates were found in this gallery branch yet. Scan/import images first if the files were imported before EXIF extraction existed.');
    echo '<div class="admin-date-range-suggestion" data-admin-gallery-date-suggestion data-admin-gallery-date-endpoint="' . e(url_for('admin_gallery_date_suggestion')) . '" data-admin-gallery-date-gallery-id="' . $galleryId . '" data-admin-gallery-date-csrf="' . e(csrf_token()) . '">';
    echo '<div class="admin-date-range-suggestion-copy"><strong>' . e($suggestionTitle) . '</strong>';
    // $suggestedLabel stores the visible From/To range suggested for this gallery branch.
    $suggestedLabel = (string) ($dateModel['exif_suggestion_label'] ?? '');
    if ($suggestion) {
        echo '<span class="admin-date-range-suggested-value">' . e($suggestedLabel) . '</span>';
    }
    echo '</div><details class="admin-inline-help"><summary aria-label="' . e($suggestionTitle) . '" title="' . e($suggestionTitle) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content"><p>' . e($suggestionHelp) . '</p><a href="' . e(url_for('admin_gallery_dates', ['gallery_id' => $galleryId])) . '">' . e(t('admin.gallery_editor.exif_date_review_branch', 'Review branch suggestions')) . '</a></div></details>';
    echo '<div class="admin-date-range-suggestion-actions">';
    if ($suggestion && empty($suggestion['matches_current'])) {
        echo '<button type="submit" name="action" value="apply_exif_date_suggestion" class="button secondary" formaction="' . e(url_for('admin_gallery_date_suggestion')) . '" formmethod="post" data-admin-gallery-date-apply>' . e(t('admin.gallery_editor.exif_date_set_automatically', 'Set automatically')) . '</button>';
    } elseif ($suggestion) {
        echo '<span class="admin-date-range-current">' . e(t('admin.gallery_dates.status_current', 'current')) . '</span>';
    }
    echo '</div></div>';
}



/**
 * Render gallery date or date-range fields for admin forms.
 *
 * @param array<string,mixed> $gallery Gallery row or gallery data.
 * @param bool $panelMode Panel mode value.
 * @param array<string,mixed> $formModel Prepared date values and schema readiness.
 * @return void Emits manual date fields with accessible on-demand guidance.
 */
function view_render_admin_gallery_date_range_fields(array $gallery = [], bool $panelMode = false, array $formModel = []): void
{
    $dateModel = is_array($formModel['date'] ?? null) ? $formModel['date'] : [];
    if (empty($dateModel['schema_ready'])) {
        if ($panelMode) {
            echo '<div class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.gallery_date_range', 'Date range')) . '</span><small>' . e(t('admin.gallery_editor.gallery_date_migration_hidden', 'Gallery date will be available after the database migration is applied.')) . '</small></div>';
            return;
        }
        echo '<p class="muted">' . e(t('admin.gallery_editor.gallery_date_migration_hidden', 'Gallery date will be available after the database migration is applied.')) . '</p>';
        return;
    }

    // $startValue stores the current range start for the native date input.
    $startValue = (string) ($dateModel['start_value'] ?? '');
    // $endValue stores the current range end for the native date input when the migration is available.
    $endValue = (string) ($dateModel['end_value'] ?? '');

    if (empty($dateModel['range_schema_ready'])) {
        if ($panelMode) {
            echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '</span><input name="gallery_date" type="date" value="' . e($startValue) . '"><small>' . e(t('admin.gallery_editor.gallery_date_help', 'Optional manual gallery date, for example an event, trip, or shooting date.')) . '</small></label>';
            return;
        }
        echo '<label class="admin-date-picker-field">' . e(t('admin.gallery_editor.gallery_date', 'Date')) . '<input name="gallery_date" type="date" value="' . e($startValue) . '"><span class="muted">' . e(t('admin.gallery_editor.gallery_date_help', 'Optional manual gallery date, for example an event, trip, or shooting date.')) . '</span></label>';
        return;
    }

    if ($panelMode) {
        echo '<div class="admin-side-panel-field admin-side-panel-field-wide admin-date-range-field"><span>' . e(t('admin.gallery_editor.gallery_date_range', 'Date range')) . '</span><div class="admin-date-range-inputs">';
        echo '<label><small>' . e(t('admin.gallery_editor.gallery_date_from', 'From')) . '</small><input name="gallery_date" type="date" value="' . e($startValue) . '"></label>';
        echo '<label><small>' . e(t('admin.gallery_editor.gallery_date_to', 'To')) . '</small><input name="gallery_date_end" type="date" value="' . e($endValue) . '"></label>';
        echo '</div><small>' . e(t('admin.gallery_editor.gallery_date_range_help', 'Optional manual date range for an event, trip, or photo series. Leave To empty for a single date.')) . '</small></div>';
        return;
    }

    $rangeLabel = t('admin.gallery_editor.gallery_date_range', 'Date range');
    echo '<fieldset class="admin-date-range-field"><legend>' . e($rangeLabel) . '</legend><details class="admin-inline-help admin-date-range-help"><summary aria-label="' . e($rangeLabel) . '" title="' . e($rangeLabel) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e(t('admin.gallery_editor.gallery_date_range_help', 'Optional manual date range for an event, trip, or photo series. Leave To empty for a single date.')) . '</div></details><div class="admin-date-range-inputs">';
    echo '<label>' . e(t('admin.gallery_editor.gallery_date_from', 'From')) . '<input name="gallery_date" type="date" value="' . e($startValue) . '"></label>';
    echo '<label>' . e(t('admin.gallery_editor.gallery_date_to', 'To')) . '<input name="gallery_date_end" type="date" value="' . e($endValue) . '"></label>';
    echo '</div>';
    view_render_admin_gallery_date_exif_suggestion($gallery, $formModel);
    echo '</fieldset>';
}

/**
 * Render the gallery title field with inline completion metadata.
 *
 * The visible completion is client-side only. The submitted field remains the
 * normal `title` input, so accepting or ignoring a suggestion does not alter the
 * create-gallery request contract.
 *
 * @param array<string,mixed> $formModel Prepared gallery form presentation data.
 * @return void Emit the accessible title completion field.
 */
function view_render_admin_new_gallery_title_input(array $formModel = []): void
{
    $completion = is_array($formModel['title_completion'] ?? null) ? $formModel['title_completion'] : [];
    $candidates = is_array($completion['candidates'] ?? null) ? $completion['candidates'] : [];
    $candidateJson = json_encode(
        $candidates,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    if (!is_string($candidateJson)) {
        $candidateJson = '[]';
    }

    echo '<span class="admin-gallery-title-completion" data-gallery-title-completion data-gallery-title-completion-candidates="' . e($candidateJson) . '" data-gallery-title-completion-url="' . e((string) ($completion['url'] ?? '')) . '" data-gallery-title-completion-announcement="' . e(t('admin.gallery_title_completion.suggestion', 'Suggested title: {title}.')) . '">';
    $helpId = 'gallery-title-help-' . bin2hex(random_bytes(8));
    // Keep the accessible name stable: the surrounding label also contains the
    // help and live region, which must not become part of the field's name.
    echo '<input name="title" value="' . e((string) (($formModel['submitted'] ?? [])['title'] ?? '')) . '" required autocomplete="off" aria-label="' . e(t('admin.gallery_editor.gallery_name', 'Gallery name')) . '" aria-describedby="' . e($helpId) . '" data-gallery-title-completion-input>';
    echo '<span class="admin-gallery-title-completion-overlay" aria-hidden="true" data-gallery-title-completion-overlay hidden><span class="admin-gallery-title-completion-prefix" data-gallery-title-completion-prefix></span><span class="admin-gallery-title-completion-tail" data-gallery-title-completion-tail></span></span>';
    echo '<span class="admin-gallery-title-completion-accessible" id="' . e($helpId) . '" data-gallery-title-completion-help>' . e(t('admin.gallery_title_completion.help', 'Type at least two characters for a title suggestion. At the end of the field, press Tab or Right Arrow to accept it, or Escape to dismiss it.')) . '</span>';
    echo '<span class="admin-gallery-title-completion-accessible" data-gallery-title-completion-status role="status" aria-live="polite" aria-atomic="true"></span>';
    echo '</span>';
}

/**
 * Render shared creation fields using the controller's parent picker and operation key.
 *
 * Used by server-rendered view helpers.
 *
 * @param int $prefillParentId Prefill parent id identifier.
 * @param bool $panelMode Panel mode value.
 * @param string $workflow Workflow value.
 * @param array{operation_key?:string,parent_picker_html?:string,count_badge?:array{schema_ready?:bool,options?:list<array{value:string,label:string}>},...} $formModel Prepared replay identity, picker markup and optional editor presentation models passed to field renderers.
 * @return void Emit creation fields without looking up galleries or generating operation keys.
 */
function view_render_admin_new_gallery_fields(int $prefillParentId, bool $panelMode, string $workflow = 'create', array $formModel = []): void
{
    echo '<input type="hidden" name="operation_key" value="' . e((string) ($formModel['operation_key'] ?? '')) . '" data-admin-operation-key>';
    if ($workflow === 'create') {
        if ($panelMode) {
            echo '<input type="hidden" name="panel" value="1">';
            echo '<input type="hidden" name="parent_id" value="' . $prefillParentId . '">';
            echo '<div class="admin-side-panel-card admin-side-panel-primary-card admin-gallery-create-quick">';
            echo '<label><span>' . e(t('admin.gallery_editor.gallery_name', 'Gallery name')) . '</span>';
            view_render_admin_new_gallery_title_input($formModel);
            echo '</label></div>';
            return;
        }
        echo '<section class="gallery-create-content"><h2>' . e(t('admin.galleries.create_content', 'Gallery content')) . '</h2>';
        view_render_admin_new_gallery_quick_fields($formModel, $panelMode);
        echo '</section><section class="gallery-create-settings"><h2>' . e(t('admin.galleries.create_settings', 'Location and visibility')) . '</h2>';
        view_render_admin_new_gallery_advanced_fields($formModel, $panelMode, $prefillParentId);
        echo '</section>';
        return;
    }
    if ($panelMode) {
        echo '<input type="hidden" name="panel" value="1">';
        $isUploadWorkflow = $workflow === 'upload';
        $panelHelp = $isUploadWorkflow ? t('admin.upload.gallery_identity_help', 'Create an empty gallery, or select photos and upload them immediately.') : t('admin.gallery_editor.only_gallery_created_here', 'Only the gallery is created here.');
        $panelKicker = $isUploadWorkflow ? t('admin.upload.new_child_gallery', 'New child gallery') : t('admin.gallery_editor.new_gallery_kicker', 'New gallery');
        echo '<div class="admin-side-panel-card admin-side-panel-primary-card"><div class="admin-side-panel-card-heading"><div><p class="admin-kicker">' . e($panelKicker) . '</p><h3>' . e(t('admin.gallery_editor.gallery_identity', 'Gallery identity')) . '</h3></div><p class="muted">' . e($panelHelp) . '</p></div><div class="admin-side-panel-field-grid">';
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.gallery_name', 'Gallery name')) . '</span>';
        view_render_admin_new_gallery_title_input($formModel);
        echo '</label>';
        echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.folder_name', 'Folder name')) . '</span><input name="folder_name" autocomplete="off"><small>' . e(t('admin.gallery_editor.derive_from_gallery_name', 'Leave empty to derive it from the gallery name.')) . '</small></label>';
        echo '<label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.metric_visibility')) . '</span><select name="visibility">' . visibility_options('unpublished') . '</select></label>';
        view_render_admin_gallery_date_range_fields([], true, $formModel);
        echo (string) ($formModel['parent_picker_html'] ?? '');
        echo '<label class="admin-side-panel-field admin-side-panel-field-wide"><span>' . e(t('admin.gallery_editor.description', 'Description')) . '</span><textarea name="description" rows="4"></textarea></label>';
        view_render_gallery_description_formatting_hint();
        echo '</div><div class="admin-side-panel-toggle-row">';
        echo '<label><input type="checkbox" name="voting_enabled" value="1"> <span>' . e(t('admin.gallery_editor.enable_image_voting_short', 'Enable image voting')) . '</span></label>';
        echo '<label><input type="checkbox" name="show_filenames" value="1"> <span>' . e(t('admin.gallery_editor.show_file_names', 'Show file names')) . '</span></label>';
        echo '</div></div>';
        if (!empty(($formModel['count_badge'] ?? [])['schema_ready'])) {
            echo '<div class="admin-side-panel-card"><label class="admin-side-panel-field"><span>' . e(t('admin.gallery_editor.count_badge_title', 'Contained-picture badge')) . '</span><select name="count_badge_visibility">';
            foreach ((array) (($formModel['count_badge'] ?? [])['options'] ?? []) as $countBadgeOption) {
                $value = (string) ($countBadgeOption['value'] ?? '');
                echo '<option value="' . e($value) . '"' . ($value === 'inherit' ? ' selected' : '') . '>' . e((string) ($countBadgeOption['label'] ?? $value)) . '</option>';
            }
            echo '</select><small>' . e(t('admin.gallery_editor.count_badge_new_gallery_help', 'Controls the stacked-picture branch image count on this gallery card and its opened-gallery hero.')) . '</small></label></div>';
        }
        return;
    }

    echo '<label>' . e(t('admin.gallery_editor.gallery_name', 'Gallery name'));
    view_render_admin_new_gallery_title_input($formModel);
    echo '</label>';
    echo '<label>' . e(t('admin.gallery_editor.folder_name', 'Folder name')) . '<input name="folder_name" autocomplete="off"><span class="muted">' . e(t('admin.gallery_editor.derive_from_gallery_name', 'Leave empty to derive it from the gallery name.')) . '</span></label>';
    echo (string) ($formModel['parent_picker_html'] ?? '');
    echo '<label>' . e(t('admin.gallery_editor.visibility', 'Visibility')) . '<select name="visibility">' . visibility_options('unpublished') . '</select></label>';
    view_render_admin_gallery_date_range_fields([], false, $formModel);
    echo '<label><input type="checkbox" name="voting_enabled" value="1"> ' . e(t('admin.gallery_editor.enable_image_voting', 'Enable image voting for this gallery')) . '</label>';
    echo '<label><input type="checkbox" name="show_filenames" value="1"> ' . e(t('admin.gallery_editor.show_file_names', 'Show file names')) . '</label>';
    if (!empty(($formModel['count_badge'] ?? [])['schema_ready'])) {
        echo '<label>' . e(t('admin.gallery_editor.count_badge_title', 'Contained-picture badge')) . '<select name="count_badge_visibility">';
        foreach ((array) (($formModel['count_badge'] ?? [])['options'] ?? []) as $countBadgeOption) {
            $value = (string) ($countBadgeOption['value'] ?? '');
            echo '<option value="' . e($value) . '"' . ($value === 'inherit' ? ' selected' : '') . '>' . e((string) ($countBadgeOption['label'] ?? $value)) . '</option>';
        }
        echo '</select><span class="muted">' . e(t('admin.gallery_editor.count_badge_new_gallery_help', 'Controls the stacked-picture branch image count on this gallery card and its opened-gallery hero.')) . '</span></label>';
    }
    echo '<label>' . e(t('admin.gallery_editor.description', 'Description')) . '<textarea name="description"></textarea></label>';
    view_render_gallery_description_formatting_hint();
}

/**
 * Render the frequent fields for an empty gallery before optional adjustments.
 *
 * @param array<string,mixed> $formModel Prepared creation presentation data.
 * @param bool $panelMode Whether this is the side-panel form.
 * @return void Emit the primary field group.
 */
function view_render_admin_new_gallery_quick_fields(array $formModel, bool $panelMode): void
{
    $preferences = (array) ($formModel['creation_preferences'] ?? []);
    $submitted = (array) ($formModel['submitted'] ?? []);
    $localization = (array) ($formModel['localization'] ?? []);
    $language = (string) ($submitted['content_language'] ?? $preferences['content_language'] ?? '');
    if ($language === '' && !array_key_exists('content_language', $submitted)) {
        $publicLanguage = (string) ($localization['default_source_language'] ?? '');
        if (in_array($publicLanguage, (array) ($localization['languages'] ?? []), true)) {
            $language = $publicLanguage;
        }
    }
    echo '<div class="admin-side-panel-card admin-side-panel-primary-card admin-gallery-create-quick">';
    echo '<label><span>' . e(t('admin.gallery_editor.gallery_name', 'Gallery name')) . '</span>';
    view_render_admin_new_gallery_title_input($formModel);
    echo '</label>';
    // Each render receives an independent textarea label, including dynamically mounted fragments.
    $descriptionId = 'gallery-create-description-' . bin2hex(random_bytes(8));
    echo '<div class="gallery-create-description-heading"><label for="' . e($descriptionId) . '">' . e(t('admin.gallery_editor.description', 'Description')) . '</label>';
    view_render_gallery_description_formatting_hint();
    echo '</div><textarea id="' . e($descriptionId) . '" name="description" rows="4" data-gallery-description-textarea>' . e((string) ($submitted['description'] ?? '')) . '</textarea>';
    echo '<label class="gallery-create-tags"><span>' . e(t('admin.gallery_editor.tags', 'Tags')) . '</span><input name="tags" aria-label="' . e(t('admin.gallery_editor.tags', 'Tags')) . '" value="' . e((string) ($submitted['tags'] ?? '')) . '" list="tag-suggestions" data-tag-input' . (string) ($formModel['tag_suggestions_attribute'] ?? '') . '><small>' . e(t('admin.gallery_editor.tags_help', 'Separate tags with commas.')) . '</small></label>';
    echo (string) ($formModel['tag_datalist_html'] ?? '');
    if (!empty($localization['enabled']) && !empty($localization['schema_ready'])) {
        echo '<div class="gallery-create-language"><label><span>' . e(t('admin.galleries.create_language', 'Content language')) . '</span><select name="content_language" data-content-language-select>';
        echo '<option value=""' . ($language === '' ? ' selected' : '') . '>' . e(t('admin.content_localization.not_specified', 'Not specified')) . '</option>';
        foreach ((array) ($localization['languages'] ?? []) as $code) {
            $presentation = (array) (($localization['presentation'] ?? [])[$code] ?? []);
            echo '<option value="' . e((string) $code) . '"' . ($language === $code ? ' selected' : '') . '>' . e((string) ($presentation['name'] ?? strtoupper((string) $code))) . '</option>';
        }
        echo '</select></label>';
        if (!empty($formModel['creation_preferences_available'])) {
            echo '<label class="checkbox-label"><input type="checkbox" name="remember_content_language" value="1"' . (!empty($submitted['remember_content_language']) ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.remember_language', 'Remember this as my default language')) . '</label>';
        }
        echo '</div>';
    }
    if (!empty($formModel['simbrief_enabled'])) {
        echo '<details class="gallery-create-simbrief"' . (!empty($submitted['simbrief_draft_ref']) ? ' open' : '') . '><summary>' . e(t('admin.galleries.create_simbrief', 'Import from SimBrief')) . '</summary>';
        view_render_admin_simbrief_description_tool(0, $formModel);
        echo '<input type="hidden" name="simbrief_draft_ref" value="' . e((string) ($submitted['simbrief_draft_ref'] ?? '')) . '" data-simbrief-draft-ref></details>';
    }
    view_render_content_localization_fields('gallery', [], $formModel, 'translations');
    echo '</div>';
}

/**
 * Keep destination and visibility visible while disclosing secondary creation controls.
 *
 * @param array<string,mixed> $formModel Prepared creation presentation data.
 * @param bool $panelMode Whether this is the side-panel form.
 * @param int $prefillParentId Selected parent gallery ID.
 * @return void Emits prepared location, date and optional controls without altering submitted field names.
 */
function view_render_admin_new_gallery_advanced_fields(array $formModel, bool $panelMode, int $prefillParentId): void
{
    $submitted = (array) ($formModel['submitted'] ?? []);
    echo '<div class="gallery-create-location">' . (string) ($formModel['parent_picker_html'] ?? '') . '</div>';
    $visibility = (string) ($submitted['visibility'] ?? 'unpublished');
    echo '<label><span>' . e(t('admin.gallery_editor.visibility', 'Visibility')) . '</span><select name="visibility">' . visibility_options($visibility) . '</select></label>';
    view_render_admin_gallery_date_range_fields([], $panelMode, $formModel);
    echo '<details class="admin-side-panel-card admin-gallery-advanced-settings"' . ($submitted !== [] ? ' open' : '') . '><summary>' . e(t('admin.galleries.create_optional', 'Optional settings')) . '</summary><div class="admin-gallery-create-advanced-fields">';
    echo '<label><span>' . e(t('admin.gallery_editor.folder_name', 'Folder name')) . '</span><input name="folder_name" value="' . e((string) ($submitted['folder_name'] ?? '')) . '" autocomplete="off"><small>' . e(t('admin.gallery_editor.derive_from_gallery_name', 'Leave empty to derive it from the gallery name.')) . '</small></label>';
    echo '<label class="checkbox-label"><input type="checkbox" name="voting_enabled" value="1"' . (!empty($submitted['voting_enabled']) ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.enable_image_voting', 'Enable image voting for this gallery')) . '</label>';
    echo '<label class="checkbox-label"><input type="checkbox" name="show_filenames" value="1"' . (!empty($submitted['show_filenames']) ? ' checked' : '') . '> ' . e(t('admin.gallery_editor.show_file_names', 'Show file names')) . '</label>';
    if (!empty(($formModel['count_badge'] ?? [])['schema_ready'])) {
        echo '<label><span>' . e(t('admin.gallery_editor.count_badge_title', 'Contained-picture badge')) . '</span><select name="count_badge_visibility">';
        foreach ((array) (($formModel['count_badge'] ?? [])['options'] ?? []) as $option) {
            $value = (string) ($option['value'] ?? '');
            echo '<option value="' . e($value) . '"' . ($value === (string) ($submitted['count_badge_visibility'] ?? 'inherit') ? ' selected' : '') . '>' . e((string) ($option['label'] ?? $value)) . '</option>';
        }
        echo '</select></label>';
    }
    echo '</div></details>';
}

/**
 * Handle view render admin new gallery side panel.
 *
 * Used by server-rendered view helpers.
 *
 * @param int $prefillParentId Prefill parent id identifier.
 * @param ?array $prefillParentGallery Prefill parent gallery value.
 * @param string $error Error value.
 * @param array<string,mixed> $formModel Prepared title completion and replay identity.
 * @return void Emit the name-only create panel.
 */
function view_render_admin_new_gallery_side_panel(int $prefillParentId, ?array $prefillParentGallery, string $error, array $formModel = []): void
{
    echo '<div class="admin-side-panel-stack gallery-create-panel" data-gallery-create-panel>';
    echo '<div class="admin-side-panel-copy"><h2>' . e(t('admin.gallery_editor.create_gallery', 'Create gallery')) . '</h2><p class="muted">' . e(t('admin.gallery_editor.name_first_help', 'Enter the gallery name. After creation, its editor opens for all other settings.')) . '</p></div>';
    echo '<p class="gallery-create-destination">' . e($prefillParentId > 0
        ? t('admin.galleries.create_destination', 'Create in: {gallery}', ['gallery' => (string) ($prefillParentGallery['title'] ?? $formModel['parent_summary'] ?? '#' . $prefillParentId)])
        : t('admin.galleries.create_root', 'Top-level gallery')) . '</p>';
    if ($error !== '') {
        echo '<div class="notice error" role="alert">' . e(t('admin.galleries.create_failed', ['error' => $error])) . '</div>';
    }
    echo '<section class="admin-side-panel-workflow" data-gallery-panel-workflow>';
    echo '<form method="post" action="' . e(url_for('admin_new_gallery')) . '" class="admin-side-panel-form" data-gallery-panel-create-form>' . csrf_field();
    view_render_admin_new_gallery_fields($prefillParentId, true, 'create', $formModel);
    echo '<div class="admin-side-panel-actions"><button type="submit" class="button primary" data-gallery-panel-submit>' . e(t('admin.gallery_editor.create_gallery', 'Create gallery')) . '</button></div>';
    echo '</form></section>';
    echo '</div>';
}

/**
 * Handle view render admin simbrief description tool.
 *
 * Used by server-rendered view helpers.
 *
 * @param int $galleryId Gallery identifier.
 * @param array<string,mixed> $formModel Prepared creation defaults, if applicable.
 * @param array<string,mixed> $flightMap Controller-prepared route-map state.
 * @return void Emit the SimBrief controls.
 */
function view_render_admin_simbrief_description_tool(int $galleryId, array $formModel = [], array $flightMap = []): void
{
    $preferences = (array) ($formModel['creation_preferences'] ?? []);
    $submitted = (array) ($formModel['submitted'] ?? []);
    $savedIdentifier = trim((string) ($preferences['simbrief_pilot_id'] ?? ''));
    if ($savedIdentifier === '') {
        $savedIdentifier = trim((string) ($preferences['simbrief_pilot_name'] ?? ''));
    }
    $submittedIdentifier = null;
    if (array_key_exists('simbrief_identifier', $submitted)) {
        $submittedIdentifier = (string) $submitted['simbrief_identifier'];
    } elseif (array_key_exists('simbrief_pilot_id', $submitted) || array_key_exists('simbrief_pilot_name', $submitted)) {
        $submittedIdentifier = trim((string) ($submitted['simbrief_pilot_id'] ?? ''));
        if ($submittedIdentifier === '') {
            $submittedIdentifier = (string) ($submitted['simbrief_pilot_name'] ?? '');
        }
    }
    $identifier = $submittedIdentifier ?? $savedIdentifier;
    $prefilled = $submittedIdentifier === null && $savedIdentifier !== '';
    // Creation retries carry the controller's expanded preference flags; editors may retain the compact flag.
    $rememberIdentifier = !empty($submitted['remember_simbrief_identifier'])
        || !empty($submitted['remember_simbrief_pilot_id']) || !empty($submitted['remember_simbrief_pilot_name']);
    echo '<div class="admin-simbrief-description" data-simbrief-description-tool data-simbrief-endpoint="' . e(url_for('admin_simbrief_description')) . '" data-gallery-id="' . (int) $galleryId . '">';
    if ($galleryId > 0) {
        echo '<input type="hidden" name="simbrief_draft_ref" value="' . e((string) ($submitted['simbrief_draft_ref'] ?? '')) . '" data-simbrief-draft-ref>';
    }
    echo '<div class="admin-simbrief-description-heading"><h3>' . e(t('admin.simbrief.title', 'Generate from SimBrief')) . '</h3><details class="admin-inline-help"><summary aria-label="' . e(t('admin.simbrief.help_label', 'About SimBrief import')) . '" title="' . e(t('admin.simbrief.help_label', 'About SimBrief import')) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e(t('admin.simbrief.help', 'Fetch the latest SimBrief OFP and create an editable gallery-description draft. Nothing is saved until you save the gallery.')) . '</div></details></div>';
    echo '<div class="admin-simbrief-description-main"><label class="admin-simbrief-identifier"><span>' . e(t('admin.simbrief.identifier', 'Pilot ID or name')) . '</span><input name="simbrief_identifier" value="' . e($identifier) . '" autocomplete="off" data-simbrief-identifier></label>';
    if (!empty($formModel['creation_preferences_available'])) {
        echo '<label class="checkbox-label admin-simbrief-remember"><input type="checkbox" name="remember_simbrief_identifier" value="1"' . ($rememberIdentifier ? ' checked' : '') . '> ' . e(t('admin.simbrief.remember_identifier', 'Remember for future galleries')) . '</label>';
    }
    echo '<button type="button" class="button secondary" data-simbrief-generate>' . e(t('admin.simbrief.generate_button', 'Generate description draft')) . '</button>';
    view_render_admin_gallery_route_disclosure($flightMap);
    echo '</div>';
    if ($prefilled) {
        echo '<small class="admin-gallery-saved-default-note">' . e(t('admin.gallery_editor.prefilled_default', 'Pre-filled from your saved defaults.')) . '</small>';
    }
    echo '<span class="muted" data-simbrief-status role="status" aria-live="polite"></span></div>';
}

/**
 * Render the compact route disclosure beside SimBrief generation.
 *
 * @param array<string, mixed> $flightMap Controller-prepared route-map state.
 * @return void Emit the route control when ready or its migration notice.
 */
function view_render_admin_gallery_route_disclosure(array $flightMap): void
{
    if (($flightMap['state'] ?? 'hidden') === 'ready') {
        $title = (string) ($flightMap['title'] ?? 'Flight route map');
        echo '<details class="admin-gallery-route-disclosure"><summary aria-label="' . e($title) . '" title="' . e($title) . '"><svg aria-hidden="true" viewBox="0 0 24 24" focusable="false"><path d="M3 18c4 0 3-7 7-7s3 5 7 5 2-9 4-9" /></svg></summary><div class="admin-gallery-route-content"><label><span>' . e((string) ($flightMap['label'] ?? 'Route text')) . '</span><textarea name="flight_route_text" rows="3" placeholder="LKPR DCT OKL DCT EDDF or LKPR@50.1008,14.2632 DCT EDDF@50.0379,8.5622">' . e((string) ($flightMap['route_text'] ?? '')) . '</textarea></label>';
        echo '<p class="muted admin-gallery-route-status">' . e((string) ($flightMap['status'] ?? '')) . '</p>';
        $help = (string) ($flightMap['help'] ?? '');
        if (trim($help) !== '') {
            echo '<details class="admin-inline-help"><summary aria-label="' . e(t('admin.gallery_editor.help_for', 'Help for {label}', ['label' => $title])) . '" title="' . e($title) . '"><span aria-hidden="true">?</span></summary><div class="admin-inline-help-content">' . e($help) . '</div></details>';
        }
        echo '</div></details>';
    } elseif (($flightMap['state'] ?? 'hidden') === 'migration') {
        echo '<p class="muted admin-gallery-route-migration">' . e((string) ($flightMap['migration_message'] ?? '')) . '</p>';
    }
}

/**
 * Render optional OpenAI text-assistance controls for a description textarea.
 *
 * @param int $galleryId Gallery id used for gallery-level prompt context, or zero for photo-only editors.
 * @param int $imageId Image id used for photo-level prompt context, or zero for gallery editors.
 * @param string $mode UI mode, either gallery or image.
 */
function view_render_admin_openai_text_assist_tool(int $galleryId, int $imageId = 0, string $mode = 'gallery', array $formModel = []): void
{
    $openAi = is_array($formModel['openai'] ?? null) ? $formModel['openai'] : [];
    if (empty($openAi['available'])) {
        return;
    }

    $mode = $mode === 'image' ? 'image' : 'gallery';
    $taskDefault = $mode === 'image' ? 'image_description' : 'gallery_description';
    $title = $mode === 'image'
        ? t('admin.openai.image_title', 'AI photo text')
        : t('admin.openai.gallery_title', 'AI gallery text');
    $help = $mode === 'image'
        ? t('admin.openai.image_help', 'Generate or clean up this public photo description. Nothing is saved until you save the photo.')
        : t('admin.openai.gallery_help', 'Generate or clean up this gallery description. Nothing is saved until you save the gallery.');
    $button = $mode === 'image'
        ? t('admin.openai.generate_image_button', 'Generate photo description')
        : t('admin.openai.generate_gallery_button', 'Generate gallery description');
    $allowImageInput = !empty($openAi['allow_image_input']);
    $languageCatalog = (array) ($openAi['language_catalog'] ?? []);
    $defaultLanguage = (string) ($openAi['default_language'] ?? 'en');

    echo '<div class="admin-openai-text-assist" data-openai-text-assist data-openai-endpoint="' . e(url_for('admin_openai_text_assist')) . '" data-gallery-id="' . (int) $galleryId . '" data-image-id="' . (int) $imageId . '" data-openai-target-selector="[data-openai-description-textarea]">';
    echo '<div class="admin-openai-text-assist-heading"><div><h3>' . e($title) . '</h3><p class="muted">' . e($help) . '</p></div></div>';
    echo '<div class="admin-openai-text-assist-actions"><label><span>' . e(t('admin.openai.action_label', 'Action')) . '</span><select data-openai-task>';
    if ($mode === 'image') {
        echo '<option value="image_description" selected>' . e(t('admin.openai.task_image_description', 'Generate photo description')) . '</option>';
        if ($allowImageInput) {
            echo '<option value="image_visual_description">' . e(t('admin.openai.task_image_visual_description', 'Describe visible content from thumbnail')) . '</option>';
        }
    } else {
        echo '<option value="gallery_description" selected>' . e(t('admin.openai.task_gallery_description', 'Generate leaf-gallery description')) . '</option>';
        echo '<option value="gallery_summary">' . e(t('admin.openai.task_gallery_summary', 'Summarize parent gallery')) . '</option>';
        if ($allowImageInput) {
            echo '<option value="gallery_visual_description">' . e(t('admin.openai.task_gallery_visual_description', 'Generate from gallery thumbnails')) . '</option>';
        }
    }
    echo '<option value="cleanup_text">' . e(t('admin.openai.task_cleanup_text', 'Fix spelling and grammar')) . '</option>';
    echo '<option value="expand_text">' . e(t('admin.openai.task_expand_text', 'Expand existing text')) . '</option>';
    echo '</select></label>';
    if ($languageCatalog !== []) {
        echo '<label class="admin-openai-language-select"><span>' . e(t('admin.openai.language_label', 'Language')) . '</span><select data-openai-language>';
        foreach ($languageCatalog as $languageCode => $languageInfo) {
            $optionLabel = trim((string) ($languageInfo['flag'] ?? '') . ' ' . (string) ($languageInfo['label'] ?? $languageCode));
            echo '<option value="' . e((string) $languageCode) . '"' . ($defaultLanguage === $languageCode ? ' selected' : '') . '>' . e($optionLabel) . '</option>';
        }
        echo '</select></label>';
    }
    echo '<button type="button" class="button secondary" data-openai-generate>' . e($button) . '</button>';
    echo '<span class="muted" data-openai-status role="status" aria-live="polite"></span></div>';
    if ($allowImageInput) {
        echo '<p class="muted admin-openai-text-assist-note">' . e(t('admin.openai.visual_note', 'Image-based actions send only small generated thumbnails, never the original files.')) . '</p>';
        if ($mode === 'gallery') {
            echo '<div class="admin-openai-text-assist-bulk">';
            echo '<button type="button" class="button secondary" data-openai-bulk-generate>' . e(t('admin.openai.bulk_generate_images_button', 'Bulk describe gallery photos')) . '</button>';
            echo '<p class="muted">' . e(t('admin.openai.bulk_generate_images_help', 'Generates and saves photo descriptions one photo at a time. You will confirm the exact number before it starts.')) . '</p>';
            echo '</div>';
        }
    }
    echo '</div>';
}
