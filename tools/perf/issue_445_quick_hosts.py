# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_445_quick_hosts.py
# Module Type: Hosted Qt Quick host comparison
# Purpose: Compare the embedded and native Quick hosts with identical scene work.
# Responsibilities:
#   - Retain independent process evidence for both native scene configurations.
#   - Check the same visible photo identities before comparing frame proxies.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)

"""Measure one controlled viewport in two independent native Quick hosts."""

from hashlib import sha256
import json
from pathlib import Path
from statistics import median
import subprocess
import sys
from tempfile import TemporaryDirectory
import time
from typing import Callable

import psutil
from PySide6.QtCore import Qt, QUrl
from PySide6.QtGui import QColor, QImage
from PySide6.QtQuick import QQuickItem, QQuickView
from PySide6.QtQuickWidgets import QQuickWidget
from PySide6.QtWidgets import QApplication

from uploader.models import JsonValue
from uploader.qt_photo_model import PhotoListModel, PhotoTextures
from uploader.qt_photo_source import PhotoRow

# Purpose: Keep the same 24 visible tiles in both native scene hosts.
# Type: tuple[int, int]. Units: logical pixels. Scope: the controlled viewport.
# Consumers: sample_quick_host. Rationale: 720 by 480 admits six by four cells.
HOST_VIEW_SIZE = (720, 480)
# Purpose: Keep each fixture cell identical on both sides of the comparison.
# Type: int. Units: logical pixels. Scope: each rendered grid cell.
# Consumers: sample_quick_host. Rationale: 120 gives an exact row boundary.
HOST_CELL_EDGE = 120
# Purpose: Exercise reuse while providing enough photos for distant row jumps.
# Type: int. Units: photos. Scope: the shared local catalogue.
# Consumers: sample_quick_host. Rationale: 2,000 matches the largest comparison.
HOST_CATALOGUE_SIZE = 2000
# Purpose: Compare identical forward and backward viewport changes.
# Type: tuple[int, ...]. Units: zero-based grid rows. Scope: one child workload.
# Consumers: sample_quick_host. Rationale: Three positions move far from the viewport.
HOST_JUMP_ROWS = (18, 38, 4)
# Purpose: Alternate order to limit position bias across fresh native processes.
# Type: tuple[tuple[str, str], ...]. Units: paired process orders.
# Scope: one fixed-host comparison. Consumers: compare_quick_hosts.
# Rationale: Four pairs allow two observations in each order without a second
# long paired acceptance matrix.
HOST_TRIAL_ORDERS = (
    ("embedded", "window"), ("window", "embedded"),
    ("embedded", "window"), ("window", "embedded"),
)
# Purpose: Bound one test process even if native graphics stops responding.
# Type: int. Units: seconds. Scope: one controlled host sample.
# Consumers: compare_quick_hosts. Rationale: 75 leaves time for the scene
# deadline and three independently observed viewport endpoints.
HOST_CHILD_TIMEOUT_SECONDS = 75

HOST_SCENE = """import QtQuick

GridView {
    id: grid
    model: hostModel
    cellWidth: fixtureCellEdge
    cellHeight: fixtureCellEdge
    boundsBehavior: Flickable.StopAtBounds
    clip: true
    reuseItems: true

    delegate: Image {
        required property int index
        required property string textureUrl
        objectName: status === Image.Ready ? "fixture-photo-" + index : ""
        width: grid.cellWidth
        height: grid.cellHeight
        source: textureUrl
        fillMode: Image.PreserveAspectFit
    }
}
"""


def _present_indices(scene: QQuickItem) -> list[int]:
    """Read the loaded image delegates inside the live viewport.

    Args:
        scene (QQuickItem): The current native host's GridView.
    Returns:
        list[int]: Sorted visible and image-ready fixture row indices.
    """
    top = float(scene.property("contentY"))
    bottom = top + scene.height()
    content = scene.property("contentItem")
    return sorted(
        int(child.objectName().removeprefix("fixture-photo-"))
        for child in content.childItems()
        if child.objectName().startswith("fixture-photo-")
        and child.y() < bottom and child.y() + child.height() > top
    )


