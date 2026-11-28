# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/models.py
# Module Type: Windows Client Transport
# Purpose: Define linked gallery and media response structures.
# Responsibilities:
#   - Keep response mapping and immutable linked media values separate from transport.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Define linked gallery and media response structures."""

from typing import Callable
from ..models import JsonValue
from typing import Literal
from ..gallery_media_cache import PHOTO_CACHE_FRESH_SECONDS
from dataclasses import dataclass
from dataclasses import field
import http.client
import math
import re
from .core import (
    LINKING_GALLERY_ANCESTORS_MAX,
    LINKING_GALLERY_DEPTH_MAX,
    LINKING_GALLERY_ID_MAX,
    LINKING_GALLERY_PAGE_MAX,
    LINKING_GALLERY_REVISION_MAX,
    LINKING_GALLERY_TITLE_MAX_CHARS,
    LINKING_GALLERY_TREE_MAX_ROWS,
    LinkedMediaDetails,
    LinkingError,
    MEDIA_POLICY_FACT_MAX_AGE_SECONDS,
    MediaPolicyFacts,
    MediaReadTimings,
    NATIVE_MEDIA_DETAIL_TEXT_CHARS,
    _GALLERY_TREE_REVISION_PATTERN,
    _MediaPolicyMarkerState,
    _MediaPolicyState,
)



@dataclass(frozen=True)
class LinkedIdentity:
    """Minimal current Admin identity returned by a linked installation.

    Args:
        user_id (int): Positive identifier of the current Admin.
        username (str): Current Admin username returned by the server.
    """

    user_id: int
    username: str


@dataclass(frozen=True)
class TransientSessionSnapshot:
    """Carry an already-approved bearer to an OS vault without repr exposure.

    Attributes:
        issuer (str): Exact installation issuer and mount.
        scope (str): Exact browser-approved scope bundle.
        token (str): Original bearer, excluded from object representation.
        expires_at (float): Original grant deadline as Unix seconds.
    """

    issuer: str
    scope: str
    token: str = field(repr=False)
    expires_at: float


@dataclass(frozen=True)
class LinkedGallery:
    """One current, server-provided physical gallery without filesystem paths.

    Args:
        gallery_id (int): Stable positive gallery identifier.
        parent_id (int | None): Parent gallery ID, or None for a root.
        title (str): Server-provided title for display.
        depth (int): Picker depth from the authorized parent chain.
        ancestors (tuple[str, ...]): Nearest authorized parent titles, oldest first.
    """

    gallery_id: int
    parent_id: int | None
    title: str
    depth: int = 0
    ancestors: tuple[str, ...] = ()


@dataclass(frozen=True)
class LinkedGalleryDetail:
    """Hold one scoped gallery's explicit stored, non-secret properties.

    Args:
        gallery_id (int): Stable positive ID of the selected physical gallery.
        title (str): Current gallery display title.
        revision (str): Canonical positive decimal editor revision.
        visibility (str): Explicit stored visibility value.
        access_mode (str): Explicit stored access mode, not inherited access.
        access_listing (str): Explicit stored listing setting.
    """

    gallery_id: int
    title: str
    revision: str
    visibility: str
    access_mode: str
    access_listing: str


# Purpose: Preserve a complete TEXT description without unbounded allocation.
# Type: int. Units: UTF-8 bytes. Scope: one native gallery editor.
# Consumers: snapshot validation and save. Rationale: 65,535 is the server TEXT capacity.
GALLERY_EDIT_DESCRIPTION_MAX_BYTES = 65535

# Purpose: Finish one explicit edit without another user confirmation.
# Type: int. Units: conditional writes. Scope: one gallery edit intent.
# Consumers: LinkingSession.apply_gallery_edit. Rationale: 2 allows one fresh
# conflict retry or same-revision retry, never an unbounded mutation loop.
GALLERY_EDIT_WRITE_ATTEMPTS = 2

# Purpose: Reconcile a lost acknowledgement before presenting an inline failure.
# Type: int. Units: authorized reads. Scope: one failed gallery write.
# Consumers: LinkingSession.apply_gallery_edit. Rationale: 3 tolerates a brief
# read outage while preserving finite work and the transport's own deadlines.
GALLERY_EDIT_READ_ATTEMPTS = 3

# Purpose: Yield between failed reconciliation reads without blocking Tk.
# Type: float. Units: seconds. Scope: one gallery edit worker.
# Consumers: LinkingSession.apply_gallery_edit. Rationale: 0.25 avoids a tight
# retry loop and remains cancellable when the originating context disappears.
GALLERY_EDIT_READ_DELAY_SECONDS = 0.25


