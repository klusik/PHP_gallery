<?php

/**
 * Project: PHP Gallery
 * Module Type: Regression Test
 * Purpose: Verify strict MVC scanner behavior and baseline handling.
 * Responsibilities:
 *   - Exercise ownership violations and ensure new findings cannot be baselined away.
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/mvc_layer_contract_test.php
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 */
/**
 * Protect the repository-wide strict MVC layer contract and migration baseline.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/check_mvc_boundaries.php';

use function PhpGallery\MvcBoundary\architecture_review_candidates;
use function PhpGallery\MvcBoundary\architecture_accepted_boundaries;
use function PhpGallery\MvcBoundary\architecture_role_for_path;
use function PhpGallery\MvcBoundary\compare_with_baseline;
use function PhpGallery\MvcBoundary\read_baseline;
use function PhpGallery\MvcBoundary\scan_architecture_source;
use function PhpGallery\MvcBoundary\scan_project;
use function PhpGallery\MvcBoundary\scan_source;

/**
 * Assert one MVC layer contract expectation.
 *
 * @param bool $condition Assertion result.
 * @param string $label Failure label.
 */
function mvc_layer_contract_assert(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException($label);
    }
}

/**
 * Return whether a fixture scan contains one expected rule.
 *
 * @param array<int, array<string, mixed>> $violations Violation list.
 * @param string $rule Rule identifier.
 * @return bool True when the rule was detected.
 */
function mvc_layer_contract_has_rule(array $violations, string $rule): bool
{
    foreach ($violations as $violation) {
        if (($violation['rule'] ?? '') === $rule) {
            return true;
        }
    }
    return false;
}

$controllerDb = <<<'PHP'
<?php
namespace Gallery\Controllers;
use function Gallery\Core\db;
function bad_controller(): void
{
    $stmt = db()->prepare('SELECT id FROM galleries WHERE id = ?');
}
PHP;
$controllerViolations = scan_source($controllerDb, 'app/controllers/fixture.php');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($controllerViolations, 'controllers.direct_db'), 'Controller db() access must be rejected.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($controllerViolations, 'controllers.pdo_method'), 'Controller PDO calls must be rejected.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($controllerViolations, 'controllers.sql_literal'), 'Controller SQL literals must be rejected.');

$serviceSql = <<<'PHP'
<?php
namespace Gallery\Services;
use function Gallery\Core\db;
function bad_service(): array
{
    return db()->query('SELECT id FROM images')->fetchAll();
}
PHP;
$serviceViolations = scan_source($serviceSql, 'app/services/fixture.php');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($serviceViolations, 'services.direct_db'), 'Service db() access must be rejected.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($serviceViolations, 'services.sql_literal'), 'Service SQL literals must be rejected.');

$serviceSessionLeak = <<<'PHP'
<?php
namespace Gallery\Services;
function leaked_service_session(): mixed
{
    return $_SESSION['identity'] ?? null;
}
PHP;
$serviceSessionViolations = scan_source($serviceSessionLeak, 'app/services/session_fixture.php');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($serviceSessionViolations, 'services.session_global'), 'All service session-global access must be rejected after migration.');

$modelSessionLeak = <<<'PHP'
<?php
namespace Gallery\Models;
function leaked_model_session(): mixed
{
    return $_SESSION['identity'] ?? null;
}
PHP;
$modelSessionViolations = scan_source($modelSessionLeak, 'app/models/session_fixture.php');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($modelSessionViolations, 'models.session_global'), 'All model session-global access must be rejected.');

$cleanServiceSession = <<<'PHP'
<?php
namespace Gallery\Services;
function adapter_owned_session(array $sessionContext): mixed
{
    return $sessionContext['identity'] ?? null;
}
PHP;
mvc_layer_contract_assert(scan_source($cleanServiceSession, 'app/services/session_fixture.php') === [], 'Explicit session context data must remain usable in services.');

