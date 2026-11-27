# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/ui_layout.py
# Module Type: Desktop layout persistence
# Purpose: Keep window and Photos geometry interchangeable between frontends.
# Responsibilities: Validate and atomically persist the existing layout schema.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Maintain per-user desktop placement independently of the GUI toolkit."""

from dataclasses import dataclass
from pathlib import Path
from typing import Literal, Tuple

from .flight_jobs import read_document
from .models import JsonValue
from .state_store import atomic_write_json

# Purpose: Start with a compact hierarchy and leave room for photos.
# Type: int. Units: pixels. Scope: the left gallery-tree pane.
# Consumers: PhotosWorkspace._build_widgets.
# Rationale: 210 px keeps common names visible and the splitter adjustable.
PHOTOS_TREE_WIDTH = 210

# Purpose: Keep one safe metadata-inspector column compact and adjustable.
# Type: int. Units: pixels. Scope: optional right Photos pane.
# Consumers: PhotosWorkspace._build_widgets.
# Rationale: 250 px fits common localized labels and remains collapsible.
PHOTOS_INFO_PANE_WIDTH = 250

# Purpose: Move Photos heading controls below the title in a narrow pane.
# Type: int. Units: logical pixels. Scope: the selected-gallery heading.
# Consumers: PhotosWorkspace and QtPhotos.
# Rationale: 440 gives the localized switch and title useful space.
PHOTOS_HEADING_STACK_WIDTH = 440
# Purpose: Reserve readable title space beside both toolbar groups.
# Type: int. Units: logical pixels. Scope: the selected-gallery heading.
# Consumers: PhotosWorkspace and QtPhotos.
# Rationale: 240 avoids a three-line title beside a short control row.
PHOTOS_HEADING_TITLE_MINIMUM = 240
# Purpose: Match the native reference's compact icon button target.
# Type: int. Units: logical pixels per edge. Scope: desktop shell and Photos.
# Consumers: QtWindow navigation/header and QtPhotos gallery heading.
# Rationale: Tk uses 26 px cells for the rail, header and gallery actions.
SHELL_ICON_BUTTON_EDGE = 26