@dataclass(frozen=True)
class LinkedGalleryEditSnapshot:
    """Hold complete server metadata and the revision reviewed for one edit."""

    gallery_id: int
    title: str
    description: str
    revision: str
    visibility: str
    listed: bool
    nsfw: bool


class LinkingGalleryEditFailure(LinkingError):
    """Retain an observed edit baseline without exposing remote error text."""

    def __init__(
        self, snapshot: LinkedGalleryEditSnapshot | None, *, conflict: bool = False,
    ) -> None:
        """Keep the latest authorized observation for an editable retained draft.

        Args:
            snapshot (LinkedGalleryEditSnapshot | None): Fresh server observation,
                or None when no follow-up read could be authenticated.
            conflict (bool): Whether another revision prevented reconciliation.
        Returns:
            None: Stores only validated metadata and a fixed local message.
        """
        self.snapshot = snapshot
        self.conflict = conflict
        super().__init__('Gallery change could not be completed.')


def gallery_edit_text_valid(field: str, value: str) -> bool:
    """Validate complete editor text using the native server-column contract.

    Args:
        field (str): title or description text field selected for editing.
        value (str): Complete candidate text, including description line breaks.
    Returns:
        bool: True for encodable text within its field's persisted capacity.
    """
    try:
        encoded = value.encode('utf-8')
    except UnicodeError:
        return False
    if field == 'description':
        return len(encoded) <= GALLERY_EDIT_DESCRIPTION_MAX_BYTES
    return (field == 'title' and bool(value.strip())
            and len(value) <= LINKING_GALLERY_TITLE_MAX_CHARS
            and not any(ord(char) < 32 or ord(char) == 127 for char in value))


def _gallery_edit_snapshot(
    data: dict[str, JsonValue], gallery_id: int,
) -> LinkedGalleryEditSnapshot:
    """Validate a complete metadata snapshot without substituting cached values.

    Args:
        data (dict[str, JsonValue]): JSON envelope with exactly one gallery row.
        gallery_id (int): Exact physical gallery expected by the editor.
    Returns:
        LinkedGalleryEditSnapshot: Complete text, settings and canonical revision.
    Raises:
        LinkingError: Any identity, type, encoding, field or bound is invalid.
    """
    row = data.get('gallery')
    if (set(data) != {'gallery'} or not isinstance(row, dict)
            or set(row) != {'id', 'title', 'description', 'revision',
                            'visibility', 'listed', 'nsfw'}
            or type(row['id']) is not int or row['id'] != gallery_id
            or not isinstance(row['title'], str)
            or len(row['title']) > LINKING_GALLERY_TITLE_MAX_CHARS
            or not isinstance(row['description'], str)
            or not isinstance(row['revision'], str)
            or re.fullmatch(r'[1-9][0-9]{0,19}', row['revision']) is None
            or int(row['revision']) > LINKING_GALLERY_REVISION_MAX
            or row['visibility'] not in ('public', 'private', 'draft', 'unpublished')
            or type(row['listed']) is not bool or type(row['nsfw']) is not bool):
        raise LinkingError('Gallery edit snapshot was not accepted.')
    try:
        row['title'].encode('utf-8')
        if len(row['description'].encode('utf-8')) > GALLERY_EDIT_DESCRIPTION_MAX_BYTES:
            raise ValueError('Oversized gallery description.')
    except (UnicodeError, ValueError) as error:
        raise LinkingError('Gallery text was not accepted.') from error
    return LinkedGalleryEditSnapshot(
        gallery_id, row['title'], row['description'], row['revision'],
        row['visibility'], row['listed'], row['nsfw'],
    )


@dataclass(frozen=True)
class LinkedGalleryPage:
    """One bounded gallery page and its optional exclusive continuation.

    Args:
        galleries (tuple[LinkedGallery, ...]): Ordered server-authorized rows.
        next_after (int | None): Last ID for the next page, or None at the end.
        limited (bool): The server could not search its full catalog safely.
    """

    galleries: tuple[LinkedGallery, ...]
    next_after: int | None
    limited: bool = False


