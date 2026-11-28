# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_folder_shelf.py
# Module Type: Qt Photos presentation
# Purpose: Present direct subgalleries with the shared folder artwork.
# Responsibilities: Virtualize the wrapping folder inventory and retain only
#   bounded, currently authorized cover results from painted rows.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Present child folders without mounting a widget or issuing I/O per item."""

from collections import OrderedDict
from html import escape
from threading import Event
from typing import Callable

from PySide6.QtCore import QAbstractListModel, QModelIndex, QPoint, QSize, Qt, QTimer, Signal
from PySide6.QtGui import QHideEvent, QImage, QPainter, QPixmap, QShowEvent
from PySide6.QtWidgets import QAbstractItemView, QListView, QStyleOptionViewItem, QStyledItemDelegate

from .linking import LinkedGallery, LinkingSession
from .gallery_folder_cover import cached_folder_cover, folder_cover_size, read_folder_cover
from .gallery_image_policy import MEDIA_VIEWPORT_COALESCE_MS
from .qt_read_lane import QtReadLane
from .ui_icons import FOLDER_COVER_INSETS, render_smooth_icon
from .ui_palette import PALETTE

# Purpose: Bound retained cover memory across nearby scroll positions.
# Type: int. Units: raster results. Scope: one current folder shelf.
# Consumers: FolderShelfModel.publish and QtFolderShelf._painted_row.
# Rationale: 96 covers several screens of compact cards at common desktop sizes.
FOLDER_TEXTURE_LIMIT = 96
# Purpose: Keep a cover request interruptible while serving painted rows.
# Type: int. Units: folder IDs. Scope: one visible read group.
# Consumers: QtFolderShelf._request_covers.
# Rationale: Six bounds consecutive HTTP reads before current demand is revisited.
FOLDER_READ_GROUP = 6
# Purpose: Match the gap between adjacent folder cards in the Tk shelf.
# Type: int. Units: logical pixels. Scope: wrapping Qt folder cards.
# Consumers: QtFolderShelf.configure. Rationale: Six matches gallery_folders.py.
FOLDER_GAP = 6


def _folder_raster(size: tuple[int, int], density: float, cover: bytes | None) -> QImage:
    """Render matching folder artwork for either a placeholder or a live cover.

    Args:
        size (tuple[int, int]): Logical folder rim width and height.
        density (float): Device pixels per logical pixel on the owning screen.
        cover (bytes | None): Trusted normalized PNG or placeholder request.
    Returns:
        QImage: Independent raster with its display density, or an empty image.
    """
    image = render_smooth_icon('folder', size, dpi_scale=density, cover=cover)
    if image is None:
        return QImage()
    with image:
        native = QImage(
            image.tobytes(), image.width, image.height, QImage.Format.Format_RGBA8888,
        ).copy()
    native.setDevicePixelRatio(density)
    return native


