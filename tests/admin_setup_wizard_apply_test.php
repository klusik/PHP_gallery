<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/admin_setup_wizard_apply_test.php
 * Module Type: Test Script
 *
 * Purpose:
 *   Exercises Setup Wizard apply and controller boundaries without a live database.
 *
 * Responsibilities:
 *   - Prove schema, identity, original-value, conflict, and secret refusals precede writes
 *   - Prove canonical saves, rollback fixtures, base_url ordering, and cache resets
 *   - Prove controller revision, approval, auth, CSRF, GET, and redirect behavior
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

namespace Gallery\Tests\AdminSetupWizardApply {
    use RuntimeException;

    /**
     * Assert one focused wizard behavior.
     *
     * @param bool $condition Evaluated condition.
     * @param string $message Failure message.
     * @return void
     */
    function assert_true(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /**
     * Reset every mutable fake used by the service and controller.
     *
     * @return void
     */
    function reset_fixture(): void
    {
        $GLOBALS['wizard_settings'] = ['alpha' => 'old-a', 'beta' => 'old-b'];
        $GLOBALS['wizard_config_url'] = '';
        $GLOBALS['wizard_schema_state'] = 'available';
        $GLOBALS['wizard_model_calls'] = 0;
        $GLOBALS['wizard_locked_keys'] = [];
        $GLOBALS['wizard_locked_telemetry_keys'] = [];
        $GLOBALS['wizard_telemetry_schema_state'] = 'available';
        $GLOBALS['wizard_events'] = [];
        $GLOBALS['wizard_save_calls'] = [];
        $GLOBALS['wizard_fail_save_id'] = null;
        $GLOBALS['wizard_before_transaction_operation'] = null;
        $GLOBALS['wizard_commit_failure'] = false;
        $GLOBALS['wizard_cache_resets'] = 0;
        $GLOBALS['wizard_auth_failure'] = false;
        $GLOBALS['wizard_csrf_failure'] = false;
        $GLOBALS['wizard_request_method'] = 'GET';
        $GLOBALS['wizard_view_model'] = null;
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
    }

    /**
     * Return the expected baseline originals for editable fixture entries.
     *
     * @return array<string,string> Original normalized fixture values.
     */
    function originals(): array
    {
        return ['alpha' => 'old-a', 'beta' => 'old-b', 'base_url' => ''];
    }

    /**
     * Marks authentication and CSRF boundary exits in controller tests.
     */
    final class BoundaryTrap extends RuntimeException
    {
    }
}

namespace Gallery\Core {
    /**
     * Return the authenticated fixture administrator.
     *
     * @return array{id:int,role:string} Fixture administrator.
     */
    function current_user(): array
    {
        return ['id' => 7, 'role' => 'admin'];
    }

    /**
     * Enforce the fixture administrator boundary.
     *
     * @return void
     */
    function require_admin(): void
    {
        if (!empty($GLOBALS['wizard_auth_failure'])) {
            throw new \Gallery\Tests\AdminSetupWizardApply\BoundaryTrap('auth');
        }
    }

    /**
     * Enforce the fixture CSRF boundary.
     *
     * @return void
     */
    function verify_csrf(): void
    {
        if (!empty($GLOBALS['wizard_csrf_failure'])) {
            throw new \Gallery\Tests\AdminSetupWizardApply\BoundaryTrap('csrf');
        }
    }

    /**
     * Return the selected fixture request method.
     *
     * @return string Selected fixture request method.
     */
    function request_method(): string
    {
        return (string) $GLOBALS['wizard_request_method'];
    }

    /**
     * Return the non-sensitive fixture configuration.
     *
     * @return array{base_url:string,galleries_root:string} Non-sensitive fixture configuration.
     */
    function cms_config(): array
    {
        return ['base_url' => (string) $GLOBALS['wizard_config_url'], 'galleries_root' => 'fixture-galleries'];
    }

    /**
     * Build a stable fixture route URL.
     *
     * @param string $page Route name.
     * @param array<string,mixed> $params Route parameters.
     * @return string Stable fixture route URL.
     */
    function url_for(string $page, array $params = []): string
    {
        return 'https://old.test/index.php?' . http_build_query(['page' => $page] + $params);
    }

    /**
     * Store or retrieve a fixture flash message.
     *
     * @param string $key Flash key.
     * @param ?string $value Optional new value.
     * @return ?string Stored fixture value.
     */
    function flash_message(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            $GLOBALS['wizard_flash'][$key] = $value;
        }
        return $GLOBALS['wizard_flash'][$key] ?? null;
    }