def _linked_gallery_page(
    data: dict[str, JsonValue], after_id: int | None, *, picker: bool = False,
) -> LinkedGalleryPage:
    """Validate an ordered listing or a ranked choice response without coercion.

    Args:
        data (dict[str, JsonValue]): JSON with galleries containing ID, parent
            ID and title, plus depth and parent titles for picker rows. The
            next_after value is the last returned ID or null. A limited picker
            response contains an empty list, null cursor and limited true.
        after_id (int | None): Previously validated exclusive continuation ID.
        picker (bool): Expect the enhanced ancestry and ranked ordering.

    Returns:
        LinkedGalleryPage: Bounded gallery identities and continuation.

    Raises:
        LinkingError: The response fields, rows or cursor are inconsistent.
    """
    if (picker and set(data) == {'galleries', 'next_after', 'limited'}
            and data['galleries'] == [] and data['next_after'] is None
            and data['limited'] is True):
        return LinkedGalleryPage((), None, limited=True)
    entries, next_after = data.get('galleries'), data.get('next_after')
    if (set(data) != {'galleries', 'next_after'} or not isinstance(entries, list)
            or len(entries) > LINKING_GALLERY_PAGE_MAX):
        raise LinkingError('Gallery response was not accepted.')
    galleries: list[LinkedGallery] = []
    previous = after_id or 0
    seen = {previous}
    fields = {'id', 'parent_id', 'title'}
    if picker:
        fields.update(('depth', 'ancestors'))
    for entry in entries:
        if not isinstance(entry, dict) or set(entry) != fields:
            raise LinkingError('Gallery response was not accepted.')
        gallery_id, parent_id, title = entry['id'], entry['parent_id'], entry['title']
        if (type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
                or gallery_id in seen or (not picker and gallery_id <= previous)
                or (parent_id is not None and (type(parent_id) is not int
                    or not 0 < parent_id <= LINKING_GALLERY_ID_MAX))
                or not isinstance(title, str)
                or len(title) > LINKING_GALLERY_TITLE_MAX_CHARS
                or any(0xd800 <= ord(char) <= 0xdfff for char in title)):
            raise LinkingError('Gallery response was not accepted.')
        depth, ancestors = entry.get('depth', 0), entry.get('ancestors', [])
        if (type(depth) is not int or not 0 <= depth <= LINKING_GALLERY_DEPTH_MAX
                or not isinstance(ancestors, list)
                or len(ancestors) != min(depth, LINKING_GALLERY_ANCESTORS_MAX)
                or any(not isinstance(parent, str)
                       or len(parent) > LINKING_GALLERY_TITLE_MAX_CHARS
                       or any(0xd800 <= ord(char) <= 0xdfff for char in parent)
                       for parent in ancestors)):
            raise LinkingError('Gallery response was not accepted.')
        galleries.append(LinkedGallery(gallery_id, parent_id, title, depth, tuple(ancestors)))
        seen.add(gallery_id)
        previous = gallery_id
    if (next_after is not None and (type(next_after) is not int
            or len(galleries) != LINKING_GALLERY_PAGE_MAX or next_after != previous)):
        raise LinkingError('Gallery response was not accepted.')
    return LinkedGalleryPage(tuple(galleries), next_after)


@dataclass(frozen=True)
class LinkedGalleryTree:
    """Hold one validated complete gallery tree and its server revision.

    Args:
        galleries (tuple[LinkedGallery, ...]): Complete ID-ordered rows, empty
            for an empty catalogue or an unchanged response backed by a matching
            local snapshot.
        revision (str): Lowercase SHA-256 revision from the server.
        unchanged (bool): Whether the server confirmed the requested revision.
    """

    galleries: tuple[LinkedGallery, ...]
    revision: str
    unchanged: bool = False


