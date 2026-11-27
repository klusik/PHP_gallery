# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_445_qt_photos_interactions.py
# Module Type: Hosted Qt Photos interaction benchmark
# Purpose: Measure native Qt Quick grid and Lightbox interactions.
# Responsibilities:
#   - Drive the production scene with deterministic local-only photo fixtures.
#   - Retain scroll, drag, zoom, pan, resize and fullscreen interaction timings.
#   - Identify unpaired Tk controls and scene-graph frame proxies explicitly.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Measure native Qt Quick Photos and Lightbox interaction endpoints."""

import argparse
import csv
import json
import os
from pathlib import Path
import platform
import subprocess
import sys
import tempfile
import time
from typing import Callable

from PIL import Image
from PIL import ImageDraw
import psutil

PROJECT_ROOT = Path(__file__).resolve().parents[2]
WINAPP_SOURCE = str(PROJECT_ROOT / "winapp")
if WINAPP_SOURCE not in sys.path:
    sys.path.insert(0, WINAPP_SOURCE)

from uploader.dependency_versions import dependency_versions

from PySide6.QtCore import QEvent, QPoint, QPointF, Qt, qVersion
from PySide6.QtGui import QMouseEvent, QWheelEvent
from PySide6.QtQuick import QQuickItem
from PySide6.QtTest import QTest
from PySide6.QtWidgets import QApplication

from uploader.qt_photos import QtPhotos
from uploader.qt_photo_source import PhotoRow
from uploader.qt_theme import apply_qt_theme
from issue_445_quick_hosts import compare_quick_hosts, sample_quick_host

