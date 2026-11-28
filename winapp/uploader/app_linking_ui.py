# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/app_linking_ui.py
# Module Type: Windows Client Compatibility Facade
# Purpose: Preserve the historical LinkingManager import path.
# Responsibilities:
#   - Re-export the cohesive linking UI manager.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Compatibility import for the gallery linking UI manager."""

from .linking.ui import LinkingManager

__all__ = ("LinkingManager",)
