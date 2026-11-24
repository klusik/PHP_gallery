# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/localization.py
# Module Type: Desktop Localization
# Purpose: Select the active WinApp interface catalog.
# Responsibilities:
#   - Load the bundled GNU gettext catalogs and resolve messages at display time.
#   - Keep presentation translation independent of profile and UI owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""WinApp interface localization using the bundled gettext catalogs."""

import gettext as _gettext
import logging
from functools import cache
from io import BytesIO
from pathlib import Path

try:
    import polib
except ImportError:
    # A source launch can still open in English and repair its dependencies.
    polib = None

DEFAULT_LANGUAGE = "en"
LANGUAGE_NAMES: dict[str, str] = {
    "en": "English",
    "cs": "Čeština",
    "de": "Deutsch",
    "sv": "Svenska",
}
CATALOG_DIRECTORY = Path(__file__).resolve().parent / "resources" / "locale"

_active_language = DEFAULT_LANGUAGE
_active_catalog = _gettext.NullTranslations()


@cache
def load_catalog(language: str) -> _gettext.NullTranslations:
    """Load a standard GNU catalog from its UTF-8 translation source.

    Args:
        language (str): Supported WinApp locale code.

    Returns:
        gettext.NullTranslations: Source-English or loaded GNU translations.

    Raises:
        ValueError: The language is unsupported or its catalog is invalid.
        OSError: The catalog cannot be read.
        ImportError: The catalog reader is unavailable.
    """
    if language == DEFAULT_LANGUAGE:
        return _gettext.NullTranslations()
    if language not in LANGUAGE_NAMES:
        raise ValueError("Unsupported interface language")

    if polib is None:
        raise ImportError("The PO catalog reader is unavailable")
    path = CATALOG_DIRECTORY / language / "LC_MESSAGES" / "winapp.po"
    source = polib.pofile(
        path.read_text(encoding="utf-8"), encoding="utf-8",
        check_for_duplicates=True,
    )
    return _gettext.GNUTranslations(BytesIO(source.to_binary()))


def configure_language(language: str) -> str:
    """Select a catalog before creating WinApp controls and session workers.

    Args:
        language (str): Preferred locale code read from the user profile.

    Returns:
        str: Active locale code, with English available on catalog failure.
    """
    global _active_language, _active_catalog
    try:
        catalog = load_catalog(language)
    except (OSError, ValueError, ImportError) as exc:
        # An incomplete source checkout or damaged installation must still be
        # able to open Settings. Keep the user's saved selection for recovery.
        logging.warning("WinApp locale %s could not be loaded: %s", language, exc)
        language = DEFAULT_LANGUAGE
        catalog = load_catalog(language)
    _active_language, _active_catalog = language, catalog
    return language


def current_language() -> str:
    """Read the shared active locale for WinApp and the older flight UI.

    Returns:
        str: Active WinApp locale code for this session.
    """
    return _active_language


def gettext(message: str) -> str:
    """Look up a complete interface message in the active GNU catalog.

    Args:
        message (str): English source message, with named fields when needed.

    Returns:
        str: Translated message or its English source.
    """
    return _active_catalog.gettext(message)


def gettext_noop(message: str) -> str:
    """Mark source text that an owner translates only when displaying it.

    Args:
        message (str): English catalog key retained in state or diagnostics.

    Returns:
        str: Stable source text that can be collected with gettext tooling.
    """
    return message
