# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_photo_model.py
# Module Type: Qt Photos model
# Purpose: Present stable photo identities with viewport-bounded textures.
# Responsibilities: Update exact model rows and provide already decoded images.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Keep logical catalogue rows independent of scene nodes and image storage."""

from threading import Lock

from PySide6.QtCore import QAbstractListModel, QByteArray, QModelIndex, Property, QSize, Qt, Signal
from PySide6.QtGui import QImage
from PySide6.QtQuick import QQuickImageProvider

from .qt_photo_source import PhotoRow

_TITLE_ROLE = int(Qt.ItemDataRole.UserRole) + 1
_TEXTURE_ROLE = _TITLE_ROLE + 1
_SELECTED_ROLE = _TEXTURE_ROLE + 1
_LOADING_ROLE = _SELECTED_ROLE + 1


class PhotoTextures(QQuickImageProvider):
    """Supply owned decoded images to the scene without disk or network I/O."""

    def __init__(self) -> None:
        """Create an empty texture source owned by the QML engine.

        Returns:
            None: Initializes guarded slots for current viewport rasters.
        """
        super().__init__(QQuickImageProvider.ImageType.Image)
        self._images: dict[str, QImage] = {}
        self._lock = Lock()

    def store(self, key: str, image: QImage) -> str:
        """Publish a decoded frame before its source URL appears in the model.

        Args:
            key (str): Unique local texture key containing no remote authority.
            image (QImage): Immutable implicitly shared raster.
        Returns:
            str: Image provider URL for the currently owned image.
        """
        with self._lock:
            self._images[key] = image
        return "image://photos/" + key

    def discard(self, key: str) -> None:
        """Withdraw a slot after its URL leaves the catalogue or lightbox.

        Args:
            key (str): Current or recently replaced local texture identity.
        Returns:
            None: Drops the provider's reference without editing pixels.
        """
        with self._lock:
            self._images.pop(key, None)

    def requestImage(self, image_id: str, size: QSize, _requested_size: QSize) -> QImage:
        """Resolve a provider key without decoding, resampling or opening files.

        Args:
            image_id (str): Key passed from the owned QML image model.
            size (QSize): Qt's output geometry holder.
            _requested_size (QSize): Requested size, already constrained upstream.
        Returns:
            QImage: Shared immutable pixels, or a null image for a retired key.
        """
        with self._lock:
            image = self._images.get(image_id)
            if image is None:
                return QImage()
            if size is not None:
                size.setWidth(image.width())
                size.setHeight(image.height())
            return QImage(image)


