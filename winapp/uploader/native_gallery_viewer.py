# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/native_gallery_viewer.py
# Module Type: Windows Client UI
# Purpose: Display bearer-authorized gallery media in a bounded native window.
# Responsibilities:
#   - Fetch one bounded page and its thumbnails without calling Tk on workers.
#   - Render selected detail images and a manually started slideshow.
#   - Play explicitly selected MP3/WAV tracks through Windows winmm only.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Native, bounded viewer for media returned by a linked gallery session."""

import ctypes
import hashlib
import io
import os
import queue
import sys
import tempfile
import threading
import tkinter as tk
from tkinter import ttk
import uuid
from typing import Callable, TypeAlias

from .app_linking import (
    NATIVE_MEDIA_MAX_BYTES,
    NATIVE_MEDIA_PAGE_SIZE,
    LinkedGallery,
    LinkedMediaPage,
    LinkedPhoto,
    LinkedPresentation,
    LinkedTrack,
    LinkingSession,
)
from .flight_report_translations import tr

try:
    from PIL import Image, ImageTk
except ImportError:
    Image = None
    ImageTk = None


# Purpose: Keep the photo preview within the compact viewer's viewport.
# Type: tuple[int, int]. Units: pixels. Scope: one preview/detail image.
# Consumers: _prepare_image. Rationale: this size fits common laptop displays.
MEDIA_IMAGE_MAX_SIZE = (960, 640)

# Purpose: Keep visible media thumbnails compact while preserving detail.
# Type: tuple[int, int]. Units: pixels. Scope: one gallery photo row.
# Consumers: _NativeGalleryViewer._load_thumbnails.
# Rationale: 112 by 76 px fits beside a readable title in the fixed pane.
MEDIA_THUMBNAIL_SIZE = (112, 76)

# Purpose: Give each thumbnail row enough height to avoid clipping.
# Type: int. Units: pixels. Scope: the gallery Treeview row.
# Consumers: _NativeGalleryViewer._build_widgets.
# Rationale: 84 px leaves a small vertical rim around the 76 px thumbnail.
MEDIA_TREE_ROW_HEIGHT = 84

# Purpose: Reject pathological decoded images before allocating a full bitmap.
# Type: int. Units: pixels. Scope: one original or thumbnail response.
# Consumers: _NativeGalleryViewer._prepare_image.
# Rationale: 36 megapixels bounds decoded RGB memory while supporting cameras.
MEDIA_IMAGE_MAX_PIXELS = 36_000_000

# Purpose: Poll worker results without making the Tk event loop block.
# Type: int. Units: milliseconds. Scope: one viewer lifetime.
# Consumers: _NativeGalleryViewer._poll_queue.
# Rationale: 80 ms keeps status responsive without a busy loop.
MEDIA_QUEUE_POLL_MS = 80

# Purpose: Match the only validated native presentation intervals.
# Type: tuple[int, ...]. Units: seconds. Scope: one manual slideshow interval.
# Consumers: _NativeGalleryViewer._slideshow_delay_ms.
# Rationale: These are the server's fixed presets, not arbitrary timer input.
MEDIA_SLIDESHOW_INTERVALS = (3, 5, 10)

# Purpose: Resolve the Windows system directory before loading multimedia APIs.
# Type: int. Units: wide characters. Scope: GetSystemDirectoryW result buffer.
# Consumers: _NativeGalleryViewer._load_winmm.
# Rationale: 32768 accommodates the documented maximum Windows path.
WINDOWS_SYSTEM_DIRECTORY_BUFFER = 32768

# Purpose: Restrict native library lookup to the operating system directory.
# Type: int. Units: LoadLibraryEx flag bits. Scope: kernel32 and winmm loading.
# Consumers: _NativeGalleryViewer._load_winmm.
# Rationale: This flag prevents DLL search through the app or working folders.
LOAD_LIBRARY_SEARCH_SYSTEM32 = 0x00000800

