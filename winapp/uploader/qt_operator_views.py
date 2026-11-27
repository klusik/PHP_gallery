# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_operator_views.py
# Module Type: Qt operator presentation
# Purpose: Present saved Import, Activity and Flight data in Qt models.
# Responsibilities: Read shared projections off the UI thread, preserve row
#   identity and expose actions only where their lifecycle is supported.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Project existing durable operator data without admitting work."""

from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from threading import Event
import time
from typing import Callable

from PySide6.QtCore import (
    QAbstractTableModel, QModelIndex, QSignalBlocker, QSortFilterProxyModel, Qt, QUrl,
)
from PySide6.QtGui import QDesktopServices, QResizeEvent, QShowEvent
from PySide6.QtWidgets import (
    QAbstractItemView, QComboBox, QFrame, QHBoxLayout,
    QHeaderView, QLabel, QMenu, QPlainTextEdit, QScrollArea, QTableView,
    QTreeWidget, QTreeWidgetItem, QVBoxLayout, QWidget,
)

from .diagnostics import redact_text
from .flight_jobs import (
    ReportFileDetails, ReportRevision, SavedFlight, WorkspaceLease,
    organize_reports, report_file_details, report_file_label, report_revisions,
    saved_flights,
)
from .import_history_reader import read_import_detail
from .import_ui_snapshot import ImportProgressSnapshot, new_worker_generation
from .flight_report_translations import tr
from .localization import current_language, gettext
from .models import ActivityEvent
from .qt_read_lane import QtReadLane
from .qt_theme import ActionButton, ToggleSwitch
from .state_store import JobStateStore
from .support_report import read_support_log

# Purpose: Bound the Qt Import history and each pageable Flight history view.
# Type: int. Units: rows. Scope: one newest-first local history page.
# Consumers: QtOperatorViews.refresh and QtOperatorViews._read.
# Rationale: 200 matches the established Import history presentation size.
HISTORY_ROWS = 200
# Purpose: Reuse the existing item cap for the Qt selected-import projection.
# Type: int. Units: item rows. Scope: one selected historical import.
# Consumers: QtOperatorViews._read_import.
# Rationale: 1,000 matches Tk Import/Activity detail display admission.
IMPORT_ITEM_ROWS = 1000
# Purpose: Reserve room for several rows in each vertically scrolling card.
# Type: int. Units: logical pixels. Scope: Activity tables and log preview.
# Consumers: ActivityPage. Rationale: Keeps headers and a few rows visible in narrow windows.
ACTIVITY_CARD_CONTENT_MIN_HEIGHT = 144
# Purpose: Match the reference Activity card gutters and its ordinary actions.
# Type: tuple[int, int, int, int]. Units: logical pixels. Scope: Activity cards.
# Consumers: ActivityPage._card. Rationale: Tk uses 14 px across and 10 px down.
ACTIVITY_CARD_INSETS = (14, 10, 14, 10)
# Purpose: Keep the Activity command row the same height as the reference.
# Type: int. Units: logical pixels. Scope: Activity buttons beside its tables.
# Consumers: ActivityPage. Rationale: 42 matches the native Tk command footprint.
ACTIVITY_ACTION_HEIGHT = 42
# Purpose: Preserve the reading depth of the Tk current Import and history tables.
# Type: tuple[int, int]. Units: native row heights. Scope: Activity cards.
# Consumers: ActivityPage. Rationale: Tk shows seven item and ten history rows.
ACTIVITY_IMPORT_VISIBLE_ROWS, ACTIVITY_HISTORY_VISIBLE_ROWS = (7, 10)
# Purpose: Preserve useful filename, state and attempt columns on the Activity card.
# Type: tuple[int, ...]. Units: logical pixels. Scope: current Import table.
# Consumers: ActivityPage._table. Rationale: Tk starts at 175, 105, 65 and 330 before
# distributing the remaining space to the file and result columns.
ACTIVITY_IMPORT_COLUMN_WIDTHS = (175, 105, 65, 330)
# Purpose: Keep connection labels and dates distinguishable in the job list.
# Type: tuple[int, ...]. Units: logical pixels. Scope: Activity job history.
# Consumers: ActivityPage._table. Rationale: Match all four fixed Tk base widths.
ACTIVITY_HISTORY_COLUMN_WIDTHS = (125, 190, 140, 270)
# Purpose: Preserve the Recent events table's labelled column bases.
# Type: tuple[int, ...]. Units: logical pixels. Scope: Activity event table.
# Consumers: ActivityPage. Rationale: Match Tk's Time/Status/File/Type/Event
# widths; File and Event both stretch with the available viewport.
ACTIVITY_EVENT_COLUMN_WIDTHS = (75, 75, 125, 85, 390)
# Purpose: Keep the same visible report column order and bases as Tk.
# Type: tuple[tuple[int, int], ...]. Units: logical section index, logical pixels.
# Scope: Saved Flights report tree. Consumers: QtOperatorViews.__init__.
# Rationale: Name, Started and PDFs come first while preserving data-role indices.
FLIGHT_REPORT_COLUMNS = ((0, 192), (1, 148), (3, 46), (6, 112), (4, 150), (5, 59), (2, 64))


@dataclass(frozen=True)
class TableRow:
    """Identify one saved record with its visible cells and local detail."""

    identity: str
    values: tuple[str, ...]
    detail: str = ""


@dataclass(frozen=True)
class ActivityRow:
    """Retain filterable source facts alongside the redacted visible row."""

    row: TableRow
    level: str
    message: str


class _ActivityStretchTable(QTableView):
    """Keep Tk's base columns while sharing only surplus across selected fields."""

    def __init__(
        self, model: 'OperatorTable', base_widths: tuple[int, ...],
        stretch_columns: tuple[int, ...],
    ) -> None:
        """Create one activity view with explicit reference widths.

        Args:
            model (OperatorTable): Existing read-only source model.
            base_widths (tuple[int, ...]): Tk reference width for each section.
            stretch_columns (tuple[int, ...]): Sections that divide excess width.
        Returns:
            None: Sets up native section resizing without changing model rows.
        """
        super().__init__()
        self._base_widths = base_widths
        self._stretch_columns = stretch_columns
        self.setModel(model)
        header = self.horizontalHeader()
        header.setStretchLastSection(False)
        for index in range(len(base_widths)):
            header.setSectionResizeMode(index, QHeaderView.ResizeMode.Interactive)
            self.setColumnWidth(index, base_widths[index])
        self._share_surplus_width()

    def resizeEvent(self, event: QResizeEvent) -> None:
        """Redistribute current surplus after the native viewport is resized.

        Args:
            event (QResizeEvent): Native table geometry change.
        Returns:
            None: Preserves base widths and divides only available surplus.
        """
        super().resizeEvent(event)
        self._share_surplus_width()

    def _share_surplus_width(self) -> None:
        """Apply equal additions to the reference columns that may stretch.

        Returns:
            None: Leaves every base width intact when the table is constrained.
        """
        if not self._stretch_columns or not self._base_widths:
            return
        extra = max(0, self.viewport().width() - sum(self._base_widths))
        share, remainder = divmod(extra, len(self._stretch_columns))
        widths = list(self._base_widths)
        for position, index in enumerate(self._stretch_columns):
            widths[index] += share + int(position < remainder)
        for index, width in enumerate(widths):
            if self.columnWidth(index) != width:
                self.setColumnWidth(index, width)


@dataclass(frozen=True)
class OperatorSnapshot:
    """Transfer bounded durable rows read through their existing owners."""

    imports: tuple[TableRow, ...]
    activity: tuple[ActivityRow, ...]
    flights: tuple[TableRow, ...] | None
    flight_offset: int
    flight_total: int
    log: str | None


@dataclass(frozen=True)
class SelectedImport:
    """Transfer the active import selection's immutable detailed projection."""

    job_id: str
    snapshot: ImportProgressSnapshot | None


@dataclass(frozen=True)
class ReportFolderChoice:
    """Carry one checked report directory back to its current selected row."""

    identity: str
    directory: Path
    has_reports: bool


@dataclass(frozen=True)
class SavedReportCatalog:
    """Carry one selected flight's revision and language hierarchy to Qt."""

    identity: str
    revisions: tuple['SavedReportRevision', ...]


@dataclass(frozen=True)
class SavedReportEdition:
    """Carry one checked language edition and its display label."""

    language: str
    path: Path
    label: str
    created_label: str
    pages_label: str


@dataclass(frozen=True)
class SavedReportRevision:
    """Carry one report revision and its ordered language editions."""

    report_id: str
    number: int | None
    editions: tuple[SavedReportEdition, ...]
    created_label: str
    pages_label: str