class PhotoListModel(QAbstractListModel):
    """Project photo identity, selection, texture and actual thumbnail activity."""

    rows: tuple[PhotoRow, ...] = ()
    loadingChanged = Signal()

    def __init__(self) -> None:
        """Start with an empty catalogue and selection.

        Returns:
            None: Creates the GUI-thread model.
        """
        super().__init__()
        self.index_by_id: dict[int, int] = {}
        self.texture_urls: dict[int, str] = {}
        self.selected: set[int] = set()
        self.loading_ids: set[int] = set()

    @Property(bool, notify=loadingChanged)
    def thumbnailsLoading(self) -> bool:
        """Expose shared animation demand from the current set of photos.

        Returns:
            bool: At least one visible photo is actually being loaded.
        """
        return bool(self.loading_ids)

    def roleNames(self) -> dict[int, QByteArray]:
        """Describe roles queried by one reusable Qt Quick grid delegate.

        Returns:
            dict[int, QByteArray]: Qt roles for title, source, selection and loading.
        """
        return {
            _TITLE_ROLE: QByteArray(b"photoTitle"),
            _TEXTURE_ROLE: QByteArray(b"textureUrl"),
            _SELECTED_ROLE: QByteArray(b"photoSelected"),
            _LOADING_ROLE: QByteArray(b"photoLoading"),
        }

    def rowCount(self, parent: QModelIndex = QModelIndex()) -> int:
        """Expose the complete flat catalogue without creating item widgets.

        Args:
            parent (QModelIndex): Root or unsupported hierarchical parent.
        Returns:
            int: Number of logical catalogue rows under the root.
        """
        return 0 if parent.isValid() else len(self.rows)

    def data(self, index: QModelIndex, role: int = Qt.ItemDataRole.DisplayRole) -> str | bool | None:
        """Return one row's presentation role.

        Args:
            index (QModelIndex): Current Qt index for a single photo.
            role (int): Display, title, image URL, selection or loading role number.
        Returns:
            str | bool | None: Requested value or no value for an invalid role.
        """
        if not index.isValid() or not 0 <= index.row() < len(self.rows):
            return None
        row = self.rows[index.row()]
        if role in (_TITLE_ROLE, Qt.ItemDataRole.DisplayRole):
            return row.title
        if role == _TEXTURE_ROLE:
            return self.texture_urls.get(row.identity, "")
        if role == _SELECTED_ROLE:
            return row.identity in self.selected
        if role == _LOADING_ROLE:
            return row.identity in self.loading_ids
        return None

    def replace(
        self, rows: tuple[PhotoRow, ...], *, preserve: bool = False,
        selection: frozenset[int] | None = None,
    ) -> None:
        """Install a new immutable ordering and keep selection by known ID.

        Args:
            rows (tuple[PhotoRow, ...]): Complete, uniquely keyed catalogue.
            preserve (bool): Retain identities on an order change of the same view.
            selection (frozenset[int] | None): Previous selections to restore
                within the reset, or None to follow the preserve option.
        Returns:
            None: Qt resets logical indices and recycles visible delegates.
        """
        was_loading = bool(self.loading_ids)
        self.beginResetModel()
        self.rows = rows
        self.index_by_id = {row.identity: index for index, row in enumerate(rows)}
        if selection is not None:
            self.selected = set(selection).intersection(self.index_by_id)
            self.texture_urls.clear()
            self.loading_ids.clear()
        elif preserve:
            self.selected.intersection_update(self.index_by_id)
            self.loading_ids.intersection_update(self.index_by_id)
        else:
            self.selected.clear()
            self.texture_urls.clear()
            self.loading_ids.clear()
        self.endResetModel()
        if was_loading != bool(self.loading_ids):
            self.loadingChanged.emit()

    def texture(self, identity: int, url: str) -> None:
        """Update an individual live scene slot or remove its source.

        Args:
            identity (int): Current catalogue photo ID.
            url (str): Owned image provider URL or empty string to release it.
        Returns:
            None: Emits one exact role update when its URL changes.
        """
        self.update_textures({identity: url})

    def update_textures(self, changes: dict[int, str]) -> None:
        """Publish changed texture roles as exact contiguous model ranges.

        Args:
            changes (dict[int, str]): Current photo IDs mapped to provider
                URLs, with an empty URL removing a departed tile.
        Returns:
            None: Slots reflect the entire coalesced viewport before QML is
                notified. An unchanged row emits no role update.
        """
        changed_rows = []
        for identity, url in changes.items():
            index = self.index_by_id.get(identity)
            if index is None or self.texture_urls.get(identity, "") == url:
                continue
            if url:
                self.texture_urls[identity] = url
            else:
                self.texture_urls.pop(identity, None)
            changed_rows.append(index)
        self._notify_rows(changed_rows, _TEXTURE_ROLE)

    def set_loading(self, identities: set[int]) -> None:
        """Animate exactly the current rows admitted by the thumbnail owner.

        Args:
            identities (set[int]): Visible photo IDs with active work.
        Returns:
            None: Changes the loading role and updates shared animation demand.
        """
        wanted = identities.intersection(self.index_by_id)
        changed = self.loading_ids.symmetric_difference(wanted)
        was_loading = bool(self.loading_ids)
        self.loading_ids = wanted
        self._notify_rows([self.index_by_id[identity] for identity in changed], _LOADING_ROLE)
        if was_loading != bool(wanted):
            self.loadingChanged.emit()

    def _notify_rows(self, changed_rows: list[int], role: int) -> None:
        """Notify the scene of exact contiguous model ranges for one role.

        Args:
            changed_rows (list[int]): Current indices whose value changed.
            role (int): Qt model role to invalidate on those indices.
        Returns:
            None: Leaves other roles and noncontiguous rows intact.
        """
        if not changed_rows:
            return
        changed_rows.sort()
        first = last = changed_rows[0]
        for row in changed_rows[1:]:
            if row != last + 1:
                self.dataChanged.emit(self.index(first, 0), self.index(last, 0), [role])
                first = row
            last = row
        self.dataChanged.emit(self.index(first, 0), self.index(last, 0), [role])

    def select(self, identities: set[int]) -> None:
        """Apply stable selection and notify only changed QModelIndex roles.

        Args:
            identities (set[int]): Exact IDs from click or keyboard selection.
        Returns:
            None: Current item roles reflect the selected identity set.
        """
        wanted = identities.intersection(self.index_by_id)
        changed = self.selected.symmetric_difference(wanted)
        self.selected = wanted
        for identity in changed:
            index = self.index(self.index_by_id[identity], 0)
            self.dataChanged.emit(index, index, [_SELECTED_ROLE])
