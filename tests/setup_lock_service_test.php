<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/setup_lock_service_test.php
 * Module Type: Regression Test
 * Purpose: Verify setup marker ownership without changing the installed site's lock.
 * Responsibilities:
 *   - Preserve fixed-path AND semantics and marker format, and prove bounded storage refusal.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

/**
 * Require a setup-storage invariant using only the private fixture tree.
 * @param bool $condition Expected marker or refusal property.
 * @param string $message Safe assertion label.
 * @return void
 */
function setup_lock_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$root = sys_get_temp_dir() . '/gallery-setup-lock-' . bin2hex(random_bytes(12));
$directories = ['', '/app', '/app/services'];
$files = ['/app/services/auth_accounts.php', '/app/policy_constants.php', '/config.php', '/cache/installed.lock'];
try {
    foreach ($directories as $directory) { mkdir($root . $directory); }
    foreach (['/app/services/auth_accounts.php', '/app/policy_constants.php'] as $source) {
        setup_lock_assert(copy(dirname(__DIR__) . $source, $root . $source), 'Fixture source copy failed.');
    }
    require $root . '/app/services/auth_accounts.php';
    setup_lock_assert(!Gallery\Services\auth_setup_is_locked(), 'A fresh fixture appeared installed.');
    Gallery\Services\auth_setup_write_lock();
    $marker = (string) file_get_contents($root . '/cache/installed.lock');
    setup_lock_assert(preg_match('/^installed=\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00\r?\n$/D', $marker) === 1, 'Setup marker format or UTC timezone changed.');
    setup_lock_assert(!Gallery\Services\auth_setup_is_locked(), 'In-application setup changed its config-and-marker condition.');
    file_put_contents($root . '/config.php', 'fixture-only; not PHP configuration');
    setup_lock_assert(Gallery\Services\auth_setup_is_locked(), 'Existing config plus marker did not lock setup.');
    Gallery\Services\auth_setup_write_lock();
    setup_lock_assert(Gallery\Services\auth_setup_is_locked(), 'Repeated completion lost the marker.');

    unlink($root . '/cache/installed.lock');
    rmdir($root . '/cache');
    file_put_contents($root . '/cache', 'owned fixture obstruction');
    $refused = false;
    try { Gallery\Services\auth_setup_write_lock(); } catch (RuntimeException $error) {
        $refused = !str_contains($error->getMessage(), $root);
    }
    setup_lock_assert($refused && file_get_contents($root . '/cache') === 'owned fixture obstruction', 'Storage obstruction was overwritten or exposed through an error.');
    $security = (string) file_get_contents(dirname(__DIR__) . '/app/security.php');
    setup_lock_assert(str_contains($security, '\Gallery\Services\auth_setup_write_lock();')
        && str_contains($security, 'return \Gallery\Services\auth_setup_is_locked();'), 'Compatibility facade stopped delegating to the setup service.');
    echo "Setup lock service checks passed.\n";
} finally {
    foreach ($files as $file) { if (is_file($root . $file)) { unlink($root . $file); } }
    if (is_file($root . '/cache')) { unlink($root . '/cache'); }
    if (is_dir($root . '/cache')) { rmdir($root . '/cache'); }
    foreach (array_reverse($directories) as $directory) { if (is_dir($root . $directory)) { rmdir($root . $directory); } }
}
