# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/ui/support.py
# Module Type: Windows Client UI support
# Purpose: Own shared presentation helpers and UI-only bounded policies.
# Responsibilities: Provide frame, activity, layout, tray, and label helpers.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Shared presentation helpers for Watcher UI owners."""

from dataclasses import dataclass, field
from typing import Callable, List, Tuple

from uploader.watcher import runtime, state as watcher_state
from uploader.config import worker_choices
from uploader.localization import DEFAULT_LANGUAGE, current_language, gettext, gettext_noop
from uploader.flight_report_translations import tr
from uploader.models import ActivityEvent
from uploader.simconnect_time import utc_status_caption
from uploader.ui_layout import WATCHER_WINDOW_MINIMUM
from uploader.ui_theme import paint_backdrop
from uploader.tk_scroll_input import TK_WHEEL_DELTA_UNITS as WINDOWS_MOUSEWHEEL_DELTA

# Purpose: Keep queue reasons readable inside the compact native Watcher card.
# Type: int. Units: pixels. Scope: the queue status label.
# Consumers: WatcherApp.build_watch_tab.
# Rationale: 420 wraps long retry/review explanations within narrow desktop layouts.
WATCH_QUEUE_STATUS_WRAP_PIXELS = 420


# Purpose: Bound durable jobs rendered in the Activity history table.
# Type: int.
# Units: visible Treeview rows.
# Scope: newest-first import-job history.
# Consumers: WatcherApp.refresh_job_history.
# Rationale: Preserve the existing 200-job history view cap.
WATCHER_JOB_HISTORY_TREE_ROW_LIMIT = 200


# Purpose: Bound events rendered in the filtered Activity drawer.
# Type: int.
# Units: visible Treeview rows.
# Scope: one activity filter selection.
# Consumers: WatcherApp.refresh_activity_drawer.
# Rationale: Preserve the existing 100-event drawer cap.
WATCHER_ACTIVITY_DRAWER_ROW_LIMIT = 100


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
    except (runtime.tk.TclError, TypeError, ValueError):
        bounds = (0, 0, 0, 0)
    if bounds[2] > 0 and bounds[3] > 0:
        return bounds
    try:
        return (0, 0, int(root.winfo_screenwidth()), int(root.winfo_screenheight()))
    except (runtime.tk.TclError, TypeError, ValueError):
        return (0, 0, WATCHER_WINDOW_MINIMUM[0], WATCHER_WINDOW_MINIMUM[1])


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
    if runtime.ImageDraw is None:
        return icon_image

    width, height = icon_image.size
    radius = max(
        TRAY_BADGE_MIN_RADIUS,
        min(width, height) // TRAY_BADGE_RADIUS_DIVISOR,
    )
    inset = TRAY_BADGE_EDGE_INSET
    draw = runtime.ImageDraw.Draw(icon_image)
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


def selected_image_filetypes() -> List[Tuple[str, str]]:
    """
    Return file picker filters for manual image selection.

    Returns:
        List[Tuple[str, str]]: Tkinter-compatible file type filters.
    """
    return [
        (gettext("Image files"), "*.jpg *.jpeg *.png *.gif *.webp *.heic *.heif *.dng"),
        ("JPEG", "*.jpg *.jpeg"),
        ("PNG", "*.png"),
        ("WebP", "*.webp"),
        (gettext("All files"), "*.*"),
    ]


def worker_choice_values(maximum: int) -> List[str]:
    """
    Build human-readable worker choices for the Tkinter comboboxes.

    Args:
        maximum (int): Highest explicit worker value to offer.

    Returns:
        List[str]: List containing Auto plus numeric worker counts.
    """
    return [label for _count, label in worker_choices(maximum)]


def parse_worker_choice(value: str) -> int:
    """
    Convert a UI worker choice into the persisted integer representation.

    Args:
        value (str): Combobox text, either Auto or a positive integer.

    Returns:
        int: Zero for Auto, otherwise a positive integer.
    """
    text = str(value).strip()
    if not text or text.casefold() in ("auto", gettext("Auto").casefold()):
        return 0
    return int(text)


def format_worker_choice(value: int) -> str:
    """
    Convert a persisted worker value into combobox text.

    Args:
        value (int): Stored worker value, where zero means Auto.

    Returns:
        str: Combobox text.
    """
    if int(value) <= 0:
        return gettext("Auto")
    return str(int(value))


def activity_filter_labels() -> dict[str, str]:
    """Describe each Activity filter without changing stored event keys.

    Returns:
        dict[str, str]: Stable filter codes mapped to their visible captions.
    """
    return {
        "all": gettext("all"),
        "active": gettext("active"),
        "succeeded": gettext("succeeded"),
        "skipped": gettext("skipped"),
        "failed": gettext("failed"),
        "warnings": gettext("warnings"),
    }


