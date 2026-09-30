<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_metadata_test.php
 * Module Type: Regression Test
 * Purpose: Verify minimal public metadata export under a fresh exact cooperative grant.
 * Responsibilities: Cover credential isolation, source restrictions, expiry and HTTP refusal boundaries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
require_once __DIR__ . '/support/cooperative_exchange_fixture.php';
require_once dirname(__DIR__) . '/app/controllers/cooperative_metadata.php';
use Gallery\Services as S;

$id = exchange_approved_fixture();
$a = $GLOBALS['exchange_nodes']['a']['id'];
$b = $GLOBALS['exchange_nodes']['b']['id'];
$album = $GLOBALS['exchange_nodes']['a']['member']['album_id'];
exchange_node('b');
$token = S\cooperative_peer_outbound_credential($a);
exchange_node('a');
$message = ['protocol' => 1, 'sender_id' => $b, 'recipient_id' => $a, 'group_id' => $id, 'album_id' => $album, 'revision' => 1];
exchange_refuses(/** Pending approvals do not authorize an export. @return array<string,mixed> Refused metadata. */ static fn() => S\cooperative_metadata_export($message, $token), 'metadata_unauthorized');
$state = exchange_state($id);
for ($step = 0; $step < 4; $step++) { $state = S\cooperative_maintenance_step($id, $state['revision']); }
exchange_check($state['sharing_active'], 'Fixture failed to activate.');
$db = $GLOBALS['exchange_nodes']['a']['db'];
$db->exec('ALTER TABLE galleries ADD COLUMN password_hash TEXT');
$db->exec('ALTER TABLE galleries ADD COLUMN folder_path TEXT');
$db->exec("UPDATE galleries SET password_hash = 'must-not-export', folder_path = '/private/storage/path' WHERE id = 1");
$changes = (int) $db->query('SELECT total_changes()')->fetchColumn();
$result = S\cooperative_metadata_export($message, $token);
exchange_check(array_keys($result) === ['ok', 'protocol', 'sender_id', 'recipient_id', 'group_id', 'revision', 'album', 'authorization_expires_at'], 'Unexpected response fields.');
exchange_check($result['album'] === ['album_id' => $album, 'title' => 'Trip'] && $result['sender_id'] === $a
    && $result['recipient_id'] === $b && $result['authorization_expires_at'] === $state['authorization_expires_at'], 'Metadata lost source or grant binding.');
