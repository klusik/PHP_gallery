# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/ui/settings/preferences.py
# Module Type: Windows Watcher Settings UI
# Purpose: Own Watcher and desktop preferences and connection controls.
# Responsibilities:
#   - Render and persist preferences through the shared mode/configuration owners.
#   - Keep settings actions bound to their exact current connection and profile.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own Watcher settings preferences and connection controls."""

from uploader.watcher import runtime
from uploader.watcher import state as watcher_state
from typing import Optional, Tuple
from uploader.mode_coordinator import CoordinatedWork, GuiModeOwner, ModeConfigPort
from uploader.watcher.state import WatcherConfig
from uploader.localization import gettext, gettext_noop
from ..support import ScrollableFrame, WATCHER_SETTINGS_FALLBACK_SIZE, WATCHER_SETTINGS_SCREEN_MARGIN, WINDOW_SUMMARY_WRAP, current_language, worker_choice_values
from uploader.config import THUMBNAIL_BATCH_CHOICES
from uploader.config import ai_backend_labels
from uploader.config import save_thumbnail_batch_size
from uploader.config import save_ui_language
from uploader.flight_jobs import connection_binding
from uploader.flight_workflow import WatchedReportTarget
from uploader.localization import LANGUAGE_NAMES
from uploader.tk_backend_settings import TkBackendSettings
from uploader.ui_layout import WATCHER_SETTINGS_MINIMUM
from uploader.ui_shell import EmbeddedWindow
from uploader.ui_theme import Button
from uploader.ui_theme import GlassCard
from uploader.ui_theme import icon_button
from uploader.watcher.profile import WatcherProfile
from uploader.windows_startup import startup_supported
from uploader.windows_startup import synchronize_startup
from uploader.http_transport import post_json_resolved

# Purpose: Keep explanatory runtime-setting text inside the compact settings card.
# Type: int.
# Units: logical Tk pixels.
# Scope: runtime preference helper labels.
# Consumers: WatcherApp.build_settings_tab.
# Rationale: 650 logical pixels keeps runtime help inside its settings card.

WATCHER_RUNTIME_HELP_WRAP_PIXELS = 650

