# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/gallery_hdr_macos.py
# Module Type: Windows Client UI
# Purpose: Present supported HDR photos in the existing macOS Tk window.
# Responsibilities:
#   - Decode supported gain maps into an extended-range native image.
#   - Attach click-through AppKit image tiles around Tk controls.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Optional AppKit extended-range surface for the native gallery lightbox."""

import ctypes
import sys
import tkinter as tk

try:
    if sys.platform != 'darwin':
        raise ImportError('AppKit is only available on macOS')
    import _tkinter
    import AppKit
    import Foundation
    import Quartz
    import objc
except ImportError:
    AppKit = Foundation = Quartz = objc = None


if AppKit is not None:
    class _ClickThroughView(AppKit.NSView):
        """Clip native image tiles while leaving mouse events to Tk."""

        def hitTest_(self, point: Foundation.NSPoint) -> None:
            """Pass a native hit test through to the underlying Tk view.

            Args:
                point (Foundation.NSPoint): Position in the superview.
            Returns:
                None: Tk receives the event instead of this surface.
            """
            return None


def _subtract(
    rectangle: tuple[float, float, float, float],
    hole: tuple[float, float, float, float],
) -> list[tuple[float, float, float, float]]:
    """Split a rectangle into areas outside a control's rectangle.

    Args:
        rectangle (tuple[float, float, float, float]): Left, top, right, bottom.
        hole (tuple[float, float, float, float]): Control bounds in canvas space.
    Returns:
        list[tuple[float, float, float, float]]: Nonempty remaining rectangles.
    """
    left, top, right, bottom = rectangle
    x1, y1, x2, y2 = hole
    x1, y1 = max(left, x1), max(top, y1)
    x2, y2 = min(right, x2), min(bottom, y2)
    if x1 >= x2 or y1 >= y2:
        return [rectangle]
    return [part for part in (
        (left, top, right, y1), (left, y2, right, bottom),
        (left, y1, x1, y2), (x2, y1, right, y2),
    ) if part[0] < part[2] and part[1] < part[3]]


