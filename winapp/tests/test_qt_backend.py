# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_qt_backend.py
# Module Type: Hosted Qt widget regressions
# Purpose: Verify real Qt scene composition, codec paths and startup readiness.
# Responsibilities: Use synthetic images and isolated per-case profiles only.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Exercise the supported Qt paths through canonical WinApp discovery."""

import faulthandler
import gc
from dataclasses import replace
from hashlib import sha256
from io import BytesIO
from math import ceil, floor
import os
from pathlib import Path
from queue import Queue
import subprocess
import sys
import tempfile
import threading
from threading import Event
import time
from typing import Callable
import unittest
from unittest import mock

from PIL import Image

from native_qt_fixture import NativeQtTestCase
from qualify_loopback_authority import (
    FIXTURE_ADMIN_ID, FIXTURE_CALLBACK_WAIT_SECONDS,
    FIXTURE_OPERATION_TIMEOUT_SECONDS, LoopbackLinkingAuthority,
)
from test_loopback_linked_switch_fixture import _MemoryCredentialStore
from uploader import app_linking, flight_workflow
from uploader.app_linking import LinkedMediaDetails
from uploader.capture_session import CaptureStatus
from uploader.config import BYTES_PER_GIB
from uploader.credential_store import CredentialStoreUnavailable
from uploader.desktop_connection import (
    ConnectionActions, ConnectionTestResult, DependencyActions, DesktopLinkedSite,
    LinkedRecoveryActions,
    RuntimeSettings, RuntimeValues,
)
from uploader.flight_data import FlightAnalysis
from uploader.flight_jobs import FlightPreferences, REPORT_LANGUAGES, SessionPlan, WatcherContinuation, WorkspaceLease
from uploader.flight_render import ReportMaps, ReportRenderer
from uploader.flight_report_translations import tr
from uploader.flight_simbrief import DispatchPlan, load_dispatch
from uploader.flight_workflow import FinalizationWorker
from uploader.gallery_image_policy import MEDIA_HTTP_BATCH_INITIAL_SIZE
from uploader.gallery_lightbox import (
    LIGHTBOX_TOUCHPAD_ZOOM_DELTA_UNITS, LIGHTBOX_WHEEL_DELTA_UNITS,
    LIGHTBOX_WHEEL_STEP,
)
from uploader.gallery_preload import probe_gallery_cache_status
from uploader.gallery_view_preferences import (
    load_gallery_card_format, load_gallery_folder_split, load_gallery_photo_order,
    load_photos_tree_preferences, photos_account_tree_path, save_gallery_card_format,
    save_gallery_photo_order,
    save_photos_tree_preferences,
)
from uploader.gui_backend import load_gui_backend, save_gui_backend
from uploader.gui_backend_profile import inspect_gui_profile_work
from uploader.import_commands import ImportActions, ImportSource
from uploader.import_ui_snapshot import (
    ImportHistorySummary, ImportItemView, ImportProgressCounts,
    ImportProgressSnapshot, new_worker_generation,
)
from uploader.linked_create import BoundCreateReceipt
from uploader.linked_upload import LinkedUploadTarget
from uploader.localization import configure_language, current_language, gettext
from uploader.media import MediaCapability
from uploader.models import ImportItem, ImportJob, ImportPlan
from uploader.offline_linking import (
    OfflineBinding, load_offline_binding, offline_binding_path,
    save_offline_binding,
)
from uploader.self_update import ReleaseAsset
from uploader.state_store import (
    load_recent_gallery_addresses, save_recent_gallery_addresses,
)
from uploader.ui_icons import render_smooth_icon, render_toggle_switch
from uploader.ui_layout import (
    PHOTOS_TREE_WIDTH, UI_LAYOUT_INFO_WIDTH_BOUNDS,
    UI_LAYOUT_VIEWER_MINIMUM_WIDTH,
)
from uploader.upload_mode import ModeRecord
from test_redesign import MAIN

QT_LOAD_ERROR: ImportError | OSError | None = None
try:
    from PySide6.QtCore import (
        QEvent, QEventLoop, QModelIndex, QObject, QPoint, QPointF, QSize, Qt, QTimer,
    )
    from PySide6.QtGui import (
        QAccessible, QColor, QContextMenuEvent, QHelpEvent, QIcon, QImage, QWheelEvent,
    )
    from PySide6.QtQml import QQmlError
    from PySide6.QtTest import QTest
    from PySide6.QtWidgets import (
        QApplication, QCheckBox, QComboBox, QDialog, QFrame, QLabel,
        QLineEdit, QMessageBox, QPushButton, QTreeView, QTreeWidget, QTreeWidgetItem,
        QSplitter, QToolTip, QWidget,
    )
    from PySide6.QtQuick import QQuickItem
    from uploader.qt_application import QT_WINDOW_MINIMUM, QtWindow, run_qt
    from uploader.qt_capture import WATCHER_START_PROGRESS_RANGE
    from uploader.qt_ai import (
        AI_ACTION_MIN_HEIGHT_PX, LinkedAiModeRefusal, QtAiWorker,
    )
    from uploader.app_linking import (
        CALLBACK_LIFETIME_SECONDS, GALLERIES_SCOPE,
        ROLLING_GALLERIES_SCOPE, ROLLING_UPLOAD_SCOPE,
        LinkedGallery, LinkedGalleryEditSnapshot, LinkedIdentity,
        LinkedPhoto, LinkedPresentation, LinkedTrack, LinkingError, LinkingSession, MediaReadTimings,
        ThumbnailBatchWitness, ThumbnailPublication,
    )
    from uploader.qt_photos import (
        LinkedPhotos, PHOTO_TREE_MARKER_SIZE, QtGalleryCacheProbe,
    )
    from uploader.qt_import import QtImport
    from uploader.qt_operator_views import (
        ACTIVITY_EVENT_COLUMN_WIDTHS, ActivityPage, OperatorPage, OperatorTable,
    )
    from uploader.qt_photo_model import PhotoListModel
    from uploader.qt_photo_source import (
        PhotoFrame, PhotoRow, ThumbnailActivity, ThumbnailGroup, decode_photo, stream_thumbnails,
    )
    from uploader.qt_theme import (
        ACTION_ICON_SIZE, ICON_DENSITIES, _switch_pixmap, apply_qt_theme, qt_icon,
    )
    from uploader import qt_update
except (ImportError, OSError) as error:
    QT_LOAD_ERROR = error
    QApplication = None

# Purpose: Bound GUI acceptance waits while tolerating cold hosted graphics.
# Type: int. Units: milliseconds. Scope: each native scene acceptance step.
# Consumers: QtBackendTests.wait_for.
# Rationale: Ten seconds permits initial native scene and codec setup per step.
SCENE_WAIT_MS = 10_000
# Purpose: Yield to queued native scene frames while waiting for an assertion.
# Type: int. Units: milliseconds. Scope: one GUI observation interval.
# Consumers: QtBackendTests.wait_for.
# Rationale: 25 ms avoids a busy probe and keeps short read tests responsive.
OBSERVATION_MS = 25
# Purpose: Bound the independent actual Qt event-loop lifecycle fixture.
# Type: int. Units: seconds. Scope: one child Qt process.
# Consumers: QtBackendTests.test_ready_child_runs_to_normal_close.
# Rationale: 20 allows cold Qt startup and stays under the partition budget.
QT_CHILD_SECONDS = 20
# Purpose: Use one positive synthetic original size in linked recovery UI tests.
# Type: int. Units: bytes. Scope: isolated Qt recovery fixture.
# Consumers: gallery-picker recovery prompt and its accepted observation.
# Rationale: 173 exercises the bounded native size field without any source file.
RECOVERY_FIXTURE_FILE_SIZE_BYTES = 173
# Purpose: Reproduce thirteen image-only 21:9 columns and ten visible rows.
# Type: tuple[int, int]. Units: logical pixels. Scope: one native Quick viewport.
# Consumers: QtWidePhotosViewportTests.test_wide_21_9_viewport_fills_all_visible_cards.
# Rationale: 2070 by 790 matches the usable area of the reported wide window.
WIDE_21_9_VIEWPORT = (2070, 790)
# Purpose: Check the same column count as the reported wide grid.
# Type: int. Units: columns. Scope: native wide gallery fixture.
# Consumers: QtWidePhotosViewportTests.test_wide_21_9_viewport_fills_all_visible_cards.
# Rationale: The viewport fits thirteen 158-pixel columns at this aspect ratio.
WIDE_21_9_COLUMNS = 13
# Purpose: Leave disjoint top, middle and bottom windows for texture release.
# Type: int. Units: photos. Scope: same hosted native viewport fixture.
# Consumers: QtWidePhotosViewportTests.test_wide_21_9_viewport_fills_all_visible_cards.
# Rationale: 460 spans the three sample positions with a partially filled end row.
WIDE_21_9_PHOTO_COUNT = 460


@unittest.skipIf(
    QApplication is None and os.environ.get("PHP_GALLERY_REQUIRE_QT") != "1",
    "Optional Qt source dependencies are absent.",
)
class _NativeQtAppFixture(NativeQtTestCase):
    """Own one native Qt application in an isolated audit child."""

    @classmethod
    def setUpClass(cls) -> None:
        """Prepare one Qt application on the test runner's GUI thread.

        Returns:
            None: Hosted Qt jobs require the dependencies and plugin initialization.
        """
        if QApplication is None:
            raise AssertionError("The hosted Qt dependency set could not be loaded.") from QT_LOAD_ERROR
        if cls.delegate_native_cases():
            return
        cls.application = QApplication.instance() or QApplication([])
        # Each fixture owns its window. Closing it must not terminate the
        # subsequent fixture's nested native observation loop.
        cls.application.setQuitOnLastWindowClosed(False)
        apply_qt_theme(cls.application)

    def setUp(self) -> None:
        """Release native wrappers after each test's registered owners close.

        Returns:
            None: Adds final cleanup after per-test window, worker and view owners.
        """
        super().setUp()
        if self._native_report is None:
            self.addCleanup(self._release_native_test_widgets)

    def _release_native_test_widgets(self) -> None:
        """Close owned top-level widgets and collect their wrapper cycles.

        Returns:
            None: Dispatches deferred Qt deletion and reclaims this case's
                otherwise-retained Shiboken wrapper graph on the GUI thread.
        Raises:
            RuntimeError: An owned QtWindow refused its normal shutdown path.
        """
        if self._native_report is not None:
            return
        application = self.application
        widgets = tuple(application.topLevelWidgets())
        widget = None
        for widget in widgets:
            if isinstance(widget, QtWindow):
                if not widget._shutdown_done:
                    widget.exit_window()
                if not widget._shutdown_done:
                    raise RuntimeError(
                        'A native Qt test window remained busy after its cleanups.',
                    )
            else:
                widget.close()
            widget.deleteLater()
        widget = None
        del widgets
        application.sendPostedEvents(None, QEvent.Type.DeferredDelete.value)
        # The Apple sample showed finalization spending most of its time
        # traversing retained Shiboken wrapper cycles; collect per test.
        gc.collect()

    @classmethod
    def tearDownClass(cls) -> None:
        """Release only the Qt application owned by the native fixture child.

        Returns:
            None: Destroys native scene and icon resources before Python exit.
        """
        if cls._native_report is not None:
            return
        application = cls.application
        application.closeAllWindows()
        application.sendPostedEvents(None, QEvent.Type.DeferredDelete.value)
        qt_icon.cache_clear()
        _switch_pixmap.cache_clear()
        application.shutdown()
        cls.application = None
        del application
        gc.collect()

    def wait_for(self, condition: Callable[[], bool]) -> None:
        """Run native event turns until the scene state is observed.

        Args:
            condition (Callable[[], bool]): Current widget or model identity predicate.
        Returns:
            None: Fails after the bounded observation deadline.
        """
        if condition():
            return
        loop = QEventLoop()
        observer = QTimer()
        observer.setInterval(OBSERVATION_MS)
        observer.timeout.connect(lambda: loop.quit() if condition() else None)
        deadline = QTimer()
        deadline.setSingleShot(True)
        deadline.timeout.connect(loop.quit)
        deadline.start(SCENE_WAIT_MS)
        observer.start()
        loop.exec()
        observer.stop()
        deadline.stop()
        if not condition():
            faulthandler.dump_traceback(file=sys.stderr, all_threads=True)
        self.assertTrue(condition())


