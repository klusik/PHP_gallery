# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_445_qt_shell_benchmark.py
# Module Type: Hosted Qt shell representative benchmark
# Purpose: Measure Qt shell behavior alongside real synthetic active-Watcher work.
# Responsibilities:
#   - Exercise QtWindow and QtImport controls without services.
#   - Retain raw producer-to-GUI event delays and sampled soak resources.
#   - Run a distinct real WatcherThread against synthetic screenshots and local
#     fixture transports while the Qt Watcher panel is foregrounded.
#   - Mark these shell measurements separate from non-equivalent Tk comparisons.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Capture Qt shell, event flood and hosted synthetic active-Watcher evidence."""

import argparse
import csv
import importlib.machinery
import importlib.util
import json
import os
from pathlib import Path
import platform
from random import Random
import subprocess
import sys
import tempfile
from threading import Event, Thread
import time
from typing import Callable
from unittest.mock import Mock
from queue import Queue
from types import ModuleType

from PIL import Image
import psutil

PROJECT_ROOT = Path(__file__).resolve().parents[2]
WINAPP_SOURCE = str(PROJECT_ROOT / "winapp")
if WINAPP_SOURCE not in sys.path:
    sys.path.insert(0, WINAPP_SOURCE)

from uploader.dependency_versions import dependency_versions

from PySide6.QtCore import QEvent, QEventLoop, QObject, Qt, QTimer, qVersion
from PySide6.QtWidgets import QApplication, QWidget

from uploader.capture_session import CaptureStatus
from uploader.import_commands import (
    ImportActions, ImportApproval, ImportEvents, ImportSource, ImportWorkerPort,
)
from uploader.qt_application import QtWindow
from uploader.qt_capture import QtCapture
from uploader.qt_theme import apply_qt_theme
from uploader.models import ImportJob, JsonValue
from uploader.watch_queue import WatchQueueStore

# Purpose: Keep one shell sample bounded to the established Issue 434 soak.
# Type: int. Units: seconds. Scope: one Qt shell soak process.
# Consumers: run_shell. Rationale: reuse the existing long-session duration.
SOAK_SECONDS = 180
# Purpose: Keep the representative workspace navigation trace repeated and bounded.
# Type: int. Units: rounds. Scope: startup interaction pre-soak.
# Consumers: run_shell. Rationale: Three cycles visit each native page without
# turning startup sampling into an extended soak.
WORKSPACE_ROUNDS = 3
# Purpose: Match the Issue 434 local QtImport event-drain bound.
# Type: int. Units: events. Scope: one generated channel burst.
# Consumers: Queue event producers and queue admission checks.
# Rationale: 64 is the production Import view queue capacity.
EVENT_BURST = 64
# Purpose: Admit one representative duration at the established worker cadence.
# Type: float. Units: seconds. Scope: each Watcher and Import event producer.
# Consumers: produce_events. Rationale: 50 ms matches Issue 434's synthetic import pace.
EVENT_INTERVAL_SECONDS = 0.05
# Purpose: Observe native GUI scheduling independently of worker-event delivery.
# Type: int. Units: milliseconds. Scope: the active Windows Qt shell soak.
# Consumers: run_shell. Rationale: A 50 ms heartbeat samples many event turns
# at the worker cadence without affecting the separate quiet measurements.
SOAK_HEARTBEAT_MS = 50
# Purpose: Bound startup, workspace and publisher soak.
# Type: float. Units: seconds. Scope: one isolated shell lifecycle.
# Consumers: wait_until and run_shell.
# Rationale: The 180-second soak leaves time for native startup and idle observations.
PROCESS_DEADLINE_SECONDS = 240.0
# Purpose: Sample process working set during the active UI workload.
# Type: float. Units: seconds. Scope: one Qt shell process.
# Consumers: run_shell resource monitor.
# Rationale: 250 ms matches the Issue 434 resource sample cadence.
RESOURCE_INTERVAL_SECONDS = 0.25
# Purpose: Bound orderly synthetic worker shutdown after the measured run.
# Type: float. Units: seconds. Scope: one connection-free worker thread.
# Consumers: run_shell teardown. Rationale: worker loops observe cancellation
# between at most one 50 ms pacing interval.
WORKER_JOIN_SECONDS = 1.0
# Purpose: Yield to the Qt event loop during bounded readiness polling.
# Type: float. Units: seconds. Scope: one shell or event readiness wait.
# Consumers: wait_until.
# Rationale: 5 ms avoids busy polling while observing native event delivery.
POLL_INTERVAL_SECONDS = 0.005
# Purpose: Change the visible workspace while publishers exercise both panels.
# Type: float. Units: seconds. Scope: active Qt shell soak.
# Consumers: run_shell. Rationale: ten complete native loops span a 20-second
# measurement evenly without dominating the 180-second issue 434 session.
WORKSPACE_SWITCH_INTERVAL_SECONDS = 2.0
# Purpose: Compare quiet shell states without a polling loop in the observer.
# Type: int. Units: milliseconds. Scope: one foreground/background sample.
# Consumers: measure_quiet_shell. Rationale: four seconds include idle timers.
QUIET_SAMPLE_MS = 4000
# Purpose: Exclude the one-time transition from each steady-state sample.
# Type: int. Units: milliseconds. Scope: one visibility or workspace transition.
# Consumers: measure_quiet_shell. Rationale: reaches normal timer cadence.
QUIET_SETTLE_MS = 800
# Purpose: Keep real Watcher work small while observing a multi-item FIFO.
# Type: int. Units: screenshots. Scope: hosted synthetic Watcher scenario.
# Consumers: run_active_watcher. Rationale: Six images exercise ordered
# discovery and acknowledgement without turning the shell benchmark into a soak.
ACTIVE_WATCHER_SCREENSHOT_COUNT = 6
# Purpose: Bound the real Watcher admission, local transport and durable readback.
# Type: float. Units: seconds. Scope: one hosted active Watcher scenario.
# Consumers: run_active_watcher. Rationale: The production consumer polls at
# one-second cadence, so this leaves room for each synthetic FIFO head.
ACTIVE_WATCHER_DEADLINE_SECONDS = 40.0
# Purpose: Keep the local transport visibly active for process sampling.
# Type: float. Units: seconds. Scope: each synthetic accepted upload only.
# Consumers: run_active_watcher local stub. Rationale: 80 ms permits several
# hosted RSS/GUI observations without introducing any external network wait.
ACTIVE_WATCHER_STUB_SECONDS = 0.08
# Purpose: Exercise screenshot-sized file admission and hashing.
# Type: tuple[int, int]. Units: pixels. Scope: hosted local screenshot fixture.
# Consumers: write_synthetic_screenshot. Rationale: Full HD provides a
# representative byte count while keeping the isolated queue within its budget.
SYNTHETIC_SCREENSHOT_SIZE = (1920, 1080)


