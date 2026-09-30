<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/cooperative_pairing_workflow_test.php
 * Module Type: Regression Test
 * Purpose: Exercise bilateral pairing and recovery on two isolated installations.
 * Responsibilities: Verify exact authority, secret isolation and durable idempotent network effects.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Escape fixture view output.
     * @param string $value Text to escape.
     * @return string Safe HTML text or attribute.
     */
    function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    /** Provide a disposable anti-CSRF form field. @return string Fixture hidden input. */
    function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="fixture-csrf">'; }
    /** Mark non-JavaScript page rendering.
     * @param string $title Prepared title.
     * @return void Emit a bounded fixture shell.
     */
    function render_header(string $title): void { echo '<main data-fixture-shell>'; }
    /** End the fixture shell. @return void Emit closing markup. */
    function render_footer(): void { echo '</main>'; }
    /** Enforce the fixture administrator boundary. @return void Refuse anonymous access. */
    function require_admin(): void
    {
        if (!($GLOBALS['pair_admin'] ?? false)) {
            throw new \Gallery\Services\CooperativeException('admin_required');
        }
    }
    /** Enforce the fixture CSRF boundary. @return void Refuse unverified mutations. */
    function verify_csrf(): void
    {
        if (!($GLOBALS['pair_csrf'] ?? false)) {
            throw new \Gallery\Services\CooperativeException('csrf_required');
        }
    }
    /** Read the test request method. @return string Explicit fixture HTTP verb. */
    function request_method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }
    /** Build a deterministic fixture route.
     * @param string $route Canonical route name.
     * @param array<string,mixed> $params Explicit nonsecret query fields.
     * @return string Local route without secrets.
     */
    function url_for(string $route, array $params = []): string
    {
        return '/index.php?' . http_build_query(['page' => $route] + $params);
    }
    /** Return only the active disposable installation database.
 *
 * @return \PDO Result described by the operation above.
 */
    function db(): \PDO
    {
        $GLOBALS['pair_db_calls']++;
        return $GLOBALS['pair_nodes'][$GLOBALS['pair_node']]['db'];
    }
    /** Return fixture-only configuration for the selected installation.
 *
 * @return array<string,mixed> Result described by the operation above.
 */
    function cms_config(): array
    {
        return ['base_url' => 'https://' . $GLOBALS['pair_node'] . '.example/gallery',
            'visitor_vote_secret' => str_repeat($GLOBALS['pair_node'] . '-fixture-secret-', 4)];
    }
}
namespace Gallery\Services {
    /** Preserve safe controller fallback text.
     * @param string $key Translation identifier.
     * @param string $fallback Safe English fallback.
     * @return string User-visible response text.
     */
    function t(string $key, string $fallback): string
    {
        return $fallback;
    }
    /** Model capability configuration without loading production settings.
 *
 * @param string $key Canonical setting or form field name.
 * @return bool Result described by the operation above.
 */
    function feature_capability_effective_enabled(string $key): bool
    {
        return $key === 'cooperative_galleries' && $GLOBALS['pair_enabled'];
    }
}
namespace {
    require_once dirname(__DIR__) . '/app/services/security_tokens.php';
    require_once dirname(__DIR__) . '/app/services/schema_inspection.php';
    require_once dirname(__DIR__) . '/app/services/cooperative_galleries.php';
    require_once dirname(__DIR__) . '/app/models/cooperative_galleries.php';
    require_once dirname(__DIR__) . '/app/models/cooperative_pairing.php';
    require_once dirname(__DIR__) . '/app/services/outbound_http.php';
    require_once dirname(__DIR__) . '/app/services/cooperative_pairing.php';

    require_once dirname(__DIR__) . '/app/helpers_mutation.php';
    require_once dirname(__DIR__) . '/app/controllers/cooperative_pairing.php';
    require_once dirname(__DIR__) . '/app/controllers/admin_cooperative_galleries.php';
    require_once dirname(__DIR__) . '/app/views/admin_cooperative_galleries.php';

    require_once dirname(__DIR__) . '/app/services/admin_settings_registry.php';
    require_once dirname(__DIR__) . '/app/views/admin_ui.php';
    require_once dirname(__DIR__) . '/app/views/admin_settings.php';

    use Gallery\Services as S;
    use Gallery\Models as M;

