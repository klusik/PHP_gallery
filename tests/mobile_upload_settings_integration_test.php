<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/mobile_upload_settings_integration_test.php
 * Module Type: Test
 * Purpose:
 *   Exercise mobile connection integration without live database dependencies.
 * Author: Rudolf Klusal
 * Responsibilities:
 *   Verify capability-OFF preparation and compact delegated mobile settings forms.
 */

declare(strict_types=1);

namespace Gallery\Services {
    /**
     * Read the fixture capability switch without touching storage.
     *
     * @param string $key Requested capability name.
     * @return bool Configured fixture availability.
     */
    function feature_capability_effective_enabled(string $key): bool
    {
        return !empty($GLOBALS['mobile_settings_enabled']);
    }

    /**
     * Reject accidental schema probes while the fixture capability is disabled.
     *
     * @return bool Never reached when the capability is disabled.
     */
    function mobile_webdav_ready(): bool
    {
        if (empty($GLOBALS['mobile_settings_enabled'])) {
            throw new \RuntimeException('Disabled mobile uploads probed schema.');
        }
        return true;
    }

    /**
     * Supply fallback prose for fixture translations.
     *
     * @param string $key Translation key.
     * @param string $fallback Fixture prose.
     * @param array<string,mixed> $params Unused placeholder values.
     * @return string Untranslated fixture prose.
     */
    function t(string $key, string $fallback = '', array $params = []): string
    {
        return $fallback;
    }

    /**
     * Return empty connection inventory or inject a post-persistence read failure.
     *
     * @return array<int,array<string,mixed>> Empty persisted fixture inventory.
     */
    function mobile_webdav_tokens(): array
    {
        if (!empty($GLOBALS['mobile_settings_inventory_failure'])) {
            throw new \RuntimeException('private inventory exception /secret/path');
        }
        return [];
    }

    /**
     * Simulate persisted connection creation with a stable ID and one-time password.
     *
     * @param int $userId Authenticated administrator.
     * @param int $galleryId Selected gallery.
     * @param string $label Connection label.
     * @return array<string,mixed> Exact persisted ID and one-time credentials.
     */
    function mobile_webdav_create_token(int $userId, int $galleryId, string $label): array
    {
        if ($galleryId !== 8) {
            throw new \RuntimeException('private schema exception /secret/path');
        }
        return ['id' => 29, 'password' => 'one-time-password', 'username' => 'phone', 'url' => '/webdav/example'];
    }

    /**
     * Record the connection identifier passed to the deletion service.
     *
     * @param int $tokenId Persisted connection identifier.
     * @return void Records the actual service deletion target.
     */
    function mobile_webdav_delete_token(int $tokenId): void
    {
        $GLOBALS['mobile_settings_deleted_id'] = $tokenId;
    }
}

namespace Gallery\Core {
    /**
     * Escape fixture text through the normal HTML presentation boundary.
     *
     * @param string $value Fixture text.
     * @return string Escaped presentation text.
     */
    function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Record that the controller required administrator authentication.
     *
     * @return void Records the authentication boundary invocation.
     */
    function require_admin(): void
    {
        $GLOBALS['mobile_settings_auth'] = true;
    }

    /**
     * Record that the controller checked the CSRF boundary.
     *
     * @return void Records the CSRF boundary invocation.
     */
    function verify_csrf(): void
    {
        $GLOBALS['mobile_settings_csrf'] = true;
    }

    /**
     * Supply the POST transport used by the fixture controller calls.
     *
     * @return string Fixture HTTP method.
     */
    function request_method(): string
    {
        return 'POST';
    }

    /**
     * Supply the authenticated administrator used by connection creation.
     *
     * @return array{id:int} Authenticated fixture administrator.
     */
    function current_user(): array
    {
        return ['id' => 1];
    }

    /**
     * Supply already prepared CSRF markup to the fixture view model.
     *
     * @return string Safe fixture hidden input.
     */
    function csrf_field(): string
    {
        return '<input name="csrf" value="fixture">';
    }

    /**
     * Build fixture route destinations using the requested section parameters.
     *
     * @param string $route Route name.
     * @param array<string,string> $params Route parameters.
     * @return string Fixture route URL.
     */
    function url_for(string $route, array $params = []): string
    {
        return '/?route=' . $route . ($params === [] ? '' : '&' . http_build_query($params));
    }