class QtPaintCounter(QObject):
    """Count native widget paint events owned by the visible Watcher panel."""

    def __init__(self, panel: QWidget) -> None:
        """Observe paints from one real Qt workspace subtree.

        Args:
            panel (QWidget): Mounted Watcher operator panel.
        Returns:
            None: Creates a passive event filter with no widget mutation.
        """
        super().__init__(panel)
        self.panel = panel
        self.count = 0

    def eventFilter(self, watched: QObject, event: QEvent) -> bool:
        """Count foreground widget paints while preserving normal delivery.

        Args:
            watched (QObject): Qt object receiving the current event.
            event (QEvent): Native event dispatched by QApplication.
        Returns:
            bool: False so Qt continues normal event processing.
        """
        if (event.type() == QEvent.Type.Paint and isinstance(watched, QWidget)
                and (watched is self.panel or self.panel.isAncestorOf(watched))):
            self.count += 1
        return False


def write_synthetic_screenshot(path: Path, index: int) -> None:
    """Build a deterministic PNG outside the measured Watcher interval.

    Args:
        path (Path): Staged local screenshot outside the watched directory.
        index (int): Stable per-scenario image identity.
    Returns:
        None: A complete full-HD RGB PNG is ready for atomic admission.
    """
    width, height = SYNTHETIC_SCREENSHOT_SIZE
    # RGB has three independent byte channels. Fixed seeds produce distinct
    # screenshot-sized file payloads without relying on the operator's files.
    raw = Random(index).randbytes(width * height * 3)
    with Image.frombytes("RGB", SYNTHETIC_SCREENSHOT_SIZE, raw) as screenshot:
        screenshot.save(path, format="PNG")


class SyntheticPreflightWorker:
    """Publish bounded local preflight events through the real QtImport queue."""

    def __init__(
        self, events: ImportEvents, messages: tuple[str, ...],
        timestamps: dict[str, float],
    ) -> None:
        """Retain one event destination and its deterministic progress labels.

        Args:
            events (ImportEvents): Existing queue owned by one QtImport instance.
            messages (tuple[str, ...]): Unique labels used to timestamp GUI delivery.
            timestamps (dict[str, float]): Producer timestamps keyed by each label.
        Returns:
            None: The worker is unstarted and owns no files or network work.
        """
        self.events = events
        self.messages = messages
        self.timestamps = timestamps
        self.cancelled = Event()
        self.thread: Thread | None = None

    def start(self) -> None:
        """Start a connection-free producer thread for the native view queue.

        Returns:
            None: Tuple events and terminal preflight state publish asynchronously.
        """
        def publish() -> None:
            """Queue deterministic status events and a terminal cancelled result.

            Returns:
                None: The Qt view receives the same bounded local queue shape.
            """
            for message in self.messages:
                if self.cancelled.is_set():
                    break
                self.timestamps[message] = time.perf_counter()
                self.events.put(("info", message))
                time.sleep(EVENT_INTERVAL_SECONDS)
            self.events.put({"kind": "preflight_cancelled", "payload": {"message": ""}})

        self.thread = Thread(target=publish, name="Issue445SyntheticImport", daemon=True)
        self.thread.start()

    def stop(self) -> None:
        """Request producer cancellation through its owned event.

        Returns:
            None: The worker retains ownership until its thread exits.
        """
        self.cancelled.set()

    def is_alive(self) -> bool:
        """Report the actual producer thread lifetime to QtImport.

        Returns:
            bool: True while the producer has not completed.
        """
        return self.thread is not None and self.thread.is_alive()


