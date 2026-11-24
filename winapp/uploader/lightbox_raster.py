# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/lightbox_raster.py
# Module Type: Windows Companion Runtime
# Purpose: Bound asynchronous Lightbox raster preparation to the newest request.
# Responsibilities:
#   - Own one Pillow source per runtime owner without copying full source pixels.
#   - Run source crop discovery and one resize at a time on its daemon lane.
#   - Transfer only current owned results back to the Tk owner thread.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Prepare latest-wins Lightbox rasters without accessing Tk widgets."""

from dataclasses import dataclass
import threading
from typing import TYPE_CHECKING, Callable

if TYPE_CHECKING:
    from PIL import Image as PillowImage


class RasterOwner:
    """Identify one Lightbox runtime by unique object identity.

    Returns:
        None: New instances compare only by identity and carry no payload.
    """

    __slots__ = ()


@dataclass(frozen=True)
class RasterRequest:
    """Describe one immutable source crop and target raster request.

    Args:
        serial (int): Monotonic request identity assigned by the Lightbox owner.
        crop_box (tuple[int, int, int, int]): Source pixel box passed to Pillow.
        output_size (tuple[int, int]): Requested output width and height in pixels.
        resampling (int): Pillow resampling enum passed unchanged to resize.
    Returns:
        None: Instances carry no mutable worker state.
    """

    serial: int
    crop_box: tuple[int, int, int, int]
    output_size: tuple[int, int]
    resampling: int


@dataclass(frozen=True)
class RasterResult:
    """Hold one worker-owned raster or a safe error result.

    Args:
        owner (RasterOwner): Runtime owner token that submitted the request.
        serial (int): Request identity copied from the immutable request.
        crop_box (tuple[int, int, int, int]): Source box used for the resize.
        output_size (tuple[int, int]): Requested output dimensions in pixels.
        resampling (int): Pillow resampling enum used for the resize.
        raster (PillowImage.Image | None): Owned result transferred by take_result.
        error (str | None): Exception class name when Pillow could not resize.
        source_pixels (int | None): Source raster pixel count when available.
        output_pixels (int | None): Output raster pixel count when available.
    Returns:
        None: Raster ownership remains with the worker until transfer or disposal.
    """

    owner: RasterOwner
    serial: int
    crop_box: tuple[int, int, int, int]
    output_size: tuple[int, int]
    resampling: int
    raster: "PillowImage.Image | None"
    error: str | None
    source_pixels: int | None
    output_pixels: int | None


@dataclass(frozen=True)
class SourcePreparationResult:
    """Hold matte-detection output for the current authorized source.

    Args:
        owner (RasterOwner): Runtime token that owns the retained source raster.
        epoch (int): Source generation captured when detection was queued.
        crop_box (tuple[int, int, int, int] | None): Detected visible bounds.
        error (str | None): Exception class name when detection failed.
    Returns:
        None: The source remains lane-owned until release or replacement.
    """

    owner: RasterOwner
    epoch: int
    crop_box: tuple[int, int, int, int] | None
    error: str | None


@dataclass(frozen=True)
class SourcePreparationRequest:
    """Describe one detector call for a newly installed source image.

    Args:
        owner (RasterOwner): Runtime token that owns the source raster.
        epoch (int): Source generation that fences stale detector output.
    detector (Callable[[PillowImage.Image], tuple[int, int, int, int]]):
        Pillow-only detector run by the lane thread.
    Returns:
        None: Detector execution state is kept outside the request.
    """

    owner: RasterOwner
    epoch: int
    detector: Callable[["PillowImage.Image"], tuple[int, int, int, int]]