    /**
     * Store and consume fallback notices through the fixture flash boundary.
     *
     * @param string $key Flash namespace.
     * @param string|null $value Optional new notice.
     * @return string|null Read or saved fixture notice.
     */
    function flash_message(string $key, ?string $value = null): ?string
    {
        if ($value !== null) {
            $GLOBALS['mobile_settings_flash'][$key] = $value;
            return null;
        }
        $notice = $GLOBALS['mobile_settings_flash'][$key] ?? null;
        unset($GLOBALS['mobile_settings_flash'][$key]);
        return $notice;
    }

    /**
     * Record fallback navigation and interrupt fixture execution like a redirect.
     *
     * @param string $url Controller-selected fallback destination.
     * @return void Stops fixture dispatch after recording the redirect.
     */
    function redirect_to(string $url): void
    {
        $GLOBALS['mobile_settings_redirect'] = $url;
        throw new \RuntimeException('fixture redirect', 302);
    }
}

namespace Gallery\Controllers {
    /**
     * Supply gallery options or inject a presentation-read failure after persistence.
     *
     * @param int $galleryId Selected fixture gallery.
     * @return string Already prepared gallery option markup.
     */
    function gallery_options_for_select(int $galleryId): string
    {
        if (!empty($GLOBALS['mobile_settings_gallery_failure'])) {
            throw new \RuntimeException('private gallery exception /secret/path');
        }
        return '<option value="8">Gallery</option>';
    }
}

namespace {
    require_once dirname(__DIR__) . '/app/controllers/mobile_webdav.php';
    require_once dirname(__DIR__) . '/app/views/mobile_webdav.php';
    require_once dirname(__DIR__) . '/app/helpers_mutation.php';

