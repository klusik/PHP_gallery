<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/updater_inventory_compatibility_test.php
 * Module Type: Updater Compatibility Regression Test
 * Purpose: Validate incoming releases with frozen pre-WinApp and companion-aware installed readers.
 * Responsibilities:
 *   - Reproduce both reported inventory rejections
 *   - Prove additive companion/resource compatibility with retained updater scope
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/release_file_policy.php';
require_once __DIR__ . '/fixtures/updater_inventory_before_winapp.php';
require_once __DIR__ . '/fixtures/updater_inventory_before_resources.php';

use function Gallery\Core\release_file_policy_read;
use function Gallery\Core\release_file_policy_archive_updater_paths;
use function Gallery\Core\release_file_policy_paths;
use function Gallery\Core\release_file_policy_validate_path_list;
use function Gallery\Tests\CompanionInstalledPolicy\{
    release_file_policy_archive_updater_paths as companion_installed_archive_paths,
    release_file_policy_read as companion_installed_policy_read
};
use function Gallery\Tests\LegacyUpdatePolicy\release_file_policy_archive_updater_paths as installed_archive_paths
;
use function Gallery\Tests\LegacyUpdatePolicy\release_file_policy_paths as installed_policy_paths
;
use function Gallery\Tests\LegacyUpdatePolicy\release_file_policy_read as installed_policy_read
;

/**
 * Require a compatibility postcondition without depending on PHP assertion settings.
 *
 * @param bool $condition Compatibility condition that must hold.
 * @param string $message Explanation of the failed condition.
 * @return void Throws when the condition does not hold.
 */
function updater_inventory_compatibility_assert(
    bool $condition,
    string $message,
): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Apply the published 1.5.7 resource check before the frozen updater fixture.
 *
 * @param string $root Directory containing the test-owned incoming archive.
 * @return array{files:list<string>,legacy:bool} The installed activation scope.
 * @throws RuntimeException When the published resource allowlist refuses a path.
 */
function updater_inventory_compatibility_resource_aware_paths(string $root): array
{
    $manifest = json_decode(
        (string) file_get_contents($root . '/app/production-files.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    // Preserve the field and literal allowlist shipped in v_1.5.7. That reader
    // ignores extensions it does not know and rejects surprises in this list.
    if (
        !is_array($manifest)
        || (
            array_key_exists('companion_resource_files', $manifest)
            && !is_array($manifest['companion_resource_files'])
        )
    ) {
        throw new RuntimeException('Invalid installed resource field.');
    }
    foreach (release_file_policy_validate_path_list(
        $manifest['companion_resource_files'] ?? [],
    ) as $path) {
        if (!in_array($path, [
            'winapp/uploader/resources/DejaVuSans.ttf',
            'winapp/uploader/resources/LICENSE_DEJAVU',
        ], true)) {
            throw new RuntimeException('Installed resource allowlist rejected the path.');
        }
    }
    return companion_installed_archive_paths($root);
}

/**
 * Remove an owned temporary fixture without following directory links.
 *
 * @param string $path Absolute test-owned directory or file path.
 * @return void Removes the fixture after the test completes.
 */
function updater_inventory_compatibility_cleanup(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
    } elseif (is_dir($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                updater_inventory_compatibility_cleanup(
                    $path . DIRECTORY_SEPARATOR . $entry,
                );
            }
        }
        rmdir($path);
    }
}

