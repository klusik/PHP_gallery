# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/ui_theme.py
# Module Type: Windows uploader presentation support
# Purpose: Provide a cohesive, accessible Tkinter theme for the uploader.
# Responsibilities: Configure shared ttk styles, icon buttons, cards and help.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Shared visual styling and accessible presentation helpers for the uploader."""

from collections.abc import Callable, Mapping
from dataclasses import dataclass
from math import cos, radians, sin
import ctypes
import _tkinter
import sys
import tkinter as tk
from tkinter import font as tkfont, ttk
from typing import TYPE_CHECKING, TypeAlias

if TYPE_CHECKING:
    from PIL import ImageTk

    ThemeImage: TypeAlias = tk.PhotoImage | ImageTk.PhotoImage
else:
    ThemeImage: TypeAlias = tk.PhotoImage


# Purpose: Set the shared delay before pointer-hover help appears.
# Type: int; Units: milliseconds; Scope: transient tooltip lifecycle.
# Consumers: tooltip scheduling and cancellation callbacks.
# Rationale: 550 ms avoids flicker while retaining timely discovery.
_TOOLTIP_DELAY_MS = 550

# Purpose: Dismiss hover help even when Tk misses a pointer-leave event.
# Type: int. Units: milliseconds. Scope: the one visible inline tooltip.
# Consumers: attach_inline_tooltip hover monitor.
# Rationale: 80 ms catches window switches and missed Leave events promptly
# without adding a timer for every idle control.
_TOOLTIP_POINTER_CHECK_MS = 80

# Purpose: Keep inline tooltip panels clear of trigger controls and edges.
# Type: int; Units: pixels; Scope: one themed inline tooltip placement.
# Consumers: attach_inline_tooltip.
# Rationale: 6 px separates the label without detaching it from its trigger.
_INLINE_TOOLTIP_GAP = 6

# Purpose: Pad inline tooltip labels for legibility over the current surface.
# Type: tuple[int, int]; Units: pixels; Scope: themed inline tooltip label.
# Consumers: Inline.Tooltip.TLabel.
# Rationale: 8 horizontal and 5 vertical pixels keep the inline label compact.
_INLINE_TOOLTIP_PADDING = (8, 5)

# Purpose: Pad the centered loading badge while leaving the prior photo visible.
# Type: tuple[int, int]; Units: pixels; Scope: Photos status label.
# Consumers: Photos.Status.TLabel.
# Rationale: 10 by 6 px makes short status text readable over imagery.
_PHOTOS_STATUS_PADDING = (10, 6)

# Purpose: Keep Photos toolbar targets compact and consistently padded.
# Type: tuple[int, int]; Units: pixels; Scope: Photos icon button style.
# Consumers: Photos.Icon.TButton.
# Rationale: 4 px padding retains a small target without an opaque image tile.
_PHOTOS_ICON_PADDING = (4, 4)

# Purpose: Match Photos toolbar glyph sizing to shared uploader icon controls.
# Type: int; Units: points; Scope: Photos icon button glyphs.
# Consumers: Photos.Icon.TButton.
# Rationale: 12 pt stays legible within the compact toolbar.
_PHOTOS_ICON_FONT_SIZE = 12

# Purpose: Keep ratio captions readable beneath their visible shape icons.
# Type: int. Units: points. Scope: the seven photo-format toolbar choices.
# Consumers: configure_theme format button styles. Rationale: 8 pt captions
# fit with 18 px artwork in a compact row without competing with album titles.
_PHOTO_FORMAT_LABEL_POINTS = 8

# Purpose: Keep the drawn Photos toolbar symbol compact inside its button.
# Type: int; Units: logical pixels; Scope: Photos icon button artwork.
# Consumers: SmoothIconButton._refresh_icon.
# Rationale: 16 px leaves the toolbar text-free while preserving the 32 px row.
_PHOTOS_ICON_IMAGE_SIZE = 16

# Purpose: Normalize Tk's reported display density to logical UI pixels.
# Type: float; Units: dots per inch; Scope: Windows uploader image and row sizing.
# Consumers: _tk_dpi_scale.
# Rationale: 96 DPI is the conventional baseline for logical desktop pixels.
_BASE_UI_DPI = 96.0

# Purpose: Keep gallery tree rows tall enough for labels and status artwork.
# Type: int; Units: logical pixels; Scope: shared ttk Treeview rows.
# Consumers: configure_theme Treeview style.
# Rationale: 30 px fits the native font and an 18 px status mark compactly.
_TREEVIEW_ROW_HEIGHT = 30

ButtonImageElement: TypeAlias = str | ThemeImage
ButtonImageOption: TypeAlias = (
    ButtonImageElement | tuple[ButtonImageElement, ...]
)
ButtonFontOption: TypeAlias = (
    str | tuple[str, int] | tuple[str, int, str]
    | tuple[str, int, str, str] | tuple[str, int, str, str, str]
)
ButtonSequenceOption: TypeAlias = (
    tuple[str | int, ...]
    | tuple[tuple[str | ThemeImage, ...], ...]
)
ButtonOptionValue: TypeAlias = (
    str | int | bool | tk.Variable | ThemeImage | Callable[[], None]
    | ButtonImageOption | ButtonFontOption | ButtonSequenceOption
)
ButtonOptionMapping: TypeAlias = Mapping[str, ButtonOptionValue]
ButtonQueryScalar: TypeAlias = (
    str | int | float | bool | tk.Variable | ThemeImage
    | Callable[[], None] | _tkinter.Tcl_Obj
)
ButtonQueryValue: TypeAlias = (
    ButtonQueryScalar | tuple[ButtonQueryScalar, ...]
)

# Type: tuple[str, str, str, str, ButtonQueryValue]; Units: ttk option metadata.
# Scope: one Tkinter widget configuration response. The final field preserves
# Tkinter's native scalar, Tcl object or typed tuple returned by Tcl.
TtkOptionSpec: TypeAlias = tuple[str, str, str, str, ButtonQueryValue]
TtkConfigureResult: TypeAlias = (
    dict[str, TtkOptionSpec] | TtkOptionSpec | str | None
)

# Purpose: Keep help instructions comfortably readable in a compact overlay.
# Type: int; Units: pixels; Scope: help window minimum content width.
# Consumers: help overlay geometry.
# Rationale: 380 px supports concise instructions on standard displays.
_HELP_MIN_WIDTH = 380

# Purpose: Choose the source size for stretchable glass button assets.
# Type: tuple[int, int]; Units: pixels; Scope: nine-slice button artwork.
# Consumers: Pillow image generation and ttk image elements.
# Rationale: 52 by 30 px gives compact buttons a lightly rounded silhouette.
_BUTTON_IMAGE_SIZE = (52, 30)

# Purpose: Choose the source size for softly rounded frosted panel assets.
# Type: tuple[int, int]; Units: pixels; Scope: nine-slice frame artwork.
# Consumers: Pillow image generation and ttk image elements.
# Rationale: 68 by 38 px preserves smooth curves without raising card minima.
_PANEL_IMAGE_SIZE = (68, 38)

# Purpose: Keep toolbar glyph controls compact while retaining a circular end.
# Type: tuple[int, int]; Units: pixels; Scope: icon-only button artwork.
# Consumers: compact ttk icon button layouts.
# Rationale: 20 by 20 px plus a 6 px nine-slice rim yields a 32 px glyph pad.
_ICON_IMAGE_SIZE = (20, 20)

# Purpose: Preserve the rounded boundary of a compact icon image.
# Type: int; Units: pixels; Scope: icon button nine-slice artwork.
# Consumers: Gloss.icon and Gloss.iconPrimary ttk elements.
# Rationale: 6 px insets retain circular corners at the 20 px source size.
_ICON_BORDER = 6

# Purpose: Keep compact icon artwork circular after supersampling.
# Type: int; Units: pixels; Scope: icon button source artwork.
# Consumers: icon material image composition.
# Rationale: 5 px stays inside the 6 px nine-slice rim on every side.
_ICON_CORNER_RADIUS = 5

# Purpose: Preserve lightly rounded corners while stretching button images.
# Type: int; Units: pixels; Scope: nine-slice button artwork.
# Consumers: ttk button image element borders.
# Rationale: 8 px insets preserve corners without inflating button heights.
_BUTTON_BORDER = 8

# Purpose: Preserve rounded panel corners while stretching card images.
# Type: int; Units: pixels; Scope: nine-slice frame artwork.
# Consumers: Glass.TFrame and Footer.TFrame image element borders.
# Rationale: 12 px protects the card radius without inflating compact frames.
_PANEL_BORDER = 12

# Purpose: Lightly round compact buttons without a tall capsule silhouette.
# Type: int; Units: pixels; Scope: generated button artwork.
# Consumers: Pillow rounded rectangle drawing.
# Rationale: 6 px softens the rectangle while retaining compact toolbars.
_BUTTON_CORNER_RADIUS = 6

# Purpose: Keep selected gallery image outlines softly rounded and compact.
# Type: int; Units: pixels; Scope: gallery tile selection artwork.
# Consumers: _install_glossy_elements.
# Rationale: 4 px rounds corners without changing any tile geometry.
_GALLERY_SELECTION_CORNER_RADIUS = 4

# Purpose: Reserve the same compact rim around every gallery tile.
# Type: int; Units: pixels per edge; Scope: gallery tile image element.
# Consumers: Gallery.tile artwork and native gallery grid geometry.
# Rationale: Four pixels preserve the small radius without inheriting the
# larger toolbar-button insets or shifting tiles when selected.
GALLERY_TILE_BORDER_INSET = 4

# Purpose: Give cards a softer silhouette than standard ttk frames.
# Type: int; Units: pixels; Scope: generated frosted panel artwork.
# Consumers: Pillow rounded rectangle drawing.
# Rationale: 10 px softens corners without wasting panel content area.
_PANEL_CORNER_RADIUS = 10

# Purpose: Restrict native region DLL lookup to trusted Windows system folders.
# Type: int. Units: LoadLibraryEx flag bits. Scope: contextual card clipping.
# Consumers: _load_context_region_api for user32.dll and gdi32.dll.
# Rationale: Region APIs must never resolve from the working directory or PATH.
_LOAD_LIBRARY_SEARCH_SYSTEM32 = 0x00000800

# Purpose: Render antialiased edges for the compact source theme images.
# Type: int; Units: scale factor; Scope: Pillow artwork generation.
# Consumers: button and panel resampling before Tk image creation.
# Rationale: 3x provides clean rounded edges at ordinary Windows DPI.
_ARTWORK_SCALE = 3

# Purpose: Bound help text and window geometry to comfortable dimensions.
# Type: int; Units: pixels; Scope: help overlay sizing.
# Consumers: tooltip wrapping and help overlay minimum and initial geometry.
# Rationale: 320 px wrap, 180 px minimum and 200 px initial height stay compact.
_HELP_MIN_HEIGHT = 180
_HELP_TEXT_WRAP = 320
_HELP_INITIAL_HEIGHT = 200

# Purpose: Define the initial text area before content-driven resizing.
# Type: tuple[int, int]; Units: text rows and columns; Scope: help overlay.
# Consumers: help Text widget requested geometry.
# Rationale: 7 by 46 characters fits concise guidance without excess space.
_HELP_TEXT_SIZE = (7, 46)

# Purpose: Keep icon-only actions compact while leaving labeled buttons natural.
# Type: int; Units: character columns; Scope: icon-only ttk button width.
# Consumers: icon_button when passed a semantic key or single glyph.
# Rationale: 2 columns fit one line glyph without claiming toolbar width.
_ICON_BUTTON_COLUMNS = 2

# Purpose: Blend cool ambient color into the opaque frosted panel surface.
# Type: float; Units: normalized blend amount; Scope: panel RGB composition.
# Consumers: Pillow card and inset panel renderer.
# Rationale: 0.55 leaves visible cool color while preserving dark-text contrast.
_FROST_TINT_BLEND = 0.55

# Purpose: Choose the uniform RGB body between the frosted edge gradients.
# Type: float; Units: normalized blend amount; Scope: nine-slice center pixels.
# Consumers: uniform card, inset and button element centers.
# Rationale: 0.5 balances the light top and cool lower rim without tile bands.
_MATERIAL_CENTER_BLEND = 0.5

