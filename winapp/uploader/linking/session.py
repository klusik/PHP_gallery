# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/session.py
# Module Type: Windows Client Session
# Purpose: Compose the explicit in-memory linking session behavior.
# Responsibilities:
#   - Bind lifecycle, gallery, media and upload behavior to one session owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Public scoped linking session composed from cohesive behavior owners."""

from typing import Callable
from ..linking_performance import LinkingRoutePerformanceWindow
from collections import OrderedDict
from ..passive_server_timing import ServerTimingWindow
import math
import threading
import time
from .core import (
    IDENTITY_SCOPE,
    LinkingError,
    ROLLING_SCOPE_SUFFIX,
    TOKEN_PREFIX,
    _FairRequestGate,
    _ThumbnailCooldownSource,
    _canonical_256bit,
    _issuer_valid,
    scope_base,
)
from .models import (
    LinkedGalleryDetail,
    LinkedGalleryEditSnapshot,
    LinkedMediaPage,
    LinkedPhoto,
)
from .session_gallery import (
    LinkingSessionGalleryMixin,
)
from .session_lifecycle import (
    LinkingSessionLifecycleMixin,
)
from .session_media_access import (
    LinkingSessionMediaAccessMixin,
)
from .session_media_pipeline import (
    LinkingSessionMediaPipelineMixin,
)
from .transport import (
    _MediaConnectionPool,
)



class LinkingSession(
    LinkingSessionLifecycleMixin,
    LinkingSessionMediaAccessMixin,
    LinkingSessionMediaPipelineMixin,
    LinkingSessionGalleryMixin,
):
    """Own an explicitly scoped, in-memory gallery link session."""

    def __init__(
        self, issuer: str, token: str, expires_at: float,
        scope: str = IDENTITY_SCOPE, *, admin_preload_supported: bool = False,
        gallery_tree_supported: bool = False,
        thumbnail_batch_supported: bool = False,
        sliding_access_supported: bool = False,
    ) -> None:
        """Create a transient session from a validated token response.

        Args:
            issuer (str): Previously trusted exact installation issuer and mount.
            token (str): Canonical scoped bearer; never log or persist it.
            expires_at (float): Conservative monotonic local expiry deadline.
            scope (str): Exact approved transient or offline scope bundle.
            admin_preload_supported (bool): Whether discovery advertised the
                explicit versioned private media protocol.
            gallery_tree_supported (bool): Whether discovery advertised the
                optional complete catalogue tree protocol.
            thumbnail_batch_supported (bool): Whether discovery advertised the
                optional framed thumbnail batch protocol.
            sliding_access_supported (bool): Whether discovery promises the
                day-v1 rolling access deadline after real authenticated traffic.

        Returns:
            None: Owns one token solely for the life of this object.

        Raises:
            LinkingError: Issuer, bearer or expiry is invalid.
        """
        if (not _issuer_valid(issuer, allow_local_http=True)
                or not token.startswith(TOKEN_PREFIX)
                or not _canonical_256bit(token[len(TOKEN_PREFIX):])
                or scope_base(scope) is None
                or type(admin_preload_supported) is not bool
                or type(gallery_tree_supported) is not bool
                or type(sliding_access_supported) is not bool
                or type(thumbnail_batch_supported) is not bool
                or not math.isfinite(expires_at)
                or expires_at <= time.monotonic()):
            raise LinkingError('Invalid linking session.')
        self._lock = threading.Lock()
        self._request_gate = _FairRequestGate()
        self._route_performance = LinkingRoutePerformanceWindow()
        self._server_timing_window = ServerTimingWindow()
        self._upload_pipeline_waiting = threading.Event()
        self._media_connection_pool = _MediaConnectionPool()
        self._cancelled = threading.Event()
        self._pending_gallery_cancels: set[threading.Event] = set()
        self._visibility_snapshot: LinkedGalleryDetail | None = None
        self._gallery_edit_snapshot: LinkedGalleryEditSnapshot | None = None
        self._recent_media_pages: OrderedDict[
            int, tuple[float, LinkedMediaPage]
        ] = OrderedDict()
        # Complete newest-first display snapshots are metadata only. Their
        # original per-photo proof timestamps remain unchanged and are checked
        # by media_photo before any cached binary can be reused.
        self._recent_media_orders: OrderedDict[
            int, tuple[float, tuple[LinkedPhoto, ...]]
        ] = OrderedDict()
        # The most recent authorized public page supersedes previous
        # thumbnail descriptions, including removed rows and unproven bytes.
        self._public_media_descriptors: OrderedDict[
            int, dict[int, tuple[str | None, float | None]]
        ] = OrderedDict()
        # A no-validator original fallback becomes reusable within one
        # authorized metadata epoch only after binary revalidation succeeds.
        self._public_unverified_confirmations: OrderedDict[
            tuple[int, int], tuple[float, str]
        ] = OrderedDict()
        # Page authority covers automatic thumbnails. The separate final flag
        # confirms saved full-media intent; thumbnails cannot grant that intent.
        # Each row retains its source cursor so fullscreen can refresh a whole
        # authorization page without re-fetching the downloaded image bodies.
        self._private_preload_proofs: dict[
            int, tuple[
                int, float, dict[int, tuple[str, float, int, int | None]], bool,
            ]
        ] = {}
        self._private_preload_generation = 0
        self._private_preload_proof_epoch = 0
        self._bound_user_id: int | None = None
        self._issuer = issuer
        self._scope = scope
        self._admin_preload_supported = admin_preload_supported
        self._cover_page_supported: bool | None = None
        self._gallery_tree_supported = gallery_tree_supported
        self._thumbnail_batch_supported = thumbnail_batch_supported
        self._sliding_access_supported = (
            sliding_access_supported and scope.endswith(ROLLING_SCOPE_SUFFIX)
        )
        self._last_authenticated_at = time.monotonic()
        self._thumbnail_batch_disabled_reason = ''
        self._thumbnail_batch_cooldown_until = 0.0
        self._thumbnail_batch_cooldown_source: _ThumbnailCooldownSource = 'unknown'
        self._thumbnail_batch_cooldown_operation_finished: threading.Event | None = None
        self._token = token
        self._expires_at = expires_at
        self._busy = False
        self._active_route = ''
        self._renew: Callable[[threading.Event], tuple[str, float]] | None = None
        self._closed = False
