# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_theme.py
# Module Type: Qt presentation components
# Purpose: Apply the shared desktop theme to native Qt controls.
# Responsibilities: Own semantic button roles, surfaces and keyboard focus.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Style the Qt application without importing Tk or creating an event loop."""

from functools import lru_cache
from typing import Callable, Literal

from PySide6.QtCore import QPoint, QSize, Qt
from PySide6.QtGui import QColor, QFontDatabase, QIcon, QImage, QPaintEvent, QPalette, QPixmap
from PySide6.QtWidgets import (
    QApplication, QCheckBox, QFrame, QGraphicsDropShadowEffect, QPushButton,
    QStyle, QStyleOptionButton, QStylePainter,
)

from .ui_icons import render_smooth_icon, render_toggle_switch
from .ui_palette import (
    PALETTE, UI_BODY_POINTS, UI_BRAND_POINTS, UI_EYEBROW_POINTS, UI_FONT_PREFERENCES,
    UI_SWITCH_LABEL_GAP, UI_SWITCH_SIZE,
)

# Purpose: Match the Tk rail's logical icon image dimensions.
# Type: int. Units: logical pixels. Scope: shared Qt action icons.
# Consumers: ActionButton and qt_icon. Rationale: Tk uses 20-pixel artwork.
ACTION_ICON_SIZE = 20
# Purpose: Retain density alternatives for standard and Retina desktop modes.
# Type: tuple[int, ...]. Units: device-pixels per logical pixel. Scope: QIcons.
# Consumers: qt_icon. Rationale: Qt chooses the closest registered density.
ICON_DENSITIES = (1, 2, 3)
# Purpose: Bound retained variants of small compiled action images.
# Type: int. Units: Qt icons. Scope: one Qt desktop process.
# Consumers: qt_icon and _switch_pixmap.
# Rationale: covers the static semantic action inventory and native pixel densities.
ICON_CACHE_LIMIT = 128
# Purpose: Keep a raised surface visibly separate from the application content.
# Type: int. Units: logical pixels. Scope: shared Qt raised surfaces.
# Consumers: RaisedSurface. Rationale: Matches the soft Tk dialog shadow.
RAISED_SURFACE_SHADOW_BLUR = 18
# Purpose: Give raised Qt surfaces the same short directional drop as Tk.
# Type: int. Units: logical pixels. Scope: shared Qt raised surfaces.
# Consumers: RaisedSurface. Rationale: Four pixels separates the card edge.
RAISED_SURFACE_SHADOW_DROP = 4
# Purpose: Keep the shared surface shadow visible against the slate background.
# Type: int. Units: alpha byte. Scope: shared Qt raised surfaces.
# Consumers: RaisedSurface. Rationale: Contrast without a hard black outline.
RAISED_SURFACE_SHADOW_ALPHA = 152


@lru_cache(maxsize=ICON_CACHE_LIMIT)
def qt_icon(
    name: str, *, size: int | tuple[int, int] = ACTION_ICON_SIZE,
    tone: str = "ink", hollow: bool = False,
    checked_tone: str = "scrim", recording: bool = False, pulse: bool = False,
    pinned: bool = False, item_view: bool = False,
) -> QIcon:
    """Adapt the same semantic artwork as Tk for device-scaled Qt controls.

    Args:
        name (str): Established application icon key.
        size (int | tuple[int, int]): Square edge or width and height in logical pixels.
        tone (str): Shared palette role for the normal icon.
        hollow (bool): Draw an outlined state marker.
        checked_tone (str): Palette role on a checked button's actual background.
        recording (bool): Add the shared recording dot to a gallery marker.
        pulse (bool): Select the bright phase of the existing recording palette.
        pinned (bool): Add the retained-cache marker to a gallery icon.
        item_view (bool): Preserve semantic colors across an item view's
            open, selected and active states.
    Returns:
        QIcon: Native multi-density icon with separate disabled and selected artwork.
    """
    icon = QIcon()
    dimensions = (size, size) if isinstance(size, int) else size
    variants = (
        (tone, ((QIcon.Mode.Normal, QIcon.State.Off),)),
        ("muted", ((QIcon.Mode.Disabled, QIcon.State.Off),)),
        (checked_tone, ((QIcon.Mode.Normal, QIcon.State.On),)),
    )
    if item_view:
        # Item views request On for open rows and Selected independently.
        states = (QIcon.State.Off, QIcon.State.On)
        variants = (
            (tone, tuple((mode, state)
                         for mode in (QIcon.Mode.Normal, QIcon.Mode.Active, QIcon.Mode.Selected)
                         for state in states)),
            ("muted", tuple((QIcon.Mode.Disabled, state) for state in states)),
        )
    for color, native_states in variants:
        for density in ICON_DENSITIES:
            image = render_smooth_icon(
                name, dimensions, dpi_scale=density, tone=color, hollow=hollow,
                recording=recording, pulse=pulse, pinned=pinned,
            )
            if image is None:
                return icon
            pixels = image.tobytes()
            native = QImage(pixels, image.width, image.height, QImage.Format.Format_RGBA8888)
            raster = QPixmap.fromImage(native.copy())
            raster.setDevicePixelRatio(density)
            for mode, state in native_states:
                icon.addPixmap(raster, mode, state)
    return icon


