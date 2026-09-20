<?php
/**
 * Project: PHP Gallery
 * Responsibilities:
 *   - Validate revisions and prepare bounded conflict data without leaking secret settings.
 * Repository: https://github.com/klusik/PHP_gallery
 * File: app/services/gallery_edit_concurrency.php
 * Module Type: Service
 * Purpose: Define gallery edit preconditions, bounded conflicts and operation ownership.
 * Author: Rudolf Klusal
 * Contact: https://github.com/klusik
 * License: MIT License (see LICENSE file in repository)
 */
declare(strict_types=1);

namespace Gallery\Services;

use RuntimeException;
use Throwable;
use function Gallery\Models\gallery_edit_model_release;
use function Gallery\Models\gallery_edit_model_lock;
use function Gallery\Models\gallery_edit_model_reserve;

require_once dirname(__DIR__) . '/models/gallery_edit_concurrency.php';

/** A refused stale/busy edit, carrying only the approved non-secret comparison projection. */
final class GalleryEditConflict extends RuntimeException
{
    /**
     * Preserve bounded conflict context; no raw gallery row may cross this boundary.
     *
     * @param array<string,mixed> $latest Non-secret comparison fields, or empty for a busy/missing row.
     * @param bool $busy True when another connection still owns the edit operation.
     * @return void
     */
    public function __construct(public readonly array $latest, public readonly bool $busy = false)
    {
        parent::__construct($busy
            ? t('admin.gallery_editor.edit_busy', 'Another gallery operation is still running. Your entries have been kept. Retry after it finishes.')
            : t('admin.gallery_editor.edit_conflict', 'This gallery changed after the form was opened. Your entries have been kept. Open the latest version and review your changes before saving.'));
    }
}

/** Refusal caused by missing or unknown schema/ownership; never contains a raw database exception. */
final class GalleryEditUnavailable extends RuntimeException
{
}

/**
 * Return the decimal revision carried by this exact row, without issuing a newer read.
 *
 * @param array<string,mixed> $gallery The same row used to prepare every base-gallery form field.
 * @return string Canonical unsigned decimal revision, or an empty unavailable marker.
 */
function gallery_edit_revision(array $gallery): string
{
    $revision = $gallery['edit_revision'] ?? null;
    return (is_int($revision) || is_string($revision)) && preg_match('/^[1-9][0-9]{0,19}$/D', (string) $revision) === 1
        ? (string) $revision : '';
}

/**
 * Observe revision storage using shared three-state schema inspection.
 *
 * @return string One of available, missing, unknown; no raw diagnostics escape.
 */
function gallery_edit_schema_state(): string
{
    $column = schema_inspection_column('galleries', 'edit_revision');
    return schema_inspection_is_available($column)
        ? 'available'
        : (schema_inspection_is_missing($column) ? 'missing' : 'unknown');
}

/**
 * Prepare explicit comparison fields; password/token/hash values are never included.
 *
 * @param array<string,mixed> $gallery Authoritative persistence row, used only within the service.
 * @return array<string,mixed> Non-secret base-gallery fields suitable for authenticated comparison.
 */
function gallery_edit_comparison(array $gallery): array
{
    return array_intersect_key($gallery, array_flip([
        'id', 'edit_revision', 'title', 'description', 'slug', 'parent_id', 'sort_order',
        'visibility', 'access_mode', 'access_listing', 'nsfw_enabled', 'gallery_date',
        'gallery_date_end', 'content_language', 'description_layout', 'grid_columns',
        'grid_rows', 'grid_use_for_subgalleries', 'thumbnail_min_size', 'thumbnail_max_size',
        'voting_enabled', 'show_filenames', 'count_badge_visibility', 'lightbox_browsing_mode',
        'picture_game_enabled', 'gps_map_enabled', 'background_source', 'cover_image_id',
    ]));
}

/**
 * Preserve non-secret draft values for no-JavaScript review without storing them in a session.
 *
 * @param array<string,mixed> $input Submitted editor fields; credentials and transport fields are untrusted.
 * @return array<string,mixed> Allowlisted draft values, including structured translation/attachment entries.
 */