def sample_quick_host(
    app: QApplication, role: str,
    wait: Callable[[QApplication, Callable[[], bool], str], None],
) -> dict[str, JsonValue]:
    """Exercise an isolated native Quick widget or native Quick window.

    Args:
        app (QApplication): Sole GUI owner in this fresh child process.
        role (str): Embedded QQuickWidget or independent QQuickView.
        wait (Callable[[QApplication, Callable[[], bool], str], None]):
            Existing bounded observer of native Qt state.
    Returns:
        dict[str, JsonValue]: Role, geometry, fixture identity, frame-proxy
            timings, actual graphics API, sampled RSS and process CPU.
    Raises:
        RuntimeError: The scene failed or the expected row identities never rendered.
    """
    process = psutil.Process()
    initial_cpu = process.cpu_times()
    image = QImage(HOST_CELL_EDGE, HOST_CELL_EDGE, QImage.Format.Format_RGB32)
    image.fill(QColor("#0ea5b7"))
    provider = PhotoTextures()
    texture_url = provider.store("comparison", image)
    model = PhotoListModel()
    model.replace(tuple(
        PhotoRow(index + 1, f"Fixture {index + 1}", Path("synthetic"))
        for index in range(HOST_CATALOGUE_SIZE)
    ))
    model.texture_urls.update({row.identity: texture_url for row in model.rows})
    if role == "embedded":
        host = QQuickWidget()
        host.setResizeMode(QQuickWidget.ResizeMode.SizeRootObjectToView)
    else:
        host = QQuickView()
        host.setResizeMode(QQuickView.ResizeMode.SizeRootObjectToView)
    host.resize(*HOST_VIEW_SIZE)
    engine = host.engine()
    engine.addImageProvider("photos", provider)
    engine.rootContext().setContextProperty("hostModel", model)
    engine.rootContext().setContextProperty("fixtureCellEdge", HOST_CELL_EDGE)
    frames: list[float] = []

    def sampled_frame() -> None:
        """Record a scene completion marker from the native render thread.

        Returns:
            None: Appends a monotonic timestamp without reading any GUI property.
        """
        frames.append(time.perf_counter())

    quick_window = None
    first_ms: float
    footprint: list[int]
    jumps: list[dict[str, JsonValue]] = []
    rss: list[int] = []
    columns = HOST_VIEW_SIZE[0] // HOST_CELL_EDGE
    visible_count = columns * (HOST_VIEW_SIZE[1] // HOST_CELL_EDGE)
    try:
        with TemporaryDirectory(prefix="445-quick-control-") as directory:
            scene_path = Path(directory) / "HostComparison.qml"
            scene_path.write_text(HOST_SCENE, encoding="utf-8")
            started = time.perf_counter()
            host.setSource(QUrl.fromLocalFile(str(scene_path)))
            scene = host.rootObject()
            if scene is None:
                raise RuntimeError("The native host could not load the shared control scene.")
            quick_window = host.quickWindow() if isinstance(host, QQuickWidget) else host
            quick_window.afterRendering.connect(sampled_frame, Qt.ConnectionType.DirectConnection)
            host.show()
            wait(app, lambda: bool(frames) and _present_indices(scene) == list(range(visible_count)),
                 "host initial visible identities and rendered frame")
            first_ms = (time.perf_counter() - started) * 1000
            footprint = [round(scene.width()), round(scene.height())]
            if tuple(footprint) != HOST_VIEW_SIZE:
                raise RuntimeError(f"Unexpected Quick control viewport: {footprint}.")
            rss.append(process.memory_info().rss)
            for row in HOST_JUMP_ROWS:
                first_index = row * columns
                expected = list(range(first_index, first_index + visible_count))
                previous_frames = len(frames)
                started = time.perf_counter()
                scene.setProperty("contentY", row * HOST_CELL_EDGE)
                wait(app, lambda: len(frames) > previous_frames and _present_indices(scene) == expected,
                     f"host row {row}")
                jumps.append({
                    "row": row, "indices": _present_indices(scene),
                    "frame_proxy_ms": (time.perf_counter() - started) * 1000,
                })
                rss.append(process.memory_info().rss)
            api = quick_window.rendererInterface().graphicsApi().name
    finally:
        if quick_window is not None:
            quick_window.afterRendering.disconnect(sampled_frame)
        host.close()
        app.processEvents()
    cpu = process.cpu_times()
    return {
        "role": role,
        "graphics_api": api,
        "scene_sha256": sha256(HOST_SCENE.encode("utf-8")).hexdigest(),
        "client_size": footprint,
        "first_frame_proxy_ms": first_ms,
        "jumps": jumps,
        "cpu_ms": (cpu.user + cpu.system - initial_cpu.user - initial_cpu.system) * 1000,
        "rss_peak_sampled_bytes": max(rss),
    }


def compare_quick_hosts(driver: Path, output: Path, source_sha: str) -> dict[str, JsonValue]:
    """Compare both hosting choices in alternated fresh native processes.

    Args:
        driver (Path): Existing hosted Photos interaction driver.
        output (Path): Report path naming the current hosted evidence directory.
        source_sha (str): Exact source revision already admitted by the audit.
    Returns:
        dict[str, JsonValue]: Both orders with raw samples, observed timing
            medians and the deliberately limited comparison scope.
    Raises:
        RuntimeError: A child failed or the displayed viewport work differs.
    """
    trials: list[dict[str, JsonValue]] = []
    by_role: dict[str, list[float]] = {}
    for index, order in enumerate(HOST_TRIAL_ORDERS):
        pair = {}
        for role in order:
            path = output.with_name(f"quick-host-{index}-{role}.json")
            child = subprocess.run(
                [sys.executable, str(driver), "--source-sha", source_sha,
                 "--output", str(path), "--quick-host", role],
                capture_output=True, text=True, timeout=HOST_CHILD_TIMEOUT_SECONDS, check=False,
            )
            if not path.is_file():
                raise RuntimeError(f"Quick control child {role} failed: {child.stderr.strip()}")
            report = json.loads(path.read_text(encoding="utf-8"))
            if child.returncode != 0 or report.get("status") != "PASS":
                raise RuntimeError(f"Quick control child {role} failed: {report.get('failure')}.")
            if report["role"] != role or report["source_sha"] != source_sha:
                raise RuntimeError("The Quick comparison report belongs to a different host or source.")
            pair[role] = report
            by_role.setdefault(role, []).append(max(jump["frame_proxy_ms"] for jump in report["jumps"]))
        embedded = pair["embedded"]
        window = pair["window"]
        for field in ("scene_sha256", "client_size"):
            if embedded[field] != window[field]:
                raise RuntimeError(f"Quick control hosts differ in {field}.")
        if ([jump["indices"] for jump in embedded["jumps"]]
                != [jump["indices"] for jump in window["jumps"]]):
            raise RuntimeError("Quick control hosts rendered different photo identities.")
        trials.append({"order": list(order), "embedded": embedded, "window": window})
    return {
        "status": "PASS",
        "source_sha": source_sha,
        "samples": trials,
        "median_worst_jump_ms": {role: median(values) for role, values in by_role.items()},
        "scope": (
            "Identical synthetic scene and retained photo source in fresh native processes. "
            "QQuickWindow.afterRendering is a rendering proxy. This control does not measure "
            "physical display, feature parity, or actual threaded render-loop selection."
        ),
    }
