# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/session_gallery.py
# Module Type: Windows Client Session Behavior
# Purpose: Own gallery browsing, metadata changes and upload operations.
# Responsibilities:
#   - Keep one cohesive group of linked-session operations together.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own gallery browsing, metadata changes and upload operations."""

from typing import Iterable
from ..models import JsonValue
from typing import Literal
from dataclasses import field
import hashlib
import json
import logging
import re
import secrets
import threading
import time
from urllib.parse import urlencode
from .core import (
    ADMIN_GALLERY_EDIT_SCOPES,
    GALLERIES_SCOPE,
    LINKING_CREATE_TITLE_MAX_BYTES,
    LINKING_GALLERY_ID_MAX,
    LINKING_GALLERY_QUERY_MAX_CHARS,
    LINKING_GALLERY_REVISION_MAX,
    LINKING_GALLERY_TITLE_MAX_CHARS,
    LINKING_INVENTORY_BATCH_BYTES,
    LINKING_INVENTORY_BATCH_SIZE,
    LINKING_INVENTORY_CANDIDATE_MAX,
    LINKING_INVENTORY_REQUEST_MAX_BYTES,
    LINKING_INVENTORY_TOTAL_SECONDS,
    LINKING_MAX_TOKEN_LIFETIME_SECONDS,
    LINKING_RECOVERY_MAX_FILE_BYTES,
    LINKING_USERNAME_MAX_CHARS,
    LinkingError,
    LinkingGrantRejected,
    LinkingResponseError,
    LinkingRevisionConflict,
    LinkingScheduledPublication,
    UPLOAD_SCOPE,
    WATCH_KEY_RANDOM_BYTES,
    _GALLERY_TREE_REVISION_PATTERN,
    _WATCH_KEY_PATTERN,
    scope_allows_admin_media,
    scope_allows_gallery_create,
    scope_allows_gallery_read,
    scope_allows_gallery_search,
    scope_allows_gallery_visibility,
    scope_base,
)
from .models import (
    GALLERY_EDIT_READ_ATTEMPTS,
    GALLERY_EDIT_READ_DELAY_SECONDS,
    GALLERY_EDIT_WRITE_ATTEMPTS,
    LinkedGallery,
    LinkedGalleryDetail,
    LinkedGalleryEditSnapshot,
    LinkedGalleryPage,
    LinkedGalleryTree,
    LinkedIdentity,
    LinkedPhoto,
    LinkingGalleryEditFailure,
    TransientSessionSnapshot,
    _gallery_edit_snapshot,
    _linked_admin_photo,
    _linked_gallery_page,
    _linked_gallery_tree,
    _native_media_id,
    gallery_edit_text_valid,
)