def flight_history_window(
    rows: list[SavedFlight], offset: int, page_size: int,
) -> tuple[tuple[SavedFlight, ...], int, int]:
    """Return one bounded page while retaining the full local history count.

    Args:
        rows (list[SavedFlight]): Newest-first local flight inventory.
        offset (int): Requested zero-based page start.
        page_size (int): Maximum number of visible sessions.
    Returns:
        tuple[tuple[SavedFlight, ...], int, int]: Page rows, clamped offset and
            total matching sessions, so every older page remains reachable.
    Raises:
        ValueError: The page size is not positive.
    """
    if page_size <= 0:
        raise ValueError('Flight history page size must be positive.')
    total = len(rows)
    last_offset = ((total - 1) // page_size) * page_size if total else 0
    start = min(max(0, offset), last_offset)
    return tuple(rows[start:start + page_size]), start, total


def report_hierarchy(
    directory: Path, revisions: tuple[ReportRevision, ...],
    cancelled: Event | None = None,
) -> tuple[SavedReportRevision, ...]:
    """Order revisions and their language editions for nested presentation.

    Args:
        directory (Path): Current session used to label legacy report files.
        revisions (tuple[ReportRevision, ...]): Shared report catalog result.
        cancelled (Event | None): Optional selection-generation fence checked
            between each existing PDF metadata read.
    Returns:
        tuple[SavedReportRevision, ...]: Newest revision first with
            language-ordered paths and display labels, or empty when cancelled.
    """
    ordered = sorted(revisions, key=lambda revision: revision.number or 0, reverse=True)
    result: list[SavedReportRevision] = []
    for revision in ordered:
        edition_details: list[tuple[SavedReportEdition, ReportFileDetails]] = []
        for language, path in sorted(revision.files.items()):
            if cancelled is not None and cancelled.is_set():
                return ()
            details = report_file_details(path)
            if cancelled is not None and cancelled.is_set():
                return ()
            created = datetime.fromisoformat(details.created_utc).astimezone().strftime(
                '%Y-%m-%d %H:%M:%S',
            )
            if details.estimated_time:
                created += ' *'
            edition_details.append((SavedReportEdition(
                language, path, report_file_label(directory, revision, language),
                created, str(details.pages) if details.pages is not None else '?',
            ), details))
        latest = max(
            (details for _edition, details in edition_details),
            key=lambda details: details.created_utc,
            default=None,
        )
        created_label = ''
        if latest is not None:
            created_label = datetime.fromisoformat(latest.created_utc).astimezone().strftime(
                '%Y-%m-%d %H:%M:%S',
            )
            if latest.estimated_time:
                created_label += ' *'
        page_values = [details.pages for _edition, details in edition_details]
        pages_label = (
            str(sum(page_values)) if page_values and all(
                pages is not None for pages in page_values
            ) else '?'
        )
        result.append(SavedReportRevision(
            revision.report_id, revision.number,
            tuple(edition for edition, _details in edition_details),
            created_label, pages_label,
        ))
    return tuple(result)


class OperatorTable(QAbstractTableModel):
    """Reuse stable table model rows and update only changed cells."""

    def __init__(self, columns: tuple[str, ...]) -> None:
        """Retain a translated schema for one flat operator table.

        Args:
            columns (tuple[str, ...]): Localized column names in cell order.
        Returns:
            None: Starts empty and ready for bounded snapshots.
        """
        super().__init__()
        self.columns = columns
        self.rows: tuple[TableRow, ...] = ()

    def rowCount(self, parent: QModelIndex = QModelIndex()) -> int:
        """Count current rows without creating per-record Qt widgets.

        Args:
            parent (QModelIndex): Flat root or a hierarchical query.
        Returns:
            int: Root row count or zero for a child index.
        """
        return 0 if parent.isValid() else len(self.rows)

    def columnCount(self, parent: QModelIndex = QModelIndex()) -> int:
        """Count the fixed localized columns.

        Args:
            parent (QModelIndex): Flat root or a hierarchical query.
        Returns:
            int: Root column count or zero for a child index.
        """
        return 0 if parent.isValid() else len(self.columns)

    def data(self, index: QModelIndex, role: int = Qt.ItemDataRole.DisplayRole) -> str | None:
        """Expose one prepared cell as text or an exact selection identity.

        Args:
            index (QModelIndex): Requested row and column in this model.
            role (int): Native display, tooltip or user role.
        Returns:
            str | None: Plain text, exact row ID or no value for another role.
        """
        if not index.isValid() or index.row() >= len(self.rows):
            return None
        row = self.rows[index.row()]
        if role == Qt.ItemDataRole.UserRole:
            return row.identity
        if role == Qt.ItemDataRole.ToolTipRole:
            return row.detail or row.values[index.column()]
        if role == Qt.ItemDataRole.DisplayRole:
            return row.values[index.column()]
        return None

    def headerData(
        self, section: int, orientation: Qt.Orientation, role: int = Qt.ItemDataRole.DisplayRole,
    ) -> str | None:
        """Name a visible horizontal column.

        Args:
            section (int): Zero-based section index.
            orientation (Qt.Orientation): Horizontal column or vertical row.
            role (int): Requested native display or another presentation role.
        Returns:
            str | None: Localized horizontal header or no override.
        """
        if (orientation == Qt.Orientation.Horizontal and role == Qt.ItemDataRole.DisplayRole
                and 0 <= section < len(self.columns)):
            return self.columns[section]
        return None

    def replace(self, rows: tuple[TableRow, ...], table: QTableView) -> None:
        """Apply a projection while retaining selected identity and scroll.

        Args:
            rows (tuple[TableRow, ...]): Ordered current record keys and cells.
            table (QTableView): Current viewer associated with this model.
        Returns:
            None: Modifies existing cell roles in place or restores selection
                after an ordered membership change.
        """
        if rows == self.rows:
            return
        if tuple(row.identity for row in rows) == tuple(row.identity for row in self.rows):
            unchanged = self.rows
            self.rows = rows
            for index, (before, after) in enumerate(zip(unchanged, rows)):
                for column, (previous, current) in enumerate(zip(before.values, after.values)):
                    if previous != current:
                        changed = self.index(index, column)
                        self.dataChanged.emit(changed, changed)
                if before.detail != after.detail:
                    self.dataChanged.emit(
                        self.index(index, 0),
                        self.index(index, len(self.columns) - 1),
                        [Qt.ItemDataRole.ToolTipRole],
                    )
            return
        view_model = table.model()
        current = table.currentIndex()
        source_current = (
            view_model.mapToSource(current)
            if isinstance(view_model, QSortFilterProxyModel) and current.isValid()
            else current
        )
        selected = self.data(source_current, Qt.ItemDataRole.UserRole)
        scroll = table.verticalScrollBar().value()
        self.beginResetModel()
        self.rows = rows
        self.endResetModel()
        if selected is not None:
            for index, row in enumerate(rows):
                if selected == row.identity:
                    source_index = self.index(index, 0)
                    view_index = (
                        view_model.mapFromSource(source_index)
                        if isinstance(view_model, QSortFilterProxyModel)
                        else source_index
                    )
                    if view_index.isValid():
                        table.selectRow(view_index.row())
                    break
        table.verticalScrollBar().setValue(scroll)


class FlightReportTreeItem(QTreeWidgetItem):
    """Sort nested flight roots using typed counts and stable identities."""

    def __lt__(self, other: QTreeWidgetItem) -> bool:
        """Compare visible cells using numeric sample/PDF values when set.

        Args:
            other (QTreeWidgetItem): Sibling tree row to compare.
        Returns:
            bool: Stable ascending comparison for Qt's requested sort direction.
        """
        column = self.treeWidget().sortColumn() if self.treeWidget() else 0
        left_revision = self.data(0, Qt.ItemDataRole.UserRole + 3)
        right_revision = other.data(0, Qt.ItemDataRole.UserRole + 3)
        if left_revision is not None and right_revision is not None:
            return int(left_revision) < int(right_revision)
        left_numeric = self.data(column, Qt.ItemDataRole.UserRole + 2)
        right_numeric = other.data(column, Qt.ItemDataRole.UserRole + 2)
        if left_numeric is not None and right_numeric is not None:
            left_key = int(left_numeric)
            right_key = int(right_numeric)
        else:
            left_key = self.text(column).casefold()
            right_key = other.text(column).casefold()
        if left_key != right_key:
            return left_key < right_key
        left_identity = self.data(0, Qt.ItemDataRole.UserRole)
        right_identity = other.data(0, Qt.ItemDataRole.UserRole)
        return str(left_identity) < str(right_identity)


class FlightCatalogProxy(QSortFilterProxyModel):
    """Filter and type-sort the bounded saved-flight summary table."""

    def __init__(self, parent: QWidget | None = None) -> None:
        """Create an identity-preserving sort and flight-only filter.

        Args:
            parent (QWidget | None): Existing Qt owner or no parent.
        Returns:
            None: Starts with all saved sessions admitted.
        """
        super().__init__(parent)
        self._flights_only = False
        self.setDynamicSortFilter(True)

    def set_flights_only(self, enabled: bool) -> None:
        """Filter sessions without sample or retained PDF evidence.

        Args:
            enabled (bool): Whether to apply Tk's flight-only row predicate.
        Returns:
            None: The source catalog and its stable identities are unchanged.
        """
        if self._flights_only == enabled:
            return
        self._flights_only = enabled
        self.invalidateFilter()

    def filterAcceptsRow(self, source_row: int, source_parent: QModelIndex) -> bool:
        """Keep report-bearing or sampled sessions in flight-only mode.

        Args:
            source_row (int): Candidate row in the bounded source catalog.
            source_parent (QModelIndex): Parent index; the source is flat.
        Returns:
            bool: True when the current filter admits this saved session.
        """
        if not self._flights_only:
            return True
        source = self.sourceModel()
        if source is None:
            return False
        sample_column = source.columns.index(tr(current_language(), 'column_samples'))
        report_column = source.columns.index(tr(current_language(), 'column_pdfs'))
        samples = source.index(source_row, sample_column, source_parent).data()
        reports = source.index(source_row, report_column, source_parent).data()
        try:
            return int(samples or 0) > 0 or int(reports or 0) > 0
        except (TypeError, ValueError):
            return False

    def lessThan(self, left: QModelIndex, right: QModelIndex) -> bool:
        """Compare report dates and counts by their actual value types.

        Args:
            left (QModelIndex): Candidate source cell in the left row.
            right (QModelIndex): Candidate source cell in the right row.
        Returns:
            bool: Stable ascending comparison; Qt applies requested direction.
        """
        left_value = str(left.data() or '')
        right_value = str(right.data() or '')
        source = self.sourceModel()
        column = source.columns[left.column()] if source is not None else ''
        if column in (
            tr(current_language(), 'column_samples'),
            tr(current_language(), 'column_pdfs'),
        ):
            try:
                left_key = int(left_value)
            except ValueError:
                left_key = -1
            try:
                right_key = int(right_value)
            except ValueError:
                right_key = -1
        else:
            left_key = left_value.casefold()
            right_key = right_value.casefold()
        if left_key == right_key:
            return str(left.siblingAtColumn(0).data(Qt.ItemDataRole.UserRole) or '') < str(
                right.siblingAtColumn(0).data(Qt.ItemDataRole.UserRole) or '',
            )
        return left_key < right_key


class OperatorPage(QWidget):
    """Host one model/view workspace with an explicit command boundary."""

    def __init__(
        self, columns: tuple[str, ...], availability: str, refresh: Callable[[], None],
    ) -> None:
        """Create compact controls and a scrollable model/view table.

        Args:
            columns (tuple[str, ...]): Localized table header schema.
            availability (str): Operator-facing explanation of available actions.
            refresh (Callable[[], None]): Requests latest shared read projections.
        Returns:
            None: The workspace owns its table, status and refresh button.
        """
        super().__init__()
        self.layout_body = QVBoxLayout(self)
        self.notice = QLabel(availability)
        self.notice.setWordWrap(True)
        self.notice.setObjectName("notice")
        self.layout_body.addWidget(self.notice)
        self.refresh_button = ActionButton(gettext("Refresh"), refresh)
        self.layout_body.addWidget(self.refresh_button)
        self.model = OperatorTable(columns)
        self.table = QTableView()
        self.table.setModel(self.model)
        self.table.setAlternatingRowColors(True)
        self.table.setSelectionBehavior(QAbstractItemView.SelectionBehavior.SelectRows)
        self.table.setSelectionMode(QAbstractItemView.SelectionMode.SingleSelection)
        self.table.verticalHeader().hide()
        self.table.horizontalHeader().setStretchLastSection(True)
        self.layout_body.addWidget(self.table, stretch=1)
        self.status = QLabel()
        self.status.setWordWrap(True)
        self.status.setObjectName("status")
        self.layout_body.addWidget(self.status)


class ActivityPage(QScrollArea):
    """Present the current Import, durable history, events and local diagnostics."""

    def __init__(
        self, jobs: OperatorPage, items: OperatorTable,
        open_import: Callable[[], None], retry_import: Callable[[], None],
        open_folder: Callable[[], None], open_log: Callable[[], None],
        open_support: Callable[[], None], copy_support: Callable[[], None],
    ) -> None:
        """Compose the same four Activity sections over existing read models.

        Args:
            jobs (OperatorPage): Original Import history and selection owner.
            items (OperatorTable): Live or selected Import's current item model.
            open_import (Callable[[], None]): Existing Import navigation action.
            retry_import (Callable[[], None]): Existing exact selected-job command.
            open_folder (Callable[[], None]): Open the local app profile.
            open_log (Callable[[], None]): Open the existing rotating application log.
            open_support (Callable[[], None]): Show a redacted support report preview.
            copy_support (Callable[[], None]): Copy the redacted local support report.
        Returns:
            None: Adds linked read-only tables and explicit navigation and retry.
        """
        super().__init__()
        body = QWidget()
        layout = QVBoxLayout(body)
        self.setWidget(body)
        self.setWidgetResizable(True)
        current = self._card(layout, gettext('Current import'))
        self.import_summary = QLabel(gettext('No import job selected'))
        self.import_summary.setWordWrap(True)
        self.import_summary.setTextFormat(Qt.TextFormat.PlainText)
        current.addWidget(self.import_summary)
        actions = QHBoxLayout()
        open_button = ActionButton(gettext('Open Import'), open_import)
        self.retry = ActionButton(gettext('Retry failed'), retry_import)
        for action in (open_button, self.retry):
            action.setMinimumHeight(ACTIVITY_ACTION_HEIGHT)
            actions.addWidget(action)
        actions.addStretch()
        current.addLayout(actions)
        self.current_items = self._table(
            items, minimum_rows=ACTIVITY_IMPORT_VISIBLE_ROWS,
            initial_widths=ACTIVITY_IMPORT_COLUMN_WIDTHS,
            stretch_columns=(0, 3),
        )
        current.addWidget(self.current_items)

        history = self._card(layout, gettext('Job history'))
        self.history = self._table(
            jobs.model, minimum_rows=ACTIVITY_HISTORY_VISIBLE_ROWS,
            initial_widths=ACTIVITY_HISTORY_COLUMN_WIDTHS,
            stretch_columns=(1, 3),
        )
        self.history.setSelectionModel(jobs.table.selectionModel())
        history.addWidget(self.history)

        events = self._card(layout, gettext('Recent events'))
        controls = QHBoxLayout()
        controls.addWidget(QLabel(gettext('Show')))
        self.filter = QComboBox()
        self.filter.setAccessibleName(gettext('Show'))
        for key in ('all', 'active', 'succeeded', 'skipped', 'failed', 'warnings'):
            self.filter.addItem(gettext(key), key)
        controls.addWidget(self.filter)
        controls.addWidget(ActionButton(gettext('Retry failed'), retry_import))
        controls.addStretch()
        controls.addWidget(ActionButton(gettext('Support diagnostics…'), open_support))
        events.addLayout(controls)
        self.model = OperatorTable((
            gettext('Time'), gettext('Status'), gettext('File'),
            gettext('Type'), gettext('Event'),
        ))
        self.table = self._table(
            self.model, initial_widths=ACTIVITY_EVENT_COLUMN_WIDTHS,
            stretch_columns=(2, 4),
        )
        self.table.setObjectName("activityEventTable")
        events.addWidget(self.table)
        self.status = QLabel()
        self.status.setObjectName('status')
        events.addWidget(self.status)
        self._rows: tuple[ActivityRow, ...] = ()
        self.filter.currentIndexChanged.connect(self._apply_filter)

        diagnostics = self._card(layout, gettext('Live diagnostics log'))
        log_actions = QHBoxLayout()
        log_actions.addWidget(ActionButton(gettext('Open app folder'), open_folder, icon='folder'))
        log_actions.addWidget(ActionButton(gettext('Open log file'), open_log))
        log_actions.addWidget(ActionButton(gettext('Support diagnostics…'), open_support))
        log_actions.addWidget(ActionButton(gettext('Copy report'), copy_support))
        log_actions.addStretch()
        diagnostics.addLayout(log_actions)
        self.log = QPlainTextEdit()
        self.log.setReadOnly(True)
        self.log.setMinimumHeight(ACTIVITY_CARD_CONTENT_MIN_HEIGHT)
        diagnostics.addWidget(self.log)

    @staticmethod
    def _card(parent: QVBoxLayout, label: str) -> QVBoxLayout:
        """Create one native grouped section in the reference reading order.

        Args:
            parent (QVBoxLayout): Current scrollable Activity column.
            label (str): Localized section title.
        Returns:
            QVBoxLayout: The group's owned content layout.
        """
        card = QFrame()
        card.setObjectName('activityCard')
        card.setProperty('shellCard', True)
        layout = QVBoxLayout(card)
        layout.setContentsMargins(*ACTIVITY_CARD_INSETS)
        heading = QLabel(label)
        heading.setObjectName('heading')
        layout.addWidget(heading)
        parent.addWidget(card)
        return layout

    @staticmethod
    def _table(
        model: OperatorTable, *, minimum_rows: int | None = None,
        initial_widths: tuple[int, ...] = (),
        stretch_columns: tuple[int, ...] = (),
    ) -> QTableView:
        """Mount an existing bounded model in a compact read-only table.

        Args:
            model (OperatorTable): Shared model and stable row identities.
            minimum_rows (int | None): Minimum visible row space in this card.
            initial_widths (tuple[int, ...]): Tk reference width for every column.
            stretch_columns (tuple[int, ...]): Sections sharing surplus width,
                matching the Tk stretch columns for this table.
        Returns:
            QTableView: Table with native row selection and scrolling.
        """
        table = _ActivityStretchTable(model, initial_widths, stretch_columns)
        table.setAlternatingRowColors(True)
        table.setSelectionBehavior(QAbstractItemView.SelectionBehavior.SelectRows)
        table.setSelectionMode(QAbstractItemView.SelectionMode.SingleSelection)
        table.verticalHeader().hide()
        height = ACTIVITY_CARD_CONTENT_MIN_HEIGHT
        if minimum_rows is not None:
            height = (minimum_rows * table.verticalHeader().defaultSectionSize()
                      + table.horizontalHeader().sizeHint().height())
        table.setMinimumHeight(height)
        return table

    def show_events(self, rows: tuple[ActivityRow, ...]) -> None:
        """Retain the latest verified events for local filter changes.

        Args:
            rows (tuple[ActivityRow, ...]): Bounded newest-first Activity projection.
        Returns:
            None: Reconciles only rows admitted by the current filter.
        """
        self._rows = rows
        if self.isVisible():
            self._apply_filter()

    def showEvent(self, event: QShowEvent) -> None:
        """Paint the latest retained Activity projection when shown again.

        Args:
            event (QShowEvent): Native visibility event from the stacked shell.
        Returns:
            None: Updates the current filter view without rereading local state.
        """
        super().showEvent(event)
        self._apply_filter()

    def _apply_filter(self, _index: int = 0) -> None:
        """Apply the reference filters to stored source text and severity.

        Args:
            _index (int): Optional Qt selection index; the stable code is item data.
        Returns:
            None: Updates table rows without repeating reads or mutating history.
        """
        selected = self.filter.currentData()
        rows = self._rows
        if selected == 'warnings':
            rows = tuple(row for row in rows if row.level == 'warning')
        elif selected == 'failed':
            rows = tuple(row for row in rows if row.level == 'error' or 'failed' in row.message)
        elif selected == 'succeeded':
            rows = tuple(row for row in rows if row.level == 'success' or any(
                token in row.message for token in ('uploaded', 'finished', 'stored')
            ))
        elif selected == 'skipped':
            rows = tuple(row for row in rows if 'skip' in row.message or 'duplicate' in row.message)
        elif selected == 'active':
            rows = tuple(row for row in rows if any(token in row.message for token in (
                'started', 'running', 'uploading', 'preflight', 'paused', 'stopping',
            )))
        self.model.replace(tuple(row.row for row in rows), self.table)


class QtOperatorViews:
    """Read durable work through shared services without starting a writer."""

    def __init__(
        self, profile: Path, history_limit: int, parent: QWidget, *,
        open_import: Callable[[], None], retry_import: Callable[[], None],
        open_folder: Callable[[], None], open_log: Callable[[], None],
        open_support: Callable[[], None], copy_support: Callable[[], None],
        sanitize: Callable[[str], str],
    ) -> None:
        """Compose Import, saved flights and Activity history views.

        Args:
            profile (Path): Current admitted WinApp profile.
            history_limit (int): Shared bounded Activity preference.
            parent (QWidget): Main window owning the Qt worker bridges.
            open_import (Callable[[], None]): Existing Import navigation action.
            retry_import (Callable[[], None]): Existing selected-job retry command.
            open_folder (Callable[[], None]): Open the current app profile folder.
            open_log (Callable[[], None]): Open the existing local log.
            open_support (Callable[[], None]): Show a redacted support report preview.
            copy_support (Callable[[], None]): Copy a redacted local report.
            sanitize (Callable[[str], str]): Current profile's credential-aware redactor.
        Returns:
            None: Creates model/view workspaces and their read-only data lanes.
        """
        self.profile = profile
        self._sanitize = sanitize
        self._review_import_id: str | None = None
        self._pinned_import_id: str | None = None
        self._open_log = open_log
        self._report_target: Callable[[], tuple[str, str] | None] | None = None
        self._report_busy: Callable[[], bool] | None = None
        self._report_catalog_identity: str | None = None
        self._flight_page_offset = 0
        self._flight_page_total = 0
        self.store = JobStateStore(profile / "upload_state.json", history_limit, read_only=True)
        self.imports = OperatorPage(
            (gettext("Created"), gettext("Source"), gettext("Target"), gettext("Result")),
            gettext("Select a gallery connection to start an import."), self.refresh,
        )
        self.flights = OperatorPage(
            (
                tr(current_language(), 'column_started'),
                tr(current_language(), 'column_aircraft'),
                tr(current_language(), 'column_samples'),
                tr(current_language(), 'column_pdfs'),
                tr(current_language(), 'column_state'),
            ),
            gettext("Select a saved flight."),
            self.refresh,
        )
        self.flights.notice.hide()
        self.flights.refresh_button.hide()
        flight_toolbar = QWidget()
        flight_toolbar_layout = QHBoxLayout(flight_toolbar)
        flight_toolbar_layout.setContentsMargins(0, 0, 0, 0)
        self.flight_only = ToggleSwitch(tr(current_language(), 'report_flights_only'))
        self.flight_only.setObjectName('savedFlightsOnly')
        self.flight_only.toggled.connect(self._set_flights_only)
        flight_toolbar_layout.addWidget(self.flight_only)
        flight_toolbar_layout.addStretch()
        self.flight_page_previous = ActionButton(
            tr(current_language(), 'flight_history_previous'),
            self._previous_flight_page, icon='back', icon_only=True,
        )
        self.flight_page_previous.setObjectName('savedFlightsPreviousPage')
        self.flight_page_previous.setEnabled(False)
        flight_toolbar_layout.addWidget(self.flight_page_previous)
        self.flight_page_status = QLabel(tr(current_language(), 'flight_history_range').format(
            start=0, end=0, total=0,
        ))
        self.flight_page_status.setObjectName('savedFlightsPageStatus')
        self.flight_page_status.setTextFormat(Qt.TextFormat.PlainText)
        flight_toolbar_layout.addWidget(self.flight_page_status)
        self.flight_page_next = ActionButton(
            tr(current_language(), 'flight_history_next'),
            self._next_flight_page, icon='next', icon_only=True,
        )
        self.flight_page_next.setObjectName('savedFlightsNextPage')
        self.flight_page_next.setEnabled(False)
        flight_toolbar_layout.addWidget(self.flight_page_next)
        self.flight_folder_button = ActionButton(
            tr(current_language(), 'report_folder'), self.open_selected_report_folder,
            icon='folder',
        )
        self.flight_folder_button.setObjectName('savedReportFolderButton')
        flight_toolbar_layout.addWidget(self.flight_folder_button)
        self._saved_report_menu = QMenu(self.flights)
        self._saved_report_menu.addAction(gettext('Open saved PDF'), self.open_pdf)
        self._saved_upload_action = self._saved_report_menu.addAction(
            tr(current_language(), 'upload_saved_pdf'),
        )
        self._saved_upload_action.setEnabled(False)
        self._simbrief_load_action = self._saved_report_menu.addAction(
            tr(current_language(), 'simbrief_load'),
        )
        self._simbrief_load_action.setVisible(False)
        self._simbrief_attach_action = self._saved_report_menu.addAction(
            tr(current_language(), 'simbrief_file'),
        )
        self._simbrief_attach_action.setVisible(False)
        self._saved_report_menu.addSeparator()
        self._saved_report_menu.addAction(
            tr(current_language(), 'refresh_reports'), self.refresh,
        )
        self._saved_report_menu.addAction(
            tr(current_language(), 'open_app_log'), self._open_log,
        )
        self._saved_report_menu.addSeparator()
        self._finish_report_action = self._saved_report_menu.addAction(
            tr(current_language(), 'finish_pdf'),
        )
        self._finish_report_action.setVisible(False)
        more_reports = ActionButton(
            tr(current_language(), 'report_more_actions'), lambda: None,
            icon='more', icon_only=True,
        )
        more_reports.setMenu(self._saved_report_menu)
        flight_toolbar_layout.addWidget(more_reports)
        self.flights.layout_body.insertWidget(2, flight_toolbar)
        self.flight_detail = QLabel(tr(current_language(), 'report_empty'))
        self.flight_detail.setObjectName('status')
        self.flight_detail.setProperty('savedFlightDetail', True)
        self.flight_detail.setTextFormat(Qt.TextFormat.PlainText)
        self.flight_detail.setWordWrap(True)
        self.flights.layout_body.insertWidget(4, self.flight_detail)
        self.flight_target = QLabel()
        self.flight_target.setObjectName('heading')
        self.flight_target.setProperty('savedReportTarget', True)
        self.flight_target.setTextFormat(Qt.TextFormat.PlainText)
        self.flight_target.setWordWrap(True)
        self.flights.layout_body.insertWidget(5, self.flight_target)
        self.flight_target_hint = QLabel()
        self.flight_target_hint.setObjectName('status')
        self.flight_target_hint.setProperty('savedReportTargetHint', True)
        self.flight_target_hint.setWordWrap(True)
        self.flights.layout_body.insertWidget(6, self.flight_target_hint)
        self.report_tree = QTreeWidget()
        self.report_tree.setObjectName('savedFlightReportTree')
        self.report_tree.setAccessibleName(tr(current_language(), 'saved_reports'))
        self.report_tree.setHeaderLabels((
            tr(current_language(), 'report_catalog_entry'),
            tr(current_language(), 'column_started'),
            tr(current_language(), 'column_samples'),
            tr(current_language(), 'column_pdfs'),
            tr(current_language(), 'column_created'),
            tr(current_language(), 'column_pages'),
            tr(current_language(), 'column_state'),
        ))
        self.report_tree.setColumnCount(7)
        header = self.report_tree.header()
        header.setStretchLastSection(False)
        for position, (section, width) in enumerate(FLIGHT_REPORT_COLUMNS):
            header.moveSection(header.visualIndex(section), position)
            self.report_tree.setColumnWidth(section, width)
        self.report_tree.setSortingEnabled(True)
        self.report_tree.sortItems(1, Qt.SortOrder.DescendingOrder)
        self.report_tree.setSelectionMode(QAbstractItemView.SelectionMode.ExtendedSelection)
        self.report_tree.setAlternatingRowColors(True)
        self.report_tree.setMinimumWidth(sum(width for _section, width in FLIGHT_REPORT_COLUMNS))
        self.report_tree.itemActivated.connect(self._open_report_item)
        self.report_tree.currentItemChanged.connect(self._flight_selection_changed)
        self.flights.table.hide()
        self.flights.layout_body.insertWidget(
            self.flights.layout_body.indexOf(flight_toolbar) + 1, self.report_tree, stretch=1,
        )
        self.flight_proxy = FlightCatalogProxy(parent)
        self.flight_proxy.setSourceModel(self.flights.model)
        self.flights.table.setObjectName('savedFlightsTable')
        self.flights.table.setModel(self.flight_proxy)
        self.flights.table.setSortingEnabled(True)
        self.flights.table.sortByColumn(0, Qt.SortOrder.DescendingOrder)
        self._flight_rows: dict[str, TableRow] = {}
        self._report_catalog_identity: str | None = None
        self._lane = QtReadLane[OperatorSnapshot](self._receive, self._finished, parent)
        self.import_items = OperatorTable((
            gettext("File"), gettext("State"), gettext("Tries"), gettext("Result / error"),
        ))
        self.import_table = QTableView()
        self.import_table.setModel(self.import_items)
        self.import_table.setAlternatingRowColors(True)
        self.import_table.verticalHeader().hide()
        self.import_table.horizontalHeader().setStretchLastSection(True)
        self.imports.layout_body.addWidget(self.import_table, stretch=1)
        self.import_summary = QLabel()
        self.import_summary.setWordWrap(True)
        self.import_summary.setObjectName("status")
        self.imports.layout_body.addWidget(self.import_summary)
        self.activity = ActivityPage(
            self.imports, self.import_items, open_import, retry_import,
            open_folder, open_log, open_support, copy_support,
        )
        self.pages = (self.imports, self.activity, self.flights)
        self.activity.retry.setEnabled(False)
        self._import_lane = QtReadLane[SelectedImport](
            self._show_import, self._import_finished, parent,
        )
        self.imports.table.selectionModel().currentChanged.connect(self._read_import)
        self._pdf_lane = QtReadLane[SavedReportCatalog](
            self._choose_pdf, self._pdf_finished, parent,
        )
        self._folder_lane = QtReadLane[ReportFolderChoice](
            self._show_report_folder, self._report_folder_finished, parent,
        )
        self.refresh_flight_target()

    def configure_saved_report_actions(
        self, *, upload: Callable[[], None] | None,
        load_dispatch: Callable[[], None] | None,
        attach_dispatch: Callable[[], None] | None,
        finish: Callable[[], None] | None,
        report_busy: Callable[[], bool] | None,
        report_target: Callable[[], tuple[str, str] | None] | None,
    ) -> None:
        """Bind only existing Qt report and saved-flight command owners.

        Args:
            upload (Callable[[], None] | None): Existing report upload action.
            load_dispatch (Callable[[], None] | None): Saved-flight SimBrief fetch.
            attach_dispatch (Callable[[], None] | None): Existing selected-flight
                OFP attachment action, or None without Capture support.
            finish (Callable[[], None] | None): Existing Watcher Finish action,
                or None without Capture support.
            report_busy (Callable[[], bool] | None): Existing report worker state.
            report_target (Callable[[], tuple[str, str] | None] | None): Read-only
                active Watcher target label provider, or None without Capture.
        Returns:
            None: Saved Reports gains no duplicate writer or authorization owner.
        """
        self._report_busy = report_busy
        self._report_target = report_target
        self._saved_upload_action.setEnabled(upload is not None)
        if upload is not None:
            self._saved_upload_action.triggered.connect(upload)
        self._simbrief_load_action.setVisible(load_dispatch is not None)
        if load_dispatch is not None:
            self._simbrief_load_action.triggered.connect(load_dispatch)
        self._simbrief_attach_action.setVisible(attach_dispatch is not None)
        if attach_dispatch is not None:
            self._simbrief_attach_action.triggered.connect(attach_dispatch)
        self._finish_report_action.setVisible(finish is not None)
        if finish is not None:
            self._finish_report_action.triggered.connect(finish)
        self.refresh_flight_target()

    def refresh_flight_target(self) -> None:
        """Show the active Watcher destination for saved-report delivery.

        Returns:
            None: Displays only the current safe label, never its binding token.
        """
        target = self._report_target() if self._report_target is not None else None
        if target is None:
            text = tr(current_language(), 'report_watcher_off')
            hint = tr(current_language(), 'report_start_watcher')
        else:
            text = target[1]
            hint = tr(current_language(), 'report_watched_gallery')
        if self.flight_target.text() != text:
            self.flight_target.setText(text)
        if self.flight_target_hint.text() != hint:
            self.flight_target_hint.setText(hint)

    @staticmethod
    def _format_started_utc(timestamp: str) -> str:
        """Format stored UTC timestamps like the native Tk archive.

        Args:
            timestamp (str): ISO 8601 start time from the saved-flight row.
        Returns:
            str: UTC date and time, or the original value if malformed.
        """
        try:
            parsed = datetime.fromisoformat(timestamp.replace('Z', '+00:00'))
            if parsed.tzinfo is None:
                parsed = parsed.replace(tzinfo=timezone.utc)
            return parsed.astimezone(timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
        except (ValueError, OverflowError):
            return timestamp

    def _set_flights_only(self, enabled: bool) -> None:
        """Apply the report browser's persisted-sample/PDF filter.

        Args:
            enabled (bool): Whether to hide sessions without flight evidence.
        Returns:
            None: Reloads the bounded page using the selected catalog scope.
        """
        self.flight_proxy.set_flights_only(enabled)
        self._flight_page_offset = 0
        self.refresh()

    def _previous_flight_page(self) -> None:
        """Show the previous bounded page of the complete saved-flight catalog.

        Returns:
            None: Keeps the GUI model bounded while older sessions stay reachable.
        """
        self._flight_page_offset = max(0, self._flight_page_offset - HISTORY_ROWS)
        self.refresh()

    def _next_flight_page(self) -> None:
        """Show the next bounded page when older saved sessions remain.

        Returns:
            None: Advances only within the newest-first local flight inventory.
        """
        if self._flight_page_offset + HISTORY_ROWS < self._flight_page_total:
            self._flight_page_offset += HISTORY_ROWS
            self.refresh()

    def _flight_root(self, item: QTreeWidgetItem | None) -> QTreeWidgetItem | None:
        """Return the flight root owning one selected catalog row.

        Args:
            item (QTreeWidgetItem | None): Current root, revision or edition.
        Returns:
            QTreeWidgetItem | None: Owning flight item or no selected session.
        """
        while item is not None and item.parent() is not None:
            item = item.parent()
        data = item.data(0, Qt.ItemDataRole.UserRole) if item is not None else None
        return item if data and data[0] == 'flight' else None

    def _show_flight_detail(self) -> None:
        """Present current saved-flight metadata and its local action hint.

        Returns:
            None: Selection stays owned by the stable source model identity.
        """
        root = self._flight_root(self.report_tree.currentItem())
        data = root.data(0, Qt.ItemDataRole.UserRole) if root is not None else None
        row = self._flight_rows.get(data[1]) if data and data[0] == 'flight' else None
        if row is None:
            detail = tr(current_language(), 'report_empty')
        else:
            detail = ' · '.join((
                row.values[1], self._format_started_utc(row.values[0]),
                tr(current_language(), 'column_samples') + ': ' + row.values[2],
                tr(current_language(), 'column_pdfs') + ': ' + row.values[3],
                row.values[4],
                tr(current_language(), 'report_open_hint'),
            ))
            if row.detail:
                detail += '\n' + row.detail
        self.flight_detail.setText(detail)
        self.flight_folder_button.setEnabled(row is not None)

    def open_selected_report_folder(self) -> None:
        """Organize and open the selected session's retained-PDF directory.

        Returns:
            None: Uses the shared workspace lease before restartable PDF moves.
        """
        if self._report_busy is not None and self._report_busy():
            self.flights.status.setText(tr(current_language(), 'session_progress'))
            return
        directory = self.selected_flight_directory()
        if directory is None:
            self.flights.status.setText(tr(current_language(), 'select_saved_flight'))
            return
        identity = str(directory)

        def inspect(
            cancelled: Event, publish: Callable[[ReportFolderChoice], None],
        ) -> None:
            """Validate, organize and select the folder under the shared lease.

            Args:
                cancelled (Event): Current folder action and shutdown fence.
                publish (Callable[[ReportFolderChoice], None]): GUI-thread result.
            Returns:
                None: Reuses Tk's restartable organizer after obtaining the
                    existing cross-process workspace lease.
            """
            root = self.profile / 'flights'
            if (directory.is_symlink()
                    or directory.resolve(strict=True).parent != root.resolve(strict=True)):
                raise ValueError('Unsafe report directory.')
            lease = WorkspaceLease(root)
            try:
                lease.verify()
                if (directory.is_symlink()
                        or directory.resolve(strict=True).parent != root.resolve(strict=True)):
                    raise ValueError('Unsafe report directory.')
                revisions = report_revisions(directory)
                reports = directory / 'reports'
                if not revisions:
                    if not cancelled.is_set():
                        publish(ReportFolderChoice(identity, reports, False))
                    return
                if (reports.is_symlink()
                        or not reports.resolve(strict=True).is_relative_to(
                            directory.resolve(strict=True),
                        )):
                    raise ValueError('Unsafe report directory.')
                if cancelled.is_set():
                    return
                organize_reports(directory)
                lease.verify()
                publish(ReportFolderChoice(identity, reports, True))
            finally:
                lease.release()

        self._folder_lane.submit(inspect)

    def _show_report_folder(self, choice: ReportFolderChoice) -> None:
        """Open only the report directory still owned by current selection.

        Args:
            choice (ReportFolderChoice): Checked folder and originating row.
        Returns:
            None: A stale selection cannot open an earlier flight's directory.
        """
        selected = self.selected_flight_directory()
        if selected is None or str(selected) != choice.identity:
            return
        if self._report_busy is not None and self._report_busy():
            self.flights.status.setText(tr(current_language(), 'session_progress'))
            return
        if not choice.has_reports:
            self.flights.status.setText(gettext('No saved PDFs for this flight.'))
            return
        QDesktopServices.openUrl(QUrl.fromLocalFile(str(choice.directory)))

    def _report_folder_finished(self, failure: str) -> None:
        """Show one fixed error if the selected report folder was unreadable.

        Args:
            failure (str): Sanitized exception type or empty success status.
        Returns:
            None: Leaves the prior selection and all report files unchanged.
        """
        if failure:
            self.flights.status.setText(gettext('Saved reports could not be read.'))

    def _flight_selection_changed(
        self, current: QTreeWidgetItem | None, previous: QTreeWidgetItem | None,
    ) -> None:
        """Refresh selection detail and its nested saved-report catalogue.

        Args:
            current (QTreeWidgetItem | None): Current flight or nested row.
            previous (QTreeWidgetItem | None): Former selected tree row.
        Returns:
            None: No late report or folder result can outlive its selected flight.
        """
        current_root = self._flight_root(current)
        previous_root = self._flight_root(previous)
        current_data = current_root.data(0, Qt.ItemDataRole.UserRole) if current_root else None
        previous_data = previous_root.data(0, Qt.ItemDataRole.UserRole) if previous_root else None
        if current_data == previous_data and current_data is not None:
            self._show_flight_detail()
            return
        self._pdf_lane.cancel()
        self._folder_lane.cancel()
        self._show_flight_detail()
        if previous_root is not None:
            previous_root.takeChildren()
        self._report_catalog_identity = None
        directory = self.selected_flight_directory()
        if directory is not None:
            self._request_report_catalog(directory)

    def selected_import_id(self) -> str | None:
        """Read the exact current Import history row from the native model.

        Returns:
            str | None: Stable selected job identity or no current selection.
        """
        return self.imports.model.data(self.imports.table.currentIndex(), Qt.ItemDataRole.UserRole)

    def set_import_busy(self, busy: bool) -> None:
        """Fence selected-history reads while an explicit operation presents progress.

        Args:
            busy (bool): Whether current preflight or upload owns the item table.
        Returns:
            None: Cancels obsolete selection projection and locks the selection.
        """
        if busy:
            self._import_lane.cancel()
            self._pinned_import_id = None
        self.imports.table.setEnabled(not busy)
        self.activity.history.setEnabled(not busy)
        self.activity.retry.setEnabled(not busy and self.selected_import_id() is not None)

    def review_startup_import(self, job_id: str) -> None:
        """Request an explicit review row without starting an import.

        Args:
            job_id (str): Exact newest recoverable job verified at startup.
        Returns:
            None: Pins the selected job in the visible bounded current history.
        """
        self._review_import_id = job_id
        self._pinned_import_id = job_id
        self.refresh()

    def refresh(self) -> None:
        """Read one current projection for the operator workspaces.

        Returns:
            None: Requests a cancellable immutable local result off-thread.
        """
        for page in self.pages:
            if page.isVisible() and not page.model.rows:
                page.status.setText(gettext("Loading…"))
        requested = self._pinned_import_id
        include_log = self.activity.isVisible()
        include_flights = self.flights.isVisible() and not self.flights.window().isMinimized()
        flight_offset = self._flight_page_offset
        flights_only = self.flight_only.isChecked()
        self._lane.submit(lambda cancelled, publish: self._read(
            cancelled, publish, requested, include_log, include_flights, flight_offset, flights_only,
        ))

    def _read(
        self, cancelled: Event, publish: Callable[[OperatorSnapshot], None],
        review: str | None, include_log: bool, include_flights: bool, flight_offset: int,
        flights_only: bool,
    ) -> None:
        """Read existing state and prepare bounded primitive presentation rows.

        Args:
            cancelled (Event): Current request lifetime and shutdown fence.
            publish (Callable[[OperatorSnapshot], None]): GUI-bound worker sink.
            review (str | None): Exact eligible startup recovery history ID or None.
            include_log (bool): The operator explicitly opened Activity or refreshed it.
            include_flights (bool): The Saved Flights viewer is visible and unminimized.
            flight_offset (int): Requested bounded newest-first Flight page start.
            flights_only (bool): Whether sessions without samples or PDFs are omitted.
        Returns:
            None: Sends one immutable snapshot without writing shared state.
        """
        jobs = self.store.list_job_history(HISTORY_ROWS)
        if review is not None and not any(row.job_id == review for row in jobs):
            _job, detail = read_import_detail(self.store, review, 0, new_worker_generation(), 0)
            if detail is not None:
                jobs = [detail.history, *jobs[:HISTORY_ROWS - 1]]
        events = self.store.list_events()[-self.store.history_limit:]
        flights: tuple[SavedFlight, ...] | None = None
        flight_total = 0
        if include_flights and not cancelled.is_set():
            all_flights = saved_flights(self.profile / "flights")
            if flights_only:
                all_flights = [
                    row for row in all_flights if row.sample_count > 0 or row.report_count > 0
                ]
            flights, flight_offset, flight_total = flight_history_window(
                all_flights, flight_offset, HISTORY_ROWS,
            )
        if cancelled.is_set():
            return
        activity = tuple(
            ActivityRow(
                TableRow(f"{event.timestamp}/{index}", (
                    time.strftime('%H:%M:%S', time.localtime(event.timestamp)),
                    gettext(event.level), Path(event.filename).name if event.filename else '',
                    gettext(event.operation), self._activity_text(event),
                )),
                event.level.lower(), event.message.lower(),
            )
            for index, event in reversed(tuple(enumerate(events)))
        )
        log = None
        if include_log:
            _status, excerpt = read_support_log(self.profile / 'watcher.log')
            log = self._sanitize(excerpt)
        publish(OperatorSnapshot(
            imports=tuple(TableRow(
                job.job_id, tuple(str(value) for value in job.row_values(
                    time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(job.created_at)),
                )),
            ) for job in jobs),
            activity=activity,
            flights=tuple(TableRow(str(row.directory), (
                row.started_utc,
                row.aircraft or row.gallery_identity
                or tr(current_language(), 'report_gallery_session'),
                str(row.sample_count), str(row.report_count),
                tr(current_language(), 'report_state_' + (
                    'error' if row.error else 'completed' if row.completed
                    else 'saved' if row.recording_ended else 'unfinished'
                )),
            ), self._sanitize(row.error)) for row in flights) if flights is not None else None,
            flight_offset=flight_offset,
            flight_total=flight_total,
            log=log,
        ))

    def _activity_text(self, event: ActivityEvent) -> str:
        """Project a historical event without treating arbitrary text as markup.

        Args:
            event (ActivityEvent): Stored source event, possibly bearing a message template.
        Returns:
            str: Localized and redacted caption, or the stored safe message.
        """
        caption = gettext(event.message_id or event.message)
        if caption != (event.message_id or event.message) and event.message_fields:
            try:
                caption = caption.format_map(event.message_fields)
            except (KeyError, ValueError):
                caption = event.message
        elif event.message_id and caption == event.message_id:
            caption = event.message
        return self._sanitize(redact_text(caption, [], redact_paths=False))

    def _receive(self, snapshot: OperatorSnapshot) -> None:
        """Apply one current projection through the existing Qt models.

        Args:
            snapshot (OperatorSnapshot): Newest owned worker result.
        Returns:
            None: Updates allowed cells on the GUI thread.
        """
        self.imports.model.replace(snapshot.imports, self.imports.table)
        if (snapshot.flights is not None and self.flights.isVisible()
                and not self.flights.window().isMinimized()):
            self._receive_flights(snapshot.flights, snapshot.flight_offset, snapshot.flight_total)
        self.activity.show_events(snapshot.activity)
        if (snapshot.log is not None and self.activity.isVisible()
                and self.activity.log.toPlainText() != snapshot.log):
            self.activity.log.setPlainText(snapshot.log)
        if self._review_import_id is not None and self.imports.table.isEnabled():
            for index, row in enumerate(snapshot.imports):
                if row.identity == self._review_import_id:
                    self.imports.table.selectRow(index)
                    break
            self._review_import_id = None

    def _receive_flights(
        self, rows: tuple[TableRow, ...], offset: int, total: int,
    ) -> None:
        """Update only the open Flight browser and its currently selected catalog.

        Args:
            rows (tuple[TableRow, ...]): One valid page of saved-flight metadata.
            offset (int): First row's zero-based index in the filtered history.
            total (int): Full number of saved flights matching the current filter.
        Returns:
            None: Preserves the current flight when it remains on this page.
        """
        selected_before = self.selected_flight_directory()
        self.flights.model.replace(rows, self.flights.table)
        self._flight_rows = {row.identity: row for row in rows}
        self._flight_page_offset = offset
        self._flight_page_total = total
        visible_end = offset + len(rows)
        self.flight_page_status.setText(tr(current_language(), 'flight_history_range').format(
            start=offset + 1 if rows else 0, end=visible_end, total=total,
        ))
        self.flight_page_previous.setEnabled(offset > 0)
        self.flight_page_next.setEnabled(visible_end < total)
        self._pdf_lane.cancel()
        self._folder_lane.cancel()
        self._report_catalog_identity = None
        blocker = QSignalBlocker(self.report_tree)
        self.report_tree.clear()
        visible_roots: list[QTreeWidgetItem] = []
        for row in rows:
            root = FlightReportTreeItem((
                row.values[1], self._format_started_utc(row.values[0]),
                row.values[2], row.values[3],
                '', '', row.values[4],
            ))
            root.setData(0, Qt.ItemDataRole.UserRole, ('flight', row.identity))
            root.setData(2, Qt.ItemDataRole.UserRole + 2, int(row.values[2] or 0))
            root.setData(3, Qt.ItemDataRole.UserRole + 2, int(row.values[3] or 0))
            if row.detail:
                root.setToolTip(0, row.detail)
            if self.flight_only.isChecked() and not (
                int(row.values[2] or 0) > 0 or int(row.values[3] or 0) > 0
            ):
                root.setHidden(True)
            self.report_tree.addTopLevelItem(root)
            if not root.isHidden():
                visible_roots.append(root)
        selected_root = next((
            item for item in visible_roots
            if selected_before is not None
            and item.data(0, Qt.ItemDataRole.UserRole) == ('flight', str(selected_before))
        ), None)
        if selected_root is None and visible_roots:
            selected_root = visible_roots[0]
        self.report_tree.setCurrentItem(selected_root)
        del blocker
        self._show_flight_detail()
        selected_flight = self.selected_flight_directory()
        if selected_flight is not None:
            self._request_report_catalog(selected_flight)
        self.refresh_flight_target()

    def _finished(self, failure: str) -> None:
        """Show the pending read's terminal status.

        Args:
            failure (str): Safe failure name or empty after a successful read.
        Returns:
            None: The previous valid rows stay usable if the read failed.
        """
        for page in self.pages:
            page.status.setText(
                gettext("Saved history could not be read.") if failure
                else gettext("No saved records.") if not page.model.rows else "",
            )

    def open_pdf(self) -> None:
        """Open the selected edition or load its current report hierarchy.

        Returns:
            None: Opens each selected safe edition; group rows load their
                current hierarchy without treating a folder as a PDF path.
        """
        selected = self.report_tree.selectedItems()
        editions = [
            item for item in selected
            if (item.data(0, Qt.ItemDataRole.UserRole)
                and item.data(0, Qt.ItemDataRole.UserRole)[0] == 'edition')
        ]
        if editions:
            for item in editions:
                if not self._open_report_item(item, 0):
                    break
            return
        directory = self.selected_flight_directory()
        if directory is None:
            self.flights.status.setText(gettext("Select a saved flight."))
            return
        root = self._flight_root(self.report_tree.currentItem())
        if (root is not None and root.data(0, Qt.ItemDataRole.UserRole) == (
                'flight', str(directory),
        ) and root.data(0, Qt.ItemDataRole.UserRole + 1) is True):
            self.flights.status.setText(tr(current_language(), 'report_open_hint'))
            return
        if self._pdf_lane.busy():
            self.flights.status.setText(gettext('Loading…'))
            return
        self._request_report_catalog(directory)

    def _request_report_catalog(self, directory: Path) -> None:
        """Read the selected flight's report hierarchy off the GUI lane.

        Args:
            directory (Path): Exact currently selected saved-flight directory.
        Returns:
            None: Replaces the tree only when this row still owns the result.
        """
        identity = str(directory)
        self._report_catalog_identity = identity
        root = self._flight_root(self.report_tree.currentItem())
        if root is None or root.data(0, Qt.ItemDataRole.UserRole) != ('flight', identity):
            return
        root.takeChildren()
        root.setData(0, Qt.ItemDataRole.UserRole + 1, False)

        def read_catalog(
            cancelled: Event, publish: Callable[[SavedReportCatalog], None],
        ) -> None:
            """Read and label revisions before crossing into the Qt thread.

            Args:
                cancelled (Event): Selection and shutdown generation fence.
                publish (Callable[[SavedReportCatalog], None]): Qt result sink.
            Returns:
                None: Discards stale results before or after filesystem reads.
            """
            if cancelled.is_set():
                return
            revisions = tuple(report_revisions(directory))
            if cancelled.is_set():
                return
            hierarchy = report_hierarchy(directory, revisions, cancelled)
            if not cancelled.is_set():
                publish(SavedReportCatalog(identity, hierarchy))

        self._pdf_lane.submit(read_catalog)

    def selected_flight_directory(self) -> Path | None:
        """Return the explicit selection from the owned local flight catalog.

        Returns:
            Path | None: Current stable flight identity or no current selection.
        """
        root = self._flight_root(self.report_tree.currentItem())
        data = root.data(0, Qt.ItemDataRole.UserRole) if root is not None else None
        return Path(data[1]) if data and data[0] == 'flight' else None

    def saved_report_upload_selection(
        self,
    ) -> tuple[Path | None, tuple[tuple[str, str], ...], bool, bool]:
        """Capture one explicit flight and its selected revision/language leaves.

        Returns:
            tuple[Path | None, tuple[tuple[str, str], ...], bool, bool]:
                Directory, exact edition identities, multi-flight rejection flag,
                and whether the operator selected a tree row.
        """
        selected = self.report_tree.selectedItems()
        current = self.report_tree.currentItem()
        if current is not None and current not in selected:
            selected.append(current)
        roots = {
            str(root.data(0, Qt.ItemDataRole.UserRole)[1]): root
            for root in (self._flight_root(item) for item in selected)
            if root is not None
        }
        if len(roots) > 1:
            return None, (), True, True
        if not roots:
            return None, (), False, False
        identity, root = next(iter(roots.items()))
        editions: list[tuple[str, str]] = []

        def add_item(item: QTreeWidgetItem) -> None:
            """Collect explicitly selected leaves or a selected group subtree.

            Args:
                item (QTreeWidgetItem): One selected root, revision or edition.
            Returns:
                None: Appends only revision/language identities from this flight.
            """
            data = item.data(0, Qt.ItemDataRole.UserRole)
            if data and data[0] == 'edition' and data[1] == identity:
                editions.append((data[2], data[3]))
                return
            for child_index in range(item.childCount()):
                add_item(item.child(child_index))

        for item in selected:
            selected_root = self._flight_root(item)
            if selected_root is root:
                add_item(item)
        return Path(identity), tuple(dict.fromkeys(editions)), False, True

    def _read_import(self, current: QModelIndex, _previous: QModelIndex) -> None:
        """Request the exact selected import's bounded historical projection.

        Args:
            current (QModelIndex): Current row in the import history model.
            _previous (QModelIndex): Earlier selection that lost its ownership.
        Returns:
            None: Clears the old issues and queues a replacement detail read.
        """
        if not self.imports.table.isEnabled():
            return
        job_id = self.imports.model.data(current, Qt.ItemDataRole.UserRole)
        self.activity.retry.setEnabled(job_id is not None)
        self._import_lane.cancel()
        self.import_items.replace((), self.activity.current_items)
        if job_id is None:
            self._set_import_summary(gettext('No import job selected'))
            return
        generation = new_worker_generation()
        self._set_import_summary(gettext('Loading…'))

        def load(cancelled: Event, publish: Callable[[SelectedImport], None]) -> None:
            """Project one current job without taking a mutation lease.

            Args:
                cancelled (Event): Selection or shutdown fence.
                publish (Callable[[SelectedImport], None]): Current GUI read sink.
            Returns:
                None: Publishes only this selected job's immutable data.
            """
            if cancelled.is_set():
                return
            _job, snapshot = read_import_detail(self.store, job_id, IMPORT_ITEM_ROWS, generation, 0)
            publish(SelectedImport(job_id, snapshot))

        self._import_lane.submit(load)

    def _show_import(self, selection: SelectedImport) -> None:
        """Apply saved attempts, states and counts to the selected history row.

        Args:
            selection (SelectedImport): Exact current selection and bounded details.
        Returns:
            None: Shows the accepted item projection without changing its status.
        """
        if selection.job_id != self.imports.model.data(
            self.imports.table.currentIndex(), Qt.ItemDataRole.UserRole,
        ):
            return
        snapshot = selection.snapshot
        if snapshot is None:
            self._set_import_summary(gettext('No saved records.'))
            return
        self.show_import_snapshot(snapshot)

    def show_import_snapshot(self, snapshot: ImportProgressSnapshot) -> None:
        """Update visible item rows for a verified historical or live projection.

        Args:
            snapshot (ImportProgressSnapshot): Bounded full row set and counters.
        Returns:
            None: Modifies model data without changing accepted job state.
        """
        rows = tuple(
            TableRow(item.item_id, tuple(str(value) for value in item.activity_values()))
            for item in snapshot.items
        )
        self.import_items.replace(rows, self.activity.current_items)
        self._set_import_summary(snapshot.history.status)

    def _set_import_summary(self, text: str) -> None:
        """Reflect the same asynchronous selected job state in both views.

        Args:
            text (str): Localized read status or verified job summary.
        Returns:
            None: Keeps loading, error and settled information visible in Activity.
        """
        self.import_summary.setText(text)
        self.activity.import_summary.setText(text)

    def _import_finished(self, failure: str) -> None:
        """Expose failure of a selected-job read without offering unsafe retry.

        Args:
            failure (str): Sanitized failure class or empty after success.
        Returns:
            None: Shows a local history error when the read cannot be verified.
        """
        if failure:
            self._set_import_summary(gettext('Saved history could not be read.'))

    def _choose_pdf(self, catalog: SavedReportCatalog) -> None:
        """Build flight→revision→language PDF rows for the selected session.

        Args:
            catalog (SavedReportCatalog): Current flight identity and revisions.
        Returns:
            None: Presents only revision and edition paths from shared services.
        """
        selected = self.selected_flight_directory()
        if selected is None or str(selected) != catalog.identity:
            return
        root = self._flight_root(self.report_tree.currentItem())
        if root is None:
            return
        root.takeChildren()
        hierarchy = catalog.revisions
        multiple = len(hierarchy) > 1
        for revision in hierarchy:
            parent = root
            if multiple:
                parent = FlightReportTreeItem(root)
                parent.setText(0, 'v' + str(revision.number))
                parent.setText(3, str(len(revision.editions)))
                parent.setText(4, revision.created_label)
                parent.setText(5, revision.pages_label)
                parent.setText(6, tr(current_language(), 'report_version'))
                parent.setData(0, Qt.ItemDataRole.UserRole, (
                    'revision', catalog.identity, revision.report_id,
                ))
                parent.setData(
                    0, Qt.ItemDataRole.UserRole + 3, revision.number or 0,
                )
                parent.setData(3, Qt.ItemDataRole.UserRole + 2, len(revision.editions))
                if revision.pages_label.isdecimal():
                    parent.setData(
                        5, Qt.ItemDataRole.UserRole + 2, int(revision.pages_label),
                    )
            for edition_data in revision.editions:
                edition = FlightReportTreeItem(parent)
                edition.setText(
                    0, edition_data.language.upper() + ' · ' + edition_data.label,
                )
                edition.setText(4, edition_data.created_label)
                edition.setText(5, edition_data.pages_label)
                edition.setText(6, 'PDF')
                if edition_data.pages_label.isdecimal():
                    edition.setData(
                        5, Qt.ItemDataRole.UserRole + 2, int(edition_data.pages_label),
                    )
                edition.setToolTip(0, edition_data.path.name)
                edition.setData(0, Qt.ItemDataRole.UserRole, (
                    'edition', catalog.identity, revision.report_id,
                    edition_data.language, str(edition_data.path),
                ))
        root.setExpanded(True)
        root.setData(0, Qt.ItemDataRole.UserRole + 1, True)
        if hierarchy and multiple:
            root.child(0).setExpanded(True)
        self.flights.status.setText('')

    def _open_report_item(self, item: QTreeWidgetItem, _column: int) -> bool:
        """Open an edition from the verified flight that owns its tree leaf.

        Args:
            item (QTreeWidgetItem): Activated row from the report hierarchy.
            _column (int): Activated column, unused by file ownership.
        Returns:
            bool: True after submitting the confined PDF to the native opener.
                False for an unrelated row or unavailable original.
        """
        data = item.data(0, Qt.ItemDataRole.UserRole)
        if not data or data[0] != 'edition' or item.treeWidget() is not self.report_tree:
            return False
        root = self._flight_root(item)
        if root is None or root.data(0, Qt.ItemDataRole.UserRole)[1] != data[1]:
            return False
        selected = Path(data[1])
        reports = selected / 'reports'
        pdf = Path(data[4])
        try:
            if (selected.is_symlink() or reports.is_symlink() or pdf.is_symlink()
                    or pdf.parent.is_symlink()
                    or not pdf.is_file()
                    or not pdf.resolve(strict=True).is_relative_to(
                        reports.resolve(strict=True),
                    )):
                raise ValueError('Saved PDF cannot redirect.')
        except (OSError, ValueError, RuntimeError):
            self.flights.status.setText(gettext('Saved reports could not be read.'))
            return False
        QDesktopServices.openUrl(QUrl.fromLocalFile(str(pdf)))
        return True

    def _pdf_finished(self, failure: str) -> None:
        """Expose an unreadable catalogue without opening an unverified path.

        Args:
            failure (str): Safe read outcome, empty on normal completion.
        Returns:
            None: Shows a recoverable local report error when applicable.
        """
        if failure:
            self.flights.status.setText(gettext("Saved reports could not be read."))

    def close(self) -> None:
        """Withdraw pending read work as the Qt window exits.

        Returns:
            None: Cancels all view-owned reads before widget teardown.
        """
        self._lane.close()
        self._import_lane.close()
        self._pdf_lane.close()
        self._folder_lane.close()
