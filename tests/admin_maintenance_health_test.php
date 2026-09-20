<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_maintenance_health_test.php
 * Module Type: Regression Test
 * Purpose: Verify shared, bounded gallery-edit and pending-move health without live storage.
 * Responsibilities:
 *   - Prove optional/lazy readers remain idle and unsafe diagnostic text is excluded.
 *   - Render both production Admin surfaces using all maintained translation catalogs.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Translate through a real in-memory catalog without loading application configuration.
     * @param string $key Maintained catalog key.
     * @param string $fallback Source fallback for unrelated view labels.
     * @param array<string,mixed> $parameters Prepared interpolation values.
     * @return string Plain localized text.
     */
    function t(string $key, string $fallback = '', array $parameters = []): string
    {
        $text = $GLOBALS['maintenance_catalog'][$key] ?? $fallback;
        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /**
     * Supply the domain verifier outcome, counting calls instead of inspecting live schema.
     * @return string Injected three-state revision-storage verification outcome.
     */
    function gallery_edit_schema_state(): string
    {
        $GLOBALS['maintenance_edit_calls']++;
        return $GLOBALS['maintenance_edit_state'];
    }

    /**
     * Supply the existing bounded domain reader, with injectable redaction/failure cases.
     * @return array<int,array<string,mixed>> Disposable journal rows only.
     */
    function gallery_image_move_pending(): array
    {
        $GLOBALS['maintenance_pending_calls']++;
        if ($GLOBALS['maintenance_pending_throw']) {
            throw new \RuntimeException('PRIVATE_DATABASE_EXCEPTION');
        }
        return $GLOBALS['maintenance_rows'];
    }

    /**
     * Keep optional capabilities disabled; any accidentally invoked resolver is undefined.
     * @param string $feature Canonical capability key.
     * @return bool Always false so registry laziness is observable.
     */
    function feature_capability_effective_enabled(string $feature): bool
    {
        return false;
    }

    /**
     * Return safe fixture correlation without request/session globals.
     * @return string Disposable correlation identifier.
     */
    function telemetry_request_id(): string
    {
        return 'maintenance-fixture-reference';
    }

    /**
     * Interpret only the established aggregate unknown state.
     * @param array<string,mixed> $status Prepared schema status.
     * @return bool Whether metadata observation is inconclusive.
     */
    function schema_inspection_is_unknown(array $status): bool
    {
        return ($status['state'] ?? '') === 'unknown';
    }

    /**
     * Keep unrelated deletion storage available in this isolated registry fixture.
     * @return array{state:string} Confirmed available fixture result.
     */
    function gallery_deletion_schema_status(): array
    {
        return ['state' => 'available'];
    }

    /**
     * Keep unrelated Trash storage available without configuration reads.
     * @return array{state:string} Confirmed available fixture result.
     */
    function gallery_trash_schema_status(): array
    {
        return ['state' => 'available'];
    }

    /**
     * Supply the parent's journal-specific resolver rather than the older move schema.
     * @return array{state:string} Injected journal schema observation.
     */
    function gallery_image_move_journal_schema_status(): array
    {
        return ['state' => $GLOBALS['maintenance_journal_state']];
    }

    /**
     * Keep ingestion readiness unrelated to pending discovery.
     * @return array{state:string} Confirmed available fixture result.
     */
    function upload_ingestion_schema_status(): array
    {
        return ['state' => 'available'];
    }

    /**
     * Keep thumbnail readiness unrelated to pending discovery.
     * @return array{state:string} Confirmed available fixture result.
     */
    function thumbnail_metadata_mutation_schema_status(): array
    {
        return ['state' => 'available'];
    }
}

namespace Gallery\Core {
    /**
     * Escape prepared output with ordinary application HTML semantics.
     * @param string $value Unescaped text or attribute.
     * @return string Escaped HTML text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Supply deterministic presentation URLs without routing or configuration.
     * @param string $page Fixed route name from the view.
     * @param array<string,mixed> $parameters Optional query values.
     * @return string Local dummy URL; no request is made.
     */
    function url_for(string $page, array $parameters = []): string
    {
        return '/index.php?' . http_build_query(['page' => $page] + $parameters);
    }

    /**
     * Leave unrelated form authority empty in a read-only view fixture.
     * @return string Empty markup; no fixture forms are submitted.
     */
    function csrf_field(): string
    {
        return '';
    }
}

namespace Gallery\Views {
    /**
     * Omit unrelated introduction markup without altering health-card rendering.
     * @param array<string,mixed> $model Prepared presentation data, unused here.
     * @return void
     */
    function view_render_admin_tab_intro(array $model): void
    {
    }