@lru_cache(maxsize=ICON_CACHE_LIMIT)
def _switch_pixmap(
    selected: bool, state: Literal['normal', 'focus', 'disabled'], density: float,
) -> QPixmap:
    """Retain a bounded Qt adapter for the existing switch pixels.

    Args:
        selected (bool): Persistent checkbox state.
        state (Literal['normal', 'focus', 'disabled']): Native presentation state.
        density (float): Device pixels per logical pixel on the current screen.
    Returns:
        QPixmap: Density-aware retained switch art, or a null native fallback signal.
    """
    artwork = render_toggle_switch(selected, state, dpi_scale=density)
    if artwork is None:
        return QPixmap()
    with artwork, artwork.convert('RGBA') as rgba:
        # A 38-pixel RGB row is not aligned to Qt's default scanline boundary.
        native = QImage(rgba.tobytes(), rgba.width, rgba.height, QImage.Format.Format_RGBA8888)
        pixmap = QPixmap.fromImage(native.copy())
    pixmap.setDevicePixelRatio(density)
    return pixmap


class ToggleSwitch(QCheckBox):
    """Show the reference WinApp switch with native Qt checkbox semantics."""

    def sizeHint(self) -> QSize:
        """Reserve the reference indicator and the native localized caption.

        Returns:
            QSize: Native label extent plus shared switch geometry.
        """
        width, height = UI_SWITCH_SIZE
        metrics = self.fontMetrics()
        caption_width = metrics.horizontalAdvance(self.text())
        return QSize(width + UI_SWITCH_LABEL_GAP + caption_width, max(height, metrics.height()))

    def minimumSizeHint(self) -> QSize:
        """Keep the complete choice legible in the owning scrollable card.

        Returns:
            QSize: Indicator and caption fit without being clipped by the layout.
        """
        return self.sizeHint()

    def hitButton(self, position: QPoint) -> bool:
        """Accept pointer input over the real switch and its native caption.

        Args:
            position (QPoint): Candidate logical point relative to the Qt widget.
        Returns:
            bool: True when the input lies inside the rendered switch area.
        """
        return self.rect().contains(position)

    def paintEvent(self, event: QPaintEvent) -> None:
        """Draw shared artwork while letting Qt render the actual checkbox label.

        Args:
            event (QPaintEvent): Native paint request for the current switch state.
        Returns:
            None: Preserves native accessibility, focus, mnemonics and toggle events.
        """
        state = 'disabled' if not self.isEnabled() else 'focus' if self.hasFocus() else 'normal'
        artwork = _switch_pixmap(self.isChecked(), state, self.devicePixelRatioF())
        if artwork.isNull():
            # Stable source-mode fallback follows the same owner as native Tk:
            # Pillow can be repaired with a usable Boolean control still present.
            super().paintEvent(event)
            return
        option = QStyleOptionButton()
        self.initStyleOption(option)
        width, height = UI_SWITCH_SIZE
        y = (self.height() - height) // 2
        offset = width + UI_SWITCH_LABEL_GAP
        painter = QStylePainter(self)
        try:
            if option.direction == Qt.LayoutDirection.RightToLeft:
                painter.drawPixmap(self.width() - width, y, artwork)
                option.rect.adjust(0, 0, -offset, 0)
            else:
                painter.drawPixmap(0, y, artwork)
                option.rect.adjust(offset, 0, 0, 0)
            painter.drawControl(QStyle.ControlElement.CE_CheckBoxLabel, option)
        finally:
            painter.end()


