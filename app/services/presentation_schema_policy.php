<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/services/presentation_schema_policy.php
 * Module Type: Service
 *
 * Purpose:
 *   Applies explicit schema policy to optional presentation and reporting features.
 *
 * Responsibilities:
 *   - Keep optional rendering failures separate from security and destructive policy
 *   - Allow safe degraded rendering only when omitting a feature cannot expose data
 *   - Refuse optional-feature writes when required schema is missing or unknown
 *   - Preserve available, missing, unknown, and disabled diagnostics for System Health
 *   - Log bounded inspection failures without SQL, credentials, tokens, or raw exceptions
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - This service contains feature policy, not metadata-query implementation.
 *   - Public/core gallery rendering may continue when an optional presentation feature is omitted safely.
 *   - Optional-feature writes must never use unknown as proof that schema is absent.
 *   - Keep comments and docstrings intact when modifying this file.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;

/**
 * Raised when a Phase 11 feature attempts a write without verified schema.
 */
final class PresentationSchemaUnavailableException extends RuntimeException
{
    /**
     * Create an exception describing an unavailable presentation capability.
     *
     * @param string $feature Stable presentation capability identifier.
     * @param string $state Observed schema capability state.
     * @param string $operation Stable attempted-operation identifier.
     * @param string $message Safe caller-facing exception message.
     */
    public function __construct(
        private readonly string $feature,
        private readonly string $state,
        private readonly string $operation,
        string $message
    ) {
        parent::__construct($message);
    }

    /**
     * Return the affected presentation feature identifier.
     */
    public function feature(): string
    {
        return $this->feature;
    }

    /**
     * Return the observed schema capability state.
     */
    public function state(): string
    {
        return $this->state;
    }

    /**
     * Return the attempted operation identifier.
     */
    public function operation(): string
    {
        return $this->operation;
    }
}

/**
 * Build one aggregate status from required table/column identities.
 *
 * @param string $feature Stable feature key.
 * @param array<string,array<int,string>> $tables Table => required columns.
 * @return array{state:string,feature:string,requirements:array}
 */
function presentation_schema_tables_status(string $feature, array $tables): array
{
    $requirements = [];
    foreach ($tables as $table => $columns) {
        $requirements[] = schema_inspection_table((string) $table);
        foreach ($columns as $column) {
            $requirements[] = schema_inspection_column((string) $table, (string) $column);
        }
    }
    return schema_inspection_feature($feature, $requirements);
}

/**
 * Build one aggregate status from required tables only.
 *
 * @param string $feature Stable feature key.
 * @param array<int,string> $tables Required table names.
 * @return array{state:string,feature:string,requirements:array}
 */
function presentation_schema_table_set_status(string $feature, array $tables): array
{
    return schema_inspection_feature(
        $feature,
        array_map(static fn (string $table): array => schema_inspection_table($table), $tables)
    );
}

/**
 * Return bounded unavailable object names for diagnostics and logs.
 *
 * @param array $status Aggregate schema status.
 * @return array<int,string>
 */
function presentation_schema_affected_objects(array $status): array
{
    $objects = [];
    foreach ((array) ($status['requirements'] ?? []) as $requirement) {
        if (!is_array($requirement) || schema_inspection_is_available($requirement)) {
            continue;
        }
        $table = (string) ($requirement['table'] ?? '');
        $object = (string) ($requirement['object'] ?? '');
        $type = (string) ($requirement['object_type'] ?? '');
        if (
            preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $object) !== 1
        ) {
            continue;
        }
        $objects[] = $type === 'table' || $table === $object ? $table : $table . '.' . $object;
    }
    return array_values(array_unique($objects));
}

/**
 * Record one safe degraded-rendering event for an unknown presentation schema.
 *
 * Missing schema is a normal migration/legacy state and is surfaced by System
 * Health without log noise. Unknown means metadata inspection itself failed and
 * therefore receives one bounded log event per feature/operation/request.
 *
 * @param array $status Aggregate schema status.
 * @param string $operation Stable rendering/reporting operation.
 */