    /** Assert one behavioral invariant without printing credentials.
 *
 * @param bool $value Value whose declared contract is checked.
 * @param string $message Validated protocol message or diagnostic assertion text.
 * @return void No return value; refusals raise an exception.
 */
    function pairing_check(bool $value, string $message): void
    {
        if (!$value) {
            throw new \RuntimeException($message);
        }
    }

    /** Expect a bounded refusal from a domain operation.
 *
 * @param callable $operation Trusted local callback; never obtained from request data.
 * @param string $reason Bounded domain refusal identifier.
 * @return void No return value; refusals raise an exception.
 */
    function pairing_refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
        } catch (S\CooperativeException $error) {
            pairing_check($error->reason === $reason, 'Unexpected refusal: ' . $error->reason . ', expected ' . $reason);
            return;
        }
        throw new \RuntimeException('Expected refusal: ' . $reason);
    }

    /** Switch isolated installations and discard request-local schema observations.
 *
 * @param string $node Disposable installation name.
 * @return void No return value; refusals raise an exception.
 */
    function pairing_node(string $node): void
    {
        $GLOBALS['pair_node'] = $node;
        S\schema_inspection_reset_request_cache();
    }

    /** Create two disposable databases from the production migration definitions.
 *
 * @return void No return value; refusals raise an exception.
 */
    function pairing_fixture(): void
    {
        $GLOBALS['pair_db_calls'] = 0;
        $GLOBALS['pair_enabled'] = true;
        $GLOBALS['pair_messages'] = [];
        $GLOBALS['pair_drop'] = '';
        $GLOBALS['pair_offline'] = '';
        foreach (['a', 'b'] as $node) {
            $db = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $db->exec('PRAGMA foreign_keys = ON');
            $db->exec('CREATE TABLE galleries (id INTEGER PRIMARY KEY)');
            foreach (['202609270001_cooperative_galleries_foundations.php', '202609270002_cooperative_pairing.php'] as $file) {
                foreach (require dirname(__DIR__) . '/database/migrations/' . $file as $sql) {
                    $sql = str_replace('BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
                    $sql = preg_replace('/ CHARACTER SET ascii COLLATE ascii_bin| UNSIGNED/', '', $sql);
                    $sql = preg_replace('/UNIQUE KEY [a-z_]+ \(([^)]+)\)/', 'UNIQUE ($1)', $sql);
                    $sql = preg_replace('/\) ENGINE=InnoDB.*$/s', ')', $sql);
                    $db->exec($sql);
                }
            }
            $GLOBALS['pair_nodes'][$node] = ['db' => $db];
            pairing_node($node);
            $executor = &S\schema_inspection_query_executor_override();
            $executor = /** Run the isolated fixture assertion. @return bool Fixture result or expected refusal. */ static fn(): bool => true;
            $GLOBALS['pair_nodes'][$node]['id'] = S\cooperative_instance_id();
        }
        $transport = &S\cooperative_pairing_transport_override();
        $transport = 'pairing_transport';
        pairing_node('a');
    }

    /** Route a real service message between installations without external network or transaction overlap.
 *
 * @param string $base Canonical HTTPS installation base.
 * @param array<string,mixed>|null $message Validated protocol message or diagnostic assertion text.
 * @param string $bearer Secret presented only in the Authorization header.
 * @return array<string,mixed> Result described by the operation above.
 */
    function pairing_transport(string $base, ?array $message, string $bearer): array
    {
        $caller = $GLOBALS['pair_node'];
        foreach ($GLOBALS['pair_nodes'] as $node) {
            pairing_check(!$node['db']->inTransaction(), 'Network request happened inside a SQL transaction.');
        }
        $target = match ($base) {
            'https://a.example/gallery' => 'a',
            'https://b.example/gallery' => 'b',
            default => throw new \RuntimeException('Unexpected fixture origin.'),
        };
        if ($GLOBALS['pair_offline'] === $target) {
            throw new \RuntimeException('Fixture offline.');
        }
        $GLOBALS['pair_messages'][] = ['target' => $target, 'body' => $message, 'bearer' => $bearer];
        pairing_node($target);
        try {
            $response = $message === null ? S\cooperative_pairing_identity() : S\cooperative_pairing_receive($message, $bearer);
            if ($message !== null && $GLOBALS['pair_drop'] === $message['action']) {
                $GLOBALS['pair_drop'] = '';
                throw new \RuntimeException('Fixture lost response after persistence.');
            }
            return $response;
        } finally {
            pairing_node($caller);
        }
    }

    /** Create and import one invitation while leaving both peers unapproved.
 *
 * @return array<string,mixed> Result described by the operation above.
 */
    function pairing_invitation(): array
    {
        pairing_node('a');
        $id = S\cooperative_id_generate();
        $created = S\cooperative_pairing_create('https://b.example/gallery', $id);
        pairing_check(S\cooperative_pairing_create('https://b.example/gallery', $id)['invitation_code'] === $created['invitation_code'], 'Create retry changed bootstrap authority.');
        $descriptor = S\cooperative_pairing_invitation_decode($created['invitation_code']);
        pairing_check(!str_contains(json_encode($descriptor), 'pgc_'), 'Invitation exposed a system credential.');
        pairing_node('b');
        $imported = S\cooperative_pairing_import($created['invitation_code']);
        pairing_check($imported['state'] === 'received' && $imported['peer_state'] === 'pending', 'Import accepted friendship.');
        pairing_check(S\cooperative_pairing_import($created['invitation_code'])['id'] === $imported['id'], 'Import replay duplicated a peer.');
        return [$id, $created, $imported, $descriptor];
    }

    /** Advance only the test fixture retry clock without sleeping or touching production state.
 *
 * @param string $id Stable public invitation identifier.
 * @return void No return value; refusals raise an exception.
 */
    function pairing_due(string $id): void
    {
        \Gallery\Core\db()->prepare('UPDATE cooperative_pairings SET next_attempt_at = 0 WHERE invitation_id = ?')->execute([$id]);
    }

    /** Capture a real JSON controller without printing credentials into audit logs.
     * @param callable $controller Controller entry point under test.
     * @return array<string,mixed> Decoded JSON response.
     */
    function pairing_http(callable $controller): array
    {
        ob_start();
        try {
            $controller();
            return json_decode(ob_get_contents(), true, 16, JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
    }

    pairing_fixture();
    [$id, $created, $imported, $descriptor] = pairing_invitation();
    pairing_node('a');
    $attack = [
        'protocol' => 1, 'action' => 'accept', 'invitation_id' => $id,
        'sender_id' => $GLOBALS['pair_nodes']['b']['id'], 'recipient_id' => $GLOBALS['pair_nodes']['a']['id'],
        'key' => S\cooperative_credential_generate(), 'nonce' => S\security_opaque_token_generate(),
    ];
    pairing_refuses(/** Run the isolated fixture assertion. @return array<string,mixed>|never Fixture result or expected refusal. */ static fn() => S\cooperative_pairing_receive($attack, $descriptor['secret']), 'peer_unavailable');
    pairing_check(S\cooperative_pairing_load($id)['state'] === 'invited', 'Unverified reverse origin consumed the invitation.');
    pairing_check(M\cooperative_model_peer_find($GLOBALS['pair_nodes']['b']['id'])['outgoing_cipher'] === null, 'Unverified key was persisted.');
    pairing_node('b');
    $active = S\cooperative_pairing_accept($id, $imported['revision']);
    pairing_check($active['state'] === 'active' && $active['peer_state'] === 'active', 'Invitee did not activate.');
    $tokenForA = S\cooperative_peer_outbound_credential($GLOBALS['pair_nodes']['a']['id']);
    pairing_node('a');
    $tokenForB = S\cooperative_peer_outbound_credential($GLOBALS['pair_nodes']['b']['id']);
    pairing_check(S\cooperative_peer_authenticate($GLOBALS['pair_nodes']['b']['id'], $tokenForA) !== null, 'B cannot authenticate at A.');
    pairing_check(S\cooperative_peer_authenticate($GLOBALS['pair_nodes']['b']['id'], $tokenForB) === null, 'Directional credentials were confused.');
    $snapshot = json_encode(S\cooperative_pairing_admin_state());
    pairing_check(!str_contains($snapshot, $tokenForA) && !str_contains($snapshot, $tokenForB)
        && !str_contains($snapshot, 'secret_hash') && !str_contains($snapshot, 'payload_cipher'), 'UI snapshot leaked authority.');
    $raw = json_encode(\Gallery\Core\db()->query('SELECT * FROM cooperative_pairings')->fetchAll());
    pairing_check(!str_contains($raw, $descriptor['secret']) && !str_contains($raw, $tokenForA), 'Recovery storage leaked plaintext.');
    $peerRevision = M\cooperative_model_peer_find($GLOBALS['pair_nodes']['b']['id'])['revision'];
    foreach ($GLOBALS['pair_messages'] as $request) {
        if (($request['body']['action'] ?? '') === 'confirm') {
            S\cooperative_pairing_receive($request['body'], $request['bearer']);
        }
    }
    pairing_check(M\cooperative_model_peer_find($GLOBALS['pair_nodes']['b']['id'])['revision'] === $peerRevision, 'Confirmation replay rotated friendship evidence.');

    // A lost acceptance response must reuse the same keys and immutable nonce tuple.
    pairing_fixture();
    [$id, $created, $imported, $descriptor] = pairing_invitation();
    $GLOBALS['pair_drop'] = 'accept';
    $pending = S\cooperative_pairing_accept($id, $imported['revision']);
    pairing_check($pending['state'] === 'accepting' && $pending['last_error'] === 'peer_unavailable', 'Lost acceptance was not recoverable.');
    $issued = S\cooperative_pairing_load($id)['payload']['issued_token'];
    pairing_refuses(/** Run the isolated fixture assertion. @return array<string,mixed>|never Fixture result or expected refusal. */ static fn() => S\cooperative_pairing_resume($id), 'retry_not_due');
    pairing_due($id);
    pairing_check(S\cooperative_pairing_resume($id)['state'] === 'active', 'Acceptance recovery failed.');
    pairing_check(S\security_authority_token_verify(M\cooperative_model_peer_find($GLOBALS['pair_nodes']['a']['id'])['incoming_hash'], $issued), 'Recovery regenerated an issued key.');

    // A lost final acknowledgement can be replayed even after the original invitation expires.
    pairing_fixture();
    [$id, $created, $imported, $descriptor] = pairing_invitation();
    $GLOBALS['pair_drop'] = 'confirm';
    pairing_check(S\cooperative_pairing_accept($id, $imported['revision'])['state'] === 'confirming', 'Lost confirmation not retained.');
    pairing_node('a');
    \Gallery\Core\db()->exec('UPDATE cooperative_pairings SET expires_at = 1');
    pairing_node('b');
    \Gallery\Core\db()->exec('UPDATE cooperative_pairings SET expires_at = 1');
    pairing_due($id);
    pairing_check(S\cooperative_pairing_resume($id)['state'] === 'active', 'Completed remote activation could not be acknowledged after expiry.');

    // Revocation removes local authority before retrying a lost remote acknowledgement.
    pairing_node('a');
    $before = S\cooperative_pairing_load($id);
    $GLOBALS['pair_drop'] = 'revoke';
    $revoking = S\cooperative_pairing_revoke($id, $before['revision']);
    pairing_check($revoking['state'] === 'revoking' && $revoking['peer_state'] === 'revoked', 'Local authority survived a remote timeout.');
    pairing_node('b');
    pairing_check(S\cooperative_pairing_load($id)['state'] === 'revoked', 'Remote revocation not applied.');
    pairing_node('a');
    pairing_due($id);
    pairing_check(S\cooperative_pairing_resume($id)['state'] === 'revoked', 'Revocation receipt replay failed.');

    // Declining an imported invitation does not need or create a long-lived credential.
    pairing_fixture();
    [$id, $created, $imported, $descriptor] = pairing_invitation();
    pairing_check(S\cooperative_pairing_revoke($id, $imported['revision'])['state'] === 'revoked', 'Decline did not complete.');
    pairing_node('a');
    pairing_check(S\cooperative_pairing_load($id)['state'] === 'revoked', 'Decline did not invalidate the invitation.');

    // Both local administrators may disconnect before either notification arrives.
    pairing_fixture();
    [$id, $created, $imported, $descriptor] = pairing_invitation();
    S\cooperative_pairing_accept($id, $imported['revision']);
    $GLOBALS['pair_offline'] = 'a';
    pairing_check(S\cooperative_pairing_revoke($id, S\cooperative_pairing_load($id)['revision'])['state'] === 'revoking', 'Offline revocation was not queued.');
    pairing_node('a');
    $GLOBALS['pair_offline'] = 'b';
    pairing_check(S\cooperative_pairing_revoke($id, S\cooperative_pairing_load($id)['revision'])['state'] === 'revoking', 'Second offline revocation was not queued.');
    $GLOBALS['pair_offline'] = '';
    pairing_due($id);
    pairing_check(S\cooperative_pairing_resume($id)['state'] === 'revoked', 'Simultaneous revocation did not converge.');
    pairing_node('b');
    pairing_check(S\cooperative_pairing_load($id)['state'] === 'revoked', 'Remote simultaneous revocation remained pending.');

    // A fresh lifecycle cannot restore old credentials or reset friendship evidence generations.
    pairing_node('a');
    $oldId = $id;
    $oldRevision = M\cooperative_model_peer_find($GLOBALS['pair_nodes']['b']['id'])['revision'];
    [$id, $created, $imported, $descriptor] = pairing_invitation();
    pairing_check($id !== $oldId, 'Re-pairing reused an old invitation.');
    pairing_check(S\cooperative_pairing_accept($id, $imported['revision'])['state'] === 'active', 'Fresh friendship after revocation failed.');
    pairing_node('a');
    pairing_check(M\cooperative_model_peer_find($GLOBALS['pair_nodes']['b']['id'])['revision'] > $oldRevision, 'Re-pairing reset credential generation.');
    pairing_check(M\cooperative_pairing_model_find($oldId) === null, 'Old invitation remained routable after replacement.');

    // Unknown optional recovery columns cannot block local credential revocation.
    pairing_fixture();
    [$id, $created, $imported, $descriptor] = pairing_invitation();
    S\cooperative_pairing_accept($id, $imported['revision']);
    $before = S\cooperative_pairing_load($id);
    $executor = &S\schema_inspection_query_executor_override();
    $executor = /** Simulate unreadable optional recovery schema while verifying minimal revoke storage.
     * @param string $kind Schema object kind.
     * @param string $table Trusted table identifier.
     * @param string $object Trusted column or index identifier.
     * @return bool Verified required storage; optional recovery raises an inspection failure.
     */ static function (string $kind, string $table, string $object): bool {
        if ($kind === 'column' && in_array($object, ['outgoing_cipher', 'payload_cipher'], true)) {
            throw new \RuntimeException('Fixture metadata unavailable.');
        }
        return true;
    };
    S\schema_inspection_reset_request_cache();
    $revoked = S\cooperative_pairing_revoke($id, $before['revision']);
    pairing_check($revoked['state'] === 'revoked' && $revoked['delivery_status'] === 'unavailable', 'Narrow local revocation was blocked.');
    pairing_check(M\cooperative_model_peer_find($GLOBALS['pair_nodes']['a']['id'])['incoming_hash'] === null, 'Narrow revocation retained inbound authority.');

    // Actual controllers enforce administrator/CSRF boundaries and canonical completion metadata.
    pairing_fixture();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['pair_admin'] = false;
    $GLOBALS['pair_csrf'] = false;
    $_POST = ['action' => 'invite', 'base_url' => 'https://b.example/gallery', 'request_id' => S\cooperative_id_generate()];
    pairing_refuses('Gallery\\Controllers\\cms_admin_cooperative_action', 'admin_required');
    $GLOBALS['pair_admin'] = true;
    pairing_refuses('Gallery\\Controllers\\cms_admin_cooperative_action', 'csrf_required');
    pairing_check(M\cooperative_pairing_model_list(0, 50) === [], 'Rejected controller mutated storage.');
    $GLOBALS['pair_csrf'] = true;
    $envelope = pairing_http('Gallery\\Controllers\\cms_admin_cooperative_action');
    pairing_check($envelope['ok'] === true && $envelope['mutation']['type'] === 'cooperative_pairing.invite'
        && $envelope['mutation']['entity_ids'] === [$envelope['pairing']['id']]
        && $envelope['panel']['keep_open'] === true && $envelope['contexts'] === []
        && isset($envelope['fallback']['redirect_url']), 'Controller lost canonical completion metadata.');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = [];
    $snapshot = pairing_http('Gallery\\Controllers\\cms_admin_cooperative_state');
    pairing_check($snapshot['ok'] === true && !str_contains(json_encode($snapshot), $envelope['invitation_code']), 'Admin list exposed invitation authority.');
    $refused = pairing_http('Gallery\\Controllers\\cms_admin_cooperative_action');
    pairing_check($refused['error_code'] === 'method_not_allowed' && http_response_code() === 405, 'GET mutated admin state.');
    $_GET['token'] = str_repeat('x', 43);
    $_COOKIE['token'] = str_repeat('x', 43);
    unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    pairing_check(\Gallery\Controllers\cooperative_pairing_bearer() === '', 'Cookie or query token authorized a peer.');
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . str_repeat('x', 43);
    pairing_check(\Gallery\Controllers\cooperative_pairing_bearer() === str_repeat('x', 43), 'Header bearer was not recognized.');
    $_SERVER['HTTP_AUTHORIZATION'] .= "\r\nInjected: yes";
    pairing_check(\Gallery\Controllers\cooperative_pairing_bearer() === '', 'Header injection was accepted.');
    $_POST = ['action' => ['invite']];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $refused = pairing_http('Gallery\\Controllers\\cms_admin_cooperative_action');
    pairing_check($refused['ok'] === false && $refused['error_code'] === 'cooperative.invalid_input', 'Array input escaped bounded refusal.');

    // Render the actual Settings page: a search result must have a visible destination.
    $GLOBALS['pair_enabled'] = true;
    $settingsCatalog = S\admin_settings_specialized_catalog();
    $friendshipEntry = $settingsCatalog['cooperative_friendships'];
    $friendshipEntry['view_group'] = 'advanced';
    $friendshipEntry['view_section_url'] = S\admin_settings_url('advanced');
    ob_start();
    \Gallery\Views\view_render_admin_settings_page([
        'active_section' => 'advanced',
        'sections' => S\admin_settings_sections(),
        'registry' => ['cooperative_friendships' => $friendshipEntry],
    ]);
    $settingsHtml = (string) ob_get_clean();
    pairing_check(str_contains($settingsHtml, 'id="admin-settings-search-option-cooperative_friendships"')
        && str_contains($settingsHtml, 'id="admin-setting-result-cooperative_friendships"'),
        'Friendship search result has no visible Settings card.');
    pairing_check(str_contains($settingsHtml, 'data-admin-side-panel-workflow="cooperative_pairing"')
        && str_contains($settingsHtml, 'data-gallery-side-panel-url="/index.php?page=admin_cooperative_galleries&amp;panel=1"')
        && str_contains($settingsHtml, 'href="/index.php?page=admin_cooperative_galleries"'),
        'Friendship Settings card lost panel navigation or its direct-page fallback.');
    $GLOBALS['pair_enabled'] = false;
    pairing_check(!isset(S\admin_settings_specialized_catalog()['cooperative_friendships']),
        'Disabled collaboration still exposes its Settings action.');
    $GLOBALS['pair_enabled'] = true;

    // UI uses the real prepared model and escaped view, with no network on GET.
    pairing_fixture();
    $GLOBALS['pair_admin'] = true;
    $GLOBALS['pair_csrf'] = true;
    $_GET = [];
    $_POST = ['action' => 'invite', 'cooperative_ui' => '1', 'ajax' => '1',
        'base_url' => 'https://b.example/gallery', 'request_id' => S\cooperative_id_generate()];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $ui = pairing_http('Gallery\\Controllers\\cms_admin_cooperative_action');
    pairing_check($ui['ok'] && str_contains($ui['panel_html'], 'data-cooperative-code')
        && str_contains($ui['panel_html'], $ui['invitation_code'])
        && !str_contains($ui['panel_html'], 'pgc_'), 'UI response lost invitation or exposed a system key.');
    $shownAgain = pairing_http('Gallery\\Controllers\\cms_admin_cooperative_action');
    pairing_check($shownAgain['invitation_code'] === $ui['invitation_code']
        && count(M\cooperative_pairing_model_list(0, 50)) === 1, 'Explicit redisplay created another invitation.');
    $networkCount = count($GLOBALS['pair_messages']);
    $model = \Gallery\Controllers\admin_cooperative_view_model();
    $html = \Gallery\Controllers\admin_cooperative_html($model);
    pairing_check(count($GLOBALS['pair_messages']) === $networkCount && !str_contains($html, $ui['invitation_code']), 'GET performed network work or redisplayed invitation authority.');
    pairing_check(str_contains($html, 'Show invitation code')
        && str_contains($html, $ui['pairing']['invitation_id']), 'Pending invitation cannot be explicitly reopened.');
    $model['rows'][0]['base_url'] = '<script>fixture</script>';
    pairing_check(!str_contains(\Gallery\Controllers\admin_cooperative_html($model), '<script>fixture'), 'Peer text was not escaped.');
    pairing_node('b');
    $_POST = ['action' => 'import', 'cooperative_ui' => '1', 'ajax' => '1', 'invitation_code' => $ui['invitation_code']];
    $importUi = pairing_http('Gallery\\Controllers\\cms_admin_cooperative_action');
    pairing_check($importUi['pairing']['state'] === 'received' && str_contains($importUi['panel_html'], 'value="accept"'), 'UI import implicitly approved friendship or omitted acceptance.');
    $_POST = ['action' => 'import', 'cooperative_ui' => '1', 'invitation_code' => '<bad-code>'];
    unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);
    ob_start();
    \Gallery\Controllers\cms_admin_cooperative_action();
    $fallback = ob_get_clean();
    pairing_check(str_contains($fallback, 'data-fixture-shell') && str_contains($fallback, '&lt;bad-code&gt;')
        && !str_contains($fallback, '<bad-code>'), 'Non-JavaScript error discarded or failed to escape user input.');

    // Expired, misaddressed and malformed authority must be refused before any callback.
    pairing_fixture();
    [$id, $created, $imported, $descriptor] = pairing_invitation();
    pairing_node('a');
    \Gallery\Core\db()->exec('UPDATE cooperative_pairings SET expires_at = 1');
    $inspect = ['protocol' => 1, 'action' => 'inspect', 'invitation_id' => $id,
        'sender_id' => $GLOBALS['pair_nodes']['b']['id'], 'recipient_id' => $GLOBALS['pair_nodes']['a']['id']];
    pairing_refuses(/** Run the isolated fixture assertion. @return array<string,mixed>|never Fixture result or expected refusal. */ static fn() => S\cooperative_pairing_receive($inspect, $descriptor['secret']), 'invitation_expired');
    $wrong = array_replace($inspect, ['sender_id' => str_repeat('f', 32)]);
    pairing_refuses(/** Run the isolated fixture assertion. @return array<string,mixed>|never Fixture result or expected refusal. */ static fn() => S\cooperative_pairing_receive($wrong, $descriptor['secret']), 'pairing_unauthorized');
    $wrong = array_replace($inspect, ['protocol' => 99]);
    pairing_refuses(/** Run the isolated fixture assertion. @return array<string,mixed>|never Fixture result or expected refusal. */ static fn() => S\cooperative_pairing_receive($wrong, $descriptor['secret']), 'protocol_unsupported');
    $GLOBALS['pair_enabled'] = false;
    $GLOBALS['pair_db_calls'] = 0;
    pairing_refuses(/** Run the isolated fixture assertion. @return array<string,mixed>|never Fixture result or expected refusal. */ static fn() => S\cooperative_pairing_identity(), 'feature_disabled');
    pairing_check($GLOBALS['pair_db_calls'] === 0, 'OFF path touched storage.');
    $GLOBALS['pair_enabled'] = true;
    S\schema_inspection_reset_request_cache();
    $executor = &S\schema_inspection_query_executor_override();
    $executor = /** Run the isolated fixture assertion. @return array<string,mixed>|never Fixture result or expected refusal. */ static fn() => throw new \RuntimeException('private database diagnostic');
    $count = \Gallery\Core\db()->query('SELECT COUNT(*) FROM cooperative_pairings')->fetchColumn();
    pairing_refuses(/** Run the isolated fixture assertion. @return array<string,mixed>|never Fixture result or expected refusal. */ static fn() => S\cooperative_pairing_create('https://b.example/gallery', S\cooperative_id_generate()), 'schema_unknown');
    pairing_check(\Gallery\Core\db()->query('SELECT COUNT(*) FROM cooperative_pairings')->fetchColumn() === $count, 'Unknown schema mutated invitation state.');

    foreach (['127.0.0.1', '10.1.2.3', '169.254.169.254', '100.64.0.1', '192.0.0.9', '198.18.0.1', '224.0.0.1', '::1'] as $address) {
        pairing_check(!S\outbound_http_ipv4_is_public($address), 'Unsafe destination accepted.');
    }
    pairing_check(S\outbound_http_choose_public_ipv4(['8.8.8.8', '127.0.0.1']) === null, 'Mixed DNS answer accepted.');
    pairing_check(S\outbound_http_choose_public_ipv4(['8.8.8.8']) === '8.8.8.8', 'Public DNS answer denied.');
    echo "Cooperative bilateral pairing and recovery contracts passed.\n";
}