    /**
     * Omit developer settings without consulting host configuration.
     * @param string $className Unused presentation wrapper.
     * @param array<string,mixed> $model Unused prepared developer settings.
     * @return void
     */
    function view_render_admin_devmode_card(string $className, array $model = []): void
    {
    }
}

namespace {
    require_once __DIR__ . '/support/module_source.php';
    require_once dirname(__DIR__) . '/app/services/admin_dashboard.php';
    require_once dirname(__DIR__) . '/app/views/admin_dashboard_sections.php';
    require_once dirname(__DIR__) . '/app/views/admin_diagnostics.php';

    /**
     * Count assertions and fail using fixed, non-sensitive descriptions.
     * @param bool $condition Observable contract outcome.
     * @param string $description Static failure explanation.
     * @return void
     */
    function maintenance_health_assert(bool $condition, string $description): void
    {
        $GLOBALS['maintenance_assertions']++;
        if (!$condition) {
            throw new RuntimeException($description);
        }
    }

    /**
     * Render actual System Health or Diagnostics with the same prepared health data.
     * @param string $surface Fixed dashboard/diagnostics selection.
     * @param array<string,array<string,mixed>> $statuses Shared mutation registry output.
     * @param array<string,mixed> $pending Read-only pending snapshot.
     * @return string Captured production HTML without host/database access.
     */
    function maintenance_health_render(string $surface, array $statuses, array $pending): string
    {
        ob_start();
        try {
            if ($surface === 'dashboard') {
                Gallery\Views\view_render_admin_dashboard_system_tools([
                    'mutation_schema_statuses' => $statuses,
                    'image_move_pending_status' => $pending,
                    'feature_enabled' => [],
                ]);
            } else {
                Gallery\Views\view_render_admin_diagnostics_page([
                    'mutation_schema_health' => $statuses,
                    'image_move_pending_status' => $pending,
                    'report_text' => implode("\n", $pending['report_lines'] ?? []),
                ]);
            }
            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }

    /**
     * Remove comments from source before asserting integration and non-mutating boundaries.
     * @param string $relative Repository-relative owned source file.
     * @return string Executable source text; it is never evaluated.
     */
    function maintenance_health_source(string $relative): string
    {
        $result = '';
        foreach (token_get_all(module_source(dirname(__DIR__) . '/' . $relative)) as $token) {
            if (!is_array($token)) {
                $result .= $token;
            } elseif (!in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $result .= $token[1];
            }
        }
        return $result;
    }

    $GLOBALS['maintenance_assertions'] = 0;
    $GLOBALS['maintenance_edit_calls'] = 0;
    $GLOBALS['maintenance_pending_calls'] = 0;
    $GLOBALS['maintenance_pending_throw'] = false;
    $GLOBALS['maintenance_rows'] = [];
    $GLOBALS['maintenance_journal_state'] = 'available';
    $GLOBALS['maintenance_edit_state'] = 'available';
    $GLOBALS['maintenance_catalog'] = [];

    maintenance_health_assert(Gallery\Services\admin_image_move_pending_health_status(['state' => 'available']) === [], 'Lazy health is omitted.');
    maintenance_health_assert($GLOBALS['maintenance_pending_calls'] === 0, 'Lazy health never invokes the journal reader.');
    foreach (['missing', 'unknown'] as $state) {
        $health = Gallery\Services\admin_image_move_pending_health_status(['state' => $state], true);
        maintenance_health_assert($health['state'] === $state && $health['action_required'], 'Unverified storage requires action without claiming an empty queue.');
    }
    maintenance_health_assert($GLOBALS['maintenance_pending_calls'] === 0, 'Unverified storage never queries journal rows.');

    $GLOBALS['maintenance_rows'] = array_fill(0, 31, [
        'operation_id' => str_repeat('a', 32), 'source_gallery_id' => 10,
        'destination_gallery_id' => 20, 'state' => 'needs_reconciliation',
        'last_error_code' => 'identity_or_storage_unverified',
        'manifest_json' => 'PRIVATE_MANIFEST', 'folder_path' => 'PRIVATE_PATH',
        'hash' => 'PRIVATE_CREDENTIAL',
    ]);
    $bounded = Gallery\Services\admin_image_move_pending_health_status(['state' => 'available'], true);
    maintenance_health_assert(count($bounded['entries']) === Gallery\Core\IMAGE_MOVE_DIAGNOSTIC_LIMIT, 'Independent presentation cap reuses the central journal limit.');
    maintenance_health_assert($bounded['state'] === 'pending' && $bounded['action_required'], 'Unfinished records are visibly actionable.');
    maintenance_health_assert(str_contains($bounded['entries'][0], 'identity_or_storage_unverified'), 'Approved safe recovery category remains discoverable.');
    maintenance_health_assert(!str_contains(json_encode($bounded, JSON_THROW_ON_ERROR), 'PRIVATE_'), 'No manifests, paths, hashes or extra row fields escape.');

    $GLOBALS['maintenance_rows'][0]['last_error_code'] = 'PRIVATE_STORED_ERROR';
    $GLOBALS['maintenance_rows'][1]['state'] = 'PRIVATE_STORED_STATE';
    $GLOBALS['maintenance_rows'][2]['operation_id'] = 'PRIVATE_OPERATION_PATH';
    $redacted = Gallery\Services\admin_image_move_pending_health_status(['state' => 'available'], true);
    maintenance_health_assert(!str_contains(json_encode($redacted, JSON_THROW_ON_ERROR), 'PRIVATE_'), 'Malformed identifiers and arbitrary stored status/error strings never escape.');
    maintenance_health_assert($redacted['state'] === 'unknown' && $redacted['action_required'], 'Malformed observations cannot become a healthy empty queue.');

    $GLOBALS['maintenance_pending_throw'] = true;
    $failed = Gallery\Services\admin_image_move_pending_health_status(['state' => 'available'], true);
    maintenance_health_assert($failed['state'] === 'unknown' && $failed['entries'] === [], 'Read failure has no partial success claim.');
    maintenance_health_assert($failed['request_id'] === 'maintenance-fixture-reference', 'Failed row observation has safe request correlation.');
    maintenance_health_assert(!str_contains(json_encode($failed, JSON_THROW_ON_ERROR), 'PRIVATE_'), 'Database exception text is excluded.');
    $GLOBALS['maintenance_pending_throw'] = false;

    $renderCount = 0;
    $requiredKeys = [];
    foreach (['en', 'cs', 'de', 'sv'] as $locale) {
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/lang/' . $locale . '.json');
        $catalog = json_decode($source, true, 512, JSON_THROW_ON_ERROR);
        $GLOBALS['maintenance_catalog'] = $catalog;
        if ($locale === 'en') {
            $requiredKeys = array_values(array_filter(array_keys($catalog),
                /**
                 * Select only this fixture's gallery-protection and move-discovery labels.
                 * @param string $key Flat maintained-catalog key, never a translated value.
                 * @return bool Whether this test owns the key's translation contract.
                 */
                static fn (string $key): bool =>
                str_starts_with($key, 'admin.health_gallery_edit.') || str_starts_with($key, 'admin.health_image_moves.')));
            maintenance_health_assert(count($requiredKeys) === 11, 'The new health namespace has exactly eleven catalog keys.');
        }
        foreach ($requiredKeys as $key) {
            maintenance_health_assert(is_string($catalog[$key] ?? null) && $catalog[$key] !== '', 'Each maintained catalog has every health label.');
            maintenance_health_assert(substr_count($source, '"' . $key . '":') === 1, 'New catalog keys are unique.');
            preg_match_all('/\{[a-z_]+\}/', $catalog[$key], $actualParameters);
            $english = json_decode((string) file_get_contents(dirname(__DIR__) . '/app/lang/en.json'), true, 512, JSON_THROW_ON_ERROR);
            preg_match_all('/\{[a-z_]+\}/', $english[$key], $expectedParameters);
            sort($actualParameters[0]);
            sort($expectedParameters[0]);
            maintenance_health_assert($actualParameters[0] === $expectedParameters[0], 'Localized interpolation matches its English owner.');
        }
        if (in_array($locale, ['en', 'cs'], true)) {
            $fallback = require dirname(__DIR__) . '/app/lang/' . $locale . '.php';
            foreach ($requiredKeys as $key) {
                maintenance_health_assert(($fallback[$key] ?? null) === $catalog[$key], 'Used PHP fallbacks agree with JSON catalogs.');
            }
        }
        foreach (['available', 'missing', 'unknown'] as $state) {
            $GLOBALS['maintenance_edit_state'] = $state;
            $before = $GLOBALS['maintenance_edit_calls'];
            $statuses = Gallery\Services\admin_mutation_schema_health_statuses();
            $edit = $statuses['mutation_gallery_edit'];
            maintenance_health_assert($GLOBALS['maintenance_edit_calls'] === $before + 1 && $edit['state'] === $state, 'Shared registration delegates exactly once to the domain verifier.');
            maintenance_health_assert($edit['affected_objects'] === ($state === 'available' ? [] : ['galleries.edit_revision']), 'Trigger refusal exposes only the fixed revision identity.');
            maintenance_health_assert($statuses['mutation_gallery_move']['state'] === 'available', 'Parent journal-specific resolver remains in use.');
            maintenance_health_assert($statuses['mutation_upload_automation']['state'] === 'disabled', 'Optional capability OFF does not invoke an undefined resolver.');
            foreach (['clear', 'pending', 'missing', 'unknown'] as $pendingState) {
                $GLOBALS['maintenance_rows'] = $pendingState === 'pending' ? [[
                    'operation_id' => str_repeat('b', 32), 'source_gallery_id' => 1,
                    'destination_gallery_id' => 2, 'state' => 'prepared', 'last_error_code' => null,
                ]] : [];
                $pending = Gallery\Services\admin_image_move_pending_health_status([
                    'state' => in_array($pendingState, ['missing', 'unknown'], true) ? $pendingState : 'available',
                ], true);
                foreach (['dashboard', 'diagnostics'] as $surface) {
                    $html = maintenance_health_render($surface, ['mutation_gallery_edit' => $edit], $pending);
                    $renderCount++;
                    maintenance_health_assert(str_contains($html, Gallery\Core\e($edit['title'])) && str_contains($html, Gallery\Core\e($edit['message'])), 'Both surfaces use the same localized trigger-verification model.');
                    maintenance_health_assert(str_contains($html, 'data-pending-state="' . $pendingState . '"') && str_contains($html, Gallery\Core\e($pending['labels']['summary'])), 'Both surfaces use the same localized pending snapshot.');
                    if ($state !== 'available' || $pendingState !== 'clear') {
                        maintenance_health_assert(str_contains($html, 'admin-tab-badge'), 'Action-required states have a visible badge.');
                    }
                    if ($surface === 'dashboard' && $pendingState !== 'clear') {
                        maintenance_health_assert(!str_contains($html, Gallery\Core\e($catalog['admin.dashboard.system_ready_title'] ?? 'System ready')), 'Pending or unverifiable work suppresses the System ready card.');
                    }
                }
            }
        }
    }

    $escapeHealth = $bounded;
    $escapeHealth['entries'] = ['<script>PRIVATE_SCRIPT</script>'];
    $escapeEdit = ['state' => 'unknown', 'title' => '<b>protected</b>', 'message' => '<script>PRIVATE_MESSAGE</script>'];
    foreach (['dashboard', 'diagnostics'] as $surface) {
        $html = maintenance_health_render($surface, ['mutation_gallery_edit' => $escapeEdit], $escapeHealth);
        maintenance_health_assert(!str_contains($html, '<script>PRIVATE_') && str_contains($html, '&lt;script&gt;PRIVATE_'), 'Shared views escape every prepared dynamic line.');
    }

    $dashboard = maintenance_health_source('app/services/admin_dashboard.php');
    $diagnostics = maintenance_health_source('app/controllers/admin_diagnostics.php');
    $part = maintenance_health_source('app/services/admin_dashboard/maintenance_health.php');
    maintenance_health_assert(str_contains($dashboard, "admin_image_move_pending_health_status(\$mutationSchemaStatuses['mutation_gallery_move'] ?? [], \$includeMaintenance)"), 'The dashboard gates journal discovery on explicit maintenance loading.');
    maintenance_health_assert(str_contains($diagnostics, "admin_image_move_pending_health_status(\$mutationSchemaHealth['mutation_gallery_move'] ?? [], true)"), 'Diagnostics opts into the same read-only owner.');
    maintenance_health_assert(strpos($diagnostics, 'require_admin();') < strpos($diagnostics, '$imageMovePending ='), 'Authentication precedes pending discovery.');
    maintenance_health_assert(str_contains($diagnostics, "\$imageMovePending['report_lines']") && str_contains($diagnostics, "\$schemaHealth['message']"), 'The copy report includes pending evidence and the prepared trigger message.');
    maintenance_health_assert(!preg_match('/gallery_image_move_reconcile|gallery_image_move_execute|scandir|glob\\(|RecursiveDirectoryIterator|file_get_contents|db\\(|->(?:query|prepare|exec)\\(/', $part), 'The health adapter has no recovery, filesystem walk or direct SQL.');
    foreach (['app/views/admin_dashboard.php', 'app/views/admin_dashboard_sections.php', 'app/views/admin_diagnostics.php'] as $path) {
        $view = maintenance_health_source($path);
        maintenance_health_assert(!str_contains($view, 'gallery_edit_schema_state(') && !str_contains($view, 'gallery_image_move_pending(') && !str_contains($view, 'admin_image_move_pending_health_status('), 'Views never call schema/discovery policy services.');
    }
    echo 'PASS admin maintenance health: ' . $GLOBALS['maintenance_assertions'] . ' assertions, '
        . $renderCount . " localized renders plus 2 escaping renders; no live database, filesystem recovery or central audit.\n";
}