# Purpose: Keep this no-service interaction fixture representative and bounded.
# Type: int. Units: photo rows. Scope: one Qt Photos interaction process.
# Consumers: run_interactions. Rationale: 2,000 exposes long-grid navigation.
PHOTO_COUNT = 2000
# Purpose: Bound individual interaction readiness waits on hosted Windows.
# Type: float. Units: seconds. Scope: one scene state transition.
# Consumers: wait_until. Rationale: Local fixture reads should settle quickly;
# a longer wait indicates a failed native interaction rather than useful latency.
ACTION_DEADLINE_SECONDS = 15.0
# Purpose: Yield between state observations while the Qt event loop is active.
# Type: float. Units: seconds. Scope: one bounded action wait.
# Consumers: wait_until. Rationale: Five milliseconds provides stable hosted
# observation without continuously polling the native GUI thread.
POLL_SECONDS = 0.005
# Purpose: Give synthetic local images enough detail to exercise fitting and pan.
# Type: tuple[int, int]. Units: pixels. Scope: every generated local fixture.
# Consumers: write_fixture. Rationale: 1600 by 1200 forces scaled Lightbox display.
IMAGE_SIZE = (1600, 1200)
# Purpose: Bound the native matte toggle fixture independently from the grid workload.
# Type: tuple[int, int]. Units: pixels. Scope: one Lightbox crop interaction.
# Consumers: write_crop_fixture. Rationale: 320 by 240 proves source clipping
# without adding meaningful decode or provider memory to the interaction run.
CROP_FIXTURE_SIZE = (320, 240)
# Purpose: Give the crop fixture clear opposed black matte bands.
# Type: tuple[int, int, int, int]. Units: pixels. Scope: crop proof image.
# Consumers: write_crop_fixture. Rationale: 32 px side and 24 px top/bottom
# margins exceed the shared detector's minimum band at 256 px sampling.
CROP_FIXTURE_CONTENT_BOUNDS = (32, 24, 287, 215)
# Purpose: Scale the Photos host window to exercise a visible geometry change.
# Type: tuple[int, int]. Units: logical pixels. Scope: one resize interaction.
# Consumers: run_interactions. Rationale: A 25 percent width increase changes
# both grid and lightbox geometry while keeping the hosted window practical.
RESIZED_VIEW = (1125, 680)
# Purpose: Start every trial at the same compact desktop dimensions.
# Type: tuple[int, int]. Units: logical pixels. Scope: initial Qt Photos window.
# Consumers: run_interactions. Rationale: The dimensions keep a visible grid and
# leave enough area to drag and display the detail scene.
INITIAL_VIEW = (900, 680)
# Purpose: Request a repeatable vertical Qt Quick wheel movement.
# Type: int. Units: angle-delta units. Scope: one grid wheel event.
# Consumers: run_interactions. Rationale: 960 is eight standard 120-unit steps.
WHEEL_ANGLE_DELTA = 960
# Purpose: Place the grid drag endpoints within the visible native viewport.
# Type: tuple[float, float]. Units: viewport-height fractions. Scope: one drag.
# Consumers: run_interactions. Rationale: Opposing quarter and three-quarter
# positions provide a long vertical drag while staying inside the widget.
GRID_DRAG_FRACTIONS = (0.75, 0.25)
# Purpose: Move toward the zoomed Lightbox content extent to observe pan state.
# Type: tuple[int, int]. Units: logical pixels. Scope: one pan drag.
# Consumers: run_interactions. Rationale: Negative pointer movement increases
# Flickable content offsets from their initial zero boundary.
LIGHTBOX_PAN_OFFSET = (-120, -80)
# Purpose: Keep synthetic Lightbox reveal points clear of toolbar controls.
# Type: int. Units: logical pixels. Scope: hosted pointer reveal proof.
# Consumers: run_interactions. Rationale: 24 px leaves an interior hit target.
LIGHTBOX_POINTER_SAFE_INSET = 24
# Purpose: Bound the synthetic raster artwork independently from photo rows.
# Type: tuple[int, int]. Units: pixels. Scope: deterministic local fixture.
# Consumers: write_fixture. Rationale: An inset frame and crossed center shapes
# provide visible landmarks during fitting, zoom and pan.
FIXTURE_FRAME_BOUNDS = (60, 60, 1540, 1140)
# Purpose: Place the synthetic fixture's centered ellipse.
# Type: tuple[int, int, int, int]. Units: pixels. Scope: local fixture artwork.
# Consumers: write_fixture. Rationale: It gives a stable zoom and pan landmark.
FIXTURE_CIRCLE_BOUNDS = (560, 360, 1040, 840)
# Purpose: Define the first corner of the synthetic diagonal.
# Type: tuple[int, int]. Units: pixels. Scope: local fixture artwork.
# Consumers: write_fixture. Rationale: Together with its endpoint, it crosses the frame.
FIXTURE_DIAGONAL_START = (80, 1060)
# Purpose: Define the opposing corner of the synthetic diagonal.
# Type: tuple[int, int]. Units: pixels. Scope: local fixture artwork.
# Consumers: write_fixture. Rationale: Together with its start, it crosses the frame.
FIXTURE_DIAGONAL_END = (1520, 140)
# Purpose: Keep the synthetic frame visible after image fitting.
# Type: int. Units: pixels. Scope: local fixture frame border.
# Consumers: write_fixture. Rationale: A visible outline remains after scaling.
FIXTURE_FRAME_WIDTH = 18
# Purpose: Keep the synthetic diagonal visible after image fitting.
# Type: int. Units: pixels. Scope: local fixture diagonal stroke.
# Consumers: write_fixture. Rationale: A visible stroke remains after scaling.
FIXTURE_DIAGONAL_WIDTH = 24


def wait_until(
    app: QApplication, predicate: Callable[[], bool], label: str,
) -> None:
    """Pump Qt until a local scene condition holds or the action times out.

    Args:
        app (QApplication): Native event-loop owner.
        predicate (Callable[[], bool]): Observable scene readiness condition.
        label (str): Short action name used in failure evidence.
    Returns:
        None: The expected scene state became observable.
    Raises:
        RuntimeError: The action did not settle within its fixed deadline.
    """
    deadline = time.monotonic() + ACTION_DEADLINE_SECONDS
    while time.monotonic() < deadline:
        app.processEvents()
        if predicate():
            return
        time.sleep(POLL_SECONDS)
    raise RuntimeError(f"Qt interaction did not settle: {label}.")


InteractionState = (
    float | tuple[float, float] | tuple[int, int] | tuple[float, float, int] | bool
)


