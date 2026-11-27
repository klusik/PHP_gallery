# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_photo_inspector.py
# Module Type: Qt Photos inspector presentation
# Purpose: Present an inspector with a bounded preview and expandable metadata.
# Responsibilities: Keep the animated card separate from the splitter geometry
#   and respect platform motion preferences.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Present Photos details inside a stable splitter pane."""

import sys
from html import escape

from PySide6.QtCore import QEasingCurve, QPoint, QPropertyAnimation, QRectF, Qt, QTimer, QUrl
from PySide6.QtGui import (
    QFontMetrics, QHideEvent, QImage, QPaintEvent, QPainter, QPalette, QResizeEvent,
    QTextOption,
)
from PySide6.QtWidgets import QFrame, QSizePolicy, QStyle, QTextBrowser

from .ui_palette import PALETTE

if sys.platform == 'win32':
    from ctypes import byref, wintypes, windll

NSWorkspace = None
if sys.platform == 'darwin':
    try:
        from AppKit import NSWorkspace
    except ImportError:
        pass

# Purpose: Honor the Windows client-area animation preference.
# Type: int. Units: Win32 action code. Scope: SystemParametersInfoW.
# Consumers: InspectorPane._reduce_motion.
# Rationale: 0x1042 reads animation eligibility without changing system settings.
SPI_GETCLIENTAREAANIMATION = 0x1042
# Purpose: Settle the inspector promptly after the user's action.
# Type: int. Units: milliseconds. Scope: one inspector reveal.
# Consumers: InspectorPane.reveal.
# Rationale: 180 ms includes the overshoot and stays within the 200 ms budget.
INSPECTOR_REVEAL_MS = 180
# Purpose: Slide the card without relaying out the surrounding photo grid.
# Type: int. Units: logical pixels. Scope: inspector card start offset.
# Consumers: InspectorPane.reveal.
# Rationale: 28 pixels gives a visible entry with only a few pixels of overshoot.
INSPECTOR_REVEAL_OFFSET = 28
# Purpose: Reserve a preview larger than the ordinary Photos thumbnail.
# Type: int. Units: logical pixels. Scope: one preview surface.
# Consumers: InspectorPreview.__init__.
# Rationale: 188 pixels leaves space for details at the minimum window height.
INSPECTOR_PREVIEW_HEIGHT = 188
# Purpose: Keep the photo and status off the preview frame border.
# Type: int. Units: logical pixels. Scope: preview padding.
# Consumers: InspectorPreview.paintEvent.
# Rationale: 8 pixels matches the compact inset of the existing media cards.
INSPECTOR_PREVIEW_INSET = 8
# Purpose: Decode one selected thumbnail at a larger bounded size.
# Type: tuple[int, int]. Units: pixels. Scope: inspector-only raster cap.
# Consumers: PhotosWorkspace._request_information.
# Rationale: 512 × 512 keeps the selected preview crisp on scaled displays.
INSPECTOR_PREVIEW_PIXELS = (512, 512)
# Purpose: Align all inspector values at one shared column edge.
# Type: int. Units: percent of table width. Scope: Qt photo metadata.
# Consumers: InspectorMetadata._render_details.
# Rationale: 40 percent accommodates translated labels and gives values more space.
INSPECTOR_METADATA_LABEL_WIDTH = 40
# Purpose: Separate related groups while keeping fields close together.
# Type: int. Units: logical pixels. Scope: metadata section headings.
# Consumers: InspectorMetadata._render_details.
# Rationale: 6 pixels distinguishes groups without inserting an empty line.
INSPECTOR_METADATA_SECTION_GAP = 6
# Purpose: Keep adjacent metadata rows readable.
# Type: int. Units: logical pixels per cell edge. Scope: Qt photo metadata.
# Consumers: InspectorMetadata._render_details.
# Rationale: 1 pixel provides a small visual separation at each row boundary.
INSPECTOR_METADATA_CELL_PADDING = 1
# Purpose: Fit an elided name inside the native text table's content width.
# Type: int. Units: logical pixels. Scope: name caption measurement.
# Consumers: InspectorMetadata._render_details.
# Rationale: 4 pixels leave room for fractional font advances and table rounding.
INSPECTOR_METADATA_TEXT_INSET = 4

