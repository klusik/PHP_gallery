# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_localization.py
# Module Type: WinApp Localization Regression
# Purpose: Exercise language selection, restart intent and diagnostic isolation.
# Responsibilities:
#   - Keep language fixtures in disposable profiles with no gallery connection.
#   - Verify the same operator actions and stable diagnostics in every locale.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Exercise the app locale independently of production configuration."""

import ast
from datetime import datetime
import gc
import json
import os
from pathlib import Path
from string import Formatter
import tempfile
import tkinter as tk
from tkinter import ttk
from types import SimpleNamespace
import unittest
from unittest import mock

import polib

from test_application_shell import MAIN, WINAPP_DIR, descendants
from test_ui_shell import SHELL_TEST_DEADLINE_MS
from uploader.watcher import runtime, state as watcher_state
from uploader.watcher.ui import event_delivery
from uploader.watcher.ui.settings import preferences as settings_preferences
from uploader.config import (
    THUMBNAIL_BATCH_CHOICES, THUMBNAIL_BATCH_DEFAULT_SIZE, THUMBNAIL_BATCH_FILE,
    load_thumbnail_batch_size, load_ui_language,
)
from uploader.flight_jobs import FlightPreferences
from uploader.flight_report_translations import tr
from uploader.flight_ui import FlightUI
from uploader.localization import (
    CATALOG_DIRECTORY, DEFAULT_LANGUAGE, LANGUAGE_NAMES, configure_language,
    current_language, gettext, load_catalog,
)
from uploader.models import ActivityEvent
from uploader.photos_workspace import PhotosWorkspace
from uploader.support_report import build_support_report
from uploader.ui_shell import active_panel, initialize_shell
from uploader.ui_theme import SmoothIconButton


class WatcherRestartFixture(SimpleNamespace):
    """Model the real Watcher reset fence for restart lifecycle tests."""

    def watcher_reset_blocked(self) -> bool:
        """Use the production admission predicate for a constructor-free host.

        Returns:
            bool: Whether the fixture host has a pending or invalid reset.
        """
        return MAIN.WatcherApp.watcher_reset_blocked(self)


class CatalogTests(unittest.TestCase):
    """Validate all application gettext calls and translations from source."""

    def test_catalogs_cover_literals_and_keep_format_fields(self) -> None:
        """Verify exact key coverage, parsing and formatting in each GNU catalog.

        Returns:
            None: Reports missing, obsolete or untranslated entries by locale.
        """
        sources = [
            WINAPP_DIR / "gallery_watch_upload.pyw",
            *(WINAPP_DIR / "uploader").rglob("*.py"),
        ]
        messages = set()
        for source in sources:
            tree = ast.parse(source.read_text(encoding="utf-8-sig"), filename=str(source))
            for node in ast.walk(tree):
                if (
                    isinstance(node, ast.Call)
                    and isinstance(node.func, ast.Name)
                    and node.func.id in {"gettext", "gettext_noop"}
                    and node.args and isinstance(node.args[0], ast.Constant)
                    and isinstance(node.args[0].value, str)
                ):
                    messages.add(node.args[0].value)

        parser = Formatter()
        self.assertTrue(messages)
        self.assertEqual("Settings", load_catalog(DEFAULT_LANGUAGE).gettext("Settings"))
        for locale in LANGUAGE_NAMES:
            if locale == DEFAULT_LANGUAGE:
                continue
            with self.subTest(locale=locale):
                path = CATALOG_DIRECTORY / locale / "LC_MESSAGES" / "winapp.po"
                catalog = polib.pofile(str(path), check_for_duplicates=True)
                compiled = load_catalog(locale)
                self.assertEqual(locale, catalog.metadata["Language"])
                self.assertSetEqual(messages, {entry.msgid for entry in catalog if not entry.obsolete})
                for entry in catalog:
                    self.assertTrue(entry.translated() and entry.msgstr.strip(), entry.msgid)
                    self.assertEqual(
                        sorted((field, spec, conversion) for _, field, spec, conversion
                               in parser.parse(entry.msgid) if field is not None),
                        sorted((field, spec, conversion) for _, field, spec, conversion
                               in parser.parse(entry.msgstr) if field is not None),
                        entry.msgid,
                    )
                    self.assertEqual(entry.msgstr, compiled.gettext(entry.msgid))


