<?php

/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 *
 * File: tests/public_media_authorization_contract_test.php
 * Module Type: Regression Test
 *
 * Purpose:
 *   Locks the canonical public gallery/image authorization semantics used by media surfaces.
 *
 * Responsibilities:
 *   - Exercise direct, unpublished, private, password, ancestor, NSFW, share-token, and admin cases
 *   - Protect the no-admin-bypass policy used by viewer-owned source-image references
 *   - Verify public media/download surfaces continue to call the canonical authorization services
 *
 * Author:
 *   Rudolf Klusal
 *
 * License:
 *   MIT License (see LICENSE file in repository)
 *
 * Notes:
 *   - Keep comments and docstrings intact when modifying this file.
 *   - This fixture intentionally stubs schema inspection as fully available.
 */

declare(strict_types=1);

namespace Gallery\Core {
    /** Return the isolated authenticated user for this policy fixture. */
    function current_user(): array|false
    {
        return $GLOBALS['public_media_authorization_user'] ?? false;
    }

    /** Return a stable timestamp for unused mutation paths in this fixture. */
    function now_sql(): string
    {
        return '2026-09-19 00:00:00';
    }

    /** Return an empty configuration value for unused compatibility paths. */
    function cms_config(string $key, mixed $default = null): mixed
    {
        return $default;
    }
}

namespace Gallery\Services {
    /** Return one deterministic gallery from the isolated ancestry map. */
    function find_gallery(int $galleryId): ?array
    {
        $gallery = $GLOBALS['public_media_authorization_galleries'][$galleryId] ?? null;
        return is_array($gallery) ? $gallery : null;
    }

    /** Return a fully available synthetic schema requirement. */
    function schema_inspection_column(string $table, string $column): array
    {
        return ['state' => 'available', 'table' => $table, 'column' => $column];
    }

    /** Return a fully available synthetic schema-definition requirement. */
    function schema_inspection_column_definition_contains(string $table, string $column, string $needle): array
    {
        return ['state' => 'available', 'table' => $table, 'column' => $column, 'needle' => $needle];
    }

    /** Aggregate synthetic schema requirements for this isolated test. */
    function schema_inspection_feature(string $feature, array $requirements): array
    {
        return ['state' => 'available', 'feature' => $feature, 'requirements' => $requirements];
    }

    /** Return true for the fully available synthetic state. */
    function schema_inspection_is_available(array $status): bool
    {
        return ($status['state'] ?? '') === 'available';
    }

    /** Return true only for an explicit synthetic missing state. */
    function schema_inspection_is_missing(array $status): bool
    {
        return ($status['state'] ?? '') === 'missing';
    }

    /** Return true only for an explicit synthetic unknown state. */
    function schema_inspection_is_unknown(array $status): bool
    {
        return ($status['state'] ?? '') === 'unknown';
    }
}

namespace {
    use function Gallery\Services\gallery_access_session_key;
    use function Gallery\Services\grant_gallery_public_access;
    use function Gallery\Services\grant_nsfw_guard_access;
    use function Gallery\Services\public_image_visible_to_current_visitor;
    use function Gallery\Services\visitor_can_access_gallery_without_admin_bypass;