# Purpose: Keep the first-launch window within common laptop display bounds.
# Type: str; Units: pixels; Scope: WinApp main window.
# Consumers: WatcherApp.build_ui.
# Rationale: Reserve enough room for the primary workflow without opening a
# window taller than a typical 768-pixel desktop after system decorations.
WATCHER_WINDOW_GEOMETRY = "980x580"
# Purpose: Preserve usable navigation and controls on smaller displays.
# Type: tuple[int, int]; Units: pixels; Scope: WinApp main window.
# Consumers: WatcherApp.build_ui.
# Rationale: Keep the UI workable at compact laptop widths while allowing the
# scrollable content views to handle longer forms.
WATCHER_WINDOW_MINIMUM = (840, 500)
# Purpose: Keep Watcher preferences readable while shorter displays scroll.
# Type: tuple[int, int]. Units: pixels. Scope: the Watcher settings sheet.
# Consumers: WatcherApp.open_watcher_settings and QtSettings.
# Rationale: 560 by 340 accommodates the compact Watcher form and its footer.
WATCHER_SETTINGS_MINIMUM = (560, 340)
# Purpose: Keep the owning workspace visible around a desktop dialog.
# Type: int. Units: logical pixels per edge. Scope: centered modal placement.
# Consumers: EmbeddedWindow._fit and QtPhotos authorization.
# Rationale: 20 px retains context at the supported main-window minimum.
DESKTOP_DIALOG_MARGIN = 20
# Purpose: Reserve content and footer space for a desktop dialog.
# Type: tuple[int, int]. Units: logical pixels. Scope: preferred modal size.
# Consumers: EmbeddedWindow and QtPhotos authorization.
# Rationale: 900 by 620 accommodates longer dialogs on an ample desktop.
DESKTOP_DIALOG_SIZE = (900, 620)
# Purpose: Show recent and active addresses without expanding the link prompt.
# Type: int. Units: rows. Scope: one gallery address list.
# Consumers: Tk and Qt linking dialogs. Rationale: Six keeps the remaining
# controls visible while an independent scrollbar exposes more history.
LINKING_ADDRESS_VISIBLE_ROWS = 6
# Purpose: Preserve the same compact shell spacing between desktop frontends.
# Type: tuple[int, int, int, int]. Units: logical pixels, left/top/right/bottom.
# Scope: WinApp main window. Consumers: Tk and Qt shell.
# Rationale: the reference shell leaves 16/12/16/10 px around its content.
SHELL_INSETS = (16, 12, 16, 10)
# Purpose: Separate the reference navigation actions and workspace.
# Type: int. Units: logical pixels. Scope: WinApp navigation.
# Consumers: Tk and Qt shell. Rationale: 8 matches the Tk action rhythm.
SHELL_RAIL_GAP = 8
# Purpose: Separate header groups, wrapped rows and the workspace.
# Type: int. Units: logical pixels. Scope: responsive WinApp header.
# Consumers: CompactAppHeader and QtAppHeader. Rationale: reference widths
# are 12 between groups, 2 between wraps and 6 above the workspace.
HEADER_GROUP_GAP = 12
HEADER_ROW_GAP = 2
HEADER_WORKSPACE_GAP = 6
# Purpose: Keep Watcher count and scan placeholders the same width as readings.
# Type: int. Units: character cells. Scope: the two Watcher header metrics.
# Consumers: WatcherApp.build_app_header and QtWindow._build_header.
# Rationale: Five fits HH:MM and ordinary queue sizes while allowing longer values.
WATCHER_HEADER_METRIC_MIN_CELLS = 5
# Purpose: Match the reference action targets in Watcher and Import.
# Type: int. Units: logical pixels. Scope: compact and standard card actions.
# Consumers: QtCapture and QtImport. Rationale: Tk uses 36 px for the compact
# folder tools and 42 px for the main Watcher, report and Import actions.
WORKSPACE_COMPACT_ACTION_HEIGHT, WORKSPACE_ACTION_HEIGHT = (36, 42)
# Purpose: Align the Watcher and Import card content with native Tk padding.
# Type: tuple[int, int, int, int]. Units: logical pixels per side.
# Scope: desktop workspace card. Consumers: QtCapture and QtImport.
# Rationale: The reference puts 14 px at each side and 10 px above/below.
WORKSPACE_CARD_INSETS = (14, 10, 14, 10)
# Purpose: Preserve room around a contextual panel even on a narrow window.
# Type: int. Units: logical pixels. Scope: each side of a report or progress panel.
# Consumers: OverlayPanel and QtCapture. Rationale: 12 px separates the panel
# from its owning workspace without wasting the operator's visible area.
OVERLAY_MARGIN_PX = 12
# Purpose: Fit translated actions in a readable, centered progress panel.
# Type: int. Units: logical pixels. Scope: preferred width of WinApp overlays.
# Consumers: OverlayPanel and QtCapture. Rationale: 760 px fits two action columns.
OVERLAY_WIDTH_PX = 760
# Purpose: Bound the visible progress and recovery panel height.
# Type: int. Units: logical pixels. Scope: preferred height of WinApp overlays.
# Consumers: OverlayPanel and QtCapture. Rationale: 580 px fits the body and footer.
OVERLAY_HEIGHT_PX = 580
# Purpose: Share compact action rows without clipping translated labels.
# Type: int. Units: columns. Scope: WinApp overlay actions.
# Consumers: layout_actions and QtCapture. Rationale: Two columns preserve the action hierarchy.
OVERLAY_ACTION_COLUMNS = 2
# Purpose: Bound the dedicated, non-secret main-window layout preference file.
# Type: int; Units: bytes; Scope: one per-user desktop layout snapshot.
# Consumers: _read_ui_layout and _write_ui_layout.
# Rationale: 8 KiB is ample for geometry and a few future view fields.
UI_LAYOUT_STATE_MAX_BYTES = 8 * 1024

