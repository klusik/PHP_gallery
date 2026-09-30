<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_proposals.php
 * Module Type: Service
 * Purpose: Exchange immutable initial album proposals and independently owned decisions.
 * Responsibilities: Separate proposal observations from fresh clique verification and bounded activation leases.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/**
 * Bound verification collection and the resulting authorization lease.
 * Type: int. Units: seconds. Scope: initial cooperative membership authorization.
 * Consumers: verification rounds, activation and every local metadata access decision.
 * Rationale: Two minutes bound remote revocation delay; time spent collecting proofs
 * consumes the same lease, and retries never extend the original round deadline.
 */
const COOPERATIVE_VERIFICATION_TTL = 120;

/**
 * Begin renewal before the current verification lease runs out.
 * Type: int. Units: seconds remaining. Scope: local cooperative maintenance.
 * Consumers: maintenance planning and the CLI renewal worker.
 * Rationale: A one-minute scheduler gets a renewal opportunity during the final
 * minute of the two-minute lease; slow or unavailable peers still fail closed.
 */
const COOPERATIVE_RENEWAL_WINDOW = 60;

/**
 * Space repeated checks after a participant explicitly reports missing consent.
 * Type: int. Units: seconds. Scope: a persisted verification receipt.
 * Consumers: maintenance planning for Admin and CLI orchestration.
 * Rationale: Thirty seconds prevent a caller's progress loop from hammering a
 * participant waiting for approval; this never extends the round deadline.
 */
const COOPERATIVE_CONSENT_RETRY_DELAY = 30;

require_once __DIR__ . '/cooperative_proposals/state.php';
require_once __DIR__ . '/cooperative_proposals/decisions.php';
require_once __DIR__ . '/cooperative_proposals/protocol.php';
require_once __DIR__ . '/cooperative_proposals/transport.php';
require_once __DIR__ . '/cooperative_proposals/verification.php';
require_once __DIR__ . '/cooperative_proposals/activation.php';
require_once __DIR__ . '/cooperative_proposals/maintenance.php';
require_once __DIR__ . '/cooperative_proposals/review.php';
require_once __DIR__ . '/cooperative_proposals/composition.php';
require_once __DIR__ . '/cooperative_proposals/metadata.php';
require_once __DIR__ . '/cooperative_proposals/workflow.php';
