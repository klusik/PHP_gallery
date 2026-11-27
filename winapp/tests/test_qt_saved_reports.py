# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_qt_saved_reports.py
# Module Type: Qt saved-report catalog regression
# Purpose: Verify saved-report hierarchy, filtering and action identity.
# Responsibilities: Cover nested report projection, filtering and PDF actions.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Exercise saved-flight filtering and edition actions without report writers."""

import os
import sys
import tempfile
import unittest
from pathlib import Path
from threading import Event
from types import SimpleNamespace
from unittest.mock import Mock, patch

from native_qt_fixture import NativeQtTestCase

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

try:
    from PySide6.QtCore import QItemSelectionModel, Qt
    from PySide6.QtWidgets import (
        QAbstractItemView, QApplication, QLabel, QPushButton, QTableView, QTreeWidget, QWidget,
    )
    from uploader.flight_report_translations import tr
    from uploader.flight_jobs import ReportFileDetails, ReportRevision, SavedFlight
    from uploader.localization import current_language
    from uploader.qt_flight_reports import QtFlightReports
    from uploader.qt_operator_views import (
        FlightCatalogProxy, FlightReportTreeItem, OperatorTable, QtOperatorViews,
        TableRow, flight_history_window, report_hierarchy,
    )
    from uploader.qt_theme import ToggleSwitch
except (ImportError, OSError):
    if os.environ.get('PHP_GALLERY_REQUIRE_QT') == '1':
        raise
    QApplication = None