    /**
     * Emit the redirect target and terminate the isolated child process.
     *
     * @param string $url Redirect target.
     * @return never
     */
    function redirect_to(string $url): never
    {
        echo 'REDIRECT:' . $url;
        exit(0);
    }
}

namespace Gallery\Models {
    /**
     * Simulate the real model transaction while retaining observable rollback state.
     *
     * @param list<string> $settingKeys Locked canonical keys.
     * @param callable():array<string,mixed> $operation Transaction work.
     * @param null|callable():void $compensate File compensation.
     * @param list<string> $telemetrySettingKeys Locked telemetry keys.
     * @return array<string,mixed> Applied normalized values.
     */
    function admin_setup_wizard_model_transaction(
        array $settingKeys,
        callable $operation,
        ?callable $compensate = null,
        array $telemetrySettingKeys = []
    ): mixed {
        $GLOBALS['wizard_model_calls']++;
        $GLOBALS['wizard_locked_keys'] = $settingKeys;
        $GLOBALS['wizard_locked_telemetry_keys'] = $telemetrySettingKeys;
        $settingsBefore = $GLOBALS['wizard_settings'];
        $before = $GLOBALS['wizard_before_transaction_operation'];
        $GLOBALS['wizard_before_transaction_operation'] = null;
        if (is_callable($before)) {
            $before();
        }
        try {
            $result = $operation();
            if (!empty($GLOBALS['wizard_commit_failure'])) {
                throw new \RuntimeException('fixture commit failure');
            }
            $GLOBALS['wizard_events'][] = 'commit';
            return $result;
        } catch (\Throwable $exception) {
            $GLOBALS['wizard_settings'] = $settingsBefore;
            $GLOBALS['wizard_events'][] = 'rollback';
            if ($compensate !== null) {
                $compensate();
            }
            throw $exception;
        }
    }
}

namespace Gallery\Services {
    use InvalidArgumentException;

    /**
     * Return the complete fixture section taxonomy.
     *
     * @return array<string,array{label:string,label_key:string}> Fixture section definitions.
     */
    function admin_settings_sections(): array
    {
        return [
            'general' => ['label' => 'General', 'label_key' => 'general'],
            'site' => ['label' => 'Site', 'label_key' => 'site'],
            'appearance' => ['label' => 'Appearance', 'label_key' => 'appearance'],
            'content' => ['label' => 'Content', 'label_key' => 'content'],
            'media' => ['label' => 'Media', 'label_key' => 'media'],
            'uploads' => ['label' => 'Uploads', 'label_key' => 'uploads'],
            'privacy' => ['label' => 'Privacy', 'label_key' => 'privacy'],
            'advanced' => ['label' => 'Advanced', 'label_key' => 'advanced'],
        ];
    }

    /**
     * Normalize a candidate to one fixture section identifier.
     *
     * @param scalar|array<array-key,mixed>|object|null $section Candidate section, including invalid types to reject.
     * @return string Known section id.
     */
    function admin_settings_section_normalize(mixed $section): string
    {
        $section = is_scalar($section) ? (string) $section : '';
        return array_key_exists($section, admin_settings_sections()) ? $section : 'general';
    }

    /**
     * Return the fixture registry used by apply tests.
     *
     * @return array<string,array<string,mixed>> Fixture registry entries.
     */
    function admin_settings_registry(): array
    {
        return [
            'alpha' => fixture_entry('alpha', 'general', 'setting.alpha', $GLOBALS['wizard_settings']['alpha']),
            'beta' => fixture_entry('beta', 'general', 'setting.beta', $GLOBALS['wizard_settings']['beta']),
            'base_url' => fixture_entry('base_url', 'site', 'base_url', $GLOBALS['wizard_config_url']),
            'secret_key' => fixture_entry('secret_key', 'advanced', 'secret_key', 'Configured', false, 'secret'),
        ];
    }

    /**
     * Build one complete fixture registry entry.
     *
     * @param string $id Stable id.
     * @param string $group Section id.
     * @param string $key Storage key.
     * @param scalar|array<array-key,mixed>|null $current Current value.
     * @param bool $editable Whether central editing is allowed.
     * @param string $sensitivity Sensitivity class.
     * @return array<string,mixed> Registry entry.
     */
    function fixture_entry(
        string $id,
        string $group,
        string $key,
        mixed $current,
        bool $editable = true,
        string $sensitivity = 'normal'
    ): array {
        return [
            'id' => $id,
            'group' => $group,
            'key' => $key,
            'label' => ucfirst($id),
            'label_key' => 'fixture.' . $id,
            'description' => $id,
            'description_key' => 'fixture.' . $id . '.hint',
            'input_type' => 'text',
            'current' => $current,
            'central_editable' => $editable,
            'sensitivity' => $sensitivity,
            'validation' => [],
            'migration_required' => false,
            'specialized_url' => '',
        ];
    }

