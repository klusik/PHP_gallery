# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_watcher_dirty_presentation.py
# Module Type: Windows Tooling Regression Test
# Purpose: Protect retained Watcher presentation from redundant Tk updates.
# Responsibilities:
#   - Verify monitor-state Canvas items are retained across state updates.
#   - Verify contextual Watcher visibility changes only with flight context.
#   - Verify repeated footer text does not suppress ordered activity writes.
#   - Verify burst footer and hidden Activity drawer synchronization.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Verify Watcher presentation updates follow visible state changes."""

import importlib.machinery
import importlib.util
import sys
import unittest
from pathlib import Path
from types import ModuleType, SimpleNamespace
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))


def load_main_module() -> ModuleType:
    """Load the Watcher entry point without constructing its GUI.

    Returns:
        ModuleType: Production module exposing WatcherApp.

    Raises:
        RuntimeError: The import loader cannot create a source specification.
    """
    source_path = WINAPP_DIR / "gallery_watch_upload.pyw"
    loader = importlib.machinery.SourceFileLoader(
        "gallery_watcher_dirty_presentation_test", str(source_path),
    )
    spec = importlib.util.spec_from_loader(loader.name, loader)
    if spec is None:
        raise RuntimeError("Could not create the Watcher test import spec.")
    module = importlib.util.module_from_spec(spec)
    sys.modules[loader.name] = module
    loader.exec_module(module)
    return module


MAIN = load_main_module()


