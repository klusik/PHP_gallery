# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_catalogue_cache.py
# Module Type: Client Persistence Service
# Purpose: Retain bounded last-known gallery structure for one account.
# Responsibilities:
#   - Isolate snapshots by verified issuer, account and exact approved scope.
#   - Validate local integrity and every saved gallery descriptor.
#   - Protect Windows titles with user-scoped DPAPI and replace files atomically.
#   - Drain accepted local metadata independently of Tk and network teardown.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Last-known local gallery structure; saved rows never grant server access."""

import ctypes
from dataclasses import dataclass
import hashlib
import json
import logging
import os
from pathlib import Path
import re
import stat
import sys
import tempfile
import threading

from .app_linking import (
    LINKING_GALLERY_ID_MAX, LINKING_GALLERY_TITLE_MAX_CHARS, LinkedGallery,
)
from .models import JsonValue
from .windows_file_identity import (
    LOAD_LIBRARY_SEARCH_SYSTEM32, assert_local_windows_profile,
)


# Purpose: Bound the retained known catalogue independently of network pages.
# Type: int. Units: gallery rows. Scope: one verified account/scope snapshot.
# Consumers: GalleryCatalogueCache._validate_galleries.
# Rationale: 10,000 matches the optional tree protocol and existing preferences.
CATALOGUE_CACHE_MAX_ROWS = 10_000

# Purpose: Bound reads, decryption and JSON allocation for one local snapshot.
# Type: int. Units: bytes. Scope: encoded or protected snapshot.
# Consumers: GalleryCatalogueCache.load, save and _windows_catalogue_data.
# Rationale: Eight MiB accommodates a four-MiB tree plus local integrity and
# DPAPI framing while refusing arbitrary profile-file growth.
CATALOGUE_CACHE_MAX_BYTES = 8 * 1024 * 1024

# Purpose: Bound local identity components before hashing their cache namespace.
# Type: int. Units: UTF-8 bytes. Scope: issuer or approved-scope input.
# Consumers: GalleryCatalogueCache.for_current_user.
# Rationale: 4,096 exceeds existing issuer/scope bounds without accepting
# unbounded local constructor data.
CATALOGUE_BINDING_COMPONENT_MAX_BYTES = 4096

# These fixed format tags distinguish protected Windows data from private
# POSIX files. They are not filenames, credentials or negotiated wire versions.
_WINDOWS_MAGIC = b'PGCT1W\0'
_POSIX_MAGIC = b'PGCT1P\0'
_DIGEST_PATTERN = re.compile(r'[a-f0-9]{64}')
# Win32 CRYPTPROTECT_UI_FORBIDDEN prevents native prompts in a cache worker.
_CRYPTPROTECT_UI_FORBIDDEN = 0x1


@dataclass(frozen=True)
class GalleryCatalogueSnapshot:
    """Hold known gallery descriptors and an optional complete-tree revision.

    Args:
        galleries (tuple[LinkedGallery, ...]): ID-ordered known identities,
            including prior rows not yet confirmed by a current partial pass.
        revision (str | None): Server revision only for a complete tree-v1
            result; None for partial or completed paged observations.
    """

    galleries: tuple[LinkedGallery, ...]
    revision: str | None = None


class _DataBlob(ctypes.Structure):
    """Describe the native DPAPI byte count and owned data pointer."""

    _fields_ = [
        ('cbData', ctypes.c_uint32),
        ('pbData', ctypes.POINTER(ctypes.c_ubyte)),
    ]


