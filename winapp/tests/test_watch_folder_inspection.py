# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_watch_folder_inspection.py
# Module Type: Watcher inspection regression
# Purpose: Keep folder inspection read-only and shared with Watcher discovery.
# Responsibilities: Verify the displayed counts and cancellation do not publish
#   or mutate the active FIFO journal.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Guard the user-requested Watcher folder inspection boundary."""

from pathlib import Path
import tempfile
import threading
import unittest
from unittest.mock import Mock, patch

from uploader.capture_session import inspect_watch_folder
from uploader.discovery import inspect_folder_counts
from uploader.watch_queue import WatchQueueStore, observe_watch_source


class WatchFolderInspectionTests(unittest.TestCase):
    """Verify read-only discovery never changes queued capture evidence."""

    def test_inspection_uses_discovery_counts_without_mutating_fifo(self) -> None:
        """Summarize configured media while preserving the active queue bytes.

        Returns:
            None: Supported/unsupported counts are read-only and queue bytes match.
        """
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            folder = root / 'watch'
            folder.mkdir()
            image = folder / 'source.jpg'
            image.write_bytes(b'not decoded by folder discovery')
            (folder / 'notes.txt').write_text('ignored by Watcher discovery')
            journal = root / 'flight' / 'watch-drain.json'
            journal.parent.mkdir()
            queue = WatchQueueStore(journal, 'a' * 64, folder, False)
            queue.initialize([observe_watch_source(image, 1.0)])
            before = journal.read_bytes()

            result = inspect_watch_folder(
                folder, {'.jpg'}, False, threading.Event(),
            )

            self.assertIsNotNone(result)
            self.assertEqual(1, result.supported)
            self.assertEqual(1, result.unsupported)
            self.assertEqual(before, journal.read_bytes())

    def test_cancelled_inspection_does_not_start_discovery(self) -> None:
        """Honor a withdrawn read before entering the shared discovery walk.

        Returns:
            None: No result is published and no filesystem enumeration starts.
        """
        cancelled = threading.Event()
        cancelled.set()
        with patch('uploader.capture_session.inspect_folder_counts') as discover:
            result = inspect_watch_folder(
                Path('unused'), {'.jpg'}, False, cancelled,
            )
        self.assertIsNone(result)
        discover.assert_not_called()

    def test_cancellation_during_walk_stops_counting_entries(self) -> None:
        """Stop between filesystem entries without retaining a result list.

        Returns:
            None: Cancellation is observed before the third candidate is statted.
        """
        with tempfile.TemporaryDirectory() as temporary:
            folder = Path(temporary)
            candidates = [folder / f'{index}.jpg' for index in range(3)]
            original_is_file = Path.is_file
            cancelled = Mock()
            cancelled.is_set.side_effect = [False, False, False, True]
            with (
                patch.object(Path, 'glob', return_value=iter(candidates)),
                patch.object(
                    Path, 'is_file', autospec=True,
                    side_effect=lambda path: original_is_file(path),
                ) as is_file,
            ):
                result = inspect_folder_counts(
                    folder, {'.jpg'}, False, cancelled,
                )
        self.assertIsNone(result)
        self.assertEqual(2, is_file.call_count)


if __name__ == '__main__':
    unittest.main()
