# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/app_linking.py
# Module Type: Windows Client Compatibility Facade
# Purpose: Preserve established app_linking imports for companion consumers.
# Responsibilities:
#   - Re-export the supported linking package API from its historical path.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Compatibility imports for the cohesive linking package."""

from .linking import *  # noqa: F401,F403
from .linking import __all__, _issuer_valid