def wait_until(
    app: QApplication, predicate: Callable[[], bool], deadline: float,
) -> None:
    """Pump Qt until a predicate holds or the process deadline expires.

    Args:
        app (QApplication): Current native event-loop owner.
        predicate (Callable[[], bool]): Required observable completion state.
        deadline (float): Absolute monotonic end time for this lifecycle.
    Returns:
        None: The state became observable while native events were delivered.
    Raises:
        RuntimeError: The bounded hosted scenario failed to settle.
    """
    while time.monotonic() < deadline:
        app.processEvents()
        if predicate():
            return
        time.sleep(POLL_INTERVAL_SECONDS)
    raise RuntimeError("The Qt shell did not settle before its deadline.")


def produce_events(
    queue: Queue[tuple[str, str] | dict[str, object]], prefix: str,
    count: int, timestamps: dict[str, float],
) -> None:
    """Feed unique synthetic worker events into the production mailbox.

    Args:
        queue (Queue[tuple[str, str] | dict[str, object]]): Native worker mailbox.
        prefix (str): Stable channel identity used in each event label.
        count (int): Number of bounded queue events to publish.
        timestamps (dict[str, float]): Producer monotonic timestamp by label.
    Returns:
        None: Each event has the existing tuple[str, str] message shape.
    """
    for index in range(count):
        label = f"{prefix}-{index:05d}"
        timestamps[label] = time.perf_counter()
        queue.put(("info", label))
        time.sleep(EVENT_INTERVAL_SECONDS)


def measure_quiet_shell(window: QtWindow, read_frames: Callable[[], int]) -> list[dict[str, object]]:
    """Observe native rendering and CPU in foreground and background modes.

    Args:
        window (QtWindow): The actual production shell on its hosted desktop.
        read_frames (Callable[[], int]): Current Qt scene-graph render proxy count.
    Returns:
        list[dict[str, object]]: Bounds, CPU time, presentation and restore evidence.
    """
    root_process = psutil.Process()
    states = (
        ("photos-visible-idle", lambda: window.show_page("photos")),
        ("photos-inactive", lambda: window.show_page("watcher")),
        ("window-minimized", window.showMinimized),
        ("hidden-to-tray", window.hide_to_tray),
    )
    observations: list[dict[str, object]] = []
    loop = QEventLoop()
    for name, action in states:
        if name in {"hidden-to-tray", "window-minimized"}:
            window.show_page("photos")
        action()
        QTimer.singleShot(QUIET_SETTLE_MS, loop.quit)
        loop.exec()
        if name == "hidden-to-tray" and not window.isHidden():
            observations.append({"state": name, "status": "BLOCKED", "reason": "No native tray."})
            continue
        if name == "window-minimized" and not window.isMinimized():
            observations.append({"state": name, "status": "BLOCKED", "reason": "Window manager refused minimize."})
            continue
        # This source harness uses in-process Qt and produces no child workers
        # during quiet samples. Retain the observed child count explicitly.
        child_count = len(root_process.children(recursive=True))
        if child_count:
            raise RuntimeError("The isolated idle shell unexpectedly spawned a child process.")
        start_cpu = root_process.cpu_times()
        frames = read_frames()
        before_rss = root_process.memory_info().rss
        started = time.perf_counter()
        QTimer.singleShot(QUIET_SAMPLE_MS, loop.quit)
        loop.exec()
        elapsed = time.perf_counter() - started
        end_cpu = root_process.cpu_times()
        cpu_seconds = end_cpu.user + end_cpu.system - start_cpu.user - start_cpu.system
        after_rss = root_process.memory_info().rss
        rendered = read_frames() - frames
        child_count = len(root_process.children(recursive=True))
        if child_count:
            raise RuntimeError("The isolated idle shell spawned a child process during measurement.")
        restore_ms = None
        if name in {"hidden-to-tray", "window-minimized"}:
            restored = time.perf_counter()
            window.restore_window()
            wait_until(QApplication.instance(), lambda: window.isVisible() and not window.isMinimized(),
                       time.monotonic() + QUIET_SETTLE_MS / 1000)
            restore_ms = (time.perf_counter() - restored) * 1000
        observations.append({
            "state": name, "status": "PASS", "wall_seconds": elapsed,
            "process_tree_cpu_ms": cpu_seconds * 1000,
            "cpu_percent_of_one_logical_core": cpu_seconds / elapsed * 100,
            "visible_scene_frame_events": rendered, "child_process_count": child_count,
            "rss_before_bytes": before_rss, "rss_after_bytes": after_rss,
            "restore_to_visible_ms": restore_ms,
        })
    return observations


def load_watcher_source() -> ModuleType:
    """Import the production `.pyw` module without opening its Tk application.

    Returns:
        ModuleType: Module exposing the real WatcherThread and WatcherConfig.
    Raises:
        RuntimeError: The hosted source module cannot be loaded.
    """
    source = PROJECT_ROOT / "winapp" / "gallery_watch_upload.pyw"
    loader = importlib.machinery.SourceFileLoader(
        "issue_445_hosted_watcher_benchmark", str(source),
    )
    spec = importlib.util.spec_from_loader(loader.name, loader)
    if spec is None:
        raise RuntimeError("Could not create the production Watcher import spec.")
    module = importlib.util.module_from_spec(spec)
    sys.modules[spec.name] = module
    loader.exec_module(module)
    return module


