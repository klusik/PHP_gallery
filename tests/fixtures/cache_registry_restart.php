<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/cache_registry_restart.php
 * Module Type: Isolated Process Fixture
 * Purpose: Read persisted registry evidence after a genuinely independent PHP process starts.
 * Responsibilities: Require a random fixture ownership marker and open only its disposable SQLite and private checkpoints.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services {
    /**
     * Supply absent fixture preferences without application configuration or credentials.
     * @param string $key Requested preference identity.
     * @param string|null $default Fallback value for the isolated reader.
     * @return string|null Empty recovery reference or the supplied fallback.
     */
    function app_setting(string $key, ?string $default = null): ?string
    {
        return $key === 'application_update_beta_backup_path' ? '' : $default;
    }
}
namespace {
    $root = $argv[1] ?? '';
    if (!is_string($root) || !is_file($root . '/.cache-registry-owner')
        || !str_starts_with(basename($root), 'gallery-cache-registry-')) throw new \RuntimeException('Fixture owner is absent.');
    // Bind every passive path and DB read to the random parent's disposable root.
    // Type: string. Units: absolute directory. Scope: this independent child process.
    // Consumers: lifecycle path guards. Rationale: ownership validation prevents live installation reads.
    define('CACHE_LIFECYCLE_TEST_ROOT', $root);
    $GLOBALS['cache_lifecycle_test_now'] = (int) ($argv[2] ?? 0);
    require_once dirname(__DIR__) . '/support/cache_registry_fixture.php';
    $GLOBALS['cache_lifecycle_test_database'] = cache_registry_fixture_database($root, false);
    require_once dirname(__DIR__, 2) . '/app/release_file_policy.php';
    require_once dirname(__DIR__, 2) . '/app/services/cache_lifecycle_storage.php';
    echo json_encode(\Gallery\Services\cache_lifecycle_status(), JSON_THROW_ON_ERROR);
}