class LightboxRasterWorker:
    """Prepare Pillow images on one daemon thread with latest-request bounds.

    Args:
        name (str): Stable diagnostic name for the worker thread.
    Returns:
        None: A reusable lane starts with no source or output ownership.

    Source images passed to set_source transfer ownership to this lane. Optional
    source preparation must be consumed before viewport submission is enabled.
    The mutually exclusive preparation and resize slots represent one pending
    operation and one completed result. The take methods transfer output to
    the caller. Other rasters and sources close when no longer in use. The token
    is compared by identity and can be replaced across gallery transitions.
    """

    def __init__(self, name: str = "GalleryLightboxRaster") -> None:
        """Start the lane's single non-blocking daemon worker.

        Args:
            name (str): Diagnostic thread name shown in process inspection.
        Returns:
            None: Condition state and worker lifecycle are initialized.
        """
        self._condition = threading.Condition()
        self._owner: RasterOwner | None = None
        self._source: "PillowImage.Image | None" = None
        self._pending_request: RasterRequest | None = None
        self._pending_preparation: SourcePreparationRequest | None = None
        self._completed_result: RasterResult | None = None
        self._completed_preparation: SourcePreparationResult | None = None
        self._source_prepared = False
        self._active_source: "PillowImage.Image | None" = None
        self._active_owner: RasterOwner | None = None
        self._active_epoch: int | None = None
        self._retired_sources: list["PillowImage.Image"] = []
        self._epoch = 0
        self._closed = False
        self._thread = threading.Thread(
            target=self._run,
            name=name,
            daemon=True,
        )
        self._thread.start()

    def set_source(
        self,
        owner: RasterOwner,
        image: "PillowImage.Image",
        detector: Callable[["PillowImage.Image"], tuple[int, int, int, int]]
        | None = None,
    ) -> bool:
        """Transfer a retained source image to the supplied runtime owner.

        Args:
            owner (RasterOwner): Identity token for the active Lightbox runtime.
            image (PillowImage.Image): Full source raster transferred to the lane.
            detector (Callable[[PillowImage.Image], tuple[int, int, int, int]] | None):
                Optional Pillow-only source detector before the first viewport resize.
        Returns:
            bool: True when accepted; false for a missing owner token or closed
                lane. Detector output remains fenced until retrieved.
        """
        sources_to_close: list["PillowImage.Image"] = []
        rasters_to_close: list["PillowImage.Image"] = []
        with self._condition:
            if self._closed or owner is None:
                sources_to_close.append(image)
                accepted = False
            else:
                self._epoch += 1
                self._clear_pending_locked()
                self._pending_preparation = None
                completed = self._completed_result
                self._completed_result = None
                if completed is not None and completed.raster is not None:
                    rasters_to_close.append(completed.raster)
                self._completed_preparation = None
                previous = self._source
                self._source = image
                self._owner = owner
                self._source_prepared = detector is None
                if detector is not None:
                    self._pending_preparation = SourcePreparationRequest(
                        owner, self._epoch, detector,
                    )
                if previous is not None and previous is not image:
                    self._retire_source_locked(previous)
                sources_to_close.extend(self._collect_retired_locked())
                self._condition.notify()
                accepted = True
        self._close_images(rasters_to_close)
        self._close_images(sources_to_close)
        return accepted

    def submit(self, owner: RasterOwner, request: RasterRequest) -> bool:
        """Replace queued work with the newest request for the current source.

        Args:
            owner (RasterOwner): Runtime token that owns the current source image.
            request (RasterRequest): Immutable crop and output-size request.
        Returns:
            bool: True when queued; false when owner or source is unavailable.
        """
        rasters_to_close: list["PillowImage.Image"] = []
        with self._condition:
            if (self._closed or owner is not self._owner
                    or self._source is None or not self._source_prepared):
                return False
            self._epoch += 1
            self._pending_request = request
            completed = self._completed_result
            self._completed_result = None
            if completed is not None and completed.raster is not None:
                rasters_to_close.append(completed.raster)
            self._condition.notify()
        self._close_images(rasters_to_close)
        return True

    def take_result(self, owner: RasterOwner) -> RasterResult | None:
        """Transfer the newest completed result for the current owner token.

        Args:
            owner (RasterOwner): Runtime token expected to own the current result.
        Returns:
            RasterResult | None: Owned result, or None when no current result
                is ready for this owner.
        """
        rasters_to_close: list["PillowImage.Image"] = []
        with self._condition:
            if owner is not self._owner:
                return None
            result = self._completed_result
            self._completed_result = None
            if result is None:
                return None
            if result.owner is owner:
                return result
            if result.raster is not None:
                rasters_to_close.append(result.raster)
        self._close_images(rasters_to_close)
        return None

    def take_source_preparation(
        self, owner: RasterOwner,
    ) -> SourcePreparationResult | None:
        """Transfer current source-detector output to its Tk owner.

        Args:
            owner (RasterOwner): Runtime token expected to own the source result.
        Returns:
            SourcePreparationResult | None: Current crop result, or None when
                preparation is still running, stale, or unavailable.
        """
        with self._condition:
            if owner is not self._owner:
                return None
            result = self._completed_preparation
            if (result is None or result.owner is not owner
                    or result.epoch != self._epoch):
                self._completed_preparation = None
                return None
            self._completed_preparation = None
            if result.crop_box is not None and result.error is None:
                self._source_prepared = True
            return result

    def has_pending_work(self, owner: RasterOwner) -> bool:
        """Report whether this current owner can still receive a result.

        Args:
            owner (RasterOwner): Runtime token expected to own active lane work.
        Returns:
            bool: True for a current-epoch active, pending or completed
                request; false when the token was superseded or all eligible
                work was invalidated.
        """
        with self._condition:
            if self._closed or owner is not self._owner:
                return False
            completed = self._completed_result
            preparation = self._completed_preparation
            return bool(
                (self._active_owner is owner
                 and self._active_epoch == self._epoch)
                or self._pending_request is not None
                or self._pending_preparation is not None
                or (completed is not None and completed.owner is owner)
                or (preparation is not None and preparation.owner is owner)
            )

    def invalidate(self, owner: RasterOwner) -> bool:
        """Discard pending and completed work while retaining the source.

        Args:
            owner (RasterOwner): Runtime token that currently owns the source.
        Returns:
            bool: True when this owner was invalidated; false for stale owners.
        """
        rasters_to_close: list["PillowImage.Image"] = []
        with self._condition:
            if self._closed or owner is not self._owner:
                return False
            self._epoch += 1
            self._pending_request = None
            self._pending_preparation = None
            completed = self._completed_result
            self._completed_result = None
            self._completed_preparation = None
            if completed is not None and completed.raster is not None:
                rasters_to_close.append(completed.raster)
        self._close_images(rasters_to_close)
        return True

    def release(self, owner: RasterOwner) -> bool:
        """Invalidate work and retire the source owned by this runtime token.

        Args:
            owner (RasterOwner): Runtime token whose source should be released.
        Returns:
            bool: True when the current owner was released; false otherwise.
        """
        rasters_to_close: list["PillowImage.Image"] = []
        sources_to_close: list["PillowImage.Image"] = []
        with self._condition:
            if owner is not self._owner:
                return False
            self._epoch += 1
            self._pending_request = None
            self._pending_preparation = None
            completed = self._completed_result
            self._completed_result = None
            self._completed_preparation = None
            if completed is not None and completed.raster is not None:
                rasters_to_close.append(completed.raster)
            source = self._source
            self._source = None
            self._owner = None
            self._source_prepared = False
            if source is not None:
                self._retire_source_locked(source)
            sources_to_close.extend(self._collect_retired_locked())
        self._close_images(rasters_to_close)
        self._close_images(sources_to_close)
        return True

    def close(self) -> None:
        """Retire lane ownership and return without joining active work.

        Returns:
            None: Active work can finish and dispose stale data asynchronously.
        """
        rasters_to_close: list["PillowImage.Image"] = []
        sources_to_close: list["PillowImage.Image"] = []
        with self._condition:
            if self._closed:
                return
            self._closed = True
            self._epoch += 1
            self._pending_request = None
            self._pending_preparation = None
            result = self._completed_result
            self._completed_result = None
            self._completed_preparation = None
            if result is not None and result.raster is not None:
                rasters_to_close.append(result.raster)
            source = self._source
            self._source = None
            self._owner = None
            self._source_prepared = False
            if source is not None:
                self._retire_source_locked(source)
            sources_to_close.extend(self._collect_retired_locked())
            self._condition.notify_all()
        self._close_images(rasters_to_close)
        self._close_images(sources_to_close)

    def _run(self) -> None:
        """Process serialized resize jobs and dispose every stale output.

        Returns:
            None: The daemon exits after close and its final active job settles.
        """
        while True:
            should_exit = False
            with self._condition:
                while (not self._closed and self._pending_request is None
                       and self._pending_preparation is None):
                    self._condition.wait()
                if self._closed:
                    sources_to_close = self._collect_retired_locked()
                    should_exit = True
                else:
                    source = self._source
                    owner = self._owner
                    preparation = self._pending_preparation
                    request = self._pending_request
                    if preparation is not None:
                        self._pending_preparation = None
                        epoch = preparation.epoch
                    else:
                        self._pending_request = None
                        epoch = self._epoch
                    self._active_source = source
                    self._active_owner = owner
                    self._active_epoch = epoch
            if should_exit:
                self._close_images(sources_to_close)
                return

            if preparation is not None:
                self._run_source_preparation(
                    preparation, source,
                )
                continue
            result, resized = self._resize(owner, source, request)

            rasters_to_close: list["PillowImage.Image"] = []
            sources_to_close: list["PillowImage.Image"] = []
            with self._condition:
                self._active_source = None
                self._active_owner = None
                self._active_epoch = None
                current = (
                    not self._closed
                    and owner is self._owner
                    and source is self._source
                    and epoch == self._epoch
                )
                if current:
                    previous = self._completed_result
                    self._completed_result = result
                    if previous is not None and previous.raster is not None:
                        rasters_to_close.append(previous.raster)
                elif resized is not None:
                    rasters_to_close.append(resized)
                sources_to_close.extend(self._collect_retired_locked())
                if self._closed:
                    self._condition.notify_all()
            self._close_images(rasters_to_close)
            self._close_images(sources_to_close)
            if self._closed:
                return

    def _run_source_preparation(
        self,
        request: SourcePreparationRequest,
        source: "PillowImage.Image | None",
    ) -> None:
        """Run one bounded source detector and retain only current output.

        Args:
            request (SourcePreparationRequest): Detector identity and source epoch.
            source (PillowImage.Image | None): Source held by the lane during scan.
        Returns:
            None: Current crop output is available through take_source_preparation.
        """
        crop_box: tuple[int, int, int, int] | None = None
        error: str | None = None
        try:
            if source is None:
                raise RuntimeError("Source detector has no retained image.")
            crop_box = request.detector(source)
        except Exception as exc:
            error = type(exc).__name__
        result = SourcePreparationResult(
            request.owner, request.epoch, crop_box, error,
        )
        sources_to_close: list["PillowImage.Image"] = []
        with self._condition:
            self._active_source = None
            self._active_owner = None
            self._active_epoch = None
            current = (
                not self._closed
                and request.owner is self._owner
                and source is self._source
                and request.epoch == self._epoch
            )
            if current:
                self._completed_preparation = result
            sources_to_close.extend(self._collect_retired_locked())
            if self._closed:
                self._condition.notify_all()
        self._close_images(sources_to_close)

    def _resize(
        self,
        owner: RasterOwner | None,
        source: "PillowImage.Image | None",
        request: RasterRequest | None,
    ) -> tuple[RasterResult, "PillowImage.Image | None"]:
        """Run one exact Pillow crop resize and capture a safe failure result.

        Args:
            owner (RasterOwner | None): Owner captured for the active request.
            source (PillowImage.Image | None): Source held until this call ends.
            request (RasterRequest | None): Request captured from the pending slot.
        Returns:
            tuple[RasterResult, PillowImage.Image | None]: Completed metadata and
                the raster separately for stale-output disposal.
        """
        if owner is None or source is None or request is None:
            raise RuntimeError("Raster worker admitted an incomplete request.")
        source_pixels: int | None = None
        raster: "PillowImage.Image | None" = None
        error: str | None = None
        try:
            source_size = source.size
            source_pixels = source_size[0] * source_size[1]
            # Preserve the legacy positional resampling and source-box semantics.
            raster = source.resize(
                request.output_size,
                request.resampling,
                box=request.crop_box,
            )
        except Exception as exc:
            error = type(exc).__name__
        result = RasterResult(
            owner=owner,
            serial=request.serial,
            crop_box=request.crop_box,
            output_size=request.output_size,
            resampling=request.resampling,
            raster=raster,
            error=error,
            source_pixels=source_pixels,
            output_pixels=(
                request.output_size[0] * request.output_size[1]
                if raster is not None else None
            ),
        )
        return result, raster

    def _clear_pending_locked(self) -> None:
        """Clear the replaceable request slot while holding the condition.

        Returns:
            None: No queued request remains eligible for worker execution.
        """
        self._pending_request = None

    def _retire_source_locked(self, source: "PillowImage.Image") -> None:
        """Defer source closure only when the active resize still reads it.

        Args:
            source (PillowImage.Image): Source whose lane ownership ended.
        Returns:
            None: Active sources move to the bounded retirement list.
        """
        self._retired_sources.append(source)

    def _collect_retired_locked(self) -> list["PillowImage.Image"]:
        """Return retired sources no longer referenced by active work.

        Returns:
            list[PillowImage.Image]: Sources safe for disposal after unlocking.
        """
        if self._active_source is None:
            sources = self._retired_sources
            self._retired_sources = []
            return sources
        retained: list["PillowImage.Image"] = []
        ready: list["PillowImage.Image"] = []
        for source in self._retired_sources:
            if source is self._active_source:
                retained.append(source)
            else:
                ready.append(source)
        self._retired_sources = retained
        return ready

    @staticmethod
    def _close_images(
        images: list["PillowImage.Image"],
    ) -> None:
        """Close owned Pillow images while tolerating already-closed rasters.

        Args:
            images (list[PillowImage.Image]): Detached lane-owned images.
        Returns:
            None: Every detached image receives best-effort close.
        """
        for image in images:
            try:
                image.close()
            except Exception:
                pass
