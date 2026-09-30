<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_setup_wizard_rendering_test.php
 * Module Type: Test Script
 * Purpose: Verify Setup Wizard server-rendered navigation, fields, summary, translations, and previews.
 * Responsibilities:
 *   - Exercise real view helpers with deterministic fixture data.
 *   - Assert escaping, step controls, summary state, and shared selector output.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Escape a rendered fixture value using the application HTML contract.
     * @param string|int|float|bool|list<string|int|float|bool>|array<string,mixed>|null $value Fixture value.
     * @return string Escaped HTML text.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Return deterministic CSRF markup for focused form assertions.
     * @return string Hidden CSRF fixture markup.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf" value="fixture">';
    }

    /** Render a bounded header marker without loading application bootstrap state.
     * @param string $title Header title.
     * @return void
     */
    function render_header(string $title = ''): void
    {
        echo '<div data-test-header="' . e($title) . '">';
    }

    /** Close the deterministic focused-render header marker.
     * @return void
     */
    function render_footer(): void
    {
        echo '</div>';
    }

    /** Return a deterministic local route used by the canonical language renderer.
     * @param string $page Route page identifier.
     * @param array<string,mixed> $params Route parameters.
     * @return string Deterministic route.
     */
    function url_for(string $page, array $params = []): string
    {
        return '/index.php?page=' . rawurlencode($page);
    }

    /** Return a deterministic local asset URL used by language flag fixtures.
     * @param string $path Asset path.
     * @return string Deterministic asset URL.
     */
    function asset_url(string $path): string
    {
        return '/assets/' . ltrim($path, '/');
    }
}

namespace Gallery\Services {
    /** Translate only through the explicit fallback supplied by the real view.
     * @param string $key Translation key.
     * @param string $fallback Fixture fallback text.
     * @param array<string,string|int|float> $parameters Interpolation values.
     * @return string Translated fixture text.
     */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        $GLOBALS['admin_setup_wizard_render_translation_calls'][] = $key;
        $text = $fallback !== '' ? $fallback : $key;
        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }
}

namespace {
    use function Gallery\Views\view_render_admin_setup_wizard_page;

    $root = dirname(__DIR__);
    require_once $root . '/app/views/admin_theme.php';
    require_once $root . '/app/views/admin_language_settings.php';
    require_once $root . '/app/views/admin_setup_wizard.php';

