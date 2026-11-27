# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_photo_source.py
# Module Type: Qt Photos worker boundary
# Purpose: Transfer authorized, bounded photo data to the retained Qt scene.
# Responsibilities: Reuse linking transport, catalogue validation, cache proof
#   and media budgets while decoding off the GUI thread.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Read catalogues and decode bounded Qt images without touching widgets."""

from dataclasses import dataclass, replace
from pathlib import Path
from threading import Event
import time
from typing import Callable

from PySide6.QtCore import QBuffer, QByteArray, QIODevice, QSize, Qt
from PySide6.QtGui import QImage, QImageReader

try:
    from PIL import Image as PillowImage
except ImportError:
    PillowImage = None

from .app_linking import (
    LINKING_GALLERY_TREE_MAX_ROWS,
    MEDIA_RECENT_ORDER_PHOTO_LIMIT,
    NATIVE_MEDIA_MAX_BYTES,
    LegacyThumbnailPublication,
    LinkedGallery,
    LinkedMediaPage,
    LinkedPhoto,
    LinkingError,
    LinkingSession,
    LinkingThumbnailProofChanged,
    MediaReadTimings,
    ThumbnailPublication,
)
from .gallery_image_policy import (
    MEDIA_HTTP_BATCH_LARGE_BYTES,
    MEDIA_HTTP_BATCH_SLOW_SECONDS,
    MEDIA_IMAGE_MAX_PIXELS,
    MEDIA_IMAGE_MAX_SIZE,
    MEDIA_THUMBNAIL_SIZE,
)
from .gallery_black_matte import (
    LIGHTBOX_BLACK_TRIM_SAMPLE_EDGE, detect_black_matte_crop,
)
from .qt_read_lane import ReadCancelled

Publication = ThumbnailPublication | LegacyThumbnailPublication


class PhotoPixelLimit(ValueError):
    """Identify an oversized raster eligible for a separately authorized preview."""


@dataclass(frozen=True)
class PhotoRow:
    """Identify one local preview item or an exact authorized remote descriptor."""

    identity: int
    title: str
    source: Path | LinkedPhoto


@dataclass(frozen=True)
class PhotoFrame:
    """Transfer decoded pixels, source geometry and optional bounded metadata."""

    row: PhotoRow
    image: QImage
    source_size: QSize
    publication: Publication | None = None
    hdr_source: bytes | None = None
    content_crop: tuple[float, float, float, float] | None = None


@dataclass(frozen=True)
class ThumbnailGroup:
    """Report whether the completed stream can advance to its steady limit."""

    healthy: bool


@dataclass(frozen=True)
class ThumbnailActivity:
    """Identify only the photos inside a current read or decode."""

    identities: tuple[int, ...]


@dataclass(frozen=True)
class ThumbnailProofPage:
    """Bind refreshed authorized rows to the exclusive cursor they covered."""

    after_id: int | None
    page: LinkedMediaPage


ThumbnailEvent = PhotoFrame | ThumbnailActivity | ThumbnailGroup | ThumbnailProofPage


def read_gallery_tree(session: LinkingSession, cancelled: Event) -> tuple[LinkedGallery, ...]:
    """Read the current tree with the existing legacy paginated fallback.

    Args:
        session (LinkingSession): Explicitly approved non-writing Photos grant.
        cancelled (Event): Owning gallery selector's cancellation fence.
    Returns:
        tuple[LinkedGallery, ...]: Validated ordered gallery and parent IDs.
    Raises:
        LinkingError: Access, bounds or catalogue continuity could not be proved.
    """
    tree = session.gallery_tree(cancelled=cancelled)
    if tree is not None:
        return tree.galleries
    rows: list[LinkedGallery] = []
    cursor = None
    while not cancelled.is_set():
        page = session.gallery_page(cursor, cancelled)
        rows.extend(page.galleries)
        if len(rows) > LINKING_GALLERY_TREE_MAX_ROWS:
            raise LinkingError("Gallery catalogue exceeds the supported bound.")
        if page.next_after is None:
            return tuple(rows)
        cursor = page.next_after
    raise ReadCancelled


def read_gallery_photos(
    session: LinkingSession, gallery_id: int, cancelled: Event,
) -> tuple[PhotoRow, ...]:
    """Read all permitted direct photo metadata before ordering by newest ID.

    Args:
        session (LinkingSession): Current authorized Photos session.
        gallery_id (int): Physical gallery selected by the operator.
        cancelled (Event): Reject metadata after replacement or close.
    Returns:
        tuple[PhotoRow, ...]: Newest-first immutable photo identities.
    Raises:
        LinkingError: Permission, bounds or catalogue continuity are unavailable.
    """
    photos: list[LinkedPhoto] = []
    cursor = None
    while not cancelled.is_set():
        page = session.media_page(gallery_id, cursor, cancelled)
        photos.extend(page.photos)
        if len(photos) > MEDIA_RECENT_ORDER_PHOTO_LIMIT:
            raise LinkingError("Photo catalogue exceeds the supported bound.")
        if page.next_after is None:
            return tuple(PhotoRow(photo.image_id, photo.title, photo) for photo in reversed(photos))
        cursor = page.next_after
    raise ReadCancelled


