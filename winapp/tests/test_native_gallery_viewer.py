# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_native_gallery_viewer.py
# Module Type: Windows Tk Regression Test
# Purpose: Exercise the native linked-gallery viewer with real Tk widgets.
# Responsibilities:
#   - Verify synthetic photo paging, thumbnails, detail rendering and slideshow.
#   - Verify closing the viewer or its parent cancels owned work.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Windows-only Tk regression coverage for the linked-gallery viewer."""

import ctypes
import hashlib
import io
import sys
import threading
import time
import unittest
import wave
from pathlib import Path
from typing import Callable
from unittest import mock

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

from uploader.app_linking import (
    LinkedGallery,
    LinkedMediaPage,
    LinkedPhoto,
    LinkedPresentation,
    LinkedTrack,
    LinkingSession,
)

try:
    import tkinter as tk
except ModuleNotFoundError as exc:
    if exc.name not in ('tkinter', '_tkinter'):
        raise
    tk = None

try:
    from PIL import Image, ImageTk
except ModuleNotFoundError as exc:
    if exc.name != 'PIL':
        raise
    Image = None
    ImageTk = None

if sys.platform == 'win32' and tk is not None and Image is not None:
    from uploader import native_gallery_viewer
else:
    native_gallery_viewer = None


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
# Rationale: Match the renderer's minimum while keeping its layout compact.
TEST_VIEWER_MINIMUM_SIZE = (580, 380)

# Purpose: Provide enough pixels to distinguish thumbnail and detail rendering.
# Type: tuple[int, int]. Units: pixels. Scope: generated synthetic PNG bodies.
# Consumers: NativeGalleryViewerTests._png.
# Rationale: A small image verifies actual decode without unnecessary payload.
TEST_SYNTHETIC_IMAGE_SIZE = (24, 16)