function presentation_schema_log_degraded(array $status, string $operation): void
{
    if (!schema_inspection_is_unknown($status) || !function_exists('Gallery\\Services\\admin_log_event')) {
        return;
    }

    $feature = (string) ($status['feature'] ?? 'presentation_schema');
    if (preg_match('/^[A-Za-z0-9_.-]{1,120}$/D', $feature) !== 1) {
        $feature = 'presentation_schema';
    }
    if (preg_match('/^[A-Za-z0-9_.-]{1,120}$/D', $operation) !== 1) {
        $operation = 'render';
    }

    static $logged = [];
    $key = $feature . '|' . $operation;
    if (isset($logged[$key])) {
        return;
    }
    $logged[$key] = true;

    admin_log_event('warning', 'database.presentation_schema_degraded', 'Optional presentation was degraded because required database schema could not be inspected.', [
        'feature' => $feature,
        'state' => 'unknown',
        'operation' => $operation,
        'affected_objects' => presentation_schema_affected_objects($status),
    ], ['category' => 'database', 'severity' => 'warning']);
}

/**
 * Return true only when an optional presentation capability is verified usable.
 *
 * Missing and unknown both omit the optional feature from output. Unknown also
 * creates bounded diagnostics so a database outage cannot masquerade silently as
 * an intentionally old installation.
 *
 * @param array $status Aggregate schema status.
 * @param string $operation Stable render/read operation.
 */
function presentation_schema_render_available(array $status, string $operation): bool
{
    if (schema_inspection_is_available($status)) {
        return true;
    }
    presentation_schema_log_degraded($status, $operation);
    return false;
}

/**
 * Require complete verified schema before an optional presentation feature writes.
 *
 * @param array $status Aggregate schema status.
 * @param string $operation Stable mutation identifier.
 * @param string $missingMessage User-facing migration message.
 * @param string $unknownMessage User-facing inspection-failure message.
 */
function presentation_schema_assert_write_available(
    array $status,
    string $operation,
    string $missingMessage = 'Required database migration has not been applied.',
    string $unknownMessage = 'Required database schema could not be verified. The operation was not started.'
): void {
    if (schema_inspection_is_available($status)) {
        return;
    }

    if (schema_inspection_is_unknown($status)) {
        presentation_schema_log_degraded($status, $operation);
    }

    throw new PresentationSchemaUnavailableException(
        (string) ($status['feature'] ?? 'presentation_schema'),
        schema_inspection_is_missing($status) ? 'missing' : 'unknown',
        $operation,
        schema_inspection_is_missing($status) ? $missingMessage : $unknownMessage
    );
}

/**
 * Refuse only an indeterminate presentation schema while preserving confirmed legacy absence.
 *
 * This is for optional persistence where a genuinely old installation may safely skip
 * the optional write, but a metadata inspection failure must not be mistaken for that
 * confirmed legacy state.
 *
 * @param array $status Aggregate schema status.
 * @param string $operation Stable mutation identifier.
 * @param string $unknownMessage User-facing inspection-failure message.
 */
