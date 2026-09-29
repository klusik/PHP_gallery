<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_setup_wizard.php
 * Module Type: Service
 *
 * Purpose:
 *   Defines the Admin Setup Wizard domain contract and ordered service parts.
 *
 * Responsibilities:
 *   - Keep shared constants and bounded domain exceptions in one entry point
 *   - Preserve the historical service loader include contract
 *   - Load catalog, draft, and apply policy in deterministic order
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Part files are private to this module and must not be loaded directly.
 *   - The controller owns session/request state; the model owns PDO transactions.
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;

/**
 * Final wizard step identifier used to require explicit review before apply.
 * @var string Stable route and draft-state identifier.
 * Type: string.
 * Units: lowercase step identifier.
 * Scope: Admin Setup Wizard service and controller flow.
 * Consumers: controller navigation and summary presentation.
 * Rationale: centralize the sentinel instead of duplicating a string literal.
 */
const ADMIN_SETUP_WIZARD_SUMMARY_STEP = 'summary';
/**
 * List canonical Theme setting identifiers editable by the wizard.
 * @var list<string> Canonical Theme setting identifiers.
 * Type: list of strings.
 * Units: setting identifiers.
 * Scope: Setup Wizard catalog and apply flow.
 * Consumers: Theme preview, normalization, and apply orchestration.
 * Rationale: keep the Theme-owned scalar adapter allowlist explicit.
 */
const ADMIN_SETUP_WIZARD_THEME_IDS = [
    'theme_accent',
    'theme_accent_dark',
    'theme_paper',
    'theme_panel',
    'theme_gallery_panel',
    'theme_header_text',
    'theme_hero_text',
    'theme_radius',
    'theme_font',
    'theme_page_width',
    'theme_page_width_custom',
];
/**
 * List wizard settings whose canonical values are structured arrays.
 * @var list<string> Canonical structured setting identifiers.
 * Type: list of strings.
 * Units: setting identifiers.
 * Scope: Setup Wizard normalization boundary.
 * Consumers: entry validation, staging, and summary rendering.
 * Rationale: permit structured input only for registered language settings.
 */
const ADMIN_SETUP_WIZARD_STRUCTURED_IDS = [
    'public_language_selector_languages',
    'public_language_selector_design',
];
/**
 * Bound the number of scalar list items rendered inline in a summary.
 * @var int Positive summary item limit.
 * Type: integer.
 * Units: list items.
 * Scope: Setup Wizard summary presentation.
 * Consumers: admin_setup_wizard_summary_display().
 * Rationale: keep list-valued setting summaries bounded.
 */
const ADMIN_SETUP_WIZARD_SUMMARY_LIST_LIMIT = 20;

/** Bounded domain failure suitable for mapping to a translated controller error. */
final class AdminSetupWizardException extends RuntimeException
{
    /**
     * Construct a domain exception carrying the stable user-facing error key.
     * @param string $errorKey Stable translation key.
     * @return void
     */
    public function __construct(private readonly string $errorKey)
    {
        parent::__construct($errorKey);
    }

    /**
     * Return the stable translation key for this failure.
     *
     * @return string Stable translation key.
     */
    public function errorKey(): string
    {
        return $this->errorKey;
    }
}

require_once __DIR__ . '/admin_setup_wizard/catalog.php';
require_once __DIR__ . '/admin_setup_wizard/draft.php';
require_once __DIR__ . '/admin_setup_wizard/apply.php';
