# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/ui/shell.py
# Module Type: Windows Client UI behavior
# Purpose: Own the shell interactions for WatcherApp.
# Responsibilities: Preserve the existing Watcher UI lifecycle and callbacks.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Watcher shell behavior owner."""

from uploader.watcher import runtime
from uploader.watcher import state as watcher_state
from uploader.localization import gettext, gettext_noop
from .support import (
    ScrollableFrame,
    WATCHER_WINDOW_MINIMUM,
    WINDOW_SUMMARY_WRAP,
    _layout_display_bounds,
    _publish_watcher_text,
    _publish_watcher_value,
    render_tray_icon,
    tr,
)
from uploader.app_header import CompactAppHeader
from uploader.app_header import HEADER_GROUP_GAP
from uploader.app_header import HEADER_WORKSPACE_GAP
from uploader.flight_report_translations import recovery_text
from uploader.ui_layout import SHELL_INSETS
from uploader.ui_layout import SHELL_RAIL_GAP
from uploader.ui_layout import WATCHER_HEADER_METRIC_MIN_CELLS
from uploader.ui_layout import WATCHER_WINDOW_GEOMETRY
from uploader.ui_shell import active_panel
from uploader.ui_shell import help_button
from uploader.ui_theme import Button
from uploader.ui_theme import GlassCard
from uploader.ui_theme import METRIC_ICON_SIZE
from uploader.ui_theme import MetricCard
from uploader.ui_theme import _PALETTE
from uploader.ui_theme import attach_inline_tooltip
from uploader.ui_theme import configure_theme
from uploader.ui_theme import create_smooth_icon
from uploader.ui_theme import icon_button
from uploader.ui_theme import paint_backdrop
from uploader.windows_startup import ACTIVATION_POLL_MS
import logging

