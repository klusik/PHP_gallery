<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_setup_wizard_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Verifies pure Setup Wizard draft, staging, skip, summary, and navigation policy.
 *
 * Responsibilities:
 *   - Check owner-bound draft creation and validation
 *   - Check normalized staging and atomic invalid-field rejection
 *   - Check read-only review, skip, navigation, and summary behavior
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Normalize a fixture registry value using the same scalar contract as the service stub.
     * @param array{id?:string} $entry Fixture registry entry.
     * @param string $value Submitted value.
     * @return string Normalized fixture value.
     */
    function admin_settings_normalize_editable_value(array $entry, mixed $value): mixed
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('bad type');
        }
        return trim($value);
    }

    /**
     * Normalize a fixture Theme value using the Theme service stub.
     * @param string $id Fixture Theme id.
     * @param string $value Submitted value.
     * @return string Normalized fixture value.
     */
    function theme_basic_appearance_normalize(string $id, mixed $value): mixed
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException('bad theme type');
        }
        return trim($value);
    }

    require_once __DIR__ . '/../app/services/admin_setup_wizard.php';

    $steps = [];
    foreach (['general', 'site', 'appearance', 'content', 'media', 'uploads', 'privacy', 'advanced'] as $section) {
        $steps[$section] = ['id' => $section, 'entries' => []];
    }
    $steps['general']['entries'] = [
        'site_name' => ['id' => 'site_name', 'wizard_editable' => true, 'central_editable' => true, 'current' => 'Gallery', 'input_type' => 'text'],
        'secret_status' => ['id' => 'secret_status', 'wizard_editable' => false, 'read_only' => true, 'current' => 'Configured'],
        'theme_accent' => ['id' => 'theme_accent', 'wizard_editable' => true, 'current' => '#112233', 'input_type' => 'color', 'wizard_adapter' => 'theme_basic_appearance'],
    ];

    $draft = admin_setup_wizard_begin_draft(7, $steps);
    if ($draft['owner'] !== 7 || $draft['revision'] !== 1 || count($draft['original']) !== 2) {
        throw new \RuntimeException('Invalid initial draft shape.');
    }
    if (!admin_setup_wizard_draft_valid($draft, 7, $steps)) {
        throw new \RuntimeException('Valid draft rejected.');
    }
    if (admin_setup_wizard_draft_valid($draft, 8, $steps)) {
        throw new \RuntimeException('Foreign draft owner accepted.');
    }

    $staged = admin_setup_wizard_stage_step($draft, $steps, 'general', ['site_name' => ' Gallery 2 '], ['site_name' => true]);
    if ($staged['errors'] !== [] || ($staged['draft']['changes']['site_name'] ?? '') !== 'Gallery 2') {
        if ($staged['errors'] !== []) { throw new \RuntimeException('Stage errors: ' . json_encode($staged['errors'])); }
        throw new \RuntimeException('Editable value was not staged.');
    }
    $skipped = admin_setup_wizard_stage_step($staged['draft'], $steps, 'general', [], [], true);
    if (!isset($skipped['draft']['skips']['site_name']) || isset($skipped['draft']['changes']['site_name'])) {
        throw new \RuntimeException('Whole-section skip did not preserve original and clear change.');
    }
    $invalid = admin_setup_wizard_stage_step($draft, $steps, 'general', ['unknown' => 'x'], ['site_name' => true]);
    if ($invalid['errors'] === [] || $invalid['draft'] !== $draft) {
        throw new \RuntimeException('Invalid field was not rejected atomically.');
    }
    $invalidInclude = admin_setup_wizard_stage_step($draft, $steps, 'general', [], ['secret_status' => true]);
    if ($invalidInclude['errors'] !== [] || isset($invalidInclude['draft']['changes']['secret_status'])) {
        throw new \RuntimeException('Read-only field was staged.');
    }

    foreach (['next', 'back', 'goto'] as $action) {
        $target = $action === 'goto' ? 'appearance' : '';
        $next = admin_setup_wizard_navigation('general', $action, $steps, $target);
        if ($next === '') {
            throw new \RuntimeException('Navigation returned an empty step.');
        }
    }
    try {
        admin_setup_wizard_navigation('general', 'invalid', $steps, '');
        throw new \RuntimeException('Invalid navigation accepted.');
    } catch (AdminSetupWizardException) {
    }

    $summaryDraft = $staged['draft'];
    $summaryDraft['skips']['secret_status'] = true;
    $summary = admin_setup_wizard_summary($summaryDraft, $steps);
    if (!is_array($summary) || $summary === []) {
        throw new \RuntimeException('Summary did not contain staged/skipped state.');
    }

    if (admin_setup_wizard_operational_group('gallery_trash_retention_days') !== 'gallery_trash') {
        throw new \RuntimeException('Gallery Trash preferences lost their coupled owner group.');
    }
    if (admin_setup_wizard_operational_group('site_maintenance_utc_time') !== 'site_maintenance') {
        throw new \RuntimeException('Scheduled Maintenance preferences lost their coupled owner group.');
    }
    if (admin_setup_wizard_operational_group('seo_request_guard_enabled') !== '') {
        throw new \RuntimeException('Independent operational preference was incorrectly grouped.');
    }
    if (admin_setup_wizard_operational_bool('1') !== '1' || admin_setup_wizard_operational_bool('') !== '0') {
        throw new \RuntimeException('Operational boolean normalization changed unexpectedly.');
    }
    try {
        admin_setup_wizard_operational_bool('yes');
        throw new \RuntimeException('Truthy operational string was accepted.');
    } catch (\InvalidArgumentException) {
    }
    echo "admin_setup_wizard_test: PASS\n";
}
