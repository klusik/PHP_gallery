# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_qt_activity_geometry.py
# Module Type: Qt Activity table geometry regression
# Purpose: Keep Qt Activity columns aligned with Tk's base and surplus widths.
# Responsibilities: Verify native resizing preserves the Tk Activity width policy.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Keep the native Activity columns at their reference widths while resizing."""

import os
import sys
import unittest
from pathlib import Path

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

try:
    from PySide6.QtWidgets import QApplication
    from uploader.qt_operator_views import OperatorTable, _ActivityStretchTable
except (ImportError, OSError):
    if os.environ.get('PHP_GALLERY_REQUIRE_QT') == '1':
        raise
    QApplication = None

from native_qt_fixture import NativeQtTestCase


@unittest.skipIf(QApplication is None, 'Optional Qt dependencies are absent.')
class QtActivityGeometryTests(NativeQtTestCase):
    """Verify Activity resizing distributes only surplus to Tk stretch columns."""

    @classmethod
    def setUpClass(cls) -> None:
        """Create the Qt application for this isolated native view fixture.

        Returns:
            None: No application or profile service is started by the fixture.
        """
        if cls.delegate_native_cases():
            return
        cls.application = QApplication.instance() or QApplication([])

    def test_table_keeps_bases_and_splits_surplus_between_reference_columns(self) -> None:
        """Preserve all base sections and share only the available surplus.

        Returns:
            None: A resize changes only the two configured Tk stretch sections.
        """
        model = OperatorTable(('File', 'State', 'Tries', 'Result / error'))
        table = _ActivityStretchTable(model, (175, 105, 65, 330), (0, 3))
        self.addCleanup(table.close)
        table.resize(900, 240)
        table.show()
        self.application.processEvents()

        available = table.viewport().width()
        extra = max(0, available - 675)
        share, remainder = divmod(extra, 2)
        self.assertEqual(
            [table.columnWidth(index) for index in range(4)],
            [175 + share + remainder, 105, 65, 330 + share],
        )

        table.resize(760, 240)
        self.application.processEvents()
        available = table.viewport().width()
        extra = max(0, available - 675)
        share, remainder = divmod(extra, 2)
        self.assertEqual(
            [table.columnWidth(index) for index in range(4)],
            [175 + share + remainder, 105, 65, 330 + share],
        )