    /**
     * Return an empty Theme adapter map for central-setting fixtures.
     *
     * @return array<string,string> Empty Theme adapter map.
     */
    function theme_basic_appearance_settings(): array
    {
        return [];
    }

    /**
     * Reject unexpected Theme normalization in this central-setting fixture.
     *
     * @param string $id Theme id.
     * @param string|list<string> $value Candidate value; lists exercise rejection.
     * @return string Never returned.
     */
    function theme_basic_appearance_normalize(string $id, mixed $value): string
    {
        throw new InvalidArgumentException('unexpected theme adapter: ' . $id);
    }

    /**
     * Reject unexpected Theme persistence in this central-setting fixture.
     *
     * @param string $id Theme id.
     * @param string|list<string> $value Candidate value; lists exercise rejection.
     * @return void
     */
    function theme_basic_appearance_save(string $id, mixed $value): void
    {
        throw new InvalidArgumentException('unexpected theme save: ' . $id);
    }

    /**
     * Return an empty safe Theme layout adapter map for central-setting fixtures.
     *
     * @return array<string,string> Empty Theme layout adapter map.
     */
    function theme_layout_safe_settings(): array
    {
        return [];
    }

    /**
     * Reject unexpected safe Theme layout availability checks in this central fixture.
     *
     * @param string $id Theme layout id.
     * @return bool Never returned for a registered fixture value.
     */
    function theme_layout_safe_setting_available(string $id): bool
    {
        return false;
    }

    /**
     * Reject unexpected safe Theme layout normalization in this central fixture.
     *
     * @param string $id Theme layout id.
     * @param scalar|array<array-key,mixed>|object|null $value Candidate value.
     * @return string Never returned.
     */
    function theme_layout_safe_normalize(string $id, mixed $value): string
    {
        throw new InvalidArgumentException('unexpected theme layout adapter: ' . $id);
    }

    /**
     * Reject unexpected safe Theme layout persistence in this central fixture.
     *
     * @param string $id Theme layout id.
     * @param scalar|array<array-key,mixed>|object|null $value Candidate value.
     * @return void
     */
    function theme_layout_safe_save(string $id, mixed $value): void
    {
        throw new InvalidArgumentException('unexpected theme layout save: ' . $id);
    }

    /**
     * Strictly normalize the fixture Admin upload source-format adapter.
     *
     * @param mixed $value Candidate value.
     * @return string Canonical value.
     */
    function admin_upload_client_format_mode_validate(mixed $value): string
    {
        if (!is_string($value) || !in_array(trim($value), ['server_supported', 'phone_jpeg'], true)) {
            throw new InvalidArgumentException('invalid upload mode');
        }
        return trim($value);
    }

    /** Strictly normalize the fixture upload auto-rename adapter. */
    function admin_upload_auto_rename_setting_normalize(mixed $value): string
    {
        if (!is_string($value) || !in_array(trim($value), ['0', '1'], true)) {
            throw new InvalidArgumentException('invalid upload rename');
        }
        return trim($value);
    }

    /** Strictly normalize one fixture browser-upload scalar. */
    function browser_upload_safe_scalar_normalize(string $id, mixed $value): string
    {
        if ($id !== 'browser_upload_enabled' || !is_string($value) || !in_array(trim($value), ['0', '1'], true)) {
            throw new InvalidArgumentException('invalid browser upload value');
        }
        return trim($value);
    }

    /** Strictly normalize one fixture telemetry scalar. */
    function telemetry_admin_setting_normalize(string $id, mixed $value): string
    {
        if ($id !== 'telemetry_enabled' || !is_string($value) || !in_array(trim($value), ['0', '1'], true)) {
            throw new InvalidArgumentException('invalid telemetry value');
        }
        return trim($value);
    }

    /** Persist a fixture Admin upload mode through its owner adapter. */
    function save_admin_upload_client_format_mode(mixed $value): string
    {
        $normalized = admin_upload_client_format_mode_validate($value);
        $GLOBALS['wizard_events'][] = 'save:admin_upload_client_format_mode';
        return $normalized;
    }

    /** Persist a fixture upload auto-rename value through its owner adapter. */
    function save_admin_upload_auto_rename_setting(mixed $value): string
    {
        $normalized = admin_upload_auto_rename_setting_normalize($value);
        $GLOBALS['wizard_events'][] = 'save:admin_upload_auto_rename_enabled';
        return $normalized;
    }

