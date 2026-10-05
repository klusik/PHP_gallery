<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/audit_route_performance.php
 * Module Type: Audit Measurement Validation
 * Purpose: Validate route-lifecycle measurements and deterministic regression limits.
 * Responsibilities: Enforce route identity, outcome, include and memory contracts while observing wall time.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace PhpGallery\Audit;

/**
 * Check that a route emitted its expected product content rather than a generic error page.
 *
 * @param string $body Captured response bytes from the actual application lifecycle.
 * @param array<string,mixed> $definition Registered response kind and stable page markers.
 * @return bool Whether the body satisfies the route's content contract; denial status is validated separately.
 */
function route_response_body_matches(string $body, array $definition): bool
{
    $kind = $definition['response_kind'] ?? null;
    if ($kind === 'denial') {
        return ($definition['expected_outcome'] ?? null) === 'denied';
    }
    if ($kind === 'robots') {
        return preg_match('~\AUser-agent: \*\r?\nAllow: /\r?\n(?:Disallow: [^\r\n]+\r?\n)+Sitemap: https?://[^\r\n]+/sitemap\.xml\r?\n?\z~D', $body) === 1;
    }
    if ($kind === 'image') {
        $image = @getimagesizefromstring($body);
        return is_array($image) && ($image[0] ?? 0) > 0 && ($image[1] ?? 0) > 0
            && in_array($image['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'], true);
    }
    $markers = $definition['response_markers'] ?? null;
    if ($kind !== 'html' || !is_array($markers) || !array_is_list($markers) || $markers === []) {
        return false;
    }
    foreach ($markers as $marker) {
        if (!is_string($marker) || $marker === '' || !str_contains($body, $marker)) {
            return false;
        }
    }
    return true;
}

/**
 * Validate one real request metric against its stable probe identity and deterministic limits.
 *
 * @param array<string,mixed> $metrics Decoded route-lifecycle measurement from an isolated child.
 * @param string $probeId Registered route probe identifier.
 * @param array<string,mixed> $limits Registered hard included-file and memory ceilings.
 * @return array<int,string> Schema or hard-ceiling problems; an empty array means valid.
 */
function route_metric_problems(array $metrics, string $probeId, array $limits): array
{
    $definitions = require __DIR__ . '/audit_route_probe_registry.php';
    $definition = $definitions[$probeId] ?? null;
    if (!is_array($definition)) {
        return ['Unknown route performance probe identity.'];
    }

    $expectedUri = preg_quote((string) $definition['request_uri'], '~');
    $expectedUri = str_replace(['\{gallery_path\}', '\{image_slug\}'],
        ['[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*', '[A-Za-z0-9_-]+'], $expectedUri);
    $validUri = is_string($metrics['request_uri'] ?? null)
        && preg_match('~^' . $expectedUri . '$~D', (string) $metrics['request_uri']) === 1;
    $paths = $metrics['included_paths'] ?? null;
    $pathProblems = [];
    if (!is_array($paths) || !array_is_list($paths)) {
        $pathProblems[] = 'included_paths must be a list.';
    } else {
        $allPathsAreStrings = count(array_filter($paths, 'is_string')) === count($paths);
        if ($allPathsAreStrings && count(array_unique($paths, SORT_STRING)) !== count($paths)) {
            $pathProblems[] = 'included_paths must not contain duplicates.';
        }
        foreach ($paths as $path) {
            if (!is_string($path) || str_contains($path, '..') || str_contains($path, '\\')
                || !(str_starts_with($path, 'app/') || in_array($path, ['public/index.php', 'index.php'], true))
                || !str_ends_with(strtolower($path), '.php')) {
                $pathProblems[] = 'included_paths contains a non-application PHP path.';
                break;
            }
        }
    }

    $requiredSchema = ($metrics['schema_version'] ?? null) === 1
        && ($metrics['scope'] ?? null) === 'cms-run-route-lifecycle'
        && ($metrics['probe'] ?? null) === $probeId
        && ($metrics['route'] ?? null) === $definition['route']
        && ($metrics['expected_outcome'] ?? null) === $definition['expected_outcome']
        && ($metrics['actual_outcome'] ?? null) === $definition['expected_outcome']
        && ($metrics['auth_mode'] ?? null) === $definition['auth_mode']
        && $validUri
        && is_int($metrics['http_status'] ?? null) && $metrics['http_status'] >= 100 && $metrics['http_status'] <= 599
        && is_int($metrics['included_php_files'] ?? null) && $metrics['included_php_files'] >= 1
        && is_array($paths) && count($paths) === $metrics['included_php_files']
        && is_int($metrics['peak_memory_bytes'] ?? null) && $metrics['peak_memory_bytes'] > 0
        && (is_int($metrics['wall_ms'] ?? null) || is_float($metrics['wall_ms'] ?? null))
        && is_finite((float) ($metrics['wall_ms'] ?? NAN)) && (float) $metrics['wall_ms'] >= 0
        && is_string($metrics['php_version'] ?? null)
        && in_array($metrics['php_int_size'] ?? null, [4, 8], true)
        && is_int($metrics['response_body_bytes'] ?? null) && $metrics['response_body_bytes'] >= 0
        && ($definition['expected_outcome'] !== 'success' || $metrics['response_body_bytes'] > 0)
        && is_string($metrics['response_body_sha256'] ?? null)
        && preg_match('/^[a-f0-9]{64}$/D', (string) $metrics['response_body_sha256']) === 1
        && ($metrics['response_contract_matches'] ?? null) === true
        && ($metrics['fatal_error'] ?? null) === false;
    if (!$requiredSchema || $pathProblems !== []) {
        return array_merge(['Invalid route performance measurement schema or route outcome.'], $pathProblems);
    }

    $statusMatchesOutcome = $definition['expected_outcome'] === 'success'
        ? $metrics['http_status'] >= 200 && $metrics['http_status'] < 300
        : in_array($metrics['http_status'], [302, 401, 403, 404], true);
    if (!$statusMatchesOutcome) {
        return ['HTTP status does not match the registered route outcome.'];
    }
    if (!is_int($limits['max_included_php_files'] ?? null) || $limits['max_included_php_files'] < 1
        || !is_int($limits['max_peak_memory_bytes'] ?? null) || $limits['max_peak_memory_bytes'] < 1) {
        return ['Invalid route performance limits.'];
    }

    $problems = [];
    foreach (['included_php_files' => 'max_included_php_files', 'peak_memory_bytes' => 'max_peak_memory_bytes'] as $metric => $limit) {
        if ($metrics[$metric] > $limits[$limit]) {
            $problems[] = $metric . '=' . $metrics[$metric] . ' exceeds ' . $limits[$limit] . '.';
        }
    }
    return $problems;
}