def run_active_watcher(
    app: QApplication, window: QtWindow, capture_commands: Mock,
    watcher_view: QtCapture, frame_count: list[int],
) -> dict[str, object]:
    """Measure a real durable Watcher FIFO through a local transport stub.

    Args:
        app (QApplication): Hosted Qt event-loop owner.
        window (QtWindow): Visible production shell.
        capture_commands (Mock): Existing QtCapture port whose mailbox is shared
            with the actual WatcherThread.
        watcher_view (QtCapture): Mounted operator workspace receiving worker events.
        frame_count (list[int]): Existing QQuick afterRendering proxy counter.
    Returns:
        dict[str, object]: Admission, FIFO, local upload, UI and process evidence.
    Raises:
        RuntimeError: Worker admission, local transport, durability or UI delivery fails.
    """
    main = load_watcher_source()
    root_context = tempfile.TemporaryDirectory(prefix="issue-445-active-watcher-")
    root = Path(root_context.name)
    profile = root / "profile"
    profile.mkdir()
    watched = root / "screenshots"
    watched.mkdir()
    staged_paths = []
    for index in range(ACTIVE_WATCHER_SCREENSHOT_COUNT):
        staged = root / f"synthetic-screenshot-{index + 1:02d}.png"
        write_synthetic_screenshot(staged, index)
        staged_paths.append(staged)
    session = root / "flight-session" / ("a" * 32)
    session.mkdir(parents=True)
    main.CONFIG_DIR = profile
    main.STATE_PATH = profile / "state.json"
    gallery_url = "https://gallery.example.invalid"
    config = main.WatcherConfig(
        gallery_url=gallery_url, api_key="synthetic-fixture-key",
        watched_folder=str(watched), scan_interval_seconds=0.1,
        stable_seconds=0.0, attach_sim_camera_metadata=False,
        delete_uploaded_files=False, watch_recursive=False,
    )
    worker_events = capture_commands.events
    worker = main.WatcherThread(config, worker_events)
    worker.configure_drain(session, [])
    inventory_calls: list[dict[str, object]] = []
    upload_calls: list[dict[str, object]] = []
    activity_events: list[dict[str, object]] = []
    source_names: list[str] = []

    def local_inventory(
        url: str, key: str, candidates: list[dict[str, JsonValue]],
        force: bool = False, deep_check: bool = False,
    ) -> bool:
        """Return an authorized fixture response without opening a socket.

        Args:
            url (str): Synthetic worker endpoint that must remain local to fixture logic.
            key (str): Synthetic-only credential passed by production inventory code.
            candidates (list[dict[str, JsonValue]]): Current FIFO head rows with
                filename, SHA-256 and size in the original inventory shape.
            force (bool): Whether the current head requests an immediate lookup.
            deep_check (bool): Whether a prior uncertain attempt needs reconciliation.
        Returns:
            bool: True for a local empty-gallery inventory response.
        Raises:
            RuntimeError: The real worker passes an unexpected fixture identity.
        """
        if (url != main.normalize_upload_url(gallery_url)
                or key != "synthetic-fixture-key" or len(candidates) != 1
                or not force or deep_check):
            raise RuntimeError("Watcher inventory escaped its local fixture boundary.")
        candidate = candidates[0]
        filename = candidate.get("filename")
        if not isinstance(filename, str) or filename not in source_names:
            raise RuntimeError("Watcher inventory did not identify an admitted screenshot.")
        inventory_calls.append({"filename": filename, "candidate_count": len(candidates)})
        return True

    def local_upload(
        url: str, path: Path, metadata: dict[str, str], file_hash: str, size: int,
    ) -> dict[str, object]:
        """Accept exact synthetic source bytes through a bounded local stub.

        Args:
            url (str): Captured synthetic gallery endpoint.
            path (Path): Current real Watcher FIFO head source.
            metadata (dict[str, str]): Empty fixture metadata from disabled SimConnect.
            file_hash (str): SHA-256 already computed by the production pipeline.
            size (int): Exact screenshot bytes already measured by the pipeline.
        Returns:
            dict[str, object]: Existing legacy success response shape accepted by Watcher.
        Raises:
            RuntimeError: Target or source evidence differs from this fixture.
        """
        with path.open("rb") as stream:
            png_header = stream.read(8)
        if (url != main.normalize_upload_url(gallery_url)
                or path.name not in source_names or not path.is_file()
                or path.stat().st_size != size or main.sha256_file(path) != file_hash
                or png_header != b"\x89PNG\r\n\x1a\n" or metadata):
            raise RuntimeError("Watcher upload did not match its synthetic source evidence.")
        started = time.perf_counter()
        worker._mark_watch_dispatch()
        time.sleep(ACTIVE_WATCHER_STUB_SECONDS)
        upload_calls.append({
            "filename": path.name, "size_bytes": size,
            "sha256": file_hash,
            "stub_elapsed_ms": (time.perf_counter() - started) * 1000,
        })
        return {"ok": True, "uploaded": 1, "scanned": 1}

    worker.remote_inventory.refresh = local_inventory
    worker.remote_inventory.has_hash = lambda _file_hash: False
    worker.remote_inventory.last_error = None
    worker.upload_watched_file = local_upload
    capture_commands.events = worker.events

    def record_active_activity(level: str, message: str) -> None:
        """Retain only fixture labels observed by the real QtCapture signal.

        Args:
            level (str): Production Watcher message severity.
            message (str): Sanitized operator message from QtCapture.
        Returns:
            None: Stores a bounded stage and synthetic filename only.
        """
        if message.startswith("Watching "):
            activity_events.append({"stage": "watching", "level": level})
        elif message.startswith("Uploading synthetic-screenshot-"):
            activity_events.append({"stage": "uploading", "level": level})
        elif message.startswith("Uploaded synthetic-screenshot-"):
            activity_events.append({"stage": "uploaded", "level": level})

    watcher_view.activity.connect(record_active_activity)

    def snapshot() -> CaptureStatus:
        """Project only scalar state from the actual benchmark Watcher.

        Returns:
            CaptureStatus: Read-only panel state for the live worker lifecycle.
        """
        active = worker.is_alive()
        return CaptureStatus(
            phase="running" if active else "idle", alive=active,
            capturing=active and not worker.stop_event.is_set(),
            error=worker.drain_error,
        )

    capture_commands.snapshot.side_effect = snapshot
    paint_counter = QtPaintCounter(watcher_view)
    app.installEventFilter(paint_counter)
    started = time.perf_counter()
    cpu_before = psutil.Process().cpu_times()
    rss_samples: list[int] = [psutil.Process().memory_info().rss]
    next_resource_sample = time.monotonic() + RESOURCE_INTERVAL_SECONDS
    frame_before = frame_count[0]
    window.show_page("watcher")
    window.showNormal()
    window.activateWindow()
    deadline = time.monotonic() + ACTIVE_WATCHER_DEADLINE_SECONDS
    try:
        worker.start()
        wait_until(app, lambda: any(event.get("stage") == "watching" for event in activity_events), deadline)
        for index, staged in enumerate(staged_paths):
            path = watched / staged.name
            source_names.append(path.name)
            staged.replace(path)
            while time.monotonic() < deadline:
                app.processEvents()
                if time.monotonic() >= next_resource_sample:
                    rss_samples.append(psutil.Process().memory_info().rss)
                    next_resource_sample = time.monotonic() + RESOURCE_INTERVAL_SECONDS
                journal = worker._queue
                items = journal.items() if journal is not None else []
                accepted = any(item.source.path == str(path) for item in items)
                acknowledged = any(
                    item.source.path == str(path) and item.state == "acknowledged"
                    for item in items
                )
                if accepted and acknowledged and len(upload_calls) == index + 1:
                    break
                time.sleep(POLL_INTERVAL_SECONDS)
            else:
                raise RuntimeError(f"Watcher did not durably upload {path.name}.")
        worker.stop()
        worker._consumer_wake.set()
        wait_until(app, lambda: not worker.is_alive(), deadline)
        worker.join(timeout=WORKER_JOIN_SECONDS)
        rss_samples.append(psutil.Process().memory_info().rss)
        journal_row = WatchQueueStore.read_journal(session / "watch-drain.json")
        raw_items = journal_row.get("items")
        if not isinstance(raw_items, list):
            raise RuntimeError("Watcher durable journal has no item collection.")
        if any(
            not isinstance(row, dict) or not isinstance(row.get("source"), dict)
            or not isinstance(row["source"].get("path"), str)
            or not isinstance(row.get("state"), str)
            for row in raw_items
        ):
            raise RuntimeError("Watcher durable journal item shape is malformed.")
        item_names = [Path(row["source"]["path"]).name for row in raw_items]
        item_states = [row["state"] for row in raw_items]
        if (item_names != source_names or item_states != ["acknowledged"] * len(source_names)
                or journal_row.get("pending") != []
                or len(inventory_calls) != ACTIVE_WATCHER_SCREENSHOT_COUNT
                or len(upload_calls) != ACTIVE_WATCHER_SCREENSHOT_COUNT
                or any(not (watched / name).is_file() for name in source_names)):
            raise RuntimeError("Watcher FIFO or retained source readback did not match uploads.")
        if not any(event.get("stage") == "uploading" for event in activity_events):
            raise RuntimeError("QtCapture did not display the real Watcher upload activity.")
        if paint_counter.count <= 0:
            raise RuntimeError("The foreground Watcher workspace produced no widget paint proxy events.")
        wait_until(app, lambda: watcher_view._header_count == "0", deadline)
        cpu_after = psutil.Process().cpu_times()
        cpu_seconds = (
            cpu_after.user + cpu_after.system - cpu_before.user - cpu_before.system
        )
        return {
            "status": "PASS",
            "scenario": "real-watcher-thread-synthetic-screenshots-local-stub",
            "fixture": {
                "watcher_thread": "production WatcherThread",
                "queue": "production WatchQueueStore; exact shared QtCapture event queue",
                "screenshots_admitted": len(source_names),
                "screenshot_size": list(SYNTHETIC_SCREENSHOT_SIZE),
                "inventory_stub_calls": len(inventory_calls),
                "upload_stub_calls": len(upload_calls),
                "upload_admitted_to_external_service": False,
                "external_services": False,
                "sources_retained": all((watched / name).is_file() for name in source_names),
            },
            "durable_readback": {
                "session_id": session.name,
                "item_filenames": item_names,
                "item_states": item_states,
                "pending_count": len(journal_row.get("pending", [])),
                "acknowledged_count": sum(state == "acknowledged" for state in item_states),
            },
            "ui": {
                "foreground_workspace": "watcher",
                "activity_events": activity_events,
                "displayed_pending_count": watcher_view._header_count,
                "watcher_widget_paint_events": paint_counter.count,
                "qquick_after_rendering_delta": frame_count[0] - frame_before,
                "proxy_limit": "Widget paints and QQuick afterRendering are software proxies, not physical display measurements.",
            },
            "timings": {
                "wall_seconds": time.perf_counter() - started,
                "process_cpu_ms": cpu_seconds * 1000,
                "upload_stub_elapsed_ms": [row["stub_elapsed_ms"] for row in upload_calls],
            },
            "resources": {
                "rss_samples_bytes": rss_samples,
                "rss_peak_sampled_bytes": max(rss_samples, default=0),
                "rss_first_sample_bytes": rss_samples[0] if rss_samples else 0,
                "rss_last_sample_bytes": rss_samples[-1] if rss_samples else 0,
            },
            "limitations": [
                "The production WatcherThread and durable FIFO run; inventory and upload transports are local stubs.",
                "Synthetic PNGs validate source admission and hashing but do not represent game capture or physical GPU load.",
                "Process CPU includes Qt and Watcher threads together; RSS is sampled at the existing 250 ms cadence.",
            ],
        }
    finally:
        if worker.is_alive():
            worker.stop()
            worker._consumer_wake.set()
            worker.join(timeout=ACTIVE_WATCHER_DEADLINE_SECONDS)
        app.removeEventFilter(paint_counter)
        watcher_view.activity.disconnect(record_active_activity)
        if not worker.is_alive():
            root_context.cleanup()