class LinkingSessionGalleryMixin:
    """Provide gallery browsing, metadata changes and upload operations."""

    def can_list_galleries(self) -> bool:
        """Check the locally retained scope without claiming remote access.

        Returns:
            bool: True for a locally available or renewable listing approval.
        """
        with self._lock:
            return (not self._closed and bool(self._token)
                    and scope_base(self._scope) in (GALLERIES_SCOPE, UPLOAD_SCOPE)
                    and (self._renew is not None or time.monotonic() < self._expires_at))

    def can_search_galleries(self) -> bool:
        """Check the explicit metadata-search approval for this temporary link.

        Returns:
            bool: True while the current grant can search gallery metadata.
        """
        return scope_allows_gallery_search(self._scope) and self.available()

    def can_read_gallery_detail(self) -> bool:
        """Check locally held explicit gallery-detail consent and renewal.

        Returns:
            bool: True for an available or renewable read-scoped session.
        """
        return scope_allows_gallery_read(self._scope) and self.available()

    def can_edit_gallery_metadata(self) -> bool:
        """Check separately approved metadata authority without a server write.

        Returns:
            bool: True only for a live or renewable gallery:edit Photos grant.
        """
        return self.available() and self._scope in ADMIN_GALLERY_EDIT_SCOPES

    def read_gallery_edit(
        self, gallery_id: int, cancelled: threading.Event | None = None,
    ) -> 'LinkedGalleryEditSnapshot':
        """Read a complete current edit baseline, never the truncated info card.

        Args:
            gallery_id (int): Physical gallery selected for explicit editing.
            cancelled (threading.Event | None): Owning card/session cancellation.
        Returns:
            LinkedGalleryEditSnapshot: Fresh complete server values and revision.
        Raises:
            LinkingError: Authority, target, transport or snapshot is unavailable.
        """
        if not self.can_edit_gallery_metadata() or not _native_media_id(gallery_id):
            raise LinkingError('Gallery editing was not approved.')
        with self._lock:
            self._gallery_edit_snapshot = None
        body = json.dumps({
            'action': 'gallery_edit_read', 'gallery_id': gallery_id,
        }).encode('utf-8')
        data = self._request(
            'app_linking_gallery_metadata', body=body, gallery_cancel=cancelled,
        )
        snapshot = _gallery_edit_snapshot(data, gallery_id)
        with self._lock:
            if self._closed or (cancelled is not None and cancelled.is_set()):
                raise LinkingError('Gallery editor is no longer current.')
            self._gallery_edit_snapshot = snapshot
        return snapshot

    def save_gallery_edit(
        self, snapshot: 'LinkedGalleryEditSnapshot', field: str, value: str | bool,
        cancelled: threading.Event | None = None,
    ) -> 'LinkedGalleryEditSnapshot':
        """Submit one field once against its exact reviewed server revision.

        Args:
            snapshot (LinkedGalleryEditSnapshot): Current one-use edit baseline.
            field (str): title, description, nsfw or listed; unrelated fields stay.
            value (str | bool): Complete desired text or explicit boolean state.
            cancelled (threading.Event | None): Owning card/session cancellation.
        Returns:
            LinkedGalleryEditSnapshot: Canonical confirmed stored snapshot.
        Raises:
            LinkingRevisionConflict: The server refused a stale revision.
            LinkingError: Refusal or unknown completion; never retry this write.
        """
        if field == 'listed' and snapshot.visibility != 'public':
            raise LinkingError('Listing can only be changed for a public gallery.')
        if field in ('nsfw', 'listed'):
            valid = type(value) is bool
        elif field in ('title', 'description') and isinstance(value, str):
            valid = gallery_edit_text_valid(field, value)
        else:
            valid = False
        if not valid or not self.can_edit_gallery_metadata():
            raise LinkingError('Invalid gallery edit.')
        with self._lock:
            if (getattr(self, '_gallery_edit_snapshot', None) is not snapshot
                    or self._closed or int(snapshot.revision) >= LINKING_GALLERY_REVISION_MAX
                    or (cancelled is not None and cancelled.is_set())):
                raise LinkingError('Fresh gallery edit is unavailable.')
            self._gallery_edit_snapshot = None
        body = json.dumps({
            'action': 'gallery_edit', 'gallery_id': snapshot.gallery_id,
            'expected_revision': snapshot.revision, 'field': field, 'value': value,
        }, ensure_ascii=False).encode('utf-8')
        data = self._request(
            'app_linking_gallery_metadata', body=body, gallery_cancel=cancelled,
        )
        if data == {'__client_visibility_conflict': 'revision_conflict'}:
            raise LinkingRevisionConflict('Gallery revision changed.')
        current = _gallery_edit_snapshot(data, snapshot.gallery_id)
        if (current.revision != str(int(snapshot.revision) + 1)
                or getattr(current, field) != value):
            raise LinkingError('Gallery edit was not confirmed.')
        for other in ('title', 'description', 'visibility', 'listed', 'nsfw'):
            if other != field and getattr(current, other) != getattr(snapshot, other):
                raise LinkingError('Unrelated gallery metadata changed.')
        return current

    def apply_gallery_edit(
        self, gallery_id: int, field: str, value: str | bool,
        cancelled: threading.Event | None = None,
    ) -> LinkedGalleryEditSnapshot:
        """Apply one user intent and reconcile its result without manual reload.

        Every write retains the server's exact revision precondition. A definite
        conflict permits one refreshed field-only attempt. An unknown outcome
        permits another write only if a fresh complete observation is identical
        to its baseline; the unchanged revision fences a still-running first
        request. A changed revision never licenses replay of an unknown write.

        Args:
            gallery_id (int): Exact physical gallery selected by the user.
            field (str): title, description, nsfw or listed; peers are preserved.
            value (str | bool): Explicit desired field value, never a toggle delta.
            cancelled (threading.Event | None): Owning context cancellation.
        Returns:
            LinkedGalleryEditSnapshot: Authorized canonical state with the
                requested value, from the acknowledgement or a fresh read.
        Raises:
            LinkingGalleryEditFailure: Finite reconciliation failed; a fresh
                observation is retained when available and the draft stays editable.
            LinkingError: Local input, authority or cancellation is invalid.
        """
        valid = (
            type(value) is bool if field in ('listed', 'nsfw')
            else isinstance(value, str) and field in ('title', 'description')
            and gallery_edit_text_valid(field, value)
        )
        if not valid:
            raise LinkingError('Invalid gallery edit.')
        if cancelled is not None and cancelled.is_set():
            raise LinkingError('Gallery editor is no longer current.')
        snapshot = self.read_gallery_edit(gallery_id, cancelled=cancelled)
        for _attempt in range(GALLERY_EDIT_WRITE_ATTEMPTS):
            if cancelled is not None and cancelled.is_set():
                raise LinkingError('Gallery editor is no longer current.')
            if getattr(snapshot, field) == value:
                return snapshot
            conflict = False
            retryable = True
            try:
                return self.save_gallery_edit(snapshot, field, value, cancelled=cancelled)
            except LinkingRevisionConflict:
                # A 409 is a definite refusal before mutation, so the user's
                # explicit field intent may use the latest reviewed revision.
                conflict = True
            except LinkingError as error:
                # Authentication, rate limits and other definite client refusals
                # are observed but never turned into another automatic write.
                status = getattr(error, 'status', None)
                retryable = not (
                    isinstance(error, LinkingGrantRejected)
                    or isinstance(status, int) and 400 <= status < 500
                )
            observed: LinkedGalleryEditSnapshot | None = None
            for read_attempt in range(GALLERY_EDIT_READ_ATTEMPTS):
                if cancelled is not None and cancelled.is_set():
                    raise LinkingError('Gallery editor is no longer current.')
                if read_attempt:
                    if cancelled is not None:
                        if cancelled.wait(GALLERY_EDIT_READ_DELAY_SECONDS):
                            raise LinkingError('Gallery editor is no longer current.')
                    else:
                        time.sleep(GALLERY_EDIT_READ_DELAY_SECONDS)
                try:
                    observed = self.read_gallery_edit(gallery_id, cancelled=cancelled)
                    break
                except LinkingError as error:
                    status = getattr(error, 'status', None)
                    if (isinstance(error, LinkingGrantRejected)
                            or isinstance(status, int) and 400 <= status < 500):
                        break
            if observed is None:
                raise LinkingGalleryEditFailure(None) from None
            if getattr(observed, field) == value:
                return observed
            if not retryable or (not conflict and observed != snapshot):
                raise LinkingGalleryEditFailure(
                    observed, conflict=observed.revision != snapshot.revision,
                ) from None
            snapshot = observed
        raise LinkingGalleryEditFailure(snapshot, conflict=conflict)

    def can_change_gallery_visibility(self) -> bool:
        """Check explicit visibility consent without asserting server access.

        Returns:
            bool: True for an available or renewable visibility-scoped session.
        """
        return scope_allows_gallery_visibility(self._scope) and self.available()

    def can_upload(self) -> bool:
        """Check locally held upload scope without claiming server permission.

        Returns:
            bool: True only while the explicit upload bundle is held locally.
        """
        with self._lock:
            return (not self._closed and bool(self._token)
                    and scope_base(self._scope) == UPLOAD_SCOPE
                    and (self._renew is not None or time.monotonic() < self._expires_at))

    def set_upload_pipeline_waiting(self, waiting: bool) -> None:
        """Publish upload backpressure without cancelling any active media request.

        Args:
            waiting (bool): True while upload work is retrying or needs review.

        Returns:
            None: Controls speculative work while explicit browsing stays usable.
        """
        if waiting:
            self._upload_pipeline_waiting.set()
        else:
            self._upload_pipeline_waiting.clear()

    def background_media_allowed(self) -> bool:
        """Expose upload backpressure to optional preload and adjacent prefetch.

        Returns:
            bool: True when speculative media may join the existing serial transport.
        """
        return not self._upload_pipeline_waiting.is_set()

    def approved_scope(self) -> str:
        """Read the exact locally held scope without exposing its bearer.

        Returns:
            str: Exact approved scope while the local session remains live.

        Raises:
            LinkingError: The local session is closed or expired.
        """
        with self._lock:
            if (self._closed or not self._token
                    or (self._renew is None and time.monotonic() >= self._expires_at)):
                raise LinkingError('Linking session is unavailable.')
            return self._scope

    def transient_snapshot(self) -> TransientSessionSnapshot:
        """Return a live non-offline bearer with its unchanged original expiry.

        Returns:
            TransientSessionSnapshot: Exact grant suitable for OS-vault storage.

        Raises:
            LinkingError: The session is closed, expired or uses offline scope.
        """
        with self._lock:
            remaining = self._expires_at - time.monotonic()
            if (self._closed or not self._token or remaining <= 0
                    or self._scope.endswith(' offline_access')
                    or remaining > LINKING_MAX_TOKEN_LIFETIME_SECONDS):
                raise LinkingError('Transient session is unavailable.')
            return TransientSessionSnapshot(
                self._issuer, self._scope, self._token, time.time() + remaining,
            )

    def can_create_gallery(self) -> bool:
        """Check locally held create scope without asserting server access.

        Returns:
            bool: True for an available or renewable creation-scoped session.
        """
        return scope_allows_gallery_create(self._scope) and self.available()

    def gallery_create_request(
        self, operation_key: str, title: str | None = None,
        parent_id: int | None = None, *, cancelled: threading.Event | None = None,
        abandon: bool = False,
    ) -> LinkedGallery | None:
        """Send one create, status or explicit closure request without retrying.

        Args:
            operation_key (str): Durable 64-character lowercase operation key.
            title (str | None): Exact create title, or None to read status only.
            parent_id (int | None): Optional positive parent for a create request.
            cancelled (threading.Event | None): Optional create cancellation signal.
            abandon (bool): Explicitly close this original operation instead of
                creating it; requires the original title and parent.

        Returns:
            LinkedGallery | None: Validated completed receipt, None for unknown
                read-only status or an explicitly confirmed abandoned operation.

        Raises:
            LinkingError: Input, permission, transport or receipt is invalid.
        """
        if (type(abandon) is not bool or not isinstance(operation_key, str)
                or re.fullmatch(r'[0-9a-f]{64}', operation_key) is None
                or (parent_id is not None and (type(parent_id) is not int
                    or not 0 < parent_id <= LINKING_GALLERY_ID_MAX))):
            raise LinkingError('Invalid gallery creation request.')
        if title is None:
            if parent_id is not None or abandon:
                raise LinkingError('Invalid gallery creation status request.')
            route = 'app_linking_gallery_create_status'
            payload = {'operation_key': operation_key}
        else:
            try:
                valid = (isinstance(title, str) and bool(title.strip(' \t\n\r\x00\x0b'))
                         and len(title.encode('utf-8')) <= LINKING_CREATE_TITLE_MAX_BYTES
                         and not any(ord(c) < 32 or ord(c) == 127 for c in title))
            except UnicodeError:
                valid = False
            if not valid:
                raise LinkingError('Invalid gallery title.')
            route = ('app_linking_gallery_create_abandon' if abandon
                     else 'app_linking_gallery_create')
            payload = {'operation_key': operation_key, 'title': title,
                       'parent_id': parent_id}
        body = json.dumps(payload, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
        data = self._request(route, body=body, gallery_cancel=cancelled)
        if abandon and data == {'state': 'abandoned'}:
            if not self.can_create_gallery():
                raise LinkingError('Gallery creation session expired.')
            return None
        if data == {'state': 'unknown'} and title is None:
            return None
        row = data.get('gallery')
        if (set(data) != {'state', 'gallery'} or data['state'] != 'completed'
                or not isinstance(row, dict) or set(row) != {'id', 'title', 'parent_id'}):
            raise LinkingError('Gallery creation receipt was not accepted.')
        gallery_id, result_title, result_parent = row['id'], row['title'], row['parent_id']
        try:
            valid_title = (isinstance(result_title, str)
                           and bool(result_title.strip(' \t\n\r\x00\x0b'))
                           and len(result_title.encode('utf-8'))
                           <= LINKING_CREATE_TITLE_MAX_BYTES
                           and not any(ord(c) < 32 or ord(c) == 127
                                       for c in result_title))
        except UnicodeError:
            valid_title = False
        if (type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
                or (result_parent is not None and (type(result_parent) is not int
                    or not 0 < result_parent <= LINKING_GALLERY_ID_MAX))
                or not valid_title):
            raise LinkingError('Gallery creation receipt was not accepted.')
        if not self.can_create_gallery():
            raise LinkingError('Gallery creation session expired.')
        return LinkedGallery(gallery_id, result_parent, result_title)

    def matches_issuer(self, issuer: str) -> bool:
        """Compare a proposed local destination with this exact session issuer.

        Args:
            issuer (str): Canonical installation origin and mount.

        Returns:
            bool: True only while this locally held session matches the issuer.
        """
        with self._lock:
            return (not self._closed and self._issuer == issuer and bool(self._token)
                    and (self._renew is not None or time.monotonic() < self._expires_at))

    def open_watch_key(
        self, gallery_id: int, *, cancelled: threading.Event | None = None,
    ) -> str:
        """Register one fresh in-memory credential for the selected capture.

        Args:
            gallery_id (int): Exact physical upload gallery ID.
            cancelled (threading.Event | None): Current start action cancellation.

        Returns:
            str: Opaque capture key sent only with this run's linked requests.

        Raises:
            LinkingError: The capture credential could not be verified.
        """
        if not _native_media_id(gallery_id) or not self.can_upload():
            raise LinkingError('Watcher access is unavailable.')
        key = 'pgw_' + secrets.token_hex(WATCH_KEY_RANDOM_BYTES)
        try:
            self._change_watch_key('watch_open', gallery_id, key, cancelled)
        except LinkingError:
            # The reply may have been lost after registration. A scoped close
            # cannot remove a newer capture's key, even after a lost reply.
            try:
                self.close_watch_key(gallery_id, key)
            except LinkingError:
                pass
            raise
        return key

    def close_watch_key(
        self, gallery_id: int, key: str, *, cancelled: threading.Event | None = None,
    ) -> None:
        """Revoke the exact temporary key without closing the site link.

        Args:
            gallery_id (int): Physical gallery that admitted the capture.
            key (str): Opaque key returned when the same capture started.
            cancelled (threading.Event | None): Optional cleanup cancellation.

        Returns:
            None: The server confirmed closure of this key generation.

        Raises:
            LinkingError: The close request could not be confirmed.
        """
        self._change_watch_key('watch_close', gallery_id, key, cancelled)

    def _change_watch_key(
        self, action: Literal['watch_open', 'watch_close'], gallery_id: int,
        key: str, cancelled: threading.Event | None,
    ) -> None:
        """Send a hash-only lifecycle request on the current upload grant.

        Args:
            action (Literal['watch_open', 'watch_close']): Exact lifecycle action.
            gallery_id (int): Physical gallery owned by this capture.
            key (str): Exact raw key whose hash is registered or closed.
            cancelled (threading.Event | None): Operation cancellation signal.

        Returns:
            None: The server acknowledged the same action and gallery.

        Raises:
            LinkingError: Input, live upload scope or reply was refused.
        """
        if (not _native_media_id(gallery_id) or not isinstance(key, str)
                or _WATCH_KEY_PATTERN.fullmatch(key) is None):
            raise LinkingError('Invalid Watcher credential.')
        body = json.dumps({
            'action': action, 'gallery_id': gallery_id,
            'key_hash': hashlib.sha256(key.encode('ascii')).hexdigest(),
        }, separators=(',', ':')).encode('ascii')
        data = self._request('app_linking_inventory', body=body, gallery_cancel=cancelled)
        if (data != {'ok': True, 'action': action, 'gallery_id': gallery_id}
                or data.get('ok') is not True or type(data.get('gallery_id')) is not int):
            raise LinkingError('Watcher credential change was not confirmed.')

    def inventory(
        self, gallery_id: int, files: list[dict[str, JsonValue]], *,
        watch_key: str | None = None,
    ) -> dict[str, JsonValue]:
        """Read verified inventory in bounded, all-or-nothing request batches.

        Args:
            gallery_id (int): Stable positive server gallery identifier.
            files (list[dict[str, JsonValue]]): Candidate objects containing
                client_id, filename, nonnegative size and lowercase SHA-256.
            watch_key (str | None): Supplemental key for a contextual capture.

        Returns:
            dict[str, JsonValue]: Combined validated snapshots for this gallery.
                Individual rows still require a fresh check before skipping work.

        Raises:
            LinkingError: Scope, target, candidates, response or session fails.
        """
        if (type(gallery_id) is not int or gallery_id <= 0
                or gallery_id > LINKING_GALLERY_ID_MAX
                or len(files) > LINKING_INVENTORY_CANDIDATE_MAX):
            raise LinkingError('Invalid gallery inventory request.')
        candidates: dict[str, tuple[str, str, int]] = {}
        for entry in files:
            if not isinstance(entry, dict) or set(entry) != {
                'client_id', 'filename', 'size', 'sha256',
            }:
                raise LinkingError('Invalid gallery inventory request.')
            client_id, filename = entry['client_id'], entry['filename']
            size, file_hash = entry['size'], entry['sha256']
            if (not isinstance(client_id, str)
                    or re.fullmatch(r'[A-Za-z0-9_-]{1,80}', client_id) is None
                    or not isinstance(filename, str)
                    or not filename or len(filename) > 255
                    or '/' in filename or '\\' in filename
                    or type(size) is not int or size < 0
                    or not isinstance(file_hash, str)
                    or re.fullmatch(r'[0-9a-f]{64}', file_hash) is None):
                raise LinkingError('Invalid gallery inventory request.')
            previous = candidates.get(file_hash)
            if previous is not None and previous[2] != size:
                raise LinkingError('Invalid gallery inventory request.')
            # The server normalizes names and retains the last descriptor for
            # each hash; filenames are display hints, never filesystem paths.
            normalized_name = filename.strip(' \t\n\r\x00\x0b') or file_hash
            candidates[file_hash] = (client_id, normalized_name, size)
        try:
            body = json.dumps({
                'action': 'inventory', 'gallery_id': gallery_id,
                'files': files, 'deep_check': False,
            }, separators=(',', ':'), ensure_ascii=True,
                allow_nan=False).encode('ascii')
        except (TypeError, ValueError, UnicodeError):
            raise LinkingError('Invalid gallery inventory request.') from None
        if len(body) > LINKING_INVENTORY_REQUEST_MAX_BYTES:
            raise LinkingError('Gallery inventory request is too large.')
        unique = list(candidates.items())
        batches: list[list[tuple[str, tuple[str, str, int]]]] = []
        current: list[tuple[str, tuple[str, str, int]]] = []
        current_bytes = 0
        for candidate in unique:
            size = candidate[1][2]
            if current and (len(current) >= LINKING_INVENTORY_BATCH_SIZE
                            or current_bytes + size > LINKING_INVENTORY_BATCH_BYTES):
                batches.append(current)
                current, current_bytes = [], 0
            current.append(candidate)
            current_bytes += size
        if current:
            batches.append(current)
        if not batches:
            batches = [[]]
        deadline = time.monotonic() + LINKING_INVENTORY_TOTAL_SECONDS
        combined: list[JsonValue] = []
        data: dict[str, JsonValue] = {}
        watch_options = {'watch_key': watch_key} if watch_key is not None else {}
        for batch in batches:
            if time.monotonic() >= deadline:
                raise LinkingError('Gallery inventory did not complete safely.')
            expected = dict(batch)
            batch_files = [
                {'sha256': digest, 'client_id': fields[0],
                 'filename': fields[1], 'size': fields[2]}
                for digest, fields in batch
            ]
            batch_body = json.dumps({
                'action': 'inventory', 'gallery_id': gallery_id,
                'files': batch_files, 'deep_check': False,
            }, separators=(',', ':'), ensure_ascii=True, allow_nan=False).encode('ascii')
            data = self._request('app_linking_inventory', body=batch_body, **watch_options)
            if time.monotonic() >= deadline:
                raise LinkingError('Gallery inventory did not complete safely.')
            existing = data.get('existing')
            if (set(data) != {'ok', 'action', 'gallery_id', 'gallery_title', 'checked',
                              'matched', 'existing', 'deep_check_applied', 'fingerprint',
                              'server_time', 'inventory_evidence'}
                    or data.get('ok') is not True or data.get('action') != 'inventory'
                    or type(data.get('gallery_id')) is not int
                    or data['gallery_id'] != gallery_id
                    or data.get('inventory_evidence') != 'verified_file_v1'
                    or not isinstance(data.get('gallery_title'), str)
                    or type(data.get('checked')) is not int
                    or data['checked'] != len(expected)
                    or not isinstance(existing, list) or len(existing) > len(expected)
                    or type(data.get('matched')) is not int
                    or data['matched'] != len(existing)
                    or data.get('deep_check_applied') is not False
                    or not isinstance(data.get('fingerprint'), str)
                    or not isinstance(data.get('server_time'), str)):
                raise LinkingError('Gallery inventory response was not accepted.')
            seen: set[str] = set()
            for row in existing:
                if (not isinstance(row, dict)
                        or set(row) != {'client_id', 'filename', 'size', 'sha256',
                                       'remote_filename', 'remote_relative_path',
                                       'remote_image_id', 'matched_by'}):
                    raise LinkingError('Gallery inventory response was not accepted.')
                digest = row['sha256']
                remote = row['remote_filename']
                if (not isinstance(digest, str) or digest not in expected or digest in seen
                        or (row['client_id'], row['filename'], row['size']) != expected[digest]
                        or type(row['size']) is not int
                        or not isinstance(remote, str) or not remote
                        or '/' in remote or '\\' in remote
                        or row['remote_relative_path'] != remote
                        or type(row['remote_image_id']) is not int
                        or not 0 < row['remote_image_id'] <= LINKING_GALLERY_ID_MAX
                        or row['matched_by'] != 'checksum_sha256'):
                    raise LinkingError('Gallery inventory response was not accepted.')
                seen.add(digest)
            combined.extend(existing)
        return {**data, 'checked': len(candidates), 'matched': len(combined),
                'existing': combined}

    def probe_uncertain_upload(self, gallery_id: int, file_hash: str, size: int) -> str:
        """Observe matching bytes once without deciding an upload outcome.

        Args:
            gallery_id (int): Current authorized gallery identifier.
            file_hash (str): Lowercase SHA-256 read from an uncertain journal.
            size (int): User-supplied original size in bytes.

        Returns:
            str: Either present or unknown; neither confirms an upload outcome.

        Raises:
            LinkingError: The request, response or live scope is unavailable.
        """
        if (type(gallery_id) is not int or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX
                or not isinstance(file_hash, str)
                or re.fullmatch(r'[0-9a-f]{64}', file_hash) is None
                or type(size) is not int or not 0 < size <= LINKING_RECOVERY_MAX_FILE_BYTES):
            raise LinkingError('Invalid recovery observation.')
        body = json.dumps({
            'action': 'inventory', 'gallery_id': gallery_id,
            'files': [{'client_id': file_hash, 'filename': 'recovery.jpg',
                       'size': size, 'sha256': file_hash}],
            'deep_check': True,
        }, separators=(',', ':'), ensure_ascii=True).encode('ascii')
        data = self._request('app_linking_inventory', body=body)
        if (set(data) != {'ok', 'action', 'gallery_id', 'result'}
                or data['ok'] is not True or data['action'] != 'recovery'
                or type(data['gallery_id']) is not int or data['gallery_id'] != gallery_id
                or data['result'] not in ('present', 'unknown')):
            raise LinkingError('Recovery observation was not accepted.')
        return str(data['result'])

    def flight_report_request(
        self, gallery_id: int, payload: dict[str, JsonValue] | None,
        body: Iterable[bytes] | None, boundary: str | None,
        content_length: int | None, *, watch_key: str | None = None,
    ) -> dict[str, JsonValue]:
        """Send one delegated PDF request through the existing upload grant.

        Args:
            gallery_id (int): Exact positive physical gallery identifier.
            payload (dict[str, JsonValue] | None): Status action, report_id,
                language and sha256; None for multipart upload.
            body (Iterable[bytes] | None): One-shot PDF MIME body or None.
            boundary (str | None): MIME boundary or None for status.
            content_length (int | None): Exact MIME size or None for status.
            watch_key (str | None): Captured supplemental Watcher credential.
        Returns:
            dict[str, JsonValue]: Same-gallery status or attachment envelope.
        Raises:
            LinkingError: Scope, input, target, request or response is invalid.
        """
        if not _native_media_id(gallery_id) or not self.can_upload():
            raise LinkingError('Flight report upload access is unavailable.')
        if payload is not None:
            if body is not None or payload.get('action') != 'flight_report_status':
                raise LinkingError('Invalid flight report request.')
            encoded = json.dumps(
                {**payload, 'gallery_id': gallery_id}, separators=(',', ':'),
            ).encode('utf-8')
            data = self._request(
                'app_linking_inventory', body=encoded, watch_key=watch_key,
            )
            action = 'flight_report_status'
        else:
            if body is None or boundary is None or content_length is None:
                raise LinkingError('Invalid flight report upload.')
            data = self._request(
                'app_linking_upload', body=body, multipart_boundary=boundary,
                content_length=content_length, watch_key=watch_key,
            )
            action = 'flight_report'
        if (data.get('ok') is not True or data.get('action') != action
                or type(data.get('gallery_id')) is not int
                or data['gallery_id'] != gallery_id):
            raise LinkingError('Flight report target was not confirmed.')
        return data

    def upload_multipart(
        self, gallery_id: int, body: Iterable[bytes], boundary: str,
        content_length: int, *, watch_key: str | None = None,
    ) -> dict[str, JsonValue]:
        """Send one explicitly selected gallery's upload without retrying it.

        Args:
            gallery_id (int): Stable positive selected gallery identifier.
            body (Iterable[bytes]): One-shot multipart bytes and file chunks.
            boundary (str): Generated ASCII multipart delimiter.
            content_length (int): Exact complete request body size in bytes.
            watch_key (str | None): Supplemental key for a contextual capture.

        Returns:
            dict[str, JsonValue]: Server acknowledgement for exactly one image.

        Raises:
            LinkingError: Target, scope, transport or acknowledgement fails;
                a failed response may be ambiguous and must not be auto-retried.
        """
        if (type(gallery_id) is not int or gallery_id <= 0
                or gallery_id > LINKING_GALLERY_ID_MAX):
            raise LinkingError('Invalid gallery upload request.')
        watch_options = {'watch_key': watch_key} if watch_key is not None else {}
        data = self._request(
            'app_linking_upload', body=body, multipart_boundary=boundary,
            content_length=content_length, **watch_options,
        )
        image_ids, filenames = data.get('image_ids'), data.get('filenames')
        if (data.get('ok') is not True or type(data.get('gallery_id')) is not int
                or data['gallery_id'] != gallery_id
                or data.get('upload_evidence') != 'fresh_insert_v1'
                or type(data.get('uploaded')) is not int
                or data['uploaded'] != 1
                or not isinstance(image_ids, list) or len(image_ids) != 1
                or type(image_ids[0]) is not int
                or not 0 < image_ids[0] <= LINKING_GALLERY_ID_MAX
                or not isinstance(filenames, list) or len(filenames) != 1
                or not isinstance(filenames[0], str) or not filenames[0]
                or type(data.get('scanned')) is not int or data['scanned'] < 0):
            raise LinkingError('Gallery upload was not confirmed.')
        for key in ('renamed', 'thumbnails', 'thumbnail_failed'):
            if key in data and (type(data[key]) is not int or data[key] < 0):
                raise LinkingError('Gallery upload was not confirmed.')
        for key in ('rename_warnings', 'rename_failures', 'thumbnail_errors',
                    'post_upload_errors'):
            if key in data and (not isinstance(data[key], list)
                                or any(not isinstance(item, str) for item in data[key])):
                raise LinkingError('Gallery upload was not confirmed.')
        camera = data.get('sim_camera_metadata')
        if 'sim_camera_metadata' in data and (not isinstance(camera, dict)
                or any(type(camera.get(key)) is not int or camera[key] < 0
                       for key in ('attached', 'skipped'))
                or not isinstance(camera.get('error'), str)):
            raise LinkingError('Gallery upload was not confirmed.')
        thumbnails = data.get('client_thumbnails')
        if 'client_thumbnails' in data and (not isinstance(thumbnails, dict)
                or any(type(thumbnails.get(key)) is not int or thumbnails[key] < 0
                       for key in ('installed', 'skipped', 'failed'))
                or not isinstance(thumbnails.get('errors'), list)
                or any(not isinstance(item, str) for item in thumbnails['errors'])):
            raise LinkingError('Gallery upload was not confirmed.')
        return data

    def gallery_page(
        self, after_id: int | None = None,
        cancelled: threading.Event | None = None,
    ) -> LinkedGalleryPage:
        """Read and validate one fresh, bounded server-owned gallery page.

        Args:
            after_id (int | None): Exclusive positive ID from the prior page.
            cancelled (threading.Event | None): Dialog-owned cancellation
                registered before the background worker starts.

        Returns:
            LinkedGalleryPage: Ordered current rows and optional next cursor.

        Raises:
            LinkingError: Scope, cursor, response or current session is invalid.
        """
        if (after_id is not None and (type(after_id) is not int or after_id <= 0
                                      or after_id > LINKING_GALLERY_ID_MAX)):
            raise LinkingError('Invalid gallery page cursor.')
        data = self._request('app_linking_galleries', after_id, gallery_cancel=cancelled)
        page = _linked_gallery_page(data, after_id)
        if not self.available():
            raise LinkingError('Linking session is unavailable.')
        return page

    def supports_gallery_tree(self) -> bool:
        """Check whether discovery negotiated the optional full-tree route.

        Returns:
            bool: True only for a live session with the exact tree-v1 capability.
        """
        with self._lock:
            return (not self._closed and self._gallery_tree_supported
                    and bool(self._token)
                    and (self._renew is not None
                         or time.monotonic() < self._expires_at))

    def gallery_tree(
        self, revision: str | None = None,
        cancelled: threading.Event | None = None,
    ) -> LinkedGalleryTree | None:
        """Read the optional complete tree or return None for safe fallback.

        Args:
            revision (str | None): Validated cached server revision, if any.
            cancelled (threading.Event | None): Workspace-owned cancellation.

        Returns:
            LinkedGalleryTree | None: Complete tree, unchanged confirmation, or
                None when the optional endpoint is unsupported/unavailable.

        Raises:
            LinkingError: The current grant, scope, session or cancellation failed.
        """
        if (not self.available()
                or (cancelled is not None and cancelled.is_set())):
            raise LinkingError('Linking session is unavailable.')
        if not self.supports_gallery_tree():
            return None
        if (revision is not None
                and (not isinstance(revision, str)
                     or _GALLERY_TREE_REVISION_PATTERN.fullmatch(revision) is None)):
            return None
        try:
            data = self._request(
                'app_linking_gallery_tree', gallery_cancel=cancelled,
                tree_revision=revision,
            )
        except LinkingResponseError as error:
            if error.status in (401, 403, 429) or error.reason == 'throttled':
                raise
            logging.getLogger('uploader.app_linking').info(
                '[photos] catalogue tree fallback; %s', error.safe_summary,
            )
            return None
        try:
            tree = _linked_gallery_tree(data, revision)
        except LinkingError:
            logging.getLogger('uploader.app_linking').info(
                '[photos] catalogue tree fallback; reason=invalid_shape',
            )
            return None
        if not self.available():
            raise LinkingError('Linking session is unavailable.')
        return tree

    def gallery_picker_page(
        self, query: str, after_id: int | None = None,
        cancelled: threading.Event | None = None,
    ) -> LinkedGalleryPage:
        """Search the approved catalog or browse a page in tree order.

        Args:
            query (str): Bounded literal search expression, or empty to browse.
            after_id (int | None): Last gallery from the same query's prior page.
            cancelled (threading.Event | None): Cancellation for this choice read.

        Returns:
            LinkedGalleryPage: Ranked matches with parent titles and pagination.

        Raises:
            LinkingError: Invalid search, expired approval or unsafe reply.
        """
        if (not isinstance(query, str) or len(query) > LINKING_GALLERY_QUERY_MAX_CHARS
                or any(ord(char) < 32 or ord(char) == 127
                       or 0xd800 <= ord(char) <= 0xdfff for char in query)
                or (after_id is not None and (type(after_id) is not int
                    or not 0 < after_id <= LINKING_GALLERY_ID_MAX))):
            raise LinkingError('Invalid gallery search.')
        body = urlencode({'query': query, 'after': after_id or ''}).encode('ascii')
        data = self._request('app_linking_galleries', body=body, gallery_cancel=cancelled)
        page = _linked_gallery_page(data, after_id, picker=True)
        if not self.available():
            raise LinkingError('Linking session is unavailable.')
        return page

    def gallery_detail(
        self, gallery_id: int, cancelled: threading.Event | None = None,
    ) -> LinkedGalleryDetail:
        """Read exact stored properties for one explicitly selected gallery.

        Args:
            gallery_id (int): Positive ID selected from a fresh server page.
            cancelled (threading.Event | None): Optional dialog cancellation.

        Returns:
            LinkedGalleryDetail: Validated fields for exactly the selected ID.

        Raises:
            LinkingError: Scope, target, transport or response is unavailable.
        """
        if (type(gallery_id) is not int or gallery_id <= 0
                or gallery_id > LINKING_GALLERY_ID_MAX):
            raise LinkingError('Invalid gallery detail target.')
        with self._lock:
            self._visibility_snapshot = None
        data = self._request(
            'app_linking_gallery_detail', gallery_cancel=cancelled,
            gallery_id=gallery_id,
        )
        row = data.get('gallery')
        if (set(data) != {'gallery'} or not isinstance(row, dict)
                or set(row) != {'id', 'title', 'revision', 'visibility',
                               'access_mode', 'access_listing'}):
            raise LinkingError('Gallery detail was not accepted.')
        title, revision = row['title'], row['revision']
        if (type(row['id']) is not int or row['id'] != gallery_id
                or not isinstance(title, str) or len(title) > LINKING_GALLERY_TITLE_MAX_CHARS
                or any(0xd800 <= ord(char) <= 0xdfff for char in title)
                or not isinstance(revision, str)
                or re.fullmatch(r'[1-9][0-9]{0,19}', revision) is None
                or int(revision) > LINKING_GALLERY_REVISION_MAX
                or row['visibility'] not in ('draft', 'unpublished', 'public', 'private')
                or row['access_mode'] not in ('normal', 'password')
                or row['access_listing'] not in ('listed', 'unlisted')):
            raise LinkingError('Gallery detail was not accepted.')
        detail = LinkedGalleryDetail(
            gallery_id, title, revision, row['visibility'], row['access_mode'],
            row['access_listing'],
        )
        with self._lock:
            if self._closed or time.monotonic() >= self._expires_at:
                raise LinkingError('Linking session is unavailable.')
            self._visibility_snapshot = detail
        return detail

    def change_photo_visibility(
        self, gallery_id: int, photo: LinkedPhoto, visibility: str,
        cancelled: threading.Event | None = None,
    ) -> LinkedPhoto:
        """Submit one scoped change against the photo's observed visibility.

        Args:
            gallery_id (int): Exact physical gallery currently displaying the photo.
            photo (LinkedPhoto): Descriptor containing the observed stored visibility.
            visibility (str): Explicit public, private or draft target.
            cancelled (threading.Event | None): Current viewer cancellation.

        Returns:
            LinkedPhoto: Fresh descriptor confirmed by the server after the write.

        Raises:
            LinkingError: The change was rejected or its outcome is uncertain.
            LinkingRevisionConflict: The prior visibility or target changed.
        """
        values = ('draft', 'private', 'public')
        if (not _native_media_id(gallery_id) or not isinstance(photo, LinkedPhoto)
                or not _native_media_id(photo.image_id) or photo.visibility not in values
                or visibility not in values or visibility == photo.visibility):
            raise LinkingError('Invalid photo visibility request.')
        body = json.dumps({
            'gallery_id': gallery_id, 'image_id': photo.image_id,
            'expected_visibility': photo.visibility, 'visibility': visibility,
        }, separators=(',', ':'), ensure_ascii=True).encode('ascii')
        data = self._request('app_linking_photo_visibility', body=body, gallery_cancel=cancelled)
        if data == {'__client_visibility_conflict': 'revision_conflict'}:
            raise LinkingRevisionConflict('Photo visibility changed.')
        row = data.get('photo')
        if set(data) != {'photo'} or not isinstance(row, dict):
            raise LinkingError('Photo visibility change was not confirmed.')
        updated = _linked_admin_photo(row)
        if updated.image_id != photo.image_id or updated.visibility != visibility:
            raise LinkingError('Photo visibility change was not confirmed.')
        return updated

    def change_gallery_visibility(
        self, detail: LinkedGalleryDetail, visibility: str,
        cancelled: threading.Event | None = None,
    ) -> LinkedGalleryDetail:
        """Submit one visibility change against a fresh, single-use detail.

        Args:
            detail (LinkedGalleryDetail): Most recent detail from this session.
            visibility (str): Explicit public, private or unpublished target.
            cancelled (threading.Event | None): Dialog cancellation signal.

        Returns:
            LinkedGalleryDetail: Fresh detail agreeing with the acknowledged
                gallery, revision, visibility and access fields.

        Raises:
            LinkingError: Rejected or uncertain operation; do not retry it.
        """
        if not isinstance(detail, LinkedGalleryDetail) or visibility not in (
            'public', 'private', 'unpublished',
        ):
            raise LinkingError('Invalid visibility request.')
        previous = 'unpublished' if detail.visibility == 'draft' else detail.visibility
        with self._lock:
            if (self._visibility_snapshot is not detail or self._closed
                    or time.monotonic() >= self._expires_at
                    or not scope_allows_gallery_visibility(self._scope)
                    or visibility == previous
                    or int(detail.revision) >= LINKING_GALLERY_REVISION_MAX
                    or (cancelled is not None and cancelled.is_set())):
                raise LinkingError('Fresh visibility detail is unavailable.')
            # Consume before dispatch: even a lost reply cannot replay this edit.
            self._visibility_snapshot = None
        body = json.dumps({
            'gallery_id': detail.gallery_id,
            'expected_revision': detail.revision,
            'visibility': visibility,
        }, separators=(',', ':'), ensure_ascii=True).encode('ascii')
        data = self._request(
            'app_linking_gallery_visibility', body=body,
            gallery_cancel=cancelled,
        )
        if data == {'__client_visibility_conflict': 'scheduled_publication_active'}:
            raise LinkingScheduledPublication('Scheduled publication is active.')
        if data == {'__client_visibility_conflict': 'revision_conflict'}:
            raise LinkingRevisionConflict('Gallery revision changed.')
        row = data.get('gallery')
        accepted_visibility = (('unpublished', 'draft')
                               if visibility == 'unpublished' else (visibility,))
        if (set(data) != {'gallery'} or not isinstance(row, dict)
                or set(row) != {'id', 'revision', 'visibility',
                                'access_mode', 'access_listing'}
                or type(row['id']) is not int or row['id'] != detail.gallery_id
                or row['revision'] != str(int(detail.revision) + 1)
                or row['visibility'] not in accepted_visibility
                or row['access_mode'] != detail.access_mode
                or row['access_listing'] != detail.access_listing):
            raise LinkingError('Visibility change was not confirmed.')
        current = self.gallery_detail(detail.gallery_id, cancelled)
        if (current.revision != row['revision'] or current.visibility != row['visibility']
                or current.access_mode != row['access_mode']
                or current.access_listing != row['access_listing']
                or (cancelled is not None and cancelled.is_set())):
            with self._lock:
                if self._visibility_snapshot is current:
                    self._visibility_snapshot = None
            raise LinkingError('Visibility change was not confirmed by fresh detail.')
        return current

    def identity(self) -> LinkedIdentity:
        """Read and validate the live identity of this scoped session.

        Returns:
            LinkedIdentity: Positive user ID and bounded current username.

        Raises:
            LinkingError: The operation fails or the identity is malformed.
        """
        data = self._request('app_linking_identity')
        user = data.get('user')
        if (set(data) != {'user', 'scope'} or data.get('scope') != self._scope
                or not isinstance(user, dict) or set(user) != {'id', 'username'}):
            raise LinkingError('Identity response was not accepted.')
        user_id, username = user['id'], user['username']
        if (type(user_id) is not int or user_id <= 0
                or not isinstance(username, str) or not username
                or len(username) > LINKING_USERNAME_MAX_CHARS
                or any(ord(char) < 32 or ord(char) == 127 for char in username)):
            raise LinkingError('Identity response was not accepted.')
        with self._lock:
            if self._closed or time.monotonic() >= self._expires_at:
                raise LinkingError('Linking session is unavailable.')
            if (self._bound_user_id is not None
                    and self._bound_user_id != user_id):
                raise LinkingError('Linked account identity changed.')
            self._bound_user_id = user_id
            return LinkedIdentity(user_id, username)

    def _verified_user_id(self) -> int:
        """Return the account identity already bound to this live session.

        Returns:
            int: Positive identity verified by the installation's identity route.

        Raises:
            LinkingError: Admin access is unavailable or identity is not verified.
        """
        with self._lock:
            bound = self._bound_user_id
            valid = (not self._closed
                     and (self._renew is not None
                          or time.monotonic() < self._expires_at)
                     and scope_allows_admin_media(self._scope))
        if not valid:
            raise LinkingError('Admin media permission is unavailable.')
        if bound is None:
            raise LinkingError('Admin account identity is not verified.')
        return bound

    def revoke(self) -> None:
        """Request revocation and discard the token after a valid acknowledgement.

        Returns:
            None: The trusted server accepted revocation or token absence.

        Raises:
            LinkingError: The response is missing, ambiguous or invalid.
        """
        self._request('app_linking_revoke')

    def close(self) -> None:
        """Forget the local token without asserting remote revocation.

        Returns:
            None: Prevents further use and discards the owned token reference.
        """
        self._cancelled.set()
        self._media_connection_pool.discard(close=True)
        with self._lock:
            for pending in self._pending_gallery_cancels:
                pending.set()
            self._closed = True
            self._token = ''
            self._gallery_edit_snapshot = None
            self._visibility_snapshot = None
            self._private_preload_generation += 1
            self._recent_media_pages.clear()
            self._recent_media_orders.clear()
            self._public_media_descriptors.clear()
            self._public_unverified_confirmations.clear()
            self._private_preload_proofs.clear()
