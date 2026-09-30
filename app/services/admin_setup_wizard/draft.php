<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/admin_setup_wizard/draft.php
 * Module Type: Service part
 *
 * Purpose:
 *   Owns the pure Setup Wizard draft, skip, navigation, and summary policy.
 *
 * Responsibilities:
 *   - Create and validate owner-bound draft values
 *   - Stage or skip one section atomically
 *   - Build safe navigation and summary results
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
 *   - Loaded only by app/services/admin_setup_wizard.php.
 *   - Do not register this part in app/services.php.
 */

declare(strict_types=1);

namespace Gallery\Services;

use InvalidArgumentException;

/**
 * Start a controller-owned draft from a trusted wizard snapshot.
 *
 * @param int $ownerId Authenticated administrator id.
 * @param array<string,array<string,mixed>> $steps Trusted wizard steps.
 * @return array{owner:int,revision:int,step:string,changes:array<string,mixed>,skips:array<string,bool>,original:array<string,mixed>} New draft.
 */
function admin_setup_wizard_begin_draft(int $ownerId, array $steps): array
{
    if ($ownerId <= 0 || $steps === []) {
        throw new AdminSetupWizardException('admin.setup_wizard.error.invalid_draft');
    }
    $original = [];
    foreach ($steps as $step) {
        foreach ((array) ($step['entries'] ?? []) as $id => $entry) {
            if (is_array($entry) && !empty($entry['wizard_editable'])) {
                $original[(string) $id] = $entry['current'] ?? null;
            }
        }
    }
    return [
        'owner' => $ownerId,
        'revision' => 1,
        'step' => (string) array_key_first($steps),
        'changes' => [],
        'skips' => [],
        'original' => $original,
    ];
}

/**
 * Validate the complete session draft shape and owner binding.
 *
 * @param array<string,mixed> $draft Candidate session draft.
 * @param int $ownerId Authenticated administrator id.
 * @param array<string,array<string,mixed>> $steps Trusted wizard steps.
 * @return bool True only for a safe reusable draft.
 */
function admin_setup_wizard_draft_valid(array $draft, int $ownerId, array $steps): bool
{
    if ($ownerId <= 0 || !is_int($draft['owner'] ?? null) || $draft['owner'] !== $ownerId
        || !is_int($draft['revision'] ?? null) || $draft['revision'] < 1) {
        return false;
    }
    $step = (string) ($draft['step'] ?? '');
    if (!isset($steps[$step]) && $step !== ADMIN_SETUP_WIZARD_SUMMARY_STEP) {
        return false;
    }
    foreach (['changes', 'skips', 'original'] as $key) {
        if (!is_array($draft[$key] ?? null)) {
            return false;
        }
    }
    $editable = admin_setup_wizard_editable_entries($steps);
    $known = admin_setup_wizard_all_entries($steps);
    foreach (array_keys($draft['changes']) as $id) {
        if (!isset($editable[(string) $id]) || !array_key_exists((string) $id, $draft['original'])) {
            return false;
        }
    }
    foreach ($draft['skips'] as $id => $skipped) {
        if (!isset($known[(string) $id]) || $skipped !== true) {
            return false;
        }
    }
    foreach (array_keys($draft['original']) as $id) {
        if (!isset($editable[(string) $id])) {
            return false;
        }
    }
    foreach (array_keys($editable) as $id) {
        if (!array_key_exists($id, $draft['original'])) {
            return false;
        }
    }
    return true;
}

/**
 * Stage or skip every editable field from one section atomically in the draft.
 *
 * @param array<string,mixed> $draft Valid draft.
 * @param array<string,array<string,mixed>> $steps Trusted wizard steps.
 * @param string $sectionId Current section id.
 * @param array<string,mixed> $settings Submitted setting values.
 * @param array<string,mixed> $include Submitted include controls.
 * @param bool $skipSection Whether the whole section is skipped.
 * @return array{draft:array<string,mixed>,errors:array<string,string>} Updated draft or original draft with bounded field errors.
 */
