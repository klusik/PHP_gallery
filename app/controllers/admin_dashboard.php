<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: app/controllers/admin_dashboard.php
 * Module Type: Controller
 *
 * Purpose:
 *   Handles request-level application logic for the Admin dashboard.
 *
 * Responsibilities:
 *   - Validate admin access and request methods
 *   - Build dashboard view models through the model/service layer
 *   - Dispatch rendering to view modules or redirects to route targets
 *
 * Author:
 *   Rudolf Klusal
 *
 * Contact:
 *   https://github.com/klusik
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - Prefer small, readable changes over broad rewrites.
 *
 * Last Updated:
 *   2026-05-24
 */

declare(strict_types=1);

/**
 * Render the main Admin dashboard.
 */
function cms_admin(): void
{
    require_admin();
    admin_render_profile_start('admin_dashboard');
    $dashboardModel = admin_dashboard_view_model();
    $dashboardModel['notices'] = admin_dashboard_notice_messages($_GET, (string) flash_message('admin_notice'));
    view_render_admin_dashboard($dashboardModel);
}


/**
 * Render the dedicated Admin storage statistics view.
 */
function cms_admin_storage_statistics(): void
{
    require_admin();
    $activeTab = (string) ($_GET['tab'] ?? 'files');
    if (!in_array($activeTab, ['files', 'database'], true)) {
        $activeTab = 'files';
    }
    $statistics = $activeTab === 'files' && function_exists('admin_storage_statistics_cached_snapshot') ? admin_storage_statistics_cached_snapshot(true) : null;
    $databaseUsage = $activeTab === 'database' && function_exists('admin_database_usage_summary') ? admin_database_usage_summary() : null;
    view_render_admin_storage_statistics_page($statistics, $databaseUsage, $activeTab);
}

/**
 * Process browser-driven storage statistics update requests.
 */
function cms_admin_storage_statistics_update(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }

    $bufferLevel = ob_get_level();
    ob_start();
    try {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? 'step');
        if ($action === 'start') {
            $state = admin_storage_statistics_start_job();
        } else {
            $batchSize = max(1, min(ADMIN_STORAGE_STATISTICS_MAX_BATCH_SIZE, (int) ($_POST['batch_size'] ?? ADMIN_STORAGE_STATISTICS_DEFAULT_BATCH_SIZE)));
            $state = admin_storage_statistics_process_job($batchSize);
        }

        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        admin_storage_statistics_json_response(admin_storage_statistics_controller_payload($state));
    } catch (Throwable $exception) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        admin_log_event('error', 'storage_statistics.update_failed', 'Admin storage statistics update failed.', ['exception' => $exception->getMessage()]);
        admin_storage_statistics_json_response([
            'ok' => false,
            'status' => 'error',
            'error' => $exception->getMessage(),
        ]);
    }
}

/**
 * Build the JSON payload for a storage statistics Ajax response.
 *
 * @param array $state State value.
 * @return array<string mixed>.
 */
function admin_storage_statistics_controller_payload(array $state): array
{
    $status = (string) ($state['status'] ?? 'running');
    $message = (string) ($state['message'] ?? '');
    if ($message === '' && $status === 'missing') {
        $message = t('admin.storage.progress_missing_job', 'No running storage statistics job was found.');
    } elseif ($status === 'stale') {
        $message = t('admin.storage.progress_stale', 'Gallery data changed while statistics were being calculated. Start a new update.');
    }

    $payload = [
        'ok' => !empty($state['ok']),
        'status' => $status,
        'processed' => (int) ($state['processed'] ?? 0),
        'total' => (int) ($state['total'] ?? 0),
        'percent' => (float) ($state['percent'] ?? 0.0),
        'message' => $message,
    ];

    if (is_array($state['snapshot'] ?? null)) {
        ob_start();
        view_render_admin_storage_statistics_panel($state['snapshot']);
        $payload['html'] = (string) ob_get_clean();
        $payload['status_text'] = view_admin_storage_snapshot_status($state['snapshot']);
    }

    return $payload;
}

