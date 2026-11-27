# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/uploader/qt_hdr_macos.py
# Module Type: Optional macOS Qt display service
# Purpose: Present gain-map photos in the Qt Quick Lightbox using macOS EDR.
# Responsibilities:
#   - Expand bounded HDR sources with Core Image on EDR-capable displays.
#   - Overlay a click-through native layer that follows the QML image geometry.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Provide an optional Core Image and Core Animation EDR overlay."""

import sys
from ctypes import c_void_p

from PySide6.QtCore import QRectF
from PySide6.QtWidgets import QWidget

from .gallery_image_policy import MEDIA_IMAGE_MAX_SIZE

try:
    if sys.platform != "darwin":
        raise ImportError("AppKit HDR output is available only on macOS")
    import AppKit
    import Foundation
    import objc
    import Quartz
except ImportError:
    AppKit = Foundation = objc = Quartz = None

# Purpose: Identify standard-dynamic-range white for EDR content checks.
# Type: float. Units: SDR-white multiples. Scope: one source and screen pair.
# Consumers: MacQtHDRSurface.present.
# Rationale: Headroom at or below one cannot show luminance above SDR white.
SDR_WHITE_HEADROOM = 1.0
# Purpose: Prevent Core Image from enlarging the bounded decoded source.
# Type: float. Units: source-size multiples. Scope: one HDR output raster.
# Consumers: MacQtHDRSurface.present.
# Rationale: Native layers can scale the image for presentation without
# allocating a larger decoded raster.
MAX_SOURCE_RENDER_SCALE = 1.0


if AppKit is not None:
    # Objective-C registers names process-wide, including the stable Tk view.
    class _QtHDRClickThroughView(AppKit.NSView):
        """Keep native HDR pixels above Qt while forwarding all input events."""

        def hitTest_(self, _point: Foundation.NSPoint) -> None:
            """Pass hit testing to the Qt Quick view beneath this overlay.

            Args:
                _point (Foundation.NSPoint): Native event position, unused.
            Returns:
                None: The overlay never captures pointer or gesture input.
            """
            return None
else:
    _QtHDRClickThroughView = None


