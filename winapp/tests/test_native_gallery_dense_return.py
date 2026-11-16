# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_native_gallery_dense_return.py
# Module Type: Native Gallery Dense-Cache Regression
# Purpose: Isolate real durable thumbnail seeding from the bounded viewer batch.
# Responsibilities:
#   - Execute the unchanged dense-return fixture once through shared native setup.
#   - Preserve cache/proof/transport assertions and its original measurement file.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Class-preserving native cache-return coverage with its own child budget."""

import unittest

import test_native_gallery_viewer as viewer_fixture


@unittest.skipUnless(
    viewer_fixture.sys.platform == 'win32' and viewer_fixture.tk is not None
    and viewer_fixture.Image is not None and viewer_fixture.ImageTk is not None,
    'The native viewer regression requires Windows, Tk and Pillow.',
)
class NativeGalleryDenseReturnTests(unittest.TestCase):
    """Run the original durable dense-cache case with the same native lifecycle.

    Explicit method reuse preserves its complete implementation and assertions
    without inheriting or rediscovering the other viewer test methods.
    """

    setUp = viewer_fixture.NativeGalleryViewerTests.setUp
    _destroy_root = viewer_fixture.NativeGalleryViewerTests._destroy_root
    _wait_until = viewer_fixture.NativeGalleryViewerTests._wait_until
    _png = viewer_fixture.NativeGalleryViewerTests._png
    _gallery_data = viewer_fixture.NativeGalleryViewerTests._gallery_data
    _session = viewer_fixture.NativeGalleryViewerTests._session
    _open_viewer = viewer_fixture.NativeGalleryViewerTests._open_viewer
    _assert_no_browsing_toplevels = viewer_fixture.NativeGalleryViewerTests._assert_no_browsing_toplevels
    test_dense_return_renews_catalogue_and_reuses_disk = (
        viewer_fixture.NativeGalleryViewerTests._case_dense_return_renews_catalogue_and_reuses_disk
    )