/**
 * Emit a JSON response for storage statistics endpoints.
 *
 * @param array $payload Payload value.
 */
function admin_storage_statistics_json_response(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/**
 * Backward-compatible wrapper for older controller/view code.
 */
function render_admin_url_rewrite_warning(): void
{
    view_render_admin_url_rewrite_warning();
}

/**
 * Backward-compatible wrapper for older controller/view code.
 *
 * @param string $className Class name value.
 */
function render_admin_url_rewrite_card(string $className): void
{
    view_render_admin_url_rewrite_card($className);
}

/**
 * Persist the URL rewrite admin setting.
 */
function cms_admin_url_rewrite(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    set_url_rewrite_enabled(isset($_POST['url_rewrite_enabled']));
    flash_message('admin_notice', '' . t('admin.dashboard.notice_url_rewrite_saved', 'URL rewrite setting saved.') . '');
    redirect_to(url_for('admin', ['url_rewrite_saved' => 1]));
}


/**
 * Persist the optional public search setting.
 */
function cms_admin_public_search_settings(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    if (function_exists('feature_flag_enabled') && !feature_flag_enabled('public_search')) {
        flash_message('admin_notice', t('admin.dashboard.notice_public_search_disabled', 'Public search is disabled in Admin > Features.'));
        redirect_to(url_for('admin'));
    }
    set_public_home_search_enabled(isset($_POST['public_home_search_enabled']));
    admin_log_event('info', 'settings.public_search_updated', 'Admin updated the public home search setting.', [
        'enabled' => public_home_search_enabled(),
    ]);
    flash_message('admin_notice', t('admin.dashboard.notice_public_search_saved', 'Public search setting saved.'));
    redirect_to(url_for('admin'));
}


/**
 * Persist global EXIF/GPS display defaults and optionally clear gallery overrides.
 */
function cms_admin_exif_gps_settings(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    if (!exif_gps_override_schema_ready()) {
        flash_message('admin_notice', t('admin.dashboard.exif_gps_requires_migration', 'EXIF/GPS default controls will be available after the database migration is applied.'));
        redirect_to(url_for('admin'));
    }

    set_exif_gps_default_enabled(!empty($_POST['exif_gps_default_enabled']));
    // $resetCount stores how many explicit gallery overrides were removed.
    $resetCount = !empty($_POST['reset_gallery_overrides']) ? reset_all_gallery_gps_map_overrides() : 0;
    admin_log_event('info', 'settings.exif_gps_updated', 'Admin updated EXIF/GPS display defaults.', [
        'default_enabled' => exif_gps_default_enabled(),
        'reset_gallery_overrides' => $resetCount,
    ]);

    if ($resetCount > 0) {
        flash_message('admin_notice', t('admin.dashboard.notice_exif_gps_saved_with_reset', 'EXIF/GPS defaults saved. Reset {count} gallery override(s).', ['count' => (string) $resetCount]));
    } else {
        flash_message('admin_notice', t('admin.dashboard.notice_exif_gps_saved', 'EXIF/GPS defaults saved.'));
    }
    redirect_to(url_for('admin'));
}

/**
 * Persist SEO request guard settings.
 */
function cms_admin_seo_guard_settings(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();

    set_seo_request_guard_enabled(!empty($_POST['seo_request_guard_enabled']));
    set_seo_request_guard_logging_enabled(!empty($_POST['seo_request_guard_logging_enabled']));
    admin_log_event('info', 'settings.seo_request_guard_updated', 'Admin updated SEO request guard settings.', [
        'enabled' => seo_request_guard_enabled(),
        'logging_enabled' => seo_request_guard_logging_enabled(),
    ], ['category' => 'security', 'severity' => 'info']);

    flash_message('admin_notice', t('admin.dashboard.notice_seo_guard_saved', 'SEO request guard setting saved.'));
    redirect_to(url_for('admin') . '#admin-tab-maintenance');
}

/**
 * Backward-compatible wrapper for older controller/view code.
 *
 * @param bool $flightNavdataReady Flight navdata ready value.
 * @param array $flightNavdataStatus Flight navdata status value.
 */
function render_admin_navdata_maintenance_card(bool $flightNavdataReady, array $flightNavdataStatus): void
{
    view_render_admin_navdata_maintenance_card($flightNavdataReady, $flightNavdataStatus);
}

/**
 * Handles admin-triggered flight-map navdata refreshes.
 */
function cms_admin_update_navdata(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();

    try {
        $result = flight_map_update_navdata_from_ourairports();
        admin_log_event('info', 'flight_map.navdata_updated', 'Admin updated flight-map navdata from OurAirports.', $result);
        flash_message('admin_notice', t('admin.dashboard.notice_navdata_updated', 'Updated flight-map navdata. Imported {airports} airport identifier(s), {navaids} navaid(s), skipped {skipped} row(s), removed {deleted} stale row(s).', [
            'airports' => (int) ($result['airports'] ?? 0),
            'navaids' => (int) ($result['navaids'] ?? 0),
            'skipped' => (int) ($result['skipped'] ?? 0),
            'deleted' => (int) ($result['deleted'] ?? 0),
        ]));
    } catch (Throwable $exception) {
        admin_log_event('error', 'flight_map.navdata_failed', 'Admin flight-map navdata update failed.', ['exception' => $exception->getMessage()]);
        flash_message('admin_notice', t('admin.dashboard.notice_navdata_failed', 'Flight-map navdata update failed: {error}', ['error' => $exception->getMessage()]));
    }

    redirect_to(url_for('admin'));
}

/**
 * Backward-compatible wrapper for older controller/view code.
 */
function render_admin_devmode_panel(): void
{
    view_render_admin_devmode_panel();
}

/**
 * Persist the admin dev mode setting.
 */
function cms_admin_devmode(): void
{
    require_admin();
    if (request_method() !== 'POST') {
        cms_not_found();
        return;
    }
    verify_csrf();
    set_dev_mode_enabled(isset($_POST['dev_mode_enabled']));
    flash_message('admin_notice', '' . e(t('admin.dashboard.notice_devmode_saved', 'Dev mode setting saved.')) . '');
    redirect_to(url_for('admin'));
}

/**
 * Backward-compatible wrapper for older controller/view code.
 *
 * @param string $message Message value.
 */
function render_admin_migration_notice(string $message): void
{
    view_render_admin_migration_notice($message);
}

/**
 * Run pending database migrations from the Admin dashboard.
 */
function cms_admin_run_migrations(): void
{
    require_admin();
    verify_csrf();
    try {
        // $ran stores an intermediate value used by the surrounding gallery workflow.
        $ran = run_migrations();
        if ($ran) {
            admin_log_event('info', 'migrations.ran', 'Admin ran pending migrations.', ['versions' => $ran]);
            flash_message('admin_notice', '' . e(t('admin.dashboard.notice_migrations_applied', 'Applied migrations:')) . ' ' . implode(', ', $ran) . '.');
            redirect_to(url_for('admin'));
        }
        admin_log_event('info', 'migrations.current', 'Admin checked migrations and database was already current.');
        flash_message('admin_notice', '' . e(t('admin.dashboard.notice_database_current', 'Database is already current.')) . '');
        redirect_to(url_for('admin'));
    } catch (Throwable $exception) {
        admin_log_event('error', 'migrations.failed', 'Admin migration run failed.', ['exception' => $exception->getMessage()]);
        flash_message('admin_notice', '' . e(t('admin.dashboard.notice_migration_failed', 'Migration failed:')) . ' ' . $exception->getMessage());
        redirect_to(url_for('admin'));
    }
}
