<?php

/**
 * Project: PHP Gallery
 * Purpose: Provide offline PHP lifecycle policy for Admin diagnostics.
 * Responsibilities:
 *   - Classify inclusive UTC lifecycle dates without gating requests or probing configuration and databases.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/runtime_support.php
 * Module Type: Service
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 *
 * Offline PHP lifecycle policy shared by existing administrator diagnostics.
 * This advisory policy never gates requests, changes configuration, or probes a
 * database. Dates are inclusive UTC calendar dates from PHP's official schedule.
 */
declare(strict_types=1);

namespace Gallery\Services;

use const Gallery\Core\RUNTIME_SUPPORT_MINIMUM_COMPATIBLE;
use const Gallery\Core\RUNTIME_SUPPORT_MINIMUM_DEPLOYMENT;
use const Gallery\Core\RUNTIME_SUPPORT_PREFERRED_BRANCH;
use const Gallery\Core\RUNTIME_SUPPORT_VERIFIED_ON;
use const Gallery\Core\RUNTIME_SUPPORT_REFERENCE;
use const Gallery\Core\RUNTIME_SUPPORT_SCHEDULE;

require_once dirname(__DIR__) . '/policy_constants.php';

/**
 * Describe compatibility and upstream support without inspecting the host config.
 *
 * Unlisted branches are unknown, even when their version satisfies the minimum;
 * a future version is never automatically declared a maintained deployment.
 * Evaluation before the recorded review is unknown: this is a reviewed policy,
 * not a reconstruction of historical release availability. Patch/vendor suffixes,
 * environment values, paths, cookies and credentials never enter the result.
 *
 * @param int $versionId PHP_VERSION_ID, injectable for deterministic boundary tests.
 * @param ?\DateTimeImmutable $asOf Evaluation instant; null uses the current UTC day.
 * @return array{feature:string,state:string,branch:string,minimum_compatible:string,
 *   minimum_deployment:string,preferred_branch:string,compatible:bool,
 *   deployment_recommended:bool,action_required:bool,active_support_until:?string,
 *   security_support_until:?string,policy_verified_on:string,reference_url:string,
 *   suggested_checks:list<string>} Bounded advisory health model. State is active,
 *   security_only, end_of_life, or unknown; dates are inclusive YYYY-MM-DD or null.
 */
function runtime_support_status(int $versionId = PHP_VERSION_ID, ?\DateTimeImmutable $asOf = null): array
{
    $utc = new \DateTimeZone('UTC');
    $today = ($asOf ?? new \DateTimeImmutable('now', $utc))->setTimezone($utc)->format('Y-m-d');
    $branch = $versionId >= 10000 && $versionId <= 99999
        ? intdiv($versionId, 10000) . '.' . intdiv($versionId % 10000, 100)
        : 'unknown';
    $schedule = RUNTIME_SUPPORT_SCHEDULE[$branch] ?? null;
    $compatible = $branch !== 'unknown' && version_compare($branch, RUNTIME_SUPPORT_MINIMUM_COMPATIBLE, '>=');
    $state = 'unknown';
    if ($schedule !== null && $today >= RUNTIME_SUPPORT_VERIFIED_ON) {
        $state = $today > $schedule['security_until'] ? 'end_of_life'
            : ($schedule['active_until'] !== null && $today <= $schedule['active_until'] ? 'active' : 'security_only');
    }
    $recommended = in_array($state, ['active', 'security_only'], true)
        && version_compare($branch, RUNTIME_SUPPORT_MINIMUM_DEPLOYMENT, '>=');
    return [
        'feature' => 'php_runtime',
        'state' => $state,
        'branch' => $branch,
        'minimum_compatible' => RUNTIME_SUPPORT_MINIMUM_COMPATIBLE,
        'minimum_deployment' => RUNTIME_SUPPORT_MINIMUM_DEPLOYMENT,
        'preferred_branch' => RUNTIME_SUPPORT_PREFERRED_BRANCH,
        'compatible' => $compatible,
        'deployment_recommended' => $recommended,
        'action_required' => !$recommended,
        'active_support_until' => $schedule['active_until'] ?? null,
        'security_support_until' => $schedule['security_until'] ?? null,
        'policy_verified_on' => RUNTIME_SUPPORT_VERIFIED_ON,
        'reference_url' => RUNTIME_SUPPORT_REFERENCE,
        'suggested_checks' => $recommended ? [] : ($state === 'unknown'
            ? ['review_php_support_schedule', 'verify_host_runtime']
            : ['plan_maintained_php_upgrade', 'verify_staging_extensions']),
    ];
}