function gallery_edit_review_draft(array $input): array
{
    $draft = gallery_edit_comparison($input);
    unset($draft['id'], $draft['edit_revision']);
    foreach (['folder_name', 'tags', 'translations', 'smart_gallery_children', 'flight_route_text',
        'access_type', 'access_action', 'clear_access_password', 'access_token_expires_at',
        'grid_override_enabled', 'gallery_thumbnail_min_size', 'gallery_thumbnail_max_size',
        'gallery_thumbnail_bounds_recursive', 'remove_branding_banner', 'remove_branding_logo',
        'remove_branding_separator'] as $key) {
        if (array_key_exists($key, $input)) {
            $draft[$key] = $input[$key];
        }
    }
    return $draft;
}

/**
 * Reserve a submitted version before any target side effects and retain the operation lock.
 *
 * @param array<string,mixed> $gallery Row read by the request; partial actions use it as their precondition.
 * @param int|string|null $expectedRevision Positive decimal hidden edit_revision; null is allowed only for partial actions. Other transport types are refused as malformed.
 * @param bool $completeForm Whether the request represents a previously rendered complete form.
 * @return array{lock:string,gallery:array<string,mixed>} Private lease: release lock only after every edit side effect.
 * @throws GalleryEditConflict On missing/malformed/stale revision or an active competing editor.
 * @throws GalleryEditUnavailable On schema/connection uncertainty, before target mutation.
 */
function gallery_edit_begin(array $gallery, mixed $expectedRevision, bool $completeForm = true): array
{
    $state = gallery_edit_schema_state();
    if ($state !== 'available') {
        throw new GalleryEditUnavailable($state === 'missing'
            ? t('admin.gallery_editor.edit_schema_missing', 'Gallery editing requires the revision migration. Run database migrations from this Gallery page, then reopen the form.')
            : t('admin.gallery_editor.edit_schema_unknown', 'Gallery edit protection could not be verified. Keep your entries and try again after database diagnostics are resolved.'));
    }
    if (!$completeForm && $expectedRevision === null) {
        $expectedRevision = gallery_edit_revision($gallery);
    }
    $expected = gallery_edit_revision(['edit_revision' => $expectedRevision]);
    if ($expected === '') {
        throw new GalleryEditConflict(gallery_edit_comparison($gallery));
    }
    try {
        $reservation = gallery_edit_model_reserve((int) $gallery['id'], $expected);
    } catch (Throwable) {
        throw new GalleryEditUnavailable(t('admin.gallery_editor.edit_schema_unknown', 'Gallery edit protection could not be verified. Keep your entries and try again after database diagnostics are resolved.'));
    }
    if ($reservation['state'] !== 'acquired') {
        throw new GalleryEditConflict(gallery_edit_comparison($reservation['gallery'] ?? []), $reservation['state'] === 'busy');
    }
    return ['lock' => (string) $reservation['lock'], 'gallery' => $reservation['gallery']];
}

/**
 * End the operation only after all editor-owned database and filesystem actions finish.
 *
 * @param array{lock:string,gallery:array<string,mixed>} $lease Private lease returned by gallery_edit_begin().
 * @return void
 */
function gallery_edit_end(array $lease): void
{
    gallery_edit_writer_end($lease['lock']);
}

/**
 * Exclude concurrent editor work before a specialized writer changes files or sidecars.
 *
 * This is the integration boundary for folder moves, ingestion, trash, asset
 * replacement and other writers with filesystem work preceding their SQL. It
 * is reentrant for use cases called inside the main editor's existing lease.
 * Model writers explicitly advance the revision in their own SQL.
 *
 * @return string Private lock name; pair with gallery_edit_writer_end() in finally.
 * @throws GalleryEditConflict If another connection owns the operation.
 * @throws GalleryEditUnavailable When the lock cannot be verified.
 */
function gallery_edit_writer_begin(): string
{
    try {
        $name = gallery_edit_model_lock();
    } catch (Throwable) {
        throw new GalleryEditUnavailable(t('admin.gallery_editor.edit_schema_unknown', 'Gallery edit protection could not be verified. Keep your entries and try again after database diagnostics are resolved.'));
    }
    if ($name === null) {
        throw new GalleryEditConflict([], true);
    }
    return $name;
}

/**
 * Release one specialized writer's lock without exposing raw connection errors.
 *
 * @param string $lockName Private name returned by gallery_edit_writer_begin().
 * @return void
 * @throws GalleryEditUnavailable On a connection error, using a bounded message.
 */
function gallery_edit_writer_end(string $lockName): void
{
    try {
        gallery_edit_model_release($lockName);
    } catch (Throwable) {
        throw new GalleryEditUnavailable(t('admin.gallery_editor.edit_connection_lost', 'The gallery operation connection was interrupted. Reopen the gallery and verify its state before trying again.'));
    }
}