    /** Throw when one public authorization contract fails. */
    function public_media_authorization_assert(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException($label);
        }
    }

    /** Build a gallery fixture with explicit policy defaults. */
    function public_media_authorization_gallery(int $id, string $visibility = 'public', string $accessMode = 'normal', ?int $parentId = null, int $nsfw = 0): array
    {
        return [
            'id' => $id,
            'parent_id' => $parentId,
            'visibility' => $visibility,
            'access_listing' => 'listed',
            'access_mode' => $accessMode,
            'access_password_hash' => $accessMode === 'password' ? password_hash('secret', PASSWORD_DEFAULT) : null,
            'access_token_hash' => null,
            'access_token_expires_at' => null,
            'nsfw_enabled' => $nsfw,
        ];
    }

    /** Build one public image fixture. */
    function public_media_authorization_image(int $galleryId, int $nsfw = 0, string $visibility = 'public'): array
    {
        return [
            'id' => 1000 + $galleryId,
            'gallery_id' => $galleryId,
            'visibility' => $visibility,
            'nsfw_enabled' => $nsfw,
        ];
    }

    $root = dirname(__DIR__);
    require_once $root . '/app/services/gallery_access.php';

    $_SESSION = [];
    $_GET = [];
    $GLOBALS['public_media_authorization_user'] = false;

    $public = public_media_authorization_gallery(1, 'public');
    $unpublished = public_media_authorization_gallery(2, 'unpublished');
    $private = public_media_authorization_gallery(3, 'private');
    $password = public_media_authorization_gallery(4, 'public', 'password');
    $passwordChild = public_media_authorization_gallery(5, 'public', 'normal', 4);
    $passwordUnpublishedChild = public_media_authorization_gallery(6, 'unpublished', 'normal', 4);
    $unpublishedParent = public_media_authorization_gallery(7, 'unpublished');
    $publicChildOfUnpublished = public_media_authorization_gallery(8, 'public', 'normal', 7);
    $nsfw = public_media_authorization_gallery(9, 'public', 'normal', null, 1);
    $shareProtected = public_media_authorization_gallery(10, 'private', 'password');
    $shareToken = 'fixture-share-token';
    $shareProtected['access_token_hash'] = hash('sha256', $shareToken);
    $shareProtected['access_token_expires_at'] = '2099-01-01 00:00:00';

    $GLOBALS['public_media_authorization_galleries'] = [
        1 => $public,
        2 => $unpublished,
        3 => $private,
        4 => $password,
        5 => $passwordChild,
        6 => $passwordUnpublishedChild,
        7 => $unpublishedParent,
        8 => $publicChildOfUnpublished,
        9 => $nsfw,
        10 => $shareProtected,
    ];

    public_media_authorization_assert(
        public_image_visible_to_current_visitor(public_media_authorization_image(1), $public),
        'Public gallery/public image must remain directly visible.'
    );
    public_media_authorization_assert(
        public_image_visible_to_current_visitor(public_media_authorization_image(2), $unpublished),
        'Unpublished galleries must retain direct-URL media access semantics.'
    );
    public_media_authorization_assert(
        !public_image_visible_to_current_visitor(public_media_authorization_image(3), $private),
        'Private gallery media must not be exposed to an anonymous visitor.'
    );
    public_media_authorization_assert(
        !public_image_visible_to_current_visitor(public_media_authorization_image(4), $password),
        'Password gallery media must remain blocked before unlock.'
    );

    grant_gallery_public_access(4);
    public_media_authorization_assert(
        public_image_visible_to_current_visitor(public_media_authorization_image(4), $password),
        'Password gallery media must become available after a valid unlock.'
    );
    public_media_authorization_assert(
        public_image_visible_to_current_visitor(public_media_authorization_image(5), $passwordChild),
        'Public child media must inherit a valid password grant from its protected parent.'
    );
    public_media_authorization_assert(
        public_image_visible_to_current_visitor(public_media_authorization_image(6), $passwordUnpublishedChild),
        'Unpublished child media must inherit a valid password grant from its protected parent.'
    );

    unset($_SESSION[gallery_access_session_key(4)]);
    public_media_authorization_assert(
        !public_image_visible_to_current_visitor(public_media_authorization_image(5), $passwordChild),
        'Public child media must not bypass a locked password-protected parent.'
    );
    public_media_authorization_assert(
        !public_image_visible_to_current_visitor(public_media_authorization_image(6), $passwordUnpublishedChild),
        'Unpublished child media must not bypass a locked password-protected parent.'
    );
    public_media_authorization_assert(
        public_image_visible_to_current_visitor(public_media_authorization_image(8), $publicChildOfUnpublished),
        'A public child under an unpublished non-password parent must preserve current direct-access semantics.'
    );

    public_media_authorization_assert(
        !public_image_visible_to_current_visitor(public_media_authorization_image(9), $nsfw),
        'NSFW gallery media must remain blocked without the session acknowledgment.'
    );
    grant_nsfw_guard_access();
    public_media_authorization_assert(
        public_image_visible_to_current_visitor(public_media_authorization_image(9), $nsfw),
        'NSFW gallery media must become available after the valid session acknowledgment.'
    );

    $_SESSION = [];
    $_GET = ['share' => $shareToken];
    public_media_authorization_assert(
        public_image_visible_to_current_visitor(public_media_authorization_image(10), $shareProtected),
        'A valid current-request share token must authorize the protected gallery media.'
    );

    $_SESSION = [];
    $_GET = [];
    $GLOBALS['public_media_authorization_user'] = ['id' => 1, 'username' => 'admin'];
    public_media_authorization_assert(
        public_image_visible_to_current_visitor(public_media_authorization_image(4), $password),
        'The normal public-image policy must retain the intentional administrator bypass.'
    );
    public_media_authorization_assert(
        !visitor_can_access_gallery_without_admin_bypass($password),
        'Viewer-owned source-image references must not inherit the administrator bypass.'
    );
    public_media_authorization_assert(
        !public_image_visible_to_current_visitor(public_media_authorization_image(1, 0, 'private'), $public),
        'Image-level non-public visibility must remain a hard deny even for an administrator-oriented public-image decision.'
    );

    $publicMediaSource = (string) file_get_contents($root . '/app/controllers/public_media.php');
    $downloadsSource = (string) file_get_contents($root . '/app/services/downloads.php');
    $viewerContentSource = (string) file_get_contents($root . '/app/services/viewer_content_foundations.php');

    public_media_authorization_assert(
        substr_count($publicMediaSource, 'public_image_visible_to_current_visitor(') >= 4,
        'Public media endpoints must continue to delegate image authorization to the canonical public-image policy.'
    );
    public_media_authorization_assert(
        substr_count($downloadsSource, 'public_image_visible_to_current_visitor(') >= 6,
        'Download manifest/build paths must continue to delegate image authorization to the canonical public-image policy.'
    );
    public_media_authorization_assert(
        substr_count($viewerContentSource, 'visitor_can_access_gallery_without_admin_bypass(') >= 2,
        'Viewer-owned source-image reference flows must continue to use the no-admin-bypass gallery policy.'
    );

    echo "public_media_authorization_contract_test: ok\n";
}
