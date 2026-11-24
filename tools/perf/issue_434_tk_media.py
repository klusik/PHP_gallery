# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: tools/perf/issue_434_tk_media.py
# Module Type: Windows Native UI Workload Driver
# Purpose: Mount real Photos and Lightbox workloads for issue 434.
# Responsibilities:
#   - Reuse the issue 361 synthetic media session and real Photos controller.
#   - Exercise Photos and Lightbox interactions across the fixed P0 scenarios.
#   - Keep media fixtures deterministic and isolated from user profiles.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Mount deterministic issue 434 media workloads in an existing Tk root."""

import hashlib
import time
import tkinter as tk
from io import BytesIO
from pathlib import Path
from typing import Callable, Literal, TypeAlias

import issue_361_tk_workloads as common
from PIL import Image, ImageTk

CanvasItemId: TypeAlias = int
CanvasItemTarget: TypeAlias = int | str
CanvasCoordinate: TypeAlias = int | float
CanvasTag: TypeAlias = str | tuple[str, ...]
CanvasOption: TypeAlias = (
    str | int | float | bool | None | tk.Misc | tk.Image | tk.PhotoImage
    | ImageTk.PhotoImage | tuple[str, ...] | list[str]
)
CanvasOptions: TypeAlias = dict[str, CanvasOption]
CanvasItemOptionValue: TypeAlias = tuple[str, str, str, str, str]
CanvasItemConfigureResult: TypeAlias = (
    str | CanvasItemOptionValue | dict[str, CanvasItemOptionValue]
)
CanvasCoordinates: TypeAlias = list[float]
RasterQualityValue: TypeAlias = Literal["preview", "final"]
RasterAttachmentValue: TypeAlias = Literal["create_image", "itemconfigure"]
PhotoImageHandle: TypeAlias = ImageTk.PhotoImage | tk.PhotoImage


# Purpose: Bound catalogues to the largest P0 Photos scenario.
# Type: int. Units: photos. Scope: one workload mount.
# Consumers: mount and fixture preparation.
# Rationale: 5000 is the largest explicitly requested catalogue extent.
MAX_PHOTO_COUNT = 5000
# Purpose: Require a nonempty catalog for every mounted Photos session.
# Type: int. Units: photos. Scope: one workload mount.
# Consumers: MediaWorkload.__init__.
# Rationale: the production viewer's first-photo interaction requires one item.
MIN_PHOTO_COUNT = 1
# Purpose: Keep synthetic window geometry valid before Tk reports mapped dimensions.
# Type: int. Units: pixels. Scope: captured client geometry.
# Consumers: MediaWorkload._step_lightbox.
# Rationale: Tk geometry dimensions must remain positive even before mapping settles.
MIN_CLIENT_SIZE_PIXELS = 1
# Purpose: Bound the scheduled catalogue priming poll cadence.
# Type: int. Units: milliseconds. Scope: one mounted media workload.
# Consumers: MediaWorkload._prime_catalogue.
# Rationale: yield to Tk and the production media worker between page requests.
CATALOGUE_POLL_MS = 20
# Purpose: Use bounded repeatable viewport positions during Photos interaction.
# Type: tuple[float, ...]. Units: normalized canvas fractions. Scope: one workload.
# Consumers: MediaWorkload.step.
# Rationale: exercise top, middle and bottom ranges without random input.
SCROLL_POSITIONS = (0.0, 0.5, 1.0, 0.25, 0.75)
# Purpose: Cycle a fixed short input pattern during Lightbox rapid input.
# Type: tuple[float, ...]. Units: zoom factors. Scope: one interaction step.
# Consumers: MediaWorkload._step_lightbox.
# Rationale: alternating zoom directions exposes repeated render scheduling.
RAPID_ZOOM_FACTORS = (1.08, 0.92, 1.12, 0.89)
# Purpose: Locate the production Lightbox zoom action in its fixed toolbar.
# Type: int. Units: button positions. Scope: mounted Lightbox controls.
# Consumers: MediaWorkload._step_lightbox.
# Rationale: position two is the existing zoom control in the #361 harness contract.
LIGHTBOX_ZOOM_BUTTON_INDEX = 2
# Purpose: Set the existing #361 lightbox resize perturbation.
# Type: int. Units: pixels. Scope: one regular Lightbox step.
# Consumers: MediaWorkload._step_lightbox.
# Rationale: 24 pixels triggers responsive layout without changing display class.
LIGHTBOX_RESIZE_DELTA_PIXELS = 24
# Purpose: Position synthetic pan events inside the mounted Lightbox canvas.
# Type: tuple[int, int]. Units: canvas pixels. Scope: one pan gesture.
# Consumers: MediaWorkload._step_lightbox.
# Rationale: fixed interior coordinates exercise the actual drag binding.
PAN_START = (20, 20)
PAN_END = (26, 24)
RAPID_PAN_END = (28, 25)
# Purpose: Set the four source rasters requested by the Lightbox P0 workload.
# Type: tuple[tuple[int, int], ...]. Units: source pixels. Scope: Lightbox runs.
# Consumers: mount and detail fixture generation.
# Rationale: cover 1080p, 1440p, ultrawide and UHD 4K image data explicitly.
LIGHTBOX_SOURCE_RASTERS = (
    (1920, 1080), (2560, 1440), (3440, 1440), (3840, 2160),
)
# Purpose: Identify scenarios that must present a real Lightbox detail image.
# Type: frozenset[str]. Units: scenario names. Scope: one workload mount.
# Consumers: fixture preparation, readiness and interactions.
# Rationale: only these P0 workloads include detail-image behavior.
LIGHTBOX_SCENARIOS = frozenset({
    "lightbox", "lightbox-rapid", "import-gui", "long-session",
})
# Purpose: Locate the production Lightbox fullscreen action in its toolbar.
# Type: int. Units: button positions. Scope: mounted Lightbox controls.
# Consumers: MediaWorkload._step_lightbox.
# Rationale: position three is the existing fullscreen control in #361.
LIGHTBOX_FULLSCREEN_BUTTON_INDEX = 3
# Purpose: Preserve the existing 4K source as the fixture resize precursor.
# Type: tuple[int, int]. Units: pixels. Scope: detail fixture generation.
# Consumers: MediaWorkload._prepare_media_fixture.
# Rationale: common fixture generation creates this bounded textured source.
SOURCE_FIXTURE_SIZE = (3840, 2160)
# Purpose: Fix deterministic JPEG encoding for raster-specific detail fixtures.
# Type: int. Units: quality points. Scope: one synthetic detail image.
# Consumers: MediaWorkload._prepare_media_fixture.
# Rationale: match the shared fixture's established quality setting.
DETAIL_JPEG_QUALITY = 82
# Purpose: Define the rapid production wheel input burst.
# Type: tuple[int, ...]. Units: Tk wheel delta units. Scope: one rapid Lightbox step.
# Consumers: MediaWorkload._step_lightbox.
# Rationale: signed deltas exercise both zoom directions and a larger wheel packet.
RAPID_WHEEL_DELTAS = (120, -120, 240, -120)
# Purpose: Alternate detail interaction and catalogue scrolling in long sessions.
# Type: int. Units: interactions. Scope: long-session media workload.
# Consumers: MediaWorkload.step.
# Rationale: equal alternation samples both settled detail and Photos viewport work.
LONG_SESSION_ACTION_CADENCE = 2
# Purpose: Deliver a bounded alternating scroll input through the Photos binding.
# Type: tuple[int, ...]. Units: wheel delta units. Scope: one Photos interaction.
# Consumers: MediaWorkload._step_photos.
# Rationale: opposite directions exercise the actual native wheel handler.
PHOTOS_WHEEL_DELTAS = (-120, 120)
# Purpose: Require the native Tk Canvas item identifier to be positive.
# Type: int. Units: Canvas item ids. Scope: one successful lightbox publication.
# Consumers: RasterPublicationProbe.on_published.
# Rationale: Tk returns positive item ids; zero and negative ids cannot identify an item.
MIN_CANVAS_ITEM_ID = 1
# Purpose: Keep derived outstanding-request counts nonnegative.
# Type: int. Units: requests. Scope: final workload counters.
# Consumers: RasterPublicationProbe.counters.
# Rationale: an observer mismatch must not emit a negative pending count.
MIN_PENDING_REQUEST_COUNT = 0