NAME_FIELDS = frozenset(('photos_workspace_field_title', 'photos_workspace_field_filename'))

# Each group stores a caption and ordered fields as key, localized label, value.
InspectorGroup = tuple[str, list[tuple[str, str, str]]]


class InspectorPane(QFrame):
    """Animate the card inside a clipping pane with a fixed splitter allocation."""

    def __init__(self) -> None:
        """Create the card and a reusable one-shot position animation.

        Returns:
            None: The splitter owns this pane and the pane owns its clipping surface.
        """
        super().__init__()
        self.setObjectName('photosInspector')
        self.card = QFrame(self)
        self.card.setProperty('shellCard', True)
        self.transition = QPropertyAnimation(self.card, b'pos', self)
        self.transition.setDuration(INSPECTOR_REVEAL_MS)
        self.transition.setEasingCurve(QEasingCurve.Type.OutBack)
        self.transition.setStartValue(QPoint(INSPECTOR_REVEAL_OFFSET, 0))
        self.transition.setEndValue(QPoint())

    def _reduce_motion(self) -> bool:
        """Read the desktop's motion preference at the point of interaction.

        Returns:
            bool: True when the system or the Qt style requests reduced motion.
        """
        if sys.platform == 'win32':
            enabled = wintypes.BOOL()
            if windll.user32.SystemParametersInfoW(
                SPI_GETCLIENTAREAANIMATION, 0, byref(enabled), 0,
            ):
                return not bool(enabled.value)
        if NSWorkspace is not None:
            workspace = NSWorkspace.sharedWorkspace()
            if hasattr(workspace, 'accessibilityDisplayShouldReduceMotion'):
                return bool(workspace.accessibilityDisplayShouldReduceMotion())
        return self.style().styleHint(
            QStyle.StyleHint.SH_Widget_Animation_Duration, None, self,
        ) <= 0

    def reveal(self) -> None:
        """Show the card immediately and animate only its interior position.

        Returns:
            None: The splitter already reserves the final geometry during motion.
        """
        self.transition.stop()
        animate = not self._reduce_motion()
        self.card.resize(self.size())
        self.card.move(QPoint(INSPECTOR_REVEAL_OFFSET, 0) if animate else QPoint())
        self.show()
        if animate:
            self.transition.start()

    def resizeEvent(self, event: QResizeEvent) -> None:
        """Resize the card without changing its current position.

        Args:
            event (QResizeEvent): Splitter, layout or window geometry change.
        Returns:
            None: The card fills this pane after the reveal finishes.
        """
        super().resizeEvent(event)
        self.card.resize(event.size())

    def hideEvent(self, event: QHideEvent) -> None:
        """Stop a reveal as soon as the inspector loses visibility.

        Args:
            event (QHideEvent): Close, workspace switch or window hide.
        Returns:
            None: Clears the transition before any later show or teardown.
        """
        self.transition.stop()
        self.card.move(QPoint())
        super().hideEvent(event)


