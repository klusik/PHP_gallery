<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_layout_rendering_test.php
 * Module Type: Regression Test
 * Purpose: Preserve compact Layout controls, real shortcut picker markup and form authority.
 * Responsibilities: Render prepared models with no installation state or settings writes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
require __DIR__.'/support/theme_appearance_fixture.php';
/** Parse actual Layout markup with bounded diagnostic handling. @param string $html Prepared rendered document. @return DOMXPath Disposable DOM query helper. */
function layout_xpath(string $html): DOMXPath { $document=new DOMDocument(); $previous=libxml_use_internal_errors(true); $document->loadHTML('<?xml encoding="UTF-8">'.$html); libxml_clear_errors(); libxml_use_internal_errors($previous); return new DOMXPath($document); }
/** Require an observable Layout contract. @param bool $condition Expected invariant. @param string $message Failure context. @return void Throws on regression. */
function layout_require(bool $condition,string $message): void { if(!$condition) throw new RuntimeException($message); }
/** Count controls in isolated markup. @param DOMXPath $xpath Disposable rendered query helper. @param string $query Fixed XPath expression. @return int Matching node count. */
function layout_count(DOMXPath $xpath,string $query): int { return $xpath->query($query)->length; }
$xpath=layout_xpath(theme_fixture_page()); $scope='//*[@id="admin-theme-tab-layout"]';
foreach(['shortcuts','cards','grids'] as $tab) layout_require(layout_count($xpath,$scope.'//*[@data-admin-subtab-target="admin-theme-layout-subtab-'.$tab.'"]')===1,'retained Layout subsection '.$tab);
foreach(['pagination_enabled','pagination_columns','pagination_rows','home_gallery_grid_columns','home_gallery_grid_rows','theme_gallery_count_badge_enabled','public_thumbnail_rendering_mode','theme_lightbox_browsing_mode'] as $name) layout_require(layout_count($xpath,$scope.'//*[@name="'.$name.'"]')===1,'exact Layout POST field '.$name);
layout_require(layout_count($xpath,$scope.'//select[@name="theme_favorite_gallery_types[]"]')===3 && layout_count($xpath,$scope.'//*[@data-gallery-search-picker]')===3,'three shortcut slots retain actual searchable gallery picker markup');
layout_require(layout_count($xpath,$scope.'//input[@data-gallery-search-picker-value and @name="theme_favorite_gallery_ids[]" and @disabled]')===3,'no-JS picker uses existing disabled hidden-field fallback contract');
layout_require(layout_count($xpath,$scope.'//noscript//input[@name="theme_favorite_gallery_ids[]" and @type="number" and @min="1"]')===3 && layout_count($xpath,$scope.'//input[@data-gallery-search-picker-value and @value=""]')===2,'no-JS numeric fallback preserves empty IDs for Home and empty targets');
layout_require(layout_count($xpath,$scope.'//select[@name="public_thumbnail_rendering_mode"]/option[@value="progressive" and @selected]')===1 && layout_count($xpath,$scope.'//select[@name="public_thumbnail_rendering_mode"]/option[@value="responsive"]')===1,'both permanent public thumbnail renderers retain canonical values');
foreach(['single','picture_strip','3d_carousel'] as $mode) layout_require(layout_count($xpath,$scope.'//select[@name="theme_lightbox_browsing_mode"]/option[@value="'.$mode.'"]')===1,'lightbox selector preserves canonical browsing mode '.$mode);
layout_require(layout_count($xpath,$scope.'//button[@name="reset_all_gallery_grid_overrides" and @value="1" and @formnovalidate and contains(@onclick,"confirm(")]')===1,'existing grid reset preserves confirmation and validation bypass');
layout_require(layout_count($xpath,'//form[@method="post" and @enctype="multipart/form-data" and @data-theme-form]')===1 && layout_count($xpath,'//form//input[@name="csrf_token" and @value="theme-fixture"]')===1,'Layout stays within shared multipart form and CSRF authority');
layout_require(layout_count($xpath,'//*[@id="admin-theme-tab-appearance"]//*[@name="theme_gallery_description_layout"]')===1 && layout_count($xpath,$scope.'//*[@name="theme_gallery_description_layout"]')===0,'card orientation remains owned by Appearance');
layout_require(layout_count($xpath,$scope.'//*[@data-gallery-search-picker-option-title and contains(text(),"<safe>")]')>0 || layout_count($xpath,$scope.'//*[contains(@class,"gallery-search-picker-option-title") and contains(text(),"<safe>")]')>0,'prepared gallery titles stay escaped');
ob_start(); \Gallery\Views\view_render_admin_theme_layout_tab(theme_fixture_layout_model(['lightbox_modes_enabled'=>false])); $off=layout_xpath((string)ob_get_clean());
layout_require(layout_count($off,'//*[@name="theme_lightbox_browsing_mode"]')===0 && layout_count($off,'//*[@name="pagination_columns"]')===1,'lightbox OFF omits only owned browsing selector');
echo "PASS Theme Layout rendering\n";