class LocalizationTests(unittest.TestCase):
    """Protect the preference, safe restart and stable evidence boundaries."""

    def setUp(self) -> None:
        """Remember the locale to leave unrelated catalog consumers isolated.

        Returns:
            None: Registers restoration even after an assertion fails.
        """
        self.addCleanup(configure_language, current_language())

    def test_legacy_and_invalid_thumbnail_preferences_use_default(self) -> None:
        """Restore a usable request limit from old and damaged local profiles.

        Returns:
            None: Invalid bytes remain untouched and no unrelated file is read.
        """
        with tempfile.TemporaryDirectory() as directory:
            profile = Path(directory)
            preference = profile / THUMBNAIL_BATCH_FILE
            self.assertEqual(THUMBNAIL_BATCH_DEFAULT_SIZE, load_thumbnail_batch_size(profile))
            for invalid in ('{', '{"batch_size": true}', '{"batch_size": "16"}',
                            '{"batch_size": 33}'):
                with self.subTest(invalid=invalid):
                    preference.write_text(invalid, encoding="utf-8")
                    self.assertEqual(
                        THUMBNAIL_BATCH_DEFAULT_SIZE, load_thumbnail_batch_size(profile),
                    )
                    self.assertEqual(invalid, preference.read_text(encoding="utf-8"))

    def test_retries_a_pending_change_and_clears_it_on_reversion(self) -> None:
        """Keep the restart action after Later or refusal in any locale.

        Returns:
            None: Saves the choice independently and clears a reverted change.
        """
        for locale in LANGUAGE_NAMES:
            with self.subTest(locale=locale), tempfile.TemporaryDirectory() as tmp:
                profile = Path(tmp)
                legacy = profile / "flights" / "preferences.json"
                legacy.parent.mkdir()
                legacy.write_text(
                    json.dumps({"ui_language": locale, "languages": ["sv"]}),
                    encoding="utf-8",
                )
                website = profile / "config.json"
                website.write_text('{"language": "de"}', encoding="utf-8")
                saved_files = {path: path.read_bytes() for path in (website, legacy)}
                self.assertEqual(locale, configure_language(load_ui_language(profile)))
                change, replace = [code for code in LANGUAGE_NAMES if code != locale][:2]
                pending = mock.Mock()
                app = SimpleNamespace(
                    root=mock.Mock(), _saved_ui_language=locale, close=mock.Mock(),
                    interface_language_var=mock.Mock(),
                    interface_language_pending=pending,
                )
                app.refresh_interface_restart = lambda: MAIN.WatcherApp.refresh_interface_restart(app)
                with (
                    mock.patch.object(watcher_state, "CONFIG_DIR", profile),
                    mock.patch.object(runtime, "messagebox") as messages,
                ):
                    messages.askyesno.return_value = False
                    app.interface_language_var.get.return_value = LANGUAGE_NAMES[change]
                    MAIN.WatcherApp.save_interface_language(app, mock.Mock())
                    self.assertEqual(change, load_ui_language(profile))
                    self.assertEqual(locale, current_language())
                    pending.grid.assert_called_once_with()
                    app.close.assert_not_called()

                    messages.askyesno.return_value = True
                    app.interface_language_var.get.return_value = LANGUAGE_NAMES[replace]
                    MAIN.WatcherApp.save_interface_language(app, mock.Mock())
                    app.close.assert_called_once_with(restart=True)
                    self.assertEqual(replace, load_ui_language(profile))
                    self.assertEqual(locale, current_language())
                    self.assertEqual(2, pending.grid.call_count)

                    app.interface_language_var.get.return_value = LANGUAGE_NAMES[locale]
                    MAIN.WatcherApp.save_interface_language(app, mock.Mock())
                    pending.grid_remove.assert_called_once_with()
                    self.assertEqual(2, messages.askyesno.call_count)
                    self.assertEqual(locale, load_ui_language(profile))
                self.assertEqual(saved_files, {path: path.read_bytes() for path in saved_files})

    def test_language_restart_leaves_in_progress_update_untouched(self) -> None:
        """Refuse the restart while update work owns the process.

        Returns:
            None: Leaves the launcher eligible for another request after update.
        """
        for locale in LANGUAGE_NAMES:
            with self.subTest(locale=locale):
                self.assertEqual(locale, configure_language(locale))
                app = SimpleNamespace(
                    root=mock.Mock(), _save_ui_layout_now=mock.Mock(),
                    updates=SimpleNamespace(
                        busy=True, gate=SimpleNamespace(active=False),
                        request_close=mock.Mock(),
                    ),
                )
                with mock.patch.object(runtime, "messagebox") as messages:
                    MAIN.WatcherApp.close(app, restart=True)
                app.updates.request_close.assert_not_called()
                self.assertFalse(getattr(app, "_restart_requested", False))
                app.root.destroy.assert_not_called()
                messages.showwarning.assert_called_once_with(
                    gettext("Restart WinApp"),
                    gettext("Wait for the update operation to finish before restarting."),
                    parent=app.root,
                )

    def test_language_restart_defers_accepted_background_work(self) -> None:
        """Keep manual uploads, import review and flight work running on restart.

        Returns:
            None: Exercises each independent admission fence in every language.
        """
        for locale in LANGUAGE_NAMES:
            self.assertEqual(locale, configure_language(locale))
            for name in ("manual_worker", "preflight_worker", "ai_worker", "flight"):
                with self.subTest(locale=locale, operation=name):
                    worker = mock.Mock(is_alive=mock.Mock(return_value=True))
                    app = WatcherRestartFixture(
                        root=mock.Mock(), _save_ui_layout_now=mock.Mock(),
                        updates=None, _prune_force_finished_workers=mock.Mock(),
                        _force_finished_workers=set(), _force_finished_operations=set(),
                        api_key_revoke_running=False, exiting=False, worker=None,
                        manual_worker=None, preflight_worker=None, ai_worker=None,
                        flights=SimpleNamespace(
                            dispatch_worker=None, busy=mock.Mock(return_value=name == "flight"),
                            is_alive=mock.Mock(return_value=name == "flight"), stop=mock.Mock(),
                        ),
                    )
                    if name != "flight":
                        setattr(app, name, worker)
                    with mock.patch.object(runtime, "messagebox") as messages:
                        MAIN.WatcherApp.close(app, restart=True)
                    app.flights.stop.assert_not_called()
                    worker.stop.assert_not_called()
                    app.root.destroy.assert_not_called()
                    self.assertFalse(app.exiting)
                    messages.showwarning.assert_called_once_with(
                        gettext("Restart WinApp"),
                        gettext("Wait for the remaining work to finish before restarting."),
                        parent=app.root,
                    )

    def test_activity_and_connection_keep_diagnostic_evidence_stable(self) -> None:
        """Translate the view while preserving safe raw data for support.

        Returns:
            None: Checks an actual persisted event, log marker and status.
        """
        marker = "[watcher-recovery] stage=resume; reason=io_error"
        source = "Import preflight failed: private-token"
        captured_at = datetime.fromisoformat("2026-01-01T00:00:00+00:00")
        expected_report = None
        for locale in LANGUAGE_NAMES:
            with self.subTest(locale=locale):
                self.assertEqual(locale, configure_language(locale))
                app = SimpleNamespace(
                    api_key_var=mock.Mock(get=mock.Mock(return_value="private-token")),
                    activity_writer=mock.Mock(), activity_events=[],
                    _schedule_activity_drawer_refresh=mock.Mock(),
                    _drain_running=True, config=SimpleNamespace(activity_history_limit=50),
                    connection_status_var=mock.Mock(), connection_detail_var=mock.Mock(),
                )
                with mock.patch.object(event_delivery.logging, "info") as info:
                    MAIN.WatcherApp.write_log(app, marker, "warning")
                info.assert_called_once_with("UI: %s", marker)
                self.assertEqual(marker, app._watch_recovery_latest)
                self.assertEqual(marker, app._watch_recovery_last_failure)
                MAIN.WatcherApp.record_activity(
                    app, "error", "Import preflight failed: {detail}", "import",
                    fields={"detail": "private-token"},
                )
                event = ActivityEvent.from_dict(app.activity_events[0].to_dict())
                self.assertEqual(("error", "import"), (event.level, event.operation))
                self.assertEqual(source.replace("private-token", "[REDACTED]"), event.message)
                self.assertEqual(
                    gettext(event.message_id).format(detail="[REDACTED]"),
                    MAIN.WatcherApp.activity_message(event),
                )
                MAIN.WatcherApp._publish_connection_text(app, "status", "Connected")
                self.assertEqual({"status": "Connected"}, app._connection_diagnostic)
                app.connection_status_var.set.assert_called_once_with(gettext("Connected"))
                runtime_source = runtime.thumbnail_runtime_status()
                runtime_caption = runtime.thumbnail_runtime_status(for_display=True)
                self.assertIn("Client-side thumbnails", runtime_source)
                self.assertEqual(locale == DEFAULT_LANGUAGE, runtime_caption == runtime_source)

                status = "UTC correction verified; delta=+0 s (was +70 s); target=2026-01-01"
                utc_event = ActivityEvent(
                    level="info", message="[time] " + status, operation="simconnect",
                )
                self.assertEqual("[time] " + status, utc_event.to_dict()["message"])
                self.assertEqual(gettext("UTC time synchronized"), MAIN.WatcherApp.activity_message(utc_event))

                report = build_support_report(
                    "\n".join((marker, app._connection_diagnostic["status"], event.message, status)),
                    {}, {}, captured_at=captured_at, secrets=("private-token",),
                )
                if expected_report is None:
                    expected_report = report
                self.assertEqual(expected_report, report)
                self.assertIn(marker, report)
                self.assertIn(event.message, report)
                self.assertNotIn("private-token", report)


