<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_smart_galleries_ui_test.php
 * Module Type: Regression Test
 * Purpose: Exercise compact Smart Gallery views and public navigation without storage.
 * Responsibilities: Verify list states, safe public actions and preservation of editor payloads.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Escape fixture text. @param string $text Raw text. @return string HTML text. */
    function e(string $text): string { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
    /** Return a local route. @param string $page Route ID. @param array<string,int|string> $query Query values. @return string Confined URL. */
    function url_for(string $page, array $query = []): string { return '/index.php?' . http_build_query(['page' => $page] + $query); }
    /** Open a fixture document. @param string $title Page title. @return void Emits markup. */
    function render_header(string $title): void { echo '<main>'; }
    /** Close the fixture document. @return void Emits markup. */
    function render_footer(): void { echo '</main>'; }
    /** Record authentication. @return void Counts protected reads. */
    function require_admin(): void { $GLOBALS['smart_ui_auth']++; }
    /** Return fixture transport. @return string Read-only method. */
    function request_method(): string { return 'GET'; }
    /** Return no previous notice. @param string $key Notice ID. @return string Empty text. */
    function flash_message(string $key): string { return ''; }
}
namespace Gallery\Services {
    /** Return offline definitions. @return list<array<string,mixed>> Fixture rows. */
    function smart_galleries_all(): array { return $GLOBALS['smart_ui_rows']; }
    /** Resolve fixture translations.
     * @param string $key Translation ID. @param string|array<string,mixed>|null $fallback Fallback or variables.
     * @param array<string,mixed> $parameters Message variables. @return string Presentation text.
     */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string {
        static $labels = null;
        $labels ??= array_merge(json_decode((string) file_get_contents(__DIR__ . '/../app/lang/en.json'), true, 512, JSON_THROW_ON_ERROR), require __DIR__ . '/../app/lang/en.php');
        if (is_array($fallback)) { $parameters = $fallback; $fallback = null; }
        $text = (string) ($labels[$key] ?? $fallback ?? $key);
        foreach ($parameters as $name => $value) { $text = str_replace('{' . $name . '}', (string) $value, $text); }
        return $text;
    }
}
namespace Gallery\Views {
    /** Emit confined thumbnail-bound controls; their renderer has independent coverage.
     * @param string $prefix Form namespace. @param list<int> $values Candidates. @param int $minimum Minimum index. @param int $maximum Maximum index.
     * @param string $title Label. @param string $help Help text. @return void Emits two successful controls.
     */
    function render_admin_thumbnail_bound_slider(string $prefix, array $values, int $minimum, int $maximum, string $title, string $help): void {
        echo '<input name="' . $prefix . '_min_size" value="600"><input name="' . $prefix . '_max_size" value="1600">';
    }
}
namespace {
    /** Require a UI contract. @param bool $condition Expected behavior. @param string $message Failure detail. @return void Throws on failure. */
    function smart_ui_assert(bool $condition, string $message): void { if (!$condition) throw new \RuntimeException($message); }
    /** Parse server markup into a fixture document. @param string $html Real rendered HTML. @return \DOMXPath Selector context. */
    function smart_ui_document(string $html): \DOMXPath {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        return new \DOMXPath($document);
    }
    require_once __DIR__ . '/../app/views/smart_galleries.php';
    require_once __DIR__ . '/../app/controllers/smart_galleries.php';
    $GLOBALS['smart_ui_auth'] = 0;
    $_GET = [];
    $GLOBALS['smart_ui_rows'] = [];
    ob_start(); \Gallery\Controllers\cms_admin_smart_galleries(); $empty = (string) ob_get_clean();
    smart_ui_assert(str_contains($empty, 'No Smart Galleries') && !str_contains($empty, 'data-smart-gallery-editor-workspace'), 'Empty list has a create action without a redundant empty editor panel.');
    $GLOBALS['smart_ui_rows'] = [['id' => 4, 'title' => 'A <gallery>', 'slug' => 'published', 'visibility' => 'public', 'enabled' => 1, 'placement_mode' => 'gallery']];
    ob_start(); \Gallery\Controllers\cms_admin_smart_galleries(); $single = (string) ob_get_clean();
    $singleDocument = smart_ui_document($single);
    smart_ui_assert($singleDocument->query('//article[contains(@class,"admin-smart-gallery-row")]')->length === 1 && !str_contains($single, 'data-smart-gallery-editor-workspace'), 'One definition renders one useful row without an empty workspace.');
    smart_ui_assert($singleDocument->query('//a[@data-smart-gallery-open-public and @target="_blank" and @rel="noopener"]')->length === 1 && str_contains($single, 'slug=published'), 'Published enabled definitions have an ordinary public link separate from editing.');
    smart_ui_assert(str_contains($single, 'A &lt;gallery&gt;') && str_contains($single, '>Edit</a>'), 'Row content is escaped and exposes an explicit edit action.');
    $GLOBALS['smart_ui_rows'][] = ['id' => 5, 'title' => 'Private', 'slug' => 'private', 'visibility' => 'private', 'enabled' => 1, 'placement_mode' => 'unlisted'];
    $GLOBALS['smart_ui_rows'][] = ['id' => 6, 'title' => 'Disabled', 'slug' => 'disabled', 'visibility' => 'public', 'enabled' => 0, 'placement_mode' => 'root'];
    ob_start(); \Gallery\Controllers\cms_admin_smart_galleries(); $multiple = (string) ob_get_clean();
    smart_ui_assert(smart_ui_document($multiple)->query('//a[@data-smart-gallery-open-public]')->length === 1 && !str_contains($multiple, 'page=smart_gallery&amp;slug=private') && !str_contains($multiple, 'page=smart_gallery&amp;slug=disabled'), 'Private and disabled definitions never get misleading public destinations.');
    $editor = ['gallery' => ['title' => 'A <gallery>', 'slug' => 'published', 'description' => 'Description', 'visibility' => 'public', 'sort_direction' => 'asc'], 'existing' => true, 'gallery_id' => 4, 'enabled' => true, 'form_action' => '/index.php?page=admin_smart_galleries&id=4', 'public_url' => '/index.php?page=smart_gallery&slug=published', 'csrf_html' => '<input type="hidden" name="csrf_token" value="fixture-csrf">', 'rules_json' => '{"version":1,"root":{"type":"group","operator":"AND","children":[]}}', 'presentation_controls' => ['has_override' => true, 'source_label' => 'Smart Gallery override', 'presentation' => ['grid_columns' => 4, 'grid_rows' => 30, 'pagination_enabled' => true], 'thumbnail_bounds' => ['values' => [600,1600]]], 'placements' => []];
    ob_start(); \Gallery\Views\view_render_smart_gallery_editor($editor); $html = (string) ob_get_clean();
    $document = smart_ui_document($html);
    $ruleLabels = json_decode($document->query('//form[@data-smart-gallery-editor]')->item(0)->getAttribute('data-smart-gallery-rule-labels'), true, 512, JSON_THROW_ON_ERROR);
    smart_ui_assert($ruleLabels['all'] === 'All conditions' && $ruleLabels['any'] === 'Any condition' && $ruleLabels['exclude'] === 'Exclude the following' && str_contains($html, 'Photos that match these conditions'), 'The view supplies localized readable labels and an explanation for the nested rule editor.');
    smart_ui_assert($document->query('//details[@class="admin-smart-gallery-details" and not(@open)]')->length === 1 && strpos($html, 'data-smart-rule-builder') < strpos($html, 'data-smart-gallery-presentation-toggle'), 'Rules precede the initially collapsed secondary presentation controls.');
    foreach (['title','slug','description','placement_mode','enabled','visibility','sort_mode','sort_direction','rules_json','presentation_override_enabled','presentation_grid_columns','presentation_grid_rows','presentation_pagination_enabled','presentation_thumbnail_min_size','presentation_thumbnail_max_size','presentation_thumbnail_rendering_mode','presentation_card_layout','presentation_map_enabled','presentation_lightbox_enabled','presentation_lightbox_browsing_mode','presentation_slideshow_enabled','presentation_voting_enabled','presentation_download_enabled','presentation_metadata_visible','presentation_source_gallery_visible'] as $name) {
        smart_ui_assert($document->query('//form[@data-smart-gallery-editor]//*[@name="' . $name . '" and not(@disabled)]')->length === 1, 'Compact or collapsed controls preserve submitted field: ' . $name);
    }
    smart_ui_assert($document->query('//form[@data-smart-gallery-editor]//button[@name="action" and @value="save"]')->length === 1 && $document->query('//form[@data-smart-gallery-editor]//button[@name="action" and @value="preview"]')->length === 1, 'Primary and existing preview actions remain inside their owned form.');
    smart_ui_assert($document->query('//form[@data-smart-gallery-panel-form]')->length === 3 && $document->query('//a[@data-smart-gallery-open-public]')->length === 1, 'Panel mutation ownership and the editor public action remain intact.');
    $editor['existing'] = false; $editor['gallery_id'] = 0; $editor['public_url'] = '';
    ob_start(); \Gallery\Views\view_render_smart_gallery_editor($editor); $new = (string) ob_get_clean();
    smart_ui_assert(smart_ui_document($new)->query('//a[@data-smart-gallery-open-public]')->length === 0 && !str_contains($new, 'value="delete"'), 'Unsaved definitions cannot acquire public URLs or destructive actions.');
    echo "Admin Smart Galleries UI: PASS\n";
}
