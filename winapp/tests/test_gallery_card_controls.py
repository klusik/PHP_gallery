# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_gallery_card_controls.py
# Module Type: Gallery Card Control Regression Tests
# Purpose: Verify immediate local gallery choices without creating Tk windows.
# Responsibilities:
#   - Exercise real workspace callbacks with mocked widget boundaries.
#   - Preserve per-gallery intent, visible save errors and session fencing.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Headless behavior tests for the compact photo-card format controls."""

from pathlib import Path
import sys
import unittest
from unittest import mock


WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.app_linking import LinkingSession
from uploader.photos_workspace import PHOTO_CARD_FORMATS, PhotosWorkspace


class GalleryCardControlTests(unittest.TestCase):
    """Exercise presentation and save ordering without a live application."""

    def _workspace(self) -> tuple[PhotosWorkspace, LinkingSession, mock.Mock]:
        """Create the complete state consumed by the two preference callbacks.

        Returns:
            tuple[PhotosWorkspace, LinkingSession, mock.Mock]: Real workspace
                methods, exact session and mocked existing viewer controller.
        """
        workspace = PhotosWorkspace.__new__(PhotosWorkspace)
        session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
        workspace._session = session
        workspace._session_provider = mock.Mock(return_value=session)
        # Two positive IDs distinguish neighboring albums without server access.
        workspace._selected_gallery_id = 1
        workspace._card_format_overrides = {}
        workspace._card_format_unsaved = set()
        workspace._card_format_buttons = {
            key: mock.Mock() for key in PHOTO_CARD_FORMATS
        }
        workspace._card_format_images = {
            (key, selected): None
            for key in PHOTO_CARD_FORMATS for selected in (False, True)
        }
        workspace._card_format_hint = mock.Mock()
        workspace._card_format_notice = mock.Mock()
        workspace._load_card_format = mock.Mock(return_value="default")
        workspace._save_card_format = mock.Mock(return_value=True)
        workspace._text = mock.Mock(side_effect=lambda key: key)
        workspace._dismiss_context = mock.Mock()
        controller = mock.Mock()
        workspace._viewer = mock.Mock(_gallery_viewer_controller=controller)
        return workspace, session, controller

    def test_choice_applies_before_its_immediate_local_save(self) -> None:
        """Apply the view and persist without rebuilding or navigating away.

        Returns:
            None: The existing controller updates before one exact gallery save.
        """
        workspace, session, controller = self._workspace()
        timeline = mock.Mock()
        timeline.attach_mock(controller.set_card_format, "display")
        timeline.attach_mock(workspace._save_card_format, "save")
        workspace._choose_card_format(session, 1, "21:9")
        self.assertEqual([
            mock.call.display("21:9"), mock.call.save(session, 1, "21:9"),
        ], timeline.mock_calls)
        workspace._load_card_format.assert_not_called()
        workspace._card_format_buttons["21:9"].configure.assert_called_with(
            state="normal", image="", style="Selected.Format.Photos.Icon.TButton",
        )
        workspace._card_format_notice.set.assert_called_with("")

    def test_passive_restore_reads_each_gallery_without_saving(self) -> None:
        """Restore per-gallery preferences without writing on ordinary browsing.

        Returns:
            None: Each gallery keeps its local format and navigation saves none.
        """
        workspace, session, _controller = self._workspace()
        workspace._load_card_format.side_effect = ("4:5", "16:9")
        workspace._restore_card_format(session, 1)
        workspace._restore_card_format(session, 2)
        workspace._restore_card_format(session, 1)
        self.assertEqual({1: "4:5", 2: "16:9"}, workspace._card_format_overrides)
        self.assertEqual([
            mock.call(session, 1), mock.call(session, 2),
        ], workspace._load_card_format.call_args_list)
        workspace._save_card_format.assert_not_called()

    def test_save_failure_preserves_display_and_visible_retry_hint(self) -> None:
        """Keep the selected preview after a failed local write.

        Returns:
            None: The failed gallery retains intent and a visible warning.
        """
        workspace, session, controller = self._workspace()
        workspace._save_card_format.return_value = False
        workspace._choose_card_format(session, 1, "9:16")
        controller.set_card_format.assert_called_once_with("9:16")
        workspace._restore_card_format(session, 1)
        self.assertEqual("9:16", workspace._card_format_overrides[1])
        workspace._card_format_notice.set.assert_called_with("!")
        workspace._card_format_hint.set.assert_called_with("card_format_unsaved")

    def test_stale_gallery_or_session_action_cannot_save(self) -> None:
        """Fence a captured action against gallery and account changes.

        Returns:
            None: Stale actions change neither the current view nor preferences.
        """
        workspace, session, controller = self._workspace()
        workspace._choose_card_format(session, 2, "1:1")
        workspace._session_provider.return_value = None
        workspace._choose_card_format(session, 1, "1:1")
        controller.set_card_format.assert_not_called()
        workspace._save_card_format.assert_not_called()
