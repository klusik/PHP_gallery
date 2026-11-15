# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_folders.py
# Module Type: Windows Client UI
# Purpose: Show direct subgalleries with their first authorized photo preview.
# Responsibilities:
#   - Wrap folder cards and scroll vertically inside the selected gallery.
#   - Display verified cached covers before refreshing them in the background.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Inline folder cards for the current server-backed gallery."""

import io
import queue
import threading
import tkinter as tk
from tkinter import ttk
from typing import Callable

from .app_linking import LinkedGallery, LinkedMediaPage, LinkingError, LinkingSession
from .gallery_view_preferences import normalize_card_format
from .ui_theme import _PALETTE, _attach_tooltip, create_smooth_icon

try:
    from PIL import Image, ImageOps, ImageTk
except ImportError:
    Image = ImageOps = ImageTk = None


# Purpose: Fit a complete folder preview and title above the photo grid.
# Type: tuple[int, int]. Units: pixels. Scope: one folder card image.
# Consumers: GalleryFolderShelf._folder_icon.
# Rationale: 112 by 68 leaves room for several folders at minimum window width.
FOLDER_IMAGE_SIZE = (112, 68)

# Purpose: Preserve space for photos when the folder inventory is large.
# Type: float. Units: fraction of pane height. Scope: a mixed gallery shelf.
# Consumers: GalleryFolderShelf._viewport_changed.
# Rationale: 0.4 admits several folder rows without starving the photo grid.
FOLDER_PANE_FRACTION = 0.4

# Purpose: Keep folder cards compact and their cover retention bounded.
# Type: int. Units: pixels. Scope: explicit folder proportions and cover decode.
# Consumers: GalleryFolderShelf._image_size and _normalize_cover.
# Rationale: 112 retains the original folder width and bounds every crop edge.
FOLDER_FORMAT_EDGE = 112

# Purpose: Separate adjacent folder rims without stretching spare row width.
# Type: int. Units: Tk pixels. Scope: wrapped folder layout.
# Consumers: GalleryFolderShelf._viewport_changed.
# Rationale: 6 matches the compact spacing of explicit photo formats.
FOLDER_CARD_GAP = 6

# Purpose: Keep long server titles readable in a fixed folder tile.
# Type: int. Units: characters. Scope: the displayed title of one folder card.
# Consumers: GalleryFolderShelf.show_children.
# Rationale: 18 characters fit typical titles, with the full title on hover.
FOLDER_TITLE_LIMIT = 18

# Purpose: Deliver visible cover results without delaying foreground input.
# Type: int. Units: milliseconds. Scope: one scheduled folder-cover check.
# Consumers: GalleryFolderShelf._schedule.
# Rationale: 80 batches viewport changes while keeping cover feedback prompt.
FOLDER_POLL_MS = 80

# Purpose: Reject pathological raster dimensions before allocating cover pixels.
# Type: int. Units: decoded pixels. Scope: a server-authorized cover image.
# Consumers: GalleryFolderShelf._request_cover.
# Rationale: 36 megapixels matches the native photo viewer's decode limit.
FOLDER_COVER_MAX_PIXELS = 36_000_000


