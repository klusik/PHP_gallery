<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_cooperative_albums.php
 * Module Type: Controller
 * Purpose: Expose the read-only administrator album selector for collaboration.
 * Responsibilities: Enforce administrator and method boundaries; return bounded private JSON.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Controllers;

use Gallery\Core as Core;
use Gallery\Services as Service;
use Gallery\Services\CooperativeException;

/** List local album candidates without sharing, identity creation or peer requests.
 *
 * @return void Emit private JSON for authenticated administrators only.
 */
function cms_admin_cooperative_albums(): void
{
    Core\require_admin();
    if (Core\request_method() !== 'GET') {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'method_not_allowed'], 405);
        return;
    }
    try {
        $cursor = filter_var($_GET['after_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($cursor === false || $cursor < 0) {
            throw new CooperativeException('invalid_cursor');
        }
        cooperative_pairing_json(['ok' => true, 'state' => Service\cooperative_album_sources_state($cursor)]);
    } catch (CooperativeException $error) {
        cooperative_pairing_json(['ok' => false, 'error_code' => $error->reason], cooperative_pairing_error_status($error->reason));
    } catch (\Throwable) {
        cooperative_pairing_json(['ok' => false, 'error_code' => 'source_unavailable'], 503);
    }
}