/**
 * Capture the current disposable fixture's route matrix when called as a standalone CLI command.
 *
 * @return int Process exit status for machine-readable CLI capture.
 */
function route_performance_cli(): int
{
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        return 2;
    }
    $label = trim((string) ($GLOBALS['argv'][1] ?? getenv('PHP_GALLERY_ROUTE_PROBE_EVIDENCE') ?: ''));
    if ($label !== '' && !in_array($label, ['before', 'phase3', 'phase4', 'phase5', 'after'], true)) {
        fwrite(STDERR, "Usage: php scripts/audit_route_performance.php [before|phase3|phase4|phase5|after]\n");
        return 2;
    }

    require_once dirname(__DIR__) . '/tests/support/audit_route_probe_fixture.php';
    $definitions = require __DIR__ . '/audit_route_probe_registry.php';
    try {
        $metrics = \GalleryWorkflow\audit_route_probe_fixture_run($definitions, __DIR__ . '/audit_route_probe.php');
        $problems = [];
        foreach ($metrics as $metric) {
            $id = (string) ($metric['probe'] ?? '');
            foreach (route_metric_problems($metric, $id, $definitions[$id] ?? []) as $problem) {
                $problems[] = $id . ': ' . $problem;
            }
        }
        $report = [
            'schema_version' => 1,
            'scope' => 'disposable-mysql-cms-run-route-lifecycle',
            'status' => $problems === [] ? 'PASS' : 'FAIL',
            'captured_at' => gmdate(DATE_ATOM),
            'php_version' => PHP_VERSION,
            'fixture' => 'generated-migrated-disposable-mysql-application',
            'problems' => $problems,
            'routes' => $metrics,
        ];
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if ($label !== '') {
            $root = dirname(__DIR__);
            $path = $root . '/cache/test-audit/issue-69-routes-' . $label . '.json';
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Route evidence directory is unavailable.');
            }
            if (file_put_contents($path, $json, LOCK_EX) === false) {
                throw new \RuntimeException('Route evidence could not be saved.');
            }
        }
        fwrite(STDOUT, $json);
        return $problems === [] ? 0 : 1;
    } catch (\Throwable) {
        fwrite(STDERR, "Route performance capture failed; disposable fixture diagnostics were suppressed.\n");
        return 1;
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(route_performance_cli());
}