class QtBackendTests(_NativeQtAppFixture):
    """Qualify genuine Qt widgets when the optional dependency set is present."""

    def test_import_options_expand_to_native_mode_choices_and_snapshot_rows(self) -> None:
        """Expose the Tk review options and stable bounded item projection.

        Returns:
            None: Native choices, headings, formatted values, identity updates
                and the clear lifecycle are visible in the actual Qt widgets.
        """
        actions = ImportActions(
            mock.Mock(), mock.Mock(), mock.Mock(), mock.Mock(), False,
            local_thumbnail_available=True,
        )
        widget = QtImport(
            actions, mock.Mock(), lambda: None, lambda value: value, mock.Mock(),
        )
        self.addCleanup(widget.close_view)
        widget.show()
        QTest.mouseClick(widget.details_toggle, Qt.MouseButton.LeftButton)
        self.assertTrue(widget.details_panel.isVisible())
        self.assertTrue(widget.server_thumbnails.isChecked())
        self.assertFalse(widget.local_thumbnails.isChecked())
        QTest.mouseClick(widget.local_thumbnails, Qt.MouseButton.LeftButton)
        self.assertTrue(widget.local_thumbnails.isChecked())
        self.assertFalse(widget.server_thumbnails.isChecked())

        item = ImportItemView(
            0, 'item-1', 'sample.jpg', 1024, 'local', 'staged', 'Ready', 0, '',
        )
        snapshot = ImportProgressSnapshot(
            generation=new_worker_generation(), revision=0, source_kind='files',
            counts=ImportProgressCounts(0, 0, 0, 0, 1, 0),
            bytes_total=1024, bytes_sent=0, started_at=0.0, finished_at=0.0,
            item_total=1, skipped_total=0, item_row_limit=1, items=(item,),
            complete_rows=True, retry_available=True,
            history=ImportHistorySummary(
                'job-1', 'files', 0.0, 'sample.jpg', 'Gallery',
                'active 1',
            ),
        )
        widget._update_review_snapshot(snapshot)
        self.assertTrue(widget.review_table.isVisible())
        self.assertEqual(1, widget.review_model.rowCount())
        self.assertEqual(
            [MAIN.gettext(label) for label in ('File', 'Size', 'Media', 'Review', 'Detail')],
            [
                widget.review_model.headerData(
                    column, Qt.Orientation.Horizontal, Qt.ItemDataRole.DisplayRole,
                )
                for column in range(widget.review_model.columnCount())
            ],
        )
        self.assertEqual(
            ('sample.jpg', '1.0 KiB'),
            tuple(
                widget.review_model.data(
                    widget.review_model.index(0, column), Qt.ItemDataRole.DisplayRole,
                )
                for column in range(2)
            ),
        )
        widget.review_table.selectRow(0)
        changed_item = replace(item, state='uploading', attempt_count=1)
        changed = replace(snapshot, revision=1, complete_rows=False, items=(changed_item,))
        widget._update_review_snapshot(changed)
        self.assertEqual(1, widget.review_model.rowCount())
        self.assertEqual(
            MAIN.gettext('uploading'),
            widget.review_model.data(
                widget.review_model.index(0, 3), Qt.ItemDataRole.DisplayRole,
            ),
        )
        self.assertEqual('item-1', widget.review_model.data(
            widget.review_model.index(0, 0), Qt.ItemDataRole.UserRole,
        ))
        widget._update_review_snapshot(snapshot)
        self.assertEqual(
            MAIN.gettext('uploading'),
            widget.review_model.data(
                widget.review_model.index(0, 3), Qt.ItemDataRole.DisplayRole,
            ),
        )
        widget._clear_review_snapshot()
        self.assertEqual(0, widget.review_model.rowCount())

    def test_import_preflight_summary_keeps_shared_detail_and_preview_limits(self) -> None:
        """Show exact shared preflight totals, capability and bounded evidence.

        Returns:
            None: Qt displays Tk-equivalent accepted bytes, thumbnail mode,
                media notes, unsupported groups and bounded archive warnings.
        """
        capabilities = {
            '.heic': MediaCapability('.heic', True, True, True, 'decoder available'),
            '.heif': MediaCapability('.heif', True, False, False, 'server only'),
            '.dng': MediaCapability('.dng', True, True, True, 'decoder available'),
        }
        actions = ImportActions(
            mock.Mock(), mock.Mock(), mock.Mock(), mock.Mock(), True,
            local_thumbnail_available=True, media_capabilities=capabilities,
        )
        widget = QtImport(
            actions, mock.Mock(), lambda: None, lambda value: value, mock.Mock(),
        )
        self.addCleanup(widget.close_view)
        item = ImportItem(
            'item-1', 'image.jpg', 'image.jpg', size=1536, state='staged',
            local_decode='server_only',
        )
        job = ImportJob(
            'job-1', 'zip', 'photos.zip', 'Fixture gallery', items=[item],
            bytes_total=1536,
        )
        plan = ImportPlan(
            job, unsupported={'txt': 2, 'exe': 1},
            unsafe_entries=[
                'unsafe-a', 'unsafe-b', 'unsafe-c', 'unsafe-d',
            ],
            duplicate_local=1, duplicate_remote=2,
            warnings=['Warning A', 'Warning B', 'Warning C', 'Warning D'],
        )

        widget._show_plan(plan)

        expected = '\n'.join((
            MAIN.gettext('Target: {gallery}').format(gallery='Fixture gallery'),
            MAIN.gettext(
                'Supported files found: {found}  |  Ready to upload: {ready}  |  '
                'Duplicate candidates: {duplicates}'
            ).format(found=1, ready=1, duplicates=3),
            MAIN.gettext(
                'Estimated accepted bytes: {size}  |  Unsupported skipped: {unsupported}  |  '
                'Unsafe ZIP entries rejected: {rejected}'
            ).format(size='1.5 KiB', unsupported=3, rejected=4),
            MAIN.gettext('Thumbnail mode: {mode}').format(mode=MAIN.gettext(
                'This PC creates derivatives with server fallback.',
            )),
            MAIN.gettext(
                'Source deletion: Original files and ZIP archives are kept for manual verification.',
            ),
            MAIN.gettext('Local HEIC/DNG capability: ')
            + '.heic: ' + MAIN.gettext('local decoder available')
            + '; .heif: ' + MAIN.gettext('server upload only on this PC')
            + '; .dng: ' + MAIN.gettext('local decoder available'),
            MAIN.gettext('Unsupported: {types}').format(types='exe: 1, txt: 2'),
            MAIN.gettext('Rejected archive entries: ')
            + 'unsafe-a; unsafe-b; unsafe-c ...',
            MAIN.gettext('Warnings: {details}').format(
                details=' | '.join(MAIN.gettext(item) for item in (
                    'Warning A', 'Warning B', 'Warning C',
                )),
            ),
        ))
        self.assertEqual(expected, widget.summary.text())
        self.assertNotIn('unsafe-d', widget.summary.text())
        self.assertNotIn('Warning D', widget.summary.text())
        unavailable_actions = ImportActions(
            mock.Mock(), mock.Mock(), mock.Mock(), mock.Mock(), True,
            local_thumbnail_available=False, media_capabilities=capabilities,
        )
        unavailable = QtImport(
            unavailable_actions, mock.Mock(), lambda: None,
            lambda value: value, mock.Mock(),
        )
        self.addCleanup(unavailable.close_view)
        unavailable._show_plan(plan)
        self.assertFalse(unavailable.local_thumbnails.isEnabled())
        self.assertTrue(unavailable.server_thumbnails.isChecked())
        self.assertIn(
            MAIN.gettext('Thumbnail mode: {mode}').format(
                mode=MAIN.gettext('Server creates thumbnails.'),
            ),
            unavailable.summary.text(),
        )

    def test_activity_event_table_keeps_reference_labels_and_column_bases(self) -> None:
        """Keep the Recent events table readable like the Tk reference.

        Returns:
            None: Labels and fixed widths match Tk while File and Event share surplus.
        """
        jobs = OperatorPage(
            (
                MAIN.gettext("Created"), MAIN.gettext("Source"),
                MAIN.gettext("Target"), MAIN.gettext("Result"),
            ),
            MAIN.gettext("Select a gallery connection to start an import."),
            lambda: None,
        )
        items = OperatorTable((
            MAIN.gettext("File"), MAIN.gettext("State"),
            MAIN.gettext("Tries"), MAIN.gettext("Result / error"),
        ))
        activity = ActivityPage(
            jobs, items, lambda: None, lambda: None, lambda: None,
            lambda: None, lambda: None, lambda: None,
        )
        self.addCleanup(activity.close)
        self.addCleanup(jobs.close)
        activity.show()
        QApplication.processEvents()
        event_model = activity.table.model()
        labels = [
            event_model.headerData(
                index, Qt.Orientation.Horizontal, Qt.ItemDataRole.DisplayRole,
            )
            for index in range(event_model.columnCount())
        ]
        self.assertEqual(
            labels,
            [
                MAIN.gettext("Time"), MAIN.gettext("Status"),
                MAIN.gettext("File"), MAIN.gettext("Type"),
                MAIN.gettext("Event"),
            ],
        )
        header = activity.table.horizontalHeader()
        self.assertEqual(
            [header.sectionSize(index) for index in (0, 1, 3)],
            [
                ACTIVITY_EVENT_COLUMN_WIDTHS[0],
                ACTIVITY_EVENT_COLUMN_WIDTHS[1],
                ACTIVITY_EVENT_COLUMN_WIDTHS[3],
            ],
        )
        surplus = max(0, activity.table.viewport().width() - sum(ACTIVITY_EVENT_COLUMN_WIDTHS))
        file_extra = header.sectionSize(2) - ACTIVITY_EVENT_COLUMN_WIDTHS[2]
        event_extra = header.sectionSize(4) - ACTIVITY_EVENT_COLUMN_WIDTHS[4]
        self.assertEqual(surplus, file_extra + event_extra)
        self.assertLessEqual(abs(file_extra - event_extra), 1)
        self.assertFalse(header.stretchLastSection())

    def test_watcher_controls_keep_the_capture_owner_through_interrupt(self) -> None:
        """Exercise native start, re-entry denial, and explicit retained Stop.

        Returns:
            None: Flight and the header reflect the worker, Stop observes the
                request fence, and the window exits after the worker stops.
        """
        commands = mock.Mock()
        commands.events = Queue()
        commands.snapshot.return_value = CaptureStatus()
        commands.report_target.return_value = None
        watching = CaptureStatus(
            phase='watching', alive=True, watcher_worker_active=True,
            interrupt_available=True, capturing=True, telemetry=(63.0, 1800.0, 2.0),
        )
        starting = replace(watching, watcher_starting=True)
        start_entered = Event()
        release_start = Event()

        def hold_start_until_released() -> None:
            """Hold Watcher startup while the native busy state is observed.

            Returns:
                None: Publishes an active worker still scanning its initial folder.
            """
            start_entered.set()
            # Event.wait takes seconds; SCENE_WAIT_MS remains the shared bound.
            if not release_start.wait(SCENE_WAIT_MS / 1000):
                raise RuntimeError('Synthetic Watcher start was not released.')
            commands.snapshot.return_value = starting

        commands.start.side_effect = hold_start_until_released
        commands.interrupt.side_effect = lambda: setattr(
            commands.snapshot, 'return_value', CaptureStatus(phase='idle'),
        )
        with tempfile.TemporaryDirectory() as folder:
            window = QtWindow(Path(folder), MAIN.APP_VERSION, '', 100, False, capture=commands)
            self.addCleanup(window.shutdown)
            self.addCleanup(release_start.set)
            capture = window.capture
            assert capture is not None
            window.show()
            window.show_page('watcher')
            capture.start_button.click()
            self.wait_for(lambda: start_entered.is_set()
                          and capture.watcher_start_progress.isVisible())
            self.assertEqual(gettext('Starting Watcher…'), capture.start_button.text())
            self.assertEqual(gettext('Starting Watcher…'), capture.watcher_state.text())
            capture_action = capture.import_capture_actions()
            self.assertEqual(gettext('Starting Watcher…'), capture_action[0])
            self.assertIsNone(capture_action[1])
            self.assertEqual(
                WATCHER_START_PROGRESS_RANGE[0],
                capture.watcher_start_progress.minimum(),
            )
            self.assertEqual(
                WATCHER_START_PROGRESS_RANGE[1],
                capture.watcher_start_progress.maximum(),
            )
            self.assertFalse(capture.start_button.isEnabled())
            release_start.set()
            self.wait_for(lambda: not capture._busy)
            self.assertTrue(capture.watcher_start_progress.isVisible())
            self.assertEqual(gettext('Starting Watcher…'), capture.watcher_state.text())
            commands.snapshot.return_value = watching
            capture._poll()
            self.wait_for(lambda: capture.interrupt_button.isEnabled())
            self.assertFalse(capture.watcher_start_progress.isVisible())
            self.assertEqual(gettext('Watcher is still running'), capture.watcher_state.text())
            self.assertEqual(
                capture.finish_button.text(), capture.import_capture_actions()[0],
            )
            self.assertTrue(window.operator_busy())
            self.assertTrue(window._monitor_active)
            self.assertEqual('IAS 122 kt · ALT 5,906 ft · V/S +394 fpm', capture.instrument.text())
            self.assertFalse(capture.start_button.isEnabled())
            window.restart()
            self.assertFalse(window.restart_requested)
            commands.snapshot.return_value = CaptureStatus(
                phase='finishing', alive=True, watcher_worker_active=True, finishing=True,
            )
            capture._poll()
            self.assertTrue(capture.interrupt_button.isHidden())
            commands.interrupt.assert_not_called()
            commands.snapshot.return_value = watching
            capture._poll()
            with mock.patch.object(QMessageBox, 'question', return_value=QMessageBox.StandardButton.Yes):
                capture.interrupt_button.click()
            self.wait_for(lambda: capture.review_button.isEnabled() and not capture.busy())
            self.assertFalse(window._monitor_active)
            commands.start.assert_called_once_with()
            commands.interrupt.assert_called_once_with()
            commands.recover.assert_not_called()
            language = current_language()
            try:
                configure_language('cs')
                stopped_message = 'Watcher stopped; pending photographs are retained.'
                translated = gettext(stopped_message)
                self.assertNotEqual(stopped_message, translated)
                commands.events.put(('info', stopped_message))
                window.statusBar().clearMessage()
                capture._poll()
                self.assertEqual(translated, window.latest_activity.text())
            finally:
                configure_language(language)
            window.exit_window()
            self.assertFalse(window.isVisible())

    def test_profile_settings_save_only_changes_to_the_existing_owner(self) -> None:
        """Exercise the native form without replacing the current profile generation.

        Returns:
            None: Only changed fields are saved, including when older worker
                settings contain a valid count outside the offered presets.
                Native Settings keeps the visual and keyboard focus order,
                and the Watcher sheet saves independently and respects Escape.
        """
        config = MAIN.WatcherConfig()
        config.close_to_tray = False
        config.manual_thumbnail_workers = 3  # Valid existing count outside the preset list.
        changes: list[RuntimeValues] = []
        stored = config.to_dict()

        def save(values: RuntimeValues) -> RuntimeValues:
            """Record which fields the native draft actually edited.

            Args:
                values (RuntimeValues): Changed configuration values only.
            Returns:
                RuntimeValues: Complete accepted settings for form readback.
            """
            changes.append(values)
            stored.update(values)
            return dict(stored)

        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            save_gui_backend(profile, 'pyside6')
            window = QtWindow(
                profile, MAIN.APP_VERSION, '', config.activity_history_limit, False,
                runtime_settings=RuntimeSettings(lambda: dict(stored), save),
            )
            self.addCleanup(window.shutdown)
            fields = window.settings.fields
            thumbnails = fields['manual_thumbnail_workers']
            uploads = fields['manual_upload_workers']
            assert isinstance(thumbnails, QComboBox) and isinstance(uploads, QComboBox)
            self.assertEqual(config.manual_thumbnail_workers, thumbnails.currentData())
            self.assertEqual(-1, uploads.findData(MAIN.MAX_UPLOAD_WORKERS + 1))
            gallery_url = fields['gallery_url']
            api_key = fields['api_key']
            assert isinstance(gallery_url, QLineEdit) and isinstance(api_key, QLineEdit)
            self.assertEqual('Gallery URL', gallery_url.accessibleName())
            self.assertEqual('API key', api_key.accessibleName())
            self.assertEqual('Language', window.settings.language.accessibleName())
            self.assertEqual(
                'Server thumbnails per request', window.settings.batch_size.accessibleName(),
            )
            self.assertEqual('GUI backend', window.settings.backend.accessibleName())
            restart_accessible = QAccessible.queryAccessibleInterface(
                window.settings.restart_button,
            )
            self.assertIsNotNone(restart_accessible)
            self.assertEqual(
                'Restart WinApp', restart_accessible.text(QAccessible.Text.Name),
            )
            self.assertTrue(
                window.settings.restart_button.isHidden(),
                'An already-persisted PySide6 backend must not show Restart.',
            )
            fields['gallery_url'].setText('https://fixture.example.test/')
            fields['close_to_tray'].setChecked(True)
            window.settings._save_runtime()
            self.assertEqual([{
                'gallery_url': 'https://fixture.example.test/', 'close_to_tray': True,
            }], changes)
            self.assertEqual(config.manual_thumbnail_workers, thumbnails.currentData())
            self.assertTrue(window.close_to_tray)
            self.assertEqual('https://fixture.example.test/', window.photos.gallery_address)
            window.show()
            window.show_page('settings')
            window.raise_()
            window.activateWindow()
            self.assertTrue(
                QTest.qWaitForWindowActive(window, SCENE_WAIT_MS),
                'The visible Settings window did not become the active Qt window.',
            )
            self.wait_for(lambda: window.settings.isVisible() and gallery_url.isVisible())
            body = window.settings.widget()
            assert body is not None
            connection_y = gallery_url.mapTo(body, QPoint(0, 0)).y()
            api_key_y = api_key.mapTo(body, QPoint(0, 0)).y()
            integration_y = window.settings.language.mapTo(body, QPoint(0, 0)).y()
            batch_y = window.settings.batch_size.mapTo(body, QPoint(0, 0)).y()
            backend_y = window.settings.backend.mapTo(body, QPoint(0, 0)).y()
            self.assertLess(connection_y, integration_y)
            self.assertLess(api_key_y, integration_y)
            self.assertLess(integration_y, batch_y)
            self.assertLess(batch_y, backend_y)
            expected_focus_order = (
                gallery_url, api_key, window.settings.language,
                window.settings.batch_size, window.settings.backend,
            )
            focus_order = []
            visited: set[QWidget] = set()
            focus_widget: QWidget = gallery_url
            while focus_widget not in visited:
                visited.add(focus_widget)
                if focus_widget in expected_focus_order and focus_widget.isEnabled():
                    focus_order.append(focus_widget)
                focus_widget = focus_widget.nextInFocusChain()
            self.assertEqual(expected_focus_order, tuple(focus_order))
            tab_behavior = QApplication.styleHints().tabFocusBehavior()
            all_controls_tab = (
                tab_behavior == Qt.TabFocusBehavior.TabFocusAllControls
            )
            tab_policy = (
                Qt.FocusPolicy.TabFocus if all_controls_tab
                else Qt.FocusPolicy.StrongFocus
            )

            def next_native_tab_target(start: QWidget, forward: bool) -> QWidget:
                """Find the next visible native Tab target under Qt's platform hint.

                Args:
                    start (QWidget): Current widget in the full focus chain.
                    forward (bool): Whether to follow Tab or Shift+Tab order.
                Returns:
                    QWidget: Next enabled visible widget admitted by Qt's Tab policy.
                Raises:
                    AssertionError: The active window has no eligible next target.
                """
                candidate = (
                    start.nextInFocusChain() if forward
                    else start.previousInFocusChain()
                )
                while candidate is not start:
                    if (candidate.isVisibleTo(window) and candidate.isEnabled()
                            and (candidate.focusPolicy() & tab_policy) == tab_policy):
                        return candidate
                    candidate = (
                        candidate.nextInFocusChain() if forward
                        else candidate.previousInFocusChain()
                    )
                raise AssertionError('The native Qt window has no eligible Tab target.')

            # Qt 6.10 Cocoa reads Full Keyboard Access from macOS and exposes it
            # as a read-only hint; without it, Qt traverses StrongFocus widgets.
            # Keep the full chain assertion above, and compare key events with
            # the platform-eligible target. See QCocoaTheme and QApplication:
            # https://github.com/qt/qtbase/blob/v6.10.3/src/plugins/platforms/cocoa/qcocoatheme.mm#L603
            # https://github.com/qt/qtbase/blob/v6.10.3/src/widgets/kernel/qapplication.cpp#L1980
            prior_focus = QApplication.focusWidget()
            try:
                gallery_url.setFocus(Qt.FocusReason.TabFocusReason)
                self.wait_for(gallery_url.hasFocus)
                gallery_tab_target = next_native_tab_target(gallery_url, True)
                if all_controls_tab:
                    self.assertIs(gallery_tab_target, api_key)
                QTest.keyClick(gallery_url, Qt.Key.Key_Tab)
                self.assertIs(QApplication.focusWidget(), gallery_tab_target)
                gallery_reverse_target = next_native_tab_target(gallery_tab_target, False)
                if all_controls_tab:
                    self.assertIs(gallery_reverse_target, gallery_url)
                QTest.keyClick(
                    gallery_tab_target, Qt.Key.Key_Tab,
                    Qt.KeyboardModifier.ShiftModifier,
                )
                self.assertIs(QApplication.focusWidget(), gallery_reverse_target)
                window.settings.language.setFocus(Qt.FocusReason.TabFocusReason)
                self.wait_for(window.settings.language.hasFocus)
                language_tab_target = next_native_tab_target(window.settings.language, True)
                if all_controls_tab:
                    self.assertIs(language_tab_target, window.settings.batch_size)
                QTest.keyClick(window.settings.language, Qt.Key.Key_Tab)
                self.assertIs(QApplication.focusWidget(), language_tab_target)
                language_reverse_target = next_native_tab_target(
                    language_tab_target, False,
                )
                if all_controls_tab:
                    self.assertIs(language_reverse_target, window.settings.language)
                QTest.keyClick(
                    language_tab_target, Qt.Key.Key_Tab,
                    Qt.KeyboardModifier.ShiftModifier,
                )
                self.assertIs(QApplication.focusWidget(), language_reverse_target)
            finally:
                if prior_focus is not None and prior_focus.isVisible():
                    prior_focus.setFocus(Qt.FocusReason.OtherFocusReason)
            window.show_page('watcher')
            fields['gallery_url'].setText('https://unsaved.fixture.example.test/')
            window._open_watcher_settings()
            dialog = window.settings._watcher_dialog
            assert dialog is not None
            self.assertTrue(dialog.isVisible())
            recursion = fields['watch_recursive']
            assert isinstance(recursion, QCheckBox)
            recursion.setChecked(not config.watch_recursive)
            QTest.keyClick(dialog, Qt.Key.Key_Escape)
            self.assertFalse(dialog.isVisible())
            self.assertEqual(1, len(changes))
            window._open_watcher_settings()
            self.assertEqual(config.watch_recursive, recursion.isChecked())
            recursion.setChecked(not config.watch_recursive)
            save_watcher = dialog.findChild(QPushButton, 'saveWatcherSettings')
            assert save_watcher is not None
            save_watcher.click()
            self.assertEqual({'watch_recursive': not config.watch_recursive}, changes[-1])
            self.assertFalse(dialog.isVisible())
            self.assertEqual('workspace-watcher', window.tabs.currentWidget().objectName())
            self.assertEqual('https://unsaved.fixture.example.test/', fields['gallery_url'].text())

    def test_begin_game_edits_only_saved_connection_from_the_current_workspace(self) -> None:
        """Drive the native idle Watcher action through its scoped credential editor.

        Returns:
            None: Escape writes nothing. Explicit Save writes only the temporary
                credentials, preserving the unrelated Settings draft and page.
        """
        config = MAIN.WatcherConfig()
        config.gallery_url = 'https://saved.gallery.example'
        config.api_key = 'saved fixture key'
        config.close_to_tray = False
        values = config.to_dict()
        save = mock.Mock(side_effect=lambda draft: {**values, **draft})
        runtime = RuntimeSettings(lambda: dict(values), save)
        select = mock.Mock()
        connections = ConnectionActions(select, mock.Mock(), lambda: None, lambda: None)
        commands = mock.Mock()
        commands.events = Queue()
        commands.snapshot.return_value = CaptureStatus()
        commands.report_target.return_value = None
        observed: list[tuple[str, str, QLineEdit.EchoMode]] = []

        def edit_dialog(accept: bool) -> None:
            """Edit the real modal and take its requested native action.

            Args:
                accept (bool): Click Save when true, otherwise send Escape.
            Returns:
                None: Retains the original visible field state for assertions.
            """
            dialog = QApplication.activeModalWidget()
            if not isinstance(dialog, QDialog):
                return
            fields = {
                editor.accessibleName(): editor for editor in dialog.findChildren(QLineEdit)
            }
            address, key = fields.get('Gallery URL'), fields.get('API key')
            save_button = dialog.findChild(QPushButton, 'saveScopedConnection')
            if (dialog.objectName() != 'scopedConnectionEditor'
                    or address is None or key is None or save_button is None):
                dialog.reject()
                return
            observed.append((address.text(), key.text(), key.echoMode()))
            address.setText('https://new.gallery.example')
            key.setText('new fixture key')
            if accept:
                save_button.click()
            else:
                QTest.keyClick(dialog, Qt.Key.Key_Escape)

        with tempfile.TemporaryDirectory() as folder:
            window = QtWindow(
                Path(folder), MAIN.APP_VERSION, config.gallery_url, config.activity_history_limit,
                False, runtime_settings=runtime, connections=connections, capture=commands,
            )
            capture = window.capture
            assert capture is not None
            try:
                window.show_page('watcher')
                close_setting = window.settings.fields['close_to_tray']
                assert isinstance(close_setting, QCheckBox)
                close_setting.setChecked(True)
                self.assertTrue(capture.connection_action_button.isVisible())
                QTimer.singleShot(0, lambda: edit_dialog(False))
                capture.connection_action_button.click()
                save.assert_not_called()
                QTimer.singleShot(0, lambda: edit_dialog(True))
                capture.connection_action_button.click()
                self.assertEqual([(config.gallery_url, config.api_key, QLineEdit.EchoMode.Password)] * 2,
                                 observed)
                save.assert_called_once_with({
                    'gallery_url': 'https://new.gallery.example', 'api_key': 'new fixture key',
                })
                self.assertTrue(close_setting.isChecked())
                self.assertFalse(window.close_to_tray)
                self.assertEqual('workspace-watcher', window.tabs.currentWidget().objectName())
                select.assert_not_called()
            finally:
                window.exit_window()

    def test_save_and_test_requires_legacy_selection_and_fences_edits(self) -> None:
        """Exercise verified Settings consent and mutation fencing in real Qt widgets.

        Returns:
            None: Linked mode is retained, and an admitted read owns edits until terminal.
        """
        config = MAIN.WatcherConfig()
        saved_mode = mock.Mock(return_value=mock.Mock(mode='linked'))
        ready, release = Event(), Event()

        def verify(cancelled: Event) -> ConnectionTestResult:
            """Keep the authenticated synthetic read active for the GUI assertions.

            Args:
                cancelled (Event): Lifetime controlled by the native Settings lane.
            Returns:
                ConnectionTestResult: Public gallery labels for the same admitted request.
            Raises:
                RuntimeError: The fixture lost its request or never released it.
            """
            ready.set()
            if not release.wait(SCENE_WAIT_MS / 1000) or cancelled.is_set():
                raise RuntimeError('Settings fixture did not complete its approved read.')
            return ConnectionTestResult('fixture', 'Hosted fixture')

        verify_request = mock.Mock(side_effect=verify)
        select_linked, select_legacy = mock.Mock(), mock.Mock()
        connections = ConnectionActions(select_linked, select_legacy, saved_mode, lambda: None, verify_request)
        with tempfile.TemporaryDirectory() as folder:
            window = QtWindow(
                Path(folder), MAIN.APP_VERSION, '', config.activity_history_limit, False,
                runtime_settings=RuntimeSettings(config.to_dict, lambda edits: {**config.to_dict(), **edits}),
                connections=connections,
            )
            try:
                self.assertEqual(MAIN.gettext('API key not configured'), window.settings.key_status.text())
                key_field = window.settings.fields['api_key']
                key_field.setText('unsaved native fixture key')
                self.assertEqual(
                    MAIN.gettext('API key or gallery URL changed, not saved'),
                    window.settings.key_status.text(),
                )
                self.assertFalse(window.settings.revoke_button.isEnabled())
                key_field.clear()
                with mock.patch.object(QMessageBox, 'information') as explanation:
                    window.settings._save_and_test_connection()
                explanation.assert_called_once()
                verify_request.assert_not_called()
                select_legacy.assert_not_called()
                saved_mode.return_value = mock.Mock(mode='legacy')
                window.settings._save_and_test_connection()
                self.wait_for(ready.is_set)
                self.assertTrue(window.settings_protected())
                self.assertFalse(window.settings.fields['gallery_url'].isEnabled())
                self.assertFalse(window.settings.backend.isEnabled())
                release.set()
                self.wait_for(lambda: not window.settings_protected())
                self.assertTrue(window.settings.fields['gallery_url'].isEnabled())
                self.assertIn('Hosted fixture', window.settings.runtime_status.text())
                select_linked.assert_not_called()
                select_legacy.assert_not_called()
            finally:
                release.set()
                window.shutdown()

    def test_legacy_revoke_waits_for_consent_and_retries_only_local_cleanup(self) -> None:
        """Exercise both authorization stages of Settings revocation through Qt.

        Returns:
            None: Refusal preserves the command, and retry uses the same proved worker.
        """
        commands = mock.Mock()
        commands.events = Queue()
        commands.snapshot.return_value = CaptureStatus()
        commands.report_target.return_value = None
        commands.revoke_legacy.return_value = None
        operation = mock.Mock(spec=FinalizationWorker)
        with tempfile.TemporaryDirectory() as folder:
            history_limit = MAIN.WatcherConfig().activity_history_limit
            window = QtWindow(Path(folder), MAIN.APP_VERSION, '', history_limit, False, capture=commands)
            self.addCleanup(window.shutdown)
            capture = window.capture
            assert capture is not None
            refreshed = Event()
            capture.credentials_changed.connect(refreshed.set)
            with mock.patch.object(QMessageBox, 'question', return_value=QMessageBox.StandardButton.No):
                capture.revoke_legacy(lambda: False)
            commands.revoke_legacy.assert_not_called()
            with mock.patch.object(QMessageBox, 'question', return_value=QMessageBox.StandardButton.Yes):
                capture.revoke_legacy(lambda: False)
            self.wait_for(lambda: commands.revoke_legacy.called and not capture.busy())
            commands.revoke_legacy.assert_called_once_with()
            commands.complete_legacy_revocation.side_effect = [False, True]
            commands.events.put({'kind': 'api_key_revoked', 'payload': {'operation': object()}})
            commands.events.put({'kind': 'api_key_revoked', 'payload': {'operation': operation}})
            commands.snapshot.return_value = CaptureStatus(alive=True, revocation_cleanup_pending=True)
            self.wait_for(lambda: capture.retry_revocation_button.isEnabled())
            commands.complete_legacy_revocation.assert_called_once_with(operation)
            self.assertTrue(window.settings_protected())
            self.assertFalse(refreshed.is_set())
            capture.retry_legacy_revocation_cleanup()
            self.wait_for(refreshed.is_set)
            self.assertTrue(refreshed.is_set())
            self.assertEqual([mock.call(operation), mock.call(operation)],
                             commands.complete_legacy_revocation.call_args_list)
            commands.revoke_legacy.assert_called_once_with()
            commands.snapshot.return_value = CaptureStatus()
            window.shutdown()

    def test_storage_settings_measure_shared_pools_and_preserve_a_failed_quota(self) -> None:
        """Exercise the Qt cache form without operating on the runner's real cache.

        Returns:
            None: Safe counts use the common cache, writes use the shared quota
                owner, and a failed save restores the observed drive quota.
        """
        with tempfile.TemporaryDirectory() as folder:
            window = QtWindow(Path(folder), MAIN.APP_VERSION, '', 100, False)
            settings = window.settings
            cache = mock.Mock()
            cache.thumbnail_storage_snapshot.return_value = (3, BYTES_PER_GIB, 4 * BYTES_PER_GIB, True, None)
            cache.retained_storage_snapshot.return_value = (1, 2, 1024, 0, 0, True)
            with (
                mock.patch('uploader.qt_settings.GalleryMediaCache.for_current_user', return_value=cache),
                mock.patch('uploader.qt_settings.set_thumbnail_cache_limit_gib', return_value=False) as save,
            ):
                try:
                    settings.refresh_storage()
                    self.wait_for(settings.storage_limit.isEnabled)
                    self.assertEqual(4, settings.storage_limit.currentData())
                    self.assertIn(settings._storage_text('keep_detail').split(':')[0], settings.storage_usage.text())
                    settings.choose_storage_limit(settings.storage_limit.findData(8))
                    self.assertTrue(settings.writes_storage())
                    self.wait_for(lambda: not settings.writes_storage())
                    save.assert_called_once_with(8)
                    self.assertEqual(settings._storage_text('save_failed'), settings.storage_note.text())
                    self.assertEqual(4, settings.storage_limit.currentData())
                finally:
                    window.exit_window()

    def test_capture_finish_uses_real_fifo_recorder_and_staged_simbrief_plan(self) -> None:
        """Drive capture, ordinary restart and Finish using the mounted Qt controls.

        Returns:
            None: Restores the exact queue and recorder identity, then finishes it
                with the originally attached OFP.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            root, photographs = profile / 'flights', profile / 'screenshots'
            root.mkdir()
            photographs.mkdir()
            FlightPreferences(recording=False, reports=False).save(root)
            store = MAIN.ConfigStore(profile / 'config.json')
            config = store.load()
            config.gallery_url = 'https://capture-fixture.example.test/index.php'
            config.api_key = 'isolated capture credential'
            config.watched_folder = str(photographs)
            config.attach_sim_camera_metadata = False
            config.stable_seconds = MAIN.MIN_STABLE_SECONDS
            store.save(config)
            mode = mock.MagicMock(spec=MAIN.GuiModeOwner)
            mode.view.return_value.state = 'legacy'
            planned = DispatchPlan('LKPR', 'ESSA', '', '', '', '', '', '', '')
            with (
                mock.patch.object(MAIN, 'CONFIG_DIR', profile),
                mock.patch.object(MAIN, 'fetch_dispatch', return_value=planned),
            ):
                owner = MAIN.DesktopCapture(store, lambda: config, mode, lambda: None)
                window = QtWindow(profile, MAIN.APP_VERSION, '', config.activity_history_limit, False, capture=owner)
                view = window.capture
                assert view is not None
                try:
                    window.show_page('watcher')
                    self.assertEqual('LKPR → ESSA', owner.load_dispatch('fixture-pilot'))
                    view.start_button.click()
                    self.wait_for(lambda: owner.worker is not None and owner.worker.initial_captured
                                  and view.finish_button.isEnabled())
                    assert owner.recorder is not None
                    session = owner.recorder.directory
                    self.assertEqual(planned, load_dispatch(session))
                    window.restart()
                    self.wait_for(lambda: window.restart_requested and not window.isVisible())
                    plan = SessionPlan.load(session)
                    work = inspect_gui_profile_work(
                        profile, capture_binding=plan.binding, restart_version=MAIN.APP_VERSION,
                    )
                    self.assertFalse(work.blocked)
                    self.assertIsNotNone(work.restart)
                    owner = MAIN.DesktopCapture(store, lambda: config, mode, lambda: None)
                    owner.restart_record = work.restart
                    window = QtWindow(
                        profile, MAIN.APP_VERSION, '', config.activity_history_limit, False,
                        capture=owner, recoverable_capture=True,
                    )
                    view = window.capture
                    assert view is not None
                    window.show()
                    window.show_page('watcher')
                    view.show_progress()
                    self.assertFalse(view._progress_continue.isHidden())
                    self.assertTrue(view._progress_continue.isEnabled())
                    view._progress_continue.click()
                    self.wait_for(lambda: owner.recorder is not None and view.finish_button.isEnabled())
                    assert owner.recorder is not None and owner.lease is not None
                    self.assertEqual(session, owner.recorder.directory)
                    checkpoint = WatcherContinuation.load(owner.lease)
                    assert checkpoint is not None
                    self.assertEqual('consumed', checkpoint.phase)
                    drain_entered, release_drain = Event(), Event()
                    drain = owner._wait_for_drain

                    def hold_drain(
                        plan: SessionPlan, worker: MAIN.WatcherThread | None,
                        cancelled: Event, deadline: float,
                    ) -> None:
                        """Hold the real finalizer at the draining phase for native evidence.

                        Args:
                            plan (SessionPlan): Exact active session plan from Finish.
                            worker (MAIN.WatcherThread | None): Current accepted queue worker.
                            cancelled (Event): Finalizer cancellation fence.
                            deadline (float): Original absolute drain deadline.
                        Returns:
                            None: The existing drain runs when the fixture releases it.
                        """
                        drain_entered.set()
                        if not release_drain.wait(SCENE_WAIT_MS / 1000):
                            raise RuntimeError('The native fixture did not release the Watcher drain.')
                        drain(plan, worker, cancelled, deadline)

                    with (mock.patch.object(owner, '_wait_for_drain', side_effect=hold_drain),
                          mock.patch.object(QMessageBox, 'question', return_value=QMessageBox.StandardButton.Yes)):
                        try:
                            view.finish_button.click()
                            self.wait_for(lambda: drain_entered.is_set() and not view._busy
                                          and owner.snapshot().force_finish_available)
                            view.show_progress()
                            panel = view._progress_dialog
                            self.assertIsNotNone(panel)
                            self.assertIs(panel.parentWidget(), window.centralWidget())
                            self.assertTrue(panel.graphicsEffect().isEnabled())
                            force = view._progress_force_finish
                            self.assertTrue(force.isVisibleTo(panel))
                            self.assertTrue(force.isEnabled())
                            self.assertTrue(panel.rect().contains(force.mapTo(panel, force.rect().center())))
                            output = os.environ.get('PHP_GALLERY_QT_ARTIFACT_DIR')
                            if output:
                                artifacts = Path(output)
                                artifacts.mkdir(parents=True, exist_ok=True)
                                self.assertTrue(window.grab().save(str(artifacts / 'watcher-force-finish.png')))
                        finally:
                            release_drain.set()
                    self.wait_for(lambda: not view.busy() and owner.finalizer is not None)
                    self.assertEqual('completed', owner.snapshot().phase)
                    self.assertTrue(SessionPlan.load(session).completed)
                    self.assertTrue(MAIN.WatchQueueStore.read_journal(session / 'watch-drain.json')['completed'])
                    original = photographs / 'original-kept.txt'
                    original.write_bytes(b'fixture original outside the queue')
                    with mock.patch.object(QMessageBox, 'question', return_value=QMessageBox.StandardButton.Yes):
                        view.reset_button.click()
                    self.wait_for(lambda: not view.busy() and owner.snapshot().phase == 'reset')
                    reset = MAIN.WatcherProfile.load(root)
                    assert reset is not None
                    self.assertFalse(reset.pending)
                    self.assertEqual('', reset.binding)
                    self.assertTrue(original.is_file())
                    self.assertEqual(planned, load_dispatch(session))
                    self.assertEqual('isolated capture credential', config.api_key)
                finally:
                    if owner.worker is not None and owner.worker.is_alive():
                        owner.worker.stop()
                        owner.worker.join(timeout=QT_CHILD_SECONDS)
                    if owner.recorder is not None and owner.recorder.is_alive():
                        owner.recorder.stop()
                        owner.recorder.join(timeout=QT_CHILD_SECONDS)
                    window.shutdown()

    def test_normal_close_checkpoints_active_capture_without_relaunch(self) -> None:
        """Preserve an active capture when the operator closes the Qt shell.

        Returns:
            None: Normal close waits for the accepted checkpoint, keeps its
                original session and does not request a launcher relaunch.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            root, photographs = profile / 'flights', profile / 'screenshots'
            root.mkdir()
            photographs.mkdir()
            FlightPreferences(recording=False, reports=False).save(root)
            store = MAIN.ConfigStore(profile / 'config.json')
            config = store.load()
            config.gallery_url = 'https://capture-close-fixture.example.test/index.php'
            config.api_key = 'isolated close credential'
            config.watched_folder = str(photographs)
            config.attach_sim_camera_metadata = False
            config.stable_seconds = MAIN.MIN_STABLE_SECONDS
            store.save(config)
            mode = mock.MagicMock(spec=MAIN.GuiModeOwner)
            mode.view.return_value.state = 'legacy'
            with mock.patch.object(MAIN, 'CONFIG_DIR', profile):
                owner = MAIN.DesktopCapture(store, lambda: config, mode, lambda: None)
                window = QtWindow(
                    profile, MAIN.APP_VERSION, '', config.activity_history_limit,
                    False, capture=owner,
                )
                view = window.capture
                assert view is not None
                try:
                    window.show_page('watcher')
                    view.start_button.click()
                    self.wait_for(lambda: owner.worker is not None
                                  and owner.worker.initial_captured
                                  and view.finish_button.isEnabled())
                    assert owner.recorder is not None
                    session = owner.recorder.directory
                    original_plan = SessionPlan.load(session)
                    with self.assertRaises(RuntimeError):
                        WorkspaceLease(root)

                    window.close()
                    self.wait_for(lambda: not window.isVisible()
                                  and not owner.snapshot().alive
                                  and owner.snapshot().restart_saved)
                    self.assertFalse(window.restart_requested)
                    self.assertEqual(session, owner.recorder.directory)

                    competitor = WorkspaceLease(root)
                    try:
                        checkpoint = WatcherContinuation.load(competitor)
                        ordinary = WatcherContinuation.load(
                            competitor, ordinary_restart=True,
                        )
                        self.assertIsNotNone(checkpoint)
                        self.assertEqual(checkpoint, ordinary)
                        assert checkpoint is not None
                        self.assertEqual('prepared', checkpoint.phase)
                        self.assertEqual(session.name, checkpoint.session_id)
                        self.assertEqual(original_plan, SessionPlan.load(session))
                    finally:
                        competitor.release()
                finally:
                    if owner.worker is not None and owner.worker.is_alive():
                        owner.worker.stop()
                        owner.worker.join(timeout=QT_CHILD_SECONDS)
                    if owner.recorder is not None and owner.recorder.is_alive():
                        owner.recorder.stop()
                        owner.recorder.join(timeout=QT_CHILD_SECONDS)
                    window.shutdown()

    def test_update_verifies_exact_qt_installer_and_waits_for_owned_work(self) -> None:
        """Exercise the native updater's real bytes and final admission gates.

        Returns:
            None: Active work refuses install; verified helper readiness permits close.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            window = QtWindow(profile, MAIN.APP_VERSION, '', 100, False)
            self.addCleanup(window.shutdown)
            window.show()
            updater = window.updater
            updater.installed = True
            updater.directory = profile / 'updates'
            name = 'PHPGalleryUploader-99.0.0-Setup.exe'
            content = b'fixture update bytes'
            release = ReleaseAsset(
                '99.0.0', name,
                'https://github.com/klusik/PHP_gallery/releases/download/fixture/' + name,
                len(content), sha256(content).hexdigest(), 1, 1,
            )
            session = qt_update.self_update.create_download_session(updater.directory)
            installer = session / 'fixture.exe'
            installer.write_bytes(content)
            with mock.patch.object(qt_update.self_update, 'find_update', return_value=release) as find:
                updater.present()
                self.wait_for(lambda: updater.asset is release and not updater.busy())
            find.assert_called_once_with(MAIN.APP_VERSION)
            updater.installer = installer
            with mock.patch.object(qt_update.update_helper, 'launch_update') as helper:
                with mock.patch.object(updater, 'protected', return_value=True):
                    updater.install()
                helper.assert_not_called()
                with mock.patch.object(QMessageBox, 'question', return_value=QMessageBox.StandardButton.Yes):
                    updater.install()
                self.wait_for(lambda: not window.isVisible())
                helper.assert_called_once_with(installer, release)

    def test_retained_scene_codec_and_stale_details(self) -> None:
        """Render and replace a retained detail before closing its nested surfaces.

        Returns:
            None: Verifies wheel and keyboard transforms preserve the scene
                image, toolbar actions have accessible names, and grid selection
                survives keyboard entry, Lightbox navigation and Escape.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            save_gui_backend(profile, "pyside6")
            image_paths = (profile / "first.jpg", profile / "second.webp")
            # Two distinct formats and colors make replacement observable.
            for path, color, encoding in zip(image_paths, ("red", "blue"), ("JPEG", "WEBP")):
                buffer = BytesIO()
                with Image.new("RGB", (360, 200), color) as fixture:
                    fixture.save(buffer, format=encoding)
                image, original = decode_photo(buffer.getvalue(), thumbnail=True)
                self.assertEqual(360, original.width())
                self.assertEqual(144, image.width())
                path.write_bytes(buffer.getvalue())
            configure_language("en")
            window = QtWindow(profile, "1.7.1", "", 500, False)
            try:
                window.show()
                window.photos.open_files(image_paths)
                self.wait_for(lambda: len(window.photos.model.texture_urls) == 2)
                self.assertFalse(window.photos.quick.grabFramebuffer().isNull())
                first_identity = window.photos.model.rows[0].identity
                second_identity = window.photos.model.rows[1].identity
                window.photos.openPhoto(0)
                self.assertEqual({first_identity}, window.photos.model.selected)
                window.photos.openPhoto(1)
                self.assertEqual({first_identity}, window.photos.model.selected)
                self.wait_for(lambda: window.photos.detailTitle == "second.webp")
                self.assertTrue(window.photos.detailUrl)
                location = window.photos._detail_location_button
                self.assertTrue(location.isVisible())
                self.assertFalse(location.isEnabled())
                toolbar_buttons = window.photos._detail_toolbar.findChildren(QPushButton)
                self.assertEqual(9, len(toolbar_buttons))
                toolbar_names = []
                for button in toolbar_buttons:
                    accessible = QAccessible.queryAccessibleInterface(button)
                    self.assertIsNotNone(
                        accessible,
                        f'Missing accessibility interface for toolbar action {button.text()!r}.',
                    )
                    toolbar_names.append(accessible.text(QAccessible.Text.Name))
                self.assertTrue(all(toolbar_names), toolbar_names)
                previous, following = window.photos._detail_edges
                self.assertTrue(previous.isVisible() and following.isVisible())
                self.assertLess(previous.x(), window.photos._detail_controls.x())
                self.assertGreater(following.x(), window.photos._detail_controls.x())
                quick = window.photos.quick
                lightbox = quick.rootObject().findChild(QObject, 'photosLightbox')
                image_item = quick.rootObject().findChild(QObject, 'photosLightboxImage')
                assert lightbox is not None and image_item is not None
                self.wait_for(lambda: bool(image_item.property('frameReady')))
                retained_url = window.photos.detailUrl

                def scroll(angle: QPoint, pixels: QPoint) -> None:
                    """Send one native wheel or trackpad packet through Quick.

                    Args:
                        angle (QPoint): Signed wheel rotation in Qt angle units.
                        pixels (QPoint): Signed scroll displacement, if available.
                    Returns:
                        None: The original scene processes the native input.
                    """
                    center = quick.rect().center()
                    event = QWheelEvent(
                        QPointF(center), QPointF(quick.mapToGlobal(center)), pixels, angle,
                        Qt.MouseButton.NoButton, Qt.KeyboardModifier.NoModifier,
                        Qt.ScrollPhase.ScrollUpdate, False,
                    )
                    QApplication.sendEvent(quick, event)

                half_notch = LIGHTBOX_WHEEL_DELTA_UNITS // 2
                scroll(QPoint(0, half_notch), QPoint())
                self.assertEqual(1.0, lightbox.property('zoom'))
                scroll(QPoint(0, half_notch), QPoint())
                self.wait_for(lambda: lightbox.property('zoom') > 1.0)
                self.assertAlmostEqual(LIGHTBOX_WHEEL_STEP, lightbox.property('zoom'))
                scroll(QPoint(LIGHTBOX_WHEEL_DELTA_UNITS, 0), QPoint())
                self.assertAlmostEqual(LIGHTBOX_WHEEL_STEP, lightbox.property('zoom'))
                scroll(QPoint(0, -LIGHTBOX_WHEEL_DELTA_UNITS), QPoint())
                self.assertAlmostEqual(1.0, lightbox.property('zoom'))
                half_touchpad_step = LIGHTBOX_TOUCHPAD_ZOOM_DELTA_UNITS // 2
                scroll(QPoint(), QPoint(0, half_touchpad_step))
                self.assertEqual(1.0, lightbox.property('zoom'))
                scroll(QPoint(), QPoint(0, half_touchpad_step))
                self.wait_for(lambda: lightbox.property('zoom') > 1.0)
                self.assertAlmostEqual(LIGHTBOX_WHEEL_STEP, lightbox.property('zoom'))
                self.assertEqual(retained_url, window.photos.detailUrl)
                QTest.keyClick(quick, Qt.Key.Key_0)
                self.wait_for(lambda: lightbox.property('zoom') == 1.0)
                QTest.keyClick(quick, Qt.Key.Key_Plus)
                self.wait_for(lambda: lightbox.property('zoom') > 1.0)
                following.click()
                self.wait_for(lambda: window.photos.detailTitle == 'first.jpg')
                self.assertEqual(1.0, lightbox.property('zoom'))
                previous.click()
                self.wait_for(lambda: window.photos.detailTitle == 'second.webp')
                window.photos.toggle_slideshow()
                self.wait_for(window.photos._slideshow.isActive)
                window.photos._advance_slideshow()
                self.wait_for(lambda: window.photos.detailTitle == "first.jpg")
                window.photos.stop_slideshow()
                self.assertFalse(window.photos._slideshow.isActive())
                self.assertEqual({first_identity}, window.photos.model.selected)
                self.assertTrue(window.isFullScreen())
                retained = window.photos.detailUrl
                center = quick.rect().center()
                context = QContextMenuEvent(
                    QContextMenuEvent.Reason.Keyboard, center, quick.mapToGlobal(center),
                )
                QApplication.sendEvent(quick, context)
                menu = window.photos._detail_menu
                assert menu is not None
                self.assertTrue(menu.isVisible())
                self.assertEqual(1, len(menu.actions()))
                QTest.keyClick(menu, Qt.Key.Key_Escape)
                self.wait_for(lambda: window.photos._detail_menu is None)
                self.assertTrue(window.isFullScreen())
                self.assertEqual(retained, window.photos.detailUrl)
                QTest.keyClick(quick, Qt.Key.Key_Escape)
                self.wait_for(lambda: not window.isFullScreen())
                self.assertTrue(window.photos.detailOpen)
                self.assertEqual(retained, window.photos.detailUrl)
                QTest.keyClick(quick, Qt.Key.Key_Escape)
                self.assertFalse(window.photos.detailOpen)
                self.assertFalse(window.photos.detailUrl)
                self.assertEqual({first_identity}, window.photos.model.selected)

                grid = quick.rootObject().findChild(QQuickItem, "photosGrid")
                assert grid is not None
                window.photos.model.select(set())
                grid.setProperty("currentIndex", 0)
                grid.forceActiveFocus()
                QTest.keyClick(quick, Qt.Key.Key_Enter)
                self.wait_for(lambda: window.photos.detailTitle == "first.jpg")
                self.assertEqual({first_identity}, window.photos.model.selected)
                QTest.keyClick(quick, Qt.Key.Key_Escape)
                self.wait_for(lambda: not window.photos.detailOpen)
                self.assertEqual({first_identity}, window.photos.model.selected)

                window.photos.model.select({first_identity, second_identity})
                window.photos.openPhoto(1)
                self.wait_for(lambda: window.photos.detailTitle == "second.webp")
                self.assertEqual(
                    {first_identity, second_identity}, window.photos.model.selected,
                )
                window.photos.dismissPhoto()
                self.assertEqual(
                    {first_identity, second_identity}, window.photos.model.selected,
                )
                window.tabs.setCurrentWidget(window.settings)
                output = os.environ.get("PHP_GALLERY_QT_ARTIFACT_DIR")
                for label, size in (
                    ("desktop", (980, 580)), ("narrow", QT_WINDOW_MINIMUM),
                ):
                    window.resize(*size)
                    self.wait_for(lambda: window.settings.backend.isVisible())
                    if output:
                        directory = Path(output)
                        directory.mkdir(parents=True, exist_ok=True)
                        self.assertTrue(window.grab().save(str(directory / f"settings-{label}.png")))
                self.assertTrue(window.settings.save_backend("tkinter"))
                self.assertEqual("tkinter", load_gui_backend(profile))
                self.assertTrue(window.settings.restart_button.isVisible())
                self.assertTrue(window.settings.cancel_button.isVisible())
            finally:
                window.exit_window()

    def test_ready_child_runs_to_normal_close(self) -> None:
        """Require an actual first Qt Quick frame from the independent event loop.

        Returns:
            None: Confirms the actual public backend runner reaches and exits ready.
        """
        with tempfile.TemporaryDirectory() as folder:
            child_source = (
                "from pathlib import Path\n"
                "import sys\n"
                "from PySide6.QtCore import QTimer\n"
                "from PySide6.QtWidgets import QApplication\n"
                "from uploader.qt_application import run_qt\n"
                "ready = lambda: QTimer.singleShot(0, QApplication.instance().quit)\n"
                "restart = run_qt(profile=Path(sys.argv[1]), version='1.7.1',"
                " gallery_address='', history_limit=500, close_to_tray=False,"
                " start_minimized=False, activation_requested=lambda: False, ready=ready)\n"
                "raise SystemExit(int(restart))\n"
            )
            environment = os.environ.copy()
            environment["PYTHONPATH"] = str(Path(__file__).resolve().parents[1])
            completed = subprocess.run(
                [sys.executable, "-c", child_source, folder],
                env=environment, capture_output=True, check=False, timeout=QT_CHILD_SECONDS,
            )
            self.assertEqual(0, completed.returncode, completed.stderr.decode("utf-8", "replace")[-1200:])

    def test_backend_later_and_cancel_keep_the_current_qt_session(self) -> None:
        """Keep Later non-disruptive and restore Qt when a choice is cancelled.

        Returns:
            None: The saved choice is explicit, no restart is requested, and
                Cancel pending change restores the active toolkit preference.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            save_gui_backend(profile, "pyside6")
            configure_language("en")
            window = QtWindow(profile, "1.7.1", "", 500, False)
            restart = mock.Mock()
            window.settings.restart = restart
            window.show()
            window.show_page('settings')
            dismissed: list[bool] = []

            def select_later() -> None:
                """Choose the actual safe-default button in the modal prompt.

                Returns:
                    None: Records whether the current prompt exposed Later.
                """
                dialog = QApplication.activeModalWidget()
                if not isinstance(dialog, QMessageBox):
                    dismissed.append(False)
                    return
                later = next((
                    button for button in dialog.buttons() if button.text() == "Later"
                ), None)
                if later is None:
                    dismissed.append(False)
                    dialog.reject()
                    return
                dismissed.append(True)
                later.click()

            try:
                window.settings.focus_backend_picker()
                self.wait_for(lambda: window.settings.backend.isVisible() and window.settings.backend.hasFocus())
                self.assertEqual('GUI backend', window.settings.backend.accessibleName())
                self.assertIn('highly experimental', window.settings.backend.currentText())
                QTimer.singleShot(0, select_later)
                tkinter_index = window.settings.backend.findData("tkinter")
                self.assertGreaterEqual(tkinter_index, 0)
                window.settings.choose_backend(tkinter_index)
                self.assertEqual([True], dismissed)
                self.assertEqual("tkinter", load_gui_backend(profile))
                self.assertFalse(window.restart_requested)
                restart.assert_not_called()

                window.settings.cancel_button.setFocus(Qt.FocusReason.TabFocusReason)
                QTest.keyClick(window.settings.cancel_button, Qt.Key.Key_Space)
                self.assertEqual("pyside6", load_gui_backend(profile))
                self.assertTrue(window.settings.cancel_button.isHidden())
                self.assertFalse(window.restart_requested)
                restart.assert_not_called()
            finally:
                window.exit_window()

    def test_ai_settings_and_dependency_actions_share_the_profile_and_fence(self) -> None:
        """Exercise the native AI cards and explicit shared workflow consent.

        Returns:
            None: The collapsed card opens by keyboard and actual AI writes and
                dependency results use their existing owners.
        """
        values = MAIN.WatcherConfig().to_dict()
        save = mock.Mock(side_effect=lambda draft: {**values, **draft})
        runtime = RuntimeSettings(lambda: dict(values), save)
        released = Event()
        entered = Event()

        def install() -> tuple[bool, str]:
            """Simulate a source dependency installation owning the runtime.

            Returns:
                tuple[bool, str]: Bounded fixture result after explicit release.
            """
            entered.set()
            released.wait(QT_CHILD_SECONDS)
            return True, 'fixture complete'

        install_ai = mock.Mock(side_effect=install)
        dependency_status = mock.Mock(side_effect=['First runtime', 'Refreshed runtime'])
        dependencies = DependencyActions(dependency_status, mock.Mock(), install_ai, False)
        with tempfile.TemporaryDirectory() as folder:
            window = QtWindow(
                Path(folder), MAIN.APP_VERSION, '', MAIN.WatcherConfig().activity_history_limit,
                False, runtime_settings=runtime, dependencies=dependencies,
            )
            ai = window.ai
            try:
                window.show()
                window.show_page('ai')
                self.application.processEvents()
                toggle = ai.findChild(QCheckBox, 'advancedAiSettings')
                advanced = ai.findChild(QFrame, 'advancedAiFields')
                assert toggle is not None and advanced is not None
                self.assertTrue(toggle.isVisibleTo(window))
                self.assertTrue(advanced.isHidden())
                self.assertGreaterEqual(ai.start_button.height(), AI_ACTION_MIN_HEIGHT_PX)
                QTest.keyClick(toggle, Qt.Key.Key_Space)
                self.assertTrue(advanced.isVisibleTo(window))
                self.assertTrue(any(
                    label.isVisibleTo(window) and label.text().startswith('Default 60.')
                    for label in advanced.findChildren(QLabel)
                ))
                QTest.keyClick(toggle, Qt.Key.Key_Space)
                self.assertTrue(advanced.isHidden())
                ai.backend.setCurrentIndex(ai.backend.findData('pillow'))
                self.assertTrue(ai._save_preferences())
                save.assert_called_once_with({'ai_vision_backend': 'pillow'})
                with mock.patch.object(QMessageBox, 'question', return_value=QMessageBox.StandardButton.No):
                    ai.install_local_ai()
                install_ai.assert_not_called()
                with (
                    mock.patch.object(QMessageBox, 'question', return_value=QMessageBox.StandardButton.Yes),
                    mock.patch.object(QMessageBox, 'open'),
                ):
                    ai.install_local_ai()
                    self.wait_for(entered.is_set)
                    self.assertTrue(window.settings_protected())
                    ai.repair_dependencies()
                    dependencies.repair.assert_not_called()
                    released.set()
                    self.wait_for(lambda: not ai.has_work())
                self.assertEqual('Refreshed runtime', ai.dependency_status())
                self.assertEqual('Refreshed runtime', window.settings._dependency_caption.text())
            finally:
                released.set()
                window.shutdown()

    def test_ai_start_reports_verified_linked_mode_without_exposing_details(self) -> None:
        """Give the explicit API-key path when linked mode refuses AI.

        Returns:
            None: Typed linked-mode refusal gets localized guidance; unrelated
                refusal details stay private behind the generic message.
        """
        configure_language('en')
        values = MAIN.WatcherConfig().to_dict()
        values.update({
            'ai_worker_enabled': True,
            'gallery_url': 'https://gallery.invalid',
            'api_key': 'fixture-secret-value',
        })
        runtime = RuntimeSettings(
            lambda: dict(values), lambda draft: {**values, **draft},
        )
        cases = (
            (
                mock.Mock(side_effect=LinkedAiModeRefusal()),
                'Linked gallery upload does not grant AI access. Switch explicitly '
                'to the saved API-key connection to use this worker.',
            ),
            (
                mock.Mock(side_effect=RuntimeError('private failure detail')),
                'Enable the AI worker in Settings and verify the saved gallery connection.',
            ),
        )
        for factory, expected in cases:
            ai = QtAiWorker(factory, lambda text: text, runtime, None, lambda: False)
            self.addCleanup(ai.close_view)
            ai.start()
            self.wait_for(lambda: not ai.has_work())
            self.assertEqual(expected, ai.status.text())
            self.assertNotIn('private failure detail', ai.status.text())
            ai.close_view()

    def test_restart_keeps_worker_alive_until_it_finishes(self) -> None:
        """Keep GUI ownership until the existing worker releases its request.

        Returns:
            None: Qt marks running work once, requests stop once and closes only
                after actual completion.
        """
        with tempfile.TemporaryDirectory() as folder:
            worker = mock.Mock()
            running = True
            worker.is_alive.side_effect = lambda: running
            worker.current_progress.return_value = (25, "synthetic worker owns a request")
            factory = mock.Mock(return_value=worker)
            window = QtWindow(Path(folder), "1.7.1", "", 500, False, ai_factory=factory)
            monitor: list[bool] = []
            window.ai.work_changed.connect(monitor.append)
            try:
                window.show()
                window.ai.start()
                self.wait_for(window.ai.running)
                worker.start.assert_called_once_with()
                self.assertEqual([True], monitor)
                window.restart()
                self.assertTrue(window.isVisible())
                self.assertTrue(window.restart_requested)
                worker.stop.assert_called_once_with()
                running = False
                self.wait_for(lambda: not window.isVisible())
                worker.stop.assert_called_once_with()
                self.assertEqual([True, False], monitor)
            finally:
                running = False
                window.exit_window()

    def test_import_owns_review_pause_cancel_and_retry(self) -> None:
        """Drive native controls through the real preflight and import lifetimes.

        Returns:
            None: Verifies explicit first admission, pause and cancellation,
                the restart barrier, separate Watcher controls, and reuse of
                the original durable job.
        """
        with tempfile.TemporaryDirectory() as folder:
            # Compare the physical profile used by native locks and bindings.
            profile = Path(folder).resolve()
            state = profile / 'upload_state.json'
            (profile / 'flights').mkdir()
            source_folder = profile / 'manual-input'
            nested_folder = source_folder / 'subfolder'
            nested_folder.mkdir(parents=True)
            original = source_folder / 'import.jpg'
            with Image.new('RGB', (144, 108), 'green') as fixture:
                fixture.save(original)
            with Image.new('RGB', (144, 108), 'red') as fixture:
                fixture.save(nested_folder / 'additional.jpg')
            store = MAIN.ConfigStore(str(profile / 'config.json'))
            config = store.load()
            config.gallery_url = 'https://gallery.example.test/'
            config.api_key = 'isolated-fixture-key'
            store.save(config)
            mode = mock.MagicMock(spec=MAIN.GuiModeOwner)
            mode.view.return_value.state = 'legacy'
            entered = Event()
            released = Event()
            attempts: list[MAIN.ManualUploadThread] = []

            def hold(worker: MAIN.ManualUploadThread, _endpoint: str) -> None:
                """Hold dispatch behind a synthetic transport boundary.

                Args:
                    worker (MAIN.ManualUploadThread): Actual job-owning worker.
                    _endpoint (str): Isolated non-routable test gallery URL.
                Returns:
                    None: Makes no HTTP request before returning to finalization.
                """
                attempts.append(worker)
                entered.set()
                released.wait(QT_CHILD_SECONDS)

            with mock.patch.multiple(MAIN, CONFIG_DIR=profile, STATE_PATH=state), \
                    mock.patch.object(MAIN.ManualUploadThread, '_run_fifo_import', hold):
                actions = MAIN.desktop_import_actions(store, config, lambda: mode)
                window = QtWindow(profile, '1.7.1', '', 500, False, import_actions=actions)
                view = window.import_flow
                assert view is not None
                ledger = MAIN.JobStateStore(state, read_only=True)
                try:
                    window.show_page('import')
                    view.select_source(ImportSource(folder=source_folder))
                    self.wait_for(lambda: not view.busy())
                    self.assertTrue(view.start_button.isEnabled(), view.status.text())
                    self.assertEqual(1, len(view._plan.job.items))
                    original_draft = view._plan.job.id
                    view.recursive.click()
                    self.wait_for(lambda: not view.busy())
                    self.assertNotEqual(original_draft, view._plan.job.id)
                    self.assertEqual(2, len(view._plan.job.items))
                    view.recursive.click()
                    self.wait_for(lambda: not view.busy())
                    self.assertEqual(1, len(view._plan.job.items))
                    self.assertFalse(entered.is_set())
                    saved = ledger.get_job(view._plan.job.id)
                    self.assertEqual('queued', saved.items[0].state)
                    self.assertFalse(MAIN.ImportBindings(profile / 'flights').entries())

                    finish, abandon = mock.Mock(), mock.Mock()
                    view.set_capture_actions('Finish flight…', finish, 'Abandon flight…', abandon)
                    view.set_admission_locked(True)
                    self.assertFalse(view.files.isEnabled())
                    self.assertFalse(view.start_button.isEnabled())
                    view.start()
                    view.select_source(ImportSource(files=(original,)))
                    self.assertFalse(view.busy())
                    self.assertFalse(entered.is_set())
                    self.assertEqual(saved.id, view._plan.job.id)
                    view._capture_primary.click()
                    view._capture_secondary.click()
                    finish.assert_called_once_with()
                    abandon.assert_called_once_with()
                    view.set_admission_locked(False)

                    view.start_button.click()
                    self.wait_for(lambda: entered.is_set() and view.pause_button.isEnabled())
                    view.pause_button.click()
                    self.assertTrue(attempts[0].pause_event.is_set())
                    view.resume_button.click()
                    self.assertFalse(attempts[0].pause_event.is_set())
                    window.restart()
                    self.assertFalse(window.restart_requested)
                    self.assertTrue(window.isVisible())
                    view.cancel_button.click()
                    self.assertTrue(attempts[0].stop_event.is_set())
                    released.set()
                    self.wait_for(lambda: not view.busy())
                    self.assertEqual('cancelled', ledger.get_job(saved.id).items[0].state)
                    self.assertTrue(original.is_file())
                    self.assertIn(saved.id, MAIN.ImportBindings(profile / 'flights').entries())

                    self.wait_for(lambda: bool(window.operator.imports.model.rows))
                    window.operator.review_startup_import(saved.id)
                    self.wait_for(lambda: window.operator.selected_import_id() == saved.id)
                    view.retry_button.click()
                    self.wait_for(lambda: len(attempts) == 2 and not view.busy())
                    self.assertEqual(saved.id, attempts[1].import_job_id)
                    self.assertNotEqual(attempts[0].import_generation, attempts[1].import_generation)
                finally:
                    released.set()
                    if view.busy():
                        view.cancel()
                        self.wait_for(lambda: not view.busy())
                    window.exit_window()

    def test_local_flight_report_holds_workspace_and_preserves_previous_editions(self) -> None:
        """Use the real report worker and filesystem lease from native Qt.

        Returns:
            None: New independent editions appear after explicit creation,
                accepted delivery bytes survive and restart waits for the lease.
        """
        entered = Event()
        released = Event()

        def render(
            _analysis: FlightAnalysis, destinations: dict[str, Path],
            _cancel: Event, started: Callable[[str], None],
            completed: Callable[[str, Path], None], *,
            prepare_maps: ReportMaps, render: ReportRenderer,
        ) -> dict[str, Path]:
            """Complete the admitted revision without native flight data or network.

            Args:
                _analysis (FlightAnalysis): Isolated synthetic analysis fixture.
                destinations (dict[str, Path]): Shared owner's reserved edition paths.
                _cancel (Event): Shared worker's cancellation flag.
                started (Callable[[str], None]): Existing edition progress callback.
                completed (Callable[[str, Path], None]): Existing durable edition callback.
                prepare_maps (ReportMaps): Unused real map preparation function.
                render (ReportRenderer): Unused native renderer for a PDF edition.
            Returns:
                dict[str, Path]: Dummy edition files admitted through the actual worker.
            """
            entered.set()
            released.wait(QT_CHILD_SECONDS)
            for language, destination in destinations.items():
                started(language)
                destination.write_bytes(b'%PDF-1.4\n%%EOF\n')
                completed(language, destination)
            return destinations

        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder).resolve()
            root = profile / 'flights'
            directory = root / 'flight-fixture'
            directory.mkdir(parents=True)
            plan = SessionPlan(directory, 'a' * 64, ('en',), False, completed=True, local_only=True)
            plan.save()
            completion = directory / 'completion.json'
            before = completion.read_bytes()
            MAIN.atomic_write_json(directory / 'session.json', {'recording_ended': True})
            previous = directory / 'reports' / 'previous.pdf'
            previous.parent.mkdir()
            previous.write_bytes(b'previous report edition')
            window = QtWindow(profile, '1.7.1', '', 500, False)
            with (mock.patch.object(flight_workflow, 'analyze_session', return_value=mock.Mock(samples=[True])),
                  mock.patch.object(flight_workflow, 'render_report_variants', side_effect=render),
                  mock.patch.object(flight_workflow.flight_delivery, 'deliver_report') as deliver):
                try:
                    window.show_page('flight')
                    self.wait_for(lambda: bool(window.operator.flights.model.rows))
                    window.operator.flights.table.selectRow(0)
                    reports = window.flight_reports
                    reports.create_button.click()
                    self.wait_for(entered.is_set)
                    self.assertTrue(reports.busy())
                    with self.assertRaises((OSError, RuntimeError)):
                        WorkspaceLease(root)
                    window.restart()
                    self.assertFalse(window.restart_requested)
                    self.assertTrue(window.isVisible())
                    released.set()
                    self.wait_for(lambda: not reports.busy())
                    self.assertEqual(before, completion.read_bytes())
                    self.assertEqual(b'previous report edition', previous.read_bytes())
                    self.assertEqual(len(REPORT_LANGUAGES) + 1, len(tuple(previous.parent.glob('*.pdf'))))
                    deliver.assert_not_called()
                    lease = WorkspaceLease(root)
                    lease.release()
                finally:
                    released.set()
                    self.wait_for(lambda: not window.flight_reports.busy())
                    window.exit_window()

    def test_restores_stable_gallery_selection_and_remembered_photo_focus(self) -> None:
        """Check visible Photos controls and restore the same saved selection.

        Returns:
            None: Verifies the disconnected card, actual ratio labels and
                stable tree, focused photo and range anchor after navigation.
                A tree-only rename keeps the authorized renderer and selection.
                Only the exact linked capture paints the heading and collapsed parents.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test/'
            path = photos_account_tree_path(profile, issuer, 1)
            self.assertTrue(save_photos_tree_preferences(path, opened={1: False}, selected=2))
            before = path.read_bytes()
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            galleries = (LinkedGallery(1, None, 'Root'), LinkedGallery(2, 1, 'Child'))
            rows = {
                1: (PhotoRow(1, 'Root photo', LinkedPhoto(1, 'Root photo')),),
                2: tuple(PhotoRow(index, str(index), LinkedPhoto(index, str(index))) for index in range(2, 17)),
            }
            window = QtWindow(profile, '1.7.1', '', 500, False)
            photos = window.photos
            renamed = (galleries[0], LinkedGallery(2, 1, 'Renamed child'))
            with (mock.patch('uploader.qt_photos.read_gallery_photos',
                             side_effect=lambda _session, gallery, _cancel: rows[gallery]) as photo_reads,
                  mock.patch('uploader.qt_photos.read_gallery_tree', return_value=renamed),
                  mock.patch('uploader.qt_photos.stream_thumbnails')):
                try:
                    window.show()
                    self.wait_for(lambda: photos._connect_card.isVisible())
                    self.assertLessEqual(
                        photos._connect_card.height(), photos._connect_card.sizeHint().height(),
                    )
                    self.assertTrue(photos.link_button.isVisible())
                    self.assertFalse(photos._split.isVisible())
                    photos._receive_catalogue(LinkedPhotos(
                        session, issuer, 1, galleries, load_photos_tree_preferences(path),
                    ))
                    self.wait_for(lambda: len(photos.model.rows) == len(rows[2]))
                    self.wait_for(lambda: photos._format_row.isVisible())
                    self.assertFalse(photos._connect_card.isVisible())
                    for format_key, button in photos._format_buttons.items():
                        caption = photos._text('card_format_default') if format_key == 'default' else format_key
                        self.assertEqual(caption, button.text())
                        self.assertTrue(button.isVisible())
                        self.assertFalse(button.icon().isNull())
                        self.assertEqual(Qt.ToolButtonStyle.ToolButtonTextUnderIcon, button.toolButtonStyle())
                    photos._format_buttons['9:16'].click()
                    self.assertLess(photos.tileWidth, photos.tileHeight)
                    photos._format_buttons['default'].click()
                    tree = photos._gallery_tree
                    parent = tree.topLevelItem(0)
                    self.assertTrue(parent.isExpanded())
                    self.assertEqual(before, path.read_bytes())
                    photos.choosePhoto(1, False, False)
                    selected = photos.model.rows[1].identity
                    photos.focusedPhoto(1)
                    restored = []
                    photos.restoreScroll.connect(lambda _first, focus: restored.append(focus))
                    tree.setCurrentItem(parent)
                    self.wait_for(lambda: photos.model.rows == rows[1])
                    self.assertEqual(1, load_photos_tree_preferences(path).selected)
                    tree.setCurrentItem(parent.child(0))
                    self.wait_for(lambda: selected in photos.model.selected)
                    self.assertEqual(photos.model.index_by_id[selected], restored[-1])
                    self.assertEqual(2, load_photos_tree_preferences(path).selected)
                    reads_before_refresh = photo_reads.call_count
                    render_revision = photos._revision
                    photos.refresh_gallery_tree()
                    self.wait_for(lambda: photos._gallery_title.text() == renamed[1].title)
                    self.assertEqual(2, photos._gallery_id)
                    self.assertIn(selected, photos.model.selected)
                    self.assertEqual(reads_before_refresh, photo_reads.call_count)
                    self.assertEqual(render_revision, photos._revision)
                    parent = tree.topLevelItem(0)
                    parent.setExpanded(False)
                    photos.set_capture_target((issuer, 1, 2))
                    self.assertEqual({1, 2}, photos._capture_markers)
                    self.assertTrue(photos._capture_heading.isVisibleTo(window))
                    parent.setExpanded(True)
                    self.assertEqual({2}, photos._capture_markers)
                    window.show_page('settings')
                    self.assertFalse(photos._capture_pulse_timer.isActive())
                    photos.set_capture_target((issuer, 3, 2))
                    self.assertFalse(photos._capture_markers)
                    self.assertTrue(photos._capture_heading.isHidden())
                finally:
                    window.exit_window()

    def test_photos_tree_cache_probe_composes_cache_pin_and_watcher_markers(self) -> None:
        """Keep cache observations local, bounded, generation-bound and composable.

        Returns:
            None: Covers unknown, cloud, partial and local states plus the pin
                and active recording overlays without a gallery transport read.
                Verifies shared colors and a native hover tooltip on the marker.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test/'
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            session.gallery_pinned.return_value = True
            one = LinkedPhoto(11, 'One')
            two = LinkedPhoto(12, 'Two')
            session.has_cached_photo.side_effect = [True, True, True, True]
            local = probe_gallery_cache_status(session, 7, (one, two), True, Event())
            self.assertEqual(('local', 2, 2, True, True), local)
            session.has_cached_photo.side_effect = [True, False]
            partial = probe_gallery_cache_status(session, 7, (one,), True, Event())
            self.assertEqual(('partial', 0, 1, True, True), partial)
            session.has_cached_photo.side_effect = [False, False]
            cloud = probe_gallery_cache_status(session, 7, (one,), True, Event())
            self.assertEqual(('cloud', 0, 1, True, True), cloud)
            unknown = probe_gallery_cache_status(session, 7, None, False, Event())
            self.assertEqual((None, 0, 0, False, True), unknown)
            session.media_page.assert_not_called()
            session.gallery_tree.assert_not_called()

            window = QtWindow(profile, '1.7.1', '', 500, False)
            photos = window.photos
            item = QTreeWidgetItem(['Root'])
            item.setData(0, Qt.ItemDataRole.UserRole, '7')
            photos._gallery_tree.addTopLevelItem(item)
            child = QTreeWidgetItem(['Child'])
            child.setData(0, Qt.ItemDataRole.UserRole, '8')
            item.addChild(child)
            photos._gallery_items = {7: item, 8: child}
            photos._session = session
            photos._identity = (issuer, 1)
            photos._tree_path = photos_account_tree_path(profile, issuer, 1)
            photos._gallery_id = 7
            photos._revision = 3
            try:
                for state in ('unknown', 'cloud', 'partial', 'local'):
                    photos._receive_cache_probe(QtGalleryCacheProbe(
                        session, 7, 3, (state, 1, 1, True, True),
                    ))
                    self.assertEqual(state, photos._cache_states[7])
                    self.assertTrue(photos._gallery_pins[7])
                    self.assertFalse(item.icon(0).isNull())
                    description = '\n'.join((
                        photos._text('cache_' + state), photos._text('cache_marker_pinned'),
                    ))
                    self.assertEqual(description, item.toolTip(0))
                    self.assertEqual(description, item.data(
                        0, Qt.ItemDataRole.AccessibleDescriptionRole,
                    ))
                window.show()
                tree = photos._gallery_tree
                previous = tree.blockSignals(True)
                try:
                    photos._gallery_panel.show()
                    tree.show()
                    self.application.processEvents()
                finally:
                    tree.blockSignals(previous)
                rect = tree.visualItemRect(item)
                self.assertTrue(rect.isValid())
                point = QPoint(rect.left() + tree.iconSize().width() // 2, rect.center().y())
                self.application.sendEvent(tree.viewport(), QHelpEvent(
                    QEvent.Type.ToolTip, point, tree.viewport().mapToGlobal(point),
                ))
                try:
                    self.assertEqual(item.toolTip(0), QToolTip.text())
                finally:
                    QToolTip.hideText()
                session.has_cached_photo.side_effect = None
                session.has_cached_photo.return_value = False
                loaded_rows = (PhotoRow(one.image_id, one.title, one),)
                photos._queue_gallery_cache_probe(loaded_rows)
                self.wait_for(lambda: photos._cache_states[7] == 'cloud')
                photos._receive_cache_probe(QtGalleryCacheProbe(
                    session, 7, 3, ('local', 1, 1, True, True),
                ))
                photos.set_capture_target((issuer, 1, 7))
                self.assertIn(7, photos._capture_markers)
                self.assertIn(photos._text('cache_marker_pinned'), item.toolTip(0))
                self.assertIn(photos._text('cache_marker_watcher'), item.toolTip(0))
                item.setExpanded(False)
                photos.set_capture_target((issuer, 1, 8))
                self.assertIn(7, photos._capture_markers)
                self.assertIn(photos._text('cache_marker_watcher_descendant'), item.toolTip(0))
                self.assertIn(photos._text('cache_marker_watcher'), child.toolTip(0))
                item.setExpanded(True)
                self.assertNotIn(7, photos._capture_markers)
                photos.set_capture_target((issuer, 1, 7))
                bright = qt_icon(
                    'local', size=PHOTO_TREE_MARKER_SIZE, pinned=True,
                    recording=True, pulse=True,
                ).pixmap(PHOTO_TREE_MARKER_SIZE).toImage()
                for mode in (QIcon.Mode.Normal, QIcon.Mode.Active, QIcon.Mode.Selected):
                    for icon_state in (QIcon.State.Off, QIcon.State.On):
                        self.assertEqual(
                            bright,
                            item.icon(0).pixmap(
                                PHOTO_TREE_MARKER_SIZE, mode, icon_state,
                            ).toImage(),
                        )
                expected = qt_icon(
                    'local', size=PHOTO_TREE_MARKER_SIZE,
                    pinned=True, recording=True, pulse=True,
                )
                self.assertEqual(
                    expected.pixmap(PHOTO_TREE_MARKER_SIZE).toImage(),
                    item.icon(0).pixmap(PHOTO_TREE_MARKER_SIZE).toImage(),
                )
                menu_status = qt_icon('local', size=PHOTO_TREE_MARKER_SIZE, pinned=True)
                self.assertEqual(
                    menu_status.pixmap(PHOTO_TREE_MARKER_SIZE).toImage(),
                    photos._gallery_options.icon().pixmap(PHOTO_TREE_MARKER_SIZE).toImage(),
                )
                photos.set_capture_target(None)
                self.assertNotIn(photos._text('cache_marker_watcher'), item.toolTip(0))
                self.assertIn(photos._text('cache_marker_pinned'), item.toolTip(0))
                photos._revision += 1
                photos._receive_cache_probe(QtGalleryCacheProbe(
                    session, 7, 3, ('cloud', 0, 1, True, False),
                ))
                self.assertEqual('local', photos._cache_states[7])
                session.media_page.assert_not_called()
            finally:
                window.exit_window()

    def test_folder_shelf_uses_exact_child_ids_and_shared_view_preferences(self) -> None:
        """Check real shelf navigation and independent portable folder controls.

        Returns:
            None: Stores only the current parent's folder preferences, restores
                the split, and selects a child with a full-width server ID.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test/'
            path = photos_account_tree_path(profile, issuer, 1)
            view = path.with_name(path.stem + '.view.json')
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            session.can_read_media.return_value = False
            # A valid identifier outside Qt's signed 32-bit integer signal range.
            high_id = 5_000_000_001
            child = LinkedGallery(high_id, 1, 'Second subgallery')
            first = LinkedGallery(7, 1, 'First subgallery')
            galleries = (LinkedGallery(1, None, 'Parent'), first, child)
            root_rows = (PhotoRow(4, 'Parent photo', LinkedPhoto(4, 'Parent photo')),)
            window = QtWindow(profile, '1.7.1', '', 500, False)
            photos = window.photos
            with (mock.patch('uploader.qt_photos.read_gallery_photos',
                             side_effect=lambda _session, gallery, _cancel: root_rows if gallery == 1 else ()),
                  mock.patch('uploader.qt_photos.stream_thumbnails')):
                try:
                    window.show()
                    photos._receive_catalogue(LinkedPhotos(
                        session, issuer, 1, galleries, load_photos_tree_preferences(path),
                    ))
                    self.wait_for(lambda: photos._folder_shelf.isVisible() and photos.model.rows == root_rows)
                    self.assertEqual(PHOTOS_TREE_WIDTH, photos.tree_width())
                    self.assertEqual((first, child), photos._folder_shelf.cards.rows)
                    self.assertIs(photos._format_row.parentWidget(), photos._photo_pane)
                    photos._folder_format_buttons['4:5'].click()
                    photos._folder_order.click()
                    self.assertEqual((child, first), photos._folder_shelf.cards.rows)
                    self.assertEqual('4:5', load_gallery_card_format(view, 1, folders=True))
                    self.assertEqual('default', load_gallery_card_format(view, 1))
                    self.assertEqual('newest', load_gallery_photo_order(view, 1, folders=True))
                    self.assertEqual('oldest', load_gallery_photo_order(view, 1))
                    # A manual split is stored in the existing thousandths wire format.
                    photos._save_folder_split(375)
                    self.assertEqual(375, load_gallery_folder_split(view, 1))
                    photos._subgallery_switch.click()
                    self.assertFalse(photos._folder_shelf.isVisible())
                    self.assertIs(photos._format_row.parentWidget(), photos._center_toolbar)
                    photos._subgallery_switch.click()
                    self.assertTrue(photos._folder_shelf.isVisible())
                    photos._folder_shelf._activate(photos._folder_shelf.cards.index(0, 0))
                    self.wait_for(lambda: photos._gallery_id == high_id and not photos._folder_shelf.isVisible())
                    self.assertEqual(high_id, load_photos_tree_preferences(path).selected)
                    self.assertEqual(high_id, int(photos._gallery_tree.currentItem().data(0, Qt.ItemDataRole.UserRole)))
                    session.media_page.assert_not_called()
                finally:
                    window.exit_window()

    def test_gallery_title_editor_reviews_current_value_and_fences_its_write(self) -> None:
        """Edit a linked gallery through the real native pencil and exact worker.

        Returns:
            None: Shows the full authorized value before mutation and refuses
                dismissal until the current accepted write has reached a result.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test/'
            tree = photos_account_tree_path(profile, issuer, 1)
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            session.can_edit_gallery_metadata.return_value = True
            session.can_read_media.return_value = False
            session.read_gallery_edit.return_value = LinkedGalleryEditSnapshot(
                1, 'Complete server title', '', '1', 'public', True, False,
            )
            entered, released = Event(), Event()

            def save(gallery: int, field: str, value: str, cancel: Event) -> LinkedGalleryEditSnapshot:
                """Hold the existing field writer at its accepted phase.

                Args:
                    gallery (int): Original authorized gallery ID.
                    field (str): Exact field selected by the pencil.
                    value (str): Native user draft submitted to the existing owner.
                    cancel (Event): Current editor lifetime while the write is active.
                Returns:
                    LinkedGalleryEditSnapshot: Confirmed result for precisely that field.
                """
                entered.set()
                if not released.wait(QT_CHILD_SECONDS) or cancel.is_set():
                    raise TimeoutError('The editor fixture did not reach terminal publication.')
                return LinkedGalleryEditSnapshot(gallery, value, '', '2', 'public', True, False)

            session.apply_gallery_edit.side_effect = save
            window = QtWindow(profile, MAIN.APP_VERSION, '', 500, False)
            photos = window.photos
            with (
                mock.patch('uploader.qt_photos.read_gallery_photos', return_value=()),
                mock.patch('uploader.qt_photos.read_gallery_tree', return_value=(LinkedGallery(1, None, 'New title'),)),
            ):
                try:
                    window.show()
                    photos._receive_catalogue(LinkedPhotos(
                        session, issuer, 1, (LinkedGallery(1, None, 'Short tree caption'),),
                        load_photos_tree_preferences(tree),
                    ))
                    self.wait_for(lambda: not photos._load.busy())
                    photos._edit_title_button.click()
                    editor = photos._gallery_editor
                    self.assertIsNotNone(editor)
                    self.wait_for(editor.save_button.isEnabled)
                    self.assertIsInstance(editor.entry, QLineEdit)
                    self.assertEqual('Complete server title', editor.entry.text())
                    session.apply_gallery_edit.assert_not_called()
                    editor.entry.setText('New title')
                    editor.save_button.click()
                    self.wait_for(entered.is_set)
                    self.assertTrue(photos.busy())
                    editor.reject()
                    self.assertTrue(editor.isVisible())
                    released.set()
                    self.wait_for(lambda: photos._gallery_editor is None)
                    session.apply_gallery_edit.assert_called_once_with(1, 'title', 'New title', mock.ANY)
                    self.wait_for(lambda: photos._gallery_title.text() == 'New title')
                finally:
                    released.set()
                    self.wait_for(lambda: not photos.busy())
                    window.exit_window()

    def test_gallery_create_keeps_failed_draft_and_admits_the_verified_child(self) -> None:
        """Keep one request's title for retry and admit only its verified receipt.

        Returns:
            None: The same journal command consumes the original title and
                parent, displays its confirmed child, and leaves the parent selected.
                A gallery action also follows the exact cached simulator context.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test'
            path = photos_account_tree_path(profile, issuer, 1)
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            session.can_create_gallery.return_value = True
            session.can_read_media.return_value = False
            parent = LinkedGallery(1, None, 'Parent')
            child = LinkedGallery(2, 1, 'Retained child title')
            receipt = BoundCreateReceipt(child, 'a' * 64)
            window = QtWindow(profile, MAIN.APP_VERSION, '', 500, False)
            photos = window.photos
            with (
                mock.patch('uploader.qt_photos.read_gallery_photos', return_value=()),
                mock.patch('uploader.qt_photos.QInputDialog.getText', return_value=(child.title, True)) as editor,
                mock.patch('uploader.qt_photos.GalleryCreateJournal.acknowledge') as acknowledge,
                mock.patch('uploader.qt_photos.GalleryCreateJournal.create_or_resume',
                           side_effect=(LinkingError('synthetic lost response'), receipt)) as create,
            ):
                try:
                    window.show()
                    photos._receive_catalogue(LinkedPhotos(
                        session, issuer, 1, (parent,), load_photos_tree_preferences(path),
                    ))
                    self.wait_for(lambda: not photos._load.busy())
                    simbrief = mock.Mock()
                    available = mock.Mock(return_value=False)
                    demand: list[bool] = []
                    photos.simbrief_available = available
                    photos.simbrief_action = simbrief
                    photos.gallery_context_changed.connect(demand.append)
                    photos._populate_gallery_menu()
                    action = photos._simbrief_menu_entry
                    self.assertIsNotNone(action)
                    self.assertFalse(action.isVisible())
                    self.assertEqual([True], demand)
                    available.return_value = True
                    photos.update_simbrief_context(True)
                    self.assertTrue(action.isVisible())
                    action.trigger()
                    simbrief.assert_called_once_with()
                    photos._end_gallery_context(photos._gallery_menu)
                    self.wait_for(lambda: demand == [True, False])
                    photos._create_child_button.click()
                    self.wait_for(lambda: not photos.busy())
                    self.assertNotIn(2, photos._gallery_items)
                    acknowledge.assert_not_called()
                    photos._create_child_button.click()
                    self.wait_for(lambda: not photos.busy() and 2 in photos._gallery_items)
                    self.assertEqual(['', child.title], [call.kwargs['text'] for call in editor.call_args_list])
                    self.assertEqual([mock.call(session, issuer, 1, child.title, 1)] * 2, create.call_args_list)
                    acknowledge.assert_called_once_with(issuer, 1, receipt)
                    self.assertEqual((child,), photos._folder_shelf.cards.rows)
                    self.assertEqual(1, photos._gallery_id)
                    self.assertIsNone(photos._create_draft)
                finally:
                    window.exit_window()

    def test_native_switch_pixmap_preserves_all_rows_of_tk_artwork(self) -> None:
        """Check the native adapter does not misalign or alter any switch row.

        Returns:
            None: Shared switch pixels match and the native icon preserves the
                exact two-times source artwork and density.
        """
        for selected, display in ((False, 'normal'), (True, 'normal'), (True, 'focus')):
            with self.subTest(selected=selected, display=display):
                artwork = render_toggle_switch(selected, display)
                self.assertIsNotNone(artwork)
                image = _switch_pixmap(selected, display, 1.0).toImage()
                self.assertEqual(artwork.size, (image.width(), image.height()))
                with artwork:
                    for y in range(artwork.height):
                        self.assertEqual(
                            [artwork.getpixel((x, y)) for x in range(artwork.width)],
                            [image.pixelColor(x, y).getRgb()[:3] for x in range(image.width())],
                        )
        density = ICON_DENSITIES[1]
        icon_size = ACTION_ICON_SIZE
        native_icon = qt_icon('folder', size=icon_size).pixmap(
            QSize(icon_size, icon_size), density,
        )
        source_icon = render_smooth_icon(
            'folder', (icon_size, icon_size), dpi_scale=density,
        )
        self.assertIsNotNone(source_icon)
        expected_pixels = icon_size * density
        self.assertEqual((expected_pixels, expected_pixels),
                         (native_icon.width(), native_icon.height()))
        self.assertEqual(density, native_icon.devicePixelRatio())
        with source_icon:
            source_image = QImage(
                source_icon.tobytes(), source_icon.width, source_icon.height,
                source_icon.width * 4, QImage.Format.Format_RGBA8888,
            ).copy().convertToFormat(QImage.Format.Format_RGBA8888_Premultiplied)
        native_image = native_icon.toImage().convertToFormat(
            QImage.Format.Format_RGBA8888_Premultiplied,
        )
        self.assertEqual(source_image.size(), native_image.size())
        self.assertEqual(source_image, native_image)

    def test_admin_media_confirmation_refresh_and_metadata_scope(self) -> None:
        """Use Qt's native confirmation with an exact synthetic Admin photo set.

        Returns:
            None: Cancel sends no write, an ambiguous deletion refreshes once,
                confirmed deletion removes the exact rows, GPS is scoped, and
                the inspector reuses only authorized results and rejects late reads.
                The inline card preserves the real action order and keyboard exit.
                A long filename leaves the tree, viewport and pane controls usable.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test/'
            path = photos_account_tree_path(profile, issuer, 1)
            self.assertTrue(save_gallery_photo_order(path.with_name(path.stem + '.view.json'), 1, 'newest'))
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            session.can_read_media.return_value = True
            session.can_read_admin_media.return_value = False
            session.can_delete_admin_media.return_value = True
            long_photo_name = (
                'Synthetic_flight_capture_with_a_single_long_filename_for_the_'
                'information_pane_and_no_spaces_to_wrap_when_resizing_the_gallery_'
                'while_the_original_file_details_remain_available.jpg'
            )
            session.media_photo_info.return_value = {
                'title': long_photo_name, 'gps_lat': 49.12,
                'exif_camera_model': 'Synthetic',
            }
            galleries = (LinkedGallery(1, None, 'Root'),)
            # Keep the direct photo IDs in a stable but deliberately nonnumeric order.
            rows = (
                PhotoRow(12, 'Retained', LinkedPhoto(12, 'Retained', visibility='public')),
                PhotoRow(8, long_photo_name, LinkedPhoto(
                    8, long_photo_name, visibility='private',
                )),
                PhotoRow(3, 'Other selected', LinkedPhoto(3, 'Other selected', visibility='private')),
            )
            window = QtWindow(profile, '1.7.1', '', 500, False)
            photos = window.photos
            with (
                mock.patch('uploader.qt_photos.read_gallery_photos', return_value=rows) as catalogue,
                mock.patch('uploader.qt_photos.stream_thumbnails'),
            ):
                try:
                    window.show()
                    window.resize(1100, 760)
                    photos._receive_catalogue(LinkedPhotos(
                        session, issuer, 1, galleries, load_photos_tree_preferences(path),
                    ))
                    self.wait_for(lambda: photos.model.rows == rows)
                    photos.photoMenu(1, photos.quick.width() - 1, photos.quick.height() - 1)
                    context = photos._photo_context
                    self.assertIsNotNone(context)
                    self.assertTrue(context.isVisibleTo(window))
                    self.assertTrue(photos.rect().contains(context.geometry()))
                    captions = [button.text() for button in context.findChildren(QPushButton)]
                    self.assertEqual([
                        photos._text('select_photo'), photos._text('delete_photo'),
                        photos._text('photo_info'),
                    ], captions[:3])
                    QTest.keyClick(context, Qt.Key.Key_Escape)
                    self.assertIsNone(photos._photo_context)
                    session.delete_media_photos.assert_not_called()
                    splitter = photos._split
                    tree_width = photos.tree_width()
                    photos._request_information(session, 1, 8)
                    self.wait_for(lambda: 'Synthetic' in photos._inspector.toPlainText())
                    text = photos._inspector.toPlainText()
                    self.assertTrue(photos._inspector_panel.isVisible())
                    self.assertGreater(photos.inspector_width(), 0)
                    self.assertIsInstance(splitter, QSplitter)
                    self.assertTrue(photos._gallery_tree.isVisibleTo(window))
                    self.assertTrue(photos.quick.isVisibleTo(window))
                    self.assertEqual(tree_width, photos.tree_width())
                    heading = photos._inspector_heading
                    self.assertEqual(long_photo_name, heading.accessibleName())
                    self.assertIn(long_photo_name, heading.toolTip())
                    self.assertIn(long_photo_name, text)
                    self.assertIn('…', heading.text())
                    self.assertLessEqual(
                        heading.fontMetrics().horizontalAdvance(heading.text()),
                        heading.contentsRect().width(),
                    )
                    self.assertGreaterEqual(
                        splitter.sizes()[1], UI_LAYOUT_VIEWER_MINIMUM_WIDTH,
                    )
                    self.assertLessEqual(
                        photos.inspector_width(), UI_LAYOUT_INFO_WIDTH_BOUNDS[1],
                    )
                    inspector = photos._inspector_panel
                    close_info = next(
                        button for button in inspector.findChildren(QPushButton)
                        if button.accessibleName() == photos._text('close_photo_info')
                    )
                    handle_index = splitter.indexOf(inspector)
                    original = photos.inspector_width()
                    splitter.moveSplitter(0, handle_index)
                    self.application.processEvents()
                    widened = photos.inspector_width()
                    self.assertGreater(widened, original)
                    self.assertEqual(tree_width, photos.tree_width())
                    self.assertGreaterEqual(
                        splitter.sizes()[1], UI_LAYOUT_VIEWER_MINIMUM_WIDTH,
                    )
                    self.assertLessEqual(
                        widened, UI_LAYOUT_INFO_WIDTH_BOUNDS[1],
                    )
                    self.assertTrue(splitter.contentsRect().contains(inspector.geometry()))
                    self.assertTrue(inspector.rect().contains(close_info.geometry()))
                    desktop_size = window.size()
                    window.resize(*QT_WINDOW_MINIMUM)
                    self.wait_for(lambda: photos.inspector_width() < widened)
                    self.assertGreaterEqual(
                        splitter.sizes()[1], UI_LAYOUT_VIEWER_MINIMUM_WIDTH,
                    )
                    window._save_layout()
                    self.assertEqual(widened, window._layout.photos_info_width)
                    window.resize(desktop_size)
                    self.wait_for(lambda: photos.inspector_width() == widened)
                    splitter.moveSplitter(splitter.width(), handle_index)
                    self.application.processEvents()
                    narrowed = photos.inspector_width()
                    self.assertLess(narrowed, widened)
                    self.assertEqual(narrowed, photos.preferred_inspector_width)
                    self.assertEqual(tree_width, photos.tree_width())
                    self.assertIn('Synthetic', text)
                    self.assertNotIn('49.12', text)
                    session.can_read_admin_media.return_value = True
                    photos._request_information(session, 1, 8)
                    self.wait_for(lambda: '49.12' in photos._inspector.toPlainText())
                    text = photos._inspector.toPlainText()
                    self.assertIn('49.12', text)
                    photos._close_inspector()
                    self.assertFalse(photos._inspector_panel.isVisible())
                    reads = session.media_photo_info.call_count
                    photos._request_information(session, 1, 8)
                    self.assertEqual(narrowed, photos.inspector_width())
                    self.assertEqual(tree_width, photos.tree_width())
                    self.assertIn('49.12', photos._inspector.toPlainText())
                    self.assertEqual(reads, session.media_photo_info.call_count)
                    session.can_read_admin_media.return_value = False
                    photos._request_information(session, 1, 8)
                    self.assertNotIn('49.12', photos._inspector.toPlainText())
                    self.assertEqual(reads, session.media_photo_info.call_count)

                    entered, release, saw_cancel = Event(), Event(), Event()

                    def held_information(
                        gallery_id: int, photo_id: int, *, cancelled: Event | None = None,
                    ) -> LinkedMediaDetails:
                        """Return one late synthetic reply after the visible pane closes.

                        Args:
                            gallery_id (int): Authorized fixture gallery identifier.
                            photo_id (int): Exact requested direct photo identifier.
                            cancelled (Event | None): Current inspector generation fence.
                        Returns:
                            LinkedMediaDetails: Exact fixture IDs and late camera field.
                        """
                        entered.set()
                        if not release.wait(SCENE_WAIT_MS / 1000):
                            raise TimeoutError('The dismissed metadata fixture was not released.')
                        if cancelled is not None and cancelled.is_set():
                            saw_cancel.set()
                        return {'gallery_id': gallery_id, 'image_id': photo_id, 'exif_camera_model': 'Late'}

                    session.media_photo_info.side_effect = held_information
                    try:
                        photos.choosePhoto(2, False, False)
                        self.wait_for(entered.is_set)
                        close_info = next(button for button in photos._inspector_panel.findChildren(QPushButton)
                                          if button.accessibleName() == photos._text('close_photo_info'))
                        self.assertTrue(close_info.isVisibleTo(window))
                        close_info.click()
                        self.assertEqual({3}, photos.model.selected)
                    finally:
                        release.set()
                    self.wait_for(lambda: saw_cancel.is_set() and not photos._information.busy())
                    self.assertFalse(photos._inspector_panel.isVisible())
                    self.assertNotIn((1, 3, False), photos._photo_info_cache)
                    session.media_photo_info.side_effect = None
                    photo_ids = (8, 3)

                    def confirmation_button(action: str) -> QPushButton:
                        """Find an explicit action inside the real anchored confirmation.

                        Args:
                            action (str): Localized Photos action key.
                        Returns:
                            QPushButton: Visible action for the selected direct photo set.
                        """
                        photos.model.select(set(photo_ids))
                        photos.photoMenu(1, photos.quick.width() - 1, photos.quick.height() - 1)
                        menu = photos._photo_context
                        assert menu is not None
                        trigger = next(button for button in menu.findChildren(QPushButton)
                                       if button.text() == photos._text('delete_selected').format(count=len(photo_ids)))
                        anchor = photos._photo_context_anchor
                        trigger.click()
                        prompt = photos._photo_context
                        assert prompt is not None
                        self.assertIsNot(menu, prompt)
                        self.assertEqual(anchor, photos._photo_context_anchor)
                        self.assertTrue(prompt.isVisibleTo(window))
                        self.assertTrue(photos.rect().contains(prompt.geometry()))
                        return next(button for button in prompt.findChildren(QPushButton)
                                    if button.text() == photos._text(action))

                    cancel = confirmation_button('cancel')
                    QTest.keyClick(cancel, Qt.Key.Key_Escape)
                    self.assertIsNone(photos._photo_context)
                    session.delete_media_photos.assert_not_called()
                    confirmation_button('cancel').click()
                    self.assertIsNone(photos._photo_context)
                    remove = confirmation_button('delete_permanently')
                    self.assertTrue(remove.property('destructive'))
                    session.can_delete_admin_media.return_value = False
                    remove.click()
                    session.delete_media_photos.assert_not_called()
                    session.can_delete_admin_media.return_value = True
                    session.delete_media_photos.side_effect = LinkingError('Ambiguous synthetic reply.')
                    confirmation_button('delete_permanently').click()
                    self.wait_for(lambda: (
                        photos.model.rows == rows and photos.status.text() == photos._text('delete_uncertain')
                    ))
                    self.assertEqual(2, catalogue.call_count)
                    session.delete_media_photos.assert_called_once_with(1, photo_ids, mock.ANY)
                    session.delete_media_photos.side_effect = None
                    session.delete_media_photos.return_value = photo_ids
                    catalogue.return_value = rows[:1]
                    confirmation_button('delete_permanently').click()
                    self.wait_for(lambda: photos.model.rows == rows[:1])
                    self.assertEqual([mock.call(1, photo_ids, mock.ANY)] * 2, session.delete_media_photos.call_args_list)
                finally:
                    window.exit_window()

    def test_photos_watcher_transfers_only_its_confirmed_temporary_authority(self) -> None:
        """Exercise the Photos gallery command with the canonical linking boundary.

        Returns:
            None: Leaves the accepted key with Capture and revokes only an
                unused key after an explicitly refused start.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test/'
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            session.can_upload.return_value = True
            session.matches_issuer.return_value = True
            session.open_watch_key.side_effect = ('accepted synthetic key', 'refused synthetic key')
            target = LinkedUploadTarget(session, issuer, 1, 1, 'Root')
            select, start = mock.Mock(), mock.Mock()
            connections = ConnectionActions(select, mock.Mock(), mock.Mock(), mock.Mock())
            window = QtWindow(profile, MAIN.APP_VERSION, '', 500, False, connections=connections)
            photos = window.photos
            photos.start_contextual = start
            with (
                mock.patch('uploader.qt_photos.read_gallery_photos', return_value=()),
                mock.patch('uploader.qt_photos.verify_linked_upload_target', return_value=target) as verify,
                mock.patch.object(QMessageBox, 'question', return_value=QMessageBox.StandardButton.Yes),
            ):
                try:
                    window.show()
                    path = photos_account_tree_path(profile, issuer, 1)
                    photos._receive_catalogue(LinkedPhotos(
                        session, issuer, 1, (LinkedGallery(1, None, 'Root'),),
                        load_photos_tree_preferences(path),
                    ))
                    photos._start_contextual_watcher(session, 1)
                    self.wait_for(lambda: not photos.busy())
                    select.assert_called_once_with(target)
                    self.assertIsNone(select.call_args.args[0].watch_key)
                    self.assertEqual('accepted synthetic key', start.call_args.args[0].watch_key)
                    verify.assert_called_once_with(session, issuer, 1, session.approved_scope(), 1, mock.ANY)
                    session.close_watch_key.assert_not_called()
                    start.side_effect = ValueError('Synthetic refused capture.')
                    photos._start_contextual_watcher(session, 1)
                    self.wait_for(lambda: not photos.busy())
                    session.close_watch_key.assert_called_once_with(1, 'refused synthetic key')
                finally:
                    window.exit_window()

    def test_gallery_music_and_slideshow_use_the_current_presentation_grant(self) -> None:
        """Exercise Qt presentation with a real playlist and isolated prepared media.

        Returns:
            None: Loads the authorized interval, transfers one verified track,
                preserves native pause and removes the file when scope is lost.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.can_read_presentation.return_value = True
            body = b'verified synthetic track'
            track = LinkedTrack('a' * 32, 'Fixture track', 'audio/wav', len(body), sha256(body).hexdigest())
            session.media_presentation.return_value = LinkedPresentation(True, True, 3, 'fast', True, (track,))
            session.media_track.return_value = body
            window = QtWindow(profile, MAIN.APP_VERSION, '', 500, False)
            playback = window.photos.playback
            playback._platform_playback = True
            prepared: list[Path] = []

            def adopt_file(path: Path) -> None:
                """Replace the audio device while keeping production file cleanup.

                Args:
                    path (Path): Verified temporary file prepared by the real worker.
                Returns:
                    None: The same native owner removes the adopted path.
                """
                playback._player._path = path
                prepared.append(path)

            with (
                mock.patch.object(playback._player, 'start', side_effect=adopt_file),
                mock.patch.object(playback._player, 'running', return_value=True),
                mock.patch.object(playback._player, 'pause') as pause,
            ):
                try:
                    playback.bind(session, 1)
                    self.wait_for(lambda: playback.choice.count() == 1)
                    self.assertEqual('photosMusicButton', playback.music_button.objectName())
                    self.assertTrue(all(button.isEnabled() for button in playback._lightbox_buttons))
                    session.media_track.assert_not_called()
                    self.assertEqual(3000, window.photos._slideshow.interval())
                    self.assertTrue(playback.start_slideshow_music())
                    self.wait_for(lambda: bool(prepared))
                    self.assertEqual(body, prepared[0].read_bytes())
                    session.media_track.assert_called_once_with(1, track, mock.ANY)
                    playback.play.click()
                    pause.assert_called_once_with(True)
                    session.can_read_presentation.return_value = False
                    playback.check_authority()
                    self.assertFalse(prepared[0].exists())
                    self.assertTrue(all(not button.isEnabled() for button in playback._lightbox_buttons))
                    self.assertFalse(playback.start_slideshow_music())
                finally:
                    window.exit_window()

    def test_photos_authorization_uses_shared_recent_address_history(self) -> None:
        """Use the bounded profile history without changing active grants.

        Returns:
            None: Recent selection, live-site parity, retry captions, local
                removal, busy refusal and the active-site guard stay visible.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            history_path = profile / 'recent-gallery-links.json'
            newest = 'https://gallery.example/A'
            older = 'https://gallery.example/B'
            save_recent_gallery_addresses(history_path, (newest, older))
            window = QtWindow(profile, '1.7.1', '', 500, False)
            photos = window.photos
            try:
                window.show()
                self.wait_for(lambda: photos._connect_card.isVisible())
                photos.link_button.click()
                dialog = photos._authorization_dialog
                self.assertIsNotNone(dialog)
                self.wait_for(lambda: dialog.isVisible())
                recent = dialog.findChild(QTreeWidget, 'photosAuthorizationRecent')
                address = dialog.findChild(QLineEdit, 'photosAuthorizationAddress')
                forget = dialog.findChild(QPushButton, 'photosAuthorizationForget')
                cancel = dialog.findChild(QPushButton, 'photosAuthorizationCancel')
                self.assertIsNotNone(recent)
                self.assertIsNotNone(address)
                self.assertIsNotNone(forget)
                self.assertIsNotNone(cancel)
                self.assertEqual(1, recent.topLevelItemCount())
                group = recent.topLevelItem(0)
                self.assertTrue(group.isExpanded())
                self.assertEqual((newest, older), tuple(
                    group.child(index).data(0, Qt.ItemDataRole.UserRole)
                    for index in range(group.childCount())
                ))
                self.assertEqual(newest, address.text())
                self.assertFalse(recent.selectedItems())
                recent.setFocus()
                QTest.keyClick(recent, Qt.Key.Key_Down)
                self.assertEqual(older, address.text())
                self.assertTrue(forget.isVisible())
                forget.click()
                self.assertEqual((newest,), load_recent_gallery_addresses(history_path))
                self.assertEqual('', address.text())
                self.assertFalse(forget.isVisible())
                cancel.click()

                session = mock.create_autospec(
                    LinkingSession, instance=True, spec_set=True,
                )
                session.available.return_value = True
                session.can_upload.return_value = False
                accepted = 'https://gallery.example/C'
                tree_path = photos_account_tree_path(profile, accepted, 7)
                photos._receive_catalogue(LinkedPhotos(
                    session, accepted, 7, (), load_photos_tree_preferences(tree_path),
                ))
                self.assertEqual((accepted, newest), load_recent_gallery_addresses(history_path))
                photos._open_authorization_dialog(False, None, None, False)
                browse_dialog = photos._authorization_dialog
                self.assertIsNotNone(browse_dialog)
                assert browse_dialog is not None
                recent = browse_dialog.findChild(QTreeWidget, 'photosAuthorizationRecent')
                browse_address = browse_dialog.findChild(
                    QLineEdit, 'photosAuthorizationAddress',
                )
                browse_submit = browse_dialog.findChild(
                    QPushButton, 'photosAuthorizationSubmit',
                )
                assert recent is not None and browse_address is not None
                assert browse_submit is not None
                live_item = recent.topLevelItem(0).child(0)
                self.assertIsNotNone(live_item)
                browse_address.setText(newest)
                self.assertEqual(
                    tr('en', 'linking_connect_website'), browse_submit.text(),
                )
                recent.setCurrentItem(None)
                recent.setCurrentItem(live_item)
                self.assertEqual(accepted, browse_address.text())
                self.assertEqual(
                    tr('en', 'linking_open_saved_link'), browse_submit.text(),
                )
                with mock.patch.object(photos, '_select_live_site') as select_live:
                    browse_submit.click()
                select_live.assert_called_once()
                self.assertIsNone(photos._authorization_dialog)

                photos._open_authorization_dialog(False, None, None, False)
                retry_dialog = photos._authorization_dialog
                assert retry_dialog is not None
                retry_address = retry_dialog.findChild(
                    QLineEdit, 'photosAuthorizationAddress',
                )
                retry_submit = retry_dialog.findChild(
                    QPushButton, 'photosAuthorizationSubmit',
                )
                retry_cancel = retry_dialog.findChild(
                    QPushButton, 'photosAuthorizationCancel',
                )
                assert retry_address is not None and retry_submit is not None
                assert retry_cancel is not None
                retry_address.setText('https://retry.gallery.example')
                with mock.patch.object(photos._load, 'submit') as queue_request:
                    retry_submit.click()
                    queue_request.assert_called_once()
                self.assertTrue(photos._authorization_active)
                self.assertEqual(
                    tr('en', 'linking_connect_again'), retry_submit.text(),
                )
                failed_attempt = photos._attempt
                photos._catalogue_finished('SyntheticLinkError')
                if failed_attempt is not None:
                    failed_attempt.close()
                self.assertFalse(photos._authorization_active)
                self.assertEqual(
                    tr('en', 'linking_connect_again'), retry_submit.text(),
                )
                retry_address.setText(accepted)
                self.assertEqual(
                    tr('en', 'linking_open_saved_link'), retry_submit.text(),
                )
                retry_cancel.click()
                self.assertFalse(photos._authorization_active)

                photos._open_authorization_dialog(False, None, None, False)
                busy_dialog = photos._authorization_dialog
                assert busy_dialog is not None
                busy_address = busy_dialog.findChild(
                    QLineEdit, 'photosAuthorizationAddress',
                )
                busy_submit = busy_dialog.findChild(
                    QPushButton, 'photosAuthorizationSubmit',
                )
                busy_status = busy_dialog.findChild(
                    QLabel, 'photosAuthorizationStatus',
                )
                assert busy_address is not None and busy_submit is not None
                assert busy_status is not None
                busy_address.setText('https://busy.gallery.example')
                with mock.patch.object(photos, '_maintenance_busy', True):
                    busy_submit.click()
                self.assertEqual(
                    'Wait for the current operation to finish.', busy_status.text(),
                    'The modal must explain why a busy connection cannot switch.',
                )
                self.assertFalse(photos._authorization_active)
                assert photos._authorization_dialog is not None
                photos._authorization_dialog.reject()

                photos.approve_admin()
                active_dialog = photos._authorization_dialog
                self.assertIsNotNone(active_dialog)
                self.wait_for(lambda: active_dialog.isVisible())
                active_recent = active_dialog.findChild(
                    QTreeWidget, 'photosAuthorizationRecent',
                )
                active_address = active_dialog.findChild(
                    QLineEdit, 'photosAuthorizationAddress',
                )
                active_forget = active_dialog.findChild(
                    QPushButton, 'photosAuthorizationForget',
                )
                self.assertEqual(accepted, active_address.text())
                self.assertTrue(active_address.isReadOnly())
                self.assertEqual(1, active_recent.topLevelItemCount())
                self.assertFalse(active_recent.isEnabled())
                group = active_recent.topLevelItem(0)
                self.assertEqual((newest,), tuple(
                    group.child(index).data(0, Qt.ItemDataRole.UserRole)
                    for index in range(group.childCount())
                ))
                self.assertFalse(active_forget.isVisible())
                self.assertIs(session, photos._session)
                self.assertEqual((accepted, 7), photos._identity)
                active_dialog.findChild(
                    QPushButton, 'photosAuthorizationCancel',
                ).click()
                self.assertIs(session, photos._session)
                session.close.assert_not_called()
            finally:
                window.exit_window()

    def test_photos_switches_parked_live_site_without_changing_upload_target(self) -> None:
        """Mount verified live sites one at a time without changing upload mode.

        Returns:
            None: New grants park the prior session and reopen it through the
                cancellable tree lane without consent or target selection.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            first_session = mock.create_autospec(
                LinkingSession, instance=True, spec_set=True,
            )
            second_session = mock.create_autospec(
                LinkingSession, instance=True, spec_set=True,
            )
            first_session.available.return_value = True
            second_session.available.return_value = True
            first_session.can_list_galleries.return_value = True
            first_issuer = 'https://gallery.example/first'
            second_issuer = 'https://gallery.example/second'
            writer = LinkedUploadTarget(first_session, first_issuer, 17, 29, 'Uploads')
            saved = mock.Mock(spec=ModeRecord)
            saved.mode = 'linked'
            saved.issuer = first_issuer
            saved.account_id = writer.user_id
            saved.gallery_id = writer.gallery_id
            select = mock.Mock()
            connections = ConnectionActions(select, mock.Mock(), lambda: saved, lambda: writer)
            window = QtWindow(profile, '1.7.1', '', 500, False, connections=connections)
            photos = window.photos
            try:
                window.show()
                self.wait_for(lambda: photos._connect_card.isVisible())
                first_path = photos_account_tree_path(profile, first_issuer, 17)
                second_path = photos_account_tree_path(profile, second_issuer, 29)
                photos._receive_catalogue(LinkedPhotos(
                    first_session, first_issuer, 17, (),
                    load_photos_tree_preferences(first_path),
                ))
                pending_attempt = mock.Mock()
                with (
                    mock.patch('uploader.qt_photos.LinkingAttempt', return_value=pending_attempt),
                    mock.patch.object(photos._load, 'submit') as submit,
                ):
                    photos._authorize(
                        False, address_override=second_issuer,
                    )
                    self.assertTrue(photos._authorization_active)
                    self.assertIs(first_session, photos._session)
                    self.assertEqual((first_issuer, 17), photos._identity)
                    first_session.close.assert_not_called()
                    submit.assert_called_once()
                    photos.cancel_authorization()
                pending_attempt.close.assert_called_once_with()
                photos._receive_catalogue(LinkedPhotos(
                    second_session, second_issuer, 29, (),
                    load_photos_tree_preferences(second_path),
                ))

                self.assertEqual((second_issuer, 29), photos._identity)
                self.assertEqual(1, len(photos._parked_links))
                self.assertIs(first_session, photos._parked_links[0].session)
                first_session.close.assert_not_called()
                self.assertIs(saved, connections.saved())
                self.assertIs(writer, connections.active())

                photos._populate_gallery_menu()
                site_menu = photos._gallery_menu.actions()[0].menu()
                self.assertIsNotNone(site_menu)
                first_action = next(
                    action for action in site_menu.actions()
                    if first_issuer in action.text()
                )
                with mock.patch(
                    'uploader.qt_photos.read_gallery_tree', return_value=(),
                ) as read_tree:
                    first_action.trigger()
                    self.wait_for(lambda: photos._session is first_session)
                read_tree.assert_called_once_with(first_session, mock.ANY)
                self.assertEqual((first_issuer, 17), photos._identity)
                self.assertEqual(1, len(photos._parked_links))
                self.assertIs(second_session, photos._parked_links[0].session)
                second_session.close.assert_not_called()
                self.assertIs(saved, connections.saved())
                self.assertIs(writer, connections.active())
                select.assert_not_called()
            finally:
                window.exit_window()

    def test_saved_browse_leaves_selection_until_explicit_verified_reconnect(self) -> None:
        """Open the saved gallery on the read lane before any upload admission.

        Returns:
            None: The explicit reconnect checks the original target again without
                opening a browser or rotating the same grant twice.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example/previous'
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            owner = LinkedIdentity(17, 'Visual fixture')
            session.available.return_value = True
            session.can_upload.return_value = True
            session.approved_scope.return_value = ROLLING_UPLOAD_SCOPE
            session.identity.return_value = owner
            target = LinkedUploadTarget(session, issuer, owner.user_id, 29, 'Uploads')
            saved = mock.Mock(spec=ModeRecord)
            saved.mode = 'linked'
            saved.issuer = issuer
            saved.account_id = owner.user_id
            saved.gallery_id = target.gallery_id
            saved.scope = ROLLING_UPLOAD_SCOPE
            select = mock.Mock()
            connections = ConnectionActions(select, mock.Mock(), lambda: saved, lambda: None)
            window = QtWindow(profile, '1.7.1', '', 500, False, connections=connections)
            photos = window.photos
            try:
                window.show()
                with (
                    mock.patch('uploader.qt_photos.sys', mock.Mock(platform='win32')),
                    mock.patch(
                        'uploader.qt_photos.restore_desktop_gallery_link',
                        return_value=DesktopLinkedSite(issuer, session, owner.user_id),
                    ) as restore,
                    mock.patch('uploader.qt_photos.read_gallery_tree', return_value=()),
                    mock.patch('uploader.qt_photos.open_saved_upload_session') as rotate,
                    mock.patch('uploader.qt_photos.webbrowser.open') as browser,
                    mock.patch(
                        'uploader.qt_photos.verify_linked_upload_target', return_value=target,
                    ) as verify,
                ):
                    photos.resume_saved_browse()
                    self.wait_for(lambda: photos._session is session and not photos._load.busy())
                    restore.assert_called_once_with(profile, issuer, mock.ANY)
                    verify.assert_not_called()
                    select.assert_not_called()
                    photos.reconnect_uploads()
                    self.assertTrue(photos.busy())
                    photos.cancel_authorization()
                    self.assertTrue(photos.busy())
                    self.wait_for(lambda: not photos.busy() and not photos._load.busy())
                    verify.assert_called_once_with(
                        session, issuer, owner.user_id, ROLLING_UPLOAD_SCOPE,
                        target.gallery_id, mock.ANY,
                    )
                    select.assert_called_once_with(target)
                    rotate.assert_not_called()
                    browser.assert_not_called()
                    session.close.assert_not_called()
            finally:
                window.exit_window()

    def test_browser_browse_persists_only_its_approved_listing_grant(self) -> None:
        """Verify Photos grants and safe browser fallback in the native lane.

        Returns:
            None: The normal grant is persisted, unavailable storage falls back
                without replacing owners, and uncertain rotation fails closed.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example/browse'
            identity = LinkedIdentity(17, 'Gallery Owner')
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            session.can_read_media.return_value = True
            session.can_upload.return_value = False
            session.approved_scope.return_value = ROLLING_GALLERIES_SCOPE
            session.identity.return_value = identity
            attempt = mock.Mock()
            attempt.push_request.return_value.authorization_url = issuer + '/approve'
            attempt.wait_for_callback.return_value = 'approved'
            attempt.requests_offline_access.return_value = True
            refresh = 'synthetic browse refresh'
            attempt.exchange_offline_once.return_value = (
                session, refresh, CALLBACK_LIFETIME_SECONDS,
            )
            select = mock.Mock()
            connections = ConnectionActions(select, mock.Mock(), lambda: None, lambda: None)
            window = QtWindow(profile, '1.7.1', '', 500, False, connections=connections)
            photos = window.photos
            try:
                window.show()
                with (
                    mock.patch('uploader.qt_photos.LinkingAttempt', return_value=attempt),
                    mock.patch('uploader.qt_photos.restore_desktop_gallery_link', return_value=None),
                    mock.patch('uploader.qt_photos.desktop_upload_vault', return_value=mock.sentinel.vault),
                    mock.patch('uploader.qt_photos.read_gallery_tree', return_value=()),
                    mock.patch('uploader.qt_photos.webbrowser.open', return_value=True),
                    mock.patch('uploader.qt_photos.persist_browser_gallery_grant') as store,
                    mock.patch('uploader.qt_photos.persist_browser_upload_grant') as store_upload,
                ):
                    photos._authorize(False, address_override=issuer)
                    self.wait_for(lambda: photos._session is session and not photos._load.busy())
                    self.assertTrue(attempt.push_request.call_args.kwargs['prefer_rolling'])
                    store.assert_called_once_with(
                        profile, mock.sentinel.vault, issuer, identity, session, refresh,
                        mock.ANY, mock.ANY,
                    )
                    store_upload.assert_not_called()
                    select.assert_not_called()

                saved = mock.Mock(spec=ModeRecord)
                saved.mode = 'linked'
                saved.issuer = issuer
                saved.account_id = 23
                saved.gallery_id = 41
                saved.scope = ROLLING_UPLOAD_SCOPE
                writer_session = mock.create_autospec(
                    LinkingSession, instance=True, spec_set=True,
                )
                writer_session.available.return_value = True
                writer_session.can_upload.return_value = True
                writer_session.approved_scope.return_value = ROLLING_UPLOAD_SCOPE
                writer = LinkedUploadTarget(
                    writer_session, issuer, 23, 41, 'Retained writer',
                )
                mode_state: dict[str, object] = {'saved': saved, 'active': writer}
                photos.connections = ConnectionActions(
                    select, mock.Mock(), lambda: mode_state['saved'],
                    lambda: mode_state['active'],
                )
                fallback_issuer = 'http://localhost:8888/galerie'
                binding_path = offline_binding_path(
                    profile / 'offline-link.json', fallback_issuer,
                )
                save_offline_binding(binding_path, OfflineBinding(
                    fallback_issuer, 17, ROLLING_GALLERIES_SCOPE,
                ))
                binding_bytes = binding_path.read_bytes()
                fallback_session = mock.create_autospec(
                    LinkingSession, instance=True, spec_set=True,
                )
                fallback_session.available.return_value = True
                fallback_session.can_read_media.return_value = True
                fallback_session.can_upload.return_value = False
                fallback_session.approved_scope.return_value = GALLERIES_SCOPE
                fallback_session.identity.return_value = LinkedIdentity(
                    29, 'Fallback Gallery Owner',
                )
                fallback_attempt = mock.Mock()
                fallback_attempt.push_request.return_value.authorization_url = (
                    fallback_issuer + '/approve'
                )
                fallback_attempt.wait_for_callback.return_value = 'approved'
                fallback_attempt.requests_offline_access.return_value = False
                fallback_attempt.exchange_once.return_value = fallback_session
                browser_opened = Event()
                continue_browser = Event()
                browser_urls: list[str] = []

                def hold_browser(url: str) -> bool:
                    """Pause browser launch until saved owners are checked.

                    Args:
                        url (str): Exact approval URL submitted by Photos.
                    Returns:
                        bool: True after the test releases the browser step.
                    """
                    browser_urls.append(url)
                    browser_opened.set()
                    return continue_browser.wait(SCENE_WAIT_MS / 1000)

                with (
                    mock.patch(
                        'uploader.qt_photos.LinkingAttempt',
                        return_value=fallback_attempt,
                    ) as attempt_factory,
                    mock.patch(
                        'uploader.qt_photos.restore_desktop_gallery_link',
                        side_effect=CredentialStoreUnavailable(
                            'Saved native credential is unavailable.',
                        ),
                    ) as restore,
                    mock.patch('uploader.qt_photos.desktop_upload_vault') as vault,
                    mock.patch(
                        'uploader.qt_photos.read_gallery_tree', return_value=(),
                    ),
                    mock.patch(
                        'uploader.qt_photos.webbrowser.open', side_effect=hold_browser,
                    ) as browser,
                    mock.patch(
                        'uploader.qt_photos.persist_browser_gallery_grant',
                    ) as persist_browse,
                    mock.patch(
                        'uploader.qt_photos.persist_browser_upload_grant',
                    ) as persist_upload,
                    mock.patch(
                        'uploader.qt_photos.remember_desktop_transient_link',
                    ) as remember_transient,
                ):
                    photos._open_authorization_dialog(False, None, None, False)
                    fallback_dialog = photos._authorization_dialog
                    assert fallback_dialog is not None
                    fallback_address = fallback_dialog.findChild(
                        QLineEdit, 'photosAuthorizationAddress',
                    )
                    fallback_submit = fallback_dialog.findChild(
                        QPushButton, 'photosAuthorizationSubmit',
                    )
                    assert fallback_address is not None and fallback_submit is not None
                    fallback_address.setText(fallback_issuer)
                    fallback_submit.click()
                    self.wait_for(browser_opened.is_set)
                    attempt_factory.assert_called_once_with(
                        fallback_issuer, scope=GALLERIES_SCOPE,
                    )
                    restore.assert_called_once_with(profile, fallback_issuer, mock.ANY)
                    self.assertIs(photos._session, session)
                    self.assertIs(mode_state['saved'], saved)
                    self.assertIs(mode_state['active'], writer)
                    self.assertEqual(binding_bytes, binding_path.read_bytes())
                    continue_browser.set()
                    self.wait_for(
                        lambda: photos._session is fallback_session
                        and not photos._load.busy(),
                    )
                    self.assertFalse(
                        fallback_attempt.push_request.call_args.kwargs['prefer_rolling'],
                    )
                    browser.assert_called_once_with(fallback_issuer + '/approve')
                    self.assertEqual([fallback_issuer + '/approve'], browser_urls)
                    fallback_attempt.exchange_offline_once.assert_not_called()
                    vault.assert_not_called()
                    persist_browse.assert_not_called()
                    persist_upload.assert_not_called()
                    remember_transient.assert_not_called()
                    self.assertIs(mode_state['saved'], saved)
                    self.assertIs(mode_state['active'], writer)
                    self.assertEqual(binding_bytes, binding_path.read_bytes())
                    select.assert_not_called()

                uncertain_issuer = 'http://localhost:8888/galerie/uncertain'
                uncertain_binding_path = offline_binding_path(
                    profile / 'offline-link.json', uncertain_issuer,
                )
                save_offline_binding(uncertain_binding_path, OfflineBinding(
                    uncertain_issuer, 31, ROLLING_GALLERIES_SCOPE,
                ))
                uncertain_binding_bytes = uncertain_binding_path.read_bytes()
                failed_attempt = mock.Mock()
                with (
                    mock.patch(
                        'uploader.qt_photos.LinkingAttempt',
                        return_value=failed_attempt,
                    ) as failed_factory,
                    mock.patch(
                        'uploader.qt_photos.restore_desktop_gallery_link',
                        side_effect=RuntimeError('uncertain saved rotation'),
                    ) as failed_restore,
                    mock.patch('uploader.qt_photos.webbrowser.open') as no_browser,
                    self.assertLogs('uploader.qt_photos', level='WARNING') as failure_log,
                ):
                    photos._open_authorization_dialog(False, None, None, False)
                    failed_dialog = photos._authorization_dialog
                    assert failed_dialog is not None
                    failed_address = failed_dialog.findChild(
                        QLineEdit, 'photosAuthorizationAddress',
                    )
                    failed_submit = failed_dialog.findChild(
                        QPushButton, 'photosAuthorizationSubmit',
                    )
                    failed_status = failed_dialog.findChild(
                        QLabel, 'photosAuthorizationStatus',
                    )
                    assert failed_address is not None and failed_submit is not None
                    assert failed_status is not None
                    failed_address.setText(uncertain_issuer)
                    failed_submit.click()
                    self.wait_for(lambda: not photos._load.busy())
                    failed_factory.assert_called_once_with(
                        uncertain_issuer, scope=GALLERIES_SCOPE,
                    )
                    failed_restore.assert_called_once_with(
                        profile, uncertain_issuer, mock.ANY,
                    )
                    self.assertEqual(
                        'Gallery connection is unavailable.', failed_status.text(),
                    )
                    self.assertEqual(
                        tr('en', 'linking_connect_again'), failed_submit.text(),
                        'An unsuccessful request must retain the browser retry action.',
                    )
                    self.assertTrue(failed_submit.isEnabled())
                    no_browser.assert_not_called()
                    failed_attempt.push_request.assert_not_called()
                    self.assertIn(
                        'step=restore; reason=RuntimeError', ' '.join(failure_log.output),
                    )
                    self.assertNotIn('uncertain saved rotation', ' '.join(failure_log.output))
                    self.assertNotIn(uncertain_issuer, ' '.join(failure_log.output))
                    self.assertIs(photos._session, fallback_session)
                    self.assertIs(mode_state['saved'], saved)
                    self.assertIs(mode_state['active'], writer)
                    self.assertEqual(
                        uncertain_binding_bytes, uncertain_binding_path.read_bytes(),
                    )

                authority = LoopbackLinkingAuthority(
                    admin_photos_edit=True, mount='galerie',
                )
                cancelled_authority = LoopbackLinkingAuthority(
                    admin_photos_edit=True, mount='galerie-cancel',
                )
                credential_store = _MemoryCredentialStore()
                authority_ports = {
                    authority.server.server_port,
                    cancelled_authority.server.server_port,
                }
                native_getaddrinfo = app_linking.socket.getaddrinfo
                consent_threads: list[threading.Thread] = []
                consent_errors: list[str] = []
                approval_urls: list[str] = []

                def resolve_fixture_host(
                    host: str, port: int | str, family: int = 0,
                    socktype: int = 0, proto: int = 0, flags: int = 0,
                ) -> list[tuple[int, int, int, str, tuple[str, int]]]:
                    """Resolve only the disposable authorities to IPv4 loopback.

                    Args:
                        host (str): Requested protocol host.
                        port (int | str): Requested protocol port.
                        family (int): Optional address family constraint.
                        socktype (int): Optional socket type constraint.
                        proto (int): Optional transport protocol constraint.
                        flags (int): Optional resolver flags.
                    Returns:
                        list[tuple[int, int, int, str, tuple[str, int]]]:
                            Native resolver results for this local fixture.
                    """
                    if host == 'localhost' and int(port) in authority_ports:
                        return native_getaddrinfo(
                            '127.0.0.1', port, family, socktype, proto, flags,
                        )
                    return native_getaddrinfo(
                        host, port, family, socktype, proto, flags,
                    )

                def deliver_consent() -> None:
                    """Send one callback and retain only a safe failure class.

                    Returns:
                        None: Delivers the local callback or records its class.
                    """
                    try:
                        authority.grant_consent()
                    except Exception as error:
                        consent_errors.append(type(error).__name__)

                def launch_fixture_approval(url: str) -> bool:
                    """Launch fixture consent asynchronously from the worker.

                    Args:
                        url (str): Browser approval URL supplied by LinkingAttempt.
                    Returns:
                        bool: True after scheduling the local callback sender.
                    """
                    approval_urls.append(url)
                    thread = threading.Thread(
                        target=deliver_consent, daemon=True,
                    )
                    consent_threads.append(thread)
                    thread.start()
                    return True

                expected_scope = (
                    app_linking.READ_SCOPE + ' gallery:visibility galleries:search'
                    + app_linking.ADMIN_PRESENTATION_SCOPE_SUFFIX
                    + app_linking.GALLERY_EDIT_SCOPE_SUFFIX
                    + app_linking.ROLLING_SCOPE_SUFFIX
                )
                try:
                    with (
                        mock.patch.object(
                            app_linking.socket, 'getaddrinfo',
                            side_effect=resolve_fixture_host,
                        ),
                        mock.patch(
                            'uploader.qt_photos.restore_desktop_gallery_link',
                            return_value=None,
                        ),
                        mock.patch(
                            'uploader.qt_photos.desktop_upload_vault',
                            return_value=credential_store,
                        ),
                        mock.patch(
                            'uploader.qt_photos.webbrowser.open',
                            side_effect=launch_fixture_approval,
                        ),
                    ):
                        photos._open_authorization_dialog(False, None, None, False)
                        approval_dialog = photos._authorization_dialog
                        assert approval_dialog is not None
                        approval_address = approval_dialog.findChild(
                            QLineEdit, 'photosAuthorizationAddress',
                        )
                        approval_submit = approval_dialog.findChild(
                            QPushButton, 'photosAuthorizationSubmit',
                        )
                        assert approval_address is not None and approval_submit is not None
                        approval_address.setText(authority.issuer)
                        approval_submit.click()
                        self.wait_for(
                            lambda: photos._identity
                            == (authority.issuer, FIXTURE_ADMIN_ID)
                            and not photos._load.busy(),
                        )
                        for consent_thread in consent_threads:
                            consent_thread.join(FIXTURE_OPERATION_TIMEOUT_SECONDS)
                        self.assertTrue(consent_threads)
                        self.assertEqual(1, len(approval_urls))
                        self.assertTrue(approval_urls[0].startswith(
                            authority.issuer
                            + '/index.php?page=app_linking_start&request_handle=',
                        ))
                        self.assertEqual([], consent_errors)
                        self.assertEqual(expected_scope, authority.scope)
                        self.assertEqual(1, authority.token_exchange_count)
                        self.assertEqual(0, authority.refresh_exchange_count)
                        self.assertIsNone(photos._authorization_dialog)
                        self.assertIsNotNone(photos._session)
                        assert photos._session is not None
                        self.assertTrue(photos._session.available())
                        self.assertTrue(photos._session.can_list_galleries())
                        self.assertTrue(photos._session.can_read_admin_media())
                        self.assertTrue(photos._session.can_edit_gallery_metadata())
                        self.assertFalse(photos._session.can_upload())
                        self.assertEqual(1, photos._gallery_tree.topLevelItemCount())
                        approved_binding = load_offline_binding(
                            offline_binding_path(profile / 'offline-link.json', authority.issuer),
                            authority.issuer,
                        )
                        self.assertEqual(
                            OfflineBinding(
                                authority.issuer, FIXTURE_ADMIN_ID, expected_scope,
                            ),
                            approved_binding,
                        )
                        self.assertIsNotNone(
                            credential_store.read(authority.issuer, FIXTURE_ADMIN_ID),
                        )
                        self.assertIs(mode_state['saved'], saved)
                        self.assertIs(mode_state['active'], writer)
                        select.assert_not_called()

                        approved_path = offline_binding_path(
                            profile / 'offline-link.json', authority.issuer,
                        )
                        approved_bytes = approved_path.read_bytes()
                        approved_credential = credential_store.record
                        approved_session = photos._session

                        browser_entered = Event()
                        release_browser = Event()
                        cancel_browser_urls: list[str] = []

                        def hold_cancelled_browser(url: str) -> bool:
                            """Hold the second browser handoff until cancellation.

                            Args:
                                url (str): Approval URL for the cancellation issuer.
                            Returns:
                                bool: True after the test releases the handoff.
                            """
                            cancel_browser_urls.append(url)
                            browser_entered.set()
                            return release_browser.wait(
                                FIXTURE_CALLBACK_WAIT_SECONDS,
                            )

                        with mock.patch(
                            'uploader.qt_photos.webbrowser.open',
                            side_effect=hold_cancelled_browser,
                        ):
                            photos._open_authorization_dialog(
                                False, None, None, False,
                            )
                            cancel_dialog = photos._authorization_dialog
                            assert cancel_dialog is not None
                            cancel_address = cancel_dialog.findChild(
                                QLineEdit, 'photosAuthorizationAddress',
                            )
                            cancel_submit = cancel_dialog.findChild(
                                QPushButton, 'photosAuthorizationSubmit',
                            )
                            assert cancel_address is not None and cancel_submit is not None
                            cancel_address.setText(cancelled_authority.issuer)
                            cancel_submit.click()
                            try:
                                self.wait_for(browser_entered.is_set)
                                self.assertTrue(photos._authorization_active)
                                photos.cancel_authorization()
                            finally:
                                release_browser.set()
                            self.wait_for(
                                lambda: not photos._authorization_active
                                and not photos._load.busy(),
                            )
                        self.assertIsNone(photos._authorization_dialog)
                        self.assertEqual(1, len(cancel_browser_urls))
                        self.assertTrue(
                            cancel_browser_urls[0].startswith(
                                cancelled_authority.issuer + '/',
                            ),
                        )
                        self.assertEqual(0, cancelled_authority.token_exchange_count)
                        self.assertIs(photos._session, approved_session)
                        self.assertEqual(approved_bytes, approved_path.read_bytes())
                        self.assertEqual(approved_credential, credential_store.record)
                        self.assertIs(mode_state['saved'], saved)
                        self.assertIs(mode_state['active'], writer)
                        select.assert_not_called()
                finally:
                    authority.close()
                    cancelled_authority.close()
            finally:
                window.exit_window()

    def test_linked_header_disconnect_revokes_only_the_selected_bearer(self) -> None:
        """Invoke the actual header unlink action with an active and parked site.

        Returns:
            None: The native operation removes the exact current session and
                retains the parked site independently of the upload mode.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            first, second = 'https://gallery.example/first', 'https://gallery.example/second'
            account_id = 17
            old = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            current = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            old.available.return_value = current.available.return_value = True
            current.approved_scope.return_value = ROLLING_GALLERIES_SCOPE
            select = mock.Mock()
            connections = ConnectionActions(select, mock.Mock(), lambda: None, lambda: None)
            window = QtWindow(profile, '1.7.1', '', 500, False, connections=connections)
            photos = window.photos
            try:
                window.show_page('settings')
                for session, issuer in ((old, first), (current, second)):
                    path = photos_account_tree_path(profile, issuer, account_id)
                    photos._receive_catalogue(LinkedPhotos(
                        session, issuer, account_id, (), load_photos_tree_preferences(path),
                    ))
                self.assertEqual(1, len(photos._parked_links))
                self.assertTrue(window._link_disconnect.isVisible())
                with (
                    mock.patch('uploader.qt_photos.load_transient', return_value=None),
                    mock.patch('uploader.qt_photos.saved_desktop_gallery_binding', return_value=None),
                ):
                    window._link_disconnect.click()
                    self.wait_for(lambda: photos._session is None and not photos.busy())
                current.revoke.assert_called_once_with()
                current.close.assert_called_once_with()
                old.revoke.assert_not_called()
                old.close.assert_not_called()
                self.assertIs(old, photos._parked_links[0].session)
                select.assert_not_called()
            finally:
                window.exit_window()

    def test_header_gallery_choice_filters_the_live_tree_on_a_non_photos_page(self) -> None:
        """Use the linked header button to choose a gallery without upload authority.

        Returns:
            None: The actual native picker filters the current read-only tree,
                records the selected gallery, and retains the active workspace.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example'
            account_id, root_id, child_id = 17, 29, 30
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            session.can_list_galleries.return_value = True
            session.can_upload.return_value = False
            window = QtWindow(profile, '1.7.1', '', 500, False)
            photos = window.photos
            try:
                window.show_page('settings')
                path = photos_account_tree_path(profile, issuer, account_id)
                galleries = (
                    LinkedGallery(root_id, None, 'Gallery Alpha'),
                    LinkedGallery(child_id, None, 'Gallery Beta'),
                )
                with mock.patch.object(photos, '_select_gallery') as select:
                    photos._receive_catalogue(LinkedPhotos(
                        session, issuer, account_id, galleries, load_photos_tree_preferences(path),
                    ))
                    self.assertTrue(window.link_address.isVisible())
                    self.assertEqual('gallery.example', window.link_address.text())
                    self.assertTrue(window._link_choose.isVisible())
                    window._link_choose.click()
                    dialog = photos._gallery_picker
                    self.assertIsNotNone(dialog)
                    self.assertEqual(Qt.WindowModality.WindowModal, dialog.windowModality())
                    search = dialog.findChild(QLineEdit, 'linkedGallerySearch')
                    choices = dialog.findChild(QTreeView, 'linkedGalleryChoices')
                    button = dialog.findChild(QPushButton, 'linkedGalleryChoose')
                    search.setText('Gallery Beta')
                    model = choices.model()
                    self.assertEqual(1, model.rowCount())
                    choices.setCurrentIndex(model.index(0, 0))
                    button.click()
                    select.assert_called_with(child_id)
                    self.assertIs(window.settings, window.tabs.currentWidget())
                    self.assertTrue(dialog.isVisible())
                    dialog.reject()
                    self.assertIsNone(photos._gallery_picker)
            finally:
                window.exit_window()

    def test_gallery_picker_recovery_cancel_and_observation_do_not_select_or_upload(self) -> None:
        """Use the real picker for bounded uncertain-source observation only.

        Returns:
            None: Cancel makes no owner call; observation uses the exact current
                target and never changes selection or resends the source.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer, account_id, gallery_id = 'https://gallery.example', 17, 29
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            session.can_list_galleries.return_value = True
            session.can_upload.return_value = True
            target = LinkedUploadTarget(session, issuer, account_id, gallery_id, 'Gallery Alpha')
            select = mock.Mock()
            probe = mock.Mock(return_value=('unknown', 'exact-journal-marker'))
            is_current = mock.Mock(return_value=True)
            review = mock.Mock(return_value='exact-journal-marker')
            transfer = [False]
            connections = ConnectionActions(
                select, mock.Mock(), mock.Mock(), lambda: target,
                recovery=LinkedRecoveryActions(probe, is_current, review),
            )
            window = QtWindow(profile, '1.7.1', '', 500, False, connections=connections)
            photos = window.photos
            try:
                window.show()
                path = photos_account_tree_path(profile, issuer, account_id)
                photos._receive_catalogue(LinkedPhotos(
                    session, issuer, account_id,
                    (LinkedGallery(gallery_id, None, target.title),),
                    load_photos_tree_preferences(path),
                ))
                photos.open_gallery_picker()
                dialog = photos._gallery_picker
                self.assertIsNotNone(dialog)
                check = dialog.findChild(QPushButton, 'linkedRecoveryCheck')
                self.assertIsNotNone(check)
                self.assertTrue(check.isVisible())
                photos.transfer_busy = lambda: transfer[0]
                with mock.patch(
                    'uploader.qt_photos.QInputDialog.getInt',
                    return_value=(0, False),
                ) as prompt:
                    check.click()
                    prompt.assert_called_once()
                probe.assert_not_called()
                review.assert_not_called()

                def accept_after_transfer_starts(
                    parent: QDialog, title: str, label: str, *,
                    value: int, minValue: int, maxValue: int,
                ) -> tuple[int, bool]:
                    """Expose a transfer admission during the native size prompt.

                    Args:
                        parent (QDialog): Current picker passed to the prompt.
                        title (str): Localized prompt title, unused.
                        label (str): Localized size guidance, unused.
                        value (int): Initial prompt value.
                        minValue (int): Bounded minimum accepted size.
                        maxValue (int): Bounded maximum accepted size.
                    Returns:
                        tuple[int, bool]: One accepted test size after the fence moves.
                    """
                    del parent, title, label, value, minValue, maxValue
                    transfer[0] = True
                    return RECOVERY_FIXTURE_FILE_SIZE_BYTES, True

                with mock.patch(
                    'uploader.qt_photos.QInputDialog.getInt',
                    side_effect=accept_after_transfer_starts,
                ):
                    check.click()
                self.assertTrue(transfer[0])
                probe.assert_not_called()
                transfer[0] = False
                with mock.patch(
                    'uploader.qt_photos.QInputDialog.getInt',
                    return_value=(RECOVERY_FIXTURE_FILE_SIZE_BYTES, True),
                ):
                    check.click()
                self.wait_for(lambda: (
                    not photos.busy()
                    and 'observation is unknown' in photos._gallery_picker_feedback.text()
                ))
                probe.assert_called_once_with(target, RECOVERY_FIXTURE_FILE_SIZE_BYTES)
                is_current.assert_called_with(target, 'exact-journal-marker')
                review.assert_not_called()
                select.assert_not_called()
                self.assertIs(target, connections.active())
                self.assertEqual(gallery_id, target.gallery_id)
            finally:
                window.exit_window()

    def test_gallery_picker_review_requires_default_no_then_exact_marker_confirmation(self) -> None:
        """Record only explicit review of the still-current linked marker.

        Returns:
            None: No leaves the marker untouched; Yes records that exact marker
                while retaining the original upload as unresolved and unsent.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer, account_id, gallery_id = 'https://gallery.example', 17, 29
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            session.can_list_galleries.return_value = True
            session.can_upload.return_value = True
            target = LinkedUploadTarget(session, issuer, account_id, gallery_id, 'Gallery Alpha')
            select = mock.Mock()
            probe = mock.Mock()
            marker = 'exact-uncertain-journal-marker'
            review = mock.Mock(side_effect=lambda _target, value: marker if value in (None, marker) else None)
            is_current = mock.Mock(return_value=True)
            connections = ConnectionActions(
                select, mock.Mock(), mock.Mock(), lambda: target,
                recovery=LinkedRecoveryActions(probe, is_current, review),
            )
            window = QtWindow(profile, '1.7.1', '', 500, False, connections=connections)
            photos = window.photos
            try:
                window.show()
                path = photos_account_tree_path(profile, issuer, account_id)
                photos._receive_catalogue(LinkedPhotos(
                    session, issuer, account_id,
                    (LinkedGallery(gallery_id, None, target.title),),
                    load_photos_tree_preferences(path),
                ))
                photos.open_gallery_picker()
                dialog = photos._gallery_picker
                self.assertIsNotNone(dialog)
                review_button = dialog.findChild(QPushButton, 'linkedRecoveryReview')
                self.assertIsNotNone(review_button)

                with mock.patch.object(
                    QMessageBox, 'question', return_value=QMessageBox.StandardButton.No,
                ) as confirm:
                    review_button.click()
                self.assertEqual(
                    QMessageBox.StandardButton.No, confirm.call_args.args[-1],
                )
                self.assertEqual([mock.call(target, None)], review.call_args_list)

                with mock.patch.object(
                    QMessageBox, 'question', return_value=QMessageBox.StandardButton.Yes,
                ) as confirm:
                    review_button.click()
                self.assertEqual(
                    QMessageBox.StandardButton.No, confirm.call_args.args[-1],
                )
                self.assertEqual(
                    [mock.call(target, None), mock.call(target, None), mock.call(target, marker)],
                    review.call_args_list,
                )
                is_current.assert_not_called()
                self.assertIn('original remains uncertain', photos._gallery_picker_feedback.text())
                self.assertIs(target, connections.active())
                self.assertEqual(gallery_id, target.gallery_id)
                probe.assert_not_called()
                select.assert_not_called()
            finally:
                window.exit_window()

    def test_gallery_picker_recovery_refuses_a_target_replaced_while_open(self) -> None:
        """Block both recovery actions after the selected writer target changes.

        Returns:
            None: A chooser retaining the earlier target cannot probe or review
                its journal once another gallery becomes authoritative.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer, account_id = 'https://gallery.example', 17
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            session.can_list_galleries.return_value = True
            session.can_upload.return_value = True
            original = LinkedUploadTarget(session, issuer, account_id, 29, 'Gallery Alpha')
            replacement = LinkedUploadTarget(session, issuer, account_id, 30, 'Gallery Beta')
            active_target = [original]
            probe, is_current, review = mock.Mock(), mock.Mock(return_value=True), mock.Mock()
            select = mock.Mock()
            connections = ConnectionActions(
                select, mock.Mock(), mock.Mock(), lambda: active_target[0],
                recovery=LinkedRecoveryActions(probe, is_current, review),
            )
            window = QtWindow(profile, '1.7.1', '', 500, False, connections=connections)
            photos = window.photos
            try:
                window.show()
                path = photos_account_tree_path(profile, issuer, account_id)
                photos._receive_catalogue(LinkedPhotos(
                    session, issuer, account_id,
                    (LinkedGallery(29, None, original.title),),
                    load_photos_tree_preferences(path),
                ))
                photos.open_gallery_picker()
                dialog = photos._gallery_picker
                self.assertIsNotNone(dialog)
                check = dialog.findChild(QPushButton, 'linkedRecoveryCheck')
                review_button = dialog.findChild(QPushButton, 'linkedRecoveryReview')
                active_target[0] = replacement
                with (
                    mock.patch('uploader.qt_photos.QInputDialog.getInt') as prompt,
                    mock.patch.object(QMessageBox, 'question') as confirm,
                ):
                    check.click()
                    review_button.click()
                prompt.assert_not_called()
                confirm.assert_not_called()
                probe.assert_not_called()
                is_current.assert_not_called()
                review.assert_not_called()
                select.assert_not_called()
                self.assertIs(replacement, connections.active())
            finally:
                window.exit_window()

    def test_viewport_reuses_decoded_frames_without_retaining_old_scene_slots(self) -> None:
        """Replay a warm viewport through the real retained Qt scene.

        Returns:
            None: Reuses decoded data and withdraws QML URLs before retiring slots.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            photo = profile / "synthetic.jpg"
            with Image.new("RGB", (144, 108), "green") as fixture:
                fixture.save(photo)
            window = QtWindow(profile, "1.7.1", "", 500, False)
            photos = window.photos
            provider = photos.textures
            discard = provider.discard
            retired: list[str] = []
            dangling: list[str] = []
            warnings: list[str] = []

            def observe_retirement(key: str) -> None:
                """Record any advertised source at the actual provider retirement.

                Args:
                    key (str): Current logical provider key about to be retired.
                Returns:
                    None: Records the ordering and releases the real provider slot.
                """
                retired.append(key)
                if f"image://photos/{key}" in photos.model.texture_urls.values():
                    dangling.append(key)
                discard(key)

            def observe_warnings(messages: list[QQmlError]) -> None:
                """Retain an image-provider failure from the native Qt Quick engine.

                Args:
                    messages (list[QQmlError]): QML diagnostics from this fixture.
                Returns:
                    None: Collects only local thumbnail lookup failures.
                """
                for message in messages:
                    text = message.description()
                    if "Failed to get image from provider: image://photos/tile/" in text:
                        warnings.append(text)

            photos.quick.engine().warnings.connect(observe_warnings)
            with mock.patch.object(provider, 'discard', side_effect=observe_retirement):
                try:
                    window.show()
                    # One hundred logical entries span multiple viewport positions.
                    photos.open_files((photo,) * 100)
                    self.wait_for(lambda: bool(photos.model.texture_urls))
                    first = photos._wanted[0]
                    self.wait_for(lambda: first in photos.model.texture_urls)
                    key = photos.model.texture_urls[first].removeprefix("image://photos/")
                    self.assertFalse(provider.requestImage(key, QSize(), QSize()).isNull())
                    retained = photos._retained[first][0]
                    photos.scrollTo.emit(90)
                    self.wait_for(lambda: first not in photos.model.texture_urls)
                    self.assertIn(key, retired)
                    self.assertTrue(provider.requestImage(key, QSize(), QSize()).isNull())
                    self.assertIs(retained, photos._retained[first][0])
                    photos.scrollTo.emit(0)
                    self.wait_for(lambda: first in photos.model.texture_urls)
                    self.assertFalse(provider.requestImage(key, QSize(), QSize()).isNull())
                    self.assertIs(retained, photos._retained[first][0])
                    photos.refresh()
                    self.assertNotIn(first, photos._retained)
                    self.assertTrue(provider.requestImage(key, QSize(), QSize()).isNull())
                    self.assertEqual([], dangling)
                    self.assertEqual([], warnings)
                finally:
                    window.exit_window()

    def test_warm_viewport_texture_changes_coalesce_only_contiguous_rows(self) -> None:
        """Verify a warm batch reports its exact changes through Qt model roles.

        Returns:
            None: Adjacent changes share one notification. Unchanged rows and
                unknown IDs never emit a notification.
        """
        model = PhotoListModel()
        source = Path("fixture.jpg")
        model.replace(tuple(PhotoRow(identity, source.name, source) for identity in range(4)))
        model.texture(2, "unchanged")
        ranges: list[tuple[int, int, list[int]]] = []

        def observe(first: QModelIndex, last: QModelIndex, roles: list[int]) -> None:
            """Keep the exact model range consumed by a Qt Quick delegate.

            Args:
                first (QModelIndex): First affected row of the role update.
                last (QModelIndex): Last affected row, inclusive.
                roles (list[int]): Qt role identifiers changed by this event.
            Returns:
                None: Appends the notification to the assertion input.
            """
            ranges.append((first.row(), last.row(), roles))

        model.dataChanged.connect(observe)
        model.update_textures({3: "last", 1: "second", 2: "unchanged", 0: "first", 99: "foreign"})
        self.assertEqual([(0, 1), (3, 3)], [(first, last) for first, last, _roles in ranges])
        self.assertEqual(ranges[0][2], ranges[1][2])
        model.update_textures({1: "second", 0: "first"})
        self.assertEqual({0: "first", 1: "second", 2: "unchanged", 3: "last"}, model.texture_urls)
        self.assertEqual(2, len(ranges))

    def test_thumbnail_proof_must_still_hold_after_qt_decode(self) -> None:
        """Reject a revoked proof before the decoded pixels can enter Qt.

        Returns:
            None: Delivers only an authorized body-free publication to the GUI.
        """
        buffer = BytesIO()
        with Image.new("RGB", (144, 108), "blue") as fixture:
            fixture.save(buffer, format="PNG")
        session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
        photo = LinkedPhoto(11, "Synthetic thumbnail")
        row = PhotoRow(photo.image_id, photo.title, photo)
        witness = ThumbnailBatchWitness(
            session, "synthetic", 9, photo, "public", ('"fixture"', time.monotonic()), None, 0,
        )
        publication = ThumbnailPublication(
            witness, photo, "ok", buffer.getvalue(), "image/png", '"fixture"', True,
        )

        def deliver(
            _gallery: int, _photos: tuple[LinkedPhoto, ...],
            publish: Callable[[ThumbnailPublication, MediaReadTimings, Callable[[], None]], None],
            _cancelled: Event, *, active_photos: Callable[[tuple[int, ...]], None] | None = None,
        ) -> None:
            """Release a fixture result at the shared publication boundary.

            Args:
                _gallery (int): Fixture gallery ID.
                _photos (tuple[LinkedPhoto, ...]): Current exact request rows.
                publish (Callable[[ThumbnailPublication, MediaReadTimings, Callable[[], None]], None]):
                    Decoder callback for the complete result and active check.
                _cancelled (Event): Current request's existing cancellation flag.
                active_photos (Callable[[tuple[int, ...]], None] | None): Optional
                    thumbnail activity observer outside this proof fixture.
            Returns:
                None: Publishes one result under the mocked authorization state.
            """
            del active_photos
            publish(publication, MediaReadTimings(), lambda: None)

        session.media_thumbnails.side_effect = deliver
        values: list[PhotoFrame | ThumbnailActivity | ThumbnailGroup] = []
        session.thumbnail_publication_current.return_value = False
        stream_thumbnails((row,), session, 9, Event(), values.append)
        self.assertEqual([
            ThumbnailActivity((row.identity,)), ThumbnailActivity(()), ThumbnailGroup(False),
        ], values)
        values.clear()
        session.thumbnail_publication_current.return_value = True
        stream_thumbnails((row,), session, 9, Event(), values.append)
        self.assertEqual(ThumbnailActivity((row.identity,)), values[0])
        self.assertIsInstance(values[1], PhotoFrame)
        self.assertIsNone(values[1].publication.body)


class QtWidePhotosViewportTests(_NativeQtAppFixture):
    """Qualify the large native photo surface in its own bounded Qt process."""

    def test_wide_21_9_viewport_fills_all_visible_cards(self) -> None:
        """Exercise large linked viewport geometry and exact active arrow roles.

        Returns:
            None: Visible top, middle and bottom rows all receive textures.
                Rotation starts for one admitted group, shrinks per frame,
                and stops on the inactive workspace and after loading.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            issuer = 'https://gallery.example.test/'
            tree = photos_account_tree_path(profile, issuer, 1)
            gallery_id = 1
            self.assertTrue(save_gallery_card_format(
                tree.with_name(tree.stem + '.view.json'), gallery_id, '21:9',
            ))
            session = mock.create_autospec(LinkingSession, instance=True, spec_set=True)
            session.available.return_value = True
            rows = tuple(PhotoRow(index, str(index), LinkedPhoto(index, str(index)))
                         for index in range(1, WIDE_21_9_PHOTO_COUNT + 1))
            window = QtWindow(profile, '1.7.1', '', 500, False)
            photos = window.photos
            photos.quick.setFixedSize(*WIDE_21_9_VIEWPORT)
            scene = photos.quick.rootObject()
            grid = scene.findChild(QObject, 'photosGrid')
            self.assertIsNotNone(grid)
            read_first = Event()
            finish_first = Event()
            hold_cancel = Event()
            finish_cancel = Event()
            first_ids: tuple[int, ...] = ()
            image = QImage(photos.tileWidth, photos.tileHeight, QImage.Format.Format_RGB32)
            image.fill(QColor(Qt.GlobalColor.green))
            source_color = image.pixelColor(0, 0)
            artifact_directory = os.environ.get('PHP_GALLERY_QT_ARTIFACT_DIR')
            evidence = Path(artifact_directory) if artifact_directory else None
            if evidence is not None:
                evidence.mkdir(parents=True, exist_ok=True)

            def active_arrow_ids() -> set[int]:
                """Observe the actual loading artwork in the mounted Quick delegates.

                Returns:
                    set[int]: Visible arrow identities in the current model order.
                """
                result: set[int] = set()
                for tile in grid.property('contentItem').childItems():
                    if tile.objectName() != 'photoTile' or tile.property('pooled'):
                        continue
                    arrow = next((child for child in tile.childItems()
                                  if child.objectName() == 'photoThumbnailLoading'), None)
                    index = tile.property('index')
                    if arrow is not None and arrow.property('visible') and 0 <= index < len(photos.model.rows):
                        result.add(photos.model.rows[index].identity)
                return result

            def stream(
                group: tuple[PhotoRow, ...], _session: LinkingSession | None,
                _gallery_id: int | None, cancelled: Event,
                publish: Callable[[PhotoFrame | ThumbnailActivity | ThumbnailGroup], None],
            ) -> None:
                """Hold the first admitted group while the native scene renders.

                Args:
                    group (tuple[PhotoRow, ...]): Exact admitted synthetic thumbnails.
                    _session (LinkingSession | None): Fixture's read-only session.
                    _gallery_id (int | None): Fixture catalogue owner.
                    cancelled (Event): Existing request generation cancellation.
                    publish (Callable[[PhotoFrame | ThumbnailActivity | ThumbnailGroup], None]):
                        Real Qt worker receiver.
                Returns:
                    None: Publishes current synthetic pixels in the admitted order.
                """
                nonlocal first_ids
                admitted = tuple(row.identity for row in group)
                held = not first_ids
                if held:
                    first_ids = admitted
                publish(ThumbnailActivity(admitted))
                if hold_cancel.is_set() and not finish_cancel.wait(SCENE_WAIT_MS / 1000):
                    raise RuntimeError('The native fixture did not release cancelled thumbnail work.')
                if held and not read_first.wait(SCENE_WAIT_MS / 1000):
                    raise RuntimeError('The native fixture did not release the first thumbnail.')
                for index, row in enumerate(group):
                    if cancelled.is_set():
                        return
                    publish(PhotoFrame(row, image, image.size()))
                    if held and index == 0 and not finish_first.wait(SCENE_WAIT_MS / 1000):
                        raise RuntimeError('The native fixture did not release the thumbnail group.')
                publish(ThumbnailActivity(()))
                publish(ThumbnailGroup(True))

            with (mock.patch('uploader.qt_photos.read_gallery_photos', return_value=rows),
                  mock.patch('uploader.qt_photos.stream_thumbnails', side_effect=stream)):
                try:
                    window.show()
                    photos._receive_catalogue(LinkedPhotos(
                        session, issuer, 1, (LinkedGallery(gallery_id, None, 'Wide gallery'),),
                        load_photos_tree_preferences(tree),
                    ))
                    self.wait_for(lambda: bool(first_ids) and photos.model.thumbnailsLoading)
                    self.assertEqual(MEDIA_HTTP_BATCH_INITIAL_SIZE, len(first_ids))
                    self.assertEqual(set(first_ids), photos.model.loading_ids)
                    self.assertGreater(len(photos._wanted), len(first_ids))
                    heading = photos._center_toolbar
                    final_ratio = photos._format_buttons['9:16']
                    self.assertTrue(final_ratio.isVisibleTo(heading))
                    ratio_end = final_ratio.mapTo(heading, QPoint(final_ratio.width(), 0)).x()
                    gap = photos._gallery_heading_actions.x() - ratio_end
                    self.assertGreaterEqual(gap, 0)
                    # The Tk title consumes spare width. Leave only the existing
                    # heading and format gutters between the two action groups.
                    self.assertLessEqual(
                        gap, heading.layout().horizontalSpacing() + photos._format_row.layout().spacing()
                        + photos._format_actions.layout().horizontalSpacing() * 2,
                    )
                    self.wait_for(lambda: scene.property('loadingAngle') > 0
                                  and active_arrow_ids() == set(first_ids))
                    if evidence is not None:
                        self.assertTrue(photos.quick.grabFramebuffer().save(
                            str(evidence / 'wide-21-9-loading.png'),
                        ))
                    read_first.set()
                    self.wait_for(lambda: first_ids[0] in photos.model.texture_urls)
                    self.assertEqual(set(first_ids[1:]), photos.model.loading_ids)
                    self.wait_for(lambda: active_arrow_ids() == set(first_ids[1:]))
                    finish_first.set()

                    self.wait_for(lambda: float(grid.property('contentHeight')) > float(grid.property('height')))
                    visible_order = tuple(row.identity for row in photos.model.rows)
                    self.assertCountEqual((row.identity for row in rows), visible_order)
                    stride = float(grid.property('cellHeight'))
                    columns = floor(float(grid.property('width')) / float(grid.property('cellWidth')))
                    self.assertEqual(WIDE_21_9_COLUMNS, columns)
                    height = float(grid.property('height'))
                    travel = float(grid.property('contentHeight')) - height
                    screenshot = QImage()
                    for position in (0.0, travel / 2, travel):  # Top, midpoint and end.
                        origin = float(grid.property('originY'))
                        self.assertTrue(grid.setProperty('contentY', origin + position))
                        self.wait_for(lambda: not photos._viewport_timer.isActive() and not photos._thumbnails.busy())
                        offset = float(grid.property('contentY')) - float(grid.property('originY'))
                        # A native view can align the requested offset to the nearest logical pixel.
                        self.assertAlmostEqual(position, offset, delta=1.0)
                        first = floor(offset / stride) * columns
                        after = ceil((offset + height) / stride) * columns
                        visible = set(visible_order[first:after])
                        # The last row can be incomplete and the first can be clipped.
                        self.assertGreaterEqual(len(visible), columns * (floor(height / stride) - 1))
                        rendered = set(photos.model.texture_urls)
                        self.assertEqual(visible, rendered, (
                            f'offset={offset}; requested={photos._requested}; '
                            f'missing={len(visible - rendered)}; departed={len(rendered - visible)}'
                        ))
                        self.assertFalse(photos.model.thumbnailsLoading)
                        # Check every card center inside the clip against the
                        # fixture raster after the scene paints its current page.
                        cell_width = float(grid.property('cellWidth'))
                        left = float(grid.property('x'))
                        top = float(grid.property('y'))
                        centers = tuple(
                            (left + index % columns * cell_width + photos.tileWidth / 2,
                             top + index // columns * stride - offset + photos.tileHeight / 2)
                            for index in range(first, min(after, len(visible_order)))
                            if 0 <= index // columns * stride - offset + photos.tileHeight / 2 < height
                        )
                        self.assertTrue(centers)
                        sampled_colors: tuple[str, ...] = ()

                        def visible_cards_painted() -> bool:
                            """Read the current frame and verify all visible photo centers.

                            Returns:
                                bool: The latest actual frame contains each current card.
                            """
                            nonlocal screenshot, sampled_colors
                            screenshot = photos.quick.grabFramebuffer()
                            if screenshot.isNull():
                                return False
                            horizontal_scale = screenshot.width() / photos.quick.width()
                            vertical_scale = screenshot.height() / photos.quick.height()
                            sampled_colors = tuple(
                                screenshot.pixelColor(int(x * horizontal_scale), int(y * vertical_scale)).name()
                                for x, y in centers
                            )
                            return all(color == source_color.name() for color in sampled_colors)

                        try:
                            self.wait_for(visible_cards_painted)
                        except AssertionError:
                            if evidence is not None and not screenshot.isNull():
                                screenshot.save(str(evidence / 'wide-21-9-paint-failure.png'))
                            tiles = [tile for tile in grid.property('contentItem').childItems()
                                     if tile.objectName() == 'photoTile' and not tile.property('pooled')]
                            first_row = []
                            for tile in tiles[:columns]:
                                raster = tile.findChild(QObject, 'photoThumbnail')
                                first_row.append((tile.property('index'), tile.property('y'),
                                                  None if raster is None else raster.property('status')))
                            self.fail(
                                f'Wide frame did not paint its cards at offset {offset}; '
                                f'grid={grid.property("width")}×{height}; assigned={len(rendered)}; '
                                f'mounted={len(tiles)}; first mounted (index, y, status)={first_row}; '
                                f'expected={source_color.name()}; first samples={sampled_colors[:columns]}.',
                            )
                    if evidence is not None:
                        self.assertTrue(screenshot.save(str(evidence / 'wide-21-9-bottom.png')))
                    hold_cancel.set()
                    grid.setProperty('contentY', float(grid.property('originY')))
                    self.wait_for(lambda: photos.model.thumbnailsLoading)
                    window.show_page('activity')
                    self.assertFalse(photos.model.thumbnailsLoading)
                finally:
                    read_first.set()
                    finish_first.set()
                    finish_cancel.set()
                    window.exit_window()
