<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/app_linking_flight_reports_controller_test.php
 * Module Type: Regression Test
 * Purpose: Exercise delegated report target authorization and lock rechecks.
 * Responsibilities: Refuse foreign targets, malformed inputs and revoked grants.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Represent the production attachment-module load boundary without storage. */
    final class ReportFixtureKernel
    {
        /** Verify the existing lazy attachment owner.
         * @param string $module Exact module selected by the production controller.
         * @return void Refuse any unexpected runtime owner.
         */
        public function load(string $module): void
        {
            if ($module !== 'gallery-pdf-documents') {
                throw new \RuntimeException('Unexpected attachment owner.');
            }
        }
    }
    /** Return the isolated runtime load boundary.
     * @return ReportFixtureKernel Inert attachment-module loader.
     */
    function cms_runtime_kernel(): ReportFixtureKernel
    {
        return new ReportFixtureKernel();
    }
}

namespace Gallery\Services {
    /** Reauthorize one exact test gallery against a live or revoked grant.
     * @param string $token Inert fixture credential.
     * @param string $issuer Fixed fixture issuer.
     * @param int $galleryId Requested physical gallery identity.
     * @param string|null $watchKey Optional supplemental capture key.
     * @return array{id:int,folder_path:string}|null Authorized target or refusal.
     */
    function app_linking_upload_target(string $token, string $issuer, int $galleryId, ?string $watchKey = null): ?array
    {
        return $galleryId === 7 && $GLOBALS['report_grant_live']
            ? ['id' => 7, 'folder_path' => 'fixture/current-gallery'] : null;
    }
    /** Preserve the enabled feature boundary in this isolated target test.
     * @return bool True for this fixture's delegated upload feature.
     */
    function app_linking_upload_feature_enabled(): bool { return true; }
    /** Return an inert address for the actual rate-admission adapter.
     * @return string Documentation-only fixture IP.
     */
    function request_client_ip(): string { return '192.0.2.1'; }
    /** Admit the controlled request without touching real rate storage.
     * @param string $operation Inventory or upload cost category.
     * @param int $grantId Fixture grant identity.
     * @param int $userId Fixture administrator identity.
     * @param int $galleryId Fixture target identity.
     * @param string $ip Documentation-only fixture address.
     * @param int $originalCount Cost charged by the actual controller.
     * @return array{allowed:bool,retry_after_seconds:int,reason:string} Admission result.
     */
    function app_linking_upload_rate_admission(string $operation, int $grantId, int $userId, int $galleryId, string $ip, int $originalCount): array
    {
        return ['allowed' => true, 'retry_after_seconds' => 0, 'reason' => 'allowed'];
    }
    /** Run the production callback after an optional concurrent revocation.
     * @param int $galleryId Exact gallery lock identity.
     * @param callable():?array{found:bool,conflict:bool,report:?array{id:string,sha256:string,size:int,filename:string}} $operation Actual controller callback.
     * @return array{found:bool,conflict:bool,report:?array{id:string,sha256:string,size:int,filename:string}}|null Same-gallery status, or refused authorization.
     */
    function upload_automation_with_gallery_lock(int $galleryId, callable $operation): ?array
    {
        if ($GLOBALS['report_revoke_in_lock']) $GLOBALS['report_grant_live'] = false;
        return $operation();
    }
    /** Observe a receipt through the already separately tested storage boundary.
     * @param array{id:int,folder_path:string} $gallery Freshly authorized gallery.
     * @param string $reportId Stable report identity.
     * @param string $language Requested language.
     * @param string $sha256 Expected content checksum.
     * @return array{found:bool,conflict:bool,report:?array{id:string,sha256:string,size:int,filename:string}} Controlled receipt observation.
     */
    function upload_flight_report_status(array $gallery, string $reportId, string $language, string $sha256): array
    {
        $report = $GLOBALS['report_stored'];
        return ['found' => $report !== null, 'conflict' => false, 'report' => $report];
    }
    /** Observe that only a reauthorized gallery reaches attachment storage.
     * @param array{id:int,folder_path:string} $gallery Freshly authorized gallery.
     * @param string $source Inert uploaded-file boundary marker.
     * @param string $reportId Stable report identity.
     * @param string $sessionId Local session identity.
     * @param string $language Report edition.
     * @param string $sha256 Expected digest.
     * @return array{found:bool,conflict:bool,report:array{id:string,sha256:string,size:int,filename:string}} Controlled attachment receipt.
     */
    function upload_flight_report_attach(array $gallery, string $source, string $reportId, string $sessionId, string $language, string $sha256): array
    {
        $GLOBALS['report_store_calls']++;
        $GLOBALS['report_stored'] = [
            'id' => str_repeat('a', 32), 'sha256' => $sha256,
            'size' => 42, 'filename' => 'fixture.pdf',
        ];
        return ['found' => true, 'conflict' => false, 'report' => $GLOBALS['report_stored']];
    }
}