function presentation_schema_assert_known(
    array $status,
    string $operation,
    string $unknownMessage = 'Required database schema could not be verified. The operation was not started.'
): void {
    if (!schema_inspection_is_unknown($status)) {
        return;
    }

    presentation_schema_log_degraded($status, $operation);
    throw new PresentationSchemaUnavailableException(
        (string) ($status['feature'] ?? 'presentation_schema'),
        'unknown',
        $operation,
        $unknownMessage
    );
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_gps_exif_schema_status(): array
{
    return presentation_schema_tables_status('presentation.gps_exif', [
        'galleries' => ['gps_map_enabled'],
        'images' => [
            'exif_taken_at', 'exif_camera_make', 'exif_camera_model', 'exif_lens_model',
            'exif_focal_length', 'exif_aperture', 'exif_exposure_time', 'exif_iso',
            'gps_lat', 'gps_lng', 'gps_altitude', 'gps_extracted_at',
        ],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_metadata_organizer_schema_status(): array
{
    return schema_inspection_feature('presentation.metadata_organizer_capture_date', [
        schema_inspection_column('images', 'exif_taken_at'),
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_gps_override_schema_status(): array
{
    return schema_inspection_feature('presentation.gps_override', [
        schema_inspection_column_nullable('galleries', 'gps_map_enabled'),
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_flight_map_schema_status(): array
{
    return presentation_schema_tables_status('presentation.flight_map', [
        'gallery_flight_maps' => [
            'gallery_id', 'map_source_type', 'route_text', 'resolved_points_json',
            'unresolved_points_json', 'point_count', 'resolved_at', 'created_at', 'updated_at',
        ],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_flight_navdata_schema_status(): array
{
    return presentation_schema_tables_status('presentation.flight_navdata', [
        'flight_map_nav_points' => ['id', 'ident', 'kind', 'region', 'latitude', 'longitude', 'source', 'cycle', 'created_at', 'updated_at'],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_voting_schema_status(): array
{
    return presentation_schema_tables_status('presentation.image_voting', [
        'galleries' => ['voting_enabled'],
        'image_votes' => ['id', 'image_id', 'user_id', 'visitor_hash', 'vote', 'created_at', 'updated_at'],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_picture_game_schema_status(): array
{
    $voting = presentation_voting_schema_status();
    $requirements = (array) ($voting['requirements'] ?? []);
    $requirements[] = schema_inspection_column('galleries', 'picture_game_enabled');
    foreach (['id', 'gallery_id', 'image_a_id', 'image_b_id', 'winner_image_id', 'voter_hash', 'created_at'] as $column) {
        $requirements[] = schema_inspection_column('picture_game_votes', $column);
    }
    $requirements[] = schema_inspection_table('picture_game_votes');
    return schema_inspection_feature('presentation.picture_game', $requirements);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_lightbox_override_schema_status(): array
{
    return schema_inspection_feature('presentation.lightbox_override', [
        schema_inspection_column('galleries', 'lightbox_browsing_mode'),
        schema_inspection_column_definition_contains('galleries', 'lightbox_browsing_mode', 'picture_strip'),
        schema_inspection_column_definition_contains('galleries', 'lightbox_browsing_mode', '3d_carousel'),
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_openai_text_schema_status(): array
{
    return presentation_schema_tables_status('presentation.openai_text', [
        'user_openai_text_settings' => ['user_id', 'enabled', 'api_key_cipher', 'api_key_hint', 'model', 'created_at', 'updated_at'],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_openai_image_input_schema_status(): array
{
    return schema_inspection_feature('presentation.openai_image_input', [
        schema_inspection_column('user_openai_text_settings', 'allow_image_input'),
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_ai_image_analysis_schema_status(): array
{
    return presentation_schema_tables_status('presentation.ai_image_analysis', [
        'image_ai_metadata' => [
            'id', 'image_id', 'model_name', 'model_version', 'source_checksum_sha256',
            'source_file_size', 'source_modified_at', 'metadata_json', 'searchable_text',
            'generated_at', 'created_at', 'updated_at',
        ],
        'image_ai_analysis_jobs' => [
            'id', 'gallery_id', 'image_id', 'job_key', 'model_name', 'model_version',
            'source_checksum_sha256', 'source_file_size', 'source_modified_at', 'state',
            'claim_owner', 'claim_token_hash', 'claim_expires_at', 'claimed_at', 'heartbeat_at',
            'progress_percent', 'progress_message', 'attempt_count', 'available_at', 'completed_at',
            'last_error', 'created_at', 'updated_at',
        ],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_navigation_cache_schema_status(): array
{
    return presentation_schema_tables_status('presentation.navigation_cache', [
        'navigation_data_cache' => ['id', 'cache_key', 'ident', 'kind', 'source', 'cycle', 'payload_json', 'expires_at', 'created_at', 'updated_at'],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_navigation_account_schema_status(): array
{
    return presentation_schema_tables_status('presentation.navigation_account', [
        'navigation_data_accounts' => [
            'id', 'user_id', 'provider', 'access_token_cipher', 'refresh_token_cipher', 'id_token_cipher',
            'token_expires_at', 'scope_text', 'claims_json', 'subscription_json', 'package_cycle',
            'package_status', 'package_format', 'package_checked_at', 'connected_at', 'updated_at',
        ],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_navigation_account_delete_schema_status(): array
{
    return presentation_schema_tables_status('presentation.navigation_account_delete', [
        'navigation_data_accounts' => ['user_id', 'provider'],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_navigation_local_schema_status(): array
{
    return presentation_flight_navdata_schema_status();
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_telemetry_settings_schema_status(): array
{
    return presentation_schema_tables_status('presentation.telemetry_settings', [
        'telemetry_settings' => ['setting_key', 'setting_value', 'updated_at'],
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_telemetry_schema_status(): array
{
    return presentation_schema_table_set_status('presentation.telemetry_reporting', [
        'telemetry_settings',
        'telemetry_sessions',
        'telemetry_events',
        'telemetry_hourly_metrics',
        'telemetry_daily_metrics',
        'telemetry_db_query_metrics',
        'telemetry_job_runs',
    ]);
}

/** @return array{state:string,feature:string,requirements:array} */
function presentation_admin_gallery_report_schema_status(): array
{
    return presentation_schema_table_set_status('presentation.admin_gallery_report', [
        'galleries', 'images', 'tags', 'gallery_tags', 'image_tags', 'image_votes', 'picture_game_votes',
        'app_settings', 'admin_logs', 'telemetry_sessions', 'telemetry_hourly_metrics',
        'telemetry_daily_metrics', 'telemetry_db_query_metrics', 'telemetry_job_runs',
        'telemetry_settings', 'navigation_data_accounts', 'navigation_data_cache', 'gallery_flight_maps',
        'image_ai_metadata', 'image_ai_analysis_jobs',
    ]);
}

/**
 * Return the Phase 11 capabilities used by Admin System Health.
 *
 * Resolvers are lazy on purpose. System Health evaluates the feature flag before
 * invoking a resolver, so a configuration-disabled optional feature can report
 * `disabled` without spending metadata queries on tables or columns that cannot
 * affect the current request. Enabled capabilities still share the request-local
 * schema inspection cache.
 *
 * SimBrief intentionally reports route-map persistence readiness separately from
 * its remote draft generation. A missing/unknown route-map schema may omit only
 * the map update; fetching and rendering a description draft remains independent.
 *
 * @return array<string,array{resolver:callable():array,flag:string}>
 */
function presentation_schema_health_definitions(): array
{
    return [
        'presentation_gps_exif' => ['resolver' => static fn(): array => presentation_gps_exif_schema_status(), 'flag' => 'gallery_maps'],
        'presentation_gps_override' => ['resolver' => static fn(): array => presentation_gps_override_schema_status(), 'flag' => 'gallery_maps'],
        'presentation_flight_map' => ['resolver' => static fn(): array => presentation_flight_map_schema_status(), 'flag' => 'flight_maps'],
        'presentation_flight_navdata' => ['resolver' => static fn(): array => presentation_flight_navdata_schema_status(), 'flag' => 'navigation_data'],
        'presentation_image_voting' => ['resolver' => static fn(): array => presentation_voting_schema_status(), 'flag' => 'image_voting'],
        'presentation_picture_game' => ['resolver' => static fn(): array => presentation_picture_game_schema_status(), 'flag' => 'picture_game'],
        'presentation_lightbox_override' => ['resolver' => static fn(): array => presentation_lightbox_override_schema_status(), 'flag' => 'lightbox_modes'],
        'presentation_openai_text' => ['resolver' => static fn(): array => presentation_openai_text_schema_status(), 'flag' => 'openai_text_assist'],
        'presentation_openai_image_input' => ['resolver' => static fn(): array => presentation_openai_image_input_schema_status(), 'flag' => 'openai_text_assist'],
        'presentation_ai_image_analysis' => ['resolver' => static fn(): array => presentation_ai_image_analysis_schema_status(), 'flag' => 'ai_image_metadata'],
        'presentation_simbrief_route_map' => ['resolver' => static fn(): array => presentation_flight_map_schema_status(), 'flag' => 'simbrief'],
        'presentation_navigation_cache' => ['resolver' => static fn(): array => presentation_navigation_cache_schema_status(), 'flag' => 'navigation_data'],
        'presentation_navigation_account' => ['resolver' => static fn(): array => presentation_navigation_account_schema_status(), 'flag' => 'navigation_data'],
        'presentation_telemetry_reporting' => ['resolver' => static fn(): array => presentation_telemetry_schema_status(), 'flag' => 'telemetry'],
        'presentation_admin_gallery_report' => ['resolver' => static fn(): array => presentation_admin_gallery_report_schema_status(), 'flag' => ''],
    ];
}