    /** Persist a fixture browser-upload scalar through its owner adapter. */
    function browser_upload_safe_scalar_save(string $id, mixed $value): string
    {
        $normalized = browser_upload_safe_scalar_normalize($id, $value);
        $GLOBALS['wizard_events'][] = 'save:' . $id;
        return $normalized;
    }

    /** Persist a fixture telemetry scalar through its owner adapter. */
    function telemetry_admin_setting_save(string $id, mixed $value): string
    {
        $normalized = telemetry_admin_setting_normalize($id, $value);
        $GLOBALS['wizard_events'][] = 'save:' . $id;
        return $normalized;
    }

    /** Return the fixture telemetry settings schema status. */
    function presentation_telemetry_settings_schema_status(): array
    {
        return ['state' => (string) $GLOBALS['wizard_telemetry_schema_state']];
    }

    /**
     * Return the fixture public thumbnail renderer used by wizard preview preparation.
     *
     * @return string Fixture renderer mode.
     */
    function public_thumbnail_rendering_mode(): string
    {
        return 'progressive';
    }

    /**
     * Normalize one editable fixture registry value.
     *
     * @param array<string,mixed> $entry Registry entry.
     * @param string|list<string> $value Candidate value; lists exercise rejection.
     * @return string Normalized value.
     */
    function admin_settings_normalize_editable_value(array $entry, mixed $value): string
    {
        if ((string) ($entry['id'] ?? '') === 'base_url') {
            return site_url_normalize($value);
        }
        if (!is_string($value)) {
            throw new InvalidArgumentException('fixture values must be strings');
        }
        return trim($value);
    }

    /**
     * Persist one normalized fixture setting value.
     *
     * @param string $id Stable setting id.
     * @param string $value Normalized value.
     * @return void
     */
    function admin_settings_save_editable_value(string $id, mixed $value): void
    {
        $GLOBALS['wizard_save_calls'][] = $id;
        $GLOBALS['wizard_events'][] = 'save:' . $id;
        if ($GLOBALS['wizard_fail_save_id'] === $id) {
            throw new \RuntimeException('fixture save failure');
        }
        $GLOBALS['wizard_settings'][$id] = (string) $value;
    }

    /**
     * Normalize an HTTP(S) fixture website URL.
     *
     * @param string|list<string> $value Candidate URL; lists exercise rejection.
     * @return string Normalized URL.
     */
    function site_url_normalize(mixed $value): string
    {
        if (!is_string($value) || !preg_match('#^https?://#', trim($value))) {
            throw new InvalidArgumentException('invalid fixture URL');
        }
        return rtrim(trim($value), '/');
    }

    /**
     * Return the uncached fixture URL.
     *
     * @return string Uncached fixture URL.
     */
    function site_url_current_value(): string
    {
        return (string) $GLOBALS['wizard_config_url'];
    }

    /**
     * Save a fixture URL and return exact-value compensation.
     *
     * @param string $url Normalized URL.
     * @return callable():void Exact-value compensation.
     */
    function site_url_save_reversible(string $url): callable
    {
        $before = (string) $GLOBALS['wizard_config_url'];
        $GLOBALS['wizard_config_url'] = site_url_normalize($url);
        $GLOBALS['wizard_events'][] = 'save:base_url';
        /**
         * Restore the fixture URL captured before the write.
         *
         * @return void
         */
        $restore = static function () use ($before): void {
            $GLOBALS['wizard_config_url'] = $before;
            $GLOBALS['wizard_events'][] = 'restore:base_url';
        };
        return $restore;
    }

    /**
     * Return the configured fixture schema status for a table.
     *
     * @param string $table Table name.
     * @return array{state:string,table:string} Fixture schema status.
     */
    function schema_inspection_table(string $table): array
    {
        return ['state' => (string) $GLOBALS['wizard_schema_state'], 'table' => $table];
    }

    /**
     * Test whether a fixture schema status is available.
     *
     * @param array<string,mixed> $status Schema status.
     * @return bool Whether it is available.
     */
    function schema_inspection_is_available(array $status): bool
    {
        return ($status['state'] ?? '') === 'available';
    }

    /**
     * Return the available GPS schema fixture status.
     *
     * @return array{state:string} Available GPS schema status.
     */
    function presentation_gps_override_schema_status(): array
    {
        return ['state' => 'available'];
    }

    /**
     * Report every fixture capability as effectively enabled.
     *
     * @param string $key Capability key.
     * @return bool Always available.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return true;
    }

    /**
     * Record a request-local settings cache reset.
     *
     * @return void
     */
    function app_settings_reset_request_cache(): void
    {
        $GLOBALS['wizard_cache_resets']++;
    }

