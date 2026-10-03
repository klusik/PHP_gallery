/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_appearance_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise actual Appearance inputs and responsive presentation in disposable Chromium.
 * Responsibilities: Delegate isolated rendering and browser lifecycle to the confined fixture runner.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[4] = process.argv[3];
process.argv[3] = 'theme_appearance.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
