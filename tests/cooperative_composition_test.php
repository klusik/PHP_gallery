<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_composition_test.php
 * Module Type: Regression Test
 * Purpose: Verify reference-based metadata proposal creation without implicit authority.
 * Responsibilities: Cover bounded parsing, direct friendship, source rechecks, retries and UI forms.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
require_once __DIR__ . '/support/cooperative_exchange_fixture.php';
require_once dirname(__DIR__) . '/app/controllers/admin_cooperative_galleries.php';
require_once dirname(__DIR__) . '/app/controllers/admin_cooperative_proposals.php';
require_once dirname(__DIR__) . '/app/views/admin_cooperative_proposals.php';
use Gallery\Services as S;
use Gallery\Controllers as C;

exchange_fixture();
exchange_friend('a', 'b');
exchange_friend('a', 'c');
exchange_friend('b', 'c');
$codes = [];
foreach (['b', 'c', 'd'] as $node) {
    exchange_node($node);
    $codes[$node] = S\cooperative_album_reference_code(1);
    $member = S\cooperative_album_reference_decode($codes[$node]);
    exchange_check($member['instance_id'] === $GLOBALS['exchange_nodes'][$node]['id'] && $member['scopes'] === ['metadata'], 'Reference changed identity or scope.');
    exchange_check(S\cooperative_album_reference_code(1) === $codes[$node], 'Reference retry changed stable album identity.');
}
exchange_node('a');
$id = S\cooperative_id_generate();
$calls = $GLOBALS['exchange_calls'];
foreach (['invalid', 'pgc_secret', $codes['b'] . '=', str_repeat('a', 513)] as $code) {
    exchange_refuses(/** Reject malformed or credential-shaped references. @return array<string,mixed> Refused member. */ static fn() => S\cooperative_album_reference_decode($code), 'invalid_reference');
}
$forged = S\cooperative_album_reference_decode($codes['b']);
$forged['scopes'][] = 'original';
$code = 'pga1.' . rtrim(strtr(base64_encode(json_encode($forged)), '+/', '-_'), '=');
exchange_refuses(/** Composer cannot expand a reference to originals. @return array<string,mixed> Refused member. */ static fn() => S\cooperative_album_reference_decode($code), 'invalid_reference');
exchange_refuses(/** Duplicate installations cannot select two albums in one proposal. @return array<string,mixed> Refused proposal. */ static fn() => S\cooperative_proposal_compose($id, 1, $codes['b'] . "\n" . $codes['b']), 'duplicate_instance');
exchange_refuses(/** A reference is not a direct friendship. @return array<string,mixed> Refused proposal. */ static fn() => S\cooperative_proposal_compose($id, 1, $codes['d']), 'friendship_unavailable');
exchange_refuses(/** Oversized member lists are rejected before preparation. @return array<string,mixed> Refused proposal. */ static fn() => S\cooperative_proposal_compose($id, 1, implode("\n", array_fill(0, 32, $codes['b']))), 'invalid_members');
exchange_check(S\cooperative_group_read($id) === null && $GLOBALS['exchange_calls'] === $calls, 'Reference validation created a group or contacted peers.');

$state = S\cooperative_proposal_compose($id, 1, $codes['b'] . "\r\n" . $codes['c']);
exchange_check(count($state['body']['members']) === 3 && $state['own']['decision'] === 'pending' && !$state['sharing_active'], 'Composition implied approval or changed membership.');
$retry = S\cooperative_proposal_compose($id, 1, $codes['c'] . "\n" . $codes['b']);
exchange_check($retry['digest'] === $state['digest'] && $retry['revision'] === $state['revision'], 'Lost-response retry changed immutable proposal.');
exchange_refuses(/** Same intent must not overwrite another member selection. @return array<string,mixed> Refused proposal. */ static fn() => S\cooperative_proposal_compose($id, 1, $codes['b']), 'request_conflict');
exchange_check($GLOBALS['exchange_calls'] === $calls, 'Composition silently delivered a proposal.');
$GLOBALS['exchange_nodes']['a']['db']->exec("UPDATE galleries SET access_mode = 'password' WHERE id = 1");
try {
    S\cooperative_proposal_compose(S\cooperative_id_generate(), 1, $codes['b']);
    throw new RuntimeException('Protected source accepted.');
} catch (S\CooperativeException) { }
$GLOBALS['exchange_nodes']['a']['db']->exec("UPDATE galleries SET access_mode = 'normal' WHERE id = 1");

$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET = [];
$_POST = ['action' => 'reference', 'gallery_id' => '1', 'cooperative_proposal_ui' => '1', 'ajax' => '1'];
$response = exchange_http();
exchange_check($response['ok'] && str_starts_with($response['reference_code'], 'pga1.') && isset($response['panel_html']), 'Reference action lost the UI envelope.');
$newId = S\cooperative_id_generate();
$_POST = ['action' => 'compose', 'request_id' => $newId, 'gallery_id' => '1', 'references' => $codes['b'], 'cooperative_proposal_ui' => '1', 'ajax' => '1'];
$response = exchange_http();
exchange_check($response['ok'] && $response['proposal']['group_id'] === $newId && $response['mutation']['entity_ids'] === [1], 'Composition action lost stable intent or canonical metadata.');
$_POST['gallery_id'] = '1junk';
$response = exchange_http();
exchange_check(!$response['ok'], 'Partial numeric gallery ID accepted.');
$_POST['gallery_id'] = '1';
$_POST['references'] = 'bad-code';
$_POST['ajax'] = '0';
ob_start();
C\cms_admin_cooperative_proposal_action();
$html = (string) ob_get_clean();
exchange_check(str_contains($html, $newId) && str_contains($html, '>bad-code</textarea>'), 'HTML failure lost creation intent.');
$_POST = [];
$view = C\admin_cooperative_proposals_model();
exchange_check(count($view['sources']) === 1 && $view['sources'][0]['eligible'], 'Composer picker did not use the real source policy.');
$GLOBALS['exchange_enabled'] = false;
$before = $GLOBALS['exchange_db_calls'];
exchange_refuses(/** Capability OFF precedes reference storage access. @return string Refused reference. */ static fn() => S\cooperative_album_reference_code(1), 'feature_disabled');
exchange_check($GLOBALS['exchange_db_calls'] === $before, 'Disabled composer touched optional storage.');
echo "PASS cooperative proposal composition\n";
