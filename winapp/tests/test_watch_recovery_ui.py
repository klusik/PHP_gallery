# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_watch_recovery_ui.py
# Module Type: Regression Test
# Purpose: Verify localized Watcher recovery behavior and native UI layout.
# Responsibilities:
#   - Require explicit receipt semantics in every supported locale.
#   - Keep cancellation and skip copy distinct and source-preserving.
#   - Exercise native desktop/narrow review states when the display is required.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Check recovery wording and render the native Watcher recovery dialog."""

import hashlib
import io
import json
import os
import string
import sys
import tempfile
import time
import unittest
import ctypes
from ctypes import wintypes
from dataclasses import dataclass, replace
from pathlib import Path
import tkinter as tk
from tkinter import ttk
import threading
from types import SimpleNamespace

from PIL import Image, ImageGrab
from uploader.watch_recovery_ui import WatchRecoveryReviewDialog
from uploader.flight_ui import FlightUI
from uploader.watch_queue import observe_watch_source
from uploader.ui_theme import configure_theme

from uploader.flight_report_translations import (
    _WATCH_RECOVERY_LABELS,
    recovery_text,
)

QA_DIR = os.environ.get("PHP_GALLERY_WATCH_RECOVERY_QA_DIR", "")
REQUIRE_TK = os.environ.get("PHP_GALLERY_REQUIRE_WATCH_RECOVERY_TK") == "1"
# Purpose: Select the actual top-level HWND for native Windows screenshots.
# Type: int. Units: Win32 ancestor constant. Scope: recovery UI screenshot capture.
# Consumers: native_capture_handle. Rationale: GA_ROOT excludes Tk child-widget handles.
WIN32_GA_ROOT = 2
# Purpose: Identify the fixture image rendered in the local evidence panel.
# Type: tuple[int, int, int]. Units: RGB color channels. Scope: screenshot evidence.
# Consumers: WatchRecoveryNativeUiTests.capture. Rationale: The cyan image proves the captured window is the dialog.
LOCAL_PREVIEW_MARKER = (34, 170, 204)
# Purpose: Identify the fixture image rendered in the remote evidence panel.
# Type: tuple[int, int, int]. Units: RGB color channels. Scope: screenshot evidence.
# Consumers: WatchRecoveryNativeUiTests.capture. Rationale: The blue image proves the captured window is the dialog.
REMOTE_PREVIEW_MARKER = (32, 64, 160)
# Purpose: Allow minimal color conversion variation in the native screenshot.
# Type: int. Units: RGB channel steps. Scope: screenshot marker assertion.
# Consumers: WatchRecoveryNativeUiTests.capture. Rationale: Two channel steps tolerate platform capture rounding.
PREVIEW_MARKER_TOLERANCE = 2
# Purpose: Bound asynchronous UI fixture completion without hanging native CI.
# Type: float. Units: seconds. Scope: recovery UI tests.
# Consumers: native preview and decision waits. Rationale: Two seconds allows scheduling but keeps failures actionable.
UI_ASYNC_WAIT_SECONDS = 2.0
# Purpose: Bound the final configure event after the narrow resize.
# Type: float. Units: seconds. Scope: responsive native UI fixture.
# Consumers: narrow-window screenshot capture. Rationale: One second allows Tk geometry negotiation.
NARROW_RESIZE_WAIT_SECONDS = 1.0
# Purpose: Let Tk poll queued worker results between fixture assertions.
# Type: float. Units: seconds. Scope: native Tk test loop.
# Consumers: preview and decision waits. Rationale: 10 ms yields promptly without busy polling.
UI_POLL_INTERVAL_SECONDS = 0.01
# Purpose: Set a bounded synthetic Finish regression deadline.
# Type: float. Units: seconds. Scope: uncertain Finish test.
# Consumers: wait_for_workers deadline. Rationale: The expected attention exception is immediate.
FINISH_TEST_DEADLINE_SECONDS = 30.0
# Purpose: Produce a scrollable evidence body in the narrow native fixture.
# Type: int. Units: repeated detail fragments. Scope: mouse-wheel interaction test.
# Consumers: WatchRecoveryNativeUiTests. Rationale: Forty fragments force vertical overflow without resizing the dialog.
SCROLL_FIXTURE_DETAIL_FRAGMENTS = 40
# Purpose: Generate one ordinary Windows mouse-wheel step in the UI fixture.
# Type: int. Units: Win32 wheel delta. Scope: evidence scroll regression.
# Consumers: WatchRecoveryNativeUiTests. Rationale: 120 is the standard single-notch delta.
SCROLL_FIXTURE_WHEEL_DELTA = -120


