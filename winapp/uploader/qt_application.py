# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_application.py
# Module Type: Qt desktop composition
# Purpose: Run the Qt WinApp frontend in one supervised event loop.
# Responsibilities: Compose the shell, navigation, tray, layout, settings,
#   supported workspaces and sanitized startup/readiness diagnostics.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Own Qt presentation and delegate profile operations to their shared owners."""

from contextlib import contextmanager
from dataclasses import replace
import logging
import os
from pathlib import Path
import platform
import sys
from threading import Event
from typing import Callable, Iterator

from PySide6.QtCore import (
    QMessageLogContext, QObject, QtMsgType, QSize, Qt, QTimer, QUrl, Slot,
    qInstallMessageHandler, qVersion,
)
from PySide6.QtGui import (
    QAction, QCloseEvent, QDesktopServices, QIcon, QKeySequence, QMoveEvent, QResizeEvent,
)
from PySide6.QtQuick import QQuickWindow
from PySide6.QtWidgets import (
    QApplication, QButtonGroup, QDialog, QFileDialog, QFrame, QHBoxLayout, QLabel,
    QMainWindow, QMenu, QMessageBox, QPlainTextEdit, QScrollArea, QSizePolicy,
    QSystemTrayIcon, QTabWidget, QVBoxLayout, QWidget,
)

from .capture_session import CaptureCommands
from .config import load_ui_language
from .desktop_connection import ConnectionActions, DependencyActions, RuntimeSettings
from .import_commands import ImportActions
from .flight_report_translations import tr
from .game_presence import GamePresence
from .localization import configure_language, current_language, gettext
from .models import ActivityEvent
from .qt_ai import AiWorkerFactory, QtAiWorker
from .qt_capture import QtCapture
from .qt_flight_reports import QtFlightReports
from .qt_import import QtImport
from .qt_operator_views import QtOperatorViews
from .qt_photos import QtPhotos
from .qt_read_lane import QtReadLane
from .qt_settings import QtSettings
from .qt_shell import QtAppHeader
from .qt_theme import ActionButton, apply_qt_theme, qt_icon
from .qt_update import QtUpdater
from .state_store import ActivityWriter, JobStateStore
from .support_report import build_support_report, redact_support_text, save_support_report
from .ui_layout import (
    HEADER_WORKSPACE_GAP, PHOTOS_TREE_WIDTH, SHELL_ICON_BUTTON_EDGE, SHELL_INSETS, SHELL_RAIL_GAP,
    UI_LAYOUT_SAVE_DELAY_MS, WATCHER_HEADER_METRIC_MIN_CELLS,
    WATCHER_WINDOW_GEOMETRY, WATCHER_WINDOW_MINIMUM,
    ViewLayoutState, _clamp_ui_layout_state, _read_ui_layout, _write_ui_layout,
)
from .ui_palette import PALETTE
from .windows_startup import ACTIVATION_POLL_MS

_ICON_PATH = Path(__file__).resolve().parents[1] / "assets" / "tray-icon.png"

QT_WINDOW_MINIMUM = WATCHER_WINDOW_MINIMUM
# Purpose: Match the header artwork footprint in Tk.
# Type: int. Units: logical pixels. Scope: Qt header glyph slots.
# Consumers: QtWindow header construction and updates. Rationale: 16 matches Tk.
SHELL_HEADER_ICON_SIZE = 16
# Purpose: Match the small activity-monitor light in the reference header.
# Type: int. Units: logical pixels. Scope: Qt header status marker.
# Consumers: QtWindow._build_header. Rationale: 12 px matches Tk's dot.
SHELL_MONITOR_ICON_SIZE = 12
# Purpose: Preserve the Tk monitor canvas's outer header footprint.
# Type: int. Units: logical pixels. Scope: Qt header monitor slot.
# Consumers: QtWindow._build_header. Rationale: the 12 px dot sits in a 16 px cell.
SHELL_MONITOR_SLOT_EDGE = 16
# Purpose: Match the reference Tk brand glyph on platforms whose Qt point
# rendering is smaller at the same nominal font size.
# Type: int. Units: points. Scope: Qt header brand only.
# Consumers: QtWindow._build_header. Rationale: 24 pt matches the 18 pt Tk
# brand's measured desktop glyph height on the hosted macOS parity capture.
SHELL_BRAND_POINTS = 24
# Purpose: Match Tk's semantic text glyph size in the compact navigation rail.
# Type: int. Units: points. Scope: Qt workspace glyphs.
# Consumers: QtWindow.__init__. Rationale: the reference rail uses 12 pt glyphs.
SHELL_RAIL_GLYPH_POINTS = 12
# Purpose: Preserve Tk's shared glyph raster where the theme draws that symbol.
# Type: int. Units: logical pixels. Scope: Qt workspace raster icons.
# Consumers: QtWindow.__init__. Rationale: 20 is the common native icon edge.
SHELL_RAIL_ART_SIZE = 20
# Purpose: Match Tk's small identity text in the Qt header.
# Type: int. Units: points. Scope: Qt tagline and version labels.
# Consumers: QtWindow._build_header. Rationale: the native Qt fallback renders
# the shared 8 pt token smaller on macOS, so 9 pt matches the Tk screenshot.
SHELL_HEADER_EYEBROW_POINTS = 9
# Purpose: Preserve the reference footer's compact but readable card insets.
# Type: tuple[int, int, int, int]. Units: logical pixels, left/top/right/bottom.
# Scope: latest-activity footer. Consumers: QtWindow._build_footer.
# Rationale: Tk uses 12 px horizontal and 8 px vertical padding.
SHELL_FOOTER_INSETS = (12, 8, 12, 8)
# Purpose: Retain the reference history trigger in the latest-activity strip.
# Type: int. Units: logical pixels. Scope: Qt footer history action.
# Consumers: QtWindow._build_footer. Rationale: 24 with 16 pixels of padding
# matches the 40-pixel Tk card.
SHELL_FOOTER_ACTION_EDGE = 24
# Purpose: Match the compact Tk gutter inside retained header and footer badges.
# Type: tuple[int, int, int, int]. Units: logical pixels. Scope: metric layout.
# Consumers: QtWindow. Rationale: Shared card footprint and vertical rhythm.
SHELL_BADGE_INSETS = (8, 4, 8, 4)
# Purpose: Flush pending recent Activity records before releasing the profile.
# Type: float. Units: seconds. Scope: final Qt shell shutdown.
# Consumers: QtWindow.shutdown.
# Rationale: A one-second bound covers the local writer's 50 ms batch window.
QT_ACTIVITY_FLUSH_SECONDS = 1.0
# Purpose: Coalesce visible Activity reloads while workers continuously publish.
# Type: int. Units: milliseconds. Scope: one visible Activity refresh batch.
# Consumers: QtWindow._record_activity. Rationale: Five seconds keeps the
# visible history current while bounding repeated log and history reads.
QT_ACTIVITY_REFRESH_MS = 5000
# Purpose: Show connection or SimBrief feedback outside its owning workspace.
# Type: int. Units: milliseconds. Scope: existing Qt latest-activity footer.
# Consumers: QtWindow._show_operation_status.
# Rationale: Five seconds lets the operator read the result without replacing history.
DISPATCH_STATUS_DURATION_MS = 5000
# Purpose: Keep native Qt diagnostics bounded before the shared rotating logger.
# Type: int. Units: characters. Scope: One Qt message line.
# Consumers: _qt_log_message. Rationale: Startup diagnostics must be concise.
QT_LOG_MESSAGE_CHARS = 800
_QT_LOG_LEVELS = {
    QtMsgType.QtDebugMsg: logging.DEBUG,
    QtMsgType.QtInfoMsg: logging.INFO,
    QtMsgType.QtWarningMsg: logging.WARNING,
    QtMsgType.QtCriticalMsg: logging.ERROR,
    QtMsgType.QtFatalMsg: logging.CRITICAL,
}


def _qt_log_message(
    message_type: QtMsgType, context: QMessageLogContext, message: str,
) -> None:
    """Send bounded, redacted Qt runtime messages to the configured Python log.

    Args:
        message_type (QtMsgType): Native Qt severity for the diagnostic.
        context (QMessageLogContext): Native category metadata.
        message (str): Qt diagnostic text to sanitize before persistence.
    Returns:
        None: Writes the message without forwarding it to stderr.
    """
    logger = logging.getLogger("qt")
    level = _QT_LOG_LEVELS.get(message_type, logging.WARNING)
    if not logger.isEnabledFor(level):
        return
    category = context.category or "default"
    entry = redact_support_text(f"Qt [{category}]: {message}")
    logger.log(level, "%s", " ".join(entry.split())[:QT_LOG_MESSAGE_CHARS])


@contextmanager
def _qt_log_capture() -> Iterator[None]:
    """Install Qt-to-Python logging for one runtime and restore its predecessor.

    Returns:
        Iterator[None]: Context manager that captures native Qt diagnostics
            until the wrapped runtime exits.

    Yields:
        None: Runs code with bounded Qt messages routed to the configured logger.
    """
    previous_handler = qInstallMessageHandler(_qt_log_message)
    try:
        yield
    finally:
        qInstallMessageHandler(previous_handler)


