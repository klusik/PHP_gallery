# Project: PHP Gallery
# Repository: https://github.com/klusik/PHP_gallery
# File: winapp/tests/test_gallery_load_progress.py
# Module Type: Native Gallery Progress Regression Test
# Purpose: Verify real Tk presentation of gallery metadata and viewport progress.
# Responsibilities:
#   - Check indeterminate metadata and determinate visible-thumbnail states.
#   - Exercise retry countdown, terminal error, hiding and bounded widget ownership.
#   - Capture Windows screenshots for the connecting-to-settled visual sequence.
# Author: Rudolf Klusal
# License: MIT License (see LICENSE file in repository)
"""Exercise gallery load progress in real Tk widgets without network access."""

import sys
import threading
import time
import unittest
from pathlib import Path
from unittest import mock

try:
    import tkinter as tk
except ModuleNotFoundError as exc:
    if exc.name not in ('tkinter', '_tkinter'):
        raise
    tk = None

try:
    from PIL import ImageGrab
except ModuleNotFoundError as exc:
    if exc.name != 'PIL':
        raise
    ImageGrab = None

WINAPP_DIR = Path(__file__).resolve().parents[1]
if str(WINAPP_DIR) not in sys.path:
    sys.path.insert(0, str(WINAPP_DIR))

import test_native_gallery_viewer as native_fixture

if tk is not None:
    from uploader import gallery_load_progress as progress_module
    from uploader import linking as linking
    from uploader import ui_theme
    from uploader.flight_report_translations import tr
else:
    progress_module = None
    linking = None
    ui_theme = None
    tr = None


# Purpose: Exercise the slow-load detail boundary without sleeping.
# Type: int. Units: elapsed seconds. Scope: GalleryLoadProgress widget tests.
# Consumers: GalleryLoadProgressTests timer boundary regressions.
# Rationale: Ten seconds is the production detail threshold and is advanced by
# a mocked monotonic clock so the test remains deterministic.
TEST_LONG_LOAD_DETAIL_SECONDS = 10

# Purpose: Set the fake monotonic clock at an exactly representable reading.
# Type: float. Units: seconds. Scope: native progress timer assertions.
# Consumers: GalleryLoadProgressTests timer boundary regressions.
# Rationale: 100.0 makes whole-second differences exact on every host.
TEST_PROGRESS_CLOCK_START_SECONDS = 100.0

# Purpose: Give the last real progress a different time from the start.
# Type: float. Units: elapsed seconds. Scope: repeated-progress regression.
# Consumers: test_repeated_progress_reuses_widgets_timers_and_real_progress_time.
# Rationale: Two exact seconds make the elapsed and last-progress labels distinct.
TEST_FIRST_PROGRESS_OFFSET_SECONDS = 2.0

# Purpose: Bound visual screenshot dimensions to the established native fixture.
# Type: tuple[int, int]. Units: Tk pixels. Scope: real progress widget root.
# Consumers: GalleryLoadProgressTests.setUp.
# Rationale: 840x620 matches the native gallery fixture and leaves the overlay visible.
TEST_ROOT_SIZE = (840, 620)

# Purpose: Exercise responsive progress placement at a compact desktop width.
# Type: tuple[int, int]. Units: Tk pixels. Scope: narrow viewport screenshot.
# Consumers: progress state and screenshot regression.
# Rationale: 520 px exposes wrapping without changing production layout policy.
TEST_NARROW_ROOT_SIZE = (520, 620)

# Purpose: Observe a real change in the native indeterminate animation.
# Type: float. Units: seconds. Scope: one actual Tk progress widget assertion.
# Consumers: test_metadata_counts_stay_indeterminate_then_thumbnails_are_determinate.
# Rationale: 120 ms spans two 50 ms production timer intervals while keeping
# the functional GUI check brief.
TEST_BAR_ANIMATION_WAIT_SECONDS = 0.12

# Purpose: Yield Tk while observing native bar animation frames.
# Type: float. Units: seconds. Scope: real progress widget animation loop.
# Consumers: component and mounted viewer animation assertions.
# Rationale: Ten milliseconds matches the established GUI fixture cadence.
TEST_BAR_ANIMATION_POLL_SECONDS = 0.01

# Purpose: Allow the next Windows composition frames to paint the overlay.
# Type: float. Units: seconds. Scope: one held-metadata screenshot.
# Consumers: NativeGalleryLoadProgressTests._capture_viewer.
# Rationale: A half-second bounds painting on the virtual display without
# treating a never-painted shadow or an occluded capture as success.
TEST_SHADOW_PAINT_WAIT_SECONDS = 0.5

# Purpose: Store visual evidence under the existing uploaded audit artifact.
# Type: tuple[str, ...]. Units: repository-relative path components.
# Scope: optional Windows progress UI evidence. Consumers: screenshot sequence test.
# Rationale: Existing CI uploads cache/test-audit/** without workflow changes.
TEST_VISUAL_EVIDENCE_PATH = (
    'cache', 'test-audit', 'gallery-load-progress',
)

# Purpose: Retain each meaningful phase in the screenshot sequence.
# Type: tuple[str, ...]. Units: PNG filename stems.
# Scope: optional Windows progress UI evidence. Consumers: screenshot sequence test.
# Rationale: Phase names make connecting, metadata, viewport, retry and settled
# captures directly reviewable without adding benchmark or telemetry output.
TEST_VISUAL_CAPTURE_STAGES = (
    'connecting', 'metadata-500', 'metadata-2000', 'metadata-5000',
    'thumbnails-partial', 'retry-wait', 'manual-wait', 'settled-hidden',
    'narrow-metadata',
)

# Purpose: Exercise both real gallery renderer implementations.
# Type: tuple[bool, bool]. Units: renderer-selection booleans. Scope: mounted UI.
# Consumers: NativeGalleryLoadProgressTests.
# Rationale: False uses native tile widgets; True selects the Canvas tile pool.
TEST_RENDERER_OPTIONS = (False, True)

# Purpose: Exercise metadata updates across medium, multi-page and large scans.
# Type: tuple[int, ...]. Units: photo descriptors. Scope: mounted UI regression.
# Consumers: NativeGalleryLoadProgressTests.
# Rationale: 500, 2,000 and 5,000 span the target fixture sizes.
TEST_PROGRESS_CATALOGUE_SIZES = (500, 2000, 5000)

# Purpose: Hold the second visible serial thumbnail after the first is presented.
# Type: int. Units: one-based thumbnail read call. Scope: mounted progress test.
# Consumers: NativeGalleryLoadProgressTests._exercise_held_scan.
# Rationale: The first returned raster proves actual partial viewport progress.
TEST_HELD_THUMBNAIL_CALL = 2

# Purpose: Observe a nonzero automatic retry countdown before it expires.
# Type: int. Units: seconds. Scope: one transient thumbnail deferral fixture.
# Consumers: NativeGalleryLoadProgressTests.test_transient_thumbnail_wait_updates_countdown.
# Rationale: Five seconds allows multiple native one-second label ticks before
# the existing automatic resume timer can run.
TEST_TRANSIENT_DEFER_SECONDS = 5

