# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos_workspace.py
# Module Type: Windows Client UI
# Purpose: Show linked galleries and selected photos in one main-window workspace.
# Responsibilities:
#   - Load bounded catalogue pages sequentially for the current live session.
#   - Display a hierarchy and mount one embedded gallery viewer.
#   - Anchor scoped photo, gallery and creation actions in the workspace.
#   - Cancel reads and release gallery state when the session or page changes.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Main-window gallery hierarchy and embedded photo workspace."""

import queue
import threading
import tkinter as tk
from tkinter import ttk
from typing import Callable, TypeAlias

from .app_linking import (
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    LinkedGallery, LinkedGalleryDetail, LinkedGalleryPage, LinkedMediaDetails,
    LinkedMediaGalleryInfo, LinkedPhoto, LinkingSession,
)
from .app_linking_ui import LinkingManager
from .flight_report_translations import tr
from .gallery_folders import GalleryFolderShelf
from .linked_create import BoundCreateReceipt
from .linked_upload import LinkedUploadTarget
from .native_gallery_viewer import GalleryThumbnailMemory, mount_native_gallery_view
from .tk_scroll_input import vertical_scroll_delta
from .ui_theme import GlassCard, _PALETTE, icon_button


# Purpose: Keep Tk result delivery responsive during sequential page loading.
# Type: int. Units: milliseconds. Scope: one PhotosWorkspace lifetime.
# Consumers: PhotosWorkspace._poll_events.
# Rationale: 80 ms gives quick feedback without a busy UI polling loop.
PHOTOS_CATALOG_POLL_MS = 80

# Purpose: Start with a compact hierarchy and leave room for photos.
# Type: int. Units: pixels. Scope: the left gallery-tree pane.
# Consumers: PhotosWorkspace._build_widgets.
# Rationale: 210 px keeps common names visible and the splitter adjustable.
PHOTOS_TREE_WIDTH = 210

# Purpose: Bound implicit attempts to find a gallery with visible photos.
# Type: int. Units: gallery candidates. Scope: one session auto-selection.
# Consumers: PhotosWorkspace._ensure_initial_gallery.
# Rationale: Three candidates handle a few empty/protected galleries quickly.
PHOTOS_INITIAL_CANDIDATE_LIMIT = 3

# Purpose: Bound automatic catalogue reads against shared per-IP request limits.
# Type: int. Units: pages. Scope: one automatic loading pass per live session.
# Consumers: PhotosWorkspace._continue_catalogue.
# Rationale: Ten 50-row pages load common trees while preserving site capacity.
PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT = 10

# Purpose: Bound metadata-only cache checks for large galleries.
# Type: int. Units: pages. Scope: one gallery info action.
# Consumers: PhotosWorkspace._gallery_info.
# Rationale: Ten pages cover typical albums while capping remote cursor reads.
PHOTOS_CACHE_PAGE_LIMIT = 10

# Purpose: Keep long metadata cards scrollable inside the current workspace.
# Type: int. Units: pixels. Scope: one on-demand information card.
# Consumers: PhotosWorkspace._render_info_card.
# Rationale: 480 px leaves room for surrounding controls and remains bounded.
PHOTOS_INFO_CARD_MAX_HEIGHT = 480

# Purpose: Keep detail cards readable without covering the full workspace.
# Type: int. Units: pixels. Scope: one on-demand information card.
# Consumers: PhotosWorkspace._render_info_card.
# Rationale: 420 px fits two wrapped label/value lines at narrow widths.
PHOTOS_INFO_CARD_MAX_WIDTH = 420

# Purpose: Move Photos heading controls below the title in a narrow pane.
# Type: int. Units: pixels. Scope: the selected-gallery heading.
# Consumers: PhotosWorkspace._on_heading_resize.
# Rationale: 440 pixels gives the localized switch and title useful space.
PHOTOS_HEADING_STACK_WIDTH = 440

# Purpose: Keep the recording dot compact beside a gallery name.
# Type: int. Units: pixels. Scope: one tree or heading indicator.
# Consumers: PhotosWorkspace._recording_image.
# Rationale: Nine pixels remain legible without changing the tree row height.
PHOTOS_RECORDING_DOT_SIZE = 9

_GalleryResult: TypeAlias = tuple[int, LinkedGalleryPage | None]
_InfoRow: TypeAlias = tuple[str, str | int | float | bool | None]
_InfoGroup: TypeAlias = tuple[str, list[_InfoRow]]


