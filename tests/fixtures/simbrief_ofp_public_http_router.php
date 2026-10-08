<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/fixtures/simbrief_ofp_public_http_router.php
 * Module Type: Isolated HTTP Fixture
 * Purpose: Exercise the real OFP PDF controller through root/subdirectory URLs.
 * Responsibilities:
 *   - Provide deterministic gallery and session/access models without a database
 *   - Serve the repository's actual attachment service and media controller
 *   - Refuse all unrelated routes without exposing the fixture's source tree
 * Author: Rudolf Klusal
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Core {
    /** Resolve only the fixture's explicit authenticated administrator cookie.
     * @return ?array<string,mixed> Fixture administrator or null for fresh visitors.
     */
    function current_user(): ?array
    {
        return ($_COOKIE['ofp_test_admin'] ?? '') === '1' ? ['id' => 1, 'role' => 'admin'] : null;
    }

    /** Indicate when the fixture explicitly requests anonymous Admin preview.
     * @return bool Whether authenticated authority must be ignored.
     */
    function admin_anonymous_preview_active(): bool
    {
        return ($_GET['anonymous_preview'] ?? '') === '1';
    }

    /** Check the resolved attachment path against its fixture-owned root.
     * @param string $root Gallery root path.
     * @param string $path Candidate PDF path.
     * @return bool True only when the path is inside the actual gallery root.
     */
    function path_inside(string $root, string $path): bool
    {
        $root = realpath($root);
        $path = realpath($path);
        return is_string($root) && is_string($path)
            && ($path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR));
    }

    /** Return the actual front-controller mount path, not configured base_url.
     * @return string Root or non-root deployment prefix.
     */
    function request_script_base_path(): string
    {
        return str_starts_with((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '/galerie/')
            ? '/galerie' : '';
    }
}

namespace Gallery\Services {
    /** Keep the real SEO guard enabled without persisting fixture rejection logs.
     * @param string $key Application setting name.
     * @param string $default Setting default.
     * @return string Guard policy value for this isolated HTTP request.
     */
    function app_setting(string $key, string $default = ''): string
    {
        return $key === 'seo_request_guard_logging_enabled' ? '0' : $default;
    }

    /** Return a fixture gallery row, including its true attachment owner.
     * @param int $id Source gallery ID.
     * @param bool $fresh Unused historical lookup option.
     * @return ?array<string,mixed> One gallery or null for an unknown ID.
     */
    function find_gallery(int $id, bool $fresh = false): ?array
    {
        $galleries = [
            1 => ['folder_path' => 'public', 'visibility' => 'public'],
            2 => ['folder_path' => 'unpublished', 'visibility' => 'unpublished'],
            3 => ['folder_path' => 'private', 'visibility' => 'private'],
            4 => ['folder_path' => 'password', 'visibility' => 'public', 'access_mode' => 'password'],
            5 => ['folder_path' => 'nsfw', 'visibility' => 'public', 'nsfw_enabled' => 1],
            6 => ['folder_path' => 'missing', 'visibility' => 'public'],
            7 => ['folder_path' => 'invalid', 'visibility' => 'public'],
            8 => ['folder_path' => 'parent', 'visibility' => 'public'],
            9 => ['folder_path' => 'parent/ofp-pages', 'visibility' => 'private', 'parent_id' => 8],
            10 => ['folder_path' => 'share', 'visibility' => 'public', 'access_mode' => 'password'],
            11 => ['folder_path' => 'unpublished-password', 'visibility' => 'unpublished', 'access_mode' => 'password'],
            12 => ['folder_path' => 'unlisted', 'visibility' => 'public', 'access_listing' => 'unlisted'],
            13 => ['folder_path' => 'unpublished-nsfw', 'visibility' => 'unpublished', 'nsfw_enabled' => 1],
            14 => ['folder_path' => 'legacy-draft', 'visibility' => 'draft'],
            15 => ['folder_path' => 'private-share', 'visibility' => 'private', 'access_mode' => 'password'],
        ];
        return isset($galleries[$id]) ? ['id' => $id] + $galleries[$id] : null;
    }

    /** Normalize public, unpublished/unlisted, legacy draft, and private visibility.
     * @param array<string,mixed> $gallery Gallery row.
     * @return string Effective visibility matching the existing gallery model.
     */
    function gallery_effective_visibility(array $gallery): string
    {
        $visibility = (string) ($gallery['visibility'] ?? 'private');
        if ($visibility === 'draft' || $visibility === 'unlisted'
            || ($visibility === 'public' && ($gallery['access_listing'] ?? 'listed') === 'unlisted')) {
            return 'unpublished';
        }
        return $visibility;
    }