$nonPdoPrepare = <<<'PHP'
<?php
namespace Gallery\Services;
final class RouteKernel
{
    public function dispatch(object $route): callable
    {
        return $this->prepare($route);
    }

    private function prepare(object $route): callable
    {
        return $route->handler;
    }
}
PHP;
$nonPdoPrepareViolations = scan_source($nonPdoPrepare, 'app/services/fixture.php');
mvc_layer_contract_assert(!mvc_layer_contract_has_rule($nonPdoPrepareViolations, 'services.pdo_method'), 'Ordinary non-PDO prepare methods must not be reported as persistence.');
$nonPdoPrepareRecord = scan_architecture_source($nonPdoPrepare, 'app/runtime/Kernel.php');
mvc_layer_contract_assert((int) ($nonPdoPrepareRecord['signals']['pdo_method']['count'] ?? 0) === 0, 'The runtime inventory must not classify Kernel prepare dispatch as PDO.');
mvc_layer_contract_assert(architecture_review_candidates($nonPdoPrepareRecord) === [], 'The non-PDO Kernel fixture must not create a persistence review candidate.');

$pdoEvidence = <<<'PHP'
<?php
namespace Gallery\Services;
use function Gallery\Core\db;
function typed_pdo_alias(\PDO $queryRunner, string $sql): void
{
    $statementExecutor = $queryRunner;
    $statementExecutor->prepare($sql);
}
function constructed_pdo(string $sql): void
{
    $connectionHandle = new \PDO('sqlite::memory:');
    $connectionHandle->prepare($sql);
}
function canonical_database(string $sql): void
{
    db()->prepare($sql);
}
function literal_sql(): void
{
    $untypedConnection->prepare('SELECT id FROM galleries WHERE id = ?');
}
PHP;
$pdoEvidenceViolations = scan_source($pdoEvidence, 'app/services/fixture.php');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($pdoEvidenceViolations, 'services.pdo_method'), 'Typed PDO aliases, direct PDO construction, db() results, and SQL-bearing prepares must remain detectable.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($pdoEvidenceViolations, 'services.sql_literal'), 'SQL literal detection must remain independent of PDO receiver inference.');
$corePdoEvidence = scan_source($pdoEvidence, 'app/helpers_legacy_probe.php');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($corePdoEvidence, 'core.pdo_method'), 'Core persistence boundaries must retain PDO provenance detection.');

$kernelStatusRead = <<<'PHP'
<?php
function finalize_observer(): void
{
    record_completion(http_response_code() ?: 200);
}
PHP;
$kernelStatusRecord = scan_architecture_source($kernelStatusRead, 'app/runtime/Kernel.php');
mvc_layer_contract_assert((int) ($kernelStatusRecord['signals']['http_response_status_read']['count'] ?? 0) === 1, 'The Kernel lifecycle status read must have its own neutral inventory signal.');
$kernelAccepted = architecture_accepted_boundaries($kernelStatusRecord);
mvc_layer_contract_assert(($kernelAccepted[0]['signal'] ?? '') === 'http_response_status_read', 'The Kernel status read must be shown as an accepted HTTP lifecycle boundary.');
$kernelResponseSource = <<<'PHP'
<?php
http_response_code(204);
PHP;
$kernelResponseRecord = scan_architecture_source($kernelResponseSource, 'app/runtime/Kernel.php');
mvc_layer_contract_assert(
    in_array('http_response', array_column(architecture_accepted_boundaries($kernelResponseRecord), 'signal'), true),
    'Kernel-owned lifecycle response status writes must be documented as an accepted HTTP boundary.'
);