def _windows_catalogue_data(
    body: bytes, entropy: bytes, *, protect: bool,
) -> bytes:
    """Protect or unprotect one bounded snapshot in the current Windows user.

    Args:
        body (bytes): Bounded plaintext JSON or protected DPAPI blob.
        entropy (bytes): Exact non-secret namespace digest used in both calls.
        protect (bool): True to seal; False to recover a prior sealed snapshot.

    Returns:
        bytes: Complete protected bytes or recovered plaintext.

    Raises:
        OSError: Native protection is unavailable, refused or exceeds bounds.
    """
    if not 0 < len(body) <= CATALOGUE_CACHE_MAX_BYTES:
        raise OSError('Catalogue protection input exceeded its bound.')
    library = ctypes.WinDLL(
        'crypt32.dll', use_last_error=True,
        winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
    )
    kernel = ctypes.WinDLL(
        'kernel32.dll', use_last_error=True,
        winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
    )
    transform = (
        library.CryptProtectData if protect else library.CryptUnprotectData
    )
    transform.argtypes = [
        ctypes.POINTER(_DataBlob),
        ctypes.c_wchar_p if protect else ctypes.POINTER(ctypes.c_void_p),
        ctypes.POINTER(_DataBlob), ctypes.c_void_p, ctypes.c_void_p,
        ctypes.c_uint32, ctypes.POINTER(_DataBlob),
    ]
    transform.restype = ctypes.c_int
    kernel.LocalFree.argtypes = [ctypes.c_void_p]
    kernel.LocalFree.restype = ctypes.c_void_p
    body_buffer = ctypes.create_string_buffer(body)
    entropy_buffer = ctypes.create_string_buffer(entropy)
    incoming = _DataBlob(
        len(body), ctypes.cast(body_buffer, ctypes.POINTER(ctypes.c_ubyte)),
    )
    additional = _DataBlob(
        len(entropy),
        ctypes.cast(entropy_buffer, ctypes.POINTER(ctypes.c_ubyte)),
    )
    outgoing = _DataBlob()
    try:
        if not transform(
            ctypes.byref(incoming), None, ctypes.byref(additional),
            None, None, _CRYPTPROTECT_UI_FORBIDDEN, ctypes.byref(outgoing),
        ):
            raise OSError('Catalogue protection is unavailable.')
        if not 0 < outgoing.cbData <= CATALOGUE_CACHE_MAX_BYTES:
            raise OSError('Catalogue protection exceeded its bound.')
        return ctypes.string_at(outgoing.pbData, outgoing.cbData)
    finally:
        if outgoing.pbData:
            kernel.LocalFree(ctypes.cast(outgoing.pbData, ctypes.c_void_p))


def _catalogue_json_object(
    pairs: list[tuple[str, JsonValue]],
) -> dict[str, JsonValue]:
    """Reject duplicate members while decoding the local integrity envelope.

    Args:
        pairs (list[tuple[str, JsonValue]]): Ordered JSON member names and values.

    Returns:
        dict[str, JsonValue]: Members with unique names.

    Raises:
        ValueError: A JSON object repeats a member.
    """
    result: dict[str, JsonValue] = {}
    for name, value in pairs:
        if name in result:
            raise ValueError('Repeated catalogue member.')
        result[name] = value
    return result