def _linked_gallery_tree(
    data: dict[str, JsonValue], requested_revision: str | None,
) -> LinkedGalleryTree:
    """Validate a complete tree-v1 envelope without coercing any member.

    Args:
        data (dict[str, JsonValue]): Strict JSON envelope from the optional route.
        requested_revision (str | None): Exact revision sent with the request.

    Returns:
        LinkedGalleryTree: Complete validated rows or matching unchanged proof.

    Raises:
        LinkingError: The envelope, revision, rows or ordering is invalid.
    """
    revision = data.get('revision')
    unchanged = data.get('unchanged')
    if (type(data.get('version')) is not int or data.get('version') != 1
            or not isinstance(revision, str)
            or _GALLERY_TREE_REVISION_PATTERN.fullmatch(revision) is None
            or type(unchanged) is not bool):
        raise LinkingError('Gallery tree response was not accepted.')
    if unchanged:
        if (set(data) != {'version', 'revision', 'unchanged'}
                or requested_revision is None
                or revision != requested_revision):
            raise LinkingError('Gallery tree response was not accepted.')
        return LinkedGalleryTree((), revision, True)
    rows = data.get('galleries')
    if (set(data) != {'version', 'revision', 'unchanged', 'galleries'}
            or not isinstance(rows, list)
            or len(rows) > LINKING_GALLERY_TREE_MAX_ROWS):
        raise LinkingError('Gallery tree response was not accepted.')
    galleries: list[LinkedGallery] = []
    previous = 0
    for row in rows:
        if not isinstance(row, dict) or set(row) != {'id', 'parent_id', 'title'}:
            raise LinkingError('Gallery tree response was not accepted.')
        gallery_id, parent_id, title = (
            row['id'], row['parent_id'], row['title'],
        )
        if (type(gallery_id) is not int
                or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
                or gallery_id <= previous
                or (parent_id is not None and (
                    type(parent_id) is not int
                    or not 0 < parent_id <= LINKING_GALLERY_ID_MAX
                ))
                or not isinstance(title, str)
                or len(title) > LINKING_GALLERY_TITLE_MAX_CHARS
                or any(0xd800 <= ord(char) <= 0xdfff for char in title)):
            raise LinkingError('Gallery tree response was not accepted.')
        galleries.append(LinkedGallery(gallery_id, parent_id, title))
        previous = gallery_id
    return LinkedGalleryTree(tuple(galleries), revision)


@dataclass(frozen=True)
class LinkedPhoto:
    """Represent one permitted direct photo without a filesystem path.

    Args:
        image_id (int): Stable positive server image identity.
        title (str): Bounded display name returned by the server.
        latitude (float | None): Authorized map latitude, or None if unavailable.
        longitude (float | None): Authorized map longitude, or None if unavailable.
        visibility (str | None): Stored Admin visibility, if explicitly authorized.
        nsfw (bool | None): Stored NSFW Guard state, if explicitly authorized.
        size (int | None): Indexed original file size in bytes, if known.
        cache_eligible (bool | None): Current independent public admission, if supplied.
        thumbnail_etag (str | None): Current validator for an existing public thumbnail.
        thumbnail_verified_at (float | None): Authorized public-page receipt time,
            including when the thumbnail has no strong metadata validator.
        preload_eligible (bool | None): Current non-NSFW Admin preload admission.
        preload_thumbnail_etag (str | None): Strong Admin-preload thumbnail proof.
        preload_verified_at (float | None): Monotonic time of Admin preload metadata.
    """

    image_id: int
    title: str
    latitude: float | None = None
    longitude: float | None = None
    visibility: str | None = None
    nsfw: bool | None = None
    size: int | None = None
    cache_eligible: bool | None = None
    thumbnail_etag: str | None = None
    thumbnail_verified_at: float | None = None
    preload_eligible: bool | None = None
    preload_thumbnail_etag: str | None = None
    preload_verified_at: float | None = None


@dataclass(frozen=True)
class ThumbnailBatchWitness:
    """Capture one current catalogue proof for a single batch candidate.

    Attributes:
        owner (LinkingSession): Exact session that captured this authority.
        issuer (str): Exact session issuer that registered the proof.
        gallery_id (int): Physical gallery owning the direct photo.
        photo (LinkedPhoto): Immutable authorized catalogue descriptor.
        kind (Literal['public', 'private']): Independent proof owner.
        public_row (tuple[str | None, float | None] | None): Exact public
            validator and catalogue receipt time, or None for private proof.
        private_identity (tuple[int, str, float, int] | None): Account,
            validator, row receipt time and row epoch, or None for public proof.
        generation (int): Captured private authorization generation.
    """

    owner: 'LinkingSession'
    issuer: str
    gallery_id: int
    photo: LinkedPhoto
    kind: Literal['public', 'private']
    public_row: tuple[str | None, float | None] | None
    private_identity: tuple[int, str, float, int] | None
    generation: int


@dataclass(frozen=True)
class ThumbnailPublication:
    """Carry one verified batch item and its independent reuse authority.

    Attributes:
        witness (ThumbnailBatchWitness): Captured authorization and identity.
        photo (LinkedPhoto): Exact descriptor corresponding to this result.
        status (Literal['ok', 'unavailable', 'oversize']): Validated wire outcome.
        body (bytes | None): Verified image bytes for ok, otherwise None.
        mime (str | None): Validated image MIME for ok, otherwise None.
        etag (str | None): Actual wire validator, never inferred from SHA-256.
        ram_eligible (bool): Independent proof permits decoded-image reuse.
    """

    witness: ThumbnailBatchWitness
    photo: LinkedPhoto
    status: Literal['ok', 'unavailable', 'oversize']
    body: bytes | None
    mime: str | None
    etag: str | None
    ram_eligible: bool