class RasterPublicationProbe:
    """Retain successful software-side Lightbox Canvas publication samples."""

    def __init__(self, recorder: common.Recorder) -> None:
        """Initialize request matching and bounded numeric outcome counters.

        Args:
            recorder (common.Recorder): Shared measured-phase timing recorder.

        Returns:
            None: The probe is ready for one isolated Lightbox instance.
        """
        self.recorder = recorder
        self._requests: dict[int, tuple[str, int]] = {}
        self._request_counts = {"preview": 0, "final": 0, "other": 0}
        self._publication_counts = {"preview": 0, "final": 0, "other": 0}
        self._unmatched_publications = 0
        self._invalid_publications = 0
        self._legacy_boundary_missing = 0
        self._hdr_counts = {"attached": 0, "not_attached": 0, "unknown": 0}
        self._attachment_counts = {"create_image": 0, "itemconfigure": 0}

    def on_request(self, request_id: int, quality: str, started_ns: int) -> None:
        """Retain one accepted raster request and its original monotonic time.

        Args:
            request_id (int): Production serial for the admitted request.
            quality (str): Production preview or final raster quality.
            started_ns (int): Monotonic timestamp captured before submission.

        Returns:
            None: The request can be matched to its successful publication.
        """
        normalized_quality = _normalized_raster_quality(quality)
        self._request_counts[normalized_quality] += 1
        # Only the current request can publish; older serials are production-stale.
        self._requests.clear()
        self._requests[request_id] = (normalized_quality, started_ns)

    def on_published(
        self,
        request_id: int,
        quality: RasterQualityValue,
        photo: PhotoImageHandle,
        canvas_item_id: CanvasItemId,
        attachment: RasterAttachmentValue,
        hdr_result: bool | None,
        attached_ns: int,
    ) -> None:
        """Record a current image after Canvas attachment and final positioning.

        Args:
            request_id (int): Published production raster serial.
            quality (RasterQualityValue): Preview or final quality for this result.
            photo (PhotoImageHandle): Tk or Pillow PhotoImage retained by Canvas.
            canvas_item_id (CanvasItemId): Native Canvas item retaining the photo.
            attachment (RasterAttachmentValue): Successful item creation or update.
            hdr_result (bool | None): Optional native HDR tile attach outcome.
            attached_ns (int): Monotonic time after Canvas attachment and final
                positioning, before status-badge removal and HDR follow-up.

        Returns:
            None: A matched successful publication contributes one latency sample.
        """
        normalized_quality = _normalized_raster_quality(quality)
        request = self._requests.pop(request_id, None)
        if (photo is None or type(canvas_item_id) is not int
                or canvas_item_id < MIN_CANVAS_ITEM_ID
                or attachment not in self._attachment_counts):
            self._invalid_publications += 1
            return
        self._publication_counts[normalized_quality] += 1
        self._attachment_counts[attachment] += 1
        hdr_label = (
            "unknown" if hdr_result is None
            else "attached" if hdr_result else "not_attached"
        )
        self._hdr_counts[hdr_label] += 1
        if request is None:
            self._unmatched_publications += 1
            return
        requested_quality, started_ns = request
        metric_quality = (
            normalized_quality
            if normalized_quality == requested_quality else "other"
        )
        self.recorder.add(
            f"lightbox_{metric_quality}_request_to_canvas_publication",
            attached_ns - started_ns,
        )

    def record_legacy_request(self, quality: str) -> None:
        """Count one synchronous B0 render request by Pillow resampling phase.

        Args:
            quality (str): Classified legacy preview, final, or other phase.

        Returns:
            None: The request count is retained for workload counters.
        """
        self._request_counts[_normalized_raster_quality(quality)] += 1

    def record_legacy_publication(
        self, quality: str, started_ns: int, attached_ns: int | None,
        attachment: str | None,
    ) -> None:
        """Record B0 latency through its successful Canvas attachment boundary.

        Args:
            quality (str): Classified legacy preview, final, or other phase.
            started_ns (int): Monotonic timestamp at synchronous render entry.
            attached_ns (int | None): Monotonic timestamp after the photo item's
                final Canvas positioning, or None when no boundary was observed.
            attachment (str | None): Exact Canvas operation when observed.

        Returns:
            None: Successful synchronous publication contributes one sample.
        """
        normalized_quality = _normalized_raster_quality(quality)
        self._publication_counts[normalized_quality] += 1
        if attachment in self._attachment_counts:
            self._attachment_counts[attachment] += 1
        self._hdr_counts["unknown"] += 1
        if attached_ns is None:
            self._legacy_boundary_missing += 1
            return
        self.recorder.add(
            f"lightbox_{normalized_quality}_request_to_canvas_publication",
            attached_ns - started_ns,
        )

    def counters(self) -> dict[str, int]:
        """Return fixed-shape raster outcome counts without image contents.

        Returns:
            dict[str, int]: Request, publication, attachment, HDR and mismatch counts.
        """
        result: dict[str, int] = {}
        for quality in ("preview", "final", "other"):
            result[f"raster_{quality}_requests"] = self._request_counts[quality]
            result[f"raster_{quality}_publications"] = (
                self._publication_counts[quality]
            )
            result[f"raster_{quality}_unpublished_requests"] = max(
                MIN_PENDING_REQUEST_COUNT,
                self._request_counts[quality]
                - self._publication_counts[quality],
            )
        result["raster_publication_unmatched_requests"] = (
            self._unmatched_publications
        )
        result["raster_publication_invalid_callbacks"] = (
            self._invalid_publications
        )
        result["raster_legacy_boundary_missing"] = (
            self._legacy_boundary_missing
        )
        result["raster_create_image_attachments"] = (
            self._attachment_counts["create_image"]
        )
        result["raster_itemconfigure_attachments"] = (
            self._attachment_counts["itemconfigure"]
        )
        result["raster_hdr_attached"] = self._hdr_counts["attached"]
        result["raster_hdr_not_attached"] = self._hdr_counts["not_attached"]
        result["raster_hdr_unknown"] = self._hdr_counts["unknown"]
        return result


