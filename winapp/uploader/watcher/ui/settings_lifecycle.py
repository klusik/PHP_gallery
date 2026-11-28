# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/ui/settings_lifecycle.py
# Module Type: Windows Watcher UI Composition
# Purpose: Compose cohesive settings and desktop lifecycle behaviors.
# Responsibilities:
#   - Preserve the existing WatcherApp settings mixin import contract.
#   - Keep feature behavior in focused preference, update and shutdown owners.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Compose Watcher preferences, update and shutdown lifecycle owners."""

from .settings.preferences import WatcherPreferencesMixin
from .settings.updates import WatcherUpdateMixin
from .settings.shutdown import WatcherShutdownMixin


class SettingsLifecycleMixin(WatcherPreferencesMixin, WatcherUpdateMixin, WatcherShutdownMixin):
    """Preserve the WatcherApp base while composing settings lifecycle owners."""