class MacQtHDRSurface:
    """Manage one optional EDR overlay for a Qt Quick photo scene.

    Args:
        widget (QWidget): Existing Qt Quick host whose native view is overlaid.
    Returns:
        None: The surface starts in SDR fallback mode.
    """

    def __init__(self, widget: QWidget) -> None:
        """Initialize an empty overlay without creating another window.

        Args:
            widget (QWidget): Existing scene host for the current lightbox.
        Returns:
            None: No native hierarchy is changed until HDR content is accepted.
        """
        self.widget = widget
        self._view = None
        self._layer = None
        self._raster = None
        self._geometry = QRectF()
        self._controls: tuple[QRectF, ...] = ()
        self._encoded: bytes | None = None
        self._attempted = False

    def present(self, encoded: bytes | None, geometry: QRectF) -> bool:
        """Decode and position the active detail image on a capable display.

        Args:
            encoded (bytes | None): Bounded source bytes for the current detail.
            geometry (QRectF): Current QML image rectangle in widget coordinates.
        Returns:
            bool: True when EDR pixels are attached; otherwise SDR stays visible.
        """
        self.clear()
        self._geometry = QRectF(geometry)
        if encoded is None or not encoded:
            return False
        if geometry.isEmpty():
            self._encoded = encoded
            return False
        self._attempted = True
        if AppKit is None or Quartz is None:
            return False
        self._encoded = encoded
        try:
            native = objc.objc_object(c_void_p=int(self.widget.winId()))
            screen = native.window().screen()
            if (screen is None
                    or screen.maximumPotentialExtendedDynamicRangeColorComponentValue()
                    <= SDR_WHITE_HEADROOM):
                return False
            data = Foundation.NSData.dataWithBytes_length_(encoded, len(encoded))
            options = Foundation.NSMutableDictionary.dictionary()
            enabled = Foundation.NSNumber.numberWithBool_(True)
            options.setObject_forKey_(enabled, Quartz.kCIImageExpandToHDR)
            options.setObject_forKey_(enabled, Quartz.kCIImageApplyOrientationProperty)
            source = Quartz.CIImage.imageWithData_options_(data, options)
            if source is None or not hasattr(source, "contentHeadroom"):
                return False
            headroom = float(source.contentHeadroom())
            if headroom <= SDR_WHITE_HEADROOM:
                return False
            extent = source.extent()
            if extent.size.width <= 0 or extent.size.height <= 0:
                return False
            scale = min(
                MAX_SOURCE_RENDER_SCALE,
                MEDIA_IMAGE_MAX_SIZE[0] / extent.size.width,
                MEDIA_IMAGE_MAX_SIZE[1] / extent.size.height,
            )
            source = source.imageByApplyingTransform_(
                Quartz.CGAffineTransformMakeScale(scale, scale),
            )
            extent = source.extent()
            color_space = Quartz.CGColorSpaceCreateWithName(
                Quartz.kCGColorSpaceITUR_2100_PQ,
            )
            context = Quartz.CIContext.contextWithOptions_(None)
            raster = context.createCGImage_fromRect_format_colorSpace_(
                source, extent, Quartz.kCIFormatRGB10, color_space,
            )
            if raster is None:
                return False
            overlay = _QtHDRClickThroughView.alloc().initWithFrame_(native.bounds())
            overlay.setWantsLayer_(True)
            overlay.layer().setMasksToBounds_(True)
            layer = Quartz.CALayer.layer()
            layer.setContentsFormat_(Quartz.kCAContentsFormatRGBA16Float)
            if hasattr(layer, "setContentsHeadroom_"):
                layer.setContentsHeadroom_(headroom)
            dynamic_range = getattr(Quartz, "CALayerDynamicRangeHigh", None)
            if hasattr(layer, "setPreferredDynamicRange_") and dynamic_range is not None:
                layer.setPreferredDynamicRange_(dynamic_range)
            else:
                layer.setWantsExtendedDynamicRangeContent_(True)
            layer.setContents_(raster)
            overlay.layer().addSublayer_(layer)
            native.addSubview_(overlay)
            self._view = overlay
            self._layer = layer
            self._raster = raster
            self._place_layer()
            self._mask_controls()
            return True
        except Exception:
            self.clear()
            return False

    def set_geometry(self, geometry: QRectF) -> bool:
        """Follow QML fit, zoom, pan, resize and fullscreen geometry changes.

        Args:
            geometry (QRectF): Visible image rectangle in Qt logical coordinates.
        Returns:
            bool: True when the native image remains attached, else SDR is shown.
        """
        self._geometry = QRectF(geometry)
        if self._view is None or self._layer is None:
            if self._encoded is not None and not self._attempted and not geometry.isEmpty():
                return self.present(self._encoded, geometry)
            return False
        try:
            self._view.setFrame_(self._view.superview().bounds())
            if geometry.isEmpty():
                self.hide()
                self._attempted = False
                return False
            self._place_layer()
            return True
        except Exception:
            self.clear()
            return False

    def _place_layer(self) -> None:
        """Map the top-left-origin QML rectangle into native layer coordinates.

        Returns:
            None: The layer matches the exact transformed image bounds.
        """
        bounds = self._view.bounds()
        x = self._geometry.x()
        y = self._geometry.y()
        width = self._geometry.width()
        height = self._geometry.height()
        self._layer.setFrame_(Foundation.NSMakeRect(
            x, bounds.size.height - y - height, width, height,
        ))

    def set_controls(self, rectangles: tuple[QRectF, ...]) -> None:
        """Expose Qt's visible toolbar and arrows over the native image.

        Args:
            rectangles (tuple[QRectF, ...]): Disjoint visible controls in Qt
                widget coordinates, empty while the fullscreen chrome is hidden.
        Returns:
            None: Updates the native mask without redecoding or rescaling the raster.
        """
        if rectangles == self._controls:
            return
        self._controls = rectangles
        if self._view is not None:
            try:
                self._mask_controls()
            except Exception:
                # macOS EDR is permanently optional. The native surface owns
                # this fallback so controls and the retained SDR image stay visible.
                self.hide()

    def _mask_controls(self) -> None:
        """Exclude the currently visible nonoverlapping native control regions.

        Returns:
            None: Core Animation presents the same retained EDR image around the controls.
        """
        overlay = self._view.layer()
        if not self._controls:
            overlay.setMask_(None)
            return
        bounds = self._view.bounds()
        viewport = QRectF(self.widget.rect())
        path = Quartz.CGPathCreateMutable()
        Quartz.CGPathAddRect(path, None, bounds)
        for control in self._controls:
            clip = control.intersected(viewport)
            if not clip.isEmpty():
                Quartz.CGPathAddRect(path, None, Foundation.NSMakeRect(
                    clip.x(), bounds.size.height - clip.bottom(), clip.width(), clip.height(),
                ))
        mask = Quartz.CAShapeLayer.layer()
        mask.setFrame_(bounds)
        mask.setFillRule_(Quartz.kCAFillRuleEvenOdd)
        mask.setPath_(path)
        overlay.setMask_(mask)

    def hide(self) -> None:
        """Remove the native overlay while keeping the SDR rendition visible.

        Returns:
            None: No HDR pixels remain above Qt's retained scene.
        """
        if self._view is not None:
            self._view.removeFromSuperview()
        self._view = None
        self._layer = None
        self._raster = None

    def clear(self) -> None:
        """Retire all current native content before selection replacement.

        Returns:
            None: The Qt SDR image is the only remaining rendition.
        """
        self.hide()
        self._geometry = QRectF()
        self._encoded = None
        self._attempted = False
