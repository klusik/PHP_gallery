# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/translation_catalog/__init__.py
# Module Type: Windows Tooling Localization Catalog
# Purpose: Assemble the maintained language dictionaries.
# Responsibilities:
#   - Export the four ordered flight and linked-media locale dictionaries.
#   - Preserve existing translation lookup structure and fallback behavior.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Ordered localized label catalog for the Windows companion."""

from .czech import LABELS as _CZECH_LABELS
from .english import LABELS as _ENGLISH_LABELS
from .german import LABELS as _GERMAN_LABELS
from .swedish import LABELS as _SWEDISH_LABELS

TRANSLATIONS = {
    "en": _ENGLISH_LABELS,
    "cs": _CZECH_LABELS,
    "de": _GERMAN_LABELS,
    "sv": _SWEDISH_LABELS,
}
