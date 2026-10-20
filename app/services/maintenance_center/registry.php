<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/maintenance_center/registry.php
 * Module Type: Service Part
 *
 * Purpose:
 *   Defines and validates the canonical Maintenance Center task registry.
 *
 * Responsibilities:
 *   - Keep task metadata and dependencies reviewable in one registry
 *   - Produce deterministic topological task ordering
 *   - Validate state-machine transitions independently of HTTP code
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Loaded only by app/services/maintenance_center.php.
 *
 * Last Updated:
 *   2026-09-20
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;

/**
 * Return the canonical task definitions.
 *
 * @return array<string,array{group:string,label_key:string,label:string,
 *     risk:string,mutates:bool,resumable:bool,progress_unit:string,
 *     dependencies:list<string>,feature_requirement:?string,
 *     default_selected:bool,required:bool,weight_floor:int,analyzer:string,
 *     executor:string,atomic?:bool}> Registry keyed by stable task key;
 *     atomic marks indivisible database work for interruption control.
 */
function maintenance_center_task_registry(): array
{
    return [
        'preflight' => [
            'group' => 'system',
            'label_key' => 'admin.maintenance_center.task.preflight',
            'label' => 'Preflight safety checks',
            'risk' => 'none',
            'mutates' => false,
            'resumable' => true,
            'progress_unit' => 'checks',
            'dependencies' => [],
            'feature_requirement' => null,
            'default_selected' => true,
            'required' => true,
            'weight_floor' => 5,
            'analyzer' =>
                __NAMESPACE__ . '\\maintenance_center_analyze_preflight',
            'executor' =>
                __NAMESPACE__ . '\\maintenance_center_execute_preflight',
        ],
        'trash.reconcile' => [
            'group' => 'trash',
            'label_key' => 'admin.maintenance_center.task.trash_reconcile',
            'label' => 'Reconcile interrupted Trash transitions',
            'risk' => 'low',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'entries',
            'dependencies' => ['preflight'],
            'feature_requirement' => 'gallery_trash',
            'default_selected' => true,
            'required' => false,
            'weight_floor' => 4,
            'analyzer' =>
                __NAMESPACE__ . '\\maintenance_center_analyze_trash_reconcile',
            'executor' =>
                __NAMESPACE__ . '\\maintenance_center_execute_trash_reconcile',
        ],
        'telemetry.retention' => [
            'group' => 'telemetry',
            'label_key' => 'admin.maintenance_center.task.telemetry',
            'label' => 'Telemetry rollup and retention',
            'risk' => 'low',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'rows',
            'dependencies' => ['preflight'],
            'feature_requirement' => 'telemetry',
            'default_selected' => true,
            'required' => false,
            'weight_floor' => 8,
            'analyzer' =>
                __NAMESPACE__ . '\\maintenance_center_analyze_telemetry',
            'executor' =>
                __NAMESPACE__ . '\\maintenance_center_execute_telemetry',
        ],
        'logs.retention' => [
            'group' => 'logs',
            'label_key' => 'admin.maintenance_center.task.logs',
            'label' => 'Admin log archival and live retention',
            'risk' => 'low',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'days',
            'dependencies' => ['preflight'],
            'feature_requirement' => null,
            'default_selected' => true,
            'required' => false,
            'weight_floor' => 4,
            'analyzer' => __NAMESPACE__ . '\\maintenance_center_analyze_logs',
            'executor' => __NAMESPACE__ . '\\maintenance_center_execute_logs',
        ],
        'trash.retention' => [
            'group' => 'trash',
            'label_key' => 'admin.maintenance_center.task.trash_retention',
            'label' => 'Gallery Trash retention',
            'risk' => 'low',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'entries',
            'dependencies' => ['trash.reconcile'],
            'feature_requirement' => 'gallery_trash',
            'default_selected' => true,
            'required' => false,
            'weight_floor' => 5,
            'analyzer' =>
                __NAMESPACE__ . '\\maintenance_center_analyze_trash_retention',
            'executor' =>
                __NAMESPACE__ . '\\maintenance_center_execute_trash_retention',
        ],
        'security.cleanup' => [
            'group' => 'security',
            'label_key' => 'admin.maintenance_center.task.security',
            'label' => 'Security and session retention',
            'risk' => 'low',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'batches',
            'dependencies' => ['preflight'],
            'feature_requirement' => null,
            'default_selected' => true,
            'required' => false,
            'weight_floor' => 8,
            'analyzer' =>
                __NAMESPACE__ . '\\maintenance_center_analyze_security',
            'executor' =>
                __NAMESPACE__ . '\\maintenance_center_execute_security',
        ],
        'downloads.cache' => [
            'group' => 'cache',
            'label_key' => 'admin.maintenance_center.task.downloads',
            'label' => 'Download and cache cleanup',
            'risk' => 'low',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'entries',
            'dependencies' => ['preflight'],
            'feature_requirement' => null,
            'default_selected' => true,
            'required' => false,
            'weight_floor' => 10,
            'analyzer' =>
                __NAMESPACE__ . '\\maintenance_center_analyze_downloads',
            'executor' =>
                __NAMESPACE__ . '\\maintenance_center_execute_downloads',
        ],
        'thumbnail.metadata' => [
            'group' => 'media',
            'label_key' => 'admin.maintenance_center.task.thumbnail_metadata',
            'label' => 'Thumbnail metadata cleanup',
            'risk' => 'low',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'rows',
            'dependencies' => ['preflight'],
            'feature_requirement' => null,
            'default_selected' => true,
            'required' => false,
            'weight_floor' => 6,
            'analyzer' =>
                __NAMESPACE__
                    . '\\maintenance_center_analyze_thumbnail_metadata',
            'executor' =>
                __NAMESPACE__
                    . '\\maintenance_center_execute_thumbnail_metadata',
        ],
        'music.repository' => [
            'group' => 'media',
            'label_key' => 'admin.maintenance_center.task.music_repository',
            'label' => 'Move gallery audio to the music repository',
            'risk' => 'low',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'tracks',
            'dependencies' => ['preflight'],
            'feature_requirement' => 'gallery_music',
            // The proposed safe move is selected by default; the Admin may
            // deselect it before approval, and execution revalidates the plan.
            'default_selected' => true,
            'required' => false,
            'weight_floor' => MAINTENANCE_CENTER_MUSIC_WEIGHT,
            'analyzer' => __NAMESPACE__
                . '\\maintenance_center_analyze_music_repository',
            'executor' => __NAMESPACE__
                . '\\maintenance_center_execute_music_repository',
        ],
        'media.attachment_usage' => [
            'group' => 'media',
            'label_key' => 'admin.maintenance_center.task.attachment_usage',
            'label' => 'Reconcile gallery media counts',
            'risk' => 'low',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'galleries',
            'dependencies' => ['preflight'],
            'feature_requirement' => null,
            'default_selected' => true,
            'required' => false,
            'weight_floor' => MAINTENANCE_CENTER_ATTACHMENT_USAGE_WEIGHT,
            'analyzer' => __NAMESPACE__
                . '\\maintenance_center_analyze_attachment_usage',
            'executor' => __NAMESPACE__
                . '\\maintenance_center_execute_attachment_usage',
        ],
        'media.deep_verify' => [
            'group' => 'media',
            'label_key' => 'admin.maintenance_center.task.media_deep',
            'label' => 'Deep thumbnail verification',
            'risk' => 'none',
            'mutates' => false,
            'resumable' => true,
            'progress_unit' => 'images',
            'dependencies' => ['thumbnail.metadata'],
            'feature_requirement' => null,
            'default_selected' => false,
            'required' => false,
            'weight_floor' => 20,
            'analyzer' =>
                __NAMESPACE__ . '\\maintenance_center_analyze_media_deep',
            'executor' =>
                __NAMESPACE__ . '\\maintenance_center_execute_media_deep',
        ],
        'database.logical' => [
            'group' => 'database',
            'label_key' => 'admin.maintenance_center.task.database_logical',
            'label' => 'Safe database logical cleanup',
            'risk' => 'low',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'rows',
            'dependencies' => [
                'telemetry.retention',
                'logs.retention',
                'trash.retention',
                'security.cleanup',
                'downloads.cache',
                'thumbnail.metadata',
            ],
            'feature_requirement' => 'advanced_database_maintenance',
            'default_selected' => true,
            'required' => false,
            'weight_floor' => 12,
            'analyzer' =>
                __NAMESPACE__ . '\\maintenance_center_analyze_database_logical',
            'executor' =>
                __NAMESPACE__ . '\\maintenance_center_execute_database_logical',
        ],
        'maintenance.history' => [
            'group' => 'system',
            'label_key' => 'admin.maintenance_center.task.history',
            'label' => 'Maintenance Center history retention',
            'risk' => 'low',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'jobs',
            'dependencies' => ['preflight'],
            'feature_requirement' => null,
            'default_selected' => true,
            'required' => false,
            'weight_floor' => 2,
            'analyzer' =>
                __NAMESPACE__ . '\\maintenance_center_analyze_history',
            'executor' =>
                __NAMESPACE__ . '\\maintenance_center_execute_history',
        ],
        'database.inventory' => [
            'group' => 'database',
            'label_key' => 'admin.maintenance_center.task.database_inventory',
            'label' => 'Refresh database inventory',
            'risk' => 'none',
            'mutates' => false,
            'resumable' => true,
            'progress_unit' => 'tables',
            'dependencies' => ['database.logical', 'maintenance.history'],
            'feature_requirement' => 'advanced_database_maintenance',
            'default_selected' => true,
            'required' => false,
            'weight_floor' => 6,
            'analyzer' =>
                __NAMESPACE__
                    . '\\maintenance_center_analyze_database_inventory',
            'executor' =>
                __NAMESPACE__
                    . '\\maintenance_center_execute_database_inventory',
        ],
        'database.analyze' => [
            'group' => 'database',
            'label_key' => 'admin.maintenance_center.task.database_analyze',
            'label' => 'Database statistics refresh',
            'risk' => 'medium',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'tables',
            'dependencies' => ['database.inventory'],
            'feature_requirement' => 'advanced_database_maintenance',
            'default_selected' => true,
            'required' => false,
            'weight_floor' => 6,
            'analyzer' =>
                __NAMESPACE__ . '\\maintenance_center_analyze_database_analyze',
            'executor' =>
                __NAMESPACE__ . '\\maintenance_center_execute_database_analyze',
            'atomic' => true,
        ],
        'database.optimize' => [
            'group' => 'database',
            'label_key' => 'admin.maintenance_center.task.database_optimize',
            'label' => 'Database physical optimization',
            'risk' => 'medium',
            'mutates' => true,
            'resumable' => true,
            'progress_unit' => 'tables',
            'dependencies' => ['database.analyze'],
            'feature_requirement' => 'advanced_database_maintenance',
            'default_selected' => true,
            'required' => false,
            'weight_floor' => 8,
            'analyzer' =>
                __NAMESPACE__
                    . '\\maintenance_center_analyze_database_optimize',
            'executor' =>
                __NAMESPACE__
                    . '\\maintenance_center_execute_database_optimize',
            'atomic' => true,
        ],
        'verify' => [
            'group' => 'system',
            'label_key' => 'admin.maintenance_center.task.verify',
            'label' => 'Postcondition verification and report',
            'risk' => 'none',
            'mutates' => false,
            'resumable' => true,
            'progress_unit' => 'checks',
            'dependencies' => ['preflight'],
            'feature_requirement' => null,
            'default_selected' => true,
            'required' => true,
            'weight_floor' => 10,
            'analyzer' => __NAMESPACE__ . '\\maintenance_center_analyze_verify',
            'executor' => __NAMESPACE__ . '\\maintenance_center_execute_verify',
        ],
    ];
}