def run_shell(args: argparse.Namespace) -> dict[str, object]:
    """Measure Qt shell publishers and a separate real active Watcher journey.

    Args:
        args (argparse.Namespace): Output path and optional bounded soak duration.
    Returns:
        dict[str, object]: Startup, workspace, event, resource and scope evidence.
    Raises:
        RuntimeError: Qt readiness, event publishing, active Watcher or soak failed.
    """
    app = QApplication([])
    app.setApplicationName("PHP Gallery uploader benchmark")
    apply_qt_theme(app)
    samples: dict[str, list[float]] = {"watcher": [], "import": []}
    event_timestamps: dict[str, float] = {}
    capture_commands = Mock()
    capture_commands.events = Queue()
    capture_commands.snapshot.return_value = CaptureStatus()
    capture_commands.reconcile_workers.return_value = None
    # The idle fixture has no active Watcher destination for saved reports.
    capture_commands.report_target.return_value = None
    profile_context = tempfile.TemporaryDirectory(prefix="issue-445-qt-shell-")
    profile = Path(profile_context.name)
    message_count = EVENT_BURST if args.soak_seconds == 0 else int(
        args.soak_seconds / EVENT_INTERVAL_SECONDS,
    )
    import_worker: SyntheticPreflightWorker | None = None

    def prepare(source: ImportSource, events: ImportEvents) -> SyntheticPreflightWorker:
        """Admit only a bounded worker that publishes preflight status tuples.

        Args:
            source (ImportSource): Isolated synthetic source selected by the harness.
            events (ImportEvents): QtImport's current event mailbox.
        Returns:
            SyntheticPreflightWorker: Unstarted local publisher with no transfer owner.
        """
        nonlocal import_worker
        if source.kind() != "files":
            raise RuntimeError("The synthetic Import panel expects explicit files.")
        import_worker = SyntheticPreflightWorker(
            events, tuple(f"import-{index:05d}" for index in range(message_count)),
            event_timestamps,
        )
        return import_worker

    def forbidden_admit(
        job: ImportJob, approval: ImportApproval, local_thumbnails: bool,
        events: ImportEvents,
    ) -> ImportWorkerPort:
        """Reject any accidental upload admission in this no-service fixture.

        Args:
            job (ImportJob): Reviewed job that the fixture must not dispatch.
            approval (ImportApproval): Preflight approval that must not be consumed.
            local_thumbnails (bool): Thumbnail option that must not start a worker.
            events (ImportEvents): Queue that must not receive upload events.
        Returns:
            ImportWorkerPort: No worker is returned because admission raises.
        Raises:
            RuntimeError: The benchmark attempted to progress beyond preflight.
        """
        raise RuntimeError("The shell workload must not admit an upload.")

    def forbidden_retry(
        job_id: str, local_thumbnails: bool, events: ImportEvents,
    ) -> tuple[ImportWorkerPort | None, int]:
        """Reject any retry attempt in this no-service fixture.

        Args:
            job_id (str): Persisted import identity that must not be retried.
            local_thumbnails (bool): Thumbnail option that must not start a worker.
            events (ImportEvents): Queue that must not receive retry events.
        Returns:
            tuple[ImportWorkerPort | None, int]: No worker result is returned.
        Raises:
            RuntimeError: The benchmark attempted to progress beyond preflight.
        """
        raise RuntimeError("The shell workload must not retry an upload.")

    actions = ImportActions(
        prepare=prepare, admit=forbidden_admit, retry=forbidden_retry,
        retire=lambda _job_id: None, use_local_thumbnails=False,
    )
    window = QtWindow(
        profile, "benchmark", "", 500, False, import_actions=actions,
        capture=capture_commands,
    )
    window.resize(900, 680)
    watcher = window.capture
    import_view = window.import_flow
    if watcher is None or import_view is None:
        window.exit_window()
        profile_context.cleanup()
        raise RuntimeError("The Qt shell did not mount its native operator panels.")

    def record_watcher(_level: str, message: str) -> None:
        """Record Watcher message time when QtCapture publishes to its panel.

        Args:
            _level (str): Synthetic worker severity.
            message (str): Unique queue event identity.
        Returns:
            None: Captures producer-to-view event-loop latency.
        """
        if message in event_timestamps:
            samples["watcher"].append((time.perf_counter() - event_timestamps[message]) * 1000)

    def record_import(_level: str, message: str, _job_id: str) -> None:
        """Record Import message time when QtImport emits its activity signal.

        Args:
            _level (str): Synthetic worker severity.
            message (str): Unique queue event identity.
            _job_id (str): Empty because no import has been admitted.
        Returns:
            None: Captures producer-to-view event-loop latency.
        """
        if message in event_timestamps:
            samples["import"].append((time.perf_counter() - event_timestamps[message]) * 1000)

    watcher.activity.connect(record_watcher)
    import_view.activity.connect(record_import)
    started = time.perf_counter()
    frame_count = [0]
    window.photos.quick.quickWindow().afterRendering.connect(
        lambda: frame_count.__setitem__(0, frame_count[0] + 1),
    )
    window.photos.prepare_graphics_readiness()
    window.show()
    drift_timer = QTimer(window)
    drift_timer.setTimerType(Qt.TimerType.PreciseTimer)
    deadline = time.monotonic() + PROCESS_DEADLINE_SECONDS
    try:
        wait_until(app, lambda: frame_count[0] > 0, deadline)
        startup_ms = (time.perf_counter() - started) * 1000
        window.photos.finish_graphics_readiness()
        idle_photo = profile / "shell-idle-photo.png"
        write_synthetic_screenshot(idle_photo, WORKSPACE_ROUNDS)
        window.photos.open_files((idle_photo,))
        wait_until(app, lambda: bool(window.photos._tiles), deadline)
        quiet_shell = measure_quiet_shell(window, lambda: frame_count[0])
        pages = ("watcher", "import", "activity", "photos")
        switch_ms: list[float] = []
        for _round in range(WORKSPACE_ROUNDS):
            for page in pages:
                before = time.perf_counter()
                window.show_page(page)
                app.processEvents()
                if window.tabs.currentWidget() is not window._pages[page]:
                    raise RuntimeError("A Qt operator workspace did not activate.")
                switch_ms.append((time.perf_counter() - before) * 1000)

        watcher_producer = Thread(
            target=produce_events,
            args=(capture_commands.events, "watcher", message_count, event_timestamps),
            name="Issue445SyntheticWatcherPublisher", daemon=True,
        )
        watcher_producer.start()
        local_source = profile / "synthetic-import.jpg"
        local_source.write_bytes(b"synthetic source; never admitted for upload")
        import_view.select_source(ImportSource(files=(local_source,)))
        if import_worker is None:
            wait_until(app, lambda: import_worker is not None, deadline)
        rss_samples: list[int] = []
        worker_cpu_started = time.process_time()
        frames_before_soak = frame_count[0]
        soak_started = time.monotonic()
        # QTimer uses milliseconds. Keep the schedule on the same monotonic
        # clock as the soak so late delivery does not move the reference phase.
        heartbeat_seconds = SOAK_HEARTBEAT_MS / 1000
        next_tick = soak_started + heartbeat_seconds
        tick_drift_ms: list[float] = []
        coalesced_ticks = 0

        def record_event_loop_drift() -> None:
            """Observe one native timer callback against its scheduled phase.

            Returns:
                None: Records elapsed lateness and periods coalesced by Qt.
            """
            nonlocal next_tick, coalesced_ticks
            lateness = max(0.0, time.monotonic() - next_tick)
            skipped_periods = int(lateness / heartbeat_seconds)
            tick_drift_ms.append(lateness * 1000)
            coalesced_ticks += skipped_periods
            next_tick += (skipped_periods + 1) * heartbeat_seconds

        drift_timer.timeout.connect(record_event_loop_drift)
        drift_timer.start(SOAK_HEARTBEAT_MS)
        next_sample = soak_started
        next_switch = soak_started + WORKSPACE_SWITCH_INTERVAL_SECONDS
        switch_index = 0
        soak_pages = ("watcher", "import", "activity", "photos")
        while time.monotonic() < deadline:
            app.processEvents()
            now = time.monotonic()
            if now >= next_sample:
                rss_samples.append(psutil.Process().memory_info().rss)
                next_sample = now + RESOURCE_INTERVAL_SECONDS
            if now >= next_switch:
                window.show_page(soak_pages[switch_index % len(soak_pages)])
                switch_index += 1
                next_switch = now + WORKSPACE_SWITCH_INTERVAL_SECONDS
            if (len(samples["watcher"]) >= message_count
                    and len(samples["import"]) >= message_count
                    and not import_view.busy()):
                break
            time.sleep(POLL_INTERVAL_SECONDS)
        else:
            raise RuntimeError("Qt worker events did not drain before the shell soak deadline.")
        drift_timer.stop()
        if not tick_drift_ms:
            raise RuntimeError("The active Qt soak did not deliver any native timer callbacks.")
        watcher_producer.join(timeout=WORKER_JOIN_SECONDS)
        soak_elapsed = time.monotonic() - soak_started
        soak_cpu = time.process_time() - worker_cpu_started
        soak_frames = frame_count[0] - frames_before_soak
        active_watcher = run_active_watcher(
            app, window, capture_commands, watcher, frame_count,
        )
        return {
            "schema_version": 1,
            "status": "PASS",
            "stage": "qt-shell-representative",
            "source_sha": args.source_sha,
            "scenario": "startup-workspaces-worker-events-soak",
            "cross_toolkit_paired": False,
            "scope": "Qt native panel event delivery; compare with separate issue 434 Tk shell cells.",
            "fixture": {
                "watcher_events": message_count,
                "import_events": message_count,
                "import_source_file": "synthetic-import.jpg",
                "upload_admitted": False,
                "external_services": False,
            },
            "timings_ms": {
                "startup_to_qquick_after_rendering_proxy": startup_ms,
                "workspace_switch_action_to_qt_state": switch_ms,
                "watcher_queue_to_signal": samples["watcher"],
                "import_queue_to_signal": samples["import"],
                "event_loop_tick_drift": tick_drift_ms,
            },
            "resources": {
                "event_soak_elapsed_seconds": soak_elapsed,
                "requested_soak_seconds": args.soak_seconds,
                "rss_samples_bytes": rss_samples,
                "rss_peak_sampled_bytes": max(rss_samples, default=0),
                "rss_first_sample_bytes": rss_samples[0] if rss_samples else 0,
                "rss_last_sample_bytes": rss_samples[-1] if rss_samples else 0,
                "event_soak_cpu_ms": soak_cpu * 1000,
                "event_soak_scene_frames": soak_frames,
                "event_loop_heartbeat_ms": SOAK_HEARTBEAT_MS,
                "event_loop_coalesced_ticks": coalesced_ticks,
            },
            "quiet_shell": quiet_shell,
            "active_watcher": active_watcher,
            "limitations": [
                "QQuickWindow afterRendering confirms scene-graph completion, not physical display proof.",
                "Workspace switch timing ends at Qt currentWidget state, not human input or paint completion.",
                "Synthetic worker tuples exercise existing Qt mailboxes and receivers, not live network services.",
                "The active Watcher subscenario separately runs production admission, FIFO and acknowledgement with local-only inventory/upload stubs.",
                "Watcher/Import shell cells are representative, not cross-toolkit paired with Photos rendering.",
                "Event-loop drift measures native Qt timer lateness and coalesced periods during the active soak.",
                "RSS is sampled every 250 ms and may miss shorter transient peaks.",
            ],
        }
    finally:
        drift_timer.stop()
        if import_worker is not None:
            import_worker.stop()
            if import_worker.thread is not None:
                import_worker.thread.join(timeout=WORKER_JOIN_SECONDS)
        window.exit_window()
        profile_context.cleanup()