def decode_photo(
    body: bytes, *, thumbnail: bool,
    thumbnail_size: tuple[int, int] = MEDIA_THUMBNAIL_SIZE,
) -> tuple[QImage, QSize]:
    """Decode a bounded encoded image using the visible Qt platform's codecs.

    Args:
        body (bytes): Complete local preview or authorized gallery image.
        thumbnail (bool): Apply a bounded thumbnail size instead of the
            detail limit.
        thumbnail_size (tuple[int, int]): Decoded maximum width and height in
            pixels for the trusted view, defaulting to the shared grid thumbnail.
    Returns:
        tuple[QImage, QSize]: Owned rendered pixels and original encoded geometry.
    Raises:
        ValueError: Image is corrupt, unsupported or beyond the shared limits.
    """
    if len(body) > NATIVE_MEDIA_MAX_BYTES:
        raise ValueError("Image exceeds the encoded size bound.")
    device = QBuffer()
    device.setData(QByteArray(body))
    device.open(QIODevice.OpenModeFlag.ReadOnly)
    reader = QImageReader(device)
    reader.setAutoTransform(True)
    size = reader.size()
    if not size.isValid() or size.isEmpty():
        raise ValueError("Image dimensions could not be determined.")
    width, height = thumbnail_size if thumbnail else MEDIA_IMAGE_MAX_SIZE
    if size.width() * size.height() > MEDIA_IMAGE_MAX_PIXELS:
        if thumbnail or bytes(reader.format()).lower() not in (b"jpeg", b"jpg"):
            raise PhotoPixelLimit("Image exceeds the decoded size bound.")
    bound = QSize(width, height)
    if size.width() > width or size.height() > height:
        reader.setScaledSize(size.scaled(bound, Qt.AspectRatioMode.KeepAspectRatio))
    image = reader.read()
    if image.isNull():
        raise ValueError("Image decoding failed.")
    if image.width() > width or image.height() > height:
        image = image.scaled(
            bound, Qt.AspectRatioMode.KeepAspectRatio, Qt.TransformationMode.SmoothTransformation,
        )
    return image, size


def _content_crop_bounds(
    image: QImage, cancelled: Event,
) -> tuple[float, float, float, float] | None:
    """Detect safe matte bounds on a bounded SDR probe off the GUI lane.

    Args:
        image (QImage): Current bounded detail raster after orientation.
        cancelled (Event): Current detail owner cancellation fence.
    Returns:
        tuple[float, float, float, float] | None: Normalized source bounds,
            absent when Pillow is unavailable or no opposing matte is proven.
    Raises:
        ReadCancelled: The detail owner ended during probe preparation.
    """
    if PillowImage is None or cancelled.is_set():
        if cancelled.is_set():
            raise ReadCancelled
        return None
    scale = min(
        1.0,
        LIGHTBOX_BLACK_TRIM_SAMPLE_EDGE / max(image.width(), image.height()),
    )
    probe_size = QSize(
        max(1, round(image.width() * scale)),
        max(1, round(image.height() * scale)),
    )
    probe = image.scaled(
        probe_size, Qt.AspectRatioMode.IgnoreAspectRatio,
        Qt.TransformationMode.FastTransformation,
    ).convertToFormat(QImage.Format.Format_RGB888)
    if cancelled.is_set():
        raise ReadCancelled
    stride = probe.bytesPerLine()
    data = probe.constBits().tobytes()
    required = stride * probe.height()
    if len(data) < required:
        return None
    with PillowImage.frombytes(
        "RGB", (probe.width(), probe.height()), data[:required],
        "raw", "RGB", stride, 1,
    ) as sampled:
        left, top, right, bottom = detect_black_matte_crop(sampled)
    if cancelled.is_set():
        raise ReadCancelled
    if (left, top, right, bottom) == (
        0, 0, probe.width(), probe.height(),
    ):
        return None
    return (
        left / probe.width(), top / probe.height(),
        right / probe.width(), bottom / probe.height(),
    )


