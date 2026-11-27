# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_import.py
# Module Type: Qt manual-import presentation
# Purpose: Present explicit preflight review and the existing Import worker.
# Responsibilities: Own native file selection, progress, cancellation, and
#   worker-generation fencing without owning FIFO or upload policy.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Project the shared manual-import lifecycle into native Qt controls."""

from dataclasses import dataclass, replace
from pathlib import Path
from queue import Empty, Queue
from threading import Event
from typing import Callable, cast

from PySide6.QtCore import Qt, QTimer, Signal
from PySide6.QtWidgets import (
    QAbstractItemView, QButtonGroup, QFileDialog, QFrame, QHBoxLayout,
    QHeaderView, QLabel, QProgressBar, QRadioButton, QSizePolicy, QTableView,
    QVBoxLayout, QWidget,
)

from .import_commands import (
    ImportActions, ImportApproval, ImportEvents, ImportSource, ImportWorkerPort,
    PreflightWorkerPort, import_approval,
)
from .gallery_information import format_gallery_bytes
from .import_summary import format_preflight_plan_summary
from .import_ui_snapshot import (
    ImportItemView, ImportProgressSnapshot, ImportWorkerGeneration,
    new_worker_generation,
)
from .flight_report_translations import tr
from .localization import current_language, gettext
from .models import ImportPlan, RECOVERABLE_ITEM_STATES
from .qt_operator_views import OperatorTable, TableRow
from .qt_read_lane import QtReadLane
from .qt_theme import ActionButton, ToggleSwitch
from .ui_layout import WORKSPACE_ACTION_HEIGHT, WORKSPACE_CARD_INSETS

# Purpose: Bound the local worker bridge while preserving FIFO events.
# Type: int. Units: events. Scope: one manual Import generation.
# Consumers: QtImport._events and QtImport._observe.
# Rationale: 64 retains the stable desktop's event drain count budget.
IMPORT_EVENT_LIMIT = 64
# Purpose: Bound observation time for shared local worker events.
# Type: int. Units: milliseconds. Scope: one live manual Import view.
# Consumers: QtImport._observe.
# Rationale: 100 matches the stable import presentation interval.
IMPORT_OBSERVE_MS = 100
# Purpose: Bound rows initially populated from preflight review.
# Type: int. Units: rows. Scope: current job presentation.
# Consumers: QtImport._show_plan.
# Rationale: 1,000 matches the stable Import item table and progress snapshots.
IMPORT_REVIEW_ROWS = 1000
# Purpose: Preserve the stable full-width manual Import progress trough.
# Type: int. Units: logical pixels. Scope: native Import progress bars.
# Consumers: QtImport.__init__. Rationale: 18 matches the Tk card's idle bar.
IMPORT_PROGRESS_HEIGHT = 18
# Purpose: Keep the reference separation between labels, tools and feedback.
# Type: int. Units: logical pixels. Scope: the Import card's rows.
# Consumers: QtImport.__init__. Rationale: A compact 4 px rhythm leaves the
# option disclosure visible below the primary actions and Qt client menu.
IMPORT_CARD_ROW_GAP = 4
# Purpose: Preserve the space between the upload target and source controls.
# Type: int. Units: logical pixels. Scope: the Import card's section break.
# Consumers: QtImport.__init__. Rationale: 6 px distinguishes the source
# controls while preserving the disclosure in a 341 px Windows viewport.
IMPORT_SOURCE_SECTION_GAP = 6
# Purpose: Match Tk's review-heading inset after the selected-source caption.
# Type: int. Units: logical pixels. Scope: the Import review section break.
# Consumers: QtImport.__init__. Rationale: Twelve plus the regular 4 px row
# gap makes the Tk reference's 16 px gap without clipping narrow controls.
IMPORT_REVIEW_SECTION_GAP = 12
# Purpose: Separate the determinate track from the preceding review caption.
# Type: int. Units: logical pixels. Scope: the idle Import progress track.
# Consumers: QtImport.__init__. Rationale: Three extra pixels fit both this
# gap and the visible details control in the narrow Linux viewport.
IMPORT_PROGRESS_LEAD_GAP = 3
# Purpose: Separate the Import action row from the progress update.
# Type: int. Units: logical pixels. Scope: the Import action row.
# Consumers: QtImport.__init__. Rationale: Three extra pixels leave room
# for the details control at the existing minimum window dimensions.
IMPORT_ACTION_LEAD_GAP = 3
# Purpose: Match the Tk preflight detail table's visible row count.
# Type: int. Units: item rows. Scope: expanded manual-import review.
# Consumers: QtImport.review_table. Rationale: Six rows show useful detail
# without allowing the inner table to consume the surrounding workspace.
IMPORT_REVIEW_VISIBLE_ROWS = 6
# Purpose: Match the fixed Tk preflight review widths before file/detail stretch.
# Type: tuple[int, ...]. Units: logical pixels. Scope: five review columns.
# Consumers: QtImport.review_table. Rationale: The same bases keep File, Size,
# Media, Review and Detail readable at the reference desktop width.
IMPORT_REVIEW_COLUMN_WIDTHS = (190, 70, 85, 95, 290)