    /**
     * Fail the fixture when a boundary invariant is violated.
     *
     * @param bool $condition Observed invariant.
     * @param string $message Failure explanation.
     * @return void Throws on failure.
     */
    function mobile_settings_assert(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $_SESSION['mobile_webdav_created'] = ['password' => 'once-secret'];
    $disabled = \Gallery\Controllers\admin_mobile_uploads_settings_view_model();
    mobile_settings_assert(!empty($disabled['disabled']), 'OFF state must be explicit.');
    mobile_settings_assert($disabled['tokens'] === [] && $disabled['created'] === null, 'OFF state exposed connections or credentials.');
    mobile_settings_assert(!isset($_SESSION['mobile_webdav_created']), 'One-time credentials remained in session.');
    ob_start();
    \Gallery\Views\view_render_admin_mobile_uploads_settings($disabled);
    $disabledHtml = (string) ob_get_clean();
    mobile_settings_assert(!str_contains($disabledHtml, '<form') && !str_contains($disabledHtml, 'once-secret'), 'OFF fragment exposes mutations or secrets.');

    $form = ['action_url' => '/?route=admin_mobile_uploads', 'csrf_html' => '<input name="csrf" value="fixture">', 'gallery_options_html' => '<option value="8">Gallery</option>'];
    ob_start();
    \Gallery\Views\view_render_admin_mobile_uploads_settings([
        'ready' => true,
        'create_form' => $form,
        'token_list' => $form,
        'created' => ['password' => '<private>', 'url' => '/webdav/example', 'username' => 'mobile-example'],
        'tokens' => [['id' => 12, 'label' => '<phone>', 'gallery_title' => 'Gallery', 'absolute_url' => '/webdav/example']],
    ]);
    $html = (string) ob_get_clean();
    mobile_settings_assert(substr_count($html, 'data-admin-settings-mobile-form') === 2, 'Create and delete must both use delegated settings forms.');
    mobile_settings_assert(substr_count($html, 'name="return_context" value="settings_uploads"') === 2, 'Fallback context missing.');
    mobile_settings_assert(str_contains($html, 'name="token_id" value="12"'), 'Stable token identifier missing.');
    mobile_settings_assert(str_contains($html, '&lt;private&gt;') && str_contains($html, '&lt;phone&gt;'), 'Credentials and labels must be escaped.');
    mobile_settings_assert(!str_contains($html, 'confirm('), 'Compact connection mutation added a confirmation flow.');
    $GLOBALS['mobile_settings_enabled'] = true;
    $_POST = ['return_context' => 'settings_uploads', 'ajax' => '1', 'action' => ' CREATE ', 'gallery_id' => '8', 'label' => 'Phone'];
    ob_start();
    \Gallery\Controllers\cms_admin_mobile_uploads();
    $success = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    mobile_settings_assert(!empty($GLOBALS['mobile_settings_auth']) && !empty($GLOBALS['mobile_settings_csrf']), 'Mutation skipped authentication or CSRF.');
    mobile_settings_assert($success['ok'] && $success['mutation']['type'] === 'mobile_upload.create' && $success['mutation']['entity_ids'] === [29], 'Create envelope lost the exact persisted identifier.');
    mobile_settings_assert($success['panel'] === null && $success['contexts'] === [] && str_contains($success['fallback']['redirect_url'], 'section=uploads'), 'Settings completion must preserve the canonical fallback.');
    mobile_settings_assert(str_contains($success['html'], 'one-time-password') && !isset($_SESSION['mobile_webdav_created']), 'AJAX credentials must appear once without session replay.');

    $_POST['gallery_id'] = '0';
    ob_start();
    \Gallery\Controllers\cms_admin_mobile_uploads();
    $failureRaw = (string) ob_get_clean();
    $failure = json_decode($failureRaw, true, 512, JSON_THROW_ON_ERROR);
    mobile_settings_assert(!$failure['ok'] && !str_contains($failureRaw, 'private schema exception') && !str_contains($failureRaw, '/secret/path'), 'Native exception text leaked into the error response.');

    $_POST = ['return_context' => 'settings_uploads', 'ajax' => '1', 'action' => 'delete', 'token_id' => '29'];
    ob_start();
    \Gallery\Controllers\cms_admin_mobile_uploads();
    $deleted = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    mobile_settings_assert($deleted['ok'] && $deleted['mutation']['entity_ids'] === [29] && $GLOBALS['mobile_settings_deleted_id'] === 29, 'Delete envelope and service target diverged.');
    foreach (['mobile_settings_inventory_failure', 'mobile_settings_gallery_failure'] as $failureFlag) {
        $GLOBALS[$failureFlag] = true;
        $_POST = ['return_context' => 'settings_uploads', 'ajax' => '1', 'action' => 'create', 'gallery_id' => '8', 'label' => 'Phone'];
        ob_start();
        \Gallery\Controllers\cms_admin_mobile_uploads();
        $degradedRaw = (string) ob_get_clean();
        $degraded = json_decode($degradedRaw, true, 512, JSON_THROW_ON_ERROR);
        mobile_settings_assert($degraded['ok'] && $degraded['mutation']['entity_ids'] === [29], 'A post-persistence read failure changed the mutation result.');
        mobile_settings_assert(str_contains($degraded['html'], 'one-time-password') && !str_contains($degraded['html'], '<form'), 'Degraded inventory must preserve credentials and hide unavailable mutations.');
        mobile_settings_assert(!str_contains($degradedRaw, '/secret/path') && !str_contains($degradedRaw, 'private inventory exception') && !str_contains($degradedRaw, 'private gallery exception'), 'Degraded state leaked the native read error.');
        mobile_settings_assert(!isset($_SESSION['mobile_webdav_created']), 'Degraded AJAX response introduced credential replay.');
        $GLOBALS[$failureFlag] = false;
    }

    $_POST = ['return_context' => 'settings_uploads', 'action' => 'create', 'gallery_id' => '8', 'label' => 'Phone'];
    try {
        \Gallery\Controllers\cms_admin_mobile_uploads();
        throw new RuntimeException('Fallback did not redirect.');
    } catch (RuntimeException $exception) {
        mobile_settings_assert($exception->getCode() === 302, 'Fallback failed before redirect.');
    }
    mobile_settings_assert(str_contains($GLOBALS['mobile_settings_redirect'], 'section=uploads'), 'Fallback left unified upload settings.');
    $GLOBALS['mobile_settings_inventory_failure'] = true;
    $fallbackModel = \Gallery\Controllers\admin_mobile_uploads_settings_view_model();
    mobile_settings_assert($fallbackModel['created']['password'] === 'one-time-password' && !isset($_SESSION['mobile_webdav_created']), 'Fallback read failure lost or replayed credentials.');
    $repeatedModel = \Gallery\Controllers\admin_mobile_uploads_settings_view_model();
    mobile_settings_assert($repeatedModel['created'] === null, 'Fallback credentials were shown more than once.');
    $GLOBALS['mobile_settings_inventory_failure'] = false;
    echo "Mobile upload settings integration: PASS\n";
}
