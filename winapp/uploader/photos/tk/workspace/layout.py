# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/photos/tk/workspace/layout.py
# Module Type: Windows Client UI mixin
# Purpose: Own a cohesive Photos workspace interaction domain.
# Responsibilities: Preserve PhotosWorkspace lifecycle and callback behavior.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Photos workspace behavior mixin."""

from .support import (
    Button,
    CataloguePerformanceWindow,
    FOLDER_DIVIDER_HIT_HEIGHT,
    GalleryCatalogueSnapshot,
    GalleryFolderShelf,
    GalleryThumbnailMemory,
    GlassCard,
    LINKING_GALLERY_ID_MAX,
    LinkingMediaThrottled,
    LinkingResponseError,
    LinkingSession,
    METRIC_ICON_SIZE,
    MetricCard,
    PHOTOS_CARD_FORMAT_ICON_SIZE,
    PHOTOS_CATALOGUE_CACHE_BATCH_SIZE,
    PHOTOS_CATALOGUE_PROGRESS_INTERVAL_MS,
    PHOTOS_CATALOGUE_SPINNER_FRAME_COUNT,
    PHOTOS_CATALOG_POLL_MS,
    PHOTOS_DIAGNOSTIC_MAX_CHARS,
    PHOTOS_HEADING_STACK_WIDTH,
    PHOTOS_HEADING_TITLE_MINIMUM,
    PHOTOS_INFO_PANE_HEADER_INSET,
    PHOTOS_INFO_PANE_WIDTH,
    PHOTOS_INFO_VIEWER_MINIMUM_WIDTH,
    PHOTOS_INFO_WIDTH_BOUNDS,
    PHOTOS_INFO_WIDTH_SAVE_DELAY_MS,
    PHOTOS_LEGACY_NOTICE_TEXT_WIDTH,
    PHOTOS_PRELOAD_CONTROL_GAP,
    PHOTOS_TREE_WIDTH,
    PHOTOS_TREE_WIDTH_SAVE_DELAY_MS,
    PHOTO_CARD_FORMATS,
    ThemeImage,
    _CatalogueCacheResult,
    _CataloguePageResult,
    _CatalogueTreeResult,
    _PALETTE,
    _log_safe_media_failure,
    attach_inline_tooltip,
    create_smooth_icon,
    format_safe_photos_failure,
    icon_button,
    is_safe_photos_observation,
    logging,
    queue,
    threading,
    time,
    tk,
    tr,
    ttk,
)

