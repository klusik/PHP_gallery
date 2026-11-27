# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_capture.py
# Module Type: Qt Watcher and Flight presentation
# Purpose: Present native capture controls and explicit FIFO recovery.
# Responsibilities: Display immutable state, serialize operator commands and
#   carry the exact reviewed queue generation back to the capture owner.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Present Watcher and Flight without owning credentials or transfer state."""

from dataclasses import dataclass, replace
from math import ceil
from pathlib import Path
from queue import Empty
from threading import Event
import time
from typing import Callable, Literal

from PySide6.QtCore import QEvent, QPoint, QObject, QSignalBlocker, Qt, QTimer, Signal
from PySide6.QtGui import QImage, QKeySequence, QPixmap, QShortcut
from PySide6.QtWidgets import (
    QApplication, QCheckBox, QDialog, QFileDialog, QFrame, QGridLayout, QHBoxLayout,
    QInputDialog, QLabel, QMainWindow,
    QLineEdit, QMessageBox, QProgressBar, QTableWidget, QTableWidgetItem, QToolTip,
    QVBoxLayout, QWidget,
)

from .capture_session import (
    CaptureCommands, CaptureDecision, CaptureReview, CaptureStatus,
    RetainedQueueSummary, WatchFolderInspection,
)
from .diagnostics import format_watch_recovery_diagnostic
from .simconnect_flight import (
    METRES_PER_FOOT, METRES_PER_SECOND_PER_FPM, METRES_PER_SECOND_PER_KNOT,
)
from .flight_jobs import FlightPreferences, REPORT_LANGUAGES
from .flight_report_translations import recovery_text, tr
from .flight_simbrief import identity_query
from .flight_workflow import FinalizationWorker
from .game_presence import GamePresence
from .localization import current_language, gettext
from .qt_photo_source import decode_photo
from .qt_read_lane import QtReadLane
from .qt_theme import ActionButton, RaisedSurface, ToggleSwitch
from .simconnect_time import utc_status_caption
from .ui_layout import (
    OVERLAY_ACTION_COLUMNS, OVERLAY_HEIGHT_PX, OVERLAY_MARGIN_PX, OVERLAY_WIDTH_PX,
    WORKSPACE_ACTION_HEIGHT, WORKSPACE_CARD_INSETS, WORKSPACE_COMPACT_ACTION_HEIGHT,
)


@dataclass(frozen=True)
class CaptureUtcPreferenceResult:
    """Return an accepted saved UTC preference to the Qt command lane."""

    enabled: bool

# Purpose: Poll the bounded command mailbox and scalar flight instruments.
# Type: int. Units: milliseconds. Scope: visible or tray-resident capture.
# Consumers: QtCapture. Rationale: 200 ms matches the Tk idle event cadence.
CAPTURE_UI_POLL_MS = 200
# Purpose: Match the narrow gutter around the reference Watcher cards.
# Type: tuple[int, int, int, int]. Units: logical pixels per side.
# Scope: the two Watcher workspace cards. Consumers: QtCapture.__init__.
# Rationale: The Tk layout gives cards a 6 px horizontal and 4 px vertical inset.
CAPTURE_WORKSPACE_INSETS = (6, 4, 6, 4)
# Purpose: Return to paint and input events between worker mailbox batches.
# Type: int. Units: events. Scope: one UI polling cycle.
# Consumers: QtCapture._poll. Rationale: 64 matches the existing desktop budget.
CAPTURE_UI_EVENT_BATCH = 64
# Purpose: Keep a successful progress result visible long enough to acknowledge.
# Type: int. Units: seconds. Scope: one visible completed finalizer.
# Consumers: QtCapture._refresh_progress_autoclose.
# Rationale: Three seconds matches the Tk progress acknowledgement window.
SUCCESS_AUTOCLOSE_SECONDS = 3
# Purpose: Keep the startup bar indeterminate until Watcher reports its first scan.
# Type: tuple[int, int]. Units: Qt progress values. Scope: Watcher startup only.
# Consumers: QtCapture. Rationale: Qt's 0..0 range paints a native busy indicator.
WATCHER_START_PROGRESS_RANGE = (0, 0)
ReviewResult = tuple[CaptureReview, QImage | None]


def capture_progress_indeterminate(state: CaptureStatus, busy: bool) -> bool:
    """Show the unknown-duration bar while an operation still owns the session.

    Args:
        state (CaptureStatus): Current immutable Watcher/Flight snapshot.
        busy (bool): Qt command lane currently owns an admitted operation.
    Returns:
        bool: True for a live owner or admitted command, false after terminal exit.
    """
    return bool(
        busy or state.alive or state.capturing or state.recording or state.finishing
        or state.revocation_cleanup_pending or state.abandon_cleanup_pending
    )


def capture_progress_timing(state: CaptureStatus, now: float) -> str:
    """Format elapsed and measured ETA values from the finalizer snapshot.

    Args:
        state (CaptureStatus): Current immutable progress projection.
        now (float): Current monotonic time for a nonterminal operation.
    Returns:
        str: Localized elapsed/phase timing, or empty without a real operation.
    """
    if state.progress_started <= 0 or state.progress_phase_started <= 0:
        return ''
    terminal = state.phase in {'completed', 'error', 'cancelled'}
    observed_at = state.progress_phase_started if terminal else now
    elapsed = max(0, int(observed_at - state.progress_started))
    phase_elapsed = max(0, int(observed_at - state.progress_phase_started))
    timing = (
        f"{tr(current_language(), 'elapsed')}: {elapsed}s  ·  "
        f"{tr(current_language(), 'phase_elapsed')}: {phase_elapsed}s"
    )
    if state.eta_seconds is None and not terminal:
        timing += '  ·  ' + tr(current_language(), 'estimating')
    elif state.eta_seconds is not None and not terminal:
        timing += (
            '  ·  ' + tr(current_language(), 'transfer_remaining') + ': '
            + str(int(max(0.0, state.eta_seconds))) + 's'
        )
    return timing


@dataclass(frozen=True)
class SavedDispatchResult:
    """Carry a successful saved-flight route back to the Qt thread."""

    caption: str


CaptureResult = str | bool | ReviewResult | SavedDispatchResult | CaptureUtcPreferenceResult


def capture_presence_demanded(
    capture_visible: bool, window_visible: bool, minimized: bool,
    interactive: bool,
) -> bool:
    """Admit observer polling only for a visible active Watcher surface.

    Args:
        capture_visible (bool): Whether the Watcher page is the visible tab.
        window_visible (bool): Whether its owning desktop window is shown.
        minimized (bool): Whether the owner is minimized to the taskbar or tray.
        interactive (bool): Whether the owner or a child dialog is active.
    Returns:
        bool: True only while the Qt Watcher is visible and interactive.
    """
    return capture_visible and window_visible and not minimized and interactive


def capture_idle_connection_action(
    state: CaptureStatus, command_busy: bool, retained_pending: bool,
    protected: bool, linked_selected: bool,
) -> Literal['begin', 'reconnect'] | None:
    """Select Tk's idle connection action from existing owner state only.

    Args:
        state (CaptureStatus): Current immutable capture and cleanup projection.
        command_busy (bool): Whether QtCapture has an admitted command in flight.
        retained_pending (bool): Whether local recovery requires operator review.
        protected (bool): Whether another writer or profile operation owns admission.
        linked_selected (bool): Whether the explicit linked upload mode is selected.
    Returns:
        Literal['begin', 'reconnect'] | None: Setup action for an idle owner, or
            None while work/recovery or another exclusive owner remains active.
    """
    unavailable = bool(
        command_busy or retained_pending or protected or state.alive
        or state.capturing or state.recording or state.finishing
        or state.phase in {'error', 'cancelled'} or state.more_retained
        or state.resume_available or state.error or state.context_cleanup_failed
        or state.revocation_cleanup_pending or state.abandon_cleanup_pending
    )
    if unavailable:
        return None
    return 'reconnect' if linked_selected else 'begin'


def capture_context_text(key: str, flight_detected: bool, language: str) -> str:
    """Match Tk flight captions while keeping non-flight wording neutral.

    Args:
        key (str): Flight recording, settings or progress label identity.
        flight_detected (bool): Cached context, never a native probe.
        language (str): Existing UI language code.
    Returns:
        str: Localized flight label or its capture-neutral counterpart.
    """
    if not flight_detected:
        key = {
            'begin_flight': 'begin_game',
            'record_flight': 'record_session',
            'flight_progress': 'session_progress',
            'flight_settings': 'session_settings',
        }.get(key, key)
    return tr(language, key)


