<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cache_lifecycle.php
 * Module Type: Service
 * Purpose: Own cache retention policies, measured previews and resumable reclamation.
 * Responsibilities: Classify producer-owned disposable storage, protect durable data and share bounded jobs with maintenance.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

require_once __DIR__ . '/cache_lifecycle_storage.php';
require_once dirname(__DIR__) . '/release_file_policy.php';
require_once __DIR__ . '/updates_path_safety.php';
require_once __DIR__ . '/updates_filesystem.php';
require_once __DIR__ . '/image_decode_policy.php';

/**
 * Bound one directory-name catalog before retaining its durable enumeration.
 * Type: int. Units: names. Scope: one private inventory directory.
 * Consumers: cache lifecycle inventory catalog creation.
 * Rationale: 4096 names fit shared-hosting memory; denser directories are explicitly blocked instead of unbounded enumeration or quadratic replay.
 */
const CACHE_LIFECYCLE_DIRECTORY_NAMES = 4096;

/**
 * Bound filesystem observations admitted by a single inventory/cleanup call.
 * Type: int. Units: operations. Scope: one lifecycle slice.
 * Consumers: inventory and cleanup workers.
 * Rationale: at most 5000 name/metadata observations and 180 ms admission keep shared-hosting requests bounded; blocking OS calls can overrun admission.
 */
const CACHE_LIFECYCLE_SLICE_ENTRIES = 5000;
/**
 * Bound time for admitting new lifecycle filesystem work.
 * Type: float. Units: seconds. Scope: one worker slice.
 * Consumers: inventory catalog creation and cleanup.
 * Rationale: 180 ms matches the updater's existing slice; queued work resumes through durable journals.
 */
const CACHE_LIFECYCLE_SLICE_SECONDS = 0.18;

/**
 * Bound the combined retirement and payload admission of a scheduled tick.
 * Type: float. Units: seconds. Scope: one scheduled lifecycle slice.
 * Consumers: scheduled slice default and burst admission.
 * Rationale: two canonical 180 ms owners permit at most 360 ms combined admission; visitor opportunities lower the same bound.
 */
const CACHE_LIFECYCLE_COMBINED_SECONDS = CACHE_LIFECYCLE_SLICE_SECONDS * 2;

/**
 * Distinguish a small measured eligible backlog from a larger reclamation queue.
 * Type: int. Units: bytes. Scope: one admitted visitor burst.
 * Consumers: visitor budget selection and authenticated diagnostics.
 * Rationale: 128 MiB includes a roughly 100 MB backlog, while independent slice/time/memory bounds protect many-file workloads.
 */
const CACHE_LIFECYCLE_SMALL_BYTES = 134217728;

/**
 * Reserve enough admission time to start a small directory catalog fairly.
 * Type: float. Units: seconds. Scope: directory catalog admission inside one slice.
 * Consumers: inventory worker before opening a previously unseen directory.
 * Rationale: defer late starts to the next slice instead of falsely marking ordinary directories partial at the deadline.
 */
const CACHE_LIFECYCLE_CATALOG_MIN_SECONDS = 0.02;

/**
 * Bound retries when a directory catalog starts with too little time to finish.
 * Type: int. Units: attempts. Scope: one inventory directory frame.
 * Consumers: directory catalog admission.
 * Rationale: three bounded attempts prevent a late slice start from falsely excluding ordinary directories without permitting indefinite prefix replay.
 */
const CACHE_LIFECYCLE_CATALOG_ATTEMPTS = 3;

/**
 * Cap independent scheduler bursts even when hosting permits longer PHP requests.
 * Type: int. Units: slices. Scope: one external scheduler invocation.
 * Consumers: bounded scheduled runner.
 * Rationale: thirty-two short alternating slices limit filesystem pressure and release locks between checkpoints.
 */
const CACHE_LIFECYCLE_RUN_SLICES = 32;
/**
 * Refuse known-low filesystem capacity before admitting inventory or payload detachment.
 * Type: int. Units: bytes. Scope: the cache filesystem.
 * Consumers: scheduled worker admission.
 * Rationale: sixteen MiB is planning headroom when measurable. Hosting-disabled/unknown capacity instead relies on successful bounded checkpoint writes; it never claims a measured reserve or host quota.
 */
const CACHE_LIFECYCLE_RESERVE_BYTES = 16777216;
/**
 * Bound private catalog and plan growth across one resumable observation cycle.
 * Type: int. Units: bytes. Scope: committed inventory metadata for one cycle.
 * Consumers: catalog/candidate admission.
 * Rationale: sixty-four MiB bounds checkpoint disk pressure independently of payload size; exceeding it preserves unseen data and reports partial coverage.
 */
const CACHE_LIFECYCLE_METADATA_BYTES = 67108864;

require_once __DIR__ . '/cache_lifecycle/policy.php';
require_once __DIR__ . '/cache_lifecycle/storage.php';
require_once __DIR__ . '/cache_lifecycle/inventory.php';
require_once __DIR__ . '/cache_lifecycle/reclamation.php';
require_once __DIR__ . '/cache_lifecycle/visitor.php';
require_once __DIR__ . '/cache_lifecycle/chain.php';
require_once __DIR__ . '/cache_lifecycle/observability.php';
require_once __DIR__ . '/cache_lifecycle/registry.php';
