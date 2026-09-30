<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/support/cooperative_exchange_fixture.php
 * Module Type: Test Support
 * Purpose: Provide isolated multi-installation fixtures for cooperative protocol regressions.
 * Responsibilities: Verify immutable delivery, direct authority, stale consent and non-activation under retries.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Escape isolated HTML presentation.
     * @param string $value Untrusted label or form value.
     * @return string Escaped text or attribute.
     */
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    /** Supply the fixture CSRF field. @return string Disposable hidden input. */
    function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="fixture-csrf">'; }
    /** Build a local fixture route.
     * @param string $route Canonical route name.
     * @param array<string,mixed> $params Nonsecret query parameters.
     * @return string Relative route URL.
     */
    function url_for(string $route, array $params = []): string { return '/index.php?' . http_build_query(['page' => $route] + $params); }
    /** Mark ordinary page rendering.
     * @param string $title Prepared title.
     * @return void Emit a test shell.
     */
    function render_header(string $title): void { echo '<main data-fixture-shell>'; }
    /** End the fixture shell. @return void Emit closing markup. */
    function render_footer(): void { echo '</main>'; }
    /** Return the active isolated node database.
     * @return \PDO Disposable connection without production configuration.
     */
    function db(): \PDO { $GLOBALS['exchange_db_calls']++; return $GLOBALS['exchange_nodes'][$GLOBALS['exchange_node']]['db']; }
    /** Supply fixture-only encryption material per installation.
     * @return array<string,mixed> Deterministic isolated secret configuration.
     */
    function cms_config(): array { return ['visitor_vote_secret' => str_repeat($GLOBALS['exchange_node'] . '-test-secret-', 5)]; }
    /** Enforce fixture administrator authorization.
     * @return void Refuse unauthenticated calls before domain operations.
     */
    function require_admin(): void { if (!$GLOBALS['exchange_admin']) { throw new \RuntimeException('admin_required'); } }
    /** Enforce fixture CSRF authorization independently of peer credentials.
     * @return void Refuse invalid Admin mutation requests.
     */
    function verify_csrf(): void { if (!$GLOBALS['exchange_csrf']) { throw new \RuntimeException('csrf_required'); } }
    /** Return the explicit fixture method.
     * @return string HTTP method chosen by the test.
     */
    function request_method(): string { return $_SERVER['REQUEST_METHOD'] ?? 'GET'; }
}
namespace Gallery\Services {
    /** Resolve the fixture capability without production settings.
     * @param string $key Capability key being checked.
     * @return bool Current fixture enablement for cooperative galleries.
     */
    function feature_capability_effective_enabled(string $key): bool { return $key === 'cooperative_galleries' && $GLOBALS['exchange_enabled']; }
    /** Preserve readable controller fallbacks in isolation.
     * @param string $key Translation key.
     * @param string $fallback Safe fallback text.
     * @return string Fixture response text.
     */
    function t(string $key, string $fallback): string { return $fallback; }
}
namespace {
    use Gallery\Services as S;
    use Gallery\Models as M;
    foreach (['services/schema_inspection.php', 'services/security_tokens.php', 'services/gallery_access.php',
        'models/galleries.php', 'models/cooperative_galleries.php', 'services/cooperative_galleries.php',
        'services/cooperative_pairing.php', 'services/cooperative_proposals.php', 'helpers_mutation.php',
        'controllers/cooperative_pairing.php', 'controllers/cooperative_proposals.php'] as $file) {
        require_once dirname(__DIR__, 2) . '/app/' . $file;
    }
    /** Assert an isolated exchange invariant.
     * @param bool $condition Observed assertion result.
     * @param string $message Bounded regression diagnostic.
     * @return void Fail immediately on a regression.
     */
    function exchange_check(bool $condition, string $message): void
    {
        if (!$condition) { throw new \RuntimeException($message); }
    }
    /** Switch simulated installations and invalidate request-local schema observations.
     * @param string $node Isolated fixture node name.
     * @return void Select the independent database and encryption context.
     */
    function exchange_node(string $node): void { $GLOBALS['exchange_node'] = $node; S\schema_inspection_reset_request_cache(); }
    /** Require a bounded domain refusal from a production operation.
     * @param callable $operation Fixture operation to execute.
     * @param string $reason Expected domain error code.
     * @return void Fail on the wrong error or unexpected success.
     */
    function exchange_refuses(callable $operation, string $reason): void
    {
        try { $operation(); } catch (S\CooperativeException $error) {
            exchange_check($error->reason === $reason, 'Wrong exchange refusal: ' . $error->reason . ', expected ' . $reason);
            return;
        }
        throw new \RuntimeException('Expected exchange refusal: ' . $reason);
    }
    /** Establish independent directed system credentials using the real foundation lifecycle.
     * @param string $first First isolated installation name.
     * @param string $second Second isolated installation name.
     * @return void Complete exactly one direct friendship at both installations.
     */
    function exchange_friend(string $first, string $second): void
    {
        exchange_node($first);
        $firstId = S\cooperative_instance_id();
        $secondId = $GLOBALS['exchange_nodes'][$second]['id'];
        S\cooperative_peer_register($secondId, 'https://' . $second . '.example/gallery');
        $firstKey = S\cooperative_peer_prepare_credentials($secondId, 1)['token'];
        exchange_node($second);
        S\cooperative_peer_register($firstId, 'https://' . $first . '.example/gallery');
        $secondKey = S\cooperative_peer_prepare_credentials($firstId, 1, $firstKey)['token'];
        exchange_node($first);
        S\cooperative_peer_accept_remote_credential($secondId, 2, $secondKey);
        foreach ([[$first, $secondId, 3], [$second, $firstId, 2]] as [$node, $remote, $revision]) {
            exchange_node($node);
            foreach (['local_consent', 'remote_consent', 'inbound_verified', 'outbound_verified'] as $fact) {
                S\cooperative_peer_record_confirmation($remote, $revision++, $fact);
            }
        }
    }
    /** Route one authenticated service request without network access or overlapping SQL transactions.
     * @param string $base Stored canonical target origin.
     * @param array<string,mixed> $message Exact outbound protocol message.
     * @param string $bearer Actual directed fixture system token.
     * @return array<string,mixed> Actual remote service response, optionally tampered for negative tests.
     */
    function exchange_transport(string $base, array $message, string $bearer): array
    {
        $caller = $GLOBALS['exchange_node'];
        foreach ($GLOBALS['exchange_nodes'] as $node) {
            exchange_check(!$node['db']->inTransaction(), 'Network operation inside SQL transaction.');
        }
        $target = null;
        foreach (array_keys($GLOBALS['exchange_nodes']) as $name) {
            if ($base === 'https://' . $name . '.example/gallery') { $target = $name; }
        }
        exchange_check($target !== null, 'Outbound target did not come from a registered peer.');
        $GLOBALS['exchange_calls']++;
        exchange_node($target);
        try {
            $response = S\cooperative_proposal_receive($message, $bearer);
            if ($GLOBALS['exchange_drop']) {
                $GLOBALS['exchange_drop'] = false;
                throw new \RuntimeException('Lost response after durable import.');
            }
            if ($GLOBALS['exchange_tamper'] === 'challenge') { $response['challenge'] = str_repeat('0', 32); }
            if ($GLOBALS['exchange_tamper'] === 'edge') { unset($response['generations'][array_key_first($response['generations'])]); }
            if ($GLOBALS['exchange_tamper'] === 'actor') { $response['sender_id'] = str_repeat('e', 32); }
            if ($GLOBALS['exchange_tamper'] === 'forwarded') { $response['approvals'] = [$message['sender_id'] => $message['digest']]; }
        } finally {
            exchange_node($caller);
        }
        $hook = $GLOBALS['exchange_hook'];
        $GLOBALS['exchange_hook'] = null;
        if (is_callable($hook)) { $hook(); }
        return $response;
    }
    /** Invoke the actual Admin controller while capturing its JSON response.
     * @param string $handler Fully qualified fixture controller name.
     * @return array<string,mixed> Decoded response envelope.
     */
    function exchange_http(string $handler = 'Gallery\\Controllers\\cms_admin_cooperative_proposal_action'): array
    {
        ob_start();
        try { $handler(); return json_decode((string) ob_get_contents(), true, 16, JSON_THROW_ON_ERROR); }
        finally { ob_end_clean(); }
    }
    /** Read current local proposal projection.
     * @param string $groupId Stable proposal group identity.
     * @return array<string,mixed> Current nonsecret local state.
     */
    function exchange_state(string $groupId): array { return S\cooperative_proposal_exchange_projection(S\cooperative_proposal_exchange_load($groupId)); }

