# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_lightbox.py
# Module Type: Windows Client UI
# Purpose: Present one selected gallery photo in a focused native overlay.
# Responsibilities:
#   - Fit prepared photos, trim clear black bars, and provide compact controls.
#   - Navigate photos, zoom and pan, and reveal an explicit location action.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Native photo-first lightbox overlay for the linked gallery viewer."""

import math
import sys
import tkinter as tk
from tkinter import ttk
from typing import TYPE_CHECKING, Callable
import webbrowser

from .gallery_hdr_macos import MacHDRSurface
from .ui_theme import hide_inline_tooltip, icon_button
from .tk_scroll_input import vertical_scroll_delta

if TYPE_CHECKING:
    from PIL import Image as PillowImage

try:
    from PIL import Image, ImageTk
except ImportError:
    Image = None
    ImageTk = None


# Purpose: Bound the displayed raster to a practical desktop viewport area.
# Type: int; Units: pixels; Scope: one resampled lightbox image.
# Consumers: PhotoLightbox._render_image.
# Rationale: 12 megapixels retain detail while limiting Tk bitmap memory.
LIGHTBOX_MAX_PIXELS = 12_000_000

# Purpose: Keep resizing and zooming responsive on ordinary laptop displays.
# Type: float; Units: multiplier; Scope: one image zoom operation.
# Consumers: PhotoLightbox._render_image.
# Rationale: 3x fit scale allows useful inspection without huge raster copies.
LIGHTBOX_MAX_ZOOM = 3.0

# Purpose: Coalesce rapid Configure events into one image resampling operation.
# Type: int; Units: milliseconds; Scope: one lightbox geometry change burst.
# Consumers: PhotoLightbox._queue_render.
# Rationale: 90 ms avoids repeated full bitmap resampling during window drags.
LIGHTBOX_RESIZE_DEBOUNCE_MS = 90

# Purpose: Bound the interval between low-cost live resize previews.
# Type: int; Units: milliseconds; Scope: one active window resize gesture.
# Consumers: PhotoLightbox._queue_render. Rationale: 33 ms targets a responsive
# 30 Hz preview while the trailing LANCZOS render preserves settled quality.
LIGHTBOX_RESIZE_PREVIEW_MS = 33

# Purpose: Reserve room for toolbar and edge breathing space in the photo fit.
# Type: tuple[int, int]; Units: pixels; Scope: photo viewport fit calculation.
# Consumers: PhotoLightbox._render_image.
# Rationale: 48 horizontal and 92 vertical pixels clear compact overlay chrome.
LIGHTBOX_VIEWPORT_INSETS = (48, 92)

# Purpose: Set the proportional step for each discrete zoom gesture.
# Type: float; Units: multiplier; Scope: one wheel or toolbar zoom step.
# Consumers: PhotoLightbox zoom controls.
# Rationale: 1.25 is a useful detail step without abrupt scale jumps.
LIGHTBOX_ZOOM_STEP = 1.25

# Purpose: Provide a consistent margin for the contextual map panel.
# Type: int; Units: pixels; Scope: lightbox location panel viewport clamp.
# Consumers: PhotoLightbox._toggle_location.
# Rationale: 8 px prevents the compact panel from touching overlay edges.
LIGHTBOX_PANEL_EDGE_GAP = 8

# Purpose: Keep the location panel separated from its triggering pin control.
# Type: int; Units: pixels; Scope: lightbox location panel placement.
# Consumers: PhotoLightbox._toggle_location.
# Rationale: 6 px leaves a visible anchor gap without detaching the panel.
LIGHTBOX_PANEL_ANCHOR_GAP = 6

# Purpose: Set the detail level used by the explicit OpenStreetMap action.
# Type: int; Units: OSM zoom level; Scope: user-requested map URL only.
# Consumers: PhotoLightbox._open_map.
# Rationale: 15 shows a useful neighborhood scale around a photo pin.
LIGHTBOX_MAP_ZOOM = 15

# Purpose: Set each mouse-wheel zoom increment.
# Type: float; Units: multiplier; Scope: one wheel notch.
# Consumers: PhotoLightbox._wheel.
# Rationale: 1.1 supports fine detail adjustment without sudden jumps.
LIGHTBOX_WHEEL_STEP = 1.1

# Purpose: Normalize Windows wheel and macOS trackpad deltas before zooming.
# Type: int. Units: delta units per zoom step. Scope: one wheel gesture.
# Consumers: PhotoLightbox._wheel.
# Rationale: Windows uses 120 per notch; macOS wheel reports small notch deltas.
LIGHTBOX_WHEEL_DELTA_UNITS = 120

# Purpose: Accumulate smooth touchpad movement into a measured zoom increment.
# Type: int. Units: touchpad delta units per zoom step. Scope: lightbox zoom.
# Consumers: PhotoLightbox._apply_zoom_delta.
# Rationale: Eight small deltas avoid rerendering for every high-rate packet.
LIGHTBOX_TOUCHPAD_ZOOM_DELTA_UNITS = 8

# Purpose: Hide fullscreen controls after the pointer has been still briefly.
# Type: int. Units: milliseconds. Scope: one fullscreen lightbox.
# Consumers: PhotoLightbox._schedule_control_hide.
# Rationale: 1400 ms leaves time to use compact controls without covering photos.
LIGHTBOX_CONTROLS_IDLE_MS = 1400

# Purpose: Bound the title row before the stable icon control strip.
# Type: int; Units: character columns; Scope: lightbox toolbar title label.
# Consumers: PhotoLightbox.present.
# Rationale: 22 columns keep the ordinal and controls visible at minimum width.
LIGHTBOX_TITLE_COLUMNS = 22

# Purpose: Bound matte detection on each individual image edge.
# Type: float; Units: fraction of image edge; Scope: one normalized source.
# Consumers: detect_black_matte_crop. Rationale: 0.30 matches the web
# detector's cap and includes cinematic letterboxing and 4:3 pillarboxing.
LIGHTBOX_BLACK_TRIM_MAX_FRACTION = 0.30

# Purpose: Cap the temporary crop-detection sample on its longest axis.
# Type: int; Units: pixels; Scope: one source photo's matte scan.
# Consumers: detect_black_matte_crop. Rationale: 256 keeps Python
# classification bounded to 65,536 pixels while Pillow downsamples in C.
LIGHTBOX_BLACK_TRIM_SAMPLE_EDGE = 256

# Purpose: Set the maximum near-black channel value admitted in matte lines.
# Type: int; Units: 8-bit channel values; Scope: sampled edge pixels.
# Consumers: detect_black_matte_crop. Rationale: 20 tolerates faint
# compression noise while excluding most dark photographic content.
LIGHTBOX_BLACK_TRIM_CHANNEL_MAX = 20

# Purpose: Reject candidate matte lines with a raised mean brightness.
# Type: float; Units: 8-bit channel values; Scope: sampled edge line.
# Consumers: detect_black_matte_crop. Rationale: 12 separates black
# padding from uniformly dark gray backgrounds.
LIGHTBOX_BLACK_TRIM_MEAN_MAX = 12.0