@dataclass(frozen=True)
class LegacyThumbnailPublication:
    """Carry a single authenticated GET result without granting RAM reuse.

    Attributes:
        owner (LinkingSession): Exact session that owns the authenticated GET.
        issuer (str): Exact issuer used by the session.
        gallery_id (int): Physical gallery owning the photo.
        photo (LinkedPhoto): Captured current viewer descriptor.
        status (Literal['ok', 'unavailable']): Result of the authenticated GET.
        body (bytes | None): Authorized body, or None for an unavailable item.
        mime (str | None): Accepted media type, or None for an unavailable item.
        etag (str | None): Actual GET validator, never inferred from a digest.
        witness (ThumbnailBatchWitness | None): Independent registered proof,
            or None for explicitly restricted transient-only descriptors.
        generation (int): Session authorization generation captured at dispatch.
        ram_eligible (Literal[False]): Always False; no cache reuse is granted.
    """

    owner: 'LinkingSession'
    issuer: str
    gallery_id: int
    photo: LinkedPhoto
    status: Literal['ok', 'unavailable']
    body: bytes | None
    mime: str | None
    etag: str | None
    witness: ThumbnailBatchWitness | None
    generation: int
    ram_eligible: Literal[False] = False


@dataclass
class ThumbnailBatchStats:
    """Accumulate bounded numeric observations for one caller-owned group.

    Attributes:
        requested (int): Requested unique photos.
        returned (int): Delivered results containing an image body.
        delivered (int): All completed result callbacks, including unavailable.
        failed (int): Requested images without a delivered body.
        operation_failures (int): Calls ending with a whole-operation failure.
        wire_items (int): Complete validated wire frames.
        wire_failures (int): Complete nonbinary wire frames.
        wire_unavailable (int): Complete unavailable wire frames.
        wire_oversize (int): Complete oversize wire frames.
        cache_hits (int): Authorized local cache results.
        fallback_unsupported (int): Singles after explicit capability refusal.
        fallback_capability_missing (int): Singles when discovery omitted support.
        fallback_wire_disabled (int): Singles after an earlier malformed response.
        fallback_restricted (int): Explicitly restricted single reads.
        fallback_exceptional (int): Unavailable or oversize single reads.
        batch_sizes (dict[int, int]): Physical batch counts keyed by group size.
        first_frame_ms (float | None): Delay to first completed wire item.
        publish_ms (float): Time spent in caller-owned result callbacks.
    """

    requested: int = 0
    returned: int = 0
    delivered: int = 0
    failed: int = 0
    operation_failures: int = 0
    wire_items: int = 0
    wire_failures: int = 0
    wire_unavailable: int = 0
    wire_oversize: int = 0
    cache_hits: int = 0
    fallback_unsupported: int = 0
    fallback_capability_missing: int = 0
    fallback_wire_disabled: int = 0
    fallback_restricted: int = 0
    fallback_exceptional: int = 0
    batch_sizes: dict[int, int] = field(default_factory=dict)
    first_frame_ms: float | None = None
    publish_ms: float = 0.0


def _observe_thumbnail_mime(timings: MediaReadTimings, mime: str | None) -> None:
    """Count one accepted per-item image type without counting HTTP again.

    Args:
        timings (MediaReadTimings): The owning item's independent metrics.
        mime (str | None): Accepted image media type, or None for unavailable.

    Returns:
        None: Increments only the fixed matching image counter.
    """
    if mime == 'image/jpeg':
        timings.jpeg_responses += 1
    elif mime == 'image/png':
        timings.png_responses += 1
    elif mime == 'image/gif':
        timings.gif_responses += 1
    elif mime == 'image/webp':
        timings.webp_responses += 1


def _observe_active_thumbnails(
    observer: Callable[[tuple[int, ...]], None] | None, identities: tuple[int, ...],
) -> None:
    """Publish a physical thumbnail read without granting control to the observer.

    Args:
        observer (Callable[[tuple[int, ...]], None] | None): Optional display receiver.
        identities (tuple[int, ...]): Exact IDs in this read, or empty when it ends.
    Returns:
        None: Observer errors leave transport and publication policy intact.
    """
    if observer is None:
        return
    try:
        observer(identities)
    except Exception:
        pass


