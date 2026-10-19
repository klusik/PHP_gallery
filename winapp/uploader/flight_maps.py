# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_maps.py
# Module Type: Flight Map Service
# Purpose: Render recorded or planned routes over OpenStreetMap tiles.
# Responsibilities:
#   - Project coordinates with antimeridian wrapping and explicit track breaks.
#   - Cache only displayed tiles and preserve an offline route fallback.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Bounded on-demand OpenStreetMap report maps with disk tile caching."""

import io
import math
import time
from dataclasses import dataclass
from pathlib import Path
from urllib.request import Request, urlopen

from PIL import Image, ImageDraw
from PIL.PngImagePlugin import PngInfo

# Retain the compact preview size for callers without a physical print size.
# Type: tuple[int, int]; Units: pixels; Scope: map renderer.
# Consumers: render_map. Rationale: previews stay small; PDFs request print_size.
MAP_SIZE = (768, 384)
# Render native map pixels at the requested physical output density.
# Type: int; Units: pixels per inch; Scope: PDF map backgrounds.
# Consumers: print_size. Rationale: 300 dpi is the requested print-quality target.
MAP_DPI = 300
# Bound memory for a single landscape PDF map rather than accepting huge canvases.
# Type: int; Units: pixels; Scope: one map render.
# Consumers: map_viewport. Rationale: 8 million covers full-width A4 at 300 dpi.
MAX_MAP_PIXELS = 8_000_000
# Reserve breathing room around all route endpoints at each chosen zoom.
# Type: float; Units: viewport fraction; Scope: map fitting.
# Consumers: map_viewport. Rationale: 0.8 leaves a visible margin on all sides.
MAP_FIT_FRACTION = 0.8
# Limit network impact and offline wait for one displayed map.
# Type: float; Units: seconds; Scope: one map render.
# Consumers: render_map. Rationale: 45 seconds accommodates the native tiles
# in one 300-dpi viewport while preserving a bounded offline failure path.
MAP_NETWORK_SECONDS = 45.0
# Bound each individual tile transport within the overall map budget.
# Type: float; Units: seconds; Scope: one OSM request.
# Consumers: render_map. Rationale: 3 seconds limits an unavailable tile's delay.
TILE_TIMEOUT_SECONDS = 3.0
# Retain tiles at least seven days as required by the OSM tile policy.
# Type: int; Units: seconds; Scope: tile cache.
# Consumers: render_map. Rationale: avoid repeated tile downloads across editions.
TILE_CACHE_SECONDS = 7 * 24 * 60 * 60
# Limit cache storage, request size and zoom to overview use.
# Type: int; Units: bytes; Scope: map tile cache and response.
# Consumers: render_map. Rationale: 64 MiB cache and 1 MiB per PNG bound disk/memory.
TILE_CACHE_BYTES = 64 * 1024 * 1024
# Reject oversized tile responses before allocating a decoder.
# Type: int; Units: bytes; Scope: one OSM PNG.
# Consumers: render_map. Rationale: 1 MiB exceeds ordinary 256-pixel PNG needs.
TILE_MAX_BYTES = 1024 * 1024
# Bound overview detail without attempting airport imagery.
# Type: int; Units: zoom levels; Scope: report map.
# Consumers: map_viewport. Rationale: zoom 18 also fits short airport-area tracks.
MAX_MAP_ZOOM = 18
# Web Mercator cannot represent the geographic poles.
# Type: float; Units: degrees; Scope: map projection.
# Consumers: project. Rationale: 85.05112878 is the standard tile latitude edge.
MERCATOR_EDGE = 85.05112878


@dataclass
class MapViewport:
    """Share fitted Mercator geometry between raster tiles and PDF vector tracks."""

    size: tuple[int, int]
    zoom: int
    world: float
    left: float
    top: float
    points: list[tuple[float, float] | None]


def print_size(width: float, height: float) -> tuple[int, int]:
    """Convert PDF points to a pixel canvas meeting the print-density target.

    Args:
        width (float): Map width in PDF points, using 72 points per inch.
        height (float): Map height in PDF points, using 72 points per inch.
    Returns:
        tuple[int, int]: Pixel width/height rounded up to at least 300 dpi.
    """
    return (math.ceil(width * MAP_DPI / 72), math.ceil(height * MAP_DPI / 72))