@dataclass(frozen=True)
class PreparedImport:
    """Transfer current work admission and any excluded uncertain requests."""

    worker: PreflightWorkerPort | None
    uncertain: int = 0


class QtImport(QFrame):
    """Present one preflight plan and one explicitly admitted worker at a time."""

    busy_changed = Signal(bool)
    finished = Signal()
    activity = Signal(str, str, str)
    connection_settings_requested = Signal()

    def __init__(
        self, actions: ImportActions,
        present: Callable[[ImportProgressSnapshot], None],
        selected_job: Callable[[], str | None],
        sanitize: Callable[[str], str],
        review_pending: Callable[[], None],
    ) -> None:
        """Bind native controls to existing source and upload service commands.

        Args:
            actions (ImportActions): Explicit application-owned import commands.
            present (Callable[[ImportProgressSnapshot], None]): Existing model
                view's GUI-thread progress receiver.
            selected_job (Callable[[], str | None]): Current explicit history
                selection, or None after loss of selection.
            sanitize (Callable[[str], str]): Existing credential-aware status redactor.
            review_pending (Callable[[], None]): Navigate to shared retained Import history.
        Returns:
            None: Builds an idle wizard with no work admitted.
        """
        super().__init__()
        self.setProperty('workspaceCard', True)
        self._actions = actions
        self._present = present
        self._selected_job = selected_job
        self._sanitize = sanitize
        self._events: ImportEvents = Queue(maxsize=IMPORT_EVENT_LIMIT)
        self._lane = QtReadLane[PreparedImport](
            self._start_worker, self._prepared, self,
        )
        self._worker: PreflightWorkerPort | None = None
        self._upload: ImportWorkerPort | None = None
        self._source: ImportSource | None = None
        self._plan: ImportPlan | None = None
        self._approval: ImportApproval | None = None
        self._preparing = ''
        self._paused = False
        self._stopping = False
        self._preflight_done = False
        self._upload_done = False
        self._shown_revision = -1  # Sentinel precedes the first saved revision.
        self._review_job_id: str | None = None
        self._review_generation: ImportWorkerGeneration | None = None
        self._review_revision = -1
        self._review_rows: dict[int, TableRow] = {}
        self._admission_locked = False
        self._capture_primary_action: Callable[[], None] | None = review_pending
        self._capture_secondary_action: Callable[[], None] | None = None
        self._timer = QTimer(self)
        self._timer.setInterval(IMPORT_OBSERVE_MS)
        self._timer.timeout.connect(self._observe)

        layout = QVBoxLayout(self)
        # Match the Tk card's internal logical padding without a group legend.
        layout.setContentsMargins(*WORKSPACE_CARD_INSETS)
        layout.setSpacing(IMPORT_CARD_ROW_GAP)
        identity = QHBoxLayout()
        self.target = QLabel(gettext('Upload gallery: not set'))
        self.target.setObjectName('heading')
        self.target.setTextFormat(Qt.TextFormat.PlainText)
        self.target.setWordWrap(True)
        identity.addWidget(self.target, stretch=1)
        identity.addWidget(ActionButton(
            gettext('Upload via API key'), self.connection_settings_requested.emit,
            icon='settings', icon_only=True,
        ))
        layout.addLayout(identity)
        capture_actions = QHBoxLayout()
        self._capture_primary = ActionButton(
            tr(current_language(), 'review_pending_uploads'),
            lambda: self._capture_primary_action() if self._capture_primary_action is not None else None,
        )
        self._capture_primary.setObjectName('importCapturePrimary')
        self._capture_secondary = ActionButton(
            '', lambda: self._capture_secondary_action() if self._capture_secondary_action is not None else None,
        )
        self._capture_secondary.setObjectName('importCaptureSecondary')
        self._capture_secondary.hide()
        for button in (self._capture_primary, self._capture_secondary):
            button.setMinimumHeight(WORKSPACE_ACTION_HEIGHT)
            button.setProperty('workspaceAction', True)
            capture_actions.addWidget(button)
        capture_actions.addStretch()
        layout.addLayout(capture_actions)
        layout.addSpacing(IMPORT_SOURCE_SECTION_GAP)
        source_heading = QLabel(gettext('CHOOSE SOURCE'))
        source_heading.setProperty('eyebrow', True)
        layout.addWidget(source_heading)
        pickers = QHBoxLayout()
        self.files = ActionButton(gettext('Files'), self.choose_files, icon='upload')
        self.folder = ActionButton(gettext('Folder'), self.choose_folder, icon='add')
        self.archive = ActionButton(gettext('ZIP'), self.choose_archive, icon='info')
        for button, caption in (
            (self.files, gettext('Choose files to import')),
            (self.folder, gettext('Choose folder to import')),
            (self.archive, gettext('Choose ZIP archive to import')),
        ):
            button.setToolTip(caption)
            button.setAccessibleName(caption)
            button.setProperty('compactText', True)
            button.setFlat(True)
        for button in (self.files, self.folder, self.archive):
            pickers.addWidget(button)
        self.clear_button = ActionButton(
            gettext('Clear the selected source'), self.clear, icon='close', icon_only=True,
        )
        pickers.addStretch()
        pickers.addWidget(self.clear_button)
        layout.addLayout(pickers)
        self.details_toggle = ToggleSwitch(gettext('Import options and item details'))
        self.details_panel = QWidget()
        self.details_layout = QVBoxLayout(self.details_panel)
        self.details_layout.setContentsMargins(12, 2, 0, 2)
        self.recursive = ToggleSwitch(gettext('Include subfolders'))
        self.recursive.toggled.connect(self._change_recursion)
        self.details_layout.addWidget(self.recursive)
        original_zip_note = QLabel(gettext('Original ZIP is kept for manual verification.'))
        original_zip_note.setObjectName('status')
        original_zip_note.setWordWrap(True)
        self.details_layout.addWidget(original_zip_note)
        self.thumbnail_modes = QButtonGroup(self.details_panel)
        self.thumbnail_modes.setExclusive(True)
        self.server_thumbnails = QRadioButton(gettext('Server creates thumbnails'))
        self.local_thumbnails = QRadioButton(
            gettext('This PC creates thumbnails when supported'),
        )
        self.thumbnail_modes.addButton(self.server_thumbnails, 0)
        self.thumbnail_modes.addButton(self.local_thumbnails, 1)
        self.server_thumbnails.setChecked(not actions.use_local_thumbnails)
        self.local_thumbnails.setChecked(actions.use_local_thumbnails)
        if not actions.local_thumbnail_available:
            self.server_thumbnails.setChecked(True)
            self.local_thumbnails.setChecked(False)
            self.local_thumbnails.setEnabled(False)
        self.details_layout.addWidget(self.server_thumbnails)
        self.details_layout.addWidget(self.local_thumbnails)
        self.review_model = OperatorTable((
            gettext('File'), gettext('Size'), gettext('Media'),
            gettext('Review'), gettext('Detail'),
        ))
        self.review_table = QTableView(self.details_panel)
        self.review_table.setModel(self.review_model)
        self.review_table.setAlternatingRowColors(True)
        self.review_table.setSelectionBehavior(
            QAbstractItemView.SelectionBehavior.SelectRows,
        )
        self.review_table.setSelectionMode(
            QAbstractItemView.SelectionMode.SingleSelection,
        )
        self.review_table.verticalHeader().hide()
        self.review_table.setFixedHeight(
            IMPORT_REVIEW_VISIBLE_ROWS
            * self.review_table.verticalHeader().defaultSectionSize()
            + self.review_table.horizontalHeader().sizeHint().height(),
        )
        header = self.review_table.horizontalHeader()
        header.setStretchLastSection(False)
        for column, width in enumerate(IMPORT_REVIEW_COLUMN_WIDTHS):
            header.setSectionResizeMode(column, QHeaderView.ResizeMode.Interactive)
            self.review_table.setColumnWidth(column, width)
        header.setSectionResizeMode(0, QHeaderView.ResizeMode.Stretch)
        header.setSectionResizeMode(4, QHeaderView.ResizeMode.Stretch)
        self.details_layout.addWidget(self.review_table)
        self.details_panel.setVisible(False)
        self.details_toggle.toggled.connect(self.details_panel.setVisible)
        self.source_caption = QLabel(gettext('Choose files, a folder, or a ZIP archive'))
        self.source_caption.setWordWrap(True)
        self.source_caption.setTextFormat(Qt.TextFormat.PlainText)
        layout.addWidget(self.source_caption)
        layout.addSpacing(IMPORT_REVIEW_SECTION_GAP)
        review_heading = QLabel(gettext('REVIEW'))
        review_heading.setProperty('eyebrow', True)
        layout.addWidget(review_heading)
        self._review_prompt = gettext(
            'Select a source to build an import summary. Nothing uploads until you press Start import.',
        )
        self.summary = QLabel(self._review_prompt)
        self.summary.setWordWrap(True)
        self.summary.setTextFormat(Qt.TextFormat.PlainText)
        layout.addWidget(self.summary)
        self._preparation_progress = QProgressBar()
        self._preparation_progress.setProperty('importProgress', True)
        self._preparation_progress.setAccessibleName(gettext('Preflight running'))
        self._preparation_progress.setRange(0, 0)  # Qt's structural indeterminate range.
        self._preparation_progress.setTextVisible(False)
        self._preparation_progress.setFixedHeight(IMPORT_PROGRESS_HEIGHT)
        self._preparation_progress.hide()
        layout.addWidget(self._preparation_progress)
        layout.addSpacing(IMPORT_PROGRESS_LEAD_GAP)
        self.progress = QProgressBar()
        self.progress.setProperty('importProgress', True)
        self.progress.setAccessibleName(gettext('Import'))
        self.progress.setValue(self.progress.minimum())
        self.progress.setTextVisible(False)
        self.progress.setFixedHeight(IMPORT_PROGRESS_HEIGHT)
        layout.addWidget(self.progress)
        self.progress_caption = QLabel(gettext('No active import'))
        self.progress_caption.setObjectName('status')
        layout.addWidget(self.progress_caption)
        layout.addSpacing(IMPORT_ACTION_LEAD_GAP)
        self.status = QLabel(gettext('Import idle'))
        self.status.setObjectName('status')
        self.status.setWordWrap(True)
        self.status.setTextFormat(Qt.TextFormat.PlainText)
        commands = QHBoxLayout()
        self.start_button = ActionButton(gettext('▶  Start import'), self.start, primary=True)
        self.pause_button = ActionButton(
            gettext('Pause import after active requests finish'), lambda: self.pause(True),
            icon='pause', icon_only=True,
        )
        self.resume_button = ActionButton(
            gettext('Resume the paused import'), lambda: self.pause(False),
            icon='play', icon_only=True,
        )
        self.cancel_button = ActionButton(
            gettext('Cancel the active import'), self.cancel, icon='close', icon_only=True,
        )
        self.retry_button = ActionButton(gettext('Retry failed'), self.retry)
        for button in (self.start_button, self.retry_button):
            button.setMinimumHeight(WORKSPACE_ACTION_HEIGHT)
            button.setProperty('workspaceAction', True)
        for button in (self.start_button, self.pause_button, self.resume_button,
                       self.cancel_button, self.retry_button):
            commands.addWidget(button)
        self.status.setAlignment(Qt.AlignmentFlag.AlignRight | Qt.AlignmentFlag.AlignVCenter)
        self.status.setSizePolicy(QSizePolicy.Policy.Ignored, QSizePolicy.Policy.Preferred)
        commands.addWidget(self.status, stretch=1)
        layout.addLayout(commands)
        layout.addWidget(self.details_toggle)
        layout.addWidget(self.details_panel)
        self._refresh_actions()

    def busy(self) -> bool:
        """Check whether an operation may still require this application owner.

        Returns:
            bool: True from command admission through final worker event delivery.
        """
        return bool(self._preparing) or self._worker is not None

    def set_capture_actions(
        self, primary_caption: str, primary_command: Callable[[], None] | None,
        secondary_caption: str, secondary_command: Callable[[], None] | None,
    ) -> None:
        """Present the current Watcher actions above the separate Import workflow.

        Args:
            primary_caption (str): Localized Finish, Progress, Review or reconnect caption.
            primary_command (Callable[[], None] | None): Existing Watcher owner action.
            secondary_caption (str): Localized Abandon or cleanup caption.
            secondary_command (Callable[[], None] | None): Existing Watcher owner action.
        Returns:
            None: Shows only actions owned by the current retained capture state.
        """
        self._capture_primary_action = primary_command
        self._capture_secondary_action = secondary_command
        for button, caption, command in (
            (self._capture_primary, primary_caption, primary_command),
            (self._capture_secondary, secondary_caption, secondary_command),
        ):
            button.setText(caption)
            button.setAccessibleName(caption)
            button.setVisible(bool(caption))
            button.setEnabled(command is not None)

    def set_admission_locked(self, locked: bool) -> None:
        """Prevent new Import work while another mode owns write admission.

        Args:
            locked (bool): Capture or another exclusive operation holds the writer gate.
        Returns:
            None: Keeps lifecycle review and safe worker controls accessible.
        """
        if locked != self._admission_locked:
            self._admission_locked = locked
            self._refresh_actions()

    def choose_files(self) -> None:
        """Admit only files selected through the platform picker.

        Returns:
            None: Starts independent shared preflight for explicit selection.
        """
        if not self.files.isEnabled():
            return
        paths, _selected_filter = QFileDialog.getOpenFileNames(self, gettext('Choose files to import'))
        if paths:
            self.select_source(ImportSource(files=tuple(Path(path) for path in paths)))

    def choose_folder(self) -> None:
        """Select one folder and the visible recursive discovery option.

        Returns:
            None: Starts independent shared preflight for explicit selection.
        """
        if not self.folder.isEnabled():
            return
        selected = QFileDialog.getExistingDirectory(self, gettext('Choose folder to import'))
        if selected:
            self.select_source(ImportSource(folder=Path(selected), recursive=self.recursive.isChecked()))

    def choose_archive(self) -> None:
        """Select a ZIP for staging by the existing bounded archive service.

        Returns:
            None: Starts independent shared preflight for explicit selection.
        """
        if not self.archive.isEnabled():
            return
        filters = f"{gettext('ZIP archives')} (*.zip);;{gettext('All files')} (*)"
        selected, _selected_filter = QFileDialog.getOpenFileName(
            self, gettext('Choose ZIP archive to import'), filter=filters,
        )
        if selected:
            self.select_source(ImportSource(archive=Path(selected)))

    def select_source(self, source: ImportSource) -> None:
        """Prepare one preflight after retiring any previous unaccepted plan.

        Args:
            source (ImportSource): Exactly one current operator selection.
        Returns:
            None: Moves discovery off the Qt GUI thread.
        """
        if self._admission_locked or self.busy():
            return
        previous = self._plan.job.id if self._plan is not None else None
        self._source = source
        selected_path = source.folder or source.archive
        if selected_path is None and len(source.files) == 1:
            selected_path = source.files[0]
        self.source_caption.setText(
            str(selected_path) if selected_path is not None
            else gettext('Files') + f' · {len(source.files)}',
        )
        self._preparing = 'preflight'
        self._preflight_done = False
        self.status.setText(gettext('Preflight running'))
        self.progress_caption.setText(gettext('No active import'))
        self.progress.setValue(self.progress.minimum())
        self._prepare_progress()

        def prepare(_cancelled: Event, publish: Callable[[PreparedImport], None]) -> None:
            """Retire an old selection and prepare the shared worker.

            Args:
                _cancelled (Event): Signals view retirement after quiescence.
                publish (Callable[[PreparedImport], None]): Current
                    operation's GUI admission sink.
            Returns:
                None: Transfers the unstarted worker to the visible wizard.
            """
            if previous is not None:
                self._actions.retire(previous)
            publish(PreparedImport(self._actions.prepare(source, self._events)))

        self._lane.submit(prepare)

    def _change_recursion(self, recursive: bool) -> None:
        """Rebuild a folder's review after its operator changes recursion.

        Args:
            recursive (bool): New setting from the visible folder options.
        Returns:
            None: Reuses the exact selected source and the normal preflight owner.
        """
        source = self._source
        if source is not None and source.folder is not None and not self.busy():
            self.select_source(replace(source, recursive=recursive))

    def clear(self) -> None:
        """Retire only a review plan that has never been bound for dispatch.

        Returns:
            None: Clears the draft after the existing ledger verifies retirement.
        """
        plan = self._plan
        if self._admission_locked or self.busy() or plan is None:
            return
        self._preparing = 'clear'
        self._prepare_progress()

        def retire(_cancelled: Event, publish: Callable[[PreparedImport], None]) -> None:
            """Retire this disposable draft away from the event loop.

            Args:
                _cancelled (Event): Presentation lifetime after safe shutdown.
                publish (Callable[[PreparedImport], None]): Exact result sink.
            Returns:
                None: Marks the draft cleared only after verified retirement.
            """
            self._actions.retire(plan.job.id)
            publish(PreparedImport(None))

        self._lane.submit(retire)

    def start(self) -> None:
        """Admit and upload the exact reviewed plan after explicit Start.

        Returns:
            None: Refuses duplicate starts and keeps the existing admission service.
        """
        plan, approval = self._plan, self._approval
        if self._admission_locked or self.busy() or plan is None or approval is None:
            return
        if not any(item.state in RECOVERABLE_ITEM_STATES for item in plan.job.items):
            return
        self._preparing = 'upload'
        self._upload_done = False
        self._shown_revision = -1  # Sentinel precedes the first saved revision.
        client_thumbnails = self.local_thumbnails.isChecked()
        self._prepare_progress()
        self._lane.submit(lambda _cancelled, publish: publish(PreparedImport(self._actions.admit(
            plan.job, approval, client_thumbnails, self._events,
        ))))

    def retry(self) -> None:
        """Request explicit recovery against a freshly verified selected history row.

        Returns:
            None: Reuses the accepted source identity and skips unknown results.
        """
        if self._admission_locked or self.busy():
            return
        job_id = self._selected_job()
        if not job_id:
            self.status.setText(gettext('Select a job in Activity first.'))
            return
        if self._plan is not None and self._approval is not None:
            if self._plan.job.id == job_id:
                self.start()
            return
        local = self.local_thumbnails.isChecked()
        self._preparing = 'upload'
        self._upload_done = False
        self._shown_revision = -1  # Sentinel precedes the first saved revision.
        self._prepare_progress()
        self._lane.submit(lambda _cancelled, publish: publish(PreparedImport(
            *self._actions.retry(job_id, local, self._events),
        )))

    def _prepare_progress(self) -> None:
        """Show pending worker preparation and the restart barrier.

        Returns:
            None: Keeps the UI responsive while the shared owner does I/O.
        """
        self._preparation_progress.show()
        self._refresh_actions()
        self.busy_changed.emit(True)

    def _start_worker(self, prepared: PreparedImport) -> None:
        """Start only the exact worker returned by the current admission.

        Args:
            prepared (PreparedImport): Unstarted worker after exact preparation
                and the current retry's unknown-outcome count.
        Returns:
            None: Starts background execution and begins local event delivery.
        """
        if self._preparing == 'preflight':
            self._plan = None
            self._approval = None
            self._clear_review_snapshot()
        elif self._preparing == 'clear':
            self._plan = None
            self._approval = None
            self._source = None
            self.source_caption.setText(gettext('Choose files, a folder, or a ZIP archive'))
            self.summary.setText(self._review_prompt)
            self.status.setText(gettext('Import idle'))
            self.progress_caption.setText(gettext('No active import'))
            self.progress.setValue(self.progress.minimum())
            self._clear_review_snapshot()
            self.finished.emit()
            return
        worker = prepared.worker
        if prepared.uncertain:
            self.summary.setText(gettext(
                '{uncertain} upload(s) have an uncertain result. '
                'Review the gallery manually; they were not retried.',
            ).format(uncertain=prepared.uncertain))
        if worker is None:
            self.status.setText(gettext('No failed or recoverable local files are available for retry.'))
            return
        self._worker = worker
        if self._preparing == 'upload':
            self._upload = cast(ImportWorkerPort, worker)
            self.status.setText(gettext('Import running'))
        try:
            worker.start()
        except RuntimeError:
            self._worker = None
            self._upload = None
            self._plan = None
            self._approval = None
            self._clear_review_snapshot()
            self.status.setText(gettext('Import needs review'))
            return
        self._timer.start()

    def _prepared(self, failure: str) -> None:
        """Report a failed preflight or admission without exposing secrets.

        Args:
            failure (str): Sanitized exception name or empty on success.
        Returns:
            None: Restores the command state after background preparation.
        """
        phase = self._preparing
        self._preparing = ''
        if failure:
            self._approval = None
            caption = gettext('Preflight failed') if phase == 'preflight' else gettext('Import needs review')
            self.status.setText(f'{caption}\n{failure}')
        if self._worker is None:
            self._preparation_progress.hide()
            self.busy_changed.emit(False)
        self._refresh_actions()

    def pause(self, paused: bool) -> None:
        """Pause or resume dispatch at the shared FIFO head.

        Args:
            paused (bool): Whether new requests should be deferred.
        Returns:
            None: Keeps any in-flight request and worker ownership intact.
        """
        upload = self._upload
        if upload is None or not upload.is_alive() or self._stopping:
            return
        upload.set_paused(paused)
        self._paused = paused
        self.status.setText(gettext('Import paused' if paused else 'Import running'))
        self._refresh_actions()

    def toggle_pause(self) -> None:
        """Use the same pause guard from the native tray and Import workspace.

        Returns:
            None: Toggles only an existing admitted upload worker.
        """
        self.pause(not self._paused)

    def cancel(self) -> None:
        """Request a normal shared cancellation and wait for actual teardown.

        Returns:
            None: Leaves durable evidence and already dispatched requests owned.
        """
        worker = self._worker
        if worker is None or not worker.is_alive():
            return
        self._stopping = True
        worker.stop()
        self.status.setText(gettext('Cancelling after in-flight requests'))
        self._refresh_actions()

    def _observe(self) -> None:
        """Consume a bounded local event slice and apply at most one progress patch.

        Returns:
            None: Releases the restart barrier only after worker teardown.
        """
        worker = self._worker
        if worker is None:
            return
        upload = self._upload
        for _index in range(IMPORT_EVENT_LIMIT):
            try:
                event = self._events.get_nowait()
            except Empty:
                break
            if isinstance(event, tuple):
                self.activity.emit(*event, upload.import_job_id if upload is not None else '')
                continue
            if not isinstance(payload := event.get('payload'), dict):
                continue
            kind = event.get('kind')
            if upload is None:
                self._preflight_event(kind if isinstance(kind, str) else '', payload)
            elif (kind == 'job_completed' and event.get('job_id') == upload.import_job_id
                  and payload.get('generation') == upload.import_generation):
                self._upload_done = True
                if payload.get('needs_review'):
                    self.status.setText(gettext('Import needs review'))
                elif payload.get('cancelled'):
                    self.status.setText(gettext('Import cancelled'))
                else:
                    self.status.setText(gettext('Import finished'))
                self.activity.emit('info', self.status.text(), upload.import_job_id)
        if upload is not None:
            snapshot = upload.latest_import_projection()
            if (snapshot is not None and snapshot.generation == upload.import_generation
                    and snapshot.history.job_id == upload.import_job_id
                    and snapshot.revision > self._shown_revision):
                self._shown_revision = snapshot.revision
                self.progress.setRange(0, max(1, snapshot.item_total))
                completed = snapshot.item_total - snapshot.counts.active
                self.progress.setValue(completed)
                self.progress_caption.setText(f'{completed} / {snapshot.item_total}')
                self._preparation_progress.hide()
                self._present_snapshot(snapshot)
        if worker.is_alive() or not self._events.empty():
            return
        self._timer.stop()
        if upload is not None:
            self._plan = None
            self._approval = None
            self._source = None
            if not self._upload_done:
                self.status.setText(gettext('Import needs review'))
        elif not self._preflight_done:
            self.status.setText(gettext('Preflight failed'))
        self._worker = None
        self._upload = None
        self._paused = False
        self._stopping = False
        self._preparation_progress.hide()
        self._refresh_actions()
        self.busy_changed.emit(False)
        self.finished.emit()

    def _preflight_event(self, kind: str, payload: dict[str, object]) -> None:
        """Apply status emitted by the current isolated preflight worker.

        Args:
            kind (str): Existing worker preflight event name.
            payload (dict[str, object]): Current worker event fields and proof.
        Returns:
            None: Displays typed progress or installs one valid reviewed plan.
        """
        if kind == 'preflight_progress':
            count, total = payload.get('current'), payload.get('total')
            if type(count) is int and type(total) is int and 0 <= count <= total:
                self.progress_caption.setText(f'{count} / {total}')
        elif kind == 'preflight_complete' and isinstance(plan := payload.get('plan'), ImportPlan):
            self._plan = plan
            binding = payload.get('preflight_binding')
            try:
                self._approval = import_approval(
                    plan.job.id, payload.get('config_generation'),
                    binding if isinstance(binding, str) else '',
                )
            except ValueError:
                self.status.setText(gettext('Preflight needs to be run again'))
            else:
                self._show_plan(plan)
                self.activity.emit('success', gettext(
                    'Import preflight completed and is awaiting explicit Start import.',
                ), plan.job.id)
            self._preflight_done = True
        elif kind in ('preflight_cancelled', 'preflight_failed'):
            self._preflight_done = True
            message = payload.get('message')
            caption = gettext('Preflight cancelled') if kind == 'preflight_cancelled' else gettext('Preflight failed')
            if isinstance(message, str) and message:
                caption += '\n' + self._sanitize(gettext(message))
            self.status.setText(caption)

    def _show_plan(self, plan: ImportPlan) -> None:
        """Display the verified destination and retained local preflight results.

        Args:
            plan (ImportPlan): Persisted plan returned by the existing worker.
        Returns:
            None: Shows the bounded job rows and summary for explicit review.
        """
        job = plan.job
        self.summary.setText(format_preflight_plan_summary(
            plan,
            use_local_thumbnails=self.local_thumbnails.isChecked(),
            local_thumbnail_available=self._actions.local_thumbnail_available,
            media_capabilities=self._actions.media_capabilities,
        ))
        self.status.setText(gettext('Ready for review'))
        self._present_snapshot(ImportProgressSnapshot.from_job(
            job, new_worker_generation(), 0, IMPORT_REVIEW_ROWS,
        ))

    def _present_snapshot(self, snapshot: ImportProgressSnapshot) -> None:
        """Update local review rows before forwarding the shared projection.

        Args:
            snapshot (ImportProgressSnapshot): Bounded durable item projection.
        Returns:
            None: Reconciles the detail table and sends the same snapshot onward.
        """
        self._update_review_snapshot(snapshot)
        self._present(snapshot)

    def _update_review_snapshot(self, snapshot: ImportProgressSnapshot) -> None:
        """Apply only received rows while fencing stale jobs and revisions.

        Args:
            snapshot (ImportProgressSnapshot): Immutable saved worker revision.
        Returns:
            None: Updates the bounded model while preserving row identity and scroll.
        """
        same_job = snapshot.history.job_id == self._review_job_id
        same_generation = snapshot.generation == self._review_generation
        if not same_job:
            self._review_rows.clear()
            self._review_revision = -1
        elif not same_generation:
            self._review_revision = -1
        elif snapshot.revision <= self._review_revision:
            return
        if snapshot.complete_rows:
            self._review_rows = {
                item.source_index: self._review_table_row(item)
                for item in snapshot.items
                if 0 <= item.source_index < snapshot.item_row_limit
            }
        else:
            for item in snapshot.items:
                if 0 <= item.source_index < snapshot.item_row_limit:
                    self._review_rows[item.source_index] = self._review_table_row(item)
        self._review_job_id = snapshot.history.job_id
        self._review_generation = snapshot.generation
        self._review_revision = snapshot.revision
        rows = tuple(self._review_rows[index] for index in sorted(self._review_rows))
        self.review_model.replace(rows, self.review_table)

    @staticmethod
    def _review_table_row(item: ImportItemView) -> TableRow:
        """Prepare one durable item with the Tk manual-review cell order.

        Args:
            item (ImportItemView): Bounded immutable row from the shared snapshot.
        Returns:
            TableRow: Stable item identity, five display cells and review detail.
        """
        values = item.item_values(format_gallery_bytes(item.size_bytes))
        return TableRow(item.item_id, tuple(str(value) for value in values), item.reason)

    def _clear_review_snapshot(self) -> None:
        """Clear stale rows after safe plan retirement or replacement.

        Returns:
            None: Drops the prior job identity and empties its visible rows.
        """
        self._review_job_id = None
        self._review_generation = None
        self._review_revision = -1
        self._review_rows.clear()
        self.review_model.replace((), self.review_table)

    def _refresh_actions(self) -> None:
        """Synchronize command buttons with the exact worker barrier.

        Returns:
            None: Admits a new click only when its action is currently allowed.
        """
        can_admit = not self.busy() and not self._admission_locked
        for control in (
            self.files, self.folder, self.archive, self.recursive,
            self.server_thumbnails,
        ):
            control.setEnabled(can_admit)
        self.local_thumbnails.setEnabled(
            can_admit and self._actions.local_thumbnail_available,
        )
        self.start_button.setEnabled(
            can_admit and self._approval is not None and self._plan is not None
            and any(item.state in RECOVERABLE_ITEM_STATES for item in self._plan.job.items),
        )
        self.pause_button.setEnabled(self._upload is not None and not self._paused and not self._stopping)
        self.resume_button.setEnabled(self._upload is not None and self._paused and not self._stopping)
        self.cancel_button.setEnabled(self._worker is not None and not self._stopping)
        self.retry_button.setEnabled(can_admit and self._approval is None)
        self.clear_button.setEnabled(can_admit and self._plan is not None)

    def close_view(self) -> None:
        """Withdraw native command callbacks after the shell verifies quiescence.

        Returns:
            None: Closes worker preparation and stops the idle observer.
        """
        self._timer.stop()
        self._lane.close()
