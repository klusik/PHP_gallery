/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_language_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise actual Language choices, local designer and preserved pack actions.
 * Responsibilities: Delegate disposable production rendering and browser lifecycle without live writes.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[4]=process.argv[3];
process.argv[3]='theme_language.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