/** Validate the registry and return a deterministic dependency order.
 * @param array<string,array<string,mixed>>|null $registry Optional task registry keyed by stable task identifiers.
 * @return list<string> Task keys in deterministic dependency order.
 */
function maintenance_center_registry_order(?array $registry = null): array
{
    $registry ??= maintenance_center_task_registry();
    foreach ($registry as $key => $task) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]*$/D', (string) $key) !== 1) {
            throw new RuntimeException(
                'Maintenance Center task key is invalid.',
            );
        }
        foreach ((array) ($task['dependencies'] ?? []) as $dependency) {
            if (!isset($registry[$dependency])) {
                throw new RuntimeException('Maintenance Center task dependency is missing: '
                    . $dependency);
            }
        }
    }

    $ordered = [];
    $visiting = [];
    $visited = [];
    $visit = static function (string $key) use (
        &$visit,
        &$ordered,
        &$visiting,
        &$visited,
        $registry,
    ): void {
        if (isset($visited[$key])) {
            return;
        }
        if (isset($visiting[$key])) {
            throw new RuntimeException(
                'Maintenance Center task dependency cycle detected.',
            );
        }
        $visiting[$key] = true;
        foreach ((array) (
            $registry[$key]['dependencies'] ?? []
        ) as $dependency) {
            $visit((string) $dependency);
        }
        unset($visiting[$key]);
        $visited[$key] = true;
        $ordered[] = $key;
    };
    foreach (array_keys($registry) as $key) {
        $visit((string) $key);
    }
    return $ordered;
}

