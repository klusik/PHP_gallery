<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_setup_wizard.php
 * Module Type: Controller
 *
 * Purpose:
 *   Owns the authenticated, CSRF-protected HTTP and session flow for Setup Wizard.
 *
 * Responsibilities:
 *   - Bind one staged draft to the signed-in administrator and revision
 *   - Parse only whitelisted navigation, staging, restart, cancel, and apply actions
 *   - Require explicit summary approval before invoking persistence
 *   - Build a presentation-ready model without leaking exception or secret values
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
 *   - Every POST, including cancel and restart, requires CSRF validation.
 *   - Only the final summary apply action can invoke persistent writes.
 */

declare(strict_types=1);

namespace Gallery\Controllers;

use Throwable;
use Gallery\Services\AdminSetupWizardException;
use function Gallery\Core\cms_config;
use function Gallery\Core\current_user;
use function Gallery\Core\flash_message;
use function Gallery\Core\redirect_to;
use function Gallery\Core\request_method;
use function Gallery\Core\require_admin;
use function Gallery\Core\url_for;
use function Gallery\Core\verify_csrf;
use function Gallery\Services\admin_log_event;
use function Gallery\Services\admin_settings_url;
use function Gallery\Services\admin_setup_wizard_apply;
use function Gallery\Services\admin_setup_wizard_begin_draft;
use function Gallery\Services\admin_setup_wizard_draft_valid;
use function Gallery\Services\admin_setup_wizard_navigation;
use function Gallery\Services\admin_setup_wizard_stage_step;
use function Gallery\Services\admin_setup_wizard_steps;
use function Gallery\Services\admin_setup_wizard_summary;
use function Gallery\Services\admin_setup_wizard_theme_preview;
use function Gallery\Services\translation_public_language_selector_view_data;
use function Gallery\Services\theme_background_asset_url;
use function Gallery\Services\t;
use function Gallery\Views\view_render_admin_setup_wizard_page;

/**
 * Session slot containing the administrator's staged wizard draft.
 * @var string Stable PHP session key.
 * Type: string.
 * Units: session key.
 * Scope: current administrator session.
 * Consumers: cms_admin_setup_wizard() and draft validation flow.
 * Rationale: isolate and preserve the wizard draft across requests.
 */
const ADMIN_SETUP_WIZARD_SESSION_KEY = 'admin_setup_wizard_draft';

/**
 * Render and process the staged Admin Setup Wizard.
 *
 * @return void
 */
function cms_admin_setup_wizard(): void
{
    require_admin();
    $ownerId = (int) (current_user()['id'] ?? 0);
    if ($ownerId <= 0) {
        http_response_code(403);
        return;
    }

    $steps = admin_setup_wizard_steps();
    $stored = $_SESSION[ADMIN_SETUP_WIZARD_SESSION_KEY] ?? null;
    $draft = is_array($stored) && admin_setup_wizard_draft_valid($stored, $ownerId, $steps)
        ? $stored
        : admin_setup_wizard_begin_draft($ownerId, $steps);
    $errors = [];
    if ($stored !== null && $draft !== $stored) {
        $errors['_page'] = ['admin.setup_wizard.error.invalid_draft'];
    }

    if (request_method() === 'POST') {
        verify_csrf();
        [$draft, $errors] = admin_setup_wizard_process_post($draft, $steps, $errors);
    } elseif (isset($_GET['step'])) {
        $requestedStep = is_scalar($_GET['step']) ? (string) $_GET['step'] : '';
        if (isset($steps[$requestedStep]) && $requestedStep !== (string) $draft['step']) {
            $draft['step'] = $requestedStep;
            $draft['revision'] = (int) $draft['revision'] + 1;
        } elseif (!isset($steps[$requestedStep])) {
            $errors['_page'] = ['admin.setup_wizard.error.invalid_step'];
        }
    }

    $_SESSION[ADMIN_SETUP_WIZARD_SESSION_KEY] = $draft;
    $presentedSteps = admin_setup_wizard_present_steps($steps, $draft);
    $activeStep = (string) $draft['step'];
    $config = cms_config();
    view_render_admin_setup_wizard_page([
        'steps' => $presentedSteps,
        'active_step' => $activeStep,
        'active_step_index' => admin_setup_wizard_step_index($activeStep, $steps),
        'draft' => $draft,
        'errors' => $errors,
        'revision' => (int) $draft['revision'],
        'summary' => $activeStep === \Gallery\Services\ADMIN_SETUP_WIZARD_SUMMARY_STEP,
        'summary_groups' => admin_setup_wizard_summary($draft, $steps),
        'urls' => [
            'settings' => admin_settings_url(),
            'cancel' => admin_settings_url(),
            'restart' => url_for('admin_setup_wizard'),
        ],
        'theme_preview' => array_merge(admin_setup_wizard_theme_preview($draft), [
            'background_url' => theme_background_asset_url(),
        ]),
        'language_selector' => translation_public_language_selector_view_data(),
        'storage_paths' => [
            'galleries_root' => (string) ($config['galleries_root'] ?? ''),
            'migration_url' => url_for('admin'),
            'read_only' => true,
        ],
    ]);
}