class RaisedSurface(QFrame):
    """Provide the shared bordered card and soft elevation for overlays."""

    def __init__(self, parent: QWidget, object_name: str) -> None:
        """Create a shared raised card using the active Qt theme tokens.

        Args:
            parent (QWidget): Window or panel that clips and owns this surface.
            object_name (str): Stable Qt name used for diagnostics and styling.
        Returns:
            None: The surface owns its decorative shadow and no task state.
        """
        super().__init__(parent)
        self.setObjectName(object_name)
        self.setProperty("raisedSurface", True)
        effect = QGraphicsDropShadowEffect(self)
        effect.setBlurRadius(RAISED_SURFACE_SHADOW_BLUR)
        effect.setOffset(0, RAISED_SURFACE_SHADOW_DROP)
        color = QColor(PALETTE["scrim"])
        color.setAlpha(RAISED_SURFACE_SHADOW_ALPHA)
        effect.setColor(color)
        self.setGraphicsEffect(effect)


class ActionButton(QPushButton):
    """Bind a keyboard-accessible action to the common Qt button treatment."""

    def __init__(
        self, caption: str, action: Callable[[], None], *, primary: bool = False,
        icon: str | None = None, icon_only: bool = False, icon_size: int = ACTION_ICON_SIZE,
    ) -> None:
        """Connect the button to its user command.

        Args:
            caption (str): Localized action name.
            action (Callable[[], None]): Command invoked on activation.
            primary (bool): Emphasize the primary command on this surface.
            icon (str | None): Shared artwork key, or a text-only action.
            icon_only (bool): Use the caption as tooltip and accessible name.
            icon_size (int): Logical icon edge in pixels for the selected surface.
        Returns:
            None: Creates a themed action button with a native focus state.
        """
        super().__init__(caption)
        self.setProperty("primary", primary)
        if icon is not None:
            artwork = qt_icon(icon, size=icon_size)
            self.setIcon(artwork)
            self.setIconSize(QSize(icon_size, icon_size))
            if icon_only and not artwork.isNull():
                self.setText("")
                self.setProperty("compactIcon", True)
            self.setAccessibleName(caption)
            self.setToolTip(caption)
        self.clicked.connect(lambda _checked=False: action())