def read_photo(
    row: PhotoRow, session: LinkingSession | None, gallery_id: int | None,
    cancelled: Event, *, thumbnail: bool,
    thumbnail_size: tuple[int, int] = MEDIA_THUMBNAIL_SIZE,
) -> PhotoFrame:
    """Read a local or explicitly authorized remote rendition.

    Args:
        row (PhotoRow): Exact current local filename or remote identity and proof.
        session (LinkingSession | None): Current gallery session for remote rows.
        gallery_id (int | None): Physical gallery that owns a remote photo.
        cancelled (Event): Existing view-owned cancellation, including read I/O.
        thumbnail (bool): True for a thumbnail and False for the selected detail.
        thumbnail_size (tuple[int, int]): Decoded maximum width and height in
            pixels for the trusted view. The encoded rendition is unchanged.
    Returns:
        PhotoFrame: Bounded, independent raster and source geometry.
    Raises:
        ReadCancelled: The view lost ownership before decode.
        LinkingError: The remote row is unavailable or no longer authorized.
        OSError: The selected local source cannot be read.
        ValueError: Decoding failed or exceeded the media bounds.
    """
    if cancelled.is_set():
        raise ReadCancelled
    source = row.source
    if isinstance(source, Path):
        with source.open("rb") as image_file:
            body = image_file.read(NATIVE_MEDIA_MAX_BYTES + 1)
        if cancelled.is_set():
            raise ReadCancelled
        image, size = decode_photo(
            body, thumbnail=thumbnail, thumbnail_size=thumbnail_size,
        )
        hdr_source = _bounded_hdr_source(body, size, thumbnail)
        content_crop = None if thumbnail else _content_crop_bounds(image, cancelled)
        return PhotoFrame(
            row, image, size, hdr_source=hdr_source, content_crop=content_crop,
        )
    if session is None or gallery_id is None:
        raise LinkingError("Photos session is unavailable.")
    body = _linked_photo_body(session, gallery_id, source, cancelled, thumbnail=thumbnail)
    if cancelled.is_set():
        raise ReadCancelled
    try:
        image, size = decode_photo(
            body, thumbnail=thumbnail, thumbnail_size=thumbnail_size,
        )
    except PhotoPixelLimit:
        if thumbnail or cancelled.is_set():
            raise
        preview = _linked_photo_body(session, gallery_id, source, cancelled, thumbnail=True)
        if cancelled.is_set():
            raise ReadCancelled
        image, size = decode_photo(preview, thumbnail=False)
        body = None
    hdr_source = _bounded_hdr_source(body, size, thumbnail)
    content_crop = None if thumbnail else _content_crop_bounds(image, cancelled)
    return PhotoFrame(
        row, image, size, hdr_source=hdr_source, content_crop=content_crop,
    )


def _bounded_hdr_source(body: bytes | None, size: QSize, thumbnail: bool) -> bytes | None:
    """Retain only a detail rendition that fits the shared source bounds.

    Args:
        body (bytes | None): Encoded bytes, absent for thumbnail preview fallback.
        size (QSize): Original encoded image dimensions.
        thumbnail (bool): Whether this frame belongs to a disposable tile.
    Returns:
        bytes | None: Exact encoded detail bytes, or None for SDR-only frames.
    """
    if body is None or thumbnail or not body or len(body) > NATIVE_MEDIA_MAX_BYTES:
        return None
    if size.width() * size.height() > MEDIA_IMAGE_MAX_PIXELS:
        return None
    return body


def _linked_photo_body(
    session: LinkingSession, gallery_id: int, source: LinkedPhoto,
    cancelled: Event, *, thumbnail: bool,
) -> bytes:
    """Read one authorized rendition and enforce shared retained cache proof.

    Args:
        session (LinkingSession): Current live media-readable grant.
        gallery_id (int): Direct owner of the selected photo.
        source (LinkedPhoto): Validated descriptor and cache proofs.
        cancelled (Event): Current render request's lifetime.
        thumbnail (bool): Read the thumbnail rather than the selected detail.
    Returns:
        bytes: Bound rendition bytes admitted by the existing session.
    """
    body, _mime = session.media_photo(
        gallery_id, source.image_id, thumbnail, cancelled,
        cache_eligible=source.cache_eligible,
        thumbnail_etag=source.thumbnail_etag,
        thumbnail_verified_at=source.thumbnail_verified_at,
        preload_eligible=source.preload_eligible,
        preload_thumbnail_etag=source.preload_thumbnail_etag,
        preload_verified_at=source.preload_verified_at,
        use_preloaded_snapshot=not thumbnail,
    )
    return body


