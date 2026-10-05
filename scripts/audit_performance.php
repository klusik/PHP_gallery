<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: scripts/audit_performance.php
 * Module Type: Audit Measurement Validation
 * Purpose: Validate clean-process bootstrap measurements and platform-tolerant ceilings.
 * Responsibilities: Check measurement schema and include/memory guards while preserving observed timing.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */

declare(strict_types=1);

namespace PhpGallery\Audit;

/**
 * Validate measurement schema and deterministic guards without gating wall time.
 *
 * @param array<string,mixed> $metrics Decoded clean-process bootstrap measurements.
 * @param string $probeId Expected registered include phase.
 * @param array{max_included_php_files:int,max_peak_memory_bytes:int} $limits Registry-owned include and memory ceilings.
 * @return array<int,string> List of schema errors or exceeded guards; empty means passed.
 */
function performance_metric_problems(array $metrics, string $probeId, array $limits): array
{
    if (($metrics['schema_version'] ?? null) !== 1 || ($metrics['probe'] ?? null) !== $probeId
        || ($metrics['scope'] ?? null) !== 'include-only-before-cms_run'
        || !is_int($metrics['included_php_files'] ?? null) || $metrics['included_php_files'] < 1
        || !is_array($metrics['included_paths'] ?? null)
        || count($metrics['included_paths']) !== $metrics['included_php_files']
        || !is_int($metrics['peak_memory_bytes'] ?? null) || $metrics['peak_memory_bytes'] < 1
        || !is_numeric($metrics['bootstrap_wall_ms'] ?? null) || !is_finite((float) $metrics['bootstrap_wall_ms'])
        || (float) $metrics['bootstrap_wall_ms'] < 0
        || !is_string($metrics['php_version'] ?? null) || !in_array($metrics['php_int_size'] ?? null, [4, 8], true)) {
        return ['Invalid bootstrap performance measurement schema.'];
    }
    foreach ($metrics['included_paths'] as $path) {
        if (!is_string($path) || !str_starts_with($path, 'app/') || str_contains($path, '..')) {
            return ['Invalid application include path in performance measurement.'];
        }
    }
    $problems = [];
    foreach (['included_php_files' => 'max_included_php_files', 'peak_memory_bytes' => 'max_peak_memory_bytes'] as $metric => $limit) {
        if ($metrics[$metric] > $limits[$limit]) {
            $problems[] = $metric . '=' . $metrics[$metric] . ' exceeds ' . $limits[$limit] . '.';
        }
    }
    return $problems;
}
