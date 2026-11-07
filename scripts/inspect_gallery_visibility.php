<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/inspect_gallery_visibility.php
 * Module Type: CLI Controller
 * Purpose: Inspect one retained visibility marker or reconcile an exact review.
 * Responsibilities: Validate the operator's gallery and reviewed fingerprint.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/cli_guard.php';
gallery_require_cli_sapi();

$arguments = array_slice($argv, 1);
if ($arguments === ['--help']) {
    echo "Usage: php scripts/inspect_gallery_visibility.php --gallery=ID [--reconcile=FINGERPRINT]\n";
    echo "Inspect first. Reconciliation only synchronizes the exact reviewed sidecar to the current database.\n";
    echo "Normal bootstrap may update updater cache and runtime lease artifacts.\n";
    exit(0);
}
$reviewed = null;
if (count($arguments) === 2
    && preg_match('/\A--reconcile=([a-f0-9]{64})\z/D', $arguments[1], $review) === 1) {
    $reviewed = $review[1];
    array_pop($arguments);
}
if (count($arguments) !== 1
    || preg_match('/^--gallery=([1-9][0-9]{0,18})$/D', $arguments[0], $match) !== 1
    || ($galleryId = filter_var(
        $match[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]],
    )) === false) {
    fwrite(STDERR, "Provide exactly one positive gallery ID using --gallery=ID.\n");
    exit(2);
}
try {
    require dirname(__DIR__) . '/app/bootstrap_full.php';
    require_once dirname(__DIR__) . '/app/models/admin_operation_keys.php';
    require_once dirname(__DIR__) . '/app/services/app_linking_schema.php';
    require_once dirname(__DIR__) . '/app/services/app_linking.php';
    require_once dirname(__DIR__) . '/app/services/app_linking_create.php';
    require_once dirname(__DIR__) . '/app/services/gallery_visibility_inspection.php';
    $result = \Gallery\Services\gallery_visibility_inspect($galleryId, $reviewed);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable) {
    fwrite(STDERR, "Visibility operation unconfirmed. Inspect the current evidence before taking further action.\n");
    exit(1);
}