$acceptedRuntimeSignals = [
    'request_global' => ['count' => 1, 'evidence' => [['line' => 5, 'snippet' => '$_GET[\'q\']']]],
    'http_response' => ['count' => 1, 'evidence' => [['line' => 6, 'snippet' => 'header(\'Location: \' . $url, true, 302)']]],
];
$redirectRecord = [
    'path' => 'app/helpers_runtime.php',
    'role' => 'helper',
    'signals' => $acceptedRuntimeSignals,
];
$redirectAccepted = architecture_accepted_boundaries($redirectRecord);
mvc_layer_contract_assert(($redirectAccepted[0]['signal'] ?? '') === 'http_response', 'The Core redirect helper response must be explicitly classified as transport ownership.');
$redirectCandidates = architecture_review_candidates($redirectRecord);
mvc_layer_contract_assert(mvc_layer_contract_has_rule($redirectCandidates, 'architecture.request_outside_http_boundary'), 'An accepted response boundary must not suppress unrelated request signals from the same file.');

$diagnosticsRecord = [
    'path' => 'app/diagnostics/admin_test_run_early.php',
    'role' => 'diagnostics',
    'signals' => [
        'request_global' => ['count' => 1, 'evidence' => [['line' => 8, 'snippet' => '$_SERVER[\'REQUEST_METHOD\']']]],
        'filesystem_mutation' => ['count' => 1, 'evidence' => [['line' => 12, 'snippet' => 'file_put_contents($path, $json)']]],
        'session_global' => ['count' => 1, 'evidence' => [['line' => 14, 'snippet' => '$_SESSION[\'probe\']']]],
    ],
];
$diagnosticsAccepted = architecture_accepted_boundaries($diagnosticsRecord);
mvc_layer_contract_assert(count($diagnosticsAccepted) === 2, 'Early diagnostic request capture and owned writes must be listed as separate accepted signals.');
$diagnosticsCandidates = architecture_review_candidates($diagnosticsRecord);
mvc_layer_contract_assert(mvc_layer_contract_has_rule($diagnosticsCandidates, 'architecture.session_state_outside_http_boundary'), 'Early diagnostic ownership must not blanket-exempt unrelated session signals.');