class NativeLocalizationTests(unittest.TestCase):
    """Click the real Settings and preference dialogs with a disposable profile."""

    def setUp(self) -> None:
        """Initialize the same Tk shell as WinApp without workers or server calls.

        Returns:
            None: Restores the root and locale after each test.
        """
        try:
            self.root = tk.Tk()
        except tk.TclError as exc:
            if os.environ.get("PHP_GALLERY_REQUIRE_LOCALIZATION_TK") == "1":
                self.fail(f"Tk display is required for locale acceptance: {exc}")
            self.skipTest(str(exc))
        self.addCleanup(gc.collect)
        self.addCleanup(self.root.destroy)
        self.addCleanup(configure_language, current_language())
        self.callback_errors = []
        self.root.report_callback_exception = lambda *_args: self.callback_errors.append(str(_args[1]))
        initialize_shell(self.root)

    def _buttons(self, owner: tk.Misc) -> dict[str, ttk.Button]:
        """Find actual Tk buttons by their visible text.

        Args:
            owner (tk.Misc): Window or card owning the tested buttons.

        Returns:
            dict[str, ttk.Button]: Visible text mapped to the corresponding widget.
        """
        return {
            str(widget.cget("text")): widget
            for widget in descendants(owner) if isinstance(widget, ttk.Button)
        }

    def _checks(self, owner: tk.Misc) -> dict[str, ttk.Checkbutton]:
        """Find the real boolean controls in a preference panel.

        Args:
            owner (tk.Misc): Window or card owning the tested toggles.

        Returns:
            dict[str, ttk.Checkbutton]: Captions mapped to invokable toggles.
        """
        return {
            str(widget.cget("text")): widget
            for widget in descendants(owner) if isinstance(widget, ttk.Checkbutton)
        }

    def _choose_later(self, decisions: list[str]) -> None:
        """Resolve the real embedded prompt while Tk is waiting for a response.

        Args:
            decisions (list[str]): Readback of the sole clicked prompt caption.

        Returns:
            None: Appends the clicked caption or closes a malformed prompt.
        """
        panel = active_panel(self.root)
        if panel is None:
            return
        buttons = self._buttons(panel)
        later = buttons.get(gettext("Later"))
        if later is None or gettext("Restart now") not in buttons:
            panel.destroy()
            return
        decisions.append(str(later.cget("text")))
        later.invoke()

    def _close_waiting_prompt(self) -> None:
        """Release a modal test promptly if a click fails to finish it.

        Returns:
            None: Closes the outstanding prompt, leaving its assertion to fail.
        """
        panel = active_panel(self.root)
        if panel is not None:
            panel.destroy()

    def test_settings_menu_choices_and_restart_action_in_every_locale(self) -> None:
        """Click Settings, each language, every worker option and runtime action.

        Returns:
            None: Checks route ownership, localized controls and profile readback.
        """
        for viewport in (MAIN.WATCHER_WINDOW_GEOMETRY, "%dx%d" % MAIN.WATCHER_WINDOW_MINIMUM):
            self.root.geometry(viewport)
            for locale in LANGUAGE_NAMES:
                with self.subTest(locale=locale, viewport=viewport), tempfile.TemporaryDirectory() as directory:
                    self._exercise_settings(locale, Path(directory))

    def _exercise_settings(self, locale: str, profile: Path) -> None:
        """Exercise a single real Settings page and repeat the deferred restart.

        Args:
            locale (str): Interface language active for this page.
            profile (Path): Empty disposable directory for preference writes.

        Returns:
            None: Clicks all display choices and checks the saved next-session code.
        """
        self.assertEqual(locale, configure_language(locale))
        app = object.__new__(MAIN.WatcherApp)
        app.root = self.root
        app.exiting = False
        app._saved_ui_language = locale
        app._thumbnail_batch_size = load_thumbnail_batch_size(profile)
        app.updates = SimpleNamespace(check=mock.Mock())
        app.select_tab = mock.Mock()
        callbacks = (
            "save_and_test_connection", "open_connection_dialog", "revoke_api_key",
            "manage_gallery_link", "repair_dependencies", "browse_simconnect_dll",
            "save_startup_preference", "save_canvas_renderer_preference", "save_config",
            "select_manual_files", "select_manual_folder", "select_manual_zip",
            "refresh_connection_controls", "close",
        )
        for name in callbacks:
            setattr(app, name, mock.Mock())
        for name in (
            "gallery_url", "api_key", "api_key_status", "connection_status", "connection_detail",
            "revoke_blocker", "thumbnail_runtime", "simconnect_dll_path", "inventory_refresh",
            "archive_max_entries", "archive_max_total_gib", "archive_max_file_gib",
            "archive_max_ratio", "staging_cleanup_hours",
        ):
            setattr(app, name + "_var", tk.StringVar(self.root))
        for name in (
            "close_to_tray", "start_with_windows", "experimental_canvas_gallery_renderer",
            "settings_advanced_visible", "api_key_visible",
        ):
            setattr(app, name + "_var", tk.BooleanVar(self.root))
        app.manual_upload_workers_var = tk.StringVar(self.root, value=gettext("Auto"))
        app.manual_thumbnail_workers_var = tk.StringVar(self.root, value=gettext("Auto"))
        app.api_key_revoke_running = False
        surface = MAIN.ScrollableFrame(self.root)
        surface.pack(fill="both", expand=True)
        app.build_menu()
        view_menu = self.root.nametowidget(app.menu_bar.entrycget(gettext("View"), "menu"))
        for label, route in (
            ("Photos", "photos"), ("Watcher", "watch"), ("Import", "import"),
            ("Activity", "activity"), ("AI", "ai"), ("Settings", "settings"),
        ):
            view_menu.invoke(label if route == "ai" else gettext(label))
            app.select_tab.assert_called_with(route)
        try:
            with (
                mock.patch.object(watcher_state, "CONFIG_DIR", profile),
                mock.patch.object(settings_preferences, "startup_supported", return_value=True),
                mock.patch.object(settings_preferences, "icon_button", wraps=settings_preferences.icon_button) as icon,
            ):
                app.build_settings_tab(surface.content)
                self.root.update()
                self._click_settings_controls(app, surface.content, icon)
                batch = next(
                    widget for widget in descendants(surface)
                    if isinstance(widget, ttk.Combobox)
                    and tuple(widget.cget("values")) == tuple(map(str, THUMBNAIL_BATCH_CHOICES))
                )
                self.assertEqual(str(THUMBNAIL_BATCH_DEFAULT_SIZE), batch.get())
                for size in THUMBNAIL_BATCH_CHOICES:
                    batch.set(str(size))
                    batch.event_generate("<<ComboboxSelected>>")
                    self.assertEqual(size, app._thumbnail_batch_size)
                    self.assertEqual(size, load_thumbnail_batch_size(profile))
                pending = app.interface_language_pending
                language = next(
                    widget for widget in descendants(surface)
                    if isinstance(widget, ttk.Combobox)
                    and tuple(widget.cget("values")) == tuple(LANGUAGE_NAMES.values())
                )
                self.assertEqual(LANGUAGE_NAMES[locale], language.get())
                self.assertFalse(pending.winfo_ismapped())
                decisions = []
                for code in (*[code for code in LANGUAGE_NAMES if code != locale], locale):
                    deadline = self.root.after(SHELL_TEST_DEADLINE_MS, self._close_waiting_prompt)
                    if code != locale:
                        self.root.after_idle(self._choose_later, decisions)
                    language.set(LANGUAGE_NAMES[code])
                    language.event_generate("<<ComboboxSelected>>")
                    self.root.update()
                    self.root.after_cancel(deadline)
                    self.assertEqual(code, load_ui_language(profile))
                    self.assertEqual(locale, current_language())
                    self.assertEqual(code != locale, bool(pending.grid_info()))
                    if code != locale:
                        self._buttons(pending)[gettext("Restart WinApp")].invoke()
                        self.assertTrue(pending.grid_info())
                        app.close.assert_called_with(restart=True)
                self.assertEqual([gettext("Later")] * (len(LANGUAGE_NAMES) - 1), decisions)
                self.assertEqual(len(decisions), app.close.call_count)
                self.assertEqual([], self.callback_errors)
        finally:
            surface.destroy()
            self.root.configure(menu="")
            app.menu_bar.destroy()

    def _click_settings_controls(
        self, app: "MAIN.WatcherApp", owner: tk.Misc, icon: mock.Mock,
    ) -> None:
        """Exercise all direct Settings actions, including icon button dispatch.

        Args:
            app (MAIN.WatcherApp): Real owner with external transports replaced.
            owner (tk.Misc): Settings page containing connection and runtime controls.
            icon (mock.Mock): Spy around the real icon builder and localized help.

        Returns:
            None: Checks the controls, dispatch and stable worker configuration.
        """
        buttons = self._buttons(owner)
        for label, callback in (
            ("✓  Save & Test", app.save_and_test_connection),
            ("Revoke key…", app.revoke_api_key),
            ("Manage link…", app.manage_gallery_link),
            ("Repair dependencies", app.repair_dependencies),
            ("Choose", app.browse_simconnect_dll),
            ("✓  Save settings", app.save_config),
        ):
            buttons[gettext(label)].invoke()
            callback.assert_called_once_with()
        icon_help = [call.args[3] for call in icon.call_args_list]
        for index, label in enumerate((
            "Reveal or mask API key", "Paste API key", "Open protected credential editor",
        )):
            self.assertEqual(gettext(label), icon_help[index])
        self.root.clipboard_clear()
        self.root.clipboard_append("fixture-key")
        for button in descendants(owner):
            if isinstance(button, SmoothIconButton):
                button.invoke()
        self.assertEqual("fixture-key", app.api_key_var.get())
        self.assertEqual("", app.api_key_entry.cget("show"))
        app.open_connection_dialog.assert_called_once_with()
        checks = self._checks(owner)
        for label, variable in (
            ("Keep working in the system tray when the window closes", app.close_to_tray_var),
            ("Start with Windows (minimized to tray)", app.start_with_windows_var),
            ("Experimental Canvas gallery renderer", app.experimental_canvas_gallery_renderer_var),
            ("Advanced import and archive settings", app.settings_advanced_visible_var),
        ):
            checks[gettext(label)].invoke()
            self.assertTrue(variable.get())
        app.save_startup_preference.assert_called_once_with()
        app.save_canvas_renderer_preference.assert_called_once_with()
        advanced = app.settings_advanced_frame
        self.assertTrue(advanced.grid_info())
        for picker in (widget for widget in descendants(advanced) if isinstance(widget, ttk.Combobox)):
            choices = tuple(picker.cget("values"))
            self.assertEqual(gettext("Auto"), choices[0])
            for index, label in enumerate(choices):
                picker.current(index)
                self.assertEqual(label, picker.get())
                self.assertEqual(0 if index == 0 else int(label), MAIN.parse_worker_choice(label))
        self._buttons(advanced)[gettext("✓  Save settings")].invoke()
        self.assertEqual(2, app.save_config.call_count)
        checks[gettext("Advanced import and archive settings")].invoke()
        self.assertFalse(advanced.grid_info())

    def test_feature_preferences_in_every_locale(self) -> None:
        """Click the real Watcher, AI and flight preference options in each locale.

        Returns:
            None: Checks translated options and independent PDF language storage.
        """
        width, height = MAIN.WATCHER_WINDOW_MINIMUM
        self.root.geometry(f"{width}x{height}")
        for locale in LANGUAGE_NAMES:
            with self.subTest(locale=locale), tempfile.TemporaryDirectory() as directory:
                self.assertEqual(locale, configure_language(locale))
                self._exercise_watcher_preferences()
                self._exercise_ai_preferences()
                self._exercise_flight_preferences(locale, Path(directory))

    def _exercise_watcher_preferences(self) -> None:
        """Click folder actions, all toggles and both Watcher sheet outcomes.

        Returns:
            None: Exercises cancellation and sends the next save to its owner.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.root = self.root
        app.worker = None
        for name in ("watched_folder", "watch_ignored", "watch_last_scan", "interval", "stable"):
            setattr(app, name + "_var", tk.StringVar(self.root))
        for name in ("watch_recursive", "attach_sim_camera_metadata", "delete_uploaded_files"):
            setattr(app, name + "_var", tk.BooleanVar(self.root))
        app.browse_folder = mock.Mock()
        app.open_watched_folder = mock.Mock()
        app.refresh_watch_delete_warning = mock.Mock()
        app.current_watcher_config = mock.Mock()
        with mock.patch.object(MAIN.WatcherApp, "_save_watcher_preferences") as save:
            app.open_watcher_settings()
            panel = active_panel(self.root)
            self.assertIsNotNone(panel)
            self.root.update()
            for button in descendants(panel):
                if isinstance(button, SmoothIconButton):
                    button.invoke()
            app.browse_folder.assert_called_once_with(parent=panel)
            app.open_watched_folder.assert_called_once_with()
            checks = self._checks(panel)
            for label in (
                "Include subfolders", "Attach simulator location",
                "Delete uploaded photos from this PC",
            ):
                checks[gettext(label)].invoke()
            self.assertTrue(app.watch_recursive_var.get())
            self.assertTrue(app.attach_sim_camera_metadata_var.get())
            self.assertTrue(app.delete_uploaded_files_var.get())
            self._buttons(panel)[gettext("Cancel")].invoke()
            self.assertIsNone(active_panel(self.root))
            self.assertFalse(app.watch_recursive_var.get())
            self.assertFalse(app.attach_sim_camera_metadata_var.get())
            self.assertFalse(app.delete_uploaded_files_var.get())
            save.assert_not_called()
            app.open_watcher_settings()
            panel = active_panel(self.root)
            self._buttons(panel)[gettext("Save watcher settings")].invoke()
            save.assert_called_once_with(app, app.current_watcher_config.return_value)
            self.assertIsNone(active_panel(self.root))

    def _exercise_ai_preferences(self) -> None:
        """Invoke every visible backend and reveal the advanced AI fields.

        Returns:
            None: Checks that translated menu labels map to the original codes.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.root = self.root
        app.ai_worker_enabled_var = tk.BooleanVar(self.root)
        app.ai_advanced_visible_var = tk.BooleanVar(self.root)
        for name in (
            "ai_vision_backend", "ai_model_version", "ai_status", "thumbnail_runtime",
            "ai_worker_poll", "ai_worker_lease", "ai_model_name", "ai_transformers_caption_model",
            "ai_transformers_detector_model", "ai_transformers_detection_threshold",
            "ai_transformers_object_labels", "ai_ollama_url", "ai_ollama_model",
            "ai_semantic_prompt", "ai_external_command",
        ):
            setattr(app, name + "_var", tk.StringVar(self.root))
        app.ai_vision_backend_var.set(gettext("Auto"))
        for name in (
            "repair_semantic_ai_dependencies", "repair_dependencies",
            "start_ai_worker", "stop_ai_worker",
        ):
            setattr(app, name, mock.Mock())
        owner = MAIN.ScrollableFrame(self.root)
        owner.pack(fill="both", expand=True)
        try:
            app.build_ai_tab(owner.content)
            self.root.update()
            checks = self._checks(owner)
            checks[gettext("Enable AI metadata worker on this PC")].invoke()
            self.assertTrue(app.ai_worker_enabled_var.get())
            (picker,) = (widget for widget in descendants(owner) if isinstance(widget, ttk.OptionMenu))
            menu = self.root.nametowidget(picker.cget("menu"))
            labels = MAIN.ai_backend_labels()
            for code, label in labels.items():
                menu.invoke(label)
                self.assertEqual(label, app.ai_vision_backend_var.get())
                self.assertEqual(code, next(key for key, value in labels.items() if value == label))
            buttons = self._buttons(owner)
            buttons[gettext("Install local AI module")].invoke()
            buttons[gettext("Repair dependencies")].invoke()
            buttons[gettext("▶  Start AI worker")].invoke()
            app.repair_semantic_ai_dependencies.assert_called_once_with()
            app.repair_dependencies.assert_called_once_with()
            app.start_ai_worker.assert_called_once_with()
            checks[gettext("Advanced AI settings")].invoke()
            self.assertTrue(app.ai_advanced_frame.grid_info())
            checks[gettext("Advanced AI settings")].invoke()
            self.assertFalse(app.ai_advanced_frame.grid_info())
        finally:
            owner.destroy()

    def _exercise_flight_preferences(self, locale: str, profile: Path) -> None:
        """Change the actual PDF edition switches in a synthetic flight profile.

        Args:
            locale (str): Active desktop locale.
            profile (Path): Independent, empty local profile.

        Returns:
            None: Saves every requested edition and keeps the desktop locale.
        """
        directory = profile / "flights"
        directory.mkdir()
        flight = object.__new__(FlightUI)
        flight.host = SimpleNamespace(
            root=self.root, simulator_presence=MAIN.GamePresence(lambda: "", profile),
        )
        flight.settings_dialog = None
        flight.directory = directory
        flight.preferences = FlightPreferences(recording=False, reports=False, languages=())
        flight.ui_language = tk.StringVar(self.root, value=locale)
        flight.record_var = tk.BooleanVar(self.root, value=False)
        flight.report_var = tk.BooleanVar(self.root, value=False)
        flight.language_vars = {
            code: tk.BooleanVar(self.root) for code in LANGUAGE_NAMES
        }
        flight.plan = None
        flight.busy = mock.Mock(return_value=False)
        flight.refresh_dispatch_status = mock.Mock()
        flight.show_reports = mock.Mock()
        flight.finish_report = mock.Mock()
        flight.open_settings()
        panel = flight.settings_dialog
        self.assertIsNotNone(panel)
        self.root.update()
        checks = self._checks(panel)
        for code in LANGUAGE_NAMES:
            checks[code.upper()].invoke()
            self.assertEqual(
                flight.language_vars[code].get(), code in FlightPreferences.load(directory).languages,
            )
            self.assertEqual(locale, current_language())
            self.assertFalse((profile / "ui-language.json").exists())
        checks[tr(locale, "auto_report")].invoke()
        self.assertTrue(FlightPreferences.load(directory).reports)
        actions = self._buttons(panel)
        actions[tr(locale, "saved_reports")].invoke()
        actions[tr(locale, "finish_pdf")].invoke()
        flight.show_reports.assert_called_once_with()
        flight.finish_report.assert_called_once_with()
        self.assertEqual([], self.callback_errors)
        panel.destroy()

    def test_import_activity_and_photos_options_in_every_locale(self) -> None:
        """Click localized import controls, activity filters and cache choices.

        Returns:
            None: Verifies real controls and stable choices in all four locales.
        """
        self.root.geometry(MAIN.WATCHER_WINDOW_GEOMETRY)
        for locale in LANGUAGE_NAMES:
            with self.subTest(locale=locale):
                self.assertEqual(locale, configure_language(locale))
                surface = MAIN.ScrollableFrame(self.root)
                surface.pack(fill="both", expand=True)
                try:
                    self._exercise_import_options(surface.content)
                    for child in surface.content.winfo_children():
                        child.destroy()
                    self._exercise_activity_filters(surface.content)
                    for child in surface.content.winfo_children():
                        child.destroy()
                    self._exercise_photos_storage_options(surface.content)
                    self.assertEqual([], self.callback_errors)
                finally:
                    surface.destroy()

    def _exercise_import_options(self, owner: ttk.Frame) -> None:
        """Click real recursive and thumbnail choices without starting import.

        Args:
            owner (ttk.Frame): Empty scrollable content mounted in the test root.

        Returns:
            None: Verifies labels, choices and a refreshed preflight request.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.root = self.root
        app.flights = SimpleNamespace(text=lambda key: tr(current_language(), key))
        for name in (
            "gallery_label", "gallery_title", "connection_status", "manual_selection",
            "manual_preflight", "manual_progress_text", "manual_status", "thumbnail_runtime",
        ):
            setattr(app, name + "_var", tk.StringVar(self.root))
        for name in ("import_details_visible", "manual_folder_recursive", "manual_local_thumbnails"):
            setattr(app, name + "_var", tk.BooleanVar(self.root))
        app.manual_progress_var = tk.DoubleVar(self.root)
        for name in (
            "open_connection_dialog", "flight_action", "abandon_flight", "select_manual_files",
            "select_manual_folder", "select_manual_zip", "clear_manual_files", "start_manual_upload",
            "pause_manual_upload", "resume_manual_upload", "stop_manual_upload", "retry_failed_import",
            "rebuild_manual_preflight", "refresh_revoke_button_state",
        ):
            setattr(app, name, mock.Mock())
        # The native runner has Pillow. Drive the local option regardless of
        # the fixture interpreter's declared thumbnail codecs.
        with mock.patch.object(runtime, "local_thumbnail_supported", return_value=True):
            app.build_manual_tab(owner)
        self.root.update()
        self.assertTrue(app.start_import_button.instate(("disabled",)))
        checks = self._checks(owner)
        checks[gettext("Import options and item details")].invoke()
        self.assertTrue(app.import_details_frame.grid_info())
        checks[gettext("Include subfolders")].invoke()
        self.assertTrue(app.manual_folder_recursive_var.get())
        app.rebuild_manual_preflight.assert_called_once_with()
        radios = {
            str(widget.cget("text")): widget
            for widget in descendants(owner) if isinstance(widget, ttk.Radiobutton)
        }
        radios[gettext("This PC creates thumbnails when supported")].invoke()
        self.assertTrue(app.manual_local_thumbnails_var.get())
        radios[gettext("Server creates thumbnails")].invoke()
        self.assertFalse(app.manual_local_thumbnails_var.get())
        checks[gettext("Import options and item details")].invoke()
        self.assertFalse(app.import_details_frame.grid_info())

    def _exercise_activity_filters(self, owner: ttk.Frame) -> None:
        """Select each filter and verify the displayed event identity and text.

        Args:
            owner (ttk.Frame): Empty scrollable content mounted in the test root.

        Returns:
            None: The real display selects rows using stable event classifications.
        """
        app = object.__new__(MAIN.WatcherApp)
        app.root = self.root
        app._activity_render_after_id = None
        app._activity_drawer_flush_count = 0
        app.log_tags_ready = False
        app.activity_job_summary_var = tk.StringVar(self.root)
        app.activity_filter_var = tk.StringVar(self.root, value=gettext("all"))
        events = {
            "active": ActivityEvent("info", "Manual upload started: 7 file(s).", "import"),
            "succeeded": ActivityEvent("success", "Uploaded photo.jpg: uploaded=1", "watcher"),
            "skipped": ActivityEvent("info", "Skipped already present on gallery: same.jpg", "import"),
            "failed": ActivityEvent("error", "Operation failed", "system"),
            "warnings": ActivityEvent("warning", "Manual import paused. In-flight requests may still finish.", "import"),
        }
        app.activity_events = list(events.values())
        for name in (
            "select_tab", "retry_failed_import", "select_history_job", "refresh_job_history",
            "open_support_report", "open_config_folder", "open_log_file", "copy_diagnostics",
        ):
            setattr(app, name, mock.Mock())
        app.build_activity_tab(owner)
        self.root.update()
        (picker,) = (widget for widget in descendants(owner) if isinstance(widget, ttk.Combobox))
        for index, (key, caption) in enumerate(MAIN.activity_filter_labels().items()):
            self.assertEqual(caption, picker.cget("values")[index])
            picker.current(index)
            picker.event_generate("<<ComboboxSelected>>")
            self.root.update()
            active = ("warnings", "active") if key == "active" else (key,)
            expected = list(reversed(app.activity_events)) if key == "all" else [
                events[code] for code in active
            ]
            self.assertEqual(
                [MAIN.WatcherApp.activity_message(event) for event in expected],
                [app.activity_drawer.item(row, "values")[-1]
                 for row in app.activity_drawer.get_children()],
            )

    def _exercise_photos_storage_options(self, owner: ttk.Frame) -> None:
        """Open the real Photos cache panel and select all advertised quotas.

        Args:
            owner (ttk.Frame): Empty scrollable content mounted in the test root.

        Returns:
            None: Localized hints accompany the stable numeric storage choices.
        """
        photos = object.__new__(PhotosWorkspace)
        photos._language = current_language
        card = MAIN.GlassCard(owner)
        card.pack(fill="x")
        photos._storage_surface = owner
        photos._storage_button = MAIN.Button(card, text="")
        photos._storage_snapshot = (None, None, None, False, None)
        photos._retained_storage_snapshot = (None, None, None, None, None, False)
        photos._storage_ram = None
        photos._open_context = mock.Mock(return_value=card)
        photos._context_event = mock.Mock()
        photos._context_content = mock.Mock()
        photos._tag_context_widget = mock.Mock()
        photos._place_context = mock.Mock()
        photos._save_storage_limit = mock.Mock()
        photos._open_storage_context()
        self.root.update()
        photos._context_content.assert_called_once_with(card, photos._text("storage_scope"))
        (picker,) = (widget for widget in descendants(card) if isinstance(widget, ttk.Combobox))
        self.assertEqual(photos._text("storage_limit"), picker.master.storage_hint.get())
        for index, caption in enumerate(picker.cget("values")):
            picker.current(index)
            picker.event_generate("<<ComboboxSelected>>")
            self.root.update()
            quota, widget, _status = photos._save_storage_limit.call_args.args
            self.assertIs(picker, widget)
            self.assertEqual(caption, quota.get())