class QtCapture(QWidget):
    """Render the real worker commands and their accepted-work barriers."""

    activity = Signal(str, str)
    busy_changed = Signal(bool)
    watcher_activity_changed = Signal(bool)
    finished = Signal()
    restart_ready = Signal(bool)
    reset_finished = Signal()
    credentials_changed = Signal()
    queue_waiting = Signal(bool)
    header_changed = Signal(str, str, str, bool)
    progress_requested = Signal()
    reports_requested = Signal()
    saved_dispatch_attached = Signal()
    simbrief_context_changed = Signal(bool)
    capture_target_changed = Signal(object)
    dispatch_status_changed = Signal(str)
    capture_actions_changed = Signal()
    folder_settings_requested = Signal()
    choose_folder_requested = Signal()
    connection_settings_requested = Signal()
    begin_connection_requested = Signal()
    reconnect_gallery_requested = Signal()

    def __init__(
        self, commands: CaptureCommands, root: Path, protected: Callable[[], bool],
        sanitize: Callable[[str], str], selected_flight: Callable[[], Path | None],
        pending: bool = False, presence: GamePresence | None = None,
    ) -> None:
        """Create Watcher and Flight controls sharing one serial command lane.

        Args:
            commands (CaptureCommands): Existing profile-bound worker owner.
            root (Path): Flight workspace for saved display preferences.
            protected (Callable[[], bool]): Other active local writers.
            sanitize (Callable[[str], str]): Redacts profile credentials in status.
            selected_flight (Callable[[], Path | None]): Current saved Flight row.
            pending (bool): An unfinished exact Watcher plan was verified at launch.
            presence (GamePresence | None): Existing Windows observer, demanded only
                while the Watcher surface is visible and interactive.
        Returns:
            None: Starts state observation without resuming or transmitting work.
        """
        super().__init__()
        self.commands = commands
        self.root = root
        self.protected = protected
        self.sanitize = sanitize
        self.selected_flight = selected_flight
        self._presence = presence
        self._photos_context_visible = False
        self._flight_context: bool | None = None
        self.language = current_language()
        self._busy = False
        self._watch_start_requested = False
        self._dispatch_feedback = False
        self._was_alive = False
        self._reported_busy = False
        self._reported_watcher_activity = False
        self._queue_backpressure = False
        self._reported_queue_waiting = False
        self._pending = pending
        self._linked_upload_selected = False
        self._linked_upload_selection_known = False
        self._legacy_revocation: FinalizationWorker | None = None
        self._awaiting_revocation_cleanup = False
        self._review_after_queue = False
        self._queue_available = False
        self._reset_requested = False
        self._checkpoint_relaunch: bool | None = None
        self._header_count = "–"
        self._header_scan = "–:–"
        self._ignored_startup = "-"
        self._utc_sync_enabled = bool(commands.snapshot().utc_enabled)
        self._pending_utc_sync: bool | None = None
        self._previous_utc_sync: bool | None = None
        self._header_state: tuple[str, str, str, bool] | None = None
        self._capture_target: tuple[str, int, int] | None = None
        self._rendered_controls: tuple[object, ...] | None = None
        self._import_action_state: tuple[object, ...] | None = None
        self._review_dialog: QDialog | None = None
        self._progress_dialog: RaisedSurface | None = None
        self._progress_container: QWidget | None = None
        self._progress_previous_focus: QWidget | None = None
        self._progress_hide_button: ActionButton | None = None
        self._progress_content_layout: QVBoxLayout | None = None
        self._progress_actions_layout: QGridLayout | None = None
        self._progress_action_buttons: tuple[ActionButton, ...] = ()
        self._visible_progress_actions: tuple[ActionButton, ...] = ()
        self._retained_summary: RetainedQueueSummary | None = None
        self._retained_summary_requested = False
        self._retained_summary_failure = False
        self._progress_retained_layout: bool | None = None
        self._progress_autoclose_identity: tuple[int, float] | None = None
        self._progress_autoclose_deadline: float | None = None
        self._progress_autoclose_cancelled = False
        self._progress_autoclose_caption = ''
        self._preferences = FlightPreferences.load(root)
        self._lane = QtReadLane[CaptureResult](self._receive, self._finished, self)
        self._folder_inspection_lane = QtReadLane[WatchFolderInspection](
            self._show_folder_inspection, self._folder_inspection_finished, self,
        )
        self._retained_summary_lane = QtReadLane[RetainedQueueSummary](
            self._show_retained_summary, self._retained_summary_finished, self,
        )
        layout = QVBoxLayout(self)
        layout.setContentsMargins(*CAPTURE_WORKSPACE_INSETS)
        target_card = QFrame()
        target_card.setProperty('workspaceCard', True)
        target_layout = QVBoxLayout(target_card)
        target_layout.setContentsMargins(*WORKSPACE_CARD_INSETS)
        self.target_display = QLabel(gettext('Upload gallery: not set'))
        self.target_display.setObjectName('heading')
        self.target_display.setTextFormat(Qt.TextFormat.PlainText)
        self.target_display.setWordWrap(True)
        target_row = QHBoxLayout()
        target_row.addWidget(self.target_display, stretch=1)
        self.connection_settings_button = ActionButton(
            gettext('Upload via API key'), self.connection_settings_requested.emit,
            icon='settings', icon_only=True,
        )
        target_row.addWidget(self.connection_settings_button)
        target_layout.addLayout(target_row)
        self.review_button = ActionButton(
            recovery_text(self.language, 'watch_recovery_title'), self.review,
        )
        target_layout.addWidget(self.review_button, alignment=Qt.AlignmentFlag.AlignLeft)
        self.finish_button = ActionButton(
            tr(self.language, 'finish_flight'), self.finish, icon='check',
        )
        self.connection_action_button = ActionButton(
            '', self._perform_idle_connection_action,
        )
        self.connection_action_button.setObjectName('watcherConnectionAction')
        self.connection_action_button.hide()
        target_layout.addWidget(
            self.connection_action_button, alignment=Qt.AlignmentFlag.AlignLeft,
        )
        flight_actions = QHBoxLayout()
        flight_actions.addWidget(self.finish_button)
        flight_actions.addStretch()
        target_layout.addLayout(flight_actions)
        layout.addWidget(target_card)

        watch_card = QFrame()
        watch_card.setProperty('workspaceCard', True)
        watch_layout = QVBoxLayout(watch_card)
        watch_layout.setContentsMargins(*WORKSPACE_CARD_INSETS)
        folder_actions = QHBoxLayout()
        folder_title = QLabel(gettext('WATCH FOLDER'))
        folder_title.setProperty('eyebrow', True)
        folder_actions.addWidget(folder_title)
        self.choose_folder_button = ActionButton(
            gettext('Choose folder…'), self.choose_folder_requested.emit,
        )
        folder_actions.addWidget(self.choose_folder_button)
        self.folder_settings_button = ActionButton(
            gettext('Watcher settings'), self.folder_settings_requested.emit,
            icon='settings', icon_only=True,
        )
        folder_actions.addWidget(self.folder_settings_button)
        self.reset_button = ActionButton(
            gettext('Watcher factory reset…'), self.reset_watcher,
            icon='refresh',
        )
        self.watcher_help_button = ActionButton(
            gettext('Watcher behavior'), self._show_watcher_behavior_help,
            icon='help', icon_only=True,
        )
        self.watcher_help_button.setObjectName('watcherBehaviorHelp')
        folder_actions.addWidget(self.watcher_help_button)
        folder_actions.addStretch()
        folder_actions.addWidget(self.reset_button)
        watch_layout.addLayout(folder_actions)
        self.folder_display = QLabel(gettext('No watched folder selected.'))
        self.folder_display.setTextFormat(Qt.TextFormat.PlainText)
        self.folder_display.setWordWrap(True)
        watch_layout.addWidget(self.folder_display)
        self.watcher_state = QLabel(gettext('Watcher stopped'))
        self.watcher_state.setObjectName('heading')
        self.watcher_state.setTextFormat(Qt.TextFormat.PlainText)
        watch_layout.addWidget(self.watcher_state)
        self.watcher_start_progress = QProgressBar()
        self.watcher_start_progress.setObjectName('watcherStartProgress')
        self.watcher_start_progress.setAccessibleName(gettext('Starting Watcher…'))
        self.watcher_start_progress.setProperty('captureProgress', True)
        self.watcher_start_progress.setRange(*WATCHER_START_PROGRESS_RANGE)
        self.watcher_start_progress.setTextVisible(False)
        self.watcher_start_progress.hide()
        watch_layout.addWidget(self.watcher_start_progress)
        self.watch_details = QLabel(
            gettext('Ignored at startup: -') + '   ' + gettext('Last scan: -'),
        )
        self.watch_details.setTextFormat(Qt.TextFormat.PlainText)
        self.watch_details.setObjectName('status')
        watch_layout.addWidget(self.watch_details)
        self.folder_inspection_status = QLabel()
        self.folder_inspection_status.setObjectName('watchFolderInspectionStatus')
        self.folder_inspection_status.setWordWrap(True)
        self.folder_inspection_status.setTextFormat(Qt.TextFormat.PlainText)
        self.folder_inspection_status.hide()
        watch_layout.addWidget(self.folder_inspection_status)
        actions = QHBoxLayout()
        self.start_button = ActionButton(
            gettext('Start Watcher'), self.start, primary=True, icon='play',
        )
        self.resume_button = ActionButton(
            gettext('Continue'), self.resume_checkpoint, primary=True, icon='play',
        )
        self.resume_button.setAccessibleName(gettext('Resume Watcher'))
        self.resume_button.setToolTip(gettext('Resume Watcher'))
        self.interrupt_button = ActionButton(
            recovery_text(self.language, 'force_stop_watch'), self.interrupt, icon='stop',
        )
        actions.addWidget(self.start_button)
        actions.addWidget(self.resume_button)
        self.refresh_button = ActionButton(
            gettext('Refresh upload queue'), self.refresh,
            icon='refresh', icon_only=True,
        )
        actions.addWidget(self.refresh_button)
        self.inspect_folder_button = ActionButton(
            recovery_text(self.language, 'watch_inspection_title'),
            self.inspect_watch_folder,
        )
        self.inspect_folder_button.setObjectName('watchFolderInspectionButton')
        actions.addWidget(self.inspect_folder_button)
        actions.addStretch()
        actions.addWidget(self.interrupt_button)
        watch_layout.addLayout(actions)
        recovery = QHBoxLayout()
        self.close_button = ActionButton(
            recovery_text(self.language, 'watch_recovery_close'), self.close_retained,
        )
        self.retry_access_button = ActionButton(gettext('Retry'), self.retry_context_cleanup)
        self.retry_access_button.setAccessibleName(gettext('Watcher access could not be closed. Retry.'))
        recovery.addWidget(self.close_button)
        recovery.addWidget(self.retry_access_button)
        self.retry_access_button.hide()
        self.retry_revocation_button = ActionButton(
            gettext('Retry'), self.retry_legacy_revocation_cleanup, icon='refresh',
        )
        self.retry_revocation_button.setAccessibleName(gettext(
            'Server revocation confirmed; local key cleanup needs review.',
        ))
        self.retry_revocation_button.hide()
        recovery.addWidget(self.retry_revocation_button)
        recovery.addStretch()
        self._recovery_row = QWidget()
        self._recovery_row.setLayout(recovery)
        self._recovery_row.hide()
        watch_layout.addWidget(self._recovery_row)
        self.status = QLabel()
        self.status.setObjectName('status')
        self.head = QLabel()
        for label in (self.status, self.head):
            label.setTextFormat(Qt.TextFormat.PlainText)
            label.setWordWrap(True)
            label.hide()
            watch_layout.addWidget(label)
        self.record = ToggleSwitch(tr(self.language, 'record_session'))
        self.record.setObjectName('flightRecordActivity')
        self.flight_settings_button = ActionButton(
            tr(self.language, 'flight_settings'), self.show_flight_settings,
            icon='settings', icon_only=True,
        )
        self.flight_settings_button.setObjectName('flightSettingsButton')
        self.flight_preview = QLabel(self._telemetry_caption(None))
        self.flight_preview.setTextFormat(Qt.TextFormat.PlainText)
        self.flight_preview.setObjectName('status')
        self.instrument = self.flight_preview
        flight_progress_row = QHBoxLayout()
        flight_progress_row.addWidget(self.record)
        flight_progress_row.addWidget(self.flight_settings_button)
        flight_progress_row.addStretch()
        flight_progress_row.addWidget(self.flight_preview)
        self.progress_button = ActionButton(
            tr(self.language, 'flight_progress'), self.progress_requested.emit,
            icon='list', icon_only=True,
        )
        self.saved_reports_button = ActionButton(
            'PDF', self.reports_requested.emit,
        )
        self.saved_reports_button.setAccessibleName(tr(self.language, 'saved_reports'))
        self.saved_reports_button.setToolTip(tr(self.language, 'saved_reports'))
        for button in (
            self.connection_action_button, self.review_button, self.finish_button,
            self.start_button, self.resume_button, self.inspect_folder_button,
            self.saved_reports_button,
        ):
            button.setMinimumHeight(WORKSPACE_ACTION_HEIGHT)
            button.setProperty('workspaceAction', True)
        for button in (self.choose_folder_button, self.reset_button):
            button.setMinimumHeight(WORKSPACE_COMPACT_ACTION_HEIGHT)
        flight_progress_row.addWidget(self.saved_reports_button)
        watch_layout.addLayout(flight_progress_row)
        self.utc_row = QWidget()
        utc_layout = QHBoxLayout(self.utc_row)
        utc_layout.setContentsMargins(0, 0, 0, 0)
        self.utc_toggle = ToggleSwitch(gettext('Sync UTC time'))
        self.utc_toggle.setObjectName('flightUtcSync')
        self.utc_toggle.setToolTip(gettext(
            'Keeps Flight Simulator in sync with real-world UTC while the watcher runs. '
            'Off by default. Your choice is remembered.',
        ))
        self.utc_toggle.toggled.connect(self._change_utc_sync)
        utc_layout.addWidget(self.utc_toggle)
        self.utc_status = QLabel()
        self._utc_status_active = False
        self.utc_status.setObjectName('status')
        self.utc_status.setTextFormat(Qt.TextFormat.PlainText)
        self.utc_status.setWordWrap(True)
        self.utc_status.hide()
        utc_layout.addWidget(self.utc_status, stretch=1)
        self.utc_row.hide()
        watch_layout.addWidget(self.utc_row)
        self.delete_warning = QLabel(gettext('Uploaded photos will be deleted from this PC.'))
        self.delete_warning.setObjectName('status')
        self.delete_warning.setWordWrap(True)
        self.delete_warning.hide()
        watch_layout.addWidget(self.delete_warning)
        self.progress_requested.connect(self.show_progress)
        layout.addWidget(watch_card)
        self.flight = QDialog(self, Qt.WindowType.Dialog)
        self.flight.setObjectName('qtFlightSettingsDialog')
        self.flight.setWindowTitle(self._capture_text('flight_settings'))
        self.flight.setModal(False)
        self.flight.setWindowModality(Qt.WindowModality.NonModal)
        self.flight.setProperty('shellCard', True)
        flight_layout = QVBoxLayout(self.flight)
        flight_header = QHBoxLayout()
        self.flight_heading = QLabel(self.flight.windowTitle())
        self.flight_heading.setObjectName('heading')
        flight_header.addWidget(self.flight_heading, stretch=1)
        flight_header.addWidget(ActionButton(
            tr(self.language, 'hide'), self.flight.hide,
            icon='close', icon_only=True,
        ))
        flight_layout.addLayout(flight_header)
        options = QHBoxLayout()
        self.reports = QCheckBox(gettext('Reports'))
        options.addWidget(self.reports)
        self.editions = {code: QCheckBox(code.upper()) for code in REPORT_LANGUAGES}
        for choice in self.editions.values():
            options.addWidget(choice)
        options.addStretch()
        flight_layout.addLayout(options)
        dispatch_controls = QHBoxLayout()
        self.simbrief_button = ActionButton(
            tr(self.language, 'simbrief_load'), self.toggle_dispatch, icon='cloud',
        )
        self.simbrief_identity_button = ActionButton(
            tr(self.language, 'simbrief_identity'), self.change_dispatch_identity,
            icon='settings', icon_only=True,
        )
        self.simbrief_identity_button.setObjectName('simbriefIdentityButton')
        self.simbrief_identity_button.setAccessibleName(
            tr(self.language, 'simbrief_identity'),
        )
        self.simbrief_identity_button.setToolTip(
            tr(self.language, 'simbrief_identity'),
        )
        self.attach_dispatch_button = ActionButton(
            tr(self.language, 'simbrief_file'), self.attach_dispatch, icon='add',
        )
        for button in (
            self.simbrief_button, self.simbrief_identity_button,
            self.attach_dispatch_button,
        ):
            dispatch_controls.addWidget(button)
        dispatch_controls.addStretch()
        flight_layout.addLayout(dispatch_controls)
        self.dispatch_status = QLabel()
        self.dispatch_status.setTextFormat(Qt.TextFormat.PlainText)
        self.dispatch_status.setWordWrap(True)
        flight_layout.addWidget(self.dispatch_status)
        settings_actions = QHBoxLayout()
        settings_actions.addWidget(self.progress_button)
        settings_actions.addStretch()
        flight_layout.addLayout(settings_actions)
        focus_order = (
            self.review_button, self.record, self.flight_settings_button,
            self.choose_folder_button, self.folder_settings_button, self.reset_button,
            self.watcher_help_button, self.start_button, self.resume_button,
            self.refresh_button, self.inspect_folder_button,
            self.finish_button, self.interrupt_button,
            self.close_button, self.retry_access_button, self.retry_revocation_button,
        )
        for current, following in zip(focus_order, focus_order[1:]):
            QWidget.setTabOrder(current, following)
        layout.addStretch()
        self._restore_preferences()
        self._sync_contextual_labels()
        for choice in (self.record, self.reports, *self.editions.values()):
            choice.toggled.connect(self._save_preferences)
        self._timer = QTimer(self)
        self._timer.setInterval(CAPTURE_UI_POLL_MS)
        self._timer.timeout.connect(self._poll)
        self._poll()
        self._timer.start()

    def _show_watcher_behavior_help(self) -> None:
        """Show the existing Watcher behavior explanation beside its trigger.

        Returns:
            None: Opens an anchored native tooltip and starts no Watcher work.
        """
        explanation = gettext(
            'Files already present at startup are ignored. New files wait until '
            'their size and modified time remain unchanged. Subfolders are '
            'excluded unless enabled in Watcher settings.',
        )
        anchor = self.watcher_help_button
        QToolTip.showText(
            anchor.mapToGlobal(QPoint(0, anchor.height())), explanation, anchor,
        )

    def _telemetry_caption(
        self, telemetry: tuple[float | None, float | None, float | None] | None,
    ) -> str:
        """Format the cached recorder sample in the same compact Tk units.

        Args:
            telemetry (tuple[float | None, float | None, float | None] | None):
                IAS, altitude and vertical speed in SI units, or no live sample.
        Returns:
            str: IAS knots, altitude feet and vertical feet/minute, using dashes
                for unavailable values without making a simulator request.
        """
        speed, altitude, vertical = telemetry or (None, None, None)
        speed_text = (
            f'{speed / METRES_PER_SECOND_PER_KNOT:.0f}'
            if speed is not None else '—'
        )
        altitude_text = (
            f'{altitude / METRES_PER_FOOT:,.0f}'
            if altitude is not None else '—'
        )
        vertical_text = (
            f'{vertical / METRES_PER_SECOND_PER_FPM:+,.0f}'
            if vertical is not None else '—'
        )
        return f'IAS {speed_text} kt · ALT {altitude_text} ft · V/S {vertical_text} fpm'

    def show_flight_settings(self) -> None:
        """Show the reusable nonmodal Flight settings sheet.

        Returns:
            None: Exposes existing report preferences and SimBrief actions.
        """
        self.flight.show()
        self.flight.raise_()
        self.flight.activateWindow()

    def inspect_watch_folder(self) -> None:
        """Request a cancellable read-only count of current Watcher sources.

        Returns:
            None: Keeps discovery off the UI lane and never admits queue work.
        """
        self.folder_inspection_status.setText(gettext('Loading…'))
        self.folder_inspection_status.show()
        self._folder_inspection_lane.submit(self._read_watch_folder)

    def _read_watch_folder(
        self, cancelled: Event, publish: Callable[[WatchFolderInspection], None],
    ) -> None:
        """Read current persisted Watcher folder options off Qt.

        Args:
            cancelled (Event): Current inspection generation fence.
            publish (Callable[[WatchFolderInspection], None]): GUI result sink.
        Returns:
            None: Publishes only one current count summary.
        """
        result = self.commands.inspect_watch_folder(cancelled)
        if result is not None and not cancelled.is_set():
            publish(result)

    def _show_folder_inspection(self, result: WatchFolderInspection) -> None:
        """Present Tk-compatible count or failure wording in the Watcher card.

        Args:
            result (WatchFolderInspection): Current read-only discovery summary.
        Returns:
            None: Shows no queue IDs or source filenames.
        """
        if result.error == 'missing':
            caption = recovery_text(self.language, 'watch_inspection_missing').format(
                folder=result.folder,
            )
        elif result.error:
            caption = recovery_text(self.language, 'watch_inspection_failed').format(
                reason=recovery_text(self.language, 'watch_inspection_failure_reason'),
            )
        else:
            caption = recovery_text(self.language, 'watch_inspection_result').format(
                folder=result.folder, supported=result.supported,
                unsupported=result.unsupported,
            )
        self.folder_inspection_status.setText(caption)
        self.folder_inspection_status.show()

    def _folder_inspection_finished(self, failure: str) -> None:
        """Show one fixed failure if shared discovery unexpectedly escaped.

        Args:
            failure (str): Safe read-lane exception category or empty success.
        Returns:
            None: Keeps the Watcher queue and all controls unchanged.
        """
        if failure:
            self.folder_inspection_status.setText(
                recovery_text(self.language, 'watch_inspection_failed').format(
                    reason=recovery_text(
                        self.language, 'watch_inspection_failure_reason',
                    ),
                ),
            )
            self.folder_inspection_status.show()

    def show_progress(self) -> None:
        """Show the reusable nonmodal snapshot and recovery panel.

        Returns:
            None: Displays current state and delegates all decisions to existing
                Capture methods without changing worker intent.
        """
        dialog = self._progress_dialog
        was_visible = dialog is not None and dialog.isVisible()
        state = self.commands.snapshot()
        if not was_visible and state.phase == 'completed':
            self._progress_autoclose_identity = state.finalizer_identity, state.progress_started
            self.keep_progress_open()
        if dialog is None:
            owner = self.window()
            container = owner
            if isinstance(owner, QMainWindow):
                central = owner.centralWidget()
                if central is not None:
                    container = central
            self._progress_container = container
            container.installEventFilter(self)
            dialog = RaisedSurface(container, 'qtCaptureProgressSurface')
            layout = QVBoxLayout(dialog)
            self._progress_content_layout = layout
            header = QHBoxLayout()
            progress_title = tr(self.language, 'session_progress')
            self._progress_title = QLabel(progress_title)
            self._progress_title.setObjectName('heading')
            header.addWidget(self._progress_title, stretch=1)
            self._progress_hide_button = ActionButton(
                tr(self.language, 'hide'), self.hide_progress, icon='close', icon_only=True,
            )
            header.addWidget(self._progress_hide_button)
            layout.addLayout(header)
            self._progress_phase = QLabel()
            self._progress_phase.setObjectName('heading')
            self._progress_phase.setTextFormat(Qt.TextFormat.PlainText)
            self._progress_phase.setWordWrap(True)
            self._progress_timing = QLabel()
            self._progress_timing.setObjectName('qtCaptureProgressTiming')
            self._progress_timing.setTextFormat(Qt.TextFormat.PlainText)
            self._progress_gauge = QProgressBar()
            self._progress_gauge.setAccessibleName(progress_title)
            self._progress_gauge.setProperty('captureProgress', True)
            self._progress_gauge.setTextVisible(False)
            self._progress_activity = QProgressBar()
            self._progress_activity.setObjectName('qtCaptureProgressActivity')
            self._progress_activity.setAccessibleName(progress_title)
            self._progress_activity.setProperty('captureProgress', True)
            self._progress_activity.setTextVisible(False)
            self._progress_activity.hide()
            layout.addWidget(self._progress_phase)
            layout.addWidget(self._progress_timing)
            layout.addWidget(self._progress_gauge)
            layout.addWidget(self._progress_activity)
            self._progress_details = QLabel()
            self._progress_details.setObjectName('qtCaptureProgressDetails')
            self._progress_details.setTextFormat(Qt.TextFormat.PlainText)
            self._progress_details.setWordWrap(True)
            self._progress_head = QLabel()
            self._progress_head.setObjectName('qtCaptureProgressHead')
            self._progress_head.setTextFormat(Qt.TextFormat.PlainText)
            self._progress_head.setWordWrap(True)
            self._progress_flight = QLabel()
            self._progress_flight.setObjectName('qtCaptureProgressFlight')
            self._progress_flight.setTextFormat(Qt.TextFormat.PlainText)
            self._progress_flight.setWordWrap(True)
            self._progress_samples = QLabel()
            self._progress_samples.setObjectName('qtCaptureProgressSamples')
            self._progress_samples.setTextFormat(Qt.TextFormat.PlainText)
            self._progress_editions = QTableWidget(0, 2, dialog)
            self._progress_editions.setObjectName('qtCaptureProgressEditions')
            self._progress_editions.setHorizontalHeaderLabels((
                tr(self.language, 'report_languages'), gettext('Status'),
            ))
            self._progress_editions.horizontalHeader().setStretchLastSection(True)
            self._progress_editions.verticalHeader().hide()
            self._progress_editions.setEditTriggers(
                QTableWidget.EditTrigger.NoEditTriggers,
            )
            self._progress_editions.setSelectionMode(
                QTableWidget.SelectionMode.NoSelection,
            )
            self._progress_editions.hide()
            self._progress_status = QLabel()
            self._progress_status.setObjectName('qtCaptureProgressStatus')
            self._progress_status.setTextFormat(Qt.TextFormat.PlainText)
            self._progress_status.setWordWrap(True)
            self._progress_autoclose_row = QWidget(dialog)
            auto_layout = QHBoxLayout(self._progress_autoclose_row)
            auto_layout.setContentsMargins(0, 0, 0, 0)
            self._progress_autoclose_label = QLabel()
            self._progress_autoclose_label.setObjectName('qtCaptureProgressAutoclose')
            self._progress_autoclose_label.setTextFormat(Qt.TextFormat.PlainText)
            auto_layout.addWidget(self._progress_autoclose_label, stretch=1)
            auto_layout.addWidget(ActionButton(
                tr(self.language, 'keep_open'), self.keep_progress_open,
            ))
            self._progress_autoclose_row.hide()
            for label in (
                self._progress_details, self._progress_head,
                self._progress_flight, self._progress_samples,
            ):
                layout.addWidget(label)
            layout.addWidget(self._progress_editions)
            layout.addWidget(self._progress_status)
            layout.addWidget(self._progress_autoclose_row)
            layout.addStretch()
            actions = QGridLayout()
            self._progress_continue = ActionButton(
                self.resume_button.text(), self._continue_progress, icon='play',
            )
            self._progress_continue.setObjectName('qtCaptureProgressContinue')
            self._progress_close = ActionButton(
                self.close_button.text(), self.close_retained, icon='close',
            )
            self._progress_stop = ActionButton(
                self.interrupt_button.text(), self.interrupt, icon='stop',
            )
            self._progress_abandon = ActionButton(
                tr(self.language, 'abandon'), self.abandon_capture, icon='close',
            )
            self._progress_force_finish = ActionButton(
                recovery_text(self.language, 'force_finish'), self.force_finish,
                icon='check',
            )
            self._progress_action_buttons = (
                self._progress_continue, self._progress_close,
                self._progress_stop, self._progress_abandon,
                self._progress_force_finish,
            )
            self._progress_actions_layout = actions
            for index, button in enumerate(self._progress_action_buttons):
                row, column = divmod(index, OVERLAY_ACTION_COLUMNS)
                actions.addWidget(button, row, column, alignment=Qt.AlignmentFlag.AlignLeft)
            self._visible_progress_actions = self._progress_action_buttons
            layout.addLayout(actions)
            self._progress_dialog = dialog
            self._progress_retained_layout = None
            escape = QShortcut(QKeySequence('Escape'), dialog)
            escape.setContext(Qt.ShortcutContext.WidgetWithChildrenShortcut)
            escape.activated.connect(self.hide_progress)
        if not was_visible:
            self._progress_previous_focus = QApplication.focusWidget()
        self._position_progress_overlay()
        dialog.show()
        dialog.raise_()
        self._refresh_progress_dialog(state)
        dialog.layout().activate()
        if self._progress_hide_button is not None:
            self._progress_hide_button.setFocus(Qt.FocusReason.OtherFocusReason)
        if not was_visible:
            self._retained_summary_requested = False
            self._request_retained_summary(state)

    def _position_progress_overlay(self) -> None:
        """Center the nonmodal progress surface inside its owning shell.

        Returns:
            None: Keeps the raised child panel inside the current central surface.
        """
        panel = self._progress_dialog
        container = self._progress_container
        if panel is None or container is None:
            return
        width = max(1, min(
            OVERLAY_WIDTH_PX, container.width() - 2 * OVERLAY_MARGIN_PX,
        ))
        height = max(1, min(
            OVERLAY_HEIGHT_PX, container.height() - 2 * OVERLAY_MARGIN_PX,
        ))
        panel.setGeometry(
            (container.width() - width) // 2,
            (container.height() - height) // 2,
            width, height,
        )

    def eventFilter(self, watched: QObject, event: QEvent) -> bool:
        """Recenter the visible overlay when the main shell is resized.

        Args:
            watched (QObject): Object whose event is being observed.
            event (QEvent): Resize or other native event delivered by Qt.
        Returns:
            bool: False so the owning shell receives its original event.
        """
        if (watched is self._progress_container
                and event.type() == QEvent.Type.Resize
                and self._progress_dialog is not None
                and self._progress_dialog.isVisible()):
            self._position_progress_overlay()
        return super().eventFilter(watched, event)

    def hide_progress(self) -> None:
        """Hide the progress panel and withdraw its passive queue read.

        Returns:
            None: No retained metadata read continues solely for a hidden panel.
        """
        self._withdraw_retained_summary()
        self._retained_summary = None
        self._retained_summary_failure = False
        panel = self._progress_dialog
        if panel is not None:
            was_visible = panel.isVisible()
            panel.hide()
            previous_focus, self._progress_previous_focus = (
                self._progress_previous_focus, None,
            )
            if was_visible and previous_focus is not None:
                try:
                    if previous_focus.isVisible() and previous_focus.isEnabled():
                        previous_focus.setFocus(Qt.FocusReason.OtherFocusReason)
                except RuntimeError:
                    # A temporary menu or action may have been deleted while open.
                    pass

    def _withdraw_retained_summary(self) -> None:
        """Cancel a retained metadata read after the panel is dismissed.

        Returns:
            None: The next explicit opening may request a fresh summary.
        """
        self._retained_summary_lane.cancel()
        self._retained_summary_requested = False

    def _retained_only(self, state: CaptureStatus) -> bool:
        """Identify a stopped pending queue without another active owner.

        Args:
            state (CaptureStatus): Current scalar Watcher and Flight snapshot.
        Returns:
            bool: True only while all capture/transport workers are stopped.
        """
        return bool(
            (self._pending or state.more_retained) and not self._busy
            and not state.alive and not state.watcher_worker_active
            and not state.capturing and not state.recording and not state.finishing
        )

    def _request_retained_summary(self, state: CaptureStatus) -> None:
        """Read retained counts only while the native progress panel is visible.

        Args:
            state (CaptureStatus): Current scalar recovery and worker state.
        Returns:
            None: Starts one cancellable summary read for the exact saved mode.
        """
        dialog = self._progress_dialog
        if (dialog is None or not dialog.isVisible() or not self._retained_only(state)
                or self._retained_summary_requested):
            return
        self._retained_summary_requested = True
        self._retained_summary = None
        self._retained_summary_failure = False

        def inspect(cancelled: Event, publish: Callable[[RetainedQueueSummary], None]) -> None:
            """Publish bounded count-only metadata from the capture owner.

            Args:
                cancelled (Event): Current panel visibility and generation fence.
                publish (Callable[[RetainedQueueSummary], None]): Qt result sink.
            Returns:
                None: Does not mutate a queue, session plan or recovery marker.
            """
            summary = self.commands.inspect_retained_queue(cancelled)
            if summary is not None and not cancelled.is_set():
                publish(summary)

        self._retained_summary_lane.submit(inspect)

    def _show_retained_summary(self, summary: RetainedQueueSummary) -> None:
        """Project exact retained counts and basename into Watcher recovery.

        Args:
            summary (RetainedQueueSummary): Read-only summary for the saved mode.
        Returns:
            None: Exposes counts only while the same stopped queue is still shown.
        """
        state = self.commands.snapshot()
        if (self._progress_dialog is None or not self._progress_dialog.isVisible()
                or not self._retained_only(state)):
            return
        self._retained_summary = summary
        self._retained_summary_failure = False
        self._refresh_progress_dialog(state)
        reason = (
            'no_pending_plan' if summary.session_count == 0 else
            'completion_plan_without_photo_journal'
            if summary.photo_count is None else 'retained_queue_read'
        )
        self.activity.emit('info', format_watch_recovery_diagnostic(
            'inspect', reason, sessions=summary.session_count,
            pending=(
                summary.photo_count if summary.session_count > 0 else None
            ),
        ))

    def _retained_summary_finished(self, failure: str) -> None:
        """Show a fixed local-unavailable status for unreadable retained state.

        Args:
            failure (str): Sanitized exception class or empty completion.
        Returns:
            None: Does not expose journal data or change recovery ownership.
        """
        if not failure:
            return
        state = self.commands.snapshot()
        if (self._progress_dialog is None or not self._progress_dialog.isVisible()
                or not self._retained_only(state)):
            return
        self._retained_summary_failure = True
        self._refresh_progress_dialog(state)

    def keep_progress_open(self) -> None:
        """Cancel the success countdown without changing capture state.

        Returns:
            None: Keeps the current native progress panel open for inspection.
        """
        self._progress_autoclose_cancelled = True
        self._progress_autoclose_deadline = None
        self._progress_autoclose_caption = ''
        if (self._progress_dialog is not None
                and not self._progress_autoclose_row.isHidden()):
            self._progress_autoclose_row.hide()

    def _refresh_progress_autoclose(self, state: CaptureStatus) -> None:
        """Close only the same visible successful operation after three seconds.

        Args:
            state (CaptureStatus): Current scalar operation snapshot.
        Returns:
            None: Updates the acknowledgement row and may hide its dialog.
        """
        dialog = self._progress_dialog
        if dialog is None or not dialog.isVisible():
            return
        identity = state.finalizer_identity, state.progress_started
        if identity != self._progress_autoclose_identity:
            self._progress_autoclose_identity = identity
            self._progress_autoclose_deadline = None
            self._progress_autoclose_cancelled = False
            self._progress_autoclose_caption = ''
        if state.phase != 'completed' or not all(identity):
            self._progress_autoclose_deadline = None
            self._progress_autoclose_caption = ''
            if not self._progress_autoclose_row.isHidden():
                self._progress_autoclose_row.hide()
            return
        if self._progress_autoclose_cancelled:
            if not self._progress_autoclose_row.isHidden():
                self._progress_autoclose_row.hide()
            return
        if self._progress_autoclose_deadline is None:
            self._progress_autoclose_deadline = (
                time.monotonic() + SUCCESS_AUTOCLOSE_SECONDS
            )
        # Clamp the caption to the acknowledgement interval when a rounded
        # monotonic deadline falls fractionally beyond its nominal duration.
        remaining = min(
            SUCCESS_AUTOCLOSE_SECONDS,
            max(0, ceil(self._progress_autoclose_deadline - time.monotonic())),
        )
        caption = tr(self.language, 'progress_autoclose').format(
            seconds=remaining,
        )
        if caption != self._progress_autoclose_caption:
            self._progress_autoclose_caption = caption
            self._progress_autoclose_label.setText(caption)
        if self._progress_autoclose_row.isHidden():
            self._progress_autoclose_row.show()
        if remaining == 0:
            self._progress_autoclose_cancelled = True
            self._progress_autoclose_row.hide()
            self.hide_progress()

    def _refresh_progress_dialog(self, state: CaptureStatus) -> None:
        """Mirror current capture labels and existing safe action state.

        Args:
            state (CaptureStatus): Current immutable worker-owned state snapshot.
        Returns:
            None: Updates presentation only; all button handlers remain the
                existing guarded Capture methods.
        """
        dialog = self._progress_dialog
        if dialog is None or not dialog.isVisible():
            return
        title = self._capture_text('flight_progress')
        if self._progress_title.text() != title:
            self._progress_title.setText(title)
            self._progress_gauge.setAccessibleName(title)
            self._progress_activity.setAccessibleName(title)
        retained_only = self._retained_only(state)
        running = capture_progress_indeterminate(state, self._busy)
        idle_review = state.phase == 'idle' and not running and not state.progress_total
        review_only = retained_only or idle_review
        if state.progress_total:
            phase = tr(self.language, 'phase_' + state.phase)
        elif review_only or state.phase == 'idle':
            phase = tr(self.language, 'review_pending_uploads')
        else:
            phase = self.watcher_state.text()
        self._progress_phase.setText(phase)
        timing = capture_progress_timing(state, time.monotonic())
        if self._progress_timing.text() != timing:
            self._progress_timing.setText(timing)
        self._progress_timing.setVisible(bool(timing))
        maximum = max(1, state.progress_total)
        if self._progress_gauge.maximum() != maximum:
            self._progress_gauge.setRange(0, maximum)
        if self._progress_gauge.value() != state.progress_done:
            self._progress_gauge.setValue(state.progress_done)
        # Qt uses range 0..0 only for active indeterminate painting. Keep the
        # reference's second trough present in review without animating it.
        activity_maximum = 0 if running else 1
        if self._progress_activity.maximum() != activity_maximum:
            self._progress_activity.setRange(0, activity_maximum)
            if not running:
                self._progress_activity.setValue(0)
        self._progress_activity.setVisible(running or review_only)
        self._place_progress_summary(review_only)
        detail = self.sanitize(state.progress_detail)
        head = self.head.text()
        if idle_review and not retained_only:
            detail = ''
            head = ''
        elif retained_only:
            head = ''
            summary = self._retained_summary
            if self._retained_summary_failure:
                detail = recovery_text(self.language, 'watch_recovery_local_unavailable')
            elif summary is None and self._retained_summary_requested:
                detail = recovery_text(self.language, 'watch_recovery_inspecting')
            elif summary is not None and summary.session_count == 0:
                detail = recovery_text(self.language, 'watch_recovery_no_session')
            elif summary is not None and summary.photo_count is None:
                detail = recovery_text(self.language, 'watch_recovery_no_journal').format(
                    sessions=summary.session_count,
                )
            elif summary is not None:
                detail = recovery_text(self.language, 'watch_recovery_counts').format(
                    sessions=summary.session_count, photos=summary.photo_count,
                )
                head = summary.head_name
            else:
                detail = self.watch_details.text()
            if head:
                head = recovery_text(self.language, 'watch_recovery_first').format(
                    filename=self.sanitize(head),
                )
        self._progress_details.setText(detail if review_only else detail or self.watch_details.text())
        self._progress_details.setVisible(bool(self._progress_details.text()))
        self._progress_head.setText(head)
        self._progress_head.setVisible(bool(head))
        self._progress_flight.setText(self.instrument.text())
        self._progress_flight.setVisible(
            bool(self.instrument.text()) and not review_only,
        )
        self._progress_samples.setText(
            f"{tr(self.language, 'samples')}: {state.samples}"
        )
        self._progress_samples.setVisible(state.samples > 0 or state.recording)
        edition_languages = tuple(dict.fromkeys((
            *state.rendered_languages, *state.rendering_languages,
        )))
        self._progress_editions.setRowCount(len(edition_languages))
        for row, code in enumerate(edition_languages):
            status = (
                tr(self.language, 'report_state_local')
                if code in state.rendered_languages
                else tr(self.language, 'phase_generating')
            )
            for column, value in enumerate((code.upper(), status)):
                item = self._progress_editions.item(row, column)
                if item is None:
                    item = QTableWidgetItem()
                    self._progress_editions.setItem(row, column, item)
                if item.text() != value:
                    item.setText(value)
        self._progress_editions.setVisible(bool(edition_languages))
        progress_status = (
            recovery_text(self.language, 'capture_recovery_paused')
            if review_only else self.status.text()
        )
        self._progress_status.setText(progress_status)
        self._progress_status.setVisible(bool(progress_status))
        continue_available = bool(
            state.resume_available or self._pending or state.more_retained
            or state.context_cleanup_failed
        )
        self._progress_continue.setVisible(continue_available)
        self._progress_continue.setEnabled(
            self.resume_button.isEnabled() if state.resume_available
            else self.review_button.isEnabled(),
        )
        self._progress_close.setVisible(self._pending and not state.alive)
        self._progress_close.setEnabled(self.close_button.isEnabled())
        self._progress_stop.setVisible(state.interrupt_available)
        self._progress_stop.setEnabled(
            state.interrupt_available and not self._busy and not self.protected(),
        )
        abandon_available = bool(
            state.abandon_cleanup_pending
            or (state.legacy_mode or state.linked_mode) and not state.finishing
            and (state.capturing or self._pending or state.more_retained)
        )
        if self._retained_only(state) and not state.abandon_cleanup_pending:
            abandon_available = False
        self._progress_abandon.setVisible(abandon_available)
        self._progress_abandon.setEnabled(
            not self._busy and not self.protected()
            and not state.revocation_cleanup_pending and not state.finishing,
        )
        if state.abandon_cleanup_pending:
            abandon_caption = gettext('Retry')
        else:
            abandon_key = 'keep_watched_files' if state.linked_mode else 'abandon'
            abandon_caption = (
                self._presence.text(abandon_key, self.language)
                if self._presence is not None else tr(self.language, abandon_key)
            )
        self._progress_abandon.setText(abandon_caption)
        self._progress_force_finish.setVisible(state.force_finish_available)
        self._progress_force_finish.setEnabled(
            not self._busy and not self.protected() and state.force_finish_available,
        )
        self._layout_visible_progress_actions()
        self._refresh_progress_autoclose(state)

    def _layout_visible_progress_actions(self) -> None:
        """Pack visible progress commands without gaps from hidden actions.

        Returns:
            None: Visible actions stay grouped in their established display order.
        """
        layout = self._progress_actions_layout
        if layout is None:
            return
        visible = tuple(
            button for button in self._progress_action_buttons
            if not button.isHidden()
        )
        if visible == self._visible_progress_actions:
            return
        for button in self._visible_progress_actions:
            layout.removeWidget(button)
        for index, button in enumerate(visible):
            row, column = divmod(index, OVERLAY_ACTION_COLUMNS)
            layout.addWidget(button, row, column, alignment=Qt.AlignmentFlag.AlignLeft)
        self._visible_progress_actions = visible

    def _place_progress_summary(self, review_only: bool) -> None:
        """Order the paused review caption, count and head like Tk recovery status.

        Args:
            review_only (bool): Whether the dialog shows a paused queue review.
        Returns:
            None: Moves only the existing labels; active progress order is restored.
        """
        if (self._progress_dialog is None
                or self._progress_retained_layout is review_only):
            return
        layout = self._progress_content_layout
        if layout is None:
            return
        spacer_index = next((
            index for index in range(layout.count())
            if layout.itemAt(index) is not None
            and layout.itemAt(index).spacerItem() is not None
        ), None)
        if spacer_index is None:
            return
        spacer = layout.takeAt(spacer_index)
        for label in (self._progress_details, self._progress_head, self._progress_status):
            layout.removeWidget(label)
        if review_only:
            layout.insertItem(layout.indexOf(self._progress_autoclose_row), spacer)
            index = layout.indexOf(self._progress_autoclose_row)
            for label in (self._progress_status, self._progress_details, self._progress_head):
                layout.insertWidget(index, label)
                index += 1
        else:
            layout.insertItem(
                layout.indexOf(self._progress_autoclose_row) + 1, spacer,
            )
            index = layout.indexOf(self._progress_flight)
            layout.insertWidget(index, self._progress_details)
            layout.insertWidget(index + 1, self._progress_head)
            layout.insertWidget(layout.indexOf(self._progress_autoclose_row), self._progress_status)
        self._progress_retained_layout = review_only

    def _continue_progress(self) -> None:
        """Continue a saved checkpoint or open review for retained uploads.

        Returns:
            None: Delegates to the exact existing owner without bypassing review.
        """
        state = self.commands.snapshot()
        if state.resume_available:
            self.resume_checkpoint()
        elif (self._pending or state.more_retained
              or state.context_cleanup_failed or state.error):
            self.review()

    def set_context(self, target: str, folder: str) -> None:
        """Update safe target and folder labels supplied by the window owner.

        Args:
            target (str): Sanitized gallery/connection label, or empty when none.
            folder (str): Runtime watched-folder path, or empty when unset.
        Returns:
            None: Updates presentation without reading profile state or doing I/O.
        """
        normalized_folder = folder or gettext('No watched folder selected.')
        if self.folder_display.text() != normalized_folder:
            self._folder_inspection_lane.cancel()
            self.folder_inspection_status.clear()
            self.folder_inspection_status.hide()
        self.target_display.setText(
            gettext('Upload gallery: ') + target if target
            else gettext('Upload gallery: not set'),
        )
        self.folder_display.setText(normalized_folder)

    def replay_capture_target(self) -> None:
        """Send the cached target once after the shell connects its listener.

        Returns:
            None: Replays the last projected value without reading worker state.
        """
        self.capture_target_changed.emit(self._capture_target)

    def replay_watcher_activity(self) -> None:
        """Send the cached Watcher thread state after the shell subscribes.

        Returns:
            None: Replays presentation state without querying the worker owner.
        """
        self.watcher_activity_changed.emit(self._reported_watcher_activity)

    def set_source_disposition(self, delete_uploaded_files: bool | None) -> None:
        """Show the currently verified Watcher source-deletion policy.

        Args:
            delete_uploaded_files (bool | None): Effective preference, or None
                when the owner could not verify its current value.
        Returns:
            None: Shows the Tk-compatible warning only for confirmed deletion.
        """
        self.delete_warning.setVisible(delete_uploaded_files is True)

    def _sync_presence_demand(self) -> None:
        """Demand game probes only for the visible active Qt Watcher surface.

        Returns:
            None: Updates thread-safe demand without querying Qt off-thread.
        """
        if self._presence is None:
            return
        owner = self.window()
        active_window = QApplication.activeWindow()
        interactive = bool(
            active_window is not None
            and (active_window is owner or owner.isAncestorOf(active_window))
        )
        demanded = capture_presence_demanded(
            self.isVisible() or self._photos_context_visible,
            owner.isVisible(), owner.isMinimized(), interactive,
        )
        self._presence.set_active(demanded)
        self._sync_contextual_labels()

    def _capture_text(self, key: str) -> str:
        """Choose Tk-compatible flight wording from cached presence only.

        Args:
            key (str): Flight settings, progress or recording caption key.
        Returns:
            str: Flight-specific text or neutral capture wording outside flight.
        """
        flight = self._presence is not None and self._presence.flight_detected
        return capture_context_text(key, flight, self.language)

    def _sync_contextual_labels(self) -> None:
        """Apply cached simulator context to capture controls and UTC visibility.

        Returns:
            None: Changes captions and presence-gated control visibility only.
        """
        flight = self._presence is not None and self._presence.flight_detected
        if self._flight_context is flight:
            return
        self._flight_context = flight
        self._rendered_controls = None
        self.simbrief_context_changed.emit(flight)
        self.record.setText(self._capture_text('record_flight'))
        progress_caption = self._capture_text('flight_progress')
        self.progress_button.setAccessibleName(progress_caption)
        self.progress_button.setToolTip(progress_caption)
        settings_caption = self._capture_text('flight_settings')
        self.flight_settings_button.setAccessibleName(settings_caption)
        self.flight_settings_button.setToolTip(settings_caption)
        self.flight.setWindowTitle(settings_caption)
        self.flight_heading.setText(settings_caption)
        self.utc_status.setVisible(flight and self._utc_status_active)
        self.utc_row.setVisible(flight)
        self._set_utc_toggle(self._utc_sync_enabled)
        if self._presence is not None:
            self.finish_button.setText(
                self._presence.action_label('finish', self.language),
            )
        else:
            self.finish_button.setText(tr(self.language, 'finish_game'))

    def set_photos_context_visible(self, visible: bool) -> None:
        """Demand cached simulator detection while linked Photos is presented.

        Args:
            visible (bool): Whether the linked Photos workspace is visible.
        Returns:
            None: Starts or suspends the shared observer under existing visibility guards.
        """
        self._photos_context_visible = visible
        self._sync_presence_demand()

    def simbrief_available(self) -> bool:
        """Read cached SimBrief context without starting a native probe.

        Returns:
            bool: True only for the current cached Flight Simulator context.
        """
        return self._presence is not None and self._presence.flight_detected

    def set_linked_upload_selected(self, selected: bool) -> None:
        """Update explicit linked-mode selection from the connection owner.

        Args:
            selected (bool): True when a live target or saved linked mode is selected.
        Returns:
            None: Refreshes lifecycle actions without polling saved state from the UI timer.
        """
        if (self._linked_upload_selection_known
                and self._linked_upload_selected == selected):
            return
        self._linked_upload_selection_known = True
        self._linked_upload_selected = selected
        self._rendered_controls = None
        self._notify_import_actions(self.commands.snapshot(), not self.protected())
        self._refresh_action_visibility(self.commands.snapshot())

    def _perform_idle_connection_action(self) -> None:
        """Recheck existing admission before opening connection setup or reconnect.

        Returns:
            None: Emits only the action authorized by the current cached mode.
        """
        if not self._linked_upload_selection_known:
            return
        action = capture_idle_connection_action(
            self.commands.snapshot(), self._busy, self._pending,
            self.protected(), self._linked_upload_selected,
        )
        if action == 'begin':
            self.begin_connection_requested.emit()
        elif action == 'reconnect':
            self.reconnect_gallery_requested.emit()

    def _refresh_action_visibility(self, state: CaptureStatus) -> None:
        """Refresh the one idle connection action alongside recovery controls.

        Args:
            state (CaptureStatus): Immutable state currently rendered by the panel.
        Returns:
            None: Shows Begin/Reconnect only while the current owners are idle.
        """
        action = capture_idle_connection_action(
            state, self._busy, self._pending, self.protected(),
            self._linked_upload_selected,
        ) if self._linked_upload_selection_known else None
        if action == 'begin':
            caption = (
                self._presence.action_label('begin', self.language)
                if self._presence is not None
                else tr(self.language, 'begin_game')
            )
        elif action == 'reconnect':
            caption = tr(self.language, 'reconnect_upload_gallery')
        else:
            caption = ''
        if self.connection_action_button.text() != caption:
            self.connection_action_button.setText(caption)
            self.connection_action_button.setAccessibleName(caption)
        self.connection_action_button.setVisible(bool(caption))
        self.connection_action_button.setEnabled(
            bool(caption) and not self._busy and not self.protected(),
        )

    def photos_simbrief_action(self) -> None:
        """Open the shared SimBrief prompt from the linked Photos context.

        Returns:
            None: Loads or detaches only through the existing capture owner.
        """
        if not self.simbrief_available():
            return
        state = self.commands.snapshot()
        if self._busy or self.protected() or state.finishing:
            self.dispatch_status_changed.emit(gettext('Wait for the current operation to finish.'))
            return
        self.toggle_dispatch(parent=self.window())

    def busy(self) -> bool:
        """Return whether the view or its owner still guards accepted work.

        Returns:
            bool: True while a command, recorder, finalizer or transport is active.
        """
        return self._busy or self.commands.snapshot().alive

    def import_capture_actions(
        self,
    ) -> tuple[str, Callable[[], None] | None, str, Callable[[], None] | None]:
        """Project Tk's live lifecycle and legacy abandon actions into Import.

        Returns:
            tuple[str, Callable[[], None] | None, str, Callable[[], None] | None]:
                Primary caption/command and optional secondary cleanup action.
        """
        state = self.commands.snapshot()
        if self._watch_start_requested or state.watcher_starting:
            primary = (gettext('Starting Watcher…'), None)
        elif self._busy or state.finishing or state.alive and not state.capturing:
            primary = (self.progress_button.toolTip(), self.show_progress)
        elif state.capturing:
            primary = (self.finish_button.text(), self.finish)
        elif (self._pending or state.more_retained or state.resume_available
              or state.error or state.context_cleanup_failed):
            primary = (self.review_button.text(), self.review)
        elif self._linked_upload_selection_known:
            idle_action = capture_idle_connection_action(
                state, self._busy, self._pending, self.protected(),
                self._linked_upload_selected,
            )
            if idle_action == 'begin':
                primary = (
                    self._capture_text('begin_flight'),
                    self._perform_idle_connection_action,
                )
            elif idle_action == 'reconnect':
                primary = (
                    tr(self.language, 'reconnect_upload_gallery'),
                    self._perform_idle_connection_action,
                )
            else:
                primary = ('', None)
        else:
            primary = ('', None)
        can_abandon = bool(
            not state.finishing and not self._busy
            and not (state.alive and not state.capturing)
            and state.legacy_mode
            and (state.capturing or self._pending or state.more_retained)
        )
        secondary = (
            (self._presence.text('abandon', self.language) if self._presence is not None
             else tr(self.language, 'abandon'), self.abandon_capture)
            if can_abandon else ('', None)
        )
        return primary[0], primary[1], secondary[0], secondary[1]

    def _notify_import_actions(self, state: CaptureStatus, permitted: bool) -> None:
        """Publish only lifecycle changes that can alter Import actions.

        Args:
            state (CaptureStatus): Current immutable capture projection.
            permitted (bool): Whether the existing command lane is open.
        Returns:
            None: Avoids repainting Import controls for telemetry-only samples.
        """
        signature = (
            self._busy, permitted, self._pending, self._flight_context,
            self._linked_upload_selection_known, self._linked_upload_selected,
            state.watcher_starting, state.capturing, state.finishing,
            state.alive, bool(state.error),
            state.more_retained, state.resume_available,
            state.context_cleanup_failed, state.legacy_mode,
        )
        if signature != self._import_action_state:
            self._import_action_state = signature
            self.capture_actions_changed.emit()

    def _set_utc_toggle(self, enabled: bool) -> None:
        """Reflect the last owner-confirmed UTC preference without recursion.

        Args:
            enabled (bool): Saved state returned by the capture owner.
        Returns:
            None: Updates only the Qt switch presentation.
        """
        if self.utc_toggle.isChecked() == enabled:
            return
        blocker = QSignalBlocker(self.utc_toggle)
        self.utc_toggle.setChecked(enabled)
        del blocker

    def _change_utc_sync(self, enabled: bool) -> None:
        """Submit UTC preference changes to the existing capture owner.

        Args:
            enabled (bool): User-selected UTC correction opt-in.
        Returns:
            None: Restores the previous value on guarded or failed saves.
        """
        state = self.commands.snapshot()
        if self._busy or self.protected() or state.finishing:
            self._set_utc_toggle(self._utc_sync_enabled)
            self.status.setText(gettext('Wait for the current operation to finish.'))
            return
        self._previous_utc_sync = self._utc_sync_enabled
        self._pending_utc_sync = enabled
        self._submit(lambda: CaptureUtcPreferenceResult(
            self.commands.set_utc_sync(enabled),
        ))
        if not self._busy:
            self._pending_utc_sync = None
            self._utc_sync_enabled = bool(self._previous_utc_sync)
            self._previous_utc_sync = None
            self._set_utc_toggle(self._utc_sync_enabled)

    def start(self) -> None:
        """Admit a new capture only through the current profile owner.

        Returns:
            None: Schedules an explicitly requested Start.
        """
        if self._busy or self.protected():
            return
        self._watch_start_requested = True
        self._submit(self.commands.start)
        if not self._busy:
            self._watch_start_requested = False
            self._poll()

    def finish(self) -> None:
        """Give the user control over the original Finish cutoff.

        Returns:
            None: Starts graceful finalization after confirmation.
        """
        if self._confirm(self.finish_button.text(), tr(self.language, 'finish_preparing_detail')):
            self._submit(self.commands.finish)
            if self._busy:
                self.show_progress()

    def revoke_legacy(self, protected: Callable[[], bool]) -> bool:
        """Request the existing destructive key action only after confirmation.

        Args:
            protected (Callable[[], bool]): Extra shell update and AI admission fence.
        Returns:
            bool: True when the operator confirmed and the exact command was accepted.
        """
        state = self.commands.snapshot()
        if (self._busy or self.protected() or protected()
                or state.finishing or state.revocation_cleanup_pending):
            self.status.setText(gettext('Wait for the current operation to finish.'))
            return False
        if not self._confirm(tr(self.language, 'finish_revoke'), tr(self.language, 'confirm_revoke')):
            return False
        if self._busy or self.protected() or protected() or self.commands.snapshot().finishing:
            self.status.setText(gettext('Wait for the current operation to finish.'))
            return False
        self._submit(self.commands.revoke_legacy)
        return True

    def retry_legacy_revocation_cleanup(self) -> None:
        """Complete only the original server-confirmed local persistence handoff.

        Returns:
            None: Refreshes both workspaces after the saved key was actually cleared.
        """
        if (self._busy or self.protected() or self._legacy_revocation is None
                or self.commands.snapshot().finishing):
            return
        operation = self._legacy_revocation
        self._awaiting_revocation_cleanup = False
        self._submit(lambda: self.commands.complete_legacy_revocation(operation))

    def abandon_capture(self) -> None:
        """Confirm local retention or retry the exact legacy key cleanup.

        Returns:
            None: Leaves original files and closes the selected capture cohort.
        """
        state = self.commands.snapshot()
        if (self._busy or self.protected() or state.finishing
                or state.revocation_cleanup_pending):
            self.status.setText(gettext('Wait for the current operation to finish.'))
            return
        if state.abandon_cleanup_pending:
            self._submit(self.commands.complete_legacy_abandon)
            return
        if (not (state.legacy_mode or state.linked_mode)
                or not (state.capturing or self._pending or state.more_retained)):
            self.status.setText(recovery_text(self.language, 'watch_recovery_close_failed'))
            return
        if not self._confirm(
            tr(self.language, 'keep_watched_files' if state.linked_mode else 'abandon'),
            tr(self.language, 'confirm_keep_watched_files' if state.linked_mode else 'confirm_abandon'),
        ):
            return
        self._submit(self.commands.abandon_capture)

    def interrupt(self) -> None:
        """Ask before retaining the original queue and ending active acquisition.

        Returns:
            None: Schedules an explicit retained Stop.
        """
        if self._confirm(self.interrupt_button.text(), recovery_text(self.language, 'watch_stop_stopping')):
            self._pending = True
            self._submit(self.commands.interrupt)

    def force_finish(self) -> None:
        """Release a captured Finish only after terminal markers are verified.

        Returns:
            None: Preserves every original source and keeps active writers fenced.
        """
        state = self.commands.snapshot()
        if (self._busy or self.protected() or not state.force_finish_available
                or state.revocation_cleanup_pending or state.abandon_cleanup_pending):
            return
        self._submit(self.commands.force_finish)

    def refresh(self) -> None:
        """Coalesce discovery while preserving existing retry deadlines.

        Returns:
            None: Requests a rescan through the live worker.
        """
        self._submit(self.commands.refresh)

    def load_dispatch(self) -> None:
        """Toggle the staged or active OFP using the remembered identity.

        Returns:
            None: Reuses saved identity or asks once when no identity is stored.
        """
        self.toggle_dispatch(parent=self)

    def toggle_dispatch(self, parent: QWidget | None = None) -> None:
        """Load or detach SimBrief through the current capture owner.

        Args:
            parent (QWidget | None): Visible native owner for an initial prompt.
        Returns:
            None: Keeps saved identity and plan changes in the existing service.
        """
        if self._busy or self.protected():
            return
        state = self.commands.snapshot()
        if state.finishing:
            self.dispatch_status.setText(gettext('Wait for the current operation to finish.'))
            return
        if state.dispatch_route:
            self.clear_dispatch()
            return
        identity = self._dispatch_identity(parent or self)
        if identity is not None:
            self._submit(lambda: self.commands.load_dispatch(identity), dispatch=True)

    def change_dispatch_identity(self) -> None:
        """Replace the remembered identity and fetch its explicit OFP.

        Returns:
            None: The saved identity changes only after the provider load succeeds.
        """
        state = self.commands.snapshot()
        if self._busy or self.protected() or state.finishing:
            self.dispatch_status.setText(gettext('Wait for the current operation to finish.'))
            return
        identity = self._dispatch_identity(self, replace_existing=True)
        if identity is not None:
            self._submit(lambda: self.commands.load_dispatch(identity), dispatch=True)

    def _dispatch_identity(
        self, parent: QWidget, *, replace_existing: bool = False,
    ) -> str | None:
        """Reuse the successful identity or request one validated replacement.

        Args:
            parent (QWidget): Visible native parent for the prompt.
            replace_existing (bool): Prompt even when a successful identity exists.
        Returns:
            str | None: Validated identity, or None after cancellation/invalid input.
        """
        saved = self.commands.dispatch_identity().strip()
        if saved and not replace_existing:
            return saved
        identity, accepted = QInputDialog.getText(
            parent, 'SimBrief', tr(self.language, 'simbrief_identity'),
            QLineEdit.EchoMode.Normal, saved,
        )
        if not accepted:
            return None
        identity = identity.strip()
        try:
            identity_query(identity)
        except ValueError:
            QMessageBox.warning(
                parent, 'SimBrief', tr(self.language, 'simbrief_identity_invalid'),
            )
            return None
        return identity

    def _prompt_dispatch(self, parent: QWidget) -> None:
        """Request the remembered identity or prompt before shared-client fetch.

        Args:
            parent (QWidget): Visible native window that owns the prompt.
        Returns:
            None: Schedules the same guarded DesktopCapture command as Flight UI.
        """
        identity = self._dispatch_identity(parent)
        if identity is not None:
            self._submit(lambda: self.commands.load_dispatch(identity), dispatch=True)

    def clear_dispatch(self) -> None:
        """Detach the staged or actively recorded flight plan explicitly.

        Returns:
            None: Updates current route presentation after service confirmation.
        """
        self._submit(self.commands.clear_dispatch, dispatch=True)
        self.dispatch_status.clear()

    def attach_dispatch(self) -> None:
        """Choose one archived OFP for the exact selected saved flight.

        Returns:
            None: Schedules bounded local parsing or describes missing selection.
        """
        flight = self.selected_flight()
        if flight is None:
            self.dispatch_status.setText(tr(self.language, 'select_saved_flight'))
            return
        path, _filter = QFileDialog.getOpenFileName(
            self, tr(self.language, 'simbrief_file'), '', 'SimBrief OFP (*.json *.xml)',
        )
        if path:
            self._submit(lambda: self.commands.attach_dispatch(flight, Path(path)), dispatch=True)

    def load_saved_dispatch(self, flight: Path | None = None) -> None:
        """Fetch SimBrief and attach it to the exact saved-flight selection.

        Args:
            flight (Path | None): Captured selection, or the current saved row.
        Returns:
            None: The existing command lane performs bounded network and disk work.
        """
        selected = flight or self.selected_flight()
        if selected is None:
            self.dispatch_status.setText(tr(self.language, 'select_saved_flight'))
            return
        if self._busy or self.protected():
            self.dispatch_status.setText(gettext('Wait for the current operation to finish.'))
            return
        identity = self._dispatch_identity(self.window())
        if identity is None:
            return
        self.dispatch_status.setText(tr(self.language, 'simbrief_loading'))
        self._submit(
            lambda: SavedDispatchResult(
                self.commands.load_saved_dispatch(selected, identity),
            ),
            dispatch=True,
        )

    def close_retained(self) -> None:
        """Confirm and close the existing retained capture locally.

        Returns:
            None: Original photos, reports and history remain available on disk.
        """
        if self._confirm(
            self.close_button.text(), recovery_text(self.language, 'watch_recovery_confirm_close'),
        ):
            self._submit(self.commands.close_retained)

    def reset_watcher(self) -> None:
        """Ask before discarding Watcher-only selection and queue journals.

        Returns:
            None: Confirmation owns the same durable reset and originals policy.
        """
        if self._confirm(gettext('Watcher factory reset'), gettext(
            "Stop Watcher and permanently forget its upload gallery, watched "
            "folder, queued work, and restart history? Watcher preferences "
            "will return to their defaults.\n\n"
            "You will keep your screenshots, local reports, logs, and other "
            "tabs' settings. An upload already sent may still reach the gallery.",
        )):
            self.resume_reset()

    def resume_reset(self) -> None:
        """Finish a newly confirmed or previously persisted Watcher reset.

        Returns:
            None: Waits for the original pipeline and retains unrelated state.
        """
        if not self._busy and not self.protected():
            self._reset_requested = True
            self._submit(self.commands.reset_watcher)

    def can_checkpoint(self) -> bool:
        """Check whether this capture can safely pause for an ordinary restart.

        Returns:
            bool: The current photo and recorder owner supports a restart checkpoint.
        """
        return not self._busy and not self.protected() and self.commands.snapshot().can_checkpoint

    def checkpoint_restart(self, *, relaunch: bool = True) -> None:
        """Honor a shell exit or restart after checkpointing accepted work.

        Args:
            relaunch (bool): Start the selected desktop after a confirmed restart.
        Returns:
            None: Dispatch remains with the original worker until its safe pause.
        """
        if self.can_checkpoint():
            self._submit(self.commands.checkpoint_restart)
            if self._busy:
                self._checkpoint_relaunch = relaunch

    def resume_checkpoint(self) -> None:
        """Start the exact paused recording once its persisted consent verifies.

        Returns:
            None: Retains the original workspace when authority is unavailable.
        """
        self._submit(self.commands.resume_checkpoint)

    def retry_context_cleanup(self) -> None:
        """Retry the existing temporary key closure after the capture stopped.

        Returns:
            None: Keeps the original server access owner through verification.
        """
        if not self.protected() and not self.busy():
            self._submit(self.commands.retry_context_cleanup)

    def review(self) -> None:
        """Open explicit review using the original queue and current target.

        Returns:
            None: Restores the accepted owner if needed and prepares evidence.
        """
        if self.commands.snapshot().alive:
            self._submit(self._read_review)
        else:
            self._queue_available = False
            self._review_after_queue = True
            self._submit(self.commands.recover)

    def _read_review(self) -> CaptureResult | None:
        """Decode a bounded local preview away from the event loop.

        Returns:
            CaptureResult | None: The immutable queue generation and owned pixels,
                a safe status caption, or None while the worker is starting.
        """
        review = self.commands.review()
        if review is None:
            return recovery_text(self.language, 'watch_recovery_clear')
        image = None
        if review.preview is not None:
            try:
                image, _geometry = decode_photo(review.preview, thumbnail=True)
            except ValueError:
                pass
        return replace(review, preview=None), image

    def _confirm(self, caption: str, detail: str) -> bool:
        """Require an explicit affirmative choice for a state-changing command.

        Args:
            caption (str): Localized action title.
            detail (str): Localized information about the exact effect.
        Returns:
            bool: The operator explicitly chose Yes.
        """
        return QMessageBox.question(
            self, caption, detail, QMessageBox.StandardButton.Yes | QMessageBox.StandardButton.No,
            QMessageBox.StandardButton.No,
        ) == QMessageBox.StandardButton.Yes

    def _submit(self, command: Callable[[], CaptureResult | None], *, dispatch: bool = False) -> None:
        """Run the next admitted operator command on one lane.

        Args:
            command (Callable[[], CaptureResult | None]): Profile-bound command.
            dispatch (bool): Present the result with the Flight OFP controls.
        Returns:
            None: The command runs off the UI thread, with no queued duplicate.
        """
        if self._busy or self.protected():
            return
        self._busy = True
        self._dispatch_feedback = dispatch
        self._poll()

        def run(_cancelled: Event, publish: Callable[[CaptureResult], None]) -> None:
            """Deliver sanitized command results on the GUI thread.

            Args:
                _cancelled (Event): Lane lifetime fence.
                publish (Callable[[CaptureResult], None]): Queue one result.
            Returns:
                None: Publishes a result when available after command completion.
            """
            try:
                result = command()
            except (OSError, RuntimeError, ValueError) as error:
                result = self.sanitize(str(error))
            if result is not None:
                publish(result)

        self._lane.submit(run)

    def _receive(self, result: CaptureResult) -> None:
        """Present a command result on the current GUI thread.

        Args:
            result (CaptureResult): Sanitized status, key-cleanup result, or exact recovery evidence.
        Returns:
            None: Updates status or opens one native review dialog.
        """
        if isinstance(result, CaptureUtcPreferenceResult):
            self._utc_sync_enabled = result.enabled
            self._pending_utc_sync = None
            self._previous_utc_sync = None
            self._set_utc_toggle(result.enabled)
        elif type(result) is bool:
            if result:
                self._legacy_revocation = None
                self.credentials_changed.emit()
        elif isinstance(result, str):
            self._review_after_queue = False
            label = self.dispatch_status if self._dispatch_feedback else self.status
            label.setText(gettext(result))
            if self._dispatch_feedback:
                self.dispatch_status_changed.emit(gettext(result))
            if self._pending_utc_sync is not None:
                self._pending_utc_sync = None
                self._utc_sync_enabled = bool(self._previous_utc_sync)
                self._previous_utc_sync = None
                self._set_utc_toggle(self._utc_sync_enabled)
        elif isinstance(result, SavedDispatchResult):
            self.dispatch_status.setText(
                tr(self.language, 'simbrief_loaded') + '\n' + gettext(result.caption),
            )
            self.saved_dispatch_attached.emit()
        else:
            self._show_review(*result)

    def _show_review(self, review: CaptureReview, image: QImage | None) -> None:
        """Show only the decisions applicable to this exact generation.

        Args:
            review (CaptureReview): Pinned head identity and proven options.
            image (QImage | None): Bounded local preview of the pinned source.
        Returns:
            None: Opens a modal native dialog with explicit decision buttons.
        """
        language = self.language
        title = recovery_text(language, 'watch_recovery_title')
        head = review.snapshot
        dialog = QDialog(self)
        dialog.setAttribute(Qt.WidgetAttribute.WA_DeleteOnClose)
        dialog.setWindowTitle(title)
        layout = QVBoxLayout(dialog)
        text = recovery_text(language, 'watch_recovery_local').format(
            sequence=head.sequence, name=Path(head.source_path).name,
            count=head.pending_count, target=head.target_binding,
        )
        if head.review_blocked_by_earlier_upload:
            text += '\n' + recovery_text(language, 'watch_recovery_prior_identity').format(
                marker=head.prior_blocker_marker, name=Path(head.prior_blocker_path).name,
                digest=head.prior_blocker_sha256, target=head.prior_blocker_target_binding,
            )
        detail = QLabel(text)
        detail.setTextFormat(Qt.TextFormat.PlainText)
        detail.setWordWrap(True)
        layout.addWidget(detail)
        preview = QLabel(recovery_text(language, 'watch_recovery_local_unavailable'))
        if image is not None:
            preview.setPixmap(QPixmap.fromImage(image))
        layout.addWidget(preview)

        def decide(decision: CaptureDecision, caption: str) -> None:
            """Return the chosen decision together with the displayed evidence.

            Args:
                decision (CaptureDecision): Exact requested disposition.
                caption (str): Localized selected action.
            Returns:
                None: The coordinator revalidates source, context and generation.
            """
            warning = recovery_text(language, 'watch_recovery_approve_hint')
            if self._confirm(caption, warning if decision == 'approve' else caption):
                dialog.accept()
                self._submit(lambda: self.commands.decide(review, decision))

        options: tuple[tuple[CaptureDecision, str, bool], ...] = (
            ('receipt', 'watch_recovery_confirm', review.can_receipt),
            ('approve', 'watch_recovery_approve_upload', review.can_approve and image is not None),
            ('earlier', 'watch_recovery_review_earlier', head.review_blocked_by_earlier_upload),
            ('continue', 'watch_recovery_resume_pending', head.retained_stop and
             not head.review_blocked_by_earlier_upload and not (head.state == 'retry_wait'
             and head.phase == 'source') and head.state in (
                 'observed', 'stabilizing', 'preparing', 'inventory', 'retry_wait',
             )),
            ('skip', 'watch_recovery_skip', True),
        )
        for decision, key, enabled in options:
            if enabled:
                caption = recovery_text(language, key)
                layout.addWidget(ActionButton(caption, lambda d=decision, s=caption: decide(d, s)))
        layout.addWidget(ActionButton(recovery_text(language, 'watch_recovery_cancel'), dialog.reject))
        self._review_dialog = dialog
        dialog.finished.connect(lambda _result: setattr(self, '_review_dialog', None))
        dialog.open()

    def _restore_preferences(self) -> None:
        """Reflect committed preferences without resubmitting them.

        Returns:
            None: Rechecks all current Flight switches.
        """
        pairs = ((self.record, self._preferences.recording),
                 (self.reports, self._preferences.reports),
                 *((button, code in self._preferences.languages) for code, button in self.editions.items()))
        for control, enabled in pairs:
            with QSignalBlocker(control):
                control.setChecked(enabled)

    def _save_preferences(self, _checked: bool) -> None:
        """Publish the whole Flight choice through its current workspace owner.

        Args:
            _checked (bool): Native toggle signal for an individual choice.
        Returns:
            None: Commits accepted controls and current live acquisition gating.
        """
        preferences = replace(
            self._preferences, recording=self.record.isChecked(), reports=self.reports.isChecked(),
            languages=tuple(code for code, choice in self.editions.items() if choice.isChecked()),
        )
        self._submit(lambda: self.commands.set_preferences(preferences))

    def _finished(self, failure: str) -> None:
        """Release the command gate and refresh the accepted profile controls.

        Args:
            failure (str): Sanitized exception category or empty completion.
        Returns:
            None: Allows the next independent user operation.
        """
        relaunch, self._checkpoint_relaunch = self._checkpoint_relaunch, None
        self._busy = False
        self._watch_start_requested = False
        if failure:
            label = self.dispatch_status if self._dispatch_feedback else self.status
            label.setText(recovery_text(self.language, 'watch_recovery_decision_unexpected'))
        self._dispatch_feedback = False
        self._preferences = FlightPreferences.load(self.root)
        self._restore_preferences()
        state = self.commands.snapshot()
        if (self._progress_dialog is not None and self._progress_dialog.isVisible()
                and self._retained_only(state)):
            self._withdraw_retained_summary()
            self._retained_summary = None
            self._retained_summary_failure = False
        if not state.alive and not self.protected():
            self.commands.close()
        self._poll()
        if relaunch is not None and state.restart_saved and not state.alive:
            self.restart_ready.emit(relaunch)
        if self._reset_requested:
            self._reset_requested = False
            if state.phase == 'reset':
                self.reset_finished.emit()

    def _poll(self) -> None:
        """Render ordered events and scalar live state without network I/O.

        Returns:
            None: Updates responsive buttons, phase, queue and Flight controls.
        """
        self._sync_presence_demand()
        for _ in range(CAPTURE_UI_EVENT_BATCH):
            try:
                event = self.commands.events.get_nowait()
            except Empty:
                break
            if isinstance(event, tuple):
                level, message = event
                safe = self.sanitize(message)
                self.status.setText(gettext(safe))
                self.activity.emit(level, safe)
            elif event.get('kind') == 'api_key_revoked':
                payload = event.get('payload')
                operation = payload.get('operation') if isinstance(payload, dict) else None
                if isinstance(operation, FinalizationWorker):
                    self._legacy_revocation = operation
                    self._awaiting_revocation_cleanup = True
            elif event.get('kind') == 'watch_queue':
                detail = event.get('payload')
                if isinstance(detail, dict):
                    self._queue_available = True
                    count = detail.get('queued_count')
                    if type(count) is int:
                        self._header_count = str(count)
                    ignored = detail.get('ignored_at_startup', detail.get('ignored'))
                    if type(ignored) is int and ignored >= 0:
                        self._ignored_startup = str(ignored)
                    scanned = detail.get('last_scan')
                    if type(scanned) in (int, float) and scanned > 0:
                        self._header_scan = time.strftime('%H:%M', time.localtime(scanned))
                    self._queue_backpressure = (
                        (type(count) is int and count > 0)
                        or detail.get('state') in ('retry_wait', 'uncertain', 'auth_required', 'blocked')
                        or bool(detail.get('reason'))
                    )
                    if detail.get('head_id'):
                        self.head.setText(recovery_text(self.language, 'watch_recovery_local').format(
                            sequence=detail.get('head_sequence'), name=detail.get('head_file'),
                            count=detail.get('queued_count'),
                            target=gettext(self.sanitize(str(detail.get('reason') or ''))),
                        ))
                    else:
                        self.head.clear()
                    self.watch_details.setText(
                        gettext('Ignored at startup: {value}').format(
                            value=self._ignored_startup,
                        ) + '   ' + gettext('Last scan: ') + self._header_scan,
                    )
        if not self._busy:
            if self._awaiting_revocation_cleanup:
                self.retry_legacy_revocation_cleanup()
            self.commands.reconcile_workers(retire_context=not self.protected())
        state = self.commands.snapshot()
        watcher_starting = self._watch_start_requested or state.watcher_starting
        start_caption = (
            gettext('Starting Watcher…') if watcher_starting
            else gettext('Start Watcher')
        )
        if self.start_button.text() != start_caption:
            self.start_button.setText(start_caption)
            self.start_button.setAccessibleName(start_caption)
        self.watcher_start_progress.setVisible(watcher_starting)
        if state.watcher_worker_active != self._reported_watcher_activity:
            self._reported_watcher_activity = state.watcher_worker_active
            self.watcher_activity_changed.emit(self._reported_watcher_activity)
        if state.capture_target != self._capture_target:
            self._capture_target = state.capture_target
            self.capture_target_changed.emit(self._capture_target)
        if state.phase in ('completed', 'closed', 'reset'):
            self._pending = state.more_retained
            if state.phase in ('closed', 'reset'):
                self.head.clear()
        elif state.error or state.more_retained or state.resume_available:
            self._pending = True
        if state.alive:
            self._was_alive = True
        elif self._was_alive and not self._busy and not self.protected():
            self._was_alive = False
            self.commands.close()
            self.finished.emit()
        if self._review_after_queue and not self._busy and (self._queue_available or not state.alive):
            self._review_after_queue = False
            self._submit(self._read_review)
        busy = self._busy or state.alive
        if not state.alive:
            self._queue_backpressure = False
        if self._reported_queue_waiting != self._queue_backpressure:
            self._reported_queue_waiting = self._queue_backpressure
            self.queue_waiting.emit(self._queue_backpressure)
        if self._reported_busy != busy:
            self._reported_busy = busy
            self.busy_changed.emit(busy)
        permitted = not self._busy and not self.protected()
        stage = ('finishing' if state.finishing else 'running' if state.capturing
                 else 'starting' if self._busy else 'pending' if self._pending else 'stopped')
        header = (self._header_count, self._header_scan, stage, permitted and state.capturing)
        if self._header_state != header:
            self._header_state = header
            self.header_changed.emit(*header)
        controls = (
            state, permitted, self._pending,
            self._linked_upload_selection_known, self._linked_upload_selected,
        )
        self._notify_import_actions(state, permitted)
        if self._rendered_controls == controls:
            self._refresh_progress_dialog(state)
            return
        self._rendered_controls = controls
        if self._pending_utc_sync is None and self._utc_sync_enabled != state.utc_enabled:
            self._utc_sync_enabled = state.utc_enabled
            self._set_utc_toggle(state.utc_enabled)
        self.utc_toggle.setEnabled(permitted and not state.finishing)
        telemetry_caption = self._telemetry_caption(state.telemetry)
        if self.flight_preview.text() != telemetry_caption:
            self.flight_preview.setText(telemetry_caption)
        utc_caption = utc_status_caption(state.utc)
        if self.utc_status.text() != utc_caption:
            self.utc_status.setText(utc_caption)
        self._utc_status_active = bool(state.utc) and state.utc != 'UTC sync stopped'
        self.utc_status.setVisible(
            bool(self._flight_context) and self._utc_status_active,
        )
        if watcher_starting:
            watcher_status = gettext('Starting Watcher…')
        elif state.capturing:
            watcher_status = gettext('Watcher is still running')
            if state.recording:
                watcher_status += ' · ' + gettext('● REC')
        elif state.finishing:
            watcher_status = tr(self.language, 'phase_' + state.phase)
        elif state.recording:
            watcher_status = gettext('● REC')
        elif state.phase == 'closed':
            watcher_status = recovery_text(self.language, 'watch_recovery_closed')
        elif state.phase == 'reset':
            watcher_status = gettext('Watcher reset complete. Choose a connection and folder to start.')
        elif self._pending:
            watcher_status = recovery_text(self.language, 'capture_recovery_paused')
        else:
            watcher_status = gettext('Watcher stopped')
        if self.watcher_state.text() != watcher_status:
            self.watcher_state.setText(watcher_status)
        retained_only = self._retained_only(state)
        self.flight_preview.setVisible(not retained_only)
        self.start_button.setEnabled(
            permitted and not state.alive and not self._pending and not state.context_cleanup_failed,
        )
        self.retry_access_button.setVisible(state.context_cleanup_failed)
        self.retry_access_button.setEnabled(permitted and not state.alive)
        self.retry_revocation_button.setVisible(state.revocation_cleanup_pending)
        self.retry_revocation_button.setEnabled(
            permitted and not state.finishing and self._legacy_revocation is not None,
        )
        self.finish_button.setEnabled(permitted and not state.finishing and (state.alive or self._pending))
        self.interrupt_button.setEnabled(permitted and state.interrupt_available)
        self.finish_button.setVisible(state.alive or self._pending)
        self.interrupt_button.setVisible(state.interrupt_available)
        self.refresh_button.setEnabled(permitted and state.capturing)
        self.review_button.setEnabled(permitted and (state.alive or self._pending))
        self.review_button.setVisible(state.alive or self._pending)
        self._refresh_action_visibility(state)
        self.close_button.setVisible(self._pending)
        self.close_button.setEnabled(permitted and not state.alive)
        self._recovery_row.setVisible(
            self._pending or state.context_cleanup_failed
            or state.revocation_cleanup_pending,
        )
        self.reset_button.setEnabled(permitted)
        self.start_button.setVisible(not state.resume_available)
        self.resume_button.setVisible(state.resume_available)
        self.resume_button.setEnabled(permitted and not state.alive)
        if state.resume_available:
            self.review_button.setEnabled(False)
            self.finish_button.setEnabled(False)
        for choice in (self.record, self.reports, *self.editions.values()):
            choice.setEnabled(permitted and not state.finishing)
        simbrief_caption = (
            tr(self.language, 'simbrief_loading_short')
            if self._dispatch_feedback and self._busy
            else tr(self.language, 'simbrief_unload')
            if state.dispatch_route
            else tr(self.language, 'simbrief_load')
        )
        if self.simbrief_button.text() != simbrief_caption:
            self.simbrief_button.setText(simbrief_caption)
        self.simbrief_button.setEnabled(permitted and not state.finishing)
        self.simbrief_identity_button.setEnabled(permitted and not state.finishing)
        self.attach_dispatch_button.setEnabled(permitted and not state.finishing)
        details: list[str] = []
        if state.error:
            details.append(gettext(self.sanitize(state.error)))
        if state.dispatch_route:
            details.append(state.dispatch_route)
        if details:
            self.status.setText(' · '.join(details))
        # Empty notices leave room for the recording controls and deletion warning.
        for label in (self.status, self.head):
            label.setVisible(bool(label.text()))
        self.instrument.setText(telemetry_caption)
        self._refresh_progress_dialog(state)
        if self._progress_dialog is not None and self._progress_dialog.isVisible():
            if retained_only:
                self._request_retained_summary(state)
            else:
                self._retained_summary_lane.cancel()
                self._retained_summary_requested = False

    def close_view(self) -> None:
        """Stop UI work and release the idle Flight workspace lease.

        Returns:
            None: Retires the capture view before the application exits.
        """
        self._timer.stop()
        if self._presence is not None:
            self._presence.set_active(False)
        self.flight.hide()
        if self._review_dialog is not None:
            self._review_dialog.reject()
        if self._progress_dialog is not None:
            self._progress_dialog.hide()
        self._progress_previous_focus = None
        if self._progress_container is not None:
            self._progress_container.removeEventFilter(self)
            self._progress_container = None
        self._folder_inspection_lane.close()
        self._retained_summary_lane.close()
        self._lane.close()
        if not self.busy():
            self.commands.close()
