# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/watcher/update_policy.py
# Module Type: Windows Update Policy
# Purpose: Own bounded Watcher update handoff and continuation timing.
# Responsibilities:
#   - Bound checkpoint, readiness, retry and safe diagnostic operations.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Own update continuation limits shared by the desktop and Watcher UI."""

# Purpose: Finish a current ACK and flush accepted work before helper launch.
# Type: float. Units: seconds. Scope: one in-app update checkpoint.
# Consumers: WatcherApp.wait_for_update_checkpoint. Rationale: 120 seconds
# covers ordinary uploads; a slower unresolved transfer postpones installation
# rather than letting setup terminate its writer.
UPDATE_CHECKPOINT_TIMEOUT_SECONDS = 120.0
# Purpose: Poll local readiness and retry transient checkpoint reads off Tk.
# Type: int. Units: milliseconds. Scope: startup and cancelled update recovery.
# Consumers: WatcherApp._inspect_update_continuation and
# WatcherApp._continue_update_resume. Rationale: 250 ms keeps filesystem and
# already-approved link readiness responsive within the existing deadline.
UPDATE_CONTINUATION_POLL_MS = 250
# Purpose: Space read-only gallery revalidation after transient server errors.
# Type: int. Units: milliseconds. Scope: one automatic Watcher continuation.
# Consumers: WatcherApp.drain_events. Rationale: 2000 ms avoids rapid repeated
# gallery reads while the existing 60-second continuation deadline bounds work.
UPDATE_CONTINUATION_TARGET_RETRY_MS = 2000
# Purpose: Bound checkpoint and already-approved saved-link recovery.
# Type: float. Units: seconds. Scope: one automatic Watcher continuation.
# Consumers: WatcherApp._inspect_update_continuation and
# WatcherApp._continue_update_resume. Rationale: 60 seconds bounds transient
# local read refusals and existing identity requests; missing authority pauses.
UPDATE_CONTINUATION_LINK_SECONDS = 60.0
# Purpose: Bound exception class names in safe continuation diagnostics.
# Type: int. Units: characters. Scope: Watcher resume exception summaries.
# Consumers: WatcherApp._continue_update_resume. Rationale: 64 characters are
# enough for locally authored class names while excluding arbitrary payloads.
UPDATE_RESUME_EXCEPTION_CLASS_MAX_CHARS = 64