def measure_action(
    action: Callable[[], None], settle: Callable[[], bool], label: str,
    before: InteractionState, target: str,
    observe: Callable[[], InteractionState],
) -> float:
    """Return time until an action's named Qt scene condition is observed.

    Args:
        action (Callable[[], None]): Native UI input or window operation.
        settle (Callable[[], bool]): Condition defining the endpoint.
        label (str): Action name retained in failure evidence.
        before (InteractionState): State observed immediately before the action.
        target (str): Human-readable expected state transition.
        observe (Callable[[], InteractionState]): Latest state at timeout.
    Returns:
        float: Action-to-state time in milliseconds.
    Raises:
        RuntimeError: The expected post-action scene state did not occur.
    """
    started = time.perf_counter()
    action()
    app = QApplication.instance()
    if app is None:
        raise RuntimeError("The native Qt application is unavailable.")
    deadline = time.monotonic() + ACTION_DEADLINE_SECONDS
    while time.monotonic() < deadline:
        app.processEvents()
        if settle():
            return (time.perf_counter() - started) * 1000
        time.sleep(POLL_SECONDS)
    raise RuntimeError(
        f"Qt interaction did not settle: {label}; before={before!r}; "
        f"target={target}; last={observe()!r}."
    )


def widget_point(item: QQuickItem, x: float, y: float, root: QQuickItem) -> QPoint:
    """Map a nested Quick item point into QQuickWidget-local event coordinates.

    Args:
        item (QQuickItem): Item whose local coordinate is being targeted.
        x (float): Local horizontal point in logical pixels.
        y (float): Local vertical point in logical pixels.
        root (QQuickItem): Scene root matching the mounted QQuickWidget surface.
    Returns:
        QPoint: Rounded widget-local point for QtTest event delivery.
    """
    mapped = item.mapToItem(root, x, y)
    return QPoint(round(mapped.x()), round(mapped.y()))


def write_fixture(path: Path) -> None:
    """Write a deterministic raster that exercises scaled tile and detail reads.

    Args:
        path (Path): Disposable local fixture path.
    Returns:
        None: One RGB PNG is shared by all logical photo identities.
    """
    image = Image.new("RGB", IMAGE_SIZE, "#263746")
    draw = ImageDraw.Draw(image)
    draw.rectangle(
        FIXTURE_FRAME_BOUNDS, outline="#e7b75a", width=FIXTURE_FRAME_WIDTH,
    )
    draw.line(
        (FIXTURE_DIAGONAL_START, FIXTURE_DIAGONAL_END),
        fill="#f4eee2", width=FIXTURE_DIAGONAL_WIDTH,
    )
    draw.ellipse(FIXTURE_CIRCLE_BOUNDS, fill="#4c9b84")
    image.save(path, format="PNG", optimize=False)


def write_crop_fixture(path: Path) -> None:
    """Write one bounded raster with a confirmed opposed black matte.

    Args:
        path (Path): Disposable detail fixture path.
    Returns:
        None: One PNG preserves the complete matte and interior content.
    """
    image = Image.new("RGB", CROP_FIXTURE_SIZE, "#000000")
    draw = ImageDraw.Draw(image)
    draw.rectangle(CROP_FIXTURE_CONTENT_BOUNDS, fill="#f0f0f0")
    draw.ellipse((92, 56, 228, 184), fill="#e7b75a")
    image.save(path, format="PNG", optimize=False)


