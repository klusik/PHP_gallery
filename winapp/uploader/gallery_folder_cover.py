# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_folder_cover.py
# Module Type: Shared Photos read service
# Purpose: Read a child gallery's currently authorized representative cover.
# Responsibilities: Own sizing, rendition validation, cached fallback and
#   bounded normalization for both native Photos workspaces.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Resolve folder artwork through the live Photos session in a read worker."""

import io
from threading import Event

from .linking import LinkedMediaPage, LinkingError, LinkingSession
from .gallery_view_preferences import normalize_card_format

try:
    from PIL import Image, ImageOps
except ImportError:
    Image = ImageOps = None


# Purpose: Bound and match the existing default subgallery artwork.
# Type: tuple[int, int]. Units: logical pixels. Scope: one folder cover.
# Consumers: folder_cover_size. Rationale: 112 by 68 keeps several columns visible.
FOLDER_COVER_SIZE = (112, 68)
# Purpose: Bound the original photo before retaining independently shaped artwork.
# Type: int. Units: pixels per edge. Scope: folder art and cached cover pixels.
# Consumers: folder_cover_size, _normalize_cover.
# Rationale: 112 preserves the original folder width in both frontend renderers.
FOLDER_COVER_EDGE = FOLDER_COVER_SIZE[0]
# Purpose: Reject excessive decoded memory before reading image pixels.
# Type: int. Units: source pixels. Scope: one authorized cover rendition.
# Consumers: _normalize_cover. Rationale: 36 megapixels is the existing folder bound.
FOLDER_COVER_MAX_PIXELS = 36_000_000


def folder_cover_size(card_format: str) -> tuple[int, int]:
    """Return the reference cover size before the surrounding folder rim.

    Args:
        card_format (str): Stored per-gallery folder format key.
    Returns:
        tuple[int, int]: Positive cover width and height in logical pixels.
    """
    ratio = normalize_card_format(card_format)
    if ratio == 'default':
        return FOLDER_COVER_SIZE
    left, right = ratio.split(':')
    width, height = int(left), int(right)
    scale = FOLDER_COVER_EDGE / max(width, height)
    return max(1, round(width * scale)), max(1, round(height * scale))


def _normalize_cover(payload: bytes) -> bytes | None:
    """Decode a bounded, orientation-correct PNG for local folder art.

    Args:
        payload (bytes): Authorized, encoded thumbnail from the active session.
    Returns:
        bytes | None: Normalized pixels or no admissible rendition.
    """
    if Image is None or ImageOps is None:
        return None
    with Image.open(io.BytesIO(payload)) as image:
        if image.width * image.height > FOLDER_COVER_MAX_PIXELS:
            return None
        edge = (FOLDER_COVER_EDGE, FOLDER_COVER_EDGE)
        image.draft('RGB', edge)
        with ImageOps.exif_transpose(image) as oriented:
            with oriented.convert('RGB') as rgb:
                with ImageOps.contain(rgb, edge) as preview, io.BytesIO() as output:
                    preview.save(output, format='PNG')
                    return output.getvalue()


def cached_folder_cover(
    session: LinkingSession, gallery_id: int, cancelled: Event,
) -> bytes | None:
    """Use a cached cover while its scoped page and validators remain current.

    Args:
        session (LinkingSession): Live scope and validator authority.
        gallery_id (int): Selected child's exact server identity.
        cancelled (Event): Current viewport cancellation boundary.
    Returns:
        bytes | None: Verified normalized cover or no admissible disk hit.
    """
    if cancelled.is_set() or Image is None:
        return None
    try:
        recent = session.recent_media_page(gallery_id)
        if isinstance(recent, LinkedMediaPage) and recent.photos:
            cached = session.cached_media_photo(gallery_id, recent.photos[0], cancelled=cancelled)
            if cached is not None and not cancelled.is_set():
                return _normalize_cover(cached[0])
    except (OSError, ValueError, RuntimeError, Image.DecompressionBombError):
        # The session refuses revoked or expired disk proof. This is permanent
        # support for both frontends and does not authorize unscoped bytes.
        pass
    return None


def read_folder_cover(
    session: LinkingSession, gallery_id: int, cancelled: Event,
) -> bytes | None:
    """Resolve a child gallery's first authorized photo off the GUI thread.

    Args:
        session (LinkingSession): Current media-readable Photos session.
        gallery_id (int): Exact child gallery for the thumbnail read.
        cancelled (Event): Current viewport generation's cancellation fence.
    Returns:
        bytes | None: Bounded PNG or no authorized preview. A transient failure
            may retain only a cache hit that the same session still verifies.
    """
    if cancelled.is_set() or Image is None:
        return None
    try:
        page = session.media_page(gallery_id, cancelled=cancelled, cover_only=True)
        if not page.photos or cancelled.is_set():
            return None
        photo = page.photos[0]
        try:
            cached = session.cached_media_photo(gallery_id, photo, cancelled=cancelled)
        except LinkingError:
            cached = None
        payload, _mime = cached if cached is not None else session.media_photo(
            gallery_id, photo.image_id, thumbnail=True, cancelled=cancelled,
            cache_eligible=photo.cache_eligible,
            thumbnail_etag=photo.thumbnail_etag,
            thumbnail_verified_at=photo.thumbnail_verified_at,
            preload_eligible=photo.preload_eligible,
            preload_thumbnail_etag=photo.preload_thumbnail_etag,
            preload_verified_at=photo.preload_verified_at,
        )
        if not cancelled.is_set():
            return _normalize_cover(payload)
    except (OSError, ValueError, RuntimeError, Image.DecompressionBombError):
        # The same session controls revocation and permanently owns the only
        # fallback allowed during transient media and filesystem failures.
        return cached_folder_cover(session, gallery_id, cancelled)
    return None
