<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/controllers/admin_gallery_features.php
 * Module Type: Controller
 * Purpose: Expose authenticated read-only feature planning and confirmed plan application.
 * Responsibilities: Own request authority, bounded JSON responses and canonical mutation completion.
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);
namespace Gallery\Controllers;
use function Gallery\Core\require_admin;
use function Gallery\Core\verify_csrf;
use function Gallery\Core\request_method;
use function Gallery\Core\url_for;
use function Gallery\Core\gallery_public_url;
use function Gallery\Core\admin_mutation_descriptor;
use function Gallery\Core\admin_mutation_success_envelope;
use function Gallery\Core\admin_mutation_error_envelope;
use function Gallery\Core\admin_mutation_public_gallery_context;
use function Gallery\Services\gallery_feature_plan_preview;
use function Gallery\Services\gallery_feature_plan_apply;
use function Gallery\Services\find_gallery;
use function Gallery\Services\t;

/** Emit private JSON for an authenticated planning or application request.
 * @param array<string,mixed> $payload Safe response fields.
 * @param int $status HTTP response status.
 * @return void Emits bounded UTF-8 JSON with no storage caching.
 */
function admin_gallery_features_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** Verify the common authority boundary before parsing any feature intentions.
 * @return bool Whether an authenticated POST may continue.
 */
function admin_gallery_features_authorized(): bool
{
    require_admin();
    if (request_method() !== 'POST') {
        admin_gallery_features_json(admin_mutation_error_envelope('Use POST to review gallery feature changes.', 'method_not_allowed'), 405);
        return false;
    }
    verify_csrf();
    return true;
}

/** Decode the bounded list passed to the feature domain owner.
 * @return array<mixed> Browser intentions awaiting semantic normalization.
 */
function admin_gallery_features_intents(): array
{
    $raw = $_POST['intents'] ?? '';
    if (!is_string($raw) || strlen($raw) > 65536) {
        throw new \InvalidArgumentException('Invalid gallery feature change.');
    }
    $intents = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($intents) || !array_is_list($intents)) {
        throw new \InvalidArgumentException('Invalid gallery feature change.');
    }
    return $intents;
}

/** Return a precise non-mutating preview for ordered local intentions.
 * @return void Emits exact scope, changed fields and the reviewed fingerprint.
 */
function cms_admin_gallery_features_plan(): void
{
    if (!admin_gallery_features_authorized()) {
        return;
    }
    try {
        admin_gallery_features_json(['ok' => true, 'plan' => gallery_feature_plan_preview(admin_gallery_features_intents())]);
    } catch (\Throwable $exception) {
        admin_gallery_features_json(admin_mutation_error_envelope(t('admin.gallery_features.review_failed', 'Gallery changes could not be reviewed. Check feature availability and System Health, then try again.'), 'feature_plan_unavailable'), 409);
    }
}

/** Apply only an explicitly confirmed fingerprint and emit canonical completion metadata.
 * @return void Emits success after persistence or safe refusal before persistence.
 */
function cms_admin_gallery_features_apply(): void
{
    if (!admin_gallery_features_authorized()) {
        return;
    }
    $fallback = ['redirect_url' => url_for('admin', ['dashboard_tab' => 'galleries'])];
    try {
        $fingerprint = $_POST['fingerprint'] ?? '';
        if (!is_string($fingerprint)) {
            throw new \InvalidArgumentException('Invalid reviewed fingerprint.');
        }
        $result = gallery_feature_plan_apply(admin_gallery_features_intents(), $fingerprint);
    } catch (\Throwable $exception) {
        admin_gallery_features_json(admin_mutation_error_envelope(t('admin.gallery_features.apply_failed', 'Gallery changes were not applied. The hierarchy, feature state or availability may have changed. Review the changes again.'), 'feature_plan_refused', null, $fallback), 409);
        return;
    }
    $contexts = [];
    $refreshDegraded = false;
    try {
        foreach ($result['entity_ids'] as $id) {
            $gallery = find_gallery($id, true);
            if (is_array($gallery)) {
                $contexts[] = admin_mutation_public_gallery_context($id, gallery_public_url($gallery));
            }
        }
    } catch (\Throwable $exception) {
        $refreshDegraded = true;
    }
    $message = t('admin.gallery_features.applied', 'Gallery feature changes saved.');
    if ($result['sidecar_refresh_failed'] || $refreshDegraded) {
        $message = t('admin.gallery_features.applied_refresh_warning', 'Gallery feature changes saved. Some metadata could not be refreshed; check System Health.');
    }
    admin_gallery_features_json(admin_mutation_success_envelope($message,
        admin_mutation_descriptor('gallery.features.update', 'gallery', 'update', $result['entity_ids']), null, $contexts, $fallback));
}
