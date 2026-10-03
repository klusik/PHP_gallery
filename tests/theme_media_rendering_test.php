<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_media_rendering_test.php
 * Module Type: Regression Test
 * Purpose: Preserve compact Media form fields, asset availability gates and shared Theme save behavior.
 * Responsibilities: Render populated and empty models without storage access or mutations.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
require __DIR__.'/support/theme_appearance_fixture.php';
/** Parse isolated actual Theme rendering with bounded markup diagnostics. @param string $html Prepared rendered document. @return DOMXPath Query helper for its disposable DOM. */
function media_xpath(string $html): DOMXPath { $document=new DOMDocument(); $previous=libxml_use_internal_errors(true); $document->loadHTML('<?xml encoding="UTF-8">'.$html); libxml_clear_errors(); libxml_use_internal_errors($previous); return new DOMXPath($document); }
/** Require an observable Media contract. @param bool $condition Required invariant. @param string $message Failure context. @return void Throws on regression. */
function media_require(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
/** Count nodes in a disposable rendering. @param DOMXPath $xpath Prepared document query helper. @param string $query Fixed XPath expression. @return int Matching node count. */
function media_count(DOMXPath $xpath,string $query): int { return $xpath->query($query)->length; }
$xpath=media_xpath(theme_fixture_page()); $scope='//*[@id="admin-theme-tab-media"]';
foreach(['appearance','media','layout','language','custom-css'] as $primary) media_require(media_count($xpath,'//*[@id="admin-theme-tab-'.$primary.'"]')===1,'unique primary panel '.$primary);
foreach(['header','favicon','background'] as $tab) media_require(media_count($xpath,$scope.'//*[@data-admin-subtab-target="admin-theme-media-subtab-'.$tab.'"]')===1,'retained Media subsection '.$tab);
media_require(media_count($xpath,'//form[@method="post" and @enctype="multipart/form-data" and @data-theme-form]')===1 && media_count($xpath,'//form//input[@name="csrf_token" and @value="theme-fixture"]')===1,'one shared multipart form and CSRF authority');
foreach(['theme_branding_banner','theme_branding_separator','theme_branding_separator_width','theme_branding_separator_height','theme_branding_separator_stretch','favicon_source','favicon_cropped_png','theme_background','theme_background_optimized_max_side','theme_background_opacity','theme_background_source'] as $name) media_require(media_count($xpath,$scope.'//*[@name="'.$name.'"]')===1,'retained exact Media POST field '.$name);
foreach(['reset_theme_branding_banner','reset_theme_branding_separator','generate_theme_background_optimized','delete_theme_background_optimized','reset_all_gallery_backgrounds','reset_theme_background','reset_favicon'] as $action) media_require(media_count($xpath,$scope.'//button[@name="'.$action.'" and @value="1" and @formnovalidate and not(@disabled)]')===1,'available action retains native validation bypass '.$action);
media_require(media_count($xpath,'//*[@id="admin-theme-media-subtab-favicon"]//*[@name="reset_favicon"]')===1 && media_count($xpath,'//*[@id="admin-theme-media-subtab-background"]//*[@name="reset_favicon"]')===0,'favicon removal belongs once to its Browser icon subsection');
foreach(['input','cropper','canvas','preview','zoom','cropped'] as $hook) media_require(media_count($xpath,$scope.'//*[@data-favicon-'.$hook.']')===1,'cropper presentation hook retained '.$hook);
media_require(media_count($xpath,'//*[@class="panel admin-theme-save-panel"]/ancestor::*[@data-admin-tab-panel]')===0,'one global floating Save remains outside subsection panels');
media_require(media_count($xpath,$scope.'//*[contains(text(),"Banner <safe>")]')>0,'untrusted prepared asset label remains escaped');
$empty=media_xpath(theme_fixture_page(['banner'=>null,'separator'=>null,'favicon_url'=>'','background'=>['has_background'=>false,'optimized_active'=>false,'optimized_max_side'=>1920,'opacity'=>65]]));
media_require(media_count($empty,$scope.'//*[@name="theme_branding_banner" or @name="theme_branding_separator"]')===0,'unavailable branding definitions omit their owned fields');
media_require(media_count($empty,$scope.'//button[@name="generate_theme_background_optimized" and @disabled]')===1 && media_count($empty,$scope.'//button[@name="delete_theme_background_optimized" and @disabled]')===1,'empty background disables generation and optimized deletion');
$supportedEmpty=media_xpath(theme_fixture_page(['banner'=>['label'=>'Banner','asset_url'=>''],'separator'=>['label'=>'Separator','asset_url'=>'','width'=>0,'height'=>48,'stretch'=>false],'favicon_url'=>'']));
media_require(media_count($supportedEmpty,$scope.'//*[@name="theme_branding_banner" or @name="theme_branding_separator"]')===2 && media_count($supportedEmpty,$scope.'//*[@name="reset_theme_branding_banner" or @name="reset_theme_branding_separator"]')===0,'supported empty branding keeps uploads while absent stored assets omit removal actions');
$unoptimized=media_xpath(theme_fixture_page(['background'=>['has_background'=>true,'optimized_active'=>false,'optimized_max_side'=>1920,'opacity'=>65]]));
media_require(media_count($unoptimized,$scope.'//button[@name="generate_theme_background_optimized" and not(@disabled)]')===1 && media_count($unoptimized,$scope.'//button[@name="delete_theme_background_optimized" and @disabled]')===1,'stored original enables generation while absent derivative stays unavailable');
echo "PASS Theme Media rendering\n";
