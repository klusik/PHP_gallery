# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/event_policy.py
# Module Type: Windows Watcher Event Policy
# Purpose: Own bounded event pump timing shared by desktop and Tk owners.
# Responsibilities:
#   - Bound queued event work and cadence without depending on the UI layer.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Shared bounded Watcher event-delivery limits."""

# Purpose: Bound cooperative event draining between complete worker events.
# Type: float; Units: milliseconds per batch; Scope: one Watcher event drain;
# Consumers: WatcherApp._drain_event_batch; Rationale: return control to Tk
# promptly while never interrupting an individual domain handler.
WATCHER_EVENT_BATCH_BUDGET_MS = 8.0


# Purpose: Bound the amount of queued Tk work handled in one event-loop turn.
# Type: int; Units: worker events per batch; Scope: one Watcher event drain;
# Consumers: WatcherApp._drain_event_batch; Rationale: process FIFO events
# cooperatively without allowing sustained worker output to monopolize Tk.
WATCHER_EVENT_BATCH_LIMIT = 64


# Purpose: Give a budget-limited event queue prompt, non-spinning continuation.
# Type: int; Units: milliseconds; Scope: continuation after a bounded batch;
# Consumers: WatcherApp.drain_events; Rationale: 24 ms gives Tk more time
# between saturated batches while steady queue polling keeps its own cadence.
WATCHER_EVENT_CONTINUATION_MS = 24


# Purpose: Preserve the ordinary worker-event poll cadence when a batch drains.
# Type: int; Units: milliseconds; Scope: idle event queue polling;
# Consumers: WatcherApp.drain_events; Rationale: retain the existing 200 ms
# idle cadence while using a shorter delay only after an exhausted batch.
WATCHER_EVENT_IDLE_POLL_MS = 200
