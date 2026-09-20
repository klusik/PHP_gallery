<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Kill owned fixture workers around commit checkpoints and inspect recovered files and rows.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/gallery_image_move_crash_test.php
 * Module Type: Integration Test
 * Purpose: Kill owned workers before/after the ownership commit and reconcile real fixture files/rows.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/gallery_workflow_http.php';

use GalleryWorkflow\Http;
use function GalleryWorkflow\check;
use function GalleryWorkflow\envelope;
use function GalleryWorkflow\fixtureDatabase;
use function GalleryWorkflow\row;
use function GalleryWorkflow\validateFixture;

if (!getenv('GALLERY_WORKFLOW_FIXTURE')) {
    $required = getenv('GALLERY_WORKFLOW_REQUIRED') === '1';
    echo ($required ? 'BLOCKED' : 'SKIP') . " image move crash recovery requires disposable MySQL/HTTP runner\n";
    exit($required ? 1 : 0);
}

/**
 * Start an owned fixture worker and read only its bounded checkpoint/result line.
 *
 * @param array<int,string> $arguments Worker mode and numeric fixture scope.
 * @param bool $terminate Whether to kill at the announced checkpoint.
 * @return array<string,mixed> Safe checkpoint or recovery result.
 */
function imageMoveCrashWorker(array $arguments, bool $terminate): array
{
    $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), (string) getenv('GALLERY_WORKFLOW_TOKEN'));
    $resultToken = bin2hex(random_bytes(12));
    $resultPath = $directory . '/move-worker-result-' . $resultToken . '.json';
    array_splice($arguments, 1, 0, [$resultToken]);
    $process = proc_open(array_merge([PHP_BINARY, __DIR__ . '/support/gallery_image_move_worker.php'], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
    check(is_resource($process), 'Could not start owned image move worker.');
    fclose($pipes[0]);
    $result = null;
    $deadline = microtime(true) + 15;
    try {
        while (microtime(true) < $deadline) {
            clearstatcache(true, $resultPath);
            if (is_file($resultPath)) {
                $result = json_decode((string) file_get_contents($resultPath), true);
                if (is_array($result) && isset($result['operation_id'])) {
                    break;
                }
            }
            if (!proc_get_status($process)['running']) {
                break;
            }
            usleep(20000);
        }
        check(is_array($result) && isset($result['operation_id']), 'Worker failed to reach a bounded checkpoint/result.');
        if ($terminate) {
            check(proc_get_status($process)['running'] && proc_terminate($process, 9), 'Could not terminate owned checkpoint worker.');
        }
        return $result;
    } finally {
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if (is_file($resultPath)) {
            unlink($resultPath);
        }
    }
}

$stage = 'fixture validation';
try {
    $token = (string) getenv('GALLERY_WORKFLOW_TOKEN');
    $directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), $token);
    $pdo = fixtureDatabase($directory, $token);
    $seed = json_decode((string) file_get_contents($directory . '/seed.json'), true, 512, JSON_THROW_ON_ERROR);
    $origin = json_decode((string) file_get_contents($directory . '/endpoint.json'), true, 512, JSON_THROW_ON_ERROR)['url'];
    $admin = new Http($origin);
    $csrf = $admin->login($seed);
    $sample = $directory . '/galleries/seed/sample-1.jpg';
    foreach (['prepared', 'file_moved', 'database_committed'] as $phase) {
        $stage = $phase;
        $base = ['csrf_token' => $csrf, 'parent_id' => $seed['root_id'], 'visibility' => 'private'];
        $sourceId = (int) envelope($admin->request('/index.php?page=admin_new_gallery',
            $base + ['title' => 'Move source ' . $phase, 'folder_name' => 'move-source-' . $phase,
                'operation_key' => $admin->operationKey('/index.php?page=admin_new_gallery&panel=1')], true), 'Move source')['gallery_id'];
        $destinationId = (int) envelope($admin->request('/index.php?page=admin_new_gallery',
            $base + ['title' => 'Move destination ' . $phase, 'folder_name' => 'move-destination-' . $phase,
                'operation_key' => $admin->operationKey('/index.php?page=admin_new_gallery&panel=1')], true), 'Move destination')['gallery_id'];
        $imageIds = [];
        foreach (['first.jpg', 'second.jpg'] as $filename) {
            $uploaded = envelope($admin->request('/index.php?page=admin_upload', [
                'csrf_token' => $csrf, 'gallery_id' => $sourceId, 'upload_mode' => 'existing',
                'images[]' => new CURLFile($sample, 'image/jpeg', $filename),
                'operation_key' => $admin->operationKey('/index.php?page=admin_upload&gallery_id=' . $sourceId),
            ], true), 'Move upload');
            $imageIds[] = (int) $uploaded['image_ids'][0];
        }
        $source = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$sourceId]);
        $destination = row($pdo, 'SELECT * FROM galleries WHERE id = ?', [$destinationId]);
        $checkpoint = imageMoveCrashWorker([$phase, (string) $sourceId, (string) $destinationId, implode(',', $imageIds)], true);
        $operationId = $checkpoint['operation_id'];
        $journal = row($pdo, 'SELECT * FROM gallery_image_move_journal WHERE operation_id = ?', [$operationId]);
        $committed = $phase === 'database_committed';
        check((bool) $journal['database_committed'] === $committed, 'Journal commit marker disagrees with checkpoint.');
        $recovered = imageMoveCrashWorker(['recover', $operationId], false);
        check($recovered['state'] === ($committed ? 'finalized' : 'rolled_back'), 'Recovery chose the wrong direction.');
        check(imageMoveCrashWorker(['recover', $operationId], false) === $recovered, 'Recovery replay changed terminal result.');
        $manifest = json_decode($journal['manifest_json'], true, 512, JSON_THROW_ON_ERROR);
        foreach ($manifest['files'] as $entry) {
            $wanted = $directory . '/galleries/' . ($committed ? $entry['to'] : $entry['from']);
            $other = $directory . '/galleries/' . ($committed ? $entry['from'] : $entry['to']);
            check(is_file($wanted) && !file_exists($other)
                && hash_file('sha256', $wanted) === $entry['identity']['sha256'], 'Recovery did not preserve original/derivative bytes.');
        }
        foreach ($imageIds as $imageId) {
            check((int) row($pdo, 'SELECT gallery_id FROM images WHERE id = ?', [$imageId])['gallery_id']
                === ($committed ? $destinationId : $sourceId), 'Recovery disagrees with database image ownership.');
        }
        if ($committed) {
            $cover = row($pdo, 'SELECT cover_image_id FROM galleries WHERE id = ?', [$destinationId]);
            check(in_array((int) $cover['cover_image_id'], $imageIds, true), 'Committed destination cover was not preserved.');
            check(is_file($directory . '/galleries/' . $destination['folder_path'] . '/gallery.json'), 'Committed destination sidecar missing.');
        }
        echo 'PASS image move owned-worker kill/recovery/replay ' . $phase . "\n";
    }
} catch (Throwable $exception) {
    $detail = get_class($exception) === RuntimeException::class ? $exception->getMessage() : 'unexpected fixture failure';
    fwrite(STDERR, 'FAIL image move crash ' . $stage . ': ' . $detail . "\n");
    exit(1);
}