$requestAdapterRecord = [
    'path' => 'app/request_data.php',
    'role' => architecture_role_for_path('app/request_data.php'),
    'signals' => [
        'request_global' => ['count' => 1, 'evidence' => [['line' => 2, 'snippet' => '$_GET']]],
        'filesystem_mutation' => ['count' => 1, 'evidence' => [['line' => 3, 'snippet' => 'file_put_contents($path, $body)']]],
    ],
];
mvc_layer_contract_assert($requestAdapterRecord['role'] === 'request_adapter', 'Request-data adapter role must be explicit.');
mvc_layer_contract_assert(count(architecture_accepted_boundaries($requestAdapterRecord)) === 1, 'Request-data adapter may accept only its request-global signal.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule(architecture_review_candidates($requestAdapterRecord), 'architecture.filesystem_mutation_outside_service'), 'Request-data adapter filesystem signals must remain actionable.');

$requestHelperRecord = [
    'path' => 'app/helpers_request.php',
    'role' => architecture_role_for_path('app/helpers_request.php'),
    'signals' => [
        'request_global' => ['count' => 1, 'evidence' => [['line' => 2, 'snippet' => '$_SERVER[\'REQUEST_URI\']']]],
        'http_response' => ['count' => 1, 'evidence' => [['line' => 3, 'snippet' => 'setcookie($name, $value)']]],
        'session_global' => ['count' => 1, 'evidence' => [['line' => 4, 'snippet' => '$_SESSION']]],
    ],
];
mvc_layer_contract_assert(count(architecture_accepted_boundaries($requestHelperRecord)) === 2, 'Request compatibility helper may accept only request and cookie/header transport signals.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule(architecture_review_candidates($requestHelperRecord), 'architecture.session_state_outside_http_boundary'), 'Request compatibility helper session signals must remain actionable.');

$sessionAdapterRecord = [
    'path' => 'app/session_context.php',
    'role' => architecture_role_for_path('app/session_context.php'),
    'signals' => [
        'session_global' => ['count' => 1, 'evidence' => [['line' => 2, 'snippet' => '$_SESSION']]],
        'http_response' => ['count' => 1, 'evidence' => [['line' => 3, 'snippet' => 'header($value)']]],
    ],
];
mvc_layer_contract_assert($sessionAdapterRecord['role'] === 'session_adapter', 'Session adapter role must be explicit.');
mvc_layer_contract_assert(count(architecture_accepted_boundaries($sessionAdapterRecord)) === 1, 'Session adapter may accept only its session-global signal.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule(architecture_review_candidates($sessionAdapterRecord), 'architecture.response_outside_controller'), 'Session adapter response signals must remain actionable.');

$viewerIdentityRecord = [
    'path' => 'app/bootstrap/viewer_identity_context.php',
    'role' => architecture_role_for_path('app/bootstrap/viewer_identity_context.php'),
    'signals' => [
        'request_global' => ['count' => 4, 'evidence' => [['line' => 2, 'snippet' => '$_SERVER[\'HTTP_USER_AGENT\']']]],
        'http_response' => ['count' => 1, 'evidence' => [['line' => 3, 'snippet' => 'setcookie($rememberCookie)']]],
        'session_global' => ['count' => 1, 'evidence' => [['line' => 4, 'snippet' => '$_SESSION']]],
        'filesystem_mutation' => ['count' => 1, 'evidence' => [['line' => 5, 'snippet' => 'file_put_contents($path, $value)']]],
    ],
];
mvc_layer_contract_assert(count(architecture_accepted_boundaries($viewerIdentityRecord)) === 3, 'Viewer identity bootstrap may accept only its request, remember-cookie, and session signals.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule(architecture_review_candidates($viewerIdentityRecord), 'architecture.filesystem_mutation_outside_service'), 'Unrelated viewer identity filesystem signals must remain actionable.');

$securityRecord = [
    'path' => 'app/security.php',
    'role' => 'security_compatibility',
    'signals' => [
        'request_global' => ['count' => 1, 'evidence' => [['line' => 22, 'snippet' => '$_POST[\'csrf_token\']']]],
        'session_global' => ['count' => 1, 'evidence' => [['line' => 23, 'snippet' => '$_SESSION[\'csrf_token\']']]],
        'filesystem_mutation' => ['count' => 1, 'evidence' => [['line' => 26, 'snippet' => 'file_put_contents($path, $value)']]],
    ],
];
mvc_layer_contract_assert(count(architecture_accepted_boundaries($securityRecord)) === 2, 'Security request and session ownership must be explicit without accepting filesystem signals.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule(architecture_review_candidates($securityRecord), 'architecture.filesystem_mutation_outside_service'), 'Unrelated security compatibility signals must remain review candidates.');

$viewRequest = <<<'PHP'
<?php
namespace Gallery\Views;
use function Gallery\Services\feature_capability_effective_enabled;
function bad_view(): void
{
    echo $_GET['q'] ?? '';
    feature_capability_effective_enabled('public_search');
}
PHP;
$viewViolations = scan_source($viewRequest, 'app/views/fixture.php');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($viewViolations, 'views.request_global'), 'View request globals must be rejected.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($viewViolations, 'views.service_dependency'), 'Non-presentation service dependencies in views must be rejected.');

$viewStringServiceProbe = <<<'PHP'
<?php
namespace Gallery\Views;
function bad_string_probe(): bool
{
    return function_exists('Gallery\\Services\\feature_capability_effective_enabled');
}
PHP;
$viewStringServiceViolations = scan_source($viewStringServiceProbe, 'app/views/fixture_string_probe.php');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($viewStringServiceViolations, 'views.service_dependency'), 'String-based Service probes in views must be rejected.');

$modelUpward = <<<'PHP'
<?php
namespace Gallery\Models;
use function Gallery\Services\gallery_lookup;
function bad_model(): void
{
    gallery_lookup();
}
PHP;
$modelViolations = scan_source($modelUpward, 'app/models/fixture.php');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($modelViolations, 'models.upward_dependency'), 'Model upward service dependencies must be rejected.');

$allowedView = <<<'PHP'
<?php
namespace Gallery\Views;
use function Gallery\Services\t;
function good_view(array $viewModel): void
{
    echo t('example.label', 'Example') . ($viewModel['value'] ?? '');
}
PHP;
mvc_layer_contract_assert(scan_source($allowedView, 'app/views/fixture.php') === [], 'Translation-only view dependency must remain allowed.');

$legacyHelper = <<<'PHP'
<?php
namespace Gallery\Core;
function legacy_helper_probe(): void
{
    $stmt = db()->prepare('SELECT id FROM galleries WHERE id = ?');
    $_SESSION['probe'] = true;
    file_put_contents('/tmp/probe', 'x');
}
PHP;
$legacyRecord = scan_architecture_source($legacyHelper, 'app/helpers_legacy_probe.php');
mvc_layer_contract_assert(($legacyRecord['role'] ?? '') === 'helper', 'Whole-runtime inventory must classify legacy helper files.');
mvc_layer_contract_assert((int) ($legacyRecord['signals']['direct_db']['count'] ?? 0) === 1, 'Whole-runtime inventory must detect direct db() access.');
mvc_layer_contract_assert((int) ($legacyRecord['signals']['sql_literal']['count'] ?? 0) === 1, 'Whole-runtime inventory must detect SQL literals.');
mvc_layer_contract_assert((int) ($legacyRecord['signals']['session_global']['count'] ?? 0) === 1, 'Whole-runtime inventory must detect session state.');
mvc_layer_contract_assert((int) ($legacyRecord['signals']['filesystem_mutation']['count'] ?? 0) === 1, 'Whole-runtime inventory must detect filesystem mutations.');
$legacyCandidates = architecture_review_candidates($legacyRecord);
mvc_layer_contract_assert(mvc_layer_contract_has_rule($legacyCandidates, 'architecture.persistence_outside_model'), 'Legacy helper persistence must become a review candidate.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($legacyCandidates, 'architecture.session_state_outside_http_boundary'), 'Legacy helper session state must become a review candidate.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($legacyCandidates, 'architecture.filesystem_mutation_outside_service'), 'Legacy helper filesystem mutation must become a review candidate.');
mvc_layer_contract_assert(architecture_role_for_path('app/bootstrap/request.php') === 'bootstrap', 'Bootstrap files must be classified separately from MVC layers.');
mvc_layer_contract_assert(architecture_role_for_path('app/database.php') === 'infrastructure', 'Database infrastructure must be classified explicitly.');
mvc_layer_contract_assert(architecture_role_for_path('public/index.php') === 'entrypoint', 'Public entrypoint must be classified explicitly.');

$root = dirname(__DIR__);
$current = scan_project($root);
$baseline = read_baseline($root . '/scripts/mvc_boundary_baseline.json');
$comparison = compare_with_baseline($current, $baseline);
mvc_layer_contract_assert($comparison['new'] === [], 'Repository contains MVC violations not present in the reviewed baseline.');
mvc_layer_contract_assert($comparison['resolved'] === [], 'Resolved MVC baseline entries must be removed with --refresh-baseline.');

$syntheticNew = $current;
$syntheticNew[] = [
    'signature' => hash('sha256', 'synthetic-new-mvc-violation'),
    'path' => 'app/controllers/synthetic.php',
    'rule' => 'controllers.direct_db',
    'line' => 1,
    'snippet' => 'db()->prepare(...)',
];
$syntheticComparison = compare_with_baseline($syntheticNew, $baseline);
mvc_layer_contract_assert(count($syntheticComparison['new']) === 1, 'Baseline comparison must reject newly introduced violation signatures.');

fwrite(STDOUT, "MVC layer contract checks passed.\n");