def _linked_thumbnail_etag(value: JsonValue) -> str | None:
    """Validate the optional public thumbnail proof from a catalogue row.

    Args:
        value (JsonValue): Decoded optional validator from the server.

    Returns:
        str | None: Strong validator or None when no proof was supplied.

    Raises:
        LinkingError: A non-null proof was malformed.
    """
    if value is None:
        return None
    if not isinstance(value, str) or re.fullmatch(r'"[a-f0-9]{64}"', value) is None:
        raise LinkingError('Gallery thumbnail validator was not accepted.')
    return value


def _linked_admin_photo(row: dict[str, JsonValue]) -> LinkedPhoto:
    """Validate an Admin photo projection before exposing it to the viewer.

    Args:
        row (dict[str, JsonValue]): Strict decoded descriptor with stored settings.

    Returns:
        LinkedPhoto: Bounded metadata and explicit public cache admission.

    Raises:
        LinkingError: The photo identity or stored settings were malformed.
    """
    image_id, title = row.get('id'), row.get('title')
    visibility, nsfw, size = row.get('visibility'), row.get('nsfw'), row.get('size')
    cache_eligible = row.get('cache_eligible')
    thumbnail_etag = _linked_thumbnail_etag(row.get('thumbnail_etag'))
    preload_eligible = row.get('preload_eligible')
    preload_thumbnail_etag = _linked_thumbnail_etag(
        row.get('preload_thumbnail_etag'),
    )
    if (type(image_id) is not int or not _native_media_id(image_id)
            or not isinstance(title, str) or not _native_media_title(title)
            or not isinstance(visibility, str) or visibility not in ('draft', 'private', 'public')
            or type(nsfw) is not bool or type(cache_eligible) is not bool
            or ('preload_eligible' in row
                and type(preload_eligible) is not bool)
            or (size is not None and (type(size) is not int or size < 0))
            or (cache_eligible and (nsfw or visibility != 'public'))
            or (thumbnail_etag is not None and not cache_eligible)
            or (preload_eligible is True and nsfw)
            or (preload_thumbnail_etag is not None
                and preload_eligible is not True)):
        raise LinkingError('Gallery photo settings were not accepted.')
    return LinkedPhoto(
        image_id, title, visibility=visibility, nsfw=nsfw,
        size=size, cache_eligible=cache_eligible, thumbnail_etag=thumbnail_etag,
        preload_eligible=preload_eligible,
        preload_thumbnail_etag=preload_thumbnail_etag,
    )


@dataclass(frozen=True)
class LinkedMediaGalleryInfo:
    """Represent the current direct original inventory of a physical gallery.

    Args:
        gallery_id (int): Exact gallery ID from the request.
        photo_count (int): Number of direct indexed photos.
        file_count (int): Number of direct indexed original photo files.
        size (int): Total indexed original bytes.
    """

    gallery_id: int
    photo_count: int
    file_count: int
    size: int


def _linked_media_gallery_inventory(
    data: dict[str, JsonValue], gallery_id: int,
) -> LinkedMediaGalleryInfo:
    """Validate the shared inventory fields in an Admin gallery response.

    Args:
        data (dict[str, JsonValue]): Gallery ID, direct photo/file counts and
            original byte size, with optional detailed fields.
        gallery_id (int): Exact requested physical gallery ID.

    Returns:
        LinkedMediaGalleryInfo: Bound direct inventory counts and size.

    Raises:
        LinkingError: The response names a different gallery or invalid counts.
    """
    photos, files, size = (data.get(key) for key in ('photo_count', 'file_count', 'size'))
    if (type(data.get('gallery_id')) is not int or data['gallery_id'] != gallery_id
            or type(photos) is not int or photos < 0
            or type(files) is not int or files < 0
            or type(size) is not int or size < 0):
        raise LinkingError('Gallery inventory was not accepted.')
    return LinkedMediaGalleryInfo(gallery_id, photos, files, size)


