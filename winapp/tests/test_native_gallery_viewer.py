# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_native_gallery_viewer.py
# Module Type: Windows Tk Regression Test
# Purpose: Exercise the native linked-gallery viewer with real Tk widgets.
# Responsibilities:
#   - Verify grid tiles, bounded thumbnails, lightbox details and photo paging.
#   - Verify GPS actions, zoom, slideshow and foreground-result fencing.
#   - Verify closing the viewer or its parent cancels owned work.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Windows-only Tk regression coverage for the linked-gallery viewer."""

import ctypes
import gc
import hashlib
import io
import sys
import threading
import time
import unittest
import wave
from pathlib import Path
from typing import Callable, cast
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.app_linking import (
    LinkedGallery,
    LinkedGalleryPage,
    LinkedMediaPage,
    LinkedPhoto,
    LinkedPresentation,
    LinkedTrack,
    LinkingMediaUnavailable,
    LinkingSession,
)
from uploader.flight_report_translations import tr

try:
    import tkinter as tk
    from tkinter import ttk
except ModuleNotFoundError as exc:
    if exc.name not in ('tkinter', '_tkinter'):
        raise
    tk = None

try:
    from PIL import Image, ImageDraw, ImageTk
except ModuleNotFoundError as exc:
    if exc.name != 'PIL':
        raise
    Image = None
    ImageTk = None

if sys.platform == 'win32' and tk is not None and Image is not None:
    from uploader import gallery_lightbox, native_gallery_viewer, photos_workspace
else:
    gallery_lightbox = None
    native_gallery_viewer = None
    photos_workspace = None


# Purpose: Bound polling for one real Tk worker result in native CI.
# Type: float. Units: seconds. Scope: one test's page/detail readiness wait.
# Consumers: NativeGalleryViewerTests._wait_until.
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

# Purpose: Keep enough rows to measure mapped grid movement in pixels.
# Type: int. Units: unique photos. Scope: mapped scroll test.
# Consumers:
#   NativeGalleryViewerTests.test_real_touchpad_packet_scrolls_grid_and_zoom.
# Rationale: Twenty-four photos exceed the viewport at the minimum test size.
TEST_SCROLL_PHOTO_COUNT = 24

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