def activity_label(code: str, *, title: bool = False) -> str:
    """Resolve display captions for stable Activity categories and severities.

    Args:
        code (str): Persisted level or operation, such as error or watcher.
        title (bool): Use the uppercased category caption in the footer.

    Returns:
        str: Localized Activity caption, with unknown operation codes intact.
    """
    captions = {
        "info": gettext_noop("info"), "system": gettext_noop("system"),
        "warning": gettext_noop("warning"), "error": gettext_noop("error"),
        "success": gettext_noop("success"),
        "watcher": gettext_noop("watcher"), "import": gettext_noop("import"),
        "simconnect": gettext_noop("simconnect"), "ai": gettext_noop("ai"),
        "linking": gettext_noop("linking"),
    }
    if title:
        captions = {
            "system": gettext_noop("System"), "watcher": gettext_noop("Watcher"),
            "import": gettext_noop("Import"), "simconnect": gettext_noop("Simconnect"),
            "ai": gettext_noop("Ai"), "linking": gettext_noop("Linking"),
        }
    return gettext(captions.get(code, code.title() if title else code))


class ScrollableFrame(runtime.ttk.Frame if runtime.ttk is not None else object):
    """Simple ttk scroll container used by the redesigned task views."""

    def __init__(self, parent: "tk.Misc") -> None:
        """Create a themed vertical canvas-backed frame.

        Args:
            parent (tk.Misc): Tk widget that owns the scroll container.

        Returns:
            None: Creates the canvas and content frame; the scrollbar appears
                only while the page exceeds its visible viewport.
        """
        if runtime.ttk is None or runtime.tk is None:
            raise RuntimeError("Tkinter is not available.")
        super().__init__(parent, style="Backdrop.TFrame")
        self.canvas = runtime.tk.Canvas(
            self,
            highlightthickness=0,
            borderwidth=0,
            background=self.winfo_toplevel().cget("background"),
        )
        self.scrollbar = runtime.ttk.Scrollbar(self, orient="vertical", command=self.canvas.yview)
        self.content = runtime.ttk.Frame(self.canvas, style="Backdrop.TFrame")
        self.window_id = self.canvas.create_window((0, 0), window=self.content, anchor="nw")
        paint_backdrop(self.content)
        self.canvas.configure(yscrollcommand=self._set_scrollbar)
        self.canvas.pack(side="left", fill="both", expand=True)
        self.content.bind("<Configure>", self._sync_scrollregion, add="+")
        self.canvas.bind("<Configure>", self._sync_width, add="+")
        self._scrollbar_range: tuple[str, str] | None = None
        self._scrollregion: tuple[int, int, int, int] | None = None
        self._content_width: int | None = None
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
            None: Publishes changed fractions and maps the scrollbar as needed.
        """
        fractions = (first, last)
        if fractions == self._scrollbar_range:
            return
        self.scrollbar.set(first, last)
        self._scrollbar_range = fractions
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
        if runtime.tk is None or runtime.ttk is None:
            return None
        if isinstance(widget, (runtime.tk.Text, runtime.tk.Listbox, runtime.ttk.Treeview, runtime.ttk.Combobox)):
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
            None: Publishes only changed bounds for the retained window item.
        """
        bounds = self.canvas.bbox(self.window_id)
        if bounds is not None and bounds != self._scrollregion:
            self.canvas.configure(scrollregion=bounds)
            self._scrollregion = bounds

    def _sync_width(self, event: "tk.Event[tk.Misc]") -> None:
        """Keep the embedded content frame at least as wide as the viewport.

        Args:
            event (tk.Event[tk.Misc]): Canvas resize event with the viewport
                width in pixels.

        Returns:
            None: Publishes a changed width once even on height-only events.
        """
        width = max(1, int(event.width))
        if width != self._content_width:
            self.canvas.itemconfigure(self.window_id, width=width)
            self._content_width = width


@dataclass
class _TreeRowSnapshot:
    """Retain bounded projected rows and Activity objects for one Treeview."""

    rows: Dict[str, Tuple[str | int | float, ...]]
    order: List[str]
    retained_activity_events: Tuple[ActivityEvent, ...] = field(
        default_factory=tuple,
    )


@dataclass(slots=True)
class _ImportViewProjection:
    """Retain bounded rows while each visible import pane catches up separately."""

    latest: ImportProgressSnapshot | None = None
    rows: dict[int, ImportItemView] = field(default_factory=dict)
    import_dirty: dict[int, ImportItemView] = field(default_factory=dict)
    activity_dirty: dict[int, ImportItemView] = field(default_factory=dict)
    full_panes: set[ImportDetailPane] = field(default_factory=set)
    presented_revisions: dict[ImportDetailPane, int] = field(default_factory=dict)

    def update(self, snapshot: ImportProgressSnapshot) -> None:
        """Fold one fenced revision into both panes' bounded pending changes.

        Args:
            snapshot (ImportProgressSnapshot): Next accepted worker revision or
                full synchronization of a newly selected durable job.

        Returns:
            None: Retains current rows even when neither pane is visible.
        """
        self.latest = snapshot
        if snapshot.complete_rows and len(snapshot.items) != len(self.rows):
            self.rows = {row.source_index: row for row in snapshot.items}
            self.full_panes.update(("import", "activity"))
            self.import_dirty.clear()
            self.activity_dirty.clear()
            return
        for row in snapshot.items:
            index = row.source_index
            if self.rows.get(index) != row:
                self.rows[index] = row
                self.import_dirty[index] = row
                self.activity_dirty[index] = row