    /** Emulate normal gallery direct-link, password/share and NSFW access.
     *
     * Unlike a bare file-server stub, unpublished is readable by direct URL
     * while private is denied without an explicit parent-gallery access grant.
     *
     * @param array<string,mixed> $gallery Protected gallery row.
     * @return bool True when the same visitor may view the gallery itself.
     */
    function visitor_can_access_gallery_without_admin_bypass(array $gallery): bool
    {
        $direct = in_array(gallery_effective_visibility($gallery), ['public', 'unpublished'], true);
        $hasPassword = ($gallery['access_mode'] ?? 'normal') === 'password';
        $passwordUnlock = ($_COOKIE['ofp_test_password'] ?? '') === '1';
        $shareUnlock = in_array((int) ($gallery['id'] ?? 0), [10, 15], true)
            && ($_GET['share'] ?? '') === 'fixture-allowed';
        $granted = $passwordUnlock || $shareUnlock;
        if ((!$direct && !$hasPassword) || ($hasPassword && !$granted)) {
            return false;
        }
        if (!empty($gallery['nsfw_enabled']) && ($_COOKIE['ofp_test_adult'] ?? '') !== '1') {
            return false;
        }
        return true;
    }

    /** Resolve only the private temporary gallery storage of this HTTP fixture.
     * @return string Absolute gallery tree path.
     */
    function galleries_root(): string
    {
        return (string) getenv('PHP_GALLERY_OFP_HTTP_ROOT') . '/galleries';
    }

    /** Resolve a fixture gallery-relative path inside the private test root.
     * @param string $relativePath Preconfigured catalog folder path.
     * @return string Absolute path to the attached PDF directory.
     */
    function gallery_abs_path(string $relativePath): string
    {
        return galleries_root() . '/' . $relativePath;
    }

    /** The fixture administrator is never a known under-18 account.
     * @return bool False for all fixture requests.
     */
    function current_user_is_known_under_18(): bool
    {
        return false;
    }
}

namespace Gallery\Controllers {
    /** Strip session-cache defaults as the real HTTP response helper would.
     * @return void Remove inherited cache-control headers.
     */
    function clear_response_cache_headers(): void
    {
        header_remove('Cache-Control');
        header_remove('Pragma');
        header_remove('Expires');
    }
}

namespace {
    $routePath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!in_array($routePath, ['/index.php', '/galerie/index.php'], true)) {
        http_response_code(404);
        return;
    }

    // The browser-visible route path simulates both deployment layouts, even
    // when PHP's development-server router is implemented by this fixture file.
    $_SERVER['SCRIPT_NAME'] = $routePath;
    require_once dirname(__DIR__, 2) . '/app/services/simbrief_ofp_attachments.php';
    require_once dirname(__DIR__, 2) . '/app/controllers/public_media.php';
    require_once dirname(__DIR__, 2) . '/app/controllers/public_gallery_controls.php';
    require_once dirname(__DIR__, 2) . '/app/services/seo_request_guard.php';

    if (($_GET['fixture_links'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'view' => \Gallery\Controllers\public_gallery_ofp_document_url(1),
            'download' => \Gallery\Controllers\public_gallery_ofp_document_url(1, true),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return;
    }

    $page = (string) ($_GET['page'] ?? '');
    // Production cms_initialize_request() evaluates this same SEO policy
    // before the runtime dispatcher calls either PDF controller.
    $decision = \Gallery\Services\seo_request_guard_enforcement_decision(
        $page,
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        $_GET,
        \Gallery\Core\current_user() !== null,
        (string) ($_SERVER['REQUEST_URI'] ?? ''),
        '127.0.0.1',
        'ofp-http-fixture'
    );
    if (($decision['action'] ?? 'allow') !== 'allow') {
        http_response_code((int) ($decision['status'] ?? 404));
        foreach (($decision['headers'] ?? []) as $name => $value) {
            header((string) $name . ': ' . (string) $value);
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            echo (string) ($decision['body'] ?? '');
        }
        return;
    }

    if ($page === 'gallery_ofp_pdf') {
        \Gallery\Controllers\cms_gallery_ofp_pdf();
    } elseif ($page === 'media' && ($_GET['ofp'] ?? '') === '1') {
        \Gallery\Controllers\cms_media();
    } else {
        http_response_code(404);
    }
}
