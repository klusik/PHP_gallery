<?php

/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Pause owned workers around durable move stages for external termination.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/gallery_image_move_worker.php
 * Module Type: Disposable Worker
 * Purpose: Expose owned image-move checkpoints for real process-termination regression tests.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/gallery_workflow_safety.php';

use function GalleryWorkflow\validateFixture;

$directory = validateFixture((string) getenv('GALLERY_WORKFLOW_FIXTURE'), (string) getenv('GALLERY_WORKFLOW_TOKEN'));
$resultToken = (string) ($argv[2] ?? '');
if (!preg_match('/^[a-f0-9]{24}$/D', $resultToken)) {
    throw new RuntimeException('Invalid owned worker result token.');
}
$resultPath = $directory . '/move-worker-result-' . $resultToken . '.json';
chdir($directory);
require $directory . '/app/bootstrap.php';

try {
    if (($argv[1] ?? '') === 'recover') {
        $result = \Gallery\Services\gallery_image_move_recover((string) ($argv[3] ?? ''));
        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR), LOCK_EX);
        exit;
    }
    $phase = (string) ($argv[1] ?? '');
    $sourceId = (int) ($argv[3] ?? 0);
    $destinationId = (int) ($argv[4] ?? 0);
    $imageIds = array_map('intval', explode(',', (string) ($argv[5] ?? '')));
    \Gallery\Services\move_gallery_images($sourceId, $destinationId, $imageIds, [
        'checkpoint' => /**
         * Announce the selected checkpoint and stay alive until the owning test terminates this process.
         *
         * @param string $observed Current service checkpoint.
         * @param string $operationId Persisted operation identifier.
         * @param int $completed Number of files moved before this checkpoint.
         * @return void
         */
        static function (string $observed, string $operationId, int $completed) use ($phase, $resultPath): void {
            if ($observed !== $phase || ($phase === 'file_moved' && $completed !== 1)) {
                return;
            }
            file_put_contents($resultPath, json_encode(['operation_id' => $operationId, 'checkpoint' => $observed], JSON_THROW_ON_ERROR), LOCK_EX);
            // The parent kills only its own proc_open resource, bypassing service finally blocks.
            sleep(20);
            throw new RuntimeException('Owned fixture worker was not terminated at its checkpoint.');
        },
    ]);
    throw new RuntimeException('Requested move checkpoint was not reached.');
} catch (Throwable) {
    fwrite(STDERR, "Disposable image move worker failed before the expected result.\n");
    exit(1);
}