    /**
     * Return the canonical fixture Admin Settings URL.
     *
     * @param ?string $section Optional section.
     * @param ?string $return Optional return token.
     * @return string Fixture settings URL.
     */
    function admin_settings_url(?string $section = null, ?string $return = null): string
    {
        return 'https://old.test/index.php?page=admin_settings';
    }

    /**
     * Record a bounded fixture administrator event.
     *
     * @param string $level Log level.
     * @param string $eventKey Event key.
     * @param string $message Static message.
     * @param array<string,mixed> $context Bounded context.
     * @param array<string,mixed> $options Log options.
     * @return void
     */
    function admin_log_event(string $level, string $eventKey, string $message, array $context = [], array $options = []): void
    {
        $GLOBALS['wizard_log'][] = [$level, $eventKey, $context];
    }

    /**
     * Resolve a fixture translation through its fallback text.
     *
     * @param string $key Translation key.
     * @param string $fallback Fallback text.
     * @param array<string,mixed> $replace Replacements.
     * @return string Fixture translation.
     */
    function t(string $key, string $fallback = '', array $replace = []): string
    {
        return $fallback !== '' ? $fallback : $key;
    }

    /**
     * Return empty language-selector presentation data.
     *
     * @return array<string,mixed> Empty selector presentation data.
     */
    function translation_public_language_selector_view_data(): array
    {
        return [];
    }

    /**
     * Return the empty Theme background URL fixture.
     *
     * @return string Empty Theme background URL.
     */
    function theme_background_asset_url(): string
    {
        return '';
    }

    /**
     * Return the fixture site name.
     *
     * @return string Fixture site name.
     */
    function site_name(): string
    {
        return 'Fixture';
    }

    require_once __DIR__ . '/../app/services/admin_setup_wizard.php';
}

namespace Gallery\Views {
    /**
     * Capture the prepared Setup Wizard view model.
     *
     * @param array<string,mixed> $model Controller view model.
     * @return void
     */
    function view_render_admin_setup_wizard_page(array $model): void
    {
        $GLOBALS['wizard_view_model'] = $model;
    }
}

namespace Gallery\Controllers {
    require_once __DIR__ . '/../app/controllers/admin_setup_wizard.php';
}

namespace Gallery\Tests\AdminSetupWizardApply {
    use Gallery\Services\AdminSetupWizardException;
    use function Gallery\Controllers\admin_setup_wizard_process_post;
    use function Gallery\Controllers\cms_admin_setup_wizard;
    use function Gallery\Services\admin_setup_wizard_apply;
    use function Gallery\Services\admin_setup_wizard_begin_draft;
    use function Gallery\Services\admin_setup_wizard_normalize_entry;
    use function Gallery\Services\admin_setup_wizard_preflight;
    use function Gallery\Services\admin_setup_wizard_save_entry;
    use function Gallery\Services\admin_setup_wizard_steps;
    use function Gallery\Services\admin_setup_wizard_storage_keys;
    use function Gallery\Services\admin_setup_wizard_telemetry_storage_keys;

    /**
     * Assert that wizard work fails with one bounded domain key.
     *
     * @param callable():array<string,mixed> $operation Operation expected to fail.
     * @param string $errorKey Expected bounded key.
     * @return void
     */
    function expect_wizard_error(callable $operation, string $errorKey): void
    {
        try {
            $operation();
        } catch (AdminSetupWizardException $exception) {
            assert_true($exception->errorKey() === $errorKey, 'Unexpected wizard error key: ' . $exception->errorKey());
            return;
        }
        throw new \RuntimeException('Expected wizard failure was not raised: ' . $errorKey);
    }

    /**
     * Execute the approved summary path in an isolated redirecting process.
     *
     * @return never
     */
    function run_redirect_child(): never
    {
        reset_fixture();
        $steps = admin_setup_wizard_steps();
        $draft = admin_setup_wizard_begin_draft(7, $steps);
        $draft['step'] = \Gallery\Services\ADMIN_SETUP_WIZARD_SUMMARY_STEP;
        $draft['changes'] = ['alpha' => 'new-a'];
        $_POST = ['wizard_action' => 'apply', 'revision' => '1', 'approval' => '1'];
        admin_setup_wizard_process_post($draft, $steps);
        throw new \RuntimeException('Successful apply did not redirect.');
    }

    if (getenv('GALLERY_WIZARD_REDIRECT_CHILD') === '1') {
        run_redirect_child();
    }

