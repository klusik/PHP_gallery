# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/linking/ui/__init__.py
# Module Type: Windows Client Interface Package
# Purpose: Expose the cohesive gallery linking interface.
# Responsibilities:
#   - Export the linking manager while keeping its behavior owners private.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Tk interface for browser-approved gallery linking."""

from .manager import LinkingManager

__all__ = ("LinkingManager",)
