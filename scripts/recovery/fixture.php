<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Own disposable recovery data without starting a restored application.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: scripts/recovery/fixture.php
 * Module Type: Synthetic Recovery Drill
 *
 * Purpose:
 *   Rehearse file recovery using fresh synthetic fixtures without starting the application.
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
 *   - Never bootstrap a restored installation or read installation credentials.
 *   - Keep comments and docstrings intact when modifying this file.
 */

declare(strict_types=1);

namespace Gallery\Recovery;

require_once __DIR__ . '/validation.php';

/** Write only synthetic bytes into a freshly created fixture directory. */
function fixture_file(string $root, string $relative, string $bytes): void
{
    $relative = relative_path($relative);
    $directory = dirname($root . '/' . $relative);
    if (!is_dir($directory)) {
        demand(mkdir($directory, 0700, true), 'fixture_directory_failed');
    }
    $handle = fopen($root . '/' . $relative, 'xb');
    demand($handle !== false, 'fixture_file_failed');
    try {
        demand(fwrite($handle, $bytes) === strlen($bytes), 'fixture_write_failed');
    } finally {
        fclose($handle);
    }
}

/** This synthetic catalog is not an exported database and contains no credentials. */
function fixture_catalog(): array
{
    return [
        'galleries' => [['id' => 1, 'parent_id' => null], ['id' => 2, 'parent_id' => 1]],
        'images' => [['id' => 1, 'gallery_id' => 1], ['id' => 2, 'gallery_id' => 2]],
        'trash_entries' => 1,
    ];
}

/** Check relationships in the synthetic catalog; this is not database validation. */
function fixture_relationships_valid(array $catalog): bool
{
    $ids = array_column($catalog['galleries'], 'id');
    foreach ($catalog['galleries'] as $gallery) {
        if ($gallery['parent_id'] !== null && !in_array($gallery['parent_id'], $ids, true)) {
            return false;
        }
    }
    foreach ($catalog['images'] as $image) {
        if (!in_array($image['gallery_id'], $ids, true)) {
            return false;
        }
    }
    return true;
}

/**
 * Repeatable file-copy restore rehearsal. It deliberately leaves real database,
 * authentication, HTTP protection, migrations and application Trash checks pending.
 */