def project(latitude: float, longitude: float) -> tuple[float, float]:
    """Project geographic coordinates into normalized Web Mercator space.

    Args:
        latitude (float): Finite latitude in decimal degrees.
        longitude (float): Finite longitude in decimal degrees.
    Returns:
        tuple[float, float]: Horizontal and downward vertical world fractions.
    """
    latitude = max(-MERCATOR_EDGE, min(MERCATOR_EDGE, latitude))
    return ((longitude + 180) / 360,
            (1 - math.asinh(math.tan(math.radians(latitude))) / math.pi) / 2)


def map_viewport(
    points: list[tuple[float, float] | None], size: tuple[int, int] = MAP_SIZE,
) -> MapViewport | None:
    """Fit valid positions into one bounded viewport with dateline unwrapping.

    Args:
        points (list[tuple[float, float] | None]): Ordered latitude/longitude
            pairs; None separates missing data, pauses or position jumps.
        size (tuple[int, int]): Requested native pixel width/height.
    Returns:
        MapViewport | None: Common tile/vector geometry, or None without positions.
    Raises:
        ValueError: Canvas dimensions are non-positive or exceed the pixel cap.
    """
    width, height = size
    if width <= 0 or height <= 0 or width * height > MAX_MAP_PIXELS:
        raise ValueError("Map size exceeds the supported print canvas.")
    projected: list[tuple[float, float] | None] = []
    previous = None
    for point in points:
        if point is None:
            projected.append(None)
            continue
        lat, lon = point
        if not math.isfinite(lat) or not math.isfinite(lon) or not (-90 <= lat <= 90 and -180 <= lon <= 180):
            projected.append(None)
            continue
        x, y = project(lat, lon)
        if previous is not None:
            x += round(previous - x)
        projected.append((x, y))
        previous = x
    valid = [p for p in projected if p is not None]
    if not valid:
        return None
    min_x, max_x = min(p[0] for p in valid), max(p[0] for p in valid)
    min_y, max_y = min(p[1] for p in valid), max(p[1] for p in valid)
    # Fit geographic extent independently of integer tile levels and print DPI.
    # A zero-length track retains the maximum supported local detail.
    world = min(
        width * MAP_FIT_FRACTION / (max_x - min_x) if max_x > min_x else math.inf,
        height * MAP_FIT_FRACTION / (max_y - min_y) if max_y > min_y else math.inf,
        256 * 2**MAX_MAP_ZOOM,
    )
    # Select native tiles at or above output density, then scale their placement.
    zoom = max(0, min(MAX_MAP_ZOOM, math.ceil(math.log2(world / 256))))
    left = (min_x + max_x) / 2 * world - width / 2
    top = (min_y + max_y) / 2 * world - height / 2
    return MapViewport(size, zoom, world, left, top, projected)


