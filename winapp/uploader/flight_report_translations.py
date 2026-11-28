# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/flight_report_translations.py
# Module Type: Windows Tooling Localization
# Purpose: Provide localized labels for flight reports and native linking UI.
# Responsibilities:
#   - Keep report, finalization and media viewer terms aligned in four languages.
#   - Supply stable English fallbacks for unsupported or missing labels.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Localized vocabulary used by Windows flight and linked-media interfaces.

The dictionary is kept separate from interface and drawing logic so supported
languages use consistent analysis and control structure.
"""

from .translation_catalog import TRANSLATIONS as _TRANSLATIONS
from .translation_catalog.watcher_translation_catalog import (
    _WATCH_QUEUE_REFRESH_LABELS, _WATCH_RECOVERY_LABELS, recovery_text,
)
from .translation_catalog.report_translation_extensions import apply_report_translation_extensions
from .translation_catalog.photos_translation_extensions import apply_photos_translation_extensions
from .translation_catalog.photos_detail_translation_catalog import apply_photos_detail_translation_catalog
from .translation_catalog.watcher_translation_extensions import apply_watcher_translation_extensions

_LANGUAGE_NAMES = ("en", "cs", "de", "sv")
apply_report_translation_extensions(_TRANSLATIONS)
apply_photos_translation_extensions(_TRANSLATIONS)
apply_photos_detail_translation_catalog(_TRANSLATIONS)
apply_watcher_translation_extensions(_TRANSLATIONS)


def tr(language: str, key: str) -> str:
    """Return one translated label with English fallback.

    Args:
        language (str): Language code, one of en, cs, de, or sv. Unknown
            codes use English.
        key (str): Stable translation key used by report or UI finalization.

    Returns:
        str: Localized text, or the key itself when no English entry exists.
    """
    selected = _TRANSLATIONS.get(language.lower(), _TRANSLATIONS["en"])
    return selected.get(key, _TRANSLATIONS["en"].get(key, key))


def supported_languages() -> tuple[str, ...]:
    """Return the supported output languages in stable display order.

    Returns:
        tuple[str, ...]: Language codes ordered English, Czech, German,
            Swedish.
    """
    return _LANGUAGE_NAMES