$root = dirname(__DIR__);
$inventory = release_file_policy_read($root);
updater_inventory_compatibility_assert(
    $inventory['companion_files'] !== [],
    'The release lost its reviewed companion membership.',
);
updater_inventory_compatibility_assert(
    installed_policy_read($root)['updater_files']
        === $inventory['updater_files'],
    'The installed reader refused the real incoming release inventory or changed its activation set.',
);
updater_inventory_compatibility_assert(
    installed_policy_paths($root, 'updater') === $inventory['updater_files'],
    'The incoming release is missing a path required by the installed updater.',
);
updater_inventory_compatibility_assert(
    companion_installed_policy_read($root)['updater_files']
        === $inventory['updater_files'],
    'The installed 0.135.1 reader rejected the real incoming inventory.',
);
$resources = [
    'winapp/uploader/resources/DejaVuSans.ttf',
    'winapp/uploader/resources/LICENSE_DEJAVU',
];
updater_inventory_compatibility_assert(
    $inventory['companion_resource_files'] === $resources,
    'The real generated inventory did not isolate the new font and license in its additive field.',
);
$locales = [
    'winapp/uploader/resources/locale/cs/LC_MESSAGES/winapp.po',
    'winapp/uploader/resources/locale/de/LC_MESSAGES/winapp.po',
    'winapp/uploader/resources/locale/sv/LC_MESSAGES/winapp.po',
];
updater_inventory_compatibility_assert(
    $inventory['companion_locale_files'] === $locales
        && array_intersect($locales, $inventory['updater_files']) === [],
    'The real package must isolate all WinApp catalogs from CMS activation.',
);

$fixture =
    sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'php-gallery-old-updater-'
    . bin2hex(random_bytes(5));