/**
 * Process one CSRF-validated wizard POST.
 *
 * @param array<string,mixed> $draft Valid owner-bound draft.
 * @param array<string,array<string,mixed>> $steps Trusted wizard steps.
 * @param array<string,mixed> $existingErrors Existing bounded errors.
 * @return array{0:array<string,mixed>,1:array<string,mixed>} Updated draft and errors.
 */
function admin_setup_wizard_process_post(array $draft, array $steps, array $existingErrors = []): array
{
    $action = is_scalar($_POST['wizard_action'] ?? null) ? (string) $_POST['wizard_action'] : '';
    if (!in_array($action, ['back', 'next', 'skip', 'goto', 'cancel', 'restart', 'apply'], true)) {
        return [$draft, ['_page' => ['admin.setup_wizard.error.invalid_step']]];
    }
    $postedRevision = $_POST['revision'] ?? null;
    if (!is_string($postedRevision) || !ctype_digit($postedRevision)
        || (int) $postedRevision !== (int) $draft['revision']) {
        return [$draft, ['_page' => ['admin.setup_wizard.error.stale']]];
    }
    if ($action === 'cancel') {
        unset($_SESSION[ADMIN_SETUP_WIZARD_SESSION_KEY]);
        redirect_to(admin_settings_url());
    }
    if ($action === 'restart') {
        unset($_SESSION[ADMIN_SETUP_WIZARD_SESSION_KEY]);
        redirect_to(url_for('admin_setup_wizard'));
    }
    if ($action === 'apply') {
        return admin_setup_wizard_process_apply($draft);
    }

    $current = (string) $draft['step'];
    if ($current !== \Gallery\Services\ADMIN_SETUP_WIZARD_SUMMARY_STEP) {
        $postedStep = is_scalar($_POST['wizard_step'] ?? null) ? (string) $_POST['wizard_step'] : '';
        if ($postedStep !== $current || !isset($steps[$postedStep])) {
            return [$draft, ['_page' => ['admin.setup_wizard.error.invalid_step']]];
        }
    }
    $gotoCarriesStepValues = $action === 'goto' && (isset($_POST['settings']) || isset($_POST['include']));
    if ($current !== \Gallery\Services\ADMIN_SETUP_WIZARD_SUMMARY_STEP
        && ($action !== 'goto' || $gotoCarriesStepValues)) {
        $postedStep = (string) $_POST['wizard_step'];
        if ((isset($_POST['settings']) && !is_array($_POST['settings']))
            || (isset($_POST['include']) && !is_array($_POST['include']))) {
            return [$draft, ['_page' => ['admin.setup_wizard.error.invalid_value']]];
        }
        $staged = admin_setup_wizard_stage_step(
            $draft,
            $steps,
            $postedStep,
            is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [],
            is_array($_POST['include'] ?? null) ? $_POST['include'] : [],
            $action === 'skip'
        );
        if ($staged['errors'] !== []) {
            return [$draft, $staged['errors']];
        }
        $draft = $staged['draft'];
    }

    $target = is_scalar($_POST['target_step'] ?? null) ? (string) $_POST['target_step'] : '';
    try {
        $draft['step'] = admin_setup_wizard_navigation($current, $action, $steps, $target);
        $draft['revision'] = (int) $draft['revision'] + 1;
    } catch (AdminSetupWizardException $exception) {
        return [$draft, ['_page' => [$exception->errorKey()]]];
    }
    return [$draft, $existingErrors];
}

/**
 * Apply one explicitly approved summary and redirect on success.
 *
 * @param array<string,mixed> $draft Valid owner-bound draft.
 * @return array{0:array<string,mixed>,1:array<string,mixed>} Draft and bounded errors on failure.
 */
