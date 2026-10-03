/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/public_home_creation_browser_test.mjs
 * Module Type: Regression Test
 * Purpose: Exercise root creation using the production drawer and completion coordinator.
 * Responsibilities: Run a confined synthetic home fixture in disposable Chromium.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'public_home_creation.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
