# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_activity_writer.py
# Module Type: Regression Test
# Purpose: Keep durable activity writes off the desktop event thread.
# Responsibilities:
#   - Verify batched event persistence preserves other upload-state sections.
#   - Verify the asynchronous writer bounds, flushes and safely reports failures.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise recent activity persistence with temporary state files only."""

import sys
import tempfile
import threading
import unittest
from pathlib import Path
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.models import ActivityEvent
from uploader.state_store import (
    ActivityWriter,
    JobStateStore,
    atomic_write_json,
    default_upload_state,
)

# Purpose: Exceed the minimum persisted history by one item in the bound test.
# Type: int; Units: events; Scope: one upload-state retention regression.
# Consumers: ActivityWriterTests.test_recent_history_keeps_only_latest_limit.
# Rationale: 51 proves the 50-event floor discards the oldest event.
_ACTIVITY_TEST_EVENT_COUNT = 51
# Purpose: Bound background writer waits in this disposable fixture.
# Type: float; Units: seconds; Scope: each ActivityWriter test close.
# Consumers: ActivityWriterTests.
# Rationale: Three seconds allows one local atomic write and verification.
_ACTIVITY_TEST_CLOSE_TIMEOUT_SECONDS = 3.0


class ActivityWriterTests(unittest.TestCase):
    """Verify recent events batch without weakening shared-state writes."""

    def make_event(self, index: int) -> ActivityEvent:
        """Create one deterministic event for ordering and retention checks.

        Args:
            index (int): Event number encoded in its visible message.

        Returns:
            ActivityEvent: Information-level synthetic test record.
        """
        return ActivityEvent(
            level='info', message=f'activity-{index}', operation='test',
            timestamp=float(index),
        )

    def test_append_events_preserves_upload_sections_and_order(self) -> None:
        """Append a batch without changing jobs or prior upload receipts.

        Returns:
            None: Shared state sections and chronological event order survive.
        """
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'upload_state.json'
            original = default_upload_state()
            original['uploaded_paths'] = {'photo.jpg': {'sha256': 'a' * 64}}
            original['jobs'] = {'accepted': {'id': 'accepted', 'items': []}}
            atomic_write_json(path, original)
            store = JobStateStore(path)

            store.append_events([self.make_event(1), self.make_event(2)])

            saved = store._load()
            self.assertEqual(original['uploaded_paths'], saved['uploaded_paths'])
            self.assertEqual(original['jobs'], saved['jobs'])
            self.assertEqual(
                ['activity-1', 'activity-2'],
                [event.message for event in store.list_events()],
            )

    def test_single_event_uses_batch_owner(self) -> None:
        """Keep append_event compatible by delegating to append_events.

        Returns:
            None: The single event is persisted through the same strict writer.
        """
        with tempfile.TemporaryDirectory() as directory:
            store = JobStateStore(Path(directory) / 'upload_state.json')
            store.append_event(self.make_event(1))
            self.assertEqual(['activity-1'], [
                event.message for event in store.list_events()
            ])

    def test_recent_history_keeps_only_latest_limit(self) -> None:
        """Bound one appended batch using the existing profile history limit.

        Returns:
            None: Only the latest 50 events remain in durable history.
        """
        with tempfile.TemporaryDirectory() as directory:
            store = JobStateStore(
                Path(directory) / 'upload_state.json', history_limit=50,
            )
            store.append_events([
                self.make_event(index)
                for index in range(_ACTIVITY_TEST_EVENT_COUNT)
            ])
            rows = store.list_events()
            self.assertEqual(50, len(rows))
            self.assertEqual('activity-1', rows[0].message)
            self.assertEqual('activity-50', rows[-1].message)

    def test_writer_batches_on_a_daemon_thread_and_flushes_on_close(self) -> None:
        """Queue recent UI events without synchronously writing the state file.

        Returns:
            None: The close flush persists events from a distinct daemon thread.
        """
        with tempfile.TemporaryDirectory() as directory:
            store = JobStateStore(Path(directory) / 'upload_state.json')
            original_append = store.append_events
            writer_thread_ids: list[int] = []

            def record_writer(events: list[ActivityEvent]) -> None:
                """Capture the writer thread before delegating to disk owner.

                Args:
                    events (list[ActivityEvent]): Ordered batch to persist.

                Returns:
                    None: Records thread identity and invokes the real writer.
                """
                writer_thread_ids.append(threading.get_ident())
                original_append(events)

            store.append_events = record_writer
            caller_thread_id = threading.get_ident()
            writer = ActivityWriter(store)
            for index in range(5):
                self.assertTrue(writer.submit(self.make_event(index)))
            self.assertTrue(writer.close(_ACTIVITY_TEST_CLOSE_TIMEOUT_SECONDS))
            self.assertFalse(writer.submit(self.make_event(6)))
            self.assertTrue(writer_thread_ids)
            self.assertTrue(all(
                thread_id != caller_thread_id for thread_id in writer_thread_ids
            ))
            self.assertEqual(
                [f'activity-{index}' for index in range(5)],
                [event.message for event in store.list_events()],
            )

    def test_writer_logs_fixed_failure_and_reports_close_failure(self) -> None:
        """Do not expose storage exception text or retry the failed batch.

        Returns:
            None: The writer exits and close reports its failed persistence.
        """
        store = mock.Mock()
        store.history_limit = 50
        store.append_events.side_effect = OSError('sensitive profile path')
        writer = ActivityWriter(store)
        with mock.patch('uploader.state_store.logging.error') as log_error:
            writer.submit(self.make_event(1))
            result = writer.close(_ACTIVITY_TEST_CLOSE_TIMEOUT_SECONDS)
        self.assertFalse(result)
        store.append_events.assert_called_once()
        log_error.assert_called_once_with(
            'Could not persist recent activity history.'
        )


if __name__ == '__main__':
    unittest.main()