def main() -> int:
    """Admit one hosted Windows Qt shell measurement and retain raw evidence.

    Returns:
        int: Zero when every shell, panel, event and soak phase completes.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--soak-seconds", type=int, default=SOAK_SECONDS)
    parser.add_argument("--source-sha")
    args = parser.parse_args()
    expected_sha = (
        os.environ.get("PHP_GALLERY_ISSUE_445_CANDIDATE")
        or os.environ.get("GITHUB_SHA", "")
    )
    actual_head = subprocess.run(
        ["git", "rev-parse", "HEAD"], check=False, capture_output=True,
        text=True, cwd=Path.cwd(),
    )
    args.source_sha = args.source_sha or expected_sha
    if (os.environ.get("GITHUB_ACTIONS") != "true" or sys.platform != "win32"
            or not args.source_sha or args.source_sha != expected_sha
            or actual_head.returncode != 0 or actual_head.stdout.strip() != args.source_sha
            or not args.output.is_absolute() or args.soak_seconds < 0
            or args.soak_seconds > SOAK_SECONDS):
        parser.error("Run on hosted Windows with an exact source SHA and bounded soak.")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    try:
        report = run_shell(args)
        report["host"] = {
            "platform": platform.platform(), "python": sys.version,
            "qt_version": qVersion(),
        }
        report["dependency_versions"] = dependency_versions()
    except Exception as error:
        report = {
            "schema_version": 1, "status": "FAIL",
            "stage": "qt-shell-representative", "source_sha": args.source_sha,
            "dependency_versions": dependency_versions(),
            "failure": f"{type(error).__name__}: {error}"[:1000],
        }
    args.output.write_text(json.dumps(report, indent=2, allow_nan=False), encoding="utf-8")
    csv_path = args.output.with_suffix(".csv")
    with csv_path.open("w", newline="", encoding="utf-8") as stream:
        writer = csv.DictWriter(
            stream, fieldnames=("channel", "sample_index", "latency_ms", "dependency_versions"),
        )
        writer.writeheader()
        timing_metrics = report.get("timings_ms", {})
        samples_by_channel = {
            channel: timing_metrics.get(f"{channel}_queue_to_signal", [])
            for channel in ("watcher", "import")
        }
        samples_by_channel["event_loop_drift"] = timing_metrics.get("event_loop_tick_drift", [])
        active_timings = report.get("active_watcher", {}).get("timings", {})
        samples_by_channel["active-watcher-local-upload"] = active_timings.get("upload_stub_elapsed_ms", [])
        for channel, samples in samples_by_channel.items():
            for index, latency in enumerate(samples):
                writer.writerow({
                    "channel": channel, "sample_index": index, "latency_ms": latency,
                    "dependency_versions": json.dumps(
                        report.get("dependency_versions", {}), sort_keys=True,
                    ),
                })
    return int(report.get("status") != "PASS")


if __name__ == "__main__":
    raise SystemExit(main())
