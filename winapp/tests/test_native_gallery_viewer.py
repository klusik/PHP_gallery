# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_native_gallery_viewer.py
# Module Type: Windows Tk and Cache Regression Test
# Purpose: Exercise the linked-gallery viewer and shared thumbnail cache.
# Responsibilities:
#   - Verify native and opt-in Canvas tiles, bounded thumbnails and photo paging.
#   - Verify shared cache limits, proof identity, expiry and owner cleanup.
#   - Verify GPS actions, zoom, slideshow and foreground-result fencing.
#   - Verify closing the viewer or its parent cancels owned work.
#   - Verify confirmed gallery creation reaches the real Watcher action.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Regression coverage for shared cache policy and the Windows gallery viewer."""

import ctypes
import gc
import hashlib
import io
import json
import queue
import sys
import threading
import time
import types
import unittest
import wave
from collections import deque
from dataclasses import replace
from pathlib import Path
from tempfile import TemporaryDirectory
from typing import Callable, cast
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.app_linking import (
    LinkedGallery,
    LinkingError,
    LinkedGalleryPage,
    LinkedGalleryEditSnapshot,
    LinkingRevisionConflict,
    LinkedGalleryTree,
    LinkedMediaPage,
    LinkedPhoto,
    LinkedPresentation,
    LinkedTrack,
    LinkingMediaThrottled,
    LinkingMediaUnavailable,
    LinkingResponseError,
    LinkingSession,
    MediaReadTimings,
    NATIVE_MEDIA_DELETE_BATCH_MAX,
    READ_SCOPE,
)
from uploader.flight_report_translations import tr
from uploader.linked_create import BoundCreateReceipt, CreateDispatch
from uploader.gallery_catalogue_cache import GalleryCatalogueSnapshot
from uploader.gallery_media_cache import GalleryMediaCache

try:
    import tkinter as tk
    from tkinter import ttk
except ModuleNotFoundError as exc:
    if exc.name not in ('tkinter', '_tkinter'):
        raise
    tk = None

try:
    from PIL import Image, ImageDraw, ImageGrab, ImageTk
except ModuleNotFoundError as exc:
    if exc.name != 'PIL':
        raise
    Image = None
    ImageTk = None

if tk is not None:
    from uploader.diagnostics import format_safe_photos_shortcut
    from uploader.ui_theme import configure_theme
    from uploader import native_gallery_viewer
else:
    native_gallery_viewer = None

if sys.platform == 'win32' and tk is not None and Image is not None:
    from pypdf import PdfWriter
    from uploader import gallery_lightbox, photos_workspace, ui_theme
    from test_redesign import MAIN
    from uploader import app_linking as linking
    from uploader.app_header import HEADER_WORKSPACE_GAP
    from uploader.flight_jobs import SessionPlan, allocate_report_revision
    from uploader.flight_ui import FlightUI
    from uploader.flight_workflow import WatchedReportTarget
    from uploader.ui_shell import active_panel
    from uploader.gallery_folders import GalleryFolderShelf
    from uploader.gallery_view_preferences import (
            load_gallery_card_format, save_gallery_card_format,
        load_gallery_photo_order, save_gallery_photo_order,
        load_gallery_folder_split, save_gallery_folder_split,
        GALLERY_FOLDER_SHARE_SCALE,
    )
    from uploader.gallery_preload import GalleryPreloadWorker
    from uploader.windows_display import prepare_windows_display
else:
    gallery_lightbox = None
    photos_workspace = None
    ui_theme = None


# Purpose: Bound polling for one real Tk worker result in native CI.
# Type: float. Units: seconds. Scope: one test's page/detail readiness wait.
# Consumers: NativeGalleryViewerTests._wait_until and held rename regression.
# Rationale: Six seconds admits loaded Windows runners while failing promptly.
TEST_GUI_WAIT_SECONDS = 6.0

# Purpose: Yield Tk enough time to dispatch worker completion and widget events.
# Type: float. Units: seconds. Scope: one polling iteration in the Tk fixture.
# Consumers: NativeGalleryViewerTests._wait_until.
# Rationale: 10 ms is responsive without spinning continuously.
TEST_GUI_POLL_SECONDS = 0.01

# Purpose: Exercise the viewer under a non-default Tk scaling factor.
# Type: float. Units: display scale multiplier. Scope: this isolated Tk root.
# Consumers: NativeGalleryViewerTests.setUp.
# Rationale: 1.5 approximates a common high-DPI desktop scale.
TEST_TK_SCALING = 1.5

# Purpose: Keep the batch boundary regression just above one production slice.
# Type: int. Units: gallery rows. Scope: cached catalogue rendering fixture.
# Consumers: NativeGalleryViewerTests cached-tree UI regressions.
# Rationale: One extra row proves later tree work yields to Tk.
TEST_CATALOGUE_CACHE_ROWS = 65

# Purpose: Verify the gallery refresh action in normal and compact native views.
# Type: tuple[tuple[int, int], ...]. Units: Tk pixels, width and height.
# Scope: one native gallery refresh fixture. Consumers: tree refresh regression.
# Rationale: 1180 by 760 represents a desktop window; 720 by 620 exercises the
# compact layout while retaining the fixed-width gallery navigation pane.
TEST_TREE_REFRESH_VIEWPORTS = ((1180, 760), (720, 620))

# Purpose: Keep captured fixture windows inside the hosted desktop work area.
# Type: int. Units: Tk pixels. Scope: tree refresh screenshot viewports.
# Consumers: NativeGalleryViewerTests tree refresh regression.
# Rationale: 80 px leaves room for native title bars and the runner's taskbar;
# target viewports are clamped instead of capturing off-screen black pixels.
TEST_TREE_REFRESH_SCREEN_MARGIN = 80

# Purpose: Use the available desktop width for the real global-header controls.
# Type: int. Units: Tk pixels. Scope: compact-header screenshot widths only.
# Consumers: NativeGalleryViewerTests.test_compact_application_header_reclaims_gallery_height.
# Rationale: 16 reserves the native eight-pixel window border on each side;
# the existing larger vertical margin still protects title bars and the taskbar.
TEST_HEADER_SCREEN_WIDTH_MARGIN = 16

# Purpose: Capture the actual parent immediately around a shaped HWND.
# Type: int. Units: native pixels. Scope: card/button corner screenshots.
# Consumers: NativeGalleryViewerTests._assert_rendered_card_corners.
# Rationale: 4 leaves room for outside samples without including adjacent controls.
TEST_CORNER_CAPTURE_MARGIN = 4

# Purpose: Identify antialiased cover pixels independently of the folder rim.
# Type: int. Units: RGB channel levels out of 255. Scope: folder ratio mask.
# Consumers: test_folder_cover_ratio_describes_the_image_inside_the_frame.
# Rationale: 32 includes Lanczos edge variation while the fixture's bright red
# cover remains over 100 levels away from every dark shared-frame color.
TEST_FOLDER_COVER_COLOR_DISTANCE = 32

# Purpose: Give generated button clicks deterministic Windows event times.
# Type: tuple[int, int]. Units: milliseconds. Scope: double-click Tk fixture.
# Consumers: NativeGalleryViewerTests.test_grid_single_click_selects_and_double_click_opens_lightbox.
# Rationale: Two presses 100 ms apart are inside ordinary Tk double-click timing.
TEST_DOUBLE_CLICK_TIMES_MS = (3000, 3100)

# Purpose: Place the independent single click outside the double-click pair.
# Type: int. Units: milliseconds. Scope: one generated single-button click.
# Consumers: NativeGalleryViewerTests.test_grid_single_click_selects_and_double_click_opens_lightbox.
# Rationale: A two-second gap exceeds ordinary double-click recognition.
TEST_SINGLE_CLICK_TIME_MS = 1000

# Purpose: Exercise a real viewer whose visible band exceeds the old cache cap.
# Type: tuple[int, int]. Units: pixels. Scope: large viewport regression root.
# Consumers: test_large_viewport_return_reuses_decoded_visible_band.
# Rationale: 1800 by 1200 exposes more than 48 fixed-size thumbnail tiles.
TEST_WIDE_VIEWPORT_SIZE = (1800, 1200)

# Purpose: Keep both ends of the large fixture vertically scrollable.
# Type: int. Units: photos. Scope: one synthetic catalogue page.
# Consumers: test_large_viewport_return_reuses_decoded_visible_band.
# Rationale: 120 tiles exceed the chosen viewport while fitting RGB channels.
TEST_WIDE_VIEWPORT_PHOTO_COUNT = 120

# Purpose: Exercise dense 21:9 rows over four real metadata pages.
# Type: int. Units: photos. Scope: dense cache-return Windows fixture.
# Consumers: test_dense_return_renews_catalogue_and_reuses_disk.
# Rationale: 240 records span multiple viewports while staying below the shared
# LRU entry cap; the test explicitly clears RAM before isolating disk return.
TEST_DENSE_CACHE_PHOTO_COUNT = 240

# Purpose: Keep the isolated media session alive during native cache assertions.
# Type: float. Units: seconds. Scope: dense cache-return Windows fixture.
# Consumers: test_dense_return_renews_catalogue_and_reuses_disk.
# Rationale: Five minutes covers real Tk scheduling without altering proof TTL.
TEST_CACHE_SESSION_SECONDS = 300.0

# Purpose: Pin the requested shared thumbnail LRU entry capacity.
# Type: int. Units: decoded images. Scope: shared thumbnail cache policy test.
# Consumers: GalleryThumbnailMemoryPolicyTests.
# Rationale: 4096 entries bound session identity bookkeeping for large albums.
TEST_EXPECTED_THUMBNAIL_CACHE_ENTRY_LIMIT = 4096

# Purpose: Pin the requested shared thumbnail LRU byte capacity.
# Type: int. Units: bytes. Scope: shared thumbnail cache policy test.
# Consumers: GalleryThumbnailMemoryPolicyTests.
# Rationale: 256 MiB supports decoded return reuse under one strict cap.
TEST_EXPECTED_THUMBNAIL_CACHE_MAX_BYTES = 256 * 1024 * 1024

# Purpose: Exercise viewport event coalescing without relying on machine speed.
# Type: int. Units: synthetic Tk signals. Scope: one burst regression.
# Consumers: test_thumbnails_follow_bounded_admitted_band.
# Rationale: Twelve signals in one Tk turn must share one owned callback.
TEST_VIEWPORT_EVENT_BURST = 12

# Purpose: Bound the join of the cancellation-aware synthetic media worker.
# Type: float. Units: seconds. Scope: parent-destruction cleanup test.
# Consumers: NativeGalleryViewerTests.test_parent_destroy_cancels_pending_read.
# Rationale: Two seconds is ample for a local event and prevents hung CI.
TEST_WORKER_JOIN_SECONDS = 2.0

# Purpose: Verify the viewer remains inside the ordinary desktop viewport.
# Type: tuple[int, int]. Units: pixels. Scope: minimum test window dimensions.
# Consumers: NativeGalleryViewerTests.test_page_detail_manual_slideshow_and_close.
# Rationale: Exercise a narrow but useful in-window Photos layout.
TEST_VIEWER_MINIMUM_SIZE = (580, 380)

# Purpose: Observe corner exclusion and resize tracking on a native card HWND.
# Type: tuple[int, int]. Units: pixels. Scope: contextual region regression.
# Consumers: test_context_glass_card_native_region_matches_mapped_geometry.
# Rationale: Distinct mapped geometries expose stale-size clipping directly.
TEST_CONTEXT_CARD_INITIAL_SIZE = (140, 80)
TEST_CONTEXT_CARD_RESIZED_SIZE = (220, 90)

# Purpose: Provide one explicit failed preload item for status scoping.
# Type: int. Units: photos. Scope: one terminal-status workspace fixture.
# Consumers: test_preload_terminal_status_is_scoped_to_selected_gallery.
# Rationale: One skipped photo makes the stale gallery error visible and bounded.
TEST_PRELOAD_STATUS_PHOTO_COUNT = 1

# Purpose: Confirm the initial gallery layout retains enough photo columns.
# Type: int. Units: columns. Scope: wide native grid fixture.
# Consumers: NativeGalleryViewerTests.test_page_detail_manual_slideshow_and_close.
# Rationale: The 840 px default viewer fits at least four 112 px photo tiles.
TEST_WIDE_GRID_COLUMNS_MINIMUM = 4

# Purpose: Confirm the minimum viewer width keeps at least one usable column.
# Type: int. Units: columns. Scope: minimum native grid fixture.
# Consumers: NativeGalleryViewerTests.test_page_detail_manual_slideshow_and_close.
# Rationale: Narrow mode must retain a photo tile instead of clipping the grid.
TEST_NARROW_GRID_COLUMNS_MINIMUM = 1

# Purpose: Keep enough rows to exercise viewport and thumbnail scheduling.
# Type: int. Units: unique photos. Scope: native grid and pipeline fixtures.
# Consumers: NativeGalleryViewerTests scroll and thumbnail batch regressions.
# Rationale: Twenty-four photos exceed the viewport at the minimum test size.
TEST_SCROLL_PHOTO_COUNT = 24

# Purpose: Span multiple viewports for opt-in page and admission checks.
# Type: int. Units: unique photos. Scope: Canvas paging and rapid-scroll test.
# Consumers:
#   NativeGalleryCanvasIntegrationTests.test_canvas_opt_in_pages_and_coalesces_rapid_viewport_scroll.
# Rationale: Two existing scroll fixture extents keep the pool visibly bounded.
TEST_CANVAS_VIEWPORT_PHOTO_COUNT = TEST_SCROLL_PHOTO_COUNT * 2

# Purpose: Send a short but coalescible run of genuine wheel events.
# Type: int. Units: wheel events. Scope: opt-in Canvas viewport regression.
# Consumers: NativeGalleryCanvasIntegrationTests.test_canvas_opt_in_pages_and_coalesces_rapid_viewport_scroll.
# Rationale: Six events exercise repeated admission without a long synthetic trace.
TEST_CANVAS_WHEEL_BURST_COUNT = 6

# Purpose: Match one conventional Windows wheel notch in generated input.
# Type: int. Units: Tk wheel delta units. Scope: mapped scroll test.
# Consumers:
#   NativeGalleryViewerTests.test_real_touchpad_packet_scrolls_grid_and_zoom.
# Rationale: Tk represents a standard wheel notch as 120 signed units.
TEST_WHEEL_DELTA_UNITS = 120

# Purpose: Measure a small high-resolution touchpad gesture precisely.
# Type: int. Units: pixels. Scope: mapped grid scroll test.
# Consumers:
#   NativeGalleryViewerTests.test_real_touchpad_packet_scrolls_grid_and_zoom.
# Rationale: 24 pixels is observable while still exposing proportional overscroll.
TEST_TOUCHPAD_SCROLL_PIXELS = 24

# Purpose: Keep a warm-cache slice fixture above one production GUI slice.
# Type: int. Units: extra photos. Scope: isolated GUI-slice regression.
# Consumers: test_warm_thumbnail_cache_copies_use_bounded_gui_slices.
# Rationale: Two extra entries prove continuation without enlarging production.
TEST_WARM_SLICE_EXTRA_PHOTOS = 2

# Purpose: Allow pixel rounding when measuring normalized canvas movement.
# Type: int. Units: pixels. Scope: precise scroll assertion.
# Consumers:
#   NativeGalleryViewerTests.test_real_touchpad_packet_scrolls_grid_and_zoom.
# Rationale: Tk fractions can round to the nearest canvas coordinate.
TEST_TOUCHPAD_SCROLL_TOLERANCE = 2

# Purpose: Cross the lightbox's precise touchpad zoom threshold once.
# Type: int. Units: packed vertical delta units. Scope: mapped zoom test.
# Consumers:
#   NativeGalleryViewerTests.test_real_touchpad_packet_scrolls_grid_and_zoom.
# Rationale: Eight units are one configured high-resolution zoom step.
TEST_TOUCHPAD_ZOOM_DELTA_UNITS = 8

# Purpose: Set the admitted synthetic location used by OSM URL assertions.
# Type: tuple[float, float]. Units: decimal degrees. Scope: location lightbox.
# Consumers: NativeGalleryViewerTests.test_location_pin_and_map_action.
# Rationale: These valid coordinates are stable, non-sensitive fixture values.
TEST_PHOTO_LOCATION = (48.123456, 16.654321)

# Purpose: Provide enough pixels to distinguish thumbnail and detail rendering.
# Type: tuple[int, int]. Units: pixels. Scope: generated synthetic PNG bodies.
# Consumers: NativeGalleryViewerTests._png.
# Rationale: A small image verifies actual decode without unnecessary payload.
TEST_SYNTHETIC_IMAGE_SIZE = (24, 16)

# Purpose: Exercise draft decoding with a mocked 48 MP camera raster.
# Type: tuple[int, int]. Units: source pixels. Scope: safe JPEG draft fixture.
# Consumers: mocked and real oversized-JPEG decode regressions.
# Rationale: 8000 by 6000 exceeds policy; only the real fixture allocates RGB.
TEST_LARGE_JPEG_SOURCE_SIZE = (8000, 6000)

# Purpose: Exercise a real JPEG above output bounds but below pixel policy.
# Type: tuple[int, int]. Units: source pixels. Scope: detail draft regression.
# Consumers: NativeGalleryViewerTests.test_medium_jpeg_drafts_before_rgb_conversion.
# Rationale: 4096 by 3072 is twice each output dimension, allowing real JPEG
# decoder half-scaling while remaining below the unchanged 36 MP policy.
TEST_MEDIUM_JPEG_SOURCE_SIZE = (4096, 3072)

# Purpose: Inject a compact threshold for a real Pillow JPEG draft regression.
# Type: int. Units: pixels. Scope: small synthetic JPEG boundary test only.
# Consumers: test_real_jpeg_draft_uses_reduced_decoder_raster.
# Rationale: 100 by 80 is over this limit and draft can reduce it before RGB.
TEST_DECODE_PIXEL_BOUNDARY = 5000

# Purpose: Request decoder downsampling for the small real-JPEG test boundary.
# Type: tuple[int, int]. Units: pixels. Scope: synthetic JPEG draft test only.
# Consumers: test_real_jpeg_draft_uses_reduced_decoder_raster.
# Rationale: Pillow's 1/4 JPEG draft fits this target and pixel boundary.
TEST_DECODE_DRAFT_TARGET = (20, 16)

# Purpose: Represent a wide photo surrounded by removable black side mattes.
# Type: tuple[int, int]. Units: pixels. Scope: thumbnail framing regression.
# Consumers: NativeGalleryViewerTests thumbnail framing regressions.
# Rationale: The 21:9 interior tests both-edge trimming before natural resizing.
TEST_WIDE_PHOTO_SIZE = (250, 90)

# Purpose: Set the synthetic paired black-band width in the wide raster.
# Type: int. Units: pixels. Scope: thumbnail framing regression.
# Consumers: NativeGalleryViewerTests.test_wide_thumbnail_trims_mattes_and_
# preserves_full_scene.
# Rationale: A 20 px band is clear while the 210 px interior remains 21:9.
TEST_WIDE_PHOTO_MATTE = 20

# Purpose: Define the expected natural-aspect raster for a 210 by 90 interior.
# Type: tuple[int, int]. Units: pixels. Scope: wide thumbnail framing tests.
# Consumers: NativeGalleryViewerTests wide thumbnail framing regressions.
# Rationale: A 144 px width bound scales the 21:9 photo to 144 by 62 pixels.
TEST_NATURAL_WIDE_THUMBNAIL_SIZE = (144, 62)

# Purpose: Leave time to inspect one manual advance before its next timer.
# Type: int. Units: seconds. Scope: synthetic presentation response.
# Consumers: NativeGalleryViewerTests._gallery_data.
# Rationale: Ten seconds is a valid server preset and exceeds the GUI wait.
TEST_PRESENTATION_INTERVAL_SECONDS = 10

# Purpose: Supply one metadata-only track to the inline chooser fixture.
# Type: int. Units: bytes. Scope: test-only unplayed track descriptor.
# Consumers: NativeGalleryViewerTests.test_music_chooser_is_inline.
# Rationale: One declared byte avoids unnecessary track loading in a panel test.
TEST_PANEL_TRACK_BYTES = 1

# Purpose: Produce a short PCM waveform without playing audible content.
# Type: int. Units: samples per second. Scope: synthetic silent WAV.
# Consumers: test_silent_wav_playback_releases_owned_file.
# Rationale: 8000 is supported by Windows waveaudio and keeps the fixture small.
TEST_WAV_RATE_HZ = 8000

# Purpose: Name the two synthetic gallery rows and their physical owner.
# Type: int. Units: gallery/image identifiers. Scope: synthetic Tk fixture.
# Consumers: NativeGalleryViewerTests test setup and assertions.
# Rationale: Stable positive IDs make request routing assertions readable.
TEST_GALLERY_ID = 44
TEST_PARENT_GALLERY_ID = 12
TEST_FIRST_IMAGE_ID = 301
TEST_SECOND_IMAGE_ID = 302
TEST_NEXT_PAGE_IMAGE_ID = 303

# Purpose: Exercise Canvas focus, selection and reordered image retention together.
# Type: int. Units: synthetic photo records. Scope: one opt-in viewer regression.
# Consumers: NativeGalleryCanvasIntegrationTests.
# Rationale: Three records make keyboard movement and reorder reuse observable.
TEST_CANVAS_GRID_PHOTO_COUNT = 3


@unittest.skipIf(
    native_gallery_viewer is None,
    'Decoded thumbnail policy requires the optional Tk runtime.',
)
class GalleryThumbnailMemoryPolicyTests(unittest.TestCase):
    """Verify decoded thumbnail capacity is configured without reservation."""

    def test_shared_capacity_is_exact_and_lazy(self) -> None:
        """Pin the larger shared LRU policy while its initial state stays empty.

        Returns:
            None: The policy values are exact and no raster bytes are reserved.
        """
        self.assertEqual(
            TEST_EXPECTED_THUMBNAIL_CACHE_ENTRY_LIMIT,
            native_gallery_viewer.MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT,
        )
        self.assertEqual(
            TEST_EXPECTED_THUMBNAIL_CACHE_MAX_BYTES,
            native_gallery_viewer.MEDIA_THUMBNAIL_CACHE_MAX_BYTES,
        )
        memory = native_gallery_viewer.GalleryThumbnailMemory()
        self.assertEqual({}, memory.entries)
        self.assertEqual({}, memory.identity_keys)
        self.assertEqual(0, memory.size_bytes)
        self.assertEqual(
            {
                'hits': 0, 'misses': 0, 'evictions': 0, 'bytes': 0,
                'entries': 0, 'admission_refusals': 0, 'expirations': 0,
                'validator_changes': 0,
            },
            memory.metrics_snapshot(),
        )
        memory.clear()


@unittest.skipUnless(
    sys.platform == 'win32' and tk is not None and Image is not None
    and ImageTk is not None,
    'The native viewer regression requires Windows, Tk and Pillow.',
)
class NativeGalleryViewerTests(unittest.TestCase):
    """Exercise mounted Tk frames and synthetic media responses."""

    def setUp(self) -> None:
        """Use the app's Windows display bootstrap before a high-DPI Tk root.

        Returns:
            None: A mapped root with native display coordinates is available
                to each native viewer test, with owner-thread cycle cleanup.
        """
        # The fixture pumps update() instead of staying inside mainloop().
        # Let the Tk thread collect unreachable widget cycles after teardown,
        # so worker allocation cannot finalize Tcl variables off-thread.
        if gc.isenabled():
            gc.disable()
            self.addCleanup(gc.enable)
        self.addCleanup(gc.collect)

        # These widget tests inject storage observations explicitly. Avoid
        # starting the background reader against the runner's real profile.
        storage_poll = mock.patch.object(
            photos_workspace.PhotosWorkspace, '_poll_storage', autospec=True,
        )
        self._storage_poll_callback = (
            photos_workspace.PhotosWorkspace._poll_storage
        )
        storage_poll.start()
        self.addCleanup(storage_poll.stop)
        prepare_windows_display()
        self.root = tk.Tk()
        self.root.tk.call('tk', 'scaling', TEST_TK_SCALING)
        # Fixed work-area origin keeps native captures clear of the taskbar.
        self.root.geometry('840x620+0+0')
        self.root.update()
        self.addCleanup(self._destroy_root)

    def test_saved_flight_direct_actions_filter_and_revision_picker(self) -> None:
        """Capture the real saved-flight UI in three native Windows states.

        Returns:
            None: Shows the watched destination, verifies offline action
                gating and filters a photo-only session, then selects two
                retained revisions. Saves the resulting CI screenshots.
        """
        configure_theme(self.root)
        evidence = WINAPP_DIR.parent / "cache" / "test-audit" / "saved-flight-reports"
        evidence.mkdir(parents=True, exist_ok=True)

        def capture(filename: str) -> None:
            """Save the mapped native app area after processing placement.

            Args:
                filename (str): PNG basename within the CI evidence directory.
            Returns:
                None: Captures the actual window content at current dimensions.
            """
            self.root.update()
            x, y = self.root.winfo_rootx(), self.root.winfo_rooty()
            bounds = (x, y, x + self.root.winfo_width(), y + self.root.winfo_height())
            with ImageGrab.grab(bbox=bounds) as image:
                image.save(evidence / filename)

        with TemporaryDirectory() as temporary:
            directory = Path(temporary)
            for name, aircraft, count, day in (
                ("saved-flight", "Carenado C337 Skymaster", 1800, 24),
                ("photos-only", "", 0, 23),
            ):
                session = directory / name
                session.mkdir()
                plan = SessionPlan(session, "a" * 64, ("en", "cs"), False)
                plan.save()
                (session / "session.json").write_text(json.dumps({
                    "started_utc": f"2026-11-{day}T12:33:40+00:00",
                    "aircraft": aircraft, "sample_count": count,
                    "recording_ended": True, "gallery_identity": "Fotky z létání",
                }), encoding="utf-8")
                if aircraft:
                    # Two prior revisions enable true multiselection. One page
                    # per language is sufficient for the file-creation column.
                    for revision in ("first", "second"):
                        edition = allocate_report_revision(session, revision, plan.languages)
                        for path in edition.files.values():
                            writer = PdfWriter()
                            # Letter dimensions in PDF points match a real page.
                            writer.add_blank_page(width=612, height=792)
                            writer.write(path)

            target = WatchedReportTarget("Praha / Večerní lety")
            host = types.SimpleNamespace(
                root=self.root, events=queue.Queue(), worker=None,
                watched_report_target=mock.Mock(return_value=target),
                simulator_presence=types.SimpleNamespace(
                    text=lambda key, locale: tr(locale, key),
                ),
                open_log_file=mock.Mock(),
            )
            with mock.patch.object(self.root, "after", return_value="fixture-no-tick"):
                ui = FlightUI(host, directory, str, mock.Mock(), mock.Mock())
            self.assertIsNotNone(ui.lease, ui.load_error)
            try:
                ui.ui_language.set("cs")
                ui.show_reports()
                self.root.update()
                self.assertFalse(ui.report_flights_only.get())
                self.assertEqual(("saved-flight", "photos-only"), ui.report_tree.get_children())
                self.assertEqual(str(ui.report_upload_button.cget("state")), "normal")
                self.assertEqual(target.label, ui.report_target_var.get())
                self.assertEqual(ui.report_button.winfo_rooty(), ui.report_upload_button.winfo_rooty())
                samples_x, _, samples_width, _ = ui.report_tree.bbox("saved-flight", "samples")
                self.assertLessEqual(samples_x + samples_width, ui.report_tree.winfo_width())
                capture("direct-actions-desktop.png")

                self.root.geometry("460x540+0+0")
                host.watched_report_target.return_value = None
                ui.report_flights_only.set(True)
                ui.refresh_reports()
                self.root.update()
                self.assertEqual(("saved-flight",), ui.report_tree.get_children())
                self.assertEqual(str(ui.report_button.cget("state")), "normal")
                self.assertEqual(str(ui.report_upload_button.cget("state")), "disabled")
                pdfs_x, _, pdfs_width, _ = ui.report_tree.bbox("saved-flight", "pdfs")
                self.assertLessEqual(pdfs_x + pdfs_width, ui.report_tree.winfo_width())
                for button in (ui.report_button, ui.report_upload_button):
                    self.assertGreaterEqual(button.winfo_width(), button.winfo_reqwidth())
                    self.assertLessEqual(
                        button.winfo_rootx() + button.winfo_width(),
                        self.root.winfo_rootx() + self.root.winfo_width(),
                    )
                capture("flight-filter-watcher-off-narrow.png")

                host.watched_report_target.return_value = target
                ui.upload_saved_pdf()
                picker = active_panel(self.root)
                self.assertIsNot(picker, ui.report_dialog)
                card = picker.winfo_children()[0]
                viewport = next(child for child in card.winfo_children() if child.winfo_class() == "TFrame")
                revisions = next(widget for widget in viewport.winfo_children()
                                 if isinstance(widget, ttk.Treeview))
                self.assertEqual(("second", "first"), revisions.get_children())
                self.assertEqual(("second",), revisions.selection())
                revisions.selection_set(revisions.get_children())
                self.assertEqual(("second", "first"), revisions.selection())
                capture("select-revisions-narrow.png")
                picker.destroy()
            finally:
                if ui.report_dialog is not None and ui.report_dialog.winfo_exists():
                    ui.report_dialog.destroy()
                ui.lease.release()

    def _destroy_root(self) -> None:
        """Destroy the private Tk interpreter after canceling its timers.

        Returns:
            None: No fixture timer survives to run during the next Tk root.
        """
        try:
            if self.root.winfo_exists():
                # Cancel the Tcl timers without deleting Python callback
                # registrations owned by the widgets being destroyed.
                for timer in self.root.tk.splitlist(self.root.tk.call('after', 'info')):
                    self.root.tk.call('after', 'cancel', timer)
                self.root.destroy()
        except tk.TclError:
            pass

    def _wait_until(self, condition: Callable[[], bool], message: str) -> None:
        """Pump Tk until a worker-backed condition succeeds or times out.

        Args:
            condition (Callable[[], bool]): Side-effect-free readiness predicate.
            message (str): Safe assertion context shown if the deadline expires.

        Returns:
            None: Tk events are serviced until the condition becomes true.
        """
        deadline = time.monotonic() + TEST_GUI_WAIT_SECONDS
        while time.monotonic() < deadline:
            self.root.update()
            if condition():
                return
            time.sleep(TEST_GUI_POLL_SECONDS)
        self.fail(message)

    def _double_click_tile(self, tile: 'tk.Widget') -> None:
        """Open a tile through two complete, timed Tk clicks.

        Args:
            tile (tk.Widget): Mounted gallery button receiving both clicks.

        Returns:
            None: The second click activates the existing lightbox binding.
        """
        for click_time in TEST_DOUBLE_CLICK_TIMES_MS:
            tile.event_generate(
                '<ButtonPress-1>', x=4, y=4, time=click_time,
            )
            tile.event_generate(
                '<ButtonRelease-1>', x=4, y=4, time=click_time,
            )

    def test_media_failure_log_omits_exception_message(self) -> None:
        """Keep sensitive exception text out of Photos diagnostics.

        Returns:
            None: Logs retain the operation and exception class only.
        """
        error = RuntimeError("secret URL token=private gallery title")
        with self.assertLogs(native_gallery_viewer.__name__, level="WARNING") as captured:
            native_gallery_viewer._log_safe_media_failure(
                "page-fetch", "page", error,
            )

        output = "\n".join(captured.output)
        self.assertIn("phase=page-fetch kind=page", output)
        self.assertIn("error_type=RuntimeError", output)
        self.assertNotIn("secret URL", output)
        self.assertNotIn("private gallery title", output)

    def test_thumbnail_transport_and_decode_failures_are_distinguished(self) -> None:
        """Retain safe response facts while separating decode failures.

        Returns:
            None: A media HTTP refusal keeps status and reason, while a local
                decode error gets its own phase and controlled timing; neither
                retains secret text.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = native_gallery_viewer._NativeGalleryViewer.__new__(
            native_gallery_viewer._NativeGalleryViewer,
        )
        viewer.session = session
        viewer.gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Album')
        viewer._thumbnail_cancelled = threading.Event()
        viewer._cancelled = threading.Event()
        summaries: list[str] = []
        viewer._diagnostic_callback = summaries.append
        secret = 'https://private.example/photo?token=secret-body'
        response_error = LinkingResponseError(
            'media', 'app_linking_media', 504, 'html', 'http_status',
        )
        response_error.args = (secret,)
        session.media_photo.side_effect = response_error

        with self.assertLogs(
            native_gallery_viewer.__name__, level='WARNING',
        ) as captured:
            self.assertEqual((0, None, 0.0), viewer._load_thumbnails(page.photos, 0))

        self.assertIn('phase=media-fetch kind=thumbnail', captured.output[0])
        self.assertIn('http=504', summaries[0])
        self.assertIn('reason=http_status', summaries[0])
        self.assertNotIn(secret, summaries[0])

        session.media_photo.side_effect = None
        session.media_photo.return_value = (b'image-bytes', 'image/jpeg')
        viewer._prepare_thumbnail = mock.Mock(side_effect=ValueError(secret))
        with self.assertLogs(
            native_gallery_viewer.__name__, level='WARNING',
        ) as captured, mock.patch.object(
            native_gallery_viewer.time, 'perf_counter',
            side_effect=(0.0, 0.003),
        ):
            result = viewer._load_thumbnails(page.photos, 0)
            self.assertEqual((0, None), result[:2])
            self.assertAlmostEqual(3.0, result[2])

        self.assertIn('phase=media-decode kind=thumbnail', captured.output[0])
        self.assertNotIn(secret, summaries[-1])

        def fail_diagnostic_sink(summary: str) -> None:
            """Raise secret-bearing sink text to prove it cannot mask fallback.

            Args:
                summary (str): Safe summary delivered by the media failure owner.

            Returns:
                None: Always raises a local sink error for this fixture.
            """
            raise RuntimeError(secret)

        session.media_photo.side_effect = RuntimeError(secret)
        viewer._diagnostic_callback = fail_diagnostic_sink
        with self.assertLogs(
            native_gallery_viewer.__name__, level='WARNING',
        ) as captured:
            self.assertEqual((0, None, 0.0), viewer._load_thumbnails(page.photos, 0))
        output = '\n'.join(captured.output)
        self.assertIn('error_type=RuntimeError', output)
        self.assertIn('diagnostic retention callback failed', output)
        self.assertNotIn(secret, output)

    def test_full_detail_requests_saved_snapshot_but_thumbnail_does_not(self) -> None:
        """Use the saved full-body mode only for native detail reads.

        Returns:
            None: A snapshot-backed detail records a disk source without HTTP;
                the thumbnail request keeps the default network read mode.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = native_gallery_viewer._NativeGalleryViewer.__new__(
            native_gallery_viewer._NativeGalleryViewer,
        )
        viewer.session = session
        viewer.gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Snapshot detail')
        viewer._cancelled = threading.Event()
        photo = page.photos[0]
        requested_modes: list[tuple[bool, bool]] = []

        def read_snapshot_or_thumbnail(
            gallery_id: int,
            image_id: int,
            thumbnail: bool,
            cancelled: threading.Event | None = None,
            *,
            cache_eligible: bool | None = None,
            thumbnail_etag: str | None = None,
            thumbnail_verified_at: float | None = None,
            preload_eligible: bool | None = None,
            preload_thumbnail_etag: str | None = None,
            preload_verified_at: float | None = None,
            timings: MediaReadTimings | None = None,
            use_preloaded_snapshot: bool = False,
        ) -> tuple[bytes, str]:
            """Return a verified saved body or a synthetic thumbnail response.

            Args:
                gallery_id (int): Requested physical gallery ID.
                image_id (int): Requested synthetic photo ID.
                thumbnail (bool): Whether the bounded preview was requested.
                cancelled (threading.Event | None): Optional read cancellation.
                cache_eligible (bool | None): Public cache admission metadata.
                thumbnail_etag (str | None): Current thumbnail validator.
                thumbnail_verified_at (float | None): Catalogue proof time.
                preload_eligible (bool | None): Full-preload admission metadata.
                preload_thumbnail_etag (str | None): Full-preload validator.
                preload_verified_at (float | None): Full-preload proof time.
                timings (MediaReadTimings | None): Mutable source observation.
                use_preloaded_snapshot (bool): Whether an authorized full copy may
                    be returned without a transport request.

            Returns:
                tuple[bytes, str]: Controlled synthetic PNG and media type.
            """
            self.assertEqual(TEST_GALLERY_ID, gallery_id)
            self.assertEqual(photo.image_id, image_id)
            requested_modes.append((thumbnail, use_preloaded_snapshot))
            if timings is not None:
                if use_preloaded_snapshot and not thumbnail:
                    timings.source = 'disk'
                    timings.cache_state = 'hit'
                else:
                    timings.source = 'network'
                    timings.http_requests += 1
                    timings.encoded_bytes += len(image_bytes[image_id])
            return image_bytes[image_id], 'image/png'

        session.media_photo.side_effect = read_snapshot_or_thumbnail
        full_timings = MediaReadTimings()
        body, _mime = viewer._read_photo_body(
            photo, thumbnail=False, timings=full_timings,
        )
        self.assertEqual(image_bytes[photo.image_id], body)
        self.assertEqual('disk', full_timings.source)
        self.assertEqual(0, full_timings.http_requests)
        self.assertEqual(0, full_timings.encoded_bytes)

        thumbnail_timings = MediaReadTimings()
        viewer._read_photo_body(
            photo, thumbnail=True, timings=thumbnail_timings,
        )
        self.assertEqual([(False, True), (True, False)], requested_modes)
        self.assertEqual('network', thumbnail_timings.source)
        self.assertEqual(1, thumbnail_timings.http_requests)
        self.assertGreater(thumbnail_timings.encoded_bytes, 0)

    def test_large_jpeg_drafts_before_rgb_conversion(self) -> None:
        """Draft oversized JPEG data before Pillow creates the RGB raster.

        Returns:
            None: The mocked 48 MP header is reduced below policy before the
                conversion step and only bounded numeric facts are retained.
        """
        events: list[tuple[str, tuple[int, int]]] = []
        source = mock.MagicMock()
        source.__enter__.return_value = source
        source.__exit__.return_value = False
        source.format = 'JPEG'
        source.size = TEST_LARGE_JPEG_SOURCE_SIZE
        source.width, source.height = TEST_LARGE_JPEG_SOURCE_SIZE
        converted = Image.new('RGB', (48, 36), (30, 50, 70))
        self.addCleanup(converted.close)
        summaries: list[str] = []

        def draft(mode: str, target: tuple[int, int]) -> None:
            """Apply the simulated decoder reduction before conversion.

            Args:
                mode (str): Pillow target mode requested by the viewer.
                target (tuple[int, int]): Existing normalized detail bounds.

            Returns:
                None: The mocked decoder reports a 3 MP source raster.
            """
            self.assertEqual('RGB', mode)
            self.assertEqual(native_gallery_viewer.MEDIA_IMAGE_MAX_SIZE, target)
            events.append(('draft', source.size))
            source.size = (2000, 1500)
            source.width, source.height = source.size

        def convert(mode: str) -> 'Image.Image':
            """Return a small real raster after the mocked draft event.

            Args:
                mode (str): RGB conversion requested by the decoder owner.

            Returns:
                Image.Image: Small in-memory image used only by this fixture.
            """
            events.append(('convert', source.size))
            return converted.copy()

        source.draft.side_effect = draft
        source.convert.side_effect = convert
        viewer = native_gallery_viewer._NativeGalleryViewer.__new__(
            native_gallery_viewer._NativeGalleryViewer,
        )
        viewer._observation_callback = summaries.append
        with mock.patch.object(native_gallery_viewer.Image, 'open', return_value=source):
            image = viewer._prepare_detail(b'bounded synthetic JPEG body')
        image.close()

        self.assertEqual(
            [('draft', TEST_LARGE_JPEG_SOURCE_SIZE),
             ('convert', (2000, 1500))],
            events,
        )
        self.assertIn('source=8000x6000 decoded=2000x1500', summaries[0])
        self.assertIn('max_pixels=36000000', summaries[0])
        self.assertNotIn('JPEG body', summaries[0])

    def test_real_48mp_jpeg_is_reduced_before_rgb_conversion(self) -> None:
        """Decode a real 48 MP JPEG through Pillow's bounded draft path.

        Returns:
            None: The actual source is reduced before RGB conversion and the
                resulting image remains within the existing output bounds.
        """
        source_image = Image.new(
            'RGB', TEST_LARGE_JPEG_SOURCE_SIZE, (40, 70, 100),
        )
        body_stream = io.BytesIO()
        source_image.save(body_stream, format='JPEG', quality=70)
        source_image.close()
        observed_sizes: list[tuple[int, int]] = []
        summaries: list[str] = []
        original_convert = Image.Image.convert

        def record_convert(
            image: 'Image.Image', mode: str,
        ) -> 'Image.Image':
            """Record Pillow's decoder raster before real RGB conversion.

            Args:
                image (Image.Image): Open JPEG source image.
                mode (str): RGB mode requested by the detail decoder.

            Returns:
                Image.Image: Real Pillow RGB conversion result.
            """
            observed_sizes.append(image.size)
            return original_convert(image, mode)

        viewer = native_gallery_viewer._NativeGalleryViewer.__new__(
            native_gallery_viewer._NativeGalleryViewer,
        )
        viewer._observation_callback = summaries.append
        with mock.patch.object(Image.Image, 'convert', record_convert):
            image = viewer._prepare_detail(body_stream.getvalue())
        output_size = image.size
        image.close()

        self.assertEqual(1, len(observed_sizes))
        self.assertLessEqual(
            observed_sizes[0][0] * observed_sizes[0][1],
            native_gallery_viewer.MEDIA_IMAGE_MAX_PIXELS,
        )
        self.assertLessEqual(output_size[0], 2048)
        self.assertLessEqual(output_size[1], 1536)
        self.assertIn('source=8000x6000', summaries[0])
        self.assertNotIn('40, 70, 100', summaries[0])

    def test_medium_jpeg_drafts_before_rgb_conversion(self) -> None:
        """Downsample a sub-policy JPEG before allocating its RGB raster.

        Returns:
            None: The real 12 MP source is reduced by Pillow draft before
                convert, with unchanged output bounds and safe size facts.
        """
        source_image = Image.new(
            'RGB', TEST_MEDIUM_JPEG_SOURCE_SIZE, (65, 85, 105),
        )
        body_stream = io.BytesIO()
        source_image.save(body_stream, format='JPEG', quality=75)
        source_image.close()
        observed_sizes: list[tuple[int, int]] = []
        summaries: list[str] = []
        original_convert = Image.Image.convert

        def record_convert(
            image: 'Image.Image', mode: str,
        ) -> 'Image.Image':
            """Capture the decoder dimensions immediately before conversion.

            Args:
                image (Image.Image): Open JPEG source after draft processing.
                mode (str): RGB mode requested by detail decoding.

            Returns:
                Image.Image: The actual Pillow RGB conversion result.
            """
            observed_sizes.append(image.size)
            return original_convert(image, mode)

        viewer = native_gallery_viewer._NativeGalleryViewer.__new__(
            native_gallery_viewer._NativeGalleryViewer,
        )
        viewer._observation_callback = summaries.append
        started = time.perf_counter()
        with mock.patch.object(Image.Image, 'convert', record_convert):
            image = viewer._prepare_detail(body_stream.getvalue())
        preparation_ms = (time.perf_counter() - started) * 1000.0
        output_size = image.size
        image.close()

        self.assertEqual(1, len(observed_sizes))
        decoded_size = observed_sizes[0]
        self.assertLess(
            decoded_size[0] * decoded_size[1],
            TEST_MEDIUM_JPEG_SOURCE_SIZE[0] * TEST_MEDIUM_JPEG_SOURCE_SIZE[1],
        )
        self.assertLessEqual(
            decoded_size[0] * decoded_size[1],
            native_gallery_viewer.MEDIA_IMAGE_MAX_PIXELS,
        )
        self.assertLessEqual(output_size[0], 2048)
        self.assertLessEqual(output_size[1], 1536)
        self.assertTrue(any(
            'decode stage=draft reason=reduced format=JPEG' in summary
            and 'source=4096x3072' in summary
            for summary in summaries
        ))
        evidence = WINAPP_DIR.parent / 'cache' / 'test-audit'
        evidence.mkdir(parents=True, exist_ok=True)
        (evidence / 'photos-detail-decode-measurement.json').write_text(
            json.dumps({
                'data_source': 'isolated synthetic JPEG in memory',
                'source_size': TEST_MEDIUM_JPEG_SOURCE_SIZE,
                'rgb_input_size': decoded_size,
                'output_size': output_size,
                'encoded_bytes': len(body_stream.getvalue()),
                'preparation_ms': preparation_ms,
                'network_requests': 0,
                'previous_rgb_input_pixels': (
                    TEST_MEDIUM_JPEG_SOURCE_SIZE[0]
                    * TEST_MEDIUM_JPEG_SOURCE_SIZE[1]
                ),
            }, indent=2) + '\n', encoding='utf-8',
        )

    def test_real_jpeg_draft_uses_reduced_decoder_raster(self) -> None:
        """Exercise Pillow's real JPEG draft with a compact injected boundary.

        Returns:
            None: A real source larger than the test boundary decodes through
                JPEG subsampling and returns a bounded RGB image.
        """
        source_image = Image.new('RGB', (100, 80), (70, 90, 110))
        body_stream = io.BytesIO()
        source_image.save(body_stream, format='JPEG')
        source_image.close()
        summaries: list[str] = []
        viewer = native_gallery_viewer._NativeGalleryViewer.__new__(
            native_gallery_viewer._NativeGalleryViewer,
        )
        viewer._observation_callback = summaries.append
        with (
            mock.patch.object(
                native_gallery_viewer, 'MEDIA_IMAGE_MAX_PIXELS',
                TEST_DECODE_PIXEL_BOUNDARY,
            ),
            mock.patch.object(
                native_gallery_viewer, 'MEDIA_IMAGE_MAX_SIZE',
                TEST_DECODE_DRAFT_TARGET,
            ),
        ):
            image = viewer._prepare_detail(body_stream.getvalue())
        self.addCleanup(image.close)

        self.assertLessEqual(
            image.width * image.height, TEST_DECODE_PIXEL_BOUNDARY,
        )
        self.assertLessEqual(image.width, TEST_DECODE_DRAFT_TARGET[0])
        self.assertLessEqual(image.height, TEST_DECODE_DRAFT_TARGET[1])
        self.assertTrue(any(
            'decode stage=draft reason=reduced format=JPEG' in summary
            for summary in summaries
        ))

    def test_oversized_non_jpeg_refuses_before_full_conversion(self) -> None:
        """Refuse oversized unsupported rasters before RGB allocation.

        Returns:
            None: The typed pixel refusal contains numeric facts and the decoder
                never calls the full-raster conversion method.
        """
        source = mock.MagicMock()
        source.__enter__.return_value = source
        source.__exit__.return_value = False
        source.format = 'PNG'
        source.size = TEST_LARGE_JPEG_SOURCE_SIZE
        source.width, source.height = TEST_LARGE_JPEG_SOURCE_SIZE
        viewer = native_gallery_viewer._NativeGalleryViewer.__new__(
            native_gallery_viewer._NativeGalleryViewer,
        )
        with mock.patch.object(native_gallery_viewer.Image, 'open', return_value=source):
            with self.assertRaises(native_gallery_viewer.PhotosDecodeError) as raised:
                viewer._prepare_detail(b'oversized PNG fixture')

        source.convert.assert_not_called()
        self.assertEqual('pixel-limit', raised.exception.facts.reason)
        self.assertEqual(TEST_LARGE_JPEG_SOURCE_SIZE, raised.exception.facts.source_size)

    def test_decode_refusal_facts_are_safe_and_render_a_specific_reason(self) -> None:
        """Keep malformed decoder details numeric and the UI explanation clear.

        Returns:
            None: Logs omit source bytes, while the lightbox receives a localized
                safe decode-specific message rather than the generic fetch error.
        """
        secret = 'private path C:\\photos\\secret.jpg token=private'
        viewer = native_gallery_viewer._NativeGalleryViewer.__new__(
            native_gallery_viewer._NativeGalleryViewer,
        )
        with self.assertRaises(native_gallery_viewer.PhotosDecodeError) as raised:
            viewer._prepare_detail(secret.encode('utf-8'))
        with self.assertLogs(
            native_gallery_viewer.__name__, level='WARNING',
        ) as captured:
            summary = native_gallery_viewer._log_safe_media_failure(
                'media-decode', 'detail', raised.exception,
            )
        output = '\n'.join(captured.output) + summary
        self.assertIn('phase=media-decode kind=detail', output)
        self.assertIn('decode stage=source-open reason=decode-failed', output)
        self.assertIn('body_bytes=', output)
        self.assertNotIn(secret, output)

        viewer._detail_serial = 5
        viewer._lightbox = mock.Mock()
        viewer._slideshow_running = False
        viewer._show_detail(5, 7, None, None, None, 'pixel-limit')
        viewer._lightbox.show_error.assert_called_once_with(
            viewer._text('linking_media_decode_error'),
        )

    def test_pixel_limit_fallback_uses_only_authorized_thumbnail_once(self) -> None:
        """Use one normal session-gated thumbnail read after pixel refusal.

        Returns:
            None: Transport/auth/cancel/corruption failures do not trigger a
                fallback, and a preview is never retained as original detail.
                Saved full-version intent differs by rendition while all
                authorization, cancellation and catalogue proof inputs match.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Preview fallback'),
        )
        observations: list[str] = []
        viewer._observation_callback = observations.append
        self._wait_until(
            lambda: viewer._worker is None,
            'The fixture viewer did not finish its initial page and thumbnails.',
        )
        session.media_photo.reset_mock()
        session.media_photo.side_effect = None
        session.media_photo.return_value = (b'authorized image body', 'image/jpeg')
        preview = Image.new('RGB', (16, 12), (10, 30, 50))
        self.addCleanup(preview.close)
        refusal = native_gallery_viewer.PhotosDecodeError(
            native_gallery_viewer.PhotosDecodeFacts(
                'pixel-check', 'pixel-limit', 'PNG',
                TEST_LARGE_JPEG_SOURCE_SIZE, TEST_LARGE_JPEG_SOURCE_SIZE,
                1024, native_gallery_viewer.MEDIA_IMAGE_MAX_PIXELS,
                native_gallery_viewer.MEDIA_IMAGE_MAX_SIZE,
            ),
        )
        with mock.patch.object(
            viewer, '_prepare_detail', side_effect=[refusal, preview],
        ):
            image, encoded = viewer._read_prepared_detail(page.photos[0])
        self.assertIs(preview, image)
        self.assertIsNone(encoded)
        self.assertTrue(any(
            'decode stage=pixel-check reason=pixel-limit' in item
            for item in observations
        ))
        self.assertTrue(any(
            'reason=preview-fallback' in item for item in observations
        ))
        self.assertEqual(2, session.media_photo.call_count)
        full_call, preview_call = session.media_photo.call_args_list
        self.assertEqual(full_call.args, preview_call.args)
        self.assertIs(full_call.kwargs['cancelled'], viewer._cancelled)
        self.assertIs(preview_call.kwargs['cancelled'], viewer._cancelled)
        self.assertFalse(full_call.kwargs['thumbnail'])
        self.assertTrue(preview_call.kwargs['thumbnail'])
        full_policy = dict(full_call.kwargs)
        preview_policy = dict(preview_call.kwargs)
        full_policy.pop('thumbnail')
        preview_policy.pop('thumbnail')
        self.assertTrue(full_policy.pop('use_preloaded_snapshot'))
        self.assertFalse(preview_policy.pop('use_preloaded_snapshot'))
        self.assertEqual(full_policy, preview_policy)

        session.media_photo.reset_mock()
        session.media_photo.side_effect = LinkingMediaThrottled()
        with self.assertRaises(LinkingMediaThrottled):
            viewer._read_prepared_detail(page.photos[0])
        session.media_photo.assert_called_once()

        session.media_photo.reset_mock()
        session.media_photo.side_effect = LinkingResponseError(
            'media', 'app_linking_media', 401, 'json', 'http_status',
        )
        with self.assertRaises(LinkingResponseError):
            viewer._read_prepared_detail(page.photos[0])
        session.media_photo.assert_called_once()

        session.media_photo.reset_mock()
        session.media_photo.side_effect = None
        with mock.patch.object(
            viewer, '_prepare_detail', side_effect=ValueError('corrupt image'),
        ):
            with self.assertRaises(ValueError):
                viewer._read_prepared_detail(page.photos[0])
        session.media_photo.assert_called_once()

        session.media_photo.reset_mock()
        viewer._cancelled.clear()

        def cancel_during_decode(_body: bytes) -> 'Image.Image':
            """Cancel before refusing pixels to block the preview retry.

            Args:
                _body (bytes): First authorized full-photo body.

            Returns:
                Image.Image: Unreachable because a typed refusal is raised.

            Raises:
                PhotosDecodeError: The fixture's deterministic pixel refusal.
            """
            viewer._cancelled.set()
            raise refusal

        with mock.patch.object(
            viewer, '_prepare_detail', side_effect=cancel_during_decode,
        ):
            with self.assertRaises(native_gallery_viewer.PhotosDecodeError):
                viewer._read_prepared_detail(page.photos[0])
        session.media_photo.assert_called_once()
        viewer.close()

    def test_unavailable_preview_shows_lightbox_error_and_retry(self) -> None:
        """Surface a denied fallback and ignore a stale unavailable result.

        Returns:
            None: The selected lightbox shows a safe error with Retry, while an
                older detail serial cannot replace its current image state.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Denied preview'),
        )
        self._wait_until(
            lambda: viewer._worker is None,
            'The fixture viewer did not finish its initial page and thumbnails.',
        )
        refusal = native_gallery_viewer.PhotosDecodeError(
            native_gallery_viewer.PhotosDecodeFacts(
                'pixel-check', 'pixel-limit', 'PNG',
                TEST_LARGE_JPEG_SOURCE_SIZE, TEST_LARGE_JPEG_SOURCE_SIZE,
                1024, native_gallery_viewer.MEDIA_IMAGE_MAX_PIXELS,
                native_gallery_viewer.MEDIA_IMAGE_MAX_SIZE,
            ),
        )

        # The original read succeeds; its one authorized preview is unavailable.
        session.media_photo.side_effect = [
            (image_bytes[TEST_FIRST_IMAGE_ID], 'image/png'),
            LinkingMediaUnavailable(),
        ]
        with mock.patch.object(viewer, '_prepare_detail', side_effect=refusal):
            viewer._load_detail(0)
            original_show_error = viewer._lightbox.show_error
            viewer._lightbox.show_error = mock.Mock(
                wraps=original_show_error,
            )
            self._wait_until(
                lambda: viewer._worker is None
                and viewer.retry_button.winfo_manager() != '',
                'The unavailable preview did not finish with a retry action.',
            )
        viewer._lightbox.show_error.assert_called_once_with(
            viewer._text('linking_media_image_error'),
        )
        self.assertEqual('detail', viewer._retry_kind)

        visible_error_count = viewer._lightbox.show_error.call_count
        viewer._events.put((
            viewer._generation, 'detail_unavailable',
            (viewer._detail_serial - 1, TEST_FIRST_IMAGE_ID, None, None, None),
        ))
        viewer._poll_queue()
        self.assertEqual(
            visible_error_count, viewer._lightbox.show_error.call_count,
        )
        viewer.close()

    def test_catalogue_failure_reaches_current_session_snapshot(self) -> None:
        """Retain safe catalogue failures for the existing copy action.

        Returns:
            None: The bounded workspace snapshot records HTTP status and reason
                without response content or an old session's gallery details.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.gallery_page.side_effect = LinkingResponseError(
            'linking', 'app_linking_galleries', 502, 'html', 'http_status',
        )
        provider_state: list[LinkingSession | None] = [None]
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: provider_state[0], mock.Mock(), lambda: 'en',
        )
        provider_state[0] = session

        with self.assertLogs(
            native_gallery_viewer.__name__, level='WARNING',
        ):
            workspace.refresh_session()
            self._wait_until(
                lambda: 'http=502' in workspace.diagnostics_snapshot(),
                'The catalogue error was not retained for support diagnostics.',
            )

        snapshot = workspace.diagnostics_snapshot()
        self.assertIn('phase=page-fetch kind=catalogue', snapshot)
        self.assertIn('reason=http_status', snapshot)
        self.assertNotIn('gallery title', snapshot)
        workspace.destroy()

    def test_cached_catalogue_displays_before_old_server_page_fallback(self) -> None:
        """Show a saved preferred row while an old server pages the tree.

        Returns:
            None: A complete fresh page removes the absent saved row and keeps
                the normal first-gallery fallback and revisionless snapshot.
        """
        first = LinkedGallery(1, None, 'Fresh first')
        added = LinkedGallery(3, None, 'Fresh added')
        cached = GalleryCatalogueSnapshot((
            LinkedGallery(1, None, 'Cached first'),
            LinkedGallery(2, None, 'Saved preferred'),
        ), 'a' * 64)
        page_started = threading.Event()
        release_page = threading.Event()
        requested_cursors: list[int | None] = []
        self.addCleanup(release_page.set)
        provider: list[LinkingSession | None] = [None]
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.supports_gallery_tree.return_value = False
        session.media_page.return_value = LinkedMediaPage(
            1, None, (page.photos[0],), None,
        )
        session.media_photo.side_effect = None
        session.media_photo.return_value = (
            image_bytes[page.photos[0].image_id], 'image/png',
        )

        def read_page(
            after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> LinkedGalleryPage:
            """Hold one legacy page so the cache display can be observed first.

            Args:
                after_id (int | None): Expected initial page cursor.
                cancelled (threading.Event | None): Session cancellation token.

            Returns:
                LinkedGalleryPage: One complete fresh page after the test releases it.
            """
            requested_cursors.append(after_id)
            page_started.set()
            if not release_page.wait(TEST_GUI_WAIT_SECONDS):
                raise TimeoutError('Test did not release the old-server page.')
            if cancelled is not None and cancelled.is_set():
                raise RuntimeError('Old-server page was cancelled.')
            return LinkedGalleryPage((first, added), None)

        session.gallery_page.side_effect = read_page
        cache = mock.Mock()
        cache.load.return_value = cached
        cache.save.return_value = True
        saved_selection: list[int] = []
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: provider[0], mock.Mock(), lambda: 'en',
            catalogue_cache=lambda _session: cache,
            load_selected_gallery=lambda _session: 2,
            save_selected_gallery=lambda _session, gallery_id: (
                saved_selection.append(gallery_id)
            ),
        )
        workspace.pack(fill='both', expand=True)
        provider[0] = session
        workspace.refresh_session()
        self.assertTrue(page_started.wait(TEST_GUI_WAIT_SECONDS))
        self._wait_until(
            lambda: workspace.tree.exists('2')
            and workspace._selected_gallery_id == 2,
            'The cached preferred gallery did not mount before page completion.',
        )
        self.assertEqual('Saved preferred', workspace._galleries[2].title)
        cache_milestones = dict(
            workspace._catalogue_performance.snapshot().milestones
        )
        self.assertIn('cached_rows_mounted', cache_milestones)
        self.assertNotIn('fresh_rows_confirmed', cache_milestones)
        session.gallery_tree.assert_not_called()
        self.assertEqual([None], requested_cursors)

        release_page.set()
        self._wait_until(
            lambda: workspace._selected_gallery_id == 1
            and workspace.tree.exists('3')
            and not workspace.tree.exists('2')
            and cache.save.call_count == 1,
            'Fresh paging did not prune the old tree and choose a safe fallback.',
        )
        self.assertEqual('Fresh first', workspace._galleries[1].title)
        self.assertIn(
            'fresh_rows_confirmed',
            dict(workspace._catalogue_performance.snapshot().milestones),
        )
        self.assertIsNone(cache.save.call_args.args[0].revision)
        self.assertEqual([], saved_selection)
        evidence = workspace._catalogue_performance.snapshot()
        self.assertEqual('disk', evidence.cache_source)
        self.assertEqual('paged-fallback', evidence.transport_source)
        self.assertEqual('capability_missing', evidence.fallback_reason)
        self.assertEqual(1, evidence.completed_pages)
        self.assertEqual(2, evidence.confirmed_rows)
        self.assertTrue(any(
            phase.stage == 'mount_cached' and phase.measured > 0
            for phase in evidence.phases
        ))
        workspace.destroy()

    def test_failed_catalogue_walk_retains_prior_complete_snapshot(self) -> None:
        """Keep saved rows and successful updates when fresh paging fails early.

        Returns:
            None: The latest known tree retains prior children and a renamed
                first page, without treating the partial walk as a full revision.
        """
        cached = GalleryCatalogueSnapshot((
            LinkedGallery(1, None, 'Saved first'),
            LinkedGallery(2, 1, 'Saved child'),
        ), 'b' * 64)
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: None, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        workspace._accept_cached_catalogue(cached)
        workspace._ensure_initial_gallery = mock.Mock()
        workspace._accept_page(LinkedGalleryPage((
            LinkedGallery(1, None, 'Fresh first page row'),
        ), TEST_GALLERY_ID))
        workspace._accept_page(None)
        self.assertTrue(workspace.tree.exists('1'))
        self.assertTrue(workspace.tree.exists('2'))
        self.assertEqual(
            'Fresh first page row',
            workspace._catalogue_cache_snapshot.galleries[0].title,
        )
        self.assertIsNone(workspace._catalogue_cache_snapshot.revision)
        self.assertEqual('Saved child', workspace._galleries[2].title)
        workspace.destroy()

    def test_tree_refresh_keeps_selection_and_rejects_superseded_results(self) -> None:
        """Refresh names and hierarchy without clearing the current photo view.

        Returns:
            None: Repeated toolbar refreshes serialize requests, ignore the old
                pass, preserve expansion and selection, and save changed names.
                Desktop and narrow screenshots retain the actual toolbar layout.
        """

        original = (
            LinkedGallery(1, None, 'Original parent'),
            LinkedGallery(2, 1, 'Selected before refresh'),
            LinkedGallery(3, None, 'Removed on server'),
        )
        renamed = LinkedGallery(2, 4, 'Renamed on server')
        replacement = (
            original[0], renamed, LinkedGallery(4, None, 'New parent'),
            LinkedGallery(5, 4, 'Second child'),
        )
        provider: list[LinkingSession | None] = [None]
        session = self._session(*self._gallery_data())
        session.supports_gallery_tree.return_value = False
        session.gallery_page.return_value = LinkedGalleryPage(original, None)
        cache = mock.Mock()
        cache.load.return_value = None
        cache.save.return_value = True
        ui_theme.configure_theme(self.root)
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: provider[0], mock.Mock(), lambda: 'en',
            catalogue_cache=lambda _session: cache,
        )
        workspace.pack(fill='both', expand=True)
        workspace._ensure_initial_gallery = mock.Mock()
        provider[0] = session
        workspace.refresh_session()
        self._wait_until(
            lambda: workspace._worker is None and workspace.tree.exists('3'),
            'Initial refresh fixture did not finish its gallery tree.',
        )
        with mock.patch.object(
            photos_workspace, 'mount_native_gallery_view',
            return_value=mock.Mock(),
        ):
            workspace._select_gallery(original[1])
        viewer = workspace._viewer
        workspace.tree.item('1', open=True)
        workspace.tree.focus('2')
        session_cancel = workspace._cancel
        workspace_generation = workspace._generation
        started = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)
        refresh_calls: list[int | None] = []

        def read_page(
            after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> LinkedGalleryPage:
            """Hold the obsolete read while the next refresh is requested.

            Args:
                after_id (int | None): Initial cursor for each refreshed pass.
                cancelled (threading.Event | None): Pass-specific cancellation.
            Returns:
                LinkedGalleryPage: Old descriptors first, then renamed metadata.
            """
            refresh_calls.append(after_id)
            if len(refresh_calls) == 1:
                started.set()
                if not release.wait(TEST_GUI_WAIT_SECONDS):
                    raise TimeoutError('Refresh fixture was not released.')
                return LinkedGalleryPage(original, None)
            return LinkedGalleryPage(replacement, None)

        session.gallery_page.side_effect = read_page
        workspace.tree_refresh_button.invoke()
        self.assertTrue(started.wait(TEST_GUI_WAIT_SECONDS))
        old_generation = workspace._catalogue_generation
        old_cancel = workspace._catalogue_cancel
        self.assertEqual('2', workspace.tree.selection()[0])
        self.assertTrue(workspace.tree.exists('3'))
        workspace.tree_refresh_button.invoke()
        self.assertTrue(old_cancel.is_set())
        self.assertIs(session_cancel, workspace._cancel)
        self.assertFalse(session_cancel.is_set())
        self.assertEqual(workspace_generation, workspace._generation)
        # A response already queued before cancellation must also be fenced.
        workspace._events.put(photos_workspace._CataloguePageResult(
            old_generation, LinkedGalleryPage((), None),
        ))
        self._wait_until(
            workspace._events.empty,
            'The obsolete queued refresh result was not examined.',
        )
        self.assertTrue(workspace.tree.exists('3'))
        self.assertIs(viewer, workspace._viewer)
        release.set()
        self._wait_until(
            lambda: workspace._worker is None
            and workspace._galleries.get(2) == renamed
            and not workspace.tree.exists('3')
            and cache.save.call_args is not None
            and cache.save.call_args.args[0].galleries == replacement,
            'Refresh did not reconcile renamed, moved and removed galleries.',
        )
        self.assertEqual([None, None], refresh_calls)
        self.assertEqual(('2',), workspace.tree.selection())
        self.assertEqual('4', workspace.tree.parent('2'))
        self.assertEqual(('1', '4'), workspace.tree.get_children(''))
        self.assertEqual(('2', '5'), workspace.tree.get_children('4'))
        self.assertTrue(workspace.tree.item('1', 'open'))
        self.assertTrue(workspace.tree.item('4', 'open'))
        self.assertEqual('Renamed on server', workspace._gallery_title.get())
        self.assertIn('Renamed on server', workspace.tree.item('2', 'text'))
        self.assertIs(viewer, workspace._viewer)
        viewer.destroy.assert_not_called()
        for width, height in TEST_TREE_REFRESH_VIEWPORTS:
            with self.subTest(width=width):
                visible_width = min(
                    width,
                    self.root.winfo_screenwidth() - TEST_TREE_REFRESH_SCREEN_MARGIN,
                )
                visible_height = min(
                    height,
                    self.root.winfo_screenheight() - TEST_TREE_REFRESH_SCREEN_MARGIN,
                )
                self.root.geometry(f'{visible_width}x{visible_height}+0+0')
                self.root.update()
                self.assertTrue(workspace.tree.bbox('2'))
                button = workspace.tree_refresh_button
                self.assertTrue(button.winfo_ismapped())
                self.assertLessEqual(
                    button.winfo_rootx() + button.winfo_width(),
                    workspace.tree_pane.winfo_rootx()
                    + workspace.tree_pane.winfo_width(),
                )
                bounds = (
                    self.root.winfo_rootx(), self.root.winfo_rooty(),
                    self.root.winfo_rootx() + self.root.winfo_width(),
                    self.root.winfo_rooty() + self.root.winfo_height(),
                )
                evidence = WINAPP_DIR.parent / 'cache' / 'test-audit'
                evidence.mkdir(parents=True, exist_ok=True)
                with ImageGrab.grab(bbox=bounds) as captured:
                    captured.save(evidence / f'photos-tree-refresh-{width}.png')
        workspace.destroy()

    def test_unchanged_catalogue_confirms_cached_rows_without_rerender(self) -> None:
        """Confirm a matching unchanged tree without repainting or saving.

        Returns:
            None: Mounted rows remain untouched and become fresh for this
                session without queuing another snapshot write.
        """
        revision = 'e' * 64
        cached = GalleryCatalogueSnapshot((
            LinkedGallery(1, None, 'Saved first'),
            LinkedGallery(2, 1, 'Saved child'),
        ), revision)
        provider: list[LinkingSession | None] = [None]
        session = self._session(*self._gallery_data())
        cache = mock.Mock()
        cache.save.return_value = True
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: provider[0], mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        workspace._ensure_initial_gallery = mock.Mock()
        provider[0] = session
        workspace._session = session
        workspace._catalogue_cache = cache
        workspace._accept_cached_catalogue(cached)
        render = mock.Mock(wraps=workspace._render_tree)
        workspace._render_tree = render

        workspace._accept_complete_catalogue(
            LinkedGalleryTree((), revision, unchanged=True),
        )

        self.assertEqual(set((1, 2)), workspace._fresh_catalogue_ids)
        render.assert_not_called()
        self.assertTrue(workspace.tree.exists('1'))
        self.assertTrue(workspace.tree.exists('2'))
        cache.save.assert_not_called()
        evidence = workspace._catalogue_performance.snapshot()
        self.assertEqual('tree-v1-unchanged', evidence.transport_source)
        self.assertEqual(2, evidence.confirmed_rows)
        self.assertTrue(any(
            phase.stage == 'mount_fresh' for phase in evidence.phases
        ))
        workspace.destroy()

    def test_selected_cached_gallery_persists_only_after_fresh_confirmation(self) -> None:
        """Persist an explicit cached choice only after a fresh page confirms it.

        Returns:
            None: Cache-only selection is not saved; a later fresh row saves
                once and keeps the user's choice over the older preference.
        """
        selected = LinkedGallery(2, None, 'Cached user choice')
        provider: list[LinkingSession | None] = [None]
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        saved: list[int] = []
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: provider[0], mock.Mock(), lambda: 'en',
            load_selected_gallery=lambda _session: 1,
            save_selected_gallery=lambda _session, gallery_id: saved.append(
                gallery_id,
            ),
        )
        workspace.pack(fill='both', expand=True)
        provider[0] = session
        workspace._session = session
        workspace._preferred_gallery_id = 1
        workspace._accept_cached_catalogue(
            GalleryCatalogueSnapshot((
                LinkedGallery(1, None, 'Saved preference'),
                selected,
            ), '9' * 64),
        )
        with mock.patch.object(
            photos_workspace, 'mount_native_gallery_view',
            return_value=mock.Mock(),
        ):
            workspace._select_gallery(selected)
            self.assertEqual(2, workspace._selected_gallery_id)
            workspace._persist_selected_gallery_if_fresh((2,))
            self.assertEqual([], saved)

            workspace._accept_page(LinkedGalleryPage((selected,), None))

        self.assertEqual([2], saved)
        self.assertEqual(2, workspace._selected_gallery_id)
        workspace.destroy()

    def test_fresh_tree_response_is_saved_with_its_server_revision(self) -> None:
        """Persist only a complete tree-v1 response and its exact revision.

        Returns:
            None: Tree-v1 completes without page fallback and the serialized
                writer receives only the complete fresh metadata snapshot.
        """
        gallery = LinkedGallery(5, None, 'Fresh revision row')
        revision = 'f' * 64
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.supports_gallery_tree.return_value = True
        session.gallery_tree.return_value = LinkedGalleryTree(
            (gallery,), revision,
        )
        provider: list[LinkingSession | None] = [None]
        cache = mock.Mock()
        cache.load.return_value = None
        cache.save.return_value = True
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: provider[0], mock.Mock(), lambda: 'en',
            catalogue_cache=lambda _session: cache,
        )
        workspace.pack(fill='both', expand=True)
        workspace._ensure_initial_gallery = mock.Mock()
        provider[0] = session
        workspace.refresh_session()
        self._wait_until(
            lambda: workspace.tree.exists('5')
            and workspace._complete_catalogue_tree is None
            and cache.save.call_count == 1,
            'The fresh complete tree was not saved after bounded rendering.',
        )
        session.gallery_tree.assert_called_once_with(
            None, cancelled=mock.ANY,
        )
        session.gallery_page.assert_not_called()
        stored = cache.save.call_args.args[0]
        self.assertEqual(revision, stored.revision)
        self.assertEqual((gallery,), stored.galleries)
        evidence = workspace._catalogue_performance.snapshot()
        self.assertEqual('miss', evidence.cache_source)
        self.assertEqual('tree-v1-full', evidence.transport_source)
        self.assertEqual(1, evidence.confirmed_rows)
        self.assertEqual(0, evidence.completed_pages)
        self.assertTrue(any(
            phase.stage == 'mount_fresh' and phase.measured == 1
            for phase in evidence.phases
        ))
        workspace.destroy()

    def test_cancelled_cache_batch_cannot_overwrite_fresh_page(self) -> None:
        """Fence a late cached row callback after a fresh complete page.

        Returns:
            None: New title remains visible and stale cached rows stay removed.
        """
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: None, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        workspace._ensure_initial_gallery = mock.Mock()
        rows = tuple(
            LinkedGallery(index, None, f'Cached {index}')
            for index in range(1, TEST_CATALOGUE_CACHE_ROWS + 1)
        )
        scheduled: list[Callable[[], None]] = []
        workspace.after = mock.Mock(
            side_effect=lambda _delay, callback: (
                scheduled.append(callback) or f'after#{len(scheduled)}'
            ),
        )
        workspace.after_cancel = mock.Mock()
        workspace._accept_cached_catalogue(GalleryCatalogueSnapshot(rows, 'c' * 64))
        self.assertTrue(workspace.tree.exists('1'))
        self.assertFalse(workspace.tree.exists(str(TEST_CATALOGUE_CACHE_ROWS)))
        self.assertEqual(1, len(scheduled))

        fresh = LinkedGallery(1, None, 'Fresh title')
        workspace._accept_page(LinkedGalleryPage((fresh,), None))
        scheduled[0]()
        self.assertEqual('Fresh title', workspace._galleries[1].title)
        self.assertIn('Fresh title', workspace.tree.item('1', 'text'))
        self.assertFalse(workspace.tree.exists('2'))
        workspace.destroy()

    def test_fresh_complete_tree_mounts_in_bounded_ui_batches(self) -> None:
        """Yield between tree slices and preserve ordered, reachable hierarchy.

        Returns:
            None: All metadata is retained before later IDs become visible
                through the owned timer callback, so an early close can save it.
                Appends, interleaved rows, late parents and cycles preserve
                numeric order and reachable rows through the real renderer.
        """
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: None, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        workspace._ensure_initial_gallery = mock.Mock()
        galleries = tuple(
            LinkedGallery(index, None, f'Fresh {index}')
            for index in range(1, TEST_CATALOGUE_CACHE_ROWS + 1)
        )
        workspace._accept_complete_catalogue(
            LinkedGalleryTree(galleries, 'd' * 64),
        )
        self.assertTrue(workspace.tree.exists('1'))
        self.assertFalse(
            workspace.tree.exists(str(TEST_CATALOGUE_CACHE_ROWS)),
        )
        self.assertIsNotNone(workspace._cached_mount_after)
        self.assertEqual(
            galleries, workspace._catalogue_cache_snapshot.galleries,
        )
        self._wait_until(
            lambda: workspace.tree.exists(str(TEST_CATALOGUE_CACHE_ROWS))
            and workspace._complete_catalogue_tree is None,
            'The bounded tree renderer did not finish its later batch.',
        )
        self.assertEqual(TEST_CATALOGUE_CACHE_ROWS, len(workspace._galleries))
        self.assertEqual(
            tuple(str(index) for index in range(1, TEST_CATALOGUE_CACHE_ROWS + 1)),
            workspace.tree.get_children(""),
        )
        evidence = workspace._catalogue_performance.snapshot()
        self.assertEqual('tree-v1-full', evidence.transport_source)
        fresh_mounts = [
            phase for phase in evidence.phases
            if phase.stage == 'mount_fresh'
        ]
        self.assertEqual(1, len(fresh_mounts))
        self.assertEqual(2, fresh_mounts[0].samples)
        self.assertEqual(TEST_CATALOGUE_CACHE_ROWS, fresh_mounts[0].rows)

        lower = galleries[0]
        workspace.tree.delete(str(lower.gallery_id))
        workspace._galleries.pop(lower.gallery_id)
        workspace._galleries[lower.gallery_id] = lower
        with mock.patch.object(
            workspace, '_ordered_index', wraps=workspace._ordered_index,
        ) as ordered_index:
            workspace._render_tree([lower.gallery_id])
        self.assertEqual(1, ordered_index.call_count)
        self.assertEqual('1', workspace.tree.get_children("")[0])

        appended = LinkedGallery(TEST_CATALOGUE_CACHE_ROWS + 1, None, 'Appended')
        workspace._galleries[appended.gallery_id] = appended
        with mock.patch.object(
            workspace, '_ordered_index', wraps=workspace._ordered_index,
        ) as ordered_index:
            workspace._render_tree([appended.gallery_id])
        self.assertEqual(0, ordered_index.call_count)
        self.assertEqual(
            str(appended.gallery_id), workspace.tree.get_children("")[-1],
        )

        late_parent_id = appended.gallery_id + 2
        orphan = LinkedGallery(
            appended.gallery_id + 1, late_parent_id,
            'Orphan awaiting parent',
        )
        workspace._galleries[orphan.gallery_id] = orphan
        workspace._children_by_parent.setdefault(
            late_parent_id, set(),
        ).add(orphan.gallery_id)
        workspace._render_tree([orphan.gallery_id])
        self.assertEqual('', workspace.tree.parent(str(orphan.gallery_id)))
        parent = LinkedGallery(late_parent_id, None, 'Late parent')
        workspace._galleries[parent.gallery_id] = parent
        workspace._render_tree([parent.gallery_id])
        self.assertEqual(str(parent.gallery_id), workspace.tree.parent(
            str(orphan.gallery_id),
        ))

        cycle_first_id = parent.gallery_id + 1
        cycle_second_id = parent.gallery_id + 2
        cycle_first = LinkedGallery(
            cycle_first_id, cycle_second_id, 'Cycle first',
        )
        cycle_second = LinkedGallery(
            cycle_second_id, cycle_first_id, 'Cycle second',
        )
        workspace._galleries[cycle_first.gallery_id] = cycle_first
        workspace._galleries[cycle_second.gallery_id] = cycle_second
        workspace._children_by_parent.setdefault(
            cycle_second_id, set(),
        ).add(cycle_first.gallery_id)
        workspace._children_by_parent.setdefault(
            cycle_first_id, set(),
        ).add(cycle_second.gallery_id)
        workspace._render_tree([
            cycle_first.gallery_id, cycle_second.gallery_id,
        ])
        self.assertEqual('', workspace.tree.parent(str(cycle_second.gallery_id)))
        self.assertEqual(str(cycle_second.gallery_id), workspace.tree.parent(
            str(cycle_first.gallery_id),
        ))
        workspace.destroy()

    def test_preload_diagnostic_reaches_copy_and_log_for_exact_session(self) -> None:
        """Forward safe worker facts to copied support and the host log queue.

        Returns:
            None: Album navigation preserves same-session preload evidence,
                while a replaced session rejects the old worker's late event.
        """
        page = LinkedMediaPage(
            42, None, (LinkedPhoto(101, 'Private album'),), None,
        )
        presentation = LinkedPresentation(
            False, False, 5, 'medium', False, (),
        )
        session = self._session(page, presentation, {})
        error = LinkingResponseError(
            'media', 'app_linking_media', 200, 'other', 'response_policy',
        )
        error.args = ('token=private https://private.example/photo',)
        session.prepare_cached_photo.return_value = False
        session.media_photo.side_effect = error
        host_events: queue.Queue[tuple[str, str]] = queue.Queue()
        workspace = photos_workspace.PhotosWorkspace.__new__(
            photos_workspace.PhotosWorkspace,
        )
        workspace._photo_diagnostic_lock = threading.Lock()
        workspace._photo_diagnostic_latest = None
        workspace._photo_diagnostic_recent = deque(
            maxlen=photos_workspace.PHOTOS_DIAGNOSTIC_RECENT_LIMIT,
        )
        workspace._photo_observation_recent = deque(
            maxlen=photos_workspace.PHOTOS_OBSERVATION_RECENT_LIMIT,
        )
        workspace._diagnostic_callback = lambda summary: host_events.put(
            ('warning', '[Photos] ' + summary),
        )
        workspace._session = session
        workspace._generation = 2
        worker = GalleryPreloadWorker(
            session, 42,
            diagnostic_callback=lambda summary: (
                workspace._record_preload_diagnostic(session, summary)
            ),
        )

        worker.run()

        self.assertGreater(session.media_photo.call_count, 0)
        snapshot = workspace.diagnostics_snapshot()
        self.assertIn('phase=preload-fetch', snapshot)
        self.assertIn('preload_phase=error', snapshot)
        self.assertNotIn('private.example', snapshot)
        first_host_event = host_events.get_nowait()
        self.assertEqual('warning', first_host_event[0])
        self.assertIn('phase=preload-fetch', first_host_event[1])
        self.assertNotIn('token=private', first_host_event[1])
        outcome_event = host_events.get_nowait()
        self.assertIn('preload_phase=error', outcome_event[1])

        workspace._generation += 1
        workspace._record_preload_diagnostic(
            session, 'phase=preload-fetch same-session-after-album-change',
        )
        self.assertIn(
            'same-session-after-album-change',
            workspace.diagnostics_snapshot(),
        )
        host_events.get_nowait()
        replacement = self._session(page, presentation, {})
        with workspace._photo_diagnostic_lock:
            workspace._session = replacement
        workspace._clear_photo_diagnostics()
        workspace._record_preload_diagnostic(
            session, 'phase=preload-fetch stale-replaced-session',
        )
        self.assertIn('No Photos failure', workspace.diagnostics_snapshot())
        self.assertTrue(host_events.empty())

    def test_shortcut_observations_copy_without_replacing_failure(self) -> None:
        """Copy bounded shortcut traces separately from the failure summary.

        Returns:
            None: Current traces are copyable, stale generations and arbitrary
                text are rejected, and Latest failure remains unchanged.
        """

        workspace = photos_workspace.PhotosWorkspace.__new__(
            photos_workspace.PhotosWorkspace,
        )
        workspace._photo_diagnostic_lock = threading.Lock()
        workspace._photo_diagnostic_latest = None
        workspace._photo_diagnostic_recent = deque(
            maxlen=photos_workspace.PHOTOS_DIAGNOSTIC_RECENT_LIMIT,
        )
        workspace._photo_observation_recent = deque(
            maxlen=photos_workspace.PHOTOS_OBSERVATION_RECENT_LIMIT,
        )
        workspace._generation = 9
        summary = format_safe_photos_shortcut(
            'f', 0x0008, 'canvas', True, False, True, 'toggled',
        )
        self.assertIsNotNone(summary)
        workspace._record_photo_observation(9, summary)
        workspace._record_photo_observation(8, summary)
        workspace._record_photo_observation(
            9, 'shortcut key=f path=C:\\private\\album',
        )

        snapshot = workspace.diagnostics_snapshot()
        self.assertIn('No Photos failure recorded', snapshot)
        self.assertIn('Recent observations:', snapshot)
        self.assertIn(summary, snapshot)
        self.assertNotIn('Latest failure:', snapshot)
        self.assertNotIn('private\\album', snapshot)

    def test_smooth_icon_button_updates_text_state_and_arrow_direction(self) -> None:
        """Keep accessible text while redrawing dynamic and disabled icons.

        Returns:
            None: Play-to-pause updates the image and directional artwork differs.
        """

        ui_theme.configure_theme(self.root)
        with mock.patch.object(self.root, "winfo_fpixels", return_value=144.0):
            ui_theme.configure_theme(self.root)
            dpi_icon = ui_theme.create_smooth_icon(
                self.root, "close", ui_theme._ICON_IMAGE_SIZE,
            )
            self.assertEqual((30, 30), ImageTk.getimage(dpi_icon).size)
            self.assertEqual(
                45,
                int(ttk.Style(self.root).lookup("Treeview", "rowheight")),
            )
        host = ttk.Frame(self.root)
        host.pack()
        button = ui_theme.icon_button(
            host, "play", lambda: None, "Toggle playback",
            style_override="Icon.TButton",
        )
        button.pack()
        self.root.update()
        self.assertEqual("▶", button.cget("text"))
        play_image = str(button.cget("image"))
        self.assertTrue(play_image)

        button.configure(text="Ⅱ")
        self.root.update()
        pause_image = str(button.cget("image"))
        self.assertEqual("Ⅱ", button.cget("text"))
        self.assertNotEqual(play_image, pause_image)

        with mock.patch.object(
            ui_theme, "create_smooth_icon", wraps=ui_theme.create_smooth_icon,
        ) as render:
            button.configure(state="disabled")
            self.root.update()
        self.assertIn("disabled", button.state())
        self.assertTrue(button.cget("image"))
        self.assertEqual(
            ui_theme._PALETTE["muted"],
            render.call_args.kwargs["tone"],
        )

        button.configure(state="normal")
        external_badge = ImageTk.PhotoImage(
            Image.new("RGBA", (8, 8), (255, 255, 255, 255)),
            master=self.root,
        )
        button.configure(image=external_badge)
        badge_name = button.cget("image")
        button.event_generate("<Enter>")
        self.root.update()
        self.assertEqual(badge_name, button.cget("image"))
        button.configure(text="?", image=external_badge)
        self.assertEqual("?", button.cget("text"))
        self.assertEqual(badge_name, button.cget("image"))
        button.configure(image="")
        self.root.update()
        self.assertEqual("?", button.cget("text"))
        self.assertNotEqual(badge_name, button.cget("image"))
        button.configure(text="×")
        self.root.update()
        self.assertNotEqual(badge_name, button.cget("image"))

        with mock.patch.object(ui_theme, "create_smooth_icon", return_value=None):
            button.configure(text="?")
            self.root.update()
        self.assertEqual("?", button.cget("text"))
        self.assertFalse(button.cget("image"))

        icon_size = ui_theme._ICON_IMAGE_SIZE
        left = ui_theme.create_smooth_icon(self.root, "back", icon_size)
        right = ui_theme.create_smooth_icon(self.root, "next", icon_size)
        fit = ui_theme.create_smooth_icon(self.root, "fit", icon_size)
        self.assertIsNotNone(ImageTk.getimage(fit).getbbox())
        left_pixels = ImageTk.getimage(left)
        right_pixels = ImageTk.getimage(right)
        middle_y = left_pixels.height // 2
        left_row = [
            (x, left_pixels.getpixel((x, middle_y))[3])
            for x in range(left_pixels.width)
            if left_pixels.getpixel((x, middle_y))[3] > 0
        ]
        right_row = [
            (x, right_pixels.getpixel((x, middle_y))[3])
            for x in range(right_pixels.width)
            if right_pixels.getpixel((x, middle_y))[3] > 0
        ]
        self.assertTrue(left_row)
        self.assertTrue(right_row)
        left_tip = sum(x * alpha for x, alpha in left_row) / sum(
            alpha for _x, alpha in left_row
        )
        right_tip = sum(x * alpha for x, alpha in right_row) / sum(
            alpha for _x, alpha in right_row
        )
        self.assertLess(left_tip, right_tip)

    def _png(self, color: tuple[int, int, int]) -> bytes:
        """Encode one tiny synthetic RGB image without filesystem access.

        Args:
            color (tuple[int, int, int]): Three RGB channel values for the image.

        Returns:
            bytes: PNG bytes consumed by the actual Pillow/Tk renderer.
        """
        stream = io.BytesIO()
        Image.new('RGB', TEST_SYNTHETIC_IMAGE_SIZE, color).save(
            stream,
            format='PNG',
        )
        return stream.getvalue()

    def test_wide_thumbnail_trims_mattes_and_preserves_full_scene(self) -> None:
        """Trim paired side mattes and keep natural thumbnail dimensions.

        Returns:
            None: Both content-edge markers survive natural-aspect resizing.
        """
        source = Image.new('RGB', TEST_WIDE_PHOTO_SIZE, '#376b91')
        draw = ImageDraw.Draw(source)
        draw.rectangle(
            (0, 0, TEST_WIDE_PHOTO_MATTE - 1, TEST_WIDE_PHOTO_SIZE[1] - 1),
            fill='black',
        )
        draw.rectangle(
            (TEST_WIDE_PHOTO_SIZE[0] - TEST_WIDE_PHOTO_MATTE, 0,
             TEST_WIDE_PHOTO_SIZE[0] - 1, TEST_WIDE_PHOTO_SIZE[1] - 1),
            fill='black',
        )
        draw.line(
            (TEST_WIDE_PHOTO_MATTE, 0,
             TEST_WIDE_PHOTO_MATTE, TEST_WIDE_PHOTO_SIZE[1] - 1),
            fill=(240, 40, 30),
        )
        draw.line(
            (TEST_WIDE_PHOTO_SIZE[0] - TEST_WIDE_PHOTO_MATTE - 1, 0,
             TEST_WIDE_PHOTO_SIZE[0] - TEST_WIDE_PHOTO_MATTE - 1,
             TEST_WIDE_PHOTO_SIZE[1] - 1),
            fill=(20, 220, 50),
        )
        stream = io.BytesIO()
        source.save(stream, format='PNG')
        source.close()

        viewer = object.__new__(native_gallery_viewer._NativeGalleryViewer)
        viewer._diagnostic_callback = None
        normalized = viewer._prepare_image(
            stream.getvalue(), native_gallery_viewer.MEDIA_THUMBNAIL_SIZE,
        )
        with Image.open(io.BytesIO(normalized)) as thumbnail:
            self.assertEqual(TEST_NATURAL_WIDE_THUMBNAIL_SIZE, thumbnail.size)
            center_y = thumbnail.height // 2
            left_edge = thumbnail.getpixel((0, center_y))
            right_edge = thumbnail.getpixel((143, center_y))
            self.assertGreater(left_edge[0], left_edge[1])
            self.assertGreater(right_edge[1], right_edge[0])

    def test_clean_dark_wide_thumbnail_keeps_both_natural_edges(self) -> None:
        """Keep unbordered dark scenes intact during thumbnail preparation.

        Returns:
            None: The full dark source remains a natural-aspect thumbnail.
        """
        source = Image.new('RGB', (210, 90), '#07090b')
        ImageDraw.Draw(source).rectangle(
            (5, 5, 204, 84), fill=(17, 24, 32),
        )
        expected = source.copy()
        expected.thumbnail(
            native_gallery_viewer.MEDIA_THUMBNAIL_SIZE,
            Image.Resampling.LANCZOS,
        )
        stream = io.BytesIO()
        source.save(stream, format='PNG')
        source.close()

        viewer = object.__new__(native_gallery_viewer._NativeGalleryViewer)
        viewer._diagnostic_callback = None
        normalized = viewer._prepare_image(
            stream.getvalue(), native_gallery_viewer.MEDIA_THUMBNAIL_SIZE,
        )
        with Image.open(io.BytesIO(normalized)) as thumbnail:
            self.assertEqual(TEST_NATURAL_WIDE_THUMBNAIL_SIZE, thumbnail.size)
            center_y = thumbnail.height // 2
            self.assertEqual(
                expected.getpixel((0, center_y)),
                thumbnail.getpixel((0, center_y)),
            )
            self.assertEqual(
                expected.getpixel((thumbnail.width - 1, center_y)),
                thumbnail.getpixel((thumbnail.width - 1, center_y)),
            )
        expected.close()

    def test_thumbnails_follow_bounded_admitted_band(self) -> None:
        """Coalesce scrolling and retain one bounded row around visible tiles.

        Returns:
            None: Small scrolls reuse Tk images, distant rows are evicted, and
                only thumbnail renditions are requested by grid navigation.
        """
        page, presentation, image_bytes = self._gallery_data()
        # Twenty-four rows force a scrollable album while staying below the
        # protocol's 64-photo maximum page size.
        photos = tuple(
            LinkedPhoto(TEST_FIRST_IMAGE_ID + index, f'Photo {index + 1}')
            for index in range(TEST_SCROLL_PHOTO_COUNT)
        )
        for index, photo in enumerate(photos):
            image_bytes[photo.image_id] = self._png((index, 80, 160))
        page = LinkedMediaPage(
            page.gallery_id, page.parent_id, photos, None,
        )
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session,
            LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Lazy tiles'),
        )
        self._wait_until(
            lambda: bool(viewer._visible_thumbnail_indices)
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices)
            and viewer._viewport_after_id is None,
            'The admitted album tiles did not receive their thumbnails.',
        )
        first_visible = set(viewer._visible_thumbnail_indices)
        canvas_top = viewer.grid_canvas.winfo_rooty()
        canvas_bottom = canvas_top + viewer.grid_canvas.winfo_height()
        actual_first_visible = {
            index for index, tile in enumerate(viewer._tile_buttons)
            if (tile.winfo_rooty() + tile.winfo_height() > canvas_top
                and tile.winfo_rooty() < canvas_bottom)
        }
        self.assertEqual(actual_first_visible, first_visible)
        self.assertLess(len(first_visible), len(photos))
        first_admitted = set(viewer._admitted_thumbnail_indices)
        self.assertEqual(first_admitted, viewer._thumbnail_loaded)
        self.assertTrue(first_visible <= first_admitted)
        self.assertLessEqual(
            len(first_admitted),
            len(first_visible)
            + 2 * native_gallery_viewer.MEDIA_THUMBNAIL_OVERSCAN_ROWS
            * viewer._grid_columns,
        )
        image_refs = {
            index: viewer._photo_images[index]
            for index in first_admitted
        }

        previous_update_count = viewer._viewport_update_count
        previous_event_count = viewer._viewport_event_count
        for _ in range(TEST_VIEWPORT_EVENT_BURST):
            viewer._on_grid_viewport(None)
        burst_callback_id = viewer._viewport_after_id
        self.assertIsNotNone(burst_callback_id)
        self.assertEqual(
            previous_event_count + TEST_VIEWPORT_EVENT_BURST,
            viewer._viewport_event_count,
        )
        self.assertEqual(burst_callback_id, viewer._viewport_after_id)
        self._wait_until(
            lambda: viewer._viewport_update_count == previous_update_count + 1
            and viewer._viewport_after_id is None,
            'A viewport burst was not handled by exactly one Tk update.',
        )

        previous_update_count = viewer._viewport_update_count
        row_one_index = viewer._grid_columns
        content_bounds = viewer.grid_canvas.bbox('all')
        self.assertIsNotNone(content_bounds)
        content_height = content_bounds[3] - content_bounds[1]
        row_one_y = viewer._tile_buttons[row_one_index].winfo_y()
        viewer.grid_canvas.yview_moveto(row_one_y / content_height)
        viewer._on_grid_viewport(None)
        self._wait_until(
            lambda: viewer._viewport_update_count > previous_update_count
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices)
            and viewer._viewport_after_id is None,
            'A small scroll did not settle its visible thumbnail band.',
        )
        self.assertNotIn(0, viewer._visible_thumbnail_indices)
        self.assertIn(0, viewer._admitted_thumbnail_indices)
        self.assertIs(image_refs[0], viewer._photo_images[0])
        self.assertTrue(
            viewer._visible_thumbnail_indices
            <= viewer._admitted_thumbnail_indices,
        )
        shared_indices = first_admitted & viewer._admitted_thumbnail_indices
        self.assertTrue(shared_indices)
        for index in shared_indices:
            self.assertIs(image_refs[index], viewer._photo_images[index])

        calls_before_reversal = session.media_photo.call_count
        previous_update_count = viewer._viewport_update_count
        viewer.grid_canvas.yview_moveto(0.0)
        viewer._on_grid_viewport(None)
        self._wait_until(
            lambda: viewer._viewport_update_count > previous_update_count
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices)
            and viewer._viewport_after_id is None,
            'Returning one row to the top did not settle its admitted band.',
        )
        self.assertIs(image_refs[0], viewer._photo_images[0])
        self.assertEqual(calls_before_reversal, session.media_photo.call_count)

        viewer.grid_canvas.yview_moveto(1.0)
        viewer._on_grid_viewport(None)
        self._wait_until(
            lambda: bool(viewer._visible_thumbnail_indices)
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices)
            and viewer._viewport_after_id is None,
            'The newly visible album tiles did not receive thumbnails.',
        )
        canvas_top = viewer.grid_canvas.winfo_rooty()
        canvas_bottom = canvas_top + viewer.grid_canvas.winfo_height()
        actual_bottom_visible = {
            index for index, tile in enumerate(viewer._tile_buttons)
            if (tile.winfo_rooty() + tile.winfo_height() > canvas_top
                and tile.winfo_rooty() < canvas_bottom)
        }
        self.assertEqual(
            actual_bottom_visible, viewer._visible_thumbnail_indices,
        )
        self.assertEqual(
            viewer._admitted_thumbnail_indices, viewer._thumbnail_loaded,
        )
        self.assertEqual(
            viewer._admitted_thumbnail_indices,
            {index for index, image in enumerate(viewer._photo_images)
             if image is not None},
        )
        self.assertNotIn(0, viewer._admitted_thumbnail_indices)
        self.assertIsNone(viewer._photo_images[0])
        self.assertLessEqual(
            len(viewer._admitted_thumbnail_indices),
            len(viewer._visible_thumbnail_indices)
            + 2 * native_gallery_viewer.MEDIA_THUMBNAIL_OVERSCAN_ROWS
            * viewer._grid_columns,
        )

        previous_update_count = viewer._viewport_update_count
        viewer.grid_canvas.yview_moveto(0.0)
        viewer._on_grid_viewport(None)
        self._wait_until(
            lambda: viewer._viewport_update_count > previous_update_count
            and all(viewer._photo_images[index]
                    for index in viewer._visible_thumbnail_indices)
            and viewer._viewport_after_id is None,
            'Returning to the first rows did not restore their thumbnails.',
        )
        self.assertGreater(viewer._thumbnail_warm_cache_hit_count, 0)
        self.assertTrue(all(
            call.kwargs.get('thumbnail') is True
            for call in session.media_photo.call_args_list
        ))

        viewer._on_grid_viewport(None)
        stale_callback_id = viewer._viewport_after_id
        changed_page = LinkedMediaPage(
            page.gallery_id, page.parent_id, page.photos, 99,
        )
        viewer._accept_refresh((changed_page,))
        self.root.update_idletasks()
        replacement_callback_id = viewer._viewport_after_id
        self.assertIsNotNone(stale_callback_id)
        self.assertIsNotNone(replacement_callback_id)
        self.assertNotEqual(stale_callback_id, replacement_callback_id)
        update_count = viewer._viewport_update_count
        viewer._apply_grid_viewport(stale_callback_id)
        self.assertEqual(update_count, viewer._viewport_update_count)
        self.assertEqual(replacement_callback_id, viewer._viewport_after_id)
        viewer.close()
        self.assertIsNone(viewer._viewport_after_id)
        viewer._apply_grid_viewport(replacement_callback_id)
        self.assertEqual(update_count, viewer._viewport_update_count)
        diagnostics = viewer.render_diagnostics()
        self.assertIn('viewport_events=', diagnostics)
        self.assertIn('viewport_updates=', diagnostics)
        self.assertIn('tk_images_created=', diagnostics)
        self.assertIn('tk_images_released=', diagnostics)
        self.assertIn('warm_cache_hits=', diagnostics)
        self.assertIn('peak_update_ms=', diagnostics)
        self.assertIn('retained=0', diagnostics)
        self.assertNotIn('Photo 1', diagnostics)
        viewer.close()

    def test_thumbnail_batch_reads_serially_before_gui_poll(self) -> None:
        """Fetch the bounded thumbnail batch before servicing Tk results.

        Returns:
            None: Several serial transport reads complete without a per-item
                Tk poll, while all resulting decoded images remain queued.
        """
        page, presentation, image_bytes = self._gallery_data()
        photos = tuple(
            LinkedPhoto(TEST_FIRST_IMAGE_ID + index, f'Batch {index}')
            for index in range(TEST_SCROLL_PHOTO_COUNT)
        )
        for index, photo in enumerate(photos):
            image_bytes[photo.image_id] = self._png((index, 90, 150))
        page = LinkedMediaPage(page.gallery_id, page.parent_id, photos, None)
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session,
            LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Batch'),
        )
        self._wait_until(
            lambda: viewer._worker is None
            and viewer._events.empty()
            and bool(viewer._admitted_thumbnail_indices)
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices),
            'The thumbnail fixture did not reach its idle viewport.',
        )
        self.assertGreaterEqual(
            len(viewer._admitted_thumbnail_indices),
            native_gallery_viewer.MEDIA_THUMBNAIL_BATCH_SIZE,
            'The fixture needs a full admitted thumbnail batch.',
        )
        self.assertFalse(viewer._cancelled.is_set())
        self.assertTrue(viewer.grid_canvas.winfo_viewable())
        self.assertIsNone(viewer._pending_detail_index)
        self.assertIsNone(viewer._pending_page_request)
        self.assertFalse(viewer._media_throttled)

        batch_ready = threading.Event()
        original_read = session.media_photo.side_effect
        target_reads = (
            session.media_photo.call_count
            + native_gallery_viewer.MEDIA_THUMBNAIL_BATCH_SIZE
        )

        def read_and_count(
            gallery_id: int,
            image_id: int,
            thumbnail: bool,
            cancelled: threading.Event | None = None,
            *,
            cache_eligible: bool | None = None,
            thumbnail_etag: str | None = None,
            thumbnail_verified_at: float | None = None,
            preload_eligible: bool | None = None,
            preload_thumbnail_etag: str | None = None,
            preload_verified_at: float | None = None,
            timings: MediaReadTimings | None = None,
            use_preloaded_snapshot: bool = False,
        ) -> tuple[bytes, str]:
            """Count completed fixture reads without involving Tk.

            Args:
                gallery_id (int): Gallery identity passed to the session.
                image_id (int): Photo identity passed to the session.
                thumbnail (bool): Whether this is a thumbnail request.
                cancelled (threading.Event | None): Viewer read cancellation.
                cache_eligible (bool | None): Public cache admission.
                thumbnail_etag (str | None): Current thumbnail validator.
                thumbnail_verified_at (float | None): Catalogue proof time.
                preload_eligible (bool | None): Admin preload admission.
                preload_thumbnail_etag (str | None): Admin thumbnail validator.
                preload_verified_at (float | None): Admin proof time.
                timings (MediaReadTimings | None): Optional transport timings.
                use_preloaded_snapshot (bool): Full-read saved-snapshot request.

            Returns:
                tuple[bytes, str]: Existing deterministic PNG media response.
            """
            result = original_read(
                gallery_id, image_id, thumbnail, cancelled,
                cache_eligible=cache_eligible,
                thumbnail_etag=thumbnail_etag,
                thumbnail_verified_at=thumbnail_verified_at,
                preload_eligible=preload_eligible,
                preload_thumbnail_etag=preload_thumbnail_etag,
                preload_verified_at=preload_verified_at,
                timings=timings,
                use_preloaded_snapshot=use_preloaded_snapshot,
            )
            if session.media_photo.call_count >= target_reads:
                batch_ready.set()
            return result

        session.media_photo.side_effect = read_and_count
        viewer._cancel_thumbnail_gui_slice()
        for index in tuple(viewer._admitted_thumbnail_indices):
            viewer._release_thumbnail_image(index)
        viewer._thumbnail_memory.clear()
        viewer._thumbnail_loaded.clear()
        viewer._thumbnail_pending = sorted(viewer._admitted_thumbnail_indices)
        # This test directly exercises the serialized network batch, so no
        # bounded warm-RAM descriptors remain ahead of its synthetic work.
        # Normal dispatch clears a previous viewport's cancellation before
        # starting the next batch; this fixture invokes the worker directly.
        viewer._thumbnail_cancelled.clear()
        viewer._load_next_thumbnail()
        self.assertIsNotNone(viewer._worker, 'The full batch was not admitted.')
        self.assertTrue(
            batch_ready.wait(TEST_GUI_WAIT_SECONDS),
            'The worker did not fetch multiple thumbnails before a GUI poll.',
        )
        self.assertEqual(
            target_reads,
            session.media_photo.call_count,
        )
        worker = viewer._worker
        self.assertIsNotNone(worker)
        worker.join(TEST_WORKER_JOIN_SECONDS)
        self.assertFalse(worker.is_alive())
        self.assertEqual(
            viewer._events.qsize(),
            native_gallery_viewer.MEDIA_THUMBNAIL_BATCH_SIZE,
        )
        with mock.patch.object(
            native_gallery_viewer, '_log_safe_media_failure',
        ) as apply_failure:
            viewer._poll_queue()
            apply_failure.assert_not_called()
        self._wait_until(
            lambda: viewer._worker is None
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices),
            'The queued thumbnail batch was not attached to the admitted grid.',
        )
        viewer.close()

    def test_warm_thumbnail_cache_copies_use_bounded_gui_slices(self) -> None:
        """Copy and attach warm rasters within one named Tk slice at a time.

        Returns:
            None: The first callback attaches at most the slice limit and the
                next timer continuation handles the remaining cached photos.
        """
        viewer = object.__new__(native_gallery_viewer._NativeGalleryViewer)
        photos = tuple(
            LinkedPhoto(TEST_FIRST_IMAGE_ID + index, f'Warm {index}')
            for index in range(
                native_gallery_viewer.MEDIA_THUMBNAIL_GUI_SLICE_SIZE
                + TEST_WARM_SLICE_EXTRA_PHOTOS
            )
        )
        viewer._closed = False
        viewer.window = self.root
        viewer.session = mock.create_autospec(
            LinkingSession, instance=True, spec_set=True,
        )
        viewer.session.can_read_media.return_value = True
        viewer.session.can_reuse_cached_media.return_value = True
        viewer.session.can_read_admin_media.return_value = False
        viewer.gallery = LinkedGallery(
            TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Warm slice',
        )
        viewer._photos = photos
        viewer._photo_images = [None] * len(photos)
        viewer._admitted_thumbnail_indices = set(range(len(photos)))
        viewer._visible_thumbnail_indices = set(range(len(photos)))
        viewer._thumbnail_pending = []
        viewer._thumbnail_gui_after_id = None
        viewer._warm_thumbnail_pending = [
            (TEST_GALLERY_ID, index, photo)
            for index, photo in enumerate(photos)
        ]
        viewer._thumbnail_tk_ms = 0.0
        viewer._thumbnail_warm_cache_hit_count = 0

        def make_copy(
            _photo: LinkedPhoto,
            *,
            expected_key: (
                tuple[int, int, bool | None, str | None, int | None] | None
            ) = None,
        ) -> 'Image.Image':
            """Return a separate tiny raster for one simulated cache hit.

            Args:
                _photo (LinkedPhoto): Descriptor looked up by the warm slice.
                expected_key (tuple[int, int, bool | None, str | None, int | None] | None):
                    Key observed by the authorized cache probe.

            Returns:
                Image.Image: Independent RGB copy owned by the GUI slice.
            """
            self.assertIsNotNone(expected_key)
            return Image.new('RGB', (2, 2), (20, 40, 60))

        def attach(
            _index: int,
            image: 'Image.Image | None',
            *,
            remember: bool = True,
        ) -> None:
            """Release the fake attached raster after counting its admission.

            Args:
                _index (int): Zero-based tile supplied by the warm slice.
                image (Image.Image | None): Simulated decoded cache copy.
                remember (bool): Whether a fresh read should update the cache.

            Returns:
                None: The fixture retains no fake Pillow resource.
            """
            self.assertFalse(remember)
            if image is not None:
                image.close()

        viewer._cached_thumbnail = mock.Mock(side_effect=make_copy)
        viewer._show_thumbnail = mock.Mock(side_effect=attach)
        with mock.patch.object(
            native_gallery_viewer.time, 'perf_counter', return_value=0.0,
        ):
            viewer._drain_warm_thumbnail_slice()
        self.assertEqual(
            native_gallery_viewer.MEDIA_THUMBNAIL_GUI_SLICE_SIZE,
            viewer._show_thumbnail.call_count,
        )
        self.assertIsNotNone(viewer._thumbnail_gui_after_id)
        self.assertEqual(
            TEST_WARM_SLICE_EXTRA_PHOTOS,
            len(viewer._warm_thumbnail_pending),
        )

        continuation_id = viewer._thumbnail_gui_after_id
        self.root.after_cancel(continuation_id)
        viewer._thumbnail_gui_after_id = None
        with mock.patch.object(
            native_gallery_viewer.time, 'perf_counter', return_value=0.0,
        ):
            viewer._drain_warm_thumbnail_slice()
        self.assertEqual(len(photos), viewer._show_thumbnail.call_count)
        self.assertEqual([], viewer._warm_thumbnail_pending)

        # One simulated attachment costs 20 ms, above the cooperative 8-ms
        # budget. Complete it, then defer the second item to a new callback.
        viewer._warm_thumbnail_pending = [
            (TEST_GALLERY_ID, index, photos[index])
            for index in range(TEST_WARM_SLICE_EXTRA_PHOTOS)
        ]
        before = viewer._show_thumbnail.call_count
        with mock.patch.object(
            native_gallery_viewer.time, 'perf_counter',
            side_effect=(0.0, 0.0, 0.020, 0.020),
        ):
            viewer._drain_warm_thumbnail_slice()
        self.assertEqual(before + 1, viewer._show_thumbnail.call_count)
        self.assertEqual(1, len(viewer._warm_thumbnail_pending))
        viewer._cancel_thumbnail_gui_slice()

    def test_departed_thumbnail_batch_yields_to_new_visible_indices(self) -> None:
        """Cancel an entirely departed batch and prioritize new visible tiles.

        Returns:
            None: A deterministic viewport jump cancels old reads, removes
                departed pending indices and orders visible warm work first.
        """
        page, presentation, image_bytes = self._gallery_data()
        photos = tuple(
            LinkedPhoto(TEST_FIRST_IMAGE_ID + index, f'Viewport {index}')
            for index in range(TEST_SCROLL_PHOTO_COUNT)
        )
        for index, photo in enumerate(photos):
            image_bytes[photo.image_id] = self._png((index, 70, 100))
        page = LinkedMediaPage(page.gallery_id, page.parent_id, photos, None)
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Viewport jump'),
        )
        self._wait_until(
            lambda: viewer._worker is None
            and bool(viewer._admitted_thumbnail_indices)
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices)
            and not viewer._thumbnail_disk_lane.busy()
            and not viewer._warm_thumbnail_pending
            and viewer._thumbnail_gui_after_id is None
            and viewer._viewport_after_id is None,
            'The viewport fixture did not finish its first admitted band.',
        )
        columns = viewer._grid_columns
        visible = tuple(range(len(photos) - columns, len(photos)))
        previous_row = visible[0] - columns
        batch = tuple(range(native_gallery_viewer.MEDIA_THUMBNAIL_BATCH_SIZE))
        for index in (*batch, *visible, previous_row):
            viewer._release_thumbnail_image(index)
        viewer._thumbnail_pending = list(batch)
        viewer._thumbnail_batch_indices = batch
        viewer._worker_kind = 'thumbnail_batch'
        viewer._worker = mock.Mock()
        viewer._thumbnail_cancelled.clear()
        viewer._warm_thumbnail_pending = [
            (TEST_GALLERY_ID, previous_row, photos[previous_row]),
        ]
        viewer._cancel_grid_viewport_callback()
        timer_identity = 'test-departed-viewport'
        viewer._viewport_after_id = timer_identity
        with mock.patch.object(
            viewer, '_visible_photo_indices', return_value=visible,
        ), mock.patch.object(
            viewer, '_drain_warm_thumbnail_slice',
        ), mock.patch.object(viewer, '_dispatch_pending_work'):
            viewer._apply_grid_viewport(timer_identity)
        self.assertTrue(viewer._thumbnail_cancelled.is_set())
        self.assertTrue(set(batch).isdisjoint(viewer._thumbnail_pending))
        self.assertEqual(
            visible,
            tuple(item[1] for item in viewer._warm_thumbnail_pending[:columns]),
        )
        viewer._worker = None
        viewer._worker_kind = None
        viewer.close()

    def test_equal_and_failed_refresh_restore_interrupted_warm_work(self) -> None:
        """Resume pending cache admission after equal or failed refresh replies.

        Returns:
            None: Interrupted tiles recover without a scroll event, rebuilding
                widgets or requesting another thumbnail from the transport.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Refresh warm work'),
        )
        self._wait_until(
            lambda: viewer._worker is None and all(viewer._photo_images),
            'The refresh fixture did not finish its initial thumbnails.',
        )
        tiles = tuple(viewer._tile_buttons)
        retained = viewer._photo_images[0]
        reads = session.media_photo.call_count
        for failure in (False, True):
            with self.subTest(failed_refresh=failure):
                viewer._release_thumbnail_image(1)
                viewer._warm_thumbnail_pending = [
                    (TEST_GALLERY_ID, 1, page.photos[1]),
                ]
                session.media_page.side_effect = (
                    native_gallery_viewer.LinkingError('Fixture refusal.')
                    if failure else None
                )
                viewer.refresh_photos()
                self._wait_until(
                    lambda: viewer._worker is None
                    and viewer._photo_images[1] is not None,
                    'An interrupted warm tile did not resume after refresh.',
                )
                self.assertEqual(tiles, tuple(viewer._tile_buttons))
                self.assertIs(retained, viewer._photo_images[0])
                self.assertEqual(reads, session.media_photo.call_count)
        viewer.close()

    def test_worker_exit_cannot_strand_its_last_queued_thumbnail(self) -> None:
        """Deliver a result published between queue inspection and worker exit.

        Returns:
            None: Generation and worker survive until the final queued image is
                scheduled and attached; a duplicate current result is then freed.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Queue exit race'),
        )
        self._wait_until(
            lambda: viewer._worker is None and all(viewer._photo_images),
            'The queue fixture did not finish its initial thumbnails.',
        )
        generation = viewer._generation
        previous_image = viewer._photo_images[0]
        viewer._release_thumbnail_image(0)
        result = native_gallery_viewer._ThumbnailResult(
            TEST_GALLERY_ID, 0, page.photos[0], Image.new('RGB', (1, 1)),
            MediaReadTimings(), 0.0, time.perf_counter(),
        )
        worker = mock.Mock()

        def exit_after_publication() -> bool:
            """Publish precisely after the poll's first empty-queue check.

            Returns:
                bool: False, modeling a producer that has now fully exited.
            """
            viewer._events.put((generation, 'thumbnail_batch_item', result))
            worker.is_alive.side_effect = None
            worker.is_alive.return_value = False
            return False

        worker.is_alive.side_effect = exit_after_publication
        viewer._worker = worker
        viewer._worker_kind = 'thumbnail_batch'
        viewer._poll_queue()
        self.assertEqual(generation, viewer._generation)
        self.assertIs(worker, viewer._worker)
        self.assertEqual(1, viewer._events.qsize())
        self.assertIsNotNone(viewer._after_id)
        self.root.after_cancel(viewer._after_id)
        viewer._after_id = None
        viewer._poll_queue()
        self.assertTrue(viewer._events.empty())
        self.assertIsNone(viewer._worker)
        self.assertIsNotNone(viewer._photo_images[0])
        self.assertIsNot(previous_image, viewer._photo_images[0])
        with self.assertRaises(ValueError):
            result.image.getpixel((0, 0))

        # A duplicate current-proof result must not replace a real Tk image.
        retained = viewer._photo_images[0]
        duplicate_image = Image.new('RGB', (1, 1))
        duplicate = native_gallery_viewer._ThumbnailResult(
            TEST_GALLERY_ID, 0, page.photos[0], duplicate_image,
            MediaReadTimings(), 0.0, time.perf_counter(),
        )
        discarded_before = viewer._thumbnail_discarded_count
        viewer._events.put((
            viewer._generation, 'thumbnail_batch_item', duplicate,
        ))
        viewer._poll_queue()
        self.assertIs(retained, viewer._photo_images[0])
        self.assertEqual(
            discarded_before + 1, viewer._thumbnail_discarded_count,
        )
        with self.assertRaises(ValueError):
            duplicate_image.getpixel((0, 0))
        viewer.close()

    def test_stale_and_closed_thumbnail_results_release_rasters(self) -> None:
        """Close image results rejected by generation and viewer teardown.

        Returns:
            None: Neither an obsolete descriptor result nor a queued close-time
                raster remains owned by the result queue.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session,
            LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Stale'),
        )
        self._wait_until(
            lambda: viewer._worker is None and all(viewer._photo_images),
            'The stale-result fixture did not finish its initial thumbnails.',
        )
        stale_image = Image.new('RGB', (3, 3), (10, 20, 30))
        stale = native_gallery_viewer._ThumbnailResult(
            TEST_GALLERY_ID, 0, page.photos[0], stale_image,
            MediaReadTimings(), 0.0, time.perf_counter(),
        )
        viewer._events.put((viewer._generation - 1, 'thumbnail_batch_item', stale))
        viewer._poll_queue()
        with self.assertRaises(ValueError):
            stale_image.getpixel((0, 0))

        retained = viewer._photo_images[0]
        for gallery_id, descriptor in (
            (TEST_GALLERY_ID, LinkedPhoto(page.photos[0].image_id, 'Old title')),
            (TEST_PARENT_GALLERY_ID, page.photos[0]),
        ):
            with self.subTest(gallery_id=gallery_id):
                rejected = Image.new('RGB', (1, 1))
                result = native_gallery_viewer._ThumbnailResult(
                    gallery_id, 0, descriptor, rejected,
                    MediaReadTimings(), 0.0, time.perf_counter(),
                )
                viewer._events.put((
                    viewer._generation, 'thumbnail_batch_item', result,
                ))
                viewer._poll_queue()
                with self.assertRaises(ValueError):
                    rejected.getpixel((0, 0))
                self.assertIs(retained, viewer._photo_images[0])

        queued_image = Image.new('RGB', (3, 3), (30, 20, 10))
        queued = native_gallery_viewer._ThumbnailResult(
            TEST_GALLERY_ID, 0, page.photos[0], queued_image,
            MediaReadTimings(), 0.0, time.perf_counter(),
        )
        viewer._events.put((viewer._generation, 'thumbnail_batch_item', queued))
        viewer.close()
        self.assertTrue(viewer._events.empty())
        with self.assertRaises(ValueError):
            queued_image.getpixel((0, 0))

    def test_warm_hit_during_transport_and_close_during_decode(self) -> None:
        """Apply a warm hit during blocked I/O and release a late decode on close.

        Returns:
            None: Warm Tk attachment needs no transport completion, and a
                decoded raster finishing after close never enters the queue.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Controlled close'),
        )
        self._wait_until(
            lambda: viewer._worker is None and all(viewer._photo_images),
            'The close fixture did not finish its initial thumbnails.',
        )
        read_started, release_read = threading.Event(), threading.Event()
        decode_started, release_decode = threading.Event(), threading.Event()
        self.addCleanup(release_read.set)
        self.addCleanup(release_decode.set)
        decoded: list['Image.Image'] = []
        original_read = session.media_photo.side_effect

        def hold_transport() -> None:
            """Hold the fake byte source until Tk has exercised its warm path.

            Returns:
                None: The controlled transport resumes after explicit release.
            """
            read_started.set()
            if not release_read.wait(TEST_GUI_WAIT_SECONDS):
                raise TimeoutError('The controlled read was not released.')

        def hold_decode(_body: bytes) -> 'Image.Image':
            """Keep a worker-owned raster until viewer teardown has completed.

            Args:
                _body (bytes): Bounded synthetic thumbnail response bytes.

            Returns:
                Image.Image: One independent RGB pixel transferred to the worker.
            """
            image = Image.new('RGB', (1, 1))
            decoded.append(image)
            decode_started.set()
            if not release_decode.wait(TEST_GUI_WAIT_SECONDS):
                image.close()
                raise TimeoutError('The controlled decoder was not released.')
            return image

        session.media_photo.side_effect = lambda *args, **kwargs: (
            hold_transport(), original_read(*args, **kwargs),
        )[1]
        viewer._release_thumbnail_image(0)
        viewer._release_thumbnail_image(1)
        viewer._thumbnail_pending = [0]
        viewer._warm_thumbnail_pending = []
        cancelled_before = viewer._thumbnail_cancelled_read_count
        with mock.patch.object(viewer, '_prepare_thumbnail', side_effect=hold_decode):
            viewer._load_next_thumbnail()
            worker = viewer._worker
            self.assertIsNotNone(worker)
            self.assertTrue(read_started.wait(TEST_GUI_WAIT_SECONDS))
            reads = session.media_photo.call_count
            viewer._warm_thumbnail_pending = [
                (TEST_GALLERY_ID, 1, page.photos[1]),
            ]
            viewer._drain_warm_thumbnail_slice()
            self.assertIsNotNone(viewer._photo_images[1])
            self.assertEqual(reads, session.media_photo.call_count)
            release_read.set()
            self.assertTrue(decode_started.wait(TEST_GUI_WAIT_SECONDS))
            viewer.close()
            release_decode.set()
            worker.join(TEST_WORKER_JOIN_SECONDS)
            self.assertFalse(worker.is_alive())
        self.assertTrue(viewer._events.empty())
        self.assertEqual(
            cancelled_before + 1, viewer._thumbnail_cancelled_read_count,
        )
        self.assertEqual(1, len(decoded))
        with self.assertRaises(ValueError):
            decoded[0].getpixel((0, 0))

    def test_thumbnail_batch_stops_at_throttle_and_retries_explicitly(self) -> None:
        """Stop a serial batch at HTTP 429 and resume after Retry.

        Returns:
            None: The initial batch issues one throttled read; explicit retry
                resumes bounded thumbnail loading with the same descriptors.
        """
        page, presentation, image_bytes = self._gallery_data()
        photos = tuple(
            LinkedPhoto(TEST_FIRST_IMAGE_ID + index, f'Throttle {index}')
            for index in range(TEST_SCROLL_PHOTO_COUNT)
        )
        for index, photo in enumerate(photos):
            image_bytes[photo.image_id] = self._png((index, 50, 100))
        page = LinkedMediaPage(page.gallery_id, page.parent_id, photos, None)
        session = self._session(page, presentation, image_bytes)
        session.media_photo.side_effect = LinkingMediaThrottled()
        viewer = self._open_viewer(
            session,
            LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, '429 batch'),
        )
        self._wait_until(
            lambda: viewer._media_throttled
            and viewer.retry_button.winfo_manager() != '',
            'The thumbnail batch did not expose an explicit throttle retry.',
        )
        self.assertEqual(1, session.media_photo.call_count)
        self.assertEqual('thumbnail', viewer._retry_kind)

        session.media_photo.side_effect = None
        session.media_photo.return_value = (image_bytes[photos[0].image_id], 'image/png')
        viewer.retry_button.invoke()
        self._wait_until(
            lambda: viewer._worker is None
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices),
            'Explicit retry did not resume the admitted thumbnail batch.',
        )
        self.assertGreater(session.media_photo.call_count, 1)
        self.assertFalse(viewer._media_throttled)
        viewer.close()

    def test_foreground_photo_selection_interrupts_thumbnail_batch(self) -> None:
        """Interrupt queued thumbnail reads when a detail becomes foreground.

        Returns:
            None: The in-flight thumbnail is followed immediately by the
                selected detail; remaining grid reads cannot precede it.
        """
        page, presentation, image_bytes = self._gallery_data()
        photos = tuple(
            LinkedPhoto(TEST_FIRST_IMAGE_ID + index, f'Foreground {index}')
            for index in range(TEST_SCROLL_PHOTO_COUNT)
        )
        for index, photo in enumerate(photos):
            image_bytes[photo.image_id] = self._png((index, 70, 110))
        page = LinkedMediaPage(page.gallery_id, page.parent_id, photos, None)
        session = self._session(page, presentation, image_bytes)
        original_read = session.media_photo.side_effect
        first_thumbnail_started = threading.Event()
        release_thumbnail = threading.Event()
        self.addCleanup(release_thumbnail.set)

        def hold_first_thumbnail(
            gallery_id: int,
            image_id: int,
            thumbnail: bool,
            cancelled: threading.Event | None = None,
            *,
            cache_eligible: bool | None = None,
            thumbnail_etag: str | None = None,
            thumbnail_verified_at: float | None = None,
            preload_eligible: bool | None = None,
            preload_thumbnail_etag: str | None = None,
            preload_verified_at: float | None = None,
            timings: MediaReadTimings | None = None,
            use_preloaded_snapshot: bool = False,
        ) -> tuple[bytes, str]:
            """Hold one thumbnail transport while foreground selection arrives.

            Args:
                gallery_id (int): Requested gallery identity.
                image_id (int): Requested photo identity.
                thumbnail (bool): Whether the read is a thumbnail.
                cancelled (threading.Event | None): Viewer-owned cancellation.
                cache_eligible (bool | None): Public cache admission.
                thumbnail_etag (str | None): Current rendition validator.
                thumbnail_verified_at (float | None): Catalogue proof time.
                preload_eligible (bool | None): Admin preload admission.
                preload_thumbnail_etag (str | None): Admin validator.
                preload_verified_at (float | None): Admin proof time.
                timings (MediaReadTimings | None): Optional timing record.
                use_preloaded_snapshot (bool): Full-read saved-snapshot request.

            Returns:
                tuple[bytes, str]: Existing synthetic image and its media type.
            """
            if thumbnail and not first_thumbnail_started.is_set():
                first_thumbnail_started.set()
                if not release_thumbnail.wait(TEST_GUI_WAIT_SECONDS):
                    raise TimeoutError('Foreground test did not release its read')
            return original_read(
                gallery_id, image_id, thumbnail, cancelled,
                cache_eligible=cache_eligible,
                thumbnail_etag=thumbnail_etag,
                thumbnail_verified_at=thumbnail_verified_at,
                preload_eligible=preload_eligible,
                preload_thumbnail_etag=preload_thumbnail_etag,
                preload_verified_at=preload_verified_at,
                timings=timings,
                use_preloaded_snapshot=use_preloaded_snapshot,
            )

        session.media_photo.side_effect = hold_first_thumbnail
        viewer = self._open_viewer(
            session,
            LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Foreground'),
        )
        self._wait_until(
            first_thumbnail_started.is_set,
            'The initial thumbnail read did not start.',
        )
        viewer._select_photo(0)
        release_thumbnail.set()
        self._wait_until(
            lambda: viewer._worker is None
            and viewer._pending_detail_index is None
            and any(not call.kwargs.get('thumbnail', False)
                    for call in session.media_photo.call_args_list),
            'The selected detail did not receive the next serialized read.',
        )
        self.assertEqual(
            1, sum(bool(call.kwargs.get('thumbnail'))
                   for call in session.media_photo.call_args_list),
        )
        self.assertTrue(session.media_photo.call_args_list[0].kwargs['thumbnail'])
        self.assertFalse(session.media_photo.call_args_list[1].kwargs['thumbnail'])
        viewer.close()

    def test_disk_lane_reuses_inflight_identity_after_departure(self) -> None:
        """Re-admit one in-flight disk lookup without a duplicate decode.

        Returns:
            None: The returned tile has one local read and one owned result.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        started = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)
        photo = page.photos[0]
        body = image_bytes[photo.image_id]

        def read_cached(
            gallery_id: int, descriptor: LinkedPhoto, *, thumbnail: bool,
            cancelled: threading.Event, timings: MediaReadTimings,
        ) -> tuple[bytes, str]:
            """Hold the original lookup across a departure and return.

            Args:
                gallery_id (int): Physical gallery whose tile was admitted.
                descriptor (LinkedPhoto): Exact photo descriptor being read.
                thumbnail (bool): Whether the cached rendition is a thumbnail.
                cancelled (threading.Event): Owned worker shutdown signal.
                timings (MediaReadTimings): Mutable local read observations.
            Returns:
                tuple[bytes, str]: Synthetic PNG bytes and their media type.
            """
            started.set()
            if not release.wait(TEST_GUI_WAIT_SECONDS):
                raise TimeoutError('The controlled disk read was not released.')
            return body, 'image/png'

        session.cached_media_photo.side_effect = read_cached
        lane = native_gallery_viewer._ThumbnailDiskLane(
            session, lambda data: Image.open(io.BytesIO(data)).convert('RGB'),
            lambda timings, elapsed: None,
        )
        self.addCleanup(lambda: lane.invalidate(close=True))
        lane.submit(TEST_GALLERY_ID, 0, photo)
        self.assertTrue(started.wait(TEST_GUI_WAIT_SECONDS))
        lane.retain(TEST_GALLERY_ID, page.photos, ())
        lane.retain(TEST_GALLERY_ID, page.photos, (0,))
        lane.submit(TEST_GALLERY_ID, 0, photo)
        release.set()
        received: list['native_gallery_viewer._ThumbnailResult'] = []

        def completed() -> bool:
            """Drain owned local results until the lane has no remaining work.

            Returns:
                bool: Whether the returned identity has settled completely.
            """
            result = lane.pop_ready()
            if result is not None:
                received.append(result)
            return bool(received) and not lane.busy()

        self._wait_until(completed, 'The re-admitted disk lookup did not settle.')
        self.assertEqual(1, session.cached_media_photo.call_count)
        self.assertEqual(1, len(received))
        self.assertEqual(photo, received[0].photo)
        self.assertIsNotNone(received[0].image)
        received[0].image.close()

    def test_large_viewport_return_reuses_decoded_visible_band(self) -> None:
        """Return over 48 visible tiles from bounded memory without new reads.

        Returns:
            None: The original visible tiles reuse memory and Tk retains only
                the current viewport and its configured overscan rows.
        """
        self.root.overrideredirect(True)
        self.root.maxsize(*TEST_WIDE_VIEWPORT_SIZE)
        self.root.minsize(*TEST_WIDE_VIEWPORT_SIZE)
        width, height = TEST_WIDE_VIEWPORT_SIZE
        self.root.geometry(f'{width}x{height}')
        page, presentation, image_bytes = self._gallery_data()
        photos = tuple(
            LinkedPhoto(TEST_FIRST_IMAGE_ID + index, f'Wide photo {index}')
            for index in range(TEST_WIDE_VIEWPORT_PHOTO_COUNT)
        )
        for index, photo in enumerate(photos):
            with Image.new(
                'RGB', native_gallery_viewer.MEDIA_THUMBNAIL_SIZE,
                (index, 80, 160),
            ) as raster:
                encoded = io.BytesIO()
                raster.save(encoded, format='PNG')
                image_bytes[photo.image_id] = encoded.getvalue()
        # Preserve the production metadata cap: this wide viewport spans two
        # real native pages, rather than bypassing the page-size validator.
        page_size = native_gallery_viewer.NATIVE_MEDIA_PAGE_SIZE
        first_page = LinkedMediaPage(
            page.gallery_id, page.parent_id, photos[:page_size],
            photos[page_size - 1].image_id,
        )
        last_page = LinkedMediaPage(
            page.gallery_id, page.parent_id, photos[page_size:], None,
        )
        session = self._session(first_page, presentation, image_bytes)
        session.media_page.side_effect = (first_page, last_page)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Wide viewport'),
        )
        try:
            self._wait_until(
                lambda: len(viewer._photos) == len(photos)
                and len(viewer._visible_thumbnail_indices) > 48
                and viewer._admitted_thumbnail_indices <= viewer._thumbnail_loaded
                and viewer._worker is None and not viewer._thumbnail_disk_lane.busy()
                and not viewer._warm_thumbnail_pending
                and viewer._thumbnail_gui_after_id is None
                and viewer._viewport_after_id is None,
                'The wide viewport did not settle with more than 48 visible tiles.',
            )
        except AssertionError as exc:
            self.fail(
                f'{exc} root={self.root.winfo_width()}x{self.root.winfo_height()} '
                f'canvas={viewer.grid_canvas.winfo_width()}x'
                f'{viewer.grid_canvas.winfo_height()} '
                f'columns={viewer._grid_columns} '
                f'loaded={len(viewer._thumbnail_loaded)} '
                f'pending={len(viewer._thumbnail_pending)} '
                f'warm_pending={len(viewer._warm_thumbnail_pending)} '
                f'disk_busy={viewer._thumbnail_disk_lane.busy()} '
                f'worker_active={viewer._worker is not None} '
                f'worker_alive={viewer._worker is not None and viewer._worker.is_alive()} '
                f'worker_kind={viewer._worker_kind} '
                f'gui_timer={viewer._thumbnail_gui_after_id is not None} '
                f'viewport_timer={viewer._viewport_after_id is not None} '
                f'{viewer.render_diagnostics()}'
            )
        first_visible = set(viewer._visible_thumbnail_indices)
        # Two legal pages cover all 120 descriptors; no repeated cursor reads.
        self.assertEqual(2, session.media_page.call_count)
        initial_reads = session.media_photo.call_count
        viewer.grid_canvas.yview_moveto(1.0)
        viewer._on_grid_viewport(None)
        self._wait_until(
            lambda: bool(viewer._visible_thumbnail_indices - first_visible)
            and viewer._admitted_thumbnail_indices <= viewer._thumbnail_loaded
            and viewer._worker is None and not viewer._thumbnail_disk_lane.busy()
            and not viewer._warm_thumbnail_pending
            and viewer._thumbnail_gui_after_id is None
            and viewer._viewport_after_id is None,
            'The lower viewport did not settle.',
        )
        reads = session.media_photo.call_count
        memory_hits = viewer._thumbnail_warm_cache_hit_count
        created_images = viewer._thumbnail_image_create_count
        viewer.grid_canvas.yview_moveto(0.0)
        viewer._on_grid_viewport(None)
        self._wait_until(
            lambda: viewer._visible_thumbnail_indices == first_visible
            and viewer._admitted_thumbnail_indices <= viewer._thumbnail_loaded
            and viewer._worker is None and not viewer._thumbnail_disk_lane.busy()
            and not viewer._warm_thumbnail_pending
            and viewer._thumbnail_gui_after_id is None
            and viewer._viewport_after_id is None,
            'The first visible band did not return from memory.',
        )
        self.assertEqual(reads, session.media_photo.call_count)
        retained = sum(image is not None for image in viewer._photo_images)
        self.assertLessEqual(retained, len(viewer._admitted_thumbnail_indices))
        self.assertLessEqual(
            viewer._thumbnail_memory.size_bytes,
            native_gallery_viewer.MEDIA_THUMBNAIL_CACHE_MAX_BYTES,
        )
        measurement = {
            'python_version': sys.version,
            'platform': sys.platform,
            'requested_window_width': width,
            'requested_window_height': height,
            'actual_canvas_width': viewer.grid_canvas.winfo_width(),
            'actual_canvas_height': viewer.grid_canvas.winfo_height(),
            'visible_count': len(first_visible),
            'admitted_count': len(viewer._admitted_thumbnail_indices),
            'initial_total_requests': initial_reads,
            'lower_total_requests': reads,
            'return_requests_delta': session.media_photo.call_count - reads,
            'return_memory_hits_delta': (
                viewer._thumbnail_warm_cache_hit_count - memory_hits
            ),
            'return_created_images_delta': (
                viewer._thumbnail_image_create_count - created_images
            ),
            'memory_bytes': viewer._thumbnail_memory.size_bytes,
            'memory_bytes_limit': native_gallery_viewer.MEDIA_THUMBNAIL_CACHE_MAX_BYTES,
            'data_source': 'synthetic in-memory PNG responses',
        }
        evidence = WINAPP_DIR.parent / 'cache' / 'test-audit'
        evidence.mkdir(parents=True, exist_ok=True)
        (evidence / 'photos-viewer-measurement.json').write_text(
            json.dumps(measurement, indent=2) + '\n', encoding='utf-8',
        )
        print(
            f'wide viewport: visible={len(first_visible)} '
            f'admitted={len(viewer._admitted_thumbnail_indices)} '
            f'tk_images={retained} memory_bytes={viewer._thumbnail_memory.size_bytes}'
        )
        viewer.close()

    def _case_dense_return_renews_catalogue_and_reuses_disk(self) -> None:
        """Reuse real disk files after dense scrolling and proof expiration.

        Returns:
            None: Returning uses RAM after metadata renewal, then hash-verified
                disk after RAM eviction, with no additional binary requests.
        """
        self.root.overrideredirect(True)
        self.root.maxsize(*TEST_WIDE_VIEWPORT_SIZE)
        self.root.minsize(*TEST_WIDE_VIEWPORT_SIZE)
        self.root.geometry('{}x{}'.format(*TEST_WIDE_VIEWPORT_SIZE))
        page, presentation, image_bytes = self._gallery_data()
        photos = tuple(
            LinkedPhoto(
                TEST_FIRST_IMAGE_ID + index, f'Dense cached photo {index}',
                cache_eligible=True,
            )
            for index in range(TEST_DENSE_CACHE_PHOTO_COUNT)
        )
        for index, photo in enumerate(photos):
            # 144 by 62 models the natural ultrawide screenshots in this case.
            with Image.new('RGB', (144, 62), (index, 80, 160)) as raster:
                encoded = io.BytesIO()
                raster.save(encoded, format='PNG')
                image_bytes[photo.image_id] = encoded.getvalue()
        photos = tuple(
            replace(photo, thumbnail_etag=(
                '"' + hashlib.sha256(image_bytes[photo.image_id]).hexdigest()
                + '"'
            )) for photo in photos
        )
        session = self._session(page, presentation, image_bytes)
        real_session = LinkingSession(
            'https://gallery.example/Galerie', 'pgla_' + 'A' * 43,
            time.monotonic() + TEST_CACHE_SESSION_SECONDS, READ_SCOPE,
        )
        self.addCleanup(real_session.close)

        def catalogue(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> LinkedMediaPage:
            """Return a real-sized page with freshly authorized validators.

            Args:
                gallery_id (int): Exact synthetic gallery requested.
                after_id (int | None): Last photo ID of the preceding page.
                cancelled (threading.Event | None): Operation cancellation.

            Returns:
                LinkedMediaPage: Ordered descriptors with a fresh proof time.
            """
            self.assertEqual(TEST_GALLERY_ID, gallery_id)
            self.assertFalse(cancelled is not None and cancelled.is_set())
            start = 0 if after_id is None else (
                after_id - TEST_FIRST_IMAGE_ID + 1
            )
            rows = tuple(
                replace(photo, thumbnail_verified_at=time.monotonic())
                for photo in photos[
                    start:start + native_gallery_viewer.NATIVE_MEDIA_PAGE_SIZE
                ]
            )
            cursor = (
                rows[-1].image_id if rows
                and start + len(rows) < len(photos) else None
            )
            return LinkedMediaPage(TEST_GALLERY_ID, None, rows, cursor)

        def network(
            gallery_id: int, kind: str, image_id: int,
            cancelled: threading.Event | None = None,
            if_none_match: str | None = None, *,
            timings: MediaReadTimings | None = None,
        ) -> tuple[bytes, str, str, bool]:
            """Provide bytes only for a genuine cache miss through transport.

            Args:
                gallery_id (int): Exact synthetic gallery requested.
                kind (str): Thumbnail rendition requested by the real session.
                image_id (int): Synthetic media identity.
                cancelled (threading.Event | None): Operation cancellation.
                if_none_match (str | None): Conditional strong validator.
                timings (MediaReadTimings | None): Optional transport metrics
                    supplied by the actual thumbnail read owner.

            Returns:
                tuple[bytes, str, str, bool]: PNG bytes, type, validator and
                    explicit cache permission for the isolated response.
            """
            self.assertEqual(TEST_GALLERY_ID, gallery_id)
            self.assertEqual('thumbnail', kind)
            body = image_bytes[image_id]
            return body, 'image/png', '"' + hashlib.sha256(body).hexdigest() + '"', True

        session.media_page.side_effect = catalogue
        session.media_photo.side_effect = real_session.media_photo
        session.cached_media_photo.side_effect = real_session.cached_media_photo
        with TemporaryDirectory(prefix='phpgallery-dense-return-') as directory:
            cache = GalleryMediaCache(
                Path(directory), native_gallery_viewer.NATIVE_MEDIA_MAX_BYTES,
                thumbnails=True,
            )
            with mock.patch.object(
                GalleryMediaCache, 'for_current_user', return_value=cache,
            ), mock.patch.object(real_session, '_media_read', side_effect=network) as transport:
                # Model completed thumbnail preload with real durable writes.
                # Seeding disk is setup, outside the unchanged six-second GUI
                # deadline; the measured scroll paths must only reuse it.
                for photo in photos:
                    self.assertEqual(
                        (image_bytes[photo.image_id], 'image/png'),
                        real_session.media_photo(
                            TEST_GALLERY_ID, photo.image_id, thumbnail=True,
                            cache_eligible=True, thumbnail_etag=photo.thumbnail_etag,
                            thumbnail_verified_at=time.monotonic(),
                        ),
                    )
                initial_transfers = transport.call_count
                viewer = self._open_viewer(
                    session, LinkedGallery(TEST_GALLERY_ID, None, 'Dense cache'),
                )
                self.addCleanup(viewer.close)
                viewer.set_card_format('21:9')

                def settled() -> bool:
                    """Wait for visible admission and every owned worker slice.

                    Returns:
                        bool: True only when all admitted tiles have settled.
                    """
                    return bool(
                        viewer._visible_thumbnail_indices
                        and viewer._admitted_thumbnail_indices
                        <= viewer._thumbnail_loaded
                        and viewer._worker is None
                        and not viewer._thumbnail_disk_lane.busy()
                        and not viewer._warm_thumbnail_pending
                        and viewer._thumbnail_gui_after_id is None
                        and viewer._viewport_after_id is None
                    )

                self._wait_until(settled, 'Dense top thumbnails did not settle.')
                self.assertEqual(initial_transfers, transport.call_count)
                first_visible = set(viewer._visible_thumbnail_indices)
                self.assertGreater(len(first_visible), 48)
                viewer.grid_canvas.yview_moveto(1.0)
                viewer._on_grid_viewport(None)
                self._wait_until(
                    lambda: settled() and viewer._next_after is None,
                    'Dense bottom thumbnails did not settle.',
                )
                self.assertEqual(len(photos), len(viewer._photos))
                self.assertTrue(first_visible - viewer._visible_thumbnail_indices)
                transfers = transport.call_count
                page_reads = session.media_page.call_count
                hits = viewer._thumbnail_warm_cache_hit_count
                expired_at = (
                    time.monotonic()
                    - native_gallery_viewer.MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS
                    - 1.0
                )
                viewer._photos = tuple(
                    replace(photo, thumbnail_verified_at=expired_at)
                    for photo in viewer._photos
                )
                # Expire the stored observations as well: refreshing the page
                # must authorize the original bytes, not restart their age.
                memory = viewer._thumbnail_memory
                with memory.lock:
                    for key, (image, size, _stored_at) in tuple(memory.entries.items()):
                        memory.entries[key] = image, size, expired_at
                started = time.perf_counter()
                viewer.grid_canvas.yview_moveto(0.0)
                viewer._on_grid_viewport(None)
                self._wait_until(
                    lambda: settled() and viewer._visible_thumbnail_indices == first_visible,
                    'Expired top thumbnails did not return after catalogue renewal.',
                )
                warm_ms = (time.perf_counter() - started) * 1000.0
                self.assertEqual(transfers, transport.call_count)
                self.assertEqual(1, viewer._thumbnail_proof_refresh_count)
                self.assertGreater(session.media_page.call_count, page_reads)
                self.assertGreater(viewer._thumbnail_warm_cache_hit_count, hits)
                writes = cache.operation_counts()['writes']
                self.assertGreater(writes, 0)
                # Reopen the real cache owner and force a complete RAM miss.
                cache = GalleryMediaCache(
                    Path(directory), native_gallery_viewer.NATIVE_MEDIA_MAX_BYTES,
                    thumbnails=True,
                )
                with mock.patch.object(GalleryMediaCache, 'for_current_user', return_value=cache):
                    memory.clear()
                    for index in tuple(viewer._thumbnail_loaded):
                        viewer._release_thumbnail_image(index)
                    disk_hits = viewer._thumbnail_source_disk_count
                    started = time.perf_counter()
                    viewer._on_grid_viewport(None)
                    self._wait_until(settled, 'Persisted thumbnails did not return from disk.')
                    disk_ms = (time.perf_counter() - started) * 1000.0
                self.assertEqual(transfers, transport.call_count)
                self.assertGreater(viewer._thumbnail_source_disk_count, disk_hits)
                evidence = WINAPP_DIR.parent / 'cache' / 'test-audit'
                evidence.mkdir(parents=True, exist_ok=True)
                (evidence / 'thumbnail-cache-return.json').write_text(
                    json.dumps({
                        'photos': len(photos), 'format': '21:9',
                        'visible': len(first_visible),
                        'persistent_writes': writes,
                        'return_binary_requests': transport.call_count - transfers,
                        'proof_refreshes': viewer._thumbnail_proof_refresh_count,
                        'return_memory_hits': viewer._thumbnail_warm_cache_hit_count - hits,
                        'return_disk_hits': viewer._thumbnail_source_disk_count - disk_hits,
                        'warm_return_ms': warm_ms, 'disk_return_ms': disk_ms,
                        'disk_read_ms': viewer._thumbnail_disk_ms,
                        'decode_ms': viewer._thumbnail_decode_ms,
                        'tk_attach_ms': viewer._thumbnail_tk_ms,
                        'queue_wait_ms': viewer._thumbnail_queue_wait_ms,
                        'source': 'real Tk, temporary cache, synthetic PNG transport',
                    }, indent=2) + '\n', encoding='utf-8',
                )
                viewer.close()

    def test_disk_hits_attach_while_unrelated_transport_is_blocked(self) -> None:
        """Deliver disk hits without using the occupied network worker.

        Returns:
            None: Every visible disk hit attaches on Tk without a duplicate
                media request; decoding stays on the local worker thread.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        started = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)
        decode_threads: list[int] = []
        gui_thread = threading.get_ident()
        session.cached_media_photo.side_effect = lambda gallery_id, photo, **kwargs: (
            image_bytes[photo.image_id], 'image/png'
        )
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Disk hits'),
        )
        self._wait_until(
            lambda: viewer._visible_thumbnail_indices <= viewer._thumbnail_loaded
            and bool(viewer._visible_thumbnail_indices)
            and viewer._worker is None and not viewer._thumbnail_disk_lane.busy(),
            'Initial disk-backed thumbnails did not attach.',
        )
        for index in tuple(viewer._thumbnail_loaded):
            viewer._release_thumbnail_image(index)
        viewer._thumbnail_memory.clear()
        original_prepare = viewer._thumbnail_disk_lane._prepare

        def prepare(body: bytes) -> 'Image.Image':
            """Record the decoder thread for one disk-owned PNG body.

            Args:
                body (bytes): Encoded synthetic PNG from the local cache.
            Returns:
                Image.Image: Independent decoded thumbnail owned by the lane.
            """
            decode_threads.append(threading.get_ident())
            return original_prepare(body)

        def block_transport() -> None:
            """Hold unrelated work until the disk path has delivered its hits.

            Returns:
                None: The test releases the worker using its owned event.
            """
            started.set()
            if not release.wait(TEST_GUI_WAIT_SECONDS):
                raise TimeoutError('The controlled transport was not released.')

        viewer._thumbnail_disk_lane._prepare = prepare
        viewer._worker = threading.Thread(target=block_transport, daemon=True)
        viewer._worker_kind = 'detail'
        viewer._worker.start()
        self.assertTrue(started.wait(TEST_GUI_WAIT_SECONDS))
        reads = session.media_photo.call_count
        attach_threads: list[int] = []
        original_photo_image = native_gallery_viewer.ImageTk.PhotoImage

        def attach(
            image: 'Image.Image', *, master: 'tk.Misc',
        ) -> 'ImageTk.PhotoImage':
            """Record the Tk owner before creating one actual image.

            Args:
                image (Image.Image): Decoded synthetic thumbnail raster.
                master (tk.Misc): Tk owner for the created image.
            Returns:
                ImageTk.PhotoImage: Real Tk image created for this tile.
            """
            attach_threads.append(threading.get_ident())
            return original_photo_image(image, master=master)

        with mock.patch.object(
            native_gallery_viewer.ImageTk, 'PhotoImage', side_effect=attach,
        ) as photo_image:
            viewer._on_grid_viewport(None)
            self._wait_until(
                lambda: viewer._visible_thumbnail_indices <= viewer._thumbnail_loaded
                and not viewer._thumbnail_disk_lane.busy(),
                'Local hits did not attach while the transport was held.',
            )
            self.assertGreater(photo_image.call_count, 0)
        self.assertTrue(attach_threads)
        self.assertTrue(all(thread == gui_thread for thread in attach_threads))
        self.assertTrue(decode_threads)
        self.assertTrue(all(thread != gui_thread for thread in decode_threads))
        self.assertEqual(reads, session.media_photo.call_count)
        release.set()
        viewer.close()

    def test_stable_viewport_keeps_network_read_behind_local_pending_tile(self) -> None:
        """Keep an admitted network read when an earlier tile is disk-owned.

        Returns:
            None: An unchanged viewport does not cancel the current network
                batch merely because an independent local result is pending.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Stable viewport'),
        )
        self._wait_until(
            lambda: viewer._worker is None and all(viewer._photo_images),
            'The stable-viewport fixture did not finish its initial thumbnails.',
        )
        for index in range(len(page.photos)):
            viewer._release_thumbnail_image(index)
        viewer._thumbnail_memory.clear()
        # Tile zero is reserved by the independent disk lane. Tile one is the
        # already admitted network operation; both remain visible and wanted.
        viewer._thumbnail_pending = [1]
        viewer._thumbnail_batch_indices = (1,)
        viewer._worker_kind = 'thumbnail_batch'
        viewer._worker = mock.Mock()
        viewer._thumbnail_cancelled.clear()
        viewer._cancel_grid_viewport_callback()
        identity = 'controlled-stable-viewport'
        viewer._viewport_after_id = identity
        with mock.patch.object(
            viewer._thumbnail_disk_lane, 'contains',
            side_effect=lambda gallery_id, index, photo: index == 0,
        ):
            viewer._apply_grid_viewport(identity)
        self.assertFalse(viewer._thumbnail_cancelled.is_set())
        self.assertEqual([1], viewer._thumbnail_pending)
        viewer._worker = None
        viewer._worker_kind = None
        viewer.close()

    def test_decoded_cache_bounds_prioritize_visible_over_overscan(self) -> None:
        """Bound full-sized decoded pixels and reject speculative displacement.

        Returns:
            None: Visible admission evicts oldest history within the byte cap;
                overscan, expiry and validator replacement release ownership.
        """
        self.assertEqual(
            TEST_EXPECTED_THUMBNAIL_CACHE_ENTRY_LIMIT,
            native_gallery_viewer.MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT,
        )
        self.assertEqual(
            TEST_EXPECTED_THUMBNAIL_CACHE_MAX_BYTES,
            native_gallery_viewer.MEDIA_THUMBNAIL_CACHE_MAX_BYTES,
        )
        empty_memory = native_gallery_viewer.GalleryThumbnailMemory()
        self.assertEqual({}, empty_memory.entries)
        self.assertEqual({}, empty_memory.identity_keys)
        self.assertEqual(0, empty_memory.size_bytes)
        empty_memory.clear()
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Byte-bound cache'),
        )
        self._wait_until(
            lambda: viewer._worker is None and all(viewer._photo_images),
            'The byte-bound fixture did not finish its initial thumbnails.',
        )
        extra = LinkedPhoto(TEST_NEXT_PAGE_IMAGE_ID, 'Third descriptor')
        viewer._photos = (*page.photos, extra)
        memory = viewer._thumbnail_memory
        memory.clear()
        self._assert_thumbnail_identity_index(memory)
        # Exactly two maximum-size RGB entries exercise the byte boundary
        # independently of the production entry-count ceiling.
        one_entry_bytes = (
            native_gallery_viewer.MEDIA_THUMBNAIL_SIZE[0]
            * native_gallery_viewer.MEDIA_THUMBNAIL_SIZE[1] * 4
        )
        with mock.patch.object(
            native_gallery_viewer, 'MEDIA_THUMBNAIL_CACHE_MAX_BYTES',
            one_entry_bytes * 2,
        ), mock.patch.object(
            native_gallery_viewer.time, 'monotonic', return_value=0.0,
        ):
            for index in (0, 1):
                viewer._visible_thumbnail_indices = {index}
                viewer._remember_thumbnail(
                    viewer._photos[index],
                    Image.new('RGB', native_gallery_viewer.MEDIA_THUMBNAIL_SIZE),
                )
            self.assertEqual(one_entry_bytes * 2, memory.size_bytes)
            self._assert_thumbnail_identity_index(memory)
            viewer._visible_thumbnail_indices = {0}
            speculative = Image.new(
                'RGB', native_gallery_viewer.MEDIA_THUMBNAIL_SIZE,
            )
            viewer._remember_thumbnail(extra, speculative)
            self.assertEqual(1, memory.admission_refusals)
            self.assertEqual(0, memory.evictions)
            self._assert_thumbnail_identity_index(memory)
            with self.assertRaises(ValueError):
                speculative.getpixel((0, 0))
            viewer._visible_thumbnail_indices = {2}
            viewer._remember_thumbnail(
                extra, Image.new('RGB', native_gallery_viewer.MEDIA_THUMBNAIL_SIZE),
            )
            self.assertEqual(1, memory.evictions)
            self.assertEqual(one_entry_bytes * 2, memory.size_bytes)
            self._assert_thumbnail_identity_index(memory)
            self.assertIsNone(viewer._cached_thumbnail(page.photos[0]))
            changed = LinkedPhoto(extra.image_id, extra.title, thumbnail_etag='"' + 'a' * 64 + '"')
            viewer._photos = (*page.photos, changed)
            viewer._remember_thumbnail(
                changed, Image.new('RGB', native_gallery_viewer.MEDIA_THUMBNAIL_SIZE),
            )
            self.assertEqual(1, memory.validator_changes)
            self._assert_thumbnail_identity_index(memory)
        misses_before_expiry = memory.metrics_snapshot()['misses']
        with mock.patch.object(
            native_gallery_viewer.time, 'monotonic',
            return_value=(
                native_gallery_viewer.MEDIA_THUMBNAIL_MEMORY_FRESH_SECONDS + 1
            ),
        ):
            self.assertIsNone(viewer._cached_thumbnail(changed))
        self.assertEqual(1, memory.expirations)
        self.assertGreater(
            memory.metrics_snapshot()['misses'], misses_before_expiry,
        )
        self.assertEqual(one_entry_bytes, memory.size_bytes)
        self._assert_thumbnail_identity_index(memory)
        retained = tuple(
            item[0] for item in memory.entries.values()
        )
        memory.remove_photos(
            TEST_GALLERY_ID,
            tuple(photo.image_id for photo in viewer._photos),
        )
        self.assertEqual({}, memory.entries)
        self.assertEqual({}, memory.identity_keys)
        self.assertEqual(0, memory.size_bytes)
        for image in retained:
            with self.assertRaises(ValueError):
                image.getpixel((0, 0))
        viewer.close()

    def test_decoded_thumbnail_cache_reuses_verified_album_previews(self) -> None:
        """Reuse authorized thumbnails across mounts and evict obsolete entries.

        Returns:
            None: A revisit hits the cache, while a changed validator reads
                once and occupies the remaining slot under a forced small cap.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        memory = native_gallery_viewer.GalleryThumbnailMemory()
        gallery = LinkedGallery(
            TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album revisit',
        )
        first = self._open_viewer(session, gallery, memory)
        self._wait_until(
            lambda: len(first._photos) == len(page.photos)
            and all(first._photo_images),
            'The first album mount did not fill its visible thumbnail cache.',
        )
        self._assert_thumbnail_identity_index(memory)
        # This fixture allocates one host per mount. Release its geometry so
        # a later viewer has the same viewport as a replacement in Photos.
        first_host = first.frame.master
        first.close()
        self.assertEqual([], first._photo_images)
        self.assertTrue(memory.entries)
        self._assert_thumbnail_identity_index(memory)
        first_host.destroy()
        initial_reads = session.media_photo.call_count
        before_return = memory.metrics_snapshot()

        second = self._open_viewer(session, gallery, memory)
        self._wait_until(
            lambda: len(second._photos) == len(page.photos)
            and all(second._photo_images),
            'A previously visited album did not reuse decoded thumbnails.',
        )
        self.assertEqual(initial_reads, session.media_photo.call_count)
        self.assertGreater(
            memory.metrics_snapshot()['hits'], before_return['hits'],
        )
        self._assert_thumbnail_identity_index(memory)
        second_host = second.frame.master
        second.close()
        second_host.destroy()

        changed = LinkedMediaPage(
            page.gallery_id,
            page.parent_id,
            (
                LinkedPhoto(
                    page.photos[0].image_id,
                    page.photos[0].title,
                    thumbnail_etag=(
                        '"' + format(TEST_FIRST_IMAGE_ID, '064x') + '"'
                    ),
                ),
            ),
            None,
        )
        session.media_page.return_value = changed
        # One retained slot forces eviction as the refreshed raster arrives.
        with mock.patch.object(
            native_gallery_viewer, 'MEDIA_THUMBNAIL_CACHE_ENTRY_LIMIT', 1,
        ):
            third = self._open_viewer(session, gallery, memory)
            self._wait_until(
                lambda: len(third._photos) == len(changed.photos)
                and all(third._photo_images) and third._worker is None,
                'The changed thumbnail did not render and settle.',
            )
        self.assertEqual(initial_reads + 1, session.media_photo.call_count)
        self.assertEqual(1, len(memory.entries))
        self._assert_thumbnail_identity_index(memory)
        self.assertEqual(
            changed.photos[0].thumbnail_etag,
            session.media_photo.call_args.kwargs['thumbnail_etag'],
        )
        third.close()
        self.assertEqual([], third._photo_images)
        self._assert_thumbnail_identity_index(memory)
        retained = tuple(
            item[0] for item in memory.entries.values()
        )
        self.assertEqual(
            {key[:2]: key for key in memory.entries},
            memory.identity_keys,
        )
        before_clear = memory.metrics_snapshot()
        memory.clear()
        after_clear = memory.metrics_snapshot()
        self.assertEqual(
            {
                key: value for key, value in before_clear.items()
                if key not in {'bytes', 'entries'}
            },
            {
                key: value for key, value in after_clear.items()
                if key not in {'bytes', 'entries'}
            },
        )
        self.assertEqual({}, memory.entries)
        self.assertEqual({}, memory.identity_keys)
        self.assertEqual(0, memory.size_bytes)
        for image in retained:
            with self.assertRaises(ValueError):
                image.getpixel((0, 0))

    def test_private_thumbnail_ram_uses_thumbnail_identity_scope(self) -> None:
        """Use thumbnail proof scope for RAM tiles and default scope for detail.

        Returns:
            None: Thumbnail operations opt into thumbnail metadata while detail
                cache identity calls preserve the original default behavior.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Thumbnail identity'),
        )
        self._wait_until(
            lambda: viewer._worker is None and all(viewer._photo_images),
            'Initial thumbnails did not settle before identity checks.',
        )
        verified_at = time.monotonic()
        photo = LinkedPhoto(
            page.photos[0].image_id,
            page.photos[0].title,
            nsfw=False,
            cache_eligible=False,
            preload_eligible=True,
            preload_thumbnail_etag='"' + 'a' * 64 + '"',
            preload_verified_at=verified_at,
        )
        viewer._photos = (photo, *page.photos[1:])
        viewer._visible_thumbnail_indices = {0}
        session.can_read_admin_media.return_value = True
        session.private_preload_cache_identity.return_value = (
            42, photo.preload_thumbnail_etag, verified_at, 7,
        )

        viewer._remember_thumbnail(photo, Image.new('RGB', (12, 12)))
        self.assertTrue(session.private_preload_cache_identity.called)
        self.assertTrue(all(
            call.kwargs.get('thumbnail') is True
            for call in session.private_preload_cache_identity.call_args_list
        ))
        cached_thumbnail = viewer._cached_thumbnail(photo)
        self.assertIsNotNone(cached_thumbnail)
        cached_thumbnail.close()

        session.private_preload_cache_identity.reset_mock()
        viewer._remember_detail(
            photo.image_id, Image.new('RGB', (12, 12)), b'original-body',
        )
        self.assertEqual(1, session.private_preload_cache_identity.call_count)
        self.assertNotIn(
            'thumbnail',
            session.private_preload_cache_identity.call_args.kwargs,
        )
        cached_detail = viewer._cached_detail(photo.image_id)
        self.assertIsNotNone(cached_detail)
        self.assertEqual(2, session.private_preload_cache_identity.call_count)
        self.assertTrue(all(
            'thumbnail' not in call.kwargs
            for call in session.private_preload_cache_identity.call_args_list
        ))
        misses_before_denied_key = (
            viewer._thumbnail_memory.metrics_snapshot()['misses']
        )
        session.can_reuse_cached_media.return_value = False
        self.assertIsNone(viewer._cached_thumbnail(photo))
        self.assertEqual(
            misses_before_denied_key,
            viewer._thumbnail_memory.metrics_snapshot()['misses'],
        )
        viewer.close()

    def _fullscreen_enabled(self, window: 'tk.Misc') -> bool:
        """Interpret Tk's platform-dependent fullscreen attribute result.

        Args:
            window (tk.Misc): Main Tk window queried by the fixture.
        Returns:
            bool: True only when Tk reports fullscreen as enabled.
        """
        value = window.attributes('-fullscreen')
        return str(value).lower() in ('1', 'true')

    def _assert_no_browsing_toplevels(self) -> None:
        """Assert photo browsing has not created a secondary Tk window.

        Returns:
            None: Only the fixture's main root owns native browsing widgets.
        """
        self.assertFalse(any(
            isinstance(child, tk.Toplevel)
            for child in self.root.winfo_children()
        ))

    def test_gallery_controls_use_fresh_text_and_render_both_menu_sizes(self) -> None:
        """Exercise real anchored editors, shared menus and preserved conflict drafts.

        Returns:
            None: Hosted Windows captures desktop/narrow controls and proves that
                edits use fresh server values, preserve drafts and do not replay.
        """

        configure_theme(self.root)

        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Cached title')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        session.can_read_admin_media.return_value = True
        session.can_edit_gallery_metadata.return_value = True
        session.can_change_gallery_visibility.return_value = True
        snapshot = LinkedGalleryEditSnapshot(
            TEST_GALLERY_ID, 'Title changed on the website',
            'Complete server description\n' * 40, '11', 'public', True, False,
        )
        session.read_gallery_edit.return_value = snapshot
        session.media_gallery_details.return_value = {
            'gallery_id': TEST_GALLERY_ID, 'title': snapshot.title,
            'description': 'Information-card description',
            'visibility': 'public', 'listed': True, 'nsfw': False,
            'photo_count': len(page.photos), 'file_count': len(page.photos),
        }
        def save(
            baseline: LinkedGalleryEditSnapshot, field: str, value: str | bool,
            cancelled: threading.Event | None = None,
        ) -> LinkedGalleryEditSnapshot:
            """Apply one mock server field while retaining a canonical fresh read.

            Args:
                baseline (LinkedGalleryEditSnapshot): Exact reviewed server revision.
                field (str): Explicit title, description or boolean field.
                value (str | bool): Explicit desired field value.
                cancelled (threading.Event | None): Captured editor cancellation.
            Returns:
                LinkedGalleryEditSnapshot: Updated canonical server state.
            """
            updated = replace(
                baseline, **{field: value, 'revision': str(int(baseline.revision) + 1)},
            )
            session.read_gallery_edit.return_value = updated
            # The subsequent canonical catalogue reflects the confirmed server
            # rename; returning the old fixture row would invent server drift.
            session.gallery_page.return_value = LinkedGalleryPage(
                (replace(gallery, title=updated.title),), None,
            )
            session.media_gallery_details.return_value.update({
                'title': updated.title, 'description': updated.description,
                'nsfw': updated.nsfw, 'listed': updated.listed,
            })
            return updated

        session.save_gallery_edit.side_effect = save
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
            start_watcher=lambda _session, _gallery: None,
            load_photo_order=lambda _session, _gallery_id: 'newest',
            save_photo_order=lambda _session, _gallery_id, _order: True,
        )
        workspace.pack(fill='both', expand=True)

        def controls(kind: str) -> list[tk.Misc]:
            """Find controls below the current anchored card by native widget class.

            Args:
                kind (str): Native Tk class name such as TEntry or Text.
            Returns:
                list[tk.Misc]: Current descendants matching that class.
            """
            pending = [workspace._context_card] if workspace._context_card else []
            found: list[tk.Misc] = []
            while pending:
                widget = pending.pop()
                pending.extend(widget.winfo_children())
                if widget.winfo_class() == kind:
                    found.append(widget)
            return found

        self._wait_until(
            lambda: workspace._selected_gallery_id == TEST_GALLERY_ID
            and workspace._viewer is not None,
            'The gallery-controls fixture did not select its gallery.',
        )
        self.assertEqual('newest', workspace._photo_order_overrides[TEST_GALLERY_ID])
        workspace._open_title_editor()
        self._wait_until(lambda: bool(controls('TEntry')), 'The synchronized title editor did not open.')
        entry = controls('TEntry')[0]
        self.assertEqual(snapshot.title, entry.get())
        self.assertEqual(snapshot.title, workspace._gallery_title.get())
        entry.delete(0, 'end')
        entry.insert(0, 'Edited in WinApp')
        previous_viewer = workspace._viewer
        workspace._submit_gallery_edit(
            workspace._context_card, session, snapshot, 'title', entry.get(),
        )
        self._wait_until(
            lambda: workspace._gallery_title.get() == 'Edited in WinApp',
            'The canonical saved title did not update the heading.',
        )
        self._wait_until(
            lambda: session.gallery_page.call_count >= 2
            and TEST_GALLERY_ID in workspace._fresh_catalogue_ids,
            'A confirmed title save did not reconcile the canonical catalogue.',
        )
        self.assertIs(previous_viewer, workspace._viewer)
        self.assertEqual(snapshot, session.save_gallery_edit.call_args.args[0])
        self.assertEqual('title', session.save_gallery_edit.call_args.args[1])
        self.assertEqual('Edited in WinApp', session.save_gallery_edit.call_args.args[2])
        self.assertEqual('Edited in WinApp', workspace._galleries[TEST_GALLERY_ID].title)
        snapshot = session.read_gallery_edit.return_value

        evidence = WINAPP_DIR.parent / 'cache' / 'test-audit' / 'gallery-controls'
        evidence.mkdir(parents=True, exist_ok=True)
        for width, height in TEST_TREE_REFRESH_VIEWPORTS:
            self.root.geometry(
                f'{min(width, self.root.winfo_screenwidth() - TEST_TREE_REFRESH_SCREEN_MARGIN)}'
                f'x{min(height, self.root.winfo_screenheight() - TEST_TREE_REFRESH_SCREEN_MARGIN)}+0+0'
            )
            self.root.update()
            workspace._dismiss_context(restore=False)
            workspace._open_cache_context()
            labels = [str(widget.cget('text')) for widget in controls('TLabel')]
            for expected in ('Information & access', 'Local cache', 'Edit & tools', 'Create'):
                self.assertIn(expected, labels)
            self.root.update()
            card = workspace._context_card
            self.assertGreaterEqual(card.winfo_rootx(), workspace.winfo_rootx())
            self.assertLessEqual(
                card.winfo_rootx() + card.winfo_width(),
                workspace.winfo_rootx() + workspace.winfo_width(),
            )
            self.assertLessEqual(
                card.winfo_rooty() + card.winfo_height(),
                workspace.winfo_rooty() + workspace.winfo_height(),
            )
            bounds = (
                self.root.winfo_rootx(), self.root.winfo_rooty(),
                self.root.winfo_rootx() + self.root.winfo_width(),
                self.root.winfo_rooty() + self.root.winfo_height(),
            )
            with ImageGrab.grab(bbox=bounds) as captured:
                captured.save(evidence / f'gallery-menu-{width}.png')
            workspace._fetch_gallery_info(card, session, TEST_GALLERY_ID)
            self._wait_until(
                lambda: len(controls('TCheckbutton')) == 2,
                'Gallery information did not expose the two direct switches.',
            )
            self.assertIn('Hide from public listings', [
                widget.cget('text') for widget in controls('TCheckbutton')
            ])
            public_fields = dict(session.media_gallery_details.return_value)
            workspace._show_gallery_details(
                card, TEST_GALLERY_ID, {**public_fields, 'visibility': 'private'},
                None, 'unknown', None,
            )
            self.assertEqual(1, len(controls('TCheckbutton')))
            self.assertTrue(any('Listed: No' in str(widget.cget('text'))
                                for widget in controls('TLabel')))
            workspace._show_gallery_details(
                card, TEST_GALLERY_ID, public_fields, None, 'unknown', None,
            )
            self.root.update()
            with ImageGrab.grab(bbox=bounds) as captured:
                captured.save(evidence / f'gallery-information-{width}.png')
            if width == TEST_TREE_REFRESH_VIEWPORTS[0][0]:
                switch = next(widget for widget in controls('TCheckbutton')
                              if widget.cget('text') == 'NSFW Guard')
                previous_saves = session.save_gallery_edit.call_count
                switch.invoke()
                self._wait_until(
                    lambda: session.save_gallery_edit.call_count > previous_saves
                    and len(controls('TCheckbutton')) == 2,
                    'The direct NSFW switch did not return to information after saving.',
                )
                self.assertEqual(('nsfw', True), session.save_gallery_edit.call_args.args[1:])
                self.assertTrue(session.read_gallery_edit.return_value.nsfw)
            workspace._begin_gallery_edit(card, session, TEST_GALLERY_ID, 'description')
            self._wait_until(lambda: bool(controls('Text')), 'The description editor did not render.')
            self.root.update()
            with ImageGrab.grab(bbox=bounds) as captured:
                captured.save(evidence / f'gallery-description-editor-{width}.png')

        workspace._dismiss_context(restore=False)
        workspace._open_title_editor()
        self._wait_until(lambda: bool(controls('TEntry')), 'The fresh editor did not reopen.')
        # Repeat the user's A -> B -> A -> B sequence through actual editors,
        # worker completion, catalogue refresh and native widget destruction.
        for title in ('Original title', 'Repeated title', 'Original title', 'Repeated title'):
            workspace._dismiss_context(restore=False)
            workspace._open_title_editor()
            self._wait_until(lambda: bool(controls('TEntry')), 'Repeated title editor did not open.')
            entry = controls('TEntry')[0]
            entry.delete(0, 'end')
            entry.insert(0, title)
            entry.focus_force()
            self.root.update()
            self.assertIs(entry, self.root.focus_get())
            writes = session.save_gallery_edit.call_count
            entry.event_generate('<Return>')
            self._wait_until(
                lambda: session.save_gallery_edit.call_count > writes,
                'The focused title editor did not dispatch its Return action.',
            )
            self._wait_until(
                lambda: workspace._context_card is None and workspace._gallery_title.get() == title,
                'Repeated rename did not finish and close its editor.',
            )
            self.assertIs(previous_viewer, workspace._viewer)
        workspace._open_title_editor()
        self._wait_until(lambda: bool(controls('TEntry')), 'Editor did not survive repeated renames.')
        entered, release = threading.Event(), threading.Event()

        def lost_reply(
            baseline: LinkedGalleryEditSnapshot, field: str, value: str | bool,
            cancelled: threading.Event | None = None,
        ) -> LinkedGalleryEditSnapshot:
            """Hold one committed rename whose acknowledgement is lost.

            Args:
                baseline (LinkedGalleryEditSnapshot): Captured conditional revision.
                field (str): Explicit metadata field under test.
                value (str | bool): Desired field value.
                cancelled (threading.Event | None): Owning context cancellation.
            Returns:
                LinkedGalleryEditSnapshot: Never returned; raises after committing.
            Raises:
                LinkingError: Synthetic lost acknowledgement after the update.
            """
            entered.set()
            if not release.wait(TEST_GUI_WAIT_SECONDS):
                raise LinkingError('Held rename was not released.')
            save(baseline, field, value, cancelled)
            raise LinkingError('Lost rename acknowledgement')

        session.save_gallery_edit.side_effect = lost_reply
        entry = controls('TEntry')[0]
        entry.delete(0, 'end')
        entry.insert(0, 'Recovered automatically')
        entry.focus_force()
        self.root.update()
        writes = session.save_gallery_edit.call_count
        entry.event_generate('<Return>')
        self._wait_until(entered.is_set, 'The held rename did not reach its worker.')
        heartbeat: list[bool] = []
        try:
            self.root.after_idle(lambda: heartbeat.append(True))
            workspace._open_title_editor()
            self.root.update()
            self.assertTrue(heartbeat, 'The native event loop blocked behind the rename worker.')
        finally:
            release.set()
        self._wait_until(
            lambda: bool(controls('TEntry')) and controls('TEntry')[0].get() == 'Recovered automatically',
            'A reopened editor did not reconcile the lost acknowledgement automatically.',
        )
        self.assertEqual(writes + 1, session.save_gallery_edit.call_count)
        self.assertIs(previous_viewer, workspace._viewer)
        session.save_gallery_edit.side_effect = save
        # Cancel and re-open a description editor using a newer web revision.
        card = workspace._context_card
        snapshot = replace(
            session.read_gallery_edit.return_value, title='Latest website title',
            revision=str(int(session.read_gallery_edit.return_value.revision) + 1),
        )
        session.read_gallery_edit.return_value = snapshot
        workspace._begin_gallery_edit(card, session, TEST_GALLERY_ID, 'description')
        self._wait_until(lambda: bool(controls('Text')), 'The full description editor did not open.')
        text = controls('Text')[0]
        self.assertEqual(snapshot.description, text.get('1.0', 'end-1c'))
        text.delete('1.0', 'end')
        text.insert('1.0', 'Retain this conflicting draft')
        session.save_gallery_edit.side_effect = LinkingRevisionConflict('Web edit')
        before_reads = session.read_gallery_edit.call_count
        workspace._submit_gallery_edit(
            card, session, snapshot, 'description', text.get('1.0', 'end-1c'),
        )
        self._wait_until(
            lambda: bool(controls('Text')) and controls('Text')[0].cget('state') == 'normal',
            'The conflicting draft did not remain editable after automatic reconciliation.',
        )
        self.assertEqual('Retain this conflicting draft', controls('Text')[0].get('1.0', 'end-1c'))
        self.assertGreater(session.read_gallery_edit.call_count, before_reads)
        self.assertTrue(any('Save' in str(button.cget('text')) for button in controls('TButton')))
        self.assertFalse(any('Reload' in str(button.cget('text')) for button in controls('TButton')))
        for width, height in TEST_TREE_REFRESH_VIEWPORTS:
            self.root.geometry(f'{width}x{height}+0+0')
            self.root.update()
            bounds = (self.root.winfo_rootx(), self.root.winfo_rooty(),
                      self.root.winfo_rootx() + self.root.winfo_width(),
                      self.root.winfo_rooty() + self.root.winfo_height())
            with ImageGrab.grab(bbox=bounds) as captured:
                captured.save(evidence / f'gallery-edit-reconciled-{width}.png')
        workspace.destroy()

    def _session(
        self,
        page: LinkedMediaPage,
        presentation: LinkedPresentation,
        image_bytes: dict[int, bytes],
    ) -> mock.Mock:
        """Build an autospecced older-server session with in-memory media.

        Args:
            page (LinkedMediaPage): Validated synthetic first page.
            presentation (LinkedPresentation): Scoped server presentation data.
            image_bytes (dict[int, bytes]): PNG content indexed by photo ID.

        Returns:
            mock.Mock: Spec-constrained session fake for only the tested calls.
        """
        session = mock.create_autospec(
            LinkingSession,
            instance=True,
            spec_set=True,
        )
        session.can_read_media.return_value = True
        session.route_performance_summary.return_value = ''
        session.server_timing_summary.return_value = ''
        session.can_reuse_cached_media.return_value = True
        session.can_read_admin_media.return_value = False
        session.can_edit_gallery_metadata.return_value = False
        session.apply_gallery_edit.side_effect = (
            lambda gallery_id, field, value, cancelled=None:
            LinkingSession.apply_gallery_edit(session, gallery_id, field, value, cancelled)
        )
        session.private_preload_user_id.return_value = None
        session.private_preload_cache_identity.return_value = None
        session.can_read_presentation.return_value = True
        session.supports_gallery_tree.return_value = False
        session.cached_media_photo.return_value = None
        session.media_page.return_value = page
        session.media_presentation.return_value = presentation

        def read_photo(
            gallery_id: int,
            image_id: int,
            thumbnail: bool,
            cancelled: threading.Event | None = None,
            *,
            cache_eligible: bool | None = None,
            thumbnail_etag: str | None = None,
            thumbnail_verified_at: float | None = None,
            preload_eligible: bool | None = None,
            preload_thumbnail_etag: str | None = None,
            preload_verified_at: float | None = None,
            timings: MediaReadTimings | None = None,
            use_preloaded_snapshot: bool = False,
        ) -> tuple[bytes, str]:
            """Return one synthetic photo through the real viewer worker.

            Args:
                gallery_id (int): Requested physical gallery ID.
                image_id (int): Requested synthetic photo ID.
                thumbnail (bool): Whether the viewer requests its thumbnail.
                cancelled (threading.Event | None): Viewer cancellation signal.
                cache_eligible (bool | None): Authorized public cache policy.
                thumbnail_etag (str | None): Current thumbnail validator.
                thumbnail_verified_at (float | None): Catalogue validation time.
                preload_eligible (bool | None): Current Admin preload admission.
                preload_thumbnail_etag (str | None): Admin thumbnail validator.
                preload_verified_at (float | None): Admin proof time.
                timings (MediaReadTimings | None): Optional read timing record.
                use_preloaded_snapshot (bool): Whether the full-read caller
                    authorizes same-account use of its verified saved snapshot.

            Returns:
                tuple[bytes, str]: In-memory PNG body and its image media type.
            """
            if cancelled is not None and cancelled.is_set():
                raise RuntimeError('Synthetic media read cancelled')
            if gallery_id != TEST_GALLERY_ID:
                raise ValueError('Unexpected gallery in native UI fixture')
            return image_bytes[image_id], 'image/png'

        session.media_photo.side_effect = read_photo
        return session

    def _gallery_data(
        self,
    ) -> tuple[LinkedMediaPage, LinkedPresentation, dict[int, bytes]]:
        """Create the two-photo page and explicit manual presentation.

        Returns:
            tuple[LinkedMediaPage, LinkedPresentation, dict[int, bytes]]:
                Page, presentation settings and distinct synthetic PNG bodies.
        """
        page = LinkedMediaPage(
            TEST_GALLERY_ID,
            TEST_PARENT_GALLERY_ID,
            (
                LinkedPhoto(TEST_FIRST_IMAGE_ID, 'First synthetic photo'),
                LinkedPhoto(TEST_SECOND_IMAGE_ID, 'Second synthetic photo'),
            ),
            None,
        )
        presentation = LinkedPresentation(
            enabled=True,
            autoplay=False,
            interval_seconds=TEST_PRESENTATION_INTERVAL_SECONDS,
            transition='medium',
            music=False,
            tracks=(),
        )
        image_bytes = {
            TEST_FIRST_IMAGE_ID: self._png((220, 35, 45)),
            TEST_SECOND_IMAGE_ID: self._png((20, 190, 95)),
        }
        return page, presentation, image_bytes

    def _photos_create_context(
        self, manager: mock.Mock, parent: LinkedGallery,
        start_watcher: Callable[[LinkingSession, LinkedGallery], None] | None = None,
    ) -> tuple['photos_workspace.PhotosWorkspace', mock.Mock, tk.Widget]:
        """Mount Photos with one exact direct parent and a live create grant.

        Args:
            manager (mock.Mock): Contextual gallery manager fake.
            parent (LinkedGallery): Direct parent offered in the visible tree.
            start_watcher (Callable[[LinkingSession, LinkedGallery], None] | None):
                Optional host receiver for the real contextual Watcher action.

        Returns:
            tuple[photos_workspace.PhotosWorkspace, mock.Mock, tk.Widget]: Workspace, session,
                and active contextual card for create-flow assertions.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.available.return_value = True
        session.can_create_gallery.return_value = True
        session.gallery_page.return_value = LinkedGalleryPage((parent,), None)
        # Creation uses the offered parent's empty page, not the unrelated
        # photo fixture's gallery ID. No media or presentation is needed here.
        session.media_page.return_value = LinkedMediaPage(
            parent.gallery_id, parent.parent_id, (), None,
        )
        session.can_read_presentation.return_value = False
        manager.photos_site_identity.return_value = (
            'https://current.example/photos', 7,
        )
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
            manager=manager, start_watcher=start_watcher,
        )
        workspace.pack(fill='both', expand=True)
        self._wait_until(
            lambda: workspace.tree.exists(str(parent.gallery_id)),
            'The Photos create parent did not load.',
        )
        event = mock.Mock()
        event.widget = workspace.tree
        event.x_root = workspace.tree.winfo_rootx() + 20
        event.y_root = workspace.tree.winfo_rooty() + 20
        card = workspace._open_context(event)
        return workspace, session, card

    def _watcher_action_button(
        self, workspace: 'photos_workspace.PhotosWorkspace',
        gallery: LinkedGallery, *, rail: bool = False,
    ) -> ttk.Button:
        """Open the real gallery or rail menu and return its Watcher button.

        Args:
            workspace (photos_workspace.PhotosWorkspace): Mounted Photos owner.
            gallery (LinkedGallery): Exact row whose action should be offered.
            rail (bool): Open the selected-gallery rail action when true.

        Returns:
            ttk.Button: Actual contextual action with its production command.
        """
        event = workspace._context_event(workspace.tree)
        if rail:
            workspace.open_selected_watcher_context(event)
        else:
            workspace._gallery_context(gallery.gallery_id, event)
        card = workspace._context_card
        self.assertIsNotNone(card)
        assert card is not None
        return next(
            child for child in card.winfo_children()
            if isinstance(child, ttk.Button)
            and child.cget('text') == workspace._text('start_watcher')
        )

    def test_first_page_and_tiles_precede_slow_optional_presentation(self) -> None:
        """Publish authorized photos before an optional presentation returns.

        Returns:
            None: A blocked presentation cannot delay the initial page or its
                first thumbnail; it is applied only after the worker resumes.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        entered = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)

        def delayed_presentation(
            gallery_id: int, cancelled: threading.Event | None = None,
        ) -> LinkedPresentation:
            """Hold the optional response until the test observes the page.

            Args:
                gallery_id (int): Exact physical gallery requested.
                cancelled (threading.Event | None): Viewer cancellation event.

            Returns:
                LinkedPresentation: The synthetic scoped presentation.
            """
            self.assertEqual(TEST_GALLERY_ID, gallery_id)
            entered.set()
            release.wait(TEST_GUI_WAIT_SECONDS)
            return presentation

        session.media_presentation.side_effect = delayed_presentation
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Slow presentation')
        viewer = self._open_viewer(session, gallery)
        self._wait_until(
            lambda: entered.is_set(),
            'The optional presentation request did not start.',
        )
        self.assertEqual(page.photos, viewer._photos)
        self.assertTrue(any(image is not None for image in viewer._photo_images))
        self.assertIsNone(viewer._presentation)
        release.set()
        self._wait_until(
            lambda: viewer._presentation is presentation,
            'The optional presentation was not applied after release.',
        )

    def _assert_thumbnail_identity_index(
        self,
        memory: native_gallery_viewer.GalleryThumbnailMemory,
    ) -> None:
        """Assert the photo identity map exactly matches resident LRU keys.

        Args:
            memory (GalleryThumbnailMemory): Shared decoded thumbnail owner.

        Returns:
            None: Every resident full proof key has one matching identity entry.
        """
        expected = {key[:2]: key for key in memory.entries}
        self.assertEqual(expected, memory.identity_keys)

    def _open_viewer(
        self,
        session: LinkingSession,
        gallery: LinkedGallery,
        thumbnail_memory: 'native_gallery_viewer.GalleryThumbnailMemory | None' = None,
        canvas_grid_enabled: bool | None = False,
        newest_first: bool = False,
        thumbnail_batch_size: Callable[[], int] | None = None,
    ) -> 'native_gallery_viewer._NativeGalleryViewer':
        """Mount the public API and retain its real embedded controller.

        Args:
            session (LinkingSession): Autospecced media session for this case.
            gallery (LinkedGallery): Explicit physical gallery selected by test.
            thumbnail_memory (GalleryThumbnailMemory | None): Shared session cache.
            canvas_grid_enabled (bool | None): Explicit renderer choice;
                False keeps native tests on the native-widget compatibility path.
            newest_first (bool): Use the real viewer's local reverse order.
            thumbnail_batch_size (Callable[[], int] | None): Reader for the
                fixture's live steady-size setting, or None for the default.

        Returns:
            native_gallery_viewer._NativeGalleryViewer: Actual controller whose
                frame and widgets were created by the public mount function.
        """
        host = ttk.Frame(self.root)
        host.pack(fill='both', expand=True)
        frame = native_gallery_viewer.mount_native_gallery_view(
            host,
            session,
            gallery,
            lambda: 'en',
            thumbnail_memory=thumbnail_memory,
            newest_first=newest_first,
            canvas_grid_enabled=canvas_grid_enabled,
            thumbnail_batch_size=thumbnail_batch_size,
        )
        frame.pack(fill='both', expand=True)
        self.root.update_idletasks()
        self.root.update()
        self._assert_no_browsing_toplevels()
        return cast(
            'native_gallery_viewer._NativeGalleryViewer',
            frame._gallery_viewer_controller,
        )

    def test_linked_tree_loads_photos_without_a_chooser(self) -> None:
        """Show the linked hierarchy and first album together in the root.

        Returns:
            None: The gallery is selected automatically and has real thumbnails;
                scaled folder art fits its viewport and cards navigate.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        parent = LinkedGallery(TEST_PARENT_GALLERY_ID, None, 'Parent')
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album')
        session.gallery_page.return_value = LinkedGalleryPage((parent, gallery), None)
        parent_page = LinkedMediaPage(TEST_PARENT_GALLERY_ID, None, (), None)
        session.media_page.side_effect = lambda gallery_id, *_args, **_kwargs: (
            parent_page if gallery_id == TEST_PARENT_GALLERY_ID else page
        )
        connect = mock.Mock()
        tree_width_state = {'width': 330}
        saved_widths: list[int] = []

        def load_tree_width() -> int:
            """Read the mutable root preference used by this integration fixture.

            Returns:
                int: Latest synthetic Photos sash preference.
            """
            return tree_width_state['width']

        def save_tree_width(width: int) -> None:
            """Persist one actual user-selected sash width in the fixture.

            Args:
                width (int): Settled Panedwindow sash position.

            Returns:
                None: Updates both the root-like preference and assertion log.
            """
            tree_width_state['width'] = width
            saved_widths.append(width)

        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, connect, lambda: 'en',
            load_tree_width=load_tree_width,
            save_tree_width=save_tree_width,
        )
        # Model root autosave observing an unmapped fallback before first Map.
        tree_width_state['width'] = 210
        workspace.pack(fill='both', expand=True)
        self._wait_until(
            lambda: workspace._viewer is not None
            and all(workspace._viewer._gallery_viewer_controller._photo_images)
            and bool(workspace._viewer._gallery_viewer_controller._photo_images),
            'The linked workspace did not render the first album.',
        )
        self._wait_until(
            lambda: 'first_viewport_settled' in dict(
                workspace._catalogue_performance.snapshot().milestones
            ),
            'The current visible Tk viewport was not fully resolved.',
        )
        milestones = dict(
            workspace._catalogue_performance.snapshot().milestones
        )
        for stage in (
            'fresh_rows_confirmed', 'gallery_view_mounted',
            'first_thumbnail_visible', 'first_viewport_settled',
        ):
            self.assertIn(stage, milestones)
        self.assertGreaterEqual(
            milestones['first_thumbnail_visible'],
            milestones['fresh_rows_confirmed'],
        )
        self.assertIsNotNone(
            workspace._catalogue_performance.snapshot().fresh_to_thumbnail_ms,
        )
        self.assertGreaterEqual(
            milestones['first_viewport_settled'],
            milestones['first_thumbnail_visible'],
        )
        self.assertIsNotNone(
            workspace._catalogue_performance.snapshot().fresh_to_viewport_settled_ms,
        )
        viewer = workspace._viewer._gallery_viewer_controller
        visible_outcome = viewer._viewport_performance.snapshot()
        self.assertEqual(visible_outcome.current_state, 'settled')
        self.assertGreaterEqual(visible_outcome.current_available, 1)
        self.assertEqual(visible_outcome.current_unavailable, 0)
        self.assertIn('queued_gui_results=', viewer.render_diagnostics())
        self.assertIn('visible_unsettled=0', viewer.render_diagnostics())
        # Reusing this mounted viewer must not strand the new catalogue pass.
        old_callback = viewer.on_first_viewport_settled
        prior_settled = visible_outcome.settled
        prior_photo_reads = session.media_photo.call_count
        workspace.tree_refresh_button.invoke()
        old_callback()
        self.assertNotIn(
            'first_viewport_settled',
            dict(workspace._catalogue_performance.snapshot().milestones),
        )
        self._wait_until(
            lambda: 'first_viewport_settled' in dict(
                workspace._catalogue_performance.snapshot().milestones
            ),
            'A retained Tk viewer did not settle its refreshed catalogue pass.',
        )
        self.assertIs(viewer, workspace._viewer._gallery_viewer_controller)
        refreshed = workspace._catalogue_performance.snapshot()
        refreshed_milestones = dict(refreshed.milestones)
        self.assertIn('fresh_rows_confirmed', refreshed_milestones)
        self.assertGreaterEqual(
            refreshed_milestones['first_viewport_settled'],
            refreshed_milestones['fresh_rows_confirmed'],
        )
        self.assertIsNotNone(refreshed.fresh_to_viewport_settled_ms)
        self.assertGreater(
            viewer._viewport_performance.snapshot().settled, prior_settled,
        )
        self.assertEqual(prior_photo_reads, session.media_photo.call_count)
        self.assertEqual(330, workspace.current_tree_width())
        sash_x = int(workspace.panes.sashpos(0))
        sash_y = max(1, workspace.panes.winfo_height() // 2)
        sash_identifier = workspace.panes.identify(sash_x, sash_y)
        self.assertNotIn(sash_identifier, ('', None))
        workspace.panes.event_generate(
            '<ButtonPress-1>', x=sash_x, y=sash_y,
        )
        self.assertTrue(workspace._tree_width_dragging)
        workspace.panes.sashpos(0, 300)
        workspace.panes.event_generate(
            '<ButtonRelease-1>', x=300, y=sash_y,
        )
        self._wait_until(
            lambda: bool(saved_widths),
            'The changed Photos tree width was not saved.',
        )
        self.assertEqual(workspace.current_tree_width(), saved_widths[-1])
        expected_saved_width = saved_widths[-1]
        workspace.panes.grid_remove()
        self.root.update_idletasks()
        self.assertIsNone(workspace.current_tree_width())
        tree_width_state['width'] = 210
        workspace.panes.grid()
        self._wait_until(
            lambda: workspace.current_tree_width() == expected_saved_width,
            'The saved Photos sash width did not return after unmapping.',
        )
        # The remap probe above deliberately clobbered the fake store to model
        # the old host autosave bug. Restore the independently saved drag value
        # before testing a clean workspace recreation.
        tree_width_state['width'] = expected_saved_width
        self.assertEqual(expected_saved_width, tree_width_state['width'])
        item = str(TEST_GALLERY_ID)
        parent_item = str(TEST_PARENT_GALLERY_ID)
        self.assertEqual((item,), workspace.tree.selection())
        self.assertEqual(parent_item, workspace.tree.parent(item))
        workspace._set_all_open(False)
        self.assertFalse(workspace.tree.item(parent_item, 'open'))
        self.assertEqual((item,), workspace.tree.selection())
        workspace._set_all_open(True)
        self.assertTrue(workspace.tree.item(parent_item, 'open'))
        workspace.tree.selection_set(parent_item)
        shelf = workspace._folder_shelf
        self._wait_until(
            lambda: shelf.winfo_viewable() and TEST_GALLERY_ID in shelf._covers,
            'The empty parent did not show its child folder with a photo cover.',
        )
        with mock.patch.object(shelf, 'winfo_fpixels', return_value=144.0):
            large_folder = shelf._folder_icon(
                image_bytes[page.photos[0].image_id],
            )
        # At 150% DPI the 112x68 cover keeps its own geometry; the surrounding
        # 10x20 logical-pixel folder frame adds 15x30 physical pixels.
        self.assertEqual((183, 132), ImageTk.getimage(large_folder).size)
        shelf._covers[TEST_GALLERY_ID] = large_folder
        shelf._tiles[TEST_GALLERY_ID].configure(image=large_folder)
        self.root.update_idletasks()
        shelf._viewport_changed(mock.Mock())
        self.root.update_idletasks()
        cards_height = shelf._cards.winfo_reqheight()
        self.assertGreaterEqual(int(shelf._canvas.cget('height')), cards_height)
        scroll_region = tuple(
            float(value) for value in shelf._canvas.cget('scrollregion').split()
        )
        self.assertGreaterEqual(scroll_region[3], cards_height)
        self.assertTrue(workspace._show_subgalleries.get())
        workspace._subgallery_switch.invoke()
        self.root.update_idletasks()
        self.assertFalse(shelf.winfo_viewable())
        self.assertEqual((parent_item,), workspace.tree.selection())
        workspace._subgallery_switch.invoke()
        self.root.update_idletasks()
        self.assertTrue(shelf.winfo_viewable())
        shelf._tiles[TEST_GALLERY_ID].invoke()
        self.assertEqual((item,), workspace.tree.selection())
        self.assertTrue(workspace.winfo_viewable())
        self._assert_no_browsing_toplevels()
        connect.assert_not_called()
        workspace.destroy()
        self.root.update_idletasks()
        restarted = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, connect, lambda: 'en',
            load_tree_width=load_tree_width,
            save_tree_width=save_tree_width,
        )
        restarted.pack(fill='both', expand=True)
        self._wait_until(
            lambda: restarted.current_tree_width() == expected_saved_width,
            'A recreated Photos workspace did not restore the saved sash width.',
        )

    def _build_screenshot_shell(self) -> tuple[ttk.Frame, ttk.Frame, ui_theme.GlassCard]:
        """Mount production header wiring above native screenshot workspaces.

        Returns:
            tuple[ttk.Frame, ttk.Frame, ui_theme.GlassCard]: Outer App surface,
                global storage host and real Watcher metric card. Live services
                are replaced at their external boundaries, not by fake widgets.
        """

        outer = ttk.Frame(self.root, style='App.TFrame', padding=(16, 12, 16, 10))
        outer.pack(fill='both', expand=True)
        outer.columnconfigure(1, weight=1)
        outer.rowconfigure(2, weight=1)
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.root = self.root
        app.flights = types.SimpleNamespace(ui_language=tk.StringVar(self.root, 'en'))
        app.updates = types.SimpleNamespace(check=mock.Mock())
        app.linking_manager = types.SimpleNamespace(
            linked_address=tk.StringVar(self.root, 'https://gallery.example.test'),
            status=tk.StringVar(self.root, ''),
        )
        app.watcher_header_count_var = tk.StringVar(self.root, '0')
        app.watcher_header_time_var = tk.StringVar(self.root, '00:23')
        app.refresh_watcher_header = mock.Mock()
        app.notebook = mock.Mock()
        app.notebook.index.return_value = 0
        app.tab_indices = {'photos': 0}
        app.build_app_header(outer)
        app._watcher_header_stop_button.pack(side='left', padx=(6, 0))
        app.monitor_light.create_oval(4, 4, 12, 12, fill='#41ce8b', outline='')
        card = app._watcher_header_status_icon.master
        assert isinstance(card, ui_theme.GlassCard)
        return outer, app._storage_header, card

    def _assert_rendered_card_corners(
        self, card: ui_theme.GlassCard | ui_theme.Button, name: str, evidence: Path,
    ) -> None:
        """Compare all four real Windows corner pixels with their surroundings.

        Args:
            card (ui_theme.GlassCard | ui_theme.Button): Mapped library surface
                with its central corner behavior.
            name (str): Safe fixture-authored screenshot basename.
            evidence (Path): Directory retaining original native screen captures.
        Returns:
            None: Each corner exposes the actual parent without a dark matte;
                native clipping is active and the detail capture is retained.
        """
        owner = card.winfo_toplevel()
        topmost = bool(owner.attributes('-topmost'))
        # Desktop capture includes overlapping native windows. A hosted
        # runner's console covered this fixture in run 45778827456.
        owner.attributes('-topmost', True)
        try:
            owner.lift()
            owner.update()
            self._wait_until(
                lambda: card.winfo_viewable()
                and card._rounded_region_size == (card.winfo_width(), card.winfo_height()),
                'The default GlassCard did not acquire its visible Windows region.',
            )
            self.assertTrue(card._rounded_region_active)
            # Four pixels around the real HWND expose the surrounding parent surface.
            margin = TEST_CORNER_CAPTURE_MARGIN
            x, y = card.winfo_rootx(), card.winfo_rooty()
            width, height = card.winfo_width(), card.winfo_height()
            evidence.mkdir(parents=True, exist_ok=True)
            with ImageGrab.grab(bbox=(x - margin, y - margin,
                                      x + width + margin, y + height + margin)) as capture:
                capture.save(evidence / f'{name}.png')
                for local_x, local_y, outside_x in (
                    (0, 0, -2), (width - 1, 0, width + 1),
                    (0, height - 1, -2), (width - 1, height - 1, width + 1),
                ):
                    self.assertEqual(
                        capture.getpixel((margin + outside_x, margin + local_y)),
                        capture.getpixel((margin + local_x, margin + local_y)),
                        f'{name}: dark/opaque corner at {(local_x, local_y)}',
                    )
        finally:
            owner.attributes('-topmost', topmost)

    def test_gallery_sections_wrap_and_keep_local_controls_independent(self) -> None:
        """Render all four folder/photo combinations with independent controls.

        Returns:
            None: Desktop/narrow screenshots and actual Tk geometry prove wrapped
                folders, hidden absent controls, separate crops, local order,
                mouse resizing, per-gallery disk restoration and header corners.
        """

        # Tk's update-based fixture has no continuously entered mainloop.
        # Collect discarded viewer/widget cycles on its Tk owner, rather than
        # allowing a Pillow worker's automatic GC to delete Tk images remotely.
        automatic_gc = gc.isenabled()
        gc.disable()
        try:
            # Distinct IDs model mixed (44), folder-only (12), photo-only (100),
            # and two independent groups of 18 direct child records.
            mixed = LinkedGallery(TEST_GALLERY_ID, None, 'Journeys · photos and subgalleries')
            folders_only = LinkedGallery(TEST_PARENT_GALLERY_ID, None, 'Collections · subgalleries only')
            leaf = LinkedGallery(100, None, 'Landscapes · photos only')
            children = tuple(
                LinkedGallery(base + index, parent.gallery_id, f'{index + 1:02d} · {title}')
                for parent, base in ((mixed, 200), (folders_only, 300))
                for index, title in enumerate((
                    'Mountain light', 'Coastal journeys', 'Evening departures',
                    'Blue horizon', 'Hidden valleys', 'Northern skies',
                ) * 3)
            )
            photos = tuple(LinkedPhoto(1000 + index, f'Landscape {index + 1:02d}')
                           for index in range(12))
            _, presentation, _ = self._gallery_data()
            artwork: dict[int, bytes] = {}
            for index, photo in enumerate(photos):
                # A 640x360 authored scene exercises actual photo crops without
                # embedding a user photograph or calling an external media service.
                with Image.new('RGB', (640, 360), '#304d69') as image:
                    draw = ImageDraw.Draw(image)
                    draw.rectangle((0, 180, 640, 360), fill='#243b43')
                    draw.ellipse((440, 38, 504, 102), fill='#c8ba8d')
                    offset = (index % 3) * 30
                    draw.polygon(((0, 235), (150, 62 + offset), (345, 265)), fill='#6e8591')
                    draw.polygon(((180, 250), (440, 110 + offset), (640, 255)), fill='#445965')
                    draw.polygon(((0, 292), (350, 252), (640, 306), (640, 360), (0, 360)), fill='#557b87')
                    with io.BytesIO() as output:
                        image.save(output, format='PNG')
                        artwork[photo.image_id] = output.getvalue()
            session = self._session(
                LinkedMediaPage(mixed.gallery_id, None, photos, None), presentation, artwork,
            )
            session.recent_media_page.return_value = None
            session.gallery_page.return_value = LinkedGalleryPage(
                (mixed, folders_only, leaf, *children), None,
            )
            session.media_photo.side_effect = lambda gallery_id, image_id, thumbnail, **kwargs: (
                artwork[image_id], 'image/png',
            )

            def read_page(
                gallery_id: int, after_id: int | None = None,
                cancelled: threading.Event | None = None,
                *, cover_only: bool = False,
            ) -> LinkedMediaPage:
                """Serve gallery-specific read-only synthetic metadata.

                Args:
                    gallery_id (int): Exact parent or child being displayed.
                    after_id (int | None): Unused first-page cursor in this fixture.
                    cancelled (threading.Event | None): Actual consumer cancellation.
                    cover_only (bool): Return the one direct folder cover.
                Returns:
                    LinkedMediaPage: Empty folder-only parent or authorized photos.
                """
                rows = (() if gallery_id == folders_only.gallery_id else
                        photos if gallery_id in (mixed.gallery_id, leaf.gallery_id) else photos[:1])
                return LinkedMediaPage(gallery_id, None, rows, None)

            session.media_page.side_effect = read_page
            provider: list[LinkingSession | None] = [None]
            ui_theme.configure_theme(self.root)
            outer, storage_host, watcher_card = self._build_screenshot_shell()
            # Accelerate only the synthetic cover poll to the fixture's established
            # GUI pump cadence. Pixel/layout and cache assertions remain unchanged;
            # these screenshots make no production loading-time claim.
            with TemporaryDirectory() as directory, mock.patch(
                'uploader.gallery_folders.FOLDER_POLL_MS', round(TEST_GUI_POLL_SECONDS * 1000),
            ):
                path = Path(directory) / 'view.json'
                workspace = photos_workspace.PhotosWorkspace(
                    outer, lambda: provider[0], mock.Mock(), lambda: 'en',
                    storage_parent=storage_host, storage_surface=outer,
                    load_folder_split=lambda _session, gallery_id: load_gallery_folder_split(path, gallery_id),
                    save_folder_split=lambda _session, gallery_id, share: save_gallery_folder_split(path, gallery_id, share),
                    load_folder_format=lambda _session, gallery_id: load_gallery_card_format(
                        path, gallery_id, folders=True,
                    ),
                    save_folder_format=lambda _session, gallery_id, value: save_gallery_card_format(
                        path, gallery_id, value, folders=True,
                    ),
                    load_folder_order=lambda _session, gallery_id: load_gallery_photo_order(
                        path, gallery_id, folders=True,
                    ),
                    save_folder_order=lambda _session, gallery_id, value: save_gallery_photo_order(
                        path, gallery_id, value, folders=True,
                    ),
                )
                workspace.grid(row=2, column=0, columnspan=2, sticky='nsew')
                workspace._ensure_initial_gallery = mock.Mock()
                provider[0] = session
                workspace.refresh_session()
                self._wait_until(lambda: workspace._worker is None and workspace.tree.exists('100'),
                                 'Synthetic section catalogue did not settle.')
                evidence = WINAPP_DIR.parent / 'cache' / 'test-audit' / 'gallery-sections'
                evidence.mkdir(parents=True, exist_ok=True)
                workspace._storage_text.set('4,794 · 30.9 MiB')
                # Existing desktop/narrow dimensions retain the runner screen margin.
                for width, height in TEST_TREE_REFRESH_VIEWPORTS:
                    self.root.geometry(
                        f'{min(width, self.root.winfo_screenwidth() - TEST_HEADER_SCREEN_WIDTH_MARGIN)}x'
                        f'{min(height, self.root.winfo_screenheight() - TEST_TREE_REFRESH_SCREEN_MARGIN)}+0+0'
                    )
                    self.root.update()
                    # Reproduce the reported tiny loading badge, then let its
                    # real native geometry grow for the ready count/size state.
                    workspace._storage_text.set('—')
                    self.root.update()
                    self._assert_rendered_card_corners(
                        workspace._storage_heading, f'storage-loading-{width}',
                        evidence / 'rounded-corners',
                    )
                    workspace._storage_text.set('4,794 · 30.9 MiB')
                    self.root.update()
                    for card_name, card in (
                        ('storage', workspace._storage_heading), ('watcher', watcher_card),
                    ):
                        self._assert_rendered_card_corners(
                            card, f'{card_name}-{width}', evidence / 'rounded-corners',
                        )
                    self.assertEqual(workspace._storage_heading.winfo_height(),
                                     watcher_card.winfo_height(), [
                                         (child.winfo_class(), str(child.cget('style')),
                                          child.winfo_reqheight())
                                         for child in watcher_card.winfo_children()
                                     ])
                    for name, gallery, show_folders in (
                        ('photos-only', leaf, True), ('mixed', mixed, True),
                        ('folders-only', folders_only, True), ('folders-hidden', mixed, False),
                    ):
                        workspace._show_subgalleries.set(show_folders)
                        workspace._select_gallery(gallery)
                        self._wait_until(
                            lambda: workspace._photos_present is (gallery is not folders_only),
                            'Section visibility did not follow the completed metadata page.',
                        )
                        controller = workspace._viewer._gallery_viewer_controller
                        shelf = workspace._folder_shelf
                        if gallery is not folders_only:
                            self._wait_until(lambda: bool(controller._visible_thumbnail_indices)
                                             and all(controller._photo_images[index]
                                                     for index in controller._visible_thumbnail_indices),
                                             'Synthetic photo thumbnails did not settle.')
                        if show_folders and gallery is not leaf:
                            def covers_ready() -> bool:
                                """Require every visible folder cover before capturing its layout.

                                Returns:
                                    bool: True once each viewport-intersecting child has artwork.
                                """
                                top = shelf._canvas.canvasy(0)
                                bottom = top + shelf._canvas.winfo_height()
                                visible = {
                                    gallery_id for gallery_id, tile in shelf._tiles.items()
                                    if tile.winfo_y() < bottom
                                    and tile.winfo_y() + tile.winfo_height() > top
                                }
                                return bool(visible) and visible <= shelf._covers.keys()

                            try:
                                self._wait_until(covers_ready, 'Visible folder covers did not settle.')
                            except AssertionError as exc:
                                state = {
                                    'case': name, 'width': width,
                                    'mapped': bool(shelf._canvas.winfo_ismapped()),
                                    'height': shelf._canvas.winfo_height(),
                                    'top': shelf._canvas.canvasy(0),
                                    'covers': sorted(shelf._covers),
                                    'resolved': sorted(shelf._resolved),
                                    'worker': shelf._worker is not None and shelf._worker.is_alive(),
                                    'poll': shelf._poll_id,
                                    'cancelled': shelf._cancelled.is_set(),
                                    'tiles': {
                                        gallery_id: (tile.winfo_y(), tile.winfo_height())
                                        for gallery_id, tile in shelf._tiles.items()
                                    },
                                    'reads': [call.args[0] for call in session.media_page.call_args_list],
                                }
                                bounds = (self.root.winfo_rootx(), self.root.winfo_rooty(),
                                          self.root.winfo_rootx() + self.root.winfo_width(),
                                          self.root.winfo_rooty() + self.root.winfo_height())
                                with ImageGrab.grab(bbox=bounds) as capture:
                                    capture.save(evidence / f'failed-{name}-{width}.png')
                                raise AssertionError(json.dumps(state, sort_keys=True)) from exc
                        self.root.update()
                        visible_folders = show_folders and gallery is not leaf
                        self.assertEqual(visible_folders, bool(shelf.winfo_ismapped()))
                        self.assertEqual(visible_folders, bool(workspace._folder_format_controls.winfo_ismapped()))
                        self.assertEqual(gallery is not folders_only,
                                         bool(workspace._card_format_controls.winfo_ismapped()))
                        self.assertEqual(visible_folders and gallery is not folders_only,
                                         bool(workspace._photo_separator.winfo_ismapped()))
                        if visible_folders:
                            rows = {int(tile.grid_info()['row']) for tile in shelf._tiles.values()}
                            self.assertGreater(len(rows), 1)
                            self.assertEqual('vertical', str(shelf._scrollbar.cget('orient')))
                            self.assertFalse(shelf._canvas.cget('xscrollcommand'))
                            for tile in shelf._tiles.values():
                                self.assertLessEqual(tile.winfo_x() + tile.winfo_width(),
                                                     shelf._canvas.winfo_width())
                        if name == 'mixed':
                            self.assertGreaterEqual(
                                workspace._card_format_controls.winfo_rooty(),
                                shelf.winfo_rooty() + shelf.winfo_height(),
                            )
                        if name == 'photos-only':
                            self.assertFalse(workspace._subgallery_switch.winfo_ismapped())
                        bounds = (self.root.winfo_rootx(), self.root.winfo_rooty(),
                                  self.root.winfo_rootx() + self.root.winfo_width(),
                                  self.root.winfo_rooty() + self.root.winfo_height())
                        with ImageGrab.grab(bbox=bounds) as capture:
                            capture.save(evidence / f'{name}-{width}.png')
                    workspace._show_subgalleries.set(True)
                    workspace._select_gallery(mixed)
                    self._wait_until(lambda: workspace._photos_present is True,
                                     'Mixed gallery did not return for independent controls.')
                    controller = workspace._viewer._gallery_viewer_controller
                    self._wait_until(
                        lambda: bool(controller._visible_thumbnail_indices)
                        and all(controller._photo_images[index]
                                for index in controller._visible_thumbnail_indices),
                        'Mixed gallery thumbnails did not settle for ratio screenshots.',
                    )
                    for key in ('16:9', '4:3', '1:1', '4:5', '9:16', '21:9'):
                        workspace._folder_format_buttons[key].invoke()
                        self.root.update()
                        self.assertEqual(key, workspace._folder_shelf._card_format)
                        self.assertEqual('default', controller._card_format)
                        self.assertEqual(key, load_gallery_card_format(path, mixed.gallery_id, folders=True))
                        self.assertEqual('default', load_gallery_card_format(path, mixed.gallery_id))
                        if width == TEST_TREE_REFRESH_VIEWPORTS[0][0]:
                            with ImageGrab.grab(bbox=bounds) as capture:
                                capture.save(evidence / ('mixed-ratio-' + key.replace(':', 'x') + '.png'))
                    original_ids = tuple(workspace._folder_shelf._tiles)
                    workspace._folder_order_button.invoke()
                    self.root.update()
                    self.assertEqual(tuple(reversed(original_ids)), tuple(workspace._folder_shelf._tiles))
                    self.assertEqual('oldest', load_gallery_photo_order(path, mixed.gallery_id))
                    workspace._folder_order_button.invoke()
                    workspace._folder_format_buttons['default'].invoke()
                    self.root.update()
                    separator = workspace._photo_separator
                    # Exact low/high mouse destinations verify both directions;
                    # coordinates are relative to the shelf and pane, not mocks.
                    for label, target_share in (('low', 220), ('high', 550)):
                        start_y = separator.winfo_rooty() + separator.winfo_height() // 2
                        target_y = (workspace._folder_shelf.winfo_rooty()
                                    + round(workspace.viewer_pane.winfo_height()
                                            * target_share / GALLERY_FOLDER_SHARE_SCALE)
                                    + separator.winfo_height() // 2)
                        separator.event_generate('<ButtonPress-1>', x=8, y=3, rooty=start_y)
                        separator.event_generate('<B1-Motion>', x=8, y=3, rooty=target_y)
                        self.root.update()
                        separator.event_generate('<ButtonRelease-1>', x=8, y=3, rooty=target_y)
                        self.root.update()
                        saved_share = load_gallery_folder_split(path, mixed.gallery_id)
                        self.assertIsNotNone(saved_share)
                        self.assertEqual(saved_share, workspace._folder_shelf._height_share)
                        self.assertIsNone(workspace.grab_current())
                        self.assertGreater(workspace._viewer.winfo_height(), 1)
                        self._wait_until(covers_ready, 'Resized folder covers did not settle.')
                        self._wait_until(
                            lambda: bool(controller._visible_thumbnail_indices)
                            and all(controller._photo_images[index]
                                    for index in controller._visible_thumbnail_indices),
                            'Resized photo thumbnails did not settle.',
                        )
                        with ImageGrab.grab(bbox=bounds) as capture:
                            capture.save(evidence / f'divider-{label}-{width}.png')
                    saved_height = workspace._folder_shelf.winfo_height()
                    self.assertIsNone(load_gallery_folder_split(path, folders_only.gallery_id))
                    workspace._select_gallery(folders_only)
                    self._wait_until(lambda: workspace._photos_present is False,
                                     'Divider fixture did not reach folder-only gallery.')
                    self.assertFalse(separator.winfo_ismapped())
                    # Discard the in-memory value so returning must read the real file.
                    workspace._folder_splits.clear()
                    workspace._select_gallery(mixed)
                    self._wait_until(lambda: workspace._photos_present is True,
                                     'Divider fixture did not return to its mixed gallery.')
                    self.root.update()
                    self.assertEqual(saved_share, workspace._folder_shelf._height_share)
                    self.assertEqual(saved_height, workspace._folder_shelf.winfo_height())
                    self._wait_until(covers_ready, 'Restored folder covers did not settle.')
                    restored = workspace._viewer._gallery_viewer_controller
                    self._wait_until(
                        lambda: bool(restored._visible_thumbnail_indices)
                        and all(restored._photo_images[index]
                                for index in restored._visible_thumbnail_indices),
                        'Restored photo thumbnails did not settle.',
                    )
                    with ImageGrab.grab(bbox=bounds) as capture:
                        capture.save(evidence / f'divider-restored-{width}.png')
                    # A gallery switch cancels a local grab; an old release cannot
                    # save the captured gallery's share under the next selection.
                    separator.event_generate('<ButtonPress-1>', x=8, y=3)
                    workspace._show_subgalleries.set(False)
                    workspace._sync_subgalleries()
                    self.assertIsNone(workspace._folder_drag)
                    separator.event_generate('<ButtonRelease-1>', x=8, y=3)
                    self.assertEqual(saved_share, load_gallery_folder_split(path, mixed.gallery_id))
                    workspace._show_subgalleries.set(True)
                    workspace._sync_subgalleries()
                    # Home clears only this divider override, retaining local formats.
                    separator.focus_force()
                    self._wait_until(lambda: self.root.focus_get() is separator,
                                     'The divider did not acquire native keyboard focus.')
                    separator.event_generate('<Down>')
                    self.root.update()
                    self.assertGreater(load_gallery_folder_split(path, mixed.gallery_id), saved_share)
                    separator.event_generate('<Home>')
                    self.root.update()
                    self.assertIsNone(load_gallery_folder_split(path, mixed.gallery_id))
                    self.assertEqual('default', load_gallery_card_format(path, mixed.gallery_id, folders=True))
                session.change_gallery_visibility.assert_not_called()
                session.save_gallery_edit.assert_not_called()
                workspace.destroy()
                outer.destroy()
        finally:
            gc.collect()
            if automatic_gc:
                gc.enable()

    def test_cover_descriptor_never_becomes_a_complete_viewer_catalogue(self) -> None:
        """Keep a shelf's one-cover metadata out of the album-page shortcut.

        Returns:
            None: A cover forces a normal gallery read; an ordinary recent
                page retains the established immediate rendering shortcut.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.recent_media_page.return_value = replace(page, photos=page.photos[:1],
                                                         cover_only=True)
        viewer = native_gallery_viewer._NativeGalleryViewer.__new__(
            native_gallery_viewer._NativeGalleryViewer,
        )
        viewer.session = session
        viewer.gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Album')
        viewer._view_order = None
        viewer._closed = False
        viewer._worker = None
        viewer._events = queue.Queue()
        viewer._cancelled = threading.Event()
        viewer._generation = 0
        viewer._launch = mock.Mock()
        viewer._schedule_queue_poll = mock.Mock()
        viewer._load_first_page()
        self.assertTrue(viewer._events.empty())
        self.assertEqual('page', viewer._launch.call_args.args[0])
        self.assertEqual((page, None), viewer._launch.call_args.args[1]())
        viewer._launch.reset_mock()
        session.recent_media_page.return_value = page
        viewer._load_first_page()
        viewer._launch.assert_not_called()
        self.assertEqual('page_recent', viewer._events.get_nowait()[1])

    def test_folder_cover_ratio_describes_the_image_inside_the_frame(self) -> None:
        """Measure the actual colored image rectangle inside every folder rim.

        Returns:
            None: The cover pixels retain the chosen ratio while the outer
                folder adds its tab and border beyond the photograph.
        """

        ui_theme.configure_theme(self.root)
        shelf = GalleryFolderShelf(self.root, mock.Mock())
        self.addCleanup(shelf.destroy)
        # This distinctive flat color identifies photo pixels independently
        # of the shared folder border and the surrounding Theme background.
        color = (237, 41, 83)
        cover = self._png(color)
        with mock.patch.object(ui_theme, '_tk_dpi_scale', return_value=1.0):
            for ratio in ('16:9', '21:9', '4:3', '1:1', '4:5', '9:16'):
                shelf.set_view(ratio, True)
                raster = ImageTk.getimage(shelf._folder_icon(cover)).convert('RGB')
                # Lanczos subtly changes even near-interior pixels: exact RGB
                # equality measured a 106x57 core of a 112x63 cover on Windows.
                # Count its antialiased photo edge, still far from the dark rim.
                points = [(index % raster.width, index // raster.width)
                          for index, pixel in enumerate(raster.getdata())
                          if max(abs(actual - expected)
                                 for actual, expected in zip(pixel, color))
                          <= TEST_FOLDER_COVER_COLOR_DISTANCE]
                self.assertTrue(points, ratio)
                width = max(x for x, _y in points) - min(x for x, _y in points) + 1
                height = max(y for _x, y in points) - min(y for _x, y in points) + 1
                target_width, target_height = (int(value) for value in ratio.split(':'))
                # Allow only the unavoidable single-pixel integer rounding.
                self.assertLessEqual(abs(width * target_height - height * target_width),
                                     max(target_width, target_height), ratio)
                self.assertGreater(raster.width, width)
                self.assertGreater(raster.height, height)

    def test_folder_cover_worker_writes_and_reuses_real_thumbnail_storage(self) -> None:
        """Exercise real session/cache writes and a fresh-session folder reopen.

        Returns:
            None: The first request persists the authorized original thumbnail;
                a new shelf/session uses its verified bytes after fresh metadata
                without a second binary read or a mocked cache lookup.
        """

        issuer = 'https://gallery.example.test'
        body = self._png((90, 130, 170))
        validator = '"' + hashlib.sha256(body).hexdigest() + '"'
        reads = {'metadata': 0, 'binary': 0}

        def transport(
            gallery_id: int, kind: str, selector: int | str | None,
            cancelled: threading.Event | None,
            if_none_match: str | None = None,
            **options: bool | list[int] | MediaReadTimings,
        ) -> tuple[bytes, str, str | None, bool]:
            """Replace only the network boundary with current authorized data.

            Args:
                gallery_id (int): Exact requested gallery identity.
                kind (str): Metadata page or thumbnail rendition.
                selector (int | str | None): Initial cursor or direct photo ID.
                cancelled (threading.Event | None): Current shelf cancellation.
                if_none_match (str | None): Optional existing body validator.
                **options (bool | list[int] | MediaReadTimings): Cover-only
                    selection, proof witness sink or transport timing record.
            Returns:
                tuple[bytes, str, str | None, bool]: Canonical metadata or the
                    first cache-admitted PNG, its MIME, validator and admission.
            """
            self.assertEqual(TEST_GALLERY_ID, gallery_id)
            if kind == 'photos':
                self.assertIs(options.get('cover_only'), True)
                reads['metadata'] += 1
                return json.dumps({
                    'gallery_id': gallery_id, 'parent_id': None,
                    'photos': [{'id': TEST_FIRST_IMAGE_ID, 'title': 'Cover',
                                'thumbnail_etag': validator}], 'next_after': None,
                    'cover_page': True,
                }).encode('utf-8'), 'application/json', None, False
            self.assertEqual('thumbnail', kind)
            reads['binary'] += 1
            self.assertEqual(1, reads['binary'], 'The disk thumbnail was downloaded again.')
            return body, 'image/png', validator, True

        with TemporaryDirectory() as directory:
            for cycle in range(2):
                cache = GalleryMediaCache(Path(directory), linking.NATIVE_MEDIA_MAX_BYTES,
                                          thumbnails=True)
                session = LinkingSession(
                    issuer, 'pgla_' + 'A' * 43,
                    time.monotonic() + linking.LINKING_MAX_TOKEN_LIFETIME_SECONDS,
                    linking.GALLERIES_SCOPE,
                )
                with mock.patch.object(session, '_media_read', side_effect=transport), \
                        mock.patch.object(linking.GalleryMediaCache, 'for_current_user',
                                          return_value=cache):
                    shelf = GalleryFolderShelf(self.root, mock.Mock())
                    shelf.pack(fill='both', expand=True)
                    shelf.show_children(session, TEST_PARENT_GALLERY_ID, (
                        LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Cached folder'),
                    ))
                    self._wait_until(lambda: TEST_GALLERY_ID in shelf._covers
                                     and TEST_GALLERY_ID in shelf._resolved,
                                     f'The real cover pipeline did not settle in cycle {cycle}.')
                    stored = cache.read(cache.key(issuer, TEST_GALLERY_ID,
                                                   TEST_FIRST_IMAGE_ID, True))
                    self.assertIsNotNone(stored)
                    self.assertEqual(body, stored.body)
                    self.assertEqual(validator, stored.etag)
                    shelf.destroy()
                    session.close()
            self.assertEqual({'metadata': 2, 'binary': 1}, reads)

    def test_folder_cover_uses_persistent_thumbnail_before_background_refresh(self) -> None:
        """Display an authorized disk cover while fresh metadata is still held.

        Returns:
            None: Reopened thumbnail storage supplies the first visible cover
                without binary HTTP; remapping restarts cover work and an empty
                refreshed inventory removes obsolete pixels.
        """

        issuer = 'https://gallery.example.test'
        session = LinkingSession(
            issuer, 'pgla_' + 'A' * 43,
            time.monotonic() + linking.LINKING_MAX_TOKEN_LIFETIME_SECONDS,
            linking.GALLERIES_SCOPE,
        )
        self.addCleanup(session.close)
        photo = LinkedPhoto(TEST_FIRST_IMAGE_ID, 'Saved cover', cache_eligible=True)
        page = LinkedMediaPage(TEST_GALLERY_ID, None, (photo,), None)
        second_photo = replace(photo, image_id=photo.image_id + 1)
        second_id = TEST_GALLERY_ID + 1
        pages = {TEST_GALLERY_ID: page,
                 second_id: LinkedMediaPage(second_id, None, (second_photo,), None)}
        body = self._png((90, 130, 170))
        validator = '"' + hashlib.sha256(body).hexdigest() + '"'
        started, release = threading.Event(), threading.Event()
        self.addCleanup(release.set)

        def refresh(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
            *, cover_only: bool = False,
        ) -> LinkedMediaPage:
            """Hold a fresh metadata read after the cached result is queued.

            Args:
                gallery_id (int): Exact child folder being refreshed.
                after_id (int | None): Initial catalogue cursor, unused here.
                cancelled (threading.Event | None): Current shelf cancellation.
                cover_only (bool): Request the first cover without a full page.
            Returns:
                LinkedMediaPage: Unchanged current authorized photo descriptor.
            """
            started.set()
            if not release.wait(TEST_GUI_WAIT_SECONDS):
                raise TimeoutError('Folder metadata refresh was not released.')
            return pages[gallery_id]

        with TemporaryDirectory() as directory:
            writer = GalleryMediaCache(Path(directory), linking.NATIVE_MEDIA_MAX_BYTES, thumbnails=True)
            for gallery_id, saved in pages.items():
                self.assertTrue(writer.write(
                    writer.key(issuer, gallery_id, saved.photos[0].image_id, True),
                    body, 'image/png', validator,
                ))
            # Reopening the owner proves persistence rather than an in-memory hit.
            reader = GalleryMediaCache(Path(directory), linking.NATIVE_MEDIA_MAX_BYTES, thumbnails=True)
            with mock.patch.object(linking.GalleryMediaCache, 'for_current_user', return_value=reader), \
                    mock.patch.object(session, 'recent_media_page', side_effect=pages.get), \
                    mock.patch.object(session, 'media_page', side_effect=refresh) as metadata, \
                    mock.patch.object(session, 'media_photo') as binary:
                ui_theme.configure_theme(self.root)
                shelf = GalleryFolderShelf(self.root, mock.Mock())
                shelf.pack(fill='both', expand=True)
                shelf.show_children(session, TEST_PARENT_GALLERY_ID, (
                    LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Saved cover folder'),
                    LinkedGallery(second_id, TEST_PARENT_GALLERY_ID, 'Second saved folder'),
                ))
                self._wait_until(lambda: started.is_set() and pages.keys() <= shelf._covers.keys(),
                                 'Every visible disk cover must precede the blocked metadata refresh.')
                self.assertNotIn(TEST_GALLERY_ID, shelf._resolved)
                binary.assert_not_called()
                release.set()
                self._wait_until(lambda: TEST_GALLERY_ID in shelf._resolved,
                                 'The cover refresh did not complete.')
                binary.assert_not_called()
                photo_reads = metadata.call_count
                shelf.set_view('9:16', True)
                self.root.update()
                self.assertEqual(photo_reads, metadata.call_count)
                # Stop a poll while only the canvas is hidden: the shelf frame
                # remains mapped and therefore receives no new Map event.
                shelf._canvas.grid_remove()
                self.root.update()
                shelf.refresh_covers()
                self._wait_until(lambda: shelf._poll_id is None,
                                 'The hidden canvas did not release its idle poll.')
                reads_before_remap = metadata.call_count
                shelf._canvas.grid()
                self._wait_until(
                    lambda: metadata.call_count > reads_before_remap
                    and TEST_GALLERY_ID in shelf._resolved,
                    'Mapping the unchanged canvas did not restart cover work.',
                )
                binary.assert_not_called()
                metadata.side_effect = None
                metadata.return_value = LinkedMediaPage(TEST_GALLERY_ID, None, (), None)
                shelf.refresh_covers()
                self._wait_until(
                    lambda: TEST_GALLERY_ID in shelf._resolved and TEST_GALLERY_ID not in shelf._covers,
                    'The empty refreshed child retained obsolete cover pixels.',
                )
                shelf.destroy()

    def test_compact_application_header_reclaims_gallery_height(self) -> None:
        """Render the real header factory above Photos at desktop and narrow widths.

        Returns:
            None: Native screenshots prove one wide row, bounded narrow actions,
                retained version/link status and more height for gallery content.
        """

        ui_theme.configure_theme(self.root)
        outer = ttk.Frame(self.root, style='App.TFrame', padding=(16, 12, 16, 10))
        outer.pack(fill='both', expand=True)
        outer.columnconfigure(1, weight=1)
        outer.rowconfigure(2, weight=1)
        app = MAIN.WatcherApp.__new__(MAIN.WatcherApp)
        app.root = self.root
        app.flights = types.SimpleNamespace(ui_language=tk.StringVar(self.root, 'en'))
        app.updates = types.SimpleNamespace(check=mock.Mock())
        app.linking_manager = types.SimpleNamespace(
            linked_address=tk.StringVar(self.root, 'https://gallery.example.test'),
            status=tk.StringVar(self.root, ''),
        )
        app.watcher_header_count_var = tk.StringVar(self.root, '0')
        app.watcher_header_time_var = tk.StringVar(self.root, '00:23')
        app.refresh_watcher_header = mock.Mock()
        app.notebook = mock.Mock()
        app.notebook.index.return_value = 0
        app.tab_indices = {'photos': 0, 'watch': 1}
        header = app.build_app_header(outer)
        app._watcher_header_stop_button.pack(side='left', padx=(6, 0))
        app.monitor_light.create_oval(4, 4, 12, 12, fill='#41ce8b', outline='')
        page, presentation, image_bytes = self._gallery_data()
        for photo, sky in zip(page.photos, ('#426986', '#536e78')):
            # Normal-size authored 640x360 scenes make the reclaimed photo area
            # visible; tiny pixel-test rasters intentionally remain tiny in Default.
            with Image.new('RGB', (640, 360), sky) as image:
                draw = ImageDraw.Draw(image)
                draw.polygon(((0, 300), (180, 70), (380, 290)), fill='#82959f')
                draw.polygon(((180, 330), (470, 130), (640, 310)), fill='#344e60')
                draw.rectangle((0, 310, 640, 360), fill='#526c62')
                with io.BytesIO() as output:
                    image.save(output, format='PNG')
                    image_bytes[photo.image_id] = output.getvalue()
        session = self._session(page, presentation, image_bytes)
        session.recent_media_page.return_value = None
        session.gallery_page.return_value = LinkedGalleryPage((
            LinkedGallery(TEST_GALLERY_ID, None, 'Journeys'),
            LinkedGallery(55, TEST_GALLERY_ID, 'Mountain light'),
            LinkedGallery(56, TEST_GALLERY_ID, 'Coastal journeys'),
        ), None)
        session.media_page.side_effect = lambda gallery_id, after_id=None, cancelled=None, **kwargs: LinkedMediaPage(
            gallery_id, None, page.photos, None,
        )
        session.media_photo.side_effect = lambda gallery_id, image_id, thumbnail, cancelled=None, **kwargs: (
            image_bytes[image_id], 'image/png',
        )
        provider: list[LinkingSession | None] = [None]
        workspace = photos_workspace.PhotosWorkspace(
            outer, lambda: provider[0], mock.Mock(), lambda: 'en',
            storage_parent=app._storage_header, storage_surface=outer,
        )
        workspace.grid(row=2, column=0, columnspan=2, sticky='nsew')
        workspace._storage_text.set('4,794 · 30.9 MiB')
        workspace._ensure_initial_gallery = mock.Mock()
        provider[0] = session
        workspace.refresh_session()
        self._wait_until(lambda: workspace.tree.exists(str(TEST_GALLERY_ID)),
                         'The compact-header Photos fixture did not load.')
        workspace._select_gallery(workspace._galleries[TEST_GALLERY_ID])
        self._wait_until(lambda: workspace._photos_present is True,
                         'The compact-header Photos fixture did not show photos.')
        # Recreate only the original three label rows to measure their native
        # requested height under the same fonts/DPI. The 2/5 row margins and
        # 10-pixel bottom gap are the original shell geometry being replaced.
        legacy = ttk.Frame(self.root, style='App.TFrame')
        for row, text, style, gap in (
            (0, 'PHP Gallery', 'Brand.TLabel', 0),
            (1, 'Your gallery on autopilot · ' + MAIN.APP_VERSION, 'App.Eyebrow.TLabel', 2),
            (2, 'Linked: https://gallery.example.test', 'App.Status.TLabel', 5),
        ):
            ttk.Label(legacy, text=text, style=style).grid(row=row, column=0, pady=(gap, 0))
        self.root.update_idletasks()
        legacy_height = legacy.winfo_reqheight() + 10
        evidence = WINAPP_DIR.parent / 'cache' / 'test-audit' / 'compact-header'
        evidence.mkdir(parents=True, exist_ok=True)
        measurements = []
        for width, height in TEST_TREE_REFRESH_VIEWPORTS:
            self.root.geometry(
                f'{min(width, self.root.winfo_screenwidth() - TEST_HEADER_SCREEN_WIDTH_MARGIN)}x'
                f'{min(height, self.root.winfo_screenheight() - TEST_TREE_REFRESH_SCREEN_MARGIN)}+0+0'
            )
            self.root.update()
            self.assertIn(MAIN.APP_VERSION, str(header.version.cget('text')))
            self.assertEqual(workspace._storage_heading.winfo_height(),
                             app._watcher_header_status_icon.master.winfo_height())
            for control in (header.brand, header.version, header.actions, header.links):
                self.assertTrue(control.winfo_ismapped())
                self.assertGreaterEqual(control.winfo_rootx(), header.winfo_rootx())
                self.assertLessEqual(control.winfo_rootx() + control.winfo_width(),
                                     header.winfo_rootx() + header.winfo_width())
            if width == TEST_TREE_REFRESH_VIEWPORTS[0][0]:
                self.assertEqual(0, int(header.links.grid_info()['row']))
                self.assertLess(header.winfo_height() + HEADER_WORKSPACE_GAP, legacy_height)
            self.assertLessEqual(int(header.links.grid_info()['row']), 1)
            expected_header = (header.winfo_height(), header.links.winfo_rooty())
            for selected in (1, 0):
                app.notebook.index.return_value = selected
                app._gallery_link_refresh()
                self.root.update()
                self.assertEqual(
                    expected_header, (header.winfo_height(), header.links.winfo_rooty()),
                )
            self.assertGreaterEqual(workspace.winfo_rooty(),
                                    header.winfo_rooty() + header.winfo_height())
            controller = workspace._viewer._gallery_viewer_controller
            self._wait_until(
                lambda: bool(controller._visible_thumbnail_indices)
                and all(controller._photo_images[index]
                        for index in controller._visible_thumbnail_indices),
                'Visible photos did not settle below the compact header.',
            )
            measurements.append({
                'viewport_width': self.root.winfo_width(),
                'header_height': header.winfo_height(),
                'legacy_header_with_gap': legacy_height,
                'header_with_gap': header.winfo_height() + HEADER_WORKSPACE_GAP,
                'gallery_height': workspace.winfo_height(),
            })
            bounds = (self.root.winfo_rootx(), self.root.winfo_rooty(),
                      self.root.winfo_rootx() + self.root.winfo_width(),
                      self.root.winfo_rooty() + self.root.winfo_height())
            with ImageGrab.grab(bbox=bounds) as capture:
                capture.save(evidence / f'header-{width}.png')
        app.check_updates_button.invoke()
        app.updates.check.assert_called_once_with()
        app.linking_manager.status.set('Connection unavailable; retry from the link controls.')
        self.root.update()
        self.assertGreater(header.links.winfo_height(), 1)
        app.linking_manager.status.set('')
        app.linking_manager.linked_address.set('')
        self.root.update()
        self.assertTrue(header.brand.winfo_ismapped())
        self.assertTrue(header.version.winfo_ismapped())
        (evidence / 'geometry.json').write_text(json.dumps(measurements, indent=2), encoding='utf-8')
        workspace.destroy()
        outer.destroy()
        legacy.destroy()

    def test_inspector_close_keeps_selection_and_rejects_late_metadata(self) -> None:
        """Close the inspector during a read and reopen the same selected photo.

        Returns:
            None: Late metadata cannot reopen the pane, the gallery/selection
                survive and cached reopening reuses details without another read.
        """

        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Photo information can be closed')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        started, release = threading.Event(), threading.Event()
        self.addCleanup(release.set)
        observations = []

        def read_info(
            gallery_id: int, image_id: int, cancelled: threading.Event | None = None,
        ) -> dict[str, str | int | bool]:
            """Hold one synthetic metadata reply across inspector dismissal.

            Args:
                gallery_id (int): Physical gallery owning the selected photo.
                image_id (int): Exact selected photo identity.
                cancelled (threading.Event | None): Real read cancellation signal.
            Returns:
                dict[str, str | int | bool]: Title, filename, MIME, size, dimensions,
                    public visibility and non-sensitive flag for the test image.
            """
            observations.append(image_id)
            started.set()
            if not release.wait(TEST_GUI_WAIT_SECONDS):
                raise TimeoutError('Inspector close fixture did not release metadata.')
            return {
                'title': 'Mountain light', 'filename': 'mountain-light.png',
                'mime': 'image/png', 'size': 123456, 'width': 640, 'height': 360,
                'visibility': 'public', 'nsfw': False,
            }

        session.media_photo_info.side_effect = read_info
        provider: list[LinkingSession | None] = [None]
        ui_theme.configure_theme(self.root)
        outer, storage_host, _watcher_card = self._build_screenshot_shell()
        workspace = photos_workspace.PhotosWorkspace(
            outer, lambda: provider[0], mock.Mock(), lambda: 'en',
            storage_parent=storage_host, storage_surface=outer,
        )
        workspace.grid(row=2, column=0, columnspan=2, sticky='nsew')
        workspace._storage_text.set('4,794 · 30.9 MiB')
        workspace._ensure_initial_gallery = mock.Mock()
        provider[0] = session
        workspace.refresh_session()
        self._wait_until(lambda: workspace.tree.exists(str(TEST_GALLERY_ID)),
                         'Inspector dismissal gallery did not load.')
        workspace._select_gallery(gallery)
        viewer_frame = workspace._viewer
        controller = viewer_frame._gallery_viewer_controller
        self._wait_until(lambda: len(controller._photos) == len(page.photos),
                         'Inspector dismissal photos did not load.')
        controller.select_photo(0)
        self.assertTrue(started.wait(TEST_GUI_WAIT_SECONDS))
        self._wait_until(lambda: workspace._photo_inspector_close.winfo_ismapped(),
                         'The inspector close control was not visible.')
        before_width = workspace.viewer_pane.winfo_width()
        selected = controller.selected_photo_ids()
        workspace._photo_inspector_close.invoke()
        self._wait_until(lambda: not workspace._photo_inspector_visible
                         and workspace.viewer_pane.winfo_width() > before_width,
                         'Closing the inspector did not return its width to the gallery.')
        self.assertEqual(selected, controller.selected_photo_ids())
        self.assertEqual(TEST_GALLERY_ID, workspace._selected_gallery_id)
        self.assertIs(viewer_frame, workspace._viewer)
        release.set()
        self._wait_until(lambda: workspace._inspector_worker is None
                         and workspace._inspector_results.empty(),
                         'The dismissed metadata read did not finish.')
        self.assertFalse(workspace._photo_inspector_visible)
        cache_key = (id(session), TEST_GALLERY_ID, page.photos[0].image_id)
        self.assertNotIn(cache_key, workspace._photo_info_cache)
        controller.select_photo(0)
        self._wait_until(lambda: workspace._photo_inspector_visible
                         and cache_key in workspace._photo_info_cache,
                         'Clicking the same selected photo did not reopen its information.')
        self.root.update()
        close = workspace._photo_inspector_close
        self.assertLessEqual(
            workspace._photo_inspector_heading.winfo_rootx()
            + workspace._photo_inspector_heading.winfo_width(), close.winfo_rootx(),
        )
        evidence = WINAPP_DIR.parent / 'cache' / 'test-audit' / 'inspector-dismissal'
        evidence.mkdir(parents=True, exist_ok=True)
        bounds = (self.root.winfo_rootx(), self.root.winfo_rooty(),
                  self.root.winfo_rootx() + self.root.winfo_width(),
                  self.root.winfo_rooty() + self.root.winfo_height())
        with ImageGrab.grab(bbox=bounds) as capture:
            capture.save(evidence / 'inspector-open.png')
        reads_before_close = len(observations)
        close.invoke()
        self.root.update()
        self.assertFalse(workspace._photo_inspector_visible)
        self.assertEqual(selected, controller.selected_photo_ids())
        with ImageGrab.grab(bbox=bounds) as capture:
            capture.save(evidence / 'inspector-closed.png')
        controller.select_photo(0)
        self.root.update()
        self.assertTrue(workspace._photo_inspector_visible)
        self.assertEqual(reads_before_close, len(observations))
        self.assertIs(viewer_frame, workspace._viewer)
        workspace.destroy()
        outer.destroy()

    def test_context_glass_card_native_region_matches_mapped_geometry(self) -> None:
        """Verify default cards reveal their actual lighter parent at every corner.

        Returns:
            None: All four screenshot corners match the parent before/after
                resize. The actual HWND region excludes its corner, includes
                its center and retains the final in-window edge pixels.
        """
        ui_theme.configure_theme(self.root)
        parent = ttk.Frame(self.root, style='Card.TFrame')
        parent.pack(fill='both', expand=True)
        card = photos_workspace.GlassCard(
            parent, padding=8,
        )
        initial_width, initial_height = TEST_CONTEXT_CARD_INITIAL_SIZE
        card.place(
            x=4, y=4, width=initial_width, height=initial_height,
        )
        self.root.update_idletasks()
        self.root.update()
        self._wait_until(
            lambda: card._rounded_region_size == (
                card.winfo_width(), card.winfo_height(),
            ),
            'The real Windows card region did not settle after mapping.',
        )
        self.assertTrue(card._rounded_region_active)
        self.assertEqual(card.cget('style'), 'Glass.TFrame')
        evidence = WINAPP_DIR.parent / 'cache' / 'test-audit' / 'rounded-corners'
        self._assert_rendered_card_corners(card, 'card-surface-initial', evidence)
        api = card._rounded_region_api
        self.assertIsNotNone(api)
        assert api is not None
        copied_region = api.gdi32.CreateRectRgn(0, 0, 0, 0)
        self.assertTrue(copied_region)
        try:
            result = api.user32.GetWindowRgn(
                ctypes.c_void_p(card.winfo_id()),
                ctypes.c_void_p(copied_region),
            )
            # GetWindowRgn returns zero only when no region could be copied.
            self.assertGreater(result, 0)
            # (0, 0) is the half-open region's outer top-left corner.
            self.assertEqual(
                api.gdi32.PtInRegion(ctypes.c_void_p(copied_region), 0, 0),
                0,
            )
            self.assertNotEqual(
                api.gdi32.PtInRegion(
                    ctypes.c_void_p(copied_region),
                    card.winfo_width() // 2, card.winfo_height() // 2,
                ),
                0,
            )

            resized_width, resized_height = TEST_CONTEXT_CARD_RESIZED_SIZE
            card.place(width=resized_width, height=resized_height)
            self._wait_until(
                lambda: card._rounded_region_size == (
                    card.winfo_width(), card.winfo_height(),
                ),
                'The rounded region did not follow the resized card.',
            )
            self._assert_rendered_card_corners(card, 'card-surface-resized', evidence)
            result = api.user32.GetWindowRgn(
                ctypes.c_void_p(card.winfo_id()),
                ctypes.c_void_p(copied_region),
            )
            self.assertGreater(result, 0)
            self.assertNotEqual(
                api.gdi32.PtInRegion(
                    ctypes.c_void_p(copied_region),
                    card.winfo_width() // 2, card.winfo_height() // 2,
                ),
                0,
            )
            # width-1 is the final in-window pixel, not the half-open edge.
            self.assertNotEqual(
                api.gdi32.PtInRegion(
                    ctypes.c_void_p(copied_region),
                    card.winfo_width() - 1, card.winfo_height() // 2,
                ),
                0,
            )
            self.assertNotEqual(
                api.gdi32.PtInRegion(
                    ctypes.c_void_p(copied_region),
                    card.winfo_width() // 2, card.winfo_height() - 1,
                ),
                0,
            )
        finally:
            # GetWindowRgn copies the owned HWND region into this caller handle.
            api.gdi32.DeleteObject(ctypes.c_void_p(copied_region))
            card.destroy()
            parent.destroy()

    def test_context_glass_card_uses_flat_fallback_when_region_api_missing(self) -> None:
        """Keep contextual cards readable when native clipping is unavailable.

        Returns:
            None: Missing native APIs select the opaque card surface without
                claiming that Windows applied a rounded region.
        """
        ui_theme.configure_theme(self.root)
        with mock.patch.object(ui_theme, '_load_context_region_api', return_value=None):
            card = photos_workspace.GlassCard(
                self.root, padding=8,
            )
        card.pack(anchor='nw')
        self.root.update_idletasks()
        self.assertFalse(card._rounded_region_active)
        self.assertEqual(card.cget('style'), 'ContextFallback.TFrame')
        self.assertEqual(
            ttk.Style(card).lookup('ContextFallback.TFrame', 'background'),
            ui_theme._PALETTE['card_surface'],
        )
        card.destroy()

    def test_context_region_failure_releases_handles_and_clears_old_clip(self) -> None:
        """Keep resize fallback safe and release only caller-owned regions.

        Returns:
            None: Equal geometry is deduplicated; a refused resize deletes its
                new HRGN, clears the old one and switches to the flat style.
        """
        # Begin without a real HWND region; the controlled API below owns all
        # successful/failing region transitions asserted by this fixture.
        with mock.patch.object(ui_theme, '_load_context_region_api', return_value=None):
            card = photos_workspace.GlassCard(self.root, padding=8)
        card.place(x=0, y=0, width=80, height=50)
        self.root.update_idletasks()
        self.root.update()
        gdi32 = types.SimpleNamespace(
            CreateRoundRectRgn=mock.Mock(side_effect=[101, 202]),
            DeleteObject=mock.Mock(return_value=1),
        )
        user32 = types.SimpleNamespace(
            SetWindowRgn=mock.Mock(side_effect=[1, 0, 1]),
        )
        card._rounded_region_api = cast(
            ui_theme._ContextRegionApi,
            types.SimpleNamespace(user32=user32, gdi32=gdi32),
        )
        card._rounded_region_active = True
        card._apply_rounded_region()
        self.assertEqual(card._rounded_region_size, (80, 50))
        self.assertEqual(gdi32.CreateRoundRectRgn.call_count, 1)
        self.assertEqual(gdi32.DeleteObject.call_count, 0)
        card._apply_rounded_region()
        self.assertEqual(gdi32.CreateRoundRectRgn.call_count, 1)

        card.place(width=120, height=70)
        self.root.update_idletasks()
        self.root.update()
        card._apply_rounded_region()
        self.assertEqual(gdi32.DeleteObject.call_count, 1)
        self.assertEqual(gdi32.DeleteObject.call_args.args[0].value, 202)
        self.assertIsNone(user32.SetWindowRgn.call_args_list[-1].args[1])
        self.assertFalse(card._rounded_region_active)
        self.assertIsNone(card._rounded_region_size)
        self.assertEqual(card.cget('style'), 'ContextFallback.TFrame')
        self.assertEqual(
            ttk.Style(card).lookup('ContextFallback.TFrame', 'background'),
            ui_theme._PALETTE['card_surface'],
        )

        card._rounded_region_active = True
        card._rounded_region_after = card.after_idle(lambda: None)
        event = tk.Event()
        event.widget = card
        card._dispose_rounded_region(event)
        self.assertIsNone(card._rounded_region_after)
        card.destroy()

    def test_ui_library_inherits_button_dialog_and_slider_appearance(self) -> None:
        """Render central components on both surfaces and verify real inheritance.

        Returns:
            None: Standard/derived actions share clipping and state behavior;
                dialogs and ranges inherit the library, unknown skins refuse,
                and original catalog/detail captures retain native rendering.
        """

        ui_theme.configure_theme(self.root)
        app = ttk.Frame(self.root, style='App.TFrame', padding=12)
        app.pack(fill='both', expand=True)
        surfaces = []
        for column, name in enumerate(('App', 'Card')):
            frame = ttk.Frame(app, style=name + '.TFrame', padding=12)
            frame.grid(row=0, column=column, sticky='nsew', padx=8)
            app.columnconfigure(column, weight=1)
            surfaces.append(frame)
        evidence = WINAPP_DIR.parent / 'cache' / 'test-audit' / 'ui-library'
        evidence.mkdir(parents=True, exist_ok=True)
        for surface, name in zip(surfaces, ('app', 'card')):
            for row, style in enumerate((
                'TButton', 'Primary.TButton', 'Compact.TButton', 'LinkedPhoto.TButton',
            )):
                button = ui_theme.Button(surface, text=style, style=style)
                button.grid(row=row, column=0, sticky='ew', pady=8)
                self.root.update()
                self._assert_rendered_card_corners(button, name + '-' + style, evidence)
                button.state(['disabled'])
                self.assertTrue(button.instate(['disabled']))
                button.state(['!disabled'])
                self.assertFalse(button.instate(['disabled']))
            icon = ui_theme.SmoothIconButton(surface, 'close', style='App.Icon.TButton')
            icon.grid(row=4, column=0)
            self.assertIsInstance(icon, ui_theme.Button)
            self.assertEqual(ui_theme._PALETTE['background' if name == 'app' else 'card_surface'],
                             ttk.Style(self.root).lookup(str(icon.cget('style')), 'background'))
            slider = ui_theme.Slider(surface, from_=0, to=100, orient='horizontal')
            slider.grid(row=5, column=0, sticky='ew', pady=8)
            slider.set(37)
            self.assertEqual(37, slider.get())
            self.root.update()
            self.assertLessEqual(slider.winfo_height(), ui_theme.SLIDER_THUMB_SIZE[0])
            # Native trough click and thumb drag must survive the shared image
            # elements; checking set/get alone would miss broken class bindings.
            click_x, click_y = slider.winfo_width() - 2, slider.winfo_height() // 2
            slider.event_generate('<ButtonPress-1>', x=click_x, y=click_y)
            slider.event_generate('<ButtonRelease-1>', x=click_x, y=click_y)
            self.root.update()
            self.assertGreater(slider.get(), 37)
        self.root.update()
        bounds = (self.root.winfo_rootx(), self.root.winfo_rooty(),
                  self.root.winfo_rootx() + self.root.winfo_width(),
                  self.root.winfo_rooty() + self.root.winfo_height())
        with ImageGrab.grab(bbox=bounds) as capture:
            capture.save(evidence / 'component-library.png')
        style = ttk.Style(self.root)
        # A base delta must reach both descendants through ttk inheritance.
        inherited_padding = (3, 2)
        style.configure('LinkedPhoto.TButton', padding=inherited_padding)
        for variant in ('Selected.LinkedPhoto.TButton', 'Placeholder.LinkedPhoto.TButton'):
            self.assertEqual(style.lookup('LinkedPhoto.TButton', 'padding'),
                             style.lookup(variant, 'padding'))
            self.assertEqual(style.layout('LinkedPhoto.TButton'), style.layout(variant))
        with self.assertRaisesRegex(ValueError, 'central UI library'):
            ui_theme.Button(app, text='Invalid', style='ScreenSpecific.TButton')
        with self.assertRaisesRegex(ValueError, 'central UI library'):
            button.configure(style='ScreenSpecific.TButton')
        button.configure(style='App.Icon.TButton')
        self.root.update()
        self.assertEqual(0, button._rounded_applied_radius)
        button.configure(style='Primary.TButton')
        self.root.update()
        self._assert_rendered_card_corners(button, 'changed-role-primary', evidence)
        with self.assertRaisesRegex(ValueError, 'central UI library'):
            ui_theme.Slider(app, style='ScreenSpecific.Horizontal.TScale')
        dialog = ui_theme.Dialog(self.root)
        dialog.geometry('360x180+20+20')
        self.assertEqual(ui_theme._PALETTE['background'], dialog.cget('background'))
        panel = ui_theme.DialogSurface(dialog, padding=12)
        panel.pack(fill='both', expand=True, padx=8, pady=8)
        ui_theme.Button(panel, text='Close', command=dialog.destroy).pack()
        self.assertIsInstance(panel, ui_theme.GlassCard)
        self.root.update()
        self._assert_rendered_card_corners(panel, 'dialog-surface', evidence)
        dialog.destroy()
        # Unsupported shaping inherits the semantic parent while replacing only
        # its rounded material, preserving enabled/disabled command behavior.
        with mock.patch.object(ui_theme, '_load_context_region_api', return_value=None):
            flat = ui_theme.Button(app, text='Primary fallback', style='Primary.TButton')
            selected_flat = ui_theme.Button(
                app, text='Selected fallback', style='Selected.LinkedPhoto.TButton',
            )
        self.assertEqual('Flat.Primary.TButton', flat.cget('style'))
        self.assertFalse(flat._rounded_region_active)
        self.assertEqual('Flat.Selected.LinkedPhoto.TButton', selected_flat.cget('style'))
        self.assertEqual('Button.border', style.layout(str(selected_flat.cget('style')))[0][0])
        self.assertEqual(ui_theme._PALETTE['primary'],
                         style.lookup(str(selected_flat.cget('style')), 'bordercolor', ('selected',)))
        flat.destroy()
        selected_flat.destroy()
        app.destroy()

    def test_catalogue_cap_schedules_one_paced_page(self) -> None:
        """Continue cataloguing at a fixed pace after the bounded burst.

        Returns:
            None: One timer requests the captured cursor, then later pages
                retain the pass count and error retry stays explicit.
        """
        session = cast(LinkingSession, mock.Mock())
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: None, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        self.root.update_idletasks()
        self.root.update()
        workspace._session = session
        workspace._session_provider = lambda: session
        workspace._next_after = TEST_GALLERY_ID
        workspace._pages_this_pass = (
            photos_workspace.PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT
        )
        scheduled: list[tuple[str, int, Callable[[], None]]] = []
        workspace.after = mock.Mock(
            side_effect=lambda delay, callback: (
                scheduled.append((
                    f'after#{len(scheduled) + 1}', delay, callback,
                )) or scheduled[-1][0]
            ),
        )
        workspace.after_cancel = mock.Mock()
        request_page = mock.Mock()
        workspace._request_page = request_page
        workspace._continue_catalogue()
        workspace._continue_catalogue()
        paced = [
            item for item in scheduled
            if item[1]
            == photos_workspace.PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS
        ]
        self.assertEqual(len(paced), 1)
        self.assertEqual(
            paced[0][1],
            photos_workspace.PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS,
        )
        first_after_id = paced[0][0]
        self.assertEqual(workspace._catalogue_after, first_after_id)
        self.assertEqual(workspace.load_more_button.winfo_manager(), '')
        self.assertEqual(
            workspace._pages_this_pass,
            photos_workspace.PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT,
        )
        paced[0][2]()
        self.assertEqual(
            request_page.call_args_list,
            [mock.call(TEST_GALLERY_ID)],
        )
        self.assertIsNone(workspace._catalogue_after)

        workspace._next_after = TEST_GALLERY_ID + 1
        workspace._pages_this_pass += 1
        workspace._continue_catalogue()
        paced = [
            item for item in scheduled
            if item[1]
            == photos_workspace.PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS
        ]
        self.assertEqual(len(paced), 2)
        second_after_id = paced[1][0]
        self.assertEqual(workspace._catalogue_after, second_after_id)
        self.assertEqual(
            workspace._pages_this_pass,
            photos_workspace.PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT + 1,
        )
        workspace._accept_page(None)
        self.assertIn(
            mock.call(second_after_id), workspace.after_cancel.call_args_list,
        )
        paced[1][2]()
        self.assertEqual(
            request_page.call_args_list,
            [mock.call(TEST_GALLERY_ID)],
        )
        workspace._retry_catalogue()
        self.assertEqual(
            request_page.call_args_list,
            [mock.call(TEST_GALLERY_ID), mock.call(TEST_GALLERY_ID + 1)],
        )
        self.assertEqual(
            workspace._pages_this_pass,
            photos_workspace.PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT + 1,
        )
        workspace.destroy()

    def test_catalogue_http_429_schedules_one_real_workspace_retry(self) -> None:
        """Respect Retry-After and preserve the last complete page boundary.

        Returns:
            None: One real PhotosWorkspace schedules its exact failed cursor.
        """
        session = cast(LinkingSession, mock.Mock())
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: None, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        self.root.update_idletasks()
        self.root.update()
        workspace._session = session
        workspace._session_provider = lambda: session
        workspace._next_after = TEST_GALLERY_ID
        scheduled: list[tuple[int, Callable[[], None]]] = []
        workspace.after = mock.Mock(side_effect=lambda delay, callback: (
            scheduled.append((delay, callback)) or f'throttle-{len(scheduled)}'
        ))
        workspace.after_cancel = mock.Mock()
        workspace._request_page = mock.Mock()
        workspace._accept_page(
            None, throttled=True, retry_after_seconds=2,
            cursor=TEST_GALLERY_ID,
        )
        rate_callbacks = [
            callback for delay, callback in scheduled if delay == 2000
        ]
        self.assertEqual(len(rate_callbacks), 1)
        self.assertEqual(workspace._catalogue_rate_retries, 1)
        self.assertEqual(workspace._next_after, TEST_GALLERY_ID)
        rate_callbacks[0]()
        workspace._request_page.assert_called_once_with(TEST_GALLERY_ID)
        self.assertIsNone(workspace._catalogue_after)
        workspace.destroy()

    def test_catalogue_http_429_fences_cancelled_timers_and_caps_retries(self) -> None:
        """Reject stale retry callbacks and refuse repeated 429 retry storms.

        Returns:
            None: Generation change and bounded retry count prevent dispatch.
        """
        session = cast(LinkingSession, mock.Mock())
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: None, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        self.root.update_idletasks()
        self.root.update()
        workspace._session = session
        workspace._session_provider = lambda: session
        workspace._next_after = TEST_GALLERY_ID
        scheduled: list[tuple[int, Callable[[], None]]] = []
        workspace.after = mock.Mock(side_effect=lambda delay, callback: (
            scheduled.append((delay, callback)) or f'throttle-{len(scheduled)}'
        ))
        workspace.after_cancel = mock.Mock()
        workspace._request_page = mock.Mock()
        workspace._accept_page(
            None, throttled=True, retry_after_seconds=1,
            cursor=TEST_GALLERY_ID,
        )
        rate_callbacks = [
            callback for delay, callback in scheduled if delay == 1000
        ]
        self.assertEqual(len(rate_callbacks), 1)
        workspace._catalogue_generation += 1
        rate_callbacks[0]()
        workspace._request_page.assert_not_called()
        workspace._catalogue_generation -= 1
        workspace._accept_page(
            None, throttled=True, retry_after_seconds=1,
            cursor=TEST_GALLERY_ID,
        )
        self.assertEqual(workspace._catalogue_rate_retries, 2)
        workspace._accept_page(
            None, throttled=True, retry_after_seconds=1,
            cursor=TEST_GALLERY_ID,
        )
        self.assertEqual(
            sum(delay == 1000 for delay, _callback in scheduled), 2,
        )
        workspace.destroy()

    def test_cancelled_catalogue_timer_cannot_clear_its_replacement(self) -> None:
        """Fence late cancelled callbacks from replacing current page work.

        Returns:
            None: A stale callback cannot erase or request ahead of a newer
                timer, and destruction cancels the still-current timer.
        """
        session = cast(LinkingSession, mock.Mock())
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: None, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        self.root.update_idletasks()
        self.root.update()
        workspace._session = session
        workspace._session_provider = lambda: session
        workspace._next_after = TEST_GALLERY_ID
        workspace._pages_this_pass = (
            photos_workspace.PHOTOS_CATALOGUE_AUTO_PAGE_LIMIT
        )
        scheduled: list[tuple[str, int, Callable[[], None]]] = []
        workspace.after = mock.Mock(
            side_effect=lambda delay, callback: (
                scheduled.append((
                    f'after#{len(scheduled) + 1}', delay, callback,
                )) or scheduled[-1][0]
            ),
        )
        workspace.after_cancel = mock.Mock()
        request_page = mock.Mock()
        workspace._request_page = request_page

        workspace._continue_catalogue()
        paced = [
            item for item in scheduled
            if item[1]
            == photos_workspace.PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS
        ]
        first_after_id = paced[0][0]
        workspace._cancel_catalogue_continuation()
        workspace._next_after += 1
        workspace._continue_catalogue()
        paced = [
            item for item in scheduled
            if item[1]
            == photos_workspace.PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS
        ]
        second_after_id = paced[1][0]
        self.assertIn(
            mock.call(first_after_id), workspace.after_cancel.call_args_list,
        )
        self.assertEqual(workspace._catalogue_after, second_after_id)
        paced[0][2]()
        self.assertEqual(workspace._catalogue_after, second_after_id)
        request_page.assert_not_called()

        workspace._cancel_catalogue_continuation()
        workspace._next_after += 1
        workspace._continue_catalogue()
        paced = [
            item for item in scheduled
            if item[1]
            == photos_workspace.PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS
        ]
        third_after_id = paced[2][0]
        self.assertEqual(workspace._catalogue_after, third_after_id)
        workspace._session_provider = lambda: cast(LinkingSession, mock.Mock())
        paced[2][2]()
        self.assertIsNone(workspace._catalogue_after)
        request_page.assert_not_called()

        workspace._session_provider = lambda: session
        workspace._continue_catalogue()
        paced = [
            item for item in scheduled
            if item[1]
            == photos_workspace.PHOTOS_CATALOGUE_CONTINUATION_DELAY_MS
        ]
        fourth_after_id = paced[3][0]
        self.assertEqual(workspace._catalogue_after, fourth_after_id)
        workspace.destroy()
        self.assertIsNone(workspace._catalogue_after)
        self.assertIn(
            mock.call(fourth_after_id), workspace.after_cancel.call_args_list,
        )
        paced[3][2]()
        request_page.assert_not_called()

    def test_gallery_context_opens_editor_only_for_gallery_rows(self) -> None:
        """Expose the browser editor action only on a real gallery context.

        Returns:
            None: The action receives the exact current session and listed
                gallery, while creation-only contexts omit the editor action.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Album')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        manager = mock.Mock()
        manager.open_photos_gallery_editor.return_value = True
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
            manager=manager,
        )
        workspace.pack(fill='both', expand=True)
        self._wait_until(
            lambda: workspace.tree.exists(str(TEST_GALLERY_ID)),
            'The editor fixture gallery did not load.',
        )
        event = mock.Mock()
        event.widget = workspace.tree
        event.x_root = workspace.tree.winfo_rootx() + 20
        event.y_root = workspace.tree.winfo_rooty() + 20
        workspace._gallery_context(TEST_GALLERY_ID, event)
        editor = next(
            widget for widget in workspace._context_card.winfo_children()
            if widget.winfo_class() == 'TButton'
            and widget.cget('text') == tr('en', 'photos_workspace_edit_gallery')
        )
        editor.invoke()
        manager.open_photos_gallery_editor.assert_called_once_with(
            session, gallery,
        )

        workspace._gallery_context(
            TEST_GALLERY_ID, event, creation_only=True,
        )
        self.assertFalse(any(
            widget.winfo_class() == 'TButton'
            and widget.cget('text') == tr(
                'en', 'photos_workspace_edit_gallery',
            )
            for widget in workspace._context_card.winfo_children()
        ))
        workspace.destroy()

    def test_earlier_completed_create_does_not_require_continue(self) -> None:
        """Submit a current draft without showing another operation's result.

        Returns:
            None: One Create action admits only the requested gallery; no
                profile-wide preflight or prior acknowledgement is required.
        """
        parent = LinkedGallery(TEST_PARENT_GALLERY_ID, None, 'Parent')
        created = LinkedGallery(TEST_GALLERY_ID, parent.gallery_id, 'Current draft')
        manager = mock.Mock()
        manager.create_photos_gallery.return_value = BoundCreateReceipt(created, 'b' * 64)
        workspace, session, card = self._photos_create_context(manager, parent)
        workspace._create_gallery(card, session, parent.gallery_id, created.title)
        self._wait_until(
            lambda: workspace.tree.exists(str(created.gallery_id)),
            'One Create action did not admit the current gallery.',
        )
        manager.create_photos_gallery.assert_called_once()
        self.assertEqual(
            (session, created.title, parent.gallery_id),
            manager.create_photos_gallery.call_args.args[:3],
        )
        manager.inspect_photos_gallery_creation.assert_not_called()
        manager.acknowledge_prior_photos_gallery.assert_not_called()
        self.assertEqual(
            [workspace._text('close')],
            [child.cget('text') for child in card.winfo_children()
             if isinstance(child, ttk.Button)],
        )
        workspace.destroy()

    def test_created_gallery_starts_watcher_without_catalogue_reload(self) -> None:
        """Create root and child galleries, then invoke their real Watcher action.

        Returns:
            None: Confirmed receipts work through gallery and rail menus
                without another catalogue request or changing an existing selection.
        """
        parent = LinkedGallery(TEST_PARENT_GALLERY_ID, None, 'Parent')
        for parent_id in (None, parent.gallery_id):
            with self.subTest(parent_id=parent_id):
                # This distinct synthetic ID was absent from the initial page.
                created = LinkedGallery(
                    TEST_GALLERY_ID, parent_id, 'Fresh Watcher gallery',
                )
                receipt = BoundCreateReceipt(created, 'e' * 64)
                manager = mock.Mock()
                manager.create_photos_gallery.return_value = receipt
                start = mock.Mock()
                workspace, session, card = self._photos_create_context(
                    manager, parent, start,
                )
                session.can_upload.return_value = True
                session.can_read_presentation.return_value = False
                session.media_page.return_value = LinkedMediaPage(
                    created.gallery_id, parent_id, (), None,
                )
                if parent_id is None:
                    workspace._clear_catalogue()
                    card = workspace._open_context(
                        workspace._context_event(workspace.tree),
                    )
                previous_selection = workspace._selected_gallery_id
                catalogue_reads = session.gallery_page.call_count
                workspace._create_gallery(
                    card, session, parent_id, created.title,
                )
                self._wait_until(
                    lambda: workspace.tree.exists(str(created.gallery_id)),
                    'Creation did not mount the new gallery.',
                )
                self.assertIn(created.gallery_id, workspace._fresh_catalogue_ids)
                self.assertEqual(
                    created.gallery_id if parent_id is None else previous_selection,
                    workspace._selected_gallery_id,
                )
                manager.acknowledge_photos_gallery.assert_not_called()
                if parent_id is not None:
                    workspace._select_gallery(created)
                self._watcher_action_button(
                    workspace, created, rail=parent_id is not None,
                ).invoke()
                start.assert_called_once_with(session, created)
                self.assertIsNone(workspace._context_card)
                self.assertEqual(catalogue_reads, session.gallery_page.call_count)
                manager.create_photos_gallery.assert_called_once()
                workspace.destroy()

    def test_watcher_refusal_keeps_a_visible_explanation(self) -> None:
        """Refuse stale or unavailable gallery actions without silently closing.

        Returns:
            None: No rejected click reaches the host; its contextual explanation
                remains within the desktop and narrow Photos surfaces.
        """

        ui_theme.configure_theme(self.root)
        gallery = LinkedGallery(TEST_PARENT_GALLERY_ID, None, 'Watcher album')
        for reason in (
            'cached', 'removed', 'replaced', 'expired', 'permission',
            'session', 'host',
        ):
            with self.subTest(reason=reason):
                start = mock.Mock()
                workspace, session, _card = self._photos_create_context(
                    mock.Mock(), gallery, start,
                )
                session.can_upload.return_value = True
                button = self._watcher_action_button(workspace, gallery)
                card = workspace._context_card
                if reason == 'cached':
                    workspace._fresh_catalogue_ids.discard(gallery.gallery_id)
                elif reason == 'removed':
                    workspace._galleries.pop(gallery.gallery_id)
                elif reason == 'replaced':
                    workspace._galleries[gallery.gallery_id] = LinkedGallery(
                        gallery.gallery_id, None, 'Changed album',
                    )
                elif reason == 'expired':
                    session.available.return_value = False
                elif reason == 'permission':
                    session.can_upload.return_value = False
                elif reason == 'session':
                    replacement = mock.Mock()
                    workspace._session_provider = lambda: replacement
                else:
                    workspace._start_watcher = None
                button.invoke()
                start.assert_not_called()
                self.assertIs(card, workspace._context_card)
                assert card is not None
                expected = workspace._text('watcher_unavailable')
                self.assertEqual(expected, workspace.tree_status.cget('text'))
                self.assertEqual(
                    [expected],
                    [child.cget('text') for child in card.winfo_children()
                     if isinstance(child, ttk.Label)],
                )
                if reason == 'cached':
                    # Exercise the existing default and minimum fixture sizes.
                    for width, height in ((840, 620), TEST_VIEWER_MINIMUM_SIZE):
                        self.root.geometry(f'{width}x{height}')
                        self.root.update()
                        workspace._place_context(card)
                        self.root.update_idletasks()
                        self.assertTrue(card.winfo_ismapped())
                        self.assertGreaterEqual(card.winfo_x(), 0)
                        self.assertGreaterEqual(card.winfo_y(), 0)
                        self.assertLessEqual(
                            card.winfo_x() + card.winfo_width(),
                            workspace.winfo_width(),
                        )
                        self.assertLessEqual(
                            card.winfo_y() + card.winfo_height(),
                            workspace.winfo_height(),
                        )
                        bounds = (
                            self.root.winfo_rootx(), self.root.winfo_rooty(),
                            self.root.winfo_rootx() + self.root.winfo_width(),
                            self.root.winfo_rooty() + self.root.winfo_height(),
                        )
                        evidence = WINAPP_DIR.parent / 'cache' / 'test-audit'
                        evidence.mkdir(parents=True, exist_ok=True)
                        with ImageGrab.grab(bbox=bounds) as captured:
                            captured.save(
                                evidence / f'photos-watcher-refusal-{width}.png',
                            )
                    self.root.geometry('840x620')
                workspace.destroy()

    def test_immediate_cache_probe_cancels_its_pending_widget_timer(self) -> None:
        """Retire a delayed cache callback when an immediate probe supersedes it.

        Returns:
            None: Widget teardown cannot leave the old Tcl command scheduled
                after the Python timer owner has cleared its reference.
        """
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: None, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        self.root.update()
        workspace._cache_after = workspace.after(
            photos_workspace.PHOTOS_CACHE_REFRESH_MS,
            workspace._start_cache_probe,
        )
        pending = workspace._cache_after
        self.assertIn(pending, self.root.tk.call('after', 'info'))
        workspace._start_cache_probe()
        self.assertIsNone(workspace._cache_after)
        self.assertNotIn(pending, self.root.tk.call('after', 'info'))
        workspace.destroy()
        self.assertNotIn(pending, self.root.tk.call('after', 'info'))

    def test_stale_creation_receipt_cannot_admit_a_watcher_gallery(self) -> None:
        """Reject a late create receipt after its live connection changes.

        Returns:
            None: A stale or expired session cannot publish a gallery, fresh
                membership or receipt into the current Photos workspace.
        """
        parent = LinkedGallery(TEST_PARENT_GALLERY_ID, None, 'Parent')
        created = LinkedGallery(TEST_GALLERY_ID, parent.gallery_id, 'Late child')
        receipt = BoundCreateReceipt(created, 'f' * 64)
        for reason in ('provider', 'workspace', 'expired'):
            with self.subTest(reason=reason):
                workspace, session, card = self._photos_create_context(
                    mock.Mock(), parent,
                )
                if reason == 'provider':
                    replacement = mock.Mock()
                    workspace._session_provider = lambda: replacement
                elif reason == 'workspace':
                    workspace._session = mock.Mock()
                else:
                    session.available.return_value = False
                workspace._creation_completed(card, session, receipt)
                self.assertNotIn(created.gallery_id, workspace._galleries)
                self.assertNotIn(
                    created.gallery_id, workspace._fresh_catalogue_ids,
                )
                self.assertFalse(workspace.tree.exists(str(created.gallery_id)))
                workspace.destroy()

    def test_recovered_creation_is_immediately_usable_for_watcher(self) -> None:
        """Treat an automatically recovered receipt like an ordinary creation.

        Returns:
            None: A recovered row reaches Watcher without acknowledgement or
                catalogue reload, including after an equal row replacement.
        """
        parent = LinkedGallery(TEST_PARENT_GALLERY_ID, None, 'Parent')
        existing = LinkedGallery(TEST_GALLERY_ID, parent.gallery_id, 'Same title')
        manager = mock.Mock()
        manager.create_photos_gallery.return_value = BoundCreateReceipt(existing, 'c' * 64)
        start = mock.Mock()
        workspace, session, card = self._photos_create_context(manager, parent, start)
        session.can_upload.return_value = True
        catalogue_reads = session.gallery_page.call_count
        workspace._create_gallery(card, session, parent.gallery_id, existing.title)
        self._wait_until(
            lambda: workspace.tree.exists(str(existing.gallery_id)),
            'The recovered gallery was not made usable.',
        )
        button = self._watcher_action_button(workspace, existing)
        # A catalogue refresh may recreate the same immutable descriptor.
        workspace._galleries[existing.gallery_id] = LinkedGallery(
            existing.gallery_id, existing.parent_id, existing.title,
        )
        button.invoke()
        start.assert_called_once_with(session, existing)
        self.assertEqual(catalogue_reads, session.gallery_page.call_count)
        workspace.destroy()

    def test_failed_creation_keeps_an_editable_draft_and_one_create_action(self) -> None:
        """Keep ordinary retry in the name form instead of journal management.

        Returns:
            None: Desktop and narrow error cards fit; the draft survives and
                its next Create succeeds without Check again or Manage link.
        """

        ui_theme.configure_theme(self.root)
        parent = LinkedGallery(TEST_PARENT_GALLERY_ID, None, 'Parent')
        gallery = LinkedGallery(TEST_GALLERY_ID, parent.gallery_id, 'Draft title')
        manager = mock.Mock()
        manager.create_photos_gallery.side_effect = [
            LinkingError('lost reply'), BoundCreateReceipt(gallery, 'd' * 64),
        ]
        workspace, session, card = self._photos_create_context(manager, parent)
        workspace._create_gallery(card, session, parent.gallery_id, gallery.title)
        self._wait_until(
            lambda: not workspace._context_busy
            and any(isinstance(child, ttk.Entry) for child in card.winfo_children()),
            'A failed create did not restore its editable draft.',
        )
        entry = next(child for child in card.winfo_children() if isinstance(child, ttk.Entry))
        self.assertEqual(gallery.title, entry.get())
        buttons = [child for child in card.winfo_children() if isinstance(child, ttk.Button)]
        self.assertEqual([workspace._text('create')], [button.cget('text') for button in buttons])
        for width, height in ((840, 620), TEST_VIEWER_MINIMUM_SIZE):
            self.root.geometry(f'{width}x{height}')
            self.root.update()
            workspace._place_context(card)
            self.root.update_idletasks()
            self.assertGreaterEqual(card.winfo_x(), 0)
            self.assertGreaterEqual(card.winfo_y(), 0)
            self.assertLessEqual(card.winfo_x() + card.winfo_width(), workspace.winfo_width())
            self.assertLessEqual(card.winfo_y() + card.winfo_height(), workspace.winfo_height())
            bounds = (
                self.root.winfo_rootx(), self.root.winfo_rooty(),
                self.root.winfo_rootx() + self.root.winfo_width(),
                self.root.winfo_rooty() + self.root.winfo_height(),
            )
            evidence = WINAPP_DIR.parent / 'cache' / 'test-audit'
            evidence.mkdir(parents=True, exist_ok=True)
            with ImageGrab.grab(bbox=bounds) as captured:
                captured.save(evidence / f'photos-create-retry-{width}.png')
        buttons[0].invoke()
        self._wait_until(
            lambda: workspace.tree.exists(str(gallery.gallery_id)),
            'The normal Create action did not recover its retained draft.',
        )
        self.assertEqual(2, manager.create_photos_gallery.call_count)
        self.root.geometry('840x620')
        workspace.destroy()

    def test_dismissing_create_before_admission_cancels_dispatch(self) -> None:
        """Cancel the captured request while its worker waits before admission.

        Returns:
            None: The dismissed card cannot admit an HTTP create or stale row.
        """
        parent = LinkedGallery(TEST_PARENT_GALLERY_ID, None, 'Parent')
        entered = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)
        manager = mock.Mock()

        def create(
            session: LinkingSession, title: str, parent_id: int | None,
            dispatch: CreateDispatch,
        ) -> BoundCreateReceipt:
            """Hold admission until the context has been dismissed.

            Args:
                session (LinkingSession): Exact submitted session.
                title (str): Captured user draft.
                parent_id (int | None): Captured parent or root.
                dispatch (CreateDispatch): Gate tied to the current card.
            Returns:
                BoundCreateReceipt: Unreachable because the gate is cancelled.
            """
            entered.set()
            release.wait(TEST_GUI_WAIT_SECONDS)
            self.assertFalse(dispatch.admit())
            raise LinkingError('cancelled before sending')

        manager.create_photos_gallery.side_effect = create
        workspace, session, card = self._photos_create_context(manager, parent)
        workspace._create_gallery(card, session, parent.gallery_id, 'New title')
        self.assertTrue(entered.wait(TEST_GUI_WAIT_SECONDS))
        dispatch = workspace._create_dispatch
        self.assertIsNotNone(dispatch)
        workspace._dismiss_context(restore=False)
        self.assertTrue(dispatch.cancelled.is_set())
        release.set()
        self._wait_until(
            lambda: not workspace._context_busy,
            'The dismissed create did not finish its queued result.',
        )
        session.gallery_create_request.assert_not_called()
        self.assertIsNone(workspace._context_card)
        workspace.destroy()

    def test_double_create_does_not_start_a_second_worker(self) -> None:
        """Keep a second submit from replacing the active request gate.

        Returns:
            None: Exactly the first draft is dispatched and admitted to the tree.
        """
        parent = LinkedGallery(TEST_PARENT_GALLERY_ID, None, 'Parent')
        gallery = LinkedGallery(TEST_GALLERY_ID, parent.gallery_id, 'First draft')
        entered = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)
        manager = mock.Mock()

        def create(
            session: LinkingSession, title: str, parent_id: int | None,
            dispatch: CreateDispatch,
        ) -> BoundCreateReceipt:
            """Hold the first response while a second submit is attempted.

            Args:
                session (LinkingSession): Exact submitted session.
                title (str): Captured first user draft.
                parent_id (int | None): Captured parent or root.
                dispatch (CreateDispatch): Gate owned by the first draft.
            Returns:
                BoundCreateReceipt: Confirmed first gallery after release.
            """
            entered.set()
            release.wait(TEST_GUI_WAIT_SECONDS)
            return BoundCreateReceipt(gallery, 'e' * 64)

        manager.create_photos_gallery.side_effect = create
        workspace, session, card = self._photos_create_context(manager, parent)
        workspace._create_gallery(card, session, parent.gallery_id, gallery.title)
        self.assertTrue(entered.wait(TEST_GUI_WAIT_SECONDS))
        first_dispatch = workspace._create_dispatch
        workspace._create_gallery(card, session, parent.gallery_id, 'Second draft')
        self.assertIs(first_dispatch, workspace._create_dispatch)
        release.set()
        self._wait_until(
            lambda: workspace.tree.exists(str(gallery.gallery_id)),
            'The first create did not complete after a duplicate submit.',
        )
        manager.create_photos_gallery.assert_called_once()
        self.assertEqual(gallery.title, manager.create_photos_gallery.call_args.args[1])
        workspace.destroy()

    def test_cache_icons_update_from_viewer_without_information_request(self) -> None:
        """Observe local coverage automatically from loaded viewer metadata.

        Returns:
            None: The tree changes from cloud to local without another server
                catalogue or an explicit Information request.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.gallery_pinned.return_value = False
        session.has_cached_photo.return_value = False
        gallery = LinkedGallery(
            TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Cache album',
        )
        parent = LinkedGallery(TEST_PARENT_GALLERY_ID, None, 'Parent')
        session.gallery_page.return_value = LinkedGalleryPage(
            (parent, gallery), None,
        )
        manager = mock.Mock()
        manager.photos_site_identity.return_value = (
            'https://gallery.example.test/photos', 7,
        )
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
            manager=manager,
        )
        workspace.pack(fill='both', expand=True)
        self._wait_until(
            lambda: workspace._cache_state.get(TEST_GALLERY_ID) == 'cloud',
            'A loaded viewer did not publish its automatic cloud cache icon.',
        )
        row_id = str(TEST_GALLERY_ID)
        parent_id = str(TEST_PARENT_GALLERY_ID)
        self.assertEqual('Cache album', workspace.tree.item(row_id, 'text'))
        cloud_image = workspace.tree.item(row_id, 'image')
        self.assertTrue(cloud_image)
        self._wait_until(
            lambda: workspace._viewer._gallery_viewer_controller._worker is None,
            'The initial viewer requests did not settle before the local probe.',
        )
        metadata_reads = session.media_page.call_count
        session.has_cached_photo.return_value = True
        controller = workspace._viewer._gallery_viewer_controller
        controller.on_cache_changed(controller._photos, True)
        self._wait_until(
            lambda: workspace._cache_state.get(TEST_GALLERY_ID) == 'local',
            'Local cache completion did not update the tree icon.',
        )
        local_image = workspace.tree.item(row_id, 'image')
        self.assertEqual('Cache album', workspace.tree.item(row_id, 'text'))
        self.assertNotEqual(cloud_image, local_image)
        controller.on_cache_changed(controller._photos, False)
        self._wait_until(
            lambda: workspace._cache_state.get(TEST_GALLERY_ID) == 'partial',
            'A partial catalogue incorrectly claimed that the whole album was cached.',
        )
        self.assertEqual(metadata_reads, session.media_page.call_count)
        session.media_gallery_info.assert_not_called()
        session.media_gallery_details.assert_not_called()

        self._wait_until(
            lambda: workspace._cache_worker is None
            and not workspace._cache_pending and workspace._cache_after is None,
            'The partial cache probe did not settle before pinning.',
        )
        session.gallery_pinned.return_value = True
        workspace.tree.item(parent_id, open=False)
        workspace._gallery_pins[TEST_GALLERY_ID] = True
        selection = (row_id,)
        workspace.tree.selection_set(selection)
        target = mock.Mock(
            issuer='https://gallery.example.test/photos', user_id=7,
            gallery_id=TEST_GALLERY_ID,
        )
        workspace.set_watcher_recording(target, True)
        self.root.update()
        recording_image = workspace.tree.item(row_id, 'image')
        ancestor_image = workspace.tree.item(parent_id, 'image')
        self.assertTrue(recording_image)
        self.assertTrue(ancestor_image)
        self.assertNotEqual(local_image, recording_image)
        self.assertNotEqual(recording_image, ancestor_image)
        self.assertEqual('Cache album', workspace.tree.item(row_id, 'text'))
        self.assertFalse(workspace.tree.item(parent_id, 'open'))
        self.assertEqual(selection, workspace.tree.selection())
        workspace.set_watcher_recording(target, False)
        self.root.update()
        self.assertNotEqual(
            recording_image, workspace.tree.item(row_id, 'image'),
        )
        self.assertEqual(selection, workspace.tree.selection())
        workspace._tree_marker_images.clear()
        workspace._recording_images.clear()
        with mock.patch.object(
            photos_workspace, 'create_smooth_icon', return_value=None,
        ):
            workspace.set_watcher_recording(target, True)
            self.root.update()
            fallback_image = workspace.tree.item(row_id, 'image')
            fallback_label = workspace.tree.item(row_id, 'text')
            self.assertTrue(fallback_image)
            self.assertIsInstance(
                workspace._recording_images[(False, True)], tk.PhotoImage,
            )
            self.assertIn('PIN ', fallback_label)
            self.assertIn('Cache album', fallback_label)
            workspace.set_watcher_recording(None, False)
            self.root.update()
            self.assertFalse(workspace.tree.item(row_id, 'image'))
            self.assertIn('PIN ', workspace.tree.item(row_id, 'text'))
        workspace.destroy()

    def test_cache_context_starts_and_cancels_only_selected_gallery(self) -> None:
        """Wire explicit preload and Keep preloaded to the captured gallery.

        Returns:
            None: A worker receives the exact session/target and its cancellation
                leaves unrelated gallery and upload actions untouched.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.gallery_pinned.return_value = False
        session.has_cached_photo.return_value = False
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Preload album')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        self._wait_until(
            lambda: TEST_GALLERY_ID in workspace._gallery_pins,
            'Local pin preference did not become available.',
        )
        worker = mock.Mock(spec=photos_workspace.GalleryPreloadWorker)
        worker.is_alive.return_value = True
        worker.snapshot.return_value = photos_workspace.PreloadProgress(
            'preloading', TEST_GALLERY_ID, len(page.photos),
            0, 0, 0, 0, False, '',
        )
        event = mock.Mock()
        event.widget = workspace.tree
        event.x_root = workspace.tree.winfo_rootx() + 20
        event.y_root = workspace.tree.winfo_rooty() + 20
        for pinned, thumbnails_only in ((None, False), (True, False), (None, True)):
            workspace._preload_worker = None
            if thumbnails_only:
                narrow_width, narrow_height = TEST_VIEWER_MINIMUM_SIZE
                self.root.geometry(f'{narrow_width}x{narrow_height}')
                self.root.update()
            workspace._gallery_context(TEST_GALLERY_ID, event)
            with mock.patch.object(
                photos_workspace, 'GalleryPreloadWorker', return_value=worker,
            ) as factory:
                if pinned is True:
                    workspace._cache_pin_button.invoke()
                else:
                    preload = next(
                        widget for widget in workspace._context_card.winfo_children()
                        if widget.winfo_class() == 'TButton'
                        and widget.cget('text') == workspace._text(
                            'preload_thumbnails' if thumbnails_only else 'preload',
                        )
                    )
                    self.root.update_idletasks()
                    self.assertGreater(preload.winfo_width(), 1)
                    self.assertLessEqual(
                        workspace._context_card.winfo_width(), workspace.winfo_width(),
                    )
                    preload.invoke()
                factory.assert_called_once()
                args, kwargs = factory.call_args
                self.assertEqual((session, TEST_GALLERY_ID), args)
                self.assertEqual(
                    {
                        'pinned': pinned, 'download': True,
                        'thumbnails_only': thumbnails_only,
                        'yield_to_foreground': workspace._preload_foreground.is_set,
                    },
                    {
                        key: value for key, value in kwargs.items()
                        if key not in ('diagnostic_callback', 'foreground_reason')
                    },
                )
                foreground_reason = kwargs.get('foreground_reason')
                self.assertTrue(callable(foreground_reason))
                self.assertIsInstance(foreground_reason(), str)
                diagnostic_callback = kwargs.get('diagnostic_callback')
                self.assertTrue(callable(diagnostic_callback))
                diagnostic_callback('phase=preload-fetch accepted-session')
                self.assertIn(
                    'accepted-session', workspace.diagnostics_snapshot(),
                )
            self.assertIsNone(workspace._context_card)
            self.assertEqual('grid', workspace._preload_strip.winfo_manager())
            self.assertIsNotNone(workspace._preload_activity_after)
            workspace._cancel_preload()
        # Three distinct explicit actions: full preload, pin and thumbnails.
        self.assertEqual(3, worker.start.call_count)
        self.assertEqual(3, worker.stop.call_count)
        replacement = self._session(page, presentation, image_bytes)
        with workspace._photo_diagnostic_lock:
            workspace._session = replacement
        diagnostic_callback('phase=preload-fetch stale-session')
        self.assertNotIn('stale-session', workspace.diagnostics_snapshot())
        workspace.destroy()

    def test_preload_terminal_status_is_scoped_to_selected_gallery(self) -> None:
        """Hide one album's terminal error while another album is selected.

        Returns:
            None: Switching away hides the old gallery's status and returning
                to that gallery restores its retained terminal outcome until
                explicitly dismissed.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'First album')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        self._wait_until(
            lambda: TEST_GALLERY_ID in workspace._galleries,
            'The preload status fixture gallery did not load.',
        )
        progress = photos_workspace.PreloadProgress(
            'error', TEST_GALLERY_ID, TEST_PRELOAD_STATUS_PHOTO_COUNT,
            0, 0, TEST_PRELOAD_STATUS_PHOTO_COUNT, 0, False, 'unavailable',
        )
        workspace._preload_last = progress
        status_row = 1 + max(
            int(group.grid_info()['row']) for group in (
                workspace._heading_title,
                workspace._card_format_controls,
                workspace._heading_controls,
            )
        )
        workspace._preload_strip.grid(
            row=status_row, column=0, columnspan=3, sticky='ew',
        )

        workspace._selected_gallery_id = TEST_GALLERY_ID
        workspace._show_preload_progress(progress)
        self.assertEqual('grid', workspace._preload_strip.winfo_manager())
        self.assertIn(workspace._text('preload_error'), workspace._preload_text.get())

        workspace._selected_gallery_id = TEST_PARENT_GALLERY_ID
        workspace._show_preload_progress(progress)
        self.assertEqual('', workspace._preload_strip.winfo_manager())

        workspace._selected_gallery_id = TEST_GALLERY_ID
        workspace._show_preload_progress(progress)
        self.assertEqual('grid', workspace._preload_strip.winfo_manager())
        self.assertIn(workspace._text('preload_error'), workspace._preload_text.get())

        workspace._cancel_preload()
        self.assertIsNone(workspace._preload_last)
        workspace._selected_gallery_id = TEST_PARENT_GALLERY_ID
        workspace._show_preload_progress(progress)
        workspace._selected_gallery_id = TEST_GALLERY_ID
        workspace._show_preload_progress(progress)
        self.assertEqual('', workspace._preload_strip.winfo_manager())
        workspace.destroy()

    def test_dismissed_preload_results_stay_closed_until_a_new_operation(self) -> None:
        """Keep acknowledged results hidden through polls, remounts and resizes.

        Returns:
            None: Every terminal phase stays closed while cache reconciliation
                continues; an explicit new preload restores visible progress.
        """

        ui_theme.configure_theme(self.root)
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Preload dismissal album')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        self._wait_until(
            lambda: TEST_GALLERY_ID in workspace._galleries,
            'The preload dismissal gallery did not load.',
        )
        gallery = workspace._galleries[TEST_GALLERY_ID]
        worker = mock.Mock(spec=photos_workspace.GalleryPreloadWorker)
        worker.is_alive.return_value = False
        with mock.patch.object(photos_workspace, 'GalleryPreloadWorker', return_value=worker):
            for phase in ('cancelled', 'complete', 'partial', 'error'):
                with self.subTest(phase=phase):
                    workspace._select_gallery(gallery)
                    workspace._open_cache_context()
                    workspace._launch_preload(workspace._context_card, session, gallery)
                    # Explicit launch must show new work even after the prior
                    # operation was acknowledged; no fixture resets its flag.
                    self.assertEqual('grid', workspace._preload_strip.winfo_manager())
                    progress = photos_workspace.PreloadProgress(
                        phase, TEST_GALLERY_ID, TEST_PRELOAD_STATUS_PHOTO_COUNT,
                        1, 1, 0, 0, False, '', cache_state='partial',
                    )
                    worker.snapshot.return_value = progress
                    workspace._poll_cache_work()
                    self.assertIn(workspace._text('preload_' + phase),
                                  workspace._preload_text.get())
                    workspace._cancel_preload()
                    self.assertEqual('', workspace._preload_strip.winfo_manager())
                    workspace._poll_cache_work()
                    self.assertEqual(
                        (1, TEST_PRELOAD_STATUS_PHOTO_COUNT, True),
                        workspace._cache_counts[TEST_GALLERY_ID],
                    )
                    for label, size in (
                        ('desktop', (840, 620)), ('narrow', TEST_VIEWER_MINIMUM_SIZE),
                    ):
                        self.root.geometry(f'{size[0]}x{size[1]}')
                        workspace._poll_cache_work()
                        workspace._selected_gallery_id = TEST_PARENT_GALLERY_ID
                        workspace._show_preload_progress(progress)
                        workspace._select_gallery(gallery)
                        workspace._poll_cache_work()
                        self.root.update()
                        self.assertEqual('', workspace._preload_strip.winfo_manager())
                        self.assertIs(progress, workspace._preload_last)
                        if phase == 'cancelled':
                            bounds = (
                                self.root.winfo_rootx(), self.root.winfo_rooty(),
                                self.root.winfo_rootx() + self.root.winfo_width(),
                                self.root.winfo_rooty() + self.root.winfo_height(),
                            )
                            evidence = WINAPP_DIR.parent / 'cache' / 'test-audit'
                            evidence.mkdir(parents=True, exist_ok=True)
                            with ImageGrab.grab(bbox=bounds) as captured:
                                captured.save(evidence / f'preload-dismissed-{label}.png')
            self.assertEqual(4, worker.start.call_count)
        workspace.destroy()

    def test_inspector_sash_restores_independently_across_recreation(self) -> None:
        """Preserve each Photos sash through inspector hiding and recreation.

        Returns:
            None: A right-sash drag persists its width without changing the
                tree preference, including after a narrow temporary resize.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Inspector album')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        preferences = {'tree': 210, 'info': 250}
        saved_tree: list[int] = []
        saved_info: list[int] = []

        def save_tree(width: int) -> None:
            """Record the tree sash selected by a user.

            Args:
                width (int): Tree sash position in Tk pixels.

            Returns:
                None: Updates the synthetic profile and its write log.
            """
            preferences['tree'] = width
            saved_tree.append(width)

        def save_info(width: int) -> None:
            """Record the independent inspector width selected by a user.

            Args:
                width (int): Inspector width in Tk pixels.

            Returns:
                None: Updates the synthetic profile and its write log.
            """
            preferences['info'] = width
            saved_info.append(width)

        def mount() -> 'photos_workspace.PhotosWorkspace':
            """Mount a workspace reading the same synthetic profile.

            Returns:
                photos_workspace.PhotosWorkspace: Real mapped Photos owner.
            """
            workspace = photos_workspace.PhotosWorkspace(
                self.root, lambda: session, mock.Mock(), lambda: 'en',
                load_tree_width=lambda: preferences['tree'],
                save_tree_width=save_tree,
                load_info_width=lambda: preferences['info'],
                save_info_width=save_info,
            )
            workspace.pack(fill='both', expand=True)
            self._wait_until(
                lambda: workspace.current_tree_width() is not None
                and workspace._selected_gallery_id == TEST_GALLERY_ID
                and workspace._viewer is not None
                and bool(workspace._viewer._gallery_viewer_controller._photos),
                'The tree and selected gallery were not mapped.',
            )
            workspace._show_photo_inspector_pane()
            self._wait_until(
                lambda: workspace._info_width_restored
                and len(workspace.panes.panes()) == 3
                and abs(
                    workspace.panes.winfo_width()
                    - workspace.panes.sashpos(1) - preferences['info']
                ) <= 2,
                'The inspector sash was not restored.',
            )
            return workspace

        workspace = mount()
        initial_tree = workspace.current_tree_width()
        sash_y = max(1, workspace.panes.winfo_height() // 2)
        sash_x = int(workspace.panes.sashpos(1))
        workspace.panes.event_generate('<ButtonPress-1>', x=sash_x, y=sash_y)
        self.assertTrue(workspace._info_width_dragging)
        desired = 280
        sash_x = workspace.panes.winfo_width() - desired
        workspace.panes.sashpos(1, sash_x)
        workspace.panes.event_generate('<ButtonRelease-1>', x=sash_x, y=sash_y)
        self._wait_until(lambda: bool(saved_info), 'Inspector width was not saved.')
        self.assertEqual(initial_tree, workspace.current_tree_width())
        self.assertFalse(saved_tree)
        desired = saved_info[-1]
        workspace._clear_photo_inspector()
        workspace._show_photo_inspector_pane()
        self.root.update()
        self.assertEqual(3, len(workspace.panes.panes()),
                         f'visible={workspace._photo_inspector_visible}')
        self._wait_until(
            lambda: workspace._info_width_restored and abs(
                workspace.panes.winfo_width() - workspace.panes.sashpos(1)
                - desired
            ) <= 2,
            'Inspector width did not return after hiding.',
        )
        self.assertAlmostEqual(
            desired, workspace.panes.winfo_width() - workspace.panes.sashpos(1),
            delta=2,
            msg=f'pane={workspace.panes.winfo_width()} tree={workspace.panes.sashpos(0)} '
                f'right={workspace.panes.sashpos(1)} viewer_req={workspace.viewer_pane.winfo_reqwidth()} '
                f'info={workspace.info_pane.winfo_width()} restored={workspace._info_width_restored}',
        )
        narrow_width, narrow_height = TEST_VIEWER_MINIMUM_SIZE
        self.root.geometry(f'{narrow_width}x{narrow_height}')
        self.root.update()
        self.assertEqual(desired, preferences['info'])
        workspace.destroy()
        self.root.geometry('840x620')
        self.root.update()
        restarted = mount()
        self.assertEqual(initial_tree, restarted.current_tree_width())
        self.assertAlmostEqual(
            desired, restarted.panes.winfo_width() - restarted.panes.sashpos(1),
            delta=2,
        )
        restarted.destroy()

    def test_preload_progress_geometry_and_activity_in_real_heading(self) -> None:
        """Render determinate progress and activity at desktop and narrow sizes.

        Returns:
            None: The complete bar fits its heading and uses the real 118/265
                fraction while waiting remains cancellable and animated.
        """

        ui_theme.configure_theme(self.root)
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Progress album')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        self._wait_until(
            lambda: TEST_GALLERY_ID in workspace._galleries,
            'The progress gallery did not load.',
        )
        workspace._selected_gallery_id = TEST_GALLERY_ID
        # Synthetic physical occupancy exercises the real compact byte/count
        # layout; no profile storage is consulted by this mapped fixture.
        workspace._storage_snapshot = (12, 2 * 1024**2, 4 * 1024**3, True, None)
        workspace._retained_storage_snapshot = (0, 0, 0, 0, 0, True)
        workspace._show_storage_snapshot()
        worker = mock.Mock(spec=photos_workspace.GalleryPreloadWorker)
        worker.is_alive.return_value = True
        workspace._preload_worker = worker
        progress = photos_workspace.PreloadProgress(
            'preloading', TEST_GALLERY_ID, 265, 118, 0, 0, 0, False, '',
            thumbnails_only=True,
            current_hits=72, stored=46, stored_bytes=46 * 1024,
        )
        for label, size in (
            ('desktop', (840, 620)), ('narrow', TEST_VIEWER_MINIMUM_SIZE),
        ):
            with self.subTest(size=label):
                self.root.geometry(f'{size[0]}x{size[1]}')
                workspace._show_preload_progress(progress)
                self.root.update()
                bar = workspace._preload_bar
                heading = workspace._gallery_heading
                self.assertEqual('determinate', str(bar.cget('mode')))
                self.assertEqual(118.0, float(bar.cget('value')))
                self.assertEqual(265.0, float(bar.cget('maximum')))
                self.assertGreater(bar.winfo_width(), 1)
                self.assertGreaterEqual(bar.winfo_rootx(), heading.winfo_rootx())
                self.assertLessEqual(
                    bar.winfo_rootx() + bar.winfo_width(),
                    heading.winfo_rootx() + heading.winfo_width(),
                )
                title = workspace._heading_title
                controls = workspace._heading_controls
                title_bottom = title.winfo_rooty() + title.winfo_height()
                controls_bottom = controls.winfo_rooty() + controls.winfo_height()
                separated = (
                    title.winfo_rootx() + title.winfo_width()
                    <= controls.winfo_rootx()
                    or title_bottom <= controls.winfo_rooty()
                    or controls_bottom <= title.winfo_rooty()
                )
                self.assertTrue(separated, 'Heading controls overlap the title.')
                # The central Windows owner uploads this existing audit tree.
                bounds = (
                    self.root.winfo_rootx(), self.root.winfo_rooty(),
                    self.root.winfo_rootx() + self.root.winfo_width(),
                    self.root.winfo_rooty() + self.root.winfo_height(),
                )
                try:
                    captured = ImageGrab.grab(bbox=bounds)
                except OSError:
                    captured = None
                if captured is not None:
                    evidence = WINAPP_DIR.parent / 'cache' / 'test-audit'
                    evidence.mkdir(parents=True, exist_ok=True)
                    captured.save(evidence / f'photos-progress-{label}.png')
                    captured.close()
        for phase in ('listing', 'yielding', 'checking', 'verifying', 'waiting'):
            with self.subTest(phase=phase):
                current = photos_workspace.PreloadProgress(
                    phase, TEST_GALLERY_ID, 265, 118, 0, 0, 0, False, '',
                    thumbnails_only=True,
                )
                workspace._show_preload_progress(current)
                self.root.update_idletasks()
                self.assertTrue(workspace._preload_activity.winfo_viewable())
                self.assertIsNotNone(workspace._preload_activity_after)
                self.assertEqual('grid', workspace._preload_bar.winfo_manager())
                self.assertEqual('determinate', str(bar.cget('mode')))
                self.assertFalse(workspace._preload_cancel.instate(('disabled',)))
        listing = photos_workspace.PreloadProgress(
            'listing', TEST_GALLERY_ID, None, 0, 0, 0, 0, False, '',
            thumbnails_only=True,
        )
        workspace._show_preload_progress(listing)
        self.assertEqual('indeterminate', str(bar.cget('mode')))
        self.assertIsNotNone(workspace._preload_activity_after)
        workspace._selected_gallery_id = TEST_PARENT_GALLERY_ID
        workspace._show_preload_progress(progress)
        self.assertEqual('grid', workspace._preload_strip.winfo_manager())
        self.assertIn('Progress album', workspace._preload_text.get())
        workspace._cancel_preload()
        worker.stop.assert_called_once()
        workspace.destroy()

    def test_storage_control_opens_anchored_keyboard_context(self) -> None:
        """Count both thumbnail pools and restore focus from storage detail.

        Returns:
            None: Counts and bytes share the same thumbnail scope; unknown
                storage stays distinct and the anchored quota card restores focus.
        """
        ui_theme.configure_theme(self.root)
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Storage album')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
        )
        self.assertEqual((None, None, None, False, None), workspace._storage_snapshot)
        self.assertNotIn('0 /', workspace._storage_text.get())
        self.assertIsNotNone(workspace._storage_activity_after)
        self.assertTrue(workspace._storage_hint.get())
        workspace._storage_snapshot = (12, 2 * 1024**2, 4 * 1024**3, True, None)
        workspace._retained_storage_snapshot = (0, 0, 0, 0, 0, True)
        workspace._show_storage_snapshot()
        self.assertIn('12', workspace._storage_text.get())
        self.assertIn('2.0 MiB', workspace._storage_text.get())
        self.assertNotIn('4.0 GiB', workspace._storage_text.get())
        self.assertIsNone(workspace._storage_activity_after)
        # The reported album has 29 retained thumbnails. Other retained media
        # must not inflate either the thumbnail count or its matching byte total.
        workspace._retained_storage_snapshot = (
            1, 29, 5 * 1024**2, 1, 128 * 1024**2, True,
        )
        workspace._show_storage_snapshot()
        self.assertIn('41 · 7.0 MiB', workspace._storage_text.get())
        workspace._storage_snapshot = (None, None, None, False, 'unavailable')
        workspace._show_storage_snapshot()
        self.assertEqual('—', workspace._storage_text.get())
        self.assertIn(
            workspace._text('storage_unavailable'), workspace._storage_hint.get(),
        )
        for language in ('en', 'cs', 'de', 'sv'):
            workspace._language = lambda: language
            workspace._show_storage_snapshot()
            self.assertTrue(workspace._storage_text.get())
            self.assertNotEqual(
                'photos_workspace_storage_unavailable', workspace._storage_text.get(),
            )
        workspace._language = lambda: 'en'
        workspace.pack(fill='both', expand=True)
        self._wait_until(
            lambda: workspace._storage_button.winfo_viewable(),
            'The storage control was not visible.',
        )
        workspace._storage_button.focus_force()
        workspace._storage_button.invoke()
        self.root.update()
        card = workspace._context_card
        self.assertIsNotNone(card)
        self.assertTrue(card.winfo_viewable())
        self.assertGreaterEqual(card.winfo_rootx(), workspace.winfo_rootx())
        self.assertLessEqual(
            card.winfo_rootx() + card.winfo_width(),
            workspace.winfo_rootx() + workspace.winfo_width(),
        )
        self.assertLessEqual(
            card.winfo_rooty(),
            workspace._storage_button.winfo_rooty()
            + workspace._storage_button.winfo_height(),
        )
        widgets = list(card.winfo_children())
        for widget in widgets:
            widgets.extend(widget.winfo_children())
        self.assertTrue(any(widget.winfo_class() == 'TCombobox' for widget in widgets))
        self.assertFalse(any(
            widget.winfo_class() == 'TButton'
            and 'save' in str(widget.cget('text')).lower()
            for widget in widgets
        ))
        card.event_generate('<Escape>')
        self.root.update()
        self.assertIsNone(workspace._context_card)
        self.assertIs(workspace._storage_button, self.root.focus_get())
        workspace.destroy()

    def test_storage_quota_applies_on_selection_without_save(self) -> None:
        """Persist quota and keep the real header's storage card in view.

        Returns:
            None: One background write owns the disabled selector, success
                updates the observed limit, and failure restores its value.
                Desktop, narrow and resized header layouts retain the card,
                all mapped descendants and final values within the client.
        """

        ui_theme.configure_theme(self.root)
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Storage graphics')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        # Mirror the app's padded outer surface and header/status nesting;
        # the storage trigger is a sibling of the Photos workspace, not its
        # child, and the watcher indicator occupies the rest of the status.
        outer = ttk.Frame(self.root, padding=(16, 12, 16, 10))
        outer.pack(fill='both', expand=True)
        outer.columnconfigure(1, weight=1)
        outer.rowconfigure(2, weight=1)
        header = ttk.Frame(outer)
        header.grid(row=0, column=0, columnspan=2, sticky='ew', pady=(0, 10))
        header.columnconfigure(1, weight=1)
        ttk.Label(header, text='PHP Gallery').grid(row=0, column=0, sticky='w')
        ttk.Label(header, text='Your gallery on autopilot').grid(
            row=1, column=0, sticky='w',
        )
        status_header = ttk.Frame(header)
        status_header.grid(row=0, column=2, rowspan=2, sticky='e')
        storage_header = ttk.Frame(status_header)
        storage_header.pack(side='left', padx=(0, 10))
        tk.Canvas(
            status_header, width=16, height=16, highlightthickness=0, bd=0,
        ).pack(side='left', padx=(0, 6))
        watcher = ui_theme.GlassCard(header, padding=(8, 4))
        watcher.grid(
            row=0, column=1, rowspan=2, sticky='e', padx=(12, 10),
        )
        for text, padding in (
            ('●', (0, 8)), ('▧', (0, 3)), ('0', (0, 8)),
            ('◷', (0, 3)), ('–', (0, 0)),
        ):
            ttk.Label(watcher, text=text, style='Card.TLabel').pack(
                side='left', padx=padding,
            )
        ttk.Button(header, text='↻', style='Photos.Icon.TButton').grid(
            row=0, column=3, rowspan=2, padx=4,
        )
        ttk.Button(header, text='?', style='Photos.Icon.TButton').grid(
            row=0, column=5, rowspan=2, padx=(4, 0),
        )
        link_bar = ttk.Frame(header)
        link_bar.grid(row=2, column=0, columnspan=6, sticky='w', pady=(5, 0))
        ttk.Label(link_bar, text='Linked: https://example.test').grid(
            row=0, column=0, sticky='w', padx=(0, 8),
        )
        ttk.Button(link_bar, text='⋯', style='Photos.Icon.TButton').grid(
            row=0, column=1, sticky='w', padx=(2, 0),
        )
        workspace = photos_workspace.PhotosWorkspace(
            outer, lambda: session, mock.Mock(), lambda: 'cs',
            storage_parent=storage_header, storage_surface=outer,
        )
        workspace.grid(row=2, column=0, columnspan=2, sticky='nsew')
        self.addCleanup(workspace.destroy)
        self._wait_until(
            lambda: workspace._session is session,
            'Storage fixture did not mount its isolated session.',
        )
        # Four, eight and sixteen are real adjacent quota menu choices.
        workspace._storage_snapshot = (650, 6 * 1024**2, 4 * 1024**3, True, None)
        workspace._retained_storage_snapshot = (4, 579, 3 * 1024**2, 62, 160 * 1024**2, True)
        workspace._storage_ram = 292 * 1024**2
        workspace._show_storage_snapshot()
        entered = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)

        def persist(value: int) -> bool:
            """Hold the first save to prove the selector cannot race writes.

            Args:
                value (int): Selected quota in GiB from the real combobox.

            Returns:
                bool: True for eight GiB; False models a failed later write.
            """
            entered.set()
            if not release.wait(TEST_GUI_WAIT_SECONDS):
                raise TimeoutError('The controlled quota write was not released.')
            return value == 8

        with mock.patch.object(
            photos_workspace, 'set_thumbnail_cache_limit_gib', side_effect=persist,
        ) as save:
            workspace._storage_button.invoke()
            self.root.update()
            card = workspace._context_card
            self.assertIsNotNone(card)
            widgets = list(card.winfo_children())
            for widget in widgets:
                widgets.extend(widget.winfo_children())
            selector = next(widget for widget in widgets if widget.winfo_class() == 'TCombobox')
            self.assertFalse(any(
                widget.winfo_class() == 'TButton'
                and 'save' in str(widget.cget('text')).lower()
                for widget in widgets
            ))
            selector.set('8')
            selector.event_generate('<<ComboboxSelected>>')
            self._wait_until(entered.is_set, 'Quota selection did not start persistence.')
            self.assertEqual('disabled', str(selector.cget('state')))
            save.assert_called_once_with(8)
            release.set()
            self._wait_until(
                lambda: str(selector.cget('state')) == 'readonly'
                and not workspace._context_busy,
                'Quota persistence did not restore the live selector.',
            )
            self.assertEqual(8 * 1024**3, workspace._storage_snapshot[2])
            selector.event_generate('<<ComboboxSelected>>')
            self.root.update()
            save.assert_called_once_with(8)
            selector.set('16')
            selector.event_generate('<<ComboboxSelected>>')
            self._wait_until(
                lambda: str(selector.cget('state')) == 'readonly'
                and save.call_count == 2 and not workspace._context_busy,
                'A failed quota write did not reconcile in place.',
            )
            self.assertEqual('8', selector.get())
            self.assertEqual(8 * 1024**3, workspace._storage_snapshot[2])
            self.assertIs(card, workspace._context_card)
        workspace._dismiss_context(restore=False)
        evidence = WINAPP_DIR.parent / 'cache' / 'test-audit'
        evidence.mkdir(parents=True, exist_ok=True)
        # Large localized numbers exercise both populated value rows, including
        # their final values, after the panel has negotiated its real width.
        workspace._storage_snapshot = (
            123456789, 1234567890123, 4 * 1024**3, True, None,
        )
        workspace._retained_storage_snapshot = (
            123456, 987654321, 1234567890123, 87654321, 987654321012, True,
        )
        workspace._show_storage_snapshot()
        workspace._storage_button.invoke()
        for index, (width, height) in enumerate((
            *TEST_TREE_REFRESH_VIEWPORTS, TEST_TREE_REFRESH_VIEWPORTS[0],
        )):
            # Keep the same open card while resizing, as in the real window.
            visible_width = min(
                width,
                self.root.winfo_screenwidth() - TEST_TREE_REFRESH_SCREEN_MARGIN,
            )
            visible_height = min(
                height,
                self.root.winfo_screenheight() - TEST_TREE_REFRESH_SCREEN_MARGIN,
            )
            self.root.geometry(f'{visible_width}x{visible_height}+0+0')
            self.root.update()
            card = workspace._context_card
            self.assertIsNotNone(card)
            self.assertGreaterEqual(card.winfo_rootx(), self.root.winfo_rootx())
            right = self.root.winfo_rootx() + self.root.winfo_width()
            self.assertLessEqual(
                card.winfo_rootx() + card.winfo_width(), right,
            )
            self.assertEqual(
                card.winfo_rootx() + card.winfo_width(),
                min(right, outer.winfo_rootx() + outer.winfo_width()),
            )
            descendants = list(card.winfo_children())
            for widget in descendants:
                descendants.extend(widget.winfo_children())
                if widget.winfo_ismapped():
                    self.assertLessEqual(
                        widget.winfo_rootx() + widget.winfo_width(), right,
                    )
                    self.assertLessEqual(
                        widget.winfo_rootx() + widget.winfo_width(),
                        card.winfo_rootx() + card.winfo_width(),
                    )
            labels = [str(widget.cget('text')) for widget in descendants
                      if widget.winfo_class() == 'TLabel']
            self.assertTrue(any('87,654,321' in value for value in labels))
            self.assertTrue(any(
                workspace._storage_bytes(987654321012) in value
                for value in labels
            ))
            self.assertTrue(any('GiB' in value for value in labels))
            bounds = (
                self.root.winfo_rootx(), self.root.winfo_rooty(),
                self.root.winfo_rootx() + self.root.winfo_width(),
                self.root.winfo_rooty() + self.root.winfo_height(),
            )
            with ImageGrab.grab(bbox=bounds) as captured:
                captured.save(
                    evidence / f'photos-storage-compact-{width}-{index}.png',
                )
            if index == 0:
                value_label = next(
                    widget for widget in descendants
                    if widget.winfo_class() == 'TLabel'
                    and '87,654,321' in str(widget.cget('text'))
                )
                original_value = str(value_label.cget('text'))
                value_label.configure(text=original_value + ' · 999')
                self.root.update()
            elif index == 1:
                value_label.configure(text=original_value)
                self.root.update()
        # A mapped hosted Windows window need not own keyboard focus. Verify
        # real key delivery before testing Escape and its focus-return action.
        card.focus_force()
        self.root.update()
        self.assertIs(card, self.root.focus_get())
        card.event_generate('<Escape>')
        self.root.update()
        self.assertIsNone(workspace._context_card)
        self.assertIs(workspace._storage_button, self.root.focus_get())
        workspace._storage_button.invoke()
        self.root.update()
        header.event_generate('<ButtonPress-1>', x=1, y=1)
        self.root.update()
        self.assertIsNone(workspace._context_card)

    def test_storage_refresh_coalesces_and_preserves_ready_retained_snapshot(self) -> None:
        """Refresh live occupancy without replacing known retained totals early.

        Returns:
            None: Cache events coalesce onto the bounded observer, and an
                ordinary-only publication preserves the last ready kept pool.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.gallery_page.return_value = LinkedGalleryPage((
            LinkedGallery(TEST_GALLERY_ID, None, 'Live storage'),
        ), None)
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
        )
        retained_ready = (1, 29, 5 * 1024**2, 1, 128 * 1024**2, True)
        workspace._retained_storage_snapshot = retained_ready
        workspace._storage_ram = 64 * 1024**2
        worker = mock.Mock()
        worker.is_alive.return_value = True
        workspace._storage_worker = worker
        workspace._storage_after = 'existing-storage-poll'

        with (mock.patch.object(workspace, 'after_cancel') as cancel_timer,
              mock.patch.object(
                  workspace, 'after', return_value='short-storage-poll',
              ) as schedule_timer):
            workspace._request_storage_refresh()
            workspace._request_storage_refresh()
        cancel_timer.assert_called_once_with('existing-storage-poll')
        schedule_timer.assert_called_once_with(
            photos_workspace.PHOTOS_STORAGE_ACTIVE_POLL_MS,
            workspace._poll_storage,
        )

        ordinary = (41, 7 * 1024**2, 4 * 1024**3, True, None)
        workspace._storage_results.put((
            ordinary, (None, None, None, None, None, False), None, False,
        ))
        self._storage_poll_callback(workspace)
        self.assertEqual(ordinary, workspace._storage_snapshot)
        self.assertEqual(retained_ready, workspace._retained_storage_snapshot)
        self.assertEqual(64 * 1024**2, workspace._storage_ram)
        self.assertTrue(workspace._storage_refresh_requested)
        self.assertTrue(workspace._storage_refresh_waiting)
        worker.is_alive.return_value = False
        follow_up = mock.Mock()
        with mock.patch.object(
            photos_workspace.threading, 'Thread', return_value=follow_up,
        ):
            self._storage_poll_callback(workspace)
        follow_up.start.assert_called_once_with()
        self.assertFalse(workspace._storage_refresh_requested)
        self.assertTrue(workspace._storage_worker_requested)
        workspace.destroy()

    def test_storage_activity_does_not_start_during_failed_construction(self) -> None:
        """Keep a failed Photos constructor from leaving an owned Tk timer.

        Returns:
            None: A later widget failure is propagated before animation starts.
        """
        with mock.patch.object(
            photos_workspace, 'GalleryFolderShelf',
            side_effect=RuntimeError('Synthetic widget construction failure'),
        ), mock.patch.object(
            photos_workspace.PhotosWorkspace, '_animate_storage_activity',
        ) as animate:
            with self.assertRaisesRegex(RuntimeError, 'Synthetic widget'):
                photos_workspace.PhotosWorkspace(
                    self.root, lambda: None, mock.Mock(), lambda: 'en',
                )
            animate.assert_not_called()

    def test_photos_information_is_explicit_grouped_and_scope_filtered(self) -> None:
        """Fetch rich information only on demand and suppress public GPS rows.

        Returns:
            None: Gallery and photo cards show rich fields with legacy fallbacks.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Metadata album')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        session.media_gallery_details.return_value = {
            'gallery_id': TEST_GALLERY_ID,
            'title': 'Metadata album',
            'photo_count': 2,
            'file_count': 2,
            'size': 476100,
            'description': 'Synthetic gallery description',
            'visibility': 'public',
            'nsfw': False,
            'listed': True,
            'jpeg_quality': 82,
            'webp_quality': 82,
        }
        session.media_photo_info.return_value = {
            'gallery_id': TEST_GALLERY_ID,
            'image_id': TEST_FIRST_IMAGE_ID,
            'title': 'Synthetic camera photo',
            'filename': 'sample-camera.jpg',
            'description': 'Synthetic description',
            'mime_type': 'image/jpeg',
            'width': 4032,
            'height': 3024,
            'display_width': 2400,
            'display_height': 1800,
            'size': 476100,
            'created_at': '2026-11-06 10:00 UTC',
            'modified_at': '2026-11-06 11:00 UTC',
            'exif_taken_at': '2026-11-05 09:30 UTC',
            'exif_camera_make': 'Fixture Optics',
            'exif_camera_model': 'Fixture Camera',
            'exif_lens_model': 'Fixture 35mm',
            'exif_focal_length': '35 mm',
            'exif_aperture': 'f/2.8',
            'exif_exposure_time': '1/125 s',
            'exif_iso': 200,
            'gps_lat': 48.1,
            'gps_lng': 17.1,
            'gps_altitude': 120.0,
            'visibility': 'public',
            'nsfw': False,
            'jpeg_quality': 82,
            'webp_quality': 82,
        }
        session.can_read_admin_media.return_value = True
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
            format_bytes=lambda value: f'{value} bytes',
        )
        workspace.pack(fill='both', expand=True)

        def labels() -> list[str]:
            """Collect rendered label text from the active card subtree.

            Returns:
                list[str]: All mounted text labels in the contextual surface.
            """
            card = workspace._context_card
            if card is None:
                return []
            pending: list[tk.Misc] = [card]
            found: list[str] = []
            while pending:
                widget = pending.pop()
                pending.extend(widget.winfo_children())
                if widget.winfo_class() == 'TLabel':
                    found.append(str(widget.cget('text')))
            return found

        tree = workspace.tree
        self._wait_until(
            lambda: tree.exists(str(TEST_GALLERY_ID))
            and bool(tree.bbox(str(TEST_GALLERY_ID))),
            'The metadata gallery row did not mount in the tree.',
        )
        box = tree.bbox(str(TEST_GALLERY_ID))
        self.assertTrue(box)
        event = mock.Mock()
        event.widget = tree
        event.x_root = tree.winfo_rootx() + box[0] + 12
        event.y_root = tree.winfo_rooty() + box[1] + 4
        workspace._gallery_context(TEST_GALLERY_ID, event)
        self.assertEqual(0, session.media_gallery_details.call_count)
        information = next(
            widget for widget in workspace._context_card.winfo_children()
            if widget.winfo_class() == 'TButton'
            and 'information' in widget.cget('text').lower()
        )
        information.invoke()
        self._wait_until(
            lambda: any('Thumbnail settings' in text for text in labels()),
            'Explicit gallery information did not render its grouped fields.',
        )
        self.assertTrue(any('Direct photos: 2' in text for text in labels()))
        self.assertTrue(any('JPEG 82' in text and 'WebP 82' in text
                            for text in labels()))
        self.assertEqual(0, session.media_gallery_info.call_count)

        workspace._dismiss_context(restore=False)
        workspace._viewer_context(
            TEST_GALLERY_ID, page.photos[0], event,
        )
        self.assertEqual(0, session.media_photo_info.call_count)
        information = next(
            widget for widget in workspace._context_card.winfo_children()
            if widget.winfo_class() == 'TButton'
            and 'information' in widget.cget('text').lower()
        )
        information.invoke()
        self._wait_until(
            lambda: any('Fixture Camera' in text for text in labels()),
            'Explicit photo information did not render EXIF details.',
        )
        self.assertTrue(any('Latitude: 48.1' in text for text in labels()))
        session.can_read_admin_media.return_value = False
        workspace._dismiss_context(restore=False)
        workspace._viewer_context(
            TEST_GALLERY_ID, page.photos[0], event,
        )
        information = next(
            widget for widget in workspace._context_card.winfo_children()
            if widget.winfo_class() == 'TButton'
            and 'information' in widget.cget('text').lower()
        )
        information.invoke()
        self._wait_until(
            lambda: any('Fixture Camera' in text for text in labels()),
            'Photo information did not refresh for the public-only scope.',
        )
        self.assertFalse(any('Latitude' in text for text in labels()))
        self.assertEqual(1, session.media_photo_info.call_count)
        workspace._dismiss_context(restore=False)
        workspace._inspect_photo(page.photos[0])
        self.assertTrue(workspace._photo_inspector_visible)
        self.assertIn(
            'Camera model: Fixture Camera',
            [
                str(child.cget('text'))
                for child in workspace._photo_inspector_content.winfo_children()
                if child.winfo_class() == 'TLabel'
            ],
        )
        self.assertFalse(any(
            'Latitude' in str(child.cget('text'))
            for child in workspace._photo_inspector_content.winfo_children()
            if child.winfo_class() == 'TLabel'
        ))
        self.assertEqual(1, session.media_photo_info.call_count)
        workspace.destroy()

    def test_photo_info_cache_is_fresh_bounded_and_session_scoped(self) -> None:
        """Bound metadata reuse by session, entry count and freshness time.

        Returns:
            None: Cached values are copied, old entries evict and expired rows miss.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        provider: list[LinkingSession | None] = [None]
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: provider[0], mock.Mock(), lambda: 'en',
        )
        provider[0] = session
        workspace._session = session
        workspace._session_provider = lambda: session
        workspace._selected_gallery_id = TEST_GALLERY_ID
        for image_id in range(
            1, photos_workspace.PHOTOS_INFO_CACHE_ENTRY_LIMIT + 2,
        ):
            workspace._remember_photo_info(
                session, TEST_GALLERY_ID, image_id,
                {'title': f'Photo {image_id}'},
            )
        self.assertEqual(
            photos_workspace.PHOTOS_INFO_CACHE_ENTRY_LIMIT,
            len(workspace._photo_info_cache),
        )
        first_key = (id(session), TEST_GALLERY_ID, 1)
        self.assertNotIn(first_key, workspace._photo_info_cache)
        latest_id = photos_workspace.PHOTOS_INFO_CACHE_ENTRY_LIMIT + 1
        latest = workspace._cached_photo_info(
            session, TEST_GALLERY_ID, latest_id,
        )
        self.assertEqual(f'Photo {latest_id}', latest['title'])
        latest['title'] = 'Mutated caller copy'
        self.assertEqual(
            f'Photo {latest_id}',
            workspace._photo_info_cache[
                (id(session), TEST_GALLERY_ID, latest_id)
            ][1]['title'],
        )
        expired_key = (id(session), TEST_GALLERY_ID, 99999)
        workspace._photo_info_cache[expired_key] = (
            time.monotonic() - photos_workspace.PHOTOS_INFO_CACHE_FRESH_SECONDS,
            {'title': 'Expired'},
        )
        self.assertIsNone(workspace._cached_photo_info(
            session, TEST_GALLERY_ID, 99999,
        ))
        self.assertNotIn(expired_key, workspace._photo_info_cache)
        replacement_session = self._session(page, presentation, image_bytes)
        self.assertIsNone(workspace._cached_photo_info(
            replacement_session, TEST_GALLERY_ID, latest_id,
        ))
        workspace.destroy()

    def test_late_inspector_result_cannot_replace_new_photo_context(self) -> None:
        """Fence an earlier metadata response while the latest choice waits.

        Returns:
            None: One reader slot serializes both reads and only the new photo
                populates visible details and the current-session cache.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        first_started = threading.Event()
        release_first = threading.Event()
        self.addCleanup(release_first.set)

        def read_info(
            gallery_id: int, image_id: int,
            cancelled: threading.Event | None = None,
        ) -> dict[str, str]:
            """Hold the first metadata response after cancellation.

            Args:
                gallery_id (int): Current physical gallery ID.
                image_id (int): Exact metadata target photo.
                cancelled (threading.Event | None): Request cancellation signal.

            Returns:
                dict[str, str]: Synthetic safe metadata values for one target.
            """
            self.assertEqual(TEST_GALLERY_ID, gallery_id)
            if image_id == TEST_FIRST_IMAGE_ID:
                first_started.set()
                if not release_first.wait(TEST_GUI_WAIT_SECONDS):
                    raise TimeoutError('The fixture did not release its first read.')
                return {'title': 'Stale first details'}
            return {'title': 'Newest second details'}

        session.media_photo_info.side_effect = read_info
        provider: list[LinkingSession | None] = [None]
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: provider[0], mock.Mock(), lambda: 'en',
        )
        provider[0] = session
        workspace._session = session
        workspace._session_provider = lambda: session
        workspace._selected_gallery_id = TEST_GALLERY_ID
        workspace._inspect_photo(page.photos[0])
        self.assertTrue(first_started.wait(TEST_GUI_WAIT_SECONDS))
        workspace._inspect_photo(page.photos[1])
        release_first.set()
        self._wait_until(
            lambda: workspace._inspector_worker is None
            and (id(session), TEST_GALLERY_ID, TEST_SECOND_IMAGE_ID)
                in workspace._photo_info_cache,
            'The queued latest photo metadata did not finish after the first read.',
        )
        rendered = [
            str(child.cget('text'))
            for child in workspace._photo_inspector_content.winfo_children()
            if child.winfo_class() == 'TLabel'
        ]
        self.assertTrue(any('Newest second details' in text for text in rendered))
        self.assertFalse(any('Stale first details' in text for text in rendered))
        self.assertNotIn(
            (id(session), TEST_GALLERY_ID, TEST_FIRST_IMAGE_ID),
            workspace._photo_info_cache,
        )
        workspace.destroy()

    def test_context_dismissal_keeps_outside_click_actions(self) -> None:
        """Dismiss context cards while preserving clicks and focus handling.

        Returns:
            None: Outside focus closes the card; left and right presses survive.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Context album')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        clicks: list[str] = []
        outside = ttk.Button(self.root, text='Outside',
                             command=lambda: clicks.append('left'))
        outside.place(relx=1.0, rely=1.0, anchor='se')
        tree = workspace.tree
        self._wait_until(
            lambda: tree.exists(str(TEST_GALLERY_ID))
            and bool(tree.bbox(str(TEST_GALLERY_ID))),
            'The context gallery row did not mount in the tree.',
        )
        box = tree.bbox(str(TEST_GALLERY_ID))
        self.assertTrue(box)
        event = mock.Mock()
        event.widget = tree
        event.x_root = tree.winfo_rootx() + box[0] + 12
        event.y_root = tree.winfo_rooty() + box[1] + 4
        workspace._gallery_context(TEST_GALLERY_ID, event)
        outside.event_generate('<ButtonPress-1>')
        outside.event_generate('<ButtonRelease-1>')
        self.root.update()
        self.assertIsNone(workspace._context_card)
        self.assertEqual(['left'], clicks)

        right_clicks: list[bool] = []
        outside.bind(
            '<ButtonPress-3>', lambda _event: right_clicks.append(True), add='+',
        )
        workspace._gallery_context(TEST_GALLERY_ID, event)
        outside.event_generate('<ButtonPress-3>')
        self.root.update()
        self.assertIsNone(workspace._context_card)
        self.assertEqual([True], right_clicks)

        workspace._gallery_context(TEST_GALLERY_ID, event)
        card = workspace._context_card
        self.assertIsNotNone(card)
        # Hosted desktops can map a card without granting foreground focus.
        with mock.patch.object(workspace, 'focus_get', return_value=card) as focus:
            self.root.update_idletasks()
            self.assertTrue(card.winfo_ismapped())
            focus.return_value = outside
            card.event_generate('<FocusOut>')
            self.root.update()
        self.assertIsNone(workspace._context_card)
        outside.destroy()
        workspace.destroy()

    def test_music_chooser_is_inline(self) -> None:
        """Show track choices in the Photos pane without a secondary window.

        Returns:
            None: The track chooser opens, Escape closes it, and the viewer stays.
        """
        page, presentation, image_bytes = self._gallery_data()
        track = LinkedTrack(
            'b' * 32,
            'Panel-only fixture',
            'audio/wav',
            TEST_PANEL_TRACK_BYTES,
            '0' * 64,
        )
        session = self._session(page, presentation, image_bytes)
        session.media_presentation.return_value = LinkedPresentation(
            enabled=True,
            autoplay=False,
            interval_seconds=TEST_PRESENTATION_INTERVAL_SECONDS,
            transition='medium',
            music=True,
            tracks=(track,),
        )
        viewer = self._open_viewer(
            session,
            LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Music panel'),
        )
        self._wait_until(
            lambda: viewer._presentation is not None,
            'The authorized playlist was not delivered to the music chooser.',
        )
        viewer.music_button.invoke()
        self.assertIsNotNone(viewer._music_panel)
        self.assertTrue(viewer._music_panel.winfo_manager())
        self.assertIsNotNone(viewer.track_menu)
        self.assertLessEqual(
            viewer._music_panel.winfo_height(), viewer.frame.winfo_height(),
        )
        self.assertEqual(
            '▶', viewer.panel_track_button.cget('text'),
        )
        session.media_track.assert_not_called()

        self._assert_no_browsing_toplevels()
        self.root.focus_force()
        self.root.event_generate('<Escape>')
        self.assertIsNone(viewer._music_panel)
        self.assertFalse(viewer._closed)
        session.can_read_presentation.return_value = False
        viewer.music_button.invoke()
        self.assertIsNone(viewer.panel_track_button)
        self.assertIn(
            'Link this site again and approve presentation access.',
            [widget.cget('text') for widget in viewer._music_panel.winfo_children()],
        )
        session.media_track.assert_not_called()

    def test_first_revision_reconciles_an_upload_during_initial_load(self) -> None:
        """Cover an upload arriving before the first revision baseline.

        Returns:
            None: The first revision observation refreshes the initial page.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.can_read_admin_media.return_value = True
        session.media_revision.return_value = 'a' * 64
        extra = LinkedPhoto(TEST_NEXT_PAGE_IMAGE_ID, 'New photo')
        changed = LinkedMediaPage(
            page.gallery_id, page.parent_id, page.photos + (extra,), None,
        )
        image_bytes[extra.image_id] = self._png((90, 80, 70))
        session.media_page.side_effect = [page, changed]
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album'),
        )
        self._wait_until(
            lambda: len(viewer._photos) == len(changed.photos),
            'The first revision did not reconcile the newly uploaded photo.',
        )
        session.media_revision.assert_called()
        viewer.close()

    def test_initial_revision_budget_counts_thumbnail_reads_not_batches(self) -> None:
        """Keep the initial revision probe within its existing read budget.

        Returns:
            None: A larger admitted grid cannot multiply the initial thumbnail
                priority limit by the number of items in a worker batch.
        """
        page, presentation, image_bytes = self._gallery_data()
        photos = tuple(
            LinkedPhoto(TEST_FIRST_IMAGE_ID + index, f'Revision {index}')
            for index in range(TEST_SCROLL_PHOTO_COUNT)
        )
        for index, photo in enumerate(photos):
            image_bytes[photo.image_id] = self._png((index, 90, 100))
        page = LinkedMediaPage(page.gallery_id, page.parent_id, photos, None)
        session = self._session(page, presentation, image_bytes)
        session.can_read_admin_media.return_value = True
        reads_at_probe: list[int] = []

        def observe_revision(
            _gallery_id: int, cancelled: threading.Event | None = None,
        ) -> str:
            """Capture completed media admissions when revision gets its turn.

            Args:
                _gallery_id (int): Physical gallery requested by the viewer.
                cancelled (threading.Event | None): Current viewer cancellation.

            Returns:
                str: Stable synthetic server revision for the existing page.
            """
            reads_at_probe.append(session.media_photo.call_count)
            return 'a' * 64

        session.media_revision.side_effect = observe_revision
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Revision budget'),
        )
        self._wait_until(
            lambda: bool(reads_at_probe),
            'The initial revision never received its bounded turn.',
        )
        self.assertLessEqual(
            reads_at_probe[0],
            native_gallery_viewer.MEDIA_INITIAL_THUMBNAIL_PRIORITY_LIMIT,
        )
        viewer.close()

    def test_lightbox_prefetch_precedes_grid_thumbnail_work(self) -> None:
        """Keep adjacent detail work ahead of grid reads, after foreground/audio.

        Returns:
            None: Foreground selection and audio keep their lead; a visible
                lightbox's adjacent detail request then runs before thumbnails.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Prefetch priority'),
        )
        self._wait_until(
            lambda: viewer._worker is None and not viewer._presentation_pending,
            'Initial viewer work did not settle before priority checks.',
        )
        viewer._lightbox = mock.Mock(visible=True)
        viewer._pending_prefetch_ids = [page.photos[1].image_id]
        viewer._thumbnail_pending = [0]
        calls: list[str] = []

        def record_foreground(index: int) -> bool:
            """Record the selected detail before background work.

            Args:
                index (int): Current selected photo index.

            Returns:
                bool: The foreground detail request was accepted.
            """
            calls.append(f'foreground:{index}')
            return True

        def record_prefetch() -> bool:
            """Record the next adjacent lightbox detail request.

            Returns:
                bool: The prefetch request was accepted.
            """
            calls.append('prefetch')
            return True

        def record_thumbnail(_limit: int = 0) -> None:
            """Record the grid thumbnail batch request.

            Args:
                _limit (int): Optional capped batch size.

            Returns:
                None: The fixture does not start background work.
            """
            calls.append('thumbnail')

        with (mock.patch.object(viewer, '_load_detail', side_effect=record_foreground),
              mock.patch.object(viewer, '_load_next_prefetch', side_effect=record_prefetch),
              mock.patch.object(viewer, '_load_next_thumbnail', side_effect=record_thumbnail),
              mock.patch.object(viewer, '_dispatch_audio_prefetch', return_value=False)):
            viewer._pending_detail_index = 0
            viewer._dispatch_pending_work()
            self.assertEqual(['foreground:0'], calls)
            viewer._dispatch_pending_work()
            self.assertEqual(['foreground:0', 'prefetch'], calls)

        calls.clear()
        with (mock.patch.object(viewer, '_load_next_prefetch', side_effect=record_prefetch),
              mock.patch.object(viewer, '_load_next_thumbnail', side_effect=record_thumbnail),
              mock.patch.object(
                  viewer, '_dispatch_audio_prefetch',
                  side_effect=lambda: calls.append('audio') or True,
              )):
            viewer._dispatch_pending_work()
        self.assertEqual(['audio'], calls)
        viewer.close()

    def test_foreground_detail_precedes_queued_refresh(self) -> None:
        """Dispatch the selected photo before stale background refresh work.

        Returns:
            None: The current selection starts before a queued catalogue refresh.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Foreground priority'),
        )
        self._wait_until(
            lambda: viewer._worker is None and not viewer._presentation_pending,
            'Initial viewer work did not settle before priority checks.',
        )
        calls: list[str] = []
        viewer._pending_detail_index = 1
        viewer._refresh_pending = True
        with (mock.patch.object(
                  viewer, '_load_detail',
                  side_effect=lambda index: calls.append(f'detail:{index}') or True,
              ),
              mock.patch.object(
                  viewer, '_start_refresh',
                  side_effect=lambda: calls.append('refresh'),
              )):
            viewer._dispatch_pending_work()
        self.assertEqual(['detail:1'], calls)
        viewer.close()

    def test_selected_detail_cancels_prefetch_with_fresh_operation_event(self) -> None:
        """Never reuse a cancelled prefetch event for the selected photo.

        Returns:
            None: Superseded work stays cancelled while the foreground gets a
                distinct live cancellation signal.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Cancel prefetch'),
        )
        self._wait_until(
            lambda: viewer._worker is None and not viewer._presentation_pending,
            'Initial viewer work did not settle before cancellation checks.',
        )
        old_cancelled = threading.Event()
        viewer._worker = mock.Mock()
        viewer._worker_kind = 'prefetch'
        viewer._active_media_cancelled = old_cancelled
        viewer._active_media_photo_id = page.photos[1].image_id

        self.assertTrue(viewer._load_detail(0))
        self.assertTrue(old_cancelled.is_set())
        self.assertEqual(0, viewer._pending_detail_index)

        viewer._worker = None
        viewer._worker_kind = None
        started_events: list[threading.Event | None] = []

        def capture_launch(
            _kind: str,
            _operation: Callable[
                [], tuple[
                    int, int, 'Image.Image', 'Image.Image | None', bytes | None,
                ],
            ],
            *,
            error_payload: tuple[int, int, None, None, None] | None = None,
            operation_cancelled: threading.Event | None = None,
        ) -> bool:
            """Capture the cancellation signal for the foreground launch.

            Args:
                _kind (str): Worker category.
                _operation (Callable[[], tuple[int, int, Image.Image, Image.Image | None, bytes | None]]):
                    Selected detail callback not executed by this fixture.
                error_payload (tuple[int, int, None, None, None] | None):
                    Optional unused failure identity.
                operation_cancelled (threading.Event | None): Per-operation stop signal.

            Returns:
                bool: The mocked worker accepts the request.
            """
            started_events.append(operation_cancelled)
            return True

        with mock.patch.object(viewer, '_launch', side_effect=capture_launch):
            viewer._dispatch_pending_work()
        self.assertEqual(1, len(started_events))
        self.assertIsNotNone(started_events[0])
        self.assertIsNot(started_events[0], old_cancelled)
        self.assertFalse(started_events[0].is_set())
        self.assertIs(viewer._active_media_cancelled, started_events[0])

        active_detail = started_events[0]
        viewer._worker = mock.Mock()
        viewer._worker_kind = 'detail'
        self.assertTrue(viewer._load_detail(1))
        self.assertTrue(active_detail.is_set())
        self.assertEqual(1, viewer._pending_detail_index)
        viewer.close()
        self.assertTrue(active_detail.is_set())

    def test_selected_prefetch_is_adopted_without_second_media_read(self) -> None:
        """Show a matching in-flight prefetch as foreground detail directly.

        Returns:
            None: Selecting the prefetched photo keeps its worker and adopts
                its result without issuing another media request.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Adopt prefetch'),
        )
        self._wait_until(
            lambda: viewer._worker is None and not viewer._presentation_pending,
            'Initial viewer work did not settle before adoption checks.',
        )
        photo = page.photos[0]
        operation_cancelled = threading.Event()
        viewer._worker = mock.Mock()
        viewer._worker_kind = 'prefetch'
        viewer._active_media_cancelled = operation_cancelled
        viewer._active_media_photo_id = photo.image_id
        request_count = session.media_photo.call_count

        self.assertTrue(viewer._load_detail(0))
        self.assertFalse(operation_cancelled.is_set())
        self.assertEqual(0, viewer._pending_detail_index)
        self.assertEqual(request_count, session.media_photo.call_count)

        image = Image.new('RGB', (16, 16))
        adopted: list[tuple[
            int, int, 'Image.Image | None', 'Image.Image | None',
            bytes | None,
        ]] = []

        def capture_detail(
            detail_serial: int,
            image_id: int,
            display_image: 'Image.Image | None',
            cached_image: 'Image.Image | None',
            encoded: bytes | None,
            decode_reason: str | None = None,
        ) -> None:
            """Capture the adopted raster without invoking Tk rendering.

            Args:
                detail_serial (int): Current foreground selection serial.
                image_id (int): Photo ID carried by the prefetched result.
                display_image (Image.Image | None): Transferred display raster.
                cached_image (Image.Image | None): Optional private cache copy.
                encoded (bytes | None): Cacheable encoded response body.
                decode_reason (str | None): Optional controlled decode outcome.

            Returns:
                None: The caller retains and closes the captured rasters.
            """
            adopted.append((
                detail_serial, image_id, display_image, cached_image, encoded,
            ))

        with (mock.patch.object(viewer, '_show_detail', side_effect=capture_detail),
              mock.patch.object(viewer, '_dispatch_pending_work'),
              mock.patch.object(viewer, '_schedule_queue_poll')):
            viewer._events.put((
                viewer._generation, 'prefetch', (photo.image_id, image, None),
            ))
            viewer._poll_queue()

        self.assertEqual(1, len(adopted))
        self.assertEqual(viewer._detail_serial, adopted[0][0])
        self.assertEqual(photo.image_id, adopted[0][1])
        self.assertIs(image, adopted[0][2])
        self.assertIsNone(adopted[0][3])
        self.assertIsNone(adopted[0][4])
        self.assertEqual(request_count, session.media_photo.call_count)
        image.close()

        active_detail = threading.Event()
        viewer._worker_kind = 'detail'
        viewer._active_media_cancelled = active_detail
        viewer._active_media_photo_id = photo.image_id
        viewer._pending_detail_index = None
        detail_serial = viewer._detail_serial
        generation = viewer._generation
        self.assertTrue(viewer._load_detail(0))
        self.assertFalse(active_detail.is_set())
        self.assertEqual(detail_serial, viewer._detail_serial)
        self.assertEqual(generation, viewer._generation)
        self.assertIsNone(viewer._pending_detail_index)
        self.assertEqual(request_count, session.media_photo.call_count)
        viewer.close()

    def test_lightbox_close_cancels_active_detail_and_background_refresh_yields(self) -> None:
        """Release hidden image work and interrupt refresh for a new selection.

        Returns:
            None: Returning to the grid cancels its old prefetch; a selected
                photo also cancels background refresh and keeps that refresh queued.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Responsive selection'),
        )
        self._wait_until(
            lambda: viewer._worker is None and not viewer._presentation_pending,
            'Initial viewer work did not settle before cancellation checks.',
        )
        prefetched = threading.Event()
        viewer._worker = mock.Mock()
        viewer._worker_kind = 'prefetch'
        viewer._active_media_cancelled = prefetched
        previous_generation = viewer._generation
        viewer._close_lightbox()
        self.assertTrue(prefetched.is_set())
        self.assertGreater(viewer._generation, previous_generation)

        refreshed = threading.Event()
        viewer._worker_kind = 'refresh'
        viewer._active_media_cancelled = refreshed
        self.assertTrue(viewer._load_detail(0))
        self.assertTrue(refreshed.is_set())
        self.assertEqual(0, viewer._pending_detail_index)
        self.assertTrue(viewer._refresh_pending)
        self.assertFalse(viewer._refresh_manual_pending)
        viewer.close()

    def test_queued_refresh_survives_a_busy_worker(self) -> None:
        """Keep one manual refresh until the current worker becomes idle.

        Returns:
            None: The queued refresh reads the current album after contention.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album'),
        )
        self._wait_until(lambda: viewer._worker is None, 'Initial work did not settle.')
        reads = session.media_page.call_count
        viewer._worker = mock.Mock()
        viewer.refresh_photos()
        viewer.refresh_photos()
        self.assertTrue(viewer._refresh_pending)
        self.assertEqual(reads, session.media_page.call_count)
        viewer._worker = None
        viewer._dispatch_pending_work()
        self._wait_until(
            lambda: session.media_page.call_count == reads + 1 and viewer._worker is None,
            'Queued refresh did not run after the worker became idle.',
        )
        viewer.close()

    def test_failed_revision_refresh_retries_on_next_observation(self) -> None:
        """Do not commit a revision whose corresponding refresh failed.

        Returns:
            None: A later identical revision retries and commits after success.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album'),
        )
        self._wait_until(lambda: viewer._worker is None, 'Initial work did not settle.')
        session.can_read_admin_media.return_value = True
        session.media_revision.return_value = 'a' * 64
        session.media_page.side_effect = [RuntimeError('temporary'), page]
        viewer._revision_initial_attempted = True
        viewer._launch('revision', lambda: session.media_revision(TEST_GALLERY_ID))
        self._wait_until(
            lambda: session.media_page.call_count >= 2 and viewer._worker is None,
            'Failed refresh did not settle.',
        )
        self.assertIsNone(viewer._revision)
        viewer._launch('revision', lambda: session.media_revision(TEST_GALLERY_ID))
        self._wait_until(
            lambda: viewer._revision == 'a' * 64 and viewer._worker is None,
            'An unchanged revision did not retry the failed refresh.',
        )
        viewer.close()

    def test_refresh_preserves_unchanged_tiles_and_loads_new_photos(self) -> None:
        """Keep equal-refresh tiles and test membership/order full reconciliation.

        Returns:
            None: Membership and order changes rebuild tiles but retain valid images.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Album'),
        )
        self._wait_until(
            lambda: bool(viewer._photo_images) and all(viewer._photo_images)
            and viewer._worker is None,
            'Initial thumbnails did not settle.',
        )
        old_tiles = tuple(viewer._tile_buttons)
        reads = session.media_photo.call_count
        viewer.refresh_photos()
        self._wait_until(
            lambda: session.media_page.call_count >= 2 and viewer._worker is None,
            'Equal refresh did not settle.',
        )
        self.assertEqual(old_tiles, tuple(viewer._tile_buttons))
        self.assertEqual(reads, session.media_photo.call_count)
        extra = LinkedPhoto(TEST_NEXT_PAGE_IMAGE_ID, 'New photo')
        session.media_page.return_value = LinkedMediaPage(
            page.gallery_id, page.parent_id, page.photos + (extra,), None,
        )
        image_bytes[extra.image_id] = self._png((90, 80, 70))
        viewer.refresh_photos()
        self._wait_until(
            lambda: len(viewer._photos) == len(page.photos) + 1
            and viewer._worker is None,
            'Changed refresh did not add the new photo.',
        )
        self.assertTrue(all(viewer._photo_images[:len(page.photos)]))
        membership_tiles = tuple(viewer._tile_buttons)
        self.assertNotEqual(old_tiles, membership_tiles)

        self.assertTrue(viewer.select_photo(0))
        anchor_id = viewer._photos[viewer._selection_anchor_index].image_id
        reordered_photos = tuple(reversed(viewer._photos))
        session.media_page.return_value = LinkedMediaPage(
            page.gallery_id, page.parent_id, reordered_photos, None,
        )
        viewer.refresh_photos()
        self._wait_until(
            lambda: viewer._worker is None
            and tuple(photo.image_id for photo in viewer._photos)
            == tuple(photo.image_id for photo in reordered_photos),
            'The reordered refresh did not settle.',
        )
        self.assertNotEqual(membership_tiles, tuple(viewer._tile_buttons))
        self.assertEqual((TEST_FIRST_IMAGE_ID,), viewer.selected_photo_ids())
        self.assertEqual(
            anchor_id,
            viewer._photos[viewer._selection_anchor_index].image_id,
        )
        viewer.close()

    def test_same_order_metadata_refresh_reuses_tiles_and_view_state(self) -> None:
        """Update one changed preview in place without moving the gallery view.

        Returns:
            None: Same-order metadata keeps tile owners, selection and scroll;
                existing descriptor comparison invalidates only the changed image.
        """
        page, presentation, image_bytes = self._gallery_data()
        initial_proof = time.monotonic()
        photos: list[LinkedPhoto] = []
        for index in range(TEST_SCROLL_PHOTO_COUNT):
            image_id = TEST_FIRST_IMAGE_ID + index
            image_bytes[image_id] = self._png((index, 80, 160))
            etag = '"' + hashlib.sha256(image_bytes[image_id]).hexdigest() + '"'
            photos.append(LinkedPhoto(
                image_id,
                f'Refresh photo {index + 1}',
                cache_eligible=True,
                thumbnail_etag=etag,
                thumbnail_verified_at=initial_proof,
            ))
        current_page = LinkedMediaPage(
            page.gallery_id, page.parent_id, tuple(photos), None,
        )
        session = self._session(current_page, presentation, image_bytes)
        viewer = self._open_viewer(
            session,
            LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Refresh'),
        )
        self._wait_until(
            lambda: len(viewer._photos) == len(photos)
            and viewer._worker is None
            and viewer._viewport_after_id is None
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices),
            'Initial refreshed-photo thumbnails did not settle.',
        )
        viewer.grid_canvas.yview_moveto(1.0)
        viewer._on_grid_viewport(None)
        self._wait_until(
            lambda: bool(viewer._visible_thumbnail_indices)
            and viewer._worker is None
            and viewer._viewport_after_id is None
            and all(viewer._photo_images[index]
                    for index in viewer._admitted_thumbnail_indices),
            'The bottom thumbnail band did not settle.',
        )

        changed_index = min(viewer._visible_thumbnail_indices)
        retained_index = next(
            index for index in sorted(viewer._admitted_thumbnail_indices)
            if index != changed_index
        )
        self.assertTrue(viewer.toggle_photo_selection(
            viewer._photos[changed_index].image_id,
        ))
        tiles = tuple(viewer._tile_buttons)
        previous_image = viewer._photo_images[changed_index]
        retained_image = viewer._photo_images[retained_index]
        selected_ids = viewer.selected_photo_ids()
        selection_anchor = viewer._selection_anchor_index
        scroll_position = viewer.grid_canvas.yview()
        self.assertGreater(scroll_position[0], 0.0)
        viewport_epoch = viewer._viewport_measure_epoch
        self.assertIsNotNone(viewport_epoch)
        reads_before_refresh = session.media_photo.call_count
        refreshed_proof = time.monotonic()
        changed_photo = replace(
            viewer._photos[changed_index],
            title='Renamed visible photo',
            thumbnail_verified_at=refreshed_proof,
        )
        refreshed_photos = tuple(
            changed_photo if index == changed_index else replace(
                photo, thumbnail_verified_at=refreshed_proof,
            )
            for index, photo in enumerate(viewer._photos)
        )
        session.media_page.return_value = LinkedMediaPage(
            page.gallery_id, page.parent_id, refreshed_photos, None,
        )

        viewer.refresh_photos()
        self._wait_until(
            lambda: viewer._worker is None
            and viewer._viewport_after_id is None
            and viewer._photo_images[changed_index] is not None
            and viewer._photo_images[changed_index] is not previous_image
            and viewer._photo_images[retained_index] is retained_image,
            'The same-order refresh did not replace only its changed image.',
        )
        self.assertEqual(tiles, tuple(viewer._tile_buttons))
        self.assertEqual(
            'Renamed visible photo',
            viewer._tile_titles[changed_index],
        )
        self.assertEqual(
            'Renamed visible photo',
            viewer._tile_tooltips[changed_index].get(),
        )
        self.assertEqual(selected_ids, viewer.selected_photo_ids())
        self.assertEqual(selection_anchor, viewer._selection_anchor_index)
        self.assertEqual(scroll_position, viewer.grid_canvas.yview())
        self.assertNotEqual(viewport_epoch, viewer._viewport_measure_epoch)
        self.assertEqual(
            refreshed_proof,
            viewer._photos[changed_index].thumbnail_verified_at,
        )
        self.assertEqual(reads_before_refresh, session.media_photo.call_count)
        viewer.close()

    def test_unproven_refresh_replaces_only_changed_visible_thumbnail(self) -> None:
        """Invalidate only previews whose proof or validator changed.

        Returns:
            None: No-ETag proof renewal and strong-ETag replacement update one
                image while unchanged tile widgets and sibling images remain.
        """
        page, presentation, image_bytes = self._gallery_data()
        initial_epoch = time.monotonic()
        stable_etag = '"' + hashlib.sha256(
            image_bytes[TEST_SECOND_IMAGE_ID],
        ).hexdigest() + '"'
        first = replace(
            page.photos[0], cache_eligible=True,
            thumbnail_verified_at=initial_epoch,
        )
        stable = replace(
            page.photos[1], cache_eligible=True,
            thumbnail_etag=stable_etag,
            thumbnail_verified_at=initial_epoch,
        )
        current_page = LinkedMediaPage(
            page.gallery_id, page.parent_id, (first, stable), None,
        )
        session = self._session(current_page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Unproven refresh'),
        )
        self._wait_until(
            lambda: len(viewer._photo_images) == 2
            and all(viewer._photo_images) and viewer._worker is None,
            'Initial two real Tk thumbnails were not mounted.',
        )
        tiles = tuple(viewer._tile_buttons)
        old_first, old_stable = viewer._photo_images
        initial_reads = session.media_photo.call_count
        self.assertEqual(2, initial_reads)

        image_bytes[TEST_FIRST_IMAGE_ID] = self._png((12, 45, 230))
        refreshed_first = replace(
            first, thumbnail_verified_at=time.monotonic(),
        )
        refreshed_stable = replace(
            stable, thumbnail_verified_at=time.monotonic(),
        )
        session.media_page.return_value = LinkedMediaPage(
            page.gallery_id, page.parent_id,
            (refreshed_first, refreshed_stable), None,
        )
        viewer.refresh_photos()
        self._wait_until(
            lambda: viewer._worker is None
            and len(viewer._photo_images) == 2
            and viewer._photo_images[0] is not None
            and viewer._photo_images[0] is not old_first
            and viewer._thumbnail_loaded == {0, 1},
            'Fresh metadata did not invalidate the unproven rendered tile.',
        )
        self.assertIs(old_stable, viewer._photo_images[1])
        self.assertEqual(tiles, tuple(viewer._tile_buttons))
        self.assertEqual(initial_reads + 1, session.media_photo.call_count)
        actual = viewer._cached_thumbnail(refreshed_first)
        expected = viewer._prepare_thumbnail(image_bytes[TEST_FIRST_IMAGE_ID])
        try:
            self.assertIsNotNone(actual)
            assert actual is not None
            self.assertEqual(expected.tobytes(), actual.tobytes())
        finally:
            expected.close()
            if actual is not None:
                actual.close()

        image_before_etag_change = viewer._photo_images[1]
        image_before_second = viewer._photo_images[0]
        changed_body = self._png((40, 50, 210))
        image_bytes[TEST_SECOND_IMAGE_ID] = changed_body
        changed_etag = '"' + hashlib.sha256(changed_body).hexdigest() + '"'
        changed_stable = replace(
            refreshed_stable,
            thumbnail_etag=changed_etag,
            thumbnail_verified_at=time.monotonic(),
        )
        session.media_page.return_value = LinkedMediaPage(
            page.gallery_id, page.parent_id,
            (refreshed_first, changed_stable), None,
        )
        viewer.refresh_photos()
        self._wait_until(
            lambda: viewer._worker is None
            and viewer._photo_images[0] is image_before_second
            and viewer._photo_images[1] is not None
            and viewer._photo_images[1] is not image_before_etag_change
            and viewer._thumbnail_loaded == {0, 1},
            'A changed strong ETag did not invalidate only its preview.',
        )
        self.assertEqual(tiles, tuple(viewer._tile_buttons))
        self.assertEqual(initial_reads + 2, session.media_photo.call_count)
        actual = viewer._cached_thumbnail(changed_stable)
        expected = viewer._prepare_thumbnail(changed_body)
        try:
            self.assertIsNotNone(actual)
            assert actual is not None
            self.assertEqual(expected.tobytes(), actual.tobytes())
        finally:
            expected.close()
            if actual is not None:
                actual.close()
        viewer.close()

    def test_real_touchpad_packet_scrolls_grid_and_zoom(self) -> None:
        """Exercise wheel and packed touchpad input at mapped Tk coordinates.

        Returns:
            None: Wheel directions work; precise input moves by its pixel delta.
        """
        page, presentation, image_bytes = self._gallery_data()
        scroll_photos = tuple(
            LinkedPhoto(
                TEST_FIRST_IMAGE_ID + index,
                f'Scroll photo {index + 1}',
            )
            for index in range(TEST_SCROLL_PHOTO_COUNT)
        )
        image_bytes = {
            photo.image_id: self._png((index, 80, 160))
            for index, photo in enumerate(scroll_photos)
        }
        session = self._session(page, presentation, image_bytes)
        session.media_page.return_value = LinkedMediaPage(
            gallery_id=page.gallery_id,
            parent_id=page.parent_id,
            photos=scroll_photos,
            next_after=None,
        )
        viewer = self._open_viewer(
            session,
            LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Scroll'),
        )
        self._wait_until(
            lambda: len(viewer._tile_buttons) >= 2,
            'The scroll fixture did not create photo tiles.',
        )
        self.root.update_idletasks()
        canvas = viewer.grid_canvas
        canvas_x, canvas_y = 24, 24
        root_x = canvas.winfo_rootx() + canvas_x
        root_y = canvas.winfo_rooty() + canvas_y
        canvas.yview_moveto(0.1)
        self.root.update()
        before_wheel = canvas.yview()[0]
        canvas.event_generate(
            '<MouseWheel>', delta=TEST_WHEEL_DELTA_UNITS,
            x=canvas_x, y=canvas_y,
            rootx=root_x, rooty=root_y,
        )
        self.root.update()
        after_wheel_up = canvas.yview()[0]
        self.assertLess(after_wheel_up, before_wheel)
        canvas.event_generate(
            '<MouseWheel>', delta=-TEST_WHEEL_DELTA_UNITS,
            x=canvas_x, y=canvas_y,
            rootx=root_x, rooty=root_y,
        )
        self.root.update()
        self.assertGreater(canvas.yview()[0], after_wheel_up)

        with self.subTest(input_variant='packed touchpad'):
            try:
                precise_supported = bool(self.root.tk.call(
                    'info', 'commands', 'tk::PreciseScrollDeltas',
                ))
            except tk.TclError:
                precise_supported = False
            if not precise_supported:
                self.skipTest('This Tk version has no packed touchpad helper.')
            canvas.yview_moveto(0.1)
            self.root.update()
            region = canvas.bbox('all')
            self.assertIsNotNone(region)
            assert region is not None
            content_height = region[3] - region[1]
            before_precise = canvas.yview()[0]
            packet_down = (0 << 16) | (-TEST_TOUCHPAD_SCROLL_PIXELS & 0xffff)
            canvas.event_generate(
                '<TouchpadScroll>', delta=packet_down,
                x=canvas_x, y=canvas_y, rootx=root_x, rooty=root_y,
            )
            self.root.update()
            down_pixels = (canvas.yview()[0] - before_precise) * content_height
            self.assertAlmostEqual(
                down_pixels, TEST_TOUCHPAD_SCROLL_PIXELS,
                delta=TEST_TOUCHPAD_SCROLL_TOLERANCE,
            )
            after_down = canvas.yview()[0]
            packet_up = TEST_TOUCHPAD_SCROLL_PIXELS & 0xffff
            canvas.event_generate(
                '<TouchpadScroll>', delta=packet_up,
                x=canvas_x, y=canvas_y, rootx=root_x, rooty=root_y,
            )
            self.root.update()
            after_up = canvas.yview()[0]
            up_pixels = (after_down - after_up) * content_height
            self.assertAlmostEqual(
                up_pixels, TEST_TOUCHPAD_SCROLL_PIXELS,
                delta=TEST_TOUCHPAD_SCROLL_TOLERANCE,
            )
            self.assertAlmostEqual(
                after_up, before_precise,
                delta=TEST_TOUCHPAD_SCROLL_TOLERANCE / content_height,
            )

        viewer._select_photo(0)
        self._wait_until(
            lambda: viewer._lightbox is not None
            and viewer._lightbox._photo is not None,
            'The Lightbox did not attach the selected photo before zoom input.',
        )
        lightbox = viewer._lightbox
        assert lightbox is not None
        lightbox_canvas = lightbox._canvas
        lightbox_x, lightbox_y = 20, 20
        lightbox_root_x = lightbox_canvas.winfo_rootx() + lightbox_x
        lightbox_root_y = lightbox_canvas.winfo_rooty() + lightbox_y
        fit_zoom = lightbox._zoom
        lightbox_canvas.event_generate(
            '<MouseWheel>', delta=TEST_WHEEL_DELTA_UNITS,
            x=lightbox_x, y=lightbox_y,
            rootx=lightbox_root_x, rooty=lightbox_root_y,
        )
        self.root.update()
        self.assertGreater(lightbox._zoom, fit_zoom)
        lightbox_canvas.event_generate(
            '<MouseWheel>', delta=-TEST_WHEEL_DELTA_UNITS,
            x=lightbox_x, y=lightbox_y,
            rootx=lightbox_root_x, rooty=lightbox_root_y,
        )
        self.root.update()
        self.assertAlmostEqual(lightbox._zoom, fit_zoom)
        if precise_supported:
            lightbox_canvas.event_generate(
                '<TouchpadScroll>', delta=TEST_TOUCHPAD_ZOOM_DELTA_UNITS,
                x=lightbox_x, y=lightbox_y,
                rootx=lightbox_root_x, rooty=lightbox_root_y,
            )
            self.root.update()
            self.assertGreater(lightbox._zoom, fit_zoom)
            lightbox_canvas.event_generate(
                '<TouchpadScroll>', delta=-TEST_TOUCHPAD_ZOOM_DELTA_UNITS,
                x=lightbox_x, y=lightbox_y,
                rootx=lightbox_root_x, rooty=lightbox_root_y,
            )
            self.root.update()
            self.assertAlmostEqual(lightbox._zoom, fit_zoom)

    def test_implicit_first_page_failure_keeps_gallery_retryable(self) -> None:
        """Keep a transient first-page failure selected until explicit retry.

        Returns:
            None: Retry loads the same gallery without consuming a candidate.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, None, 'Retry album')
        session.gallery_page.return_value = LinkedGalleryPage((gallery,), None)
        session.media_page.side_effect = [RuntimeError('temporary'), page]
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        self._wait_until(
            lambda: workspace._viewer is not None
            and workspace._viewer._gallery_viewer_controller.retry_button.winfo_manager(),
            'The failed implicit page did not expose its retry action.',
        )
        self.assertEqual((str(TEST_GALLERY_ID),), workspace.tree.selection())
        viewer = workspace._viewer._gallery_viewer_controller
        viewer.retry_button.invoke()
        self._wait_until(
            lambda: bool(viewer._photo_images) and all(viewer._photo_images),
            'Retry did not load the same selected gallery.',
        )
        self.assertEqual((str(TEST_GALLERY_ID),), workspace.tree.selection())
        self.assertEqual(2, session.media_page.call_count)
        workspace.destroy()

    def test_implicit_unavailable_gallery_advances_candidate(self) -> None:
        """Skip a gallery only when its authenticated media target is absent.

        Returns:
            None: The next candidate is selected and previewed automatically.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        # The rejected lower ID must precede the fixture's actual media owner.
        first = LinkedGallery(TEST_GALLERY_ID - 1, None, 'Restricted album')
        second = LinkedGallery(TEST_GALLERY_ID, None, 'Public album')
        session.gallery_page.return_value = LinkedGalleryPage((first, second), None)
        session.media_page.side_effect = [
            LinkingMediaUnavailable('sanitized'), page,
        ]
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, mock.Mock(), lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        self._wait_until(
            lambda: workspace._selected_gallery_id == TEST_GALLERY_ID
            and workspace._viewer is not None
            and bool(workspace._viewer._gallery_viewer_controller._photo_images)
            and bool(workspace._viewer._gallery_viewer_controller
                     ._visible_thumbnail_indices)
            and all(
                workspace._viewer._gallery_viewer_controller._photo_images[index]
                for index in workspace._viewer._gallery_viewer_controller
                ._visible_thumbnail_indices
            ),
            'The unavailable implicit candidate did not advance to the next album.',
        )
        self.assertEqual(2, session.media_page.call_count)
        workspace.destroy()

    def test_page_detail_manual_slideshow_and_close(self) -> None:
        """Render actual thumbnails/detail, advance once, stop and close.

        Returns:
            None: Synthetic image pixels and real widget lifecycle are asserted.
        """
        page, presentation, image_bytes = self._gallery_data()
        first_page = LinkedMediaPage(
            page.gallery_id, page.parent_id, page.photos, TEST_SECOND_IMAGE_ID,
        )
        next_page = LinkedMediaPage(
            page.gallery_id, page.parent_id,
            (LinkedPhoto(TEST_NEXT_PAGE_IMAGE_ID, 'Next page'),), None,
        )
        image_bytes[TEST_NEXT_PAGE_IMAGE_ID] = self._png((50, 70, 240))
        session = self._session(page, presentation, image_bytes)

        def read_page(
            gallery_id: int,
            after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> LinkedMediaPage:
            """Return the fixture page selected by its server cursor.

            Args:
                gallery_id (int): Physical gallery requested by the viewer.
                after_id (int | None): Exclusive cursor, or None for page one.
                cancelled (threading.Event | None): Viewer cancellation signal.
            Returns:
                LinkedMediaPage: First or continuation page owned by the fixture.
            """
            if cancelled is not None and cancelled.is_set():
                raise RuntimeError('Synthetic page read cancelled')
            if gallery_id != TEST_GALLERY_ID:
                raise ValueError('Unexpected gallery in native UI fixture')
            if after_id is None:
                return first_page
            if after_id == TEST_SECOND_IMAGE_ID:
                return next_page
            raise ValueError('Unexpected media page cursor')

        session.media_page.side_effect = read_page
        gallery = LinkedGallery(
            TEST_GALLERY_ID,
            TEST_PARENT_GALLERY_ID,
            'Native viewer regression',
        )
        viewer = self._open_viewer(session, gallery)
        self.assertFalse(hasattr(viewer, 'more_button'))
        self.assertFalse(hasattr(viewer, 'previous_page_button'))
        self.root.update_idletasks()
        width, height = viewer.window.winfo_width(), viewer.window.winfo_height()
        self.assertGreaterEqual(width, TEST_VIEWER_MINIMUM_SIZE[0])
        self.assertGreaterEqual(height, TEST_VIEWER_MINIMUM_SIZE[1])
        self.assertLessEqual(width, self.root.winfo_screenwidth())
        self.assertLessEqual(height, self.root.winfo_screenheight())
        self._wait_until(
            lambda: session.media_page.call_count == 2
            and len(viewer._photo_images) == 3
            and all(viewer._photo_images),
            'The visible album metadata and thumbnails did not finish loading.',
        )
        self._wait_until(
            lambda: viewer._grid_columns >= TEST_WIDE_GRID_COLUMNS_MINIMUM,
            'The wide viewer did not lay out the expected photo columns.',
        )
        tile_relayout = mock.Mock(wraps=viewer._tile_buttons[0].grid_configure)
        viewer._tile_buttons[0].grid_configure = tile_relayout
        current_width = viewer.window.winfo_width()
        viewer.window.geometry(f'{current_width + 1}x620')
        self._wait_until(
            lambda: viewer.grid_canvas.winfo_width() > 0,
            'The viewer grid did not receive its same-bucket resize.',
        )
        self.root.update()
        self.assertEqual(0, tile_relayout.call_count)
        viewer._tile_buttons[0].grid_configure = tile_relayout._mock_wraps
        viewer.window.geometry('580x380')
        self._wait_until(
            lambda: viewer._grid_columns >= TEST_NARROW_GRID_COLUMNS_MINIMUM
            and viewer.window.winfo_width() <= TEST_VIEWER_MINIMUM_SIZE[0],
            'The minimum-width viewer did not retain a usable photo column.',
        )
        viewer.window.geometry('840x620')
        self._wait_until(
            lambda: viewer._grid_columns >= TEST_WIDE_GRID_COLUMNS_MINIMUM,
            'The restored wide viewer did not restore its photo columns.',
        )
        self._wait_until(
            lambda: session.media_photo.call_count == 3
            and len(viewer._tile_buttons) == 3,
            'The initial continuous thumbnail grid did not finish rendering.',
        )
        self.assertFalse(viewer._lightbox is not None and viewer._lightbox.visible)
        self.assertEqual(
            [
                mock.call(TEST_GALLERY_ID, TEST_FIRST_IMAGE_ID,
                          thumbnail=True, cancelled=mock.ANY,
                          cache_eligible=None, thumbnail_etag=None,
                          thumbnail_verified_at=None, preload_eligible=None,
                          preload_thumbnail_etag=None, preload_verified_at=None,
                          timings=mock.ANY),
                mock.call(TEST_GALLERY_ID, TEST_SECOND_IMAGE_ID,
                          thumbnail=True, cancelled=mock.ANY,
                          cache_eligible=None, thumbnail_etag=None,
                          thumbnail_verified_at=None, preload_eligible=None,
                          preload_thumbnail_etag=None, preload_verified_at=None,
                          timings=mock.ANY),
                mock.call(TEST_GALLERY_ID, TEST_NEXT_PAGE_IMAGE_ID,
                          thumbnail=True, cancelled=mock.ANY,
                          cache_eligible=None, thumbnail_etag=None,
                          thumbnail_verified_at=None, preload_eligible=None,
                          preload_thumbnail_etag=None, preload_verified_at=None,
                          timings=mock.ANY),
            ],
            session.media_photo.call_args_list,
        )
        for call in session.media_photo.call_args_list:
            self.assertIsInstance(call.kwargs['timings'], MediaReadTimings)
        self.assertNotEqual('', viewer._tile_buttons[0].cget('image'))
        self.assertNotEqual('', viewer._tile_buttons[1].cget('image'))
        content_geometry = dict(viewer._main_content.pack_info())
        self._double_click_tile(viewer._tile_buttons[0])
        self._wait_until(
            lambda: viewer._lightbox is not None
            and viewer._lightbox.visible
            and viewer._lightbox._photo is not None,
            'Selecting the first grid tile did not open the lightbox.',
        )
        self._assert_no_browsing_toplevels()
        self.assertEqual('', viewer._main_content.winfo_manager())
        self.assertEqual(0, viewer._slide_index)
        rendered = ImageTk.getimage(viewer._lightbox._photo)
        self.assertEqual((220, 35, 45, 255), rendered.getpixel((0, 0)))
        self.assertTrue(viewer._lightbox._map_button.instate(('disabled',)))

        viewer.slide_button.invoke()
        self.assertTrue(viewer._slideshow_running)
        self.assertTrue(viewer._lightbox._fullscreen)
        self._wait_until(
            lambda: session.media_photo.call_count >= 4,
            'Starting the slideshow did not request its immediate next image.',
        )
        self._wait_until(
            lambda: viewer._lightbox._photo is not None
            and ImageTk.getimage(viewer._lightbox._photo).getpixel((0, 0))
            == (20, 190, 95, 255),
            'The immediate slideshow advance did not render the second photo.',
        )
        self.assertEqual(
            TEST_SECOND_IMAGE_ID,
            viewer._photos[viewer._slide_index].image_id,
        )

        # Stop before the server-provided ten-second interval elapses.
        self.assertIsNotNone(viewer._slideshow_id)
        viewer.slide_button.invoke()
        self.assertFalse(viewer._slideshow_running)
        self.assertIsNone(viewer._slideshow_id)

        viewer._lightbox._canvas.focus_force()
        viewer._lightbox._canvas.event_generate('<KeyPress-Right>')
        self._wait_until(
            lambda: viewer._photos == first_page.photos + next_page.photos
            and viewer._lightbox._photo is not None
            and ImageTk.getimage(viewer._lightbox._photo).getpixel((0, 0))
            == (50, 70, 240, 255),
            'The lightbox Right key did not cross to the next bounded page.',
        )
        self.assertEqual(
            TEST_NEXT_PAGE_IMAGE_ID,
            viewer._photos[viewer._slide_index].image_id,
        )
        self.assertEqual(
            TEST_SECOND_IMAGE_ID,
            session.media_page.call_args.kwargs['after_id'],
        )
        fit_zoom = viewer._lightbox._zoom
        viewer._lightbox._wheel(mock.Mock(delta=120))
        self.assertGreater(viewer._lightbox._zoom, fit_zoom)
        viewer._lightbox._wheel(mock.Mock(delta=-120))
        self.assertAlmostEqual(fit_zoom, viewer._lightbox._zoom)
        viewer._lightbox._change_zoom(2.0)
        self.assertGreater(viewer._lightbox._zoom, 1.0)
        crop_mode = viewer._lightbox._content_crop_enabled
        viewer._lightbox._canvas.focus_force()
        self._wait_until(
            lambda: self.root.focus_get() is viewer._lightbox._canvas,
            'The fullscreen canvas did not receive focus before zero.',
        )
        viewer._lightbox._canvas.event_generate('<KeyPress-0>')
        self._wait_until(
            lambda: viewer._lightbox._zoom == 1.0,
            'The zero shortcut did not reset zoom to the fitted viewport.',
        )
        self.assertEqual(crop_mode, viewer._lightbox._content_crop_enabled)
        viewer._lightbox._canvas.focus_force()
        viewer._lightbox._canvas.event_generate('<KeyPress-Left>')
        self._wait_until(
            lambda: viewer._photos == first_page.photos + next_page.photos
            and viewer._slide_index == 1
            and viewer._lightbox._photo is not None
            and ImageTk.getimage(viewer._lightbox._photo).getpixel((0, 0))
            == (20, 190, 95, 255),
            'The lightbox Left key did not cross back to the preceding page.',
        )
        page_requests = session.media_page.call_args_list
        self.assertEqual(2, len(page_requests))
        self.assertEqual(
            [None, TEST_SECOND_IMAGE_ID],
            [request.kwargs.get('after_id') for request in page_requests],
        )
        viewer._move_lightbox(-1)
        self._wait_until(
            lambda: viewer._slide_index == 0
            and viewer._lightbox._photo is not None
            and ImageTk.getimage(viewer._lightbox._photo).getpixel((0, 0))
            == (220, 35, 45, 255),
            'Left navigation did not reach the album’s first photo.',
        )
        viewer._move_lightbox(-1)
        self._wait_until(
            lambda: viewer._slide_index == len(first_page.photos)
            and viewer._lightbox._photo is not None
            and ImageTk.getimage(viewer._lightbox._photo).getpixel((0, 0))
            == (50, 70, 240, 255),
            'Left navigation did not wrap from the first to the last photo.',
        )
        self._wait_until(
            lambda: set(viewer._detail_cache)
            >= {TEST_FIRST_IMAGE_ID, TEST_SECOND_IMAGE_ID,
                TEST_NEXT_PAGE_IMAGE_ID},
            'The last photo did not prepare both wrapped detail neighbors.',
        )
        viewer._move_lightbox(1)
        self._wait_until(
            lambda: viewer._slide_index == 0
            and viewer._lightbox._photo is not None
            and ImageTk.getimage(viewer._lightbox._photo).getpixel((0, 0))
            == (220, 35, 45, 255),
            'Right navigation did not wrap from the last to the first photo.',
        )
        viewer.window.event_generate('<Escape>')
        self.root.update()
        self.assertFalse(viewer._closed)
        self.assertTrue(viewer._lightbox.visible)
        self.assertFalse(viewer._lightbox._fullscreen)
        viewer.window.event_generate('<Escape>')
        self.root.update()
        self.assertFalse(viewer._lightbox.visible)
        self._wait_until(
            lambda: viewer._main_content.winfo_manager() == 'pack'
            and dict(viewer._main_content.pack_info()) == content_geometry,
            'Lightbox dismissal did not remap the original main content layout.',
        )
        viewer.window.event_generate('<Escape>')
        self.root.update()
        self.assertFalse(viewer._closed)
        self.assertTrue(viewer.frame.winfo_exists())
        self.assertFalse(viewer._cancelled.is_set())
        self.assertIsNone(viewer._slideshow_id)

    def test_slideshow_without_presentation_uses_medium_and_wraps(self) -> None:
        """Allow explicit looping slides when server presentation is absent.

        Returns:
            None: The local five-second medium pace wraps across the album.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.can_read_presentation.return_value = False
        viewer = self._open_viewer(
            session,
            LinkedGallery(
                TEST_GALLERY_ID,
                TEST_PARENT_GALLERY_ID,
                'Local slideshow fallback',
            ),
        )
        self._wait_until(
            lambda: len(viewer._tile_buttons) == len(page.photos)
            and viewer._worker is None,
            'The fallback slideshow album did not finish loading.',
        )
        self.assertIsNone(viewer._presentation)
        self.assertEqual(
            native_gallery_viewer.MEDIA_SLIDESHOW_DEFAULT_SECONDS * 1000,
            viewer._slideshow_delay_ms(),
        )
        viewer.slide_button.invoke()
        self._wait_until(
            lambda: viewer._slide_index == 0
            and viewer._lightbox is not None
            and viewer._lightbox._photo is not None
            and viewer._slideshow_id is not None,
            'The first fallback slide did not schedule the tested pace.',
        )
        # The five-second production interval is asserted above. Advance its
        # exact callback directly to avoid spending the native partition budget
        # waiting on the real desktop scheduler to reach the same transition.
        viewer.window.after_cancel(viewer._slideshow_id)
        viewer._slideshow_id = None
        viewer._advance_slide()
        self._wait_until(
            lambda: viewer._slide_index == 1
            and viewer._lightbox._photo is not None
            and viewer._slideshow_id is not None,
            'The explicit fallback slideshow did not advance.',
        )
        viewer.window.after_cancel(viewer._slideshow_id)
        viewer._slideshow_id = None
        viewer._advance_slide()
        self._wait_until(
            lambda: viewer._slide_index == 0
            and viewer._lightbox._photo is not None
            and viewer._slideshow_id is not None
            and viewer._slideshow_running,
            'The fallback slideshow did not wrap continuously.',
        )
        viewer.slide_button.invoke()
        viewer.close()

    def test_slideshow_opens_fullscreen_and_starts_configured_music(self) -> None:
        """Start immediate fullscreen slideshow and configured playlist together.

        Returns:
            None: The local playback queue receives its explicit first track.
        """
        page, _presentation, image_bytes = self._gallery_data()
        track = LinkedTrack(
            'a' * 32, 'Configured fixture', 'audio/wav',
            TEST_PANEL_TRACK_BYTES, 'b' * 64,
        )
        configured = LinkedPresentation(
            enabled=True,
            autoplay=False,
            interval_seconds=TEST_PRESENTATION_INTERVAL_SECONDS,
            transition='medium',
            music=True,
            tracks=(track,),
        )
        session = self._session(page, configured, image_bytes)
        viewer = self._open_viewer(
            session,
            LinkedGallery(
                TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID,
                'Configured slideshow',
            ),
        )
        self._wait_until(
            lambda: len(viewer._photos) == len(page.photos)
            and viewer._presentation is configured and viewer._worker is None,
            'The configured slideshow album did not finish loading.',
        )
        dispatch = mock.Mock(return_value=True)
        viewer._dispatch_audio_prefetch = dispatch

        viewer.slide_button.invoke()

        self.assertTrue(viewer._slideshow_running)
        self.assertTrue(viewer._lightbox._fullscreen)
        self.assertEqual(0, viewer._audio_index)
        dispatch.assert_called_once_with()
        viewer.close()

    def test_slideshow_skips_a_failed_photo_and_continues(self) -> None:
        """Keep a multi-photo slideshow moving after one image read fails.

        Returns:
            None: The failed photo displays an error before the next timer step.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        normal_read = session.media_photo.side_effect

        def read_photo(
            gallery_id: int,
            image_id: int,
            thumbnail: bool,
            cancelled: threading.Event | None = None,
            *,
            cache_eligible: bool | None = None,
            thumbnail_etag: str | None = None,
            thumbnail_verified_at: float | None = None,
            preload_eligible: bool | None = None,
            preload_thumbnail_etag: str | None = None,
            preload_verified_at: float | None = None,
            timings: MediaReadTimings | None = None,
            use_preloaded_snapshot: bool = False,
        ) -> tuple[bytes, str]:
            """Fail only the second original while retaining its thumbnail.

            Args:
                gallery_id (int): Requested physical gallery ID.
                image_id (int): Requested photo identity.
                thumbnail (bool): Whether the read requests a small rendition.
                cancelled (threading.Event | None): Viewer cancellation signal.
                cache_eligible (bool | None): Authorized public cache policy.
                thumbnail_etag (str | None): Current thumbnail validator.
                thumbnail_verified_at (float | None): Catalogue validation time.
                preload_eligible (bool | None): Current Admin preload admission.
                preload_thumbnail_etag (str | None): Admin thumbnail validator.
                preload_verified_at (float | None): Admin proof time.
                timings (MediaReadTimings | None): Optional read timing record.
                use_preloaded_snapshot (bool): Full-read saved-snapshot request.
            Returns:
                tuple[bytes, str]: Normal fixture bytes for successful reads.
            """
            if not thumbnail and image_id == TEST_SECOND_IMAGE_ID:
                raise OSError('Synthetic missing photo')
            return normal_read(
                gallery_id, image_id, thumbnail, cancelled,
                cache_eligible=cache_eligible,
                thumbnail_etag=thumbnail_etag,
                thumbnail_verified_at=thumbnail_verified_at,
                preload_eligible=preload_eligible,
                preload_thumbnail_etag=preload_thumbnail_etag,
                preload_verified_at=preload_verified_at,
                timings=timings,
                use_preloaded_snapshot=use_preloaded_snapshot,
            )

        session.media_photo.side_effect = read_photo
        viewer = self._open_viewer(
            session,
            LinkedGallery(
                TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID,
                'Failed-slide continuation',
            ),
        )
        self._wait_until(
            lambda: len(viewer._tile_buttons) == len(page.photos)
            and viewer._worker is None
            and all(viewer._photo_images),
            'The failure slideshow fixture did not finish loading.',
        )
        viewer._select_photo(0)
        self._wait_until(
            lambda: viewer._lightbox is not None
            and viewer._lightbox._photo is not None,
            'The slideshow could not prepare its first photo.',
        )
        viewer.slide_button.invoke()
        self._wait_until(
            lambda: viewer._slide_index == 1
            and viewer._slideshow_running
            and viewer._slideshow_id is not None
            and viewer._lightbox is not None
            and viewer._lightbox._photo is None,
            'A missing photo stopped the multi-photo slideshow.',
        )
        viewer.window.after_cancel(viewer._slideshow_id)
        viewer._slideshow_id = None
        viewer._advance_slide()
        self._wait_until(
            lambda: viewer._slide_index == 0
            and viewer._lightbox._photo is not None
            and viewer._slideshow_running,
            'The slideshow did not continue past the failed photo.',
        )
        viewer.slide_button.invoke()
        viewer.close()

    def test_location_pin_and_explicit_map_action(self) -> None:
        """Show only admitted GPS and open OSM only from its explicit action.

        Returns:
            None: Valid photo coordinates are formatted and sent to the browser.
        """
        page, presentation, image_bytes = self._gallery_data()
        located_page = LinkedMediaPage(
            page.gallery_id,
            page.parent_id,
            (
                LinkedPhoto(
                    TEST_FIRST_IMAGE_ID,
                    'Located synthetic photo',
                    TEST_PHOTO_LOCATION[0],
                    TEST_PHOTO_LOCATION[1],
                ),
                LinkedPhoto(TEST_SECOND_IMAGE_ID, 'Photo without GPS'),
            ),
            None,
        )
        session = self._session(located_page, presentation, image_bytes)
        viewer = self._open_viewer(
            session,
            LinkedGallery(
                TEST_GALLERY_ID,
                TEST_PARENT_GALLERY_ID,
                'Native location regression',
            ),
        )
        self._wait_until(
            lambda: bool(viewer.grid_canvas.winfo_viewable()),
            'The photo viewport did not map before thumbnail admission.',
        )
        viewer._on_grid_viewport(None)
        content_geometry = dict(viewer._main_content.pack_info())
        self._wait_until(
            lambda: len(viewer._tile_buttons) == 2
            and viewer._tile_buttons[0].cget('image') != '',
            'The located photo tile did not finish rendering.',
        )
        self._double_click_tile(viewer._tile_buttons[0])
        self._wait_until(
            lambda: viewer._lightbox is not None
            and viewer._lightbox._photo is not None
            and viewer._lightbox._map_button.instate(('!disabled',)),
            'The admitted photo pin did not appear in the lightbox.',
        )
        self.assertEqual('', viewer._main_content.winfo_manager())
        lightbox = viewer._lightbox
        self.assertFalse(lightbox._location_panel.winfo_manager())
        lightbox._map_button.invoke()
        self._assert_no_browsing_toplevels()
        self.assertTrue(lightbox._location_panel.winfo_manager())
        self.assertIn('48.12346', lightbox._location_label.cget('text'))
        self.assertIn('16.65432', lightbox._location_label.cget('text'))
        with mock.patch.object(
            gallery_lightbox.webbrowser, 'open', return_value=True,
        ) as opened:
            opened.assert_not_called()
            lightbox._map_action.invoke()
        opened.assert_called_once_with(
            'https://www.openstreetmap.org/?mlat=48.123456&mlon=16.654321'
            '#map=15/48.123456/16.654321'
        )
        self.assertTrue(lightbox._location_panel.winfo_manager())
        lightbox._map_action.focus_force()
        self._wait_until(
            lambda: str(self.root.focus_get()) == str(lightbox._map_action),
            'The map action did not receive focus before outside dismissal.',
        )
        lightbox._canvas.event_generate('<Button-1>', x=4, y=4)
        self.root.update()
        self.assertFalse(lightbox._location_panel.winfo_manager())
        self._wait_until(
            lambda: str(self.root.focus_get()) == str(lightbox._map_button),
            'Outside dismissal did not return focus to the map pin.',
        )
        lightbox._canvas.focus_force()
        lightbox._canvas.event_generate('<KeyPress-Right>')
        self._wait_until(
            lambda: viewer._slide_index == 1
            and lightbox._photo is not None
            and lightbox._map_button.instate(('disabled',)),
            'Selecting a photo without GPS retained the prior pin.',
        )
        self.assertFalse(lightbox._location_panel.winfo_manager())
        lightbox._canvas.event_generate('<KeyPress-Left>')
        self._wait_until(
            lambda: viewer._slide_index == 0
            and lightbox._photo is not None
            and lightbox._map_button.instate(('!disabled',)),
            'Returning to the located photo did not restore its admitted pin.',
        )
        lightbox._map_button.invoke()
        lightbox._map_action.focus_force()
        self._wait_until(
            lambda: str(self.root.focus_get()) == str(lightbox._map_action),
            'The map action did not receive focus before Escape dismissal.',
        )
        viewer.window.event_generate('<Escape>')
        self.root.update()
        self.assertFalse(lightbox._location_panel.winfo_manager())
        self._wait_until(
            lambda: str(self.root.focus_get()) == str(lightbox._map_button),
            'Escape dismissal did not return focus to the map pin.',
        )
        self.assertFalse(viewer._closed)
        lightbox._canvas.focus_force()
        self._wait_until(
            lambda: str(self.root.focus_get()) == str(lightbox._canvas),
            'The lightbox canvas did not receive focus before fullscreen Escape.',
        )
        lightbox._map_button.invoke()
        self.assertTrue(lightbox._location_panel.winfo_manager())
        lightbox._canvas.event_generate('<KeyPress-F11>')
        self._wait_until(
            lambda: self._fullscreen_enabled(viewer.window),
            'The viewer window manager did not enter fullscreen.',
        )
        controls = (lightbox._toolbar, lightbox._previous_edge, lightbox._next_edge)
        self.assertTrue(all(not widget.winfo_manager() for widget in controls))
        self.assertFalse(lightbox._location_panel.winfo_manager())
        lightbox._on_pointer_motion(mock.Mock())
        self.assertTrue(all(widget.winfo_manager() == 'place' for widget in controls))
        lightbox._toggle_location()
        self.assertTrue(lightbox._location_panel.winfo_manager())
        self._assert_no_browsing_toplevels()
        with mock.patch.object(
            gallery_lightbox.webbrowser, 'open', return_value=True,
        ) as opened:
            lightbox._map_action.invoke()
        opened.assert_called_once_with(
            'https://www.openstreetmap.org/?mlat=48.123456&mlon=16.654321'
            '#map=15/48.123456/16.654321'
        )
        lightbox._canvas.focus_force()
        self._wait_until(
            lambda: str(self.root.focus_get()) == str(lightbox._canvas),
            'The lightbox canvas lost focus before fullscreen Escape.',
        )
        lightbox._canvas.event_generate('<KeyPress-Escape>')
        self.assertTrue(self._fullscreen_enabled(viewer.window))
        self.assertTrue(lightbox.visible)
        self.assertFalse(lightbox._location_panel.winfo_manager())
        lightbox._canvas.focus_force()
        self._wait_until(
            lambda: self.root.focus_get() is lightbox._canvas,
            'The fullscreen canvas did not regain focus after closing the map.',
        )
        lightbox._canvas.event_generate('<KeyPress-Escape>')
        self._wait_until(
            lambda: not self._fullscreen_enabled(viewer.window)
            and lightbox.visible,
            'Fullscreen Escape did not restore the lightbox window state.',
        )
        self.assertTrue(all(widget.winfo_manager() == 'place' for widget in controls))
        lightbox._canvas.focus_force()
        self._wait_until(
            lambda: str(self.root.focus_get()) == str(lightbox._canvas),
            'The lightbox canvas did not receive focus before overlay Escape.',
        )
        lightbox._canvas.event_generate('<KeyPress-Escape>')
        self.root.update()
        self.assertFalse(lightbox.visible)
        self.assertFalse(viewer._closed)
        self._wait_until(
            lambda: str(self.root.focus_get()) == str(viewer._tile_buttons[0]),
            'Lightbox dismissal did not return focus to the selected grid tile.',
        )
        self._wait_until(
            lambda: viewer._main_content.winfo_manager() == 'pack'
            and dict(viewer._main_content.pack_info()) == content_geometry,
            'Lightbox dismissal did not remap the original main content layout.',
        )
        viewer.window.event_generate('<Escape>')
        self.root.update()
        self.assertFalse(viewer._closed)
        self.assertTrue(viewer.frame.winfo_exists())

    def test_grid_tile_return_opens_lightbox(self) -> None:
        """Open a photo from the grid with its native Return key binding.

        Returns:
            None: Keyboard activation opens detail and Escape returns to the grid.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session,
            LinkedGallery(
                TEST_GALLERY_ID,
                TEST_PARENT_GALLERY_ID,
                'Native keyboard regression',
            ),
        )
        self._wait_until(
            lambda: len(viewer._tile_buttons) == 2
            and viewer._tile_buttons[0].cget('image') != '',
            'The keyboard target photo tile did not finish rendering.',
        )
        viewer._tile_buttons[0].focus_force()
        viewer._tile_buttons[0].event_generate('<KeyPress-Return>')
        viewer._tile_buttons[0].event_generate('<KeyRelease-Return>')
        self._wait_until(
            lambda: viewer._lightbox is not None
            and viewer._lightbox.visible
            and viewer._lightbox._photo is not None,
            'Return on a focused grid tile did not open its lightbox.',
        )
        viewer.window.event_generate('<Escape>')
        self.root.update()
        self.assertFalse(viewer._lightbox.visible)
        self.assertFalse(viewer._closed)
        self.assertEqual(
            str(viewer._tile_buttons[0]), str(self.root.focus_get()),
        )
        viewer.close()

    def test_lightbox_revocation_clears_the_photo_before_pending_publication(self) -> None:
        """Fence the mounted viewer's retained photo at the session boundary.

        Returns:
            None: Revocation clears an attached photo and any pending resize.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Pending raster grant'),
        )
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: len(viewer._tile_buttons) == len(page.photos)
            and viewer._worker is None,
            'The authorised viewer did not finish opening the gallery.',
        )
        viewer._open_grid_photo(0)
        self._wait_until(
            lambda: viewer._lightbox is not None and viewer._lightbox._photo is not None,
            'The authorised Lightbox did not attach its first photo.',
        )
        lightbox = viewer._lightbox
        assert lightbox is not None
        with mock.patch.object(
            lightbox, '_publish_raster_result', wraps=lightbox._publish_raster_result,
        ) as publish:
            lightbox._zoom = native_gallery_viewer.LIGHTBOX_ZOOM_STEP
            lightbox._render_image()
            self.assertTrue(lightbox._raster_request_inflight)
            session.can_read_media.return_value = False
            self._wait_until(
                lambda: lightbox._photo is None and not lightbox.visible,
                'The old photo remained attached after withdrawal of access.',
            )
            publish.assert_not_called()

    def test_lightbox_f_fullscreen_keys_and_command_text_guards(self) -> None:
        """Toggle fullscreen once from the focused overlay and preserve text.

        Returns:
            None: F, shifted F and F11 work; modifiers and text inputs do not.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Fullscreen keys'),
        )
        self._wait_until(
            lambda: len(viewer._tile_buttons) == 2 and viewer._worker is None,
            'Grid tiles did not finish before fullscreen-key checks.',
        )
        viewer._open_grid_photo(0)
        self._wait_until(
            lambda: viewer._lightbox is not None and viewer._lightbox.visible
            and viewer._lightbox._photo is not None,
            'The test could not activate the lightbox overlay.',
        )
        lightbox = viewer._lightbox
        assert lightbox is not None
        observations: list[str] = []
        viewer._observation_callback = observations.append
        lightbox._canvas.focus_force()
        lightbox._canvas.event_generate('<KeyPress-f>')
        self._wait_until(
            lambda: lightbox._fullscreen,
            'Focused lowercase f did not enter fullscreen.',
        )
        lightbox._canvas.event_generate('<KeyPress-F>')
        self._wait_until(
            lambda: not lightbox._fullscreen,
            'Focused uppercase F did not leave fullscreen.',
        )
        lightbox._canvas.event_generate('<KeyPress-F11>')
        self._wait_until(
            lambda: lightbox._fullscreen,
            'Focused F11 did not enter fullscreen.',
        )
        # Windows maps NumLock to Mod1 and Alt to Mod2; other Tk backends
        # retain their command masks. Four lock toggles preserve fullscreen.
        lock_states = (
            (0x0008, 0x0020, 0x0002, 0x002B)
            if sys.platform == 'win32' else (0, 0x0002, 0x0001, 0x0003)
        )
        command_states = (
            (0x0004, 0x0010, 0x20000)
            if sys.platform == 'win32' else (0x0004, 0x0008, 0x0040)
        )
        for state in lock_states:
            before = lightbox._fullscreen
            lightbox._canvas.event_generate(
                '<KeyPress-f>', state=state,
            )
            self._wait_until(
                lambda before=before: lightbox._fullscreen is not before,
                f'Lock-state bits 0x{state:x} blocked the F shortcut.',
            )
        for state in command_states:
            before = lightbox._fullscreen
            lightbox._canvas.event_generate(
                '<KeyPress-f>', state=state,
            )
            self.root.update()
            self.assertEqual(before, lightbox._fullscreen)
        if sys.platform == 'win32':
            self.assertTrue(any(
                summary.startswith('shortcut key=f state=0x8')
                and 'reason=toggled' in summary
                for summary in observations
            ))
        self.assertTrue(any(
            'reason=command-modifier' in summary
            for summary in observations
        ))
        command_event = mock.Mock()
        command_event.widget = lightbox._canvas
        command_event.state = 0x0004
        self.assertIsNone(viewer._on_fullscreen(command_event))
        self.assertTrue(lightbox._fullscreen)
        entry = ttk.Entry(lightbox._overlay)
        entry.place(x=8, y=8)
        entry.insert(0, 'draft')
        viewer._bind_lightbox_shortcuts(lightbox._overlay)
        self.root.update_idletasks()
        entry.focus_force()
        self._wait_until(
            lambda: self.root.focus_get() is entry,
            'The text entry did not receive focus before the key event.',
        )
        entry.event_generate('<KeyPress-f>')
        self.root.update()
        self.assertTrue(lightbox._fullscreen)
        self.assertEqual('draftf', entry.get())
        self.assertTrue(any(
            'widget=text-entry' in summary and 'reason=text-input' in summary
            for summary in observations
        ))
        text_event = mock.Mock()
        text_event.widget = entry
        text_event.state = 0
        self.assertIsNone(viewer._on_fullscreen(text_event))
        self.assertTrue(lightbox._fullscreen)
        self.assertEqual('draftf', entry.get())
        entry.destroy()
        lightbox._canvas.event_generate('<KeyPress-Escape>')
        self._wait_until(
            lambda: not lightbox._fullscreen and lightbox.visible,
            'Escape did not leave fullscreen without dismissing the overlay.',
        )
        viewer.close()

    def test_grid_single_click_selects_and_double_click_opens_lightbox(self) -> None:
        """Keep ordinary grid selection separate from detail loading.

        Returns:
            None: One click requests no full photo; explicit double-click opens it.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Click behavior'),
        )
        self._wait_until(
            lambda: len(viewer._tile_buttons) == len(page.photos)
            and viewer._worker is None,
            'The initial grid thumbnails did not finish before click checks.',
        )
        initial_reads = session.media_photo.call_count
        viewer._tile_buttons[0].event_generate(
            '<ButtonPress-1>', x=4, y=4, time=TEST_SINGLE_CLICK_TIME_MS,
        )
        viewer._tile_buttons[0].event_generate(
            '<ButtonRelease-1>', x=4, y=4, time=TEST_SINGLE_CLICK_TIME_MS,
        )
        self.root.update()
        self.assertEqual((TEST_FIRST_IMAGE_ID,), viewer.selected_photo_ids())
        self.assertIsNone(viewer._lightbox)
        self.assertEqual(initial_reads, session.media_photo.call_count)
        self.assertTrue(all(
            call.kwargs.get('thumbnail') is True
            for call in session.media_photo.call_args_list
        ))

        for click_time in TEST_DOUBLE_CLICK_TIMES_MS:
            viewer._tile_buttons[0].event_generate(
                '<ButtonPress-1>', x=4, y=4, time=click_time,
            )
            viewer._tile_buttons[0].event_generate(
                '<ButtonRelease-1>', x=4, y=4, time=click_time,
            )
        self._wait_until(
            lambda: viewer._lightbox is not None
            and viewer._lightbox.visible
            and viewer._lightbox._photo is not None,
            'Explicit double-click did not open the selected full photo.',
        )
        self.assertTrue(any(
            call.kwargs.get('thumbnail') is False
            for call in session.media_photo.call_args_list
        ))
        viewer.close()

    def test_grid_modifier_and_keyboard_selection_preserve_loaded_ids(self) -> None:
        """Exercise Ctrl, Shift, Space and modified-arrow selection behavior.

        Returns:
            None: Modifier actions stay in the grid and preserve the anchor set.
        """
        page, presentation, image_bytes = self._gallery_data()
        third = LinkedPhoto(TEST_NEXT_PAGE_IMAGE_ID, 'Third selection photo')
        page = LinkedMediaPage(
            page.gallery_id, page.parent_id, page.photos + (third,), None,
        )
        image_bytes[third.image_id] = self._png((90, 80, 70))
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Modifiers'),
        )
        self._wait_until(
            lambda: len(viewer._tile_buttons) == 3 and viewer._worker is None,
            'All three selection tiles did not finish rendering.',
        )
        viewer._tile_buttons[0].event_generate('<Button-1>')
        viewer._tile_buttons[2].event_generate('<Shift-Button-1>')
        self.root.update()
        self.assertEqual(
            (TEST_FIRST_IMAGE_ID, TEST_SECOND_IMAGE_ID, TEST_NEXT_PAGE_IMAGE_ID),
            viewer.selected_photo_ids(),
        )
        viewer._tile_buttons[1].event_generate('<Control-Button-1>')
        self.root.update()
        self.assertEqual(
            (TEST_FIRST_IMAGE_ID, TEST_NEXT_PAGE_IMAGE_ID),
            viewer.selected_photo_ids(),
        )
        viewer._tile_buttons[1].focus_force()
        viewer._tile_buttons[1].event_generate('<KeyPress-space>')
        self.root.update()
        self.assertEqual(
            (TEST_FIRST_IMAGE_ID, TEST_SECOND_IMAGE_ID, TEST_NEXT_PAGE_IMAGE_ID),
            viewer.selected_photo_ids(),
        )
        event = mock.Mock()
        event.keysym = 'Right'
        event.state = 0x0004
        self.assertEqual('break', viewer._tile_key(0, event))
        self.assertEqual(
            (TEST_FIRST_IMAGE_ID, TEST_SECOND_IMAGE_ID, TEST_NEXT_PAGE_IMAGE_ID),
            viewer.selected_photo_ids(),
        )
        self.assertEqual(
            str(viewer._tile_buttons[1]), str(self.root.focus_get()),
        )
        self.assertIsNone(viewer._lightbox)
        viewer.close()

    def test_shift_range_refuses_more_than_delete_batch_limit(self) -> None:
        """Refuse an oversized range without changing selected identities.

        Returns:
            None: The existing deletion cap also bounds visual range selection.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session, LinkedGallery(TEST_GALLERY_ID, None, 'Range limit'),
        )
        self._wait_until(
            lambda: viewer._worker is None,
            'The selection viewer did not become idle.',
        )
        viewer._photos = tuple(
            LinkedPhoto(image_id, f'Range item {image_id}')
            for image_id in range(
                1, NATIVE_MEDIA_DELETE_BATCH_MAX + 2,
            )
        )
        viewer._selected_photo_ids = {1}
        viewer._selection_anchor_index = 0
        viewer._focus_tile = mock.Mock()
        viewer._render_photo_selection = mock.Mock()
        self.assertFalse(viewer.select_photo_range(NATIVE_MEDIA_DELETE_BATCH_MAX))
        self.assertEqual({1}, viewer._selected_photo_ids)
        self.assertIn(
            str(NATIVE_MEDIA_DELETE_BATCH_MAX), viewer.status.cget('text'),
        )
        viewer.close()

    def test_rapid_detail_selection_discards_stale_result(self) -> None:
        """Keep the newest selected photo after an earlier read completes.

        Returns:
            None: The delayed first result cannot replace the second selection
                or enter its detail cache. Adjacent prefetch is observed at its
                enqueue boundary; its transport has separate native coverage.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        first_detail_started = threading.Event()
        release_first_detail = threading.Event()
        self.addCleanup(release_first_detail.set)

        def read_photo(
            gallery_id: int,
            image_id: int,
            thumbnail: bool,
            cancelled: threading.Event | None = None,
            *,
            cache_eligible: bool | None = None,
            thumbnail_etag: str | None = None,
            thumbnail_verified_at: float | None = None,
            preload_eligible: bool | None = None,
            preload_thumbnail_etag: str | None = None,
            preload_verified_at: float | None = None,
            timings: MediaReadTimings | None = None,
            use_preloaded_snapshot: bool = False,
        ) -> tuple[bytes, str]:
            """Hold the first detail response while a newer selection arrives.

            Args:
                gallery_id (int): Physical gallery requested by the viewer.
                image_id (int): Requested photo identity.
                thumbnail (bool): Whether this request is a grid thumbnail.
                cancelled (threading.Event | None): Viewer cancellation signal.
                cache_eligible (bool | None): Authorized public cache policy.
                thumbnail_etag (str | None): Current thumbnail validator.
                thumbnail_verified_at (float | None): Catalogue validation time.
                preload_eligible (bool | None): Current Admin preload admission.
                preload_thumbnail_etag (str | None): Admin thumbnail validator.
                preload_verified_at (float | None): Admin proof time.
                timings (MediaReadTimings | None): Optional read timing record.
                use_preloaded_snapshot (bool): Full-read saved-snapshot request.
            Returns:
                tuple[bytes, str]: Fixture PNG body with its image media type.
            """
            if cancelled is not None and cancelled.is_set():
                raise RuntimeError('Synthetic media read cancelled')
            if gallery_id != TEST_GALLERY_ID or image_id not in image_bytes:
                raise ValueError('Unexpected media identity in race fixture')
            if not thumbnail and image_id == TEST_FIRST_IMAGE_ID:
                first_detail_started.set()
                if not release_first_detail.wait(TEST_GUI_WAIT_SECONDS):
                    raise TimeoutError('The test did not release its first detail')
            return image_bytes[image_id], 'image/png'

        session.media_photo.side_effect = read_photo
        viewer = self._open_viewer(
            session,
            LinkedGallery(
                TEST_GALLERY_ID,
                TEST_PARENT_GALLERY_ID,
                'Native detail race regression',
            ),
        )
        self._wait_until(
            lambda: len(viewer._tile_buttons) == 2
            and all(viewer._photo_images)
            and viewer._worker is None,
            'The grid was not ready before the detail race started.',
        )
        # Isolate foreground race requests from legitimate neighbor prefetch.
        # The adjacent-prefetch fixture checks that independent transport path.
        viewer._queue_adjacent_prefetch = mock.Mock()
        viewer._select_photo(0)
        self.assertTrue(first_detail_started.wait(TEST_GUI_WAIT_SECONDS))
        viewer._select_photo(1)
        self.assertIsNone(viewer._lightbox._photo)
        release_first_detail.set()
        self._wait_until(
            lambda: viewer._lightbox._photo is not None
            and viewer._slide_index == 1
            and ImageTk.getimage(viewer._lightbox._photo).getpixel((0, 0))
            == (20, 190, 95, 255),
            'A stale detail result replaced the latest selected photo.',
        )
        detail_ids = [
            call.args[1]
            for call in session.media_photo.call_args_list
            if call.kwargs['thumbnail'] is False
        ]
        self.assertEqual(
            [TEST_FIRST_IMAGE_ID, TEST_SECOND_IMAGE_ID], detail_ids,
        )
        viewer._queue_adjacent_prefetch.assert_called_once_with(1)
        self.assertNotIn(TEST_FIRST_IMAGE_ID, viewer._detail_cache)
        viewer.close()

    def test_escape_during_page_read_keeps_refreshed_grid(self) -> None:
        """Keep a late page response from reopening a dismissed lightbox.

        Returns:
            None: The fetched grid remains usable after Escape during navigation.
        """
        page, presentation, image_bytes = self._gallery_data()
        first_page = LinkedMediaPage(
            page.gallery_id,
            page.parent_id,
            page.photos,
            TEST_SECOND_IMAGE_ID,
        )
        next_page = LinkedMediaPage(
            page.gallery_id,
            page.parent_id,
            (LinkedPhoto(TEST_NEXT_PAGE_IMAGE_ID, 'Late page photo'),),
            None,
        )
        image_bytes[TEST_NEXT_PAGE_IMAGE_ID] = self._png((50, 70, 240))
        session = self._session(first_page, presentation, image_bytes)
        page_read_started = threading.Event()
        release_page_read = threading.Event()
        self.addCleanup(release_page_read.set)

        def read_page(
            gallery_id: int,
            after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> LinkedMediaPage:
            """Block the continuation until Escape dismisses its lightbox.

            Args:
                gallery_id (int): Physical gallery requested by the viewer.
                after_id (int | None): Exclusive cursor or initial-page marker.
                cancelled (threading.Event | None): Viewer cancellation signal.
            Returns:
                LinkedMediaPage: First page or the gated continuation page.
            """
            if cancelled is not None and cancelled.is_set():
                raise RuntimeError('Synthetic page read cancelled')
            if gallery_id != TEST_GALLERY_ID:
                raise ValueError('Unexpected gallery in native UI fixture')
            if after_id is None:
                return first_page
            if after_id == TEST_SECOND_IMAGE_ID:
                page_read_started.set()
                if not release_page_read.wait(TEST_GUI_WAIT_SECONDS):
                    raise TimeoutError('The test did not release the page reply')
                return next_page
            raise ValueError('Unexpected media page cursor')

        session.media_page.side_effect = read_page
        viewer = self._open_viewer(
            session,
            LinkedGallery(
                TEST_GALLERY_ID,
                TEST_PARENT_GALLERY_ID,
                'Native late-page regression',
            ),
        )
        self._wait_until(
            lambda: len(viewer._tile_buttons) == len(first_page.photos),
            'The first photo tiles did not mount before continuation.',
        )
        self._wait_until(
            page_read_started.is_set,
            'The underfilled viewport did not request its next metadata page.',
        )
        self._double_click_tile(viewer._tile_buttons[-1])
        lightbox = viewer._lightbox
        self.assertIsNotNone(lightbox)
        self._wait_until(
            lambda: lightbox.visible,
            'Selecting a tile during continuation did not open its detail overlay.',
        )
        lightbox._canvas.focus_force()
        self._wait_until(
            lambda: self.root.focus_get() is lightbox._canvas,
            'The loading lightbox did not receive keyboard focus.',
        )
        lightbox._canvas.event_generate('<KeyPress-Escape>')
        self._wait_until(
            lambda: not lightbox.visible,
            'Escape did not dismiss the focused lightbox during continuation.',
        )
        self.assertFalse(viewer._closed)
        release_page_read.set()
        self._wait_until(
            lambda: viewer._photos == first_page.photos + next_page.photos
            and viewer._tile_buttons[0].cget('image') != ''
            and not lightbox.visible,
            'The late page reply reopened the dismissed lightbox.',
        )
        self._double_click_tile(viewer._tile_buttons[0])
        self._wait_until(
            lambda: lightbox.visible and lightbox._photo is not None,
            'The refreshed grid tile could not open a new detail lightbox.',
        )
        self.assertEqual(0, viewer._slide_index)
        viewer.close()

    def test_slideshow_crossing_page_keeps_advancing(self) -> None:
        """Open the next page's first photo and retain the slideshow timer.

        Returns:
            None: Automatic page continuation renders and schedules another step.
        """
        page, presentation, image_bytes = self._gallery_data()
        first_page = LinkedMediaPage(
            page.gallery_id,
            page.parent_id,
            page.photos,
            TEST_SECOND_IMAGE_ID,
        )
        next_page = LinkedMediaPage(
            page.gallery_id,
            page.parent_id,
            (LinkedPhoto(TEST_NEXT_PAGE_IMAGE_ID, 'Slideshow continuation'),),
            None,
        )
        image_bytes[TEST_NEXT_PAGE_IMAGE_ID] = self._png((50, 70, 240))
        session = self._session(first_page, presentation, image_bytes)

        def read_page(
            gallery_id: int,
            after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> LinkedMediaPage:
            """Return a page for the slideshow's captured cursor.

            Args:
                gallery_id (int): Physical gallery requested by the viewer.
                after_id (int | None): Exclusive cursor or initial-page marker.
                cancelled (threading.Event | None): Viewer cancellation signal.
            Returns:
                LinkedMediaPage: First page or its slideshow continuation.
            """
            if cancelled is not None and cancelled.is_set():
                raise RuntimeError('Synthetic page read cancelled')
            if gallery_id != TEST_GALLERY_ID:
                raise ValueError('Unexpected gallery in native UI fixture')
            if after_id is None:
                return first_page
            if after_id == TEST_SECOND_IMAGE_ID:
                return next_page
            raise ValueError('Unexpected media page cursor')

        session.media_page.side_effect = read_page
        viewer = self._open_viewer(
            session,
            LinkedGallery(
                TEST_GALLERY_ID,
                TEST_PARENT_GALLERY_ID,
                'Native slideshow continuation regression',
            ),
        )
        self._wait_until(
            lambda: viewer._photos == first_page.photos + next_page.photos
            and all(viewer._photo_images),
            'The continuous slideshow album did not finish rendering.',
        )
        self._double_click_tile(
            viewer._tile_buttons[len(first_page.photos) - 1],
        )
        self._wait_until(
            lambda: viewer._lightbox is not None
            and viewer._lightbox._photo is not None
            and viewer._slide_index == len(first_page.photos) - 1,
            'The last first-page photo did not open for the slideshow.',
        )
        viewer.slide_button.invoke()
        self._wait_until(
            lambda: viewer._photos == first_page.photos + next_page.photos
            and viewer._slide_index == len(first_page.photos)
            and viewer._lightbox._photo is not None
            and ImageTk.getimage(viewer._lightbox._photo).getpixel((0, 0))
            == (50, 70, 240, 255)
            and viewer._slideshow_running
            and viewer._slideshow_id is not None,
            'The slideshow page crossing did not render and schedule the appended photo.',
        )
        self.assertEqual(
            TEST_NEXT_PAGE_IMAGE_ID,
            viewer._photos[viewer._slide_index].image_id,
        )
        viewer.slide_button.invoke()
        self.assertFalse(viewer._slideshow_running)
        self.assertIsNone(viewer._slideshow_id)
        viewer.close()

    def test_adjacent_detail_prefetch_makes_arrow_navigation_immediate(self) -> None:
        """Keep the current frame visible while publishing a prefetched neighbor.

        Returns:
            None: Selection advances immediately and the new authorized pixels
                reuse the same Canvas item without another foreground request.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session,
            LinkedGallery(
                TEST_GALLERY_ID,
                TEST_PARENT_GALLERY_ID,
                'Adjacent prefetch regression',
            ),
        )
        self._wait_until(
            lambda: len(viewer._tile_buttons) == 2
            and all(viewer._photo_images)
            and viewer._worker is None,
            'The photo tiles did not finish before detail selection.',
        )
        viewer._select_photo(0)
        self._wait_until(
            lambda: viewer._lightbox is not None
            and viewer._lightbox._photo is not None
            and viewer._worker is None
            and TEST_SECOND_IMAGE_ID in viewer._detail_cache,
            'The selected detail and its next-photo prefetch did not finish.',
        )
        detail_ids = [
            call.args[1]
            for call in session.media_photo.call_args_list
            if call.kwargs['thumbnail'] is False
        ]
        self.assertEqual(
            [TEST_FIRST_IMAGE_ID, TEST_SECOND_IMAGE_ID], detail_ids,
        )

        lightbox = viewer._lightbox
        assert lightbox is not None
        previous = lightbox._photo
        retained_item = lightbox._photo_item_id
        viewer._move_lightbox(1)

        self.assertEqual(
            TEST_SECOND_IMAGE_ID,
            viewer._photos[viewer._slide_index].image_id,
        )
        self.assertIs(previous, lightbox._photo)
        self._wait_until(
            lambda: lightbox._photo is not None
            and ImageTk.getimage(lightbox._photo).getpixel((0, 0))
            == (20, 190, 95, 255),
            'The prefetched neighbor did not publish its pixels.',
        )
        self.assertEqual(retained_item, lightbox._photo_item_id)
        self.assertEqual(
            detail_ids,
            [
                call.args[1]
                for call in session.media_photo.call_args_list
                if call.kwargs['thumbnail'] is False
            ],
        )
        viewer.close()

    def test_silent_wav_playback_releases_owned_file(self) -> None:
        """Exercise real WinMM playback when the runner exposes an output device.

        Returns:
            None: Native playback starts and closes its matching owned file;
                a runner with no audio output reports that platform gap.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Silent playback')
        viewer = self._open_viewer(session, gallery)
        viewer._load_winmm()
        devices = viewer._winmm.waveOutGetNumDevs
        devices.argtypes = []
        devices.restype = ctypes.c_uint
        if devices() == 0:
            viewer.close()
            self.skipTest('Windows runner exposes no native wave output device.')

        body = io.BytesIO()
        with wave.open(body, 'wb') as audio:
            # One mono 16-bit PCM silence sample is two zero bytes.
            audio.setnchannels(1)
            audio.setsampwidth(2)
            audio.setframerate(TEST_WAV_RATE_HZ)
            audio.writeframes(b'\x00\x00' * TEST_WAV_RATE_HZ)
        payload = body.getvalue()
        track = LinkedTrack(
            'a' * 32, 'Silent fixture', 'audio/wav', len(payload),
            hashlib.sha256(payload).hexdigest(),
        )
        viewer._tracks = (track,)
        viewer._audio_index = 0
        owner = threading.Event()
        viewer._audio_cancelled = owner
        viewer._accept_audio_track(owner, 0, payload)
        self.assertIsNotNone(viewer._audio_alias)
        self.assertIsNotNone(viewer._audio_path)
        owned = Path(viewer._audio_path)
        self.assertEqual(payload, owned.read_bytes())
        viewer.close()
        self.assertFalse(owned.exists())

    def test_fullscreen_music_pause_and_resume_keep_native_player(self) -> None:
        """Pause and resume the owned MCI player without replacing its file.

        Returns:
            None: Native commands retain one active track and one poll owner.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session,
            LinkedGallery(
                TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'MCI pause fixture',
            ),
        )
        viewer._audio_index = 0
        viewer._audio_alias = 'pglink-fixture'
        viewer._mci = mock.Mock(return_value=0)
        player = viewer._mci

        viewer._toggle_audio_pause()
        self.assertTrue(viewer._audio_paused)
        self.assertEqual('pause pglink-fixture', player.call_args.args[0])
        self.assertIsNone(viewer._audio_poll_id)

        viewer._toggle_audio_pause()
        self.assertFalse(viewer._audio_paused)
        self.assertEqual('resume pglink-fixture', player.call_args.args[0])
        self.assertIsNotNone(viewer._audio_poll_id)
        viewer.close()

    def test_macos_playlist_prefetches_only_next_and_plays_prepared_file(self) -> None:
        """Run the real viewer queue with a fixture-owned afplay process boundary.

        Returns:
            None: One prepared successor plays locally and Stop clears both files.
        """
        page, presentation, image_bytes = self._gallery_data()
        bodies = (b'first track', b'second track', b'third track')
        tracks = tuple(
            LinkedTrack(character * 32, title, 'audio/wav', len(body),
                        hashlib.sha256(body).hexdigest())
            for character, title, body in zip('abc', ('First', 'Second', 'Third'), bodies)
        )
        session = self._session(page, presentation, image_bytes)
        session.media_presentation.return_value = LinkedPresentation(
            enabled=True, autoplay=False,
            interval_seconds=TEST_PRESENTATION_INTERVAL_SECONDS,
            transition='medium', music=True, tracks=tracks,
        )
        session.media_track.side_effect = bodies
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Local playback')
        viewer = self._open_viewer(session, gallery)
        first, second = mock.Mock(), mock.Mock()
        first.poll.return_value = second.poll.return_value = None
        with (
            mock.patch.object(native_gallery_viewer, 'sys', mock.Mock(platform='darwin')),
            mock.patch.object(
                native_gallery_viewer.subprocess, 'Popen', side_effect=[first, second],
            ) as launch,
        ):
            self._wait_until(lambda: viewer._tracks == tracks, 'Playlist was not loaded.')
            session.media_track.assert_not_called()
            launch.assert_not_called()
            viewer._toggle_track()
            self._wait_until(
                lambda: viewer._audio_prepared is not None,
                'The immediate successor was not prepared during playback.',
            )
            owned_first = Path(viewer._audio_path)
            _, prepared = viewer._audio_prepared
            self.assertEqual(bodies[0], owned_first.read_bytes())
            self.assertEqual(bodies[1], Path(prepared).read_bytes())
            self.assertEqual(
                [tracks[0], tracks[1]],
                [call.args[1] for call in session.media_track.call_args_list],
            )
            launch.assert_called_once()
            first.poll.return_value = 0
            self._wait_until(
                lambda: viewer._audio_path == prepared
                and viewer._audio_prepared is not None,
                'The prepared successor did not start or its successor was not ready.',
            )
            self.assertFalse(owned_first.exists())
            self.assertEqual(['/usr/bin/afplay', prepared], launch.call_args.args[0])
            _, prepared_third = viewer._audio_prepared
            self.assertEqual(bodies[2], Path(prepared_third).read_bytes())
            viewer._toggle_track()
            second.kill.assert_called_once()
            self.assertFalse(Path(prepared).exists())
            self.assertFalse(Path(prepared_third).exists())
        viewer.close()

    def test_offline_successor_leaves_current_track_playing_until_handoff(self) -> None:
        """Distinguish speculative offline failure from the selected track failing.

        Returns:
            None: The first player survives until natural completion and a
                failed explicit transfer never launches another player.
        """
        page, presentation, image_bytes = self._gallery_data()
        body = b'local first track'
        tracks = tuple(
            LinkedTrack(character * 32, title, 'audio/wav', len(body),
                        hashlib.sha256(body).hexdigest())
            for character, title in zip('ab', ('First', 'Second'))
        )
        session = self._session(page, presentation, image_bytes)
        session.media_presentation.return_value = LinkedPresentation(
            enabled=True, autoplay=False,
            interval_seconds=TEST_PRESENTATION_INTERVAL_SECONDS,
            transition='medium', music=True, tracks=tracks,
        )
        session.media_track.side_effect = [body, OSError('Offline'), OSError('Offline')]
        gallery = LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Offline music')
        viewer = self._open_viewer(session, gallery)
        process = mock.Mock()
        process.poll.return_value = None
        with (
            mock.patch.object(native_gallery_viewer, 'sys', mock.Mock(platform='darwin')),
            mock.patch.object(
                native_gallery_viewer.subprocess, 'Popen', return_value=process,
            ) as launch,
        ):
            self._wait_until(lambda: viewer._tracks == tracks, 'Playlist was not loaded.')
            viewer.music_button.invoke()
            viewer.panel_track_button.invoke()
            self._wait_until(
                lambda: viewer._audio_successor_failed,
                'Offline successor did not reach the playlist handoff state.',
            )
            playing = Path(viewer._audio_path)
            self.assertEqual(body, playing.read_bytes())
            self.assertIs(process, viewer._audio_process)
            self.assertIsNone(viewer._audio_prepared)
            self.assertEqual('disabled', str(viewer.track_menu.cget('state')))
            self.assertEqual('■', str(viewer.panel_track_button.cget('text')))
            process.kill.assert_not_called()

            process.poll.return_value = 0
            self._wait_until(
                lambda: viewer._audio_index is None,
                'Natural completion did not surface the failed successor.',
            )
            self.assertFalse(playing.exists())
            self.assertEqual('Could not play this track.', str(viewer.status.cget('text')))
            self.assertEqual('readonly', str(viewer.track_menu.cget('state')))
            launch.assert_called_once()
            self.assertEqual(
                [tracks[0], tracks[1]],
                [call.args[1] for call in session.media_track.call_args_list],
            )

            viewer.track_menu.current(1)
            viewer.panel_track_button.invoke()
            self._wait_until(
                lambda: viewer._audio_index is None,
                'Failed explicit transfer did not terminate the playlist run.',
            )
            launch.assert_called_once()
            self.assertEqual(
                [tracks[0], tracks[1], tracks[1]],
                [call.args[1] for call in session.media_track.call_args_list],
            )
        viewer.close()

    def test_parent_destroy_cancels_pending_read(self) -> None:
        """Cancel an active media read when the owning root is destroyed.

        Returns:
            None: The child-frame destruction callback signals and joins its worker.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        worker_started = threading.Event()

        def wait_for_cancel(
            gallery_id: int,
            after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> LinkedMediaPage:
            """Hold the first page read until the viewer cancels it.

            Args:
                gallery_id (int): Gallery requested by the viewer.
                after_id (int | None): First-page cursor, expected to be None.
                cancelled (threading.Event | None): Viewer-owned cancellation.

            Returns:
                LinkedMediaPage: Synthetic page returned after observing cancel.
            """
            if gallery_id != TEST_GALLERY_ID or after_id is not None:
                raise ValueError('Unexpected page request in cancellation fixture')
            worker_started.set()
            if cancelled is None or not cancelled.wait(TEST_GUI_WAIT_SECONDS):
                raise TimeoutError('Viewer did not cancel its page read')
            return page

        session.media_page.side_effect = wait_for_cancel
        gallery = LinkedGallery(
            TEST_GALLERY_ID,
            TEST_PARENT_GALLERY_ID,
            'Pending native viewer',
        )
        viewer = self._open_viewer(session, gallery)
        self._wait_until(
            worker_started.is_set,
            'The synthetic page worker did not start.',
        )
        old_generation = viewer._generation
        self.root.destroy()
        self.assertTrue(viewer._closed)
        self.assertTrue(viewer._cancelled.is_set())
        self.assertGreater(viewer._generation, old_generation)
        self.assertIsNotNone(viewer._worker)
        viewer._worker.join(TEST_WORKER_JOIN_SECONDS)
        self.assertFalse(viewer._worker.is_alive())
        self.assertEqual(1, session.media_page.call_count)
        self.assertTrue(viewer._events.empty())



    def test_selection_stays_bound_to_loaded_photo_ids_across_refresh(self) -> None:
        """Keep explicit multi-selection tied to current server photo IDs.

        Returns:
            None: Refresh drops removed IDs while preserving selected survivors.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session,
            LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Selection'),
        )
        self._wait_until(
            lambda: len(viewer._tile_buttons) == 2 and viewer._worker is None,
            'The selectable photo tiles did not settle.',
        )
        self.assertTrue(viewer.toggle_photo_selection(TEST_FIRST_IMAGE_ID))
        self.assertTrue(viewer.toggle_photo_selection(TEST_SECOND_IMAGE_ID))
        self.assertEqual(
            (TEST_FIRST_IMAGE_ID, TEST_SECOND_IMAGE_ID),
            viewer.selected_photo_ids(),
        )
        self.assertEqual(
            'Selected.LinkedPhoto.TButton',
            viewer._tile_buttons[0].cget('style'),
        )
        third = LinkedPhoto(TEST_NEXT_PAGE_IMAGE_ID, 'Third photo')
        image_bytes[third.image_id] = self._png((90, 80, 70))
        session.media_page.return_value = LinkedMediaPage(
            page.gallery_id, page.parent_id, (page.photos[0], third), None,
        )
        viewer.refresh_photos()
        self._wait_until(
            lambda: viewer._worker is None and len(viewer._photos) == 2
            and viewer._photos[-1].image_id == third.image_id,
            'Selection refresh did not reconcile the updated photo IDs.',
        )
        self.assertEqual((TEST_FIRST_IMAGE_ID,), viewer.selected_photo_ids())
        viewer.clear_photo_selection((TEST_FIRST_IMAGE_ID,))
        self.assertEqual((), viewer.selected_photo_ids())
        viewer.close()

    def test_selected_tile_rim_tracks_rendered_photo_across_formats(self) -> None:
        """Keep real selected tiles snug and close at different viewport widths.

        Returns:
            None: Every rendered aspect, including natural Default, has only
                the shared rim outside its image at two viewport widths.
        """

        configure_theme(self.root)
        page, presentation, image_bytes = self._gallery_data()
        # The natural image exceeds the themed element's minimum artwork size.
        stream = io.BytesIO()
        Image.new('RGB', (180, 120), '#527184').save(stream, format='PNG')
        # Eight cards ensure a second row even at the wider viewport.
        photos = tuple(LinkedPhoto(TEST_FIRST_IMAGE_ID + index, 'Geometry')
                       for index in range(8))
        page = LinkedMediaPage(page.gallery_id, page.parent_id, photos, None)
        image_bytes = {photo.image_id: stream.getvalue() for photo in photos}
        session = self._session(page, presentation, image_bytes)
        viewer = self._open_viewer(
            session,
            LinkedGallery(TEST_GALLERY_ID, TEST_PARENT_GALLERY_ID, 'Geometry'),
        )
        self._wait_until(
            lambda: len(viewer._photo_images) == 8
            and all(image is not None for image in viewer._photo_images),
            'The synthetic thumbnails did not render.',
        )
        viewer.toggle_photo_selection(TEST_FIRST_IMAGE_ID)
        tile = viewer._tile_buttons[0]
        tile.focus_set()
        # Two widths leave different residual space after the fixed columns.
        for window_width in (840, 1031):
            self.root.geometry(f'{window_width}x620')
            self.root.update()
            for key in ('default', '16:9', '21:9', '4:3', '1:1', '4:5', '9:16'):
                with self.subTest(width=window_width, format=key):
                    viewer.set_card_format(key)
                    self.root.update_idletasks()
                    image = viewer._photo_images[0]
                    self.assertIsNotNone(image)
                    self.assertEqual('image', str(tile.cget('compound')))
                    self.assertEqual('', str(tile.cget('text')))
                    if key == '21:9':
                        self.assertEqual((144, 62), (image.width(), image.height()))
                        next_tile = viewer._tile_buttons[1]
                        next_row = viewer._tile_buttons[viewer._grid_columns]
                        self.assertEqual(
                            native_gallery_viewer.MEDIA_GRID_GUTTER,
                            next_tile.winfo_x() - tile.winfo_x() - tile.winfo_width(),
                        )
                        self.assertEqual(
                            native_gallery_viewer.MEDIA_GRID_GUTTER,
                            next_row.winfo_y() - tile.winfo_y() - tile.winfo_height(),
                        )
                    rim = 2 * native_gallery_viewer.GALLERY_TILE_BORDER_INSET
                    self.assertEqual(image.width() + rim, tile.winfo_width())
                    self.assertEqual(image.height() + rim, tile.winfo_height())
                    self.assertEqual('Selected.LinkedPhoto.TButton', tile.cget('style'))
                    self.assertEqual((TEST_FIRST_IMAGE_ID,), viewer.selected_photo_ids())
        viewer.close()

    def test_card_format_updates_in_place_and_rejects_unknown_key(self) -> None:
        """Reflow real geometry even when the number of columns stays unchanged.

        Returns:
            None: Short rows replace old tall rows, obsolete rows lose their
                height, selections remain intact and unknown choices do nothing.
        """
        viewer = native_gallery_viewer._NativeGalleryViewer.__new__(
            native_gallery_viewer._NativeGalleryViewer,
        )
        viewer._card_format = 'default'
        viewer._grid_columns = 2
        viewer.grid_canvas = mock.Mock()
        # A 400 px canvas keeps two columns in both Default and 16:9. The
        # third old row represents space left by an earlier larger page.
        viewer.grid_canvas.winfo_width.return_value = 400
        viewer._native_grid_width = viewer.grid_canvas.winfo_width.return_value
        viewer.grid_frame = mock.Mock()
        viewer.grid_frame.grid_size.return_value = (2, 3)
        viewer.grid_window = 'photo-grid'
        viewer._tile_buttons = [mock.Mock(), mock.Mock(), mock.Mock()]
        viewer._canonical_thumbnail_images = {}
        viewer._selected_photo_ids = {TEST_FIRST_IMAGE_ID}
        viewer._on_grid_viewport = mock.Mock()
        viewer.set_card_format('16:9')
        self.assertEqual('16:9', viewer._card_format)
        self.assertEqual(2, viewer._grid_columns)
        viewer.grid_canvas.itemconfigure.assert_not_called()
        viewer.grid_frame.grid_rowconfigure.assert_any_call(2, minsize=0)
        # 81 px is the exact integer height of a 144 px-wide 16:9 card.
        short_row = (
            81 + native_gallery_viewer.MEDIA_GRID_GUTTER
            + 2 * native_gallery_viewer.GALLERY_TILE_BORDER_INSET
        )
        viewer.grid_frame.grid_rowconfigure.assert_any_call(0, minsize=short_row)
        self.assertEqual({TEST_FIRST_IMAGE_ID}, viewer._selected_photo_ids)
        viewer.set_card_format('default')
        viewer.grid_frame.grid_rowconfigure.assert_any_call(
            0, minsize=native_gallery_viewer.MEDIA_GRID_ROW_MINIMUM_HEIGHT,
        )
        before = viewer.grid_canvas.itemconfigure.call_count
        viewer.set_card_format('wide-invalid')
        self.assertEqual('default', viewer._card_format)
        self.assertEqual(before, viewer.grid_canvas.itemconfigure.call_count)

    def test_card_dimensions_cover_landscape_portrait_and_default(self) -> None:
        """Bound every selected image aspect within the existing tile limit.

        Returns:
            None: Each format keeps at least one pixel per dimension.
        """
        viewer = native_gallery_viewer._NativeGalleryViewer.__new__(
            native_gallery_viewer._NativeGalleryViewer,
        )
        for format_key in ('default', '16:9', '21:9', '4:3', '1:1', '4:5', '9:16'):
            viewer._card_format = format_key
            width, height = viewer._card_dimensions()
            self.assertGreater(width, 0, format_key)
            self.assertGreater(height, 0, format_key)
            self.assertLessEqual(width, native_gallery_viewer.MEDIA_THUMBNAIL_SIZE[0])
            self.assertLessEqual(height, native_gallery_viewer.MEDIA_THUMBNAIL_SIZE[1])
        viewer._card_format = 'default'
        self.assertEqual(
            native_gallery_viewer.MEDIA_THUMBNAIL_SIZE,
            viewer._card_dimensions(),
        )

    def test_card_crop_preserves_default_and_crops_selected_preview(self) -> None:
        """Keep canonical pixels unchanged while deriving a selected crop.

        Returns:
            None: Default pixels match the source and 16:9 crops its height.
        """
        viewer = native_gallery_viewer._NativeGalleryViewer.__new__(
            native_gallery_viewer._NativeGalleryViewer,
        )
        # A 144 by 100 source with a ten-pixel red rim makes center-crop pixel
        # retention observable while fitting the production thumbnail bound.
        canonical = Image.new('RGB', (144, 100), color='black')
        ImageDraw.Draw(canonical).rectangle((0, 0, 143, 9), fill='red')
        viewer._card_format = 'default'
        natural = viewer._present_thumbnail(canonical)
        self.assertEqual(canonical.size, natural.size)
        self.assertEqual(canonical.tobytes(), natural.tobytes())
        natural.close()
        viewer._card_format = '16:9'
        cropped = viewer._present_thumbnail(canonical)
        self.assertEqual((144, 81), cropped.size)
        self.assertEqual(canonical.getpixel((72, 50)), cropped.getpixel((72, 40)))
        cropped.close()
        canonical.close()




