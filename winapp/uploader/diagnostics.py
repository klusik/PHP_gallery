# Project: PHP Gallery
# Module Type: Windows Tooling
# Purpose: Prepare redacted uploader and safe Photos diagnostic details.
# Responsibilities:
#   - Remove sensitive data from support output and copyable activity information.
#   - Format allowlisted Photos failures without importing Tk modules.
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/uploader/diagnostics.py
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
Redaction helpers for support diagnostics and copyable activity details.
"""

import os
import re
import traceback
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable, Literal, cast

_SECRET_RE = re.compile(r"(?i)(api[_ -]?key|authorization|token|secret|password)\s*[:=]\s*([^\s,;]+)")

_SAFE_PHOTOS_PHASES = frozenset({
    "page-fetch", "media-fetch", "media-decode", "page-apply",
    "media-apply", "preload-listing", "preload-cache", "preload-fetch",
    "preload-verify", "preload-storage", "preload-setup",
})

# Purpose: Restrict operation labels to fixed, non-user-supplied names.
# Type: frozenset[str]. Units: operation labels. Scope: Photos diagnostics.
# Consumers: format_safe_photos_failure. Rationale: Exclude titles and URLs.
_SAFE_PHOTOS_DIAGNOSTIC_KINDS = frozenset({
    "page", "page_recent", "more", "slide_more", "refresh", "revision",
    "thumbnail",
    "catalogue", "preload", "detail", "prefetch", "photo_info",
    "audio_track", "presentation", "other",
})

# Purpose: Restrict traceback locations to known local Photos source owners.
# Type: frozenset[str]. Units: Python basenames. Scope: Photos diagnostics.
# Consumers: safe_photos_error_site. Rationale: Never expose arbitrary paths.
_SAFE_PHOTOS_SOURCE_FILES = frozenset({
    "native_gallery_viewer.py", "app_linking.py", "photos_workspace.py",
    "gallery_preload.py",
})

# Purpose: Keep exception classes to concrete labels used by Photos owners.
# Type: frozenset[str]. Units: exception class names. Scope: Photos diagnostics.
# Consumers: format_safe_photos_failure. Rationale: Discard dynamic class text.
_SAFE_PHOTOS_EXCEPTION_TYPES = frozenset({
    "LinkingError", "LinkingMediaThrottled", "LinkingMediaUnavailable",
    "LinkingResponseError", "LinkingRevisionUnsupported", "OSError",
    "RuntimeError", "ValueError", "TypeError", "TimeoutError",
    "ConnectionError", "IndexError", "KeyError", "OverflowError",
    "EOFError", "TclError", "UnidentifiedImageError",
    "DecompressionBombError", "PermissionError", "FileNotFoundError",
    "NotImplementedError", "AssertionError", "PhotosDecodeError",
    "error", "Exception",
})

# Purpose: Bound source-defined exception labels retained in support output.
# Type: int. Units: ASCII characters. Scope: one Photos exception label.
# Consumers: format_safe_photos_failure. Rationale: Keep class facts compact.
SAFE_PHOTOS_EXCEPTION_TYPE_MAX_CHARS = 64

# Purpose: Bound dimensions retained in safe image-decoder facts.
# Type: int. Units: pixels per axis. Scope: one Photos decode observation.
# Consumers: format_safe_photos_decode_facts. Rationale: A signed 32-bit axis
# can describe refused headers too; this does not admit those pixels to decode.
SAFE_PHOTOS_DECODE_DIMENSION_MAX = 2_147_483_647

# Purpose: Bound source bytes represented in Photos decoder diagnostics.
# Type: int. Units: bytes. Scope: one linked media body.
# Consumers: format_safe_photos_decode_facts. Rationale: Matches the native
# transport's current 16 MiB cap without importing its session owner.
SAFE_PHOTOS_DECODE_BODY_MAX_BYTES = 16_777_216

# Purpose: Retain the actual rejected body count as well as its smaller budget.
# Type: int. Units: bytes. Scope: numeric decoder diagnostic facts only.
# Consumers: format_safe_photos_decode_facts and is_safe_photos_observation.
# Rationale: A signed 64-bit count covers Python lengths without accepting
# arbitrary text or changing the 16-MiB media admission policy.
SAFE_PHOTOS_DECODE_BODY_COUNT_MAX = 9_223_372_036_854_775_807

# Purpose: Bound the decoded-pixel policy displayed in safe support output.
# Type: int. Units: pixels. Scope: one decoded source raster.
# Consumers: format_safe_photos_decode_facts. Rationale: The current image
# decoder refuses a full RGB conversion above 36 million pixels.
SAFE_PHOTOS_DECODE_PIXEL_MAX = 36_000_000

# Purpose: Bound dimensions rendered in safe decoder summaries.
# Type: int. Units: pixels per axis. Scope: normalized detail image.
# Consumers: format_safe_photos_decode_facts. Rationale: Current detail
# output is bounded to 2048 by 1536 pixels.
SAFE_PHOTOS_DECODE_OUTPUT_AXIS_MAX = 2048

_PhotosDecodeStage = Literal[
    "source-open", "draft", "pixel-check", "source-convert",
    "thumbnail-fallback", "resize",
]
_PhotosDecodeReason = Literal[
    "decode-failed", "pixel-limit", "body-limit", "reduced",
    "preview-fallback",
]
_PhotosImageFormat = Literal[
    "JPEG", "MPO", "PNG", "WEBP", "GIF", "TIFF", "BMP", "OTHER",
]


@dataclass(frozen=True)
class PhotosDecodeFacts:
    """Carry only fixed decoder facts between the worker and safe formatter.

    Args:
        stage (_PhotosDecodeStage): Fixed decoder operation stage.
        reason (_PhotosDecodeReason): Fixed outcome or policy reason.
        image_format (_PhotosImageFormat): Allowlisted Pillow format label.
        source_size (tuple[int, int]): Original width and height, or zeros.
        decoded_size (tuple[int, int]): Decoder dimensions before RGB conversion.
        body_bytes (int): Bounded HTTP body length in bytes.
        pixel_limit (int): Maximum pixels admitted before full RGB conversion.
        output_size (tuple[int, int]): Normalized detail width and height limit.
    """

    stage: _PhotosDecodeStage
    reason: _PhotosDecodeReason
    image_format: _PhotosImageFormat
    source_size: tuple[int, int]
    decoded_size: tuple[int, int]
    body_bytes: int
    pixel_limit: int
    output_size: tuple[int, int]


class PhotosDecodeError(ValueError):
    """Describe a decoder refusal without retaining Pillow text or image data.

    Args:
        facts (PhotosDecodeFacts): Fixed numeric and allowlisted format facts.

    Returns:
        None: The static exception message contains no request or image data.
    """

    def __init__(self, facts: PhotosDecodeFacts) -> None:
        """Initialize a safe decoder refusal.

        Args:
            facts (PhotosDecodeFacts): Safe facts retained for diagnostics.

        Returns:
            None: A static safe message is available to internal callers.
        """
        super().__init__("Photo could not be safely decoded.")
        self.facts = facts


_SAFE_PHOTOS_SHORTCUT_KEYS = frozenset({"f", "F", "F11"})
_SAFE_PHOTOS_SHORTCUT_WIDGETS = frozenset({
    "canvas", "text-entry", "control", "other",
})
_SAFE_PHOTOS_SHORTCUT_REASONS = frozenset({
    "inactive", "text-input", "command-modifier", "toggled",
})
# Purpose: Bound the numeric Tk state while retaining native Windows flags.
# Type: int. Units: event-state bits. Scope: fullscreen support observations.
# Consumers: format_safe_photos_shortcut and is_safe_photos_observation.
# Rationale: Twenty bits include Tk's logical Alt and extended-key flags.
_SAFE_PHOTOS_SHORTCUT_STATE_MAX = 0x000FFFFF

# Purpose: Bound every retained non-failure Photos observation.
# Type: int. Units: characters. Scope: one copied/logged observation.
# Consumers: is_safe_photos_observation.
# Rationale: 256 covers finite labels, dimensions and 64-bit counters.
SAFE_PHOTOS_OBSERVATION_MAX_CHARS = 256
_SAFE_PHOTOS_SHORTCUT_PATTERN = re.compile(
    r"shortcut key=(f|F|F11) state=0x([0-9a-f]{1,5}) "
    r"widget=(canvas|text-entry|control|other) visible=([01]) "
    r"fullscreen=([01])->([01]) "
    r"reason=(inactive|text-input|command-modifier|toggled)"
)
_SAFE_PHOTOS_DECODE_OBSERVATION_PATTERN = re.compile(
    r"decode stage=(source-open|draft|pixel-check|source-convert|"
    r"thumbnail-fallback|resize) reason=(decode-failed|pixel-limit|body-limit|"
    r"reduced|preview-fallback) format=(JPEG|MPO|PNG|WEBP|GIF|TIFF|BMP|OTHER) "
    r"source=([0-9]{1,10})x([0-9]{1,10}) "
    r"decoded=([0-9]{1,10})x([0-9]{1,10}) "
    r"body_bytes=([0-9]{1,19}) max_bytes=([0-9]{1,8}) "
    r"max_pixels=([0-9]{1,8}) "
    r"max_size=([0-9]{1,4})x([0-9]{1,4})"
)


def format_safe_photos_shortcut(
    key: str, state: int, widget: str, visible: bool,
    fullscreen_before: bool, fullscreen_after: bool, reason: str,
) -> str | None:
    """Format one allowlisted fullscreen-key observation for copied support.

    Args:
        key (str): F, shifted F, or F11 keysym from the local event binding.
        state (int): Bounded Tk modifier-state bitmask.
        widget (str): Fixed widget category, never a widget path or title.
        visible (bool): Whether the lightbox was visible at dispatch.
        fullscreen_before (bool): Fullscreen state before dispatch.
        fullscreen_after (bool): Fullscreen state after dispatch.
        reason (str): Fixed outcome or rejection category.

    Returns:
        str | None: Safe compact trace, or None when any field is invalid.
    """
    if (type(key) is not str or key not in _SAFE_PHOTOS_SHORTCUT_KEYS
            or type(state) is not int
            or not 0 <= state <= _SAFE_PHOTOS_SHORTCUT_STATE_MAX
            or type(widget) is not str
            or widget not in _SAFE_PHOTOS_SHORTCUT_WIDGETS
            or type(visible) is not bool
            or type(fullscreen_before) is not bool
            or type(fullscreen_after) is not bool
            or type(reason) is not str
            or reason not in _SAFE_PHOTOS_SHORTCUT_REASONS):
        return None
    return (
        f"shortcut key={key} state=0x{state:x} widget={widget} "
        f"visible={int(visible)} fullscreen={int(fullscreen_before)}->"
        f"{int(fullscreen_after)} reason={reason}"
    )


def format_safe_photos_decode_facts(
    facts: PhotosDecodeFacts | None,
) -> str | None:
    """Format validated decoder dimensions and fixed limits without raw text.

    Args:
        facts (PhotosDecodeFacts | None): Candidate record of finite stage,
            reason, format, dimensions and policy counters, or absent facts.
            Every field is revalidated before output.

    Returns:
        str | None: Bounded numeric summary, or None for invalid fact shapes.
    """
    if type(facts) is not PhotosDecodeFacts:
        return None
    stage = getattr(facts, 'stage', None)
    reason = getattr(facts, 'reason', None)
    image_format = getattr(facts, 'image_format', None)
    body_bytes = getattr(facts, 'body_bytes', None)
    pixel_limit = getattr(facts, 'pixel_limit', None)
    sizes = (
        getattr(facts, 'source_size', None),
        getattr(facts, 'decoded_size', None),
        getattr(facts, 'output_size', None),
    )
    if any(type(size) is not tuple or len(size) != 2 for size in sizes):
        return None
    source_width, source_height = sizes[0]
    decoded_width, decoded_height = sizes[1]
    output_width, output_height = sizes[2]
    dimensions = (
        source_width, source_height, decoded_width, decoded_height,
        output_width, output_height,
    )
    if (any(type(value) is not int or value < 0
            or value > SAFE_PHOTOS_DECODE_DIMENSION_MAX
            for value in dimensions)
            or type(stage) is not str
            or stage not in {
                "source-open", "draft", "pixel-check", "source-convert",
                "thumbnail-fallback", "resize",
            }
            or type(reason) is not str
            or reason not in {
                "decode-failed", "pixel-limit", "body-limit", "reduced",
                "preview-fallback",
            }
            or type(image_format) is not str
            or image_format not in {
                "JPEG", "MPO", "PNG", "WEBP", "GIF", "TIFF", "BMP", "OTHER",
            }
            or type(body_bytes) is not int
            or not 0 <= body_bytes <= SAFE_PHOTOS_DECODE_BODY_COUNT_MAX
            or type(pixel_limit) is not int
            or not 0 < pixel_limit <= SAFE_PHOTOS_DECODE_PIXEL_MAX
            or not 0 < output_width <= SAFE_PHOTOS_DECODE_OUTPUT_AXIS_MAX
            or not 0 < output_height <= SAFE_PHOTOS_DECODE_OUTPUT_AXIS_MAX):
        return None
    return (
        f"decode stage={stage} reason={reason} "
        f"format={image_format} source={source_width}x{source_height} "
        f"decoded={decoded_width}x{decoded_height} "
        f"body_bytes={body_bytes} max_bytes={SAFE_PHOTOS_DECODE_BODY_MAX_BYTES} "
        f"max_pixels={pixel_limit} "
        f"max_size={output_width}x{output_height}"
    )


def is_safe_photos_observation(summary: str) -> bool:
    """Validate a bounded shortcut or decoder observation before retention.

    Args:
        summary (str): Compact trace candidate from the native viewer owner.

    Returns:
        bool: True only when the full line matches one finite safe grammar.
    """
    if (type(summary) is not str
            or len(summary) > SAFE_PHOTOS_OBSERVATION_MAX_CHARS):
        return False
    shortcut = _SAFE_PHOTOS_SHORTCUT_PATTERN.fullmatch(summary)
    if shortcut is not None:
        return int(shortcut.group(2), 16) <= _SAFE_PHOTOS_SHORTCUT_STATE_MAX
    decoded = _SAFE_PHOTOS_DECODE_OBSERVATION_PATTERN.fullmatch(summary)
    if decoded is None:
        return False
    if int(decoded.group(9)) != SAFE_PHOTOS_DECODE_BODY_MAX_BYTES:
        return False
    # The full grammar establishes these finite labels before the casts.
    facts = PhotosDecodeFacts(
        cast(_PhotosDecodeStage, decoded.group(1)),
        cast(_PhotosDecodeReason, decoded.group(2)),
        cast(_PhotosImageFormat, decoded.group(3)),
        (int(decoded.group(4)), int(decoded.group(5))),
        (int(decoded.group(6)), int(decoded.group(7))),
        int(decoded.group(8)), int(decoded.group(10)),
        (int(decoded.group(11)), int(decoded.group(12))),
    )
    return format_safe_photos_decode_facts(facts) == summary


def format_safe_photos_failure(
    phase: str, kind: str, error: Exception,
) -> str:
    """Format allowlisted Photos failure facts without raw exception text.

    Args:
        phase (str): Operation phase reduced to the fixed safe allowlist.
        kind (str): Fixed operation label, reduced to a known safe value.
        error (Exception): Caught failure whose message is never retained.

    Returns:
        str: Bounded phase, exception class, owned source site and validated
            LinkingResponseError facts when the exception supplies them.
    """
    safe_phase = phase if phase in _SAFE_PHOTOS_PHASES else "media-fetch"
    safe_kind = (
        kind if kind in _SAFE_PHOTOS_DIAGNOSTIC_KINDS else "other"
    )
    site = safe_photos_error_site(error)
    candidate_type = type(error).__name__
    error_type = (
        candidate_type
        if (candidate_type in _SAFE_PHOTOS_EXCEPTION_TYPES
            and candidate_type.isascii()
            and candidate_type.isidentifier()
            and len(candidate_type) <= SAFE_PHOTOS_EXCEPTION_TYPE_MAX_CHARS)
        else "OtherError"
    )
    response = ""
    from .app_linking import LinkingResponseError

    if isinstance(error, LinkingResponseError):
        try:
            validated = LinkingResponseError(
                error.phase, error.route, error.status,
                error.content_kind, error.reason,
                media_policy_facts=error.media_policy_facts,
                transport_step=error.transport_step,
            )
        except (AttributeError, TypeError, ValueError):
            try:
                validated = LinkingResponseError(
                    error.phase, error.route, error.status,
                    error.content_kind, error.reason,
                )
            except (AttributeError, TypeError, ValueError):
                validated = None
        if validated is not None:
            response = " " + validated.safe_summary
    if isinstance(error, PhotosDecodeError):
        decode_facts = format_safe_photos_decode_facts(
            getattr(error, "facts", None),
        )
        if decode_facts is not None:
            response += " " + decode_facts
    return (
        f"phase={safe_phase} kind={safe_kind} error_type={error_type} "
        f"site={site}{response}"
    )


def safe_photos_error_site(error: Exception) -> str:
    """Return only a whitelisted Python source basename and owned location.

    Args:
        error (Exception): Caught worker or Tk-application failure.

    Returns:
        str: Safe basename/function/line label, or the fixed value ``unknown``.
    """
    for frame in reversed(traceback.extract_tb(error.__traceback__)):
        basename = os.path.basename(frame.filename)
        if (basename in _SAFE_PHOTOS_SOURCE_FILES
                and frame.name.isidentifier() and frame.lineno > 0):
            return f"{basename}:{frame.name}:{frame.lineno}"
    return "unknown"


def redact_text(text: str, secrets: Iterable[str] = (), redact_paths: bool = True) -> str:
    """Redact configured secrets and obvious credential assignments from text."""
    result = str(text)
    for secret in secrets:
        value = str(secret or "")
        if value:
            result = result.replace(value, "[REDACTED]")
    result = _SECRET_RE.sub(lambda match: match.group(1) + "=[REDACTED]", result)
    if redact_paths:
        result = re.sub(r"(?i)\b[A-Z]:\\[^\r\n\t]+", "[LOCAL PATH]", result)
        home = str(Path.home())
        if home:
            result = result.replace(home, "[USER PROFILE]")
    return result
