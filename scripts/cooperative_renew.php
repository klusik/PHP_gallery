<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/cooperative_renew.php
 * Module Type: CLI Controller
 * Purpose: Renew one explicitly selected active collaboration from a system scheduler.
 * Responsibilities: Validate CLI intent, invoke bounded maintenance and print only safe status.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$arguments = array_slice($argv, 1);
$usage = "Usage: php scripts/cooperative_renew.php --group=32_HEX_GROUP_ID\n"
    . "Run on each participating installation once per minute for each active group.\n"
    . "Renews existing approval only; never accepts invitations or activates pending groups.\n"
    . "Exit codes: 0 current authorization, 1 refused/unavailable, 2 invalid arguments or waiting.\n";
if ($arguments === ['--help']) {
    echo $usage;
    exit(0);
}
if (count($arguments) !== 1 || preg_match('/\A--group=([a-f0-9]{32})\z/', $arguments[0], $matches) !== 1) {
    fwrite(STDERR, $usage);
    exit(2);
}

// Match other maintenance CLIs: do not enter browser GET auto-update hooks.
$_SERVER['REQUEST_METHOD'] = 'CLI';
try {
    require dirname(__DIR__) . '/app/bootstrap_full.php';
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $state = \Gallery\Services\cooperative_maintenance_renew($matches[1]);
    echo json_encode(['group_id' => $state['group_id'], 'sharing_active' => $state['sharing_active'],
        'authorization_expires_at' => $state['authorization_expires_at'],
        'next_action' => $state['maintenance']['action'], 'retry_at' => $state['maintenance']['retry_at'],
        'reason' => $state['maintenance']['reason']], JSON_THROW_ON_ERROR) . PHP_EOL;
    exit($state['sharing_active'] ? 0 : 2);
} catch (Throwable $error) {
    $reason = $error instanceof \Gallery\Services\CooperativeException ? $error->reason : 'renewal_unavailable';
    fwrite(STDERR, 'Cooperative renewal refused (' . $reason . ").\n");
    exit(1);
}