    /**
     * Assert every registry-owned Wizard label, hint, and example is translated.
     * Sources mirror the three extraction patterns used by the translation owner.
     * @return void
     */
    function admin_setup_wizard_assert_translation_coverage(): void
    {
        global $root;
        $registry = (string) file_get_contents($root . '/app/services/admin_settings_registry.php');
        $features = (string) file_get_contents($root . '/app/services/feature_flags/registry.php');
        $ids = [];
        preg_match_all("~^\\s*'[^']+'\\s*=>\\s*admin_settings_entry\\('([^']+)'~m", $registry, $primary);
        preg_match_all("~^\\s*\\['([^']+)',\\s*'[^']+',~m", $registry, $specialized);
        foreach (array_merge($primary[1] ?? [], $specialized[1] ?? []) as $id) $ids[(string) $id] = true;
        $start = strpos($features, 'function feature_capability_definitions');
        $end = strpos($features, 'foreach ($definitions as &$definition)', $start ?: 0);
        $featureBlock = substr($features, (int) $start, (int) $end - (int) $start);
        preg_match_all("~^\\s{8}'([^']+)'\\s*=>\\s*\\[~m", $featureBlock, $featureIds);
        foreach ($featureIds[1] ?? [] as $id) $ids['feature_' . $id] = true;
        foreach (['en', 'cs', 'de', 'sv'] as $language) {
            $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true);
            admin_setup_wizard_render_assert(is_array($catalog), 'Unable to load ' . $language . ' catalog.');
            foreach (array_keys($ids) as $id) {
                foreach (['', '.hint', '.example'] as $suffix) {
                    $key = 'admin.settings.item.' . $id . $suffix;
                    admin_setup_wizard_render_assert(isset($catalog[$key]) && is_string($catalog[$key]) && trim($catalog[$key]) !== '', 'Missing ' . $language . ' Wizard translation: ' . $key);
                }
            }
        }
    }

    admin_setup_wizard_assert_translation_coverage();

    /** Fail the focused contract with a readable assertion message.
     * @param bool $condition Assertion result.
     * @param string $message Failure message.
     * @return void
     */
    function admin_setup_wizard_render_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    }

    /** Render the real wizard view into one deterministic HTML string.
     * @param array<string,mixed> $model Wizard view model.
     * @return string Rendered HTML.
     */
    function admin_setup_wizard_render(array $model): string
    {
        ob_start();
        view_render_admin_setup_wizard_page($model);
        return (string) ob_get_clean();
    }

    /** Parse HTML with DOM when the extension is available, otherwise return null for regex fallback checks.
     * @param string $html Rendered HTML.
     * @return \DOMDocument|null Parsed document or null when unavailable.
     */
    function admin_setup_wizard_render_dom(string $html): ?\DOMDocument
    {
        if (!class_exists(\DOMDocument::class)) {
            return null;
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $document;
    }

    /** Build one presentation-ready registry entry for the focused fixture.
     * @param string $id Setting identifier.
     * @param string $inputType Control type.
     * @param string|int|float|bool|list<string|int|float|bool>|array<string,mixed>|null $current Persisted value.
     * @param bool $editable Whether the entry is editable.
     * @param array<string,mixed> $extra Additional entry metadata.
     * @return array<string,mixed> Prepared registry entry.
     */
    function admin_setup_wizard_render_entry(string $id, string $inputType, mixed $current, bool $editable = true, array $extra = []): array
    {
        return array_replace([
            'id' => $id,
            'label' => ucwords(str_replace('_', ' ', $id)),
            'label_key' => 'admin.settings.item.' . $id,
            'description' => 'Meaningful explanation for ' . str_replace('_', ' ', $id) . '.',
            'description_key' => 'admin.settings.item.' . $id . '.hint',
            'example' => 'admin.settings.item.' . $id . '.example',
            'example_fallback' => 'A concrete value appropriate for this setting.',
            'input_type' => $inputType,
            'current' => $current,
            'value' => $current,
            'wizard_editable' => $editable,
            'included' => true,
        ], $extra);
    }

    /** Build all eight real wizard section identifiers with representative entry kinds.
     * @return array<string,array<string,mixed>> Prepared wizard steps.
     */
    function admin_setup_wizard_render_steps(): array
    {
        $sections = [
            'general' => admin_setup_wizard_render_entry('site_name', 'text', 'Saved gallery'),
            'site' => admin_setup_wizard_render_entry('base_url', 'url', 'https://example.test/gallery'),
            'appearance' => admin_setup_wizard_render_entry('theme_accent', 'color', '#336699'),
            'content' => admin_setup_wizard_render_entry('custom_css', 'specialized', 'Configured', false, ['deferred_url' => '/index.php?page=admin_theme#custom-css']),
            'media' => admin_setup_wizard_render_entry('exif_gps_maps_default_enabled', 'checkbox', '1'),
            'uploads' => admin_setup_wizard_render_entry('browser_upload_max_items_per_batch', 'number', '25', true, ['validation' => ['min' => 1, 'max' => 100]]),
            'privacy' => admin_setup_wizard_render_entry('dev_mode_enabled', 'select', '0', true, ['options' => ['0' => 'Disabled', '1' => 'Enabled']]),
            'advanced' => admin_setup_wizard_render_entry('account_credentials', 'specialized', 'Managed separately', false, ['deferred_url' => '/index.php?page=admin_account']),
        ];
        $steps = [];
        foreach ($sections as $id => $entry) {
            $steps[$id] = [
                'id' => $id,
                'definition' => ['label' => ucfirst($id), 'label_key' => 'admin.settings.section.' . $id, 'description' => 'Review the ' . $id . ' settings.', 'description_key' => 'admin.settings.section.' . $id . '_hint'],
                'entries' => [$entry['id'] => $entry],
            ];
        }
        $steps['general']['entries']['public_language_selector_enabled'] = admin_setup_wizard_render_entry('public_language_selector_enabled', 'checkbox', '1');
        $steps['general']['entries']['public_language_selector_languages'] = admin_setup_wizard_render_entry('public_language_selector_languages', 'language-multicheckbox', ['en', 'cs']);
        $steps['general']['entries']['public_language_selector_design'] = admin_setup_wizard_render_entry('public_language_selector_design', 'language-design', ['preset' => 'classic', 'show_flags' => true]);
        $steps['appearance']['entries']['theme_gallery_description_layout'] = admin_setup_wizard_render_entry('theme_gallery_description_layout', 'select', 'vertical', true, ['options' => ['vertical' => 'Vertical', 'horizontal' => 'Horizontal']]);
        $steps['appearance']['entries']['theme_gallery_count_badge_enabled'] = admin_setup_wizard_render_entry('theme_gallery_count_badge_enabled', 'checkbox', '1');
        $steps['appearance']['entries']['pagination_enabled'] = admin_setup_wizard_render_entry('pagination_enabled', 'checkbox', '1');
        $steps['appearance']['entries']['home_gallery_grid_columns'] = admin_setup_wizard_render_entry('home_gallery_grid_columns', 'range', '4', true, ['validation' => ['min' => 1, 'max' => 12, 'step' => 1]]);
        $steps['content']['entries']['tag_page_gallery_grid_columns'] = admin_setup_wizard_render_entry('tag_page_gallery_grid_columns', 'range', '3', true, ['validation' => ['min' => 1, 'max' => 12, 'step' => 1]]);
        $steps['content']['entries']['tag_page_gallery_description_layout'] = admin_setup_wizard_render_entry('tag_page_gallery_description_layout', 'select', 'horizontal', true, ['options' => ['vertical' => 'Vertical', 'horizontal' => 'Horizontal']]);
        $steps['media']['entries']['theme_lightbox_browsing_mode'] = admin_setup_wizard_render_entry('theme_lightbox_browsing_mode', 'select', 'single', true, ['options' => ['single' => 'Single photo', 'continuous' => 'Continuous']]);
        $steps['media']['entries']['public_thumbnail_rendering_mode'] = admin_setup_wizard_render_entry('public_thumbnail_rendering_mode', 'select', 'progressive', true, ['options' => ['progressive' => 'Progressive', 'responsive' => 'Responsive']]);
        return $steps;
    }

    /** Return the minimal canonical selector view model needed by its shared basic renderer.
     * @return array<string,mixed> Selector presentation model.
     */
    function admin_setup_wizard_render_language_selector(): array
    {
        return [
            'enabled' => true,
            'languages' => ['en', 'cs'],
            'supported_languages' => ['en', 'cs'],
            'presentations' => [
                'en' => ['name' => 'English', 'flag_asset' => 'flags/en.svg'],
                'cs' => ['name' => 'Čeština', 'flag_asset' => 'flags/cs.svg'],
            ],
            'design_defaults' => ['preset' => 'classic', 'show_flags' => true],
            'design' => ['preset' => 'classic', 'show_flags' => true],
            'errors' => [],
        ];
    }

    $steps = admin_setup_wizard_render_steps();
    $baseModel = [
        'steps' => $steps,
        'draft' => ['revision' => 7, 'step' => 'general', 'changes' => [], 'skips' => [], 'original' => []],
        'revision' => 7,
        'errors' => [],
        'urls' => ['action' => '/index.php?page=admin_setup_wizard', 'restart' => '/index.php?page=admin_setup_wizard'],
        'theme_preview' => ['site_name' => 'Saved gallery', 'accent' => '#336699', 'accent_dark' => '#224466', 'paper' => '#ffffff', 'panel' => '#eeeeee', 'gallery_panel' => '#dddddd', 'header_text' => '#111111', 'hero_text' => '#222222', 'radius' => 12, 'font' => 'sans', 'page_width' => 'wide', 'page_width_custom' => 1440, 'gps_pin_enabled' => '1', 'gps_pin_background_enabled' => '1', 'gps_pin_size' => '26', 'gps_pin_background_size' => '22', 'gallery_description_layout' => 'vertical', 'gallery_count_badge_enabled' => '1', 'pagination_enabled' => '1', 'pagination_columns' => '4', 'pagination_rows' => '5', 'home_gallery_grid_columns' => '4', 'home_gallery_grid_rows' => '5', 'tag_page_gallery_grid_columns' => '3', 'tag_page_gallery_grid_rows' => '4', 'tag_page_gallery_description_layout' => 'horizontal', 'lightbox_browsing_mode' => 'single', 'public_thumbnail_rendering_mode' => 'progressive', 'background_url' => ''],
        'language_selector' => admin_setup_wizard_render_language_selector(),
        'summary_groups' => [],
    ];

    // Every real section renders its own fields and never exposes the final approval prematurely.
    foreach (array_keys($steps) as $sectionId) {
        $model = $baseModel;
        $model['active_step'] = $sectionId;
        $model['draft']['step'] = $sectionId;
        if ($sectionId === 'general') {
            $model['draft']['changes']['site_name'] = '<script>alert("unsafe")</script>';
            $model['steps']['general']['entries']['site_name']['value'] = '<script>alert("unsafe")</script>';
        }
        if ($sectionId === 'uploads') {
            $model['draft']['changes']['browser_upload_max_items_per_batch'] = 'not-a-number';
            $model['steps']['uploads']['entries']['browser_upload_max_items_per_batch']['value'] = 'not-a-number';
        }
        $html = admin_setup_wizard_render($model);
        admin_setup_wizard_render_assert(str_contains($html, 'name="wizard_step" value="' . $sectionId . '"'), 'Active section must remain the rendered server step: ' . $sectionId);
        admin_setup_wizard_render_assert(!str_contains($html, 'name="approval"'), 'Approval checkbox must never render before the final summary.');
        admin_setup_wizard_render_assert(!str_contains($html, 'admin-setup-wizard-summary'), 'Staged changes must not trigger a premature summary.');
        admin_setup_wizard_render_assert(str_contains($html, 'formnovalidate') && str_contains($html, 'value="skip"'), 'Skip/back actions must bypass browser validation and remain server-owned.');
        admin_setup_wizard_render_assert(substr_count($html, 'admin-setup-wizard-actions is-top') === 1, 'Every wizard section must expose navigation before its fields.');
        admin_setup_wizard_render_assert(substr_count($html, 'admin-setup-wizard-actions is-bottom') === 1, 'Every wizard section must retain navigation after its fields.');
        admin_setup_wizard_render_assert(str_contains($html, '?page=admin_setup_wizard&amp;step=appearance'), 'Progress must expose a no-JavaScript GET fallback.');
        admin_setup_wizard_render_assert(str_contains($html, 'wizard-progress-marker') && str_contains($html, 'wizard-progress-label'), 'Progress markup must separate compact markers from the active label.');
    }

    $generalModel = $baseModel;
    $generalModel['active_step'] = 'general';
    $generalModel['draft']['changes']['site_name'] = '<script>alert("unsafe")</script>';
    $generalModel['steps']['general']['entries']['site_name']['value'] = '<script>alert("unsafe")</script>';
    $generalHtml = admin_setup_wizard_render($generalModel);
    admin_setup_wizard_render_assert(str_contains($generalHtml, 'name="settings[site_name]"'), 'Editable entries must submit through settings[id].');
    admin_setup_wizard_render_assert(str_contains($generalHtml, 'name="include[site_name]"'), 'Editable entries must submit through include[id].');
    admin_setup_wizard_render_assert(str_contains($generalHtml, '&lt;script&gt;alert(&quot;unsafe&quot;)&lt;/script&gt;') && !str_contains($generalHtml, '<script>alert'), 'Staged values must be HTML escaped.');
    admin_setup_wizard_render_assert(str_contains($generalHtml, 'data-public-language-selector-settings') && str_contains($generalHtml, 'name="settings[public_language_selector_languages][]"'), 'Wizard must reuse the canonical language-selector renderer and field names.');

    $contentModel = $baseModel;
    $contentModel['active_step'] = 'content';
    $contentModel['draft']['step'] = 'content';
    $contentHtml = admin_setup_wizard_render($contentModel);
    admin_setup_wizard_render_assert(!str_contains($contentHtml, 'name="include[custom_css]"') && !str_contains($contentHtml, 'name="settings[custom_css]"'), 'Specialist status entries must never submit staged values or fake review controls.');
    admin_setup_wizard_render_assert(str_contains($contentHtml, 'wizard-deferred-notice') && !str_contains($contentHtml, 'target="_blank"') && !str_contains($contentHtml, 'href="/index.php?page=admin_theme#custom-css"'), 'Wizard specialist entries must stay inside the draft instead of navigating to separately committed settings.');
    admin_setup_wizard_render_assert(str_contains($contentHtml, 'wizard-disclosure') && str_contains($contentHtml, 'data-wizard-subsection-panel'), 'Long sections must support progressive disclosure and in-step subsections.');
    admin_setup_wizard_render_assert(substr_count($contentHtml, 'data-theme-live-preview') === 1 && str_contains($contentHtml, 'data-theme-preview-tag-grid-columns'), 'Content must reuse the Theme preview for tag-page grid controls.');
    admin_setup_wizard_render_assert(str_contains($contentHtml, 'data-theme-preview-tag-description-layout'), 'Content tag-page card layout must drive the shared preview hook.');

    $appearanceModel = $baseModel;
    $appearanceModel['active_step'] = 'appearance';
    $appearanceModel['draft']['step'] = 'appearance';
    $appearanceHtml = admin_setup_wizard_render($appearanceModel);
    admin_setup_wizard_render_assert(substr_count($appearanceHtml, 'data-theme-live-preview') === 1 && substr_count($appearanceHtml, 'data-theme-preview-page') === 1, 'Appearance must render exactly one coordinated shared Theme preview.');
    admin_setup_wizard_render_assert(str_contains($appearanceHtml, 'data-theme-preview-color="accent"'), 'Appearance control must drive the shared preview hook.');
    admin_setup_wizard_render_assert(str_contains($appearanceHtml, 'data-theme-preview-description-layout') && str_contains($appearanceHtml, 'data-theme-preview-home-grid-columns'), 'Appearance Phase 2 layout controls must drive the shared preview hooks.');

    $mediaModel = $baseModel;
    $mediaModel['active_step'] = 'media';
    $mediaModel['draft']['step'] = 'media';
    $mediaHtml = admin_setup_wizard_render($mediaModel);
    admin_setup_wizard_render_assert(substr_count($mediaHtml, 'data-theme-live-preview') === 1, 'Media must reuse the shared Theme preview exactly once.');
    admin_setup_wizard_render_assert(str_contains($mediaHtml, 'data-theme-preview-lightbox-mode') && str_contains($mediaHtml, 'data-theme-preview-thumbnail-mode'), 'Media controls must drive lightbox and thumbnail preview state.');

    $summaryModel = $baseModel;
    $summaryModel['active_step'] = 'summary';
    $summaryModel['draft']['step'] = 'summary';
    $summaryModel['language_selector'] = [];
    $summaryModel['summary_groups'] = [[
        'id' => 'general', 'title' => 'General', 'title_key' => 'admin.settings.section.general',
        'changes' => [['id' => 'site_name', 'label' => 'Site name', 'label_key' => 'admin.settings.item.site_name', 'before' => 'Old name', 'after' => 'New name']],
        'reviewed' => [['id' => 'public_language_selector_enabled', 'label' => 'Viewer selector', 'label_key' => 'admin.settings.item.public_language_selector_enabled', 'value' => '1']],
        'skipped' => ['public_language_selector_languages'],
        'deferred' => [['id' => 'account_credentials', 'label' => 'Account credentials', 'url' => '/index.php?page=admin_account']],
    ]];
    $summaryHtml = admin_setup_wizard_render($summaryModel);
    admin_setup_wizard_render_assert(str_contains($summaryHtml, 'name="approval" value="1" required'), 'Only the final summary must require explicit approval.');
    admin_setup_wizard_render_assert(str_contains($summaryHtml, 'Old name') && str_contains($summaryHtml, 'New name') && str_contains($summaryHtml, 'reviewed, unchanged') && str_contains($summaryHtml, 'Skipped'), 'Summary must retain changed, reviewed, and skipped outcomes in its review markup.');
    admin_setup_wizard_render_assert(str_contains($summaryHtml, 'data-wizard-summary-show-all') && str_contains($summaryHtml, 'data-wizard-summary-unchanged') && !str_contains($summaryHtml, 'href="/index.php?page=admin_account"'), 'Summary must hide unchanged/status items by default and must not link out to independently committed settings.');
    admin_setup_wizard_render_assert(!str_contains($summaryHtml, 'data-wizard-summary-unchanged-group'), 'A summary group containing real changes must stay visible even when unchanged details are hidden.');
    admin_setup_wizard_render_assert(str_contains($summaryHtml, 'value="back" class="secondary" formnovalidate'), 'Summary Back must not require approval.');
    admin_setup_wizard_render_assert(substr_count($summaryHtml, 'admin-setup-wizard-actions is-top') === 1 && substr_count($summaryHtml, 'admin-setup-wizard-actions is-bottom') === 1, 'Summary must expose navigation both before and after the review content.');
    admin_setup_wizard_render_assert(substr_count($summaryHtml, 'value="apply"') === 1, 'Final Apply must remain a single bottom action after explicit approval.');

    $unchangedOnlyModel = $summaryModel;
    $unchangedOnlyModel['summary_groups'][] = [
        'id' => 'content', 'title' => 'Content', 'title_key' => 'admin.settings.section.content',
        'changes' => [],
        'reviewed' => [],
        'skipped' => ['custom_css'],
        'deferred' => [],
    ];
    $unchangedOnlyHtml = admin_setup_wizard_render($unchangedOnlyModel);
    admin_setup_wizard_render_assert(str_contains($unchangedOnlyHtml, 'class="wizard-summary-group is-unchanged-only" data-wizard-summary-unchanged-group'), 'Unchanged-only summary sections must be marked so enhancement can remove the entire empty block from the default review.');

    $document = admin_setup_wizard_render_dom($summaryHtml);
    if ($document !== null) {
        $xpath = new \DOMXPath($document);
        admin_setup_wizard_render_assert($xpath->query('//input[@name="approval"]')->length === 1, 'DOM summary must contain exactly one approval checkbox.');
    }

    // Every wizard-owned UI/error key is maintained in all four language catalogs with a real skip-help sample.
    $viewSource = (string) file_get_contents($root . '/app/views/admin_setup_wizard.php');
    preg_match_all("/'(admin\\.setup_wizard\\.[a-z0-9_.]+)'/", $viewSource, $translationMatches);
    $requiredKeys = array_values(array_unique($translationMatches[1] ?? []));
    $semanticSamples = ['en' => 'original setting', 'cs' => 'Původní nastavení', 'de' => 'ursprüngliche Einstellung', 'sv' => 'ursprungliga inställningen'];
    foreach ($semanticSamples as $language => $sample) {
        $catalog = json_decode((string) file_get_contents($root . '/app/lang/' . $language . '.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($requiredKeys as $key) admin_setup_wizard_render_assert(isset($catalog[$key]) && trim((string) $catalog[$key]) !== '', strtoupper($language) . ' catalog must contain ' . $key);
        admin_setup_wizard_render_assert(str_contains((string) $catalog['admin.setup_wizard.item_skip_help'], $sample), strtoupper($language) . ' skip guidance must preserve its localized semantic meaning.');
    }

    $themeSource = (string) file_get_contents($root . '/app/views/admin_theme.php');
    $wizardJavascript = (string) file_get_contents($root . '/public/assets/gallery-modules/admin-setup-wizard.js');
    $wizardStyles = (string) file_get_contents($root . '/public/assets/styles/admin-setup-wizard.css');
    admin_setup_wizard_render_assert(str_contains($wizardStyles, '.admin-setup-wizard-progress .is-active .wizard-progress-label') && str_contains($wizardStyles, 'display: none;'), 'Progress CSS must hide non-active labels while preserving numbered markers.');
    admin_setup_wizard_render_assert(!str_contains($wizardStyles, '.admin-setup-wizard-progress {\n    overflow-x: auto'), 'Progress bar must not reintroduce a horizontal scrollbar.');
    admin_setup_wizard_render_assert(str_contains($wizardStyles, '.admin-setup-wizard-progress-nav {') && str_contains($wizardStyles, 'overflow: hidden;'), 'Progress navigation must clip accidental horizontal overflow instead of exposing a scrollbar.');
    admin_setup_wizard_render_assert(substr_count($themeSource, 'data-theme-live-preview') === 1, 'Theme source must define preview markup only in the shared helper.');
    admin_setup_wizard_render_assert(str_contains($themeSource, 'view_render_admin_theme_live_preview([') && str_contains($viewSource, 'view_render_admin_theme_live_preview($preview)'), 'Original Theme and wizard must call the same preview renderer.');
    admin_setup_wizard_render_assert(str_contains($wizardJavascript, "./theme-form.js?v=20260929-setup-wizard-preview-v2"), 'Wizard must version the Theme preview dependency so a cached pre-export module cannot break gallery.js.');
    admin_setup_wizard_render_assert(array_values(array_unique($GLOBALS['admin_setup_wizard_render_translation_calls'] ?? [])) !== [], 'Focused rendering must exercise only the explicit translation presentation boundary.');

    echo "Admin setup wizard rendering tests passed.\n";
}