class FolderShelfModel(QAbstractListModel):
    """Expose authorized direct children and bounded native decoration."""

    def __init__(self, parent: QListView) -> None:
        """Prepare one list model before the containing gallery is selected.

        Args:
            parent (QListView): Owning folder view.
        Returns:
            None: Initializes empty logical rows and shared folder artwork.
        """
        super().__init__(parent)
        self.rows: tuple[LinkedGallery, ...] = ()
        self.positions: dict[int, int] = {}
        self.covers: OrderedDict[int, QPixmap | None] = OrderedDict()
        self.placeholder = QPixmap()

    def rowCount(self, parent: QModelIndex = QModelIndex()) -> int:
        """Count direct children in the current gallery.

        Args:
            parent (QModelIndex): Invalid for the flat root list.
        Returns:
            int: Number of direct children, or zero below a list row.
        """
        return 0 if parent.isValid() else len(self.rows)

    def data(self, index: QModelIndex, role: int = Qt.ItemDataRole.DisplayRole) -> str | QPixmap | None:
        """Expose one row's safe label, full tooltip or shared artwork.

        Args:
            index (QModelIndex): Candidate direct-child row.
            role (int): Qt presentation role requested by the native item view.
        Returns:
            str | QPixmap | None: Title or trusted cover, or no value for an unsupported role.
        """
        if not index.isValid() or not 0 <= index.row() < len(self.rows):
            return None
        row = self.rows[index.row()]
        if role == Qt.ItemDataRole.DecorationRole:
            return self.covers.get(row.gallery_id) or self.placeholder
        if role in (Qt.ItemDataRole.DisplayRole, Qt.ItemDataRole.ToolTipRole):
            title = ''.join(char if char.isprintable() else ' ' for char in row.title)
            return title if role == Qt.ItemDataRole.DisplayRole else f'<qt>{escape(title)}</qt>'
        return None

    def replace(self, rows: tuple[LinkedGallery, ...], retain: bool) -> None:
        """Replace the authorized inventory while preserving matching covers if allowed.

        Args:
            rows (tuple[LinkedGallery, ...]): Current ordered direct children.
            retain (bool): True within the same gallery, grant and artwork geometry.
        Returns:
            None: Resets native list indices and evicts data for removed galleries.
        """
        self.beginResetModel()
        self.rows = rows
        self.positions = {gallery.gallery_id: index for index, gallery in enumerate(rows)}
        if retain:
            for gallery_id in tuple(self.covers):
                if gallery_id not in self.positions:
                    self.covers.pop(gallery_id)
        else:
            self.covers.clear()
        self.endResetModel()

    def publish(self, gallery_id: int, image: QImage | None) -> None:
        """Retain a current artwork result or a completed placeholder.

        Args:
            gallery_id (int): Exact authorized child whose artwork completed.
            image (QImage | None): Independent worker raster or exhausted read.
        Returns:
            None: Refreshes only the affected item and bounds retained results.
        """
        row = self.positions.get(gallery_id)
        if row is None:
            return
        self.covers[gallery_id] = QPixmap.fromImage(image) if image is not None else None
        self.covers.move_to_end(gallery_id)
        if len(self.covers) > FOLDER_TEXTURE_LIMIT:
            self.covers.popitem(last=False)
        index = self.index(row, 0)
        self.dataChanged.emit(index, index, [Qt.ItemDataRole.DecorationRole])


class FolderPaintDelegate(QStyledItemDelegate):
    """Report actual intersecting rows while preserving native item painting."""

    def __init__(self, parent: QListView, painted: Callable[[int], None]) -> None:
        """Route paint demand to the shelf without launching work from paint.

        Args:
            parent (QListView): Qt list that owns painting and keyboard selection.
            painted (Callable[[int], None]): Receives one painted logical row index.
        Returns:
            None: Mounts the native item delegate with a demand observer.
        """
        super().__init__(parent)
        self._painted = painted

    def paint(self, painter: QPainter, option: QStyleOptionViewItem, index: QModelIndex) -> None:
        """Paint the native tile and record its current viewport demand.

        Args:
            painter (QPainter): Active Qt viewport painter.
            option (QStyleOptionViewItem): Style and geometry of the actual tile.
            index (QModelIndex): Current logical direct-child row.
        Returns:
            None: Presents artwork and queues a coalesced read if necessary.
        """
        super().paint(painter, option, index)
        self._painted(index.row())


