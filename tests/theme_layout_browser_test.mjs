/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_layout_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise actual compact Layout controls and preserved shortcut/reset semantics.
 * Responsibilities: Delegate isolated production rendering and browser lifecycle without live POSTs.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[4]=process.argv[3];
process.argv[3]='theme_layout.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