def _linked_media_details(
    data: dict[str, JsonValue], fields: tuple[str, ...],
) -> LinkedMediaDetails:
    """Project bounded scalar information from an exact Admin response.

    Args:
        data (dict[str, JsonValue]): Parsed metadata object with allowed text,
            image geometry, EXIF, location or gallery attributes.
        fields (tuple[str, ...]): Explicit optional fields for this entity type.

    Returns:
        LinkedMediaDetails: Present bounded scalars keyed by their wire names.
    """
    details: LinkedMediaDetails = {}
    for key in fields:
        value = data.get(key)
        if isinstance(value, str) and len(value) <= NATIVE_MEDIA_DETAIL_TEXT_CHARS:
            details[key] = ''.join(char if char.isprintable() else ' ' for char in value)
        elif type(value) in (int, bool) or (type(value) is float and math.isfinite(value)):
            details[key] = value
    return details


@dataclass(frozen=True)
class LinkedMediaPage:
    """Hold one page of current authorized photo descriptors.

    Args:
        gallery_id (int): Requested gallery identity.
        parent_id (int | None): Public hierarchy parent, or None at root.
        photos (tuple[LinkedPhoto, ...]): Ordered positive photo IDs; transport
            pages ascend and WinApp-only reverse snapshots descend.
        next_after (int | None): Exclusive continuation or None at the end.
        cover_only (bool): True for a single cover descriptor, which cannot
            establish the size or completeness of the gallery catalogue.
    """

    gallery_id: int
    parent_id: int | None
    photos: tuple[LinkedPhoto, ...]
    next_after: int | None
    cover_only: bool = False


@dataclass(frozen=True)
class LinkedTrack:
    """Represent one permitted track without its storage path.

    Args:
        track_id (str): Opaque gallery-specific identifier.
        title (str): Bounded display name.
        mime (str): Supported native audio media type.
        size (int): Expected bytes in the complete track.
        sha256 (str): Expected lowercase digest of those bytes.
    """

    track_id: str
    title: str
    mime: str
    size: int
    sha256: str


@dataclass(frozen=True)
class LinkedPresentation:
    """Hold server-selected playback preferences and current visible music.

    Args:
        enabled (bool): Whether the server permits presentation in this gallery.
        autoplay (bool): Whether a user-started presentation advances itself.
        interval_seconds (int): Server preset duration per photo.
        transition (str): Server's slow, medium or fast transition preset.
        music (bool): Whether the presentation permits gallery music.
        tracks (tuple[LinkedTrack, ...]): Ordered bounded playable tracks.
        track_cache (bool): Whether the current server permits opted-in track reuse.
    """

    enabled: bool
    autoplay: bool
    interval_seconds: int
    transition: str
    music: bool
    tracks: tuple[LinkedTrack, ...]
    track_cache: bool = False


def _native_media_id(value: JsonValue) -> bool:
    """Validate one positive, non-boolean server media or gallery ID.

    Args:
        value (JsonValue): Candidate identifier from a local or wire selector.

    Returns:
        bool: True for an integer in the supported server ID range.
    """
    return type(value) is int and 0 < value <= LINKING_GALLERY_ID_MAX


def _native_media_title(value: JsonValue) -> bool:
    """Accept bounded text that can be rendered without control characters.

    Args:
        value (JsonValue): Candidate photo or track name from the server.

    Returns:
        bool: True for valid bounded single-line display text.
    """
    return (isinstance(value, str) and len(value) <= LINKING_GALLERY_TITLE_MAX_CHARS
            and not any(ord(c) < 32 or ord(c) == 127 or 0xd800 <= ord(c) <= 0xdfff
                        for c in value))


