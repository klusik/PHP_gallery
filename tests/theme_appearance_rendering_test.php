<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_appearance_rendering_test.php
 * Module Type: Regression Test
 * Purpose: Preserve Appearance form authority, deep links and shared preview placement.
 * Responsibilities: Render production Theme controls from disposable models without storage writes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
require __DIR__.'/support/theme_appearance_fixture.php';

/** Parse the real renderer's fragment with bounded diagnostics.
 * @param string $html Production fixture markup.
 * @return DOMXPath Query helper for its isolated DOM.
 */
function appearance_xpath(string $html): DOMXPath { $document=new DOMDocument(); $previous=libxml_use_internal_errors(true); $document->loadHTML('<?xml encoding="UTF-8">'.$html); libxml_clear_errors(); libxml_use_internal_errors($previous); return new DOMXPath($document); }
/** Require an observable form or presentation contract.
 * @param bool $condition Required predicate.
 * @param string $message Meaningful failure context.
 * @return void Throws on a regression.
 */
function appearance_assert(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
/** Count DOM nodes matching a presentation invariant.
 * @param DOMXPath $xpath Isolated rendered document.
 * @param string $query Fixed XPath expression.
 * @return int Number of matching nodes.
 */
function appearance_count(DOMXPath $xpath,string $query): int { return $xpath->query($query)->length; }

$html=theme_fixture_page(); $xpath=appearance_xpath($html);
foreach (['appearance','media','layout','language','custom-css'] as $primary) appearance_assert(appearance_count($xpath,'//*[@id="admin-theme-tab-'.$primary.'"]')===1,'actual renderer retains one unique primary panel: '.$primary);
appearance_assert(appearance_count($xpath,'//form')===1 && appearance_count($xpath,'//form[@method="post" and @enctype="multipart/form-data" and @data-theme-form]')===1,'all Theme tabs retain one shared multipart POST form');
appearance_assert(appearance_count($xpath,'//form//input[@name="csrf_token" and @value="theme-fixture"]')===1,'shared form retains authority');
appearance_assert(appearance_count($xpath,'//*[@data-theme-live-preview]')===1 && appearance_count($xpath,'//*[@data-theme-live-preview]/ancestor::*[@data-admin-subtab-panel]')===0,'one live preview remains outside all Appearance subsection panels');
appearance_assert(appearance_count($xpath,'//*[@id="admin-theme-tab-appearance"]//select[@name="theme_gallery_description_layout" and @data-theme-preview-description-layout]/option[@value="horizontal" and @selected]')===1 && appearance_count($xpath,'//*[@id="admin-theme-tab-layout"]//*[@name="theme_gallery_description_layout"]')===0,'canonical global horizontal control moves once to Appearance without leaving a competing Layout field');
appearance_assert(appearance_count($xpath,'//form//*[@name="theme_gallery_description_layout"]')===1 && appearance_count($xpath,'//form//*[@name="tag_page_gallery_description_layout"]')===1,'global and tag card layouts retain exactly one successful control each');
appearance_assert(appearance_count($xpath,'//*[@id="admin-theme-tab-appearance"]//*[@name="tag_page_gallery_description_layout"]')===1 && appearance_count($xpath,'//*[@data-theme-preview-context-select and not(@name)]/option')===2,'Appearance keeps the separate tag setting and a read-only two-scope preview selector');
appearance_assert(appearance_count($xpath,'//*[@data-theme-preview-description-card and @data-description-layout="horizontal"]')>0,'initial server-rendered Colors preview uses global horizontal layout');
appearance_assert(appearance_count($xpath,'//*[@data-theme-appearance-resizer and @role="separator" and @tabindex="0" and @aria-orientation="vertical" and @aria-label and @aria-controls]')===1 && appearance_count($xpath,'//*[@data-theme-appearance-resizer]/ancestor::*[@data-admin-subtab-panel]')===0,'one named keyboard-accessible separator controls the two shared columns outside subsection panels');
$base='//*[@id="admin-theme-tab-appearance"]';
appearance_assert(appearance_count($xpath,$base.'//*[@data-admin-subtab-target]')===4,'Appearance keeps its four compact subsections');
foreach (['colors','width-map','gallery-tags','animations'] as $tab) appearance_assert(appearance_count($xpath,$base.'//*[@data-admin-subtab-target="admin-theme-appearance-subtab-'.$tab.'"]')===1,'retained subsection destination: '.$tab);
$fields=['site_name','theme_accent','theme_accent_dark','theme_paper','theme_panel','theme_gallery_panel','theme_header_text','theme_hero_text','theme_radius','theme_font','theme_page_width','theme_page_width_custom_slider','theme_page_width_custom','tag_page_gallery_grid_columns','tag_page_gallery_grid_rows','tag_page_gallery_description_layout','theme_hero_tag_sort_mode','theme_hero_tag_display_all','theme_hero_tag_visible_limit_slider','theme_hero_tag_visible_limit','theme_hero_tag_scrollbar_enabled','theme_hero_tag_scrollbar_rows_slider','theme_hero_tag_scrollbar_rows','theme_gps_pin_enabled','theme_gps_pin_background_enabled','theme_gps_pin_size','theme_gps_pin_background_size'];
foreach ($fields as $name) appearance_assert(appearance_count($xpath,$base.'//*[@name="'.$name.'"]')===1,'Appearance retains exact POST field: '.$name);
foreach (['theme_gallery_info_motion_ms' => '320','theme_admin_side_panel_motion_ms' => '260'] as $name => $default) appearance_assert(appearance_count($xpath,$base.'//input[@type="number" and @name="'.$name.'" and @min="0" and @max="800" and @value="'.$default.'"]')===1,'Appearance retains the bounded animation duration control and default: '.$name);
appearance_assert(appearance_count($xpath,$base.'//*[@data-theme-color-row]')===7 && appearance_count($xpath,$base.'//input[@data-theme-color-hex and not(@name) and @required]')===7,'compact colors preserve seven native inputs and unnamed validity-checked HEX controls');
appearance_assert(appearance_count($xpath,$base.'//input[@data-theme-color-hex and @hidden]')===7,'without JavaScript only the native persisted color picker remains editable');
appearance_assert(appearance_count($xpath,$base.'//*[@data-theme-custom-width-control and @hidden]')===1,'noncustom width conceals tuning controls without removing their fields');
appearance_assert(appearance_count($xpath,$base.'//*[@data-theme-hero-tag-limit-controls and not(@hidden)]')===1 && appearance_count($xpath,$base.'//*[@data-theme-hero-tag-scrollbar-controls and not(@hidden)]')===1,'hero baseline exposes stored numeric controls');
appearance_assert(appearance_count($xpath,'//input[@name="site_name" and @value="Theme <safe> & identity"]')===1,'site name is escaped while preserving its exact value');
foreach (['reset_theme_overrides','reset_custom_css','reset_gps_pin_size','save_language_pack','import_language_pack'] as $action) appearance_assert(appearance_count($xpath,'//button[@name="'.$action.'" and @formnovalidate]')>=1,'neighboring save/reset action keeps validation bypass: '.$action);
foreach (['custom_css','custom_css_preset','language_pack_json','cms_language','public_language','pagination_columns'] as $name) appearance_assert(appearance_count($xpath,'//form//*[@name="'.$name.'"]')>=1,'untouched tabs remain in the same successful-control form: '.$name);
$off=appearance_xpath(theme_fixture_appearance(['gps_maps_feature_enabled'=>false,'page_width_mode'=>'custom','hero_tag_display_all'=>true,'hero_tag_scrollbar_enabled'=>false]));
appearance_assert(appearance_count($off,'//*[@name="theme_gps_pin_enabled" or @name="theme_gps_pin_size" or @name="reset_gps_pin_size"]')===0,'GPS OFF hides owned mutable fields');
appearance_assert(appearance_count($off,'//*[@data-theme-custom-width-control and not(@hidden)]')===1 && appearance_count($off,'//*[@data-theme-hero-tag-limit-controls and @hidden]')===1 && appearance_count($off,'//*[@data-theme-hero-tag-scrollbar-controls and @hidden]')===1,'conditional sections reflect prepared custom-width and hero preferences');
$savedGet=$_GET;
foreach (['colors'=>'colors','width-map'=>'width-map','gallery-tags'=>'gallery-tags','animations'=>'animations','preview'=>'colors','invalid'=>'colors'] as $requested=>$expected) {
    $_GET=['appearance_subtab'=>'admin-theme-appearance-subtab-'.$requested]; ob_start(); \Gallery\Controllers\render_admin_theme_appearance_tab(theme_fixture_model()['theme'],'',true,['columns'=>3,'rows'=>4,'items_per_page'=>12],'vertical'); $deep=appearance_xpath((string)ob_get_clean());
    appearance_assert(appearance_count($deep,'//*[@data-admin-subtab-target="admin-theme-appearance-subtab-'.$expected.'" and @aria-selected="true"]')===1,'controller retains deep link or compatibility mapping: '.$requested);
    $expectedLayout=$expected==='gallery-tags'?'vertical':'horizontal';
    appearance_assert(appearance_count($deep,'//*[@data-theme-preview-description-card and @data-description-layout="'.$expectedLayout.'"]')>0,'initial controller preview uses the authoritative layout for '.$requested);
}
$_GET=$savedGet;
echo "PASS Theme Appearance rendering\n";
