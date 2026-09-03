<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/configuration_defaults.php
 * Module Type: Core Configuration
 *
 * Purpose:
 *   Provides the single canonical source for operational runtime-limit defaults.
 *
 * Responsibilities:
 *   - Keep administrator/deployment tuning values out of individual feature modules
 *   - Preserve safe defaults for existing config.php files after application updates
 *   - Allow local config.php files to override selected limits without duplicating defaults
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
 *   - Protocol constants, schema versions, enum values, and format hard limits do not belong here.
 *   - Override only values that genuinely need deployment-specific tuning.
 *
 * Last Updated:
 *   2026-09-03
 */

declare(strict_types=1);

return [
    'runtime_limits' => [
        // Public download manifest and bounded legacy-server fallback policy.
        'download.manifest_max_files' => 20000,
        'download.manifest_max_galleries' => 5000,

        // Revision-keyed progressive manifest metadata cache. Cached files contain only
        // normalized image ids, versions, ZIP names, and byte sizes, never capabilities.
        'download.manifest_cache_physical_retention_seconds' => 24 * 60 * 60,
        'download.manifest_cache_smart_retention_seconds' => 15 * 60,
        'download.manifest_cache_max_entry_bytes' => 16 * 1024 * 1024,
        'download.manifest_cache_cleanup_max_entries' => 10000,

        'download.legacy_max_files' => 1000,
        'download.legacy_max_source_bytes' => 256 * 1024 * 1024,
        'download.memory_fallback_warning_bytes' => 256 * 1024 * 1024,
        'download.memory_fallback_max_bytes' => 512 * 1024 * 1024,
        'download.smart_gallery_zip_max_images' => 5000,
        'download.smart_gallery_zip_max_source_bytes' => 2 * 1024 * 1024 * 1024,

        // Legacy server ZIP preparation is admission-controlled, but completed archive
        // transfer speed is never throttled. Filesystem flock() makes slots crash-safe.
        'download.legacy_max_concurrent_builds' => 2,
        'download.legacy_busy_retry_after_seconds' => 5,

        // Immutable managed legacy-artifact cache. Physical revisions are retained for
        // seven days; dynamic Smart Gallery result fingerprints use a shorter one-day
        // retention to bound churn. Capacity includes completed artifacts plus active
        // build reservations and preserves a filesystem free-space safety margin.
        'download.legacy_artifact_physical_retention_seconds' => 7 * 24 * 60 * 60,
        'download.legacy_artifact_smart_retention_seconds' => 24 * 60 * 60,
        'download.legacy_artifact_partial_retention_seconds' => 6 * 60 * 60,
        'download.legacy_artifact_lock_retention_seconds' => 24 * 60 * 60,
        'download.legacy_artifact_cache_max_bytes' => 4 * 1024 * 1024 * 1024,
        'download.legacy_artifact_free_space_margin_bytes' => 512 * 1024 * 1024,

        // Bounded values accepted into operational download-failure diagnostics.
        'download.failure_user_agent_max_length' => 300,
        'download.failure_referer_max_length' => 500,
        'download.failure_referer_input_max_length' => 2000,
        'download.failure_exception_message_max_length' => 240,

        // Stateless capability envelope. Six hours starts at the explicit browser handshake and
        // covers multi-gigabyte transfers without creating a permanent reusable bearer URL.
        // The Stage 3 browser transport keeps the token out of normal request URLs/access logs.
        'download.capability_ttl_seconds' => 6 * 60 * 60,
        'download.capability_max_token_length' => 1024,
        'download.capability_nonce_bytes' => 16,
        'download.capability_clock_skew_seconds' => 30,

        // Browser-side upload concurrency and package sizing policy.
        'browser_upload.default_worker_count' => 8,
        'browser_upload.min_worker_count' => 1,
        'browser_upload.hard_worker_cap' => 32,
        'browser_upload.default_zip_ratio' => 0.80,
        'browser_upload.min_zip_ratio' => 0.10,
        'browser_upload.max_zip_ratio' => 0.95,
        'browser_upload.default_max_items_per_batch' => 8,
        'browser_upload.min_items_per_batch' => 1,
        'browser_upload.max_items_per_batch' => 64,
        'browser_upload.default_max_zip_batch_bytes' => 24 * 1024 * 1024,
        'browser_upload.min_max_zip_batch_bytes' => 1 * 1024 * 1024,
        'browser_upload.hard_max_zip_batch_bytes' => 128 * 1024 * 1024,
        'browser_upload.fallback_server_upload_limit_bytes' => 8 * 1024 * 1024,
        'browser_upload.request_reserve_min_bytes' => 64 * 1024,
        'browser_upload.request_reserve_max_bytes' => 512 * 1024,
        'browser_upload.request_reserve_ratio' => 0.05,
        'browser_upload.max_zip_entries' => 20000,

        // Browser-assisted thumbnail source-package sizing policy.
        'browser_thumbnail_rebuild.default_chunk_bytes' => 512 * 1024 * 1024,
        'browser_thumbnail_rebuild.min_chunk_bytes' => 16 * 1024 * 1024,
        'browser_thumbnail_rebuild.hard_chunk_bytes' => 3 * 1024 * 1024 * 1024,
        'browser_thumbnail_rebuild.default_max_items_per_chunk' => 96,
        'browser_thumbnail_rebuild.hard_max_items_per_chunk' => 512,
        'browser_thumbnail_rebuild.request_time_limit_seconds' => 300,
    ],

    // Optional dedicated secret. Existing installations intentionally leave this blank and
    // derive an HMAC-only subkey from visitor_vote_secret, so updates never require config edits.
    'download_security' => [
        'capability_secret' => '',
    ],
];