class QtWindow(QMainWindow):
    """Own the visible Qt shell and its restart and tray lifecycle."""

    def __init__(
        self, profile: Path, version: str, gallery_address: str, history_limit: int,
        close_to_tray: bool, *, ai_factory: AiWorkerFactory | None = None,
        redact_ai: Callable[[str], str] | None = None,
        import_actions: ImportActions | None = None,
        capture: CaptureCommands | None = None, recoverable_capture: bool = False,
        runtime_settings: RuntimeSettings | None = None, connections: ConnectionActions | None = None,
        dependencies: DependencyActions | None = None,
    ) -> None:
        """Compose native pages and restore shared window geometry.

        Args:
            profile (Path): Profile owned for the complete desktop process.
            version (str): Current WinApp application version.
            gallery_address (str): Saved non-secret URL suggested by Photos.
            history_limit (int): Shared bound for recent Activity records.
            close_to_tray (bool): Saved preference for hiding on window close.
            ai_factory (AiWorkerFactory | None): Optional existing Windows
                legacy AI mode owner, bound to the saved profile.
            redact_ai (Callable[[str], str] | None): Credential-aware worker
                event redactor, or None for an isolated profile.
            import_actions (ImportActions | None): Available manual import
                commands for the profile's exact current connection mode.
            capture (CaptureCommands | None): Available shared Watcher and
                Flight owner for the saved connection.
            recoverable_capture (bool): An exact pending plan is available for review.
            runtime_settings (RuntimeSettings | None): Existing writable profile settings.
            connections (ConnectionActions | None): Shared explicit upload mode transitions.
            dependencies (DependencyActions | None): Existing runtime diagnostics and repair operations.
        Returns:
            None: Creates the Qt shell without automatically resuming a writer.
        """
        super().__init__()
        self.profile = profile
        self.version = version
        self.close_to_tray = close_to_tray
        self.restart_requested = False
        self._exiting = False
        self._shutdown_done = False
        self._exit_after_ai = False
        self._runtime_settings = runtime_settings
        self._connections = connections
        self._edit_focus: QWidget | None = None
        self._support_dialog: QDialog | None = None
        self._saved_reports_dialog: QDialog | None = None
        self._support_copy = False
        self._support_preview: QPlainTextEdit | None = None
        self._support_status: QLabel | None = None
        self._lightbox_active = False
        self._background_activity: dict[str, bool] = {
            'watcher': False, 'import': False, 'ai': False,
        }
        self._monitor_active: bool | None = None
        self._monitor_indicator: QLabel | None = None
        self._header_has_link = False
        self._support_lane = QtReadLane[str](self._show_support_report, self._finish_support_report, self)
        self._activity_timer = QTimer(self)
        self._activity_timer.setSingleShot(True)
        self._activity_timer.setInterval(QT_ACTIVITY_REFRESH_MS)
        self._activity_timer.timeout.connect(self._refresh_visible_activity)
        self._sanitize_worker = redact_ai or (lambda message: message)
        self._activity_writer = (
            ActivityWriter(JobStateStore(profile / 'upload_state.json', history_limit))
            if ai_factory is not None or import_actions is not None or capture is not None else None
        )
        self._layout_path = profile / "ui-layout.json"
        self._layout_timer = QTimer(self)
        self._layout_timer.setInterval(UI_LAYOUT_SAVE_DELAY_MS)
        self._layout_timer.setSingleShot(True)
        self._layout_timer.timeout.connect(self._save_layout)
        self._tray: QSystemTrayIcon | None = None
        self._layout: ViewLayoutState | None = None
        name = gettext("PHP Gallery uploader")
        self.setWindowTitle(f"{name} {version}")
        icon = QIcon(str(_ICON_PATH))
        self.setWindowIcon(icon)
        root = QWidget()
        layout = QVBoxLayout(root)
        layout.setContentsMargins(*SHELL_INSETS)
        layout.setSpacing(HEADER_WORKSPACE_GAP)
        self._shell_layout = layout
        self._shell_margins = layout.contentsMargins()
        self._shell_spacing = layout.spacing()
        self.setCentralWidget(root)
        self.header = QtAppHeader(version)
        if sys.platform == 'darwin':
            self.header.brand.setStyleSheet(
                f"font-size: {SHELL_BRAND_POINTS}pt; font-weight: 700;",
            )
            for identity_label in (self.header.tagline, self.header.version):
                identity_label.setStyleSheet(f"font-size: {SHELL_HEADER_EYEBROW_POINTS}pt;")
        layout.addWidget(self.header)
        self.update_banner = QWidget()
        self.update_banner.setProperty("shellCard", True)
        update_row = QHBoxLayout(self.update_banner)
        update_row.setContentsMargins(*SHELL_BADGE_INSETS)
        self.update_label = QLabel()
        update_row.addWidget(self.update_label, stretch=1)
        update_row.addWidget(ActionButton(gettext("Open update"), self.check_updates, primary=True))
        self.update_banner.hide()
        layout.addWidget(self.update_banner)
        self.rail = QWidget()
        self.rail.setObjectName("workspace-rail")
        rail = QVBoxLayout(self.rail)
        rail.setContentsMargins(0, 0, 0, 0)
        rail.setSpacing(SHELL_RAIL_GAP)
        self.rail.setStyleSheet(
            'QPushButton[compactIcon="true"] {'
            f'border-radius: 8px; padding: 2px; font-size: {SHELL_RAIL_GLYPH_POINTS}pt;'
            '}'
        )
        self._navigation = QButtonGroup(self)
        self._nav_buttons: list[ActionButton] = []
        body = QHBoxLayout()
        body.setSpacing(SHELL_RAIL_GAP)
        body.addWidget(self.rail)
        self.updater = QtUpdater(profile, version, self.update_protected, self)
        self.updater.ready_to_exit.connect(self.exit_window)
        self.updater.release_changed.connect(self._update_offer)
        self.tabs = QTabWidget()
        self.tabs.setDocumentMode(True)
        self.tabs.tabBar().hide()
        body.addWidget(self.tabs, stretch=1)
        layout.addLayout(body, stretch=1)
        self.photos = QtPhotos(
            profile, gallery_address, connections, self.transfer_busy,
            capture.start_contextual if capture is not None else None,
            gallery_rename_busy=lambda: self.transfer_busy(allow_linked_rename=True),
        )
        self.photos.splitter_moved.connect(self._schedule_layout_save)
        self.operator = QtOperatorViews(
            profile, history_limit, self,
            open_import=lambda: self.show_page('import'), retry_import=self._retry_import_from_activity,
            open_folder=lambda: self.open_local(profile), open_log=self._open_log,
            open_support=self.open_support_report, copy_support=self.copy_diagnostics,
            sanitize=self._sanitize_worker,
        )
        self.flight_reports = QtFlightReports(
            profile / 'flights', self.operator.selected_flight_directory, capture, self._sanitize_worker,
        )
        self.flight_reports.finished.connect(self.operator.refresh)
        self.operator.flights.layout_body.addWidget(self.flight_reports)
        self.import_flow: QtImport | None = None
        if import_actions is not None:
            self.import_flow = QtImport(
                import_actions, self.operator.show_import_snapshot, self.operator.selected_import_id,
                self._sanitize_worker, self._review_pending_uploads,
            )
            self.import_flow.connection_settings_requested.connect(self._open_connection_settings)
            self.import_flow.busy_changed.connect(self.operator.set_import_busy)
            self.import_flow.busy_changed.connect(
                lambda active: self._set_background_activity('import', active),
            )
            self.import_flow.finished.connect(self.operator.refresh)
            self.import_flow.activity.connect(self._import_event)
            for history_widget in (
                self.operator.imports.notice, self.operator.imports.refresh_button,
                self.operator.imports.table, self.operator.imports.status,
                self.operator.import_table, self.operator.import_summary,
            ):
                history_widget.hide()
            self.operator.imports.layout_body.addWidget(self.import_flow)
            self.operator.imports.layout_body.addStretch()
        import_scroll = QScrollArea()
        import_scroll.setWidgetResizable(True)
        import_scroll.setFrameShape(QFrame.Shape.NoFrame)
        import_scroll.setWidget(self.operator.imports)
        self.ai = QtAiWorker(
            ai_factory, self._sanitize_worker, runtime_settings, dependencies,
            self.settings_protected,
        )
        self.ai.stopped.connect(self._ai_finished)
        self.ai.status_event.connect(self._ai_event)
        self.ai.work_changed.connect(
            lambda active: self._set_background_activity('ai', active),
        )
        self.photos.work_changed.connect(self._capture_busy)
        self.updater.gate_changed.connect(self._capture_busy)
        self.updater.gate_changed.connect(lambda busy: self.ai.setEnabled(not busy))
        ai_host = QWidget()
        ai_layout = QVBoxLayout(ai_host)
        ai_layout.setContentsMargins(0, 0, 0, 0)
        ai_layout.addWidget(self.ai)
        ai_layout.addStretch()
        ai_scroll = QScrollArea()
        ai_scroll.setWidgetResizable(True)
        ai_scroll.setFrameShape(QFrame.Shape.NoFrame)
        ai_scroll.setWidget(ai_host)
        self.capture: QtCapture | None = None
        self._game_presence: GamePresence | None = None
        if capture is not None:
            if os.name == 'nt':
                self._game_presence = GamePresence(
                    lambda: str(runtime_settings.load().get('simconnect_dll_path', ''))
                    if runtime_settings is not None else '',
                    profile, active_by_default=False,
                )
                self._game_presence.start()
            self.capture = QtCapture(
                capture, profile / 'flights', self.other_writer_busy,
                self._sanitize_worker, self.operator.selected_flight_directory,
                recoverable_capture, presence=self._game_presence,
            )
            self.capture.activity.connect(self._watcher_event)
            self.capture.finished.connect(self.operator.refresh)
            self.capture.busy_changed.connect(self._capture_busy)
            self.capture.watcher_activity_changed.connect(
                lambda active: self._set_background_activity('watcher', active),
            )
            self.capture.replay_watcher_activity()
            self.capture.capture_target_changed.connect(self.photos.set_capture_target)
            self.capture.replay_capture_target()
            self.capture.capture_actions_changed.connect(self._sync_import_capture_actions)
            self.capture.restart_ready.connect(self._restart_ready)
            self.capture.queue_waiting.connect(self.photos.set_upload_pipeline_waiting)
            self.operator.configure_saved_report_actions(
                upload=lambda: self.flight_reports.upload_selected(
                    *self.operator.saved_report_upload_selection(),
                ),
                load_dispatch=lambda: self.capture.load_saved_dispatch(
                    self.operator.selected_flight_directory(),
                ),
                attach_dispatch=self.capture.attach_dispatch,
                finish=self.capture.finish,
                report_busy=self.flight_reports.busy,
                report_target=capture.report_target,
            )
            self.updater.capture_checkpoint = lambda: (
                capture.checkpoint_update if capture.snapshot().can_checkpoint else None
            )
            self.operator.flights.notice.setText(gettext('Select a saved flight.'))
            watcher = self.capture
        else:
            watcher = QWidget()
            watcher_layout = QVBoxLayout(watcher)
            watcher_note = QLabel(gettext('Gallery connection is unavailable.'))
            watcher_note.setObjectName('notice')
            watcher_note.setWordWrap(True)
            watcher_layout.addWidget(watcher_note)
            watcher_layout.addStretch()
        self.settings = QtSettings(
            profile, self.restart, runtime_settings, self.settings_protected, connections=connections,
        )
        if dependencies is not None:
            self.settings.show_runtime_dependencies(self.ai.dependency_status(), self.ai.repair_dependencies)
            self.ai.runtime_changed.connect(self.settings.refresh_dependency_status)
        self.settings.batch_changed.connect(self.photos.set_batch_limit)
        self.settings.runtime_changed.connect(self._settings_applied)
        self.settings.manage_links_requested.connect(self.photos.manage_link)
        self.settings.revoke_requested.connect(self._request_key_revocation)
        if self.capture is not None:
            self.capture.reset_finished.connect(self.settings.reload_watcher)
            self.capture.reset_finished.connect(self._refresh_capture_context)
            self.capture.folder_settings_requested.connect(self._open_watcher_settings)
            self.capture.connection_settings_requested.connect(self._open_connection_settings)
            self.capture.begin_connection_requested.connect(self._begin_connection_setup)
            self.capture.reconnect_gallery_requested.connect(self._reconnect_capture_gallery)
            self.capture.choose_folder_requested.connect(self._choose_watcher_folder)
            self.capture.reports_requested.connect(self._show_saved_flight_reports)
            self.capture.saved_dispatch_attached.connect(self.operator.refresh)
            self.capture.dispatch_status_changed.connect(self._show_operation_status)
            self.capture.credentials_changed.connect(self.settings.reload_saved_connection)
            self.capture.credentials_changed.connect(self._refresh_capture_context)
            self.photos.gallery_context_changed.connect(self._photos_presence_context_changed)
            self.photos.connection_changed.connect(self._refresh_capture_context)
            self.settings.connection_changed.connect(self._refresh_capture_context)
            self.photos.simbrief_available = self.capture.simbrief_available
            self.photos.simbrief_action = self.capture.photos_simbrief_action
            self.capture.simbrief_context_changed.connect(
                self.photos.update_simbrief_context,
            )
            self._sync_import_capture_actions()
        if self.import_flow is None:
            self.operator.activity.retry.hide()
        watcher_host = QWidget()
        watcher_body = QVBoxLayout(watcher_host)
        watcher_body.setContentsMargins(0, 0, 0, 0)
        watcher_body.addWidget(watcher)
        watcher_body.addStretch()
        self._watcher_scroll = QScrollArea()
        self._watcher_scroll.setWidgetResizable(True)
        self._watcher_scroll.setFrameShape(QFrame.Shape.NoFrame)
        self._watcher_scroll.setWidget(watcher_host)
        self.operator.flights.setObjectName("workspace-flight")
        self._pages = {
            "photos": self.photos,
            "watcher": self._watcher_scroll,
            "import": import_scroll,
            "activity": self.operator.activity,
            "flight": self._watcher_scroll,
            "ai": ai_scroll,
            "settings": self.settings,
        }
        self._workspace_labels = (
            ("photos", "▧", tr(current_language(), "photos_pane_title")),
            ("watcher", "◉", gettext("Watcher")),
            ("import", "⇧", gettext("Import")),
            ("activity", "≡", gettext("Activity")),
            ("ai", "✦", gettext("AI")),
            ("settings", "⚙", gettext("Settings")),
        )
        for page, symbol, label in self._workspace_labels:
            view = self._pages[page]
            view.setObjectName("workspace-" + page)
            self.tabs.addTab(view, label)
            button = ActionButton(label, lambda key=page: self.show_page(key))
            tone = PALETTE['ink']
            artwork = qt_icon(symbol, size=SHELL_RAIL_ART_SIZE, tone=tone, checked_tone=tone)
            if artwork.isNull():
                button.setText(symbol)
            else:
                button.setText('')
                button.setIcon(artwork)
                button.setIconSize(QSize(SHELL_RAIL_ART_SIZE, SHELL_RAIL_ART_SIZE))
            button.setAccessibleName(label)
            button.setToolTip(label)
            button.setProperty("compactIcon", True)
            button.setFixedSize(SHELL_ICON_BUTTON_EDGE, SHELL_ICON_BUTTON_EDGE)
            button.setCheckable(True)
            button.setObjectName("rail-" + page)
            self._navigation.addButton(button)
            rail.addWidget(button)
            self._nav_buttons.append(button)
        rail.addStretch()
        self._build_header()
        self._footer = self._build_footer()
        layout.addWidget(self._footer)
        self.photos.lightbox_state_changed.connect(self._show_lightbox_shell)
        self.tabs.currentChanged.connect(self._page_changed)
        self._nav_buttons[0].setChecked(True)
        self._make_menu()
        self._make_tray(icon)
        self._restore_layout()
        self._refresh_capture_context()
        self.operator.refresh()

    def _build_header(self) -> None:
        """Fill the link and action groups with their existing desktop commands.

        Returns:
            None: Connects the compact header to Photos, Watcher and updater.
        """
        links = QHBoxLayout()
        links.setSpacing(SHELL_RAIL_GAP)
        self.link_address = QLabel()
        self.link_address.setObjectName('headerLinkAddress')
        self.link_address.setTextFormat(Qt.TextFormat.PlainText)
        self.link_address.setSizePolicy(QSizePolicy.Policy.Expanding, QSizePolicy.Policy.Preferred)
        self.link_address.setWordWrap(True)
        links.addWidget(self.link_address, stretch=1)
        self._links_trigger = ActionButton(
            tr(current_language(), 'linking_address_manager'), self.photos.link,
            icon="more", icon_only=True,
        )
        links.addWidget(self._links_trigger)
        self._link_choose = ActionButton(
            gettext("Choose gallery…"), self.photos.open_gallery_picker, icon="list", icon_only=True,
        )
        self._link_disconnect = ActionButton(
            gettext("Disconnect gallery"), self.photos.unlink, icon="close", icon_only=True,
        )
        self._link_connect = ActionButton(tr(current_language(), "linking_connect_website"), self.photos.link)
        self._link_connect.setObjectName("header-link-connect")
        self._link_connect.setSizePolicy(QSizePolicy.Policy.Fixed, QSizePolicy.Policy.Preferred)
        for action in (self._link_choose, self._link_disconnect, self._link_connect):
            links.addWidget(action)
        links.setAlignment(self._link_connect, Qt.AlignmentFlag.AlignRight)
        self.header.link_layout.addLayout(links)
        self.photos.connection_changed.connect(self._refresh_header_link)
        self.photos.link_action_reported.connect(self._show_operation_status)
        self.photos.diagnostic_reported.connect(
            lambda summary: self._record_activity('system', 'warning', '[Photos] ' + summary, ''),
        )
        # Win32 and X11 collapse the idle Photos link slot and use tighter
        # action spacing. Aqua keeps the native-sized group for its reserve.
        if sys.platform != 'darwin':
            self.header.action_layout.setSpacing(SHELL_RAIL_GAP)
        badge = QWidget()
        badge.setProperty("shellCard", True)
        badge.setObjectName("watcher-metrics")
        readings = QHBoxLayout(badge)
        readings.setContentsMargins(*SHELL_BADGE_INSETS)
        self._watcher_marker = QLabel()
        self._watcher_count = QLabel("–")
        self._watcher_clock = QLabel("–:–")
        readings.addWidget(self._watcher_marker)
        for art, label in (("queue", self._watcher_count), ("clock", self._watcher_clock)):
            icon_label = QLabel()
            icon_label.setPixmap(qt_icon(art, size=SHELL_HEADER_ICON_SIZE).pixmap(SHELL_HEADER_ICON_SIZE))
            readings.addWidget(icon_label)
            label.setMinimumWidth(
                label.fontMetrics().horizontalAdvance('0' * WATCHER_HEADER_METRIC_MIN_CELLS),
            )
            readings.addWidget(label)
        self._watcher_stop = ActionButton(
            tr(current_language(), "watcher_header_stop"), self._finish_watcher_from_header,
            icon="stop", icon_only=True, icon_size=SHELL_HEADER_ICON_SIZE,
        )
        self._watcher_stop.setObjectName('watcherHeaderFinish')
        self._watcher_stop.setFixedSize(SHELL_ICON_BUTTON_EDGE, SHELL_HEADER_ICON_SIZE)
        retained_size = self._watcher_stop.sizePolicy()
        retained_size.setRetainSizeWhenHidden(True)
        self._watcher_stop.setSizePolicy(retained_size)
        readings.addWidget(self._watcher_stop)
        self.header.action_layout.addWidget(badge)
        storage_label = tr(current_language(), 'photos_workspace_storage_scope')
        self._storage_button = ActionButton(
            storage_label, self._open_storage_details, icon='unknown', icon_size=SHELL_HEADER_ICON_SIZE,
        )
        self._storage_button.setObjectName('headerPhotoStorage')
        self._storage_button.setText('—')
        self.settings.storage_observed.connect(self._show_storage_summary)
        self.header.action_layout.addWidget(self._storage_button)
        self._monitor_indicator = QLabel()
        self._monitor_indicator.setObjectName('background-activity-monitor')
        self._monitor_indicator.setFixedSize(
            SHELL_MONITOR_SLOT_EDGE, SHELL_MONITOR_SLOT_EDGE,
        )
        self._monitor_indicator.setAlignment(Qt.AlignmentFlag.AlignCenter)
        self.header.action_layout.addWidget(self._monitor_indicator)
        self._sync_background_monitor()
        self.updates_button = ActionButton(
            gettext("Check for updates or open the available update"),
            self.check_updates, icon="refresh", icon_only=True,
        )
        self.header.action_layout.addWidget(self.updates_button)
        help_button = ActionButton(
            gettext("Watcher help"), self._show_watcher_help, icon="help", icon_only=True,
        )
        self.header.action_layout.addWidget(help_button)
        if sys.platform != 'darwin':
            for action in (
                self._links_trigger, self._link_choose, self._link_disconnect,
                self.updates_button, help_button,
            ):
                action.setFixedSize(SHELL_ICON_BUTTON_EDGE, SHELL_ICON_BUTTON_EDGE)
                action.setIconSize(QSize(SHELL_HEADER_ICON_SIZE, SHELL_HEADER_ICON_SIZE))
        if self.capture is not None:
            self.capture.header_changed.connect(self._update_watcher_header)
        self._update_watcher_header("–", "–:–", "stopped", False)
        self._refresh_header_link()

    def _build_footer(self) -> QWidget:
        """Mount the latest-activity strip below the current workspace.

        Returns:
            QWidget: Compact footer linking to the full Activity history.
        """
        footer = QWidget()
        footer.setObjectName("latest-activity")
        footer.setProperty("shellCard", True)
        row = QHBoxLayout(footer)
        row.setContentsMargins(*SHELL_FOOTER_INSETS)
        title = QLabel(gettext("LATEST"))
        title.setProperty("eyebrow", True)
        title.setObjectName("latest-activity-title")
        self.latest_activity = QLabel()
        self.latest_activity.setObjectName("latest-activity-message")
        self.latest_activity.setTextFormat(Qt.TextFormat.PlainText)
        self.latest_activity.setSizePolicy(QSizePolicy.Policy.Ignored, QSizePolicy.Policy.Preferred)
        self._latest_message = gettext("Ready when you are")
        self.latest_activity.setText(self._latest_message)
        self.statusBar().hide()
        self.statusBar().messageChanged.connect(self._show_footer_notice)
        row.addWidget(title)
        row.addWidget(self.latest_activity, stretch=1)
        history = ActionButton(
            gettext("Open full activity history"), lambda: self.show_page("activity"),
            icon="list", icon_only=True,
        )
        history.setFixedSize(SHELL_FOOTER_ACTION_EDGE, SHELL_FOOTER_ACTION_EDGE)
        row.addWidget(history)
        return footer

    def _publish_latest(self, message: str) -> None:
        """Retain the latest activity while a transient notice owns the footer.

        Args:
            message (str): Sanitized local activity text.
        Returns:
            None: Keeps the most recent message underneath temporary feedback.
        """
        self._latest_message = message
        self._show_footer_notice(self.statusBar().currentMessage())

    def _show_footer_notice(self, notice: str) -> None:
        """Render only changed text in the existing latest-activity strip.

        Args:
            notice (str): Current native status message, or empty after expiry.
        Returns:
            None: Preserves footer geometry during worker and selection changes.
        """
        text = notice or self._latest_message
        if self.latest_activity.text() != text:
            self.latest_activity.setText(text)
            self.latest_activity.setToolTip(text)

    def _set_background_activity(self, source: str, active: bool) -> None:
        """Update one existing worker's cached activity indicator state.

        Args:
            source (str): Stable activity owner key: watcher, import or ai.
            active (bool): Whether that owner currently has a live worker.
        Returns:
            None: Updates only the monitor dot when aggregate state changes.
        """
        if source not in self._background_activity:
            return
        state = bool(active)
        if self._background_activity[source] == state:
            return
        self._background_activity[source] = state
        self._sync_background_monitor()

    def _sync_background_monitor(self) -> None:
        """Render the Tk-compatible idle or active monitor state.

        Returns:
            None: Uses cached worker signals and shared theme artwork only.
        """
        indicator = self._monitor_indicator
        if indicator is None:
            return
        active = any(self._background_activity.values())
        if self._monitor_active is active:
            return
        self._monitor_active = active
        caption = gettext(
            'Background activity is running.' if active
            else 'No background worker is active.',
        )
        color = PALETTE['success' if active else 'muted']
        indicator.setText('●')
        indicator.setStyleSheet(
            'QLabel#background-activity-monitor {'
            f'color: {color}; font-size: {SHELL_MONITOR_ICON_SIZE}px; padding: 0px;'
            '}',
        )
        indicator.setToolTip(caption)
        indicator.setAccessibleName(caption)

    def _refresh_header_link(self) -> None:
        """Show the live linked address above the same contextual actions.

        Returns:
            None: Presents the current safe address without reading credentials.
        """
        address = self.photos.linked_gallery_address()
        photos_selected = self.tabs.currentWidget() is self.photos
        disconnected_photos = not address and photos_selected
        # Aqua Tk keeps the unused Connect cell's natural geometry. On Win32
        # and X11 the same empty group collapses. Keep native breakpoints even
        # though the Photos card owns the only visible Connect action here.
        reserve_idle_link = sys.platform == 'darwin' and disconnected_photos
        for widget in (self._link_connect, self.header.links):
            policy = widget.sizePolicy()
            if policy.retainSizeWhenHidden() != reserve_idle_link:
                policy.setRetainSizeWhenHidden(reserve_idle_link)
                widget.setSizePolicy(policy)
        display_address = address.removeprefix("https://").removeprefix("http://")
        self.link_address.setText(display_address)
        self.link_address.setToolTip(address)
        self.link_address.setAccessibleName(address)
        self.link_address.setVisible(bool(address))
        self._links_trigger.setVisible(bool(address))
        self._link_connect.setVisible(not address and not photos_selected)
        for action in (self._link_choose, self._link_disconnect):
            size = action.sizePolicy()
            size.setRetainSizeWhenHidden(bool(address))
            action.setSizePolicy(size)
            action.setVisible(bool(address) and not photos_selected)
        self.header.links.setVisible(not disconnected_photos)
        observe_storage = bool(address) and not self._header_has_link
        self._header_has_link = bool(address)
        if observe_storage:
            self.settings.refresh_storage()
        self.header.reflow()

    def _show_storage_summary(self, summary: str) -> None:
        """Keep the shared thumbnail count and bytes in the adaptive header.

        Args:
            summary (str): Safe local totals or the existing unknown indicator.
        Returns:
            None: Keeps the latest local-cache summary available across link changes.
        """
        if self._storage_button.text() != summary:
            self._storage_button.setText(summary)
            self._storage_button.setIcon(qt_icon(
                'unknown' if summary == '—' else 'local', size=SHELL_HEADER_ICON_SIZE,
            ))
            self.header.reflow()

    def _open_storage_details(self) -> None:
        """Anchor local thumbnail accounting to the original header action.

        Returns:
            None: Shows shared measured totals and opens the same quota owner.
        """
        button = self._storage_button
        menu = QMenu(button)
        details = self.settings.storage_usage.text().splitlines()
        if not details:
            details = [tr(current_language(), 'photos_workspace_storage_loading')]
        for detail in details:
            menu.addAction(detail).setEnabled(False)
        menu.addSeparator()
        menu.addAction(gettext('Refresh'), self.settings.refresh_storage)
        menu.addAction(gettext('Settings'), self._show_storage_settings)
        menu.exec(button.mapToGlobal(button.rect().bottomLeft()))
        menu.deleteLater()

    def _show_storage_settings(self) -> None:
        """Open the one Settings surface that owns thumbnail limits.

        Returns:
            None: Selects Settings and focuses the cache quota control.
        """
        self.show_page('settings')
        self.settings.focus_storage()

    def _open_connection_settings(self) -> None:
        """Focus the existing API-key controls without switching connection mode.

        Returns:
            None: Opens Settings at the saved connection editor only; the
                current linked or legacy upload authority is not changed.
        """
        self.show_page('settings')
        api_key = self.settings.fields.get('api_key')
        if api_key is not None:
            self.settings.ensureWidgetVisible(api_key)
            api_key.setFocus(Qt.FocusReason.ShortcutFocusReason)

    def _begin_connection_setup(self) -> None:
        """Open the scoped connection editor over the current operator workspace.

        Returns:
            None: Leaves the active panel in place while the operator edits a draft.
        """
        self.settings.open_begin_connection_editor(self)

    def _reconnect_capture_gallery(self) -> None:
        """Open the live site's picker or reverify the exact saved upload grant.

        Returns:
            None: Keeps the established upload mode and any pending capture binding.
        """
        address = self.photos.linked_gallery_address()
        try:
            saved = self._connections.saved() if self._connections is not None else None
        except RuntimeError:
            saved = None
        if address and (saved is None or saved.issuer == address):
            self.photos.open_gallery_picker()
        else:
            self.photos.reconnect_uploads()

    def _review_pending_uploads(self) -> None:
        """Route the Import card to the current authorized recovery surface.

        Returns:
            None: Uses the live capture review when admitted and Import history otherwise.
        """
        capture = self.capture
        if capture is not None and capture.review_button.isEnabled():
            self.show_page('watcher')
            capture.review()
        else:
            self.show_page('activity')

    def _open_links(self, trigger: QWidget | None = None) -> None:
        """Anchor the connection actions to the compact header trigger.

        Args:
            trigger (QWidget | None): Explicit visible Settings action or the header/menu route.
        Returns:
            None: Displays existing link, renewal and removal commands.
        """
        anchor = trigger if trigger is not None and trigger.isVisible() else self._links_trigger
        if not anchor.isVisible():
            anchor = self._link_connect if self._link_connect.isVisible() else self.menuBar()
        menu = QMenu(anchor)
        menu.addAction(gettext("Open local photos"), self.photos.choose_files)
        menu.addSeparator()
        for caption, command in (
            (tr(current_language(), "linking_connect_website"), self.photos.link),
            (gettext("Link for uploads"), self.photos.link_uploads),
            ("&" + gettext("Reconnect saved link"), self.photos.reconnect_uploads),
            (gettext("Reapprove saved link"), self.photos.reapprove_uploads),
            (gettext("Forget saved link"), self.photos.forget_uploads),
        ):
            menu.addAction(caption, command)
        if self.photos.connections is not None:
            menu.addAction(
                gettext("Select upload gallery"),
                self.photos.select_upload_gallery,
            )
        if self.settings.can_select_saved_api_key():
            menu.addAction(gettext("Use saved API key"), self.settings.select_saved_api_key)
        menu.addSeparator()
        menu.addAction(gettext("Disconnect"), self.photos.unlink)
        menu.exec(anchor.mapToGlobal(anchor.rect().bottomLeft()))
        menu.deleteLater()

    def _finish_watcher_from_header(self) -> None:
        """Use the Watcher workspace's existing guarded Finish command.

        Returns:
            None: Retains the live capture's normal bound dispatch sequence.
        """
        if self.capture is not None and self.capture.finish_button.isEnabled():
            self.restore_window()
            self.capture.finish()

    def _show_watcher_help(self) -> None:
        """Show the reference Watcher guidance without changing its state.

        Returns:
            None: Opens and dismisses a native modal information card.
        """
        QMessageBox.information(self, gettext("Watcher help"), gettext(
            "Choose a folder and connect a gallery. Start watching ignores files already present, "
            "then uploads new supported images after they finish copying. Files stay on this PC "
            "unless confirmed deletion is explicitly enabled.",
        ))

    def _show_help(self) -> None:
        """Present the same general operating guidance as the Tk Help menu.

        Returns:
            None: Explains connection, capture, import, workspace and diagnostics actions.
        """
        QMessageBox.information(self, gettext("Using PHP Gallery"), gettext(
            "Connect a gallery, choose a Watcher folder, then explicitly start "
            "capture. Finish drains accepted photographs before completing.\n\n"
            "Use File to choose an import source. Review preflight before "
            "starting an import. View switches between Photos, Watcher, Import, "
            "Activity, AI and Settings.\n\n"
            "Help → Support diagnostics builds a local report you can review, "
            "copy or save for support. Review it before sharing; nothing is sent "
            "automatically."
        ))

    def _show_about(self) -> None:
        """Show the current application version and license attribution.

        Returns:
            None: Displays the same local About facts as the Tk menu.
        """
        QMessageBox.information(self, gettext("About PHP Gallery"), gettext(
            "{app_display_name} {app_version}\n"
            "Author: Rudolf Klusal\nMIT License\n"
            "https://github.com/klusik/PHP_gallery"
        ).format(
            app_display_name=gettext("PHP Gallery uploader"),
            app_version=self.version,
        ))

    @Slot(str, str, str, bool)
    def _update_watcher_header(self, queued: str, last_scan: str, stage: str, stop: bool) -> None:
        """Render retained Watcher count, scan time and state on actual changes.

        Args:
            queued (str): Current queue count or its unknown marker.
            last_scan (str): Time of the last completed scan or unknown marker.
            stage (str): Locally authored Watcher stage for translated feedback.
            stop (bool): Whether the same captured worker can finish.
        Returns:
            None: Updates the shared icons and their focus/tooltips together.
        """
        language = current_language()
        tone = "recording" if stage == "running" else "warning" if stage != "stopped" else "muted"
        self._watcher_marker.setPixmap(qt_icon(
            "recording", size=SHELL_HEADER_ICON_SIZE, tone=tone,
            hollow=stage != "running",
        ).pixmap(SHELL_HEADER_ICON_SIZE))
        self._watcher_count.setText(queued)
        self._watcher_clock.setText(last_scan)
        self._watcher_stop.setVisible(stop)
        hint = "\n".join((
            tr(language, "watcher_header_" + stage),
            tr(language, "watcher_header_queue") + " · " + queued,
            tr(language, "watcher_header_last_scan") + " · " + last_scan,
        ))
        for label in (self._watcher_marker, self._watcher_count, self._watcher_clock):
            label.setToolTip(hint)
            label.setAccessibleName(hint)
        self.header.reflow()

    def _make_menu(self) -> None:
        """Present shell commands in the reference native menu order.

        Returns:
            None: Adds keyboard-accessible File, Edit, View, Tools and Help menus.
        """
        # Tk exposes the first character of each translated title as a mnemonic.
        file_menu = self.menuBar().addMenu("&" + gettext("File"))
        if self.import_flow is not None:
            files = file_menu.addAction(gettext("Import files…"), self.import_flow.choose_files)
            files.setShortcut(QKeySequence.StandardKey.Open)
            file_menu.addAction(gettext("Import folder…"), self.import_flow.choose_folder)
            file_menu.addAction(gettext("Import ZIP archive…"), self.import_flow.choose_archive)
            file_menu.addSeparator()
        file_menu.addAction(gettext("Open watched folder"), self._open_watched_folder)
        file_menu.addAction(gettext("Open log folder"), lambda: self.open_local(self.profile))
        file_menu.addSeparator()
        quit_action = QAction(gettext("Exit"), self)
        quit_action.setShortcut(QKeySequence.StandardKey.Quit)
        quit_action.triggered.connect(self.exit_window)
        file_menu.addAction(quit_action)
        edit = self.menuBar().addMenu("&" + gettext("Edit"))
        edit.aboutToShow.connect(self._remember_edit_focus)
        for caption, method in (("Cut", "cut"), ("Copy", "copy"), ("Paste", "paste"), ("Select all", "selectAll")):
            edit.addAction(gettext(caption), lambda _checked=False, command=method: self._edit_command(command))
        edit.addSeparator()
        settings = edit.addAction(gettext("Settings…"), lambda: self.show_page("settings"))
        settings.setShortcut(QKeySequence("Ctrl+,"))
        view = self.menuBar().addMenu("&" + gettext("View"))
        for page, _symbol, label in self._workspace_labels:
            view.addAction(label, lambda _checked=False, key=page: self.show_page(key))
        tools = self.menuBar().addMenu("&" + gettext("Tools"))
        backend_action = QAction(gettext("GUI backend settings…"), self)
        backend_action.setShortcut(QKeySequence("Ctrl+Shift+B"))
        backend_action.setShortcutContext(Qt.ShortcutContext.WindowShortcut)
        backend_action.triggered.connect(self.focus_backend_picker)
        self.addAction(backend_action)
        tools.addAction(backend_action)
        tools.addSeparator()
        tools.addAction(gettext("Watcher settings…"), self._open_watcher_settings)
        if self.capture is not None:
            tools.addAction(gettext("Refresh upload queue"), self.capture.refresh)
        tools.addAction("&" + gettext("Gallery connections…"), lambda: self._open_links())
        tools.addAction(gettext("API-key connection…"), self._edit_api_connection)
        tools.addAction(gettext("Check for updates…"), self.check_updates)
        tools.addAction(gettext("Support diagnostics…"), self.open_support_report)
        choose_gallery = QAction(gettext("Choose gallery…"), self)
        choose_gallery.setShortcut(QKeySequence("Ctrl+Shift+G"))
        choose_gallery.setShortcutContext(Qt.ShortcutContext.WindowShortcut)
        choose_gallery.triggered.connect(self.photos.open_gallery_picker)
        self.addAction(choose_gallery)
        tools.addAction(choose_gallery)
        help_menu = self.menuBar().addMenu("&" + gettext("Help"))
        help_action = help_menu.addAction(gettext("Using PHP Gallery"), self._show_help)
        help_action.setShortcut(QKeySequence.StandardKey.HelpContents)
        diagnostics = help_menu.addAction(gettext("Support diagnostics…"), self.open_support_report)
        diagnostics.setShortcut(QKeySequence("Ctrl+Shift+D"))
        help_menu.addAction(gettext("About PHP Gallery"), self._show_about)

    def _remember_edit_focus(self) -> None:
        """Keep the field that was focused before opening the native Edit menu.

        Returns:
            None: Preserves the editor as the destination for its usual clipboard action.
        """
        self._edit_focus = QApplication.focusWidget()

    def _edit_command(self, name: str) -> None:
        """Dispatch a native editing action only to the remembered editor.

        Args:
            name (str): Known text-edit operation from the shell menu.
        Returns:
            None: The existing widget determines whether the operation is supported.
        """
        target = self._edit_focus
        operation = getattr(target, name, None)
        if callable(operation) and target.isEnabled():
            operation()

    def _open_watched_folder(self) -> None:
        """Open the local watched directory from the shared settings view.

        Returns:
            None: Only an existing directory is opened by the platform shell.
        """
        if self._runtime_settings is None:
            self.show_page("watcher")
            return
        try:
            saved = self._runtime_settings.load().get("watched_folder")
        except (OSError, RuntimeError, ValueError):
            self.show_page("watcher")
            return
        if isinstance(saved, str) and saved and Path(saved).is_dir():
            self.open_local(Path(saved))
        else:
            self.show_page("watcher")

    def _edit_api_connection(self) -> None:
        """Open the same protected credential draft from the menu or tray.

        Returns:
            None: Restores the regular Settings workspace behind its dialog.
        """
        self.show_page('settings')
        self.settings.open_connection_editor()

    def focus_backend_picker(self) -> None:
        """Show Settings and focus the existing GUI backend combobox.

        Returns:
            None: Offers only navigation; the real chooser owns persistence.
        """
        self.restore_window()
        self.tabs.setCurrentWidget(self.settings)
        self.settings.focus_backend_picker()

    def _make_tray(self, icon: QIcon) -> None:
        """Keep the dispatchable window visible when a native tray is absent.

        Args:
            icon (QIcon): Existing shared WinApp application icon.
        Returns:
            None: Creates one native tray icon and its explicit application actions.
        """
        if icon.isNull() or not QSystemTrayIcon.isSystemTrayAvailable():
            return
        tray = QSystemTrayIcon(icon, self)
        tray.setToolTip(gettext("PHP Gallery uploader"))
        menu = QMenu(self)
        menu.addAction(gettext('Check for updates…'), self._open_tray_update)
        open_window = menu.addAction(gettext('Open'), self.restore_window)
        menu.setDefaultAction(open_window)
        menu.addAction(gettext('Open activity'), lambda: self.show_page('activity'))
        if self._connections is not None:
            menu.addAction(gettext('Connection / API key...'), self._edit_api_connection)
            if self.capture is not None:
                menu.addAction(gettext('Revoke API key...'), self._request_key_revocation)
        if self.import_flow is not None:
            menu.addAction(gettext('Pause/resume current import'), self._toggle_tray_import)
        menu.addSeparator()
        if self.capture is not None:
            menu.addAction(gettext('Start watching'), self.capture.start_button.click)
            menu.addAction(gettext('Stop watching'), self._finish_watcher_from_header)
        if not self.ai.isHidden():
            menu.addAction(gettext('Start AI metadata worker'), self._start_tray_ai)
            menu.addAction(gettext('Stop AI metadata worker'), self.ai.stop)
        menu.addSeparator()
        menu.addAction(gettext('Open log folder'), lambda: self.open_local(self.profile))
        menu.addAction(gettext("Exit"), self.exit_window)
        tray.setContextMenu(menu)
        tray.activated.connect(self._tray_activated)
        tray.show()
        self._tray = tray

    def _open_tray_update(self) -> None:
        """Restore the native window before presenting the shared updater.

        Returns:
            None: Opens the same verified update notice as the shell button.
        """
        self.restore_window()
        self.check_updates()

    def _toggle_tray_import(self) -> None:
        """Toggle real Import pause state through its current native owner.

        Returns:
            None: Keeps progress and acknowledgement barriers in QtImport.
        """
        if self.import_flow is not None:
            self.import_flow.toggle_pause()

    def _start_tray_ai(self) -> None:
        """Dispatch the existing AI action when its view gate is enabled.

        Returns:
            None: Leaves actual gallery and worker admission to the current controller.
        """
        if self.ai.isEnabled():
            self.ai.start()

    def _tray_activated(self, reason: QSystemTrayIcon.ActivationReason) -> None:
        """Restore via the operating system's tray activation action.

        Args:
            reason (QSystemTrayIcon.ActivationReason): Native pointer action.
        Returns:
            None: Shows the existing window on click or double-click.
        """
        if reason in (QSystemTrayIcon.ActivationReason.Trigger, QSystemTrayIcon.ActivationReason.DoubleClick):
            self.restore_window()

    def show_page(self, page: str) -> None:
        """Restore the current process and select one existing workspace.

        Args:
            page (str): Toolkit-independent workspace navigation identifier.
        Returns:
            None: Shows and focuses the requested native page.
        """
        self.restore_window()
        self.tabs.setCurrentWidget(self._pages[page])
        if page == "flight":
            self._show_saved_flight_reports()
        elif page == "watcher":
            self._refresh_capture_context()
            self._watcher_scroll.verticalScrollBar().setValue(0)

    def _show_saved_flight_reports(self) -> None:
        """Open the existing saved-flight model over its owning workspace.

        Returns:
            None: Shows the catalog and actions without altering any active capture.
        """
        dialog = self._saved_reports_dialog
        create = dialog is None
        if dialog is None:
            dialog = QDialog(self)
            dialog.setWindowTitle(tr(current_language(), 'saved_reports'))
            dialog.setObjectName('savedReportsDialog')
            dialog.setWindowModality(Qt.WindowModality.WindowModal)
            layout = QVBoxLayout(dialog)
            layout.addWidget(self.operator.flights)
            dialog.resize(900, 500)
            self._saved_reports_dialog = dialog
        if dialog.isMinimized():
            dialog.showNormal()
        else:
            dialog.show()
        if create:
            native_window = dialog.windowHandle()
            if native_window is not None:
                native_window.windowStateChanged.connect(self._saved_reports_window_state)
        self.operator.refresh_flight_target()
        self.operator.refresh()
        dialog.raise_()
        dialog.activateWindow()

    def _saved_reports_window_state(self, state: Qt.WindowState) -> None:
        """Refresh the saved-flight view when its native window is restored.

        Args:
            state (Qt.WindowState): New window state from the current report dialog.
        Returns:
            None: Reopens reads only while the report view can display them.
        """
        dialog = self._saved_reports_dialog
        if state != Qt.WindowState.WindowMinimized and dialog is not None and dialog.isVisible():
            self.operator.refresh()

    def _open_watcher_settings(self) -> None:
        """Open the focused Watcher preferences from the current workspace.

        Returns:
            None: Leaves Watcher presentation and active-session ownership in place.
        """
        self.settings.open_watcher_settings()

    def _choose_watcher_folder(self) -> None:
        """Choose a folder and persist it through the shared Watcher configuration owner.

        Returns:
            None: Changes the watched path only after explicit selection and writer checks.
        """
        if self._runtime_settings is None or self.settings_protected():
            self.statusBar().showMessage(gettext('Wait for the current operation to finish.'))
            return
        try:
            current = str(self._runtime_settings.load().get('watched_folder', ''))
            selected = QFileDialog.getExistingDirectory(self, gettext('Choose folder…'), current)
            if selected and not self.settings_protected():
                self._runtime_settings.save({'watched_folder': selected})
                self.settings.reload_watcher()
                self._refresh_capture_context()
        except (OSError, RuntimeError, ValueError):
            self.statusBar().showMessage(gettext('Configuration error'))

    def _request_key_revocation(self) -> None:
        """Admit the original legacy key only while other operation owners are quiet.

        Returns:
            None: Shows the confirmed Watcher command and its completion state.
        """
        self.show_page('settings')
        if self.capture is None or self.other_writer_busy() or self.updater.busy() or self.ai.has_work():
            self.statusBar().showMessage(gettext('Wait for the current operation to finish.'))
            return
        if self.capture.revoke_legacy(
            lambda: self.updater.busy() or self.ai.has_work()
            or not self.settings.ready_for_legacy_revocation(),
        ):
            self.show_page('watcher')

    def _refresh_capture_context(self) -> None:
        """Project the selected destination and folder after accepted changes.

        Returns:
            None: Updates display labels without transmitting profile data.
        """
        folder = ''
        target_label = ''
        linked_selected = False
        try:
            if self._runtime_settings is not None:
                folder = str(self._runtime_settings.load().get('watched_folder', ''))
            if self._connections is not None:
                target = self._connections.active()
                saved = self._connections.saved()
                linked_selected = target is not None or saved is not None and saved.mode == 'linked'
                if target is not None:
                    target_label = self._sanitize_worker(target.title)
                elif saved is not None and saved.mode == 'legacy':
                    target_label = gettext('API key')
        except (OSError, RuntimeError, ValueError):
            # A missing or changing profile must never leave a stale target label.
            pass
        if self.capture is not None:
            self.capture.set_context(target_label, folder)
            self.capture.set_linked_upload_selected(linked_selected)
            try:
                delete_uploaded_files = self.capture.commands.delete_uploaded_after_upload()
            except (OSError, RuntimeError, ValueError):
                delete_uploaded_files = None
            self.capture.set_source_disposition(delete_uploaded_files)
        if self.import_flow is not None:
            self.import_flow.target.setText(
                gettext('Upload gallery: ') + target_label if target_label
                else gettext('Upload gallery: not set'),
            )

    def _photos_presence_context_changed(self, visible: bool) -> None:
        """Demand cached detection only while a linked Photos action is open.

        Args:
            visible (bool): Whether a gallery options or contextual card is open.
        Returns:
            None: GamePresence also retains window and active-owner visibility gates.
        """
        if self.capture is None:
            return
        self.capture.set_photos_context_visible(visible)

    def _show_operation_status(self, message: str) -> None:
        """Expose a current connection or SimBrief result in the common footer.

        Args:
            message (str): Sanitized result emitted by the existing capture lane.
        Returns:
            None: Shows a brief native status message without switching pages.
        """
        self.statusBar().showMessage(message, DISPATCH_STATUS_DURATION_MS)

    def _page_changed(self, index: int) -> None:
        """Refresh passive rows when an operator page becomes visible.

        Args:
            index (int): Qt's selected tab position.
        Returns:
            None: Schedules a coalesced state projection when needed.
        """
        page = self.tabs.widget(index)
        if 0 <= index < len(self._nav_buttons):
            self._nav_buttons[index].setChecked(True)
        self._refresh_header_link()
        if page is not self.photos:
            if self.photos.detailOpen:
                self.photos.dismissPhoto()
            else:
                self.photos.stop_slideshow()
        if page in self.operator.pages or page is self._watcher_scroll or page is self._pages["import"]:
            self.operator.refresh()
        elif page is self.settings:
            self.settings.refresh()

    def _show_lightbox_shell(self, active: bool) -> None:
        """Give the retained Lightbox the full client area with its own controls.

        Args:
            active (bool): The Photos view entered or dismissed its detail surface.
        Returns:
            None: Restores chrome and pending update feedback after dismissal.
        """
        self._lightbox_active = active
        if active:
            self._shell_layout.setContentsMargins(0, 0, 0, 0)
        else:
            self._shell_layout.setContentsMargins(self._shell_margins)
        self._shell_layout.setSpacing(0 if active else self._shell_spacing)
        for surface in (self.header, self.rail, self._footer, self.menuBar()):
            surface.setVisible(not active)
        self.update_banner.setVisible(not active and bool(self.update_label.text()))

    def _ai_event(self, level: str, message: str) -> None:
        """Publish the AI worker event through the shared Activity writer.

        Args:
            level (str): Original stable event severity.
            message (str): Sanitized worker caption for the active process.
        Returns:
            None: Queues the event without emitting another server request.
        """
        self._record_activity('ai', level, message, '')

    def _import_event(self, level: str, message: str, job_id: str) -> None:
        """Record manual Import status through the common Activity writer.

        Args:
            level (str): Original Import worker severity.
            message (str): Activity caption from the current worker.
            job_id (str): Original durable job ID, or empty during preflight.
        Returns:
            None: Queues a sanitized recent Activity row off the event loop.
        """
        self._record_activity('import', level, message, job_id)

    def _watcher_event(self, level: str, message: str) -> None:
        """Record sanitized Watcher or Flight events in shared Activity.

        Args:
            level (str): Severity reported by the capture service.
            message (str): Redacted display message.
        Returns:
            None: Queues a recent status row for the existing disk writer.
        """
        self._record_activity('watcher', level, message, '')

    def _retry_import_from_activity(self) -> None:
        """Offer retry through the existing selected-job Import owner.

        Returns:
            None: Switches to Import for explicit preflight or progress feedback.
        """
        if self.import_flow is not None and not self.transfer_busy():
            self.show_page('import')
            self.import_flow.retry()

    def _capture_busy(self, _changed: bool) -> None:
        """Keep capture and mode-changing commands from racing shared writers.

        Args:
            _changed (bool): One participating writer's state notification.
        Returns:
            None: Independent PDF uploads retain their exact active capture target.
        """
        exclusive = self.photos.busy() or self.updater.gate.active
        capture_owned = self.capture is not None and self.capture.busy()
        self.flight_reports.setEnabled(not exclusive)
        self.flight_reports.set_capture_busy(capture_owned)
        self.operator.refresh_flight_target()
        if self.import_flow is not None:
            self.import_flow.setEnabled(not exclusive)
            self.import_flow.set_admission_locked(capture_owned)

    def _sync_import_capture_actions(self) -> None:
        """Project live Finish, Progress, Review and safe cleanup into Import.

        Returns:
            None: Leaves every lifecycle action on the existing QtCapture owner.
        """
        if self.import_flow is None or self.capture is None:
            return
        self.import_flow.set_capture_actions(*self.capture.import_capture_actions())

    def _settings_applied(self, address: str, close_to_tray: bool) -> None:
        """Reflect accepted runtime settings in the existing Qt window.

        Args:
            address (str): Saved gallery endpoint used to suggest a connection.
            close_to_tray (bool): Current window close behavior.
        Returns:
            None: Updates the view without reloading or transmitting credentials.
        """
        self.photos.gallery_address = address
        self.close_to_tray = close_to_tray
        self._refresh_capture_context()

    def settings_protected(self) -> bool:
        """Check current writers before admitting a profile change.

        Returns:
            bool: True if settings must wait for active ownership.
        """
        return self.operator_busy() or self.ai.has_work()

    def update_protected(self) -> bool:
        """Admit installation only when every active writer can reach quiescence.

        Returns:
            bool: True for work that the current update checkpoint cannot preserve.
        """
        if self.other_writer_busy() or self.ai.has_work():
            return True
        capture = self.capture
        if capture is None or not capture.busy():
            return False
        return not self.updater.installed or not capture.can_checkpoint()

    def transfer_busy(self, *, allow_linked_rename: bool = False) -> bool:
        """Keep gallery access alive while another view owns a transfer.

        Args:
            allow_linked_rename (bool): Allow a title edit during steady linked
                capture through the existing shared upload and writer fences.
        Returns:
            bool: True if an active operation must block this Photos action.
        """
        return ((self.import_flow is not None and self.import_flow.busy())
                or (self.capture is not None and self.capture.blocks_connection_action(
                    allow_linked_rename=allow_linked_rename,
                ))
                or self.flight_reports.busy() or self.ai.has_work() or self.updater.gate.active)

    def _record_activity(self, operation: str, level: str, message: str, job_id: str) -> None:
        """Translate the current footer while retaining durable source captions.

        Args:
            operation (str): Existing application Activity category.
            level (str): Worker event severity.
            message (str): Original statement to redact before persistence.
            job_id (str): Associated stable manual job, if present.
        Returns:
            None: Leaves all persistent Activity writes to the shared owner.
        """
        safe = self._sanitize_worker(message)
        self._publish_latest(gettext(safe))
        if self._activity_writer is not None:
            self._activity_writer.submit(ActivityEvent(
                level=level, message=safe,
                operation=operation, job_id=job_id,
            ))
        if (self.operator.activity.isVisible() and not self.isMinimized()
                and not self._activity_timer.isActive()):
            self._activity_timer.start()

    def _refresh_visible_activity(self) -> None:
        """Apply one queued history refresh only while its surface is visible.

        Returns:
            None: Leaves worker event recording independent of presentation.
        """
        if self.operator.activity.isVisible() and not self.isMinimized():
            self.operator.refresh()

    def _ai_finished(self) -> None:
        """Resume an explicitly requested exit after real worker completion.

        Returns:
            None: Acts only when a close or restart is already pending.
        """
        if self._exit_after_ai:
            self.exit_window()

    def _screen_bounds(self) -> tuple[int, int, int, int]:
        """Return the nearest available desktop rectangle in logical pixels.

        Returns:
            tuple[int, int, int, int]: Usable screen x, y, width and height.
        """
        screen = self.screen() or QApplication.primaryScreen()
        if screen is None:
            return 0, 0, *QT_WINDOW_MINIMUM
        area = screen.availableGeometry()
        return area.x(), area.y(), area.width(), area.height()

    def _restore_layout(self) -> None:
        """Restore the existing geometry schema with the current DPI bounds.

        Returns:
            None: Applies normal bounds and the saved maximization state.
        """
        bounds = self._screen_bounds()
        width, height = (int(value) for value in WATCHER_WINDOW_GEOMETRY.split("x"))
        state = _read_ui_layout(self._layout_path, bounds, QT_WINDOW_MINIMUM)
        self._layout = state or _clamp_ui_layout_state(
            ViewLayoutState(width, height, bounds[0], bounds[1], "normal", PHOTOS_TREE_WIDTH),
            bounds, QT_WINDOW_MINIMUM,
        )
        self.setMinimumSize(min(QT_WINDOW_MINIMUM[0], bounds[2]), min(QT_WINDOW_MINIMUM[1], bounds[3]))
        self.setGeometry(self._layout.x, self._layout.y, self._layout.width, self._layout.height)
        if self._layout.window_state == "zoomed":
            self.setWindowState(Qt.WindowState.WindowMaximized)
        self.photos.restore_tree_width(self._layout.photos_tree_width)
        self.photos.restore_inspector_width(self._layout.photos_info_width)

    def _schedule_layout_save(self) -> None:
        """Coalesce visible window or pane-split movement into one write.

        Returns:
            None: Starts the shared layout debounce timer when appropriate.
        """
        if self.isVisible() and self._layout is not None:
            self._layout_timer.start()

    def moveEvent(self, event: QMoveEvent) -> None:
        """Observe a Qt desktop position change.

        Args:
            event (QMoveEvent): Native window movement.
        Returns:
            None: Passes through default behavior and schedules one save.
        """
        super().moveEvent(event)
        self._schedule_layout_save()

    def resizeEvent(self, event: QResizeEvent) -> None:
        """Observe a Qt desktop size change.

        Args:
            event (QResizeEvent): Native window resize.
        Returns:
            None: Passes through default behavior and schedules one save.
        """
        super().resizeEvent(event)
        self._schedule_layout_save()

    def _save_layout(self) -> None:
        """Save only current ordinary window placement via the common owner.

        Returns:
            None: Publishes a valid layout without storing maximized dimensions.
        """
        if self._layout is None or self.isMinimized() or self.isFullScreen():
            return
        geometry = self.normalGeometry() if self.isMaximized() else self.geometry()
        state = _clamp_ui_layout_state(replace(
            self._layout,
            x=geometry.x(), y=geometry.y(), width=geometry.width(), height=geometry.height(),
            window_state="zoomed" if self.isMaximized() else "normal",
            photos_tree_width=self.photos.tree_width() or self._layout.photos_tree_width,
            photos_info_width=self.photos.preferred_inspector_width,
        ), self._screen_bounds(), QT_WINDOW_MINIMUM)
        if state == self._layout:
            return
        try:
            _write_ui_layout(self._layout_path, state)
            self._layout = state
        except OSError:
            logging.warning("Qt window layout could not be saved.")

    def restore_window(self) -> None:
        """Activate the current window without creating a second UI owner.

        Returns:
            None: Displays existing state with its established maximization.
        """
        if self.isMinimized():
            self.setWindowState(self.windowState() & ~Qt.WindowState.WindowMinimized)
        self.show()
        self.raise_()
        self.activateWindow()

    def hide_to_tray(self) -> None:
        """Hide only after an available tray can restore the same window.

        Returns:
            None: Leaves the window visible on platforms without a native tray.
        """
        if self._tray is not None and self._tray.isVisible():
            self._save_layout()
            self.hide()

    def open_local(self, path: Path) -> None:
        """Open an explicit user-owned application log or configuration path.

        Args:
            path (Path): Fixed existing local path from the application menu.
        Returns:
            None: Delegates to the native desktop shell.
        """
        QDesktopServices.openUrl(QUrl.fromLocalFile(str(path)))

    def graphics_api(self) -> str:
        """Return the observed Qt scene graph API without inferring hardware.

        Returns:
            str: Qt's reported API name for the live viewport.
        """
        return self.photos.quick.quickWindow().rendererInterface().graphicsApi().name

    def copy_diagnostics(self) -> None:
        """Collect and copy the same privacy-filtered local report as Tk.

        Returns:
            None: The worker publishes a bounded report to the clipboard.
        """
        self._request_support_report(copy=True)

    def _open_log(self) -> None:
        """Open the existing local rotating log through the platform shell.

        Returns:
            None: Opens only the known file in the current profile if present.
        """
        path = self.profile / 'watcher.log'
        if path.is_file():
            self.open_local(path)

    def open_support_report(self) -> None:
        """Review redacted diagnostics before copying or saving them locally.

        Returns:
            None: Shows a modal native report with local-only actions.
        """
        if self._support_dialog is not None:
            self._support_dialog.raise_()
            return
        dialog = QDialog(self)
        dialog.setWindowTitle(gettext('Support diagnostics'))
        dialog.setAttribute(Qt.WidgetAttribute.WA_DeleteOnClose)
        layout = QVBoxLayout(dialog)
        notice = QLabel(gettext(
            'Review this report before sharing. Credentials, local paths '
            'and URL query values are filtered. Files and photographs are not '
            'attached. Nothing is sent automatically.'
        ))
        notice.setWordWrap(True)
        layout.addWidget(notice)
        controls = QHBoxLayout()
        controls.addWidget(ActionButton(gettext('Refresh report'), self._request_support_report, icon='refresh'))
        controls.addWidget(ActionButton(gettext('Copy report'), self._copy_displayed_support))
        controls.addWidget(ActionButton(gettext('Save report…'), self._save_displayed_support, primary=True))
        controls.addStretch()
        controls.addWidget(ActionButton(gettext('Close'), dialog.reject))
        layout.addLayout(controls)
        self._support_preview = QPlainTextEdit()
        self._support_preview.setAccessibleName(gettext('Support diagnostics'))
        self._support_preview.setReadOnly(True)
        layout.addWidget(self._support_preview, stretch=1)
        self._support_status = QLabel()
        layout.addWidget(self._support_status)
        self._support_dialog = dialog
        dialog.finished.connect(self._close_support_report)
        dialog.resize(self.centralWidget().size())
        dialog.open()
        self._support_preview.setFocus()
        self._request_support_report()

    def _request_support_report(self, *, copy: bool = False) -> None:
        """Collect local support data without blocking the GUI event loop.

        Args:
            copy (bool): Publish directly to the clipboard after explicit copy.
        Returns:
            None: Starts the latest bounded local read with no network activity.
        """
        self._support_copy = copy
        diagnostic = '\n'.join((
            f"WinApp: {self.version}",
            f"GUI backend: effective=pyside6 requested={self.settings.requested}",
            f"Qt: {qVersion()} ({QApplication.platformName()})",
            f"Qt Quick API: {self.graphics_api()}",
        ))
        runtime = {
            'app_version': self.version,
            'python_version': sys.version.split()[0],
            'platform': f'{platform.system()} {platform.release()}',
            'machine': platform.machine(),
            'runtime': 'installed' if getattr(sys, 'frozen', False) else 'source',
        }
        if self._support_status is not None:
            self._support_status.setText(gettext('Loading…'))

        def collect(cancelled: Event, publish: Callable[[str], None]) -> None:
            """Publish a redacted snapshot from the shared local report builder.

            Args:
                cancelled (Event): This report read's current lifetime fence.
                publish (Callable[[str], None]): Queued safe report receiver.
            Returns:
                None: Returns one text report while its request remains active.
            """
            if cancelled.is_set():
                return
            values = self._runtime_settings.load() if self._runtime_settings is not None else {}
            report = build_support_report(
                diagnostic, values, runtime, self.profile / 'watcher.log',
                secrets=(str(values.get('api_key', '')),),
            )
            if not cancelled.is_set():
                publish(report)

        self._support_lane.submit(collect)

    def _show_support_report(self, report: str) -> None:
        """Publish only the current redacted local report.

        Args:
            report (str): Complete bounded text from the privacy-filtering owner.
        Returns:
            None: Updates the report dialog and any explicit clipboard request.
        """
        if self._support_copy:
            QApplication.clipboard().setText(report)
            self.statusBar().showMessage(gettext('Diagnostics copied.'))
        if self._support_preview is not None and self._support_status is not None:
            self._support_preview.setPlainText(report)
            self._support_status.setText(gettext(
                'Report ready · {report_count:,} characters · collected locally'
            ).format(report_count=len(report)))

    def _finish_support_report(self, failure: str) -> None:
        """Show a redacted failure outcome for the current collection only.

        Args:
            failure (str): Exception category from the read lane, or empty.
        Returns:
            None: Leaves the previous report usable after a read failure.
        """
        if failure:
            message = gettext('Report collection failed: ') + failure
            if self._support_status is not None:
                self._support_status.setText(message)
            self.statusBar().showMessage(message)

    def _copy_displayed_support(self) -> None:
        """Copy the report currently visible to the user.

        Returns:
            None: Leaves the clipboard untouched until a report is available.
        """
        if self._support_preview is not None and self._support_status is not None:
            report = self._support_preview.toPlainText()
            if report:
                QApplication.clipboard().setText(report)
                self._support_status.setText(gettext('Displayed report copied to clipboard.'))

    def _save_displayed_support(self) -> None:
        """Save exactly the currently displayed report with the shared owner.

        Returns:
            None: Saves only to a destination explicitly chosen in the local picker.
        """
        if self._support_preview is None or self._support_status is None:
            return
        report = self._support_preview.toPlainText()
        if not report:
            return
        selected, _filter = QFileDialog.getSaveFileName(
            self._support_dialog, gettext('Save support report'),
            str(self.profile / 'PHP-Gallery-support.txt'),
            f"{gettext('Text files')} (*.txt);;{gettext('All files')} (*)",
        )
        if not selected:
            return
        try:
            save_support_report(Path(selected), report)
        except OSError as exc:
            self._support_status.setText(gettext('Could not save report: ') + type(exc).__name__)
        else:
            self._support_status.setText(gettext('Displayed report saved locally. Nothing was sent.'))

    def _close_support_report(self, _result: int) -> None:
        """Withdraw the closed dialog and its passive pending reads.

        Args:
            _result (int): Modal close result, with no persistent transition.
        Returns:
            None: Releases the view references and retires the read lane request.
        """
        self._support_lane.cancel()
        self._support_dialog = None
        self._support_preview = None
        self._support_status = None

    def check_updates(self) -> None:
        """Open the shared verified update flow from the header.

        Returns:
            None: Shows the single update surface for this application.
        """
        self.updater.present()

    def _update_offer(self, version: str) -> None:
        """Update the common header caption when a verified offer changes.

        Args:
            version (str): Available exact version, or empty if current.
        Returns:
            None: Keeps the same header action and shows the offered version.
        """
        self.updates_button.setIcon(qt_icon("upload" if version else "refresh"))
        hint = (gettext("Update {update_available_version} available").format(update_available_version=version)
                if version else gettext("Check for updates or open the available update"))
        self.updates_button.setToolTip(hint)
        self.updates_button.setAccessibleName(hint)
        self.update_label.setText(hint if version else "")
        self.update_banner.setVisible(not self._lightbox_active and bool(version))

    def restart(self) -> None:
        """Request normal exit followed by the existing launcher relaunch.

        Returns:
            None: The launcher retains its profile owner through Qt teardown.
        """
        if self.capture is not None and self.capture.can_checkpoint() and not self.updater.busy():
            self.capture.checkpoint_restart()
        elif self.operator_busy():
            logging.info('Desktop restart deferred; backend=pyside6; reason=owned_work')
            self.statusBar().showMessage(gettext('Wait for the current operation to finish.'))
        else:
            self._restart_ready()

    def _restart_ready(self, relaunch: bool = True) -> None:
        """Exit only after the live capture releases its workspace.

        Args:
            relaunch (bool): Preserve the shell's explicit restart intent.
        Returns:
            None: The launcher applies the saved backend if a restart is requested.
        """
        self.restart_requested = relaunch
        self.exit_window()

    def exit_window(self) -> None:
        """Exit explicitly even when ordinary close would hide to tray.

        Returns:
            None: Runs the same final close path used by a visible window.
        """
        self._exiting = True
        self.close()

    def operator_busy(self) -> bool:
        """Check each active desktop writer before exiting or restarting.

        Returns:
            bool: True while an operation could still own accepted work or files.
        """
        return (self.other_writer_busy() or self.updater.busy()
                or (self.capture is not None and self.capture.busy()))

    def other_writer_busy(self) -> bool:
        """Check the Import and saved Flight writers outside live capture.

        Returns:
            bool: True while a competing command can still own local work.
        """
        settings = getattr(self, 'settings', None)
        return ((self.import_flow is not None and self.import_flow.busy())
                or (settings is not None and (settings.writes_storage() or settings.writes_connection_test()))
                or self.flight_reports.busy() or self.photos.busy() or self.updater.gate.active)

    def closeEvent(self, event: QCloseEvent) -> None:
        """Release read activity and native objects during actual shutdown.

        Args:
            event (QCloseEvent): Window close request from Qt or explicit exit.
        Returns:
            None: Hides a tray-enabled window or accepts coordinated exit.
        """
        if not self._exiting and self.close_to_tray and self._tray is not None and self._tray.isVisible():
            self.hide_to_tray()
            event.ignore()
            return
        if self.capture is not None and self.capture.can_checkpoint() and not self.updater.busy():
            self.capture.checkpoint_restart(relaunch=False)
            event.ignore()
            return
        if self.operator_busy():
            self._exiting = False
            self.statusBar().showMessage(gettext('Wait for the current operation to finish.'))
            event.ignore()
            return
        self.ai.stop()
        if self.ai.has_work():
            self._exit_after_ai = True
            self.statusBar().showMessage(gettext("Wait for the current operation to finish."))
            event.ignore()
            return
        self.shutdown()
        event.accept()

    def shutdown(self) -> None:
        """Withdraw view ownership before the Qt event loop returns.

        Returns:
            None: Stops scheduled GUI callbacks and retires read-only sessions.
        """
        if self._shutdown_done:
            return
        self._shutdown_done = True
        self._layout_timer.stop()
        self._activity_timer.stop()
        self._save_layout()
        self.photos.shutdown()
        self._support_lane.close()
        self.operator.close()
        if self.capture is not None:
            self.capture.close_view()
        if self._game_presence is not None:
            self._game_presence.stop()
        if self._saved_reports_dialog is not None:
            self._saved_reports_dialog.hide()
        self.flight_reports.close_view()
        if self.import_flow is not None:
            self.import_flow.close_view()
        self.ai.close_view()
        self.settings.close_view()
        self.updater.shutdown()
        if self._activity_writer is not None and not self._activity_writer.close(QT_ACTIVITY_FLUSH_SECONDS):
            logging.warning('Recent Activity history did not finish flushing before exit.')
        if self._tray is not None:
            self._tray.hide()


