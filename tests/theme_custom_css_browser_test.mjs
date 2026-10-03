/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/theme_custom_css_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Preserve explicit Custom CSS selection and complete shared form intent.
 * Responsibilities: Delegate production rendering and isolated browser behavior without live POSTs.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[4]=process.argv[3];
process.argv[3]='theme_custom_css.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
