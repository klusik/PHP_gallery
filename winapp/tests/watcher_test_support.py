# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/watcher_test_support.py
# Module Type: Regression Support
# Purpose: Initialize complete isolated Watcher lifecycles in constructor spies.
# Responsibilities:
#   - Reuse the production constructor with a stubbed native metadata provider.
#   - Keep direct-scan journals beneath a fixture-owned temporary directory.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Shared lifecycle initialization for production Watcher constructor spies."""

import tempfile
import sys
import queue
import threading
import unittest
from pathlib import Path
from types import ModuleType
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.watcher import state as watcher_state
from uploader.watcher.workers import watcher as watcher_worker


def initialize_watcher_fixture(
    worker: threading.Thread, module: ModuleType, owner: unittest.TestCase,
) -> None:
    """Initialize a production Watcher without native calls or profile mutations.

    Args:
        worker (threading.Thread): Constructor-bypassed production WatcherThread.
        module (ModuleType): Loaded .pyw module exporting WatcherThread,
            WatcherConfig and the queue module.
        owner (unittest.TestCase): Fixture owner retaining temporary-directory cleanup.

    Returns:
        None: Supplies every current lifecycle field and a private direct-scan journal.
    """
    temporary = tempfile.TemporaryDirectory()
    owner.addCleanup(temporary.cleanup)
    root = Path(temporary.name)
    if not Path(watcher_state.CONFIG_DIR).absolute().is_relative_to(Path(tempfile.gettempdir()).absolute()):
        profile = mock.patch.object(watcher_state, 'CONFIG_DIR', root)
        profile.start()
        owner.addCleanup(profile.stop)
    config = module.WatcherConfig(
        gallery_url='https://gallery.example.test', api_key='fixture-key',
        watched_folder=str(root), delete_uploaded_files=False,
    )
    with mock.patch.object(watcher_worker, 'SimConnectCameraClient', return_value=mock.Mock()):
        module.WatcherThread.__init__(worker, config, queue.Queue())
    worker._queue_directory_override = root