def _normalized_raster_quality(quality: str) -> str:
    """Map observer labels to the probe's three fixed quality buckets.

    Args:
        quality (str): Candidate quality label from production or the B0 adapter.

    Returns:
        str: One of ``preview``, ``final`` or ``other``.
    """
    return quality if quality in {"preview", "final"} else "other"


def _legacy_raster_quality(resampling: int | None) -> str:
    """Classify B0's actual Pillow filter instead of treating every filter as preview.

    Args:
        resampling (int | None): Optional Pillow resampling enum passed by B0.

    Returns:
        str: ``preview`` for BILINEAR, ``final`` for LANCZOS/default, else ``other``.
    """
    if resampling is None or resampling == Image.Resampling.LANCZOS:
        return "final"
    if resampling == Image.Resampling.BILINEAR:
        return "preview"
    return "other"


class MediaWorkload:
    """Own one mounted production Photos/Lightbox workload and fixture state."""

    def __init__(
        self,
        root: tk.Tk,
        source_root: Path,
        private_root: Path,
        scenario: str,
        seed: int,
        scale: int,
        raster_size: tuple[int, int] | None,
        recorder: common.Recorder,
    ) -> None:
        """Create the private fixture, mount requested views and schedule priming.

        Args:
            root (tk.Tk): Existing Tk interpreter and event-loop root.
            source_root (Path): Checkout containing the production WinApp source.
            private_root (Path): Private per-child root reserved by the runner.
            scenario (str): One supported issue 434 scenario name.
            seed (int): Nonnegative deterministic image and row seed.
            scale (int): Requested Photos catalogue size, in photos.
            raster_size (tuple[int, int] | None): Optional Lightbox source raster in pixels.
            recorder (common.Recorder): Shared timing and operation recorder.

        Returns:
            None: The workload is mounted and asynchronous catalogue priming begins.

        Raises:
            ValueError: Scenario, extent, seed or raster dimensions are invalid.
        """
        if scenario not in {
            "photos", "lightbox", "lightbox-rapid", "mixed",
            "import-gui", "long-session",
        }:
            raise ValueError("Unsupported issue 434 media scenario.")
        if type(seed) is not int or seed < 0:
            raise ValueError("Fixture seed must be a nonnegative integer.")
        if (type(scale) is not int
                or not MIN_PHOTO_COUNT <= scale <= MAX_PHOTO_COUNT):
            raise ValueError("Photos fixture extent is outside the P0 bound.")
        if raster_size is not None and (
            len(raster_size) != 2
            or any(type(value) is not int or value <= 0 for value in raster_size)
        ):
            raise ValueError("Raster dimensions must be positive integer pixels.")
        if (scenario in {"lightbox", "lightbox-rapid", "import-gui", "long-session"}
                and raster_size not in LIGHTBOX_SOURCE_RASTERS):
            raise ValueError("Lightbox requires one of the four P0 source rasters.")

        self.root = root
        self.source_root = source_root.resolve()
        self.private_root = private_root.resolve()
        self.scenario = scenario
        self.seed = seed
        self.photo_count = min(scale, MAX_PHOTO_COUNT)
        self.raster_size = raster_size
        self.recorder = recorder
        self.interactions = 0
        self._skipped_steps = 0
        self._closed = False
        self._prime_after_id: str | None = None
        self._lightbox_requested = False
        self._first_image_elapsed_ms: float | None = None
        self._first_detail_elapsed_ms: float | None = None
        self._grid_settled_elapsed_ms: float | None = None
        self._settled_elapsed_ms: float | None = None
        self._startup_ready = False
        self._initial_client_size: tuple[int, int] | None = None
        self._restore_lightbox_render: Callable[[], None] | None = None
        self._restore_lightbox_init: Callable[[], None] | None = None
        self._restore_canvas_methods: list[Callable[[], None]] = []
        self._raster_probe: RasterPublicationProbe | None = None
        self._previous_photo_fixtures = common.PHOTO_BODY_FIXTURES.get(seed)
        self._previous_hires_fixtures = common.HIRES_FIXTURE_BODIES.get(seed)
        self.viewer: common.NativeViewerContract | None = None
        self.fixture_info: common.FixtureMediaInfo | None = None
        self.fixture_state: common.GalleryFixtureState | None = None
        self.fixture_sha256: dict[str, str] = {}
        self._prepare_media_fixture()
        self._mounted_ns = time.perf_counter_ns()
        self.viewer, _, self.fixture_info, self.fixture_state = (
            common.build_gallery(
                root, self.source_root, seed,
                photo_count=self.photo_count,
            )
        )
        common.wrap_publication(self.viewer, recorder)
        if self.scenario in LIGHTBOX_SCENARIOS and recorder.enabled:
            self._instrument_lightbox_render()
        self._prime_after_id = root.after(0, self._prime_catalogue)

    def _instrument_lightbox_render(self) -> None:
        """Measure Lightbox publication without timing only async submission.

        Returns:
            None: Per-instance hooks are restored during workload close.
        """
        # The source checkout is placed on sys.path by build_gallery before this import.
        from uploader.gallery_lightbox import PhotoLightbox

        original_render = PhotoLightbox._render_image
        original_init = PhotoLightbox.__init__
        self._raster_probe = RasterPublicationProbe(self.recorder)

        def measured_init(
            lightbox: PhotoLightbox,
            window: tk.Misc,
            text: Callable[[str], str],
            on_move: Callable[[int], None],
            on_close: Callable[[], None],
            content_owner: tk.Misc,
            *,
            can_publish: Callable[[], bool] | None = None,
            on_photo_resolved: Callable[[bool], None] | None = None,
        ) -> None:
            """Install observer hooks after one production Lightbox is initialized.

            Args:
                lightbox (PhotoLightbox): Production overlay being constructed.
                window (tk.Misc): Existing root or toplevel owning the overlay.
                text (Callable[[str], str]): Localized label lookup.
                on_move (Callable[[int], None]): Photo navigation callback.
                on_close (Callable[[], None]): Overlay close callback.
                content_owner (tk.Misc): Root-managed content restored on close.
                can_publish (Callable[[], bool] | None): Optional current-viewer guard.
                on_photo_resolved (Callable[[bool], None] | None): Optional
                    one-shot image outcome callback.

            Returns:
                None: A publication observer or legacy Canvas probe is attached.
            """
            if can_publish is None and on_photo_resolved is None:
                original_init(
                    lightbox, window, text, on_move, on_close, content_owner,
                )
            else:
                original_init(
                    lightbox, window, text, on_move, on_close, content_owner,
                    can_publish=can_publish,
                    on_photo_resolved=on_photo_resolved,
                )
            probe = self._raster_probe
            if probe is None:
                return
            if hasattr(lightbox, "_raster_worker"):
                setattr(lightbox, "_raster_observer", probe)
                return
            canvas = getattr(lightbox, "_canvas", None)
            if canvas is None:
                return
            original_create = canvas.create_image

            def measured_create(
                x: CanvasCoordinate,
                y: CanvasCoordinate,
                **options: CanvasOption,
            ) -> CanvasItemId:
                """Observe successful native Canvas photo item creation.

                Args:
                    x (CanvasCoordinate): Horizontal image-item coordinate.
                    y (CanvasCoordinate): Vertical image-item coordinate.
                    options (dict[str, CanvasOption]): Canvas creation options,
                        including image and tags when present.

                Returns:
                    CanvasItemId: Exact Tk item id returned by the native widget.
                """
                item_id = original_create(x, y, **options)
                tags = options.get("tags")
                tag_names = (tags,) if isinstance(tags, str) else tags
                photo = options.get("image")
                if (isinstance(tag_names, (tuple, list))
                        and "photo" in tag_names
                        and isinstance(photo, (ImageTk.PhotoImage, tk.PhotoImage))):
                    setattr(lightbox, "_issue434_canvas_photo", photo)
                    setattr(lightbox, "_issue434_canvas_photo_item", item_id)
                    setattr(
                        lightbox, "_issue434_canvas_photo_attachment",
                        "create_image",
                    )
                    setattr(
                        lightbox, "_issue434_canvas_photo_attached_ns",
                        time.perf_counter_ns(),
                    )
                return item_id

            def photo_item_matches(target: CanvasItemTarget) -> bool:
                """Recognize the legacy raster item by its retained ID or tag.

                Args:
                    target (CanvasItemTarget): Tk item id or its string tag.

                Returns:
                    bool: True when the target addresses the currently tracked photo.
                """
                return (
                    target == "photo"
                    or target == getattr(lightbox, "_issue434_canvas_photo_item", None)
                )

            def measured_itemconfigure(
                target: CanvasItemTarget,
                cnf: CanvasOptions | str | None = None,
                **options: CanvasOption,
            ) -> CanvasItemConfigureResult:
                """Record Canvas image replacement after the Tk mutation returns.

                Args:
                    target (CanvasItemTarget): Tk item id or string tag to configure.
                    cnf (CanvasOptions | str | None): Optional typed options or
                        Tk option query name.
                    options (dict[str, CanvasOption]): Named Canvas options forwarded.

                Returns:
                    CanvasItemConfigureResult: Native Tk configuration result.
                """
                if cnf is None:
                    result = original_itemconfigure(target, **options)
                else:
                    result = original_itemconfigure(target, cnf, **options)
                merged_options = dict(cnf) if isinstance(cnf, dict) else {}
                merged_options.update(options)
                photo = merged_options.get("image")
                if (isinstance(photo, (ImageTk.PhotoImage, tk.PhotoImage))
                        and photo_item_matches(target)):
                    setattr(lightbox, "_issue434_canvas_photo", photo)
                    if isinstance(target, int):
                        setattr(lightbox, "_issue434_canvas_photo_item", target)
                    setattr(
                        lightbox, "_issue434_canvas_photo_attachment",
                        "itemconfigure",
                    )
                    setattr(
                        lightbox, "_issue434_canvas_photo_attached_ns",
                        time.perf_counter_ns(),
                    )
                return result

            def measured_coords(
                target: CanvasItemTarget,
                *coordinates: CanvasCoordinate,
            ) -> CanvasCoordinates:
                """Capture final photo positioning after the native Tk call.

                Args:
                    target (CanvasItemTarget): Tk item id or string tag.
                    coordinates (tuple[CanvasCoordinate, ...]): Optional exact
                        coordinates; an empty tuple preserves query behavior.

                Returns:
                    CanvasCoordinates: Coordinate values returned by Tk.
                """
                result = original_coords(target, *coordinates)
                if (coordinates and photo_item_matches(target)
                        and getattr(lightbox, "_issue434_canvas_photo", None) is not None):
                    setattr(
                        lightbox, "_issue434_canvas_photo_attached_ns",
                        time.perf_counter_ns(),
                    )
                return result

            def install_canvas_method(name: str, wrapper: Callable[..., object]) -> None:
                """Install an instance hook and retain exact method lookup state.

                Args:
                    name (str): Canvas method name being observed.
                    wrapper (Callable[..., object]): Replacement instance wrapper.

                Returns:
                    None: An exact restoration callback is retained for teardown.
                """
                instance_dict = getattr(canvas, "__dict__", {})
                had_instance_method = name in instance_dict
                previous_instance_method = instance_dict.get(name)
                setattr(canvas, name, wrapper)

                def restore_canvas_method() -> None:
                    """Restore the Canvas method's prior instance lookup state.

                    Returns:
                        None: The class method or previous instance override is restored.
                    """
                    if had_instance_method:
                        setattr(canvas, name, previous_instance_method)
                    else:
                        try:
                            delattr(canvas, name)
                        except AttributeError:
                            pass

                self._restore_canvas_methods.append(restore_canvas_method)

            original_itemconfigure = getattr(canvas, "itemconfigure", None)
            original_itemconfig = getattr(canvas, "itemconfig", None)
            original_coords = getattr(canvas, "coords", None)
            install_canvas_method("create_image", measured_create)
            if callable(original_itemconfigure):
                install_canvas_method("itemconfigure", measured_itemconfigure)
            if callable(original_itemconfig) and original_itemconfig is not original_itemconfigure:
                install_canvas_method("itemconfig", measured_itemconfigure)
            if callable(original_coords):
                install_canvas_method("coords", measured_coords)

        PhotoLightbox.__init__ = measured_init

        def restore_init() -> None:
            """Restore the exact Lightbox constructor before instrumentation.

            Returns:
                None: No constructor wrapper remains after workload close.
            """
            PhotoLightbox.__init__ = original_init

        self._restore_lightbox_init = restore_init

        def measured_render(
            lightbox: PhotoLightbox,
            *,
            resampling: int | None = None,
            preserve_resize_jobs: bool = False,
        ) -> None:
            """Measure the real production raster render callback.

            Args:
                lightbox (PhotoLightbox): Production overlay receiving a render.
                resampling (int | None): Optional source resampling filter.
                preserve_resize_jobs (bool): Preserve currently owned resize work.

            Returns:
                None: Original image rendering finishes before the sample is retained.
            """
            started_ns = time.perf_counter_ns()
            previous_photo = lightbox._photo
            is_async = hasattr(lightbox, "_raster_worker")
            legacy_quality = _legacy_raster_quality(resampling)
            if not is_async:
                setattr(lightbox, "_issue434_canvas_photo", None)
                setattr(lightbox, "_issue434_canvas_photo_item", None)
                setattr(lightbox, "_issue434_canvas_photo_attachment", None)
                setattr(lightbox, "_issue434_canvas_photo_attached_ns", None)
                probe = self._raster_probe
                if probe is not None:
                    probe.record_legacy_request(legacy_quality)
            render_succeeded = False
            try:
                original_render(
                    lightbox, resampling=resampling,
                    preserve_resize_jobs=preserve_resize_jobs,
                )
                render_succeeded = True
            finally:
                elapsed_ns = time.perf_counter_ns() - started_ns
                if not is_async:
                    render_metric = (
                        "lightbox_preview_render_callback"
                        if resampling is not None
                        else "lightbox_final_render_callback"
                    )
                    self.recorder.add(render_metric, elapsed_ns)
                    attached_photo = getattr(
                        lightbox, "_issue434_canvas_photo", None,
                    )
                    if (lightbox._photo is not previous_photo
                            and lightbox._photo is not None):
                        attachment_metric = (
                            "lightbox_preview_photo_attachment"
                            if resampling is not None
                            else "lightbox_final_photo_attachment"
                        )
                        self.recorder.add(attachment_metric, elapsed_ns)
                    if (render_succeeded and lightbox._photo is not previous_photo
                            and lightbox._photo is not None):
                        probe = self._raster_probe
                        if probe is not None:
                            attached_ns = getattr(
                                lightbox,
                                "_issue434_canvas_photo_attached_ns", None,
                            )
                            matching_photo = (
                                attached_photo is not None
                                and attached_photo is lightbox._photo
                            )
                            attachment_value = getattr(
                                lightbox,
                                "_issue434_canvas_photo_attachment", None,
                            )
                            attachment = (
                                attachment_value
                                if attachment_value in probe._attachment_counts
                                else None
                            )
                            probe.record_legacy_publication(
                                legacy_quality, started_ns,
                                attached_ns if matching_photo
                                and isinstance(attached_ns, int) else None,
                                attachment,
                            )

        PhotoLightbox._render_image = measured_render

        def restore_render() -> None:
            """Restore the exact production renderer bound before instrumentation.

            Returns:
                None: No class-level callback wrapper remains after workload close.
            """
            PhotoLightbox._render_image = original_render

        self._restore_lightbox_render = restore_render

    def _prepare_media_fixture(self) -> None:
        """Create deterministic in-memory fixtures for the authorized read session.

        Returns:
            None: The exact fixture bodies are bound to the #361 synthetic session.
        """
        generated = common.make_photo_bodies(self.seed, self.photo_count)
        common.PHOTO_BODY_FIXTURES[self.seed] = generated
        photos_digest = hashlib.sha256(b"".join(
            hashlib.sha256(body).digest() for body in generated
        )).hexdigest()
        self.fixture_sha256["photos"] = photos_digest

        if self.scenario in LIGHTBOX_SCENARIOS:
            target_size = self.raster_size or SOURCE_FIXTURE_SIZE
            details: list[bytes] = []
            for variant in range(common.HIRES_FIXTURE_VARIANTS):
                body = common.make_hires_body(self.seed + variant)
                if target_size != SOURCE_FIXTURE_SIZE:
                    with Image.open(BytesIO(body)) as source_image:
                        resized_image = source_image.resize(
                            target_size, Image.Resampling.LANCZOS,
                        )
                        detail_stream = BytesIO()
                        resized_image.save(
                            detail_stream, format="JPEG",
                            quality=DETAIL_JPEG_QUALITY,
                        )
                        resized_image.close()
                        body = detail_stream.getvalue()
                details.append(body)
            hires = tuple(details)
        else:
            # build_gallery captures this pool for its read callback even in Photos-only runs.
            # A valid small JPEG avoids 4K fixture generation when details are never opened.
            with Image.open(BytesIO(generated[0])) as sample_image:
                detail_stream = BytesIO()
                sample_image.convert("RGB").save(
                    detail_stream, format="JPEG", quality=DETAIL_JPEG_QUALITY,
                )
            small_detail = detail_stream.getvalue()
            hires = (small_detail,) * common.HIRES_FIXTURE_VARIANTS
        common.HIRES_FIXTURE_BODIES[self.seed] = hires
        self.fixture_sha256["details"] = hashlib.sha256(b"".join(
            hashlib.sha256(body).digest() for body in hires
        )).hexdigest()

    def _prime_catalogue(self) -> None:
        """Load pages and await the visible grid before requesting a detail image.

        Returns:
            None: Page and visible-thumbnail ownership remains asynchronous on Tk.
        """
        if self._closed or self.viewer is None:
            return
        viewer = self.viewer
        self._observe_first_image()
        if len(viewer._photos) < self.photo_count and viewer._worker is None:
            viewer._load_more()
        if len(viewer._photos) >= self.photo_count and self.ready():
            self._prime_after_id = None
            return
        self._prime_after_id = self.root.after(
            CATALOGUE_POLL_MS, self._prime_catalogue,
        )

    def ready(self) -> bool:
        """Report startup readiness once, after grid and initial detail settlement.

        Returns:
            bool: One-way startup readiness; later render transitions do not reset it.
        """
        if self._startup_ready:
            return True
        viewer = self.viewer
        if viewer is None:
            return False
        if self.scenario in LIGHTBOX_SCENARIOS and self._lightbox_requested:
            lightbox = viewer._lightbox
            settled_detail = bool(
                lightbox is not None and lightbox._photo is not None
                and viewer._pending_detail_index is None
                and viewer._worker is None
            )
            if not settled_detail:
                return False
            if self._first_detail_elapsed_ms is None:
                self._first_detail_elapsed_ms = (
                    time.perf_counter_ns() - self._mounted_ns
            ) / common.NS_PER_MS
            self._startup_ready = True
            self._settled_elapsed_ms = (
                time.perf_counter_ns() - self._mounted_ns
            ) / 1_000_000
            return True
        if not self.root.winfo_ismapped():
            return False
        self._observe_first_image()
        if (viewer is None or len(viewer._photos) < self.photo_count
                or viewer._worker is not None or not viewer.frame.winfo_ismapped()):
            return False
        visible = viewer._visible_thumbnail_indices
        if not visible:
            return False
        if not all(
            0 <= index < len(viewer._photo_images)
            and viewer._photo_images[index] is not None
            for index in visible
        ):
            return False
        if not viewer._events.empty():
            return False
        if self._grid_settled_elapsed_ms is None:
            self._grid_settled_elapsed_ms = (
                time.perf_counter_ns() - self._mounted_ns
            ) / common.NS_PER_MS
        if self.scenario in LIGHTBOX_SCENARIOS:
            self._lightbox_requested = True
            viewer._open_grid_photo(0)
            return False
        self._startup_ready = True
        self._settled_elapsed_ms = (
            time.perf_counter_ns() - self._mounted_ns
        ) / common.NS_PER_MS
        return True

    def _observe_first_image(self) -> None:
        """Record the first attached visible thumbnail before full settlement.

        Returns:
            None: The first visible thumbnail latency is retained once.
        """
        if self._first_image_elapsed_ms is not None or self.viewer is None:
            return
        available = any(
            index < len(self.viewer._photo_images)
            and self.viewer._photo_images[index] is not None
            for index in self.viewer._visible_thumbnail_indices
        )
        if available:
            self._first_image_elapsed_ms = (
                time.perf_counter_ns() - self._mounted_ns
            ) / common.NS_PER_MS

    def step(self) -> None:
        """Apply one deterministic interaction through real production UI owners.

        Returns:
            None: One scenario action and its GUI feedback sample are recorded.
        """
        if self._closed or not self._startup_ready:
            self._skipped_steps += 1
            return
        if (self.scenario in LIGHTBOX_SCENARIOS
                and not self._lightbox_input_ready()):
            self._skipped_steps += 1
            return
        started_ns = time.perf_counter_ns()
        if self.scenario == "long-session":
            if self.interactions % LONG_SESSION_ACTION_CADENCE == 0:
                self._step_lightbox()
            else:
                self._step_photos()
            feedback_metric = "long_session_media_feedback"
        elif self.scenario in LIGHTBOX_SCENARIOS:
            self._step_lightbox()
            feedback_metric = (
                "lightbox_rapid_feedback"
                if self.scenario == "lightbox-rapid"
                else "lightbox_regular_feedback"
            )
        else:
            self._step_photos()
            feedback_metric = "photos_viewport_feedback"
        self.interactions += 1
        phase = self.recorder.phase
        self.root.after_idle(lambda: self.recorder.add(
            feedback_metric, time.perf_counter_ns() - started_ns, phase,
        ))
        self.recorder.add("interaction_feedback", time.perf_counter_ns() - started_ns)

    def _lightbox_input_ready(self) -> bool:
        """Check valid detail ownership before another Lightbox input.

        Returns:
            bool: Rapid input is admitted over an existing image; regular input waits.
        """
        viewer = self.viewer
        lightbox = viewer._lightbox if viewer is not None else None
        if viewer is None or lightbox is None or lightbox._photo is None:
            return False
        if self.scenario == "lightbox-rapid":
            return True
        return bool(
            viewer._pending_detail_index is None
            and viewer._worker is None
            and getattr(lightbox, "_resize_job", None) is None
            and getattr(lightbox, "_resize_preview_job", None) is None
        )

    def _step_photos(self) -> None:
        """Move the actual production canvas and reconcile its visible viewport.

        Returns:
            None: The real Photos controller receives a deterministic scroll position.
        """
        if self.viewer is None:
            return
        position = SCROLL_POSITIONS[self.interactions % len(SCROLL_POSITIONS)]
        started_ns = time.perf_counter_ns()
        self.viewer.grid_canvas.yview_moveto(position)
        self.viewer.grid_canvas.event_generate(
            "<MouseWheel>",
            delta=PHOTOS_WHEEL_DELTAS[
                self.interactions % len(PHOTOS_WHEEL_DELTAS)
            ],
            when="now",
        )
        self.viewer._on_grid_viewport(None)
        self.recorder.add(
            "callback:NativeGalleryViewer._on_grid_viewport",
            time.perf_counter_ns() - started_ns,
        )

    def _step_lightbox(self) -> None:
        """Exercise production zoom, pan and resize actions on the real overlay.

        Returns:
            None: Lightbox state changes through its production controls and bindings.

        Raises:
            RuntimeError: The expected settled production Lightbox is unavailable.
        """
        viewer = self.viewer
        lightbox = viewer._lightbox if viewer is not None else None
        if lightbox is None or lightbox._photo is None:
            raise RuntimeError("Production Lightbox is not settled.")
        if self._initial_client_size is None:
            self._initial_client_size = (
                max(MIN_CLIENT_SIZE_PIXELS, self.root.winfo_width()),
                max(MIN_CLIENT_SIZE_PIXELS, self.root.winfo_height()),
            )
        if self.scenario == "lightbox-rapid":
            for factor in RAPID_ZOOM_FACTORS:
                lightbox._change_zoom(factor)
            lightbox._canvas.event_generate(
                "<ButtonPress-1>", x=PAN_START[0], y=PAN_START[1], when="now",
            )
            lightbox._canvas.event_generate(
                "<B1-Motion>", x=RAPID_PAN_END[0], y=RAPID_PAN_END[1],
                state=common.TK_BUTTON1_MASK, when="now",
            )
            lightbox._canvas.event_generate(
                "<ButtonRelease-1>", x=RAPID_PAN_END[0], y=RAPID_PAN_END[1], when="now",
            )
            for delta in RAPID_WHEEL_DELTAS:
                lightbox._canvas.event_generate(
                    "<MouseWheel>", delta=delta, when="now",
                )
        else:
            lightbox._buttons[LIGHTBOX_ZOOM_BUTTON_INDEX].invoke()
            lightbox._canvas.event_generate(
                "<ButtonPress-1>", x=PAN_START[0], y=PAN_START[1], when="now",
            )
            lightbox._canvas.event_generate(
                "<B1-Motion>", x=PAN_END[0], y=PAN_END[1],
                state=common.TK_BUTTON1_MASK, when="now",
            )
            lightbox._canvas.event_generate(
                "<ButtonRelease-1>", x=PAN_END[0], y=PAN_END[1], when="now",
            )
            lightbox._buttons[LIGHTBOX_FULLSCREEN_BUTTON_INDEX].invoke()
            lightbox._buttons[LIGHTBOX_FULLSCREEN_BUTTON_INDEX].invoke()
            width, height = self._initial_client_size
            self.root.geometry(
                f"{width + LIGHTBOX_RESIZE_DELTA_PIXELS}x{height}"
            )
            self.root.after_idle(lambda: self.root.geometry(f"{width}x{height}"))

    def counters(self) -> dict[str, int | float | str | bool | None]:
        """Return bounded workload counts without exposing fixture identities.

        Returns:
            dict[str, int | float | str | bool | None]: Stable scenario and UI counters.
        """
        viewer = self.viewer
        result: dict[str, int | float | str | bool | None] = {
            "scenario": self.scenario,
            "requested_photos": self.photo_count if viewer is not None else 0,
            "loaded_photos": len(viewer._photos) if viewer is not None else 0,
            "page_reads": self.fixture_state["page_reads"] if self.fixture_state else 0,
            "thumbnail_images": sum(
                image is not None for image in viewer._photo_images
            ) if viewer is not None else 0,
            "lightbox_open": bool(viewer and viewer._lightbox is not None),
            "interactions": self.interactions,
            "skipped_steps": self._skipped_steps,
            "lightbox_source_width": (
                self.raster_size[0]
                if self.raster_size and self.scenario in LIGHTBOX_SCENARIOS else None
            ),
            "lightbox_source_height": (
                self.raster_size[1]
                if self.raster_size and self.scenario in LIGHTBOX_SCENARIOS else None
            ),
            "display_width_px": self.root.winfo_screenwidth(),
            "display_height_px": self.root.winfo_screenheight(),
            "client_width_px": self.root.winfo_width(),
            "client_height_px": self.root.winfo_height(),
            "first_image_elapsed_ms": self._first_image_elapsed_ms,
            "first_detail_elapsed_ms": self._first_detail_elapsed_ms,
            "grid_settled_elapsed_ms": self._grid_settled_elapsed_ms,
            "settled_elapsed_ms": self._settled_elapsed_ms,
            "startup_ready": self._startup_ready,
            "input_ready": (
                self._lightbox_input_ready()
                if self.scenario in LIGHTBOX_SCENARIOS
                else self._startup_ready
            ),
            "photo_fixture_sha256": self.fixture_sha256.get("photos"),
            "detail_fixture_sha256": self.fixture_sha256.get("details"),
            "closed": self._closed,
        }
        if self.fixture_info is not None:
            result["fixture_bytes"] = self.fixture_info["thumbnail_body_bytes"]
            result["hires_cache_peak"] = self.fixture_state["hires_cache_peak"] if self.fixture_state else 0
        if self._raster_probe is not None:
            result.update(self._raster_probe.counters())
        return result

    def close(self) -> None:
        """Cancel owned polling, close the real viewer and restore fixture globals.

        Returns:
            None: Mounted media workers and shared fixture references are released.
        """
        if self._closed:
            return
        self._closed = True
        if self._prime_after_id is not None:
            try:
                self.root.after_cancel(self._prime_after_id)
            except tk.TclError:
                pass
            self._prime_after_id = None
        if self.viewer is not None:
            media_host = self.viewer.frame.master
            self.viewer.close()
            try:
                media_host.destroy()
            except tk.TclError:
                pass
        for restore_canvas_method in reversed(self._restore_canvas_methods):
            restore_canvas_method()
        self._restore_canvas_methods.clear()
        if self._restore_lightbox_init is not None:
            self._restore_lightbox_init()
            self._restore_lightbox_init = None
        if self._restore_lightbox_render is not None:
            self._restore_lightbox_render()
            self._restore_lightbox_render = None
        if self._previous_photo_fixtures is None:
            common.PHOTO_BODY_FIXTURES.pop(self.seed, None)
        else:
            common.PHOTO_BODY_FIXTURES[self.seed] = self._previous_photo_fixtures
        if self._previous_hires_fixtures is None:
            common.HIRES_FIXTURE_BODIES.pop(self.seed, None)
        else:
            common.HIRES_FIXTURE_BODIES[self.seed] = self._previous_hires_fixtures


def mount(
    root: tk.Tk,
    source_root: Path,
    private_root: Path,
    scenario: str,
    seed: int,
    scale: int,
    raster_size: tuple[int, int] | None,
    recorder: common.Recorder,
) -> MediaWorkload:
    """Create the requested issue 434 workload in an existing Tk interpreter.

    Args:
        root (tk.Tk): Existing Tk root and event loop.
        source_root (Path): Source checkout containing production WinApp code.
        private_root (Path): Private child directory for synthetic fixture files.
        scenario (str): Supported fixed issue 434 scenario name.
        seed (int): Deterministic nonnegative fixture seed.
        scale (int): Photos catalogue extent in photos.
        raster_size (tuple[int, int] | None): Optional Lightbox source dimensions.
        recorder (common.Recorder): Shared callback and feedback timing recorder.

    Returns:
        MediaWorkload: Mounted workload implementing ready, step, counters and close.
    """
    return MediaWorkload(
        root, source_root, private_root, scenario, seed, scale,
        raster_size, recorder,
    )
