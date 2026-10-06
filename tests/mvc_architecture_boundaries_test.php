<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/mvc_architecture_boundaries_test.php
 * Module Type: Architecture Regression Test
 * Purpose: Preserve raw observations and precise accepted Core responsibility signals.
 * Responsibilities: Keep unrelated SQL, state, response and filesystem signals actionable.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/check_mvc_boundaries.php';

/**
 * Reject a boundary classification that discards or broadly exempts evidence.
 *
 * @param bool $condition Whether a semantic boundary invariant holds.
 * @param string $message Precise invariant description for a failed fixture.
 * @return void Throws when the boundary invariant fails.
 */
function mvc_architecture_boundary_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$boundaries = [
    'app/request_data.php' => ['request_global'],
    'app/session_context.php' => ['session_global'],
    'app/helpers_request.php' => ['request_global', 'http_response'],
    'app/bootstrap/viewer_identity_context.php' => ['request_global', 'session_global', 'http_response'],
    'app/helpers_runtime.php' => ['http_response'],
    'app/diagnostics/admin_test_run_early.php' => ['request_global', 'filesystem_mutation'],
    'app/integrity.php' => ['filesystem_mutation'],
    'app/runtime/Kernel.php' => ['http_response', 'http_response_status_read'],
];
$signalRules = [
    'request_global' => 'architecture.request_outside_http_boundary',
    'session_global' => 'architecture.session_state_outside_http_boundary',
    'http_response' => 'architecture.response_outside_controller',
    'filesystem_mutation' => 'architecture.filesystem_mutation_outside_service',
    'presentation_output' => 'architecture.presentation_outside_view',
    'pdo_method' => 'architecture.persistence_outside_model',
];

foreach ($boundaries as $path => $expectedAccepted) {
    $signals = [];
    foreach (array_merge(array_keys($signalRules), ['http_response_status_read']) as $signal) {
        $signals[$signal] = ['count' => 1, 'evidence' => [['line' => 7, 'snippet' => 'synthetic ' . $signal]]];
    }
    $record = ['path' => $path, 'role' => \PhpGallery\MvcBoundary\architecture_role_for_path($path), 'signals' => $signals];
    $accepted = \PhpGallery\MvcBoundary\architecture_accepted_boundaries($record);
    $actualAccepted = array_column($accepted, 'signal');
    sort($actualAccepted);
    sort($expectedAccepted);
    mvc_architecture_boundary_assert($actualAccepted === $expectedAccepted, $path . ' accepts only its reviewed responsibility signals');
    foreach ($accepted as $row) {
        mvc_architecture_boundary_assert($row['path'] === $path && $row['count'] === 1, $path . ' preserves accepted signal identity and count');
        mvc_architecture_boundary_assert(trim($row['reason']) !== '' && $row['evidence'] === $signals[$row['signal']]['evidence'], $path . ' retains purpose and evidence');
    }
    $candidates = \PhpGallery\MvcBoundary\architecture_review_candidates($record);
    $candidateRules = array_column($candidates, 'rule');
    foreach ($signalRules as $signal => $rule) {
        $shouldRemain = !in_array($signal, $expectedAccepted, true);
        mvc_architecture_boundary_assert(in_array($rule, $candidateRules, true) === $shouldRemain, $path . ' keeps unrelated ' . $signal . ' actionable');
    }
    mvc_architecture_boundary_assert($record['signals'] === $signals, $path . ' classification does not discard raw observations');
}

// Status observation is distinct from response mutation at the actual token seam.
$readSource = '<?php function observe(): int { return http_response_code(); }';
$writeSource = '<?php function respond(): void { http_response_code(503); }';
$read = \PhpGallery\MvcBoundary\scan_architecture_source($readSource, 'app/runtime/Kernel.php');
$write = \PhpGallery\MvcBoundary\scan_architecture_source($writeSource, 'app/runtime/Kernel.php');
mvc_architecture_boundary_assert(($read['signals']['http_response_status_read']['count'] ?? 0) === 1, 'No-argument status reads remain observable');
mvc_architecture_boundary_assert(!isset($read['signals']['http_response']), 'A status read does not create a mutation signal');
mvc_architecture_boundary_assert(($write['signals']['http_response']['count'] ?? 0) === 1, 'A status argument remains a response mutation');

// The newly admitted Core adapters retain strict persistence rejection.
$sqlSource = '<?php $connection = new \PDO("sqlite::memory:"); $connection->prepare($sql);';
foreach (['app/request_data.php', 'app/session_context.php'] as $path) {
    $findings = \PhpGallery\MvcBoundary\scan_source($sqlSource, $path);
    mvc_architecture_boundary_assert(in_array('core.pdo_construction', array_column($findings, 'rule'), true), $path . ' cannot become an SQL/PDO owner');
}

echo "MVC architecture boundary fixtures passed.\n";