def apply_qt_theme(app: QApplication) -> None:
    """Apply the slate palette and size controls in device-independent pixels.

    Args:
        app (QApplication): Sole application for the current Qt process.
    Returns:
        None: Sets semantic color, surface, selection and focus styling.
    """
    app.setStyle("Fusion")
    font = app.font()
    available_fonts = {family.casefold(): family for family in QFontDatabase.families()}
    font.setFamily(next((available_fonts[name.casefold()] for name in UI_FONT_PREFERENCES
                         if name.casefold() in available_fonts), font.family()))
    font.setPointSize(UI_BODY_POINTS)
    app.setFont(font)
    palette = QPalette()
    for role, color in (
        (QPalette.ColorRole.Window, "background"),
        (QPalette.ColorRole.WindowText, "ink"),
        (QPalette.ColorRole.Base, "surface"),
        (QPalette.ColorRole.AlternateBase, "surface_alt"),
        (QPalette.ColorRole.Text, "ink"),
        (QPalette.ColorRole.Button, "card_surface_alt"),
        (QPalette.ColorRole.ButtonText, "ink"),
        (QPalette.ColorRole.Highlight, "primary_pressed"),
        (QPalette.ColorRole.HighlightedText, "ink"),
        (QPalette.ColorRole.PlaceholderText, "muted"),
        (QPalette.ColorRole.Link, "focus"),
    ):
        palette.setColor(role, QColor(PALETTE[color]))
    app.setPalette(palette)
    # Compact geometry belongs to the theme. Padding and corner radii are in
    # Qt logical pixels and preserve click targets at system DPI.
    app.setStyleSheet("""
        QGroupBox {
            border: 1px solid %(line)s;
            border-radius: 8px;
            margin-top: 10px;
            padding: 10px;
            background: %(surface)s;
        }
        QGroupBox::title {
            subcontrol-origin: margin;
            left: 9px;
            padding: 0 4px;
            color: %(muted)s;
        }
        QPushButton, QComboBox, QLineEdit {
            background: %(card_surface_alt)s;
            border: 1px solid %(line)s;
            border-radius: 6px;
            padding: 5px 9px;
            color: %(ink)s;
        }
        QPushButton:hover, QPushButton:checked { background: %(surface_alt)s; }
        QPushButton[primary="true"] { background: %(primary_pressed)s; }
        QPushButton[primary="true"]:hover { background: %(primary)s; color: %(scrim)s; }
        QPushButton:focus, QComboBox:focus, QLineEdit:focus { border-color: %(focus)s; }
        QPushButton:disabled, QComboBox:disabled { color: %(muted)s; background: %(surface)s; }
        QPushButton[workspaceAction="true"] { padding-left: 17px; padding-right: 17px; }
        QPushButton[workspaceAction="true"][primary="true"]:enabled {
            background: %(primary)s; font-weight: 600;
        }
        QPushButton[workspaceAction="true"][primary="true"]:hover { background: %(primary_hover)s; }
        QPushButton[workspaceAction="true"][primary="true"]:pressed { background: %(primary_pressed)s; }
        QPushButton[workspaceAction="true"][primary="true"]:disabled {
            background: qlineargradient(x1: 0, y1: 0, x2: 0, y2: 1,
                stop: 0 %(primary_inactive_top)s, stop: 1 %(primary_inactive_bottom)s);
        }
        QPushButton[compactIcon="true"] { background: transparent; border-color: transparent; padding: 4px; }
        QPushButton[compactIcon="true"]:hover { background: %(surface_alt)s; }
        QPushButton[compactIcon="true"]:checked, QPushButton[compactIcon="true"]:pressed { background: %(primary)s; }
        QPushButton[compactIcon="true"]:focus { border-color: %(focus)s; }
        QPushButton[lightboxEdge="true"] { background: %(card_surface)s; border-radius: 0; }
        QPushButton[lightboxEdge="true"]:hover { background: %(card_surface_alt)s; }
        QPushButton[compactText="true"] {
            color: %(muted)s; background: transparent; border-color: transparent; padding: 3px 4px;
        }
        QPushButton[compactText="true"]:hover { color: %(ink)s; background: %(surface_alt)s; }
        QPushButton[compactText="true"]:focus { border-color: %(focus)s; }
        QLabel#status { color: %(muted)s; }
        QLabel#brand { font-size: %(brand_points)spt; font-weight: 700; }
        QLabel[eyebrow="true"] { color: %(muted)s; font-size: %(eyebrow_points)spt; font-weight: 700; }
        QLabel#latest-activity-message { color: %(muted)s; }
        QDialog#photosAuthorizationDialog { background: %(card_surface)s; }
        QTreeWidget#photosGalleryTree::item:selected {
            background: %(card_surface_alt)s; color: %(ink)s;
        }
        QPushButton#header-link-connect {
            background: %(card_surface)s; color: %(ink)s;
        }
        QLabel#headerLinkAddress { font-weight: 600; }
        QFrame[raisedSurface="true"] {
            border: 1px solid %(line)s; border-radius: 10px;
            background: %(card_surface)s;
        }
        QWidget#watcher-metrics, QPushButton#headerPhotoStorage {
            border: 1px solid %(line)s; border-radius: 11px; background: %(card_surface)s;
        }
        QPushButton#headerPhotoStorage:hover { background: %(surface_alt)s; }
        QPushButton#headerPhotoStorage:focus { border-color: %(focus)s; }
        QPushButton#watcherHeaderFinish { padding: 0; }
        QWidget#latest-activity { border: 1px solid %(line)s; }
        QLabel#heading { font-size: 16px; font-weight: 600; }
        QLabel#notice { background: %(surface)s; color: %(warning)s; padding: 6px; }
        QHeaderView::section { background: %(card_surface)s; padding: 5px; }
        QTabBar::tab { padding: 6px 9px; color: %(muted)s; }
        QTabBar::tab:selected { color: %(ink)s; border-bottom: 2px solid %(primary)s; }
        QStatusBar { background: %(surface)s; }
        QWidget[shellCard="true"] { border-radius: 7px; background: %(card_surface)s; }
        QFrame[settingsCard="true"], QFrame[workspaceCard="true"], QFrame#activityCard {
            border: 1px solid %(line)s; border-radius: 10px; background: %(card_surface)s;
        }
        QProgressBar[importProgress="true"], QProgressBar[captureProgress="true"] {
            border: 1px solid %(line)s; border-radius: 0; background: %(surface_alt)s;
        }
        QProgressBar[importProgress="true"]::chunk, QProgressBar[captureProgress="true"]::chunk {
            background: %(primary)s;
        }
        QToolTip { color: %(ink)s; background: %(surface_alt)s; border: 1px solid %(line)s; }
    """ % {**PALETTE, "brand_points": UI_BRAND_POINTS, "eyebrow_points": UI_EYEBROW_POINTS})
