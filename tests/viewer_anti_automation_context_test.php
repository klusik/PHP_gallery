<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/viewer_anti_automation_context_test.php
 * Module Type: Regression Test
 * Purpose: Verify explicit ticket contexts and their actual controller-owned session publication.
 * Responsibilities:
 *   - Protect one-use, binding, exclusive expiry, retention and refusal semantics without live state.
 * Author: Rudolf Klusal
 */
declare(strict_types=1);

require_once __DIR__ . '/support/viewer_anti_automation_context_fixture.php';
require_once dirname(__DIR__) . '/app/services/viewer_anti_automation.php';
require_once dirname(__DIR__) . '/app/controllers/viewer_accounts.php';

use function Gallery\Tests\ViewerTicketContext\check;
use function Gallery\Tests\ViewerTicketContext\reset;
use function Gallery\Services\viewer_anti_automation_form_issue;
use function Gallery\Services\viewer_anti_automation_challenge_issue;
use function Gallery\Services\viewer_anti_automation_ticket_validate;
use function Gallery\Services\viewer_anti_automation_ticket_decode;
use function Gallery\Services\viewer_anti_automation_context_consume;
use function Gallery\Services\viewer_anti_automation_context_prune;
use function Gallery\Services\viewer_anti_automation_context_register;
use function Gallery\Services\viewer_anti_automation_nonce_fingerprint;
use function Gallery\Services\viewer_anti_automation_authorize_submission;
use function Gallery\Services\viewer_anti_automation_pow_verify;
use const Gallery\Core\VIEWER_ANTI_AUTOMATION_SESSION_NAMESPACE;
use const Gallery\Core\VIEWER_ANTI_AUTOMATION_ACTION_REGISTER;
use const Gallery\Core\VIEWER_ANTI_AUTOMATION_ACTION_RESEND;
use const Gallery\Core\VIEWER_ANTI_AUTOMATION_KIND_FORM;
use const Gallery\Core\VIEWER_ANTI_AUTOMATION_KIND_CHALLENGE;
use const Gallery\Core\VIEWER_ANTI_AUTOMATION_MAX_COUNTER;
use const Gallery\Core\VIEWER_ANTI_AUTOMATION_OUTSTANDING_CAP;
use const Gallery\Core\VIEWER_ANTI_AUTOMATION_PRUNE_CANDIDATE_CAP;

/**
 * Find one bounded real SHA-256 proof for a freshly issued fixture challenge.
 * @param string $action Signed register/resend action.
 * @param array{challenge:string,difficulty:int} $challenge Browser-safe challenge projection.
 * @return string Valid decimal counter, never printed together with the private ticket.
 */
function ticket_context_proof(string $action, array $challenge): string
{
    for ($counter = 0; $counter <= VIEWER_ANTI_AUTOMATION_MAX_COUNTER; $counter++) {
        if (viewer_anti_automation_pow_verify($action, $challenge['challenge'], $challenge['difficulty'], (string) $counter)) {
            return (string) $counter;
        }
    }
    throw new RuntimeException('Bounded fixture proof unavailable.');
}