    reset_fixture();
    foreach (['missing', 'unknown'] as $state) {
        $GLOBALS['wizard_schema_state'] = $state;
        expect_wizard_error(
            /**
             * Attempt an apply while required storage is unavailable.
             *
             * @return array<string,mixed> Unreachable apply result.
             */
            static fn (): array => admin_setup_wizard_apply(['alpha' => 'new-a'], originals()),
            'admin.setup_wizard.error.unavailable'
        );
        assert_true($GLOBALS['wizard_model_calls'] === 0 && $GLOBALS['wizard_save_calls'] === [], 'Unavailable schema reached a write boundary.');
    }

    reset_fixture();
    expect_wizard_error(
        /**
         * Attempt to apply an unregistered identifier.
         *
         * @return array<string,mixed> Unreachable apply result.
         */
        static fn (): array => admin_setup_wizard_apply(['unknown' => 'value'], originals()),
        'admin.setup_wizard.error.invalid_draft'
    );
    expect_wizard_error(
        /**
         * Attempt to apply a value without its optimistic original.
         *
         * @return array<string,mixed> Unreachable apply result.
         */
        static fn (): array => admin_setup_wizard_apply(['alpha' => 'new-a'], []),
        'admin.setup_wizard.error.invalid_draft'
    );
    expect_wizard_error(
        /**
         * Attempt to apply a nested value to a scalar setting.
         *
         * @return array<string,mixed> Unreachable apply result.
         */
        static fn (): array => admin_setup_wizard_apply(['alpha' => ['nested']], originals()),
        'admin.setup_wizard.error.invalid_value'
    );
    expect_wizard_error(
        /**
         * Attempt to apply a sensitive registry identifier.
         *
         * @return array<string,mixed> Unreachable apply result.
         */
        static fn (): array => admin_setup_wizard_apply(['secret_key' => 'secret'], ['secret_key' => 'Configured']),
        'admin.setup_wizard.error.invalid_draft'
    );
    assert_true($GLOBALS['wizard_model_calls'] === 0, 'Invalid or secret payload reached the transaction boundary.');

    reset_fixture();
    /**
     * Introduce a competing database setting update after transaction start.
     *
     * @return void
     */
    $databaseConflict = static function (): void {
        $GLOBALS['wizard_settings']['beta'] = 'concurrent-b';
    };
    $GLOBALS['wizard_before_transaction_operation'] = $databaseConflict;
    expect_wizard_error(
        /**
         * Attempt to apply against stale database originals.
         *
         * @return array<string,mixed> Unreachable apply result.
         */
        static fn (): array => admin_setup_wizard_apply(['alpha' => 'new-a', 'beta' => 'new-b'], originals()),
        'admin.setup_wizard.error.conflict'
    );
    assert_true($GLOBALS['wizard_save_calls'] === [], 'A conflict wrote an earlier setting before checking every original.');
    assert_true(!in_array('restore:base_url', $GLOBALS['wizard_events'], true), 'Conflict attempted URL compensation before any URL change.');

    reset_fixture();
    $applied = admin_setup_wizard_apply(['beta' => ' new-b ', 'alpha' => ' new-a '], originals());
    assert_true($applied === ['alpha' => 'new-a', 'beta' => 'new-b'], 'Successful apply did not return normalized stable ordering.');
    assert_true($GLOBALS['wizard_save_calls'] === ['alpha', 'beta'], 'Canonical setters were not called exactly once in stable order.');
    assert_true($GLOBALS['wizard_locked_keys'] === ['setting.alpha', 'setting.beta'], 'Transaction did not receive the expected sorted lock keys.');
    assert_true($GLOBALS['wizard_cache_resets'] >= 2, 'Successful apply did not refresh request-local settings state.');

    reset_fixture();
    $GLOBALS['wizard_fail_save_id'] = 'beta';
    try {
        admin_setup_wizard_apply(['alpha' => 'new-a', 'beta' => 'new-b'], originals());
        throw new \RuntimeException('Second canonical save failure was accepted.');
    } catch (\RuntimeException $exception) {
        assert_true($exception->getMessage() === 'fixture save failure', 'Unexpected canonical save failure.');
    }
    assert_true($GLOBALS['wizard_settings'] === ['alpha' => 'old-a', 'beta' => 'old-b'], 'Transaction fixture did not roll back the first save.');
    assert_true(in_array('rollback', $GLOBALS['wizard_events'], true), 'Save failure did not roll back.');
    assert_true($GLOBALS['wizard_cache_resets'] >= 2, 'Failed apply did not refresh request-local settings state.');