# Purpose: Reject texture within candidate near-black matte lines.
# Type: float; Units: squared 8-bit channel values; Scope: sampled edge line.
# Consumers: detect_black_matte_crop. Rationale: 36 matches a six-level
# standard-deviation ceiling and avoids cropping textured night scenes.
LIGHTBOX_BLACK_TRIM_VARIANCE_MAX = 36.0

# Purpose: Require a visible sampled matte before removing any edge.
# Type: int; Units: sampled pixels; Scope: each candidate image edge.
# Consumers: detect_black_matte_crop. Rationale: Three lines filter
# isolated dark borders and single-line resampling artifacts.
LIGHTBOX_BLACK_TRIM_MIN_BAND = 3

# Purpose: Require visible scene detail immediately inside a candidate matte.
# Type: tuple[int, float]. Units: channel level and pixel share.
# Scope: one sampled content line. Consumers: detect_black_matte_crop.
# Rationale: Level 40 across 35% of a line rejects dark frames and stray text.
LIGHTBOX_MATTE_BRIGHT_CONTENT = (40, 0.35)

# Purpose: Admit textured, dim scenes next to confirmed opposing mattes.
# Type: tuple[int, float, int]. Units: level, pixel share and inner level spread.
# Scope: one sampled content line. Consumers: detect_black_matte_crop.
# Rationale: Level 24 across 15% and a spread of 12 within the central
# brightness range require texture beyond sparse pixels on the opposing edges.
LIGHTBOX_MATTE_DIM_CONTENT = (24, 0.15, 12)

# Purpose: Confirm a sustained content transition past blended boundary pixels.
# Type: tuple[int, int]. Units: sampled lines. Scope: one candidate matte edge.
# Consumers: detect_black_matte_crop. Rationale: Two blended lines are
# allowed before requiring two consecutive lines of visible scene detail.
LIGHTBOX_MATTE_TRANSITION_LINES = (2, 2)

# Purpose: Admit only confirmed near-black runs in the flat-scene fallback.
# Type: float. Units: peak channel level. Scope: interior side-matte samples.
# Consumers: detect_black_matte_crop. Rationale: 3 keeps the paired-edge
# fallback limited to black padding after excluding its blended boundary.
LIGHTBOX_FLAT_MATTE_INTERIOR_MAX = 3.0

# Purpose: Include a compressed or blended outer line in a flat side matte.
# Type: float. Units: mean channel level. Scope: candidate side-matte lines.
# Consumers: detect_black_matte_crop. Rationale: 10 admits edge compression
# while remaining below the confirmed scene-transition level.
LIGHTBOX_FLAT_MATTE_EDGE_MAX = 10.0

# Purpose: Require visible content transition beyond a flat side matte.
# Type: float. Units: mean channel levels. Scope: two adjacent scene lines.
# Consumers: detect_black_matte_crop. Rationale: 10 levels exceeds observed
# edge noise while admitting the dim, low-texture real gallery scenes.
LIGHTBOX_FLAT_MATTE_RISE_MIN = 10.0

# Purpose: Keep opposing flat-matte candidates geometrically consistent.
# Type: int. Units: sampled columns. Scope: paired side-matte thicknesses.
# Consumers: detect_black_matte_crop. Rationale: Two columns allow compression
# variation while rejecting unrelated dark content on one side.
LIGHTBOX_FLAT_MATTE_BALANCE = 2


def detect_black_matte_crop(
    image: "PillowImage.Image",
) -> tuple[int, int, int, int]:
    """Return bounds of clearly separated black mattes on a bounded sample.

    Args:
        image (PillowImage.Image): Source raster inspected without mutation.
    Returns:
        tuple[int, int, int, int]: Crop bounds in original-image pixels.
    """
    if Image is None:
        return (0, 0, image.width, image.height)
    # Type: int. Units: pixels. Scope: each sampled edge dimension.
    # Consumers: bounded matte scan. Rationale: caps Python work at 65,536
    # sampled pixels while Pillow performs the source reduction in C.
    sample_edge = LIGHTBOX_BLACK_TRIM_SAMPLE_EDGE
    scale = min(1.0, sample_edge / max(image.width, image.height))
    sample_size = (max(1, round(image.width * scale)),
                   max(1, round(image.height * scale)))
    if image.mode == "RGB":
        sample = image.resize(sample_size, Image.Resampling.BOX)
    else:
        with image.convert("RGB") as converted:
            sample = converted.resize(sample_size, Image.Resampling.BOX)
    levels = list(map(max, sample.getdata()))
    sample.close()
    width, height = sample_size

    def sample_line(vertical: bool, index: int,
                    start: int, end: int) -> list[int]:
        """Read peak RGB levels across one sampled candidate line.

        Args:
            vertical (bool): Whether the candidate is a column.
            index (int): Sample row or column index.
            start (int): Inclusive span start on the perpendicular axis.
            end (int): Exclusive span end on the perpendicular axis.
        Returns:
            list[int]: Peak channel level for each sampled pixel.
        """
        if vertical:
            return levels[start * width + index:end * width + index:width]
        row = index * width
        return levels[row + start:row + end]

    def edge_count(vertical: bool, reverse: bool,
                   start: int, end: int) -> int:
        """Count one edge's matte lines before a sustained content edge.

        Args:
            vertical (bool): Whether to scan a column edge.
            reverse (bool): Whether to scan from the far edge.
            start (int): Inclusive perpendicular span start.
            end (int): Exclusive perpendicular span end.
        Returns:
            int: Matte thickness, or zero without a confirmed content edge.
        """
        extent = width if vertical else height
        limit = min(int(extent * LIGHTBOX_BLACK_TRIM_MAX_FRACTION),
                    extent - 1)
        count = 0
        for distance in range(limit):
            index = extent - 1 - distance if reverse else distance
            values = sample_line(vertical, index, start, end)
            mean = sum(values) / len(values)
            variance = sum((value - mean) ** 2 for value in values) / len(values)
            if (max(values) > LIGHTBOX_BLACK_TRIM_CHANNEL_MAX
                    or mean > LIGHTBOX_BLACK_TRIM_MEAN_MAX
                    or variance > LIGHTBOX_BLACK_TRIM_VARIANCE_MAX):
                break
            count += 1
        if count == limit or count < LIGHTBOX_BLACK_TRIM_MIN_BAND:
            return 0

        bright_level, bright_share = LIGHTBOX_MATTE_BRIGHT_CONTENT
        dim_level, dim_share, dim_spread = LIGHTBOX_MATTE_DIM_CONTENT
        max_ramp, depth = LIGHTBOX_MATTE_TRANSITION_LINES
        stop = min(count + max_ramp + 1, extent - depth + 1)
        for offset in range(count, stop):
            for distance in range(offset, offset + depth):
                index = extent - 1 - distance if reverse else distance
                values = sample_line(vertical, index, start, end)
                bright = sum(value >= bright_level for value in values)
                if bright >= len(values) * bright_share:
                    continue
                dim = sum(value >= dim_level for value in values)
                if dim < len(values) * dim_share:
                    break
                ranked = sorted(values)
                tail = int(len(ranked) * dim_share)
                if ranked[-tail - 1] - ranked[tail] < dim_spread:
                    break
            else:
                return count
        return 0

    top = edge_count(False, False, 0, width)
    bottom = edge_count(False, True, 0, width)
    if not (top and bottom):
        top = bottom = 0
    else:
        top = round(top * image.height / height)
        bottom = round(bottom * image.height / height)
    content_top = round(top * height / image.height)
    content_bottom = height - round(bottom * height / image.height)
    left = edge_count(True, False, content_top, content_bottom)
    right = edge_count(True, True, content_top, content_bottom)
    if not (left and right):
        def flat_side_edge(reverse: bool) -> int:
            """Find a black side run with a sustained, flat content transition.

            Args:
                reverse (bool): Whether to scan inward from the right edge.
            Returns:
                int: Confirmed matte width, or zero without sufficient proof.
            """
            extent = width
            limit = min(
                int(extent * LIGHTBOX_BLACK_TRIM_MAX_FRACTION), extent - 1,
            )
            means: list[float] = []
            for distance in range(limit):
                index = extent - 1 - distance if reverse else distance
                values = sample_line(True, index, content_top, content_bottom)
                mean = sum(values) / len(values)
                if mean > LIGHTBOX_FLAT_MATTE_EDGE_MAX:
                    break
                means.append(mean)
            count = len(means)
            if (count < LIGHTBOX_BLACK_TRIM_MIN_BAND or count >= limit):
                return 0
            interior = means[:-1]
            interior_mean = sum(interior) / len(interior)
            if interior_mean > LIGHTBOX_FLAT_MATTE_INTERIOR_MAX:
                return 0
            boundary_mean = means[-1]
            _ramp, depth = LIGHTBOX_MATTE_TRANSITION_LINES
            for distance in range(count, count + depth):
                if distance >= extent:
                    return 0
                index = extent - 1 - distance if reverse else distance
                values = sample_line(True, index, content_top, content_bottom)
                mean = sum(values) / len(values)
                if mean - boundary_mean < LIGHTBOX_FLAT_MATTE_RISE_MIN:
                    return 0
            return count

        left_candidate = flat_side_edge(False)
        right_candidate = flat_side_edge(True)
        if (left_candidate and right_candidate
                and abs(left_candidate - right_candidate)
                <= LIGHTBOX_FLAT_MATTE_BALANCE):
            left = left_candidate
            right = right_candidate
    if not (left and right):
        left = right = 0
    else:
        # Round detected side mattes outward so a subpixel sample fringe stays
        # outside the retained content. At sample size these bounds are exact.
        left = math.ceil(left * image.width / width)
        right = math.ceil(right * image.width / width)
    if not (top or bottom or left or right):
        return (0, 0, image.width, image.height)
    return (left, top, image.width - right, image.height - bottom)