class GalleryCatalogueCache:
    """Store known display metadata independently of current remote authority."""

    def __init__(self, directory: Path, binding: str) -> None:
        """Select one account's hash-only snapshot path without performing I/O.

        Args:
            directory (Path): Dedicated per-user local catalogue-cache directory.
            binding (str): Lowercase SHA-256 of issuer, account and exact scope.

        Returns:
            None: Retains no bearer, password, username or server URL.

        Raises:
            ValueError: The supplied namespace is not a canonical digest.
        """
        if (not isinstance(binding, str)
                or _DIGEST_PATTERN.fullmatch(binding) is None
                or not directory.is_absolute()):
            raise ValueError('Invalid catalogue cache binding.')
        self._directory = directory
        self._binding = binding
        self._path = directory / (binding + '.bin')

    @classmethod
    def for_current_user(
        cls, issuer: str, account_id: int, scope: str,
    ) -> 'GalleryCatalogueCache':
        """Select local storage for an already verified site/account/scope.

        Args:
            issuer (str): Canonical issuer confirmed by the linking manager.
            account_id (int): Positive server-verified account identity.
            scope (str): Exact current approved scope from the live session.

        Returns:
            GalleryCatalogueCache: Optional cache; unavailable storage is a miss.

        Raises:
            ValueError: A binding component is empty, oversized or malformed.
        """
        if (type(account_id) is not int or account_id <= 0
                or not isinstance(issuer, str) or not issuer
                or not isinstance(scope, str) or not scope
                or any(
                    len(value.encode('utf-8'))
                    > CATALOGUE_BINDING_COMPONENT_MAX_BYTES
                    for value in (issuer, scope)
                )):
            raise ValueError('Invalid catalogue cache identity.')
        material = json.dumps(
            [issuer, account_id, scope], separators=(',', ':'),
        ).encode('utf-8')
        binding = hashlib.sha256(material).hexdigest()
        root = (os.environ.get('LOCALAPPDATA') or os.environ.get('XDG_CACHE_HOME')
                or str(Path.home() / '.cache'))
        return cls(
            Path(root) / 'PHPGalleryUploader' / 'catalogue-cache', binding,
        )

    def load(
        self, cancelled: threading.Event | None = None,
    ) -> GalleryCatalogueSnapshot | None:
        """Read and validate last-known structure on a background worker.

        Args:
            cancelled (threading.Event | None): Current session cancellation.

        Returns:
            GalleryCatalogueSnapshot | None: Verified prior metadata or a miss;
                this does not verify current remote visibility or permissions.
        """
        if cancelled is not None and cancelled.is_set():
            return None
        descriptor: int | None = None
        try:
            self._directory.lstat()
            self._check_directory()
            named = self._path.lstat()
            self._check_file(named)
            if not 0 < named.st_size <= CATALOGUE_CACHE_MAX_BYTES:
                raise ValueError('Invalid catalogue cache size.')
            flags = os.O_RDONLY | getattr(os, 'O_BINARY', 0)
            flags |= getattr(os, 'O_NOFOLLOW', 0)
            descriptor = os.open(self._path, flags)
            opened = os.fstat(descriptor)
            self._check_file(opened)
            if not os.path.samestat(named, opened):
                raise ValueError('Catalogue identity changed.')
            with os.fdopen(descriptor, 'rb') as stream:
                descriptor = None
                data = stream.read(CATALOGUE_CACHE_MAX_BYTES + 1)
                if not os.path.samestat(self._path.lstat(), opened):
                    raise ValueError('Catalogue identity changed.')
            if len(data) > CATALOGUE_CACHE_MAX_BYTES:
                raise ValueError('Invalid catalogue cache size.')
            if os.name == 'nt' and data.startswith(_WINDOWS_MAGIC):
                data = _windows_catalogue_data(
                    data[len(_WINDOWS_MAGIC):],
                    self._binding.encode('ascii'), protect=False,
                )
            elif os.name != 'nt' and data.startswith(_POSIX_MAGIC):
                data = data[len(_POSIX_MAGIC):]
            else:
                raise ValueError('Unsupported catalogue protection.')
            snapshot = self._decode(data)
            return (None if cancelled is not None and cancelled.is_set()
                    else snapshot)
        except FileNotFoundError:
            return None
        except (
            OSError, ValueError, TypeError, AttributeError, RecursionError,
            ctypes.ArgumentError,
        ):
            logging.getLogger(__name__).warning(
                'Photos catalogue cache rejected; using live catalogue reads.',
            )
            return None
        finally:
            if descriptor is not None:
                os.close(descriptor)

    def save(
        self, snapshot: GalleryCatalogueSnapshot,
        cancelled: threading.Event | None = None,
    ) -> bool:
        """Atomically publish known structure from a serialized writer.

        Args:
            snapshot (GalleryCatalogueSnapshot): Immutable last-known metadata.
            cancelled (threading.Event | None): Captured session cancellation.

        Returns:
            bool: True after replacement; False on cancellation, unsafe storage
                or rejected data. The prior file survives failed preparation.
        """
        temporary: Path | None = None
        try:
            if cancelled is not None and cancelled.is_set():
                return False
            if len(snapshot.galleries) > CATALOGUE_CACHE_MAX_ROWS:
                return False
            galleries = self._validate_galleries([
                {'id': row.gallery_id, 'parent_id': row.parent_id,
                 'title': row.title}
                for row in snapshot.galleries
            ])
            if snapshot.revision is not None and (
                    not isinstance(snapshot.revision, str)
                    or _DIGEST_PATTERN.fullmatch(snapshot.revision) is None):
                return False
            payload: dict[str, JsonValue] = {
                'version': 1, 'binding': self._binding,
                'revision': snapshot.revision,
                'galleries': [
                    {'id': row.gallery_id, 'parent_id': row.parent_id,
                     'title': row.title} for row in galleries
                ],
            }
            data = self._canonical_json({
                'payload': payload,
                'sha256': hashlib.sha256(
                    self._canonical_json(payload),
                ).hexdigest(),
            })
            if len(data) > CATALOGUE_CACHE_MAX_BYTES:
                return False
            if os.name == 'nt':
                data = _WINDOWS_MAGIC + _windows_catalogue_data(
                    data, self._binding.encode('ascii'), protect=True,
                )
            else:
                data = _POSIX_MAGIC + data
            if len(data) > CATALOGUE_CACHE_MAX_BYTES:
                return False
            self._ensure_directory()
            descriptor, name = tempfile.mkstemp(
                prefix='.catalogue-', suffix='.tmp', dir=self._directory,
            )
            temporary = Path(name)
            with os.fdopen(descriptor, 'wb') as stream:
                stream.write(data)
                stream.flush()
                os.fsync(stream.fileno())
            self._check_directory()
            try:
                self._check_file(self._path.lstat())
            except FileNotFoundError:
                pass
            if cancelled is not None and cancelled.is_set():
                return False
            os.replace(temporary, self._path)
            temporary = None
            return True
        except (
            OSError, ValueError, TypeError, AttributeError, RecursionError,
            ctypes.ArgumentError,
        ):
            logging.getLogger(__name__).warning(
                'Photos catalogue cache could not be saved; prior snapshot retained.',
            )
            return False
        finally:
            if temporary is not None:
                try:
                    self._check_directory()
                    temporary.unlink(missing_ok=True)
                except OSError:
                    pass

    @staticmethod
    def _canonical_json(payload: dict[str, JsonValue]) -> bytes:
        """Encode deterministic UTF-8 bytes for the local integrity digest.

        Args:
            payload (dict[str, JsonValue]): Strict snapshot or integrity envelope.

        Returns:
            bytes: Canonical JSON bytes.
        """
        return json.dumps(
            payload, ensure_ascii=False, sort_keys=True,
            separators=(',', ':'), allow_nan=False,
        ).encode('utf-8')

    def _decode(self, data: bytes) -> GalleryCatalogueSnapshot:
        """Validate a protected payload, binding and known descriptor set.

        Args:
            data (bytes): Bounded recovered JSON bytes.

        Returns:
            GalleryCatalogueSnapshot: Validated last-known display metadata.

        Raises:
            ValueError: The envelope, integrity or any descriptor is invalid.
        """
        document = json.loads(data, object_pairs_hook=_catalogue_json_object)
        if (not isinstance(document, dict)
                or set(document) != {'payload', 'sha256'}
                or not isinstance(document['payload'], dict)
                or not isinstance(document['sha256'], str)):
            raise ValueError('Invalid catalogue cache envelope.')
        payload = document['payload']
        if (set(payload) != {'version', 'binding', 'revision', 'galleries'}
                or type(payload['version']) is not int
                or payload['version'] != 1
                or payload['binding'] != self._binding
                or _DIGEST_PATTERN.fullmatch(document['sha256']) is None
                or hashlib.sha256(
                    self._canonical_json(payload),
                ).hexdigest() != document['sha256']):
            raise ValueError('Invalid catalogue cache integrity.')
        revision = payload['revision']
        if (revision is not None and (
                not isinstance(revision, str)
                or _DIGEST_PATTERN.fullmatch(revision) is None)):
            raise ValueError('Invalid catalogue cache revision.')
        return GalleryCatalogueSnapshot(
            self._validate_galleries(payload['galleries']), revision,
        )

    @staticmethod
    def _validate_galleries(rows: JsonValue) -> tuple[LinkedGallery, ...]:
        """Apply the existing wire descriptor bounds to saved display rows.

        Args:
            rows (JsonValue): Candidate list of id, parent_id and title objects.

        Returns:
            tuple[LinkedGallery, ...]: Strictly ordered known descriptors.

        Raises:
            ValueError: Count, shape, identity, order or title bounds fail.
        """
        if not isinstance(rows, list) or len(rows) > CATALOGUE_CACHE_MAX_ROWS:
            raise ValueError('Invalid catalogue row count.')
        galleries: list[LinkedGallery] = []
        previous = 0
        for row in rows:
            if not isinstance(row, dict) or set(row) != {'id', 'parent_id', 'title'}:
                raise ValueError('Invalid catalogue descriptor.')
            gallery_id, parent, title = row['id'], row['parent_id'], row['title']
            if (type(gallery_id) is not int
                    or not previous < gallery_id <= LINKING_GALLERY_ID_MAX
                    or (parent is not None and (
                        type(parent) is not int
                        or not 0 < parent <= LINKING_GALLERY_ID_MAX
                    ))
                    or not isinstance(title, str)
                    or len(title) > LINKING_GALLERY_TITLE_MAX_CHARS
                    or any(0xd800 <= ord(char) <= 0xdfff for char in title)):
                raise ValueError('Invalid catalogue descriptor.')
            galleries.append(LinkedGallery(gallery_id, parent, title))
            previous = gallery_id
        return tuple(galleries)

    def _ensure_directory(self) -> None:
        """Create the dedicated private leaf after checking existing ancestors.

        Returns:
            None: The verified directory is ready for one atomic replacement.

        Raises:
            OSError: A parent is redirected or the private leaf is unavailable.
        """
        for path in (self._directory, *self._directory.parents):
            try:
                information = path.lstat()
            except FileNotFoundError:
                continue
            if (not stat.S_ISDIR(information.st_mode)
                    or self._is_reparse(information)):
                raise OSError('Catalogue directory is redirected.')
        self._directory.mkdir(mode=0o700, parents=True, exist_ok=True)
        self._check_directory()

    def _check_directory(self) -> None:
        """Require local, non-redirected storage and a private POSIX leaf.

        Returns:
            None: Storage can be read without following a redirected profile.

        Raises:
            OSError: Directory locality, ancestry or private ownership fails.
        """
        if os.name == 'nt':
            assert_local_windows_profile(self._directory)
            return
        if sys.platform == 'darwin':
            from .macos_mode import assert_local_macos_profile
            assert_local_macos_profile(self._directory)
        for path in (self._directory, *self._directory.parents):
            information = path.lstat()
            if (not stat.S_ISDIR(information.st_mode)
                    or self._is_reparse(information)):
                raise OSError('Catalogue directory is redirected.')
        leaf = self._directory.lstat()
        if leaf.st_uid != os.getuid() or leaf.st_mode & 0o077:
            raise OSError('Catalogue directory is not private.')

    @staticmethod
    def _check_file(information: os.stat_result) -> None:
        """Reject redirected or non-private snapshot file identities.

        Args:
            information (os.stat_result): No-follow or opened-file metadata.

        Returns:
            None: The file is regular and privately readable in this platform.

        Raises:
            OSError: The entry is redirected, special or owned by another user.
        """
        if (not stat.S_ISREG(information.st_mode)
                or GalleryCatalogueCache._is_reparse(information)
                or (os.name != 'nt' and (
                    information.st_uid != os.getuid()
                    or information.st_mode & 0o077
                ))):
            raise OSError('Catalogue file is unsafe.')

    @staticmethod
    def _is_reparse(information: os.stat_result) -> bool:
        """Recognize symlinks and native Windows reparse metadata.

        Args:
            information (os.stat_result): Entry metadata obtained without follows.

        Returns:
            bool: True when the entry can redirect path resolution.
        """
        return (stat.S_ISLNK(information.st_mode)
                or bool(getattr(information, 'st_file_attributes', 0)
                        & getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 0x0400)))