    reset_fixture();
    $GLOBALS['wizard_commit_failure'] = true;
    try {
        admin_setup_wizard_apply(['alpha' => 'new-a', 'base_url' => 'https://new.test/gallery'], originals());
        throw new \RuntimeException('Commit failure was accepted.');
    } catch (\RuntimeException $exception) {
        assert_true($exception->getMessage() === 'fixture commit failure', 'Unexpected commit failure result.');
    }
    assert_true($GLOBALS['wizard_config_url'] === '', 'Commit failure did not restore the exact legacy empty base_url.');
    assert_true($GLOBALS['wizard_settings']['alpha'] === 'old-a', 'Commit failure did not roll back database settings.');
    $saveAlpha = array_search('save:alpha', $GLOBALS['wizard_events'], true);
    $saveUrl = array_search('save:base_url', $GLOBALS['wizard_events'], true);
    $restoreUrl = array_search('restore:base_url', $GLOBALS['wizard_events'], true);
    assert_true(is_int($saveAlpha) && is_int($saveUrl) && is_int($restoreUrl) && $saveAlpha < $saveUrl && $saveUrl < $restoreUrl, 'base_url was not last or was not compensated after commit failure.');

    reset_fixture();
    /**
     * Introduce a competing base URL update after transaction start.
     *
     * @return void
     */
    $configConflict = static function (): void {
        $GLOBALS['wizard_config_url'] = 'https://concurrent.test';
    };
    $GLOBALS['wizard_before_transaction_operation'] = $configConflict;
    expect_wizard_error(
        /**
         * Attempt to apply against the stale configuration original.
         *
         * @return array<string,mixed> Unreachable apply result.
         */
        static fn (): array => admin_setup_wizard_apply(['base_url' => 'https://new.test'], originals()),
        'admin.setup_wizard.error.conflict'
    );
    assert_true(!in_array('save:base_url', $GLOBALS['wizard_events'], true), 'Stale config was overwritten.');

    reset_fixture();
    $phaseThreeEntries = [
        'admin_upload_client_format_mode' => ['id' => 'admin_upload_client_format_mode', 'wizard_adapter' => 'admin_upload_safe', 'key' => 'admin_upload_client_format_mode', 'wizard_editable' => true, 'unavailable' => false],
        'browser_upload_enabled' => ['id' => 'browser_upload_enabled', 'wizard_adapter' => 'browser_upload_safe', 'key' => 'browser_upload_enabled', 'wizard_editable' => true, 'unavailable' => false],
        'telemetry_enabled' => ['id' => 'telemetry_enabled', 'wizard_adapter' => 'telemetry_safe', 'key' => '', 'wizard_editable' => true, 'unavailable' => false],
    ];
    assert_true(
        admin_setup_wizard_storage_keys(
            ['telemetry_enabled' => '1', 'browser_upload_enabled' => '1', 'admin_upload_client_format_mode' => 'phone_jpeg'],
            $phaseThreeEntries
        ) === ['browser_upload_enabled', 'admin_upload_client_format_mode'],
        'Phase 3 app_settings lock keys crossed into telemetry storage or lost upload keys.'
    );
    assert_true(
        admin_setup_wizard_telemetry_storage_keys(['telemetry_enabled' => '1', 'browser_upload_enabled' => '1'], $phaseThreeEntries) === ['telemetry_enabled'],
        'Phase 3 telemetry lock keys were not isolated.'
    );
    assert_true(
        admin_setup_wizard_storage_keys(
            ['browser_upload_default_worker_count' => '8'],
            ['browser_upload_default_worker_count' => ['id' => 'browser_upload_default_worker_count', 'wizard_adapter' => 'browser_upload_safe', 'key' => 'browser_upload_default_worker_count']]
        ) === ['browser_upload_default_worker_count', 'browser_upload_max_worker_count', 'browser_upload_hard_worker_cap'],
        'Browser default worker validation dependencies must be locked with the edited row.'
    );
    assert_true(
        admin_setup_wizard_normalize_entry($phaseThreeEntries['admin_upload_client_format_mode'], 'phone_jpeg') === 'phone_jpeg'
        && admin_setup_wizard_normalize_entry($phaseThreeEntries['browser_upload_enabled'], '1') === '1'
        && admin_setup_wizard_normalize_entry($phaseThreeEntries['telemetry_enabled'], '1') === '1',
        'Phase 3 owner adapters did not normalize through their dedicated boundaries.'
    );
    admin_setup_wizard_save_entry($phaseThreeEntries['admin_upload_client_format_mode'], 'phone_jpeg');
    admin_setup_wizard_save_entry($phaseThreeEntries['browser_upload_enabled'], '1');
    admin_setup_wizard_save_entry($phaseThreeEntries['telemetry_enabled'], '1');
    assert_true(
        array_slice($GLOBALS['wizard_events'], -3) === ['save:admin_upload_client_format_mode', 'save:browser_upload_enabled', 'save:telemetry_enabled'],
        'Phase 3 values did not route through canonical upload and telemetry owner adapters.'
    );
    $GLOBALS['wizard_schema_state'] = 'missing';
    $GLOBALS['wizard_telemetry_schema_state'] = 'available';
    admin_setup_wizard_preflight(['telemetry_enabled' => '1'], $phaseThreeEntries);
    $GLOBALS['wizard_telemetry_schema_state'] = 'unknown';
    expect_wizard_error(
        static fn (): array => (admin_setup_wizard_preflight(['telemetry_enabled' => '1'], $phaseThreeEntries) ?? []),
        'admin.setup_wizard.error.unavailable'
    );