function admin_setup_wizard_process_apply(array $draft): array
{
    if ((string) $draft['step'] !== \Gallery\Services\ADMIN_SETUP_WIZARD_SUMMARY_STEP) {
        return [$draft, ['_page' => ['admin.setup_wizard.error.invalid_step']]];
    }
    if (!is_string($_POST['approval'] ?? null) || $_POST['approval'] !== '1') {
        return [$draft, ['_page' => ['admin.setup_wizard.error.approval_required']]];
    }

    $settingIds = array_values(array_map('strval', array_keys($draft['changes'])));
    $oldBaseUrl = rtrim((string) (cms_config()['base_url'] ?? ''), '/');
    $settingsUrl = admin_settings_url();
    try {
        $applied = admin_setup_wizard_apply($draft['changes'], $draft['original']);
        admin_setup_wizard_log_event('info', 'settings.setup_wizard_applied', 'Admin applied the Setup Wizard summary.', [
            'setting_ids' => $settingIds,
        ]);
        unset($_SESSION[ADMIN_SETUP_WIZARD_SESSION_KEY]);
        flash_message('admin_notice', t('admin.setup_wizard.notice.saved', 'Setup Wizard settings were saved.'));
        redirect_to(admin_setup_wizard_post_apply_url($settingsUrl, $oldBaseUrl, $applied));
    } catch (AdminSetupWizardException $exception) {
        admin_setup_wizard_log_failure($settingIds);
        return [$draft, ['_page' => [$exception->errorKey()]]];
    } catch (Throwable) {
        admin_setup_wizard_log_failure($settingIds);
        return [$draft, ['_page' => ['admin.setup_wizard.error.apply_failed']]];
    }
}

/**
 * Log a bounded apply failure without values, secrets, paths, or exception text.
 *
 * @param list<string> $settingIds Stable affected setting ids.
 * @return void
 */
function admin_setup_wizard_log_failure(array $settingIds): void
{
    admin_setup_wizard_log_event('error', 'settings.setup_wizard_apply_failed', 'Setup Wizard apply failed safely.', [
        'setting_ids' => $settingIds,
    ]);
}

/**
 * Write one best-effort bounded wizard event without affecting the HTTP outcome.
 *
 * @param string $level info, warning, or error.
 * @param string $eventKey Stable event identifier.
 * @param string $message Static non-sensitive message.
 * @param array{setting_ids:list<string>} $context Identifier-only context.
 * @return void
 */
function admin_setup_wizard_log_event(string $level, string $eventKey, string $message, array $context): void
{
    try {
        admin_log_event($level, $eventKey, $message, $context, [
            'category' => 'settings',
            'severity' => $level === 'error' ? 'error' : 'notice',
            'route_name' => 'admin_setup_wizard',
        ]);
    } catch (Throwable) {
    }
}

/**
 * Rebase the canonical Settings URL onto a newly saved base_url.
 *
 * @param string $settingsUrl Canonical pre-save Admin Settings URL.
 * @param string $oldBaseUrl Pre-save base URL.
 * @param array<string,mixed> $applied Applied normalized values.
 * @return string Safe post-apply redirect URL.
 */
function admin_setup_wizard_post_apply_url(string $settingsUrl, string $oldBaseUrl, array $applied): string
{
    if (!isset($applied['base_url']) || !is_string($applied['base_url']) || $oldBaseUrl === '') {
        return $settingsUrl;
    }
    if (!str_starts_with($settingsUrl, $oldBaseUrl)) {
        return rtrim($applied['base_url'], '/') . '/';
    }
    return rtrim($applied['base_url'], '/') . substr($settingsUrl, strlen($oldBaseUrl));
}

/**
 * Overlay staged values and include state onto presentation entries.
 *
 * @param array<string,array<string,mixed>> $steps Trusted wizard steps.
 * @param array<string,mixed> $draft Valid draft.
 * @return array<string,array<string,mixed>> Presentation-ready steps.
 */
function admin_setup_wizard_present_steps(array $steps, array $draft): array
{
    foreach ($steps as $sectionId => $step) {
        foreach ((array) ($step['entries'] ?? []) as $id => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = (string) $id;
            if (array_key_exists($id, $draft['changes'])) {
                $entry['value'] = $draft['changes'][$id];
            }
            $entry['included'] = empty($draft['skips'][$id]);
            $steps[$sectionId]['entries'][$id] = $entry;
        }
    }
    return $steps;
}

/**
 * Return a zero-based progress index for one safe active step.
 *
 * @param string $activeStep Section id or summary.
 * @param array<string,array<string,mixed>> $steps Trusted wizard steps.
 * @return int Progress index.
 */
function admin_setup_wizard_step_index(string $activeStep, array $steps): int
{
    if ($activeStep === \Gallery\Services\ADMIN_SETUP_WIZARD_SUMMARY_STEP) {
        return count($steps);
    }
    $index = array_search($activeStep, array_keys($steps), true);
    return $index === false ? 0 : (int) $index;
}