class WatcherDirtyPresentationTests(unittest.TestCase):
    """Assert retained Watcher widgets mutate only when their state changes."""

    def test_monitor_state_retains_one_canvas_item_and_updates_its_color(self) -> None:
        """Keep the monitor oval while updating color, detail and state label.

        Returns:
            None: The Canvas item is created once, retained, and recolored only
                when the monitor state changes.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.monitor_state = "disabled"
        app.monitor_detail = "Paused"
        app.monitor_state_var = mock.Mock()
        app.monitor_detail_var = mock.Mock()
        app.monitor_light = mock.Mock()

        MAIN.WatcherApp.update_monitor_state(app, "disabled", "Paused")

        self.assertEqual(1, app.monitor_light.create_oval.call_count)
        app.monitor_light.delete.assert_not_called()
        app.monitor_light.itemconfigure.reset_mock()
        app.monitor_state_var.set.reset_mock()
        app.monitor_detail_var.set.reset_mock()

        MAIN.WatcherApp.update_monitor_state(app, "disabled", "Paused")

        app.monitor_light.create_oval.assert_called_once()
        app.monitor_light.delete.assert_not_called()
        app.monitor_light.itemconfigure.assert_not_called()
        app.monitor_state_var.set.assert_not_called()
        app.monitor_detail_var.set.assert_not_called()

        MAIN.WatcherApp.update_monitor_state(app, "green", "Watching folder")

        app.monitor_light.create_oval.assert_called_once()
        app.monitor_light.delete.assert_not_called()
        app.monitor_light.itemconfigure.assert_called_once()
        app.monitor_state_var.set.assert_called_once_with("Monitoring active")
        app.monitor_detail_var.set.assert_called_once_with("Watching folder")

        app.monitor_state_var.set.reset_mock()
        app.monitor_light.itemconfigure.reset_mock()
        MAIN.WatcherApp.update_monitor_state(app, "green", "Uploads: 2")

        app.monitor_light.create_oval.assert_called_once()
        app.monitor_light.delete.assert_not_called()
        app.monitor_light.itemconfigure.assert_not_called()
        app.monitor_state_var.set.assert_not_called()
        self.assertEqual(
            [mock.call("Watching folder"), mock.call("Uploads: 2")],
            app.monitor_detail_var.set.call_args_list,
        )

    def test_contextual_visibility_changes_only_with_flight_state(self) -> None:
        """Retain row visibility and ordinary UTC status across refreshes.

        Returns:
            None: Contextual row operations occur only at state transitions and
                an unrelated UTC status remains visible outside flight context.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.simulator_presence = SimpleNamespace(flight_detected=False)
        app.flights = SimpleNamespace(refresh_contextual_labels=mock.Mock())
        app.watcher_utc_row = mock.Mock()
        app.utc_sync = SimpleNamespace(
            status=mock.Mock(return_value="UTC sync ready"),
        )
        app.utc_sync_status_var = mock.Mock()

        MAIN.WatcherApp.refresh_contextual_watcher_copy(app)
        app.watcher_utc_row.grid_remove.assert_called_once()
        app.utc_sync_status_var.set.assert_called_once_with("UTC sync ready")

        app.watcher_utc_row.grid_remove.reset_mock()
        app.utc_sync_status_var.set.reset_mock()
        MAIN.WatcherApp.refresh_contextual_watcher_copy(app)

        app.watcher_utc_row.grid_remove.assert_not_called()
        app.watcher_utc_row.grid.assert_not_called()
        app.utc_sync_status_var.set.assert_not_called()

        app.simulator_presence.flight_detected = True
        MAIN.WatcherApp.refresh_contextual_watcher_copy(app)
        app.watcher_utc_row.grid.assert_called_once()
        app.watcher_utc_row.grid_remove.assert_not_called()

        app.watcher_utc_row.grid.reset_mock()
        MAIN.WatcherApp.refresh_contextual_watcher_copy(app)
        app.watcher_utc_row.grid.assert_not_called()

        app.simulator_presence.flight_detected = False
        app.utc_sync.status.return_value = "UTC sync awaiting simulator: MSFS"
        MAIN.WatcherApp.refresh_contextual_watcher_copy(app)
        app.watcher_utc_row.grid_remove.assert_called_once()
        app.utc_sync_status_var.set.assert_called_with("")

    def test_explicit_watch_start_and_poll_share_the_same_button_projection(self) -> None:
        """Keep immediate startup feedback synchronized with periodic refresh.

        Returns:
            None: The first busy and subsequent idle captions each publish once.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.root = mock.Mock()
        app.watcher_action_button = mock.Mock()
        app.refresh_watcher_header = mock.Mock()
        app.flights = mock.Mock()
        app.flights.session_state.return_value = "idle"
        app.flights.ui_language.get.return_value = "en"
        app.worker = None

        app._set_watch_start_activity(True)
        app.refresh_watcher_action()
        app._set_watch_start_activity(False)
        app.refresh_watcher_action()

        self.assertEqual(
            [
                mock.call(text="Starting Watcher…", state="disabled"),
                mock.call(text="▶  Start Watcher", state="normal"),
            ],
            app.watcher_action_button.configure.call_args_list,
        )

    def test_hidden_log_tail_keeps_durable_order_and_flushes_the_visible_batch(self) -> None:
        """Retain ordered log writes with one visible, bounded Tk append.

        Returns:
            None: The hidden tab performs no Tk operation and evicts its oldest
                inline record at the configured tail limit.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.log_text = mock.Mock()
        app.log_text.tag_names.return_value = ("timestamp", "system", "warning")
        app._activity_tab_selected = False
        app._drain_running = True
        app._displayed_log_lines = 0
        # Three source lines expose eviction from the two-record inline tail.
        app.job_store = mock.Mock(history_limit=2)
        app._pending_log_lines = MAIN.deque(maxlen=app.job_store.history_limit)
        with mock.patch.object(MAIN.logging, "info") as persistent:
            app.write_log("first")
            app.write_log("second", "warning")
            app.write_log("third")

        persistent.assert_has_calls([
            mock.call("UI: %s", "first"),
            mock.call("UI: %s", "second"),
            mock.call("UI: %s", "third"),
        ])
        app.log_text.insert.assert_not_called()
        app._activity_tab_selected = True
        app._flush_visible_log()
        app._flush_visible_log()
        app.log_text.insert.assert_called_once()
        rendered = app.log_text.insert.call_args.args
        self.assertNotIn("first\n", rendered)
        self.assertLess(rendered.index("second\n"), rendered.index("third\n"))
        app.log_text.see.assert_called_once_with("end")

    def test_duplicate_footer_text_keeps_each_activity_submission_ordered(self) -> None:
        """Avoid duplicate footer writes without dropping durable events.

        Returns:
            None: Equivalent normalized footer text is set once while both
                ordered activity events are retained and submitted.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.api_key_var = mock.Mock()
        app.api_key_var.get.return_value = ""
        app.latest_activity_var = mock.Mock()
        app.latest_activity_label = None
        app.activity_events = []
        app.config = SimpleNamespace()
        app.activity_writer = SimpleNamespace(submit=mock.Mock(return_value=True))
        app._schedule_activity_drawer_refresh = mock.Mock()

        MAIN.WatcherApp.record_activity(
            app, "info", "  Watching   folder  ", "watcher",
        )
        MAIN.WatcherApp.record_activity(
            app, "info", "Watching folder", "watcher",
        )

        app.latest_activity_var.set.assert_called_once_with(
            "Watcher · Watching folder",
        )
        self.assertEqual(2, len(app.activity_events))
        submitted = [
            call.args[0] for call in app.activity_writer.submit.call_args_list
        ]
        self.assertEqual(app.activity_events, submitted)
        self.assertEqual(
            ["  Watching   folder  ", "Watching folder"],
            [event.message for event in submitted],
        )
        self.assertEqual(2, app._schedule_activity_drawer_refresh.call_count)

    def test_activity_footer_publishes_only_last_event_in_batch(self) -> None:
        """Retain ordered activity while publishing one footer for a burst.

        Returns:
            None: All events reach the writer before the final preview is applied.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.latest_activity_var = mock.Mock()
        app.latest_activity_label = mock.Mock()
        app.activity_events = []
        app.config = SimpleNamespace()
        app.activity_writer = SimpleNamespace(submit=mock.Mock(return_value=True))
        app._schedule_activity_drawer_refresh = mock.Mock()
        app._drain_running = True

        app.record_activity("warning", "First result", "watcher")
        app.record_activity("error", "Final result", "watcher")

        app.latest_activity_var.set.assert_not_called()
        app.latest_activity_label.configure.assert_not_called()
        self.assertEqual(
            ["First result", "Final result"],
            [call.args[0].message for call in app.activity_writer.submit.call_args_list],
        )
        app._flush_activity_footer()
        app.latest_activity_var.set.assert_called_once_with("Watcher · Final result")
        app.latest_activity_label.configure.assert_called_once_with(style="Footer.Error.TLabel")

    def test_hidden_activity_refresh_waits_until_the_panel_is_visible(self) -> None:
        """Keep one pending Activity view synchronization while hidden.

        Returns:
            None: Repeated notifications behind another panel schedule no
                Tk work. Showing Activity admits exactly one render.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.root = mock.Mock()
        app.root.after_idle.return_value = "after-activity-refresh"
        app.exiting = False
        app._activity_render_after_id = None
        app._activity_tab_selected = False
        app.activity_drawer = mock.Mock()
        app.refresh_activity_drawer = mock.Mock()

        app._schedule_activity_drawer_refresh()
        app._schedule_activity_drawer_refresh()
        app.root.after_idle.assert_not_called()
        self.assertTrue(app._activity_drawer_dirty)

        app._activity_tab_selected = True
        app._schedule_activity_drawer_refresh()
        app._schedule_activity_drawer_refresh()
        app.root.after_idle.assert_called_once()
        app.root.after_idle.call_args.args[0]()
        app.refresh_activity_drawer.assert_called_once_with()


if __name__ == "__main__":
    unittest.main()
