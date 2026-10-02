/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_settings_workspace_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise Settings drafts and scoped saves in disposable Chromium.
 * Responsibilities: Run the confined Settings fixture without reading installation data.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'admin_settings_workspace.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