class PhotosWorkspaceLayoutMixin:
    """Own one cohesive group of Photos workspace behavior."""

    def _text(self, key: str) -> str:
        """Translate a stable workspace key for the current language.

        Args:
            key (str): Photos-workspace translation key.

        Returns:
            str: Localized label with the translation module's fallback.
        """
        return tr(self._language(), "photos_workspace_" + key)
    def _build_widgets(self) -> None:
        """Create the connection action, hierarchy, viewer and inspector panes.

        Returns:
            None: Installs the workspace widgets in the main window.
        """
        self.connect_view = ttk.Frame(self, style="Card.TFrame", padding=18)
        self.connect_view.grid(row=0, column=0, sticky="nw", padx=18, pady=18)
        Button(
            self.connect_view,
            text=tr(self._language(), "linking_start"),
            command=self._connect,
            style="Accent.Compact.TButton",
        ).grid(row=0, column=0, sticky="w")

        self.panes = ttk.Panedwindow(self, orient="horizontal")
        self.panes.grid(row=0, column=0, sticky="nsew")
        self.tree_pane = ttk.Frame(self.panes, style="Card.TFrame", padding=8)
        self.viewer_pane = ttk.Frame(self.panes, style="Card.TFrame", padding=8)
        self.info_pane = ttk.Frame(self.panes, style="Card.TFrame", padding=8)
        self.panes.add(self.tree_pane, weight=0)
        self.panes.add(self.viewer_pane, weight=1)
        self.info_pane.configure(width=PHOTOS_INFO_PANE_WIDTH)
        self.info_pane.grid_propagate(False)
        self.info_pane.rowconfigure(2, weight=1)
        self.info_pane.columnconfigure(0, weight=1)
        if self._restart_with_qt is not None:
            self._legacy_interface_notice = GlassCard(self.viewer_pane)
            self._legacy_interface_notice.pack(fill='x', padx=8, pady=(4, 2))
            self._legacy_interface_notice.columnconfigure(0, weight=1)
            self._legacy_interface_notice_label = ttk.Label(
                self._legacy_interface_notice,
                text=tr(self._language(), 'photos_workspace_legacy_notice'),
                style='Warning.TLabel',
                wraplength=PHOTOS_LEGACY_NOTICE_TEXT_WIDTH,
                justify='left',
            )
            self._legacy_interface_notice_label.grid(
                row=0, column=0, sticky='ew', padx=(10, 8), pady=6,
            )
            self._legacy_interface_restart = Button(
                self._legacy_interface_notice,
                text=tr(self._language(), 'photos_workspace_switch_to_qt'),
                command=self._restart_with_qt,
                style='Accent.Compact.TButton',
            )
            self._legacy_interface_restart.grid(
                row=0, column=1, sticky='e', padx=(0, 8), pady=4,
            )
            self._legacy_interface_notice.bind(
                '<Configure>',
                self._resize_legacy_interface_notice,
                add='+',
            )
        self._photo_inspector_heading = ttk.Label(
            self.info_pane, text=self._text("photo_info"),
            style="Section.TLabel",
            wraplength=(
                PHOTOS_INFO_PANE_WIDTH - PHOTOS_INFO_PANE_HEADER_INSET
            ),
        )
        self._photo_inspector_heading.grid(
            row=0, column=0, sticky="ew", pady=(0, 4),
        )
        self._photo_inspector_close = icon_button(
            self.info_pane, 'close', self._dismiss_photo_inspector,
            self._text('close_photo_info'),
        )
        self._photo_inspector_close.grid(
            row=0, column=1, sticky='ne', padx=(4, 0),
        )
        self._photo_inspector_summary = ttk.Label(
            self.info_pane, text=self._text("select_photo_hint"),
            style="Muted.TLabel",
            wraplength=(
                PHOTOS_INFO_PANE_WIDTH - PHOTOS_INFO_PANE_HEADER_INSET
            ),
            justify="left",
        )
        self._photo_inspector_summary.grid(
            row=1, column=0, columnspan=2, sticky="ew", pady=(0, 6),
        )
        inspector_body = ttk.Frame(self.info_pane, style="Card.TFrame")
        inspector_body.grid(row=2, column=0, columnspan=2, sticky="nsew")
        inspector_body.rowconfigure(0, weight=1)
        inspector_body.columnconfigure(0, weight=1)
        self._photo_inspector_canvas = tk.Canvas(
            inspector_body, highlightthickness=0, borderwidth=0,
            background=ttk.Style(self).lookup(
                "Card.TFrame", "background",
            ) or _PALETTE["card_surface"],
        )
        self._photo_inspector_scrollbar = ttk.Scrollbar(
            inspector_body, orient="vertical",
            command=self._photo_inspector_canvas.yview,
        )
        self._photo_inspector_canvas.configure(
            yscrollcommand=self._photo_inspector_scrollbar.set,
        )
        self._bind_photo_inspector_scroll(self._photo_inspector_canvas)
        self._photo_inspector_canvas.grid(row=0, column=0, sticky="nsew")
        self._photo_inspector_scrollbar.grid(row=0, column=1, sticky="ns")
        self._photo_inspector_content = ttk.Frame(
            self._photo_inspector_canvas, style="Card.TFrame", padding=(2, 1),
        )
        self._photo_inspector_window = (
            self._photo_inspector_canvas.create_window(
                (0, 0), window=self._photo_inspector_content,
                anchor="nw",
            )
        )
        self._photo_inspector_content.bind(
            "<Configure>", self._resize_photo_inspector_content, add="+",
        )
        self._bind_photo_inspector_scroll(self._photo_inspector_content)
        self._photo_inspector_canvas.bind(
            "<Configure>", self._resize_photo_inspector_viewport, add="+",
        )
        self.tree_pane.configure(width=PHOTOS_TREE_WIDTH)
        self.tree_pane.grid_propagate(False)
        self.tree_pane.rowconfigure(2, weight=1)
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
        self.tree_refresh_button = icon_button(
            controls, "refresh", self._refresh_catalogue,
            self._text("refresh_galleries"),
        )
        self.tree_refresh_button.pack(side="left", padx=(4, 0))
        self.load_more_button = Button(
            controls,
            text=self._text("load_more"),
            command=self._load_more_catalogue,
            style="Compact.TButton",
        )
        self.load_more_button.pack(side="right")
        self.load_more_button.pack_forget()
        self.retry_button = Button(
            controls,
            text=self._text("retry"),
            command=self._retry_catalogue,
            style="TButton",
        )
        self.retry_button.pack(side="right")
        self.retry_button.pack_forget()
        self._catalogue_status = ttk.Frame(
            self.tree_pane, style="Card.TFrame",
        )
        self._catalogue_status.grid(
            row=1, column=0, columnspan=2, sticky="ew", pady=(0, 4),
        )
        self._catalogue_status.columnconfigure(1, weight=1)
        spinner_frames = tuple(
            image for frame_index in range(
                PHOTOS_CATALOGUE_SPINNER_FRAME_COUNT,
            )
            if (image := create_smooth_icon(
                self._catalogue_status,
                "loading_spinner",
                (16, 16),
                angle=(360 * frame_index)
                // PHOTOS_CATALOGUE_SPINNER_FRAME_COUNT,
                tone="primary",
            )) is not None
        )
        self._catalogue_spinner_frames = spinner_frames
        self._catalogue_spinner_index = 0
        self._catalogue_spinner_label = ttk.Label(
            self._catalogue_status,
            image=spinner_frames[0] if spinner_frames else "",
            style="Card.TLabel",
        )
        self._catalogue_spinner_label.grid(
            row=0, column=0, sticky="w", padx=(0, 6),
        )
        self._catalogue_spinner_label.grid_remove()
        self.tree_status = ttk.Label(
            self._catalogue_status, text="", style="Muted.TLabel",
            wraplength=PHOTOS_TREE_WIDTH - 48,
        )
        self.tree_status.grid(row=0, column=1, sticky="w")
        self.tree = ttk.Treeview(
            self.tree_pane,
            show="tree",
            selectmode="browse",
            takefocus=True,
        )
        self.tree.grid(row=2, column=0, sticky="nsew")
        tree_scroll = ttk.Scrollbar(
            self.tree_pane,
            orient="vertical",
            command=self.tree.yview,
        )
        tree_scroll.grid(row=2, column=1, sticky="ns")
        self.tree.configure(yscrollcommand=tree_scroll.set)
        self.tree.bind("<<TreeviewSelect>>", self._on_gallery_selected)
        self.tree.bind("<<TreeviewOpen>>", self._on_tree_expansion, add="+")
        self.tree.bind("<<TreeviewClose>>", self._on_tree_expansion, add="+")

        for sequence in ("<Button-3>", "<Button-2>", "<Control-Button-1>"):
            self.tree.bind(sequence, self._tree_context)
        storage_parent = self._storage_parent or self.viewer_pane
        self._storage_heading = MetricCard(storage_parent)
        self._storage_heading.pack(anchor='e')
        self._storage_text = tk.StringVar(self, self._text('storage_loading'))
        self._storage_button = Button(
            self._storage_heading, textvariable=self._storage_text,
            command=self._open_storage_context, style='Storage.Metric.TButton',
            takefocus=True,
        )
        self._storage_button.pack(side='right')
        self._storage_icon = create_smooth_icon(
            self._storage_button, 'local', METRIC_ICON_SIZE, tone='primary',
        )
        if self._storage_icon is not None:
            self._storage_button.configure(image=self._storage_icon, compound='left')
        self._storage_hint = tk.StringVar(self, self._text('storage_scope_hint'))
        attach_inline_tooltip(
            self._storage_button, self._storage_hint,
            self._storage_surface or self.viewer_pane,
        )
        self._storage_heading.bind('<Configure>', self._resize_storage, add='+')
        self._gallery_heading = ttk.Frame(self.viewer_pane, style="Card.TFrame")
        self._gallery_heading.pack(fill="x", padx=8, pady=(4, 6))
        self._gallery_heading.columnconfigure(0, weight=1)
        self._heading_stacked: bool | None = None
        # The progress strip follows the occupied heading rows: one desktop
        # row, two rows with paired controls, or three when groups cannot fit.
        self._heading_preload_row = 1
        self._heading_title = ttk.Frame(self._gallery_heading, style="Card.TFrame")
        self._heading_controls = ttk.Frame(self._gallery_heading, style="Card.TFrame")
        self._heading_title.grid(row=0, column=0, sticky="ew")
        self._heading_controls.grid(row=0, column=2, sticky="e")
        self._cache_tooltip = tk.StringVar(self, self._text('cache_unknown'))
        self._cache_button = icon_button(
            self._heading_controls, '◌', self._open_cache_context,
            self._cache_tooltip,
        )
        # Fit the retention pin and coverage glyph together using Tk's width.
        self._cache_button.configure(width=-1)
        self._cache_button.pack(side='right', padx=(0, 8))
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
        # Parenting to the pane permits this same strip to sit in the heading
        # for photos alone, or directly below the folder shelf for mixed albums.
        self._card_format_controls = ttk.Frame(
            self.viewer_pane, style="Card.TFrame",
        )
        self._card_format_controls.grid(
            in_=self._gallery_heading, row=0, column=1, sticky="w",
        )
        self._photo_order_hint = tk.StringVar(self, self._text('order_oldest'))
        self._photo_order_button = Button(
            self._card_format_controls, text='↑', width=-1,
            style='Photos.Icon.TButton', state='disabled',
            command=self._toggle_photo_order, takefocus=True,
        )
        self._photo_order_button.grid(row=0, column=0, sticky='ew', padx=(0, 4))
        attach_inline_tooltip(
            self._photo_order_button, self._photo_order_hint, self.viewer_pane,
        )
        self._card_format_buttons: dict[str, ttk.Button] = {}
        self._card_format_images: dict[tuple[str, bool], ThemeImage | None] = {}
        self._card_format_hint = tk.StringVar(self, self._text("card_format_hint"))
        for index, key in enumerate(PHOTO_CARD_FORMATS):
            caption = self._text("card_format_default") if key == "default" else key
            for selected in (False, True):
                self._card_format_images[(key, selected)] = create_smooth_icon(
                    self, "aspect_" + key, PHOTOS_CARD_FORMAT_ICON_SIZE,
                    tone="primary" if selected else "muted",
                )
            artwork = self._card_format_images[(key, False)]
            button = Button(
                self._card_format_controls, text=caption,
                image=artwork if artwork is not None else "", compound="top",
                style="Format.Photos.Icon.TButton", state="disabled",
                takefocus=True, width=-1,
                command=lambda value=key: self._activate_card_format(value),
            )
            button.grid(row=0, column=index + 1, sticky="ew")
            attach_inline_tooltip(button, self._card_format_hint, self.viewer_pane)
            self._card_format_buttons[key] = button
        self._card_format_notice = tk.StringVar(self, "")
        notice = ttk.Label(
            self._card_format_controls, textvariable=self._card_format_notice,
            style="Card.TLabel",
        )
        notice.grid(row=0, column=len(PHOTO_CARD_FORMATS) + 1)
        self._card_format_notice_label = notice
        self._card_format_controls.bind(
            '<Configure>', self._on_photo_strip_resize, add='+',
        )
        self._card_format_columns = len(PHOTO_CARD_FORMATS) + 1
        attach_inline_tooltip(notice, self._card_format_hint, self.viewer_pane)
        self._folder_format_controls = ttk.Frame(
            self._gallery_heading, style='Card.TFrame',
        )
        self._folder_order_hint = tk.StringVar(self)
        self._folder_format_hint = tk.StringVar(self)
        self._folder_order_button = Button(
            self._folder_format_controls, text='↑', width=-1,
            style='Photos.Icon.TButton', takefocus=True,
            command=lambda: self._choose_folder_view(order=True),
        )
        self._folder_order_button.grid(row=0, column=0, padx=(0, 4))
        attach_inline_tooltip(
            self._folder_order_button, self._folder_order_hint, self.viewer_pane,
        )
        self._folder_format_buttons: dict[str, ttk.Button] = {}
        for index, key in enumerate(PHOTO_CARD_FORMATS):
            button = Button(
                self._folder_format_controls,
                text=self._text('card_format_default') if key == 'default' else key,
                image=self._card_format_images[(key, False)] or '', compound='top',
                style='Format.Photos.Icon.TButton', width=-1, takefocus=True,
                command=lambda value=key: self._choose_folder_view(value),
            )
            button.grid(row=0, column=index + 1, sticky='ew')
            attach_inline_tooltip(button, self._folder_format_hint, self.viewer_pane)
            self._folder_format_buttons[key] = button
        self._folder_notice = tk.StringVar(self)
        self._folder_notice_label = ttk.Label(
            self._folder_format_controls, textvariable=self._folder_notice,
            style='Card.TLabel',
        )
        self._folder_notice_label.grid(row=0, column=len(PHOTO_CARD_FORMATS) + 1)
        attach_inline_tooltip(
            self._folder_notice_label, self._folder_format_hint, self.viewer_pane,
        )
        self._photo_separator = ttk.Frame(
            self.viewer_pane, style='Card.TFrame',
            height=FOLDER_DIVIDER_HIT_HEIGHT, cursor='sb_v_double_arrow',
            takefocus=True,
        )
        divider_line = ttk.Separator(
            self._photo_separator, orient='horizontal', cursor='sb_v_double_arrow',
        )
        divider_line.place(relx=0, rely=0.5, relwidth=1)
        self._folder_split_hint = tk.StringVar(self, self._text('folder_split_hint'))
        for target in (self._photo_separator, divider_line):
            target.bind('<ButtonPress-1>', self._start_folder_resize)
            target.bind('<B1-Motion>', self._move_folder_resize)
            target.bind('<ButtonRelease-1>', self._finish_folder_resize)
            target.bind('<Up>', self._key_folder_resize)
            target.bind('<Down>', self._key_folder_resize)
            target.bind('<Home>', self._key_folder_resize)
            attach_inline_tooltip(target, self._folder_split_hint, self.viewer_pane)
        self._recording_heading = ttk.Label(
            self._heading_title, text="", style="Card.TLabel",
        )
        self._recording_heading.pack(side="left", padx=(0, 4))
        self._gallery_title_label = ttk.Label(
            self._heading_title, textvariable=self._gallery_title,
            style="Section.TLabel", justify="left", cursor="hand2",
            takefocus=True,
        )
        self._gallery_title_label.pack(side="left", fill="x")
        self._gallery_title_label.bind('<Button-1>', lambda event: self._open_title_editor(event))
        self._gallery_title_label.bind('<Return>', lambda event: self._open_title_editor(event))
        self._gallery_title_label.bind('<space>', lambda event: self._open_title_editor(event))
        self._title_edit_button = icon_button(
            self._heading_title, '✎', lambda: self._open_title_editor(),
            self._text('edit_title'),
        )
        self._title_edit_button.pack(side='left', padx=(2, 4))
        self._preload_strip = ttk.Frame(self._gallery_heading, style='Card.TFrame')
        self._preload_strip.columnconfigure(0, weight=1)
        self._preload_text = tk.StringVar(self)
        self._preload_cancel_hint = tk.StringVar(self, self._text('preload_cancel'))
        self._preload_label = ttk.Label(
            self._preload_strip, textvariable=self._preload_text,
            style='Muted.TLabel', wraplength=440,
        )
        self._preload_label.grid(row=0, column=0, sticky='w')
        self._preload_activity = ttk.Label(
            self._preload_strip, style='Card.TLabel', takefocus=False,
        )
        self._preload_activity.grid(row=0, column=1, padx=(4, 0))
        self._preload_cancel = icon_button(
            self._preload_strip, '×', self._cancel_preload,
            self._preload_cancel_hint,
        )
        self._preload_cancel.grid(row=0, column=2, padx=(6, 0))
        self._preload_bar = ttk.Progressbar(
            self._preload_strip, mode='indeterminate',
        )
        self._preload_bar.grid(row=1, column=0, columnspan=3, sticky='ew')
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
    def _restore_tree_width(self) -> None:
        """Apply the saved Photos pane width after the Panedwindow is mapped.

        Returns:
            None: Applies the latest trusted preference after mapped geometry
                is usable, and leaves the default alone when no preference exists.
        """
        if self._tree_width_restored:
            return
        if not self.panes.winfo_ismapped():
            return
        if self._tree_width_dragging:
            return
        available = self.panes.winfo_width()
        if available <= 1:
            return
        width = self._preferred_tree_width
        if width is not None:
            self.panes.sashpos(0, min(width, available - 1))
        self._tree_width_restored = True
    def _restore_info_width(self) -> None:
        """Restore the preferred right-pane width when its sash is visible.

        Returns:
            None: Keeps the requested width for a later map when space is narrow.
        """
        if (self._destroyed or not self._photo_inspector_visible
                or self._info_width_dragging
                or not self.panes.winfo_ismapped()):
            return
        pane_width = self.panes.winfo_width()
        if (pane_width <= 1
                or str(self.panes.panes()[-1]) != str(self.info_pane)):
            return
        tree_edge = int(self.panes.sashpos(0))
        available = pane_width - tree_edge
        low, high = PHOTOS_INFO_WIDTH_BOUNDS
        if available < low + PHOTOS_INFO_VIEWER_MINIMUM_WIDTH:
            return
        target = min(
            high, max(low, self._preferred_info_width),
            available - PHOTOS_INFO_VIEWER_MINIMUM_WIDTH,
        )
        current_width = pane_width - int(self.panes.sashpos(1))
        if target > 0 and abs(current_width - target) > 2:
            self.panes.sashpos(1, pane_width - target)
        self._info_width_restored = True
    def _schedule_info_width_save(self, event: tk.Event) -> None:
        """Debounce a user drag of only the viewer/inspector sash.

        Args:
            event (tk.Event): Pointer or keyboard release in the Panedwindow.

        Returns:
            None: Schedules persistence of the right pane's actual width.
        """
        if not self._photo_inspector_visible or not self.panes.winfo_ismapped():
            return
        if not self._info_width_dragging:
            return
        pane_width = self.panes.winfo_width()
        width = pane_width - int(self.panes.sashpos(1))
        if width <= 0:
            return
        self._pending_info_width = width
        low, high = PHOTOS_INFO_WIDTH_BOUNDS
        self._preferred_info_width = min(high, max(low, width))
        self._info_width_dragging = False
        self._info_width_restored = True
        if self._info_width_save_after is not None:
            try:
                self.after_cancel(self._info_width_save_after)
            except tk.TclError:
                pass
        self._info_width_save_after = self.after(
            PHOTOS_INFO_WIDTH_SAVE_DELAY_MS, self._persist_info_width,
        )
    def _persist_info_width(self) -> None:
        """Persist the settled right inspector width through the host owner.

        Returns:
            None: Updates the desired width and calls the optional profile writer.
        """
        self._info_width_save_after = None
        if self._destroyed:
            return
        width = getattr(self, '_pending_info_width', None)
        self._pending_info_width = None
        if type(width) is not int or width <= 0:
            return
        low, high = PHOTOS_INFO_WIDTH_BOUNDS
        width = min(high, max(low, width))
        self._preferred_info_width = width
        if self._save_info_width is not None:
            self._save_info_width(width)
    def current_info_width(self) -> int | None:
        """Return the latest desired inspector width, including pending drags.

        Returns:
            int | None: Desired preference independent of transient pane geometry,
                or None before a valid width has been established.
        """
        pending = getattr(self, '_pending_info_width', None)
        if type(pending) is int and pending > 0:
            low, high = PHOTOS_INFO_WIDTH_BOUNDS
            return min(high, max(low, pending))
        preferred = getattr(self, '_preferred_info_width', None)
        if type(preferred) is int and preferred > 0:
            low, high = PHOTOS_INFO_WIDTH_BOUNDS
            return min(high, max(low, preferred))
        return None
    def _on_panes_unmap(self, event: tk.Event) -> None:
        """Retain a pending settled sash value before hiding the pane.

        Args:
            event (tk.Event): Panedwindow mapping change from Tk.

        Returns:
            None: A captured drag is flushed before the next map restores the
                latest preference; transient unmapped sash geometry is ignored.
        """
        if event.widget is not self.panes:
            return
        if self._tree_width_save_after is not None:
            try:
                self.after_cancel(self._tree_width_save_after)
            except tk.TclError:
                pass
            self._tree_width_save_after = None
            self._persist_tree_width()
        if self._info_width_save_after is not None:
            try:
                self.after_cancel(self._info_width_save_after)
            except tk.TclError:
                pass
            self._info_width_save_after = None
            self._persist_info_width()
        self._tree_width_dragging = False
        self._info_width_dragging = False
        self._tree_width_restored = False
        self._info_width_restored = False
    def _begin_tree_width_drag(self, event: tk.Event) -> None:
        """Mark pointer interaction with the Panedwindow sash as user-owned.

        Args:
            event (tk.Event): Pointer press and coordinates from Tk.

        Returns:
            None: A later Map callback cannot replace an active sash drag.
        """
        # Ttk identifies a sash by its index (often "0"), not the word "sash".
        if self._sash_index_at(event.x, event.y) == 0:
            self._tree_width_dragging = True
    def _sash_index_at(self, x: int, y: int) -> int | None:
        """Return the numeric sash index reported at one pointer position.

        Args:
            x (int): Horizontal Panedwindow coordinate.
            y (int): Vertical Panedwindow coordinate.

        Returns:
            int | None: Identified sash index, or None for a non-sash element.
        """
        identified = self.panes.identify(x, y)
        if isinstance(identified, (tuple, list)):
            parts = tuple(str(part) for part in identified)
        else:
            parts = str(identified).replace(',', ' ').split()
        for part in reversed(parts):
            try:
                index = int(part)
            except ValueError:
                continue
            if index >= 0:
                return index
        return None
    def _begin_info_width_drag(self, event: tk.Event) -> None:
        """Mark only the right inspector sash as directly user-controlled.

        Args:
            event (tk.Event): Pointer press and coordinates from Tk.

        Returns:
            None: The inspector preference survives later geometry changes.
        """
        if (self._photo_inspector_visible
                and self._sash_index_at(event.x, event.y) == 1):
            self._info_width_dragging = True
    def _schedule_tree_width_save(self, _event: tk.Event) -> None:
        """Debounce sash release before persisting the user-selected width.

        Args:
            _event (tk.Event): Button or keyboard release on the Panedwindow.

        Returns:
            None: Schedules one callback after the current sash interaction.
        """
        if not self.panes.winfo_ismapped():
            return
        if (_event.type == tk.EventType.ButtonRelease
                and self._sash_index_at(_event.x, _event.y) != 0):
            return
        if not self._tree_width_dragging:
            return
        self._tree_width_dragging = False
        self._tree_width_restored = True
        width = int(self.panes.sashpos(0))
        if width <= 0:
            return
        self._pending_tree_width = width
        if self._tree_width_save_after is not None:
            try:
                self.after_cancel(self._tree_width_save_after)
            except tk.TclError:
                pass
        self._tree_width_save_after = self.after(
            PHOTOS_TREE_WIDTH_SAVE_DELAY_MS, self._persist_tree_width,
        )
    def _persist_tree_width(self) -> None:
        """Save the current positive sash position through its profile owner.

        Returns:
            None: The current Photos tree width is atomically persisted by the
                host callback when one is configured.
        """
        self._tree_width_save_after = None
        if self._destroyed:
            return
        width = self._pending_tree_width
        self._pending_tree_width = None
        if type(width) is not int or width <= 0:
            return
        self._preferred_tree_width = width
        if self._save_tree_width is not None:
            self._save_tree_width(width)
    def current_tree_width(self) -> int | None:
        """Return a mapped Photos sash width only after trusted initialization.

        Returns:
            int | None: Current positive Panedwindow sash position in Tk pixels,
                or None while its mapped user-selected width is not established.
        """
        if (not self._tree_width_restored
                or not self.panes.winfo_ismapped()):
            return None
        position = int(self.panes.sashpos(0))
        return position if position > 0 else None
    def _fit_view_controls(
        self, buttons: tuple[ttk.Button, ...], notice: ttk.Label, width: int,
    ) -> int:
        """Wrap a compact ratio strip only when its actual controls cannot fit.

        Args:
            buttons (tuple[ttk.Button, ...]): Order arrow followed by ratio buttons.
            notice (ttk.Label): Local persistence warning at the final row.
            width (int): Available logical Tk width for the strip.
        Returns:
            int: Number of button columns that fit without horizontal overflow.
        """
        columns = len(buttons)
        while columns > 1:
            needed = sum(
                max(button.winfo_reqwidth() for button in buttons[column::columns])
                for column in range(columns)
            ) + notice.winfo_reqwidth()
            if needed <= width:
                break
            columns -= 1
        for index, button in enumerate(buttons):
            row, column = index // columns, index % columns
            current = button.grid_info()
            if current.get('row') != row or current.get('column') != column:
                button.grid_configure(row=row, column=column)
        row = (len(buttons) - 1) // columns
        current = notice.grid_info()
        if current.get('row') != row or current.get('column') != columns:
            notice.grid_configure(row=row, column=columns)
        return columns
    def _resize_legacy_interface_notice(self, event: tk.Event) -> None:
        """Fit the optional Legacy Tk notice and restart action to its pane.

        Args:
            event (tk.Event): Notice width change from Tk geometry management.

        Returns:
            None: The action shares a row on desktop and wraps below the message
                when the Photos pane is narrow.
        """
        # The warning label has 20 pixels of theme padding and 18 of grid
        # margins. The button reserves its requested width and right margin.
        message_width = event.width - 38
        inline_width = message_width - self._legacy_interface_restart.winfo_reqwidth() - 8
        if inline_width < PHOTOS_LEGACY_NOTICE_TEXT_WIDTH:
            self._legacy_interface_notice_label.grid_configure(
                row=0, column=0, columnspan=2, sticky='ew',
            )
            self._legacy_interface_restart.grid_configure(
                row=1, column=1, sticky='e',
            )
            wraplength = message_width
        else:
            self._legacy_interface_notice_label.grid_configure(
                row=0, column=0, columnspan=1, sticky='ew',
            )
            self._legacy_interface_restart.grid_configure(
                row=0, column=1, sticky='e',
            )
            wraplength = inline_width
        self._legacy_interface_notice_label.configure(
            wraplength=max(1, wraplength),  # Tk uses zero for unwrapped text.
        )
    def _on_photo_strip_resize(self, event: tk.Event) -> None:
        """Fit the photo controls to their own width below a mixed folder shelf.

        Args:
            event (tk.Event): Actual photo strip geometry change.
        Returns:
            None: The thin strip uses another row only if its buttons cannot fit.
        """
        # In the heading, its owner allocates width across title and controls.
        # Fitting to this strip's transient request there would feed a narrower
        # request back into the heading and repeatedly collapse/expand the row.
        if self._card_format_controls.winfo_manager() != 'pack':
            return
        self._card_format_columns = self._fit_view_controls(
            (self._photo_order_button, *self._card_format_buttons.values()),
            self._card_format_notice_label, event.width,
        )
    def _on_heading_resize(self, event: tk.Event) -> None:
        """Fit the title and the active section's compact controls to the pane.

        Args:
            event (tk.Event): Heading width change from Tk geometry management.
        Returns:
            None: Desktop retains one heading row; narrow panes wrap controls.
                Mixed galleries keep the separate photo strip beside their photos.
        """
        folders = self._folders_visible
        controls = (self._folder_format_controls if folders
                    else self._card_format_controls)
        buttons = ((self._folder_order_button, *self._folder_format_buttons.values())
                   if folders else
                   (self._photo_order_button, *self._card_format_buttons.values()))
        notice = self._folder_notice_label if folders else self._card_format_notice_label
        self._fit_view_controls(buttons, notice, event.width)
        active = folders or self._photos_present is not False
        groups_width = self._heading_controls.winfo_reqwidth()
        if active:
            groups_width += controls.winfo_reqwidth()
        stacked = (event.width < PHOTOS_HEADING_STACK_WIDTH
                   or groups_width + PHOTOS_HEADING_TITLE_MINIMUM > event.width)
        paired = stacked and groups_width <= event.width
        preload_row = 2 if paired else 3 if stacked else 1
        self._heading_stacked = stacked
        self._heading_preload_row = preload_row
        self._heading_title.grid_configure(columnspan=3 if stacked else 1)
        if active:
            controls.grid(
                in_=self._gallery_heading,
                row=1 if stacked else 0, column=0 if stacked else 1,
                columnspan=2 if paired else 3 if stacked else 1, sticky='w',
            )
        self._heading_controls.grid(
            row=1 if paired else 2 if stacked else 0,
            column=0 if stacked and not paired else 2,
            columnspan=3 if stacked and not paired else 1,
            sticky='w' if stacked and not paired else 'e',
            pady=(4, 0) if stacked and not paired else 0,
        )
        if self._preload_strip.winfo_manager() == 'grid':
            self._preload_strip.grid_configure(row=preload_row)
        self._preload_label.configure(wraplength=max(
            1, event.width - self._preload_cancel.winfo_reqwidth()
            - self._preload_activity.winfo_reqwidth() - PHOTOS_PRELOAD_CONTROL_GAP,
        ))
        available = event.width if stacked else event.width - groups_width
        self._gallery_title_label.configure(wraplength=max(1, available - 16))
    def _on_viewer_resize(self, event: tk.Event) -> None:
        """Keep the contextual viewer status within its pane width.

        Args:
            event (tk.Event): Resize event from the right-hand pane.

        Returns:
            None: Status text wraps at the currently available width.
        """
        self.viewer_status.configure(wraplength=max(160, event.width - 24))
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
        if (gallery_id is not None
                and gallery_id not in self._fresh_catalogue_ids):
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
        if (parent_id is not None
                and parent_id not in self._fresh_catalogue_ids):
            return
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