# Purpose: Leave time to inspect one manual advance before its next timer.
# Type: int. Units: seconds. Scope: synthetic presentation response.
# Consumers: NativeGalleryViewerTests._gallery_data.
# Rationale: Ten seconds is a valid server preset and exceeds the GUI wait.
TEST_PRESENTATION_INTERVAL_SECONDS = 10

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
    """Exercise actual Toplevel widgets with synthetic media responses."""

    def setUp(self) -> None:
        """Create a real Tk root with a high-DPI test scale.

        Returns:
            None: A mapped root is available to each native viewer test.
        """
        self.root = tk.Tk()
        self.root.tk.call('tk', 'scaling', TEST_TK_SCALING)
        self.root.geometry('320x180')
        self.root.update()
        self.addCleanup(self._destroy_root)

    def _destroy_root(self) -> None:
        """Destroy the test root if the test did not already close it.

        Returns:
            None: The isolated Tk interpreter and any remaining children close.
        """
        try:
            if self.root.winfo_exists():
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
        session.can_read_presentation.return_value = True
        session.media_page.return_value = page
        session.media_presentation.return_value = presentation

        def read_photo(
            gallery_id: int,
            image_id: int,
            thumbnail: bool,
            cancelled: threading.Event | None = None,
        ) -> tuple[bytes, str]:
            """Return one synthetic photo through the real viewer worker.

            Args:
                gallery_id (int): Requested physical gallery ID.
                image_id (int): Requested synthetic photo ID.
                thumbnail (bool): Whether the viewer requests its thumbnail.
                cancelled (threading.Event | None): Viewer cancellation signal.

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
    ) -> 'native_gallery_viewer._NativeGalleryViewer':
        """Open the public UI entry point and retain its real viewer owner.

        Args:
            session (LinkingSession): Autospecced media session for this case.
            gallery (LinkedGallery): Explicit physical gallery selected by test.

        Returns:
            native_gallery_viewer._NativeGalleryViewer: Actual controller whose
                Toplevel and widgets were created by the public entry point.
        """
        original = native_gallery_viewer._NativeGalleryViewer
        created: list[object] = []

        def capture(
            parent: 'tk.Misc',
            linked_session: LinkingSession,
            selected_gallery: LinkedGallery,
            language: Callable[[], str],
        ) -> 'native_gallery_viewer._NativeGalleryViewer':
            """Construct the real viewer while capturing its owner reference.

            Args:
                parent (tk.Misc): Test root that owns the native Toplevel.
                linked_session (LinkingSession): Synthetic scoped media session.
                selected_gallery (LinkedGallery): Physical gallery to display.
                language (Callable[[], str]): Current UI language provider.

            Returns:
                native_gallery_viewer._NativeGalleryViewer: Real viewer instance.
            """
            viewer = original(parent, linked_session, selected_gallery, language)
            created.append(viewer)
            return viewer

        with mock.patch.object(
            native_gallery_viewer,
            '_NativeGalleryViewer',
            side_effect=capture,
        ):
            native_gallery_viewer.open_native_gallery_view(
                self.root,
                session,
                gallery,
                lambda: 'en',
            )
        self.assertEqual(1, len(created))
        return created[0]

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
        session.media_page.side_effect = [first_page, next_page]
        gallery = LinkedGallery(
            TEST_GALLERY_ID,
            TEST_PARENT_GALLERY_ID,
            'Native viewer regression',
        )
        viewer = self._open_viewer(session, gallery)
        self.root.update_idletasks()
        width, height = viewer.window.winfo_width(), viewer.window.winfo_height()
        self.assertGreaterEqual(width, TEST_VIEWER_MINIMUM_SIZE[0])
        self.assertGreaterEqual(height, TEST_VIEWER_MINIMUM_SIZE[1])
        self.assertLessEqual(width, self.root.winfo_screenwidth())
        self.assertLessEqual(height, self.root.winfo_screenheight())
        self._wait_until(
            lambda: session.media_page.call_count == 1
            and len(viewer._photo_images) == 2
            and all(viewer._photo_images),
            'The first bounded media page and thumbnails did not finish.',
        )
        self._wait_until(
            lambda: session.media_photo.call_count == 3
            and getattr(viewer.image_label, 'image', None) is not None,
            'The initial full-size detail image did not render.',
        )
        self.assertEqual(
            [
                mock.call(TEST_GALLERY_ID, TEST_FIRST_IMAGE_ID,
                          thumbnail=True, cancelled=mock.ANY),
                mock.call(TEST_GALLERY_ID, TEST_SECOND_IMAGE_ID,
                          thumbnail=True, cancelled=mock.ANY),
                mock.call(TEST_GALLERY_ID, TEST_FIRST_IMAGE_ID,
                          thumbnail=False, cancelled=mock.ANY),
            ],
            session.media_photo.call_args_list,
        )
        self.assertNotEqual('', viewer.listbox.item('0', 'image'))
        self.assertNotEqual('', viewer.listbox.item('1', 'image'))
        rendered = ImageTk.getimage(viewer.image_label.image)
        self.assertEqual((220, 35, 45, 255), rendered.getpixel((0, 0)))

        viewer.slide_button.invoke()
        self.assertTrue(viewer._slideshow_running)
        self._wait_until(
            lambda: session.media_photo.call_count >= 4,
            'Starting the slideshow did not request its immediate next image.',
        )
        self._wait_until(
            lambda: getattr(viewer.image_label, 'image', None) is not None
            and ImageTk.getimage(viewer.image_label.image).getpixel((0, 0))
            == (20, 190, 95, 255),
            'The immediate slideshow advance did not render the second photo.',
        )
        self.assertEqual(
            (TEST_GALLERY_ID, TEST_SECOND_IMAGE_ID, False),
            (
                session.media_photo.call_args_list[-1].args[0],
                session.media_photo.call_args_list[-1].args[1],
                session.media_photo.call_args_list[-1].kwargs['thumbnail'],
            ),
        )

        # Stop before the server-provided ten-second interval elapses.
        self.assertIsNotNone(viewer._slideshow_id)
        viewer.slide_button.invoke()
        self.assertFalse(viewer._slideshow_running)
        self.assertIsNone(viewer._slideshow_id)
        viewer.slide_button.invoke()
        self._wait_until(
            lambda: viewer._photos == next_page.photos
            and ImageTk.getimage(viewer.image_label.image).getpixel((0, 0))
            == (50, 70, 240, 255),
            'The slideshow did not advance into the next bounded page.',
        )
        self.assertEqual(TEST_SECOND_IMAGE_ID, session.media_page.call_args.kwargs['after_id'])
        viewer.close()
        self.assertTrue(viewer._closed)
        self.assertTrue(viewer._cancelled.is_set())
        self.assertIsNone(viewer._slideshow_id)

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
        viewer._start_audio(track, payload)
        self.assertIsNotNone(viewer._audio_alias)
        self.assertIsNotNone(viewer._audio_path)
        owned = Path(viewer._audio_path)
        self.assertEqual(payload, owned.read_bytes())
        viewer.close()
        self.assertFalse(owned.exists())

    def test_parent_destroy_cancels_pending_read(self) -> None:
        """Cancel an active media read when the owning root is destroyed.

        Returns:
            None: The Toplevel destruction callback signals and joins its worker.
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
        stale_generation, _kind, _payload = viewer._events.get_nowait()
        self.assertNotEqual(viewer._generation, stale_generation)


if __name__ == '__main__':
    unittest.main()