def stream_thumbnails(
    rows: tuple[PhotoRow, ...], session: LinkingSession | None, gallery_id: int | None,
    cancelled: Event, publish: Callable[[PhotoFrame | ThumbnailActivity | ThumbnailGroup], None],
) -> None:
    """Publish current thumbnail results before a complete group finishes.

    Args:
        rows (tuple[PhotoRow, ...]): One viewport-owned batch in visible priority.
        session (LinkingSession | None): Authoritative remote reader, if needed.
        gallery_id (int | None): Physical gallery binding for remote rows.
        cancelled (Event): Active request and viewport cancellation.
        publish (Callable[[PhotoFrame | ThumbnailActivity | ThumbnailGroup], None]):
            Bounded receiver for frames, active reads and final group health.
    Returns:
        None: Publishes available photos and the batch health signal.
    """
    started = time.monotonic()
    encoded_bytes = 0
    active: tuple[int, ...] = ()

    def show_activity(identities: tuple[int, ...]) -> None:
        """Publish an actual read transition through the same bounded lane.

        Args:
            identities (tuple[int, ...]): Current active photo IDs, or empty.
        Returns:
            None: Suppresses unchanged or cancelled activity notifications.
        """
        nonlocal active
        if not cancelled.is_set() and active != identities:
            active = identities
            publish(ThumbnailActivity(identities))

    if session is None or gallery_id is None:
        for row in rows:
            show_activity((row.identity,))
            try:
                publish(read_photo(row, None, None, cancelled, thumbnail=True))
            except (OSError, ValueError):
                show_activity(())
            else:
                active = ()  # The emitted frame ends this one local read.
    else:
        descriptors = tuple(row.source for row in rows if isinstance(row.source, LinkedPhoto))
        by_id = {row.identity: row for row in rows}
        expected = len(descriptors)
        delivered = 0

        def accept(
            publication: Publication, _timings: MediaReadTimings,
            check_active: Callable[[], None],
        ) -> None:
            """Decode and transfer one complete item behind its current proof.

            Args:
                publication (Publication): Authorized batch or legacy item result.
                _timings (MediaReadTimings): Shared transport timing observations.
                check_active (Callable[[], None]): Existing session and deadline fence.
            Returns:
                None: Publishes one available image without retaining its encoded body.
            """
            nonlocal active, encoded_bytes, delivered
            identity = publication.photo.image_id
            decoded = False
            try:
                if publication.body is None:
                    return
                if identity not in active:
                    # A legacy GET has already ended when this decode begins.
                    show_activity((*active, identity))
                encoded_bytes += len(publication.body)
                try:
                    image, size = decode_photo(publication.body, thumbnail=True)
                except ValueError:
                    return
                check_active()
                if not session.thumbnail_publication_current(publication, cancelled):
                    raise LinkingThumbnailProofChanged('Gallery thumbnail proof is no longer current.')
                publish(PhotoFrame(by_id[identity], image, size, replace(publication, body=None)))
                decoded = True
                delivered += 1
            finally:
                remaining = tuple(current for current in active if current != identity)
                if decoded:
                    # The frame retires the animation in the GUI's current generation.
                    active = remaining
                else:
                    show_activity(remaining)

        session.media_thumbnails(gallery_id, descriptors, accept, cancelled, active_photos=show_activity)
        if delivered != expected:
            publish(ThumbnailGroup(False))
            return
    publish(ThumbnailGroup(
        time.monotonic() - started <= MEDIA_HTTP_BATCH_SLOW_SECONDS
        and encoded_bytes < MEDIA_HTTP_BATCH_LARGE_BYTES,
    ))


def stream_revalidated_thumbnails(
    rows: tuple[PhotoRow, ...], session: LinkingSession, gallery_id: int,
    cancelled: Event, publish: Callable[[ThumbnailEvent], None],
) -> None:
    """Renew only pages covering a bounded visible group before retrying its pixels.

    Args:
        rows (tuple[PhotoRow, ...]): Current visible retry group at the initial batch limit.
        session (LinkingSession): Original, still-approved gallery reader.
        gallery_id (int): Physical gallery owning the requested photos.
        cancelled (Event): Current group cancellation fence.
        publish (Callable[[ThumbnailEvent], None]): Ordered page, activity and raster output.
    Returns:
        None: Delivers refreshed proofs and reads only still-listed candidates.
    Raises:
        ReadCancelled: The viewport cancelled this renewal.
        LinkingError: The renewed catalogue or thumbnail read was refused.
    """
    refreshed: dict[int, PhotoRow] = {}
    covered: int | None = 0
    for identity in sorted(row.identity for row in rows):
        if covered is None or identity <= covered:
            continue
        if cancelled.is_set():
            raise ReadCancelled
        # The existing cursor is exclusive. Start just before the missing ID.
        after_id = identity - 1 or None
        page = session.media_page(gallery_id, after_id, cancelled)
        if cancelled.is_set():
            raise ReadCancelled
        publish(ThumbnailProofPage(after_id, page))
        refreshed.update(
            (photo.image_id, PhotoRow(photo.image_id, photo.title, photo))
            for photo in page.photos
        )
        covered = page.next_after
    current = tuple(refreshed[row.identity] for row in rows if row.identity in refreshed)
    if current:
        stream_thumbnails(current, session, gallery_id, cancelled, publish)