# Purpose: Coalesce geometry and Photos sash movement before atomic persistence.
# Type: int; Units: milliseconds; Scope: one visible main-window layout update.
# Consumers: WatcherApp._schedule_ui_layout_save.
# Rationale: 300 ms avoids disk writes during resize drags.
UI_LAYOUT_SAVE_DELAY_MS = 300

# Purpose: Keep the Photos hierarchy useful while leaving room for its viewer.
# Type: int; Units: pixels; Scope: saved tree-pane width.
# Consumers: ViewLayoutState validation and PhotosWorkspace sash restoration.
# Rationale: A 150 to 600 px range avoids unreadable or viewer-starving panes.
UI_LAYOUT_TREE_WIDTH_BOUNDS = (150, 600)

# Purpose: Bound the right inspector width across desktop layouts.
# Type: tuple[int, int]; Units: pixels; Scope: Photos pane and saved preference.
# Consumers: _clamp_ui_layout_state and QtPhotos splitter sizing.
# Rationale: 180 px retains readable metadata and 600 px preserves viewer room.
UI_LAYOUT_INFO_WIDTH_BOUNDS = (180, 600)

# Purpose: Keep a visible region for gallery media beside the tree pane.
# Type: int; Units: pixels; Scope: Photos tree and inspector width allocation.
# Consumers: _clamp_ui_layout_state and QtPhotos splitter sizing.
# Rationale: 320 px leaves at least one useful gallery column at narrow widths.
UI_LAYOUT_VIEWER_MINIMUM_WIDTH = 320


@dataclass(frozen=True)
class ViewLayoutState:
    """Hold validated, non-secret geometry for the desktop window and Photos.

    Attributes:
        width (int): Normal window client width in logical pixels.
        height (int): Normal window client height in logical pixels.
        x (int): Normal window horizontal position in virtual-root pixels.
        y (int): Normal window vertical position in virtual-root pixels.
        window_state (Literal["normal", "zoomed"]): Last visible window mode.
        photos_tree_width (int): Photos tree sash position in logical pixels.
        photos_info_width (int): Right inspector width in logical pixels.
    """

    width: int
    height: int
    x: int
    y: int
    window_state: Literal["normal", "zoomed"]
    photos_tree_width: int
    photos_info_width: int = PHOTOS_INFO_PANE_WIDTH


def _clamp_ui_layout_state(
    state: ViewLayoutState,
    bounds: Tuple[int, int, int, int],
    minimum: Tuple[int, int],
) -> ViewLayoutState:
    """Clamp persisted dimensions and position to the current desktop display.

    Args:
        state (ViewLayoutState): Requested saved layout values.
        bounds (Tuple[int, int, int, int]): Virtual-root x, y, width and height.
        minimum (Tuple[int, int]): Current main-window minimum dimensions.

    Returns:
        ViewLayoutState: Geometry wholly inside the current virtual root with
            bounded window and Photos tree sizes.
    """
    origin_x, origin_y, area_width, area_height = bounds
    area_width = max(1, area_width)
    area_height = max(1, area_height)
    min_width = min(max(1, minimum[0]), area_width)
    min_height = min(max(1, minimum[1]), area_height)
    width = min(area_width, max(min_width, state.width))
    height = min(area_height, max(min_height, state.height))
    x = min(origin_x + area_width - width, max(origin_x, state.x))
    y = min(origin_y + area_height - height, max(origin_y, state.y))
    tree_minimum, tree_maximum = UI_LAYOUT_TREE_WIDTH_BOUNDS
    tree_maximum = min(
        tree_maximum, max(tree_minimum, width - UI_LAYOUT_VIEWER_MINIMUM_WIDTH),
    )
    tree_minimum = min(tree_minimum, tree_maximum)
    tree_width = min(tree_maximum, max(tree_minimum, state.photos_tree_width))
    window_state = state.window_state if state.window_state in (
        "normal", "zoomed",
    ) else "normal"
    info_minimum, info_maximum = UI_LAYOUT_INFO_WIDTH_BOUNDS
    info_width = min(info_maximum, max(info_minimum, state.photos_info_width))
    return ViewLayoutState(
        width, height, x, y, window_state, tree_width, info_width,
    )


