<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/admin_cooperative_proposals_test.php
 * Module Type: Regression Test
 * Purpose: Verify real proposal review markup and UI mutation boundaries in isolated installations.
 * Responsibilities: Cover explicit consent, escaping, read-only rendering and canonical AJAX/HTML completion.
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

/** Capture real HTML output without a browser or application bootstrap.
 * @param callable $render Actual controller or view operation.
 * @return string Rendered HTML body.
 */
function proposal_ui_html(callable $render): string
{
    ob_start();
    try { $render(); return (string) ob_get_contents(); }
    finally { ob_end_clean(); }
}

exchange_fixture();
$GLOBALS['exchange_nodes']['a']['db']->exec('DELETE FROM cooperative_identity');
exchange_node('a');
$empty = C\admin_cooperative_proposals_model();
exchange_check($empty['rows'] === [] && $empty['error'] === ''
    && (int) $GLOBALS['exchange_nodes']['a']['db']->query('SELECT COUNT(*) FROM cooperative_identity')->fetchColumn() === 0,
    'Empty review created an installation identity.');

$id = exchange_approved_fixture();
$stored = S\cooperative_proposal_exchange_load($id);
$group = $stored['group'];
$group['exchange']['decision'] = 'pending';
$group['exchange']['generations'] = [];
unset($group['pending']['approvals'][S\cooperative_instance_id()]);
S\cooperative_proposal_exchange_save($stored, $group);
$GLOBALS['exchange_nodes']['a']['db']->exec("UPDATE galleries SET title = '<script>unsafe</script>' WHERE id = 1");
$before = exchange_state($id);
$calls = $GLOBALS['exchange_calls'];
$model = C\admin_cooperative_proposals_model();
$html = C\admin_cooperative_proposals_html($model);
exchange_check(str_contains($html, '&lt;script&gt;unsafe&lt;/script&gt;') && !str_contains($html, '<script>unsafe'), 'Album title escaped incorrectly.');
exchange_check(str_contains($html, 'value="approve"') && str_contains($html, $before['digest']) && str_contains($html, 'Photo previews'), 'Review lost explicit consent or scope.');
exchange_check(str_contains($html, 'https://b.example/gallery') && str_contains($html, 'https://c.example/gallery'), 'Participant identity missing.');
exchange_check($GLOBALS['exchange_calls'] === $calls && exchange_state($id)['revision'] === $before['revision'], 'Opening review performed writes or network calls.');
exchange_check(!str_contains($html, 'pgc_') && !str_contains($html, 'token_hash'), 'Review leaked credential records.');

$_GET = [];
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['action' => 'approve', 'group_id' => $id, 'revision' => $before['revision'], 'digest' => $before['digest'], 'cooperative_proposal_ui' => '1', 'ajax' => '1'];
$response = exchange_http();
exchange_check($response['ok'] && $response['mutation']['entity_ids'] === [1]
    && $response['panel']['workflow'] === 'cooperative_proposals' && $response['panel']['keep_open']
    && ($response['contexts'][0]['gallery_id'] ?? null) === 1 && str_contains($response['panel_html'], 'value="advance"'), 'UI approval lost canonical in-place completion.');
exchange_check(!str_contains($response['panel_html'], 'value="approve"') && !exchange_state($id)['sharing_active'], 'Approval bypassed separate verification.');
$stale = exchange_http();
exchange_check(!$stale['ok'] && http_response_code() === 409 && !isset($stale['panel_html']), 'Stale consent rewrote the fragment.');

foreach (['deliver', 'refresh', 'advance'] as $action) {
    $current = exchange_state($id);
    $_POST = ['action' => $action, 'group_id' => $id, 'revision' => $current['revision'], 'peer_id' => $GLOBALS['exchange_nodes']['b']['id'], 'cooperative_proposal_ui' => '1', 'ajax' => '1'];
    $response = exchange_http();
    exchange_check($response['ok'] && isset($response['panel_html']) && $response['mutation']['action'] === $action, 'Proposal UI action lost its canonical response.');
}
$current = exchange_state($id);
$_POST = ['action' => 'decline', 'group_id' => $id, 'revision' => $current['revision'], 'digest' => $current['digest'], 'cooperative_proposal_ui' => '1'];
$html = proposal_ui_html('Gallery\\Controllers\\cms_admin_cooperative_proposal_action');
exchange_check(str_contains($html, 'data-fixture-shell') && str_contains($html, 'Declined')
    && !str_contains($html, 'value="approve"') && !str_contains($html, 'value="advance"'), 'No-JavaScript decline lost state or restored consent.');
$_SERVER['REQUEST_METHOD'] = 'GET';
$_POST = [];
$_GET = ['panel' => '1'];
$html = proposal_ui_html('Gallery\\Controllers\\cms_admin_cooperative_collaborations');
exchange_check(str_contains($html, 'data-cooperative-panel') && !str_contains($html, 'data-fixture-shell'), 'Panel read included the ordinary page shell.');
$_GET = ['after_id' => ['invalid']];
$error = exchange_http('Gallery\\Controllers\\cms_admin_cooperative_collaborations');
exchange_check(!$error['ok'] && http_response_code() === 400, 'Array cursor was accepted.');
$_GET = [];
$GLOBALS['exchange_enabled'] = false;
$calls = $GLOBALS['exchange_db_calls'];
exchange_refuses(/** Disabled review is rejected before optional reads. @return void No output expected. */ static fn() => C\cms_admin_cooperative_collaborations(), 'feature_disabled');
exchange_check($GLOBALS['exchange_db_calls'] === $calls, 'Disabled review read optional storage.');

echo "PASS cooperative proposal UI\n";