# Purpose: Compose a soft cool shadow into opaque frame art before Tk display.
# Type: float; Units: normalized color blend; Scope: panel outer edge shading.
# Consumers: frosted panel artwork composition.
# Rationale: 0.10 adds separation without a dark baseline or alpha pixels.
_PANEL_SHADOW_BLEND = 0.10

# Purpose: Soften the composited panel shadow at its edge.
# Type: int; Units: source pixels; Scope: panel RGB artwork.
# Consumers: Pillow Gaussian blur radius after supersampling.
# Rationale: 2 px yields a quiet halo after 3x source rendering.
_PANEL_SHADOW_BLUR = 2

# Purpose: Define shared colors for the uploader's dark glass hierarchy.
# Type: dict[str, str]; Units: color strings; Scope: uploader widget palette.
# Consumers: ttk style registration and card defaults.
# Rationale: light text and cyan actions remain legible on slate panels;
#     semantic colors distinguish success, attention and failures.
_PALETTE = {
    "background": "#131B26",
    "surface": "#182330",
    "surface_alt": "#223344",
    "card_surface": "#253443",
    "card_surface_alt": "#2B3D4D",
    "ink": "#F2F6FA",
    "muted": "#AABCCB",
    "line": "#3D5264",
    "primary": "#08AED0",
    "primary_hover": "#22C3E2",
    "primary_pressed": "#008AA8",
    "focus": "#8BE4F2",
    "success": "#46D68C",
    "warning": "#F2C581",
    "danger": "#FF91A2",
    "recording": "#FF6673",
    "recording_dim": "#B93A45",
}

# Purpose: Map common actions to consistent single-line icon glyphs.
# Type: dict[str, str]; Units: icon glyphs; Scope: common uploader actions.
# Consumers: icon_button callers using a semantic icon key.
# Rationale: Unicode line symbols render consistently without emoji assets.
_ICON_GLYPHS = {
    "add": "+",
    "back": "‹",
    "check": "✓",
    "close": "×",
    "help": "?",
    "info": "i",
    "pause": "Ⅱ",
    "play": "▶",
    "refresh": "↻",
    "settings": "⚙",
    "stop": "■",
    "upload": "↑",
}

# Purpose: Prefer the native variable UI face while retaining usable fallbacks.
# Type: tuple[str, ...]; Units: installed Tk family names; Scope: uploader UI.
# Consumers: configure_theme font-family selection.
# Rationale: Use the installed text family instead of a symbol font for UI text.
_UI_FONT_PREFERENCES = ("Segoe UI Variable Text", "Segoe UI")

# Purpose: Keep compact semantic artwork crisp after reduction.
# Type: int; Units: supersampling factor; Scope: smooth icon generation.
# Consumers: create_smooth_icon.
# Rationale: Existing theme artwork uses 3x rendering for clean edges.
_ICON_SUPERSAMPLE = _ARTWORK_SCALE

# Purpose: Map known UI glyph spellings to deterministic geometric drawings.
# Type: dict[str, str]; Units: icon key; Scope: common uploader controls.
# Consumers: SmoothIconButton and icon composition.
# Rationale: Drawing known shapes avoids font fallback while keeping text intact.
_ICON_ALIASES = {
    "+": "add", "−": "remove", "×": "close", "✓": "check",
    "‹": "back", "›": "next", "▶": "play", "Ⅱ": "pause",
    "↻": "refresh", "⚙": "settings", "■": "stop", "↑": "upload",
    "☁": "cloud", "●": "local", "◐": "partial", "◌": "unknown",
    "⏻": "power", "⋯": "more", "≡": "list", "↗": "external",
    "⌖": "location", "⛶": "fullscreen", "□": "fit",
    "♫": "music", "⊕": "zoom_in",
    "◉": "recording", "◯": "recording", "◍": "recording",
    "?": "help", "i": "info", "help": "help", "info": "info",
    "PIN ☁": "cloud", "PIN ●": "local", "PIN ◐": "partial",
    "PIN ◌": "unknown",
}

# Purpose: Retain generated Tk images while ttk styles may use them.
# Type: dict[str, ThemeImage]; Units: retained image objects; Scope: process.
# Consumers: ttk layouts using generated glossy elements.
# Rationale: Tk image handles must stay referenced for the widget lifetime.
_THEME_IMAGES: dict[str, ThemeImage] = {}

# Purpose: Give all boolean controls the same compact sliding-switch shape.
# Type: tuple[int, int]; Units: pixels; Scope: shared ttk switch artwork.
# Consumers: _install_switch_elements.
# Rationale: 38 by 22 px leaves a visible thumb and comfortable label spacing.
_SWITCH_IMAGE_SIZE = (38, 22)


def _select_ui_font_family(root: tk.Misc) -> str:
    """Select an installed static UI family and update Tk named fonts.

    Args:
        root (tk.Misc): Tk interpreter owning the fonts and option database.

    Returns:
        str: Installed family selected for ordinary uploader text.
    """
    try:
        families = {name.casefold(): name for name in tkfont.families(root)}
    except tk.TclError:
        families = {}
    default_family = str(tkfont.nametofont("TkDefaultFont", root=root).actual(
        "family",
    ))
    family = next(
        (families[name.casefold()] for name in _UI_FONT_PREFERENCES
         if name.casefold() in families),
        families.get(default_family.casefold(), default_family),
    )
    for name in (
        "TkDefaultFont", "TkTextFont", "TkMenuFont", "TkHeadingFont",
        "TkCaptionFont", "TkSmallCaptionFont", "TkIconFont", "TkTooltipFont",
    ):
        try:
            named = tkfont.nametofont(name, root=root)
            if name == "TkHeadingFont":
                named.configure(family=family, weight="bold")
            else:
                named.configure(family=family)
        except tk.TclError:
            continue
    return family


def _tk_dpi_scale(master: tk.Misc) -> float:
    """Read Tk's display scale relative to the conventional 96 DPI baseline.

    Args:
        master (tk.Misc): Tk widget whose display density should be measured.

    Returns:
        float: Scale factor of at least 1.0, or 1.0 if Tk cannot report DPI.
    """
    try:
        return max(1.0, float(master.winfo_fpixels("1i")) / _BASE_UI_DPI)
    except (tk.TclError, ValueError):
        return 1.0


def _scale_ui_pixels(master: tk.Misc, logical_pixels: int) -> int:
    """Convert a logical Tk pixel measurement to the active display density.

    Args:
        master (tk.Misc): Widget on the display that owns the measurement.
        logical_pixels (int): Baseline UI size at 96 DPI.

    Returns:
        int: At least one pixel at the current Tk-reported display scale.
    """
    return max(1, round(logical_pixels * _tk_dpi_scale(master)))