namespace Gallery\Controllers {
    /** Record the actual controller's bounded refusal.
     * @param int $status HTTP response status.
     * @param string $reason Fixed refusal identifier.
     * @return void Retain the bounded error for the assertion.
     */
    function app_linking_json_error(int $status, string $reason): void
    {
        $GLOBALS['report_error'] = [$status, $reason];
    }
    /** Leave header policy inert in this output-buffered unit fixture.
     * @return void No production response is emitted.
     */
    function app_linking_security_headers(): void {}
    /** Leave response-cache headers inert at this non-HTTP boundary.
     * @return void No production cache state is changed.
     */
    function clear_response_cache_headers(): void {}
    /** Replace only PHP's uploaded-file check for a controlled test marker.
     * @param string $source Upload temporary path passed by the controller.
     * @return bool True only for this fixture's inert upload marker.
     */
    function is_uploaded_file(string $source): bool { return $source === 'fixture-upload'; }
}

namespace {
    require_once __DIR__ . '/../app/controllers/app_linking/upload.php';
    $auth = ['issuer' => 'https://gallery.test', 'token' => 'fixture',
        'identity' => ['grant_id' => 1, 'user_id' => 1, 'scope' => 'upload']];
    $payload = ['action' => 'flight_report', 'gallery_id' => 7,
        'report_id' => 'revision', 'session_id' => 'session',
        'language' => 'cs', 'sha256' => str_repeat('b', 64)];
    $_FILES = ['report' => ['error' => UPLOAD_ERR_OK, 'tmp_name' => 'fixture-upload']];
    $GLOBALS['report_grant_live'] = true;
    $GLOBALS['report_revoke_in_lock'] = false;
    $GLOBALS['report_stored'] = null;
    $GLOBALS['report_store_calls'] = 0;
    $GLOBALS['report_error'] = null;
    ob_start();
    \Gallery\Controllers\app_linking_handle_flight_report($auth, $payload, true);
    $receipt = json_decode((string) ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);
    if ($receipt['gallery_id'] !== 7 || $receipt['report']['sha256'] !== $payload['sha256']
        || $GLOBALS['report_store_calls'] !== 1) {
        throw new \RuntimeException('A delegated PDF did not retain its authorized target receipt.');
    }
    foreach ([
        [array_replace($payload, ['gallery_id' => 8]), false, 404],
        [$payload + ['api_key' => 'foreign-mode'], false, 400],
        [$payload, true, 401],
    ] as [$request, $revoke, $expectedStatus]) {
        $GLOBALS['report_error'] = null;
        $GLOBALS['report_grant_live'] = true;
        $GLOBALS['report_revoke_in_lock'] = $revoke;
        \Gallery\Controllers\app_linking_handle_flight_report($auth, $request, true);
        if (($GLOBALS['report_error'][0] ?? null) !== $expectedStatus
            || $GLOBALS['report_store_calls'] !== 1) {
            throw new \RuntimeException('Invalid or concurrently revoked authority reached PDF storage.');
        }
    }
    echo "delegated flight report controller tests passed\n";
}
