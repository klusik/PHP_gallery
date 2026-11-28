# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_gui_visual_parity.py
# Module Type: Hosted production GUI visual captures
# Purpose: Capture paired Tk and Qt WinApp workspaces on one native runner.
# Responsibilities: Seed an identical profile, use isolated application
#   processes, and retain screenshots with display, geometry and action data.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Capture corresponding production workspaces for hosted visual review."""

import argparse
from contextlib import ExitStack
from hashlib import sha256
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
from threading import Event
from tkinter import font as tkfont
from typing import Callable, Literal, TypeAlias
from tkinter import ttk
import unittest
from unittest import mock
import webbrowser

from PIL import Image, ImageDraw, ImageGrab
import tkinter as tk

WINAPP_DIR = Path(__file__).resolve().parents[1]
TESTS_DIR = Path(__file__).resolve().parent
for _source_path in (str(WINAPP_DIR), str(TESTS_DIR)):
    if _source_path not in sys.path:
        sys.path.insert(0, _source_path)

from test_redesign import MAIN
from uploader.linking import (
    LegacyThumbnailPublication, LinkedGallery, LinkedGalleryTree,
    LinkedIdentity, LinkedMediaPage, LinkedPhoto, LinkedPresentation,
    LinkingAttempt, LinkingError,
    LinkingSession,
    MediaReadTimings, PushedAuthorization,
    ThumbnailBatchStats,
    ThumbnailPublication,
)
from uploader.gallery_view_preferences import (
    PHOTO_CARD_FORMATS, PhotosTreePreferences, load_photos_tree_preferences,
    photos_account_tree_path, save_gallery_photo_order,
)
from uploader.config import load_ui_language, save_ui_language
from uploader.desktop_connection import DesktopLinkedSite
from uploader.diagnostics import redact_text
from uploader.gallery_media_cache import PhotoCacheReceipt
from uploader.flight_jobs import FlightPreferences, SessionPlan, connection_binding
from uploader.flight_report_translations import tr
from uploader.gui_backend_profile import inspect_gui_profile_work
from uploader.models import ActivityEvent, ImportJob, JsonValue
from uploader.state_store import (
    JobStateStore, save_recent_gallery_addresses,
)
from uploader.ui_layout import OVERLAY_MARGIN_PX, OVERLAY_WIDTH_PX
from uploader.watcher.queue import WatchQueueStore, observe_watch_source
from uploader.localization import configure_language, gettext
from uploader.watcher import runtime, state as watcher_state
from uploader.watcher import configuration as watcher_configuration
from uploader.watcher.ui import app as watcher_app_module

VisualEvidenceValue: TypeAlias = (
    JsonValue | tuple[str, ...] | set[str] | set[tuple[str, str]]
)
VisualAssertionMessage: TypeAlias = JsonValue | tuple[str, str] | None
# Purpose: Bound only the unittest summary, while full detail stays in JSON.
# Type: int. Units: Unicode characters. Scope: visual test failure summaries.
# Consumers: slice and manifest unittest failures.
# Rationale: Keep structured test reports valid when artifact diagnostics are large.
VISUAL_FAILURE_SUMMARY_LIMIT = 240


def visual_failure_summary(failures: list[str], artifact_name: str) -> str:
    """Build a compact unittest failure while retaining full artifact diagnostics.

    Args:
        failures (list[str]): Full failure strings already written to JSON artifacts.
        artifact_name (str): Basename of the detailed report for this failed check.
    Returns:
        str: Failure count, bounded first reason and report basename.
    """
    first_failure = failures[0] if failures else "No detailed failure was recorded."
    if len(first_failure) > VISUAL_FAILURE_SUMMARY_LIMIT:
        first_failure = first_failure[:VISUAL_FAILURE_SUMMARY_LIMIT] + "…"
    return (
        f"{len(failures)} visual failure(s); {first_failure} "
        f"See {artifact_name}."
    )


class _VisualAssertionCollector:
    """Accumulate independent visual assertions until evidence is serialized."""

    def __init__(
        self, test_case: unittest.TestCase, failures: list[str],
    ) -> None:
        """Retain one test assertion owner and its failure list.

        Args:
            test_case (unittest.TestCase): Native test case whose assertions remain authoritative.
            failures (list[str]): Artifact failures retained before the final failing assertion.
        Returns:
            None: The collector is ready to run assertion methods without short-circuiting.
        """
        self._test_case = test_case
        self._failures = failures
        self._group = "visual evidence"

    def set_group(self, group: str) -> None:
        """Set the identity attached to subsequent failed assertions.

        Args:
            group (str): Frontend or paired-cell identity for diagnostic attribution.
        Returns:
            None: Later messages identify this evidence group.
        """
        self._group = group

    def _run_assertion(self, name: str, assertion: Callable[[], None]) -> None:
        """Record one failed unittest assertion and continue collecting evidence.

        Args:
            name (str): Exact unittest assertion name for a concise fallback message.
            assertion (Callable[[], None]): Bound assertion call with its typed inputs.
        Returns:
            None: Failure is recorded, while success adds no diagnostic.
        """
        try:
            assertion()
        except AssertionError as error:
            detail = str(error) or f"{name} assertion failed."
            self._failures.append(f"{self._group}: {detail}")

    def assertEqual(
        self,
        first: VisualEvidenceValue,
        second: VisualEvidenceValue,
        msg: VisualAssertionMessage = None,
    ) -> None:
        """Collect a unittest equality assertion.

        Args:
            first (VisualEvidenceValue): Actual or expected scalar, JSON structure or identity set.
            second (VisualEvidenceValue): Paired evidence value to compare.
            msg (VisualAssertionMessage): Optional string, JSON value or identity context.
        Returns:
            None: A comparison failure is retained for the final artifact.
        """
        self._run_assertion(
            "assertEqual", lambda: self._test_case.assertEqual(first, second, msg),
        )

    def assertFalse(
        self, expr: bool | JsonValue, msg: VisualAssertionMessage = None,
    ) -> None:
        """Collect a unittest false assertion.

        Args:
            expr (bool | JsonValue): Evidence value expected to be falsey.
            msg (VisualAssertionMessage): Optional string, JSON value or identity context.
        Returns:
            None: A truthy value is retained as a failure.
        """
        self._run_assertion("assertFalse", lambda: self._test_case.assertFalse(expr, msg))

    def assertGreater(
        self, first: int | float, second: int | float,
        msg: VisualAssertionMessage = None,
    ) -> None:
        """Collect a strict greater-than assertion.

        Args:
            first (int | float): Measured value expected to exceed the bound.
            second (int | float): Comparison bound.
            msg (VisualAssertionMessage): Optional failure context.
        Returns:
            None: An unsuccessful comparison is retained.
        """
        self._run_assertion(
            "assertGreater", lambda: self._test_case.assertGreater(first, second, msg),
        )

    def assertGreaterEqual(
        self, first: int | float, second: int | float,
        msg: VisualAssertionMessage = None,
    ) -> None:
        """Collect a greater-than-or-equal assertion.

        Args:
            first (int | float): Measured value expected to meet the bound.
            second (int | float): Comparison bound.
            msg (VisualAssertionMessage): Optional failure context.
        Returns:
            None: An unsuccessful comparison is retained.
        """
        self._run_assertion(
            "assertGreaterEqual",
            lambda: self._test_case.assertGreaterEqual(first, second, msg),
        )

    def assertIn(
        self, member: VisualEvidenceValue, container: VisualEvidenceValue,
        msg: VisualAssertionMessage = None,
    ) -> None:
        """Collect a membership assertion for one captured value.

        Args:
            member (VisualEvidenceValue): Caption or identity expected in the collection.
            container (VisualEvidenceValue): Actual sequence, set, mapping or text evidence.
            msg (VisualAssertionMessage): Optional failure context.
        Returns:
            None: Missing membership is retained for the final artifact.
        """
        self._run_assertion(
            "assertIn", lambda: self._test_case.assertIn(member, container, msg),
        )

    def assertLess(
        self, first: int | float, second: int | float,
        msg: VisualAssertionMessage = None,
    ) -> None:
        """Collect a strict less-than assertion.

        Args:
            first (int | float): Measured value expected below the bound.
            second (int | float): Comparison bound.
            msg (VisualAssertionMessage): Optional failure context.
        Returns:
            None: An unsuccessful comparison is retained.
        """
        self._run_assertion(
            "assertLess", lambda: self._test_case.assertLess(first, second, msg),
        )

    def assertLessEqual(
        self,
        first: int | float | set[tuple[str, str]],
        second: int | float | set[tuple[str, str]],
        msg: VisualAssertionMessage = None,
    ) -> None:
        """Collect a less-than-or-equal assertion.

        Args:
            first (int | float | set[tuple[str, str]]): Measured value or identity subset.
            second (int | float | set[tuple[str, str]]): Numeric bound or containing identity set.
            msg (VisualAssertionMessage): Optional failure context.
        Returns:
            None: An unsuccessful comparison is retained.
        """
        self._run_assertion(
            "assertLessEqual",
            lambda: self._test_case.assertLessEqual(first, second, msg),
        )

    def assertNotIn(
        self, member: VisualEvidenceValue, container: VisualEvidenceValue,
        msg: VisualAssertionMessage = None,
    ) -> None:
        """Collect a non-membership assertion for captured evidence.

        Args:
            member (VisualEvidenceValue): Identity expected to be absent.
            container (VisualEvidenceValue): Actual sequence, set, mapping or text evidence.
            msg (VisualAssertionMessage): Optional failure context.
        Returns:
            None: Unexpected membership is retained.
        """
        self._run_assertion(
            "assertNotIn", lambda: self._test_case.assertNotIn(member, container, msg),
        )

    def assertTrue(
        self, expr: bool | JsonValue, msg: VisualAssertionMessage = None,
    ) -> None:
        """Collect a unittest true assertion.

        Args:
            expr (bool | JsonValue): Evidence value expected to be truthy.
            msg (VisualAssertionMessage): Optional failure context.
        Returns:
            None: A falsey value is retained for the final artifact.
        """
        self._run_assertion("assertTrue", lambda: self._test_case.assertTrue(expr, msg))


QT_LOAD_ERROR: ImportError | OSError | None = None
try:
    from PySide6.QtCore import QEventLoop, QObject, QPoint, QTimer, Qt
    from PySide6.QtGui import QGuiApplication
    from PySide6.QtTest import QTest
    from PySide6.QtWidgets import (
        QApplication, QAbstractButton, QComboBox, QFrame, QLabel, QLineEdit, QMenu,
        QScrollArea, QTreeWidget, QWidget,
    )
    from uploader.qt_application import QtWindow
    from uploader.qt_photo_source import PhotoRow
    from uploader.qt_photos import LinkedPhotos
    from uploader.qt_theme import apply_qt_theme
except (ImportError, OSError) as error:
    QT_LOAD_ERROR = error
    QApplication = None
    QEventLoop = QObject = QPoint = QGuiApplication = QAbstractButton = QComboBox = Qt = None
    QLabel = QFrame = QLineEdit = QMenu = QScrollArea = QTreeWidget = QWidget = QTimer = None
    PhotoRow = LinkedPhotos = None

# Purpose: Select hosted visual evidence without burdening ordinary suite runs.
# Type: str. Units: environment value. Scope: one canonical GUI visual test.
# Consumers: VisualParityTests.test_production_workspaces_match_capture_matrix.
# Rationale: Central audit opts in only after artifacts and display capture are ready.
VISUAL_SELECTOR = "PHP_GALLERY_VISUAL_PARITY"
# Purpose: Select a separately seeded pending legacy Watcher recovery profile.
# Type: str. Units: environment value. Scope: one hosted paired Watcher probe.
# Consumers: the central visual child dispatcher. Rationale: Preserve the main
# shell captures while proving both real frontend recovery controls from one
# read-only queue identity without dispatching its local source.
VISUAL_WATCHER_RECOVERY_PROBE = "PHP_GALLERY_VISUAL_WATCHER_RECOVERY_PROBE"
# Purpose: Exercise matching narrow, standard, wide and maximized window layouts.
# Type: tuple[tuple[str, int, int], ...]. Units: logical pixels. Scope: all screens.
# Consumers: capture_tk, capture_qt and metadata validation.
# Rationale: Preserve the shared 980x580 and narrow 840x500 baselines; wide is
# screen-fitted on smaller displays; 0x0 marks native maximize.
VISUAL_SIZES = (
    ("standard", 980, 580), ("narrow", 840, 500), ("wide", 1080, 620),
    ("maximized", 0, 0),
)
# Purpose: Bound native Czech locale evidence to the requested narrow viewport.
# Type: tuple[tuple[str, int, int], ...]. Units: logical pixels. Scope: one locale lane.
# Consumers: the Czech child capture matrix. Rationale: three paired states add
# native localization evidence without rerunning the full English size matrix.
VISUAL_CZECH_SIZES = (("narrow-cs", 840, 500),)
# Purpose: Keep native capture viewports clear of desktop chrome.
# Type: int. Units: logical pixels. Scope: normal and screen-fitted captures.
# Consumers: visual_size_matrix, place_tk_capture_window, maximize fallback.
# Rationale: Leave room for window decorations and the Windows taskbar.
VISUAL_SCREEN_MARGIN = 64
# Purpose: Require native or fallback maximize to exceed standard viewport area.
# Type: float. Units: viewport area ratio. Scope: maximize evidence.
# Consumers: VisualParityTests.test_production_workspaces_match_capture_matrix.
# Rationale: A seven-percent increase rejects unchanged Xvfb maximize geometry
# while allowing work-area reductions from desktop menus and docks.
VISUAL_MAX_AREA_RATIO = 1.07
# Purpose: Require maximized mode to gain visible vertical workspace.
# Type: float. Units: viewport height ratio. Scope: maximize evidence.
# Consumers: Tk and Qt capture plus paired report validation.
# Rationale: Five-percent height change prevents area-only width changes passing.
VISUAL_MAX_HEIGHT_RATIO = 1.05
# Purpose: Simulate one fractional high-density scale without changing the Xvfb display.
# Type: float. Units: toolkit scale factor. Scope: one Linux-only visual child pair.
# Consumers: VisualParityTests.test_synthetic_150_percent_scale and child dispatch.
# Rationale: 1.5 exercises fractional scaling while the ordinary runner DPI lane remains native.
VISUAL_SYNTHETIC_SCALE = 1.5
# Purpose: Match the configured scale while allowing toolkit subpixel rounding.
# Type: float. Units: relative scale. Scope: synthetic DPI assertions.
# Consumers: VisualParityTests.test_synthetic_150_percent_scale.
# Rationale: Two hundredths covers Tk's Xvfb scale quantization.
VISUAL_SYNTHETIC_SCALE_TOLERANCE = 0.02
# Purpose: Keep synthetic high-density Photos controls within a narrow logical viewport.
# Type: tuple[str, int, int]. Units: name and logical client pixels. Scope: one synthetic lane.
# Consumers: synthetic Tk/Qt child captures. Rationale: Fit the scaled Qt window on Xvfb.
VISUAL_SYNTHETIC_SIZE = ("synthetic-150-narrow", 760, 460)
# Purpose: Capture every current Tk destination and Qt's embedded Flight state.
# Type: tuple[str, ...]. Units: stable workspace IDs. Scope: each size/backend.
# Consumers: capture_tk, capture_qt and paired report serialization.
# Rationale: Flight is a subview of Watcher in the reference Tk shell.
VISUAL_WORKSPACES = (
    "photos", "photos-lightbox", "watcher", "flight", "import", "activity",
    "ai", "settings",
)
# Purpose: Add paired Photos state evidence without expanding every layout case.
# Type: tuple[str, ...]. Units: scenario workspace IDs. Scope: standard size.
# Consumers: capture child reports, paired matrix and report renderer.
# Rationale: Keep high-value Photos operator states, including Music, at
# standard size without multiplying each interaction across every viewport.
VISUAL_SCENARIO_WORKSPACES = (
    "photos-disconnected", "photos-ratio-16-9", "photos-selected",
    "photos-multiselected", "photos-linked-empty", "photos-hierarchy",
    "photos-info", "photos-context-menu",
    "photos-connecting",
    "photos-delete-confirmation",
    "photos-folder-shelf",
    "photos-music-panel",
    "photos-info-unavailable",
    "photos-lightbox-fullscreen",
)
# Purpose: Divide the full native visual contract into separately bounded children.
# Type: tuple[str, ...]. Units: stable slice identities. Scope: English native matrix.
# Consumers: visual test fan-out, child capture selector and final manifest.
# Rationale: Each exact test ID keeps the same-host pair under the hosted child deadline.
VISUAL_CAPTURE_SLICE_IDS = (
    "workspace-standard", "workspace-narrow", "workspace-wide",
    "workspace-maximized", "photos-connect", "photos-selection",
    "photos-tree", "photos-details", "photos-lightbox",
)
VisualCaptureSlice: TypeAlias = tuple[
    tuple[tuple[str, int, int], ...], tuple[str, ...], tuple[str, ...], bool,
]
# Purpose: Assign each required capture identity to exactly one hosted test ID.
# Type: dict[str, VisualCaptureSlice]. Units: capture scopes. Scope: native English matrix.
# Consumers: visual test methods and independent Tk/Qt child dispatch.
# Rationale: Disjoint slices preserve the complete union while bounding each child.
VISUAL_CAPTURE_SLICES: dict[str, VisualCaptureSlice] = {
    "workspace-standard": ((VISUAL_SIZES[0],), VISUAL_WORKSPACES, (), True),
    "workspace-narrow": ((VISUAL_SIZES[1],), VISUAL_WORKSPACES, (), False),
    "workspace-wide": ((VISUAL_SIZES[2],), VISUAL_WORKSPACES, (), False),
    "workspace-maximized": ((VISUAL_SIZES[3],), VISUAL_WORKSPACES, (), False),
    "photos-connect": (
        (VISUAL_SIZES[0],), (), ("photos-disconnected", "photos-connecting"), False,
    ),
    "photos-selection": (
        (VISUAL_SIZES[0],), (), ("photos-ratio-16-9", "photos-selected",
                                 "photos-multiselected", "photos-linked-empty"),
        False,
    ),
    "photos-tree": (
        (VISUAL_SIZES[0],), (),
        ("photos-hierarchy", "photos-folder-shelf", "photos-music-panel"), False,
    ),
    "photos-details": (
        (VISUAL_SIZES[0],), (),
        ("photos-info", "photos-context-menu", "photos-info-unavailable",
         "photos-delete-confirmation"), False,
    ),
    "photos-lightbox": (
        (VISUAL_SIZES[0],), (), ("photos-lightbox-fullscreen",), False,
    ),
}
# Purpose: Distinguish a full direct child invocation from bounded matrix slices.
# Type: str. Units: selector identity. Scope: one native capture child.
# Consumers: capture_tk, capture_qt and the child command dispatcher.
# Rationale: Existing Czech, recovery and scale probes retain their own selectors.
VISUAL_CAPTURE_SLICE_ID = "full"
# Purpose: Disclose Photos states not yet captured by the native matrix.
# Type: tuple[str, ...]. Units: state coverage names. Scope: all hosted platforms.
# Consumers: paired visual report and qualification review.
# Rationale: Missing operator flows remain visible as explicit evidence gaps.
VISUAL_COVERAGE_GAPS = (
    "live service-backed online session",
    "cancellation of an active metadata read",
    "Only one valid single-item legacy pending queue is paired; blocked, malformed, linked-mode and uncertain recovery cases are not captured",
)
# Purpose: Allow native layout negotiation before collecting a screenshot.
# Type: float. Units: seconds. Scope: one selected workspace and size.
# Consumers: capture_tk and capture_qt.
# Rationale: A quarter-second admits resize, idle geometry and queued scene updates.
VISUAL_SETTLE_SECONDS = 0.25
# Purpose: Keep the synthetic browser request valid while the native cancel action is observed.
# Type: float. Units: seconds. Scope: one fake visual authorization handoff.
# Consumers: hold_visual_authorization_request. Rationale: One minute exceeds
# the bounded capture but is never sent to a remote service.
VISUAL_AUTH_EXPIRY_SECONDS = 60.0
# Purpose: Bound one production frontend process and its full capture matrix.
# Type: int. Units: seconds. Scope: one backend's independent child.
# Consumers: VisualParityTests.test_production_workspaces_match_capture_matrix.
# Rationale: Bounded native captures use short settle intervals; the child
# timeout guards their aggregate work on slower hosted display runners.
VISUAL_CHILD_SECONDS = 60
# Purpose: Bound one native event pump slice in the Qt capture child.
# Type: int. Units: milliseconds. Scope: one wait during screen preparation.
# Consumers: settle_qt. Rationale: 25 ms processes queued layouts and frames responsively.
VISUAL_QT_EVENT_SLICE_MS = 25
# Purpose: Yield between observations without making the fixture busy-loop.
# Type: float. Units: seconds. Scope: Tk and Qt settle loops.
# Consumers: settle_tk and settle_qt. Rationale: Ten milliseconds leaves the native loop available.
VISUAL_PUMP_DELAY_SECONDS = 0.01
# Purpose: Keep synthetic Activity projection within the normal bounded history.
# Type: int. Units: retained events. Scope: both native visual profiles.
# Consumers: seed_profile and QtWindow. Rationale: 100 admits the small visual fixture.
VISUAL_HISTORY_LIMIT = 100
# Purpose: Keep persisted synthetic activity and import identity identical across slices.
# Type: float. Units: Unix seconds. Scope: every normal visual seed profile.
# Consumers: seed_profile. Rationale: Stable persisted timestamps let the manifest
# bind one exact profile across separately hosted test IDs.
VISUAL_SEED_TIMESTAMP = 1_700_000_000.0
# Purpose: Keep the persisted synthetic import row identity stable across slices.
# Type: str. Units: import job ID. Scope: every normal visual seed profile.
# Consumers: seed_profile. Rationale: A fixed ID removes ImportJob.create UUID drift.
VISUAL_SEED_IMPORT_JOB_ID = "44500000000000000000000000000001"
# Purpose: Bound retained action captions in the structured geometry report.
# Type: int. Units: characters. Scope: each Tk or Qt native action caption.
# Consumers: tk_widget_text and qt_widget_text. Rationale: 180 retains labels without unbounded text.
VISUAL_ACTION_CAPTION_CHARS = 180
# Purpose: Identify one immutable local Flight fixture shared by both applications.
# Type: str. Units: hexadecimal session ID. Scope: local synthetic profile.
# Consumers: seed_profile.
# Rationale: A fixed non-secret identity makes paired Flight rows deterministic.
VISUAL_SESSION_ID = "44500000000000000000000000000000"
# Purpose: Keep the local-only Watcher recovery target deterministic and unreachable.
# Type: str. Units: normalized URL. Scope: one synthetic legacy binding.
# Consumers: seed_profile, Tk recovery UI and Qt profile admission.
# Rationale: Reserved .invalid host keeps the real connection fingerprint
# without permitting a live gallery connection.
VISUAL_WATCHER_FIXTURE_URL = "https://visual-fixture.invalid/index.php"
VISUAL_PHOTOS_ISSUER = 'https://visual-fixture.invalid'
# Purpose: Identify the same synthetic site account in both Photos clients.
# Type: int. Units: account IDs. Scope: isolated visual link input.
# Consumers: Tk and Qt visual captures. Rationale: One fixture account owns the tree.
VISUAL_PHOTOS_ACCOUNT = 1
# Purpose: Bind the synthetic pending queue without exposing a real credential.
# Type: str. Units: API-key characters. Scope: one synthetic legacy binding.
# Consumers: seed_profile and exact read-only profile admission.
# Rationale: A fixed non-secret key yields identical normalized bindings.
VISUAL_WATCHER_FIXTURE_KEY = "issue445-visual-pending-key"
# Purpose: Identify the sole pending local source in the recovery queue.
# Type: str. Units: local filename. Scope: one Watcher probe source.
# Consumers: seed_profile and paired journal identity assertions.
# Rationale: A deterministic source filename ties both child profiles to the
# same shared read-only media fixture.
VISUAL_WATCHER_FIXTURE_SOURCE = "issue445-pending-visual.jpg"


def visual_mode_vault() -> mock.MagicMock:
    """Provide an empty disposable vault to the real legacy-mode coordinator.

    Returns:
        mock.MagicMock: Transaction owner whose real coordinator observes no
            linked-mode record and therefore admits the profile's legacy key.
    """
    vault = mock.MagicMock()
    vault.transaction.return_value.__enter__.return_value.read.return_value = None
    return vault


def hold_visual_authorization_request(
    attempt: LinkingAttempt, *, prefer_upload: bool = False,
    prefer_rolling: bool = False, prefer_presentation: bool = False,
    prefer_admin_media: bool = False, require_admin_media: bool = False,
) -> PushedAuthorization:
    """Return a synthetic nonauthorizing request after the UI shows requesting.

    Args:
        attempt (LinkingAttempt): Ephemeral local callback attempt whose real
            loopback listener remains owned by the frontend.
        prefer_upload (bool): Upload preference passed by the production caller.
        prefer_rolling (bool): Renewal preference passed by the production caller.
        prefer_presentation (bool): Presentation preference passed by the caller.
        prefer_admin_media (bool): Admin media preference passed by the caller.
        require_admin_media (bool): Whether admin media was required by the caller.
    Returns:
        PushedAuthorization: Synthetic URL handle; no network request is made.
    """
    del (
        attempt, prefer_upload, prefer_rolling, prefer_presentation,
        prefer_admin_media, require_admin_media,
    )
    return PushedAuthorization(
        "visual-fixture-request",
        "https://visual-fixture.invalid/authorize",
        time.monotonic() + VISUAL_AUTH_EXPIRY_SECONDS,
    )
# Purpose: Keep the shared visual media catalogue small and fully deterministic.
# Type: int. Units: raster pixels. Scope: one local synthetic photo.
# Consumers: seed_profile. Rationale: 640x420 shows the same image clearly in both viewers.
VISUAL_PHOTO_SIZE = (640, 420)
# Purpose: Keep the hosted paired contact sheet readable and bounded.
# Type: tuple[int, int]. Units: thumbnail pixels. Scope: generated review sheet.
# Consumers: make_visual_contact_sheet. Rationale: 360x202 preserves landscape UI.
VISUAL_CONTACT_THUMBNAIL_SIZE = (360, 202)
# Purpose: Reserve one caption line above each paired fixture row.
# Type: int. Units: pixels. Scope: generated review sheet.
# Consumers: make_visual_contact_sheet. Rationale: 24px fits one default font line.
VISUAL_CONTACT_LABEL_HEIGHT = 24
# Purpose: Separate two native screenshots within each review row.
# Type: int. Units: pixels. Scope: generated review sheet.
# Consumers: make_visual_contact_sheet. Rationale: 16px visibly divides captures.
VISUAL_CONTACT_GAP = 16
# Purpose: Bound the number of identical synthetic linked-gallery entries.
# Type: int. Units: photos. Scope: paired Photos workspace fixture.
# Consumers: seed_profile and visual_linking_session. Rationale: Four cards fill the first row without large artifacts.
VISUAL_PHOTO_COUNT = 4
# Purpose: Give the local synthetic gallery a stable numeric server identity.
# Type: int. Units: gallery ID. Scope: offline paired viewer fixture.
# Consumers: visual_linking_session. Rationale: One positive ID represents a single root gallery.
VISUAL_GALLERY_ID = 445
# Purpose: Give the hierarchy screenshots a stable first nested gallery identity.
# Type: int. Units: gallery ID. Scope: visual-only child of the fixture gallery.
# Consumers: visual_linking_session and paired hierarchy/shelf states.
# Rationale: The child exceeds the root ID and remains within fixture identity.
VISUAL_CHILD_GALLERY_ID = 446
# Purpose: Give the expanded hierarchy a second direct child for shelf evidence.
# Type: int. Units: gallery ID. Scope: visual-only sibling of the fixture root.
# Consumers: visual_linking_session and the Photos folder shelf scenario.
# Rationale: A two-card shelf proves ordered identity-to-artwork mapping.
VISUAL_SECOND_CHILD_GALLERY_ID = 447
# Purpose: Identify deterministic cover photos for synthetic child galleries.
# Type: tuple[int, int]. Units: image IDs. Scope: visual shelf only.
# Consumers: visual_linking_session and paired folder-card assertions.
# Rationale: Distinct IDs keep both child covers independent from root photos.
VISUAL_FOLDER_COVER_PHOTO_IDS = (11, 12)
# Purpose: Bound waiting for the synthetic Tk catalogue and first raster.
# Type: float. Units: seconds. Scope: one visual workspace transition.
# Consumers: wait_tk. Rationale: Five seconds covers loaded CI worker scheduling.
VISUAL_OPERATION_TIMEOUT_SECONDS = 5.0
# Purpose: Bound clean-connect-card area while allowing native toolkit padding.
# Type: float. Units: fraction of the Photos workspace area. Scope: clean launch.
# Consumers: disconnected-card visual qualification. Rationale: The action must
# remain a compact trigger card instead of expanding to fill the empty workspace.
VISUAL_CONNECT_CARD_MAX_AREA_RATIO = 0.25
_VisualThumbnailPublisher: TypeAlias = Callable[
    [ThumbnailPublication | LegacyThumbnailPublication, MediaReadTimings,
     Callable[[], None]],
    None,
]


