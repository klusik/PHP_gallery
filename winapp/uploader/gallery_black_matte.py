# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_black_matte.py
# Module Type: Shared gallery image geometry
# Purpose: Detect confirmed black matte bounds for Tk and Qt photo viewers.
# Responsibilities:
#   - Inspect a bounded RGB sample without modifying the source raster.
#   - Return source-relative bounds only when opposed mattes are confirmed.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Shared, toolkit-free black-matte geometry detection."""

import math

try:
    from PIL import Image
except ImportError:
    Image = None

from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from PIL import Image as PillowImage

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
