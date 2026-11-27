# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_flight_reports.py
# Module Type: Qt Flight report presentation
# Purpose: Generate new local editions for an explicitly selected stopped flight.
# Responsibilities: Hold the existing workspace lease and present the shared
#   report worker's status, completion, failure and cancellation in Qt.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Present local flight report creation in the shared operator workspace."""

from dataclasses import dataclass
from pathlib import Path
from threading import Event
from typing import Callable

from PySide6.QtCore import Qt, QTimer, Signal
from PySide6.QtWidgets import (
    QAbstractItemView, QDialog, QHBoxLayout, QLabel,
    QProgressBar, QTableWidget, QTableWidgetItem, QTreeWidget,
    QTreeWidgetItem, QVBoxLayout, QWidget,
)

from .capture_session import CaptureCommands
from .flight_jobs import SessionPlan, WorkspaceLease, read_document, report_revisions
from .flight_report_translations import tr
from .flight_workflow import FlightProgress, FinalizationWorker, create_local_report_worker
from .localization import current_language, gettext
from .qt_read_lane import QtReadLane
from .qt_theme import ActionButton

# Purpose: Present actual local render progress without blocking the UI.
# Type: int. Units: milliseconds. Scope: one active Flight report operation.
# Consumers: QtFlightReports._observe.
# Rationale: 250 matches the existing desktop Flight progress interval.
REPORT_OBSERVE_MS = 250


@dataclass(frozen=True)
class PreparedReport:
    """Transfer the validated local worker together with its actual workspace lease."""

    worker: FinalizationWorker
    lease: WorkspaceLease | None

    def release(self) -> None:
        """Release only the independent workspace owned by a local render.

        Returns:
            None: Leaves a borrowed active capture workspace with its owner.
        """
        if self.lease is not None:
            self.lease.release()


@dataclass(frozen=True)
class ReportChoices:
    """Carry a stopped flight's catalogue and the destination shown before upload."""

    directory: Path
    binding: str
    destination: str
    editions: tuple[tuple[str, int | None, str, str], ...]
    requested_editions: tuple[tuple[str, str], ...] | None = None