@dataclass(frozen=True)
class Snapshot:
    """Supply the immutable recovery fields consumed by the dialog fixture."""

    item_id: str
    sequence: int
    source_path: str
    source_signature: str
    source_size: int
    source_mtime_ns: int
    sha256: str
    state: str
    target_binding: str
    session_id: str
    pending_count: int
    acknowledged_count: int
    skipped_count: int
    last_confirmed: str
    progress: str
    progress_at: float
    error: str
    captured_at: float
    last_confirmed_sequence: int
    last_confirmed_at: float
    phase: str
    http_status: str
    review_blocked_by_earlier_upload: bool = False
    retained_stop: bool = False
    prior_blocker_marker: str = ""
    prior_blocker_path: str = ""
    prior_blocker_sha256: str = ""
    prior_blocker_target_binding: str = ""


class FakeCoordinator:
    """Model exact-head decisions and count calls made by visible controls."""

    def __init__(self, snapshot: Snapshot) -> None:
        """Keep the fixture snapshot and decision counters.

        Args:
            snapshot (Snapshot): Immutable pending head rendered by the dialog.
        Returns:
            None: Initializes deterministic test state.
        """
        self.current = snapshot
        self.receipts = 0
        self.skips = 0
        self.prior_reviews = 0
        self.resumes = 0

    def snapshot(self) -> Snapshot:
        """Return the currently captured immutable head.

        Returns:
            Snapshot: Current test head.
        """
        return self.current

    def confirm_receipt(self, snapshot: Snapshot) -> Snapshot:
        """Count a receipt-only decision and preserve the fixture head.

        Args:
            snapshot (Snapshot): Displayed head token.
        Returns:
            Snapshot: Same head in this non-mutating fixture.
        """
        if snapshot != self.current:
            raise ValueError("stale snapshot")
        self.receipts += 1
        return self.current

    def skip_head(self, snapshot: Snapshot) -> Snapshot:
        """Count an explicit skip and preserve the fixture head.

        Args:
            snapshot (Snapshot): Displayed head token.
        Returns:
            Snapshot: Same head in this non-mutating fixture.
        """
        if snapshot != self.current:
            raise ValueError("stale snapshot")
        self.skips += 1
        return self.current

    def review_earlier_blocker(self, snapshot: Snapshot) -> Snapshot:
        """Record a separate review of the earlier upload blocking this item.

        Args:
            snapshot (Snapshot): Displayed never-sent head token.
        Returns:
            Snapshot: Current item remains unchanged and unsent.
        """
        if snapshot != self.current or not snapshot.review_blocked_by_earlier_upload:
            raise ValueError("stale or unrelated blocker")
        self.prior_reviews += 1
        return self.current

    def resume_retained(self, snapshot: Snapshot) -> Snapshot:
        """Count an explicit resume of a retained queue.

        Args:
            snapshot (Snapshot): Displayed retained queue head.
        Returns:
            Snapshot: Current fixture view after the explicit choice.
        """
        if (snapshot != self.current or snapshot.state not in {
                "observed", "stabilizing", "preparing", "inventory", "retry_wait"
        } or snapshot.phase == "source"
                or snapshot.review_blocked_by_earlier_upload):
            raise ValueError("queue is not retained")
        self.resumes += 1
        return self.current


def make_snapshot(path: Path) -> Snapshot:
    """Build exact source metadata for the local preview fixture.

    Args:
        path (Path): Retained local image used in this fixture.
    Returns:
        Snapshot: Head snapshot with matching source size, time and hash.
    """
    info = path.stat()
    content = path.read_bytes()
    observed = observe_watch_source(path, time.time())
    return Snapshot("item-1", 444, str(path), observed.signature, info.st_size,
                    info.st_mtime_ns, hashlib.sha256(content).hexdigest(),
                    "uncertain", "target-021", "session-1", 81, 443, 0,
                    "item-443", "443 acknowledged", time.time(), "HTTP 503",
                    observed.captured_at, 443, time.time(), "dispatching", "503")


