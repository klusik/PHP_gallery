<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_custom_css_rendering_test.php
 * Module Type: Regression Test
 * Purpose: Preserve explicit Custom CSS replacement and reset form contracts.
 * Responsibilities: Exercise actual prepared rendering without reading or writing user stylesheets.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
require __DIR__.'/support/theme_appearance_fixture.php';
/** Parse actual Custom CSS presentation quietly.
 * @param string $html Rendered production fragment.
 * @return DOMXPath Disposable document query helper.
 */
function custom_css_xpath(string $html): DOMXPath { $document=new DOMDocument(); $previous=libxml_use_internal_errors(true); $document->loadHTML('<?xml encoding="UTF-8">'.$html); libxml_clear_errors(); libxml_use_internal_errors($previous); return new DOMXPath($document); }
/** Require an observable Custom CSS presentation contract.
 * @param bool $condition Expected invariant.
 * @param string $message Failure context.
 * @return void Throw on regression.
 */
function custom_css_require(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
/** Count controls within a prepared document.
 * @param DOMXPath $xpath Disposable document query helper.
 * @param string $query Fixed XPath selector.
 * @return int Number of matching nodes.
 */
function custom_css_count(DOMXPath $xpath,string $query): int { return $xpath->query($query)->length; }
$xpath=custom_css_xpath(theme_fixture_page()); $scope='//*[@id="admin-theme-tab-custom-css"]';
foreach(['source','reset'] as $tab) custom_css_require(custom_css_count($xpath,$scope.'//*[@data-admin-subtab-target="admin-theme-css-subtab-'.$tab.'"]')===1,'retained Custom CSS subsection '.$tab);
custom_css_require(custom_css_count($xpath,$scope.'//select[@name="custom_css_preset"]/option[@selected and @value=""]')===1 && custom_css_count($xpath,$scope.'//select[@name="custom_css_preset"]/option[@selected and @value!=""]')===0,'Keep current stays selected even with current preset metadata');
custom_css_require(custom_css_count($xpath,$scope.'//select[@name="custom_css_preset"]/option[@value="night.css" and contains(text(),"<safe>")]')===1,'catalogued preset key and escaped label remain intact');
custom_css_require(custom_css_count($xpath,$scope.'//input[@type="file" and @name="custom_css" and @accept=".css,text/css"]')===1,'stylesheet upload retains its native canonical name and accept filter');
foreach(['reset_theme_overrides','reset_custom_css'] as $action) custom_css_require(custom_css_count($xpath,$scope.'//button[@name="'.$action.'" and @value="1" and @formnovalidate]')===1,'existing independent reset '.$action);
custom_css_require(custom_css_count($xpath,$scope.'//*[@id="admin-custom-css"]')===1 && custom_css_count($xpath,$scope.'//*[contains(text(),"Uploaded <safe> stylesheet")]')>0,'current stylesheet metadata stays escaped and discoverable');
custom_css_require(custom_css_count($xpath,'//form[@data-theme-form and @method="post" and @enctype="multipart/form-data"]')===1 && custom_css_count($xpath,'//form[@data-theme-form]//input[@name="csrf_token" and @value="theme-fixture"]')===1,'installed CSS stays inside complete shared form authority');
custom_css_require(custom_css_count($xpath,'//form[@data-css-override-form]//input[@name="csrf_token" and @value="theme-fixture"]')===1 && custom_css_count($xpath,'//textarea[@name="css_override_text" and @form="admin-theme-css-overrides-form"]')===1,'manual editor has independent form ownership and CSRF');
ob_start(); \Gallery\Views\view_render_admin_theme_css_editor(['state'=>['text'=>'/* </textarea><script>alert(1)</script> */','revision'=>str_repeat('a',64),'url'=>''],'ready'=>true],[]); $escaped=custom_css_xpath((string)ob_get_clean());
custom_css_require(custom_css_count($escaped,'//script')===0 && str_contains($escaped->query('//textarea')->item(0)->textContent,'</textarea><script>'),'HTML-looking CSS remains escaped textarea data');
foreach ([['active'=>false,'status_label'=>'No custom stylesheet'],['active'=>false,'status_label'=>'Missing selected preset','preset_label'=>'Missing <safe>'],['active'=>true,'status_label'=>'Preset active','preset_label'=>'Night']] as $state) {
    ob_start(); \Gallery\Views\view_render_admin_theme_custom_css_tab(theme_fixture_custom_css_model(['current_css'=>$state])); $variant=custom_css_xpath((string)ob_get_clean());
    custom_css_require(custom_css_count($variant,'//*[contains(text(),"'.$state['status_label'].'")]')>0 && custom_css_count($variant,'//select[@name="custom_css_preset"]/option[@selected and @value=""]')===1,'current state never auto-applies a replacement '.$state['status_label']);
}
echo "PASS Theme Custom CSS rendering\n";