try {
    reset();
    $sentinel = ['user_id' => 47, 'viewer' => ['id' => 91], 'csrf_token' => 'unrelated-fixture-csrf',
        'language' => 'cs', 'unrelated' => ['keep' => true]];
    $_SESSION = $sentinel;
    $actions = [VIEWER_ANTI_AUTOMATION_ACTION_REGISTER, VIEWER_ANTI_AUTOMATION_ACTION_RESEND];
    foreach ($actions as $action) {
        $own = [];
        $other = [];
        $form = viewer_anti_automation_form_issue($own, $action, 1000);
        $payload = viewer_anti_automation_ticket_decode($form['ticket']);
        $fingerprint = viewer_anti_automation_nonce_fingerprint($payload['n']);
        check(array_keys($own['entries']) === [$fingerprint], 'Only a scoped nonce HMAC indexes private ticket context.');
        check(!str_contains(json_encode($own, JSON_THROW_ON_ERROR), $payload['n']), 'Raw nonce is absent from private retained context.');
        check(viewer_anti_automation_ticket_validate($other, $form['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM, $action, false, 1001) !== null
            && $other === [], 'Signature-only inspection remains non-consuming and is not session authorization.');
        check(viewer_anti_automation_ticket_validate($other, $form['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM, $action, true, 1001) === null,
            'A valid signature cannot authorize another caller context.');
        check(count($own['entries']) === 1, 'A foreign-context refusal cannot consume the issuing context.');
        check(!viewer_anti_automation_context_consume($own, $payload['n'], VIEWER_ANTI_AUTOMATION_KIND_FORM, $action, 999, 1600, 0, 1001),
            'Metadata mismatch cannot consume an otherwise valid nonce.');
        check(viewer_anti_automation_ticket_validate($own, $form['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM,
            $action === 'register' ? 'resend' : 'register', true, 1001) === null, 'Action crossing remains refused.');
        check(viewer_anti_automation_ticket_validate($own, $form['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM, $action, true, 1001) !== null,
            'Matching caller context consumes exactly once.');
        check(viewer_anti_automation_ticket_validate($own, $form['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM, $action, true, 1001) === null,
            'Consumed form cannot replay.');

        $own = [];
        $form = viewer_anti_automation_form_issue($own, $action, 1000);
        check(viewer_anti_automation_ticket_validate($own, $form['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM, $action, false, 1599) !== null,
            'Form remains valid immediately before exclusive expiry.');
        check(viewer_anti_automation_ticket_validate($own, $form['ticket'], VIEWER_ANTI_AUTOMATION_KIND_FORM, $action, true, 1600) === null,
            'Exact expiry timestamp refuses authorization.');
        viewer_anti_automation_context_prune($own, 1600);
        check($own === ['entries' => []], 'Pruning removes exact-expiry authority.');
    }

    $retained = ['entries' => ['malformed' => 'discard'], 'legacy_extra' => true];
    $expected = [];
    for ($index = 0; $index < 20; $index++) {
        $nonce = 'retention-fixture-' . $index;
        viewer_anti_automation_context_register($retained, $nonce, 'form', 'register', 1000 + $index, 2000);
        $expected[] = viewer_anti_automation_nonce_fingerprint($nonce);
    }
    check(array_keys($retained) === ['entries'], 'Pruning drops unrelated or malformed legacy namespace fields.');
    check(array_keys($retained['entries']) === array_slice($expected, -VIEWER_ANTI_AUTOMATION_OUTSTANDING_CAP),
        'Newest twelve valid authorities survive registration retention.');
    $ties = [];
    foreach ($expected as $index => $fingerprint) {
        viewer_anti_automation_context_register($ties, 'tie-' . $index, 'form', 'register', 1000, 2000);
    }
    check(array_key_first($ties['entries']) === viewer_anti_automation_nonce_fingerprint('tie-8'),
        'Equal issue times preserve insertion ordering when evicting oldest entries.');

    $legacy = ['entries' => ['bad-key' => []]];
    for ($index = 0; $index < 70; $index++) {
        $legacy['entries'][str_pad(dechex($index), 64, '0', STR_PAD_LEFT)] = [
            'kind' => 'form', 'action' => 'register', 'issued_at' => 1000 + $index,
            'expires_at' => 2000, 'difficulty' => 0,
        ];
    }
    viewer_anti_automation_context_prune($legacy, 1100);
    check(VIEWER_ANTI_AUTOMATION_PRUNE_CANDIDATE_CAP === 64
        && array_key_first($legacy['entries']) === str_pad(dechex(52), 64, '0', STR_PAD_LEFT)
        && array_key_last($legacy['entries']) === str_pad(dechex(63), 64, '0', STR_PAD_LEFT),
        'Existing sixty-four-valid-candidate normalization precedes twelve-entry retention.');

    foreach ($actions as $action) {
        reset();
        $context = [];
        $challenge = viewer_anti_automation_challenge_issue($context, $action, 10, 1000);
        $old = array_key_first($context['entries']);
        $invalid = viewer_anti_automation_authorize_submission($context, $action, [
            'viewer_aa_challenge_ticket' => $challenge['ticket'], 'viewer_aa_pow_counter' => 'invalid',
        ], '192.0.2.77', 1001);
        check($invalid['result'] === 'challenge_required' && !isset($context['entries'][$old])
            && count($context['entries']) === 1, 'Failed proof consumes old authority before issuing a replacement.');
        $proof = ticket_context_proof($action, $invalid['challenge']);
        $post = ['viewer_aa_challenge_ticket' => $invalid['challenge']['ticket'], 'viewer_aa_pow_counter' => $proof];
        check(viewer_anti_automation_authorize_submission($context, $action, $post, '192.0.2.77', 1002)['result'] === 'allow',
            'One genuine bounded proof permits only the protected action to continue.');
        check(viewer_anti_automation_authorize_submission($context, $action, $post, '192.0.2.77', 1002)['result'] === 'invalid',
            'Proof success cannot be replayed.');
        $challenge = viewer_anti_automation_challenge_issue($context, $action, 10, 2000);
        $early = viewer_anti_automation_authorize_submission($context, $action, [
            'viewer_aa_challenge_ticket' => $challenge['ticket'], 'viewer_aa_fallback' => '1',
        ], '192.0.2.77', 2002);
        check($early['result'] === 'challenge_required' && $early['reason'] === 'fallback_too_fast',
            'Accessible fallback still requires three server-measured seconds.');
        $post = ['viewer_aa_challenge_ticket' => $early['challenge']['ticket'], 'viewer_aa_fallback' => '1'];
        check(viewer_anti_automation_authorize_submission($context, $action, $post, '192.0.2.77', 2005)['result'] === 'allow',
            'Aged replacement fallback can continue once.');
        check(viewer_anti_automation_authorize_submission($context, $action, $post, '192.0.2.77', 2005)['result'] === 'invalid',
            'Accessible fallback authority cannot replay.');

        $context = [];
        $form = viewer_anti_automation_form_issue($context, $action, 3000);
        $calls = $GLOBALS['ticket_context_fixture']['limiter_calls'];
        $denied = viewer_anti_automation_authorize_submission($context, $action, [
            'viewer_aa_form_ticket' => $form['ticket'], $form['honeypot_field'] => ['not-a-string'],
        ], '192.0.2.77', 3005);
        check($denied['result'] === 'suppress' && $context['entries'] === []
            && $GLOBALS['ticket_context_fixture']['limiter_calls'] === $calls, 'Malformed honeypot consumes and suppresses before limiter work.');
        $form = viewer_anti_automation_form_issue($context, $action, 4000);
        $GLOBALS['ticket_context_fixture']['limiter_allowed'] = false;
        $denied = viewer_anti_automation_authorize_submission($context, $action, [
            'viewer_aa_form_ticket' => $form['ticket'],
        ], '192.0.2.77', 4005);
        check($denied['result'] === 'suppress' && $context['entries'] === [], 'Hard limiter denial retains one-use refusal semantics.');
    }
    check($_SESSION === $sentinel, 'Every direct service operation leaves all PHP session state untouched.');
    reset();
    $GLOBALS['ticket_context_fixture']['enabled'] = false;
    $context = ['opaque_legacy_field' => true];
    check(viewer_anti_automation_authorize_submission($context, 'register', [], '192.0.2.77', 1000)['result'] === 'allow'
        && $context === ['opaque_legacy_field' => true], 'Disabled gate does not normalize or mutate a caller namespace.');

    $controllers = [
        'register' => 'Gallery\\Controllers\\cms_viewer_register',
        'resend' => 'Gallery\\Controllers\\cms_viewer_resend_verification',
    ];
    foreach ($controllers as $action => $controller) {
        // Real controller GET prepares form state; only its ticket namespace is published.
        reset();
        $_SESSION = $sentinel;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        $controller();
        check(count($_SESSION[VIEWER_ANTI_AUTOMATION_SESSION_NAMESPACE]['entries']) === 1
            && array_diff_key($_SESSION, [VIEWER_ANTI_AUTOMATION_SESSION_NAMESPACE => true]) === $sentinel,
            'Controller GET publishes only its private ticket namespace.');

        foreach (['csrf', 'unavailable', 'disabled'] as $case) {
            reset();
            $_SESSION = $sentinel;
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['email' => 'person@example.test', 'viewer_csrf_token' => $case === 'csrf' ? 'bad' : 'fixture-viewer-csrf'];
            if ($case === 'unavailable') {
                $GLOBALS['ticket_context_fixture']['available'] = false;
            } elseif ($case === 'disabled') {
                $GLOBALS['ticket_context_fixture']['enabled'] = false;
            }
            $controller();
            check($_SESSION === $sentinel, 'CSRF/readiness refusal and disabled gates leave absent ticket storage absent.');
            check($GLOBALS['ticket_context_fixture']['business_calls'] === ($case === 'disabled' ? 1 : 0),
                'Authentication/CSRF/readiness checks still precede business work.');
        }

        foreach (['honeypot', 'business_exception', 'challenge', 'signing_exception'] as $case) {
            reset();
            $context = [];
            $now = time();
            $_POST = ['email' => 'person@example.test', 'viewer_csrf_token' => 'fixture-viewer-csrf'];
            if ($case === 'signing_exception') {
                $ticket = viewer_anti_automation_challenge_issue($context, $action, 10, $now);
                $_POST['viewer_aa_challenge_ticket'] = $ticket['ticket'];
                $_POST['viewer_aa_pow_counter'] = 'invalid';
                $GLOBALS['ticket_context_fixture']['signature_calls'] = 0;
                // Decoding validates signature #1; replacement signing #2 fails after consumption.
                $GLOBALS['ticket_context_fixture']['signature_throw_at'] = 2;
            } else {
                $ticket = viewer_anti_automation_form_issue($context, $action, $case === 'challenge' ? $now : $now - 5);
                $_POST['viewer_aa_form_ticket'] = $ticket['ticket'];
                if ($case === 'honeypot') {
                    $_POST[$ticket['honeypot_field']] = 'filled';
                }
                $GLOBALS['ticket_context_fixture']['business_throws'] = $case === 'business_exception';
            }
            $old = array_key_first($context['entries']);
            $_SESSION = $sentinel + [VIEWER_ANTI_AUTOMATION_SESSION_NAMESPACE => $context];
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $threw = false;
            try {
                $controller();
            } catch (RuntimeException $exception) {
                check($case === 'signing_exception' && $exception->getMessage() === 'Fixture signing interruption.',
                    'Only the injected signature failure may escape this controller scenario.');
                $threw = true;
            }
            check($threw === ($case === 'signing_exception'), 'Injected failure occurs after matching ticket consumption.');
            check(!isset($_SESSION[VIEWER_ANTI_AUTOMATION_SESSION_NAMESPACE]['entries'][$old]),
                'Controller persists one-use consumption on suppression, challenge, downstream failure and signing interruption.');
            check(array_diff_key($_SESSION, [VIEWER_ANTI_AUTOMATION_SESSION_NAMESPACE => true]) === $sentinel,
                'Controller publication never overwrites unrelated identity, CSRF, language or session fields.');
            check($GLOBALS['ticket_context_fixture']['business_calls'] === ($case === 'business_exception' ? 1 : 0),
                'Suppression, challenge and signing refusal cannot reach registration/resend work.');
        }
    }

    reset();
    $_SESSION = $sentinel;
    $GLOBALS['ticket_context_fixture']['signature_throw_at'] = 1;
    $threw = false;
    try {
        Gallery\Controllers\viewer_anti_automation_form_fields('register');
    } catch (RuntimeException $exception) {
        $threw = $exception->getMessage() === 'Fixture signing interruption.';
    }
    check($threw && count($_SESSION[VIEWER_ANTI_AUTOMATION_SESSION_NAMESPACE]['entries']) === 1,
        'Controller form issuance publishes registered state even if later signing fails.');
    reset();
    $_SESSION = $sentinel;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $GLOBALS['ticket_context_fixture']['viewer'] = ['id' => 91];
    $redirected = false;
    try {
        Gallery\Controllers\cms_viewer_register();
    } catch (Gallery\Tests\ViewerTicketContext\Redirect) {
        $redirected = true;
    }
    check($redirected && $_SESSION === $sentinel, 'Existing Viewer redirect precedes any ticket issuance.');

    reset();
    $_SESSION = $sentinel;
    $invalidAction = false;
    try {
        Gallery\Controllers\viewer_anti_automation_form_fields('not-an-action');
    } catch (InvalidArgumentException) {
        $invalidAction = true;
    }
    check($invalidAction && $_SESSION === $sentinel, 'Invalid action refusal before state mutation must not create ticket storage.');

    $source = (string) file_get_contents(dirname(__DIR__) . '/app/services/viewer_anti_automation.php');
    $globalDependencies = [];
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_VARIABLE
            && in_array($token[1], ['$_SESSION', '$_GET', '$_POST', '$_COOKIE', '$_REQUEST', '$_SERVER', '$GLOBALS'], true)) {
            $globalDependencies[] = $token[1];
        }
    }
    check($globalDependencies === [], 'Ticket service has no request/session/global-state dependency.');
    check(!preg_match('/\\b(?:session_start|session_write_close|session_id|call_user_func)\\s*\\(/', $source),
        'Service does not hide state access or transport behind a callback/session adapter.');
    check(!str_contains($source, 'const VIEWER_ANTI_AUTOMATION_'), 'Immutable ticket policy has no service-local definitions.');
    check(VIEWER_ANTI_AUTOMATION_SESSION_NAMESPACE === 'viewer_anti_automation'
        && VIEWER_ANTI_AUTOMATION_OUTSTANDING_CAP === 12
        && VIEWER_ANTI_AUTOMATION_MAX_COUNTER === 1048575, 'Existing storage key, retention and proof ceiling are unchanged.');
    echo 'PASS Viewer ticket caller contexts and controller storage boundaries: ' . $GLOBALS['ticket_context_assertions'] . " assertions\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL Viewer ticket context: ' . $exception->getMessage() . "\n");
    exit(1);
}
