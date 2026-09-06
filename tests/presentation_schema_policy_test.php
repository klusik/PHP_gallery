<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/presentation_schema_policy_test.php
 * Module Type: Test
 *
 * Purpose:
 *   Verifies the Phase 11 three-state policy for optional presentation/reporting schema.
 *
 * Responsibilities:
 *   - Prove available, missing, and unknown are not collapsed together
 *   - Prove safe read degradation differs from write authorization
 *   - Prove request-local schema metadata caching bounds repeated feature checks
 *   - Protect the Phase 11 capability registry and converted service boundaries
 *   - Keep raw database failures and credential-like text out of presentation diagnostics
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
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This test is database-free and uses the schema inspection executor seam.
 *
 * Last Updated:
 *   2026-08-12
 */

declare(strict_types=1);

namespace Gallery\Services {
    /** @var array<int,array<string,mixed>> */
    $presentationSchemaPolicyTestLogs = [];

    /**
     * Capture the bounded presentation log event without loading the full logging stack.
     *
     * @param string $level Log level.
     * @param string $eventKey Event key.
     * @param string $message Message.
     * @param array<string,mixed> $context Context.
     * @param array<string,mixed> $options Options.
     */
    function admin_log_event(string $level, string $eventKey, string $message, array $context = [], array $options = []): void
    {
        global $presentationSchemaPolicyTestLogs;
        $presentationSchemaPolicyTestLogs[] = compact('level', 'eventKey', 'message', 'context', 'options');
    }
}

namespace {
    require_once __DIR__ . '/support/module_source.php';

    use Gallery\Services\PresentationSchemaUnavailableException;
    use function Gallery\Services\presentation_picture_game_schema_status;
    use function Gallery\Services\presentation_schema_assert_known;
    use function Gallery\Services\presentation_schema_assert_write_available;
    use function Gallery\Services\presentation_schema_health_definitions;
    use function Gallery\Services\presentation_schema_render_available;
    use function Gallery\Services\presentation_voting_schema_status;
    use function Gallery\Services\schema_inspection_is_available;
    use function Gallery\Services\schema_inspection_is_missing;
    use function Gallery\Services\schema_inspection_is_unknown;
    use function Gallery\Services\schema_inspection_set_query_executor_for_tests;

    require_once __DIR__ . '/../app/services/schema_inspection.php';
    require_once __DIR__ . '/../app/services/presentation_schema_policy.php';

    /**
     * Fail the test process with one message.
     */
    function presentation_policy_fail(string $message): never
    {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }

