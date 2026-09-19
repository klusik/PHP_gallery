<?php

/**
 * Project: PHP Gallery
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
function bad_service(): array
{
    return db()->query('SELECT id FROM images')->fetchAll();
}
PHP;
$serviceViolations = scan_source($serviceSql, 'app/services/fixture.php');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($serviceViolations, 'services.direct_db'), 'Service db() access must be rejected.');
mvc_layer_contract_assert(mvc_layer_contract_has_rule($serviceViolations, 'services.sql_literal'), 'Service SQL literals must be rejected.');

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
