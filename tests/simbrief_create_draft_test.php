<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/simbrief_create_draft_test.php
 * Module Type: Regression Test
 * Purpose: Verify private pre-create SimBrief draft identity and expiry.
 * Responsibilities: Check owner, session, token, and expiry boundaries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/services/simbrief_description_drafts.php';

/**
 * Require one expected draft behavior.
 *
 * @param bool $condition Expected condition.
 * @param string $message Failure explanation.
 * @return void Throw when the condition fails.
 */
function simbrief_draft_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Require a draft refusal without exposing its payload.
 *
 * @param int $userId Requested administrator ID.
 * @param string $token Opaque draft reference.
 * @return void Throw if the draft is accessible.
 */
function simbrief_draft_refused(int $userId, string $token): void
{
    try {
        \Gallery\Services\simbrief_description_draft_read($userId, $token);
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException('Draft access should have been refused.');
}

session_save_path(sys_get_temp_dir());
session_id('gallery-draft-test-' . bin2hex(random_bytes(8)));
session_start();
$originalSessionId = session_id();
$token = \Gallery\Services\simbrief_description_draft_create(11, ['flight' => 'private'], ['kind' => 'userid'], ['route' => 'ABCD']);
$path = \Gallery\Services\simbrief_description_draft_directory() . '/' . $token . '.json';
try {
    simbrief_draft_assert(is_file($path), 'Private draft was not written.');
    simbrief_draft_assert(is_file(dirname(__DIR__) . '/cache/.htaccess'), 'Cache access protection is missing.');
    $draft = \Gallery\Services\simbrief_description_draft_read(11, $token);
    simbrief_draft_assert(($draft['payload']['flight'] ?? '') === 'private', 'Owner could not read original OFP.');
    simbrief_draft_refused(12, $token);
    simbrief_draft_refused(11, '../bad');

    session_write_close();
    session_id('gallery-draft-other-' . bin2hex(random_bytes(8)));
    session_start();
    simbrief_draft_refused(11, $token);
    session_destroy();
    session_id($originalSessionId);
    session_start();

    $document = json_decode((string) file_get_contents($path), true);
    simbrief_draft_assert(is_array($document), 'Draft test fixture could not be decoded.');
    $document['expires_at'] = time() - 1;
    file_put_contents($path, json_encode($document), LOCK_EX);
    simbrief_draft_refused(11, $token);
    echo "SimBrief create draft: PASS\n";
} finally {
    if (is_file($path)) {
        unlink($path);
    }
    session_destroy();
}