@unittest.skipUnless(
    sys.platform == 'win32' and tk is not None and Image is not None
    and ImageTk is not None,
    'The native viewer regression requires Windows, Tk and Pillow.',
)
class NativeGalleryViewerTests(unittest.TestCase):
    """Exercise mounted Tk frames and synthetic media responses."""

    def setUp(self) -> None:
        """Create a real Tk root with a high-DPI test scale.

        Returns:
            None: A mapped root is available to each native viewer test.
        """
        self.root = tk.Tk()
        self.root.tk.call('tk', 'scaling', TEST_TK_SCALING)
        self.root.geometry('840x620')
        self.root.update()
        self.addCleanup(self._destroy_root)

    def _destroy_root(self) -> None:
        """Destroy the test root and collect Tk-owned cycles on its thread.

        Returns:
            None: Remaining Tcl variables finalize on the Tk owner thread.
        """
        try:
            if self.root.winfo_exists():
                self.root.destroy()
        except tk.TclError:
            pass
        finally:
            gc.collect()

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

    def test_smooth_icon_button_updates_text_state_and_arrow_direction(self) -> None:
        """Keep accessible text while redrawing dynamic and disabled icons.

        Returns:
            None: Play-to-pause updates the image and directional artwork differs.
        """
        from uploader import ui_theme

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
        left_points = [
            x for y in range(left_pixels.height)
            for x in range(left_pixels.width)
            if left_pixels.getpixel((x, y))[3] > 0
        ]
        right_points = [
            x for y in range(right_pixels.height)
            for x in range(right_pixels.width)
            if right_pixels.getpixel((x, y))[3] > 0
        ]
        self.assertTrue(left_points)
        self.assertTrue(right_points)
        self.assertLess(sum(left_points) / len(left_points),
                        sum(right_points) / len(right_points))

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

    def test_thumbnails_follow_only_the_current_viewport(self) -> None:
        """Fetch and retain thumbnail widgets only while their tiles are visible.

        Returns:
            None: Scrolling moves the bounded Tk image set to the new viewport.
        """
        page, presentation, image_bytes = self._gallery_data()
        # Twenty-four rows force a scrollable album while staying below the
        # protocol's 64-photo maximum page size.
        photos = tuple(
            LinkedPhoto(TEST_FIRST_IMAGE_ID + index, f'Photo {index + 1}')
            for index in range(24)
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
                    for index in viewer._visible_thumbnail_indices),
            'The visible album tiles did not receive their thumbnails.',
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
        self.assertEqual(first_visible, viewer._thumbnail_loaded)

        viewer.grid_canvas.yview_moveto(1.0)
        viewer._on_grid_viewport(None)
        self._wait_until(
            lambda: bool(viewer._visible_thumbnail_indices)
            and all(viewer._photo_images[index]
                    for index in viewer._visible_thumbnail_indices),
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
            actual_bottom_visible, viewer._thumbnail_loaded,
        )
        self.assertEqual(
            actual_bottom_visible,
            {index for index, image in enumerate(viewer._photo_images)
             if image is not None},
        )
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
        # This fixture allocates one host per mount. Release its geometry so
        # a later viewer has the same viewport as a replacement in Photos.
        first_host = first.frame.master
        first.close()
        first_host.destroy()
        initial_reads = session.media_photo.call_count

        second = self._open_viewer(session, gallery, memory)
        self._wait_until(
            lambda: len(second._photos) == len(page.photos)
            and all(second._photo_images),
            'A previously visited album did not reuse decoded thumbnails.',
        )
        self.assertEqual(initial_reads, session.media_photo.call_count)
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
        self.assertEqual(
            changed.photos[0].thumbnail_etag,
            session.media_photo.call_args.kwargs['thumbnail_etag'],
        )
        third.close()
        memory.clear()

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

    def _session(
        self,
        page: LinkedMediaPage,
        presentation: LinkedPresentation,
        image_bytes: dict[int, bytes],
    ) -> mock.Mock:
        """Build an autospecced LinkingSession fake with in-memory media.

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
        session.can_read_admin_media.return_value = False
        session.can_read_presentation.return_value = True
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

    def _open_viewer(
        self,
        session: LinkingSession,
        gallery: LinkedGallery,
        thumbnail_memory: 'native_gallery_viewer.GalleryThumbnailMemory | None' = None,
    ) -> 'native_gallery_viewer._NativeGalleryViewer':
        """Mount the public API and retain its real embedded controller.

        Args:
            session (LinkingSession): Autospecced media session for this case.
            gallery (LinkedGallery): Explicit physical gallery selected by test.
            thumbnail_memory (GalleryThumbnailMemory | None): Shared session cache.

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
        workspace = photos_workspace.PhotosWorkspace(
            self.root, lambda: session, connect, lambda: 'en',
        )
        workspace.pack(fill='both', expand=True)
        self._wait_until(
            lambda: workspace._viewer is not None
            and all(workspace._viewer._gallery_viewer_controller._photo_images)
            and bool(workspace._viewer._gallery_viewer_controller._photo_images),
            'The linked workspace did not render the first album.',
        )
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
        self.assertEqual((168, 102), ImageTk.getimage(large_folder).size)
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

    def test_cache_icons_update_from_viewer_without_information_request(self) -> None:
        """Observe local coverage automatically from loaded viewer metadata.

        Returns:
            None: The tree changes from cloud to local without another server
                catalogue or an explicit Information request.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.gallery_pinned.return_value = True
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

        workspace.tree.item(parent_id, open=False)
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
        for pinned in (None, True):
            workspace._preload_worker = None
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
                        and widget.cget('text') == workspace._text('preload')
                    )
                    preload.invoke()
                factory.assert_called_once_with(
                    session, TEST_GALLERY_ID, pinned=pinned, download=True,
                )
            self.assertIsNone(workspace._context_card)
            self.assertEqual('grid', workspace._preload_strip.winfo_manager())
            workspace._cancel_preload()
        self.assertEqual(2, worker.start.call_count)
        self.assertEqual(2, worker.stop.call_count)
        workspace.destroy()

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
        """Refresh the selected album without refetching unchanged thumbnails.

        Returns:
            None: Equal snapshots preserve tiles and changed snapshots retain images.
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
            and viewer._lightbox._source_image is not None,
            'The lightbox did not prepare the selected photo.',
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
                          thumbnail_verified_at=None),
                mock.call(TEST_GALLERY_ID, TEST_SECOND_IMAGE_ID,
                          thumbnail=True, cancelled=mock.ANY,
                          cache_eligible=None, thumbnail_etag=None,
                          thumbnail_verified_at=None),
                mock.call(TEST_GALLERY_ID, TEST_NEXT_PAGE_IMAGE_ID,
                          thumbnail=True, cancelled=mock.ANY,
                          cache_eligible=None, thumbnail_etag=None,
                          thumbnail_verified_at=None),
            ],
            session.media_photo.call_args_list,
        )
        self.assertNotEqual('', viewer._tile_buttons[0].cget('image'))
        self.assertNotEqual('', viewer._tile_buttons[1].cget('image'))
        content_geometry = dict(viewer._main_content.pack_info())
        viewer._tile_buttons[0].invoke()
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
            and ImageTk.getimage(viewer._lightbox._photo).getpixel((0, 0))
            == (220, 35, 45, 255),
            'Left navigation did not reach the album’s first photo.',
        )
        viewer._move_lightbox(-1)
        self._wait_until(
            lambda: viewer._slide_index == len(first_page.photos)
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
            and viewer._worker is None,
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
        viewer._tile_buttons[0].invoke()
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

    def test_rapid_detail_selection_discards_stale_result(self) -> None:
        """Keep the newest selected photo after an earlier read completes.

        Returns:
            None: The delayed first result cannot replace the second selection.
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
        viewer._tile_buttons[-1].invoke()
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
        viewer._tile_buttons[0].invoke()
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
        viewer._tile_buttons[len(first_page.photos) - 1].invoke()
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
        """Use bounded prepared neighbors without a second foreground request.

        Returns:
            None: The adjacent image displays from memory after its prefetch.
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

        viewer._move_lightbox(1)

        self.assertEqual(
            TEST_SECOND_IMAGE_ID,
            viewer._photos[viewer._slide_index].image_id,
        )
        self.assertEqual(
            (20, 190, 95, 255),
            ImageTk.getimage(viewer._lightbox._photo).getpixel((0, 0)),
        )
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
            'LinkedPhotoSelected.TButton',
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


if __name__ == '__main__':
    unittest.main()
