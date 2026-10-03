/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_media_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise compact Media controls and local favicon cropping in disposable Chromium.
 * Responsibilities: Delegate actual view rendering and confined browser lifetime to the fixture runner.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[4]=process.argv[3];
process.argv[3]='theme_media.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
