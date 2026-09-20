/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * Module Type: Regression Test
 * Purpose: Run real create/classic/prepared browser workflows against a disposable replay ledger.
 * Responsibilities:
 *   - Reuse the confined Chromium fixture runner without live PHP, credentials or database access.
 * File: tests/admin_operation_keys_browser_test.mjs
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
process.argv[3] = 'admin_operation_keys.html';
await import('./admin_panel_lifecycle_browser_test.mjs');