def _publish_watcher_value(
    host: "WatcherApp",
    slot: str,
    target: "tk.Misc | tk.Variable",
    value: "str | bool | tuple[str, str]",
    publish: Callable[[], None],
) -> None:
    """Apply one owned Watcher presentation value when its target or value changes.

    Args:
        host (WatcherApp): Tk owner retaining the latest successfully published values.
        slot (str): Stable field or widget property owned by this projection.
        target (tk.Misc | tk.Variable): Current Tk object for that slot.
        value (str | bool | tuple[str, str]): Desired text, flag or paired options.
        publish (Callable[[], None]): Apply the supplied value to the current target.

    Returns:
        None: The target is synchronized at most once for this value and identity.
    """
    rendered = host.__dict__.setdefault("_published_watcher_values", {})
    previous = rendered.get(slot)
    if previous is not None and previous[0] is target and previous[1] == value:
        return
    publish()
    rendered[slot] = (target, value)


def _publish_watcher_text(host: "WatcherApp", slot: str, value: str) -> None:
    """Set text on a Watcher-owned display variable only on a real change.

    Args:
        host (WatcherApp): Tk owner of the display variable.
        slot (str): Attribute holding a StringVar written by this projection.
        value (str): Current display text in the applicable language or context.

    Returns:
        None: The current variable has the requested text.
    """
    variable = getattr(host, slot)
    _publish_watcher_value(host, slot, variable, value, lambda: variable.set(value))


def watch_queue_state_caption(state: str) -> str:
    """Describe the head of the FIFO in the current UI language.

    Args:
        state (str): Stable queue state published by the current worker.

    Returns:
        str: Display caption, with a generic value for an unrecognized state.
    """
    return {
        "idle": gettext("Idle"),
        "observed": gettext("Detected"),
        "stabilizing": gettext("Waiting for the file to settle"),
        "preparing": gettext("Preparing"),
        "inventory": gettext("Checking the gallery inventory"),
        "dispatching": gettext("uploading"),
        "retry_wait": gettext("Waiting to retry"),
        "auth_required": gettext("Gallery access required"),
        "uncertain": gettext("Upload outcome uncertain"),
        "blocked": gettext("Blocked"),
        "acknowledged": gettext("Confirmed"),
        "skipped": gettext("skipped"),
    }.get(state, gettext("Unknown state"))


def operational_activity_caption(event: ActivityEvent) -> str:
    """Describe familiar worker activity without rewriting its diagnostic data.

    Args:
        event (ActivityEvent): Redacted event from the live or retained history.

    Returns:
        str: Localized operator summary, or the original detail for an
            unrecognized external or historical diagnostic.
    """
    message = event.message
    if event.operation == "simconnect" and message.startswith("[time] "):
        return utc_status_caption(message.removeprefix("[time] "))
    if event.operation in ("watcher", "import"):
        if message.lstrip().startswith("Watching "):
            return gettext("Watching {folder}").format(
                folder=message.lstrip().removeprefix("Watching "),
            )
        if message.startswith("Skipped already present on gallery: "):
            return gettext("Already in the gallery: {filename}").format(
                filename=message.removeprefix("Skipped already present on gallery: "),
            )
        if message.startswith("Manual upload started: "):
            return gettext("Manual import started. Files: {count}").format(
                count=message.removeprefix("Manual upload started: ").removesuffix(" file(s)."),
            )
        if message.startswith("Manual upload finished: "):
            return gettext("Manual import finished.")
        if message.startswith("Manual upload stopped: "):
            return gettext("Manual import stopped.")
        if message.startswith("Uploaded "):
            return gettext("Uploaded file: {filename}").format(
                filename=message.removeprefix("Uploaded ").split(": ", 1)[0],
            )
        if message.startswith("Uploading "):
            return gettext("Upload in progress")
    if event.operation == "ai" and message.startswith("AI metadata worker claimed job "):
        return gettext("AI metadata worker is processing a job")
    first_line, newline, detail = message.partition("\n")
    for key in ("simbrief_loading", "simbrief_loaded", "simbrief_staged_loaded"):
        if first_line == tr(DEFAULT_LANGUAGE, key):
            return tr(current_language(), key) + newline + detail
    return message
