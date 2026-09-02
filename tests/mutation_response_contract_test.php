<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/helpers_mutation.php';

use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_panel_metadata;
use function Gallery\Core\admin_mutation_postcondition;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\admin_wants_json;

/**
 * Fail the focused contract test with a concise diagnostic.
 *
 * @param bool $condition Assertion condition.
 * @param string $message Failure message.
 */
function mutation_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$_POST = [];
$_GET = [];
$_SERVER = [];
mutation_contract_assert(admin_wants_json() === false, 'Plain requests must not be classified as JSON mutations.');

$_POST = ['ajax' => '1'];
mutation_contract_assert(admin_wants_json() === true, 'POST ajax marker must request JSON completion.');
$_POST = [];
$_SERVER['HTTP_ACCEPT'] = 'text/html, application/json;q=0.9';
mutation_contract_assert(admin_wants_json() === true, 'JSON Accept header must request JSON completion.');
$_SERVER = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'];
mutation_contract_assert(admin_wants_json() === true, 'XMLHttpRequest header must request JSON completion.');

require_once __DIR__ . '/../app/controllers/admin_uploads.php';
$_POST = ['panel' => '1'];
$_GET = [];
$_SERVER = [];
mutation_contract_assert(\Gallery\Controllers\admin_wants_json() === true, 'Legacy controller namespace must delegate to the canonical JSON detector during staged migration.');

$mutation = admin_mutation_descriptor('gallery.create', 'gallery', 'create', [123, '123', 0, 124]);
mutation_contract_assert($mutation === [
    'type' => 'gallery.create',
    'entity' => 'gallery',
    'action' => 'create',
    'entity_ids' => [123, 124],
], 'Mutation descriptor must normalize stable entity ids.');

$postcondition = admin_mutation_postcondition('gallery_present', ['gallery_id' => 123]);
$context = admin_mutation_public_gallery_context(55, '/gallery/parent/', $postcondition);
mutation_contract_assert($context === [
    'type' => 'gallery',
    'gallery_id' => 55,
    'render_url' => '/gallery/parent/',
    'render_mode' => 'preserve_view',
    'postcondition' => [
        'type' => 'gallery_present',
        'gallery_id' => 123,
    ],
], 'Gallery context must carry stable identity, render source, mode, and postcondition.');

$unverifiedContext = admin_mutation_public_gallery_context(55, '/gallery/parent/');
mutation_contract_assert(array_key_exists('postcondition', $unverifiedContext) && $unverifiedContext['postcondition'] === null, 'Contexts without a stable observable invariant must declare postcondition=null explicitly.');

$membershipPostcondition = admin_mutation_postcondition('gallery_membership', [
    'gallery_id' => 123,
    'present' => true,
    'count' => 14,
]);
mutation_contract_assert($membershipPostcondition === [
    'type' => 'gallery_membership',
    'gallery_id' => 123,
    'present' => true,
    'count' => 14,
], 'Pagination-safe gallery membership must preserve expected membership and authoritative full count.');

$imageOrderPostcondition = admin_mutation_postcondition('image_order', [
    'image_ids' => [9, '7', 9, 5],
    'count' => 3,
]);
mutation_contract_assert($imageOrderPostcondition === [
    'type' => 'image_order',
    'image_ids' => [9, 7, 5],
    'count' => 3,
], 'Image order postconditions must normalize stable ids while retaining ordered semantics.');

$smartPlacementPostcondition = admin_mutation_postcondition('smart_gallery_presence', [
    'smart_gallery_id' => 81,
    'present' => true,
    'count' => 4,
    'placement' => 'top',
    'placement_order' => 6,
]);
mutation_contract_assert($smartPlacementPostcondition === [
    'type' => 'smart_gallery_presence',
    'smart_gallery_id' => 81,
    'present' => true,
    'count' => 4,
    'placement' => 'top',
    'placement_order' => 6,
], 'Smart Gallery postconditions must carry placement and ordering state, not mere presence.');

$panel = admin_mutation_panel_metadata('gallery-edit', '/admin/edit?id=123', true);
$success = admin_mutation_success_envelope(
    'Created.',
    $mutation,
    $panel,
    [$context],
    ['redirect_url' => '/admin/edit?id=123']
);
mutation_contract_assert(array_keys($success) === ['ok', 'message', 'mutation', 'panel', 'contexts', 'fallback'], 'Success envelope top-level shape changed unexpectedly.');
mutation_contract_assert($success['ok'] === true, 'Success envelope must report ok=true.');
mutation_contract_assert($success['contexts'][0]['gallery_id'] === 55, 'Success envelope must preserve stable affected context identity.');
mutation_contract_assert($success['fallback']['redirect_url'] === '/admin/edit?id=123', 'Direct-page redirect must remain fallback metadata only.');

$error = admin_mutation_error_envelope('Validation failed.', 'validation_failed', $mutation);
mutation_contract_assert(array_keys($error) === ['ok', 'message', 'error', 'error_code', 'mutation', 'panel', 'contexts', 'fallback'], 'Error envelope top-level shape changed unexpectedly.');
mutation_contract_assert($error['ok'] === false, 'Error envelope must report ok=false.');
mutation_contract_assert($error['error'] === 'Validation failed.', 'Compatibility error string must remain available during migration.');
mutation_contract_assert($error['error_code'] === 'validation_failed', 'Error envelope must expose a stable error code.');
$normalizedError = admin_mutation_error_envelope('Failed.', ' Validation FAILED / retry ');
mutation_contract_assert($normalizedError['error_code'] === 'validation_failed_retry', 'Error codes must be normalized to a bounded machine-readable category.');
mutation_contract_assert($error['contexts'] === [], 'Expected mutation errors must not claim synchronized public contexts.');

$threw = false;
try {
    admin_mutation_postcondition('arbitrary_browser_command', []);
} catch (InvalidArgumentException $exception) {
    $threw = true;
}
mutation_contract_assert($threw, 'Unknown postconditions must be rejected instead of creating a generic browser command language.');

echo "mutation_response_contract_test: OK\n";
