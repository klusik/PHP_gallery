# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_qt_capture_panel.py
# Module Type: Qt capture progress regression
# Purpose: Keep Qt Flight progress tied to live worker and command fences.
# Responsibilities: Cover progress states, Stop admission and native presence.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Guard the Qt capture progress panel's real worker progress projection."""

import os
import sys
import unittest
from pathlib import Path

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

try:
    from uploader.watcher.capture_session import CaptureStatus
    from uploader.watcher.capture_session import watcher_interrupt_available
    from uploader.flight_report_translations import tr
    from uploader.localization import current_language
    from uploader.qt_capture import (
        capture_context_text, capture_idle_connection_action, capture_presence_demanded,
        capture_progress_indeterminate, capture_progress_timing,
    )
except (ImportError, OSError):
    if os.environ.get('PHP_GALLERY_REQUIRE_QT') == '1':
        raise
    CaptureStatus = None
    capture_idle_connection_action = None


@unittest.skipIf(CaptureStatus is None, 'Optional Qt dependencies are absent.')
class QtCaptureProgressPanelTests(unittest.TestCase):
    """Verify the marquee bar represents actual ongoing work only."""

    def test_active_capture_and_finalization_show_indeterminate_progress(self) -> None:
        """Show the second bar for active worker phases with unknown duration.

        Returns:
            None: Both Watcher work and Flight finalization activate the bar.
        """
        for state in (
            CaptureStatus(capturing=True),
            CaptureStatus(recording=True),
            CaptureStatus(finishing=True),
            CaptureStatus(abandon_cleanup_pending=True),
            CaptureStatus(revocation_cleanup_pending=True),
        ):
            with self.subTest(state=state):
                self.assertTrue(capture_progress_indeterminate(state, False))

    def test_paused_live_owner_and_terminal_states_match_tk_bar_lifecycle(self) -> None:
        """Retain the unknown-duration bar while a paused owner remains live.

        Returns:
            None: Idle and terminal sessions are static; a live paused owner remains.
        """
        for state in (
            CaptureStatus(alive=True, resume_available=True),
        ):
            with self.subTest(state=state):
                self.assertTrue(capture_progress_indeterminate(state, False))
        for state in (
            CaptureStatus(),
            CaptureStatus(phase='completed', progress_done=4, progress_total=4),
            CaptureStatus(phase='error', progress_total=3),
        ):
            with self.subTest(state=state):
                self.assertFalse(capture_progress_indeterminate(state, False))

    def test_admitted_command_shows_progress_before_snapshot_arrives(self) -> None:
        """Cover the short Qt command interval before worker state is published.

        Returns:
            None: A real busy command lane animates without fabricating a percent.
        """
        self.assertTrue(capture_progress_indeterminate(CaptureStatus(), True))

    def test_game_presence_is_demanded_only_for_interactive_watcher_surface(self) -> None:
        """Keep hidden, inactive and minimized Qt windows out of native polling.

        Returns:
            None: Presence work requires all visible and active predicates.
        """
        self.assertTrue(capture_presence_demanded(True, True, False, True))
        for values in (
            (False, True, False, True),
            (True, False, False, True),
            (True, True, True, True),
            (True, True, False, False),
        ):
            with self.subTest(values=values):
                self.assertFalse(capture_presence_demanded(*values))

    def test_game_presence_uses_neutral_capture_wording_without_flight(self) -> None:
        """Match the Tk record/settings/progress captions from cached context.

        Returns:
            None: Flight captions remain contextual; ordinary capture is neutral.
        """
        language = current_language()
        self.assertEqual(
            tr(language, 'record_flight'),
            capture_context_text('record_flight', True, language),
        )
        self.assertEqual(
            tr(language, 'record_session'),
            capture_context_text('record_flight', False, language),
        )
        self.assertEqual(
            tr(language, 'session_settings'),
            capture_context_text('flight_settings', False, language),
        )
        self.assertEqual(
            tr(language, 'begin_game'),
            capture_context_text('begin_flight', False, language),
        )

    def test_idle_connection_action_respects_capture_and_linked_mode(self) -> None:
        """Match Tk Begin/Reconnect admission without starting a worker.

        Returns:
            None: Idle API-key setup and explicitly selected linked reconnection
                are distinct from live, retained and competing operations.
        """
        self.assertEqual(
            'begin', capture_idle_connection_action(
                CaptureStatus(), False, False, False, False,
            ),
        )
        self.assertEqual(
            'reconnect', capture_idle_connection_action(
                CaptureStatus(), False, False, False, True,
            ),
        )
        for state, command_busy, pending, protected in (
            (CaptureStatus(capturing=True), False, False, False),
            (CaptureStatus(alive=True), False, False, False),
            (CaptureStatus(resume_available=True), False, False, False),
            (CaptureStatus(finishing=True), False, False, False),
            (CaptureStatus(phase='error'), False, False, False),
            (CaptureStatus(), True, False, False),
            (CaptureStatus(), False, True, False),
            (CaptureStatus(), False, False, True),
        ):
            with self.subTest(state=state, command_busy=command_busy):
                self.assertIsNone(capture_idle_connection_action(
                    state, command_busy, pending, protected, False,
                ))

    def test_timing_uses_real_elapsed_phase_and_eta_snapshot_values(self) -> None:
        """Keep timing tied to the finalizer's monotonic timestamps and ETA.

        Returns:
            None: Active ETA and frozen terminal time reflect supplied snapshots.
        """
        active = CaptureStatus(
            phase='uploading', progress_started=20.0,
            progress_phase_started=25.0,
        )
        estimating = capture_progress_timing(active, 30.0)
        self.assertIn('10s', estimating)
        self.assertIn('5s', estimating)
        estimating_label = tr(current_language(), 'estimating')
        self.assertIn(estimating_label, estimating)

        transferring = CaptureStatus(
            phase='uploading', progress_started=20.0,
            progress_phase_started=25.0, eta_seconds=12.8,
        )
        self.assertIn('12s', capture_progress_timing(transferring, 30.0))

        terminal = CaptureStatus(
            phase='completed', progress_started=20.0,
            progress_phase_started=30.0, eta_seconds=0.0,
        )
        finished = capture_progress_timing(terminal, 100.0)
        self.assertIn('10s', finished)
        self.assertNotIn('100s', finished)
        self.assertNotIn(estimating_label, finished)

        self.assertEqual('', capture_progress_timing(CaptureStatus(), 100.0))

    def test_watcher_stop_matches_live_worker_and_server_request_fence(self) -> None:
        """Offer Stop while draining, then retire it at request admission.

        Returns:
            None: UTC or recorder liveness alone never exposes Watcher Stop.
        """
        cases = (
            (True, False, True),   # Worker draining before finalizer request.
            (True, True, False),   # The server request boundary has been crossed.
            (False, False, False), # Independent UTC/recorder work is not Watcher.
        )
        for watcher_active, request_sent, expected in cases:
            with self.subTest(watcher_active=watcher_active, request_sent=request_sent):
                self.assertEqual(
                    expected,
                    watcher_interrupt_available(watcher_active, request_sent),
                )


if __name__ == '__main__':
    unittest.main()
