# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_shell.py
# Module Type: Native Qt shell surfaces
# Purpose: Arrange the compact desktop header using the established WinApp layout.
# Responsibilities: Keep identity, connection, metrics and actions in stable hosts.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Build the Qt header with the same adaptive groups as the Tk reference."""

import sys

from PySide6.QtCore import Qt
from PySide6.QtGui import QResizeEvent
from PySide6.QtWidgets import QGridLayout, QHBoxLayout, QLabel, QSizePolicy, QVBoxLayout, QWidget

from .localization import gettext
from .ui_layout import HEADER_GROUP_GAP, HEADER_ROW_GAP


class QtAppHeader(QWidget):
    """Keep the main header's identity, linking and action groups visible."""

    def __init__(self, version: str) -> None:
        """Create existing application groups without touching profile services.

        Args:
            version (str): The independent WinApp version displayed with the brand.
        Returns:
            None: Creates stable layouts for connection and action controls.
        """
        super().__init__()
        self.setObjectName("shell-header")
        self.setSizePolicy(QSizePolicy.Policy.Ignored, QSizePolicy.Policy.Maximum)
        self._mode: tuple[str, bool] | None = None
        self._grid = QGridLayout(self)
        self._grid.setContentsMargins(0, 0, 0, 0)
        self._grid.setHorizontalSpacing(HEADER_GROUP_GAP)
        self._grid.setVerticalSpacing(HEADER_ROW_GAP)
        self._grid.setColumnStretch(1, 1)
        self.identity = QWidget()
        brand_row = QHBoxLayout(self.identity)
        brand_row.setContentsMargins(0, 0, 0, 0)
        brand_row.setSpacing(HEADER_GROUP_GAP)
        self.brand = QLabel("PHP Gallery")
        self.brand.setObjectName("brand")
        self.tagline = QLabel(gettext("Your gallery on autopilot"))
        self.version = QLabel("· " + version)
        for label in (self.tagline, self.version):
            label.setProperty("eyebrow", True)
        for label in (self.brand, self.tagline, self.version):
            brand_row.addWidget(label)
        self.links = QWidget()
        self.link_layout = QVBoxLayout(self.links)
        self.link_layout.setContentsMargins(0, 0, 0, 0)
        self.link_layout.setSpacing(HEADER_ROW_GAP)
        self.actions = QWidget()
        self.action_layout = QHBoxLayout(self.actions)
        self.action_layout.setContentsMargins(0, 0, 0, 0)
        self.action_layout.setSpacing(HEADER_GROUP_GAP)
        self.reflow()

    def resizeEvent(self, event: QResizeEvent) -> None:
        """Reflow groups when the header receives a different client width.

        Args:
            event (QResizeEvent): New native width and height of the header.
        Returns:
            None: Keeps actions reachable within the current responsive mode.
        """
        super().resizeEvent(event)
        self.reflow()

    def reflow(self) -> None:
        """Prefer inline groups and yield the Aqua maximized tagline.

        Returns:
            None: The decorative tagline yields first while actions and version
                remain visible in every layout.
        """
        compact = self.brand.sizeHint().width() + self.version.sizeHint().width() + HEADER_GROUP_GAP
        full = compact + self.tagline.sizeHint().width() + HEADER_GROUP_GAP
        reserved = self.links.sizePolicy().retainSizeWhenHidden()
        links = self.links.sizeHint().width() if reserved or not self.links.isHidden() else 0
        actions = self.actions.sizeHint().width()
        inline_spacing = HEADER_GROUP_GAP + HEADER_GROUP_GAP
        width = self.width()
        if full + links + actions + inline_spacing <= width:
            mode, tagline = "inline", True
        elif compact + links + actions + inline_spacing <= width:
            mode, tagline = "inline", False
        elif compact + actions + HEADER_GROUP_GAP <= width:
            mode, tagline = "links-below", full + actions + HEADER_GROUP_GAP <= width
        else:
            mode, tagline = "stacked", full <= width
        # Tk's native Aqua title row yields this decorative label at the
        # maximized reference geometry even though Qt's size hints fit it.
        # Keep Qt aligned on Aqua while preserving its measured rules elsewhere.
        if sys.platform == "darwin" and self.window().isMaximized():
            tagline = False
        if self._mode == (mode, tagline):
            return
        self._mode = (mode, tagline)
        self.tagline.setVisible(tagline)
        for widget in (self.identity, self.links, self.actions):
            self._grid.removeWidget(widget)
        self._grid.addWidget(self.identity, 0, 0, 1, 3 if mode == "stacked" else 1,
                             alignment=Qt.AlignmentFlag.AlignLeft)
        if mode == "inline":
            self._grid.addWidget(self.links, 0, 1)
            self._grid.addWidget(self.actions, 0, 2, alignment=Qt.AlignmentFlag.AlignRight)
        elif mode == "links-below":
            self._grid.addWidget(self.actions, 0, 2, alignment=Qt.AlignmentFlag.AlignRight)
            self._grid.addWidget(self.links, 1, 0, 1, 3)
        else:
            self._grid.addWidget(self.actions, 1, 0, 1, 3, alignment=Qt.AlignmentFlag.AlignLeft)
            self._grid.addWidget(self.links, 2, 0, 1, 3)
