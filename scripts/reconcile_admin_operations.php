<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/reconcile_admin_operations.php
 * Module Type: CLI Controller
 * Purpose: Inspect retained operation keys and explicitly attach independently verified results.
 * Responsibilities:
 *   - Normalize CLI input, bound evidence reads and require explicit apply before ledger completion.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: Never deletes claims, expires keys, or executes create/upload work.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arguments = array_slice($argv, 1);
$usage = "Usage:\n"
    . "  php scripts/reconcile_admin_operations.php --list [--after=ACTOR:KEY_HASH]\n"
    . "  php scripts/reconcile_admin_operations.php --inspect=ACTOR:KEY_HASH\n"
    . "  php scripts/reconcile_admin_operations.php --complete=EVIDENCE_JSON [--apply]\n"
    . "Completion defaults to dry-run. Apply requires independently verified original effects.\n"
    . "No command deletes claims, expires keys, retries target work, or repairs files.\n";
if ($arguments === ['--help']) {
    echo $usage;
    exit(0);
}
$mode = '';
$cursor = '';
$identity = [];
$evidencePath = '';
$apply = false;
if (($arguments[0] ?? '') === '--list' && count($arguments) <= 2) {
    $mode = 'list';
    if (isset($arguments[1])) {
        if (!str_starts_with($arguments[1], '--after=')) {
            fwrite(STDERR, $usage);
            exit(2);
        }
        $cursor = substr($arguments[1], strlen('--after='));
    }
} elseif (count($arguments) === 1 && preg_match('/^--inspect=([1-9][0-9]{0,18}):([a-f0-9]{64})$/D', $arguments[0], $identity)) {
    $mode = 'inspect';
} elseif (count($arguments) >= 1 && count($arguments) <= 2 && str_starts_with($arguments[0], '--complete=')
    && (!isset($arguments[1]) || $arguments[1] === '--apply')) {
    $mode = 'complete';
    $evidencePath = substr($arguments[0], strlen('--complete='));
    $apply = isset($arguments[1]);
}
if ($mode === '' || ($mode === 'complete' && $evidencePath === '')) {
    fwrite(STDERR, $usage);
    exit(2);
}

try {
    require dirname(__DIR__) . '/app/bootstrap.php';
    if ($mode === 'list') {
        $result = \Gallery\Services\admin_operation_pending_report($cursor);
    } elseif ($mode === 'inspect') {
        $result = \Gallery\Services\admin_operation_inspect((int) $identity[1], $identity[2]);
    } else {
        if (!is_file($evidencePath) || !is_readable($evidencePath)) {
            throw new RuntimeException('Evidence file unavailable.');
        }
        $stream = @fopen($evidencePath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Evidence file unavailable.');
        }
        try {
            $json = @stream_get_contents($stream, \Gallery\Core\ADMIN_OPERATION_INPUT_MAX_BYTES + 1);
        } finally {
            fclose($stream);
        }
        $evidence = \Gallery\Services\admin_operation_reconciliation_document(is_string($json) ? $json : '');
        $response = \Gallery\Services\admin_operation_reconcile_result($evidence['actor_id'], $evidence['key_hash'], $evidence['payload_hash'], $evidence['response'], $apply);
        $result = ['applied' => $apply, 'state' => $apply ? 'completed' : 'validated_only',
            'gallery_id' => (int) $response['gallery_id'], 'image_ids' => $response['image_ids'] ?? [],
            'note' => 'No target work was executed; no retained claim was deleted.'];
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    $reason = $exception instanceof \Gallery\Services\AdminOperationRefusal ? $exception->reason : 'operation_storage_unavailable';
    fwrite(STDERR, 'Admin operation inspection/reconciliation refused (' . $reason . "). Inspect retained state; do not delete the claim or repeat target work.\n");
    exit(1);
}