class InspectorPreview(QFrame):
    """Fit one authorized image inside the preview with its aspect ratio intact."""

    def __init__(self, name: str) -> None:
        """Create the bounded preview surface.

        Args:
            name (str): Localized accessible name for the photo preview.
        Returns:
            None: The preview starts without a selected image.
        """
        super().__init__()
        self.setObjectName('photosInspectorPreview')
        self.setAccessibleName(name)
        self.setSizePolicy(QSizePolicy.Policy.Ignored, QSizePolicy.Policy.Fixed)
        self.setFixedHeight(INSPECTOR_PREVIEW_HEIGHT)
        self.setFrameShape(QFrame.Shape.StyledPanel)
        self.setStyleSheet(
            f'QFrame#photosInspectorPreview {{ background: {PALETTE["surface"]}; }}',
        )
        self.image = QImage()
        self._status = ''

    def present(self, image: QImage | None, status: str = '') -> None:
        """Replace the selected preview or display its current status.

        Args:
            image (QImage | None): Bounded current pixels or None to clear them.
            status (str): Localized loading or unavailable text.
        Returns:
            None: Schedules a single repaint of the preview surface.
        """
        self.image = QImage() if image is None else image
        self._status = status
        self.setAccessibleDescription(status)
        self.update()

    def paintEvent(self, event: QPaintEvent) -> None:
        """Render the current image without cropping or a resize-time raster copy.

        Args:
            event (QPaintEvent): Qt repaint request for the visible preview.
        Returns:
            None: Paints the bounded image or centered status within the frame.
        """
        super().paintEvent(event)
        inset = INSPECTOR_PREVIEW_INSET
        area = self.contentsRect().adjusted(inset, inset, -inset, -inset)
        if area.isEmpty():
            return
        painter = QPainter(self)
        try:
            if self.image.isNull():
                painter.setPen(self.palette().color(QPalette.ColorRole.PlaceholderText))
                flags = int(Qt.AlignmentFlag.AlignCenter) | int(Qt.TextFlag.TextWordWrap)
                painter.drawText(area, flags, self._status)
                return
            size = self.image.size().scaled(area.size(), Qt.AspectRatioMode.KeepAspectRatio)
            destination = QRectF(
                area.x() + (area.width() - size.width()) / 2,
                area.y() + (area.height() - size.height()) / 2,
                size.width(), size.height(),
            )
            painter.setRenderHint(QPainter.RenderHint.SmoothPixmapTransform)
            painter.drawImage(destination, self.image)
        finally:
            painter.end()


