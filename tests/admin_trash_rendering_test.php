<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_trash_rendering_test.php
 * Module Type: Regression Test
 * Purpose: Verify compact Trash presentation and lifecycle-safe controls without live storage.
 * Responsibilities:
 *   - Render production Trash branches using disposable prepared view models.
 *   - Preserve POST, CSRF, confirmation, token and delegated-refresh contracts.
 *   - Verify problem-entry refusals and HTML escaping.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace Gallery\Core {
    /**
     * Escape fixture strings through the ordinary HTML boundary.
     * @param string $value Untrusted prepared text.
     * @return string Escaped text or attribute value.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Build deterministic fixture destinations without installation configuration.
     * @param string $route Existing application route.
     * @param array<string,mixed> $params Optional query values.
     * @return string Local fixture URL.
     */
    function url_for(string $route, array $params = []): string
    {
        return '/index.php?' . http_build_query(['page' => $route] + $params);
    }

    /**
     * Supply CSRF markup so every rendered persistent form can be checked.
     * @return string Disposable hidden authority field.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="csrf_token" value="trash-fixture-csrf">';
    }
}

namespace Gallery\Services {
    /**
     * Translate fallback prose and interpolate fixture values without loading configuration.
     * @param string $key Existing translation identifier.
     * @param string $fallback Default localized prose.
     * @param array<string,mixed> $parameters Prepared placeholder values.
     * @return string Plain presentation text.
     */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        foreach ($parameters as $name => $value) {
            $fallback = str_replace('{' . $name . '}', (string) $value, $fallback);
        }
        return $fallback;
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/views/admin_ui.php';
    require_once dirname(__DIR__) . '/app/views/admin_trash.php';

    /**
     * Fail on a broken observable Trash contract.
     * @param bool $condition Expected invariant.
     * @param string $message Fixed failure description.
     * @return void Throws on a contract violation.
     */
    function trash_render_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Render production fragment markup and parse it into a disposable DOM.
     * @param array<int,array<string,mixed>> $entries Controller-prepared Trash entries.
     * @param array<string,mixed> $summary Prepared availability and safe counters.
     * @param array<string,mixed> $settings Prepared enablement and retention preferences.
     * @return DOMXPath Queryable DOM with no live installation access.
     */
    function trash_render_fixture(array $entries, array $summary, array $settings = []): DOMXPath
    {
        ob_start();
        try {
            \Gallery\Views\render_admin_trash_page($entries, $summary, true, $settings);
            $html = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new DOMXPath($document);
    }

    /**
     * Check every rendered form's transport and authority fields.
     * @param DOMXPath $dom Production-rendered fixture DOM.
     * @return void Rejects lost POST methods, CSRF fields, confirmations or stable tokens.
     */
    function trash_render_form_contracts(DOMXPath $dom): void
    {
        foreach ($dom->query('//form') as $form) {
            trash_render_assert(strtolower($form->getAttribute('method')) === 'post', 'Trash forms must remain POST-only.');
            trash_render_assert($dom->query('.//input[@name="csrf_token" and @value="trash-fixture-csrf"]', $form)->length === 1, 'Trash form lost its CSRF field.');
            if ($form->hasAttribute('data-admin-trash-restore-form') || $form->hasAttribute('data-admin-trash-purge-form') || $form->hasAttribute('data-admin-trash-empty-form')) {
                $action = $form->hasAttribute('data-admin-trash-restore-form') ? 'restore' : ($form->hasAttribute('data-admin-trash-purge-form') ? 'purge' : 'empty');
                trash_render_assert($form->getAttribute('action') === '/index.php?page=admin_trash_' . $action, 'Mutation form changed its existing route.');
                trash_render_assert(str_contains($form->getAttribute('onsubmit'), 'confirm('), 'Destructive or restore confirmation hook was lost.');
                if (!$form->hasAttribute('data-admin-trash-empty-form')) {
                    trash_render_assert($dom->query('.//input[@name="trash_token"]', $form)->length === 1, 'Per-entry mutation lost its stable token field.');
                }
            }
        }
    }

    $settings = ['enabled' => true, 'auto_purge_enabled' => false, 'auto_purge_active' => false, 'retention_days' => 30, 'purge_batch_size' => 20];
    $unavailable = trash_render_fixture([], ['available' => false], $settings);
    trash_render_assert($unavailable->query('//form')->length === 0, 'Unavailable Trash must not expose persistent controls.');
    trash_render_assert($unavailable->query('//*[@data-admin-trash-panel and @data-admin-trash-refresh-url]')->length === 1 && $unavailable->query('//*[@data-admin-trash-status]')->length === 1, 'Unavailable fragment lost delegated refresh/status anchors.');

    $empty = trash_render_fixture([], ['available' => true], $settings);
    trash_render_assert($empty->query('//form[@action="/index.php?page=admin_trash_settings"]')->length === 1, 'Empty Trash lost its settings POST form.');
    foreach (['gallery_trash_enabled', 'gallery_trash_auto_purge_enabled', 'gallery_trash_retention_days', 'gallery_trash_purge_batch'] as $name) {
        trash_render_assert($empty->query('//input[@name="' . $name . '"]')->length === 1, 'Trash settings changed a submitted field name.');
    }
    trash_render_assert($empty->query('//form[@data-admin-trash-empty-form or @data-admin-trash-restore-form or @data-admin-trash-purge-form]')->length === 0, 'Empty Trash exposes an entry mutation.');
    trash_render_assert($empty->query('//details[contains(@class,"admin-trash-settings-details") and not(@open)]//form')->length === 1, 'Trash settings must remain available inside the compact native disclosure.');
    trash_render_assert($empty->query('//table')->length === 0, 'Empty Trash must not render an empty listing table.');
    trash_render_form_contracts($empty);

    $base = ['trash_token' => str_repeat('a', 32), 'title' => '<script>gallery & title</script>', 'original_folder_path' => 'folder/<private>', 'deleted_by_username' => '<b>admin</b>', 'status' => 'trashed', 'view_can_purge' => true, 'view_days_remaining' => 7, 'purge_after' => '2026-10-09 12:00:00'];
    $staleUnavailable = trash_render_fixture([$base], ['available' => false, 'purgeable_count' => 1, 'active_count' => 1], $settings);
    trash_render_assert($staleUnavailable->query('//form|//table')->length === 0, 'Unverified availability overrides stale entry flags and counters.');
    foreach (['trashed' => [true, 1, 1], 'broken' => [false, 0, 0], 'preparing' => [false, 0, 0], 'restoring' => [false, 0, 0], 'purging' => [false, 0, 0]] as $state => $expectations) {
        $entry = array_replace($base, ['status' => $state, 'view_can_purge' => $expectations[0], 'last_error_code' => '<code>safe fixture</code>']);
        $dom = trash_render_fixture([$entry], ['available' => true, 'active_count' => 1, 'purgeable_count' => $state === 'trashed' ? 1 : 0, 'problem_count' => $state === 'trashed' ? 0 : 1], $settings);
        trash_render_assert($dom->query('//form[@data-admin-trash-restore-form]')->length === $expectations[1], 'Restore controls violate the prepared lifecycle state.');
        trash_render_assert($dom->query('//form[@data-admin-trash-purge-form]')->length === $expectations[2], 'Purge controls violate the prepared safety decision.');
        trash_render_assert($dom->query('//form[@data-admin-trash-empty-form]')->length === ($state === 'trashed' ? 1 : 0), 'Empty Trash must follow the verified purgeable counter.');
        trash_render_assert($dom->query('//script|//b|//private|//code[text()="safe fixture"]')->length === 0, 'Trash-authored values became active markup.');
        trash_render_assert(str_contains($dom->document->textContent, $base['title']) && str_contains($dom->document->textContent, $base['original_folder_path']), 'Escaping lost the original gallery identity.');
        trash_render_form_contracts($dom);
        if ($state !== 'trashed') {
            trash_render_assert($dom->query('//tbody/tr[contains(@class,"is-attention")]')->length === 1, 'Problem lifecycle row lost its visible attention state.');
        }
    }

    $safeBroken = trash_render_fixture([array_replace($base, ['status' => 'broken', 'view_can_purge' => true])], ['available' => true, 'purgeable_count' => 0, 'problem_count' => 1], $settings);
    trash_render_assert($safeBroken->query('//form[@data-admin-trash-purge-form]')->length === 1 && $safeBroken->query('//form[@data-admin-trash-restore-form or @data-admin-trash-empty-form]')->length === 0, 'An isolated problem entry permits only its explicitly approved purge.');
    trash_render_form_contracts($safeBroken);
    $paused = trash_render_fixture([$base], ['available' => true, 'purgeable_count' => 1], array_replace($settings, ['enabled' => false, 'auto_purge_enabled' => true]));
    trash_render_assert(str_contains($paused->document->textContent, 'Automatic purge is configured but paused'), 'Feature OFF must expose the configured purge pause.');
    trash_render_assert($paused->query('//details//p[contains(text(),"Automatic purge is configured but paused")]')->length === 0, 'Critical purge-pause guidance must remain outside collapsed settings.');
    trash_render_assert($paused->query('//form[@data-admin-trash-restore-form]')->length === 1, 'Turning Trash OFF must preserve recovery of stored contents.');
    $autoPurge = trash_render_fixture([$base], ['available' => true, 'purgeable_count' => 1], array_replace($settings, ['auto_purge_enabled' => true, 'auto_purge_active' => true]));
    trash_render_assert(str_contains($autoPurge->document->textContent, '7 day(s)') && str_contains($autoPurge->document->textContent, $base['purge_after']), 'Automatic purge ignored the prepared retention countdown.');
    echo "Trash production rendering: PASS\n";
}
