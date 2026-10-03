<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_creation_rendering_test.php
 * Module Type: Regression Test
 * Purpose: Preserve full-page creation fields and the name-only side-panel boundary.
 * Responsibilities: Exercise actual prepared rendering and optional states without application bootstrap.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
require __DIR__.'/support/gallery_creation_render_fixture.php';
/** Parse production creation markup without parser diagnostics.
 * @param string $html Rendered production fragment.
 * @return DOMXPath Disposable document query helper.
 */
function creation_xpath(string $html): DOMXPath { $document=new DOMDocument(); $previous=libxml_use_internal_errors(true); $document->loadHTML('<?xml encoding="UTF-8">'.$html); libxml_clear_errors(); libxml_use_internal_errors($previous); return new DOMXPath($document); }
/** Require a meaningful creation rendering contract.
 * @param bool $condition Expected invariant.
 * @param string $message Failure context.
 * @return void Throws on regression.
 */
function creation_require(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
/** Count controls in an isolated rendered document.
 * @param DOMXPath $xpath Prepared document query helper.
 * @param string $query Fixed XPath expression.
 * @return int Matching node count.
 */
function creation_count(DOMXPath $xpath,string $query): int { return $xpath->query($query)->length; }
$page=creation_xpath(creation_fixture_page());
foreach(['csrf_token','operation_key','title','description','tags','content_language','remember_content_language','simbrief_identifier','remember_simbrief_identifier','simbrief_draft_ref','folder_name','visibility','gallery_date','gallery_date_end','voting_enabled','show_filenames','count_badge_visibility'] as $name) creation_require(creation_count($page,'//form//*[@name="'.$name.'"]')===1,'full page retains exactly one named field '.$name);
creation_require(creation_count($page,'//form[@method="post" and @action="/fixture-full-create" and contains(@class,"gallery-create-form")]')===1,'full page retains native POST authority');
creation_require(creation_count($page,'//select[@name="visibility"]/option')===3 && creation_count($page,'//select[@name="visibility"]/option[@value="public" and text()="Public"]')===1 && creation_count($page,'//select[@name="visibility"]/option[@value="published"]')===0,'fixture exposes canonical public visibility alongside unpublished and private');
creation_require(creation_count($page,'//header//a[@href="/fixture-home" and contains(text(),"Open galleries")]')===1 && creation_count($page,'//a[contains(@href,"fixture-upload")]')===0,'page navigation points to the canonical gallery surface');
creation_require(creation_count($page,'//input[@name="parent_id" and @data-gallery-search-picker-value and @disabled and @value="7"]')===1 && creation_count($page,'//noscript//input[@name="parent_id" and @type="number" and @min="0"]')===1,'real parent picker preserves shared JavaScript and no-JavaScript scope contracts');
creation_require(creation_count($page,'//select[@name="content_language"]/option[@value="cs" and @selected]')===1 && creation_count($page,'//input[@name="simbrief_identifier" and @value="12345"]')===1,'saved language and SimBrief preferences populate only the full page');
creation_require(creation_count($page,'//details[contains(@class,"admin-gallery-advanced-settings") and not(@open)]')===1 && creation_count($page,'//*[contains(@class,"gallery-create-settings")]//*[@name="visibility"]')===1,'frequent settings remain visible while optional settings start collapsed');
creation_require(creation_count($page,'//details[contains(@class,"gallery-create-simbrief")]//*[@data-simbrief-generate]')===1 && creation_count($page,'//*[@data-gallery-title-completion-url="/fixture-title-completion"]')===1,'draft and completion metadata retain original native controls');
$retry=creation_xpath(creation_fixture_page(creation_fixture_retry(),'Validation <safe> failed'));
foreach(['title'=>'Retry <safe>','tags'=>'flight, landscape','folder_name'=>'retry-folder','simbrief_identifier'=>'Retry pilot','simbrief_draft_ref'=>'fixture-draft','gallery_date'=>'2026-10-01','gallery_date_end'=>'2026-10-03'] as $name=>$value) creation_require(creation_count($retry,'//input[@name="'.$name.'" and @value="'.$value.'"]')===1,'submitted retry value preserved '.$name);
creation_require(creation_count($retry,'//textarea[@name="description" and contains(text(),"Keep <text> & description")]')===1 && creation_count($retry,'//*[contains(text(),"Validation <safe> failed")]')>0,'description and validation text stay escaped');
creation_require(creation_count($retry,'//details[contains(@class,"admin-gallery-advanced-settings") and @open]')===1 && creation_count($retry,'//select[@name="visibility"]/option[@value="private" and @selected]')===1,'retry reveals and preserves advanced selections');
creation_require(creation_count($retry,'//input[@name="remember_simbrief_identifier" and @checked]')===1,'controller-normalized remembered pilot name survives a validation retry');
foreach([0,7] as $parent) { $panel=creation_xpath(creation_fixture_panel($parent)); $names=[]; foreach($panel->query('//form//*[@name]') as $control) $names[]=$control->getAttribute('name'); sort($names); creation_require($names===['csrf_token','operation_key','panel','parent_id','title'],'panel keeps exact name-only request boundary'); creation_require(creation_count($panel,'//form[@data-gallery-panel-create-form]')===1 && creation_count($panel,'//input[@name="parent_id" and @value="'.$parent.'"]')===1 && creation_count($panel,'//*[@data-gallery-panel-submit]')===1,'panel retains explicit scope and dynamic workflow hooks'); }
$off=creation_xpath(creation_fixture_page(['simbrief_enabled'=>false,'creation_preferences_available'=>false,'localization'=>['enabled'=>false,'schema_ready'=>false],'date'=>['schema_ready'=>false],'count_badge'=>['schema_ready'=>false]]));
foreach(['simbrief_identifier','simbrief_draft_ref','remember_content_language','content_language','gallery_date','gallery_date_end','count_badge_visibility'] as $name) creation_require(creation_count($off,'//*[@name="'.$name.'"]')===0,'unavailable optional input omitted '.$name);
creation_require(creation_count($off,'//*[@name="title"]')===1 && creation_count($off,'//*[@name="tags"]')===1 && creation_count($off,'//*[@name="visibility"]')===1,'optional OFF preserves core creation controls');
$single=creation_xpath(creation_fixture_page(['date'=>['schema_ready'=>true,'range_schema_ready'=>false,'start_value'=>'2026-10-01']]));
creation_require(creation_count($single,'//input[@name="gallery_date" and @value="2026-10-01"]')===1 && creation_count($single,'//*[@name="gallery_date_end"]')===0,'legacy single-date schema retains only its supported native field');
echo "PASS Gallery creation rendering\n";
