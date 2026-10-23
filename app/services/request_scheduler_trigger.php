<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/request_scheduler_trigger.php
 * Module Type: Service
 * Purpose: Register visitor scheduling without loading the planner before rendering.
 * Responsibilities: Preserve foreground publication and defer scheduler observations.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Services;

/**
 * Identify ordinary HTML visits, including crawlers, without activating transports.
 * @param string $page Canonical route identity.
 * @param string $method Normalized request method.
 * @param bool $passive Whether this is an AJAX/checkpoint/preview read.
 * @return bool True only for admitted visitor HTML reads.
 */
function request_scheduler_visit_allowed(
    string $page, string $method, bool $passive = false,
): bool {
    return !$passive && in_array($method, ['GET', 'HEAD'], true)
        && (in_array($page, ['home', 'gallery', 'smart_gallery', 'tag'], true)
            || in_array($page, ['admin', 'admin_logs', 'admin_cron_overview'], true));
}

/**
 * Register exactly one successful-response scheduling opportunity for a visit.
 * @param string $page Canonical HTML route.
 * @param string $method Normalized HTTP method.
 * @param bool $passive Whether AJAX/status/preview excludes this request.
 * @param callable(string):mixed|null $loadDependencies Selective loader; full bootstrap callers may omit it.
 * @return void Applies the integrated indexed publication transition before rendering, then registers one bounded private background handoff.
 */
function request_scheduler_register_visit(
    string $page, string $method, bool $passive,
    ?callable $loadDependencies = null,
): void {
    // The integrated publication owner must finish its tiny indexed transition
    // before the triggering response discovers galleries. It shares this one
    // lifecycle entry; heavy owners still run only after the response.
    if (!$passive && in_array($method, ['GET', 'HEAD'], true)
        && $loadDependencies !== null) {
        $loadDependencies('site-request-trigger');
        gallery_publication_request($method, $loadDependencies);
    }
    if (PHP_SAPI === 'cli'
        || !request_scheduler_visit_allowed($page, $method, $passive)) {
        return;
    }
    site_maintenance_register_foreground_observation($page);
    register_shutdown_function(static function () use ($loadDependencies): void {
        $error = error_get_last();
        if (\Gallery\Core\response_status_code() < 200
            || \Gallery\Core\response_status_code() >= 400
            || $error !== null && in_array($error['type'],
                [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            if ($loadDependencies !== null) {
                $loadDependencies('archive-request-trigger');
            }
            $detached = site_maintenance_finish_response_before_background_work();
            request_scheduler_tick($loadDependencies, '', $detached);
        } catch (\Throwable) {
            // Scheduling failures preserve the completed response. Expiring
            // tickets and the next visit recover without suppressing a domain.
        }
    });
}
