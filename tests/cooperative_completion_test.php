<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_completion_test.php
 * Module Type: Regression Test
 * Purpose: Verify expansion, automated orchestration and non-authorizing email invitations.
 * Responsibilities: Cover pagination, direct authority, media revocation and unavailable sources.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

require_once __DIR__ . '/support/cooperative_exchange_fixture.php';
use Gallery\Services as S;
exchange_fixture();
exchange_friend('a', 'b');
exchange_friend('b', 'c');
$members = [$GLOBALS['exchange_nodes']['a']['member'], $GLOBALS['exchange_nodes']['b']['member']];
exchange_node('a');
$old = S\cooperative_id_generate();
$state = S\cooperative_proposal_exchange_create($old, $members);
$state = S\cooperative_proposal_synchronize($old, $state['revision']);
exchange_check($state['own']['decision'] === 'pending', 'Automatic delivery granted consent.');
foreach (['a', 'b'] as $node) {
    exchange_node($node); $state = exchange_state($old);
    S\cooperative_proposal_exchange_decide($old, $state['revision'], $state['digest'], 'approved');
}
foreach (['a', 'b'] as $node) {
    exchange_node($node);
    for ($step = 0; $step < 4; $step++) {
        $state = exchange_state($old);
        if (S\cooperative_proposal_workflow_next($state) === null) { break; }
        S\cooperative_proposal_synchronize($old, $state['revision']);
    }
    exchange_check(exchange_state($old)['sharing_active'], 'One-click orchestration failed to activate the original collaboration.');
}
exchange_node('c'); $code = S\cooperative_album_reference_code(1, true);
exchange_node('b');
$id = S\cooperative_id_generate();
$expanded = S\cooperative_proposal_expand($old, exchange_state($old)['revision'], $id, $code);
exchange_check(count($expanded['body']['members']) === 3 && $expanded['own']['decision'] === 'pending', 'Expansion reused consent.');
exchange_check(exchange_state($old)['sharing_active'], 'Preparing expansion interrupted the old collaboration.');
$retry = S\cooperative_proposal_expand($old, exchange_state($old)['revision'], $id, $code);
exchange_check($retry['digest'] === $expanded['digest'], 'Expansion retry created another immutable proposal.');
$send = &S\cooperative_invitation_mail_override();
$mail = [];
$send = /** Capture email without external delivery.
 * @param string $to Explicit recipient.
 * @param string $subject Localized subject.
 * @param string $body Non-authorizing review URL and fixed deadline.
 * @return array{sent:bool} Simulated configured transport acceptance.
 */ static function(string $to, string $subject, string $body) use (&$mail): array {
    $mail[] = [$to, $subject, $body]; return ['sent' => true];
};
$a = $GLOBALS['exchange_nodes']['a']['id'];
$state = S\cooperative_proposal_email($id, $expanded['revision'], $a, 'owner@example.test');
exchange_check(count($mail) === 1 && str_contains($mail[0][2], 'https://a.example/gallery/index.php?')
    && str_contains($mail[0][2], $id) && !str_contains($mail[0][2], 'pgc_'), 'Email lost peer review URL or exposed credentials.');
exchange_check($state['body']['expires_at'] - $state['body']['issued_at'] === 86400, 'Email changed the one-day proposal lifetime.');
exchange_refuses(/** Repeated explicit sends are bounded even if the first response was lost. @return array<string,mixed> Refused repeat. */
    static fn() => S\cooperative_proposal_email($id, $state['revision'], $a, 'owner@example.test'), 'mail_retry_later');
exchange_refuses(/** Email header injection is refused before any transport. @return array<string,mixed> Refused recipient. */
    static fn() => S\cooperative_proposal_email($id, $state['revision'], $a, "owner@example.test\r\nBcc:other@example.test"), 'invalid_email');
$state = S\cooperative_proposal_exchange_contact($id, $state['revision'], $GLOBALS['exchange_nodes']['c']['id'], 'offer');
exchange_node('a'); $state = exchange_state($id);
exchange_refuses(/** A-C cannot inherit B's direct friendship. @return array<string,mixed> Refused consent. */
    static fn() => S\cooperative_proposal_exchange_decide($id, $state['revision'], $state['digest'], 'approved'), 'friendship_unavailable');
exchange_check(exchange_state($old)['sharing_active'], 'Missing new friendship affected the original A+B grant.');
exchange_friend('a', 'c');
foreach (['a', 'b', 'c'] as $node) {
    exchange_node($node); $state = exchange_state($id);
    S\cooperative_proposal_exchange_decide($id, $state['revision'], $state['digest'], 'approved');
}
foreach (['a', 'b', 'c'] as $node) {
    exchange_node($node);
    for ($step = 0; $step < 5; $step++) {
        $state = exchange_state($id);
        if (S\cooperative_proposal_workflow_next($state) === null) { break; }
        S\cooperative_proposal_synchronize($id, $state['revision']);
    }
    exchange_check(exchange_state($id)['sharing_active'], 'Unanimous directly paired expansion failed activation.');
}

exchange_node('c');
$state = exchange_state($id);
$state = S\cooperative_proposal_exchange_decide($id, $state['revision'], $state['digest'], 'declined');
exchange_check(!$state['sharing_active'] && count($state['withdrawal_pending']) === 2, 'Withdrawal did not persist its notification destinations.');
for ($i = 0; $i < 2; $i++) { $state = S\cooperative_proposal_synchronize($id, $state['revision']); }
exchange_check($state['withdrawal_pending'] === [], 'Delivered invalidations were not acknowledged locally.');
foreach (['a', 'b'] as $node) {
    exchange_node($node);
    exchange_check(!exchange_state($id)['sharing_active'], 'Direct withdrawal notification left remote authority active.');
    exchange_check(exchange_state($id)['own']['decision'] === 'approved', 'Notification forged another administrator decision.');
}
echo "PASS cooperative expansion, delivery, email, activation and withdrawal\n";
