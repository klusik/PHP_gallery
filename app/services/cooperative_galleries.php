<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_galleries.php
 * Module Type: Service
 * Purpose: Provide dormant, transport-independent cooperative gallery foundations.
 * Responsibilities: Own peer credentials, immutable proposals and unanimous membership policy.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 * Notes: No route, network request or permission is enabled by loading this module.
 */
declare(strict_types=1);

namespace Gallery\Services;

/**
 * Select the canonical consent payload format.
 * Type: int. Units: protocol version. Scope: cooperative domain.
 * Consumers: proposal canonicalization and future peer adapters.
 * Rationale: Unsupported formats must not reinterpret previously approved authority.
 */
const COOPERATIVE_PROTOCOL_VERSION = 1;
/**
 * Bound the fully connected collaboration membership.
 * Type: int. Units: installations per group. Scope: cooperative domain.
 * Consumers: membership validation and friendship evidence enumeration.
 * Rationale: 32 members bound the clique to 496 pairs and keep proposal work finite.
 */
const COOPERATIVE_MAX_MEMBERS = 32;
/**
 * Limit how long an unactivated membership proposal may collect consent.
 * Type: int. Units: seconds. Scope: cooperative domain.
 * Consumers: proposal construction and canonical payload validation.
 * Rationale: One week permits asynchronous approval without permanent stale invitations.
 */
const COOPERATIVE_MAX_PROPOSAL_LIFETIME = 604800;

/** Bounded domain error; messages never contain submitted secrets or peer addresses. */
final class CooperativeException extends \RuntimeException
{
    /** Construct an error with a stable reason suitable for orchestration.
 *
 * @param string $reason Stable bounded refusal reason without user data or secrets.
 * @return void No return value; failure raises an exception.
 */
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Cooperative gallery operation refused: ' . $reason . '.');
    }
}

require_once __DIR__ . '/cooperative_galleries/validation.php';
require_once __DIR__ . '/cooperative_galleries/credentials.php';
require_once __DIR__ . '/cooperative_galleries/proposals.php';
require_once __DIR__ . '/cooperative_galleries/storage.php';require_once __DIR__ . '/cooperative_galleries/sources.php';