class QtFlightReports(QWidget):
    """Offer report creation for a stopped flight and retain ownership through exit."""

    finished = Signal()

    def __init__(
        self, root: Path, selected: Callable[[], Path | None],
        capture: CaptureCommands | None = None, redact: Callable[[str], str] | None = None,
    ) -> None:
        """Create report controls for the current native flight selection.

        Args:
            root (Path): Existing per-profile root of the owned flight workspace.
            selected (Callable[[], Path | None]): Current verified catalog row
                directory, or None while no flight is selected.
            capture (CaptureCommands | None): Current owner of a live Watcher target.
            redact (Callable[[str], str] | None): Credential-aware status redaction.
        Returns:
            None: Starts idle with no workspace write ownership.
        """
        super().__init__()
        self._root = root
        self._selected = selected
        self._capture = capture
        self._redact = redact or (lambda message: message)
        self._language = current_language()
        self._preparing = False
        self._capturing = False
        self._upload_admitted = False
        self._current: PreparedReport | None = None
        self._lane = QtReadLane[PreparedReport](
            self._start, self._prepared, self, dispose=lambda report: report.release(),
        )
        self._catalogue = QtReadLane[ReportChoices](self._choose_upload, self._listed, self)
        self._timer = QTimer(self)
        self._timer.setInterval(REPORT_OBSERVE_MS)
        self._timer.timeout.connect(self._observe)
        layout = QVBoxLayout(self)
        commands = QHBoxLayout()
        commands.addStretch()
        self.create_button = ActionButton(tr(self._language, 'create_local_pdf'), self.create)
        self.create_upload_button = ActionButton(
            tr(self._language, 'report_create_upload'), self.create_and_upload, primary=True,
        )
        self.create_upload_button.setVisible(capture is not None)
        self.create_upload_button.setEnabled(False)
        self.cancel_button = ActionButton(tr(self._language, 'cancel_pdf'), self.cancel)
        commands.addWidget(self.create_button)
        commands.addWidget(self.create_upload_button)
        commands.addWidget(self.cancel_button)
        layout.addLayout(commands)
        self.progress = QProgressBar()
        self.progress.setAccessibleName(tr(self._language, 'flight_progress'))
        self.progress.hide()
        layout.addWidget(self.progress)
        self.edition_progress = QTableWidget(0, 2)
        self.edition_progress.setObjectName('qtSavedReportEditionProgress')
        self.edition_progress.setHorizontalHeaderLabels((
            tr(self._language, 'report_languages'), gettext('Status'),
        ))
        self.edition_progress.horizontalHeader().setStretchLastSection(True)
        self.edition_progress.verticalHeader().hide()
        self.edition_progress.setEditTriggers(QTableWidget.EditTrigger.NoEditTriggers)
        self.edition_progress.setSelectionMode(QTableWidget.SelectionMode.NoSelection)
        self.edition_progress.hide()
        layout.addWidget(self.edition_progress)
        self.status = QLabel()
        self.status.setWordWrap(True)
        self.status.setTextFormat(Qt.TextFormat.PlainText)
        self.status.hide()
        layout.addWidget(self.status)
        self.cancel_button.setEnabled(False)
        self.cancel_button.hide()

    def busy(self) -> bool:
        """Report whether any real render or workspace admission is still owned.

        Returns:
            bool: True through actual preparation, rendering and final lease release.
        """
        return self._preparing or self._current is not None

    def _set_status(self, message: str) -> None:
        """Show a user-relevant report status only after an explicit action.

        Args:
            message (str): Current action result or worker phase.
        Returns:
            None: Keeps the idle archive surface free of stale status text.
        """
        self.status.setText(message)
        self.status.setVisible(bool(message))

    def create(self) -> None:
        """Prepare a fresh local revision for the current stopped flight.

        Returns:
            None: Starts background verification after an explicit Create click.
        """
        if self.busy() or self._capturing:
            return
        directory = self._selected()
        if directory is None:
            self._set_status(tr(self._language, 'select_saved_flight'))
            return
        self._preparing = True
        self._set_status(gettext('Loading…'))
        self.create_button.setEnabled(False)
        self._lane.submit(lambda cancelled, publish: self._prepare(directory, cancelled, publish))

    def set_capture_busy(self, busy: bool) -> None:
        """Keep local rendering separate from the active capture workspace.

        Args:
            busy (bool): A capture or its lifecycle command owns the workspace.
        Returns:
            None: Leaves explicit saved PDF upload available to the same target.
        """
        self._capturing = busy
        self.create_button.setEnabled(not busy and not self.busy())
        self.create_upload_button.setEnabled(busy and not self.busy())

    def create_and_upload(self) -> None:
        """Render a new revision and deliver it to the currently captured gallery.

        Returns:
            None: The original destination and stopped source are checked off Qt.
        """
        capture = self._capture
        if capture is None or self.busy():
            return
        directory, target = self._selected(), capture.report_target()
        if directory is None:
            self._set_status(tr(self._language, 'select_saved_flight'))
            return
        if target is None:
            self._set_status(tr(self._language, 'report_start_watcher'))
            return
        self._preparing = True
        self._set_status(gettext('Loading…'))
        self.create_button.setEnabled(False)
        self.create_upload_button.setEnabled(False)
        self._lane.submit(lambda cancel, publish: publish(PreparedReport(
            capture.prepare_report_creation(directory, target[0]), None,
        )) if not cancel.is_set() else None)

    def upload_selected(
        self, directory: Path | None = None,
        editions: tuple[tuple[str, str], ...] | None = None,
        multiple_flights: bool = False, has_selection: bool = False,
    ) -> None:
        """Read and confirm selected saved editions for the current gallery.

        Args:
            directory (Path | None): Exact flight captured from the catalog.
            editions (tuple[tuple[str, str], ...] | None): Selected report ID and
                language pairs, or None to use the default latest revision.
            multiple_flights (bool): Whether selected catalog rows span sessions.
            has_selection (bool): Whether the caller captured explicit tree rows.
        Returns:
            None: Approves only one stopped flight and current Watcher destination.
        """
        if self._capture is None or self.busy():
            return
        if multiple_flights:
            self._set_status(tr(self._language, 'single_flight_selection'))
            return
        current = self._selected()
        if directory is not None and directory != current:
            self._set_status(tr(self._language, 'select_saved_flight'))
            return
        directory, target = directory or current, self._capture.report_target()
        if directory is None:
            self._set_status(tr(self._language, 'select_saved_flight'))
            return
        if target is None:
            self._set_status(tr(self._language, 'report_start_watcher'))
            return
        self._preparing = True
        self.create_button.setEnabled(False)
        self.create_upload_button.setEnabled(False)
        requested_editions = editions if has_selection else None

        def list_editions(cancelled: Event, publish: Callable[[ReportChoices], None]) -> None:
            """List existing edition identities without opening a report writer.

            Args:
                cancelled (Event): Current native catalogue request fence.
                publish (Callable[[ReportChoices], None]): Explicit approval view.
            Returns:
                None: Lists exact saved revision and language choices.
            """
            if (directory.parent != self._root or directory.is_symlink()
                    or directory.resolve(strict=True).parent != self._root.resolve(strict=True)
                    or read_document(directory / 'session.json').get('recording_ended') is not True):
                raise ValueError('Finish recording before uploading saved files.')
            revisions = sorted(
                report_revisions(directory),
                key=lambda revision: revision.number or 0, reverse=True,
            )
            if not cancelled.is_set():
                publish(ReportChoices(
                    directory, *target, tuple(
                        (revision.report_id, revision.number, language, pdf.name)
                        for revision in revisions
                        for language, pdf in sorted(revision.files.items())
                    ), requested_editions,
                ))

        self._catalogue.submit(list_editions)

    def _listed(self, failure: str) -> None:
        """Release an unavailable or dismissed catalogue selection.

        Args:
            failure (str): Fixed background failure type, or empty completion.
        Returns:
            None: Restores controls if no approved report worker was submitted.
        """
        if failure:
            self._set_status(tr(self._language, 'phase_failed'))
        if not self._upload_admitted:
            self._prepared('')
        self._upload_admitted = False

    def _choose_upload(self, choice: ReportChoices) -> None:
        """Confirm the exact saved editions and their current Watcher destination.

        Args:
            choice (ReportChoices): Verified catalogue and original target fingerprint.
        Returns:
            None: Submits only the files selected in the explicit confirmation.
        """
        if not choice.editions or self._capture is None:
            self._set_status(tr(self._language, 'no_saved_pdf'))
            return
        dialog = QDialog(self)
        dialog.setWindowTitle(tr(self._language, 'upload_saved_pdf'))
        layout = QVBoxLayout(dialog)
        label = QLabel(tr(self._language, 'confirm_saved_upload') + '\n' + choice.destination)
        label.setTextFormat(Qt.TextFormat.PlainText)
        label.setWordWrap(True)
        layout.addWidget(label)
        listing = QTreeWidget()
        listing.setObjectName('savedReportUploadSelection')
        listing.setAccessibleName(tr(self._language, 'saved_reports'))
        listing.setHeaderLabels((
            tr(self._language, 'report_version'),
            tr(self._language, 'report_languages'),
        ))
        listing.setSelectionMode(QAbstractItemView.SelectionMode.ExtendedSelection)
        root = QTreeWidgetItem(listing)
        root.setText(0, choice.directory.name)
        root.setFlags(root.flags() & ~Qt.ItemFlag.ItemIsSelectable)
        for revision, number, language, caption in choice.editions:
            revision_item = next((
                root.child(index) for index in range(root.childCount())
                if root.child(index).data(0, Qt.ItemDataRole.UserRole) == revision
            ), None)
            if revision_item is None:
                revision_item = QTreeWidgetItem(root)
                revision_item.setText(0, 'v' + str(number or '?'))
                revision_item.setData(0, Qt.ItemDataRole.UserRole, revision)
            edition = QTreeWidgetItem(revision_item)
            edition.setText(0, language.upper() + ' · ' + caption)
            edition.setText(1, language.upper())
            edition.setData(0, Qt.ItemDataRole.UserRole, (revision, language))
        root.setExpanded(True)
        for revision_index in range(root.childCount()):
            revision_item = root.child(revision_index)
            revision_item.setExpanded(True)
            if choice.requested_editions is None and revision_index == 0:
                revision_item.setSelected(True)
            elif choice.requested_editions is not None:
                requested = set(choice.requested_editions)
                for edition_index in range(revision_item.childCount()):
                    edition = revision_item.child(edition_index)
                    if edition.data(0, Qt.ItemDataRole.UserRole) in requested:
                        edition.setSelected(True)
        layout.addWidget(listing)
        buttons = QHBoxLayout()
        accept = ActionButton(tr(self._language, 'upload_saved_pdf'), dialog.accept, primary=True)
        accept.setEnabled(bool(listing.selectedItems()))
        listing.itemSelectionChanged.connect(
            lambda: accept.setEnabled(bool(listing.selectedItems())),
        )
        buttons.addWidget(accept)
        buttons.addWidget(ActionButton(gettext('Cancel'), dialog.reject))
        layout.addLayout(buttons)
        if dialog.exec() != QDialog.DialogCode.Accepted:
            return
        selected_editions: set[tuple[str, str]] = set()
        for item in listing.selectedItems():
            data = item.data(0, Qt.ItemDataRole.UserRole)
            if isinstance(data, tuple):
                selected_editions.add((str(data[0]), str(data[1])))
            elif isinstance(data, str):
                selected_editions.update(
                    (revision, language)
                    for revision, _number, language, _caption in choice.editions
                    if revision == data
                )
        selected = tuple(
            (revision, language)
            for revision, _number, language, _caption in choice.editions
            if (revision, language) in selected_editions
        )
        if not selected:
            return
        source = self._capture
        self._upload_admitted = True
        self._lane.submit(lambda cancel, publish: publish(PreparedReport(
            source.prepare_report_upload(choice.directory, selected, choice.binding), None,
        )) if not cancel.is_set() else None)

    def _prepare(
        self, directory: Path, cancelled: Event, publish: Callable[[PreparedReport], None],
    ) -> None:
        """Verify the stopped source and retain the same native workspace lock.

        Args:
            directory (Path): Exact row selected in the local catalog.
            cancelled (Event): Latest request and view shutdown fence.
            publish (Callable[[PreparedReport], None]): GUI receipt for the
                unstarted application worker and its owned lease.
        Returns:
            None: Transfers only a valid stopped source, or releases ownership.
        Raises:
            OSError: Existing workspace or selected source is unavailable.
            ValueError: Selected source is unsafe, incomplete or still recording.
            RuntimeError: An incompatible operation already owns the workspace.
        """
        if cancelled.is_set():
            return
        lease = WorkspaceLease(self._root)
        try:
            lease.verify()
            if (directory.is_symlink() or directory.parent != self._root
                    or directory.resolve().parent != self._root.resolve()):
                raise ValueError('Flight session redirects outside its workspace.')
            meta = directory / 'session.json'
            if meta.is_symlink() or read_document(meta).get('recording_ended') is not True:
                raise ValueError('Finish recording before creating a local PDF.')
            if (directory / 'completion.json').is_symlink():
                raise ValueError('Flight completion cannot redirect outside its workspace.')
            plan = SessionPlan.load(directory)
            publish(PreparedReport(create_local_report_worker(plan), lease))
        except BaseException:
            lease.release()
            raise

    def _start(self, report: PreparedReport) -> None:
        """Start the captured worker with its held lease.

        Args:
            report (PreparedReport): Exact unstarted local operation and lease.
        Returns:
            None: Starts rendering or releases an unstartable operation.
        """
        try:
            report.worker.start()
        except RuntimeError:
            report.release()
            self._set_status(tr(self._language, 'phase_failed'))
            return
        self._current = report
        self.progress.setRange(0, 0)  # Qt's structural indeterminate range.
        self.progress.show()
        self.cancel_button.show()
        self._timer.start()

    def _prepared(self, failure: str) -> None:
        """Display preparation refusal and release the visible pending state.

        Args:
            failure (str): Sanitized failure type or empty on successful ownership.
        Returns:
            None: Restores Create or enables cancellation of the active render.
        """
        self._preparing = False
        if failure:
            self._set_status(tr(self._language, 'phase_failed'))
        elif self.status.text() == gettext('Loading…'):
            self._set_status('')
        self.create_button.setEnabled(self._current is None and not self._capturing)
        self.create_upload_button.setEnabled(self._current is None and self._capturing)
        self.cancel_button.setEnabled(self._current is not None)
        self.cancel_button.setVisible(self._current is not None)

    def cancel(self) -> None:
        """Signal the existing local cancel checkpoint without losing generated files.

        Returns:
            None: Leaves rendering and the workspace lease owned until quiescent.
        """
        if self._current is not None:
            self._current.worker.cancel.set()
            self.cancel_button.setEnabled(False)

    def _observe(self) -> None:
        """Project real worker phases and release the lease after termination.

        Returns:
            None: Shows committed reports or retained failure/cancellation status.
        """
        report = self._current
        if report is None:
            return
        snapshot = report.worker.snapshot()
        phase = {
            'cancelled': 'report_cancelled', 'error': 'phase_failed',
        }.get(snapshot.phase, f'phase_{snapshot.phase}')
        text = tr(self._language, phase)
        self._set_status(f'{text}\n{self._redact(snapshot.error)}' if snapshot.error else text)
        self.progress.setMaximum(snapshot.total)
        self.progress.setValue(snapshot.done)
        self._show_edition_progress(report, snapshot)
        if report.worker.is_alive():
            return
        self._timer.stop()
        report.release()
        self._current = None
        self.progress.hide()
        self.create_button.setEnabled(not self._capturing)
        self.create_upload_button.setEnabled(self._capturing)
        self.cancel_button.setEnabled(False)
        self.cancel_button.hide()
        self.finished.emit()

    def _show_edition_progress(
        self, report: PreparedReport, snapshot: FlightProgress,
    ) -> None:
        """Render bounded per-language status from the shared worker snapshot.

        Args:
            report (PreparedReport): Current report worker and workspace lease.
            snapshot (FlightProgress): Immutable worker progress copy.
        Returns:
            None: Shows only languages belonging to the active source plan.
        """
        worker = report.worker
        if worker.saved_upload and worker.saved_revisions:
            editions = [
                (revision.report_id, language)
                for revision in worker.saved_revisions
                for language in revision.files
            ]
            offset = 1 + len(worker.plans[0].languages) if worker.local_reports else 0
            delivered = snapshot.done - offset
            rows = []
            for index, (revision_id, language) in enumerate(editions):
                state = tr(self._language, 'report_state_local')
                if delivered >= index + 2:
                    state = tr(self._language, 'phase_completed')
                elif delivered == index + 1 and snapshot.phase == 'uploading':
                    state = tr(self._language, 'phase_uploading')
                rows.append((f'{revision_id} · {language.upper()}', state))
        else:
            rows = []
            for plan in worker.plans:
                if not plan.reports_enabled and worker.local_reports is not True:
                    continue
                current = snapshot.render_session == plan.session_id
                for language in plan.languages:
                    state = tr(self._language, 'phase_idle')
                    if current and language in snapshot.rendered_languages:
                        state = tr(
                            self._language,
                            'report_state_local' if worker.local_reports else 'phase_ready',
                        )
                    rendering = (
                        current and snapshot.phase == 'generating'
                        and language in snapshot.rendering_languages
                    )
                    if rendering:
                        state = tr(self._language, 'phase_generating')
                    uploading = (
                        current and snapshot.phase == 'uploading'
                        and snapshot.detail == language.upper()
                    )
                    if uploading:
                        state = tr(self._language, 'phase_uploading')
                    complete = language in plan.receipts or (plan.completed and plan.local_only)
                    if worker.local_reports is not True and complete:
                        state = tr(self._language, 'phase_completed')
                    rows.append((language.upper(), state))
        self.edition_progress.setRowCount(len(rows))
        for row, values in enumerate(rows):
            for column, value in enumerate(values):
                item = self.edition_progress.item(row, column)
                if item is None:
                    item = QTableWidgetItem()
                    self.edition_progress.setItem(row, column, item)
                if item.text() != value:
                    item.setText(value)
        self.edition_progress.setVisible(bool(rows))

    def close_view(self) -> None:
        """Release native callbacks once the shell has confirmed a safe close.

        Returns:
            None: Stops repainting and withdraws the idle preparation lane.
        """
        self._timer.stop()
        self._catalogue.close()
        self._lane.close()
