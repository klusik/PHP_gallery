# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_canvas_renderer_setting.py
# Module Type: Windows Tooling Regression Tests
# Purpose: Verify persisted and frozen experimental gallery renderer choices.
# Responsibilities:
#   - Cover legacy migration, strict environment overrides and durable settings.
#   - Ensure immediate UI saves roll back on failure without hot-switching.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Regression coverage for persisted Canvas choices and factory defaults."""

from dataclasses import replace
from pathlib import Path
import os
import sys
import tempfile
import unittest
from unittest import mock


WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.config import (
    CANVAS_GALLERY_RENDERER_ENVIRONMENT_VARIABLE,
    resolve_canvas_gallery_renderer_enabled,
)
from test_redesign import MAIN


class CanvasRendererSettingTests(unittest.TestCase):
    """Verify configuration, environment priority and restart-only UI behavior."""

    def test_legacy_defaults_and_strict_saved_values(self) -> None:
        """Enable Canvas when missing and preserve explicit saved preferences.

        Returns:
            None: Checks migration and WatcherConfig JSON round trips.
        """
        legacy = MAIN.WatcherConfig.from_dict({"api_key": "kept"})
        self.assertTrue(legacy.experimental_canvas_gallery_renderer)
        self.assertTrue(MAIN.WatcherConfig().experimental_canvas_gallery_renderer)
        self.assertFalse(
            MAIN.WatcherConfig.from_dict({
                "experimental_canvas_gallery_renderer": False,
            }).experimental_canvas_gallery_renderer,
        )
        self.assertFalse(
            MAIN.WatcherConfig.from_dict({
                "experimental_canvas_gallery_renderer": 1,
            }).experimental_canvas_gallery_renderer,
        )
        enabled = MAIN.WatcherConfig.from_dict({
            "experimental_canvas_gallery_renderer": True,
        })
        self.assertTrue(enabled.experimental_canvas_gallery_renderer)
        self.assertTrue(
            MAIN.WatcherConfig.from_dict(
                enabled.to_dict(),
            ).experimental_canvas_gallery_renderer,
        )
        self.assertEqual("kept", legacy.api_key)

    def test_environment_override_is_exact_and_fails_closed(self) -> None:
        """Resolve saved values only when the override is absent.

        Returns:
            None: Exact 1/0 values override; malformed values remain disabled.
        """
        key = CANVAS_GALLERY_RENDERER_ENVIRONMENT_VARIABLE
        self.assertFalse(resolve_canvas_gallery_renderer_enabled(False, {}))
        self.assertTrue(resolve_canvas_gallery_renderer_enabled(True, {}))
        self.assertTrue(resolve_canvas_gallery_renderer_enabled(False, {key: "1"}))
        self.assertFalse(resolve_canvas_gallery_renderer_enabled(True, {key: "0"}))
        for invalid in ("true", "yes", "", " 1", "2"):
            with self.subTest(value=invalid):
                self.assertFalse(
                    resolve_canvas_gallery_renderer_enabled(
                        True, {key: invalid},
                    ),
                )
        with mock.patch.dict(os.environ, {key: "0"}):
            self.assertFalse(resolve_canvas_gallery_renderer_enabled(True))

    def test_nonstandard_config_path_saves_on_and_off_durably(self) -> None:
        """Round-trip both choices through the existing ConfigStore owner.

        Returns:
            None: The nested temporary profile reloads both saved values.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "profile data" / "custom.json"
            initial_store = MAIN.ConfigStore(path)
            config = initial_store.load()
            self.assertTrue(config.experimental_canvas_gallery_renderer)

            config.experimental_canvas_gallery_renderer = True
            initial_store.save(config)
            enabled_store = MAIN.ConfigStore(path)
            enabled = enabled_store.load()
            self.assertTrue(enabled.experimental_canvas_gallery_renderer)

            enabled.experimental_canvas_gallery_renderer = False
            enabled_store.save(enabled)
            off_store = MAIN.ConfigStore(path)
            self.assertFalse(
                off_store.load().experimental_canvas_gallery_renderer,
            )

    def test_setting_save_does_not_hot_switch_current_process(self) -> None:
        """Save immediately while retaining the startup renderer snapshot.

        Returns:
            None: The user receives a restart notice after the saved state changes.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.config = MAIN.WatcherConfig(
            experimental_canvas_gallery_renderer=False,
        )
        app._canvas_grid_enabled_at_startup = False
        app.experimental_canvas_gallery_renderer_var = mock.Mock()
        app.experimental_canvas_gallery_renderer_var.get.return_value = True
        app.updates = None
        app.api_key_revoke_running = False
        app.root = mock.Mock()
        app.write_log = mock.Mock()
        app._save_owned_config = mock.Mock(side_effect=lambda proposed: proposed)

        with mock.patch.object(MAIN.messagebox, "showinfo") as showinfo:
            app.save_canvas_renderer_preference()

        self.assertTrue(app.config.experimental_canvas_gallery_renderer)
        self.assertFalse(app._canvas_grid_enabled_at_startup)
        app._save_owned_config.assert_called_once()
        showinfo.assert_called_once()

    def test_workspace_uses_frozen_startup_choice_after_saved_change(self) -> None:
        """Keep future gallery mounts on the startup choice until restart.

        Returns:
            None: A persisted-on preference does not replace a startup-off
                renderer snapshot passed to PhotosWorkspace.
        """
        app = mock.MagicMock()
        app._canvas_grid_enabled_at_startup = False
        app.config = MAIN.WatcherConfig(
            experimental_canvas_gallery_renderer=True,
        )

        with mock.patch.object(MAIN, "PhotosWorkspace") as workspace_type:
            MAIN.WatcherApp.build_photos_tab(app, mock.Mock())

        self.assertFalse(
            workspace_type.call_args.kwargs["canvas_grid_enabled"],
        )

    def test_blocked_immediate_save_rolls_back_without_write(self) -> None:
        """Refuse the toggle during update or revocation ownership.

        Returns:
            None: Both protected states restore the saved value and show a notice.
        """
        for update_blocked, revoke_running in ((True, False), (False, True)):
            with self.subTest(
                update_blocked=update_blocked,
                revoke_running=revoke_running,
            ):
                app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
                app.config = MAIN.WatcherConfig(
                    experimental_canvas_gallery_renderer=False,
                )
                app.experimental_canvas_gallery_renderer_var = mock.Mock()
                app.experimental_canvas_gallery_renderer_var.get.return_value = True
                app.updates = mock.Mock(
                    blocked=mock.Mock(return_value=update_blocked),
                )
                app.api_key_revoke_running = revoke_running
                app.root = mock.Mock()
                app._save_owned_config = mock.Mock()

                with mock.patch.object(MAIN.messagebox, "showwarning") as warning:
                    app.save_canvas_renderer_preference()

                app.experimental_canvas_gallery_renderer_var.set.assert_called_once_with(
                    False,
                )
                app._save_owned_config.assert_not_called()
                warning.assert_called_once()

    def test_failed_immediate_save_restores_saved_switch(self) -> None:
        """Keep the visible value aligned with disk when persistence fails.

        Returns:
            None: The existing saved value is retained and the error is shown.
        """
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.config = MAIN.WatcherConfig(
            experimental_canvas_gallery_renderer=False,
        )
        app.experimental_canvas_gallery_renderer_var = mock.Mock()
        app.experimental_canvas_gallery_renderer_var.get.return_value = True
        app.updates = None
        app.api_key_revoke_running = False
        app.root = mock.Mock()
        app._save_owned_config = mock.Mock(side_effect=OSError("write failed"))

        with mock.patch.object(MAIN.messagebox, "showerror") as showerror:
            app.save_canvas_renderer_preference()

        app.experimental_canvas_gallery_renderer_var.set.assert_called_once_with(False)
        self.assertFalse(app.config.experimental_canvas_gallery_renderer)
        showerror.assert_called_once()


if __name__ == "__main__":
    unittest.main()