class WatcherShellMixin:
    """Own this cohesive WatcherApp UI behavior domain."""

    def complete_startup(self) -> None:
        """Keep a logon launch hidden only when the native tray becomes usable.

        Returns:
            None: Retains the tray-only launch, or restores a visible window
                after missing/failed/late tray initialization. Deferred import
                recovery remains an explicit action after the window is opened.
        """
        if self.exiting or not self.startup_waiting_for_tray:
            return
        if (
            self.tray_icon is not None and self.tray_icon.visible
            and self.tray_thread is not None and self.tray_thread.is_alive()
        ):
            self.startup_waiting_for_tray = False
            self.write_log("Windows startup: application is running in the tray.", "system")
            return
        if (
            self.tray_icon is None
            or self.tray_thread is None
            or not self.tray_thread.is_alive()
            or watcher_state.time.monotonic() >= self.startup_tray_deadline
        ):
            self.write_log(
                "Windows startup: tray unavailable; showing the application.",
                "warning",
            )
            self.restore_from_tray()
            return
        self.root.after(ACTIVATION_POLL_MS, self.complete_startup)


    def poll_instance_activation(self) -> None:
        """Deliver another process's restore request through the Tk main loop.

        Returns:
            None: Restores the existing window without restarting any worker;
                polling stops during confirmed application shutdown.
        """
        if self.exiting or self.instance is None:
            return
        try:
            if self.instance.activation_requested():
                self.restore_from_tray()
        except OSError as exc:
            self.write_log("Instance activation failed: " + str(exc), "warning")
            return
        self.root.after(ACTIVATION_POLL_MS, self.poll_instance_activation)


    def build_ui(self) -> None:
        """
        Create the themed navigation, primary watcher view, and live footer.

        Returns:
            None: Builds navigation, update notice, and application views;
            Photos is selected by default; startup mode remains withdrawn.
        """
        configure_theme(self.root)
        self.build_menu()
        self.gallery_title_var.trace_add(
            "write",
            lambda *_trace_info: self.refresh_watcher_header(),
        )
        layout = self._ui_layout_state
        geometry = (
            f"{layout.width}x{layout.height}{layout.x:+d}{layout.y:+d}"
            if self._ui_layout_restored else WATCHER_WINDOW_GEOMETRY
        )
        self.root.geometry(geometry)
        bounds = _layout_display_bounds(self.root)
        self.root.minsize(
            min(WATCHER_WINDOW_MINIMUM[0], bounds[2]),
            min(WATCHER_WINDOW_MINIMUM[1], bounds[3]),
        )
        outer = runtime.ttk.Frame(
            self.root,
            padding=SHELL_INSETS,
            style="Backdrop.TFrame",
        )
        paint_backdrop(outer)
        outer.pack(fill="both", expand=True)
        outer.columnconfigure(1, weight=1)
        outer.rowconfigure(2, weight=1)

        self.build_app_header(outer)
        self.update_banner = GlassCard(outer, padding=(12, 6))
        self.update_banner.grid(
            row=1,
            column=0,
            columnspan=2,
            sticky="ew",
            pady=(0, 8),
        )
        self.update_banner.columnconfigure(0, weight=1)
        self.update_banner_label = runtime.ttk.Label(
            self.update_banner,
            text="",
            style="Section.TLabel",
        )
        self.update_banner_label.grid(row=0, column=0, sticky="w")
        self.update_banner_action = Button(
            self.update_banner,
            text=gettext("Open update"),
            command=self.open_update_notice,
            style="Primary.TButton",
        )
        self.update_banner_action.grid(row=0, column=1, sticky="e", padx=(12, 0))
        self.update_banner.grid_remove()

        rail = runtime.ttk.Frame(outer, style="App.TFrame", padding=(0, 4, 6, 0))
        rail.grid(row=2, column=0, sticky="ns")
        self.navigation_buttons: Dict[str, runtime.ttk.Button] = {}
        self.notebook = runtime.ttk.Notebook(outer, style="App.TNotebook")
        self.notebook.grid(row=2, column=1, sticky="nsew")
        tabs: Dict[str, runtime.ttk.Frame | ScrollableFrame] = {}
        self.tab_indices: Dict[str, int] = {}
        for key, glyph, label in [
            (
                "photos",
                "▧",
                tr(
                    self.flights.ui_language.get(),
                    "photos_pane_title",
                ),
            ),
            ("watch", "◉", gettext("Watcher")),
            ("import", "⇧", gettext("Import")),
            ("activity", "≡", gettext("Activity")),
            ("ai", "✦", "AI"),
            ("settings", "⚙", gettext("Settings")),
        ]:
            host = runtime.ttk.Frame(self.notebook, style="Backdrop.TFrame")
            paint_backdrop(host)
            host.rowconfigure(0, weight=1)
            host.columnconfigure(0, weight=1)
            if key == "photos":
                page = runtime.ttk.Frame(host, style="Backdrop.TFrame")
                page.grid(row=0, column=0, sticky="nsew")
                paint_backdrop(page)
                tabs[key] = page
            else:
                scroll = ScrollableFrame(host)
                scroll.grid(row=0, column=0, sticky="nsew")
                tabs[key] = scroll
            self.notebook.add(host, text=f"{glyph}  {label}")
            self.tab_indices[key] = self.notebook.index(host)
            self.navigation_buttons[key] = icon_button(
                rail, glyph, lambda name=key: self.select_tab(name), label,
            )
            self.navigation_buttons[key].pack(pady=(0, SHELL_RAIL_GAP))
            if key == "watch":
                self.navigation_buttons[key].configure(text="◉")
        self.tab_hosts = tabs

        self.build_photos_tab(tabs["photos"])
        for sequence in ("<Button-3>", "<Button-2>", "<Control-Button-1>"):
            self.navigation_buttons["watch"].bind(
                sequence, self.open_watcher_rail_context, add="+",
            )
        self.build_watch_tab(tabs["watch"].content)
        self.build_manual_tab(tabs["import"].content)
        self.build_activity_tab(tabs["activity"].content)
        self.build_ai_tab(tabs["ai"].content)
        self.build_settings_tab(tabs["settings"].content)
        self._sync_watcher_recording()
        self.notebook.select(self.tab_indices["photos"])
        self.notebook.bind("<<NotebookTabChanged>>", self.refresh_navigation)
        self.refresh_navigation()
        if (layout.window_state == "zoomed"
                and not self.startup_waiting_for_tray):
            try:
                self.root.state("zoomed")
            except runtime.tk.TclError:
                pass

        footer = runtime.ttk.Frame(outer, style="Footer.TFrame", padding=(12, 8))
        footer.grid(row=3, column=0, columnspan=2, sticky="ew", pady=(12, 0))
        footer.columnconfigure(1, weight=1)
        runtime.ttk.Label(footer, text=gettext("LATEST"), style="Eyebrow.TLabel").grid(row=0, column=0, sticky="w", padx=(0, 12))
        self.latest_activity_label = runtime.ttk.Label(
            footer,
            textvariable=self.latest_activity_var,
            style="Footer.TLabel",
        )
        self.latest_activity_label.grid(row=0, column=1, sticky="ew")
        icon_button(
            footer,
            "≡",
            lambda: self.select_tab("activity"),
            gettext("Open full activity history"),
        ).grid(row=0, column=2, padx=(8, 0))

        self.write_log(f"Configuration: {watcher_state.CONFIG_PATH}", "system")
        self.write_log(f"State: {watcher_state.STATE_PATH}", "system")
        self.write_log(f"Log: {watcher_state.LOG_PATH}", "system")
        self.write_log(runtime.thumbnail_runtime_status(), "system")
        self.refresh_connection_controls()
        self.update_monitor_state("disabled", gettext("No background worker is active."))
        self.set_update_available(self.update_available_version)


    def build_app_header(self, outer: "ttk.Frame") -> CompactAppHeader:
        """Build one responsive identity/link/action strip above the workspace.

        Args:
            outer (ttk.Frame): Main window surface owning the header and views.
        Returns:
            CompactAppHeader: Mounted compact header with existing live actions,
                storage context surface and link-state trace ownership.
        """
        header = CompactAppHeader(outer, runtime.APP_VERSION)
        self._app_header = header
        header.grid(
            row=0, column=0, columnspan=2, sticky='ew',
            pady=(0, HEADER_WORKSPACE_GAP),
        )
        status = runtime.ttk.Frame(header.actions, style="App.TFrame")
        self._storage_header = runtime.ttk.Frame(status, style="App.TFrame")
        self._storage_header.pack(side="left", padx=(0, 10))
        self._storage_context_surface = outer
        self.monitor_light = runtime.tk.Canvas(
            status,
            width=16,
            height=16,
            highlightthickness=0,
            bd=0,
            background=_PALETTE["background"],
        )
        self.monitor_light.pack(side="left", padx=(0, 6))
        watcher_display = MetricCard(header.actions)
        watcher_display.pack(side='left', padx=(0, HEADER_GROUP_GAP))
        self._watcher_header_icon_cache: Dict[str, ThemeImage] = {}
        self._watcher_header_status_icon = runtime.ttk.Label(
            watcher_display, style="Metric.Card.TLabel",
        )
        self._watcher_header_status_icon.pack(side="left", padx=(0, 8))
        self._watcher_header_queue_image = create_smooth_icon(
            watcher_display, "queue", METRIC_ICON_SIZE,
        )
        self._watcher_header_queue_icon = runtime.ttk.Label(
            watcher_display,
            image=self._watcher_header_queue_image or "",
            text="▧" if self._watcher_header_queue_image is None else "",
            style="Metric.Card.TLabel",
        )
        self._watcher_header_queue_icon.pack(side="left", padx=(0, 3))
        self.watcher_header_count_label = runtime.ttk.Label(
            watcher_display,
            textvariable=self.watcher_header_count_var,
            style="Metric.Card.TLabel",
            width=-WATCHER_HEADER_METRIC_MIN_CELLS,
        )
        self.watcher_header_count_label.pack(side="left", padx=(0, 8))
        self._watcher_header_clock_image = create_smooth_icon(
            watcher_display, "clock", METRIC_ICON_SIZE,
        )
        self._watcher_header_clock_icon = runtime.ttk.Label(
            watcher_display,
            image=self._watcher_header_clock_image or "",
            text="◷" if self._watcher_header_clock_image is None else "",
            style="Metric.Card.TLabel",
        )
        self._watcher_header_clock_icon.pack(side="left", padx=(0, 3))
        self.watcher_header_time_label = runtime.ttk.Label(
            watcher_display,
            textvariable=self.watcher_header_time_var,
            style="Metric.Card.TLabel",
            width=-WATCHER_HEADER_METRIC_MIN_CELLS,
        )
        self.watcher_header_time_label.pack(side="left")
        self._watcher_header_stop_hint = runtime.tk.StringVar(
            self.root,
            tr(self.flights.ui_language.get(), "watcher_header_stop"),
        )
        stop_slot = runtime.ttk.Frame(watcher_display, style="Card.TFrame")
        self._watcher_header_stop_button = icon_button(
            stop_slot, "stop", self.stop,
            self._watcher_header_stop_hint, tooltip_surface=outer,
            style_override="Header.Metric.Icon.TButton",
        )
        # Keep the action's measured footprint through every Watcher state.
        # The empty slot has no focusable child while Stop is unavailable.
        stop_slot.configure(
            width=self._watcher_header_stop_button.winfo_reqwidth() + 8,
            height=self._watcher_header_stop_button.winfo_reqheight(),
        )
        stop_slot.pack_propagate(False)
        stop_slot.pack(side="left")
        self._watcher_header_hint = runtime.tk.StringVar(self.root)
        for trigger in (
            self._watcher_header_status_icon,
            self._watcher_header_queue_icon,
            self.watcher_header_count_label,
            self._watcher_header_clock_icon,
            self.watcher_header_time_label,
        ):
            attach_inline_tooltip(trigger, self._watcher_header_hint, outer)
        self.refresh_watcher_header()
        status.pack(side="left")
        self.build_gallery_link_bar(header.links)
        self.check_updates_button = icon_button(
            header.actions,
            "refresh",
            self.updates.check,
            gettext("Check for updates or open the available update"),
        )
        self.check_updates_button.pack(side='left', padx=4)
        help_button(
            header.actions,
            gettext("Watcher help"),
            gettext("Choose a folder and connect a gallery. Start watching ignores files already present, then uploads new supported images after they finish copying. Files stay on this PC unless confirmed deletion is explicitly enabled."),
        ).pack(side='left', padx=(4, 0))
        header.request_layout()
        return header


    def build_menu(self) -> None:
        """Connect the native application menu to existing command owners.

        Returns:
            None: Installs keyboard-accessible menus and guarded shortcuts.
        """
        menu = runtime.tk.Menu(self.root, tearoff=False)
        self.root.configure(menu=menu)
        self._edit_focus = None
        file_menu = runtime.tk.Menu(menu, tearoff=False)
        menu.add_cascade(label=gettext("File"), underline=0, menu=file_menu)
        for label, action, shortcut in (
            (gettext("Import files…"), self.select_manual_files, "Ctrl+O"),
            (gettext("Import folder…"), self.select_manual_folder, ""),
            (gettext("Import ZIP archive…"), self.select_manual_zip, ""),
        ):
            file_menu.add_command(
                label=label, accelerator=shortcut,
                command=lambda callback=action: self.menu_command(callback),
            )
        file_menu.add_separator()
        file_menu.add_command(
            label=gettext("Open watched folder"),
            command=lambda: self.menu_command(self.open_watched_folder),
        )
        file_menu.add_command(
            label=gettext("Open log folder"),
            command=lambda: self.menu_command(self.open_config_folder),
        )
        file_menu.add_separator()
        file_menu.add_command(label=gettext("Exit"), command=lambda: self.menu_command(self.close))

        edit = runtime.tk.Menu(menu, tearoff=False, postcommand=self.remember_edit_focus)
        menu.add_cascade(label=gettext("Edit"), underline=0, menu=edit)
        for label, event, shortcut in (
            (gettext("Cut"), "<<Cut>>", "Ctrl+X"),
            (gettext("Copy"), "<<Copy>>", "Ctrl+C"),
            (gettext("Paste"), "<<Paste>>", "Ctrl+V"),
            (gettext("Select all"), "<<SelectAll>>", "Ctrl+A"),
        ):
            edit.add_command(
                label=label, accelerator=shortcut,
                command=lambda sequence=event: self.menu_edit(sequence),
            )
        edit.add_separator()
        edit.add_command(
            label=gettext("Settings…"), accelerator="Ctrl+,",
            command=lambda: self.menu_command(lambda: self.select_tab("settings")),
        )
        view = runtime.tk.Menu(menu, tearoff=False)
        menu.add_cascade(label=gettext("View"), underline=0, menu=view)
        for name, label in (
            ("photos", gettext("Photos")), ("watch", gettext("Watcher")),
            ("import", gettext("Import")), ("activity", gettext("Activity")),
            ("ai", "AI"), ("settings", gettext("Settings")),
        ):
            view.add_command(
                label=label,
                command=lambda key=name: self.menu_command(lambda: self.select_tab(key)),
            )
        tools = runtime.tk.Menu(menu, tearoff=False)
        menu.add_cascade(label=gettext("Tools"), underline=0, menu=tools)
        tools.add_command(
            label=gettext("GUI backend settings…"), accelerator="Ctrl+Shift+B",
            command=lambda: self.menu_command(self.focus_backend_picker),
        )
        tools.add_separator()
        for label, action in (
            (gettext("Watcher settings…"), self.open_watcher_settings),
            (gettext("Refresh upload queue"), self.refresh_upload_queue),
            (gettext("Gallery connections…"), self.open_gallery_links),
            (gettext("API-key connection…"), self.open_connection_dialog),
            (gettext("Check for updates…"), self.updates.check),
            (gettext("Support diagnostics…"), self.open_support_report),
        ):
            tools.add_command(
                label=label,
                command=lambda callback=action: self.menu_command(callback),
            )
        tools.add_command(
            label=gettext("Choose gallery…"), accelerator="Ctrl+Shift+G",
            command=lambda: self.menu_command(self.choose_linked_gallery),
        )
        help_menu = runtime.tk.Menu(menu, tearoff=False)
        menu.add_cascade(label=gettext("Help"), underline=0, menu=help_menu)
        help_menu.add_command(
            label=gettext("Using PHP Gallery"), accelerator="F1",
            command=lambda: self.menu_command(self.open_help),
        )
        help_menu.add_command(
            label=gettext("Support diagnostics…"), accelerator="Ctrl+Shift+D",
            command=lambda: self.menu_command(self.open_support_report),
        )
        help_menu.add_command(
            label=gettext("About PHP Gallery"),
            command=lambda: self.menu_command(self.open_about),
        )
        for sequence, callback in (
            ("<Control-o>", self.select_manual_files),
            ("<Control-comma>", lambda: self.select_tab("settings")),
            ("<Control-Shift-B>", self.focus_backend_picker),
            ("<Control-Shift-D>", self.open_support_report),
            ("<Control-Shift-G>", self.choose_linked_gallery),
            ("<F1>", self.open_help),
        ):
            self.root.bind(
                sequence, lambda _event, action=callback: self.menu_command(action),
                add="+",
            )
        self.menu_bar = menu


    def menu_command(self, callback: Callable[[], None]) -> str:
        """Dispatch a menu or shortcut only when no panel owns the interaction.

        Args:
            callback (Callable[[], None]): Existing application action to run.
        Returns:
            str: Tk break marker so shortcuts never reach background controls.
        """
        if active_panel(self.root) is None and not self.exiting:
            callback()
        return "break"


    def focus_backend_picker(self) -> None:
        """Show Settings and focus its saved GUI backend chooser.

        Returns:
            None: Scrolls the existing Settings page to the chooser without
                changing the saved backend or bypassing its confirmation flow.
        """
        self.select_tab("settings")
        page = self.tab_hosts.get("settings")
        settings = getattr(self, "_gui_backend_settings", None)
        if not isinstance(page, ScrollableFrame) or settings is None:
            return

        def focus_picker() -> None:
            """Scroll the mapped page and transfer keyboard focus to its picker.

            Returns:
                None: Leaves the standard combobox selection behavior intact.
            """
            page.canvas.yview_moveto(0.0)
            self.root.update_idletasks()
            content_height = max(1, page.content.winfo_reqheight())
            target_offset = max(
                0, settings.picker.winfo_rooty() - page.canvas.winfo_rooty(),
            )
            page.canvas.yview_moveto(min(1.0, target_offset / content_height))
            settings.picker.focus_set()

        self.root.after_idle(focus_picker)


    def remember_edit_focus(self) -> None:
        """Remember the focused editor before the native Edit menu opens.

        Returns:
            None: Retains the actual text control receiving the next edit action.
        """
        self._edit_focus = self.root.focus_get()


    def menu_edit(self, sequence: str) -> None:
        """Send a standard editing event to the focused control.

        Args:
            sequence (str): Tk Cut, Copy, Paste or SelectAll virtual event.
        Returns:
            None: Edits only a surviving focused control, including panel text.
        """
        target = self._edit_focus
        if target is not None and target.winfo_exists():
            target.event_generate(sequence)


    def open_help(self) -> None:
        """Present operating guidance inside the application window.

        Returns:
            None: Opens a dismissible help panel without starting background work.
        """
        runtime.messagebox.showinfo(
            gettext("Using PHP Gallery"),
            gettext(
                "Connect a gallery, choose a Watcher folder, then explicitly start "
                "capture. Finish drains accepted photographs before completing.\n\n"
                "Use File to choose an import source. Review preflight before "
                "starting an import. View switches between Photos, Watcher, Import, "
                "Activity, AI and Settings.\n\n"
                "Help → Support diagnostics builds a local report you can review, "
                "copy or save for support. Review it before sharing; nothing is sent "
                "automatically."
            ), parent=self.root,
        )


    def open_about(self) -> None:
        """Show the independent application version and attribution in place.

        Returns:
            None: Displays version and license information in the main window.
        """
        runtime.messagebox.showinfo(
            gettext("About PHP Gallery"), gettext(
                "{app_display_name} {app_version}\n"
                "Author: Rudolf Klusal\nMIT License\n"
                "https://github.com/klusik/PHP_gallery"
            ).format(
                app_display_name=gettext(watcher_state.APP_DISPLAY_NAME),
                app_version=runtime.APP_VERSION,
            ), parent=self.root,
        )


    def build_gallery_link_bar(self, header: "ttk.Frame") -> None:
        """Group the selected site, gallery picker and connection actions.

        Args:
            header (ttk.Frame): Responsive header host receiving current link controls.

        Returns:
            None: Shows the live site, keeps its full address on hover and preserves
                existing link actions and status traces in the compact header.
        """
        bar = runtime.ttk.Frame(header, style="App.TFrame")
        bar.grid(row=0, column=0, sticky='ew')
        bar.columnconfigure(0, weight=1)
        address = runtime.ttk.Label(
            bar, style="App.Status.TLabel", wraplength=WINDOW_SUMMARY_WRAP,
        )
        choose = icon_button(
            bar, "≡", self.choose_linked_gallery, gettext("Choose gallery…"),
            tooltip_surface=self._storage_context_surface,
        )
        disconnect_hint = runtime.tk.StringVar(bar)
        disconnect = icon_button(
            bar, "close", self.link_gallery, disconnect_hint,
            tooltip_surface=self._storage_context_surface,
        )
        connect = Button(bar, command=self.link_gallery, style="Compact.TButton")
        links = icon_button(
            bar, "⋯", self.open_gallery_links,
            tr(self.flights.ui_language.get(), "linking_address_manager"),
        )
        # One set of cells keeps global controls anchored through tab changes.
        address.grid(row=0, column=0, sticky="w", padx=(0, 8))
        links.grid(row=0, column=1, sticky="w", padx=(2, 6))
        choose.grid(row=0, column=2, sticky="w", padx=(0, 6))
        disconnect.grid(row=0, column=3, sticky="w")
        connect.grid(row=0, column=2, columnspan=2, sticky="w")
        linked_address = self.linking_manager.linked_address
        status = self.linking_manager.status
        attach_inline_tooltip(address, linked_address, self._storage_context_surface)
        feedback = runtime.ttk.Label(
            bar, textvariable=status, style="App.Status.TLabel",
            wraplength=WINDOW_SUMMARY_WRAP,
        )
        previous: tuple[str, bool, str, bool] | None = None
        reserve_after: str | None = None

        def reserve_actions() -> None:
            """Retain the measured action footprint after ttk applies text.

            Returns:
                None: Each contextual action occupies the same cell when hidden.
            """
            nonlocal reserve_after
            reserve_after = None
            linked = bool(linked_address.get())
            # Reserve only the compact controls belonging to this link state.
            # The choice cell includes its six-pixel right padding.
            bar.columnconfigure(
                2, minsize=choose.winfo_reqwidth() + 6 if linked else connect.winfo_reqwidth(),
            )
            bar.columnconfigure(3, minsize=disconnect.winfo_reqwidth() if linked else 0)
            bar.rowconfigure(0, minsize=max(
                connect.winfo_reqheight(), choose.winfo_reqheight(),
                disconnect.winfo_reqheight(),
            ))
            app_header = getattr(self, '_app_header', None)
            if app_header is not None:
                app_header.request_layout()

        def refresh(*_trace_info: str) -> None:
            """Show only the controls and feedback for the live link state.

            Args:
                *_trace_info (str): Tk variable name, index and write operation.

            Returns:
                None: Maps contextual actions in their reserved cells without
                    rearranging the linked address or header on a tab change.
            """
            nonlocal previous, reserve_after
            issuer = linked_address.get()
            notebook = getattr(self, "notebook", None)
            tab_indices = getattr(self, "tab_indices", {})
            photos_selected = bool(
                notebook is not None
                and notebook.index("current") == tab_indices.get("photos")
            )
            language = self.flights.ui_language.get()
            show_feedback = bool(status.get())
            current = (issuer, photos_selected, language, show_feedback)
            if previous == current:
                return
            remeasure = (
                previous is None
                or (issuer, language) != (previous[0], previous[2])
            )
            if remeasure:
                connect.configure(text=tr(language, 'linking_connect_website'))
                disconnect_hint.set(tr(language, 'linking_disconnect_website'))
                if issuer:
                    # Keep the host and installation path in the header.
                    # The hover/focus description retains the complete URL.
                    address.configure(
                        text=issuer.removeprefix("https://").removeprefix("http://"),
                    )
                if reserve_after is None:
                    reserve_after = bar.after_idle(reserve_actions)
            if previous is None or bool(issuer) != bool(previous[0]):
                if issuer:
                    address.grid()
                    links.grid()
                else:
                    address.grid_remove()
                    links.grid_remove()
            if (previous is None or (bool(issuer), photos_selected)
                    != (bool(previous[0]), previous[1])):
                if issuer and not photos_selected:
                    choose.grid()
                    disconnect.grid()
                else:
                    choose.grid_remove()
                    disconnect.grid_remove()
                if not issuer and not photos_selected:
                    connect.grid()
                else:
                    connect.grid_remove()
            feedback_changed = previous is None or show_feedback != previous[3]
            if show_feedback and (previous is None or not previous[3]):
                feedback.grid(
                    row=1, column=0, columnspan=4, sticky="w", pady=(4, 0),
                )
            elif not show_feedback and previous is not None and previous[3]:
                feedback.grid_remove()
            previous = current
            app_header = getattr(self, '_app_header', None)
            if app_header is not None and feedback_changed:
                app_header.request_layout()

        self._gallery_link_refresh = refresh
        address_trace = linked_address.trace_add("write", refresh)
        status_trace = status.trace_add("write", refresh)

        def release(event: "tk.Event") -> None:
            """Release the link bar's retained layout and variable callbacks.

            Args:
                event (tk.Event): Destroy event from the bar or a child.
            Returns:
                None: Cancels only this bar's pending reservation and traces.
            """
            if event.widget is bar:
                if reserve_after is not None:
                    bar.after_cancel(reserve_after)
                linked_address.trace_remove("write", address_trace)
                status.trace_remove("write", status_trace)

        bar.bind("<Destroy>", release, add="+")
        refresh()


    def select_tab(self, name: str) -> None:
        """Select a named view in the application navigation.

        Args:
            name (str): Stable tab key from ``tab_indices``.

        Returns:
            None: Changes the selected notebook page when the key is known.
        """
        index = self.tab_indices.get(name)
        if index is not None:
            self.notebook.select(index)


    def refresh_navigation(self, _event: "tk.Event | None" = None) -> None:
        """Highlight the current screen in the compact icon navigation rail.

        Args:
            _event (tk.Event | None): Optional notebook selection event.

        Returns:
            None: Highlights the selected page, restores its Activity projection
                when needed, and updates contextual link actions.
        """
        selected = self.notebook.index("current")
        if selected == getattr(self, "_selected_navigation_index", None):
            return
        self._selected_navigation_index = selected
        self._activity_tab_selected = self.tab_indices.get("activity") == selected
        self._import_tab_selected = self.tab_indices.get("import") == selected
        if self._activity_tab_selected:
            type(self)._flush_visible_log(self)
        if ((self._activity_tab_selected or self._import_tab_selected)
                and hasattr(self, "_import_presentation_after")):
            self._flush_import_presentation(force=True)
        if (
            self._activity_tab_selected
            and getattr(self, "_activity_drawer_dirty", False) is True
        ):
            self._schedule_activity_drawer_refresh()
        for key, button in self.navigation_buttons.items():
            if key == "watch" and getattr(self, "_watcher_recording_active", False):
                continue
            style = (
                "Primary.Icon.TButton" if self.tab_indices[key] == selected
                else "App.Icon.TButton"
            )
            _publish_watcher_value(
                self, "navigation_" + key, button, style,
                lambda: button.configure(style=style),
            )
        refresh_gallery_link = getattr(self, "_gallery_link_refresh", None)
        if refresh_gallery_link is not None:
            refresh_gallery_link()


    def toggle_watcher(self) -> None:
        """Dispatch the primary Watcher action for the current session state.

        Returns:
            None: Starts, finishes or continues the original pending queue.
        """
        if (type(self).watcher_reset_blocked(self)
                or getattr(self, "_watch_start_pending", False) is True):
            return
        if not type(self)._watcher_connection_selected(self):
            self.connect_watcher()
            return
        state = self.flights.session_state()
        if (self.worker is not None and self.worker.is_alive()
                and getattr(self.worker, '_recovery_review_only', False) is True):
            self.flights.continue_watch()
        elif state == "working":
            self.flights.show_progress()
        elif state in ("pending", "blocked"):
            if not self._retry_update_continuation():
                self.flights.continue_watch()
        elif state == "active":
            self.stop()
        else:
            if isinstance(getattr(self, "_contextual_watch_target", None), watcher_state.LinkedUploadTarget):
                self.release_contextual_watcher()
                return
            self.start()


    def refresh_watcher_action(self) -> None:
        """Match the primary Watcher caption to capture, drain or recovery.

        Returns:
            None: Synchronizes the primary action and the existing interruption
                controls when their labels, enabled state or visibility change.
        """
        action_button = self.watcher_action_button
        if action_button is None:
            return
        interrupt_button = getattr(self, 'watch_recovery_action_button', None)
        reset_button = getattr(self, 'watcher_reset_button', None)
        resetting = getattr(self, '_watcher_reset_running', False) is True
        blocked = type(self).watcher_reset_blocked(self)
        selected = type(self)._watcher_connection_selected(self)
        if reset_button is not None:
            _publish_watcher_value(
                self, 'watch_reset_busy', reset_button, resetting,
                lambda: reset_button.configure(state='disabled' if resetting else 'normal'),
            )
        if 'watcher_gallery_label_var' in self.__dict__:
            _publish_watcher_text(self, 'watcher_gallery_label_var',
                self.gallery_label_var.get() if selected else gettext('Upload gallery: not set'))
            _publish_watcher_text(self, 'watcher_gallery_title_var',
                self.gallery_title_var.get() if selected else '')
            _publish_watcher_text(self, 'watcher_connection_status_var',
                self.connection_status_var.get() if selected else '')
        if blocked or not selected:
            label = (gettext('Resetting Watcher…') if resetting else gettext('Watcher reset required')
                     if blocked else 'Connect Watcher…')
            state = 'disabled' if blocked else 'normal'
            _publish_watcher_value(
                self, 'watch_action', action_button, (label, state),
                lambda: action_button.configure(text=label, state=state),
            )
            if interrupt_button is not None:
                _publish_watcher_value(
                    self, 'watch_interrupt_visible', interrupt_button, False,
                    interrupt_button.pack_forget,
                )
            return
        if getattr(self, "_watch_start_pending", False) is True:
            pending_label = "Starting Watcher…"
            _publish_watcher_value(
                self, "watch_action", action_button, (pending_label, "disabled"),
                lambda: action_button.configure(text=pending_label, state="disabled"),
            )
            if interrupt_button is not None:
                _publish_watcher_value(
                    self, "watch_interrupt_visible", interrupt_button, False,
                    interrupt_button.pack_forget,
                )
            return
        state = self.flights.session_state()
        if (state == "active"
                and isinstance(getattr(self, "_contextual_watch_target", None),
                               watcher_state.LinkedUploadTarget)):
            self.contextual_watcher_is_flight()
        language = self.flights.ui_language.get()
        continue_text = recovery_text(language, 'watch_recovery_resume_pending')
        labels = {
            "active": "■  Stop Watcher",
            "working": self.flights.text("show_upload_progress"),
            "pending": continue_text,
            "blocked": continue_text,
            "idle": "▶  Start Watcher",
        }
        worker = self.worker
        capture_live = (
            worker is not None and worker.is_alive()
            and getattr(worker, '_queue', None) is not None
        )
        if capture_live and getattr(worker, '_recovery_review_only', False) is True:
            labels[state] = continue_text
        label = labels[state]
        if (state == "idle"
                and isinstance(getattr(self, "_contextual_watch_target", None),
                               watcher_state.LinkedUploadTarget)):
            label = (
                "Closing Watcher access…" if self._watcher_key_closing
                else "Retry closing Watcher access"
            )
        _publish_watcher_value(
            self, "watch_action", action_button, (label, "normal"),
            lambda: action_button.configure(text=label, state="normal"),
        )
        if interrupt_button is not None:
            operation = self.flights.operation
            can_interrupt = capture_live and (
                operation is None or not operation.server_request_sent
            )
            if can_interrupt:
                interrupt_label = recovery_text(language, 'force_stop_watch')
                _publish_watcher_value(
                    self, "watch_interrupt_text", interrupt_button, interrupt_label,
                    lambda: interrupt_button.configure(text=interrupt_label),
                )
            _publish_watcher_value(
                self, "watch_interrupt_visible", interrupt_button, can_interrupt,
                (lambda: interrupt_button.pack(side='right')) if can_interrupt
                else interrupt_button.pack_forget,
            )
        indicator = getattr(self, "watcher_capture_label", None)
        if indicator is not None:
            capturing = state == "active"
            _publish_watcher_value(
                self, "watch_capture_visible", indicator, capturing,
                indicator.grid if capturing else indicator.grid_remove,
            )


    def _set_watcher_recording(
        self, active: bool, target: watcher_state.LinkedUploadTarget | None = None,
    ) -> None:
        """Start or stop the app-wide Watcher recording marker pulse.

        Args:
            active (bool): Whether an active folder capture owns the indicator.
            target (LinkedUploadTarget | None): Exact linked target, or None for
                legacy Watcher capture.

        Returns:
            None: Updates the rail and Photos workspace and owns one Tk timer.
        """
        if not active or self.exiting:
            if self._watcher_recording_after is not None:
                self.root.after_cancel(self._watcher_recording_after)
                self._watcher_recording_after = None
            self._watcher_recording_target = None
            self._watcher_recording_active = False
            self._watcher_recording_pulse = False
            self._render_watcher_recording()
            return
        self._watcher_recording_target = target
        self._watcher_recording_active = True
        if self._watcher_recording_after is None:
            self._watcher_recording_after = self.root.after(
                watcher_state.WATCHER_RECORDING_PULSE_MS, self._pulse_watcher_recording,
            )
        self._render_watcher_recording()


    def _sync_watcher_recording(self) -> None:
        """Hydrate the recording marker from the live worker lifecycle.

        Returns:
            None: Reflects active capture without repolling or repainting Photos.
        """
        worker = getattr(self, "worker", None)
        active = bool(
            not self.exiting and worker is not None and worker.is_alive()
            and not worker.stop_event.is_set()
            and not worker.drain_only
            and not worker.drain_event.is_set()
            and getattr(worker, "initial_captured", True)
        )
        target = getattr(worker, "linked_target", None) if active else None
        if (not isinstance(target, watcher_state.LinkedUploadTarget)
                and isinstance(getattr(self, "_contextual_watch_target", None),
                               watcher_state.LinkedUploadTarget)):
            target = self._contextual_watch_target
        self._set_watcher_recording(
            active,
            target if isinstance(target, watcher_state.LinkedUploadTarget) else None,
        )


    def _pulse_watcher_recording(self) -> None:
        """Alternate visible red shades while Watcher capture remains active.

        Returns:
            None: Schedules the next bounded pulse or clears stale worker state.
        """
        self._watcher_recording_after = None
        worker = getattr(self, "worker", None)
        if (self.exiting or worker is None or not worker.is_alive()
                or worker.stop_event.is_set() or worker.drain_only):
            self._set_watcher_recording(False)
            return
        self._watcher_recording_pulse = not self._watcher_recording_pulse
        self._render_watcher_recording()
        self._watcher_recording_after = self.root.after(
            watcher_state.WATCHER_RECORDING_PULSE_MS, self._pulse_watcher_recording,
        )


    def _render_watcher_recording(self) -> None:
        """Paint rail, tray and header markers and forward Photos target state.

        Returns:
            None: Updates rail, tray, header and Photos from the shared pulse.
        """
        button = getattr(self, "navigation_buttons", {}).get("watch")
        if button is not None:
            if self._watcher_recording_active:
                style = (
                    "Watcher.RecordingBright.Icon.TButton"
                    if self._watcher_recording_pulse
                    else "Watcher.RecordingDim.Icon.TButton"
                )
            else:
                notebook = getattr(self, "notebook", None)
                try:
                    selected = (
                        notebook is not None
                        and self.tab_indices.get("watch")
                        == notebook.index("current")
                    )
                except (AttributeError, runtime.tk.TclError):
                    selected = False
                style = (
                    "Primary.Icon.TButton" if selected else "App.Icon.TButton"
                )
            _publish_watcher_value(
                self, "navigation_watch", button, style,
                lambda: button.configure(style=style),
            )
        workspace = getattr(self, "photos_workspace", None)
        callback = getattr(workspace, "set_watcher_recording", None)
        if callback is not None:
            callback(
                self._watcher_recording_target,
                self._watcher_recording_pulse,
            )
        tray_icon = getattr(self, "tray_icon", None)
        if tray_icon is not None:
            try:
                tray_icon.icon = self.make_tray_icon_image()
            except Exception:  # noqa: BLE001
                logging.debug("Could not refresh tray Watcher marker.", exc_info=True)
        self.refresh_watcher_header()


    def configure_window_icon(self) -> None:
        """
        Apply the bundled Windows icon to the Tkinter window when available.

        Returns:
            None: Applies the icon when available and logs load failures.
        """
        if not runtime.TRAY_ICON_ICO_PATH.is_file():
            return
        try:
            self.root.iconbitmap(default=str(runtime.TRAY_ICON_ICO_PATH))
        except Exception as exc:  # noqa: BLE001
            logging.warning("Window icon could not be loaded: %s", exc)


    def make_tray_icon_image(self) -> "Image.Image":
        """Load the base art and compose current update and Watcher badges.

        Raises:
            RuntimeError: Tray image support is unavailable without Pillow.
            OSError: The bundled tray image cannot be read.

        Returns:
            Image.Image: Fresh RGBA tray artwork derived from the bundled base.
        """
        if runtime.Image is None:
            raise RuntimeError("Pillow is required to compose the tray icon.")
        with runtime.Image.open(runtime.TRAY_ICON_PNG_PATH) as opened:
            base_image = opened.convert("RGBA")
        return render_tray_icon(
            base_image,
            self.update_available_version is not None,
            self._watcher_recording_active and self._watcher_recording_pulse,
        )


    def build_tray_menu(self) -> Optional["pystray.Menu"]:
        """Create the tray menu with an update-aware first action.

        Returns:
            Optional[pystray.Menu]: Current native tray menu, or None when
                pystray is unavailable in this runtime.
        """
        if runtime.pystray is None:
            return None
        return runtime.pystray.Menu(
            runtime.pystray.MenuItem(self.tray_update_menu_label(), self.tray_open_updates),
            runtime.pystray.MenuItem(gettext("Open"), self.tray_restore_window, default=True),
            runtime.pystray.MenuItem(gettext("Open activity"), self.tray_open_activity),
            runtime.pystray.MenuItem(gettext("Connection / API key..."), self.tray_open_connection),
            runtime.pystray.MenuItem(gettext("Revoke API key..."), self.tray_revoke_api_key),
            runtime.pystray.MenuItem(gettext("Pause/resume current import"), self.tray_toggle_import_pause),
            runtime.pystray.Menu.SEPARATOR,
            runtime.pystray.MenuItem(gettext("Start watching"), self.tray_start_watching),
            runtime.pystray.MenuItem(gettext("Stop watching"), self.tray_stop_watching),
            runtime.pystray.MenuItem(gettext("Start AI metadata worker"), self.tray_start_ai_worker),
            runtime.pystray.MenuItem(gettext("Stop AI metadata worker"), self.tray_stop_ai_worker),
            runtime.pystray.Menu.SEPARATOR,
            runtime.pystray.MenuItem(gettext("Open log folder"), self.tray_open_log_folder),
            runtime.pystray.MenuItem(gettext("Exit"), self.tray_exit_application),
        )


    def start_tray_icon(self) -> None:
        """Start the Windows tray icon with current update state.

        Returns:
            None: Starts Windows tray integration when supported; the macOS
                source app remains available in its main window.
        """
        if runtime.sys.platform == "darwin":
            # Tk owns the Cocoa main loop. A Windows-style tray worker would
            # call AppKit off the main thread and terminate the source app.
            return
        if runtime.pystray is None or runtime.Image is None:
            self.write_log("System tray unavailable. Run install.bat to install pystray and Pillow.", "warning")
            return
        if not runtime.TRAY_ICON_PNG_PATH.is_file():
            self.write_log(f"System tray icon asset is missing: {runtime.TRAY_ICON_PNG_PATH}", "warning")
            return

        try:
            self.tray_icon = runtime.pystray.Icon(
                watcher_state.APP_NAME,
                self.make_tray_icon_image(),
                self.tray_tooltip_text(),
                self.build_tray_menu(),
            )
            self.tray_thread = watcher_state.threading.Thread(target=self.tray_icon.run, name="PHPGalleryTray", daemon=True)
            self.tray_thread.start()
            self.write_log("System tray icon is active.", "system")
        except Exception as exc:  # noqa: BLE001
            self.tray_icon = None
            self.tray_thread = None
            self.write_log(f"System tray could not be started: {exc}", "warning")


    def schedule_ui(self, callback: Callable[[], None]) -> None:
        """
        Run a tray callback on the Tkinter UI thread.

        Args:
            callback (Callable[[], None]): UI action called without arguments
                on the Tk thread; it returns no value.

        Returns:
            None: Run a tray callback on the Tkinter UI thread.
        """
        if self.exiting:
            return
        try:
            self.root.after(0, callback)
        except Exception:  # noqa: BLE001
            logging.debug("Ignored tray callback after Tk shutdown.", exc_info=True)


    def tray_restore_window(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Restore the hidden window from a tray icon action.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.restore_from_tray)


    def tray_open_updates(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Restore the application and open the existing update notice.

        Args:
            *_args (pystray.Icon | pystray.MenuItem): Native tray callback
                arguments supplied by pystray; direct calls may omit them.

        Returns:
            None: Schedules the updater entry point on Tk's UI thread.
        """
        self.schedule_ui(self.open_update_notice)


    def tray_open_activity(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Restore the window and select the Activity section from the tray.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.open_activity_tab)


    def tray_open_connection(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Open the connection and API-key editor from the tray.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.open_connection_dialog)


    def tray_revoke_api_key(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Start the guarded API-key revocation flow from the tray.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.revoke_api_key)


    def tray_toggle_import_pause(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Pause or resume the current manual import from the tray.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        def toggle() -> None:
            """Pause or resume the active Import worker on the Tk thread.

            Returns:
                None: Updates the active worker or reports that none is active.
            """
            worker = self.manual_worker
            if worker is None or not worker.is_alive():
                self.write_log("No active manual import to pause or resume.", "system")
                return
            if worker.pause_event.is_set():
                self.resume_manual_upload()
            else:
                self.pause_manual_upload()
        self.schedule_ui(toggle)


    def tray_open_log_folder(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Open the local app/log folder from the tray.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.open_config_folder)


    def tray_start_watching(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Start the watcher from the tray menu.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.start)


    def tray_stop_watching(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Stop the watcher from the tray menu.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.stop)


    def tray_start_ai_worker(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Start the optional AI metadata worker from the tray menu.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.start_ai_worker)


    def tray_stop_ai_worker(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Stop the optional AI metadata worker from the tray menu.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.stop_ai_worker)


    def tray_exit_application(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Exit the application from the tray menu.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.close)


    def request_window_close(self) -> None:
        """
        Apply the configured close-to-tray policy or perform coordinated exit.

        Returns:
            None: Hides the window when configured or starts coordinated exit.
        """
        if self.tray_icon and self.close_to_tray_var.get():
            self.hide_to_tray()
            return
        self.close()


    def api_key_request_blockers(self, *, include_watcher: bool = True) -> List[str]:
        """
        Return exact activities that can still issue authenticated gallery requests.

        Args:
            include_watcher (bool): Include a live Watcher even when its
                owning caller is about to request a coordinated checkpoint.
        Returns:
            List[str]: Human-readable blocker labels.
        """
        blockers: List[str] = []
        if include_watcher and self.worker and self.worker.is_alive():
            blockers.append(gettext("watch-folder uploader is running"))
        if self.manual_worker and self.manual_worker.is_alive():
            blockers.append(gettext("manual import is running"))
        if self.preflight_worker and self.preflight_worker.is_alive():
            blockers.append(gettext("import preflight is running"))
        if self.ai_worker and self.ai_worker.is_alive():
            blockers.append(gettext("AI metadata worker is running"))
        if self.connection_test_running:
            blockers.append(gettext("connection test is running"))
        flights = getattr(self, "flights", None)
        operation = getattr(flights, "operation", None)
        if (
            operation is not None
            and getattr(operation, "saved_upload", False) is True
            and operation.is_alive()
        ):
            blockers.append(gettext("saved flight PDF upload is running"))
        return blockers


    def background_work_active(self, *, include_watcher: bool = True) -> bool:
        """
        Return whether any background worker or long-running lifecycle operation is running.

        Args:
            include_watcher (bool): Count live capture as an independent blocker.
        Returns:
            bool: True when background work is active.
        """
        return bool(
            self.api_key_request_blockers(include_watcher=include_watcher)
            or self.semantic_ai_install_running
            or self.api_key_revoke_running
            or getattr(self, "_watcher_key_closing", False) is True
        )


    def handle_window_unmap(self, event: "tk.Event") -> None:
        """
        Convert normal window minimization into tray hiding.

        Args:
            event (tk.Event): Unmap notification whose widget identifies the
                Tk window being minimized.

        Returns:
            None: Convert normal window minimization into tray hiding.
        """
        if self.exiting or self.window_hidden_to_tray or event.widget is not self.root:
            return
        self.root.after(100, self.hide_if_minimized)


    def hide_if_minimized(self) -> None:
        """
        Hide the window to tray after a user minimize action when configured.

        Returns:
            None: Hides an iconic window when the tray policy is enabled.
        """
        if self.exiting or self.window_hidden_to_tray or not self.tray_icon or not self.close_to_tray_var.get():
            return
        try:
            if self.root.state() == "iconic":
                self.hide_to_tray()
        except Exception:  # noqa: BLE001
            logging.debug("Ignored minimize-to-tray check after Tk shutdown.", exc_info=True)


    def hide_to_tray(self) -> None:
        """Hide the window while keeping the app alive in the system tray.

        Returns:
            None: Saves a safe layout snapshot before withdrawing the window.
        """
        if not self.tray_icon:
            return
        self._save_ui_layout_now(allow_iconic=True)
        self.window_hidden_to_tray = True
        self.root.withdraw()
        self.write_log("Window hidden to tray. Use the tray icon to restore it.", "system")


    def restore_from_tray(self) -> None:
        """Show the existing window after a tray action or explicit relaunch.

        Returns:
            None: Restores the focused window and its latest Activity
                presentation, then admits deferred recovery once.
        """
        if self.exiting:
            return
        self.window_hidden_to_tray = False
        self.startup_waiting_for_tray = False
        self.root.deiconify()
        try:
            requested_state = self._ui_layout_state.window_state
            self.root.state(
                "zoomed" if requested_state == "zoomed" else "normal",
            )
        except Exception:  # noqa: BLE001
            logging.debug("Could not force normal window state.", exc_info=True)
        self.root.lift()
        self.root.focus_force()
        self._flush_activity_footer()
        type(self)._flush_visible_log(self)
        if hasattr(self, "_import_presentation_after"):
            self._flush_import_presentation(force=True)
        if getattr(self, "_activity_drawer_dirty", False) is True:
            self._schedule_activity_drawer_refresh()
        if getattr(self, "recovery_offer_pending", False):
            self.recovery_offer_pending = False
            self.root.after_idle(self.offer_recoverable_jobs)


    def stop_tray_icon(self) -> None:
        """
        Stop the tray icon loop during application shutdown.

        Returns:
            None: Hides and stops the tray icon when one is active.
        """
        icon = self.tray_icon
        self.tray_icon = None
        if icon is None:
            return
        try:
            icon.visible = False
            icon.stop()
        except Exception:  # noqa: BLE001
            logging.debug("Tray icon shutdown failed.", exc_info=True)