@unittest.skipUnless(
    os.environ.get(VISUAL_SELECTOR) == "1",
    "Hosted GUI visual parity capture is opt-in.",
)
class VisualParityTests(unittest.TestCase):
    """Run paired Tk and Qt production captures in independently bounded slices."""

    def test_production_workspaces_match_capture_matrix(self) -> None:
        """Capture the standard-size production workspace set.

        Returns:
            None: Standard-size pairs retain exact identity and structural evidence.
        """
        self._run_visual_capture_slice("workspace-standard")

    def test_visual_slice_01_workspace_narrow(self) -> None:
        """Capture the narrow production workspace set independently.

        Returns:
            None: Narrow pairs retain the full shared workspace identity set.
        """
        self._run_visual_capture_slice("workspace-narrow")

    def test_visual_slice_02_workspace_wide(self) -> None:
        """Capture the screen-fitted wide production workspace set independently.

        Returns:
            None: Wide pairs retain the full shared workspace identity set.
        """
        self._run_visual_capture_slice("workspace-wide")

    def test_visual_slice_03_workspace_maximized(self) -> None:
        """Capture the genuinely expanded or screen-sized native maximize set.

        Returns:
            None: Maximize pairs retain the same workspace identities and geometry proof.
        """
        self._run_visual_capture_slice("workspace-maximized")

    def test_visual_slice_04_photos_connect(self) -> None:
        """Pair the fresh disconnected and real connecting Photos states.

        Returns:
            None: Both shell states retain their native foreground and fixture proof.
        """
        self._run_visual_capture_slice("photos-connect")

    def test_visual_slice_05_photos_selection(self) -> None:
        """Pair ratio, selection and empty-gallery Photos states.

        Returns:
            None: Selected IDs and visible ratio controls remain strictly compared.
        """
        self._run_visual_capture_slice("photos-selection")

    def test_visual_slice_06_photos_tree(self) -> None:
        """Pair hierarchy, folder shelf and Music Photos states.

        Returns:
            None: Folder identities, dimensions and supplemental captures remain checked.
        """
        self._run_visual_capture_slice("photos-tree")

    def test_visual_slice_07_photos_details(self) -> None:
        """Pair metadata, context and destructive confirmation Photos states.

        Returns:
            None: Actual native detail/action surfaces remain required evidence.
        """
        self._run_visual_capture_slice("photos-details")

    def test_visual_slice_08_photos_lightbox(self) -> None:
        """Pair the fullscreen Lightbox as an isolated native capture.

        Returns:
            None: Fullscreen visible-frame and identity assertions remain strict.
        """
        self._run_visual_capture_slice("photos-lightbox")

    def test_visual_slice_99_manifest(self) -> None:
        """Verify and combine the exact complete set of previously captured slices.

        Returns:
            None: A durable aggregate and its hash-bound slice manifest are emitted.
        """
        self._write_visual_slice_manifest()

    def _run_visual_capture_slice(self, slice_id: str) -> None:
        """Capture and compare one disjoint native slice of the English matrix.

        Args:
            slice_id (str): Stable identity from VISUAL_CAPTURE_SLICE_IDS.
        Returns:
            None: Writes slice-specific child reports, screenshots and paired JSON.
        Raises:
            KeyError: The requested slice is not part of the fixed capture matrix.
        """
        global VISUAL_SIZES, VISUAL_WORKSPACES, VISUAL_SCENARIO_WORKSPACES
        global VISUAL_CAPTURE_SLICE_ID
        if slice_id not in VISUAL_CAPTURE_SLICES:
            raise KeyError(f"Unknown visual capture slice: {slice_id}")
        prior_scope = {
            "VISUAL_SIZES": VISUAL_SIZES,
            "VISUAL_WORKSPACES": VISUAL_WORKSPACES,
            "VISUAL_SCENARIO_WORKSPACES": VISUAL_SCENARIO_WORKSPACES,
            "VISUAL_CAPTURE_SLICE_ID": VISUAL_CAPTURE_SLICE_ID,
        }
        self.addCleanup(globals().update, prior_scope)
        (
            VISUAL_SIZES, VISUAL_WORKSPACES, VISUAL_SCENARIO_WORKSPACES,
            include_watcher_probe,
        ) = VISUAL_CAPTURE_SLICES[slice_id]
        VISUAL_CAPTURE_SLICE_ID = slice_id
        output_value = os.environ.get("PHP_GALLERY_VISUAL_PARITY_OUTPUT", "")
        self.assertTrue(output_value, "The hosted visual artifact directory is required.")
        output = Path(output_value).resolve()
        output.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix="issue-445-visual-") as temporary:
            root = Path(temporary)
            seed = root / "seed-profile"
            profile = root / "active-profile"
            seed_profile(seed)
            seed_hash = profile_digest(seed)
            reports: dict[str, dict[str, JsonValue]] = {}
            report_paths: dict[str, Path] = {}
            child_returncodes: dict[str, int] = {}
            child_failures: list[str] = []
            for backend in ("tk", "qt"):
                if profile.exists():
                    self.assertEqual(root.resolve(), profile.resolve().parent)
                    shutil.rmtree(profile)
                shutil.copytree(seed, profile)
                report_path = output / f"{backend}-{slice_id}-capture.json"
                try:
                    child = subprocess.run(
                        [
                            sys.executable, str(Path(__file__).resolve()),
                            "--capture-backend", backend,
                            "--visual-slice", slice_id,
                            "--profile", str(profile),
                            "--output", str(output),
                            "--report", str(report_path),
                        ],
                        cwd=WINAPP_DIR.parent,
                        env=dict(os.environ, PHP_GALLERY_VISUAL_SEED_SHA256=seed_hash),
                        capture_output=True, text=True, timeout=VISUAL_CHILD_SECONDS,
                        check=False,
                    )
                except subprocess.TimeoutExpired as error:
                    stdout = error.stdout or ""
                    stderr = error.stderr or ""
                    if isinstance(stdout, bytes):
                        stdout = stdout.decode("utf-8", errors="replace")
                    if isinstance(stderr, bytes):
                        stderr = stderr.decode("utf-8", errors="replace")
                    (output / f"{backend}-{slice_id}-child.stdout.log").write_text(
                        stdout, encoding="utf-8",
                    )
                    (output / f"{backend}-{slice_id}-child.stderr.log").write_text(
                        stderr, encoding="utf-8",
                    )
                    child_failures.append(
                        f"{backend} visual child timed out after "
                        f"{VISUAL_CHILD_SECONDS}s; stderr={stderr[-4000:]}"
                    )
                    continue
                except OSError as error:
                    child_failures.append(
                        f"{backend} visual child could not start: {error}"
                    )
                    continue
                (output / f"{backend}-{slice_id}-child.stdout.log").write_text(
                    child.stdout, encoding="utf-8",
                )
                (output / f"{backend}-{slice_id}-child.stderr.log").write_text(
                    child.stderr, encoding="utf-8",
                )
                child_returncodes[backend] = child.returncode
                if not report_path.is_file():
                    child_failures.append(
                        f"{backend} visual child exited {child.returncode} and "
                        "did not write its capture report; "
                        f"stderr={child.stderr[-4000:]}"
                    )
                    continue
                report_paths[backend] = report_path
            for backend, report_path in report_paths.items():
                try:
                    reports[backend] = json.loads(
                        report_path.read_text(encoding="utf-8"),
                    )
                except (OSError, json.JSONDecodeError) as error:
                    child_failures.append(
                        f"{backend} visual child report could not be read: {error}"
                    )
                    continue
                if (
                    child_returncodes.get(backend, 0) != 0
                    and reports[backend].get("status") == "PASS"
                ):
                    child_failures.append(
                        f"{backend} visual child exited {child.returncode} "
                        "after claiming a complete report."
                    )
                for failure in reports[backend].get("capture_failures", []):
                    child_failures.append(
                        f"{backend} capture {failure.get('workspace', 'unknown')} "
                        f"failed ({failure.get('exception_type', 'Exception')}): "
                        f"{failure.get('message', '')}"
                    )
            watcher_pending_rows: dict[str, dict[str, JsonValue]] = {}
            if include_watcher_probe:
                watcher_seed = root / "watcher-pending-seed"
                watcher_source_root = root / "watcher-pending-input"
                watcher_source_root.mkdir(parents=True)
                seed_profile(
                    watcher_seed, include_flight=False, pending_watcher=True,
                    watcher_source_root=watcher_source_root,
                )
                watcher_seed_hash = profile_digest(watcher_seed)
                watcher_expected_item_ids, _watcher_seed_journal_sha = (
                    watcher_pending_queue_evidence(
                        watcher_seed / "flights" / VISUAL_SESSION_ID / "watch-drain.json",
                    )
                )
                if len(watcher_expected_item_ids) != 1:
                    raise AssertionError(
                        "Pending Watcher seed must contain exactly one item."
                    )
                for backend in ("tk", "qt"):
                    watcher_profile = root / f"{backend}-watcher-pending-profile"
                    shutil.copytree(watcher_seed, watcher_profile)
                    watcher_report_path = output / f"{backend}-watcher-pending-capture.json"
                    try:
                        watcher_child = subprocess.run(
                            [
                                sys.executable, str(Path(__file__).resolve()),
                                "--capture-backend", backend,
                                "--watcher-recovery-probe",
                                "--profile", str(watcher_profile),
                                "--output", str(output),
                                "--report", str(watcher_report_path),
                            ],
                            cwd=WINAPP_DIR.parent,
                            env=dict(
                                os.environ,
                                PHP_GALLERY_VISUAL_SEED_SHA256=watcher_seed_hash,
                            ),
                            capture_output=True, text=True,
                            timeout=VISUAL_CHILD_SECONDS, check=False,
                        )
                    except (OSError, subprocess.TimeoutExpired) as error:
                        child_failures.append(
                            f"{backend} pending-Watcher child failed to start or timed out: "
                            f"{error}"
                        )
                        continue
                    (output / f"{backend}-watcher-pending-child.stdout.log").write_text(
                        watcher_child.stdout, encoding="utf-8",
                    )
                    (output / f"{backend}-watcher-pending-child.stderr.log").write_text(
                        watcher_child.stderr, encoding="utf-8",
                    )
                    if not watcher_report_path.is_file():
                        child_failures.append(
                            f"{backend} pending-Watcher child exited "
                            f"{watcher_child.returncode} without its capture report."
                        )
                        continue
                    try:
                        watcher_report = json.loads(
                            watcher_report_path.read_text(encoding="utf-8"),
                        )
                    except (OSError, json.JSONDecodeError) as error:
                        child_failures.append(
                            f"{backend} pending-Watcher report could not be read: {error}"
                        )
                        continue
                    watcher_rows = watcher_report.get("captures", [])
                    if (
                        watcher_child.returncode != 0
                        or watcher_report.get("backend") != backend
                        or watcher_report.get("seed_profile_sha256") != watcher_seed_hash
                        or watcher_report.get("source_identity") != os.environ.get(
                            "ISSUE_445_SHA", os.environ.get("GITHUB_SHA", ""),
                        )
                        or watcher_report.get("watcher_recovery_target_type")
                        != "normalized-legacy-profile-binding"
                        or watcher_report.get("status") != "PASS"
                        or len(watcher_rows) != 1
                        or watcher_rows[0].get("workspace") != "watcher"
                        or watcher_rows[0].get("size_name") != "standard"
                    ):
                        child_failures.append(
                            f"{backend} pending-Watcher child did not produce its exact "
                            f"standard profile capture: {watcher_report}"
                        )
                        continue
                    watcher_row = watcher_rows[0]
                    watcher_row["fixture_profile_scope"] = (
                        "separate-pending-legacy-profile"
                    )
                    watcher_row["fixture_profile_sha256"] = watcher_seed_hash
                    watcher_pending_rows[backend] = watcher_row
            if child_failures:
                (output / f"{slice_id}-child-failures.json").write_text(
                    json.dumps({
                        "status": "FAIL",
                        "children_attempted": ["tk", "qt"],
                        "failures": child_failures,
                        "successful_reports": sorted(reports),
                    }, indent=2),
                    encoding="utf-8",
                )
            checks = _VisualAssertionCollector(self, child_failures)
            if include_watcher_probe:
                checks.set_group("independent pending-Watcher profile")
                checks.assertTrue(seed_hash != watcher_seed_hash)
            for backend in ("tk", "qt"):
                if backend not in reports:
                    continue
                report = reports[backend]
                checks.set_group(f"{backend} frontend evidence")
                checks.assertEqual(backend, report.get("backend"))
                checks.assertEqual(seed_hash, report.get("seed_profile_sha256"))
                checks.assertEqual(
                    report.get("source_identity"), os.environ.get(
                        "ISSUE_445_SHA", os.environ.get("GITHUB_SHA", ""),
                    ),
                )
                checks.assertEqual(list(VISUAL_WORKSPACES), report.get("workspaces"))
                checks.assertEqual(
                    list(VISUAL_SCENARIO_WORKSPACES),
                    report.get("scenario_workspaces"),
                )
                expected_identities = {
                    (name, workspace)
                    for name, _width, _height in VISUAL_SIZES
                    for workspace in VISUAL_WORKSPACES
                }
                expected_identities.update(
                    ("standard", workspace)
                    for workspace in VISUAL_SCENARIO_WORKSPACES
                )
                actual_identities = [
                    (row["size_name"], row["workspace"])
                    for row in report.get("captures", [])
                ]
                checks.assertEqual(len(actual_identities), len(set(actual_identities)))
                checks.assertLessEqual(set(actual_identities), expected_identities)
                if report.get("status") == "PASS":
                    checks.assertEqual(expected_identities, set(actual_identities))
                    checks.assertEqual(
                        len(VISUAL_SIZES) * len(VISUAL_WORKSPACES)
                        + len(VISUAL_SCENARIO_WORKSPACES),
                        len(report.get("captures", [])),
                    )
                else:
                    checks.assertTrue(report.get("capture_failures"), report)
                for row in report["captures"]:
                    checks.assertTrue((output / row["image"]).is_file(), row["image"])
                scenario_captures = [
                    row for row in report["captures"]
                    if row["workspace"] in VISUAL_SCENARIO_WORKSPACES
                ]
                scenario_fingerprints = [
                    row["image_pixel_sha256"] for row in scenario_captures
                ]
                checks.assertEqual(
                    len(scenario_fingerprints), len(set(scenario_fingerprints)),
                    f"{backend} Photos states did not produce distinct captures.",
                )
                standard_photos = next((
                    row for row in report["captures"]
                    if row["size_name"] == "standard" and row["workspace"] == "photos"
                ), None)
                if standard_photos is not None:
                    checks.assertNotIn(
                        standard_photos["image_pixel_sha256"], scenario_fingerprints,
                        f"{backend} scenario capture repeated the filled Photos state.",
                    )
                for size_name, _width, _height in report["size_scope"]:
                    size_rows = [
                        row for row in report["captures"]
                        if row["size_name"] == size_name
                    ]
                    workspace_rows = [
                        row for row in size_rows if row["workspace"] != "flight"
                    ]
                    fingerprints = [row["image_pixel_sha256"] for row in workspace_rows]
                    checks.assertEqual(
                        len(workspace_rows), len(set(fingerprints)),
                        f"{backend} {size_name} captures are not visually distinct: "
                        f"{[(row['workspace'], row['image_pixel_sha256']) for row in workspace_rows]}",
                    )
            checks.set_group("Tk/Qt host comparison")
            if "tk" in reports and "qt" in reports:
                checks.assertEqual(reports["tk"]["app_version"], reports["qt"]["app_version"])
                checks.assertEqual(
                    reports["tk"]["source_identity"], reports["qt"]["source_identity"],
                )
                checks.assertEqual(reports["tk"]["size_scope"], reports["qt"]["size_scope"])
                checks.assertEqual(reports["tk"]["fixture_scope"], reports["qt"]["fixture_scope"])
                tk_display = reports["tk"]["display"]
                qt_display = reports["qt"]["display"]
                checks.assertEqual(tk_display["platform"], qt_display["platform"])
                checks.assertEqual(tk_display["screen_size"], qt_display["screen_size"])
                checks.assertEqual(tk_display["dpi_units"], "Tk screen pixels per inch")
                checks.assertEqual(qt_display["dpi_units"], "Qt logical dots per inch")
            else:
                tk_display = reports.get("tk", {}).get("display", {})
                qt_display = reports.get("qt", {}).get("display", {})
            tk_captures = {
                (row["size_name"], row["workspace"]): row
                for row in reports.get("tk", {}).get("captures", [])
            }
            qt_captures = {
                (row["size_name"], row["workspace"]): row
                for row in reports.get("qt", {}).get("captures", [])
            }
            watcher_recovery_rows = {
                "tk": tk_captures.get(("standard", "watcher")),
                "qt": qt_captures.get(("standard", "watcher")),
            }
            checks.set_group("ordinary linked Watcher header")
            for backend, row in watcher_recovery_rows.items():
                if row is None:
                    continue
                checks.assertTrue(
                    row.get("header_link_visible"),
                    f"{backend} ordinary Watcher header is not visible: {row}",
                )
                checks.assertEqual(
                    VISUAL_PHOTOS_ISSUER.removeprefix("https://"),
                    row.get("visible_header_link_caption"), row,
                )
            for backend, captures in (("tk", tk_captures), ("qt", qt_captures)):
                pending_row = watcher_pending_rows.get(backend)
                if pending_row is not None:
                    captures[("standard", "watcher")] = pending_row
                if backend == "qt" and pending_row is not None:
                    checks.set_group("Qt retained-Watcher footer geometry")
                    footer = pending_row.get("watcher_retained_layout_bounds")
                    checks.assertTrue(isinstance(footer, dict), footer)
                    if isinstance(footer, dict):
                        surface = footer.get("surface")
                        valid_surface = (
                            isinstance(surface, list) and len(surface) == 4
                            and all(type(part) is int for part in surface)
                        )
                        checks.assertTrue(
                            valid_surface, footer,
                        )
                        if valid_surface:
                            checks.assertGreaterEqual(int(surface[0]), 0, footer)
                            checks.assertGreaterEqual(int(surface[1]), 0, footer)
                            checks.assertGreater(int(surface[2]), 0, footer)
                            checks.assertGreater(int(surface[3]), 0, footer)
                            container_size = footer.get("surface_container_size")
                            valid_container = (
                                isinstance(container_size, list)
                                and len(container_size) == 2
                                and all(type(part) is int for part in container_size)
                            )
                            checks.assertTrue(valid_container, footer)
                            checks.assertTrue(
                                footer.get("surface_parent_is_central"), footer,
                            )
                            if valid_container:
                                checks.assertLessEqual(
                                    int(surface[0]) + int(surface[2]),
                                    int(container_size[0]), footer,
                                )
                                checks.assertLessEqual(
                                    int(surface[1]) + int(surface[3]),
                                    int(container_size[1]), footer,
                                )
                        checks.assertTrue(footer.get("surface_shadow"), footer)
                        for target_name in ("continue_target", "close_target"):
                            target = footer.get(target_name)
                            valid_target = (
                                isinstance(target, list) and len(target) == 4
                                and all(type(part) is int for part in target)
                            )
                            checks.assertTrue(valid_target, (target_name, footer))
                            if valid_target and valid_surface:
                                x, y, width, height = (int(part) for part in target)
                                checks.assertGreater(width, 0, (target_name, footer))
                                checks.assertGreater(height, 0, (target_name, footer))
                                checks.assertGreaterEqual(x, 0, (target_name, footer))
                                checks.assertGreaterEqual(y, 0, (target_name, footer))
                                checks.assertLessEqual(
                                    x + width, int(surface[2]), (target_name, footer),
                                )
                                checks.assertLessEqual(
                                    y + height, int(surface[3]), (target_name, footer),
                                )
                        raw_rects = (
                            footer.get("status"), footer.get("details"),
                            footer.get("head"), footer.get("continue"),
                            footer.get("close"),
                        )
                        progress_bottom = footer.get("progress_bottom")
                        geometry_valid = all(
                            isinstance(rect, list) and len(rect) == 4
                            and all(type(part) is int for part in rect)
                            for rect in raw_rects
                        )
                        if geometry_valid and type(progress_bottom) is int:
                            status, details, head, continue_action, close_action = (
                                [int(part) for part in rect]
                                for rect in raw_rects
                            )
                            summary_top = min(status[1], details[1], head[1])
                            summary_bottom = max(
                                status[1] + status[3],
                                details[1] + details[3],
                                head[1] + head[3],
                            )
                            actions_top = min(
                                continue_action[1], close_action[1],
                            )
                            checks.assertLessEqual(status[1], details[1])
                            checks.assertLessEqual(details[1], head[1])
                            checks.assertLessEqual(summary_bottom, actions_top)
                            checks.assertLess(
                                actions_top - summary_bottom,
                                summary_top - progress_bottom,
                                "Retained summary must sit in the footer after the stretch.",
                            )
                        else:
                            checks.assertTrue(
                                False, "Qt retained-Watcher geometry is incomplete.",
                            )
            pairs = []
            for identity in sorted(set(tk_captures).intersection(qt_captures)):
                tk_row, qt_row = tk_captures[identity], qt_captures[identity]
                checks.set_group(f"pair {identity[0]}/{identity[1]}")
                checks.assertEqual(tk_row["requested_size"], qt_row["requested_size"])
                checks.assertEqual(
                    tk_row["requested_window_state"],
                    qt_row["requested_window_state"],
                )
                geometry_comparable = (
                    identity[0] != "maximized"
                    and identity[1] not in (
                        "photos-lightbox-fullscreen", "photos-context-menu",
                        "photos-connecting", "photos-music-panel",
                    )
                )
                if geometry_comparable:
                    checks.assertEqual(
                        tk_row["observed_client_size"], qt_row["observed_client_size"]
                    )
                    checks.assertEqual(tk_row["image_size"], qt_row["image_size"])
                    checks.assertEqual(
                        tk_row["capture_pixel_scale"], qt_row["capture_pixel_scale"]
                    )
                checks.assertTrue(tk_row["workspace_visible"], identity)
                checks.assertTrue(qt_row["workspace_visible"], identity)
                checks.assertEqual(tk_row["lightbox_visible"], qt_row["lightbox_visible"])
                checks.assertEqual(tk_row["fixture_gallery_id"], qt_row["fixture_gallery_id"])
                checks.assertEqual(tk_row.get("fixture_gallery_ids", []),
                                 qt_row.get("fixture_gallery_ids", []))
                checks.assertEqual(tk_row["fixture_photo_ids"], qt_row["fixture_photo_ids"])
                checks.assertEqual(tk_row["fixture_state"], qt_row["fixture_state"])
                if identity == ("standard", "watcher") and watcher_pending_rows:
                    checks.set_group("paired pending Watcher recovery from separate legacy seed")
                    checks.assertEqual(
                        "separate-pending-legacy-profile",
                        tk_row.get("fixture_profile_scope"),
                    )
                    checks.assertEqual(
                        tk_row.get("fixture_profile_scope"),
                        qt_row.get("fixture_profile_scope"),
                    )
                    checks.assertEqual(
                        tk_row.get("fixture_profile_sha256"),
                        qt_row.get("fixture_profile_sha256"),
                    )
                    checks.assertEqual("pending", tk_row.get("watcher_fixture_state"))
                    checks.assertEqual(
                        tk_row.get("watcher_fixture_state"),
                        qt_row.get("watcher_fixture_state"),
                    )
                    for row in (tk_row, qt_row):
                        checks.assertIn("reason=retained_queue_read", row["latest_activity_text"])
                        checks.assertIn("pending=1", row["latest_activity_text"])
                    checks.assertTrue(tk_row.get("watcher_action_captions"), tk_row)
                    checks.assertEqual(
                        tk_row.get("watcher_action_captions"),
                        qt_row.get("watcher_action_captions"),
                    )
                    checks.assertTrue(tk_row.get("watcher_continue_visible"), tk_row)
                    checks.assertTrue(tk_row.get("watcher_continue_enabled"), tk_row)
                    checks.assertTrue(qt_row.get("watcher_continue_visible"), qt_row)
                    checks.assertTrue(qt_row.get("watcher_continue_enabled"), qt_row)
                    checks.assertFalse(
                        tk_row.get("watcher_start_action_enabled"), tk_row,
                    )
                    checks.assertFalse(
                        qt_row.get("watcher_start_action_enabled"), qt_row,
                    )
                    checks.assertFalse(
                        tk_row.get("watcher_interrupt_action_enabled"), tk_row,
                    )
                    checks.assertFalse(
                        qt_row.get("watcher_interrupt_action_enabled"), qt_row,
                    )
                    checks.assertEqual(
                        64, len(str(tk_row.get("watcher_target_binding_sha256", ""))),
                    )
                    checks.assertEqual(
                        tk_row.get("watcher_target_binding_sha256"),
                        qt_row.get("watcher_target_binding_sha256"),
                    )
                    checks.assertEqual(
                        [VISUAL_SESSION_ID], tk_row.get("watcher_pending_session_ids"),
                    )
                    checks.assertEqual(
                        tk_row.get("watcher_pending_session_ids"),
                        qt_row.get("watcher_pending_session_ids"),
                    )
                    checks.assertEqual(
                        watcher_expected_item_ids,
                        tk_row.get("watcher_pending_item_ids"),
                    )
                    checks.assertEqual(
                        tk_row.get("watcher_pending_item_ids"),
                        qt_row.get("watcher_pending_item_ids"),
                    )
                    checks.assertTrue(tk_row.get("watcher_queue_unchanged"), tk_row)
                    checks.assertTrue(qt_row.get("watcher_queue_unchanged"), qt_row)
                    checks.assertEqual(
                        "read-only; no upload or network action invoked",
                        tk_row.get("watcher_probe_actions"),
                    )
                    checks.assertEqual(
                        tk_row.get("watcher_probe_actions"),
                        qt_row.get("watcher_probe_actions"),
                    )
                    checks.assertEqual("", tk_row.get("watcher_load_error"), tk_row)
                    checks.assertEqual(
                        "owned", tk_row.get("watcher_lease_owner_state"), tk_row,
                    )
                expected_tk_page = (
                    "watch" if identity[1] in ("watcher", "flight") else
                    "photos" if identity[1].startswith("photos-") else identity[1]
                )
                expected_qt_page = (
                    "watcher" if identity[1] == "flight" else
                    "photos" if identity[1].startswith("photos-") else identity[1]
                )
                checks.assertEqual(expected_tk_page, tk_row["selected_page"], identity)
                checks.assertEqual(expected_qt_page, qt_row["selected_page"], identity)
                if identity[1] in (
                    "photos", "photos-ratio-16-9", "photos-selected",
                    "photos-multiselected",
                ):
                    expected_ratio_captions = [
                        "Default" if ratio == "default" else ratio
                        for ratio in PHOTO_CARD_FORMATS
                    ]
                    checks.assertEqual(expected_ratio_captions, tk_row["ratio_captions"])
                    checks.assertEqual(tk_row["ratio_captions"], qt_row["ratio_captions"])
                    expected_ratio = (
                        "16:9" if identity[1] == "photos-ratio-16-9" else "default"
                    )
                    checks.assertEqual(expected_ratio, tk_row["selected_ratio"])
                    checks.assertEqual(expected_ratio, qt_row["selected_ratio"])
                if identity[1] in ("photos-selected", "photos-multiselected"):
                    checks.assertEqual(tk_row["selected_photo_ids"],
                                     qt_row["selected_photo_ids"])
                    expected_selection_count = (
                        1 if identity[1] == "photos-selected" else 2
                    )
                    checks.assertEqual(
                        expected_selection_count, len(tk_row["selected_photo_ids"]),
                    )
                if identity[1] in ("photos-lightbox", "photos-lightbox-fullscreen"):
                    checks.assertEqual(
                        tk_row["fixture_photo_ids"], qt_row["fixture_photo_ids"],
                    )
                    expected_open_photo = [tk_row["fixture_photo_ids"][0]]
                    checks.assertEqual(
                        expected_open_photo, tk_row["selected_photo_ids"],
                    )
                    checks.assertEqual(
                        expected_open_photo, qt_row["selected_photo_ids"],
                    )
                if identity[1] in VISUAL_WORKSPACES and identity[1] != "photos-lightbox":
                    for row in (tk_row, qt_row):
                        if row.get("fixture_profile_scope") == (
                            "separate-pending-legacy-profile"
                        ):
                            continue
                        checks.assertTrue(
                            row.get("header_link_visible"),
                            f"Linked header is not visibly rendered: {identity}, {row}",
                        )
                        checks.assertEqual(
                            VISUAL_PHOTOS_ISSUER.removeprefix("https://"),
                            row.get("visible_header_link_caption"),
                            identity,
                        )
                    checks.assertEqual(
                        tk_row.get("visible_header_link_caption"),
                        qt_row.get("visible_header_link_caption"),
                        identity,
                    )
                    if identity[0] == "narrow" and identity[1] in ("photos", "watcher"):
                        checks.assertEqual(
                            tk_row["header_link_inline"], qt_row["header_link_inline"],
                            (tk_row["header_brand_and_link_rows"],
                             qt_row["header_brand_and_link_rows"]),
                        )
                if identity[1] == "flight":
                    expected_supplement_count = (
                        2 if tk_row["size_name"] == "standard" else 1
                    )
                    for flight_row in (tk_row, qt_row):
                        supplement = flight_row["supplemental_images"]
                        checks.assertEqual(expected_supplement_count, len(supplement))
                        checks.assertEqual("flight-progress-overlay", supplement[0]["name"])
                        checks.assertTrue((output / supplement[0]["image"]).is_file())
                        checks.assertTrue(supplement[0]["paused_status"], supplement)
                        checks.assertTrue(supplement[0]["activity_visible"], supplement)
                    tk_panel = tk_row["supplemental_images"][0]
                    qt_panel = qt_row["supplemental_images"][0]
                    checks.assertEqual(tk_panel["phase"], qt_panel["phase"])
                    checks.assertEqual(tk_panel["paused_status"], qt_panel["paused_status"])
                    checks.assertEqual(False, qt_panel["activity_indeterminate"])
                    if tk_row["size_name"] == "standard":
                        tk_archive = tk_row["supplemental_images"][1]
                        qt_archive = qt_row["supplemental_images"][1]
                        for archive in (tk_archive, qt_archive):
                            checks.assertEqual("saved-flight-archive", archive["name"])
                            checks.assertTrue((output / archive["image"]).is_file())
                            checks.assertTrue(archive["visible"], archive)
                            checks.assertEqual(4, len(archive["desktop_bounds"]))
                            checks.assertTrue(
                                archive["desktop_bounds"][2] > 0
                                and archive["desktop_bounds"][3] > 0,
                                archive,
                            )
                            checks.assertTrue(
                                archive["image_size"][0] > 0
                                and archive["image_size"][1] > 0,
                                archive,
                            )
                            checks.assertEqual([VISUAL_SESSION_ID], archive["flight_ids"])
                            checks.assertEqual(["Synthetic aircraft"], archive["flight_labels"])
                            checks.assertEqual([VISUAL_SESSION_ID], archive["selected_flight_ids"])
                        checks.assertEqual(tk_archive["flight_ids"], qt_archive["flight_ids"])
                        checks.assertEqual(tk_archive["flight_labels"], qt_archive["flight_labels"])
                        for backend, archive in (("tk", tk_archive), ("qt", qt_archive)):
                            geometry = archive["action_geometry"]
                            local_key = (
                                "report_generate_only" if backend == "qt"
                                else "create_local_pdf"
                            )
                            required_archive_actions = [
                                tr("en", "report_flights_only"),
                                tr("en", "report_folder"),
                                tr("en", local_key),
                                tr("en", "report_create_upload"),
                            ]
                            if backend == "qt":
                                checks.assertIn(tr("en", "report_send_only"), geometry, archive)
                            for caption in (
                                gettext("Refresh"), gettext("Open saved PDF"),
                                tr("en", "cancel_pdf"), tr("en", "upload_saved_pdf"),
                            ):
                                checks.assertNotIn(caption, geometry, archive)
                            complete = all(caption in geometry for caption in required_archive_actions)
                            checks.assertTrue(complete, archive)
                            if complete:
                                tree_x, tree_y, tree_width, tree_height = archive["tree_geometry"]
                                only, folder, local, upload = (
                                    geometry[caption] for caption in required_archive_actions
                                )
                                checks.assertGreater(tree_width, 0, archive)
                                checks.assertGreater(tree_height, 0, archive)
                                checks.assertLess(only[0], folder[0])
                                checks.assertLessEqual(
                                    max(only[1] + only[3], folder[1] + folder[3]), tree_y,
                                )
                                checks.assertLessEqual(
                                    tree_y + tree_height, min(local[1], upload[1]),
                                )
                                checks.assertGreaterEqual(local[0], tree_x)
                                checks.assertLessEqual(upload[0] + upload[2], tree_x + tree_width)
                                if backend == "qt":
                                    sent = geometry.get(tr("en", "report_send_only"))
                                    if sent is not None:
                                        checks.assertLessEqual(local[0] + local[2], sent[0])
                                        checks.assertLessEqual(sent[0] + sent[2], upload[0])
                                else:
                                    checks.assertGreater(local[0], tree_x + tree_width // 2)
                                    checks.assertLess(local[0], upload[0])
                if identity[1] == "photos" and geometry_comparable:
                    tk_grid, qt_grid = tk_row["photo_grid"], qt_row["photo_grid"]
                    checks.assertEqual(
                        tk_grid["first_row_cards"], qt_grid["first_row_cards"],
                        f"Same-window Photos row wraps differently: Tk {tk_grid}, Qt {qt_grid}",
                    )
                    if sys.platform == "win32" and identity[0] == "wide":
                        checks.assertTrue(tk_grid["heading_stacked"] is False, tk_grid)
                        checks.assertEqual(tk_grid["heading_stacked"], qt_grid["heading_stacked"], qt_grid)
                if identity[1] == "photos-linked-empty":
                    checks.assertEqual(0, tk_row["visible_photo_count"], identity)
                    checks.assertEqual(0, qt_row["visible_photo_count"], identity)
                    for row in (tk_row, qt_row):
                        checks.assertEqual(tr('en', 'linking_media_empty'), row['empty_gallery_message'])
                        checks.assertFalse(row['photo_controls_visible'], identity)
                elif tk_row["fixture_gallery_id"] is not None:
                    checks.assertGreater(tk_row["visible_photo_count"], 0, identity)
                    checks.assertGreater(qt_row["visible_photo_count"], 0, identity)
                if tk_row['fixture_gallery_id'] is not None:
                    for row in (tk_row, qt_row):
                        checks.assertEqual(VISUAL_PHOTOS_ISSUER, row['header_link_address'], identity)
                if identity[1] == "photos-hierarchy":
                    checks.assertEqual(tk_row["gallery_hierarchy"], qt_row["gallery_hierarchy"])
                    checks.assertEqual(
                        [0, 1, 1],
                        [row["depth"] for row in tk_row["gallery_hierarchy"]],
                    )
                if identity[1] == "photos-folder-shelf":
                    checks.assertEqual(
                        [VISUAL_CHILD_GALLERY_ID, VISUAL_SECOND_CHILD_GALLERY_ID],
                        tk_row["folder_tree_child_ids"],
                    )
                    checks.assertEqual(
                        tk_row["folder_tree_child_ids"],
                        qt_row["folder_tree_child_ids"],
                    )
                    checks.assertEqual(tk_row["folder_cards"], qt_row["folder_cards"])
                    checks.assertEqual(
                        ["Synthetic child gallery", "Synthetic second child gallery"],
                        [card["title"] for card in tk_row["folder_cards"]],
                    )
                    for row in (tk_row, qt_row):
                        checks.assertEqual(2, len(row["folder_cards"]), row)
                        checks.assertTrue(row["folder_section_visible"], row)
                        checks.assertTrue(row["folder_controls_visible"], row)
                        checks.assertTrue(row["photo_controls_visible"], row)
                        checks.assertTrue(row["subgallery_enabled"], row)
                        checks.assertEqual("16:9", row["folder_selected_ratio"], row)
                        checks.assertEqual(
                            ["Default" if ratio == "default" else ratio
                             for ratio in PHOTO_CARD_FORMATS],
                            row["folder_ratio_captions"],
                            row,
                        )
                        strip_x, strip_y, strip_width, strip_height = row[
                            "folder_strip_geometry"
                        ]
                        checks.assertGreater(strip_width, 0, row)
                        checks.assertGreater(strip_height, 0, row)
                        checks.assertGreaterEqual(strip_x, 0, row)
                        checks.assertGreaterEqual(strip_y, 0, row)
                        ratio_layout = row["folder_ratio_layout"]
                        checks.assertEqual(len(PHOTO_CARD_FORMATS), len(ratio_layout), row)
                        checks.assertEqual(
                            [strip_width, strip_height],
                            ratio_layout[0]["strip_size"], row,
                        )
                        for control in ratio_layout:
                            control_x, control_y, control_width, control_height = (
                                control["control_geometry"]
                            )
                            icon_width, icon_height = control["icon_size"]
                            text_height = control["text_height"]
                            checks.assertTrue(control["caption"], control)
                            checks.assertTrue(control["icon_visible"], control)
                            checks.assertEqual(
                                control_height >= control["control_requested_height"]
                                and control_height >= icon_height + text_height,
                                control["content_fits_actual_height"], control,
                            )
                            checks.assertGreater(icon_width, 0, control)
                            checks.assertGreater(icon_height, 0, control)
                            checks.assertGreater(text_height, 0, control)
                            checks.assertGreaterEqual(
                                control_height, icon_height + text_height, control,
                            )
                            checks.assertGreaterEqual(
                                control_height,
                                control["control_requested_height"], control,
                            )
                            checks.assertTrue(control["control_fits_strip"], control)
                            checks.assertGreaterEqual(control_x, 0, control)
                            checks.assertGreaterEqual(control_y, 0, control)
                            checks.assertLessEqual(
                                control_x + control_width, strip_width, control,
                            )
                            checks.assertLessEqual(
                                control_y + control_height, strip_height, control,
                            )
                            checks.assertGreaterEqual(
                                control["control_device_height_px"],
                                control["icon_device_height_px"]
                                + control["text_device_height_px"],
                                control,
                            )
                            if "compound" in control:
                                checks.assertEqual("top", control["compound"], control)
                            else:
                                checks.assertEqual(
                                    int(Qt.ToolButtonStyle.ToolButtonTextUnderIcon.value),
                                    control["tool_button_style"], control,
                                )
                        checks.assertTrue(
                            all(card["cover_visible"] for card in row["folder_cards"]),
                            row,
                        )
                if identity[1] == "photos-lightbox-fullscreen":
                    checks.assertTrue(tk_row["fullscreen"])
                    checks.assertTrue(qt_row["fullscreen"])
                    checks.assertEqual(tk_row["fixture_photo_ids"], qt_row["fixture_photo_ids"])
                    for row in (tk_row, qt_row):
                        checks.assertTrue(row["lightbox_idle_chrome_hidden"], row["actions"])
                        checks.assertTrue(row["lightbox_chrome_restored_by_motion"])
                        active = next((
                            extra for extra in row["supplemental_images"]
                            if extra["name"] == "fullscreen-active-controls"
                        ), None)
                        checks.assertTrue(active is not None, 'Missing fullscreen controls capture.')
                        if active is not None:
                            checks.assertTrue((output / active["image"]).is_file())
                            checks.assertEqual(row["image_size"], active["image_size"])
                if identity[1] == "photos-info":
                    checks.assertEqual(tk_row["selected_photo_ids"],
                                     qt_row["selected_photo_ids"])
                    checks.assertTrue(tk_row["photo_info_visible"])
                    checks.assertTrue(qt_row["photo_info_visible"])
                    for row in (tk_row, qt_row):
                        checks.assertEqual(4, len(row["photo_info_geometry"]), row)
                        info_x, info_y, info_width, info_height = row[
                            "photo_info_geometry"
                        ]
                        checks.assertGreater(info_width, 0, row)
                        checks.assertGreater(info_height, 0, row)
                        checks.assertGreaterEqual(info_x, 0, row)
                        checks.assertGreaterEqual(info_y, 0, row)
                        checks.assertLessEqual(
                            info_x + info_width,
                            row["observed_client_size"][0], row,
                        )
                        checks.assertLessEqual(
                            info_y + info_height,
                            row["observed_client_size"][1], row,
                        )
                    checks.assertIn("Visual fixture", tk_row["photo_info_text"])
                    checks.assertIn("Visual fixture", qt_row["photo_info_text"])
                    size_label = tr('en', 'photos_workspace_field_size')
                    size_prefix = size_label + ': '
                    tk_sizes = tuple(
                        line for line in tk_row['photo_info_text'].splitlines()
                        if line.startswith(size_prefix)
                    )
                    # The native Qt document emits each table cell on its own line.
                    qt_lines = qt_row['photo_info_text'].splitlines()
                    qt_sizes = tuple(
                        size_prefix + value for label, value in zip(qt_lines, qt_lines[1:])
                        if label == size_label
                    )
                    checks.assertTrue(tk_sizes, 'The reference must expose its actual byte size.')
                    checks.assertEqual(tk_sizes, qt_sizes, identity)
                    checks.assertNotIn(size_prefix, qt_row['photo_info_summary'])
                if identity[1] == "photos-context-menu":
                    checks.assertIn(tk_row["photo_info_action"],
                                  [action["caption"] for action in tk_row["actions"]])
                    checks.assertIn(qt_row["photo_info_action"],
                                  [action["caption"] for action in qt_row["actions"]])
                    qt_context_region = next(
                        region for region in qt_row["regions"]
                        if region["name"] == "photosPhotoContext"
                    )
                    context_x, context_y, context_width, context_height = (
                        qt_context_region["geometry"]
                    )
                    checks.assertGreaterEqual(context_x, 0, qt_context_region)
                    checks.assertGreaterEqual(context_y, 0, qt_context_region)
                    checks.assertGreater(context_width, 0, qt_context_region)
                    checks.assertGreater(context_height, 0, qt_context_region)
                    checks.assertEqual(
                        [context_width, context_height],
                        qt_row["observed_client_size"],
                    )
                    checks.assertFalse(geometry_comparable)
                if identity[1] == "photos-info-unavailable":
                    checks.assertTrue(tk_row["metadata_failure_visible"])
                    checks.assertTrue(qt_row["metadata_failure_visible"])
                    checks.assertIn("Visual fixture", tk_row["photo_info_text"])
                    checks.assertIn("Visual fixture", qt_row["photo_info_text"])
                    checks.assertIn("kind=photo_info", qt_row["latest_activity_text"])
                    for row in (tk_row, qt_row):
                        checks.assertTrue(row["photo_info_visible"], row)
                        checks.assertGreater(row["photo_info_geometry"][2], 0, row)
                        checks.assertGreater(row["photo_info_geometry"][3], 0, row)
                if identity[1] == "photos-disconnected":
                    for row in (tk_row, qt_row):
                        checks.assertEqual(
                            ["Connect"], row["workspace_action_captions"], row["actions"],
                        )
                    checks.assertEqual(
                        bool(tk_row["tagline_visible"]), qt_row["tagline_visible"],
                        (tk_row["header_reflow"], qt_row["header_reflow"]),
                    )
                if identity == ("maximized", "watcher"):
                    if tk_display["platform"] == "darwin":
                        checks.assertFalse(tk_row["tagline_visible"])
                        checks.assertFalse(qt_row["tagline_visible"])
                    else:
                        checks.assertEqual(
                            tk_row["tagline_visible"], qt_row["tagline_visible"],
                            (tk_row.get("header_reflow"), qt_row.get("header_reflow")),
                        )
                if identity[1] == "photos-delete-confirmation":
                    for row in (tk_row, qt_row):
                        checks.assertTrue(row["confirmation_visible"], row)
                        checks.assertTrue(row["confirmation_cancelled"], row)
                        checks.assertIn("Synthetic visual gallery", row["confirmation_text"])
                        checks.assertIn(
                            row["confirmation_cancel_caption"],
                            [action["caption"] for action in row["actions"]],
                        )
                        checks.assertEqual(0, row["write_count"], row)
                        region = next(region for region in row["regions"]
                                      if region["name"] == "photos-delete-confirmation")
                        x, y, width, height = region["geometry"]
                        canvas_width, canvas_height = row["observed_client_size"]
                        checks.assertGreater(x, 0, region)
                        checks.assertGreater(y, 0, region)
                        checks.assertGreater(width, 0, region)
                        checks.assertGreater(height, 0, region)
                        checks.assertLess(x + width, canvas_width)
                        checks.assertLess(y + height, canvas_height)
                    checks.assertEqual(tk_row["confirmation_text"], qt_row["confirmation_text"])
                    checks.assertEqual(
                        [action["caption"] for action in tk_row["confirmation_actions"]],
                        [action["caption"] for action in qt_row["confirmation_actions"]],
                    )
                    checks.assertTrue(qt_row["confirmation_safety_focus"])
                if identity[1] == "photos-connecting":
                    checks.assertTrue(tk_row["pending_auth_state"])
                    checks.assertTrue(qt_row["pending_auth_state"])
                    checks.assertTrue(tk_row["pending_status"])
                    checks.assertTrue(qt_row["pending_status"])
                    checks.assertTrue(tk_row['connect_action_enabled'])
                    checks.assertTrue(qt_row['connect_action_enabled'])
                    checks.assertFalse(qt_row['progress_visible'])
                    checks.assertTrue(qt_row["pending_request_status"])
                    checks.assertTrue(qt_row["pending_approval_status"])
                    checks.assertTrue(
                        qt_row["pending_request_status"] != qt_row["pending_approval_status"],
                        "Qt must show distinct request and browser approval messages.",
                    )
                    checks.assertTrue(qt_row["cancel_action_caption"])
                    checks.assertTrue(qt_row["invalid_address_rejected"])
                    checks.assertTrue(qt_row["cancel_action_invoked"])
                    checks.assertTrue(qt_row["callback_wait_entered"])
                    checks.assertTrue(qt_row["callback_wait_cancelled"])
                    checks.assertTrue(qt_row["cancelled_state"])
                    checks.assertTrue(qt_row["cancelled_status"])
                    checks.assertEqual([VISUAL_PHOTOS_ISSUER], tk_row["recent_addresses"])
                    checks.assertEqual(
                        tk_row["recent_addresses"], qt_row["recent_addresses"],
                    )
                    checks.assertTrue(tk_row["recent_addresses_visible"], tk_row)
                    checks.assertTrue(qt_row["recent_addresses_visible"], qt_row)
                    for backend, row in (("tk", tk_row), ("qt", qt_row)):
                        dialog_name = (
                            "photos-connecting-dialog" if backend == "tk"
                            else "photosAuthorizationDialog"
                        )
                        dialog_region = next(
                            region for region in row["regions"]
                            if region["name"] == dialog_name
                        )
                        dialog_x, dialog_y, dialog_width, dialog_height = (
                            dialog_region["geometry"]
                        )
                        checks.assertEqual([0, 0], [dialog_x, dialog_y], row)
                        checks.assertGreater(dialog_width, 0, row)
                        checks.assertGreater(dialog_height, 0, row)
                        cancel_action = next(
                            action for action in row["actions"]
                            if action["caption"] == gettext("Cancel")
                        )
                        action_x, action_y, action_width, action_height = (
                            cancel_action["geometry"]
                        )
                        checks.assertGreater(action_width, 0, row)
                        checks.assertGreater(action_height, 0, row)
                        checks.assertGreaterEqual(action_x, 0, row)
                        checks.assertGreaterEqual(action_y, 0, row)
                        checks.assertLessEqual(action_x + action_width, dialog_width, row)
                        checks.assertLessEqual(action_y + action_height, dialog_height, row)
                        checks.assertIn(
                            tr('en', 'linking_connect_again'),
                            [action['caption'] for action in row['actions']],
                        )
                    address_region = next(
                        region for region in qt_row["regions"]
                        if region["name"] == "photosAuthorizationAddress"
                    )
                    checks.assertGreater(address_region["geometry"][2], 0, qt_row)
                    checks.assertGreater(address_region["geometry"][3], 0, qt_row)
                if identity[1] == "photos-music-panel":
                    for row in (tk_row, qt_row):
                        checks.assertTrue(row["music_panel_visible"], row)
                        checks.assertIn("No gallery music is available.",
                                      row["music_panel_text"])
                        checks.assertEqual(1, len(row["supplemental_images"]), row)
                        checks.assertEqual("music-panel",
                                         row["supplemental_images"][0]["name"])
                        checks.assertTrue(
                            (output / row["supplemental_images"][0]["image"]).is_file(),
                            row,
                        )
                if identity[1] == "activity":
                    checks.assertEqual(
                        tk_row["latest_activity_text"],
                        qt_row["latest_activity_text"],
                        "The persisted Activity row must not replace either startup footer.",
                    )
                    checks.assertEqual(
                        tk_row["activity_import_summary"],
                        qt_row["activity_import_summary"],
                    )
                    checks.assertEqual(
                        tk_row["activity_selected_job"],
                        qt_row["activity_selected_job"],
                    )
                    checks.assertEqual(
                        tk_row["activity_history_row_count"],
                        qt_row["activity_history_row_count"],
                    )
                    checks.assertEqual(
                        tk_row["activity_event_row_count"],
                        qt_row["activity_event_row_count"],
                    )
                if identity[1] == "photos-disconnected":
                    for row in (tk_row, qt_row):
                        x, y, width, height = row["connect_card_geometry"]
                        page_width, page_height = row["connect_card_workspace_size"]
                        checks.assertGreater(width, 0, row)
                        checks.assertGreater(height, 0, row)
                        checks.assertGreaterEqual(x, 0, row)
                        checks.assertGreaterEqual(y, 0, row)
                        checks.assertLessEqual(x + width, page_width, row)
                        checks.assertLessEqual(y + height, page_height, row)
                        checks.assertLess(
                            width * height,
                            page_width * page_height * VISUAL_CONNECT_CARD_MAX_AREA_RATIO,
                            f"Disconnected connect card is not compact: {row}",
                        )
                if identity[0] == "maximized":
                    for row in (tk_row, qt_row):
                        checks.assertIn(
                            row["actual_window_state"],
                            ("maximized", "screen-sized-fallback"),
                        )
                        observed = row["observed_client_size"]
                        baseline = row.get("max_reference_client_size")
                        checks.assertTrue(
                            isinstance(baseline, list) and len(baseline) == 2,
                            f"Missing immediately preceding window geometry: {row}",
                        )
                        if not isinstance(baseline, list) or len(baseline) != 2:
                            continue
                        checks.assertGreaterEqual(
                            observed[0] * observed[1],
                            baseline[0] * baseline[1] * VISUAL_MAX_AREA_RATIO,
                            f"Maximized viewport did not expand: {row}",
                        )
                        checks.assertGreaterEqual(
                            observed[1], baseline[1] * VISUAL_MAX_HEIGHT_RATIO,
                            f"Maximized viewport height did not expand: {row}",
                        )
                pairs.append({
                    "size_name": identity[0], "workspace": identity[1],
                    "tk_image": tk_row["image"], "qt_image": qt_row["image"],
                    "tk_image_pixel_sha256": tk_row["image_pixel_sha256"],
                    "qt_image_pixel_sha256": qt_row["image_pixel_sha256"],
                    "tk_selected_page": tk_row["selected_page"],
                    "qt_selected_page": qt_row["selected_page"],
                    "tk_fixture_state": tk_row["fixture_state"],
                    "qt_fixture_state": qt_row["fixture_state"],
                    "tk_gallery_hierarchy": tk_row.get("gallery_hierarchy", []),
                    "qt_gallery_hierarchy": qt_row.get("gallery_hierarchy", []),
                    "tk_ratio_captions": tk_row["ratio_captions"],
                    "qt_ratio_captions": qt_row["ratio_captions"],
                    "tk_selected_ratio": tk_row["selected_ratio"],
                    "qt_selected_ratio": qt_row["selected_ratio"],
                    "tk_selected_photo_ids": tk_row.get("selected_photo_ids", []),
                    "qt_selected_photo_ids": qt_row.get("selected_photo_ids", []),
                    "tk_fullscreen": tk_row.get("fullscreen", False),
                    "qt_fullscreen": qt_row.get("fullscreen", False),
                    "tk_lightbox_idle_chrome_hidden": tk_row.get("lightbox_idle_chrome_hidden"),
                    "qt_lightbox_idle_chrome_hidden": qt_row.get("lightbox_idle_chrome_hidden"),
                    "tk_lightbox_controls_restored": tk_row.get("lightbox_chrome_restored_by_motion"),
                    "qt_lightbox_controls_restored": qt_row.get("lightbox_chrome_restored_by_motion"),
                    "tk_photo_grid": tk_row.get("photo_grid"),
                    "qt_photo_grid": qt_row.get("photo_grid"),
                    "tk_photo_info_visible": tk_row.get("photo_info_visible", False),
                    "qt_photo_info_visible": qt_row.get("photo_info_visible", False),
                    "tk_photo_info_text": tk_row.get("photo_info_text", ""),
                    "qt_photo_info_text": qt_row.get("photo_info_text", ""),
                    "tk_photo_info_geometry": tk_row.get("photo_info_geometry", []),
                    "qt_photo_info_geometry": qt_row.get("photo_info_geometry", []),
                    "tk_photo_info_summary": tk_row.get("photo_info_summary", ""),
                    "qt_photo_info_summary": qt_row.get("photo_info_summary", ""),
                    "tk_metadata_failure_visible": tk_row.get("metadata_failure_visible", False),
                    "qt_metadata_failure_visible": qt_row.get("metadata_failure_visible", False),
                    "tk_confirmation_visible": tk_row.get("confirmation_visible", False),
                    "qt_confirmation_visible": qt_row.get("confirmation_visible", False),
                    "tk_confirmation_cancelled": tk_row.get("confirmation_cancelled", False),
                    "qt_confirmation_cancelled": qt_row.get("confirmation_cancelled", False),
                    "tk_confirmation_text": tk_row.get("confirmation_text", ""),
                    "qt_confirmation_text": qt_row.get("confirmation_text", ""),
                    "tk_folder_cards": tk_row.get("folder_cards", []),
                    "qt_folder_cards": qt_row.get("folder_cards", []),
                    "tk_folder_tree_child_ids": tk_row.get("folder_tree_child_ids", []),
                    "qt_folder_tree_child_ids": qt_row.get("folder_tree_child_ids", []),
                    "tk_folder_selected_ratio": tk_row.get("folder_selected_ratio", ""),
                    "qt_folder_selected_ratio": qt_row.get("folder_selected_ratio", ""),
                    "tk_folder_ratio_captions": tk_row.get("folder_ratio_captions", []),
                    "qt_folder_ratio_captions": qt_row.get("folder_ratio_captions", []),
                    "tk_folder_ratio_layout": tk_row.get("folder_ratio_layout", []),
                    "qt_folder_ratio_layout": qt_row.get("folder_ratio_layout", []),
                    "tk_folder_strip_geometry": tk_row.get("folder_strip_geometry", []),
                    "qt_folder_strip_geometry": qt_row.get("folder_strip_geometry", []),
                    "tk_folder_section_visible": tk_row.get("folder_section_visible", False),
                    "qt_folder_section_visible": qt_row.get("folder_section_visible", False),
                    "tk_empty_gallery_message": tk_row.get("empty_gallery_message", ""),
                    "qt_empty_gallery_message": qt_row.get("empty_gallery_message", ""),
                    "tk_music_panel_visible": tk_row.get("music_panel_visible", False),
                    "qt_music_panel_visible": qt_row.get("music_panel_visible", False),
                    "tk_music_panel_text": tk_row.get("music_panel_text", ""),
                    "qt_music_panel_text": qt_row.get("music_panel_text", ""),
                    "tk_music_panel_geometry": tk_row.get("music_panel_geometry"),
                    "qt_music_panel_geometry": qt_row.get("music_panel_geometry"),
                    "tk_pending_auth_state": tk_row.get("pending_auth_state", False),
                    "qt_pending_auth_state": qt_row.get("pending_auth_state", False),
                    "tk_pending_status": tk_row.get("pending_status", ""),
                    "qt_pending_status": qt_row.get("pending_status", ""),
                    "qt_pending_request_status": qt_row.get(
                        "pending_request_status", "",
                    ),
                    "qt_pending_approval_status": qt_row.get(
                        "pending_approval_status", "",
                    ),
                    "qt_cancel_action_caption": qt_row.get(
                        "cancel_action_caption", "",
                    ),
                    "qt_cancel_action_invoked": qt_row.get(
                        "cancel_action_invoked", False,
                    ),
                    "qt_callback_wait_entered": qt_row.get(
                        "callback_wait_entered", False,
                    ),
                    "qt_callback_wait_cancelled": qt_row.get(
                        "callback_wait_cancelled", False,
                    ),
                    "qt_cancelled_state": qt_row.get("cancelled_state", False),
                    "qt_cancelled_status": qt_row.get("cancelled_status", ""),
                    "tk_connect_card_geometry": tk_row.get("connect_card_geometry"),
                    "qt_connect_card_geometry": qt_row.get("connect_card_geometry"),
                    "tk_connect_card_workspace_size": tk_row.get("connect_card_workspace_size"),
                    "qt_connect_card_workspace_size": qt_row.get("connect_card_workspace_size"),
                    "tk_workspace_action_captions": tk_row.get("workspace_action_captions", []),
                    "qt_workspace_action_captions": qt_row.get("workspace_action_captions", []),
                    "tk_header_reflow": tk_row.get("header_reflow"),
                    "qt_header_reflow": qt_row.get("header_reflow"),
                    "tk_latest_activity_text": tk_row.get("latest_activity_text", ""),
                    "qt_latest_activity_text": qt_row.get("latest_activity_text", ""),
                    "tk_header_link_address": tk_row.get("header_link_address", ""),
                    "qt_header_link_address": qt_row.get("header_link_address", ""),
                    "header_link_state_differs": (
                        tk_row.get("header_link_address", "")
                        != qt_row.get("header_link_address", "")
                    ),
                    "latest_activity_text_differs": (
                        tk_row.get("latest_activity_text", "")
                        != qt_row.get("latest_activity_text", "")
                    ),
                    "tk_watcher_fixture_state": tk_row.get("watcher_fixture_state"),
                    "qt_watcher_fixture_state": qt_row.get("watcher_fixture_state"),
                    "tk_watcher_probe_profile_scope": tk_row.get(
                        "fixture_profile_scope", "shared-full-profile",
                    ),
                    "qt_watcher_probe_profile_scope": qt_row.get(
                        "fixture_profile_scope", "shared-full-profile",
                    ),
                    "tk_watcher_probe_profile_sha256": tk_row.get(
                        "fixture_profile_sha256", seed_hash,
                    ),
                    "qt_watcher_probe_profile_sha256": qt_row.get(
                        "fixture_profile_sha256", seed_hash,
                    ),
                    "tk_watcher_continue_visible": tk_row.get(
                        "watcher_continue_visible", False,
                    ),
                    "qt_watcher_continue_visible": qt_row.get(
                        "watcher_continue_visible", False,
                    ),
                    "tk_watcher_continue_enabled": tk_row.get(
                        "watcher_continue_enabled", False,
                    ),
                    "qt_watcher_continue_enabled": qt_row.get(
                        "watcher_continue_enabled", False,
                    ),
                    "tk_watcher_start_action_enabled": tk_row.get(
                        "watcher_start_action_enabled", False,
                    ),
                    "qt_watcher_start_action_enabled": qt_row.get(
                        "watcher_start_action_enabled", False,
                    ),
                    "tk_watcher_interrupt_action_enabled": tk_row.get(
                        "watcher_interrupt_action_enabled", False,
                    ),
                    "qt_watcher_interrupt_action_enabled": qt_row.get(
                        "watcher_interrupt_action_enabled", False,
                    ),
                    "tk_watcher_pending_session_ids": tk_row.get(
                        "watcher_pending_session_ids", [],
                    ),
                    "qt_watcher_pending_session_ids": qt_row.get(
                        "watcher_pending_session_ids", [],
                    ),
                    "tk_watcher_target_binding_sha256": tk_row.get(
                        "watcher_target_binding_sha256", "",
                    ),
                    "qt_watcher_target_binding_sha256": qt_row.get(
                        "watcher_target_binding_sha256", "",
                    ),
                    "tk_watcher_pending_item_ids": tk_row.get(
                        "watcher_pending_item_ids", [],
                    ),
                    "qt_watcher_pending_item_ids": qt_row.get(
                        "watcher_pending_item_ids", [],
                    ),
                    "tk_watcher_queue_unchanged": tk_row.get(
                        "watcher_queue_unchanged", False,
                    ),
                    "qt_watcher_queue_unchanged": qt_row.get(
                        "watcher_queue_unchanged", False,
                    ),
                    "tk_watcher_probe_actions": tk_row.get(
                        "watcher_probe_actions", "",
                    ),
                    "qt_watcher_probe_actions": qt_row.get(
                        "watcher_probe_actions", "",
                    ),
                    "tk_watcher_load_error": tk_row.get("watcher_load_error", ""),
                    "tk_watcher_lease_owner_state": tk_row.get(
                        "watcher_lease_owner_state", "",
                    ),
                    "tk_watcher_action_captions": tk_row.get(
                        "watcher_action_captions", [],
                    ),
                    "qt_watcher_action_captions": qt_row.get(
                        "watcher_action_captions", [],
                    ),
                    "watcher_state_differs": identity[1] == "watcher" and (
                        tk_row.get("watcher_fixture_state")
                        != qt_row.get("watcher_fixture_state")
                        or tk_row.get("watcher_action_captions", [])
                        != qt_row.get("watcher_action_captions", [])
                    ),
                    "tk_recovery_watcher_image": (
                        watcher_recovery_rows["tk"].get("image", "")
                        if identity[1] == "watcher"
                        and watcher_recovery_rows.get("tk") is not None else ""
                    ),
                    "qt_recovery_watcher_image": (
                        watcher_recovery_rows["qt"].get("image", "")
                        if identity[1] == "watcher"
                        and watcher_recovery_rows.get("qt") is not None else ""
                    ),
                    "tk_recovery_watcher_fixture_state": (
                        watcher_recovery_rows["tk"].get("watcher_fixture_state")
                        if identity[1] == "watcher"
                        and watcher_recovery_rows.get("tk") is not None else None
                    ),
                    "qt_recovery_watcher_fixture_state": (
                        watcher_recovery_rows["qt"].get("watcher_fixture_state")
                        if identity[1] == "watcher"
                        and watcher_recovery_rows.get("qt") is not None else None
                    ),
                    "tk_recovery_watcher_action_captions": (
                        watcher_recovery_rows["tk"].get("watcher_action_captions", [])
                        if identity[1] == "watcher"
                        and watcher_recovery_rows.get("tk") is not None else []
                    ),
                    "qt_recovery_watcher_action_captions": (
                        watcher_recovery_rows["qt"].get("watcher_action_captions", [])
                        if identity[1] == "watcher"
                        and watcher_recovery_rows.get("qt") is not None else []
                    ),
                    "tk_recovery_watcher_resume_available": (
                        watcher_recovery_rows["tk"].get("watcher_resume_available")
                        if identity[1] == "watcher"
                        and watcher_recovery_rows.get("tk") is not None else None
                    ),
                    "qt_recovery_watcher_resume_available": (
                        watcher_recovery_rows["qt"].get("watcher_resume_available")
                        if identity[1] == "watcher"
                        and watcher_recovery_rows.get("qt") is not None else None
                    ),
                    "tk_recovery_watcher_load_error": (
                        watcher_recovery_rows["tk"].get("watcher_load_error", "")
                        if identity[1] == "watcher"
                        and watcher_recovery_rows.get("tk") is not None else ""
                    ),
                    "tk_recovery_watcher_lease_owner_state": (
                        watcher_recovery_rows["tk"].get(
                            "watcher_lease_owner_state", "",
                        ) if identity[1] == "watcher"
                        and watcher_recovery_rows.get("tk") is not None else ""
                    ),
                    "watcher_recovery_state_differs": identity[1] == "watcher" and (
                        (watcher_recovery_rows.get("tk") or {}).get(
                            "watcher_fixture_state",
                        ) != (watcher_recovery_rows.get("qt") or {}).get(
                            "watcher_fixture_state",
                        )
                        or (watcher_recovery_rows.get("tk") or {}).get(
                            "watcher_action_captions", [],
                        ) != (watcher_recovery_rows.get("qt") or {}).get(
                            "watcher_action_captions", [],
                        )
                        or (watcher_recovery_rows.get("tk") or {}).get(
                            "watcher_resume_available",
                        ) != (watcher_recovery_rows.get("qt") or {}).get(
                            "watcher_resume_available",
                        )
                    ),
                    "tk_activity_import_summary": tk_row.get(
                        "activity_import_summary", "",
                    ),
                    "qt_activity_import_summary": qt_row.get(
                        "activity_import_summary", "",
                    ),
                    "tk_activity_selected_job": tk_row.get("activity_selected_job"),
                    "qt_activity_selected_job": qt_row.get("activity_selected_job"),
                    "tk_activity_history_row_count": tk_row.get(
                        "activity_history_row_count",
                    ),
                    "qt_activity_history_row_count": qt_row.get(
                        "activity_history_row_count",
                    ),
                    "tk_activity_event_row_count": tk_row.get(
                        "activity_event_row_count",
                    ),
                    "qt_activity_event_row_count": qt_row.get(
                        "activity_event_row_count",
                    ),
                    "requested_size": tk_row["requested_size"],
                    "requested_window_state": tk_row["requested_window_state"],
                    "tk_actual_window_state": tk_row["actual_window_state"],
                    "qt_actual_window_state": qt_row["actual_window_state"],
                    "tk_max_reference_client_size": tk_row.get(
                        "max_reference_client_size",
                    ),
                    "qt_max_reference_client_size": qt_row.get(
                        "max_reference_client_size",
                    ),
                    "geometry_comparable": geometry_comparable,
                    "tk_observed_client_size": tk_row["observed_client_size"],
                    "qt_observed_client_size": qt_row["observed_client_size"],
                    "tk_image_size": tk_row["image_size"],
                    "qt_image_size": qt_row["image_size"],
                    "tk_actions": tk_row["actions"], "qt_actions": qt_row["actions"],
                    "tk_regions": tk_row["regions"], "qt_regions": qt_row["regions"],
                    "tk_supplemental_images": tk_row.get("supplemental_images", []),
                    "qt_supplemental_images": qt_row.get("supplemental_images", []),
                    "capture_pixel_scale": tk_row["capture_pixel_scale"],
                    "tk_capture_pixel_scale": tk_row["capture_pixel_scale"],
                    "qt_capture_pixel_scale": qt_row["capture_pixel_scale"],
                    "tk_native_dpi": tk_display.get("dpi"),
                    "tk_scaling_pixels_per_point": (
                        tk_display.get("tk_scaling_pixels_per_point")
                    ),
                    "qt_logical_dpi": qt_display.get("dpi"),
                    "qt_device_pixel_ratio": qt_display.get(
                        "device_pixel_ratio",
                    ),
                })
            contact_sheet = (
                make_visual_contact_sheet(output, pairs, slice_id) if pairs else ""
            )
            if contact_sheet:
                checks.assertTrue((output / contact_sheet).is_file(), contact_sheet)
            expected_pair_ids = {
                (name, workspace)
                for name, _width, _height in VISUAL_SIZES
                for workspace in VISUAL_WORKSPACES
            }
            expected_pair_ids.update(
                ("standard", workspace) for workspace in VISUAL_SCENARIO_WORKSPACES
            )
            paired_ids = {
                (row["size_name"], row["workspace"]) for row in pairs
            }
            missing_pair_ids = sorted(expected_pair_ids - paired_ids)
            if missing_pair_ids:
                child_failures.append(
                    "Missing paired native captures: "
                    + ", ".join(f"{size}/{workspace}" for size, workspace in missing_pair_ids)
                )
            if child_failures:
                (output / f"{slice_id}-child-failures.json").write_text(
                    json.dumps({
                        "status": "FAIL",
                        "children_attempted": ["tk", "qt"],
                        "failures": child_failures,
                        "successful_reports": sorted(reports),
                    }, indent=2),
                    encoding="utf-8",
                )
            expected_case_ids = [list(identity) for identity in sorted(expected_pair_ids)]
            completed_case_ids = [
                [row["size_name"], row["workspace"]] for row in pairs
            ]
            slice_report_path = output / f"paired-captures-{slice_id}.json"
            slice_report_path.write_text(
                json.dumps({
                    "schema_version": 1,
                    "slice_id": slice_id,
                    "status": "FAIL" if child_failures else "PASS",
                    "failures": child_failures,
                    "missing_identities": [list(identity) for identity in missing_pair_ids],
                    "expected_case_ids": expected_case_ids,
                    "completed_case_ids": completed_case_ids,
                    "successful_report_backends": sorted(reports),
                    "contact_sheet": contact_sheet,
                    "seed_profile_sha256": seed_hash,
                    "source_identity": reports.get("tk", {}).get("source_identity", ""),
                    "display": {"tk": tk_display, "qt": qt_display},
                    "workspace_scope": list(VISUAL_WORKSPACES),
                    "scenario_workspace_scope": list(VISUAL_SCENARIO_WORKSPACES),
                    "scenario_coverage": [
                        {
                            "workspace": "photos-disconnected",
                            "state": "fresh-disconnected-profile",
                            "proof": "selected Photos page and visible connect card",
                        },
                        {
                            "workspace": "photos",
                            "state": "linked-filled-gallery",
                            "proof": "same synthetic gallery and photo IDs plus ordered ratio captions",
                        },
                        {
                            "workspace": "photos-ratio-16-9",
                            "state": "selected-16:9-card-format",
                            "proof": "actual paired toolbar selection with the complete ordered captions",
                        },
                        {
                            "workspace": "photos-multiselected",
                            "state": "two-selected-photos",
                            "proof": "same selected photo IDs retained in the visible grid",
                        },
                        {
                            "workspace": "photos-selected",
                            "state": "single-selected-photo",
                            "proof": "same selected photo ID highlighted in each visible grid",
                        },
                        {
                            "workspace": "photos-linked-empty",
                            "state": "linked-empty-catalogue",
                            "proof": "linked tree mounted with zero visible photo rows",
                        },
                        {
                            "workspace": "photos-hierarchy",
                            "state": "expanded-gallery-hierarchy",
                            "proof": "same visible root and nested gallery titles and depths",
                        },
                        {
                            "workspace": "photos-lightbox",
                            "state": "linked-lightbox",
                            "proof": "same decoded fixture raster through the production detail view",
                        },
                        {
                            "workspace": "photos-lightbox-fullscreen",
                            "state": "fullscreen-lightbox",
                            "proof": "actual fullscreen state and matching selected fixture photo",
                        },
                        {
                            "workspace": "photos-info",
                            "state": "selected-photo-metadata",
                            "proof": "same selected photo ID and real structured metadata in each inspector",
                        },
                        {
                            "workspace": "photos-context-menu",
                            "state": "native-photo-context-actions",
                            "proof": "triggered Tk and Qt in-window cards expose the visible Photo information action",
                        },
                        {
                            "workspace": "photos-info-unavailable",
                            "state": "safe-metadata-read-failure",
                            "proof": "controlled read exception produces each frontend's visible safe fallback",
                        },
                        {
                            "workspace": "photos-delete-confirmation",
                            "state": "cancelled-permanent-delete",
                            "proof": "actual scoped prompt captured in each frontend; Cancel leaves writer call count at zero",
                        },
                        {
                            "workspace": "photos-folder-shelf",
                            "state": "two-child-mixed-gallery-shelf",
                            "proof": "same two child IDs, titles and decoded cover cards appear beside the photo format controls",
                        },
                        {
                            "workspace": "photos-music-panel",
                            "state": "authorized-empty-presentation",
                            "proof": "both native Music controls open and show the actual empty-playlist status; popup screenshots retained separately",
                        },
                        {
                            "workspace": "watcher",
                            "state": "paired-single-item-pending-legacy-recovery",
                            "proof": "real profile admission verifies the same normalized legacy binding, unfinished SessionPlan, queue session and sole item in separate profile copies; both native progress panels expose enabled Continue while the queue digest remains unchanged and no request is dispatched",
                        },
                        {
                            "workspace": "activity",
                            "state": "seeded-import-history-and-event",
                            "proof": "same no-selection summary, job/event row counts and selected job identity are checked; latest footer copy is recorded separately",
                        },
                    ],
                    "coverage_gaps": list(VISUAL_COVERAGE_GAPS),
                    "size_scope": reports.get("tk", {}).get("size_scope", []),
                    "fixture_scope": reports.get("tk", {}).get("fixture_scope", {}),
                    "tk_report": f"tk-{slice_id}-capture.json",
                    "qt_report": f"qt-{slice_id}-capture.json",
                    "watcher_pending_capture_reports": {
                        backend: f"{backend}-watcher-pending-capture.json"
                        for backend in ("tk", "qt")
                        if backend in watcher_pending_rows
                    },
                    "pairs": pairs,
                    "interpretation": (
                        "Images are native production-window or live production-widget captures. Geometry and "
                        "action inventories support review; they do not claim pixel identity. "
                        "Tk screen DPI/scaling and Qt logical DPI/backing ratio retain their "
                        "native units; only captured device pixels per client pixel are paired."
                    ),
                }, indent=2),
                encoding="utf-8",
            )
            self.assertFalse(
                child_failures,
                visual_failure_summary(
                    child_failures, f"paired-captures-{slice_id}.json",
                ),
            )

    def _write_visual_slice_manifest(self) -> None:
        """Verify the full disjoint slice union and emit the final paired report.

        Returns:
            None: Writes the hash-bound manifest and backward-compatible aggregate.
        """
        output_value = os.environ.get("PHP_GALLERY_VISUAL_PARITY_OUTPUT", "")
        self.assertTrue(output_value, "The hosted visual artifact directory is required.")
        output = Path(output_value).resolve()
        failures: list[str] = []
        expected_ids = {
            (size_name, workspace)
            for size_name, _width, _height in VISUAL_SIZES
            for workspace in VISUAL_WORKSPACES
        }
        expected_ids.update(
            ("standard", workspace) for workspace in VISUAL_SCENARIO_WORKSPACES
        )
        slice_entries: list[dict[str, JsonValue]] = []
        all_pairs: list[dict[str, JsonValue]] = []
        union_ids: list[tuple[str, str]] = []
        seed_hash = ""
        source_identity = ""
        standard_report: dict[str, JsonValue] = {}
        size_scopes: list[list[JsonValue]] = []
        fixtures: dict[str, JsonValue] = {}
        displays: dict[str, JsonValue] = {}
        reports_by_backend: dict[str, dict[str, str]] = {"tk": {}, "qt": {}}
        watcher_reports: dict[str, str] = {}
        for slice_id in VISUAL_CAPTURE_SLICE_IDS:
            filename = f"paired-captures-{slice_id}.json"
            path = output / filename
            if not path.is_file():
                failures.append(f"Missing visual slice report: {filename}")
                slice_entries.append({
                    "slice_id": slice_id, "file": filename, "sha256": "",
                    "status": "MISSING", "expected_case_ids": [],
                    "completed_case_ids": [], "seed_profile_sha256": "",
                    "source_identity": "",
                })
                continue
            raw = path.read_bytes()
            entry_sha256 = sha256(raw).hexdigest()
            try:
                decoded = json.loads(raw.decode("utf-8"))
            except (UnicodeDecodeError, json.JSONDecodeError) as error:
                failures.append(f"Invalid visual slice {filename}: {error}")
                slice_entries.append({
                    "slice_id": slice_id, "file": filename,
                    "sha256": entry_sha256, "status": "INVALID",
                    "expected_case_ids": [], "completed_case_ids": [],
                    "seed_profile_sha256": "", "source_identity": "",
                })
                continue
            if not isinstance(decoded, dict):
                failures.append(f"Visual slice is not an object: {filename}")
                continue
            slice_report = decoded
            sizes, workspaces, scenarios, _include_watcher = VISUAL_CAPTURE_SLICES[
                slice_id
            ]
            expected_slice_ids = {
                (name, workspace)
                for name, _width, _height in sizes
                for workspace in workspaces
            }
            expected_slice_ids.update(("standard", workspace) for workspace in scenarios)
            expected_serialized = [
                list(identity) for identity in sorted(expected_slice_ids)
            ]
            completed_value = slice_report.get("completed_case_ids", [])
            expected_value = slice_report.get("expected_case_ids", [])
            pairs_value = slice_report.get("pairs", [])
            status = str(slice_report.get("status", "MISSING"))
            current_seed = str(slice_report.get("seed_profile_sha256", ""))
            current_source = str(slice_report.get("source_identity", ""))
            if slice_report.get("schema_version") != 1:
                failures.append(f"Unexpected slice schema: {filename}")
            if slice_report.get("slice_id") != slice_id:
                failures.append(f"Slice identity mismatch: expected {slice_id} in {filename}")
            if expected_value != expected_serialized:
                failures.append(f"Declared case set differs for {slice_id}: {expected_value}")
            if completed_value != expected_serialized:
                failures.append(f"Incomplete case set for {slice_id}: {completed_value}")
            if status != "PASS":
                failures.append(f"Visual slice {slice_id} did not pass: {status}")
            pair_ids = [
                (str(row.get("size_name", "")), str(row.get("workspace", "")))
                for row in pairs_value if isinstance(row, dict)
            ] if isinstance(pairs_value, list) else []
            if pair_ids != sorted(expected_slice_ids):
                failures.append(f"Pair rows differ from the exact slice {slice_id}.")
            if len(pair_ids) != len(set(pair_ids)):
                failures.append(f"Duplicate pair identities in slice {slice_id}.")
            if not seed_hash:
                seed_hash = current_seed
            elif current_seed != seed_hash:
                failures.append(f"Seed profile differs in slice {slice_id}.")
            if not source_identity:
                source_identity = current_source
            elif current_source != source_identity:
                failures.append(f"Source identity differs in slice {slice_id}.")
            for backend in ("tk", "qt"):
                report_name = str(slice_report.get(f"{backend}_report", ""))
                report_path = (output / report_name).resolve()
                if (not report_name or report_path.parent != output
                        or not report_path.is_file()):
                    failures.append(f"{slice_id} lacks its retained {backend} child report.")
                else:
                    reports_by_backend[backend][slice_id] = report_name
            if slice_id == "workspace-standard":
                standard_report = slice_report
                raw_displays = slice_report.get("display", {})
                if isinstance(raw_displays, dict):
                    displays = raw_displays
                raw_fixtures = slice_report.get("fixture_scope", {})
                if isinstance(raw_fixtures, dict):
                    fixtures = raw_fixtures
                size_scope = slice_report.get("size_scope", [])
                if isinstance(size_scope, list):
                    size_scopes.extend(
                        item for item in size_scope if isinstance(item, list)
                    )
                raw_watcher_reports = slice_report.get(
                    "watcher_pending_capture_reports", {},
                )
                if isinstance(raw_watcher_reports, dict):
                    watcher_reports = {
                        str(backend): str(report)
                        for backend, report in raw_watcher_reports.items()
                        if isinstance(report, str)
                    }
            for pair in pairs_value if isinstance(pairs_value, list) else []:
                if not isinstance(pair, dict):
                    continue
                for image_key in ("tk_image", "qt_image"):
                    image_path = (output / str(pair.get(image_key, ""))).resolve()
                    if image_path.parent != output or not image_path.is_file():
                        failures.append(
                            f"Missing native image in {slice_id}: {pair.get(image_key)}",
                        )
                all_pairs.append(pair)
            union_ids.extend(pair_ids)
            slice_entries.append({
                "slice_id": slice_id, "file": filename, "sha256": entry_sha256,
                "status": status,
                "expected_case_ids": expected_serialized,
                "completed_case_ids": completed_value,
                "seed_profile_sha256": current_seed,
                "source_identity": current_source,
            })

        if set(union_ids) != expected_ids:
            failures.append(
                f"Slice union differs from the required 46-case matrix; missing="
                f"{sorted(expected_ids - set(union_ids))}, extra="
                f"{sorted(set(union_ids) - expected_ids)}",
            )
        if len(union_ids) != len(set(union_ids)):
            failures.append("The complete visual matrix contains duplicate pair identities.")
        scenario_pairs = [
            pair for pair in all_pairs
            if pair.get("workspace") in VISUAL_SCENARIO_WORKSPACES
        ]
        for backend, hash_key in (
            ("tk", "tk_image_pixel_sha256"),
            ("qt", "qt_image_pixel_sha256"),
        ):
            scenarios_by_hash: dict[str, list[str]] = {}
            for pair in scenario_pairs:
                digest = str(pair.get(hash_key, ""))
                workspace = str(pair.get("workspace", ""))
                scenarios_by_hash.setdefault(digest, []).append(workspace)
            collisions = {
                digest: workspaces for digest, workspaces in scenarios_by_hash.items()
                if digest and len(workspaces) > 1
            }
            if collisions:
                failures.append(
                    f"{backend} scenario screenshots have identical native pixel "
                    f"captures: {collisions}. This can indicate an external modal "
                    "occluding multiple application states; review the retained PNGs."
                )
            standard_photos = next((
                pair for pair in all_pairs
                if pair.get("size_name") == "standard" and pair.get("workspace") == "photos"
            ), None)
            if (standard_photos is not None and standard_photos.get(hash_key)
                    in scenarios_by_hash):
                failures.append(f"{backend} scenario repeated the filled Photos screenshot.")
        for pair in all_pairs:
            for backend, image_key, hash_key in (
                ("tk", "tk_image", "tk_image_pixel_sha256"),
                ("qt", "qt_image", "qt_image_pixel_sha256"),
            ):
                image_path = output / str(pair.get(image_key, ""))
                if image_path.is_file() and visual_image_digest(image_path) != pair.get(hash_key):
                    failures.append(f"Stale {backend} screenshot digest for {pair.get(image_key)}.")
        contact_sheet = ""
        if all_pairs:
            contact_sheet = make_visual_contact_sheet(output, all_pairs, "complete")
        manifest = {
            "schema_version": 1,
            "status": "FAIL" if failures else "PASS",
            "expected_slice_ids": list(VISUAL_CAPTURE_SLICE_IDS),
            "slices": slice_entries,
            "expected_case_ids": [list(identity) for identity in sorted(expected_ids)],
            "completed_case_ids": [list(identity) for identity in sorted(union_ids)],
            "seed_profile_sha256": seed_hash,
            "source_identity": source_identity,
        }
        manifest_path = output / "paired-captures-manifest.json"
        manifest_bytes = json.dumps(manifest, indent=2).encode("utf-8")
        manifest_path.write_bytes(manifest_bytes)
        aggregate = {
            "schema_version": 2,
            "status": "FAIL" if failures else "PASS",
            "manifest_file": manifest_path.name,
            "manifest_sha256": sha256(manifest_bytes).hexdigest(),
            "seed_profile_sha256": seed_hash,
            "source_identity": source_identity,
            "expected_case_ids": [list(identity) for identity in sorted(expected_ids)],
            "completed_case_ids": [list(identity) for identity in sorted(union_ids)],
            "missing_identities": [
                list(identity) for identity in sorted(expected_ids - set(union_ids))
            ],
            "expected_slice_ids": list(VISUAL_CAPTURE_SLICE_IDS),
            "successful_report_backends": [
                backend for backend in ("tk", "qt")
                if len(reports_by_backend[backend]) == len(VISUAL_CAPTURE_SLICE_IDS)
            ],
            "slice_reports": reports_by_backend,
            "tk_report": reports_by_backend["tk"].get(
                "workspace-standard", "tk-workspace-standard-capture.json",
            ),
            "qt_report": reports_by_backend["qt"].get(
                "workspace-standard", "qt-workspace-standard-capture.json",
            ),
            "watcher_pending_capture_reports": watcher_reports,
            "contact_sheet": contact_sheet,
            "display": displays,
            "workspace_scope": list(VISUAL_WORKSPACES),
            "scenario_workspace_scope": list(VISUAL_SCENARIO_WORKSPACES),
            "scenario_coverage": standard_report.get("scenario_coverage", []),
            "coverage_gaps": list(VISUAL_COVERAGE_GAPS),
            "size_scope": size_scopes,
            "fixture_scope": fixtures,
            "failures": failures,
            "pairs": all_pairs,
            "interpretation": (
                "Images are native production-window or live production-widget captures. "
                "Geometry and action inventories support review; they do not claim pixel "
                "identity. Tk screen DPI/scaling and Qt logical DPI/backing ratio retain "
                "their native units; only captured device pixels per client pixel are paired."
            ),
        }
        (output / "paired-captures.json").write_text(
            json.dumps(aggregate, indent=2), encoding="utf-8",
        )
        self.assertFalse(
            failures,
            visual_failure_summary(failures, "paired-captures-manifest.json"),
        )

    @unittest.skipUnless(
        sys.platform == "linux",
        "Synthetic fractional-scale evidence is confined to hosted Xvfb.",
    )
    def test_synthetic_150_percent_scale(self) -> None:
        """Compare real narrow Photos windows under a bounded synthetic scale.

        Returns:
            None: Raw Tk/Qt captures keep configured toolkit scale distinct from
                the unchanged Xvfb DPI and native backend measurements.
        """
        output_value = os.environ.get("PHP_GALLERY_VISUAL_PARITY_OUTPUT", "")
        self.assertTrue(output_value, "The hosted visual artifact directory is required.")
        output = Path(output_value).resolve()
        output.mkdir(parents=True, exist_ok=True)
        failures: list[str] = []
        reports: dict[str, dict[str, JsonValue]] = {}
        with tempfile.TemporaryDirectory(prefix="issue-445-synthetic-dpi-") as temporary:
            root = Path(temporary)
            seed = root / "seed-profile"
            seed_profile(seed)
            seed_hash = profile_digest(seed)
            for backend in ("tk", "qt"):
                profile = root / f"{backend}-profile"
                shutil.copytree(seed, profile)
                report_path = output / f"{backend}-synthetic-150-capture.json"
                child_env = dict(
                    os.environ,
                    PHP_GALLERY_VISUAL_SEED_SHA256=seed_hash,
                    PHP_GALLERY_VISUAL_SYNTHETIC_SCALE=str(VISUAL_SYNTHETIC_SCALE),
                )
                if backend == "qt":
                    child_env["QT_SCALE_FACTOR"] = str(VISUAL_SYNTHETIC_SCALE)
                    child_env["QT_SCALE_FACTOR_ROUNDING_POLICY"] = "PassThrough"
                else:
                    child_env.pop("QT_SCALE_FACTOR", None)
                    child_env.pop("QT_SCALE_FACTOR_ROUNDING_POLICY", None)
                try:
                    child = subprocess.run(
                        [
                            sys.executable, str(Path(__file__).resolve()),
                            "--capture-backend", backend,
                            "--synthetic-scale", str(VISUAL_SYNTHETIC_SCALE),
                            "--profile", str(profile),
                            "--output", str(output),
                            "--report", str(report_path),
                        ],
                        cwd=WINAPP_DIR.parent, env=child_env,
                        capture_output=True, text=True,
                        timeout=VISUAL_CHILD_SECONDS, check=False,
                    )
                except (OSError, subprocess.TimeoutExpired) as error:
                    failures.append(f"{backend} synthetic-scale child failed: {error}")
                    continue
                if not report_path.is_file():
                    failures.append(
                        f"{backend} synthetic-scale child wrote no report: "
                        f"{child.stderr[-4000:]}"
                    )
                    continue
                try:
                    reports[backend] = json.loads(
                        report_path.read_text(encoding="utf-8"),
                    )
                except (OSError, json.JSONDecodeError) as error:
                    failures.append(f"{backend} synthetic-scale report is invalid: {error}")
                    continue
                if child.returncode != 0 or reports[backend].get("status") != "PASS":
                    failures.append(
                        f"{backend} synthetic-scale capture failed: {child.stderr[-4000:]}"
                    )
                for capture in reports[backend].get("captures", []):
                    image = output / str(capture.get("image", ""))
                    if not image.is_file() or image.stat().st_size <= 0:
                        failures.append(f"{backend} raw image is missing: {image.name}")

        tk_report = reports.get("tk", {})
        qt_report = reports.get("qt", {})
        tk_display = tk_report.get("display", {})
        qt_display = qt_report.get("display", {})
        for backend, report, display in (
            ("tk", tk_report, tk_display), ("qt", qt_report, qt_display),
        ):
            if (report.get('seed_profile_sha256') != seed_hash
                    or not report.get('source_identity')
                    or report.get('source_identity') != tk_report.get('source_identity')):
                failures.append(f'{backend} synthetic capture has different profile or source input.')
            if report.get("size_scope") != [list(VISUAL_SYNTHETIC_SIZE)]:
                failures.append(f"{backend} did not use the bounded synthetic viewport.")
            if report.get("workspaces") != ["photos"]:
                failures.append(f"{backend} did not capture the Photos workspace only.")
            scale = display.get("synthetic_scale")
            if not isinstance(scale, dict):
                failures.append(f"{backend} omitted synthetic/native scale provenance.")
            elif scale.get("requested_factor") != VISUAL_SYNTHETIC_SCALE:
                failures.append(f"{backend} synthetic scale metadata differs.")
        tk_scale = tk_display.get("synthetic_scale", {})
        if isinstance(tk_scale, dict):
            baseline = tk_scale.get("native_scaling_pixels_per_point")
            configured = tk_display.get("tk_scaling_pixels_per_point")
            if isinstance(baseline, (int, float)) and isinstance(configured, (int, float)):
                if (abs(baseline * VISUAL_SYNTHETIC_SCALE - configured)
                        > VISUAL_SYNTHETIC_SCALE_TOLERANCE):
                    failures.append("Tk configured scaling is not native scaling × 1.5.")
            else:
                failures.append("Tk did not retain its native/configured scaling values.")
        qt_scale = qt_display.get("synthetic_scale", {})
        if isinstance(qt_scale, dict):
            window_dpr = qt_scale.get("window_device_pixel_ratio")
            if (not isinstance(window_dpr, (int, float))
                    or abs(window_dpr - VISUAL_SYNTHETIC_SCALE) > VISUAL_SYNTHETIC_SCALE_TOLERANCE):
                failures.append("Qt did not expose the requested fractional window DPR.")
        tk_photos = next((
            row for row in tk_report.get("captures", []) if row.get("workspace") == "photos"
        ), {})
        qt_photos = next((
            row for row in qt_report.get("captures", []) if row.get("workspace") == "photos"
        ), {})
        expected: list[str] = []
        if tk_photos and qt_photos:
            expected = tk_photos.get("ratio_captions", [])
            if len(PHOTO_CARD_FORMATS) != len(expected):
                failures.append("The Tk synthetic-scale capture lacks seven ratio captions.")
            if expected != qt_photos.get("ratio_captions"):
                failures.append("Tk and Qt ratio captions differ at synthetic scale.")
            for backend, row in (("tk", tk_photos), ("qt", qt_photos)):
                layouts = row.get("ratio_button_layout")
                if not isinstance(layouts, list) or len(layouts) != len(PHOTO_CARD_FORMATS):
                    failures.append(f"{backend} ratio controls were not all measured.")
                elif any(not isinstance(item, dict) or not item.get("label_visible")
                         for item in layouts):
                    failures.append(f"{backend} ratio captions are clipped at synthetic scale.")
        else:
            failures.append("One synthetic-scale Photos capture is missing.")

        evidence = {
            "schema_version": 1,
            "status": "FAIL" if failures else "PASS",
            "source_identity": (
                os.environ.get('PHP_GALLERY_ISSUE_445_CANDIDATE')
                or os.environ.get('GITHUB_SHA') or tk_report.get('source_identity')
            ),
            "seed_profile_sha256": seed_hash,
            "synthetic_factor": VISUAL_SYNTHETIC_SCALE,
            "physical_display_claim": False,
            "native_dpi_claim": False,
            "native_visual_lane_changed": False,
            "reports": {
                backend: f"{backend}-synthetic-150-capture.json"
                for backend in reports
            },
            "raw_images": {
                "tk": str(tk_photos.get("image", "")),
                "qt": str(qt_photos.get("image", "")),
            },
            "ratio_captions": expected if tk_photos and qt_photos else [],
            "limitations": (
                "The Xvfb screen remains at its runner-native DPI. Tk scaling and "
                "Qt QT_SCALE_FACTOR are configured independently at 1.5; this is "
                "software-only evidence and says nothing about a physical monitor."
            ),
            "failures": failures,
        }
        (output / "synthetic-150-dpi-pair.json").write_text(
            json.dumps(evidence, indent=2), encoding="utf-8",
        )
        self.assertFalse(failures, "\n".join(failures))

    def test_native_czech_locale_pair(self) -> None:
        """Capture localized Photos and Settings from matched fresh profiles.

        Returns:
            None: Three Czech native states per frontend are paired or the
                artifact records the exact locale or capture failure.
        """
        output_value = os.environ.get("PHP_GALLERY_VISUAL_PARITY_OUTPUT", "")
        self.assertTrue(output_value, "The hosted visual artifact directory is required.")
        output = Path(output_value).resolve()
        output.mkdir(parents=True, exist_ok=True)
        with tempfile.TemporaryDirectory(prefix="issue-445-czech-visual-") as temporary:
            root = Path(temporary)
            seed = root / "seed-profile"
            seed_profile(seed, "cs")
            seed_hash = profile_digest(seed)
            reports: dict[str, dict[str, JsonValue]] = {}
            failures: list[str] = []
            for backend in ("tk", "qt"):
                profile = root / f"{backend}-profile"
                shutil.copytree(seed, profile)
                report_path = output / f"{backend}-czech-capture.json"
                try:
                    child = subprocess.run(
                        [
                            sys.executable, str(Path(__file__).resolve()),
                            "--capture-backend", backend,
                            "--locale-probe", "cs",
                            "--profile", str(profile),
                            "--output", str(output),
                            "--report", str(report_path),
                        ],
                        cwd=WINAPP_DIR.parent,
                        env=dict(
                            os.environ,
                            PHP_GALLERY_VISUAL_SEED_SHA256=seed_hash,
                            PHP_GALLERY_VISUAL_LOCALE_PROBE="cs",
                        ),
                        capture_output=True, text=True,
                        timeout=VISUAL_CHILD_SECONDS, check=False,
                    )
                except (OSError, subprocess.TimeoutExpired) as error:
                    failures.append(f"{backend} Czech capture failed to run: {error}")
                    continue
                if not report_path.is_file():
                    failures.append(
                        f"{backend} Czech capture wrote no report: "
                        f"{child.stderr[-4000:]}"
                    )
                    continue
                try:
                    reports[backend] = json.loads(
                        report_path.read_text(encoding="utf-8"),
                    )
                except (OSError, json.JSONDecodeError) as error:
                    failures.append(f"{backend} Czech report could not be read: {error}")
                    continue
                if child.returncode != 0 or reports[backend].get("status") != "PASS":
                    failures.append(
                        f"{backend} Czech capture failed: {child.stderr[-4000:]}"
                    )
            expected = {
                ("narrow-cs", "photos-disconnected"),
                ("narrow-cs", "settings"),
                ("narrow-cs", "photos-ratio-16-9"),
            }
            captures: dict[str, dict[tuple[str, str], dict[str, JsonValue]]] = {}
            checks = _VisualAssertionCollector(self, failures)
            for backend, report in reports.items():
                checks.set_group(f"{backend} Czech locale report")
                checks.assertEqual("cs", report.get("locale"))
                checks.assertEqual(seed_hash, report.get("seed_profile_sha256"))
                checks.assertEqual(
                    os.environ.get("ISSUE_445_SHA", os.environ.get("GITHUB_SHA", "")),
                    report.get("source_identity"),
                )
                rows = {
                    (str(row["size_name"]), str(row["workspace"])): row
                    for row in report.get("captures", [])
                }
                captures[backend] = rows
                checks.assertTrue(expected <= set(rows), f"{backend} lacks Czech states.")
                checks.set_group(f"{backend} Czech disconnected Photos")
                disconnected_row = rows.get(("narrow-cs", "photos-disconnected"))
                if disconnected_row is not None:
                    connect_caption = report.get(
                        "localized_probe_strings", {},
                    ).get("connect_action")
                    disconnected_captions = [
                        str(action.get("caption", ""))
                        for action in disconnected_row.get("actions", [])
                    ]
                    checks.assertEqual("Připojit", connect_caption)
                    checks.assertIn(connect_caption, disconnected_captions)
                checks.set_group(f"{backend} Czech Settings")
                settings_row = rows.get(("narrow-cs", "settings"))
                if settings_row is not None:
                    settings_text = [
                        str(action.get("caption", ""))
                        for action in settings_row.get("actions", [])
                    ]
                    settings_caption = report.get(
                        "localized_probe_strings", {},
                    ).get("settings_action")
                    checks.assertEqual("✓  Uložit nastavení", settings_caption)
                    checks.assertTrue(any(
                        str(settings_caption) in caption for caption in settings_text
                    ))
                checks.set_group(f"{backend} Czech Photos ratio")
                ratio_row = rows.get(("narrow-cs", "photos-ratio-16-9"))
                if ratio_row is not None:
                    captions = ratio_row.get("ratio_captions", [])
                    default_caption = report.get(
                        "localized_probe_strings", {},
                    ).get("default_ratio")
                    checks.assertEqual("16:9", ratio_row.get("selected_ratio"))
                    checks.assertEqual("Výchozí", default_caption)
                    checks.assertTrue(captions, "Czech ratio labels are missing.")
                    if captions:
                        checks.assertEqual(default_caption, captions[0])
                        checks.assertEqual(
                            [
                                default_caption if ratio == "default" else ratio
                                for ratio in PHOTO_CARD_FORMATS
                            ],
                            captions,
                        )
                for identity in expected:
                    row = rows.get(identity)
                    if row is None:
                        continue
                    checks.set_group(f"{backend} Czech {identity[1]}")
                    checks.assertTrue((output / str(row["image"])).is_file())
                    checks.assertEqual([840, 500], row.get("requested_size"))
            pairs: list[dict[str, JsonValue]] = []
            if set(captures) == {"tk", "qt"}:
                for size_name, workspace in sorted(expected):
                    identity = (size_name, workspace)
                    if identity not in captures["tk"] or identity not in captures["qt"]:
                        failures.append(f"Missing Czech pair {size_name}/{workspace}.")
                        continue
                    tk_row = captures["tk"][identity]
                    qt_row = captures["qt"][identity]
                    checks.set_group(f"Czech paired {workspace}")
                    checks.assertEqual(tk_row.get("requested_size"), qt_row.get("requested_size"))
                    checks.assertEqual(
                        tk_row.get("observed_client_size"),
                        qt_row.get("observed_client_size"),
                    )
                    checks.assertEqual(tk_row.get("selected_page"), qt_row.get("selected_page"))
                    checks.assertEqual(
                        tk_row.get("ratio_captions"), qt_row.get("ratio_captions"),
                    )
                    checks.assertEqual(
                        tk_row.get("selected_ratio"), qt_row.get("selected_ratio"),
                    )
                    pair = {
                        "size_name": size_name,
                        "workspace": workspace,
                        "locale": "cs",
                        "tk_image": tk_row["image"],
                        "qt_image": qt_row["image"],
                        "tk_observed_client_size": tk_row["observed_client_size"],
                        "qt_observed_client_size": qt_row["observed_client_size"],
                        "tk_actions": tk_row["actions"],
                        "qt_actions": qt_row["actions"],
                        "tk_settings_captions": [
                            action.get("caption", "")
                            for action in tk_row.get("actions", [])
                        ] if workspace == "settings" else [],
                        "qt_settings_captions": [
                            action.get("caption", "")
                            for action in qt_row.get("actions", [])
                        ] if workspace == "settings" else [],
                        "tk_connect_captions": [
                            action.get("caption", "")
                            for action in tk_row.get("actions", [])
                        ] if workspace == "photos-disconnected" else [],
                        "qt_connect_captions": [
                            action.get("caption", "")
                            for action in qt_row.get("actions", [])
                        ] if workspace == "photos-disconnected" else [],
                        "tk_ratio_captions": tk_row.get("ratio_captions", []),
                        "qt_ratio_captions": qt_row.get("ratio_captions", []),
                        "tk_selected_ratio": tk_row.get("selected_ratio"),
                        "qt_selected_ratio": qt_row.get("selected_ratio"),
                        "tk_image_pixel_sha256": tk_row["image_pixel_sha256"],
                        "qt_image_pixel_sha256": qt_row["image_pixel_sha256"],
                    }
                    pairs.append(pair)
            result = {
                "schema_version": 1,
                "status": "FAIL" if failures else "PASS",
                "locale": "cs",
                "seed_profile_sha256": seed_hash,
                "source_identity": os.environ.get(
                    "ISSUE_445_SHA", os.environ.get("GITHUB_SHA", ""),
                ),
                "expected_identities": [list(item) for item in sorted(expected)],
                "pairs": pairs,
                "failures": failures,
            }
            (output / "czech-paired-captures.json").write_text(
                json.dumps(result, indent=2), encoding="utf-8",
            )
            self.assertFalse(failures, "\n".join(failures))
            self.assertEqual({"tk", "qt"}, set(reports))


def profile_digest(profile: Path) -> str:
    """Hash a sorted synthetic profile manifest before either app is launched.

    Args:
        profile (Path): Seed directory containing the shared fixture files.
    Returns:
        str: SHA-256 digest of relative names and exact file bytes.
    """
    digest = sha256()
    for path in sorted(item for item in profile.rglob("*") if item.is_file()):
        digest.update(path.relative_to(profile).as_posix().encode("utf-8"))
        digest.update(b"\0")
        digest.update(path.read_bytes())
        digest.update(b"\0")
    return digest.hexdigest()


def visual_idle_watch_root() -> Path:
    """Return one empty runner-temp Watcher folder shared by matrix slices.

    Returns:
        Path: Existing empty local folder referenced by every normal seed profile.
    Raises:
        OSError: The runner temporary directory cannot create the fixture folder.
        RuntimeError: An earlier fixture left unexpected files in the shared folder.
    """
    runner_temp = Path(os.environ.get("RUNNER_TEMP", tempfile.gettempdir()))
    run_id = os.environ.get("GITHUB_RUN_ID", "local")
    attempt = os.environ.get("GITHUB_RUN_ATTEMPT", "0")
    watch_root = runner_temp / f"issue-445-visual-watch-{run_id}-{attempt}"
    watch_root.mkdir(parents=True, exist_ok=True)
    if any(watch_root.iterdir()):
        raise RuntimeError(
            f"Shared visual Watcher folder is not empty: {watch_root.name}."
        )
    return watch_root


def watcher_pending_queue_evidence(path: Path) -> tuple[list[str], str]:
    """Read the durable Watcher item IDs and exact journal fingerprint.

    Args:
        path (Path): Physical path to the synthetic Watcher queue journal.
    Returns:
        tuple[list[str], str]: Ordered item identities and SHA-256 of the
            unmodified journal bytes.
    Raises:
        RuntimeError: The journal does not contain a string-identified item list.
    """
    row = WatchQueueStore.read_journal(path)
    raw_items = row.get("items")
    if not isinstance(raw_items, list):
        raise RuntimeError("Pending Watcher journal has no item list.")
    item_ids: list[str] = []
    for raw_item in raw_items:
        if not isinstance(raw_item, dict):
            raise RuntimeError("Pending Watcher journal contains a malformed item.")
        item_id = raw_item.get("item_id")
        if not isinstance(item_id, str):
            raise RuntimeError("Pending Watcher item identity is malformed.")
        item_ids.append(item_id)
    return item_ids, sha256(path.read_bytes()).hexdigest()


def visual_image_digest(path: Path) -> str:
    """Hash decoded RGBA pixels so image metadata cannot fake a distinct capture.

    Args:
        path (Path): Saved screenshot image from one hosted production window.
    Returns:
        str: SHA-256 digest of its decoded RGBA pixel bytes.
    """
    with Image.open(path) as screenshot:
        rgba = screenshot.convert("RGBA")
        return sha256(rgba.tobytes()).hexdigest()


def make_visual_contact_sheet(
    output: Path, pairs: list[dict[str, JsonValue]], slice_id: str,
) -> str:
    """Create a hosted thumbnail sheet for one bounded group of native pairs.

    Args:
        output (Path): Directory containing the exact Tk and Qt screenshots.
        pairs (list[dict[str, JsonValue]]): Validated capture pairs from one runner.
        slice_id (str): Stable slice identifier used in the sheet filename.
    Returns:
        str: Artifact filename for the generated review sheet.
    Raises:
        OSError: A screenshot is absent or the output image cannot be saved.
        TypeError: A pair contains invalid image metadata.
        RuntimeError: No standard-size capture pair is available.
    """
    standard_pairs = [row for row in pairs if row["size_name"] == "standard"]
    sheet_pairs = standard_pairs or pairs
    if not sheet_pairs:
        raise RuntimeError("No captures are available for the contact sheet.")
    tile_width, tile_height = VISUAL_CONTACT_THUMBNAIL_SIZE
    sheet_width = tile_width * 2 + VISUAL_CONTACT_GAP
    row_height = tile_height + VISUAL_CONTACT_LABEL_HEIGHT
    sheet = Image.new(
        "RGB", (sheet_width, row_height * len(sheet_pairs)), "#f2f2f2",
    )
    draw = ImageDraw.Draw(sheet)
    for index, pair in enumerate(sheet_pairs):
        y = index * row_height
        draw.text((0, y + 5), f'{pair["workspace"]} (Tk | Qt)', fill="#111111")
        for column, key in enumerate(("tk_image", "qt_image")):
            image_path = output / str(pair[key])
            with Image.open(image_path) as source:
                thumbnail = source.convert("RGB")
                thumbnail.thumbnail(VISUAL_CONTACT_THUMBNAIL_SIZE, Image.Resampling.LANCZOS)
                x = column * (tile_width + VISUAL_CONTACT_GAP)
                sheet.paste(thumbnail, (x, y + VISUAL_CONTACT_LABEL_HEIGHT))
    filename = f"visual-contact-sheet-{slice_id}.png"
    sheet.save(output / filename)
    return filename


def visual_size_matrix(screen_width: int, screen_height: int) -> tuple[
    tuple[str, int, int], ...
]:
    """Fit the wide paired viewport to the current shared display bounds.

    Args:
        screen_width (int): Native screen width reported by the frontend.
        screen_height (int): Native screen height reported by the frontend.
    Returns:
        tuple[tuple[str, int, int], ...]: Fixed baseline sizes and the
            screen-fitted wide size; maximized remains a native mode marker.
    """
    wide_width = min(
        1080, max(1, screen_width - VISUAL_SCREEN_MARGIN),
    )
    wide_height = min(
        620, max(1, screen_height - VISUAL_SCREEN_MARGIN),
    )
    return (
        ("standard", 980, 580), ("narrow", 840, 500),
        ("wide", wide_width, wide_height), ("maximized", 0, 0),
    )


def place_tk_capture_window(root: tk.Tk, width: int, height: int) -> None:
    """Size a native Tk capture window within the shared display.

    Args:
        root (tk.Tk): Production root used by the isolated visual fixture.
        width (int): Requested client width in logical pixels.
        height (int): Requested client height in logical pixels.
    Returns:
        None: The normal window geometry is requested before capture.
    """
    geometry = f"{width}x{height}"
    if sys.platform == "win32":
        # The synthesized profile can restore a centered origin low enough for
        # Windows to cover the native footer with its taskbar after a resize.
        offset = VISUAL_SCREEN_MARGIN // 2
        geometry += f"+{offset}+{offset}"
    root.geometry(geometry)


def seed_profile(
    profile: Path, language: str = "en", include_flight: bool = True,
    *, pending_watcher: bool = False, watcher_source_root: Path | None = None,
) -> None:
    """Create deterministic UI evidence and optional durable Watcher recovery.

    Args:
        profile (Path): New isolated root shared byte-for-byte by both children.
        language (str): Saved interface locale selected before app construction.
        include_flight (bool): Whether to include the saved session used by the
            separate Flight/recovery evidence.
        pending_watcher (bool): Whether to seed one unfinished legacy Watcher plan.
        watcher_source_root (Path | None): Shared physical folder for the single
            synthetic source, or None to use this profile's local watched folder.
    Returns:
        None: Writes only synthetic, offline presentation fixtures.
    """
    profile.mkdir(parents=True)
    flights = profile / "flights"
    flights.mkdir()
    watched = profile / "watch-input"
    watched.mkdir()
    photo_dir = profile / "visual-photos"
    photo_dir.mkdir()
    for index in range(VISUAL_PHOTO_COUNT):
        image = Image.new("RGB", VISUAL_PHOTO_SIZE, (
            32 + index * 45, 86 + index * 25, 140 + index * 18,
        ))
        drawing = ImageDraw.Draw(image)
        drawing.ellipse((70, 55, 310, 295), fill=(238, 198, 95))
        drawing.rectangle((330, 80, 570, 360), fill=(32, 54, 74))
        drawing.text((28, 20), f"Visual fixture {index + 1}", fill="white")
        image.save(photo_dir / f"visual-{index + 1:02d}.jpg", format="JPEG", quality=88)
    config_store = MAIN.ConfigStore(profile / "config.json")
    config = MAIN.WatcherConfig()
    source_root = (
        watcher_source_root.resolve(strict=True)
        if pending_watcher and watcher_source_root is not None
        else visual_idle_watch_root()
    )
    config.watched_folder = str(source_root)
    config.gallery_url = (
        VISUAL_WATCHER_FIXTURE_URL if pending_watcher else ""
    )
    config.api_key = VISUAL_WATCHER_FIXTURE_KEY if pending_watcher else ""
    config.close_to_tray = False
    config_store.save(config)
    save_ui_language(profile, language)
    FlightPreferences(recording=False, reports=False).save(flights)
    store = JobStateStore(profile / "upload_state.json", VISUAL_HISTORY_LIMIT)
    store.append_event(ActivityEvent(
        "success", "Synthetic visual capture event", "system",
        timestamp=VISUAL_SEED_TIMESTAMP,
    ))
    if not pending_watcher:
        seeded_job = ImportJob.create(
            "files", "Synthetic visual source", "Offline fixture gallery",
        )
        seeded_job.id = VISUAL_SEED_IMPORT_JOB_ID
        seeded_job.created_at = VISUAL_SEED_TIMESTAMP
        store.save_job(seeded_job)
    if include_flight:
        session = flights / VISUAL_SESSION_ID
        session.mkdir()
        SessionPlan(
            session, "a" * 64, (), False, completed=True, local_only=True,
        ).save()
        MAIN.atomic_write_json(session / "session.json", {
            "started_utc": "2026-01-01T12:00:00Z", "aircraft": "Synthetic aircraft",
            "sample_count": 3, "recording_ended": True,
            "gallery_identity": "Offline fixture gallery",
        })
    if pending_watcher:
        if include_flight:
            raise ValueError("Pending Watcher seed cannot share the completed Flight plan.")
        source_root.mkdir(parents=True, exist_ok=True)
        source = source_root / VISUAL_WATCHER_FIXTURE_SOURCE
        shutil.copyfile(photo_dir / "visual-01.jpg", source)
        binding = connection_binding(
            MAIN.normalize_upload_url(config.gallery_url), config.api_key.strip(),
        )
        session = flights / VISUAL_SESSION_ID
        session.mkdir()
        queue_store = WatchQueueStore(
            session / "watch-drain.json", binding, source_root, False,
        )
        queue_store.initialize([])
        if queue_store.reconcile([observe_watch_source(source, time.time())]) != 1:
            raise RuntimeError("Pending Watcher fixture source was not admitted.")
        SessionPlan(session, binding, (), False).save()
        MAIN.atomic_write_json(session / "session.json", {
            "started_utc": "2026-01-01T12:00:00Z", "aircraft": "Synthetic aircraft",
            "sample_count": 3, "recording_ended": False,
            "gallery_identity": "Offline fixture gallery",
        })


def visual_linking_session(
    profile: Path, shape: str = "filled",
) -> tuple[
    LinkingSession, tuple[LinkedGallery, ...], tuple[LinkedPhoto, ...], bytes,
]:
    """Build synthetic read-only gallery pages backed by seeded JPEG bytes.

    Args:
        profile (Path): Shared seed copied into this frontend child.
        shape (str): ``filled``, ``empty``, ``hierarchy`` or ``admin`` variant.
    Returns:
        tuple[LinkingSession, tuple[LinkedGallery, ...], tuple[LinkedPhoto, ...], bytes]:
            Read-only session stub, exact gallery rows, photo descriptors and
            the first generated JPEG used by the native Tk media adapter.
    Raises:
        ValueError: The requested fixture variant is unsupported.
    """
    if shape not in ("filled", "empty", "hierarchy", "admin"):
        raise ValueError("Unsupported linked Photos fixture shape.")
    session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
    session.available.return_value = True
    session.matches_issuer.side_effect = lambda issuer: issuer == VISUAL_PHOTOS_ISSUER
    session.can_list_galleries.return_value = True
    session.can_read_media.return_value = True
    session.can_upload.return_value = False
    session.keepalive_due.return_value = False
    session.can_read_admin_media.return_value = False
    # The rendered probe supplies media bytes without occupying the cache.
    session.has_cached_photo.return_value = False
    session.can_read_presentation.return_value = False
    session.supports_thumbnail_batch.return_value = False
    session.can_reuse_cached_media.return_value = False
    if shape == "admin":
        session.can_read_admin_media.return_value = True
        session.can_delete_admin_media.return_value = True
    session.supports_gallery_tree.return_value = True
    session.identity.return_value = LinkedIdentity(VISUAL_PHOTOS_ACCOUNT, "Visual fixture")
    gallery = LinkedGallery(VISUAL_GALLERY_ID, None, "Synthetic visual gallery")
    galleries = (gallery,)
    if shape == "hierarchy":
        galleries = (
            gallery,
            LinkedGallery(
                VISUAL_CHILD_GALLERY_ID, VISUAL_GALLERY_ID,
                "Synthetic child gallery",
            ),
            LinkedGallery(
                VISUAL_SECOND_CHILD_GALLERY_ID, VISUAL_GALLERY_ID,
                "Synthetic second child gallery",
            ),
        )
    tree = LinkedGalleryTree(galleries, "a" * 64)
    session.gallery_tree.return_value = tree
    all_photos = tuple(
        LinkedPhoto(index + 1, f"Visual fixture {index + 1}")
        for index in range(VISUAL_PHOTO_COUNT)
    )
    photos = () if shape == "empty" else all_photos
    bodies = {
        photo.image_id: (
            profile / "visual-photos" / f"visual-{photo.image_id:02d}.jpg"
        ).read_bytes()
        for photo in all_photos
    }
    child_photos = (
        LinkedPhoto(VISUAL_FOLDER_COVER_PHOTO_IDS[0], "First folder cover"),
        LinkedPhoto(VISUAL_FOLDER_COVER_PHOTO_IDS[1], "Second folder cover"),
    )
    bodies[child_photos[0].image_id] = (
        profile / "visual-photos" / "visual-01.jpg"
    ).read_bytes()
    bodies[child_photos[1].image_id] = (
        profile / "visual-photos" / "visual-02.jpg"
    ).read_bytes()
    photos_by_gallery = {VISUAL_GALLERY_ID: photos}
    if shape == "hierarchy":
        photos_by_gallery.update({
            VISUAL_CHILD_GALLERY_ID: (child_photos[0],),
            VISUAL_SECOND_CHILD_GALLERY_ID: (child_photos[1],),
        })

    def fixture_media_page(
        gallery_id: int, after_id: int | None = None,
        cancelled: Event | None = None, *, timings: MediaReadTimings | None = None,
        cover_only: bool = False,
    ) -> LinkedMediaPage:
        """Return the requested synthetic catalogue page with cover semantics.

        Args:
            gallery_id (int): Exact seeded root or child gallery identity.
            after_id (int | None): Existing page cursor, unused by this fixture.
            cancelled (Event | None): Read cancellation boundary.
            timings (MediaReadTimings | None): Optional caller-owned observation.
            cover_only (bool): Whether the caller requests only a shelf cover row.
        Returns:
            LinkedMediaPage: Stable rows and parent identity for the requested gallery.
        Raises:
            ValueError: The gallery ID is outside this fixture's direct children.
        """
        del timings
        if gallery_id not in photos_by_gallery or after_id is not None:
            raise ValueError("Synthetic media page identity is inconsistent.")
        if cancelled is not None and cancelled.is_set():
            return LinkedMediaPage(gallery_id, None, (), None, cover_only)
        rows = photos_by_gallery[gallery_id]
        if cover_only:
            rows = rows[:1]
        parent_id = None if gallery_id == VISUAL_GALLERY_ID else VISUAL_GALLERY_ID
        return LinkedMediaPage(gallery_id, parent_id, rows, None, cover_only)

    session.media_page.side_effect = fixture_media_page
    session.recent_media_page.side_effect = lambda gallery_id: (
        fixture_media_page(gallery_id)
        if gallery_id in photos_by_gallery else None
    )

    def fixture_cached_media_photo(
        gallery_id: int, photo: LinkedPhoto, *, thumbnail: bool = True,
        cancelled: Event | None = None, timings: MediaReadTimings | None = None,
        use_preloaded_snapshot: bool = False,
    ) -> tuple[bytes, str] | None:
        """Return the fixture JPEG for its exact gallery/photo identity.

        Args:
            gallery_id (int): Exact photo-owning gallery.
            photo (LinkedPhoto): Current authorized synthetic row.
            thumbnail (bool): Existing cache rendition request.
            cancelled (Event | None): Current media cancellation boundary.
            timings (MediaReadTimings | None): Optional caller observation.
            use_preloaded_snapshot (bool): Existing cache mode, irrelevant here.
        Returns:
            tuple[bytes, str] | None: Generated JPEG, or no result after cancel.
        Raises:
            ValueError: The gallery does not own the supplied fixture photo.
        """
        del thumbnail, timings, use_preloaded_snapshot
        if (gallery_id not in photos_by_gallery
                or photo not in photos_by_gallery[gallery_id]):
            raise ValueError("Synthetic cached photo identity is inconsistent.")
        if cancelled is not None and cancelled.is_set():
            return None
        return bodies[photo.image_id], "image/jpeg"

    session.cached_media_photo.side_effect = fixture_cached_media_photo
    first_body = bodies[1]

    def read_fixture_photo_info(
        gallery_id: int, image_id: int, *, cancelled: Event | None = None,
    ) -> dict[str, str | int | bool]:
        """Return exact deterministic metadata for the requested fixture row.

        Args:
            gallery_id (int): Exact synthetic gallery owning the image.
            image_id (int): Exact synthetic photo descriptor being inspected.
            cancelled (Event | None): Optional worker cancellation signal.
        Returns:
            dict[str, str | int | bool]: Bounded, non-sensitive display fields.
        Raises:
            ValueError: The gallery or photo identity is outside the fixture.
        """
        if gallery_id != VISUAL_GALLERY_ID or image_id not in bodies:
            raise ValueError("The requested metadata identity is inconsistent.")
        if cancelled is not None and cancelled.is_set():
            return {}
        return {
            "title": f"Visual fixture {image_id}",
            "filename": f"visual-{image_id:02d}.jpg",
            "mime_type": "image/jpeg", "size": len(bodies[image_id]),
            "width": VISUAL_PHOTO_SIZE[0], "height": VISUAL_PHOTO_SIZE[1],
            "visibility": "private", "nsfw": False,
        }

    session.media_photo_info.side_effect = read_fixture_photo_info
    def read_fixture_photo(
        gallery_id: int, image_id: int, thumbnail: bool,
        cancelled: Event | None = None, *, cache_eligible: bool | None = None,
        thumbnail_etag: str | None = None,
        thumbnail_verified_at: float | None = None,
        preload_eligible: bool | None = None,
        preload_thumbnail_etag: str | None = None,
        preload_verified_at: float | None = None,
        timings: MediaReadTimings | None = None,
        cache_receipts: list[PhotoCacheReceipt] | None = None,
        use_preloaded_snapshot: bool = False,
    ) -> tuple[bytes, str]:
        """Return the exact JPEG bytes associated with one fixture identity.

        Args:
            gallery_id (int): Synthetic gallery identifier supplied by the viewer.
            image_id (int): Stable photo identifier selected by the viewer.
            thumbnail (bool): Whether the current read requests a thumbnail.
            cancelled (Event | None): Current read cancellation event.
            cache_eligible (bool | None): Existing remote cache proof.
            thumbnail_etag (str | None): Existing thumbnail validator.
            thumbnail_verified_at (float | None): Existing proof timestamp.
            preload_eligible (bool | None): Existing preload admission.
            preload_thumbnail_etag (str | None): Existing preload validator.
            preload_verified_at (float | None): Existing preload timestamp.
            timings (MediaReadTimings | None): Optional read observations.
            cache_receipts (list[PhotoCacheReceipt] | None): Optional receipt sink.
            use_preloaded_snapshot (bool): Existing full-image reuse choice.
        Returns:
            tuple[bytes, str]: Encoded image body and its JPEG media type.
        Raises:
            ValueError: The gallery ID differs from the seeded gallery identity.
        """
        if (gallery_id not in photos_by_gallery
                or image_id not in {photo.image_id for photo in photos_by_gallery[gallery_id]}):
            raise ValueError("The synthetic gallery identity is inconsistent.")
        del (
            thumbnail, cancelled, cache_eligible, thumbnail_etag,
            thumbnail_verified_at, preload_eligible, preload_thumbnail_etag,
            preload_verified_at, timings, cache_receipts, use_preloaded_snapshot,
        )
        return bodies[image_id], "image/jpeg"

    session.media_photo.side_effect = read_fixture_photo
    session.thumbnail_publication_current.return_value = True

    def publish_fixture_thumbnails(
        gallery_id: int, descriptors: tuple[LinkedPhoto, ...],
        publish: _VisualThumbnailPublisher,
        cancelled: Event | None = None, *, timings: MediaReadTimings | None = None,
        stats: ThumbnailBatchStats | None = None,
        retry_wait: Callable[[int], None] | None = None,
        active_photos: Callable[[tuple[int, ...]], None] | None = None,
    ) -> None:
        """Publish exact synthetic thumbnails through the Qt worker contract.

        Args:
            gallery_id (int): Exact fixture gallery selected in Photos.
            descriptors (tuple[LinkedPhoto, ...]): Current catalogued photo rows.
            publish (_VisualThumbnailPublisher): Qt worker receiver.
            cancelled (Event | None): Existing worker cancellation event.
            timings (MediaReadTimings | None): Optional timing collector.
            stats (ThumbnailBatchStats | None): Optional batch counters.
            retry_wait (Callable[[int], None] | None): Optional cooldown callback.
            active_photos (Callable[[tuple[int, ...]], None] | None): Optional
                current request IDs for the shared loading animation.
        Returns:
            None: Sends each image using the standard immutable publication.
        """
        del cancelled, timings, stats, retry_wait
        if active_photos is not None:
            active_photos(tuple(photo.image_id for photo in descriptors))
        try:
            for photo in descriptors:
                publication = LegacyThumbnailPublication(
                    session, VISUAL_PHOTOS_ISSUER, gallery_id, photo,
                    "ok", bodies[photo.image_id], "image/jpeg", None, None, 0,
                )
                publish(publication, MediaReadTimings(), lambda: None)
        finally:
            if active_photos is not None:
                active_photos(())

    session.media_thumbnails.side_effect = publish_fixture_thumbnails
    return session, galleries, photos, first_body


def tk_widget_text(widget: tk.Misc) -> str:
    """Read a bounded action caption from one native Tk widget.

    Args:
        widget (tk.Misc): Tk action widget under the production window.
    Returns:
        str: Visible action caption, or empty when unsupported.
    """
    try:
        return str(widget.cget("text"))[:VISUAL_ACTION_CAPTION_CHARS]
    except (AttributeError, RuntimeError):
        return ""


def qt_widget_text(widget: QWidget) -> str:
    """Read a bounded action caption from one native Qt widget.

    Args:
        widget (QWidget): Qt action widget under the production window.
    Returns:
        str: Visible action caption, or empty when unsupported.
    """
    text = getattr(widget, "text", None)
    if callable(text):
        try:
            return str(text())[:VISUAL_ACTION_CAPTION_CHARS]
        except RuntimeError:
            return ""
    return ""


def capture_tk_disconnected(app: MAIN.WatcherApp, output: Path) -> dict[str, JsonValue]:
    """Capture the clean disconnected Photos page before mounting fixture access.

    Args:
        app (MAIN.WatcherApp): Fresh application using only the synthetic profile.
        output (Path): Directory receiving the native window screenshot.
    Returns:
        dict[str, JsonValue]: Actual selected page, controls, geometry and pixels.
    Raises:
        RuntimeError: The fresh profile is linked or its Photos connect card is hidden.
    """
    root = app.root
    size_name, width, height = VISUAL_SIZES[0]
    app.select_tab("photos")
    place_tk_capture_window(root, width, height)
    root.deiconify()
    settle_tk(root)
    workspace = app.photos_workspace
    page = app.tab_hosts["photos"]
    if workspace._session is not None or workspace._viewer is not None:
        raise RuntimeError("Fresh Tk visual profile unexpectedly has a linked Photos session.")
    if not page.winfo_viewable() or not workspace.connect_view.winfo_viewable():
        raise RuntimeError("Fresh Tk Photos connect card is not visibly selected.")
    root.update_idletasks()
    card_geometry = [
        workspace.connect_view.winfo_rootx() - page.winfo_rootx(),
        workspace.connect_view.winfo_rooty() - page.winfo_rooty(),
        workspace.connect_view.winfo_width(), workspace.connect_view.winfo_height(),
    ]
    page_size = [page.winfo_width(), page.winfo_height()]
    if (min(card_geometry[2:]) <= 0 or min(page_size) <= 0
            or card_geometry[2] * card_geometry[3]
            >= page_size[0] * page_size[1] * VISUAL_CONNECT_CARD_MAX_AREA_RATIO):
        raise RuntimeError(
            f"Tk clean connect card is not compact within its workspace: "
            f"card={card_geometry}, workspace={page_size}."
        )
    bounds = [root.winfo_rootx(), root.winfo_rooty(),
              root.winfo_width(), root.winfo_height()]
    filename = f"tk-{size_name}-photos-disconnected.png"
    image = ImageGrab.grab(bbox=(
        bounds[0], bounds[1], bounds[0] + bounds[2], bounds[1] + bounds[3],
    ))
    image.save(output / filename)
    actions = []
    workspace_action_captions: list[str] = []
    workspace_controls = set(descendants_tk(page))
    for widget in descendants_tk(root):
        if widget.winfo_viewable() and widget.winfo_class() in (
            "Button", "TButton", "Checkbutton", "TCheckbutton", "Radiobutton",
        ):
            caption = tk_widget_text(widget)
            if widget in workspace_controls:
                workspace_action_captions.append(caption)
            actions.append({
                "class": widget.winfo_class(), "caption": caption,
                "geometry": [widget.winfo_rootx() - bounds[0],
                             widget.winfo_rooty() - bounds[1],
                             widget.winfo_width(), widget.winfo_height()],
            })
    selected_page = next(
        (name for name, index in app.tab_indices.items()
         if index == app.notebook.index(app.notebook.select())),
        "",
    )
    if selected_page != "photos":
        raise RuntimeError(
            f"Fresh Tk Photos tab mismatch: actual={selected_page}."
        )
    header = app._app_header
    return {
        "size_name": size_name, "workspace": "photos-disconnected",
        "image": filename, "requested_size": list(VISUAL_SIZES[0][1:]),
        "requested_window_state": "normal", "actual_window_state": "normal",
        "selected_page": selected_page,
        "observed_client_size": bounds[2:], "image_size": list(image.size),
        "image_pixel_sha256": visual_image_digest(output / filename),
        "capture_pixel_scale": [image.width / bounds[2], image.height / bounds[3]],
        "workspace_visible": True, "lightbox_visible": False,
        "visible_photo_count": 0, "regions": [{
            "name": "workspace-photos-disconnected",
            "geometry": [page.winfo_rootx() - bounds[0],
                         page.winfo_rooty() - bounds[1],
                         page.winfo_width(), page.winfo_height()],
        }, {
            "name": "photos-connect-card",
            "geometry": card_geometry,
        }],
        "connect_card_geometry": card_geometry,
        "connect_card_workspace_size": page_size,
        "actions": actions, "fixture_gallery_id": None, "fixture_photo_ids": [],
        "fixture_state": "fresh-disconnected-profile", "ratio_captions": [],
        "selected_ratio": None, "supplemental_images": [],
        "latest_activity_text": app.latest_activity_var.get(),
        "workspace_action_captions": workspace_action_captions,
        "tagline_visible": header.tagline.winfo_viewable(),
        "header_reflow": {
            "mode": header._mode[0] if header._mode is not None else "unlaid",
            "available_width": header.winfo_width(),
            "brand_width": header.brand.winfo_reqwidth(),
            "tagline_width": header.tagline.winfo_reqwidth(),
            "version_width": header.version.winfo_reqwidth(),
            "links_width": header.links.winfo_reqwidth(),
            "actions_width": header.actions.winfo_reqwidth(),
        },
    }


def capture_tk_connecting(
    app: MAIN.WatcherApp, output: Path, profile: Path,
) -> dict[str, JsonValue]:
    """Capture the real pending consent dialog before any request reaches a service.

    Args:
        app (MAIN.WatcherApp): Fresh Tk shell with no linked gallery session.
        output (Path): Directory receiving the native pending-state capture.
        profile (Path): Isolated profile receiving the exact recent-address fixture.
    Returns:
        dict[str, JsonValue]: Actual pending dialog surface and visible status.
    Raises:
        RuntimeError: The pending consent UI or its owned worker is not visible.
    """
    manager = app.linking_manager
    root = app.root
    history_path = profile / "recent-gallery-links.json"
    save_recent_gallery_addresses(history_path, (VISUAL_PHOTOS_ISSUER,))
    manager._recent_link_path = history_path
    manager._recent_links = (VISUAL_PHOTOS_ISSUER,)
    manager.open_address_dialog(prefill="https://visual-fixture.invalid/")
    prompt = manager._address_prompt
    if prompt is None:
        raise RuntimeError("Tk pending consent address dialog did not open.")
    dialog, _entry, confirm = prompt
    recent_tree = next((
        widget for widget in descendants_tk(dialog)
        if widget.winfo_class() == "Treeview"
        and "recent" in widget.get_children("")
    ), None)
    recent_addresses = tuple(
        str(recent_tree.item(item, "text")).removeprefix("✓ ")
        for item in recent_tree.get_children("recent")
    ) if recent_tree is not None else ()
    recent_addresses_visible = bool(
        recent_tree is not None and recent_tree.winfo_viewable()
        and recent_addresses == (VISUAL_PHOTOS_ISSUER,)
    )
    if not recent_addresses_visible:
        raise RuntimeError(
            "Tk linking prompt did not show the isolated recent-address fixture."
        )
    release_worker = Event()

    def hold_before_network(
        attempt: LinkingAttempt, *, prefer_upload: bool = False,
        prefer_rolling: bool = False, prefer_presentation: bool = False,
        prefer_admin_media: bool = False, require_admin_media: bool = False,
    ) -> PushedAuthorization:
        """Hold the real authorization worker before any transport request.

        Args:
            attempt (LinkingAttempt): Current ephemeral local callback owner.
            prefer_upload (bool): Requested upload capability.
            prefer_rolling (bool): Requested offline renewal capability.
            prefer_presentation (bool): Requested presentation capability.
            prefer_admin_media (bool): Requested Photos administration capability.
            require_admin_media (bool): Whether admin Photos is mandatory.
        Returns:
            PushedAuthorization: Never returned; the fixture cancels this stage.
        Raises:
            LinkingError: The capture released the worker without issuing HTTP.
        """
        del (
            attempt, prefer_upload, prefer_rolling, prefer_presentation,
            prefer_admin_media, require_admin_media,
        )
        release_worker.wait(VISUAL_OPERATION_TIMEOUT_SECONDS)
        raise LinkingError("Synthetic authorization capture cancelled before HTTP.")

    try:
        with mock.patch.object(LinkingAttempt, "push_request", hold_before_network):
            confirm.invoke()
            wait_tk(root, lambda: (
                manager._worker is not None
                and manager._worker.is_alive()
                and manager._status.get() == manager._text("linking_requesting")
                and dialog.winfo_viewable()
            ), "pending Tk browser-consent request")
            dialog.update_idletasks()
            bounds = [dialog.winfo_rootx(), dialog.winfo_rooty(),
                      dialog.winfo_width(), dialog.winfo_height()]
            size_name = VISUAL_SIZES[0][0]
            filename = f"tk-{size_name}-photos-connecting.png"
            image = ImageGrab.grab(bbox=(
                bounds[0], bounds[1], bounds[0] + bounds[2], bounds[1] + bounds[3],
            ))
            image.save(output / filename)
            actions = [
                {
                    "class": widget.winfo_class(),
                    "caption": tk_widget_text(widget),
                    "geometry": [
                        widget.winfo_rootx() - bounds[0],
                        widget.winfo_rooty() - bounds[1],
                        widget.winfo_width(), widget.winfo_height(),
                    ],
                }
                for widget in descendants_tk(dialog)
                if widget.winfo_viewable()
                and widget.winfo_class() in ("Button", "TButton", "Entry", "TEntry")
            ]
            selected_page = app.tab_indices and next(
                (name for name, index in app.tab_indices.items()
                 if index == app.notebook.index(app.notebook.select())),
                "",
            )
            if selected_page != "photos":
                raise RuntimeError(
                    f"Tk pending consent left Photos: actual={selected_page}."
                )
            return {
                "size_name": size_name, "workspace": "photos-connecting",
                "image": filename, "requested_size": list(VISUAL_SIZES[0][1:]),
                "requested_window_state": "normal", "actual_window_state": "normal",
                "selected_page": selected_page,
                "observed_client_size": bounds[2:], "image_size": list(image.size),
                "image_pixel_sha256": visual_image_digest(output / filename),
                "capture_pixel_scale": [
                    image.width / bounds[2], image.height / bounds[3],
                ],
                "workspace_visible": bool(dialog.winfo_viewable()),
                "lightbox_visible": False, "visible_photo_count": 0,
                "regions": [{
                    "name": "photos-connecting-dialog",
                    "geometry": [0, 0, bounds[2], bounds[3]],
                }],
                "actions": actions, "fixture_gallery_id": None,
                "fixture_photo_ids": [], "fixture_state": "pending-browser-consent",
                "pending_auth_state": True, "pending_status": manager._status.get(),
                "connect_action_enabled": bool(confirm.instate(["!disabled"])),
                "recent_addresses": list(recent_addresses),
                "recent_addresses_visible": recent_addresses_visible,
                "latest_activity_text": app.latest_activity_var.get(),
                "ratio_captions": [], "selected_ratio": None,
                "selected_photo_ids": [], "supplemental_images": [],
            }
    finally:
        if manager._address_prompt is not None:
            cancel_button = next((
                widget for widget in descendants_tk(dialog)
                if widget.winfo_class() == "TButton"
                and tk_widget_text(widget) == gettext("Cancel")
            ), None)
            if cancel_button is None:
                raise RuntimeError("Tk consent fixture lost its real Cancel action.")
            cancel_button.invoke()
            wait_tk(
                root, lambda: manager._address_prompt is None,
                "real Tk consent cancellation",
            )
        if manager._cancel is not None:
            manager._cancel.set()
        release_worker.set()
        worker = manager._worker
        if worker is not None:
            wait_tk(root, lambda: not worker.is_alive(), "cancelled local consent worker")
        if manager._address_prompt is not None:
            manager._dismiss_address_prompt()


def capture_tk(profile: Path, output: Path) -> dict[str, JsonValue]:
    """Capture the complete production Tk notebook at matching client sizes.

    Args:
    profile (Path): Current child profile cloned from the shared seed and
        physically resolved before native profile ownership begins.
        output (Path): Directory receiving native PNG captures.
    Returns:
        dict[str, JsonValue]: Display metrics, workspaces and observed controls.
    """
    profile = profile.resolve(strict=True)
    if profile_digest(profile) != os.environ["PHP_GALLERY_VISUAL_SEED_SHA256"]:
        raise RuntimeError("Tk child profile differs from the shared visual seed.")
    language = configure_language(load_ui_language(profile))
    captures: list[dict[str, JsonValue]] = []
    capture_failures: list[dict[str, str]] = []
    patches = ExitStack()
    for name, value in (
        ("CONFIG_DIR", profile), ("CONFIG_PATH", profile / "config.json"),
        ("STATE_PATH", profile / "upload_state.json"),
        ("LOG_PATH", profile / "watcher.log"),
        ("STAGING_ROOT", profile / "staging"),
    ):
        patches.enter_context(mock.patch.object(watcher_state, name, value))
    patches.enter_context(mock.patch.object(MAIN.WatcherApp, "start_tray_icon"))
    watcher_recovery_probe = (
        os.environ.get(VISUAL_WATCHER_RECOVERY_PROBE) == "1"
    )
    mode_store_name = (
        "MacUploadModeStore" if sys.platform == "darwin"
        else "WindowsUploadModeStore"
    )
    patches.enter_context(mock.patch.object(
        watcher_app_module, mode_store_name, return_value=visual_mode_vault(),
    ))
    capture_name_prefix = "watcher-recovery-" if watcher_recovery_probe else ""
    try:
        startup_link_requests = mock.Mock(
            side_effect=AssertionError(
                "A visual fixture must not start a live linking request."
            ),
        )
        with mock.patch.object(
            LinkingAttempt, "push_request", startup_link_requests,
        ):
            app = MAIN.WatcherApp(
                config_store=MAIN.ConfigStore(profile / "config.json"),
            )
            root = app.root
            synthetic_scale = os.environ.get(
                "PHP_GALLERY_VISUAL_SYNTHETIC_SCALE",
            )
            native_tk_dpi = float(root.winfo_fpixels("1i"))
            native_tk_scaling = float(root.tk.call("tk", "scaling"))
            if synthetic_scale:
                root.tk.call(
                    "tk", "scaling",
                    native_tk_scaling * float(synthetic_scale),
                )
            root.deiconify()
            root.update()
            if sys.platform == "win32":
                # Use the outer client edge so the runner's taskbar tooltip
                # cannot obscure the real footer or reach a hover action.
                root.event_generate('<Motion>', x=0, y=0, warp=True)
            wait_tk(root, lambda: app._gui_mode_ready, "real legacy mode owner")
            disconnected_capture = (
                capture_tk_disconnected(app, output)
                if not watcher_recovery_probe
                and "photos-disconnected" in VISUAL_SCENARIO_WORKSPACES else None
            )
        if watcher_recovery_probe:
            if (
                MAIN.normalize_upload_url(app.config.gallery_url)
                != MAIN.normalize_upload_url(VISUAL_WATCHER_FIXTURE_URL)
                or app.config.api_key != VISUAL_WATCHER_FIXTURE_KEY
                or app.mode_owner.view().state not in ("legacy", "legacy_unmigrated")
            ):
                raise RuntimeError("Tk pending probe lacks its admitted legacy target.")
        elif app.config.gallery_url or app.config.api_key:
            raise RuntimeError("Synthetic shell profile unexpectedly has a gallery target.")
        watcher_binding = (
            app.flights.binding() if watcher_recovery_probe else ""
        )
        if startup_link_requests.call_count:
            raise RuntimeError(
                "Tk startup dispatched a live linking request."
            )
        display = {
            "platform": sys.platform,
            "screen_size": [root.winfo_screenwidth(), root.winfo_screenheight()],
            "dpi": (
                native_tk_dpi if synthetic_scale
                else float(root.winfo_fpixels("1i"))
            ),
            "dpi_units": "Tk screen pixels per inch",
            "native_dpi": native_tk_dpi,
            "native_dpi_units": "Tk screen pixels per inch before synthetic scaling",
            "tk_scaling_pixels_per_point": float(root.tk.call("tk", "scaling")),
            "tk_scaling_native_pixels_per_point": native_tk_scaling,
            "tk_patchlevel": str(root.tk.call("info", "patchlevel")),
        }
        if synthetic_scale:
            display["synthetic_scale"] = {
                "requested_factor": float(synthetic_scale),
                "native_scaling_pixels_per_point": native_tk_scaling,
                "native_screen_dpi": native_tk_dpi,
                "configured_screen_dpi": float(root.winfo_fpixels("1i")),
                "configured_scaling_pixels_per_point": float(
                    root.tk.call("tk", "scaling"),
                ),
                "configuration": "Tk scaling set to native value × factor",
                "physical_display_claim": False,
            }
        if not watcher_recovery_probe:
            if ("photos-disconnected" in VISUAL_SCENARIO_WORKSPACES
                    and disconnected_capture is None):
                raise RuntimeError("Tk disconnected startup capture is unavailable.")
            if disconnected_capture is not None:
                captures.append(disconnected_capture)
            if "photos-connecting" in VISUAL_SCENARIO_WORKSPACES:
                captures.append(capture_tk_connecting(app, output, profile))
                # Cancel is the real terminal outcome of this isolated request;
                # restore the shared Photos fixture's neutral reference status.
                app.linking_manager.status.set("")
        if not watcher_recovery_probe:
            visual_session, _galleries, _photos, _first_body = visual_linking_session(profile)
            app.photos_workspace._load_photo_order = lambda _session, _gallery: "newest"
            app.linking_manager._show_link(DesktopLinkedSite(
                VISUAL_PHOTOS_ISSUER, visual_session, VISUAL_PHOTOS_ACCOUNT,
            ))
            app.photos_workspace.refresh_session()
        sizes = (
            (VISUAL_SIZES[0],) if (
                watcher_recovery_probe or synthetic_scale
            ) else
            VISUAL_CZECH_SIZES
            if language == "cs" and os.environ.get("PHP_GALLERY_VISUAL_LOCALE_PROBE") == "cs"
            else visual_size_matrix(
                root.winfo_screenwidth(), root.winfo_screenheight(),
            )[2:3]
            if VISUAL_CAPTURE_SLICE_ID == "workspace-wide"
            else VISUAL_SIZES
            if VISUAL_CAPTURE_SLICE_ID != "full"
            else visual_size_matrix(
                root.winfo_screenwidth(), root.winfo_screenheight(),
            )
        )
        standard_client_size: tuple[int, int] | None = None
        pending_journal_path = (
            profile / "flights" / VISUAL_SESSION_ID / "watch-drain.json"
        )
        pending_journal_before = (
            watcher_pending_queue_evidence(pending_journal_path)[1]
            if watcher_recovery_probe else ""
        )
        for size_name, width, height in sizes:
            root.state("normal")
            if (size_name == "maximized"
                    and VISUAL_CAPTURE_SLICE_ID == "workspace-maximized"):
                _wide_name, wide_width, wide_height = visual_size_matrix(
                    root.winfo_screenwidth(), root.winfo_screenheight(),
                )[2]
                place_tk_capture_window(root, wide_width, wide_height)
                root.update()
            actual_window_state = "normal"
            max_reference_client_size = (
                [root.winfo_width(), root.winfo_height()]
                if size_name == "maximized" else None
            )
            if size_name == "maximized":
                requested_size = [root.winfo_screenwidth(), root.winfo_screenheight()]
                try:
                    root.state("zoomed")
                    root.update()
                except tk.TclError:
                    pass
                root.update()
                expanded = (
                    max_reference_client_size is not None
                    and root.winfo_width() * root.winfo_height()
                    >= (max_reference_client_size[0] * max_reference_client_size[1]
                        * VISUAL_MAX_AREA_RATIO)
                    and root.winfo_height()
                    >= max_reference_client_size[1] * VISUAL_MAX_HEIGHT_RATIO
                )
                if root.state() == "zoomed" and expanded:
                    actual_window_state = "maximized"
                else:
                    root.state("normal")
                    root.geometry(
                        f"{max(1, requested_size[0] - VISUAL_SCREEN_MARGIN)}x"
                        f"{max(1, requested_size[1] - VISUAL_SCREEN_MARGIN)}+"
                        f"{VISUAL_SCREEN_MARGIN // 2}+{VISUAL_SCREEN_MARGIN // 2}"
                    )
                    root.update()
                    actual_window_state = "screen-sized-fallback"
            else:
                requested_size = [width, height]
                place_tk_capture_window(root, width, height)
            root.update()
            for workspace in VISUAL_WORKSPACES:
                if workspace in ("flight", "watcher"):
                    app.select_tab("watch")
                    if workspace == "flight":
                        app.flights.show_progress()
                    elif watcher_recovery_probe:
                        app.flights.show_recovery()
                        wait_tk(root, lambda: (
                            app.flights.dialog is not None
                            and app.flights.dialog.winfo_viewable()
                            and app.flights._retained_capture is not None
                            and "watch_recovery_resume_pending"
                            in app.flights.progress_buttons
                            and app.flights.progress_buttons[
                                "watch_recovery_resume_pending"
                            ].instate(["!disabled"])
                        ), "read-only retained Watcher recovery and Continue")
                else:
                    page_name = {
                        "watcher": "watch", "photos-lightbox": "photos",
                    }.get(workspace, workspace)
                    app.select_tab(page_name)
                    if workspace.startswith("photos"):
                        wait_tk(root, lambda: app.photos_workspace._viewer is not None)
                        controller = app.photos_workspace._viewer._gallery_viewer_controller
                        wait_tk(root, lambda: any(
                            image is not None for image in controller._photo_images
                        ))
                        if workspace == "photos-lightbox":
                            controller._open_grid_photo(0)
                            wait_tk(root, lambda: (
                                controller._lightbox is not None
                                and controller._lightbox.visible
                                and controller._lightbox._photo is not None
                            ))
                settle_tk(root)
                page_name = (
                    "watch" if workspace in ("flight", "watcher") else
                    "photos" if workspace == "photos-lightbox" else workspace
                )
                selected_tab = app.notebook.index(app.notebook.select())
                selected_page = next(
                    (name for name, index in app.tab_indices.items()
                     if index == selected_tab),
                    "",
                )
                if selected_page != page_name:
                    raise RuntimeError(
                        f"Tk selected tab mismatch for {workspace}/{size_name}: "
                        f"expected={page_name}, actual={selected_page}."
                    )
                bounds = [root.winfo_rootx(), root.winfo_rooty(),
                          root.winfo_width(), root.winfo_height()]
                if size_name == "standard" and standard_client_size is None:
                    standard_client_size = (bounds[2], bounds[3])
                supplemental_images: list[dict[str, JsonValue]] = []
                if (workspace == "flight" or watcher_recovery_probe
                        and workspace == "watcher") and app.flights.dialog is not None:
                    dialog = app.flights.dialog
                    dialog.update_idletasks()
                    dialog_bounds = [
                        dialog.winfo_rootx(), dialog.winfo_rooty(),
                        dialog.winfo_width(), dialog.winfo_height(),
                    ]
                    dialog_image = ImageGrab.grab(bbox=(
                        dialog_bounds[0], dialog_bounds[1],
                        dialog_bounds[0] + dialog_bounds[2],
                        dialog_bounds[1] + dialog_bounds[3],
                    ))
                    panel_name = (
                        "watcher-recovery" if watcher_recovery_probe
                        and workspace == "watcher" else "flight"
                    )
                    dialog_filename = f"tk-{size_name}-{panel_name}-panel.png"
                    dialog_image.save(output / dialog_filename)
                    supplemental_images.append({
                        "name": "flight-progress-overlay",
                        "image": dialog_filename,
                        "desktop_bounds": dialog_bounds,
                        "image_size": list(dialog_image.size),
                        "phase": app.flights.phase_var.get(),
                        "paused_status": app.flights.detail_var.get(),
                        "activity_visible": bool(app.flights.activity_bar.winfo_viewable()),
                    })
                filename = f"{capture_name_prefix}tk-{size_name}-{workspace}.png"
                image = ImageGrab.grab(bbox=(
                    bounds[0], bounds[1], bounds[0] + bounds[2], bounds[1] + bounds[3],
                ))
                image.save(output / filename)
                actions = []
                for widget in descendants_tk(root):
                    if widget.winfo_viewable() and widget.winfo_class() in (
                        "Button", "TButton", "Checkbutton", "TCheckbutton", "Radiobutton",
                    ):
                        actions.append({
                            "class": widget.winfo_class(), "caption": tk_widget_text(widget),
                            "geometry": [widget.winfo_rootx() - bounds[0],
                                         widget.winfo_rooty() - bounds[1],
                                         widget.winfo_width(), widget.winfo_height()],
                        })
                page = app.tab_hosts[page_name]
                lightbox = (
                    app.photos_workspace._viewer._gallery_viewer_controller._lightbox
                    if app.photos_workspace._viewer is not None else None
                )
                lightbox_visible = bool(lightbox is not None and lightbox.visible)
                if lightbox_visible != (workspace == "photos-lightbox"):
                    raise RuntimeError(
                        f"Tk Lightbox visibility is inconsistent: {workspace}/{size_name}."
                    )
                if workspace == "photos-lightbox":
                    active_surface_visible = bool(
                        lightbox is not None
                        and lightbox._overlay.winfo_viewable()
                        and lightbox._photo is not None
                    )
                else:
                    active_surface_visible = bool(page.winfo_viewable())
                    if (lightbox is not None
                            and lightbox._overlay.winfo_manager() != ""):
                        raise RuntimeError(
                            f"Tk Lightbox overlay was not cleared: {workspace}/{size_name}."
                        )
                if not active_surface_visible:
                    raise RuntimeError(
                        f"Tk active surface is not viewable: {workspace}/{size_name}."
                    )
                if workspace == "flight" and size_name == "standard":
                    app.flights.show_reports()
                    report_dialog = app.flights.report_dialog
                    wait_tk(root, lambda: (
                        report_dialog is not None
                        and report_dialog.winfo_viewable()
                        and app.flights.report_tree is not None
                        and app.flights.report_tree.winfo_viewable()
                    ), "standard Tk saved-flight catalog")
                    report_tree = app.flights.report_tree
                    if report_dialog is None or report_tree is None:
                        raise RuntimeError("Tk saved-flight catalog has no native tree.")
                    flight_ids = list(report_tree.get_children(""))
                    flight_labels = [
                        str(report_tree.item(identity, "text"))
                        for identity in flight_ids
                    ]
                    if flight_ids != [VISUAL_SESSION_ID]:
                        raise RuntimeError(
                            f"Tk saved-flight fixture rows differ: {flight_ids}."
                        )
                    report_tree.selection_set(VISUAL_SESSION_ID)
                    report_tree.focus(VISUAL_SESSION_ID)
                    settle_tk(root)
                    archive_bounds = [
                        report_dialog.winfo_rootx(), report_dialog.winfo_rooty(),
                        report_dialog.winfo_width(), report_dialog.winfo_height(),
                    ]
                    archive_actions = {
                        caption: [widget.winfo_rootx() - archive_bounds[0],
                                  widget.winfo_rooty() - archive_bounds[1],
                                  widget.winfo_width(), widget.winfo_height()]
                        for widget in descendants_tk(report_dialog)
                        if widget.winfo_viewable() and widget.winfo_class() in (
                            "Button", "TButton", "Checkbutton", "TCheckbutton",
                        ) and (caption := tk_widget_text(widget))
                    }
                    archive_filename = "tk-standard-flight-saved-reports.png"
                    archive_image = ImageGrab.grab(bbox=(
                        archive_bounds[0], archive_bounds[1],
                        archive_bounds[0] + archive_bounds[2],
                        archive_bounds[1] + archive_bounds[3],
                    ))
                    archive_image.save(output / archive_filename)
                    supplemental_images.append({
                        "name": "saved-flight-archive",
                        "image": archive_filename,
                        "image_size": list(archive_image.size),
                        "desktop_bounds": archive_bounds,
                        "visible": report_dialog.winfo_viewable()
                        and report_tree.winfo_viewable(),
                        "flight_ids": flight_ids,
                        "flight_labels": flight_labels,
                        "selected_flight_ids": list(report_tree.selection()),
                        "tree_geometry": [
                            report_tree.winfo_rootx() - archive_bounds[0],
                            report_tree.winfo_rooty() - archive_bounds[1],
                            report_tree.winfo_width(), report_tree.winfo_height(),
                        ],
                        "action_geometry": archive_actions,
                    })
                    report_dialog.destroy()
                header_bars = app._app_header.links.winfo_children()
                header_controls = (
                    header_bars[0].winfo_children() if header_bars else []
                )
                header_address_widget = (
                    header_controls[0] if header_controls else None
                )
                header_brand = app._app_header.brand
                brand_top = header_brand.winfo_rooty()
                brand_bottom = brand_top + header_brand.winfo_height()
                link_top = header_address_widget.winfo_rooty() if header_address_widget else 0
                link_bottom = link_top + (
                    header_address_widget.winfo_height() if header_address_widget else 0
                )
                if workspace == "photos" and any(
                    not button.winfo_viewable()
                    for button in app.photos_workspace._card_format_buttons.values()
                ):
                    raise RuntimeError("Tk card-ratio captions are not all visible.")
                if workspace == "photos":
                    expected_captions = [
                        app.photos_workspace._text("card_format_default")
                        if ratio == "default" else ratio
                        for ratio in PHOTO_CARD_FORMATS
                    ]
                    observed_captions = [
                        str(button.cget("text")) for button in
                        app.photos_workspace._card_format_buttons.values()
                    ]
                    if observed_captions != expected_captions:
                        raise RuntimeError(
                            f"Tk visible ratio text differs: {observed_captions}."
                        )
                    if any(
                        str(button.cget("compound")).lower() != "top"
                        or not str(button.cget("image"))
                        or button.winfo_height() < button.winfo_reqheight()
                        for button in app.photos_workspace._card_format_buttons.values()
                    ):
                        raise RuntimeError(
                            "Tk ratio icons and their visible labels are not paired."
                        )
                regions = [{
                    "name": f"workspace-{workspace}",
                    "geometry": [page.winfo_rootx() - bounds[0],
                                 page.winfo_rooty() - bounds[1],
                                 page.winfo_width(), page.winfo_height()],
                }]
                captures.append({
                    "size_name": size_name, "workspace": workspace,
                    "image": filename, "requested_size": requested_size,
                    "selected_page": selected_page,
                    "requested_window_state": (
                        "maximized" if size_name == "maximized" else "normal"
                    ),
                    "actual_window_state": actual_window_state,
                    "max_reference_client_size": max_reference_client_size,
                    "observed_client_size": [bounds[2], bounds[3]],
                    "image_size": list(image.size),
                    "image_pixel_sha256": visual_image_digest(output / filename),
                    "capture_pixel_scale": [
                        image.width / bounds[2], image.height / bounds[3],
                    ],
                    "workspace_visible": active_surface_visible,
                    "lightbox_visible": lightbox_visible,
                    "visible_photo_count": (
                        sum(image is not None for image in controller._photo_images)
                        if workspace.startswith("photos") else None
                    ),
                    "photo_grid": ({
                        "viewport_width": controller.grid_canvas.winfo_width(),
                        "columns": controller._grid_columns,
                        "first_row_cards": min(VISUAL_PHOTO_COUNT, controller._grid_columns),
                        "heading_stacked": app.photos_workspace._heading_stacked,
                    } if workspace == "photos" else None),
                    "regions": regions,
                    "actions": actions,
                    "fixture_state": (
                        "linked-filled-gallery" if workspace == "photos" else
                        "linked-lightbox" if workspace == "photos-lightbox" else None
                    ),
                    "fixture_gallery_id": (
                        VISUAL_GALLERY_ID
                        if workspace in ("photos", "photos-lightbox") else None
                    ),
                    "fixture_photo_ids": (
                        list(range(VISUAL_PHOTO_COUNT, 0, -1))
                        if workspace in ("photos", "photos-lightbox") else []
                    ),
                    "ratio_captions": (
                        [str(button.cget("text")) for button in
                         app.photos_workspace._card_format_buttons.values()]
                        if workspace == "photos" else []
                    ),
                    "ratio_button_layout": (
                        [
                            {
                                "compound": str(button.cget("compound")),
                                "label_visible": bool(
                                    button.winfo_viewable()
                                    and str(button.cget("text"))
                                ),
                            }
                            for button in app.photos_workspace._card_format_buttons.values()
                        ] if workspace == "photos" else []
                    ),
                    "selected_ratio": (
                        controller._card_format if workspace == "photos" else None
                    ),
                    "selected_photo_ids": (
                        sorted(controller._selected_photo_ids)
                        if workspace in ("photos", "photos-lightbox") else []
                    ),
                    "supplemental_images": supplemental_images,
                    "latest_activity_text": app.latest_activity_var.get(),
                    "header_link_address": str(
                        app.linking_manager.linked_address.get()
                    ),
                    "header_link_visible": bool(
                        header_address_widget is not None
                        and header_address_widget.winfo_viewable()
                    ),
                    "tagline_visible": app._app_header.tagline.winfo_viewable(),
                    "visible_header_link_caption": (
                        str(header_address_widget.cget("text"))
                        if header_address_widget is not None else ""
                    ),
                    "header_link_inline": brand_top < link_bottom and link_top < brand_bottom,
                    "header_brand_and_link_rows": [brand_top, brand_bottom, link_top, link_bottom],
                    "watcher_fixture_state": (
                        app.flights.session_state() if workspace == "watcher" else None
                    ),
                    "watcher_load_error": (
                        redact_text(app.flights.load_error, redact_paths=True)
                        if workspace == "watcher" else ""
                    ),
                    "watcher_lease_owner_state": (
                        "owned" if app.flights.lease is not None else "unavailable"
                    ) if workspace == "watcher" else "",
                    "watcher_action_captions": (
                        [app.flights.progress_buttons[
                            "watch_recovery_resume_pending"
                        ].cget("text")]
                        if watcher_recovery_probe and workspace == "watcher"
                        and "watch_recovery_resume_pending" in app.flights.progress_buttons
                        else [
                            str(action["caption"])
                            for action in actions
                            if any(token in str(action["caption"]).casefold()
                                   for token in ("start", "continue", "resume"))
                        ] if workspace == "watcher" else []
                    ),
                    "watcher_resume_available": (
                        any(
                            any(token in str(action["caption"]).casefold()
                                for token in ("continue", "resume"))
                            for action in actions
                        ) if workspace == "watcher" else None
                    ),
                    "watcher_continue_visible": (
                        bool(
                            app.flights.dialog is not None
                            and app.flights.dialog.winfo_viewable()
                            and "watch_recovery_resume_pending"
                            in app.flights.progress_buttons
                            and app.flights.progress_buttons[
                                "watch_recovery_resume_pending"
                            ].winfo_viewable()
                        ) if watcher_recovery_probe and workspace == "watcher" else None
                    ),
                    "watcher_continue_enabled": (
                        bool(app.flights.progress_buttons[
                            "watch_recovery_resume_pending"
                        ].instate(["!disabled"]))
                        if watcher_recovery_probe and workspace == "watcher"
                        and "watch_recovery_resume_pending" in app.flights.progress_buttons
                        else None
                    ),
                    "watcher_start_action_enabled": (
                        bool(
                            "start" in str(app.watcher_action_button.cget("text")).casefold()
                            and app.watcher_action_button.instate(["!disabled"])
                        ) if watcher_recovery_probe and workspace == "watcher" else None
                    ),
                    "watcher_interrupt_action_enabled": (
                        bool(
                            "force_stop_watch" in app.flights.progress_buttons
                            and app.flights.progress_buttons[
                                "force_stop_watch"
                            ].winfo_viewable()
                            and app.flights.progress_buttons[
                                "force_stop_watch"
                            ].instate(["!disabled"])
                        ) if watcher_recovery_probe and workspace == "watcher" else None
                    ),
                    "watcher_pending_session_ids": (
                        [plan.session_id for plan in app.flights.pending()]
                        if watcher_recovery_probe and workspace == "watcher" else []
                    ),
                    "watcher_target_binding_sha256": (
                        watcher_binding if watcher_recovery_probe
                        and workspace == "watcher" else ""
                    ),
                    "watcher_pending_item_ids": (
                        watcher_pending_queue_evidence(pending_journal_path)[0]
                        if watcher_recovery_probe and workspace == "watcher" else []
                    ),
                    "watcher_queue_unchanged": (
                        watcher_pending_queue_evidence(pending_journal_path)[1]
                        == pending_journal_before
                        if watcher_recovery_probe and workspace == "watcher" else None
                    ),
                    "watcher_probe_actions": (
                        "read-only; no upload or network action invoked"
                        if watcher_recovery_probe and workspace == "watcher" else ""
                    ),
                    "activity_import_summary": (
                        app.activity_job_summary_var.get()
                        if workspace == "activity" else ""
                    ),
                    "activity_selected_job": (
                        str(app.job_history_tree.selection()[0])
                        if workspace == "activity"
                        and app.job_history_tree.selection() else None
                    ),
                    "activity_history_row_count": (
                        len(app.job_history_tree.get_children(""))
                        if workspace == "activity" else None
                    ),
                    "activity_event_row_count": (
                        len(app.activity_drawer.get_children(""))
                        if workspace == "activity" else None
                    ),
                })
                if workspace == "flight" and app.flights.dialog is not None:
                    app.flights.dialog.withdraw()
                if workspace == "photos-lightbox":
                    if lightbox is not None:
                        lightbox.dismiss()
                        wait_tk(root, lambda: (
                            not lightbox.visible
                            and lightbox._overlay.winfo_manager() == ""
                        ))
                        app.photos_workspace._clear_photo_inspector()
        for scenario in VISUAL_SCENARIO_WORKSPACES:
            if scenario not in ("photos-disconnected", "photos-connecting"):
                try:
                    captures.append(capture_tk_linked_variant(
                        app, profile, output, scenario,
                    ))
                except Exception as error:
                    capture_failures.append({
                        "workspace": scenario,
                        "exception_type": type(error).__name__,
                        "message": str(error),
                    })
        app.close()
        root.update_idletasks()
        return {
            "backend": "tk", "app_version": MAIN.APP_VERSION,
            "locale": language,
            "localized_probe_strings": {
                "settings": gettext("Settings"),
                "settings_action": gettext("✓  Save settings"),
                "default_ratio": tr(language, "photos_workspace_card_format_default"),
                "connect_action": tr(language, "linking_start"),
            },
            "status": "FAIL" if capture_failures else "PASS",
            "capture_failures": capture_failures,
            "source_identity": os.environ.get(
                "ISSUE_445_SHA", os.environ.get("GITHUB_SHA", ""),
            ),
            "seed_profile_sha256": os.environ["PHP_GALLERY_VISUAL_SEED_SHA256"],
            "workspaces": list(VISUAL_WORKSPACES), "display": display,
            "scenario_workspaces": list(VISUAL_SCENARIO_WORKSPACES),
            "size_scope": [list(item) for item in sizes], "captures": captures,
            "fixture_adapters": (
                "Synthetic read-only LinkingSession supplies identical gallery IDs, "
                "photo IDs and generated JPEG bytes through the production Tk viewer; "
                "no network or write actions are invoked."
            ),
            "fixture_scope": {
                "gallery_id": VISUAL_GALLERY_ID,
                "photo_ids": list(range(VISUAL_PHOTO_COUNT, 0, -1)),
                "media_sha256": [
                    sha256((profile / "visual-photos" / f"visual-{index:02d}.jpg")
                           .read_bytes()).hexdigest()
                    for index in range(1, VISUAL_PHOTO_COUNT + 1)
                ],
                "network_access": False,
                "write_actions": False,
                "gallery_url_configured": watcher_recovery_probe,
                "api_key_configured": watcher_recovery_probe,
                "watcher_recovery_target_type": (
                    "normalized-legacy-profile-binding"
                    if watcher_recovery_probe else None
                ),
            },
            "watcher_recovery_target_type": (
                "normalized-legacy-profile-binding"
                if watcher_recovery_probe else None
            ),
        }
    finally:
        patches.close()


def capture_tk_linked_variant(
    app: MAIN.WatcherApp, profile: Path, output: Path, scenario: str,
) -> dict[str, JsonValue]:
    """Capture actual linked catalogue, selection, metadata and Music states.

    Args:
        app (MAIN.WatcherApp): Production shell with the synthetic gallery mounted.
        profile (Path): Current child profile for generated fixture media.
        output (Path): Directory receiving the native screenshot.
        scenario (str): One supported linked Photos case.
    Returns:
        dict[str, JsonValue]: Matched fixture state and native screenshot metadata.
    Raises:
        RuntimeError: The requested controls did not reach their expected state.
        ValueError: Scenario is not a supported linked Photos case.
    """
    if scenario not in (
        "photos-ratio-16-9", "photos-selected", "photos-multiselected",
        "photos-linked-empty", "photos-hierarchy", "photos-lightbox-fullscreen",
        "photos-info", "photos-context-menu", "photos-info-unavailable",
        "photos-delete-confirmation", "photos-folder-shelf",
        "photos-music-panel",
    ):
        raise ValueError("Unsupported Tk Photos visual scenario.")
    root = app.root
    size_name, width, height = VISUAL_SIZES[0]
    root.state("normal")
    place_tk_capture_window(root, width, height)
    app.select_tab("photos")
    settle_tk(root)
    workspace = app.photos_workspace
    fixture_gallery_ids = [VISUAL_GALLERY_ID]
    if scenario in (
        "photos-linked-empty", "photos-hierarchy", "photos-lightbox-fullscreen",
        "photos-info", "photos-context-menu", "photos-info-unavailable",
        "photos-delete-confirmation", "photos-folder-shelf",
        "photos-music-panel",
    ):
        fixture_shape = (
            "empty" if scenario == "photos-linked-empty" else
            "hierarchy" if scenario in ("photos-hierarchy", "photos-folder-shelf") else
            "admin" if scenario == "photos-delete-confirmation" else "filled"
        )
        fixture_session, fixture_galleries, _fixture_photos, _first_body = (
            visual_linking_session(profile, fixture_shape)
        )
        if scenario == "photos-music-panel":
            fixture_session.can_read_presentation.return_value = True
            fixture_session.media_presentation.return_value = LinkedPresentation(
                False, False, 5, "medium", False, (),
            )
        if scenario == "photos-info-unavailable":
            fixture_session.media_photo_info.side_effect = OSError(
                "synthetic metadata failure",
            )
        fixture_gallery_ids = [gallery.gallery_id for gallery in fixture_galleries]
        workspace._load_selected_gallery = lambda _session: VISUAL_GALLERY_ID
        workspace._load_tree_state = lambda _session: (
            {VISUAL_GALLERY_ID: True} if fixture_shape == "hierarchy" else {}
        )
        workspace._load_photo_order = lambda _session, _gallery: "newest"
        app.linking_manager._show_link(DesktopLinkedSite(
            VISUAL_PHOTOS_ISSUER, fixture_session, VISUAL_PHOTOS_ACCOUNT,
        ))
        workspace.refresh_session()
        wait_tk(root, lambda: (
            not workspace._catalogue_loading
            and set(workspace._galleries) == set(fixture_gallery_ids)
            and workspace._selected_gallery_id == VISUAL_GALLERY_ID
            and workspace._viewer is not None
        ))
    viewer = workspace._viewer
    if viewer is None:
        raise RuntimeError("Tk synthetic Photos viewer was not mounted.")
    controller = viewer._gallery_viewer_controller
    confirmation_text = ""
    confirmation_actions: list[dict[str, JsonValue]] = []
    confirmation_capture: tuple[str, list[int], Image.Image] | None = None
    confirmation_region: list[int] = []
    folder_cards: list[dict[str, JsonValue]] = []
    folder_tree_child_ids: list[int] = []
    folder_section_visible = False
    folder_controls_visible = False
    photo_controls_visible = False
    empty_gallery_message = ""
    folder_selected_ratio = "default"
    music_panel_text = ""
    music_panel_geometry: list[int] | None = None
    supplemental_images: list[dict[str, JsonValue]] = []
    if scenario == "photos-linked-empty":
        empty_caption = controller._text('linking_media_empty')
        wait_tk(root, lambda: (
            not controller._photos and not controller._photo_images
            and controller.status.winfo_viewable() and controller.status.cget('text') == empty_caption
        ))
        empty_gallery_message = str(controller.status.cget('text'))
        photo_controls_visible = any(
            button.winfo_viewable() for button in workspace._card_format_buttons.values()
        )
    elif scenario == "photos-hierarchy":
        wait_tk(root, lambda: len(controller._photos) == VISUAL_PHOTO_COUNT)
        child_ids = workspace.tree.get_children(str(VISUAL_GALLERY_ID))
        if set(map(int, child_ids)) != {
            VISUAL_CHILD_GALLERY_ID, VISUAL_SECOND_CHILD_GALLERY_ID,
        }:
            raise RuntimeError(
                f"Tk hierarchy fixture did not show both child galleries: {child_ids}."
            )
    elif scenario == "photos-folder-shelf":
        shelf = workspace._folder_shelf
        expected_child_ids = {
            VISUAL_CHILD_GALLERY_ID, VISUAL_SECOND_CHILD_GALLERY_ID,
        }
        wait_tk(root, lambda: (
            shelf.winfo_viewable()
            and set(shelf._tiles) == expected_child_ids
            and set(shelf._covers) == expected_child_ids
            and set(map(int, workspace.tree.get_children(str(VISUAL_GALLERY_ID))))
            == expected_child_ids
        ), "two visible Tk folder cards, exact child IDs and decoded covers")
        if not workspace._subgallery_switch.instate(["selected"]):
            raise RuntimeError("Tk Photos subgallery control is not enabled for the fixture.")
        if not any(button.winfo_viewable() for button in workspace._folder_format_buttons.values()):
            raise RuntimeError("Tk folder format strip is not visible in the mixed view.")
        workspace._folder_format_buttons["16:9"].invoke()
        wait_tk(root, lambda: workspace._folder_views.get(
            VISUAL_GALLERY_ID, ("default", "oldest"),
        )[0] == "16:9")
        wait_tk(root, lambda: set(shelf._covers) == expected_child_ids)
        folder_selected_ratio = "16:9"
        folder_cards = [
            {
                "gallery_id": gallery_id,
                "title": shelf._titles[gallery_id].get(),
                "cover_visible": (
                    gallery_id in shelf._covers and shelf._covers[gallery_id] is not None
                ),
            }
            for gallery_id in sorted(shelf._tiles)
        ]
        folder_tree_child_ids = sorted(expected_child_ids)
        folder_section_visible = True
        folder_controls_visible = True
        photo_controls_visible = any(
            button.winfo_viewable()
            for button in workspace._card_format_buttons.values()
        )
    elif scenario == "photos-music-panel":
        wait_tk(root, lambda: len(controller._photos) == VISUAL_PHOTO_COUNT)
        if not controller.music_button.winfo_viewable():
            raise RuntimeError("Tk Music trigger is not visible in the linked Photos view.")
        controller.music_button.invoke()
        wait_tk(root, lambda: (
            controller._music_panel is not None
            and controller._music_panel.winfo_viewable()
        ), "native Tk Music panel visibility")
        assert controller._music_panel is not None
        music_panel_text = " ".join(
            tk_widget_text(widget)
            for widget in descendants_tk(controller._music_panel)
            if tk_widget_text(widget)
        )
        if not music_panel_text.strip():
            raise RuntimeError("Tk Music panel has no visible status text.")
        music_panel_geometry = [
            controller._music_panel.winfo_rootx() - root.winfo_rootx(),
            controller._music_panel.winfo_rooty() - root.winfo_rooty(),
            controller._music_panel.winfo_width(), controller._music_panel.winfo_height(),
        ]
        music_filename = f"tk-{size_name}-photos-music-panel.png"
        panel_bounds = (
            controller._music_panel.winfo_rootx(), controller._music_panel.winfo_rooty(),
            controller._music_panel.winfo_rootx() + controller._music_panel.winfo_width(),
            controller._music_panel.winfo_rooty() + controller._music_panel.winfo_height(),
        )
        panel_image = ImageGrab.grab(bbox=panel_bounds)
        panel_image.save(output / music_filename)
        supplemental_images.append({
            "name": "music-panel", "image": music_filename,
            "desktop_bounds": list(panel_bounds),
            "image_size": list(panel_image.size),
        })
    elif scenario == "photos-lightbox-fullscreen":
        wait_tk(root, lambda: (
            len(controller._photos) == VISUAL_PHOTO_COUNT
            and any(photo is not None for photo in controller._photo_images)
        ), "linked rows and a decoded thumbnail before Lightbox open")
        controller._open_grid_photo(0)
        wait_tk(root, lambda: (
            controller._lightbox is not None
            and controller._lightbox.visible
            and controller._lightbox._photo is not None
        ), "visible Lightbox with its decoded fixture image")
        assert controller._lightbox is not None
        controller._lightbox._toggle_fullscreen()
        wait_tk(root, lambda: controller._lightbox._fullscreen)
    elif scenario == "photos-info":
        wait_tk(root, lambda: (
            len(controller._photos) == VISUAL_PHOTO_COUNT
            and bool(controller._photos)
            and any(photo is not None for photo in controller._photo_images)
            and workspace._selected_gallery_id == VISUAL_GALLERY_ID
        ), "linked catalogue rows, decoded tile and selected gallery before metadata")
        first_photo = controller._photos[0]
        if not controller.select_photo(0):
            raise RuntimeError(
                "Tk metadata fixture could not select loaded photo "
                f"{first_photo.image_id}; rows={len(controller._photos)}, "
                f"images={len(controller._photo_images)}, "
                f"selected_gallery={workspace._selected_gallery_id}.",
            )
        if controller._selected_photo_ids != {first_photo.image_id}:
            raise RuntimeError(
                "Tk metadata selection owner did not retain fixture photo "
                f"{first_photo.image_id}: "
                f"{sorted(controller._selected_photo_ids)}.",
            )
        expected_title = f"Visual fixture {controller._photos[0].image_id}"
        wait_tk(root, lambda: (
            workspace._photo_inspector_visible
            and workspace._photo_inspector_heading.cget("text") == expected_title
            and bool(workspace._photo_inspector_content.winfo_children())
        ), "photo selection and real metadata inspector")
    elif scenario == "photos-info-unavailable":
        wait_tk(root, lambda: (
            len(controller._photos) == VISUAL_PHOTO_COUNT
            and bool(controller._photos)
            and any(photo is not None for photo in controller._photo_images)
            and workspace._selected_gallery_id == VISUAL_GALLERY_ID
        ), "linked catalogue rows, decoded tile and selected gallery before metadata error")
        first_photo = controller._photos[0]
        if not controller.select_photo(0):
            raise RuntimeError(
                "Tk metadata error fixture could not select loaded photo "
                f"{first_photo.image_id}; rows={len(controller._photos)}, "
                f"images={len(controller._photo_images)}, "
                f"selected_gallery={workspace._selected_gallery_id}.",
            )
        if controller._selected_photo_ids != {first_photo.image_id}:
            raise RuntimeError(
                "Tk metadata error selection owner did not retain fixture "
                f"photo {first_photo.image_id}: "
                f"{sorted(controller._selected_photo_ids)}.",
            )
        fallback = workspace._text("details_fallback")
        wait_tk(root, lambda: (
            workspace._photo_inspector_visible
            and fallback in workspace._photo_inspector_summary.cget("text")
        ), "safe metadata fallback after synthetic read failure")
    elif scenario == "photos-context-menu":
        wait_tk(root, lambda: (
            len(controller._photos) == VISUAL_PHOTO_COUNT
            and any(photo is not None for photo in controller._photo_images)
            and workspace._selected_gallery_id == VISUAL_GALLERY_ID
        ), "linked rows and decoded tile before opening the context card")
        canvas = controller.grid_canvas
        event = tk.Event()
        event.widget = canvas
        event.x_root = canvas.winfo_rootx() + max(1, canvas.winfo_width() // 2)
        event.y_root = canvas.winfo_rooty() + max(1, canvas.winfo_height() // 2)
        controller._on_photo_context(0, event)
        settle_tk(root)
        card = workspace._context_card
        if card is None or not card.winfo_viewable():
            raise RuntimeError("Tk production photo context card did not open.")
    elif scenario == "photos-delete-confirmation":
        wait_tk(root, lambda: (
            len(controller._photos) == VISUAL_PHOTO_COUNT
            and len(controller._photo_images) == VISUAL_PHOTO_COUNT
            and all(photo is not None for photo in controller._photo_images)
            and workspace._selected_gallery_id == VISUAL_GALLERY_ID
        ), "all linked thumbnails decoded before opening delete confirmation")
        canvas = controller.grid_canvas
        event = tk.Event()
        event.widget = canvas
        event.x_root = canvas.winfo_rootx() + max(1, canvas.winfo_width() // 2)
        event.y_root = canvas.winfo_rooty() + max(1, canvas.winfo_height() // 2)
        controller._on_photo_context(0, event)
        wait_tk(root, lambda: workspace._context_card is not None)
        delete_caption = workspace._text("delete_photo")
        delete_action = next((
            widget for widget in descendants_tk(workspace._context_card)
            if isinstance(widget, ttk.Button)
            and str(widget.cget("text")) == delete_caption
        ), None)
        if delete_action is None:
            raise RuntimeError("Tk admin fixture did not expose delete confirmation action.")
        delete_action.invoke()
        cancel_caption = workspace._text("cancel")
        wait_tk(root, lambda: any(
            isinstance(widget, ttk.Button)
            and str(widget.cget("text")) == cancel_caption
            for widget in descendants_tk(workspace._context_card)
        ), "production photo delete confirmation card")
        confirmation_text = " ".join(
            str(widget.cget("text")) for widget in descendants_tk(workspace._context_card)
            if isinstance(widget, ttk.Label)
        )
        if not confirmation_text or "Synthetic visual gallery" not in confirmation_text:
            raise RuntimeError(
                f"Tk delete confirmation omitted its gallery scope: {confirmation_text!r}."
            )
        settle_tk(root)
        confirmation_bounds = [
            root.winfo_rootx(), root.winfo_rooty(),
            root.winfo_width(), root.winfo_height(),
        ]
        confirmation_region = [
            workspace._context_card.winfo_rootx() - confirmation_bounds[0],
            workspace._context_card.winfo_rooty() - confirmation_bounds[1],
            workspace._context_card.winfo_width(),
            workspace._context_card.winfo_height(),
        ]
        filename = f"tk-{size_name}-{scenario}.png"
        confirmation_image = ImageGrab.grab(bbox=(
            confirmation_bounds[0], confirmation_bounds[1],
            confirmation_bounds[0] + confirmation_bounds[2],
            confirmation_bounds[1] + confirmation_bounds[3],
        ))
        confirmation_image.save(output / filename)
        confirmation_actions = [
            {
                "class": widget.winfo_class(), "caption": tk_widget_text(widget),
                "geometry": [
                    widget.winfo_rootx() - confirmation_bounds[0],
                    widget.winfo_rooty() - confirmation_bounds[1],
                    widget.winfo_width(), widget.winfo_height(),
                ],
            }
            for widget in sorted(
                descendants_tk(workspace._context_card), key=lambda child: child.winfo_rooty(),
            )
            if widget.winfo_viewable() and widget.winfo_class() in ("Button", "TButton")
        ]
        confirmation_capture = (filename, confirmation_bounds, confirmation_image)
        cancel_action = next(
            widget for widget in descendants_tk(workspace._context_card)
            if isinstance(widget, ttk.Button)
            and str(widget.cget("text")) == cancel_caption
        )
        cancel_action.invoke()
        settle_tk(root)
        if fixture_session.delete_media_photos.called:
            raise RuntimeError("Tk cancel confirmation issued a photo deletion write.")
    elif scenario == "photos-ratio-16-9":
        controller.clear_photo_selection(tuple(controller._selected_photo_ids))
        workspace._clear_photo_inspector()
        workspace._card_format_buttons["16:9"].invoke()
        wait_tk(root, lambda: controller._card_format == "16:9")
    elif scenario == "photos-selected":
        workspace._card_format_buttons["default"].invoke()
        controller.clear_photo_selection(tuple(controller._selected_photo_ids))
        if not controller.select_photo(0):
            raise RuntimeError("Tk single-selection fixture did not select one photo.")
        workspace._clear_photo_inspector()
    else:
        workspace._card_format_buttons["default"].invoke()
        controller.clear_photo_selection(tuple(controller._selected_photo_ids))
        targets = tuple(photo.image_id for photo in controller._photos[:2])
        if len(targets) != 2 or not all(
            controller.toggle_photo_selection(image_id) for image_id in targets
        ):
            raise RuntimeError("Tk multi-selection fixture did not select two photos.")
        workspace._clear_photo_inspector()
    settle_tk(root)
    page = app.tab_hosts["photos"]
    lightbox = controller._lightbox
    fullscreen = scenario == "photos-lightbox-fullscreen"
    lightbox_visible = bool(lightbox is not None and lightbox.visible)
    lightbox_ready = bool(
        fullscreen and lightbox_visible and lightbox is not None
        and lightbox._fullscreen and lightbox._overlay.winfo_viewable()
        and lightbox._photo is not None
    )
    lightbox_cleared = (
        lightbox is None
        or (
            not lightbox.visible
            and lightbox._overlay.winfo_manager() == ""
        )
    )
    context_card = (
        workspace._context_card if scenario == "photos-context-menu" else None
    )
    context_visible = bool(
        context_card is not None and context_card.winfo_viewable()
    )
    if (fullscreen and not lightbox_ready) or (
        not fullscreen and (
            not page.winfo_viewable() or not lightbox_cleared
            or (scenario == "photos-context-menu" and not context_visible)
        )
    ):
        raise RuntimeError(f"Tk Photos scenario is not unobscured: {scenario}.")
    capture_surface = (
        lightbox._overlay if fullscreen and lightbox is not None else
        context_card if context_visible else root
    )
    lightbox_controls: tuple[tk.Misc, ...] = ()
    if fullscreen and lightbox is not None:
        lightbox_controls = (lightbox._toolbar, lightbox._previous_edge, lightbox._next_edge)
        wait_tk(root, lambda: not any(control.winfo_viewable() for control in lightbox_controls),
                "all fullscreen Lightbox controls hidden after idle")
    if confirmation_capture is None:
        bounds = [capture_surface.winfo_rootx(), capture_surface.winfo_rooty(),
                  capture_surface.winfo_width(), capture_surface.winfo_height()]
        filename = f"tk-{size_name}-{scenario}.png"
        rectangle = bounds[0], bounds[1], bounds[0] + bounds[2], bounds[1] + bounds[3]
        if fullscreen and sys.platform == 'darwin':
            image = capture_stable_desktop_pixels(root.update, rectangle)
        else:
            image = ImageGrab.grab(bbox=rectangle)
        image.save(output / filename)
    else:
        filename, bounds, image = confirmation_capture
    actions = []
    for widget in descendants_tk(capture_surface):
        if widget.winfo_viewable() and widget.winfo_class() in (
            "Button", "TButton", "Checkbutton", "TCheckbutton", "Radiobutton",
        ):
            actions.append({
                "class": widget.winfo_class(), "caption": tk_widget_text(widget),
                "geometry": [widget.winfo_rootx() - bounds[0],
                             widget.winfo_rooty() - bounds[1],
                             widget.winfo_width(), widget.winfo_height()],
            })
    if confirmation_actions:
        actions = confirmation_actions
    idle_chrome_hidden = bool(lightbox_controls) and not any(
        control.winfo_viewable() for control in lightbox_controls
    )
    chrome_restored = False
    if fullscreen and lightbox is not None:
        canvas = lightbox._canvas
        canvas.event_generate('<Motion>', x=canvas.winfo_width() // 2, y=canvas.winfo_height() // 2)
        wait_tk(root, lambda: all(control.winfo_viewable() for control in lightbox_controls),
                "fullscreen Lightbox controls restored by pointer motion")
        settle_tk(root)
        chrome_restored = all(control.winfo_viewable() for control in lightbox_controls)
        active_filename = f"tk-{size_name}-{scenario}-active.png"
        with ImageGrab.grab(bbox=(
            bounds[0], bounds[1], bounds[0] + bounds[2], bounds[1] + bounds[3],
        )) as active_frame:
            active_frame.save(output / active_filename)
            supplemental_images.append({
                "name": "fullscreen-active-controls", "image": active_filename,
                "desktop_bounds": bounds, "image_size": list(active_frame.size),
            })
    selected_page = next(
        (name for name, index in app.tab_indices.items()
         if index == app.notebook.index(app.notebook.select())),
        "",
    )
    if selected_page != "photos":
        raise RuntimeError(
            f"Tk linked Photos tab mismatch for {scenario}: actual={selected_page}."
        )
    photo_info_visible = False
    photo_info_geometry: list[int] = []
    photo_info_text = ""
    photo_info_summary = ""
    metadata_failure_visible = False
    if scenario in ("photos-info", "photos-info-unavailable"):
        inspector = workspace._photo_inspector_content
        inspector_origin_x = inspector.winfo_rootx() - bounds[0]
        inspector_origin_y = inspector.winfo_rooty() - bounds[1]
        photo_info_visible = bool(
            workspace._photo_inspector_visible and inspector.winfo_viewable()
        )
        photo_info_geometry = [
            inspector_origin_x, inspector_origin_y,
            inspector.winfo_width(), inspector.winfo_height(),
        ]
        photo_info_text = "\n".join(
            tk_widget_text(widget)
            for widget in descendants_tk(workspace._photo_inspector_content)
            if tk_widget_text(widget)
        )
        photo_info_summary = str(
            workspace._photo_inspector_summary.cget("text")
        )
        metadata_failure_visible = (
            scenario == "photos-info-unavailable"
            and workspace._text("details_fallback")
            in workspace._photo_inspector_summary.cget("text")
        )
    if context_visible:
        workspace._dismiss_context()
    if scenario in ("photos-info", "photos-info-unavailable"):
        workspace._clear_photo_inspector()
    folder_ratio_layout: list[dict[str, JsonValue]] = []
    folder_strip_geometry: list[int] = []
    if scenario == "photos-folder-shelf":
        strip = workspace._folder_format_controls
        folder_strip_geometry = [
            strip.winfo_rootx() - bounds[0], strip.winfo_rooty() - bounds[1],
            strip.winfo_width(), strip.winfo_height(),
        ]
        device_scale_y = image.height / bounds[3]
        for ratio, button in workspace._folder_format_buttons.items():
            icon = workspace._card_format_images[(ratio, False)]
            icon_size = [icon.width(), icon.height()] if icon is not None else [0, 0]
            style = ttk.Style(button)
            button_font = style.lookup(button.cget("style"), "font")
            text_height = int(tkfont.Font(
                root=root, font=button_font or "TkDefaultFont",
            ).metrics("linespace"))
            origin_x = button.winfo_rootx() - strip.winfo_rootx()
            origin_y = button.winfo_rooty() - strip.winfo_rooty()
            control_width, control_height = button.winfo_width(), button.winfo_height()
            required_height = button.winfo_reqheight()
            folder_ratio_layout.append({
                "caption": str(button.cget("text")),
                "compound": str(button.cget("compound")),
                "icon_visible": bool(button.cget("image")),
                "control_geometry": [
                    origin_x, origin_y, control_width, control_height,
                ],
                "control_requested_height": required_height,
                "icon_size": icon_size,
                "text_height": text_height,
                "strip_size": [strip.winfo_width(), strip.winfo_height()],
                "control_device_height_px": control_height * device_scale_y,
                "icon_device_height_px": icon_size[1] * device_scale_y,
                "text_device_height_px": text_height * device_scale_y,
                "content_fits_actual_height": bool(
                    control_height >= required_height
                    and control_height >= icon_size[1] + text_height
                ),
                "control_fits_strip": bool(
                    origin_x >= 0 and origin_y >= 0
                    and origin_x + control_width <= strip.winfo_width()
                    and origin_y + control_height <= strip.winfo_height()
                ),
            })
    return {
        "size_name": size_name, "workspace": scenario,
        "image": filename, "requested_size": list(VISUAL_SIZES[0][1:]),
        "requested_window_state": "fullscreen" if fullscreen else "normal",
        "actual_window_state": "fullscreen" if fullscreen else "normal",
        "selected_page": selected_page,
        "observed_client_size": bounds[2:], "image_size": list(image.size),
        "image_pixel_sha256": visual_image_digest(output / filename),
        "capture_pixel_scale": [image.width / bounds[2], image.height / bounds[3]],
        "workspace_visible": True, "lightbox_visible": lightbox_visible,
        "visible_photo_count": len(controller._photo_images),
        "fullscreen": bool(lightbox is not None and lightbox._fullscreen),
        "lightbox_idle_chrome_hidden": idle_chrome_hidden,
        "lightbox_chrome_restored_by_motion": chrome_restored,
        "regions": ([{
            "name": "photos-delete-confirmation",
            "geometry": confirmation_region,
        }] if confirmation_region else ([{
            "name": "photosFolderFormats",
            "geometry": folder_strip_geometry,
        }, {
            "name": "photosFolderShelf",
            "geometry": [
                workspace._folder_shelf.winfo_rootx() - bounds[0],
                workspace._folder_shelf.winfo_rooty() - bounds[1],
                workspace._folder_shelf.winfo_width(),
                workspace._folder_shelf.winfo_height(),
            ],
        }] if scenario == "photos-folder-shelf" else [{
            "name": (
                "photos-lightbox" if fullscreen else
                "photos-context-menu" if context_visible else "workspace-photos"
            ),
            "geometry": (
                [0, 0, bounds[2], bounds[3]] if fullscreen or context_visible else
                [page.winfo_rootx() - bounds[0], page.winfo_rooty() - bounds[1],
                 page.winfo_width(), page.winfo_height()]
            ),
        }])),
        "actions": actions, "fixture_gallery_id": VISUAL_GALLERY_ID,
        "fixture_gallery_ids": fixture_gallery_ids,
        "fixture_photo_ids": (
            [] if scenario == "photos-linked-empty"
            else list(range(VISUAL_PHOTO_COUNT, 0, -1))
        ),
        "fixture_state": scenario,
        "gallery_hierarchy": gallery_hierarchy_tk(workspace.tree),
        "folder_cards": folder_cards,
        "folder_tree_child_ids": folder_tree_child_ids,
        "folder_section_visible": folder_section_visible,
        "folder_controls_visible": folder_controls_visible,
        "photo_controls_visible": photo_controls_visible,
        "empty_gallery_message": empty_gallery_message,
        "folder_selected_ratio": folder_selected_ratio,
        "folder_ratio_captions": [
            str(button.cget("text"))
            for button in workspace._folder_format_buttons.values()
        ] if scenario == "photos-folder-shelf" else [],
        "folder_ratio_layout": folder_ratio_layout,
        "folder_strip_geometry": folder_strip_geometry,
        "subgallery_enabled": (
            bool(workspace._subgallery_switch.instate(["selected"]))
            if scenario == "photos-folder-shelf" else False
        ),
        "ratio_captions": [str(button.cget("text")) for button in
                           workspace._card_format_buttons.values()],
        "selected_ratio": controller._card_format,
        "selected_photo_ids": sorted(controller._selected_photo_ids),
        "photo_info_visible": photo_info_visible,
        "photo_info_geometry": photo_info_geometry,
        "photo_info_text": photo_info_text,
        "photo_info_summary": photo_info_summary,
        "photo_info_action": (
            workspace._text("photo_info")
            if scenario == "photos-context-menu" else ""
        ),
        "metadata_failure_visible": metadata_failure_visible,
        "confirmation_visible": confirmation_capture is not None,
        "confirmation_text": confirmation_text,
        "confirmation_cancelled": (
            scenario == "photos-delete-confirmation"
            and not fixture_session.delete_media_photos.called
        ),
        "confirmation_actions": confirmation_actions,
        "confirmation_cancel_caption": (
            workspace._text("cancel")
            if scenario == "photos-delete-confirmation" else ""
        ),
        "write_count": (
            fixture_session.delete_media_photos.call_count
            if scenario == "photos-delete-confirmation" else 0
        ),
        "music_panel_visible": bool(
            scenario == "photos-music-panel"
            and controller._music_panel is not None
            and controller._music_panel.winfo_viewable()
        ),
        "music_panel_text": music_panel_text,
        "music_panel_geometry": music_panel_geometry,
        "supplemental_images": supplemental_images,
        "header_link_address": str(
            app.linking_manager.linked_address.get()
        ),
    }


def descendants_tk(root: tk.Misc) -> tuple[tk.Misc, ...]:
    """Return a stable depth-first list of actual Tk descendants.

    Args:
        root (tk.Misc): Native Tk root or widget with children.
    Returns:
        tuple[tk.Misc, ...]: All currently constructed descendants.
    """
    result = []
    pending = list(root.winfo_children())
    while pending:
        widget = pending.pop()
        result.append(widget)
        pending.extend(widget.winfo_children())
    return tuple(result)


def gallery_hierarchy_tk(tree: ttk.Treeview) -> list[dict[str, JsonValue]]:
    """Return displayed Tk gallery titles and their nesting depths.

    Args:
        tree (ttk.Treeview): Production gallery tree for the linked fixture.
    Returns:
        list[dict[str, JsonValue]]: Visible hierarchy in display order.
    """
    rows: list[dict[str, JsonValue]] = []
    pending = [(item, 0) for item in reversed(tree.get_children(""))]
    while pending:
        item, depth = pending.pop()
        rows.append({"title": tree.item(item, "text"), "depth": depth})
        if tree.item(item, "open"):
            pending.extend(
                (child, depth + 1)
                for child in reversed(tree.get_children(item))
            )
    return rows


def gallery_hierarchy_qt(tree: QTreeWidget) -> list[dict[str, JsonValue]]:
    """Return displayed Qt gallery titles and their nesting depths.

    Args:
        tree (QTreeWidget): Production gallery tree for the linked fixture.
    Returns:
        list[dict[str, JsonValue]]: Visible hierarchy in display order.
    """
    rows: list[dict[str, JsonValue]] = []
    pending = [
        (tree.topLevelItem(index), 0)
        for index in reversed(range(tree.topLevelItemCount()))
    ]
    while pending:
        item, depth = pending.pop()
        rows.append({"title": item.text(0), "depth": depth})
        if item.isExpanded():
            pending.extend(
                (item.child(index), depth + 1)
                for index in reversed(range(item.childCount()))
            )
    return rows


def settle_tk(root: tk.Misc) -> None:
    """Pump real Tk events and idle geometry for the shared settle interval.

    Args:
        root (tk.Misc): Native Tk root owning the actual application window.
    Returns:
        None: The window has processed layout and presentation callbacks.
    """
    deadline = time.monotonic() + VISUAL_SETTLE_SECONDS
    while time.monotonic() < deadline:
        root.update()
        time.sleep(VISUAL_PUMP_DELAY_SECONDS)


def capture_stable_desktop_pixels(
    pump: Callable[[], None], bounds: tuple[int, int, int, int],
) -> Image.Image:
    """Wait for native fullscreen composition to settle after geometry is reported.

    Args:
        pump (Callable[[], None]): Event processor for the real fullscreen frontend.
        bounds (tuple[int, int, int, int]): Desktop region in screen coordinates.
    Returns:
        Image.Image: Final native frame, held for the standard settle interval.
    Raises:
        RuntimeError: The compositor did not settle within the existing test budget.
    """
    deadline = time.monotonic() + VISUAL_OPERATION_TIMEOUT_SECONDS
    stable_since = deadline
    previous: bytes | None = None
    while time.monotonic() < deadline:
        pump()
        frame = ImageGrab.grab(bbox=bounds)
        pixels = frame.tobytes()
        now = time.monotonic()
        if pixels != previous:
            previous = pixels
            stable_since = now
        elif now - stable_since >= VISUAL_SETTLE_SECONDS:
            return frame
        frame.close()
        time.sleep(VISUAL_PUMP_DELAY_SECONDS)
    raise RuntimeError('Fullscreen desktop pixels did not settle.')


def wait_tk(
    root: tk.Misc, predicate: Callable[[], bool], phase: str = "requested state",
) -> None:
    """Pump Tk until one synthetic fixture transition is visibly owned.

    Args:
        root (tk.Misc): Native Tk root owning the actual application window.
        predicate (Callable[[], bool]): Current state condition to observe.
        phase (str): Short fixture transition being observed for diagnostics.
    Returns:
        None: The transition completed or raises a bounded hosted failure.
    Raises:
        RuntimeError: The production owner did not reach the expected state.
    """
    deadline = time.monotonic() + VISUAL_OPERATION_TIMEOUT_SECONDS
    while time.monotonic() < deadline:
        root.update()
        if predicate():
            return
        time.sleep(VISUAL_PUMP_DELAY_SECONDS)
    raise RuntimeError(f"Tk visual fixture did not reach {phase}.")


def capture_qt(profile: Path, output: Path) -> dict[str, JsonValue]:
    """Capture the complete production Qt shell at matching client sizes.

    Args:
        profile (Path): Current child profile cloned from the shared seed.
        output (Path): Directory receiving native PNG captures.
    Returns:
        dict[str, JsonValue]: Display metrics, workspaces and observed controls.
    """
    profile = profile.resolve(strict=True)
    if profile_digest(profile) != os.environ["PHP_GALLERY_VISUAL_SEED_SHA256"]:
        raise RuntimeError("Qt child profile differs from the shared visual seed.")
    language = configure_language(load_ui_language(profile))
    app = QApplication([])
    app.setQuitOnLastWindowClosed(False)
    apply_qt_theme(app)
    config_store = MAIN.ConfigStore(profile / "config.json")
    config = config_store.load()
    watcher_recovery_probe = (
        os.environ.get(VISUAL_WATCHER_RECOVERY_PROBE) == "1"
    )
    if not watcher_recovery_probe and (config.gallery_url or config.api_key):
        raise RuntimeError("Synthetic shell profile unexpectedly has a gallery target.")
    recovery_profile_work = None
    recovery_binding = ""
    if watcher_recovery_probe:
        binding = connection_binding(
            MAIN.normalize_upload_url(config.gallery_url), config.api_key.strip(),
        )
        recovery_profile_work = inspect_gui_profile_work(
            profile, legacy_import_binding=binding, capture_binding=binding,
        )
        if recovery_profile_work.blocked or not recovery_profile_work.recoverable_capture:
            raise RuntimeError(
                "Qt profile admission did not verify the seeded pending capture."
            )
        recovery_binding = binding
    mode = MAIN.GuiModeOwner(
        config_store.mode_port(config), lambda: visual_mode_vault(),
    )
    mode.initialize()
    if mode.view().state not in ("legacy", "legacy_unmigrated"):
        raise RuntimeError("Qt visual fixture lacks an admitted legacy target.")
    with (
        mock.patch.object(watcher_state, "CONFIG_DIR", profile),
        mock.patch.object(watcher_state, "STATE_PATH", profile / "upload_state.json"),
        mock.patch.object(watcher_state, "STAGING_ROOT", profile / "staging"),
    ):
        capture = MAIN.DesktopCapture(config_store, lambda: config, mode, lambda: None)
        actions = MAIN.desktop_import_actions(
            config_store, lambda: config, lambda: mode,
        )
    desktop = watcher_configuration.DesktopConfiguration(config_store, config, mode)
    dependencies = MAIN.DependencyActions(
        lambda: runtime.thumbnail_runtime_status(for_display=True),
        mock.Mock(side_effect=AssertionError("Visual fixtures do not repair dependencies.")),
        mock.Mock(side_effect=AssertionError("Visual fixtures do not install AI packages.")),
        False,
    )
    ai_factory = lambda _events: mock.Mock()
    window = QtWindow(
        profile, MAIN.APP_VERSION, config.gallery_url, config.activity_history_limit,
        False, ai_factory=ai_factory, import_actions=actions, capture=capture,
        recoverable_capture=bool(
            recovery_profile_work is not None
            and recovery_profile_work.recoverable_capture
        ),
        runtime_settings=desktop.settings, connections=desktop.connections,
        dependencies=dependencies,
    )
    capture_name_prefix = "watcher-recovery-" if watcher_recovery_probe else ""
    startup_link_requests = mock.Mock(
        side_effect=AssertionError(
            "The blank visual profile must not start a link request."
        ),
    )
    with mock.patch.object(LinkingAttempt, "push_request", startup_link_requests):
        window.show()
        app.processEvents()
        disconnected_capture = (
            capture_qt_disconnected(app, window, output)
            if not watcher_recovery_probe
            and "photos-disconnected" in VISUAL_SCENARIO_WORKSPACES else None
        )
    if not watcher_recovery_probe and (config.gallery_url or config.api_key):
        raise RuntimeError("Synthetic shell profile unexpectedly has a gallery target.")
    if startup_link_requests.call_count:
        raise RuntimeError(
            "Qt startup dispatched a link request from an empty profile."
        )
    tree_path = photos_account_tree_path(
        profile, VISUAL_PHOTOS_ISSUER, VISUAL_PHOTOS_ACCOUNT,
    )
    save_gallery_photo_order(
        tree_path.with_name(tree_path.stem + ".view.json"),
        VISUAL_GALLERY_ID, "newest",
    )
    screen = window.screen() or QGuiApplication.primaryScreen()
    display = {
        "platform": sys.platform,
        "screen_size": [screen.geometry().width(), screen.geometry().height()],
        "dpi": float(screen.logicalDotsPerInch()),
        "dpi_units": "Qt logical dots per inch",
        "device_pixel_ratio": float(screen.devicePixelRatio()),
        "capture_method": "desktop client area" if sys.platform == "win32" else "native window",
    }
    synthetic_scale = os.environ.get("PHP_GALLERY_VISUAL_SYNTHETIC_SCALE")
    if synthetic_scale:
        display["synthetic_scale"] = {
            "requested_factor": float(synthetic_scale),
            "configuration": "QT_SCALE_FACTOR before QApplication initialization",
            "window_device_pixel_ratio": float(window.devicePixelRatio()),
            "native_screen_logical_dpi": float(screen.logicalDotsPerInch()),
            "native_screen_device_pixel_ratio": float(screen.devicePixelRatio()),
            "physical_display_claim": False,
        }
    screen_geometry = screen.geometry()
    sizes = (
        (VISUAL_SIZES[0],) if (
            watcher_recovery_probe or synthetic_scale
        ) else
        VISUAL_CZECH_SIZES
        if language == "cs" and os.environ.get("PHP_GALLERY_VISUAL_LOCALE_PROBE") == "cs"
        else visual_size_matrix(
            screen_geometry.width(), screen_geometry.height(),
        )[2:3]
        if VISUAL_CAPTURE_SLICE_ID == "workspace-wide"
        else VISUAL_SIZES
        if VISUAL_CAPTURE_SLICE_ID != "full"
        else visual_size_matrix(
            screen_geometry.width(), screen_geometry.height(),
        )
    )
    captures: list[dict[str, JsonValue]] = []
    capture_failures: list[dict[str, str]] = []
    standard_client_size: tuple[int, int] | None = None
    pending_journal_path = (
        profile / "flights" / VISUAL_SESSION_ID / "watch-drain.json"
    )
    pending_journal_before = (
        watcher_pending_queue_evidence(pending_journal_path)[1]
        if watcher_recovery_probe else ""
    )
    try:
        if not watcher_recovery_probe:
            if ("photos-disconnected" in VISUAL_SCENARIO_WORKSPACES
                    and disconnected_capture is None):
                raise RuntimeError("Qt disconnected startup capture is unavailable.")
            if disconnected_capture is not None:
                captures.append(disconnected_capture)
            if "photos-connecting" in VISUAL_SCENARIO_WORKSPACES:
                captures.append(capture_qt_connecting(app, window, output))
        for size_name, width, height in sizes:
            visual_session, galleries, visual_photos, _first_body = (
                visual_linking_session(profile)
            )
            photo_rows = tuple(
                PhotoRow(photo.image_id, photo.title, photo)
                for photo in reversed(visual_photos)
            )
            window.showNormal()
            if (size_name == "maximized"
                    and VISUAL_CAPTURE_SLICE_ID == "workspace-maximized"):
                _wide_name, wide_width, wide_height = visual_size_matrix(
                    screen_geometry.width(), screen_geometry.height(),
                )[2]
                window.resize(wide_width, wide_height)
                app.processEvents()
            actual_window_state = "normal"
            max_reference_client_size = (
                [window.width(), window.height()]
                if size_name == "maximized" else None
            )
            if size_name == "maximized":
                screen_geometry = screen.geometry()
                requested_size = [screen_geometry.width(), screen_geometry.height()]
                window.showMaximized()
                app.processEvents()
                expanded = (
                    max_reference_client_size is not None
                    and window.width() * window.height()
                    >= (max_reference_client_size[0] * max_reference_client_size[1]
                        * VISUAL_MAX_AREA_RATIO)
                    and window.height()
                    >= max_reference_client_size[1] * VISUAL_MAX_HEIGHT_RATIO
                )
                if window.isMaximized() and expanded:
                    actual_window_state = "maximized"
                else:
                    window.showNormal()
                    window.setGeometry(
                        screen_geometry.x() + VISUAL_SCREEN_MARGIN // 2,
                        screen_geometry.y() + VISUAL_SCREEN_MARGIN // 2,
                        max(1, screen_geometry.width() - VISUAL_SCREEN_MARGIN),
                        max(1, screen_geometry.height() - VISUAL_SCREEN_MARGIN),
                    )
                    app.processEvents()
                    actual_window_state = "screen-sized-fallback"
            else:
                requested_size = [width, height]
                window.resize(width, height)
            app.processEvents()
            for workspace in VISUAL_WORKSPACES:
                flight_panel: QWidget | None = None
                if workspace.startswith("photos"):
                    window.show_page("photos")
                    if not window.photos.model.rows:
                        window.photos._receive_catalogue(LinkedPhotos(
                            visual_session, VISUAL_PHOTOS_ISSUER, VISUAL_PHOTOS_ACCOUNT,
                            galleries, load_photos_tree_preferences(tree_path),
                        ))
                        wait_qt(app, lambda: window.photos.model.rows == photo_rows)
                    wait_qt(app, lambda: bool(window.photos._tiles))
                    if workspace == "photos-lightbox":
                        window.photos.openPhoto(0)
                        wait_qt(app, lambda: window.photos._detail_display_index == 0)
                elif workspace == "flight":
                    window.show_page("watcher")
                    assert window.capture is not None
                    window.capture.show_progress()
                    flight_panel = window.capture._progress_dialog
                    assert flight_panel is not None
                else:
                    window.show_page(workspace)
                    if watcher_recovery_probe and workspace == "watcher":
                        assert window.capture is not None
                        window.capture.show_progress()
                        flight_panel = window.capture._progress_dialog
                        wait_qt(app, lambda: (
                            flight_panel is not None
                            and flight_panel.isVisible()
                            and window.capture is not None
                            and window.capture._progress_continue.isVisible()
                            and window.capture._progress_continue.isEnabled()
                        ))
                settle_qt(app)
                page_name = (
                    "watcher" if workspace == "flight" else
                    "photos" if workspace == "photos-lightbox" else workspace
                )
                page = window._pages[page_name]
                selected_widget = window.tabs.currentWidget()
                selected_page = selected_widget.objectName().removeprefix("workspace-")
                selected_nav_index = next(
                    index for index, entry in enumerate(window._workspace_labels)
                    if entry[0] == page_name
                )
                if selected_widget is not page or not window._nav_buttons[
                    selected_nav_index
                ].isChecked():
                    raise RuntimeError(
                        f"Qt selected page mismatch for {workspace}/{size_name}: "
                        f"expected={page_name}, actual={selected_page}."
                    )
                workspace_visible = bool(page.isVisibleTo(window))
                if not workspace_visible:
                    raise RuntimeError(
                        f"Qt workspace is not visible: {workspace}/{size_name}."
                    )
                if workspace == "photos" and any(
                    not button.isVisibleTo(window)
                    or not button.text()
                    or button.icon().isNull()
                    or button.toolButtonStyle()
                    != Qt.ToolButtonStyle.ToolButtonTextUnderIcon
                    or button.parentWidget() is not window.photos._format_actions
                    or button.x() < 0 or button.y() < 0
                    or button.x() + button.width() > window.photos._format_actions.width()
                    or button.y() + button.height() > window.photos._format_actions.height()
                    for button in window.photos._format_buttons.values()
                ):
                    raise RuntimeError(
                        "Qt card-ratio labels/icons are hidden, clipped or malformed."
                    )
                if workspace == "import":
                    flow = window.import_flow
                    assert flow is not None and isinstance(page, QScrollArea)
                    viewport = page.viewport()
                    for action in (flow.files, flow.folder, flow.archive, flow.start_button,
                                   flow.details_toggle):
                        position = action.mapTo(viewport, QPoint())
                        if (not action.isVisibleTo(window)
                                or action.height() < action.fontMetrics().height()
                                or not viewport.rect().contains(action.rect().translated(position))):
                            raise RuntimeError(
                                f"Qt Import action is clipped in {size_name}: {action.text()}; "
                                f"control={action.rect().translated(position).getRect()}, "
                                f"viewport={viewport.rect().getRect()}."
                            )
                if workspace == "watcher" and size_name == "narrow" and sys.platform == "darwin":
                    assert window.capture is not None and isinstance(page, QScrollArea)
                    viewport = page.viewport()
                    warning = window.capture.delete_warning
                    warning_bounds = warning.rect().translated(warning.mapTo(viewport, QPoint()))
                    if not warning.isVisibleTo(window) or not viewport.rect().contains(warning_bounds):
                        raise RuntimeError('The narrow Watcher source-deletion warning is outside its viewport.')
                photo_grid: dict[str, JsonValue] | None = None
                if workspace == "photos":
                    scene = window.photos.quick.rootObject()
                    grid = scene.findChild(QObject, 'photosGrid') if scene is not None else None
                    if grid is None:
                        raise RuntimeError('The Qt Photos viewport is unavailable.')
                    viewport_width = float(grid.property('width'))
                    cell_width = float(grid.property('cellWidth'))
                    columns = max(1, int(viewport_width // cell_width))
                    heading = window.photos._gallery_header_layout
                    formats = heading.indexOf(window.photos._format_row)
                    if formats < 0:
                        raise RuntimeError('The loaded gallery did not mount its ratio strip in the heading.')
                    photo_grid = {
                        "viewport_width": viewport_width, "cell_width": cell_width,
                        "columns": columns,
                        "first_row_cards": min(len(window.photos.model.rows), columns),
                        "heading_stacked": heading.getItemPosition(formats)[0] > 0,
                    }
                detail_visible = bool(window.photos.detailOpen)
                if detail_visible != (workspace == "photos-lightbox"):
                    raise RuntimeError(
                        f"Qt detail visibility is inconsistent: {workspace}/{size_name}."
                    )
                bounds = [window.x(), window.y(), window.width(), window.height()]
                if size_name == "standard" and standard_client_size is None:
                    standard_client_size = (bounds[2], bounds[3])
                page.update()
                window.centralWidget().update()
                window.repaint()
                page.repaint()
                app.processEvents()
                QGuiApplication.sync()
                filename = f"{capture_name_prefix}qt-{size_name}-{workspace}.png"
                image_size = save_qt_client(window, output / filename)
                supplemental_images: list[dict[str, JsonValue]] = []
                retained_layout_bounds: dict[str, JsonValue] | None = None
                if flight_panel is not None:
                    maximum_width = min(OVERLAY_WIDTH_PX, window.width() - 2 * OVERLAY_MARGIN_PX)
                    if flight_panel.width() > maximum_width:
                        raise RuntimeError(f"Qt Flight progress exceeded its host width in {size_name}.")
                    if watcher_recovery_probe and window.capture is not None:
                        progress = window.capture
                        dialog = progress._progress_dialog
                        if dialog is not None:
                            dialog.layout().activate()
                            app.processEvents()
                            surface = (
                                dialog if dialog.objectName() == "qtCaptureProgressSurface"
                                else dialog.findChild(QFrame, "qtCaptureProgressSurface")
                            )
                            retained_widgets = {
                                "status": progress._progress_status,
                                "details": progress._progress_details,
                                "head": progress._progress_head,
                                "continue": progress._progress_continue,
                                "close": progress._progress_close,
                            }
                            retained_layout_bounds = {
                                "panel_height": dialog.height(),
                                "surface": list(surface.geometry().getRect())
                                if surface is not None else [],
                                "surface_container_size": [
                                    progress._progress_container.width(),
                                    progress._progress_container.height(),
                                ] if progress._progress_container is not None else [],
                                "surface_parent_is_central": bool(
                                    surface is not None
                                    and progress._progress_container is window.centralWidget()
                                    and surface.parentWidget() is window.centralWidget()
                                ),
                                "surface_shadow": bool(
                                    surface is not None
                                    and surface.isVisible()
                                    and surface.property("raisedSurface") is True
                                    and surface.graphicsEffect() is not None
                                    and surface.graphicsEffect().isEnabled()
                                ),
                                "progress_bottom": (
                                    progress._progress_gauge.y()
                                    + progress._progress_gauge.height()
                                ),
                            }
                            for name, widget in retained_widgets.items():
                                rect = widget.geometry()
                                retained_layout_bounds[name] = [
                                    rect.x(), rect.y(), rect.width(), rect.height(),
                                ]
                            if surface is not None:
                                for name, widget in (
                                    ("continue_target", progress._progress_continue),
                                    ("close_target", progress._progress_close),
                                ):
                                    position = widget.mapTo(surface, QPoint())
                                    retained_layout_bounds[name] = [
                                        position.x(), position.y(),
                                        widget.width(), widget.height(),
                                    ]
                    progress_filename = f"qt-{size_name}-flight-panel.png"
                    progress_size = save_qt_client(flight_panel, output / progress_filename)
                    assert window.capture is not None
                    activity = window.capture._progress_activity
                    supplemental_images.append({
                        "name": "flight-progress-overlay",
                        "image": progress_filename,
                        "image_size": list(progress_size),
                        "phase": window.capture._progress_phase.text(),
                        "paused_status": window.capture._progress_status.text(),
                        "activity_visible": activity.isVisibleTo(flight_panel),
                        "activity_indeterminate": activity.maximum() == activity.minimum(),
                    })
                actions = []
                regions = []
                for widget in window.findChildren(QWidget):
                    if not widget.isVisibleTo(window):
                        continue
                    if widget.objectName().startswith((
                        "workspace-", "shell-header", "latest-activity",
                    )):
                        top_left = widget.mapTo(window, widget.rect().topLeft())
                        regions.append({
                            "name": widget.objectName(),
                            "geometry": [top_left.x(), top_left.y(),
                                         widget.width(), widget.height()],
                        })
                    if isinstance(widget, (QAbstractButton, QComboBox)):
                        top_left = widget.mapTo(window, widget.rect().topLeft())
                        actions.append({
                            "class": type(widget).__name__,
                            "object_name": widget.objectName(),
                            "caption": qt_widget_text(widget),
                            "geometry": [top_left.x(), top_left.y(),
                                         widget.width(), widget.height()],
                        })
                if workspace == "flight" and size_name == "standard":
                    window._show_saved_flight_reports()
                    archive_dialog = window._saved_reports_dialog
                    wait_qt(app, lambda: (
                        archive_dialog is not None
                        and archive_dialog.isVisible()
                        and window.operator.report_tree.topLevelItemCount() > 0
                    ))
                    if archive_dialog is None:
                        raise RuntimeError("Qt saved-flight dialog did not open.")
                    report_tree = window.operator.report_tree
                    archive_rows = [
                        report_tree.topLevelItem(index)
                        for index in range(report_tree.topLevelItemCount())
                    ]
                    flight_ids = [
                        Path(str(item.data(0, Qt.ItemDataRole.UserRole)[1])).name
                        for item in archive_rows
                        if item.data(0, Qt.ItemDataRole.UserRole)
                    ]
                    flight_labels = [item.text(0) for item in archive_rows]
                    if flight_ids != [VISUAL_SESSION_ID]:
                        raise RuntimeError(
                            f"Qt saved-flight fixture rows differ: {flight_ids}."
                        )
                    report_tree.setCurrentItem(archive_rows[0])
                    settle_qt(app)
                    archive_actions = {}
                    for widget in archive_dialog.findChildren(QAbstractButton):
                        caption = qt_widget_text(widget)
                        if widget.isVisibleTo(archive_dialog) and caption:
                            origin = widget.mapTo(archive_dialog, QPoint())
                            archive_actions[caption] = [
                                origin.x(), origin.y(), widget.width(), widget.height(),
                            ]
                    archive_filename = "qt-standard-flight-saved-reports.png"
                    archive_image_size = save_qt_client(
                        archive_dialog, output / archive_filename,
                    )
                    archive_top_left = archive_dialog.mapToGlobal(
                        archive_dialog.rect().topLeft(),
                    )
                    tree_origin = report_tree.mapTo(archive_dialog, QPoint())
                    supplemental_images.append({
                        "name": "saved-flight-archive",
                        "image": archive_filename,
                        "image_size": list(archive_image_size),
                        "desktop_bounds": [
                            archive_top_left.x(), archive_top_left.y(),
                            archive_dialog.width(), archive_dialog.height(),
                        ],
                        "visible": archive_dialog.isVisible()
                        and report_tree.isVisibleTo(archive_dialog),
                        "flight_ids": flight_ids,
                        "flight_labels": flight_labels,
                        "selected_flight_ids": [
                            Path(str(item.data(0, Qt.ItemDataRole.UserRole)[1])).name
                            for item in report_tree.selectedItems()
                        ],
                        "tree_geometry": [
                            tree_origin.x(), tree_origin.y(),
                            report_tree.width(), report_tree.height(),
                        ],
                        "action_geometry": archive_actions,
                    })
                    archive_dialog.hide()
                brand_top = window.header.brand.mapTo(window, QPoint()).y()
                brand_bottom = brand_top + window.header.brand.height()
                link_top = window.link_address.mapTo(window, QPoint()).y()
                link_bottom = link_top + window.link_address.height()
                captures.append({
                    "size_name": size_name, "workspace": workspace,
                    "image": filename, "requested_size": requested_size,
                    "selected_page": selected_page,
                    "requested_window_state": (
                        "maximized" if size_name == "maximized" else "normal"
                    ),
                    "actual_window_state": actual_window_state,
                    "max_reference_client_size": max_reference_client_size,
                    "observed_client_size": [bounds[2], bounds[3]],
                    "image_size": list(image_size),
                    "image_pixel_sha256": visual_image_digest(output / filename),
                    "capture_pixel_scale": [
                        image_size[0] / bounds[2], image_size[1] / bounds[3],
                    ],
                    "workspace_visible": workspace_visible,
                    "lightbox_visible": detail_visible,
                    "visible_photo_count": (
                        len(window.photos._tiles) if workspace.startswith("photos") else None
                    ),
                    "photo_grid": photo_grid,
                    "regions": regions,
                    "actions": actions,
                    "fixture_state": (
                        "linked-filled-gallery" if workspace == "photos" else
                        "linked-lightbox" if workspace == "photos-lightbox" else None
                    ),
                    "supplemental_images": supplemental_images,
                    "fixture_gallery_id": (
                        VISUAL_GALLERY_ID
                        if workspace in ("photos", "photos-lightbox") else None
                    ),
                    "fixture_photo_ids": (
                        list(range(VISUAL_PHOTO_COUNT, 0, -1))
                        if workspace in ("photos", "photos-lightbox") else []
                    ),
                    "ratio_captions": (
                        [button.text() for button in
                         window.photos._format_buttons.values()]
                        if workspace == "photos" else []
                    ),
                    "ratio_button_layout": (
                        [
                            {
                                "tool_button_style": int(button.toolButtonStyle().value),
                                "label_visible": bool(
                                    button.isVisibleTo(window) and button.text()
                                    and not button.icon().isNull()
                                ),
                            }
                            for button in window.photos._format_buttons.values()
                        ] if workspace == "photos" else []
                    ),
                    "selected_ratio": (
                        next((key for key, button in
                              window.photos._format_buttons.items()
                              if button.isChecked()), None)
                        if workspace == "photos" else None
                    ),
                    "selected_photo_ids": (
                        sorted(window.photos.model.selected)
                        if workspace in ("photos", "photos-lightbox") else []
                    ),
                    "latest_activity_text": window.latest_activity.text(),
                    "header_link_address": window.photos.linked_gallery_address(),
                    "header_link_visible": bool(
                        window.link_address.isVisibleTo(window)
                        and window.link_address.text()
                    ),
                    "tagline_visible": window.header.tagline.isVisibleTo(window),
                    "visible_header_link_caption": window.link_address.text(),
                    "header_link_inline": brand_top < link_bottom and link_top < brand_bottom,
                    "header_brand_and_link_rows": [brand_top, brand_bottom, link_top, link_bottom],
                    "watcher_fixture_state": (
                        "pending" if watcher_recovery_probe
                        and recovery_profile_work is not None
                        and recovery_profile_work.recoverable_capture
                        else window.capture.commands.snapshot().phase
                        if workspace == "watcher" and window.capture is not None
                        else None
                    ),
                    "watcher_resume_available": (
                        window.capture.commands.snapshot().resume_available
                        if workspace == "watcher" and window.capture is not None
                        else None
                    ),
                    "watcher_action_captions": (
                        [window.capture._progress_continue.text()]
                        if watcher_recovery_probe and workspace == "watcher"
                        and window.capture is not None
                        else [
                            button.text() for button in (
                                window.capture.start_button,
                                window.capture.resume_button,
                            ) if button.isVisibleTo(window)
                        ] if workspace == "watcher" and window.capture is not None
                        else []
                    ),
                    "watcher_continue_visible": (
                        bool(
                            window.capture is not None
                            and window.capture._progress_dialog is not None
                            and window.capture._progress_dialog.isVisible()
                            and window.capture._progress_continue.isVisible()
                        ) if watcher_recovery_probe and workspace == "watcher" else None
                    ),
                    "watcher_continue_enabled": (
                        bool(
                            window.capture is not None
                            and window.capture._progress_continue.isEnabled()
                        ) if watcher_recovery_probe and workspace == "watcher" else None
                    ),
                    "watcher_retained_layout_bounds": (
                        retained_layout_bounds
                        if watcher_recovery_probe and workspace == "watcher" else None
                    ),
                    "watcher_start_action_enabled": (
                        bool(
                            window.capture is not None
                            and window.capture.start_button.isVisibleTo(window)
                            and window.capture.start_button.isEnabled()
                        ) if watcher_recovery_probe and workspace == "watcher" else None
                    ),
                    "watcher_interrupt_action_enabled": (
                        bool(
                            window.capture is not None
                            and window.capture._progress_stop.isVisible()
                            and window.capture._progress_stop.isEnabled()
                        ) if watcher_recovery_probe and workspace == "watcher" else None
                    ),
                    "watcher_pending_session_ids": (
                        [plan.session_id for plan in MAIN.pending_sessions(
                            profile / "flights", connection_binding(
                                MAIN.normalize_upload_url(config.gallery_url),
                                config.api_key.strip(),
                            ),
                        )] if watcher_recovery_probe and workspace == "watcher" else []
                    ),
                    "watcher_target_binding_sha256": (
                        recovery_binding if watcher_recovery_probe
                        and workspace == "watcher" else ""
                    ),
                    "watcher_pending_item_ids": (
                        watcher_pending_queue_evidence(pending_journal_path)[0]
                        if watcher_recovery_probe and workspace == "watcher" else []
                    ),
                    "watcher_queue_unchanged": (
                        watcher_pending_queue_evidence(pending_journal_path)[1]
                        == pending_journal_before
                        if watcher_recovery_probe and workspace == "watcher" else None
                    ),
                    "watcher_probe_actions": (
                        "read-only; no upload or network action invoked"
                        if watcher_recovery_probe and workspace == "watcher" else ""
                    ),
                    "activity_import_summary": (
                        window.operator.activity.import_summary.text()
                        if workspace == "activity" else ""
                    ),
                    "activity_selected_job": (
                        window.operator.selected_import_id()
                        if workspace == "activity" else None
                    ),
                    "activity_history_row_count": (
                        len(window.operator.imports.model.rows)
                        if workspace == "activity" else None
                    ),
                    "activity_event_row_count": (
                        len(window.operator.activity.model.rows)
                        if workspace == "activity" else None
                    ),
                })
                if flight_panel is not None:
                    flight_panel.hide()
                if workspace == "photos-lightbox":
                    window.photos.dismissPhoto()
                    settle_qt(app)
                    window.show_page("watcher")
                    if window.photos.linked_gallery_address() != VISUAL_PHOTOS_ISSUER:
                        raise RuntimeError(
                            "Qt linked Photos identity changed while switching workspace."
                        )
        for scenario in VISUAL_SCENARIO_WORKSPACES:
            if scenario not in ("photos-disconnected", "photos-connecting"):
                try:
                    captures.append(capture_qt_linked_variant(
                        app, window, profile, output, scenario,
                    ))
                except Exception as error:
                    capture_failures.append({
                        "workspace": scenario,
                        "exception_type": type(error).__name__,
                        "message": str(error),
                    })
    finally:
        window.shutdown()
        window.close()
        app.processEvents()
        app.quit()
    return {
        "backend": "qt", "app_version": MAIN.APP_VERSION,
        "locale": language,
        "localized_probe_strings": {
            "settings": gettext("Settings"),
            "settings_action": gettext("✓  Save settings"),
            "default_ratio": tr(language, "photos_workspace_card_format_default"),
            "connect_action": tr(language, "linking_start"),
        },
        "status": "FAIL" if capture_failures else "PASS",
        "capture_failures": capture_failures,
        "source_identity": os.environ.get(
            "ISSUE_445_SHA", os.environ.get("GITHUB_SHA", ""),
        ),
        "seed_profile_sha256": os.environ["PHP_GALLERY_VISUAL_SEED_SHA256"],
        "workspaces": list(VISUAL_WORKSPACES), "display": display,
        "scenario_workspaces": list(VISUAL_SCENARIO_WORKSPACES),
        "size_scope": [list(item) for item in sizes], "captures": captures,
        "fixture_adapters": (
            "Synthetic read-only LinkingSession supplies identical gallery IDs, "
            "photo IDs and generated JPEG bytes through production Qt owners; "
            "no network or write actions are invoked."
        ),
        "fixture_scope": {
            "gallery_id": VISUAL_GALLERY_ID,
            "photo_ids": list(range(VISUAL_PHOTO_COUNT, 0, -1)),
            "media_sha256": [
                sha256((profile / "visual-photos" / f"visual-{index:02d}.jpg")
                       .read_bytes()).hexdigest()
                for index in range(1, VISUAL_PHOTO_COUNT + 1)
            ],
            "network_access": False,
            "write_actions": False,
            "gallery_url_configured": watcher_recovery_probe,
            "api_key_configured": watcher_recovery_probe,
            "watcher_recovery_target_type": (
                "normalized-legacy-profile-binding"
                if watcher_recovery_probe else None
            ),
        },
        "watcher_recovery_target_type": (
            "normalized-legacy-profile-binding"
            if watcher_recovery_probe else None
        ),
    }


def capture_qt_linked_variant(
    app: QApplication, window: QtWindow, profile: Path, output: Path,
    scenario: str,
) -> dict[str, JsonValue]:
    """Capture actual Qt catalogue, selection, metadata and Music states.

    Args:
        app (QApplication): Existing native event loop owner.
        window (QtWindow): Production shell with the synthetic gallery mounted.
        profile (Path): Current child profile for generated fixture media.
        output (Path): Directory receiving the native screenshot.
        scenario (str): One supported linked Photos case.
    Returns:
        dict[str, JsonValue]: Selected IDs, ratio labels and native pixel evidence.
    Raises:
        RuntimeError: The requested controls did not reach their expected state.
        ValueError: Scenario is not a supported linked Photos case.
    """
    if scenario not in (
        "photos-ratio-16-9", "photos-selected", "photos-multiselected",
        "photos-linked-empty", "photos-hierarchy", "photos-lightbox-fullscreen",
        "photos-info", "photos-context-menu", "photos-info-unavailable",
        "photos-delete-confirmation", "photos-folder-shelf",
        "photos-music-panel",
    ):
        raise ValueError("Unsupported Qt Photos visual scenario.")
    size_name, width, height = VISUAL_SIZES[0]
    window.showNormal()
    window.resize(width, height)
    window.show_page("photos")
    settle_qt(app)
    photos = window.photos
    folder_cards: list[dict[str, JsonValue]] = []
    folder_tree_child_ids: list[int] = []
    folder_section_visible = False
    folder_controls_visible = False
    photo_controls_visible = False
    empty_gallery_message = ""
    folder_selected_ratio = "default"
    music_panel_text = ""
    music_panel_geometry: list[int] | None = None
    supplemental_images: list[dict[str, JsonValue]] = []
    context_card: QWidget | None = None
    context_filename = ""
    context_image_size: tuple[int, int] | None = None
    context_bounds: list[int] = []
    context_geometry: list[int] = []
    photo_info_visible = False
    photo_info_geometry: list[int] = []
    photo_info_text = ""
    photo_info_summary = ""
    confirmation: dict[str, JsonValue] = {}
    fixture_shape = (
        "empty" if scenario == "photos-linked-empty" else
        "hierarchy" if scenario in ("photos-hierarchy", "photos-folder-shelf") else
        "admin" if scenario == "photos-delete-confirmation" else "filled"
    )
    fixture_session, fixture_galleries, fixture_photos, _body = (
        visual_linking_session(profile, fixture_shape)
    )
    if scenario == "photos-music-panel":
        fixture_session.can_read_presentation.return_value = True
        fixture_session.media_presentation.return_value = LinkedPresentation(
            False, False, 5, "medium", False, (),
        )
    if scenario == "photos-info-unavailable":
        fixture_session.media_photo_info.side_effect = OSError(
            "synthetic metadata failure",
        )
    fixture_gallery_ids = [gallery.gallery_id for gallery in fixture_galleries]
    photos._receive_catalogue(LinkedPhotos(
        fixture_session, VISUAL_PHOTOS_ISSUER, VISUAL_PHOTOS_ACCOUNT,
        fixture_galleries, PhotosTreePreferences(
            {VISUAL_GALLERY_ID: True} if fixture_shape == "hierarchy" else {},
            VISUAL_GALLERY_ID,
        ),
    ))
    expected_rows = tuple(
        PhotoRow(photo.image_id, photo.title, photo)
        for photo in reversed(fixture_photos)
    )
    wait_qt(app, lambda: (
        photos._session is fixture_session
        and photos._identity == (VISUAL_PHOTOS_ISSUER, VISUAL_PHOTOS_ACCOUNT)
        and photos._gallery_tree.topLevelItemCount() == 1
        and photos.model.rows == expected_rows
        and (bool(photos._tiles) if expected_rows else True)
    ))
    expected_format = "16:9" if scenario == "photos-ratio-16-9" else "default"
    if photos._format != expected_format and photos._format_row.isVisibleTo(window):
        photos._format_buttons[expected_format].click()
        wait_qt(app, lambda: photos._format == expected_format)
    if scenario == "photos-linked-empty":
        wait_qt(app, lambda: photos._empty_gallery_message.isVisibleTo(window))
        empty_gallery_message = photos._empty_gallery_message.text()
        photo_controls_visible = photos._format_row.isVisibleTo(window)
    elif scenario == "photos-ratio-16-9":
        photos.model.select(set())
    elif scenario == "photos-lightbox-fullscreen":
        photos.openPhoto(0)
        wait_qt(app, lambda: photos._detail_display_index == 0)
        photos.toggle_fullscreen()
        try:
            wait_qt(app, lambda: (
                photos._fullscreen and photos.detailOpen and window.isFullScreen()
            ))
        except RuntimeError as error:
            raise RuntimeError(
                "Qt fullscreen request did not settle: "
                f"flag={photos._fullscreen}, detail={photos.detailOpen}, "
                f"native_fullscreen={window.isFullScreen()}, "
                f"current_page={window.tabs.currentWidget().objectName()}, "
                f"detail_index={photos._detail_display_index}."
            ) from error
        settle_qt(app)
    elif scenario == "photos-folder-shelf":
        expected_child_ids = {
            VISUAL_CHILD_GALLERY_ID, VISUAL_SECOND_CHILD_GALLERY_ID,
        }
        shelf = photos._folder_shelf
        wait_qt(app, lambda: (
            shelf.isVisibleTo(window)
            and {row.gallery_id for row in shelf.cards.rows} == expected_child_ids
            and set(shelf.cards.covers) == expected_child_ids
            and all(
                shelf.cards.covers[gallery_id] is not None
                and not shelf.cards.covers[gallery_id].isNull()
                for gallery_id in expected_child_ids
            )
        ))
        root_item = photos._gallery_tree.topLevelItem(0)
        if root_item is None:
            raise RuntimeError("Qt hierarchy fixture has no root gallery row.")
        folder_tree_child_ids = sorted(
            int(root_item.child(index).data(0, Qt.ItemDataRole.UserRole))
            for index in range(root_item.childCount())
        )
        if set(folder_tree_child_ids) != expected_child_ids:
            raise RuntimeError(
                f"Qt tree did not expose both direct children: {folder_tree_child_ids}."
            )
        if not photos._subgallery_switch.isChecked():
            raise RuntimeError("Qt Photos subgallery control is not enabled for the fixture.")
        photos._folder_format_buttons["16:9"].click()
        wait_qt(app, lambda: photos._folder_format == "16:9")
        wait_qt(app, lambda: (
            set(shelf.cards.covers) == expected_child_ids
            and all(
                shelf.cards.covers[gallery_id] is not None
                and not shelf.cards.covers[gallery_id].isNull()
                for gallery_id in expected_child_ids
            )
        ))
        folder_selected_ratio = "16:9"
        folder_cards = [
            {
                "gallery_id": row.gallery_id,
                "title": row.title,
                "cover_visible": (
                    shelf.cards.covers.get(row.gallery_id) is not None
                    and not shelf.cards.covers[row.gallery_id].isNull()
                ),
            }
            for row in sorted(shelf.cards.rows, key=lambda item: item.gallery_id)
        ]
        folder_section_visible = photos._folder_section.isVisibleTo(window)
        folder_controls_visible = photos._folder_formats.isVisibleTo(window)
        photo_controls_visible = photos._format_row.isVisibleTo(window)
        if not (folder_section_visible and folder_controls_visible and photo_controls_visible):
            raise RuntimeError(
                "Qt mixed folder/photo section omitted its real controls: "
                f"section={folder_section_visible}, folder_formats={folder_controls_visible}, "
                f"photo_formats={photo_controls_visible}."
            )
    elif scenario == "photos-music-panel":
        playback = photos.playback
        wait_qt(app, lambda: playback._settings is not None)
        if not playback.music_button.isVisibleTo(window):
            raise RuntimeError(
                "Qt Music trigger is not visible in the linked Photos view: "
                f"visible={playback.music_button.isVisible()}, "
                f"size={playback.music_button.width()}x{playback.music_button.height()}, "
                f"parent={playback.music_button.parentWidget().objectName()}.")
        playback.music_button.click()
        settle_qt(app)
        if not playback._panel.isVisible():
            raise RuntimeError("Qt Music popup did not become visible.")
        music_panel_text = playback._feedback.text()
        if not music_panel_text.strip() or playback._playback_controls.isVisible():
            raise RuntimeError(
                "Qt Music popup did not show the empty authorized presentation state: "
                f"feedback={music_panel_text!r}, controls="
                f"{playback._playback_controls.isVisible()}."
            )
        panel_origin = playback._panel.mapToGlobal(playback._panel.rect().topLeft())
        music_panel_geometry = [
            panel_origin.x() - window.x(), panel_origin.y() - window.y(),
            playback._panel.width(), playback._panel.height(),
        ]
        music_filename = f"qt-{size_name}-photos-music-panel.png"
        music_image_size = save_qt_client(
            playback._panel, output / music_filename,
        )
        supplemental_images.append({
            "name": "music-panel", "image": music_filename,
            "image_size": list(music_image_size),
            "geometry": music_panel_geometry,
        })
    elif scenario == "photos-info":
        photos.choosePhoto(0, False, False)
        expected_title = f"Visual fixture {photos.model.rows[0].identity}"
        wait_qt(app, lambda: (
            photos._inspector_panel.isVisibleTo(window)
            and photos._inspector_panel.width() > 0
            and photos._inspector_panel.card.x() == 0
            and not photos._inspector_preview.image.isNull()
            and expected_title in photos._inspector.toPlainText()
        ))
    elif scenario == "photos-info-unavailable":
        photos.choosePhoto(0, False, False)
        expected_failure = photos._text("details_fallback")
        expected_title = f"Visual fixture {photos.model.rows[0].identity}"
        wait_qt(app, lambda: (
            photos._inspector_panel.isVisibleTo(window)
            and photos._inspector_panel.width() > 0
            and photos._inspector_panel.card.x() == 0
            and expected_failure in photos._inspector_summary.text()
            and expected_title in photos._inspector.toPlainText()
        ))
    elif scenario == "photos-context-menu":
        wait_qt(app, lambda: (
            len(photos._tiles) == VISUAL_PHOTO_COUNT
            and all(
                row.identity in photos.model.texture_urls
                for row in photos.model.rows
            )
        ))
        settle_qt(app)
        photos.quick.setFocus()
        photos.photoMenu(0, max(1, photos.quick.width() // 2),
                         max(1, photos.quick.height() // 2))
        wait_qt(app, lambda: any(
            card.objectName() == "photosPhotoContext"
            and card.isVisibleTo(window)
            and card.width() > 0 and card.height() > 0
            and any(
                isinstance(button, QAbstractButton)
                and button.isVisibleTo(window)
                and qt_widget_text(button) == photos._text("photo_info")
                for button in card.findChildren(QAbstractButton)
            )
            for card in window.findChildren(QWidget)
        ))
        context_card = window.findChild(QWidget, "photosPhotoContext")
        if context_card is None or not context_card.isVisibleTo(window):
            raise RuntimeError(
                "Qt photo context card is absent after its production action."
            )
        context_actions = [
            button for button in context_card.findChildren(QAbstractButton)
            if button.isVisibleTo(window)
        ]
        if not any(
            qt_widget_text(button) == photos._text("photo_info")
            for button in context_actions
        ):
            raise RuntimeError(
                "Qt photo context card omitted the visible Photo information action."
            )
        context_origin = context_card.mapTo(
            window, context_card.rect().topLeft(),
        )
        context_geometry = [
            context_origin.x(), context_origin.y(),
            context_card.width(), context_card.height(),
        ]
        context_bounds = [
            0, 0, context_card.width(), context_card.height(),
        ]
        context_filename = f"qt-{size_name}-{scenario}.png"
        context_pixmap = context_card.grab()
        if context_pixmap.isNull() or not context_pixmap.save(
            str(output / context_filename), "PNG",
        ):
            raise RuntimeError("Qt photo context card could not be captured.")
        context_image_size = (context_pixmap.width(), context_pixmap.height())
    elif scenario == "photos-delete-confirmation":
        wait_qt(app, lambda: (
            len(photos._tiles) == VISUAL_PHOTO_COUNT
            and all(
                row.identity in photos.model.texture_urls
                for row in photos.model.rows
            )
        ))
        photos.photoMenu(0, max(1, photos.quick.width() // 2),
                         max(1, photos.quick.height() // 2))
        card = photos._photo_context
        if card is None or not card.isVisibleTo(window):
            raise RuntimeError("Qt Admin photo context card is unavailable.")
        delete_action = next((
            action for action in card.findChildren(QAbstractButton)
            if action.isVisibleTo(card) and action.text() == photos._text("delete_photo")
        ), None)
        if delete_action is None:
            raise RuntimeError("Qt admin fixture did not expose delete confirmation action.")
        anchor = photos._photo_context_anchor
        delete_action.click()
        prompt = photos._photo_context
        if (prompt is None or prompt is card or not prompt.isVisibleTo(window)
                or photos._photo_context_anchor != anchor):
            raise RuntimeError("Qt delete confirmation did not stay at its tile.")
        if prompt.property("raisedSurface") is not True:
            raise RuntimeError("Qt delete confirmation is not an inherited raised surface.")
        labels = prompt.findChildren(QLabel)
        buttons = sorted(
            (button for button in prompt.findChildren(QAbstractButton) if button.isVisibleTo(prompt)),
            key=lambda button: button.y(),
        )
        cancel = next((button for button in buttons if button.text() == photos._text('cancel')), None)
        if cancel is None:
            raise RuntimeError("Qt delete confirmation has no visible Cancel action.")
        settle_qt(app)
        origin = prompt.mapTo(window, prompt.rect().topLeft())
        confirmation = {
            "text": " ".join(label.text() for label in labels if label.isVisibleTo(prompt)),
            "actions": [
                {"class": type(button).__name__, "caption": button.text()}
                for button in buttons
            ],
            "bounds": [window.x(), window.y(), window.width(), window.height()],
            "region": [origin.x(), origin.y(), prompt.width(), prompt.height()],
            "safety_focus": cancel.hasFocus(),
            "image_size": list(save_qt_client(window, output / f"qt-{size_name}-{scenario}.png")),
        }
        cancel.click()
        if "Synthetic visual gallery" not in str(confirmation["text"]):
            raise RuntimeError(
                f"Qt delete prompt omitted its gallery scope: {confirmation['text']!r}."
            )
        if fixture_session.delete_media_photos.called:
            raise RuntimeError("Qt cancel confirmation issued a photo deletion write.")
    elif scenario == "photos-selected":
        photos.model.select(set())
        photos.choosePhoto(0, False, False)
        photos._close_inspector()
        if len(photos.model.selected) != 1:
            raise RuntimeError("Qt single-selection fixture did not select one photo.")
    elif scenario == "photos-multiselected":
        photos.model.select(set())
        photos.choosePhoto(0, False, False)
        photos.choosePhoto(1, True, False)
        if len(photos.model.selected) != 2:
            raise RuntimeError("Qt multi-selection fixture did not select two photos.")
    if context_card is None:
        settle_qt(app)
    page = window._pages["photos"]
    fullscreen = scenario == "photos-lightbox-fullscreen"
    if scenario in ("photos-info", "photos-info-unavailable"):
        inspector = photos._inspector_panel
        photo_info_visible = bool(
            inspector.isVisibleTo(window) and inspector.width() > 0
        )
        inspector_origin = inspector.mapTo(window, inspector.rect().topLeft())
        photo_info_geometry = [
            inspector_origin.x(), inspector_origin.y(),
            inspector.width(), inspector.height(),
        ]
        photo_info_text = photos._inspector.toPlainText()
        photo_info_summary = photos._inspector_summary.text()
        if not photo_info_visible:
            raise RuntimeError(
                "Qt Photos inspector is not visible in its host window: "
                f"geometry={photo_info_geometry}, text={photo_info_text!r}."
            )
    if window.tabs.currentWidget() is not page or (
        photos.detailOpen != fullscreen
    ) or (fullscreen and not photos._fullscreen) or (
        scenario == "photos-context-menu"
        and (context_card is None or not context_card.isVisibleTo(window))
    ):
        raise RuntimeError(
            f"Qt Photos scenario is not unobscured: {scenario}; "
            f"selected_page={window.tabs.currentWidget().objectName()}, "
            f"expected_page={page.objectName()}, detail_open={photos.detailOpen}, "
            f"fullscreen_flag={photos._fullscreen}, "
            f"native_fullscreen={window.isFullScreen()}, "
            f"detail_index={photos._detail_display_index}, "
            f"detail_url={bool(photos.detailUrl)}, "
            f"quick_visible={photos.quick.isVisibleTo(window)}, "
            f"lightbox_controls_visible="
            f"{photos._detail_controls.isVisibleTo(window)}, "
            f"window_geometry={window.geometry().getRect()}, "
            f"screen_geometry="
            f"{(window.screen().geometry().getRect() if window.screen() else None)}."
        )
    capture_widget = window
    lightbox_controls = (photos._detail_controls, *photos._detail_edges) if fullscreen else ()
    if fullscreen:
        wait_qt(app, lambda: not any(control.isVisibleTo(window) for control in lightbox_controls))
    if confirmation:
        bounds = list(confirmation["bounds"])
        image_size = tuple(confirmation["image_size"])
        filename = f"qt-{size_name}-{scenario}.png"
    elif context_card is not None:
        bounds = context_bounds
    else:
        bounds = [window.x(), window.y(), window.width(), window.height()]
    if not confirmation and context_image_size is None:
        page.repaint()
        window.repaint()
        app.processEvents()
        QGuiApplication.sync()
        filename = f"qt-{size_name}-{scenario}.png"
        if fullscreen and sys.platform == 'darwin':
            origin = capture_widget.mapToGlobal(QPoint())
            rectangle = (origin.x(), origin.y(), origin.x() + bounds[2], origin.y() + bounds[3])
            with capture_stable_desktop_pixels(app.processEvents, rectangle) as frame:
                frame.save(output / filename)
                image_size = frame.size
        else:
            image_size = save_qt_client(capture_widget, output / filename)
    elif context_image_size is not None:
        filename = context_filename
        image_size = context_image_size
    actions = []
    regions = []
    for widget in window.findChildren(QWidget):
        if not widget.isVisibleTo(window):
            continue
        if widget.objectName().startswith((
            "workspace-", "shell-header", "latest-activity",
        )):
            top_left = widget.mapTo(window, widget.rect().topLeft())
            regions.append({
                "name": widget.objectName(),
                "geometry": [top_left.x(), top_left.y(),
                             widget.width(), widget.height()],
            })
        if isinstance(widget, (QAbstractButton, QComboBox)):
            top_left = widget.mapTo(window, widget.rect().topLeft())
            actions.append({
                "class": type(widget).__name__,
                "object_name": widget.objectName(),
                "caption": qt_widget_text(widget),
                "geometry": [top_left.x(), top_left.y(),
                             widget.width(), widget.height()],
            })
    if scenario == "photos-folder-shelf":
        for widget in (photos._folder_formats, photos._folder_shelf):
            top_left = widget.mapTo(window, widget.rect().topLeft())
            regions.append({
                "name": widget.objectName(),
                "geometry": [
                    top_left.x(), top_left.y(), widget.width(), widget.height(),
                ],
            })
    if confirmation:
        actions = list(confirmation.get("actions", []))
    if context_card is not None:
        actions = [
            {
                "class": type(button).__name__,
                "object_name": button.objectName(),
                "caption": qt_widget_text(button),
                "geometry": [
                    button.mapTo(context_card, button.rect().topLeft()).x(),
                    button.mapTo(context_card, button.rect().topLeft()).y(),
                    button.width(), button.height(),
                ],
            }
            for button in context_actions
        ]
        photos._dismiss_photo_context()
    if scenario in ("photos-info", "photos-info-unavailable"):
        photos._close_inspector()
    idle_chrome_hidden = bool(lightbox_controls) and not any(
        control.isVisibleTo(window) for control in lightbox_controls
    )
    chrome_restored = False
    if fullscreen:
        center = photos.quick.rect().center()
        QTest.mouseMove(photos.quick, QPoint(center.x() // 2, center.y()))
        QTest.mouseMove(photos.quick, center)
        wait_qt(app, lambda: all(control.isVisibleTo(window) for control in lightbox_controls))
        settle_qt(app)
        chrome_restored = all(control.isVisibleTo(window) for control in lightbox_controls)
        active_filename = f"qt-{size_name}-{scenario}-active.png"
        if sys.platform == 'darwin':
            with ImageGrab.grab(bbox=rectangle) as active_frame:
                active_frame.save(output / active_filename)
                active_size = active_frame.size
        else:
            active_size = save_qt_client(window, output / active_filename)
        supplemental_images.append({
            "name": "fullscreen-active-controls", "image": active_filename,
            "desktop_bounds": bounds, "image_size": list(active_size),
        })
    folder_ratio_layout: list[dict[str, JsonValue]] = []
    folder_strip_geometry: list[int] = []
    if scenario == "photos-folder-shelf":
        strip = photos._folder_formats
        strip_origin = strip.mapTo(window, strip.rect().topLeft())
        folder_strip_geometry = [
            strip_origin.x(), strip_origin.y(), strip.width(), strip.height(),
        ]
        device_scale_y = image_size[1] / bounds[3]
        for button in photos._folder_format_buttons.values():
            origin = button.mapTo(strip, button.rect().topLeft())
            icon_size = button.iconSize()
            text_height = button.fontMetrics().height()
            control_width, control_height = button.width(), button.height()
            required_height = button.sizeHint().height()
            folder_ratio_layout.append({
                "caption": button.text(),
                "tool_button_style": int(button.toolButtonStyle().value),
                "control_geometry": [
                    origin.x(), origin.y(), control_width, control_height,
                ],
                "control_requested_height": required_height,
                "icon_size": [icon_size.width(), icon_size.height()],
                "text_height": text_height,
                "strip_size": [strip.width(), strip.height()],
                "control_device_height_px": control_height * device_scale_y,
                "icon_device_height_px": icon_size.height() * device_scale_y,
                "text_device_height_px": text_height * device_scale_y,
                "content_fits_actual_height": bool(
                    control_height >= required_height
                    and control_height >= icon_size.height() + text_height
                ),
                "control_fits_strip": bool(
                    origin.x() >= 0 and origin.y() >= 0
                    and origin.x() + control_width <= strip.width()
                    and origin.y() + control_height <= strip.height()
                ),
                "icon_visible": not button.icon().isNull(),
            })
    return {
        "size_name": size_name, "workspace": scenario, "image": filename,
        "requested_size": list(VISUAL_SIZES[0][1:]),
        "requested_window_state": "fullscreen" if fullscreen else "normal",
        "actual_window_state": "fullscreen" if fullscreen else "normal",
        "selected_page": page.objectName().removeprefix("workspace-"),
        "observed_client_size": bounds[2:], "image_size": list(image_size),
        "image_pixel_sha256": visual_image_digest(output / filename),
        "capture_pixel_scale": [
            image_size[0] / bounds[2], image_size[1] / bounds[3],
        ],
        "workspace_visible": True, "lightbox_visible": fullscreen,
        "visible_photo_count": len(photos._tiles),
        "regions": ([{
            "name": "photos-delete-confirmation",
            "geometry": confirmation["region"],
        }] if confirmation else ([{
            "name": "photosPhotoContext",
            "geometry": context_geometry,
        }] if context_card is not None else regions)),
        "fullscreen": photos._fullscreen,
        "lightbox_idle_chrome_hidden": idle_chrome_hidden,
        "lightbox_chrome_restored_by_motion": chrome_restored,
        "actions": actions, "fixture_gallery_id": VISUAL_GALLERY_ID,
        "fixture_gallery_ids": fixture_gallery_ids,
        "fixture_photo_ids": (
            [] if scenario == "photos-linked-empty"
            else list(range(VISUAL_PHOTO_COUNT, 0, -1))
        ),
        "fixture_state": scenario,
        "gallery_hierarchy": gallery_hierarchy_qt(photos._gallery_tree),
        "folder_cards": folder_cards,
        "folder_tree_child_ids": folder_tree_child_ids,
        "folder_section_visible": folder_section_visible,
        "folder_controls_visible": folder_controls_visible,
        "photo_controls_visible": photo_controls_visible,
        "empty_gallery_message": empty_gallery_message,
        "folder_selected_ratio": folder_selected_ratio,
        "folder_ratio_captions": [
            button.text() for button in photos._folder_format_buttons.values()
        ] if scenario == "photos-folder-shelf" else [],
        "folder_ratio_layout": folder_ratio_layout,
        "folder_strip_geometry": folder_strip_geometry,
        "subgallery_enabled": (
            photos._subgallery_switch.isChecked()
            if scenario == "photos-folder-shelf" else False
        ),
        "ratio_captions": [button.text() for button in
                           photos._format_buttons.values()],
        "selected_ratio": photos._format,
        "selected_photo_ids": sorted(photos.model.selected),
        "photo_info_visible": photo_info_visible,
        "photo_info_geometry": photo_info_geometry,
        "photo_info_text": photo_info_text,
        "photo_info_summary": photo_info_summary,
        "photo_info_action": (
            photos._text("photo_info")
            if scenario == "photos-context-menu" else ""
        ),
        "metadata_failure_visible": (
            scenario == "photos-info-unavailable"
            and photos._text("details_fallback") in photo_info_summary
        ),
        "confirmation_visible": bool(confirmation),
        "confirmation_text": str(confirmation.get("text", "")),
        "confirmation_cancelled": (
            scenario == "photos-delete-confirmation"
            and not fixture_session.delete_media_photos.called
        ),
        "confirmation_actions": list(confirmation.get("actions", [])),
        "confirmation_safety_focus": bool(confirmation.get("safety_focus")),
        "confirmation_cancel_caption": (
            photos._text("cancel")
            if scenario == "photos-delete-confirmation" else ""
        ),
        "write_count": (
            fixture_session.delete_media_photos.call_count
            if scenario == "photos-delete-confirmation" else 0
        ),
        "latest_activity_text": window.latest_activity.text(),
        "music_panel_visible": (
            scenario == "photos-music-panel" and photos.playback._panel.isVisible()
        ),
        "music_panel_text": music_panel_text,
        "music_panel_geometry": music_panel_geometry,
        "supplemental_images": supplemental_images,
        "header_link_address": photos.linked_gallery_address(),
    }


def save_qt_client(window: QWidget, path: Path) -> tuple[int, int]:
    """Save the observed pixels of one mapped Qt window or child surface.

    Args:
        window (QWidget): Existing visible main window or progress surface.
        path (Path): Fixture-owned destination for the captured PNG.
    Returns:
        tuple[int, int]: Exact device-pixel width and height of the saved capture.
    Raises:
        RuntimeError: The window has no observable native pixels or the PNG could not be saved.
    """
    screen = window.screen() or QGuiApplication.primaryScreen()
    if not window.isVisible() or screen is None:
        raise RuntimeError(f"Qt capture window is unavailable: {path.name}.")
    if sys.platform == "win32":
        # A window DC returned a stale Quick surface in hosted run 45751101072.
        # Capture the corresponding portion of the Windows desktop compositor.
        origin = window.mapToGlobal(window.rect().topLeft()) - screen.geometry().topLeft()
        pixmap = screen.grabWindow(0, origin.x(), origin.y(), window.width(), window.height())
    else:
        pixmap = screen.grabWindow(int(window.winId()))
    if pixmap.isNull() or not pixmap.save(str(path), "PNG"):
        raise RuntimeError(f"Qt native screenshot could not be saved: {path.name}.")
    return pixmap.width(), pixmap.height()


def settle_qt(app: QApplication) -> None:
    """Pump native Qt events and scene frames for the shared settle interval.

    Args:
        app (QApplication): Existing production Qt application singleton.
    Returns:
        None: Native layout and scene updates have been processed.
    """
    deadline = time.monotonic() + VISUAL_SETTLE_SECONDS
    while time.monotonic() < deadline:
        app.processEvents(
            QEventLoop.ProcessEventsFlag.AllEvents, VISUAL_QT_EVENT_SLICE_MS,
        )
        time.sleep(VISUAL_PUMP_DELAY_SECONDS)


def capture_qt_disconnected(
    app: QApplication, window: QtWindow, output: Path,
) -> dict[str, JsonValue]:
    """Capture a clean Qt launch before the synthetic linked tree is mounted.

    Args:
        app (QApplication): Native application processing the real window.
        window (QtWindow): Fresh production shell with an empty Photos model.
        output (Path): Directory receiving the native capture.
    Returns:
        dict[str, JsonValue]: Actual selected page, visible connect card and pixels.
    Raises:
        RuntimeError: The clean profile is linked or the Photos connect card is hidden.
    """
    size_name, width, height = VISUAL_SIZES[0]
    page = window._pages["photos"]
    window.resize(width, height)
    window.show_page("photos")
    settle_qt(app)
    if window.photos._identity is not None or window.photos.model.rows:
        raise RuntimeError("Fresh Qt visual profile unexpectedly has linked Photos rows.")
    if (not window.photos._connect_card.isVisibleTo(window)
            or not window.photos.link_button.isEnabled()):
        raise RuntimeError("Fresh Qt Photos connect card is not visible on clean launch.")
    card_top_left = window.photos._connect_card.mapTo(page, QPoint(0, 0))
    card_geometry = [
        card_top_left.x(), card_top_left.y(), window.photos._connect_card.width(),
        window.photos._connect_card.height(),
    ]
    page_size = [page.width(), page.height()]
    if (min(card_geometry[2:]) <= 0 or min(page_size) <= 0
            or card_geometry[2] * card_geometry[3]
            >= page_size[0] * page_size[1] * VISUAL_CONNECT_CARD_MAX_AREA_RATIO):
        raise RuntimeError(
            f"Qt clean connect card is not compact within its workspace: "
            f"card={card_geometry}, workspace={page_size}."
        )
    if window.tabs.currentWidget() is not page or not window._nav_buttons[0].isChecked():
        raise RuntimeError("Fresh Qt Photos page is not selected on clean launch.")
    page.repaint()
    window.repaint()
    app.processEvents()
    QGuiApplication.sync()
    bounds = [window.x(), window.y(), window.width(), window.height()]
    filename = f"qt-{size_name}-photos-disconnected.png"
    image_size = save_qt_client(window, output / filename)
    actions = []
    workspace_action_captions: list[str] = []
    regions = []
    for widget in window.findChildren(QWidget):
        if not widget.isVisibleTo(window):
            continue
        if widget.objectName().startswith((
            "workspace-", "shell-header", "latest-activity",
        )):
            top_left = widget.mapTo(window, widget.rect().topLeft())
            regions.append({
                "name": widget.objectName(),
                "geometry": [top_left.x(), top_left.y(),
                             widget.width(), widget.height()],
            })
        if isinstance(widget, (QAbstractButton, QComboBox)):
            top_left = widget.mapTo(window, widget.rect().topLeft())
            caption = qt_widget_text(widget)
            if page.isAncestorOf(widget):
                workspace_action_captions.append(caption)
            actions.append({
                "class": type(widget).__name__,
                "object_name": widget.objectName(),
                "caption": caption,
                "geometry": [top_left.x(), top_left.y(),
                             widget.width(), widget.height()],
            })
    header = window.header
    return {
        "size_name": size_name, "workspace": "photos-disconnected",
        "image": filename, "requested_size": list(VISUAL_SIZES[0][1:]),
        "requested_window_state": "normal", "actual_window_state": "normal",
        "selected_page": page.objectName().removeprefix("workspace-"),
        "observed_client_size": bounds[2:], "image_size": list(image_size),
        "image_pixel_sha256": visual_image_digest(output / filename),
        "capture_pixel_scale": [
            image_size[0] / bounds[2], image_size[1] / bounds[3],
        ],
        "workspace_visible": True, "lightbox_visible": False,
        "visible_photo_count": 0, "regions": regions, "actions": actions,
        "connect_card_geometry": card_geometry,
        "connect_card_workspace_size": page_size,
        "fixture_gallery_id": None, "fixture_photo_ids": [],
        "fixture_state": "fresh-disconnected-profile", "ratio_captions": [],
        "selected_ratio": None,
        "latest_activity_text": window.latest_activity.text(),
        "workspace_action_captions": workspace_action_captions,
        "tagline_visible": header.tagline.isVisibleTo(window),
        "header_reflow": {
            "mode": header._mode[0] if header._mode is not None else "unlaid",
            "available_width": header.width(),
            "brand_width": header.brand.sizeHint().width(),
            "tagline_width": header.tagline.sizeHint().width(),
            "version_width": header.version.sizeHint().width(),
            "links_width": header.links.sizeHint().width(),
            "links_hidden": header.links.isHidden(),
            "actions_width": header.actions.sizeHint().width(),
        },
    }


def capture_qt_connecting(
    app: QApplication, window: QtWindow, output: Path,
) -> dict[str, JsonValue]:
    """Capture Qt's real request, browser-approval and cancellation UI phases.

    Args:
        app (QApplication): Native event loop processing the visible window.
        window (QtWindow): Fresh production shell with disconnected Photos.
        output (Path): Directory receiving the native pending-state capture.
    Returns:
        dict[str, JsonValue]: Actual connect card, disabled action and status.
    Raises:
        RuntimeError: Consent admission did not reach the visible pending state.
    """
    photos = window.photos
    history_path = photos.profile / "recent-gallery-links.json"
    save_recent_gallery_addresses(history_path, (VISUAL_PHOTOS_ISSUER,))
    photos._recent_gallery_addresses = (VISUAL_PHOTOS_ISSUER,)
    window.showNormal()
    window.resize(VISUAL_SIZES[0][1], VISUAL_SIZES[0][2])
    window.show_page("photos")
    settle_qt(app)
    if photos._identity is not None or not photos.link_button.isEnabled():
        raise RuntimeError("Qt connecting fixture did not start disconnected.")
    request_entered = Event()
    release_request = Event()
    callback_entered = Event()
    callback_cancelled = Event()

    def gate_request(
        attempt: LinkingAttempt, *, prefer_upload: bool = False,
        prefer_rolling: bool = False, prefer_presentation: bool = False,
        prefer_admin_media: bool = False, require_admin_media: bool = False,
    ) -> PushedAuthorization:
        """Hold the worker at the request handoff until requesting is captured.

        Args:
            attempt (LinkingAttempt): Real ephemeral loopback attempt.
            prefer_upload (bool): Upload preference from the Photos owner.
            prefer_rolling (bool): Renewal preference from the Photos owner.
            prefer_presentation (bool): Presentation preference from the owner.
            prefer_admin_media (bool): Admin preference from the Photos owner.
            require_admin_media (bool): Whether the requested grant is mandatory.
        Returns:
            PushedAuthorization: Local synthetic authorization URL.
        Raises:
            LinkingError: The bounded fixture gate did not release in time.
        """
        request_entered.set()
        if not release_request.wait(VISUAL_OPERATION_TIMEOUT_SECONDS):
            raise LinkingError("Synthetic authorization handoff timed out.")
        return hold_visual_authorization_request(
            attempt, prefer_upload=prefer_upload,
            prefer_rolling=prefer_rolling,
            prefer_presentation=prefer_presentation,
            prefer_admin_media=prefer_admin_media,
            require_admin_media=require_admin_media,
        )

    def wait_for_fixture_callback(
        attempt: LinkingAttempt,
    ) -> Literal["approved", "denied"]:
        """Wait only for the native Cancel action to interrupt this fixture.

        Args:
            attempt (LinkingAttempt): Real local attempt whose cancellation
                event is owned and signalled by Photos.
        Returns:
            Literal["approved", "denied"]: Never returns an approval result.
        Raises:
            LinkingError: The native Cancel action closes the local attempt or
                the bounded fixture wait expires.
        """
        callback_entered.set()
        if not attempt._cancelled.wait(VISUAL_OPERATION_TIMEOUT_SECONDS):
            raise LinkingError("Synthetic callback wait expired.")
        callback_cancelled.set()
        raise LinkingError("Synthetic callback wait cancelled by Photos.")

    filename = f"qt-{VISUAL_SIZES[0][0]}-photos-connecting.png"
    approval_filename = (
        f"qt-{VISUAL_SIZES[0][0]}-photos-connecting-approved.png"
    )
    request_image_size: list[int] = []
    approval_image_size: list[int] = []
    request_status = ""
    status = ""
    cancelled_status = ""
    cancelled = False
    cancel_caption = ""
    invalid_address_rejected = False
    connect_action_enabled = True
    progress_visible = False
    request_dialog_visible = False
    bounds: list[int] = []
    approval_bounds: list[int] = []
    address_geometry: list[int] = []
    actions: list[dict[str, JsonValue]] = []
    dialog = None
    address_field = None
    submit_action = None
    cancel_action = None
    with (
        mock.patch.object(
            LinkingAttempt, "push_request", gate_request,
        ),
        mock.patch.object(
            LinkingAttempt, "wait_for_callback", wait_for_fixture_callback,
        ),
        mock.patch.object(webbrowser, "open", return_value=True),
    ):
        try:
            photos.link_button.click()
            dialog = photos._authorization_dialog
            if dialog is None:
                raise RuntimeError("Qt Photos did not open its address dialog.")
            address_field = dialog.findChild(QLineEdit, "photosAuthorizationAddress")
            submit_action = dialog.findChild(
                QAbstractButton, "photosAuthorizationSubmit",
            )
            cancel_action = dialog.findChild(
                QAbstractButton, "photosAuthorizationCancel",
            )
            recent_tree = dialog.findChild(QTreeWidget, "photosAuthorizationRecent")
            if address_field is None or submit_action is None or cancel_action is None:
                raise RuntimeError("Qt Photos address dialog is missing native controls.")
            recent_rows = []
            if recent_tree is not None:
                for index in range(recent_tree.topLevelItemCount()):
                    group = recent_tree.topLevelItem(index)
                    recent_rows.extend(group.child(row) for row in range(group.childCount()))
            recent_addresses = tuple(row.data(0, Qt.ItemDataRole.UserRole) for row in recent_rows)
            recent_addresses_visible = bool(
                recent_tree is not None
                and recent_tree.isVisibleTo(dialog)
                and recent_addresses == (VISUAL_PHOTOS_ISSUER,)
                and all(recent_tree.visualItemRect(row).height() > 0 for row in recent_rows)
            )
            if not recent_addresses_visible:
                raise RuntimeError(
                    "Qt linking prompt did not show the isolated recent-address fixture."
                )
            address_field.setText("not a gallery address")
            settle_qt(app)
            if not dialog.isVisible():
                raise RuntimeError("Qt Photos address dialog is not visible.")
            submit_action.click()
            wait_qt(app, lambda: (
                dialog.isVisible()
                and photos._authorization_status.text() == tr(
                    photos.language, 'linking_website_address_invalid',
                )
                and not request_entered.is_set()
            ))
            invalid_address_rejected = True
            address_field.setText("visual-fixture.invalid/")
            settle_qt(app)
            submit_action.click()
            wait_qt(app, lambda: (
                request_entered.is_set()
                and photos._authorization_active
                and not photos.link_button.isEnabled()
                and dialog.isVisible()
                and cancel_action.isVisibleTo(dialog)
                and cancel_action.isEnabled()
                and photos._authorization_status.text() == tr(
                    photos.language, "linking_requesting",
                )
            ))
            request_status = photos._authorization_status.text()
            request_dialog_visible = dialog.isVisible()
            bounds = [dialog.x(), dialog.y(), dialog.width(), dialog.height()]
            request_image_size = save_qt_client(dialog, output / filename)
            release_request.set()
            wait_qt(app, lambda: (
                callback_entered.is_set()
                and photos._authorization_active
                and photos._authorization_status.text() == tr(
                    photos.language, "linking_approve_browser",
                )
                and dialog.isVisible()
                and cancel_action.isVisibleTo(dialog)
                and cancel_action.isEnabled()
            ))
            status = photos._authorization_status.text()
            page = window._pages["photos"]
            if window.tabs.currentWidget() is not page:
                raise RuntimeError(
                    "Qt pending consent changed the selected workspace."
                )
            connect_action_enabled = submit_action.isEnabled()
            progress_visible = photos.progress.isVisibleTo(window)
            approval_bounds = [
                dialog.x(), dialog.y(), dialog.width(), dialog.height(),
            ]
            approval_image_size = save_qt_client(dialog, output / approval_filename)
            anchor = address_field.mapTo(dialog, address_field.rect().topLeft())
            address_geometry = [anchor.x(), anchor.y(), address_field.width(), address_field.height()]
            actions = []
            for widget in dialog.findChildren(QWidget):
                if not widget.isVisibleTo(dialog):
                    continue
                if isinstance(widget, (QAbstractButton, QComboBox)):
                    top_left = widget.mapTo(dialog, widget.rect().topLeft())
                    actions.append({
                        "class": type(widget).__name__,
                        "object_name": widget.objectName(),
                        "caption": qt_widget_text(widget),
                        "geometry": [top_left.x(), top_left.y(),
                                     widget.width(), widget.height()],
                    })
            cancel_caption = qt_widget_text(cancel_action)
            cancel_action.click()
            wait_qt(app, lambda: (
                callback_cancelled.is_set()
                and not photos._authorization_active
                and photos._authorization_dialog is None
                and photos.status.text() == gettext("Gallery linking cancelled.")
            ))
            cancelled_status = photos.status.text()
            cancelled = (
                callback_cancelled.is_set()
                and not photos._authorization_active
                and photos._attempt is None
            )
        finally:
            release_request.set()
            if photos._authorization_active:
                photos.cancel_authorization()
                settle_qt(app)
    pending = bool(request_status and status)
    if not pending:
        raise RuntimeError("Qt Photos authorization did not traverse both pending phases.")
    if not cancelled:
        raise RuntimeError("Qt Photos native Cancel did not fence the pending authorization.")
    if not cancel_caption:
        raise RuntimeError("Qt Photos native Cancel action had no visible caption.")
    size_name = VISUAL_SIZES[0][0]
    supplement = {
        "name": "authorization-browser-approval",
        "image": approval_filename,
        "image_size": approval_image_size,
        "image_pixel_sha256": visual_image_digest(output / approval_filename),
        "observed_client_size": approval_bounds[2:],
    }
    settle_qt(app)
    return {
            "size_name": size_name, "workspace": "photos-connecting",
            "image": filename, "requested_size": list(VISUAL_SIZES[0][1:]),
            "requested_window_state": "normal", "actual_window_state": "normal",
            "selected_page": page.objectName().removeprefix("workspace-"),
            "observed_client_size": bounds[2:],
            "image_size": request_image_size,
            "image_pixel_sha256": visual_image_digest(output / filename),
            "capture_pixel_scale": [
                request_image_size[0] / bounds[2],
                request_image_size[1] / bounds[3],
            ],
            "workspace_visible": request_dialog_visible,
            "lightbox_visible": False, "visible_photo_count": 0,
            "regions": [{
                "name": "photosAuthorizationDialog",
                "geometry": [0, 0, bounds[2], bounds[3]],
            }, {
                "name": "photosAuthorizationAddress",
                "geometry": address_geometry,
            }],
            "actions": actions, "fixture_gallery_id": None,
            "fixture_photo_ids": [], "fixture_state": "pending-browser-consent",
            "pending_auth_state": pending, "pending_status": request_status,
            "pending_request_status": request_status,
            "pending_approval_status": status,
            "connect_action_enabled": connect_action_enabled,
            "recent_addresses": list(recent_addresses),
            "recent_addresses_visible": recent_addresses_visible,
            "progress_visible": progress_visible,
            "cancel_action_caption": cancel_caption,
            "invalid_address_rejected": invalid_address_rejected,
            "cancel_action_invoked": True, "cancelled_state": cancelled,
            "callback_wait_entered": callback_entered.is_set(),
            "callback_wait_cancelled": callback_cancelled.is_set(),
            "cancelled_status": cancelled_status,
            "latest_activity_text": window.latest_activity.text(),
            "ratio_captions": [], "selected_ratio": None,
            "selected_photo_ids": [], "supplemental_images": [supplement],
        }


def wait_qt(app: QApplication, predicate: Callable[[], bool]) -> None:
    """Pump Qt until one synthetic fixture transition is visibly owned.

    Args:
        app (QApplication): Existing native Qt application singleton.
        predicate (Callable[[], bool]): Current state condition to observe.
    Returns:
        None: The transition completed or raises a bounded hosted failure.
    Raises:
        RuntimeError: The production owner did not reach the expected state.
    """
    deadline = time.monotonic() + VISUAL_OPERATION_TIMEOUT_SECONDS
    while time.monotonic() < deadline:
        app.processEvents(
            QEventLoop.ProcessEventsFlag.AllEvents, VISUAL_QT_EVENT_SLICE_MS,
        )
        if predicate():
            return
        time.sleep(VISUAL_PUMP_DELAY_SECONDS)
    raise RuntimeError("Qt visual fixture transition did not settle.")


def main() -> int:
    """Dispatch one hosted backend child without changing production code.

    Returns:
        int: Zero after a complete child report, nonzero on capture failure.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--capture-backend", choices=("tk", "qt"))
    parser.add_argument("--visual-slice", choices=VISUAL_CAPTURE_SLICE_IDS)
    parser.add_argument("--locale-probe", choices=("cs",))
    parser.add_argument("--watcher-recovery-probe", action="store_true")
    parser.add_argument("--synthetic-scale", choices=("1.5",))
    parser.add_argument("--profile", type=Path)
    parser.add_argument("--output", type=Path)
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()
    if args.capture_backend is None:
        parser.error("A hosted visual backend child is required.")
    if args.profile is None or args.output is None or args.report is None:
        parser.error("Profile, output directory and report path are required.")
    if args.locale_probe == "cs" and args.watcher_recovery_probe:
        parser.error("The Czech and pending-Watcher probes are independent lanes.")
    if args.visual_slice and (
        args.locale_probe or args.watcher_recovery_probe or args.synthetic_scale
    ):
        parser.error("A production visual slice is an independent capture lane.")
    if args.synthetic_scale and (args.locale_probe or args.watcher_recovery_probe):
        parser.error("Synthetic scale is an independent hosted visual lane.")
    if args.synthetic_scale and sys.platform != "linux":
        parser.error("Synthetic fractional scale is supported only by hosted Xvfb.")
    if (args.locale_probe == "cs" or args.watcher_recovery_probe
            or args.synthetic_scale or args.visual_slice):
        global VISUAL_SIZES, VISUAL_WORKSPACES, VISUAL_SCENARIO_WORKSPACES
        global VISUAL_CAPTURE_SLICE_ID
    if args.visual_slice:
        (
            VISUAL_SIZES, VISUAL_WORKSPACES, VISUAL_SCENARIO_WORKSPACES,
            _include_watcher_probe,
        ) = VISUAL_CAPTURE_SLICES[args.visual_slice]
        VISUAL_CAPTURE_SLICE_ID = args.visual_slice
    elif args.locale_probe == "cs":
        VISUAL_SIZES = VISUAL_CZECH_SIZES
        VISUAL_WORKSPACES = ("settings",)
        VISUAL_SCENARIO_WORKSPACES = (
            "photos-disconnected", "photos-connecting", "photos-ratio-16-9",
        )
        os.environ["PHP_GALLERY_VISUAL_LOCALE_PROBE"] = "cs"
    elif args.watcher_recovery_probe:
        VISUAL_SIZES = (VISUAL_SIZES[0],)
        VISUAL_WORKSPACES = ("watcher",)
        VISUAL_SCENARIO_WORKSPACES = ()
        os.environ[VISUAL_WATCHER_RECOVERY_PROBE] = "1"
    elif args.synthetic_scale:
        if float(args.synthetic_scale) != VISUAL_SYNTHETIC_SCALE:
            parser.error("Unsupported synthetic scale factor.")
        VISUAL_SIZES = (VISUAL_SYNTHETIC_SIZE,)
        VISUAL_WORKSPACES = ("photos",)
        VISUAL_SCENARIO_WORKSPACES = ()
        os.environ["PHP_GALLERY_VISUAL_SYNTHETIC_SCALE"] = args.synthetic_scale
    args.output.mkdir(parents=True, exist_ok=True)
    if args.capture_backend == "qt" and QApplication is None:
        raise AssertionError(
            "The opted-in native Qt visual frontend did not load."
        ) from QT_LOAD_ERROR
    capture = capture_tk if args.capture_backend == "tk" else capture_qt
    report = capture(args.profile, args.output)
    args.report.write_text(json.dumps(report, indent=2), encoding="utf-8")
    return 1 if report.get("status") == "FAIL" else 0


if __name__ == "__main__":
    raise SystemExit(main())