/** Return true when one server-side state-machine transition is valid. */
function maintenance_center_transition_allowed(string $from, string $to): bool
{
    $allowed = [
        'analyzing' => ['ready', 'failed', 'cancelled'],
        'ready' => ['running', 'cancelled'],
        'running' => ['paused', 'completed', 'failed', 'cancelled'],
        'paused' => ['running', 'failed', 'cancelled'],
        'completed' => [],
        'failed' => [],
        'cancelled' => [],
    ];
    return isset($allowed[$from]) && in_array($to, $allowed[$from], true);
}

/** Throw if a proposed server-side transition is invalid.
 * @param string $from Current state-machine status.
 * @param string $to Proposed next state-machine status.
 * @return void Returns no value; completes the operation or throws when its preconditions fail.
 */
function maintenance_center_assert_transition(string $from, string $to): void
{
    if (!maintenance_center_transition_allowed($from, $to)) {
        throw new RuntimeException(
            'Invalid Maintenance Center state transition: '
                . $from
                . ' -> '
                . $to,
        );
    }
}

/** Expand selected tasks with required dependencies and return registry order.
 * @param list<string> $selectedKeys Task keys selected by the administrator.
 * @param list<array{key:string,available:bool}> $planTasks Analyzed plan tasks available for selection.
 * @return list<string> Selected task keys in dependency order, including available required dependencies.
 */
