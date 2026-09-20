<?php
/**
 * Project: PHP Gallery
 * Repository: https://github.com/klusik/PHP_gallery
 * File: tests/upload_writer_ownership_test.php
 * Module Type: Regression Test
 * Purpose: Exercise real upload service entry points and reentrant writer ownership.
 * Responsibilities:
 *   - Refuse competing writers before entering domain/file work and release nested leases on failure.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 * Notes: Isolated SQL and missing-gallery seams; no live database, files, or decoding.
 */
declare(strict_types=1);

namespace Gallery\Core {
    require_once __DIR__ . '/support/admin_operation_fixture.php';
}

namespace Gallery\Services {
    /**
     * Bounded fixture message.
     *
     * @param string $key Translation key.
     * @param string|null $fallback Safe fallback text.
     * @return string Bounded fixture message.
     */
    function t(string $key, ?string $fallback = null): string { return $fallback ?? $key; }
    /**
     * Unchanged domain kind.
     *
     * @param string $kind Valid fixture branding kind.
     * @return string Unchanged domain kind.
     */
    function gallery_branding_asset_kind(string $kind): string { return $kind; }
    /**
     * Refuse before any target filesystem access.
     *
     * @param int $galleryId Deliberately missing fixture identity.
     * @param bool $fresh Required cache bypass under ownership.
     * @return array<string,mixed>|null Refuse before any target filesystem access.
     */
    function find_gallery(int $galleryId, bool $fresh = false): ?array
    {
        \upload_writer_expect($fresh, 'Upload reused a potentially pre-lock cached gallery.');
        $database = $GLOBALS['operation_fixture_db'];
        $name = hash('sha256', 'disposable_operation_fixture' . \Gallery\Core\GALLERY_EDIT_LOCK_SUFFIX);
        \upload_writer_expect(($database->locks[$name]['connection'] ?? null) === $database->connection, 'Upload entered domain work without owning the writer lease.');
        ++$GLOBALS['upload_writer_domain_calls'];
        return null;
    }
}

namespace {
    require_once __DIR__ . '/../app/services/uploads.php';

    /**
     * Fail without exposing native diagnostics.
     *
     * @param bool $condition Required ownership invariant.
     * @param string $message Bounded assertion failure.
     * @return void Fail without exposing native diagnostics.
     */
    function upload_writer_expect(bool $condition, string $message): void
    {
        if (!$condition) { throw new RuntimeException($message); }
    }

    /**
     * Confirm a guarded domain refusal.
     *
     * @param string $function Public upload service entry point.
     * @param list<mixed> $arguments Missing-gallery fixture input.
     * @return void Confirm a guarded domain refusal.
     */
    function upload_writer_refused(string $function, array $arguments): void
    {
        try {
            $function(...$arguments);
        } catch (RuntimeException $exception) {
            upload_writer_expect($exception->getMessage() === 'Gallery not found.', 'Upload did not reach its guarded missing-gallery boundary.');
            return;
        }
        throw new RuntimeException('Missing fixture gallery unexpectedly admitted upload work.');
    }

    $GLOBALS['operation_fixture_db'] = $database = new \Gallery\Core\AdminOperationFixtureDatabase();
    $GLOBALS['upload_writer_domain_calls'] = 0;
    $cases = [
        'Gallery\\Services\\store_uploaded_gallery_images' => [99, []],
        'Gallery\\Services\\store_uploaded_gallery_cover' => [99, []],
        'Gallery\\Services\\store_uploaded_gallery_branding_asset' => [99, 'logo', []],
    ];
    foreach ($cases as $function => $arguments) {
        upload_writer_refused($function, $arguments);
        upload_writer_expect($database->locks === [], 'Failed upload leaked a writer lease.');

        $outer = \Gallery\Services\gallery_edit_writer_begin();
        try {
            upload_writer_refused($function, $arguments);
            upload_writer_expect(($database->locks[$outer]['count'] ?? 0) === 1, 'Nested upload released the caller-owned lease.');
            $before = $GLOBALS['upload_writer_domain_calls'];
            $database->connection = 2;
            $conflict = false;
            try {
                $function(...$arguments);
            } catch (\Gallery\Services\GalleryEditConflict $exception) {
                $conflict = $exception->busy;
            } finally {
                $database->connection = 1;
            }
            upload_writer_expect($conflict && $GLOBALS['upload_writer_domain_calls'] === $before, 'Competing writer reached upload domain/file work.');
        } finally {
            \Gallery\Services\gallery_edit_writer_end($outer);
        }
        upload_writer_expect($database->locks === [], 'Reentrant upload failed to balance its writer lease.');
    }
    echo "PASS upload writer ownership (real three upload facades and lock model; isolated missing-gallery/SQL seams)\n";
}