_WorkerPayload: TypeAlias = (
    LinkedMediaPage
    | tuple[LinkedMediaPage, LinkedPresentation | None]
    | tuple[LinkedTrack, bytes]
    | tuple[bytes | None, ...]
    | bytes
    | None
)
_WorkerEvent: TypeAlias = tuple[int, str, _WorkerPayload]
_MciSender: TypeAlias = Callable[[str, str | None, int, int | None], int]


class _NativeGalleryViewer:
    """Own one Tk viewer, its cancellable worker, and temporary playback file.

    Args:
        parent (tk.Misc): Tk widget whose window owns the new viewer.
        session (LinkingSession): Current scoped session used only for media.
        gallery (LinkedGallery): Explicitly chosen gallery identity and title.
        language (Callable[[], str]): Callback returning the current language.
    """

    def __init__(
        self,
        parent: tk.Misc,
        session: LinkingSession,
        gallery: LinkedGallery,
        language: Callable[[], str],
    ) -> None:
        """Create the window and begin loading its first bounded page.

        Args:
            parent (tk.Misc): Tk widget whose window owns the new viewer.
            session (LinkingSession): Current scoped session used only for media.
            gallery (LinkedGallery): Explicitly chosen gallery identity and title.
            language (Callable[[], str]): Callback returning the current language.

        Returns:
            None: The viewer owns the created window until it is closed.
        """
        self.session = session
        self.gallery = gallery
        self.language = language
        self.window = tk.Toplevel(parent)
        self.window.title(self._text('linking_media_title'))
        self.window.geometry('840x620')
        self.window.minsize(580, 380)
        self.window.transient(parent.winfo_toplevel())
        self.window.protocol('WM_DELETE_WINDOW', self.close)
        self.window.bind('<Escape>', lambda _event: self.close(), add='+')
        self.window.bind('<Destroy>', self._on_destroy, add='+')

        self._events: queue.Queue[_WorkerEvent] = queue.Queue()
        self._cancelled = threading.Event()
        self._generation = 0
        self._worker: threading.Thread | None = None
        self._closed = False
        self._photos: tuple[LinkedPhoto, ...] = ()
        self._next_after: int | None = None
        self._after_id: str | None = None
        self._slideshow_id: str | None = None
        self._slideshow_running = False
        self._programmatic_selection: str | None = None
        self._slide_index = 0
        self._presentation: LinkedPresentation | None = None
        self._tracks: tuple[LinkedTrack, ...] = ()
        self._audio_path: str | None = None
        self._audio_alias: str | None = None
        self._winmm: object | None = None
        self._mci: _MciSender | None = None
        self._photo_images: list[object] = []

        self._build_widgets()
        self._poll_queue()
        self._load_first_page()

    def _text(self, key: str) -> str:
        """Return a translated viewer label for the current UI language.

        Args:
            key (str): Stable `linking_media_` translation key.

        Returns:
            str: Localized label with the translation module's English fallback.
        """
        try:
            current = self.language()
        except Exception:
            current = 'en'
        return tr(current, key)

    def _build_widgets(self) -> None:
        """Create the compact gallery, detail, and presentation controls.

        Returns:
            None: Widgets are installed in the viewer's Tk window.
        """
        shell = ttk.Frame(self.window, padding=14, style='Card.TFrame')
        shell.pack(fill='both', expand=True)
        gallery_title = ''.join(
            char if char.isprintable() else ' '
            for char in self.gallery.title
        ).strip()[:160]
        parent_context = (
            f' · parent #{self.gallery.parent_id}'
            if self.gallery.parent_id is not None else ''
        )
        ttk.Label(
            shell,
            text=(f'{gallery_title or "Gallery"} '
                  f'(#{self.gallery.gallery_id}{parent_context})'),
            style='Section.TLabel',
        ).pack(anchor='w')
        self.status = ttk.Label(shell, text=self._text('linking_media_loading'))
        self.status.pack(anchor='w', pady=(4, 8))

        body = ttk.Frame(shell, style='Card.TFrame')
        body.pack(fill='both', expand=True)
        style = ttk.Style(self.window)
        surface = style.lookup('Card.TFrame', 'background') or '#253443'
        ink = style.lookup('TLabel', 'foreground') or '#F2F6FA'
        selection = style.lookup('Primary.TButton', 'background') or '#08AED0'
        style.configure(
            'LinkedGallery.Treeview',
            background=surface,
            fieldbackground=surface,
            foreground=ink,
            rowheight=MEDIA_TREE_ROW_HEIGHT,
            borderwidth=0,
        )
        style.map(
            'LinkedGallery.Treeview',
            background=[('selected', selection)],
            foreground=[('selected', ink)],
        )
        self.listbox = ttk.Treeview(
            body,
            show='tree',
            selectmode='browse',
            height=20,
            style='LinkedGallery.Treeview',
        )
        self.listbox.column('#0', width=220, stretch=False)
        self.listbox.pack(side='left', fill='y', padx=(0, 12))
        self.listbox.bind('<<TreeviewSelect>>', self._on_photo_selected)

        right = ttk.Frame(body, style='Card.TFrame')
        right.pack(side='left', fill='both', expand=True)
        self.image_label = ttk.Label(
            right,
            text=self._text('linking_media_loading'),
            anchor='center',
            style='Muted.TLabel',
        )
        self.image_label.pack(fill='both', expand=True)

        actions = ttk.Frame(shell, style='Card.TFrame')
        actions.pack(fill='x', pady=(10, 0))
        self.more_button = ttk.Button(
            actions,
            text=self._text('linking_media_load_more'),
            command=self._load_more,
            state='disabled',
        )
        self.more_button.pack(side='left')
        self.slide_button = ttk.Button(
            actions,
            text=self._text('linking_media_slideshow_start'),
            command=self._toggle_slideshow,
            state='disabled',
        )
        self.slide_button.pack(side='left', padx=(8, 0))
        self.track_button = ttk.Button(
            actions,
            text=self._text('linking_media_play'),
            command=self._toggle_track,
            state='disabled',
        )
        self.track_button.pack(side='left', padx=(8, 0))
        self.track_menu = ttk.Combobox(
            actions,
            state='disabled',
            width=24,
        )
        self.track_menu.pack(side='left', padx=(8, 0))
        ttk.Button(
            actions,
            text=self._text('linking_media_close'),
            command=self.close,
        ).pack(side='right')

    def _launch(self, kind: str, operation: Callable[[], _WorkerPayload]) -> bool:
        """Run one operation and return its result through the Tk queue.

        Args:
            kind (str): Event type interpreted by the Tk polling callback.
            operation (Callable[[], _WorkerPayload]): Worker-only media work.

        Returns:
            bool: True when a daemon worker was started; otherwise False.
        """
        if self._closed or self._cancelled.is_set():
            return False
        if not self.session.can_read_media():
            self.close()
            return False
        if self._worker is not None and self._worker.is_alive():
            self.status.configure(text=self._text('linking_media_loading'))
            return False
        self._generation += 1
        generation = self._generation
        def run() -> None:
            """Execute the supplied operation and publish its result.

            Returns:
                None: A result or failure marker enters the owned queue.
            """
            try:
                result = operation()
                self._events.put((generation, kind, result))
            except Exception:
                self._events.put((generation, kind + '_error', None))

        self._worker = threading.Thread(
            target=run,
            name='linked-gallery-' + kind,
            daemon=True,
        )
        self._worker.start()
        return True

    def _load_first_page(self) -> None:
        """Request the initial photo page and optional presentation metadata.

        Returns:
            None: Work proceeds on the single owned background worker.
        """
        def operation() -> tuple[LinkedMediaPage, LinkedPresentation | None]:
            """Fetch one media page and optional authorized settings.

            Returns:
                tuple[LinkedMediaPage, LinkedPresentation | None]: Page and
                    optional presentation.
            """
            page = self.session.media_page(
                self.gallery.gallery_id,
                cancelled=self._cancelled,
            )
            presentation = None
            if self.session.can_read_presentation() and not self._cancelled.is_set():
                try:
                    presentation = self.session.media_presentation(
                        self.gallery.gallery_id,
                        cancelled=self._cancelled,
                    )
                except Exception:
                    presentation = None
            return page, presentation

        self._launch('page', operation)

    def _load_more(self, *, automatic: bool = False) -> None:
        """Request the next server page using its exclusive continuation.

        Args:
            automatic (bool): Whether the active slideshow advanced past its page.

        Returns:
            None: Replaces the current page on success or stops an interrupted slide.
        """
        after_id = self._next_after
        if after_id is None:
            return

        def operation() -> LinkedMediaPage:
            """Fetch the page after the current exclusive cursor.

            Returns:
                LinkedMediaPage: Validated page from the linked session.
            """
            return self.session.media_page(
                self.gallery.gallery_id,
                after_id=after_id,
                cancelled=self._cancelled,
            )

        if self._launch('slide_more' if automatic else 'more', operation):
            self.more_button.configure(state='disabled')
        elif automatic:
            self._stop_slideshow()

    def _accept_page(
        self,
        page: LinkedMediaPage,
        presentation: LinkedPresentation | None,
        *, continuing_slides: bool = False,
    ) -> None:
        """Validate and display one page, then fetch its bounded thumbnails.

        Args:
            page (LinkedMediaPage): Validated server page for this gallery.
            presentation (LinkedPresentation | None): Optional scoped settings.
            continuing_slides (bool): Keep active playback when crossing a page.

        Returns:
            None: The current page is shown and thumbnail work is queued.
        """
        if self._slideshow_running and not continuing_slides:
            self._stop_slideshow()
        if self._audio_alias is not None and not continuing_slides:
            self._stop_audio()
            self.track_button.configure(text=self._text('linking_media_play'))
        if (not isinstance(page, LinkedMediaPage)
                or (presentation is not None
                    and not isinstance(presentation, LinkedPresentation))
                or page.gallery_id != self.gallery.gallery_id
                or len(page.photos) > NATIVE_MEDIA_PAGE_SIZE
                or any(type(photo.image_id) is not int or photo.image_id <= 0
                       for photo in page.photos)
                or (page.next_after is not None
                    and (type(page.next_after) is not int
                         or page.next_after <= 0))):
            raise ValueError('Invalid media page')
        photos = page.photos
        self._photos = photos
        self._next_after = page.next_after
        self._presentation = presentation
        self._tracks = tuple(presentation.tracks) if presentation else ()
        self.listbox.delete(*self.listbox.get_children())
        self._photo_images.clear()
        for index, photo in enumerate(photos):
            title = ''.join(char if char.isprintable() else ' '
                            for char in photo.title).strip()
            self.listbox.insert(
                '', 'end', iid=str(index),
                text=title[:160] or f'#{photo.image_id}',
            )
        self.more_button.configure(
            state='normal' if self._next_after is not None else 'disabled',
        )
        can_slideshow = bool(
            presentation is not None
            and presentation.enabled
            and (len(photos) > 1 or self._next_after is not None
                 or self._slideshow_running)
        )
        self.slide_button.configure(state='normal' if can_slideshow else 'disabled')
        can_play = bool(presentation is not None and presentation.music and self._tracks)
        self.track_button.configure(state='normal' if can_play else 'disabled')
        self.track_menu.configure(
            values=tuple(track.title for track in self._tracks),
            state='readonly' if can_play else 'disabled',
        )
        if self._tracks:
            self.track_menu.current(0)
        if not photos:
            self._stop_slideshow()
            self.status.configure(text=self._text('linking_media_empty'))
            self.image_label.configure(text=self._text('linking_media_empty'), image='')
            return
        self.status.configure(text=self._text('linking_media_loading'))
        self._launch('thumbnails', lambda: self._load_thumbnails(photos))

    def _load_thumbnails(
        self,
        photos: tuple[LinkedPhoto, ...],
    ) -> tuple[bytes | None, ...]:
        """Fetch and downsize thumbnails for only the visible page.

        Args:
            photos (tuple[LinkedPhoto, ...]): Validated photos in this page.

        Returns:
            tuple[bytes | None, ...]: PNG thumbnails, preserving photo order.
        """
        thumbnails: list[bytes | None] = []
        for photo in photos:
            if self._cancelled.is_set():
                break
            try:
                body, _mime = self.session.media_photo(
                    self.gallery.gallery_id,
                    photo.image_id,
                    thumbnail=True,
                    cancelled=self._cancelled,
                )
                thumbnails.append(self._prepare_image(body, MEDIA_THUMBNAIL_SIZE))
            except Exception:
                thumbnails.append(None)
        return tuple(thumbnails)

    def _prepare_image(
        self,
        body: bytes,
        max_size: tuple[int, int] = MEDIA_IMAGE_MAX_SIZE,
    ) -> bytes:
        """Decode, constrain and normalize one image as bounded PNG bytes.

        Args:
            body (bytes): Image response body from the linked session.
            max_size (tuple[int, int]): Maximum normalized image dimensions.

        Returns:
            bytes: Resized PNG bytes suitable for Tk-side PhotoImage creation.

        Raises:
            ValueError: Pillow is unavailable or image data exceeds policy.
        """
        if Image is None or len(body) > NATIVE_MEDIA_MAX_BYTES:
            raise ValueError('Image support unavailable or response too large')
        with Image.open(io.BytesIO(body)) as source:
            if source.width * source.height > MEDIA_IMAGE_MAX_PIXELS:
                raise ValueError('Image dimensions exceed policy')
            image = source.convert('RGB')
            image.thumbnail(max_size)
            output = io.BytesIO()
            image.save(output, format='PNG', optimize=True)
            result = output.getvalue()
        if len(result) > NATIVE_MEDIA_MAX_BYTES:
            raise ValueError('Normalized image exceeds policy')
        return result

    def _poll_queue(self) -> None:
        """Apply queued worker results on Tk's owning thread.

        Returns:
            None: A new poll is scheduled while the viewer remains open.
        """
        if self._closed:
            return
        if not self.session.can_read_media():
            self.close()
            return
        while True:
            try:
                generation, kind, result = self._events.get_nowait()
            except queue.Empty:
                break
            if generation != self._generation or self._closed:
                continue
            self._worker = None
            try:
                if kind == 'page':
                    self._accept_page(*result)
                elif kind in ('more', 'slide_more'):
                    self._accept_page(
                        result, self._presentation,
                        continuing_slides=kind == 'slide_more' and self._slideshow_running,
                    )
                elif kind == 'thumbnails':
                    self._show_thumbnails(result)
                elif kind == 'detail':
                    self._show_detail(result)
                elif kind == 'track':
                    self._start_audio(*result)
                elif kind.endswith('_error'):
                    self.status.configure(text=self._text('linking_media_error'))
                    if kind.startswith('detail'):
                        self.image_label.configure(
                            text=self._text('linking_media_image_error'), image='',
                        )
                        self._stop_slideshow()
                    elif kind.startswith('track'):
                        self._stop_audio()
                        self.track_button.configure(
                            text=self._text('linking_media_play'),
                            state='normal',
                        )
                        self.status.configure(
                            text=self._text('linking_media_audio_error'),
                        )
                    elif kind in ('more_error', 'slide_more_error'):
                        self._stop_slideshow()
                        self.more_button.configure(
                            state='normal' if self._next_after is not None else 'disabled',
                        )
            except Exception:
                self.status.configure(text=self._text('linking_media_error'))
        self._after_id = self.window.after(MEDIA_QUEUE_POLL_MS, self._poll_queue)

    def _show_thumbnails(self, thumbnails: tuple[bytes | None, ...]) -> None:
        """Create Tk thumbnail images and show the first available preview.

        Args:
            thumbnails (tuple[bytes | None, ...]): Worker-normalized images.

        Returns:
            None: Images are retained for the lifetime of the current page.
        """
        if ImageTk is None:
            self.status.configure(text=self._text('linking_media_image_error'))
            return
        self._photo_images = []
        for index, data in enumerate(thumbnails):
            if data is None:
                self._photo_images.append(None)
                continue
            image = ImageTk.PhotoImage(Image.open(io.BytesIO(data)))
            self._photo_images.append(image)
            self.listbox.item(str(index), image=image)
        self.status.configure(text=self.gallery.title)
        if self._photos:
            self._programmatic_selection = '0'
            self.listbox.selection_set('0')
            self._load_detail(0)

    def _on_photo_selected(self, _event: tk.Event) -> None:
        """Load the full image for the selected photo on a worker.

        Args:
            _event (tk.Event): Treeview selection event from Tk.

        Returns:
            None: The current selection is fetched asynchronously.
        """
        selection = self.listbox.selection()
        if selection:
            if self._programmatic_selection == selection[0]:
                self._programmatic_selection = None
                return
            self._stop_slideshow()
            self._load_detail(int(selection[0]))

    def _load_detail(self, index: int) -> bool:
        """Request a selected original and queue its constrained rendering.

        Args:
            index (int): Zero-based index in the visible photo page.

        Returns:
            bool: True when the detail read was started.
        """
        if index < 0 or index >= len(self._photos):
            return False
        photo = self._photos[index]
        self._slide_index = index

        def operation() -> bytes:
            """Fetch and normalize the selected original image.

            Returns:
                bytes: Bounded PNG bytes for Tk-side display.
            """
            body, _mime = self.session.media_photo(
                self.gallery.gallery_id,
                photo.image_id,
                thumbnail=False,
                cancelled=self._cancelled,
            )
            return self._prepare_image(body)

        return self._launch('detail', operation)

    def _show_detail(self, data: bytes) -> None:
        """Render one normalized detail image on Tk's owning thread.

        Args:
            data (bytes): Worker-normalized PNG image bytes.

        Returns:
            None: The detail label retains the new PhotoImage reference.
        """
        if ImageTk is None:
            self.image_label.configure(text=self._text('linking_media_image_error'))
            return
        image = ImageTk.PhotoImage(Image.open(io.BytesIO(data)))
        self.image_label.configure(image=image, text='')
        self.image_label.image = image
        if self._slideshow_running and self._slideshow_id is None:
            self._schedule_slide()

    def _toggle_slideshow(self) -> None:
        """Start or stop the user-requested slideshow timer.

        Returns:
            None: The timer advances photos only while explicitly started.
        """
        if self._slideshow_running:
            self._stop_slideshow()
            return
        if ((len(self._photos) < 2 and self._next_after is None) or self._presentation is None
                or not self._presentation.enabled):
            return
        if not self.session.can_read_media():
            self.close()
            return
        self._slideshow_running = True
        if self._slide_index + 1 >= len(self._photos) and self._next_after is None:
            self._slide_index = -1
        self.slide_button.configure(text=self._text('linking_media_slideshow_stop'))
        self._advance_slide()

    def _stop_slideshow(self) -> None:
        """Stop slideshow advancement and cancel its one pending Tk timer.

        Returns:
            None: The slideshow returns to its explicit start state.
        """
        self._slideshow_running = False
        if self._slideshow_id is not None:
            try:
                self.window.after_cancel(self._slideshow_id)
            except tk.TclError:
                pass
            self._slideshow_id = None
        if not self._closed:
            self.slide_button.configure(
                text=self._text('linking_media_slideshow_start'),
            )

    def _slideshow_delay_ms(self) -> int:
        """Return the bounded interval configured by the gallery owner.

        Returns:
            int: Delay in milliseconds for one manually started slide.
        """
        raw = self._presentation.interval_seconds
        seconds = (
            raw if type(raw) is int and raw in MEDIA_SLIDESHOW_INTERVALS
            else MEDIA_SLIDESHOW_INTERVALS[0]
        )
        return seconds * 1000

    def _schedule_slide(self) -> None:
        """Schedule the next manual slideshow advance.

        Returns:
            None: Tk advances one image after the server-provided interval.
        """
        if self._closed or not self._slideshow_running:
            return
        self._slideshow_id = self.window.after(
            self._slideshow_delay_ms(),
            self._advance_slide,
        )

    def _advance_slide(self) -> None:
        """Show the next photo and schedule another slide if still active.

        Returns:
            None: The slideshow remains manual and stops on close or user input.
        """
        self._slideshow_id = None
        if self._closed or not self._slideshow_running or not self._photos:
            self._stop_slideshow()
            return
        if self._worker is not None and self._worker.is_alive():
            self._stop_slideshow()
            return
        next_index = self._slide_index + 1
        if next_index >= len(self._photos):
            if self._next_after is None:
                self._stop_slideshow()
            else:
                self._load_more(automatic=True)
            return
        self._programmatic_selection = str(next_index)
        self.listbox.selection_set(str(next_index))
        self.listbox.see(str(next_index))
        if not self._load_detail(next_index):
            self._programmatic_selection = None
            self._stop_slideshow()

    def _toggle_track(self) -> None:
        """Stop active audio or fetch the first explicitly available track.

        Returns:
            None: Playback starts only after the user presses this control.
        """
        if self._audio_alias is not None:
            self._stop_audio()
            self.track_button.configure(text=self._text('linking_media_play'))
            return
        if not self._tracks:
            return
        selected = self.track_menu.current()
        if selected < 0 or selected >= len(self._tracks):
            return
        track = self._tracks[selected]
        if track.mime not in ('audio/mpeg', 'audio/wav', 'audio/x-wav'):
            self.status.configure(text=self._text('linking_media_audio_unavailable'))
            return
        self.track_button.configure(text=self._text('linking_media_audio_loading'))

        def operation() -> tuple[LinkedTrack, bytes]:
            """Fetch and verify the selected track before temporary storage.

            Returns:
                tuple[LinkedTrack, bytes]: Exact track and verified content.
            """
            body = self.session.media_track(
                self.gallery.gallery_id,
                track,
                cancelled=self._cancelled,
            )
            if (len(body) > NATIVE_MEDIA_MAX_BYTES
                    or len(body) != track.size
                    or hashlib.sha256(body).hexdigest() != track.sha256):
                raise ValueError('Track integrity check failed')
            return track, body

        if self._launch('track', operation):
            self.track_button.configure(state='disabled')
        elif not self._closed:
            self.track_button.configure(text=self._text('linking_media_play'))

    def _start_audio(self, track: LinkedTrack, body: bytes) -> None:
        """Write verified bytes to an owned temp file and start Windows MCI.

        Args:
            track (LinkedTrack): Metadata for the exact requested track.
            body (bytes): Verified track content returned by the worker.

        Returns:
            None: Windows winmm owns playback until stop or window close.
        """
        if sys.platform != 'win32' or not hasattr(ctypes, 'WinDLL'):
            self.status.configure(text=self._text('linking_media_audio_unavailable'))
            self.track_button.configure(
                text=self._text('linking_media_play'), state='normal',
            )
            return
        suffix = '.mp3' if track.mime == 'audio/mpeg' else '.wav'
        descriptor, path = tempfile.mkstemp(prefix='php-gallery-track-', suffix=suffix)
        self._audio_path = path
        alias = 'pglink' + uuid.uuid4().hex
        self._audio_alias = alias
        try:
            with os.fdopen(descriptor, 'wb') as stream:
                stream.write(body)
            if '"' in path:
                raise ValueError('Unsupported temporary path')
            mci = self._load_winmm()
            media_type = 'mpegvideo' if suffix == '.mp3' else 'waveaudio'
            self._mci_command(mci, f'open "{path}" type {media_type} alias {alias}')
            self._mci_command(mci, f'play {alias}')
            self.track_button.configure(
                text=self._text('linking_media_stop'), state='normal',
            )
            self.status.configure(text=track.title)
        except Exception:
            self._stop_audio()
            self.track_button.configure(
                text=self._text('linking_media_play'), state='normal',
            )
            self.status.configure(text=self._text('linking_media_audio_error'))

    def _load_winmm(self) -> _MciSender:
        """Load winmm from the OS-reported System32 directory only.

        Returns:
            _MciSender: Typed Unicode MCI command function.

        Raises:
            OSError: The system directory or native library cannot be resolved.
        """
        if sys.platform != 'win32' or not hasattr(ctypes, 'WinDLL'):
            raise OSError('Windows multimedia is unavailable')
        kernel32 = ctypes.WinDLL(
            'kernel32.dll',
            use_last_error=True,
            winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        get_system_directory = kernel32.GetSystemDirectoryW
        get_system_directory.argtypes = [ctypes.c_wchar_p, ctypes.c_uint]
        get_system_directory.restype = ctypes.c_uint
        buffer = ctypes.create_unicode_buffer(WINDOWS_SYSTEM_DIRECTORY_BUFFER)
        length = get_system_directory(buffer, len(buffer))
        if length == 0 or length >= len(buffer):
            raise OSError('Could not resolve Windows System32')
        library_path = os.path.join(buffer.value, 'winmm.dll')
        if not os.path.isabs(library_path):
            raise OSError('Windows multimedia path is not absolute')
        library = ctypes.WinDLL(
            library_path,
            use_last_error=True,
            winmode=LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        mci = library.mciSendStringW
        mci.argtypes = [
            ctypes.c_wchar_p,
            ctypes.c_wchar_p,
            ctypes.c_uint,
            ctypes.c_void_p,
        ]
        mci.restype = ctypes.c_uint
        self._winmm = library
        self._mci = mci
        return mci

    def _mci_command(self, mci: _MciSender, command: str) -> None:
        """Send one bounded command to the current Windows MCI device.

        Args:
            mci (_MciSender): Windows winmm MCI command function.
            command (str): Locally constructed command and owned alias.

        Returns:
            None: Raises OSError if winmm rejects the command.
        """
        result = mci(command, None, 0, None)
        if result:
            raise OSError(f'MCI command failed: {result}')

    def _stop_audio(self) -> None:
        """Stop and close owned MCI playback, then unlink its owned file.

        Returns:
            None: Only this viewer's alias and temporary file are removed.
        """
        alias = self._audio_alias
        self._audio_alias = None
        if alias is not None and self._mci is not None:
            for command in (f'stop {alias}', f'close {alias}'):
                try:
                    self._mci(command, None, 0, None)
                except Exception:
                    pass
        self._mci = None
        self._winmm = None
        path = self._audio_path
        self._audio_path = None
        if path is not None:
            try:
                os.unlink(path)
            except FileNotFoundError:
                pass
            except OSError:
                pass

    def close(self, *, destroy_window: bool = True) -> None:
        """Cancel owned work, stop playback, and optionally destroy the window.

        Args:
            destroy_window (bool): Whether this call should destroy the Toplevel.

        Returns:
            None: The worker observes cancellation and no later event updates Tk.
        """
        if self._closed:
            return
        self._closed = True
        self._cancelled.set()
        self._generation += 1
        if self._after_id is not None:
            try:
                self.window.after_cancel(self._after_id)
            except tk.TclError:
                pass
        self._stop_slideshow()
        self._stop_audio()
        if destroy_window:
            try:
                self.window.destroy()
            except tk.TclError:
                pass

    def _on_destroy(self, event: tk.Event) -> None:
        """Release viewer resources when its own Toplevel is destroyed.

        Args:
            event (tk.Event): Tk destruction event for this window or a child.

        Returns:
            None: Cleanup starts only for this Toplevel's destruction event.
        """
        if event.widget is self.window and not self._closed:
            self.close(destroy_window=False)


def open_native_gallery_view(
    parent: tk.Misc,
    session: LinkingSession,
    gallery: LinkedGallery,
    language: Callable[[], str],
) -> tk.Toplevel:
    """Open a bounded native viewer for a gallery chosen by the user.

    Args:
        parent (tk.Misc): Tk widget owning the new viewer window.
        session (LinkingSession): Live bearer session with required media scope.
        gallery (LinkedGallery): User-selected gallery from a validated page.
        language (Callable[[], str]): Callback returning the current UI language.

    Returns:
        tk.Toplevel: Viewer window owning its worker and playback resources.
    """
    return _NativeGalleryViewer(parent, session, gallery, language).window