def _read_ui_layout(
    path: Path,
    bounds: Tuple[int, int, int, int],
    minimum: Tuple[int, int],
) -> ViewLayoutState | None:
    """Read and validate the dedicated per-user desktop layout document.

    Args:
        path (Path): Dedicated local layout preference file.
        bounds (Tuple[int, int, int, int]): Current desktop virtual-root bounds.
        minimum (Tuple[int, int]): Current main-window minimum dimensions.

    Returns:
        ViewLayoutState | None: Clamped saved state, or None when absent,
            oversized, malformed or invalid at the document boundary.
    """
    try:
        if path.is_symlink() or path.stat().st_size > UI_LAYOUT_STATE_MAX_BYTES:
            return None
        document = read_document(path)
        if type(document.get("version")) is not int or document["version"] != 1:
            return None
        window = document.get("window")
        photos = document.get("photos")
        if not isinstance(window, dict) or not isinstance(photos, dict):
            return None

        def integer_field(
            fields: dict[str, JsonValue], name: str, default: int,
        ) -> int:
            """Return one exact integer field or its local safe default.

            Args:
                fields (dict[str, JsonValue]): Validated nested JSON object.
                name (str): Field name owned by this layout schema.
                default (int): Safe default for a missing or invalid field.

            Returns:
                int: Non-boolean integer field or the supplied default.
            """
            value = fields.get(name)
            return value if type(value) is int else default

        default_width, default_height = (
            int(value) for value in WATCHER_WINDOW_GEOMETRY.split("x")
        )
        raw_state = window.get("state")
        state = ViewLayoutState(
            integer_field(window, "width", default_width),
            integer_field(window, "height", default_height),
            integer_field(window, "x", bounds[0]),
            integer_field(window, "y", bounds[1]),
            raw_state if raw_state in ("normal", "zoomed") else "normal",
            integer_field(photos, "tree_width", PHOTOS_TREE_WIDTH),
            integer_field(photos, "info_width", PHOTOS_INFO_PANE_WIDTH),
        )
        return _clamp_ui_layout_state(state, bounds, minimum)
    except (OSError, ValueError, TypeError, KeyError):
        return None


def _write_ui_layout(path: Path, state: ViewLayoutState) -> None:
    """Atomically merge current window and Photos dimensions into the profile.

    Args:
        path (Path): Dedicated per-user layout preference file.
        state (ViewLayoutState): Validated window and Photos pane values.

    Returns:
        None: Publishes an atomic JSON snapshot while retaining unrelated keys.
    """
    document: dict[str, JsonValue] = {}
    try:
        if (not path.is_symlink()
                and path.stat().st_size <= UI_LAYOUT_STATE_MAX_BYTES):
            document = read_document(path)
    except (OSError, ValueError, TypeError):
        document = {}
    window_value = document.get("window")
    photos_value = document.get("photos")
    window: dict[str, JsonValue] = (
        dict(window_value) if isinstance(window_value, dict) else {}
    )
    photos: dict[str, JsonValue] = (
        dict(photos_value) if isinstance(photos_value, dict) else {}
    )
    window.update({
        "width": state.width, "height": state.height,
        "x": state.x, "y": state.y, "state": state.window_state,
    })
    photos["tree_width"] = state.photos_tree_width
    photos["info_width"] = state.photos_info_width
    document.update({"version": 1, "window": window, "photos": photos})
    atomic_write_json(path, document)