def create_smooth_icon(
    master: tk.Misc,
    icon: str,
    size: tuple[int, int],
    *,
    pinned: bool = False,
    recording: bool = False,
    hollow: bool = False,
    pulse: bool = True,
    angle: int = 0,
    cover: bytes | None = None,
    tone: str = "ink",
) -> ThemeImage | None:
    """Render a semantic icon with supersampled vector-like Pillow artwork.

    Args:
        master (tk.Misc): Tk widget that owns the returned image handle.
        icon (str): Semantic icon key such as ``"close"`` or ``"folder"``.
        size (tuple[int, int]): Final logical image width and height in pixels.
        pinned (bool): Whether to overlay a retention pin on a tree marker.
        recording (bool): Whether to overlay the Watcher recording marker.
        hollow (bool): Whether recording marks a collapsed ancestor.
        pulse (bool): Whether the recording marker uses its bright phase.
        angle (int): Rotation in degrees for the ``loading_spinner`` arc.
        cover (bytes | None): Optional normalized PNG for folder artwork.
        tone (str): Palette key or current ttk foreground color for its strokes.

    Returns:
        ThemeImage | None: Tk-owned artwork, or None when Pillow is unavailable.
    """
    try:
        from PIL import Image, ImageDraw, ImageOps, ImageTk
    except ImportError:
        return None
    dpi_scale = _tk_dpi_scale(master)
    width, height = size
    scale = max(1, round(_ICON_SUPERSAMPLE * dpi_scale))
    canvas = Image.new("RGBA", (width * scale, height * scale), (0, 0, 0, 0))
    draw = ImageDraw.Draw(canvas)
    ink = _PALETTE.get(tone, tone if tone.startswith("#") else _PALETTE["ink"])
    stroke = max(1, scale)
    cx, cy = width * scale // 2, height * scale // 2
    radius = max(2 * scale, min(width, height) * scale // 3)
    key = _ICON_ALIASES.get(icon, icon)

    def line(points: tuple[tuple[int, int], ...], color: str = ink,
             width_px: int = stroke) -> None:
        """Draw a rounded polyline in logical final-image coordinates.

        Args:
            points (tuple[tuple[int, int], ...]): Logical image coordinates.
            color (str): Palette color used for the stroke.
            width_px (int): Stroke width in supersampled pixels.

        Returns:
            None: Artwork is painted into the current supersampled canvas.
        """
        draw.line([(x * scale, y * scale) for x, y in points],
                  fill=color, width=width_px, joint="curve")

    # These simple logical coordinates define icon silhouettes, not sizing limits.
    if key.startswith("aspect_"):
        ratios = {
            "aspect_default": (4, 3), "aspect_16:9": (16, 9),
            "aspect_21:9": (21, 9), "aspect_4:3": (4, 3),
            "aspect_1:1": (1, 1), "aspect_4:5": (4, 5),
            "aspect_9:16": (9, 16),
        }
        ratio = ratios.get(key)
        if ratio is None:
            return None
        available_width, available_height = width - 4, height - 4
        factor = min(available_width / ratio[0], available_height / ratio[1])
        box_width, box_height = ratio[0] * factor, ratio[1] * factor
        left = (width - box_width) / 2
        top = (height - box_height) / 2
        draw.rectangle(
            (round(left * scale), round(top * scale),
             round((left + box_width) * scale),
             round((top + box_height) * scale)),
            outline=ink, width=stroke,
        )
        if key == "aspect_default":
            line(((round(left) + 2, round(top) + 2),
                  (round(left) + 4, round(top) + 2)))
        if tone == "primary":
            line(((2, height - 1), (width - 2, height - 1)))
    elif key == "folder":
        color = _PALETTE["card_surface_alt"]
        draw.rounded_rectangle((scale, scale, 42 * scale, 15 * scale),
                               radius=5 * scale, fill=color)
        draw.rounded_rectangle((scale, 8 * scale, (width - 2) * scale,
                                (height - 2) * scale), radius=7 * scale,
                               fill=color, outline=_PALETTE["line"],
                               width=stroke)
        if cover is not None:
            try:
                import io
                with Image.open(io.BytesIO(cover)) as source:
                    preview = ImageOps.fit(source.convert("RGB"),
                                           (102 * scale, 48 * scale))
                    canvas.paste(preview, (5 * scale, 12 * scale))
            except (OSError, ValueError):
                pass
    elif key in ("cloud", "local", "partial", "unknown"):
        semantic_color = {
            "cloud": _PALETTE["muted"], "local": _PALETTE["success"],
            "partial": _PALETTE["primary"], "unknown": _PALETTE["muted"],
        }[key]
        color = semantic_color if tone == "ink" else ink
        if key == "cloud":
            draw.ellipse((cx - 7 * scale, cy - 2 * scale,
                          cx + 5 * scale, cy + 6 * scale), fill=color)
            draw.ellipse((cx - 3 * scale, cy - 6 * scale,
                          cx + 5 * scale, cy + 4 * scale), fill=color)
            draw.rounded_rectangle((cx - 8 * scale, cy, cx + 8 * scale,
                                    cy + 6 * scale), radius=3 * scale,
                                   fill=color)
        elif key == "local":
            draw.ellipse((cx - radius, cy - radius, cx + radius, cy + radius),
                         fill=color)
        elif key == "partial":
            draw.pieslice((cx - radius, cy - radius, cx + radius, cy + radius),
                          90, 270, fill=color)
            draw.ellipse((cx - radius, cy - radius, cx + radius, cy + radius),
                         outline=color, width=stroke)
        else:
            draw.ellipse((cx - radius, cy - radius, cx + radius, cy + radius),
                         outline=color, width=stroke)
    elif key == "recording":
        color = (
            _PALETTE["recording" if pulse else "recording_dim"]
            if tone == "ink" else ink
        )
        box = (cx - radius, cy - radius, cx + radius, cy + radius)
        if hollow:
            draw.ellipse(box, outline=color, width=stroke)
        else:
            draw.ellipse(box, fill=color)
    else:
        color = ink
        bounds = (cx - radius, cy - radius, cx + radius, cy + radius)
        if key in ("add", "zoom_in"):
            line(((width // 2, height // 2 - 5), (width // 2, height // 2 + 5)))
            line(((width // 2 - 5, height // 2), (width // 2 + 5, height // 2)))
        elif key in ("remove", "zoom_out"):
            line(((width // 2 - 5, height // 2), (width // 2 + 5, height // 2)))
        elif key == "close":
            line(((width // 2 - 5, height // 2 - 5),
                  (width // 2 + 5, height // 2 + 5)))
            line(((width // 2 + 5, height // 2 - 5),
                  (width // 2 - 5, height // 2 + 5)))
        elif key in ("back", "next"):
            x = width // 2
            direction = 1 if key == "back" else -1
            line(((x + 3 * direction, height // 2 - 6),
                  (x - 3 * direction, height // 2),
                  (x + 3 * direction, height // 2 + 6)))
        elif key == "play":
            draw.polygon(((cx - 4 * scale, cy - 6 * scale),
                          (cx + 6 * scale, cy),
                          (cx - 4 * scale, cy + 6 * scale)), fill=color)
        elif key == "pause":
            draw.rectangle((cx - 5 * scale, cy - 6 * scale,
                            cx - scale, cy + 6 * scale), fill=color)
            draw.rectangle((cx + scale, cy - 6 * scale,
                            cx + 5 * scale, cy + 6 * scale), fill=color)
        elif key == "check":
            line(((width // 2 - 6, height // 2),
                  (width // 2 - 2, height // 2 + 4),
                  (width // 2 + 6, height // 2 - 5)), color)
        elif key in ("help", "info"):
            draw.ellipse(bounds, outline=color, width=stroke)
            if key == "info":
                draw.ellipse((cx - scale, cy - 5 * scale,
                              cx + scale, cy - 3 * scale), fill=color)
                draw.line((cx, cy - scale, cx, cy + 5 * scale), fill=color,
                          width=stroke)
            else:
                draw.arc((cx - 3 * scale, cy - 5 * scale,
                          cx + 4 * scale, cy + 2 * scale), 205, 510,
                         fill=color, width=stroke)
                draw.ellipse((cx - scale, cy + 4 * scale,
                              cx + scale, cy + 6 * scale), fill=color)
        elif key == "settings":
            draw.ellipse(bounds, outline=color, width=stroke)
            draw.ellipse((cx - 2 * scale, cy - 2 * scale,
                          cx + 2 * scale, cy + 2 * scale), fill=color)
            for angle in range(0, 360, 45):
                a = radians(angle)
                line(((round(width / 2 + 4 * cos(a)),
                       round(height / 2 + 4 * sin(a))),
                      (round(width / 2 + 7 * cos(a)),
                       round(height / 2 + 7 * sin(a)))), color)
        elif key == "refresh":
            draw.arc(bounds, 35, 315, fill=color, width=stroke)
            draw.polygon(((cx + 6 * scale, cy - 6 * scale),
                          (cx + 7 * scale, cy),
                          (cx + scale, cy - 2 * scale)), fill=color)
        elif key == "loading_spinner":
            draw.ellipse(
                bounds, outline=_PALETTE["line"], width=stroke,
            )
            draw.arc(
                bounds, angle % 360, angle % 360 + 105,
                fill=_PALETTE["primary"], width=stroke,
            )
        elif key == "queue":
            draw.rectangle((2 * scale, 2 * scale, 11 * scale, 11 * scale),
                           outline=color, width=stroke)
            draw.rectangle((5 * scale, 5 * scale, 14 * scale, 14 * scale),
                           outline=color, width=stroke)
        elif key == "clock":
            draw.ellipse((2 * scale, 2 * scale, 14 * scale, 14 * scale),
                         outline=color, width=stroke)
            line(((8, 4), (8, 8), (11, 10)), color)
        elif key == "stop":
            draw.rectangle((cx - 4 * scale, cy - 4 * scale,
                            cx + 4 * scale, cy + 4 * scale), fill=color)
        elif key == "upload":
            line(((width // 2, height // 2 + 6), (width // 2, height // 2 - 5)))
            line(((width // 2 - 4, height // 2 - 1),
                  (width // 2, height // 2 - 5),
                  (width // 2 + 4, height // 2 - 1)))
            line(((width // 2 - 6, height // 2 + 5),
                  (width // 2 + 6, height // 2 + 5)))
        elif key == "power":
            draw.arc(bounds, 35, 325, fill=color, width=stroke)
            line(((width // 2, height // 2 - 8),
                  (width // 2, height // 2 + 1)))
        elif key == "more":
            for offset in (-5, 0, 5):
                draw.ellipse((cx + offset * scale - scale,
                              cy - scale, cx + offset * scale + scale,
                              cy + scale), fill=color)
        elif key == "list":
            for offset in (-5, 0, 5):
                draw.ellipse((cx - 7 * scale, cy + offset * scale - scale,
                              cx - 5 * scale, cy + offset * scale + scale),
                             fill=color)
                draw.line((cx - 2 * scale, cy + offset * scale,
                           cx + 7 * scale, cy + offset * scale), fill=color,
                          width=stroke)
        elif key == "external":
            draw.rectangle((cx - 7 * scale, cy - 4 * scale,
                            cx + 4 * scale, cy + 7 * scale),
                           outline=color, width=stroke)
            line(((width // 2 - 1, height // 2 + 3),
                  (width // 2 + 7, height // 2 - 5),
                  (width // 2 + 2, height // 2 - 5)))
        elif key == "location":
            draw.ellipse((cx - 6 * scale, cy - 7 * scale,
                          cx + 6 * scale, cy + 5 * scale),
                         outline=color, width=stroke)
            draw.ellipse((cx - 2 * scale, cy - 3 * scale,
                          cx + 2 * scale, cy + scale), fill=color)
            draw.polygon(((cx - 4 * scale, cy + 2 * scale),
                          (cx + 4 * scale, cy + 2 * scale),
                          (cx, cy + 8 * scale)), fill=color)
        elif key in ("fit", "fullscreen"):
            line(((width // 2 - 6, height // 2 - 2),
                  (width // 2 - 6, height // 2 - 6),
                  (width // 2 - 2, height // 2 - 6)))
            line(((width // 2 + 2, height // 2 - 6),
                  (width // 2 + 6, height // 2 - 6),
                  (width // 2 + 6, height // 2 - 2)))
            line(((width // 2 - 6, height // 2 + 2),
                  (width // 2 - 6, height // 2 + 6),
                  (width // 2 - 2, height // 2 + 6)))
            line(((width // 2 + 2, height // 2 + 6),
                  (width // 2 + 6, height // 2 + 6),
                  (width // 2 + 6, height // 2 + 2)))
        elif key == "music":
            draw.ellipse((cx - 7 * scale, cy + 2 * scale,
                          cx - 2 * scale, cy + 6 * scale), fill=color)
            line(((width // 2 - 2, height // 2 + 4),
                  (width // 2 - 2, height // 2 - 6),
                  (width // 2 + 6, height // 2 - 8),
                  (width // 2 + 6, height // 2 + 2)))
            draw.ellipse((cx + scale, cy, cx + 6 * scale, cy + 4 * scale),
                         fill=color)
        elif key == "pin" or pinned:
            draw.ellipse((cx - 4 * scale, cy - 6 * scale,
                          cx + 4 * scale, cy + 2 * scale), fill=color)
            line(((width // 2, height // 2), (width // 2 - 3, height - 2)))
        elif key == "recording" or recording:
            dot_color = _PALETTE["recording" if pulse else "recording_dim"]
            dotbox = (width - 9, 1, width - 2, 8)
            draw.ellipse(tuple(v * scale for v in dotbox),
                         outline=dot_color if hollow else None,
                         fill=None if hollow else dot_color,
                         width=stroke)
        elif key == "cloud":
            draw.ellipse(bounds, outline=color, width=stroke)
        else:
            # Unknown labels stay textual and are not replaced with fallback art.
            return None
    if pinned and key not in ("pin", "folder"):
        px, py = width - 4, 4
        pin_color = _PALETTE["primary"]
        draw.ellipse(((px - 2) * scale, (py - 2) * scale,
                      (px + 2) * scale, (py + 2) * scale), fill=pin_color)
    if recording and key != "recording":
        px, py = width - 4, height - 4
        rec = _PALETTE["recording" if pulse else "recording_dim"]
        draw.ellipse(((px - 3) * scale, (py - 3) * scale,
                      (px + 3) * scale, (py + 3) * scale),
                     outline=rec if hollow else None,
                     fill=None if hollow else rec, width=stroke)
    output_size = (
        max(1, round(width * dpi_scale)), max(1, round(height * dpi_scale)),
    )
    canvas = canvas.resize(output_size, Image.Resampling.LANCZOS)
    if ImageTk is not None:
        return ImageTk.PhotoImage(canvas, master=master)
    return None


class SmoothIconButton(ttk.Button):
    """A ttk button whose known glyph text is backed by smooth icon artwork.

    Args:
        master (tk.Misc): Parent Tk widget.
        icon (str): Initial semantic icon or display text.
        **kwargs (ButtonOptionValue): Standard ttk option values including
            text, image, font, state, style, command and sizing options.
    """

    def __init__(
        self, master: tk.Misc, icon: str, **kwargs: ButtonOptionValue,
    ) -> None:
        """Create the button and bind state-aware icon refreshes.

        Args:
            master (tk.Misc): Parent Tk widget.
            icon (str): Semantic icon or initial display text.
            **kwargs (ButtonOptionValue): Native text, image, font, state, style,
                command, sizing and inherited ttk option values.

        Returns:
            None: The button owns its generated image and event bindings.
        """
        self._smooth_icon = icon
        self._icon_photo: ThemeImage | None = None
        self._external_image = bool(kwargs.get("image"))
        super().__init__(master, **kwargs)
        for sequence in ("<Enter>", "<Leave>", "<FocusIn>", "<FocusOut>",
                         "<ButtonPress-1>", "<ButtonRelease-1>"):
            self.bind(sequence, self._refresh_icon_event, add="+")
        self._refresh_icon()

    def configure(
        self,
        cnf: ButtonOptionMapping | str | None = None,
        **kwargs: ButtonOptionValue,
    ) -> TtkConfigureResult:
        """Apply ttk options and refresh artwork after text changes.

        Args:
            cnf (ButtonOptionMapping | str | None): Option-name/value mapping or
                ttk option-name query passed through to Tkinter.
            **kwargs (ButtonOptionValue): Ttk option values including text,
                image, font, style, state, command and sizing options.

        Returns:
            TtkConfigureResult: Option metadata for a query, queried Tcl value,
                or None after a normal option update.
        """
        text = kwargs.get("text")
        image = kwargs.get("image")
        if isinstance(cnf, Mapping) and "text" in cnf:
            text = cnf["text"]
        if isinstance(cnf, Mapping) and "image" in cnf:
            image = cnf["image"]
        style_changed = isinstance(cnf, Mapping) and (
            "style" in cnf or "state" in cnf
        )
        result = super().configure(cnf, **kwargs)
        if isinstance(text, str):
            self._smooth_icon = text
        if image is not None and bool(image):
            self._external_image = True
        elif isinstance(text, str):
            self._external_image = False
            self._refresh_icon()
        elif image is not None:
            self._external_image = bool(image)
            if not self._external_image:
                self._refresh_icon()
        elif style_changed or "state" in kwargs or "style" in kwargs:
            self._refresh_icon()
        return result

    config = configure

    def state(
        self, statespec: tuple[str, ...] | list[str] | None = None,
    ) -> tuple[str, ...]:
        """Read or update ttk state and repaint when state changes.

        Args:
            statespec (tuple[str, ...] | list[str] | None): Optional state changes.

        Returns:
            tuple[str, ...]: Current ttk state flags.
        """
        result = super().state(statespec)
        if statespec is not None:
            self._refresh_icon()
        return result

    def _refresh_icon_event(self, _event: tk.Event) -> None:
        """Refresh artwork after ttk applies a hover, focus or press state.

        Args:
            _event (tk.Event): Widget event before the ttk class binding runs.

        Returns:
            None: An idle callback reads the settled ttk state.
        """
        try:
            self.after_idle(self._refresh_icon)
        except tk.TclError:
            return

    def _refresh_icon(self) -> None:
        """Render current semantic text and preserve the per-widget photo ref.

        Returns:
            None: The Tcl image option follows current style and widget state.
        """
        if self._external_image:
            return
        label = self._smooth_icon.strip()
        pinned = label.startswith("PIN ")
        semantic_label = label[4:] if pinned else label
        key = _ICON_ALIASES.get(label, _ICON_ALIASES.get(
            semantic_label, semantic_label,
        ))
        try:
            current_state = set(self.state())
            style = ttk.Style(self)
            state = tuple(current_state)
            color = style.lookup(str(self.cget("style")), "foreground", state)
            if not color:
                color = _PALETTE["muted" if "disabled" in current_state else "ink"]
            logical_size = (
                _PHOTOS_ICON_IMAGE_SIZE
                if "Photos.Icon" in str(self.cget("style"))
                else _ICON_IMAGE_SIZE[0]
            )
            size = (logical_size, logical_size)
            image = create_smooth_icon(
                self, key, size, pinned=pinned, tone=color,
            )
            self._icon_photo = image
            super().configure(image=image or "", compound="none")
        except tk.TclError:
            return

def configure_theme(root: tk.Tk) -> None:
    """Apply the uploader palette and shared ttk widget styles.

    Args:
        root (tk.Tk): Application root whose ttk style and base background
            should receive the theme.

    Returns:
        None: The root and its shared ttk style are configured in place.
    """
    style = ttk.Style(root)
    _ui_font = _select_ui_font_family(root)
    if "clam" in style.theme_names():
        style.theme_use("clam")

    colors = _PALETTE
    _install_glossy_elements(root, style)
    root.configure(background=colors["background"])
    # Secondary Tk windows and native menu/popdown surfaces do not inherit ttk
    # frame colors. Set their defaults before any settings sheets are created.
    root.option_add("*Toplevel.background", colors["background"])
    root.option_add("*Menu.background", colors["surface"])
    root.option_add("*Menu.foreground", colors["ink"])
    root.option_add("*Menu.activeBackground", colors["surface_alt"])
    root.option_add("*Menu.activeForeground", colors["ink"])
    root.option_add("*TCombobox*Listbox.background", colors["surface"])
    root.option_add("*TCombobox*Listbox.foreground", colors["ink"])
    style.configure(".", background=colors["background"],
                    foreground=colors["ink"], font=(_ui_font, 10))
    # Unqualified inner frames live inside cards. App/page containers select
    # App.TFrame or Backdrop.TFrame explicitly so toolbars never cut dark strips
    # through an otherwise continuous panel surface.
    style.configure("TFrame", background=colors["card_surface"])
    style.configure("TLabel", background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("App.TLabel", background=colors["background"],
                    foreground=colors["ink"])
    style.configure("Card.TLabel", background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Muted.TLabel", background=colors["card_surface"],
                    foreground=colors["muted"])
    style.configure("Inline.Tooltip.TLabel",
                    background=colors["surface_alt"],
                    foreground=colors["ink"],
                    padding=_INLINE_TOOLTIP_PADDING,
                    relief="solid", borderwidth=1,
                    bordercolor=colors["line"])
    style.configure("Photos.Status.TLabel",
                    background=colors["card_surface"],
                    foreground=colors["ink"],
                    padding=_PHOTOS_STATUS_PADDING)
    style.configure("Title.TLabel", font=(_ui_font, 20, "bold"),
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Glass.Title.TLabel",
                    font=(_ui_font, 20, "bold"),
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Brand.TLabel", font=(_ui_font, 18, "bold"),
                    background=colors["background"],
                    foreground=colors["ink"])
    style.configure("Eyebrow.TLabel", font=(_ui_font, 8, "bold"),
                    background=colors["card_surface"],
                    foreground=colors["muted"])
    style.configure("Status.TLabel", font=(_ui_font, 10, "bold"),
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("App.Status.TLabel", font=(_ui_font, 10, "bold"),
                    background=colors["background"],
                    foreground=colors["ink"])
    style.configure("App.Muted.TLabel", background=colors["background"],
                    foreground=colors["muted"])
    style.configure("App.Eyebrow.TLabel",
                    font=(_ui_font, 8, "bold"),
                    background=colors["background"],
                    foreground=colors["muted"])
    style.configure("Warning.TLabel",
                    background=colors["card_surface_alt"],
                    foreground=colors["warning"], padding=(10, 8))
    style.configure("Watcher.Active.TLabel", font=(_ui_font, 10, "bold"),
                    background=colors["card_surface"], foreground=colors["danger"])
    style.configure("Body.TLabel", background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Footer.TLabel", background=colors["card_surface"],
                    foreground=colors["muted"])
    style.configure("Hero.TLabel", font=(_ui_font, 22, "bold"),
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("HeroTarget.TLabel",
                    font=(_ui_font, 11, "bold"),
                    background=colors["card_surface"],
                    foreground=colors["primary"])
    style.configure("Large.TEntry", font=(_ui_font, 11),
                    padding=(10, 9))
    style.configure("App.TFrame", background=colors["background"])
    style.configure("Backdrop.TFrame", background=colors["background"],
                    relief="flat", borderwidth=0, padding=0)
    style.configure("Footer.TFrame",
                    background=colors["card_surface"],
                    relief="flat", borderwidth=0, padding=(12, 8))
    style.configure("Section.TLabel", font=(_ui_font, 12, "bold"),
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Glass.TFrame", background=colors["card_surface"],
                    relief="flat", borderwidth=0)
    style.configure("GlassInset.TFrame",
                    background=colors["card_surface_alt"],
                    relief="flat", borderwidth=0)
    style.configure("TButton", padding=(12, 6), borderwidth=0,
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Card.TFrame", background=colors["card_surface"],
                    relief="flat", borderwidth=0, padding=0)
    style.map("TButton",
              background=[("pressed", colors["line"]),
                          ("active", colors["surface_alt"])],
              foreground=[("disabled", colors["muted"])])
    style.configure("Compact.TButton", padding=(7, 3), width=-1)
    style.configure("Accent.Compact.TButton", foreground=colors["focus"])
    style.map("Accent.Compact.TButton",
              foreground=[("disabled", colors["muted"])])
    style.configure("Primary.TButton", padding=(12, 6), borderwidth=0,
                    background=colors["primary"], foreground="#F2F6FA",
                    font=(_ui_font, 10, "bold"))
    style.map("Primary.TButton",
              background=[("pressed", colors["primary_pressed"]),
                          ("active", colors["primary_hover"])],
              foreground=[("disabled", "#D8D7FA")])
    style.configure("Icon.TButton", padding=(2, 2),
                    font=(_ui_font, 12),
                    background=colors["card_surface"],
                    foreground=colors["ink"])
    style.configure("Photos.Icon.TButton", padding=_PHOTOS_ICON_PADDING,
                    borderwidth=0,
                    background=colors["card_surface"],
                    foreground=colors["ink"],
                    font=(_ui_font, _PHOTOS_ICON_FONT_SIZE))
    style.map("Photos.Icon.TButton",
              background=[("pressed", colors["line"]),
                          ("active", colors["surface_alt"])],
              foreground=[("disabled", colors["muted"])])
    style.layout("Photos.Icon.TButton", [(
        "Button.border", {"sticky": "nsew", "children": [
            ("Button.padding", {"sticky": "nsew", "children": [
                ("Button.label", {"sticky": "nsew"}),
            ]}),
        ]},
    )])
    style.configure("Format.Photos.Icon.TButton", padding=(3, 1),
                    font=(_ui_font, _PHOTO_FORMAT_LABEL_POINTS), anchor="center")
    style.configure("Selected.Format.Photos.Icon.TButton",
                    padding=(3, 1), font=(_ui_font, _PHOTO_FORMAT_LABEL_POINTS),
                    foreground=colors["primary"], anchor="center",
                    background=colors["surface_alt"])
    for format_style in ("Format.Photos.Icon.TButton",
                         "Selected.Format.Photos.Icon.TButton"):
        style.map(format_style, foreground=[("focus", colors["focus"])])
    style.configure("Action.Photos.Icon.TButton",
                    padding=(6, 3), font="TkDefaultFont", anchor="w")
    style.configure("IconPrimary.TButton", padding=(3, 3),
                    font=(_ui_font, 12),
                    background=colors["primary"], foreground="#F2F6FA")
    style.map("IconPrimary.TButton",
              foreground=[("disabled", "#F0EFFC")],
              background=[("pressed", colors["primary_pressed"]),
                          ("active", colors["primary_hover"])])
    for phase, tone in (("Bright", "recording"), ("Dim", "recording_dim")):
        name = f"Watcher.Recording{phase}.Icon.TButton"
        style.configure(
            name, padding=(3, 3), font=(_ui_font, 12),
            background=colors["background"], foreground=colors[tone],
        )
        style.map(name, foreground=[
            ("disabled", colors[tone]),
            ("focus", colors[tone]),
            ("active", colors[tone]),
        ], background=[
            ("pressed", colors["surface"]),
            ("active", colors["surface_alt"]),
        ])
    style.map("Icon.TButton",
              foreground=[("disabled", colors["muted"])])
    style.configure("TEntry", fieldbackground=colors["surface"],
                    foreground=colors["ink"], insertcolor=colors["ink"],
                    bordercolor=colors["line"], lightcolor=colors["line"],
                    darkcolor=colors["line"], padding=(9, 7))
    style.map("TEntry", bordercolor=[("focus", colors["focus"])])
    style.configure("TCombobox", fieldbackground=colors["surface"],
                    background=colors["surface"], foreground=colors["ink"],
                    padding=(8, 6), arrowcolor=colors["muted"],
                    bordercolor=colors["line"], lightcolor=colors["line"],
                    darkcolor=colors["line"])
    style.map("TCombobox",
              fieldbackground=[("readonly", colors["surface"])],
              foreground=[("readonly", colors["ink"])],
              selectbackground=[("readonly", colors["surface_alt"])],
              selectforeground=[("readonly", colors["ink"])])
    style.configure("TMenubutton", background=colors["surface"],
                    foreground=colors["ink"], arrowcolor=colors["muted"],
                    padding=(8, 6), borderwidth=0, relief="flat")
    style.configure("TCheckbutton", background=colors["card_surface"],
                    foreground=colors["ink"], padding=4)
    style.configure("App.TCheckbutton",
                    background=colors["background"],
                    foreground=colors["ink"], padding=4)
    style.configure("Card.TCheckbutton",
                    background=colors["card_surface"],
                    foreground=colors["ink"], padding=4)
    style.map("TCheckbutton", foreground=[("disabled", colors["muted"])])
    _install_switch_elements(root, style)
    # The icon rail owns app navigation; nested Activity tabs stay visible.
    style.layout("App.TNotebook.Tab", [])
    style.configure("TRadiobutton", background=colors["card_surface"],
                    foreground=colors["ink"], padding=4)
    style.configure("App.TRadiobutton",
                    background=colors["background"],
                    foreground=colors["ink"], padding=4)
    style.configure("Card.TRadiobutton",
                    background=colors["card_surface"],
                    foreground=colors["ink"], padding=4)
    style.configure(
        "Language.TRadiobutton", padding=(10, 5),
        background=colors["card_surface"], foreground=colors["muted"],
        font=(_ui_font, 9, "bold"),
    )
    style.map("Language.TRadiobutton", foreground=[
        ("disabled", colors["muted"]),
        ("selected", colors["ink"]),
        ("focus", colors["focus"]),
        ("active", colors["focus"]),
    ])
    segment_content = [("Radiobutton.padding", {
        "sticky": "nswe", "children": [
            ("Radiobutton.label", {"sticky": "nswe"}),
        ],
    })]
    style.layout("Language.TRadiobutton", (
        [("Gloss.segment", {"sticky": "nswe", "children": segment_content})]
        if "Gloss.segment" in style.element_names() else segment_content
    ))
    style.configure("TLabelframe", background=colors["card_surface"],
                    bordercolor=colors["line"], relief="flat")
    style.configure("TLabelframe.Label",
                    background=colors["card_surface"],
                    foreground=colors["muted"],
                    font=(_ui_font, 10, "bold"))
    style.configure("TNotebook", background=colors["background"],
                    borderwidth=0, bordercolor=colors["background"],
                    lightcolor=colors["background"],
                    darkcolor=colors["background"],
                    tabmargins=(0, 0, 0, 0))
    style.configure("App.TNotebook", background=colors["background"],
                    borderwidth=0, bordercolor=colors["background"],
                    lightcolor=colors["background"],
                    darkcolor=colors["background"],
                    tabmargins=(0, 0, 0, 0))
    style.configure("TNotebook.Tab", padding=(10, 6),
                    background=colors["card_surface_alt"],
                    foreground=colors["muted"])
    style.map("TNotebook.Tab",
              background=[("selected", colors["surface"])],
              foreground=[("selected", colors["primary"])])
    style.configure("Treeview",
                    rowheight=_scale_ui_pixels(root, _TREEVIEW_ROW_HEIGHT),
                    borderwidth=0,
                    background=colors["surface"],
                    fieldbackground=colors["surface"],
                    foreground=colors["ink"],
                    bordercolor=colors["surface"],
                    lightcolor=colors["surface"],
                    darkcolor=colors["surface"])
    style.configure("Treeview.Heading", padding=(9, 7),
                    background=colors["surface_alt"],
                    foreground=colors["muted"],
                    bordercolor=colors["surface_alt"],
                    lightcolor=colors["surface_alt"],
                    darkcolor=colors["surface_alt"], borderwidth=0,
                    relief="flat",
                    font=(_ui_font, 9, "bold"))
    style.map("Treeview.Heading",
              background=[("active", colors["line"])],
              foreground=[("active", colors["ink"])],
              relief=[("active", "flat"), ("!active", "flat")])
    style.map("Treeview", background=[("selected", colors["surface_alt"])],
              foreground=[("selected", colors["ink"])])
    style.configure("Horizontal.TProgressbar",
                    background=colors["primary"],
                    troughcolor=colors["line"], borderwidth=0,
                    thickness=8)
    style.configure("Vertical.TScrollbar",
                    background=colors["card_surface_alt"],
                    troughcolor=colors["background"],
                    bordercolor=colors["background"], borderwidth=0,
                    arrowsize=12, arrowcolor=colors["muted"])
    style.configure("Horizontal.TScrollbar",
                    background=colors["card_surface_alt"],
                    troughcolor=colors["background"],
                    bordercolor=colors["background"], borderwidth=0,
                    arrowsize=12, arrowcolor=colors["muted"])
    for orientation, sticky in (("Vertical", "ns"), ("Horizontal", "ew")):
        name = orientation + ".TScrollbar"
        style.configure(
            name, arrowsize=10, relief="flat", gripcount=0,
            lightcolor=colors["line"], darkcolor=colors["line"],
        )
        style.map(name, background=[
            ("active", colors["muted"]),
            ("!active", colors["line"]),
        ])
        style.layout(name, [(orientation + ".Scrollbar.trough", {
            "sticky": sticky, "children": [
                (orientation + ".Scrollbar.thumb", {
                    "sticky": "nswe", "expand": True,
                }),
            ],
        })])
    _install_focus_styles(style)
    # Context actions are glyphs, not miniature boxed buttons. Preserve ttk
    # keyboard activation and focus while painting only a quiet hover surface.
    for name in ("Icon.TButton", "App.Icon.TButton", "IconPrimary.TButton"):
        style.layout(name, [("Button.padding", {
            "sticky": "nswe", "children": [
                ("Button.label", {"sticky": "nswe"}),
            ],
        })])
        style.configure(name, padding=(3, 3), borderwidth=0, relief="flat")
        style.map(name, foreground=[
            ("disabled", colors["muted"]),
            ("focus", colors["focus"]),
            ("active", colors["focus"]),
        ])
    style.configure("App.Icon.TButton", background=colors["background"])
    style.map("App.Icon.TButton", background=[
        ("active", colors["surface_alt"]),
        ("pressed", colors["surface"]),
    ])


def paint_backdrop(widget: ttk.Frame) -> None:
    """Match the container to the rounded artwork's opaque exterior matte.

    Args:
        widget (ttk.Frame): App, page or scrolling-content container.

    Returns:
        None: Applies the uniform backing color without resize canvases or
            rectangular color tiles around rounded card corners.
    """
    widget.configure(style="Backdrop.TFrame")


def _install_glossy_elements(root: tk.Tk, style: ttk.Style) -> None:
    """Register opaque glass artwork and bind it to reusable ttk styles.

    Args:
        root (tk.Tk): Tk interpreter that owns generated PhotoImage objects.
        style (ttk.Style): Theme registry receiving the image elements.

    Returns:
        None: Material elements and image references are installed, or ttk
            uses readable flat styles when optional Pillow is unavailable.
    """
    if "Gloss.card" in style.element_names():
        return
    try:
        from PIL import Image, ImageDraw, ImageFilter, ImageTk
    except ImportError:
        # Uploader runtime compatibility owner: Pillow is visual-only, so
        # source mode, --once and native repair stay supported without it.
        return

    scale = _ARTWORK_SCALE
    # Material artwork is composited into opaque RGB images before Tk sees it.
    # That keeps Windows ttk from treating transparent image pixels as black.
    frost_tint = _hex_rgb("#2A4353")
    glass_top = _blend_rgb(
        _hex_rgb("#243442"), frost_tint, _FROST_TINT_BLEND
    )
    glass_bottom = _blend_rgb(
        _hex_rgb("#263541"), _hex_rgb("#233544"), _FROST_TINT_BLEND
    )
    inset_top = _blend_rgb(
        _hex_rgb("#2B3D4D"), frost_tint, _FROST_TINT_BLEND
    )
    inset_bottom = _blend_rgb(
        _hex_rgb("#293B4A"), _hex_rgb("#2A4050"), _FROST_TINT_BLEND
    )
    asset_specs = {
        "button": (("#2B4051", "#263A4B"), _PALETTE["card_surface"],
                   _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                   _BUTTON_IMAGE_SIZE),
        "button_active": (("#3A556A", "#33536A"), _PALETTE["card_surface"],
                          _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                          _BUTTON_IMAGE_SIZE),
        "button_pressed": (("#1E3445", "#1B3040"), _PALETTE["card_surface"],
                           _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                           _BUTTON_IMAGE_SIZE),
        "button_focus": (("#2B4051", "#263A4B"), _PALETTE["card_surface"],
                         _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                         _BUTTON_IMAGE_SIZE),
        "button_selection": ((_PALETTE["card_surface"],
                              _PALETTE["card_surface"]),
                              _PALETTE["card_surface"],
                              GALLERY_TILE_BORDER_INSET,
                              _GALLERY_SELECTION_CORNER_RADIUS,
                              _BUTTON_IMAGE_SIZE),
        "gallery_tile": ((_PALETTE["card_surface"], _PALETTE["card_surface"]),
                         _PALETTE["card_surface"], GALLERY_TILE_BORDER_INSET,
                         _GALLERY_SELECTION_CORNER_RADIUS,
                         _BUTTON_IMAGE_SIZE),
        "button_disabled": (("#2B3742", "#26333E"), _PALETTE["card_surface"],
                             _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                             _BUTTON_IMAGE_SIZE),
        "primary": (("#16BBDC", "#089ABA"), _PALETTE["card_surface"],
                    _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                    _BUTTON_IMAGE_SIZE),
        "primary_active": (("#39CDE8", "#0EAACB"), _PALETTE["card_surface"],
                           _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                           _BUTTON_IMAGE_SIZE),
        "primary_pressed": (("#079AB9", "#007F9D"), _PALETTE["card_surface"],
                            _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                            _BUTTON_IMAGE_SIZE),
        "primary_focus": (("#16BBDC", "#089ABA"), _PALETTE["card_surface"],
                          _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                          _BUTTON_IMAGE_SIZE),
        "primary_disabled": (("#446C79", "#345764"), _PALETTE["card_surface"],
                             _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                             _BUTTON_IMAGE_SIZE),
        "icon": (("#2B4051", "#263A4B"), _PALETTE["card_surface"],
                 _ICON_BORDER, _ICON_CORNER_RADIUS, _ICON_IMAGE_SIZE),
        "icon_active": (("#3A556A", "#33536A"), _PALETTE["card_surface"],
                        _ICON_BORDER, _ICON_CORNER_RADIUS, _ICON_IMAGE_SIZE),
        "icon_pressed": (("#1E3445", "#1B3040"), _PALETTE["card_surface"],
                         _ICON_BORDER, _ICON_CORNER_RADIUS, _ICON_IMAGE_SIZE),
        "icon_focus": (("#2B4051", "#263A4B"), _PALETTE["card_surface"],
                       _ICON_BORDER, _ICON_CORNER_RADIUS, _ICON_IMAGE_SIZE),
        "icon_disabled": (("#2B3742", "#26333E"), _PALETTE["card_surface"],
                          _ICON_BORDER, _ICON_CORNER_RADIUS, _ICON_IMAGE_SIZE),
        "icon_primary": (("#16BBDC", "#089ABA"), _PALETTE["card_surface"],
                         _ICON_BORDER, _ICON_CORNER_RADIUS, _ICON_IMAGE_SIZE),
        "icon_primary_active": (("#39CDE8", "#0EAACB"), _PALETTE["card_surface"],
                                _ICON_BORDER, _ICON_CORNER_RADIUS,
                                _ICON_IMAGE_SIZE),
        "icon_primary_pressed": (("#079AB9", "#007F9D"), _PALETTE["card_surface"],
                                 _ICON_BORDER, _ICON_CORNER_RADIUS,
                                 _ICON_IMAGE_SIZE),
        "icon_primary_focus": (("#16BBDC", "#089ABA"), _PALETTE["card_surface"],
                               _ICON_BORDER, _ICON_CORNER_RADIUS,
                               _ICON_IMAGE_SIZE),
        "icon_primary_disabled": (("#446C79", "#345764"), _PALETTE["card_surface"],
                                  _ICON_BORDER, _ICON_CORNER_RADIUS,
                                  _ICON_IMAGE_SIZE),
        "tab": (("#243745", "#213241"), _PALETTE["background"],
                _BUTTON_BORDER, _BUTTON_CORNER_RADIUS, _BUTTON_IMAGE_SIZE),
        "tab_active": (("#365768", "#2B485C"), _PALETTE["background"],
                       _BUTTON_BORDER, _BUTTON_CORNER_RADIUS,
                       _BUTTON_IMAGE_SIZE),
        "panel": ((glass_top, glass_bottom), "#131B26",
                  _PANEL_BORDER, _PANEL_CORNER_RADIUS, _PANEL_IMAGE_SIZE),
        "inset": ((inset_top, inset_bottom), "#131B26",
                  _PANEL_BORDER, _PANEL_CORNER_RADIUS, _PANEL_IMAGE_SIZE),
    }
    for name, (gradient, matte, border, radius, size) in asset_specs.items():
        width, height = size
        scaled_size = (width * scale, height * scale)
        matte_rgb = _hex_rgb(matte)
        artwork = Image.new("RGB", scaled_size, matte_rgb)
        if name in ("panel", "inset"):
            shadow = _blend_rgb(
                matte_rgb, _hex_rgb("#0D131C"), _PANEL_SHADOW_BLEND
            )
            shadow_mask = Image.new("L", scaled_size, 0)
            shadow_draw = ImageDraw.Draw(shadow_mask)
            shadow_draw.rounded_rectangle(
                (scale * 2, scale * 2,
                 scaled_size[0] - scale * 2 - 1,
                 scaled_size[1] - scale * 2 - 1),
                radius=radius * scale,
                fill=255,
            )
            shadow_mask = shadow_mask.filter(
                ImageFilter.GaussianBlur(_PANEL_SHADOW_BLUR * scale)
            )
            artwork.paste(shadow, mask=shadow_mask)
        mask = Image.new("L", scaled_size, 0)
        mask_draw = ImageDraw.Draw(mask)
        scaled_radius = radius * scale
        mask_draw.rounded_rectangle(
            (scale, scale, scaled_size[0] - scale - 1,
             scaled_size[1] - scale - 1),
            radius=scaled_radius,
            fill=255,
        )
        top = (
            gradient[0] if isinstance(gradient[0], tuple)
            else _hex_rgb(gradient[0])
        )
        bottom = (
            gradient[1] if isinstance(gradient[1], tuple)
            else _hex_rgb(gradient[1])
        )
        center = (
            _hex_rgb(_PALETTE["card_surface"])
            if name == "panel" else
            _hex_rgb(_PALETTE["card_surface_alt"])
            if name == "inset" else
            _blend_rgb(top, bottom, _MATERIAL_CENTER_BLEND)
        )
        gradient_image = Image.new("RGB", scaled_size, center)
        gradient_draw = ImageDraw.Draw(gradient_image)
        scaled_border = border * scale
        for y in range(scaled_size[1]):
            if y < scaled_border:
                progress = y / max(1, scaled_border)
                row = _blend_rgb(top, center, progress)
            elif y >= scaled_size[1] - scaled_border:
                progress = (
                    y - (scaled_size[1] - scaled_border)
                ) / max(1, scaled_border - 1)
                row = _blend_rgb(center, bottom, progress)
            else:
                row = center
            gradient_draw.line(
                (0, y, scaled_size[0] - 1, y), fill=row
            )
        artwork.paste(gradient_image, mask=mask)
        draw = ImageDraw.Draw(artwork)
        if name not in ("gallery_tile", "button_selection"):
            draw.rounded_rectangle(
                (scale, scale, scaled_size[0] - scale - 1,
                 scaled_size[1] - scale - 1),
                radius=scaled_radius,
                outline=_hex_rgb(_PALETTE["line"]),
                width=max(1, scale),
            )
        # The quiet slate edge supplies the rounded rim. Keep every
        # stretchable slice uniform to avoid dashed highlights from tiling.
        if name in (
            "button_focus", "primary_focus", "icon_focus",
            "icon_primary_focus", "button_selection",
        ):
            draw.rounded_rectangle(
                (0, 0, scaled_size[0] - 1, scaled_size[1] - 1),
                radius=scaled_radius,
                outline=_hex_rgb(
                    _PALETTE["primary"] if name == "button_selection"
                    else _PALETTE["focus"]
                ),
                width=scale * 2,
            )
        artwork = artwork.resize(size, Image.Resampling.LANCZOS)
        _THEME_IMAGES[name] = ImageTk.PhotoImage(artwork, master=root)

    button_border = _BUTTON_BORDER
    panel_border = _PANEL_BORDER
    button_options = {
        "border": (button_border, button_border,
                   button_border, button_border),
        "sticky": "nsew",
    }
    panel_options = {
        "border": (panel_border, panel_border,
                   panel_border, panel_border),
        "sticky": "nsew",
    }
    style.element_create(
        "Gloss.button", "image", _THEME_IMAGES["button"],
        ("disabled", _THEME_IMAGES["button_disabled"]),
        ("pressed", _THEME_IMAGES["button_pressed"]),
        ("active", _THEME_IMAGES["button_active"]),
        ("focus", _THEME_IMAGES["button_focus"]), **button_options,
    )
    style.element_create(
        "Gallery.tile", "image", _THEME_IMAGES["gallery_tile"],
        ("selected", _THEME_IMAGES["button_selection"]),
        border=(
            GALLERY_TILE_BORDER_INSET, GALLERY_TILE_BORDER_INSET,
            GALLERY_TILE_BORDER_INSET, GALLERY_TILE_BORDER_INSET,
        ),
        sticky="nsew",
    )
    style.element_create(
        "Gloss.primary", "image", _THEME_IMAGES["primary"],
        ("disabled", _THEME_IMAGES["primary_disabled"]),
        ("pressed", _THEME_IMAGES["primary_pressed"]),
        ("active", _THEME_IMAGES["primary_active"]),
        ("focus", _THEME_IMAGES["primary_focus"]), **button_options,
    )
    style.element_create(
        "Gloss.segment", "image", _THEME_IMAGES["button"],
        ("disabled", _THEME_IMAGES["button_disabled"]),
        ("selected", "focus", _THEME_IMAGES["primary_focus"]),
        ("selected", _THEME_IMAGES["primary"]),
        ("focus", _THEME_IMAGES["button_focus"]),
        ("active", _THEME_IMAGES["button_active"]), **button_options,
    )
    style.element_create(
        "Gloss.icon", "image", _THEME_IMAGES["icon"],
        ("disabled", _THEME_IMAGES["icon_disabled"]),
        ("pressed", _THEME_IMAGES["icon_pressed"]),
        ("active", _THEME_IMAGES["icon_active"]),
        ("focus", _THEME_IMAGES["icon_focus"]), **{
            "border": (_ICON_BORDER, _ICON_BORDER,
                       _ICON_BORDER, _ICON_BORDER),
            "sticky": "nsew",
        },
    )
    style.element_create(
        "Gloss.iconPrimary", "image", _THEME_IMAGES["icon_primary"],
        ("disabled", _THEME_IMAGES["icon_primary_disabled"]),
        ("pressed", _THEME_IMAGES["icon_primary_pressed"]),
        ("active", _THEME_IMAGES["icon_primary_active"]),
        ("focus", _THEME_IMAGES["icon_primary_focus"]), **{
            "border": (_ICON_BORDER, _ICON_BORDER,
                       _ICON_BORDER, _ICON_BORDER),
            "sticky": "nsew",
        },
    )
    style.element_create("Gloss.card", "image", _THEME_IMAGES["panel"],
                         **panel_options)
    style.element_create("Gloss.inset", "image", _THEME_IMAGES["inset"],
                         **panel_options)
    style.element_create(
        "Gloss.tab", "image", _THEME_IMAGES["tab"],
        ("selected", _THEME_IMAGES["tab_active"]),
        ("active", _THEME_IMAGES["tab_active"]), **button_options,
    )
    style.layout("Glass.TFrame", [("Gloss.card", {"sticky": "nsew"})])
    style.layout("Footer.TFrame", [("Gloss.card", {"sticky": "nsew"})])
    style.layout("GlassInset.TFrame", [("Gloss.inset", {"sticky": "nsew"})])
    style.layout("TNotebook.Tab", [(
        "Gloss.tab", {"sticky": "nsew", "children": [
            ("Notebook.padding", {"sticky": "nsew", "children": [
                ("Notebook.label", {"sticky": "nsew"}),
            ]}),
        ]},
    )])
    for name, element in (
        ("TButton", "Gloss.button"),
        ("Primary.TButton", "Gloss.primary"),
        ("Icon.TButton", "Gloss.icon"),
        ("IconPrimary.TButton", "Gloss.iconPrimary"),
    ):
        style.layout(name, [(
            element,
            {"sticky": "nsew", "children": [
                ("Button.padding", {"sticky": "nsew", "children": [
                    ("Button.label", {"sticky": "nsew"}),
                ]}),
            ]},
        )])


def _install_switch_elements(root: tk.Tk, style: ttk.Style) -> None:
    """Replace square checkbox indicators with keyboard-native pill switches.

    Args:
        root (tk.Tk): Interpreter owning the retained switch images.
        style (ttk.Style): Registry shared by all uploader boolean controls.

    Returns:
        None: Registers selected, disabled and focused switch states. Without
            Pillow, the existing ttk indicator remains usable in source mode.
    """
    if "Switch.indicator" in style.element_names():
        return
    try:
        from PIL import Image, ImageDraw, ImageTk
    except ImportError:
        # Presentation owner: source-mode dependency repair must stay usable
        # without Pillow. Retire only if Pillow becomes mandatory at launch.
        return
    width, height = _SWITCH_IMAGE_SIZE
    scale = _ARTWORK_SCALE
    for selected in (False, True):
        for state in ("normal", "focus", "disabled"):
            name = f"switch_{selected}_{state}"
            artwork = Image.new(
                "RGB", (width * scale, height * scale),
                _PALETTE["card_surface"],
            )
            draw = ImageDraw.Draw(artwork)
            track = _PALETTE["success"] if selected else "#607182"
            if state == "disabled":
                track = "#40525F"
            # Two-pixel inset leaves room for the keyboard-focus outline.
            draw.rounded_rectangle(
                (2 * scale, 2 * scale,
                 (width - 2) * scale, (height - 2) * scale),
                radius=(height // 2) * scale, fill=track,
            )
            thumb = height - 8
            left = width - thumb - 4 if selected else 4
            draw.ellipse(
                (left * scale, 4 * scale,
                 (left + thumb) * scale, (height - 4) * scale),
                fill="#F1F6FA" if state != "disabled" else "#8B9AA6",
            )
            if state == "focus":
                draw.rounded_rectangle(
                    (0, 0, width * scale - 1, height * scale - 1),
                    radius=(height // 2) * scale,
                    outline=_PALETTE["focus"], width=scale,
                )
            _THEME_IMAGES[name] = ImageTk.PhotoImage(
                artwork.resize((width, height), Image.Resampling.LANCZOS),
                master=root,
            )
    style.element_create(
        "Switch.indicator", "image", _THEME_IMAGES["switch_False_normal"],
        ("disabled", "selected", _THEME_IMAGES["switch_True_disabled"]),
        ("disabled", _THEME_IMAGES["switch_False_disabled"]),
        ("focus", "selected", _THEME_IMAGES["switch_True_focus"]),
        ("focus", _THEME_IMAGES["switch_False_focus"]),
        ("selected", _THEME_IMAGES["switch_True_normal"]),
        width=width + 6, sticky="w",
    )
    for name in ("TCheckbutton", "App.TCheckbutton", "Card.TCheckbutton"):
        style.layout(name, [("Checkbutton.padding", {
            "sticky": "nswe", "children": [
                ("Switch.indicator", {"side": "left", "sticky": "w"}),
                ("Checkbutton.label", {"side": "left", "sticky": "w"}),
            ],
        })])
        style.map(name, background=[("active", _PALETTE["card_surface"])])


def _hex_rgb(value: str) -> tuple[int, int, int]:
    """Convert a six-digit hexadecimal color to its RGB channels.

    Args:
        value (str): Color in ``#RRGGBB`` form.

    Returns:
        tuple[int, int, int]: Red, green and blue channels from 0 to 255.
    """
    return (int(value[1:3], 16), int(value[3:5], 16),
            int(value[5:7], 16))


def _blend_rgb(
    base: tuple[int, int, int],
    tint: tuple[int, int, int],
    amount: float,
) -> tuple[int, int, int]:
    """Blend two opaque RGB colors for a frosted surface tint.

    Args:
        base (tuple[int, int, int]): Opaque source channels in the 0–255 range.
        tint (tuple[int, int, int]): Ambient color channels in the 0–255 range.
        amount (float): Tint contribution from 0.0 through 1.0.

    Returns:
        tuple[int, int, int]: Rounded opaque RGB channels after composition.
    """
    return (
        round(base[0] * (1.0 - amount) + tint[0] * amount),
        round(base[1] * (1.0 - amount) + tint[1] * amount),
        round(base[2] * (1.0 - amount) + tint[2] * amount),
    )


def _install_focus_styles(style: ttk.Style) -> None:
    """Set visible focus cues for keyboard users across interactive controls.

    Args:
        style (ttk.Style): Style registry configured for the application.

    Returns:
        None: Focus maps are added to the supplied style registry.
    """
    focus = _PALETTE["focus"]
    for name in ("TButton", "Primary.TButton", "Icon.TButton",
                 "IconPrimary.TButton", "Photos.Icon.TButton",
                 "TEntry", "TCombobox"):
        style.map(name, bordercolor=[("focus", focus)])
    style.map("Gallery.Section.TLabel", foreground=[("focus", focus)])


def icon_button(parent: tk.Misc, icon: str,
                command: Callable[[], None], tooltip: str | tk.StringVar,
                primary: bool = False,
                tooltip_surface: tk.Misc | None = None,
                style_override: str | None = None) -> ttk.Button:
    """Create a compact icon button with an accessible tooltip.

    Args:
        parent (tk.Misc): Tk widget that owns the new button.
        icon (str): Semantic icon key, single glyph or short visible label.
        command (Callable[[], None]): Action invoked by mouse or keyboard.
        tooltip (str | tk.StringVar): Fixed or live accessible hover description.
        primary (bool): Whether to use the cyan primary action style.
        tooltip_surface (tk.Misc | None): Existing widget that may host an
            inline tooltip instead of creating a transient window.
        style_override (str | None): Optional ttk style for scoped controls.

    Returns:
        ttk.Button: The themed, bound button widget.
    """
    glyph = _ICON_GLYPHS.get(icon, icon)
    icon_only = icon in _ICON_GLYPHS or (
        len(icon.strip()) == 1
        and not any(character.isspace() for character in icon)
    )
    if style_override is not None:
        style = style_override
    else:
        style = (
            "IconPrimary.TButton" if primary else "Icon.TButton"
        ) if icon_only else ("Primary.TButton" if primary else "TButton")
        if icon_only and not primary:
            container_style = str(parent.cget("style"))
            background = ttk.Style(parent).lookup(
                container_style, "background",
            )
            if background == _PALETTE["background"]:
                style = "App.Icon.TButton"
    button = SmoothIconButton(parent, icon, text=glyph, command=command, style=style,
                        takefocus=True,
                        width=_ICON_BUTTON_COLUMNS if icon_only else -1)
    button.configure(cursor="hand2")
    if tooltip:
        if tooltip_surface is None:
            _attach_tooltip(button, tooltip)
        else:
            attach_inline_tooltip(button, tooltip, tooltip_surface)
    return button


def help_button(parent: tk.Misc, title: str, body: str) -> ttk.Button:
    """Create a help button that opens a focused, selectable help overlay.

    Args:
        parent (tk.Misc): Tk widget that owns the new button.
        title (str): Short heading displayed in the help window.
        body (str): Plain text instructions that users can select and copy.

    Returns:
        ttk.Button: A themed question-mark button that opens the overlay.
    """
    button: ttk.Button

    def open_help() -> None:
        """Open the help window and move keyboard focus into its text.

        Returns:
            None: A transient help window is shown when its owner exists.
        """
        if not button.winfo_exists():
            return
        _show_help(button, title, body)

    button = icon_button(parent, "help", open_help,
                         tooltip=f"Help: {title}")
    return button


class GlassCard(ttk.Frame):
    """Frosted tinted ttk frame for grouping uploader controls.

    The rounded RGB material simulates a translucent card while keeping all
    actual Tk pixels opaque. Callers can pass standard ttk.Frame options.
    """

    def __init__(
        self,
        parent: tk.Misc,
        *,
        rounded_clip: bool = False,
        **kwargs: str | int | bool | tuple[int, ...] | tk.Misc | ThemeImage,
    ) -> None:
        """Create a themed card, optionally clipping its rounded corners.

        Args:
            parent (tk.Misc): Parent widget that owns the card.
            rounded_clip (bool): Whether to clip the opaque exterior matte from
                this card's HWND on Windows; unsupported platforms use a flat
                opaque contextual style.
            **kwargs (str | int | bool | tuple[int, ...] | tk.Misc | ThemeImage):
                Standard ttk.Frame options such as style, padding, dimensions
                and an optional Tk image.

        Returns:
            None: The initialized frame is ready to receive child widgets.
        """
        self._rounded_region_api = (
            _load_context_region_api() if rounded_clip else None
        )
        self._rounded_region_active = rounded_clip and (
            self._rounded_region_api is not None
        )
        self._rounded_region_size: tuple[int, int] | None = None
        self._rounded_region_after: str | None = None
        if rounded_clip and not self._rounded_region_active:
            ttk.Style(parent).configure(
                "ContextFallback.TFrame",
                background=_PALETTE["card_surface"],
                relief="flat", borderwidth=0,
            )
            kwargs.setdefault("style", "ContextFallback.TFrame")
        else:
            kwargs.setdefault("style", "Glass.TFrame")
        super().__init__(parent, **kwargs)
        if self._rounded_region_active:
            self.bind("<Configure>", self._schedule_rounded_region, add="+")
            self.bind("<Destroy>", self._dispose_rounded_region, add="+")

    def _schedule_rounded_region(self, event: tk.Event) -> None:
        """Defer native shape updates until Tk finishes layout negotiation.

        Args:
            event (tk.Event): Configure event for the card's actual HWND.

        Returns:
            None: At most one idle callback is queued for the current geometry.
        """
        if (event.widget is not self or not self._rounded_region_active
                or self._rounded_region_after is not None):
            return
        self._rounded_region_after = self.after_idle(
            self._apply_rounded_region,
        )

    def _apply_rounded_region(self) -> None:
        """Apply a DPI-consistent native rounded region to this card HWND.

        Returns:
            None: Windows owns a successful HRGN; unsupported geometry falls
                back to the flat opaque style without leaking GDI objects.
        """
        self._rounded_region_after = None
        if not self._rounded_region_active or not self.winfo_exists():
            return
        width, height = self.winfo_width(), self.winfo_height()
        if width <= 0 or height <= 0:
            return
        if self._rounded_region_size == (width, height):
            return

        api = self._rounded_region_api
        if api is None:
            self._use_context_fallback()
            return
        radius = min(_PANEL_CORNER_RADIUS, width // 2, height // 2)
        if radius <= 0:
            return
        # GDI clips the final raster row/column at the nominal half-open bounds.
        # The extra pixel keeps the HWND's last in-window pixel inside the region.
        try:
            region = api.gdi32.CreateRoundRectRgn(
                # The corner ellipse dimensions are the panel radius diameter.
                0, 0, width + 1, height + 1, radius * 2, radius * 2,
            )
        except (AttributeError, OSError, TypeError, ValueError,
                ctypes.ArgumentError):
            self._use_context_fallback()
            return
        if not region:
            self._use_context_fallback()
            return
        try:
            # The final Win32 flag is TRUE so Windows redraws the shaped child.
            result = api.user32.SetWindowRgn(
                ctypes.c_void_p(self.winfo_id()), ctypes.c_void_p(region), 1,
            )
        except (AttributeError, OSError, TypeError, ValueError,
                ctypes.ArgumentError):
            try:
                api.gdi32.DeleteObject(ctypes.c_void_p(region))
            except (AttributeError, OSError, TypeError, ValueError,
                    ctypes.ArgumentError):
                pass
            self._use_context_fallback()
            return
        if not result:
            try:
                api.gdi32.DeleteObject(ctypes.c_void_p(region))
            except (AttributeError, OSError, TypeError, ValueError,
                    ctypes.ArgumentError):
                pass
            self._use_context_fallback()
            return
        # SetWindowRgn transfers HRGN ownership to Windows on success.
        self._rounded_region_size = (width, height)

    def _use_context_fallback(self) -> bool:
        """Switch to an opaque flat card after native clipping is unavailable.

        Returns:
            bool: True when the old region was removed and the flat style was
                applied; False when Windows refused to clear the prior region.
        """
        api = self._rounded_region_api
        if api is not None and self._rounded_region_size is not None:
            try:
                # Passing a null HRGN removes the old shape from this HWND.
                cleared = api.user32.SetWindowRgn(
                    ctypes.c_void_p(self.winfo_id()), None, 1,
                )
            except (AttributeError, OSError, TypeError, ValueError,
                    ctypes.ArgumentError):
                cleared = 0
            if not cleared:
                # Retain the API and resize binding so a later Configure can
                # retry; dropping it here would leave a stale clipped region.
                return False
        self._rounded_region_size = None
        self._rounded_region_active = False
        self._rounded_region_api = None
        ttk.Style(self).configure(
            "ContextFallback.TFrame",
            background=_PALETTE["card_surface"],
            relief="flat", borderwidth=0,
        )
        self.configure(style="ContextFallback.TFrame")
        return True

    def _dispose_rounded_region(self, event: tk.Event) -> None:
        """Cancel pending region work when this card is destroyed.

        Args:
            event (tk.Event): Destroy event identifying the widget being removed.

        Returns:
            None: No callback or caller-owned HRGN remains after destruction.
        """
        if event.widget is not self or self._rounded_region_after is None:
            return
        try:
            self.after_cancel(self._rounded_region_after)
        except tk.TclError:
            pass
        self._rounded_region_after = None


@dataclass(frozen=True, slots=True)
class _ContextRegionApi:
    """Own trusted system DLL interfaces for rounded child-window regions.

    Attributes:
        user32 (ctypes.CDLL): System user32 exports for setting and querying
            one child-window region.
        gdi32 (ctypes.CDLL): System GDI exports for region creation, point
            checks and deletion.
    """

    user32: ctypes.CDLL
    gdi32: ctypes.CDLL


def _load_context_region_api() -> _ContextRegionApi | None:
    """Load typed window-region functions only from Windows system libraries.

    Returns:
        _ContextRegionApi | None: Configured system APIs on Windows, or None
            so the caller can select a flat opaque fallback.
    """
    if sys.platform != "win32":
        return None
    try:
        user32 = ctypes.WinDLL(
            "user32.dll", winmode=_LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        gdi32 = ctypes.WinDLL(
            "gdi32.dll", winmode=_LOAD_LIBRARY_SEARCH_SYSTEM32,
        )
        gdi32.CreateRoundRectRgn.argtypes = [
            ctypes.c_int, ctypes.c_int, ctypes.c_int, ctypes.c_int,
            ctypes.c_int, ctypes.c_int,
        ]
        gdi32.CreateRoundRectRgn.restype = ctypes.c_void_p
        gdi32.DeleteObject.argtypes = [ctypes.c_void_p]
        gdi32.DeleteObject.restype = ctypes.c_int
        user32.SetWindowRgn.argtypes = [
            ctypes.c_void_p, ctypes.c_void_p, ctypes.c_int,
        ]
        user32.SetWindowRgn.restype = ctypes.c_int
        user32.GetWindowRgn.argtypes = [ctypes.c_void_p, ctypes.c_void_p]
        user32.GetWindowRgn.restype = ctypes.c_int
        gdi32.CreateRectRgn.argtypes = [
            ctypes.c_int, ctypes.c_int, ctypes.c_int, ctypes.c_int,
        ]
        gdi32.CreateRectRgn.restype = ctypes.c_void_p
        gdi32.PtInRegion.argtypes = [
            ctypes.c_void_p, ctypes.c_int, ctypes.c_int,
        ]
        gdi32.PtInRegion.restype = ctypes.c_int
        return _ContextRegionApi(user32=user32, gdi32=gdi32)
    except (AttributeError, OSError, TypeError, ValueError):
        return None


@dataclass(slots=True)
class _InlineTooltipState:
    """Track one hover delay or pointer monitor and its dismissal callback."""

    timer: str | None = None
    label: ttk.Label | None = None
    hide: Callable[[tk.Event | None], None] | None = None


class GalleryIdentityLabel(ttk.Label):
    """Show the current gallery title and its physical folder hierarchy."""

    def __init__(
        self, parent: tk.Misc, source: tk.StringVar,
        title: tk.StringVar | None = None,
    ) -> None:
        """Bind a wrapping gallery heading to the existing full identity.

        Args:
            parent (tk.Misc): Container allocating the heading's available width.
            source (tk.StringVar): Full identity prefixed with Target gallery.
            title (tk.StringVar | None): Current server display title; None
                supports callers with only the physical folder identity.
        Returns:
            None: Creates a focusable heading and live hierarchy tooltip.
        """
        self._source = source
        self._title = title
        self._display_name = tk.StringVar(master=parent)
        self._hierarchy = tk.StringVar(master=parent)
        super().__init__(
            parent, textvariable=self._display_name, style="Gallery.Section.TLabel",
            width=1, takefocus=True, justify="left",
        )
        self._trace = source.trace_add("write", self._refresh)
        self._title_trace = (
            title.trace_add("write", self._refresh) if title is not None else None
        )
        self.bind("<Configure>", self._wrap, add="+")
        self.bind("<Destroy>", self._dispose, add="+")
        self._refresh()
        _attach_tooltip(self, self._hierarchy)

    def _refresh(self, *_args: str) -> None:
        """Show the server title separately from the physical folder hierarchy.

        Args:
            *_args (str): Tcl trace variable name, array index and write action.
        Returns:
            None: Refreshes presentation without changing the source identity.
        """
        value = self._source.get().removeprefix("Target gallery: ")
        parts = [part for part in value.replace("\\", "/").split("/") if part]
        title = self._title.get().strip() if self._title is not None else ""
        if not parts or value == "unknown":
            heading = "Upload gallery: " + title if title else "Upload gallery: not set"
            self._display_name.set(heading)
            self._hierarchy.set(heading)
            return
        # Older inventory APIs omit the title. Keep their folder leaf usable;
        # this presentation fallback never rewrites the authoritative identity.
        heading = "Upload gallery: " + (title or parts[-1])
        self._display_name.set(heading)
        # Each arrow is one folder edge; line breaks retain the complete path
        # without spending heading width on the potentially long parent names.
        self._hierarchy.set(heading + "\n\nFolder hierarchy\n" + "\n".join(
            ("  " * depth + "└ " if depth else "") + part
            for depth, part in enumerate(parts)
        ))

    def _wrap(self, event: tk.Event) -> None:
        """Wrap the leaf name within the width allocated by the current layout.

        Args:
            event (tk.Event): Configure event containing the heading width.
        Returns:
            None: Keeps even a long leaf name readable after window resizing.
        """
        # Tk treats zero as no wrapping; one pixel is the smallest valid width.
        self.configure(wraplength=max(1, event.width))

    def _dispose(self, event: tk.Event) -> None:
        """Release the source trace when this heading is destroyed.

        Args:
            event (tk.Event): Destroy event identifying the removed widget.
        Returns:
            None: Stops updates to a heading whose Tk widget no longer exists.
        """
        if event.widget is self:
            self._source.trace_remove("write", self._trace)
            if self._title is not None and self._title_trace is not None:
                self._title.trace_remove("write", self._title_trace)


def _attach_tooltip(widget: tk.Misc, message: str | tk.StringVar) -> None:
    """Bind a delayed in-window tooltip exclusively to pointer hover.

    Args:
        widget (tk.Misc): Widget that owns pointer-hover tooltip bindings.
        message (str | tk.StringVar): Fixed help or live formatted tooltip text.

    Returns:
        None: The widget's existing toplevel hosts the themed tooltip label.
    """
    try:
        surface = widget.winfo_toplevel()
    except tk.TclError:
        return
    attach_inline_tooltip(widget, message, surface)


def attach_inline_tooltip(
    widget: tk.Misc,
    message: str | tk.StringVar,
    surface: tk.Misc,
) -> None:
    """Show themed help only while the pointer remains over its trigger.

    Args:
        widget (tk.Misc): Control that owns the pointer-hover activation area.
        message (str | tk.StringVar): Fixed or live accessible description.
        surface (tk.Misc): Existing widget that hosts the placed label.
    Returns:
        None: One delay or hover monitor is bound without a separate window;
            keyboard focus alone never displays a tooltip.
    """
    state = _InlineTooltipState()

    def pointer_over_trigger() -> bool:
        """Check the actual mapped widget under the pointer, including children.

        Returns:
            bool: True only over this trigger or one of its child widgets on
                the mapped surface; hidden, destroyed or covered controls fail.
        """
        try:
            if (not widget.winfo_exists() or not widget.winfo_ismapped()
                    or not surface.winfo_exists()
                    or not surface.winfo_ismapped()):
                return False
            x, y = widget.winfo_pointerxy()
            target = widget.winfo_containing(x, y)
            while target is not None:
                if target is widget:
                    return True
                target = target.master
        except (tk.TclError, KeyError):
            return False
        return False

    def hide(event: tk.Event | None = None) -> None:
        """Cancel display and hide the label owned by this trigger.

        Args:
            event (tk.Event | None): Optional widget event that caused hiding.
        Returns:
            None: The scheduled callback and mapped label are cleared.
        """
        if (event is not None
                and event.type in (tk.EventType.Configure, tk.EventType.Unmap)
                and event.widget is not widget):
            return
        timer = state.timer
        state.timer = None
        if timer is not None:
            try:
                widget.after_cancel(timer)
            except tk.TclError:
                pass
        if getattr(surface, "_inline_tooltip_state", None) is state:
            surface._inline_tooltip_state = None
        label = state.label
        if label is None:
            return
        try:
            if event is not None:
                label.destroy()
                state.label = None
            else:
                label.place_forget()
        except tk.TclError:
            state.label = None

    def show() -> None:
        """Place the themed label beside its still-hovered trigger.

        Returns:
            None: The surface contains a viewport-clamped tooltip if eligible.
        """
        state.timer = None
        try:
            if (getattr(surface, "_inline_tooltip_state", None) is not state
                    or not pointer_over_trigger()):
                hide()
                return
            if state.label is None:
                state.label = ttk.Label(
                    surface,
                    text=message if isinstance(message, str) else "",
                    textvariable=(
                        message if isinstance(message, tk.StringVar) else ""
                    ),
                    style="Inline.Tooltip.TLabel",
                    wraplength=_HELP_TEXT_WRAP,
                )
            label = state.label
            surface.update_idletasks()
            gap = _INLINE_TOOLTIP_GAP
            label.configure(
                wraplength=min(
                    _HELP_TEXT_WRAP,
                    max(1, surface.winfo_width() - 2 * gap),
                ),
            )
            label.update_idletasks()
            width = min(
                label.winfo_reqwidth(),
                max(1, surface.winfo_width() - 2 * gap),
            )
            height = min(label.winfo_reqheight(), surface.winfo_height())
            x = widget.winfo_rootx() - surface.winfo_rootx() + gap
            y = (widget.winfo_rooty() - surface.winfo_rooty()
                 + widget.winfo_height() + gap)
            if y + height > surface.winfo_height():
                y = widget.winfo_rooty() - surface.winfo_rooty() - height - gap
            x = max(0, min(surface.winfo_width() - width, x))
            y = max(0, min(surface.winfo_height() - height, y))
            label.place(x=x, y=y, width=width)
            label.lift()
            monitor_pointer()
        except tk.TclError:
            hide()

    def monitor_pointer() -> None:
        """Retire visible help when a Leave event was lost or a window moved.

        Returns:
            None: Only the current hovered tooltip keeps one monitoring timer;
                pointer departure cancels and unmaps it without waiting for focus.
        """
        state.timer = None
        if (getattr(surface, "_inline_tooltip_state", None) is not state
                or not pointer_over_trigger()):
            hide()
            return
        try:
            state.timer = widget.after(
                _TOOLTIP_POINTER_CHECK_MS, monitor_pointer,
            )
        except tk.TclError:
            hide()

    def schedule(_event: tk.Event | None = None) -> None:
        """Schedule display only for the currently hovered activation area.

        Args:
            _event (tk.Event | None): Optional pointer-entry event.
        Returns:
            None: One safe delayed callback replaces any earlier callback.
        """
        hide()
        if not pointer_over_trigger():
            return
        previous = getattr(surface, "_inline_tooltip_state", None)
        if previous is not None and previous is not state:
            if previous.hide is not None:
                previous.hide(None)
        # Register pending help too, so surface transitions can cancel the
        # delay before it ever places a label over the new content.
        surface._inline_tooltip_state = state
        try:
            state.timer = widget.after(_TOOLTIP_DELAY_MS, show)
        except tk.TclError:
            hide()

    widget.bind("<Enter>", schedule, add="+")
    widget.bind("<Leave>", hide, add="+")
    widget.bind("<FocusOut>", hide, add="+")
    widget.bind("<ButtonPress>", hide, add="+")
    widget.bind("<Escape>", hide, add="+")
    widget.bind("<Destroy>", hide, add="+")
    widget.bind("<Unmap>", hide, add="+")
    widget.bind("<Configure>", hide, add="+")
    state.hide = hide


def hide_inline_tooltip(surface: tk.Misc) -> None:
    """Hide the one active inline tooltip hosted by a widget surface.

    Args:
        surface (tk.Misc): Existing widget that owns the inline tooltip label.
    Returns:
        None: No label remains mapped over a content or overlay transition.
    """
    state = getattr(surface, "_inline_tooltip_state", None)
    if state is not None and state.hide is not None:
        state.hide(None)


def place_dialog(dialog: tk.Toplevel, owner: tk.Misc) -> None:
    """Center a child window over the window that opened it.

    Args:
        dialog (tk.Toplevel): Fully populated child window awaiting display.
        owner (tk.Misc): Invoking widget or its active application toplevel.
    Returns:
        None: Sets transient ownership and nearby requested-size geometry.
    """
    parent = owner.winfo_toplevel()
    dialog.transient(parent)
    dialog.update_idletasks()
    width, height = dialog.winfo_reqwidth(), dialog.winfo_reqheight()
    x = parent.winfo_rootx() + max(0, (parent.winfo_width() - width) // 2)
    y = parent.winfo_rooty() + max(0, (parent.winfo_height() - height) // 2)
    # Preserve the parent's monitor, including negative desktop coordinates.
    # winfo_screenwidth can describe only the primary monitor on Windows.
    # Tk's negative geometry offsets are measured from the right/bottom edge.
    # Convert absolute negative monitor coordinates instead of emitting '+-'.
    horizontal = f"+{x}" if x >= 0 else f"-{dialog.winfo_screenwidth() - width - x}"
    vertical = f"+{y}" if y >= 0 else f"-{dialog.winfo_screenheight() - height - y}"
    dialog.geometry(f"{width}x{height}{horizontal}{vertical}")


def _show_help(owner: tk.Misc, title: str, body: str) -> None:
    """Display a modal-style help panel with selectable content.

    Args:
        owner (tk.Misc): Widget whose toplevel owns the help dialog.
        title (str): Concise title shown in the dialog heading and chrome.
        body (str): Plain text content users may select or copy.

    Returns:
        None: The help overlay is focused and dismissed by Escape or close.
    """
    try:
        parent = owner.winfo_toplevel()
        previous_grab = parent.grab_current()
        previous_focus = parent.focus_get()
        dialog = tk.Toplevel(parent)
    except tk.TclError:
        return

    def close_help() -> None:
        """Close help and return grab and focus to their previous owners.

        Returns:
            None: The dialog is dismissed and prior live owners are restored.
        """
        try:
            if dialog.winfo_exists():
                dialog.destroy()
        except tk.TclError:
            return
        try:
            if previous_grab is not None and previous_grab.winfo_exists():
                previous_grab.grab_set()
            if previous_focus is not None and previous_focus.winfo_exists():
                previous_focus.focus_set()
        except tk.TclError:
            pass

    dialog.title(title or "Help")
    dialog.configure(background=_PALETTE["surface"])
    dialog.transient(parent)
    dialog.resizable(True, True)
    dialog.minsize(_HELP_MIN_WIDTH, _HELP_MIN_HEIGHT)
    shell = ttk.Frame(dialog, style="Glass.TFrame", padding=22)
    shell.pack(fill="both", expand=True)
    ttk.Label(shell, text=title, style="Glass.Title.TLabel").pack(
        anchor="w", pady=(0, 10))
    text_rows, text_columns = _HELP_TEXT_SIZE
    text_box = tk.Text(
        shell,
        wrap="word",
        height=text_rows,
        width=text_columns,
        relief="flat",
        borderwidth=0,
        padx=2,
        pady=2,
        background=_PALETTE["surface"],
        foreground=_PALETTE["ink"],
        font=(tkfont.nametofont("TkDefaultFont", root=parent).actual("family"), 10),
        takefocus=True,
    )
    text_box.insert("1.0", body)
    text_box.pack(fill="both", expand=True, anchor="w")
    text_box.bind("<Control-a>", lambda _event: _select_help_text(text_box))
    text_box.bind("<Key>", lambda event: _guard_help_text(event))
    text_box.bind("<<Paste>>", lambda _event: "break")
    text_box.bind("<<Cut>>", lambda _event: "break")
    text_box.bind("<Button-2>", lambda _event: "break")
    footer = ttk.Frame(shell, style="Card.TFrame")
    footer.pack(fill="x", pady=(14, 0))
    close_button = ttk.Button(footer, text="Close", command=close_help)
    close_button.pack(side="right")
    dialog.bind("<Escape>", lambda _event: close_help())
    dialog.protocol("WM_DELETE_WINDOW", close_help)
    dialog.update_idletasks()
    width = max(_HELP_MIN_WIDTH, dialog.winfo_reqwidth())
    height = max(_HELP_INITIAL_HEIGHT, dialog.winfo_reqheight())
    x = parent.winfo_rootx() + max(0, (parent.winfo_width() - width) // 2)
    y = parent.winfo_rooty() + max(0, (parent.winfo_height() - height) // 3)
    dialog.geometry(f"{width}x{height}+{x}+{y}")
    dialog.grab_set()
    text_box.focus_set()


def _select_help_text(text_box: tk.Text) -> str:
    """Select all help text and allow the normal copy shortcut to continue.

    Args:
        text_box (tk.Text): Read-only text widget containing help content.

    Returns:
        str: ``break`` to stop the default Control+A binding after selection.
    """
    text_box.tag_add("sel", "1.0", "end-1c")
    return "break"


def _guard_help_text(event: tk.Event) -> str | None:
    """Keep help content read-only while preserving select and copy keys.

    Args:
        event (tk.Event): Key event sent to the selectable help text widget.

    Returns:
        str | None: ``break`` prevents edits; ``None`` permits copy and select.
    """
    if event.state & 0x4 and event.keysym.lower() in ("a", "c"):
        return None
    return "break"