class QtFolderShelf(QListView):
    """Wrap stable folder IDs and cancel speculative reads outside the current pane."""

    # Python objects preserve every signed 64-bit gallery ID across native slots.
    gallery_activated = Signal(object)
    gallery_menu = Signal(object, QPoint)

    def __init__(self, caption: str) -> None:
        """Build a native wrapping view and an inactive cover lane.

        Args:
            caption (str): Localized accessible name of the shelf.
        Returns:
            None: The view is ready for a verified gallery and layout binding.
        """
        super().__init__()
        self.setObjectName('photosFolderShelf')
        self.setAccessibleName(caption)
        self.setViewMode(QListView.ViewMode.IconMode)
        self.setMovement(QListView.Movement.Static)
        self.setResizeMode(QListView.ResizeMode.Adjust)
        self.setWrapping(True)
        self.setUniformItemSizes(True)
        self.setLayoutMode(QListView.LayoutMode.Batched)
        self.setVerticalScrollMode(QAbstractItemView.ScrollMode.ScrollPerPixel)
        self.setHorizontalScrollBarPolicy(Qt.ScrollBarPolicy.ScrollBarAlwaysOff)
        self.setTextElideMode(Qt.TextElideMode.ElideRight)
        self.setContextMenuPolicy(Qt.ContextMenuPolicy.CustomContextMenu)
        self.setStyleSheet(
            f'QListView {{ border: 0; background: {PALETTE["card_surface"]}; }}'
            'QListView::item { border: 1px solid transparent; border-radius: 6px; }'
            f'QListView::item:hover {{ border-color: {PALETTE["line"]}; }}'
            f'QListView::item:selected {{ border-color: {PALETTE["focus"]}; '
            f'background: {PALETTE["surface_alt"]}; color: {PALETTE["ink"]}; }}'
        )
        self.cards = FolderShelfModel(self)
        self.setModel(self.cards)
        self.setItemDelegate(FolderPaintDelegate(self, self._painted_row))
        self._session: LinkingSession | None = None
        self._parent_id: int | None = None
        self._art: tuple[tuple[int, int], float] = ((0, 0), 0.0)
        self._wanted: dict[int, None] = {}
        self._reading: tuple[int, ...] = ()
        self._lane = QtReadLane[tuple[int, QImage | None]](self._receive, self._finished, self)
        self._timer = QTimer(self)
        self._timer.setSingleShot(True)
        self._timer.setInterval(MEDIA_VIEWPORT_COALESCE_MS)
        self._timer.timeout.connect(self._request_covers)
        self.verticalScrollBar().valueChanged.connect(self._cancel_reads)
        self.clicked.connect(self._activate)
        self.activated.connect(self._activate)
        self.customContextMenuRequested.connect(self._context_menu)

    def configure(
        self, session: LinkingSession | None, parent_id: int | None,
        rows: tuple[LinkedGallery, ...], card_format: str,
    ) -> None:
        """Bind the current gallery, supplied direct children and folder geometry.

        Args:
            session (LinkingSession | None): Live authorization for the inventory.
            parent_id (int | None): Current gallery's exact ID.
            rows (tuple[LinkedGallery, ...]): Ordered children in the authorized tree.
            card_format (str): Selected shared folder format key.
        Returns:
            None: Updates the visible cards and discards any superseded results.
        """
        if not rows:
            if self.cards.rows:
                self._cancel_reads()
                self.cards.replace((), False)
            self._session, self._parent_id = None, None
            return
        width, height = folder_cover_size(card_format)
        left, top, right, bottom = FOLDER_COVER_INSETS
        size = width + left + right, height + top + bottom
        density = self.devicePixelRatioF()
        art = size, density
        same_owner = session is self._session and parent_id == self._parent_id
        if same_owner and rows == self.cards.rows and art == self._art:
            return
        self._cancel_reads()
        if art != self._art:
            self.cards.placeholder = QPixmap.fromImage(_folder_raster(size, density, None))
            self.setIconSize(QSize(*size))
            # Allow Qt's font-dependent line box and native decoration margins.
            self.setGridSize(QSize(size[0] + FOLDER_GAP, size[1] + self.fontMetrics().height() + FOLDER_GAP))
        self.cards.replace(rows, same_owner and art == self._art)
        self._session, self._parent_id, self._art = session, parent_id, art
        if not same_owner:
            self.scrollToTop()
        self.viewport().update()

    def _activate(self, index: QModelIndex) -> None:
        """Route one current folder activation through normal tree navigation.

        Args:
            index (QModelIndex): Selected child card.
        Returns:
            None: Publishes the exact ID while the original grant is available.
        """
        if index.isValid() and self._session is not None and self._session.available():
            self.gallery_activated.emit(self.cards.rows[index.row()].gallery_id)

    def _context_menu(self, pos: QPoint) -> None:
        """Open the contextual gallery menu for the exact card under the pointer.

        Args:
            pos (QPoint): Viewport-local location supplied by Qt.
        Returns:
            None: Publishes an authorized gallery ID and global menu anchor.
        """
        index = self.indexAt(pos)
        if index.isValid() and self._session is not None and self._session.available():
            self.gallery_menu.emit(self.cards.rows[index.row()].gallery_id, self.viewport().mapToGlobal(pos))

    def _painted_row(self, row: int) -> None:
        """Coalesce cover demand from only rows painted by the item view.

        Args:
            row (int): Current native list item index.
        Returns:
            None: Schedules an asynchronous read when a cover is missing.
        """
        gallery_id = self.cards.rows[row].gallery_id
        if gallery_id in self.cards.covers:
            self.cards.covers.move_to_end(gallery_id)
        elif gallery_id not in self._reading and len(self._wanted) < FOLDER_TEXTURE_LIMIT:
            self._wanted[gallery_id] = None
            if not self._reading and not self._timer.isActive():
                self._timer.start()

    def _request_covers(self) -> None:
        """Read scoped cached hits first, then refresh one small painted group.

        Returns:
            None: Starts one serialized worker for the current visible gallery.
        """
        session = self._session
        if (not self.isVisible() or session is None or not session.can_read_media()
                or not session.available() or self._reading):
            return
        wanted = tuple(self._wanted)[:FOLDER_READ_GROUP]
        if not wanted:
            return
        for gallery_id in wanted:
            self._wanted.pop(gallery_id)
        self._reading = wanted
        size, density = self._art

        def load(cancelled: Event, publish: Callable[[tuple[int, QImage | None]], None]) -> None:
            """Deliver cache and current cover results from the same live grant.

            Args:
                cancelled (Event): Current viewport generation fence.
                publish (Callable[[tuple[int, QImage | None]], None]): Bounded result sink.
            Returns:
                None: Publishes at most one warm and one fresh result per ID.
            """
            warmed: dict[int, bytes] = {}
            for gallery_id in wanted:
                if cancelled.is_set():
                    return
                cover = cached_folder_cover(session, gallery_id, cancelled)
                if cover is not None:
                    warmed[gallery_id] = cover
                    publish((gallery_id, _folder_raster(size, density, cover)))
            for gallery_id in wanted:
                if cancelled.is_set():
                    return
                cover = read_folder_cover(session, gallery_id, cancelled)
                if gallery_id in warmed and cover == warmed[gallery_id]:
                    continue
                publish((gallery_id, _folder_raster(size, density, cover) if cover else None))

        self._lane.submit(load)

    def _receive(self, result: tuple[int, QImage | None]) -> None:
        """Apply one currently authorized cover on the GUI thread.

        Args:
            result (tuple[int, QImage | None]): Child ID and normalized artwork outcome.
        Returns:
            None: Updates only a row still owned by the live authorization.
        """
        if self.isVisible() and self._session is not None and self._session.available():
            self.cards.publish(*result)

    def _finished(self, failure: str) -> None:
        """Finish the group without repeatedly retrying a failed decode.

        Args:
            failure (str): Sanitized read error class, or empty on success.
        Returns:
            None: Advances the remaining current paint demand, if present.
        """
        completed, self._reading = self._reading, ()
        if failure:
            for gallery_id in completed:
                if gallery_id not in self.cards.covers:
                    self._receive((gallery_id, None))
        if self._wanted:
            self._timer.start()

    def _cancel_reads(self) -> None:
        """Withdraw pending demand while preserving bounded relevant artwork.

        Returns:
            None: Cancels speculative work for a hidden or superseded viewport.
        """
        self._lane.cancel()
        self._timer.stop()
        self._wanted.clear()
        self._reading = ()

    def hideEvent(self, event: QHideEvent) -> None:
        """Stop speculative cover transport when the parent pane is hidden.

        Args:
            event (QHideEvent): Qt visibility change for the actual shelf.
        Returns:
            None: Retains trusted local artwork while further reads are withdrawn.
        """
        self._cancel_reads()
        super().hideEvent(event)

    def showEvent(self, event: QShowEvent) -> None:
        """Resume demand from actual paint after the folder pane becomes visible.

        Args:
            event (QShowEvent): Qt visibility change for the actual shelf.
        Returns:
            None: Queues a viewport repaint rather than reading the full tree.
        """
        super().showEvent(event)
        self.viewport().update()

    def shutdown(self) -> None:
        """Close the speculative read lane when Photos is being destroyed.

        Returns:
            None: Prevents any cover publication into the retiring view.
        """
        self._cancel_reads()
        self._lane.close()
