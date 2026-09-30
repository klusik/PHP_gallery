<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/cooperative_content.php
 * Module Type: Service
 * Purpose: Serve bounded public photo catalogs and revocable media tickets.
 * Responsibilities: Enforce explicit public cooperative grants without exposing system credentials.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;


/** Bound each source request and its encrypted tickets below the JSON transport limit.
 * Type: int. Units: photos per page. Scope: cooperative content.
 * Consumers: catalog model and response validator. Rationale: eight entries fit within 16 KiB.
 */
const COOPERATIVE_PHOTO_PAGE_SIZE = 8;
/** Bound successful remote catalog admission without delaying local catalog reads.
 * Type: int. Units: seconds. Scope: cooperative content network admission.
 * Consumers: reservation completion. Rationale: public polling cannot amplify peer traffic.
 */
const COOPERATIVE_CONTENT_SUCCESS_RETRY_DELAY = 1;
/** Bound ownership of a remote catalog operation after worker failure.
 * Type: int. Units: seconds. Scope: cooperative content network admission.
 * Consumers: durable reservation. Rationale: covers transport timeout with finite crash recovery.
 */
const COOPERATIVE_CONTENT_OPERATION_TTL = 30;
/** Bound existing derivative reads before metadata removal and HTTP output.
 * Type: int. Units: bytes. Scope: cooperative derivative delivery.
 * Consumers: derivative reader and sanitizer. Rationale: constrain anonymous-request memory use.
 */
const COOPERATIVE_DERIVATIVE_MAX_BYTES = 8 * 1024 * 1024;
require_once __DIR__ . '/cooperative_content/policy.php';
require_once __DIR__ . '/cooperative_content/catalog.php';
require_once __DIR__ . '/cooperative_content/admission.php';
require_once __DIR__ . '/cooperative_content/client.php';
require_once __DIR__ . '/cooperative_content/derivative_bytes.php';
require_once __DIR__ . '/cooperative_content/media.php';