# Purpose: Pump one bounded Tk countdown refresh while transient work is held.
# Type: float. Units: seconds. Scope: one native countdown test.
# Consumers: NativeGalleryLoadProgressTests.test_transient_thumbnail_wait_updates_countdown.
# Rationale: 2.2 seconds crosses two production one-second UI ticks with modest
# scheduling margin while staying below the five-second automatic retry.
TEST_TRANSIENT_COUNTDOWN_WAIT_SECONDS = 2.2


def observe_bar_motion(root: 'tk.Tk', bar: 'tk.Widget') -> bool:
    """Observe a changed value from the real native indeterminate timer.

    Args:
        root (tk.Tk): Native root that owns the progress animation.
        bar (tk.Widget): The mapped progressbar with its native value option.
    Returns:
        bool: True if a pumped event turn changes the value within the budget.
    """
    initial = float(bar.cget('value'))
    deadline = time.monotonic() + TEST_BAR_ANIMATION_WAIT_SECONDS
    while time.monotonic() < deadline:
        root.update()
        # The indeterminate value can return to its starting phase. Checking
        # each native turn distinguishes that from an animation that never ran.
        if float(bar.cget('value')) != initial:
            return True
        time.sleep(TEST_BAR_ANIMATION_POLL_SECONDS)
    return False


