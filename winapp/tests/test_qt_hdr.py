# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_qt_hdr.py
# Module Type: Qt HDR regression tests
# Purpose: Verify bounded HDR source retention and safe SDR fallback ownership.
# Responsibilities: Cover source size policy, overlay teardown and unavailable
#   platform behavior without claiming physical display acceptance.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Check bounded Qt HDR handoff and its portable SDR fallback."""

from contextlib import AbstractContextManager
import sys
import unittest
from pathlib import Path
from threading import Event
from types import SimpleNamespace
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

try:
    from PySide6.QtCore import QRectF, QSize, Qt
    from PySide6.QtGui import QColor, QImage, QPainter
    from PySide6.QtWidgets import QWidget
    from uploader import qt_hdr_macos
    from uploader import qt_photo_source
    from uploader.app_linking import NATIVE_MEDIA_MAX_BYTES
    from uploader.gallery_image_policy import (
        MEDIA_IMAGE_MAX_PIXELS, MEDIA_IMAGE_MAX_SIZE,
    )
    from uploader.qt_hdr_macos import MacQtHDRSurface
    from uploader.qt_photo_source import _bounded_hdr_source, _content_crop_bounds
except (ImportError, OSError):
    QRectF = QSize = Qt = QColor = QImage = QPainter = QWidget = None

# Purpose: Bound the generated native HDR advisory image allocation.
# Type: int. Units: pixels per side. Scope: synthetic macOS Core Image fixture.
# Consumers: QtHDRTests.test_core_image_decodes_bounded_pq_heif_and_reports_headroom.
# Rationale: A solid 32-pixel raster is sufficient to verify PQ decode metadata.
HDR_ADVISORY_FIXTURE_SIZE = 32
# Purpose: Bound encoded bytes retained by the native HDR advisory fixture.
# Type: int. Units: bytes. Scope: one synthetic macOS HEIF source.
# Consumers: QtHDRTests.test_core_image_decodes_bounded_pq_heif_and_reports_headroom.
# Rationale: A one MiB ceiling is generous for a tiny solid color HEIF.
HDR_ADVISORY_FIXTURE_MAX_BYTES = 1024 * 1024