class PhotosWorkspace(ttk.Frame):
    """Own one linked gallery tree and one embedded gallery viewer.

    Args:
        parent (tk.Misc): Main-window page that owns the workspace.
        session_provider (Callable[[], LinkingSession | None]): Current live
            session admitted to gallery listing and photo reads.
        connect (Callable[[], None]): Existing address-connection action.
        language (Callable[[], str]): Current application language callback.
        manager (LinkingManager | None): Shared approval and creation owner.
        format_bytes (Callable[[int], str]): Existing application size formatter.
        manage_link (Callable[[], None] | None): Open established link recovery.
        start_watcher (Callable[[LinkingSession, LinkedGallery], None] | None):
            Start a watcher for one explicitly chosen gallery.
        simbrief_available (Callable[[], bool] | None): Whether SimBrief actions
            are available from the current desktop session.
        simbrief_action (Callable[[], None] | None): Open the host SimBrief view.
        load_tree_state (Callable[[LinkingSession], dict[int, bool]] | None):
            Load saved expansion states for the current site and account.
        save_tree_state (Callable[[LinkingSession, dict[int, bool]], None] | None):
            Persist expansion states for the current site and account.
    """

    def __init__(
        self,
        parent: tk.Misc,
        session_provider: Callable[[], LinkingSession | None],
        connect: Callable[[], None],
        language: Callable[[], str],
        *,
        manager: LinkingManager | None = None,
        format_bytes: Callable[[int], str] = str,
        manage_link: Callable[[], None] | None = None,
        start_watcher: Callable[[LinkingSession, LinkedGallery], None] | None = None,
        simbrief_available: Callable[[], bool] | None = None,
        simbrief_action: Callable[[], None] | None = None,
        load_tree_state: Callable[[LinkingSession], dict[int, bool]] | None = None,
        save_tree_state: Callable[[LinkingSession, dict[int, bool]], None] | None = None,
    ) -> None:
        """Create the two-pane workspace and request its current session.

        Args:
            parent (tk.Misc): Main-window page that owns the workspace.
            session_provider (Callable[[], LinkingSession | None]): Current
                session lookup owned by LinkingManager.
            connect (Callable[[], None]): Existing address-connection action.
            language (Callable[[], str]): Current application language callback.
            manager (LinkingManager | None): Optional live linking action owner.
            format_bytes (Callable[[int], str]): Formats an indexed byte count.
            manage_link (Callable[[], None] | None): Existing link recovery action.
            start_watcher (Callable[[LinkingSession, LinkedGallery], None] | None):
                Optional explicit gallery-scoped watcher launch callback.
            simbrief_available (Callable[[], bool] | None): Optional host readiness
                check for the SimBrief contextual action.
            simbrief_action (Callable[[], None] | None): Optional host action to
                open SimBrief for the current desktop selection.
            load_tree_state (Callable[[LinkingSession], dict[int, bool]] | None):
                Load per-gallery expansion states for this site and account.
            save_tree_state (Callable[[LinkingSession, dict[int, bool]], None] | None):
                Save per-gallery expansion states for this site and account.

        Returns:
            None: Starts a bounded asynchronous catalogue read when available.
        """
        super().__init__(parent, style="Backdrop.TFrame")
        self._session_provider = session_provider
        self._connect = connect
        self._language = language
        self._manager = manager
        self._format_bytes = format_bytes
        self._manage_link = manage_link
        self._start_watcher = start_watcher
        self._simbrief_available = simbrief_available
        self._simbrief_action = simbrief_action
        self._load_tree_state = load_tree_state
        self._save_tree_state = save_tree_state
        self._tree_state: dict[int, bool] = {}
        self._thumbnail_memory = GalleryThumbnailMemory()
        self._cache_state: dict[int, str] = {}
        self._context_card: GlassCard | None = None
        self._context_origin: tk.Misc | None = None
        self._context_xy = (0, 0)
        self._context_bindings: list[tuple[str, str]] = []
        self._context_bindtag = "PhotosContext" + str(id(self))
        self._context_tagged_widgets: list[tk.Misc] = []
        self._context_click_binding: str | None = None
        self._context_focus_binding: str | None = None
        self._context_focus_check_id: str | None = None
        self._context_results: queue.Queue[
            tuple[int | None, Callable[[], None]]
        ] = queue.Queue()
        self._context_busy = False
        self._probe_cancel: threading.Event | None = None
        self._create_receipt: BoundCreateReceipt | None = None
        self._session: LinkingSession | None = None
        self._cancel = threading.Event()
        self._generation = 0
        self._worker: threading.Thread | None = None
        self._events: queue.Queue[_GalleryResult] = queue.Queue()
        self._poll_id: str | None = None
        self._pending_cursor: int | None = None
        self._has_pending_page = False
        self._next_after: int | None = None
        self._pages_this_pass = 0
        self._galleries: dict[int, LinkedGallery] = {}
        self._children_by_parent: dict[int, set[int]] = {}
        self._selected_gallery_id: int | None = None
        self._implicit_pending = False
        self._implicit_attempted: set[int] = set()
        self._initial_attempt_count = 0
        self._catalogue_paused_for_preview = False
        self._viewer: ttk.Frame | None = None
        self._destroyed = False
        self._rendering_tree = False
        self._expand_new_nodes = False
        self._watcher_target: LinkedUploadTarget | None = None
        self._watcher_pulse = False
        self._recording_rows: set[str] = set()
        self._recording_images: dict[tuple[bool, bool], tk.PhotoImage] = {}

        self.rowconfigure(0, weight=1)
        self.columnconfigure(0, weight=1)
        self._build_widgets()
        self.bind("<Destroy>", self._on_destroy, add="+")
        self.bind("<Unmap>", lambda _event: self._dismiss_context(restore=False), add="+")
        self.bind(
            "<Configure>", lambda _event: self._place_context(self._context_card),
            add="+",
        )
        self.refresh_session()

    def _text(self, key: str) -> str:
        """Translate a stable workspace key for the current language.

        Args:
            key (str): Photos-workspace translation key.

        Returns:
            str: Localized label with the translation module's fallback.
        """
        return tr(self._language(), "photos_workspace_" + key)

    def _build_widgets(self) -> None:
        """Create the connection action, hierarchy pane and viewer host.

        Returns:
            None: Installs the workspace widgets in the main window.
        """
        self.connect_view = ttk.Frame(self, style="Card.TFrame", padding=18)
        style = ttk.Style(self)
        danger = style.lookup('Watcher.Active.TLabel', 'foreground') or '#FF91A2'
        style.configure(
            'Danger.Action.Photos.Icon.TButton', foreground=danger,
        )
        self.connect_view.grid(row=0, column=0, sticky="nw", padx=18, pady=18)
        ttk.Button(
            self.connect_view,
            text=tr(self._language(), "linking_start"),
            command=self._connect,
            style="Accent.Compact.TButton",
        ).grid(row=0, column=0, sticky="w")

        self.panes = ttk.Panedwindow(self, orient="horizontal")
        self.panes.grid(row=0, column=0, sticky="nsew")
        self.tree_pane = ttk.Frame(self.panes, style="Card.TFrame", padding=8)
        self.viewer_pane = ttk.Frame(self.panes, style="Card.TFrame", padding=8)
        self.panes.add(self.tree_pane, weight=0)
        self.panes.add(self.viewer_pane, weight=1)
        self.tree_pane.configure(width=PHOTOS_TREE_WIDTH)
        self.tree_pane.grid_propagate(False)
        self.tree_pane.rowconfigure(1, weight=1)
        self.tree_pane.columnconfigure(0, weight=1)
        controls = ttk.Frame(self.tree_pane, style="Card.TFrame")
        controls.grid(row=0, column=0, sticky="ew", pady=(0, 6))
        icon_button(
            controls,
            "+",
            lambda: self._set_all_open(True),
            self._text("expand_all"),
        ).pack(side="left")
        icon_button(
            controls,
            "−",
            lambda: self._set_all_open(False),
            self._text("collapse_all"),
        ).pack(side="left", padx=(4, 0))
        self.add_root_button = icon_button(
            controls,
            "⊕",
            lambda: self._open_creation_context(self.add_root_button, None),
            self._text("create_root"),
        )
        self.add_root_button.pack(side="left", padx=(4, 0))
        self.load_more_button = icon_button(
            controls,
            "+",
            self._load_more_catalogue,
            self._text("load_more"),
        )
        self.load_more_button.pack(side="right")
        self.load_more_button.pack_forget()
        self.retry_button = ttk.Button(
            controls,
            text=self._text("retry"),
            command=self._retry_catalogue,
            style="TButton",
        )
        self.retry_button.pack(side="right")
        self.retry_button.pack_forget()
        self.tree = ttk.Treeview(
            self.tree_pane,
            show="tree",
            selectmode="browse",
            takefocus=True,
        )
        self.tree.grid(row=1, column=0, sticky="nsew")
        tree_scroll = ttk.Scrollbar(
            self.tree_pane,
            orient="vertical",
            command=self.tree.yview,
        )
        tree_scroll.grid(row=1, column=1, sticky="ns")
        self.tree.configure(yscrollcommand=tree_scroll.set)
        self.tree.bind("<<TreeviewSelect>>", self._on_gallery_selected)
        self.tree.bind("<<TreeviewOpen>>", self._on_tree_expansion, add="+")
        self.tree.bind("<<TreeviewClose>>", self._on_tree_expansion, add="+")
        for sequence in ("<Button-3>", "<Button-2>", "<Control-Button-1>"):
            self.tree.bind(sequence, self._tree_context)
        self.tree_status = ttk.Label(
            self.tree_pane, text="", style="Muted.TLabel",
            wraplength=PHOTOS_TREE_WIDTH,
        )
        self.tree_status.grid(row=2, column=0, sticky="w", pady=(6, 0))
        self._gallery_heading = ttk.Frame(self.viewer_pane, style="Card.TFrame")
        self._gallery_heading.pack(fill="x", padx=8, pady=(4, 6))
        self._gallery_heading.columnconfigure(0, weight=1)
        self._heading_stacked: bool | None = None
        self._heading_title = ttk.Frame(self._gallery_heading, style="Card.TFrame")
        self._heading_controls = ttk.Frame(self._gallery_heading, style="Card.TFrame")
        self._heading_title.grid(row=0, column=0, sticky="ew")
        self._heading_controls.grid(row=0, column=1, sticky="e")
        self._gallery_title = tk.StringVar(self._gallery_heading)
        self._show_subgalleries = tk.BooleanVar(self._gallery_heading, value=True)
        self.refresh_button = icon_button(
            self._heading_controls, "refresh", self._refresh_photos,
            self._text("refresh"),
        )
        self.refresh_button.pack(side="right", padx=(0, 8))
        self.add_child_button = icon_button(
            self._heading_controls,
            "add",
            self._open_selected_creation_context,
            self._text("create_child"),
        )
        self.add_child_button.configure(state="disabled")
        self.add_child_button.pack(side="right", padx=(0, 8))
        self._subgallery_switch = ttk.Checkbutton(
            self._heading_controls, text=self._text("subgalleries"),
            style="Card.TCheckbutton", variable=self._show_subgalleries,
            command=self._sync_subgalleries,
        )
        self._subgallery_switch.pack(side="right")
        self._recording_heading = ttk.Label(
            self._heading_title, text="", style="Card.TLabel",
        )
        self._recording_heading.pack(side="left", padx=(0, 4))
        self._gallery_title_label = ttk.Label(
            self._heading_title, textvariable=self._gallery_title,
            style="Section.TLabel", justify="left",
        )
        self._gallery_title_label.pack(side="left", fill="x", expand=True)
        self._gallery_heading.bind("<Configure>", self._on_heading_resize, add="+")
        self._folder_shelf = GalleryFolderShelf(
            self.viewer_pane, self._open_subgallery, self._gallery_context,
        )
        self.viewer_status = ttk.Label(
            self.viewer_pane,
            text=self._text("select_gallery"),
            style="Muted.TLabel",
            wraplength=520,
        )
        self.viewer_status.pack(fill="x", padx=8, pady=8)
        self.viewer_pane.bind("<Configure>", self._on_viewer_resize, add="+")
        self.panes.grid_remove()

    def _on_heading_resize(self, event: tk.Event) -> None:
        """Stack controls below the album title when its pane is narrow.

        Args:
            event (tk.Event): Heading width change from Tk geometry management.

        Returns:
            None: Title wrapping follows available width without pulse relayout.
        """
        stacked = event.width < PHOTOS_HEADING_STACK_WIDTH
        if stacked != self._heading_stacked:
            self._heading_stacked = stacked
            self._heading_controls.grid(
                row=1 if stacked else 0, column=0 if stacked else 1,
                sticky="w" if stacked else "e", pady=(4, 0) if stacked else 0,
            )
        available = event.width
        if not stacked:
            available -= self._heading_controls.winfo_reqwidth()
        self._gallery_title_label.configure(wraplength=max(1, available - 16))

    def _on_viewer_resize(self, event: tk.Event) -> None:
        """Keep the contextual viewer status within its pane width.

        Args:
            event (tk.Event): Resize event from the right-hand pane.

        Returns:
            None: Status text wraps at the currently available width.
        """
        self.viewer_status.configure(wraplength=max(160, event.width - 24))

    def _photos_changed(self, gallery_id: int) -> None:
        """Invalidate stale cache coverage after a changed photo snapshot.

        Args:
            gallery_id (int): Gallery owned by the current viewer.

        Returns:
            None: Only the corresponding tree row is updated when needed.
        """
        if gallery_id in self._cache_state:
            del self._cache_state[gallery_id]
            self._render_tree([gallery_id])

    def _refresh_photos(self) -> None:
        """Refresh the selected gallery through its current viewer owner.

        Returns:
            None: The viewer preserves the current page, selection and cache.
        """
        if self._viewer is not None:
            controller = getattr(self._viewer, '_gallery_viewer_controller', None)
            if controller is not None:
                controller.refresh_photos()

    def _open_selected_creation_context(self) -> None:
        """Open child creation for the currently selected gallery.

        Returns:
            None: Shows the existing create action or its approval guidance.
        """
        gallery_id = self._selected_gallery_id
        if gallery_id is not None:
            self._open_creation_context(self.add_child_button, gallery_id)

    def _context_event(self, widget: tk.Misc) -> tk.Event:
        """Build the pointer coordinates used by the existing inline card.

        Args:
            widget (tk.Misc): Toolbar or context trigger anchoring the card.

        Returns:
            tk.Event: Synthetic event anchored below the trigger widget.
        """
        event = tk.Event()
        event.widget = widget
        event.x_root = widget.winfo_rootx() + widget.winfo_width() // 2
        event.y_root = widget.winfo_rooty() + widget.winfo_height()
        return event

    def _open_creation_context(
        self, widget: tk.Misc, gallery_id: int | None,
    ) -> None:
        """Anchor the existing create action to a visible toolbar button.

        Args:
            widget (tk.Misc): Root or child creation toolbar button.
            gallery_id (int | None): Exact parent gallery, or web root.

        Returns:
            None: Displays the inline creation form or explicit consent route.
        """
        if self._session is None:
            return
        event = self._context_event(widget)
        card = self._open_context(event)
        if self._session.can_create_gallery() and self._manager is not None:
            self._creation_form(card, self._session, gallery_id)
            return
        self._context_content(card, self._text("create_requires_approval"))
        self._add_creation_approval_action(card, self._session, gallery_id)
        self._place_context(card)

    def _add_creation_approval_action(
        self, card: GlassCard, session: LinkingSession,
        parent_id: int | None,
    ) -> None:
        """Offer the explicit consent route for an unavailable create grant.

        Args:
            card (GlassCard): Current inline creation surface.
            session (LinkingSession): Session that lacks the create grant.
            parent_id (int | None): Chosen parent gallery, or web root.

        Returns:
            None: Legacy links open Admin consent; other cases open management.
        """
        label = self._text("create_root" if parent_id is None else "create_child")
        self._context_action(
            card, "+ " + label,
            lambda: self._request_creation_approval(card, session),
        )

    def _add_gallery_creation_action(
        self, card: GlassCard, session: LinkingSession,
        parent_id: int | None,
    ) -> None:
        """Add creation or explicit approval for one chosen location.

        Args:
            card (GlassCard): Current gallery or photo context surface.
            session (LinkingSession): Current linked gallery session.
            parent_id (int | None): Chosen parent gallery, or web root.

        Returns:
            None: The action uses the existing create journal after consent.
        """
        if self._manager is not None and session.can_create_gallery():
            label = self._text("create_root" if parent_id is None else "create_child")
            self._context_action(
                card, "+ " + label,
                lambda: self._creation_form(card, session, parent_id),
            )
        else:
            self._add_creation_approval_action(card, session, parent_id)

    def _request_creation_approval(
        self, card: GlassCard, session: LinkingSession,
    ) -> None:
        """Start the existing explicit consent or link management flow.

        Args:
            card (GlassCard): Current inline approval surface.
            session (LinkingSession): Session whose scope was checked.

        Returns:
            None: Admin consent starts for legacy links; otherwise management.
        """
        self._dismiss_context()
        if (self._manager is not None
                and self._session is session
                and not session.can_read_admin_media()):
            self._manager.approve_admin_photos()
        elif self._manage_link is not None:
            self._manage_link()

    def refresh_session(self) -> None:
        """Switch to the current live gallery session and reload its tree.

        Returns:
            None: Releases stale reads, decoded media and contextual actions.
        """
        if self._destroyed:
            return
        session = self._session_provider()
        if session is self._session:
            return
        self._dismiss_context(restore=False)
        self._session = session
        self._tree_state = (
            self._load_tree_state(session)
            if session is not None and self._load_tree_state is not None else {}
        )
        self._cache_state.clear()
        self._create_receipt = None
        self._generation += 1
        self._cancel.set()
        self._cancel = threading.Event()
        self._clear_catalogue()
        self._thumbnail_memory.clear()
        self._thumbnail_memory = GalleryThumbnailMemory()
        self._pages_this_pass = 0
        self.load_more_button.pack_forget()
        self._pending_cursor = None
        self._has_pending_page = False
        if session is None:
            self.panes.grid_remove()
            self.connect_view.grid()
            return
        self.connect_view.grid_remove()
        self.panes.grid()
        self.viewer_status.configure(text=self._text("loading"))
        self.tree_status.configure(text=self._text("loading"))
        self._request_page(None)

    def _clear_catalogue(self) -> None:
        """Clear the previous catalogue, selection and embedded viewer.

        Returns:
            None: Old site data and image references are released immediately.
        """
        if self._viewer is not None:
            self._viewer.destroy()
            self._viewer = None
        self._galleries.clear()
        self._children_by_parent.clear()
        self._selected_gallery_id = None
        self.add_child_button.configure(state="disabled")
        self._gallery_title.set("")
        self._recording_heading.configure(image="")
        self._recording_rows.clear()
        self._sync_subgalleries()
        self._next_after = None
        self._implicit_pending = False
        self._implicit_attempted.clear()
        self._initial_attempt_count = 0
        self._catalogue_paused_for_preview = False
        self.tree.delete(*self.tree.get_children())
        self.tree_status.configure(text="")
        self.viewer_status.configure(text=self._text("select_gallery"))
        self.viewer_status.pack(fill="x", padx=8, pady=8)

    def _request_page(self, after_id: int | None) -> None:
        """Queue one server catalogue page on the current session.

        Args:
            after_id (int | None): Exclusive cursor from the prior page.

        Returns:
            None: At most one request occupies the session transport.
        """
        if self._session is None or self._destroyed:
            return
        if self._worker is not None:
            self._pending_cursor = after_id
            self._has_pending_page = True
            return
        session = self._session
        generation = self._generation
        cancel = self._cancel

        def fetch() -> None:
            """Read one validated catalogue page and queue its result.

            Returns:
                None: Worker output is consumed only by the Tk polling owner.
            """
            try:
                page = session.gallery_page(after_id, cancelled=cancel)
            except Exception:
                page = None
            if not cancel.is_set():
                self._events.put((generation, page))

        self._worker = threading.Thread(
            target=fetch,
            name="photos-catalogue-page",
            daemon=True,
        )
        self._worker.start()
        self._schedule_poll()

    def _schedule_poll(self) -> None:
        """Schedule one nonblocking result poll for active catalogue work.

        Returns:
            None: A single timer services this workspace's worker queue.
        """
        if self._poll_id is None and not self._destroyed:
            self._poll_id = self.after(PHOTOS_CATALOG_POLL_MS, self._poll_events)

    def _poll_events(self) -> None:
        """Apply context and catalogue results on the Tk thread.

        Returns:
            None: Current results apply before pending catalogue work advances.
        """
        self._poll_id = None
        while True:
            try:
                generation, completion = self._context_results.get_nowait()
            except queue.Empty:
                break
            self._context_busy = False
            if ((generation is None or generation == self._generation)
                    and self._session_provider() is self._session):
                completion()
        while True:
            try:
                generation, page = self._events.get_nowait()
            except queue.Empty:
                break
            if generation == self._generation and self._session is not None:
                self._accept_page(page)
        if self._worker is not None and not self._worker.is_alive():
            if not self._events.empty():
                self._schedule_poll()
                return
            self._worker = None
        if self._worker is None and self._has_pending_page:
            cursor = self._pending_cursor
            self._pending_cursor = None
            self._has_pending_page = False
            self._request_page(cursor)
        if self._worker is not None or self._has_pending_page or self._context_busy:
            self._schedule_poll()

    def _accept_page(self, page: LinkedGalleryPage | None) -> None:
        """Add one page and request its continuation when present.

        Args:
            page (LinkedGalleryPage | None): Validated response or failure marker.

        Returns:
            None: Current rows become visible without retaining prior image data.
        """
        if page is None:
            self.tree_status.configure(text=self._text("error"))
            self.viewer_status.configure(text=self._text("error"))
            self.retry_button.pack(side="right")
            return
        self.retry_button.pack_forget()
        page_gallery_ids: list[int] = []
        for gallery in page.galleries:
            self._galleries[gallery.gallery_id] = gallery
            page_gallery_ids.append(gallery.gallery_id)
            if gallery.parent_id is not None:
                self._children_by_parent.setdefault(
                    gallery.parent_id, set(),
                ).add(gallery.gallery_id)
        self._render_tree(page_gallery_ids)
        self._sync_subgalleries()
        self._pages_this_pass += 1
        self._next_after = page.next_after
        if self._selected_gallery_id is None and self._galleries:
            self._implicit_pending = True
            self._ensure_initial_gallery()
        if page.next_after is not None:
            self.tree_status.configure(text=self._text("loading"))
            if not self._catalogue_paused_for_preview:
                self._continue_catalogue()
        else:
            if not self._galleries:
                self.tree_status.configure(text=self._text("empty"))
                self.viewer_status.configure(text=self._text("empty"))
            else:
                self.tree_status.configure(text="")

    def _first_gallery(self) -> LinkedGallery | None:
        """Choose an untried leaf gallery, falling back to an orphan row.

        Returns:
            LinkedGallery | None: Lowest-ID leaf, then any untried row.
        """
        ordered = sorted(self._galleries.values(), key=lambda item: item.gallery_id)
        parents = {gallery.parent_id for gallery in ordered}
        for gallery in ordered:
            if (gallery.gallery_id not in parents
                    and gallery.gallery_id not in self._implicit_attempted):
                return gallery
        for gallery in ordered:
            if gallery.gallery_id not in self._implicit_attempted:
                return gallery
        return None

    def _ensure_initial_gallery(self) -> None:
        """Mount the next leaf candidate for the implicit first preview.

        Returns:
            None: Only a few candidates are probed before leaving the tree usable.
        """
        if (not self._implicit_pending or self._selected_gallery_id is not None
                or self._initial_attempt_count >= PHOTOS_INITIAL_CANDIDATE_LIMIT):
            return
        gallery = self._first_gallery()
        if gallery is None:
            return
        self._catalogue_paused_for_preview = True
        self._initial_attempt_count += 1
        self._implicit_attempted.add(gallery.gallery_id)
        self._select_gallery(gallery, implicit=True)

    def _on_implicit_preview(
        self, gallery_id: int, has_photos: bool | None,
    ) -> None:
        """Advance the implicit choice if its first preview has no photos.

        Args:
            gallery_id (int): Gallery identity associated with the callback.
            has_photos (bool | None): Whether photos exist; None means a
                temporary failure that should preserve this selection.

        Returns:
            None: Explicit user choices are never replaced by this fallback.
        """
        if (not self._implicit_pending
                or self._selected_gallery_id != gallery_id):
            return
        if has_photos is True:
            self._implicit_pending = False
        elif has_photos is None:
            self._implicit_pending = False
        else:
            self._selected_gallery_id = None
            self._ensure_initial_gallery()
            if self._selected_gallery_id is None:
                self._implicit_pending = False
                self._selected_gallery_id = gallery_id
        if not self._implicit_pending and self._next_after is not None:
            self._catalogue_paused_for_preview = False
            self._continue_catalogue()

    def _render_tree(self, page_gallery_ids: list[int]) -> None:
        """Incrementally add a page and attach newly resolvable orphans.

        Args:
            page_gallery_ids (list[int]): IDs introduced or refreshed by the
                current validated catalogue page.

        Returns:
            None: Existing item state remains intact as later pages arrive.
        """
        self._rendering_tree = True
        candidates = set(page_gallery_ids)
        for gallery_id in page_gallery_ids:
            gallery = self._galleries[gallery_id]
            item_id = str(gallery_id)
            title = gallery.title or self._text("untitled")
            glyph = self._cache_glyph(gallery_id)
            label = ("  " if item_id in self._recording_rows else "") + (
                (glyph + "  " if glyph else "") + title
            )
            if self.tree.exists(item_id):
                self.tree.item(
                    item_id,
                    text=label,
                )
            else:
                self.tree.insert(
                    "",
                    self._ordered_index("", gallery_id),
                    iid=str(gallery_id),
                    text=label,
                    open=self._tree_state.get(gallery_id, self._expand_new_nodes),
                )
            candidates.update(self._children_by_parent.get(gallery_id, ()))
        for gallery_id in sorted(candidates):
            self._attach_gallery(gallery_id)
        if self._selected_gallery_id is not None:
            selected = str(self._selected_gallery_id)
            if self.tree.exists(selected):
                self.tree.selection_set(selected)
        self._rendering_tree = False
        self._update_recording_rows()

    def _ordered_index(self, parent_id: str, gallery_id: int) -> int:
        """Find the insertion index that orders siblings by gallery ID.

        Args:
            parent_id (str): Tree item ID, or empty string for the root.
            gallery_id (int): ID of the row being inserted or moved.

        Returns:
            int: Count of existing siblings with a smaller ID.
        """
        index = 0
        for sibling in self.tree.get_children(parent_id):
            if int(sibling) > gallery_id:
                break
            index += 1
        return index

    def _attach_gallery(self, gallery_id: int) -> None:
        """Place a known row under its available, cycle-safe parent.

        Args:
            gallery_id (int): Current gallery row to attach or keep at root.

        Returns:
            None: Missing parents and malformed cycles remain reachable.
        """
        gallery = self._galleries[gallery_id]
        item_id = str(gallery_id)
        parent_id = gallery.parent_id
        parent_item = (
            str(parent_id)
            if parent_id is not None and self.tree.exists(str(parent_id))
            else ""
        )
        ancestor = parent_item
        while ancestor:
            if ancestor == item_id:
                parent_item = ""
                break
            ancestor = self.tree.parent(ancestor)
        if self.tree.parent(item_id) == parent_item:
            return
        self.tree.move(
            item_id,
            parent_item,
            self._ordered_index(parent_item, gallery_id),
        )

    def _continue_catalogue(self) -> None:
        """Continue pagination or expose the manual action at the page cap.

        Returns:
            None: Automatic reads stop at the documented per-pass limit.
        """
        if self._next_after is None:
            return
        if self._pages_this_pass >= PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT:
            self.tree_status.configure(text="")
            self.load_more_button.pack(side="right")
            return
        self.load_more_button.pack_forget()
        self._request_page(self._next_after)

    def _load_more_catalogue(self) -> None:
        """Start another bounded pass from the saved server cursor.

        Returns:
            None: The server cursor and current tree remain authoritative.
        """
        if self._session is None or self._next_after is None:
            return
        self.load_more_button.pack_forget()
        self._pages_this_pass = 0
        self.tree_status.configure(text=self._text("loading"))
        self._continue_catalogue()

    def _all_items(self) -> list[str]:
        """Return every current tree item in parent-before-child order.

        Returns:
            list[str]: Tree item identifiers for the current in-memory catalog.
        """
        result: list[str] = []
        pending = list(self.tree.get_children())
        while pending:
            item = pending.pop()
            result.append(item)
            pending.extend(self.tree.get_children(item))
        return result

    def _set_all_open(self, opened: bool) -> None:
        """Expand or collapse all currently loaded gallery branches.

        Args:
            opened (bool): True to expand every loaded node, false to collapse.

        Returns:
            None: Tree expansion is purely local and makes no server request.
        """
        self._expand_new_nodes = opened
        for item in self._all_items():
            self.tree.item(item, open=opened)
        self._save_expansion_state()
        self._update_recording_rows()

    def set_watcher_recording(
        self, target: LinkedUploadTarget | None, pulse: bool,
    ) -> None:
        """Display the active Watcher target in this exact linked account.

        Args:
            target (LinkedUploadTarget | None): Active linked target, or None.
            pulse (bool): Whether the current animation phase is bright.

        Returns:
            None: Only previously and currently marked tree rows are updated.
        """
        self._watcher_target = target
        self._watcher_pulse = pulse
        self._update_recording_rows()

    def _recording_image(self, hollow: bool) -> tk.PhotoImage:
        """Return a small red indicator for the current pulse phase.

        Args:
            hollow (bool): Draw an outline for an obscured descendant.

        Returns:
            tk.PhotoImage: Retained Tk image for this phase and marker shape.
        """
        key = (hollow, self._watcher_pulse)
        image = self._recording_images.get(key)
        if image is None:
            image = tk.PhotoImage(
                master=self, width=PHOTOS_RECORDING_DOT_SIZE,
                height=PHOTOS_RECORDING_DOT_SIZE,
            )
            color = _PALETTE['recording' if self._watcher_pulse else 'recording_dim']
            center = PHOTOS_RECORDING_DOT_SIZE // 2
            for y in range(PHOTOS_RECORDING_DOT_SIZE):
                for x in range(PHOTOS_RECORDING_DOT_SIZE):
                    distance = (x - center) ** 2 + (y - center) ** 2
                    # Inner and outer radii describe this circle's geometry.
                    if distance <= center ** 2 and (not hollow or distance >= 7):
                        image.put(color, (x, y))
            self._recording_images[key] = image
        return image

    def _on_tree_expansion(self, _event: tk.Event) -> None:
        """Update the hidden-target indicator after Tk changes expansion.

        Args:
            _event (tk.Event): Tree open or close notification.

        Returns:
            None: Deferred work observes Tk's completed expansion change.
        """
        self.after_idle(self._save_expansion_state)
        self.after_idle(self._update_recording_rows)

    def _save_expansion_state(self) -> None:
        """Persist loaded rows while retaining states for later catalogue pages.

        Returns:
            None: The host saves the merged account-scoped preferences.
        """
        session = self._session
        if self._destroyed or session is None or self._save_tree_state is None:
            return
        for item in self._all_items():
            self._tree_state[int(item)] = bool(self.tree.item(item, "open"))
        self._save_tree_state(session, dict(self._tree_state))

    def _update_recording_rows(self) -> None:
        """Update markers for the exact target and collapsed hiding ancestors.

        Returns:
            None: Unrelated tree rows and selection remain untouched.
        """
        if self._destroyed:
            return
        target = self._watcher_target
        manager = self._manager
        matching = (
            target is not None and self._session is not None
            and manager is not None
            and manager.photos_site_identity(self._session)
                == (target.issuer, target.user_id)
        )
        markers: dict[str, bool] = {}
        if matching and target is not None:
            item = str(target.gallery_id)
            if self.tree.exists(item):
                markers[item] = False
                parent = self.tree.parent(item)
                while parent:
                    if not self.tree.item(parent, 'open'):
                        markers[parent] = True
                    parent = self.tree.parent(parent)
        for item in self._recording_rows | set(markers):
            if self.tree.exists(item):
                image = self._recording_image(markers[item]) if item in markers else ''
                was_marked = item in self._recording_rows
                is_marked = item in markers
                if was_marked != is_marked:
                    text = str(self.tree.item(item, 'text'))
                    text = '  ' + text if is_marked else text.removeprefix('  ')
                    self.tree.item(item, image=image, text=text)
                else:
                    self.tree.item(item, image=image)
        self._recording_rows = set(markers)
        selected_target = matching and target is not None and (
            self._selected_gallery_id == target.gallery_id
        )
        self._recording_heading.configure(
            image=self._recording_image(False) if selected_target else '',
        )

    def _retry_catalogue(self) -> None:
        """Retry the current failed page through its last cursor.

        Returns:
            None: The existing session and page boundary remain authoritative.
        """
        if self._session is None:
            return
        self.retry_button.pack_forget()
        self.tree_status.configure(text=self._text("loading"))
        self._request_page(self._next_after)

    def _on_gallery_selected(self, _event: tk.Event) -> None:
        """Mount the selected gallery in the embedded viewer pane.

        Args:
            _event (tk.Event): Tree selection event owned by Tk.

        Returns:
            None: Same-session selection replaces the previous viewer frame.
        """
        if self._rendering_tree:
            return
        selection = self.tree.selection()
        if not selection:
            return
        gallery = self._galleries.get(int(selection[0]))
        if (gallery is not None
                and gallery.gallery_id != self._selected_gallery_id):
            self._dismiss_context(restore=False)
            self._select_gallery(gallery)

    def _select_gallery(
        self, gallery: LinkedGallery, *, implicit: bool = False,
    ) -> None:
        """Mount one exact gallery descriptor against the active session.

        Args:
            gallery (LinkedGallery): Current server gallery row selected in tree.
            implicit (bool): Whether this is an automatic first-preview attempt.

        Returns:
            None: The previous bounded viewer state is destroyed before mounting.
        """
        session = self._session
        if session is None:
            return
        self._selected_gallery_id = gallery.gallery_id
        self.add_child_button.configure(state="normal")
        title = ''.join(char if char.isprintable() else ' ' for char in gallery.title)
        self._gallery_title.set(title.strip() or self._text("untitled"))
        self._update_recording_rows()
        if not implicit:
            self._implicit_pending = False
            if self._catalogue_paused_for_preview:
                self._catalogue_paused_for_preview = False
                if self._next_after is not None:
                    self._request_page(self._next_after)
        self.tree.selection_set(str(gallery.gallery_id))
        if not implicit:
            self.tree.see(str(gallery.gallery_id))
        if self._viewer is not None:
            self._viewer.destroy()
            self._viewer = None
        self.viewer_status.pack_forget()
        try:
            self._viewer = mount_native_gallery_view(
                self.viewer_pane,
                session,
                gallery,
                self._language,
                show_title=False,
                thumbnail_memory=self._thumbnail_memory,
                context_menu=lambda photo, event: self._viewer_context(
                    gallery.gallery_id, photo, event,
                ),
                preview_result=(
                    lambda has_photos: self._on_implicit_preview(
                        gallery.gallery_id,
                        has_photos,
                    )
                ) if implicit else None,
            )
            controller = self._viewer._gallery_viewer_controller
            controller.on_photos_changed = lambda: self._photos_changed(
                gallery.gallery_id,
            )
            self._viewer.pack(fill="both", expand=True)
        except tk.TclError:
            self.viewer_status.configure(text=self._text("error"))
            self.viewer_status.pack(fill="x", padx=8, pady=8)
            if implicit:
                self._on_implicit_preview(gallery.gallery_id, False)
        self._sync_subgalleries()

    def _sync_subgalleries(self) -> None:
        """Project the selected gallery's direct children into its folder strip.

        Returns:
            None: The default-on switch controls only the folder cards.
        """
        parent_id = self._selected_gallery_id
        children = tuple(
            self._galleries[child_id]
            for child_id in sorted(self._children_by_parent.get(parent_id, ()))
            if child_id != parent_id
        )
        visible = bool(children) and self._show_subgalleries.get()
        if self._viewer is not None:
            self._viewer._gallery_viewer_controller.set_folder_shelf_visible(visible)
        if not visible:
            self._folder_shelf.pack_forget()
            self._folder_shelf.show_children(self._session, parent_id, ())
            return
        self._folder_shelf.show_children(self._session, parent_id, children)
        if not self._folder_shelf.winfo_manager():
            self._folder_shelf.pack(fill="x", padx=8, after=self._gallery_heading)

    def _open_subgallery(self, gallery_id: int) -> None:
        """Open a folder card using the existing tree navigation path.

        Args:
            gallery_id (int): Exact server identity represented by the card.

        Returns:
            None: A current direct child can replace the selected gallery.
        """
        gallery = self._galleries.get(gallery_id)
        if (gallery is not None and gallery.parent_id == self._selected_gallery_id
                and gallery_id != self._selected_gallery_id):
            self._dismiss_context(restore=False)
            self._select_gallery(gallery)

    def _dismiss_context(self, *, restore: bool = True) -> None:
        """Close the inline card and release the owning window's bindings.

        Args:
            restore (bool): Whether to return focus to the surviving trigger.

        Returns:
            None: Other workspace controls remain available.
        """
        if self._probe_cancel is not None:
            self._probe_cancel.set()
            self._probe_cancel = None
        if self._context_focus_check_id is not None:
            try:
                self.after_cancel(self._context_focus_check_id)
            except tk.TclError:
                pass
            self._context_focus_check_id = None
        card, origin = self._context_card, self._context_origin
        self._context_card = self._context_origin = None
        for sequence, binding in self._context_bindings:
            try:
                self.winfo_toplevel().unbind(sequence, binding)
            except tk.TclError:
                pass
        self._context_bindings.clear()
        top = self.winfo_toplevel()
        for widget in self._context_tagged_widgets:
            try:
                tags = widget.bindtags()
                if self._context_bindtag in tags:
                    widget.bindtags(tuple(
                        tag for tag in tags if tag != self._context_bindtag
                    ))
            except tk.TclError:
                pass
        self._context_tagged_widgets.clear()
        if self._context_click_binding is not None:
            try:
                top.unbind_class(self._context_bindtag, "<ButtonPress>")
                top._root().deletecommand(self._context_click_binding)
            except tk.TclError:
                pass
            self._context_click_binding = None
        if self._context_focus_binding is not None:
            try:
                top.unbind_class(self._context_bindtag, "<FocusOut>")
                top._root().deletecommand(self._context_focus_binding)
            except tk.TclError:
                pass
            self._context_focus_binding = None
        if card is not None:
            card.destroy()
        if restore and origin is not None and origin.winfo_exists():
            origin.focus_set()

    def _context_outside(self, event: tk.Event) -> None:
        """Dismiss on a press outside the current contextual card.

        Args:
            event (tk.Event): Main-window pointer press and target widget.

        Returns:
            None: The original press can continue to its target.
        """
        if getattr(event, "num", None) in (4, 5):
            return  # X11 represents wheel motion as button events.
        card = self._context_card
        if card is not None and not (
            event.widget is card or str(event.widget).startswith(str(card) + '.')
        ):
            focused = self.focus_get()
            self._dismiss_context(restore=(
                focused is card or str(focused).startswith(str(card) + '.')
            ))

    def _bind_context_clicks(self) -> None:
        """Place a dismiss binding before child widget bindtags.

        Returns:
            None: Clicks outside the card dismiss it before child handlers run.
        """
        top = self.winfo_toplevel()
        self._context_click_binding = top.bind_class(
            self._context_bindtag, "<ButtonPress>",
            self._context_outside, add="+",
        )
        self._context_focus_binding = top.bind_class(
            self._context_bindtag, "<FocusOut>",
            self._context_focus_out, add="+",
        )

        def tag_widget(widget: tk.Misc) -> None:
            """Place context dismissal before a mapped widget's own actions.

            Args:
                widget (tk.Misc): Current or newly mapped widget in the window.

            Returns:
                None: The widget receives at most one temporary context tag.
            """
            if self._context_card is None or not isinstance(widget, tk.Misc):
                return
            self._tag_context_widget(widget)

        pending: list[tk.Misc] = [top]
        while pending:
            widget = pending.pop()
            if widget.winfo_viewable():
                tag_widget(widget)
                pending.extend(widget.winfo_children())
        binding = top.bind("<Map>", lambda event: tag_widget(event.widget), add="+")
        self._context_bindings.append(("<Map>", binding))

    def _tag_context_widget(self, widget: tk.Misc) -> None:
        """Place context dismissal before one widget's default event handlers.

        Args:
            widget (tk.Misc): Existing mapped control or active-card child.

        Returns:
            None: The widget observes outside presses and focus changes first.
        """
        try:
            tags = widget.bindtags()
            if self._context_bindtag not in tags:
                widget.bindtags((self._context_bindtag, *tags))
                self._context_tagged_widgets.append(widget)
        except tk.TclError:
            return

    def _context_focus_out(self, _event: tk.Event) -> None:
        """Check after focus transitions whether the card lost focus.

        Args:
            _event (tk.Event): Focus change delivered by the owning toplevel.

        Returns:
            None: Focus moving between controls inside the card keeps it open.
        """
        if (self._context_card is not None
                and self._context_focus_check_id is None):
            self._context_focus_check_id = self.after_idle(
                self._check_context_focus,
            )

    def _check_context_focus(self) -> None:
        """Dismiss the card when focus settles outside its descendants.

        Returns:
            None: External keyboard focus closes the current context card.
        """
        self._context_focus_check_id = None
        card = self._context_card
        focused = self.focus_get()
        if card is not None and not (
            focused is card
            or focused is not None
            and str(focused).startswith(str(card) + '.')
        ):
            self._dismiss_context(restore=False)

    def _open_context(self, event: tk.Event) -> GlassCard:
        """Place a compact inline surface at the pointer inside Photos.

        Args:
            event (tk.Event): Trigger widget and screen coordinates.

        Returns:
            GlassCard: The new current contextual surface.
        """
        self._dismiss_context(restore=False)
        card = GlassCard(self, padding=8, takefocus=True)
        self._context_card, self._context_origin = card, event.widget
        self._context_xy = (
            event.x_root - self.winfo_rootx(), event.y_root - self.winfo_rooty(),
        )
        self._bind_context_clicks()
        for sequence, callback in (
            ("<Escape>", self._context_escape),
            ("<FocusOut>", self._context_focus_out),
        ):
            binding = self.winfo_toplevel().bind(sequence, callback, add="+")
            self._context_bindings.append((sequence, binding))
        card.bind("<Configure>", lambda _event: self._place_context(card), add="+")
        card.bind("<Map>", lambda _event: card.focus_set(), add="+")
        self._place_context(card)
        self._tag_context_widget(card)
        return card

    def _context_escape(self, _event: tk.Event) -> str:
        """Close the inline surface before other Escape actions.

        Args:
            _event (tk.Event): Main-window Escape event.

        Returns:
            str: Tk break marker after restoring focus.
        """
        self._dismiss_context()
        return "break"

    def _place_context(self, card: GlassCard | None) -> None:
        """Clamp the current card within the Photos viewport after content changes.

        Args:
            card (GlassCard | None): The current surface, if one exists.

        Returns:
            None: The panel stays visible at desktop and narrow window widths.
        """
        if card is None or card is not self._context_card:
            return
        x, y = self._context_xy
        width, height = card.winfo_reqwidth(), card.winfo_reqheight()
        card.place(
            x=max(0, min(x, self.winfo_width() - width)),
            y=max(0, min(y, self.winfo_height() - height)),
        )
        card.lift()

    def _context_content(self, card: GlassCard, title: str) -> None:
        """Replace the contents of a card with its current heading or status.

        Args:
            card (GlassCard): Current contextual card.
            title (str): Localized heading or status message.

        Returns:
            None: A wrapped heading and keyboard focus remain inside the surface.
        """
        card.focus_set()
        for child in card.winfo_children():
            child.destroy()
        ttk.Label(
            card, text=title, style="Card.TLabel",
            wraplength=min(PHOTOS_TREE_WIDTH, self.winfo_width()),
        ).pack(fill="x")
        self._tag_context_widget(card.winfo_children()[-1])

    def _context_action(
        self, card: GlassCard, text: str, command: Callable[[], None], *,
        destructive: bool = False,
    ) -> ttk.Button:
        """Add a compact themed action to the active card.

        Args:
            card (GlassCard): Contextual surface receiving the action.
            text (str): Localized action label.
            command (Callable[[], None]): Action invoked on the Tk thread.
            destructive (bool): Use the theme danger text for a permanent action.

        Returns:
            ttk.Button: Focusable action control.
        """
        button = ttk.Button(
            card, text=text, command=command,
            style=(
                'Danger.Action.Photos.Icon.TButton' if destructive
                else 'Action.Photos.Icon.TButton'
            ),
        )
        button.pack(fill="x", pady=(4, 0))
        self._tag_context_widget(button)
        return button

    def _run_context(
        self, card: GlassCard, operation: Callable[[], Callable[[], None]],
        failure: Callable[[], None], *, survive_view_change: bool = False,
    ) -> None:
        """Run one Photos context operation asynchronously on its exact session.

        Args:
            card (GlassCard): Contextual surface that initiated the work.
            operation (Callable[[], Callable[[], None]]): Worker returning a Tk
                callback for its validated result.
            failure (Callable[[], None]): Tk callback for an unavailable result.
            survive_view_change (bool): Keep terminal reconciliation bound to the
                captured session when another gallery is selected meanwhile.

        Returns:
            None: Admits at most one concurrent context operation.
        """
        if self._context_busy or self._session_provider() is not self._session:
            return
        self._context_content(card, self._text("working"))
        self._place_context(card)
        self._context_busy = True
        generation = None if survive_view_change else self._generation

        def work() -> None:
            """Queue the completion without touching Tk from the worker.

            Returns:
                None: Exactly one success or failure reaches the workspace queue.
            """
            try:
                completion = operation()
            except Exception:
                completion = failure
            self._context_results.put((generation, completion))

        worker = threading.Thread(target=work, name="photos-context", daemon=True)
        try:
            worker.start()
        except RuntimeError:
            self._context_busy = False
            failure()
            return
        self._schedule_poll()

    def _toggle_photo_selection(self, gallery_id: int, image_id: int) -> None:
        """Toggle one visible photo selection without opening its lightbox.

        Args:
            gallery_id (int): Gallery owning the current tile.
            image_id (int): Exact photo ID represented by that tile.

        Returns:
            None: Selection remains local to the current viewer instance.
        """
        controller = getattr(
            self._viewer, '_gallery_viewer_controller', None,
        )
        if (controller is None or gallery_id != self._selected_gallery_id
                or self._session is None):
            return
        controller.toggle_photo_selection(image_id)
        self._dismiss_context()

    def _confirm_photo_delete(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        image_ids: tuple[int, ...],
    ) -> None:
        """Show one anchored confirmation for the exact permanent photo set.

        Args:
            card (GlassCard): Contextual card anchored at the clicked tile.
            session (LinkingSession): Exact session whose delete scope is required.
            gallery_id (int): Exact gallery for the captured image IDs.
            image_ids (tuple[int, ...]): Unique exact IDs to delete after consent.

        Returns:
            None: The destructive request starts only from the final button.
        """
        if (not image_ids or len(image_ids) > NATIVE_MEDIA_DELETE_BATCH_MAX
                or self._session is not session
                or self._selected_gallery_id != gallery_id
                or not session.can_delete_admin_media()):
            return
        gallery = self._galleries.get(gallery_id)
        gallery_name = ' '.join(
            ''.join(char if char.isprintable() else ' ' for char in gallery.title).split()
        ) if gallery is not None else str(gallery_id)
        self._context_content(
            card, self._text('delete_confirm').format(
                count=len(image_ids), gallery=gallery_name,
            ),
        )
        self._context_action(
            card, self._text('cancel'), self._dismiss_context,
        )
        self._context_action(
            card, self._text('delete_permanently'),
            lambda: self._delete_photos(
                card, session, gallery_id, image_ids,
            ),
            destructive=True,
        )
        self._place_context(card)

    def _delete_photos(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        image_ids: tuple[int, ...],
    ) -> None:
        """Send one confirmed batch and reconcile its exact terminal outcome.

        Args:
            card (GlassCard): Confirmation card that initiated deletion.
            session (LinkingSession): Exact live site session captured by Photos.
            gallery_id (int): Exact physical gallery ID.
            image_ids (tuple[int, ...]): Exact confirmed photo IDs, in gallery order.

        Returns:
            None: The single server action is never automatically replayed.
        """
        def operation() -> Callable[[], None]:
            """Perform the one-shot deletion away from the Tk thread.

            Returns:
                Callable[[], None]: UI reconciliation for the captured operation.
            """
            deleted = session.delete_media_photos(gallery_id, image_ids)

            def complete() -> None:
                """Refresh only the still-mounted owner of the deleted gallery.

                Returns:
                    None: Confirmed IDs leave selection and metadata reloads in place.
                """
                if (self._session is not session
                        or self._session_provider() is not session):
                    return
                self._thumbnail_memory.remove_photos(gallery_id, deleted)
                controller = getattr(
                    self._viewer, '_gallery_viewer_controller', None,
                )
                if (self._selected_gallery_id == gallery_id and controller is not None):
                    controller.clear_photo_selection(deleted)
                    controller.refresh_photos()
                    controller.status.pack(anchor='w', pady=(4, 8))
                    controller.status.configure(text=self._text('delete_complete'))
                if self._context_card is card:
                    self._dismiss_context()

            return complete

        def failure() -> None:
            """Surface an ambiguous outcome without inviting request replay.

            Returns:
                None: The current gallery reloads to inspect server state.
            """
            def report() -> None:
                """Show uncertainty and request a passive current-gallery refresh.

                Returns:
                    None: The user must inspect the reloaded gallery before acting.
                """
                if self._session is not session:
                    return
                controller = getattr(
                    self._viewer, '_gallery_viewer_controller', None,
                )
                if (self._selected_gallery_id == gallery_id and controller is not None):
                    controller.status.pack(anchor='w', pady=(4, 8))
                    controller.status.configure(text=self._text('delete_uncertain'))
                    controller.refresh_photos()
                if self._context_card is card:
                    self._context_content(card, self._text('delete_uncertain'))
                    self._place_context(card)
            report()

        self._run_context(
            card, operation, failure, survive_view_change=True,
        )

    def _tree_context(self, event: tk.Event) -> str | None:
        """Open a gallery-row menu or the blank tree's root creation action.

        Args:
            event (tk.Event): Secondary pointer press in the gallery tree.

        Returns:
            str | None: Tk break for a handled Photos context.
        """
        item = self.tree.identify_row(event.y)
        return self._gallery_context(
            int(item) if item else None, event, allow_root=True,
        )

    def _viewer_context(
        self, gallery_id: int, photo: LinkedPhoto | None, event: tk.Event,
    ) -> str | None:
        """Dispatch an exact photo or empty-grid context.

        Args:
            gallery_id (int): Gallery owning the currently mounted grid.
            photo (LinkedPhoto | None): Exact clicked photo, or empty space.
            event (tk.Event): Secondary pointer press in the grid.

        Returns:
            str | None: Tk break for a handled Photos context.
        """
        session = self._session
        if session is None or self._selected_gallery_id != gallery_id:
            return None
        if photo is None:
            return self._gallery_context(gallery_id, event, creation_only=True)
        controller = getattr(
            self._viewer, '_gallery_viewer_controller', None,
        )
        selected_ids = (
            controller.selected_photo_ids() if controller is not None else ()
        )
        card = self._open_context(event)
        can_create = self._manager is not None and session.can_create_gallery()
        self._context_content(card, self._text("photo_menu"))
        if controller is not None:
            is_selected = photo.image_id in selected_ids
            self._context_action(
                card,
                self._text('deselect_photo' if is_selected else 'select_photo'),
                lambda: self._toggle_photo_selection(
                    gallery_id, photo.image_id,
                ),
            )
        if session.can_delete_admin_media():
            delete_ids = (
                selected_ids
                if photo.image_id in selected_ids else (photo.image_id,)
            )
            if len(delete_ids) <= NATIVE_MEDIA_DELETE_BATCH_MAX:
                delete_label = (
                    self._text('delete_selected').format(count=len(delete_ids))
                    if photo.image_id in selected_ids
                    else self._text('delete_photo')
                )
                self._context_action(
                    card, delete_label,
                    lambda: self._confirm_photo_delete(
                        card, session, gallery_id, delete_ids,
                    ),
                )
        self._context_action(
            card, self._text("photo_info"),
            lambda: self._photo_info(card, photo),
        )
        if can_create:
            self._add_gallery_creation_action(card, session, gallery_id)
        if photo.visibility is not None and session.can_change_photo_visibility():
            target = "private" if photo.visibility == "public" else "public"
            self._context_action(
                card, "👁 " + self._text("visibility_" + target),
                lambda: self._write_photo_visibility(
                    card, session, gallery_id, photo, target,
                ),
            )
        elif self._manager is not None and not session.can_read_admin_media():
            self._context_action(
                card, self._text("approve_admin"), self._approve_admin,
            )
        self._place_context(card)
        return "break"

    def _gallery_context(
        self, gallery_id: int | None, event: tk.Event, *,
        creation_only: bool = False, allow_root: bool = False,
    ) -> str | None:
        """Open the exact gallery's actions, or root creation on blank tree.

        Args:
            gallery_id (int | None): Target gallery ID, or web root.
            event (tk.Event): Pointer event anchoring the contextual card.
            creation_only (bool): Whether the trigger was blank photo-grid space.
            allow_root (bool): Whether a tree-row menu also offers root creation.

        Returns:
            str | None: Tk break for a handled Photos context.
        """
        session = self._session
        if session is None:
            return None
        can_create = self._manager is not None and session.can_create_gallery()
        card = self._open_context(event)
        title = (
            "root_menu" if gallery_id is None
            else "subgallery_menu" if creation_only else "gallery_menu"
        )
        heading = self._text(title)
        if not can_create:
            heading += "\n" + self._text("create_requires_approval")
        self._context_content(card, heading)
        if gallery_id is not None and not creation_only:
            self._gallery_info(card, session, gallery_id)
            if session.can_change_gallery_visibility():
                self._context_action(
                    card, "👁 " + self._text("visibility"),
                    lambda: self._gallery_visibility(card, session, gallery_id),
                )
            if self._manager is not None and not session.can_read_admin_media():
                self._context_action(
                    card, self._text("approve_admin"), self._approve_admin,
                )
        gallery = self._galleries.get(gallery_id) if gallery_id is not None else None
        if gallery is not None:
            self._add_host_gallery_actions(card, session, gallery)
        self._add_gallery_creation_action(card, session, gallery_id)
        if gallery_id is not None and allow_root:
            self._add_gallery_creation_action(card, session, None)
        self._place_context(card)
        return "break"

    def selected_watcher_gallery(
        self,
    ) -> tuple[LinkingSession, LinkedGallery] | None:
        """Return the selected gallery for the current linked session.

        Returns:
            tuple[LinkingSession, LinkedGallery] | None: Exact current session
                and selected physical gallery, or None when selection is stale.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        if (session is None or gallery_id is None
                or self._session_provider() is not session):
            return None
        gallery = self._galleries.get(gallery_id)
        return (session, gallery) if gallery is not None else None

    def open_selected_watcher_context(self, event: tk.Event) -> str | None:
        """Open Watcher actions from an external rail using the shared card.

        Args:
            event (tk.Event): Pointer event from the Watcher rail trigger.

        Returns:
            str | None: Tk break for a handled selected-gallery context.
        """
        current = self.selected_watcher_gallery()
        if current is None or not current[0].can_upload():
            return None
        session, gallery = current
        card = self._open_context(event)
        self._context_content(card, self._text("watcher_menu"))
        if self._start_watcher is not None:
            self._context_action(
                card, self._text("start_watcher"),
                lambda: self._launch_watcher(card, session, gallery),
            )
        if (self._simbrief_action is not None
                and self._simbrief_available is not None
                and self._simbrief_available()):
            self._context_action(
                card, self._text("simbrief"),
                lambda: self._launch_simbrief(card),
            )
        self._place_context(card)
        return "break"

    def _add_host_gallery_actions(
        self, card: GlassCard, session: LinkingSession, gallery: LinkedGallery,
    ) -> None:
        """Add host-owned gallery actions when their callbacks are available.

        Args:
            card (GlassCard): Exact gallery's active context card.
            session (LinkingSession): Exact live session for this gallery.
            gallery (LinkedGallery): Physical row represented by this action.

        Returns:
            None: Only explicitly authorized, configured host actions appear.
        """
        if self._start_watcher is not None and session.can_upload():
            self._context_action(
                card, self._text("start_watcher"),
                lambda: self._launch_watcher(card, session, gallery),
            )
        if (self._simbrief_action is not None
                and self._simbrief_available is not None
                and self._simbrief_available()):
            self._context_action(
                card, self._text("simbrief"),
                lambda: self._launch_simbrief(card),
            )

    def _launch_watcher(
        self, card: GlassCard, session: LinkingSession, gallery: LinkedGallery,
    ) -> None:
        """Dismiss the card and invoke the explicit gallery-scoped Watcher.

        Args:
            card (GlassCard): Context card owning the action.
            session (LinkingSession): Exact session captured at opening.
            gallery (LinkedGallery): Exact selected physical gallery.

        Returns:
            None: The host receives the target only while it remains current.
        """
        self._dismiss_context()
        if (self._start_watcher is not None and self._session is session
                and self._session_provider() is session and session.can_upload()):
            self._start_watcher(session, gallery)

    def _launch_simbrief(self, card: GlassCard) -> None:
        """Dismiss the card and invoke the current host SimBrief action.

        Args:
            card (GlassCard): Context card owning the action.

        Returns:
            None: The host controls the simulator panel lifecycle.
        """
        self._dismiss_context()
        if (self._simbrief_action is not None
                and self._simbrief_available is not None
                and self._simbrief_available()):
            self._simbrief_action()

    def _approve_admin(self) -> None:
        """Start the existing browser approval flow from the context card.

        Returns:
            None: The current selection remains until the session changes.
        """
        self._dismiss_context()
        if self._manager is not None:
            self._manager.approve_admin_photos()

    def _context_status(self, card: GlassCard, key: str) -> None:
        """Show the localized outcome while the originating card remains open.

        Args:
            card (GlassCard): Originating contextual surface.
            key (str): Localized status suffix.

        Returns:
            None: A dismissed card is never reopened by a worker.
        """
        if card is self._context_card:
            self._context_content(card, self._text(key))
            self._place_context(card)

    def _photo_info(self, card: GlassCard, photo: LinkedPhoto) -> None:
        """Fetch and show the selected photo's authorized metadata on demand.

        Args:
            card (GlassCard): Photo context surface.
            photo (LinkedPhoto): Exact photo row already authorized for the grid.

        Returns:
            None: Displays server details or safe local fallback information.
        """
        session = self._session
        gallery_id = self._selected_gallery_id
        if session is None or gallery_id is None:
            return
        cancel = threading.Event()
        self._probe_cancel = cancel

        def read() -> Callable[[], None]:
            """Fetch server metadata or build a local descriptor fallback.

            Returns:
                Callable[[], None]: Tk callback rendering validated photo data.
            """
            details: LinkedMediaDetails | None = None
            method = getattr(session, "media_photo_info", None)
            if callable(method):
                try:
                    details = method(
                        gallery_id, photo.image_id, cancelled=cancel,
                    )
                except Exception:
                    details = None
            fields = details or {
                "title": photo.title,
                "size": photo.size,
                "visibility": photo.visibility,
                "nsfw": photo.nsfw,
            }
            return lambda: self._show_details(
                card, self._text("photo_info"), fields,
                session=session,
                fallback=details is None,
            )

        self._run_context(
            card, read,
            lambda: self._show_details(
                card, self._text("photo_info"), {
                    "title": photo.title,
                    "size": photo.size,
                }, session=session, fallback=True,
            ),
        )

    def _cache_glyph(self, gallery_id: int) -> str:
        """Return the icon for the latest local coverage check.

        Args:
            gallery_id (int): Exact gallery being displayed.

        Returns:
            str: Cloud, partial circle, local circle or empty when unchecked.
        """
        return {"cloud": "☁", "local": "●", "partial": "◐"}.get(
            self._cache_state.get(gallery_id, "unknown"), "",
        )

    def _gallery_info(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Add an explicit action for fetching gallery information.

        Args:
            card (GlassCard): Current gallery context surface.
            session (LinkingSession): Exact authorized gallery session.
            gallery_id (int): Gallery whose direct photos should be checked.

        Returns:
            None: Server metadata is requested only after activation.
        """
        self._context_action(
            card, "i " + self._text("gallery_info"),
            lambda: self._fetch_gallery_info(card, session, gallery_id),
        )

    def _fetch_gallery_info(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Fetch gallery details and the existing local cache coverage on demand.

        Args:
            card (GlassCard): Context card that initiated the request.
            session (LinkingSession): Exact current linked session.
            gallery_id (int): Physical gallery identity.

        Returns:
            None: A detail card replaces the menu when the worker completes.
        """
        cancel = threading.Event()
        self._probe_cancel = cancel

        def read() -> Callable[[], None]:
            """Read validated detail fields and fallback inventory metadata.

            Returns:
                Callable[[], None]: Tk callback rendering this gallery's result.
            """
            details: LinkedMediaDetails | None = None
            method = getattr(session, "media_gallery_details", None)
            if callable(method):
                try:
                    details = method(gallery_id, cancelled=cancel)
                except Exception:
                    details = None
            try:
                info, state, visible = self._inspect_gallery_cache(
                    session, gallery_id, cancel,
                    include_admin_inventory=details is None,
                )
            except Exception:
                info, state, visible = None, "unknown", None
            return lambda: self._show_gallery_details(
                card, gallery_id, details, info, state, visible,
            )

        self._run_context(
            card, read,
            lambda: self._show_gallery_details(
                card, gallery_id, None, None, "unknown", None,
            ),
        )

    def _inspect_gallery_cache(
        self, session: LinkingSession, gallery_id: int, cancel: threading.Event,
        *, include_admin_inventory: bool = True,
    ) -> tuple[LinkedMediaGalleryInfo | None, str, int]:
        """Read direct metadata and inspect cache entries without media downloads.

        Args:
            session (LinkingSession): Exact authorized gallery session.
            gallery_id (int): Gallery whose authorized photos are inspected.
            cancel (threading.Event): Context dismissal and session change signal.
            include_admin_inventory (bool): Whether to use the legacy counts API.

        Returns:
            tuple[LinkedMediaGalleryInfo | None, str, int]: Optional Admin
                inventory, observed cache status and visible photo count.
        """
        info = (
            session.media_gallery_info(gallery_id, cancelled=cancel)
            if include_admin_inventory and session.can_read_admin_media()
            else None
        )
        after = None
        any_cached = False
        all_cached = True
        seen = 0
        for _page_index in range(PHOTOS_CACHE_PAGE_LIMIT):
            if cancel.is_set():
                break
            page = session.media_page(gallery_id, after, cancelled=cancel)
            for photo in page.photos:
                if cancel.is_set():
                    break
                detail = session.has_cached_photo(gallery_id, photo)
                thumb = session.has_cached_photo(gallery_id, photo, thumbnail=True)
                any_cached |= detail or thumb
                all_cached &= detail and thumb
                seen += 1
            after = page.next_after
            if after is None:
                break
        complete = (
            not cancel.is_set() and after is None
            and (info is None or seen == info.photo_count)
        )
        state = (
            "local" if complete and seen and all_cached
            else "partial" if any_cached
            else "cloud" if complete else "unknown"
        )
        return info, state, seen

    def _show_gallery_details(
        self, card: GlassCard, gallery_id: int,
        details: LinkedMediaDetails | None,
        info: LinkedMediaGalleryInfo | None, state: str,
        visible_count: int | None,
    ) -> None:
        """Render grouped gallery metadata with cache and legacy fallback.

        Args:
            card (GlassCard): Context card that requested the information.
            gallery_id (int): Exact gallery represented by the card.
            details (LinkedMediaDetails | None): Optional current server fields.
            info (LinkedMediaGalleryInfo | None): Legacy Admin inventory.
            state (str): Observed local-cache coverage state.
            visible_count (int | None): Visible count, or None on read error.

        Returns:
            None: The active card shows available fields and cache status.
        """
        if card is not self._context_card:
            return
        fields: LinkedMediaDetails = dict(details or {})
        if info is not None:
            fields.setdefault("photo_count", info.photo_count)
            fields.setdefault("file_count", info.file_count)
            fields.setdefault("size", info.size)
        if visible_count is not None:
            fields.setdefault("photo_count", visible_count)
        self._cache_state[gallery_id] = state
        if gallery_id in self._galleries:
            self._render_tree([gallery_id])
        groups = [
            ("photos_workspace_group_inventory", [
                ("photos_workspace_field_photo_count", fields.get("photo_count")),
                ("photos_workspace_field_file_count", fields.get("file_count")),
                ("photos_workspace_field_size", fields.get("size")),
            ]),
            ("photos_workspace_group_gallery", [
                ("photos_workspace_field_title", fields.get("title")),
                ("photos_workspace_field_description", fields.get("description")),
                ("photos_workspace_field_child_count", fields.get("child_count")),
                ("photos_workspace_field_created_at", fields.get("created_at")),
                ("photos_workspace_field_updated_at", fields.get("updated_at")),
            ]),
            ("photos_workspace_group_access", [
                ("photos_workspace_field_visibility", fields.get("visibility")),
                ("photos_workspace_field_nsfw", fields.get("nsfw")),
                ("photos_workspace_field_listed", fields.get("listed")),
            ]),
            ("photos_workspace_group_thumbnail", [
                ("photos_workspace_field_thumbnail_settings",
                 self._thumbnail_settings(fields)),
            ]),
        ]
        suffix = (self._text("cache_status") + ": "
                  + self._text("cache_" + state))
        if details is None:
            suffix += "\n" + self._text("details_fallback")
        if self._probe_cancel is not None:
            self._probe_cancel = None
        self._render_info_card(
            card, self._text("gallery_info"), groups,
            suffix=suffix,
        )

    def _show_details(
        self, card: GlassCard, title: str, fields: LinkedMediaDetails, *,
        session: LinkingSession, fallback: bool,
    ) -> None:
        """Render grouped photo metadata while protecting location information.

        Args:
            card (GlassCard): Context card that requested the information.
            title (str): Localized card title.
            fields (LinkedMediaDetails): Bounded scalar metadata from the server.
            session (LinkingSession): Current permission scope for sensitive rows.
            fallback (bool): Whether local descriptor fields were used.

        Returns:
            None: Only fields approved for the live scope are displayed.
        """
        if card is not self._context_card:
            return
        if self._probe_cancel is not None:
            self._probe_cancel = None
        file_rows: list[_InfoRow] = []
        description_rows: list[_InfoRow] = []
        date_rows: list[_InfoRow] = []
        camera_rows: list[_InfoRow] = []
        location_rows: list[_InfoRow] = []
        access_rows: list[_InfoRow] = []
        mapping: tuple[tuple[list[_InfoRow], str], ...] = (
            (file_rows, "title"), (file_rows, "filename"),
            (file_rows, "mime_type"), (file_rows, "size"),
            (file_rows, "width"), (file_rows, "height"),
            (file_rows, "display_width"), (file_rows, "display_height"),
            (file_rows, "exif_orientation"),
            (date_rows, "created_at"), (date_rows, "modified_at"),
            (date_rows, "exif_taken_at"),
            (camera_rows, "exif_camera_make"),
            (camera_rows, "exif_camera_model"),
            (camera_rows, "exif_lens_model"),
            (camera_rows, "exif_focal_length"),
            (camera_rows, "exif_aperture"),
            (camera_rows, "exif_exposure_time"), (camera_rows, "exif_iso"),
            (description_rows, "description"),
            (location_rows, "gps_lat"), (location_rows, "gps_lng"),
            (location_rows, "gps_altitude"),
            (access_rows, "visibility"), (access_rows, "nsfw"),
        )
        for rows, key in mapping:
            value = fields.get(key)
            if value is not None:
                rows.append(("photos_workspace_field_" + key, value))
        derivative = self._thumbnail_settings(fields)
        if derivative is not None:
            file_rows.append((
                "photos_workspace_field_thumbnail_settings", derivative,
            ))
        groups = [
            ("photos_workspace_group_file", file_rows),
            ("photos_workspace_group_description", description_rows),
            ("photos_workspace_group_dates", date_rows),
            ("photos_workspace_group_camera", camera_rows),
        ]
        if session.can_read_admin_media():
            groups.append(("photos_workspace_group_location", location_rows))
        groups.append(("photos_workspace_group_access", access_rows))
        self._render_info_card(
            card, title, groups,
            suffix=(self._text("details_fallback")
                    if fallback else ""),
        )

    def _thumbnail_settings(
        self, fields: LinkedMediaDetails,
    ) -> str | None:
        """Describe configured quality for future generated thumbnails.

        Args:
            fields (LinkedMediaDetails): Detail response with optional qualities.

        Returns:
            str | None: Explicit JPEG/WebP settings, or None when absent.
        """
        jpeg, webp = fields.get("jpeg_quality"), fields.get("webp_quality")
        if jpeg is None and webp is None:
            return None
        qualities = []
        if jpeg is not None:
            qualities.append("JPEG " + str(jpeg))
        if webp is not None:
            qualities.append("WebP " + str(webp))
        return " · ".join(qualities)

    def _render_info_card(
        self, card: GlassCard, title: str, groups: list[_InfoGroup], *,
        suffix: str = "",
    ) -> None:
        """Replace a context menu with compact, wrapped metadata rows.

        Args:
            card (GlassCard): Active anchored information surface.
            title (str): Localized heading.
            groups (list[_InfoGroup]): Ordered localized headings and rows.
            suffix (str): Optional cache state or compatibility note.

        Returns:
            None: Rows wrap to fit the current Photos workspace width.
        """
        if card is not self._context_card:
            return
        for child in card.winfo_children():
            child.destroy()
        width = max(160, min(
            PHOTOS_INFO_CARD_MAX_WIDTH - 40, self.winfo_width() - 60,
        ))
        body = ttk.Frame(card, style="Card.TFrame")
        canvas = tk.Canvas(
            body, highlightthickness=0, borderwidth=0,
            background=ttk.Style().lookup("Card.TLabel", "background"),
        )
        scrollbar = ttk.Scrollbar(body, orient="vertical", command=canvas.yview)
        canvas.configure(yscrollcommand=scrollbar.set)
        body.pack(fill="both", expand=True)
        canvas.pack(side="left", fill="both", expand=True)
        scrollbar.pack(side="right", fill="y")
        content = ttk.Frame(canvas, style="Card.TFrame", padding=(2, 1))
        content_id = canvas.create_window(
            (0, 0), window=content, anchor="nw", width=width,
        )
        for widget in (body, canvas, scrollbar, content):
            self._tag_context_widget(widget)

        def scroll_detail(event: tk.Event, *, precise: bool = False) -> None:
            """Scroll the metadata view by native wheel or touchpad input.

            Args:
                event (tk.Event): Native Tk wheel/precise event.
                precise (bool): Whether this is Tk's pixel delta event.

            Returns:
                None: Wheel motion affects only this card's metadata viewport.
            """
            delta = vertical_scroll_delta(self, event, precise=precise)
            if precise:
                bounds = canvas.bbox("all")
                if bounds is None:
                    return
                content_height = bounds[3] - bounds[1]
                if content_height > 0 and delta:
                    current = canvas.yview()[0]
                    canvas.yview_moveto(max(
                        0.0, min(1.0, current - delta / content_height),
                    ))
            elif delta:
                canvas.yview_scroll(-1 if delta > 0 else 1, "units")

        def bind_scroll(widget: tk.Misc) -> None:
            """Bind scroll input directly to each metadata row.

            Args:
                widget (tk.Misc): Row or canvas receiving pointer events.

            Returns:
                None: Tk 9 precise input is optional on older supported runtimes.
            """
            widget.bind("<MouseWheel>", scroll_detail, add="+")
            widget.bind("<Button-4>", scroll_detail, add="+")
            widget.bind("<Button-5>", scroll_detail, add="+")
            try:
                widget.bind(
                    "<TouchpadScroll>",
                    lambda event: scroll_detail(event, precise=True), add="+",
                )
            except tk.TclError:
                pass

        bind_scroll(canvas)
        ttk.Label(
            content, text=title, style="Card.TLabel", wraplength=width,
        ).pack(fill="x")
        self._tag_context_widget(content.winfo_children()[-1])
        bind_scroll(content.winfo_children()[-1])
        for heading, rows in groups:
            rows = [(key, value) for key, value in rows if value is not None]
            if not rows:
                continue
            ttk.Label(
                content,
                text=self._text(heading.removeprefix("photos_workspace_")),
                style="Muted.TLabel",
            ).pack(anchor="w", pady=(6, 1))
            self._tag_context_widget(content.winfo_children()[-1])
            bind_scroll(content.winfo_children()[-1])
            for key, value in rows:
                label = self._text(key.removeprefix("photos_workspace_"))
                rendered = self._format_detail_value(key, value)
                ttk.Label(
                    content, text=label + ": " + rendered,
                    style="Card.TLabel", wraplength=width,
                    justify="left",
                ).pack(fill="x", anchor="w")
                self._tag_context_widget(content.winfo_children()[-1])
                bind_scroll(content.winfo_children()[-1])
        if suffix:
            ttk.Label(
                content, text=suffix, style="Muted.TLabel",
                wraplength=width, justify="left",
            ).pack(fill="x", pady=(6, 0))
            self._tag_context_widget(content.winfo_children()[-1])
            bind_scroll(content.winfo_children()[-1])
        content.update_idletasks()
        def resize_content(_event: tk.Event) -> None:
            """Update scroll bounds after metadata rows change.

            Args:
                _event (tk.Event): Content frame geometry notification.

            Returns:
                None: The scrollbar follows the measured content height.
            """
            canvas.configure(scrollregion=canvas.bbox("all"))

        def resize_canvas(event: tk.Event) -> None:
            """Keep the content width matched to the viewport.

            Args:
                event (tk.Event): Canvas geometry notification.

            Returns:
                None: Wrapped values use the available narrow or wide width.
            """
            canvas.itemconfigure(content_id, width=event.width)

        content.bind("<Configure>", resize_content, add="+")
        canvas.bind("<Configure>", resize_canvas, add="+")
        content_height = content.winfo_reqheight() + 6
        maximum_height = min(
            PHOTOS_INFO_CARD_MAX_HEIGHT - 20,
            max(100, self.winfo_height() - 24),
            content_height,
        )
        body.configure(height=maximum_height, width=width + 22)
        body.pack_propagate(False)
        card.update_idletasks()
        card.focus_set()
        self._place_context(card)

    def _format_detail_value(self, key: str, value: str | int | float | bool) -> str:
        """Format validated scalar metadata for a readable localized row.

        Args:
            key (str): Stable metadata field key.
            value (str | int | float | bool): Validated scalar from the server.

        Returns:
            str: Safe readable value with byte sizes and visibility localized.
        """
        if key.endswith("_size") or key == "photos_workspace_field_size":
            if type(value) is int:
                return self._format_bytes(value)
        if isinstance(value, bool):
            return self._text("yes" if value else "no")
        if key.endswith("_gps_altitude") and isinstance(value, (int, float)):
            return f"{value:g} m"
        if key.endswith("_visibility"):
            visibility = "unpublished" if value == "draft" else str(value)
            return self._text("visibility_state_" + visibility)
        return "".join(char if char.isprintable() else " " for char in str(value))

    def _gallery_visibility(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Read the stored gallery state before showing explicit eye actions.

        Args:
            card (GlassCard): Originating gallery context card.
            session (LinkingSession): Exact authorized gallery session.
            gallery_id (int): Selected physical gallery ID.

        Returns:
            None: Shows choices only after a validated revision is available.
        """
        cancel = self._cancel

        def read() -> Callable[[], None]:
            """Read the current one-use gallery visibility snapshot.

            Returns:
                Callable[[], None]: Tk callback displaying the fresh choices.
            """
            detail = session.gallery_detail(gallery_id, cancelled=cancel)
            return lambda: self._show_gallery_visibility(card, session, detail)

        self._run_context(
            card, read, lambda: self._context_status(card, "info_unavailable"),
        )

    def _show_gallery_visibility(
        self, card: GlassCard, session: LinkingSession, detail: LinkedGalleryDetail,
    ) -> None:
        """Offer exact gallery visibility choices for a verified revision.

        Args:
            card (GlassCard): Originating gallery context surface.
            session (LinkingSession): Session that read the snapshot.
            detail (LinkedGalleryDetail): Exact revision and stored visibility.

        Returns:
            None: Available explicit actions replace the loading heading.
        """
        if card is not self._context_card:
            return
        current = "unpublished" if detail.visibility == "draft" else detail.visibility
        self._context_content(
            card, "👁 " + self._text("visibility_state_" + current),
        )
        cancel = self._cancel
        for choice in ("public", "private", "unpublished"):
            if choice == current:
                continue
            self._context_action(
                card, self._text("visibility_" + choice),
                lambda choice=choice: self._run_context(
                    card,
                    lambda: self._change_gallery(session, detail, choice, cancel, card),
                    lambda: self._visibility_done(card, detail.gallery_id, False),
                ),
            )
        self._place_context(card)

    def _change_gallery(
        self, session: LinkingSession, detail: LinkedGalleryDetail, choice: str,
        cancel: threading.Event, card: GlassCard,
    ) -> Callable[[], None]:
        """Submit a gallery visibility edit once against the reviewed state.

        Args:
            session (LinkingSession): Current linked session.
            detail (LinkedGalleryDetail): Previously presented exact snapshot.
            choice (str): Explicit target visibility.
            cancel (threading.Event): Workspace session lifetime signal.
            card (GlassCard): Originating card for the result.

        Returns:
            Callable[[], None]: Tk callback for a confirmed result.
        """
        session.change_gallery_visibility(detail, choice, cancelled=cancel)
        return lambda: self._visibility_done(card, detail.gallery_id, True)

    def _write_photo_visibility(
        self, card: GlassCard, session: LinkingSession, gallery_id: int,
        photo: LinkedPhoto, target: str,
    ) -> None:
        """Submit the selected photo's explicit visibility action.

        Args:
            card (GlassCard): Originating photo context surface.
            session (LinkingSession): Exact authorized gallery session.
            gallery_id (int): Direct owner of the clicked photo.
            photo (LinkedPhoto): Authorized photo row containing its current state.
            target (str): Explicit public or private target.

        Returns:
            None: One request runs asynchronously against the exact photo.
        """
        cancel = self._cancel

        def write() -> Callable[[], None]:
            """Request one revision-checked photo visibility change.

            Returns:
                Callable[[], None]: Tk callback for a confirmed result.
            """
            session.change_photo_visibility(gallery_id, photo, target, cancelled=cancel)
            return lambda: self._visibility_done(card, gallery_id, True)

        self._run_context(
            card, write, lambda: self._visibility_done(card, gallery_id, False),
        )

    def _visibility_done(self, card: GlassCard, gallery_id: int, saved: bool) -> None:
        """Refresh authoritative media after either a confirmed or uncertain edit.

        Args:
            card (GlassCard): Originating contextual surface.
            gallery_id (int): Exact owner of the submitted visibility action.
            saved (bool): Whether the server acknowledged and verified the edit.

        Returns:
            None: Keeps the gallery selection and avoids replaying the write.
        """
        previous_cache = self._cache_state
        self._cache_state = {}
        self._render_tree(list(previous_cache))
        self._thumbnail_memory.clear()
        self._thumbnail_memory = GalleryThumbnailMemory()
        self._folder_shelf.show_children(None, None, ())
        gallery = self._galleries.get(gallery_id)
        if gallery is not None and self._selected_gallery_id == gallery_id:
            if card is self._context_card:
                self._context_origin = self.tree
            self._select_gallery(gallery)
        else:
            self._sync_subgalleries()
        self._context_status(card, "saved" if saved else "visibility_uncertain")

    def _creation_form(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
    ) -> None:
        """Collect a gallery title inline for the explicitly chosen location.

        Args:
            card (GlassCard): Context surface for the action.
            session (LinkingSession): Exact active session with create consent.
            parent_id (int | None): Clicked parent gallery, or web root.

        Returns:
            None: Shows a title field or a pending acknowledgement.
        """
        if self._create_receipt is not None:
            self._creation_completed(card, session, self._create_receipt)
            return
        key = "create_root" if parent_id is None else "create_child"
        self._context_content(card, self._text(key))
        if parent_id is not None:
            parent = self._galleries[parent_id]
            ttk.Label(
                card, text=parent.title, style="Card.TLabel",
                wraplength=PHOTOS_TREE_WIDTH,
            ).pack(fill="x")
        entry = ttk.Entry(card)
        entry.pack(fill="x", pady=(4, 0))
        action = self._context_action(
            card, self._text("create"),
            lambda: self._create_gallery(card, session, parent_id, entry.get().strip()),
        )
        entry.bind("<Return>", lambda _event: action.invoke())
        self._place_context(card)
        entry.focus_set()

    def _create_gallery(
        self, card: GlassCard, session: LinkingSession, parent_id: int | None,
        title: str,
    ) -> None:
        """Journal one explicitly submitted creation in the background.

        Args:
            card (GlassCard): Originating name form.
            session (LinkingSession): Exact verified session at submission.
            parent_id (int | None): Chosen parent ID, or root.
            title (str): Entered physical gallery title.

        Returns:
            None: Creation completion or recovery is presented inline.
        """
        manager = self._manager
        if not title or manager is None:
            return

        def create() -> Callable[[], None]:
            """Run the original creation through the shared journal owner.

            Returns:
                Callable[[], None]: Tk callback for the bound completed receipt.
            """
            receipt = manager.create_photos_gallery(session, title, parent_id)
            return lambda: self._creation_completed(card, session, receipt)

        self._run_context(card, create, lambda: self._creation_recovery(card))

    def _creation_completed(
        self, card: GlassCard, session: LinkingSession, receipt: BoundCreateReceipt,
    ) -> None:
        """Insert the confirmed gallery and request explicit receipt acknowledgement.

        Args:
            card (GlassCard): Originating context, if still open.
            session (LinkingSession): Exact link that completed the creation.
            receipt (BoundCreateReceipt): Validated gallery and original intent.

        Returns:
            None: The first root becomes active; existing selection is preserved.
        """
        self._create_receipt = receipt
        gallery = receipt.gallery
        had_selection = self._selected_gallery_id is not None
        self._galleries[gallery.gallery_id] = gallery
        if gallery.parent_id is not None:
            self._children_by_parent.setdefault(
                gallery.parent_id, set(),
            ).add(gallery.gallery_id)
            parent = str(gallery.parent_id)
            if self.tree.exists(parent):
                self.tree.item(parent, open=True)
        self._render_tree([gallery.gallery_id])
        self._sync_subgalleries()
        if not had_selection and gallery.parent_id is None:
            self.tree_status.configure(text="")
            self._select_gallery(gallery)
        if card is self._context_card:
            self._context_content(
                card, self._text("created").format(title=gallery.title),
            )
            self._context_action(
                card, "OK", lambda: self._acknowledge_creation(card, session, receipt),
            ).focus_set()
            self._place_context(card)

    def _acknowledge_creation(
        self, card: GlassCard, session: LinkingSession, receipt: BoundCreateReceipt,
    ) -> None:
        """Clear the exact receipt only after the user acknowledges the result.

        Args:
            card (GlassCard): Receipt confirmation surface.
            session (LinkingSession): Session bound to the original result.
            receipt (BoundCreateReceipt): Exact completed journal receipt.

        Returns:
            None: Acknowledgement runs without changing the upload target.
        """
        manager = self._manager
        if manager is None:
            return

        def acknowledge() -> Callable[[], None]:
            """Clear the matching receipt through the shared journal.

            Returns:
                Callable[[], None]: Tk callback clearing the acknowledged result.
            """
            manager.acknowledge_photos_gallery(session, receipt)
            return lambda: self._creation_acknowledged(card)

        self._run_context(card, acknowledge, lambda: self._creation_recovery(card))

    def _creation_acknowledged(self, card: GlassCard) -> None:
        """Release the confirmed receipt after successful journal cleanup.

        Args:
            card (GlassCard): Receipt confirmation surface.

        Returns:
            None: A later create can submit a new independent operation.
        """
        self._create_receipt = None
        if card is self._context_card:
            self._dismiss_context()

    def _creation_recovery(self, card: GlassCard) -> None:
        """Offer the established recovery surface for an unconfirmed operation.

        Args:
            card (GlassCard): Originating creation or receipt confirmation card.

        Returns:
            None: The operation remains journalled for review.
        """
        if card is not self._context_card:
            return
        self._context_content(card, self._text("creation_uncertain"))
        if self._manage_link is not None:
            self._context_action(card, self._text("manage"), self._open_creation_recovery)
        self._place_context(card)

    def _open_creation_recovery(self) -> None:
        """Return receipt ownership to the established management surface.

        Returns:
            None: Management can review and resolve the same persisted journal.
        """
        if self._manage_link is not None:
            self._create_receipt = None
            self._dismiss_context()
            self._manage_link()

    def _on_destroy(self, event: tk.Event) -> None:
        """Cancel page work and release the embedded viewer on destruction.

        Args:
            event (tk.Event): Widget destruction event for this frame or child.

        Returns:
            None: Cleanup runs only when the workspace itself is destroyed.
        """
        if event.widget is not self or self._destroyed:
            return
        self._destroyed = True
        self._dismiss_context(restore=False)
        self._generation += 1
        self._cancel.set()
        if self._poll_id is not None:
            try:
                self.after_cancel(self._poll_id)
            except tk.TclError:
                pass
        if self._viewer is not None:
            self._viewer.destroy()
            self._viewer = None
        self._galleries.clear()
        self._thumbnail_memory.clear()