class WatchRecoveryTranslationTests(unittest.TestCase):
    """Keep recovery decisions distinct in all maintained locales."""

    def test_all_locales_describe_receipt_skip_and_keep_pending(self) -> None:
        """Require explicit and non-overlapping decision labels.

        Returns:
            None: Fails if any maintained locale loses the key semantics.
        """
        for language in ("en", "cs", "de", "sv"):
            with self.subTest(language=language):
                confirm = recovery_text(language, "watch_recovery_confirm").lower()
                skip = recovery_text(language, "watch_recovery_skip").lower()
                cancel = recovery_text(language, "watch_recovery_cancel").lower()
                self.assertNotEqual(confirm, skip)
                self.assertNotEqual(skip, cancel)
                self.assertTrue(confirm)
                self.assertTrue(skip)
                self.assertTrue(cancel)

    def test_unknown_locale_uses_english_receipt_label(self) -> None:
        """Keep unsupported locales understandable through English fallback.

        Returns:
            None: Asserts the exact fallback action label.
        """
        self.assertEqual(
            "Confirm already uploaded (receipt)",
            recovery_text("xx", "watch_recovery_confirm"),
        )

    def test_locale_keys_and_format_fields_match_english(self) -> None:
        """Require complete four-language labels and matching format fields.

        Returns:
            None: Detects missing labels or unsafe mismatched placeholders.
        """
        english_keys = set(_WATCH_RECOVERY_LABELS["en"])
        formatter = string.Formatter()
        for language in ("cs", "de", "sv"):
            self.assertEqual(english_keys, set(_WATCH_RECOVERY_LABELS[language]))
            for key in sorted(english_keys):
                english_fields = {
                    field for _, field, _, _ in formatter.parse(
                        _WATCH_RECOVERY_LABELS["en"][key]
                    ) if field
                }
                localized_fields = {
                    field for _, field, _, _ in formatter.parse(
                        _WATCH_RECOVERY_LABELS[language][key]
                    ) if field
                }
                self.assertEqual(english_fields, localized_fields,
                                 f"{language}.{key}")