function admin_setup_wizard_stage_step(
    array $draft,
    array $steps,
    string $sectionId,
    array $settings,
    array $include,
    bool $skipSection = false
): array {
    if (!isset($steps[$sectionId])) {
        return ['draft' => $draft, 'errors' => ['_page' => 'admin.setup_wizard.error.invalid_step']];
    }
    $entries = (array) ($steps[$sectionId]['entries'] ?? []);
    foreach (array_keys($settings) as $submittedId) {
        $submittedId = (string) $submittedId;
        if (!isset($entries[$submittedId]) || !is_array($entries[$submittedId]) || empty($entries[$submittedId]['wizard_editable'])) {
            return ['draft' => $draft, 'errors' => [$submittedId => 'admin.setup_wizard.error.unavailable']];
        }
    }
    foreach (array_keys($include) as $submittedId) {
        $submittedId = (string) $submittedId;
        if (!isset($entries[$submittedId]) || !is_array($entries[$submittedId])) {
            return ['draft' => $draft, 'errors' => [$submittedId => 'admin.setup_wizard.error.unavailable']];
        }
    }

    $candidate = $draft;
    $errors = [];
    foreach ($entries as $id => $entry) {
        $id = (string) $id;
        if (!is_array($entry)) {
            continue;
        }
        if (empty($entry['wizard_editable'])) {
            // Specialist operations and secret/file-backed resources are informational in the
            // staged wizard. They never need a fake "reviewed" checkbox and are never marked
            // skipped merely because no editable value was posted for them.
            unset($candidate['skips'][$id], $candidate['changes'][$id]);
            continue;
        }
        if ($skipSection || !array_key_exists($id, $include)) {
            $candidate['skips'][$id] = true;
            unset($candidate['changes'][$id]);
            continue;
        }
        if (is_array($include[$id] ?? null)) {
            $errors[$id] = 'admin.setup_wizard.error.invalid_value';
            continue;
        }
        $inputType = (string) ($entry['input_type'] ?? 'text');
        $raw = $inputType === 'checkbox' && !array_key_exists($id, $settings) ? '' : ($settings[$id] ?? null);
        try {
            $normalized = admin_setup_wizard_normalize_entry($entry, $raw);
            $original = $candidate['original'][$id] ?? ($entry['current'] ?? null);
            if (admin_setup_wizard_values_equal($normalized, admin_setup_wizard_normalize_original($entry, $original))) {
                unset($candidate['changes'][$id]);
            } else {
                $candidate['changes'][$id] = $normalized;
            }
            unset($candidate['skips'][$id]);
        } catch (InvalidArgumentException) {
            $errors[$id] = 'admin.setup_wizard.error.invalid_value';
        }
    }
    return $errors === []
        ? ['draft' => $candidate, 'errors' => []]
        : ['draft' => $draft, 'errors' => $errors];
}

/**
 * Resolve a whitelisted navigation destination.
 *
 * @param string $current Current section or summary id.
 * @param string $action back, next, skip, or goto.
 * @param array<string,array<string,mixed>> $steps Trusted wizard steps.
 * @param string $target Requested goto target.
 * @return string Safe destination section or summary id.
 */
function admin_setup_wizard_navigation(string $current, string $action, array $steps, string $target = ''): string
{
    $ids = array_keys($steps);
    if ($ids === []) {
        throw new AdminSetupWizardException('admin.setup_wizard.error.invalid_step');
    }
    if ($current === ADMIN_SETUP_WIZARD_SUMMARY_STEP) {
        if ($action === 'back') {
            return (string) end($ids);
        }
        if ($action === 'goto' && isset($steps[$target])) {
            return $target;
        }
        return ADMIN_SETUP_WIZARD_SUMMARY_STEP;
    }
    $index = array_search($current, $ids, true);
    if ($index === false) {
        throw new AdminSetupWizardException('admin.setup_wizard.error.invalid_step');
    }
    if ($action === 'goto') {
        if (!isset($steps[$target])) {
            throw new AdminSetupWizardException('admin.setup_wizard.error.invalid_step');
        }
        return $target;
    }
    if ($action === 'back') {
        return (string) $ids[max(0, $index - 1)];
    }
    if (in_array($action, ['next', 'skip'], true)) {
        return isset($ids[$index + 1]) ? (string) $ids[$index + 1] : ADMIN_SETUP_WIZARD_SUMMARY_STEP;
    }
    throw new AdminSetupWizardException('admin.setup_wizard.error.invalid_step');
}