class GalleryFolderShelf(ttk.Frame):
    """Wrap authorized child folders with independent vertical navigation."""

    def __init__(
        self, parent: tk.Misc, open_gallery: Callable[[int], None],
        context_menu: Callable[[int, tk.Event], str | None] | None = None,
    ) -> None:
        """Set up a wrapping folder shelf and its cancellable cover owner.

        Args:
            parent (tk.Misc): The current Photos viewer pane.
            open_gallery (Callable[[int], None]): Navigate to an exact child ID.
            context_menu (Callable[[int, tk.Event], str | None] | None):
                Optional inline context action for the exact folder card.

        Returns:
            None: The strip is ready for the selected gallery's children.
        """
        super().__init__(parent, style='Card.TFrame')
        self._open_gallery = open_gallery
        self._context_menu = context_menu
        self._session: LinkingSession | None = None
        self._parent_id: int | None = None
        self._tiles: dict[int, ttk.Button] = {}
        self._titles: dict[int, tk.StringVar] = {}
        self._covers: dict[int, 'ImageTk.PhotoImage'] = {}
        self._cover_payloads: dict[int, bytes] = {}
        self._card_format = 'default'
        self._has_photos = True
        self._resolved: set[int] = set()
        self._results: queue.Queue[tuple[object, int, bytes | None, bool]] = queue.Queue()
        self._generation = object()
        self._cancelled = threading.Event()
        self._worker: threading.Thread | None = None
        self._poll_id: str | None = None
        self._closed = False
        self._build_widgets()
        self._placeholder = self._folder_icon(None)
        self.bind('<Destroy>', self._on_destroy, add='+')
        self.bind('<Map>', self._viewport_changed, add='+')
        self.bind('<Unmap>', self._on_unmap, add='+')

    def _build_widgets(self) -> None:
        """Create a width-constrained canvas with vertical overflow only.

        Returns:
            None: Cards are presented on the strip's themed background.
        """
        self.columnconfigure(0, weight=1)
        self._canvas = tk.Canvas(
            self, height=1, highlightthickness=0,
            background=_PALETTE['card_surface'],
        )
        self.rowconfigure(0, weight=1)
        self._canvas.grid(row=0, column=0, sticky='nsew')
        self._scrollbar = ttk.Scrollbar(
            self, orient='vertical', command=self._scroll,
        )
        self._canvas.configure(yscrollcommand=self._scrollbar.set)
        self._cards = ttk.Frame(self._canvas, style='Card.TFrame')
        self._window = self._canvas.create_window(
            (0, 0), window=self._cards, anchor='nw',
        )
        self._cards.bind('<Configure>', self._viewport_changed, add='+')
        self._canvas.bind('<Configure>', self._viewport_changed, add='+')
        self._parent_configure_binding = self.master.bind(
            '<Configure>', self._viewport_changed, add='+',
        )
        for widget in (self._canvas, self._cards):
            widget.bind('<MouseWheel>', self._wheel, add='+')
            widget.bind('<Button-4>', self._wheel, add='+')
            widget.bind('<Button-5>', self._wheel, add='+')

    def show_children(
        self, session: LinkingSession | None, parent_id: int | None,
        children: tuple[LinkedGallery, ...],
    ) -> None:
        """Replace the parent projection or append newly listed children.

        Args:
            session (LinkingSession | None): Exact selected live listing session.
            parent_id (int | None): Current physical gallery, or None to clear.
            children (tuple[LinkedGallery, ...]): Ordered direct server rows.

        Returns:
            None: Available cards appear immediately with folder placeholders.
        """
        if session is not self._session or parent_id != self._parent_id:
            self._cancelled.set()
            self._cancelled = threading.Event()
            self._generation = object()
            self._session, self._parent_id = session, parent_id
            for tile in self._tiles.values():
                tile.destroy()
            self._tiles.clear()
            self._titles.clear()
            self._covers.clear()
            self._cover_payloads.clear()
            self._resolved.clear()
            self._canvas.yview_moveto(0)
        child_ids = {gallery.gallery_id for gallery in children}
        for gallery_id in tuple(self._tiles):
            if gallery_id not in child_ids:
                self._tiles.pop(gallery_id).destroy()
                self._titles.pop(gallery_id, None)
                self._covers.pop(gallery_id, None)
                self._cover_payloads.pop(gallery_id, None)
                self._resolved.discard(gallery_id)
        for gallery in children:
            title = ''.join(char if char.isprintable() else ' ' for char in gallery.title)
            short = (title if len(title) <= FOLDER_TITLE_LIMIT
                     else title[:FOLDER_TITLE_LIMIT] + '…')
            if gallery.gallery_id in self._tiles:
                self._tiles[gallery.gallery_id].configure(text=short)
                self._titles[gallery.gallery_id].set(title)
                continue
            tile = ttk.Button(
                self._cards, text=short, image=self._placeholder or '',
                compound='top', style='LinkedPhoto.TButton',
                command=lambda gallery_id=gallery.gallery_id: self._open_gallery(gallery_id),
            )
            tile.grid(sticky='nw', padx=(0, FOLDER_CARD_GAP), pady=(0, FOLDER_CARD_GAP))
            for sequence in ('<MouseWheel>', '<Button-4>', '<Button-5>'):
                tile.bind(sequence, self._wheel, add='+')
            tile.bind('<Return>', lambda _event, button=tile: button.invoke())
            if self._context_menu is not None:
                for sequence in ('<Button-3>', '<Button-2>', '<Control-Button-1>'):
                    tile.bind(
                        sequence,
                        lambda event, gallery_id=gallery.gallery_id:
                            self._context_menu(gallery_id, event),
                    )
            tile.bind('<FocusIn>', lambda _event, button=tile: self._reveal(button), add='+')
            self._titles[gallery.gallery_id] = tk.StringVar(self, title)
            _attach_tooltip(tile, self._titles[gallery.gallery_id])
            self._tiles[gallery.gallery_id] = tile
        # Dict insertion order is the current local display order, including
        # a direction change without rebuilding widgets or their Tk images.
        self._tiles = {row.gallery_id: self._tiles[row.gallery_id] for row in children}
        self._viewport_changed(None)
        self._schedule()

    def set_view(self, card_format: str, has_photos: bool) -> None:
        """Apply local folder geometry without requesting its media again.

        Args:
            card_format (str): Supported folder ratio or default silhouette.
            has_photos (bool): Reserve photo space for mixed or pending albums.
        Returns:
            None: Existing decoded covers are repainted and rows reflow.
        """
        normalized = normalize_card_format(card_format)
        self._has_photos = has_photos
        if normalized != self._card_format:
            self._card_format = normalized
            self._placeholder = self._folder_icon(None)
            for gallery_id, tile in self._tiles.items():
                payload = self._cover_payloads.get(gallery_id)
                image = self._folder_icon(payload) if payload is not None else None
                if image is not None and payload is not None:
                    self._covers[gallery_id] = image
                tile.configure(image=image or self._placeholder or '')
        self._viewport_changed(None)

    def refresh_covers(self) -> None:
        """Recheck visible covers while keeping their current pixels displayed.

        Returns:
            None: Existing session/disk proofs lead the next background refresh.
        """
        self._resolved.clear()
        self._schedule()

    def _image_size(self) -> tuple[int, int]:
        """Return the bounded silhouette size for the selected folder ratio.

        Returns:
            tuple[int, int]: Width and height in logical Tk pixels.
        """
        if self._card_format == 'default':
            return FOLDER_IMAGE_SIZE
        width, height = (int(part) for part in self._card_format.split(':'))
        scale = FOLDER_FORMAT_EDGE / max(width, height)
        return max(1, round(width * scale)), max(1, round(height * scale))

    def _folder_icon(self, cover: bytes | None) -> 'ImageTk.PhotoImage | None':
        """Paint the folder silhouette with an optional normalized preview.

        Args:
            cover (bytes | None): Small PNG from the cover worker, if available.

        Returns:
            ImageTk.PhotoImage | None: The themed image, or None without Pillow.
        """
        if Image is None or ImageTk is None:
            return None
        return create_smooth_icon(
            self, "folder", self._image_size(), cover=cover,
        )

    def _viewport_changed(self, _event: tk.Event | None) -> None:
        """Wrap cards at the actual width and bound their vertical viewport.

        Args:
            _event (tk.Event | None): Geometry event, or explicit view change.

        Returns:
            None: Every card fits the width; overflow uses vertical scrolling.
        """
        width = max(1, self._canvas.winfo_width())
        tile_width = max((tile.winfo_reqwidth() for tile in self._tiles.values()), default=1)
        columns = max(1, width // (tile_width + FOLDER_CARD_GAP))
        for index, tile in enumerate(self._tiles.values()):
            tile.grid_configure(row=index // columns, column=index % columns)
        height = self._cards.winfo_reqheight()
        fraction = FOLDER_PANE_FRACTION if self._has_photos else 1.0
        # The folder-only shelf may use the remaining pane, while mixed albums
        # retain their photo viewport. Pack handles the remaining heading space.
        limit = max(1, round(self.master.winfo_height() * fraction))
        self._canvas.itemconfigure(self._window, width=width)
        self._canvas.configure(height=min(height, limit), scrollregion=(0, 0, width, height))
        if height > limit:
            self._scrollbar.grid(row=0, column=1, sticky='ns')
        else:
            self._scrollbar.grid_remove()
        self._release_hidden_covers()
        self._schedule()

    def _release_hidden_covers(self) -> None:
        """Keep decoded folder artwork within the mapped vertical viewport.

        Returns:
            None: Offscreen pixels are released; verified thumbnail disk entries
                remain under the shared cache owner's normal retention policy.
        """
        if not self._canvas.winfo_ismapped():
            return
        top = self._canvas.canvasy(0)
        bottom = top + self._canvas.winfo_height()
        for gallery_id in tuple(self._covers):
            tile = self._tiles.get(gallery_id)
            if (tile is not None and tile.winfo_y() < bottom
                    and tile.winfo_y() + tile.winfo_height() > top):
                continue
            if tile is not None:
                tile.configure(image=self._placeholder or '')
            self._covers.pop(gallery_id, None)
            self._cover_payloads.pop(gallery_id, None)
            self._resolved.discard(gallery_id)

    def _scroll(self, *arguments: str) -> None:
        """Scroll the folder strip and schedule newly exposed cards.

        Args:
            *arguments (str): Tcl scrollbar command and scroll position fields.

        Returns:
            None: Only visible cards become candidates for cover requests.
        """
        self._canvas.yview(*arguments)
        self._release_hidden_covers()
        self._schedule()

    def _wheel(self, event: tk.Event) -> str:
        """Move the folder viewport vertically using wheel or trackpad input.

        Args:
            event (tk.Event): Native wheel delta or X11 wheel-button event.
        Returns:
            str: Tk break marker preventing duplicate parent scrolling.
        """
        direction = -1 if event.num == 4 or event.delta > 0 else 1
        self._canvas.yview_scroll(direction, 'units')
        self._release_hidden_covers()
        self._schedule()
        return 'break'

    def _reveal(self, tile: ttk.Button) -> None:
        """Scroll a focused card into the visible strip.

        Args:
            tile (ttk.Button): Folder card that received keyboard focus.

        Returns:
            None: The focused folder remains visible during keyboard navigation.
        """
        top = self._canvas.canvasy(0)
        bottom = top + self._canvas.winfo_height()
        y = tile.winfo_y()
        if y < top or y + tile.winfo_height() > bottom:
            self._canvas.yview_moveto(y / max(1, self._cards.winfo_reqheight()))
            self._schedule()

    def _schedule(self) -> None:
        """Schedule a single cover worker or result poll.

        Returns:
            None: Repeated geometry events share one Tk callback.
        """
        if not self._closed and self._poll_id is None:
            self._poll_id = self.after(FOLDER_POLL_MS, self._poll)

    def _poll(self) -> None:
        """Apply current worker results and advance one visible cover.

        Returns:
            None: Idle or hidden shelves release their polling timer.
        """
        self._poll_id = None
        session = self._session
        active = session is not None and session.can_read_media()
        while True:
            try:
                generation, gallery_id, cover, final = self._results.get_nowait()
            except queue.Empty:
                break
            if generation is not self._generation or not active:
                continue
            if final:
                self._resolved.add(gallery_id)
            if cover is not None and (tile := self._tiles.get(gallery_id)) is not None:
                image = self._folder_icon(cover)
                if image is not None:
                    self._covers[gallery_id] = image
                    self._cover_payloads[gallery_id] = cover
                    tile.configure(image=image)
            elif final and gallery_id in self._tiles:
                self._covers.pop(gallery_id, None)
                self._cover_payloads.pop(gallery_id, None)
                self._tiles[gallery_id].configure(image=self._placeholder or '')
        self._release_hidden_covers()
        if self._worker is not None and self._worker.is_alive():
            self._schedule()
            return
        self._worker = None
        if not self._results.empty():
            self._schedule()
            return
        if not active or Image is None or not self._canvas.winfo_ismapped():
            return
        top = self._canvas.canvasy(0)
        bottom = top + self._canvas.winfo_height()
        for gallery_id, tile in self._tiles.items():
            if (gallery_id not in self._resolved
                    and tile.winfo_y() < bottom
                    and tile.winfo_y() + tile.winfo_height() > top):
                self._request_cover(session, gallery_id)
                return

    def _request_cover(self, session: LinkingSession, gallery_id: int) -> None:
        """Resolve one visible child’s first authorized photo in a worker.

        Args:
            session (LinkingSession): Exact active listing session for this shelf.
            gallery_id (int): Child gallery whose preview is requested.

        Returns:
            None: The worker queues a small PNG or leaves the folder placeholder.
        """
        generation, cancelled = self._generation, self._cancelled

        def fetch() -> None:
            """Fetch and normalize the first authorized direct photo.

            Returns:
                None: Puts the current cover outcome on the Tk result queue.
            """
            cover = None
            try:
                # The session owns descriptor freshness and disk authorization.
                # Never persist a second unscoped cover cache or trust saved IDs.
                recent = session.recent_media_page(gallery_id)
                if isinstance(recent, LinkedMediaPage) and recent.photos:
                    try:
                        cached = session.cached_media_photo(
                            gallery_id, recent.photos[0], cancelled=cancelled,
                        )
                    except LinkingError:
                        # The session owner may reject an aged proof. A fresh
                        # metadata read below must still get its normal chance.
                        cached = None
                    if cached is not None and not cancelled.is_set():
                        cover = self._normalize_cover(cached[0])
                        self._results.put((generation, gallery_id, cover, False))
                page = session.media_page(gallery_id, cancelled=cancelled)
                if page.photos and not cancelled.is_set():
                    photo = page.photos[0]
                    try:
                        cached = session.cached_media_photo(
                            gallery_id, photo, cancelled=cancelled,
                        )
                    except LinkingError:
                        cached = None
                    payload, _mime = cached if cached is not None else session.media_photo(
                        gallery_id, photo.image_id, thumbnail=True,
                        cancelled=cancelled,
                        cache_eligible=photo.cache_eligible,
                        thumbnail_etag=photo.thumbnail_etag,
                        thumbnail_verified_at=photo.thumbnail_verified_at,
                        preload_eligible=photo.preload_eligible,
                        preload_thumbnail_etag=photo.preload_thumbnail_etag,
                        preload_verified_at=photo.preload_verified_at,
                    )
                    cover = self._normalize_cover(payload)
                elif not cancelled.is_set():
                    cover = None
            except (OSError, ValueError, RuntimeError, Image.DecompressionBombError):
                pass
            if not cancelled.is_set():
                self._results.put((generation, gallery_id, cover, True))

        self._worker = threading.Thread(target=fetch, name='PHPGalleryFolderCover', daemon=True)
        self._worker.start()
        self._schedule()

    def _normalize_cover(self, payload: bytes) -> bytes | None:
        """Retain a small uncropped raster for all local folder proportions.

        Args:
            payload (bytes): Authorized encoded thumbnail from the session cache.
        Returns:
            bytes | None: Bounded PNG, or None for excessive raster dimensions.
        """
        with Image.open(io.BytesIO(payload)) as image:
            if image.width * image.height > FOLDER_COVER_MAX_PIXELS:
                return None
            # JPEG draft reduces camera-sized fallback renditions before RGB.
            image.draft('RGB', (FOLDER_FORMAT_EDGE, FOLDER_FORMAT_EDGE))
            with ImageOps.exif_transpose(image) as oriented:
                preview = ImageOps.contain(
                    oriented.convert('RGB'), (FOLDER_FORMAT_EDGE, FOLDER_FORMAT_EDGE),
                )
                with preview, io.BytesIO() as output:
                    preview.save(output, format='PNG')
                    return output.getvalue()

    def _on_unmap(self, _event: tk.Event) -> None:
        """Cancel cover work while the photo pane is hidden.

        Args:
            _event (tk.Event): Shelf unmap event, including opening the lightbox.

        Returns:
            None: The next mapping can start cover work with a fresh signal.
        """
        self._cancelled.set()
        self._cancelled = threading.Event()
        self._generation = object()

    def _on_destroy(self, event: tk.Event) -> None:
        """Release cover work when Tk destroys this shelf.

        Args:
            event (tk.Event): Tk destruction event for this frame.

        Returns:
            None: The last worker may finish but cannot update any widgets.
        """
        if event.widget is not self:
            return
        self._closed = True
        self._cancelled.set()
        self.master.unbind('<Configure>', self._parent_configure_binding)
        if self._poll_id is not None:
            self.after_cancel(self._poll_id)