function drill(string $work): array
{
    $started = microtime(true);
    $parent = external_directory(dirname($work));
    $name = relative_path(basename($work));
    demand(!str_contains($name, '/'), 'invalid_fixture_name');
    $work = $parent . '/' . $name;
    demand(!file_exists($work) && !is_link($work), 'fixture_exists');
    demand(mkdir($work, 0700), 'fixture_directory_failed');

    $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jSioAAAAASUVORK5CYII=', true);
    demand(is_string($pixel), 'fixture_image_failed');
    $catalog = fixture_catalog();
    $files = [
        'app/bootstrap.php' => "<?php\nthrow new RuntimeException('Synthetic fixture must never execute.');\n",
        'index.php' => "<?php\n// Synthetic recovery fixture, not a runnable installation.\n",
        'galleries/one/one.png' => $pixel,
        'galleries/one/two/two.png' => $pixel,
        'data/gallery-trash/fixture/payload/three.png' => $pixel,
        'synthetic-catalog.json' => json_text($catalog),
    ];
    $manifest = ['version' => 'fixture', 'algorithm' => 'sha256', 'hash_mode' => 'normalized-text-sha256', 'files' => []];
    foreach (['app/bootstrap.php', 'index.php'] as $path) {
        $manifest['files'][$path] = 'sha256:' . hash('sha256', $files[$path]);
    }
    $files['app/core-manifest.json'] = json_text($manifest);
    foreach ($files as $path => $bytes) {
        fixture_file($work . '/backup', $path, $bytes);
    }

    $set = set_template();
    $set['set_id'] = 'synthetic-fixture';
    $set['backup'] = ['method' => 'maintenance_window', 'consistent' => true, 'off_host' => true, 'retention_days' => 1, 'previous_restore_verified' => false];
    $set['targets'] = ['rto_seconds' => 60, 'rpo_seconds' => 3600];
    foreach (COMPONENTS as $component) {
        $set['components'][$component] = ['state' => 'present', 'snapshot_id' => $set['set_id'], 'receipt_sha256' => hash('sha256', 'synthetic-only-' . $component)];
    }
    $set['application'] = ['version' => 'fixture', 'manifest_sha256' => hash('sha256', $files['app/core-manifest.json'])];
    $set['counts'] = ['images' => 2, 'galleries' => 2, 'trash_entries' => 1];
    $set['originals'] = [
        ['path' => 'galleries/one/one.png', 'sha256' => hash('sha256', $pixel)],
        ['path' => 'galleries/one/two/two.png', 'sha256' => hash('sha256', $pixel)],
    ];
    write_json($work . '/set.json', $set);
    $reports = [];
    foreach (['restored', 'damaged'] as $variant) {
        $root = $work . '/' . $variant;
        demand(mkdir($root, 0700), 'fixture_directory_failed');
        foreach (array_keys($files) as $path) {
            if ($variant === 'damaged' && $path === 'galleries/one/two/two.png') {
                continue;
            }
            $bytes = $variant === 'damaged' && $path === 'galleries/one/one.png'
                ? 'intentionally corrupted synthetic image' : (string) file_get_contents(contained_file($work . '/backup', $path));
            fixture_file($root, $path, $bytes);
        }
        $marker = isolation_template($set);
        // These flags describe an inert synthetic tree; no application is started.
        $marker['controls'] = array_fill_keys(CONTROLS, true);
        write_json($root . '/.recovery-isolated.json', $marker);
        $reports[$variant] = validate($set, $root, null, time());
        // Mark all nested evidence explicitly synthetic too, never off-host proof.
        $reports[$variant]['coverage'] = 'fixture_files_only';
        $reports[$variant]['recovery_readiness'] = 'not_proven';
        write_json($work . '/' . $variant . '-evidence.json', $reports[$variant]);
    }

    $restoredCatalog = read_json($work . '/restored/synthetic-catalog.json');
    $brokenCatalog = $restoredCatalog;
    $brokenCatalog['images'][0]['gallery_id'] = 999;
    $trashSource = contained_file($work . '/restored', 'data/gallery-trash/fixture/payload/three.png');
    demand(mkdir($work . '/restored/galleries/recovered', 0700), 'fixture_directory_failed');
    $trashTarget = $work . '/restored/galleries/recovered/three.png';
    demand(rename($trashSource, $trashTarget), 'fixture_trash_move_failed');
    $assertions = [
        'restored_files_match' => $reports['restored']['status'] === 'INCOMPLETE'
            && !in_array('FAIL', array_column($reports['restored']['checks'], 'status'), true)
            && $reports['restored']['originals_hashed'] === 2,
        'corruption_and_omission_detected' => $reports['damaged']['status'] === 'FAIL' && $reports['damaged']['failed_original_ordinals'] === [1, 2],
        'catalog_round_trip' => $restoredCatalog === $catalog && fixture_relationships_valid($restoredCatalog),
        'catalog_orphan_detected' => !fixture_relationships_valid($brokenCatalog),
        'synthetic_trash_bytes_restored' => !file_exists($trashSource) && hash_file('sha256', $trashTarget) === hash('sha256', $pixel),
        'real_operator_checks_pending' => $reports['restored']['recovery_readiness'] === 'not_proven',
    ];
    $report = [
        'schema' => 1, 'command' => 'drill', 'status' => in_array(false, $assertions, true) ? 'FAIL' : 'PASS',
        'coverage' => 'fixture_only', 'recovery_readiness' => 'not_proven',
        'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'), 'duration_seconds' => round(microtime(true) - $started, 3),
        'set_sha256' => fingerprint($set), 'assertions' => $assertions,
        'limitations' => ['no_database_restore', 'no_application_started', 'no_http_or_login_checks', 'no_off_host_transfer', 'synthetic_trash_move_only'],
    ];
    write_json($work . '/drill-evidence.json', $report);
    return $report;
}