class WatchRecoveryNativeUiTests(unittest.TestCase):
    """Exercise visible decisions, close behavior and responsive screenshots."""

    def test_native_dialog_actions_close_and_responsive_render(self) -> None:
        """Render both widths and ensure closing leaves recovery untouched.

        Returns:
            None: Validates the native Tk controls and writes QA screenshots.
        """
        try:
            root = tk.Tk()
        except tk.TclError as exc:
            if REQUIRE_TK:
                self.fail(f"Required native Tk display is unavailable: {exc}")
            self.skipTest(f"Tk display is unavailable: {exc}")
        root.title("Watcher recovery UI fixture")
        root.geometry("900x650+10+10")
        configure_theme(root)
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "pending.png"
            Image.new("RGB", (64, 48), "#22aacc").save(source)
            coordinator = FakeCoordinator(make_snapshot(source))
            dialog = WatchRecoveryReviewDialog(
                root, coordinator, "en", "Gallery 021")
            dialog.window.geometry("740x520+30+30")
            root.update()
            deadline = time.time() + UI_ASYNC_WAIT_SECONDS
            while not hasattr(dialog.local_image, "image") and time.time() < deadline:
                root.update()
                time.sleep(UI_POLL_INTERVAL_SECONDS)
            self.capture(dialog, "desktop.png")
            self.assert_actions_inside(dialog)
            self.assertFalse(dialog.skip_button.instate(["disabled"]))
            dialog.skip_button.invoke()
            deadline = time.time() + UI_ASYNC_WAIT_SECONDS
            while dialog.busy and time.time() < deadline:
                root.update()
                time.sleep(UI_POLL_INTERVAL_SECONDS)
            self.assertEqual(1, coordinator.skips)
            coordinator.skips = 0
            dialog.window.geometry("390x560+30+30")
            root.update()
            deadline = time.time() + NARROW_RESIZE_WAIT_SECONDS
            while dialog.window.winfo_width() < 390 and time.time() < deadline:
                root.update()
                time.sleep(UI_POLL_INTERVAL_SECONDS)
            self.capture(dialog, "narrow.png")
            self.assert_actions_inside(dialog)
            saved_status = dialog.status.get()
            dialog.status.set("Retained queue evidence "
                              * SCROLL_FIXTURE_DETAIL_FRAGMENTS)
            root.update()
            dialog.canvas.update_idletasks()
            self.assertLess(dialog.canvas.yview()[1], 1.0)
            footer_y = dialog.visible_action_buttons[0].winfo_rooty()
            dialog.local_label.event_generate(
                "<MouseWheel>", delta=SCROLL_FIXTURE_WHEEL_DELTA)
            root.update()
            self.assertGreater(dialog.canvas.yview()[0], 0.0)
            self.assertEqual(footer_y, dialog.visible_action_buttons[0].winfo_rooty())
            dialog.status.set(saved_status)
            dialog.canvas.yview_moveto(0.0)
            root.update()
            legacy = replace(coordinator.current, source_mtime_ns=0, sha256="")
            coordinator.current = legacy
            dialog.snapshot = legacy
            dialog.populate()
            deadline = time.time() + UI_ASYNC_WAIT_SECONDS
            loaded_label = recovery_text("en", "watch_recovery_local_loaded")
            while loaded_label not in dialog.local_var.get() \
                    and time.time() < deadline:
                root.update()
                time.sleep(UI_POLL_INTERVAL_SECONDS)
            self.assertIn(loaded_label, dialog.local_var.get())
            old_info = source.stat()
            replacement = Path(directory) / "replacement.png"
            Image.new("RGB", (64, 48), "#aa3344").save(replacement)
            os.utime(replacement, ns=(old_info.st_atime_ns, old_info.st_mtime_ns))
            os.replace(replacement, source)
            dialog.snapshot = legacy
            dialog.populate()
            deadline = time.time() + UI_ASYNC_WAIT_SECONDS
            replaced_label = recovery_text("en", "watch_recovery_local_replaced")
            while replaced_label not in dialog.local_var.get() \
                    and time.time() < deadline:
                root.update()
                time.sleep(UI_POLL_INTERVAL_SECONDS)
            self.assertIn(replaced_label, dialog.local_var.get())
            old_started = threading.Event()
            old_release = threading.Event()
            current_started = threading.Event()
            current_release = threading.Event()

            def remote_loader(snapshot: Snapshot) -> tuple[bytes, str]:
                """Hold two remote responses to deliver the old one last.

                Args:
                    snapshot (Snapshot): Exact queue view requesting remote evidence.
                Returns:
                    tuple[bytes, str]: Fixture thumbnail and evidence classification.
                """
                color = "#a02020"
                if snapshot.item_id == "item-1":
                    old_started.set()
                    old_release.wait(UI_ASYNC_WAIT_SECONDS)
                else:
                    color = "#2040a0"
                    current_started.set()
                    current_release.wait(UI_ASYNC_WAIT_SECONDS)
                output = io.BytesIO()
                Image.new("RGB", (16, 16), color).save(output, format="PNG")
                return output.getvalue(), "observation"

            dialog.remote_loader = remote_loader
            dialog.load_previews(legacy)
            old_worker = dialog._preview_workers[-1]
            self.assertTrue(old_started.wait(UI_ASYNC_WAIT_SECONDS))
            current = replace(make_snapshot(source), item_id="item-current")
            coordinator.current = current
            dialog.snapshot = current
            dialog.populate()
            current_worker = dialog._preview_workers[-1]
            self.assertTrue(current_started.wait(UI_ASYNC_WAIT_SECONDS))
            current_release.set()
            current_worker.join(UI_ASYNC_WAIT_SECONDS)
            self.assertFalse(current_worker.is_alive())
            dialog.poll_worker_results()
            current_image = dialog.remote_image.image
            self.assertEqual(
                recovery_text("en", "watch_recovery_remote_observation"),
                dialog.remote_var.get(),
            )
            old_release.set()
            old_worker.join(UI_ASYNC_WAIT_SECONDS)
            self.assertFalse(old_worker.is_alive())
            dialog.poll_worker_results()
            self.assertIs(current_image, dialog.remote_image.image)
            self.assertTrue(dialog.confirm_button.instate(["disabled"]))
            prior = replace(current,
                            review_blocked_by_earlier_upload=True,
                            error="Earlier linked upload has an unknown result; review required",
                            prior_blocker_marker="A#443",
                            prior_blocker_path="C:/retained/older-uncertain-A.png",
                            prior_blocker_sha256=("abcdef0123456789" * 4),
                            prior_blocker_target_binding="target-021")
            coordinator.current = prior
            dialog.snapshot = prior
            dialog.populate()
            prior_preview = dialog._preview_workers[-1]
            prior_preview.join(UI_ASYNC_WAIT_SECONDS)
            self.assertFalse(prior_preview.is_alive())
            dialog.poll_worker_results()
            dialog.window.geometry("740x600+30+30")
            root.update()
            self.capture(dialog, "prior-desktop.png")
            prior_status = dialog.status.get()
            self.assertIn("This photo was not sent", prior_status)
            self.assertIn("A#443", prior_status)
            self.assertIn("older-uncertain-A.png", prior_status)
            self.assertIn("abcdef0123456789", prior_status)
            self.assertIn("target-021", prior_status)
            self.assertNotIn(dialog.confirm_button, dialog.visible_action_buttons)
            self.assertNotIn(dialog.skip_button, dialog.visible_action_buttons)
            dialog.window.geometry("390x650+30+30")
            root.update()
            self.capture(dialog, "prior-narrow.png")
            self.assert_actions_inside(dialog)
            dialog.review_earlier()
            dialog.review_earlier()
            self.assertEqual(
                recovery_text("en", "watch_recovery_hide_processing"),
                dialog.cancel_button.cget("text"),
            )
            self.assertEqual(
                recovery_text("en", "watch_recovery_decision_processing"),
                dialog.status.get(),
            )
            deadline = time.time() + UI_ASYNC_WAIT_SECONDS
            while dialog.busy and time.time() < deadline:
                root.update()
                time.sleep(UI_POLL_INTERVAL_SECONDS)
            self.assertEqual(1, coordinator.prior_reviews)
            self.assertEqual(0, coordinator.receipts)
            self.assertEqual(0, coordinator.skips)
            retained = replace(coordinator.current, state="retry_wait",
                               phase="retry_backoff", retained_stop=True,
                               review_blocked_by_earlier_upload=False,
                               error="Retry scheduled after a rate limit.")
            coordinator.current = retained
            dialog.snapshot = retained
            dialog.populate()
            retained_preview = dialog._preview_workers[-1]
            retained_preview.join(UI_ASYNC_WAIT_SECONDS)
            self.assertFalse(retained_preview.is_alive())
            dialog.poll_worker_results()
            self.assertIn(
                recovery_text("en", "watch_recovery_retained_stop"),
                dialog.status.get(),
            )
            self.assertIn("Retry scheduled after a rate limit.", dialog.status.get())
            dialog.window.geometry("740x600+30+30")
            root.update()
            self.capture(dialog, "retained-desktop.png")
            self.assertIn(dialog.resume_button, dialog.visible_action_buttons)
            self.assertNotIn(dialog.skip_button, dialog.visible_action_buttons)
            dialog.window.geometry("390x650+30+30")
            root.update()
            self.capture(dialog, "retained-narrow.png")
            self.assert_actions_inside(dialog)
            dialog.close()
            root.update()
            self.assertEqual(0, coordinator.receipts)
            self.assertEqual(0, coordinator.skips)
            safe_head = replace(
                make_snapshot(source), state="observed", phase="stabilizing",
                retained_stop=False, error="",
            )
            coordinator.current = safe_head
            restored_dialog = WatchRecoveryReviewDialog(
                root, coordinator, "en", "Gallery 021")
            deadline = time.time() + UI_ASYNC_WAIT_SECONDS
            while restored_dialog._snapshot_pending and time.time() < deadline:
                root.update()
                time.sleep(UI_POLL_INTERVAL_SECONDS)
            self.assertFalse(restored_dialog._snapshot_pending)
            self.assertIn(restored_dialog.resume_button,
                          restored_dialog.visible_action_buttons)
            self.assertTrue(restored_dialog.resume_button.instate(["!disabled"]))
            self.assertNotIn(restored_dialog.confirm_button,
                             restored_dialog.visible_action_buttons)
            self.assertNotIn(restored_dialog.skip_button,
                             restored_dialog.visible_action_buttons)
            self.assertIn(
                recovery_text("en", "watch_recovery_resume_available"),
                restored_dialog.status.get(),
            )
            restored_dialog.resume_button.invoke()
            deadline = time.time() + UI_ASYNC_WAIT_SECONDS
            while restored_dialog.busy and time.time() < deadline:
                root.update()
                time.sleep(UI_POLL_INTERVAL_SECONDS)
            self.assertEqual(1, coordinator.resumes)
            restored_dialog.close()
            root.update()
        root.destroy()

    def assert_actions_inside(self, dialog: WatchRecoveryReviewDialog) -> None:
        """Verify visible controls remain inside the native window bounds.

        Args:
            dialog (WatchRecoveryReviewDialog): Rendered native recovery dialog.
        Returns:
            None: Fails when a visible action crosses the dialog boundary.
        """
        dialog.window.update_idletasks()
        left, top = dialog.window.winfo_rootx(), dialog.window.winfo_rooty()
        right, bottom = left + dialog.window.winfo_width(), top + dialog.window.winfo_height()
        for button in dialog.visible_action_buttons:
            x, y = button.winfo_rootx(), button.winfo_rooty()
            self.assertGreaterEqual(x, left)
            self.assertGreaterEqual(y, top)
            self.assertLessEqual(x + button.winfo_width(), right)
            self.assertLessEqual(y + button.winfo_height(), bottom)
        for label in (dialog.remote_label, dialog.local_label, dialog.status_label):
            self.assertLessEqual(int(label.cget("wraplength")), label.winfo_width())

    def test_finish_actions_delegate_and_stop_retains_cancel_state(self) -> None:
        """Keep Force Continue and Stop/keep routed through the host owner.

        Returns:
            None: Confirms no direct success or queue mutation is fabricated.
        """
        calls: list[str] = []
        cancel = threading.Event()
        host = SimpleNamespace(
            open_watch_recovery_review=lambda: calls.append("review"),
            stop_and_keep_watch_pending=lambda on_persisted: (
                calls.append("stop-retain"), on_persisted(True), True
            )[-1],
        )
        adapter = object.__new__(FlightUI)
        adapter.host = host
        adapter.operation = SimpleNamespace(cancel=cancel)
        adapter.detail_var = SimpleNamespace(set=lambda _value: None)
        adapter.text = lambda key: key
        adapter.force_continue_watch()
        adapter.force_stop_watch()
        self.assertEqual(["review", "stop-retain"], calls)
        self.assertTrue(cancel.is_set())
        failed_cancel = threading.Event()
        failed_details: list[str] = []
        failed = object.__new__(FlightUI)
        failed.host = SimpleNamespace(
            stop_and_keep_watch_pending=lambda on_persisted: (
                on_persisted(False), True
            )[-1],
        )
        failed.operation = SimpleNamespace(cancel=failed_cancel)
        failed.detail_var = SimpleNamespace(set=failed_details.append)
        failed.text = lambda key: key
        failed.force_stop_watch()
        self.assertFalse(failed_cancel.is_set())
        self.assertEqual(
            ["watch_stop_stopping", "watch_stop_not_persisted"], failed_details)

    def test_uncertain_finish_enters_needs_attention_without_waiting_forever(self) -> None:
        """Fail Finish promptly while retaining the live Watcher owner.

        Returns:
            None: The lifecycle reports review needed rather than draining forever.
        """
        with tempfile.TemporaryDirectory() as directory:
            queue_path = Path(directory) / "watch-drain.json"
            queue_path.write_text(json.dumps({
                "pending": ["head"],
                "items": [{"sequence": 444, "state": "uncertain",
                           "source": {"path": str(Path(directory) / "pending.png")},
                           "reason": "HTTP 503; result unknown"}],
            }), encoding="utf-8")
            phases: list[tuple[str, str]] = []
            operation = SimpleNamespace(
                wants_revoke=False,
                plans=[SimpleNamespace(directory=Path(directory), abandoned=False)],
                update=lambda phase, detail="": phases.append((phase, detail)),
            )
            alive_worker = SimpleNamespace(is_alive=lambda: True, drain_manifest=object())
            host = SimpleNamespace(
                worker=alive_worker,
                utc_sync=SimpleNamespace(is_alive=lambda: False),
                semantic_ai_install_running=False,
                simulator_presence=SimpleNamespace(
                    text=lambda key, language: recovery_text(language, key)),
            )
            adapter = object.__new__(FlightUI)
            adapter.operation = operation
            adapter.host = host
            adapter.recorder = None
            adapter.resume_workers = []
            adapter.ui_language = SimpleNamespace(get=lambda: "en")
            with self.assertRaisesRegex(RuntimeError, "Needs attention"):
                adapter.wait_for_workers(
                    threading.Event(),
                    time.monotonic() + FINISH_TEST_DEADLINE_SECONDS,
                )
            self.assertEqual("error", phases[-1][0])
            self.assertIn("#444 pending.png: uncertain", phases[-1][1])
            self.assertTrue(alive_worker.is_alive())

    def capture(self, dialog: WatchRecoveryReviewDialog, filename: str) -> None:
        """Save one rendered native dialog size when QA capture is configured.

        Args:
            dialog (WatchRecoveryReviewDialog): Dialog and evidence widgets to capture.
            filename (str): Desktop or narrow screenshot basename.
        Returns:
            None: Writes a PNG artifact when the QA directory is configured.
        """
        if not QA_DIR:
            if REQUIRE_TK:
                self.fail("Native Tk screenshots require PHP_GALLERY_WATCH_RECOVERY_QA_DIR.")
            return
        destination = Path(QA_DIR)
        destination.mkdir(parents=True, exist_ok=True)
        window = dialog.window
        window.deiconify()
        window.attributes("-topmost", True)
        window.lift()
        window.focus_force()
        window.update()
        window.update_idletasks()
        self.assertTrue(dialog.details.winfo_ismapped())
        self.assertGreater(dialog.details.winfo_width(), 0)
        self.assertGreater(dialog.details.winfo_height(), 0)
        detail_text = dialog.details.get("1.0", "end-1c")
        self.assertIn("SHA-256:", detail_text)
        self.assertIn("Verified target:", detail_text)
        self.assertIn("#444", dialog.local_var.get())
        self.assertEqual(
            ttk.Style(window).lookup("TFrame", "background"),
            dialog.details.cget("background"),
        )
        canvas_top = dialog.canvas.winfo_rooty()
        canvas_bottom = canvas_top + dialog.canvas.winfo_height()
        details_top = dialog.details.winfo_rooty()
        details_bottom = details_top + dialog.details.winfo_height()
        self.assertGreater(details_bottom, canvas_top)
        self.assertLess(details_top, canvas_bottom)
        try:
            if sys.platform == "win32":
                capture_handle = self.native_capture_handle(window)
                image = ImageGrab.grab(window=capture_handle)
            else:
                x, y = window.winfo_rootx(), window.winfo_rooty()
                image = ImageGrab.grab((
                    x, y, x + window.winfo_width(), y + window.winfo_height(),
                ))
        finally:
            window.attributes("-topmost", False)
        self.assertGreater(image.width, 0)
        self.assertGreater(image.height, 0)
        colors = image.convert("RGB").getdata()
        def matches_marker(pixel: tuple[int, int, int],
                           marker: tuple[int, int, int]) -> bool:
            """Compare one pixel with a known recovery preview marker.

            Args:
                pixel (tuple[int, int, int]): Captured RGB pixel.
                marker (tuple[int, int, int]): Expected fixture RGB value.
            Returns:
                bool: True when all channels fall within screenshot tolerance.
            """
            return all(
                abs(actual - expected) <= PREVIEW_MARKER_TOLERANCE
                for actual, expected in zip(pixel, marker)
            )

        self.assertTrue(any(
            matches_marker(pixel, LOCAL_PREVIEW_MARKER)
            or matches_marker(pixel, REMOTE_PREVIEW_MARKER)
            for pixel in colors
        ), "Captured window image has no recovery preview marker.")
        image.save(destination / filename)

    def native_capture_handle(self, window: tk.Toplevel) -> int:
        """Resolve and validate the actual top-level Windows window handle.

        Args:
            window (tk.Toplevel): Native recovery dialog to capture.
        Returns:
            int: Valid top-level HWND accepted by Pillow ImageGrab.
        Raises:
            AssertionError: Tk does not map to a valid Win32 top-level window.
        """
        user32 = ctypes.windll.user32
        user32.GetAncestor.argtypes = [wintypes.HWND, wintypes.UINT]
        user32.GetAncestor.restype = wintypes.HWND
        user32.IsWindow.argtypes = [wintypes.HWND]
        user32.IsWindow.restype = wintypes.BOOL
        child_handle = wintypes.HWND(window.winfo_id())
        root_handle = user32.GetAncestor(child_handle, WIN32_GA_ROOT)
        self.assertTrue(root_handle)
        self.assertTrue(user32.IsWindow(root_handle))
        return int(root_handle)


if __name__ == "__main__":
    unittest.main()
