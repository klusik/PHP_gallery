# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_folders.py
# Module Type: Windows Client UI
# Purpose: Show direct subgalleries with their first authorized photo preview.
# Responsibilities:
#   - Render and scroll folder cards inside the selected gallery.
#   - Resolve visible covers one at a time without blocking Tk or persisting data.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Inline folder cards for the current server-backed gallery."""

import io
import queue
import threading
import tkinter as tk
from tkinter import ttk
from typing import Callable

from .app_linking import LinkedGallery, LinkingSession
from .ui_theme import _PALETTE, _attach_tooltip

try:
    from PIL import Image, ImageDraw, ImageOps, ImageTk
except ImportError:
    Image = ImageDraw = ImageOps = ImageTk = None


# Purpose: Fit a complete folder preview and title above the photo grid.
# Type: tuple[int, int]. Units: pixels. Scope: one folder card image.
# Consumers: GalleryFolderShelf._folder_icon.
# Rationale: 112 by 68 leaves room for several folders at minimum window width.
FOLDER_IMAGE_SIZE = (112, 68)

# Purpose: Leave height for the label and button padding beneath the artwork.
# Type: int. Units: pixels. Scope: one horizontal folder strip.
# Consumers: GalleryFolderShelf._build_widgets.
# Rationale: 104 keeps this strip to one compact row at the narrow breakpoint.
FOLDER_STRIP_HEIGHT = 104

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
    """Keep one row of children and their best-effort covers in the photo pane."""

    def __init__(
        self, parent: tk.Misc, open_gallery: Callable[[int], None],
        context_menu: Callable[[int, tk.Event], str | None] | None = None,
    ) -> None:
        """Set up a scrollable folder strip and its cancellable cover owner.

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
        self._covers: dict[int, 'ImageTk.PhotoImage'] = {}
        self._resolved: set[int] = set()
        self._results: queue.Queue[tuple[object, int, bytes | None]] = queue.Queue()
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
        """Create the fixed-height canvas with its own horizontal scrollbar.

        Returns:
            None: Cards are presented on the strip's themed background.
        """
        self.columnconfigure(0, weight=1)
        self._canvas = tk.Canvas(
            self, height=FOLDER_STRIP_HEIGHT, highlightthickness=0,
            background=_PALETTE['card_surface'],
        )
        self._canvas.grid(row=0, column=0, sticky='ew')
        self._scrollbar = ttk.Scrollbar(
            self, orient='horizontal', command=self._scroll,
        )
        self._scrollbar.grid(row=1, column=0, sticky='ew')
        self._canvas.configure(xscrollcommand=self._scrollbar.set)
        self._cards = ttk.Frame(self._canvas, style='Card.TFrame')
        self._canvas.create_window((0, 0), window=self._cards, anchor='nw')
        self._cards.bind('<Configure>', self._viewport_changed, add='+')
        self._canvas.bind('<Configure>', self._viewport_changed, add='+')

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
            self._covers.clear()
            self._resolved.clear()
            self._canvas.xview_moveto(0)
        for gallery in children:
            if gallery.gallery_id in self._tiles:
                continue
            title = ''.join(char if char.isprintable() else ' ' for char in gallery.title)
            short = (title if len(title) <= FOLDER_TITLE_LIMIT
                     else title[:FOLDER_TITLE_LIMIT] + '…')
            tile = ttk.Button(
                self._cards, text=short, image=self._placeholder or '',
                compound='top', style='LinkedPhoto.TButton',
                command=lambda gallery_id=gallery.gallery_id: self._open_gallery(gallery_id),
            )
            tile.pack(side='left', padx=(0, 8), anchor='n')
            tile.bind('<Return>', lambda _event, button=tile: button.invoke())
            if self._context_menu is not None:
                for sequence in ('<Button-3>', '<Button-2>', '<Control-Button-1>'):
                    tile.bind(
                        sequence,
                        lambda event, gallery_id=gallery.gallery_id:
                            self._context_menu(gallery_id, event),
                    )
            tile.bind('<FocusIn>', lambda _event, button=tile: self._reveal(button), add='+')
            _attach_tooltip(tile, title)
            self._tiles[gallery.gallery_id] = tile
        self._schedule()

    def _folder_icon(self, cover: bytes | None) -> 'ImageTk.PhotoImage | None':
        """Paint the folder silhouette with an optional normalized preview.

        Args:
            cover (bytes | None): Small PNG from the cover worker, if available.

        Returns:
            ImageTk.PhotoImage | None: The themed image, or None without Pillow.
        """
        if Image is None or ImageDraw is None or ImageTk is None:
            return None
        artwork = Image.new('RGBA', FOLDER_IMAGE_SIZE)
        draw = ImageDraw.Draw(artwork)
        width, height = FOLDER_IMAGE_SIZE
        # Structural coordinates form the tab, body and four-pixel preview inset.
        fill = _PALETTE['card_surface_alt']
        draw.rounded_rectangle((1, 1, 42, 15), radius=5, fill=fill)
        draw.rounded_rectangle(
            (1, 8, width - 2, height - 2), radius=7,
            fill=fill, outline=_PALETTE['line'],
        )
        if cover is not None:
            with Image.open(io.BytesIO(cover)) as preview:
                artwork.paste(preview.convert('RGB'), (5, 12))
        return ImageTk.PhotoImage(artwork, master=self)

    def _viewport_changed(self, _event: tk.Event) -> None:
        """Update the strip geometry and queue newly visible covers.

        Args:
            _event (tk.Event): Tk viewport, card geometry or mapping event.

        Returns:
            None: Overflow is scrollable when the cards exceed the viewport.
        """
        width = self._cards.winfo_reqwidth()
        self._canvas.configure(scrollregion=(0, 0, width, FOLDER_STRIP_HEIGHT))
        if width > self._canvas.winfo_width():
            self._scrollbar.grid()
        else:
            self._scrollbar.grid_remove()
        self._schedule()

    def _scroll(self, *arguments: str) -> None:
        """Scroll the folder strip and schedule newly exposed cards.

        Args:
            *arguments (str): Tcl scrollbar command and scroll position fields.

        Returns:
            None: Only visible cards become candidates for cover requests.
        """
        self._canvas.xview(*arguments)
        self._schedule()

    def _reveal(self, tile: ttk.Button) -> None:
        """Scroll a focused card into the visible strip.

        Args:
            tile (ttk.Button): Folder card that received keyboard focus.

        Returns:
            None: The focused folder remains visible during keyboard navigation.
        """
        left = self._canvas.canvasx(0)
        right = left + self._canvas.winfo_width()
        x = tile.winfo_x()
        if x < left or x + tile.winfo_width() > right:
            self._canvas.xview_moveto(x / max(1, self._cards.winfo_reqwidth()))
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
                generation, gallery_id, cover = self._results.get_nowait()
            except queue.Empty:
                break
            if generation is not self._generation or not active:
                continue
            self._resolved.add(gallery_id)
            if cover is not None and (tile := self._tiles.get(gallery_id)) is not None:
                image = self._folder_icon(cover)
                if image is not None:
                    self._covers[gallery_id] = image
                    tile.configure(image=image)
        if self._worker is not None and self._worker.is_alive():
            self._schedule()
            return
        self._worker = None
        if not self._results.empty():
            self._schedule()
            return
        if not active or Image is None or not self._canvas.winfo_ismapped():
            return
        left = self._canvas.canvasx(0)
        right = left + self._canvas.winfo_width()
        for gallery_id, tile in self._tiles.items():
            if (gallery_id not in self._resolved
                    and tile.winfo_x() < right
                    and tile.winfo_x() + tile.winfo_width() > left):
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
                page = session.media_page(gallery_id, cancelled=cancelled)
                if page.photos and not cancelled.is_set():
                    photo = page.photos[0]
                    payload, _mime = session.media_photo(
                        gallery_id, photo.image_id, thumbnail=True,
                        cancelled=cancelled,
                        cache_eligible=photo.cache_eligible,
                        thumbnail_etag=photo.thumbnail_etag,
                        thumbnail_verified_at=photo.thumbnail_verified_at,
                    )
                    with Image.open(io.BytesIO(payload)) as image:
                        if image.width * image.height <= FOLDER_COVER_MAX_PIXELS:
                            width, height = FOLDER_IMAGE_SIZE
                            preview = ImageOps.fit(
                                ImageOps.exif_transpose(image).convert('RGB'),
                                (width - 10, height - 17),
                            )
                            output = io.BytesIO()
                            preview.save(output, format='PNG')
                            cover = output.getvalue()
            except (OSError, ValueError, RuntimeError, Image.DecompressionBombError):
                pass
            if not cancelled.is_set():
                self._results.put((generation, gallery_id, cover))

        self._worker = threading.Thread(target=fetch, name='PHPGalleryFolderCover', daemon=True)
        self._worker.start()
        self._schedule()

    def _on_unmap(self, _event: tk.Event) -> None:
        """Cancel cover work while the photo pane is hidden.

        Args:
            _event (tk.Event): Shelf unmap event, including opening the lightbox.

        Returns:
            None: The next mapping can start cover work with a fresh signal.
        """
        self._cancelled.set()
        self._cancelled = threading.Event()

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
        if self._poll_id is not None:
            self.after_cancel(self._poll_id)