exchange_check((int) $db->query('SELECT total_changes()')->fetchColumn() === $changes, 'Metadata read mutated storage.');
exchange_check(!str_contains(json_encode($result), 'must-not-export') && !str_contains(json_encode($result), '/private/'), 'Raw storage fields leaked.');
foreach (['sender_id' => $GLOBALS['exchange_nodes']['d']['id'], 'recipient_id' => $GLOBALS['exchange_nodes']['c']['id'],
    'album_id' => S\cooperative_id_generate(), 'group_id' => S\cooperative_id_generate(), 'revision' => 2] as $key => $value) {
    $changed = array_replace($message, [$key => $value]);
    exchange_refuses(/** Request correlation is exact, not a grant hint. @return array<string,mixed> Refused metadata. */ static fn() => S\cooperative_metadata_export($changed, $token), 'metadata_unauthorized');
}
exchange_refuses(/** The Admin session cannot replace a directed credential. @return array<string,mixed> Refused metadata. */ static fn() => S\cooperative_metadata_export($message, ''), 'metadata_unauthorized');
foreach ([$message + ['url' => 'https://untrusted.example'], array_replace($message, ['revision' => '1']), array_replace($message, ['protocol' => 2])] as $changed) {
    exchange_refuses(/** Strict protocol fields prevent forwarded scope or target injection. @return array<string,mixed> Refused metadata. */ static fn() => S\cooperative_metadata_export($changed, $token), 'invalid_message');
}
foreach ([['visibility', 'private', 'public'], ['access_mode', 'password', 'normal'], ['access_listing', 'unlisted', 'listed'], ['nsfw_enabled', '1', '0']] as [$column, $restricted, $normal]) {
    $db->prepare('UPDATE galleries SET ' . $column . ' = ? WHERE id = 1')->execute([$restricted]);
    exchange_refuses(/** Protected local sources stay private despite stored approval. @return array<string,mixed> Refused metadata. */ static fn() => S\cooperative_metadata_export($message, $token), 'metadata_unauthorized');
    $db->prepare('UPDATE galleries SET ' . $column . ' = ? WHERE id = 1')->execute([$normal]);
}
$db->prepare('UPDATE galleries SET title = ? WHERE id = 1')->execute([str_repeat('ž', 700)]);
exchange_check(S\cooperative_metadata_export($message, $token)['album']['title'] === str_repeat('ž', 512), 'Unicode truncation split or exceeded the title bound.');
$db->prepare('UPDATE galleries SET title = ? WHERE id = 1')->execute(["\xff"]);
exchange_refuses(/** Invalid UTF-8 cannot become an unbounded JSON failure. @return array<string,mixed> Refused metadata. */ static fn() => S\cooperative_metadata_export($message, $token), 'metadata_unavailable');
$db->exec("UPDATE galleries SET title = 'Trip' WHERE id = 1");
$stored = S\cooperative_proposal_exchange_load($id);
$group = $stored['group'];
$group['exchange']['lease']['started_at'] = time() - S\COOPERATIVE_VERIFICATION_TTL - 1;
$group['exchange']['lease']['expires_at'] = $group['exchange']['lease']['started_at'] + S\COOPERATIVE_VERIFICATION_TTL;
S\cooperative_proposal_exchange_save($stored, $group);
exchange_refuses(/** Old membership cannot extend the export lease. @return array<string,mixed> Refused metadata. */ static fn() => S\cooperative_metadata_export($message, $token), 'metadata_unauthorized');
S\cooperative_maintenance_renew($id);
S\schema_inspection_set_query_executor_for_tests(/** Unknown schema never permits export. @return never Fixture observation failure. */ static fn() => throw new RuntimeException('private database diagnostic'));
exchange_refuses(/** Unknown schema is fail-closed and redacted. @return array<string,mixed> Refused metadata. */ static fn() => S\cooperative_metadata_export($message, $token), 'metadata_unauthorized');
S\schema_inspection_set_query_executor_for_tests(/** Restore available fixture schema. @return bool Available objects. */ static fn(): bool => true);
S\cooperative_peer_revoke($b);
exchange_refuses(/** Revocation immediately closes the export. @return array<string,mixed> Refused metadata. */ static fn() => S\cooperative_metadata_export($message, $token), 'metadata_unauthorized');
$GLOBALS['exchange_enabled'] = false;
$before = $GLOBALS['exchange_db_calls'];
exchange_refuses(/** Feature OFF precedes optional storage reads. @return array<string,mixed> Refused metadata. */ static fn() => S\cooperative_metadata_export($message, $token), 'feature_disabled');
exchange_check($GLOBALS['exchange_db_calls'] === $before, 'Disabled export touched storage.');

// Actual HTTP controller ignores browser sessions and query/form/cookie tokens.
$_SERVER['REQUEST_METHOD'] = 'GET';
$result = exchange_http('Gallery\\Controllers\\cms_cooperative_metadata_api');
exchange_check(!$result['ok'] && http_response_code() === 405, 'GET metadata accepted.');
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = $_POST = $_COOKIE = ['token' => $token];
$result = exchange_http('Gallery\\Controllers\\cms_cooperative_metadata_api');
exchange_check(!$result['ok'] && http_response_code() === 403 && $GLOBALS['exchange_db_calls'] === $before, 'Non-header credentials authorized a read.');
$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
$_SERVER['CONTENT_TYPE'] = 'text/plain';
$result = exchange_http('Gallery\\Controllers\\cms_cooperative_metadata_api');
exchange_check(!$result['ok'] && http_response_code() === 400 && $result['error_code'] === 'json_required', 'Endpoint accepted unsupported content type.');
echo "PASS cooperative metadata export\n";
