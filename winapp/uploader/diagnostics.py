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
from pathlib import Path
from typing import Iterable

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
    "page", "more", "slide_more", "refresh", "revision", "thumbnail",
    "catalogue", "preload", "detail", "prefetch", "audio_track", "other",
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
    "NotImplementedError", "AssertionError", "error", "Exception",
})

# Purpose: Bound source-defined exception labels retained in support output.
# Type: int. Units: ASCII characters. Scope: one Photos exception label.
# Consumers: format_safe_photos_failure. Rationale: Keep class facts compact.
SAFE_PHOTOS_EXCEPTION_TYPE_MAX_CHARS = 64


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