class PhotoLightbox:
    """Manage a photo overlay, compact controls and optional location panel.

    Args:
        window (tk.Misc): Main-window widget hosting fullscreen behavior.
        text (Callable[[str], str]): Localization lookup for short UI labels.
        on_move (Callable[[int], None]): Select previous (-1) or next (+1) photo.
        on_close (Callable[[], None]): Restore the parent viewer after dismissal.
        content_owner (tk.Misc): Root's managed content frame, remapped after
            the root-sized lightbox closes to refresh native drawing.

    Returns:
        None: The lightbox overlay and its event handlers are initialized.
    """

    def __init__(self, window: tk.Misc, text: Callable[[str], str],
                 on_move: Callable[[int], None],
                 on_close: Callable[[], None],
                 content_owner: tk.Misc) -> None:
        """Create a hidden overlay beside the root's main content frame.

        Args:
            window (tk.Misc): Main window that owns the root-sized overlay.
            text (Callable[[str], str]): Localized text lookup by stable key.
            on_move (Callable[[int], None]): Select previous (-1) or next (+1).
            on_close (Callable[[], None]): Restore the grid after dismissal.
            content_owner (tk.Misc): Root's directly managed application frame.
        Returns:
            None: The inline overlay widgets are initialized.
        """
        self.parent = window
        self.window = window.winfo_toplevel()
        self.content_owner = content_owner
        self.text = text
        self.on_move = on_move
        self.on_close = on_close
        self.visible = False
        self._active = False
        self._fullscreen = False
        self._fullscreen_restore: str | bool | None = None
        self._content_manager: str | None = None
        self._content_geometry: dict[str, int | str | tk.Misc] | None = None
        self._location: tuple[float, float] | None = None
        self._zoom = 1.0
        self._fullscreen_center: tuple[float, float] | None = None
        self._source_image = None
        self._crop_box: tuple[int, int, int, int] | None = None
        self._content_crop_enabled = True
        self._photo = None
        self._status_message: str | None = None
        self._status_fill = "#AABCCB"
        self._status_label: ttk.Label | None = None
        self._pending_header: tuple[str, str] | None = None
        self._resize_job: str | None = None
        self._resize_preview_job: str | None = None
        self._pan_origin: tuple[int, int, float, float] | None = None
        self._wheel_remainder = 0
        self._touchpad_remainder = 0
        self._control_hide_job: str | None = None
        self._controls_visible = False
        self._overlay = tk.Frame(window, bg="#0B1018")
        self._window_configure_binding = self.window.bind(
            "<Configure>", self._on_window_configure, add="+",
        )
        self._canvas = tk.Canvas(
            self._overlay, bg="#0B1018", highlightthickness=0,
            takefocus=True,
        )
        self._canvas.pack(fill="both", expand=True)
        self._hdr_surface = MacHDRSurface(self._canvas)
        self._toolbar = ttk.Frame(self._overlay, style="Glass.TFrame")
        self._heading = ttk.Frame(self._toolbar, style="TFrame")
        self._heading.pack(fill="x", padx=6, pady=(3, 0))
        self._title = ttk.Label(
            self._heading, text="", style="Muted.TLabel",
            width=LIGHTBOX_TITLE_COLUMNS,
        )
        self._title.pack(side="left", padx=(3, 4), pady=2)
        self._position = ttk.Label(
            self._heading, text="", style="Muted.TLabel",
        )
        self._position.pack(side="left", padx=4)
        self._controls = ttk.Frame(self._toolbar, style="TFrame")
        self._controls.pack(fill="x", padx=5, pady=(0, 3))
        self._buttons: list[ttk.Button] = []
        self._previous_edge = ttk.Frame(self._overlay, style="TFrame")
        self._previous_button = icon_button(
            self._previous_edge, "‹", lambda: self.on_move(-1),
            self.text("previous"),
            style_override="Photos.Icon.TButton",
            tooltip_surface=self._overlay,
        )
        self._previous_button.pack()
        self._add_button(
            "zoom_out", lambda: self._change_zoom(1 / LIGHTBOX_ZOOM_STEP),
        )
        self._fit_tooltip = tk.StringVar(
            master=self._overlay, value=self.text("show_original_frame"),
        )
        self._fit_button = self._add_button(
            "fit", self._fit_image, tooltip=self._fit_tooltip,
        )
        self._add_button(
            "zoom_in", lambda: self._change_zoom(LIGHTBOX_ZOOM_STEP),
        )
        self._add_button("fullscreen", self._toggle_fullscreen)
        self._map_button = self._add_button("location", self._toggle_location)
        self._next_edge = ttk.Frame(self._overlay, style="TFrame")
        self._next_button = icon_button(
            self._next_edge, "›", lambda: self.on_move(1), self.text("next"),
            style_override="Photos.Icon.TButton",
            tooltip_surface=self._overlay,
        )
        self._next_button.pack()
        self._toolbar.bind("<Motion>", self._on_pointer_motion, add="+")
        self._previous_button.bind(
            "<Motion>", self._on_pointer_motion, add="+",
        )
        self._next_button.bind("<Motion>", self._on_pointer_motion, add="+")
        self._show_controls()
        self._add_button("close", self.dismiss)
        self._location_panel = ttk.Frame(self._overlay, style="Glass.TFrame")
        self._location_label = ttk.Label(
            self._location_panel, text="", style="Muted.TLabel",
        )
        self._location_label.pack(side="left", padx=8, pady=5)
        self._map_status = ttk.Label(
            self._location_panel, text="", style="Muted.TLabel",
        )
        self._map_action = ttk.Button(
            self._location_panel, text=self.text("open_map"),
            command=self._open_map, style="Photos.Icon.TButton",
        )
        self._map_action.pack(side="left", padx=(0, 6), pady=3)
        self._overlay.bind("<Configure>", self._queue_render, add="+")
        self._canvas.bind("<ButtonPress-1>", self._pan_start, add="+")
        self._canvas.bind("<B1-Motion>", self._pan_motion, add="+")
        self._canvas.bind("<Motion>", self._on_pointer_motion, add="+")
        self._canvas.bind("<MouseWheel>", self._wheel, add="+")
        self._bind_precise_scroll()
        self._canvas.bind(
            "<Button-4>", self._wheel,
            add="+",
        )
        self._canvas.bind(
            "<Button-5>", self._wheel,
            add="+",
        )

    def _bind_precise_scroll(self) -> None:
        """Bind high-resolution scrolling only when this Tk exposes it.

        Returns:
            None: Legacy Tk keeps its ordinary MouseWheel and button bindings.
        """
        try:
            available = bool(self.window.tk.call(
                "info", "commands", "tk::PreciseScrollDeltas",
            ))
            if available:
                self._canvas.bind(
                    "<TouchpadScroll>", self._touchpad_scroll, add="+",
                )
        except tk.TclError:
            return

    def _add_button(self, key: str, command: Callable[[], None],
                    tooltip: str | tk.StringVar | None = None) -> ttk.Button:
        """Create one localized glass icon control in the lightbox toolbar.

        Args:
            key (str): Translation key and semantic theme icon name.
            command (Callable[[], None]): Action invoked by the control.
            tooltip (str | tk.StringVar | None): Optional fixed or live help.
        Returns:
            ttk.Button: The created toolbar action.
        """
        glyphs = {
            "previous": "‹", "zoom_out": "−", "fit": "□",
            "zoom_in": "+", "fullscreen": "⛶", "location": "⌖",
            "next": "›", "close": "close",
        }
        button = icon_button(
            self._controls,
            glyphs[key],
            command,
            tooltip if tooltip is not None else self.text(key),
            style_override="Photos.Icon.TButton",
            tooltip_surface=self._overlay,
        )
        button.pack(side="left", padx=1, pady=2)
        self._buttons.append(button)
        button.bind("<Motion>", self._on_pointer_motion, add="+")
        return button

    def present(self, title: str, position: str, *, can_previous: bool,
                can_next: bool) -> None:
        """Show loading state for a newly selected photo.

        Args:
            title (str): Selected photo's concise display title.
            position (str): Localized position such as “3 of 12”.
            can_previous (bool): Whether the previous-photo action is available.
            can_next (bool): Whether the next-photo action is available.
        Returns:
            None: Overlay is raised and its image state is reset.
        """
        if not self._active:
            self._suspend_content()
        self._active = True
        self.visible = True
        self._clear_location()
        self._location_panel.place_forget()
        self._zoom = 1.0
        self._fullscreen_center = None
        self._pan_origin = None
        clipped_title = title
        if len(clipped_title) > LIGHTBOX_TITLE_COLUMNS:
            clipped_title = clipped_title[:LIGHTBOX_TITLE_COLUMNS - 1] + "…"
        self._pending_header = (clipped_title, position)
        if self._source_image is None:
            self._apply_pending_header()
        self._previous_button.configure(state="normal" if can_previous else "disabled")
        self._next_button.configure(state="normal" if can_next else "disabled")
        self._show_status(self.text("loading"), "#AABCCB")
        self._overlay.place(relx=0, rely=0, relwidth=1, relheight=1)
        self._overlay.lift()
        self._overlay.update_idletasks()
        self._draw_status()
        self._canvas.focus_set()
        self._refresh_location()

    def _apply_pending_header(self) -> None:
        """Apply the pending selection label when its raster is ready.

        Returns:
            None: Title and ordinal match the current or failed photo pixels.
        """
        header = self._pending_header
        if header is None:
            return
        self._title.configure(text=header[0])
        self._position.configure(text=header[1])
        self._pending_header = None

    def _suspend_content(self) -> None:
        """Unmap the main content owner before raising the root-sized overlay.

        Returns:
            None: Current geometry options are retained for restoration.
        """
        hide_inline_tooltip(self.window)
        manager = self.content_owner.winfo_manager()
        if manager not in ("pack", "grid", "place"):
            return
        self._content_manager = manager
        self._content_geometry = getattr(
            self.content_owner, f"{manager}_info",
        )()
        getattr(self.content_owner, f"{manager}_forget")()

    def _restore_content(self) -> None:
        """Restore the main content with its original geometry manager.

        Returns:
            None: The saved content mapping is reapplied once when available.
        """
        manager = self._content_manager
        geometry = self._content_geometry
        self._content_manager = None
        self._content_geometry = None
        if manager is None or geometry is None:
            return
        try:
            if not self.content_owner.winfo_exists():
                return
            getattr(self.content_owner, manager)(**geometry)
        except tk.TclError:
            return

    def show_prepared_image(
        self,
        image: "PillowImage.Image",
        location: tuple[float, float] | None = None,
        encoded: bytes | None = None,
    ) -> None:
        """Take ownership of a worker-prepared RGB image and display it.

        Args:
            image (PillowImage.Image): Bounded RGB image transferred to the
                lightbox, which closes it when the selection changes.
            location (tuple[float, float] | None): Admitted latitude/longitude.
            encoded (bytes | None): Original bounded image bytes for native HDR.
        Returns:
            None: Image is fitted or covered to the active viewport.
        """
        if not self._active or ImageTk is None:
            image.close()
            return
        self._clear_image()
        self._status_message = None
        self._location = location
        self._zoom = 1.0
        self._fullscreen_center = None
        self._pan_origin = None
        self._source_image = image
        self._hdr_surface.load(encoded, image.size)
        detected_crop = self._trim_black_bars(image)
        self._crop_box = (
            detected_crop if self._content_crop_enabled
            else (0, 0, image.width, image.height)
        )
        self._apply_pending_header()
        self._render_image()
        self._refresh_location()

    def show_error(self, message: str) -> None:
        """Replace the photo with a concise localized retrieval failure.

        Args:
            message (str): Safe user-facing error text.
        Returns:
            None: Error is centered within the photo area.
        """
        self._clear_image()
        self._clear_location()
        self._location_panel.place_forget()
        self._apply_pending_header()
        self._pan_origin = None
        self._show_status(message, "#FF91A2")

    def _show_status(self, message: str, fill: str) -> None:
        """Set and draw the centered loading or retrieval status.

        Args:
            message (str): Localized status text for the current photo.
            fill (str): Theme-compatible text color for the status.
        Returns:
            None: The canvas status item is replaced at its current center.
        """
        self._hdr_surface.hide()
        self._status_message = message
        self._status_fill = fill
        self._draw_status()

    def _draw_status(self) -> None:
        """Place status in a themed badge without obscuring a prior photo.

        Returns:
            None: The current status badge is centered over the current raster.
        """
        self._canvas.delete("status")
        if self._status_message is None:
            return
        if self._status_label is None:
            self._status_label = ttk.Label(
                self._canvas, style="Photos.Status.TLabel",
            )
        self._status_label.configure(
            text=self._status_message,
            foreground=self._status_fill,
        )
        self._canvas.create_window(
            self._canvas.winfo_width() / 2,
            self._canvas.winfo_height() / 2,
            window=self._status_label,
            tags="status",
        )

    def dismiss(self) -> None:
        """Hide the overlay, clear image references and restore viewer focus.

        Returns:
            None: The configured close callback runs once for an active overlay.
        """
        self._cancel_resize_job()
        self._cancel_resize_preview()
        if self._control_hide_job is not None:
            try:
                self.window.after_cancel(self._control_hide_job)
            except tk.TclError:
                pass
            self._control_hide_job = None
        was_active = self._active
        was_fullscreen = self._fullscreen
        self._active = False
        self.visible = False
        try:
            owner_exists = bool(self.window.winfo_exists())
        except tk.TclError:
            owner_exists = False
        if owner_exists and was_fullscreen:
            try:
                self._toggle_fullscreen()
            except tk.TclError:
                owner_exists = False
        self._fullscreen = False
        self._clear_image()
        if owner_exists:
            self._clear_location()
        self._pan_origin = None
        hide_inline_tooltip(self._overlay)
        try:
            if self._overlay.winfo_exists():
                self._location_panel.place_forget()
                self._overlay.place_forget()
        except tk.TclError:
            pass
        self._restore_content()
        if was_active and owner_exists:
            self.on_close()

    def close(self) -> None:
        """Release pending UI work and remove the inline overlay widget.

        Returns:
            None: No photo, coordinate or resampling reference remains.
        """
        self.dismiss()
        self._cancel_resize_job()
        self._cancel_resize_preview()
        self._clear_image()
        self._clear_location()
        if self._window_configure_binding is not None:
            try:
                self.window.unbind(
                    "<Configure>", self._window_configure_binding,
                )
            except tk.TclError:
                pass
            self._window_configure_binding = None
        try:
            if self._overlay.winfo_exists():
                self._overlay.destroy()
        except tk.TclError:
            pass

    def escape(self) -> bool:
        """Consume Escape for the open location panel or fullscreen state.

        Returns:
            bool: True when one nested lightbox state was dismissed.
        """
        if self._location_panel.winfo_manager():
            self._location_panel.place_forget()
            self._map_button.focus_set()
            if self._active and self._source_image is not None:
                self._render_image()
            return True
        if self._fullscreen:
            self._toggle_fullscreen()
            return True
        return False

    def _queue_render(self, _event: tk.Event) -> None:
        """Debounce viewport resampling after geometry changes.

        Args:
            _event (tk.Event): Configure event for the overlay dimensions.
        Returns:
            None: A single deferred render is scheduled.
        """
        if not self._active:
            return
        if self._resize_job is not None:
            self._cancel_resize_job()
        self._pan_origin = None
        self._resize_job = self.window.after(
            LIGHTBOX_RESIZE_DEBOUNCE_MS, self._render_after_resize,
        )
        if self._source_image is not None and self._resize_preview_job is None:
            self._resize_preview_job = self.window.after(
                LIGHTBOX_RESIZE_PREVIEW_MS, self._render_resize_preview,
            )

    def _on_window_configure(self, event: tk.Event) -> None:
        """Schedule one rerender after the top-level moves between displays.

        Args:
            event (tk.Event): Toplevel geometry event, possibly a monitor move.
        Returns:
            None: The trailing resize renderer reevaluates display conditions.
        """
        if event.widget is self.window:
            self._queue_render(event)

    def _cancel_resize_job(self) -> None:
        """Cancel a pending resample callback while the owner still exists.

        Returns:
            None: Pending callback identity is cleared, including during teardown.
        """
        job = self._resize_job
        self._resize_job = None
        if job is None:
            return
        try:
            self.window.after_cancel(job)
        except tk.TclError:
            pass

    def _cancel_resize_preview(self) -> None:
        """Cancel a pending low-quality resize preview.

        Returns:
            None: Preview callback identity is cleared during normal use/teardown.
        """
        job = self._resize_preview_job
        self._resize_preview_job = None
        if job is None:
            return
        try:
            self.window.after_cancel(job)
        except tk.TclError:
            pass

    def _render_resize_preview(self) -> None:
        """Render one BILINEAR frame while geometry is actively changing.

        Returns:
            None: The current viewport is repainted without disturbing its
                trailing full-quality render callback.
        """
        self._resize_preview_job = None
        if self._source_image is not None:
            self._render_image(
                resampling=Image.Resampling.BILINEAR,
                preserve_resize_jobs=True,
            )

    def _render_after_resize(self) -> None:
        """Clear the fired debounce identity before rendering the viewport.

        Returns:
            None: The latest viewport geometry is applied to the photo.
        """
        self._resize_job = None
        self._cancel_resize_preview()
        self._pan_origin = None
        if self._source_image is None:
            self._draw_status()
        else:
            self._render_image()

    def _trim_black_bars(
        self, image: "PillowImage.Image",
    ) -> tuple[int, int, int, int]:
        """Return detected matte bounds for existing lightbox callers.

        Args:
            image (PillowImage.Image): Normalized source retained unchanged.
        Returns:
            tuple[int, int, int, int]: Crop bounds in original-image pixels.
        """
        return detect_black_matte_crop(image)

    def _render_image(self, *, resampling: int | None = None,
                      preserve_resize_jobs: bool = False) -> None:
        """Fit the source crop before creating bounded Tk pixels.

        Args:
            resampling (int | None): Pillow resampling enum for preview frames;
                omitted renders at final LANCZOS quality.
            preserve_resize_jobs (bool): Keep callbacks owned by active geometry
                changes when this is a scheduled preview repaint.
        Returns:
            None: Canvas image is updated from the retained source crop.
        """
        if self._source_image is None or self._crop_box is None or ImageTk is None:
            return
        if not preserve_resize_jobs:
            self._cancel_resize_job()
            self._cancel_resize_preview()
        inset_x, inset_y = (
            (0, 0) if self._fullscreen else LIGHTBOX_VIEWPORT_INSETS
        )
        width = max(1, self._canvas.winfo_width() - inset_x)
        height = max(1, self._canvas.winfo_height() - inset_y)
        left, top, right, bottom = self._crop_box
        display_width = right - left
        display_height = bottom - top
        render_box = self._crop_box
        if self._fullscreen:
            base_scale = min(width / display_width, height / display_height)
            scale = base_scale * min(self._zoom, LIGHTBOX_MAX_ZOOM)
            visible_width = min(display_width, width / scale)
            visible_height = min(display_height, height / scale)
            center_x, center_y = self._fullscreen_center or (
                (left + right) / 2,
                (top + bottom) / 2,
            )
            center_x = min(
                right - visible_width / 2,
                max(left + visible_width / 2, center_x),
            )
            center_y = min(
                bottom - visible_height / 2,
                max(top + visible_height / 2, center_y),
            )
            self._fullscreen_center = (center_x, center_y)
            source_left = max(left, math.floor(center_x - visible_width / 2))
            source_top = max(top, math.floor(center_y - visible_height / 2))
            render_box = (
                source_left,
                source_top,
                min(right, max(source_left + 1,
                               math.ceil(center_x + visible_width / 2))),
                min(bottom, max(source_top + 1,
                                math.ceil(center_y + visible_height / 2))),
            )
            out_size = (
                max(1, min(width, round(visible_width * scale))),
                max(1, min(height, round(visible_height * scale))),
            )
        else:
            scale_x = width / display_width
            scale_y = height / display_height
            factor = min(1.0, scale_x, scale_y) * min(
                self._zoom, LIGHTBOX_MAX_ZOOM,
            )
            out_size = (
                max(1, round(display_width * factor)),
                max(1, round(display_height * factor)),
            )
        if out_size[0] * out_size[1] > LIGHTBOX_MAX_PIXELS:
            cap = math.sqrt(LIGHTBOX_MAX_PIXELS / (out_size[0] * out_size[1]))
            out_size = (max(1, int(out_size[0] * cap)),
                        max(1, int(out_size[1] * cap)))
        image = self._source_image.resize(
            out_size,
            Image.Resampling.LANCZOS if resampling is None else resampling,
            box=render_box,
        )
        self._photo = ImageTk.PhotoImage(image, master=self._canvas)
        self._canvas.delete("all")
        self._canvas.create_image(
            self._canvas.winfo_width() / 2,
            self._canvas.winfo_height() / 2,
            image=self._photo, tags="photo",
        )
        self._pan_origin = None
        self._center_or_constrain()
        if self._status_message is None:
            self._render_hdr(render_box)
        if self._status_message is not None:
            self._draw_status()

    def _render_hdr(self, box: tuple[int, int, int, int]) -> None:
        """Align the optional HDR surface with the prepared canvas image.

        Args:
            box (tuple[int, int, int, int]): Current source crop bounds.
        Returns:
            None: Native tiles follow the image or the SDR canvas remains visible.
        """
        coords = self._canvas.coords("photo")
        if not coords or self._photo is None:
            return
        width, height = self._photo.width(), self._photo.height()
        x, y = coords
        self._hdr_surface.render(
            box, (x - width / 2, y - height / 2,
                  x + width / 2, y + height / 2),
            [self._toolbar, self._previous_edge, self._next_edge,
             self._location_panel],
        )

    def _change_zoom(self, factor: float) -> None:
        """Adjust zoom by a bounded factor and rerender the current image.

        Args:
            factor (float): Positive zoom multiplier for this interaction.
        Returns:
            None: The image is resampled to the bounded viewport raster.
        """
        if self._source_image is not None:
            zoom = min(LIGHTBOX_MAX_ZOOM, max(1.0, self._zoom * factor))
            if zoom != self._zoom:
                self._zoom = zoom
                self._render_image()

    def _fit_image(self) -> None:
        """Toggle between detected content and the full original frame.

        Returns:
            None: The original frame or detected content bounds are displayed.
        """
        if self._source_image is None:
            return
        self._content_crop_enabled = not self._content_crop_enabled
        self._fit_tooltip.set(
            self.text("fit_content") if not self._content_crop_enabled
            else self.text("show_original_frame")
        )
        if not self._content_crop_enabled:
            self._crop_box = (0, 0, self._source_image.width,
                              self._source_image.height)
        else:
            self._crop_box = self._trim_black_bars(self._source_image)
        self._reset_zoom()

    def _reset_zoom(self) -> None:
        """Restore the current content mode to its fitted viewport scale.

        Returns:
            None: Zoom and pan return to the active fit mode's initial view.
        """
        self._zoom = 1.0
        self._wheel_remainder = 0
        self._touchpad_remainder = 0
        self._fullscreen_center = None
        self._render_image()

    def _pan_start(self, event: tk.Event) -> None:
        """Remember canvas or source-image origin before a drag gesture.

        Args:
            event (tk.Event): Mouse press coordinates in canvas space.
        Returns:
            None: The active display mode's pan origin is retained.
        """
        if self._photo is not None and self._fullscreen:
            if self._crop_box is not None:
                left, top, right, bottom = self._crop_box
                self._fullscreen_center = self._fullscreen_center or (
                    (left + right) / 2,
                    (top + bottom) / 2,
                )
                self._pan_origin = (
                    event.x,
                    event.y,
                    self._fullscreen_center[0],
                    self._fullscreen_center[1],
                )
        elif self._photo is not None:
            coords = self._canvas.coords("photo")
            self._pan_origin = (event.x, event.y, coords[0], coords[1])

    def _pan_motion(self, event: tk.Event) -> None:
        """Move a zoomed photo or fullscreen source crop under the pointer.

        Args:
            event (tk.Event): Current pointer coordinates in canvas space.
        Returns:
            None: The visible image remains bounded by its source crop.
        """
        if self._pan_origin is None:
            return
        x, y, ox, oy = self._pan_origin
        if self._fullscreen and self._crop_box is not None:
            left, top, right, bottom = self._crop_box
            canvas_width = max(1, self._canvas.winfo_width())
            canvas_height = max(1, self._canvas.winfo_height())
            scale = max(
                canvas_width / (right - left),
                canvas_height / (bottom - top),
            ) * min(self._zoom, LIGHTBOX_MAX_ZOOM)
            self._fullscreen_center = (
                ox - (event.x - x) / scale,
                oy - (event.y - y) / scale,
            )
            origin = self._pan_origin
            self._render_image()
            self._pan_origin = origin
            return
        self._canvas.coords("photo", ox + event.x - x, oy + event.y - y)
        self._center_or_constrain()
        if self._crop_box is not None:
            self._render_hdr(self._crop_box)

    def _center_or_constrain(self) -> None:
        """Constrain image movement so no blank gap replaces photo content.

        Returns:
            None: Photo center is clamped to canvas and image half extents.
        """
        if self._photo is None:
            return
        coords = self._canvas.coords("photo")
        if not coords:
            return
        image_width = self._photo.width()
        image_height = self._photo.height()
        canvas_width = self._canvas.winfo_width()
        canvas_height = self._canvas.winfo_height()
        if image_width <= canvas_width:
            x_min = x_max = canvas_width / 2
        else:
            x_min, x_max = canvas_width - image_width / 2, image_width / 2
        if image_height <= canvas_height:
            y_min = y_max = canvas_height / 2
        else:
            y_min, y_max = canvas_height - image_height / 2, image_height / 2
        self._canvas.coords(
            "photo", min(x_max, max(x_min, coords[0])),
            min(y_max, max(y_min, coords[1])),
        )

    def _wheel(self, event: tk.Event) -> None:
        """Zoom with the wheel while preserving the bounded raster policy.

        Args:
            event (tk.Event): Wheel delta from the Tk platform adapter.
        Returns:
            None: Zoom direction is applied when a photo is active.
        """
        self._apply_zoom_delta(vertical_scroll_delta(self.window, event))

    def _touchpad_scroll(self, event: tk.Event) -> None:
        """Zoom for precise two-axis touchpad packets using the vertical axis.

        Args:
            event (tk.Event): Tk touchpad event carrying packed axis deltas.
        Returns:
            None: Vertical gesture motion is accumulated into bounded zoom steps.
        """
        self._apply_zoom_delta(
            vertical_scroll_delta(self.window, event, precise=True),
            precise=True,
        )

    def _apply_zoom_delta(self, delta: int, *, precise: bool = False) -> None:
        """Accumulate high-resolution scrolling and apply coalesced zoom steps.

        Args:
            delta (int): Signed vertical delta; positive means zoom in.
            precise (bool): Whether the value came from high-resolution input.
        Returns:
            None: At most one raster render is requested per received packet.
        """
        if precise:
            self._touchpad_remainder += delta
            threshold = LIGHTBOX_TOUCHPAD_ZOOM_DELTA_UNITS
            steps, remainder = divmod(abs(self._touchpad_remainder), threshold)
            if steps:
                direction = 1 if self._touchpad_remainder > 0 else -1
                self._touchpad_remainder = remainder * direction
                self._apply_zoom_steps(direction, steps)
        else:
            threshold = LIGHTBOX_WHEEL_DELTA_UNITS if sys.platform == "win32" else 1
            self._wheel_remainder += delta
            steps, remainder = divmod(abs(self._wheel_remainder), threshold)
            if steps:
                direction = 1 if self._wheel_remainder > 0 else -1
                self._wheel_remainder = remainder * direction
                self._apply_zoom_steps(direction, steps)
        self._schedule_control_hide()

    def _apply_zoom_steps(self, direction: int, steps: int) -> None:
        """Apply accumulated zoom steps with one render per input packet.

        Args:
            direction (int): Signed zoom direction, positive for zoom in.
            steps (int): Number of accumulated zoom increments.
        Returns:
            None: One bounded render request updates the current photo.
        """
        maximum = math.ceil(math.log(LIGHTBOX_MAX_ZOOM, LIGHTBOX_WHEEL_STEP))
        self._change_zoom(
            LIGHTBOX_WHEEL_STEP ** (direction * min(steps, maximum)),
        )

    def add_action(
        self,
        glyph: str,
        command: Callable[[], None],
        tooltip: str | tk.StringVar,
    ) -> ttk.Button:
        """Add a compact playback action to the shared toolbar.

        Args:
            glyph (str): Visible single-line action mark.
            command (Callable[[], None]): Action called on activation.
            tooltip (str | tk.StringVar): Localized accessible hover description.
        Returns:
            ttk.Button: The toolbar button managed with other lightbox controls.
        """
        button = icon_button(
            self._controls, glyph, command, tooltip,
            style_override="Photos.Icon.TButton",
            tooltip_surface=self._overlay,
        )
        button.pack(side="left", padx=1, pady=2)
        self._buttons.append(button)
        button.bind("<Motion>", self._on_pointer_motion, add="+")
        return button

    def _on_pointer_motion(self, _event: tk.Event) -> None:
        """Reveal fullscreen controls and restart their idle timer.

        Args:
            _event (tk.Event): Pointer motion over the lightbox image canvas.
        Returns:
            None: Fullscreen chrome remains available briefly after movement.
        """
        if not self._fullscreen:
            return
        if not self._controls_visible:
            self._show_controls()
        self._schedule_control_hide()

    def _schedule_control_hide(self) -> None:
        """Restart the bounded fullscreen toolbar idle timer.

        Returns:
            None: A still pointer hides fullscreen controls after the idle bound.
        """
        if not self._fullscreen:
            return
        if self._control_hide_job is not None:
            try:
                self.window.after_cancel(self._control_hide_job)
            except tk.TclError:
                pass
        self._control_hide_job = self.window.after(
            LIGHTBOX_CONTROLS_IDLE_MS, self._hide_controls,
        )

    def _hide_controls(self) -> None:
        """Hide toolbar and edge actions while preserving fullscreen imagery.

        Returns:
            None: The image is rerendered after native surfaces are withdrawn.
        """
        self._control_hide_job = None
        if not self._fullscreen or not self._controls_visible:
            return
        self._toolbar.place_forget()
        self._previous_edge.place_forget()
        self._next_edge.place_forget()
        self._location_panel.place_forget()
        self._controls_visible = False
        if (self._fullscreen and self._active
                and self._source_image is not None):
            self._render_image()

    def _show_controls(self) -> None:
        """Restore the existing overlay toolbar and edge navigation.

        Returns:
            None: The same controls retain their current button states.
        """
        self._toolbar.place(relx=0.5, rely=0.025, anchor="n")
        self._previous_edge.place(relx=0.025, rely=0.5, anchor="w")
        self._next_edge.place(relx=0.975, rely=0.5, anchor="e")
        self._controls_visible = True
        if self._active and self._source_image is not None:
            # Resolve mapped control geometry before clipping the native tiles.
            self._overlay.update_idletasks()
            self._render_image()

    def enter_fullscreen(self) -> None:
        """Enter fullscreen once and leave controls available on pointer motion.

        Returns:
            None: The current lightbox is fullscreen without toggling it back.
        """
        if not self._fullscreen:
            self._toggle_fullscreen()

    def _toggle_fullscreen(self) -> None:
        """Toggle fullscreen and restore the previous window state.

        Returns:
            None: Fullscreen image and keyboard/pointer controls stay available.
        """
        if not self._fullscreen:
            self._fullscreen_restore = self.window.attributes("-fullscreen")
            self.window.attributes("-fullscreen", True)
            self._fullscreen = True
            self._controls_visible = False
            hide_inline_tooltip(self._overlay)
            self._toolbar.place_forget()
            self._previous_edge.place_forget()
            self._next_edge.place_forget()
            self._location_panel.place_forget()
        else:
            restore = (
                self._fullscreen_restore
                if self._fullscreen_restore is not None else False
            )
            self.window.attributes("-fullscreen", restore)
            self._fullscreen_restore = None
            self._fullscreen = False
            if self._control_hide_job is not None:
                try:
                    self.window.after_cancel(self._control_hide_job)
                except tk.TclError:
                    pass
                self._control_hide_job = None
            self._show_controls()
        self._canvas.focus_set()
        if self._active and self._source_image is not None:
            self._render_image()

    def _toggle_location(self) -> None:
        """Toggle a compact location surface beside its pin trigger.

        Returns:
            None: Surface is anchored to the pin control when coordinates exist.
        """
        if self._location is None:
            return
        if self._location_panel.winfo_manager():
            self._location_panel.place_forget()
        else:
            self._map_status.pack_forget()
            self._refresh_location()
            self._overlay.update_idletasks()
            x = self._map_button.winfo_rootx() - self._overlay.winfo_rootx()
            y = (
                self._map_button.winfo_rooty() - self._overlay.winfo_rooty()
                + self._map_button.winfo_height() + LIGHTBOX_PANEL_ANCHOR_GAP
            )
            self._location_panel.update_idletasks()
            panel_width = self._location_panel.winfo_reqwidth()
            panel_height = self._location_panel.winfo_reqheight()
            overlay_width = self._overlay.winfo_width()
            overlay_height = self._overlay.winfo_height()
            edge_gap = LIGHTBOX_PANEL_EDGE_GAP
            panel_width = min(panel_width, max(1, overlay_width - 2 * edge_gap))
            x = max(panel_width / 2 + edge_gap,
                    min(overlay_width - panel_width / 2 - edge_gap, x))
            y = min(y, max(edge_gap, overlay_height - panel_height - edge_gap))
            self._location_panel.place(
                x=x, y=y, anchor="n", width=panel_width,
            )
            self._map_button.focus_set()
        if self._active and self._source_image is not None:
            self._overlay.update_idletasks()
            self._render_image()

    def _refresh_location(self) -> None:
        """Update the admitted coordinates and map action availability.

        Returns:
            None: Location control is disabled when no admitted pin exists.
        """
        has_location = self._location is not None
        self._map_button.configure(state="normal" if has_location else "disabled")
        if has_location:
            latitude, longitude = self._location
            self._location_label.configure(
                text=f"{latitude:.5f}, {longitude:.5f}",
            )

    def _clear_image(self) -> None:
        """Release the decoded image and Tk bitmap for the prior selection.

        Returns:
            None: No prior photo raster remains referenced by the overlay.
        """
        self._hdr_surface.clear()
        source = self._source_image
        if source is not None:
            try:
                source.close()
            except Exception:
                pass
        self._source_image = None
        self._crop_box = None
        self._photo = None
        try:
            self._canvas.delete("photo")
            self._canvas.delete("status")
        except tk.TclError:
            pass

    def _clear_location(self) -> None:
        """Remove coordinate state and disable the contextual pin action.

        Returns:
            None: The prior selection's coordinates cannot be reopened.
        """
        self._location = None
        try:
            self._map_button.configure(state="disabled")
            self._map_status.pack_forget()
            self._map_status.configure(text="")
        except tk.TclError:
            pass

    def _outside_location_click(self, event: tk.Event) -> str | None:
        """Close the map surface on clicks outside its panel and pin trigger.

        Args:
            event (tk.Event): Click event from a widget in the owning toplevel.
        Returns:
            str | None: Break when this click closes the contextual surface.
        """
        if not self._location_panel.winfo_manager():
            return None
        widget = event.widget
        while widget is not None:
            if widget in (self._location_panel, self._map_button):
                return None
            widget = getattr(widget, "master", None)
        self._location_panel.place_forget()
        self._map_button.focus_set()
        return "break"

    def _zoom_in_key(self, _event: tk.Event) -> str | None:
        """Increase zoom for the plus or equals keyboard shortcut.

        Args:
            _event (tk.Event): Plus or equals key event.
        Returns:
            str | None: Break when an active photo receives the shortcut.
        """
        if self._active and self._source_image is not None:
            self._change_zoom(LIGHTBOX_ZOOM_STEP)
            return "break"
        return None

    def _zoom_out_key(self, _event: tk.Event) -> str | None:
        """Decrease zoom for the minus keyboard shortcut.

        Args:
            _event (tk.Event): Minus key event.
        Returns:
            str | None: Break when an active photo receives the shortcut.
        """
        if self._active and self._source_image is not None:
            self._change_zoom(1 / LIGHTBOX_ZOOM_STEP)
            return "break"
        return None

    def _fit_key(self, _event: tk.Event) -> str | None:
        """Fit the photo to the viewport for the zero key shortcut.

        Args:
            _event (tk.Event): Zero key event.
        Returns:
            str | None: Break when an active photo receives the shortcut.
        """
        if self._active and self._source_image is not None:
            self._reset_zoom()
            return "break"
        return None

    def _open_map(self) -> None:
        """Open the admitted coordinate in OpenStreetMap after explicit action.

        Returns:
            None: The user's system browser is asked to open the public map URL.
        """
        if self._location is None:
            return
        latitude, longitude = self._location
        if not (-90 <= latitude <= 90 and -180 <= longitude <= 180):
            return
        url = (
            "https://www.openstreetmap.org/?mlat="
            f"{latitude:.6f}&mlon={longitude:.6f}#map={LIGHTBOX_MAP_ZOOM}/"
            f"{latitude:.6f}/{longitude:.6f}"
        )
        try:
            opened = webbrowser.open(url)
        except Exception:
            opened = False
        if not opened:
            self._map_status.configure(text=self.text("map_open_failed"))
            self._map_status.pack(side="left", padx=(0, 8), pady=5)

    def _previous_key(self, _event: tk.Event) -> str | None:
        """Navigate to the previous photo for a Left key event.

        Args:
            _event (tk.Event): Left key event from this window's bindtag.
        Returns:
            str | None: Break when the overlay is active, otherwise None.
        """
        if self._active:
            self.on_move(-1)
            return "break"
        return None

    def _next_key(self, _event: tk.Event) -> str | None:
        """Navigate to the next photo for a Right key event.

        Args:
            _event (tk.Event): Right key event from this window's bindtag.
        Returns:
            str | None: Break when the overlay is active, otherwise None.
        """
        if self._active:
            self.on_move(1)
            return "break"
        return None

    def _fullscreen_key(self, _event: tk.Event) -> str | None:
        """Toggle fullscreen on F11 while the overlay is active.

        Args:
            _event (tk.Event): F11 event from this window's bindtag.
        Returns:
            str | None: Break when the overlay is active, otherwise None.
        """
        if self._active:
            self._toggle_fullscreen()
            return "break"
        return None