/**
 * Prepare the same localized lifecycle card and copy-report lines for both Admin surfaces.
 *
 * The nested policy is exactly runtime_support_status()'s bounded result. Labels
 * are plain localized text, never HTML. This is the presentation-data owner;
 * views only escape/render its strings and must not resolve runtime policy.
 *
 * @param int $versionId PHP_VERSION_ID, injectable for obsolete/unknown UI contracts.
 * @param ?\DateTimeImmutable $asOf Evaluation instant; null uses the current UTC day.
 * @return array{policy:array{feature:string,state:string,branch:string,minimum_compatible:string,
 *   minimum_deployment:string,preferred_branch:string,compatible:bool,deployment_recommended:bool,
 *   action_required:bool,active_support_until:?string,security_support_until:?string,
 *   policy_verified_on:string,reference_url:string,suggested_checks:list<string>},
 *   labels:array{title:string,summary:string,deadline:string,baseline:string,guidance:string,
 *   reviewed:string,reference:string,action:string},report_lines:list<string>}
 *   Policy fields retain their documented units/states; report lines reuse the visible labels.
 */
function runtime_support_health_status(int $versionId = PHP_VERSION_ID, ?\DateTimeImmutable $asOf = null): array
{
    $policy = runtime_support_status($versionId, $asOf);
    $stateLabel = match ($policy['state']) {
        'active' => t('admin.runtime_support.state_active', 'Active upstream support'),
        'security_only' => t('admin.runtime_support.state_security_only', 'Upstream security fixes only'),
        'end_of_life' => t('admin.runtime_support.state_end_of_life', 'Upstream support ended'),
        default => t('admin.runtime_support.state_unknown', 'Support status requires review'),
    };
    $labels = [
        'title' => t('admin.runtime_support.title', 'PHP runtime support'),
        'summary' => t('admin.runtime_support.summary', 'PHP {branch}: {state}.', [
            'branch' => $policy['branch'], 'state' => $stateLabel,
        ]),
        'deadline' => $policy['security_support_until'] !== null
            ? t('admin.runtime_support.security_until', 'Upstream security support through {date}.', ['date' => $policy['security_support_until']])
            : t('admin.runtime_support.security_unknown', 'No reviewed security-support deadline is available for this branch.'),
        'baseline' => t('admin.runtime_support.baseline', 'Compatibility minimum: PHP {minimum}. Deployment baseline at policy review: PHP {maintained}; preferred branch: PHP {preferred}.', [
            'minimum' => $policy['minimum_compatible'], 'maintained' => $policy['minimum_deployment'], 'preferred' => $policy['preferred_branch'],
        ]),
        'guidance' => $policy['deployment_recommended']
            ? t('admin.runtime_support.guidance_maintained', 'Keep this branch at its latest patch level and verify enabled extensions in staging.')
            : ($policy['state'] === 'unknown'
                ? t('admin.runtime_support.guidance_review', 'Review the official PHP support schedule and confirm the hosting runtime before deployment.')
                : t('admin.runtime_support.guidance_upgrade', 'Plan a hosting upgrade to a maintained PHP branch and verify the application and enabled extensions in staging.')),
        'reviewed' => t('admin.runtime_support.reviewed', 'Policy reviewed: {date}.', ['date' => $policy['policy_verified_on']]),
        'reference' => t('admin.runtime_support.reference', 'Official PHP support schedule'),
        'action' => $policy['action_required'] ? t('admin.dashboard.badge_action', 'Action') : '',
    ];
    return ['policy' => $policy, 'labels' => $labels, 'report_lines' => [
        $labels['title'], $labels['summary'], $labels['deadline'], $labels['baseline'],
        $labels['guidance'], $labels['reviewed'], $labels['reference'] . ': ' . $policy['reference_url'],
    ]];
}
