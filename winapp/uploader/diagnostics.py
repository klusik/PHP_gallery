"""
Redaction helpers for support diagnostics and copyable activity details.
"""

from pathlib import Path
from typing import Iterable
import re

_SECRET_RE = re.compile(r"(?i)(api[_ -]?key|authorization|token|secret|password)\s*[:=]\s*([^\s,;]+)")


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