class InspectorMetadata(QTextBrowser):
    """Show selectable metadata and let each overflowing name expand in place."""

    def __init__(self, name: str, expand_name: str, collapse_name: str) -> None:
        """Configure one compact document with local disclosure controls.

        Args:
            name (str): Localized accessible name for the document.
            expand_name (str): Localized tooltip for the collapsed name.
            collapse_name (str): Localized tooltip for the expanded name.
        Returns:
            None: The text surface begins empty and keeps native link activation.
        """
        super().__init__()
        self._groups: list[InspectorGroup] = []
        self._expanded_names: set[str] = set()
        self._rendered_html = ''
        self._expand_name = expand_name
        self._collapse_name = collapse_name
        self._relayout = QTimer(self)
        self._relayout.setSingleShot(True)
        self._relayout.timeout.connect(self._render_details)
        self.setOpenLinks(False)
        self.setOpenExternalLinks(False)
        self.anchorClicked.connect(self._toggle_name)
        self.setFrameShape(QFrame.Shape.NoFrame)
        self.setWordWrapMode(QTextOption.WrapMode.WrapAtWordBoundaryOrAnywhere)
        self.setHorizontalScrollBarPolicy(Qt.ScrollBarPolicy.ScrollBarAlwaysOff)
        self.setStyleSheet(f'QTextBrowser {{ background: {PALETTE["card_surface"]}; }}')
        self.setAccessibleName(name)

    def set_groups(self, groups: list[InspectorGroup]) -> None:
        """Replace the current photo details and reset their disclosure state.

        Args:
            groups (list[InspectorGroup]): Localized headings and ordered triples
                of field keys, localized labels and formatted values.
        Returns:
            None: Presents the new photo from the top of the document.
        """
        self._groups = groups
        self._expanded_names.clear()
        self._render_details()
        self.verticalScrollBar().setValue(0)

    def resizeEvent(self, event: QResizeEvent) -> None:
        """Recompute name captions after the viewport width settles.

        Args:
            event (QResizeEvent): Native resize from the splitter or scroll area.
        Returns:
            None: Coalesces width changes in the current event-loop turn.
        """
        super().resizeEvent(event)
        if event.size().width() != event.oldSize().width():
            self._relayout.start(0)

    def _toggle_name(self, link: QUrl) -> None:
        """Toggle only one allowlisted metadata field in the current document.

        Args:
            link (QUrl): Local fragment generated by the name renderer.
        Returns:
            None: Renders the updated disclosure without navigation or a new read.
        """
        key = link.fragment()
        if key in NAME_FIELDS and link == QUrl(f'#{key}'):
            self._expanded_names.symmetric_difference_update((key,))
            self._render_details()

    def _value_markup(self, key: str, value: str, metrics: QFontMetrics, width: int) -> str:
        """Keep an overflowing title or filename on one line until expanded.

        Args:
            key (str): Stable metadata field key.
            value (str): Formatted plain text to preserve when expanded.
            metrics (QFontMetrics): Document font used for both value states.
            width (int): Available value-cell width in logical pixels.
        Returns:
            str: Escaped value text with an optional local disclosure link.
        """
        if key not in NAME_FIELDS or metrics.horizontalAdvance(value) <= width:
            return escape(value)
        if key in self._expanded_names:
            hint = escape(self._collapse_name)
            arrow = '▾'
            caption = ''
            revealed = f' {escape(value)}'
        else:
            hint = escape(self._expand_name)
            arrow = '▸'
            room = max(0, width - metrics.horizontalAdvance(arrow + ' '))
            name = metrics.elidedText(value, Qt.TextElideMode.ElideMiddle, room)
            caption = ' ' + escape(name)
            revealed = ''
        return (
            f'<a href="#{key}" title="{hint}" '
            f'style="color: {PALETTE["ink"]}; text-decoration: none">'
            f'<span style="color: {PALETTE["primary_hover"]}">{arrow}</span>{caption}</a>'
            f'{revealed}'
        )

    def _render_details(self) -> None:
        """Update the two-column document only when its presentation changes.

        Returns:
            None: Keeps field values selectable and preserves vertical scroll.
        """
        document = self.document()
        metrics = QFontMetrics(document.defaultFont())
        # Both sides of the document and value cell contribute their own inset.
        table_width = self.viewport().width() - round(2 * document.documentMargin())
        width = max(0, table_width * (100 - INSPECTOR_METADATA_LABEL_WIDTH) // 100
                    - 2 * INSPECTOR_METADATA_CELL_PADDING - INSPECTOR_METADATA_TEXT_INSET)
        sections: list[str] = []
        for heading, fields in self._groups:
            if not fields:
                continue
            gap = INSPECTOR_METADATA_SECTION_GAP if sections else 0
            # A heading spans the label and value columns of the same table.
            sections.append(
                f'<tr><td colspan="2"><p style="margin-top:{gap}px; margin-bottom:0">'
                f'<b>{escape(heading)}</b></p></td></tr>',
            )
            sections.extend(
                f'<tr><td width="{INSPECTOR_METADATA_LABEL_WIDTH}%" valign="top" '
                f'style="color: {PALETTE["muted"]}">{escape(label)}</td>'
                '<td valign="top" style="white-space: pre-wrap">'
                f'{self._value_markup(key, value, metrics, width)}</td></tr>'
                for key, label, value in fields
            )
        html = (
            f'<table width="100%" cellspacing="0" cellpadding="{INSPECTOR_METADATA_CELL_PADDING}">'
            f'{"".join(sections)}</table>' if sections else ''
        )
        if html != self._rendered_html:
            scroll = self.verticalScrollBar().value()
            self._rendered_html = html
            self.setHtml(html)
            self.verticalScrollBar().setValue(scroll)