    /**
     * Require a boolean condition.
     */
    function presentation_policy_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            presentation_policy_fail($message);
        }
    }

    $queryCount = 0;
    schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object, string $token = '') use (&$queryCount): bool {
        $queryCount++;
        return true;
    });
    $available = presentation_voting_schema_status();
    presentation_policy_assert(schema_inspection_is_available($available), 'Complete voting schema must be available.');
    presentation_policy_assert($queryCount === 10, 'Voting capability must use exactly ten first-use metadata probes.');
    presentation_voting_schema_status();
    presentation_policy_assert($queryCount === 10, 'Repeated voting inspection in one request must use the request-local cache.');
    presentation_policy_assert(presentation_schema_render_available($available, 'test_render'), 'Available optional schema must render.');

    schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object, string $token = ''): bool {
        return !($table === 'galleries' && $object === 'voting_enabled');
    });
    $missing = presentation_voting_schema_status();
    presentation_policy_assert(schema_inspection_is_missing($missing), 'Confirmed absent voting column must produce missing state.');
    presentation_policy_assert(!presentation_schema_render_available($missing, 'test_missing_render'), 'Missing optional schema must omit the optional renderer.');
    presentation_schema_assert_known($missing, 'test_optional_legacy_write');
    $missingWriteBlocked = false;
    try {
        presentation_schema_assert_write_available($missing, 'test_required_write');
    } catch (PresentationSchemaUnavailableException $exception) {
        $missingWriteBlocked = $exception->state() === 'missing';
    }
    presentation_policy_assert($missingWriteBlocked, 'A write that requires the optional schema must reject confirmed missing state.');

    $secretMarker = 'SECRET_DATABASE_PASSWORD_SHOULD_NEVER_APPEAR';
    schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object, string $token = '') use ($secretMarker): bool {
        if ($table === 'image_votes' && $object === 'visitor_hash') {
            throw new RuntimeException('permission denied ' . $secretMarker . ' mysql://user:password@host/gallery');
        }
        return true;
    });
    $unknown = presentation_voting_schema_status();
    presentation_policy_assert(schema_inspection_is_unknown($unknown), 'Inspection exception must produce unknown state.');
    presentation_policy_assert(!presentation_schema_render_available($unknown, 'test_unknown_render'), 'Unknown optional read schema must omit only the optional renderer.');
    $unknownWriteBlocked = false;
    try {
        presentation_schema_assert_known($unknown, 'test_unknown_write');
    } catch (PresentationSchemaUnavailableException $exception) {
        $unknownWriteBlocked = $exception->state() === 'unknown';
    }
    presentation_policy_assert($unknownWriteBlocked, 'Unknown optional schema must never authorize a dependent write.');

    global $presentationSchemaPolicyTestLogs;
    $encodedLogs = json_encode($presentationSchemaPolicyTestLogs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    presentation_policy_assert(str_contains($encodedLogs, 'database.presentation_schema_degraded'), 'Unknown presentation schema must create a bounded diagnostic log event.');
    presentation_policy_assert(!str_contains($encodedLogs, $secretMarker), 'Presentation diagnostics must not contain raw exception text.');
    presentation_policy_assert(!str_contains($encodedLogs, 'mysql://'), 'Presentation diagnostics must not contain credential-bearing DSNs.');

    $healthQueryCount = 0;
    schema_inspection_set_query_executor_for_tests(static function (string $objectType, string $table, string $object, string $token = '') use (&$healthQueryCount): bool {
        $healthQueryCount++;
        return true;
    });
    $definitions = presentation_schema_health_definitions();
    presentation_policy_assert($healthQueryCount === 0, 'Building the Phase 11 health registry must be lazy and issue no metadata queries by itself.');
    $expectedHealthKeys = [
        'presentation_gps_exif',
        'presentation_gps_override',
        'presentation_flight_map',
        'presentation_flight_navdata',
        'presentation_image_voting',
        'presentation_picture_game',
        'presentation_lightbox_override',
        'presentation_openai_text',
        'presentation_openai_image_input',
        'presentation_ai_image_analysis',
        'presentation_simbrief_route_map',
        'presentation_navigation_cache',
        'presentation_navigation_account',
        'presentation_telemetry_reporting',
        'presentation_admin_gallery_report',
    ];
    presentation_policy_assert(array_keys($definitions) === $expectedHealthKeys, 'Phase 11 System Health registry changed unexpectedly.');
    $votingResolver = $definitions['presentation_image_voting']['resolver'] ?? null;
    presentation_policy_assert(is_callable($votingResolver), 'Phase 11 health entries must expose lazy schema resolvers.');
    $votingResolver();
    presentation_policy_assert($healthQueryCount === 10, 'Resolving only voting health must inspect only the ten voting metadata requirements.');

    schema_inspection_set_query_executor_for_tests(static fn (string $objectType, string $table, string $object, string $token = ''): bool => true);
    $pictureGame = presentation_picture_game_schema_status();
    presentation_policy_assert(schema_inspection_is_available($pictureGame), 'Complete picture-game schema must remain available through aggregate composition.');

    $convertedFiles = [
        'app/services/exif.php',
        'app/services/flight_maps.php',
        'app/services/picture_game.php',
        'app/services/gallery_lightbox_mode.php',
        'app/services/gallery_sidecars.php',
        'app/services/gallery_metadata_organizer.php',
        'app/services/openai_text_assist.php',
        'app/services/ai_image_analysis.php',
        'app/services/navigation_data.php',
        'app/services/telemetry.php',
        'app/services/telemetry_settings.php',
        'app/services/telemetry_rollup.php',
    ];
    foreach ($convertedFiles as $relativePath) {
        // module_source() also covers services that are split into part files.
        $source = module_source(__DIR__ . '/../' . $relativePath);
        presentation_policy_assert(!preg_match('/\bdb_(?:table|column)_exists\s*\(/', $source), $relativePath . ' still contains a legacy boolean schema probe.');
        presentation_policy_assert(!str_contains($source, 'SHOW COLUMNS'), $relativePath . ' still contains a direct SHOW COLUMNS schema probe.');
    }

    // The Admin report service is split into part files; assert against the whole module.
    $reportSource = module_source(__DIR__ . '/../app/services/admin_gallery_report.php');
    presentation_policy_assert(!preg_match('/\bdb_(?:table|column)_exists\s*\(/', $reportSource), 'Admin report must use structured named-object inspection.');
    presentation_policy_assert(str_contains($reportSource, 'information_schema.TABLES'), 'Admin report must retain its explicitly justified dynamic base-table inventory query.');
    presentation_policy_assert(!str_contains($reportSource, '$exception->getMessage()'), 'Admin report must not export raw database exception messages.');

    $uploadAutomationSource = file_get_contents(__DIR__ . '/../app/controllers/upload_automation.php') ?: '';
    $aiActionStart = strpos($uploadAutomationSource, 'function upload_automation_handle_ai_action');
    $aiActionEnd = $aiActionStart === false ? false : strpos($uploadAutomationSource, '/**', $aiActionStart + 1);
    $aiActionSource = ($aiActionStart !== false && $aiActionEnd !== false)
        ? substr($uploadAutomationSource, $aiActionStart, $aiActionEnd - $aiActionStart)
        : '';
    presentation_policy_assert(str_contains($aiActionSource, 'presentation_ai_image_analysis_schema_status'), 'AI worker endpoint must distinguish Phase 11 schema state before queue mutations.');
    presentation_policy_assert(!str_contains($aiActionSource, '$exception->getMessage()'), 'AI worker endpoint must not return or log raw service/database exception text.');

    $sidecarSource = file_get_contents(__DIR__ . '/../app/services/gallery_sidecars.php') ?: '';
    presentation_policy_assert(str_contains($sidecarSource, 'gallery_sidecar_import.voting_enabled'), 'Gallery sidecar import must verify voting storage before enabling voting.');
    presentation_policy_assert(str_contains($sidecarSource, 'gallery_sidecar_import.lightbox_override'), 'Gallery sidecar import must not silently discard an explicit lightbox override on unknown schema.');
    presentation_policy_assert(str_contains($sidecarSource, 'gallery_create.voting_enabled'), 'Gallery creation must verify voting storage before enabling voting.');
    presentation_policy_assert(str_contains($sidecarSource, 'gallery_create.lightbox_override'), 'Gallery creation must verify explicit lightbox override schema before persistence.');

    $organizerSource = file_get_contents(__DIR__ . '/../app/services/gallery_metadata_organizer.php') ?: '';
    presentation_policy_assert(str_contains($organizerSource, 'gallery_metadata_organizer.inherit_lightbox_override'), 'Metadata organizer child creation must distinguish unknown lightbox schema from confirmed legacy absence.');

    $bulkGallerySource = file_get_contents(__DIR__ . '/../app/controllers/admin_galleries_bulk.php') ?: '';
    presentation_policy_assert(!str_contains($bulkGallerySource, 'admin_feature_schema_ready()'), 'Picture-game bulk mutation must not depend on unrelated legacy readiness booleans.');
    presentation_policy_assert(str_contains($bulkGallerySource, 'presentation_picture_game_schema_status'), 'Picture-game bulk mutation must use the exact Phase 11 capability status.');

    $dashboardSource = file_get_contents(__DIR__ . '/../app/services/admin_dashboard.php') ?: '';
    $diagnosticsSource = file_get_contents(__DIR__ . '/../app/controllers/admin_diagnostics.php') ?: '';
    presentation_policy_assert(str_contains($dashboardSource, 'admin_presentation_schema_health_statuses'), 'System Health must include the Phase 11 presentation registry.');
    presentation_policy_assert(str_contains($diagnosticsSource, 'Optional presentation and reporting database status'), 'Runtime Diagnostics copy report must include Phase 11 readiness.');

    schema_inspection_set_query_executor_for_tests(null);
    echo "Presentation schema policy checks passed.\n";
}
