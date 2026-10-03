<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_language_rendering_test.php
 * Module Type: Regression Test
 * Purpose: Preserve actual Language fields, selector design and translation pack authority.
 * Responsibilities: Exercise prepared rendering and compatibility variants without storage.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
require __DIR__.'/support/theme_appearance_fixture.php';
/** Parse production Language markup without emitting parser diagnostics.
 * @param string $html Prepared production rendering.
 * @return DOMXPath Disposable document query helper.
 */
function language_xpath(string $html): DOMXPath { $document=new DOMDocument(); $previous=libxml_use_internal_errors(true); $document->loadHTML('<?xml encoding="UTF-8">'.$html); libxml_clear_errors(); libxml_use_internal_errors($previous); return new DOMXPath($document); }
/** Require one observable language rendering invariant.
 * @param bool $condition Expected condition.
 * @param string $message Failure context.
 * @return void Throw on regression.
 */
function language_require(bool $condition,string $message): void { if (!$condition) throw new RuntimeException($message); }
/** Count controls in an isolated document.
 * @param DOMXPath $xpath Prepared document query helper.
 * @param string $query Fixed XPath expression.
 * @return int Matching node count.
 */
function language_count(DOMXPath $xpath,string $query): int { return $xpath->query($query)->length; }
$xpath=language_xpath(theme_fixture_page()); $scope='//*[@id="admin-theme-tab-language"]';
foreach (['settings','design','editor','diagnostics'] as $tab) language_require(language_count($xpath,$scope.'//*[@data-admin-subtab-target="admin-theme-language-subtab-'.$tab.'"]')===1,'retained or explicit Language subsection '.$tab);
foreach (['cms_language'=>'cs','public_language'=>'de','language_pack_code'=>'cs'] as $name=>$value) {
    language_require(language_count($xpath,$scope.'//select[@name="'.$name.'"]')===1,'one canonical language select '.$name);
    language_require(language_count($xpath,$scope.'//select[@name="'.$name.'"]/option[@selected and @value="'.$value.'"]')===1,'prepared language preference '.$name);
    foreach (['en','cs','de','sv'] as $code) language_require(language_count($xpath,$scope.'//select[@name="'.$name.'"]/option[@value="'.$code.'"]')===1,'maintained selectable code '.$code);
}
foreach (['en','cs','de','sv'] as $code) {
    $option=$xpath->query($scope.'//select[@name="language_pack_code"]/option[@value="'.$code.'"]')->item(0);
    $url=parse_url($option->getAttribute('data-edit-url')); parse_str($url['query'],$query);
    language_require($query===['page'=>'admin_theme','edit_language'=>$code] && $url['fragment']==='admin-theme-tab-language','pack edit URL preserves route query and primary Language destination '.$code);
}
language_require(language_count($xpath,$scope.'//*[@name="default_language"]')===0,'source language stays read-only');
language_require(language_count($xpath,$scope.'//*[@data-public-language-selector-settings]')===1 && language_count($xpath,$scope.'//*[@data-language-design-editor]')===1,'split Theme selector has one choices owner and one designer');
language_require(language_count($xpath,$scope.'//input[@name="public_language_selector_settings_present" and @value="1"]')===1,'split controls retain one canonical settings presence marker');
language_require(language_count($xpath,$scope.'//*[@data-language-design-editor and @data-language-settings-id="admin-theme-public-language-selector"]')===1,'designer explicitly references its choices owner');
foreach (['classic','solid_pills','outline','soft_cards','minimal'] as $preset) {
    language_require(language_count($xpath,$scope.'//*[@data-language-design-preset="'.$preset.'"]')===1,'canonical design preset '.$preset);
    foreach (theme_fixture_language_defaults()['bounds'] as $field=>$bounds) language_require(language_count($xpath,$scope.'//input[@name="public_language_selector_design[presets]['.$preset.']['.$field.']" and @min="'.$bounds[0].'" and @max="'.$bounds[1].'"]')===1,'canonical numeric bounds '.$preset.'/'.$field);
    language_require(language_count($xpath,$scope.'//input[@name="public_language_selector_design[presets]['.$preset.'][container_bg_transparent]" and @type="hidden" and @value="0"]')===1,'unchecked transparency remains explicit '.$preset);
}
foreach (['save_language_pack','import_language_pack','clear_translation_diagnostics'] as $action) language_require(language_count($xpath,$scope.'//button[@name="'.$action.'" and @value="1" and @formnovalidate]')===1,'existing explicit action and validation bypass '.$action);
language_require(language_count($xpath,$scope.'//button[@name="import_language_pack" and contains(@onclick,"confirm(")]')===1,'existing import confirmation preserved');
language_require(language_count($xpath,$scope.'//input[@name="language_pack_file" and @type="file" and @accept="application/json,.json"]')===1 && language_count($xpath,$scope.'//textarea[@name="language_pack_json" and @spellcheck="false"]')===1,'JSON editor and upload contracts preserved');
language_require(language_count($xpath,$scope.'//*[contains(text(),"gallery.missing<safe>")]')>0 && language_count($xpath,$scope.'//*[contains(text(),"gallery.diagnostic<safe>")]')>0,'coverage and diagnostic keys remain escaped and visible in markup');
language_require(language_count($xpath,'//form[@method="post" and @enctype="multipart/form-data" and @data-theme-form]')===1 && language_count($xpath,'//input[@name="csrf_token" and @value="theme-fixture"]')===1,'Language preserves complete shared multipart form authority');
$model=theme_fixture_language_model()['selector_state']; $model['detailed_design']=false; $model['compact']=true;
ob_start(); \Gallery\Views\view_render_public_language_selector_settings_panel($model); $basic=language_xpath((string)ob_get_clean());
language_require(language_count($basic,'//*[@name="public_language_selector_design[basic_only]" and @value="1"]')===1 && language_count($basic,'//*[@data-language-design-preset]')===0,'Settings basic-only compatibility retains marker and omits detailed design');
$model['detailed_design']=true; $model['compact']=false;
ob_start(); \Gallery\Views\view_render_public_language_selector_settings_panel($model); $all=language_xpath((string)ob_get_clean());
language_require(language_count($all,'//*[@data-public-language-selector-settings]//*[@data-language-design-editor]')===1 && language_count($all,'//*[@data-language-design-preset]')===5,'default shared renderer preserves enclosing owner and full detailed design');
ob_start(); \Gallery\Views\view_render_admin_theme_language_tab(theme_fixture_language_model(['missing_translations'=>[]])); $empty=language_xpath((string)ob_get_clean());
language_require(language_count($empty,'//*[@name="clear_translation_diagnostics"]')===0 && language_count($empty,'//*[@name="save_language_pack"]')===1,'empty diagnostics omit only the unavailable clear action');
ob_start(); \Gallery\Views\view_render_admin_theme_language_tab(theme_fixture_language_model(['active_subtab'=>'admin-theme-language-subtab-editor'])); $editing=language_xpath((string)ob_get_clean());
language_require(language_count($editing,'//*[@data-admin-subtab-target="admin-theme-language-subtab-editor" and @aria-selected="true"]')===1 && language_count($editing,'//*[@id="admin-theme-language-subtab-editor" and contains(@class,"is-active")]')===1,'explicit controller edit selection opens exactly the retained editor');
language_require(language_count($editing,'//*[@data-admin-subtab-target and @aria-selected="true"]')===1 && language_count($editing,'//*[@data-admin-subtab-panel and contains(@class,"is-active")]')===1,'prepared Language selection exposes exactly one native tab and panel');
echo "PASS Theme Language rendering\n";