function maintenance_center_expand_selection(
    array $selectedKeys,
    array $planTasks,
): array {
    $registry = maintenance_center_task_registry();
    $planByKey = [];
    foreach ($planTasks as $planTask) {
        if (is_array($planTask) && isset($planTask['key'])) {
            $planByKey[(string) $planTask['key']] = $planTask;
        }
    }
    $selected = [];
    foreach ($selectedKeys as $key) {
        $key = (string) $key;
        if (
            isset($registry[$key], $planByKey[$key])
            && !empty($planByKey[$key]['available'])
        ) {
            $selected[$key] = true;
        }
    }
    foreach ($registry as $key => $task) {
        if (
            !empty($task['required'])
            && isset($planByKey[$key])
            && !empty($planByKey[$key]['available'])
        ) {
            $selected[$key] = true;
        }
    }
    $expand = static function (string $key) use (
        &$expand,
        &$selected,
        $registry,
        $planByKey,
    ): void {
        foreach ((array) (
            $registry[$key]['dependencies'] ?? []
        ) as $dependency) {
            if (
                !isset($planByKey[$dependency])
                || empty($planByKey[$dependency]['available'])
            ) {
                continue;
            }
            if (!isset($selected[$dependency])) {
                $selected[$dependency] = true;
                $expand((string) $dependency);
            }
        }
    };
    foreach (array_keys($selected) as $key) {
        $expand((string) $key);
    }
    return array_values(array_filter(
        maintenance_center_registry_order($registry),
        static fn(string $key): bool => isset($selected[$key]),
    ));
}