def _native_media_policy_facts(
    headers: http.client.HTTPMessage, cache_header: str | None,
    cache_policy: str, admin_preload: bool, *,
    binary_check: Literal[
        'none', 'length', 'validator', 'framing', 'body_length', 'digest',
    ] = 'none',
) -> MediaPolicyFacts:
    """Reduce media headers and refusal stages to finite policy observations.

    Args:
        headers (http.client.HTTPMessage): Parsed response header collection.
        cache_header (str | None): Requested cache marker name, if any.
        cache_policy (str): Exact cache directive required by the request.
        admin_preload (bool): Whether the explicit preload marker was requested.
        binary_check (Literal['none', 'length', 'validator', 'framing', 'body_length', 'digest']):
            Fixed binary refusal stage, or none for cache-header observations.

    Returns:
        MediaPolicyFacts: Validated allowlisted facts with no retained header
            strings, values, URLs or response content.
    """
    cache_controls = headers.get_all('Cache-Control', [])
    if not cache_controls:
        cache_control: _MediaPolicyState = 'missing'
    elif len(cache_controls) > 1:
        cache_control = 'multiple'
    elif cache_policy and cache_controls[0].strip().lower() == cache_policy:
        cache_control = 'expected'
    else:
        cache_control = 'different'

    cache_markers = (
        [] if cache_header is None
        else headers.get_all(cache_header, [])
    )
    if cache_header is None:
        cache_marker: _MediaPolicyMarkerState = 'not_requested'
    elif not cache_markers:
        cache_marker = 'missing'
    elif len(cache_markers) > 1:
        cache_marker = 'multiple'
    elif cache_markers[0] == '1':
        cache_marker = 'expected'
    else:
        cache_marker = 'different'

    directives = [
        part.strip().lower()
        for value in cache_controls
        for part in value.split(',')
    ]
    directive_names = [
        directive.partition('=')[0].strip()
        for directive in directives
    ]
    max_age_values: list[int] = []
    max_age_directives = [
        directive.partition('=')[2].strip()
        for directive, name in zip(directives, directive_names)
        if name == 'max-age'
    ]
    if (len(max_age_directives) == 1
            and re.fullmatch(r'[0-9]{1,10}', max_age_directives[0]) is not None):
        parsed_max_age = int(max_age_directives[0])
        if parsed_max_age <= MEDIA_POLICY_FACT_MAX_AGE_SECONDS:
            max_age_values.append(parsed_max_age)

    vary_tokens = {
        part.strip().lower()
        for value in headers.get_all('Vary', [])
        for part in value.split(',')
    }
    preload_markers = (
        headers.get_all('X-PHP-Gallery-Preload', [])
        if admin_preload else []
    )
    if not admin_preload:
        preload_marker: _MediaPolicyMarkerState = 'not_requested'
    elif not preload_markers:
        preload_marker = 'missing'
    elif len(preload_markers) > 1:
        preload_marker = 'multiple'
    elif preload_markers[0] == 'admin-v1':
        preload_marker = 'expected'
    else:
        preload_marker = 'different'

    return MediaPolicyFacts(
        cache_control=cache_control,
        cache_marker=cache_marker,
        private='private' in directive_names,
        must_revalidate='must-revalidate' in directive_names,
        max_age_seconds=max_age_values[0] if max_age_values else None,
        vary_auth='authorization' in vary_tokens,
        vary_marker=(
            cache_header is not None and cache_header.lower() in vary_tokens
        ),
        vary_wildcard='*' in vary_tokens,
        preload_marker=preload_marker,
        vary_preload='x-php-gallery-preload' in vary_tokens,
        encoding_present=bool(headers.get_all('Content-Encoding', [])),
        binary_check=binary_check,
    )


def _private_media_cache_control_matches(
    values: list[str], *, photo_request: bool, track_request: bool,
) -> bool:
    """Match equivalent forms of the negotiated private cache policy.

    Args:
        values (list[str]): All Cache-Control field values from one response.
        photo_request (bool): Whether the request is for photo bytes.
        track_request (bool): Whether the request is for audio bytes.

    Returns:
        bool: True only for the complete private policy with allowed repeats.

    Photo directives may be split, reordered or repeated equivalently. For
    repeated max-age fields RFC 9111 permits use of the first origin value.
    Admission still requires an explicit 60-second value and every value to
    meet the existing 60-second local freshness cap. Audio accepts only equivalent
    `private, no-cache` directives.
    """
    flags: set[str] = set()
    ages: list[str] = []
    for header in values:
        if not isinstance(header, str) or not header:
            return False
        for field in header.split(','):
            directive = field.strip()
            if not directive:
                return False
            name, separator, parameter = directive.partition('=')
            name = name.strip().lower()
            parameter = parameter.strip()
            if name in ('private', 'must-revalidate', 'no-cache'):
                if separator:
                    return False
                flags.add(name)
                continue
            if name != 'max-age' or not separator or not photo_request:
                return False
            if re.fullmatch(r'[0-9]+', parameter) is None:
                return False
            ages.append(parameter.lstrip('0') or '0')

    if photo_request and not track_request:
        required = {'private', 'must-revalidate'}
        minimum_age = str(PHOTO_CACHE_FRESH_SECONDS)
        return (
            flags == required
            and bool(ages)
            and minimum_age in ages
            and all(
                len(age) > len(minimum_age)
                or (len(age) == len(minimum_age) and age >= minimum_age)
                for age in ages
            )
        )
    if track_request and not photo_request:
        return flags == {'private', 'no-cache'} and not ages
    return False
