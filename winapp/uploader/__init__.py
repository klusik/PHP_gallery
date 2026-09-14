# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
#
# File: winapp/uploader/__init__.py
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
Support package for the PHP Gallery Windows uploader.

The public launcher remains ``gallery_watch_upload.pyw``.  Modules in this
package contain deterministic persistence, import-discovery, media-capability,
and job-model helpers so they can be tested without starting Tkinter.
"""

from .models import ActivityEvent, ImportItem, ImportJob, ImportPlan

__all__ = ["ActivityEvent", "ImportItem", "ImportJob", "ImportPlan"]