class GalleryCatalogueWriter:
    """Drain accepted display metadata without retaining any Tk objects."""

    def __init__(self) -> None:
        """Prepare one serialized writer and one newest pending snapshot slot.

        Returns:
            None: No thread or file access starts until metadata is submitted.
        """
        self._lock = threading.Lock()
        self._worker: threading.Thread | None = None
        self._pending: tuple[
            GalleryCatalogueCache, GalleryCatalogueSnapshot,
        ] | None = None

    def submit(
        self, cache: GalleryCatalogueCache, snapshot: GalleryCatalogueSnapshot,
    ) -> None:
        """Accept immutable metadata for an already verified cache binding.

        Args:
            cache (GalleryCatalogueCache): Original account/scope-bound storage.
            snapshot (GalleryCatalogueSnapshot): Known navigation hints accepted
                by the UI while its originating session was current.
        Returns:
            None: One local writer drains the latest accepted metadata even
                after the originating UI closes, without accessing Tk or HTTP.
        """
        with self._lock:
            if self._worker is not None:
                self._pending = (cache, snapshot)
                return
            self._worker = threading.Thread(
                target=self._write,
                args=(cache, snapshot),
                name='photos-catalogue-cache',
                # Normal process exit must finish accepted local metadata;
                # network cancellation cannot erase already visible galleries.
                daemon=False,
            )
            try:
                self._worker.start()
            except RuntimeError:
                self._worker = None

    def _write(
        self, cache: GalleryCatalogueCache, snapshot: GalleryCatalogueSnapshot,
    ) -> None:
        """Save accepted snapshots serially, retaining only the newest wait.

        Args:
            cache (GalleryCatalogueCache): Bound storage for the first write.
            snapshot (GalleryCatalogueSnapshot): First immutable accepted tree.
        Returns:
            None: Atomic saves drain without retaining the UI or its session.
        """
        current: tuple[GalleryCatalogueCache, GalleryCatalogueSnapshot] | None = (
            cache, snapshot,
        )
        while current is not None:
            current_cache, current_snapshot = current
            current_cache.save(current_snapshot)
            with self._lock:
                current = self._pending
                self._pending = None
                if current is None:
                    self._worker = None