/**
 * Build presentation-ready summary groups without exposing sensitive values.
 *
 * @param array<string,mixed> $draft Valid draft.
 * @param array<string,array<string,mixed>> $steps Trusted wizard steps.
 * @return list<array{id:string,title:string,title_key:string,changes:list<array<string,mixed>>,reviewed:list<array<string,mixed>>,skipped:list<string>,deferred:list<array<string,string>>}> Summary groups.
 */
function admin_setup_wizard_summary(array $draft, array $steps): array
{
    $groups = [];
    foreach ($steps as $sectionId => $step) {
        $changes = [];
        $reviewed = [];
        $skipped = [];
        $deferred = [];
        foreach ((array) ($step['entries'] ?? []) as $id => $entry) {
            $id = (string) $id;
            if (!is_array($entry)) {
                continue;
            }
            if (array_key_exists($id, $draft['changes'])) {
                $value = $draft['changes'][$id];
                $changes[] = [
                    'id' => $id,
                    'label' => (string) ($entry['label'] ?? $id),
                    'label_key' => (string) ($entry['label_key'] ?? ''),
                    'before' => $draft['original'][$id] ?? ($entry['current'] ?? null),
                    'after' => $value,
                    'value' => $value,
                    'display' => admin_setup_wizard_summary_display($value),
                ];
            } elseif (!empty($draft['skips'][$id])) {
                $skipped[] = $id;
            } elseif (empty($entry['wizard_editable'])) {
                $deferred[] = [
                    'id' => $id,
                    'label' => (string) ($entry['label'] ?? $id),
                    'label_key' => (string) ($entry['label_key'] ?? ''),
                ];
            } else {
                $value = $draft['original'][$id] ?? ($entry['current'] ?? null);
                $reviewed[] = [
                    'id' => $id,
                    'label' => (string) ($entry['label'] ?? $id),
                    'label_key' => (string) ($entry['label_key'] ?? ''),
                    'value' => $value,
                    'display' => admin_setup_wizard_summary_display($value),
                ];
            }
        }
        if ($changes !== [] || $reviewed !== [] || $skipped !== [] || $deferred !== []) {
            $definition = is_array($step['definition'] ?? null) ? $step['definition'] : [];
            $groups[] = [
                'id' => (string) $sectionId,
                'title' => (string) ($definition['label'] ?? $sectionId),
                'title_key' => (string) ($definition['label_key'] ?? ''),
                'changes' => $changes,
                'reviewed' => $reviewed,
                'skipped' => $skipped,
                'deferred' => $deferred,
            ];
        }
    }
    return $groups;
}

/**
 * Render a bounded scalar summary for a safe normalized wizard value.
 *
 * @param scalar|array<string,mixed>|list<scalar>|null $value Safe normalized value.
 * @return string Human-readable bounded summary.
 */
function admin_setup_wizard_summary_display(mixed $value): string
{
    if (!is_array($value)) {
        return (string) $value;
    }
    if (array_is_list($value) && count($value) <= ADMIN_SETUP_WIZARD_SUMMARY_LIST_LIMIT
        && array_reduce($value,
            /**
             * Check whether every visited list item is scalar.
             *
             * @param bool $safe Accumulated scalar-only state.
             * @param scalar|array<array-key,mixed>|object|null $item Candidate normalized item.
             * @return bool Updated scalar-only state.
             */
            static fn (bool $safe, mixed $item): bool => $safe && is_scalar($item),
            true
        )) {
        return implode(', ', array_map(
            /**
             * Format one scalar list item for bounded summary output.
             *
             * @param scalar $item Validated scalar list item.
             * @return string Display text.
             */
            static fn (mixed $item): string => (string) $item,
            $value
        ));
    }
    return t('admin.setup_wizard.value_customized', 'Customized');
}