    reset_fixture();
    $steps = admin_setup_wizard_steps();
    $draft = admin_setup_wizard_begin_draft(7, $steps);
    $_POST = ['wizard_action' => 'next', 'revision' => [], 'wizard_step' => 'general'];
    [, $errors] = admin_setup_wizard_process_post($draft, $steps);
    assert_true(($errors['_page'][0] ?? '') === 'admin.setup_wizard.error.stale' && $GLOBALS['wizard_model_calls'] === 0, 'Array revision bypassed stale protection.');

    $_POST = ['wizard_action' => 'apply', 'revision' => '1', 'approval' => '1'];
    [, $errors] = admin_setup_wizard_process_post($draft, $steps);
    assert_true(($errors['_page'][0] ?? '') === 'admin.setup_wizard.error.invalid_step' && $GLOBALS['wizard_model_calls'] === 0, 'Apply outside summary reached persistence.');

    $draft['step'] = \Gallery\Services\ADMIN_SETUP_WIZARD_SUMMARY_STEP;
    $_POST = ['wizard_action' => 'apply', 'revision' => '1'];
    [, $errors] = admin_setup_wizard_process_post($draft, $steps);
    assert_true(($errors['_page'][0] ?? '') === 'admin.setup_wizard.error.approval_required' && $GLOBALS['wizard_model_calls'] === 0, 'Missing approval reached persistence.');
    $_POST = ['wizard_action' => 'apply', 'revision' => '1', 'approval' => ['1']];
    [, $errors] = admin_setup_wizard_process_post($draft, $steps);
    assert_true(($errors['_page'][0] ?? '') === 'admin.setup_wizard.error.approval_required' && $GLOBALS['wizard_model_calls'] === 0, 'Array approval reached persistence.');

    reset_fixture();
    $GLOBALS['wizard_auth_failure'] = true;
    try {
        cms_admin_setup_wizard();
        throw new \RuntimeException('Authentication failure was ignored.');
    } catch (BoundaryTrap $exception) {
        assert_true($exception->getMessage() === 'auth', 'Unexpected authentication trap.');
    }
    assert_true($GLOBALS['wizard_model_calls'] === 0, 'Authentication failure reached persistence.');

    reset_fixture();
    $GLOBALS['wizard_request_method'] = 'POST';
    $GLOBALS['wizard_csrf_failure'] = true;
    $_POST = ['wizard_action' => 'next', 'revision' => '1', 'wizard_step' => 'general'];
    try {
        cms_admin_setup_wizard();
        throw new \RuntimeException('CSRF failure was ignored.');
    } catch (BoundaryTrap $exception) {
        assert_true($exception->getMessage() === 'csrf', 'Unexpected CSRF trap.');
    }
    assert_true($GLOBALS['wizard_model_calls'] === 0, 'CSRF failure reached persistence.');

    reset_fixture();
    $_GET = ['step' => 'site'];
    cms_admin_setup_wizard();
    assert_true(($_SESSION['admin_setup_wizard_draft']['step'] ?? '') === 'site', 'Whitelisted GET navigation was not persisted in the session draft.');
    assert_true($GLOBALS['wizard_model_calls'] === 0 && $GLOBALS['wizard_save_calls'] === [], 'GET navigation persisted settings.');

    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $environment = array_merge($_ENV, ['GALLERY_WIZARD_REDIRECT_CHILD' => '1']);
    $process = proc_open([PHP_BINARY, __FILE__], $descriptor, $pipes, __DIR__, $environment);
    assert_true(is_resource($process), 'Could not start redirect child process.');
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    assert_true($exitCode === 0, 'Redirect child failed: ' . trim((string) $stderr));
    assert_true(str_contains((string) $stdout, 'REDIRECT:https://old.test/index.php?page=admin_settings'), 'Successful approved summary did not use the canonical Settings redirect.');

    echo "admin_setup_wizard_apply_test: PASS\n";
}