@_qt_log_capture()
def run_qt(
    *, profile: Path, version: str, gallery_address: str, history_limit: int,
    close_to_tray: bool, start_minimized: bool,
    activation_requested: Callable[[], bool], ready: Callable[[], None],
    ai_factory: AiWorkerFactory | None = None, redact_ai: Callable[[str], str] | None = None,
    import_actions: ImportActions | None = None,
    recoverable_imports: tuple[str, ...] = (),
    capture: CaptureCommands | None = None, recoverable_capture: bool = False,
    runtime_settings: RuntimeSettings | None = None, connections: ConnectionActions | None = None,
    resume_capture: bool = False,
    reset_watcher: bool = False,
    dependencies: DependencyActions | None = None,
) -> bool:
    """Start a single Qt event loop and confirm the first retained scene frame.

    Args:
        profile (Path): Already admitted WinApp profile.
        version (str): Application version for shell and update discovery.
        gallery_address (str): Non-secret current address suggested for Photos.
        history_limit (int): Shared bound for Activity rows.
        close_to_tray (bool): Existing profile close-to-tray setting.
        start_minimized (bool): Hide after readiness when a tray is available.
        activation_requested (Callable[[], bool]): Consume native single-owner activation.
        ready (Callable[[], None]): Publish this child's exact startup response.
        ai_factory (AiWorkerFactory | None): Optional saved-config AI factory
            admitted by the existing Windows profile owner.
        redact_ai (Callable[[str], str] | None): Existing redactor for AI status.
        import_actions (ImportActions | None): Explicit native import commands
            when the saved mode and profile support them.
        recoverable_imports (tuple[str, ...]): Newest-first legacy recovery
            rows verified against the saved profile before application startup.
        capture (CaptureCommands | None): Shared Watcher owner for the saved mode.
        recoverable_capture (bool): An exact pending capture is available.
        runtime_settings (RuntimeSettings | None): Current application config owner.
        connections (ConnectionActions | None): Explicit upload mode selection.
        resume_capture (bool): Resume an admitted ordinary or installer checkpoint.
        reset_watcher (bool): Continue a previously confirmed durable factory reset.
        dependencies (DependencyActions | None): Shared runtime diagnostics and repair commands.
    Returns:
        bool: True if a Settings or menu action requested an ordinary relaunch.
    Raises:
        RuntimeError: Qt exited before a validated scene frame or failed its event loop.
    """
    configure_language(load_ui_language(profile))
    app = QApplication([])
    app.setApplicationName("PHP Gallery uploader")
    app.setApplicationVersion(version)
    apply_qt_theme(app)
    window = QtWindow(
        profile, version, gallery_address, history_limit, close_to_tray,
        ai_factory=ai_factory, redact_ai=redact_ai, import_actions=import_actions,
        capture=capture, recoverable_capture=recoverable_capture,
        runtime_settings=runtime_settings, connections=connections,
        dependencies=dependencies,
    )
    window.photos.prepare_graphics_readiness()
    startup_opacity = window.windowOpacity()
    window.setWindowOpacity(0.0)
    app.aboutToQuit.connect(window.shutdown)
    quick_window = window.photos.quick.quickWindow()
    live = False

    def present() -> None:
        """Confirm readiness after native scene rendering and the main loop start.

        Returns:
            None: Acknowledges the startup once and applies requested tray state.
        """
        nonlocal live
        if live:
            return
        quick_window.afterRendering.disconnect(first_frame.publish)
        window.photos.finish_graphics_readiness()
        window.setWindowOpacity(startup_opacity)
        logging.info(
            "Desktop backend ready: pyside6; pid=%d", os.getpid(),
        )
        try:
            ready()
        except OSError:
            app.exit(1)
            return
        live = True
        logging.info("Qt ready: version=%s platform=%s graphics=%s", qVersion(), app.platformName(), window.graphics_api())
        if reset_watcher and window.capture is not None:
            window.show_page("watcher")
            window.capture.resume_reset()
        elif recoverable_capture and window.capture is not None:
            window.show_page("watcher")
            if resume_capture:
                window.capture.resume_checkpoint()
        elif recoverable_imports and window.import_flow is not None:
            window.tabs.setCurrentWidget(window.operator.imports)
            window.operator.review_startup_import(recoverable_imports[0])
            window.import_flow.status.setText(gettext('Recover unfinished import'))
        if start_minimized:
            window.hide_to_tray()
        window.photos.resume_saved_browse()

    def activate() -> None:
        """Consume an activation request without creating a new profile owner.

        Returns:
            None: Observes only local activation and existing link lifetime.
        """
        try:
            if activation_requested():
                window.restore_window()
        except OSError:
            logging.warning("Qt instance activation could not be observed.")
        window.photos.check_link()

    def scene_error(_error: QQuickWindow.SceneGraphError, _message: str) -> None:
        """Exit correctly if rendering fails before or after startup.

        Args:
            _error (QQuickWindow.SceneGraphError): Qt's sanitized error category.
            _message (str): Qt diagnostic text, kept outside user/profile logs.
        Returns:
            None: Refuses readiness or closes after any admitted worker finishes.
        """
        if live:
            window.exit_window()
        else:
            app.exit(1)

    first_frame = FirstFrame(present, window)
    quick_window.afterRendering.connect(first_frame.publish, Qt.ConnectionType.QueuedConnection)
    window.photos.quick.sceneGraphError.connect(scene_error)
    poll = QTimer(window)
    poll.setInterval(ACTIVATION_POLL_MS)
    poll.timeout.connect(activate)
    poll.start()
    window.show()
    result = app.exec()
    if result != 0 or not live:
        raise RuntimeError("Qt did not complete a ready application loop.")
    return window.restart_requested


class FirstFrame(QObject):
    """Deliver the first Qt Quick frame callback on the owning GUI thread."""

    def __init__(self, receive: Callable[[], None], owner: QWidget) -> None:
        """Bind the native scene event to the application's startup callback.

        Args:
            receive (Callable[[], None]): Ready callback running on the GUI thread.
            owner (QWidget): Visible shell owning this queued receiver.
        Returns:
            None: QObject lifetime follows the top-level window.
        """
        super().__init__(owner)
        self.receive = receive

    @Slot()
    def publish(self) -> None:
        """Acknowledge a rendered scene without modifying it on the render thread.

        Returns:
            None: The main event loop admits startup exactly once.
        """
        self.receive()