@unittest.skipIf(QWidget is None, "Optional Qt source dependencies are absent.")
class QtHDRTests(unittest.TestCase):
    """Verify bounded source ownership and non-macOS SDR behavior."""

    def test_detail_worker_returns_normalized_crop_bounds_from_bounded_probe(self) -> None:
        """Detect opposed matte without mutating the retained detail raster.

        Returns:
            None: The bounded worker probe returns normalized interior geometry.
        """
        if qt_photo_source.PillowImage is None:
            self.skipTest("Pillow is unavailable for the shared matte detector.")
        image = QImage(320, 240, QImage.Format.Format_RGB32)
        image.fill(Qt.GlobalColor.black)
        painter = QPainter(image)
        painter.fillRect(20, 20, 280, 200, QColor("#f0f0f0"))
        painter.end()
        width, height = image.width(), image.height()
        crop = _content_crop_bounds(image, Event())
        self.assertIsNotNone(crop)
        left, top, right, bottom = crop
        self.assertGreater(left, 0.03)
        self.assertGreater(top, 0.03)
        self.assertLess(right, 0.97)
        self.assertLess(bottom, 0.97)
        self.assertEqual((image.width(), image.height()), (width, height))

    def test_hdr_source_retention_excludes_thumbnails_and_oversized_images(self) -> None:
        """Admit only bounded detail bytes for possible native HDR expansion.

        Returns:
            None: Source policy assertions pass without decoding a display image.
        """
        encoded = b"bounded encoded fixture"
        self.assertEqual(
            _bounded_hdr_source(encoded, QSize(64, 64), False), encoded,
        )
        self.assertIsNone(_bounded_hdr_source(encoded, QSize(64, 64), True))
        self.assertIsNone(_bounded_hdr_source(None, QSize(64, 64), False))
        oversized = bytes(NATIVE_MEDIA_MAX_BYTES + 1)
        self.assertIsNone(_bounded_hdr_source(oversized, QSize(64, 64), False))
        self.assertIsNone(_bounded_hdr_source(
            encoded, QSize(MEDIA_IMAGE_MAX_PIXELS, 2), False,
        ))

    def test_non_macos_overlay_stays_in_sdr_and_clears_safely(self) -> None:
        """Keep the standard Qt rendition when Cocoa EDR is unavailable.

        Returns:
            None: The optional surface reports unavailable and accepts teardown.
        """
        surface = MacQtHDRSurface(mock.Mock(spec=QWidget))
        if sys.platform != "darwin":
            self.assertIsNone(qt_hdr_macos.AppKit)
            self.assertFalse(surface.present(b"encoded", QRectF(0, 0, 20, 20)))
        surface.clear()
        self.assertFalse(surface.set_geometry(QRectF(0, 0, 20, 20)))

    def test_mocked_native_surface_retires_hdr_for_sdr_replacement(self) -> None:
        """Remove the native layer before returning to ordinary SDR content.

        Returns:
            None: A later SDR selection cannot leave a stale HDR overlay.
        """
        surface, overlay, _layer = self._native_surface()
        with self._native_frameworks():
            self.assertTrue(
                surface.present(b"gain-map fixture", self._rect(10, 20, 300, 200)),
                self._native_stage_summary(surface),
            )
            self.assertTrue(surface.set_geometry(self._rect(10, 20, 300, 200)))
            self.assertFalse(surface.present(None, self._rect(0, 0, 300, 200)))
        overlay.removeFromSuperview.assert_called_once_with()
        self.assertIsNone(surface._raster)
        self.assertIsNone(surface._encoded)

    def test_mocked_native_geometry_tracks_zoom_pan_resize_and_fullscreen(self) -> None:
        """Keep the EDR layer aligned with each QML image geometry update.

        Returns:
            None: Current image frame follows the retained Quick scene bounds.
        """
        surface, _overlay, layer = self._native_surface()
        geometries = (
            self._rect(12, 18, 360, 240),  # fitted image
            self._rect(-40, 8, 620, 414),  # zoom and horizontal pan
            self._rect(25, -30, 1_020, 680),  # resized fullscreen viewport
        )
        with self._native_frameworks():
            self.assertTrue(
                surface.present(b"gain-map fixture", geometries[0]),
                self._native_stage_summary(surface),
            )
            self.assertTrue(surface.set_geometry(geometries[1]))
            surface._test_native.bounds.return_value.size.height = 900.0
            self.assertTrue(surface.set_geometry(geometries[2]))
        context = surface._test_context
        render_call = context.createCGImage_fromRect_format_colorSpace_
        raster_extent = render_call.call_args.args[1]
        self.assertLessEqual(raster_extent.size.width, MEDIA_IMAGE_MAX_SIZE[0])
        self.assertLessEqual(raster_extent.size.height, MEDIA_IMAGE_MAX_SIZE[1])
        self.assertEqual(
            (25.0, 900.0 - (-30.0) - 680.0, 1_020.0, 680.0),
            layer.setFrame_.call_args.args[0],
        )

    def test_mocked_disabled_panel_and_source_loss_remove_native_content(self) -> None:
        """Withdraw the overlay when its scene geometry or authorized source ends.

        Returns:
            None: Empty presentation and source retirement leave only SDR content.
        """
        surface, overlay, _layer = self._native_surface()
        with self._native_frameworks():
            self.assertTrue(
                surface.present(b"gain-map fixture", self._rect(5, 8, 200, 140)),
                self._native_stage_summary(surface),
            )
            self.assertFalse(surface.set_geometry(QRectF()))
            overlay.removeFromSuperview.assert_called_once_with()
            self.assertTrue(surface.set_geometry(self._rect(5, 8, 200, 140)))
            self.assertTrue(surface.present(b"next gain-map", self._rect(5, 8, 200, 140)))
            surface.clear()
        self.assertIsNone(surface._encoded)
        self.assertIsNone(surface._view)
        # The empty geometry, source replacement and final clear each detach.
        self.assertEqual(3, overlay.removeFromSuperview.call_count)

    def test_mocked_sdr_only_display_never_attaches_native_hdr(self) -> None:
        """Keep native output disabled when the selected display has no EDR.

        Returns:
            None: Display capability is required before any native subview attach.
        """
        surface, _overlay, _layer = self._native_surface()
        screen = surface._test_native.window.return_value.screen.return_value
        screen.maximumPotentialExtendedDynamicRangeColorComponentValue.return_value = (
            qt_hdr_macos.SDR_WHITE_HEADROOM
        )
        with self._native_frameworks():
            self.assertFalse(surface.present(b"gain-map fixture", self._rect(0, 0, 80, 60)))
        surface._test_native.addSubview_.assert_not_called()

    @unittest.skipUnless(sys.platform == "darwin", "Core Image HDR decode is macOS-only.")
    def test_core_image_decodes_bounded_pq_heif_and_reports_headroom(self) -> None:
        """Exercise real Core Image PQ decode without requiring an EDR display.

        Returns:
            None: Native source encoding, decode and extended-headroom checks pass.
        """
        quartz = qt_hdr_macos.Quartz
        foundation = qt_hdr_macos.Foundation
        self.assertIsNotNone(quartz)
        self.assertIsNotNone(foundation)

        color = quartz.CIColor.colorWithRed_green_blue_alpha_(4.0, 0.75, 0.25, 1.0)
        source = quartz.CIImage.imageWithColor_(color).imageByCroppingToRect_(
            quartz.CGRectMake(0.0, 0.0, HDR_ADVISORY_FIXTURE_SIZE, HDR_ADVISORY_FIXTURE_SIZE),
        )
        context = quartz.CIContext.contextWithOptions_(None)
        pq_space = quartz.CGColorSpaceCreateWithName(quartz.kCGColorSpaceITUR_2100_PQ)
        cg_image = context.createCGImage_fromRect_format_colorSpace_(
            source, source.extent(), quartz.kCIFormatRGB10, pq_space,
        )
        self.assertIsNotNone(cg_image)
        source_color_name = quartz.CGColorSpaceCopyName(
            quartz.CGImageGetColorSpace(cg_image),
        )
        self.assertEqual(quartz.kCGColorSpaceITUR_2100_PQ, source_color_name)

        encoded = foundation.NSMutableData.data()
        destination = quartz.CGImageDestinationCreateWithData(encoded, "public.heic", 1, None)
        self.assertIsNotNone(destination)
        quartz.CGImageDestinationAddImage(destination, cg_image, None)
        self.assertTrue(quartz.CGImageDestinationFinalize(destination))
        fixture = bytes(encoded)
        self.assertLessEqual(len(fixture), HDR_ADVISORY_FIXTURE_MAX_BYTES)

        data = foundation.NSData.dataWithBytes_length_(fixture, len(fixture))
        image_source = quartz.CGImageSourceCreateWithData(data, None)
        self.assertIsNotNone(image_source)
        decoded_cg_image = quartz.CGImageSourceCreateImageAtIndex(
            image_source, 0, None,
        )
        self.assertIsNotNone(decoded_cg_image)
        encoded_color_name = quartz.CGColorSpaceCopyName(
            quartz.CGImageGetColorSpace(decoded_cg_image),
        )
        self.assertEqual(
            quartz.kCGColorSpaceITUR_2100_PQ, encoded_color_name,
            "The encoded HEIF must round-trip its named PQ color space.",
        )

        options = foundation.NSMutableDictionary.dictionary()
        options.setObject_forKey_(
            foundation.NSNumber.numberWithBool_(True), quartz.kCIImageExpandToHDR,
        )
        decoded = quartz.CIImage.imageWithData_options_(data, options)
        self.assertIsNotNone(decoded)
        decoded_color_name = quartz.CGColorSpaceCopyName(decoded.colorSpace())
        self.assertIsNotNone(decoded_color_name)
        decoded_color_label = str(decoded_color_name).lower()
        self.assertTrue(
            decoded_color_name == quartz.kCGColorSpaceITUR_2100_PQ
            or "extended" in decoded_color_label and "linear" in decoded_color_label,
            f"Unexpected Core Image output color space: {decoded_color_name}",
        )
        self.assertGreater(
            float(decoded.contentHeadroom()), qt_hdr_macos.SDR_WHITE_HEADROOM,
        )
        self.assertGreater(decoded.extent().size.width, 0.0)
        self.assertGreater(decoded.extent().size.height, 0.0)

    @staticmethod
    def _rect(x: float, y: float, width: float, height: float) -> QRectF:
        """Create one synthetic QML logical-pixel image rectangle.

        Args:
            x (float): Horizontal viewport-relative image origin.
            y (float): Vertical viewport-relative image origin.
            width (float): Transformed image width.
            height (float): Transformed image height.
        Returns:
            QRectF: Rectangle used only by the native lifecycle fixture.
        """
        return QRectF(x, y, width, height)

    def _native_surface(self) -> tuple[MacQtHDRSurface, mock.Mock, mock.Mock]:
        """Build a native-view surrogate and its EDR content layer.

        Returns:
            tuple[MacQtHDRSurface, Mock, Mock]: Surface, overlay view and image layer.
        """
        native = mock.Mock()
        native.bounds.return_value = SimpleNamespace(
            size=SimpleNamespace(height=600.0),
        )
        native.window.return_value.screen.return_value = mock.Mock(
            maximumPotentialExtendedDynamicRangeColorComponentValue=mock.Mock(
                return_value=2.0,
            ),
        )
        overlay = mock.Mock()
        overlay.bounds.return_value = native.bounds.return_value
        overlay.superview.return_value = native
        overlay_layer = mock.Mock()
        overlay.layer.return_value = overlay_layer
        image_layer = mock.Mock()
        widget = mock.Mock(spec=QWidget)
        widget.winId.return_value = 123
        surface = MacQtHDRSurface(widget)
        self._active_surface = surface
        surface._test_native = native
        surface._test_overlay = overlay
        surface._test_overlay_layer = overlay_layer
        surface._test_image_layer = image_layer
        native_view_class = mock.Mock()
        native_view_class.alloc.return_value.initWithFrame_.return_value = overlay
        surface._test_native_view_class = native_view_class
        return surface, overlay, image_layer

    def _native_frameworks(self) -> AbstractContextManager[dict[str, object]]:
        """Patch PyObjC APIs with native-view and Core Image test doubles.

        Returns:
            AbstractContextManager[dict[str, object]]: Active framework patch context.
        """
        surface = self._active_surface
        return mock.patch.multiple(
            qt_hdr_macos,
            AppKit=SimpleNamespace(),
            Foundation=self._foundation(),
            objc=SimpleNamespace(objc_object=mock.Mock(
                return_value=surface._test_native,
            )),
            Quartz=self._quartz(surface),
            _QtHDRClickThroughView=surface._test_native_view_class,
        )

    def _native_stage_summary(self, surface: MacQtHDRSurface) -> str:
        """Report which mocked native setup stages ran after a failed attach.

        Args:
            surface (MacQtHDRSurface): Surface whose current fixture is inspected.
        Returns:
            str: Fixed stage booleans with no native error or source contents.
        """
        native_class = surface._test_native_view_class
        image_layer = surface._test_image_layer
        view = surface._test_overlay
        native = surface._test_native
        raster_call = surface._test_context.createCGImage_fromRect_format_colorSpace_
        return (
            f"raster={raster_call.called}, view={native_class.alloc.called}, "
            f"layer={image_layer.setContents_.called}, "
            f"sublayer={view.layer.return_value.addSublayer_.called}, "
            f"attach={native.addSubview_.called}, "
            f"frame={image_layer.setFrame_.called}"
        )

    def _foundation(self) -> SimpleNamespace:
        """Prepare the Foundation objects used for encoded Core Image input.

        Returns:
            SimpleNamespace: Minimal NSData, options and rectangle functions.
        """
        options = mock.Mock()
        return SimpleNamespace(
            NSData=SimpleNamespace(dataWithBytes_length_=mock.Mock()),
            NSMutableDictionary=SimpleNamespace(dictionary=mock.Mock(return_value=options)),
            NSNumber=SimpleNamespace(numberWithBool_=mock.Mock(return_value=True)),
            NSMakeRect=mock.Mock(side_effect=lambda *values: tuple(values)),
        )

    def _quartz(self, surface: MacQtHDRSurface) -> SimpleNamespace:
        """Prepare Core Image and Core Animation native test doubles.

        Args:
            surface (MacQtHDRSurface): Fixture whose image layer is installed.
        Returns:
            SimpleNamespace: Required optional Quartz selectors and constants.
        """
        image = _CoreImageStub()
        context = mock.Mock()
        context.createCGImage_fromRect_format_colorSpace_.return_value = object()
        surface._test_context = context
        return SimpleNamespace(
            kCIImageExpandToHDR=object(),
            kCIImageApplyOrientationProperty=object(),
            kCGColorSpaceITUR_2100_PQ=object(),
            kCIFormatRGB10=object(),
            kCAContentsFormatRGBA16Float=object(),
            CIImage=SimpleNamespace(imageWithData_options_=mock.Mock(return_value=image)),
            CIContext=SimpleNamespace(contextWithOptions_=mock.Mock(return_value=context)),
            CGColorSpaceCreateWithName=mock.Mock(return_value=object()),
            CGAffineTransformMakeScale=mock.Mock(side_effect=lambda x, y: (x, y)),
            CALayer=SimpleNamespace(layer=mock.Mock(return_value=surface._test_image_layer)),
            CALayerDynamicRangeHigh=object(),
        )


class _CoreImageStub:
    """Model a gain-map Core Image with bounded transformed extents."""

    def __init__(self) -> None:
        """Initialize a synthetic large image with EDR headroom.

        Returns:
            None: The test source starts at 4,000 by 2,000 pixels.
        """
        self.width = 4_000.0
        self.height = 2_000.0

    def contentHeadroom(self) -> float:
        """Report synthetic source headroom above SDR white.

        Returns:
            float: Fixture signal that Core Image decoded an HDR source.
        """
        return 2.0

    def extent(self) -> SimpleNamespace:
        """Expose the current synthetic image dimensions.

        Returns:
            SimpleNamespace: CGRect-shaped extent with width and height.
        """
        return SimpleNamespace(
            size=SimpleNamespace(width=self.width, height=self.height),
        )

    def imageByApplyingTransform_(self, transform: tuple[float, float]) -> _CoreImageStub:
        """Apply the requested scalar while preserving the source aspect.

        Args:
            transform (tuple[float, float]): X and Y test scale factors.
        Returns:
            _CoreImageStub: This synthetic image after its extent is scaled.
        """
        self.width *= transform[0]
        self.height *= transform[1]
        return self


if __name__ == "__main__":
    unittest.main()
