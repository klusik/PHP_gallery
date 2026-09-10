<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/feature_policy_adapters_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Protects capability persistence adapters and fresh-install seeding semantics.
 *
 * Responsibilities:
 *   - Verify generic app-setting capability sources read and write canonical persisted values
 *   - Verify lazy domain adapters delegate to existing domain-owned setters
 *   - Verify Gallery Trash master writes preserve subordinate Trash preferences
 *   - Verify fresh-install defaults never overwrite explicit persisted administrator state
 *   - Verify fresh-install seeding is idempotent through its lifecycle marker
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Domain functions below are focused stubs for adapter behavior, not alternate production owners.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Services {
    /** Return a deterministic translated fallback for the isolated policy test. */
    function t(string $key, string|array|null $fallback = null, array $parameters = []): string
    {
        $text = is_string($fallback) ? $fallback : $key;
        foreach ($parameters as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    /** Return one in-memory application setting. */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return array_key_exists($key, $GLOBALS['feature_policy_adapter_settings'] ?? [])
            ? (string) $GLOBALS['feature_policy_adapter_settings'][$key]
            : $default;
    }

    /** Persist one in-memory application setting. */
    function set_app_setting(string $key, string $value): void
    {
        $GLOBALS['feature_policy_adapter_settings'][$key] = $value;
    }

    /** Return the isolated Gallery Trash master state. */
    function gallery_trash_enabled(): bool
    {
        return app_setting('gallery_trash_enabled', '1') !== '0';
    }

    /** Return the isolated Gallery Trash automatic-purge preference. */
    function gallery_trash_auto_purge_enabled(): bool
    {
        return app_setting('gallery_trash_auto_purge_enabled', '0') !== '0';
    }

    /** Return the isolated Gallery Trash retention period. */
    function gallery_trash_retention_days(): int
    {
        return (int) app_setting('gallery_trash_retention_days', '30');
    }

    /** Return the isolated Gallery Trash purge batch size. */
    function gallery_trash_purge_batch_size(): int
    {
        return (int) app_setting('gallery_trash_purge_batch', '77');
    }

    /** Persist isolated compound Gallery Trash settings and record the delegated values. */
    function set_gallery_trash_settings(bool $enabled, bool $autoPurge, int $retentionDays, int $batchSize): void
    {
        $GLOBALS['feature_policy_adapter_trash_write'] = [$enabled, $autoPurge, $retentionDays, $batchSize];
        set_app_setting('gallery_trash_enabled', $enabled ? '1' : '0');
    }

    /** Return the isolated thumbnail warmup master state. */
    function thumbnail_warmup_enabled(): bool
    {
        return app_setting('thumbnail_background_warmup_enabled', '1') !== '0';
    }

    /** Persist the isolated thumbnail warmup master state. */
    function set_thumbnail_warmup_enabled(bool $enabled): void
    {
        set_app_setting('thumbnail_background_warmup_enabled', $enabled ? '1' : '0');
    }

    /** Return the isolated development diagnostics state. */
    function dev_mode_enabled(): bool
    {
        return app_setting('dev_mode_enabled', '0') !== '0';
    }

    /** Persist the isolated development diagnostics state. */
    function set_dev_mode_enabled(bool $enabled): void
    {
        set_app_setting('dev_mode_enabled', $enabled ? '1' : '0');
    }

    /** Return the isolated automatic-update state. */
    function application_autoupdate_enabled(): bool
    {
        return app_setting('application_autoupdate_enabled', '1') !== '0';
    }

    /** Persist the isolated automatic-update state. */
    function set_application_autoupdate_enabled(bool $enabled): void
    {
        set_app_setting('application_autoupdate_enabled', $enabled ? '1' : '0');
    }
}

namespace {
    use function Gallery\Services\feature_capability_seed_fresh_install_defaults;
    use function Gallery\Services\feature_capability_source_has_explicit_value;
    use function Gallery\Services\feature_capability_source_read;
    use function Gallery\Services\feature_capability_source_setting_key;
    use function Gallery\Services\feature_capability_source_write;

    /** Throw when one persistence-adapter expectation fails. */
    function feature_policy_adapter_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    $root = dirname(__DIR__);
    require_once $root . '/app/services/feature_flags.php';

    $GLOBALS['feature_policy_adapter_settings'] = [];
    unset($GLOBALS['feature_policy_adapter_trash_write']);

    $appSettingDefinition = [
        'source' => ['type' => 'app_setting', 'key' => 'isolated_feature_enabled'],
        'default_enabled' => false,
    ];
    feature_policy_adapter_assert(
        feature_capability_source_setting_key('isolated_feature', $appSettingDefinition) === 'isolated_feature_enabled',
        'App-setting sources must expose their existing persisted key.'
    );
    feature_policy_adapter_assert(
        !feature_capability_source_has_explicit_value('isolated_feature', $appSettingDefinition),
        'Missing app-setting sources must not look explicitly configured.'
    );
    feature_policy_adapter_assert(
        !feature_capability_source_read('isolated_feature', $appSettingDefinition),
        'App-setting sources must honor their canonical default.'
    );
    feature_policy_adapter_assert(
        feature_capability_source_write('isolated_feature', $appSettingDefinition, true),
        'App-setting sources must accept writes through the generic adapter layer.'
    );
    feature_policy_adapter_assert(
        feature_capability_source_read('isolated_feature', $appSettingDefinition)
            && feature_capability_source_has_explicit_value('isolated_feature', $appSettingDefinition),
        'Generic app-setting writes must become explicit configured state.'
    );

    $trashDefinition = [
        'source' => ['type' => 'domain_adapter', 'adapter' => 'gallery_trash'],
        'default_enabled' => true,
    ];
    $GLOBALS['feature_policy_adapter_settings']['gallery_trash_auto_purge_enabled'] = '1';
    $GLOBALS['feature_policy_adapter_settings']['gallery_trash_retention_days'] = '45';
    $GLOBALS['feature_policy_adapter_settings']['gallery_trash_purge_batch'] = '91';
    feature_policy_adapter_assert(
        feature_capability_source_setting_key('gallery_trash', $trashDefinition) === 'gallery_trash_enabled',
        'Gallery Trash adapter must expose the existing master setting key.'
    );
    feature_policy_adapter_assert(
        feature_capability_source_write('gallery_trash', $trashDefinition, false),
        'Gallery Trash adapter must delegate master writes to the existing domain setter.'
    );
    feature_policy_adapter_assert(
        ($GLOBALS['feature_policy_adapter_trash_write'] ?? null) === [false, true, 45, 91],
        'Gallery Trash adapter must preserve subordinate purge, retention, and batch preferences.'
    );

    foreach ([
        'thumbnail_warmup' => ['setting' => 'thumbnail_background_warmup_enabled', 'default' => true],
        'development_diagnostics' => ['setting' => 'dev_mode_enabled', 'default' => false],
        'automatic_updates' => ['setting' => 'application_autoupdate_enabled', 'default' => true],
    ] as $adapterId => $expectation) {
        $definition = [
            'source' => ['type' => 'domain_adapter', 'adapter' => $adapterId],
            'default_enabled' => $expectation['default'],
        ];
        feature_policy_adapter_assert(
            feature_capability_source_setting_key($adapterId, $definition) === $expectation['setting'],
            'Domain adapter must expose its existing persisted master key: ' . $adapterId
        );
        feature_policy_adapter_assert(
            feature_capability_source_write($adapterId, $definition, false),
            'Domain adapter must accept canonical OFF writes: ' . $adapterId
        );
        feature_policy_adapter_assert(
            ($GLOBALS['feature_policy_adapter_settings'][$expectation['setting']] ?? null) === '0',
            'Domain adapter must delegate to the existing domain setter: ' . $adapterId
        );
    }

    // Fresh-install seeding writes only declared defaults and then becomes idempotent.
    $GLOBALS['feature_policy_adapter_settings'] = [];
    $freshDefinitions = [
        'fresh_optional' => [
            'source' => ['type' => 'app_setting', 'key' => 'fresh_optional_enabled'],
            'default_enabled' => true,
            'fresh_default_enabled' => false,
        ],
    ];
    $seeded = feature_capability_seed_fresh_install_defaults($freshDefinitions);
    feature_policy_adapter_assert($seeded === ['fresh_optional'], 'Fresh-install seeding must report the settings it initialized.');
    feature_policy_adapter_assert(
        ($GLOBALS['feature_policy_adapter_settings']['fresh_optional_enabled'] ?? null) === '0',
        'Fresh-install seeding must persist the declared fresh default through the source adapter.'
    );
    feature_policy_adapter_assert(
        ($GLOBALS['feature_policy_adapter_settings']['feature_capability.fresh_defaults_seeded'] ?? null) === '1',
        'Fresh-install seeding must persist its lifecycle marker.'
    );
    feature_policy_adapter_assert(
        feature_capability_seed_fresh_install_defaults($freshDefinitions) === [],
        'Fresh-install seeding must be idempotent after its lifecycle marker exists.'
    );

    // A failed source write must leave the lifecycle marker unset so setup can retry safely.
    $GLOBALS['feature_policy_adapter_settings'] = [];
    $incompleteDefinitions = [
        'unwritable_optional' => [
            'source' => ['type' => 'app_setting', 'key' => ''],
            'default_enabled' => true,
            'fresh_default_enabled' => false,
        ],
    ];
    feature_policy_adapter_assert(
        feature_capability_seed_fresh_install_defaults($incompleteDefinitions) === [],
        'Fresh-install seeding must not report an unaccepted source write.'
    );
    feature_policy_adapter_assert(
        !array_key_exists('feature_capability.fresh_defaults_seeded', $GLOBALS['feature_policy_adapter_settings']),
        'Fresh-install seeding must not mark an incomplete write pass as finished.'
    );

    // An explicit pre-existing value wins even during the first-install lifecycle.
    $GLOBALS['feature_policy_adapter_settings'] = ['fresh_optional_enabled' => '1'];
    $seeded = feature_capability_seed_fresh_install_defaults($freshDefinitions);
    feature_policy_adapter_assert($seeded === [], 'Fresh-install seeding must not report explicitly configured values as seeded.');
    feature_policy_adapter_assert(
        ($GLOBALS['feature_policy_adapter_settings']['fresh_optional_enabled'] ?? null) === '1',
        'Fresh-install seeding must never overwrite explicit persisted state.'
    );

    echo "Feature policy adapter tests passed.\n";
}