class MacHDRSurface:
    """Own an optional click-through extended-range image in one Tk canvas.

    Args:
        canvas (tk.Canvas): Existing photo canvas in the main application window.
    Returns:
        None: Surface starts detached and safely unavailable when unsupported.
    """

    def __init__(self, canvas: tk.Canvas) -> None:
        """Initialize the native surface without creating a separate window.

        Args:
            canvas (tk.Canvas): Existing photo canvas in the main window.
        Returns:
            None: The surface starts without an image or attached views.
        """
        self.canvas = canvas
        self._window = None
        self._raster = None
        self._tiles = []
        self._bridge = None
        self._encoded = None
        self._size = None
        self._attempted = False
        if AppKit is None:
            return
        try:
            bridge = ctypes.CDLL(_tkinter.__file__)
            lookup = bridge.Tk_MacOSXGetNSWindowForDrawable
            lookup.argtypes = [ctypes.c_ulong]
            lookup.restype = ctypes.c_void_p
            self._bridge = lookup
        except (AttributeError, OSError):
            pass

    def load(self, encoded: bytes | None, size: tuple[int, int]) -> bool:
        """Decode HDR source bytes without reducing highlights to SDR.

        Args:
            encoded (bytes | None): Original bounded JPEG or other image bytes.
            size (tuple[int, int]): Prepared SDR image width and height in pixels.
        Returns:
            bool: True when an extended-range image is ready for presentation.
        """
        self.clear()
        self._encoded = encoded
        self._size = size
        return self._decode()

    def _decode(self) -> bool:
        """Decode the retained bytes once an EDR screen is available.

        Returns:
            bool: True when a native HDR image was successfully prepared.
        """
        encoded, size = self._encoded, self._size
        if not encoded or size is None or self._bridge is None or self._attempted:
            return False
        try:
            pointer = self._bridge(self.canvas.winfo_toplevel().winfo_id())
            if not pointer:
                return False
            window = objc.objc_object(c_void_p=pointer)
            screen = window.screen()
            # Current headroom can be 1.0 until a renderer requests EDR.
            # Potential headroom identifies a display that can exceed SDR white.
            if (screen is None
                    or screen.maximumPotentialExtendedDynamicRangeColorComponentValue() <= 1.0):
                return False
            self._attempted = True
            data = Foundation.NSData.dataWithBytes_length_(encoded, len(encoded))
            # Core Image probes optional keys; a native dictionary returns nil
            # for absent keys instead of raising through a Python dict proxy.
            options = Foundation.NSMutableDictionary.dictionary()
            enabled = Foundation.NSNumber.numberWithBool_(True)
            options.setObject_forKey_(enabled, Quartz.kCIImageExpandToHDR)
            options.setObject_forKey_(enabled, Quartz.kCIImageApplyOrientationProperty)
            source = Quartz.CIImage.imageWithData_options_(data, options)
            if source is None or not hasattr(source, 'contentHeadroom'):
                return False
            if source.contentHeadroom() <= 1.0:
                return False
            extent = source.extent()
            if extent.size.width <= 0 or extent.size.height <= 0:
                return False
            transform = Quartz.CGAffineTransformMakeScale(
                size[0] / extent.size.width, size[1] / extent.size.height,
            )
            source = source.imageByApplyingTransform_(transform)
            color = Quartz.CGColorSpaceCreateWithName(
                Quartz.kCGColorSpaceITUR_2100_PQ,
            )
            context = Quartz.CIContext.contextWithOptions_(None)
            raster = context.createCGImage_fromRect_format_colorSpace_(
                source, source.extent(), Quartz.kCIFormatRGB10, color,
            )
            if raster is None:
                return False
            self._raster = raster
            self._window = window
            return True
        except Exception:
            self._raster = None
            self._window = None
            return False

    def render(
        self,
        box: tuple[int, int, int, int],
        bounds: tuple[float, float, float, float],
        controls: list[tk.Misc],
    ) -> bool:
        """Place clipped native tiles over the current canvas image.

        Args:
            box (tuple[int, int, int, int]): Source crop bounds in SDR pixels.
            bounds (tuple[float, float, float, float]): Canvas image left, top,
                right and bottom, including any pan offset.
            controls (list[tk.Misc]): Mapped Tk controls that must remain visible.
        Returns:
            bool: True when native tiles were successfully attached.
        """
        self._remove_tiles()
        if self._raster is None and not self._decode():
            return False
        if self._window is None or self._size is None:
            return False
        try:
            screen = self._window.screen()
            if (screen is None
                    or screen.maximumPotentialExtendedDynamicRangeColorComponentValue() <= 1.0):
                return False
            canvas = self.canvas
            width, height = canvas.winfo_width(), canvas.winfo_height()
            left, top, right, bottom = bounds
            visible = (max(0.0, left), max(0.0, top),
                       min(float(width), right), min(float(height), bottom))
            if visible[0] >= visible[2] or visible[1] >= visible[3]:
                return False
            regions = [visible]
            for widget in controls:
                if not widget.winfo_ismapped():
                    continue
                x = widget.winfo_rootx() - canvas.winfo_rootx()
                y = widget.winfo_rooty() - canvas.winfo_rooty()
                hole = (x, y, x + widget.winfo_width(), y + widget.winfo_height())
                regions = [piece for region in regions for piece in _subtract(region, hole)]
            parent = self._window.contentView()
            origin_x = canvas.winfo_rootx() - canvas.winfo_toplevel().winfo_rootx()
            origin_y = canvas.winfo_rooty() - canvas.winfo_toplevel().winfo_rooty()
            parent_height = parent.bounds().size.height
            crop_left, crop_top, crop_right, crop_bottom = box
            image_width, image_height = self._size
            scale_x = (right - left) / (crop_right - crop_left)
            scale_y = (bottom - top) / (crop_bottom - crop_top)
            for x1, y1, x2, y2 in regions:
                clip = _ClickThroughView.alloc().initWithFrame_(Foundation.NSMakeRect(
                    origin_x + x1, parent_height - origin_y - y2, x2 - x1, y2 - y1,
                ))
                clip.setWantsLayer_(True)
                clip.layer().setMasksToBounds_(True)
                # CALayer's HDR mode tone maps its CGImage to the current
                # display headroom. NSImageView resolved this image as SDR.
                layer = Quartz.CALayer.layer()
                layer.setFrame_(Foundation.NSMakeRect(
                    left - crop_left * scale_x - x1,
                    y2 - top - (image_height - crop_top) * scale_y,
                    image_width * scale_x, image_height * scale_y,
                ))
                layer.setContentsFormat_(Quartz.kCAContentsFormatRGBA16Float)
                layer.setWantsExtendedDynamicRangeContent_(True)
                layer.setContents_(self._raster)
                clip.layer().addSublayer_(layer)
                parent.addSubview_(clip)
                self._tiles.append(clip)
            return bool(self._tiles)
        except Exception:
            self._remove_tiles()
            return False

    def hide(self) -> None:
        """Hide native tiles while preserving the current image for repaint.

        Returns:
            None: The underlying Tk canvas is visible.
        """
        self._remove_tiles()

    def _remove_tiles(self) -> None:
        """Detach all native tiles before changing geometry or closing.

        Returns:
            None: No tile remains in the Tk window's native view hierarchy.
        """
        for tile in self._tiles:
            tile.removeFromSuperview()
        self._tiles.clear()

    def clear(self) -> None:
        """Release the previous image and its attached native surfaces.

        Returns:
            None: The SDR canvas is visible again.
        """
        self._remove_tiles()
        self._raster = None
        self._window = None
        self._encoded = None
        self._size = None
        self._attempted = False
