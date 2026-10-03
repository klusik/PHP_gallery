/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_gallery_features_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Verify local staging and explicit reviewed confirmation using production gallery controls.
 * Responsibilities: Exercise dynamically replaced rows with synthetic preview/application responses only.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[4] = process.argv[3];
process.argv[3] = 'admin_gallery_features.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
