# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/ui_icons.py
# Module Type: Shared WinApp icon artwork
# Purpose: Render the existing desktop glyphs independently of the GUI toolkit.
# Responsibilities: Own semantic icon geometry, state accents and DPI rasterization.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Render WinApp's semantic artwork for native Tk and Qt image adapters."""

import io
from math import cos, radians, sin
from typing import Literal

from .ui_palette import PALETTE as _PALETTE
from .ui_palette import UI_SWITCH_SIZE

try:
    from PIL import Image, ImageDraw, ImageOps
except ImportError:
    Image = None


# Purpose: Draw the folder rim outside the correctly proportioned cover image.
# Type: tuple[int, int, int, int]. Units: logical pixels, left/top/right/bottom.
# Scope: shared folder artwork. Consumers: folder_card_size and create_smooth_icon.
# Rationale: 5/12/5/8 retains the compact rim and raised tab without cropping
# the selected image ratio into the remaining space of a ratio-shaped folder.
FOLDER_COVER_INSETS = (5, 12, 5, 8)

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

# Purpose: Keep compact semantic artwork crisp after reduction.
# Type: int; Units: supersampling factor; Scope: smooth icon generation.
# Consumers: create_smooth_icon.
# Rationale: Existing theme artwork uses 3x rendering for clean edges.
_ICON_SUPERSAMPLE = 3

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


def render_toggle_switch(
    selected: bool, state: Literal['normal', 'focus', 'disabled'], *, dpi_scale: float = 1.0,
) -> 'Image.Image | None':
    """Draw the established WinApp switch and its keyboard-focus treatment.

    Args:
        selected (bool): Persistent Boolean setting represented by the thumb.
        state (Literal['normal', 'focus', 'disabled']): Current native widget state.
        dpi_scale (float): Device pixels per logical pixel, defaulting to 1.
    Returns:
        Image.Image | None: Density-scaled indicator or no artwork without Pillow.
    """
    if Image is None:
        return None
    width, height = UI_SWITCH_SIZE
    scale = max(1, round(_ICON_SUPERSAMPLE * dpi_scale))
    with Image.new('RGB', (width * scale, height * scale), _PALETTE['card_surface']) as artwork:
        draw = ImageDraw.Draw(artwork)
        track = _PALETTE['success'] if selected else '#607182'
        if state == 'disabled':
            track = '#40525F'
        # Match the Tk reference silhouettes, including room for the focus rim.
        draw.rounded_rectangle(
            (2 * scale, 2 * scale, (width - 2) * scale, (height - 2) * scale),
            radius=(height // 2) * scale, fill=track,
        )
        thumb = height - 8
        left = width - thumb - 4 if selected else 4
        draw.ellipse(
            (left * scale, 4 * scale, (left + thumb) * scale, (height - 4) * scale),
            fill='#F1F6FA' if state != 'disabled' else '#8B9AA6',
        )
        if state == 'focus':
            draw.rounded_rectangle(
                (0, 0, width * scale - 1, height * scale - 1),
                radius=(height // 2) * scale, outline=_PALETTE['focus'], width=scale,
            )
        output = max(1, round(width * dpi_scale)), max(1, round(height * dpi_scale))
        return artwork.resize(output, Image.Resampling.LANCZOS)


def render_smooth_icon(
    icon: str,
    size: tuple[int, int],
    *,
    dpi_scale: float = 1.0,
    pinned: bool = False,
    recording: bool = False,
    hollow: bool = False,
    pulse: bool = True,
    angle: int = 0,
    cover: bytes | None = None,
    tone: str = "ink",
) -> "Image.Image | None":
    """Render shared icon geometry on a transparent, density-aware raster.

    Args:
        icon (str): Established semantic glyph key or its source alias.
        size (tuple[int, int]): Final logical dimensions in pixels.
        dpi_scale (float): Native display density multiplier, defaulting to 1.
        pinned (bool): Add a retention marker to the current icon.
        recording (bool): Add the active Watcher recording marker.
        hollow (bool): Outline the recording marker for a collapsed ancestor.
        pulse (bool): Use the bright recording phase.
        angle (int): Rotation of the loading arc in degrees.
        cover (bytes | None): Optional prepared folder artwork payload.
        tone (str): Shared palette role or a custom hexadecimal color.
    Returns:
        Image.Image | None: Density-sized RGBA pixels for the owning adapter,
            or None for an unsupported glyph or missing Pillow.
    """
    if Image is None:
        return None
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
                with Image.open(io.BytesIO(cover)) as source:
                    # The caller sizes the cover first, then adds this frame.
                    # The selected ratio therefore describes the actual pixels.
                    left, top, right, bottom = FOLDER_COVER_INSETS
                    preview = ImageOps.fit(source.convert("RGB"), (
                        max(1, (width - left - right) * scale),
                        max(1, (height - top - bottom) * scale),
                    ))
                    canvas.paste(preview, (left * scale, top * scale))
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
        if key == "photos":
            # Reproduce the Photos rail's framed diagonal fill.
            draw.rectangle(bounds, outline=color, width=stroke)
            for offset in (-3, 1, 5):
                line(((width // 2 - 5, height // 2 + offset),
                      (width // 2 + offset, height // 2 + 5)))
        elif key == "import":
            # The native glyph is a hollow upward arrow, separate from upload.
            x, y = width // 2, height // 2
            line(((x, y - 6), (x + 6, y), (x + 2, y), (x + 2, y + 6),
                  (x - 2, y + 6), (x - 2, y), (x - 6, y), (x, y - 6)))
        elif key == "ai":
            # The compact rail uses the existing four-point sparkle silhouette.
            x, y = width // 2, height // 2
            points = ((x, y - 7), (x + 2, y - 2), (x + 7, y), (x + 2, y + 2),
                      (x, y + 7), (x - 2, y + 2), (x - 7, y), (x - 2, y - 2))
            draw.polygon(tuple((a * scale, b * scale) for a, b in points), fill=color)
        elif key in ("add", "zoom_in"):
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
        elif key in ("music_previous", "music_next"):
            x, y = width // 2, height // 2
            draw.ellipse((cx - 7 * scale, cy + 2 * scale,
                          cx - 3 * scale, cy + 6 * scale), fill=color)
            line(((x - 3, y + 4), (x - 3, y - 6), (x + 1, y - 3)))
            edge, tip = (6, 2) if key == "music_previous" else (2, 6)
            line(((x + edge, y - 2), (x + tip, y + 1), (x + edge, y + 4)))
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
    return canvas.resize(output_size, Image.Resampling.LANCZOS)
