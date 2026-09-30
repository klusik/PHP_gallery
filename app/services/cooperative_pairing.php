<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_pairing.php
 * Module Type: Service
 * Purpose: Orchestrate bilateral invitation, challenge and durable recovery.
 * Responsibilities: Keep network effects outside local transactions and expose safe administrative state.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

/**
 * Bound pairing message size before JSON parsing.
 * Type: int. Units: bytes. Scope: pairing protocol.
 * Consumers: transport and HTTP controllers.
 * Rationale: Control messages contain only bounded identities and credentials, never media.
 */
const COOPERATIVE_PAIRING_MAX_BYTES = 16384;
/**
 * Limit the invitation acceptance window.
 * Type: int. Units: seconds. Scope: bilateral pairing.
 * Consumers: invitation creation and expiry checks.
 * Rationale: A one-day invitation supports asynchronous consent without permanent bootstrap authority.
 */
const COOPERATIVE_PAIRING_LIFETIME = 86400;

/**
 * Bound encoded invitations before base64 and JSON decoding.
 * Type: int. Units: bytes. Scope: pairing input.
 * Consumers: administrator form extraction and invitation decoder.
 * Rationale: Allows two canonical bases and identifiers while rejecting oversized bootstrap messages.
 */
const COOPERATIVE_PAIRING_CODE_MAX_BYTES = 8192;

require_once __DIR__ . '/cooperative_pairing/protocol.php';
require_once __DIR__ . '/cooperative_pairing/transport.php';
require_once __DIR__ . '/cooperative_pairing/storage.php';
require_once __DIR__ . '/cooperative_pairing/lifecycle.php';
require_once __DIR__ . '/cooperative_pairing/inbound.php';
require_once __DIR__ . '/cooperative_pairing/admin.php';