try {
    // The running checkout also contains generated scanner/test artifacts.
    // Only an isolated inventory snapshot models the actual incoming package.
    $incomingRoot = $fixture . '/incoming';
    foreach (release_file_policy_paths($root) as $path) {
        $destination = $incomingRoot . '/' . $path;
        if (
            !is_dir(dirname($destination))
            && !mkdir(dirname($destination), 0777, true)
        ) {
            throw new RuntimeException(
                'Could not create incoming package fixture.',
            );
        }
        if (!copy($root . '/' . $path, $destination)) {
            throw new RuntimeException(
                'Could not copy incoming package fixture.',
            );
        }
    }
    updater_inventory_compatibility_assert(
        companion_installed_archive_paths($incomingRoot)['files']
            === $inventory['updater_files'],
        'The real incoming package changed installed activation ownership.',
    );
    updater_inventory_compatibility_assert(
        updater_inventory_compatibility_resource_aware_paths($incomingRoot)['files']
            === $inventory['updater_files'],
        'The 1.5.7 resource contract refused the packaged WinApp catalogs.',
    );
    $incomingSidecar = $incomingRoot . '/app/production-files.json';
    $incomingBytes = (string) file_get_contents($incomingSidecar);
    $misplaced = $inventory;
    $misplaced['companion_resource_files'] = array_merge($resources, $locales);
    unset($misplaced['companion_locale_files']);
    file_put_contents($incomingSidecar, json_encode($misplaced, JSON_THROW_ON_ERROR));
    try {
        $refused = false;
        try {
            updater_inventory_compatibility_resource_aware_paths($incomingRoot);
        } catch (RuntimeException $exception) {
            $refused = $exception->getMessage()
                === 'Installed resource allowlist rejected the path.';
        }
        updater_inventory_compatibility_assert(
            $refused,
            'The compatibility fixture must detect catalogs in the old resource field.',
        );
    } finally {
        file_put_contents($incomingSidecar, $incomingBytes);
    }
    $unlisted = $incomingRoot . '/app/unlisted-compatibility.php';
    file_put_contents($unlisted, "unlisted fixture\n");
    $refused = false;
    try {
        companion_installed_archive_paths($incomingRoot);
    } catch (RuntimeException $exception) {
        $refused =
            $exception->getMessage()
            === 'Update archive contains unexpected updater-owned files.';
    }
    updater_inventory_compatibility_assert(
        $refused,
        'The installed reader accepted an unlisted file in the real package.',
    );
    unlink($unlisted);

    $production = [
        'app/production-files.json',
        'app/runtime.php',
        'winapp/.htaccess',
    ];
    $companion = ['winapp/uploader/media.py'];
    $payload = [
        'schema_version' => 1,
        'production_files' => $production,
        'companion_files' => $companion,
        'updater_files' => $production,
        'source_review_files' => [],
    ];
    foreach (array_merge($production, $companion) as $path) {
        $absolute = $fixture . '/' . $path;
        if (
            !is_dir(dirname($absolute))
            && !mkdir(dirname($absolute), 0777, true)
        ) {
            throw new RuntimeException(
                'Could not create an extracted-source compatibility fixture.',
            );
        }
        file_put_contents($absolute, "fixture\n");
    }
    $sidecar = $fixture . '/app/production-files.json';
    // Preserve the exact failed shape as a control, before testing the compatible shape.
    $broken = $payload;
    $broken['production_files'] = array_merge($production, $companion);
    sort($broken['production_files'], SORT_STRING);
    unset($broken['companion_files']);
    file_put_contents($sidecar, json_encode($broken, JSON_THROW_ON_ERROR));
    $reference = null;
    try {
        installed_archive_paths($fixture);
    } catch (RuntimeException $exception) {
        $reference = strtoupper(substr(
            hash('sha256', $exception->getMessage()),
            0,
            12,
        ));
    }
    updater_inventory_compatibility_assert(
        $reference === '385BCD5058D6',
        'The frozen installed validator did not reproduce the reported package_validate failure.',
    );

    file_put_contents($sidecar, json_encode($payload, JSON_THROW_ON_ERROR));
    $expected = ['files' => $production, 'legacy' => false];
    updater_inventory_compatibility_assert(
        installed_archive_paths($fixture) === $expected,
        'The installed updater refused the additive inventory or activated companion sources.',
    );
    updater_inventory_compatibility_assert(
        release_file_policy_archive_updater_paths($fixture) === $expected,
        'Current and installed updater activation scopes disagree.',
    );

    foreach ($resources as $path) {
        $absolute = $fixture . '/' . $path;
        if (
            !is_dir(dirname($absolute))
            && !mkdir(dirname($absolute), 0777, true)
        ) {
            throw new RuntimeException(
                'Could not create the companion resource fixture.',
            );
        }
        file_put_contents($absolute, "resource fixture\n");
    }
    $brokenResources = $payload;
    $brokenResources['companion_files'] = array_merge($companion, $resources);
    sort($brokenResources['companion_files'], SORT_STRING);
    file_put_contents($sidecar, json_encode(
        $brokenResources,
        JSON_THROW_ON_ERROR,
    ));
    $reference = null;
    try {
        companion_installed_archive_paths($fixture);
    } catch (RuntimeException $exception) {
        $reference = strtoupper(substr(
            hash('sha256', $exception->getMessage()),
            0,
            12,
        ));
    }
    updater_inventory_compatibility_assert(
        $reference === 'C35BC3B622B0',
        'The actual 0.135.1 reader did not reproduce the reported resource rejection.',
    );
    updater_inventory_compatibility_assert(
        release_file_policy_archive_updater_paths($fixture) === $expected,
        'Current readers must retain the published 0.136 inventory shape.',
    );

    $payload['companion_resource_files'] = $resources;
    file_put_contents($sidecar, json_encode($payload, JSON_THROW_ON_ERROR));
    foreach ([
        installed_archive_paths(...),
        companion_installed_archive_paths(...),
        release_file_policy_archive_updater_paths(...),
    ] as $reader) {
        updater_inventory_compatibility_assert(
            $reader($fixture) === $expected,
            'An additive resource field changed or blocked the installed CMS activation set.',
        );
    }
    $allPackagePaths = array_merge($production, $companion, $resources);
    sort($allPackagePaths, SORT_STRING);
    updater_inventory_compatibility_assert(
        release_file_policy_paths($fixture) === $allPackagePaths,
        'Production distribution lost the font or license during compatibility.',
    );

    file_put_contents($fixture . '/app/unlisted.php', "unlisted\n");
    $refused = false;
    try {
        installed_archive_paths($fixture);
    } catch (RuntimeException $exception) {
        $refused =
            $exception->getMessage()
            === 'Update archive contains unexpected updater-owned files.';
    }
    updater_inventory_compatibility_assert(
        $refused,
        'Compatibility weakened the installed updater archive allowlist.',
    );

    echo "Installed updater inventory compatibility checks passed.\n";
} finally {
    updater_inventory_compatibility_cleanup($fixture);
}