def run_interactions(output: Path, source_sha: str) -> dict[str, object]:
    """Exercise real Qt Photos grid and Lightbox with local synthetic media.

    Args:
        output (Path): JSON destination used to locate the temporary profile.
        source_sha (str): Exact hosted candidate source identity.
    Returns:
        dict[str, object]: Raw action timings, resources and honest scope labels.
    Raises:
        RuntimeError: Native scene, input delivery or expected state failed.
    """
    app = QApplication([])
    app.setApplicationName("PHP Gallery issue 445 Qt interaction benchmark")
    apply_qt_theme(app)
    profile_context = tempfile.TemporaryDirectory(
        prefix="issue-445-qt-photos-", dir=output.parent,
    )
    workspace = Path(profile_context.name)
    fixture_path = workspace / "synthetic-local-photo.png"
    write_fixture(fixture_path)
    profile = workspace / "profile"
    profile.mkdir(parents=True, exist_ok=True)
    viewport = QtPhotos(profile, "")
    viewport.resize(*INITIAL_VIEW)
    viewport.show()
    window = viewport.quick.quickWindow()
    frame_count = [0]
    window.afterRendering.connect(lambda: frame_count.__setitem__(0, frame_count[0] + 1))
    grid = viewport.quick.rootObject().findChild(QQuickItem, "photosGrid")
    scrollbar = viewport.quick.rootObject().findChild(QQuickItem, "photosGridScrollbar")
    scrollbar_thumb = viewport.quick.rootObject().findChild(
        QQuickItem, "photosGridScrollbarThumb",
    )
    lightbox = viewport.quick.rootObject().findChild(QQuickItem, "photosLightbox")
    detail_frame = viewport.quick.rootObject().findChild(QQuickItem, "photosLightboxFrame")
    image_item = viewport.quick.rootObject().findChild(QQuickItem, "photosLightboxImage")
    if (grid is None or scrollbar is None or scrollbar_thumb is None
            or lightbox is None or detail_frame is None or image_item is None):
        viewport.shutdown()
        viewport.close()
        raise RuntimeError("Qt Photos scene interaction objects are unavailable.")

    def grid_content_y() -> float:
        """Read the real GridView offset as a concrete numeric sample.

        Returns:
            float: Current logical vertical content offset.
        """
        return float(grid.property("contentY"))

    try:
        viewport.open_files((fixture_path,) * PHOTO_COUNT)
        wait_until(
            app,
            lambda: bool(viewport._tiles) and frame_count[0] > 0
            and scrollbar.property("visible")
            and viewport.quick.grabFramebuffer().isNull() is False,
            "Photos grid ready",
        )
        timings: dict[str, list[float]] = {}
        resource_samples = [psutil.Process().memory_info().rss]
        footprint = [viewport.quick.width(), viewport.quick.height()]
        center = QPoint(viewport.quick.width() // 2, viewport.quick.height() // 2)
        before_y = grid_content_y()
        wheel = QWheelEvent(
            QPointF(center), QPointF(viewport.quick.mapToGlobal(center)), QPoint(),
            QPoint(0, -WHEEL_ANGLE_DELTA), Qt.MouseButton.NoButton,
            Qt.KeyboardModifier.NoModifier,
            Qt.ScrollPhase.ScrollUpdate, False,
        )
        timings["grid_wheel_to_content_y_ms"] = [measure_action(
            lambda: QApplication.sendEvent(viewport.quick, wheel),
            lambda: grid_content_y() != before_y,
            "grid wheel", before_y, "GridView contentY changes",
            grid_content_y,
        )]
        resource_samples.append(psutil.Process().memory_info().rss)

        track_click = widget_point(
            scrollbar, scrollbar.width() / 2, scrollbar.height() * 0.85,
            viewport.quick.rootObject(),
        )
        before_y = grid_content_y()
        timings["grid_scrollbar_track_jump_to_content_y_ms"] = [measure_action(
            lambda: QTest.mouseClick(
                viewport.quick, Qt.MouseButton.LeftButton, pos=track_click,
            ),
            lambda: grid_content_y() != before_y,
            "scrollbar track jump", before_y, "GridView contentY changes",
            grid_content_y,
        )]
        resource_samples.append(psutil.Process().memory_info().rss)

        scrollbar_drag_start = widget_point(
            scrollbar, scrollbar.width() / 2,
            scrollbar_thumb.y() + scrollbar_thumb.height() / 2,
            viewport.quick.rootObject(),
        )
        scrollbar_drag_end = widget_point(
            scrollbar, scrollbar.width() / 2, scrollbar.height() * 0.20,
            viewport.quick.rootObject(),
        )
        before_y = grid_content_y()

        def drag_scrollbar_thumb() -> None:
            """Drag the real Qt Quick scrollbar thumb toward the grid start.

            Returns:
                None: Pointer events exercise the accessible production overlay.
            """
            QTest.mousePress(
                viewport.quick, Qt.MouseButton.LeftButton, pos=scrollbar_drag_start,
            )
            move = QMouseEvent(
                QEvent.Type.MouseMove, QPointF(scrollbar_drag_end),
                QPointF(viewport.quick.mapToGlobal(scrollbar_drag_end)),
                Qt.MouseButton.NoButton, Qt.MouseButton.LeftButton,
                Qt.KeyboardModifier.NoModifier,
            )
            QApplication.sendEvent(viewport.quick, move)
            QTest.mouseRelease(
                viewport.quick, Qt.MouseButton.LeftButton, pos=scrollbar_drag_end,
            )

        timings["grid_scrollbar_thumb_drag_to_content_y_ms"] = [measure_action(
            drag_scrollbar_thumb,
            lambda: grid_content_y() != before_y,
            "scrollbar thumb drag", before_y, "GridView contentY changes",
            grid_content_y,
        )]
        resource_samples.append(psutil.Process().memory_info().rss)

        drag_start = QPoint(
            viewport.quick.width() // 2,
            round(viewport.quick.height() * GRID_DRAG_FRACTIONS[0]),
        )
        drag_end = QPoint(
            viewport.quick.width() // 2,
            round(viewport.quick.height() * GRID_DRAG_FRACTIONS[1]),
        )
        before_y = grid_content_y()

        def drag_grid() -> None:
            """Drag the production Qt Quick grid viewport upward once.

            Returns:
                None: Mouse input is delivered to the mounted QQuickWidget.
            """
            QTest.mousePress(viewport.quick, Qt.MouseButton.LeftButton, pos=drag_start)
            move = QMouseEvent(
                QEvent.Type.MouseMove, QPointF(drag_end),
                QPointF(viewport.quick.mapToGlobal(drag_end)), Qt.MouseButton.NoButton,
                Qt.MouseButton.LeftButton, Qt.KeyboardModifier.NoModifier,
            )
            QApplication.sendEvent(viewport.quick, move)
            QTest.mouseRelease(viewport.quick, Qt.MouseButton.LeftButton, pos=drag_end)

        timings["grid_drag_to_content_y_ms"] = [measure_action(
            drag_grid, lambda: grid_content_y() != before_y,
            "grid drag", before_y, "GridView contentY changes",
            grid_content_y,
        )]
        resource_samples.append(psutil.Process().memory_info().rss)
        scroll_position = grid_content_y()
        if scroll_position <= 0:
            raise RuntimeError("Grid wheel and drag did not move the native viewport.")

        lightbox_started = time.perf_counter()
        previous_frame_count = frame_count[0]
        viewport.openPhoto(0)
        wait_until(
            app,
            lambda: bool(viewport._detail_keys),
            "Lightbox decoded detail result",
        )
        detail_data_ready_ms = (time.perf_counter() - lightbox_started) * 1000
        wait_until(app, lambda: viewport.detailUrl != "", "Lightbox texture publication")
        detail_published_ms = (time.perf_counter() - lightbox_started) * 1000
        wait_until(
            app,
            lambda: image_item.property("frameReady") is True
            and frame_count[0] > previous_frame_count,
            "Lightbox rendered scene frame",
        )
        timings["lightbox_detail_data_ready_ms"] = [detail_data_ready_ms]
        timings["lightbox_resource_published_ms"] = [detail_published_ms]
        timings["lightbox_rendered_frame_proxy_ms"] = [
            (time.perf_counter() - lightbox_started) * 1000,
        ]
        resource_samples.append(psutil.Process().memory_info().rss)
        before_zoom = float(lightbox.property("zoom"))
        started = time.perf_counter()
        QTest.keyClick(viewport.quick, Qt.Key.Key_Plus)
        wait_until(app, lambda: float(lightbox.property("zoom")) > before_zoom, "Lightbox zoom")
        wait_until(
            app,
            lambda: image_item.width() > lightbox.width()
            or image_item.height() > lightbox.height(),
            "Lightbox zoomed content bounds",
        )
        timings["lightbox_zoom_key_to_zoom_state_ms"] = [
            (time.perf_counter() - started) * 1000,
        ]
        resource_samples.append(psutil.Process().memory_info().rss)

        pan_start = QPoint(viewport.quick.width() // 2, viewport.quick.height() // 2)
        pan_dx = LIGHTBOX_PAN_OFFSET[0] if image_item.width() > lightbox.width() else 0
        pan_dy = LIGHTBOX_PAN_OFFSET[1] if image_item.height() > lightbox.height() else 0
        pan_end = QPoint(
            pan_start.x() + pan_dx,
            pan_start.y() + pan_dy,
        )
        before_pan = (float(lightbox.property("contentX")), float(lightbox.property("contentY")))

        def pan_lightbox() -> None:
            """Drag the zoomed Lightbox image to move its Flickable content.

            Returns:
                None: Mouse input targets the actual retained Lightbox surface.
            """
            QTest.mousePress(viewport.quick, Qt.MouseButton.LeftButton, pos=pan_start)
            distance = pan_end - pan_start
            for step in range(1, 5):
                point = QPoint(
                    pan_start.x() + round(distance.x() * step / 4),
                    pan_start.y() + round(distance.y() * step / 4),
                )
                move = QMouseEvent(
                    QEvent.Type.MouseMove, QPointF(point),
                    QPointF(viewport.quick.mapToGlobal(point)),
                    Qt.MouseButton.NoButton, Qt.MouseButton.LeftButton,
                    Qt.KeyboardModifier.NoModifier,
                )
                QApplication.sendEvent(viewport.quick, move)
                QApplication.processEvents()
            QTest.mouseRelease(viewport.quick, Qt.MouseButton.LeftButton, pos=pan_end)

        timings["lightbox_pan_drag_to_content_state_ms"] = [measure_action(
            pan_lightbox,
            lambda: (float(lightbox.property("contentX")), float(lightbox.property("contentY")))
            != before_pan,
            "Lightbox pan", before_pan, "Flickable content position changes",
            lambda: (float(lightbox.property("contentX")), float(lightbox.property("contentY"))),
        )]
        resource_samples.append(psutil.Process().memory_info().rss)

        before_size = (lightbox.width(), lightbox.height())
        before_frames = frame_count[0]
        timings["lightbox_resize_to_scene_frame_proxy_ms"] = [measure_action(
            lambda: viewport.resize(*RESIZED_VIEW),
            lambda: (lightbox.width(), lightbox.height()) != before_size
            and frame_count[0] > before_frames,
            "Lightbox resize", before_size,
            "Lightbox geometry changes and afterRendering advances",
            lambda: (lightbox.width(), lightbox.height(), frame_count[0]),
        )]
        resource_samples.append(psutil.Process().memory_info().rss)
        resized_footprint = [viewport.quick.width(), viewport.quick.height()]

        fullscreen_before = viewport._fullscreen
        timings["lightbox_fullscreen_action_to_qt_state_ms"] = [measure_action(
            viewport.toggle_fullscreen,
            lambda: viewport._fullscreen is not fullscreen_before,
            "Lightbox fullscreen", fullscreen_before,
            "QtPhotos fullscreen state toggles",
            lambda: viewport._fullscreen,
        )]
        resource_samples.append(psutil.Process().memory_info().rss)
        wait_until(
            app,
            lambda: not viewport._detail_controls.isVisible(),
            "fullscreen toolbar idle hide",
        )
        controls_hidden_after_idle = not viewport._detail_controls.isVisible()
        reveal_points = (
            QPoint(
                max(0, viewport.quick.width() - LIGHTBOX_POINTER_SAFE_INSET),
                max(0, viewport.quick.height() - LIGHTBOX_POINTER_SAFE_INSET),
            ),
            QPoint(
                viewport.quick.width() // 3,
                viewport.quick.height() // 3,
            ),
        )
        for point in reveal_points:
            QTest.mouseMove(viewport.quick, point)
            app.processEvents()
        wait_until(
            app,
            lambda: viewport._detail_controls.isVisible(),
            "fullscreen toolbar pointer reveal",
        )
        controls_revealed_on_pointer = viewport._detail_controls.isVisible()
        viewport.toggle_fullscreen()
        wait_until(app, lambda: not viewport._fullscreen, "restore window after fullscreen")
        if viewport.quick.grabFramebuffer().isNull():
            raise RuntimeError("Qt Photos did not retain a framebuffer after interactions.")

        viewport.dismissPhoto()
        crop_path = workspace / "black-matte-photo.png"
        write_crop_fixture(crop_path)
        crop_row = PhotoRow(PHOTO_COUNT + 1, "Matte crop proof", crop_path)
        viewport.model.replace((crop_row,))
        viewport.openPhoto(0)
        wait_until(
            app,
            lambda: viewport.detailHasContentCrop and viewport.detailUrl != "",
            "bounded content crop decode",
        )
        wait_until(
            app,
            lambda: image_item.property("frameReady") is True,
            "bounded content crop frame",
        )
        keys_before_toggle = viewport._detail_keys
        detail_url = viewport.detailUrl
        full_width = float(image_item.property("implicitWidth"))
        crop_width = detail_frame.width()
        if (not viewport.contentCropEnabled or not detail_frame.clip()
                or crop_width >= image_item.width() or image_item.x() >= 0):
            raise RuntimeError("The confirmed matte did not clip the retained texture by default.")
        viewport._detail_fit_button.click()
        wait_until(
            app,
            lambda: not viewport.contentCropEnabled
            and not detail_frame.clip() and detail_frame.width() == image_item.width()
            and image_item.x() == 0,
            "original frame restoration",
        )
        viewport._detail_fit_button.click()
        wait_until(
            app,
            lambda: viewport.contentCropEnabled and detail_frame.clip()
            and detail_frame.width() == crop_width and image_item.x() < 0,
            "content crop restoration",
        )
        if (viewport._detail_keys != keys_before_toggle or viewport.detailUrl != detail_url
                or float(image_item.property("implicitWidth")) != full_width):
            raise RuntimeError("The crop toggle replaced the retained source image.")
        crop_observation = {
            "normalized_crop": [
                viewport.detailCropRect.x(), viewport.detailCropRect.y(),
                viewport.detailCropRect.width(), viewport.detailCropRect.height(),
            ],
            "full_source_width": full_width,
            "cropped_view_width": crop_width,
            "retained_source_unchanged": viewport._detail_keys == keys_before_toggle,
        }
        return {
            "schema_version": 1,
            "status": "PASS",
            "stage": "qt-photos-interactions",
            "source_sha": source_sha,
            "dependency_versions": dependency_versions(),
            "scenario": "photos-grid-lightbox-native-inputs",
            "cross_toolkit_paired": False,
            "input_delivery": "QtTest/QApplication event injection; physical pointer and keyboard input are unobserved.",
            "fixture": {
                "photo_rows": PHOTO_COUNT,
                "unique_media_files": 1,
                "source": "deterministic local synthetic PNG",
                "remote_services": False,
                "qt_scrollbar_present": True,
            },
            "viewports": {
                "initial_quick_widget": footprint,
                "resized_quick_widget": resized_footprint,
                "host_window_requested": list(RESIZED_VIEW),
            },
            "timings_ms": timings,
            "measurement_definitions": {
                "grid_wheel_to_content_y_ms": "Input dispatch to native GridView contentY change.",
                "grid_scrollbar_track_jump_to_content_y_ms": "Track click to native GridView contentY change.",
                "grid_scrollbar_thumb_drag_to_content_y_ms": "Thumb drag to native GridView contentY change.",
                "grid_drag_to_content_y_ms": "Mouse drag dispatch to native GridView contentY change.",
                "lightbox_detail_data_ready_ms": "Detail PhotoFrame accepted by QtPhotos.",
                "lightbox_resource_published_ms": "Provider URL exposed to the Qt Quick image item.",
                "lightbox_rendered_frame_proxy_ms": "Image Ready plus a subsequent afterRendering signal.",
                "lightbox_zoom_key_to_zoom_state_ms": "Plus key dispatch to QML zoom property change.",
                "lightbox_pan_drag_to_content_state_ms": "Mouse drag dispatch to Flickable content position change.",
                "lightbox_resize_to_scene_frame_proxy_ms": "Window resize to changed Lightbox geometry and subsequent scene frame.",
                "lightbox_fullscreen_action_to_qt_state_ms": "Fullscreen toggle to QtPhotos fullscreen state change.",
                "lightbox_fullscreen_controls": "Hosted Qt event injection confirms idle hide and pointer reveal; not physical input.",
            },
            "p99_limitation": "One observation per interaction; this lane cannot estimate p99.",
            "observed_state": {
                "grid_content_y_after_scroll_and_drag": scroll_position,
                "lightbox_zoom_after_key": float(lightbox.property("zoom")),
                "lightbox_content_x_after_pan": float(lightbox.property("contentX")),
                "lightbox_content_y_after_pan": float(lightbox.property("contentY")),
                "lightbox_fullscreen_restored": not viewport._fullscreen,
                "lightbox_fullscreen_controls_idle_hidden": controls_hidden_after_idle,
                "lightbox_fullscreen_controls_pointer_revealed": controls_revealed_on_pointer,
                "lightbox_content_crop": crop_observation,
            },
            "resources": {
                "rss_samples_bytes": resource_samples,
                "rss_peak_sampled_bytes": max(resource_samples),
            },
            "frame_proxy": "QQuickWindow.afterRendering; scene graph completion, not physical display presentation.",
            "comparison_scope": [
                "Qt overlay scrollbar uses the existing scene and does not change GridView bounds, cells, or cache.",
                "Tk #399 and Qt scrollbar drag endpoints both observe vertical catalogue movement; harnesses remain separately scoped.",
                "QtTest injects events through the real widget and Quick scene event path, not hardware input.",
                "Qt Lightbox zoom, pan, resize and fullscreen use native scene inputs and Qt state endpoints.",
                "One local synthetic raster reused across identities isolates UI interactions from media I/O.",
                "RSS samples are sparse observations, not continuous process peak memory.",
            ],
        }
    finally:
        viewport.shutdown()
        viewport.close()
        profile_context.cleanup()


def main() -> int:
    """Admit the exact hosted Windows interaction workload and retain reports.

    Returns:
        int: Zero when the local scene completed every measured interaction.
    """
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--source-sha")
    parser.add_argument("--quick-host", choices=("embedded", "window"))
    args = parser.parse_args()
    expected_sha = (
        os.environ.get("PHP_GALLERY_ISSUE_445_CANDIDATE")
        or os.environ.get("GITHUB_SHA", "")
    )
    actual_head = subprocess.run(
        ["git", "rev-parse", "HEAD"], check=False, capture_output=True,
        text=True, cwd=PROJECT_ROOT,
    )
    source_sha = args.source_sha or expected_sha
    if (os.environ.get("GITHUB_ACTIONS") != "true" or sys.platform != "win32"
            or not source_sha or source_sha != expected_sha
            or actual_head.returncode != 0 or actual_head.stdout.strip() != source_sha
            or not args.output.is_absolute()):
        parser.error("Run on hosted Windows with the exact prepared candidate SHA.")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    try:
        if args.quick_host is not None:
            child_app = QApplication([])
            report = sample_quick_host(child_app, args.quick_host, wait_until)
            report.update({"status": "PASS", "source_sha": source_sha})
        else:
            report = run_interactions(args.output, source_sha)
            report["quick_host_comparison"] = compare_quick_hosts(
                Path(__file__).resolve(), args.output, source_sha,
            )
        report["host"] = {
            "platform": platform.platform(), "python": sys.version,
            "qt_version": qVersion(),
        }
        report["dependency_versions"] = dependency_versions()
    except Exception as error:
        report = {
            "schema_version": 1, "status": "FAIL",
            "stage": "qt-photos-interactions", "source_sha": source_sha,
            "dependency_versions": dependency_versions(),
            "failure": f"{type(error).__name__}: {error}"[:1000],
        }
    args.output.write_text(json.dumps(report, indent=2, allow_nan=False), encoding="utf-8")
    if args.quick_host is not None:
        return int(report.get("status") != "PASS")
    with args.output.with_suffix(".csv").open("w", newline="", encoding="utf-8") as stream:
        writer = csv.writer(stream)
        writer.writerow(("action", "sample_index", "latency_ms", "dependency_versions"))
        for action, samples in report.get("timings_ms", {}).items():
            for index, value in enumerate(samples):
                writer.writerow((
                    action, index, value,
                    json.dumps(report.get("dependency_versions", {}), sort_keys=True),
                ))
    return int(report.get("status") != "PASS")


if __name__ == "__main__":
    raise SystemExit(main())