class WatcherPreferencesMixin:
    """Own this focused Watcher UI lifecycle responsibility."""

    def open_watcher_settings(self) -> None:
        """Open focused watcher preferences with explicit save or cancel.

        Returns:
            None: Shows folder, scan interval, stability and upload policy
            controls without leaving the primary Watcher workflow.
        """
        if (type(self).watcher_reset_blocked(self)
                or getattr(self, "updates", None) is not None and self.updates.blocked()):
            return
        if self.worker is not None and self.worker.is_alive():
            runtime.messagebox.showinfo(
                gettext("Watcher settings"),
                gettext("Stop the watcher before changing its folder or upload policy. Changes apply when the next watcher starts."),
                parent=self.root,
            )
            return
        dialog = EmbeddedWindow(self.root)
        dialog.title(gettext("Watcher settings"))
        dialog.transient(self.root)
        dialog.resizable(True, True)
        dialog.columnconfigure(0, weight=1)
        dialog.rowconfigure(0, weight=1)

        previous = {
            "folder": self.watched_folder_var.get(),
            "ignored": self.watch_ignored_var.get(),
            "last_scan": self.watch_last_scan_var.get(),
            "interval": self.interval_var.get(),
            "recursive": self.watch_recursive_var.get(),
            "stable": self.stable_var.get(),
            "sim_location": self.attach_sim_camera_metadata_var.get(),
            "delete": self.delete_uploaded_files_var.get(),
        }
        scroller = ScrollableFrame(dialog)
        scroller.grid(row=0, column=0, sticky="nsew")
        scroller.content.columnconfigure(0, weight=1)
        panel = GlassCard(scroller.content, padding=(20, 18))
        panel.grid(row=0, column=0, sticky="ew", padx=12, pady=12)
        panel.columnconfigure(0, weight=1)
        runtime.ttk.Label(panel, text=gettext("WATCHER PREFERENCES"), style="Eyebrow.TLabel").grid(
            row=0, column=0, sticky="w"
        )
        runtime.ttk.Label(panel, text=gettext("Watch folder")).grid(row=1, column=0, sticky="w", pady=(12, 5))
        folder_row = runtime.ttk.Frame(panel, style="Card.TFrame")
        folder_row.grid(row=2, column=0, sticky="ew")
        folder_row.columnconfigure(0, weight=1)
        folder_entry = runtime.ttk.Entry(
            folder_row,
            textvariable=self.watched_folder_var,
            style="Large.TEntry",
        )
        folder_entry.grid(row=0, column=0, sticky="ew")
        icon_button(
            folder_row, "add", lambda: self.browse_folder(parent=dialog),
            gettext("Choose watched folder"),
        ).grid(row=0, column=1, padx=(7, 0))
        icon_button(
            folder_row, "↗", self.open_watched_folder, gettext("Open watched folder"),
        ).grid(row=0, column=2, padx=(4, 0))
        runtime.ttk.Checkbutton(
            panel,
            text=gettext("Include subfolders"),
            variable=self.watch_recursive_var,
        ).grid(row=3, column=0, sticky="w", pady=(10, 0))
        interval_row = runtime.ttk.Frame(panel, style="Card.TFrame")
        interval_row.grid(row=4, column=0, sticky="w", pady=(8, 0))
        runtime.ttk.Label(interval_row, text=gettext("Scan folder every")).pack(side="left")
        runtime.ttk.Entry(interval_row, textvariable=self.interval_var, width=8).pack(
            side="left", padx=7
        )
        runtime.ttk.Label(interval_row, text=gettext("seconds")).pack(side="left")
        stable_row = runtime.ttk.Frame(panel, style="Card.TFrame")
        stable_row.grid(row=5, column=0, sticky="w", pady=(5, 0))
        runtime.ttk.Label(stable_row, text=gettext("File stable for")).pack(side="left")
        runtime.ttk.Entry(stable_row, textvariable=self.stable_var, width=8).pack(side="left", padx=7)
        runtime.ttk.Label(stable_row, text=gettext("seconds")).pack(side="left")
        runtime.ttk.Checkbutton(
            panel,
            text=gettext("Attach simulator location"),
            variable=self.attach_sim_camera_metadata_var,
        ).grid(row=6, column=0, sticky="w", pady=(8, 0))
        runtime.ttk.Checkbutton(
            panel,
            text=gettext("Delete uploaded photos from this PC"),
            variable=self.delete_uploaded_files_var,
            command=self.refresh_watch_delete_warning,
        ).grid(row=7, column=0, sticky="w", pady=(6, 0))
        runtime.ttk.Label(
            panel,
            text=gettext("After confirmed upload. Existing and changed files are kept."),
            style="Muted.TLabel",
            wraplength=WINDOW_SUMMARY_WRAP,
        ).grid(row=8, column=0, sticky="w", padx=(24, 0), pady=(1, 0))
        footer = GlassCard(dialog, padding=(14, 10))
        footer.grid(row=1, column=0, sticky="ew", padx=12, pady=(0, 12))

        def cancel_changes() -> None:
            """Restore saved form values and close without saving.

            Returns:
                None: Restores the opening snapshot of watcher preferences.
            """
            self.watched_folder_var.set(previous["folder"])
            self.watch_ignored_var.set(previous["ignored"])
            self.watch_last_scan_var.set(previous["last_scan"])
            self.interval_var.set(previous["interval"])
            self.watch_recursive_var.set(previous["recursive"])
            self.stable_var.set(previous["stable"])
            self.attach_sim_camera_metadata_var.set(previous["sim_location"])
            self.delete_uploaded_files_var.set(previous["delete"])
            self.refresh_watch_delete_warning()
            dialog.destroy()

        def save_changes() -> None:
            """Persist watcher preferences and close after a successful save.

            Returns:
                None: Closes the sheet only when the configuration is saved.
            """
            try:
                type(self)._save_watcher_preferences(self, self.current_watcher_config())
            except (OSError, ValueError, watcher_state.ModeCoordinatorError) as exc:
                runtime.messagebox.showerror(gettext("Watcher settings"), gettext(str(exc)), parent=dialog)
            else:
                dialog.destroy()

        Button(footer, text=gettext("Cancel"), command=cancel_changes).pack(side="right")
        Button(
            footer,
            text=gettext("Save watcher settings"),
            command=save_changes,
            style="Primary.TButton",
        ).pack(side="right", padx=(0, 8))
        self.refresh_watch_delete_warning()
        dialog.protocol("WM_DELETE_WINDOW", cancel_changes)
        dialog.bind("<Escape>", lambda _event: cancel_changes())
        dialog.update_idletasks()
        screen_width = dialog.winfo_screenwidth()
        screen_height = dialog.winfo_screenheight()
        margin_width, margin_height = WATCHER_SETTINGS_SCREEN_MARGIN
        fallback_width, fallback_height = WATCHER_SETTINGS_FALLBACK_SIZE
        minimum_width, minimum_height = WATCHER_SETTINGS_MINIMUM
        content_height = (
            scroller.content.winfo_reqheight()
            + footer.winfo_reqheight()
            + 20
        )
        width = min(
            max(dialog.winfo_reqwidth(), minimum_width),
            max(fallback_width, screen_width - margin_width),
        )
        height = min(
            max(content_height, minimum_height),
            max(fallback_height, screen_height - margin_height),
        )
        dialog.geometry(f"{width}x{height}")
        dialog.update_idletasks()
        parent_x = self.root.winfo_rootx()
        parent_y = self.root.winfo_rooty()
        parent_width = self.root.winfo_width()
        parent_height = self.root.winfo_height()
        x = min(
            max(0, parent_x + (parent_width - width) // 2),
            max(0, screen_width - width - margin_width),
        )
        y = min(
            max(0, parent_y + (parent_height - height) // 2),
            max(0, screen_height - height - margin_height),
        )
        dialog.geometry(f"{width}x{height}+{x}+{y}")
        dialog.grab_set()
        folder_entry.focus_set()

    def build_settings_tab(self, parent: "tk.Misc") -> None:
        """Build compact connection and operator preferences sections.

        Args:
            parent (tk.Misc): Scrollable Settings page receiving the controls.

        Returns:
            None: Creates connection, interface, runtime and advanced controls
                including the independently saved gallery download setting.
        """
        parent.columnconfigure(0, weight=1)
        connection = GlassCard(parent, padding=(14, 10))
        connection.grid(row=0, column=0, sticky="ew", padx=6, pady=4)
        connection.columnconfigure(1, weight=1)
        runtime.ttk.Label(connection, text=gettext("GALLERY CONNECTION"), style="Eyebrow.TLabel").grid(
            row=0, column=0, sticky="w", pady=(0, 8)
        )
        runtime.ttk.Label(connection, text=gettext("Gallery URL")).grid(row=1, column=0, sticky="w", padx=(0, 10), pady=5)
        self.gallery_url_entry = runtime.ttk.Entry(connection, textvariable=self.gallery_url_var)
        self.gallery_url_entry.grid(row=1, column=1, columnspan=3, sticky="ew", pady=5)
        runtime.ttk.Label(connection, text=gettext("API key")).grid(row=2, column=0, sticky="w", padx=(0, 10), pady=5)
        self.api_key_entry = runtime.ttk.Entry(connection, textvariable=self.api_key_var, show="*")
        self.api_key_entry.grid(row=2, column=1, sticky="ew", pady=5)
        icon_button(connection, "info", self.toggle_api_key_visibility, gettext("Reveal or mask API key")).grid(row=2, column=2, padx=(6, 2), pady=5)
        icon_button(connection, "add", self.paste_api_key_from_clipboard, gettext("Paste API key")).grid(row=2, column=3, padx=(2, 0), pady=5)
        runtime.ttk.Label(connection, textvariable=self.api_key_status_var, style="Muted.TLabel").grid(
            row=3, column=1, sticky="w", pady=(1, 0)
        )
        runtime.ttk.Label(connection, textvariable=self.connection_detail_var, style="Muted.TLabel", wraplength=760).grid(
            row=4, column=1, columnspan=3, sticky="w", pady=(5, 0)
        )
        actions = runtime.ttk.Frame(connection, style="Card.TFrame")
        actions.grid(row=5, column=1, columnspan=3, sticky="ew", pady=(10, 0))
        Button(actions, text=gettext("✓  Save & Test"), command=self.save_and_test_connection, style="Primary.TButton").pack(side="left")
        icon_button(actions, "add", self.open_connection_dialog, gettext("Open protected credential editor")).pack(side="left", padx=(7, 0))
        self.revoke_button = Button(actions, text=gettext("Revoke key…"), command=self.revoke_api_key)
        self.revoke_button.pack(side="left", padx=(7, 0))
        Button(
            actions, text=gettext("Manage link…"), command=self.manage_gallery_link,
        ).pack(side="left", padx=(7, 0))
        runtime.ttk.Label(connection, textvariable=self.revoke_blocker_var, style="Muted.TLabel", wraplength=WINDOW_SUMMARY_WRAP).grid(
            row=6, column=1, columnspan=3, sticky="w", pady=(6, 0)
        )

        runtime_card = GlassCard(parent, padding=(14, 10))
        runtime_card.grid(row=1, column=0, sticky="ew", padx=6, pady=4)
        runtime_card.columnconfigure(1, weight=1)
        runtime.ttk.Label(runtime_card, text=gettext("APP & INTEGRATIONS"), style="Eyebrow.TLabel").grid(
            row=0, column=0, columnspan=3, sticky="w", pady=(0, 7)
        )
        runtime.ttk.Label(runtime_card, text=gettext("Language")).grid(
            row=1, column=0, sticky="w", pady=5,
        )
        self.interface_language_var = runtime.tk.StringVar(
            self.root, value=LANGUAGE_NAMES[self._saved_ui_language],
        )
        language_picker = runtime.ttk.Combobox(
            runtime_card, state="readonly", textvariable=self.interface_language_var,
            values=tuple(LANGUAGE_NAMES.values()),
        )
        language_picker.grid(row=1, column=1, sticky="w", padx=8, pady=5)
        language_picker.bind("<<ComboboxSelected>>", self.save_interface_language)
        self.interface_language_pending = runtime.ttk.Frame(runtime_card, style="Card.TFrame")
        self.interface_language_pending.grid(
            row=2, column=1, columnspan=2, sticky="w", padx=8,
        )
        runtime.ttk.Label(
            self.interface_language_pending,
            text=gettext("Restart to apply the selected language."),
            style="Muted.TLabel",
        ).pack(side="left")
        Button(
            self.interface_language_pending,
            text=gettext("Restart WinApp"),
            command=lambda: self.close(restart=True),
        ).pack(side="left", padx=(8, 0))
        self.refresh_interface_restart()
        runtime.ttk.Label(runtime_card, text=gettext("Server thumbnails per request")).grid(
            row=3, column=0, sticky="w", pady=5,
        )
        self.thumbnail_batch_size_var = runtime.tk.StringVar(
            self.root, value=str(self._thumbnail_batch_size),
        )
        thumbnail_batch_picker = runtime.ttk.Combobox(
            runtime_card, state="readonly", textvariable=self.thumbnail_batch_size_var,
            values=THUMBNAIL_BATCH_CHOICES, width=8,
        )
        thumbnail_batch_picker.grid(row=3, column=1, sticky="w", padx=8, pady=5)
        thumbnail_batch_picker.bind(
            "<<ComboboxSelected>>", self.save_gallery_thumbnail_batch_size,
        )
        runtime.ttk.Label(
            runtime_card,
            text=gettext("Saves immediately. The first thumbnail request stays small."),
            style="Muted.TLabel", wraplength=WATCHER_RUNTIME_HELP_WRAP_PIXELS,
        ).grid(row=4, column=1, columnspan=2, sticky="w", padx=8)
        runtime.ttk.Label(runtime_card, textvariable=self.thumbnail_runtime_var, style="Muted.TLabel", wraplength=WINDOW_SUMMARY_WRAP).grid(
            row=5, column=0, columnspan=2, sticky="w", pady=5
        )
        Button(runtime_card, text=gettext("Repair dependencies"), command=self.repair_dependencies).grid(row=5, column=2, padx=(8, 0), pady=5)
        runtime.ttk.Label(runtime_card, text=gettext("Custom SimConnect.dll")).grid(
            row=6, column=0, sticky="w", pady=5,
        )
        runtime.ttk.Entry(runtime_card, textvariable=self.simconnect_dll_path_var).grid(row=6, column=1, sticky="ew", padx=8, pady=5)
        Button(runtime_card, text=gettext("Choose"), command=self.browse_simconnect_dll).grid(row=6, column=2, pady=5)
        runtime.ttk.Checkbutton(runtime_card, text=gettext("Keep working in the system tray when the window closes"), variable=self.close_to_tray_var).grid(
            row=7, column=1, columnspan=2, sticky="w", pady=(5, 0)
        )
        startup_switch = runtime.ttk.Checkbutton(
            runtime_card,
            text=gettext("Start with Windows (minimized to tray)"),
            variable=self.start_with_windows_var,
            command=self.save_startup_preference,
        )
        startup_switch.grid(
            row=8, column=1, columnspan=2, sticky="w", pady=(5, 0),
        )
        if not startup_supported():
            startup_switch.state(["disabled"])
        runtime.ttk.Label(
            runtime_card,
            text=gettext("Installed Windows app only. This switch saves immediately."),
            style="Muted.TLabel",
        ).grid(row=9, column=1, columnspan=2, sticky="w", pady=(3, 0))
        # Grid row indices keep the immediate option, its help, save and
        # advanced controls in that order inside the runtime preferences card.
        runtime.ttk.Checkbutton(
            runtime_card,
            text=gettext("Experimental Canvas gallery renderer"),
            variable=self.experimental_canvas_gallery_renderer_var,
            command=self.save_canvas_renderer_preference,
        ).grid(row=10, column=1, columnspan=2, sticky="w", pady=(8, 0))
        runtime.ttk.Label(
            runtime_card,
            text=(
                gettext(
                    "Use experimental virtualized Canvas rendering for photo "
                    "galleries. Requires application restart."
                )
            ),
            style="Muted.TLabel",
            wraplength=WATCHER_RUNTIME_HELP_WRAP_PIXELS,
        ).grid(row=11, column=1, columnspan=2, sticky="w", pady=(2, 0))
        Button(
            runtime_card,
            text=gettext("✓  Save settings"),
            command=self.save_config,
            style="Primary.TButton",
        ).grid(row=12, column=1, sticky="w", pady=(10, 0))

        advanced_toggle = runtime.ttk.Checkbutton(
            runtime_card,
            text=gettext("Advanced import and archive settings"),
            variable=self.settings_advanced_visible_var,
            command=self.toggle_settings_advanced,
        )
        advanced_toggle.grid(
            row=13, column=0, columnspan=3, sticky="w", pady=(8, 0),
        )
        interface = GlassCard(parent, padding=(14, 10))
        # Row two is the independent interface card above advanced settings.
        interface.grid(row=2, column=0, sticky="ew", padx=6, pady=4)
        runtime.ttk.Label(
            interface, text=gettext("Interface"), style="Eyebrow.TLabel",
        ).pack(anchor="w", pady=(0, 7))
        self._gui_backend_settings = TkBackendSettings(
            interface, watcher_state.CONFIG_DIR, lambda: self.close(restart=True),
            WINDOW_SUMMARY_WRAP, True,
        )
        self._gui_backend_settings.pack(fill="x")
        advanced = GlassCard(parent, padding=(14, 10))
        self.settings_advanced_frame = advanced
        advanced.columnconfigure(1, weight=1)
        rows = [
            (gettext("Remote inventory reconnect seconds"), self.inventory_refresh_var),
            (gettext("ZIP max entries"), self.archive_max_entries_var),
            (gettext("ZIP max total uncompressed GiB"), self.archive_max_total_gib_var),
            (gettext("ZIP max single file GiB"), self.archive_max_file_gib_var),
            (gettext("ZIP max compression ratio"), self.archive_max_ratio_var),
            (gettext("Staging cleanup age hours"), self.staging_cleanup_hours_var),
        ]
        for row, (label, variable) in enumerate(rows):
            runtime.ttk.Label(advanced, text=label).grid(row=row, column=0, sticky="w", padx=(0, 14), pady=4)
            runtime.ttk.Entry(advanced, textvariable=variable, width=18).grid(row=row, column=1, sticky="w", pady=4)
        workers_row = len(rows)
        runtime.ttk.Label(advanced, text=gettext("Local thumbnail processes")).grid(row=workers_row, column=0, sticky="w", padx=(0, 14), pady=4)
        runtime.ttk.Combobox(advanced, textvariable=self.manual_thumbnail_workers_var, values=worker_choice_values(watcher_state.MAX_THUMBNAIL_WORKERS), width=10, state="readonly").grid(row=workers_row, column=1, sticky="w", pady=4)
        runtime.ttk.Label(advanced, text=gettext("Upload threads")).grid(row=workers_row + 1, column=0, sticky="w", padx=(0, 14), pady=4)
        runtime.ttk.Combobox(advanced, textvariable=self.manual_upload_workers_var, values=worker_choice_values(watcher_state.MAX_UPLOAD_WORKERS), width=10, state="readonly").grid(row=workers_row + 1, column=1, sticky="w", pady=4)
        Button(
            advanced,
            text=gettext("✓  Save settings"),
            command=self.save_config,
            style="Primary.TButton",
        ).grid(row=workers_row + 2, column=0, columnspan=2, sticky="w", pady=(10, 0))
        self.toggle_settings_advanced()

    def build_ai_tab(self, parent: "tk.Misc") -> None:
        """
        Build controls for the optional AI metadata worker.

        The tab intentionally keeps the normal operator view small. The common
        workflow is enable, choose the local backend, install dependencies if
        needed, and start the worker. Low-level tuning fields stay available in
        a collapsible advanced section, but they do not dominate the UI.

        Args:
            parent (tk.Misc): Tk container receiving the AI worker controls.

        Returns:
            None: Build controls for the optional AI metadata worker.
        """
        parent.columnconfigure(0, weight=1)
        quick = GlassCard(parent, padding=(14, 10))
        quick.grid(row=0, column=0, sticky="ew", padx=6, pady=4)
        quick.columnconfigure(1, weight=1)
        runtime.ttk.Label(quick, text=gettext("AI METADATA"), style="Eyebrow.TLabel").grid(
            row=0, column=0, columnspan=3, sticky="w"
        )
        runtime.ttk.Label(
            quick,
            text=gettext("Optional, server-assigned image analysis."),
            style="Section.TLabel",
        ).grid(row=1, column=0, columnspan=3, sticky="w", pady=(3, 2))
        runtime.ttk.Label(
            quick,
            text=gettext("The gallery assigns one job at a time; this PC returns internal search metadata."),
            style="Muted.TLabel",
            wraplength=780,
        ).grid(row=2, column=0, columnspan=3, sticky="w")
        runtime.ttk.Checkbutton(
            quick,
            text=gettext("Enable AI metadata worker on this PC"),
            variable=self.ai_worker_enabled_var,
        ).grid(row=3, column=0, columnspan=3, sticky="w", pady=(11, 6))
        runtime.ttk.Label(quick, text=gettext("Backend")).grid(row=4, column=0, sticky="w", pady=5)
        runtime.ttk.OptionMenu(
            quick,
            self.ai_vision_backend_var,
            self.ai_vision_backend_var.get(),
            *ai_backend_labels().values(),
        ).grid(row=4, column=1, sticky="w", padx=8, pady=5)
        runtime.ttk.Label(quick, text=gettext("Model version")).grid(row=5, column=0, sticky="w", pady=5)
        runtime.ttk.Entry(quick, textvariable=self.ai_model_version_var, width=24).grid(
            row=5, column=1, sticky="w", padx=8, pady=5
        )
        runtime.ttk.Label(quick, textvariable=self.ai_status_var, style="Status.TLabel").grid(
            row=6, column=0, columnspan=2, sticky="w", pady=(8, 0)
        )
        runtime_row = runtime.ttk.Frame(quick, style="Card.TFrame")
        runtime_row.grid(row=7, column=0, columnspan=3, sticky="ew", pady=(10, 0))
        runtime_row.columnconfigure(0, weight=1)
        runtime.ttk.Label(
            runtime_row,
            textvariable=self.thumbnail_runtime_var,
            style="Muted.TLabel",
            wraplength=550,
        ).grid(row=0, column=0, sticky="w")
        self.semantic_ai_install_button = Button(
            runtime_row,
            text=gettext("Install local AI module"),
            command=self.repair_semantic_ai_dependencies,
        )
        self.semantic_ai_install_button.grid(row=0, column=1, sticky="e", padx=(8, 0))
        Button(
            runtime_row,
            text=gettext("Repair dependencies"),
            command=self.repair_dependencies,
        ).grid(row=0, column=2, sticky="e", padx=(6, 0))
        actions = runtime.ttk.Frame(quick, style="Card.TFrame")
        actions.grid(row=8, column=0, columnspan=3, sticky="ew", pady=(12, 0))
        Button(
            actions,
            text=gettext("▶  Start AI worker"),
            command=self.start_ai_worker,
            style="Primary.TButton",
        ).pack(side="left")
        icon_button(actions, "stop", self.stop_ai_worker, gettext("Stop AI metadata worker")).pack(
            side="left", padx=(7, 0)
        )

        advanced_toggle = runtime.ttk.Checkbutton(
            quick,
            text=gettext("Advanced AI settings"),
            variable=self.ai_advanced_visible_var,
            command=self.toggle_ai_advanced_settings,
        )
        advanced_toggle.grid(
            row=9, column=0, columnspan=3, sticky="w", pady=(8, 0),
        )

        advanced = GlassCard(parent, padding=(14, 10))
        self.ai_advanced_frame = advanced
        advanced.columnconfigure(1, weight=1)

        runtime.ttk.Label(advanced, text=gettext("Poll seconds when idle")).grid(row=0, column=0, sticky="w", padx=8, pady=5)
        runtime.ttk.Entry(advanced, textvariable=self.ai_worker_poll_var, width=12).grid(row=0, column=1, sticky="w", padx=8, pady=5)
        runtime.ttk.Label(advanced, text=gettext("Default 60. Larger values keep the server quieter."), foreground="#AABCCB").grid(row=0, column=2, sticky="w", padx=8, pady=5)

        runtime.ttk.Label(advanced, text=gettext("Lease seconds per job")).grid(row=1, column=0, sticky="w", padx=8, pady=5)
        runtime.ttk.Entry(advanced, textvariable=self.ai_worker_lease_var, width=12).grid(row=1, column=1, sticky="w", padx=8, pady=5)
        runtime.ttk.Label(advanced, text=gettext("Default 600. Heartbeats extend the lease while analysis runs."), foreground="#AABCCB").grid(row=1, column=2, sticky="w", padx=8, pady=5)

        runtime.ttk.Label(advanced, text=gettext("Model or analyzer name")).grid(row=2, column=0, sticky="w", padx=8, pady=5)
        runtime.ttk.Entry(advanced, textvariable=self.ai_model_name_var).grid(row=2, column=1, sticky="ew", padx=8, pady=5)

        runtime.ttk.Label(advanced, text=gettext("Transformers caption model")).grid(row=3, column=0, sticky="w", padx=8, pady=5)
        runtime.ttk.Entry(advanced, textvariable=self.ai_transformers_caption_model_var).grid(row=3, column=1, sticky="ew", padx=8, pady=5)
        runtime.ttk.Label(advanced, text=gettext("First use may download model files locally."), foreground="#AABCCB", wraplength=360).grid(row=3, column=2, sticky="w", padx=8, pady=5)

        runtime.ttk.Label(advanced, text=gettext("Transformers object detector")).grid(row=4, column=0, sticky="w", padx=8, pady=5)
        runtime.ttk.Entry(advanced, textvariable=self.ai_transformers_detector_model_var).grid(row=4, column=1, sticky="ew", padx=8, pady=5)
        runtime.ttk.Label(advanced, text=gettext("Zero-shot object labels such as person, bridge, guitar, house."), foreground="#AABCCB").grid(row=4, column=2, sticky="w", padx=8, pady=5)

        runtime.ttk.Label(advanced, text=gettext("Detector threshold")).grid(row=5, column=0, sticky="w", padx=8, pady=5)
        runtime.ttk.Entry(advanced, textvariable=self.ai_transformers_detection_threshold_var, width=12).grid(row=5, column=1, sticky="w", padx=8, pady=5)
        runtime.ttk.Label(advanced, text=gettext("Default 0.18. Lower sees more objects but may add noise."), foreground="#AABCCB").grid(row=5, column=2, sticky="w", padx=8, pady=5)

        runtime.ttk.Label(advanced, text=gettext("Object labels")).grid(row=6, column=0, sticky="nw", padx=8, pady=5)
        runtime.ttk.Entry(advanced, textvariable=self.ai_transformers_object_labels_var).grid(row=6, column=1, columnspan=2, sticky="ew", padx=8, pady=5)

        runtime.ttk.Label(advanced, text=gettext("Local Ollama URL")).grid(row=7, column=0, sticky="w", padx=8, pady=5)
        runtime.ttk.Entry(advanced, textvariable=self.ai_ollama_url_var).grid(row=7, column=1, sticky="ew", padx=8, pady=5)
        runtime.ttk.Label(advanced, text=gettext("Optional fallback when you intentionally run Ollama."), foreground="#AABCCB").grid(row=7, column=2, sticky="w", padx=8, pady=5)

        runtime.ttk.Label(advanced, text=gettext("Ollama vision model")).grid(row=8, column=0, sticky="w", padx=8, pady=5)
        runtime.ttk.Entry(advanced, textvariable=self.ai_ollama_model_var, width=32).grid(row=8, column=1, sticky="w", padx=8, pady=5)
        runtime.ttk.Label(advanced, text=gettext("Examples: llava:latest or llama3.2-vision:latest."), foreground="#AABCCB").grid(row=8, column=2, sticky="w", padx=8, pady=5)

        runtime.ttk.Label(advanced, text=gettext("Semantic prompt")).grid(row=9, column=0, sticky="nw", padx=8, pady=5)
        runtime.ttk.Entry(advanced, textvariable=self.ai_semantic_prompt_var).grid(row=9, column=1, columnspan=2, sticky="ew", padx=8, pady=5)

        runtime.ttk.Label(advanced, text=gettext("External analyzer command")).grid(row=10, column=0, sticky="w", padx=8, pady=5)
        runtime.ttk.Entry(advanced, textvariable=self.ai_external_command_var).grid(row=10, column=1, columnspan=2, sticky="ew", padx=8, pady=5)
        runtime.ttk.Label(
            advanced,
            text=gettext("Optional. Use {image_path}, {filename}, and {job_id}. External analyzer output is treated as untrusted JSON and validated before use. Leave blank unless you use your own local analyzer."),
            foreground="#AABCCB",
            wraplength=820,
        ).grid(row=11, column=1, columnspan=2, sticky="w", padx=8, pady=(0, 6))

        self.toggle_ai_advanced_settings()

    def toggle_ai_advanced_settings(self) -> None:
        """
        Show or hide low-level AI worker tuning controls.

        Returns:
            None: Keeps advanced AI fields collapsed until requested.
        """
        if not self.ai_advanced_frame:
            return
        if self.ai_advanced_visible_var.get():
            self.ai_advanced_frame.grid(row=2, column=0, sticky="ew", padx=6, pady=4)
        else:
            self.ai_advanced_frame.grid_remove()

    def refresh_thumbnail_controls(self) -> None:
        """
        Refresh local thumbnail availability in the manual upload tab.

        Returns:
            None: Shows the localized runtime status and available thumbnail mode.
        """
        self.thumbnail_runtime_var.set(runtime.thumbnail_runtime_status(for_display=True))
        if runtime.local_thumbnail_supported():
            self.thumbnail_check.state(["!disabled"])
        else:
            self.thumbnail_check.state(["disabled"])
            self.manual_local_thumbnails_var.set(False)

    def repair_dependencies(self) -> None:
        """
        Install WinApp dependencies into the current Python interpreter.

        Returns:
            None: Reports installation or bundled dependency availability.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if getattr(runtime.sys, "frozen", False):
            ok, output = runtime.install_dependencies_for_current_runtime()
            show = runtime.messagebox.showinfo if ok else runtime.messagebox.showerror
            show(gettext("Bundled dependencies"), gettext(output))
            return
        self.write_log("Installing or repairing Python dependencies for the current runtime...")
        ok, output = runtime.install_dependencies_for_current_runtime()
        for line in output.splitlines()[-12:]:
            self.write_log(line)
        if ok:
            if not self.tray_icon:
                self.start_tray_icon()
            runtime.messagebox.showinfo(gettext("Dependency repair"), gettext("Required dependencies are available for this Python runtime."))
        else:
            runtime.messagebox.showerror(gettext("Dependency repair failed"), output[-1200:] if output else gettext("pip failed without output"))
        self.refresh_thumbnail_controls()

    def watcher_reset_blocked(self) -> bool:
        """Report a Watcher-only admission fence from confirmed or invalid state.

        Returns:
            bool: True until the pending reset finishes and its result verifies.
        """
        profile = getattr(self, '_watcher_profile', None)
        return (
            getattr(self, '_watcher_reset_running', False) is True
            or getattr(self, '_watcher_profile_invalid', False) is True
            or isinstance(profile, WatcherProfile) and profile.pending
        )

    def _save_owned_config(self, config: WatcherConfig, *,
                           first_setup: bool = False,
                           completed_work: Optional[CoordinatedWork] = None) -> WatcherConfig:
        """Save one host configuration under exclusive mode admission.

        Args:
            config (WatcherConfig): Proposed complete settings with owner token.
            first_setup (bool): True only for an explicit first settings save.
            completed_work (Optional[CoordinatedWork]): Exact server-confirmed
                revoke lifetime to exchange atomically for this config save.

        Returns:
            WatcherConfig: The accepted host object, retaining no-op identity.

        Raises:
            ModeCoordinatorError: Active work or uncertain mode blocks saving.
            OSError: The original config owner cannot persist the settings.
        """
        if not first_setup and config.to_dict() == self.config.to_dict():
            return self.config

        def save() -> watcher_state._ConfigModePort:
            """Write only through the current ConfigStore and return its port.

            Returns:
                _ConfigModePort: Mode port bound to the saved config object.
            """
            self.config_store.save(config)
            return self.config_store.mode_port(config)

        options = {'completed_work': completed_work} if completed_work is not None else {}
        self.mode_owner.save(save, first_setup=first_setup, **options)
        return config

    def capture_flight_admission(self) -> Tuple[GuiModeOwner, ModeConfigPort, str, str]:
        """Capture the exact saved legacy connection for a flight worker.

        Returns:
            Tuple[GuiModeOwner, ModeConfigPort, str, str]: GUI owner, port
                bound to sealed settings, canonical endpoint and private API
                key. The caller passes these directly to the worker only.

        Raises:
            ValueError: The saved settings cannot be captured safely.
            OSError: The ConfigStore path cannot be verified.
        """
        captured = watcher_state.capture_worker_config(self.config)
        port = self.config_store.mode_port(captured)
        return (self.mode_owner, port, watcher_state.normalize_upload_url(captured.gallery_url),
                captured.api_key)

    def watched_report_target(self) -> WatchedReportTarget | None:
        """Read the exact running Watcher's target without changing its state.

        Returns:
            WatchedReportTarget | None: Capture-owned authority for an
                independent archive upload, or None before active observation,
                during drain/review, or after upload scope becomes unavailable.
        """
        worker = self.worker
        if (worker is None or not worker.is_alive() or not worker.initial_captured
                or self._watch_start_pending or worker.drain_only
                or worker.drain_event.is_set() or worker.stop_event.is_set()
                or worker.update_pause_event.is_set() or worker._recovery_review_only
                or worker._force_finish_requested.is_set()):
            return None
        target = worker.linked_target
        if target is not None:
            if not target.session.can_upload():
                return None
            return WatchedReportTarget(
                target.title or f"{target.issuer} #{target.gallery_id}",
                linked_target=target,
            )
        url = watcher_state.normalize_upload_url(worker.config.gallery_url)
        key = worker.config.api_key.strip()
        if not (url and key and worker._gui_admission is not None):
            return None
        verified = self._verified_legacy_gallery
        if verified is not None and verified[1] == connection_binding(url, key):
            folder, title = verified[2], verified[3]
        else:
            folder, title = None, ""
        return WatchedReportTarget(
            title or folder or url, upload_url=url, api_key=key,
            gallery_folder=folder, gui_admission=worker._gui_admission,
        )

    def connection_change_allowed(self, config: WatcherConfig) -> bool:
        """Preserve captured connection ownership while accepted work is pending.

        Args:
            config (WatcherConfig): Proposed saved settings from current UI fields.

        Returns:
            bool: True for the same connection or an idle resolved connection.
        """
        if (
            watcher_state.normalize_upload_url(config.gallery_url)
            == watcher_state.normalize_upload_url(self.config.gallery_url)
            and config.api_key == self.config.api_key
        ):
            return True
        try:
            unresolved = bool(self.flights.pending())
            proposed_binding = connection_binding(
                watcher_state.normalize_upload_url(config.gallery_url), config.api_key,
            )
            with watcher_state.accepted_work_owner(watcher_state.CONFIG_DIR):
                # Every accepted job and binding must be reloaded strictly
                # after ownership. An orphan or damaged row blocks edits even
                # when its old binding is not the currently displayed key.
                bindings = self.flights.import_bindings.entries()
                if not self.job_store.admitted_imports_match_target(
                    bindings, proposed_binding,
                ):
                    unresolved = True
        except (OSError, ValueError, RuntimeError):
            unresolved = True
        if self.background_work_active() or unresolved or self.flights.is_alive():
            runtime.messagebox.showwarning(
                gettext("Connection"),
                gettext(
                    "Finish or explicitly resolve current work before replacing "
                    "its gallery connection."
                ),
            )
            return False
        return True

    def save_interface_language(self, _event: "tk.Event") -> None:
        """Remember a language choice for the next WinApp session.

        Args:
            _event (tk.Event): Selection event from the Settings language field.

        Returns:
            None: Persists the selection and offers a safe restart, or restores
                the last saved choice after a write failure.
        """
        selection = self.interface_language_var.get()
        previous = self._saved_ui_language
        language = next(
            (code for code, name in LANGUAGE_NAMES.items() if name == selection),
            previous,
        )
        if language == previous:
            return
        try:
            save_ui_language(watcher_state.CONFIG_DIR, language)
        except OSError:
            self.interface_language_var.set(LANGUAGE_NAMES[previous])
            runtime.messagebox.showerror(
                gettext("Settings"),
                gettext("Could not save the interface language."),
                parent=self.root,
            )
            return
        self._saved_ui_language = language
        self.refresh_interface_restart()
        if language == current_language():
            return
        if runtime.messagebox.askyesno(
            gettext("Restart WinApp"),
            gettext("The language was saved. Restart WinApp now to apply it?"),
            parent=self.root,
            yes_label=gettext("Restart now"),
            no_label=gettext("Later"),
        ):
            self.close(restart=True)

    def refresh_interface_restart(self) -> None:
        """Expose the retry action while the saved choice differs from the UI.

        Returns:
            None: Shows or hides the pending restart controls in Settings.
        """
        if self._saved_ui_language != current_language():
            self.interface_language_pending.grid()
        else:
            self.interface_language_pending.grid_remove()

    def save_gallery_thumbnail_batch_size(self, _event: "tk.Event") -> None:
        """Apply a persisted thumbnail request limit to upcoming viewer work.

        Args:
            _event (tk.Event): Settings combobox selection event.
        Returns:
            None: Publishes the new value after persistence or restores the
                last saved choice and shows a warning if saving failed.
        """
        try:
            size = int(self.thumbnail_batch_size_var.get())
            if size == self._thumbnail_batch_size:
                return
            save_thumbnail_batch_size(watcher_state.CONFIG_DIR, size)
        except (OSError, ValueError):
            self.thumbnail_batch_size_var.set(str(self._thumbnail_batch_size))
            runtime.messagebox.showwarning(
                gettext("Settings"), gettext("Could not save the thumbnail request size."),
                parent=self.root,
            )
            return
        self._thumbnail_batch_size = size

    def save_startup_preference(self) -> None:
        """Save the startup switch without admitting unrelated unsaved fields.

        Returns:
            None: Preserves all other saved settings; a blocked/failed save
                restores the prior switch and a registry failure is displayed.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            self.start_with_windows_var.set(self.config.start_with_windows)
            return
        if self.api_key_revoke_running:
            self.start_with_windows_var.set(self.config.start_with_windows)
            return
        config = watcher_state.replace(
            self.config, start_with_windows=self.start_with_windows_var.get(),
        )
        try:
            config = self._save_owned_config(config)
        except (OSError, watcher_state.ModeCoordinatorError) as exc:
            self.start_with_windows_var.set(self.config.start_with_windows)
            runtime.messagebox.showerror(gettext("Configuration error"), str(exc), parent=self.root)
            return
        self.config = config
        self.apply_startup_registration()

    def save_canvas_renderer_preference(self) -> None:
        """Persist the renderer choice immediately without changing this process.

        Returns:
            None: Saves only this preference, rolls the switch back on refusal
                or failure, and requests a restart after a successful change.
        """
        previous = self.config.experimental_canvas_gallery_renderer
        requested = self.experimental_canvas_gallery_renderer_var.get() is True
        if requested == previous:
            return
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            self.experimental_canvas_gallery_renderer_var.set(previous)
            runtime.messagebox.showwarning(
                gettext("Configuration"),
                gettext("Finish the active update before changing this setting."),
                parent=self.root,
            )
            return
        if self.api_key_revoke_running:
            self.experimental_canvas_gallery_renderer_var.set(previous)
            runtime.messagebox.showwarning(
                gettext("Configuration"),
                gettext("Wait for API-key revocation to finish before changing this setting."),
                parent=self.root,
            )
            return
        try:
            saved = self._save_owned_config(watcher_state.replace(
                self.config,
                experimental_canvas_gallery_renderer=requested,
            ))
        except Exception as exc:  # noqa: BLE001
            self.experimental_canvas_gallery_renderer_var.set(previous)
            runtime.messagebox.showerror(
                gettext("Configuration error"), str(exc), parent=self.root,
            )
            return
        self.config = saved
        self.write_log(
            "Experimental Canvas gallery renderer setting saved; restart required.",
            "success",
        )
        runtime.messagebox.showinfo(
            gettext("Restart required"),
            gettext(
                "The setting was saved. Restart the application for the "
                "experimental Canvas gallery renderer to take effect."
            ),
            parent=self.root,
        )

    def apply_startup_registration(self) -> None:
        """Apply the saved preference and expose registry errors for retry.

        Returns:
            None: Keeps saved preferences after a registry failure and reports
                the failure; saving again retries the same desired state.
        """
        try:
            synchronize_startup(self.config.start_with_windows)
        except OSError as exc:
            self.write_log(
                "Windows startup registration failed: " + str(exc), "warning",
            )
            runtime.messagebox.showwarning(
                gettext("Windows startup"),
                gettext(
                    "Settings were saved, but Windows startup could not be "
                    "updated. Save settings again to retry.\n\n"
                ) + str(exc),
                parent=self.root,
            )

    def save_config(self) -> bool:
        """
        Persist current settings to the local config file.

        Returns:
            bool: True when the configuration was saved.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return False
        if self.api_key_revoke_running:
            runtime.messagebox.showwarning(gettext("Configuration"), gettext("Wait for API-key revocation to finish before changing connection credentials."))
            return False
        try:
            config = self.current_config()
            if not self.connection_change_allowed(config):
                return False
            config = self._save_owned_config(config, first_setup=True)
            self.config = config
            self.write_log("Configuration saved.", "success")
            self.apply_startup_registration()
            self.refresh_connection_controls()
            return True
        except Exception as exc:  # noqa: BLE001
            runtime.messagebox.showerror(gettext("Configuration error"), gettext(str(exc)))
            return False

    def save_and_test_connection(self) -> None:
        """Save the Settings connection fields and test authentication.

        Returns:
            None: Saves valid values before starting the existing test worker.
        """
        if self.save_config():
            self.test_connection()

    def simbrief_available(self) -> bool:
        """Check whether the current game supports the shared SimBrief controls.

        Returns:
            bool: True when the existing game observer reports a flight simulator.
        """
        return self.simulator_presence.flight_detected

    def toggle_settings_advanced(self) -> None:
        """Show or hide low-level import/archive tuning settings.

        Returns:
            None: Keeps detailed settings collapsed until requested.
        """
        if self.settings_advanced_frame is None:
            return
        if self.settings_advanced_visible_var.get():
            self.settings_advanced_frame.grid(row=3, column=0, sticky="ew", padx=6, pady=4)
        else:
            self.settings_advanced_frame.grid_remove()

    def copy_api_key(self) -> None:
        """Copy the configured API key to the clipboard on explicit action.

        Returns:
            None: Copies the key or shows a warning when it is missing.
        """
        value = self.api_key_var.get().strip()
        if not value:
            runtime.messagebox.showwarning(gettext("API key"), gettext("No API key is configured."))
            return
        self.root.clipboard_clear()
        self.root.clipboard_append(value)
        self.write_log("API key copied to clipboard by explicit user action.", "system")

    def test_connection(self) -> None:
        """Refresh connection status and current gallery metadata.

        Returns:
            None: Starts an authenticated inventory check without uploading
                files, or reports why the request cannot start.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.linking_manager.linked_upload_mode_selected():
            runtime.messagebox.showinfo(
                gettext('Connection'),
                gettext(
                    "This tests the saved API key. Select the API-key connection "
                    "explicitly before running this test."
                ),
            )
            return
        if self.api_key_revoke_running:
            runtime.messagebox.showwarning(gettext("Connection"), gettext("Wait for API-key revocation to finish before testing the connection."))
            return
        if self.connection_test_running:
            return
        upload_url = watcher_state.normalize_upload_url(self.gallery_url_var.get())
        api_key = self.api_key_var.get().strip()
        if not upload_url:
            runtime.messagebox.showerror(gettext("Connection"), gettext("Enter a valid HTTP or HTTPS Gallery URL/endpoint."))
            return
        if not api_key:
            runtime.messagebox.showerror(gettext("Connection"), gettext("Enter an API key before testing the connection."))
            return
        try:
            current = self.current_config()
            if current.to_dict() != self.config.to_dict():
                raise ValueError('Save the current settings before testing the connection.')
            captured = watcher_state.capture_worker_config(current)
            config_port = self.config_store.mode_port(captured)
        except (ValueError, OSError) as exc:
            runtime.messagebox.showerror(gettext('Connection'), str(exc))
            return
        self.connection_test_running = True
        type(self)._publish_connection_text(self, "status", gettext_noop("Testing"))
        type(self)._publish_connection_text(
            self, "detail", gettext_noop(
                "Checking endpoint and API-key authorization without uploading media..."
            ),
        )
        self.refresh_revoke_button_state()

        def tester() -> None:
            """Read current gallery metadata without blocking Tkinter.

            Returns:
                None: Enqueues connection metadata or a connection error.
            """
            try:
                with self.mode_owner.acquire('legacy', config_port) as work:
                    payload, resolved_url = post_json_resolved(
                        upload_url, api_key,
                        {"action": "inventory", "files": [], "deep_check": False},
                        timeout_seconds=20.0, before_request=work.checkpoint,
                    )
                label = str(payload.get("gallery_folder", "") or payload.get("gallery", "") or "gallery").strip()
                self.events.put({"kind": "connection_ready", "payload": {
                    "gallery_label": label,
                    "gallery_title": str(payload.get("gallery_title", "") or "").strip(),
                    "normalized_url": resolved_url,
                    "explicit_test": True,
                    "config_generation": captured._snapshot_token,
                }})
            except Exception as exc:  # noqa: BLE001
                self.events.put({"kind": "connection_error", "payload": {
                    "message": str(exc), "normalized_url": upload_url,
                    "explicit_test": True,
                    "config_generation": captured._snapshot_token,
                }})

        watcher_state.threading.Thread(target=tester, name="PHPGalleryConnectionTest", daemon=True).start()