def render_map(
    points: list[tuple[float, float] | None], cache: Path | None,
    *, size: tuple[int, int] = MAP_SIZE, fade: float = 0.0,
    draw_track: bool = True,
) -> bytes | None:
    """Compose native-resolution tiles without resizing a small preview raster.

    Args:
        points (list[tuple[float, float] | None]): Ordered latitude/longitude
            pairs with None separators for gaps, pauses and position jumps.
        cache (Path | None): Private bounded tile cache; None renders offline.
        size (tuple[int, int]): Native pixel canvas, normally from print_size.
        fade (float): White background blend from zero to one; tracks stay opaque.
        draw_track (bool): Include a raster route, or leave PDF vector rendering
            to the caller using the same map_viewport geometry.
    Returns:
        bytes | None: Native-size PNG background/route, or None without positions.
            PDF callers draw attribution as readable vector text themselves.
    """
    viewport = map_viewport(points, size)
    if viewport is None:
        return None
    width, height = size
    zoom, world = viewport.zoom, viewport.world
    left, top, projected = viewport.left, viewport.top, viewport.points
    tile_span = world / 2**zoom
    valid = [p for p in projected if p is not None]
    image = Image.new("RGB", size, "#e6edf2")
    draw = ImageDraw.Draw(image)
    deadline = time.monotonic() + MAP_NETWORK_SECONDS
    missing = False
    try:
        if cache is not None:
            cache.mkdir(parents=True, exist_ok=True)
        files = sorted(cache.glob("*.png"), key=lambda p: p.stat().st_mtime) if cache else []
        total = sum(p.stat().st_size for p in files)
        for path in files:
            if total <= TILE_CACHE_BYTES:
                break
            total -= path.stat().st_size
            path.unlink()
    except OSError:
        pass
    for tx in range(math.floor(left / tile_span), math.floor((left + width - 1) / tile_span) + 1):
        for ty in range(math.floor(top / tile_span), math.floor((top + height - 1) / tile_span) + 1):
            if not 0 <= ty < 2**zoom:
                continue
            wrapped = tx % 2**zoom
            path = cache / f"{zoom}-{wrapped}-{ty}.png" if cache is not None else None
            raw = None
            try:
                if path is not None and path.is_file() and not path.is_symlink() and path.stat().st_size <= TILE_MAX_BYTES:
                    raw = path.read_bytes()
                fresh = path is not None and path.exists() and time.time() - path.stat().st_mtime < TILE_CACHE_SECONDS
                remaining = deadline - time.monotonic()
                if path is not None and not fresh and remaining > 0:
                    request = Request(f"https://tile.openstreetmap.org/{zoom}/{wrapped}/{ty}.png",
                        headers={"User-Agent": "PHPGalleryUploader/0.6.1 (+https://github.com/klusik/PHP_gallery)"})
                    with urlopen(request, timeout=min(TILE_TIMEOUT_SECONDS, remaining)) as response:
                        downloaded = response.read(TILE_MAX_BYTES + 1)
                    if len(downloaded) > TILE_MAX_BYTES:
                        raise ValueError("Map tile exceeds limit")
                    with Image.open(io.BytesIO(downloaded)) as tile:
                        if tile.size != (256, 256):
                            raise ValueError("Unexpected map tile size")
                        tile.load()
                    raw = downloaded
                    try:
                        temporary = path.with_suffix(".tmp")
                        temporary.write_bytes(raw)
                        temporary.replace(path)
                    except OSError:
                        pass
            except (OSError, ValueError):
                # A failed provider must not multiply its timeout across a
                # high-resolution viewport. Cached tiles remain usable and a
                # later explicit render may retry the provider normally.
                deadline = 0.0
            try:
                if raw is None:
                    raise ValueError("No map tile")
                with Image.open(io.BytesIO(raw)) as tile:
                    # Round shared edges so adjacent scaled tiles leave no seams.
                    x0, y0 = round(tx * tile_span - left), round(ty * tile_span - top)
                    x1 = round((tx + 1) * tile_span - left)
                    y1 = round((ty + 1) * tile_span - top)
                    image.paste(tile.convert("RGB").resize(
                        (x1 - x0, y1 - y0), Image.Resampling.LANCZOS), (x0, y0))
            except (OSError, ValueError):
                missing = True
    if fade:
        image = Image.blend(image, Image.new("RGB", size, "white"), max(0.0, min(1.0, fade)))
        draw = ImageDraw.Draw(image)
    if not draw_track:
        output = io.BytesIO()
        metadata = PngInfo()
        metadata.add_text("tile_status", "unavailable" if missing else "complete")
        # Preserve print intent in PNG metadata as well as PDF placement size.
        image.save(output, format="PNG", dpi=(MAP_DPI, MAP_DPI), pnginfo=metadata)
        return output.getvalue()
    previous_pixel = None
    for point in projected:
        if point is None:
            previous_pixel = None
            continue
        pixel = (round(point[0] * world - left), round(point[1] * world - top))
        if previous_pixel is not None:
            draw.line([previous_pixel, pixel], fill="#087eaa", width=3)
        previous_pixel = pixel
    for point, color in ((valid[0], "#16824b"), (valid[-1], "#192d43")):
        x, y = point[0] * world - left, point[1] * world - top
        draw.ellipse((x - 4, y - 4, x + 4, y + 4), fill=color)
    draw.rectangle((0, height - 20, width, height), fill="white")
    draw.text((6, height - 16), "© OpenStreetMap contributors | openstreetmap.org/copyright"
              + (" | Tiles unavailable" if missing else ""), fill="#192d43")
    output = io.BytesIO()
    image.save(output, format="PNG", dpi=(MAP_DPI, MAP_DPI))
    return output.getvalue()