    /** Reset independent installations and their transport for one isolated scenario.
     * @return void Provision only disposable in-memory databases and fixture credentials.
     */
    function exchange_fixture(): void
    {
        $GLOBALS['exchange_nodes'] = [];
        $GLOBALS['exchange_db_calls'] = 0;
        $GLOBALS['exchange_calls'] = 0;
        $GLOBALS['exchange_enabled'] = true;
        $GLOBALS['exchange_admin'] = true;
        $GLOBALS['exchange_csrf'] = true;
        $GLOBALS['exchange_drop'] = false;
        $GLOBALS['exchange_tamper'] = '';
        $GLOBALS['exchange_hook'] = null;
        S\schema_inspection_set_query_executor_for_tests(/** Observe isolated current schema. @return bool All required objects exist in this fixture. */ static fn(): bool => true);
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
            $db->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY, title TEXT, parent_id INTEGER, visibility TEXT, access_mode TEXT, access_listing TEXT, nsfw_enabled INTEGER)');
            $db->exec('INSERT INTO galleries VALUES (1, "Trip", NULL, "public", "normal", "listed", 0)');
            foreach (require dirname(__DIR__, 2) . '/database/migrations/202609270001_cooperative_galleries_foundations.php' as $sql) {
                $sql = preg_replace('/ CHARACTER SET ascii COLLATE ascii_bin| UNSIGNED/', '', $sql);
                $sql = preg_replace('/UNIQUE KEY [a-z_]+ \(([^)]+)\)/', 'UNIQUE ($1)', $sql);
                $sql = preg_replace('/\) ENGINE=InnoDB.*$/s', ')', $sql);
                $db->exec($sql);
            }
            $GLOBALS['exchange_nodes'][$name] = ['db' => $db];
            exchange_node($name);
            $GLOBALS['exchange_nodes'][$name]['id'] = S\cooperative_instance_id();
            $GLOBALS['exchange_nodes'][$name]['member'] = S\cooperative_album_source_member(1, ['metadata', 'preview']);
        }
        $transport = &S\cooperative_proposal_transport_override();
        $transport = 'exchange_transport';
    }
    /** Prepare a fully paired A/B/C proposal with independent explicit local approvals.
     * @return string Initial group ID; no installation is activated by this setup.
     */
    function exchange_approved_fixture(): string
    {
        exchange_fixture();
        exchange_friend('a', 'b');
        exchange_friend('a', 'c');
        exchange_friend('b', 'c');
        exchange_friend('a', 'd');
        $members = [];
        foreach (['a', 'b', 'c'] as $name) { $members[] = $GLOBALS['exchange_nodes'][$name]['member']; }
        exchange_node('a');
        $id = S\cooperative_id_generate();
        $state = S\cooperative_proposal_exchange_create($id, $members);
        foreach (['b', 'c'] as $name) {
            $state = S\cooperative_proposal_exchange_contact($id, $state['revision'], $GLOBALS['exchange_nodes'][$name]['id'], 'offer');
        }
        foreach (['a', 'b', 'c'] as $name) {
            exchange_node($name);
            $state = exchange_state($id);
            S\cooperative_proposal_exchange_decide($id, $state['revision'], $state['digest'], 'approved');
        }
        exchange_node('a');
        return $id;
    }


}