@unittest.skipIf(QApplication is None, "Optional Qt dependencies are absent.")
class QtSavedReportCatalogTests(NativeQtTestCase):
    """Verify the saved-report view keeps only current stable row identities."""

    @classmethod
    def setUpClass(cls) -> None:
        """Create the one Qt application required by the model/view fixture.

        Returns:
            None: No window or report writer is started by these tests.
        """
        if cls.delegate_native_cases():
            return
        cls.application = QApplication.instance() or QApplication([])

    def test_filter_sort_and_refresh_preserve_selected_session(self) -> None:
        """Filter empty sessions and retain the selected identity after refresh.

        Returns:
            None: Sample/PDF counts use numeric sort and filtering semantics.
        """
        columns = (
            tr(current_language(), 'column_started'),
            tr(current_language(), 'column_aircraft'),
            tr(current_language(), 'column_samples'),
            tr(current_language(), 'column_pdfs'),
            tr(current_language(), 'column_state'),
        )
        source = OperatorTable(columns)
        proxy = FlightCatalogProxy()
        proxy.setSourceModel(source)
        table = QTableView()
        table.setModel(proxy)
        source.replace((
            TableRow('session-empty', ('2026-11-01T00:00:00Z', 'Gallery', '0', '0', 'Recorded')),
            TableRow('session-many', ('2026-11-03T00:00:00Z', 'A320', '12', '1', 'Finished')),
            TableRow('session-few', ('2026-11-02T00:00:00Z', 'C172', '2', '0', 'Interrupted')),
        ), table)
        self.assertEqual(3, proxy.rowCount())

        proxy.set_flights_only(True)
        self.assertEqual(2, proxy.rowCount())
        proxy.sort(2, Qt.SortOrder.DescendingOrder)
        self.assertEqual(
            'session-many',
            proxy.index(0, 0).data(Qt.ItemDataRole.UserRole),
        )
        selected_row = next(
            index for index in range(proxy.rowCount())
            if proxy.index(index, 0).data(Qt.ItemDataRole.UserRole) == 'session-few'
        )
        table.selectRow(selected_row)

        source.replace((
            TableRow('session-few', ('2026-11-02T00:00:00Z', 'C172', '3', '0', 'Interrupted')),
            TableRow('session-empty', ('2026-11-01T00:00:00Z', 'Gallery', '0', '0', 'Recorded')),
            TableRow('session-many', ('2026-11-03T00:00:00Z', 'A320', '12', '1', 'Finished')),
        ), table)
        self.assertEqual(
            'session-few',
            table.currentIndex().data(Qt.ItemDataRole.UserRole),
        )
        self.assertEqual(2, proxy.rowCount())

    def test_archive_actions_start_compact_and_idle(self) -> None:
        """Expose separate actions and preserve the send-only selection.

        Returns:
            None: The visible send action passes only its selected edition to upload.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            selected = (root / 'flight-a', (('rev-1', 'en'),), False, True)
            reports = QtFlightReports(
                root, Mock(return_value=selected[0]), capture=Mock(), redact=str,
                upload_selection=Mock(return_value=selected),
            )
            self.addCleanup(reports.close)

            self.assertTrue(reports.cancel_button.isHidden())
            self.assertNotIn(
                tr(current_language(), 'upload_saved_pdf'),
                [button.text() for button in reports.findChildren(QPushButton)],
            )
            self.assertTrue(reports.status.isHidden())
            self.assertFalse(reports.create_button.isHidden())
            self.assertFalse(reports.create_upload_button.isHidden())
            self.assertFalse(reports.send_button.isHidden())
            with patch.object(reports, 'upload_selected') as upload:
                reports.send_button.click()
                upload.assert_called_once_with(*selected)

    def test_report_hierarchy_keeps_editions_under_newest_revision(self) -> None:
        """Keep the saved-flight→revision→language ordering used by the tree.

        Returns:
            None: Each leaf retains its exact report path and language identity.
        """
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            (directory / 'session.json').write_text(
                '{"started_utc":"2026-11-26T00:00:00Z","aircraft":"A320"}',
                encoding='utf-8',
            )
            with patch('uploader.qt_operator_views.report_file_details') as details:
                details.side_effect = lambda path: ReportFileDetails(
                    2 if path.name.endswith('en.pdf') else 4,
                    '2026-11-26T00:00:00+00:00',
                    path.name.startswith('newer'),
                )
                hierarchy = report_hierarchy(directory, (
                    ReportRevision('older', {
                        'de': Path('older-de.pdf'), 'en': Path('older-en.pdf'),
                    }, number=1),
                    ReportRevision('newer', {
                        'sv': Path('newer-sv.pdf'), 'en': Path('newer-en.pdf'),
                    }, number=2),
                ))

        self.assertEqual(['newer', 'older'], [row.report_id for row in hierarchy])
        self.assertEqual(
            (('en', Path('newer-en.pdf')), ('sv', Path('newer-sv.pdf'))),
            tuple((edition.language, edition.path) for edition in hierarchy[0].editions),
        )
        self.assertEqual('6', hierarchy[0].pages_label)
        self.assertTrue(hierarchy[0].created_label.endswith(' *'))
        self.assertEqual('2', hierarchy[0].editions[0].pages_label)

    def test_bounded_flight_history_pages_keep_all_older_rows_reachable(self) -> None:
        """Keep the model bounded without truncating the saved-flight history.

        Returns:
            None: Newest, middle and oldest windows retain every stable identity.
        """
        rows = [SavedFlight(Path(f'session-{index:03d}')) for index in range(405)]

        middle, middle_offset, total = flight_history_window(rows, 200, 200)
        oldest, oldest_offset, oldest_total = flight_history_window(rows, 400, 200)

        self.assertEqual((200, 405), (middle_offset, total))
        self.assertEqual(200, len(middle))
        self.assertEqual('session-200', middle[0].directory.name)
        self.assertEqual((400, 405), (oldest_offset, oldest_total))
        self.assertEqual(['session-400', 'session-401', 'session-402', 'session-403', 'session-404'],
                         [row.directory.name for row in oldest])
        clamped, clamped_offset, _count = flight_history_window(rows, 9999, 200)
        self.assertEqual(400, clamped_offset)
        self.assertEqual(oldest, clamped)

        view = object.__new__(QtOperatorViews)
        view._flight_page_offset = 0
        view._flight_page_total = len(rows)
        with patch.object(view, 'refresh') as refresh:
            view._next_flight_page()
            self.assertEqual(200, view._flight_page_offset)
            view._next_flight_page()
            self.assertEqual(400, view._flight_page_offset)
            view._next_flight_page()
            self.assertEqual(2, refresh.call_count)
            view._previous_flight_page()
            view._previous_flight_page()
            view._previous_flight_page()
        self.assertEqual(0, view._flight_page_offset)

    def test_hidden_flight_browser_does_not_scan_the_archive(self) -> None:
        """Keep local Flight archive scans scoped to the visible report browser.

        Returns:
            None: Off-screen Activity refresh does not fetch or replace Flight rows.
        """
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder)
            owner = QWidget()
            view = QtOperatorViews(
                profile, 100, owner, open_import=Mock(), retry_import=Mock(),
                open_folder=Mock(), open_log=Mock(), open_support=Mock(),
                copy_support=Mock(), sanitize=str,
            )
            self.addCleanup(view.close)
            self.addCleanup(view.flights.hide)
            self.addCleanup(view.activity.hide)
            header = view.report_tree.header()
            self.assertEqual((0, 1, 3, 6, 4, 5, 2), tuple(
                header.logicalIndex(position) for position in range(header.count())
            ))
            self.assertEqual(
                tr(current_language(), 'report_catalog_entry'),
                view.report_tree.headerItem().text(0),
            )
            self.assertIsInstance(view.flight_only, ToggleSwitch)
            self.assertEqual(
                tr(current_language(), 'report_no_upload_destination'), view.flight_target.text(),
            )
            self.assertEqual(
                tr(current_language(), 'report_upload_target_unavailable'),
                view.flight_target_hint.text(),
            )
            self.assertEqual(
                '2026-11-26 10:00:00',
                view._format_started_utc('2026-11-26T12:00:00+02:00'),
            )
            self.assertEqual(
                'bad-timestamp', view._format_started_utc('bad-timestamp'),
            )
            layout = view.flights.layout_body
            self.assertLess(layout.indexOf(view.report_tree), layout.indexOf(view.flight_detail))
            self.assertLess(layout.indexOf(view.flight_detail), layout.indexOf(view.flight_target))
            with (patch.object(view._lane, 'submit') as submit,
                  patch('uploader.qt_operator_views.saved_flights', return_value=[]) as scan):
                publish = Mock()
                view.activity.show()
                view.refresh()
                submit.call_args.args[0](Event(), publish)
                scan.assert_not_called()
                self.assertIsNone(publish.call_args.args[0].flights)
                view.flights.show()
                view.refresh()
                submit.call_args.args[0](Event(), publish)
                scan.assert_called_once_with(profile / 'flights')
                self.assertEqual((), publish.call_args.args[0].flights)

    def test_nested_flight_roots_sort_counts_numerically(self) -> None:
        """Keep report-tree count sorting numeric rather than lexical.

        Returns:
            None: Twelve retained samples sort ahead of two on the count column.
        """
        tree = QTreeWidget()
        tree.setColumnCount(7)
        tree.setSortingEnabled(True)
        for identity, count in (('few', 2), ('many', 12)):
            item = FlightReportTreeItem((identity, '', str(count), '0', 'Saved'))
            item.setData(2, Qt.ItemDataRole.UserRole + 2, count)
            item.setData(0, Qt.ItemDataRole.UserRole, ('flight', identity))
            tree.addTopLevelItem(item)

        tree.sortItems(2, Qt.SortOrder.DescendingOrder)

        self.assertEqual('many', tree.topLevelItem(0).text(0))

    def test_catalog_upload_selection_keeps_exact_editions_and_one_flight(self) -> None:
        """Capture selected edition leaves and reject mixed flight roots.

        Returns:
            None: Upload receives only explicit editions from one selected session.
        """
        view = object.__new__(QtOperatorViews)
        tree = QTreeWidget()
        tree.setColumnCount(7)
        tree.setSelectionMode(QAbstractItemView.SelectionMode.ExtendedSelection)
        view.report_tree = tree
        first = FlightReportTreeItem(('A320', '', '1', '2', 'Saved'))
        first.setData(0, Qt.ItemDataRole.UserRole, ('flight', 'flight-a'))
        first_revision = FlightReportTreeItem(first)
        first_revision.setData(0, Qt.ItemDataRole.UserRole, (
            'revision', 'flight-a', 'rev-1',
        ))
        first_edition = FlightReportTreeItem(first_revision)
        first_edition.setData(0, Qt.ItemDataRole.UserRole, (
            'edition', 'flight-a', 'rev-1', 'en', 'a-en.pdf',
        ))
        second_revision = FlightReportTreeItem(first)
        second_revision.setData(0, Qt.ItemDataRole.UserRole, (
            'revision', 'flight-a', 'rev-2',
        ))
        second_edition = FlightReportTreeItem(second_revision)
        second_edition.setData(0, Qt.ItemDataRole.UserRole, (
            'edition', 'flight-a', 'rev-2', 'de', 'a-de.pdf',
        ))
        second = FlightReportTreeItem(('C172', '', '2', '1', 'Saved'))
        second.setData(0, Qt.ItemDataRole.UserRole, ('flight', 'flight-b'))
        tree.addTopLevelItem(first)
        tree.addTopLevelItem(second)
        tree.expandAll()
        selection = tree.selectionModel()
        selection.select(
            tree.indexFromItem(first_edition),
            QItemSelectionModel.SelectionFlag.Select
            | QItemSelectionModel.SelectionFlag.Rows,
        )
        selection.select(
            tree.indexFromItem(second_edition),
            QItemSelectionModel.SelectionFlag.Select
            | QItemSelectionModel.SelectionFlag.Rows,
        )
        selection.setCurrentIndex(
            tree.indexFromItem(second_edition),
            QItemSelectionModel.SelectionFlag.NoUpdate,
        )

        directory, editions, multiple_flights, has_selection = (
            view.saved_report_upload_selection()
        )

        self.assertEqual(Path('flight-a'), directory)
        self.assertEqual((('rev-1', 'en'), ('rev-2', 'de')), editions)
        self.assertFalse(multiple_flights)
        self.assertTrue(has_selection)
        selection.select(
            tree.indexFromItem(first),
            QItemSelectionModel.SelectionFlag.Select
            | QItemSelectionModel.SelectionFlag.Rows,
        )
        selection.select(
            tree.indexFromItem(second),
            QItemSelectionModel.SelectionFlag.Select
            | QItemSelectionModel.SelectionFlag.Rows,
        )
        selection.setCurrentIndex(
            tree.indexFromItem(second),
            QItemSelectionModel.SelectionFlag.NoUpdate,
        )
        self.assertTrue(view.saved_report_upload_selection()[2])

    def test_open_pdf_opens_each_selected_edition_once(self) -> None:
        """Open selected PDF leaves individually and ignore selected groups.

        Returns:
            None: Only exact confined edition files reach the desktop opener.
        """
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary).resolve() / 'flight-a'
            other_directory = directory.with_name('flight-b')
            first_pdf = directory / 'reports' / 'rev-1-en.pdf'
            second_pdf = other_directory / 'reports' / 'rev-2-de.pdf'
            first_pdf.parent.mkdir(parents=True)
            second_pdf.parent.mkdir(parents=True)
            first_pdf.write_bytes(b'%PDF first fixture')
            second_pdf.write_bytes(b'%PDF second fixture')

            view = object.__new__(QtOperatorViews)
            tree = QTreeWidget()
            tree.setColumnCount(7)
            tree.setSelectionMode(QAbstractItemView.SelectionMode.ExtendedSelection)
            view.report_tree = tree
            view.flights = SimpleNamespace(status=QLabel())
            flight = FlightReportTreeItem(('A320', '', '1', '2', 'Saved'))
            flight.setData(0, Qt.ItemDataRole.UserRole, ('flight', str(directory)))
            first_revision = FlightReportTreeItem(flight)
            first_revision.setData(0, Qt.ItemDataRole.UserRole, (
                'revision', str(directory), 'rev-1',
            ))
            first_edition = FlightReportTreeItem(first_revision)
            first_edition.setData(0, Qt.ItemDataRole.UserRole, (
                'edition', str(directory), 'rev-1', 'en', str(first_pdf),
            ))
            other_flight = FlightReportTreeItem(('B738', '', '1', '1', 'Saved'))
            other_flight.setData(0, Qt.ItemDataRole.UserRole, ('flight', str(other_directory)))
            second_revision = FlightReportTreeItem(other_flight)
            second_revision.setData(0, Qt.ItemDataRole.UserRole, (
                'revision', str(other_directory), 'rev-2',
            ))
            second_edition = FlightReportTreeItem(second_revision)
            second_edition.setData(0, Qt.ItemDataRole.UserRole, (
                'edition', str(other_directory), 'rev-2', 'de', str(second_pdf),
            ))
            tree.addTopLevelItem(flight)
            tree.addTopLevelItem(other_flight)
            tree.expandAll()
            selection = tree.selectionModel()
            for item in (first_edition, second_edition):
                selection.select(
                    tree.indexFromItem(item),
                    QItemSelectionModel.SelectionFlag.Select
                    | QItemSelectionModel.SelectionFlag.Rows,
                )
            selection.setCurrentIndex(
                tree.indexFromItem(second_edition),
                QItemSelectionModel.SelectionFlag.NoUpdate,
            )

            with patch('uploader.qt_operator_views.QDesktopServices.openUrl') as open_url:
                view.open_pdf()

            opened = [Path(call.args[0].toLocalFile()) for call in open_url.call_args_list]
            expected = [first_pdf, second_pdf]
            self.assertCountEqual(expected, opened)
            self.assertEqual(len(expected), len(opened))

            selection.clearSelection()
            selection.select(
                tree.indexFromItem(first_revision),
                QItemSelectionModel.SelectionFlag.Select
                | QItemSelectionModel.SelectionFlag.Rows,
            )
            selection.setCurrentIndex(
                tree.indexFromItem(first_revision),
                QItemSelectionModel.SelectionFlag.NoUpdate,
            )
            flight.setData(0, Qt.ItemDataRole.UserRole + 1, True)

            with patch('uploader.qt_operator_views.QDesktopServices.openUrl') as open_group:
                view.open_pdf()
            open_group.assert_not_called()


if __name__ == '__main__':
    unittest.main()
