<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/cooperative_metadata.php
 * Module Type: Controller
 * Purpose: Serve credential-bound public album metadata as noncacheable JSON.
 * Responsibilities: Enforce POST, header-only credentials, bounded input and redacted errors.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Controllers;
use Gallery\Core as Core;
use Gallery\Services as Service;

/** Serve a direct peer metadata request independently of cookies or administrator sessions.
 * @return void Emit no-store JSON without media, local storage identifiers or raw errors.
 */
function cms_cooperative_metadata_api(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    if (Core\request_method() !== 'POST') {
        header('Allow: POST');
        cooperative_pairing_json(['ok' => false, 'error_code' => 'method_not_allowed'], 405);
        return;
    }
    $bearer = cooperative_pairing_bearer();
    if ($bearer === '') {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'metadata_unauthorized'], 403);
        return;
    }
    try {
        cooperative_pairing_json(Service\cooperative_metadata_export(cooperative_pairing_request_body(), $bearer));
    } catch (Service\CooperativeException $error) {
        $status = match ($error->reason) {
            'metadata_unauthorized' => 403,
            'metadata_unavailable' => 503,
            default => cooperative_pairing_error_status($error->reason),
        };
        cooperative_pairing_json(['ok' => false, 'error_code' => $error->reason], $status);
    } catch (\Throwable) {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'metadata_unavailable'], 503);
    }
}
