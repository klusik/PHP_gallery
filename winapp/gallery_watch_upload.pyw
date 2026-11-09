# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Watch a local folder and upload stable images to PHP Gallery.
# Responsibilities:
#   - Coordinate local observation, multipart transport and retry state while PHP owns authorization and storage.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/gallery_watch_upload.pyw
#
# Author:
#   Rudolf Klusal
#
# License:
#   MIT License (see LICENSE file in repository)
#
# Notes:
#   - Keep comments and docstrings intact when modifying this file.
"""
PHP Gallery watched-folder uploader.

This Windows companion app watches one local folder and uploads new image
files to PHP Gallery through a gallery-scoped API key. The Python side is
intentionally thin: it observes files, waits until each file is stable, sends
an HTTP multipart upload request, and records local retry state.

Gallery-side validation, permissions, storage decisions, database indexing,
thumbnail generation, and any future business rules remain owned by PHP
Gallery. Keeping those rules server-side prevents this helper from becoming a
second, divergent implementation of upload behavior.
"""

import argparse
import base64
import concurrent.futures
import copy
import ctypes
import email.message
import hashlib
import json
import http.client
import importlib
import math
import mimetypes
import multiprocessing
import os
import shutil
import queue
import re
import stat
import subprocess
import sys
import tempfile
import threading
import time
import uuid
import webbrowser
from dataclasses import dataclass, field, replace
from pathlib import Path
from types import TracebackType
from typing import (
    Any,
    Callable,
    Dict,
    Iterable,
    IO,
    List,
    Literal,
    Optional,
    Set,
    Tuple,
)
from urllib import error, parse, request

# Purpose: Bound the amount of queued Tk work handled in one event-loop turn.
# Type: int; Units: worker events per batch; Scope: one Watcher event drain;
# Consumers: WatcherApp._drain_event_batch; Rationale: process FIFO events
# cooperatively without allowing sustained worker output to monopolize Tk.
WATCHER_EVENT_BATCH_LIMIT = 64
# Purpose: Bound cooperative event draining between complete worker events.
# Type: float; Units: milliseconds per batch; Scope: one Watcher event drain;
# Consumers: WatcherApp._drain_event_batch; Rationale: return control to Tk
# promptly while never interrupting an individual domain handler.
WATCHER_EVENT_BATCH_BUDGET_MS = 8.0
# Purpose: Give a budget-limited event queue prompt, non-spinning continuation.
# Type: int; Units: milliseconds; Scope: continuation after a bounded batch;
# Consumers: WatcherApp.drain_events; Rationale: preserve responsiveness
# without scheduling a zero-delay callback loop.
WATCHER_EVENT_CONTINUATION_MS = 16
# Purpose: Preserve the ordinary worker-event poll cadence when a batch drains.
# Type: int; Units: milliseconds; Scope: idle event queue polling;
# Consumers: WatcherApp.drain_events; Rationale: retain the existing 200 ms
# idle cadence while using a shorter delay only after an exhausted batch.
WATCHER_EVENT_IDLE_POLL_MS = 200

# Dispatch helper/version modes before application configuration or logging.
if __name__ == "__main__":
    multiprocessing.freeze_support()
    if len(sys.argv) == 3 and sys.argv[1] == "--runtime-info":
        from uploader.runtime_check import runtime_info

        # Exit before configuration/logging/Tk. The builder owns this fresh
        # staging path and requires both successful exit and its exact report.
        try:
            Path(sys.argv[2]).write_text(
                json.dumps(runtime_info()), encoding="utf-8"
            )
        except (OSError, RuntimeError) as exc:
            if sys.stderr is not None:
                print("Runtime check failed: " + str(exc), file=sys.stderr)
            raise SystemExit(1)
        raise SystemExit(0)
    if sys.argv[1:] == ["--self-update-smoke"]:
        try:
            from uploader.update_helper import WindowsBackend
            expected_version = (Path(__file__).resolve().parent / "VERSION").read_text(encoding="utf-8").strip()
            if WindowsBackend().version(sys.executable) != expected_version:
                raise RuntimeError("Frozen executable version does not match bundled VERSION")
        except Exception as exc:
            # Windowed builds may have no stderr; the exit code remains authoritative.
            if sys.stderr is not None:
                print("Frozen update smoke failed: " + str(exc)[:500], file=sys.stderr)
            raise SystemExit(1)
        raise SystemExit(0)
    if sys.argv[1:] == ["--version"]:
        print((Path(__file__).resolve().parent / "VERSION").read_text(encoding="utf-8").strip())
        raise SystemExit(0)
    if len(sys.argv) >= 2 and sys.argv[1] == "--self-update-helper":
        if len(sys.argv) != 3:
            raise SystemExit(2)
        from uploader.update_helper import main as update_helper_main
        raise SystemExit(update_helper_main(sys.argv[2]))

import logging
from logging.handlers import RotatingFileHandler

from uploader.config import CONFIG_SCHEMA_VERSION, DEFAULTS as REDESIGN_CONFIG_DEFAULTS, migrate_config_payload
from uploader.config_snapshot import ConfigSnapshot, ConfigSnapshotError, ConfigSnapshotStore
from uploader.credential_store import WindowsUploadModeStore
from uploader.macos_mode import MacUploadModeStore, assert_local_macos_profile
from uploader.mode_coordinator import (
    CoordinatedWork, GuiModeOwner, ModeConfigPort, ModeCoordinator, ModeCoordinatorError,
)
from uploader.diagnostics import redact_text
from uploader.linked_upload import (
    LINKED_UPLOAD_CHUNK_BYTES,
    LINKED_UPLOAD_FILE_MAX_BYTES,
    LinkedTargetLease,
    LinkedThumbnail,
    LinkedUploadTarget,
    _FileSegment,
    capture_linked_source,
    delete_verified_linked_source,
    linked_target_binding,
    verify_linked_upload_target,
    verify_linked_source,
)
from uploader.windows_startup import (
    ACTIVATION_POLL_MS,
    STARTUP_TRAY_TIMEOUT_SECONDS,
    WindowsInstance,
    startup_supported,
    synchronize_startup,
)
from uploader.discovery import (
    ArchiveLimits,
    discover_folder,
    discover_selected_files,
    stage_zip_archive,
)
from uploader.media import capability_notes, detect_suffix_capabilities, probe_file
from uploader.models import (
    ActivityEvent,
    ImportItem,
    ImportJob,
    ImportPlan,
    ITEM_ACTIVE_STATES,
    LINKED_UPLOAD_UNCERTAIN_REASON,
    JsonValue,
    RECOVERABLE_ITEM_STATES,
)
from uploader.simconnect_location import SimCameraLocation, SimConnectCameraClient, SimulatorInfo
from uploader.simconnect_time import UtcSyncManager
from uploader.game_presence import GamePresence
from uploader.windows_game_processes import msfs_running
from uploader.flight_jobs import (
    SessionPlan, WatcherContinuation, WatcherContinuationError,
    WATCHER_CONTINUATION_LIFETIME_SECONDS,
    connection_binding, read_document,
)
from uploader.flight_report_translations import tr
from uploader.photos_workspace import (
    PHOTOS_INFO_PANE_WIDTH, PHOTOS_TREE_WIDTH, PhotosWorkspace,
)
from uploader.app_linking import (
    LINKING_GALLERY_ID_MAX, LinkedGallery, LinkingError,
    LinkingResponseError, LinkingSession,
)
from uploader.gallery_catalogue_cache import GalleryCatalogueCache
from uploader.gallery_view_preferences import (
    load_gallery_card_format, save_gallery_card_format,
)
from uploader.flight_workflow import FINALIZATION_DRAIN_SECONDS, FinalizationWorker
from uploader.flight_recorder import FlightRecorder
from uploader.windows_file_identity import assert_local_windows_profile
from uploader.windows_display import prepare_windows_display
from uploader.state_store import (
    ActivityWriter,
    JobStateStore,
    UploadStateTooLargeError,
    accepted_work_owner,
    atomic_write_json,
    linked_upload_state_path,
    load_upload_state,
    load_upload_state_for_mutation,
    merge_upload_sections,
    quarantine_malformed,
    _strict_mode_history_object,
    _reject_mode_history_constant,
    _strict_mode_history_float,
)

try:
    import tkinter as tk
    from tkinter import filedialog, messagebox, ttk
except ImportError:  # pragma: no cover
    # Tkinter can be absent in some stripped-down Python builds. The import is
    # optional so command-line mode can still report a clean error instead of
    # crashing at import time.
    tk = None
    filedialog = None
    messagebox = None
    ttk = None

if tk is not None:
    from uploader.ui_theme import (
        GalleryIdentityLabel,
        GlassCard,
        ThemeImage,
        _PALETTE,
        attach_inline_tooltip,
        configure_theme,
        create_smooth_icon,
        help_button,
        icon_button,
        paint_backdrop,
    )

try:
    from PIL import Image, ImageDraw, ImageOps, ImageStat
except ImportError:  # pragma: no cover
    # Pillow is optional for the watcher. Manual client-side thumbnail generation
    # requires it, while normal watch-folder uploads keep working without it.
    Image = None
    ImageDraw = None
    ImageOps = None
    ImageStat = None

try:
    import pystray
except ImportError:  # pragma: no cover
    pystray = None


APP_NAME = "PHPGalleryUploader"
APP_DISPLAY_NAME = "PHP Gallery uploader"
# Purpose: Keep the three watcher status glyphs aligned with compact controls.
# Type: tuple[int, int]. Units: pixels. Scope: Watcher header artwork.
# Consumers: WatcherApp.build_ui and WatcherApp.refresh_watcher_header.
# Rationale: 16 by 16 pixels matches the existing header status indicator.
WATCHER_HEADER_ICON_SIZE = (16, 16)
CONFIG_DIR = Path(os.environ.get("APPDATA", str(Path.home()))) / APP_NAME
CONFIG_PATH = CONFIG_DIR / "config.json"
STATE_PATH = CONFIG_DIR / "upload_state.json"
LOG_PATH = CONFIG_DIR / "watcher.log"
STAGING_ROOT = CONFIG_DIR / "staging"
APP_DIR = Path(__file__).resolve().parent
APP_VERSION = (APP_DIR / "VERSION").read_text(encoding="utf-8").strip()
REQUIREMENTS_PATH = APP_DIR / "requirements.txt"
# Purpose: Finish a current ACK and flush accepted work before helper launch.
# Type: float. Units: seconds. Scope: one in-app update checkpoint.
# Consumers: WatcherApp.wait_for_update_checkpoint. Rationale: 120 seconds
# covers ordinary uploads; a slower unresolved transfer postpones installation
# rather than letting setup terminate its writer.
UPDATE_CHECKPOINT_TIMEOUT_SECONDS = 120.0
# Purpose: Poll local readiness and retry transient checkpoint reads off Tk.
# Type: int. Units: milliseconds. Scope: startup and cancelled update recovery.
# Consumers: WatcherApp._inspect_update_continuation and
# WatcherApp._continue_update_resume. Rationale: 250 ms keeps filesystem and
# already-approved link readiness responsive within the existing deadline.
UPDATE_CONTINUATION_POLL_MS = 250
# Purpose: Space read-only gallery revalidation after transient server errors.
# Type: int. Units: milliseconds. Scope: one automatic Watcher continuation.
# Consumers: WatcherApp.drain_events. Rationale: 2000 ms avoids rapid repeated
# gallery reads while the existing 60-second continuation deadline bounds work.
UPDATE_CONTINUATION_TARGET_RETRY_MS = 2000
# Purpose: Bound checkpoint and already-approved saved-link recovery.
# Type: float. Units: seconds. Scope: one automatic Watcher continuation.
# Consumers: WatcherApp._inspect_update_continuation and
# WatcherApp._continue_update_resume. Rationale: 60 seconds bounds transient
# local read refusals and existing identity requests; missing authority pauses.
UPDATE_CONTINUATION_LINK_SECONDS = 60.0
# Purpose: Bound exception class names in safe continuation diagnostics.
# Type: int. Units: characters. Scope: Watcher resume exception summaries.
# Consumers: WatcherApp._continue_update_resume. Rationale: 64 characters are
# enough for locally authored class names while excluding arbitrary payloads.
UPDATE_RESUME_EXCEPTION_CLASS_MAX_CHARS = 64
ASSETS_DIR = APP_DIR / "assets"
TRAY_ICON_PNG_PATH = ASSETS_DIR / "tray-icon.png"
TRAY_ICON_ICO_PATH = ASSETS_DIR / "tray-icon.ico"
# Purpose: Keep the update badge visible at common tray-icon resolutions.
# Type: int; Units: minimum pixels; Scope: Windows tray update badge.
# Consumers: render_tray_icon.
# Rationale: 3 px yields a distinct mark on a 16 px notification icon.
TRAY_BADGE_MIN_RADIUS = 3
# Purpose: Scale the update badge to larger tray source artwork.
# Type: int; Units: pixels per badge radius divisor; Scope: Windows tray icon.
# Consumers: render_tray_icon.
# Rationale: One fifth of the smaller icon edge keeps the badge compact.
TRAY_BADGE_RADIUS_DIVISOR = 5
# Purpose: Keep the badge edge clear of the source icon boundary.
# Type: int; Units: pixels; Scope: Windows tray update badge.
# Consumers: render_tray_icon.
# Rationale: A 1 px inset avoids clipping the outer white rim.
TRAY_BADGE_EDGE_INSET = 1
# Purpose: Keep a crisp white outline around the red update badge.
# Type: int; Units: pixels; Scope: tray badge boundary.
# Consumers: render_tray_icon.
# Rationale: One pixel preserves a clean boundary at standard tray sizes.
TRAY_BADGE_OUTLINE_WIDTH = 1
# Purpose: Scale the white update glyph stroke to the badge radius.
# Type: int; Units: pixels per radius divisor; Scope: tray badge glyph.
# Consumers: render_tray_icon.
# Rationale: Half the radius yields about a 1.5 px stem after 256-to-16 px
#     tray downsampling, preserving a bright center pixel in the exclamation.
TRAY_BADGE_GLYPH_STROKE_DIVISOR = 2
# Purpose: Keep the exclamation stem centered within the badge.
# Type: int; Units: radius divisor; Scope: tray badge glyph.
# Consumers: render_tray_icon.
# Rationale: Starting halfway above center leaves room for the lower dot.
TRAY_BADGE_GLYPH_LINE_START_DIVISOR = 2
# Purpose: Stop the exclamation stem before its separate dot.
# Type: int; Units: radius divisor; Scope: tray badge glyph.
# Consumers: render_tray_icon.
# Rationale: Ending one sixth below center separates stem and dot at small sizes.
TRAY_BADGE_GLYPH_LINE_END_DIVISOR = 6
# Purpose: Place the exclamation dot within the red badge interior.
# Type: int; Units: radius divisor; Scope: tray badge glyph.
# Consumers: render_tray_icon.
# Rationale: Half a radius below center keeps the dot clear of the outer rim.
TRAY_BADGE_GLYPH_DOT_OFFSET_DIVISOR = 2
# Purpose: Ensure the update glyph remains visible on the smallest badges.
# Type: int; Units: minimum pixels; Scope: tray badge glyph.
# Consumers: render_tray_icon.
# Rationale: One pixel is the smallest crisp stroke supported by Pillow.
TRAY_BADGE_GLYPH_MIN_STROKE = 1
# Purpose: Keep the exclamation dot proportional to its stem.
# Type: int; Units: stroke-width divisor; Scope: tray badge glyph.
# Consumers: render_tray_icon.
# Rationale: Half the stem width forms a compact round dot after downsampling.
TRAY_BADGE_GLYPH_DOT_RADIUS_DIVISOR = 2
# Purpose: Respect the Windows notification-area tooltip text limit.
# Type: int; Units: visible characters; Scope: pystray icon title.
# Consumers: WatcherApp.tray_tooltip_text.
# Rationale: 127 visible characters reserve one character for the native null.
TRAY_TOOLTIP_MAX_LENGTH = 127
# Purpose: Keep the first-launch window within common laptop display bounds.
# Type: str; Units: pixels; Scope: Tkinter watcher main window.
# Consumers: WatcherApp.build_ui.
# Rationale: Reserve enough room for the primary workflow without opening a
# window taller than a typical 768-pixel desktop after system decorations.
WATCHER_WINDOW_GEOMETRY = "980x580"
# Purpose: Preserve usable navigation and controls on smaller displays.
# Type: tuple[int, int]; Units: pixels; Scope: Tkinter watcher main window.
# Consumers: WatcherApp.build_ui.
# Rationale: Keep the UI workable at compact laptop widths while allowing the
# scrollable content views to handle longer forms.
WATCHER_WINDOW_MINIMUM = (840, 500)
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

# Purpose: Bound saved right inspector width across desktop layouts.
# Type: tuple[int, int]; Units: pixels; Scope: Photos inspector preference.
# Consumers: _clamp_ui_layout_state.
# Rationale: 180 px retains readable metadata and 600 px preserves viewer room.
UI_LAYOUT_INFO_WIDTH_BOUNDS = (180, 600)

# Purpose: Keep a visible region for gallery media beside the tree pane.
# Type: int; Units: pixels; Scope: saved Photos tree-pane maximum width.
# Consumers: _clamp_ui_layout_state.
# Rationale: 320 px leaves at least one useful gallery column at narrow widths.
UI_LAYOUT_VIEWER_MINIMUM_WIDTH = 320


@dataclass(frozen=True)
class ViewLayoutState:
    """Hold validated, non-secret geometry for the desktop window and Photos.

    Attributes:
        width (int): Normal window client width in Tk pixels.
        height (int): Normal window client height in Tk pixels.
        x (int): Normal window horizontal position in virtual-root pixels.
        y (int): Normal window vertical position in virtual-root pixels.
        window_state (Literal["normal", "zoomed"]): Last visible window mode.
        photos_tree_width (int): Photos tree sash position in Tk pixels.
        photos_info_width (int): Right inspector width in Tk pixels.
    """

    width: int
    height: int
    x: int
    y: int
    window_state: Literal["normal", "zoomed"]
    photos_tree_width: int
    photos_info_width: int = PHOTOS_INFO_PANE_WIDTH


def _layout_display_bounds(root: "tk.Tk") -> Tuple[int, int, int, int]:
    """Return Tk's current virtual-root rectangle for safe placement.

    Args:
        root (tk.Tk): Current Tk display owner.

    Returns:
        Tuple[int, int, int, int]: Virtual-root x, y, width and height, falling
            back to the current screen rectangle when Tk has no virtual root.
    """
    try:
        bounds = (
            int(root.winfo_vrootx()), int(root.winfo_vrooty()),
            int(root.winfo_vrootwidth()), int(root.winfo_vrootheight()),
        )
    except (tk.TclError, TypeError, ValueError):
        bounds = (0, 0, 0, 0)
    if bounds[2] > 0 and bounds[3] > 0:
        return bounds
    try:
        return (0, 0, int(root.winfo_screenwidth()), int(root.winfo_screenheight()))
    except (tk.TclError, TypeError, ValueError):
        return (0, 0, WATCHER_WINDOW_MINIMUM[0], WATCHER_WINDOW_MINIMUM[1])


def _clamp_ui_layout_state(
    state: ViewLayoutState,
    bounds: Tuple[int, int, int, int],
    minimum: Tuple[int, int],
) -> ViewLayoutState:
    """Clamp persisted dimensions and position to the current Tk display.

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
        bounds (Tuple[int, int, int, int]): Current Tk virtual-root bounds.
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

# Purpose: Keep credential entry comfortably sized while remaining compact.
# Type: str; Units: pixels; Scope: gallery connection dialog.
# Consumers: WatcherApp.open_connection_dialog.
# Rationale: The URL and masked key fields fit with the explicit save actions.
CONNECTION_DIALOG_GEOMETRY = "640x300"
# Purpose: Keep the credential dialog usable on smaller displays.
# Type: tuple[int, int]; Units: pixels; Scope: gallery connection dialog.
# Consumers: WatcherApp.open_connection_dialog.
# Rationale: URL and API-key fields retain enough room for editing.
CONNECTION_DIALOG_MINIMUM = (560, 280)
# Purpose: Leave room for system borders and taskbars around the connection dialog.
# Type: tuple[int, int]; Units: pixels; Scope: gallery connection dialog.
# Consumers: WatcherApp.open_connection_dialog.
# Rationale: Keep the resizable credential editor inside the usable display area.
CONNECTION_DIALOG_SCREEN_MARGIN = (80, 100)
# Purpose: Keep long site addresses, folder paths and feedback readable.
# Type: int. Units: Tk pixels. Scope: main window summary labels.
# Consumers: WatcherApp.build_gallery_link_bar and WatcherApp.build_watch_tab.
# Rationale: 500 leaves room for neighboring actions at the minimum width.
WINDOW_SUMMARY_WRAP = 500

# Purpose: Keep watcher preferences readable while allowing shorter screens to scroll.
# Type: tuple[int, int]; Units: pixels; Scope: watcher settings sheet.
# Consumers: WatcherApp.open_watcher_settings.
# Rationale: The sheet remains compact and scrolls when content exceeds display.
WATCHER_SETTINGS_MINIMUM = (560, 340)
# Purpose: Keep the resizable watcher sheet inside the usable desktop area.
# Type: tuple[int, int]; Units: pixels; Scope: watcher settings sheet.
# Consumers: WatcherApp.open_watcher_settings.
# Rationale: Leave room for system borders and taskbars around the dialog.
WATCHER_SETTINGS_SCREEN_MARGIN = (80, 100)
# Purpose: Provide a geometry floor on unusually small displays.
# Type: tuple[int, int]; Units: pixels; Scope: watcher settings sheet.
# Consumers: WatcherApp.open_watcher_settings.
# Rationale: Avoid zero or negative dimensions when display work area is tight.
WATCHER_SETTINGS_FALLBACK_SIZE = (420, 300)
# Purpose: Keep the persistent latest-event footer readable on one line.
# Type: int; Units: characters; Scope: Main-window event footer.
# Consumers: WatcherApp.record_activity.
# Rationale: The footer is a compact status hint; full messages remain in
# Activity and the rotating log.
LATEST_ACTIVITY_PREVIEW_LENGTH = 150
# Purpose: Convert native Windows wheel deltas into whole scroll increments.
# Type: int; Units: delta units per notch; Scope: ScrollableFrame wheel routing.
# Consumers: ScrollableFrame._on_mousewheel.
# Rationale: Windows reports 120 delta units for one standard wheel notch.
WINDOWS_MOUSEWHEEL_DELTA = 120


def render_tray_icon(
    base_image: "Image.Image",
    update_available: bool,
    watcher_pulse: bool = False,
) -> "Image.Image":
    """Return fresh tray artwork with independent update and Watcher marks.

    Args:
        base_image (Image.Image): Unmodified source artwork loaded from the
            bundled tray icon asset.
        update_available (bool): Whether to draw the red and white update badge.
        watcher_pulse (bool): Whether the Watcher marker is currently bright.

    Returns:
        Image.Image: A new RGBA image with the requested update badge and
            Watcher dot, or the unmarked source artwork.
    """
    icon_image = base_image.convert("RGBA")
    if ImageDraw is None:
        return icon_image

    width, height = icon_image.size
    radius = max(
        TRAY_BADGE_MIN_RADIUS,
        min(width, height) // TRAY_BADGE_RADIUS_DIVISOR,
    )
    inset = TRAY_BADGE_EDGE_INSET
    draw = ImageDraw.Draw(icon_image)
    if watcher_pulse:
        center = radius + inset
        draw.ellipse(
            (center - radius, height - center - radius,
             center + radius, height - center + radius),
            fill="#D92D3F",
            outline="#FFFFFF",
            width=TRAY_BADGE_OUTLINE_WIDTH,
        )
    if not update_available:
        return icon_image
    center_x = width - radius - inset - 1
    center_y = radius + inset
    bounds = (
        center_x - radius,
        center_y - radius,
        center_x + radius,
        center_y + radius,
    )
    draw.ellipse(
        bounds,
        fill="#D92D3F",
        outline="#FFFFFF",
        width=TRAY_BADGE_OUTLINE_WIDTH,
    )
    glyph_stroke = max(
        TRAY_BADGE_GLYPH_MIN_STROKE,
        radius // TRAY_BADGE_GLYPH_STROKE_DIVISOR,
    )
    draw.line(
        (
            center_x,
            center_y - radius // TRAY_BADGE_GLYPH_LINE_START_DIVISOR,
            center_x,
            center_y + radius // TRAY_BADGE_GLYPH_LINE_END_DIVISOR,
        ),
        fill="#FFFFFF",
        width=glyph_stroke,
    )
    dot_y = center_y + radius // TRAY_BADGE_GLYPH_DOT_OFFSET_DIVISOR
    dot_radius = max(
        TRAY_BADGE_GLYPH_MIN_STROKE,
        glyph_stroke // TRAY_BADGE_GLYPH_DOT_RADIUS_DIVISOR,
    )
    draw.ellipse(
        (
            center_x - dot_radius,
            dot_y - dot_radius,
            center_x + dot_radius,
            dot_y + dot_radius,
        ),
        fill="#FFFFFF",
    )
    return icon_image

_AI_TRANSFORMERS_PIPELINES: Dict[Tuple[str, str], Any] = {}
_AI_TRANSFORMERS_LOCK = threading.Lock()

SUPPORTED_SUFFIXES = {
    ".jpg",
    ".jpeg",
    ".png",
    ".gif",
    ".webp",
    ".heic",
    ".heif",
    ".dng",
}

DEFAULT_INTERVAL_SECONDS = 1.0
DEFAULT_STABLE_SECONDS = 2.0
DEFAULT_TIMEOUT_SECONDS = 180
# Purpose: Limit how often the watcher checks the directory.
# Type: float. Units: seconds. Scope: the Watcher form.
# Consumers: WatcherApp.current_watcher_config.
# Rationale: Preserve the existing 0.2-second minimum for scan scheduling.
MIN_INTERVAL_SECONDS = 0.2
# Purpose: Give a file time to stop changing before a watcher upload.
# Type: float. Units: seconds. Scope: the Watcher form.
# Consumers: WatcherApp.current_watcher_config.
# Rationale: Preserve the existing half-second minimum for file stability.
MIN_STABLE_SECONDS = 0.5
# Purpose: Bound delegated inventory batches before JSON encoding.
# Type: int. Units: file descriptors. Scope: one linked preflight request.
# Consumers: ManualUploadThread.preload_remote_inventory,
# ImportPreflightThread.run. Rationale: 500 leaves room within the two-MiB
# request cap for long escaped filenames and their SHA-256 descriptors.
LINKED_INVENTORY_BATCH_SIZE = 500
DEFAULT_INVENTORY_REFRESH_SECONDS = 30.0

# Purpose: Bound the watcher recording indicator's accessible color pulse.
# Type: int. Units: milliseconds. Scope: one active GUI watcher.
# Consumers: WatcherApp._pulse_watcher_recording.
# Rationale: 800 ms alternation stays noticeable while never hiding the marker.
WATCHER_RECORDING_PULSE_MS = 800

# Purpose: Animate unknown-duration Watcher linking and initial folder capture.
# Type: int. Units: milliseconds. Scope: Watcher startup activity only.
# Consumers: WatcherApp._set_watch_start_activity.
# Rationale: 50 ms keeps activity visible without suggesting measured progress.
WATCHER_START_ACTIVITY_MS = 50
# Purpose: Bound persisted Photos expansion entries against malformed growth.
# Type: int. Units: gallery IDs. Scope: one site/account preference file.
# Consumers: WatcherApp.load_photos_tree_state and save_photos_tree_state.
# Rationale: 10,000 exceeds realistic personal gallery counts while bounding IO.
PHOTOS_TREE_STATE_MAX_GALLERIES = 10000

# Purpose: Preserve transient-failure backoff without unbound historical state.
# Type: int. Units: seconds. Scope: one live legacy watcher and source hash.
# Consumers: WatcherThread.scan_once. Rationale: start at the existing five
# seconds and retain the old one-hour ceiling while avoiding another gallery's
# persisted retry metadata. The exponent remains bounded at the existing seven.
LEGACY_WATCH_RETRY_BASE_SECONDS = 5
LEGACY_WATCH_RETRY_CAP_SECONDS = 3600
LEGACY_WATCH_RETRY_EXPONENT_MAX = 7

# An unconfirmed legacy mutation is not evidence of absence or a retry grant.
LEGACY_UPLOAD_UNCERTAIN_REASON = (
    'Upload outcome is unconfirmed; review the gallery before retrying.'
)
DEFAULT_AI_WORKER_POLL_SECONDS = 60.0
DEFAULT_AI_WORKER_LEASE_SECONDS = 600
AI_WORKER_MIN_POLL_SECONDS = 5.0
AI_WORKER_MAX_LEASE_SECONDS = 3600
AI_ANALYZER_TIMEOUT_SECONDS = 1800
AI_HEARTBEAT_MIN_SECONDS = 15.0
AI_MODEL_NAME_DEFAULT = "local-image-metadata"
AI_MODEL_VERSION_DEFAULT = "1"
AI_VISION_BACKEND_DEFAULT = "auto"
AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT = "Salesforce/blip-image-captioning-base"
AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT = "google/owlvit-base-patch32"
AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT = 0.18
AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT = (
    "person, people, man, woman, child, face, dog, cat, horse, bird, animal, car, truck, bus, "
    "train, aircraft, airplane, helicopter, boat, bicycle, motorcycle, house, building, bridge, "
    "road, street, city, village, church, castle, tower, window, door, guitar, piano, violin, "
    "musical instrument, food, drink, table, chair, bed, computer, laptop, phone, camera, flower, "
    "tree, forest, mountain, beach, sea, river, lake, sky, cloud, sunset, snow, rain, airport, "
    "runway, cockpit, airplane wing"
)
AI_OLLAMA_URL_DEFAULT = "http://127.0.0.1:11434"
AI_OLLAMA_MODEL_DEFAULT = "llava:latest"
AI_SEMANTIC_PROMPT_DEFAULT = (
    "Describe this image for private gallery search metadata. "
    "Return JSON only with keys internal_description, labels, objects, scene, activities, text, and confidence_notes. "
    "Use concise lower-case labels for visible objects such as person, bridge, guitar, house, car, animal, aircraft, food, landscape, building. "
    "Do not invent objects that are not visually apparent. The metadata is internal search data, not public prose."
)
AI_VISION_BACKEND_CHOICES = ("auto", "pillow", "transformers", "ollama", "external")
THUMBNAIL_SIZES = [300, 600, 800, 960, 1280, 1600]
DEFAULT_THUMBNAIL_WORKERS = max(2, min(12, (os.cpu_count() or 4) // 2 or 2))
DEFAULT_UPLOAD_WORKERS = 1
MAX_THUMBNAIL_WORKERS = 32
MAX_UPLOAD_WORKERS = 1
@dataclass
class WatcherConfig:
    """
    Runtime and persisted configuration for the watcher app.

    The values are intentionally simple JSON-compatible primitives so the
    configuration file can be edited manually when needed. The GUI writes the
    same structure as command-line mode reads, which keeps both entry points
    aligned.

    Args:
        schema_version (int): Existing configuration migration marker.
        delete_uploaded_files (bool): Saved preference to delete a watched
            source after exact captured bytes receive upload confirmation.
        watch_recursive (bool): Include descendants in watch-folder discovery.
        manual_thumbnail_mode (str): Server or local thumbnail generation.
        archive_max_entries (int): Maximum accepted ZIP entry count.
        archive_max_uncompressed_bytes (int): Maximum expanded ZIP bytes.
        archive_max_file_bytes (int): Maximum individual expanded file bytes.
        archive_max_compression_ratio (float): Maximum accepted expansion
            ratio.
        staging_cleanup_age_hours (float): Age before unowned staging expires.
        close_to_tray (bool): Keep background work when closing to the tray.
        start_with_windows (bool): Start the installed app in the tray at logon.
        activity_history_limit (int): Maximum retained durable activity
            records.
        watched_folder (str): Local folder to observe for newly added images.
        gallery_url (str): PHP Gallery site root or explicit upload endpoint
            URL.
        api_key (str): Gallery-scoped API key used in the X-Gallery-API-Key
            header.
        scan_interval_seconds (float): Polling delay between folder scans.
        stable_seconds (float): Minimum unchanged duration before a file is
            uploaded.
        create_thumbnails (bool): Whether the server should create thumbnails
            after accepting the upload.
        attach_sim_camera_metadata (bool): Whether watched-folder uploads
            should try to attach the current Flight Simulator camera location.
        simconnect_dll_path (str): Optional user-selected SimConnect.dll
            override.
        sync_utc_time (bool): Opt-in UTC correction while watching; off by
            default.
        manual_thumbnail_workers (int): Manual upload process count for local
            thumbnail generation. Zero means automatic.
        manual_upload_workers (int): Manual upload thread count for multipart
            HTTP upload requests. Zero means automatic.
        inventory_refresh_seconds (float): Seconds between remote inventory
            handshakes. The default keeps long batches using fresh short-lived
            API requests.
        ai_worker_enabled (bool): Whether this app should run the optional AI
            metadata worker. Disabled by default so existing upload behavior is
            unchanged after upgrade.
        ai_worker_poll_seconds (float): Delay between queue polls when the
            server has no AI work for this API key.
        ai_worker_lease_seconds (int): Requested server lease length for one
            claimed AI job. Heartbeats extend the lease while work continues.
        ai_model_name (str): Worker-reported model or analyzer family name.
        ai_model_version (str): Worker-reported model or analyzer version.
        ai_external_command (str): Optional local command that receives an
            image path placeholder and returns JSON metadata on stdout.
        ai_vision_backend (str): Analyzer selection. Auto prefers the external
            command when configured, then the in-process Transformers backend,
            then local Ollama vision, then Pillow.
        ai_transformers_caption_model (str): Optional Hugging Face image-to-
            text model used inside this Python process for semantic captions.
        ai_transformers_detector_model (str): Optional Hugging Face zero-shot
            object detector used inside this Python process for semantic
            labels.
        ai_transformers_object_labels (str): Candidate object labels passed to
            the local zero-shot detector.
        ai_transformers_detection_threshold (float): Minimum detector score
            saved as an internal object label.
        ai_ollama_url (str): Local Ollama base URL used for semantic vision
            captions. The default points to localhost.
        ai_ollama_model (str): Ollama vision model name, for example
            llava:latest or llama3.2-vision:latest.
        ai_semantic_prompt (str): Prompt sent only to a local vision backend.
        _snapshot_token (object | None): Private config-owner generation; never
            serialized or used as a credential.
    """

    schema_version: int = CONFIG_SCHEMA_VERSION
    watched_folder: str = ""
    gallery_url: str = ""
    api_key: str = ""
    scan_interval_seconds: float = DEFAULT_INTERVAL_SECONDS
    stable_seconds: float = DEFAULT_STABLE_SECONDS
    create_thumbnails: bool = True
    attach_sim_camera_metadata: bool = True
    simconnect_dll_path: str = ""
    sync_utc_time: bool = False
    delete_uploaded_files: bool = True
    watch_recursive: bool = bool(REDESIGN_CONFIG_DEFAULTS["watch_recursive"])
    manual_thumbnail_mode: str = str(REDESIGN_CONFIG_DEFAULTS["manual_thumbnail_mode"])
    manual_thumbnail_workers: int = 0
    manual_upload_workers: int = 0
    inventory_refresh_seconds: float = DEFAULT_INVENTORY_REFRESH_SECONDS
    archive_max_entries: int = int(REDESIGN_CONFIG_DEFAULTS["archive_max_entries"])
    archive_max_uncompressed_bytes: int = int(REDESIGN_CONFIG_DEFAULTS["archive_max_uncompressed_bytes"])
    archive_max_file_bytes: int = int(REDESIGN_CONFIG_DEFAULTS["archive_max_file_bytes"])
    archive_max_compression_ratio: float = float(REDESIGN_CONFIG_DEFAULTS["archive_max_compression_ratio"])
    staging_cleanup_age_hours: float = float(REDESIGN_CONFIG_DEFAULTS["staging_cleanup_age_hours"])
    close_to_tray: bool = bool(REDESIGN_CONFIG_DEFAULTS["close_to_tray"])
    start_with_windows: bool = bool(REDESIGN_CONFIG_DEFAULTS["start_with_windows"])
    activity_history_limit: int = int(REDESIGN_CONFIG_DEFAULTS["activity_history_limit"])
    ai_worker_enabled: bool = False
    ai_worker_poll_seconds: float = DEFAULT_AI_WORKER_POLL_SECONDS
    ai_worker_lease_seconds: int = DEFAULT_AI_WORKER_LEASE_SECONDS
    ai_model_name: str = AI_MODEL_NAME_DEFAULT
    ai_model_version: str = AI_MODEL_VERSION_DEFAULT
    ai_external_command: str = ""
    ai_vision_backend: str = AI_VISION_BACKEND_DEFAULT
    ai_transformers_caption_model: str = AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT
    ai_transformers_detector_model: str = AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT
    ai_transformers_object_labels: str = AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT
    ai_transformers_detection_threshold: float = AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT
    ai_ollama_url: str = AI_OLLAMA_URL_DEFAULT
    ai_ollama_model: str = AI_OLLAMA_MODEL_DEFAULT
    ai_semantic_prompt: str = AI_SEMANTIC_PROMPT_DEFAULT
    _snapshot_token: object | None = field(default=None, repr=False, compare=False)

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "WatcherConfig":
        """
        Build a typed configuration object from decoded JSON data.

        Missing keys are treated as defaults. This makes configuration upgrades
        safe because older config files keep working when new fields are added.
        Numeric values are coerced from strings where possible because manual
        edits and GUI variables often produce text.

        Args:
            data (Dict[str, Any]): JSON configuration keyed by the
                WatcherConfig field names; string connection/model/path values,
                boolean switches, integer counts/byte limits and floating-point
                durations/ratios.

        Returns:
            WatcherConfig: Normalized WatcherConfig instance.

        Raises:
            ValueError: Raised by float conversion when numeric values are
                present but cannot be parsed.
        """
        data, _migrated = migrate_config_payload(data)
        return cls(
            schema_version=CONFIG_SCHEMA_VERSION,
            watched_folder=str(data.get("watched_folder", "")),
            gallery_url=str(data.get("gallery_url", "")),
            api_key=str(data.get("api_key", "")),
            scan_interval_seconds=float(data.get("scan_interval_seconds", DEFAULT_INTERVAL_SECONDS) or DEFAULT_INTERVAL_SECONDS),
            stable_seconds=float(data.get("stable_seconds", DEFAULT_STABLE_SECONDS) or DEFAULT_STABLE_SECONDS),
            create_thumbnails=bool(data.get("create_thumbnails", True)),
            attach_sim_camera_metadata=bool(data.get("attach_sim_camera_metadata", True)),
            simconnect_dll_path=str(data.get("simconnect_dll_path", "")),
            sync_utc_time=data.get("sync_utc_time", False) is True,
            delete_uploaded_files=bool(data.get("delete_uploaded_files", True)),
            watch_recursive=bool(data.get("watch_recursive", REDESIGN_CONFIG_DEFAULTS["watch_recursive"])),
            manual_thumbnail_mode=str(data.get("manual_thumbnail_mode", REDESIGN_CONFIG_DEFAULTS["manual_thumbnail_mode"]) or "server"),
            manual_thumbnail_workers=int(data.get("manual_thumbnail_workers", 0) or 0),
            manual_upload_workers=int(data.get("manual_upload_workers", 0) or 0),
            inventory_refresh_seconds=float(data.get("inventory_refresh_seconds", DEFAULT_INVENTORY_REFRESH_SECONDS) or DEFAULT_INVENTORY_REFRESH_SECONDS),
            archive_max_entries=int(data.get("archive_max_entries", REDESIGN_CONFIG_DEFAULTS["archive_max_entries"]) or REDESIGN_CONFIG_DEFAULTS["archive_max_entries"]),
            archive_max_uncompressed_bytes=int(data.get("archive_max_uncompressed_bytes", REDESIGN_CONFIG_DEFAULTS["archive_max_uncompressed_bytes"]) or REDESIGN_CONFIG_DEFAULTS["archive_max_uncompressed_bytes"]),
            archive_max_file_bytes=int(data.get("archive_max_file_bytes", REDESIGN_CONFIG_DEFAULTS["archive_max_file_bytes"]) or REDESIGN_CONFIG_DEFAULTS["archive_max_file_bytes"]),
            archive_max_compression_ratio=float(data.get("archive_max_compression_ratio", REDESIGN_CONFIG_DEFAULTS["archive_max_compression_ratio"]) or REDESIGN_CONFIG_DEFAULTS["archive_max_compression_ratio"]),
            staging_cleanup_age_hours=float(data.get("staging_cleanup_age_hours", REDESIGN_CONFIG_DEFAULTS["staging_cleanup_age_hours"]) or REDESIGN_CONFIG_DEFAULTS["staging_cleanup_age_hours"]),
            close_to_tray=bool(data.get("close_to_tray", REDESIGN_CONFIG_DEFAULTS["close_to_tray"])),
            start_with_windows=data["start_with_windows"] is True,
            activity_history_limit=int(data.get("activity_history_limit", REDESIGN_CONFIG_DEFAULTS["activity_history_limit"]) or REDESIGN_CONFIG_DEFAULTS["activity_history_limit"]),
            ai_worker_enabled=bool(data.get("ai_worker_enabled", False)),
            ai_worker_poll_seconds=float(data.get("ai_worker_poll_seconds", DEFAULT_AI_WORKER_POLL_SECONDS) or DEFAULT_AI_WORKER_POLL_SECONDS),
            ai_worker_lease_seconds=int(data.get("ai_worker_lease_seconds", DEFAULT_AI_WORKER_LEASE_SECONDS) or DEFAULT_AI_WORKER_LEASE_SECONDS),
            ai_model_name=str(data.get("ai_model_name", AI_MODEL_NAME_DEFAULT) or AI_MODEL_NAME_DEFAULT),
            ai_model_version=str(data.get("ai_model_version", AI_MODEL_VERSION_DEFAULT) or AI_MODEL_VERSION_DEFAULT),
            ai_external_command=str(data.get("ai_external_command", "")),
            ai_vision_backend=normalize_ai_vision_backend(str(data.get("ai_vision_backend", AI_VISION_BACKEND_DEFAULT) or AI_VISION_BACKEND_DEFAULT)),
            ai_transformers_caption_model=str(data.get("ai_transformers_caption_model", AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT) or AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT),
            ai_transformers_detector_model=str(data.get("ai_transformers_detector_model", AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT) or AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT),
            ai_transformers_object_labels=str(data.get("ai_transformers_object_labels", AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT) or AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT),
            ai_transformers_detection_threshold=float(data.get("ai_transformers_detection_threshold", AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT) or AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT),
            ai_ollama_url=str(data.get("ai_ollama_url", AI_OLLAMA_URL_DEFAULT) or AI_OLLAMA_URL_DEFAULT),
            ai_ollama_model=str(data.get("ai_ollama_model", AI_OLLAMA_MODEL_DEFAULT) or AI_OLLAMA_MODEL_DEFAULT),
            ai_semantic_prompt=str(data.get("ai_semantic_prompt", AI_SEMANTIC_PROMPT_DEFAULT) or AI_SEMANTIC_PROMPT_DEFAULT),
        )

    def to_dict(self) -> Dict[str, Any]:
        """
        Convert this configuration to a JSON-serializable dictionary.

        Returns:
            Dict[str, Any]: Configuration field names mapped to string
                connection/model/path values, boolean switches, integer
                counts/byte limits and floating-point durations/ratios,
                suitable for JSON.
        """
        return {
            "schema_version": CONFIG_SCHEMA_VERSION,
            "watched_folder": self.watched_folder,
            "gallery_url": self.gallery_url,
            "api_key": self.api_key,
            "scan_interval_seconds": self.scan_interval_seconds,
            "stable_seconds": self.stable_seconds,
            "create_thumbnails": self.create_thumbnails,
            "attach_sim_camera_metadata": self.attach_sim_camera_metadata,
            "simconnect_dll_path": self.simconnect_dll_path,
            "sync_utc_time": self.sync_utc_time,
            "delete_uploaded_files": self.delete_uploaded_files,
            "watch_recursive": self.watch_recursive,
            "manual_thumbnail_mode": self.manual_thumbnail_mode,
            "manual_thumbnail_workers": self.manual_thumbnail_workers,
            "manual_upload_workers": self.manual_upload_workers,
            "inventory_refresh_seconds": self.inventory_refresh_seconds,
            "archive_max_entries": self.archive_max_entries,
            "archive_max_uncompressed_bytes": self.archive_max_uncompressed_bytes,
            "archive_max_file_bytes": self.archive_max_file_bytes,
            "archive_max_compression_ratio": self.archive_max_compression_ratio,
            "staging_cleanup_age_hours": self.staging_cleanup_age_hours,
            "close_to_tray": self.close_to_tray,
            "start_with_windows": self.start_with_windows,
            "activity_history_limit": self.activity_history_limit,
            "ai_worker_enabled": self.ai_worker_enabled,
            "ai_worker_poll_seconds": self.ai_worker_poll_seconds,
            "ai_worker_lease_seconds": self.ai_worker_lease_seconds,
            "ai_model_name": self.ai_model_name,
            "ai_model_version": self.ai_model_version,
            "ai_external_command": self.ai_external_command,
            "ai_vision_backend": self.ai_vision_backend,
            "ai_transformers_caption_model": self.ai_transformers_caption_model,
            "ai_transformers_detector_model": self.ai_transformers_detector_model,
            "ai_transformers_object_labels": self.ai_transformers_object_labels,
            "ai_transformers_detection_threshold": self.ai_transformers_detection_threshold,
            "ai_ollama_url": self.ai_ollama_url,
            "ai_ollama_model": self.ai_ollama_model,
            "ai_semantic_prompt": self.ai_semantic_prompt,
        }


@dataclass
class LocalThumbnail:
    """
    One locally generated thumbnail variant waiting to be uploaded.

    Args:
        path (Path): Temporary thumbnail file path on the client computer.
        size (int): Long-side size in pixels, matching PHP Gallery
            thumbnail_sizes().
        format (str): Output format accepted by the gallery, either jpg or
            webp.
    """

    path: Path
    size: int
    format: str


def trim_path(value: str) -> Optional[Path]:
    """
    Convert one optional path string into a usable Path object.

    Args:
        value (str): Raw environment variable or config value.

    Returns:
        Optional[Path]: Path when the string is non-empty, otherwise None.
    """
    text = str(value).strip()
    if not text:
        return None
    return Path(text)


class CapturedWatcherConfig(WatcherConfig):
    """Hold sealed primitive settings for cooperating worker code.

    This is not a security boundary against arbitrary code in this process.
    """

    def __setattr__(self, name: str,
                    value: str | bool | int | float | object | None) -> None:
        """Refuse later changes to the worker's captured settings.

        Args:
            name (str): Config field selected by the assignment.
            value (str | bool | int | float | object | None): Configuration
                primitive or private snapshot token, accepted during construction.

        Returns:
            None: Stores initial fields before the capture is sealed.

        Raises:
            AttributeError: A sealed capture cannot be changed by a worker.
        """
        if getattr(self, '_capture_sealed', False):
            raise AttributeError('Captured worker configuration is immutable.')
        super().__setattr__(name, value)

    def __delattr__(self, name: str) -> None:
        """Refuse accidental removal of any sealed captured field.

        Args:
            name (str): Field selected for deletion.

        Returns:
            None: Deletes only an unsealed construction-time field.

        Raises:
            AttributeError: A sealed capture cannot be changed by a worker.
        """
        if getattr(self, '_capture_sealed', False):
            raise AttributeError('Captured worker configuration is immutable.')
        super().__delattr__(name)

    def __repr__(self) -> str:
        """Avoid exposing the captured API key in diagnostics.

        Returns:
            str: A fixed non-secret description.
        """
        return 'CapturedWatcherConfig(<private>)'


def capture_worker_config(config: WatcherConfig) -> CapturedWatcherConfig:
    """Create a sealed worker copy preserving the original snapshot token.

    Args:
        config (WatcherConfig): Current host configuration selected for work.

    Returns:
        CapturedWatcherConfig: Independent immutable primitive settings.

    Raises:
        ValueError: Fields or the owner generation cannot be safely captured.
    """
    values = config.to_dict()
    if (config._snapshot_token is None or any(
            type(value) not in (str, int, float, bool) for value in values.values())):
        raise ValueError('Worker configuration is unavailable.')
    captured = CapturedWatcherConfig.from_dict(values)
    captured._snapshot_token = config._snapshot_token
    if captured.to_dict() != values:
        raise ValueError('Worker configuration changed during capture.')
    captured._capture_sealed = True
    return captured


class ConfigStore:
    """
    Small persistence wrapper for the local configuration file.

    The class owns all filesystem details for config persistence so the GUI and
    command-line code do not duplicate path handling, JSON parsing, and atomic
    write behavior.
    """

    def __init__(self, path: Path | str = CONFIG_PATH) -> None:
        """
        Create a config store for one JSON file.

        Args:
            path (Path | str): Selected JSON config path. A string preserves
                its original spelling for mode evidence; Path callers retain
                the spelling already represented by their Path object.

        Returns:
            None: Create a config store for one JSON file.
        """
        self._mode_raw_path = str(path)
        self.path = Path(path)
        self._snapshot_store = ConfigSnapshotStore(
            self.path, frozenset(WatcherConfig().to_dict()),
        )
        self._snapshot = None
        self._lock = threading.RLock()
        self._revision = object()
        self._mode_transaction_active = False

    def load(self) -> WatcherConfig:
        """
        Load the configuration file.

        A confirmed missing file returns first-run defaults. An unreadable,
        malformed or ambiguous file is not treated as an empty configuration.

        Returns:
            WatcherConfig: Loaded configuration or first-run defaults.

        Raises:
            OSError: Configuration or its parent cannot be safely observed.
        """
        # First launches and explicit --config paths may need a new parent.
        # A created directory does not prove prior mode evidence never existed.
        with self._lock:
            if self._mode_transaction_active:
                raise ConfigSnapshotError('Configuration mode transaction is active.')
            self.path.parent.mkdir(parents=True, exist_ok=True)
            snapshot = self._snapshot_store.inspect()
            self._snapshot = snapshot
            self._revision = object()
            if snapshot.state == 'missing':
                return WatcherConfig(_snapshot_token=self._revision)
            if snapshot.state != 'valid':
                raise ConfigSnapshotError(
                    'Configuration is unavailable. Preserve the file and restore '
                    'a valid backup before starting uploads.'
                )
            try:
                data = self._snapshot_store.read(snapshot)
                normalized, migrated = migrate_config_payload(data)
                config = WatcherConfig.from_dict(normalized)
                config._snapshot_token = self._revision
                canonical_url = normalize_upload_url(config.gallery_url) if config.gallery_url.strip() else ""
                if canonical_url and canonical_url != config.gallery_url:
                    config.gallery_url = canonical_url
                    migrated = True
                if migrated:
                    self.save(config)
                return config
            except (ValueError, TypeError, OverflowError):
                raise ConfigSnapshotError(
                    'Configuration is invalid. Preserve the file and restore '
                    'a valid backup before starting uploads.'
                ) from None

    def save(self, config: WatcherConfig) -> None:
        """
        Persist configuration atomically.

        Preserve fields outside WatcherConfig and compare the complete prior
        snapshot before publishing. This does not exclude unrelated writers or
        establish directory durability after a power failure.

        Args:
            config (WatcherConfig): Configuration object to persist.

        Raises:
            OSError: Propagated when the file cannot be written.

        Returns:
            None: Persist configuration atomically.
        """
        with self._lock:
            if self._mode_transaction_active:
                raise ConfigSnapshotError('Configuration mode transaction is active.')
            fresh_owner = self._snapshot is None
            if self._snapshot is None:
                self.path.parent.mkdir(parents=True, exist_ok=True)
                self._snapshot = self._snapshot_store.inspect()
            if (config._snapshot_token is not self._revision
                    and not (fresh_owner and self._snapshot.state == 'missing'
                             and config._snapshot_token is None)):
                raise ConfigSnapshotError('Configuration selection is stale.')
            if fresh_owner and self._snapshot.state == 'missing':
                # Retain this attempt's generation if staging fails. A retry
                # still has to match the original missing snapshot on disk.
                config._snapshot_token = self._revision
            self._snapshot = self._snapshot_store.save(
                self._snapshot, config.to_dict(),
            )
            self._revision = object()
            config._snapshot_token = self._revision

    def mode_port(self, config: WatcherConfig) -> "_ConfigModePort":
        """Bind mode transactions to the current host-owned config object.

        Args:
            config (WatcherConfig): Exact live configuration selected by the host.

        Returns:
            _ConfigModePort: Port retaining the config object and its owner.
        """
        with self._lock:
            if (str(self.path) != str(self._snapshot_store.path)
                    or str(self.path) != str(Path(self._mode_raw_path))):
                raise ConfigSnapshotError('Configuration path changed.')
            return _ConfigModePort(self, config, str(self.path), self._mode_raw_path)


class _ConfigModePort:
    """Bind a mode transaction factory to one host-owned config generation."""

    def __init__(self, store: ConfigStore, config: WatcherConfig, path: str,
                 raw_path: str) -> None:
        """Retain the exact config object whose token may be refreshed.

        Args:
            store (ConfigStore): Existing owner for the complete config file.
            config (WatcherConfig): Host-owned active config object.
            path (str): Exact selected Path spelling captured by the store.
            raw_path (str): Original string supplied before Path conversion.

        Returns:
            None: Prepares a port without filesystem work.
        """
        self._store = store
        self._config = config
        self._path = path
        self._raw_path = raw_path

    def mode_transaction(self) -> "_ConfigModeTransaction":
        """Prepare one transaction sharing the original ConfigStore lock.

        Returns:
            _ConfigModeTransaction: Unentered transaction bound to this config.
        """
        return _ConfigModeTransaction(self._store, self._config, self._path, self._raw_path)


class _ConfigModeTransaction:
    """Hold one ConfigStore lock while its mode anchor is observed or updated."""

    def __init__(self, store: ConfigStore, config: WatcherConfig, path: str,
                 raw_path: str) -> None:
        """Bind an unentered mode transaction to the existing config owner.

        Args:
            store (ConfigStore): ConfigStore which owns the full document.
            config (WatcherConfig): Exact host-owned config selected for update.
            path (str): Exact Path spelling captured by the mode port.
            raw_path (str): Original spelling captured before Path conversion.

        Returns:
            None: Creates an unentered transaction without filesystem work.
        """
        self._store = store
        self._config = config
        self._path = path
        self._raw_path = raw_path
        self._thread_id: int | None = None

    def __enter__(self) -> "_ConfigModeTransaction":
        """Acquire the config lock for this transaction's calling thread.

        Returns:
            _ConfigModeTransaction: Entered transaction for the current thread.

        Raises:
            ConfigSnapshotError: The transaction is already entered.
        """
        if self._thread_id is not None:
            raise ConfigSnapshotError('Configuration transaction is unavailable.')
        self._store._lock.acquire()
        try:
            if (self._store._mode_transaction_active
                    or self._config._snapshot_token is not self._store._revision
                    or str(self._store.path) != self._path
                    or str(self._store._snapshot_store.path) != self._path
                    or self._store._mode_raw_path != self._raw_path
                    or str(Path(self._raw_path)) != self._path):
                raise ConfigSnapshotError('Configuration selection is stale.')
            self._assert_config_matches_snapshot()
            self._thread_id = threading.get_ident()
            self._store._mode_transaction_active = True
        except BaseException:
            self._store._lock.release()
            raise
        return self

    def _check_owner(self) -> None:
        """Require the same thread that acquired the config-owner lock.

        Returns:
            None: The transaction belongs to the current thread.

        Raises:
            ConfigSnapshotError: The transaction is inactive or belongs elsewhere.
        """
        if (self._thread_id is None or self._thread_id != threading.get_ident()
                or self._config._snapshot_token is not self._store._revision
                or str(self._store.path) != self._path
                or str(self._store._snapshot_store.path) != self._path
                or self._store._mode_raw_path != self._raw_path
                or str(Path(self._raw_path)) != self._path):
            raise ConfigSnapshotError('Configuration transaction is unavailable.')
        self._assert_config_matches_snapshot()

    def _assert_config_matches_snapshot(self) -> None:
        """Require current in-memory settings to match the owned config evidence.

        This comparison performs no migration write and never reports values.

        Returns:
            None: Known fields match the stored document or first-run defaults.

        Raises:
            ConfigSnapshotError: Known settings or snapshot cannot be verified.
        """
        snapshot = self._store._snapshot
        try:
            if snapshot is None:
                raise ValueError
            if snapshot.state == 'missing':
                expected = WatcherConfig()
            elif snapshot.state == 'valid':
                expected = WatcherConfig.from_dict(
                    self._store._snapshot_store.read(snapshot),
                )
            else:
                raise ValueError
            if self._config.to_dict() != expected.to_dict():
                raise ValueError
        except (ValueError, TypeError, OverflowError, OSError):
            raise ConfigSnapshotError('Configuration selection is stale.') from None

    @property
    def config_path(self) -> str:
        """Return the original selected path spelling for mode validation.

        Returns:
            str: Original string spelling, or the already-converted Path
                spelling for a Path caller; not filesystem identity proof.

        Raises:
            ConfigSnapshotError: This transaction is inactive or belongs elsewhere.
        """
        self._check_owner()
        return self._raw_path

    def snapshot(self) -> ConfigSnapshot:
        """Return the exact loaded and still-current complete config snapshot.

        Returns:
            ConfigSnapshot: Existing validated or positively missing config.

        Raises:
            ConfigSnapshotError: The config is changed or unavailable.
        """
        self._check_owner()
        snapshot = self._store._snapshot
        if (snapshot is None or snapshot.state not in ('valid', 'missing')
                or self._store._snapshot_store.inspect() != snapshot):
            raise ConfigSnapshotError('Configuration evidence changed.')
        self._check_owner()
        return snapshot

    def read_mode_anchor(self, snapshot: ConfigSnapshot) -> bytes | None:
        """Read the exact current anchor without treating invalid data as absent.

        Args:
            snapshot (ConfigSnapshot): Exact current snapshot from this owner.

        Returns:
            bytes | None: Canonical mode record, or proven field absence.

        Raises:
            ConfigSnapshotError: Evidence is stale, invalid or unavailable.
        """
        self._check_owner()
        if snapshot is not self._store._snapshot:
            raise ConfigSnapshotError('Configuration evidence changed.')
        payload = self._store._snapshot_store.read_mode_anchor(snapshot)
        self._check_owner()
        return payload

    def write_mode_anchor(self, snapshot: ConfigSnapshot, payload: bytes) -> ConfigSnapshot:
        """Publish a mode anchor and invalidate every older config generation.

        Args:
            snapshot (ConfigSnapshot): Exact current snapshot from this owner.
            payload (bytes): Canonical, validated upload-mode record.

        Returns:
            ConfigSnapshot: Verified new complete configuration observation.

        Raises:
            ConfigSnapshotError: Evidence or publication is unsafe or uncertain.
        """
        self._check_owner()
        if snapshot is not self._store._snapshot:
            raise ConfigSnapshotError('Configuration evidence changed.')
        updated = self._store._snapshot_store.save_mode_anchor(snapshot, payload)
        self._check_owner()
        self._store._snapshot = updated
        self._store._revision = object()
        self._config._snapshot_token = self._store._revision
        return updated

    def __exit__(
        self, exc_type: type[BaseException] | None,
        exc_value: BaseException | None, traceback: TracebackType | None,
    ) -> bool:
        """Release the owner-thread lock without suppressing caller failures.

        Args:
            exc_type (type[BaseException] | None): Raised exception class.
            exc_value (BaseException | None): Raised exception instance.
            traceback (TracebackType | None): Raised exception traceback.

        Returns:
            bool: False so caller failures remain visible.

        Raises:
            ConfigSnapshotError: The transaction belongs to another thread.
        """
        if self._thread_id is None or self._thread_id != threading.get_ident():
            raise ConfigSnapshotError('Configuration transaction is unavailable.')
        self._thread_id = None
        self._store._mode_transaction_active = False
        self._store._lock.release()
        return False


class UploadState:
    """
    Tracks successful uploads, duplicate content, and retry scheduling.

    State is deliberately local and conservative. The server remains the final
    authority, but the helper avoids repeatedly uploading the same file content
    when the user restarts the app or copies a duplicate image into the watched
    folder.
    """

    def __init__(self, path: Path = STATE_PATH) -> None:
        """
        Create and load the upload state store.

        Args:
            path (Path): Path to the JSON state file.

        Returns:
            None: Create and load the upload state store.
        """
        self.path = path
        self.data: Dict[str, Any] = {
            "schema_version": 2,
            "uploaded_paths": {},
            "uploaded_hashes": {},
            "failures": {},
            "jobs": {},
            "recent_events": [],
        }
        self.load()

    def load(self) -> None:
        """
        Load upload state from disk into memory.

        Malformed sections are not discarded during writer admission: a legacy
        state cache may be tolerant for display, but accepted upload history
        cannot be silently replaced after a damaged or concurrent write.
        The baseline is captured independently under the accepted-work owner.

        Returns:
            None: The verified persisted sections are loaded into memory.
        """
        with accepted_work_owner(self.path.parent):
            data = load_upload_state_for_mutation(self.path)
            sections = ("uploaded_paths", "uploaded_hashes", "failures")
            self._disk_baseline = {
                key: copy.deepcopy(data[key]) for key in sections
            }
        self.data["schema_version"] = 2
        for key in sections:
            self.data[key] = data[key]
        self.data["jobs"] = data["jobs"]
        self.data["recent_events"] = data["recent_events"]

    def save(self) -> None:
        """
        Persist upload state atomically.

        Raises:
            OSError: Propagated when the state file cannot be written.
            ValueError: Current watcher history differs from our baseline.
            RuntimeError: The resulting document could not be verified.

        Returns:
            None: Persist upload state atomically.
        """
        confirmed = merge_upload_sections(
            self.path, self.data, self._disk_baseline,
        )
        self._disk_baseline = {
            key: copy.deepcopy(confirmed[key])
            for key in ("uploaded_paths", "uploaded_hashes", "failures")
        }

    def already_uploaded_path(self, path: Path, file_hash: str) -> bool:
        """
        Check whether the exact path and content were already uploaded.

        Args:
            path (Path): Local image path being considered.
            file_hash (str): SHA-256 hash of the current file content.

        Returns:
            bool: True when this path already succeeded with the same content.
        """
        entry = self.data["uploaded_paths"].get(str(path))
        return isinstance(entry, dict) and entry.get("sha256") == file_hash

    def already_uploaded_hash(self, file_hash: str) -> bool:
        """
        Check whether identical content already uploaded under any file name.

        Args:
            file_hash (str): SHA-256 hash of the current file content.

        Returns:
            bool: True when this byte-identical file content is already known.
        """
        return file_hash in self.data["uploaded_hashes"]

    def mark_uploaded(self, path: Path, file_hash: str, size: int, response: Dict[str, Any]) -> None:
        """
        Record a successful upload after the server confirms it.

        Args:
            path (Path): Local file path that was uploaded.
            file_hash (str): SHA-256 hash captured at upload time.
            size (int): File size in bytes captured at upload time.
            response (Dict[str, Any]): JSON object returned by the PHP Gallery
                endpoint.

        Returns:
            None: Record a successful upload after the server confirms it.
        """
        now = time.time()
        record = {
            "sha256": file_hash,
            "size": size,
            "uploaded_at": now,
            "response": response,
        }
        self.data["uploaded_paths"][str(path)] = record
        self.data["uploaded_hashes"][file_hash] = {
            "first_path": str(path),
            "uploaded_at": now,
        }
        # A confirmed upload clears any retry metadata for the same path.
        self.data["failures"].pop(str(path), None)
        self.save()

    def mark_duplicate(self, path: Path, file_hash: str, size: int) -> None:
        """
        Record a skipped file whose content was already uploaded earlier.

        The path-level record prevents the same duplicate file from being
        repeatedly revisited during later scans.

        Args:
            path (Path): Local duplicate file path.
            file_hash (str): SHA-256 hash matching already uploaded content.
            size (int): File size in bytes.

        Returns:
            None: Record a skipped file whose content was already uploaded
                earlier.
        """
        self.data["uploaded_paths"][str(path)] = {
            "sha256": file_hash,
            "size": size,
            "uploaded_at": time.time(),
            "skipped_duplicate": True,
        }
        self.data["failures"].pop(str(path), None)
        self.save()

    def retry_allowed(self, path: Path, file_hash: str) -> bool:
        """
        Determine whether a failed upload may be attempted now.

        Retry delay is keyed by path and content hash. Replacing the file with
        different content immediately clears the wait because it is no longer
        the same failed upload attempt.

        Args:
            path (Path): Local file path being considered.
            file_hash (str): Current SHA-256 hash of the file.

        Returns:
            bool: True when no backoff delay is active.
        """
        failure = self.data["failures"].get(str(path))
        if not isinstance(failure, dict):
            return True
        if failure.get("sha256") != file_hash:
            return True
        return time.time() >= float(failure.get("next_retry_at", 0) or 0)

    def mark_failure(self, path: Path, file_hash: str, message: str) -> None:
        """
        Record a failed upload attempt and schedule a later retry.

        The retry delay uses exponential backoff capped at one hour. This avoids
        hammering the gallery endpoint while still recovering automatically from
        transient network, hosting, or maintenance failures.

        Args:
            path (Path): Local file path that failed to upload.
            file_hash (str): SHA-256 hash of the file at failure time.
            message (str): Human-readable failure reason.

        Returns:
            None: Record a failed upload attempt and schedule a later retry.
        """
        previous = self.data["failures"].get(str(path))
        previous_attempts = int(previous.get("attempts", 0)) if isinstance(previous, dict) and previous.get("sha256") == file_hash else 0
        attempts = previous_attempts + 1
        retry_delay = min(3600, 5 * (2 ** min(attempts - 1, 7)))
        now = time.time()
        self.data["failures"][str(path)] = {
            "sha256": file_hash,
            "attempts": attempts,
            "last_error": message,
            "last_failed_at": now,
            "next_retry_at": now + retry_delay,
        }
        self.save()


# Bound a target recovery file before parsing. Oversize is unknown history, not
# permission to reset the target or retry an upload.
LINKED_RECOVERY_STATE_MAX_BYTES = 16 * 1024 * 1024


def write_linked_upload_state(path: Path, payload: Dict[str, JsonValue]) -> None:
    """Atomically write a linked target's recovery state and require file fsync.

    Args:
        path (Path): Verified destination-owned JSON state file.
        payload (Dict[str, JsonValue]): Existing upload-state object containing only
            path/hash, failure, job and event collections without credentials.

    Returns:
        None: Publishes the complete file only after a successful file flush.

    Raises:
        OSError: Creation, writing, file synchronization or replacement fails.
    """
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + '.' + uuid.uuid4().hex + '.tmp')
    descriptor: Optional[int] = None
    try:
        flags = (os.O_WRONLY | os.O_CREAT | os.O_EXCL
                 | getattr(os, 'O_BINARY', 0) | getattr(os, 'O_NOFOLLOW', 0))
        descriptor = os.open(temporary, flags, 0o600)
        with os.fdopen(descriptor, 'w', encoding='utf-8', newline='\n') as handle:
            descriptor = None
            json.dump(payload, handle, indent=2, ensure_ascii=False)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        if descriptor is not None:
            os.close(descriptor)
        try:
            temporary.unlink()
        except FileNotFoundError:
            pass


class LinkedUploadState(UploadState):
    """Keep linked-gallery retry state separate from all legacy galleries."""

    def __init__(self, target: LinkedUploadTarget) -> None:
        """Bind local retry state to one exact installation, account and gallery.

        Args:
            target (LinkedUploadTarget): Captured in-memory delegated destination.

        Returns:
            None: Loads only the independently scoped, non-secret state file.
        """
        self._binding = target.binding()
        state_path = linked_upload_state_path(CONFIG_DIR, self._binding)
        # Determine existence during the owner-protected load, not from a
        # separate pre-lock path observation that could already be stale.
        self.preexisting = False
        self._history_created = False
        self._disk_baseline: Optional[Dict[str, JsonValue]] = None
        super().__init__(state_path)

    def _safe_existing_document(self) -> Optional[Dict[str, JsonValue]]:
        """Read a linked state document without repairing ambiguous history.

        Returns:
            Optional[Dict[str, JsonValue]]: Schema version, uploaded path and
                hash maps, failure rows, jobs and recent events, or None before
                any scoped history exists. Reviewed failure rows retain the
                uncertain flag and SHA-256.

        Raises:
            ValueError: Stored history is unreadable or lacks required sections.
        """
        self.path = linked_upload_state_path(CONFIG_DIR, self._binding)
        try:
            before = self.path.lstat()
        except FileNotFoundError:
            if self._history_created:
                raise ValueError('Linked upload history disappeared; review before recovery.')
            return None
        except OSError as exc:
            raise ValueError('Linked upload state requires recovery.') from exc
        if (not stat.S_ISREG(before.st_mode) or before.st_nlink != 1
                or before.st_dev <= 0 or before.st_ino <= 0
                or before.st_size > LINKED_RECOVERY_STATE_MAX_BYTES):
            raise ValueError('Linked upload state requires recovery.')
        flags = (os.O_RDONLY | getattr(os, 'O_BINARY', 0)
                 | getattr(os, 'O_NOFOLLOW', 0)
                 | getattr(os, 'O_NONBLOCK', 0))
        try:
            with os.fdopen(os.open(self.path, flags), 'rb') as source:
                opened = os.fstat(source.fileno())
                raw = source.read(LINKED_RECOVERY_STATE_MAX_BYTES + 1)
                finished = os.fstat(source.fileno())
            after = self.path.lstat()
            snapshots = (before, opened, finished, after)
            identities = {
                (entry.st_dev, entry.st_ino, entry.st_size, entry.st_mtime_ns)
                for entry in snapshots
            }
            if (len(raw) > LINKED_RECOVERY_STATE_MAX_BYTES
                    or len(raw) != opened.st_size or len(identities) != 1
                    or any(not stat.S_ISREG(entry.st_mode) or entry.st_nlink != 1
                           for entry in snapshots)):
                raise ValueError('Linked upload history changed during read.')
            data = json.loads(
                raw.decode('utf-8'),
                object_pairs_hook=_strict_mode_history_object,
                parse_constant=_reject_mode_history_constant,
                parse_float=_strict_mode_history_float,
            )
        except (OSError, UnicodeError, ValueError, TypeError, OverflowError,
                RecursionError) as exc:
            raise ValueError('Linked upload state requires recovery.') from exc
        if (not isinstance(data, dict) or data.get('schema_version') != 2
                or any(not isinstance(data.get(key), dict) for key in (
                    'uploaded_paths', 'uploaded_hashes', 'failures', 'jobs',
                )) or not isinstance(data.get('recent_events'), list)):
            raise ValueError('Linked upload state requires recovery.')
        for value in data['failures'].values():
            if (not isinstance(value, dict)
                    or ('uncertain' in value and type(value['uncertain']) is not bool)
                    or ('reviewed' in value and (
                        value['reviewed'] is not True
                        or value.get('uncertain') is not True
                    ))
                    or (value.get('uncertain') is True and (
                        not isinstance(value.get('sha256'), str)
                        or re.fullmatch(r'[a-f0-9]{64}', value['sha256']) is None))):
                raise ValueError('Linked upload recovery marker is malformed.')
        return data

    def load(self) -> None:
        """Revalidate the scoped state path before reading its local history.

        Returns:
            None: Loads only state belonging to this linked destination.

        Raises:
            ValueError: The scoped state path is unsafe or redirected.
        """
        with accepted_work_owner(CONFIG_DIR):
            data = self._safe_existing_document()
            self.preexisting = data is not None
            if data is not None:
                self._history_created = True
                self.data.update(data)
            # Keep an independent snapshot so in-memory transitions cannot
            # change the state we subsequently compare against fresh disk.
            self._disk_baseline = copy.deepcopy(data)

    def save(self) -> None:
        """Publish a linked target state only from its last owned disk snapshot.

        The shared accepted-work owner includes this fresh read, full-file
        publication and readback. A stale instance must not erase an uncertain
        HTTP outcome or another process's confirmed result. This cannot attest
        to history lost before construction or collectively rolled back files.

        Returns:
            None: Persists unchanged target provenance and new retry metadata.

        Raises:
            ValueError: Scoped history disappeared or changed since our load.
            RuntimeError: Published linked history could not be read back.
            OSError: The state file cannot be written.
        """
        with accepted_work_owner(CONFIG_DIR):
            current = self._safe_existing_document()
            if current != self._disk_baseline:
                raise ValueError(
                    'Linked upload recovery history changed; review before retry.'
                )
            write_linked_upload_state(self.path, self.data)
            self._history_created = True
            confirmed = self._safe_existing_document()
            if confirmed != self.data:
                raise RuntimeError('Published linked recovery state is unconfirmed.')
            self._disk_baseline = copy.deepcopy(confirmed)

    def has_uncertain_hash(self, file_hash: str) -> bool:
        """Check for any unresolved transfer of this content to this gallery.

        Args:
            file_hash (str): Lowercase SHA-256 of a proposed local source.

        Returns:
            bool: True when any recorded path has an ambiguous matching upload.
        """
        return any(
            isinstance(value, dict) and value.get('uncertain') is True
            and value.get('sha256') == file_hash
            for value in self.data['failures'].values()
        )

    def has_unresolved_upload(self) -> bool:
        """Block other transfers until the operator reviews the unknown result.

        Returns:
            bool: True when an uncertain marker lacks explicit review. A
                reviewed original remains uncertain and excluded separately.
        """
        return any(
            isinstance(value, dict) and value.get('uncertain') is True
            and value.get('reviewed') is not True
            for value in self.data['failures'].values()
        )

    def recovery_marker(self) -> tuple[str, str]:
        """Identify the unreviewed marker without opening its recorded path.

        Returns:
            tuple[str, str]: Its validated SHA-256 and local identity digest.

        Raises:
            ValueError: The journal is missing, malformed or ambiguous.
        """
        with accepted_work_owner(CONFIG_DIR):
            data = self._safe_existing_document()
            if data is None:
                raise ValueError('Linked upload recovery marker is unavailable.')
            uncertain = [(path, entry) for path, entry in data['failures'].items()
                         if entry.get('uncertain') is True
                         and entry.get('reviewed') is not True]
            if len(uncertain) != 1:
                raise ValueError('Linked upload recovery marker is unavailable.')
            path, entry = uncertain[0]
            encoded = json.dumps([path, entry], ensure_ascii=True, sort_keys=True,
                                 separators=(',', ':')).encode('ascii')
            return entry['sha256'], hashlib.sha256(encoded).hexdigest()

    def review_uncertainty(self, marker: str) -> None:
        """Record review of one exact marker while retaining its unknown result.

        The caller holds the same gallery lease as Watcher and Import. Review
        permits other originals only; the path and matching content stay
        excluded even across restart. No receipt or retry permission is made.

        Args:
            marker (str): Digest of the unreviewed marker the operator inspected.

        Returns:
            None: Publishes review while preserving the original failure row.

        Raises:
            ValueError: The marker changed or the current history is unsafe.
            OSError: The scoped journal could not be written.
            RuntimeError: Publication could not be confirmed by readback.
        """
        if self.recovery_marker()[1] != marker:
            raise ValueError('The uncertain upload changed during review.')
        pending = [entry for entry in self.data['failures'].values()
                   if entry.get('uncertain') is True
                   and entry.get('reviewed') is not True]
        if len(pending) != 1:
            raise ValueError('The uncertain upload needs manual review.')
        pending[0]['reviewed'] = True
        # save() rereads and compares the complete owned baseline under the
        # publication lock; a newer journal is not replaced by this review.
        self.save()

    def has_uncertain_source(self, path: Path, file_hash: str) -> bool:
        """Exclude retained unknown paths and the same bytes at other paths.

        Args:
            path (Path): Local source being considered for dispatch.
            file_hash (str): Lowercase SHA-256 of its current bytes.

        Returns:
            bool: True for any matching uncertain history, reviewed or not.
        """
        failure = self.data['failures'].get(str(path))
        return ((isinstance(failure, dict) and failure.get('uncertain') is True)
                or self.has_uncertain_hash(file_hash))

    def retry_allowed(self, path: Path, file_hash: str) -> bool:
        """Refuse automatic retry of a transfer with an uncertain result.

        Args:
            path (Path): Local source path being considered.
            file_hash (str): Current lowercase SHA-256 of the source file.

        Returns:
            bool: False for the same ambiguous transfer; otherwise the existing
                bounded retry policy applies.
        """
        if self.has_uncertain_source(path, file_hash):
            return False
        return super().retry_allowed(path, file_hash)

    def mark_uncertain(self, path: Path, file_hash: str) -> None:
        """Persist an ambiguous transfer without scheduling an automatic retry.

        Args:
            path (Path): Source retained for explicit human review.
            file_hash (str): Lowercase SHA-256 of the attempted source.

        Returns:
            None: Preserves an unresolved marker scoped to this exact gallery.

        Raises:
            OSError: The marker cannot be persisted.
            ValueError: The journal is unsafe, or this source or another
                unreviewed marker already owns the transfer boundary.
            RuntimeError: Publication could not be confirmed by readback.
        """
        existing = self.data['failures'].get(str(path))
        if (self.has_unresolved_upload()
                or self.has_uncertain_source(path, file_hash)) and not (
            isinstance(existing, dict) and existing.get('uncertain') is True
            and existing.get('sha256') == file_hash
            and existing.get('reviewed') is not True
        ):
            raise ValueError('Linked gallery has an unresolved upload; review it before continuing.')
        self.data['failures'][str(path)] = {
            'sha256': file_hash,
            'uncertain': True,
            'last_error': 'Upload result uncertain; review the gallery manually.',
            'last_failed_at': time.time(),
        }
        self.save()

    def mark_uploaded(
        self, path: Path, file_hash: str, size: int, response: Dict[str, Any],
    ) -> None:
        """Clear only the marker for the exact acknowledged transfer.

        Args:
            path (Path): Local path used for the acknowledged upload.
            file_hash (str): SHA-256 of the exact acknowledged bytes.
            size (int): Source size in bytes.
            response (Dict[str, Any]): Validated acknowledgement with success,
                gallery ID, uploaded and scanned counts, fresh insertion
                evidence, image IDs and filenames, and optional warnings or
                thumbnail results.

        Returns:
            None: Saves the acknowledgement and clears its own marker.

        Raises:
            ValueError: The marker is absent, reviewed or belongs to other bytes.
            OSError: The scoped state could not be persisted.
            RuntimeError: Publication could not be confirmed by readback.
        """
        pending = self.data['failures'].get(str(path))
        if (not isinstance(pending, dict) or pending.get('uncertain') is not True
                or pending.get('sha256') != file_hash
                or pending.get('reviewed') is True):
            raise ValueError('Linked upload acknowledgement does not match its marker.')
        super().mark_uploaded(path, file_hash, size, response)


class FileStabilityTracker:
    """
    Detects when copied files have stopped changing.

    Files copied into a watched folder are often visible before the copy is
    complete. Uploading such a file would produce truncated images or server-side
    errors. This tracker waits until size and modification time remain unchanged
    for the configured duration.
    """

    def __init__(self, stable_seconds: float) -> None:
        """
        Create a stability tracker.

        Args:
            stable_seconds (float): Required unchanged duration before a file
                is.

        Returns:
            None: Create a stability tracker.
        """
        self.stable_seconds = max(0.5, stable_seconds)
        self._seen: Dict[Path, Tuple[int, float, float]] = {}

    def stable(self, path: Path) -> bool:
        """
        Check whether a path has remained unchanged long enough.

        Args:
            path (Path): File path to inspect.

        Returns:
            bool: True when size and modification time have been stable for
                the.
        """
        try:
            stat = path.stat()
        except OSError:
            self._seen.pop(path, None)
            return False

        size = int(stat.st_size)
        mtime = float(stat.st_mtime)
        now = time.time()
        previous = self._seen.get(path)

        # First sighting, or a changed size/mtime, restarts the stability timer.
        if previous is None or previous[0] != size or previous[1] != mtime:
            self._seen[path] = (size, mtime, now)
            return False

        return now - previous[2] >= self.stable_seconds


def setup_logging() -> None:
    """
    Configure file logging for watcher diagnostics.
    
    The GUI shows recent status in the window, but the file log survives restarts
    and is more useful when diagnosing upload or hosting issues later.
    """
    CONFIG_DIR.mkdir(parents=True, exist_ok=True)
    root_logger = logging.getLogger()
    if root_logger.handlers:
        return
    root_logger.setLevel(logging.INFO)
    handler = RotatingFileHandler(str(LOG_PATH), maxBytes=2 * 1024 * 1024, backupCount=3, encoding="utf-8")
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
    root_logger.addHandler(handler)


def upload_endpoint_candidates(value: str) -> List[str]:
    """
    Resolve a user-supplied Gallery URL into ordered upload endpoint candidates.

    The query-string front-controller endpoint is always preferred, even when a
    user pastes the optional /api/upload alias. Real shared-hosting deployments
    can route clean URLs for ordinary GET pages while proxies/WAF rules still
    treat POST requests under /api/ differently. The query-string endpoint is
    therefore the portable canonical form. The clean route remains a bounded
    fallback only when the canonical route is rejected by the hosting layer with
    a non-JSON HTTP 404 before PHP Gallery is reached.

    Args:
        value (str): User-provided Gallery root or upload endpoint URL.

    Returns:
        List[str]: Ordered unique HTTP(S) endpoint candidates.
    """
    raw = value.strip()
    if not raw:
        return []

    parsed = parse.urlparse(raw)
    if not parsed.scheme:
        raw = "https://" + raw
        parsed = parse.urlparse(raw)
    if parsed.scheme.lower() not in {"http", "https"} or not parsed.netloc:
        return []

    parsed = parsed._replace(fragment="")
    path = parsed.path or ""
    stripped_path = path.rstrip("/")
    query = parse.parse_qs(parsed.query)
    page = query.get("page", [""])[0]

    if stripped_path.endswith("/api/upload"):
        base_path = stripped_path[:-len("/api/upload")]
    elif stripped_path.endswith("/index.php") or stripped_path == "/index.php":
        base_path = stripped_path[:-len("/index.php")] if stripped_path != "/index.php" else ""
    else:
        base_path = stripped_path

    base_path = base_path.rstrip("/")
    clean_path = (base_path + "/api/upload") if base_path else "/api/upload"
    query_path = (base_path + "/index.php") if base_path else "/index.php"

    clean_url = parse.urlunparse(parsed._replace(path=clean_path, query="", fragment=""))
    query_url = parse.urlunparse(parsed._replace(path=query_path, query="page=upload_automation_upload", fragment=""))

    # Preserve an already-canonical URL exactly apart from the discarded fragment.
    if (stripped_path.endswith("/index.php") or stripped_path == "/index.php") and page == "upload_automation_upload":
        query_url = parse.urlunparse(parsed)

    ordered = [query_url, clean_url]
    unique: List[str] = []
    seen: Set[str] = set()
    for candidate in ordered:
        if candidate not in seen:
            seen.add(candidate)
            unique.append(candidate)
    return unique


def normalize_upload_url(value: str) -> str:
    """
    Convert a site root or endpoint-like value into the preferred upload URL.

    All accepted inputs, including the optional /api/upload alias, normalize to
    the portable query-string front-controller endpoint. The clean route remains
    available only as a routing-level 404 fallback through
    upload_endpoint_candidates().

    Args:
        value (str): User-provided site URL or upload endpoint.

    Returns:
        str: Preferred upload endpoint URL, or an empty string for invalid
            input.
    """
    candidates = upload_endpoint_candidates(value)
    return candidates[0] if candidates else ""


# Purpose: Bound the existing ambiguous gateway revocation retry delay.
# Type: float. Units: seconds. Scope: one captured gallery-key revoke request.
# Consumers: revoke_upload_key. Rationale: 2 seconds spaces its sole retry
# without extending retry count or changing the server reconciliation policy.
KEY_REVOCATION_RETRY_DELAY_SECONDS = 2.0


def revoke_upload_key(
    upload_url: str, api_key: str, *,
    before_request: Optional[Callable[[], None]] = None,
) -> Dict[str, JsonValue]:
    """
    Revoke the active upload automation key through the authenticated API.

    Revocation uses the same JSON request path and endpoint fallback rules as
    inventory and other metadata commands. The server identifies the exact token
    from X-Gallery-API-Key, so no admin session or token database id is required.

    A gateway 502/503/504 is treated as ambiguous because the reverse proxy may
    have lost the PHP response after the token was already revoked. One delayed
    retry is allowed. If that retry reports the key as invalid/revoked, the desired
    server state has been reached and the operation is considered successful. The
    retry is intentionally bounded to avoid amplifying an overloaded server.

    Args:
        upload_url (str): PHP Gallery upload endpoint or Gallery root URL.
        api_key (str): Current gallery-scoped API key.
        before_request (Optional[Callable[[], None]]): Captured work checkpoint
            before every route/redirect/retry attempt, or None for direct calls.

    Returns:
        Dict[str, JsonValue]: Positive ok/action=revoke response, optional message
            string and reconciled_after_gateway_error boolean.

    Raises:
        RuntimeError: Raised for network, HTTP, non-JSON, or server failures.
    """
    options = {'before_request': before_request} if before_request is not None else {}
    try:
        return post_json(upload_url, api_key, {"action": "revoke"}, **options)
    except RuntimeError as exc:
        first_message = str(exc)
        if not any(marker in first_message for marker in ("HTTP 502:", "HTTP 503:", "HTTP 504:")):
            raise
        logging.warning("API-key revocation received a transient gateway error; retrying once after a short delay.")
        time.sleep(KEY_REVOCATION_RETRY_DELAY_SECONDS)
        try:
            return post_json(upload_url, api_key, {"action": "revoke"}, **options)
        except RuntimeError as retry_exc:
            retry_message = str(retry_exc).lower()
            if "invalid or revoked api key" in retry_message:
                return {
                    "ok": True,
                    "action": "revoke",
                    "message": "API key is revoked.",
                    "reconciled_after_gateway_error": True,
                }
            raise RuntimeError(
                "Gallery server is temporarily unavailable while revoking the API key. "
                "The key was kept locally because server revocation could not be confirmed. "
                f"Original error: {first_message}; retry error: {retry_exc}"
            ) from retry_exc


class GalleryKeyRedirectError(RuntimeError):
    """Refuse a redirect that could disclose the legacy gallery API key."""


class _GalleryKeyRedirectHandler(request.HTTPRedirectHandler):
    """Keep gallery credentials on the same host and a safe transport."""

    def __init__(self, checkpoint: Optional[Callable[[], None]] = None,
                 allow_redirects: bool = True) -> None:
        """Retain the optional request checkpoint for redirect attempts.

        Args:
            checkpoint (Optional[Callable[[], None]]): Revalidates admitted
                once-mode work or a manual batch, or None for other callers.
            allow_redirects (bool): Whether safe redirects may be followed.

        Returns:
            None: Initializes the redirect handler.
        """
        super().__init__()
        self._checkpoint = checkpoint
        self._allow_redirects = allow_redirects

    def redirect_request(
        self, req: request.Request, fp: IO[bytes], code: int, msg: str,
        headers: email.message.Message, newurl: str,
    ) -> Optional[request.Request]:
        """Restrict the destination and recheck any admitted once-mode work.

        Args:
            req (request.Request): Original urllib request.
            fp (IO[bytes]): Original HTTP response stream.
            code (int): HTTP redirect status.
            msg (str): HTTP redirect reason.
            headers (email.message.Message): Response headers.
            newurl (str): Destination selected by urllib.

        Returns:
            Optional[request.Request]: Redirect request, or None when urllib
                declines to follow it.

        Raises:
            ModeCoordinatorError: The admitted evidence is no longer valid.
            GalleryKeyRedirectError: The redirect changes host, weakens TLS,
                changes an unapproved port, or has ambiguous URL authority.
        """
        if not self._allow_redirects:
            raise GalleryKeyRedirectError("Gallery upload redirect was refused.")
        try:
            source = parse.urlsplit(req.full_url)
            target = parse.urlsplit(newurl)
            source_scheme, target_scheme = source.scheme.lower(), target.scheme.lower()
            source_port = source.port if source.port is not None else (
                80 if source_scheme == 'http' else 443
            )
            target_port = target.port if target.port is not None else (
                80 if target_scheme == 'http' else 443
            )
            safe = (
                source_scheme in ('http', 'https')
                and target_scheme in ('http', 'https')
                and source.hostname is not None and source.hostname == target.hostname
                and source.username is None and source.password is None
                and target.username is None and target.password is None
                and not (source_scheme == 'https' and target_scheme != 'https')
                and (source_port == target_port or (
                    source_scheme == 'http' and target_scheme == 'https'
                    and source_port == 80 and target_port == 443
                ))
            )
        except ValueError:
            safe = False
        if not safe:
            raise GalleryKeyRedirectError('Gallery API redirect was refused.')
        if self._checkpoint is not None:
            self._checkpoint()
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def _gallery_key_open(
    http_request: request.Request, timeout: float,
    before_request: Optional[Callable[[], None]] = None,
    allow_redirects: bool = True,
) -> http.client.HTTPResponse:
    """Open one legacy gallery request with guarded credential redirects.

    Args:
        http_request (request.Request): Existing request carrying a gallery key.
        timeout (float): Network socket timeout in seconds.
        before_request (Optional[Callable[[], None]]): Once-mode or manual
            batch checkpoint, or None for other callers.
        allow_redirects (bool): Whether safe redirects may be followed.

    Returns:
        http.client.HTTPResponse: Successful response owned by the caller.

    Raises:
        GalleryKeyRedirectError: An unsafe redirect is refused.
        ModeCoordinatorError: Once-mode evidence cannot authorize an attempt.
        OSError: The HTTP transport could not complete.
    """
    opener = request.build_opener(
        _GalleryKeyRedirectHandler(before_request, allow_redirects),
    )
    if before_request is not None:
        before_request()
    return opener.open(http_request, timeout=timeout)


def post_json_resolved(
    upload_url: str,
    api_key: str,
    payload: Dict[str, Any],
    timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
    before_request: Optional[Callable[[], None]] = None,
) -> Tuple[Dict[str, Any], str]:
    """
    Submit one JSON API request and report the endpoint that actually succeeded.

    Only a non-JSON HTTP 404 is treated as a front-controller routing failure and
    retried against the alternate clean/query-string endpoint. Gallery-generated
    JSON errors, including 404 gallery-not-found and 401/403 authentication
    failures, are authoritative and are never hidden behind fallback retries.

    Args:
        upload_url (str): PHP Gallery root or upload endpoint.
        api_key (str): Gallery-scoped API key sent as X-Gallery-API-Key.
        payload (Dict[str, Any]): Request object with action and its JSON
            scalar, object or array fields for the selected automation action.
        timeout_seconds (float): Network timeout for this metadata request.
        before_request (Optional[Callable[[], None]]): Once-mode or manual
            batch checkpoint before each endpoint and redirect, or None.

    Returns:
        Tuple[Dict[str, Any], str]: Response object containing ok and
            action-specific JSON fields, and the successful endpoint URL.

    Raises:
        RuntimeError: Raised for network, HTTP, non-JSON, or gallery errors.
        ModeCoordinatorError: The once-mode evidence changed before an attempt.
        GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
    """
    body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    candidates = upload_endpoint_candidates(upload_url)
    if not candidates:
        raise RuntimeError("Gallery URL or upload endpoint is invalid.")

    for index, endpoint in enumerate(candidates):
        http_request = request.Request(
            endpoint,
            data=body,
            headers={
                "Content-Type": "application/json; charset=utf-8",
                "Content-Length": str(len(body)),
                "X-Gallery-API-Key": api_key,
                "Accept": "application/json",
                "User-Agent": "PHPGalleryUploader/1.4",
            },
            method="POST",
        )

        try:
            request_options = (
                {'before_request': before_request} if before_request is not None else {}
            )
            with _gallery_key_open(
                http_request, max(5.0, float(timeout_seconds)), **request_options,
            ) as response:
                response_body = response.read().decode("utf-8", errors="replace")
        except error.HTTPError as exc:
            response_body = exc.read().decode("utf-8", errors="replace")
            try:
                error_payload = json.loads(response_body)
                message = error_payload.get("error") if isinstance(error_payload, dict) else None
                json_error = isinstance(error_payload, dict)
            except json.JSONDecodeError:
                message = None
                json_error = False

            has_fallback = index + 1 < len(candidates)
            if exc.code == 404 and not json_error and has_fallback:
                logging.warning("Upload endpoint route returned HTTP 404; retrying alternate Gallery API route.")
                continue
            raise RuntimeError(str(message or f"HTTP {exc.code}: {response_body[:300]}")) from exc
        except error.URLError as exc:
            raise RuntimeError(str(exc.reason)) from exc

        try:
            response_payload = json.loads(response_body)
        except json.JSONDecodeError as exc:
            raise RuntimeError(f"Server returned non-JSON response: {response_body[:300]}") from exc
        if not isinstance(response_payload, dict):
            raise RuntimeError("Server returned an invalid JSON response.")
        if not response_payload.get("ok"):
            raise RuntimeError(str(response_payload.get("error") or "API request failed."))
        return response_payload, endpoint

    raise RuntimeError("No usable PHP Gallery upload endpoint was found.")


def post_json(
    upload_url: str, api_key: str, payload: Dict[str, Any],
    timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
    before_request: Optional[Callable[[], None]] = None,
) -> Dict[str, Any]:
    """
    Submit one JSON API request and parse the JSON response.

    The companion app uses JSON only for metadata handshakes. Image bytes still
    use multipart/form-data because PHP Gallery routes them through the normal
    upload pipeline. Routing-only HTTP 404 responses may use the alternate
    clean/query-string endpoint, while authenticated gallery errors remain final.

    Args:
        upload_url (str): PHP Gallery root or upload endpoint.
        api_key (str): Gallery-scoped API key sent as X-Gallery-API-Key.
        payload (Dict[str, Any]): Action and its JSON scalar, object or array
            fields for the selected automation request.
        timeout_seconds (float): Network timeout for this metadata request.
        before_request (Optional[Callable[[], None]]): Once-mode or manual
            batch checkpoint before each endpoint and redirect, or None.

    Returns:
        Dict[str, Any]: Response object with ok and action-specific JSON fields.

    Raises:
        RuntimeError: Raised for network, HTTP, non-JSON, or gallery errors.
        ModeCoordinatorError: The once-mode evidence changed before an attempt.
        GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
    """
    if before_request is None:
        response_payload, _resolved_url = post_json_resolved(upload_url, api_key, payload, timeout_seconds)
    else:
        response_payload, _resolved_url = post_json_resolved(
            upload_url, api_key, payload, timeout_seconds, before_request,
        )
    return response_payload

def post_binary_to_file(
    upload_url: str,
    api_key: str,
    fields: Dict[str, str],
    target_path: Path,
    timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
    before_request: Optional[Callable[[], None]] = None,
) -> Tuple[str, int]:
    """
    Submit one form-encoded request and stream the binary response to a file.

    AI-analysis workers use this helper to download only the server-assigned
    image asset for a claimed job. The API key and claim token remain required;
    the public gallery URL is not used, so private or unpublished images do not
    need to be exposed to anonymous visitors for processing.

    Args:
        upload_url (str): Normalized PHP Gallery upload endpoint.
        api_key (str): Gallery-scoped API key sent as X-Gallery-API-Key.
        fields (Dict[str, str]): Form fields accepted by the upload automation
            endpoint.
        target_path (Path): Local path where the downloaded asset should be
            stored.
        timeout_seconds (float): Network timeout for the download request.
        before_request (Optional[Callable[[], None]]): Optional work checkpoint
            before each endpoint and redirect attempt.

    Returns:
        Tuple[str, int]: Tuple of response content type and downloaded byte
            count.

    Raises:
        RuntimeError: Raised for network or HTTP failures.
        GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
    """
    body = parse.urlencode(fields).encode("utf-8")
    candidates = upload_endpoint_candidates(upload_url)
    if not candidates:
        raise RuntimeError("Gallery URL or upload endpoint is invalid.")

    for index, endpoint in enumerate(candidates):
        http_request = request.Request(
            endpoint,
            data=body,
            headers={
                "Content-Type": "application/x-www-form-urlencoded",
                "Content-Length": str(len(body)),
                "X-Gallery-API-Key": api_key,
                "Accept": "application/octet-stream, application/json;q=0.8",
                "User-Agent": "PHPGalleryUploader/1.4",
            },
            method="POST",
        )

        try:
            with _gallery_key_open(
                http_request, max(5.0, float(timeout_seconds)),
                **({'before_request': before_request} if before_request is not None else {}),
            ) as response:
                content_type = str(response.headers.get("Content-Type") or "application/octet-stream")
                downloaded = 0
                with target_path.open("wb") as handle:
                    while True:
                        chunk = response.read(1024 * 256)
                        if not chunk:
                            break
                        handle.write(chunk)
                        downloaded += len(chunk)
                return content_type, downloaded
        except error.HTTPError as exc:
            response_body = exc.read().decode("utf-8", errors="replace")
            try:
                error_payload = json.loads(response_body)
                message = error_payload.get("error") if isinstance(error_payload, dict) else None
                json_error = isinstance(error_payload, dict)
            except json.JSONDecodeError:
                message = None
                json_error = False
            has_fallback = index + 1 < len(candidates)
            if exc.code == 404 and not json_error and has_fallback:
                logging.warning("Upload endpoint route returned HTTP 404 during binary API request; retrying alternate Gallery API route.")
                continue
            raise RuntimeError(str(message or f"HTTP {exc.code}: {response_body[:300]}")) from exc
        except error.URLError as exc:
            raise RuntimeError(str(exc.reason)) from exc

    raise RuntimeError("No usable PHP Gallery upload endpoint was found.")


def extension_for_content_type(content_type: str, fallback_name: str) -> str:
    """
    Return a safe filename suffix for a downloaded AI job asset.

    Args:
        content_type (str): Response Content-Type header.
        fallback_name (str): Original filename from the claimed job payload.

    Returns:
        str: File extension including the leading dot.
    """
    fallback_suffix = Path(fallback_name).suffix.lower()
    if fallback_suffix in SUPPORTED_SUFFIXES:
        return fallback_suffix
    mime = content_type.split(";", 1)[0].strip().lower()
    guessed = mimetypes.guess_extension(mime) or ""
    if guessed.lower() in {".jpe"}:
        return ".jpg"
    return guessed if guessed else ".img"


def ai_worker_id() -> str:
    """
    Return a stable local worker id for server-side lease diagnostics.

    The value is not a secret and is not used for authorization. It lets the
    server show which companion app instance currently owns a claim.

    Returns:
        str: Stable worker identifier for this Windows profile and machine.
    """
    CONFIG_DIR.mkdir(parents=True, exist_ok=True)
    worker_path = CONFIG_DIR / "worker_id.txt"
    try:
        if worker_path.is_file():
            existing = worker_path.read_text(encoding="utf-8").strip()
            if existing:
                return existing
        generated = f"{os.environ.get('COMPUTERNAME', 'windows').strip() or 'windows'}:{uuid.uuid4().hex}"
        worker_path.write_text(generated, encoding="utf-8")
        return generated
    except OSError:
        return f"temporary:{uuid.uuid4().hex}"


def enter_background_thread_mode() -> None:
    """
    Ask Windows to schedule the current thread as background work.
    
    This keeps optional image analysis from competing aggressively with the tray
    UI and ordinary uploads. Unsupported platforms silently continue because the
    worker can still run correctly without this scheduling hint.
    """
    if os.name != "nt":
        return
    try:
        kernel32 = ctypes.windll.kernel32  # type: ignore[attr-defined]
        thread_handle = kernel32.GetCurrentThread()
        thread_mode_background_begin = 0x00010000
        if kernel32.SetThreadPriority(thread_handle, thread_mode_background_begin):
            return
        thread_priority_below_normal = -1
        kernel32.SetThreadPriority(thread_handle, thread_priority_below_normal)
    except Exception:  # noqa: BLE001
        logging.debug("Could not set background thread priority.", exc_info=True)


def upload_failure_needs_inventory_reconciliation(
    error_value: Exception | str,
) -> bool:
    """
    Return whether a failed upload response is ambiguous enough to justify one inventory probe.

    Gateway failures, timeouts, and dropped connections can happen after PHP has
    already committed the upload. Authentication and other explicit HTTP client
    errors are authoritative failures and must not trigger an immediate second
    API request, especially while a shared host is already under pressure.

    Args:
        error_value (Exception | str): Exception or error text from the
            multipart request; only its string message is inspected.

    Returns:
        bool: True when one bounded remote-inventory reconciliation is useful.
    """
    message = str(error_value).strip().lower()
    if not message:
        return False

    definitive_markers = (
        "http 400:",
        "http 401:",
        "http 403:",
        "http 404:",
        "http 405:",
        "http 409:",
        "http 413:",
        "http 415:",
        "http 422:",
        "invalid api key",
        "revoked api key",
        "gallery assigned to this api key no longer exists",
    )
    if any(marker in message for marker in definitive_markers):
        return False

    ambiguous_markers = (
        "http 500:",
        "http 502:",
        "http 503:",
        "http 504:",
        "timed out",
        "timeout",
        "connection reset",
        "connection aborted",
        "remote end closed",
        "incomplete response",
        "broken pipe",
        "temporarily unavailable",
    )
    return any(marker in message for marker in ambiguous_markers)


def inventory_candidate(path: Path, file_hash: Optional[str] = None, size: Optional[int] = None) -> Dict[str, Any]:
    """
    Build one file descriptor for a remote gallery inventory request.

    Args:
        path (Path): Local image path being compared with the target gallery.
        file_hash (Optional[str]): Optional precomputed SHA-256 hash. When
            omitted, the file is hashed now.
        size (Optional[int]): Optional precomputed file size. When omitted,
            stat() is used now.

    Returns:
        Dict[str, Any]: JSON-safe file descriptor accepted by PHP Gallery.

    Raises:
        OSError: Propagated when the file cannot be read or statted.
    """
    resolved_hash = file_hash or sha256_file(path)
    resolved_size = int(size if size is not None else path.stat().st_size)
    return {
        "client_id": resolved_hash,
        "filename": path.name,
        "size": resolved_size,
        "sha256": resolved_hash,
    }


class RemoteInventorySession:
    """
    Maintains a short-lived view of the passive gallery inventory.

    The session does not persist transfer state. It asks the remote gallery what
    already exists, remembers only the current process answer, and refreshes that
    answer after the configured reconnect interval or after an ambiguous upload
    failure. This is deliberately API-driven so a restarted client still treats
    the gallery as the authority.
    """

    def __init__(
        self,
        refresh_seconds: float,
        emit: Callable[[str, str], None],
        linked_target: Optional[LinkedUploadTarget] = None,
        before_request: Optional[Callable[[], None]] = None,
    ) -> None:
        """
        Create a remote inventory session.

        Args:
            refresh_seconds (float): Minimum seconds between planned inventory
                probes.
            emit (Callable[[str, str], None]): Callback receiving status level
                and message; it queues/logs the event and returns None.
            linked_target (Optional[LinkedUploadTarget]): Captured scoped
                destination, or None for the original API-key connection.
            before_request (Optional[Callable[[], None]]): Once-mode or manual
                batch request checkpoint, or None for other inventory.

        Returns:
            None: Create a remote inventory session.
        """
        self.refresh_seconds = max(1.0, float(refresh_seconds or DEFAULT_INVENTORY_REFRESH_SECONDS))
        self.emit = emit
        self.linked_target = linked_target
        self.before_request = before_request
        self.last_refresh_at = 0.0
        self.last_fingerprint = ""
        self.existing_hashes: Set[str] = set()
        self.resolved_endpoint = ""
        self.lock = threading.Lock()

    def due(self) -> bool:
        """
        Return whether the planned reconnect interval has elapsed.

        Returns:
            bool: True when another inventory handshake should be made.
        """
        return (time.time() - self.last_refresh_at) >= self.refresh_seconds

    def remember_existing(self, payload: Dict[str, Any]) -> int:
        """
        Store hashes confirmed by a gallery inventory response.

        Args:
            payload (Dict[str, Any]): Parsed inventory response returned by PHP
                Gallery.

        Returns:
            int: Number of confirmed remote files in this response.
        """
        existing = payload.get("existing", [])
        if not isinstance(existing, list):
            return 0
        matched = 0
        for row in existing:
            if not isinstance(row, dict):
                continue
            remote_hash = str(row.get("sha256", "")).lower()
            if len(remote_hash) != 64:
                continue
            self.existing_hashes.add(remote_hash)
            matched += 1
        self.last_fingerprint = str(payload.get("fingerprint", "") or self.last_fingerprint)
        return matched

    def refresh(
        self,
        upload_url: str,
        api_key: str,
        candidates: List[Dict[str, Any]],
        force: bool = False,
        deep_check: bool = False,
    ) -> bool:
        """
        Ask the passive gallery which submitted files already exist.

        Routine inventory is database-only. The expensive filesystem fallback is
        requested only after an ambiguous upload failure, where the server may have
        stored a file before the HTTP response was lost. This keeps normal watcher
        polling and import preflight from scanning a large gallery directory.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.
            api_key (str): Gallery-scoped API key.
            candidates (List[Dict[str, Any]]): Local file descriptors to
                compare with the gallery.
            force (bool): When True, refresh even before the planned interval
                elapses.
            deep_check (bool): Request the bounded disk fallback for failure
                reconciliation.

        Returns:
            bool: True when the API call completed successfully.

        Raises:
            ModeCoordinatorError: Once-mode evidence changed before a request.
            GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
        """
        if not candidates:
            return False
        if not force and not self.due():
            return False
        with self.lock:
            if not force and not self.due():
                return False
            # A fresh failed or negative check must never reuse an old match.
            queried = {str(item.get('sha256', '')).lower() for item in candidates}
            self.existing_hashes.difference_update(queried)
            self.resolved_endpoint = ""
            try:
                if self.linked_target is not None:
                    if deep_check:
                        self.emit(
                            "warning", "Linked upload recovery requires manual "
                            "review; database absence does not prove failure.",
                        )
                        return False
                    if self.before_request is not None:
                        self.before_request()
                    payload = self.linked_target.inventory(candidates)
                else:
                    request_options = (
                        {'before_request': self.before_request}
                        if self.before_request is not None else {}
                    )
                    payload, endpoint = post_json_resolved(
                        upload_url,
                        api_key,
                        {"action": "inventory", "files": candidates, "deep_check": bool(deep_check)},
                        timeout_seconds=min(DEFAULT_TIMEOUT_SECONDS, max(10.0, self.refresh_seconds)),
                        **request_options,
                    )
                    if (payload.get("action") == "inventory"
                            and endpoint in upload_endpoint_candidates(upload_url)):
                        self.resolved_endpoint = endpoint
                matched = self.remember_existing(payload)
                self.last_refresh_at = time.time()
                checked = int(payload.get("checked", len(candidates)) or 0)
                self.emit("info", f"Remote inventory refreshed: checked={checked}, already_present={matched}.")
                return True
            except (ModeCoordinatorError, GalleryKeyRedirectError):
                raise
            except Exception as exc:  # noqa: BLE001
                self.last_refresh_at = time.time()
                self.emit("warning", f"Remote inventory refresh failed: {exc}")
                return False

    def has_hash(self, file_hash: str) -> bool:
        """
        Return whether the remote gallery has confirmed this content hash.

        Args:
            file_hash (str): SHA-256 hash of a local file.

        Returns:
            bool: True when a previous inventory response matched it.
        """
        return file_hash.lower() in self.existing_hashes

    def confirm_after_failure(self, upload_url: str, api_key: str, candidate: Dict[str, Any]) -> bool:
        """
        Force a reconnect probe after an ambiguous upload request failure or timeout.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.
            api_key (str): Gallery-scoped API key.
            candidate (Dict[str, Any]): File descriptor for the request that
                just failed.

        Returns:
            bool: True when the gallery now reports the file as already
                present.
        """
        if self.linked_target is not None:
            return False
        self.refresh(upload_url, api_key, [candidate], force=True, deep_check=True)
        return self.has_hash(str(candidate.get("sha256", "")))


def sha256_file(path: Path) -> str:
    """
    Calculate a file SHA-256 hash without loading the whole file at once.

    Args:
        path (Path): File to hash.

    Returns:
        str: Hex-encoded SHA-256 digest.

    Raises:
        OSError: Propagated when the file cannot be opened or read.
    """
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while True:
            chunk = handle.read(1024 * 1024)
            if not chunk:
                break
            digest.update(chunk)
    return digest.hexdigest()


def iter_candidate_files(folder: Path, recursive: bool = False) -> List[Path]:
    """
    Return supported image files inside the watched folder.

    The historical default remains non-recursive so an existing watched
    import/drop folder keeps acting as a flat staging area. Users may explicitly
    enable recursive subfolders in the redesigned Watch folder view. Gallery
    hierarchy and final placement remain controlled by PHP Gallery.

    Args:
        folder (Path): Folder to scan.
        recursive (bool): Whether supported files in subfolders are included.

    Returns:
        List[Path]: Sorted list of supported image paths. Missing or unreadable
            folders return an empty list.
    """
    try:
        iterator = folder.rglob("*") if recursive else folder.iterdir()
        candidates = [item for item in iterator if item.is_file() and item.suffix.lower() in SUPPORTED_SUFFIXES]
    except OSError:
        return []

    # Oldest first produces stable, predictable upload order when several files
    # are copied into the folder at roughly the same time.
    def sort_key(item: Path) -> Tuple[float, str]:
        try:
            modified = item.stat().st_mtime
        except OSError:
            modified = 0.0
        return modified, str(item).lower()

    return sorted(candidates, key=sort_key)


def multipart_field_part(boundary: str, name: str, value: str) -> bytes:
    """
    Build one text field for a multipart/form-data request body.

    Args:
        boundary (str): Multipart boundary string without the leading dashes.
        name (str): Submitted field name.
        value (str): Submitted field value.

    Returns:
        bytes: Encoded multipart field bytes.
    """
    return b"".join([
        f"--{boundary}\r\n".encode("ascii"),
        f'Content-Disposition: form-data; name="{name}"\r\n\r\n'.encode("ascii"),
        str(value).encode("utf-8"),
        b"\r\n",
    ])


def multipart_file_part(
    boundary: str, field_name: str, path: Path, filename: Optional[str] = None,
    file_bytes: Optional[bytes] = None,
) -> bytes:
    """
    Build one file field for a multipart/form-data request body.

    Args:
        boundary (str): Multipart boundary string without the leading dashes.
        field_name (str): Submitted file field name.
        path (Path): Local file path whose bytes should be sent.
        filename (Optional[str]): Optional remote file name. When omitted,
            path.name is used.
        file_bytes (Optional[bytes]): Exact captured file bytes, or None to
            read the selected file once.

    Returns:
        bytes: Encoded multipart file bytes including the file content.

    Raises:
        OSError: Propagated when the file cannot be read.
    """
    safe_name = (filename or path.name).replace('"', "_")
    content_type = mimetypes.guess_type(safe_name)[0] or "application/octet-stream"
    return b"".join([
        f"--{boundary}\r\n".encode("ascii"),
        f'Content-Disposition: form-data; name="{field_name}"; filename="{safe_name}"\r\n'.encode("utf-8"),
        f"Content-Type: {content_type}\r\n\r\n".encode("ascii"),
        path.read_bytes() if file_bytes is None else file_bytes,
        b"\r\n",
    ])


def multipart_upload(
    upload_url: str,
    api_key: str,
    path: Path,
    create_thumbnails: bool,
    thumbnails: Optional[List[LocalThumbnail]] = None,
    client_upload_id: Optional[str] = None,
    metadata_fields: Optional[Dict[str, str]] = None,
    before_request: Optional[Callable[[], None]] = None,
    expected_sha256: str = "",
    expected_size: Optional[int] = None,
    single_dispatch: bool = False,
    selected_endpoint: str = "",
) -> Dict[str, Any]:
    """
    Upload one image file using standard-library HTTP multipart/form-data.

    The same endpoint is used for watch-folder uploads and manual uploads. Manual
    uploads may include locally generated thumbnail files in the same request. The
    server still stores the original image through the existing gallery upload
    pipeline, then installs the supplied thumbnail variants beside the final image
    record.

    Args:
        upload_url (str): Normalized PHP Gallery upload endpoint.
        api_key (str): Gallery-scoped API key sent as X-Gallery-API-Key.
        path (Path): Local image path to upload.
        create_thumbnails (bool): Whether to ask the gallery to generate
            thumbnails.
        thumbnails (Optional[List[LocalThumbnail]]): Optional local thumbnail
            variants to send with the image.
        client_upload_id (Optional[str]): Optional stable request-local ID used
            to map supplied.
        metadata_fields (Optional[Dict[str, str]]): Optional text fields to
            submit beside the image.
        before_request (Optional[Callable[[], None]]): Once-mode or manual
            batch checkpoint before each endpoint and redirect, or None.
        expected_sha256 (str): Expected image digest, or empty for an older
            direct caller without a captured candidate.
        expected_size (Optional[int]): Expected image length, or None when no
            candidate was captured.
        single_dispatch (bool): Refuse redirects and alternate multipart POSTs.
        selected_endpoint (str): Successful inventory route, or empty for the
            canonical route; used only with single_dispatch.

    Returns:
        Dict[str, Any]: Response with ok and upload result fields, including
            uploaded, scanned, filenames and optional metadata or rename status.

    Raises:
        RuntimeError: Raised for HTTP errors, network errors, non-JSON
            responses, malformed JSON payloads, or server-declared upload
            failure.
        OSError: Propagated when the image or a thumbnail cannot be read.
        ValueError: The bytes to send do not match the captured candidate.
        ModeCoordinatorError: The once-mode evidence changed before an attempt.
        GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
    """
    boundary = "PHPGalleryUpload" + uuid.uuid4().hex
    field_entries: List[Tuple[str, str]] = [
        ("create_thumbnails", "1" if create_thumbnails else "0"),
    ]
    if client_upload_id:
        field_entries.append(("image_client_ids[]", client_upload_id))
    for name, value in (metadata_fields or {}).items():
        field_entries.append((str(name), str(value)))

    body_parts: List[bytes] = []
    for name, value in field_entries:
        body_parts.append(multipart_field_part(boundary, name, value))

    # Hash the immutable bytes that enter the request, not a prior path read.
    image_bytes = path.read_bytes()
    if ((expected_size is not None and len(image_bytes) != expected_size)
            or (expected_sha256
                and hashlib.sha256(image_bytes).hexdigest() != expected_sha256)):
        raise ValueError('Source changed before upload; no request was sent.')
    body_parts.append(multipart_file_part(
        boundary, "images[]", path, path.name, image_bytes,
    ))

    for thumbnail in thumbnails or []:
        body_parts.append(multipart_field_part(boundary, "thumbnail_client_ids[]", client_upload_id or ""))
        body_parts.append(multipart_field_part(boundary, "thumbnail_sizes[]", str(int(thumbnail.size))))
        body_parts.append(multipart_field_part(boundary, "thumbnail_formats[]", thumbnail.format))
        body_parts.append(multipart_file_part(boundary, "client_thumbnails[]", thumbnail.path, thumbnail.path.name))

    body_parts.append(f"--{boundary}--\r\n".encode("ascii"))
    body = b"".join(body_parts)

    candidates = upload_endpoint_candidates(upload_url)
    if not candidates:
        raise RuntimeError("Gallery URL or upload endpoint is invalid.")
    if single_dispatch:
        if selected_endpoint and selected_endpoint not in candidates:
            raise ValueError("The selected upload endpoint is invalid.")
        candidates = [selected_endpoint or candidates[0]]

    for index, endpoint in enumerate(candidates):
        http_request = request.Request(
            endpoint,
            data=body,
            headers={
                "Content-Type": f"multipart/form-data; boundary={boundary}",
                "Content-Length": str(len(body)),
                "X-Gallery-API-Key": api_key,
                "Accept": "application/json",
                "User-Agent": "PHPGalleryUploader/1.4",
            },
            method="POST",
        )

        try:
            request_options = (
                {'before_request': before_request} if before_request is not None else {}
            )
            if single_dispatch:
                request_options["allow_redirects"] = False
            with _gallery_key_open(
                http_request, DEFAULT_TIMEOUT_SECONDS, **request_options,
            ) as response:
                response_body = response.read().decode("utf-8", errors="replace")
        except error.HTTPError as exc:
            # PHP Gallery returns JSON for application errors. Only an HTML/plain
            # 404 from the hosting layer is eligible for alternate-route retry.
            response_body = exc.read().decode("utf-8", errors="replace")
            try:
                error_payload = json.loads(response_body)
                message = error_payload.get("error") if isinstance(error_payload, dict) else None
                json_error = isinstance(error_payload, dict)
            except json.JSONDecodeError:
                message = None
                json_error = False
            has_fallback = index + 1 < len(candidates)
            if exc.code == 404 and not json_error and has_fallback:
                logging.warning("Upload endpoint route returned HTTP 404 during multipart upload; retrying alternate Gallery API route.")
                continue
            raise RuntimeError(str(message or f"HTTP {exc.code}: {response_body[:300]}")) from exc
        except error.URLError as exc:
            raise RuntimeError(str(exc.reason)) from exc

        try:
            payload = json.loads(response_body)
        except json.JSONDecodeError as exc:
            raise RuntimeError(f"Server returned non-JSON response: {response_body[:300]}") from exc
        if not isinstance(payload, dict):
            raise RuntimeError("Server returned an invalid JSON response.")
        if not payload.get("ok"):
            raise RuntimeError(str(payload.get("error") or "Upload failed."))
        if expected_sha256 and (payload.get("ok") is not True
                                or type(payload.get("uploaded")) is not int
                                or payload.get("uploaded") != 1):
            raise RuntimeError("Upload outcome is unconfirmed; review the gallery.")
        return payload

    raise RuntimeError("No usable PHP Gallery upload endpoint was found.")


def selected_image_filetypes() -> List[Tuple[str, str]]:
    """
    Return file picker filters for manual image selection.

    Returns:
        List[Tuple[str, str]]: Tkinter-compatible file type filters.
    """
    return [
        ("Image files", "*.jpg *.jpeg *.png *.gif *.webp *.heic *.heif *.dng"),
        ("JPEG", "*.jpg *.jpeg"),
        ("PNG", "*.png"),
        ("WebP", "*.webp"),
        ("All files", "*.*"),
    ]


def filter_supported_paths(paths: Iterable[str]) -> List[Path]:
    """
    Normalize manually selected paths and keep only supported image suffixes.

    Args:
        paths (Iterable[str]): Raw path strings returned by Tkinter.

    Returns:
        List[Path]: Sorted, de-duplicated Path objects.
    """
    unique: Dict[str, Path] = {}
    for raw_path in paths:
        path = Path(raw_path)
        if path.is_file() and path.suffix.lower() in SUPPORTED_SUFFIXES:
            unique[str(path)] = path
    return [unique[key] for key in sorted(unique.keys(), key=str.lower)]


def local_thumbnail_supported() -> bool:
    """
    Return whether Pillow is available for client-side thumbnail generation.

    Returns:
        bool: True when Pillow imports are available.
    """
    return Image is not None and ImageOps is not None


def thumbnail_runtime_status() -> str:
    """
    Build a clear status line for the Python runtime used by this process.

    Windows can have multiple Python installations and Microsoft Store aliases.
    The app must report the exact executable currently running the GUI because
    optional helper packages have to be installed into this same interpreter.

    Returns:
        str: Human-readable runtime and Pillow availability status.
    """
    executable = sys.executable or "unknown Python executable"
    version = sys.version.split()[0]
    tray_status = "tray available" if pystray is not None else "tray unavailable"
    if local_thumbnail_supported():
        pillow_version = getattr(Image, "__version__", "installed")
        return f"Client-side thumbnails available. Python {version}: {executable}. Pillow: {pillow_version}; {tray_status}."
    return f"Client-side thumbnails unavailable for this Python runtime: {executable}. Python {version}; {tray_status}."


def clamp_int(value: int, minimum: int, maximum: int) -> int:
    """
    Restrict an integer to a configured inclusive range.

    Args:
        value (int): Candidate integer value.
        minimum (int): Lowest accepted value.
        maximum (int): Highest accepted value.

    Returns:
        int: Value restricted to the accepted range.
    """
    return max(minimum, min(maximum, int(value)))


def automatic_thumbnail_worker_count() -> int:
    """
    Choose a conservative multiprocessing thumbnail worker count.

    The automatic value intentionally avoids using every logical CPU. Image
    resizing and WebP encoding also consume disk I/O, memory bandwidth, and RAM.
    On high-core CPUs, using roughly half the logical CPUs is usually faster and
    more stable than launching one encoder process per thread.

    Returns:
        int: Worker process count for thumbnail generation.
    """
    cpu_count = os.cpu_count() or 4
    return clamp_int(max(2, cpu_count // 2), 2, min(MAX_THUMBNAIL_WORKERS, cpu_count))


def automatic_upload_worker_count() -> int:
    """
    Choose a conservative manual upload worker count.

    PHP Gallery serializes mutations for one gallery under a server-side lock.
    Parallel multipart requests therefore do not increase mutation throughput; they
    only occupy additional PHP workers while waiting for the same gallery lock. A
    single network upload worker is intentionally used to protect shared hosting.

    Returns:
        int: Worker thread count for multipart uploads.
    """
    return DEFAULT_UPLOAD_WORKERS


def resolve_thumbnail_worker_count(configured_value: int) -> int:
    """
    Resolve the manual thumbnail worker setting into a real process count.

    Args:
        configured_value (int): Stored UI value, where zero means automatic.

    Returns:
        int: Safe worker process count.
    """
    if int(configured_value) <= 0:
        return automatic_thumbnail_worker_count()
    return clamp_int(int(configured_value), 1, MAX_THUMBNAIL_WORKERS)


def resolve_upload_worker_count(configured_value: int) -> int:
    """
    Resolve the manual upload worker setting into a real thread count.

    Args:
        configured_value (int): Stored UI value, where zero means automatic.

    Returns:
        int: Safe worker thread count.
    """
    if int(configured_value) <= 0:
        return automatic_upload_worker_count()
    return clamp_int(int(configured_value), 1, MAX_UPLOAD_WORKERS)


def worker_choice_values(maximum: int) -> List[str]:
    """
    Build human-readable worker choices for the Tkinter comboboxes.

    Args:
        maximum (int): Highest explicit worker value to offer.

    Returns:
        List[str]: List containing Auto plus numeric worker counts.
    """
    values = ["Auto"]
    for value in [1, 2, 4, 6, 8, 12, 16, 24, 32]:
        if value <= maximum:
            values.append(str(value))
    return values


def parse_worker_choice(value: str) -> int:
    """
    Convert a UI worker choice into the persisted integer representation.

    Args:
        value (str): Combobox text, either Auto or a positive integer.

    Returns:
        int: Zero for Auto, otherwise a positive integer.
    """
    text = str(value).strip()
    if not text or text.lower() == "auto":
        return 0
    return int(text)


def normalize_ai_vision_backend(value: str) -> str:
    """
    Return a supported AI vision backend identifier.

    Args:
        value (str): Raw backend value from config or UI.

    Returns:
        str: One of the supported backend identifiers.
    """
    normalized = value.strip().lower()
    if normalized in AI_VISION_BACKEND_CHOICES:
        return normalized
    return AI_VISION_BACKEND_DEFAULT


def format_worker_choice(value: int) -> str:
    """
    Convert a persisted worker value into combobox text.

    Args:
        value (int): Stored worker value, where zero means Auto.

    Returns:
        str: Combobox text.
    """
    if int(value) <= 0:
        return "Auto"
    return str(int(value))


def install_dependencies_for_current_runtime() -> Tuple[bool, str]:
    """
    Install or repair winapp dependencies for the exact Python interpreter.

    This avoids the common Windows issue where install.bat installs packages into
    one Python version while a .pyw file association launches another version.

    Returns:
        Tuple[bool, str]: Tuple containing success flag and command output
            text.
    """
    if getattr(sys, "frozen", False):
        available = Image is not None and pystray is not None
        return available, (
            "Required dependencies are bundled with this application."
            if available else "Bundled dependencies are unavailable. Reinstall PHP Gallery Uploader."
        )
    command = [sys.executable, "-m", "pip", "install", "--user"]
    if REQUIREMENTS_PATH.is_file():
        command.extend(["-r", str(REQUIREMENTS_PATH)])
    else:
        command.extend(["Pillow>=10.0", "pystray>=0.19.5"])

    try:
        completed = subprocess.run(
            command,
            check=False,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
        )
    except Exception as exc:  # noqa: BLE001
        return False, str(exc)

    output = completed.stdout.strip() if completed.stdout else "pip produced no output"
    if completed.returncode == 0:
        try:
            globals()["Image"] = importlib.import_module("PIL.Image")
            globals()["ImageOps"] = importlib.import_module("PIL.ImageOps")
            globals()["ImageStat"] = importlib.import_module("PIL.ImageStat")
            globals()["pystray"] = importlib.import_module("pystray")
        except Exception as exc:  # noqa: BLE001
            return False, f"pip finished, but required packages still cannot be imported by this process: {exc}\n{output}"
    return completed.returncode == 0, output


def install_semantic_ai_dependencies_for_current_runtime() -> Tuple[bool, str]:
    """
    Install optional in-process semantic AI packages for this Python runtime.

    These packages are intentionally not listed in requirements.txt because they
    are large and are needed only when the operator selects the Transformers
    backend. The normal uploader, tray icon, manual upload, and Pillow fallback
    continue to use the lightweight required dependency set.

    Returns:
        Tuple[bool, str]: Tuple containing success flag and command output
            text.
    """
    if getattr(sys, "frozen", False):
        return False, (
            "The standalone installer cannot install Python packages. Use Ollama or an external analyzer, "
            "or run the Python source version to use Transformers."
        )
    command = [
        sys.executable,
        "-m",
        "pip",
        "install",
        "--user",
        "transformers>=4.40",
        "torch>=2.2",
        "torchvision>=0.17",
    ]
    try:
        completed = subprocess.run(
            command,
            check=False,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
        )
    except Exception as exc:  # noqa: BLE001
        return False, str(exc)

    output = completed.stdout.strip() if completed.stdout else "pip produced no output"
    if completed.returncode == 0:
        try:
            importlib.import_module("torch")
            importlib.import_module("transformers")
        except Exception as exc:  # noqa: BLE001
            return False, f"pip finished, but semantic AI packages still cannot be imported by this process: {exc}\n{output}"
    return completed.returncode == 0, output


def image_has_alpha(image: "Image.Image") -> bool:
    """
    Return whether a Pillow image contains transparency that matters for output.

    Args:
        image (Image.Image): Pillow image with a mode string and info mapping;
            palette transparency is read from info["transparency"].

    Returns:
        bool: True when the image has an alpha channel or palette transparency.
    """
    return image.mode in {"RGBA", "LA"} or (image.mode == "P" and "transparency" in image.info)


def prepare_jpeg_image(image: Any) -> Any:
    """
    Convert a Pillow image to the RGB canvas expected by JPEG output.

    Transparent pixels are composited over white to match the gallery server's
    JPEG thumbnail behavior.

    Args:
        image (Any): Pillow image instance.

    Returns:
        Any: RGB Pillow image suitable for JPEG encoding.
    """
    if image_has_alpha(image):
        rgba = image.convert("RGBA")
        background = Image.new("RGB", rgba.size, (255, 255, 255))
        background.paste(rgba, mask=rgba.getchannel("A"))
        return background
    return image.convert("RGB")


def prepare_webp_image(image: Any) -> Any:
    """
    Convert a Pillow image to a WebP-friendly mode while preserving alpha.

    Args:
        image (Any): Pillow image instance.

    Returns:
        Any: Pillow image suitable for WebP encoding.
    """
    if image_has_alpha(image):
        return image.convert("RGBA")
    return image.convert("RGB")


def generate_local_thumbnails(source_path: Path, output_root: Path, client_upload_id: str) -> List[LocalThumbnail]:
    """
    Generate PHP Gallery responsive thumbnail variants on the client computer.

    The size list and naming intent mirror PHP Gallery's thumbnail service:
    300, 600, 800, 960, 1280, and 1600 pixels on the long side, emitted as JPG
    and WebP. The server decides where those files belong after it stores the
    original and resolves the final image record.

    Args:
        source_path (Path): Original image selected for manual upload.
        output_root (Path): Temporary parent directory for generated files.
        client_upload_id (str): Request-local ID shared with the server
            metadata.

    Returns:
        List[LocalThumbnail]: Generated thumbnail descriptors.

    Raises:
        RuntimeError: Raised when Pillow is unavailable.
        OSError: Propagated when files cannot be read or written.
        Exception: Propagated when Pillow cannot decode or encode the image.
    """
    if not local_thumbnail_supported():
        raise RuntimeError("Client-side thumbnails require Pillow. Install it with: python -m pip install Pillow")

    target_dir = output_root / client_upload_id
    target_dir.mkdir(parents=True, exist_ok=True)
    thumbnails: List[LocalThumbnail] = []

    with Image.open(source_path) as opened:
        try:
            opened.seek(0)
        except EOFError:
            pass
        source = ImageOps.exif_transpose(opened)
        stem = source_path.stem.replace('"', "_")

        for size in THUMBNAIL_SIZES:
            resized = source.copy()
            resized.thumbnail((size, size), Image.Resampling.LANCZOS)

            jpeg_path = target_dir / f"{stem}_thumb{size}.jpg"
            jpeg_image = prepare_jpeg_image(resized)
            jpeg_image.save(jpeg_path, "JPEG", quality=82, optimize=True, progressive=True)
            thumbnails.append(LocalThumbnail(path=jpeg_path, size=size, format="jpg"))

            webp_path = target_dir / f"{stem}_thumb{size}.webp"
            webp_image = prepare_webp_image(resized)
            webp_image.save(webp_path, "WEBP", quality=82, method=6)
            thumbnails.append(LocalThumbnail(path=webp_path, size=size, format="webp"))

    return thumbnails


def generate_linked_thumbnails(
    source_path: Path, output_root: Path, client_upload_id: str, expected_sha256: str,
) -> List[LocalThumbnail]:
    """Generate variants from a size-bounded, hash-verified private snapshot.

    Args:
        source_path (Path): Original source retained by its owner.
        output_root (Path): Owned temporary root for this generation pipeline.
        client_upload_id (str): Unique request-local thumbnail directory.
        expected_sha256 (str): SHA-256 captured before generation begins.

    Returns:
        List[LocalThumbnail]: Variants generated only from verified bytes.

    Raises:
        OSError: The source or private snapshot cannot be safely processed.
        ValueError: The source is too large or changed since hashing.
    """
    snapshot_dir = output_root / client_upload_id / 'source-snapshot'
    snapshot_dir.mkdir(parents=True, exist_ok=False)
    snapshot = snapshot_dir / source_path.name
    digest = hashlib.sha256()
    copied = 0
    try:
        with source_path.open('rb') as source, snapshot.open('xb') as destination:
            while True:
                chunk = source.read(LINKED_UPLOAD_CHUNK_BYTES)
                if not chunk:
                    break
                copied += len(chunk)
                if copied > LINKED_UPLOAD_FILE_MAX_BYTES:
                    raise ValueError('Linked thumbnail source exceeds the upload size limit.')
                digest.update(chunk)
                destination.write(chunk)
        if digest.hexdigest() != expected_sha256:
            raise ValueError('Source changed before linked thumbnail generation.')
        return generate_local_thumbnails(snapshot, output_root, client_upload_id)
    finally:
        shutil.rmtree(snapshot_dir, ignore_errors=True)


class PeriodicAIHeartbeat(threading.Thread):
    """
    Sends periodic lease heartbeats while one AI job is being processed.

    The worker thread owns the actual image analysis. This helper only extends
    the server lease, which protects long external model runs from being claimed
    by another companion app after the original lease expires.
    """

    def __init__(
        self,
        upload_url: str,
        api_key: str,
        job_id: int,
        claim_token: str,
        lease_seconds: int,
        progress_callback: Callable[[], Tuple[int, str]],
        emit: Callable[[str, str], None],
        before_request: Optional[Callable[[], None]] = None,
        terminal_event: Optional[threading.Event] = None,
    ) -> None:
        """
        Create a heartbeat thread for one claimed job.

        Args:
            upload_url (str): Normalized upload automation endpoint.
            api_key (str): Gallery-scoped API key.
            job_id (int): Server job id from the claim response.
            claim_token (str): Server claim token returned with the job.
            lease_seconds (int): Requested lease extension length.
            progress_callback (Callable[[], Tuple[int, str]]): Snapshot callback
                returning integer progress percent and status text.
            emit (Callable[[str, str], None]): Status callback receiving level
                and message, with no return value.
            before_request (Optional[Callable[[], None]]): GUI work checkpoint,
                or None for non-GUI fixture and compatibility callers.
            terminal_event (Optional[threading.Event]): Shared AI parent signal,
                or None for an independent heartbeat.

        Returns:
            None: Create a heartbeat thread for one claimed job.
        """
        super().__init__(name="PHPGalleryAIHeartbeat", daemon=True)
        self.upload_url = upload_url
        self.api_key = api_key
        self.job_id = job_id
        self.claim_token = claim_token
        self.lease_seconds = lease_seconds
        self.progress_callback = progress_callback
        self.emit = emit
        self.before_request = before_request
        self.terminal_event = terminal_event
        self.stop_event = threading.Event()
        self.interval = max(AI_HEARTBEAT_MIN_SECONDS, min(120.0, lease_seconds / 3.0))

    def stop(self) -> None:
        """Stop scheduling heartbeats without claiming an active request ended.

        Returns:
            None: Signals the worker; an in-flight request may still be active.
        """
        self.stop_event.set()

    def run(self) -> None:
        """
        Send heartbeats until stopped.

        Returns:
            None: Ends after a stop signal or terminal authorization refusal.
        """
        while not self.stop_event.wait(self.interval):
            try:
                progress_percent, message = self.progress_callback()
                post_json(
                    self.upload_url,
                    self.api_key,
                    {
                        "action": "ai_heartbeat",
                        "job_id": self.job_id,
                        "claim_token": self.claim_token,
                        "lease_seconds": self.lease_seconds,
                        "progress_percent": int(progress_percent),
                        "message": str(message),
                    },
                    timeout_seconds=30,
                    **({'before_request': self.before_request}
                       if self.before_request is not None else {}),
                )
            except (ModeCoordinatorError, GalleryKeyRedirectError):
                if self.terminal_event is not None:
                    self.terminal_event.set()
                self.stop_event.set()
                self.emit('warning', 'Gallery authorization could not be verified; AI heartbeat stopped.')
            except Exception as exc:  # noqa: BLE001
                self.emit("warning", f"AI worker heartbeat failed for job {self.job_id}: {exc}")


class AIImageAnalyzer:
    """
    Local image-analysis adapter used by the optional AI metadata worker.

    The default implementation is deliberately dependency-light and relies on
    Pillow because the companion app already supports it. Operators who run a
    real local vision model can provide an external command that receives the
    downloaded image path and writes JSON to stdout.
    """

    def __init__(self, config: WatcherConfig) -> None:
        """
        Create an analyzer adapter from the current worker configuration.

        Args:
            config (WatcherConfig): Worker configuration captured at start
                time.

        Returns:
            None: Create an analyzer adapter from the current worker
                configuration.
        """
        self.config = config
        self.external_command = config.ai_external_command.strip()
        self.vision_backend = normalize_ai_vision_backend(config.ai_vision_backend)
        self.transformers_caption_model = config.ai_transformers_caption_model.strip() or AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT
        self.transformers_detector_model = config.ai_transformers_detector_model.strip() or AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT
        self.transformers_object_labels = config.ai_transformers_object_labels.strip() or AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT
        self.transformers_detection_threshold = max(0.01, min(0.95, float(config.ai_transformers_detection_threshold or AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT)))
        self.ollama_url = config.ai_ollama_url.strip().rstrip("/") or AI_OLLAMA_URL_DEFAULT
        self.ollama_model = config.ai_ollama_model.strip() or AI_OLLAMA_MODEL_DEFAULT
        self.semantic_prompt = config.ai_semantic_prompt.strip() or AI_SEMANTIC_PROMPT_DEFAULT

    def analyze(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Analyze one downloaded image asset and return internal metadata.

        Args:
            image_path (Path): Local path to the claimed image asset.
            job (Dict[str, Any]): Job payload returned by the PHP Gallery
                server.

        Returns:
            Tuple[Dict[str, Any], str]: Tuple of metadata dictionary and
                explicit searchable text.

        Raises:
            RuntimeError: Raised when neither the external command nor the
                built-in Pillow analysis can produce metadata.
        """
        if self.vision_backend == "external":
            if not self.external_command:
                raise RuntimeError("The external AI backend is selected, but no external analyzer command is configured.")
            return self.analyze_with_external_command(image_path, job)
        if self.vision_backend == "transformers":
            return self.analyze_with_transformers(image_path, job)
        if self.vision_backend == "ollama":
            return self.analyze_with_ollama(image_path, job)
        if self.vision_backend == "pillow":
            return self.analyze_with_pillow(image_path, job)
        if self.external_command:
            return self.analyze_with_external_command(image_path, job)
        try:
            return self.analyze_with_transformers(image_path, job)
        except Exception as exc:  # noqa: BLE001
            logging.info("Local Transformers backend unavailable, using next AI fallback: %s", exc)
        try:
            return self.analyze_with_ollama(image_path, job)
        except Exception as exc:  # noqa: BLE001
            logging.info("Local Ollama backend unavailable, using Pillow fallback: %s", exc)
        return self.analyze_with_pillow(image_path, job)

    def analyze_with_external_command(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Run an operator-provided local analyzer command.

        The command must print a JSON object to stdout. Accepted shapes are:
        {"metadata": {...}, "searchable_text": "..."} or any JSON object, which
        will be stored directly as metadata.

        Args:
            image_path (Path): Local path to the downloaded image.
            job (Dict[str, Any]): Server job payload.

        Returns:
            Tuple[Dict[str, Any], str]: Tuple of metadata dictionary and
                explicit searchable text.

        Raises:
            RuntimeError: Raised when the command fails or returns bad JSON.
        """
        image = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        command = self.external_command
        # Placeholder replacement is intentionally simple so JSON braces in shell
        # commands are not accidentally parsed as Python format fields.
        command = command.replace("{image_path}", str(image_path))
        command = command.replace("{filename}", str(image.get("filename") or image_path.name))
        command = command.replace("{job_id}", str(job.get("job_id") or ""))
        completed = subprocess.run(
            command,
            shell=True,
            capture_output=True,
            text=True,
            timeout=AI_ANALYZER_TIMEOUT_SECONDS,
            check=False,
        )
        if completed.returncode != 0:
            stderr = (completed.stderr or completed.stdout or "").strip()
            raise RuntimeError(f"External AI analyzer failed with exit code {completed.returncode}: {stderr[:800]}")

        try:
            payload = json.loads(completed.stdout)
        except json.JSONDecodeError as exc:
            raise RuntimeError(f"External AI analyzer returned non-JSON output: {completed.stdout[:800]}") from exc
        if not isinstance(payload, dict):
            raise RuntimeError("External AI analyzer must return a JSON object.")

        metadata = payload.get("metadata") if isinstance(payload.get("metadata"), dict) else payload
        searchable_text = str(payload.get("searchable_text") or "")
        metadata.setdefault("analyzer", "external-command")
        metadata.setdefault("external_command_used", True)
        return metadata, searchable_text

    def analyze_with_transformers(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Produce semantic metadata inside this Python process with Transformers.

        This backend does not require a separate local server. It imports the
        optional Hugging Face Transformers and PyTorch packages directly, loads
        the configured models in-process, and analyzes the assigned image on the
        worker PC. First use may download model files into the normal local
        Hugging Face cache for the current Windows user.

        Args:
            image_path (Path): Local path to the downloaded image.
            job (Dict[str, Any]): Server job payload.

        Returns:
            Tuple[Dict[str, Any], str]: Tuple of metadata dictionary and
                searchable text.

        Raises:
            RuntimeError: Raised when optional packages or models are absent.
        """
        if Image is None or ImageOps is None:
            raise RuntimeError("Pillow is required before the local Transformers backend can open images.")

        image_info = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        with Image.open(image_path) as opened:
            image = ImageOps.exif_transpose(opened).convert("RGB")
            caption = self.transformers_caption(image)
            detections = self.transformers_object_detections(image)

        objects = self.normalize_label_list([item["label"] for item in detections])
        caption_labels = self.labels_from_caption(caption, self.transformers_candidate_labels())
        labels = sorted(set(objects + caption_labels))
        internal_description = caption.strip().rstrip(".")
        if objects:
            internal_description += "; objects: " + ", ".join(objects[:16])
        if not internal_description:
            internal_description = "semantic image metadata generated locally"

        metadata = {
            "analyzer": "local-transformers-vision-metadata",
            "analyzer_version": "1",
            "backend": "transformers",
            "caption_model": self.transformers_caption_model,
            "detector_model": self.transformers_detector_model,
            "detection_threshold": self.transformers_detection_threshold,
            "internal_description": internal_description,
            "caption": caption,
            "labels": labels,
            "objects": objects,
            "detections": detections[:40],
            "source": {
                "filename": str(image_info.get("filename") or image_path.name),
                "image_id": int(image_info.get("id") or 0),
                "checksum_sha256": str(image_info.get("checksum_sha256") or ""),
            },
        }
        searchable_parts = [
            internal_description,
            caption,
            " ".join(labels),
            " ".join(objects),
            str(image_info.get("filename") or ""),
        ]
        searchable_text = " ".join(part for part in searchable_parts if part).strip()
        return metadata, searchable_text

    def transformers_caption(self, image: "Image.Image") -> str:
        """
        Return one concise caption from the configured in-process caption model.

        Args:
            image (Image.Image): RGB Pillow image supplied to the caption
                pipeline.

        Returns:
            str: Caption text, lower-case and whitespace-normalized.

        Raises:
            RuntimeError: Raised when the model cannot run.
        """
        last_error: Optional[Exception] = None
        for task in ("image-to-text", "image-text-to-text"):
            try:
                pipeline = self.transformers_pipeline(task, self.transformers_caption_model)
                try:
                    result = pipeline(image, max_new_tokens=60)
                except TypeError:
                    result = pipeline(image)
                return self.transformers_caption_text_from_result(result)
            except Exception as exc:  # noqa: BLE001
                last_error = exc
                logging.info("Local Transformers caption task %s unavailable: %s", task, exc)
        raise RuntimeError(f"Local Transformers caption model failed: {last_error}")

    def transformers_caption_text_from_result(self, result: JsonValue) -> str:
        """
        Extract normalized caption text from multiple Transformers result shapes.

        Transformers versions differ in their preferred image captioning task and
        output field names. This helper keeps the analyzer tolerant while keeping
        diagnostic details out of metadata sent to the gallery server.

        Args:
            result (JsonValue): JSON-compatible pipeline output: a dictionary
                with optional generated_text, caption or text fields, a list
                whose first item is inspected, or a scalar. Selected values
                are converted to text; empty/None results produce no caption.

        Returns:
            str: Lower-case, whitespace-normalized caption text.
        """
        if isinstance(result, list) and result:
            first = result[0]
            if isinstance(first, dict):
                text = str(first.get("generated_text") or first.get("caption") or first.get("text") or "")
            else:
                text = str(first)
        elif isinstance(result, dict):
            text = str(result.get("generated_text") or result.get("caption") or result.get("text") or "")
        else:
            text = str(result or "")
        return " ".join(text.lower().split())

    def transformers_object_detections(
        self, image: "Image.Image"
    ) -> List[Dict[str, Any]]:
        """
        Return zero-shot object detections from the configured local model.

        The detector is optional even when the Transformers backend is selected.
        If the detector model fails after a caption has been produced, the
        analyzer keeps the caption result and stores a diagnostic object label
        only in local logs through the raised message when no caption exists.

        Args:
            image (Image.Image): RGB Pillow image supplied to the detector.

        Returns:
            List[Dict[str, Any]]: Detection rows with label (str), score (float)
                and box (dict with integer xmin/ymin/xmax/ymax, or an empty
                dictionary when no bounding box was supplied).
        """
        labels = self.transformers_candidate_labels()
        if not labels:
            return []
        try:
            pipeline = self.transformers_pipeline("zero-shot-object-detection", self.transformers_detector_model)
            result = pipeline(image, candidate_labels=labels, threshold=self.transformers_detection_threshold)
        except TypeError:
            result = pipeline(image, candidate_labels=labels)
        except Exception as exc:  # noqa: BLE001
            logging.info("Local Transformers detector unavailable: %s", exc)
            return []

        detections: List[Dict[str, Any]] = []
        if not isinstance(result, list):
            return detections
        for item in result:
            if not isinstance(item, dict):
                continue
            score = float(item.get("score") or 0.0)
            if score < self.transformers_detection_threshold:
                continue
            label = " ".join(str(item.get("label") or "").lower().split())
            if not label:
                continue
            box = item.get("box") if isinstance(item.get("box"), dict) else {}
            detections.append({
                "label": label,
                "score": round(score, 4),
                "box": {
                    "xmin": int(float(box.get("xmin") or 0)),
                    "ymin": int(float(box.get("ymin") or 0)),
                    "xmax": int(float(box.get("xmax") or 0)),
                    "ymax": int(float(box.get("ymax") or 0)),
                } if box else {},
            })
        detections.sort(key=lambda item: float(item.get("score") or 0.0), reverse=True)
        return detections[:40]

    def transformers_pipeline(self, task: str, model_name: str) -> Any:
        """
        Load and cache one Transformers pipeline inside the current process.

        Args:
            task (str): Transformers pipeline task name.
            model_name (str): Hugging Face model identifier or local model
                path.

        Returns:
            Any: Pipeline callable.

        Raises:
            RuntimeError: Raised when optional packages are not installed.
        """
        cache_key = (task, model_name)
        with _AI_TRANSFORMERS_LOCK:
            if cache_key in _AI_TRANSFORMERS_PIPELINES:
                return _AI_TRANSFORMERS_PIPELINES[cache_key]
            try:
                transformers = importlib.import_module("transformers")
                importlib.import_module("torch")
            except Exception as exc:  # noqa: BLE001
                raise RuntimeError(
                    "Local Transformers backend needs optional packages. Use the AI tab button "
                    "or run: python -m pip install --user transformers torch torchvision"
                ) from exc
            try:
                pipeline = transformers.pipeline(task, model=model_name)
            except Exception as exc:  # noqa: BLE001
                raise RuntimeError(f"Could not load local Transformers model {model_name!r} for {task}: {exc}") from exc
            _AI_TRANSFORMERS_PIPELINES[cache_key] = pipeline
            return pipeline

    def transformers_candidate_labels(self) -> List[str]:
        """
        Return normalized object labels for local zero-shot detection.

        Returns:
            List[str]: Deduplicated candidate label list.
        """
        return self.normalize_label_list(self.transformers_object_labels)

    def labels_from_caption(self, caption: str, candidates: List[str]) -> List[str]:
        """
        Extract configured search labels explicitly mentioned in a caption.

        Args:
            caption (str): Caption text from the local caption model.
            candidates (List[str]): Candidate labels configured for object
                detection.

        Returns:
            List[str]: Labels found in the caption.
        """
        caption_text = " " + " ".join(caption.lower().replace("-", " ").split()) + " "
        found: List[str] = []
        for label in candidates:
            label_text = " ".join(label.lower().replace("-", " ").split())
            if not label_text:
                continue
            plural = label_text + "s"
            if f" {label_text} " in caption_text or f" {plural} " in caption_text:
                if label_text not in found:
                    found.append(label_text)
        return found[:40]

    def analyze_with_ollama(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Produce semantic image metadata with a local Ollama vision model.

        This backend keeps heavy analysis on the Windows machine. It contacts
        only the configured local Ollama endpoint and never sends image bytes to
        PHP Gallery except for the final internal metadata result.

        Args:
            image_path (Path): Local path to the downloaded image.
            job (Dict[str, Any]): Server job payload.

        Returns:
            Tuple[Dict[str, Any], str]: Tuple of metadata dictionary and
                searchable text.

        Raises:
            RuntimeError: Raised when Ollama is unavailable or returns bad
                data.
        """
        image_info = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        with image_path.open("rb") as handle:
            encoded_image = base64.b64encode(handle.read()).decode("ascii")

        payload = {
            "model": self.ollama_model,
            "prompt": self.semantic_prompt,
            "images": [encoded_image],
            "stream": False,
            "format": "json",
            "options": {
                "temperature": 0.1,
                "num_predict": 512,
            },
        }
        body = json.dumps(payload).encode("utf-8")
        req = request.Request(
            self.ollama_url + "/api/generate",
            data=body,
            headers={"Content-Type": "application/json", "Accept": "application/json"},
            method="POST",
        )
        try:
            with request.urlopen(req, timeout=AI_ANALYZER_TIMEOUT_SECONDS) as response:
                response_payload = json.loads(response.read().decode("utf-8", "replace"))
        except error.URLError as exc:
            raise RuntimeError(f"Ollama vision backend is unavailable at {self.ollama_url}: {exc}") from exc
        except json.JSONDecodeError as exc:
            raise RuntimeError("Ollama vision backend returned invalid JSON.") from exc

        raw_response = str(response_payload.get("response") or "").strip()
        semantic = self.parse_semantic_json(raw_response)
        labels = self.normalize_label_list(semantic.get("labels"))
        objects = self.normalize_label_list(semantic.get("objects"))
        activities = self.normalize_label_list(semantic.get("activities"))
        scene = str(semantic.get("scene") or "").strip().lower()
        recognized_text = str(semantic.get("text") or "").strip()
        internal_description = str(semantic.get("internal_description") or "").strip()
        if not internal_description:
            internal_description = self.semantic_description_from_parts(scene, objects, activities, labels)
        if not internal_description:
            internal_description = "semantic image metadata generated locally"

        metadata = {
            "analyzer": "local-ollama-vision-metadata",
            "analyzer_version": "1",
            "backend": "ollama",
            "ollama_url": self.ollama_url,
            "ollama_model": self.ollama_model,
            "internal_description": internal_description,
            "labels": sorted(set(labels + objects + activities + ([scene] if scene else []))),
            "objects": objects,
            "scene": scene,
            "activities": activities,
            "text": recognized_text,
            "confidence_notes": str(semantic.get("confidence_notes") or "").strip(),
            "source": {
                "filename": str(image_info.get("filename") or image_path.name),
                "image_id": int(image_info.get("id") or 0),
                "checksum_sha256": str(image_info.get("checksum_sha256") or ""),
            },
        }
        searchable_parts = [
            internal_description,
            scene,
            recognized_text,
            " ".join(metadata["labels"]),
            str(image_info.get("filename") or ""),
        ]
        searchable_text = " ".join(part for part in searchable_parts if part).strip()
        return metadata, searchable_text

    def parse_semantic_json(self, raw_response: str) -> Dict[str, Any]:
        """
        Decode the JSON object produced by a local vision model.

        Vision models sometimes wrap JSON in explanatory text despite explicit
        prompting. This parser first tries strict JSON, then extracts the first
        object-shaped range as a practical recovery path.

        Args:
            raw_response (str): Raw response text from the local model.

        Returns:
            Dict[str, Any]: Decoded dictionary.

        Raises:
            RuntimeError: Raised when no JSON object can be decoded.
        """
        try:
            decoded = json.loads(raw_response)
        except json.JSONDecodeError:
            start = raw_response.find("{")
            end = raw_response.rfind("}")
            if start < 0 or end <= start:
                raise RuntimeError(f"Ollama vision backend did not return a JSON object: {raw_response[:500]}")
            try:
                decoded = json.loads(raw_response[start:end + 1])
            except json.JSONDecodeError as exc:
                raise RuntimeError(f"Ollama vision backend returned unusable JSON: {raw_response[:500]}") from exc
        if not isinstance(decoded, dict):
            raise RuntimeError("Ollama vision backend JSON must be an object.")
        return decoded

    def normalize_label_list(self, value: JsonValue) -> List[str]:
        """
        Normalize model-produced labels into short searchable strings.

        Args:
            value (JsonValue): Raw decoded JSON. A string is split at commas
                or semicolons; list members are converted to strings. Other
                JSON kinds yield no labels.

        Returns:
            List[str]: Deduplicated list of lower-case labels.
        """
        if isinstance(value, str):
            candidates = [part.strip() for part in value.replace(";", ",").split(",")]
        elif isinstance(value, list):
            candidates = [str(part).strip() for part in value]
        else:
            candidates = []
        labels: List[str] = []
        for candidate in candidates:
            label = " ".join(candidate.lower().split())
            if label and len(label) <= 80 and label not in labels:
                labels.append(label)
        return labels[:40]

    def semantic_description_from_parts(self, scene: str, objects: List[str], activities: List[str], labels: List[str]) -> str:
        """
        Create a compact fallback sentence from semantic model fields.

        Args:
            scene (str): Scene label returned by the model.
            objects (List[str]): Visible object labels.
            activities (List[str]): Visible activity labels.
            labels (List[str]): General labels.

        Returns:
            str: Compact internal description.
        """
        parts: List[str] = []
        if scene:
            parts.append(scene)
        if objects:
            parts.append("objects: " + ", ".join(objects[:12]))
        if activities:
            parts.append("activities: " + ", ".join(activities[:8]))
        if not parts and labels:
            parts.append(", ".join(labels[:12]))
        return "; ".join(parts)

    def analyze_with_pillow(self, image_path: Path, job: Dict[str, Any]) -> Tuple[Dict[str, Any], str]:
        """
        Produce internal searchable metadata using local Pillow inspection.

        This is a conservative fallback, not an authoritative human caption. It
        records technical and visual descriptors that help search find likely
        images while a stronger local model can be configured later.

        Args:
            image_path (Path): Local path to the downloaded image.
            job (Dict[str, Any]): Server job payload.

        Returns:
            Tuple[Dict[str, Any], str]: Tuple of metadata dictionary and
                searchable text.

        Raises:
            RuntimeError: Raised when Pillow is unavailable or cannot read the
                file.
        """
        if Image is None or ImageOps is None or ImageStat is None:
            raise RuntimeError("Pillow is required for the built-in AI metadata analyzer. Use Install or repair dependencies.")

        image_info = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        with Image.open(image_path) as opened:
            image = ImageOps.exif_transpose(opened)
            width, height = image.size
            mode = image.mode
            fmt = str(opened.format or image_path.suffix.lstrip(".")).upper()
            rgb = image.convert("RGB")
            sample = rgb.copy()
            sample.thumbnail((256, 256))
            grayscale = sample.convert("L")
            luminance_stat = ImageStat.Stat(grayscale)
            rgb_stat = ImageStat.Stat(sample)
            mean_luminance = float(luminance_stat.mean[0]) if luminance_stat.mean else 0.0
            luminance_std = float(luminance_stat.stddev[0]) if luminance_stat.stddev else 0.0
            colorfulness = self.colorfulness_score(rgb_stat)
            dominant_colors = self.dominant_color_labels(sample)

        orientation = self.orientation_label(width, height)
        brightness = self.brightness_label(mean_luminance)
        contrast = self.contrast_label(luminance_std)
        color_label = self.colorfulness_label(colorfulness)
        labels = [orientation, brightness, contrast, color_label]
        labels.extend(dominant_colors)
        labels = [label for label in labels if label]
        internal_description = f"{orientation} image, {brightness}, {contrast}, {color_label}"
        if dominant_colors:
            internal_description += ", dominant colors: " + ", ".join(dominant_colors)

        metadata = {
            "analyzer": "local-pillow-visual-metadata",
            "analyzer_version": "1",
            "internal_description": internal_description,
            "labels": labels,
            "dominant_colors": dominant_colors,
            "technical": {
                "width": width,
                "height": height,
                "aspect_ratio": round(width / height, 4) if height else None,
                "orientation": orientation,
                "mode": mode,
                "format": fmt,
                "mean_luminance": round(mean_luminance, 2),
                "luminance_stddev": round(luminance_std, 2),
                "colorfulness": round(colorfulness, 2),
            },
            "source": {
                "filename": str(image_info.get("filename") or image_path.name),
                "image_id": int(image_info.get("id") or 0),
                "checksum_sha256": str(image_info.get("checksum_sha256") or ""),
            },
        }
        searchable_text = " ".join([internal_description, " ".join(labels), str(image_info.get("filename") or "")])
        return metadata, searchable_text

    def colorfulness_score(self, stat: "ImageStat.Stat") -> float:
        """
        Return a simple RGB dispersion score for colorfulness.

        Args:
            stat (ImageStat.Stat): RGB sample statistics whose mean list holds
                the red, green and blue channel means.

        Returns:
            float: Approximate colorfulness score.
        """
        if not stat.mean or len(stat.mean) < 3:
            return 0.0
        red, green, blue = [float(value) for value in stat.mean[:3]]
        rg = abs(red - green)
        yb = abs(0.5 * (red + green) - blue)
        return math.sqrt((rg * rg) + (yb * yb))

    def dominant_color_labels(self, image: "Image.Image") -> List[str]:
        """
        Return compact color labels from a sampled image.

        Args:
            image (Image.Image): RGB Pillow image supporting convert() and
                getcolors(), which returns count/RGB-triple pairs.

        Returns:
            List[str]: List of color labels suitable for internal search.
        """
        adaptive_palette = getattr(Image, "ADAPTIVE", None)
        if adaptive_palette is None and hasattr(Image, "Palette"):
            adaptive_palette = getattr(Image.Palette, "ADAPTIVE", 1)
        if adaptive_palette is None:
            adaptive_palette = 1
        quantized = image.convert("P", palette=adaptive_palette, colors=5).convert("RGB")
        colors = quantized.getcolors(maxcolors=256 * 256) or []
        colors = sorted(colors, key=lambda item: item[0], reverse=True)[:5]
        labels: List[str] = []
        for _count, rgb in colors:
            label = self.rgb_label(rgb)
            if label and label not in labels:
                labels.append(label)
        return labels[:4]

    def rgb_label(self, rgb: Tuple[int, int, int]) -> str:
        """
        Convert one RGB color into a coarse human-readable label.

        Args:
            rgb (Tuple[int, int, int]): Red, green, and blue tuple.

        Returns:
            str: Coarse color label.
        """
        red, green, blue = rgb
        maximum = max(red, green, blue)
        minimum = min(red, green, blue)
        if maximum < 45:
            return "black"
        if minimum > 215:
            return "white"
        if maximum - minimum < 24:
            if maximum < 95:
                return "dark gray"
            if maximum > 175:
                return "light gray"
            return "gray"
        if red >= green and red >= blue:
            if green > 140 and blue < 110:
                return "yellow"
            if blue > 120 and green < 120:
                return "magenta"
            return "red"
        if green >= red and green >= blue:
            if red > 130 and blue < 110:
                return "yellow-green"
            if blue > 120:
                return "cyan"
            return "green"
        if red > 120 and green < 120:
            return "purple"
        return "blue"

    def orientation_label(self, width: int, height: int) -> str:
        """
        Return landscape, portrait, square, or panorama from image dimensions.

        Args:
            width (int): Image width in pixels.
            height (int): Image height in pixels.

        Returns:
            str: Orientation label.
        """
        if width <= 0 or height <= 0:
            return "unknown orientation"
        ratio = width / height
        if ratio > 2.0:
            return "panorama"
        if ratio > 1.15:
            return "landscape"
        if ratio < 0.87:
            return "portrait"
        return "square"

    def brightness_label(self, luminance: float) -> str:
        """
        Return a coarse brightness label.

        Args:
            luminance (float): Mean luminance from 0 to 255.

        Returns:
            str: Brightness label.
        """
        if luminance < 55:
            return "dark"
        if luminance < 115:
            return "dim"
        if luminance < 185:
            return "balanced brightness"
        return "bright"

    def contrast_label(self, stddev: float) -> str:
        """
        Return a coarse contrast label.

        Args:
            stddev (float): Luminance standard deviation.

        Returns:
            str: Contrast label.
        """
        if stddev < 28:
            return "low contrast"
        if stddev > 72:
            return "high contrast"
        return "medium contrast"

    def colorfulness_label(self, score: float) -> str:
        """
        Return a coarse colorfulness label.

        Args:
            score (float): Approximate colorfulness score.

        Returns:
            str: Colorfulness label.
        """
        if score < 18:
            return "muted colors"
        if score > 70:
            return "very colorful"
        return "colorful"


class AIAnalysisWorkerThread(threading.Thread):
    """
    Background worker that asks PHP Gallery for server-assigned AI jobs.

    It never scans the gallery by itself. The server is authoritative: each poll
    either returns one claimed job with a lease token or no job. This makes it
    safe to run several companion app instances against the same gallery key.
    """

    def __init__(self, config: WatcherConfig, events: "queue.Queue[Tuple[str, str]]",
                 gui_admission: Optional[Tuple[GuiModeOwner, ModeConfigPort]] = None) -> None:
        """
        Create a background AI-analysis worker.

        Args:
            config (WatcherConfig): Runtime configuration captured when the
                worker starts.
            events (queue.Queue[Tuple[str, str]]): Queue used to send status
                lines back to the UI.
            gui_admission (Optional[Tuple[GuiModeOwner, ModeConfigPort]]): GUI
                owner and port for the exact captured AI configuration.

        Returns:
            None: Create a background AI-analysis worker.
        """
        super().__init__(name="PHPGalleryAIAnalysisWorker", daemon=True)
        self.config = config
        self.events = events
        self._gui_admission = gui_admission
        self._gui_work: Optional[CoordinatedWork] = None
        self._terminal_refused = threading.Event()
        self.stop_event = threading.Event()
        self.worker_id = ai_worker_id()
        self.progress_lock = threading.Lock()
        self.progress_percent = 0
        self.progress_message = "Starting."

    def stop(self) -> None:
        """
        Request the worker loop to stop.
        """
        self.stop_event.set()

    def _request_checkpoint(self) -> None:
        """Stop every new AI request after a shared terminal refusal.

        Returns:
            None: The AI job and any GUI work remain admitted.

        Raises:
            GalleryKeyRedirectError: A heartbeat already refused a redirect.
            ModeCoordinatorError: Current mode evidence cannot admit a request.
        """
        if self._terminal_refused.is_set():
            raise GalleryKeyRedirectError('Gallery authorization is unavailable.')
        if self._gui_work is not None:
            self._gui_work.checkpoint()

    def emit(self, level: str, message: str) -> None:
        """
        Send one worker event to the UI and file log.

        Args:
            level (str): Severity label.
            message (str): Human-readable status text.

        Returns:
            None: Send one worker event to the UI and file log.
        """
        logging.log(logging.ERROR if level == "error" else logging.INFO, "%s", message)
        self.events.put((level, message))

    def update_progress(self, percent: int, message: str) -> None:
        """
        Store the current progress text used by heartbeat requests.

        Args:
            percent (int): Integer progress from 0 to 99 while running.
            message (str): Short status text.

        Returns:
            None: Store the current progress text used by heartbeat requests.
        """
        with self.progress_lock:
            self.progress_percent = max(0, min(99, int(percent)))
            self.progress_message = message

    def current_progress(self) -> Tuple[int, str]:
        """
        Return the latest heartbeat progress tuple.

        Returns:
            Tuple[int, str]: Progress percent and message.
        """
        with self.progress_lock:
            return self.progress_percent, self.progress_message

    def run(self) -> None:
        """Hold the GUI work lease across AI jobs and their heartbeats.

        Returns:
            None: Stops on unavailable mode and releases its own work lease.
        """
        try:
            if self._gui_admission is not None:
                owner, port = self._gui_admission
                self._gui_work = owner.acquire('legacy', port)
            self._run_owned()
        except (ModeCoordinatorError, GalleryKeyRedirectError):
            self._terminal_refused.set()
            self.emit('error', 'Gallery authorization could not be verified; AI worker stopped.')
        finally:
            if self._gui_work is not None:
                self._gui_work.close()

    def _run_owned(self) -> None:
        """Poll the server until stopped while retaining admitted work.

        Returns:
            None: Processes claimed jobs without admitting another mode.
        """
        enter_background_thread_mode()
        upload_url = normalize_upload_url(self.config.gallery_url)
        api_key = self.config.api_key.strip()
        if not self.config.ai_worker_enabled:
            self.emit("info", "AI metadata worker is disabled in configuration.")
            return
        if not upload_url or not api_key:
            self.emit("error", "AI metadata worker needs Gallery URL and API key.")
            return

        poll_seconds = max(AI_WORKER_MIN_POLL_SECONDS, float(self.config.ai_worker_poll_seconds or DEFAULT_AI_WORKER_POLL_SECONDS))
        lease_seconds = max(60, min(AI_WORKER_MAX_LEASE_SECONDS, int(self.config.ai_worker_lease_seconds or DEFAULT_AI_WORKER_LEASE_SECONDS)))
        model_name = self.config.ai_model_name.strip() or AI_MODEL_NAME_DEFAULT
        model_version = self.config.ai_model_version.strip() or AI_MODEL_VERSION_DEFAULT
        self.emit("info", f"AI metadata worker started as {self.worker_id} using {model_name} {model_version}.")

        while not self.stop_event.is_set():
            try:
                claimed = post_json(
                    upload_url,
                    api_key,
                    {
                        "action": "ai_next_job",
                        "worker_id": self.worker_id,
                        "model_name": model_name,
                        "model_version": model_version,
                        "lease_seconds": lease_seconds,
                    },
                    timeout_seconds=60,
                    before_request=self._request_checkpoint,
                )
                job = claimed.get("job")
                if not isinstance(job, dict):
                    wait_seconds = float(claimed.get("poll_after_seconds") or poll_seconds)
                    self.update_progress(0, "No job available.")
                    self.stop_event.wait(max(AI_WORKER_MIN_POLL_SECONDS, wait_seconds))
                    continue
                self.process_job(upload_url, api_key, job, lease_seconds)
            except (ModeCoordinatorError, GalleryKeyRedirectError):
                self._terminal_refused.set()
                raise
            except Exception as exc:  # noqa: BLE001
                self.emit("warning", f"AI metadata worker poll failed: {exc}")
                self.stop_event.wait(poll_seconds)

        self.emit("info", "AI metadata worker stopped.")

    def process_job(self, upload_url: str, api_key: str, job: Dict[str, Any], lease_seconds: int) -> None:
        """
        Download, analyze, and report one claimed AI job.

        Args:
            upload_url (str): Normalized upload automation endpoint.
            api_key (str): Gallery-scoped API key.
            job (Dict[str, Any]): Claimed job payload from the server.
            lease_seconds (int): Requested lease extension length.

        Returns:
            None: Download, analyze, and report one claimed AI job.
        """
        job_id = int(job.get("job_id") or 0)
        claim_token = str(job.get("claim_token") or "")
        image = job.get("image", {}) if isinstance(job.get("image"), dict) else {}
        filename = str(image.get("filename") or f"job-{job_id}.img")
        if job_id <= 0 or not claim_token:
            self.emit("warning", "AI metadata worker received an invalid job payload.")
            return

        heartbeat = PeriodicAIHeartbeat(
            upload_url, api_key, job_id, claim_token, lease_seconds,
            self.current_progress, self.emit,
            before_request=self._request_checkpoint,
            terminal_event=self._terminal_refused,
        )
        temp_dir = Path(tempfile.mkdtemp(prefix="php_gallery_ai_"))
        heartbeat_started = False
        try:
            downloaded_path = temp_dir / ("source" + extension_for_content_type(str(image.get("mime_type") or ""), filename))
            heartbeat.start()
            heartbeat_started = True
            self.update_progress(5, "Downloading assigned image.")
            self.emit("info", f"AI metadata worker claimed job {job_id} for {filename}.")
            content_type, downloaded = post_binary_to_file(
                upload_url,
                api_key,
                {
                    "action": "ai_asset",
                    "job_id": str(job_id),
                    "claim_token": claim_token,
                },
                downloaded_path,
                timeout_seconds=DEFAULT_TIMEOUT_SECONDS,
                before_request=self._request_checkpoint,
            )
            if downloaded <= 0:
                raise RuntimeError("Server returned an empty image asset.")
            corrected_suffix = extension_for_content_type(content_type, filename)
            if downloaded_path.suffix.lower() != corrected_suffix:
                corrected_path = temp_dir / ("source" + corrected_suffix)
                downloaded_path.rename(corrected_path)
                downloaded_path = corrected_path

            self.update_progress(35, "Analyzing image locally.")
            analyzer = AIImageAnalyzer(self.config)
            metadata, searchable_text = analyzer.analyze(downloaded_path, job)
            self.update_progress(90, "Reporting AI metadata.")
            post_json(
                upload_url,
                api_key,
                {
                    "action": "ai_complete",
                    "job_id": job_id,
                    "claim_token": claim_token,
                    "status": "succeeded",
                    "metadata": metadata,
                    "searchable_text": searchable_text,
                },
                timeout_seconds=60,
                before_request=self._request_checkpoint,
            )
            self.update_progress(100, "Completed.")
            self.emit("info", f"AI metadata stored for {filename}.")
        except (ModeCoordinatorError, GalleryKeyRedirectError):
            self._terminal_refused.set()
            raise
        except Exception as exc:  # noqa: BLE001
            self.update_progress(0, "Reporting failure.")
            self.report_failure(upload_url, api_key, job_id, claim_token, filename, exc)
        finally:
            heartbeat.stop()
            if heartbeat_started:
                # The parent stays active until the last heartbeat request exits.
                # Network and DNS delays can extend this wait beyond socket timeouts.
                heartbeat.join()
            shutil.rmtree(temp_dir, ignore_errors=True)

    def report_failure(self, upload_url: str, api_key: str, job_id: int, claim_token: str, filename: str, exc: Exception) -> None:
        """
        Report one failed job to the server without hiding local diagnostics.

        Args:
            upload_url (str): Normalized upload automation endpoint.
            api_key (str): Gallery-scoped API key.
            job_id (int): Failed server job id.
            claim_token (str): Claim token returned with the job.
            filename (str): Original filename for readable logging.
            exc (Exception): Exception raised during processing.

        Returns:
            None: Report one failed job to the server without hiding local
                diagnostics.
        """
        message = str(exc)
        try:
            post_json(
                upload_url,
                api_key,
                {
                    "action": "ai_complete",
                    "job_id": job_id,
                    "claim_token": claim_token,
                    "status": "failed",
                    "error": message,
                },
                timeout_seconds=60,
                before_request=self._request_checkpoint,
            )
        except (ModeCoordinatorError, GalleryKeyRedirectError):
            self._terminal_refused.set()
            raise
        except Exception as report_exc:  # noqa: BLE001
            self.emit("warning", f"AI metadata worker could not report failure for {filename}: {report_exc}")
        self.emit("warning", f"AI metadata worker failed for {filename}: {message}")


# Poll the separate update-pause signal without changing Stop's cutoff fence.
# Type: float. Units: seconds. Scope: one watcher scan-interval wait.
# Consumers: WatcherThread._run_owned. Rationale: bounded responsiveness while
# keeping update pause independent from drain_event and stop_event.
WATCHER_UPDATE_PAUSE_POLL_SECONDS = 0.1
# Require the host to durably consume a one-use update checkpoint before a
# resumed watcher may begin its first scan or dispatch.
# Type: float; Units: seconds; Scope: one prepared Watcher continuation.
# Consumers: WatcherThread._run_owned. Rationale: 15 seconds covers ordinary
# Tk event handling while bounding a lost host acknowledgement.
WATCHER_CONTINUATION_ADMISSION_TIMEOUT_SECONDS = 15.0
# Structural Win32 FILE_ATTRIBUTE_REPARSE_POINT mask used by path checks.
# Type: int. Units: attribute bitmask. Scope: update continuation paths.
# Consumers: WatcherThread._validate_continuation_path. Rationale: older Python
# builds do not expose the stat module constant, so retain the documented ABI bit.
WATCHER_REPARSE_POINT_ATTRIBUTE = getattr(stat, 'FILE_ATTRIBUTE_REPARSE_POINT', 0x0400)


class WatcherThread(threading.Thread):
    """
    Background worker that scans, uploads, and reports status events.

    The worker uses polling rather than OS-specific filesystem notifications.
    Polling is less elegant, but it is robust on Windows network folders, synced
    folders, camera import folders, and other locations where notification APIs
    can behave inconsistently.
    """

    def __init__(
        self, config: WatcherConfig, events: "queue.Queue[Tuple[str, str]]",
        linked_target: Optional[LinkedUploadTarget] = None,
        once_work: Optional[CoordinatedWork] = None,
        gui_admission: Optional[Tuple[GuiModeOwner, ModeConfigPort]] = None,
        watch_folder_override: Optional[Path] = None,
        watch_recursive_override: Optional[bool] = None,
    ) -> None:
        """
        Create a watcher worker.

        Args:
            config (WatcherConfig): Runtime configuration captured when the
                worker starts.
            events (queue.Queue[Tuple[str, str]]): Thread-safe queue used to
                send status messages to the GUI.
            linked_target (Optional[LinkedUploadTarget]): Captured delegated
                destination, or None for the existing API-key workflow.
            once_work (Optional[CoordinatedWork]): Admitted once-mode work to
                recheck before each request; None for ordinary GUI workers.
            gui_admission (Optional[Tuple[GuiModeOwner, ModeConfigPort]]): GUI
                owner and port for this exact captured worker configuration.
            watch_folder_override (Optional[Path]): Previously admitted drain
                folder, or None for the captured configuration folder.
            watch_recursive_override (Optional[bool]): Previously admitted drain
                recursion choice, or None for the captured setting.

        Returns:
            None: Create a watcher worker.
        """
        super().__init__(daemon=True)
        self.config = config
        self.linked_target = linked_target
        self._once_work = once_work
        self._gui_admission = gui_admission
        self._watch_folder_override = watch_folder_override
        self._watch_recursive_override = watch_recursive_override
        self.events = events
        self.stop_event = threading.Event()
        self.update_pause_event = threading.Event()
        self._linked_lease = (LinkedTargetLease(CONFIG_DIR, linked_target.binding())
                              if linked_target is not None else None)
        # New legacy work never reads or rewrites the old unbound history.
        self.state: Optional[LinkedUploadState] = None
        self.stability = FileStabilityTracker(config.stable_seconds)
        self.remote_inventory = RemoteInventorySession(
            config.inventory_refresh_seconds, self.emit, linked_target,
            once_work.checkpoint if once_work is not None else None,
        )
        self.remote_skipped_paths: Set[Tuple[Path, str]] = set()
        self._legacy_failed_paths: Dict[Tuple[Path, str], Tuple[int, float]] = {}
        self.initial_paths: Set[Path] = set()
        self._admission_lock = threading.RLock()
        self.initial_captured = False
        self.accepted_paths: Set[Path] = set()
        self.drain_event = threading.Event()
        self.drain_only = False
        self.drain_manifest: Optional[Path] = None
        self.drain_requested_at = 0.0
        self.drain_started_monotonic = 0.0
        self.drain_error = ""
        self._continuation_gate: threading.Event | None = None
        self._diagnostic_phase = 'startup'
        self.simconnect_client = SimConnectCameraClient(config.simconnect_dll_path, event_sink=self.emit)
        # Publish a complete snapshot by assignment; the UI never probes SimConnect.
        self.simconnect_diagnostics = self.simconnect_client.diagnostics_snapshot()

    def stop(self) -> None:
        """Request cancellation while retaining the durable photograph queue.

        Returns:
            None: Stops observation without acknowledging unfinished files.
        """
        with self._admission_lock:
            self.stop_event.set()

    def request_update_pause(self) -> None:
        """Finish the current photo operation and pause before another path.

        Returns:
            None: Requests a resumable pause without setting Stop cutoff,
                freezing the queue or interrupting an in-flight acknowledgement.
        """
        pause_event = getattr(self, 'update_pause_event', None)
        if pause_event is not None:
            lock = getattr(self, '_admission_lock', None)
            if lock is None:
                pause_event.set()
            else:
                with lock:
                    pause_event.set()

    def admit_continuation(self) -> bool:
        """Release a resumed watcher only after its nonce is durably consumed.

        Returns:
            bool: True when a continuation gate opened; False when Stop,
                drain or update pause already prevents safe admission. An
                ordinary watcher without a gate is admitted unchanged.
        """
        gate = getattr(self, '_continuation_gate', None)
        if gate is None:
            return True
        with self._admission_lock:
            if gate.is_set():
                return True
            if (self.stop_event.is_set() or self.drain_event.is_set()
                    or self._update_pause_requested()):
                return False
            gate.set()
        return True

    def _update_pause_requested(self) -> bool:
        """Read the independent update pause without changing Stop state.

        Returns:
            bool: True when the worker must leave its current scan boundary.
                Missing state is treated as unpaused for older isolated fixtures.
        """
        pause_event = getattr(self, 'update_pause_event', None)
        return pause_event is not None and pause_event.is_set()

    @staticmethod
    def _validate_continuation_path(
        value: str, folder: Path, recursive: bool,
    ) -> Path:
        """Validate one persisted path against the original scan boundary.

        Args:
            value (str): Absolute path recorded by the original watcher.
            folder (Path): Exact original watched folder.
            recursive (bool): Original recursion setting.

        Returns:
            Path: Original lexical path after proving it resolves within scope.

        Raises:
            ValueError: The path is relative, redirected, nested out of scope
                or cannot be verified through its existing components.
        """
        candidate = Path(value)
        try:
            if not candidate.is_absolute():
                raise ValueError("Continuation path is not absolute.")
            root = folder.resolve(strict=True)
            if (folder.is_symlink()
                    or not folder.is_dir()
                    or getattr(folder.lstat(), 'st_file_attributes', 0)
                    & WATCHER_REPARSE_POINT_ATTRIBUTE):
                raise ValueError("Continuation folder is redirected.")
            resolved = candidate.resolve(strict=False)
            if (resolved == root or not resolved.is_relative_to(root)
                    or (not recursive and resolved.parent != root)):
                raise ValueError("Continuation path escaped its original folder.")
            # Resolve containment canonically, but walk the persisted lexical
            # spelling. Windows may expand an 8.3 ancestor in `resolve()`.
            lexical_root = Path(os.path.abspath(folder))
            cursor = Path(os.path.abspath(candidate))
            if cursor != lexical_root and lexical_root not in cursor.parents:
                raise ValueError("Continuation path escaped its original folder.")
            while True:
                try:
                    info = cursor.lstat()
                except FileNotFoundError:
                    pass
                else:
                    if (stat.S_ISLNK(info.st_mode)
                            or getattr(info, 'st_file_attributes', 0)
                            & WATCHER_REPARSE_POINT_ATTRIBUTE):
                        raise ValueError("Continuation path is redirected.")
                if cursor == lexical_root:
                    break
                cursor = cursor.parent
            return candidate
        except (OSError, RuntimeError):
            raise ValueError("Continuation path could not be verified.") from None

    def configure_drain(
        self, directory: Path, initial: Iterable[Path] | None,
        resume: bool = False, *, continuation: bool = False,
    ) -> None:
        """Persist photograph admission before starting the watcher thread.

        Args:
            directory (Path): Connection-bound flight session workspace.
            initial (Iterable[Path] | None): Captured startup exclusions, or
                None to capture the initial folder on the worker before REC.
            resume (bool): Restore only a previously frozen accepted file set.
            continuation (bool): Restore an exact unfinished update continuation
                while retaining its unfrozen original admission boundary.

        Returns:
            None: Binds a durable queue without scanning historical files.

        Raises:
            ValueError: Resume state, folder, recursion or source paths do not
                match the original captured boundary.
        """
        if continuation and not resume:
            raise ValueError('Update continuation requires an existing drain manifest.')
        continuation_row: Dict[str, Any] | None = None
        if continuation:
            manifest = directory / "watch-drain.json"
            continuation_row = read_document(manifest)
            captured_folder = (
                getattr(self, '_watch_folder_override', None)
                or Path(self.config.watched_folder)
            )
            recursive_override = getattr(self, '_watch_recursive_override', None)
            captured_recursive = (
                self.config.watch_recursive
                if recursive_override is None else recursive_override
            )
            stored_folder = continuation_row.get('folder')
            # Windows Path equality normalizes slash spelling but keeps this
            # exact lexical boundary; canonical containment and reparse checks
            # below remain independent and unchanged.
            same_folder = (
                isinstance(stored_folder, str)
                and Path(stored_folder) == Path(captured_folder)
            )
            cutoff = continuation_row.get('cutoff')
            initial_values = continuation_row.get('initial')
            pending_values = continuation_row.get('pending')
            if (type(continuation_row.get('schema_version')) is not int
                    or continuation_row.get('schema_version') != 1
                    or not same_folder
                    or type(captured_recursive) is not bool
                    or continuation_row.get('recursive') is not captured_recursive
                    or continuation_row.get('initial_captured') is not True
                    or continuation_row.get('frozen') is not False
                    or type(cutoff) not in (int, float) or cutoff != 0
                    or continuation_row.get('error') != ''):
                raise ValueError('Update continuation no longer matches its original boundary.')
            if (not isinstance(initial_values, list)
                    or any(not isinstance(value, str) for value in initial_values)
                    or not isinstance(pending_values, list)
                    or any(not isinstance(value, str) for value in pending_values)
                    or len(set(initial_values)) != len(initial_values)
                    or len(set(pending_values)) != len(pending_values)
                    or set(initial_values).intersection(pending_values)):
                raise ValueError('Update continuation path history needs review.')
            for value in (*initial_values, *pending_values):
                self._validate_continuation_path(
                    value, captured_folder, captured_recursive,
                )
        if self.linked_target is not None:
            if self._linked_lease is None:
                raise ValueError('Linked upload lease is unavailable.')
            self._linked_lease.acquire()
            try:
                state = LinkedUploadState(self.linked_target)
                if resume and not state.preexisting:
                    raise ValueError('Linked upload history is missing; review before recovery.')
                if not resume:
                    # Record destination-owned history before accepting a file.
                    state.save()
                self.state = state
            except Exception:
                self._linked_lease.release()
                raise
        self.drain_manifest = directory / "watch-drain.json"
        self.initial_paths = set(initial) if initial is not None else set()
        self.initial_captured = initial is not None or continuation
        self._continuation_gate = threading.Event() if continuation else None
        if resume:
            row = (continuation_row if continuation_row is not None
                   else read_document(self.drain_manifest))
            if row.get("initial_captured", True) is not True:
                raise ValueError("Watcher startup did not finish; no photos were accepted.")
            paths = row.get("pending")
            if not isinstance(paths, list):
                raise ValueError("Pending photograph queue is malformed.")
            folder = (getattr(self, '_watch_folder_override', None)
                      or Path(self.config.watched_folder)).resolve()
            for value in paths:
                if not isinstance(value, str):
                    raise ValueError("Pending photograph path is malformed.")
                path = Path(value)
                if path.is_symlink() or not path.resolve().is_relative_to(folder):
                    raise ValueError("Pending photograph escaped its folder.")
                self.accepted_paths.add(path)
            initial_values = row.get("initial", [])
            if not isinstance(initial_values, list) or any(
                not isinstance(value, str) for value in initial_values
            ):
                raise ValueError("Initial photograph boundary is malformed.")
            self.initial_paths = {Path(value) for value in initial_values}
            self.drain_only = False if continuation else row.get("frozen") is True
            if continuation:
                self.drain_event.clear()
                self.drain_requested_at = 0.0
                self.drain_started_monotonic = 0.0
            else:
                self.drain_event.set()
                self.drain_requested_at = float(
                    row.get("cutoff") or row.get("checkpoint") or time.time()
                )
                self.drain_started_monotonic = time.monotonic()
        self.persist_drain()

    def persist_drain(self) -> None:
        """Atomically retain acceptance, startup exclusions and Stop admission.

        Returns:
            None: Writes no credentials and never acknowledges a failed file.
        """
        with self._admission_lock:
            if self.drain_manifest is not None:
                atomic_write_json(self.drain_manifest, {
                    "schema_version": 1,  # First explicit queue wire format.
                    "folder": str(getattr(self, '_watch_folder_override', None)
                                  or self.config.watched_folder),
                    "recursive": (self.config.watch_recursive
                                  if getattr(self, '_watch_recursive_override', None) is None
                                  else self._watch_recursive_override),
                    "initial": sorted(str(path) for path in self.initial_paths),
                    "pending": sorted(str(path) for path in self.accepted_paths),
                    # An interrupted initial scan accepted nothing. Keeping
                    # that empty boundary frozen also makes older readers
                    # retain historical files instead of discovering them.
                    "initial_captured": self.initial_captured,
                    "frozen": self.drain_only or not self.initial_captured,
                    "cutoff": self.drain_requested_at,
                    "checkpoint": time.time(),
                    "error": self.drain_error,
                })

    def acknowledge_photo(self, path: Path) -> None:
        """Remove one file only after upload history or gallery evidence agrees.

        Args:
            path (Path): Accepted local photograph with confirmed disposition.

        Returns:
            None: Advances the recoverable queue and reports its size.
        """
        with self._admission_lock:
            self.accepted_paths.discard(path)
            self.persist_drain()
        self.emit_queue_snapshot()

    def emit_queue_snapshot(self) -> None:
        """Publish the current accepted-photo count from its owning lock.

        Returns:
            None: Queues a Watcher-only count snapshot for the Tk event owner.
        """
        with self._admission_lock:
            queued_count = len(self.accepted_paths)
        self.events.put({
            "kind": "watch_queue",
            "payload": {"worker": self, "queued_count": queued_count},
        })

    def request_drain(self) -> None:
        """Stop discovery and safely finish photographs accepted by the boundary.

        Returns:
            None: Records one cutoff without interrupting current network work.
        """
        with self._admission_lock:
            if not self.drain_event.is_set():
                self.drain_requested_at = time.time()
                self.drain_started_monotonic = time.monotonic()
                self.drain_event.set()
                # Persist intent before returning to Tk; the worker performs
                # the potentially long final folder reconciliation off-thread.
                self.persist_drain()

    def emit(self, level: str, message: str) -> None:
        """
        Send a message to the UI queue and the persistent log file.

        Args:
            level (str): Logging level name such as info, warning, or error.
            message (str): Human-readable status message.

        Returns:
            None: Send a message to the UI queue and the persistent log file.
        """
        self.events.put((level, message))
        log_method = getattr(logging, level if level in {"debug", "info", "warning", "error"} else "info")
        log_method(message)

    def run(self) -> None:
        """Run the watcher while retaining its target lease through shutdown.

        Returns:
            None: Reports unsafe redirects, retains pending work and releases
                an acquired lease after the worker has stopped.
        """
        gui_work: Optional[CoordinatedWork] = None
        try:
            gui_admission = getattr(self, '_gui_admission', None)
            if gui_admission is not None:
                owner, port = gui_admission
                kind = 'linked' if self.linked_target is not None else 'legacy'
                gui_work = owner.acquire(kind, port)
                self._once_work = gui_work
                self.remote_inventory.before_request = gui_work.checkpoint
            self._run_owned()
        except (GalleryKeyRedirectError, ModeCoordinatorError) as exc:
            self.drain_error = ('Gallery API redirect was refused; pending photographs are retained.'
                                if isinstance(exc, GalleryKeyRedirectError) else
                                'Upload mode could not be verified; pending photographs are retained.')
            self.stop_event.set()
            try:
                self.persist_drain()
            finally:
                self.emit('error', self.drain_error)
        except Exception as exc:  # noqa: BLE001
            self.drain_error = self._unexpected_error_summary(exc)
            self.stop_event.set()
            try:
                self.persist_drain()
            except Exception:  # noqa: BLE001
                self.drain_error += ' Recovery state could not be persisted.'
            finally:
                self.emit('error', self.drain_error)
        finally:
            try:
                if gui_work is not None:
                    gui_work.close()
            finally:
                try:
                    if self._linked_lease is not None:
                        self._linked_lease.release()
                finally:
                    self.events.put({
                        "kind": "watcher_start_finished",
                        "payload": {"worker": self},
                    })

    def _unexpected_error_summary(self, error: BaseException) -> str:
        """Describe an unexpected worker error without its sensitive message.

        Args:
            error (BaseException): Exception caught at the watcher owner boundary.

        Returns:
            str: Fixed phase, safe class name and last local source location.
        """
        phase = getattr(self, '_diagnostic_phase', 'worker')
        if phase not in {
            'startup', 'initial_scan', 'scan', 'candidate', 'inventory',
            'upload', 'acknowledgement', 'worker',
        }:
            phase = 'worker'
        error_type = type(error).__name__
        if re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,63}', error_type) is None:
            error_type = 'Exception'
        source = 'unknown'
        current = error.__traceback__
        while current is not None:
            code = current.tb_frame.f_code
            if (Path(code.co_filename).name == 'gallery_watch_upload.pyw'
                    and current.tb_frame.f_locals.get('self') is self):
                function = code.co_name
                if re.fullmatch(r'[A-Za-z_][A-Za-z0-9_]{0,63}', function):
                    source = (
                        f'gallery_watch_upload.pyw:WatcherThread.{function}:'
                        f'{current.tb_lineno}'
                    )
            current = current.tb_next
        return (
            f'Watcher failed phase={phase} error_type={error_type} '
            f'source={source}; pending photographs are retained.'
        )

    def _run_owned(self) -> None:
        """Observe new photographs, then drain a frozen recoverable queue.

        Returns:
            None: Pending/error state survives cancellation, timeout and restart.
        """
        folder = (getattr(self, '_watch_folder_override', None)
                  or Path(self.config.watched_folder))
        self._diagnostic_phase = 'startup'
        recursive_override = getattr(self, '_watch_recursive_override', None)
        recursive = (self.config.watch_recursive if recursive_override is None
                     else recursive_override)
        if self.linked_target is not None and self.state is None:
            self.drain_error = 'Linked upload recovery state is unavailable.'
            self.emit('error', self.drain_error)
            return
        upload_url = (self.linked_target.issuer if self.linked_target is not None
                      else normalize_upload_url(self.config.gallery_url))
        if (not folder.is_dir() or not upload_url
                or (self.linked_target is None and not self.config.api_key.strip())):
            self.drain_error = "Readable watch folder and saved connection required."
            self.persist_drain()
            self.emit("error", self.drain_error)
            return
        if not self.initial_captured:
            if self._update_pause_requested():
                self.persist_drain()
                return
            try:
                self._diagnostic_phase = 'initial_scan'
                existing = set(iter_candidate_files(folder, recursive))
                with self._admission_lock:
                    self.initial_paths = existing
                    self.initial_captured = True
                    # Stop during startup admits no new screenshots. The
                    # complete initial snapshot must never become its queue.
                    if self.drain_event.is_set() or self.stop_event.is_set():
                        self.drain_only = True
                    self.persist_drain()
            except (OSError, ValueError, RuntimeError):
                self.drain_error = "Could not read the screenshot folder. Check access and retry."
                self.stop_event.set()
                self.persist_drain()
                self.emit("error", self.drain_error)
                return
        if not self.drain_only and not self.stop_event.is_set():
            self.events.put({
                "kind": "watcher_start_ready",
                "payload": {"worker": self, "ignored": len(self.initial_paths)},
            })
        gate = getattr(self, '_continuation_gate', None)
        if gate is not None:
            self._diagnostic_phase = 'continuation_admission'
            deadline = (
                time.monotonic()
                + WATCHER_CONTINUATION_ADMISSION_TIMEOUT_SECONDS
            )
            while not gate.is_set():
                if (self.stop_event.is_set() or self.drain_event.is_set()
                        or self._update_pause_requested()):
                    self.persist_drain()
                    return
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    with self._admission_lock:
                        if gate.is_set():
                            continue
                        self.drain_error = (
                            'Update resume confirmation timed out; pending '
                            'photographs are retained.'
                        )
                        self.stop_event.set()
                    self.persist_drain()
                    self.emit('error', self.drain_error)
                    return
                gate.wait(min(
                    remaining, WATCHER_UPDATE_PAUSE_POLL_SECONDS,
                ))
            if (self.stop_event.is_set() or self.drain_event.is_set()
                    or self._update_pause_requested()):
                self.persist_drain()
                return
        self.emit("info", f"Watching {folder}")
        self.emit("info", f"Upload endpoint: {upload_url}")
        self.emit(
            "info",
            f"Remote inventory reconnect interval: {self.config.inventory_refresh_seconds:g} seconds.",
        )
        if self.config.attach_sim_camera_metadata:
            self.emit(
                "info",
                "[simconnect] Flight Simulator location metadata enabled. "
                + self.simconnect_client.dll_resolution_message(),
            )
        else:
            self.emit(
                "info",
                "[simconnect] Flight Simulator location metadata disabled.",
            )
        self.emit(
            "info",
            f"Ignoring {len(self.initial_paths)} existing image file(s).",
        )
        while (not self.stop_event.is_set()
               and not self._update_pause_requested()):
            if (self.linked_target is not None
                    and not self.linked_target.session.can_upload()):
                self.drain_error = "Gallery access expired. Reconnect to resume uploads."
                self.emit("warning", self.drain_error)
                break
            if self.drain_event.is_set() and not self.drain_only:
                # On Windows ctime is creation time: include files already
                # being copied at Stop, but never admit later creations.
                # Previously observed files remain accepted even if modified.
                for path in iter_candidate_files(folder, recursive):
                    try:
                        before_stop = path.stat().st_ctime <= self.drain_requested_at
                    except OSError:
                        before_stop = False
                    if path not in self.initial_paths and before_stop:
                        with self._admission_lock:
                            self.accepted_paths.add(path)
                with self._admission_lock:
                    self.drain_only = True
                    self.persist_drain()
            self._diagnostic_phase = 'scan'
            self.scan_once(folder, upload_url)
            with self._admission_lock:
                queued_count = len(self.accepted_paths)
            self.events.put({
                "kind": "watch_scan",
                "payload": {
                    "worker": self,
                    "timestamp": time.time(),
                    "queued_count": queued_count,
                },
            })
            if self.drain_only:
                if not self.accepted_paths:
                    break
                if (
                    time.monotonic() - self.drain_started_monotonic
                    >= FINALIZATION_DRAIN_SECONDS
                ):
                    self.drain_error = (
                        "Accepted photographs remain unfinished; retry required."
                    )
                    self.emit("error", self.drain_error)
                    break
            deadline = time.monotonic() + max(
                0.2, self.config.scan_interval_seconds,
            )
            while (not self.stop_event.is_set()
                   and not self._update_pause_requested()):
                remaining = deadline - time.monotonic()
                if remaining <= 0:
                    break
                self.stop_event.wait(min(
                    remaining, WATCHER_UPDATE_PAUSE_POLL_SECONDS,
                ))
        self.persist_drain()
        self.emit("info", "Watcher stopped; pending photographs are retained.")

    def scan_once(self, folder: Path, upload_url: str) -> None:
        """
        Scan the watched folder once and process files that are ready.

        Args:
            folder (Path): Folder to scan.
            upload_url (str): Normalized endpoint used for uploads.

        Returns:
            None: Scan the watched folder once and process files that are
                ready.

        Raises:
            ModeCoordinatorError: Once-mode evidence changed before a request.
            GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
        """
        candidates = (
            sorted(self.accepted_paths) if self.drain_only
            else iter_candidate_files(folder, (
                self.config.watch_recursive if getattr(self, '_watch_recursive_override', None) is None
                else self._watch_recursive_override
            ))
        )
        admitted: List[Path] = []
        if not hasattr(self, '_legacy_failed_paths'):
            self._legacy_failed_paths = {}
        with self._admission_lock:
            if self._update_pause_requested():
                return
            for path in candidates:
                if path in self.initial_paths:
                    continue
                if self.drain_event.is_set() and path not in self.accepted_paths:
                    try:
                        if path.stat().st_ctime > self.drain_requested_at:
                            continue
                    except OSError:
                        continue
                admitted.append(path)
            self.accepted_paths.update(admitted)
            self.persist_drain()
        self.emit_queue_snapshot()
        for path in admitted:
            if self.stop_event.is_set() or self._update_pause_requested():
                return
            self._diagnostic_phase = 'candidate'

            # Files already present when the worker started are intentionally
            # ignored. The watched folder can contain historical images without
            # causing a mass upload on startup.
            if path in self.initial_paths:
                continue

            if self.drain_only and not path.is_file():
                self.drain_error = (
                    "A pending photo is unavailable. Restore it or keep files locally."
                )
                self.stop_event.set()
                self.persist_drain()
                self.emit("warning", self.drain_error)
                return

            if not self.stability.stable(path):
                continue

            try:
                file_hash = sha256_file(path)
                size = path.stat().st_size
                linked_source = capture_linked_source(path, file_hash, size)
            except (OSError, ValueError, RuntimeError) as exc:
                self.emit("warning", f"Cannot read {path.name}: {exc}")
                continue

            if (self.linked_target is not None
                    and self.state.has_unresolved_upload()):
                self.drain_error = 'Linked upload outcome needs manual review.'
                self.stop_event.set()
                self.emit('error', self.drain_error)
                return

            if (self.linked_target is not None
                    and self.state.has_uncertain_source(path, file_hash)):
                # Explicit review excludes this source from further work.
                # Keep its unknown journal and bytes without an acknowledgement.
                with self._admission_lock:
                    self.initial_paths.add(path)
                    self.accepted_paths.discard(path)
                    self.persist_drain()
                self.emit('warning', f'Kept {path.name}; its prior upload remains uncertain.')
                continue

            # Acknowledgements apply only to this worker's fixed destination
            # and exact bytes. New or changed files still need fresh inventory.
            if (path, file_hash) in self.remote_skipped_paths:
                self.acknowledge_photo(path)
                continue
            # The old global local history has no gallery binding; never use it
            # to skip a source on a newly selected gallery.
            candidate = inventory_candidate(path, file_hash, size)
            self._diagnostic_phase = 'inventory'
            inventory_ready = self.remote_inventory.refresh(
                upload_url, self.config.api_key.strip(), [candidate],
                force=True,
            )
            if self.linked_target is not None and not inventory_ready:
                self.drain_error = (
                    "Could not check the upload gallery. Retry when it is reachable."
                    if self.linked_target.session.can_upload() else
                    "Gallery access expired. Reconnect to resume uploads."
                )
                self.stop_event.set()
                self.persist_drain()
                self.emit("warning", self.drain_error)
                return
            if inventory_ready and self.remote_inventory.has_hash(file_hash):
                if linked_source is not None:
                    try:
                        verify_linked_source(linked_source)
                    except (OSError, ValueError, RuntimeError):
                        self.drain_error = 'Linked source changed during inventory.'
                        self.stop_event.set()
                        self.emit('error', self.drain_error)
                        return
                self.acknowledge_photo(path)
                self.remote_skipped_paths.add((path, file_hash))
                self.emit("info", f"Skipped already present on gallery: {path.name}")
                continue

            if (self.linked_target is not None and not self.state.retry_allowed(path, file_hash)):
                continue
            if (self.linked_target is None
                    and time.monotonic() < self._legacy_failed_paths.get(
                        (path, file_hash), (0, 0.0),
                    )[1]):
                continue
            if self._update_pause_requested():
                return

            legacy_send_started = False
            try:
                self._diagnostic_phase = 'upload'
                self.emit("info", f"Uploading {path.name}")
                metadata_fields = self.sim_camera_metadata_fields(path)
                if self.linked_target is not None:
                    if not self.linked_target.session.can_upload():
                        self.drain_error = "Gallery access expired. Reconnect to resume uploads."
                        self.stop_event.set()
                        self.persist_drain()
                        self.emit("warning", self.drain_error)
                        return
                    # Persist uncertainty before any bytes can reach the server.
                    self.state.mark_uncertain(path, file_hash)
                legacy_send_started = self.linked_target is None
                payload = self.upload_watched_file(
                    upload_url, path, metadata_fields, file_hash, size,
                )
                current_work = getattr(self, '_once_work', None)
                if current_work is not None:
                    current_work.checkpoint()
                self._diagnostic_phase = 'acknowledgement'
                # A path can be replaced while the immutable request is in flight.
                # Keep the drain pending if the current file cannot be verified.
                try:
                    verify_linked_source(linked_source)
                except (OSError, ValueError, RuntimeError):
                    self.drain_error = 'Source changed during upload; photo retained for review.'
                    self.stop_event.set()
                    self.persist_drain()
                    self.emit('error', self.drain_error)
                    return
                if self.linked_target is not None:
                    self.state.mark_uploaded(path, file_hash, size, payload)
                else:
                    self._legacy_failed_paths.pop((path, file_hash), None)
                    self.remote_skipped_paths.add((path, file_hash))
                self.acknowledge_photo(path)
                uploaded = payload.get("uploaded", 0)
                scanned = payload.get("scanned", 0)
                renamed = int(payload.get("renamed", 0) or 0)
                self.emit("info", f"Uploaded {path.name}: uploaded={uploaded}, scanned={scanned}, renamed={renamed}")
                rename_failures = payload.get("rename_failures")
                if isinstance(rename_failures, list) and rename_failures:
                    self.emit("warning", f"Upload succeeded but automatic rename policy was not fully enforced for {path.name}: {'; '.join(str(item) for item in rename_failures[:3])}")
                sim_result = payload.get("sim_camera_metadata")
                if isinstance(sim_result, dict):
                    attached_count = int(sim_result.get("attached", 0) or 0)
                    sim_error = str(sim_result.get("error", "") or "")
                    if attached_count > 0:
                        self.emit("info", f"[simconnect] Stored Flight Simulator location for {path.name}.")
                    elif sim_error:
                        self.emit("warning", f"Uploaded {path.name}, but simulator location metadata was not stored: {sim_error}")
                if self.config.delete_uploaded_files:
                    self.delete_uploaded_file(path, payload, linked_source)
            except (ModeCoordinatorError, GalleryKeyRedirectError):
                raise
            except Exception as exc:  # noqa: BLE001
                current_work = getattr(self, '_once_work', None)
                if current_work is not None:
                    current_work.checkpoint()
                message = str(exc)
                if self.linked_target is not None:
                    # A dropped reply can follow a committed upload. Retain the
                    # durable pre-send marker and stop; absence from indexed
                    # inventory cannot prove that the original was not stored.
                    try:
                        self.state.mark_uncertain(path, file_hash)
                    except (OSError, ValueError) as marker_error:
                        self.emit('error', f'Could not persist linked recovery state: {marker_error}')
                    self.drain_error = 'Linked upload outcome needs manual review.'
                    self.stop_event.set()
                    self.emit('error', self.drain_error)
                    return
                if legacy_send_started:
                    self.drain_error = 'Upload outcome is unconfirmed; review the gallery before retrying.'
                    self.stop_event.set()
                    self.persist_drain()
                    self.emit('error', self.drain_error)
                    return
                if self.linked_target is None:
                    attempts = self._legacy_failed_paths.get((path, file_hash), (0, 0.0))[0] + 1
                    delay = min(LEGACY_WATCH_RETRY_CAP_SECONDS,
                                LEGACY_WATCH_RETRY_BASE_SECONDS * (
                                    2 ** min(attempts - 1, LEGACY_WATCH_RETRY_EXPONENT_MAX)))
                    self._legacy_failed_paths[(path, file_hash)] = (
                        attempts, time.monotonic() + delay,
                    )
                self.emit("error", f"Upload failed for {path.name}: {message}")

    def upload_watched_file(
        self, upload_url: str, path: Path, metadata_fields: Dict[str, str],
        file_hash: str = '',
        expected_size: Optional[int] = None,
    ) -> Dict[str, Any]:
        """
        Upload one stable watched file with local thumbnails when available.

        Synchronous server-side generation of all responsive variants can keep one
        PHP worker busy long enough for a shared-hosting proxy to return 502 even
        though the original was stored successfully. Raster files that Pillow can
        decode therefore use the same client-thumbnail protocol as manual import.
        HEIC/HEIF/DNG and local decode failures fall back to the authoritative PHP
        thumbnail pipeline.

        Args:
            upload_url (str): Normalized gallery automation endpoint.
            path (Path): Stable watched source file.
            metadata_fields (Dict[str, str]): Optional multipart metadata
                fields.
            file_hash (str): Captured source SHA-256, or empty for an older
                direct caller without a candidate.
            expected_size (Optional[int]): Captured byte length, or None for
                an older direct caller without a candidate.

        Returns:
            Dict[str, Any]: Upload response with ok, uploaded/scanned counts
                and optional filename, rename and metadata result fields.

        Raises:
            ModeCoordinatorError: Once-mode evidence changed before a request.
            GalleryKeyRedirectError: A credential-bearing redirect is unsafe.
        """
        create_server_thumbnails = bool(self.config.create_thumbnails)
        local_suffixes = {".jpg", ".jpeg", ".png", ".gif", ".webp"}
        request_options = (
            {'before_request': self._once_work.checkpoint}
            if self._once_work is not None else {}
        )
        if self.linked_target is not None:
            if self._once_work is not None:
                self._once_work.checkpoint()
            if (not create_server_thumbnails or not local_thumbnail_supported()
                    or path.suffix.lower() not in local_suffixes):
                return self.linked_target.upload(
                    path, create_server_thumbnails, file_hash, metadata=metadata_fields,
                )
            client_upload_id = uuid.uuid4().hex
            with tempfile.TemporaryDirectory(prefix="php-gallery-watch-thumbs-") as directory:
                thumbnails = generate_linked_thumbnails(
                    path, Path(directory), client_upload_id, file_hash,
                )
                if sha256_file(path) != file_hash:
                    raise ValueError('Source changed during linked thumbnail generation.')
                converted = tuple(
                    LinkedThumbnail(item.path, item.size, item.format)
                    for item in thumbnails
                )
                if self._once_work is not None:
                    self._once_work.checkpoint()
                return self.linked_target.upload(
                    path, False, file_hash, converted, client_upload_id, metadata_fields,
                )
        if not create_server_thumbnails or not local_thumbnail_supported() or path.suffix.lower() not in local_suffixes:
            return multipart_upload(
                upload_url,
                self.config.api_key.strip(),
                path,
                create_server_thumbnails,
                metadata_fields=metadata_fields,
                **({"expected_sha256": file_hash, "expected_size": expected_size,
                    "single_dispatch": True,
                    "selected_endpoint": self.remote_inventory.resolved_endpoint}
                       if file_hash else {}),
                **request_options,
            )

        client_upload_id = uuid.uuid4().hex
        with tempfile.TemporaryDirectory(prefix="php-gallery-watch-thumbs-") as temp_dir_name:
            temp_root = Path(temp_dir_name)
            upload_attempted = False
            try:
                thumbnails = generate_local_thumbnails(path, temp_root, client_upload_id)
                self.emit("info", f"Generated {len(thumbnails)} local thumbnail file(s) for {path.name}; server-side batch generation skipped.")
                upload_attempted = True
                return multipart_upload(
                    upload_url,
                    self.config.api_key.strip(),
                    path,
                    False,
                    thumbnails=thumbnails,
                    client_upload_id=client_upload_id,
                    metadata_fields=metadata_fields,
                    **({"expected_sha256": file_hash, "expected_size": expected_size,
                    "single_dispatch": True,
                    "selected_endpoint": self.remote_inventory.resolved_endpoint}
                       if file_hash else {}),
                    **request_options,
                )
            except (ModeCoordinatorError, GalleryKeyRedirectError):
                raise
            except Exception as exc:  # noqa: BLE001
                if upload_attempted:
                    raise
                self.emit("warning", f"Local thumbnails failed for {path.name}; falling back to server generation: {exc}")
                return multipart_upload(
                    upload_url,
                    self.config.api_key.strip(),
                    path,
                    True,
                    metadata_fields=metadata_fields,
                    **({"expected_sha256": file_hash, "expected_size": expected_size,
                    "single_dispatch": True,
                    "selected_endpoint": self.remote_inventory.resolved_endpoint}
                       if file_hash else {}),
                    **request_options,
                )

    def sim_camera_metadata_fields(self, path: Path) -> Dict[str, str]:
        """
        Return Flight Simulator camera metadata fields for one watched upload.

        The historical method/field names also cover aircraft-position results.
        Acquire coordinates only while MSFS 2020/2024 is running, publish the
        completed diagnostic snapshot, and log the actual provider source. Any host/API
        exception returns no extra form fields so optional GPS cannot block
        thumbnails, uploading, retry state or confirmed-source deletion.

        Args:
            path (Path): Local image path about to be uploaded.

        Returns:
            Dict[str, str]: Multipart form fields, or an empty dictionary when
                unavailable.
        """
        if not self.config.attach_sim_camera_metadata or not msfs_running():
            return {}

        try:
            location, message = self.simconnect_client.current_location()
            self.simconnect_diagnostics = self.simconnect_client.diagnostics_snapshot()
            if location is None:
                self.emit("warning", f"[simconnect] Simulator location unavailable for {path.name}: {message} Upload will continue without simulator location.")
                return {}

            fields = location.upload_fields()
            self.emit(
                "info",
                (
                    f"[simconnect] Attached Flight Simulator location for {path.name}: "
                    f"source={location.source}, lat={location.latitude:.7f}, lng={location.longitude:.7f}, "
                    f"alt={location.altitude:.2f} ft."
                ),
            )
            return fields
        except Exception as exc:  # noqa: BLE001
            # Optional host integration must never turn a valid photo into an upload failure.
            self.simconnect_diagnostics = {"last_result": "failed", "reason": str(exc), "connection": "unknown"}
            self.emit("warning", f"[simconnect] Simulator location acquisition failed for {path.name}: {exc}. Upload will continue without simulator location.")
            return {}

    def delete_uploaded_file(
        self, path: Path, payload: Dict[str, Any],
        snapshot: _FileSegment | None = None,
    ) -> None:
        """Delete only a Windows source matching exact upload evidence.

        Args:
            path (Path): Local image path that was submitted.
            payload (Dict[str, Any]): Gallery response with ok/uploaded and
                optional metadata, validated by the upload owner.
            snapshot (_FileSegment | None): Captured source identity and digest;
                None for direct callers without a verified candidate.

        Returns:
            None: Deletes the exact positively uploaded source, or retains it
                with a warning when safe same-handle deletion is unavailable.
        """
        if (payload.get('ok') is not True or type(payload.get('uploaded')) is not int
                or payload.get('uploaded') != 1 or snapshot is None
                or snapshot.path != path):
            self.emit(
                'warning',
                f'Kept {path.name}: upload confirmation or source identity was unavailable.',
            )
            return
        try:
            delete_verified_linked_source(snapshot)
        except Exception:  # noqa: BLE001
            self.emit(
                'warning',
                f'Kept {path.name}: the confirmed source could not be safely deleted.',
            )
            return
        self.emit('info', f'Deleted confirmed upload source: {path.name}')


class ManualUploadThread(threading.Thread):
    """
    Background worker for manual bulk uploads.

    The worker keeps manual uploading separate from watch-folder polling while
    reusing the same config object, endpoint normalizer, API-key header, and
    multipart upload function. Client-side thumbnail generation is optional and
    runs in a worker pool before each image is uploaded.
    """

    def __init__(
        self,
        config: WatcherConfig,
        paths: List[Path],
        client_thumbnails: bool,
        thumbnail_workers: int,
        upload_workers: int,
        events: "queue.Queue[Any]",
        job: Optional[ImportJob] = None,
        job_store: Optional[JobStateStore] = None,
        linked_target: Optional[LinkedUploadTarget] = None,
        gui_admission: Optional[Tuple[GuiModeOwner, ModeConfigPort]] = None,
    ) -> None:
        """
        Create a manual upload worker.

        Args:
            config (WatcherConfig): Shared connection configuration captured
                from the UI.
            paths (List[Path]): Image paths selected by the user for manual
                upload.
            client_thumbnails (bool): Whether to generate responsive thumbnails
                on.
            thumbnail_workers (int): Process count used for local thumbnail
                work.
            upload_workers (int): Thread count used for concurrent HTTP
                uploads.
            events (queue.Queue[Any]): Thread-safe queue used to send status
                messages to the GUI.
            job (Optional[ImportJob]): Durable import job record used for
                progress and restart recovery.
            job_store (Optional[JobStateStore]): Shared state store for job and
                activity persistence.
            linked_target (Optional[LinkedUploadTarget]): Captured delegated
                gallery, or None for the saved gallery API-key connection.
            gui_admission (Optional[Tuple[GuiModeOwner, ModeConfigPort]]): GUI
                owner and exact immutable worker configuration port.

        Returns:
            None: Create a manual upload worker.
        """
        super().__init__(daemon=True)
        self.config = config
        self.linked_target = linked_target
        self._gui_admission = gui_admission
        self._gui_work: Optional[CoordinatedWork] = None
        self._linked_lease = (LinkedTargetLease(CONFIG_DIR, linked_target.binding())
                              if linked_target is not None else None)
        self.linked_state: Optional[LinkedUploadState] = None
        self.paths = paths
        self.client_thumbnails = client_thumbnails
        self.thumbnail_workers = resolve_thumbnail_worker_count(thumbnail_workers)
        # One delegated mutation at a time protects its durable write-ahead
        # marker and prevents same-target workers from racing recovery.
        self.upload_workers = (1 if linked_target is not None
                               else resolve_upload_worker_count(upload_workers))
        self.events = events
        self.stop_event = threading.Event()
        self._redirect_refused = threading.Event()
        self.pause_event = threading.Event()
        self.uploaded = 0
        self.failed = 0
        self.skipped_existing = 0
        self.completed_uploads = 0
        self.progress_lock = threading.Lock()
        self.remote_inventory = RemoteInventorySession(
            config.inventory_refresh_seconds, self.emit, linked_target,
            self._redirect_checkpoint if linked_target is None else None,
        )
        self.job = job
        self.job_store = job_store
        self.job_lock = threading.RLock()
        self.item_by_path: Dict[str, ImportItem] = {}
        if self.job is not None:
            for item in self.job.items:
                self.item_by_path[os.path.normcase(os.path.abspath(item.path))] = item


    def _redirect_checkpoint(self) -> None:
        """Reject another manual request after an unsafe redirect was refused.

        Returns:
            None: This batch has not observed an unsafe redirect.

        Raises:
            GalleryKeyRedirectError: This batch must retain unfinished sources.
        """
        if self._redirect_refused.is_set():
            raise GalleryKeyRedirectError('Gallery API redirect was refused.')
        work = getattr(self, '_gui_work', None)
        if work is not None:
            work.checkpoint()

    def _stop_for_unsafe_redirect(self, path: Path, *, mode_error: bool = False) -> None:
        """Retain one ambiguous item and stop new requests after a refusal.

        Args:
            path (Path): Local source whose outcome must be reviewed.
            mode_error (bool): True when mode evidence rather than a redirect failed.

        Returns:
            None: Marks the item unresolved and signals the batch to stop.
        """
        self._redirect_refused.set()
        self.stop_event.set()
        with self.progress_lock:
            self.failed += 1
            self.completed_uploads += 1
        reason = ('Upload mode could not be verified' if mode_error
                  else 'Gallery API redirect was refused')
        uncertain = (LINKED_UPLOAD_UNCERTAIN_REASON if self.linked_target is not None
                     else LEGACY_UPLOAD_UNCERTAIN_REASON)
        self.update_item(
            path, 'failed_permanent', uncertain,
        )
        self.emit('error', reason + '; import sources are retained.')

    def stop(self) -> None:
        """
        Request the manual upload worker to stop after the current item.
        """
        self.stop_event.set()
        self.pause_event.clear()

    def pause(self) -> None:
        """Pause submission of new manual-import work while in-flight requests finish."""
        self.pause_event.set()
        self.emit("info", "Manual import paused. In-flight requests may still finish.")
        self.emit_structured("job_control", {"paused": True})

    def resume(self) -> None:
        """Resume a previously paused manual import."""
        self.pause_event.clear()
        self.emit("info", "Manual import resumed.")
        self.emit_structured("job_control", {"paused": False})

    def wait_if_paused(self) -> bool:
        """Wait while paused and return False when cancellation was requested."""
        while self.pause_event.is_set() and not self.stop_event.is_set():
            self.stop_event.wait(0.1)
        return not self.stop_event.is_set()

    def emit_structured(self, kind: str, payload: Dict[str, Any]) -> None:
        """Send one structured UI event without letting worker threads touch Tk widgets."""
        event = {"kind": kind, "payload": dict(payload)}
        if self.job is not None:
            event["job_id"] = self.job.id
        self.events.put(event)

    def item_for_path(self, path: Path) -> Optional[ImportItem]:
        """Return the durable item record associated with one local path."""
        return self.item_by_path.get(os.path.normcase(os.path.abspath(str(path))))

    def persist_job(self) -> None:
        """Persist the current import job and emit a throttled-safe UI snapshot."""
        if self.job is None or self.job_store is None:
            return
        with self.job_lock:
            self.job_store.save_job(self.job)
            counts = self.job.counts()
            self.emit_structured(
                "job_progress",
                {
                    "counts": counts,
                    "bytes_total": self.job.bytes_total,
                    "bytes_sent": self.job.bytes_sent,
                    "started_at": self.job.started_at,
                    "finished_at": self.job.finished_at,
                },
            )

    def update_item(
        self,
        path: Path,
        state: str,
        reason: str = "",
        file_hash: str = "",
        server_result: Optional[Dict[str, Any]] = None,
        increment_attempt: bool = False,
        count_bytes: bool = False,
    ) -> None:
        """Apply one durable item transition and save the enclosing job."""
        item = self.item_for_path(path)
        if item is None or self.job is None:
            return
        with self.job_lock:
            previous_state = item.state
            item.transition(state, reason)
            if file_hash:
                item.sha256 = file_hash
            if increment_attempt:
                item.attempt_count += 1
            if server_result is not None:
                allowed = {"uploaded", "scanned", "filenames", "client_thumbnails"}
                item.server_result = {key: value for key, value in server_result.items() if key in allowed}
            if count_bytes and previous_state != "confirmed":
                self.job.bytes_sent = min(self.job.bytes_total, self.job.bytes_sent + max(0, item.size))
        self.persist_job()

    def mark_remaining_cancelled(self) -> None:
        """Persist cancellation for work that never reached a terminal state."""
        if self.job is None:
            return
        changed = False
        with self.job_lock:
            for item in self.job.items:
                if item.state in ITEM_ACTIVE_STATES:
                    item.transition("cancelled", "Import cancelled before this item completed.")
                    changed = True
        if changed:
            self.persist_job()

    def finalize_job(self) -> None:
        """Persist the terminal batch while retaining all original ZIP sources.

        Returns:
            None: Finishes the job without deleting source or staged media.
        """
        if self.job is None:
            return
        with self.job_lock:
            self.job.finished_at = time.time()
        self.persist_job()
        # A local confirmed/duplicate state does not attest a durable
        # server image-operation receipt or prove absent historical work.
        # Keep extracted originals even when every item looks successful.
        if self.job.staging_dir:
            self.emit(
                "warning",
                "Import staging sources retained; safe automatic deletion "
                "requires server-bound operation receipts.",
            )
        if (self.linked_target is None and self.job.delete_source_zip_after_success
                and self.job.source_zip):
            self.emit(
                "warning",
                "Legacy upload cannot verify the destination for ZIP cleanup; "
                "the source ZIP is retained.",
            )


    def emit(self, level: str, message: str) -> None:
        """
        Send a status message to the GUI and persistent log.

        Args:
            level (str): Logging level name such as info, warning, or error.
            message (str): Human-readable status message.

        Returns:
            None: Send a status message to the GUI and persistent log.
        """
        self.events.put((level, message))
        log_method = getattr(logging, level if level in {"debug", "info", "warning", "error"} else "info")
        log_method(message)

    def run(self) -> None:
        """Retain the target lease throughout one manual import worker.

        Returns:
            None: Refuses linked work when exclusive recovery state is unavailable.
        """
        try:
            if self._gui_admission is not None:
                owner, port = self._gui_admission
                kind = 'linked' if self.linked_target is not None else 'legacy'
                self._gui_work = owner.acquire(kind, port)
                self.remote_inventory.before_request = self._redirect_checkpoint
            if self.linked_target is not None:
                if self._linked_lease is None:
                    raise ValueError('Linked gallery lease is unavailable.')
                self._linked_lease.acquire()
                self.linked_state = LinkedUploadState(self.linked_target)
            self._run_owned()
        except (OSError, ValueError, RuntimeError) as exc:
            self.emit('error', f'Import cannot acquire safe recovery state: {exc}')
        finally:
            if self._gui_work is not None:
                self._gui_work.close()
            if self._linked_lease is not None:
                self._linked_lease.release()

    def _run_owned(self) -> None:
        """Run the selected manual upload mode under its captured owner.

        Returns:
            None: Records item outcomes without retrying ambiguous transfers.
        """
        upload_url = (self.linked_target.issuer if self.linked_target is not None
                      else normalize_upload_url(self.config.gallery_url))
        if (not upload_url
                or (self.linked_target is None and not self.config.api_key.strip())
                or (self.linked_target is not None
                    and not self.linked_target.session.can_upload())):
            reason = ('Linked gallery session expired; reconnect before continuing.'
                      if self.linked_target is not None
                      else 'Gallery URL and API key are required.')
            self.emit('error', reason)
            if self.job is not None:
                for path in self.paths:
                    self.update_item(
                        path, 'failed_retryable' if self.linked_target is not None
                        else 'failed_permanent', reason,
                    )
                self.finalize_job()
            return
        if (self.linked_target is not None and (
                self.job is None or self.job_store is None
                or any(self.item_for_path(path) is None for path in self.paths))):
            self.emit('error', 'Linked import requires a durable item recovery record.')
            return
        if self.linked_state is not None and self.linked_state.has_unresolved_upload():
            self.emit('error', LINKED_UPLOAD_UNCERTAIN_REASON)
            return
        if not self.paths:
            self.emit("warning", "No manual upload files were selected.")
            if self.job is not None:
                self.finalize_job()
            return

        if self.job is not None:
            with self.job_lock:
                if self.job.started_at <= 0:
                    self.job.started_at = time.time()
                self.job.finished_at = 0.0
                for path in self.paths:
                    item = self.item_for_path(path)
                    if item is not None and item.state in RECOVERABLE_ITEM_STATES:
                        item.transition("queued")
            self.persist_job()

        self.emit("info", f"Manual upload started: {len(self.paths)} file(s).")
        self.emit("info", f"Upload endpoint: {upload_url}")
        self.emit("info", f"Remote inventory reconnect interval: {self.config.inventory_refresh_seconds:g} seconds.")

        try:
            self.preload_remote_inventory(upload_url)

            if self.client_thumbnails:
                self.run_with_client_thumbnails(upload_url)
            else:
                self.run_with_server_thumbnails(upload_url)
        except (GalleryKeyRedirectError, ModeCoordinatorError) as exc:
            self._redirect_refused.set()
            self.stop_event.set()
            reason = ('Upload mode could not be verified' if isinstance(exc, ModeCoordinatorError)
                      else 'Gallery API redirect was refused')
            self.emit('error', reason + '; import sources are retained.')
        finally:
            if self.stop_event.is_set():
                self.mark_remaining_cancelled()
            self.finalize_job()

        if self.stop_event.is_set():
            self.emit("info", f"Manual upload stopped: uploaded={self.uploaded}, skipped_existing={self.skipped_existing}, failed={self.failed}.")
        else:
            self.emit("info", f"Manual upload finished: uploaded={self.uploaded}, skipped_existing={self.skipped_existing}, failed={self.failed}.")


    def preload_remote_inventory(self, upload_url: str) -> None:
        """
        Ask the gallery which selected files are already present before a batch.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.

        Returns:
            None: Ask the gallery which selected files are already present
                before a batch.
        """
        candidates: List[Dict[str, Any]] = []
        for path in self.paths:
            if self.stop_event.is_set():
                return
            try:
                candidates.append(inventory_candidate(path))
            except OSError as exc:
                self.emit("warning", f"Cannot include {path.name} in remote inventory preload: {exc}")
        if candidates:
            if self.linked_target is not None:
                for start in range(0, len(candidates), LINKED_INVENTORY_BATCH_SIZE):
                    chunk = candidates[start:start + LINKED_INVENTORY_BATCH_SIZE]
                    if not self.remote_inventory.refresh(
                        upload_url, self.config.api_key.strip(), chunk, force=True,
                    ):
                        self.emit('warning', 'Linked inventory preload incomplete; individual files will be rechecked.')
                        break
            else:
                self.remote_inventory.refresh(upload_url, self.config.api_key.strip(), candidates, force=True)

    def remote_skip_before_work(self, upload_url: str, path: Path, file_hash: str, size: int) -> bool:
        """
        Return whether an upload should be skipped because the gallery has it.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.
            path (Path): Local image path being considered.
            file_hash (str): SHA-256 hash of the local image.
            size (int): Local file size in bytes.

        Returns:
            bool: True when the remote gallery confirms the file already
                exists.
        """
        candidate = inventory_candidate(path, file_hash, size)
        refreshed = self.remote_inventory.refresh(
            upload_url, self.config.api_key.strip(), [candidate],
            force=True,
        )
        if self.linked_target is not None and not refreshed:
            raise RuntimeError('Linked gallery inventory is unavailable.')
        if not refreshed or not self.remote_inventory.has_hash(file_hash):
            return False
        with self.progress_lock:
            self.skipped_existing += 1
            self.completed_uploads += 1
        self.update_item(path, "skipped_duplicate", "Gallery inventory already contains this content.", file_hash=file_hash)
        self.emit("info", f"Skipped already present on gallery: {path.name}")
        return True


    def run_with_server_thumbnails(self, upload_url: str) -> None:
        """
        Upload selected originals and ask PHP Gallery to create thumbnails.

        This mode keeps the upload executor structure used by the client-thumbnail
        pipeline, but the resolved network worker count is one. PHP Gallery already
        serializes mutations for one gallery, so concurrent requests would only hold
        extra PHP workers and can starve normal gallery page requests on shared hosting.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.

        Returns:
            None: Upload selected originals and ask PHP Gallery to create
                thumbnails.
        """
        self.emit("info", f"Uploading with {self.upload_workers} upload thread(s).")
        with concurrent.futures.ThreadPoolExecutor(max_workers=self.upload_workers) as executor:
            pending: Dict[concurrent.futures.Future[None], Path] = {}
            path_iterator = iter(enumerate(self.paths, start=1))
            queue_limit = max(1, self.upload_workers * 2)

            def submit_next() -> bool:
                """
                Submit one server-thumbnail upload when input remains.

                Returns:
                    bool: True when an upload task was queued.
                """
                if not self.wait_if_paused():
                    return False
                try:
                    index, path = next(path_iterator)
                except StopIteration:
                    return False
                self.update_item(path, "queued")
                future = executor.submit(self.upload_one, upload_url, path, index, [], None, True)
                pending[future] = path
                return True

            for _ in range(queue_limit):
                if not submit_next():
                    break

            while pending:
                if self.stop_event.is_set():
                    return
                done, _ = concurrent.futures.wait(pending.keys(), timeout=0.2, return_when=concurrent.futures.FIRST_COMPLETED)
                if not done:
                    continue
                for future in done:
                    pending.pop(future)
                    try:
                        future.result()
                    except Exception as exc:  # noqa: BLE001
                        with self.progress_lock:
                            self.failed += 1
                        self.emit("error", f"Manual upload worker crashed: {exc}")
                    if not self.stop_event.is_set():
                        submit_next()


    def run_with_client_thumbnails(self, upload_url: str) -> None:
        """
        Generate thumbnails in separate processes and upload in parallel threads.

        This is a producer-consumer pipeline tuned for large manual batches. CPU
        heavy resize and encode work runs in a ProcessPoolExecutor so Windows can
        schedule it across many CPU cores. Network-bound multipart uploads run in
        a separate ThreadPoolExecutor. Thumbnail generation, uploading, and temp
        file cleanup overlap, but both queues remain bounded so a 500-photo batch
        does not fill the disk with completed thumbnail sets waiting to upload.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.

        Returns:
            None: Generate thumbnails in separate processes and upload in
                parallel threads.
        """
        if not local_thumbnail_supported():
            self.emit("warning", "Local thumbnails are unavailable; falling back to server-side thumbnail generation.")
            self.run_with_server_thumbnails(upload_url)
            return

        temp_root = Path(tempfile.mkdtemp(prefix="php_gallery_thumbs_"))
        try:
            thumbnail_executor = concurrent.futures.ProcessPoolExecutor(max_workers=self.thumbnail_workers)
        except BaseException:
            shutil.rmtree(temp_root, ignore_errors=True)
            raise
        try:
            upload_executor = concurrent.futures.ThreadPoolExecutor(max_workers=self.upload_workers)
        except BaseException:
            try:
                thumbnail_executor.shutdown(wait=True, cancel_futures=True)
            finally:
                shutil.rmtree(temp_root, ignore_errors=True)
            raise
        pending_thumbnails: Dict[concurrent.futures.Future[List[LocalThumbnail]], Tuple[int, Path, str, str]] = {}
        pending_uploads: Dict[concurrent.futures.Future[None], Tuple[Path, Path]] = {}
        path_iterator = iter(enumerate(self.paths, start=1))
        thumbnail_queue_limit = max(1, self.thumbnail_workers * 2)
        upload_queue_limit = max(1, self.upload_workers * 2)

        def submit_next_thumbnail() -> bool:
            """
            Submit one thumbnail job to the process pool when input remains.

            Files already confirmed by the remote gallery are consumed here
            without starting local thumbnail generation. The loop continues until
            it either queues real work or exhausts the selected file list.

            Returns:
                bool: True when a thumbnail task was submitted.
            """
            while not self.stop_event.is_set():
                if not self.wait_if_paused():
                    return False
                try:
                    index, path = next(path_iterator)
                except StopIteration:
                    return False
                try:
                    self.update_item(path, "hashing")
                    file_hash = sha256_file(path)
                    size = path.stat().st_size
                except OSError as exc:
                    with self.progress_lock:
                        self.failed += 1
                        self.completed_uploads += 1
                    self.update_item(path, "failed_permanent", f"Cannot read source file: {exc}")
                    self.emit("error", f"Cannot read {path.name}: {exc}")
                    continue
                try:
                    if (self.linked_target is None
                            and self.remote_skip_before_work(upload_url, path, file_hash, size)):
                        continue
                except RuntimeError as exc:
                    with self.progress_lock:
                        self.failed += 1
                        self.completed_uploads += 1
                    self.update_item(path, 'failed_retryable', str(exc))
                    self.emit('error', f'Linked inventory unavailable for {path.name}.')
                    self.stop_event.set()
                    return False
                client_upload_id = uuid.uuid4().hex
                self.update_item(path, "staged", file_hash=file_hash)
                if self.linked_target is not None:
                    future = thumbnail_executor.submit(
                        generate_linked_thumbnails, path, temp_root, client_upload_id, file_hash,
                    )
                else:
                    future = thumbnail_executor.submit(generate_local_thumbnails, path, temp_root, client_upload_id)
                pending_thumbnails[future] = (index, path, client_upload_id, file_hash)
                return True
            return False

        def submit_upload(
            path: Path,
            index: int,
            thumbnails: List[LocalThumbnail],
            client_upload_id: Optional[str],
            create_server_thumbnails: bool,
            cleanup_dir: Path,
            expected_sha256: str,
        ) -> None:
            """
            Submit one upload job to the upload thread pool.

            Args:
                path (Path): Original image path to upload.
                index (int): One-based item index for progress messages.
                thumbnails (List[LocalThumbnail]): Local thumbnail files to
                    include in the request.
                client_upload_id (Optional[str]): Request-local ID mapping
                    thumbnails to the.
                create_server_thumbnails (bool): Whether the server should
                    generate.
                cleanup_dir (Path): Temporary directory to delete after upload.
                expected_sha256 (str): Hash captured before thumbnail generation.

            Returns:
                None: Submit one upload job to the upload thread pool.
            """
            if not self.wait_if_paused():
                return
            self.update_item(path, "queued")
            future = upload_executor.submit(
                self.upload_one,
                upload_url,
                path,
                index,
                thumbnails,
                client_upload_id,
                create_server_thumbnails,
                expected_sha256 if self.linked_target is not None else '',
            )
            pending_uploads[future] = (path, cleanup_dir)

        def drain_completed_uploads(wait_for_one: bool) -> None:
            """
            Collect completed upload jobs and remove their temp directories.

            Args:
                wait_for_one (bool): When True, wait until at least one upload
                    has.

            Returns:
                None: Collect completed upload jobs and remove their temp
                    directories.
            """
            if not pending_uploads:
                return
            timeout = None if wait_for_one else 0
            done, _ = concurrent.futures.wait(
                pending_uploads.keys(),
                timeout=timeout,
                return_when=concurrent.futures.FIRST_COMPLETED,
            )
            for future in done:
                _path, cleanup_dir = pending_uploads.pop(future)
                try:
                    future.result()
                except Exception as exc:  # noqa: BLE001
                    with self.progress_lock:
                        self.failed += 1
                    self.emit("error", f"Manual upload worker crashed: {exc}")
                finally:
                    shutil.rmtree(cleanup_dir, ignore_errors=True)

        try:
            for _ in range(thumbnail_queue_limit):
                if not submit_next_thumbnail():
                    break

            self.emit(
                "info",
                f"Generating thumbnails with {self.thumbnail_workers} worker process(es) and uploading with {self.upload_workers} thread(s).",
            )

            while pending_thumbnails or pending_uploads:
                if self.stop_event.is_set():
                    return

                while len(pending_uploads) >= upload_queue_limit:
                    drain_completed_uploads(wait_for_one=True)
                    if self.stop_event.is_set():
                        return

                drain_completed_uploads(wait_for_one=False)

                if not pending_thumbnails:
                    drain_completed_uploads(wait_for_one=True)
                    continue

                done, _ = concurrent.futures.wait(
                    pending_thumbnails.keys(),
                    timeout=0.1,
                    return_when=concurrent.futures.FIRST_COMPLETED,
                )
                if not done:
                    continue

                for future in done:
                    index, path, client_upload_id, expected_sha256 = pending_thumbnails.pop(future)
                    thumbnail_dir = temp_root / client_upload_id
                    try:
                        thumbnails = future.result()
                        self.emit("info", f"Generated {len(thumbnails)} thumbnail file(s) for {path.name}.")
                        submit_upload(path, index, thumbnails, client_upload_id, False, thumbnail_dir, expected_sha256)
                    except Exception as exc:  # noqa: BLE001
                        if self.linked_target is not None:
                            self.update_item(path, 'failed_retryable', str(exc))
                            self.emit('error', f'Linked thumbnail generation failed for {path.name}.')
                            self.stop_event.set()
                            continue
                        self.emit("warning", f"Local thumbnails failed for {path.name}; asking server to create them: {exc}")
                        self.emit_structured("thumbnail_fallback", {"filename": path.name, "reason": str(exc)})
                        submit_upload(path, index, [], None, True, thumbnail_dir, expected_sha256)

                    if not self.stop_event.is_set() and len(pending_thumbnails) < thumbnail_queue_limit:
                        submit_next_thumbnail()

            drain_completed_uploads(wait_for_one=False)
        finally:
            try:
                thumbnail_executor.shutdown(wait=True, cancel_futures=True)
            finally:
                try:
                    upload_executor.shutdown(wait=True, cancel_futures=True)
                finally:
                    for _path, cleanup_dir in list(pending_uploads.values()):
                        shutil.rmtree(cleanup_dir, ignore_errors=True)
                    shutil.rmtree(temp_root, ignore_errors=True)


    def upload_one(
        self,
        upload_url: str,
        path: Path,
        index: int,
        thumbnails: List[LocalThumbnail],
        client_upload_id: Optional[str],
        create_server_thumbnails: bool,
        expected_sha256: str = '',
    ) -> None:
        """
        Upload one original, optionally with locally generated thumbnails.

        Args:
            upload_url (str): Normalized PHP Gallery upload endpoint.
            path (Path): Original image path to upload.
            index (int): One-based item index for status messages.
            thumbnails (List[LocalThumbnail]): Local thumbnail files to submit
                with the original.
            client_upload_id (Optional[str]): Request-local ID mapping
                derivatives to this original.
            create_server_thumbnails (bool): Whether PHP Gallery should
                generate thumbnails.
            expected_sha256 (str): Source hash before linked thumbnail work,
                or empty when no linked snapshot was generated.

        Returns:
            None: Records a confirmed, skipped or failed disposition; retains
                an unknown original excluded from dispatch after review.
        """
        if not self.wait_if_paused():
            return
        candidate: Optional[Dict[str, Any]] = None
        linked_send_started = False
        legacy_send_started = False
        try:
            self.update_item(path, "hashing")
            file_hash = sha256_file(path)
            if self.linked_target is not None and expected_sha256 and file_hash != expected_sha256:
                raise ValueError('Source changed during linked thumbnail generation.')
            size = path.stat().st_size
            linked_source = (capture_linked_source(path, file_hash, size)
                             if self.linked_target is not None else None)
            candidate = inventory_candidate(path, file_hash, size)
            if (self.linked_state is not None
                    and self.linked_state.has_unresolved_upload()):
                self.update_item(path, 'failed_permanent', LINKED_UPLOAD_UNCERTAIN_REASON)
                self.emit('error', LINKED_UPLOAD_UNCERTAIN_REASON)
                self.stop_event.set()
                return
            if (self.linked_state is not None
                    and self.linked_state.has_uncertain_source(path, file_hash)):
                with self.progress_lock:
                    self.failed += 1
                    self.completed_uploads += 1
                self.update_item(path, 'failed_permanent', LINKED_UPLOAD_UNCERTAIN_REASON)
                self.emit('warning', f'Kept {path.name}; its prior upload remains uncertain.')
                return
            inventory_ready = self.remote_inventory.refresh(
                upload_url, self.config.api_key.strip(), [candidate],
                force=True,
            )
            if self.linked_target is not None and not inventory_ready:
                raise RuntimeError('Linked gallery inventory is unavailable.')
            if inventory_ready and self.remote_inventory.has_hash(file_hash):
                if linked_source is not None:
                    verify_linked_source(linked_source)
                with self.progress_lock:
                    self.skipped_existing += 1
                    self.completed_uploads += 1
                self.update_item(path, "skipped_duplicate", "Gallery inventory already contains this content.", file_hash=file_hash)
                self.emit("info", f"Skipped already present on gallery: {path.name}")
                return

            if not self.wait_if_paused():
                return
            if self.linked_state is not None:
                # Share the write-ahead marker with watcher and future imports.
                self.linked_state.mark_uncertain(path, file_hash)
            self.update_item(
                path, 'failed_permanent' if self.linked_target is not None else 'uploading',
                LINKED_UPLOAD_UNCERTAIN_REASON if self.linked_target is not None else '',
                file_hash=file_hash, increment_attempt=True,
            )
            self.emit("info", f"Uploading {index}/{len(self.paths)}: {path.name}")
            if self.linked_target is not None:
                # The durable item marker precedes all network side effects.
                linked_send_started = True
                self._redirect_checkpoint()
                payload = self.linked_target.upload(
                    path, create_server_thumbnails, file_hash,
                    tuple(LinkedThumbnail(item.path, item.size, item.format)
                          for item in thumbnails), client_upload_id or '',
                )
                self.linked_state.mark_uploaded(path, file_hash, size, payload)
            else:
                legacy_send_started = True
                payload = multipart_upload(
                    upload_url,
                    self.config.api_key.strip(),
                    path,
                    create_server_thumbnails,
                    thumbnails=thumbnails,
                    client_upload_id=client_upload_id,
                    before_request=self._redirect_checkpoint,
                    expected_sha256=file_hash, expected_size=size,
                    single_dispatch=True,
                    selected_endpoint=self.remote_inventory.resolved_endpoint,
                )
            with self.progress_lock:
                self.uploaded += int(payload.get("uploaded", 0) or 0)
                self.completed_uploads += 1
            self.update_item(path, "confirmed", file_hash=file_hash, server_result=payload, count_bytes=True)
            installed = 0
            failed_thumbnails = 0
            thumbnail_errors: List[str] = []
            client_result = payload.get("client_thumbnails")
            if isinstance(client_result, dict):
                installed = int(client_result.get("installed", 0) or 0)
                failed_thumbnails = int(client_result.get("failed", 0) or 0)
                raw_errors = client_result.get("errors")
                if isinstance(raw_errors, list):
                    thumbnail_errors = [str(item) for item in raw_errors[:3]]
            renamed = int(payload.get("renamed", 0) or 0)
            self.emit("info", f"Uploaded {path.name}: uploaded={payload.get('uploaded', 0)}, scanned={payload.get('scanned', 0)}, renamed={renamed}, client_thumbnails={installed}")
            rename_failures = payload.get("rename_failures")
            if isinstance(rename_failures, list) and rename_failures:
                self.emit("warning", f"Upload succeeded but automatic rename policy was not fully enforced for {path.name}: {'; '.join(str(item) for item in rename_failures[:3])}")
            if failed_thumbnails or thumbnail_errors:
                details = "; ".join(thumbnail_errors) if thumbnail_errors else "no detailed server message"
                self.emit("warning", f"Client thumbnails were partially rejected for {path.name}: failed={failed_thumbnails}; {details}. Server-side generation remains authoritative.")
        except (GalleryKeyRedirectError, ModeCoordinatorError) as exc:
            # A redirect can arrive after an original was accepted; never turn
            # that ambiguous result into an automatic retry or confirmation.
            self._stop_for_unsafe_redirect(path, mode_error=isinstance(exc, ModeCoordinatorError))
        except Exception as exc:  # noqa: BLE001
            if self.linked_target is not None:
                with self.progress_lock:
                    self.failed += 1
                    self.completed_uploads += 1
                if linked_send_started:
                    if candidate is not None and self.linked_state is not None:
                        try:
                            self.linked_state.mark_uncertain(
                                path, str(candidate.get('sha256', '')),
                            )
                        except (OSError, ValueError):
                            self.emit('error', 'Linked recovery marker could not be refreshed.')
                    self.update_item(path, 'failed_permanent', LINKED_UPLOAD_UNCERTAIN_REASON)
                    self.emit('error', LINKED_UPLOAD_UNCERTAIN_REASON)
                else:
                    self.update_item(path, 'failed_retryable', str(exc))
                    self.emit('error', f'Linked upload could not start: {exc}')
                self.stop_event.set()
                return
            if legacy_send_started:
                with self.progress_lock:
                    self.failed += 1
                    self.completed_uploads += 1
                self.update_item(path, 'failed_permanent', LEGACY_UPLOAD_UNCERTAIN_REASON)
                self.emit('error', LEGACY_UPLOAD_UNCERTAIN_REASON)
                self.stop_event.set()
                return
            try:
                if (
                    candidate is not None
                    and upload_failure_needs_inventory_reconciliation(exc)
                    and self.remote_inventory.confirm_after_failure(upload_url, self.config.api_key.strip(), candidate)
                ):
                    with self.progress_lock:
                        self.skipped_existing += 1
                        self.completed_uploads += 1
                    file_hash = str(candidate.get("sha256", ""))
                    # This path is reached only before legacy HTTP dispatch.
                    # Matching gallery inventory is a duplicate-skip hint, not
                    # an immutable receipt for any particular upload attempt.
                    self.update_item(
                        path,
                        "skipped_duplicate",
                        "Matching content is already indexed; no upload was dispatched by this attempt.",
                        file_hash=file_hash,
                    )
                    self.emit("warning", f"Pre-dispatch operation failed for {path.name}; existing gallery inventory matches the content, so this attempt was skipped without an upload receipt. Original error: {exc}")
                    return
            except (GalleryKeyRedirectError, ModeCoordinatorError) as exc:
                self._stop_for_unsafe_redirect(path, mode_error=isinstance(exc, ModeCoordinatorError))
                return
            except Exception as confirm_exc:  # noqa: BLE001
                self.emit("warning", f"Could not confirm remote inventory after failure for {path.name}: {confirm_exc}")
            with self.progress_lock:
                self.failed += 1
                self.completed_uploads += 1
            message = str(exc)
            lower = message.lower()
            permanent = any(token in lower for token in ["api key", "authentication", "unauthorized", "forbidden", "unsupported", "invalid gallery", "http 400", "http 401", "http 403", "http 404", "http 422"])
            state = "failed_permanent" if permanent else "failed_retryable"
            self.update_item(path, state, message)
            self.emit("error", f"Manual upload failed for {path.name}: {exc}")



class ImportPreflightThread(threading.Thread):
    """
    Background discovery and duplicate/capability preflight for manual imports.

    The thread performs potentially expensive ZIP inspection, hashing, decoder
    probes, and remote inventory checks without blocking Tkinter. It never
    uploads image content. The user must explicitly start the prepared job.
    """

    def __init__(
        self,
        config: WatcherConfig,
        source_kind: str,
        source_paths: List[Path],
        source_folder: Optional[Path],
        source_zip: Optional[Path],
        recursive: bool,
        delete_source_zip_after_success: bool,
        events: "queue.Queue[Any]",
        job_store: JobStateStore,
        linked_target: Optional[LinkedUploadTarget] = None,
        gui_admission: Optional[Tuple[GuiModeOwner, ModeConfigPort]] = None,
    ) -> None:
        """
        Create one preflight worker for the selected source.

        Args:
            config (WatcherConfig): Connection and archive-safety settings.
            source_kind (str): files, folder, or zip.
            source_paths (List[Path]): Explicit file selection for files mode.
            source_folder (Optional[Path]): Selected folder for folder mode.
            source_zip (Optional[Path]): Selected ZIP archive for zip mode.
            recursive (bool): Whether folder discovery includes subfolders.
            delete_source_zip_after_success (bool): Whether a successful ZIP
                import may delete its original archive.
            events (queue.Queue[Any]): Thread-safe UI event queue.
            job_store (JobStateStore): Durable job history store.
            linked_target (Optional[LinkedUploadTarget]): Explicitly selected
                delegated gallery, or None for the legacy API-key connection.
            gui_admission (Optional[Tuple[GuiModeOwner, ModeConfigPort]]): GUI
                owner and exact immutable preflight configuration port.

        Returns:
            None: Create one preflight worker for the selected source.
        """
        super().__init__(daemon=True)
        self.config = config
        self.source_kind = source_kind
        self.source_paths = list(source_paths)
        self.source_folder = source_folder
        self.source_zip = source_zip
        self.recursive = recursive
        self.delete_source_zip_after_success = delete_source_zip_after_success
        self.events = events
        self.job_store = job_store
        self.linked_target = linked_target
        self._gui_admission = gui_admission
        self._gui_work: Optional[CoordinatedWork] = None
        self._preflight_owner = object()
        self._linked_lease = (LinkedTargetLease(CONFIG_DIR, linked_target.binding())
                              if linked_target is not None else None)
        self.stop_event = threading.Event()

    def stop(self) -> None:
        """Request cancellation of discovery before it publishes a plan."""
        self.stop_event.set()

    def emit(self, kind: str, payload: Dict[str, Any]) -> None:
        """Send one structured preflight event to the Tkinter thread.

        Args:
            kind (str): Preflight event name identifying the payload variant.
            payload (Dict[str, Any]): Event fields such as job_id, message,
                current and total counts, filename, or the completed ImportPlan.

        Returns:
            None: Enqueues the event with its captured linked destination.
        """
        event_payload = dict(payload)
        # Opaque generations and owner tickets never contain credentials.
        event_payload['config_generation'] = self.config._snapshot_token
        event_payload['preflight_owner'] = self._preflight_owner
        event_payload['linked_binding'] = (
            self.linked_target.binding() if self.linked_target is not None else None
        )
        event_payload['preflight_binding'] = (
            self.linked_target.binding() if self.linked_target is not None else
            connection_binding(normalize_upload_url(self.config.gallery_url), self.config.api_key)
        )
        self.events.put({"kind": kind, "payload": event_payload})

    def run(self) -> None:
        """Build a preflight plan and publish current gallery metadata.

        Returns:
            None: Saves the plan and emits connection metadata or a warning;
                no source media is uploaded.
        """
        try:
            if self._gui_admission is not None:
                owner, port = self._gui_admission
                kind = 'linked' if self.linked_target is not None else 'legacy'
                self._gui_work = owner.acquire(kind, port)
            if self._linked_lease is not None:
                self._linked_lease.acquire()
            self._run_owned()
        except (OSError, ValueError, RuntimeError) as exc:
            self.emit('preflight_failed', {'message': str(exc)})
        finally:
            if self._gui_work is not None:
                self._gui_work.close()
            if self._linked_lease is not None:
                self._linked_lease.release()

    def _run_owned(self) -> None:
        """Inspect sources while holding the target lease.

        Returns:
            None: Emits a plan excluding retained unknown sources, or a
                diagnostic when the gallery still needs review.
        """
        upload_url = (self.linked_target.issuer if self.linked_target is not None
                      else normalize_upload_url(self.config.gallery_url))
        parsed = parse.urlparse(upload_url) if upload_url else None
        target_label = parsed.netloc if parsed and parsed.netloc else "Gallery connection not configured"
        if parsed and parsed.path and parsed.path not in {"", "/"}:
            target_label += parsed.path
        if self.linked_target is not None:
            target_label = f'{self.linked_target.title} (#{self.linked_target.gallery_id})'

        if self.source_kind == "files":
            source_label = f"{len(self.source_paths)} selected file(s)"
        elif self.source_kind == "folder":
            source_label = str(self.source_folder or "")
        else:
            source_label = str(self.source_zip or "")
        job = ImportJob.create(self.source_kind, source_label, target_label)
        self.emit("preflight_started", {"job_id": job.id, "source_label": source_label})

        try:
            linked_state = (LinkedUploadState(self.linked_target)
                            if self.linked_target is not None else None)
        except (OSError, ValueError):
            self.emit('preflight_failed', {
                'message': 'Linked gallery recovery state is unavailable.',
                'job_id': job.id,
            })
            return
        if linked_state is not None and linked_state.has_unresolved_upload():
            self.emit('preflight_failed', {
                'message': LINKED_UPLOAD_UNCERTAIN_REASON, 'job_id': job.id,
            })
            return

        limits = ArchiveLimits(
            max_entries=self.config.archive_max_entries,
            max_uncompressed_bytes=self.config.archive_max_uncompressed_bytes,
            max_file_bytes=self.config.archive_max_file_bytes,
            max_compression_ratio=self.config.archive_max_compression_ratio,
        )
        try:
            if self.source_kind == "files":
                discovery = discover_selected_files(self.source_paths, SUPPORTED_SUFFIXES)
            elif self.source_kind == "folder":
                if self.source_folder is None:
                    raise ValueError("No source folder was selected.")
                discovery = discover_folder(self.source_folder, SUPPORTED_SUFFIXES, self.recursive)
            elif self.source_kind == "zip":
                if self.source_zip is None:
                    raise ValueError("No ZIP archive was selected.")
                discovery = stage_zip_archive(self.source_zip, STAGING_ROOT, job.id, SUPPORTED_SUFFIXES, limits)
                job.staging_dir = discovery.staging_dir
                job.source_zip = str(self.source_zip)
                job.delete_source_zip_after_success = (
                    self.delete_source_zip_after_success and self.linked_target is None
                )
            else:
                raise ValueError("Unknown import source kind: " + self.source_kind)
        except Exception as exc:  # noqa: BLE001
            self.emit("preflight_failed", {"message": str(exc), "job_id": job.id})
            return

        if self.stop_event.is_set():
            # A cancelled preflight cannot prove staged sources were never
            # accepted by an older or concurrent uploader. Keep the bytes.
            self.emit("preflight_cancelled", {
                "job_id": job.id,
                "staging_dir": discovery.staging_dir,
            })
            return

        generic_capabilities = detect_suffix_capabilities(SUPPORTED_SUFFIXES, Image)
        notes = capability_notes(generic_capabilities)
        valid_items: List[ImportItem] = []
        local_duplicates = 0
        retained_unknown = 0
        candidates: List[Dict[str, Any]] = []
        item_by_hash: Dict[str, List[ImportItem]] = {}
        total = max(1, len(discovery.items))
        for index, item in enumerate(discovery.items, start=1):
            if self.stop_event.is_set():
                # A cancelled preflight cannot prove staged sources were never
                # accepted by an older or concurrent uploader. Keep the bytes.
                self.emit("preflight_cancelled", {
                    "job_id": job.id,
                    "staging_dir": discovery.staging_dir,
                })
                return
            path = Path(item.path)
            self.emit("preflight_progress", {"current": index, "total": total, "filename": path.name})
            try:
                item.transition("hashing")
                item.sha256 = sha256_file(path)
                item.size = int(path.stat().st_size)
            except OSError as exc:
                bucket = "unreadable source"
                discovery.unsupported[bucket] = discovery.unsupported.get(bucket, 0) + 1
                discovery.warnings.append(f"{item.source_label}: cannot be read: {exc}")
                continue
            if (linked_state is not None
                    and linked_state.has_uncertain_source(path, item.sha256)):
                # Keep this outside the newly accepted job. Marking an
                # unbound item terminal would fail its first binding admission.
                retained_unknown += 1
                discovery.warnings.append(
                    f'Kept {item.source_label}; its prior upload remains uncertain.',
                )
                continue

            suffix = path.suffix.lower()
            if suffix in {".heic", ".heif", ".dng"}:
                capability = probe_file(path, SUPPORTED_SUFFIXES, Image)
            else:
                capability = generic_capabilities.get(suffix)
            if capability is None or not capability.local_previewable:
                item.local_decode = "server_only"
            else:
                item.local_decode = "local"

            item.transition("queued")
            candidate = inventory_candidate(path, item.sha256, item.size)
            candidates.append(candidate)
            item_by_hash.setdefault(item.sha256, []).append(item)
            valid_items.append(item)

        job.items = valid_items
        job.bytes_total = sum(max(0, item.size) for item in valid_items)
        job.skipped = (sum(discovery.unsupported.values())
                       + len(discovery.unsafe_entries) + retained_unknown)

        remote_duplicates = 0
        gallery_label = target_label
        gallery_title = ""
        if (candidates and upload_url and not self.stop_event.is_set()
                and (self.linked_target is not None or self.config.api_key.strip())):
            existing_hashes: Set[str] = set()
            try:
                for start in range(0, len(candidates), LINKED_INVENTORY_BATCH_SIZE):
                    chunk = candidates[start : start + LINKED_INVENTORY_BATCH_SIZE]
                    if self.linked_target is not None:
                        if self._gui_work is not None:
                            self._gui_work.checkpoint()
                        payload = self.linked_target.inventory(chunk)
                    else:
                        payload = post_json(
                            upload_url,
                            self.config.api_key.strip(),
                            {"action": "inventory", "files": chunk, "deep_check": False},
                            timeout_seconds=min(DEFAULT_TIMEOUT_SECONDS, max(15.0, self.config.inventory_refresh_seconds)),
                            **({'before_request': self._gui_work.checkpoint}
                               if self._gui_work is not None else {}),
                        )
                    folder_label = str(payload.get("gallery_folder", "") or "").strip()
                    if folder_label:
                        gallery_label = folder_label
                    gallery_title = str(payload.get("gallery_title", "") or "").strip()
                    rows = payload.get("existing", [])
                    if isinstance(rows, list):
                        for row in rows:
                            if isinstance(row, dict):
                                remote_hash = str(row.get("sha256", "") or "").lower()
                                if len(remote_hash) == 64:
                                    existing_hashes.add(remote_hash)
                for file_hash in existing_hashes:
                    for item in item_by_hash.get(file_hash, []):
                        if item.state != "skipped_duplicate":
                            remote_duplicates += 1
                if remote_duplicates:
                    discovery.warnings.append(
                        'Duplicate candidates are only a preview; each '
                        'file is checked again during import.',
                    )
                job.target_gallery_label = gallery_label
                self.emit("connection_ready", {
                    "gallery_label": gallery_label,
                    "gallery_title": gallery_title,
                    "normalized_url": upload_url,
                })
            except (ModeCoordinatorError, GalleryKeyRedirectError):
                raise
            except Exception as exc:  # noqa: BLE001
                discovery.warnings.append("Remote duplicate preflight was unavailable: " + str(exc))
                self.emit("connection_warning", {"message": str(exc), "normalized_url": upload_url})
        elif not upload_url or (self.linked_target is None and not self.config.api_key.strip()):
            discovery.warnings.append("Connection is not configured. Preflight is local only until Gallery URL and API key are provided.")

        plan = ImportPlan(
            job=job,
            unsupported=discovery.unsupported,
            unsafe_entries=discovery.unsafe_entries,
            duplicate_local=local_duplicates,
            duplicate_remote=remote_duplicates,
            capability_notes=notes,
            warnings=discovery.warnings,
        )
        self.job_store.save_job(job)
        self.emit("preflight_complete", {"plan": plan, "gallery_label": gallery_label})


class ScrollableFrame(ttk.Frame if ttk is not None else object):
    """Simple ttk scroll container used by the redesigned task views."""

    def __init__(self, parent: "tk.Misc") -> None:
        """Create a themed vertical canvas-backed frame.

        Args:
            parent (tk.Misc): Tk widget that owns the scroll container.

        Returns:
            None: Creates the canvas and content frame; the scrollbar appears
                only while the page exceeds its visible viewport.
        """
        if ttk is None or tk is None:
            raise RuntimeError("Tkinter is not available.")
        super().__init__(parent, style="Backdrop.TFrame")
        self.canvas = tk.Canvas(
            self,
            highlightthickness=0,
            borderwidth=0,
            background=self.winfo_toplevel().cget("background"),
        )
        self.scrollbar = ttk.Scrollbar(self, orient="vertical", command=self.canvas.yview)
        self.content = ttk.Frame(self.canvas, style="Backdrop.TFrame")
        self.window_id = self.canvas.create_window((0, 0), window=self.content, anchor="nw")
        paint_backdrop(self.content)
        self.canvas.configure(yscrollcommand=self._set_scrollbar)
        self.canvas.pack(side="left", fill="both", expand=True)
        self.content.bind("<Configure>", self._sync_scrollregion, add="+")
        self.canvas.bind("<Configure>", self._sync_width, add="+")
        self._wheel_remainder = 0
        self._wheel_toplevel = self.winfo_toplevel()
        self._wheel_binding_id = self._wheel_toplevel.bind(
            "<MouseWheel>",
            self._on_mousewheel,
            add="+",
        )
        self.bind("<Destroy>", self._on_destroy, add="+")

    def _set_scrollbar(self, first: str, last: str) -> None:
        """Show the themed scrollbar only when the viewport has hidden content.

        Args:
            first (str): Tk's normalized first visible vertical fraction.
            last (str): Tk's normalized final visible vertical fraction.

        Returns:
            None: Updates the thumb and adds or removes its occupied space.
        """
        self.scrollbar.set(first, last)
        # Tk's complete viewport is exactly the structural interval [0, 1].
        needed = float(first) > 0.0 or float(last) < 1.0
        visible = bool(self.scrollbar.winfo_manager())
        if needed and not visible:
            self.scrollbar.pack(side="right", fill="y")
        elif not needed and visible:
            self.scrollbar.pack_forget()

    def _on_mousewheel(
        self,
        event: "tk.Event[tk.Misc]",
    ) -> Optional[str]:
        """Scroll this page when the pointer is over its non-native controls.

        Args:
            event (tk.Event[tk.Misc]): Windows wheel event with a delta and
                originating widget.

        Returns:
            Optional[str]: ``break`` when this page consumes a full wheel
                increment; otherwise None so another widget can handle it.
        """
        widget = event.widget
        if tk is None or ttk is None:
            return None
        if isinstance(widget, (tk.Text, tk.Listbox, ttk.Treeview, ttk.Combobox)):
            return None

        while widget is not None and widget is not self:
            widget = getattr(widget, "master", None)
        if widget is not self:
            return None

        accumulated_delta = self._wheel_remainder + int(event.delta)
        steps = int(accumulated_delta / WINDOWS_MOUSEWHEEL_DELTA)
        self._wheel_remainder = (
            accumulated_delta - steps * WINDOWS_MOUSEWHEEL_DELTA
        )
        if steps == 0:
            return None

        self.canvas.yview_scroll(-steps, "units")
        return "break"

    def _on_destroy(self, event: "tk.Event[tk.Misc]") -> None:
        """Remove this page's additive top-level wheel binding when destroyed.

        Args:
            event (tk.Event[tk.Misc]): Destroy event used to ignore child
                teardown notifications.

        Returns:
            None: Unbinds only this ScrollableFrame's registered callback.
        """
        if event.widget is self and self._wheel_binding_id:
            self._wheel_toplevel.unbind(
                "<MouseWheel>",
                self._wheel_binding_id,
            )
            self._wheel_binding_id = None

    def _sync_scrollregion(self, _event: Optional["tk.Event[tk.Misc]"] = None) -> None:
        """Refresh the canvas scroll region after content geometry changes.

        Args:
            _event (Optional[tk.Event[tk.Misc]]): Optional geometry event from
                the embedded content frame.

        Returns:
            None: Updates the canvas scrollable bounds.
        """
        self.canvas.configure(scrollregion=self.canvas.bbox("all"))

    def _sync_width(self, event: "tk.Event[tk.Misc]") -> None:
        """Keep the embedded content frame at least as wide as the viewport.

        Args:
            event (tk.Event[tk.Misc]): Canvas resize event with the viewport
                width in pixels.

        Returns:
            None: Resizes the embedded content frame to match the viewport.
        """
        self.canvas.itemconfigure(self.window_id, width=max(1, int(event.width)))


def protected_staging_job_ids(jobs: List[ImportJob]) -> List[str]:
    """Keep recoverable and uncertain linked ZIP jobs out of stale cleanup.

    Args:
        jobs (List[ImportJob]): Persisted jobs with their individual item states.

    Returns:
        List[str]: Job IDs whose staged sources must remain available.
    """
    return [
        job.id for job in jobs
        if job.recoverable() or any(
            item.reason in (LINKED_UPLOAD_UNCERTAIN_REASON, LEGACY_UPLOAD_UNCERTAIN_REASON)
            for item in job.items
        )
    ]


class WatcherApp:
    """
    Tkinter user interface for the watched-folder uploader.

    The GUI is intentionally small: it collects configuration, starts and stops
    the worker, and mirrors worker events into a visible status log. Upload logic
    stays in WatcherThread so the same behavior can be reused by command-line
    mode.
    """

    def __init__(
        self, *, config_store: Optional[ConfigStore] = None,
        start_minimized: bool = False,
        after_update: Optional[str] = None,
    ) -> None:
        """
        Initialize the Tkinter application and load saved configuration.

        Args:
            config_store (Optional[ConfigStore]): Selected configuration owner;
                None uses the standard per-user profile.
            start_minimized (bool): Withdraw before painting and start in the
                tray; restore the window if a usable tray cannot be created.
            after_update (Optional[str]): Exact non-secret helper nonce for an
                explicitly prepared continuation, or None for ordinary startup.
        Raises:
            RuntimeError: Raised when Tkinter is not available.

        Returns:
            None: Creates the window, settings and idle worker coordinators,
                then starts the read-only game-presence observer.
        """
        if tk is None or ttk is None or filedialog is None or messagebox is None:
            raise RuntimeError("Tkinter is not available in this Python installation.")

        from uploader.app_linking_ui import LinkingManager

        # Establish the display context before Tk creates its first window.
        prepare_windows_display()
        self.root = tk.Tk()
        self.linking_manager = LinkingManager(
            self.root, self.linked_target_change_refusal, self.linked_target_changed,
            self.probe_linked_uncertain_upload, self.publish_linked_recovery,
            self.saved_linked_mode_selected, self.commit_linked_upload_target,
            self.commit_saved_api_key,
            language=lambda: self.flights.ui_language.get(),
            recovery_review=self.review_linked_uncertainty,
            upload_workspace_ready=self.linked_workspace_ready,
            saved_upload_identity=self.saved_linked_upload_identity,
            diagnostic_callback=lambda message: self.events.put(
                (
                    "info" if message.endswith("reason=connected") else "warning",
                    "[linking] " + message,
                ),
            ),
        )
        if start_minimized:
            self.root.withdraw()
        self.root.title(f"{APP_DISPLAY_NAME} {APP_VERSION}")

        self.config_store = config_store or ConfigStore()
        self.config = self.config_store.load()
        self._ui_layout_path = CONFIG_DIR / "ui-layout.json"
        display_bounds = _layout_display_bounds(self.root)
        loaded_layout = _read_ui_layout(
            self._ui_layout_path, display_bounds, WATCHER_WINDOW_MINIMUM,
        )
        self._ui_layout_restored = loaded_layout is not None
        default_layout_width, default_layout_height = (
            int(value) for value in WATCHER_WINDOW_GEOMETRY.split("x")
        )
        self._ui_layout_state = loaded_layout or _clamp_ui_layout_state(
            ViewLayoutState(
                default_layout_width, default_layout_height,
                display_bounds[0], display_bounds[1], "normal", PHOTOS_TREE_WIDTH,
            ),
            display_bounds,
            WATCHER_WINDOW_MINIMUM,
        )
        self._ui_layout_save_after: Optional[str] = None
        startup_error = ""
        try:
            synchronize_startup(self.config.start_with_windows)
        except OSError as exc:
            startup_error = str(exc)
        self.job_store = JobStateStore(STATE_PATH, self.config.activity_history_limit)
        self.activity_writer = ActivityWriter(self.job_store)
        self.events: "queue.Queue[Any]" = queue.Queue()
        self.mode_owner = GuiModeOwner(
            self.config_store.mode_port(self.config),
            (lambda: MacUploadModeStore(CONFIG_DIR))
            if sys.platform == "darwin" else WindowsUploadModeStore,
        )
        threading.Thread(
            target=self._initialize_gui_mode, name='PHPGalleryModeStartup', daemon=True,
        ).start()
        self.utc_sync = UtcSyncManager(self.emit_utc_activity)
        self.simulator_presence = GamePresence(
            lambda: self.config.simconnect_dll_path, CONFIG_DIR
        )
        self.worker: Optional[WatcherThread] = None
        self._after_update_nonce = after_update
        self._gui_mode_ready = False
        self._update_resume_attempted = False
        self._update_resume_inspecting = False
        self._update_resume_cancelled = False
        self._update_resume_deadline = 0.0
        self._update_resume_generation = 0
        self._update_resume_status = 'No update continuation requested.'
        self._update_checkpoint_ready = False
        self._update_continuation: Optional[WatcherContinuation] = None
        self._resume_continuation: Optional[WatcherContinuation] = None
        self._update_checkpoint_workers = ()
        self._update_context_target: Optional[LinkedUploadTarget] = None
        self._update_context_key_closed = False
        self._update_resume_context_target: Optional[LinkedUploadTarget] = None
        self._update_resume_context_key_closing = False
        self._update_resume_context_key_worker: Optional[threading.Thread] = None
        self._update_resume_preparing = False
        self._ordinary_restart_closing = False
        self._ordinary_restart_prepared = False
        self._ordinary_restart_resuming = False
        self._ordinary_restart_thread: Optional[threading.Thread] = None
        self._discard_update_resume_intent()
        self._resume_retry_available = False
        self.manual_worker: Optional[ManualUploadThread] = None
        self.preflight_worker: Optional[ImportPreflightThread] = None
        self.ai_worker: Optional[AIAnalysisWorkerThread] = None
        self.ai_worker_stop_requested = False
        self.semantic_ai_install_running = False
        self.semantic_ai_install_button: Optional[Any] = None
        self.connection_test_running = False
        self.api_key_revoke_running = False
        self.api_key_revoke_thread: Optional[threading.Thread] = None
        self._verified_legacy_gallery: Optional[
            Tuple[object, str, str, str]
        ] = None
        self.manual_paths: List[Path] = []
        self.manual_folder: Optional[Path] = None
        self.manual_zip: Optional[Path] = None
        self.manual_plan: Optional[ImportPlan] = None
        self._preflight_approved: Optional[Tuple[str, object, str]] = None
        self.current_job: Optional[ImportJob] = None
        self.tray_icon: Optional[Any] = None
        self.tray_thread: Optional[threading.Thread] = None
        self.update_available_version: Optional[str] = None
        self.update_banner: Optional["ttk.Frame"] = None
        self.update_banner_label: Optional["ttk.Label"] = None
        self.update_banner_action: Optional["ttk.Button"] = None
        self.check_updates_button: Optional["ttk.Button"] = None
        self.window_hidden_to_tray = start_minimized
        self.startup_waiting_for_tray = start_minimized
        self.recovery_offer_pending = start_minimized
        self.instance: Optional[WindowsInstance] = None
        self.exiting = False
        self._drain_after_id: Optional[str] = None
        self._drain_running = False
        self._activity_render_after_id: Optional[str] = None
        self._event_drain_last_count = 0
        self._event_drain_total_count = 0
        self._event_drain_yield_count = 0
        self._event_drain_last_ms = 0.0
        self._event_drain_max_ms = 0.0
        self._activity_drawer_flush_count = 0
        self.activity_events: List[ActivityEvent] = self.job_store.list_events()
        self.media_capabilities = detect_suffix_capabilities(SUPPORTED_SUFFIXES, Image)

        self.watched_folder_var = tk.StringVar(value=self.config.watched_folder)
        self.watch_folder_display_var = tk.StringVar(
            value=self.config.watched_folder or "No folder selected",
        )
        self.watched_folder_var.trace_add("write", self.update_watch_folder_display)
        self.gallery_url_var = tk.StringVar(value=self.config.gallery_url)
        self.api_key_var = tk.StringVar(value=self.config.api_key)
        self.gallery_url_var.trace_add(
            "write", self._invalidate_verified_legacy_gallery,
        )
        self.api_key_var.trace_add(
            "write", self._invalidate_verified_legacy_gallery,
        )
        self.api_key_visible_var = tk.BooleanVar(value=False)
        self.api_key_status_var = tk.StringVar(value="API key configured" if self.config.api_key.strip() else "API key not configured")
        self.revoke_blocker_var = tk.StringVar(value="")
        self.interval_var = tk.StringVar(value=str(self.config.scan_interval_seconds))
        self.stable_var = tk.StringVar(value=str(self.config.stable_seconds))
        self.create_thumbnails_var = tk.BooleanVar(value=self.config.create_thumbnails)
        self.attach_sim_camera_metadata_var = tk.BooleanVar(value=self.config.attach_sim_camera_metadata)
        self.simconnect_dll_path_var = tk.StringVar(
            value=self.config.simconnect_dll_path
        )
        self.sync_utc_time_var = tk.BooleanVar(value=self.config.sync_utc_time)
        self.utc_sync_status_var = tk.StringVar(value="UTC sync stopped")
        self.delete_uploaded_files_var = tk.BooleanVar(value=self.config.delete_uploaded_files)
        self.watch_recursive_var = tk.BooleanVar(value=self.config.watch_recursive)
        self.manual_folder_recursive_var = tk.BooleanVar(value=False)
        self.delete_source_zip_var = tk.BooleanVar(value=False)
        self.manual_local_thumbnails_var = tk.BooleanVar(value=self.config.manual_thumbnail_mode == "local")
        self.manual_thumbnail_workers_var = tk.StringVar(value=format_worker_choice(self.config.manual_thumbnail_workers))
        self.manual_upload_workers_var = tk.StringVar(value=format_worker_choice(self.config.manual_upload_workers))
        self.inventory_refresh_var = tk.StringVar(value=f"{self.config.inventory_refresh_seconds:g}")
        self.archive_max_entries_var = tk.StringVar(value=str(self.config.archive_max_entries))
        self.archive_max_total_gib_var = tk.StringVar(value=f"{self.config.archive_max_uncompressed_bytes / (1024 ** 3):g}")
        self.archive_max_file_gib_var = tk.StringVar(value=f"{self.config.archive_max_file_bytes / (1024 ** 3):g}")
        self.archive_max_ratio_var = tk.StringVar(value=f"{self.config.archive_max_compression_ratio:g}")
        self.staging_cleanup_hours_var = tk.StringVar(value=f"{self.config.staging_cleanup_age_hours:g}")
        self.close_to_tray_var = tk.BooleanVar(value=self.config.close_to_tray)
        self.start_with_windows_var = tk.BooleanVar(
            value=self.config.start_with_windows,
        )
        self.settings_advanced_visible_var = tk.BooleanVar(value=False)
        self.ai_worker_enabled_var = tk.BooleanVar(value=self.config.ai_worker_enabled)
        self.ai_worker_poll_var = tk.StringVar(value=f"{self.config.ai_worker_poll_seconds:g}")
        self.ai_worker_lease_var = tk.StringVar(value=str(self.config.ai_worker_lease_seconds))
        self.ai_model_name_var = tk.StringVar(value=self.config.ai_model_name)
        self.ai_model_version_var = tk.StringVar(value=self.config.ai_model_version)
        self.ai_external_command_var = tk.StringVar(value=self.config.ai_external_command)
        self.ai_vision_backend_var = tk.StringVar(value=normalize_ai_vision_backend(self.config.ai_vision_backend))
        self.ai_transformers_caption_model_var = tk.StringVar(value=self.config.ai_transformers_caption_model)
        self.ai_transformers_detector_model_var = tk.StringVar(value=self.config.ai_transformers_detector_model)
        self.ai_transformers_object_labels_var = tk.StringVar(value=self.config.ai_transformers_object_labels)
        self.ai_transformers_detection_threshold_var = tk.StringVar(value=f"{self.config.ai_transformers_detection_threshold:g}")
        self.ai_ollama_url_var = tk.StringVar(value=self.config.ai_ollama_url)
        self.ai_ollama_model_var = tk.StringVar(value=self.config.ai_ollama_model)
        self.ai_semantic_prompt_var = tk.StringVar(value=self.config.ai_semantic_prompt)
        self.ai_advanced_visible_var = tk.BooleanVar(value=False)
        self.ai_advanced_frame = None
        self.settings_advanced_frame = None
        self.thumbnail_runtime_var = tk.StringVar(value=thumbnail_runtime_status())
        self.manual_selection_var = tk.StringVar(value="Choose files, a folder, or a ZIP archive")
        self.manual_preflight_var = tk.StringVar(value="Select a source to build an import summary. Nothing uploads until you press Start import.")
        self.manual_status_var = tk.StringVar(value="Import idle")
        self.manual_progress_text_var = tk.StringVar(value="No active import")
        self.manual_progress_var = tk.DoubleVar(value=0.0)
        self.status_var = tk.StringVar(value="Watcher stopped")
        self.watch_ignored_var = tk.StringVar(value="Ignored at startup: -")
        self.watch_last_scan_var = tk.StringVar(value="Last scan: -")
        self.ai_status_var = tk.StringVar(value="AI metadata worker idle")
        self.monitor_state_var = tk.StringVar(value="Idle")
        self.monitor_detail_var = tk.StringVar(value="No background worker is active.")
        self.connection_status_var = tk.StringVar(
            value="Not tested" if self.config.api_key.strip() else "",
        )
        self.connection_detail_var = tk.StringVar(value="Save or test the gallery connection in Settings.")
        self.gallery_label_var = tk.StringVar(value="Target gallery: unknown")
        self.gallery_title_var = tk.StringVar(value="")
        self.watcher_activity_var = tk.StringVar(value="Watch: stopped")
        self.watcher_header_count_var = tk.StringVar(value="–")
        self.watcher_header_time_var = tk.StringVar(value="–:–")
        self._watcher_queued_count: Optional[int] = None
        self._watcher_queue_worker: Optional[WatcherThread] = None
        self._watcher_last_scan_at: Optional[float] = None
        self.import_activity_var = tk.StringVar(value="Import: idle")
        self.ai_activity_var = tk.StringVar(value="AI: stopped")
        self.activity_filter_var = tk.StringVar(value="all")
        self.import_details_visible_var = tk.BooleanVar(value=False)
        self.activity_job_summary_var = tk.StringVar(value="No import job selected")
        self.latest_activity_var = tk.StringVar(value="Ready when you are")
        self.monitor_state = "disabled"
        self.monitor_detail = "No background worker is active."
        self.log_tags_ready = False
        self.watcher_action_button: Optional["ttk.Button"] = None
        self._contextual_watch_target: LinkedUploadTarget | None = None
        self._contextual_watch_is_flight = False
        self._watcher_key_closing = False
        self._watcher_recording_pulse = False
        self._watcher_recording_after: Optional[str] = None
        self._watcher_recording_target: LinkedUploadTarget | None = None
        self._watcher_recording_active = False
        self._watch_start_pending = False
        self.watch_start_progress: Optional[ttk.Progressbar] = None
        self.latest_activity_label: Optional["ttk.Label"] = None

        # Uncertain linked uploads remain blocked rather than recoverable, but
        # their staged ZIP entries must not be treated as disposable on restart.
        # No rollback-resistant witness currently proves that a staging job
        # missing from a valid local snapshot was never accepted. Age and a
        # legacy tolerant job listing are therefore insufficient to delete it.
        # Keep staged originals intact until durable admission/recovery exists.
        removed_staging: List[str] = []

        from uploader.update_ui import UpdateController
        self.updates = UpdateController(self, APP_VERSION)
        from uploader.flight_ui import FlightUI
        self.flights = FlightUI(
            self, CONFIG_DIR / "flights", normalize_upload_url,
            revoke_upload_key, self.resume_flight_photos,
        )
        self.build_ui()
        self.simulator_presence.start()
        self.configure_window_icon()
        self.start_tray_icon()
        self.startup_tray_deadline = (
            time.monotonic() + STARTUP_TRAY_TIMEOUT_SECONDS
        )
        self.root.protocol("WM_DELETE_WINDOW", self.request_window_close)
        self.root.bind("<Unmap>", self.handle_window_unmap, add="+")
        self.root.bind("<Configure>", self._schedule_ui_layout_save, add="+")
        self._schedule_event_drain(WATCHER_EVENT_IDLE_POLL_MS)
        if start_minimized:
            self.root.after(ACTIVATION_POLL_MS, self.complete_startup)
        else:
            self.root.after(350, self.offer_recoverable_jobs)
        if startup_error:
            self.write_log(
                "Windows startup registration failed: " + startup_error,
                "warning",
            )
        if removed_staging:
            self.write_log(f"Cleaned {len(removed_staging)} stale staging directorie(s).", "system")


    def complete_startup(self) -> None:
        """Keep a logon launch hidden only when the native tray becomes usable.

        Returns:
            None: Retains the tray-only launch, or restores a visible window
                after missing/failed/late tray initialization. Deferred import
                recovery remains an explicit action after the window is opened.
        """
        if self.exiting or not self.startup_waiting_for_tray:
            return
        if (
            self.tray_icon is not None and self.tray_icon.visible
            and self.tray_thread is not None and self.tray_thread.is_alive()
        ):
            self.startup_waiting_for_tray = False
            self.write_log("Windows startup: application is running in the tray.", "system")
            return
        if (
            self.tray_icon is None
            or self.tray_thread is None
            or not self.tray_thread.is_alive()
            or time.monotonic() >= self.startup_tray_deadline
        ):
            self.write_log(
                "Windows startup: tray unavailable; showing the application.",
                "warning",
            )
            self.restore_from_tray()
            return
        self.root.after(ACTIVATION_POLL_MS, self.complete_startup)

    def poll_instance_activation(self) -> None:
        """Deliver another process's restore request through the Tk main loop.

        Returns:
            None: Restores the existing window without restarting any worker;
                polling stops during confirmed application shutdown.
        """
        if self.exiting or self.instance is None:
            return
        try:
            if self.instance.activation_requested():
                self.restore_from_tray()
        except OSError as exc:
            self.write_log("Instance activation failed: " + str(exc), "warning")
            return
        self.root.after(ACTIVATION_POLL_MS, self.poll_instance_activation)

    def build_ui(self) -> None:
        """
        Create the themed navigation, primary watcher view, and live footer.

        Returns:
            None: Builds navigation, update notice, and application views;
            Photos is selected by default; startup mode remains withdrawn.
        """
        configure_theme(self.root)
        self.gallery_title_var.trace_add(
            "write",
            lambda *_trace_info: self.refresh_watcher_header(),
        )
        ttk.Style(self.root).configure(
            "Footer.Warning.TLabel",
            foreground="#F2C581",
        )
        ttk.Style(self.root).configure(
            "Footer.Error.TLabel",
            foreground="#FF91A2",
        )
        layout = self._ui_layout_state
        geometry = (
            f"{layout.width}x{layout.height}{layout.x:+d}{layout.y:+d}"
            if self._ui_layout_restored else WATCHER_WINDOW_GEOMETRY
        )
        self.root.geometry(geometry)
        bounds = _layout_display_bounds(self.root)
        self.root.minsize(
            min(WATCHER_WINDOW_MINIMUM[0], bounds[2]),
            min(WATCHER_WINDOW_MINIMUM[1], bounds[3]),
        )
        outer = ttk.Frame(
            self.root,
            padding=(16, 12, 16, 10),
            style="Backdrop.TFrame",
        )
        paint_backdrop(outer)
        outer.pack(fill="both", expand=True)
        outer.columnconfigure(1, weight=1)
        outer.rowconfigure(2, weight=1)

        header = ttk.Frame(outer, style="App.TFrame")
        header.grid(row=0, column=0, columnspan=2, sticky="ew", pady=(0, 10))
        header.columnconfigure(1, weight=1)
        ttk.Label(
            header,
            text="PHP Gallery",
            style="Brand.TLabel",
        ).grid(row=0, column=0, sticky="w")
        ttk.Label(
            header,
            text="Your gallery on autopilot  ·  " + APP_VERSION,
            style="App.Eyebrow.TLabel",
        ).grid(row=1, column=0, sticky="w", pady=(2, 0))
        status = ttk.Frame(header, style="App.TFrame")
        status.grid(row=0, column=2, rowspan=2, sticky="e")
        self._storage_header = ttk.Frame(status, style="App.TFrame")
        self._storage_header.pack(side="left", padx=(0, 10))
        self._storage_context_surface = outer
        self.monitor_light = tk.Canvas(
            status,
            width=16,
            height=16,
            highlightthickness=0,
            bd=0,
            background="#131B26",
        )
        self.monitor_light.pack(side="left", padx=(0, 6))
        watcher_display = GlassCard(header, padding=(8, 4))
        watcher_display.grid(
            row=0, column=1, rowspan=2, sticky="e", padx=(12, 10),
        )
        self._watcher_header_icon_cache: Dict[str, ThemeImage] = {}
        self._watcher_header_status_icon = ttk.Label(
            watcher_display, style="Card.TLabel",
        )
        self._watcher_header_status_icon.pack(side="left", padx=(0, 8))
        self._watcher_header_queue_image = create_smooth_icon(
            watcher_display, "queue", WATCHER_HEADER_ICON_SIZE,
        )
        self._watcher_header_queue_icon = ttk.Label(
            watcher_display,
            image=self._watcher_header_queue_image or "",
            text="▧" if self._watcher_header_queue_image is None else "",
            style="Card.TLabel",
        )
        self._watcher_header_queue_icon.pack(side="left", padx=(0, 3))
        self.watcher_header_count_label = ttk.Label(
            watcher_display,
            textvariable=self.watcher_header_count_var,
            style="Card.TLabel",
        )
        self.watcher_header_count_label.pack(side="left", padx=(0, 8))
        self._watcher_header_clock_image = create_smooth_icon(
            watcher_display, "clock", WATCHER_HEADER_ICON_SIZE,
        )
        self._watcher_header_clock_icon = ttk.Label(
            watcher_display,
            image=self._watcher_header_clock_image or "",
            text="◷" if self._watcher_header_clock_image is None else "",
            style="Card.TLabel",
        )
        self._watcher_header_clock_icon.pack(side="left", padx=(0, 3))
        self.watcher_header_time_label = ttk.Label(
            watcher_display,
            textvariable=self.watcher_header_time_var,
            style="Card.TLabel",
        )
        self.watcher_header_time_label.pack(side="left")
        self._watcher_header_stop_hint = tk.StringVar(
            self.root,
            tr(self.flights.ui_language.get(), "watcher_header_stop"),
        )
        self._watcher_header_stop_button = icon_button(
            watcher_display, "stop", self.stop,
            self._watcher_header_stop_hint, tooltip_surface=outer,
            style_override="Photos.Icon.TButton",
        )
        self._watcher_header_hint = tk.StringVar(self.root)
        for trigger in (
            self._watcher_header_status_icon,
            self._watcher_header_queue_icon,
            self.watcher_header_count_label,
            self._watcher_header_clock_icon,
            self.watcher_header_time_label,
        ):
            attach_inline_tooltip(trigger, self._watcher_header_hint, outer)
        self.refresh_watcher_header()
        self.build_gallery_link_bar(header)
        self.check_updates_button = icon_button(
            header,
            "refresh",
            self.updates.check,
            "Check for updates or open the available update",
        )
        self.check_updates_button.grid(
            row=0,
            column=3,
            rowspan=2,
            padx=4,
        )
        self.update_banner = GlassCard(outer, padding=(12, 6))
        self.update_banner.grid(
            row=1,
            column=0,
            columnspan=2,
            sticky="ew",
            pady=(0, 8),
        )
        self.update_banner.columnconfigure(0, weight=1)
        self.update_banner_label = ttk.Label(
            self.update_banner,
            text="",
            style="Section.TLabel",
        )
        self.update_banner_label.grid(row=0, column=0, sticky="w")
        self.update_banner_action = ttk.Button(
            self.update_banner,
            text="Open update",
            command=self.open_update_notice,
            style="Primary.TButton",
        )
        self.update_banner_action.grid(row=0, column=1, sticky="e", padx=(12, 0))
        self.update_banner.grid_remove()
        help_button(
            header,
            "Watcher help",
            "Choose a folder and connect a gallery. Start watching ignores files already present, then uploads new supported images after they finish copying. Files stay on this PC unless confirmed deletion is explicitly enabled.",
        ).grid(row=0, column=5, rowspan=2, padx=(4, 0))

        rail = ttk.Frame(outer, style="App.TFrame", padding=(0, 4, 6, 0))
        rail.grid(row=2, column=0, sticky="ns")
        self.navigation_buttons: Dict[str, ttk.Button] = {}
        self.notebook = ttk.Notebook(outer, style="App.TNotebook")
        self.notebook.grid(row=2, column=1, sticky="nsew")
        tabs: Dict[str, ttk.Frame | ScrollableFrame] = {}
        self.tab_indices: Dict[str, int] = {}
        for key, label in [
            (
                "photos",
                "▧  " + tr(
                    self.flights.ui_language.get(),
                    "photos_pane_title",
                ),
            ),
            ("watch", "◉  Watcher"),
            ("import", "⇧  Import"),
            ("activity", "≡  Activity"),
            ("ai", "✦  AI"),
            ("settings", "⚙  Settings"),
        ]:
            host = ttk.Frame(self.notebook, style="Backdrop.TFrame")
            paint_backdrop(host)
            host.rowconfigure(0, weight=1)
            host.columnconfigure(0, weight=1)
            if key == "photos":
                page = ttk.Frame(host, style="Backdrop.TFrame")
                page.grid(row=0, column=0, sticky="nsew")
                paint_backdrop(page)
                tabs[key] = page
            else:
                scroll = ScrollableFrame(host)
                scroll.grid(row=0, column=0, sticky="nsew")
                tabs[key] = scroll
            self.notebook.add(host, text=label)
            self.tab_indices[key] = self.notebook.index(host)
            self.navigation_buttons[key] = icon_button(
                rail, label.split()[0],
                lambda name=key: self.select_tab(name), label.split()[-1],
            )
            self.navigation_buttons[key].pack(pady=(0, 8))
            if key == "watch":
                self.navigation_buttons[key].configure(text="◉")
        self.tab_hosts = tabs

        self.build_photos_tab(tabs["photos"])
        for sequence in ("<Button-3>", "<Button-2>", "<Control-Button-1>"):
            self.navigation_buttons["watch"].bind(
                sequence, self.open_watcher_rail_context, add="+",
            )
        self.build_watch_tab(tabs["watch"].content)
        self.build_manual_tab(tabs["import"].content)
        self.build_activity_tab(tabs["activity"].content)
        self.build_ai_tab(tabs["ai"].content)
        self.build_settings_tab(tabs["settings"].content)
        self._sync_watcher_recording()
        self.notebook.select(self.tab_indices["photos"])
        self.notebook.bind("<<NotebookTabChanged>>", self.refresh_navigation)
        self.refresh_navigation()
        if (layout.window_state == "zoomed"
                and not self.startup_waiting_for_tray):
            try:
                self.root.state("zoomed")
            except tk.TclError:
                pass

        footer = ttk.Frame(outer, style="Footer.TFrame", padding=(12, 8))
        footer.grid(row=3, column=0, columnspan=2, sticky="ew", pady=(12, 0))
        footer.columnconfigure(1, weight=1)
        ttk.Label(footer, text="LATEST", style="Eyebrow.TLabel").grid(row=0, column=0, sticky="w", padx=(0, 12))
        self.latest_activity_label = ttk.Label(
            footer,
            textvariable=self.latest_activity_var,
            style="Footer.TLabel",
        )
        self.latest_activity_label.grid(row=0, column=1, sticky="ew")
        icon_button(
            footer,
            "≡",
            lambda: self.select_tab("activity"),
            "Open full activity history",
        ).grid(row=0, column=2, padx=(8, 0))

        self.write_log(f"Configuration: {CONFIG_PATH}", "system")
        self.write_log(f"State: {STATE_PATH}", "system")
        self.write_log(f"Log: {LOG_PATH}", "system")
        self.write_log(thumbnail_runtime_status(), "system")
        self.refresh_connection_controls()
        self.update_monitor_state("disabled", "No background worker is active.")
        self.set_update_available(self.update_available_version)

    def build_gallery_link_bar(self, header: "ttk.Frame") -> None:
        """Group the selected site, gallery picker and connection actions.

        Args:
            header (ttk.Frame): Main window header receiving the linking row.

        Returns:
            None: Shows the live site and opens the separate links chooser.
        """
        bar = ttk.Frame(header, style="App.TFrame")
        bar.grid(row=2, column=0, columnspan=6, sticky="w", pady=(5, 0))
        address = ttk.Label(
            bar, style="App.Status.TLabel", wraplength=WINDOW_SUMMARY_WRAP,
        )
        choose = ttk.Button(
            bar, text="Choose gallery…", command=self.choose_linked_gallery,
            style="Accent.Compact.TButton",
        )
        link = ttk.Button(
            bar, command=self.link_gallery, style="Compact.TButton",
        )
        link.grid(row=0, column=2, sticky="w")
        links = icon_button(
            bar, "⋯", self.open_gallery_links,
            tr(self.flights.ui_language.get(), "linking_address_manager"),
        )
        linked_address = self.linking_manager.linked_address
        status = self.linking_manager.status
        feedback = ttk.Label(
            bar, textvariable=status, style="App.Status.TLabel",
            wraplength=WINDOW_SUMMARY_WRAP,
        )

        def refresh(*_trace_info: str) -> None:
            """Show only the controls and feedback for the live link state.

            Args:
                *_trace_info (str): Tk variable name, index and write operation.

            Returns:
                None: Shows gallery choice, unlinking and link management.
            """
            issuer = linked_address.get()
            link.configure(text="Unlink gallery" if issuer else "Link gallery")
            notebook = getattr(self, "notebook", None)
            tab_indices = getattr(self, "tab_indices", {})
            photos_selected = bool(
                notebook is not None
                and notebook.index("current") == tab_indices.get("photos")
            )
            if issuer:
                address.configure(text="Linked: " + issuer)
                address.grid(row=0, column=0, sticky="w", padx=(0, 8))
                if photos_selected:
                    link.grid_remove()
                    choose.grid_remove()
                    links.grid(row=0, column=1, sticky="w", padx=(2, 0))
                else:
                    link.grid()
                    choose.grid(row=0, column=1, sticky="w", padx=(0, 6))
                    links.grid(row=0, column=3, sticky="w", padx=(2, 0))
            else:
                if photos_selected:
                    link.grid_remove()
                else:
                    link.grid()
                address.grid_remove()
                choose.grid_remove()
                links.grid_remove()
            if status.get():
                feedback.grid(
                    row=1, column=0, columnspan=4, sticky="w", pady=(4, 0),
                )
            else:
                feedback.grid_remove()

        self._gallery_link_refresh = refresh
        address_trace = linked_address.trace_add("write", refresh)
        status_trace = status.trace_add("write", refresh)
        bar.bind("<Destroy>", lambda event: (
            linked_address.trace_remove("write", address_trace),
            status.trace_remove("write", status_trace),
        ) if event.widget is bar else None, add="+")
        refresh()

    def set_update_available(self, version: Optional[str]) -> None:
        """Show or clear the verified uploader update across app surfaces.

        Args:
            version (Optional[str]): Available uploader release version, or
                None after a successful check finds no current offer.

        Returns:
            None: Updates the global banner, marked check icon, tray image,
                tooltip and menu while preserving verified state on Later.
        """
        normalized_version = version.strip() if version is not None else ""
        self.update_available_version = normalized_version or None
        has_update = self.update_available_version is not None
        if self.check_updates_button is not None:
            self.check_updates_button.configure(
                text="↑" if has_update else "↻",
                command=(
                    self.open_update_notice
                    if has_update
                    else self.updates.check
                ),
            )
        if self.update_banner is not None:
            if has_update:
                self.update_banner_label.configure(
                    text=f"Update {self.update_available_version} available"
                )
                self.update_banner.grid()
            else:
                self.update_banner.grid_remove()

        tray_icon = self.tray_icon
        if tray_icon is None:
            return
        try:
            tray_icon.icon = self.make_tray_icon_image()
            tray_icon.title = self.tray_tooltip_text()
            tray_icon.menu = self.build_tray_menu()
            tray_icon.update_menu()
        except Exception:  # noqa: BLE001
            logging.debug("Could not refresh tray update indication.", exc_info=True)

    def open_update_notice(self) -> None:
        """Restore the app and open the controller's current update notice.

        Returns:
            None: Shows the known offer or begins a manual check when idle.
        """
        self.restore_from_tray()
        self.updates.open_updates()

    def tray_update_menu_label(self) -> str:
        """Return the tray menu's current update action caption.

        Returns:
            str: ``Update <version>`` for an available offer, otherwise
                ``Check for updates``.
        """
        if self.update_available_version is None:
            return "Check for updates"
        return f"Update {self.update_available_version}"

    def select_tab(self, name: str) -> None:
        """Select a named view in the application navigation.

        Args:
            name (str): Stable tab key from ``tab_indices``.

        Returns:
            None: Changes the selected notebook page when the key is known.
        """
        index = self.tab_indices.get(name)
        if index is not None:
            self.notebook.select(index)

    def refresh_navigation(self, _event: "tk.Event | None" = None) -> None:
        """Highlight the current screen in the compact icon navigation rail.

        Args:
            _event (tk.Event | None): Optional notebook selection event.

        Returns:
            None: Highlights the selected page and updates contextual link actions.
        """
        selected = self.notebook.index("current")
        for key, button in self.navigation_buttons.items():
            button.configure(style=(
                "IconPrimary.TButton"
                if self.tab_indices[key] == selected else "App.Icon.TButton"
            ))
        if getattr(self, "_watcher_recording_active", False):
            self._render_watcher_recording()
        refresh_gallery_link = getattr(self, "_gallery_link_refresh", None)
        if refresh_gallery_link is not None:
            refresh_gallery_link()

    def toggle_watcher(self) -> None:
        """Dispatch the primary Watcher action for the current session state.

        Returns:
            None: Starts, finishes or opens the pending queue for recovery.
        """
        if getattr(self, "_watch_start_pending", False) is True:
            return
        state = self.flights.session_state()
        if state == "working":
            self.flights.show_progress()
        elif state in ("pending", "blocked"):
            if not self._retry_update_continuation():
                self.flights.show_recovery()
        elif state == "active":
            self.stop()
        else:
            if isinstance(getattr(self, "_contextual_watch_target", None), LinkedUploadTarget):
                self.release_contextual_watcher()
                return
            self.start()

    def refresh_watcher_action(self) -> None:
        """Match the primary Watcher caption to capture, drain or recovery.

        Returns:
            None: Updates the primary action even after a stopped worker.
        """
        if self.watcher_action_button is None:
            return
        if getattr(self, "_watch_start_pending", False) is True:
            self.watcher_action_button.configure(
                text="Starting Watcher…", state="disabled",
            )
            return
        self.watcher_action_button.configure(state="normal")
        state = self.flights.session_state()
        if (state == "active"
                and isinstance(getattr(self, "_contextual_watch_target", None),
                               LinkedUploadTarget)):
            self.contextual_watcher_is_flight()
        labels = {
            "active": "■  Stop Watcher",
            "working": self.flights.text("show_upload_progress"),
            "pending": self.flights.text("review_pending_uploads"),
            "blocked": self.flights.text("review_pending_uploads"),
            "idle": "▶  Start Watcher",
        }
        if (state in ('pending', 'blocked')
                and getattr(self, '_resume_retry_available', False)):
            labels[state] = '▶  Resume Watcher'
        self.watcher_action_button.configure(text=labels[state])
        indicator = getattr(self, "watcher_capture_label", None)
        if indicator is not None:
            if state == "active":
                indicator.grid()
            else:
                indicator.grid_remove()
        if (state == "idle"
                and isinstance(getattr(self, "_contextual_watch_target", None),
                               LinkedUploadTarget)):
            self.watcher_action_button.configure(
                text="Closing Watcher access…" if self._watcher_key_closing
                else "Retry closing Watcher access",
            )

    def _set_watcher_recording(
        self, active: bool, target: LinkedUploadTarget | None = None,
    ) -> None:
        """Start or stop the app-wide Watcher recording marker pulse.

        Args:
            active (bool): Whether an active folder capture owns the indicator.
            target (LinkedUploadTarget | None): Exact linked target, or None for
                legacy Watcher capture.

        Returns:
            None: Updates the rail and Photos workspace and owns one Tk timer.
        """
        if not active or self.exiting:
            if self._watcher_recording_after is not None:
                self.root.after_cancel(self._watcher_recording_after)
                self._watcher_recording_after = None
            self._watcher_recording_target = None
            self._watcher_recording_active = False
            self._watcher_recording_pulse = False
            self._render_watcher_recording()
            return
        self._watcher_recording_target = target
        self._watcher_recording_active = True
        if self._watcher_recording_after is None:
            self._watcher_recording_after = self.root.after(
                WATCHER_RECORDING_PULSE_MS, self._pulse_watcher_recording,
            )
        self._render_watcher_recording()

    def _sync_watcher_recording(self) -> None:
        """Hydrate the recording marker from the live worker lifecycle.

        Returns:
            None: Reflects active capture without repolling or repainting Photos.
        """
        worker = getattr(self, "worker", None)
        active = bool(
            not self.exiting and worker is not None and worker.is_alive()
            and not worker.stop_event.is_set()
            and not worker.drain_only
            and not worker.drain_event.is_set()
            and getattr(worker, "initial_captured", True)
        )
        target = getattr(worker, "linked_target", None) if active else None
        if (not isinstance(target, LinkedUploadTarget)
                and isinstance(getattr(self, "_contextual_watch_target", None),
                               LinkedUploadTarget)):
            target = self._contextual_watch_target
        self._set_watcher_recording(
            active,
            target if isinstance(target, LinkedUploadTarget) else None,
        )

    def _pulse_watcher_recording(self) -> None:
        """Alternate visible red shades while Watcher capture remains active.

        Returns:
            None: Schedules the next bounded pulse or clears stale worker state.
        """
        self._watcher_recording_after = None
        worker = getattr(self, "worker", None)
        if (self.exiting or worker is None or not worker.is_alive()
                or worker.stop_event.is_set() or worker.drain_only):
            self._set_watcher_recording(False)
            return
        self._watcher_recording_pulse = not self._watcher_recording_pulse
        self._render_watcher_recording()
        self._watcher_recording_after = self.root.after(
            WATCHER_RECORDING_PULSE_MS, self._pulse_watcher_recording,
        )

    def _render_watcher_recording(self) -> None:
        """Paint rail, tray and header markers and forward Photos target state.

        Returns:
            None: Updates rail, tray, header and Photos from the shared pulse.
        """
        button = getattr(self, "navigation_buttons", {}).get("watch")
        if button is not None:
            if self._watcher_recording_active:
                button.configure(style=(
                    "Watcher.RecordingBright.Icon.TButton"
                    if self._watcher_recording_pulse
                    else "Watcher.RecordingDim.Icon.TButton"
                ))
            else:
                notebook = getattr(self, "notebook", None)
                try:
                    selected = (
                        notebook is not None
                        and self.tab_indices.get("watch")
                        == notebook.index("current")
                    )
                except (AttributeError, tk.TclError):
                    selected = False
                button.configure(style=(
                    "IconPrimary.TButton" if selected else "App.Icon.TButton"
                ))
        workspace = getattr(self, "photos_workspace", None)
        callback = getattr(workspace, "set_watcher_recording", None)
        if callback is not None:
            callback(
                self._watcher_recording_target,
                self._watcher_recording_pulse,
            )
        tray_icon = getattr(self, "tray_icon", None)
        if tray_icon is not None:
            try:
                tray_icon.icon = self.make_tray_icon_image()
            except Exception:  # noqa: BLE001
                logging.debug("Could not refresh tray Watcher marker.", exc_info=True)
        self.refresh_watcher_header()


    def configure_window_icon(self) -> None:
        """
        Apply the bundled Windows icon to the Tkinter window when available.
        """
        if not TRAY_ICON_ICO_PATH.is_file():
            return
        try:
            self.root.iconbitmap(default=str(TRAY_ICON_ICO_PATH))
        except Exception as exc:  # noqa: BLE001
            logging.warning("Window icon could not be loaded: %s", exc)

    def make_tray_icon_image(self) -> "Image.Image":
        """Load the base art and compose current update and Watcher badges.

        Raises:
            RuntimeError: Tray image support is unavailable without Pillow.
            OSError: The bundled tray image cannot be read.

        Returns:
            Image.Image: Fresh RGBA tray artwork derived from the bundled base.
        """
        if Image is None:
            raise RuntimeError("Pillow is required to compose the tray icon.")
        with Image.open(TRAY_ICON_PNG_PATH) as opened:
            base_image = opened.convert("RGBA")
        return render_tray_icon(
            base_image,
            self.update_available_version is not None,
            self._watcher_recording_active and self._watcher_recording_pulse,
        )

    def build_tray_menu(self) -> Optional["pystray.Menu"]:
        """Create the tray menu with an update-aware first action.

        Returns:
            Optional[pystray.Menu]: Current native tray menu, or None when
                pystray is unavailable in this runtime.
        """
        if pystray is None:
            return None
        return pystray.Menu(
            pystray.MenuItem(self.tray_update_menu_label(), self.tray_open_updates),
            pystray.MenuItem("Open", self.tray_restore_window, default=True),
            pystray.MenuItem("Open activity", self.tray_open_activity),
            pystray.MenuItem("Connection / API key...", self.tray_open_connection),
            pystray.MenuItem("Revoke API key...", self.tray_revoke_api_key),
            pystray.MenuItem("Pause/resume current import", self.tray_toggle_import_pause),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem("Start watching", self.tray_start_watching),
            pystray.MenuItem("Stop watching", self.tray_stop_watching),
            pystray.MenuItem("Start AI metadata worker", self.tray_start_ai_worker),
            pystray.MenuItem("Stop AI metadata worker", self.tray_stop_ai_worker),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem("Open log folder", self.tray_open_log_folder),
            pystray.MenuItem("Exit", self.tray_exit_application),
        )

    def start_tray_icon(self) -> None:
        """Start the Windows tray icon with current update state.

        Returns:
            None: Starts Windows tray integration when supported; the macOS
                source app remains available in its main window.
        """
        if sys.platform == "darwin":
            # Tk owns the Cocoa main loop. A Windows-style tray worker would
            # call AppKit off the main thread and terminate the source app.
            return
        if pystray is None or Image is None:
            self.write_log("System tray unavailable. Run install.bat to install pystray and Pillow.", "warning")
            return
        if not TRAY_ICON_PNG_PATH.is_file():
            self.write_log(f"System tray icon asset is missing: {TRAY_ICON_PNG_PATH}", "warning")
            return

        try:
            self.tray_icon = pystray.Icon(
                APP_NAME,
                self.make_tray_icon_image(),
                self.tray_tooltip_text(),
                self.build_tray_menu(),
            )
            self.tray_thread = threading.Thread(target=self.tray_icon.run, name="PHPGalleryTray", daemon=True)
            self.tray_thread.start()
            self.write_log("System tray icon is active.", "system")
        except Exception as exc:  # noqa: BLE001
            self.tray_icon = None
            self.tray_thread = None
            self.write_log(f"System tray could not be started: {exc}", "warning")

    def schedule_ui(self, callback: Callable[[], None]) -> None:
        """
        Run a tray callback on the Tkinter UI thread.

        Args:
            callback (Callable[[], None]): UI action called without arguments
                on the Tk thread; it returns no value.

        Returns:
            None: Run a tray callback on the Tkinter UI thread.
        """
        if self.exiting:
            return
        try:
            self.root.after(0, callback)
        except Exception:  # noqa: BLE001
            logging.debug("Ignored tray callback after Tk shutdown.", exc_info=True)

    def tray_restore_window(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Restore the hidden window from a tray icon action.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.restore_from_tray)

    def tray_open_updates(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Restore the application and open the existing update notice.

        Args:
            *_args (pystray.Icon | pystray.MenuItem): Native tray callback
                arguments supplied by pystray; direct calls may omit them.

        Returns:
            None: Schedules the updater entry point on Tk's UI thread.
        """
        self.schedule_ui(self.open_update_notice)

    def tray_open_activity(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Restore the window and select the Activity section from the tray.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.open_activity_tab)

    def tray_open_connection(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Open the connection and API-key editor from the tray.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.open_connection_dialog)

    def tray_revoke_api_key(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Start the guarded API-key revocation flow from the tray.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.revoke_api_key)

    def tray_toggle_import_pause(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Pause or resume the current manual import from the tray.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        def toggle() -> None:
            worker = self.manual_worker
            if worker is None or not worker.is_alive():
                self.write_log("No active manual import to pause or resume.", "system")
                return
            if worker.pause_event.is_set():
                self.resume_manual_upload()
            else:
                self.pause_manual_upload()
        self.schedule_ui(toggle)

    def tray_open_log_folder(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Open the local app/log folder from the tray.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.open_config_folder)

    def tray_start_watching(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Start the watcher from the tray menu.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.start)

    def tray_stop_watching(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Stop the watcher from the tray menu.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.stop)

    def tray_start_ai_worker(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Start the optional AI metadata worker from the tray menu.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.start_ai_worker)

    def tray_stop_ai_worker(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Stop the optional AI metadata worker from the tray menu.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.stop_ai_worker)

    def tray_exit_application(
        self, *_args: "pystray.Icon | pystray.MenuItem"
    ) -> None:
        """Exit the application from the tray menu.

        Args:
            _args (pystray.Icon | pystray.MenuItem): Unused tray icon
                and menu item supplied by pystray; direct calls may
                omit both arguments.

        Returns:
            None: Schedules the tray action on the Tk thread.
        """
        self.schedule_ui(self.close)

    def request_window_close(self) -> None:
        """
        Apply the configured close-to-tray policy or perform coordinated exit.
        """
        if self.tray_icon and self.close_to_tray_var.get():
            self.hide_to_tray()
            return
        self.close()

    def api_key_request_blockers(self) -> List[str]:
        """
        Return exact activities that can still issue authenticated gallery requests.

        Returns:
            List[str]: Human-readable blocker labels.
        """
        blockers: List[str] = []
        if self.worker and self.worker.is_alive():
            blockers.append("watch-folder uploader is running")
        if self.manual_worker and self.manual_worker.is_alive():
            blockers.append("manual import is running")
        if self.preflight_worker and self.preflight_worker.is_alive():
            blockers.append("import preflight is running")
        if self.ai_worker and self.ai_worker.is_alive():
            blockers.append("AI metadata worker is running")
        if self.connection_test_running:
            blockers.append("connection test is running")
        flights = getattr(self, "flights", None)
        operation = getattr(flights, "operation", None)
        if (
            operation is not None
            and getattr(operation, "saved_upload", False) is True
            and operation.is_alive()
        ):
            blockers.append("saved flight PDF upload is running")
        return blockers

    def background_work_active(self) -> bool:
        """
        Return whether any background worker or long-running lifecycle operation is running.

        Returns:
            bool: True when background work is active.
        """
        return bool(
            self.api_key_request_blockers()
            or self.semantic_ai_install_running
            or self.api_key_revoke_running
            or getattr(self, "_watcher_key_closing", False) is True
        )

    def handle_window_unmap(self, event: "tk.Event") -> None:
        """
        Convert normal window minimization into tray hiding.

        Args:
            event (tk.Event): Unmap notification whose widget identifies the
                Tk window being minimized.

        Returns:
            None: Convert normal window minimization into tray hiding.
        """
        if self.exiting or self.window_hidden_to_tray or event.widget is not self.root:
            return
        self.root.after(100, self.hide_if_minimized)

    def hide_if_minimized(self) -> None:
        """
        Hide the window to tray after a user minimize action when configured.
        """
        if self.exiting or self.window_hidden_to_tray or not self.tray_icon or not self.close_to_tray_var.get():
            return
        try:
            if self.root.state() == "iconic":
                self.hide_to_tray()
        except Exception:  # noqa: BLE001
            logging.debug("Ignored minimize-to-tray check after Tk shutdown.", exc_info=True)

    def hide_to_tray(self) -> None:
        """Hide the window while keeping the app alive in the system tray.

        Returns:
            None: Saves a safe layout snapshot before withdrawing the window.
        """
        if not self.tray_icon:
            return
        self._save_ui_layout_now(allow_iconic=True)
        self.window_hidden_to_tray = True
        self.root.withdraw()
        self.write_log("Window hidden to tray. Use the tray icon to restore it.", "system")

    def restore_from_tray(self) -> None:
        """Show the existing window after a tray action or explicit relaunch.

        Returns:
            None: Restores/focuses the window and admits deferred recovery once.
        """
        if self.exiting:
            return
        self.window_hidden_to_tray = False
        self.startup_waiting_for_tray = False
        self.root.deiconify()
        try:
            requested_state = self._ui_layout_state.window_state
            self.root.state(
                "zoomed" if requested_state == "zoomed" else "normal",
            )
        except Exception:  # noqa: BLE001
            logging.debug("Could not force normal window state.", exc_info=True)
        self.root.lift()
        self.root.focus_force()
        if getattr(self, "recovery_offer_pending", False):
            self.recovery_offer_pending = False
            self.root.after_idle(self.offer_recoverable_jobs)

    def stop_tray_icon(self) -> None:
        """
        Stop the tray icon loop during application shutdown.
        """
        icon = self.tray_icon
        self.tray_icon = None
        if icon is None:
            return
        try:
            icon.visible = False
            icon.stop()
        except Exception:  # noqa: BLE001
            logging.debug("Tray icon shutdown failed.", exc_info=True)

    def build_photos_tab(self, parent: "tk.Misc") -> None:
        """Build the main-window gallery tree and embedded photo workspace.

        Args:
            parent (tk.Misc): Main-window frame for the Photos page.

        Returns:
            None: Loads the exact current linked session without another window.
        """
        preferred_tree_width = self._ui_layout_state.photos_tree_width
        preferred_info_width = self._ui_layout_state.photos_info_width
        parent.rowconfigure(0, weight=1)
        parent.columnconfigure(0, weight=1)
        self.photos_workspace = PhotosWorkspace(
            parent,
            self.linking_manager.photos_session,
            self.open_photos,
            language=lambda: self.flights.ui_language.get(),
            manager=self.linking_manager,
            format_bytes=self.format_bytes,
            manage_link=self.manage_gallery_link,
            start_watcher=self.open_gallery_watcher,
            simbrief_available=self.simbrief_available,
            simbrief_action=self.flights.toggle_simbrief,
            load_tree_state=self.load_photos_tree_state,
            save_tree_state=self.save_photos_tree_state,
            load_selected_gallery=self.load_photos_selected_gallery,
            save_selected_gallery=self.save_photos_selected_gallery,
            load_card_format=self.load_photos_card_format,
            save_card_format=self.save_photos_card_format,
            catalogue_cache=self.photos_catalogue_cache,
            load_tree_width=lambda: preferred_tree_width,
            save_tree_width=self.save_photos_tree_width,
            load_info_width=lambda: preferred_info_width,
            save_info_width=self.save_photos_info_width,
            storage_parent=getattr(self, '_storage_header', None),
            storage_surface=getattr(self, '_storage_context_surface', None),
            diagnostic_callback=lambda message: self.events.put(
                ('warning', '[Photos] ' + message),
            ),
        )
        self.photos_workspace.grid(row=0, column=0, sticky="nsew")
        self._sync_watcher_recording()
        address_trace = self.linking_manager.linked_address.trace_add(
            "write", lambda *_trace_info: self.photos_workspace.refresh_session(),
        )
        self.photos_workspace.bind(
            "<Destroy>",
            lambda event: self.linking_manager.linked_address.trace_remove(
                "write", address_trace,
            ) if event.widget is self.photos_workspace else None,
            add="+",
        )

    def _photos_tree_state_path(self, session: LinkingSession) -> Path | None:
        """Build a credential-free preference path for one verified account.

        Args:
            session (LinkingSession): Exact current Photos session.

        Returns:
            Path | None: Hashed site/account preference path, or None when stale.
        """
        identity_reader = getattr(self.linking_manager, "photos_site_identity", None)
        identity = identity_reader(session) if identity_reader is not None else None
        if identity is None:
            return None
        issuer, user_id = identity
        key = hashlib.sha256(
            f"{issuer}\0{user_id}".encode("utf-8"),
        ).hexdigest()
        return CONFIG_DIR / "photos-tree" / f"{key}.json"

    def photos_catalogue_cache(
        self, session: LinkingSession,
    ) -> GalleryCatalogueCache | None:
        """Create the optional catalogue cache for the live approved identity.

        Args:
            session (LinkingSession): Exact current Photos session.

        Returns:
            GalleryCatalogueCache | None: Issuer/account/scope-bound cache, or
                None when the manager cannot verify the current account.
        """
        identity_reader = getattr(
            self.linking_manager, "photos_site_identity", None,
        )
        identity = identity_reader(session) if identity_reader is not None else None
        if identity is None:
            return None
        issuer, account_id = identity
        try:
            return GalleryCatalogueCache.for_current_user(
                issuer, account_id, session.approved_scope(),
            )
        except (OSError, TypeError, ValueError, LinkingError):
            return None

    def load_photos_card_format(
        self, session: LinkingSession, gallery_id: int,
    ) -> str:
        """Load a local grid preference for the verified Photos identity.

        Args:
            session (LinkingSession): Current approved site/account session.
            gallery_id (int): Selected gallery's positive identity.
        Returns:
            str: Supported saved format, or default without verified local state.
        """
        account_path = self._photos_tree_state_path(session)
        if account_path is None:
            return "default"
        view_path = account_path.with_name(account_path.stem + ".view.json")
        return load_gallery_card_format(view_path, gallery_id)

    def save_photos_card_format(
        self, session: LinkingSession, gallery_id: int, card_format: str,
    ) -> bool:
        """Persist a WinApp-only grid choice without touching upload settings.

        Args:
            session (LinkingSession): Current approved site/account session.
            gallery_id (int): Selected gallery's positive identity.
            card_format (str): Supported local card-format key.
        Returns:
            bool: True only after the verified account's atomic local write;
                False when identity or persistence cannot be confirmed.
        """
        account_path = self._photos_tree_state_path(session)
        if account_path is None:
            return False
        view_path = account_path.with_name(account_path.stem + ".view.json")
        return save_gallery_card_format(view_path, gallery_id, card_format)

    def _schedule_ui_layout_save(self, event: "tk.Event") -> None:
        """Debounce visible main-window geometry updates on the Tk owner.

        Args:
            event (tk.Event): Configure event for the root or one of its children.

        Returns:
            None: Root-only changes schedule one bounded layout write.
        """
        if event.widget is not self.root or self.exiting:
            return
        if self._ui_layout_save_after is not None:
            try:
                self.root.after_cancel(self._ui_layout_save_after)
            except tk.TclError:
                pass
        self._ui_layout_save_after = self.root.after(
            UI_LAYOUT_SAVE_DELAY_MS, self._save_ui_layout_now,
        )

    def _save_ui_layout_now(self, *, allow_iconic: bool = False) -> None:
        """Persist the last valid normal geometry and current maximize state.

        Args:
            allow_iconic (bool): During minimize-to-tray only, accept a still
                normal-sized window while retaining its last safe position.

        Returns:
            None: Transient minimized/withdrawn/fullscreen/tiny geometry is
                ignored; minimize-to-tray can save a still normal-sized sample.
        """
        self._ui_layout_save_after = None
        if self.exiting:
            return
        current = self._ui_layout_state
        try:
            window_state = self.root.state()
            fullscreen = bool(self.root.attributes("-fullscreen"))
            visible = bool(self.root.winfo_viewable())
            width = int(self.root.winfo_width())
            height = int(self.root.winfo_height())
            x = int(self.root.winfo_x())
            y = int(self.root.winfo_y())
        except (tk.TclError, TypeError, ValueError):
            return
        iconic_normal = (
            allow_iconic and window_state == "iconic"
            and current.window_state == "normal"
            and width >= WATCHER_WINDOW_MINIMUM[0]
            and height >= WATCHER_WINDOW_MINIMUM[1]
        )
        if (fullscreen or (not visible and not iconic_normal)
                or (window_state not in ("normal", "zoomed") and not iconic_normal)
                or width <= 1 or height <= 1):
            return
        if iconic_normal:
            current = replace(
                current, width=width, height=height, window_state="normal",
            )
        elif window_state == "normal":
            current = replace(
                current, width=width, height=height, x=x, y=y,
                window_state="normal",
            )
        else:
            current = replace(current, window_state="zoomed")
        workspace = getattr(self, "photos_workspace", None)
        if workspace is not None:
            try:
                pane_width = int(workspace.current_tree_width())
            except (tk.TclError, TypeError, ValueError):
                pane_width = 0
            if pane_width > 0:
                current = replace(current, photos_tree_width=pane_width)
            try:
                info_width = int(workspace.current_info_width())
            except (tk.TclError, TypeError, ValueError, AttributeError):
                info_width = 0
            if info_width > 0:
                current = replace(current, photos_info_width=info_width)
        current = _clamp_ui_layout_state(
            current, _layout_display_bounds(self.root), WATCHER_WINDOW_MINIMUM,
        )
        try:
            _write_ui_layout(self._ui_layout_path, current)
        except (OSError, ValueError, TypeError):
            logging.debug("Desktop layout preferences could not be saved.")
            return
        self._ui_layout_state = current

    def save_photos_tree_width(self, width: int) -> None:
        """Persist one settled Photos sash width in the shared UI layout file.

        Args:
            width (int): Sash position in the current Panedwindow's Tk pixels.

        Returns:
            None: The width is clamped and atomically merged with window state.
        """
        if type(width) is not int or width <= 0:
            return
        current = replace(self._ui_layout_state, photos_tree_width=width)
        current = _clamp_ui_layout_state(
            current, _layout_display_bounds(self.root), WATCHER_WINDOW_MINIMUM,
        )
        try:
            _write_ui_layout(self._ui_layout_path, current)
        except (OSError, ValueError, TypeError):
            logging.debug("Photos tree width preference could not be saved.")
            return
        self._ui_layout_state = current

    def save_photos_info_width(self, width: int) -> None:
        """Persist one settled Photos inspector width in the UI layout file.

        Args:
            width (int): Desired right inspector width in Tk pixels.

        Returns:
            None: The width is clamped and atomically merged with window state.
        """
        if type(width) is not int or width <= 0:
            return
        current = replace(self._ui_layout_state, photos_info_width=width)
        current = _clamp_ui_layout_state(
            current, _layout_display_bounds(self.root), WATCHER_WINDOW_MINIMUM,
        )
        try:
            _write_ui_layout(self._ui_layout_path, current)
        except (OSError, ValueError, TypeError):
            logging.debug("Photos inspector width preference could not be saved.")
            return
        self._ui_layout_state = current

    def load_photos_tree_state(
        self, session: LinkingSession,
    ) -> Dict[int, bool]:
        """Load validated gallery expansion preferences for the live account.

        Args:
            session (LinkingSession): Exact current Photos session.

        Returns:
            Dict[int, bool]: Positive gallery IDs mapped to open state; invalid
                or unavailable preferences produce an empty mapping.
        """
        path = self._photos_tree_state_path(session)
        if path is None:
            return {}
        try:
            payload = read_document(path)
            if (payload.get("version") not in (1, 2)
                    or not isinstance(payload.get("open"), dict)):
                return {}
            state: Dict[int, bool] = {}
            for raw_id, opened in payload["open"].items():
                if len(state) >= PHOTOS_TREE_STATE_MAX_GALLERIES:
                    break
                if (not isinstance(raw_id, str) or not raw_id.isdecimal()
                        or not isinstance(opened, bool)):
                    continue
                gallery_id = int(raw_id)
                if gallery_id > 0:
                    state[gallery_id] = opened
            return state
        except (OSError, ValueError, TypeError):
            return {}

    def save_photos_tree_state(
        self, session: LinkingSession, state: Dict[int, bool],
    ) -> None:
        """Atomically save bounded gallery expansion preferences.

        Args:
            session (LinkingSession): Exact current Photos session.
            state (Dict[int, bool]): Positive gallery IDs mapped to booleans.

        Returns:
            None: Writes only preferences after rechecking exact live identity.
        """
        path = self._photos_tree_state_path(session)
        if path is None:
            return
        bounded = {
            str(gallery_id): opened
            for gallery_id, opened in state.items()
            if type(gallery_id) is int and gallery_id > 0
            and isinstance(opened, bool)
        }
        if len(bounded) > PHOTOS_TREE_STATE_MAX_GALLERIES:
            return
        try:
            previous = read_document(path)
        except (OSError, ValueError, TypeError):
            previous = {}
        selected_id = previous.get("selected_gallery_id")
        if (type(selected_id) is not int or not 0 < selected_id <= LINKING_GALLERY_ID_MAX):
            selected_id = None
        try:
            atomic_write_json(path, {
                "version": 2,
                "open": bounded,
                "selected_gallery_id": selected_id,
            })
        except (OSError, ValueError):
            logging.debug("Photos tree preferences could not be saved.")

    def load_photos_selected_gallery(
        self, session: LinkingSession,
    ) -> int | None:
        """Load one positive saved gallery preference for the exact account.

        Args:
            session (LinkingSession): Exact current Photos session.

        Returns:
            int | None: Saved gallery ID from preference version 2, if valid.
        """
        path = self._photos_tree_state_path(session)
        if path is None:
            return None
        try:
            payload = read_document(path)
            selected_id = payload.get("selected_gallery_id")
            if (payload.get("version") != 2 or type(selected_id) is not int
                    or not 0 < selected_id <= LINKING_GALLERY_ID_MAX):
                return None
            return selected_id
        except (OSError, ValueError, TypeError):
            return None

    def save_photos_selected_gallery(
        self, session: LinkingSession, gallery_id: int,
    ) -> None:
        """Persist an explicit selection while preserving expansion settings.

        Args:
            session (LinkingSession): Exact current Photos session.
            gallery_id (int): Positive selected gallery ID from a fresh row.

        Returns:
            None: Updates only the version-2 preference for the verified account.
        """
        if (type(gallery_id) is not int
                or not 0 < gallery_id <= LINKING_GALLERY_ID_MAX):
            return
        path = self._photos_tree_state_path(session)
        if path is None:
            return
        open_state: dict[str, bool] = {}
        try:
            previous = read_document(path)
            rows = previous.get("open")
            if (previous.get("version") in (1, 2) and isinstance(rows, dict)):
                for raw_id, opened in rows.items():
                    if len(open_state) >= PHOTOS_TREE_STATE_MAX_GALLERIES:
                        break
                    if (not isinstance(raw_id, str) or not raw_id.isdecimal()
                            or type(opened) is not bool):
                        continue
                    parsed_id = int(raw_id)
                    if 0 < parsed_id <= LINKING_GALLERY_ID_MAX:
                        open_state[str(parsed_id)] = opened
        except (OSError, ValueError, TypeError):
            pass
        try:
            atomic_write_json(path, {
                "version": 2,
                "open": open_state,
                "selected_gallery_id": gallery_id,
            })
        except (OSError, ValueError):
            logging.debug("Photos tree selection could not be saved.")

    def open_photos(self) -> None:
        """Open the existing connection flow for the Photos workspace.

        Returns:
            None: Update handoff blocks new UI work; otherwise the existing
                address dialog starts or changes the live site connection.
        """
        if self.updates.blocked():
            return
        self.linking_manager.open_address_dialog()

    def open_watcher_rail_context(self, event: "tk.Event") -> str | None:
        """Open the same selected-gallery card beside the Watcher rail button.

        Args:
            event (tk.Event): Secondary pointer action on the Watcher trigger.

        Returns:
            str | None: Tk break for a handled Photos context, otherwise None.
        """
        if self.notebook.index("current") == self.tab_indices["photos"]:
            return self.photos_workspace.open_selected_watcher_context(event)
        return None

    def build_watch_tab(self, parent: "tk.Misc") -> None:
        """
        Build controls dedicated to watch-folder uploading.

        Args:
            parent (tk.Misc): Tkinter frame that receives the controls.

        Returns:
            None: Adds a wrapping gallery name with hierarchy help, offline
                abandonment, folder, UTC and flight controls, retaining
                SimBrief widgets for contextual visibility.
        """
        parent.columnconfigure(0, weight=1)
        hero = GlassCard(parent, padding=(14, 8))
        hero.grid(row=0, column=0, sticky="ew", padx=6, pady=(4, 4))
        hero.columnconfigure(0, weight=1)
        target = ttk.Frame(hero, style="Card.TFrame")
        target.grid(row=0, column=0, sticky="ew")
        target.columnconfigure(0, weight=1)
        GalleryIdentityLabel(
            target, self.gallery_label_var, self.gallery_title_var,
        ).grid(
            row=0, column=0, sticky="ew"
        )
        icon_button(
            target, "settings", self.open_connection_dialog,
            "Upload via API key",
        ).grid(row=0, column=1, padx=(8, 0))
        connection = ttk.Frame(hero, style="Card.TFrame")
        connection.grid(row=0, column=1, sticky="e", padx=(16, 0))
        ttk.Label(
            connection, textvariable=self.connection_status_var, style="Muted.TLabel",
        ).grid(row=0, column=0)
        self.watcher_capture_label = ttk.Label(
            connection, text="● REC", style="Watcher.Active.TLabel",
        )
        self.watcher_capture_label.grid(row=0, column=1, padx=(8, 0))
        self.watcher_capture_label.grid_remove()
        flight_actions = ttk.Frame(hero, style="Card.TFrame")
        flight_actions.grid(row=1, column=0, columnspan=2, sticky="w", pady=(6, 0))
        self.finish_flight_button = ttk.Button(
            flight_actions, text=self.flights.text("finish_flight"),
            command=self.flight_action,
        )
        self.finish_flight_button.grid(row=0, column=0, sticky="w")
        self.simbrief_button = ttk.Button(
            flight_actions, textvariable=self.flights.simbrief_button_var,
            command=self.flights.toggle_simbrief,
        )
        self.simbrief_button.grid(row=0, column=1, padx=(6, 0))
        self.simbrief_settings_button = icon_button(
            flight_actions, "settings",
            lambda: self.flights.load_simbrief(change_identity=True),
            self.flights.text("simbrief_identity"),
        )
        self.simbrief_settings_button.grid(row=0, column=2, padx=(4, 0))
        self.abandon_flight_button = ttk.Button(
            flight_actions, text=self.flights.text("abandon"),
            command=self.abandon_flight,
        )
        self.abandon_flight_button.grid(row=0, column=3, padx=(6, 0))
        self.simbrief_status_label = ttk.Label(
            flight_actions, textvariable=self.flights.simbrief_status_var,
            style="Muted.TLabel",
        )
        self.simbrief_status_label.grid(
            row=1, column=1, sticky="w", pady=(4, 0)
        )

        card = GlassCard(parent, padding=(14, 10))
        card.grid(row=1, column=0, sticky="ew", padx=6, pady=4)
        card.columnconfigure(0, weight=1)
        heading = ttk.Frame(card, style="Card.TFrame")
        heading.grid(row=0, column=0, sticky="ew")
        ttk.Label(heading, text="WATCH FOLDER", style="Eyebrow.TLabel").grid(
            row=0, column=0, sticky="w"
        )
        ttk.Button(
            heading, text="Choose folder…", command=self.browse_folder,
            style="Compact.TButton",
        ).grid(row=0, column=1, padx=(8, 0))
        icon_button(
            heading,
            "settings",
            self.open_watcher_settings,
            "Watcher settings",
        ).grid(row=0, column=2, padx=(4, 0))
        help_button(
            heading,
            "Watcher behavior",
            "Files already present at startup are ignored. New files wait until their size and modified time remain unchanged. Subfolders are excluded unless enabled in Watcher settings.",
        ).grid(row=0, column=3, padx=(4, 0))
        ttk.Label(
            card, textvariable=self.watch_folder_display_var,
            style="Muted.TLabel", wraplength=WINDOW_SUMMARY_WRAP,
        ).grid(row=1, column=0, sticky="w", pady=(1, 4))
        startup_status = ttk.Frame(card, style="Card.TFrame")
        startup_status.grid(row=2, column=0, sticky="ew", pady=(1, 3))
        startup_status.columnconfigure(0, weight=1)
        ttk.Label(
            startup_status, textvariable=self.status_var, style="Status.TLabel",
            wraplength=WINDOW_SUMMARY_WRAP,
        ).grid(row=0, column=0, sticky="w")
        self.watch_start_progress = ttk.Progressbar(
            startup_status, mode="indeterminate",
        )
        self.watch_start_progress.grid(
            row=1, column=0, sticky="ew", pady=(4, 4),
        )
        self.watch_start_progress.grid_remove()
        state = ttk.Frame(card, style="Card.TFrame")
        state.grid(row=3, column=0, sticky="ew", pady=(0, 5))
        ttk.Label(state, textvariable=self.watch_ignored_var, style="Muted.TLabel").pack(side="left")
        ttk.Label(state, textvariable=self.watch_last_scan_var, style="Muted.TLabel").pack(side="left", padx=(18, 0))
        actions = ttk.Frame(card, style="Card.TFrame")
        actions.grid(row=4, column=0, sticky="ew")
        self.watcher_action_button = ttk.Button(
            actions,
            text="▶  Start Watcher",
            command=self.toggle_watcher,
            style="Primary.TButton",
            takefocus=True,
        )
        self.watcher_action_button.pack(side="left")
        icon_button(
            actions,
            "⌕",
            self.dry_run_watch_folder,
            "Check existing files without uploading or changing saved state",
        ).pack(side="left", padx=(8, 0))
        utc = ttk.Frame(card, style="Card.TFrame")
        self.watcher_utc_row = utc
        utc.grid(row=5, column=0, sticky="w", pady=(6, 0))
        ttk.Checkbutton(
            utc,
            text="Sync UTC time",
            variable=self.sync_utc_time_var,
            command=self.toggle_utc_sync,
        ).pack(side="left")
        help_button(
            utc,
            "UTC sync",
            "Keeps Flight Simulator in sync with real-world UTC while the watcher runs. Off by default. Your choice is remembered.",
        ).pack(side="left", padx=(2, 0))
        ttk.Label(
            utc, textvariable=self.utc_sync_status_var, style="Muted.TLabel",
        ).pack(side="left", padx=(14, 0))
        self.watch_delete_warning = ttk.Label(
            card,
            text="",
            style="Warning.TLabel",
            wraplength=760,
        )
        self.watch_delete_warning.grid(row=8, column=0, sticky="ew", pady=(14, 0))
        self.refresh_watch_delete_warning()
        self.flights.controls(card)

    def open_watcher_settings(self) -> None:
        """Open focused watcher preferences with explicit save or cancel.

        Returns:
            None: Shows folder, scan interval, stability and upload policy
            controls without leaving the primary Watcher workflow.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.worker is not None and self.worker.is_alive():
            messagebox.showinfo(
                "Watcher settings",
                "Stop the watcher before changing its folder or upload policy. Changes apply when the next watcher starts.",
                parent=self.root,
            )
            return
        dialog = tk.Toplevel(self.root)
        dialog.title("Watcher settings")
        dialog.configure(background=self.root.cget("background"))
        dialog.transient(self.root)
        dialog.resizable(True, True)
        dialog.columnconfigure(0, weight=1)
        dialog.rowconfigure(0, weight=1)

        previous = {
            "folder": self.watched_folder_var.get(),
            "ignored": self.watch_ignored_var.get(),
            "last_scan": self.watch_last_scan_var.get(),
            "interval": self.interval_var.get(),
            "recursive": self.watch_recursive_var.get(),
            "stable": self.stable_var.get(),
            "sim_location": self.attach_sim_camera_metadata_var.get(),
            "delete": self.delete_uploaded_files_var.get(),
        }
        scroller = ScrollableFrame(dialog)
        scroller.grid(row=0, column=0, sticky="nsew")
        scroller.content.columnconfigure(0, weight=1)
        panel = GlassCard(scroller.content, padding=(20, 18))
        panel.grid(row=0, column=0, sticky="ew", padx=12, pady=12)
        panel.columnconfigure(0, weight=1)
        ttk.Label(panel, text="WATCHER PREFERENCES", style="Eyebrow.TLabel").grid(
            row=0, column=0, sticky="w"
        )
        ttk.Label(panel, text="Watch folder").grid(row=1, column=0, sticky="w", pady=(12, 5))
        folder_row = ttk.Frame(panel, style="Card.TFrame")
        folder_row.grid(row=2, column=0, sticky="ew")
        folder_row.columnconfigure(0, weight=1)
        folder_entry = ttk.Entry(
            folder_row,
            textvariable=self.watched_folder_var,
            style="Large.TEntry",
        )
        folder_entry.grid(row=0, column=0, sticky="ew")
        icon_button(
            folder_row, "add", lambda: self.browse_folder(parent=dialog),
            "Choose watched folder",
        ).grid(row=0, column=1, padx=(7, 0))
        icon_button(
            folder_row, "↗", self.open_watched_folder, "Open watched folder",
        ).grid(row=0, column=2, padx=(4, 0))
        ttk.Checkbutton(
            panel,
            text="Include subfolders",
            variable=self.watch_recursive_var,
        ).grid(row=3, column=0, sticky="w", pady=(10, 0))
        interval_row = ttk.Frame(panel, style="Card.TFrame")
        interval_row.grid(row=4, column=0, sticky="w", pady=(8, 0))
        ttk.Label(interval_row, text="Scan folder every").pack(side="left")
        ttk.Entry(interval_row, textvariable=self.interval_var, width=8).pack(
            side="left", padx=7
        )
        ttk.Label(interval_row, text="seconds").pack(side="left")
        stable_row = ttk.Frame(panel, style="Card.TFrame")
        stable_row.grid(row=5, column=0, sticky="w", pady=(5, 0))
        ttk.Label(stable_row, text="File stable for").pack(side="left")
        ttk.Entry(stable_row, textvariable=self.stable_var, width=8).pack(side="left", padx=7)
        ttk.Label(stable_row, text="seconds").pack(side="left")
        ttk.Checkbutton(
            panel,
            text="Attach simulator location",
            variable=self.attach_sim_camera_metadata_var,
        ).grid(row=6, column=0, sticky="w", pady=(8, 0))
        ttk.Checkbutton(
            panel,
            text="Delete uploaded photos from this PC",
            variable=self.delete_uploaded_files_var,
            command=self.refresh_watch_delete_warning,
        ).grid(row=7, column=0, sticky="w", pady=(6, 0))
        ttk.Label(
            panel,
            text="After confirmed upload. Existing and changed files are kept.",
            style="Muted.TLabel",
            wraplength=WINDOW_SUMMARY_WRAP,
        ).grid(row=8, column=0, sticky="w", padx=(24, 0), pady=(1, 0))
        footer = GlassCard(dialog, padding=(14, 10))
        footer.grid(row=1, column=0, sticky="ew", padx=12, pady=(0, 12))

        def cancel_changes() -> None:
            """Restore saved form values and close without saving.

            Returns:
                None: Restores the opening snapshot of watcher preferences.
            """
            self.watched_folder_var.set(previous["folder"])
            self.watch_ignored_var.set(previous["ignored"])
            self.watch_last_scan_var.set(previous["last_scan"])
            self.interval_var.set(previous["interval"])
            self.watch_recursive_var.set(previous["recursive"])
            self.stable_var.set(previous["stable"])
            self.attach_sim_camera_metadata_var.set(previous["sim_location"])
            self.delete_uploaded_files_var.set(previous["delete"])
            self.refresh_watch_delete_warning()
            dialog.destroy()

        def save_changes() -> None:
            """Persist watcher preferences and close after a successful save.

            Returns:
                None: Closes the sheet only when the configuration is saved.
            """
            try:
                self.config = self._save_owned_config(
                    self.current_watcher_config(), first_setup=True,
                )
            except (OSError, ValueError, ModeCoordinatorError) as exc:
                messagebox.showerror("Watcher settings", str(exc), parent=dialog)
            else:
                dialog.destroy()

        ttk.Button(footer, text="Cancel", command=cancel_changes).pack(side="right")
        ttk.Button(
            footer,
            text="Save watcher settings",
            command=save_changes,
            style="Primary.TButton",
        ).pack(side="right", padx=(0, 8))
        self.refresh_watch_delete_warning()
        dialog.protocol("WM_DELETE_WINDOW", cancel_changes)
        dialog.bind("<Escape>", lambda _event: cancel_changes())
        dialog.update_idletasks()
        screen_width = dialog.winfo_screenwidth()
        screen_height = dialog.winfo_screenheight()
        margin_width, margin_height = WATCHER_SETTINGS_SCREEN_MARGIN
        fallback_width, fallback_height = WATCHER_SETTINGS_FALLBACK_SIZE
        minimum_width, minimum_height = WATCHER_SETTINGS_MINIMUM
        content_height = (
            scroller.content.winfo_reqheight()
            + footer.winfo_reqheight()
            + 20
        )
        width = min(
            max(dialog.winfo_reqwidth(), minimum_width),
            max(fallback_width, screen_width - margin_width),
        )
        height = min(
            max(content_height, minimum_height),
            max(fallback_height, screen_height - margin_height),
        )
        dialog.geometry(f"{width}x{height}")
        dialog.update_idletasks()
        parent_x = self.root.winfo_rootx()
        parent_y = self.root.winfo_rooty()
        parent_width = self.root.winfo_width()
        parent_height = self.root.winfo_height()
        x = min(
            max(0, parent_x + (parent_width - width) // 2),
            max(0, screen_width - width - margin_width),
        )
        y = min(
            max(0, parent_y + (parent_height - height) // 2),
            max(0, screen_height - height - margin_height),
        )
        dialog.geometry(f"{width}x{height}+{x}+{y}")
        dialog.grab_set()
        folder_entry.focus_set()


    def build_manual_tab(self, parent: "tk.Misc") -> None:
        """
        Build controls dedicated to manual bulk uploading.

        Keep the chosen upload target and its optional API-key editor together.
        The site link and its gallery picker live in the main header.

        Args:
            parent (tk.Misc): Tk container receiving the import controls.

        Returns:
            None: Builds manual import with a wrapping gallery name, hierarchy
                help and offline flight-abandonment controls.
        """
        parent.columnconfigure(0, weight=1)
        card = GlassCard(parent, padding=(14, 10))
        card.grid(row=0, column=0, sticky="ew", padx=6, pady=4)
        card.columnconfigure(0, weight=1)
        target = ttk.Frame(card, style="Card.TFrame")
        target.grid(row=0, column=0, sticky="ew")
        target.columnconfigure(0, weight=1)
        identity = ttk.Frame(target, style="Card.TFrame")
        identity.grid(row=0, column=0, sticky="ew")
        identity.columnconfigure(0, weight=1)
        GalleryIdentityLabel(
            identity, self.gallery_label_var, self.gallery_title_var,
        ).grid(
            row=0, column=0, sticky="ew"
        )
        icon_button(
            identity, "settings", self.open_connection_dialog,
            "Upload via API key",
        ).grid(row=0, column=1, padx=(8, 0))
        ttk.Label(target, textvariable=self.connection_status_var, style="Muted.TLabel").grid(
            row=2, column=0, sticky="w", pady=(2, 0)
        )
        self.import_revoke_button = ttk.Button(
            target,
            text=self.flights.text("finish_flight"),
            command=self.flight_action,
        )
        self.import_revoke_button.grid(row=1, column=0, sticky="w", pady=(6, 0))
        self.import_abandon_button = ttk.Button(
            target, text=self.flights.text("abandon"),
            command=self.abandon_flight,
        )
        self.import_abandon_button.grid(row=1, column=1, padx=(6, 0), pady=(6, 0))

        ttk.Label(card, text="CHOOSE SOURCE", style="Eyebrow.TLabel").grid(
            row=2, column=0, sticky="w", pady=(17, 5)
        )
        toolbar = ttk.Frame(card, style="Card.TFrame")
        toolbar.grid(row=3, column=0, sticky="ew")
        icon_button(toolbar, "upload", self.select_manual_files, "Choose image files").pack(side="left")
        ttk.Label(toolbar, text="Files", style="Muted.TLabel").pack(side="left", padx=(2, 10))
        icon_button(toolbar, "add", self.select_manual_folder, "Choose a folder").pack(side="left")
        ttk.Label(toolbar, text="Folder", style="Muted.TLabel").pack(side="left", padx=(2, 10))
        icon_button(toolbar, "info", self.select_manual_zip, "Choose a ZIP archive").pack(side="left")
        ttk.Label(toolbar, text="ZIP", style="Muted.TLabel").pack(side="left", padx=(2, 10))
        icon_button(toolbar, "close", self.clear_manual_files, "Clear the selected source").pack(side="right")
        ttk.Label(card, textvariable=self.manual_selection_var, style="Body.TLabel", wraplength=800).grid(
            row=4, column=0, sticky="w", pady=(9, 0)
        )

        ttk.Label(card, text="REVIEW", style="Eyebrow.TLabel").grid(
            row=5, column=0, sticky="w", pady=(16, 5)
        )
        ttk.Label(
            card,
            textvariable=self.manual_preflight_var,
            style="Body.TLabel",
            wraplength=820,
            justify="left",
        ).grid(row=6, column=0, sticky="w")
        self.preflight_progress = ttk.Progressbar(card, mode="indeterminate")
        self.preflight_progress.grid(row=7, column=0, sticky="ew", pady=(7, 0))
        self.preflight_progress.grid_remove()
        self.manual_progress = ttk.Progressbar(
            card,
            variable=self.manual_progress_var,
            maximum=100.0,
            mode="determinate",
        )
        self.manual_progress.grid(row=8, column=0, sticky="ew", pady=(10, 4))
        ttk.Label(card, textvariable=self.manual_progress_text_var, style="Muted.TLabel").grid(
            row=9, column=0, sticky="w"
        )
        actions = ttk.Frame(card, style="Card.TFrame")
        actions.grid(row=10, column=0, sticky="ew", pady=(11, 0))
        self.start_import_button = ttk.Button(
            actions,
            text="▶  Start import",
            command=self.start_manual_upload,
            state="disabled",
            style="Primary.TButton",
            takefocus=True,
        )
        self.start_import_button.pack(side="left")
        self.pause_import_button = icon_button(
            actions,
            "pause",
            self.pause_manual_upload,
            "Pause import after active requests finish",
        )
        self.pause_import_button.state(["disabled"])
        self.pause_import_button.pack(side="left", padx=(7, 0))
        self.resume_import_button = icon_button(
            actions,
            "play",
            self.resume_manual_upload,
            "Resume the paused import",
        )
        self.resume_import_button.state(["disabled"])
        self.resume_import_button.pack(side="left", padx=(5, 0))
        self.cancel_import_button = icon_button(
            actions,
            "close",
            self.stop_manual_upload,
            "Cancel the active import",
        )
        self.cancel_import_button.state(["disabled"])
        self.cancel_import_button.pack(side="left", padx=(5, 0))
        self.retry_failed_button = ttk.Button(actions, text="Retry failed", command=self.retry_failed_import, state="disabled")
        self.retry_failed_button.pack(side="left", padx=(7, 0))
        ttk.Label(actions, textvariable=self.manual_status_var, style="Muted.TLabel").pack(side="right")

        advanced_toggle = ttk.Checkbutton(
            card,
            text="Import options and item details",
            variable=self.import_details_visible_var,
            command=self.toggle_import_details,
        )
        advanced_toggle.grid(row=11, column=0, sticky="w", pady=(12, 0))
        self.import_details_frame = ttk.Frame(card, style="Card.TFrame")
        self.import_details_frame.columnconfigure(0, weight=1)
        ttk.Checkbutton(
            self.import_details_frame,
            text="Include subfolders",
            variable=self.manual_folder_recursive_var,
            command=self.rebuild_manual_preflight,
        ).grid(row=0, column=0, sticky="w", pady=3)
        ttk.Label(
            self.import_details_frame,
            text="Original ZIP is kept for manual verification.",
            style="Muted.TLabel",
        ).grid(row=1, column=0, sticky="w", pady=3)
        ttk.Radiobutton(
            self.import_details_frame,
            text="Server creates thumbnails",
            variable=self.manual_local_thumbnails_var,
            value=False,
        ).grid(row=2, column=0, sticky="w", pady=3)
        self.thumbnail_check = ttk.Radiobutton(
            self.import_details_frame,
            text="This PC creates thumbnails when supported",
            variable=self.manual_local_thumbnails_var,
            value=True,
        )
        self.thumbnail_check.grid(row=3, column=0, sticky="w", pady=3)
        self.import_item_tree = ttk.Treeview(
            self.import_details_frame,
            columns=("file", "size", "decode", "state", "reason"),
            show="headings",
            height=6,
        )
        for column, title, width in [
            ("file", "File", 190),
            ("size", "Size", 70),
            ("decode", "Media", 85),
            ("state", "Review", 95),
            ("reason", "Detail", 290),
        ]:
            self.import_item_tree.heading(column, text=title)
            self.import_item_tree.column(column, width=width, stretch=column in {"file", "reason"})
        self.import_item_tree.grid(row=4, column=0, sticky="ew", pady=(9, 0))
        self.import_details_frame.grid(row=12, column=0, sticky="ew", pady=(6, 0))
        self.import_details_frame.grid_remove()
        self.refresh_thumbnail_controls()
        self.refresh_revoke_button_state()

    def toggle_import_details(self) -> None:
        """Show or hide detailed import options and per-item preflight rows.

        Returns:
            None: Keeps advanced controls collapsed until explicitly requested.
        """
        if self.import_details_visible_var.get():
            self.import_details_frame.grid()
        else:
            self.import_details_frame.grid_remove()

    def build_activity_tab(self, parent: "tk.Misc") -> None:
        """Build the full event, job-history, retry, and diagnostics workspace.

        Args:
            parent (tk.Misc): Scrollable page receiving the activity controls.

        Returns:
            None: Creates persistent job and operational event views.
        """
        parent.columnconfigure(0, weight=1)
        current = GlassCard(parent, padding=(14, 10))
        current.grid(row=1, column=0, sticky="ew", padx=6, pady=4)
        current.columnconfigure(0, weight=1)
        ttk.Label(
            current, text="Current import", style="Section.TLabel",
        ).grid(row=0, column=0, sticky="w", pady=(0, 6))
        ttk.Label(current, textvariable=self.activity_job_summary_var, wraplength=800).grid(row=1, column=0, sticky="w", pady=(0, 8))
        activity_actions = ttk.Frame(current, style="Card.TFrame")
        activity_actions.grid(row=2, column=0, sticky="ew", pady=(0, 8))
        ttk.Button(activity_actions, text="Open Import", command=lambda: self.select_tab("import")).pack(side="left")
        ttk.Button(activity_actions, text="Retry failed", command=self.retry_failed_import).pack(side="left", padx=8)
        self.activity_item_tree = ttk.Treeview(current, columns=("file", "state", "attempts", "detail"), show="headings", height=7)
        for column, title, width in [
            ("file", "File", 175),
            ("state", "State", 105),
            ("attempts", "Tries", 65),
            ("detail", "Result / error", 330),
        ]:
            self.activity_item_tree.heading(column, text=title)
            self.activity_item_tree.column(column, width=width, stretch=column in {"file", "detail"})
        self.activity_item_tree.grid(row=3, column=0, sticky="ew")

        history = GlassCard(parent, padding=(14, 10))
        history.grid(row=2, column=0, sticky="ew", padx=6, pady=4)
        history.columnconfigure(0, weight=1)
        ttk.Label(
            history, text="Job history", style="Section.TLabel",
        ).grid(row=0, column=0, sticky="w", pady=(0, 8))
        self.job_history_tree = ttk.Treeview(history, columns=("created", "source", "target", "status"), show="headings", height=10)
        for column, title, width in [
            ("created", "Created", 125),
            ("source", "Source", 190),
            ("target", "Target", 140),
            ("status", "Result", 270),
        ]:
            self.job_history_tree.heading(column, text=title)
            self.job_history_tree.column(column, width=width, stretch=column in {"source", "status"})
        self.job_history_tree.grid(row=1, column=0, sticky="ew")
        self.job_history_tree.bind("<<TreeviewSelect>>", self.select_history_job)
        self.refresh_job_history()

        events = GlassCard(parent, padding=(14, 10))
        events.grid(row=3, column=0, sticky="ew", padx=6, pady=4)
        events.columnconfigure(0, weight=1)
        ttk.Label(
            events, text="Recent events", style="Section.TLabel",
        ).grid(row=0, column=0, sticky="w", pady=(0, 8))
        controls = ttk.Frame(events, style="Card.TFrame")
        controls.grid(row=1, column=0, sticky="ew", pady=(0, 8))
        ttk.Label(controls, text="Show").pack(side="left")
        activity_filter = ttk.Combobox(
            controls,
            textvariable=self.activity_filter_var,
            values=("all", "active", "succeeded", "skipped", "failed", "warnings"),
            width=12,
            state="readonly",
        )
        activity_filter.pack(side="left", padx=(6, 10))
        activity_filter.bind(
            "<<ComboboxSelected>>",
            lambda _event: self.refresh_activity_drawer(),
        )
        ttk.Button(controls, text="Retry failed", command=self.retry_failed_import).pack(side="left")
        ttk.Button(controls, text="Copy diagnostics", command=self.copy_diagnostics).pack(side="right")
        event_table = ttk.Frame(events, style="Card.TFrame")
        event_table.grid(row=2, column=0, sticky="ew")
        event_table.columnconfigure(0, weight=1)
        event_table.rowconfigure(0, weight=1)
        self.activity_drawer = ttk.Treeview(
            event_table,
            columns=("time", "level", "filename", "operation", "message"),
            show="headings",
            height=9,
            yscrollcommand=lambda first, last: event_scroll_y.set(first, last),
            xscrollcommand=lambda first, last: event_scroll_x.set(first, last),
        )
        for column, title, width in [
            ("time", "Time", 75),
            ("level", "Status", 75),
            ("filename", "File", 125),
            ("operation", "Type", 85),
            ("message", "Event", 390),
        ]:
            self.activity_drawer.heading(column, text=title)
            self.activity_drawer.column(
                column,
                width=width,
                stretch=column in {"filename", "message"},
            )
        self.activity_drawer.grid(row=0, column=0, sticky="nsew")
        event_scroll_y = ttk.Scrollbar(event_table, orient="vertical", command=self.activity_drawer.yview)
        event_scroll_y.grid(row=0, column=1, sticky="ns")
        event_scroll_x = ttk.Scrollbar(event_table, orient="horizontal", command=self.activity_drawer.xview)
        event_scroll_x.grid(row=1, column=0, sticky="ew")
        self.refresh_activity_drawer()

        diagnostics = GlassCard(parent, padding=(14, 10))
        diagnostics.grid(row=4, column=0, sticky="ew", padx=6, pady=(4, 8))
        diagnostics.columnconfigure(0, weight=1)
        ttk.Label(
            diagnostics, text="Live diagnostics log", style="Section.TLabel",
        ).grid(row=0, column=0, sticky="w", pady=(0, 8))
        diagnostic_actions = ttk.Frame(diagnostics, style="Card.TFrame")
        diagnostic_actions.grid(row=1, column=0, sticky="ew", pady=(0, 8))
        ttk.Button(diagnostic_actions, text="Open app folder", command=self.open_config_folder).pack(side="left")
        ttk.Button(diagnostic_actions, text="Open log file", command=self.open_log_file).pack(side="left", padx=8)
        ttk.Button(diagnostic_actions, text="Copy redacted diagnostics", command=self.copy_diagnostics).pack(side="left")
        self.log_text = tk.Text(
            diagnostics, height=9, wrap="word", relief="flat", padx=8, pady=6,
            font="TkFixedFont",
            background="#182330", foreground="#F2F6FA",
            insertbackground="#F2F6FA", selectbackground="#365768",
        )
        self.log_text.grid(row=2, column=0, sticky="ew")
        self.log_text.configure(state="disabled")
        self.configure_log_tags()

    def build_settings_tab(self, parent: "tk.Misc") -> None:
        """Build compact connection and operator preferences sections.

        Args:
            parent (tk.Misc): Scrollable Settings page receiving the controls.

        Returns:
            None: Creates connection, runtime, and advanced sections.
        """
        parent.columnconfigure(0, weight=1)
        connection = GlassCard(parent, padding=(14, 10))
        connection.grid(row=0, column=0, sticky="ew", padx=6, pady=4)
        connection.columnconfigure(1, weight=1)
        ttk.Label(connection, text="GALLERY CONNECTION", style="Eyebrow.TLabel").grid(
            row=0, column=0, sticky="w", pady=(0, 8)
        )
        ttk.Label(connection, text="Gallery URL").grid(row=1, column=0, sticky="w", padx=(0, 10), pady=5)
        self.gallery_url_entry = ttk.Entry(connection, textvariable=self.gallery_url_var)
        self.gallery_url_entry.grid(row=1, column=1, columnspan=3, sticky="ew", pady=5)
        ttk.Label(connection, text="API key").grid(row=2, column=0, sticky="w", padx=(0, 10), pady=5)
        self.api_key_entry = ttk.Entry(connection, textvariable=self.api_key_var, show="*")
        self.api_key_entry.grid(row=2, column=1, sticky="ew", pady=5)
        icon_button(connection, "info", self.toggle_api_key_visibility, "Reveal or mask API key").grid(row=2, column=2, padx=(6, 2), pady=5)
        icon_button(connection, "add", self.paste_api_key_from_clipboard, "Paste API key").grid(row=2, column=3, padx=(2, 0), pady=5)
        ttk.Label(connection, textvariable=self.api_key_status_var, style="Muted.TLabel").grid(
            row=3, column=1, sticky="w", pady=(1, 0)
        )
        ttk.Label(connection, textvariable=self.connection_detail_var, style="Muted.TLabel", wraplength=760).grid(
            row=4, column=1, columnspan=3, sticky="w", pady=(5, 0)
        )
        actions = ttk.Frame(connection, style="Card.TFrame")
        actions.grid(row=5, column=1, columnspan=3, sticky="ew", pady=(10, 0))
        ttk.Button(actions, text="✓  Save & Test", command=self.save_and_test_connection, style="Primary.TButton").pack(side="left")
        icon_button(actions, "add", self.open_connection_dialog, "Open protected credential editor").pack(side="left", padx=(7, 0))
        self.revoke_button = ttk.Button(actions, text="Revoke key…", command=self.revoke_api_key)
        self.revoke_button.pack(side="left", padx=(7, 0))
        ttk.Button(
            actions, text="Manage link…", command=self.manage_gallery_link,
        ).pack(side="left", padx=(7, 0))
        ttk.Label(connection, textvariable=self.revoke_blocker_var, style="Muted.TLabel", wraplength=760).grid(
            row=6, column=1, columnspan=3, sticky="w", pady=(6, 0)
        )

        runtime = GlassCard(parent, padding=(14, 10))
        runtime.grid(row=1, column=0, sticky="ew", padx=6, pady=4)
        runtime.columnconfigure(1, weight=1)
        ttk.Label(runtime, text="APP & INTEGRATIONS", style="Eyebrow.TLabel").grid(
            row=0, column=0, columnspan=3, sticky="w", pady=(0, 7)
        )
        ttk.Label(runtime, textvariable=self.thumbnail_runtime_var, style="Muted.TLabel", wraplength=650).grid(
            row=1, column=0, columnspan=2, sticky="w", pady=5
        )
        ttk.Button(runtime, text="Repair dependencies", command=self.repair_dependencies).grid(row=1, column=2, padx=(8, 0), pady=5)
        ttk.Label(runtime, text="SimConnect.dll override").grid(row=2, column=0, sticky="w", pady=5)
        ttk.Entry(runtime, textvariable=self.simconnect_dll_path_var).grid(row=2, column=1, sticky="ew", padx=8, pady=5)
        ttk.Button(runtime, text="Choose", command=self.browse_simconnect_dll).grid(row=2, column=2, pady=5)
        ttk.Checkbutton(runtime, text="Keep working in the system tray when the window closes", variable=self.close_to_tray_var).grid(
            row=3, column=1, columnspan=2, sticky="w", pady=(5, 0)
        )
        startup_switch = ttk.Checkbutton(
            runtime,
            text="Start with Windows (minimized to tray)",
            variable=self.start_with_windows_var,
            command=self.save_startup_preference,
        )
        startup_switch.grid(
            row=4, column=1, columnspan=2, sticky="w", pady=(5, 0),
        )
        if not startup_supported():
            startup_switch.state(["disabled"])
        ttk.Label(
            runtime,
            text="Installed Windows app only. This switch saves immediately.",
            style="Muted.TLabel",
        ).grid(row=5, column=1, columnspan=2, sticky="w", pady=(3, 0))
        ttk.Button(
            runtime,
            text="✓  Save settings",
            command=self.save_config,
            style="Primary.TButton",
        ).grid(row=6, column=1, sticky="w", pady=(10, 0))

        advanced_toggle = ttk.Checkbutton(
            runtime,
            text="Advanced import and archive settings",
            variable=self.settings_advanced_visible_var,
            command=self.toggle_settings_advanced,
        )
        advanced_toggle.grid(
            row=7, column=0, columnspan=3, sticky="w", pady=(8, 0),
        )
        advanced = GlassCard(parent, padding=(14, 10))
        self.settings_advanced_frame = advanced
        advanced.columnconfigure(1, weight=1)
        rows = [
            ("Remote inventory reconnect seconds", self.inventory_refresh_var),
            ("ZIP max entries", self.archive_max_entries_var),
            ("ZIP max total uncompressed GiB", self.archive_max_total_gib_var),
            ("ZIP max single file GiB", self.archive_max_file_gib_var),
            ("ZIP max compression ratio", self.archive_max_ratio_var),
            ("Staging cleanup age hours", self.staging_cleanup_hours_var),
        ]
        for row, (label, variable) in enumerate(rows):
            ttk.Label(advanced, text=label).grid(row=row, column=0, sticky="w", padx=(0, 14), pady=4)
            ttk.Entry(advanced, textvariable=variable, width=18).grid(row=row, column=1, sticky="w", pady=4)
        workers_row = len(rows)
        ttk.Label(advanced, text="Local thumbnail processes").grid(row=workers_row, column=0, sticky="w", padx=(0, 14), pady=4)
        ttk.Combobox(advanced, textvariable=self.manual_thumbnail_workers_var, values=worker_choice_values(MAX_THUMBNAIL_WORKERS), width=10, state="readonly").grid(row=workers_row, column=1, sticky="w", pady=4)
        ttk.Label(advanced, text="Upload threads").grid(row=workers_row + 1, column=0, sticky="w", padx=(0, 14), pady=4)
        ttk.Combobox(advanced, textvariable=self.manual_upload_workers_var, values=worker_choice_values(MAX_UPLOAD_WORKERS), width=10, state="readonly").grid(row=workers_row + 1, column=1, sticky="w", pady=4)
        ttk.Button(
            advanced,
            text="✓  Save settings",
            command=self.save_config,
            style="Primary.TButton",
        ).grid(row=workers_row + 2, column=0, columnspan=2, sticky="w", pady=(10, 0))
        self.toggle_settings_advanced()


    def build_ai_tab(self, parent: "tk.Misc") -> None:
        """
        Build controls for the optional AI metadata worker.

        The tab intentionally keeps the normal operator view small. The common
        workflow is enable, choose the local backend, install dependencies if
        needed, and start the worker. Low-level tuning fields stay available in
        a collapsible advanced section, but they do not dominate the UI.

        Args:
            parent (tk.Misc): Tk container receiving the AI worker controls.

        Returns:
            None: Build controls for the optional AI metadata worker.
        """
        parent.columnconfigure(0, weight=1)
        quick = GlassCard(parent, padding=(14, 10))
        quick.grid(row=0, column=0, sticky="ew", padx=6, pady=4)
        quick.columnconfigure(1, weight=1)
        ttk.Label(quick, text="AI METADATA", style="Eyebrow.TLabel").grid(
            row=0, column=0, columnspan=3, sticky="w"
        )
        ttk.Label(
            quick,
            text="Optional, server-assigned image analysis.",
            style="Section.TLabel",
        ).grid(row=1, column=0, columnspan=3, sticky="w", pady=(3, 2))
        ttk.Label(
            quick,
            text="The gallery assigns one job at a time; this PC returns internal search metadata.",
            style="Muted.TLabel",
            wraplength=780,
        ).grid(row=2, column=0, columnspan=3, sticky="w")
        ttk.Checkbutton(
            quick,
            text="Enable AI metadata worker on this PC",
            variable=self.ai_worker_enabled_var,
        ).grid(row=3, column=0, columnspan=3, sticky="w", pady=(11, 6))
        ttk.Label(quick, text="Backend").grid(row=4, column=0, sticky="w", pady=5)
        ttk.OptionMenu(
            quick,
            self.ai_vision_backend_var,
            normalize_ai_vision_backend(self.ai_vision_backend_var.get()),
            *AI_VISION_BACKEND_CHOICES,
        ).grid(row=4, column=1, sticky="w", padx=8, pady=5)
        ttk.Label(quick, text="Model version").grid(row=5, column=0, sticky="w", pady=5)
        ttk.Entry(quick, textvariable=self.ai_model_version_var, width=24).grid(
            row=5, column=1, sticky="w", padx=8, pady=5
        )
        ttk.Label(quick, textvariable=self.ai_status_var, style="Status.TLabel").grid(
            row=6, column=0, columnspan=2, sticky="w", pady=(8, 0)
        )
        runtime_row = ttk.Frame(quick, style="Card.TFrame")
        runtime_row.grid(row=7, column=0, columnspan=3, sticky="ew", pady=(10, 0))
        runtime_row.columnconfigure(0, weight=1)
        ttk.Label(
            runtime_row,
            textvariable=self.thumbnail_runtime_var,
            style="Muted.TLabel",
            wraplength=550,
        ).grid(row=0, column=0, sticky="w")
        self.semantic_ai_install_button = ttk.Button(
            runtime_row,
            text="Install local AI module",
            command=self.repair_semantic_ai_dependencies,
        )
        self.semantic_ai_install_button.grid(row=0, column=1, sticky="e", padx=(8, 0))
        ttk.Button(
            runtime_row,
            text="Repair dependencies",
            command=self.repair_dependencies,
        ).grid(row=0, column=2, sticky="e", padx=(6, 0))
        actions = ttk.Frame(quick, style="Card.TFrame")
        actions.grid(row=8, column=0, columnspan=3, sticky="ew", pady=(12, 0))
        ttk.Button(
            actions,
            text="▶  Start AI worker",
            command=self.start_ai_worker,
            style="Primary.TButton",
        ).pack(side="left")
        icon_button(actions, "stop", self.stop_ai_worker, "Stop AI metadata worker").pack(
            side="left", padx=(7, 0)
        )

        advanced_toggle = ttk.Checkbutton(
            quick,
            text="Advanced AI settings",
            variable=self.ai_advanced_visible_var,
            command=self.toggle_ai_advanced_settings,
        )
        advanced_toggle.grid(
            row=9, column=0, columnspan=3, sticky="w", pady=(8, 0),
        )

        advanced = GlassCard(parent, padding=(14, 10))
        self.ai_advanced_frame = advanced
        advanced.columnconfigure(1, weight=1)

        ttk.Label(advanced, text="Poll seconds when idle").grid(row=0, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_worker_poll_var, width=12).grid(row=0, column=1, sticky="w", padx=8, pady=5)
        ttk.Label(advanced, text="Default 60. Larger values keep the server quieter.", foreground="#AABCCB").grid(row=0, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Lease seconds per job").grid(row=1, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_worker_lease_var, width=12).grid(row=1, column=1, sticky="w", padx=8, pady=5)
        ttk.Label(advanced, text="Default 600. Heartbeats extend the lease while analysis runs.", foreground="#AABCCB").grid(row=1, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Model or analyzer name").grid(row=2, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_model_name_var).grid(row=2, column=1, sticky="ew", padx=8, pady=5)

        ttk.Label(advanced, text="Transformers caption model").grid(row=3, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_transformers_caption_model_var).grid(row=3, column=1, sticky="ew", padx=8, pady=5)
        ttk.Label(advanced, text="First use may download model files locally.", foreground="#AABCCB", wraplength=360).grid(row=3, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Transformers object detector").grid(row=4, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_transformers_detector_model_var).grid(row=4, column=1, sticky="ew", padx=8, pady=5)
        ttk.Label(advanced, text="Zero-shot object labels such as person, bridge, guitar, house.", foreground="#AABCCB").grid(row=4, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Detector threshold").grid(row=5, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_transformers_detection_threshold_var, width=12).grid(row=5, column=1, sticky="w", padx=8, pady=5)
        ttk.Label(advanced, text="Default 0.18. Lower sees more objects but may add noise.", foreground="#AABCCB").grid(row=5, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Object labels").grid(row=6, column=0, sticky="nw", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_transformers_object_labels_var).grid(row=6, column=1, columnspan=2, sticky="ew", padx=8, pady=5)

        ttk.Label(advanced, text="Local Ollama URL").grid(row=7, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_ollama_url_var).grid(row=7, column=1, sticky="ew", padx=8, pady=5)
        ttk.Label(advanced, text="Optional fallback when you intentionally run Ollama.", foreground="#AABCCB").grid(row=7, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Ollama vision model").grid(row=8, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_ollama_model_var, width=32).grid(row=8, column=1, sticky="w", padx=8, pady=5)
        ttk.Label(advanced, text="Examples: llava:latest or llama3.2-vision:latest.", foreground="#AABCCB").grid(row=8, column=2, sticky="w", padx=8, pady=5)

        ttk.Label(advanced, text="Semantic prompt").grid(row=9, column=0, sticky="nw", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_semantic_prompt_var).grid(row=9, column=1, columnspan=2, sticky="ew", padx=8, pady=5)

        ttk.Label(advanced, text="External analyzer command").grid(row=10, column=0, sticky="w", padx=8, pady=5)
        ttk.Entry(advanced, textvariable=self.ai_external_command_var).grid(row=10, column=1, columnspan=2, sticky="ew", padx=8, pady=5)
        ttk.Label(
            advanced,
            text="Optional. Use {image_path}, {filename}, and {job_id}. External analyzer output is treated as untrusted JSON and validated before use. Leave blank unless you use your own local analyzer.",
            foreground="#AABCCB",
            wraplength=820,
        ).grid(row=11, column=1, columnspan=2, sticky="w", padx=8, pady=(0, 6))

        self.toggle_ai_advanced_settings()

    def toggle_ai_advanced_settings(self) -> None:
        """
        Show or hide low-level AI worker tuning controls.

        Returns:
            None: Keeps advanced AI fields collapsed until requested.
        """
        if not self.ai_advanced_frame:
            return
        if self.ai_advanced_visible_var.get():
            self.ai_advanced_frame.grid(row=2, column=0, sticky="ew", padx=6, pady=4)
        else:
            self.ai_advanced_frame.grid_remove()

    def refresh_thumbnail_controls(self) -> None:
        """
        Refresh local thumbnail availability in the manual upload tab.
        """
        self.thumbnail_runtime_var.set(thumbnail_runtime_status())
        if local_thumbnail_supported():
            self.thumbnail_check.state(["!disabled"])
        else:
            self.thumbnail_check.state(["disabled"])
            self.manual_local_thumbnails_var.set(False)

    def repair_dependencies(self) -> None:
        """
        Install winapp dependencies into the current Python interpreter.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if getattr(sys, "frozen", False):
            ok, output = install_dependencies_for_current_runtime()
            (messagebox.showinfo if ok else messagebox.showerror)("Bundled dependencies", output)
            return
        self.write_log("Installing or repairing Python dependencies for the current runtime...")
        ok, output = install_dependencies_for_current_runtime()
        for line in output.splitlines()[-12:]:
            self.write_log(line)
        if ok:
            if not self.tray_icon:
                self.start_tray_icon()
            messagebox.showinfo("Dependency repair", "Required dependencies are available for this Python runtime.")
        else:
            messagebox.showerror("Dependency repair failed", output[-1200:] if output else "pip failed without output")
        self.refresh_thumbnail_controls()

    def current_watcher_config(self) -> WatcherConfig:
        """Read the watcher settings without selecting a connection.

        Returns:
            WatcherConfig: Saved settings with the current Watcher fields.

        Raises:
            ValueError: The scan interval or file stability value is invalid.
        """
        try:
            interval = max(
                MIN_INTERVAL_SECONDS,
                float(self.interval_var.get().strip() or DEFAULT_INTERVAL_SECONDS),
            )
            stable = max(
                MIN_STABLE_SECONDS,
                float(self.stable_var.get().strip() or DEFAULT_STABLE_SECONDS),
            )
        except ValueError as exc:
            raise ValueError("Enter a valid scan interval and file stability time.") from exc
        return replace(
            self.config,
            watched_folder=self.watched_folder_var.get().strip(),
            scan_interval_seconds=interval,
            stable_seconds=stable,
            attach_sim_camera_metadata=bool(self.attach_sim_camera_metadata_var.get()),
            delete_uploaded_files=bool(self.delete_uploaded_files_var.get()),
            watch_recursive=bool(self.watch_recursive_var.get()),
        )

    def current_config(self) -> WatcherConfig:
        """
        Read and validate the current UI values.

        Returns:
            WatcherConfig: WatcherConfig built from the current form state.

        Raises:
            ValueError: Raised when numeric settings cannot be parsed.
        """
        watcher = self.current_watcher_config()
        try:
            thumbnail_workers = parse_worker_choice(self.manual_thumbnail_workers_var.get())
            upload_workers = parse_worker_choice(self.manual_upload_workers_var.get())
            inventory_refresh = max(1.0, float(self.inventory_refresh_var.get().strip() or DEFAULT_INVENTORY_REFRESH_SECONDS))
            archive_max_entries = max(10, min(50000, int(self.archive_max_entries_var.get().strip() or REDESIGN_CONFIG_DEFAULTS["archive_max_entries"])))
            archive_max_total = int(max(0.01, min(128.0, float(self.archive_max_total_gib_var.get().strip() or 16.0))) * (1024 ** 3))
            archive_max_file = int(max(0.001, min(32.0, float(self.archive_max_file_gib_var.get().strip() or 2.0))) * (1024 ** 3))
            archive_max_ratio = max(2.0, min(10000.0, float(self.archive_max_ratio_var.get().strip() or 250.0)))
            staging_cleanup_hours = max(1.0, min(24.0 * 30.0, float(self.staging_cleanup_hours_var.get().strip() or 24.0)))
            ai_worker_poll = max(AI_WORKER_MIN_POLL_SECONDS, float(self.ai_worker_poll_var.get().strip() or DEFAULT_AI_WORKER_POLL_SECONDS))
            ai_worker_lease = max(60, min(AI_WORKER_MAX_LEASE_SECONDS, int(self.ai_worker_lease_var.get().strip() or DEFAULT_AI_WORKER_LEASE_SECONDS)))
            ai_transformers_detection_threshold = max(0.01, min(0.95, float(self.ai_transformers_detection_threshold_var.get().strip() or AI_TRANSFORMERS_DETECTION_THRESHOLD_DEFAULT)))
        except ValueError as exc:
            raise ValueError("Timing, worker, archive-safety, and AI numeric settings must contain valid numbers.") from exc

        gallery_url = self.gallery_url_var.get().strip()
        if gallery_url:
            gallery_url = normalize_upload_url(gallery_url)
            if not gallery_url:
                raise ValueError("Gallery URL must use HTTP or HTTPS and contain a valid host.")

        return replace(
            watcher,
            schema_version=CONFIG_SCHEMA_VERSION,
            gallery_url=gallery_url,
            api_key=self.api_key_var.get().strip(),
            create_thumbnails=bool(self.create_thumbnails_var.get()),
            simconnect_dll_path=self.simconnect_dll_path_var.get().strip(),
            sync_utc_time=self.sync_utc_time_var.get(),
            manual_thumbnail_mode="local" if self.manual_local_thumbnails_var.get() else "server",
            manual_thumbnail_workers=thumbnail_workers,
            manual_upload_workers=upload_workers,
            inventory_refresh_seconds=inventory_refresh,
            archive_max_entries=archive_max_entries,
            archive_max_uncompressed_bytes=archive_max_total,
            archive_max_file_bytes=archive_max_file,
            archive_max_compression_ratio=archive_max_ratio,
            staging_cleanup_age_hours=staging_cleanup_hours,
            close_to_tray=bool(self.close_to_tray_var.get()),
            start_with_windows=bool(self.start_with_windows_var.get()),
            activity_history_limit=self.config.activity_history_limit,
            ai_worker_enabled=bool(self.ai_worker_enabled_var.get()),
            ai_worker_poll_seconds=ai_worker_poll,
            ai_worker_lease_seconds=ai_worker_lease,
            ai_model_name=self.ai_model_name_var.get().strip() or AI_MODEL_NAME_DEFAULT,
            ai_model_version=self.ai_model_version_var.get().strip() or AI_MODEL_VERSION_DEFAULT,
            ai_external_command=self.ai_external_command_var.get().strip(),
            ai_vision_backend=normalize_ai_vision_backend(self.ai_vision_backend_var.get()),
            ai_transformers_caption_model=self.ai_transformers_caption_model_var.get().strip() or AI_TRANSFORMERS_CAPTION_MODEL_DEFAULT,
            ai_transformers_detector_model=self.ai_transformers_detector_model_var.get().strip() or AI_TRANSFORMERS_DETECTOR_MODEL_DEFAULT,
            ai_transformers_object_labels=self.ai_transformers_object_labels_var.get().strip() or AI_TRANSFORMERS_OBJECT_LABELS_DEFAULT,
            ai_transformers_detection_threshold=ai_transformers_detection_threshold,
            ai_ollama_url=self.ai_ollama_url_var.get().strip() or AI_OLLAMA_URL_DEFAULT,
            ai_ollama_model=self.ai_ollama_model_var.get().strip() or AI_OLLAMA_MODEL_DEFAULT,
            ai_semantic_prompt=self.ai_semantic_prompt_var.get().strip() or AI_SEMANTIC_PROMPT_DEFAULT,
        )


    def update_watch_folder_display(self, *_trace_info: str) -> None:
        """Refresh the read-only folder label as its edit field changes.

        Args:
            *_trace_info (str): Tk variable name, index, and write operation.

        Returns:
            None: Shows the selected path or a concise empty-state label.
        """
        folder = self.watched_folder_var.get().strip()
        self.watch_folder_display_var.set(folder or "No folder selected")

    def browse_folder(self, *, parent: Optional["tk.Misc"] = None) -> None:
        """
        Open a native directory picker for the stopped Watcher.

        Args:
            parent (Optional[tk.Misc]): Settings dialog for an unsaved edit,
                or None to save the folder directly from the main window.

        Returns:
            None: Updates the folder after a successful pick and any required
                save. Cancel or a failed save leaves the existing folder.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.worker is not None and self.worker.is_alive():
            messagebox.showinfo(
                "Watch folder", "Stop Watcher before changing its folder.",
                parent=parent or self.root,
            )
            return
        selected = filedialog.askdirectory(
            initialdir=self.watched_folder_var.get() or str(Path.home()),
            parent=parent or self.root, mustexist=True,
        )
        if not selected:
            return
        if parent is None:
            try:
                self.config = self._save_owned_config(
                    replace(self.config, watched_folder=selected), first_setup=True,
                )
            except (OSError, ModeCoordinatorError) as exc:
                messagebox.showerror("Watch folder", str(exc), parent=self.root)
                return
        self.watched_folder_var.set(selected)
        self.watch_ignored_var.set("Ignored at startup: not scanned yet")
        self.watch_last_scan_var.set("Last scan: -")


    def browse_simconnect_dll(self) -> None:
        """
        Open a file picker for the optional SimConnect client DLL.
        """
        selected = filedialog.askopenfilename(
            title="Select SimConnect.dll",
            initialdir=str(Path(self.simconnect_dll_path_var.get()).parent) if self.simconnect_dll_path_var.get().strip() else str(Path.home()),
            filetypes=[("SimConnect DLL", "SimConnect.dll"), ("DLL files", "*.dll"), ("All files", "*.*")],
        )
        if selected:
            self.simconnect_dll_path_var.set(selected)

    def select_manual_files(self) -> None:
        """
        Open a file picker and add supported images to the manual upload list.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        selected = filedialog.askopenfilenames(
            title="Choose files to import",
            initialdir=self.watched_folder_var.get() or str(Path.home()),
            filetypes=selected_image_filetypes() + [("All files", "*.*")],
        )
        if not selected:
            return
        self.manual_paths = [Path(value) for value in selected]
        self.manual_folder = None
        self.manual_zip = None
        self.manual_selection_var.set(f"Files: {len(self.manual_paths)} selected")
        self.rebuild_manual_preflight()

    def select_manual_folder(self) -> None:
        """Choose one folder for explicit manual import discovery."""
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        selected = filedialog.askdirectory(initialdir=self.watched_folder_var.get() or str(Path.home()), title="Choose folder to import")
        if not selected:
            return
        self.manual_paths = []
        self.manual_folder = Path(selected)
        self.manual_zip = None
        self.manual_selection_var.set("Folder: " + str(self.manual_folder))
        self.rebuild_manual_preflight()

    def select_manual_zip(self) -> None:
        """Choose one ZIP archive for bounded staging and preflight."""
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        selected = filedialog.askopenfilename(
            title="Choose ZIP archive to import",
            initialdir=self.watched_folder_var.get() or str(Path.home()),
            filetypes=[("ZIP archives", "*.zip"), ("All files", "*.*")],
        )
        if not selected:
            return
        self.manual_paths = []
        self.manual_folder = None
        self.manual_zip = Path(selected)
        self.manual_selection_var.set("ZIP: " + str(self.manual_zip))
        self.rebuild_manual_preflight()


    def clear_manual_files(self) -> None:
        """
        Clear the manual upload selection.

        Returns:
            None: Clears the selection unless an update blocks changes.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.preflight_worker and self.preflight_worker.is_alive():
            self.preflight_worker.stop()
            messagebox.showwarning(
                "Import", "The previous preflight is stopping. Clear the selection again after it finishes.",
            )
            return
        if self.manual_plan is not None and self.manual_plan.job.started_at <= 0:
            try:
                self.flights.import_bindings.discard_unbound_preflight(
                    self.job_store, self.manual_plan.job.id,
                )
            except (OSError, ValueError, RuntimeError) as exc:
                messagebox.showwarning("Import recovery", str(exc))
                return
        self.manual_paths = []
        self.manual_folder = None
        self.manual_zip = None
        self.manual_plan = None
        self._preflight_approved = None
        self.current_job = None
        self.manual_selection_var.set("Choose files, a folder, or a ZIP archive")
        self.manual_preflight_var.set("Select a source to build an import summary. Nothing uploads until you press Start import.")
        self.manual_progress_var.set(0.0)
        self.manual_progress_text_var.set("No active import")
        self.refresh_manual_file_label()
        self.refresh_import_item_tree(None)
        self.start_import_button.configure(state="disabled")

    def rebuild_manual_preflight(self) -> None:
        """Start a non-uploading preflight pass for the selected source.

        Returns:
            None: Starts a worker when the selected connection is ready, or
                displays the reason the preflight could not start.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.api_key_revoke_running:
            messagebox.showwarning("Connection", "Wait for API-key revocation to finish before starting import preflight.")
            return
        if self.manual_worker and self.manual_worker.is_alive():
            return
        if self.preflight_worker and self.preflight_worker.is_alive():
            self.preflight_worker.stop()
            messagebox.showwarning(
                'Import', 'The previous preflight is stopping. Start preflight again when it has finished.',
            )
            return
        if not self.manual_paths and self.manual_folder is None and self.manual_zip is None:
            return
        try:
            config = self.current_config()
            linked_target = self.capture_linked_target()
            if linked_target is None and config.to_dict() != self.config.to_dict():
                raise ValueError('Save the current settings before starting import preflight.')
            config = capture_worker_config(config)
            config_port = self.config_store.mode_port(config)
            if linked_target is not None and self.worker and self.worker.is_alive():
                raise ValueError('Stop the linked watcher before starting import preflight.')
        except ValueError as exc:
            messagebox.showerror("Invalid settings", str(exc))
            return
        if self.manual_plan is not None and self.manual_plan.job.started_at <= 0:
            try:
                self.flights.import_bindings.discard_unbound_preflight(
                    self.job_store, self.manual_plan.job.id,
                )
            except (OSError, ValueError, RuntimeError) as exc:
                messagebox.showwarning("Import recovery", str(exc))
                return
        self.manual_plan = None
        self._preflight_approved = None
        self.current_job = None
        self.start_import_button.configure(state="disabled")
        self.retry_failed_button.configure(state="disabled")
        self.manual_preflight_var.set("Inspecting source, hashing files, checking media capabilities, and comparing remote inventory...")
        self.preflight_progress.grid()
        self.preflight_progress.start(12)
        source_kind = "files" if self.manual_paths else ("folder" if self.manual_folder is not None else "zip")
        self.preflight_worker = ImportPreflightThread(
            config,
            source_kind,
            self.manual_paths,
            self.manual_folder,
            self.manual_zip,
            bool(self.manual_folder_recursive_var.get()),
            bool(self.delete_source_zip_var.get()),
            self.events,
            self.job_store,
            linked_target,
            (self.mode_owner, config_port),
        )
        self.preflight_worker.start()
        self.manual_status_var.set("Preflight running")
        self.import_activity_var.set("Import: preflight")
        self.refresh_revoke_button_state()

    def format_bytes(self, value: int) -> str:
        """Format one byte count for compact UI summaries."""
        size = float(max(0, value))
        units = ["B", "KiB", "MiB", "GiB", "TiB"]
        for unit in units:
            if size < 1024.0 or unit == units[-1]:
                return f"{size:.1f} {unit}" if unit != "B" else f"{int(size)} B"
            size /= 1024.0
        return f"{int(value)} B"

    def render_preflight_plan(self, plan: ImportPlan) -> None:
        """Render a compact import summary and enable Start import when useful.

        Args:
            plan (ImportPlan): Preflight job, duplicate counts and warnings.

        Returns:
            None: Updates the summary, item tree and import controls.
        """
        job = plan.job
        duplicates = plan.duplicate_local + plan.duplicate_remote
        ready = sum(1 for item in job.items if item.state in RECOVERABLE_ITEM_STATES)
        unsupported = sum(plan.unsupported.values())
        lines = [
            f"Target: {job.target_gallery_label}",
            f"Supported files found: {len(job.items)}  |  Ready to upload: {ready}  |  Duplicate candidates: {duplicates}",
            f"Estimated accepted bytes: {self.format_bytes(job.bytes_total)}  |  Unsupported skipped: {unsupported}  |  Unsafe ZIP entries rejected: {len(plan.unsafe_entries)}",
            "Thumbnail mode: " + ("This PC creates derivatives with server fallback." if self.manual_local_thumbnails_var.get() else "Server creates thumbnails."),
            "Source deletion: Original files and ZIP archives are kept for manual verification.",
        ]
        special = []
        for suffix in (".heic", ".heif", ".dng"):
            capability = self.media_capabilities.get(suffix)
            if capability is not None:
                special.append(f"{suffix}: {'local decoder available' if capability.local_previewable else 'server upload only on this PC'}")
        if special:
            lines.append("Local HEIC/DNG capability: " + "; ".join(special))
        if plan.unsupported:
            grouped = ", ".join(f"{key}: {count}" for key, count in sorted(plan.unsupported.items()))
            lines.append("Unsupported: " + grouped)
        if plan.unsafe_entries:
            lines.append("Rejected archive entries: " + "; ".join(plan.unsafe_entries[:3]) + (" ..." if len(plan.unsafe_entries) > 3 else ""))
        if plan.warnings:
            lines.append("Warnings: " + " | ".join(plan.warnings[:3]))
        self.manual_preflight_var.set("\n".join(lines))
        self.refresh_import_item_tree(job)
        self.start_import_button.configure(state="normal" if ready > 0 else "disabled")
        self.retry_failed_button.configure(state="normal" if any(item.state in RECOVERABLE_ITEM_STATES or item.state == "failed_permanent" for item in job.items) else "disabled")
        self.activity_job_summary_var.set(self.describe_job(job))

    def refresh_import_item_tree(self, job: Optional[ImportJob]) -> None:
        """Refresh preflight/current item rows from one job snapshot."""
        if not hasattr(self, "import_item_tree"):
            return
        for iid in self.import_item_tree.get_children():
            self.import_item_tree.delete(iid)
        if hasattr(self, "activity_item_tree"):
            for iid in self.activity_item_tree.get_children():
                self.activity_item_tree.delete(iid)
        if job is None:
            return
        for item in job.items[:1000]:
            reason = item.reason
            self.import_item_tree.insert(
                "",
                "end",
                iid=item.id,
                values=(Path(item.source_label).name or item.source_label, self.format_bytes(item.size), item.local_decode, item.state, reason),
            )
        if hasattr(self, "activity_item_tree"):
            for item in job.items[:1000]:
                detail = item.reason
                if not detail and item.server_result:
                    detail = f"uploaded={item.server_result.get('uploaded', 0)}, scanned={item.server_result.get('scanned', 0)}"
                self.activity_item_tree.insert(
                    "",
                    "end",
                    iid="activity-" + item.id,
                    values=(Path(item.source_label).name or item.source_label, item.state, item.attempt_count, detail),
                )

    def describe_job(self, job: ImportJob) -> str:
        """Return one compact status sentence for Activity and tray views."""
        counts = job.counts()
        progress = f"{self.format_bytes(job.bytes_sent)} / {self.format_bytes(job.bytes_total)}"
        if job.started_at and not job.finished_at:
            elapsed = max(0.001, time.time() - job.started_at)
            rate = job.bytes_sent / elapsed
            if rate > 0:
                remaining = max(0, job.bytes_total - job.bytes_sent)
                progress += f" at {self.format_bytes(int(rate))}/s, ETA {int(remaining / rate)}s"
        return (
            f"{job.source_kind}: {job.source_label} | target {job.target_gallery_label} | "
            f"confirmed {counts['uploaded']}, duplicates {counts['duplicates']}, failed {counts['failed']}, "
            f"cancelled {counts['cancelled']}, active {counts['active']} | {progress}"
        )


    def refresh_manual_file_label(self) -> None:
        """
        Refresh the visible manual selection count.
        """
        if self.manual_paths:
            self.manual_selection_var.set(f"Files: {len(self.manual_paths)} selected")
        elif self.manual_folder is not None:
            self.manual_selection_var.set("Folder: " + str(self.manual_folder))
        elif self.manual_zip is not None:
            self.manual_selection_var.set("ZIP: " + str(self.manual_zip))
        else:
            self.manual_selection_var.set("Choose files, a folder, or a ZIP archive")


    def saved_linked_mode_selected(self) -> bool:
        """Reflect durable linked selection without silently selecting a key.

        Returns:
            bool: True for linked mode or unavailable startup evidence, and
                False only for verified legacy or first-setup evidence.
        """
        try:
            return self.mode_owner.view().state not in (
                'legacy_unmigrated', 'legacy', 'first_setup',
            )
        except (AttributeError, ModeCoordinatorError):
            return True

    def saved_linked_upload_identity(self) -> tuple[str, str] | None:
        """Recover the exact saved destination even without a live grant.

        Returns:
            tuple[str, str] | None: Verified issuer and target fingerprint,
                or None when mode evidence is unavailable or legacy is selected.
        """
        try:
            record = self.mode_owner.view().record
        except ModeCoordinatorError:
            return None
        if record is None or record.mode != "linked":
            return None
        return (
            record.issuer,
            linked_target_binding(record.issuer, record.account_id, record.gallery_id),
        )

    def commit_linked_upload_target(self, target: LinkedUploadTarget) -> bool:
        """Publish and activate one explicitly chosen server-verified target.

        The linking worker must have freshly verified this exact gallery, Admin
        and scope before asking the Tk owner to commit. No request is sent here.

        Args:
            target (LinkedUploadTarget): Exact independently verified gallery.

        Returns:
            bool: True only after local workspace admission and confirmed mode
                publication and activation.
        """
        if (not self.linked_workspace_ready()
                or self.linked_target_change_refusal(target.binding()) is not None):
            return False
        try:
            if self.mode_owner.view().state == 'first_setup':
                # The explicit target choice also authorizes initializing the
                # missing config. Write only the loaded defaults, never
                # copy the linked URL or read unsaved API-key form fields.
                self.config = self._save_owned_config(self.config, first_setup=True)
            view = self.mode_owner.select_linked(target)
            record = view.record
            return (
                view.state == 'linked_active' and record is not None
                and record.mode == 'linked' and record.issuer == target.issuer
                and record.account_id == target.user_id
                and record.gallery_id == target.gallery_id
                and record.scope == target.session.approved_scope()
            )
        except Exception:
            logging.warning('Linked target could not be activated safely.')
            return False

    def linked_workspace_ready(self) -> bool:
        """Check local profile ownership before requesting or starting uploads.

        Returns:
            bool: True for an admitted local Windows or macOS profile.
        """
        try:
            if sys.platform == "darwin":
                assert_local_macos_profile(CONFIG_DIR)
                if self.config_store.path.parent != CONFIG_DIR:
                    assert_local_macos_profile(self.config_store.path.parent)
            else:
                assert_local_windows_profile(CONFIG_DIR)
        except OSError:
            return False
        return True

    def commit_saved_api_key(self) -> bool:
        """Honor an explicit return to the unchanged saved gallery API key.

        Returns:
            bool: True only after the persistent owner confirms legacy mode.
        """
        if self.linked_target_change_refusal(None) is not None:
            return False
        try:
            view = self.mode_owner.select_legacy()
            return view.state in ('legacy', 'legacy_unmigrated')
        except Exception:
            logging.warning('Saved API-key mode could not be restored safely.')
            return False

    def linked_target_change_refusal(self, proposed_binding: str | None) -> str | None:
        """Explain the first blocker to an explicit upload-target change.

        Args:
            proposed_binding (str | None): Proposed linked target fingerprint,
                or None for the saved API-key connection.

        Returns:
            str | None: Short reason for refusal, or None when the mode
                and existing work permit the change.
        """
        if getattr(self, '_ordinary_restart_closing', False) is True:
            return 'Wait for the Watcher restart checkpoint to finish.'
        if isinstance(getattr(self, "_contextual_watch_target", None), LinkedUploadTarget):
            return 'Finish the current Watcher capture before changing its gallery.'
        if self.background_work_active() or self.flights.is_alive() or self.flights.busy():
            return 'Wait for the current Watcher, import or flight work to stop.'
        try:
            view = self.mode_owner.view()
        except ModeCoordinatorError:
            return 'Upload settings could not be verified. Restart WinApp and try again.'

        legacy_binding = connection_binding(
            normalize_upload_url(self.config.gallery_url), self.config.api_key,
        )
        record = view.record
        current_binding = (
            linked_target_binding(record.issuer, record.account_id, record.gallery_id)
            if record is not None and record.mode == 'linked'
            else legacy_binding
        )
        desired_binding = proposed_binding if proposed_binding is not None else legacy_binding
        try:
            # The saved mode identifies pending flights even when the linked
            # token has expired or WinApp has just restarted.
            if (self.flights.pending(binding=current_binding)
                    and desired_binding != current_binding):
                return 'Finish the pending flight before changing the upload target.'
            # An accepted import retains its binding across restarts. Do not
            # replace its destination, but allow reconnecting to that same
            # exact target to review and explicitly recover incomplete work.
            # Check even a same-target reactivation: an orphaned or damaged job
            # cannot become safe solely because the in-memory target matches.
            # Never erase a completed binding as a side effect of this check.
            with accepted_work_owner(CONFIG_DIR):
                bindings = self.flights.import_bindings.entries()
                # Both ledgers are re-read under one cooperative owner. This
                # cannot attest to previously deleted or rolled-back work.
                result = self.job_store.admitted_imports_match_target(
                    bindings, desired_binding,
                )
                if result is not True:
                    return 'Review unfinished imports in Activity before changing the upload target.'
        except UploadStateTooLargeError:
            return 'Local upload history exceeds the safe size limit. Review Activity.'
        except (OSError, ValueError, RuntimeError):
            return 'Local upload history could not be verified. Review Activity.'
        return None

    def linked_target_changed(self) -> None:
        """Refresh the chosen connection and invalidate stale preflight results.

        Returns:
            None: Keeps the saved API-key fields untouched and requires a fresh
                import preflight for the newly selected destination.
        """
        if (self.manual_plan is not None
                and not (self.manual_worker and self.manual_worker.is_alive())):
            self.manual_plan = None
            self._preflight_approved = None
            self.current_job = None
            self.start_import_button.configure(state='disabled')
            self.manual_preflight_var.set(
                'Gallery connection changed. Run import preflight again.',
            )
        target = self.linking_manager.selected_upload_target()
        if target is not None:
            self._verified_legacy_gallery = None
            self.connection_status_var.set('Ready for uploads')
            self.connection_detail_var.set(
                'Choose a folder in Watcher or add files in Import.',
            )
            self.gallery_label_var.set('Target gallery: ' + target.title)
            self.gallery_title_var.set(target.title)
        elif self.linking_manager.linked_upload_mode_selected():
            self._verified_legacy_gallery = None
            self.connection_status_var.set('Reconnect to upload')
            self.connection_detail_var.set('The selected upload gallery needs a new link.')
            self.gallery_label_var.set('Target gallery: unknown')
            self.gallery_title_var.set('')
        else:
            if self._restore_verified_legacy_gallery():
                self.connection_status_var.set('Connected')
                self.connection_detail_var.set(
                    'Authenticated endpoint available: '
                    + normalize_upload_url(self.config.gallery_url),
                )
            else:
                self.connection_status_var.set('Not tested')
                self.connection_detail_var.set(
                    'Saved API-key connection selected.',
                )
        self.refresh_connection_controls()

    def _restore_verified_legacy_gallery(self) -> bool:
        """Show legacy metadata only for a response verified against this binding.

        Returns:
            bool: True when its exact normalized URL/key binding remains valid.
        """
        verified = self._verified_legacy_gallery
        current_url = normalize_upload_url(self.config.gallery_url)
        current_key = self.config.api_key.strip()
        binding = connection_binding(current_url, current_key)
        if (verified is None or self.api_key_revoke_running
                or not current_url or not current_key
                or verified[1] != binding
                or normalize_upload_url(self.gallery_url_var.get()) != current_url
                or self.api_key_var.get().strip() != current_key):
            self._verified_legacy_gallery = None
            self.gallery_label_var.set('Target gallery: unknown')
            self.gallery_title_var.set('')
            return False
        self.gallery_label_var.set('Target gallery: ' + verified[2])
        self.gallery_title_var.set(verified[3])
        return True

    def _invalidate_verified_legacy_gallery(
        self, *_trace_args: str,
    ) -> None:
        """Clear verified legacy metadata as soon as connection fields differ.

        Args:
            *_trace_args (str): Tk variable-trace name, index and operation.

        Returns:
            None: Linked target labels remain owned by the selected target.
        """
        if self._verified_legacy_gallery is None:
            return
        if (normalize_upload_url(self.gallery_url_var.get())
                != normalize_upload_url(self.config.gallery_url)
                or self.api_key_var.get().strip() != self.config.api_key.strip()):
            self._verified_legacy_gallery = None
            if (self.linking_manager.selected_upload_target() is None
                    and not self.linking_manager.linked_upload_mode_selected()):
                self.gallery_label_var.set('Target gallery: unknown')
                self.gallery_title_var.set('')

    def probe_linked_uncertain_upload(
        self, target: LinkedUploadTarget, size: int,
    ) -> tuple[str, str]:
        """Observe one journaled hash under its target's exclusive lease.

        Args:
            target (LinkedUploadTarget): Exact live linked gallery and Admin.
            size (int): Size in bytes explicitly supplied by the user.

        Returns:
            tuple[str, str]: Server observation and the local marker digest.

        Raises:
            ValueError: The journal, session or identity cannot be verified.
        """
        lease = LinkedTargetLease(CONFIG_DIR, target.binding())
        lease.acquire()
        try:
            state = LinkedUploadState(target)
            file_hash, marker = state.recovery_marker()
            if (not target.session.matches_issuer(target.issuer)
                    or target.session.identity().user_id != target.user_id):
                raise ValueError('Linked identity is unavailable.')
            result = target.session.probe_uncertain_upload(target.gallery_id, file_hash, size)
            if (not target.session.matches_issuer(target.issuer)
                    or target.session.identity().user_id != target.user_id
                    or LinkedUploadState(target).recovery_marker() != (file_hash, marker)):
                raise ValueError('Linked recovery state changed.')
            return result, marker
        finally:
            lease.release()

    def publish_linked_recovery(
        self, target: LinkedUploadTarget, marker: str, publish: Callable[[], None],
    ) -> bool:
        """Publish an observation only while its original journal is current.

        Args:
            target (LinkedUploadTarget): Exact linked gallery and Admin observed.
            marker (str): Digest of the exact locally observed uncertain marker.
            publish (Callable[[], None]): Tk-thread update without journal writes.

        Returns:
            bool: True only if the same marker was revalidated under the lease.
        """
        lease = LinkedTargetLease(CONFIG_DIR, target.binding())
        try:
            lease.acquire()
            if (self.linking_manager.selected_upload_target() is not target
                    or LinkedUploadState(target).recovery_marker()[1] != marker):
                return False
            publish()
            return True
        except (OSError, ValueError, RuntimeError):
            return False
        finally:
            lease.release()

    def review_linked_uncertainty(
        self, target: LinkedUploadTarget, marker: Optional[str],
    ) -> Optional[str]:
        """Read or acknowledge one exact retained unknown under its gallery lease.

        Args:
            target (LinkedUploadTarget): Current selected gallery and session.
            marker (Optional[str]): None for inspection; exact inspected marker
                only after explicit user confirmation to continue other files.

        Returns:
            Optional[str]: Current marker after inspection or verified review;
                None when work, the selected target or the journal changed.
        """
        if (self.background_work_active() or not self.linked_workspace_ready()
                or self.linking_manager.selected_upload_target() is not target):
            return None
        lease = LinkedTargetLease(CONFIG_DIR, target.binding())
        try:
            lease.acquire()
            state = LinkedUploadState(target)
            current = state.recovery_marker()[1]
            if marker is not None:
                state.review_uncertainty(marker)
            return current
        except (OSError, ValueError, RuntimeError):
            return None
        finally:
            lease.release()

    def capture_linked_target(self) -> Optional[LinkedUploadTarget]:
        """Capture the explicitly selected target without legacy fallback.

        Returns:
            Optional[LinkedUploadTarget]: Live linked target, or None only when
                the user explicitly selected the saved API-key connection.

        Raises:
            ValueError: Linked mode lacks a live session, safe local profile or
                matching saved mode evidence.
        """
        if not self.linking_manager.linked_upload_mode_selected():
            return None
        target = self.linking_manager.selected_upload_target()
        if target is None:
            raise ValueError(
                'Linked gallery access expired. Reconnect or explicitly select '
                'the saved API-key connection.',
            )
        if not self.linked_workspace_ready():
            raise ValueError(tr(
                self.flights.ui_language.get(), 'linking_upload_workspace',
            ))
        try:
            view = self.mode_owner.view()
        except ModeCoordinatorError:
            raise ValueError('Linked mode evidence is unavailable.') from None
        record = view.record
        if (view.state != 'linked_active' or record is None
                or record.mode != 'linked' or record.issuer != target.issuer
                or record.account_id != target.user_id
                or record.gallery_id != target.gallery_id
                or record.scope != target.session.approved_scope()):
            raise ValueError('Linked gallery mode needs verification before uploading.')
        return target

    def _initialize_gui_mode(self) -> None:
        """Observe startup mode off Tk before any GUI key work can be admitted.

        Returns:
            None: Reports unavailable evidence without opening a fallback mode.
        """
        try:
            self.mode_owner.initialize()
        except ModeCoordinatorError:
            self.events.put({
                "kind": "linking_resume_startup",
                "payload": {"issuer": None},
            })
            self.events.put(('warning', 'Upload mode needs verification before API-key work.'))
            self.events.put({
                'kind': 'gui_mode_initialized', 'payload': {'verified': False},
            })
        else:
            identity = self.saved_linked_upload_identity()
            if identity is not None:
                self.events.put({"kind": "saved_linked_mode_ready"})
            self.events.put({
                "kind": "linking_resume_startup",
                "payload": {"issuer": identity[0] if identity else None},
            })
            self.events.put({
                'kind': 'gui_mode_initialized', 'payload': {'verified': True},
            })

    def _inspect_update_continuation(self) -> None:
        """Inspect one prepared restart off Tk after mode initialization.

        Returns:
            None: Queues a profile-bound continuation or a fixed safe reason;
                an ordinary startup never resumes a stopped capture.
        """
        if (self._update_resume_attempted or not self._gui_mode_ready
                or getattr(self, '_update_resume_cancelled', False)
                or getattr(self, '_update_resume_inspecting', False)):
            return
        if self._update_resume_deadline <= 0.0:
            self._update_resume_deadline = (
                time.monotonic() + UPDATE_CONTINUATION_LINK_SECONDS
            )
        self._update_resume_generation += 1
        generation = self._update_resume_generation
        self._update_resume_attempted = True
        self._update_resume_inspecting = True
        requested = self._after_update_nonce
        inspection_stage = 'workspace_lease'

        def inspect() -> None:
            """Verify saved intent without touching Tk or uploading media.

            Returns:
                None: Queues only validated checkpoint metadata or a safe failure.
            """
            nonlocal inspection_stage

            def reject(reason: str) -> None:
                """Queue a fixed local diagnosis for a rejected checkpoint.

                Args:
                    reason (str): Fixed stage-specific reason identifier.

                Returns:
                    None: Publishes no checkpoint contents or private values.
                """
                self.events.put({
                    'kind': 'watcher_resume_failed',
                    'payload': {
                        'reason': reason, 'stage': inspection_stage,
                        'generation': generation,
                    },
                })

            try:
                if getattr(self, '_update_resume_cancelled', False):
                    return
                automatic = requested is not None
                lease = self.flights.lease
                if lease is None:
                    raise ValueError('The flight workspace is unavailable.')
                inspection_stage = 'primary_checkpoint'
                saved = WatcherContinuation.load(lease)
                ordinary_restart = False
                if requested is not None:
                    inspection_stage = 'update_nonce_and_version'
                    if (saved is None or saved.update_session != requested
                            or saved.phase not in ('prepared', 'claimed')
                            or APP_VERSION not in (
                                saved.source_version, saved.target_version,
                            )):
                        raise ValueError('No matching update continuation.')
                elif saved is not None:
                    inspection_stage = 'ordinary_restart_mirror'
                    ordinary = WatcherContinuation.load(
                        lease, ordinary_restart=True,
                    )
                    if ordinary is not None and (
                        replace(ordinary, phase=saved.phase) == saved
                        and ordinary.phase in ('prepared', 'claimed')
                        and ordinary.source_version == APP_VERSION
                        and ordinary.target_version == APP_VERSION
                        and saved.phase in ('prepared', 'claimed')
                    ):
                        ordinary_restart = True
                    else:
                        inspection_stage = 'ordinary_restart_not_admitted'
                        return
                elif requested is not None:
                    raise ValueError('The update continuation is missing.')
                else:
                    # Older helpers cannot pass a nonce. Admit only an actual,
                    # verified installed-version transition plus an active API
                    # session with no potentially interrupted accepted upload.
                    inspection_stage = 'legacy_update_bridge'
                    from uploader.update_helper import find_legacy_update_ticket

                    ticket = find_legacy_update_ticket(
                        APP_VERSION, self.updates.previous_version,
                        WATCHER_CONTINUATION_LIFETIME_SECONDS,
                    )
                    if ticket is None or self.mode_owner.view().state != 'legacy':
                        return
                    binding = connection_binding(
                        normalize_upload_url(self.config.gallery_url),
                        self.config.api_key,
                    )
                    pending = self.flights.pending(binding=binding)
                    if len(pending) != 1:
                        return
                    plan = pending[0]
                    queue_row = read_document(plan.directory / 'watch-drain.json')
                    telemetry = read_document(plan.directory / 'session.json')
                    if (plan.local_only or plan.completed or plan.abandoned
                            or queue_row.get('initial_captured', True) is not True
                            or queue_row.get('frozen') is not False
                            or queue_row.get('cutoff') != 0
                            or queue_row.get('error')
                            or telemetry.get('recording_ended') is not False):
                        return
                    if queue_row.get('pending') != []:
                        raise ValueError('Old-client accepted uploads need review.')
                    saved = WatcherContinuation(
                        plan.session_id, binding,
                        WatcherContinuation.profile_digest(self.flights.directory),
                        self.updates.previous_version, APP_VERSION,
                        ticket.session_id, 'legacy', False, time.time(), 'prepared',
                    )
                    saved.save(lease)
                if ordinary_restart:
                    inspection_stage = 'ordinary_session_and_queue'
                    pending = self.flights.pending(binding=saved.binding)
                    if len(pending) != 1 or pending[0].session_id != saved.session_id:
                        reject('restart_session_mismatch')
                        return
                    plan = pending[0]
                    queue_row = read_document(plan.directory / 'watch-drain.json')
                    mode_view = self.mode_owner.view()
                    mode_record = mode_view.record
                    if (plan.completed or plan.abandoned
                            or plan.local_only != (saved.mode == 'linked')
                            or queue_row.get('initial_captured') is not True
                            or queue_row.get('frozen') is not False
                            or queue_row.get('cutoff') != 0 or queue_row.get('error')):
                        reject('restart_queue_needs_review')
                        return
                    if saved.mode == 'linked':
                        inspection_stage = 'ordinary_linked_identity'
                        if (mode_record is None or mode_record.mode != 'linked'
                                or mode_record.scope != saved.approved_scope
                                or linked_target_binding(
                                    mode_record.issuer, mode_record.account_id,
                                    mode_record.gallery_id,
                                ) != saved.binding):
                            reject('restart_linked_identity_changed')
                            return
                    elif (saved.mode != 'legacy' or mode_view.state != 'legacy'
                          or saved.binding != connection_binding(
                              normalize_upload_url(self.config.gallery_url),
                              self.config.api_key,
                          )):
                        reject('restart_legacy_target_changed')
                        return
                    automatic = True
                self.events.put({
                    'kind': 'watcher_resume_available',
                    'payload': {
                        'continuation': saved, 'automatic': automatic,
                        'ordinary_restart': ordinary_restart,
                        'generation': generation,
                    },
                })
            except OSError:
                if (not getattr(self, '_update_resume_cancelled', False)
                        and time.monotonic() < self._update_resume_deadline):
                    self.events.put({
                        'kind': 'watcher_resume_inspection_retry',
                        'payload': {
                            'reason': 'temporary_checkpoint_read_failure',
                            'stage': inspection_stage,
                            'generation': generation,
                        },
                    })
                    return
                self.events.put({
                    'kind': 'watcher_resume_failed',
                    'payload': {
                        'reason': 'checkpoint_read_unavailable',
                        'stage': inspection_stage,
                        'generation': generation,
                    },
                })
            except WatcherContinuationError as error:
                self.events.put({
                    'kind': 'watcher_resume_failed',
                    'payload': {
                        'reason': 'checkpoint_' + error.reason,
                        'stage': inspection_stage,
                        'generation': generation,
                    },
                })
            except (ValueError, RuntimeError):
                reason_by_stage = {
                    'workspace_lease': 'workspace_lease_unavailable',
                    'primary_checkpoint': 'primary_checkpoint_unverified',
                    'update_nonce_and_version': 'update_nonce_or_version_mismatch',
                    'ordinary_restart_mirror': 'restart_mirror_unverified',
                    'legacy_update_bridge': 'legacy_bridge_unverified',
                    'ordinary_session_and_queue': 'restart_queue_unverified',
                    'ordinary_linked_identity': 'restart_linked_identity_changed',
                }
                self.events.put({
                    'kind': 'watcher_resume_failed',
                    'payload': {
                        'reason': reason_by_stage.get(
                            inspection_stage, 'checkpoint_admission_unverified',
                        ),
                        'stage': inspection_stage,
                        'generation': generation,
                    },
                })

        try:
            threading.Thread(
                target=inspect, name='PHPGalleryUpdateResumeInspect', daemon=True,
            ).start()
        except RuntimeError:
            self._update_resume_inspecting = False
            self.events.put({
                'kind': 'watcher_resume_failed',
                'payload': {
                    'reason': 'inspection_worker_unavailable',
                    'stage': 'checkpoint_inspection',
                    'generation': generation,
                },
            })

    def _continue_update_resume(self) -> None:
        """Wait for owned writers and fresh linked authority without blocking Tk.

        Returns:
            None: Prepares one exact capture or retains an actionable safe reason.
        """
        record = self._resume_continuation
        if record is None or self.exiting or self._update_resume_preparing:
            return
        if getattr(self, '_update_resume_cancelled', False):
            return
        generation = getattr(self, '_update_resume_generation', 0)
        if any(worker is not None and worker.is_alive()
               for worker in self._update_checkpoint_workers):
            if time.monotonic() >= self._update_resume_deadline:
                self._update_resume_failed('prior_workers_still_running')
                return
            self.root.after(UPDATE_CONTINUATION_POLL_MS, self._continue_update_resume)
            return
        try:
            view = self.mode_owner.view()
            if record.mode == 'legacy':
                if (view.state != 'legacy' or record.binding != connection_binding(
                    normalize_upload_url(self.config.gallery_url), self.config.api_key,
                )):
                    raise ValueError('The saved API target changed.')
                self._prepare_update_resume(None)
                return
            target_record = view.record
            if (target_record is None or target_record.mode != 'linked'
                    or target_record.scope != record.approved_scope
                    or linked_target_binding(
                        target_record.issuer, target_record.account_id,
                        target_record.gallery_id,
                    ) != record.binding):
                raise ValueError('The linked gallery target changed.')
            session = self.linking_manager.resume_upload_session(
                target_record.issuer, target_record.account_id,
            )
            if session is None:
                if time.monotonic() >= self._update_resume_deadline:
                    self._update_resume_failed('saved_gallery_access_unavailable')
                    return
                self.status_var.set('Restoring the approved Watcher connection…')
                self.root.after(
                    UPDATE_CONTINUATION_POLL_MS, self._continue_update_resume,
                )
                return
            if session.approved_scope() != record.approved_scope:
                raise ValueError('The original linked scope changed.')
            self._update_resume_preparing = True

            def verify_target() -> None:
                """Reverify the original gallery using the approved live account.

                Returns:
                    None: Queues the exact target or a fixed authorization failure.
                """
                try:
                    target = verify_linked_upload_target(
                        session, target_record.issuer, target_record.account_id,
                        record.approved_scope, target_record.gallery_id,
                    )
                    self.events.put({
                        'kind': 'watcher_resume_target',
                        'payload': {
                            'target': target, 'generation': generation,
                        },
                    })
                except Exception as error:
                    if isinstance(error, LinkingResponseError):
                        transient_reasons = {
                            'dns_failure', 'connect_failure', 'tls_failure',
                            'timeout', 'transport_failure', 'throttled',
                        }
                        transient = (
                            error.status == 429
                            or error.status is not None
                            and 500 <= error.status <= 599
                            or error.status is None
                            and error.reason in transient_reasons
                        )
                        if transient:
                            self.events.put({
                                'kind': 'watcher_resume_retry',
                                'payload': {
                                    'reason': 'temporary_target_verification_failure',
                                    'stage': 'target_verification',
                                    'generation': generation,
                                },
                            })
                            return
                        if error.status in (401, 403):
                            self.events.put({
                                'kind': 'watcher_resume_failed',
                                'payload': {
                                    'reason': 'saved_gallery_access_revoked',
                                    'stage': 'target_authorization',
                                    'generation': generation,
                                },
                            })
                            return
                    error_type = type(error).__name__
                    if (not error_type.isidentifier()
                            or len(error_type) > UPDATE_RESUME_EXCEPTION_CLASS_MAX_CHARS):
                        error_type = 'Exception'
                    self.events.put((
                        'warning',
                        'Watcher resume verification failed; '
                        'operation=verify_linked_upload_target '
                        f'error_type={error_type}.',
                    ))
                    self.events.put({
                        'kind': 'watcher_resume_failed',
                        'payload': {
                            'reason': 'saved_gallery_access_unverified',
                            'stage': 'target_verification',
                            'generation': generation,
                        },
                    })

            threading.Thread(
                target=verify_target, name='PHPGalleryUpdateResumeTarget', daemon=True,
            ).start()
        except (OSError, ValueError, RuntimeError):
            self._update_resume_failed('saved_target_changed')

    def _update_resume_failed(self, reason: str, *, stage: str = 'resume') -> None:
        """Leave unfinished work recoverable with a fixed non-secret diagnosis.

        Args:
            reason (str): Locally authored continuation failure identifier.
            stage (str): Locally authored validation or recovery phase.

        Returns:
            None: Retains credentials and queue while showing the next action.
        """
        # The identifier is diagnostic data, never server text or an exception.
        if re.fullmatch(r'[a-z_]+', reason) is None:
            reason = 'continuation_unverified'
        if re.fullmatch(r'[a-z_]+', stage) is None:
            stage = 'resume'
        self._close_update_resume_context_key()
        self._update_resume_status = 'stage=' + stage + '; reason=' + reason
        self._update_resume_preparing = False
        self._resume_continuation = None
        messages = {
            'prior_workers_still_running':
                'Previous work is still finishing. Wait, then resume Watcher.',
            'saved_gallery_access_unavailable':
                'Reconnect the original gallery, then resume Watcher.',
            'saved_gallery_access_unverified':
                'The original gallery access could not be verified. Reconnect it.',
            'saved_watcher_access_unavailable':
                'Temporary Watcher access is unavailable. Reconnect the original gallery.',
            'saved_target_changed':
                'The original upload target changed. Select it before resuming.',
            'session_claim_unverified':
                'The saved session could not be verified. Review pending uploads.',
            'saved_session_needs_review':
                'Saved screenshot or flight data needs review. Copy diagnostics.',
            'saved_update_state_unverified':
                'The saved update state could not be verified. Review pending uploads.',
            'primary_checkpoint_unverified':
                'The saved Watcher checkpoint could not be verified. Review pending uploads.',
            'restart_mirror_unverified':
                'The restart checkpoint could not be verified. Review pending uploads.',
            'restart_queue_unverified':
                'The saved Watcher queue could not be verified. Review pending uploads.',
            'restart_queue_needs_review':
                'The saved Watcher queue needs review before resuming.',
            'restart_session_mismatch':
                'The saved Watcher session no longer matches its checkpoint.',
            'restart_linked_identity_changed':
                'The linked account or gallery changed. Select the original target.',
            'restart_legacy_target_changed':
                'The saved upload target changed. Select it before resuming.',
            'update_nonce_or_version_mismatch':
                'The update restart could not be matched to this checkpoint.',
            'workspace_lease_unavailable':
                'The saved Watcher workspace is unavailable. Review pending uploads.',
            'legacy_bridge_unverified':
                'The older update handoff could not be verified. Review pending uploads.',
            'checkpoint_admission_unverified':
                'The saved Watcher checkpoint could not be verified. Review pending uploads.',
            'inspection_worker_unavailable':
                'The saved Watcher checkpoint could not be inspected. Review pending uploads.',
            'checkpoint_expired':
                'The saved Watcher checkpoint expired. Review pending uploads.',
            'checkpoint_profile_mismatch':
                'The saved Watcher checkpoint belongs to a different profile.',
            'checkpoint_invalid_scope':
                'The saved linked permission scope changed. Reconnect the original gallery.',
            'saved_gallery_access_revoked':
                'The original gallery permission was refused. Reconnect that gallery.',
            'continuation_worker_stopped':
                'Watcher stopped before resume was confirmed. Review pending uploads before trying again.',
            'saved_watcher_key_close_pending':
                'Previous Watcher access is still being closed. Wait for confirmation, then resume Watcher.',
        }
        message = messages.get(
            reason, 'Watcher could not resume. Copy diagnostics; saved work is retained.',
        )
        self._set_watch_start_activity(False, message)
        self.write_log('[watcher-resume] ' + self._update_resume_status, 'warning')

    def _close_update_resume_context_key(self) -> None:
        """Revoke the exact staged contextual key after a failed resume.

        Returns:
            None: Retains ownership until a close result arrives; the key is
                never published as an active Watcher target on this path.
        """
        target = getattr(self, '_update_resume_context_target', None)
        if (not isinstance(target, LinkedUploadTarget)
                or target.watch_key is None
                or getattr(self, '_update_resume_context_key_closing', False)):
            return
        self._update_resume_context_key_closing = True

        def revoke() -> None:
            """Close one staged key off Tk and report only its outcome.

            Returns:
                None: Queues the target identity and a boolean close result.
            """
            try:
                target.session.close_watch_key(target.gallery_id, target.watch_key)
                closed = True
            except Exception:  # noqa: BLE001
                closed = False
            self.events.put({
                'kind': 'update_resume_context_key_closed',
                'payload': {'target': target, 'closed': closed},
            })

        try:
            worker = threading.Thread(
                target=revoke, name='PHPGalleryResumeKeyClose', daemon=True,
            )
            self._update_resume_context_key_worker = worker
            worker.start()
        except RuntimeError:
            self._update_resume_context_key_closing = False
            self._update_resume_context_key_worker = None

    def _finish_update_resume_context_key_close(
        self, target: LinkedUploadTarget, closed: bool,
    ) -> None:
        """Release local ownership only after the exact remote close result.

        Args:
            target (LinkedUploadTarget): Staged key owner from the worker event.
            closed (bool): True only after the server confirmed key closure.

        Returns:
            None: Detaches the matching temporary selection on success, or
                retains it for a later close retry after failure.
        """
        if target is not getattr(self, '_update_resume_context_target', None):
            return
        self._update_resume_context_key_closing = False
        self._update_resume_context_key_worker = None
        if closed:
            self._update_resume_context_target = None
            self.linking_manager.detach_photos_watcher_target(target)
            if (getattr(self, '_resume_retry_available', False)
                    and getattr(self, '_resume_continuation', None) is not None):
                self.refresh_watcher_action()
        else:
            self.status_var.set(
                'Watcher access could not be closed. Review the saved session.',
            )

    def _retry_update_continuation(self) -> bool:
        """Retry only an explicitly offered exact update continuation.

        Returns:
            bool: True when the visible Resume Watcher action starts its owned
                retry; False leaves the ordinary passive recovery action intact.
        """
        if not getattr(self, '_resume_retry_available', False):
            return False
        try:
            lease = self.flights.lease
            record = WatcherContinuation.load(lease) if lease is not None else None
            if (record is None or record.phase not in ('prepared', 'claimed')
                    or APP_VERSION not in (record.source_version, record.target_version)):
                return False
        except (OSError, ValueError, RuntimeError):
            return False
        self._resume_continuation = record
        self._update_resume_deadline = time.monotonic() + UPDATE_CONTINUATION_LINK_SECONDS
        self._continue_update_resume()
        return True

    def _prepare_update_resume(
        self, target: Optional[LinkedUploadTarget],
    ) -> bool:
        """Claim and prepare the same queue and telemetry off the Tk thread.

        Args:
            target (Optional[LinkedUploadTarget]): Reverified original linked
                target, including a fresh Watcher key when required, or None
                for the unchanged saved API-key connection.

        Returns:
            bool: True once this owner accepts responsibility for preparation.
        """
        record = self._resume_continuation
        generation = getattr(self, '_update_resume_generation', 0)
        lease = self.flights.lease
        staged_target = getattr(self, '_update_resume_context_target', None)
        if (record is not None and record.contextual
                and isinstance(staged_target, LinkedUploadTarget)):
            # Never replace a key whose remote close has not been confirmed.
            self._close_update_resume_context_key()
            self.status_var.set(
                'Closing previous Watcher access. Try again after it finishes.',
            )
            return False
        if (record is None or lease is None or self.exiting
                or self.worker is not None and self.worker.is_alive()):
            return False
        try:
            lease.verify()
            current = WatcherContinuation.load(lease)
            if (current is None or current.session_id != record.session_id
                    or current.binding != record.binding
                    or current.profile_hash != record.profile_hash
                    or current.source_version != record.source_version
                    or current.target_version != record.target_version
                    or (current.update_session
                        and current.update_session != record.update_session)
                    or current.mode != record.mode
                    or current.contextual != record.contextual
                    or current.created != record.created
                    or current.phase not in ('preparing', 'prepared', 'claimed')):
                raise ValueError('The update continuation changed.')
            if record.binding != self.flights.binding():
                raise ValueError('The continuation target changed.')
            if record.mode == 'linked':
                mode_record = self.mode_owner.view().record
                if (mode_record is None
                        or mode_record.scope != record.approved_scope
                        or target is None
                        or target.session.approved_scope() != record.approved_scope):
                    raise ValueError('The original linked scope changed.')
            plan = SessionPlan.load(self.flights.directory / record.session_id)
            if (plan.binding != record.binding or plan.completed or plan.abandoned
                    or plan.local_only != (record.mode == 'linked')
                    or (target is not None and target.binding() != record.binding)
                    or record.contextual and (
                        target is None or target.watch_key is None
                    )):
                raise ValueError('The accepted session could not be verified.')
            claimed = replace(record, phase='claimed')
            if getattr(self, '_ordinary_restart_resuming', False) is True:
                ordinary = WatcherContinuation.load(
                    lease, ordinary_restart=True,
                )
                if (ordinary is None
                        or ordinary.phase not in ('prepared', 'claimed')
                        or replace(ordinary, phase=current.phase) != current):
                    raise ValueError('The ordinary restart consent changed.')
                claimed.save(lease, ordinary_restart=True)
            claimed.save(lease)
            self._resume_continuation = claimed
            config = capture_worker_config(self.config)
            port = self.config_store.mode_port(config)
        except (OSError, ValueError, RuntimeError):
            self._update_resume_failed('session_claim_unverified')
            return False
        self._update_resume_preparing = True
        self._set_watch_start_activity(True, 'Reading the saved Watcher session…')

        if record.contextual:
            # The LinkingManager callback returns True to transfer this exact
            # key. Record that ownership before any asynchronous preparation.
            self._update_resume_context_target = target
            self._update_resume_context_key_closing = False

        def prepare() -> None:
            """Restore immutable history while leaving the GUI responsive.

            Returns:
                None: Queues idle verified workers or a safe retained-work error.
            """
            worker = None
            operation = 'read_watch_drain'
            try:
                row = read_document(plan.directory / 'watch-drain.json')
                folder, recursive = row.get('folder'), row.get('recursive')
                if not isinstance(folder, str) or not isinstance(recursive, bool):
                    raise ValueError('The original folder could not be verified.')
                operation = 'create_watcher'
                worker = WatcherThread(
                    config, self.events, target,
                    gui_admission=(self.mode_owner, port),
                    watch_folder_override=Path(folder),
                    watch_recursive_override=recursive,
                )
                operation = 'restore_watch_drain'
                worker.configure_drain(
                    plan.directory, (), resume=True, continuation=True,
                )
                identity = (
                    target.issuer + ' #' + str(target.gallery_id)
                    if target is not None else normalize_upload_url(config.gallery_url)
                )
                operation = 'restore_flight_recorder'
                recorder = FlightRecorder.from_session(
                    plan.directory, identity, config.simconnect_dll_path,
                    lambda level, message: self.events.put((level, message)),
                )
                self.events.put({
                    'kind': 'watcher_resume_prepared',
                    'payload': {
                        'continuation': claimed, 'worker': worker,
                        'recorder': recorder, 'target': target,
                        'generation': generation,
                    },
                })
            except Exception as error:
                if worker is not None and worker._linked_lease is not None:
                    worker._linked_lease.release()
                error_type = type(error).__name__
                if (not error_type.isidentifier()
                        or len(error_type) > UPDATE_RESUME_EXCEPTION_CLASS_MAX_CHARS):
                    error_type = 'Exception'
                self.events.put((
                    'warning',
                    'Watcher resume preparation failed; '
                    'stage=resume_prepare operation='
                    f'{operation} error_type={error_type}.',
                ))
                self.events.put({
                    'kind': 'watcher_resume_failed',
                    'payload': {
                        'reason': 'saved_session_needs_review',
                        'stage': operation,
                        'generation': generation,
                    },
                })

        try:
            threading.Thread(
                target=prepare, name='PHPGalleryUpdateResumePrepare', daemon=True,
            ).start()
        except RuntimeError:
            self._update_resume_preparing = False
            if record.contextual:
                self._update_resume_context_target = None
            self._update_resume_failed('saved_session_needs_review')
            return False
        return True

    def _accept_update_resume_target(self, target: LinkedUploadTarget) -> None:
        """Activate only the freshly verified original linked destination.

        Args:
            target (LinkedUploadTarget): Existing authority reverified off Tk.

        Returns:
            None: Requests fresh contextual access or prepares ordinary linked
                capture; an unavailable authority leaves the queue retained.
        """
        record = self._resume_continuation
        self._update_resume_preparing = False
        if record is None or record.binding != target.binding() or self.exiting:
            return
        if target.session.approved_scope() != record.approved_scope:
            self._update_resume_failed('saved_scope_changed')
            return
        if record.contextual:
            if isinstance(
                getattr(self, '_update_resume_context_target', None),
                LinkedUploadTarget,
            ):
                self._close_update_resume_context_key()
                self._resume_retry_available = True
                self._update_resume_status = (
                    'stage=resume; reason=saved_watcher_key_close_pending'
                )
                self.status_var.set(
                    'Previous Watcher access is still being closed. Wait for '
                    'confirmation, then resume Watcher.',
                )
                self.refresh_watcher_action()
                return
            # The existing Watcher owner independently rechecks this ID, account
            # and scope. Ancestry is irrelevant to its exact-target verification.
            gallery = LinkedGallery(target.gallery_id, None, target.title)
            self.linking_manager.start_photos_watcher(
                target.session, gallery, self._prepare_update_resume,
                lambda _message: self._update_resume_failed(
                    'saved_watcher_access_unavailable',
                ),
            )
            return
        if not self.linking_manager.activate_verified_resume_target(
            target, record.binding, record.approved_scope,
        ):
            self._update_resume_failed('saved_gallery_mode_unverified')
            return
        self._prepare_update_resume(target)

    def _start_prepared_update_resume(
        self, record: WatcherContinuation, worker: WatcherThread,
        recorder: FlightRecorder, target: Optional[LinkedUploadTarget],
    ) -> None:
        """Adopt prepared idle workers after rechecking the exact continuation.

        Args:
            record (WatcherContinuation): Exact claimed update checkpoint.
            worker (WatcherThread): Prepared idle queue owner.
            recorder (FlightRecorder): Prepared idle recorder for the same plan.
            target (Optional[LinkedUploadTarget]): Fresh original linked
                authority, or None for the original saved API key.

        Returns:
            None: Starts the same plan or releases prepared ownership on failure.
        """
        valid = record == self._resume_continuation and not self.exiting
        if not valid:
            if worker._linked_lease is not None:
                worker._linked_lease.release()
            if target is getattr(self, '_update_resume_context_target', None):
                self._close_update_resume_context_key()
            return
        try:
            lease = self.flights.lease
            if lease is None or WatcherContinuation.load(lease) != record:
                raise ValueError('The claimed continuation changed.')
            if self.flights.binding() != record.binding:
                raise ValueError('The continuation target changed.')
            plan = SessionPlan.load(self.flights.directory / record.session_id)
            self.worker = worker
            self.flights.resume_session(plan, recorder=recorder)
            self.watched_folder_var.set(str(worker._watch_folder_override))
            self.watch_recursive_var.set(bool(worker._watch_recursive_override))
            worker.start()
            self.refresh_utc_sync()
            self.watcher_activity_var.set('Watch: resuming')
            self._update_resume_status = 'stage=resume; reason=waiting_for_worker'
            self.refresh_connection_controls()
        except Exception:
            self.flights.stop()
            worker.stop()
            if not worker.is_alive() and worker._linked_lease is not None:
                worker._linked_lease.release()
            self._update_resume_failed('prepared_session_changed')

    def _complete_update_resume(self) -> bool:
        """Consume the one-use checkpoint only when its worker reaches REC.

        Returns:
            bool: True for ordinary startup or a durably consumed continuation;
                False pauses an accepted worker whose checkpoint cannot be saved.
        """
        record = self._resume_continuation
        if record is None:
            return True
        lease = self.flights.lease
        if (self.worker is None or self.worker.stop_event.is_set()
                or self.worker.drain_event.is_set()
                or self.worker._update_pause_requested()):
            if self.worker is not None:
                self.worker.request_update_pause()
            self.flights.stop()
            reason = (
                'saved_session_needs_review'
                if self.worker is not None and self.worker.drain_error
                else 'continuation_worker_stopped'
            )
            self._update_resume_failed(reason)
            return False
        if record.contextual and (
            self._update_resume_context_target is None
            or self.worker.linked_target is not self._update_resume_context_target
            or self._update_resume_context_key_closing
        ):
            self.worker.request_update_pause()
            self.flights.stop()
            self._update_resume_failed('saved_watcher_access_unavailable')
            return False
        consumed = replace(record, phase='consumed')
        try:
            if lease is None or WatcherContinuation.load(lease) != record:
                raise ValueError('The continuation checkpoint changed.')
            if getattr(self, '_ordinary_restart_resuming', False) is True:
                ordinary = WatcherContinuation.load(
                    lease, ordinary_restart=True,
                )
                if (ordinary is None
                        or ordinary.phase not in ('prepared', 'claimed')
                        or replace(ordinary, phase=record.phase) != record):
                    raise ValueError('The ordinary restart consent changed.')
                consumed.save(lease, ordinary_restart=True)
            consumed.save(lease)
        except (OSError, ValueError, RuntimeError):
            if self.worker is not None:
                self.worker.request_update_pause()
            self.flights.stop()
            if getattr(self, '_ordinary_restart_resuming', False) is True:
                self._restore_unadmitted_continuation(record, consumed)
            self._update_resume_failed('continuation_consumption_unverified')
            return False
        if self.worker is None or not self.worker.admit_continuation():
            # The worker's gate has not opened, so restore the exact claimed
            # checkpoint. This makes a Stop or timeout during the durable save
            # retryable without risking a duplicate dispatch.
            if not self._restore_unadmitted_continuation(record, consumed):
                self.flights.stop()
                self._update_resume_failed('continuation_consumption_unverified')
                return False
            self.flights.stop()
            reason = (
                'saved_session_needs_review'
                if self.worker is not None and self.worker.drain_error
                else 'continuation_worker_stopped'
            )
            self._update_resume_failed(reason)
            return False
        self._resume_continuation = None
        self._ordinary_restart_resuming = False
        if record.contextual:
            self._contextual_watch_target = self._update_resume_context_target
            plan = getattr(self.flights, 'plan', None)
            self._contextual_watch_is_flight = bool(
                plan is not None and plan.reports_enabled
            )
            self._update_resume_context_target = None
            self._update_resume_context_key_closing = False
        self._update_resume_preparing = False
        self._resume_retry_available = False
        self._update_resume_status = 'stage=resume; reason=watcher_running'
        self.write_log('Watcher resumed using its original session.', 'success')
        return True

    def _restore_unadmitted_continuation(
        self, record: WatcherContinuation, consumed: WatcherContinuation,
    ) -> bool:
        """Reconcile an owned checkpoint pair before any resumed dispatch.

        Args:
            record (WatcherContinuation): Exact claimed state before consumption.
            consumed (WatcherContinuation): Same owned state marked consumed.
        Returns:
            bool: True after the unchanged records are verified as claimed;
                False for changed, cancelled or unreadable state. Callers keep
                the worker gate closed, so this never reverses an admitted run.
        """
        try:
            lease = self.flights.lease
            if lease is None:
                return False
            lease.verify()
            current = WatcherContinuation.load(lease)
            if current not in (record, consumed):
                return False
            ordinary = None
            if getattr(self, '_ordinary_restart_resuming', False) is True:
                ordinary = WatcherContinuation.load(lease, ordinary_restart=True)
                if ordinary not in (record, consumed):
                    return False
            # A failed readback may follow an already-committed local replace.
            # Restore only exact snapshots owned by this still-closed gate.
            if current != record:
                record.save(lease)
            if ordinary is not None and ordinary != record:
                record.save(lease, ordinary_restart=True)
            return True
        except (OSError, ValueError, RuntimeError):
            return False

    def _save_owned_config(self, config: WatcherConfig, *,
                           first_setup: bool = False,
                           completed_work: Optional[CoordinatedWork] = None) -> WatcherConfig:
        """Save one host configuration under exclusive mode admission.

        Args:
            config (WatcherConfig): Proposed complete settings with owner token.
            first_setup (bool): True only for an explicit first settings save.
            completed_work (Optional[CoordinatedWork]): Exact server-confirmed
                revoke lifetime to exchange atomically for this config save.

        Returns:
            WatcherConfig: The accepted host object, retaining no-op identity.

        Raises:
            ModeCoordinatorError: Active work or uncertain mode blocks saving.
            OSError: The original config owner cannot persist the settings.
        """
        if not first_setup and config.to_dict() == self.config.to_dict():
            return self.config

        def save() -> _ConfigModePort:
            """Write only through the current ConfigStore and return its port.

            Returns:
                _ConfigModePort: Mode port bound to the saved config object.
            """
            self.config_store.save(config)
            return self.config_store.mode_port(config)

        options = {'completed_work': completed_work} if completed_work is not None else {}
        self.mode_owner.save(save, first_setup=first_setup, **options)
        return config

    def capture_flight_admission(self) -> Tuple[GuiModeOwner, ModeConfigPort, str, str]:
        """Capture the exact saved legacy connection for a flight worker.

        Returns:
            Tuple[GuiModeOwner, ModeConfigPort, str, str]: GUI owner, port
                bound to sealed settings, canonical endpoint and private API
                key. The caller passes these directly to the worker only.

        Raises:
            ValueError: The saved settings cannot be captured safely.
            OSError: The ConfigStore path cannot be verified.
        """
        captured = capture_worker_config(self.config)
        port = self.config_store.mode_port(captured)
        return (self.mode_owner, port, normalize_upload_url(captured.gallery_url),
                captured.api_key)

    def connection_change_allowed(self, config: WatcherConfig) -> bool:
        """Preserve captured connection ownership while accepted work is pending.

        Args:
            config (WatcherConfig): Proposed saved settings from current UI fields.

        Returns:
            bool: True for the same connection or an idle resolved connection.
        """
        if (
            normalize_upload_url(config.gallery_url)
            == normalize_upload_url(self.config.gallery_url)
            and config.api_key == self.config.api_key
        ):
            return True
        try:
            unresolved = bool(self.flights.pending())
            proposed_binding = connection_binding(
                normalize_upload_url(config.gallery_url), config.api_key,
            )
            with accepted_work_owner(CONFIG_DIR):
                # Every accepted job and binding must be reloaded strictly
                # after ownership. An orphan or damaged row blocks edits even
                # when its old binding is not the currently displayed key.
                bindings = self.flights.import_bindings.entries()
                if not self.job_store.admitted_imports_match_target(
                    bindings, proposed_binding,
                ):
                    unresolved = True
        except (OSError, ValueError, RuntimeError):
            unresolved = True
        if self.background_work_active() or unresolved or self.flights.is_alive():
            messagebox.showwarning(
                "Connection",
                "Finish or explicitly resolve current work before replacing "
                "its gallery connection.",
            )
            return False
        return True

    def save_startup_preference(self) -> None:
        """Save the startup switch without admitting unrelated unsaved fields.

        Returns:
            None: Preserves all other saved settings; a blocked/failed save
                restores the prior switch and a registry failure is displayed.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            self.start_with_windows_var.set(self.config.start_with_windows)
            return
        if self.api_key_revoke_running:
            self.start_with_windows_var.set(self.config.start_with_windows)
            return
        config = replace(
            self.config, start_with_windows=self.start_with_windows_var.get(),
        )
        try:
            config = self._save_owned_config(config)
        except (OSError, ModeCoordinatorError) as exc:
            self.start_with_windows_var.set(self.config.start_with_windows)
            messagebox.showerror("Configuration error", str(exc), parent=self.root)
            return
        self.config = config
        self.apply_startup_registration()

    def apply_startup_registration(self) -> None:
        """Apply the saved preference and expose registry errors for retry.

        Returns:
            None: Keeps saved preferences after a registry failure and reports
                the failure; saving again retries the same desired state.
        """
        try:
            synchronize_startup(self.config.start_with_windows)
        except OSError as exc:
            self.write_log(
                "Windows startup registration failed: " + str(exc), "warning",
            )
            messagebox.showwarning(
                "Windows startup",
                "Settings were saved, but Windows startup could not be "
                "updated. Save settings again to retry.\n\n" + str(exc),
                parent=self.root,
            )

    def save_config(self) -> bool:
        """
        Persist current settings to the local config file.

        Returns:
            bool: True when the configuration was saved.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return False
        if self.api_key_revoke_running:
            messagebox.showwarning("Configuration", "Wait for API-key revocation to finish before changing connection credentials.")
            return False
        try:
            config = self.current_config()
            if not self.connection_change_allowed(config):
                return False
            config = self._save_owned_config(config, first_setup=True)
            self.config = config
            self.write_log("Configuration saved.", "success")
            self.apply_startup_registration()
            self.refresh_connection_controls()
            return True
        except Exception as exc:  # noqa: BLE001
            messagebox.showerror("Configuration error", str(exc))
            return False

    def save_and_test_connection(self) -> None:
        """Save the Settings connection fields and test authentication.

        Returns:
            None: Saves valid values before starting the existing test worker.
        """
        if self.save_config():
            self.test_connection()

    def simbrief_available(self) -> bool:
        """Check whether the current game supports the shared SimBrief controls.

        Returns:
            bool: True when the existing game observer reports a flight simulator.
        """
        return self.simulator_presence.flight_detected

    def contextual_watcher_is_flight(self) -> bool:
        """Latch observed simulator evidence for the current contextual capture.

        Returns:
            bool: True after simulator detection or any retained flight sample,
                including after the simulator closes.
        """
        if self._contextual_watch_is_flight:
            return True
        if self.simbrief_available():
            self._contextual_watch_is_flight = True
        else:
            recorder = self.flights.recorder
            samples = (
                recorder.diagnostics_snapshot().get("sample_count")
                if recorder is not None else None
            )
            self._contextual_watch_is_flight = type(samples) is int and samples > 0
        return self._contextual_watch_is_flight

    def _set_watch_start_activity(self, active: bool, message: str = "") -> None:
        """Paint explicit startup feedback without admitting screenshot work.

        Args:
            active (bool): Whether linking or the initial folder scan is pending.
            message (str): Current preparation phase, or empty to retain status.

        Returns:
            None: Shows activity and prevents repeated Start actions until ready.
        """
        self._watch_start_pending = active
        if message:
            self.status_var.set(message)
        progress = getattr(self, "watch_start_progress", None)
        if progress is not None:
            if active:
                progress.grid()
                progress.start(WATCHER_START_ACTIVITY_MS)
            else:
                progress.stop()
                progress.grid_remove()
        button = getattr(self, "watcher_action_button", None)
        if button is not None:
            button.configure(
                text="Starting Watcher…" if active else "▶  Start Watcher",
                state="disabled" if active else "normal",
            )
        self.refresh_watcher_header()
        if active:
            # Paint the phase before synchronous local ownership checks. The
            # potentially long folder scan itself runs on the watcher thread.
            self.root.update_idletasks()

    def _watch_start_failed(self, message: str) -> None:
        """End the original Photos startup request with its safe refusal.

        Args:
            message (str): Fixed user-facing refusal supplied by the link owner.

        Returns:
            None: Restores the idle action and leaves the reason in Watcher.
        """
        self._set_watch_start_activity(False, message)

    def open_gallery_watcher(
        self, session: LinkingSession, gallery: LinkedGallery,
    ) -> None:
        """Start Watcher for the selected gallery while retaining the Photos view.

        Args:
            session (LinkingSession): Current explicitly approved Photos link.
            gallery (LinkedGallery): Physical gallery selected for screenshots.

        Returns:
            None: Requests temporary Watcher authority without changing tabs;
                existing progress and recovery actions retain their behavior.
        """
        if self.updates.blocked() or getattr(self, "_watch_start_pending", False) is True:
            return
        self._set_watch_start_activity(True, "Checking the Watcher connection…")
        state = self.flights.session_state()
        if state == "working":
            self._set_watch_start_activity(False)
            self.flights.show_progress()
            return
        if state in ("pending", "blocked"):
            self._set_watch_start_activity(False)
            self.flights.show_recovery()
            return
        if state != "idle":
            self._set_watch_start_activity(False)
            return
        if self._contextual_watch_target is not None:
            self._set_watch_start_activity(False)
            self.release_contextual_watcher()
            self.status_var.set("Closing previous Watcher access. Try again when it finishes.")
            return
        folder = self.watched_folder_var.get().strip()
        try:
            ready = bool(folder) and Path(folder).is_dir()
        except OSError:
            ready = False
        if not ready:
            self.browse_folder()
            folder = self.watched_folder_var.get().strip()
            try:
                if not folder or not Path(folder).is_dir():
                    self._set_watch_start_activity(False)
                    return
            except OSError:
                self._set_watch_start_activity(False)
                return
        self.status_var.set("Preparing the Watcher connection…")
        self.linking_manager.start_photos_watcher(
            session, gallery, self._start_contextual_watcher,
            self._watch_start_failed,
        )

    def _start_contextual_watcher(self, target: LinkedUploadTarget) -> bool:
        """Start the existing Watcher with one freshly issued temporary key.

        Args:
            target (LinkedUploadTarget): Verified gallery and in-memory Watcher key.

        Returns:
            bool: True when the accepted capture owns the temporary credential.
        """
        try:
            self.start(contextual_target=target)
        except Exception:  # noqa: BLE001
            self._watch_start_failed("Watcher could not start.")
        return self._contextual_watch_target is target

    def release_contextual_watcher(self) -> None:
        """Revoke one temporary key after the existing finalization completed.

        Returns:
            None: Starts bounded cleanup or preserves unfinished capture authority.
        """
        target = getattr(self, "_contextual_watch_target", None)
        if (not isinstance(target, LinkedUploadTarget) or target.watch_key is None
                or self._watcher_key_closing
                or self.flights.session_state() != "idle"):
            return
        key = target.watch_key
        self._watcher_key_closing = True

        def revoke() -> None:
            """Revoke the exact captured credential off the Tk thread.

            Returns:
                None: Queues a confirmation or retryable failure without the key text.
            """
            try:
                target.session.close_watch_key(target.gallery_id, key)
                closed = True
            except Exception:  # noqa: BLE001
                closed = False
            self.events.put({
                "kind": "photos_watch_key_closed",
                "payload": {"target": target, "closed": closed},
            })

        try:
            threading.Thread(target=revoke, name="PhotosWatcherKeyClose", daemon=True).start()
        except RuntimeError:
            self._watcher_key_closing = False
        self.refresh_watcher_action()

    def start(self, *, contextual_target: LinkedUploadTarget | None = None) -> None:
        """Show startup activity before preparing the shared Watcher worker.

        Args:
            contextual_target (LinkedUploadTarget | None): Exact temporary
                Photos authority, or None for ordinary Watcher startup.

        Returns:
            None: Accepts startup once; REC waits for the initial folder boundary.
        """
        if getattr(self, '_ordinary_restart_closing', False) is True:
            return
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if (getattr(self, "_watch_start_pending", False) is True
                and contextual_target is None):
            return
        if self.worker is not None and self.worker.is_alive():
            self.write_log("Watcher is already running.")
            return
        self._set_watch_start_activity(True, "Checking the Watcher settings…")
        try:
            WatcherApp._start_watcher(self, contextual_target=contextual_target)
        finally:
            if self.worker is None or not self.worker.is_alive():
                self._set_watch_start_activity(False)

    def _start_watcher(self, *, contextual_target: LinkedUploadTarget | None = None) -> None:
        """
        Start the watcher worker using the current form values.

        Files already present at start are intentionally counted and ignored.

        Args:
            contextual_target (LinkedUploadTarget | None): Verified ephemeral
                authority for this Photos capture, or None for ordinary Watcher.

        Returns:
            None: Starts watching and admits UTC work only when selected.
        """
        if getattr(self, '_ordinary_restart_closing', False) is True:
            return
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.api_key_revoke_running:
            self.flights.show_progress()
            return
        if self.worker and self.worker.is_alive():
            self.write_log("Watcher is already running.")
            return

        selected = self.watched_folder_var.get().strip()
        folder = Path(selected)
        try:
            has_folder = bool(selected) and folder.is_dir()
        except OSError:
            has_folder = False
        if not has_folder:
            messagebox.showinfo(
                "Watch folder", "Choose a folder to watch before starting.",
                parent=self.root,
            )
            return
        if not self.flights.can_start():
            return

        try:
            linked_target = self.capture_linked_target()
            if contextual_target is not None:
                if (linked_target is None or contextual_target.watch_key is None
                        or linked_target.session is not contextual_target.session
                        or linked_target.binding() != contextual_target.binding()):
                    raise ValueError('The Watcher gallery changed before capture.')
                linked_target = contextual_target
            elif isinstance(getattr(self, "_contextual_watch_target", None),
                            LinkedUploadTarget):
                self.release_contextual_watcher()
                return
            config = (self.current_watcher_config() if linked_target is not None
                      else self.current_config())
            if linked_target is not None and (
                (self.manual_worker and self.manual_worker.is_alive())
                or (self.preflight_worker and self.preflight_worker.is_alive())
            ):
                raise ValueError('Wait for the linked import or preflight to finish first.')
            if not self.connection_change_allowed(config):
                return
            config = self._save_owned_config(config)
            self.config = config
        except Exception as exc:  # noqa: BLE001
            messagebox.showerror("Configuration error", str(exc))
            return

        if (linked_target is None
                and (not normalize_upload_url(config.gallery_url) or not config.api_key.strip())):
            messagebox.showerror("Connection", "Gallery URL and API key are required before starting the watcher.")
            return

        self._set_watch_start_activity(True, "Reading existing screenshots…")
        self.watch_ignored_var.set("Ignored at startup: checking existing files…")
        self.watch_last_scan_var.set("Last scan: preparing")
        self.utc_sync.stop()
        try:
            captured = capture_worker_config(config)
            config_port = self.config_store.mode_port(captured)
            self.worker = WatcherThread(
                captured, self.events, linked_target,
                gui_admission=(self.mode_owner, config_port),
            )
            contextual_flight = contextual_target is not None and self.simbrief_available()
            directory = (
                self.flights.start_session(photos_only=True)
                if contextual_target is not None and not contextual_flight
                else self.flights.start_session()
            )
            self.worker.configure_drain(directory, None)
            self.worker.start()
        except (OSError, ValueError, RuntimeError) as exc:
            if self.worker is not None and self.worker._linked_lease is not None:
                self.worker._linked_lease.release()
            self.flights.stop()
            self._watch_start_failed("Watcher could not prepare its local queue. Check the error and retry.")
            messagebox.showerror("Watcher state", str(exc))
            self.worker = None
            return
        if contextual_target is not None:
            self._contextual_watch_target = contextual_target
            self._contextual_watch_is_flight = contextual_flight
        self.refresh_utc_sync()
        self.watcher_activity_var.set("Watch: starting")
        self.write_log("Watcher preparation started.", "system")
        self.refresh_activity_monitor_state()
        self.refresh_revoke_button_state()

    def stop(self) -> None:
        """Stop observation and finish accepted photographs and flight reports.

        Returns:
            None: Routes ordinary and contextual captures through shared drain.
        """
        if getattr(self, '_ordinary_restart_closing', False) is True:
            return
        self._cancel_ordinary_restart_for_action()
        self._resume_retry_available = False
        self._set_watcher_recording(False)
        if not isinstance(getattr(self, "_contextual_watch_target", None), LinkedUploadTarget):
            self.flights.begin(False)
        elif self.contextual_watcher_is_flight():
            self.flights.finish_report()
        else:
            self.flights.begin(False, photos_only=True)

    def _cancel_ordinary_restart_intent(self) -> None:
        """Revoke ordinary restart consent without changing retained queue data.

        Returns:
            None: Marks only this profile's alternate restart record cancelled.
        """
        # Like finalization admission, this bounded local metadata cancellation
        # completes before returning to Tk: a queued resume callback must never
        # observe still-active consent after the user has pressed Stop.
        try:
            lease = self.flights.lease
            if lease is None:
                return
            record = WatcherContinuation.load(lease, ordinary_restart=True)
            if record is not None and record.phase in (
                    'preparing', 'prepared', 'claimed'):
                replace(record, phase='cancelled').save(
                    lease, ordinary_restart=True,
                )
        except (OSError, ValueError, RuntimeError):
            self.write_log(
                '[watcher-resume] stage=stop; reason=ordinary_consent_cancel_failed',
                'warning',
            )

    def _cancel_ordinary_restart_for_action(self) -> None:
        """Cancel both restart records before an explicit Stop, Finish or unlink.

        Returns:
            None: Revokes ordinary consent while leaving session data intact.
        """
        self._update_resume_cancelled = True
        self._update_resume_generation += 1
        self._discard_update_resume_intent(explicit=True)
        self._resume_continuation = None
        self._resume_retry_available = False
        try:
            lease = self.flights.lease
            ordinary = (WatcherContinuation.load(lease, ordinary_restart=True)
                        if lease is not None else None)
        except (OSError, ValueError, RuntimeError):
            ordinary = None
        if ordinary is not None and ordinary.phase in (
                'preparing', 'prepared', 'claimed'):
            self._discard_update_resume_intent(explicit=True)
        self._cancel_ordinary_restart_intent()

    def resume_flight_photos(self, directory: Path) -> Optional[WatcherThread]:
        """Resume only unfinished photographs from the bound session queue.

        Args:
            directory (Path): Owned, connection-matching flight session folder.

        Returns:
            Optional[WatcherThread]: Started drain worker, or None for no queue.

        Raises:
            ValueError: A durable queue cannot be interpreted safely.
        """
        path = directory / "watch-drain.json"
        if not path.exists():
            return None
        row = read_document(path)
        pending, frozen = row.get("pending"), row.get("frozen")
        if not isinstance(pending, list) or not isinstance(frozen, bool):
            raise ValueError("Pending photograph queue is malformed.")
        if not pending and frozen:
            return None
        # A persisted Stop can precede the final scan while a new screenshot
        # is still being copied. Reconcile that cutoff even with no queued path.
        config = capture_worker_config(self.config)
        config_port = self.config_store.mode_port(config)
        folder, recursive = row.get("folder"), row.get("recursive")
        if not isinstance(folder, str) or not isinstance(recursive, bool):
            raise ValueError("The original Watcher folder could not be verified.")
        drain_folder = Path(folder)
        if not drain_folder.is_absolute():
            raise ValueError("The original Watcher folder could not be verified.")
        linked_target = self.capture_linked_target()
        contextual_target = getattr(self, "_contextual_watch_target", None)
        if isinstance(contextual_target, LinkedUploadTarget):
            if (linked_target is None or contextual_target.session is not linked_target.session
                    or contextual_target.binding() != linked_target.binding()):
                raise ValueError('The accepted Watcher gallery changed before recovery.')
            linked_target = contextual_target
        # Resume only a queue admitted under the current exact connection.
        if not any(plan.directory.resolve() == directory.resolve()
                   for plan in self.flights.pending()):
            raise ValueError('Pending photographs belong to another gallery connection.')
        worker = WatcherThread(
            config, self.events, linked_target,
            gui_admission=(self.mode_owner, config_port),
            watch_folder_override=drain_folder,
            watch_recursive_override=recursive,
        )
        try:
            worker.configure_drain(directory, (), resume=True)
            worker.start()
        except Exception:
            if worker._linked_lease is not None:
                worker._linked_lease.release()
            raise
        return worker

    def emit_utc_activity(self, level: str, message: str) -> None:
        """Queue UTC activity without accessing Tk from a native worker.

        Args:
            level (str): Existing activity severity, info or warning.
            message (str): Bounded clock state/correction diagnostic.

        Returns:
            None: Sends the message to the existing log/activity consumer.
        """
        self.events.put((level, message))
        getattr(logging, level)(message)

    def toggle_utc_sync(self) -> None:
        """Persist just the checkbox and apply it to the active watcher.

        Returns:
            None: Keeps unsaved connection/settings edits out of persistence.
        """
        if self.exiting or self.updates.blocked():
            self.sync_utc_time_var.set(self.config.sync_utc_time)
            return
        enabled = self.sync_utc_time_var.get()
        # Revoke writes immediately, before even attempting a disk save.
        if not enabled:
            self.utc_sync.stop()
        config = replace(self.config, sync_utc_time=enabled)
        try:
            config = self._save_owned_config(config)
        except (OSError, ModeCoordinatorError) as exc:
            self.sync_utc_time_var.set(self.config.sync_utc_time)
            self.refresh_utc_sync()
            messagebox.showerror("UTC synchronization", str(exc))
            return
        self.config = config
        self.refresh_utc_sync()

    def refresh_utc_sync(self) -> None:
        """Bind UTC admission to this exact watcher, including tray starts.

        Returns:
            None: Starts selected work or cancels all prior UTC admission.
        """
        worker = self.worker
        if (
            self.config.sync_utc_time and not self.exiting
            and worker is not None and worker.is_alive()
            and not worker.stop_event.is_set()
        ):
            self.utc_sync.start(
                worker.config.simconnect_dll_path,
                lambda: worker.is_alive() and not worker.stop_event.is_set(),
            )
        else:
            self.utc_sync.stop()
        self.refresh_contextual_watcher_copy()

    def refresh_contextual_watcher_copy(self) -> None:
        """Hide simulator-only controls outside a detected flight context.

        Returns:
            None: The UTC preference remains saved while its row and pending
                simulator status are hidden for ordinary games.
        """
        flight_detected = self.simulator_presence.flight_detected
        self.flights.refresh_contextual_labels()
        if flight_detected:
            self.watcher_utc_row.grid()
        else:
            self.watcher_utc_row.grid_remove()
        status = self.utc_sync.status()
        if (not flight_detected
                and status.startswith("UTC sync awaiting simulator:")):
            status = ""
        if self.utc_sync_status_var.get() != status:
            self.utc_sync_status_var.set(status)

    def start_manual_upload(self) -> None:
        """
        Start the explicitly reviewed import plan.
        

        Returns:
            None: Applies this action only after lifecycle admission succeeds.
        """
        if getattr(self, '_ordinary_restart_closing', False) is True:
            return
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.api_key_revoke_running:
            messagebox.showwarning("Connection", "Wait for API-key revocation to finish before starting manual import.")
            return
        if self.manual_worker and self.manual_worker.is_alive():
            self.write_log("Manual import is already running.")
            return
        if self.preflight_worker and self.preflight_worker.is_alive():
            messagebox.showwarning("Import", "Preflight is still running. Review the result before starting the import.")
            return
        if self.current_job is None:
            messagebox.showwarning("Import", "Choose a source and complete preflight first.")
            return

        try:
            config = self.current_config()
            linked_target = self.capture_linked_target()
            if not self.connection_change_allowed(config):
                return
            config = self._save_owned_config(config)
            self.config = config
        except Exception as exc:  # noqa: BLE001
            messagebox.showerror("Configuration error", str(exc))
            return
        if (linked_target is None
                and (not normalize_upload_url(config.gallery_url) or not config.api_key.strip())):
            messagebox.showerror("Connection", "Gallery URL and API key are required before starting an import.")
            return

        paths = [
            Path(item.path)
            for item in self.current_job.items
            if item.state in RECOVERABLE_ITEM_STATES and Path(item.path).is_file()
        ]
        if not paths:
            messagebox.showinfo("Import", "This job has no queued or recoverable files to upload.")
            self.render_preflight_plan(self.manual_plan) if self.manual_plan is not None else None
            return
        self.launch_manual_job(self.current_job, paths, config, linked_target)

    def stop_manual_upload(self) -> None:
        """
        Request cancellation of the current manual import.
        """
        if self.manual_worker and self.manual_worker.is_alive():
            self.manual_worker.stop()
            self.manual_status_var.set("Cancelling after in-flight requests")
            self.import_activity_var.set("Import: cancelling")
            self.cancel_import_button.configure(state="disabled")
            self.write_log("Manual import cancellation requested.", "warning")
            self.record_activity("warning", "Manual import cancellation requested.", "import", job_id=self.current_job.id if self.current_job else "")
        self.refresh_revoke_button_state()

    def repair_semantic_ai_dependencies(self) -> None:
        """
        Install optional in-process AI packages without freezing the tray UI.

        PyTorch and Transformers are large packages and may also cause model
        downloads later. Installation is therefore explicit and confirmed.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if getattr(sys, "frozen", False):
            _ok, output = install_semantic_ai_dependencies_for_current_runtime()
            messagebox.showinfo("Local AI module", output)
            return
        if self.semantic_ai_install_running:
            self.write_log("Optional local AI module dependency installation is already running.")
            return
        if not messagebox.askyesno(
            "Install local AI module",
            "This installs large optional Python packages such as PyTorch and Transformers into the current Python runtime. "
            "Using those backends can later download large model files. Continue?",
        ):
            return

        self.semantic_ai_install_running = True
        if self.semantic_ai_install_button is not None:
            self.semantic_ai_install_button.configure(state="disabled")
        self.write_log("Installing optional local AI module dependencies into the current Python runtime...")

        def installer() -> None:
            """Run pip installation away from the Tkinter event loop."""
            ok, output = install_semantic_ai_dependencies_for_current_runtime()
            safe_output = output.strip() if output else "pip produced no output"
            if ok:
                self.events.put(("info", "Optional local AI module dependency installation finished successfully. Restart the app before running the Transformers backend for the first time."))
            else:
                self.events.put(("error", "Optional local AI module dependency installation finished with errors: " + safe_output[:4000]))

        threading.Thread(target=installer, daemon=True).start()

    def start_ai_worker(self) -> None:
        """
        Start the optional AI metadata worker using current shared connection fields.
        

        Returns:
            None: Applies this action only after lifecycle admission succeeds.
        """
        if getattr(self, '_ordinary_restart_closing', False) is True:
            return
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.linking_manager.linked_upload_mode_selected():
            messagebox.showwarning(
                "AI metadata worker",
                "Linked gallery upload does not grant AI access. Switch "
                "explicitly to the saved API-key connection to use this worker.",
            )
            return
        if self.api_key_revoke_running:
            messagebox.showwarning("Connection", "Wait for API-key revocation to finish before starting the AI metadata worker.")
            return
        if self.ai_worker and self.ai_worker.is_alive():
            self.write_log("AI metadata worker is already running.")
            return

        try:
            config = self.current_config()
            if not self.connection_change_allowed(config):
                return
            config = self._save_owned_config(config)
            self.config = config
        except Exception as exc:  # noqa: BLE001
            messagebox.showerror("Configuration error", str(exc))
            return

        if not config.ai_worker_enabled:
            messagebox.showwarning("AI metadata worker", "Enable the AI metadata worker before starting it.")
            return
        if not normalize_upload_url(config.gallery_url) or not config.api_key.strip():
            messagebox.showerror("Connection", "Gallery URL and API key are required before starting the AI worker.")
            return

        self.ai_worker_stop_requested = False
        captured = capture_worker_config(config)
        config_port = self.config_store.mode_port(captured)
        self.ai_worker = AIAnalysisWorkerThread(
            captured, self.events, (self.mode_owner, config_port),
        )
        self.ai_worker.start()
        self.ai_status_var.set("AI metadata worker running")
        self.ai_activity_var.set("AI: running")
        self.write_log("AI metadata worker started.", "success")
        self.record_activity("success", "AI metadata worker started.", "ai")
        self.refresh_activity_monitor_state()
        self.refresh_revoke_button_state()

    def stop_ai_worker(self) -> None:
        """
        Request the optional AI metadata worker to stop after current work is safely reported.
        

        Returns:
            None: Applies this action only after lifecycle admission succeeds.
        """
        if self.ai_worker and self.ai_worker.is_alive():
            self.ai_worker.stop()
            self.ai_worker_stop_requested = True
            self.ai_status_var.set("AI metadata worker stopping")
            self.ai_activity_var.set("AI: stopping")
        else:
            self.ai_activity_var.set("AI: stopped")
        self.refresh_activity_monitor_state()
        self.refresh_revoke_button_state()

    def refresh_connection_controls(self) -> None:
        """Refresh key settings and the shared Watcher/Import session actions.

        Returns:
            None: Selects actions from the accepted work and current upload
                mode. Begin and Finish use cached game-specific wording.
                SimBrief controls are visible only for a detected flight.
        """
        current_url = self.gallery_url_var.get().strip()
        current_key = self.api_key_var.get().strip()
        saved_url = self.config.gallery_url.strip()
        saved_key = self.config.api_key.strip()
        key_present = bool(current_key)
        credentials_saved = current_url == saved_url and current_key == saved_key
        if not key_present:
            self.api_key_status_var.set("API key not configured")
        elif credentials_saved:
            self.api_key_status_var.set("API key configured and saved")
        else:
            self.api_key_status_var.set("API key or gallery URL changed, not saved")
        blockers = self.api_key_request_blockers()
        if self.api_key_revoke_running:
            blocker_text = (
                "Finishing accepted work. Open Flight progress for the current "
                "stage; the key is retained until required work is confirmed."
            )
        elif blockers:
            blocker_text = "Revoke will stop and finish: " + "; ".join(blockers) + "."
        elif key_present and not credentials_saved:
            blocker_text = "Revocation unavailable because the connection fields contain unsaved changes. Save or restore the saved connection first."
        elif key_present:
            blocker_text = "API key can be revoked now. Revocation is permanent for this key and removes it from the local configuration after server confirmation."
        else:
            blocker_text = "Paste a gallery-scoped API key generated in PHP Gallery Admin before testing or uploading."
        self.revoke_blocker_var.set(blocker_text)
        enabled = key_present and credentials_saved
        action = self.flight_action_state()
        if action in ("begin", "finish"):
            action_label = self.simulator_presence.action_label(
                action, self.flights.ui_language.get()
            )
            if (action == "finish"
                    and isinstance(getattr(self, "_contextual_watch_target", None),
                                   LinkedUploadTarget)
                    and self.contextual_watcher_is_flight()):
                action_label = self.flights.text("finish_flight")
        else:
            action_label = self.flights.text({
                "progress": "show_upload_progress",
                "recover": "review_pending_uploads",
                "reconnect": "reconnect_upload_gallery",
            }.get(action, "show_upload_progress"))
        for attr in ("revoke_button",):
            button = getattr(self, attr, None)
            if button is not None:
                button.state(["!disabled"] if enabled else ["disabled"])
        for attr in ("finish_flight_button", "import_revoke_button"):
            button = getattr(self, attr, None)
            if button is not None:
                if action == "idle":
                    button.grid_remove()
                else:
                    button.configure(text=action_label)
                    button.grid()
                    button.state(["!disabled"])
        legacy_cleanup = (
            saved_key and not self.linking_manager.linked_upload_mode_selected()
            and action != "progress"
        )
        for attr in ("abandon_flight_button", "import_abandon_button"):
            button = getattr(self, attr, None)
            if button is not None:
                if legacy_cleanup:
                    button.configure(text=self.flights.text("abandon"))
                    button.grid()
                    button.state(["!disabled"])
                else:
                    button.grid_remove()
        for attr in (
            "simbrief_button", "simbrief_settings_button", "simbrief_status_label",
        ):
            widget = getattr(self, attr, None)
            if widget is not None:
                if self.simulator_presence.flight_detected:
                    widget.grid()
                else:
                    widget.grid_remove()
        entry_state = "disabled" if self.api_key_revoke_running else "normal"
        for attr in ("api_key_entry", "gallery_url_entry"):
            entry = getattr(self, attr, None)
            if entry is not None:
                try:
                    entry.configure(state=entry_state)
                except Exception:  # noqa: BLE001
                    logging.debug("Could not refresh connection entry state.", exc_info=True)

    def flight_action_state(
        self,
    ) -> Literal["idle", "begin", "finish", "progress", "recover", "reconnect"]:
        """Resolve the primary action from work and explicit upload mode.

        Returns:
            str: One visible lifecycle, recovery, reconnect or Begin action;
                a Photos-only link and saved legacy key do not hide Begin.
        """
        state = self.flights.session_state()
        if state == "working":
            return "progress"
        if state in ("pending", "blocked"):
            return "recover"
        if state == "active":
            return "finish"
        if self.manual_worker is not None and self.manual_worker.is_alive():
            return "idle"
        if (self.linking_manager.selected_upload_target() is not None
                or self.linking_manager.linked_upload_mode_selected()):
            return "reconnect"
        return "begin"

    def refresh_revoke_button_state(self) -> None:
        """
        Compatibility wrapper that refreshes all connection and revocation controls.
        

        Returns:
            None: Applies this action only after lifecycle admission succeeds.
        """
        self.refresh_connection_controls()

    def paste_api_key_from_clipboard(self) -> None:
        """Paste one API key from the clipboard into the masked Settings field."""
        if self.api_key_revoke_running:
            messagebox.showwarning("API key", "Wait for API-key revocation to finish before replacing the key.")
            return
        try:
            value = str(self.root.clipboard_get()).strip()
        except Exception:  # noqa: BLE001
            messagebox.showwarning("API key", "The clipboard does not contain text.")
            return
        if not value:
            messagebox.showwarning("API key", "The clipboard does not contain an API key.")
            return
        self.api_key_var.set(value)
        self.api_key_status_var.set("API key entered but not saved")
        self.connection_status_var.set("Not tested")
        self.connection_detail_var.set("API key changed. Save and test the connection before starting work.")
        self.refresh_connection_controls()

    def link_gallery(self) -> None:
        """Run the action for the current site-link state.

        Returns:
            None: Opens the linking prompt or requests unlinking of the live site.
        """
        if not self.updates.blocked():
            if self.linking_manager.linked_address.get():
                self._cancel_ordinary_restart_for_action()
                self.linking_manager.unlink()
            else:
                self.linking_manager.open_address_dialog()

    def open_gallery_links(self) -> None:
        """Open the gallery address manager while a site is selected.

        Returns:
            None: Shows live and recent links, or waits for an active update.
        """
        if not self.updates.blocked():
            self.linking_manager.open_address_dialog()

    def choose_linked_gallery(self) -> None:
        """Show the gallery list beside the linked site's address.

        Returns:
            None: Opens the linked gallery picker or reports that a link is needed.
        """
        if not self.updates.blocked():
            self.linking_manager.open(self.root, self.linking_manager.address())

    def reconnect_upload_gallery(self) -> None:
        """Restore browser access or choose a destination from the linked site.

        Returns:
            None: Opens the existing linking or gallery dialog for this step.
        """
        if not self.updates.blocked():
            linked = self.linking_manager.linked_address.get()
            saved = self.saved_linked_upload_identity()
            if linked and (saved is None or linked == saved[0]):
                self.choose_linked_gallery()
            else:
                self.linking_manager.open_address_dialog(prefill=saved[0] if saved else "")

    def manage_gallery_link(self) -> None:
        """Open the access controls for the active or entered installation.

        Returns:
            None: Shows access controls or requests a separate linking address.
        """
        if not self.updates.blocked():
            address = self.linking_manager.address()
            self.linking_manager.open(self.root, address, manage=True)

    def open_connection_dialog(self) -> None:
        """
        Open the legacy gallery URL and API-key editor.

        The dialog works with temporary values until Save or Save & Test is
        pressed, so closing it cannot accidentally replace a working key.

        Returns:
            None: Displays the URL and API-key controls.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        self.restore_from_tray()
        dialog = tk.Toplevel(self.root)
        dialog.title("Gallery connection and API key")
        dialog.transient(self.root)
        dialog.geometry(CONNECTION_DIALOG_GEOMETRY)
        dialog.minsize(*CONNECTION_DIALOG_MINIMUM)
        dialog.resizable(True, False)
        dialog.columnconfigure(0, weight=1)
        frame = GlassCard(dialog, padding=(18, 14))
        frame.grid(row=0, column=0, sticky="nsew")
        frame.columnconfigure(1, weight=1)

        url_var = tk.StringVar(value=self.gallery_url_var.get())
        key_var = tk.StringVar(value=self.api_key_var.get())
        reveal_var = tk.BooleanVar(value=False)
        ttk.Label(frame, text="PHP Gallery connection", style="Section.TLabel").grid(row=0, column=0, columnspan=4, sticky="w", pady=(0, 4))
        ttk.Label(
            frame,
            text="Use a gallery-scoped API key from Admin. Your password is not entered into this app.",
            wraplength=520,
            style="Muted.TLabel",
        ).grid(row=1, column=0, columnspan=4, sticky="w", pady=(0, 8))
        ttk.Label(frame, text="Gallery URL").grid(row=2, column=0, sticky="w", padx=(0, 8), pady=6)
        url_entry = ttk.Entry(frame, textvariable=url_var, width=70)
        url_entry.grid(row=2, column=1, columnspan=3, sticky="ew", pady=6)
        ttk.Label(frame, text="API key").grid(row=3, column=0, sticky="w", padx=(0, 8), pady=6)
        key_entry = ttk.Entry(frame, textvariable=key_var, show="*", width=54)
        key_entry.grid(row=3, column=1, sticky="ew", pady=6)

        def toggle_reveal() -> None:
            """Reveal or mask the temporary credential field.

            Returns:
                None: Updates only the dialog's API-key display mode.
            """
            key_entry.configure(show="" if reveal_var.get() else "*")

        def paste_key() -> None:
            """Paste clipboard text into the dialog's temporary API-key field.

            Returns:
                None: Leaves stored credentials unchanged until a save action.
            """
            try:
                value = str(dialog.clipboard_get()).strip()
            except Exception:  # noqa: BLE001
                messagebox.showwarning("API key", "The clipboard does not contain text.", parent=dialog)
                return
            if not value:
                messagebox.showwarning("API key", "The clipboard does not contain an API key.", parent=dialog)
                return
            key_var.set(value)

        ttk.Checkbutton(frame, text="Reveal", variable=reveal_var, command=toggle_reveal).grid(row=3, column=2, padx=6, pady=6)
        ttk.Button(frame, text="Paste", command=paste_key).grid(row=3, column=3, pady=6)
        status = "A key is currently stored locally." if self.api_key_var.get().strip() else "No API key is currently stored."
        ttk.Label(frame, text=status, style="Muted.TLabel").grid(row=4, column=1, columnspan=3, sticky="w", pady=(0, 8))

        def apply_values(test_after: bool) -> None:
            """Validate, store, and optionally test temporary credentials.

            Args:
                test_after (bool): Whether to test authentication after saving.

            Returns:
                None: Preserves the current stored key when validation fails.
            """
            if self.updates.blocked():
                return
            if self.api_key_revoke_running:
                messagebox.showwarning("Connection", "Wait for API-key revocation to finish before changing credentials.", parent=dialog)
                return
            url = url_var.get().strip()
            key = key_var.get().strip()
            if not normalize_upload_url(url):
                messagebox.showerror("Connection", "Enter a valid HTTP(S) Gallery URL or upload endpoint.", parent=dialog)
                return
            if not key:
                messagebox.showerror("Connection", "Paste the gallery-scoped API key before saving.", parent=dialog)
                return
            canonical_url = normalize_upload_url(url)
            self.gallery_url_var.set(canonical_url)
            self.api_key_var.set(key)
            if not self.save_config():
                return
            dialog.destroy()
            self.connection_status_var.set("Not tested")
            self.connection_detail_var.set("Connection saved. Test authentication before starting work." if not test_after else "Connection saved. Testing authentication...")
            self.refresh_connection_controls()
            if test_after:
                self.test_connection()

        revoke = ttk.Button(
            frame, text="Revoke saved API key...",
            command=lambda: (dialog.destroy(), self.revoke_api_key()),
        )
        revoke.grid(row=5, column=0, columnspan=2, sticky="w", pady=(0, 3))
        if not self.api_key_var.get().strip():
            revoke.state(["disabled"])
        if (self.config.api_key.strip()
                and self.linking_manager.linked_upload_mode_selected()):
            def use_saved_key() -> None:
                """Select the saved key without changing the browser link.

                Returns:
                    None: Closes the editor on success or explains a refusal.
                """
                refusal = self.linking_manager.use_saved_api_key()
                if refusal is not None:
                    messagebox.showwarning("Upload via API key", refusal, parent=dialog)
                else:
                    dialog.destroy()

            ttk.Button(
                frame, text="Use saved API key", command=use_saved_key,
                style="Compact.TButton",
            ).grid(row=5, column=2, columnspan=2, sticky="e", pady=(0, 3))
        buttons = ttk.Frame(frame, style="Card.TFrame")
        buttons.grid(row=6, column=0, columnspan=4, sticky="ew", pady=(3, 0))
        buttons.columnconfigure(0, weight=1)
        ttk.Button(
            buttons, text="Cancel", command=dialog.destroy,
        ).grid(row=0, column=1, padx=(0, 8))
        ttk.Button(
            buttons, text="Save", command=lambda: apply_values(False),
        ).grid(row=0, column=2, padx=(0, 8))
        ttk.Button(
            buttons,
            text="✓  Save & Test",
            command=lambda: apply_values(True),
            style="Primary.TButton",
        ).grid(row=0, column=3)
        dialog.bind("<Escape>", lambda _event: dialog.destroy())
        dialog.update_idletasks()
        screen_width = dialog.winfo_screenwidth()
        screen_height = dialog.winfo_screenheight()
        margin_width, margin_height = CONNECTION_DIALOG_SCREEN_MARGIN
        minimum_width, minimum_height = CONNECTION_DIALOG_MINIMUM
        width = min(
            max(dialog.winfo_reqwidth(), minimum_width),
            max(minimum_width, screen_width - margin_width),
        )
        height = min(
            max(dialog.winfo_reqheight(), minimum_height),
            max(minimum_height, screen_height - margin_height),
        )
        parent_x = self.root.winfo_rootx()
        parent_y = self.root.winfo_rooty()
        parent_width = self.root.winfo_width()
        parent_height = self.root.winfo_height()
        x = min(
            max(0, parent_x + (parent_width - width) // 2),
            max(0, screen_width - width - margin_width),
        )
        y = min(
            max(0, parent_y + (parent_height - height) // 2),
            max(0, screen_height - height - margin_height),
        )
        dialog.geometry(f"{width}x{height}+{x}+{y}")
        if url_var.get().strip():
            key_entry.focus_set()
        else:
            url_entry.focus_set()
        dialog.grab_set()

    def flight_action(self) -> None:
        """Run the same lifecycle action shown by Watcher and Import.

        Returns:
            None: Finishes capture, opens progress or recovery, or offers the
                appropriate connection setup before any work is accepted.
        """
        if getattr(self, '_ordinary_restart_closing', False) is True:
            return
        if self.updates.blocked():
            return
        action = self.flight_action_state()
        if action == "begin":
            self.open_connection_dialog()
        elif action == "finish":
            self._cancel_ordinary_restart_for_action()
            if (isinstance(getattr(self, "_contextual_watch_target", None),
                           LinkedUploadTarget)
                    and not self.contextual_watcher_is_flight()):
                self.flights.begin(False, automatic=True, photos_only=True)
            elif isinstance(getattr(self, '_contextual_watch_target', None), LinkedUploadTarget):
                self.flights.finish_report()
            else:
                self.flights.finish_report(revoke=True)
        elif action == "progress":
            self.flights.show_progress()
        elif action == "recover":
            self.flights.show_recovery()
        elif action == "reconnect":
            self.reconnect_upload_gallery()

    def abandon_flight(self) -> None:
        """Offer explicit local recovery for a deleted or unreachable gallery.

        Returns:
            None: Uses the shared offline stop/abandon/key-cleanup workflow
                after confirmation; update ownership keeps its existing gate.
        """
        if (self.updates.blocked() or not self.config.api_key.strip()
                or self.linking_manager.linked_upload_mode_selected()):
            return
        self.flights.abandon()

    def forget_flight_connection(self, binding: str) -> None:
        """Persist removal of the captured key before updating visible state.

        Args:
            binding (str): Fingerprint of the explicitly abandoned saved
                endpoint and key, checked again immediately before persistence.
        Returns:
            None: Clears the saved key, resets the target and records local
                abandonment without claiming server revocation.
        Raises:
            ValueError: The saved endpoint/key no longer matches the decision.
            Exception: Persistence failure retains the original saved key and UI.
        """
        if binding != self.flights.binding():
            raise ValueError("Saved connection changed before local key cleanup.")
        cleared_config = replace(self.config, api_key="")
        cleared_config = self._save_owned_config(cleared_config)
        self.config = cleared_config
        self.api_key_var.set("")
        self._verified_legacy_gallery = None
        self.connection_status_var.set("Not configured")
        self.gallery_label_var.set("Target gallery: unknown")
        self.gallery_title_var.set("")
        message = self.flights.text("flight_abandoned")
        self.connection_detail_var.set(message)
        self.write_log(message, "system")
        self.record_activity("info", message, "system")
        self.refresh_connection_controls()

    def complete_legacy_revocation(self, operation: FinalizationWorker) -> None:
        """Clear only the captured key after its exact server-confirmed Finish.

        Args:
            operation (FinalizationWorker): Exact current finalizer retaining its
                legacy_revoke work and captured configuration generation.
        Returns:
            None: Atomically exchanges the sole revoke ticket for a verified
                ConfigStore save. Failure keeps cleanup visibly unconfirmed;
                a stale event cannot clear or acknowledge a newer operation.
        """
        if operation is not self.flights.operation or not operation.server_revocation_confirmed:
            return
        work: Optional[CoordinatedWork] = None
        try:
            if (operation.config_generation is None
                    or operation.config_generation is not self.config._snapshot_token
                    or operation.api_key != self.config.api_key
                    or operation.upload_url != normalize_upload_url(self.config.gallery_url)):
                raise ValueError('Captured revocation configuration changed.')
            work = operation.revocation_work()
            work.checkpoint()
            cleared = self._save_owned_config(
                replace(self.config, api_key=''), completed_work=work,
            )
        except Exception:  # noqa: BLE001
            detail = 'Server revocation confirmed; local key cleanup needs review.'
            retryable = False
            if work is not None:
                try:
                    work.checkpoint()
                    retryable = True
                except RuntimeError:
                    pass
            operation.confirm_local_cleanup(detail, retryable=retryable)
            self.connection_detail_var.set(detail)
            self.write_log(detail, 'error')
            self.record_activity('error', detail, 'system')
        else:
            self.config = cleared
            self.api_key_var.set('')
            self._verified_legacy_gallery = None
            self.connection_status_var.set('Not configured')
            self.gallery_label_var.set('Target gallery: unknown')
            self.gallery_title_var.set('')
            self.connection_detail_var.set('API key revoked and removed from local configuration.')
            operation.confirm_local_cleanup()
            self.write_log('Gallery API key revoked.', 'success')
            self.record_activity('success', 'Gallery API key revoked.', 'system')
        self.api_key_revoke_running = operation.local_cleanup_pending
        self.api_key_revoke_thread = operation if operation.local_cleanup_pending else None
        self.refresh_connection_controls()

    def revoke_api_key(self) -> None:
        """Confirm one combined stop, finish, upload and key-revocation action.

        Returns:
            None: Reuses finalization progress and never revokes before receipts.
        """
        if self.updates.blocked():
            return
        if (
            self.gallery_url_var.get().strip() != self.config.gallery_url.strip()
            or self.api_key_var.get().strip() != self.config.api_key.strip()
        ):
            messagebox.showwarning(
                "Revoke API key",
                "Save or restore connection fields before revoking the saved key.",
            )
            return
        if not normalize_upload_url(self.config.gallery_url) or not self.config.api_key:
            messagebox.showwarning("Revoke API key", "A saved connection is required.")
            return
        self._verified_legacy_gallery = None
        self.gallery_label_var.set("Target gallery: unknown")
        self.gallery_title_var.set("")
        self.flights.begin(True)

    def prepare_update_checkpoint(self, target_version: str) -> None:
        """Capture active Watcher intent before requesting a non-destructive pause.

        Args:
            target_version (str): Verified installer version selected for update.

        Returns:
            None: Requests a pause after the current upload acknowledgement;
                a stopped Watcher never acquires new continuation consent.

        Raises:
            RuntimeError: Another accepted operation must finish first.
            ValueError: The active session or mode cannot be verified.
            OSError: A durable checkpoint cannot be prepared.
        """
        if getattr(self, '_ordinary_restart_closing', False) is True:
            raise RuntimeError('The Watcher is already preparing to close.')
        if (self.flights.busy() or self.api_key_revoke_running
                or any(worker is not None and worker.is_alive() for worker in (
                    self.manual_worker, self.ai_worker,
                ))):
            raise RuntimeError('Finish the current accepted operation before updating.')
        self._update_resume_cancelled = False
        self._update_resume_generation += 1
        self._update_checkpoint_ready = False
        self._update_continuation = None
        self._update_context_target = None
        self._update_context_key_closed = False
        worker = self.worker
        if worker is not None and worker.is_alive():
            plan = self.flights.plan
            lease = self.flights.lease
            if (plan is None or lease is None or worker.drain_event.is_set()
                    or worker.stop_event.is_set() or worker.drain_error
                    or not worker.initial_captured):
                raise ValueError('The current Watcher must finish preparation or recovery first.')
            lease.verify()
            current = self.flights.pending()
            if (len(current) != 1 or current[0].directory != plan.directory
                    or current[0].binding != plan.binding):
                raise ValueError('The current Watcher session could not be verified.')
            mode = self.mode_owner.view()
            kind = 'linked' if worker.linked_target is not None else 'legacy'
            if ((kind == 'legacy' and mode.state != 'legacy')
                    or (kind == 'linked' and mode.state != 'linked_active')):
                raise ValueError('The current Watcher mode could not be verified.')
            contextual = bool(worker.linked_target is not None
                              and worker.linked_target.watch_key is not None)
            record = WatcherContinuation(
                plan.session_id, plan.binding,
                WatcherContinuation.profile_digest(self.flights.directory),
                APP_VERSION, target_version, '', kind, contextual,
                time.time(), 'preparing',
                approved_scope=mode.record.scope if kind == 'linked' and mode.record else '',
            )
            record.save(lease)
            self._update_continuation = record
            self._update_context_target = worker.linked_target if contextual else None
            worker.request_update_pause()
            self._set_watcher_recording(False)
            self.status_var.set('Finishing the current upload before updating…')
        self.flights.stop()
        self.utc_sync.stop()
        if self.preflight_worker is not None and self.preflight_worker.is_alive():
            self.preflight_worker.stop()
        self._update_checkpoint_workers = (
            self.worker, self.flights, self.utc_sync, self.preflight_worker,
        )
        self._update_resume_status = 'stage=checkpoint; reason=waiting_for_acknowledged_work'

    def wait_for_update_checkpoint(self) -> None:
        """Wait off Tk for all captured writers before allowing installation.

        Returns:
            None: Releases workers and confirms durable exact-session state.

        Raises:
            TimeoutError: A current request or native worker has not settled.
            ValueError: A queue, target or credential remains unresolved.
            OSError: Accepted state cannot be verified on disk.
        """
        deadline = time.monotonic() + UPDATE_CHECKPOINT_TIMEOUT_SECONDS
        for worker in self._update_checkpoint_workers:
            if worker is not None and worker.is_alive():
                worker.join(timeout=max(0.0, deadline - time.monotonic()))
        if any(worker is not None and worker.is_alive()
               for worker in self._update_checkpoint_workers):
            self._update_resume_status = 'stage=checkpoint; reason=workers_still_running'
            raise TimeoutError('Current work is still finishing; update postponed.')
        record = self._update_continuation
        if record is not None:
            lease = self.flights.lease
            if lease is None:
                raise ValueError('The Watcher workspace owner is unavailable.')
            lease.verify()
            plan = SessionPlan.load(self.flights.directory / record.session_id)
            row = read_document(plan.directory / 'watch-drain.json')
            if (plan.completed or plan.abandoned or plan.binding != record.binding
                    or row.get('frozen') is not False or row.get('cutoff') != 0
                    or row.get('error') or self.worker is None
                    or self.worker.drain_error):
                self._update_resume_status = 'stage=checkpoint; reason=queue_needs_review'
                raise ValueError('The Watcher queue needs review before updating.')
            if (self.worker.linked_target is not None
                    and self.worker.state.has_unresolved_upload()):
                self._update_resume_status = 'stage=checkpoint; reason=upload_unconfirmed'
                raise ValueError('An upload is unconfirmed; update postponed.')
            target = self._update_context_target
            if target is not None and target.watch_key is not None:
                try:
                    target.session.close_watch_key(target.gallery_id, target.watch_key)
                except Exception:
                    self._update_resume_status = 'stage=checkpoint; reason=watch_access_unconfirmed'
                    raise ValueError('Temporary Watcher access could not be closed.') from None
                self._update_context_key_closed = True
            # Reobserve the final persisted queue only after its writer ended.
            record.save(lease)
        self._update_checkpoint_ready = True
        self._update_resume_status = 'stage=checkpoint; reason=saved'

    def bind_update_checkpoint(self, ticket: Path) -> None:
        """Bind safe paused work to the exact independently staged helper.

        Args:
            ticket (Path): Private ticket created and validated by the helper owner.

        Returns:
            None: Publishes the nonce before the helper's proceed marker.

        Raises:
            ValueError: The checkpoint, helper or selected version changed.
            OSError: The verified checkpoint cannot be persisted.
        """
        from uploader.update_helper import load_ticket

        if not self._update_checkpoint_ready:
            raise ValueError('The update checkpoint is not ready.')
        selected = load_ticket(ticket)
        record = self._update_continuation
        if record is not None:
            lease = self.flights.lease
            if lease is None or selected.target_version != record.target_version:
                raise ValueError('The update checkpoint target changed.')
            record = replace(
                record, update_session=selected.session_id, phase='prepared',
            )
            record.save(lease)
            self._update_continuation = record
        self._update_resume_status = 'stage=checkpoint; reason=handoff_bound'

    def cancel_update_checkpoint(self) -> None:
        """Retain credentials and resume the same paused work after handoff failure.

        Returns:
            None: Schedules a verified continuation after its writers settle;
                unresolved work keeps an actionable reason instead of abandonment.
        """
        record = getattr(self, '_update_continuation', None)
        if record is None:
            return
        if not record.update_session:
            # This is a local cancellation before helper creation; the nonce
            # identifies only this owned continuation, never authentication.
            record = replace(record, update_session=os.urandom(16).hex())
        self._resume_continuation = record
        self._update_resume_deadline = (
            time.monotonic() + UPDATE_CHECKPOINT_TIMEOUT_SECONDS
        )
        self._update_resume_status = 'stage=resume; reason=handoff_cancelled'
        self._continue_update_resume()

    def _discard_update_resume_intent(self, *, explicit: bool = False) -> None:
        """Persist an explicit Stop/Quit without deleting unfinished work.

        Args:
            explicit (bool): Whether Stop, unlink or true exit explicitly
                revokes an unclaimed startup nonce.

        Returns:
            None: Cancels only automatic continuation; photos, telemetry,
                credentials and accepted finalization remain with their owners.
        """
        owned = (
            getattr(self, '_resume_continuation', None)
            or getattr(self, '_update_continuation', None)
        )
        offered_retry = getattr(self, '_resume_retry_available', False) is True
        requested_nonce = getattr(self, '_after_update_nonce', None)
        self._resume_continuation = None
        self._resume_retry_available = False
        self._close_update_resume_context_key()
        if owned is None and not offered_retry and not explicit:
            return
        try:
            lease = self.flights.lease
            if lease is None:
                return
            current = WatcherContinuation.load(lease)
            if (current is None
                    or current.phase not in ('preparing', 'prepared', 'claimed')):
                return
            if owned is not None:
                matches_owner = (
                    current.session_id == owned.session_id
                    and current.binding == owned.binding
                    and (not owned.update_session
                         or current.update_session == owned.update_session)
                )
            else:
                if requested_nonce is not None and current.update_session != requested_nonce:
                    return
                if (APP_VERSION not in (
                        current.source_version, current.target_version,
                )):
                    return
                mode_view = self.mode_owner.view()
                if current.mode == 'legacy':
                    expected_binding = connection_binding(
                        normalize_upload_url(self.config.gallery_url),
                        self.config.api_key,
                    )
                    matches_owner = (
                        mode_view.state == 'legacy'
                        and current.binding == expected_binding
                    )
                elif current.mode == 'linked':
                    mode_record = mode_view.record
                    matches_owner = (
                        mode_record is not None
                        and mode_record.mode == 'linked'
                        and mode_record.scope == current.approved_scope
                        and linked_target_binding(
                            mode_record.issuer, mode_record.account_id,
                            mode_record.gallery_id,
                        ) == current.binding
                    )
                else:
                    matches_owner = False
            if matches_owner:
                replace(
                    current, phase='cancelled',
                    update_session=current.update_session or os.urandom(16).hex(),
                ).save(lease)
        except (OSError, ValueError, RuntimeError):
            self.write_log(
                '[watcher-resume] stage=stop; reason=checkpoint_cancel_unverified',
                'warning',
            )

    def close(self, *, for_update: bool = False) -> None:
        """Coordinate shutdown while preserving update and import state.

        Args:
            for_update (bool): Terminate after a durable checkpoint, released
                upload writers and the external helper's readiness acknowledgement.
        Returns:
            None: Retains the update indicator when shutdown is deferred or
                canceled, and clears it only before confirmed app teardown.
                Confirmed teardown also stops the simulator-presence observer
                and forgets any in-memory linked identity.
                A clean quit checkpoints an active Watcher asynchronously and
                preserves only its exact profile-bound restart consent.
                Update shutdown requires the pre-handoff checkpoint; its existing
                immediate exit happens only after accepted writers have settled.
        """
        self._save_ui_layout_now()
        if for_update:
            if (getattr(self, '_update_checkpoint_ready', False) is not True
                    or any(worker is not None and worker.is_alive()
                           for worker in self._update_checkpoint_workers)):
                self.write_log(
                    'Update shutdown refused: accepted work has not checkpointed.',
                    'error',
                )
                return
            self.exiting = True
            try:
                # Children belong to this app; the standalone update helper is
                # a separate subprocess and must survive this process exit.
                for process in multiprocessing.active_children():
                    try:
                        process.terminate()
                    except (OSError, ValueError):
                        pass
            finally:
                # Exit all threads together, including optional observers and
                # non-daemon work. Do not enter Python's thread/atexit joins.
                os._exit(0)
        if getattr(self, "updates", None) is not None and self.updates.request_close():
            return
        if self.api_key_revoke_running:
            messagebox.showwarning(
                "Finishing current work",
                "Wait for accepted work and any server response to finish. "
                "Flight progress shows the remaining tasks.",
            )
            return
        if self.exiting:
            return
        active_worker = self.worker
        active_capture = bool(
            active_worker is not None and active_worker.is_alive()
            and active_worker.initial_captured
            and not active_worker.stop_event.is_set()
            and not active_worker.drain_event.is_set()
            and getattr(active_worker, 'drain_only', False) is not True
        )
        ordinary_prepared = (
            getattr(self, '_ordinary_restart_prepared', False) is True
        )
        if (not for_update and not ordinary_prepared
                and active_capture
                and (self.worker.linked_target is not None
                     or self.mode_owner.view().state == 'legacy')):
            if getattr(self, '_ordinary_restart_closing', False) is True:
                return
            try:
                self.prepare_update_checkpoint(APP_VERSION)
            except (OSError, RuntimeError, ValueError):
                messagebox.showwarning(
                    "Watcher is still running",
                    "The active Watcher could not be safely prepared for restart. "
                    "Keep the app open and finish or stop Watcher first.",
                )
                return
            self._ordinary_restart_closing = True
            self.watcher_activity_var.set('Watch: finishing')
            self.status_var.set('Saving the Watcher session before closing…')
            self.refresh_watcher_header()
            self._ordinary_restart_thread = threading.Thread(
                target=self._prepare_ordinary_restart_close,
                name='PHPGalleryOrdinaryRestartCheckpoint', daemon=True,
            )
            try:
                self._ordinary_restart_thread.start()
            except RuntimeError:
                self._ordinary_restart_closing = False
                self._ordinary_restart_thread = None
                self.write_log(
                    '[watcher-resume] stage=close; reason=checkpoint_worker_unavailable',
                    'warning',
                )
                self.cancel_update_checkpoint()
            return
        self.flights.stop()
        self.utc_sync.stop()
        if (not ordinary_prepared
                and getattr(self, '_ordinary_restart_resuming', False) is not True):
            self._discard_update_resume_intent(explicit=True)
        self.exiting = True
        self._cancel_event_ui_callbacks()
        self._set_watcher_recording(False)
        self.write_log("Application shutdown requested. Stopping background work safely.", "system")

        if self.preflight_worker and self.preflight_worker.is_alive():
            self.preflight_worker.stop()
        if self.worker and self.worker.is_alive():
            self.worker.stop()
        if self.manual_worker and self.manual_worker.is_alive():
            self.manual_worker.stop()
        if self.ai_worker and self.ai_worker.is_alive():
            self.ai_worker.stop()
            self.ai_worker_stop_requested = True

        deadline = time.monotonic() + 5.0
        for worker in (
            self.flights, self.utc_sync, self.preflight_worker, self.worker,
            self.manual_worker, self.ai_worker, self.api_key_revoke_thread,
            getattr(self, '_update_resume_context_key_worker', None),
        ):
            if worker is None or not worker.is_alive():
                continue
            remaining = max(0.0, deadline - time.monotonic())
            if remaining <= 0:
                break
            worker.join(timeout=remaining)

        still_running = [
            label
            for label, worker in (
                ("flight recording", self.flights),
                ("UTC synchronization", self.utc_sync),
                ("preflight", self.preflight_worker),
                ("watcher", self.worker),
                ("import", self.manual_worker),
                ("AI", self.ai_worker),
                ("API-key revocation", self.api_key_revoke_thread),
                ("Watcher key closure", getattr(
                    self, '_update_resume_context_key_worker', None,
                )),
            )
            if worker is not None and worker.is_alive()
        ]
        if still_running:
            message = (
                "Shutdown wait expired for " + ", ".join(still_running) +
                ". In-flight network work may still be returning, and durable import state is preserved."
            )
            self.write_log(message, "warning")
            if self.tray_icon is not None and messagebox.askyesno(
                "Background work is still stopping",
                message + "\n\nKeep the application running in the tray instead of forcing it closed?",
            ):
                self.exiting = False
                self.hide_to_tray()
                self._sync_watcher_recording()
                self._schedule_activity_drawer_refresh()
                self._schedule_event_drain(WATCHER_EVENT_IDLE_POLL_MS)
                return
        writer = getattr(self, "activity_writer", None)
        if writer is not None and not writer.close(max(0.0, deadline - time.monotonic())):
            logging.warning("Recent activity history did not finish flushing before exit.")
        self.set_update_available(None)
        self.stop_tray_icon()
        self.simulator_presence.stop()
        self.linking_manager.close()
        self._cancel_event_ui_callbacks()
        self.root.destroy()

    def _prepare_ordinary_restart_close(self) -> None:
        """Persist clean restart consent after the active Watcher checkpoints.

        Returns:
            None: Queues either a safe close event or a recoverable failure.
        """
        self._update_resume_cancelled = False
        self._update_resume_generation += 1
        try:
            self.wait_for_update_checkpoint()
            record = self._update_continuation
            lease = self.flights.lease
            if (record is None or lease is None
                    or record.mode not in ('linked', 'legacy')):
                raise ValueError('The Watcher checkpoint is incomplete.')
            prepared = replace(
                record, source_version=APP_VERSION, target_version=APP_VERSION,
                update_session=os.urandom(16).hex(), phase='prepared',
            )
            lease.verify()
            # Recovery must retain this exact nonce even if only the first
            # atomic replacement commits before the alternate write fails.
            self._update_continuation = prepared
            prepared.save(lease)
            prepared.save(lease, ordinary_restart=True)
            self.events.put({'kind': 'ordinary_restart_ready'})
        except (OSError, RuntimeError, TimeoutError, ValueError):
            self.events.put({'kind': 'ordinary_restart_failed'})

    def open_config_folder(self) -> None:
        """
        Open the folder containing config, state, staging, and log files.
        """
        CONFIG_DIR.mkdir(parents=True, exist_ok=True)
        self.open_local_path(CONFIG_DIR)

    def open_local_path(self, path: Path) -> None:
        """Open one local file/folder with the platform shell when possible."""
        target = Path(path)
        try:
            if hasattr(os, "startfile"):
                os.startfile(str(target))  # type: ignore[attr-defined]
            else:
                webbrowser.open(target.resolve().as_uri())
        except Exception as exc:  # noqa: BLE001
            self.write_log(f"Could not open {target}: {exc}", "warning")

    def open_log_file(self) -> None:
        """Open the rotating uploader log, creating it when necessary."""
        CONFIG_DIR.mkdir(parents=True, exist_ok=True)
        if not LOG_PATH.exists():
            LOG_PATH.touch()
        self.open_local_path(LOG_PATH)

    def open_watched_folder(self) -> None:
        """Open the currently configured watch folder in Explorer."""
        folder = Path(self.watched_folder_var.get().strip())
        if not folder.is_dir():
            messagebox.showwarning("Watch folder", "Choose an existing folder first.")
            return
        self.open_local_path(folder)

    def open_activity_tab(self) -> None:
        """Restore the main window and show durable Activity history.

        Returns:
            None: Brings the Activity view into the foreground.
        """
        self.restore_from_tray()
        try:
            self.select_tab("activity")
        except Exception:  # noqa: BLE001
            logging.debug("Could not select Activity tab.", exc_info=True)

    def tray_tooltip_text(self) -> str:
        """Return the update version and activity within Windows' tooltip limit.

        Returns:
            str: One-line current update and activity summary capped to the
                documented notification-area character budget.
        """
        watch = 1 if self.worker and self.worker.is_alive() else 0
        importing = 1 if self.manual_worker and self.manual_worker.is_alive() else 0
        ai = 1 if self.ai_worker and self.ai_worker.is_alive() and not self.ai_worker_stop_requested else 0
        failures = self.current_job.counts()["failed"] if self.current_job is not None else 0
        update = (
            f" | Update {self.update_available_version}"
            if self.update_available_version is not None
            else ""
        )
        summary = (
            f"PHP Gallery uploader{update} | watch {watch} | "
            f"import {importing} | AI {ai} | failures {failures}"
        )
        return summary[:TRAY_TOOLTIP_MAX_LENGTH]

    def toggle_settings_advanced(self) -> None:
        """Show or hide low-level import/archive tuning settings.

        Returns:
            None: Keeps detailed settings collapsed until requested.
        """
        if self.settings_advanced_frame is None:
            return
        if self.settings_advanced_visible_var.get():
            self.settings_advanced_frame.grid(row=3, column=0, sticky="ew", padx=6, pady=4)
        else:
            self.settings_advanced_frame.grid_remove()

    def toggle_api_key_visibility(self) -> None:
        """Reveal or remask the API key only on explicit user action."""
        visible = not self.api_key_visible_var.get()
        self.api_key_visible_var.set(visible)
        self.api_key_entry.configure(show="" if visible else "*")

    def copy_api_key(self) -> None:
        """Copy the configured API key to the Windows clipboard on explicit action."""
        value = self.api_key_var.get().strip()
        if not value:
            messagebox.showwarning("API key", "No API key is configured.")
            return
        self.root.clipboard_clear()
        self.root.clipboard_append(value)
        self.write_log("API key copied to clipboard by explicit user action.", "system")

    def test_connection(self) -> None:
        """Refresh connection status and current gallery metadata.

        Returns:
            None: Starts an authenticated inventory check without uploading
                files, or reports why the request cannot start.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.linking_manager.linked_upload_mode_selected():
            messagebox.showinfo(
                'Connection',
                'This tests the saved API key. Select the API-key connection '
                'explicitly before running this test.',
            )
            return
        if self.api_key_revoke_running:
            messagebox.showwarning("Connection", "Wait for API-key revocation to finish before testing the connection.")
            return
        if self.connection_test_running:
            return
        upload_url = normalize_upload_url(self.gallery_url_var.get())
        api_key = self.api_key_var.get().strip()
        if not upload_url:
            messagebox.showerror("Connection", "Enter a valid HTTP or HTTPS Gallery URL/endpoint.")
            return
        if not api_key:
            messagebox.showerror("Connection", "Enter an API key before testing the connection.")
            return
        try:
            current = self.current_config()
            if current.to_dict() != self.config.to_dict():
                raise ValueError('Save the current settings before testing the connection.')
            captured = capture_worker_config(current)
            config_port = self.config_store.mode_port(captured)
        except (ValueError, OSError) as exc:
            messagebox.showerror('Connection', str(exc))
            return
        self.connection_test_running = True
        self.connection_status_var.set("Testing")
        self.connection_detail_var.set("Checking endpoint and API-key authorization without uploading media...")
        self.refresh_revoke_button_state()

        def tester() -> None:
            """Read current gallery metadata without blocking Tkinter.

            Returns:
                None: Enqueues connection metadata or a connection error.
            """
            try:
                with self.mode_owner.acquire('legacy', config_port) as work:
                    payload, resolved_url = post_json_resolved(
                        upload_url, api_key,
                        {"action": "inventory", "files": [], "deep_check": False},
                        timeout_seconds=20.0, before_request=work.checkpoint,
                    )
                label = str(payload.get("gallery_folder", "") or payload.get("gallery", "") or "gallery").strip()
                self.events.put({"kind": "connection_ready", "payload": {
                    "gallery_label": label,
                    "gallery_title": str(payload.get("gallery_title", "") or "").strip(),
                    "normalized_url": resolved_url,
                    "explicit_test": True,
                    "config_generation": captured._snapshot_token,
                }})
            except Exception as exc:  # noqa: BLE001
                self.events.put({"kind": "connection_error", "payload": {
                    "message": str(exc), "normalized_url": upload_url,
                    "explicit_test": True,
                    "config_generation": captured._snapshot_token,
                }})

        threading.Thread(target=tester, name="PHPGalleryConnectionTest", daemon=True).start()

    def refresh_watch_delete_warning(self) -> None:
        """Explain the active source-deletion policy in the Watcher card.

        Returns:
            None: Explains exact-byte deletion only when the preference is on.
        """
        if not hasattr(self, "watch_delete_warning"):
            return
        if self.delete_uploaded_files_var.get():
            self.watch_delete_warning.grid()
            self.watch_delete_warning.configure(
                text="Uploaded photos will be deleted from this PC.",
                style="Muted.TLabel",
            )
        else:
            self.watch_delete_warning.grid_remove()

    def dry_run_watch_folder(self) -> None:
        """Inspect an explicitly chosen watch folder without uploading.

        Returns:
            None: Reports existing files or asks for a readable folder.
        """
        selected = self.watched_folder_var.get().strip()
        folder = Path(selected)
        if not selected or not folder.is_dir():
            messagebox.showwarning("Watch folder", "Choose an existing folder first.")
            return
        result = discover_folder(folder, SUPPORTED_SUFFIXES, bool(self.watch_recursive_var.get()))
        supported = len(result.items)
        unsupported = sum(result.unsupported.values())
        self.watch_ignored_var.set(f"Would ignore on start: {supported}")
        self.watch_last_scan_var.set("Last check: " + time.strftime("%Y-%m-%d %H:%M:%S"))
        detail = f"Watch-folder dry run: {supported} supported existing file(s) would be ignored at watcher start; {unsupported} unsupported file(s) found."
        self.write_log(detail, "system")
        self.record_activity("info", detail, "watcher")

    def launch_manual_job(
        self, job: ImportJob, paths: List[Path], config: WatcherConfig,
        linked_target: Optional[LinkedUploadTarget] = None,
    ) -> None:
        """Bind and launch accepted manual-import work for the saved connection.

        Args:
            job (ImportJob): Durable job containing accepted source items.
            paths (List[Path]): Recoverable originals selected for this attempt.
            config (WatcherConfig): Saved connection and upload settings snapshot.
            linked_target (Optional[LinkedUploadTarget]): Captured delegated
                destination, or None for explicitly selected API-key mode.

        Returns:
            None: Starts the worker after binding, or reports a recovery error.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if getattr(self, "api_key_revoke_running", False):
            self.write_log(
                "Manual import cannot start while accepted work is finishing.",
                "warning",
            )
            return
        if not paths:
            return
        if (self.linking_manager.linked_upload_mode_selected()
                != (linked_target is not None)):
            messagebox.showerror('Import recovery', 'The selected gallery connection changed.')
            return
        if (linked_target is not None and (
                self.linking_manager.linked_upload_binding() != linked_target.binding()
                or self.linking_manager.selected_upload_target() is not linked_target)):
            messagebox.showerror('Import recovery', 'Reconnect the selected linked gallery.')
            return
        if linked_target is not None and self.flights.session_state() != "idle":
            messagebox.showinfo(
                "Import", "Finish the current Watcher session before starting an import.",
                parent=self.root,
            )
            return
        use_local_thumbnails = bool(self.manual_local_thumbnails_var.get()) and local_thumbnail_supported()
        if self.manual_local_thumbnails_var.get() and not local_thumbnail_supported():
            self.write_log("Local thumbnail generation is unavailable. Server thumbnail generation will be used.", "warning")
            use_local_thumbnails = False
        try:
            binding = (linked_target.binding() if linked_target is not None
                       else self.flights.binding())
            entries = self.flights.import_bindings.entries()
            if job.started_at <= 0:
                if getattr(self, '_preflight_approved', None) != (
                    job.id, config._snapshot_token, binding,
                ):
                    raise ValueError('Configuration changed or preflight is unverified; run it again.')
            elif (entries.get(job.id) != binding
                  or any(item.state == 'skipped_duplicate' for item in job.items)):
                raise ValueError('This import needs a new preflight before recovery.')
            self.flights.import_bindings.admit_job(self.job_store, job, binding)
        except (OSError, ValueError, RuntimeError) as exc:
            messagebox.showerror("Import recovery", str(exc))
            return
        self.current_job = job
        captured = capture_worker_config(config)
        config_port = self.config_store.mode_port(captured)
        self.manual_worker = ManualUploadThread(
            captured,
            list(paths),
            use_local_thumbnails,
            config.manual_thumbnail_workers,
            config.manual_upload_workers,
            self.events,
            job=job,
            job_store=self.job_store,
            linked_target=linked_target,
            gui_admission=(self.mode_owner, config_port),
        )
        self.manual_worker.start()
        self.manual_status_var.set("Import running")
        self.import_activity_var.set("Import: running")
        self.start_import_button.configure(state="disabled")
        self.pause_import_button.configure(state="normal")
        self.resume_import_button.configure(state="disabled")
        self.cancel_import_button.configure(state="normal")
        self.retry_failed_button.configure(state="disabled")
        self.write_log(f"Manual import job started with {len(paths)} file(s).", "success")
        self.record_activity("success", f"Manual import started with {len(paths)} file(s).", "import", job_id=job.id)
        self.refresh_activity_monitor_state()
        self.refresh_revoke_button_state()

    def pause_manual_upload(self) -> None:
        """Pause submission of new import work while allowing in-flight requests to finish."""
        if self.manual_worker and self.manual_worker.is_alive():
            self.manual_worker.pause()
            self.manual_status_var.set("Import paused")
            self.import_activity_var.set("Import: paused")
            self.pause_import_button.configure(state="disabled")
            self.resume_import_button.configure(state="normal")

    def resume_manual_upload(self) -> None:
        """Resume a paused manual import."""
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.manual_worker and self.manual_worker.is_alive():
            self.manual_worker.resume()
            self.manual_status_var.set("Import running")
            self.import_activity_var.set("Import: running")
            self.pause_import_button.configure(state="normal")
            self.resume_import_button.configure(state="disabled")

    def retry_failed_import(self) -> None:
        """Explicitly retry failed/cancelled or crash-interrupted items in the selected job.

        Returns:
            None: Applies this action only after lifecycle admission succeeds.
        """
        if getattr(self, "updates", None) is not None and self.updates.blocked():
            return
        if self.api_key_revoke_running:
            messagebox.showwarning("Connection", "Wait for API-key revocation to finish before retrying an import.")
            return
        if self.manual_worker and self.manual_worker.is_alive():
            messagebox.showwarning("Retry import", "Wait for the current import to stop before retrying failed items.")
            return
        if self.current_job is None:
            messagebox.showwarning("Retry import", "Select a job in Activity first.")
            return
        try:
            with accepted_work_owner(CONFIG_DIR):
                # Never fall back to an in-memory row when durable history is
                # missing, malformed or newer than the selected GUI snapshot.
                jobs = self.job_store.inspect_jobs_for_target_change()
                refreshed = next(
                    (job for job in jobs if job.id == self.current_job.id),
                    None,
                )
                if refreshed is None:
                    raise ValueError("Accepted import history is missing; review it.")
                entries = self.flights.import_bindings.entries()
        except (OSError, ValueError, RuntimeError) as exc:
            messagebox.showwarning('Import review', str(exc))
            return
        if any(item.state == 'skipped_duplicate' for item in refreshed.items):
            messagebox.showwarning(
                'Retry import',
                'Earlier duplicate results need a new preflight. Original sources were retained.',
            )
            return
        try:
            proposed = self.current_config()
            if proposed.to_dict() != self.config.to_dict():
                raise ValueError('Save the current settings and run preflight again.')
            target = self.capture_linked_target()
            binding = target.binding() if target is not None else self.flights.binding()
            if refreshed.started_at <= 0:
                if getattr(self, '_preflight_approved', None) != (
                    refreshed.id, self.config._snapshot_token, binding,
                ):
                    raise ValueError('This import needs a new preflight before recovery.')
            elif entries.get(refreshed.id) != binding:
                raise ValueError('This import has no verified current gallery binding.')
        except (OSError, ValueError) as exc:
            messagebox.showwarning('Retry import', str(exc))
            return
        paths = []
        missing = 0
        uncertain = 0
        for item in refreshed.items:
            if item.state not in RECOVERABLE_ITEM_STATES and item.state != "failed_permanent":
                continue
            if item.reason in (LINKED_UPLOAD_UNCERTAIN_REASON, LEGACY_UPLOAD_UNCERTAIN_REASON):
                uncertain += 1
                continue
            path = Path(item.path)
            if not path.is_file():
                item.transition("failed_permanent", "Local source is no longer available for retry.")
                missing += 1
                continue
            if item.state == "failed_permanent":
                item.transition("failed_retryable", "Explicit user retry requested.")
            paths.append(path)
        if missing:
            self.job_store.save_job(refreshed)
        if uncertain:
            messagebox.showwarning(
                'Import review',
                f'{uncertain} upload(s) have an uncertain result. '
                'Review the gallery manually; they were not retried.',
            )
        if not paths:
            messagebox.showinfo("Retry import", "No failed or recoverable local files are available for retry.")
            self.current_job = refreshed
            self.refresh_import_item_tree(refreshed)
            self.refresh_job_history()
            return
        refreshed.finished_at = 0.0
        try:
            config = self.current_config()
            linked_target = self.capture_linked_target()
            if not self.connection_change_allowed(config):
                return
            config = self._save_owned_config(config)
            self.config = config
        except Exception as exc:  # noqa: BLE001
            messagebox.showerror("Configuration error", str(exc))
            return
        if (linked_target is None
                and (not normalize_upload_url(config.gallery_url) or not config.api_key.strip())):
            messagebox.showerror("Connection", "Gallery URL and API key are required before retrying.")
            return
        self.current_job = refreshed
        self.launch_manual_job(refreshed, paths, config, linked_target)

    def offer_recoverable_jobs(self) -> None:
        """Offer the newest unfinished import for explicit recovery after restart.

        Returns:
            None: Opens Import only after explicit recovery consent.
        """
        if self.exiting or self.current_job is not None:
            return
        jobs = self.job_store.recoverable_jobs()
        if not jobs:
            return
        job = jobs[0]
        counts = job.counts()
        answer = messagebox.askyesno(
            "Recover unfinished import",
            f"An unfinished import from {time.strftime('%Y-%m-%d %H:%M', time.localtime(job.created_at))} contains "
            f"{counts['active'] + counts['failed'] + counts['cancelled']} recoverable item(s). Open it for review and resume/retry?",
        )
        if not answer:
            self.record_activity("warning", "Recoverable import remains saved for later review.", "import", job_id=job.id)
            return
        self.current_job = job
        self.manual_plan = ImportPlan(job=job)
        self.manual_paths = [Path(item.path) for item in job.items if Path(item.path).is_file()]
        self.manual_folder = None
        self.manual_zip = Path(job.source_zip) if job.source_zip else None
        self.manual_selection_var.set("Recovered import: " + job.source_label)
        self.render_preflight_plan(self.manual_plan)
        self.select_tab("import")
        self.write_log("Recovered unfinished import for explicit review. No upload was started automatically.", "warning")

    def refresh_job_history(self) -> None:
        """Refresh the durable import-job history table."""
        if not hasattr(self, "job_history_tree"):
            return
        for iid in self.job_history_tree.get_children():
            self.job_history_tree.delete(iid)
        for job in self.job_store.list_jobs()[:200]:
            counts = job.counts()
            created = time.strftime("%Y-%m-%d %H:%M", time.localtime(job.created_at))
            status = f"uploaded {counts['uploaded']}, duplicate {counts['duplicates']}, failed {counts['failed']}, cancelled {counts['cancelled']}, active {counts['active']}"
            self.job_history_tree.insert("", "end", iid=job.id, values=(created, job.source_label, job.target_gallery_label, status))

    def select_history_job(self, _event: Any = None) -> None:
        """Select one durable job from Activity for inspection or explicit retry."""
        if not hasattr(self, "job_history_tree"):
            return
        selection = self.job_history_tree.selection()
        if not selection:
            return
        job = self.job_store.get_job(selection[0])
        if job is None:
            return
        self.current_job = job
        self.manual_plan = ImportPlan(job=job)
        self.activity_job_summary_var.set(self.describe_job(job))
        self.refresh_import_item_tree(job)
        self.retry_failed_button.configure(
            state="normal" if any(item.state in RECOVERABLE_ITEM_STATES or item.state == "failed_permanent" for item in job.items) else "disabled"
        )

    def record_activity(self, level: str, message: str, operation: str = "system", filename: str = "", job_id: str = "") -> None:
        """Display a redacted event and enqueue its bounded history off Tk.

        Args:
            level (str): Event severity such as info, success, warning, or error.
            message (str): Event detail before credential redaction.
            operation (str): Owning activity category.
            filename (str): Optional local filename associated with the event.
            job_id (str): Optional durable import job identifier.

        Returns:
            None: Updates the footer, retained history, and persistence queue
                immediately, then coalesces the Activity drawer render.
        """
        secret = self.api_key_var.get().strip() if hasattr(self, "api_key_var") else ""
        # The queue marker has already selected the structured operation.
        if operation == "simconnect" and message.startswith("[simconnect]"):
            message = message[len("[simconnect]"):].lstrip()
        safe_message = redact_text(message, [secret], redact_paths=False)
        event = ActivityEvent(level=level, message=safe_message, operation=operation, filename=filename, job_id=job_id)
        one_line_message = " ".join(event.message.split())
        footer_text = f"{event.operation.title()} · {one_line_message}"
        self.latest_activity_var.set(footer_text[:LATEST_ACTIVITY_PREVIEW_LENGTH])
        if self.latest_activity_label is not None:
            footer_style = {
                "warning": "Footer.Warning.TLabel",
                "error": "Footer.Error.TLabel",
            }.get(event.level.lower(), "Footer.TLabel")
            self.latest_activity_label.configure(style=footer_style)
        self.activity_events.append(event)
        limit = max(50, int(getattr(self.config, "activity_history_limit", 500) or 500))
        self.activity_events = self.activity_events[-limit:]
        try:
            if not self.activity_writer.submit(event):
                logging.warning("Recent activity history is closing; event remains in the log.")
        except Exception:  # noqa: BLE001
            logging.error("Could not queue recent activity history.")
        self._schedule_activity_drawer_refresh()

    def _schedule_activity_drawer_refresh(self) -> None:
        """Coalesce drawer rendering while activity state and persistence stay current.

        Returns:
            None: Schedules one idle render of the latest retained activity.
        """
        if self.exiting or not hasattr(self, "activity_drawer"):
            return
        if self._activity_render_after_id is not None:
            return
        callback_id: Optional[str] = None

        def flush() -> None:
            """Dispatch the drawer refresh with its captured timer identity.

            Returns:
                None: Delegates identity validation to the owning method.
            """
            self._flush_activity_drawer_refresh(callback_id)

        callback_id = self.root.after_idle(flush)
        self._activity_render_after_id = callback_id

    def _flush_activity_drawer_refresh(
        self, callback_id: Optional[str],
    ) -> None:
        """Render the latest activity rows once after queued events settle.

        Args:
            callback_id (Optional[str]): Identity captured by the scheduled
                idle callback so a stale callback cannot clear a replacement.

        Returns:
            None: Clears the owned callback and refreshes the current filter.
        """
        if callback_id != self._activity_render_after_id:
            return
        self._activity_render_after_id = None
        if not self.exiting:
            self.refresh_activity_drawer()

    def _cancel_event_ui_callbacks(self) -> None:
        """Cancel owned event and activity callbacks before Tk shutdown.

        Returns:
            None: Clears both callback IDs after best-effort cancellation.
        """
        for attribute in ("_drain_after_id", "_activity_render_after_id"):
            callback_id = getattr(self, attribute, None)
            if callback_id is None:
                continue
            try:
                self.root.after_cancel(callback_id)
            except tk.TclError:
                pass
            setattr(self, attribute, None)

    def refresh_activity_drawer(self) -> None:
        """Render retained events with the current filter and cancel stale work.

        Returns:
            None: Cancels a pending coalesced render, updates the visible rows,
                and increments the drawer-render observation count.
        """
        callback_id = self._activity_render_after_id
        self._activity_render_after_id = None
        if callback_id is not None:
            try:
                self.root.after_cancel(callback_id)
            except tk.TclError:
                pass
        if not hasattr(self, "activity_drawer"):
            return
        self._activity_drawer_flush_count += 1
        for iid in self.activity_drawer.get_children():
            self.activity_drawer.delete(iid)
        selected = self.activity_filter_var.get().strip().lower() or "all"

        def visible(event: ActivityEvent) -> bool:
            level = event.level.lower()
            message = event.message.lower()
            if selected == "all":
                return True
            if selected == "warnings":
                return level == "warning"
            if selected == "failed":
                return level == "error" or "failed" in message
            if selected == "succeeded":
                return level == "success" or "uploaded" in message or "finished" in message or "stored" in message
            if selected == "skipped":
                return "skip" in message or "duplicate" in message
            if selected == "active":
                return any(token in message for token in ("started", "running", "uploading", "preflight", "paused", "stopping"))
            return True

        rows = [event for event in self.activity_events if visible(event)][-100:]
        for index, event in enumerate(reversed(rows)):
            stamp = time.strftime("%H:%M:%S", time.localtime(event.timestamp))
            filename = Path(event.filename).name if event.filename else ""
            self.activity_drawer.insert("", "end", iid=f"event-{index}-{int(event.timestamp * 1000)}", values=(stamp, event.level, filename, event.operation, event.message))

    def copy_diagnostics(self) -> None:
        """Copy redacted linking, updater, runtime and saved flight diagnostics.

        Returns:
            None: Copies bounded support snapshots to the clipboard and records
                the action without adding credentials or transport text.
        """
        job_summary = self.describe_job(self.current_job) if self.current_job is not None else "No selected job"
        capability_summary = ", ".join(
            f"{suffix}={'local' if capability.local_previewable else 'server-only'}"
            for suffix, capability in sorted(self.media_capabilities.items())
            if suffix in {".jpg", ".png", ".heic", ".heif", ".dng"}
        )
        recent = "\n".join(
            f"{time.strftime('%Y-%m-%d %H:%M:%S', time.localtime(event.timestamp))} [{event.level}/{event.operation}] {event.message}"
            for event in self.activity_events[-30:]
        )
        simconnect = getattr(self.worker, "simconnect_diagnostics", {"connection": "not queried", "last_result": "not requested"})
        updater_snapshot = getattr(
            getattr(self, 'updates', None), 'diagnostics_snapshot', None,
        )
        updater_diagnostics = (
            updater_snapshot() if callable(updater_snapshot)
            else 'No updater diagnostic event recorded.'
        )
        photos_snapshot = getattr(
            getattr(self, 'photos_workspace', None), 'diagnostics_snapshot', None,
        )
        photos_diagnostics = (
            photos_snapshot() if callable(photos_snapshot)
            else 'No Photos failure recorded for the current session.'
        )
        recorder = self.flights.recorder
        flight_progress = (
            recorder.diagnostics_snapshot() if recorder is not None
            else {"running": False, "sample_count": 0}
        )
        raw = "\n".join(
            [
                f"App: {APP_DISPLAY_NAME}",
                f"App version: {APP_VERSION}",
                f"Python: {sys.version.split()[0]}",
                f"Runtime: {'installed' if getattr(sys, 'frozen', False) else 'source'}",
                f"Executable: {Path(sys.executable).name}",
                f"Tk: {self.root.tk.call('info', 'patchlevel')}",
                "Pillow: " + (
                    str(getattr(Image, '__version__', 'installed'))
                    if Image is not None else 'unavailable'
                ),
                f"OS: {os.name}",
                f"Gallery URL: {self.gallery_url_var.get().strip()}",
                f"API key: {self.api_key_var.get().strip()}",
                f"Connection: {self.connection_status_var.get()} | {self.connection_detail_var.get()}",
                "Browser linking: " + (
                    self.linking_manager.diagnostics_snapshot()
                    or "No linking outcome recorded yet."
                ),
                "Updater diagnostics:\n" + updater_diagnostics,
                "Photos diagnostics:\n" + photos_diagnostics,
                f"Update continuation: {self._update_resume_status}",
                f"Watcher: {self.watcher_activity_var.get()}",
                f"UTC synchronization: {self.utc_sync.status()}",
                f"Import: {self.import_activity_var.get()} | {job_summary}",
                f"AI: {self.ai_activity_var.get()}",
                f"Media capabilities: {capability_summary}",
                "Event-loop observations: "
                f"last_batch_events={self._event_drain_last_count} "
                f"total_events={self._event_drain_total_count} "
                f"budget_yields={self._event_drain_yield_count} "
                f"last_batch_ms={self._event_drain_last_ms:.2f} "
                f"max_batch_ms={self._event_drain_max_ms:.2f} "
                f"activity_drawer_flushes={self._activity_drawer_flush_count}",
                f"Config path: {CONFIG_PATH}",
                f"State path: {STATE_PATH}",
                f"Log path: {LOG_PATH}",
                "SimConnect (last acquisition; connections close after each request):",
                json.dumps(simconnect, indent=2, ensure_ascii=False),
                "Flight recorder (independent aircraft telemetry):",
                json.dumps(flight_progress, indent=2, ensure_ascii=False),
                "Recent activity:",
                recent,
            ]
        )
        safe = redact_text(raw, [self.api_key_var.get().strip()], redact_paths=True)
        self.root.clipboard_clear()
        self.root.clipboard_append(safe)
        self.write_log("Redacted diagnostic details copied to clipboard.", "system")

    def refresh_watcher_header(self) -> None:
        """Show compact Watcher state, queue size and last scan artwork.

        Returns:
            None: Updates three icon groups and keeps full context in the hover
                tooltip without adding visible captions to the header.
        """
        status_icon = getattr(self, "_watcher_header_status_icon", None)
        count_var = getattr(self, "watcher_header_count_var", None)
        time_var = getattr(self, "watcher_header_time_var", None)
        if status_icon is None or count_var is None or time_var is None:
            return
        language = self.flights.ui_language.get()
        gallery_name = " ".join(self.gallery_title_var.get().split())
        queued = (
            str(self._watcher_queued_count)
            if self._watcher_queued_count is not None
            else "–"
        )
        state = self.watcher_activity_var.get().removeprefix("Watch: ")
        if getattr(self, "_watch_start_pending", False) is True:
            state = "starting"
        activity_key = {
            "running": "running", "starting": "starting",
            "resuming": "starting", "finishing": "finishing",
            "uploads pending": "pending", "stopped": "stopped",
        }.get(state, "stopped")
        activity = tr(language, "watcher_header_" + activity_key)
        worker = getattr(self, "worker", None)
        worker_active = bool(
            worker is not None and worker.is_alive()
            and not worker.stop_event.is_set()
            and not worker.drain_only and not worker.drain_event.is_set()
            and not worker._update_pause_requested()
        )
        preparing = activity_key in {"starting", "finishing", "pending"}
        if preparing:
            tone = "warning"
            hollow = True
            pulse = False
        elif worker_active:
            tone = "ink"
            hollow = False
            pulse = bool(getattr(self, "_watcher_recording_pulse", False))
        else:
            tone = "muted"
            hollow = True
            pulse = False
        cache_key = f"{tone}:{int(hollow)}:{int(pulse)}"
        icon_cache = getattr(self, "_watcher_header_icon_cache", None)
        icon = None
        if isinstance(icon_cache, dict):
            icon = icon_cache.get(cache_key)
            if icon is None:
                icon = create_smooth_icon(
                    status_icon,
                    "recording",
                    WATCHER_HEADER_ICON_SIZE,
                    tone=tone,
                    hollow=hollow,
                    pulse=pulse,
                )
                if icon is not None:
                    icon_cache[cache_key] = icon
        if icon is not None:
            status_icon.configure(image=icon)
            self._watcher_header_status_image = icon
        else:
            # The theme owns permanent optional-Pillow support for source-mode
            # repair. Native glyphs retain status meaning in that environment;
            # normal installed/source runs use the supersampled artwork above.
            status_icon.configure(
                image="", text="◌" if hollow else "●",
                foreground=_PALETTE[
                    "recording" if tone == "ink" else tone
                ],
            )
        count_var.set(queued)
        scanned_at = getattr(self, "_watcher_last_scan_at", None)
        scan_clock = (
            time.strftime("%H:%M", time.localtime(scanned_at))
            if scanned_at is not None else "–:–"
        )
        time_var.set(scan_clock)
        stop_button = getattr(self, "_watcher_header_stop_button", None)
        if stop_button is not None:
            if worker_active and not preparing:
                stop_button.configure(state="normal")
                if not stop_button.winfo_manager():
                    stop_button.pack(side="left", padx=(8, 0))
            else:
                stop_button.pack_forget()
        stop_hint = getattr(self, "_watcher_header_stop_hint", None)
        if stop_hint is not None:
            stop_hint.set(tr(language, "watcher_header_stop"))
        hint = getattr(self, "_watcher_header_hint", None)
        if hint is not None:
            queue_caption = tr(language, "watcher_header_queue")
            scan_caption = tr(language, "watcher_header_last_scan")
            missing_gallery = tr(
                language, "watcher_header_gallery_unavailable",
            )
            photos_caption = tr(language, "photos_pane_title")
            hint.set(
                f"{gallery_name or missing_gallery} · {activity}\n"
                f"{queue_caption}: {queued} {photos_caption}\n"
                f"{scan_caption}: {scan_clock}"
            )

    def refresh_activity_monitor_state(self) -> None:
        """
        Refresh independent watcher, import, AI, connection, and tray indicators.

        Returns:
            None: Synchronizes visible worker captions and status indicators.
        """
        watcher_running = bool(self.worker and self.worker.is_alive())
        if self.worker is not getattr(self, "_watcher_queue_worker", None):
            self._watcher_queued_count = None
            self._watcher_last_scan_at = None
            self._watcher_queue_worker = self.worker
        self.refresh_watcher_action()
        manual_running = bool(self.manual_worker and self.manual_worker.is_alive())
        ai_running = bool(self.ai_worker and self.ai_worker.is_alive() and not self.ai_worker_stop_requested)
        preflight_running = bool(self.preflight_worker and self.preflight_worker.is_alive())

        state = self.flights.session_state()
        if state == "working" and not self.flights.independent_operation():
            self.watcher_activity_var.set("Watch: finishing")
        elif watcher_running:
            self.watcher_activity_var.set("Watch: running")
        elif state == "pending":
            self.watcher_activity_var.set("Watch: uploads pending")
        else:
            self.watcher_activity_var.set("Watch: stopped")
        self.refresh_watcher_header()
        if preflight_running:
            self.import_activity_var.set("Import: preflight")
        elif manual_running:
            self.import_activity_var.set("Import: paused" if self.manual_worker and self.manual_worker.pause_event.is_set() else "Import: running")
        elif not self.import_activity_var.get().startswith("Import: failed"):
            self.import_activity_var.set("Import: idle")
        if ai_running:
            self.ai_activity_var.set("AI: running")
        elif self.ai_worker_stop_requested and self.ai_worker and self.ai_worker.is_alive():
            self.ai_activity_var.set("AI: stopping")
        else:
            self.ai_activity_var.set("AI: stopped")

        active = watcher_running or manual_running or ai_running or preflight_running
        self.update_monitor_state("green" if active else "disabled", "Background activity is running." if active else "No background worker is active.")
        if self.tray_icon is not None:
            try:
                self.tray_icon.title = self.tray_tooltip_text()
            except Exception:  # noqa: BLE001
                logging.debug("Could not refresh tray tooltip.", exc_info=True)

    def _schedule_event_drain(self, delay_ms: int) -> None:
        """Schedule the single owned event-pump callback when active.

        Args:
            delay_ms (int): Delay before the next event batch, in milliseconds.

        Returns:
            None: Stores one callback ID or leaves scheduling to the active pump.
        """
        if (self.exiting or self._drain_running
                or self._drain_after_id is not None):
            return
        callback_id: Optional[str] = None

        def drain() -> None:
            """Dispatch the pump with its captured timer identity.

            Returns:
                None: Delegates identity validation to the owning method.
            """
            self._run_scheduled_event_drain(callback_id)

        callback_id = self.root.after(delay_ms, drain)
        self._drain_after_id = callback_id

    def _run_scheduled_event_drain(
        self, callback_id: Optional[str],
    ) -> None:
        """Release the timer slot and run the next bounded FIFO batch.

        Args:
            callback_id (Optional[str]): Identity captured by the scheduled
                timer so a stale callback cannot clear a replacement.

        Returns:
            None: Clears the fired callback ID before dispatching events.
        """
        if callback_id != self._drain_after_id:
            return
        self._drain_after_id = None
        self.drain_events()

    def drain_events(self) -> None:
        """
        Run one cooperative batch of worker events and schedule its continuation.

        Returns:
            None: Preserves FIFO event handling and schedules one later batch.
        """
        if self.exiting or self._drain_running:
            return
        callback_id = self._drain_after_id
        self._drain_after_id = None
        if callback_id is not None:
            try:
                self.root.after_cancel(callback_id)
            except tk.TclError:
                pass
        self._drain_running = True
        budget_exhausted = False
        batch_started = time.perf_counter()
        try:
            budget_exhausted = self._drain_event_batch()
            if not self.exiting:
                self.refresh_contextual_watcher_copy()
                self.refresh_activity_monitor_state()
                self.refresh_revoke_button_state()
        finally:
            elapsed_ms = (time.perf_counter() - batch_started) * 1000.0
            self._event_drain_last_ms = elapsed_ms
            self._event_drain_max_ms = max(
                self._event_drain_max_ms, elapsed_ms,
            )
            if budget_exhausted:
                self._event_drain_yield_count += 1
            self._drain_running = False
            if not self.exiting:
                delay_ms = (
                    WATCHER_EVENT_CONTINUATION_MS
                    if budget_exhausted else WATCHER_EVENT_IDLE_POLL_MS
                )
                self._schedule_event_drain(delay_ms)

    def _drain_event_batch(self) -> bool:
        """Apply complete queued events until the cooperative batch budget ends.

        Returns:
            bool: True when the event count or time budget ended the batch;
                False when the queue was observed empty or shutdown began
                between complete events.
        """
        batch_started = time.perf_counter()
        processed_events = 0
        self._event_drain_last_count = 0
        while True:
            if self.exiting:
                return False
            elapsed_ms = (time.perf_counter() - batch_started) * 1000.0
            if (processed_events >= WATCHER_EVENT_BATCH_LIMIT
                    or elapsed_ms >= WATCHER_EVENT_BATCH_BUDGET_MS):
                return True
            try:
                event = self.events.get_nowait()
            except queue.Empty:
                break
            processed_events += 1
            self._event_drain_last_count += 1
            self._event_drain_total_count += 1

            if isinstance(event, dict):
                kind = str(event.get("kind", "") or "")
                payload = event.get("payload", {}) if isinstance(event.get("payload", {}), dict) else {}
                if (kind.startswith('watcher_resume_')
                        and 'generation' in payload
                        and payload.get('generation') != getattr(
                            self, '_update_resume_generation', 0)):
                    if kind == 'watcher_resume_prepared':
                        worker = payload.get('worker')
                        if (isinstance(worker, WatcherThread)
                                and worker._linked_lease is not None):
                            worker._linked_lease.release()
                        target = payload.get('target')
                        if (isinstance(target, LinkedUploadTarget)
                                and target is getattr(
                                    self, '_update_resume_context_target', None,
                                )):
                            self._close_update_resume_context_key()
                    continue
                if kind == 'gui_mode_initialized':
                    self._gui_mode_ready = payload.get('verified') is True
                    if self._gui_mode_ready:
                        self._inspect_update_continuation()
                    else:
                        self._update_resume_status = 'stage=resume; reason=mode_unverified'
                    continue
                if kind == 'ordinary_restart_ready':
                    self._ordinary_restart_prepared = True
                    self._ordinary_restart_closing = False
                    self.close()
                    continue
                if kind == 'ordinary_restart_failed':
                    self._ordinary_restart_closing = False
                    self._ordinary_restart_thread = None
                    self._cancel_ordinary_restart_intent()
                    self.cancel_update_checkpoint()
                    messagebox.showwarning(
                        'Watcher remains open',
                        'The active Watcher could not be safely checkpointed. '
                        'Its queue is retained; wait for recovery, then stop or retry.',
                    )
                    continue
                if kind == 'watcher_resume_available':
                    self._update_resume_inspecting = False
                    if (self.exiting or getattr(
                            self, '_update_resume_cancelled', False)):
                        continue
                    record = payload.get('continuation')
                    if isinstance(record, WatcherContinuation):
                        self._resume_continuation = record
                        self._ordinary_restart_resuming = (
                            payload.get('ordinary_restart') is True
                        )
                        self._resume_retry_available = True
                        self._update_resume_deadline = (
                            time.monotonic() + UPDATE_CONTINUATION_LINK_SECONDS
                        )
                        self._update_resume_status = 'stage=resume; reason=verifying_saved_target'
                        if payload.get('automatic') is True:
                            self._continue_update_resume()
                        else:
                            # Old versions never persisted whether a pending
                            # session was active at update time. Offer explicit
                            # continuation without inventing startup consent.
                            self._update_resume_status = 'stage=resume; reason=legacy_confirmation_required'
                            self.status_var.set(
                                'An older version left unfinished Watcher work. '
                                'Resume Watcher continues the original session.',
                            )
                            self.refresh_watcher_action()
                    continue
                if kind == 'watcher_resume_failed':
                    self._update_resume_inspecting = False
                    if (self.exiting or getattr(
                            self, '_update_resume_cancelled', False)):
                        continue
                    self._update_resume_failed(
                        str(payload.get('reason', 'continuation_unverified')),
                        stage=str(payload.get('stage', 'resume')),
                    )
                    continue
                if kind == 'watcher_resume_inspection_retry':
                    self._update_resume_inspecting = False
                    if (self.exiting or getattr(
                            self, '_update_resume_cancelled', False)):
                        continue
                    if time.monotonic() >= self._update_resume_deadline:
                        self._update_resume_failed(
                            'checkpoint_read_unavailable',
                            stage=str(payload.get('stage', 'checkpoint_read')),
                        )
                        continue
                    self._update_resume_attempted = False
                    self._update_resume_status = (
                        'stage=' + str(payload.get('stage', 'checkpoint_read'))
                        + '; reason=temporary_checkpoint_read_failure'
                    )
                    self.root.after(
                        UPDATE_CONTINUATION_POLL_MS,
                        self._inspect_update_continuation,
                    )
                    continue
                if kind == 'watcher_resume_retry':
                    if (self._resume_continuation is None or self.exiting
                            or getattr(self, '_update_resume_cancelled', False)
                            or not self._update_resume_preparing):
                        continue
                    self._update_resume_preparing = False
                    if time.monotonic() >= self._update_resume_deadline:
                        self._update_resume_failed(
                            'saved_gallery_access_unavailable',
                            stage='target_verification',
                        )
                        continue
                    self._update_resume_status = (
                        'stage=target_verification; '
                        'reason=temporary_target_verification_failure'
                    )
                    self.status_var.set(
                        'The original gallery is temporarily unavailable. '
                        'Retrying safely…',
                    )
                    self.root.after(
                        UPDATE_CONTINUATION_TARGET_RETRY_MS,
                        self._continue_update_resume,
                    )
                    continue
                if kind == 'watcher_resume_target':
                    if (self.exiting or getattr(
                            self, '_update_resume_cancelled', False)):
                        continue
                    target = payload.get('target')
                    if isinstance(target, LinkedUploadTarget):
                        self._accept_update_resume_target(target)
                    continue
                if kind == 'watcher_resume_prepared':
                    record, worker, recorder, target = (
                        payload.get('continuation'), payload.get('worker'),
                        payload.get('recorder'), payload.get('target'),
                    )
                    if (isinstance(record, WatcherContinuation)
                            and isinstance(worker, WatcherThread)
                            and isinstance(recorder, FlightRecorder)
                            and (target is None or isinstance(target, LinkedUploadTarget))):
                        self._start_prepared_update_resume(record, worker, recorder, target)
                    continue
                if kind in ("watcher_start_ready", "watcher_start_finished"):
                    if payload.get("worker") is not self.worker:
                        continue
                    if kind == "watcher_start_ready":
                        self._set_watch_start_activity(False)
                        self.watch_ignored_var.set(
                            f"Ignored at startup: {payload['ignored']}"
                        )
                        if (self.worker.is_alive()
                                and not self.worker.stop_event.is_set()
                                and not self.worker.drain_event.is_set()):
                            if not self._complete_update_resume():
                                continue
                            self.status_var.set("Running")
                            self.watcher_activity_var.set("Watch: running")
                            self._set_watcher_recording(True, self.worker.linked_target)
                            self.write_log("Watcher started.", "success")
                    elif (getattr(self, '_resume_continuation', None) is not None
                            and getattr(self, '_update_resume_preparing', False)):
                        reason = (
                            'saved_session_needs_review'
                            if self.worker.drain_error
                            else 'continuation_worker_stopped'
                        )
                        self._update_resume_failed(reason)
                    elif (self.worker.drain_error
                            or not self.worker.initial_captured
                            or getattr(self, "_watch_start_pending", False) is True):
                        self._set_watch_start_activity(
                            False, self.worker.drain_error
                            or "Watcher stopped before capture began.",
                        )
                    self.refresh_watcher_action()
                    continue
                if 'preflight_owner' in payload:
                    worker = self.preflight_worker
                    if worker is None or payload['preflight_owner'] is not worker._preflight_owner:
                        continue
                    if (payload.get('config_generation') is None
                            or payload['config_generation'] is not self.config._snapshot_token
                            or (not payload.get('linked_binding') and (
                                normalize_upload_url(self.gallery_url_var.get())
                                != normalize_upload_url(self.config.gallery_url)
                                or self.api_key_var.get().strip() != self.config.api_key.strip()
                            ))):
                        if kind in ('preflight_complete', 'preflight_failed', 'preflight_cancelled'):
                            self.preflight_progress.stop()
                            self.preflight_progress.grid_remove()
                            self.preflight_worker = None
                            self._preflight_approved = None
                            self.manual_status_var.set('Preflight needs to be run again')
                            self.manual_preflight_var.set(
                                'Configuration changed; run preflight again. Sources were retained.',
                            )
                        continue
                if ('linked_binding' in payload and payload['linked_binding']
                        != self.linking_manager.linked_upload_binding()):
                    continue
                job_id = str(event.get("job_id", payload.get("job_id", "")) or "")
                if kind == "preflight_started":
                    self.manual_status_var.set("Preflight running")
                    self.import_activity_var.set("Import: preflight")
                elif kind == "preflight_progress":
                    current = int(payload.get("current", 0) or 0)
                    total = max(1, int(payload.get("total", 1) or 1))
                    filename = str(payload.get("filename", "") or "")
                    self.manual_preflight_var.set(f"Inspecting {current}/{total}: {filename}")
                elif kind == "preflight_complete":
                    plan = payload.get("plan")
                    self.preflight_progress.stop()
                    self.preflight_progress.grid_remove()
                    self.preflight_worker = None
                    if isinstance(plan, ImportPlan):
                        binding = payload.get('preflight_binding')
                        if not isinstance(binding, str) or re.fullmatch(r'[0-9a-f]{64}', binding) is None:
                            self._preflight_approved = None
                            self.manual_status_var.set('Preflight needs to be run again')
                            continue
                        self._preflight_approved = (
                            plan.job.id, payload['config_generation'], binding,
                        )
                        self.manual_plan = plan
                        self.current_job = plan.job
                        self.render_preflight_plan(plan)
                        self.refresh_job_history()
                        self.write_log("Import preflight completed. Review the summary before starting.", "success")
                        self.record_activity("success", "Import preflight completed and is awaiting explicit Start import.", "import", job_id=plan.job.id)
                    self.manual_status_var.set("Ready for review")
                elif kind == "preflight_failed":
                    self.preflight_progress.stop()
                    self.preflight_progress.grid_remove()
                    self.preflight_worker = None
                    message = str(payload.get("message", "Preflight failed") or "Preflight failed")
                    self.manual_preflight_var.set("Preflight failed: " + message)
                    self.manual_status_var.set("Preflight failed")
                    self.import_activity_var.set("Import: failed preflight")
                    self.write_log("Import preflight failed: " + message, "error")
                    self.record_activity("error", "Import preflight failed: " + message, "import", job_id=job_id)
                elif kind == "preflight_cancelled":
                    self.preflight_progress.stop()
                    self.preflight_progress.grid_remove()
                    self.preflight_worker = None
                    self.manual_status_var.set("Preflight cancelled")
                    self.import_activity_var.set("Import: idle")
                elif kind in ("watch_scan", "watch_queue"):
                    if payload.get("worker") is self.worker:
                        if self._watcher_queue_worker is not self.worker:
                            self._watcher_queued_count = None
                            self._watcher_last_scan_at = None
                        self._watcher_queue_worker = self.worker
                        count = payload.get("queued_count")
                        if type(count) is int and count >= 0:
                            self._watcher_queued_count = count
                            self.refresh_activity_monitor_state()
                    if kind == "watch_scan":
                        if payload.get("worker") is self.worker:
                            timestamp = float(payload.get("timestamp", time.time()) or time.time())
                            self._watcher_last_scan_at = timestamp
                            self.watch_last_scan_var.set("Last successful scan: " + time.strftime("%Y-%m-%d %H:%M:%S", time.localtime(timestamp)))
                            self.refresh_watcher_header()
                elif kind in {"connection_ready", "connection_warning", "connection_error"}:
                    explicit = bool(payload.get("explicit_test", False))
                    if explicit:
                        self.connection_test_running = False
                    generation = payload.get('config_generation')
                    if (generation is None
                            or generation is not self.config._snapshot_token
                            or (not payload.get('linked_binding') and (
                                self.linking_manager.linked_upload_mode_selected()
                                or self.linking_manager.selected_upload_target()
                                is not None
                                or normalize_upload_url(
                                    self.gallery_url_var.get(),
                                )
                                != normalize_upload_url(self.config.gallery_url)
                                or self.api_key_var.get().strip() != self.config.api_key.strip()
                            ))):
                        if explicit:
                            self.refresh_connection_controls()
                        continue
                    if kind == "connection_ready":
                        label = str(payload.get("gallery_label", "gallery") or "gallery")
                        resolved_url = str(payload.get("normalized_url", "") or "")
                        self.gallery_label_var.set("Target gallery: " + label)
                        self.gallery_title_var.set(
                            str(payload.get("gallery_title", "") or "").strip()
                        )
                        self.connection_status_var.set("Connected")
                        self.connection_detail_var.set("Authenticated endpoint available: " + resolved_url)
                        if explicit:
                            saved_url = self.config.gallery_url.strip()
                            saved_key = self.config.api_key.strip()
                            current_url = self.gallery_url_var.get().strip()
                            current_key = self.api_key_var.get().strip()
                            if (
                                resolved_url
                                and resolved_url != saved_url
                                and current_url == saved_url
                                and current_key == saved_key
                                and not getattr(self, "api_key_revoke_running", False)
                            ):
                                resolved_config = replace(self.config, gallery_url=resolved_url)
                                try:
                                    resolved_config = self._save_owned_config(resolved_config)
                                    self.config = resolved_config
                                    self.gallery_url_var.set(resolved_url)
                                    self.write_log("Working Gallery API route detected and saved: " + resolved_url, "system")
                                except Exception as exc:  # noqa: BLE001
                                    self.write_log("Connection succeeded, but saving the working API route failed: " + str(exc), "warning")
                            self.write_log("Connection test succeeded for gallery " + label + ".", "success")
                            self.record_activity("success", "Connection test succeeded for gallery " + label + ".", "system")
                        if (not payload.get('linked_binding')
                                and not self.api_key_revoke_running):
                            saved_url = normalize_upload_url(
                                self.config.gallery_url,
                            )
                            saved_key = self.config.api_key.strip()
                            if (saved_url and saved_key
                                    and normalize_upload_url(
                                        self.gallery_url_var.get(),
                                    ) == saved_url
                                    and self.api_key_var.get().strip() == saved_key):
                                self._verified_legacy_gallery = (
                                    self.config._snapshot_token,
                                    connection_binding(saved_url, saved_key),
                                    label,
                                    str(payload.get('gallery_title', '') or '').strip(),
                                )
                            else:
                                self._verified_legacy_gallery = None
                    elif kind == "connection_warning":
                        self.connection_status_var.set("Connection warning")
                        self.connection_detail_var.set(str(payload.get("message", "Remote preflight unavailable") or "Remote preflight unavailable"))
                    else:
                        message = str(payload.get("message", "Connection failed") or "Connection failed")
                        auth_error = any(token in message.lower() for token in ("api key", "invalid or revoked", "unauthorized", "forbidden", "http 401", "http 403"))
                        self.connection_status_var.set("Authentication error" if auth_error else "Connection failed")
                        self.connection_detail_var.set(message)
                        self.write_log("Connection test failed: " + message, "error")
                        self.record_activity("error", "Connection test failed: " + message, "system")
                elif kind == "flight_abandoned":
                    self.flights.forgotten(str(payload.get("binding", "")))
                elif kind == "photos_watch_key_closed":
                    if payload.get("target") is self._contextual_watch_target:
                        self._watcher_key_closing = False
                        if payload.get("closed") is True:
                            target = self._contextual_watch_target
                            self._contextual_watch_target = None
                            self._contextual_watch_is_flight = False
                            self.linking_manager.detach_photos_watcher_target(target)
                        else:
                            self.status_var.set("Watcher access could not be closed. Retry.")
                        self.refresh_watcher_action()
                elif kind == 'update_resume_context_key_closed':
                    target = payload.get('target')
                    if isinstance(target, LinkedUploadTarget):
                        self._finish_update_resume_context_key_close(
                            target, payload.get('closed') is True,
                        )
                elif kind == "saved_linked_mode_ready":
                    self.linked_target_changed()
                elif kind == "linking_resume_startup":
                    payload = event.get("payload", {})
                    issuer = payload.get("issuer") if isinstance(payload, dict) else None
                    if isinstance(issuer, str) and issuer:
                        self.linking_manager.resume_saved_link(issuer)
                    else:
                        self.linking_manager.resume_recent_links()
                elif kind == "api_key_revoked":
                    operation = payload.get('operation')
                    if isinstance(operation, FinalizationWorker):
                        self.complete_legacy_revocation(operation)
                elif kind == "api_key_revoke_error":
                    self.api_key_revoke_running = False
                    self.api_key_revoke_thread = None
                    message = str(payload.get("message", "API-key revocation failed.") or "API-key revocation failed.")
                    self.connection_status_var.set("Revocation failed")
                    self.connection_detail_var.set(message)
                    self.write_log("API-key revocation failed: " + message, "error")
                    self.record_activity("error", "API-key revocation failed: " + message, "system")
                    self.refresh_connection_controls()
                    messagebox.showerror("Revoke API key failed", message)
                elif kind == "job_progress":
                    if self.current_job is not None and (not job_id or self.current_job.id == job_id):
                        refreshed = self.job_store.get_job(self.current_job.id)
                        if refreshed is not None:
                            self.current_job = refreshed
                        job = self.current_job
                        counts = job.counts()
                        terminal = counts["uploaded"] + counts["duplicates"] + counts["failed"] + counts["cancelled"] + counts["skipped"]
                        total = max(1, len(job.items) + job.skipped)
                        percent = min(100.0, terminal * 100.0 / total)
                        self.manual_progress_var.set(percent)
                        elapsed = max(0.001, time.time() - job.started_at) if job.started_at else 0.0
                        rate = job.bytes_sent / elapsed if elapsed else 0.0
                        remaining = max(0, job.bytes_total - job.bytes_sent)
                        eta = (remaining / rate) if rate > 0 else 0.0
                        rate_text = f"{self.format_bytes(int(rate))}/s" if rate > 0 else "rate pending"
                        eta_text = f"ETA {int(eta)}s" if eta > 0 else "ETA pending"
                        self.manual_progress_text_var.set(f"{terminal}/{total} terminal | {self.format_bytes(job.bytes_sent)} / {self.format_bytes(job.bytes_total)} | {rate_text} | {eta_text}")
                        self.activity_job_summary_var.set(self.describe_job(job))
                        self.refresh_import_item_tree(job)
                        self.refresh_job_history()
                elif kind == "job_control":
                    paused = bool(payload.get("paused", False))
                    self.manual_status_var.set("Import paused" if paused else "Import running")
                    self.import_activity_var.set("Import: paused" if paused else "Import: running")
                    self.pause_import_button.configure(state="disabled" if paused else "normal")
                    self.resume_import_button.configure(state="normal" if paused else "disabled")
                elif kind == "thumbnail_fallback":
                    filename = str(payload.get("filename", "") or "")
                    reason = str(payload.get("reason", "") or "")
                    message = f"Local thumbnail fallback for {filename}: {reason}. Server generation remains authoritative."
                    self.write_log(message, "warning")
                    self.record_activity("warning", message, "import", filename=filename, job_id=job_id)
                self.refresh_revoke_button_state()
                self.refresh_activity_monitor_state()
                continue

            if not isinstance(event, tuple) or len(event) != 2:
                self.write_log("Ignored malformed worker event.", "warning")
                continue
            level, message = str(event[0]), str(event[1])
            log_level = self.classify_log_level(level, message)
            operation = "system"
            if message.startswith("[simconnect]"):
                operation = "simconnect"
            elif message.startswith(("Manual ", "Uploading ", "Uploaded ", "Skipped already present", "Generated ", "Local thumbnails")):
                operation = "import" if (self.manual_worker and self.manual_worker.is_alive()) or message.startswith("Manual ") else "watcher"
            elif message.startswith(("Watching ", "Watcher ", "Ignoring ", "Flight Simulator")):
                operation = "watcher"
            elif message.startswith("AI metadata"):
                operation = "ai"
            # Classify the original queue text before removing its routing marker.
            display_message = message[len("[simconnect]"):].lstrip() if operation == "simconnect" else message
            self.write_log(f"{level.upper()}: {display_message}", log_level)
            self.record_activity(log_level if log_level in {"success", "warning", "error"} else level, message, operation, job_id=self.current_job.id if operation == "import" and self.current_job else "")

            if message.startswith("Manual upload started"):
                self.manual_status_var.set("Import running")
                self.import_activity_var.set("Import: running")
            elif message.startswith(("Manual upload finished", "Manual upload stopped")):
                if self.current_job is not None:
                    refreshed = self.job_store.get_job(self.current_job.id)
                    if refreshed is not None:
                        self.current_job = refreshed
                    self.refresh_import_item_tree(self.current_job)
                    self.activity_job_summary_var.set(self.describe_job(self.current_job))
                failed = self.current_job.counts()["failed"] if self.current_job else 0
                cancelled = self.current_job.counts()["cancelled"] if self.current_job else 0
                self.manual_status_var.set("Import finished" if message.startswith("Manual upload finished") else "Import cancelled")
                self.import_activity_var.set("Import: idle" if not failed else "Import: failed items")
                self.manual_progress_var.set(100.0 if not failed and not cancelled else self.manual_progress_var.get())
                self.start_import_button.configure(state="disabled")
                self.pause_import_button.configure(state="disabled")
                self.resume_import_button.configure(state="disabled")
                self.cancel_import_button.configure(state="disabled")
                self.retry_failed_button.configure(state="normal" if (failed or cancelled) else "disabled")
                self.refresh_job_history()
            elif message.startswith("Watcher stopped"):
                self.status_var.set("Stopped")
                self.watcher_activity_var.set("Watch: stopped")
            elif message.startswith("Watching "):
                self.status_var.set("Running")
                self.watcher_activity_var.set("Watch: running")
                self.watch_last_scan_var.set("Last scan: " + time.strftime("%Y-%m-%d %H:%M:%S"))
            elif message.startswith("AI metadata worker claimed job"):
                self.ai_status_var.set(message)
                self.ai_activity_var.set("AI: working")
            elif message.startswith("AI metadata stored"):
                self.ai_status_var.set("AI metadata worker running")
                self.ai_activity_var.set("AI: running")
            elif message.startswith("AI metadata worker stopped"):
                self.ai_worker_stop_requested = False
                self.ai_status_var.set("AI metadata worker stopped")
                self.ai_activity_var.set("AI: stopped")
            elif message.startswith("AI metadata worker started"):
                self.ai_worker_stop_requested = False
                self.ai_status_var.set("AI metadata worker running")
                self.ai_activity_var.set("AI: running")
            if message.startswith("Optional local AI module dependency installation finished"):
                self.semantic_ai_install_running = False
                if self.semantic_ai_install_button is not None:
                    self.semantic_ai_install_button.configure(state="normal")

        return False

    def configure_log_tags(self) -> None:
        """Configure readable semantic log colors on the dark diagnostics panel.

        Returns:
            None: Registers each level's body and prefix styles once.
        """
        if self.log_tags_ready:
            return
        self.log_text.tag_configure("timestamp", foreground="#AABCCB")
        self.log_text.tag_configure("system", foreground="#D2DFE9")
        self.log_text.tag_configure("success", foreground="#46D68C")
        self.log_text.tag_configure("warning", foreground="#F2C581")
        self.log_text.tag_configure("error", foreground="#FF91A2")
        self.log_text.tag_configure("debug", foreground="#95A9BA")
        # Keep compact bold prefixes in the same installed family as the UI.
        family = str(self.root.tk.call(
            "font", "actual", "TkDefaultFont", "-family",
        ))
        prefix_font = (family, 9, "bold")
        self.log_text.tag_configure(
            "prefix_system", foreground="#D2DFE9", font=prefix_font,
        )
        self.log_text.tag_configure(
            "prefix_success", foreground="#46D68C", font=prefix_font,
        )
        self.log_text.tag_configure(
            "prefix_warning", foreground="#F2C581", font=prefix_font,
        )
        self.log_text.tag_configure(
            "prefix_error", foreground="#FF91A2", font=prefix_font,
        )
        self.log_text.tag_configure(
            "prefix_debug", foreground="#95A9BA", font=prefix_font,
        )
        self.log_tags_ready = True

    def update_monitor_state(self, state: str, detail: str) -> None:
        """
        Update the small monitoring light and its text labels.

        Args:
            state (str): One of disabled, green, or red.
            detail (str): Short human-readable explanation.

        Returns:
            None: Update the small monitoring light and its text labels.
        """
        state = state if state in {"disabled", "green", "red"} else "red"
        self.monitor_state = state
        self.monitor_detail = detail
        palette = {
            "disabled": ("#c0c0c0", "#8a8a8a", "Monitoring disabled"),
            "green": ("#4caf50", "#2e7d32", "Monitoring active"),
            "red": ("#ef5350", "#b71c1c", "Monitoring error"),
        }
        fill, outline, label = palette[state]
        self.monitor_state_var.set(label)
        self.monitor_detail_var.set(detail)
        self.monitor_light.delete("all")
        self.monitor_light.create_oval(2, 2, 14, 14, fill=fill, outline=outline, width=1)

    def classify_log_level(self, level: str, message: str) -> str:
        """
        Convert watcher events into readable log colors.

        Args:
            level (str): Original worker severity.
            message (str): Event text.

        Returns:
            str: Tk text tag name.
        """
        lower = message.lower()
        if level == "error":
            return "error"
        if level == "warning":
            return "warning"
        if any(lower.startswith(prefix) for prefix in ["watching ", "upload endpoint:", "watcher started", "configuration saved", "manual upload started", "manual upload worker started", "manual upload finished", "ai metadata worker started", "ai metadata stored"]):
            return "success"
        if lower.startswith("uploaded ") or lower.startswith("skipped duplicate content") or lower.startswith("generated "):
            return "success"
        if lower.startswith("manual upload stopped") or lower.startswith("watcher stopped") or lower.startswith("ai metadata worker stopped"):
            return "system"
        return "system"

    def write_log(self, message: str, tag: str = "system") -> None:
        """
        Append one secret-safe line to the status log.

        Args:
            message (str): Message text to append.
            tag (str): Log color tag name.

        Returns:
            None: Append one secret-safe line to the status log.
        """
        secret = self.api_key_var.get().strip() if hasattr(self, "api_key_var") else ""
        safe_message = redact_text(message, [secret], redact_paths=False)
        logging.info("UI: %s", redact_text(safe_message, [secret], redact_paths=False))
        if not hasattr(self, "log_text"):
            return
        stamp = time.strftime("%H:%M:%S")
        self.log_text.configure(state="normal")
        self.log_text.insert("end", "[", ("timestamp",))
        self.log_text.insert("end", stamp, ("timestamp",))
        self.log_text.insert("end", "] ", ("timestamp",))
        self.log_text.insert("end", safe_message, (tag if tag in self.log_text.tag_names() else "system",))
        self.log_text.insert("end", "\n")
        self.log_text.see("end")
        self.log_text.configure(state="disabled")

    def run(self) -> None:
        """
        Run the Tkinter event loop.
        """
        self.root.mainloop()


def run_once(config: WatcherConfig, config_store: ConfigStore) -> int:
    """
    Run one scan without showing the GUI.

    This is mostly useful for testing and scheduled execution. Note that the
    live watcher behavior of ignoring pre-existing files is not applied here
    unless initial_paths is explicitly populated before calling scan_once().

    Args:
        config (WatcherConfig): Configuration to use for the scan.
        config_store (ConfigStore): Owner of the exact loaded configuration;
            mode evidence is checked before constructing the worker.

    Returns:
        int: Zero after a completed scan; one if evidence or work fails.
    """
    try:
        if type(config_store) is not ConfigStore:
            raise ValueError('Configuration owner is unavailable.')
        coordinator = ModeCoordinator(
            config_store.mode_port(config), WindowsUploadModeStore(),
        )
        view = coordinator.refresh()
        with coordinator.acquire_work(view, 'legacy') as work:
            setup_logging()
            events: "queue.Queue[Tuple[str, str]]" = queue.Queue()
            worker = WatcherThread(config, events, once_work=work)
            folder = Path(config.watched_folder)
            upload_url = normalize_upload_url(config.gallery_url)
            worker.scan_once(folder, upload_url)

            while not events.empty():
                level, message = events.get()
                print(f"{level.upper()}: {message}")
        return 0
    except Exception:  # noqa: BLE001
        # Never expose config contents or interpret failed evidence as key mode.
        if sys.stderr is not None:
            print('Upload mode or one-scan work could not be verified.', file=sys.stderr)
        return 1


def parse_args(argv: List[str]) -> argparse.Namespace:
    """
    Parse optional command-line switches.

    Args:
        argv (List[str]): Command-line argument list without the executable
            name.

    Returns:
        argparse.Namespace: once/startup/configure_startup boolean mode flags,
            optional after_update nonce and config string path; operating modes
            are mutually exclusive.
    """
    parser = argparse.ArgumentParser(description="Watch a folder and upload new images to PHP Gallery.")
    modes = parser.add_mutually_exclusive_group()
    modes.add_argument("--once", action="store_true", help="Run one scan using saved configuration and exit.")
    modes.add_argument(
        "--startup", action="store_true",
        help="Start the installed app in the tray after Windows logon.",
    )
    modes.add_argument(
        "--configure-startup", action="store_true",
        help="Apply the saved per-user Windows startup preference without a UI.",
    )
    modes.add_argument('--after-update', metavar='SESSION', help=argparse.SUPPRESS)
    parser.add_argument("--config", default=str(CONFIG_PATH), help="Path to a config JSON file.")
    result = parser.parse_args(argv)
    if (result.after_update is not None
            and re.fullmatch(r'[0-9a-f]{32}', result.after_update) is None):
        parser.error('Invalid update restart identity.')
    return result


def main(argv: Optional[List[str]] = None) -> int:
    """
    Application entry point.

    Args:
        argv (Optional[List[str]]): Optional command-line argument list. When
            omitted, sys.argv is.

    Returns:
        int: Zero after a normal exit, activation or startup reconciliation;
            one if coordination or configuration fails, or --once overlaps a
            running app.
    """
    args = parse_args(list(argv) if argv is not None else sys.argv[1:])
    instance = WindowsInstance(CONFIG_DIR)
    try:
        if not instance.acquire():
            if args.once:
                return 1
            if not args.startup and not args.configure_startup:
                instance.request_activation()
            return 0
        # A second launcher never opens/rotates logs or reads/writes app state.
        setup_logging()
        store = ConfigStore(args.config)
        config = store.load()
        if args.once:
            return run_once(config, store)
        if args.configure_startup:
            synchronize_startup(config.start_with_windows)
            return 0
        if args.startup and not config.start_with_windows:
            synchronize_startup(False)
            return 0
        if args.after_update is None:
            app = WatcherApp(config_store=store, start_minimized=args.startup)
        else:
            app = WatcherApp(
                config_store=store, start_minimized=False,
                after_update=args.after_update,
            )
        app.instance = instance
        app.root.after(ACTIVATION_POLL_MS, app.poll_instance_activation)
        app.run()
        return 0
    except OSError as exc:
        # Windowed builds may have no stderr. Native coordination failure must
        # never admit a second writer, and needs a visible manual-launch error.
        if not args.configure_startup and not args.once and messagebox is not None:
            messagebox.showerror("Application startup", str(exc))
        elif sys.stderr is not None:
            print("Application startup failed: " + str(exc), file=sys.stderr)
        return 1
    finally:
        instance.close()


if __name__ == "__main__":
    multiprocessing.freeze_support()
    raise SystemExit(main())