@unittest.skipIf(tk is None, 'Tkinter is unavailable on this runtime.')
class GalleryLoadProgressTests(unittest.TestCase):
    """Verify real progress widgets and their controller lifecycle."""

    def setUp(self) -> None:
        """Create a mapped native Tk root for the progress overlay.

        Returns:
            None: A real Tcl/Tk event loop is available to the test.
        """
        try:
            self.root = tk.Tk()
            self.root.geometry(
                f'{TEST_ROOT_SIZE[0]}x{TEST_ROOT_SIZE[1]}+0+0',
            )
            self.canvas = tk.Canvas(self.root, highlightthickness=0)
            self.canvas.pack(fill='both', expand=True)
            self.root.update()
            ui_theme.ensure_component_theme(self.canvas)
        except tk.TclError as exc:
            self.skipTest(f'Real Tk display is unavailable: {exc}')
        self.addCleanup(self._destroy_root)
        errors: list[str] = []
        self.root.report_callback_exception = lambda _kind, error, _trace: errors.append(
            str(error),
        )
        self.addCleanup(self.assertEqual, [], errors)
        self.progress = progress_module.GalleryLoadProgress(
            self.canvas, lambda key: tr('en', key),
        )
        self.addCleanup(self.progress.close)

    def _destroy_root(self) -> None:
        """Destroy the fixture root after controller-owned callbacks stop.

        Returns:
            None: Tcl/Tk resources are released on the creating thread.
        """
        try:
            if self.root.winfo_exists():
                self.root.destroy()
        except tk.TclError:
            pass

    def _capture(self, stage: str) -> None:
        """Save one optional Windows screenshot of the mapped progress overlay.

        Args:
            stage (str): Stable filename stem for the current phase.
        Returns:
            None: Non-Windows or Pillow-less runs retain behavioral assertions only.
        """
        if sys.platform != 'win32' or ImageGrab is None:
            return
        evidence = WINAPP_DIR.parent.joinpath(*TEST_VISUAL_EVIDENCE_PATH)
        evidence.mkdir(parents=True, exist_ok=True)
        self.root.update_idletasks()
        bounds = (
            self.root.winfo_rootx(), self.root.winfo_rooty(),
            self.root.winfo_rootx() + self.root.winfo_width(),
            self.root.winfo_rooty() + self.root.winfo_height(),
        )
        with ImageGrab.grab(bbox=bounds) as captured:
            captured.save(evidence / f'gallery-progress-{stage}.png')

    def test_metadata_counts_stay_indeterminate_then_thumbnails_are_determinate(self) -> None:
        """Show real metadata counts without fabricating a completion ratio.

        Returns:
            None: Metadata count updates retain the indeterminate bar; actual
                thumbnail outcomes switch it to the determinate viewport target.
        """
        self.assertTrue(self.progress.visible)
        self.assertTrue(self.progress.active)
        self.root.update()
        self._capture(TEST_VISUAL_CAPTURE_STAGES[0])
        self.progress.metadata(0)
        self.root.update()
        self.assertTrue(self.progress.visible)
        self.assertEqual('metadata', self.progress.phase)
        self.assertEqual('indeterminate', str(self.progress.bar.cget('mode')))

        for count, stage in zip(
            TEST_PROGRESS_CATALOGUE_SIZES, TEST_VISUAL_CAPTURE_STAGES[1:4],
        ):
            self.progress.metadata(count)
            self.root.update_idletasks()
            self.assertEqual('indeterminate', str(self.progress.bar.cget('mode')))
            self.assertNotEqual(
                float(count), float(self.progress.bar['maximum']),
                'Metadata counts must not be a fabricated completion percentage.',
            )
            self.assertIn(f'{count:,}', self.progress.label.cget('text'))
            self._capture(stage)

        self.assertTrue(
            observe_bar_motion(self.root, self.progress.bar),
            'The metadata progress bar did not change during native animation.',
        )

        self.progress.thumbnails(2, 5)
        self.root.update_idletasks()
        self.assertEqual('thumbnails', self.progress.phase)
        self.assertEqual('determinate', str(self.progress.bar.cget('mode')))
        self.assertEqual(5.0, float(self.progress.bar['maximum']))
        self.assertEqual(2.0, float(self.progress.bar['value']))
        self._capture(TEST_VISUAL_CAPTURE_STAGES[4])

        self.progress.waiting(8, automatic=True)
        self.root.update_idletasks()
        self.assertEqual('waiting', self.progress.phase)
        self.assertEqual('indeterminate', str(self.progress.bar.cget('mode')))
        self.assertIn(
            tr('en', 'linking_media_wait_retry').format(seconds=8),
            self.progress.label.cget('text'),
        )
        self._capture(TEST_VISUAL_CAPTURE_STAGES[5])
        self.progress.waiting(None, automatic=False)
        self.root.update_idletasks()
        self.assertIn(
            tr('en', 'linking_media_throttled'),
            self.progress.label.cget('text'),
        )
        self._capture(TEST_VISUAL_CAPTURE_STAGES[6])

        self.root.geometry(
            f'{TEST_NARROW_ROOT_SIZE[0]}x{TEST_NARROW_ROOT_SIZE[1]}+0+0',
        )
        self.progress.rearm()
        self.progress.metadata(2000)
        self.root.update()
        self._capture(TEST_VISUAL_CAPTURE_STAGES[8])

        self.progress.hide()
        self.root.update_idletasks()
        self.assertFalse(self.progress.visible)
        self.assertFalse(self.progress.active)
        self.assertEqual('hidden', self.progress.phase)
        self.assertEqual({}, self.progress.frame.place_info())
        self._capture(TEST_VISUAL_CAPTURE_STAGES[7])

    def test_long_load_detail_appears_after_ten_seconds(self) -> None:
        """Keep elapsed detail absent until the production ten-second threshold.

        Returns:
            None: The controller timer shows elapsed metadata only after the
                configured threshold, without relying on a wall-clock sleep.
        """
        self.progress.metadata(0)
        self.root.update_idletasks()
        self.assertEqual('', self.progress.details.cget('text'))
        self.progress._cancel_tick()
        with (
            mock.patch.object(
                self.progress, '_started_at', TEST_PROGRESS_CLOCK_START_SECONDS,
            ),
            mock.patch.object(
                progress_module.time, 'monotonic',
                return_value=TEST_PROGRESS_CLOCK_START_SECONDS + TEST_LONG_LOAD_DETAIL_SECONDS,
            ),
        ):
            self.progress._tick()
        self.root.update_idletasks()
        self.assertEqual(
            tr('en', 'linking_media_elapsed').format(seconds=TEST_LONG_LOAD_DETAIL_SECONDS),
            self.progress.details.cget('text'),
        )

    def test_error_retry_and_close_release_widgets_and_timer(self) -> None:
        """Keep terminal error readable and release its timer and widgets.

        Returns:
            None: Error stops the bar; closing destroys the owned frame and
                leaves the controller inactive with no scheduled update.
        """
        self.progress.fail('Could not load photos')
        self.root.update_idletasks()
        self.assertEqual('error', self.progress.phase)
        self.assertEqual('Could not load photos', self.progress.label.cget('text'))
        self.assertIsNone(self.progress._tick_id)
        self.progress.close()
        self.assertFalse(self.progress.active)
        self.assertFalse(self.progress.frame.winfo_exists())
        self.progress.rearm()
        self.progress.metadata(9)
        self.progress.waiting(1, automatic=True)
        self.progress.fail('Late diagnostic')
        self.assertFalse(self.progress.active)
        self.assertFalse(self.progress.visible)
        self.assertEqual('hidden', self.progress.phase)

    def test_repeated_progress_reuses_widgets_timers_and_real_progress_time(self) -> None:
        """Keep thousands of progress reports bounded and their timestamps factual.

        Returns:
            None: Reports create no widgets or timers, and repeated counts and
                retry dispatch do not replace the last real progress timestamp.
        """
        self.root.update()
        children = tuple(self.progress.frame.winfo_children())
        timers = self.root.tk.call('after', 'info')
        largest_count = TEST_PROGRESS_CATALOGUE_SIZES[-1]
        progress_at = TEST_PROGRESS_CLOCK_START_SECONDS + TEST_FIRST_PROGRESS_OFFSET_SECONDS
        with (
            mock.patch.object(
                self.progress, '_started_at', TEST_PROGRESS_CLOCK_START_SECONDS,
            ),
            mock.patch.object(
                progress_module.time, 'monotonic', return_value=progress_at,
            ) as clock,
        ):
            for count in range(1, largest_count + 1):
                self.progress.metadata(count)
            self.assertEqual(children, tuple(self.progress.frame.winfo_children()))
            self.assertEqual(timers, self.root.tk.call('after', 'info'))

            started_at = self.progress._started_at
            last_progress = self.progress._last_progress_at
            self.assertEqual(progress_at, last_progress)
            # The exact ten-second gap leaves the confirmed count unchanged.
            observed_at = last_progress + TEST_LONG_LOAD_DETAIL_SECONDS
            clock.return_value = observed_at
            self.progress.metadata(largest_count)
            self.progress._cancel_tick()
            self.progress._tick()
            expected_age = tr('en', 'linking_media_last_progress').format(
                seconds=TEST_LONG_LOAD_DETAIL_SECONDS, count=largest_count,
            )
            self.assertIn(expected_age, self.progress.details.cget('text'))
            self.assertIn(
                tr('en', 'linking_media_elapsed').format(
                    seconds=int(observed_at - started_at),
                ),
                self.progress.details.cget('text'),
            )
            self.progress.waiting(None, automatic=True)
            self.progress.resume()
            self.assertEqual(last_progress, self.progress._last_progress_at)
            self.progress.fail('Retry needed')
            self.progress.rearm()
            self.assertEqual(started_at, self.progress._started_at)
            self.assertEqual(last_progress, self.progress._last_progress_at)

        self.progress.hide()
        self.progress._tick()
        self.assertIsNone(self.progress._tick_id)
        self.assertEqual(children, tuple(self.progress.frame.winfo_children()))

    def test_paint_is_idempotent_and_overlay_keeps_its_anchor(self) -> None:
        """Keep placement and widget state stable across repeated progress reports.

        Returns:
            None: Real transitions repaint once and repeated observations
                leave the bar, caption and viewport geometry alone.
        """
        self.root.update()
        placement = self.progress.frame.place_info()
        canvas_size = (self.canvas.winfo_width(), self.canvas.winfo_height())
        self.progress.thumbnails(2, 5)
        self.root.update()
        with (
            mock.patch.object(self.progress.bar, 'configure') as configure_bar,
            mock.patch.object(self.progress.label, 'configure') as configure_label,
            mock.patch.object(self.progress.frame, 'place') as place,
        ):
            self.progress.thumbnails(2, 5)
            configure_bar.assert_not_called()
            configure_label.assert_not_called()
            place.assert_not_called()
        self.assertEqual(placement, self.progress.frame.place_info())
        self.assertEqual(canvas_size, (self.canvas.winfo_width(), self.canvas.winfo_height()))


@unittest.skipUnless(
    sys.platform == 'win32' and tk is not None
    and native_fixture.Image is not None
    and native_fixture.ImageTk is not None,
    'Mounted gallery progress requires Windows, Tk and Pillow.',
)
class NativeGalleryLoadProgressTests(unittest.TestCase):
    """Exercise queue-fed progress in the mounted newest-first viewer."""

    setUp = native_fixture.NativeGalleryViewerTests.setUp
    _destroy_root = native_fixture.NativeGalleryViewerTests._destroy_root
    _wait_until = native_fixture.NativeGalleryViewerTests._wait_until
    _gallery_data = native_fixture.NativeGalleryViewerTests._gallery_data
    _session = native_fixture.NativeGalleryViewerTests._session
    _png = native_fixture.NativeGalleryViewerTests._png
    _open_viewer = native_fixture.NativeGalleryViewerTests._open_viewer
    _assert_no_browsing_toplevels = (
        native_fixture.NativeGalleryViewerTests._assert_no_browsing_toplevels
    )

    def _capture_viewer(
        self, viewer: 'native_fixture.native_gallery_viewer._NativeGalleryViewer',
        stage: str, *, expect_progress: bool = False,
    ) -> None:
        """Capture the mounted viewer's real desktop bounds when supported.

        Args:
            viewer (native_fixture.native_gallery_viewer._NativeGalleryViewer):
                Mounted controller whose frame is visible.
            stage (str): Stable filename stem for this progress phase.
            expect_progress (bool): Require the actual painted track color
                and, during held metadata, the shadow outside the card.
        Returns:
            None: Saves screenshot evidence under the existing audit artifact.
        """
        if ImageGrab is None:
            return
        frame = viewer.frame
        # The hosted runner's terminal can cover a mapped Tk frame. Keep this
        # fixture root above it so the desktop grab samples the owned raster.
        self.root.attributes('-topmost', True)
        self.root.lift()
        self.root.update()
        bounds = (
            frame.winfo_rootx(), frame.winfo_rooty(),
            frame.winfo_rootx() + frame.winfo_width(),
            frame.winfo_rooty() + frame.winfo_height(),
        )
        evidence = WINAPP_DIR.parent.joinpath(*TEST_VISUAL_EVIDENCE_PATH)
        evidence.mkdir(parents=True, exist_ok=True)
        progress = viewer._load_progress
        shadow_sample: tuple[int, int] | None = None
        background = ui_theme._hex_rgb(ui_theme._PALETTE['card_surface'])
        if expect_progress and progress.phase == 'metadata':
            card = progress.frame
            layers = card._shadow_layers
            self.assertTrue(layers and all(layer.winfo_viewable() for layer in layers), stage)
            # Two pixels outside the card, comfortably inside the canvas.
            right = card.winfo_rootx() + card.winfo_width() - bounds[0] + 2
            center_y = card.winfo_rooty() - bounds[1] + card.winfo_height() // 2
            shadow_sample = (right, center_y)
            # Tk has mapped the children. The Windows compositor can paint
            # a later frame, so sample pixels before taking the full evidence.
            deadline = time.monotonic() + TEST_SHADOW_PAINT_WAIT_SECONDS
            pixel = (bounds[0] + right, bounds[1] + center_y)
            pixel_bounds = (*pixel, pixel[0] + 1, pixel[1] + 1)
            while time.monotonic() < deadline:
                with ImageGrab.grab(bbox=pixel_bounds) as sample:
                    if sum(sample.getpixel((0, 0))) < sum(background):
                        break
                self.root.update()
                time.sleep(TEST_BAR_ANIMATION_POLL_SECONDS)
        with ImageGrab.grab(bbox=bounds) as captured:
            captured.save(evidence / f'viewer-{stage}.png')
            if expect_progress:
                bar = progress.bar
                self.assertTrue(bar.winfo_viewable(), stage)
                self.assertGreaterEqual(bar.winfo_width(), bar.winfo_reqwidth(), stage)
                x = bar.winfo_rootx() - bounds[0]
                y = bar.winfo_rooty() - bounds[1]
                with captured.crop((
                    x, y, x + bar.winfo_width(), y + bar.winfo_height(),
                )).convert('RGB') as track:
                    trough = ui_theme._hex_rgb(ui_theme._PALETTE['line'])
                    colors = track.getcolors()
                    self.assertIsNotNone(colors, stage)
                    self.assertIn(trough, {color for _count, color in colors}, stage)
                if shadow_sample is not None:
                    shadow = captured.getpixel(shadow_sample)
                    self.assertLess(sum(shadow), sum(background), stage)

    def test_newest_first_progress_reaches_tk_during_held_catalogue_read(self) -> None:
        """Show queue-fed validated counts while large newest-first scans wait.

        Returns:
            None: Three fixture catalogue sizes keep the metadata indicator
                indeterminate during a held read, then settle into real viewport
                thumbnail progress without creating unbounded UI state.
        """
        page_size = linking.NATIVE_MEDIA_PAGE_SIZE
        for canvas_grid_enabled in TEST_RENDERER_OPTIONS:
            for total in TEST_PROGRESS_CATALOGUE_SIZES:
                with self.subTest(
                    renderer=canvas_grid_enabled, total=total,
                ):
                    self._exercise_held_scan(
                        total, canvas_grid_enabled, page_size,
                    )

    def _exercise_held_scan(
        self, total: int, canvas_grid_enabled: bool, page_size: int,
    ) -> None:
        """Run one mounted renderer and catalogue-size held-read scenario.

        Args:
            total (int): Synthetic catalogue size for this scenario.
            canvas_grid_enabled (bool): Selects the production Canvas renderer.
            page_size (int): Existing transport metadata page size.
        Returns:
            None: Queue-fed progress arrives during the held continuation and
                the released reverse snapshot remains newest-first.
        """
        page, presentation, image_bytes = self._gallery_data()
        renderer_name = 'canvas' if canvas_grid_enabled else 'native'
        first_body = image_bytes[native_fixture.TEST_FIRST_IMAGE_ID]
        image_bytes = {image_id: first_body for image_id in range(1, total + 1)}
        session = self._session(page, presentation, image_bytes)
        session.supports_thumbnail_batch.return_value = False
        read_thumbnail = session.media_photo.side_effect
        thumbnail_calls: list[int] = []
        thumbnail_entered = threading.Event()
        release_thumbnail = threading.Event()
        self.addCleanup(release_thumbnail.set)
        entered = threading.Event()
        release = threading.Event()
        second_entered = threading.Event()
        release_second = threading.Event()
        self.addCleanup(release.set)
        self.addCleanup(release_second.set)

        def hold_second_thumbnail(
            gallery_id: int, image_id: int, thumbnail: bool,
            cancelled: threading.Event | None = None, *,
            cache_eligible: bool | None = None,
            thumbnail_etag: str | None = None,
            thumbnail_verified_at: float | None = None,
            preload_eligible: bool | None = None,
            preload_thumbnail_etag: str | None = None,
            preload_verified_at: float | None = None,
            timings: linking.MediaReadTimings | None = None,
            use_preloaded_snapshot: bool = False,
        ) -> tuple[bytes, str]:
            """Present the first thumbnail and hold the next real read.

            Args:
                gallery_id (int): Physical gallery requested by the viewer.
                image_id (int): Photo whose thumbnail is being resolved.
                thumbnail (bool): Whether the viewer requests a thumbnail raster.
                cancelled (threading.Event | None): Viewer cancellation state.
                cache_eligible (bool | None): Captured public cache policy.
                thumbnail_etag (str | None): Current thumbnail validator.
                thumbnail_verified_at (float | None): Validator observation time.
                preload_eligible (bool | None): Captured Admin preload policy.
                preload_thumbnail_etag (str | None): Preload validator.
                preload_verified_at (float | None): Preload observation time.
                timings (linking.MediaReadTimings | None): Optional read timings.
                use_preloaded_snapshot (bool): Whether the request uses an
                    explicitly authorized saved preloaded body.
            Returns:
                tuple[bytes, str]: Existing fixture PNG body and media type.
            """
            thumbnail_calls.append(image_id)
            result = read_thumbnail(
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
            if len(thumbnail_calls) == TEST_HELD_THUMBNAIL_CALL:
                thumbnail_entered.set()
                release_thumbnail.wait(native_fixture.TEST_GUI_WAIT_SECONDS)
            return result

        session.media_photo.side_effect = hold_second_thumbnail

        def read_page(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> linking.LinkedMediaPage:
            """Return one ascending metadata slice or hold its continuation.

            Args:
                gallery_id (int): Physical gallery requested by the viewer.
                after_id (int | None): Exclusive transport page cursor.
                cancelled (threading.Event | None): Viewer cancellation.
            Returns:
                linking.LinkedMediaPage: Valid page for the requested slice.
            """
            if gallery_id != native_fixture.TEST_GALLERY_ID:
                raise AssertionError('Unexpected gallery in progress fixture.')
            if after_id == page_size:
                entered.set()
                release.wait(native_fixture.TEST_GUI_WAIT_SECONDS)
            elif after_id == 2 * page_size:
                second_entered.set()
                release_second.wait(native_fixture.TEST_GUI_WAIT_SECONDS)
            start = (after_id or 0) + 1
            stop = min(total, start + page_size - 1)
            photo_rows = tuple(
                linking.LinkedPhoto(value, f'Photo {value}')
                for value in range(start, stop + 1)
            )
            return linking.LinkedMediaPage(
                gallery_id, native_fixture.TEST_PARENT_GALLERY_ID,
                photo_rows, stop if stop < total else None,
            )

        session.media_page.side_effect = read_page
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID,
            f'Load progress {total}',
        )
        viewer = self._open_viewer(
            session, gallery, canvas_grid_enabled=canvas_grid_enabled,
            newest_first=True,
        )
        host = viewer.frame.master
        self.addCleanup(viewer.close)
        self._wait_until(
            entered.is_set,
            'The metadata worker did not reach its held continuation.',
        )
        self._wait_until(
            lambda: viewer._load_progress.phase == 'metadata'
            and f'{page_size:,}' in viewer._load_progress.label.cget('text'),
            'Validated metadata count did not reach the Tk event queue.',
        )
        self.assertEqual('indeterminate', str(viewer._load_progress.bar.cget('mode')))
        self.assertEqual(page_size, len(viewer._view_order._pending))
        self.assertEqual(page_size, viewer._view_order._pending_cursor)
        self._capture_viewer(
            viewer, f'{renderer_name}-metadata-held-{total}', expect_progress=True,
        )
        self.root.geometry(
            f'{TEST_NARROW_ROOT_SIZE[0]}x{TEST_NARROW_ROOT_SIZE[1]}+0+0',
        )
        self.root.update()
        self._capture_viewer(
            viewer, f'{renderer_name}-metadata-held-narrow-{total}', expect_progress=True,
        )
        self.root.geometry(
            f'{TEST_ROOT_SIZE[0]}x{TEST_ROOT_SIZE[1]}+0+0',
        )
        self.root.update()

        stale_generation = viewer._generation - 1
        with viewer._load_progress_lock:
            viewer._pending_load_progress = (stale_generation, total)

        def stale_count_consumed() -> bool:
            """Read coalesced metadata slot state under its owner lock.

            Returns:
                bool: True when the Tk poll consumed the stale count.
            """
            with viewer._load_progress_lock:
                return viewer._pending_load_progress is None

        self._wait_until(
            stale_count_consumed,
            'The stale generation count was not consumed by the Tk owner.',
        )
        self.assertIn(
            f'{page_size:,}', viewer._load_progress.label.cget('text'),
            'A stale progress event must not replace the current count.',
        )

        self.assertTrue(
            observe_bar_motion(self.root, viewer._load_progress.bar),
            'The bar must animate while the metadata read remains held.',
        )

        release.set()
        self._wait_until(
            second_entered.is_set,
            'The metadata worker did not reach its second held continuation.',
        )
        next_count = 2 * page_size
        self._wait_until(
            lambda: f'{next_count:,}' in viewer._load_progress.label.cget('text'),
            'The second validated metadata count did not reach Tk.',
        )
        self.assertEqual(next_count, len(viewer._view_order._pending))
        self.assertEqual(next_count, viewer._view_order._pending_cursor)
        self._capture_viewer(
            viewer, f'{renderer_name}-metadata-held-second-{total}',
        )
        release_second.set()
        self._wait_until(
            thumbnail_entered.is_set,
            'The viewport did not start visible thumbnail reads.',
        )
        self._wait_until(
            lambda: viewer._load_progress.phase == 'thumbnails'
            and float(viewer._load_progress.bar['value']) >= 1,
            'The first real thumbnail did not update partial viewport progress.',
        )
        self._capture_viewer(
            viewer, f'{renderer_name}-thumbnails-partial-{total}', expect_progress=True,
        )
        release_thumbnail.set()
        self._wait_until(
            lambda: viewer._worker is None
            and viewer._load_progress.phase == 'hidden'
            and all(
                viewer._photo_images[index] is not None
                for index in viewer._visible_thumbnail_indices
            ),
            'The released thumbnails did not settle the first usable viewport.',
        )
        self.assertEqual(total, len(viewer._view_order._photos))
        self.assertEqual(
            list(range(total, total - page_size, -1)),
            [photo.image_id for photo in viewer._photos],
        )
        self.assertEqual(
            native_fixture.native_gallery_viewer.MEDIA_THUMBNAIL_BATCH_SIZE,
            viewer._events.maxsize,
        )
        self.assertLessEqual(len(viewer._tile_buttons), page_size)
        self._capture_viewer(viewer, f'{renderer_name}-settled-{total}')
        viewer.close()
        host.destroy()

    def test_explicit_retry_rearms_manual_server_wait(self) -> None:
        """Keep a server throttle manual until the user invokes Retry.

        Returns:
            None: The viewer shows the server's manual wait without a timer,
                then a real button invocation resumes and settles metadata.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.media_page.side_effect = [
            linking.LinkingMediaThrottled(route='app_linking_media'), page,
        ]
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID,
            'Manual metadata retry',
        )
        viewer = self._open_viewer(session, gallery, newest_first=True)
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: viewer._worker is None and viewer._media_throttled,
            'The server throttle did not reach the manual wait state.',
        )
        self.assertEqual('waiting', viewer._load_progress.phase)
        self.assertIn(
            viewer._text('linking_media_throttled'),
            viewer._load_progress.label.cget('text'),
        )
        self._capture_viewer(viewer, 'manual-server-wait')
        self.assertEqual(1, session.media_page.call_count)
        viewer.retry_button.invoke()
        self._wait_until(
            lambda: viewer._load_progress.phase == 'hidden'
            and bool(viewer._view_order._photos)
            and all(
                viewer._photo_images[index] is not None
                for index in viewer._visible_thumbnail_indices
            ),
            'Explicit Retry did not settle the first usable viewport.',
        )
        self.assertGreater(session.media_page.call_count, 1)
        self.assertFalse(viewer._load_progress.active)
        host = viewer.frame.master
        viewer.close()
        host.destroy()

    def test_safe_header_timeout_shows_automatic_retry_while_read_is_held(self) -> None:
        """Show existing safe retry state without changing timeout behavior.

        Returns:
            None: The timeout is retried at the same cursor, its retry event
                reaches Tk, and releasing the original read completes metadata.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        retry_entered = threading.Event()
        release_retry = threading.Event()
        self.addCleanup(release_retry.set)
        attempts: list[int | None] = []

        def retry_once(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> linking.LinkedMediaPage:
            """Fail once at headers then hold the same-cursor safe retry.

            Args:
                gallery_id (int): Physical gallery requested by the viewer.
                after_id (int | None): Exact retry cursor supplied by the order.
                cancelled (threading.Event | None): Viewer cancellation state.
            Returns:
                linking.LinkedMediaPage: Complete fixture page after release.
            Raises:
                linking.LinkingResponseError: First request times out before headers.
            """
            del gallery_id, cancelled
            attempts.append(after_id)
            if len(attempts) == 1:
                raise linking.LinkingResponseError(
                    'media', 'app_linking_media', None, 'other', 'timeout',
                    transport_step='headers',
                )
            retry_entered.set()
            release_retry.wait(native_fixture.TEST_GUI_WAIT_SECONDS)
            return page

        session.media_page.side_effect = retry_once
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID,
            'Safe metadata retry',
        )
        viewer = self._open_viewer(session, gallery, newest_first=True)
        self.addCleanup(viewer.close)
        self._wait_until(
            retry_entered.is_set,
            'The safe timeout retry did not reach its held response.',
        )
        self._wait_until(
            lambda: viewer._load_progress.phase == 'waiting',
            'The safe retry event did not reach the Tk owner.',
        )
        self.assertIn(
            tr('en', 'linking_media_retrying'),
            viewer._load_progress.label.cget('text'),
        )
        self._capture_viewer(viewer, 'automatic-safe-retry')
        self.assertEqual([None, None], attempts)
        release_retry.set()
        self._wait_until(
            lambda: viewer._load_progress.phase == 'hidden'
            and viewer._photos == tuple(reversed(page.photos))
            and all(
                viewer._photo_images[index] is not None
                for index in viewer._visible_thumbnail_indices
            ),
            'The safe same-cursor retry did not settle the first viewport.',
        )
        self.assertEqual([None, None], attempts)
        host = viewer.frame.master
        viewer.close()
        host.destroy()

    def test_fresh_cached_viewport_settles_before_metadata_refresh(self) -> None:
        """Keep cached first paint ahead of metadata refresh in both renderers.

        Returns:
            None: Both native-widget and Canvas paths settle cached images and
                hide progress before applying the released fresh response.
        """
        for canvas_grid_enabled in TEST_RENDERER_OPTIONS:
            with self.subTest(canvas=canvas_grid_enabled):
                self._exercise_fresh_cached_viewport(canvas_grid_enabled)

    def _exercise_fresh_cached_viewport(
        self, canvas_grid_enabled: bool,
    ) -> None:
        """Hold an authoritative refresh behind a real cached viewport.

        Args:
            canvas_grid_enabled (bool): Selects the production Canvas renderer;
                False exercises the native-widget renderer.
        Returns:
            None: The shared cached thumbnails render before fresh metadata
                replaces the reverse-order descriptors.
        """
        renderer_name = 'canvas' if canvas_grid_enabled else 'widget'
        page, presentation, image_bytes = self._gallery_data()
        image_bytes[native_fixture.TEST_NEXT_PAGE_IMAGE_ID] = self._png((40, 80, 120))
        session = self._session(page, presentation, image_bytes)
        cached = tuple(reversed(page.photos))
        fresh_page = linking.LinkedMediaPage(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID,
            (
                linking.LinkedPhoto(
                    native_fixture.TEST_FIRST_IMAGE_ID, 'Fresh oldest',
                ),
                linking.LinkedPhoto(
                    native_fixture.TEST_SECOND_IMAGE_ID, 'Fresh middle',
                ),
                linking.LinkedPhoto(
                    native_fixture.TEST_NEXT_PAGE_IMAGE_ID, 'Fresh newest',
                ),
            ),
            None,
        )
        refresh_entered = threading.Event()
        release_refresh = threading.Event()
        self.addCleanup(release_refresh.set)

        thumbnail_memory = (
            native_fixture.native_gallery_viewer.GalleryThumbnailMemory()
        )
        self.addCleanup(thumbnail_memory.clear)
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID,
            'Cached viewport first',
        )
        warm_viewer = self._open_viewer(
            session, gallery, thumbnail_memory=thumbnail_memory,
            canvas_grid_enabled=canvas_grid_enabled,
        )
        warm_host = warm_viewer.frame.master
        self._wait_until(
            lambda: warm_viewer._load_progress.phase == 'hidden'
            and warm_viewer._worker is None
            and bool(warm_viewer._visible_thumbnail_indices)
            and all(
                warm_viewer._photo_images[index] is not None
                for index in warm_viewer._visible_thumbnail_indices
            ),
            'The first mounted viewer did not populate real warm thumbnails.',
        )
        self.assertGreaterEqual(thumbnail_memory.metrics_snapshot()['entries'], 1)
        warm_viewer.close()
        warm_host.destroy()
        session.cache_recent_media_order(
            native_fixture.TEST_GALLERY_ID,
            cached,
            native_fixture.native_gallery_viewer.time.monotonic(),
        )
        session.recent_media_order.return_value = cached

        def hold_refresh(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> linking.LinkedMediaPage:
            """Hold the authoritative refresh while cached images render.

            Args:
                gallery_id (int): Physical gallery requested by the viewer.
                after_id (int | None): Complete refresh begins without a cursor.
                cancelled (threading.Event | None): Viewer cancellation state.
            Returns:
                linking.LinkedMediaPage: Fresh catalogue after test release.
            """
            refresh_entered.set()
            release_refresh.wait(native_fixture.TEST_GUI_WAIT_SECONDS)
            return fresh_page

        session.media_page.side_effect = hold_refresh
        viewer = self._open_viewer(
            session, gallery, thumbnail_memory=thumbnail_memory,
            newest_first=True, canvas_grid_enabled=canvas_grid_enabled,
        )
        self.addCleanup(viewer.close)
        self._wait_until(
            refresh_entered.is_set,
            'The authoritative refresh did not start while Tk remained active.',
        )
        self._wait_until(
            lambda: viewer._photos == cached
            and viewer._load_progress.phase == 'hidden'
            and bool(viewer._visible_thumbnail_indices)
            and all(
                viewer._photo_images[index] is not None
                for index in viewer._visible_thumbnail_indices
            ),
            'The cached first viewport did not settle before fresh metadata.',
        )
        self.assertTrue(viewer._worker.is_alive())
        self.assertGreaterEqual(thumbnail_memory.metrics_snapshot()['hits'], 1)
        self._capture_viewer(
            viewer, f'{renderer_name}-cached-viewport-during-refresh',
        )
        release_refresh.set()
        self._wait_until(
            lambda: viewer._photos and viewer._photos[0].image_id
            == native_fixture.TEST_NEXT_PAGE_IMAGE_ID,
            'The released fresh catalogue did not replace cached descriptors.',
        )
        self.assertEqual('hidden', viewer._load_progress.phase)
        host = viewer.frame.master
        viewer.close()
        host.destroy()

    def test_oldest_first_small_album_uses_the_same_viewport_completion(self) -> None:
        """Keep existing oldest-first order and hide after a small viewport.

        Returns:
            None: Two ascending rows retain server order and their actual visible
                thumbnails settle the first usable viewport.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        entered = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)

        def held_page(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> linking.LinkedMediaPage:
            """Hold the ordinary first page before returning its small album.

            Args:
                gallery_id (int): Physical gallery requested by the viewer.
                after_id (int | None): Initial request cursor; expected None.
                cancelled (threading.Event | None): Viewer cancellation state.
            Returns:
                linking.LinkedMediaPage: Existing ascending server page.
            """
            entered.set()
            release.wait(native_fixture.TEST_GUI_WAIT_SECONDS)
            return page

        session.media_page.side_effect = held_page
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID,
            'Small oldest-first album',
        )
        viewer = self._open_viewer(session, gallery, newest_first=False)
        host = viewer.frame.master
        self.addCleanup(viewer.close)
        self._wait_until(
            entered.is_set,
            'The oldest-first held metadata request did not start.',
        )
        self._wait_until(
            lambda: viewer._load_progress.phase == 'metadata',
            'The held ordinary metadata read did not announce its current phase.',
        )
        self.assertEqual(
            tr('en', 'linking_media_metadata'),
            viewer._load_progress.label.cget('text'),
        )
        release.set()
        self._wait_until(
            lambda: viewer._load_progress.phase == 'hidden'
            and viewer._photos == page.photos
            and all(
                viewer._photo_images[index] is not None
                for index in viewer._visible_thumbnail_indices
            ),
            'The small oldest-first viewport did not settle.',
        )
        self.assertIsNone(viewer._view_order)
        viewer.close()
        host.destroy()

    def test_transient_thumbnail_wait_updates_countdown(self) -> None:
        """Show the real automatic thumbnail cooldown countdown in Tk.

        Returns:
            None: Existing transient cooldown data drives a visible countdown;
                closing cancels its owned retry timer without a new request.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        session.supports_thumbnail_batch.return_value = True
        session.thumbnail_batch_preferred.return_value = True
        session.thumbnail_publication_current.return_value = True
        previous_finished = threading.Event()
        previous_finished.set()
        session.media_thumbnails.side_effect = linking.LinkingThumbnailBatchDeferred(
            TEST_TRANSIENT_DEFER_SECONDS, cooldown_source='transient',
            operation_finished=previous_finished,
        )
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID,
            'Transient thumbnail wait',
        )
        viewer = self._open_viewer(session, gallery)
        host = viewer.frame.master
        self.addCleanup(viewer.close)
        self._wait_until(
            lambda: viewer._load_progress.phase == 'waiting'
            and viewer._thumbnail_resume_after_id is not None,
            'The existing transient cooldown did not reach its Tk timer.',
        )
        initial_label = viewer._load_progress.label.cget('text')
        self.assertIn('retrying in', initial_label.lower())
        first_wait_deadline = time.monotonic() + TEST_TRANSIENT_COUNTDOWN_WAIT_SECONDS
        while (time.monotonic() < first_wait_deadline
               and viewer._load_progress.label.cget('text') == initial_label):
            self.root.update()
            time.sleep(TEST_BAR_ANIMATION_POLL_SECONDS)
        self.assertNotEqual(
            initial_label, viewer._load_progress.label.cget('text'),
            'The native countdown did not update while its timer was owned.',
        )
        self.assertEqual(1, session.media_thumbnails.call_count)
        self._capture_viewer(viewer, 'transient-thumbnail-retry-countdown')
        viewer.close()
        self.assertIsNone(viewer._thumbnail_resume_after_id)
        self.assertFalse(viewer._load_progress.active)
        host.destroy()

    def test_large_oldest_first_viewports_do_not_wait_for_the_remaining_catalogue(self) -> None:
        """Keep the first oldest-first viewport independent of album size.

        Returns:
            None: Both renderers show and settle one bounded ascending page
                without fetching the remaining 500, 2,000 or 5,000-photo album.
        """
        page_size = linking.NATIVE_MEDIA_PAGE_SIZE
        for canvas_enabled in TEST_RENDERER_OPTIONS:
            for total in TEST_PROGRESS_CATALOGUE_SIZES:
                with self.subTest(canvas=canvas_enabled, photos=total):
                    original, presentation, bodies = self._gallery_data()
                    rows = tuple(
                        linking.LinkedPhoto(image_id, f'Photo {image_id}')
                        for image_id in range(1, page_size + 1)
                    )
                    page = linking.LinkedMediaPage(
                        original.gallery_id, original.parent_id, rows, page_size,
                    )
                    body = bodies[native_fixture.TEST_FIRST_IMAGE_ID]
                    session = self._session(
                        page, presentation,
                        {image_id: body for image_id in range(1, total + 1)},
                    )

                    def read_catalogue(
                        gallery_id: int, after_id: int | None = None,
                        cancelled: threading.Event | None = None,
                    ) -> linking.LinkedMediaPage:
                        """Model the complete ascending album behind its first page.

                        Args:
                            gallery_id (int): Exact fixture gallery identity.
                            after_id (int | None): Exclusive requested cursor.
                            cancelled (threading.Event | None): Viewer cancellation.
                        Returns:
                            linking.LinkedMediaPage: The requested bounded slice
                                with its real continuation until the album ends.
                        """
                        if gallery_id != page.gallery_id:
                            raise ValueError('Unexpected oldest-first gallery.')
                        start = (after_id or 0) + 1
                        stop = min(total, start + page_size - 1)
                        return linking.LinkedMediaPage(
                            gallery_id, page.parent_id,
                            tuple(
                                linking.LinkedPhoto(image_id, f'Photo {image_id}')
                                for image_id in range(start, stop + 1)
                            ),
                            stop if stop < total else None,
                        )

                    session.media_page.side_effect = read_catalogue
                    session.can_read_presentation.return_value = False
                    viewer = self._open_viewer(
                        session,
                        linking.LinkedGallery(
                            page.gallery_id, page.parent_id, f'Oldest first {total}',
                        ),
                        canvas_grid_enabled=canvas_enabled,
                    )
                    host = viewer.frame.master
                    try:
                        self._wait_until(
                            lambda: viewer._load_progress.phase == 'hidden',
                            'The first oldest-first viewport waited for unseen pages.',
                        )
                        self.assertEqual(rows, viewer._photos)
                        self.assertIsNone(viewer._view_order)
                        self.assertEqual(page_size, viewer._next_after)
                        session.media_page.assert_called_once()
                        self.assertTrue(viewer._visible_thumbnail_indices)
                        self.assertTrue(all(
                            viewer._photo_images[index] is not None
                            for index in viewer._visible_thumbnail_indices
                        ))
                        viewer.rearm_first_viewport_diagnostics()
                        self._wait_until(
                            lambda: viewer._viewport_after_id is None,
                            'The diagnostic viewport pass did not finish.',
                        )
                        self.assertFalse(viewer._load_progress.active)
                    finally:
                        viewer.close()
                        host.destroy()

    def test_close_during_held_metadata_does_not_reveal_stale_progress(self) -> None:
        """Close the viewer while metadata waits and ignore the old result.

        Returns:
            None: Releasing the cancelled read cannot reactivate its destroyed
                progress overlay or apply its queued metadata event.
        """
        page, presentation, image_bytes = self._gallery_data()
        session = self._session(page, presentation, image_bytes)
        entered = threading.Event()
        release = threading.Event()
        self.addCleanup(release.set)

        def held_page(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> linking.LinkedMediaPage:
            """Return a valid prefix then hold the next read until viewer close.

            Args:
                gallery_id (int): Physical gallery requested by the viewer.
                after_id (int | None): Initial request has no cursor.
                cancelled (threading.Event | None): Viewer lifetime cancellation.
            Returns:
                linking.LinkedMediaPage: First page or terminal page after release.
            """
            if after_id is None:
                return linking.LinkedMediaPage(
                    gallery_id, native_fixture.TEST_PARENT_GALLERY_ID,
                    (page.photos[0],), page.photos[0].image_id,
                )
            entered.set()
            release.wait(native_fixture.TEST_GUI_WAIT_SECONDS)
            return linking.LinkedMediaPage(
                gallery_id, native_fixture.TEST_PARENT_GALLERY_ID,
                (page.photos[1],), None,
            )

        session.media_page.side_effect = held_page
        gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID,
            'Close while loading',
        )
        viewer = self._open_viewer(session, gallery, newest_first=True)
        self._wait_until(
            entered.is_set,
            'The cancelled metadata continuation did not start.',
        )
        indicator = viewer._load_progress
        self._wait_until(
            lambda: indicator.phase == 'metadata',
            'The validated prefix did not reach the visible progress overlay.',
        )
        self.assertTrue(indicator.active)
        indicator.waiting(None, automatic=True)
        self.assertEqual('waiting', indicator.phase)
        worker = viewer._worker
        viewer.close()
        self.assertFalse(indicator.active)
        release.set()
        self._wait_until(
            lambda: worker is None or not worker.is_alive(),
            'The cancelled metadata worker did not release after the fixture read.',
        )
        self.assertFalse(indicator.visible)
        self.assertEqual('hidden', indicator.phase)
        self.assertTrue(viewer._events.empty())

    def test_replacing_gallery_while_old_read_waits_keeps_new_viewer_current(self) -> None:
        """Switch mounted galleries while the prior metadata read is held.

        Returns:
            None: The cancelled viewer cannot publish after replacement, and
                the new gallery's order and progress settle independently.
        """
        page, presentation, image_bytes = self._gallery_data()
        old_session = self._session(page, presentation, image_bytes)
        old_entered = threading.Event()
        release_old = threading.Event()
        self.addCleanup(release_old.set)

        def old_page(
            gallery_id: int, after_id: int | None = None,
            cancelled: threading.Event | None = None,
        ) -> linking.LinkedMediaPage:
            """Return a validated prefix then wait in its old continuation.

            Args:
                gallery_id (int): Old gallery physical identifier.
                after_id (int | None): Current exclusive transport cursor.
                cancelled (threading.Event | None): Old viewer cancellation.
            Returns:
                linking.LinkedMediaPage: Prefix or terminal old gallery rows.
            """
            if after_id is None:
                return linking.LinkedMediaPage(
                    gallery_id, native_fixture.TEST_PARENT_GALLERY_ID,
                    (page.photos[0],), page.photos[0].image_id,
                )
            old_entered.set()
            release_old.wait(native_fixture.TEST_GUI_WAIT_SECONDS)
            return linking.LinkedMediaPage(
                gallery_id, native_fixture.TEST_PARENT_GALLERY_ID,
                (page.photos[1],), None,
            )

        old_session.media_page.side_effect = old_page
        old_gallery = linking.LinkedGallery(
            native_fixture.TEST_GALLERY_ID,
            native_fixture.TEST_PARENT_GALLERY_ID,
            'Old gallery',
        )
        old_viewer = self._open_viewer(
            old_session, old_gallery, newest_first=True,
        )
        old_host = old_viewer.frame.master
        self._wait_until(
            old_entered.is_set,
            'The old gallery read did not reach its held continuation.',
        )
        self._wait_until(
            lambda: old_viewer._load_progress.phase == 'metadata',
            'Old viewer progress did not reach its held continuation.',
        )
        old_indicator = old_viewer._load_progress
        old_worker = old_viewer._worker
        old_viewer.close()
        old_host.destroy()

        new_gallery_id = native_fixture.TEST_GALLERY_ID + 1
        new_image_id = native_fixture.TEST_NEXT_PAGE_IMAGE_ID + 1
        new_page = linking.LinkedMediaPage(
            new_gallery_id, native_fixture.TEST_PARENT_GALLERY_ID,
            (linking.LinkedPhoto(new_image_id, 'New gallery photo'),), None,
        )
        new_session = self._session(new_page, presentation, image_bytes)
        new_session.media_photo.side_effect = None
        new_session.media_photo.return_value = (
            image_bytes[native_fixture.TEST_FIRST_IMAGE_ID], 'image/png',
        )
        new_session.media_page.return_value = new_page
        new_gallery = linking.LinkedGallery(
            new_gallery_id, native_fixture.TEST_PARENT_GALLERY_ID,
            'New gallery',
        )
        new_viewer = self._open_viewer(
            new_session, new_gallery, newest_first=True,
        )
        new_host = new_viewer.frame.master
        self.addCleanup(new_viewer.close)
        self._wait_until(
            lambda: new_viewer._view_order._photos
            and new_viewer._view_order._photos[0].image_id == new_image_id
            and new_viewer._load_progress.phase == 'hidden'
            and all(
                new_viewer._photo_images[index] is not None
                for index in new_viewer._visible_thumbnail_indices
            ),
            'The replacement gallery did not settle its own viewport.',
        )
        release_old.set()
        self._wait_until(
            lambda: old_worker is None or not old_worker.is_alive(),
            'The replaced worker did not leave its held metadata call.',
        )
        self.root.update()
        self.assertFalse(old_indicator.active)
        self.assertEqual('hidden', old_indicator.phase)
        self.assertEqual(new_gallery_id, new_viewer.gallery.gallery_id)
        self.assertEqual(
            new_image_id, new_viewer._view_order._photos[0].image_id,
        )
        new_viewer.close()
        new_host.destroy()


if __name__ == '__main__':
    unittest.main()
